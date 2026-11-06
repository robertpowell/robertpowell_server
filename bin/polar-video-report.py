#!/usr/bin/python3 -I
"""
polar-video-report.py — daily email: has the /polar/ video on robertpowell.net been watched?

Reads /var/lib/polar-video/events.jsonl (written by /var/www/html/polar/beacon.php from polar.js), groups the
events into viewing sessions (one per page load) and reports the last 24 h, a day-by-day history table (last
HISTORY_DAYS calendar days, Europe/London, older days rolled into one row) and running totals. The Apache
log adds what the script cannot see: page requests from clients that never ran it (bots, text fetches) and
full downloads of the file (a 200 for the whole file, as opposed to the player's 206 range requests).

"Watched to the end" = the browser fired `ended`, or the union of its played ranges covers >= 95 % of the
length. Skipping ahead does not count as watching; the furthest point reached is shown separately.
Cron: /etc/cron.d/polar-video-report, 08:05 daily. REPORT_TEST=1 marks the subject; REPORT_HOURS widens the window.
Sent as server@robertpowell.net (every report from this box comes from the domain it is about).
"""
import datetime as dt
import email.utils
import glob
import html
import json
import os
import re
import subprocess
import zoneinfo
from collections import defaultdict
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

EVENTS = "/var/lib/polar-video/events.jsonl"
MEDIA = "/var/www/html/polar/media"
ACCESS = "/var/log/apache2/robertpowell.net-access.log"
SITE = "robertpowell.net"
PAGE = f"https://{SITE}/polar/"
TO = "mail@robertpowell.com"
SENDER = f"{SITE} <server@{SITE}>"
TZ = zoneinfo.ZoneInfo("Europe/London")
HOURS = int(os.environ.get("REPORT_HOURS", "24"))
HISTORY_DAYS = int(os.environ.get("REPORT_HISTORY_DAYS", "30"))   # rows in the by-day table
TEST = os.environ.get("REPORT_TEST") == "1"
VIDEO_EXT = (".mp4", ".m4v", ".webm", ".mov")

GREEN, AMBER, GREY, RED = "#1f7a3f", "#b7791f", "#64748b", "#b91c1c"
INK, MUTED, RULE, SURF = "#0f172a", "#64748b", "#cbd5e1", "#f8fafc"

esc = lambda s: html.escape(str(s), quote=True)


def parse_ts(s):
    return dt.datetime.strptime(s, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)


def mmss(sec):
    sec = int(round(sec or 0))
    return f"{sec // 60}:{sec % 60:02d}"


def local(t):
    return t.astimezone(TZ).strftime("%a %d %b %H:%M")


DOWNLOADS = []   # (ts, ip, geo, device-ish dict) for every Download-button click


def sessions():
    """Fold events into one record per session id; Download-button clicks go to DOWNLOADS."""
    out = {}
    if not os.path.isfile(EVENTS):
        return out
    with open(EVENTS, encoding="utf-8") as f:
        for line in f:
            try:
                e = json.loads(line)
            except ValueError:
                continue
            try:
                t = parse_ts(e["ts"])
            except (KeyError, ValueError):
                continue
            if e.get("ev") == "download":
                DOWNLOADS.append((t, e))
                continue
            s = out.setdefault(e["sid"], {"first": t, "last": t, "ip": e.get("ip", ""), "file": e.get("file", ""),
                                          "max": 0.0, "played": 0.0, "dur": 0.0, "plays": 0, "seeks": 0,
                                          "ended": False, "error": False, "muted": False, "events": 0,
                                          "geo": None, "device": "", "os": "", "browser": "", "bot": False,
                                          "screen": "", "tz": ""})
            s["first"], s["last"] = min(s["first"], t), max(s["last"], t)
            s["events"] += 1
            s["max"] = max(s["max"], float(e.get("max") or 0), float(e.get("pos") or 0))
            s["played"] = max(s["played"], float(e.get("played") or 0))
            s["dur"] = max(s["dur"], float(e.get("dur") or 0))
            s["plays"] = max(s["plays"], int(e.get("plays") or 0))
            s["seeks"] = max(s["seeks"], int(e.get("seeks") or 0))
            s["ended"] |= e.get("ev") == "ended"
            s["error"] |= e.get("ev") == "error"
            s["muted"] |= bool(e.get("muted"))
            if e.get("ev") == "open":
                for k in ("geo", "device", "os", "browser", "bot", "screen", "tz"):
                    if k in e:
                        s[k] = e[k]
            if e.get("file"):
                s["file"] = e["file"]
    return out


def verdict(s):
    """(label, colour, detail) for one session."""
    dur, played = s["dur"], s["played"]
    pct = round(100 * played / dur) if dur else None
    if s["ended"] or (dur and played / dur >= 0.95):
        return "Watched to the end", GREEN, f"{mmss(played)} of {mmss(dur)}" if dur else mmss(played)
    if played > 0:
        how = f"{pct} %, " if pct is not None else ""
        return "Watched part of it", AMBER, f"{how}{mmss(played)} played, stopped at {mmss(s['max'])}" + (f" of {mmss(dur)}" if dur else "")
    if s["error"]:
        return "Playback failed", RED, "the browser reported an error"
    return "Opened, not played", GREY, "page shown, play never pressed"


def where(s):
    g = s.get("geo") or {}
    bits = [x for x in (g.get("city"), g.get("country")) if x]
    if g.get("zip"):
        bits.insert(1, g["zip"]) if bits else bits.append(g["zip"])
    place = ", ".join(bits) or "location unknown"
    flags = []
    if g.get("proxy"):
        flags.append("Apple Private Relay")
    if g.get("hosting"):
        flags.append("hosting/VPN")
    if s.get("bot"):
        flags.append("bot")
    extra = ", ".join(x for x in (g.get("isp"),) + tuple(flags) if x)
    return place, extra


def device(s):
    parts = [p for p in (s.get("device"), s.get("os"), s.get("browser")) if p]
    return " · ".join(parts) or "unknown device"


def video_file():
    for p in sorted(glob.glob(os.path.join(MEDIA, "*"))):
        if p.lower().endswith(VIDEO_EXT) and os.path.isfile(p):
            st = os.stat(p)
            return os.path.basename(p), st.st_size, dt.datetime.fromtimestamp(st.st_mtime, dt.timezone.utc)
    return None


LOG_RE = re.compile(r'^\S+ (?P<ip>\S+) \S+ \S+ \[(?P<t>[^\]]+)\] "(?P<m>[A-Z]+) (?P<path>\S+)[^"]*" (?P<st>\d{3}) (?P<bytes>\d+|-)')


def access(since, vfile, vsize):
    """Page requests and whole-file downloads in the window, from the Apache log (current + previous)."""
    hits, hit_ips, downloads = 0, set(), []
    media = f"/polar/media/{vfile}" if vfile else None
    for path in (ACCESS + ".1", ACCESS):
        if not os.path.isfile(path):
            continue
        with open(path, encoding="utf-8", errors="replace") as f:
            for line in f:
                if "/polar" not in line:
                    continue
                m = LOG_RE.match(line)
                if not m:
                    continue
                try:
                    t = dt.datetime.strptime(m["t"], "%d/%b/%Y:%H:%M:%S %z")
                except ValueError:
                    continue
                if t < since:
                    continue
                p = m["path"].split("?")[0]
                if m["m"] in ("GET", "HEAD") and p in ("/polar", "/polar/", "/polar/index.php") and m["st"] == "200":
                    hits += 1
                    hit_ips.add(m["ip"])
                elif media and p == media and m["st"] == "200" and m["bytes"] != "-" and int(m["bytes"]) >= vsize:
                    downloads.append((t, m["ip"]))
    return hits, hit_ips, downloads


def history(allsess, now):
    """One row per local calendar day, newest first: sessions, to the end, in part, opened only, downloads, addresses.
    Covers the last HISTORY_DAYS days (zero days included once there is any data); older activity is one 'earlier' row."""
    today = now.astimezone(TZ).date()
    counts = defaultdict(lambda: {"sessions": 0, "end": 0, "part": 0, "opened": 0, "failed": 0, "dl": 0, "ips": set(),
                                  "countries": defaultdict(int)})
    for s in allsess.values():
        d = s["first"].astimezone(TZ).date()
        c = counts[d]
        c["sessions"] += 1
        c["ips"].add(s["ip"])
        g = s.get("geo") or {}
        c["countries"][(g.get("cc") or "", g.get("country") or "unknown")] += 1
        label = verdict(s)[0]
        c["end" if label == "Watched to the end" else "part" if label == "Watched part of it"
          else "failed" if label == "Playback failed" else "opened"] += 1
    for t, e in DOWNLOADS:
        d = t.astimezone(TZ).date()
        counts[d]["dl"] += 1
        counts[d]["ips"].add(e.get("ip", ""))
        g = e.get("geo") or {}
        counts[d]["countries"][(g.get("cc") or "", g.get("country") or "unknown")] += 1
    if not counts:
        return []
    first_day = min(counts)
    cutoff = today - dt.timedelta(days=HISTORY_DAYS - 1)
    rows = []
    d = today
    while d >= max(cutoff, first_day):
        c = counts.get(d)
        rows.append((d.strftime("%a %d %b"), "today" if d == today else "yesterday" if d == today - dt.timedelta(days=1) else "", c))
        d -= dt.timedelta(days=1)
    older = [k for k in counts if k < cutoff]
    if older:
        agg = {"sessions": 0, "end": 0, "part": 0, "opened": 0, "failed": 0, "dl": 0, "ips": set(), "countries": defaultdict(int)}
        for k in older:
            for f in ("sessions", "end", "part", "opened", "failed", "dl"):
                agg[f] += counts[k][f]
            agg["ips"] |= counts[k]["ips"]
            for key, n in counts[k]["countries"].items():
                agg["countries"][key] += n
        rows.append((f"Earlier ({min(older):%d %b} to {max(older):%d %b})", "", agg))
    return rows


MAX_FLAGS = 4   # flags per day in the history table, laid out 2 x 2


def flag(cc):
    """Regional-indicator emoji for a two-letter country code; white flag when unknown."""
    cc = (cc or "").upper()
    if len(cc) == 2 and cc.isalpha():
        return "".join(chr(0x1F1E6 + ord(ch) - 65) for ch in cc)
    return "\U0001F3F3\uFE0F"


def top_countries(c):
    """[(cc, name, n)] by sessions desc, at most MAX_FLAGS."""
    if not c:
        return []
    return [(cc, name, n) for (cc, name), n in sorted(c["countries"].items(), key=lambda kv: (-kv[1], kv[0][1]))][:MAX_FLAGS]


def flag_grid(c):
    """Up to four flags in a 2 x 2 nested table (email-safe), each with the country and count as a tooltip."""
    tops = top_countries(c)
    if not tops:
        return ""
    cells = [f'<td style="padding:0 2px;font-size:18px;line-height:22px;text-align:center" title="{esc(name)}: {n}">{flag(cc)}</td>'
             for cc, name, n in tops]
    rows = ["".join(cells[i:i + 2]) for i in range(0, len(cells), 2)]
    return ('<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;display:inline-table">'
            + "".join(f"<tr>{r}</tr>" for r in rows) + "</table>")


def history_html(rows):
    if not rows:
        return ""
    th = f'style="padding:6px 10px;text-align:right;font-size:12px;color:{MUTED};text-transform:uppercase;letter-spacing:.04em"'
    thl = th.replace("text-align:right", "text-align:left")
    cols = (("sessions", INK), ("end", GREEN), ("part", AMBER), ("opened", GREY), ("dl", INK))
    out = [f'<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px">'
           f'<tr><th {thl}>Day</th><th {th}>Sessions</th><th {th}>To the end</th><th {th}>In part</th>'
           f'<th {th}>Opened only</th><th {th}>Downloads</th><th {th}>Addresses</th><th {th}>From</th></tr>']
    for day, tag, c in rows:
        empty = c is None or (c["sessions"] == 0 and c["dl"] == 0)
        dim = f'color:{MUTED};' if empty else ""
        cells = []
        for key, colour in cols:
            n = 0 if c is None else c[key] + (c["failed"] if key == "opened" else 0)
            cells.append(f'<td style="padding:6px 10px;border-top:1px solid {RULE};text-align:right;{"color:" + colour + ";font-weight:600" if n and colour != INK else dim}">{n if n or not empty else "–"}</td>')
        ips = 0 if c is None else len(c["ips"])
        cells.append(f'<td style="padding:6px 10px;border-top:1px solid {RULE};text-align:right;{dim}">{ips if ips or not empty else "–"}</td>')
        cells.append(f'<td style="padding:3px 10px;border-top:1px solid {RULE};text-align:right;vertical-align:middle">{flag_grid(c)}</td>')
        out.append(f'<tr><td style="padding:6px 10px;border-top:1px solid {RULE};white-space:nowrap;{dim}">{esc(day)}'
                   + (f' <span style="color:{MUTED};font-size:12px">{tag}</span>' if tag else "") + "</td>" + "".join(cells) + "</tr>")
    out.append("</table>")
    return "".join(out)


def history_text(rows):
    if not rows:
        return []
    lines = [f"{'Day':<28}{'Sess':>6}{'End':>6}{'Part':>6}{'Open':>6}{'DL':>6}{'IPs':>6}  From"]
    for day, tag, c in rows:
        c = c or {"sessions": 0, "end": 0, "part": 0, "opened": 0, "failed": 0, "dl": 0, "ips": set(), "countries": {}}
        name = day + (f" ({tag})" if tag else "")
        frm = ", ".join(f"{cc or '??'} {n}" for cc, _, n in top_countries(c))
        lines.append(f"{name:<28}{c['sessions']:>6}{c['end']:>6}{c['part']:>6}{c['opened'] + c['failed']:>6}{c['dl']:>6}{len(c['ips']):>6}  {frm}")
    return lines


def build():
    now = dt.datetime.now(dt.timezone.utc)
    since = now - dt.timedelta(hours=HOURS)
    allsess = sessions()
    recent = sorted((s for s in allsess.values() if s["last"] >= since), key=lambda s: s["first"], reverse=True)
    vf = video_file()
    vfile, vsize = (vf[0], vf[1]) if vf else (None, 0)
    hits, hit_ips, downloads = access(since, vfile, vsize)
    script_ips = {s["ip"] for s in recent}
    silent = sorted(hit_ips - script_ips)

    # headline
    ends = [s for s in recent if verdict(s)[0] == "Watched to the end"]
    parts = [s for s in recent if verdict(s)[0] == "Watched part of it"]
    opened = [s for s in recent if verdict(s)[0] == "Opened, not played"]
    if ends:
        head, colour = (f"Watched to the end ({len(ends)} time{'s' if len(ends) != 1 else ''})", GREEN)
    elif parts:
        head, colour = (f"Watched in part ({len(parts)} session{'s' if len(parts) != 1 else ''}, none to the end)", AMBER)
    elif opened:
        head, colour = (f"Page opened ({len(opened)}), video not played", GREY)
    elif hits:
        head, colour = (f"{hits} page request{'s' if hits != 1 else ''}, no browser playback", GREY)
    else:
        head, colour = ("Not watched", GREY)
    if vf is None:
        head, colour = ("No video uploaded yet", RED)

    # totals
    total = list(allsess.values())
    t_end = sum(1 for s in total if verdict(s)[0] == "Watched to the end")
    t_part = sum(1 for s in total if verdict(s)[0] == "Watched part of it")
    t_ips = len({s["ip"] for s in total})
    t_dl = len(DOWNLOADS)
    first = min((s["first"] for s in total), default=None)

    hist = history(allsess, now)
    hist_html = history_html(hist)

    # html
    rows = []
    for s in recent:
        label, c, detail = verdict(s)
        place, extra = where(s)
        rows.append(
            f'<tr><td style="padding:8px 10px;border-top:1px solid {RULE};white-space:nowrap;vertical-align:top">{esc(local(s["first"]))}'
            + (f'<br><span style="color:{MUTED};font-size:12px">to {esc(s["last"].astimezone(TZ).strftime("%H:%M"))}</span>' if s["last"] - s["first"] >= dt.timedelta(minutes=1) else "")
            + f'</td><td style="padding:8px 10px;border-top:1px solid {RULE};vertical-align:top">{esc(place)}'
            + (f'<br><span style="color:{MUTED};font-size:12px">{esc(extra)}</span>' if extra else "")
            + f'<br><span style="color:{MUTED};font-size:12px">{esc(s["ip"])}</span>'
            + f'</td><td style="padding:8px 10px;border-top:1px solid {RULE};vertical-align:top">{esc(device(s))}'
            + (f'<br><span style="color:{MUTED};font-size:12px">{esc(s["screen"])}{", " + esc(s["tz"]) if s.get("tz") else ""}</span>' if s.get("screen") else "")
            + f'</td><td style="padding:8px 10px;border-top:1px solid {RULE};vertical-align:top"><span style="color:{c};font-weight:600">{esc(label)}</span>'
            + f'<br><span style="color:{MUTED};font-size:12px">{esc(detail)}'
            + (f'; {s["plays"]} play{"s" if s["plays"] != 1 else ""}' if s["plays"] > 1 else "")
            + (f'; {s["seeks"]} skip{"s" if s["seeks"] != 1 else ""}' if s["seeks"] else "")
            + ("; sound off" if s["muted"] else "") + "</span></td></tr>")
    th = f'style="padding:8px 10px;text-align:left;font-size:12px;color:{MUTED};text-transform:uppercase;letter-spacing:.04em"'
    table = (f'<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px">'
             f'<tr><th {th}>When</th><th {th}>Where</th><th {th}>Device</th><th {th}>Result</th></tr>{"".join(rows)}</table>'
             if rows else f'<p style="margin:0;color:{MUTED}">No viewing sessions in the last {HOURS} hours.</p>')

    notes = []
    if silent:
        notes.append(f"{len(silent)} other address{'es' if len(silent) != 1 else ''} fetched the page without running the player script "
                     f"(bots or link previews): {', '.join(silent[:6])}{' …' if len(silent) > 6 else ''}.")
    clicks = sorted((t, e) for t, e in DOWNLOADS if t >= since)
    if clicks:
        notes.append(f"Download button clicked {len(clicks)} time{'s' if len(clicks) != 1 else ''}: "
                     + "; ".join(f"{esc(local(t))} from {esc(where(e)[0])} ({esc(e.get('ip', ''))}, {esc(device(e))})" for t, e in clicks[:6]) + ".")
    if downloads:
        notes.append(f"The file was downloaded in full {len(downloads)} time{'s' if len(downloads) != 1 else ''} "
                     f"(not via the player): " + ", ".join(f"{esc(local(t))} from {esc(ip)}" for t, ip in downloads[:6]) + ".")
    if vf:
        video_line = (f"<strong>{esc(vf[0])}</strong>, {vf[1] / 1048576:.1f} MB, uploaded {esc(local(vf[2]))}")
    else:
        video_line = "<strong>no video uploaded yet</strong>: the page shows a holding message"
    totals = (f"Since {esc(local(first))}: {len(total)} session{'s' if len(total) != 1 else ''}, {t_end} watched to the end, "
              f"{t_part} in part, {t_dl} download click{'s' if t_dl != 1 else ''}, {t_ips} distinct address{'es' if t_ips != 1 else ''}." if first else "No viewing sessions recorded yet.")

    html_body = f"""<!doctype html><html><body style="margin:0;padding:24px;background:#ffffff;color:{INK};font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">
<table cellpadding="0" cellspacing="0" style="max-width:760px;width:100%;border-collapse:collapse">
<tr><td style="background:{colour};color:#ffffff;padding:18px 20px;border-radius:8px 8px 0 0">
  <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.85">Polar video · last {HOURS} hours</div>
  <div style="font-size:22px;font-weight:600;margin-top:4px">{esc(head)}</div></td></tr>
<tr><td style="background:{SURF};border:1px solid {RULE};border-top:0;padding:16px 20px">{table}</td></tr>
{f'<tr><td style="border:1px solid {RULE};border-top:0;padding:14px 20px"><div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:{MUTED};margin-bottom:8px">Day by day · last {min(HISTORY_DAYS, len(hist))} days</div>{hist_html}</td></tr>' if hist_html else ""}
<tr><td style="border:1px solid {RULE};border-top:0;padding:14px 20px;font-size:13px;color:{MUTED}">
  <div>{totals}</div>
  <div style="margin-top:6px">Video: {video_line}. Page: <a href="{PAGE}" style="color:{MUTED}">{esc(PAGE)}</a></div>
  {"".join(f'<div style="margin-top:6px">{n}</div>' for n in notes)}
  <div style="margin-top:10px;font-size:12px">"Watched to the end" means the browser reached the end, or its played ranges cover at least 95 % of the video.
  Skipping ahead is not counted as watching. The player reports every 10 s, so at most the last 10 s of a session can be missing if the tab is closed abruptly.
  Whether anyone was looking at the screen cannot be known.</div>
</td></tr></table></body></html>"""

    text = [f"Polar video, last {HOURS} h: {head}", ""]
    for s in recent:
        label, _, detail = verdict(s)
        place, extra = where(s)
        text.append(f"{local(s['first'])}  {place}{' (' + extra + ')' if extra else ''}  {s['ip']}  {device(s)}  {label}: {detail}")
    if not recent:
        text.append(f"No viewing sessions in the last {HOURS} hours.")
    if hist:
        text += ["", "Day by day:", *history_text(hist)]
    text += ["", re.sub("<[^>]+>", "", totals), "Video: " + re.sub("<[^>]+>", "", video_line), *[re.sub("<[^>]+>", "", n) for n in notes]]
    subject = ("[test] " if TEST else "") + f"Polar video: {head}"
    return subject, html_body, "\n".join(text)


def send(subject, html_body, text_body):
    msg = MIMEMultipart("alternative")
    msg["Subject"], msg["From"], msg["To"] = subject, SENDER, TO
    msg["Date"] = email.utils.formatdate(localtime=True)
    msg.attach(MIMEText(text_body, "plain", "utf-8"))
    msg.attach(MIMEText(html_body, "html", "utf-8"))
    p = subprocess.Popen(["/usr/sbin/sendmail", "-t", "-oi", "-f", f"server@{SITE}"], stdin=subprocess.PIPE)
    p.communicate(msg.as_bytes())
    return p.returncode


if __name__ == "__main__":
    subject, h, t = build()
    if os.environ.get("REPORT_DRY") == "1":
        print(subject); print(t)
    else:
        rc = send(subject, h, t)
        print(f"{dt.datetime.now(dt.timezone.utc):%Y-%m-%d %H:%M:%S} sent: {subject} (sendmail rc={rc})")
