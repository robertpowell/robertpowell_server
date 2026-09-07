#!/bin/bash
# Nightly restic backup of this box to Backblaze B2 (bucket RPServer26, S3 API).
# Credentials: /root/.restic.env   Repository password: /root/.restic-password
# Excludes:    /etc/restic/excludes   Log: /var/log/restic-backup.log
# Retention:   7 daily, 4 weekly, 6 monthly. Prune + integrity check on Sundays.
# Mails "Backup FAILED" on any error, and a short "Backup OK" summary on Sundays.
set -uo pipefail
set -a; . /root/.restic.env; set +a
export RESTIC_PASSWORD_FILE=/root/.restic-password
export RESTIC_CACHE_DIR=/var/cache/restic
LOG=/var/log/restic-backup.log
PRE=/var/backups/restic-pre
MAILTO=mail@robertpowell.com
SENDER=server@robertpowell.net
FROM="robertpowell.net <$SENDER>"
HOST=$(hostname)
DOW=$(date +%u)   # 7 = Sunday

mkdir -p "$PRE" "$RESTIC_CACHE_DIR"
chmod 700 "$PRE" "$RESTIC_CACHE_DIR"

log() { echo "$(date -u +'%F %T') $*" >> "$LOG"; }
mail_out() {  # subject body
    printf 'From: %s\nTo: %s\nSubject: [%s] %s\nDate: %s\n\n%s\n' \
        "$FROM" "$MAILTO" "$HOST" "$1" "$(date -R)" "$2" | sendmail -t -oi -f "$SENDER"
}
fail() {
    log "FAILED: $1"
    mail_out "Backup FAILED: $1" "$1

Last 30 log lines from $LOG:

$(tail -30 "$LOG")"
    exit 1
}

log "start"

# Consistent database copies (the live files are not safe to copy directly).
mysqldump --single-transaction --routines --events visits | gzip -1 > "$PRE/visits.sql.gz" \
    || fail "mysqldump of visits"
sqlite3 /opt/ropo-blog/data/blog.db ".backup '$PRE/blog.db'" \
    || fail "sqlite3 backup of blog.db"

# Cheap inventory that makes a rebuild much easier.
dpkg --get-selections > "$PRE/dpkg-selections.txt"
crontab -l > "$PRE/root-crontab.txt" 2>/dev/null || true
ufw status numbered > "$PRE/ufw-status.txt" 2>/dev/null || true
systemctl list-unit-files --state=enabled --no-legend > "$PRE/enabled-units.txt" 2>/dev/null || true

restic backup --quiet --tag nightly --exclude-file=/etc/restic/excludes \
    /etc /root /home /var/www /opt/ropo-blog /var/spool/cron \
    /usr/local/bin /usr/local/sbin /mnt/ropo_volume/robertpowell-migration "$PRE" \
    >> "$LOG" 2>&1
rc=$?
# 3 = snapshot made but some files could not be read (warnings). Anything else is a failure.
if [ $rc -ne 0 ] && [ $rc -ne 3 ]; then fail "restic backup exited $rc"; fi
[ $rc -eq 3 ] && log "warning: some files were unreadable (exit 3), snapshot still made"

if [ "$DOW" -eq 7 ]; then
    restic forget --quiet --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --prune >> "$LOG" 2>&1 \
        || fail "restic forget/prune"
    restic check --quiet --read-data-subset=5% >> "$LOG" 2>&1 \
        || fail "restic check"
    SUMMARY=$(restic snapshots --compact 2>/dev/null | tail -n +1)
    STATS=$(restic stats --mode raw-data 2>/dev/null | grep -E 'Snapshots|Total Size')
    log "weekly prune + check OK"
    mail_out "Backup OK: weekly check passed" "Nightly restic backups to Backblaze B2 (RPServer26) are healthy.

$STATS

Snapshots kept:
$SUMMARY

Log: $LOG on $HOST"
else
    restic forget --quiet --keep-daily 7 --keep-weekly 4 --keep-monthly 6 >> "$LOG" 2>&1 \
        || fail "restic forget"
fi

log "done"
