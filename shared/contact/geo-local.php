<?php
/* Visitor location from the databases on this box (ROP-341, copied from ropo.uk's method).
   No IP ever leaves the server: MaxMind GeoLite2 City + ASN and Apple's Private Relay egress
   ranges under /var/lib/site-visitor-report/geo/, refreshed weekly by site-report-geodata.
   Returns the same field names the old ip-api.com answer had, so callers are unchanged:
   status country countryCode regionName city zip lat lon timezone isp org as proxy hosting.
   Differences: no "mobile"; "hosting" is a guess from the network's name; lat/lon only when
   GeoLite2 knows a city to within 100 km (otherwise its point is the country centre). */

namespace GeoLocal;

const GEO_DIR = '/var/lib/site-visitor-report/geo';
const HOSTING = '/amazon|aws|google|microsoft|azure|digitalocean|ovh|hetzner|linode|akamai|vultr|choopa|'
    . 'constant company|contabo|scaleway|online s\.?a\.?s|alibaba|tencent|huawei cloud|oracle|'
    . 'm247|leaseweb|hostinger|ionos|datacamp|cdn77|cloudflare|fastly|g-core|gcore|colocrossing|'
    . 'quadranet|servers\.com|selectel|timeweb|aeza|pfcloud|stark industries|hostkey|worldstream|'
    . 'netcup|ipxo|zenlayer|interserver|frantech|buyvm|dedipath|serverion|nforce|i3d|limestone|'
    . 'webnx|kamatera|upcloud|hivelocity|psychz|tzulo|hostroyale|ucloud|bytedance|byteplus|'
    . 'cyber ?assets|internet utilities|global connectivity solutions|techoff|unmanaged ltd|'
    . 'flyservers|hosting|datacenter|data center|cloud|vps|server/i';

spl_autoload_register(function (string $c): void {
    if (strncmp($c, 'MaxMind\\Db\\', 11) !== 0) { return; }
    $f = __DIR__ . '/maxmind-db/' . str_replace('\\', '/', substr($c, 11)) . '.php';
    if (is_file($f)) { require $f; }
});

function reader(string $edition): ?\MaxMind\Db\Reader {
    static $open = [];
    if (!array_key_exists($edition, $open)) {
        try { $open[$edition] = new \MaxMind\Db\Reader(GEO_DIR . "/GeoLite2-$edition.mmdb"); }
        catch (\Throwable $e) { $open[$edition] = null; }
    }
    return $open[$edition];
}

/** Country code only (the cheap call for the country gate and the tracker). Null = lookup unavailable. */
function country(string $ip): ?string {
    $r = reader('City');
    if (!$r) { return null; }
    try { $c = $r->get($ip); } catch (\Throwable $e) { return null; }
    return $c['country']['iso_code'] ?? '';
}

/** Full record in ip-api.com's shape. ['status' => 'fail'] when the databases are unusable. */
function lookup(string $ip, bool $relay = true): array {
    $r = reader('City');
    if (!$r) { return ['status' => 'fail', 'message' => 'GeoLite2-City.mmdb unavailable']; }
    try { $c = $r->get($ip) ?? []; } catch (\Throwable $e) { return ['status' => 'fail', 'message' => 'invalid address']; }
    $loc = $c['location'] ?? [];
    $city = $c['city']['names']['en'] ?? '';
    $g = [
        'status'      => 'success',
        'country'     => $c['country']['names']['en'] ?? '',
        'countryCode' => $c['country']['iso_code'] ?? '',
        'regionName'  => $c['subdivisions'][0]['names']['en'] ?? '',
        'city'        => $city,
        'zip'         => $c['postal']['code'] ?? '',
        'timezone'    => $loc['time_zone'] ?? '',
        'isp' => '', 'org' => '', 'as' => '', 'proxy' => false, 'hosting' => false,
    ];
    if ($city !== '' && isset($loc['latitude'], $loc['longitude']) && ($loc['accuracy_radius'] ?? 9999) <= 100) {
        $g['lat'] = $loc['latitude']; $g['lon'] = $loc['longitude'];
    }
    if ($a = reader('ASN')) {
        try { $asn = $a->get($ip) ?? []; } catch (\Throwable $e) { $asn = []; }
        $org = $asn['autonomous_system_organization'] ?? '';
        $g['isp'] = $g['org'] = $org;
        if (isset($asn['autonomous_system_number'])) { $g['as'] = 'AS' . $asn['autonomous_system_number'] . ($org !== '' ? " $org" : ''); }
    }
    $g['proxy'] = $relay && private_relay($ip);
    $g['hosting'] = $g['isp'] !== '' && !$g['proxy'] && preg_match(HOSTING, $g['isp']) === 1;
    return $g;
}

/** Is the address inside one of Apple's iCloud Private Relay egress ranges? Scans the CSV (one
    prefix per line) with a cheap textual prefilter, so it costs a few tens of ms; used only where
    the result is shown to a person (contact form, QR notice), never on ordinary page loads. */
function private_relay(string $ip): bool {
    $bin = @inet_pton($ip);
    if ($bin === false) { return false; }
    $fh = @fopen(GEO_DIR . '/apple-private-relay.csv', 'rb');
    if (!$fh) { return false; }
    $v6 = strlen($bin) === 16;
    if ($v6) {
        $hex = bin2hex($bin);
        $pre = ltrim(substr($hex, 0, 4), '0') ?: '0';     // first hextet as it is written in the file
        $pre .= ':';
    } else {
        $o = explode('.', $ip);
        $pre = $o[0] . '.' . $o[1] . '.';
    }
    $plen = strlen($pre);
    $hit = false;
    while (($ln = fgets($fh)) !== false) {
        if (strncasecmp($ln, $pre, $plen) !== 0) { continue; }
        $cidr = strstr($ln, ',', true);
        if ($cidr === false || !str_contains($cidr, '/')) { continue; }
        [$net, $bits] = explode('/', $cidr, 2);
        $nb = @inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($bin)) { continue; }
        $bits = (int)$bits; $full = intdiv($bits, 8); $rem = $bits % 8;
        if (substr($bin, 0, $full) !== substr($nb, 0, $full)) { continue; }
        if ($rem === 0 || ((ord($bin[$full]) ^ ord($nb[$full])) & (0xFF << (8 - $rem) & 0xFF)) === 0) { $hit = true; break; }
    }
    fclose($fh);
    return $hit;
}

/** Age in days of the City database, for status lines. Null when missing. */
function age_days(): ?int {
    $m = @filemtime(GEO_DIR . '/GeoLite2-City.mmdb');
    return $m ? intdiv(time() - $m, 86400) : null;
}
