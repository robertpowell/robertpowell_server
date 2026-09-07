#!/bin/bash
# Copy the repository checkout OUT to the live locations, following the manifest, then reload services.
#   ./deploy.sh            dry run: show what would change, touch nothing
#   ./deploy.sh --apply    copy files, set owner/mode, test and reload Apache, reload fail2ban,
#                          daemon-reload + restart ropo-blog when their files changed
# Never deletes live files (data directories and secrets live beside the code); remove those by hand.
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"
. ./lib.sh
MODE=dry; [ "${1:-}" = "--apply" ] && MODE=apply
CHANGED=$(for_each_entry deploy "$MODE")
if [ "$MODE" = dry ]; then
    [ -n "$CHANGED" ] && printf '%s\n' "$CHANGED" || echo "Nothing to deploy: live matches the checkout."
    echo "(dry run; re-run with --apply to make these changes)"
    exit 0
fi
[ -z "$CHANGED" ] && { echo "Nothing to deploy: live matches the checkout."; exit 0; }
printf '%s\n' "$CHANGED"
if grep -q '^etc/apache2\|^sites/\|^shared/' <<<"$CHANGED"; then
    apache2ctl configtest && systemctl reload apache2 && echo "apache2 reloaded"
fi
if grep -q '^etc/fail2ban' <<<"$CHANGED"; then
    fail2ban-client reload >/dev/null && echo "fail2ban reloaded"
fi
if grep -q '^etc/systemd' <<<"$CHANGED"; then
    systemctl daemon-reload && echo "systemd reloaded"
fi
if grep -q '^blog/\|^etc/systemd' <<<"$CHANGED"; then
    systemctl restart ropo-blog && echo "ropo-blog restarted"
fi
if grep -q '^etc/postfix\|^etc/opendkim' <<<"$CHANGED"; then
    for m in sender_canonical sender_domains; do [ -f /etc/postfix/$m ] && postmap /etc/postfix/$m; done
    systemctl reload postfix opendkim && echo "postfix + opendkim reloaded"
fi
echo "Deployed."
