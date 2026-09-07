<?php
declare(strict_types=1);
/**
 * Shared contact form back end for every site on this box.
 * Lives outside all web roots. Recipient addresses are only ever here.
 * Each site's contact.php calls Contact\boot('<site key>') first.
 */
namespace Contact;

const SITES = [
    'highlyconfidential.co.uk' => [
        'name' => 'highlyconfidential.co.uk', 'url' => 'https://highlyconfidential.co.uk',
        'recipient' => 'robert@highlyconfidential.co.uk', 'sender' => 'server@highlyconfidential.co.uk',
        'data' => '/var/www/highlyconfidential.co.uk/data',
        'logo' => 'https://highlyconfidential.co.uk/logo-mark-512.png', 'accent' => '#0f172a',
    ],
    'robertpowell.net' => [
        'name' => 'robertpowell.net', 'url' => 'https://robertpowell.net',
        'recipient' => 'mail@robertpowell.net', 'sender' => 'server@robertpowell.net',
        'data' => '/var/www/shared/contact/data/robertpowell.net', 'logo' => '', 'accent' => '#fa4a04',
    ],
    'rpowell.co.uk' => [
        'name' => 'rpowell.co.uk', 'url' => 'https://rpowell.co.uk',
        'recipient' => 'robert@rpowell.co.uk', 'sender' => 'server@rpowell.co.uk',
        'data' => '/var/www/shared/contact/data/rpowell.co.uk', 'logo' => '', 'accent' => '#fa4a04',
    ],
    'ultrasecret.net' => [
        'name' => 'ultrasecret.net', 'url' => 'https://ultrasecret.net',
        'recipient' => 'robert@ultrasecret.net', 'sender' => 'server@ultrasecret.net',
        'data' => '/var/www/shared/contact/data/ultrasecret.net', 'logo' => '', 'accent' => '#0f172a',
    ],
    'new.robertpowell.com' => [
        'name' => 'robertpowell.com', 'url' => 'https://new.robertpowell.com',
        'recipient' => 'mail@robertpowell.com', 'sender' => 'server@robertpowell.com',
        'data' => '/var/www/shared/contact/data/new.robertpowell.com', 'logo' => '', 'accent' => '#a3571a',
    ],
];

const LIMITS        = ['ip_hour' => 3, 'ip_day' => 6, 'all_day' => 40];
const ALLOW_IPS     = ['81.103.25.79'];   // owner: never rate limited (human checks still apply)
const TOKEN_MIN_AGE = 4;        // form must have been open at least this many seconds
const TOKEN_MAX_AGE = 3600;     // and no more than an hour
const SLIDE_MIN_MS  = 120;      // a real drag takes time
const SLIDE_MAX_MS  = 30000;
const SLIDE_MIN_MOVES = 6;      // and produces intermediate pointer events
const MAX_LEN = ['name' => 120, 'email' => 254, 'subject' => 160, 'message' => 5000];

function boot(string $key): array {
    if (!isset(SITES[$key])) { throw new \RuntimeException("unknown site $key"); }
    $GLOBALS['__contact_site'] = SITES[$key] + ['key' => $key];
    return $GLOBALS['__contact_site'];
}

function cfg(string $k): string {
    return (string)($GLOBALS['__contact_site'][$k] ?? '');
}

function data_dir(): string { return cfg('data'); }

function secret(): string {
    static $s = null;
    if ($s === null) {
        $s = trim((string)@file_get_contents(data_dir() . '/secret'));
        if ($s === '') { throw new \RuntimeException('contact form secret missing'); }
    }
    return $s;
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ---------- proof-of-open token (issued with the form, single use) ---------- */

function token_issue(): string {
    $ts = time();
    $nonce = bin2hex(random_bytes(12));
    $mac = hash_hmac('sha256', "$ts|$nonce", secret());
    return "$ts.$nonce.$mac";
}

/** @return string|null error text, null if fine */
function token_check(string $tok): ?string {
    $p = explode('.', $tok);
    if (count($p) !== 3 || !ctype_digit($p[0]) || !ctype_xdigit($p[1]) || !ctype_xdigit($p[2])) {
        return 'The form token was missing or malformed. Reload the page and try again.';
    }
    [$ts, $nonce, $mac] = $p;
    if (!hash_equals(hash_hmac('sha256', "$ts|$nonce", secret()), $mac)) {
        return 'The form token did not verify. Reload the page and try again.';
    }
    $age = time() - (int)$ts;
    if ($age < TOKEN_MIN_AGE) { return 'That was very quick. Take a moment, then slide to send.'; }
    if ($age > TOKEN_MAX_AGE) { return 'This form has been open for over an hour. Reload the page and try again.'; }
    if (file_exists(data_dir() . '/nonces/' . $nonce)) { return 'This form has already been sent. Reload the page to send another.'; }
    return null;
}

/** Spend the token. Only called once the message is actually going out. */
function token_burn(string $tok): void {
    $nonce = explode('.', $tok)[1] ?? '';
    if ($nonce !== '') { @touch(data_dir() . '/nonces/' . $nonce); }
    // prune old nonces now and then
    if (random_int(1, 20) === 1) {
        foreach (glob(data_dir() . '/nonces/*') ?: [] as $g) {
            if (filemtime($g) < time() - TOKEN_MAX_AGE - 60) { @unlink($g); }
        }
    }
}

/* ---------- human check: the slide must look like a slide ---------- */

function slide_check(array $post): ?string {
    $ms = (int)($post['slide_ms'] ?? 0);
    $moves = (int)($post['slide_moves'] ?? 0);
    if ($ms < SLIDE_MIN_MS || $ms > SLIDE_MAX_MS || $moves < SLIDE_MIN_MOVES) {
        return 'Please slide the lock all the way across to send.';
    }
    if (($post['company'] ?? '') !== '') {      // honeypot: humans never see this field
        return 'Message rejected.';
    }
    return null;
}

/* ---------- rate limiting: per IP and site-wide, file backed ---------- */

function ratelimit(string $ip, bool $record): ?string {
    if (in_array($ip, ALLOW_IPS, true)) { return null; }
    $f = data_dir() . '/ratelimit.json';
    $h = fopen($f, 'c+');
    if (!$h) { return null; }                    // never block on our own failure
    flock($h, LOCK_EX);
    $raw = stream_get_contents($h);
    $db = $raw ? (json_decode($raw, true) ?: []) : [];
    $now = time();
    $key = hash('sha256', $ip . secret());       // IPs are not stored in clear in this file
    $db['ips'] = $db['ips'] ?? [];
    $db['all'] = array_values(array_filter($db['all'] ?? [], fn($t) => $t > $now - 86400));
    foreach ($db['ips'] as $k => $ts) {
        $db['ips'][$k] = array_values(array_filter($ts, fn($t) => $t > $now - 86400));
        if (!$db['ips'][$k]) { unset($db['ips'][$k]); }
    }
    $mine = $db['ips'][$key] ?? [];
    $hour = count(array_filter($mine, fn($t) => $t > $now - 3600));
    $err = null;
    if ($hour >= LIMITS['ip_hour'])            { $err = 'You have sent several messages in the last hour. Please try again later.'; }
    elseif (count($mine) >= LIMITS['ip_day'])  { $err = 'You have reached the daily limit for messages. Please try again tomorrow.'; }
    elseif (count($db['all']) >= LIMITS['all_day']) { $err = 'The contact form is busy today. Please try again tomorrow.'; }
    if ($err === null && $record) {
        $db['ips'][$key][] = $now;
        $db['all'][] = $now;
    }
    ftruncate($h, 0); rewind($h);
    fwrite($h, json_encode($db));
    flock($h, LOCK_UN); fclose($h);
    return $err;
}

/* ---------- validation ---------- */

function clean(string $s, int $max): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/** @return array{0: array<string,string>, 1: array<string,string>} [errors, values] */
function validate(array $post): array {
    $v = [
        'name'    => clean((string)($post['name'] ?? ''), MAX_LEN['name']),
        'email'   => clean((string)($post['email'] ?? ''), MAX_LEN['email']),
        'subject' => clean((string)($post['subject'] ?? ''), MAX_LEN['subject']),
        'message' => clean((string)($post['message'] ?? ''), MAX_LEN['message']),
    ];
    $e = [];
    if (mb_strlen($v['name']) < 2)      { $e['name'] = 'Please tell me your name.'; }
    if (preg_match('/[\r\n]/', $v['name'] . $v['subject'] . $v['email'])) { $e['name'] = 'Line breaks are not allowed here.'; }
    if ($v['email'] === '' || !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $e['email'] = 'That does not look like a valid email address.';
    } else {
        $dom = substr(strrchr($v['email'], '@'), 1);
        if (!checkdnsrr($dom, 'MX') && !checkdnsrr($dom, 'A') && !checkdnsrr($dom, 'AAAA')) {
            $e['email'] = 'That email domain does not accept mail.';
        }
    }
    if (mb_strlen($v['subject']) < 2)   { $e['subject'] = 'Please give the message a subject.'; }
    if (mb_strlen($v['message']) < 10)  { $e['message'] = 'Please write a little more in the message.'; }
    return [$e, $v];
}

/* ---------- sender context: where and what they are using ---------- */

function geo(string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['note' => 'private or local address'];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $raw = @file_get_contents('http://ip-api.com/json/' . rawurlencode($ip)
        . '?fields=status,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org,as,mobile,proxy,hosting', false, $ctx);
    $g = $raw ? json_decode($raw, true) : null;
    return (is_array($g) && ($g['status'] ?? '') === 'success') ? $g : ['note' => 'lookup unavailable'];
}

function flag(string $cc): string {
    $cc = strtoupper($cc);
    if (!preg_match('/^[A-Z]{2}$/', $cc)) { return ''; }
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
}

function ua_parse(string $ua): array {
    $b = 'Unknown browser'; $o = 'Unknown OS'; $d = 'Desktop';
    if (preg_match('/iPhone|iPad|iPod/i', $ua))       { $o = 'iOS'; $d = preg_match('/iPad/i', $ua) ? 'Tablet' : 'Phone'; }
    elseif (preg_match('/Android/i', $ua))            { $o = 'Android'; $d = preg_match('/Mobile/i', $ua) ? 'Phone' : 'Tablet'; }
    elseif (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) { $o = 'Windows' . (($m[1] === '10.0') ? ' 10/11' : ''); }
    elseif (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) { $o = 'macOS ' . str_replace('_', '.', $m[1]); }
    elseif (preg_match('/CrOS/', $ua))                { $o = 'ChromeOS'; }
    elseif (preg_match('/Linux/', $ua))               { $o = 'Linux'; }
    if (preg_match('/Edg(?:e|A|iOS)?\/([\d.]+)/', $ua, $m))       { $b = 'Edge ' . $m[1]; }
    elseif (preg_match('/OPR\/([\d.]+)/', $ua, $m))                { $b = 'Opera ' . $m[1]; }
    elseif (preg_match('/Firefox\/([\d.]+)/', $ua, $m))            { $b = 'Firefox ' . $m[1]; }
    elseif (preg_match('/(?:Chrome|CriOS)\/([\d.]+)/', $ua, $m))   { $b = 'Chrome ' . $m[1]; }
    elseif (preg_match('/Version\/([\d.]+).*Safari/', $ua, $m))    { $b = 'Safari ' . $m[1]; }
    elseif (preg_match('/curl\/([\d.]+)/', $ua, $m))               { $b = 'curl ' . $m[1]; $d = 'Script'; }
    return ['browser' => $b, 'os' => $o, 'device' => $d];
}


/* ---------- slide telemetry: what the human check actually saw ---------- */

/** Parse the client's slide_path JSON into metrics. Never rejects; this is evidence, not a gate. */
function telemetry(array $post): array {
    $raw = (string)($post['slide_path'] ?? '');
    $t = ['present' => false, 'input' => 'unknown', 'ms' => (int)($post['slide_ms'] ?? 0), 'moves' => (int)($post['slide_moves'] ?? 0)];
    if ($raw === '' || strlen($raw) > 20000) { return $t; }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['path']) || !is_array($j['path'])) { return $t; }
    $pts = [];
    foreach (array_slice($j['path'], 0, 400) as $pt) {
        if (is_array($pt) && count($pt) === 2 && is_numeric($pt[0]) && is_numeric($pt[1])) {
            $pts[] = [(int)$pt[0], (int)$pt[1]];
        }
    }
    $t['present'] = count($pts) >= 2;
    $t['input']   = preg_replace('/[^a-z]/', '', strtolower((string)($j['input'] ?? ''))) ?: 'unknown';
    $t['travel']  = max(1, (int)($j['travel'] ?? 0));
    $t['width']   = (int)($j['w'] ?? 0);
    $t['dpr']     = round((float)($j['dpr'] ?? 1), 2);
    $t['touch_capable'] = !empty($j['touch']);
    $t['path']    = $pts;
    if (!$t['present']) { return $t; }
    // derived metrics
    $n = count($pts); $dur = max(1, $pts[$n - 1][0] - $pts[0][0]);
    $dist = 0; $rev = 0; $gaps = []; $lastdir = 0; $maxgap = 0;
    for ($i = 1; $i < $n; $i++) {
        $dx = $pts[$i][1] - $pts[$i - 1][1]; $dt = $pts[$i][0] - $pts[$i - 1][0];
        $dist += abs($dx); $gaps[] = $dt; $maxgap = max($maxgap, $dt);
        $dir = $dx <=> 0;
        if ($dir !== 0 && $lastdir !== 0 && $dir !== $lastdir) { $rev++; }
        if ($dir !== 0) { $lastdir = $dir; }
    }
    $mean = array_sum($gaps) / max(1, count($gaps));
    $var = 0; foreach ($gaps as $g) { $var += ($g - $mean) ** 2; }
    $sd = sqrt($var / max(1, count($gaps)));
    $net = $pts[$n - 1][1] - $pts[0][1];
    // per-slice speed profile (24 slices) and position profile for the strips
    $slices = 24; $speed = array_fill(0, $slices, 0); $posn = array_fill(0, $slices, 0);
    for ($i = 1; $i < $n; $i++) {
        $k = min($slices - 1, (int)(($pts[$i][0] - $pts[0][0]) / $dur * $slices));
        $speed[$k] += abs($pts[$i][1] - $pts[$i - 1][1]);
        $posn[$k] = max($posn[$k], $pts[$i][1]);
    }
    for ($k = 1; $k < $slices; $k++) { if ($posn[$k] === 0) { $posn[$k] = $posn[$k - 1]; } }
    $t += [
        'samples' => $n, 'duration_ms' => $dur, 'distance_px' => $dist, 'net_px' => $net,
        'reversals' => $rev, 'interval_mean_ms' => round($mean, 1), 'interval_sd_ms' => round($sd, 1),
        'longest_pause_ms' => $maxgap, 'straightness' => $dist > 0 ? round($net / $dist, 3) : 0,
        'speed_profile' => $speed, 'position_profile' => $posn,
        'peak_speed' => max(1, max($speed)),
    ];
    // a plain-language reading, deliberately hedged: evidence, not a verdict
    $notes = [];
    if ($sd < 1.0 && $n > 10)            { $notes[] = 'perfectly regular timing (scripted?)'; }
    if ($rev === 0 && $n > 20)           { $notes[] = 'no backward wobble at all'; }
    if ($dur < 250)                      { $notes[] = 'very fast'; }
    if ($t['input'] === 'keyboard')      { $notes[] = 'keyboard hold, not a drag'; }
    if ($maxgap > 2000)                  { $notes[] = 'long pause mid-drag'; }
    if (!$notes)                         { $notes[] = 'timing and wobble look like a hand'; }
    $t['reading'] = implode('; ', $notes);
    return $t;
}

function telemetry_text(array $t): string {
    if (!$t['present']) { return "Slide telemetry: none captured (input {$t['input']}, {$t['ms']} ms, {$t['moves']} moves)\n"; }
    $o = "Slide telemetry\n"
       . "Input:        {$t['input']}" . ($t['touch_capable'] ? ' (touch-capable device)' : '') . "\n"
       . "Duration:     {$t['duration_ms']} ms over {$t['samples']} samples, {$t['moves']} pointer events\n"
       . "Distance:     {$t['distance_px']} px moved, {$t['net_px']} px net of {$t['travel']} px track (straightness {$t['straightness']})\n"
       . "Reversals:    {$t['reversals']}\n"
       . "Intervals:    mean {$t['interval_mean_ms']} ms, sd {$t['interval_sd_ms']} ms, longest pause {$t['longest_pause_ms']} ms\n"
       . "Reading:      {$t['reading']}\n"
       . "Path:         " . json_encode(array_slice($t['path'], 0, 60)) . (count($t['path']) > 60 ? ' …' : '') . "\n";
    return $o;
}

function telemetry_html(array $t, callable $row): string {
    if (!$t['present']) {
        return $row('Slide', 'No path captured <span style="color:#64748b">(input ' . h($t['input']) . ', ' . (int)$t['ms'] . ' ms, ' . (int)$t['moves'] . ' moves)</span>');
    }
    // Email clients drop empty <div>s and opacity, so each cell is a coloured <td> with a
    // pre-blended shade (accent mixed towards a pale base) and a non-breaking space inside.
    $accHex = ltrim(cfg('accent'), '#');
    $rgb = strlen($accHex) === 6 ? [hexdec(substr($accHex, 0, 2)), hexdec(substr($accHex, 2, 2)), hexdec(substr($accHex, 4, 2))] : [15, 23, 42];
    $shade = function (float $f) use ($rgb): string {
        $base = [241, 245, 249];   // #f1f5f9
        $f = max(0.08, min(1, $f));
        return sprintf('#%02x%02x%02x', (int)round($base[0] + ($rgb[0] - $base[0]) * $f), (int)round($base[1] + ($rgb[1] - $base[1]) * $f), (int)round($base[2] + ($rgb[2] - $base[2]) * $f));
    };
    $strip = function (array $vals, int $peak, string $label) use ($shade): string {
        $cells = '';
        foreach ($vals as $v) {
            $cells .= '<td width="' . number_format(100 / count($vals), 2) . '%" height="18" bgcolor="' . $shade($v / max(1, $peak)) . '" style="height:18px;background:' . $shade($v / max(1, $peak)) . ';font-size:1px;line-height:18px">&nbsp;</td>';
        }
        return '<div style="font:600 9px/1.6 ui-monospace,Menlo,monospace;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin:8px 0 2px">' . $label . '</div>'
             . '<table width="100%" cellpadding="0" cellspacing="2" border="0" style="table-layout:fixed;width:100%;border-collapse:separate;border-spacing:2px 0"><tr>' . $cells . '</tr></table>';
    };
    $stat = fn(string $k, $v) => '<td style="padding:6px 10px 6px 0;vertical-align:top;white-space:nowrap"><div style="font:600 9px/1.4 ui-monospace,Menlo,monospace;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8">' . $k . '</div><div style="font:600 13px ui-monospace,Menlo,monospace;color:#0f172a">' . (string)$v . '</div></td>';
    $body = '<table cellpadding="0" cellspacing="0"><tr>'
        . $stat('Input', h($t['input']) . ($t['touch_capable'] ? ' <span style="color:#94a3b8;font-weight:400">touch device</span>' : ''))
        . $stat('Duration', (int)$t['duration_ms'] . ' ms')
        . $stat('Events', (int)$t['moves'] . ' <span style="color:#94a3b8;font-weight:400">/ ' . (int)$t['samples'] . ' samples</span>')
        . $stat('Reversals', (int)$t['reversals'])
        . '</tr><tr>'
        . $stat('Distance', (int)$t['distance_px'] . ' px <span style="color:#94a3b8;font-weight:400">of ' . (int)$t['travel'] . '</span>')
        . $stat('Straightness', h((string)$t['straightness']))
        . $stat('Interval', h((string)$t['interval_mean_ms']) . ' ms <span style="color:#94a3b8;font-weight:400">sd ' . h((string)$t['interval_sd_ms']) . '</span>')
        . $stat('Longest pause', (int)$t['longest_pause_ms'] . ' ms')
        . '</tr></table>'
        . $strip($t['speed_profile'], (int)$t['peak_speed'], 'Speed over time')
        . $strip($t['position_profile'], (int)$t['travel'], 'Position over time')
        . '<div style="font:12px -apple-system,Segoe UI,Arial,sans-serif;color:#334155;margin-top:8px"><b>Reading:</b> ' . h($t['reading']) . '</div>';
    return $row('Slide', $body);
}

/* ---------- the email ---------- */

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function build_email(array $v, array $ctx): array {
    $g = $ctx['geo']; $u = $ctx['ua'];
    $place = implode(', ', array_filter([$g['city'] ?? '', $g['regionName'] ?? '', $g['country'] ?? '']));
    $place = $place !== '' ? $place : ($g['note'] ?? 'unknown');
    $fl = flag($g['countryCode'] ?? '');
    $map = (isset($g['lat'], $g['lon']))
        ? sprintf('https://www.openstreetmap.org/?mlat=%.4f&mlon=%.4f#map=11/%.4f/%.4f', $g['lat'], $g['lon'], $g['lat'], $g['lon'])
        : '';
    $netflags = implode(' ', array_filter([
        !empty($g['hosting']) ? 'hosting' : '', !empty($g['proxy']) ? 'proxy/VPN' : '', !empty($g['mobile']) ? 'mobile' : '']));
    $when = $ctx['when']->format('D j M Y, H:i:s T');

    $subject = '[' . cfg('name') . ' contact] ' . $v['subject'];

    $text = "New message via " . cfg('name') . "\n\n"
        . "From:    {$v['name']} <{$v['email']}>\n"
        . "Subject: {$v['subject']}\n"
        . "Sent:    $when\n\n"
        . "----------------------------------------\n{$v['message']}\n----------------------------------------\n\n"
        . "Sender details\n"
        . "IP address:  {$ctx['ip']}\n"
        . "Location:    $place" . ($fl ? " $fl" : '') . "\n"
        . (isset($g['zip']) && $g['zip'] !== '' ? "Postcode:    {$g['zip']}\n" : '')
        . ($map ? "Map:         $map\n" : '')
        . "Network:     " . ($g['isp'] ?? '?') . (isset($g['as']) ? " ({$g['as']})" : '') . ($netflags ? " [$netflags]" : '') . "\n"
        . "Timezone:    " . ($g['timezone'] ?? '?') . "\n"
        . "Browser:     {$u['browser']} on {$u['os']} ({$u['device']})\n"
        . "Language:    {$ctx['lang']}\n"
        . "User agent:  {$ctx['ua_raw']}\n"
        . "Referrer:    {$ctx['ref']}\n\n"
        . telemetry_text($ctx['tele']);

    $row = fn(string $k, string $val, bool $mono = false) =>
        '<tr><td style="padding:7px 12px;border-bottom:1px solid #e2e8f0;font:600 11px/1.4 ui-monospace,Menlo,monospace;'
        . 'letter-spacing:.06em;text-transform:uppercase;color:#64748b;white-space:nowrap;vertical-align:top;width:110px">' . h($k) . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e2e8f0;font:' . ($mono ? '12px ui-monospace,Menlo,monospace' : '14px -apple-system,Segoe UI,Arial,sans-serif')
        . ';color:#0f172a;vertical-align:top;word-break:break-word">' . $val . '</td></tr>';
    $chip = fn(string $t, string $bg) => '<span style="display:inline-block;font:600 10px/1.6 ui-monospace,Menlo,monospace;color:#fff;background:'
        . $bg . ';padding:1px 7px;border-radius:3px;margin-left:6px;letter-spacing:.04em">' . h($t) . '</span>';
    $chips = (!empty($g['hosting']) ? $chip('hosting', '#b3541f') : '') . (!empty($g['proxy']) ? $chip('proxy / vpn', '#7c3aed') : '')
        . (!empty($g['mobile']) ? $chip('mobile', '#26304d') : '');

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:28px 12px"><tr><td align="center">'
        . '<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%">'
        // header
        . '<tr><td style="padding:0 0 18px"><table cellpadding="0" cellspacing="0"><tr>'
        . '<td style="padding-right:12px">' . (cfg('logo') !== ''
            ? '<img src="' . h(cfg('logo')) . '" width="40" height="40" alt="" style="display:block;border-radius:10px">'
            : '<div style="width:40px;height:40px;border-radius:10px;background:' . h(cfg('accent')) . ';color:#fff;font:700 20px/40px -apple-system,Segoe UI,Arial,sans-serif;text-align:center">' . h(strtoupper(substr(cfg('name'), 0, 1))) . '</div>')
        . '</td>'
        . '<td><div style="font:700 19px/1.2 -apple-system,Segoe UI,Arial,sans-serif;color:#0f172a;letter-spacing:-.02em">New message</div>'
        . '<div style="font:13px -apple-system,Segoe UI,Arial,sans-serif;color:#64748b;margin-top:2px">via ' . h(cfg('name')) . ' &middot; ' . h($when) . '</div></td>'
        . '</tr></table></td></tr>'
        // message card
        . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-left:4px solid ' . h(cfg('accent')) . ';border-radius:8px">'
        . '<tr><td style="padding:18px 20px 6px">'
        . '<div style="font:600 11px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;text-transform:uppercase;color:#64748b">From</div>'
        . '<div style="font:600 16px/1.4 -apple-system,Segoe UI,Arial,sans-serif;color:#0f172a;margin-top:4px">' . h($v['name'])
        . ' <a href="mailto:' . h($v['email']) . '" style="font-weight:400;color:#334155">&lt;' . h($v['email']) . '&gt;</a></div>'
        . '<div style="font:600 11px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;text-transform:uppercase;color:#64748b;margin-top:14px">Subject</div>'
        . '<div style="font:15px/1.4 -apple-system,Segoe UI,Arial,sans-serif;color:#0f172a;margin-top:4px">' . h($v['subject']) . '</div>'
        . '</td></tr><tr><td style="padding:14px 20px 20px">'
        . '<div style="font:15px/1.65 -apple-system,Segoe UI,Arial,sans-serif;color:#0f172a;white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px 16px">'
        . h($v['message']) . '</div></td></tr></table></td></tr>'
        // sender details
        . '<tr><td style="padding-top:22px"><div style="font:600 11px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;text-transform:uppercase;color:#64748b;padding-bottom:8px">Sender details</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;border-collapse:separate;overflow:hidden">'
        . $row('IP address', '<span style="font:600 13px ui-monospace,Menlo,monospace">' . h($ctx['ip']) . '</span>' . $chips)
        . $row('Location', h($place) . ($fl ? ' <span style="font-size:18px;vertical-align:-2px">' . $fl . '</span>' : '')
            . (isset($g['zip']) && $g['zip'] !== '' ? '<div style="color:#64748b;font-size:12px;margin-top:2px">Postcode area ' . h($g['zip']) . '</div>' : '')
            . ($map ? '<div style="margin-top:6px"><a href="' . h($map) . '" style="display:inline-block;font:600 12px -apple-system,Segoe UI,Arial,sans-serif;color:#fff;background:' . h(cfg('accent')) . ';padding:5px 11px;border-radius:5px;text-decoration:none">Open map &rarr;</a>'
                . ' <span style="font:11px ui-monospace,Menlo,monospace;color:#94a3b8;margin-left:6px">' . h(sprintf('%.4f, %.4f', $g['lat'], $g['lon'])) . '</span></div>' : ''))
        . $row('Network', h($g['isp'] ?? '?') . (isset($g['org']) && $g['org'] !== '' && $g['org'] !== ($g['isp'] ?? '') ? ' &middot; ' . h($g['org']) : '')
            . (isset($g['as']) ? '<div style="color:#64748b;font-size:12px;margin-top:2px">' . h($g['as']) . '</div>' : ''))
        . $row('Timezone', h($g['timezone'] ?? '?'))
        . $row('Browser', h($u['browser']) . ' on ' . h($u['os']) . ' <span style="color:#64748b">&middot; ' . h($u['device']) . '</span>')
        . $row('Language', h($ctx['lang']))
        . $row('User agent', h($ctx['ua_raw']), true)
        . $row('Referrer', h($ctx['ref']), true)
        . '</table></td></tr>'
        // slide telemetry: what the human check saw
        . '<tr><td style="padding-top:22px"><div style="font:600 11px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;text-transform:uppercase;color:#64748b;padding-bottom:8px">Slide telemetry</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;border-collapse:separate;overflow:hidden">'
        . telemetry_html($ctx['tele'], $row)
        . '</table></td></tr>'
        // footer
        . '<tr><td style="padding-top:22px"><div style="font:11px/1.6 ui-monospace,Menlo,monospace;color:#94a3b8">Sent by the contact form at '
        . h(cfg('url')) . '. Reply to this email to answer ' . h($v['name']) . ' directly. Location is a geo-IP estimate.</div></td></tr>'
        . '</table></td></tr></table></body></html>';

    $boundary = 'hc_' . bin2hex(random_bytes(12));
    $headers = implode("\r\n", [
        'From: ' . mb_encode_mimeheader(cfg('name'), 'UTF-8') . ' <' . cfg('sender') . '>',
        'Reply-To: ' . mb_encode_mimeheader($v['name'], 'UTF-8') . ' <' . $v['email'] . '>',
        'Date: ' . $ctx['when']->format(DATE_RFC2822),
        'MIME-Version: 1.0',
        'X-Contact-Form-IP: ' . $ctx['ip'],
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($text) . "\r\n"
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($html) . "\r\n--$boundary--\r\n";
    return [mb_encode_mimeheader($subject, 'UTF-8'), $headers, $body];
}

function context(): array {
    return [
        'ip'     => client_ip(),
        'when'   => new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')),
        'ua_raw' => clean((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 400),
        'lang'   => clean((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 120) ?: '—',
        'ref'    => clean((string)($_SERVER['HTTP_REFERER'] ?? ''), 300) ?: '—',
    ];
}

function store(array $v, array $ctx, bool $mailed): void {
    $rec = ['ts' => $ctx['when']->format('c'), 'ip' => $ctx['ip'], 'name' => $v['name'], 'email' => $v['email'],
            'subject' => $v['subject'], 'message' => $v['message'], 'ua' => $ctx['ua_raw'],
            'geo' => array_intersect_key($ctx['geo'], array_flip(['city', 'regionName', 'country', 'countryCode', 'zip', 'lat', 'lon', 'isp', 'as'])),
            'slide' => array_diff_key($ctx['tele'] ?? [], array_flip(['speed_profile', 'position_profile', 'peak_speed'])),
            'mailed' => $mailed];
    @file_put_contents(data_dir() . '/submissions.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/** Full pipeline for a POST. Returns [httpStatus, payload]. */
function handle(array $post): array {
    $ip = client_ip();
    if ($e = slide_check($post))            { return [403, ['ok' => false, 'error' => $e]]; }
    if ($e = token_check((string)($post['token'] ?? ''))) { return [403, ['ok' => false, 'error' => $e]]; }
    if ($e = ratelimit($ip, false))         { return [429, ['ok' => false, 'error' => $e]]; }
    [$errors, $v] = validate($post);
    if ($errors)                            { return [422, ['ok' => false, 'fields' => $errors]]; }
    if ($e = ratelimit($ip, true))          { return [429, ['ok' => false, 'error' => $e]]; }
    token_burn((string)$post['token']);

    $ctx = context();
    $ctx['geo'] = geo($ip);
    $ctx['ua']  = ua_parse($ctx['ua_raw']);
    $ctx['tele'] = telemetry($post);
    [$subject, $headers, $body] = build_email($v, $ctx);
    $ok = mail(cfg('recipient'), $subject, $body, $headers, '-f' . cfg('sender'));
    store($v, $ctx, $ok);
    if (!$ok) { return [500, ['ok' => false, 'error' => 'The message could not be handed to the mail system. Please try again later.']]; }
    return [200, ['ok' => true, 'message' => 'Thank you, ' . $v['name'] . '. Your message has been sent.']];
}
