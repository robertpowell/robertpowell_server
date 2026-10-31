#!/bin/bash
# geoip-nftables-update.sh - country-level inbound filter (nftables table inet geoip-filter).
# Adopted 2026-10-31 from the ropo.uk box (same 35 countries, RP's decision ROP-339).
# Allows: loopback, tailscale0, established/related, ICMP, the whitelist, the allowed countries'
# IPv4/IPv6 ranges (ipdeny.com) and Apple Private Relay egress ranges for those countries.
# Everything else inbound is dropped (logged, rate-limited). Runs weekly from /etc/cron.d/geoip-nftables-update;
# /etc/systemd/system/geoip-nftables.service loads the last-good file at boot, before fail2ban.
# Differences from ropo's script: the updown.io monitoring nodes are resolved from ips.updown.io at build time
# (their list changes; static copy below is the fallback), and the whitelist is this box's.
set -euo pipefail

COUNTRIES="gb at be bg hr cy cz dk ee fi fr de gr hu ie it lv lt lu mt nl pt ro sk si es se no is li ch us ca au nz jp"
OUTFILE="/etc/nftables.d/geoip-allow.nft"
TMPDIR=$(mktemp -d /tmp/geoip-nft.XXXXXX)
trap 'rm -rf "$TMPDIR"' EXIT
IPV4_BASE="https://www.ipdeny.com/ipblocks/data/aggregated"
IPV6_BASE="https://www.ipdeny.com/ipv6/ipaddresses/aggregated"
LOG_TAG="geoip-nftables"
log() { logger -t "$LOG_TAG" "$1"; echo "$(date -u +%FT%TZ) $1"; }

# --- whitelist: never country-filtered -------------------------------------------------------
# 127/8 loopback | 81.103.25.79 RP home (81.129.151.215 older) | 2a00:23c4:1229:f801::/64 RP home v6
# 178.62.75.145 188.166.137.38 2a03:b0c0:1:e0:0:1:b4e7:8001 this box | 46.101.0.165 / 2a03:b0c0:1:e0:0:1:2a66:1001 ropo.uk
STATIC_V4="127.0.0.0/8 81.103.25.79 81.129.151.215 178.62.75.145 188.166.137.38 46.101.0.165"
STATIC_V6="::1 2a00:23c4:1229:f801::/64 2a03:b0c0:1:e0:0:1:b4e7:8001 2a03:b0c0:1:e0:0:1:2a66:1001"
# updown.io monitoring nodes (fallback copy of ips.updown.io as of 2026-10-31)
UPDOWN_V4_FALLBACK="45.32.74.41 104.238.136.194 192.99.37.47 91.121.222.175 104.238.159.87 102.212.60.78 135.181.102.135 45.32.107.181 45.76.104.117 45.63.29.207 178.63.21.176 172.105.96.222"
UPDOWN_V6_FALLBACK="2001:19f0:4400:402e::1 2001:19f0:5801:1d8::1 2001:19f0:6001:2c6::1 2001:19f0:6c01:145::1 2001:19f0:7001:45a::1 2001:19f0:9002:11a::1 2001:41d0:2:85af::1 2600:3c04:e001:37f::1 2a01:4f8:141:441a::2 2a01:4f9:c010:d5f9::1 2c0f:c40:4003:4::2"
UPDOWN_V4=$(dig +short +time=5 +tries=2 ips.updown.io A 2>/dev/null | grep -E '^[0-9.]+$' | tr '\n' ' ' || true)
UPDOWN_V6=$(dig +short +time=5 +tries=2 ips.updown.io AAAA 2>/dev/null | grep -E '^[0-9a-f:]+$' | tr '\n' ' ' || true)
[ -n "$UPDOWN_V4" ] || { log "WARNING: ips.updown.io A lookup failed, using fallback list"; UPDOWN_V4="$UPDOWN_V4_FALLBACK"; }
[ -n "$UPDOWN_V6" ] || { log "WARNING: ips.updown.io AAAA lookup failed, using fallback list"; UPDOWN_V6="$UPDOWN_V6_FALLBACK"; }
WL_V4=$(echo $STATIC_V4 $UPDOWN_V4 | tr ' ' '\n' | sort -u | paste -sd, -)
WL_V6=$(echo $STATIC_V6 $UPDOWN_V6 | tr ' ' '\n' | sort -u | paste -sd, -)

log "Starting GeoIP CIDR download for nftables..."
V4_CIDRS=""; V6_CIDRS=""; DOWNLOAD_FAILED=""
fetch_zone() { local url="$1" out="$2" a; for a in 1 2 3; do if curl -sf --max-time 30 "$url" -o "$out" 2>/dev/null && [ -s "$out" ]; then return 0; fi; sleep 3; done; return 1; }
for cc in $COUNTRIES; do
    if fetch_zone "${IPV4_BASE}/${cc}-aggregated.zone" "${TMPDIR}/${cc}.v4"; then V4_CIDRS="${V4_CIDRS}$(sed '/^#/d;/^$/d' "${TMPDIR}/${cc}.v4" | tr '\n' ',')"; else log "ERROR: IPv4 download failed for ${cc}"; DOWNLOAD_FAILED="${DOWNLOAD_FAILED} ${cc}/v4"; fi
    if fetch_zone "${IPV6_BASE}/${cc}-aggregated.zone" "${TMPDIR}/${cc}.v6"; then V6_CIDRS="${V6_CIDRS}$(sed '/^#/d;/^$/d' "${TMPDIR}/${cc}.v6" | tr '\n' ',')"; else log "ERROR: IPv6 download failed for ${cc}"; DOWNLOAD_FAILED="${DOWNLOAD_FAILED} ${cc}/v6"; fi
done
# Apple Private Relay egress ranges, allowed countries only
APPLE_CSV="${TMPDIR}/apple.csv"; COUNTRIES_UPPER=$(echo "$COUNTRIES" | tr '[:lower:]' '[:upper:]' | tr ' ' '|')
if curl -sf --max-time 60 https://mask-api.icloud.com/egress-ip-ranges.csv -o "$APPLE_CSV" 2>/dev/null; then
    grep -E "^[^,]+,(${COUNTRIES_UPPER})," "$APPLE_CSV" > "${TMPDIR}/apple-f.csv" || true
    A4=$(awk -F',' '$1 !~ /:/ {print $1}' "${TMPDIR}/apple-f.csv" | tr '\n' ','); A6=$(awk -F',' '$1 ~ /:/ {print $1}' "${TMPDIR}/apple-f.csv" | tr '\n' ',')
    V4_CIDRS="${V4_CIDRS}${A4}"; V6_CIDRS="${V6_CIDRS}${A6}"
    log "Apple Private Relay: $(echo "$A4" | tr ',' '\n' | grep -c .) IPv4, $(echo "$A6" | tr ',' '\n' | grep -c .) IPv6 ranges added"
else log "WARNING: Apple Private Relay CSV download failed; continuing with ipdeny data only"; fi
V4_CIDRS="${V4_CIDRS%,}"; V6_CIDRS="${V6_CIDRS%,}"
V4_COUNT=$(echo "$V4_CIDRS" | tr ',' '\n' | wc -l); V6_COUNT=$(echo "$V6_CIDRS" | tr ',' '\n' | wc -l)
log "Downloaded ${V4_COUNT} IPv4 CIDRs, ${V6_COUNT} IPv6 CIDRs"
# Never ship a partial ruleset: a missing country would silently block all its users (ropo regression 2026-05-24).
if [ -n "$DOWNLOAD_FAILED" ]; then log "ERROR: zone download(s) failed:${DOWNLOAD_FAILED}. Keeping last-good ruleset, firewall unchanged."; exit 1; fi
[ "$V4_COUNT" -ge 1000 ] || { log "ERROR: only ${V4_COUNT} IPv4 CIDRs (expected >1000). Aborting."; exit 1; }
[ "$V6_COUNT" -ge 100 ]  || { log "ERROR: only ${V6_COUNT} IPv6 CIDRs (expected >100). Aborting."; exit 1; }

cat > "${TMPDIR}/geoip-allow.nft" <<NFTEOF
#!/usr/sbin/nft -f
# GeoIP country-level firewall - auto-generated $(date -u +%Y-%m-%dT%H:%M:%SZ) by /usr/local/sbin/geoip-nftables-update.sh
# Allows traffic from 35 countries + whitelisted IPs; all other inbound traffic is dropped.
table inet geoip-filter
delete table inet geoip-filter
table inet geoip-filter {
    set whitelist_v4 { type ipv4_addr; flags interval; auto-merge; elements = { ${WL_V4} } }
    set whitelist_v6 { type ipv6_addr; flags interval; auto-merge; elements = { ${WL_V6} } }
    set allowed_v4   { type ipv4_addr; flags interval; auto-merge; elements = { ${V4_CIDRS} } }
    set allowed_v6   { type ipv6_addr; flags interval; auto-merge; elements = { ${V6_CIDRS} } }
    chain input {
        type filter hook input priority -10; policy accept;
        iifname "lo" accept
        iifname "tailscale0" accept
        ct state established,related accept
        meta l4proto icmp accept
        meta l4proto icmpv6 accept
        ip saddr @whitelist_v4 accept
        ip6 saddr @whitelist_v6 accept
        ip saddr @allowed_v4 accept
        ip6 saddr @allowed_v6 accept
        log prefix "geoip-drop " limit rate 10/minute burst 10 packets
        counter drop
    }
}
NFTEOF
if nft -c -f "${TMPDIR}/geoip-allow.nft" 2>/dev/null; then
    cp "${TMPDIR}/geoip-allow.nft" "$OUTFILE"; chmod 600 "$OUTFILE"; log "Validated; rules written to ${OUTFILE} (whitelist v4: $(echo $WL_V4 | tr ',' '\n' | wc -l), v6: $(echo $WL_V6 | tr ',' '\n' | wc -l))"
else log "ERROR: nftables validation failed, not installing"; nft -c -f "${TMPDIR}/geoip-allow.nft" 2>&1 | logger -t "$LOG_TAG"; exit 1; fi
if [ "${1:-}" = "--apply" ]; then nft -f "$OUTFILE"; log "Rules applied."; fi
log "Done."
