#!/usr/bin/env python3
"""Estate audit: every Cloudflare zone, checked from the outside (25 Nov 2026, RP).

For each zone the Cloudflare token can see (both boxes' domains), this checks nameservers, DNSSEC, CAA,
every web hostname (HTTP->HTTPS redirect, certificate, HSTS), and the mail records (MX, SPF, DMARC,
DKIM, MTA-STS, TLS-RPT, no-mail records on named hosts, TXT quoting).

It only reads: the Cloudflare API (GET), public DNS through 1.1.1.1 and the zone's own nameservers,
and one HTTPS fetch per hostname. Nothing is changed anywhere and nothing runs on the ropo box.

Email:  daily   only when a check fails (failures confirmed by a second run a few minutes later)
        weekly  on Mondays, always: the full table, so you can see it is running and what it tested
Usage:  estate-audit.py            the cron run (rules above)
        estate-audit.py --weekly   send the full weekly report now
        estate-audit.py --print    print the result, send nothing
"""
import datetime, email.utils, html, json, re, socket, ssl, subprocess, sys, time, urllib.request
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

TO = "mail@robertpowell.com"
SENDER = "Estate check <server@robertpowell.net>"
CF_INI = "/root/.cloudflare-allzones.ini"
STATE = "/var/lib/estate-audit/last.json"
RESOLVER = "1.1.1.1"
RECHECK_AFTER = 180          # seconds before failures are re-tested, so a blip is not reported
CERT_MIN_DAYS = 14           # Let's Encrypt renews at 30 days left; under 14 means renewal is failing

# Zones left out entirely (RP, 25 Nov 2026: Verity's domain is not ours to tidy yet).
SKIP_ZONES = {"veritypowell.com"}

# Where things are allowed to point / send from.
BOXES = {"178.62.75.145": "rp.net box", "2a03:b0c0:1:e0:0:1:b4e7:8001": "rp.net box",
         "46.101.0.165": "ropo box", "2a03:b0c0:1:e0:0:1:2a66:1001": "ropo box",
         "85.118.233.228": "cPanel"}
SPF_KNOWN = {"ip4:178.62.75.145", "ip4:188.166.137.38", "ip6:2a03:b0c0:1:e0:0:1:b4e7:8000/124",
             "ip4:46.101.0.165", "ip6:2a03:b0c0:1:e0:0:1:2a66:1001", "include:spf.messagingengine.com"}

# Known and accepted: (zone regex, message regex, why). These show in the weekly report as accepted,
# never as failures. Delete a line when its reason goes away.
ACCEPTED = [
    (r"robertpowell\.com", r"(^|www\.|veritypowell\.)robertpowell\.com: no HSTS", "still on cPanel until the ROP-23 flip"),
]

TOKEN = [l.split("=", 1)[1].strip() for l in open(CF_INI) if "dns_cloudflare_api_token" in l][0]


def cf(path):
    r = urllib.request.Request("https://api.cloudflare.com/client/v4/" + path,
                               headers={"Authorization": "Bearer " + TOKEN})
    return json.load(urllib.request.urlopen(r, timeout=30))


def sh(*a, t=20):
    try:
        return subprocess.run(a, capture_output=True, text=True, timeout=t).stdout.strip()
    except Exception:
        return ""


def dig(*a):
    return [l for l in sh("dig", "+short", "+time=4", "+tries=2", *a).split("\n") if l]


def web(host):
    """Fetch through the address public DNS gives, not this box's own resolver or /etc/hosts."""
    ips = [x for x in dig("A", host, "@" + RESOLVER) if re.match(r"^\d+\.\d+\.\d+\.\d+$", x)]
    if not ips:
        return {"err": "does not resolve"}
    ip, o = ips[0], {}
    res = ["--resolve", f"{host}:80:{ip}", "--resolve", f"{host}:443:{ip}"]
    o["http"] = sh("curl", "-s", "-m", "10", *res, "-o", "/dev/null", "-w", "%{http_code} %{redirect_url}", f"http://{host}/")
    o["https"] = sh("curl", "-s", "-m", "10", *res, "-o", "/dev/null", "-w", "%{http_code} %{ssl_verify_result}", f"https://{host}/")
    o["hsts"] = "strict-transport-security" in sh("curl", "-sI", "-m", "10", *res, f"https://{host}/").lower()
    try:
        s = ssl.create_default_context().wrap_socket(socket.create_connection((ip, 443), timeout=10), server_hostname=host)
        na = s.getpeercert()["notAfter"]; s.close()
        exp = datetime.datetime.strptime(na, "%b %d %H:%M:%S %Y %Z").replace(tzinfo=datetime.timezone.utc)
        o["days"] = (exp - datetime.datetime.now(datetime.timezone.utc)).days
    except Exception as e:
        o["days"] = None; o["certerr"] = str(e)[:80]
    return o


def audit():
    """-> (rows, zones). rows = [(zone, area, ok, message)]."""
    rows, names = [], []
    zones = sorted(cf("zones?per_page=50")["result"], key=lambda z: z["name"])
    for z in zones:
        n = z["name"]
        if n in SKIP_ZONES:
            continue
        names.append(n)
        fails = {}

        def bad(area, msg):
            fails.setdefault(area, []).append(msg)

        def done(area, okmsg):
            if area in fails:
                for m in fails[area]:
                    rows.append((n, area, False, m))
            else:
                rows.append((n, area, True, okmsg))

        rs, p = [], 1
        while True:
            d = cf(f"zones/{z['id']}/dns_records?per_page=500&page={p}"); rs += d["result"]
            if p >= d["result_info"]["total_pages"]:
                break
            p += 1

        # --- zone + nameservers
        if z["status"] != "active":
            bad("Zone", f"zone status is {z['status']}")
        ns = set(x.rstrip(".") for x in dig("NS", n, "@" + RESOLVER))
        if ns != set(z["name_servers"]):
            bad("Zone", f"public nameservers {sorted(ns)} are not Cloudflare's {z['name_servers']}")
        for r in rs:
            if r.get("proxied"):
                bad("Zone", f"{r['name']} is proxied (house rule: DNS only)")
        done("Zone", "active, Cloudflare nameservers, nothing proxied")

        # --- DNSSEC
        st = (cf(f"zones/{z['id']}/dnssec").get("result") or {}).get("status")
        ds = dig("DS", n, "@" + RESOLVER)
        val = "fully validated" in sh("delv", "@" + RESOLVER, n, "SOA") + sh("delv", "@" + RESOLVER, n, "A")
        if st != "active" or not ds or not val:
            bad("DNSSEC", f"not complete (Cloudflare: {st}, DS at registry: {'yes' if ds else 'no'}, validates: {'yes' if val else 'no'})")
        done("DNSSEC", "signed, DS at registry, validates")

        # --- CAA
        caa = [r["content"] for r in rs if r["type"] == "CAA"]
        served = dig("CAA", n, "@" + z["name_servers"][0])
        if not caa:
            bad("CAA", "no CAA records")
        else:
            if not any('issue "letsencrypt.org"' in c for c in caa):
                bad("CAA", "Let's Encrypt is not authorised")
            if len(served) != len(caa):
                bad("CAA", f"{len(served)} CAA records served but {len(caa)} stored: Universal SSL is on and adding other authorities")
        done("CAA", "Let's Encrypt only")

        # --- web hosts
        hosts = sorted({r["name"] for r in rs if r["type"] in ("A", "AAAA", "CNAME")
                        and "_domainkey" not in r["name"] and not r["name"].startswith("*")})
        mindays = None
        for h in hosts:
            mine = [r for r in rs if r["name"] == h]
            w = web(h)
            if "err" in w:
                bad("Web", f"{h}: {w['err']}"); continue
            if not w["http"].startswith("301 https://"):
                bad("Web", f"{h}: http does not redirect to https (got {w['http'] or 'no answer'})")
            if not re.match(r"(200|301|302|308|403) 0$", w["https"]):
                bad("Web", f"{h}: https problem (got {w['https'] or 'no answer'})")
            if not w["hsts"]:
                bad("Web", f"{h}: no HSTS")
            if w["days"] is None:
                bad("Certificates", f"{h}: certificate could not be read ({w.get('certerr', '')})")
            else:
                mindays = w["days"] if mindays is None else min(mindays, w["days"])
                if w["days"] < CERT_MIN_DAYS:
                    bad("Certificates", f"{h}: certificate expires in {w['days']} days")
            cname = any(r["type"] == "CNAME" for r in mine)
            cpanel = any(r["content"].startswith("85.118.") for r in mine)
            if not cname and not cpanel and not any(r["type"] == "AAAA" for r in mine):
                bad("IPv6", f"{h}: no IPv6 address")
            for r in mine:
                if r["type"] in ("A", "AAAA") and r["content"] not in BOXES:
                    bad("Web", f"{h}: points at unknown address {r['content']}")
            # a host with its own address is not covered by the zone's wildcard SPF/MX
            if h != n and not cname and not cpanel:
                if not any(r["type"] == "TXT" and "v=spf1 -all" in r["content"] for r in mine):
                    bad("Named hosts", f"{h}: no SPF -all of its own")
                if not any(r["type"] == "MX" and r["content"] == "." for r in mine):
                    bad("Named hosts", f"{h}: no null MX of its own")
        if "www." + n not in hosts:
            bad("Web", "no www record")
        done("Web", f"{len(hosts)} hostnames: redirect to HTTPS, valid certificate, HSTS")
        done("Certificates", f"shortest has {mindays} days left")
        done("IPv6", "every host has an IPv6 address")
        done("Named hosts", "each named host has its own SPF -all and null MX")

        # --- mail
        mx = [r for r in rs if r["type"] == "MX"]
        apexmx = sorted(r["content"] for r in mx if r["name"] == n)
        wmx = sorted(r["content"] for r in mx if r["name"] == "*." + n)
        nomail = apexmx == ["."]
        if not apexmx:
            bad("MX", "no MX at the apex")
        for r in mx:
            if r["content"] != "." and not dig("A", r["content"], "@" + RESOLVER):
                bad("MX", f"MX target does not resolve: {r['content']}")
            if r["content"] != "." and r["name"] not in (n, "*." + n):
                bad("MX", f"MX on unexpected name {r['name']}")
        if wmx and wmx != apexmx:
            bad("MX", f"wildcard MX {wmx} differs from apex {apexmx}")
        done("MX", "no mail (null MX)" if nomail else ", ".join(x.split(".")[0] for x in apexmx))

        spf = [r["content"].strip('"') for r in rs if r["type"] == "TXT" and r["name"] == n and "v=spf1" in r["content"]]
        if len(spf) != 1:
            bad("SPF", f"{len(spf)} SPF records at the apex")
        else:
            if not spf[0].endswith("-all"):
                bad("SPF", "SPF does not end in -all")
            for mech in spf[0].split()[1:-1]:
                if mech not in SPF_KNOWN:
                    bad("SPF", f"SPF authorises unknown sender {mech}")
        if not any(r["type"] == "TXT" and r["name"] == "*." + n and "v=spf1 -all" in r["content"] for r in rs):
            bad("SPF", "no wildcard SPF -all")
        done("SPF", "one record, ends -all, known senders only, wildcard -all")

        dm = [r["content"].strip('"') for r in rs if r["type"] == "TXT" and r["name"] == "_dmarc." + n]
        if len(dm) != 1:
            bad("DMARC", f"{len(dm)} DMARC records")
        else:
            if "p=reject" not in re.sub(r"sp=\w+", "", dm[0]):
                bad("DMARC", "policy is not p=reject")
            if "sp=reject" not in dm[0]:
                bad("DMARC", "no sp=reject (subdomain policy)")
            if "pct=" in dm[0] and "pct=100" not in dm[0]:
                bad("DMARC", "pct below 100")
            for a in re.findall(r"mailto:([^;,\s]+)", dm[0]):
                dom = a.split("@")[1]
                if not dig("MX", dom, "@" + RESOLVER):
                    bad("DMARC", f"report address {a}: its domain has no MX")
                if not dom.endswith(n):
                    bad("DMARC", f"report address {a} is on another domain")
        done("DMARC", "reject, subdomains reject, reports to this domain")

        if nomail:
            if not any(r["name"] == "*._domainkey." + n for r in rs):
                bad("DKIM", "no-mail domain without a DKIM revocation record")
            done("DKIM", "revoked (no-mail domain)")
            rows.append((n, "MTA-STS", True, "not needed (no-mail domain)"))
            rows.append((n, "TLS-RPT", True, "not needed (no-mail domain)"))
        else:
            # Fastmail rotates its key between fm1/fm2/fm3: all three must resolve, and one must hold a real key.
            fm = {sel: " ".join(dig("TXT", f"{sel}._domainkey.{n}", "@" + RESOLVER)) for sel in ("fm1", "fm2", "fm3")}
            for sel, ans in fm.items():
                if "v=DKIM1" not in ans:
                    bad("DKIM", f"Fastmail record {sel} does not resolve")
            if not any(len(re.sub(r'[" ]', "", ans).split("p=")[-1]) > 300 for ans in fm.values() if "p=" in ans):
                bad("DKIM", "none of Fastmail's fm1/fm2/fm3 holds a live key")
            box = [r for r in rs if r["type"] == "TXT" and "_domainkey" in r["name"] and not r["name"].startswith("*")]
            for r in box:
                if len(re.sub(r'[" ]', "", r["content"]).split("p=")[-1]) < 300:
                    bad("DKIM", f"key {r['name']} looks short or empty")
            done("DKIM", f"Fastmail keys resolve; {len(box)} box key(s) published")

            sts = [r["content"].strip('"') for r in rs if r["type"] == "TXT" and r["name"] == "_mta-sts." + n]
            if not sts:
                bad("MTA-STS", "no MTA-STS record")
            else:
                ip = (dig("A", "mta-sts." + n, "@" + RESOLVER) or ["0.0.0.0"])[0]
                pol = sh("curl", "-s", "-m", "10", "--resolve", f"mta-sts.{n}:443:{ip}", f"https://mta-sts.{n}/.well-known/mta-sts.txt")
                mode = re.search(r"mode:\s*(\w+)", pol); pmx = re.findall(r"mx:\s*(\S+)", pol)
                if not mode or mode.group(1) != "enforce":
                    bad("MTA-STS", "policy file missing or not in enforce mode")
                for m in apexmx:
                    if m not in pmx:
                        bad("MTA-STS", f"MX {m} is not listed in the policy")
            done("MTA-STS", "enforce, policy lists every MX")
            if not any(r["type"] == "TXT" and r["name"] == "_smtp._tls." + n and "v=TLSRPTv1" in r["content"] for r in rs):
                bad("TLS-RPT", "no TLS reporting record")
            done("TLS-RPT", "published")

        # --- record hygiene
        for r in rs:
            if r["type"] not in ("A", "AAAA", "CNAME", "MX", "TXT", "CAA"):
                bad("Records", f"unexpected record type {r['type']} at {r['name']}")
            if r["type"] == "TXT":
                if not (r["content"].startswith('"') and r["content"].endswith('"')):
                    bad("Records", f"TXT not stored in quotes: {r['name']}")
                if not any(k in r["content"] for k in ("v=spf1", "v=DMARC1", "v=DKIM1", "v=STSv1", "v=TLSRPTv1")):
                    bad("Records", f"unrecognised TXT at {r['name']}: {r['content'][:50]}")
        done("Records", f"{len(rs)} records, all of known types, every TXT quoted")
    return rows, names


def accepted(zone, msg):
    for zr, mr, why in ACCEPTED:
        if re.fullmatch(zr, zone) and re.search(mr, msg):
            return why
    return None


def classify(rows):
    """-> (failures, accepted_rows, passes)."""
    f, a, p = [], [], []
    for zone, area, ok, msg in rows:
        if ok:
            p.append((zone, area, msg))
        else:
            why = accepted(zone, msg)
            (a if why else f).append((zone, area, msg, why))
    return f, a, p


AREAS = ["Zone", "DNSSEC", "CAA", "Web", "IPv6", "Certificates", "MX", "SPF", "DMARC", "DKIM", "MTA-STS", "TLS-RPT", "Named hosts", "Records"]


def render(rows, zones, weekly):
    fails, acc, passes = classify(rows)
    e = html.escape
    day = datetime.datetime.now().strftime("%A %-d %B %Y")
    nchecks = len({(z, a) for z, a, _, _ in rows })
    if fails:
        subject = f"Estate check: {len(fails)} problem{'s' if len(fails) != 1 else ''} on {len({f[0] for f in fails})} domain{'s' if len({f[0] for f in fails}) != 1 else ''}"
        colour, head = "#b91c1c", f"{len(fails)} problem{'s' if len(fails) != 1 else ''} found"
    else:
        subject = f"Estate check: all clear, {len(zones)} domains"
        colour, head = "#15803d", "All clear"
    td = "padding:6px 10px;border-bottom:1px solid #e2e8f0;font-size:13px;vertical-align:top"
    out = [f'<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;color:#0f172a;max-width:860px">',
           f'<table cellpadding="0" cellspacing="0" width="100%"><tr><td bgcolor="{colour}" style="padding:14px 18px;color:#ffffff">'
           f'<div style="font-size:19px;font-weight:600">{e(head)}</div>'
           f'<div style="font-size:13px">{e(day)} &middot; {len(zones)} domains &middot; {nchecks} checks'
           f'{" &middot; weekly report" if weekly else ""}</div></td></tr></table>']
    text = [subject, ""]
    if fails:
        out.append('<h3 style="font-size:15px;margin:18px 0 6px">Problems</h3><table cellpadding="0" cellspacing="0" width="100%">')
        for z, a, m, _ in fails:
            out.append(f'<tr><td style="{td};font-weight:600;white-space:nowrap">{e(z)}</td><td style="{td};white-space:nowrap">{e(a)}</td>'
                       f'<td style="{td};color:#b91c1c">{e(m)}</td></tr>')
            text.append(f"PROBLEM  {z}  [{a}]  {m}")
        out.append("</table>")
    if weekly:
        out.append('<h3 style="font-size:15px;margin:22px 0 6px">What was tested</h3>'
                   '<table cellpadding="0" cellspacing="0" width="100%">'
                   f'<tr><td style="{td};font-weight:600">Domain</td>' +
                   "".join(f'<td style="{td};font-weight:600;font-size:11px" align="center">{e(a)}</td>' for a in AREAS) + "</tr>")
        bad = {(z, a) for z, a, _, _ in fails}
        for z in zones:
            cells = ""
            for a in AREAS:
                if (z, a) in bad:
                    cells += f'<td style="{td}" align="center" bgcolor="#fee2e2"><span style="color:#b91c1c;font-weight:700">&#10007;</span></td>'
                else:
                    cells += f'<td style="{td}" align="center"><span style="color:#15803d;font-weight:700">&#10003;</span></td>'
            out.append(f'<tr><td style="{td};font-weight:600;white-space:nowrap">{e(z)}</td>{cells}</tr>')
        out.append("</table>")
        out.append('<h3 style="font-size:15px;margin:22px 0 6px">Detail</h3><table cellpadding="0" cellspacing="0" width="100%">')
        for z in zones:
            out.append(f'<tr><td colspan="2" bgcolor="#f1f5f9" style="{td};font-weight:600">{e(z)}</td></tr>')
            text.append(f"\n{z}")
            for zz, a, m in passes:
                if zz == z:
                    out.append(f'<tr><td style="{td};white-space:nowrap;color:#64748b">{e(a)}</td><td style="{td}">{e(m)}</td></tr>')
                    text.append(f"  ok       {a}: {m}")
            for zz, a, m, _ in fails:
                if zz == z:
                    out.append(f'<tr><td style="{td};white-space:nowrap;color:#b91c1c">{e(a)}</td><td style="{td};color:#b91c1c">{e(m)}</td></tr>')
        out.append("</table>")
        if acc:
            out.append('<h3 style="font-size:15px;margin:22px 0 6px">Known and accepted</h3><table cellpadding="0" cellspacing="0" width="100%">')
            groups = {}
            for z, a, m, why in acc:
                groups.setdefault(why, []).append(m.split(":")[0] if ": " in m else z)
            for why, items in groups.items():
                out.append(f'<tr><td style="{td}">{e(why)}</td><td style="{td};color:#64748b">{e(", ".join(sorted(set(items))))}</td></tr>')
                text.append(f"accepted: {why}: {', '.join(sorted(set(items)))}")
            out.append("</table>")
        if SKIP_ZONES:
            out.append(f'<p style="font-size:12px;color:#64748b">Not checked: {e(", ".join(sorted(SKIP_ZONES)))}.</p>')
    out.append('<p style="font-size:12px;color:#64748b">Daily at 07:15; an email is sent only when a check fails, plus this full report every Monday. '
               '/usr/local/bin/estate-audit.py on the robertpowell.net box.</p></div>')
    return subject, "\n".join(out), "\n".join(text), fails


def send(subject, html_body, text_body):
    msg = MIMEMultipart("alternative")
    msg["Subject"], msg["From"], msg["To"] = subject, SENDER, TO
    msg["Date"] = email.utils.formatdate(localtime=True)
    msg.attach(MIMEText(text_body, "plain", "utf-8"))
    msg.attach(MIMEText(html_body, "html", "utf-8"))
    p = subprocess.Popen(["/usr/sbin/sendmail", "-t", "-oi", "-f", email.utils.parseaddr(SENDER)[1]], stdin=subprocess.PIPE)
    p.communicate(msg.as_bytes())


def main():
    weekly = "--weekly" in sys.argv or (datetime.datetime.now().weekday() == 0 and "--print" not in sys.argv)
    rows, zones = audit()
    fails = classify(rows)[0]
    if fails and "--print" not in sys.argv:
        # Re-test before reporting: only a failure seen twice is real.
        time.sleep(RECHECK_AFTER)
        rows2, zones2 = audit()
        again = {(z, a, m) for z, a, m, _ in classify(rows2)[0]}
        first = {(z, a, m) for z, a, m, _ in fails}
        keep = first & again
        rows = [r for r in rows2 if r[2] or accepted(r[0], r[3]) or (r[0], r[1], r[3]) in keep] + \
               [(z, a, True, "passed on re-test") for z, a, m in again - keep if not any(r[0] == z and r[1] == a for r in rows2 if (r[0], r[1], r[3]) in keep)]
        zones = zones2
    subject, body, text, fails = render(rows, zones, weekly or "--print" in sys.argv)
    stamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M")
    if "--print" in sys.argv:
        print(text); return
    try:
        import os
        os.makedirs(os.path.dirname(STATE), exist_ok=True)
        json.dump({"when": stamp, "zones": zones, "failures": [list(f[:3]) for f in fails]}, open(STATE, "w"), indent=1)
    except Exception:
        pass
    if fails or weekly:
        send(subject, body, text)
        print(f"{stamp} {subject} (emailed)")
    else:
        print(f"{stamp} {subject} (no email)")


if __name__ == "__main__":
    main()
