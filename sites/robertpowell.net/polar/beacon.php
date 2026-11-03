<?php
/**
 * /polar/beacon.php — receives playback progress from polar.js and appends one JSON line per event to
 * /var/lib/polar-video/events.jsonl (outside the docroot; www-data only). Read by polar-video-report.py.
 *
 * The 'open' event (once per page load) also records the visitor's location (local GeoLite2, incl. the
 * Apple Private Relay check) and device (Matomo Device Detector); every other event stores only the
 * numbers. Every field is validated and bounded; the file stops growing at 20 MB.
 */
declare(strict_types=1);

const LOG      = '/var/lib/polar-video/events.jsonl';
const MAX_LOG  = 20 * 1024 * 1024;
const EVENTS   = ['open', 'play', 'tick', 'pause', 'seek', 'ended', 'hide', 'close', 'error'];

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
