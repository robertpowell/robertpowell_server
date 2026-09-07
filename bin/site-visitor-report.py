#!/opt/ropo-blog/venv/bin/python
"""Daily visitor report across every vhost on this server.

One email, a section per site. No cookies, no tracking pixel, no JavaScript -
this reads Apache access logs and nothing else.
"""
import email.utils
import html, json, os, re, subprocess, sys, urllib.parse, urllib.request
from collections import Counter
from datetime import datetime, timedelta, timezone
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from math import asin, cos, log10, radians, sin, sqrt

LOGDIR = "/var/log/apache2"
TO = "mail@robertpowell.com"
FROM_USER = "server"           # every report is sent from server@<the site it is about>
# No per-site fallback: a report must only ever come from its own domain.
SENDER_OVERRIDE = {}
GEO_CACHE = "/opt/ropo-blog/reports/geo-cache.json"
PC_CACHE = "/opt/ropo-blog/reports/postcode-cache.json"
LEARNED = "/opt/ropo-blog/data/known-ips.json"
HOURS, NEAR_KM, LEARNED_MAX_AGE_DAYS = 24, 25, 120
HOME_OUTCODE, WATCH_OUTCODE = "GU9", "GU5"
KNOWN_IPS = {"81.103.25.79": "You"}

# A dormant site would otherwise mail you an empty report every morning.
# Set False if you would rather have one per domain regardless.
SKIP_EMPTY = True

# label, log file, include in the report?
SITES = [
    ("robertpowell.com",        "new.robertpowell.com-access.log",      True),
    ("robertpowell.net",        "robertpowell.net-access.log",          True),
    ("rpowell.co.uk",           "rpowell.co.uk-access.log",             True),
    ("ultrasecret.net",         "ultrasecret.net-access.log",           True),
    ("highlyconfidential.co.uk","highlyconfidential.co.uk-access.log",  True),
]
# Machine-only traffic, counted but not detailed: mail servers fetching an
# MTA-STS policy are not visitors, and the catch-all is pure scanner noise.
MACHINE_LOG = "other_vhosts_access.log"
# Where each site's contact form logs accepted messages (submissions.jsonl, one JSON object per line).
CONTACT_DATA = {
    "robertpowell.com":         "/var/www/shared/contact/data/new.robertpowell.com",
    "robertpowell.net":         "/var/www/shared/contact/data/robertpowell.net",
    "rpowell.co.uk":            "/var/www/shared/contact/data/rpowell.co.uk",
    "ultrasecret.net":          "/var/www/shared/contact/data/ultrasecret.net",
    "highlyconfidential.co.uk": "/var/www/highlyconfidential.co.uk/data",
}

# matches both "combined" and "vhost_combined" (leading %v:%p)
LINE = re.compile(
    r'^(?:(?P<vhost>[\w.\-]+):\d+\s+)?(?P<ip>\S+) \S+ \S+ \[(?P<ts>[^\]]+)\] '
    r'"(?P<method>[A-Z]+) (?P<path>\S*) [^"]*" (?P<status>\d{3}) (?P<size>\S+) '
    r'"(?P<ref>[^"]*)" "(?P<ua>[^"]*)"')
BOT = re.compile(r'bot|crawler|spider|slurp|facebookexternalhit|updown\.io|pingdom|'
                 r'uptimerobot|semrush|ahrefs|mj12|dotbot|petalbot|censys|zgrab|masscan|'
                 r'python-requests|curl/|wget|go-http-client|scrapy|headlesschrome|'
                 r'phantomjs|monitoring|statuscake|site24x7|health|crusader-worker', re.I)
ASSET = re.compile(r'\.(css|js|svg|ico|png|jpe?g|gif|webp|woff2?|map|txt|xml)$', re.I)

A, INK, MUT, FNT = "#a3571a", "#1b1a18", "#5d5a55", "#8d8880"
RULE, BG, SURF, INDIGO = "#e4e0d9", "#fbfaf8", "#ffffff", "#26304d"


def jload(p, d=None):
    try:
        return json.load(open(p))
    except Exception:
        return {} if d is None else d


def jsave(p, o):
    try:
        os.makedirs(os.path.dirname(p), exist_ok=True)
        json.dump(o, open(p, "w"), indent=1, sort_keys=True)
    except Exception:
        pass


def learned():
    out, cut = {}, datetime.now(timezone.utc) - timedelta(days=LEARNED_MAX_AGE_DAYS)
    for ip, e in (jload(LEARNED) or {}).items():
        try:
            seen = datetime.strptime(e.get("last_seen", ""), "%Y-%m-%d %H:%M:%S") \
                       .replace(tzinfo=timezone.utc)
        except Exception:
            seen = None
        if seen is None or seen >= cut:
            out[ip] = e.get("label") or "You"
    return out


def banned():
    """Every IP fail2ban currently has banned, across all jails, as a set."""
    try:
        import ast
        out = subprocess.run(["/usr/bin/fail2ban-client", "banned"], capture_output=True,
                             text=True, timeout=20).stdout
        return {ip for jail in ast.literal_eval(out.strip()) for v in jail.values() for ip in v}
    except Exception as e:
        print(f"fail2ban list failed: {e}", file=sys.stderr)
        return set()


def geo(ips, cache):
    todo = [i for i in ips if i not in cache]
    for n in range(0, len(todo), 100):
        body = json.dumps([{"query": i} for i in todo[n:n + 100]]).encode()
        req = urllib.request.Request(
            "http://ip-api.com/batch?fields=status,query,country,countryCode,"
            "regionName,city,zip,lat,lon,isp,mobile,proxy,hosting",
            data=body, headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=25) as r:
                for row in json.load(r):
                    cache[row.get("query", "")] = row
        except Exception as e:
            print(f"geo failed: {e}", file=sys.stderr)
            break
    return cache


def outcode(oc, cache):
    oc = (oc or "").upper().strip()
    if not oc:
        return None
    if oc in cache:
        return cache[oc]
    try:
        with urllib.request.urlopen(
                f"https://api.postcodes.io/outcodes/{urllib.parse.quote(oc)}", timeout=15) as r:
            res = (json.load(r) or {}).get("result") or {}
        cache[oc] = ({"lat": res["latitude"], "lon": res["longitude"],
                      "district": (res.get("admin_district") or [""])[0]}
                     if res.get("latitude") is not None else None)
    except Exception:
        cache[oc] = None
    return cache[oc]


def km(a, b, c, d):
    if None in (a, b, c, d):
        return None
    p1, p2 = radians(a), radians(c)
    h = sin(radians(c - a) / 2) ** 2 + cos(p1) * cos(p2) * sin(radians(d - b) / 2) ** 2
    return 2 * 6371.0 * asin(sqrt(h))


def band(d):
    if d is None:
        return ("unknown", FNT, 0)
    w = max(3, min(100, int(log10(d + 1) / log10(20000) * 100)))
    if d <= 5:    return ("on the doorstep", A, w)
    if d <= 25:   return ("local", "#c07434", w)
    if d <= 100:  return ("nearby", INDIGO, w)
    if d <= 500:  return ("a fair way", "#4a5878", w)
    if d <= 2000: return ("distant", "#6b7590", w)
    return ("far away", FNT, w)


def read(path, want_vhost=None):
    cut = datetime.now(timezone.utc) - timedelta(hours=HOURS)
    rows = []
    for p in (path + ".1", path):
        if not os.path.exists(p):
            continue
        for line in open(p, errors="replace"):
            m = LINE.match(line)
            if not m:
                continue
            try:
                ts = datetime.strptime(m.group("ts"), "%d/%b/%Y:%H:%M:%S %z")
            except ValueError:
                continue
            if ts < cut:
                continue
            d = m.groupdict()
            if want_vhost and d.get("vhost") and want_vhost not in d["vhost"]:
                continue
            d["dt"] = ts
            d["bot"] = bool(BOT.search(d["ua"])) or d["ua"] in ("-", "")
            d["asset"] = bool(ASSET.search(d["path"]))
            rows.append(d)
    return rows


def esc(s):
    return html.escape(str(s or ""))


def contacts_sent(label):
    """Messages accepted by the site's contact form in the last HOURS hours."""
    path = os.path.join(CONTACT_DATA.get(label, ""), "submissions.jsonl")
    cut = datetime.now(timezone.utc) - timedelta(hours=HOURS)
    n = 0
    try:
        for line in open(path, errors="replace"):
            try:
                ts = datetime.fromisoformat(json.loads(line).get("ts", ""))
                if ts.tzinfo is None:
                    ts = ts.replace(tzinfo=timezone.utc)
                if ts >= cut:
                    n += 1
            except Exception:
                continue
    except OSError:
        pass
    return n


def https(u):
    """Force https on any URL or bare host we put in the email. Gmail turns bare
    domains and http:// text into http links; an explicit https anchor stops that."""
    u = str(u or "").strip()
    u = re.sub(r"^https?://", "", u, flags=re.I)
    return "https://" + u


def link(text, url, color=None):
    style = f'color:{color};text-decoration:none' if color else 'color:inherit;text-decoration:none'
    return f'<a href="{esc(https(url))}" style="{style}">{esc(text)}</a>'


def visitors_for(rows, gcache, pcc, home, known, bans=frozenset()):
    people = [r for r in rows if not r["bot"] and not r["ip"].startswith("127.")]
    pages = [r for r in people if not r["asset"]]
    ips = sorted({r["ip"] for r in people})
    geo(ips, gcache)
    out = []
    for ip in ips:
        hits = [r for r in people if r["ip"] == ip]
        pg = [r for r in hits if not r["asset"]]
        g = gcache.get(ip, {})
        v = {"ip": ip, "hits": len(pg) or len(hits),
             "last": max(r["dt"] for r in hits),
             "paths": [r["path"] for r in pg][:5],
             "city": g.get("city", ""), "zip": g.get("zip", ""),
             "country": g.get("country", ""), "cc": g.get("countryCode", ""),
             "isp": g.get("isp", ""), "lat": g.get("lat"), "lon": g.get("lon"),
             "flags": [k for k in ("mobile", "proxy", "hosting") if g.get(k)],
             "known": known.get(ip, ""), "banned": ip in bans}
        oc = ""
        m = re.match(r"^([A-Z]{1,2}\d{1,2}[A-Z]?)", (v["zip"] or "").upper())
        if m and v["cc"] == "GB":
            oc = m.group(1)
        v["outcode"] = oc
        v["is_gu"] = oc.startswith("GU")
        v["dist"] = km(home["lat"], home["lon"], v["lat"], v["lon"]) if home else None
        if oc and home:
            o = outcode(oc, pcc)
            if o:
                v["dist"] = km(home["lat"], home["lon"], o["lat"], o["lon"])
        if v["known"]:
            v.update(dist=0.0, outcode=HOME_OUTCODE, is_gu=True)
        out.append(v)
    # Closest first: guests by distance from home, unplaceable ones after them,
    # your own addresses at the bottom. Ties break by most recent hit.
    def _order(v):
        d = v.get("dist")
        return (1 if v.get("known") else 0, d is None, d if d is not None else 0.0, -v["last"].timestamp())
    out.sort(key=_order)
    return out, pages, [r for r in rows if r["bot"]]


def rows_html(vis, empty="No human visitors in the last 24 hours."):
    out = []
    for v in vis:
        lbl, col, w = band(v.get("dist"))
        d = v.get("dist")
        dtxt = ("0 km" if v.get("known") else
                (f"{d:.0f} km" if d is not None and d >= 1 else ("<1 km" if d is not None else "—")))
        bar = (f'<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:4px;'
               f'background:{RULE};height:5px;border-radius:3px"><tr>'
               f'<td width="{w}%" style="background:{col};height:5px;border-radius:3px;'
               f'font-size:0;line-height:0">&nbsp;</td><td style="font-size:0;line-height:0">'
               f'&nbsp;</td></tr></table>') if d is not None else ""
        if v.get("known"):
            who = (f'<b style="color:{A}">{esc(v["known"])}</b>'
                   f'<div style="color:{FNT};font-size:11px">IP says {esc(v["city"])} {esc(v["zip"])}</div>')
        else:
            chip = ""
            if v["outcode"]:
                bgc = INDIGO if v["is_gu"] else "#9a948c"
                chip = (f'<span style="display:inline-block;font:600 10px/1.6 ui-monospace,monospace;'
                        f'color:#fff;background:{bgc};padding:1px 6px;border-radius:2px;'
                        f'margin-left:5px">{esc(v["outcode"])}</span>')
            who = ((esc(v["city"]) or "—") + chip +
                   f'<div style="color:{FNT};font-size:11px">{esc(v["country"])}</div>')
        flg = (f'<span style="display:inline-block;font:600 9px/1.5 ui-monospace,monospace;'
               f'color:#fff;background:#b3261e;padding:1px 6px;border-radius:2px;margin-left:4px;'
               f'letter-spacing:.06em">BANNED</span>') if v.get("banned") else ""
        flg += "".join(f'<span style="display:inline-block;font:500 9px/1.5 ui-monospace,monospace;'
                      f'color:#fff;background:{"#b3541f" if f=="hosting" else INDIGO};'
                      f'padding:1px 5px;border-radius:2px;margin-left:4px">{f}</span>'
                      for f in v["flags"])
        paths = "<br>".join(f'<span style="color:{MUT}">{esc(p[:44])}</span>' for p in v["paths"]) or "—"
        out.append(
          f'<tr><td style="padding:11px 12px;border-bottom:1px solid {RULE};font:600 12px '
          f'ui-monospace,monospace;color:{INK};white-space:nowrap;vertical-align:top">'
          f'{v["last"]:%H:%M}<div style="font-weight:400;font-size:10px;color:{FNT}">{esc(v["ip"])}</div></td>'
          f'<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:13px -apple-system,'
          f'Segoe UI,Arial,sans-serif;color:{INK};vertical-align:top">{who}{flg}</td>'
          f'<td style="padding:11px 12px;border-bottom:1px solid {RULE};vertical-align:top;width:112px">'
          f'<div style="font:600 12px ui-monospace,monospace;color:{col}">{dtxt}</div>{bar}'
          f'<div style="font:9px ui-monospace,monospace;color:{FNT};margin-top:3px">{lbl}</div></td>'
          f'<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:11px -apple-system,'
          f'Segoe UI,Arial,sans-serif;color:{MUT};vertical-align:top">{esc(v["isp"][:28]) or "—"}'
          f'<div style="font:10px ui-monospace,monospace;color:{FNT};margin-top:3px">{paths}</div></td>'
          f'<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:600 14px ui-monospace,'
          f'monospace;color:{A};text-align:right;vertical-align:top">{v["hits"]}</td></tr>')
    if not out:
        out = [f'<tr><td colspan="5" style="padding:24px;text-align:center;color:{FNT};'
               f'font:13px -apple-system,Segoe UI,Arial,sans-serif">{empty}</td></tr>']
    return "".join(out)


def banned_section(rows, th):
    if not rows:
        return ""
    return (f'<tr><td style="padding-top:22px">'
            f'<div style="font:600 11px/1 ui-monospace,monospace;letter-spacing:.09em;'
            f'text-transform:uppercase;color:#b3261e;padding-bottom:8px">Banned by fail2ban '
            f'&middot; {len(rows)}</div>'
            f'<table width="100%" cellpadding="0" cellspacing="0" style="background:{SURF};'
            f'border:1px solid {RULE};border-left:3px solid #b3261e;border-collapse:collapse">'
            f'<tr style="background:{BG}"><th align="left" style="{th}">When</th>'
            f'<th align="left" style="{th}">Who / where</th><th align="left" style="{th}">From {HOME_OUTCODE}</th>'
            f'<th align="left" style="{th}">Network &amp; pages</th><th align="right" style="{th}">Hits</th></tr>'
            f'{rows_html(rows)}</table>'
            f'<div style="font:10px/1.5 ui-monospace,monospace;color:{FNT};margin-top:6px">'
            f'Addresses currently in a fail2ban jail. They are left out of the visitor count and the '
            f'closeness summary above.</div></td></tr>')


def render_site(label, vis, pages, bots, day, home, watch_ring, contacts=0):
    banned_rows = [v for v in vis if v.get("banned")]
    vis = [v for v in vis if not v.get("banned")]
    guests = [v for v in vis if not v.get("known")]
    others = guests
    local = sorted([v for v in others if v.get("dist") is not None and v["dist"] <= NEAR_KM],
                   key=lambda v: v["dist"])
    gu = [v for v in others if v.get("is_gu")]
    hits = [v for v in others if v.get("outcode") in watch_ring]
    nearest = min((v for v in others if v.get("dist") is not None),
                  key=lambda v: v["dist"], default=None)
    if hits:
        headline = (f'<span style="color:{A};font-weight:700">{len(hits)} from {WATCH_OUTCODE} '
                    f'or next to it</span>')
    elif local:
        headline = (f'<span style="color:{A};font-weight:700">{len(local)} within {NEAR_KM} km '
                    f'of {HOME_OUTCODE}</span>')
    elif nearest:
        headline = (f'Nearest was <b>{esc(nearest["city"] or nearest["country"])}</b>, '
                    f'{nearest["dist"]:.0f} km away')
    else:
        headline = "Nobody close enough to place"
    chips = "".join(
        f'<span style="display:inline-block;font:600 11px/1.7 ui-monospace,monospace;color:#fff;'
        f'background:{A if v.get("outcode") in watch_ring else INDIGO};padding:2px 7px;'
        f'border-radius:2px;margin:0 5px 5px 0">{esc(v.get("outcode") or v["city"] or "?")}'
        f' &middot; {v["dist"]:.0f}km</span>' for v in gu) or \
        f'<span style="color:{FNT};font:12px -apple-system,Segoe UI,Arial,sans-serif">none today</span>'

    top = Counter(r["path"] for r in pages).most_common(7)
    refs = Counter(r["ref"] for r in pages
                   if r["ref"] not in ("-", "") and label not in r["ref"]).most_common(5)
    ctry = Counter(v["country"] for v in others if v["country"]).most_common(5)

    def stat(n, l, c=INK):
        return (f'<td style="padding:13px 14px;background:{SURF};border:1px solid {RULE};'
                f'text-align:center"><div style="font:700 25px/1 -apple-system,Segoe UI,Arial,'
                f'sans-serif;color:{c}">{n}</div><div style="font:500 9px/1.4 ui-monospace,monospace;'
                f'letter-spacing:.09em;text-transform:uppercase;color:{FNT};margin-top:5px">{l}</div></td>')

    def box(title, items, href=None):
        if not items:
            return ""
        li = "".join(
            f'<tr><td style="padding:7px 12px;border-bottom:1px solid {RULE};font:13px -apple-system,'
            f'Segoe UI,Arial,sans-serif;color:{INK}">'
            f'{link(str(k)[:56], href(k), INK) if href else esc(str(k)[:56])}</td>'
            f'<td align="right" style="padding:7px 12px;border-bottom:1px solid {RULE};'
            f'font:600 13px ui-monospace,monospace;color:{A}">{n}</td></tr>' for k, n in items)
        return (f'<tr><td style="padding-top:20px"><div style="font:600 11px/1 ui-monospace,monospace;'
                f'letter-spacing:.09em;text-transform:uppercase;color:{FNT};padding-bottom:8px">{title}</div>'
                f'<table width="100%" cellpadding="0" cellspacing="0" style="background:{SURF};'
                f'border:1px solid {RULE};border-collapse:collapse">{li}</table></td></tr>')

    th = (f'padding:8px 12px;font:600 9px ui-monospace,monospace;letter-spacing:.09em;'
          f'text-transform:uppercase;color:{FNT};border-bottom:1px solid {RULE}')

    return f'''<!doctype html><html><body style="margin:0;padding:0;background:{BG}">
<table width="100%" cellpadding="0" cellspacing="0" style="background:{BG};padding:24px 12px">
<tr><td align="center"><table width="650" cellpadding="0" cellspacing="0" style="max-width:650px;width:100%">

<tr><td style="padding-bottom:18px"><table cellpadding="0" cellspacing="0"><tr>
 <td style="padding-right:11px"><svg width="32" height="32" viewBox="0 0 100 100"
  xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="96" height="96" rx="20" fill="{INDIGO}"/>
  <g fill="none" stroke-width="8" stroke-linecap="round" stroke-linejoin="round">
  <path d="M20 16 V46 M20 16 H33 A9 9 0 0 1 33 32 H20 M31 32 L41 46" stroke="#eef1f7"/>
  <path d="M20 54 V84 M20 54 H33 A9 9 0 0 1 33 70 H20" stroke="#eef1f7"/>
  <circle cx="70" cy="35" r="11" stroke="#f0a55e"/><circle cx="70" cy="73" r="11" stroke="#f0a55e"/>
  </g></svg></td>
 <td><div style="font:700 20px/1.15 -apple-system,Segoe UI,Arial,sans-serif;color:{INK};
  letter-spacing:-.02em">{link(label, label + "/", INK)}</div>
  <div style="font:13px -apple-system,Segoe UI,Arial,sans-serif;color:{MUT};margin-top:2px">{day}</div>
 </td></tr></table></td></tr>

<tr><td><table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;
 border-spacing:6px 0"><tr>{stat(len(guests), "Visitors", A)}{stat(len(pages), "Page views")}
 {stat(len({v["country"] for v in others if v["country"]}), "Countries")}
 {stat(len(banned_rows), "Banned", "#b3261e")}
 {stat(contacts, "Contacts sent", "#15803d" if contacts else FNT)}
 {stat(len(bots), "Bot hits", FNT)}</tr></table></td></tr>

<tr><td style="padding-top:22px">
 <div style="font:600 11px/1 ui-monospace,monospace;letter-spacing:.09em;text-transform:uppercase;
  color:{FNT};padding-bottom:8px">How close to home</div>
 <table width="100%" cellpadding="0" cellspacing="0" style="background:{SURF};border:1px solid {RULE};
  border-left:3px solid {A}"><tr><td style="padding:13px 15px">
  <div style="font:15px/1.45 -apple-system,Segoe UI,Arial,sans-serif;color:{INK}">{headline}</div>
  <div style="font:11px ui-monospace,monospace;color:{FNT};margin-top:4px">from {HOME_OUTCODE}
   &nbsp;|&nbsp; watching {WATCH_OUTCODE} and its neighbours</div>
  <div style="margin-top:10px">{chips}</div></td></tr></table></td></tr>

<tr><td style="padding-top:22px">
 <div style="font:600 11px/1 ui-monospace,monospace;letter-spacing:.09em;text-transform:uppercase;
  color:{FNT};padding-bottom:8px">Every visitor</div>
 <table width="100%" cellpadding="0" cellspacing="0" style="background:{SURF};border:1px solid {RULE};
  border-collapse:collapse"><tr style="background:{BG}">
  <th align="left" style="{th}">When</th><th align="left" style="{th}">Who / where</th>
  <th align="left" style="{th}">From {HOME_OUTCODE}</th>
  <th align="left" style="{th}">Network &amp; pages</th><th align="right" style="{th}">Hits</th></tr>
  {rows_html(vis)}</table></td></tr>

{banned_section(banned_rows, th)}

{box("Most read", top, lambda k: label + k)}{box("Came from", refs, https)}{box("Countries", ctry)}

<tr><td style="padding-top:24px;border-top:1px solid {RULE}">
 <div style="font:11px/1.65 ui-monospace,monospace;color:{FNT}">
 Apache access log for <b>{link(label, label + "/", INK)}</b> &mdash; <b>no cookies, no tracking pixel, no JavaScript</b>.
 Distance is from <b>{HOME_OUTCODE}</b>, using ONS postcode centroids where the visitor resolves to a
 UK postcode. It is approximate: the postcode is an outward district at best and the co-ordinates are
 a town centroid, not a person. Rows tagged <i>hosting</i> or <i>proxy</i> are machines.
 </div></td></tr>
</table></td></tr></table></body></html>'''


def sender_for(label):
    """Each site's report comes from that site's own domain, shown as the site name."""
    return f"{label} <{FROM_USER}@{label}>"


def send(subject, html_body, text_body, sender):
    msg = MIMEMultipart("alternative")
    msg["Subject"], msg["From"], msg["To"] = subject, sender, TO
    msg["Date"] = email.utils.formatdate(localtime=True)
    msg.attach(MIMEText(text_body or "No traffic.", "plain", "utf-8"))
    msg.attach(MIMEText(html_body, "html", "utf-8"))
    envelope = email.utils.parseaddr(sender)[1] or sender
    p = subprocess.Popen(["/usr/sbin/sendmail", "-t", "-oi", "-f", envelope], stdin=subprocess.PIPE)
    p.communicate(msg.as_bytes())


def main():
    global KNOWN_IPS
    KNOWN_IPS = {**KNOWN_IPS, **learned()}
    gcache, pcc = jload(GEO_CACHE), jload(PC_CACHE)
    home = outcode(HOME_OUTCODE, pcc)
    watch = outcode(WATCH_OUTCODE, pcc)
    ring = set()
    if watch:
        key = f"__ring_{WATCH_OUTCODE}"
        if key not in pcc:
            try:
                url = (f"https://api.postcodes.io/outcodes?lat={watch['lat']}"
                       f"&lon={watch['lon']}&limit=12&radius=20000")
                with urllib.request.urlopen(url, timeout=15) as r:
                    pcc[key] = [x["outcode"] for x in (json.load(r).get("result") or [])]
            except Exception:
                pcc[key] = []
        ring = {o.upper() for o in (pcc.get(key) or [])}
    ring.add(WATCH_OUTCODE)

    bans = banned()
    day = datetime.now().strftime("%A %-d %B %Y")
    tag = "[TEST] " if os.environ.get("REPORT_TEST") else ""
    only = os.environ.get("REPORT_SITE", "")
    sent = 0

    for label, logfile, on in SITES:
        if not on or (only and only != label):
            continue
        rows = read(os.path.join(LOGDIR, logfile))
        vis, pages, bots = visitors_for(rows, gcache, pcc, home, KNOWN_IPS, bans)
        guests = [v for v in vis if not v.get("known") and not v.get("banned")]
        if SKIP_EMPTY and not guests and not pages:
            print(f"  {label}: nothing to report, skipped")
            continue
        subject = (f"{tag}{label} — {len(guests)} visitor"
                   f"{'s' if len(guests) != 1 else ''}, {day}")
        line = lambda v: (f"{v['last']:%H:%M}  {v['ip']:<16} "
                          f"{(v['known'] or v['city'] or '?'):<16} "
                          f"{('%.0f km' % v['dist']) if v.get('dist') is not None else '?':>8}  "
                          f"{v['hits']} pages")
        text = "\n".join(line(v) for v in vis if not v.get("banned"))
        if any(v.get("banned") for v in vis):
            text += "\n\nBanned by fail2ban:\n" + "\n".join(line(v) for v in vis if v.get("banned"))
        contacts = contacts_sent(label)
        text = f"Contacts sent via the form: {contacts}\n\n" + text
        send(subject, render_site(label, vis, pages, bots, day, home, ring, contacts), text,
             sender_for(label))
        sent += 1
        print(f"  {label}: sent — {len(guests)} visitors, {len(pages)} views, {len(bots)} bot")

    jsave(GEO_CACHE, gcache); jsave(PC_CACHE, pcc)
    print(f"{sent} email(s) sent")


if __name__ == "__main__":
    main()
