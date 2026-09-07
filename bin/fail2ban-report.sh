#!/bin/bash
# Fail2Ban Daily Report — emailed at 08:00 UTC via cron
# Generates an HTML summary of the last 24 hours and sends it to the specified recipient.

set -euo pipefail

RECIPIENT="mail@robertpowell.com"
HOSTNAME=$(hostname -f)
DB="/var/lib/fail2ban/fail2ban.sqlite3"
NOW=$(date +%s)
YESTERDAY=$((NOW - 86400))
REPORT_DATE=$(date -u +"%d %b %Y")
PERIOD_START=$(date -u -d "@${YESTERDAY}" +"%d %b %Y %H:%M UTC")
PERIOD_END=$(date -u +"%d %b %Y %H:%M UTC")

# ── 1. Collect per-jail stats ────────────────────────────────────────────────

JAILS=$(fail2ban-client status | grep "Jail list:" | sed 's/.*Jail list:\s*//' | tr -d ' ' | tr ',' '\n')

declare -A JAIL_CURRENT   # currently banned (live)
declare -A JAIL_24H       # bans in last 24 h (from DB)
declare -A JAIL_FAILURES  # failed attempts in last 24 h (from DB)

TOTAL_CURRENT=0
TOTAL_24H=0
TOTAL_FAILURES=0

for jail in $JAILS; do
    # Live status from fail2ban-client
    current=$(fail2ban-client status "$jail" 2>/dev/null \
        | grep "Currently banned:" | awk '{print $NF}')
    JAIL_CURRENT[$jail]=${current:-0}
    TOTAL_CURRENT=$((TOTAL_CURRENT + ${current:-0}))

    # Bans in last 24 h from SQLite
    bans24=$(sqlite3 "$DB" \
        "SELECT COUNT(*) FROM bans WHERE jail='$jail' AND timeofban >= $YESTERDAY;")
    JAIL_24H[$jail]=${bans24:-0}
    TOTAL_24H=$((TOTAL_24H + ${bans24:-0}))

    # Failed attempts (sum of json_extract failures) in last 24 h
    failures=$(sqlite3 "$DB" \
        "SELECT COALESCE(SUM(json_extract(data,'$.failures')),0) FROM bans WHERE jail='$jail' AND timeofban >= $YESTERDAY;")
    JAIL_FAILURES[$jail]=${failures:-0}
    TOTAL_FAILURES=$((TOTAL_FAILURES + ${failures:-0}))
done

# ── 2. Top 15 offending IPs (last 24 h) ─────────────────────────────────────

TOP_IPS=$(sqlite3 -separator '|' "$DB" "
    SELECT ip,
           COUNT(*) AS ban_count,
           GROUP_CONCAT(DISTINCT jail) AS jails
    FROM bans
    WHERE timeofban >= $YESTERDAY
    GROUP BY ip
    ORDER BY ban_count DESC
    LIMIT 15;
")

# ── 3. Geo-lookup via ip-api.com batch API ───────────────────────────────────

declare -A IP_COUNTRY_CODE
declare -A IP_COUNTRY_NAME

if [[ -n "$TOP_IPS" ]]; then
    # Build JSON array of IPs for the batch endpoint
    IP_LIST_JSON="["
    first=true
    while IFS='|' read -r ip _ _; do
        $first || IP_LIST_JSON+=","
        IP_LIST_JSON+="\"$ip\""
        first=false
    done <<< "$TOP_IPS"
    IP_LIST_JSON+="]"

    GEO_RESPONSE=$(curl -s --max-time 15 \
        -H "Content-Type: application/json" \
        -d "$IP_LIST_JSON" \
        "http://ip-api.com/batch?fields=query,countryCode,country,status" 2>/dev/null || true)

    # Parse JSON response (simple line-by-line with grep/sed — no jq dependency)
    if [[ -n "$GEO_RESPONSE" ]]; then
        # Extract each object block
        while IFS= read -r line; do
            ip=$(echo "$line" | sed -n 's/.*"query":"\([^"]*\)".*/\1/p')
            cc=$(echo "$line" | sed -n 's/.*"countryCode":"\([^"]*\)".*/\1/p')
            cn=$(echo "$line" | sed -n 's/.*"country":"\([^"]*\)".*/\1/p')
            if [[ -n "$ip" && -n "$cc" ]]; then
                IP_COUNTRY_CODE[$ip]="$cc"
                IP_COUNTRY_NAME[$ip]="$cn"
            fi
        done < <(echo "$GEO_RESPONSE" | tr '{' '\n')
    fi
fi

# ── 4. Country-code → flag emoji ─────────────────────────────────────────────

country_flag() {
    local cc="${1^^}"  # uppercase
    [[ ${#cc} -ne 2 ]] && return
    local a b
    # Regional indicator symbol A = U+1F1E6 = 127462
    a=$(( 127462 + $(printf '%d' "'${cc:0:1}") - 65 ))
    b=$(( 127462 + $(printf '%d' "'${cc:1:1}") - 65 ))
    printf "\\U$(printf '%08x' $a)\\U$(printf '%08x' $b)"
}

# ── 5. Build HTML email ─────────────────────────────────────────────────────

read -r -d '' HTML <<'HTMLEOF' || true
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f4f4f7;color:#333;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:24px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.07);">

<!-- Header -->
<tr><td style="background:#fa4a04;padding:28px 32px;">
  <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:600;">Fail2Ban Daily Report</h1>
  <p style="margin:6px 0 0;color:rgba(255,255,255,0.85);font-size:13px;">SERVER_NAME_PLACEHOLDER &mdash; REPORT_DATE_PLACEHOLDER</p>
</td></tr>

<!-- Period -->
<tr><td style="padding:20px 32px 0;">
  <p style="margin:0;font-size:13px;color:#888;">Reporting period: <strong>PERIOD_START_PLACEHOLDER</strong> &rarr; <strong>PERIOD_END_PLACEHOLDER</strong></p>
</td></tr>

<!-- Jail Summary -->
<tr><td style="padding:20px 32px;">
  <h2 style="margin:0 0 12px;font-size:16px;color:#fa4a04;">Jail Summary</h2>
  <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
    <tr style="background:#fa4a04;color:#fff;">
      <th style="padding:10px 12px;text-align:left;border-radius:4px 0 0 0;">Jail</th>
      <th style="padding:10px 12px;text-align:right;">Currently Banned</th>
      <th style="padding:10px 12px;text-align:right;">Bans (24 h)</th>
      <th style="padding:10px 12px;text-align:right;border-radius:0 4px 0 0;">Failed Attempts</th>
    </tr>
JAIL_ROWS_PLACEHOLDER
    <tr style="background:#2d2d2d;color:#fff;font-weight:600;">
      <td style="padding:10px 12px;border-radius:0 0 0 4px;">Total</td>
      <td style="padding:10px 12px;text-align:right;">TOTAL_CURRENT_PLACEHOLDER</td>
      <td style="padding:10px 12px;text-align:right;">TOTAL_24H_PLACEHOLDER</td>
      <td style="padding:10px 12px;text-align:right;border-radius:0 0 4px 0;">TOTAL_FAILURES_PLACEHOLDER</td>
    </tr>
  </table>
</td></tr>

<!-- Top Offenders -->
<tr><td style="padding:4px 32px 24px;">
  <h2 style="margin:0 0 12px;font-size:16px;color:#fa4a04;">Top Offenders (24 h)</h2>
  <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
    <tr style="background:#fa4a04;color:#fff;">
      <th style="padding:10px 12px;text-align:left;border-radius:4px 0 0 0;">IP Address</th>
      <th style="padding:10px 12px;text-align:left;">Country</th>
      <th style="padding:10px 12px;text-align:right;">Bans</th>
      <th style="padding:10px 12px;text-align:left;border-radius:0 4px 0 0;">Jails Triggered</th>
    </tr>
OFFENDER_ROWS_PLACEHOLDER
  </table>
</td></tr>

<!-- Footer -->
<tr><td style="padding:16px 32px;background:#fafafa;border-top:1px solid #eee;text-align:center;">
  <p style="margin:0;font-size:11px;color:#aaa;">Generated automatically &mdash; do not reply</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTMLEOF

# ── Build jail rows ──────────────────────────────────────────────────────────

JAIL_ROWS=""
row_idx=0
for jail in $JAILS; do
    if (( row_idx % 2 == 0 )); then
        bg="#ffffff"
    else
        bg="#f9f9fb"
    fi
    JAIL_ROWS+="    <tr style=\"background:${bg};\">
      <td style=\"padding:8px 12px;border-bottom:1px solid #eee;\"><code>${jail}</code></td>
      <td style=\"padding:8px 12px;text-align:right;border-bottom:1px solid #eee;\">${JAIL_CURRENT[$jail]}</td>
      <td style=\"padding:8px 12px;text-align:right;border-bottom:1px solid #eee;\">${JAIL_24H[$jail]}</td>
      <td style=\"padding:8px 12px;text-align:right;border-bottom:1px solid #eee;\">${JAIL_FAILURES[$jail]}</td>
    </tr>
"
    row_idx=$((row_idx + 1))
done

# ── Build offender rows ─────────────────────────────────────────────────────

OFFENDER_ROWS=""
if [[ -z "$TOP_IPS" ]]; then
    OFFENDER_ROWS="    <tr><td colspan=\"4\" style=\"padding:12px;text-align:center;color:#aaa;\">No bans recorded in the last 24 hours.</td></tr>
"
else
    row_idx=0
    while IFS='|' read -r ip ban_count jails_list; do
        if (( row_idx % 2 == 0 )); then
            bg="#ffffff"
        else
            bg="#f9f9fb"
        fi
        cc="${IP_COUNTRY_CODE[$ip]:-}"
        cn="${IP_COUNTRY_NAME[$ip]:-Unknown}"
        flag=""
        [[ -n "$cc" ]] && flag="$(country_flag "$cc") "
        # Format jails with commas → line-breakable
        jails_fmt=$(echo "$jails_list" | tr ',' ', ')
        OFFENDER_ROWS+="    <tr style=\"background:${bg};\">
      <td style=\"padding:8px 12px;border-bottom:1px solid #eee;\"><code>${ip}</code></td>
      <td style=\"padding:8px 12px;border-bottom:1px solid #eee;\">${flag}${cn}</td>
      <td style=\"padding:8px 12px;text-align:right;border-bottom:1px solid #eee;\">${ban_count}</td>
      <td style=\"padding:8px 12px;border-bottom:1px solid #eee;font-size:12px;\">${jails_fmt}</td>
    </tr>
"
        row_idx=$((row_idx + 1))
    done <<< "$TOP_IPS"
fi

# ── Substitute placeholders ─────────────────────────────────────────────────

HTML="${HTML//SERVER_NAME_PLACEHOLDER/$HOSTNAME}"
HTML="${HTML//REPORT_DATE_PLACEHOLDER/$REPORT_DATE}"
HTML="${HTML//PERIOD_START_PLACEHOLDER/$PERIOD_START}"
HTML="${HTML//PERIOD_END_PLACEHOLDER/$PERIOD_END}"
HTML="${HTML//JAIL_ROWS_PLACEHOLDER/$JAIL_ROWS}"
HTML="${HTML//OFFENDER_ROWS_PLACEHOLDER/$OFFENDER_ROWS}"
HTML="${HTML//TOTAL_CURRENT_PLACEHOLDER/$TOTAL_CURRENT}"
HTML="${HTML//TOTAL_24H_PLACEHOLDER/$TOTAL_24H}"
HTML="${HTML//TOTAL_FAILURES_PLACEHOLDER/$TOTAL_FAILURES}"

# ── 6. Send email ───────────────────────────────────────────────────────────

echo "$HTML" | mail -s "$(echo -e "Fail2Ban Report — ${REPORT_DATE}\nContent-Type: text/html; charset=UTF-8\nMIME-Version: 1.0")" "$RECIPIENT"

echo "Fail2Ban report sent to ${RECIPIENT} at $(date -u +"%Y-%m-%d %H:%M UTC")"
