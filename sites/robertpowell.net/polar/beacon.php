<?php
/**
 * /polar/beacon.php — receives playback progress from polar.js and appends one JSON line per event to
 * /var/lib/polar-video/events.jsonl (outside the docroot; www-data only). Read by polar-video-report.py.
 *
 * The 'open' event (once per page load) also records the visitor's location (local GeoLite2, incl. the
 * Apple Private Relay check) and device (Matomo Device Detector); every other event stores only the
 * numbers. Every field is validated and bounded; the file stops growing at 20 MB.
 *
 * Alert: the first time a session is seen to have watched the whole video (the browser fired `ended`, or its
 * played ranges cover >= 95 % of the length, the daily report's rule) an email goes to RP at once, sent as
 * server@robertpowell.net. One alert per session: a marker file in /var/lib/polar-video/alerted/ prevents repeats.
 */
declare(strict_types=1);

const LOG      = '/var/lib/polar-video/events.jsonl';
const MAX_LOG  = 20 * 1024 * 1024;
const EVENTS   = ['open', 'play', 'tick', 'pause', 'seek', 'ended', 'hide', 'close', 'error'];
const ALERTED  = '/var/lib/polar-video/alerted';
const ALERT_TO = 'mail@robertpowell.com';
const ALERT_FROM = 'server@robertpowell.net';
const PAGE_URL = 'https://robertpowell.net/polar/';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input', false, null, 0, 4096);
if ($raw === false || $raw === '' || strlen($raw) > 2048) { http_response_code(413); exit; }
$d = json_decode($raw, true);
if (!is_array($d)) { http_response_code(400); exit; }

$str = fn(string $k, int $n): string => mb_substr(preg_replace('/[^\x20-\x7E]/', '', (string)($d[$k] ?? '')) ?? '', 0, $n);
$num = fn(string $k): float => is_numeric($d[$k] ?? null) ? round(max(0.0, min(1e7, (float)$d[$k])), 1) : 0.0;
$int = fn(string $k): int => is_numeric($d[$k] ?? null) ? (int)max(0, min(1e6, (float)$d[$k])) : 0;

$sid = $str('sid', 48);
$ev  = $str('ev', 8);
if (!preg_match('/^[A-Za-z0-9-]{8,48}$/', $sid) || !in_array($ev, EVENTS, true)) { http_response_code(400); exit; }

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rec = [
    'ts'     => gmdate('Y-m-d\TH:i:s\Z'),
    'ip'     => $ip,
    'sid'    => $sid,
    'ev'     => $ev,
    'file'   => $str('file', 120),
    'pos'    => $num('pos'),
    'max'    => $num('max'),
    'dur'    => $num('dur'),
    'played' => $num('played'),
    'plays'  => $int('plays'),
    'seeks'  => $int('seeks'),
    'muted'  => (bool)($d['muted'] ?? false),
    'vis'    => $str('vis', 10),
];

if ($ev === 'open') {
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    $rec['ua'] = $ua;
    $rec['screen'] = $int('w') . 'x' . $int('h');
    $rec['tz'] = $str('tz', 40);
    try {
        require_once '/var/www/vendor/autoload.php';
        $dd = new \DeviceDetector\DeviceDetector($ua);
        require_once '/var/www/shared/lib/device-detector-cache.php';   // APCu cache (ROP-348)
        if ($ddCache = \RpShared\device_detector_cache()) { $dd->setCache($ddCache); }
        $dd->parse();
        $rec['device']  = trim(implode(' ', array_filter([$dd->getBrandName(), $dd->getModel()]))) ?: ($dd->getDeviceName() ?: '');
        $unk = fn($x) => in_array($x, [null, '', 'UNK'], true) ? '' : (string)$x;
        $rec['os']      = trim($unk($dd->getOs('name')) . ' ' . $unk($dd->getOs('version')));
        $rec['browser'] = trim($unk($dd->getClient('name')) . ' ' . $unk($dd->getClient('version')));
        $rec['bot']     = $dd->isBot();
    } catch (\Throwable $e) {
        $rec['device'] = $rec['os'] = $rec['browser'] = '';
        $rec['bot'] = false;
    }
    try {
        require_once '/var/www/shared/contact/geo-local.php';
        $g = \GeoLocal\lookup($ip, true);
        $rec['geo'] = ($g['status'] ?? '') === 'success'
            ? ['country' => $g['country'] ?? '', 'cc' => $g['countryCode'] ?? '', 'city' => $g['city'] ?? '',
               'zip' => $g['zip'] ?? '', 'isp' => $g['isp'] ?? '', 'proxy' => (bool)($g['proxy'] ?? false),
               'hosting' => (bool)($g['hosting'] ?? false)]
            : null;
    } catch (\Throwable $e) {
        $rec['geo'] = null;
    }
}

if (is_file(LOG) && filesize(LOG) > MAX_LOG) { error_log('polar beacon: events.jsonl over 20 MB, not writing'); http_response_code(204); exit; }
if (@file_put_contents(LOG, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) === false) {
    error_log('polar beacon: cannot write ' . LOG);
}
http_response_code(204);

// ---- watched-all-the-way-through alert (once per session) ----
$complete = $ev === 'ended' || ($rec['dur'] > 0 && $rec['played'] / $rec['dur'] >= 0.95);
if (!$complete || !is_dir(ALERTED) || file_exists(ALERTED . "/$sid")) { exit; }
if (@file_put_contents(ALERTED . "/$sid", $rec['ts'] . "\n", LOCK_EX) === false) { exit; }

// the session's open record carries the location and device
$open = null;
if (is_file(LOG) && ($fh = @fopen(LOG, 'r'))) {
    while (($line = fgets($fh)) !== false) {
        if (str_contains($line, $sid) && str_contains($line, '"ev":"open"')) { $open = json_decode($line, true); break; }
    }
    fclose($fh);
}
$mmss = fn(float $x): string => sprintf('%d:%02d', (int)($x / 60), ((int)round($x)) % 60);
$g = $open['geo'] ?? [];
$place = implode(', ', array_filter([$g['city'] ?? '', $g['zip'] ?? '', $g['country'] ?? ''])) ?: 'location unknown';
$flags = array_filter([($g['isp'] ?? ''), !empty($g['proxy']) ? 'Apple Private Relay' : '', !empty($g['hosting']) ? 'hosting/VPN' : '']);
$dev = implode(' · ', array_filter([$open['device'] ?? '', $open['os'] ?? '', $open['browser'] ?? ''])) ?: 'unknown device';
$started = $open['ts'] ?? $rec['ts'];
$fmt = function (string $iso): string {
    $d = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Europe/London'))->format('D j M H:i');
};
$length = $rec['dur'] > 0 ? $mmss($rec['dur']) : '?';
$lines = [
    "The video at " . PAGE_URL . " was watched all the way through.",
    "",
    "When:     started {$fmt($started)}, finished {$fmt($rec['ts'])} (UK time)",
    "Where:    $place" . ($flags ? ' (' . implode(', ', $flags) . ')' : '') . " - $ip",
    "Device:   $dev" . (!empty($open['screen']) ? " ({$open['screen']}" . (!empty($open['tz']) ? ", {$open['tz']}" : '') . ')' : ''),
    "Watched:  {$mmss($rec['played'])} of $length" . ($rec['seeks'] ? ", {$rec['seeks']} skip" . ($rec['seeks'] == 1 ? '' : 's') : '')
              . ($rec['plays'] > 1 ? ", {$rec['plays']} plays" : '') . ($rec['muted'] ? ', sound off' : ''),
    "File:     {$rec['file']}",
    "",
    "One alert per viewing session; the daily 08:05 email has the full picture.",
];
$headers = "From: robertpowell.net <" . ALERT_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Auto-Response-Suppress: All";
$when = (new DateTimeImmutable($rec['ts'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('H:i');
if (!@mail(ALERT_TO, "Polar video watched to the end ($place, $when)", implode("\n", $lines), $headers, '-f' . ALERT_FROM)) {
    error_log('polar beacon: alert mail failed');
}
