<?php
/**
 * /polar/download.php — the page's "Download video" button.
 *
 * Records the click as a 'download' event in /var/lib/polar-video/events.jsonl (same shape as beacon.php's
 * open event: location, device), emails RP at once (one alert per address per 10 minutes, so a double click
 * is one email), then redirects to the file with ?dl=1, which polar/.htaccess turns into an attachment.
 * The session id from polar.js is passed through so the click lines up with the viewing session.
 */
declare(strict_types=1);

const LOG      = '/var/lib/polar-video/events.jsonl';
const MARK_DIR = '/var/lib/polar-video/alerted';
const MEDIA    = __DIR__ . '/media';
const ALERT_TO = 'mail@robertpowell.com';
const ALERT_FROM = 'server@robertpowell.net';
const PAGE_URL = 'https://robertpowell.net/polar/';
const TYPES    = ['mp4', 'm4v', 'webm', 'mov'];

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$file = null;
$names = glob(MEDIA . '/*') ?: [];
sort($names);
foreach ($names as $f) {
    if (is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), TYPES, true)) { $file = basename($f); break; }
}
if ($file === null) { http_response_code(404); exit('No video.'); }

$sid = (string)($_GET['sid'] ?? '');
if (!preg_match('/^[A-Za-z0-9-]{8,48}$/', $sid)) { $sid = 'dl-' . bin2hex(random_bytes(8)); }
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
$rec = ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'ip' => $ip, 'sid' => $sid, 'ev' => 'download', 'file' => $file,
        'pos' => 0, 'max' => 0, 'dur' => 0, 'played' => 0, 'plays' => 0, 'seeks' => 0, 'muted' => false, 'vis' => '', 'ua' => $ua];
try {
    require_once '/var/www/vendor/autoload.php';
    $dd = new \DeviceDetector\DeviceDetector($ua);
    $dd->parse();
    $unk = fn($x) => in_array($x, [null, '', 'UNK'], true) ? '' : (string)$x;
    $rec['device']  = trim(implode(' ', array_filter([$dd->getBrandName(), $dd->getModel()]))) ?: ($dd->getDeviceName() ?: '');
    $rec['os']      = trim($unk($dd->getOs('name')) . ' ' . $unk($dd->getOs('version')));
    $rec['browser'] = trim($unk($dd->getClient('name')) . ' ' . $unk($dd->getClient('version')));
    $rec['bot']     = $dd->isBot();
} catch (\Throwable $e) { $rec['device'] = $rec['os'] = $rec['browser'] = ''; $rec['bot'] = false; }
try {
    require_once '/var/www/shared/contact/geo-local.php';
    $g = \GeoLocal\lookup($ip, true);
    $rec['geo'] = ($g['status'] ?? '') === 'success'
        ? ['country' => $g['country'] ?? '', 'cc' => $g['countryCode'] ?? '', 'city' => $g['city'] ?? '', 'zip' => $g['zip'] ?? '',
           'isp' => $g['isp'] ?? '', 'proxy' => (bool)($g['proxy'] ?? false), 'hosting' => (bool)($g['hosting'] ?? false)]
        : null;
} catch (\Throwable $e) { $rec['geo'] = null; }
@file_put_contents(LOG, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// alert, at most once per address per 10 minutes
$mark = MARK_DIR . '/dl-' . preg_replace('/[^A-Za-z0-9]/', '_', $ip);
if (is_dir(MARK_DIR) && (!is_file($mark) || time() - (int)filemtime($mark) > 600)) {
    @touch($mark);
    $g = $rec['geo'] ?? [];
    $place = implode(', ', array_filter([$g['city'] ?? '', $g['zip'] ?? '', $g['country'] ?? ''])) ?: 'location unknown';
    $flags = array_filter([$g['isp'] ?? '', !empty($g['proxy']) ? 'Apple Private Relay' : '', !empty($g['hosting']) ? 'hosting/VPN' : '']);
    $dev = implode(' · ', array_filter([$rec['device'], $rec['os'], $rec['browser']])) ?: 'unknown device';
    $when = (new DateTimeImmutable('now', new DateTimeZone('Europe/London')))->format('D j M H:i');
    $body = implode("\n", [
        "The Download button on " . PAGE_URL . " was clicked.",
        "",
        "When:     $when (UK time)",
        "Where:    $place" . ($flags ? ' (' . implode(', ', $flags) . ')' : '') . " - $ip",
        "Device:   $dev",
        "File:     $file",
        "",
        "One alert per address per 10 minutes; the daily 08:05 email lists every click and every completed download.",
    ]);
    $headers = "From: robertpowell.net <" . ALERT_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Auto-Response-Suppress: All";
    if (!@mail(ALERT_TO, "Polar video download clicked ($place, " . substr($when, -5) . ")", $body, $headers, '-f' . ALERT_FROM)) {
        error_log('polar download: alert mail failed');
    }
}

header('Location: media/' . rawurlencode($file) . '?dl=1', true, 302);
