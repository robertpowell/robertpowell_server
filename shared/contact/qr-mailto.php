<?php
declare(strict_types=1);
/* One-visit mailto QR for every site's contact page (shared; call after contact_entry() so Contact\cfg
   is booted). The page HTML carries NO address: hc_qr_card() emits an empty card, and contact.js then
   POSTs qr=1&token=<form token>&slide_ms/slide_moves/slide_path (after a slide-to-reveal drag) to /contact.php, which hc_qr_serve_if_requested() answers with JSON
   {address, svg}. So a scraper that only reads HTML gets nothing and burns nothing; each address is
   tied to one page token (a repeat fetch with the same token returns the same address).
   The first reveal of an address emails the owner a notification with the visitor's sender details and slide
   telemetry (hc_qr_notify, capped 5/IP/hour), because a mail written in the visitor's own client carries none.
   Minting: word-word-word@<site mail domain> (Fastmail catch-all delivers it on all five domains -
   RCPT-verified 2026-09-05), never issued twice (<data>/qr-issued.txt), logged to <data>/qr-addresses.jsonl.
   On failure the site's recipient is emailed (throttled 1/h). Domain = part after @ in the site's sender. */
require_once '/var/www/shared/qr/vendor/autoload.php';

use chillerlan\QRCode\{QRCode, QROptions};

function hc_qr_domain(): string {
    return function_exists('Contact\\cfg') ? substr(Contact\cfg('sender'), strpos(Contact\cfg('sender'), '@') + 1) : 'highlyconfidential.co.uk';
}
const HC_QR_WORDS = [
  'ant','ape','asp','bat','bear','bee','bison','boar','bull','calf','camel','carp','cat','clam','cobra','cod','colt','cow',
  'crab','crane','crow','cub','deer','dingo','doe','dog','dove','drake','duck','eagle','eel','egret','elk','emu','ewe','fawn',
  'finch','fly','foal','fox','frog','gecko','gnat','gnu','goat','goose','gull','hare','hawk','hen','heron','hog','horse',
  'hound','ibex','ibis','jay','kid','kite','kiwi','koala','krill','lamb','lark','lemur','lion','llama','loon','lynx','mare',
  'marmot','mink','mole','moose','moth','mouse','mule','newt','okapi','orca','otter','owl','ox','panda','pig','pike','pony',
  'puffin','pug','puma','quail','rabbit','ram','rat','raven','rhino','robin','rook','seal','shark','sheep','shrew','skunk',
  'sloth','slug','snail','snake','sole','sow','squid','stag','stoat','stork','swan','swift','tapir','tern','tiger','toad',
  'trout','tuna','vole','wasp','whale','wolf','wombat','worm','wren','yak','zebra',
];

/** dog-cat-wolf@highlyconfidential.co.uk — three distinct words, CSPRNG-picked, never issued before.
    Every address ever handed out is written to data/qr-issued.txt (one per line, flock-protected), and a
    candidate that appears there is thrown away. So an address that has been used to send mail can never
    come round again — we don't need to know whether it was used, only that it was issued. 136 words give
    2,460,240 ordered triples; at ~30 page loads a day that is centuries of supply. */
function hc_qr_issued_file(): string {
    $dir = function_exists('Contact\data_dir') ? Contact\data_dir() : '/var/www/highlyconfidential.co.uk/data';
    return $dir . '/qr-issued.txt';
}

function hc_qr_address(): string {
    $words = HC_QR_WORDS;
    $file  = hc_qr_issued_file();
    $fh    = fopen($file, 'c+');
    if ($fh === false) { throw new RuntimeException('cannot open ' . $file); }
    flock($fh, LOCK_EX);
    $issued = [];
    while (($line = fgets($fh)) !== false) { $issued[trim($line)] = true; }
    for ($try = 0; $try < 1000; $try++) {
        $pick = [];
        while (count($pick) < 3) {
            $w = $words[random_int(0, count($words) - 1)];
            if (!in_array($w, $pick, true)) { $pick[] = $w; }
        }
        $address = implode('-', $pick) . '@' . hc_qr_domain();
        if (!isset($issued[$address])) {
            fseek($fh, 0, SEEK_END);
            fwrite($fh, $address . "\n");
            fflush($fh);
            flock($fh, LOCK_UN); fclose($fh);
            return $address;
        }
    }
    flock($fh, LOCK_UN); fclose($fh);
    throw new RuntimeException('address space exhausted');
}

/** Inline SVG (no XML header, dark modules only, fill left to CSS) for a mailto: URL. */
function hc_qr_svg(string $mailto): string {
    $opts = new QROptions([
        'outputType'            => QRCode::OUTPUT_MARKUP_SVG,
        'eccLevel'              => QRCode::ECC_M,
        'quietzoneSize'         => 2,
        'drawLightModules'      => false,
        'svgUseFillAttributes'  => false,
        'svgAddXmlHeader'       => false,
        'outputBase64'          => false,
    ]);
    $svg = (new QRCode($opts))->render($mailto);
    // give it a title for screen readers / hover
    return preg_replace('/<svg /', '<svg role="img" aria-label="QR code that opens a new email to ' . htmlspecialchars($mailto, ENT_QUOTES) . '" ', $svg, 1);
}

/** Append one line per minted address to <data>/qr-addresses.jsonl: who revealed it and how they slid. */
function hc_qr_log(string $address, array $ctx = [], bool $notified = false): void {
    $rec = [
        'ts'       => gmdate('c'),
        'address'  => $address,
        'ip'       => $ctx['ip'] ?? (function_exists('Contact\client_ip') ? Contact\client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '')),
        'ua'       => mb_substr((string)($ctx['ua_raw'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300),
        'lang'     => $ctx['lang'] ?? '',
        'ref'      => $ctx['ref'] ?? '',
        'geo'      => array_intersect_key($ctx['geo'] ?? [], array_flip(['city', 'regionName', 'country', 'countryCode', 'zip', 'lat', 'lon', 'isp', 'as', 'mobile', 'proxy', 'hosting'])),
        'slide'    => array_diff_key($ctx['tele'] ?? [], array_flip(['speed_profile', 'position_profile', 'peak_speed'])),
        'notified' => $notified,
    ];
    $dir = function_exists('Contact\data_dir') ? Contact\data_dir() : '/var/www/highlyconfidential.co.uk/data';
    @file_put_contents($dir . '/qr-addresses.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/** At most 5 reveal notifications per IP per hour; beyond that the address is still minted and logged, just not mailed. */
function hc_qr_should_notify(string $ip): bool {
    $dir = function_exists('Contact\data_dir') ? Contact\data_dir() : '/var/www/highlyconfidential.co.uk/data';
    $lines = @file($dir . '/qr-addresses.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $n = 0; $since = time() - 3600;
    foreach (array_slice($lines, -200) as $l) {
        $r = json_decode($l, true);
        if (is_array($r) && ($r['ip'] ?? '') === $ip && !empty($r['notified']) && strtotime((string)($r['ts'] ?? '')) >= $since) { $n++; }
    }
    return $n < 5;
}

/** Email the site owner that an address has been revealed, with the same sender-details and slide-telemetry
    panels as a form message. A message later arriving at the address (from the visitor's own mail client)
    carries none of this, so this notification is the only record of who asked for it. */
function hc_qr_notify(string $address, array $ctx): bool {
    $g = $ctx['geo']; $u = $ctx['ua']; $h = 'Contact\h';
    $place = implode(', ', array_filter([$g['city'] ?? '', $g['regionName'] ?? '', $g['country'] ?? '']));
    $place = $place !== '' ? $place : ($g['note'] ?? 'unknown');
    $fl = Contact\flag($g['countryCode'] ?? '');
    $map = isset($g['lat'], $g['lon']) ? sprintf('https://www.openstreetmap.org/?mlat=%.4f&mlon=%.4f#map=11/%.4f/%.4f', $g['lat'], $g['lon'], $g['lat'], $g['lon']) : '';
    $netflags = implode(' ', array_filter([!empty($g['hosting']) ? 'hosting' : '', !empty($g['proxy']) ? 'proxy/VPN' : '', !empty($g['mobile']) ? 'mobile' : '']));
    $when = $ctx['when']->format('D j M Y, H:i:s T');
    $site = Contact\cfg('name'); $accent = Contact\cfg('accent');

    $subject = '[' . $site . ' QR] ' . $address;
    $text = "Address revealed on " . $site . "\n\n"
        . "Address: $address\n"
        . "When:    $when\n\n"
        . "A visitor slid to reveal this one-visit address. Any message that arrives at it was written in their own\n"
        . "mail client, so it will carry none of the details below. Match it to this notification by the address.\n\n"
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
        . Contact\telemetry_text($ctx['tele']);

    $row = fn(string $k, string $val, bool $mono = false) =>
        '<tr><td style="padding:7px 12px;border-bottom:1px solid #e2e8f0;font:600 11px/1.4 ui-monospace,Menlo,monospace;'
        . 'letter-spacing:.06em;text-transform:uppercase;color:#64748b;white-space:nowrap;vertical-align:top;width:110px">' . $h($k) . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e2e8f0;font:' . ($mono ? '12px ui-monospace,Menlo,monospace' : '14px -apple-system,Segoe UI,Arial,sans-serif')
        . ';color:#0f172a;vertical-align:top;word-break:break-word">' . $val . '</td></tr>';
    $chip = fn(string $t, string $bg) => '<span style="display:inline-block;font:600 10px/1.6 ui-monospace,Menlo,monospace;color:#fff;background:'
        . $bg . ';padding:1px 7px;border-radius:3px;margin-left:6px;letter-spacing:.04em">' . $h($t) . '</span>';
    $chips = (!empty($g['hosting']) ? $chip('hosting', '#b3541f') : '') . (!empty($g['proxy']) ? $chip('proxy / vpn', '#7c3aed') : '') . (!empty($g['mobile']) ? $chip('mobile', '#26304d') : '');
    $panel = fn(string $title, string $rows) => '<tr><td style="padding-top:22px"><div style="font:600 11px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;text-transform:uppercase;color:#64748b;padding-bottom:8px">' . $title . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;border-collapse:separate;overflow:hidden">' . $rows . '</table></td></tr>';

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:28px 12px"><tr><td align="center">'
        . '<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%">'
        . '<tr><td style="padding:0 0 18px"><table cellpadding="0" cellspacing="0"><tr>'
        . '<td style="padding-right:12px">' . (Contact\cfg('logo') !== ''
            ? '<img src="' . $h(Contact\cfg('logo')) . '" width="40" height="40" alt="" style="display:block;border-radius:10px">'
            : '<div style="width:40px;height:40px;border-radius:10px;background:' . $h($accent) . ';color:#fff;font:700 20px/40px -apple-system,Segoe UI,Arial,sans-serif;text-align:center">' . $h(strtoupper(substr($site, 0, 1))) . '</div>') . '</td>'
        . '<td><div style="font:700 19px/1.2 -apple-system,Segoe UI,Arial,sans-serif;color:#0f172a;letter-spacing:-.02em">Address revealed</div>'
        . '<div style="font:13px -apple-system,Segoe UI,Arial,sans-serif;color:#64748b;margin-top:2px">on ' . $h($site) . ' &middot; ' . $h($when) . '</div></td>'
        . '</tr></table></td></tr>'
        // the address card
        . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-left:4px solid ' . $h($accent) . ';border-radius:8px">'
        . '<tr><td style="padding:18px 20px 6px"><div style="font:600 11px/1 ui-monospace,Menlo,monospace;letter-spacing:.09em;text-transform:uppercase;color:#64748b">One-visit address</div>'
        . '<div style="font:600 18px/1.4 ui-monospace,Menlo,monospace;color:#0f172a;margin-top:6px;word-break:break-all">' . $h($address) . '</div></td></tr>'
        . '<tr><td style="padding:6px 20px 18px"><div style="font:14px/1.6 -apple-system,Segoe UI,Arial,sans-serif;color:#334155">A visitor slid to reveal this address. Any message that arrives at it was written in their own mail client and carries none of the details below. Match it to this notification by the address.</div></td></tr>'
        . '</table></td></tr>'
        . $panel('Sender details',
            $row('IP address', '<span style="font:600 13px ui-monospace,Menlo,monospace">' . $h($ctx['ip']) . '</span>' . $chips)
            . $row('Location', $h($place) . ($fl ? ' <span style="font-size:18px;vertical-align:-2px">' . $fl . '</span>' : '')
                . (isset($g['zip']) && $g['zip'] !== '' ? '<div style="color:#64748b;font-size:12px;margin-top:2px">Postcode area ' . $h($g['zip']) . '</div>' : '')
                . ($map ? '<div style="margin-top:6px"><a href="' . $h($map) . '" style="display:inline-block;font:600 12px -apple-system,Segoe UI,Arial,sans-serif;color:#fff;background:' . $h($accent) . ';padding:5px 11px;border-radius:5px;text-decoration:none">Open map &rarr;</a>'
                    . ' <span style="font:11px ui-monospace,Menlo,monospace;color:#94a3b8;margin-left:6px">' . $h(sprintf('%.4f, %.4f', $g['lat'], $g['lon'])) . '</span></div>' : ''))
            . $row('Network', $h($g['isp'] ?? '?') . (isset($g['org']) && $g['org'] !== '' && $g['org'] !== ($g['isp'] ?? '') ? ' &middot; ' . $h($g['org']) : '')
                . (isset($g['as']) ? '<div style="color:#64748b;font-size:12px;margin-top:2px">' . $h($g['as']) . '</div>' : ''))
            . $row('Timezone', $h($g['timezone'] ?? '?'))
            . $row('Browser', $h($u['browser']) . ' on ' . $h($u['os']) . ' <span style="color:#64748b">&middot; ' . $h($u['device']) . '</span>')
            . $row('Language', $h($ctx['lang']))
            . $row('User agent', $h($ctx['ua_raw']), true)
            . $row('Referrer', $h($ctx['ref']), true))
        . $panel('Slide telemetry', Contact\telemetry_html($ctx['tele'], $row))
        . '<tr><td style="padding-top:22px"><div style="font:11px/1.6 ui-monospace,Menlo,monospace;color:#94a3b8">Sent by the QR reveal on ' . $h(Contact\cfg('url')) . '/contact.php. Location is a geo-IP estimate. The address is never issued again.</div></td></tr>'
        . '</table></td></tr></table></body></html>';

    $boundary = 'qr_' . bin2hex(random_bytes(12));
    $headers = implode("\r\n", [
        'From: ' . mb_encode_mimeheader($site, 'UTF-8') . ' <' . Contact\cfg('sender') . '>',
        'Date: ' . $ctx['when']->format(DATE_RFC2822),
        'MIME-Version: 1.0',
        'X-Contact-Form-IP: ' . $ctx['ip'],
        'X-QR-Address: ' . $address,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($text) . "\r\n"
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($html) . "\r\n--$boundary--\r\n";
    return (bool)@mail(Contact\cfg('recipient'), mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers, '-f' . Contact\cfg('sender'));
}

/** Verify a page token's signature and age (NOT its 4-second minimum and NOT single-use: the QR fetch
    happens the instant the page loads and must not spend the form's token). Returns the nonce or null. */
function hc_qr_token_nonce(string $tok): ?string {
    $p = explode('.', $tok);
    if (count($p) !== 3 || !ctype_digit($p[0]) || !ctype_xdigit($p[1]) || !ctype_xdigit($p[2])) { return null; }
    [$ts, $nonce, $mac] = $p;
    if (!hash_equals(hash_hmac('sha256', "$ts|$nonce", Contact\secret()), $mac)) { return null; }
    $age = time() - (int)$ts;
    if ($age < -60 || $age > Contact\TOKEN_MAX_AGE) { return null; }
    return $nonce;
}

/** One address per page token: the first call mints and remembers it, later calls return the same one. */
function hc_qr_address_for(string $nonce, array $req): string {
    $dir = (function_exists('Contact\data_dir') ? Contact\data_dir() : '/var/www/highlyconfidential.co.uk/data') . '/qr-tokens';
    if (!is_dir($dir)) { @mkdir($dir, 0700); }
    $f = $dir . '/' . $nonce;
    $known = @file_get_contents($f);
    if (is_string($known) && $known !== '') { return trim($known); }
    $address = hc_qr_address();
    @file_put_contents($f, $address, LOCK_EX);
    // first reveal of this address: capture who revealed it, log it, and tell the owner
    $ctx = Contact\context();
    $ctx['geo']  = Contact\geo($ctx['ip']);
    $ctx['ua']   = Contact\ua_parse($ctx['ua_raw']);
    $ctx['tele'] = Contact\telemetry($req);
    $notified = hc_qr_should_notify($ctx['ip']) ? hc_qr_notify($address, $ctx) : false;
    hc_qr_log($address, $ctx, $notified);
    if (random_int(1, 20) === 1) {                       // prune spent entries now and then
        foreach (glob($dir . '/*') ?: [] as $g) { if (@filemtime($g) < time() - Contact\TOKEN_MAX_AGE - 60) { @unlink($g); } }
    }
    return $address;
}

/** Call right after contact_entry(). On a GET or POST carrying qr=1&token=...&slide_* it answers JSON and exits; otherwise no-op. */
function hc_qr_serve_if_requested(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $req = $method === 'POST' ? $_POST : ($method === 'GET' ? $_GET : []);
    if (!isset($req['qr'])) { return; }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $nonce = hc_qr_token_nonce((string)($req['token'] ?? ''));
    if ($nonce === null) { http_response_code(403); echo '{"ok":false}'; exit; }
    // the reveal must look like a slide, same rules as the form (duration window + pointer moves)
    if (Contact\slide_check($req) !== null) { http_response_code(403); echo '{"ok":false,"error":"slide"}'; exit; }
    try {
        $address = hc_qr_address_for($nonce, $req);
    } catch (Throwable $err) {
        hc_qr_alert($err);
        http_response_code(503); echo '{"ok":false}'; exit;
    }
    echo json_encode(['ok' => true, 'address' => $address, 'svg' => hc_qr_svg('mailto:' . $address)], JSON_UNESCAPED_SLASHES);
    exit;
}

/** Email RP when address generation fails. Throttled to one alert per hour via a marker file so a burst
    of page loads sends one message, not hundreds. Uses the site's own sender (server@) and recipient. */
function hc_qr_alert(Throwable $err): void {
    $dir    = function_exists('Contact\data_dir') ? Contact\data_dir() : '/var/www/highlyconfidential.co.uk/data';
    $marker = $dir . '/qr-alert.sent';
    $last   = @filemtime($marker);
    if ($last !== false && time() - $last < 3600) { return; }
    @touch($marker);
    $to     = function_exists('Contact\cfg') ? Contact\cfg('recipient') : 'robert@highlyconfidential.co.uk';
    $from   = function_exists('Contact\cfg') ? Contact\cfg('sender')    : 'server@highlyconfidential.co.uk';
    $issued = @count(file(hc_qr_issued_file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $when   = gmdate('D, d M Y H:i:s') . ' UTC';
    $subject = '[' . hc_qr_domain() . '] Contact page QR address FAILED';
    $body = "The contact page could not create a one-visit email address.\n\n"
          . "When:    {$when}\n"
          . "Error:   " . $err->getMessage() . "\n"
          . "Issued:  {$issued} addresses so far (data/qr-issued.txt)\n"
          . "Page:    " . (function_exists('Contact\\cfg') ? Contact\cfg('url') : 'https://highlyconfidential.co.uk') . "/contact.php\n\n"
          . "The page still loaded, but without the QR card. Visitors can still use the form.\n"
          . "This alert is sent at most once an hour while the fault persists.\n";
    $headers = implode("\r\n", [
        'From: ' . hc_qr_domain() . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Auto-Response-Suppress: All',
    ]);
    @mail($to, $subject, $body, $headers, '-f' . $from);
    error_log('qr-mailto: address generation failed: ' . $err->getMessage());
}

/** The card shell. Contains no address; the visitor slides to reveal, then contact.js fetches one. */
function hc_qr_card(): string {
    return <<<HTML
<style>
  .qrc { margin-top:1.25rem; background:var(--cf-card,var(--card,#fff)); border:1px solid var(--cf-line,var(--line,#cbd5e1)); border-radius:14px; padding:1.5rem;
         display:grid; grid-template-columns:168px 1fr; gap:1.25rem; align-items:center; color:var(--cf-fg,var(--fg,#0f172a)); text-align:left; }
  .qrc .qr { background:#fff; border-radius:10px; padding:.5rem; border:1px solid #e2e8f0; aspect-ratio:1; }
  .qrc .qr svg { display:block; width:100%; height:auto; animation:qr-in .35s ease; }
  .qrc .qr svg path { fill:#0f172a; }
  .qrc .qr .wait { display:block; width:100%; aspect-ratio:1; border-radius:6px; background:repeating-linear-gradient(45deg,#f1f5f9 0 8px,#e2e8f0 8px 16px); }
  @keyframes qr-in { from { opacity:0; transform:scale(.92); } to { opacity:1; transform:none; } }
  .qrc h2 { font-size:1.05rem; font-weight:600; margin:0 0 .35rem; letter-spacing:-.01em; line-height:1.3; }
  .qrc p { margin:0 0 .6rem; font-size:.92rem; color:var(--cf-muted,var(--muted,#64748b)); line-height:1.5; }
  .qrc p:last-child { margin-bottom:0; }
  .qrc .addr { display:inline-block; font-family:ui-monospace,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace; font-size:.95rem;
               color:var(--cf-fg,var(--fg,#0f172a)); text-decoration:none; background:var(--cf-field,var(--field,var(--bg,#fff))); border:1px solid var(--cf-line,var(--line,#cbd5e1));
               border-radius:8px; padding:.4rem .7rem; word-break:break-all; animation:qr-in .35s ease; }
  .qrc .addr b { font-weight:600; }
  .qrc .addr:hover { border-color:var(--cf-accent,var(--accent,#0f172a)); color:var(--cf-fg,var(--fg,#0f172a)); }
  .qrc .after { display:none; }
  .qrc.shown .after { display:block; }
  .qrc.shown .before, .qrc.shown .rv, .qrc.shown .rvk { display:none; }
  .qrc.off { display:none; }
  /* slide-to-reveal track: same idiom as the form's slide-to-send, a little smaller */
  .qrc .rv { position:relative; height:48px; border-radius:24px; background:var(--cf-track,var(--track,#e2e8f0)); border:1px solid var(--cf-line,var(--line,#cbd5e1));
             user-select:none; -webkit-user-select:none; touch-action:pan-y; overflow:hidden; margin-top:.4rem; }
  .qrc .rv .fill { position:absolute; inset:0; width:0; background:var(--cf-accent,var(--accent,#0f172a)); opacity:.14; transition:width .05s linear; }
  .qrc .rv .hint { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; gap:.5rem; font-weight:600; font-size:.9rem;
                   letter-spacing:.02em; color:var(--cf-muted,var(--muted,#64748b)); pointer-events:none; transition:opacity .2s; }
  .qrc .rv .hint .chev { display:inline-block; animation:cf-nudge 1.4s ease-in-out infinite; }
  .qrc .rv .knob { position:absolute; top:3px; left:3px; width:40px; height:40px; border-radius:50%; background:var(--cf-knob,var(--knob,var(--cf-accent,var(--accent,#0f172a))));
                   color:var(--cf-knobfg,var(--knobfg,#fff)); display:grid; place-items:center; cursor:grab; box-shadow:0 2px 8px rgba(0,0,0,.25); touch-action:none; outline:none; transition:transform .05s linear; }
  .qrc .rv .knob:focus-visible { box-shadow:0 0 0 3px color-mix(in srgb, var(--cf-accent,var(--accent,#0f172a)) 40%, transparent), 0 2px 8px rgba(0,0,0,.25); }
  .qrc .rv .knob svg { width:20px; height:20px; }
  .qrc .rv.dragging .knob { cursor:grabbing; transition:none; }
  .qrc .rv.dragging .hint { opacity:.35; }
  .qrc .rv.busy .hint { opacity:1; color:var(--cf-fg,var(--fg,#0f172a)); }
  .qrc .rv.busy .hint .chev { display:none; }
  .qrc .rvk { font-size:.78rem; color:var(--cf-muted,var(--muted,#64748b)); margin:.4rem 0 0; }
  @media (max-width: 520px) { .qrc { grid-template-columns:1fr; text-align:center; } .qrc .qr { width:168px; margin:0 auto; } }
</style>
<section class="qrc" id="qrc" aria-labelledby="qrh">
  <div class="qr" id="qrbox"><span class="wait" aria-hidden="true"></span></div>
  <div>
    <h2 id="qrh">Or scan to email from your phone</h2>
    <p>This email address is unique to you. Slide to reveal it, then scan the code or tap the address and your mail app opens with it filled in.</p>
    <div class="rv" id="rv" aria-label="Slide to reveal">
      <div class="fill"></div>
      <div class="hint"><span class="txt">Slide to reveal</span><span class="chev">&rsaquo;&rsaquo;</span></div>
      <div class="knob" id="rvknob" role="button" tabindex="0" aria-describedby="rvhint" aria-label="Slide to reveal the address. Drag to the right, or hold Space.">
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>
          <path d="M14 14h3v3M21 14v0M14 21h3M21 18v3"/>
        </svg>
      </div>
    </div>
    <p class="rvk" id="rvhint">Keyboard: focus the knob and hold <kbd>Space</kbd>.</p>
    <p class="after"><a class="addr" id="qraddr" href="#" rel="nofollow">&#8230;</a></p>
    <p class="after">Reload the page and you get a different one.</p>
    <noscript><p>Turn on JavaScript to reveal the address.</p></noscript>
  </div>
</section>
HTML;
}
