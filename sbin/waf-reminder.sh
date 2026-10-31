#!/bin/bash
# One-shot reminder to RP (asked 2026-10-31) to switch ModSecurity from DetectionOnly to On. Removes itself after sending.
HITS=$(grep -c -- '-A--$' /var/log/apache2/modsec_audit.log 2>/dev/null || echo 0)
MODE=$(awk '/^SecRuleEngine/{print $2}' /etc/modsecurity/modsecurity.conf)
/usr/sbin/sendmail -t <<MAIL
From: robertpowell.net <server@robertpowell.net>
To: mail@robertpowell.com
Subject: Reminder: switch the WAF to blocking (ROP-340)
Content-Type: text/plain; charset=utf-8

You asked on 31 October to be reminded today to switch ModSecurity from detection-only to blocking.

Current state: SecRuleEngine ${MODE}; ${HITS} audit-log entries so far (today's log, see the WAF row in the daily reports for the trend).

Steps (or ask Claude on the box to do them):
  1. Review /var/log/apache2/modsec_audit.log* for false positives, especially the blog editor (/admin/*) and the contact forms (POST /contact.php).
  2. Add scoped exclusions to /etc/modsecurity/crs/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf.
  3. Set "SecRuleEngine On" in /etc/modsecurity/modsecurity.conf, then: apache2ctl configtest && systemctl reload apache2
  4. Check the apache-modsecurity fail2ban jail starts banning: fail2ban-client status apache-modsecurity

Linear: https://linear.app/ropo/issue/ROP-340
MAIL
rm -f /etc/cron.d/waf-reminder /usr/local/sbin/waf-reminder.sh
