<?php
/**
 * /polar/ — unlisted video page (2026-11-03).
 *
 * Not linked from anywhere on robertpowell.net, absent from the sitemap, and sent with noindex
 * (header below and in polar/.htaccess), so it is reached only by someone given the address.
 * Shows the one video file found in media/ (mp4, m4v, webm or mov; a poster.jpg|png and a
 * title.txt are used if present). polar.js reports playback progress to beacon.php; the daily
 * email comes from /usr/local/bin/polar-video-report.py.
 */
declare(strict_types=1);

header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
     . "media-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$types = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
$dir = __DIR__ . '/media';
$file = $mime = null;
$names = glob($dir . '/*') ?: [];
sort($names);
foreach ($names as $f) {
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if (is_file($f) && isset($types[$ext])) { $file = basename($f); $mime = $types[$ext]; break; }
}
$poster = null;
foreach (['poster.jpg', 'poster.png'] as $p) { if (is_file("$dir/$p")) { $poster = $p; break; } }
$title = is_file("$dir/title.txt") ? trim((string)file_get_contents("$dir/title.txt")) : '';
$h = fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?= $h($title !== '' ? $title : 'Video') ?></title>
<link rel="icon" href="/favicon.ico">
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body { background: #0b1120; color: #e2e8f0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
         display: grid; place-items: center; padding: 1.5rem; }
  main { width: min(100%, 72rem); text-align: center; }
  h1 { font-size: clamp(1.1rem, 2.5vw, 1.5rem); font-weight: 500; letter-spacing: -0.01em; margin: 0 0 1rem; color: #cbd5e1; }
  video { display: block; width: 100%; max-height: 82vh; background: #000; border-radius: 10px; outline: none; }
  p.note { color: #94a3b8; margin: 1rem 0 0; font-size: 0.9rem; }
</style>
</head>
<body>
<main>
<?php if ($file === null): ?>
  <h1>Nothing to see here yet</h1>
  <p class="note">The video has not been uploaded.</p>
<?php else: ?>
<?php if ($title !== ''): ?>  <h1><?= $h($title) ?></h1>
<?php endif; ?>
  <video id="v" controls playsinline preload="metadata" controlslist="nodownload" data-file="<?= $h($file) ?>"<?= $poster ? ' poster="media/' . $h($poster) . '"' : '' ?>>
    <source src="media/<?= $h(rawurlencode($file)) ?>" type="<?= $h($mime) ?>">
    Your browser cannot play this video.
  </video>
  <script src="polar.js"></script>
<?php endif; ?>
</main>
</body>
</html>
