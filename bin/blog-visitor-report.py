#!/opt/ropo-blog/venv/bin/python
"""Daily visitor report for the blog.

Reads the vhost access log, separates people from robots, resolves approximate
location for the human visitors, and emails an HTML summary.

No cookies, no tracking pixel, no JavaScript - this is server log analysis only.
"""
import html
import json
import os
import re
import smtplib
import email.utils
import subprocess
import sys
import urllib.parse
import urllib.request
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

LOG = "/var/log/apache2/new.robertpowell.com-access.log"
LOG_OLD = LOG + ".1"
TO = "mail@robertpowell.com"
FROM = "noreply@robertpowell.net"
SITE = "new.robertpowell.com"
CACHE = "/opt/ropo-blog/reports/geo-cache.json"
PC_CACHE = "/opt/ropo-blog/reports/postcode-cache.json"
HOURS = 24

# Where "home" is. Change this one line if it is wrong.
HOME_OUTCODE = "GU9"
# Outward codes worth calling out even when they are not the nearest.
WATCH_OUTCODE = "GU5"
# How far around each of the above still counts as "nearby", in km.
NEAR_KM = 25

# Addresses known to be yours. IP geolocation puts these in Aldershot GU12,
# ~6 km out, so without this your own visits read as a stranger from up the road.
#
# The blog appends to LEARNED_IPS_FILE every time someone signs in to /admin/,
# so this maintains itself as your ISP rotates your address. Anything listed
# here by hand is kept as well.
KNOWN_IPS = {
    "81.103.25.79": "You",
}
LEARNED_IPS_FILE = "/opt/ropo-blog/data/known-ips.json"
LEARNED_MAX_AGE_DAYS = 120

LINE = re.compile(
    r'^(?P<ip>\S+) \S+ \S+ \[(?P<ts>[^\]]+)\] "(?P<method>[A-Z]+) (?P<path>\S*) [^"]*" '
    r'(?P<status>\d{3}) (?P<size>\S+) "(?P<ref>[^"]*)" "(?P<ua>[^"]*)"')

BOT_RE = re.compile(
    r'bot|crawler|spider|slurp|bingpreview|facebookexternalhit|updown\.io|'
    r'pingdom|uptimerobot|semrush|ahrefs|mj12|dotbot|petalbot|censys|'
    r'zgrab|masscan|python-requests|curl/|wget|go-http-client|scrapy|'
    r'headlesschrome|phantomjs|monitoring|statuscake|site24x7', re.I)

ASSET_RE = re.compile(r'\.(css|js|svg|ico|png|jpe?g|gif|webp|woff2?|map|txt|xml)$', re.I)


def load_cache():
    try:
        with open(CACHE) as f:
            return json.load(f)
    except Exception:
        return {}


def save_cache(c):
    try:
        os.makedirs(os.path.dirname(CACHE), exist_ok=True)
        with open(CACHE, "w") as f:
            json.dump(c, f)
    except Exception:
        pass


def geo(ips, cache):
    """Resolve in batches of 100. Cached, so repeat visitors cost nothing."""
    todo = [i for i in ips if i not in cache]
    for n in range(0, len(todo), 100):
        chunk = todo[n:n + 100]
        body = json.dumps([{"query": i} for i in chunk]).encode()
        req = urllib.request.Request(
            "http://ip-api.com/batch?fields=status,query,country,countryCode,"
            "regionName,city,zip,lat,lon,isp,mobile,proxy,hosting",
            data=body, headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=25) as r:
                for row in json.load(r):
                    cache[row.get("query", "")] = row
        except Exception as e:
            print(f"geo lookup failed: {e}", file=sys.stderr)
            break
    return cache


def learned_ips():
    """Addresses that have signed in to /admin/, minus any gone stale."""
    out = {}
    try:
        with open(LEARNED_IPS_FILE) as f:
            data = json.load(f)
    except Exception:
        return out
    cutoff = datetime.now(timezone.utc) - timedelta(days=LEARNED_MAX_AGE_DAYS)
    for ip, e in (data or {}).items():
        try:
            seen = datetime.strptime(e.get("last_seen", ""), "%Y-%m-%d %H:%M:%S") \
                       .replace(tzinfo=timezone.utc)
        except Exception:
            seen = None
        if seen is None or seen >= cutoff:
            out[ip] = e.get("label") or "You"
    return out


def pc_cache():
    try:
        with open(PC_CACHE) as f:
            return json.load(f)
    except Exception:
        return {}


def outcode_latlon(outcode, cache):
    """ONS-backed centroid for a UK outward code, via postcodes.io. Cached."""
    oc = (outcode or "").upper().strip()
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


def neighbours(lat, lon, limit=12, radius=20000):
    try:
        url = (f"https://api.postcodes.io/outcodes?lat={lat}&lon={lon}"
               f"&limit={limit}&radius={radius}")
        with urllib.request.urlopen(url, timeout=15) as r:
            return [x["outcode"] for x in (json.load(r).get("result") or [])]
    except Exception:
        return []


def km(a_lat, a_lon, b_lat, b_lon):
    """Great-circle distance in km."""
    from math import radians, sin, cos, asin, sqrt
    if None in (a_lat, a_lon, b_lat, b_lon):
        return None
    p1, p2 = radians(a_lat), radians(b_lat)
    dp, dl = radians(b_lat - a_lat), radians(b_lon - a_lon)
    h = sin(dp / 2) ** 2 + cos(p1) * cos(p2) * sin(dl / 2) ** 2
    return 2 * 6371.0 * asin(sqrt(h))


def band(d):
    """Distance band: (label, colour, bar width %)."""
    from math import log10
    if d is None:
        return ("unknown", "#8d8880", 0)
    w = max(3, min(100, int(log10(d + 1) / log10(20000) * 100)))
    if d <= 5:    return ("on the doorstep", "#a3571a", w)
    if d <= 25:   return ("local", "#c07434", w)
    if d <= 100:  return ("nearby", "#26304d", w)
    if d <= 500:  return ("a fair way", "#4a5878", w)
    if d <= 2000: return ("distant", "#6b7590", w)
    return ("far away", "#8d8880", w)


def read_log():
    cutoff = datetime.now(timezone.utc) - timedelta(hours=HOURS)
    rows = []
    for path in (LOG_OLD, LOG):
        if not os.path.exists(path):
            continue
        with open(path, errors="replace") as f:
            for line in f:
                m = LINE.match(line)
                if not m:
                    continue
                try:
                    ts = datetime.strptime(m.group("ts"), "%d/%b/%Y:%H:%M:%S %z")
                except ValueError:
                    continue
                if ts < cutoff:
                    continue
                d = m.groupdict()
                d["dt"] = ts
                d["bot"] = bool(BOT_RE.search(d["ua"])) or d["ua"] in ("-", "")
                d["asset"] = bool(ASSET_RE.search(d["path"]))
                rows.append(d)
    return rows


def esc(s):
    return html.escape(str(s or ""))


def main():
    rows = read_log()
    people = [r for r in rows if not r["bot"] and r["ip"] != "127.0.0.1"]
    bots = [r for r in rows if r["bot"]]
    pages = [r for r in people if not r["asset"]]

    global KNOWN_IPS
    KNOWN_IPS = {**KNOWN_IPS, **learned_ips()}

    ips = sorted({r["ip"] for r in people})
    cache = geo(ips, load_cache())
    save_cache(cache)

    # home + watch outcodes, and what counts as "around" each of them
    pcc = pc_cache()
    home = outcode_latlon(HOME_OUTCODE, pcc)
    watch = outcode_latlon(WATCH_OUTCODE, pcc)
    watch_ring = set()
    if watch:
        key = f"__ring_{WATCH_OUTCODE}"
        if key not in pcc:
            pcc[key] = neighbours(watch["lat"], watch["lon"])
        watch_ring = {o.upper() for o in (pcc.get(key) or [])}
    watch_ring.add(WATCH_OUTCODE)
    try:
        os.makedirs(os.path.dirname(PC_CACHE), exist_ok=True)
        json.dump(pcc, open(PC_CACHE, "w"))
    except Exception:
        pass

    # one row per visitor
    visitors = []
    for ip in ips:
        hits = [r for r in people if r["ip"] == ip]
        pg = [r for r in hits if not r["asset"]]
        g = cache.get(ip, {})
        visitors.append({
            "ip": ip,
            "hits": len(pg) or len(hits),
            "first": min(r["dt"] for r in hits),
            "last": max(r["dt"] for r in hits),
            "paths": [r["path"] for r in pg][:6],
            "ua": hits[0]["ua"],
            "ref": next((r["ref"] for r in hits if r["ref"] not in ("-", "")), ""),
            "city": g.get("city", ""), "zip": g.get("zip", ""),
            "region": g.get("regionName", ""), "country": g.get("country", ""),
            "cc": g.get("countryCode", ""), "isp": g.get("isp", ""),
            "lat": g.get("lat"), "lon": g.get("lon"),
            "flags": [k for k in ("mobile", "proxy", "hosting") if g.get(k)],
        })
        v = visitors[-1]
        v["known"] = KNOWN_IPS.get(ip, "")
        oc = ""
        m = re.match(r"^([A-Z]{1,2}\d{1,2}[A-Z]?)", (v["zip"] or "").upper())
        if m and v["cc"] == "GB":
            oc = m.group(1)
        v["outcode"] = oc
        v["is_gu"] = oc.startswith("GU")
        v["in_watch"] = oc in watch_ring
        v["dist"] = km(home["lat"], home["lon"], v["lat"], v["lon"]) if home else None
        if v["known"]:
            # you are where you say you are, not where your ISP terminates
            v["dist"] = 0.0
            v["outcode"] = HOME_OUTCODE
            v["is_gu"] = True
            v["in_watch"] = False
            continue
        # if we have a real UK outcode, prefer postcode-to-postcode distance
        if home and oc:
            o = outcode_latlon(oc, pcc)
            if o:
                d2 = km(home["lat"], home["lon"], o["lat"], o["lon"])
                if d2 is not None:
                    v["dist"] = d2
                    v["district"] = o.get("district", "")
    # Closest first: guests by distance from home, unplaceable ones after them,
    # your own addresses at the bottom. Ties break by most recent hit.
    def _order(v):
        d = v.get("dist")
        return (1 if v.get("known") else 0, d is None, d if d is not None else 0.0, -v["last"].timestamp())
    visitors.sort(key=_order)

    day = datetime.now().strftime("%A %-d %B %Y")
    guests = [v for v in visitors if not v.get("known")]
    tag = "[TEST] " if os.environ.get("REPORT_TEST") else ""
    subject = (f"{tag}{SITE} — {len(guests)} visitor"
               f"{'s' if len(guests) != 1 else ''}, {day}")
    body = render(visitors, people, bots, pages, day, home, watch_ring)

    msg = MIMEMultipart("alternative")
    msg["Subject"] = subject
    msg["From"] = FROM
    msg["To"] = TO
    msg["Date"] = email.utils.formatdate(localtime=True)
    plain = "\n".join(
        f"{v['last']:%H:%M}  {v['ip']:<16} {v['city'] or '?'} {v['zip']}  "
        f"{v['hits']} pages  {v['isp'][:30]}" for v in visitors) or "No human visitors."
    msg.attach(MIMEText(plain, "plain", "utf-8"))
    msg.attach(MIMEText(body, "html", "utf-8"))

    p = subprocess.Popen(["/usr/sbin/sendmail", "-t", "-oi"], stdin=subprocess.PIPE)
    p.communicate(msg.as_bytes())
    print(f"sent: {len(visitors)} visitors, {len(pages)} page views, {len(bots)} bot hits")


def render(visitors, people, bots, pages, day, home=None, watch_ring=frozenset()):
    top_pages = Counter(r["path"] for r in pages).most_common(8)
    refs = Counter(r["ref"] for r in people
                   if r["ref"] not in ("-", "") and SITE not in r["ref"]).most_common(5)
    countries = Counter(v["country"] for v in visitors if v["country"]).most_common(6)

    A = "#a3571a"; INK = "#1b1a18"; MUT = "#5d5a55"; FNT = "#8d8880"
    RULE = "#e4e0d9"; BG = "#fbfaf8"; SURF = "#ffffff"; INDIGO = "#26304d"

    def stat(n, l, colour=INK):
        return (f'<td style="padding:14px 16px;background:{SURF};border:1px solid {RULE};'
                f'text-align:center"><div style="font:700 26px/1 -apple-system,Segoe UI,Arial,sans-serif;'
                f'color:{colour}">{n}</div><div style="font:500 10px/1.4 ui-monospace,Menlo,monospace;'
                f'letter-spacing:.09em;text-transform:uppercase;color:{FNT};margin-top:5px">{l}</div></td>')

    vrows = []
    for v in visitors:
        oc = v.get("outcode", "")
        if oc:
            chip_bg = A if v.get("in_watch") else (INDIGO if v.get("is_gu") else "#9a948c")
            pc = (f'<span style="display:inline-block;font:600 10px/1.6 ui-monospace,monospace;'
                  f'letter-spacing:.06em;color:#fff;background:{chip_bg};padding:1px 6px;'
                  f'border-radius:2px;margin-left:5px">{esc(oc)}</span>')
        else:
            pc = ""
        if v.get("known"):
            place = (f'<b style="color:{A}">{esc(v["known"])}</b>'
                     f'<span style="display:inline-block;font:600 10px/1.6 ui-monospace,monospace;'
                     f'letter-spacing:.06em;color:#fff;background:{A};padding:1px 6px;'
                     f'border-radius:2px;margin-left:5px">{HOME_OUTCODE}</span>'
                     f'<div style="color:{FNT};font-size:11px">IP says '
                     f'{esc(v["city"])} {esc(v["zip"])}</div>')
        else:
            place = (esc(v["city"]) or "—") + pc
        loc = f'{place}<div style="color:{FNT};font-size:11px">{esc(v["country"])}</div>'
        lbl, col, w = band(v.get("dist"))
        if v.get("dist") is not None:
            d = v["dist"]
            dtxt = f"{d:.0f} km" if d >= 1 else "<1 km"
            maps = (
              f'<div style="font:600 13px ui-monospace,Menlo,monospace;color:{col}">{dtxt}</div>'
              f'<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:4px;'
              f'background:{RULE};height:5px;border-radius:3px"><tr>'
              f'<td width="{w}%" style="background:{col};height:5px;border-radius:3px;'
              f'font-size:0;line-height:0">&nbsp;</td>'
              f'<td style="font-size:0;line-height:0">&nbsp;</td></tr></table>'
              f'<div style="font:10px ui-monospace,monospace;color:{FNT};margin-top:3px">{lbl}</div>')
        else:
            maps = f'<span style="color:{FNT};font-size:11px">unknown</span>' 
        flags = "".join(
            f'<span style="display:inline-block;font:500 9px/1.5 ui-monospace,monospace;'
            f'letter-spacing:.07em;text-transform:uppercase;color:#fff;background:'
            f'{"#b3541f" if f=="hosting" else INDIGO};padding:1px 5px;border-radius:2px;'
            f'margin-left:4px">{f}</span>' for f in v["flags"])
        paths = "<br>".join(
            f'<span style="color:{MUT}">{esc(p[:46])}</span>' for p in v["paths"]) or "—"
        vrows.append(f'''<tr>
<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:600 13px ui-monospace,Menlo,monospace;
 color:{INK};white-space:nowrap;vertical-align:top">{v["last"]:%H:%M}<div style="font-weight:400;
 font-size:11px;color:{FNT}">{esc(v["ip"])}</div></td>
<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:13px -apple-system,Segoe UI,Arial,sans-serif;
 color:{INK};vertical-align:top">{loc}{flags}</td>
<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:11px ui-monospace,Menlo,monospace;
 color:{MUT};vertical-align:top">{maps}</td>
<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:12px -apple-system,Segoe UI,Arial,sans-serif;
 color:{MUT};vertical-align:top">{esc(v["isp"][:34]) or "—"}</td>
<td style="padding:11px 12px;border-bottom:1px solid {RULE};font:600 15px ui-monospace,monospace;
 color:{A};text-align:right;vertical-align:top">{v["hits"]}</td>
</tr>
<tr><td colspan="5" style="padding:0 12px 11px 12px;border-bottom:1px solid {RULE};
 font:11px ui-monospace,Menlo,monospace">{paths}</td></tr>''')

    if not vrows:
        vrows = [f'<tr><td colspan="5" style="padding:26px;text-align:center;color:{FNT};'
                 f'font:14px -apple-system,Segoe UI,Arial,sans-serif">No human visitors in the last 24 hours.</td></tr>']

    def listbox(title, items, fmt):
        if not items:
            return ""
        li = "".join(fmt(k, n) for k, n in items)
        return f'''<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px">
<tr><td style="font:600 12px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;
 text-transform:uppercase;color:{FNT};padding-bottom:9px">{title}</td></tr>
<tr><td style="background:{SURF};border:1px solid {RULE}">{li}</td></tr></table>'''

    row = lambda k, n: (f'<div style="padding:8px 12px;border-bottom:1px solid {RULE};'
                        f'font:13px -apple-system,Segoe UI,Arial,sans-serif;color:{MUT};'
                        f'display:flex;justify-content:space-between">'
                        f'<span style="color:{INK}">{esc(k)[:58]}</span>'
                        f'<span style="font-family:ui-monospace,monospace;color:{A};'
                        f'font-weight:600">{n}</span></div>')

    # --- proximity panel -------------------------------------------------
    others = [v for v in visitors if not v.get("known")]
    local = sorted([v for v in others if v.get("dist") is not None and v["dist"] <= NEAR_KM],
                   key=lambda v: v["dist"])
    gu = [v for v in others if v.get("is_gu")]
    hits = [v for v in others if v.get("in_watch")]
    nearest = min((v for v in others if v.get("dist") is not None),
                  key=lambda v: v["dist"], default=None)

    if hits:
        headline = (f'<span style="color:{A};font-weight:700">'
                    f'{len(hits)} visitor{"s" if len(hits) != 1 else ""} from '
                    f'{WATCH_OUTCODE} or next to it</span>')
    elif local:
        headline = (f'<span style="color:{A};font-weight:700">{len(local)} within '
                    f'{NEAR_KM} km of {HOME_OUTCODE}</span>')
    elif nearest:
        headline = (f'Nearest was <b>{esc(nearest["city"] or nearest["country"])}</b>, '
                    f'{nearest["dist"]:.0f} km away')
    else:
        headline = "Nobody close enough to place"

    def chips(vs):
        return "".join(
            f'<span style="display:inline-block;font:600 11px/1.7 ui-monospace,monospace;'
            f'color:#fff;background:{A if v.get("in_watch") else INDIGO};padding:2px 7px;'
            f'border-radius:2px;margin:0 5px 5px 0">{esc(v.get("outcode") or v["city"] or "?")}'
            f' &middot; {v["dist"]:.0f}km</span>' for v in vs) or \
            f'<span style="color:{FNT};font:12px -apple-system,Segoe UI,Arial,sans-serif">none today</span>'

    proximity = f'''<tr><td style="padding-top:22px">
  <div style="font:600 12px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;
   text-transform:uppercase;color:{FNT};padding-bottom:9px">How close to home</div>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:{SURF};
   border:1px solid {RULE};border-left:3px solid {A}">
   <tr><td style="padding:14px 16px">
     <div style="font:15px/1.45 -apple-system,Segoe UI,Arial,sans-serif;color:{INK}">{headline}</div>
     <div style="font:11px ui-monospace,monospace;color:{FNT};margin-top:4px">
       measured from {HOME_OUTCODE}{" &middot; " + esc(home.get("district","")) if home else ""}
       &nbsp;|&nbsp; watching {WATCH_OUTCODE} and its neighbours</div>
     <div style="margin-top:11px">{chips(gu)}</div>
   </td></tr></table>
</td></tr>'''

    return f'''<!doctype html><html><body style="margin:0;padding:0;background:{BG}">
<table width="100%" cellpadding="0" cellspacing="0" style="background:{BG};padding:26px 14px">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%">

<tr><td style="padding-bottom:20px">
  <table cellpadding="0" cellspacing="0"><tr>
  <td style="padding-right:11px">
    <svg width="34" height="34" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
      <rect x="2" y="2" width="96" height="96" rx="20" fill="{INDIGO}"/>
      <g fill="none" stroke-width="8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 16 V46 M20 16 H33 A9 9 0 0 1 33 32 H20 M31 32 L41 46" stroke="#eef1f7"/>
        <path d="M20 54 V84 M20 54 H33 A9 9 0 0 1 33 70 H20" stroke="#eef1f7"/>
        <circle cx="70" cy="35" r="11" stroke="#f0a55e"/>
        <circle cx="70" cy="73" r="11" stroke="#f0a55e"/>
      </g></svg></td>
  <td>
    <div style="font:700 21px/1.15 -apple-system,Segoe UI,Arial,sans-serif;color:{INK};
     letter-spacing:-.02em">Who came by</div>
    <div style="font:13px -apple-system,Segoe UI,Arial,sans-serif;color:{MUT};margin-top:2px">{day}</div>
  </td></tr></table>
</td></tr>

<tr><td style="padding-bottom:4px">
  <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:6px 0">
  <tr>{stat(len([v for v in visitors if not v.get("known")]), "Visitors", A)}{stat(len(pages), "Page views")}
      {stat(len({v["country"] for v in visitors if v["country"]}), "Countries")}
      {stat(len(bots), "Bot hits", FNT)}</tr></table>
</td></tr>

{proximity}

<tr><td style="padding-top:22px">
  <div style="font:600 12px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;
   text-transform:uppercase;color:{FNT};padding-bottom:9px">Every visitor</div>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:{SURF};border:1px solid {RULE};
   border-collapse:collapse">
  <tr style="background:{BG}">
    <th align="left" style="padding:9px 12px;font:600 10px ui-monospace,monospace;letter-spacing:.09em;
     text-transform:uppercase;color:{FNT};border-bottom:1px solid {RULE}">When</th>
    <th align="left" style="padding:9px 12px;font:600 10px ui-monospace,monospace;letter-spacing:.09em;
     text-transform:uppercase;color:{FNT};border-bottom:1px solid {RULE}">Roughly where</th>
    <th align="left" style="padding:9px 12px;font:600 10px ui-monospace,monospace;letter-spacing:.09em;
     text-transform:uppercase;color:{FNT};border-bottom:1px solid {RULE}">From home</th>
    <th align="left" style="padding:9px 12px;font:600 10px ui-monospace,monospace;letter-spacing:.09em;
     text-transform:uppercase;color:{FNT};border-bottom:1px solid {RULE}">Network</th>
    <th align="right" style="padding:9px 12px;font:600 10px ui-monospace,monospace;letter-spacing:.09em;
     text-transform:uppercase;color:{FNT};border-bottom:1px solid {RULE}">Pages</th>
  </tr>
  {"".join(vrows)}
  </table>
</td></tr>

<tr><td>{listbox("Most read", top_pages, row)}</td></tr>
<tr><td>{listbox("Came from", refs, row)}</td></tr>
<tr><td>{listbox("Countries", countries, row)}</td></tr>

<tr><td style="padding-top:26px;border-top:1px solid {RULE};margin-top:20px">
  <div style="font:11px/1.65 ui-monospace,Menlo,monospace;color:{FNT}">
  Built from the Apache access log for {SITE}. <b>No cookies, no tracking pixel, no JavaScript.</b><br>
  Location is estimated from the IP address and is approximate &mdash; the postcode is an outward
  district at best, and the co-ordinates are a town centroid, not the visitor. Mobile networks and
  VPNs can be wildly out. Rows tagged <i>hosting</i> or <i>proxy</i> are usually machines, not people.
  </div>
</td></tr>

</table></td></tr></table></body></html>'''


if __name__ == "__main__":
    main()
