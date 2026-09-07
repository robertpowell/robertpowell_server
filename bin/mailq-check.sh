#!/bin/bash
# Alert if the Postfix queue holds anything for more than an hour (plan phase 5). Runs hourly from /etc/cron.d/mailq-check.
n=$(find /var/spool/postfix/deferred /var/spool/postfix/active -type f -mmin +60 2>/dev/null | wc -l)
[ "$n" -gt 0 ] || exit 0
{ echo "Date: $(date -R)"; echo "Subject: [robertpowell.net] $n message(s) stuck in the Postfix queue"; echo "From: server@robertpowell.net"; echo "To: server@robertpowell.net"; echo; mailq; echo; grep -E "postfix/smtp\[.*(defer|bounce|timed out|refused)" /var/log/mail.log | tail -20; } | sendmail -t -i -f server@robertpowell.net
