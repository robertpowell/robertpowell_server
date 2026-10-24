#!/usr/bin/env python3
"""Alert when robertpowell.net visit tracking stops recording.

track.php writes one row per page view into MySQL visits.events. On 2026-10-23 MySQL was
stopped by a package postrm and nobody noticed for 22 h (no rows, nightly restic aborted).
Runs hourly from /etc/cron.d/visit-tracking-check. Checks, in order:
  1. mysql.service active and mysqld answering on its socket
  2. no "Database connection failed" from track.php in the Apache error log in the last hour
  3. if robertpowell.net served real pages in the last 3 h, at least one row landed in visits.events
Mails mail@robertpowell.com from server@robertpowell.net at most once per 6 h while broken,
and once more when it recovers. State lives in /var/lib/visit-tracking-check/.
    --selftest   send the alert mail with fake findings; does not touch state
"""
import json, os, re, subprocess, sys, time
from datetime import datetime, timedelta, timezone

MAILTO = "mail@robertpowell.com"
SENDER = "server@robertpowell.net"
HOST = "robertpowell.net"
ERROR_LOG = "/var/log/apache2/error.log"
ACCESS_LOG = "/var/log/apache2/robertpowell.net-access.log"
STATE_DIR = "/var/lib/visit-tracking-check"
STATE = os.path.join(STATE_DIR, "state.json")
REALERT_HOURS = 6
HIT_WINDOW_H = 3          # how far back to compare page hits with DB rows
MIN_HITS = 5              # fewer real hits than this and silence proves nothing
OWN_IPS = {"127.0.0.1", "::1", "178.62.75.145", "188.166.137.38", "2a03:b0c0:1:e0:0:1:b4e7:8001"}

now = datetime.now(timezone.utc)


def sh(cmd, timeout=30):
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return r.returncode, r.stdout.strip(), r.stderr.strip()
    except Exception as e:                      # noqa: BLE001
        return 99, "", str(e)


def tail_lines(path, max_bytes=4_000_000):
    try:
        with open(path, "rb") as f:
            f.seek(0, 2)
            size = f.tell()
            f.seek(max(0, size - max_bytes))
            return f.read().decode("utf-8", "replace").splitlines()
    except FileNotFoundError:
        return []


def check():
    problems, notes = [], []

    rc, out, _ = sh(["systemctl", "is-active", "mysql"])
    if out != "active":
        problems.append(f"mysql.service is {out or 'unknown'} (not active)")
    rc, _, err = sh(["mysqladmin", "ping", "--silent"])
    if rc != 0:
        problems.append("mysqld is not answering on its socket: " + (err.splitlines() or ["?"])[-1])

    # 2. track.php connection failures in the last hour
    since = now - timedelta(hours=1)
    pat = re.compile(r"^\[\w{3} (\w{3}) +(\d{1,2}) (\d\d):(\d\d):(\d\d)\.\d+ (\d{4})\]")
    fails, last = 0, ""
    for line in tail_lines(ERROR_LOG):
        if "Database connection failed" not in line and "track.php" not in line:
            continue
        m = pat.match(line)
        if not m:
            continue
        try:
            t = datetime.strptime(" ".join(m.groups()), "%b %d %H %M %S %Y").replace(tzinfo=timezone.utc)
        except ValueError:
            continue
        if t >= since:
            fails += 1
            last = line[:220]
    if fails:
        problems.append(f"{fails} track.php database error(s) in the last hour; latest:\n    {last}")

    # 3. hits served vs rows recorded
    since = now - timedelta(hours=HIT_WINDOW_H)
    apat = re.compile(r'^\S+ (\S+) \S+ \S+ \[(\d\d/\w{3}/\d{4}:\d\d:\d\d:\d\d) [+-]\d{4}\] "GET ([^ "]+) [^"]*" 200 ')
    hits = 0
    for line in tail_lines(ACCESS_LOG):
        m = apat.match(line)
        if not m or m.group(1) in OWN_IPS:
            continue
        path = m.group(3).split("?")[0]
        if not (path == "/" or path.endswith("/") or path.endswith(".php") or path.endswith(".html")):
            continue
        try:
            t = datetime.strptime(m.group(2), "%d/%b/%Y:%H:%M:%S").replace(tzinfo=timezone.utc)
        except ValueError:
            continue
        if t >= since:
            hits += 1
    rc, out, err = sh(["mysql", "-N", "-e",
                       f"SELECT COUNT(*), IFNULL(UNIX_TIMESTAMP(MAX(timestamp)),0) FROM visits.events "
                       f"WHERE timestamp >= FROM_UNIXTIME({int(since.timestamp())})"])
    if rc != 0:
        problems.append("query against visits.events failed: " + (err.splitlines() or ["?"])[-1])
        rows = None
    else:
        rows, newest = (int(x) for x in out.split())
        notes.append(f"last {HIT_WINDOW_H} h: {hits} page hits served, {rows} rows recorded")
        if hits >= MIN_HITS and rows == 0:
            problems.append(f"robertpowell.net served {hits} pages in the last {HIT_WINDOW_H} h but "
                            f"visits.events gained 0 rows (newest row {datetime.fromtimestamp(newest, timezone.utc):%F %T} UTC)"
                            if newest else
                            f"robertpowell.net served {hits} pages in the last {HIT_WINDOW_H} h but visits.events gained 0 rows")
    return problems, notes


def mail(subject, body):
    msg = (f"From: {HOST} <{SENDER}>\nTo: {MAILTO}\nSubject: [{HOST}] {subject}\n"
           f"Date: {now:%a, %d %b %Y %H:%M:%S +0000}\n\n{body}\n")
    subprocess.run(["sendmail", "-t", "-oi", "-f", SENDER], input=msg, text=True, timeout=60)


def load_state():
    try:
        with open(STATE) as f:
            return json.load(f)
    except (FileNotFoundError, ValueError):
        return {}


def save_state(s):
    os.makedirs(STATE_DIR, exist_ok=True)
    with open(STATE, "w") as f:
        json.dump(s, f)


FIX = ("Usual cause and fix (see 2026-10-23 incident):\n"
       "  install -d -o mysql -g adm -m 2750 /var/log/mysql\n"
       "  systemctl start mysql && mysqladmin ping\n"
       "Then check: tail /var/log/mysql/error.log ; grep 'Database connection failed' /var/log/apache2/error.log | tail\n"
       f"This check runs hourly ({sys.argv[0]}) and re-alerts every {REALERT_HOURS} h until the problem clears.")


def main():
    if "--selftest" in sys.argv:
        mail("SELFTEST: visit tracking alert", "This is a test of the hourly visit-tracking check. "
             "A real alert would list findings like:\n\n  - mysql.service is inactive (not active)\n"
             "  - 12 track.php database error(s) in the last hour\n\n" + FIX)
        print("selftest mail sent")
        return 0
    problems, notes = check()
    state = load_state()
    if problems:
        last = state.get("alerted_at", 0)
        if time.time() - last >= REALERT_HOURS * 3600:
            body = ("Visit tracking on robertpowell.net is NOT recording.\n\nFindings:\n"
                    + "".join(f"  - {p}\n" for p in problems)
                    + ("\n" + "\n".join(notes) + "\n" if notes else "")
                    + "\n" + FIX)
            mail("Visit tracking BROKEN", body)
            state.update(alerted_at=time.time(), broken_since=state.get("broken_since") or time.time())
            save_state(state)
            print("ALERT sent:", "; ".join(problems))
        else:
            print("still broken, alert suppressed:", "; ".join(problems))
        return 1
    if state.get("broken_since"):
        dur = timedelta(seconds=int(time.time() - state["broken_since"]))
        mail("Visit tracking recovered", f"Visit tracking is recording again after {dur}.\n\n" + "\n".join(notes))
        save_state({})
        print("recovered, mail sent")
    else:
        print("ok;", "; ".join(notes))
    return 0


if __name__ == "__main__":
    sys.exit(main())
