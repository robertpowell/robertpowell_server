#!/bin/bash
# One-shot health report after the 2026-09-06 reboot (hardening plan item T2).
# Run by /etc/cron.d/post-reboot-check at boot; removes that cron entry when done.
sleep 90
K=$(uname -r); WANT="6.8.0-139-generic"
out="Post-reboot check on $(hostname) at $(date -u '+%Y-%m-%d %H:%M:%S UTC')\n\n"
ok=0; bad=0
line() { if [ "$2" = "ok" ]; then ok=$((ok+1)); else bad=$((bad+1)); fi; out+="$(printf '%-38s %s\n' "$1" "$2")\n"; }
[ "$K" = "$WANT" ] && line "kernel $K" ok || line "kernel $K (wanted $WANT)" FAIL
for s in apache2 php8.4-fpm ropo-blog postfix fail2ban mysql ufw; do
  st=$(systemctl is-active "$s" 2>/dev/null); [ "$st" = "active" ] && line "service $s" ok || line "service $s" "FAIL ($st)"
done
mountpoint -q /mnt/ropo_volume && line "mount /mnt/ropo_volume" ok || line "mount /mnt/ropo_volume" FAIL
for h in highlyconfidential.co.uk rpowell.co.uk ultrasecret.net new.robertpowell.com; do
  c=$(curl -s -m 15 --resolve "$h:443:127.0.0.1" -o /dev/null -w '%{http_code}' "https://$h/contact.php")
  [ "$c" = "200" ] && line "https://$h/contact.php" ok || line "https://$h/contact.php" "FAIL ($c)"
done
c=$(curl -s -m 15 --resolve 'robertpowell.net:443:[2a03:b0c0:1:e0:0:1:b4e7:8001]' -o /dev/null -w '%{http_code}' https://robertpowell.net/contact.php)
[ "$c" = "200" ] && line "https://robertpowell.net/contact.php" ok || line "https://robertpowell.net/contact.php" "FAIL ($c)"
j=$(fail2ban-client status 2>/dev/null | grep -c "Jail list")
[ "$j" = "1" ] && line "fail2ban responding" ok || line "fail2ban responding" FAIL
out+="\n$ok checks passed, $bad failed.\n"
[ "$bad" = "0" ] && subj="[robertpowell.net] Reboot OK: $ok/$((ok+bad)) checks passed" || subj="[robertpowell.net] Reboot: $bad CHECK(S) FAILED"
printf "From: robertpowell.net <server@robertpowell.net>\nTo: mail@robertpowell.com\nSubject: %s\nDate: %s\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n\n%b" "$subj" "$(date -R)" "$out" | /usr/sbin/sendmail -f server@robertpowell.net mail@robertpowell.com
printf "%b" "$out" >> /var/log/post-reboot-check.log
rm -f /etc/cron.d/post-reboot-check
