<?php
/* Standalone page shell used by the static sites and the blog front for contact/privacy pages.
   $theme keys: title, site_html (heading), tagline, logo_html, font, vars_light (css var block),
   vars_dark (css var block or ''), header_class, footer_links [[href,label],...], color_scheme */
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($theme['title']) ?></title>
<?= $theme['head_extra'] ?? '' ?>
<style>
  :root { color-scheme:<?= $theme['color_scheme'] ?? 'light dark' ?>; <?= $theme['vars_light'] ?> }
<?php if (!empty($theme['vars_dark'])): ?>
  @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { <?= $theme['vars_dark'] ?> } }
  :root[data-theme="dark"] { <?= $theme['vars_dark'] ?> }
<?php endif; ?>
  * { box-sizing:border-box; }
  html, body { min-height:100%; margin:0; }
  body { background:var(--bg); color:var(--fg); font:16px/1.6 <?= $theme['font'] ?>; padding:2rem 1rem; }
  main { width:100%; max-width:<?= $theme['measure'] ?? '34rem' ?>; margin:0 auto; }
  header.ph { text-align:center; margin-bottom:1.5rem; }
  header.ph.band { background:var(--band,#343a40); padding:2.2rem 1rem; border-radius:14px; }
  .mark { width:64px; height:64px; margin:0 auto 1rem; display:block; border-radius:14px; }
  h1 { font-size:clamp(1.4rem,4vw,2rem); font-weight:600; letter-spacing:-.02em; margin:0 0 .25rem; }
  h1 .tld { color:var(--muted); font-weight:500; }
  h1 .pill { display:inline-block; color:#fff; background:var(--accent); padding:.25rem 1rem; border-radius:6px; }
  header.ph p { color:var(--muted); margin:.35rem 0 0; font-size:.95rem; }
  header.ph.band p { color:#cbd5e1; }
  article.card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:1.5rem 1.75rem; }
  article.card h2 { font-size:1.05rem; font-weight:600; margin:1.6rem 0 .4rem; letter-spacing:-.01em; }
  article.card h2:first-child { margin-top:0; }
  article.card ul { padding-left:1.2rem; }
  article.card table { width:100%; border-collapse:collapse; font-size:.92rem; margin:.5rem 0 1rem; }
  article.card th, article.card td { text-align:left; padding:.5rem .6rem; border-bottom:1px solid var(--line); vertical-align:top; }
  article.card th { font-size:.75rem; letter-spacing:.05em; text-transform:uppercase; color:var(--muted); font-weight:600; }
  article.card .muted { color:var(--muted); font-size:.9rem; }
  a { color:var(--fg); }
  footer.pf { text-align:center; font-size:.8rem; color:var(--muted); margin-top:1.5rem; }
  footer.pf a { color:var(--muted); }
  .cf { --cf-fg:var(--fg); --cf-muted:var(--muted); --cf-line:var(--line); --cf-card:var(--card); --cf-field:var(--field,var(--bg));
        --cf-accent:var(--accent); --cf-ok:var(--ok,#15803d); --cf-bad:var(--bad,#b91c1c); --cf-track:var(--track,var(--line));
        --cf-knob:var(--knob,var(--accent)); --cf-knobfg:var(--knobfg,#fff); }
</style>
</head>
<body>
<main>
  <header class="ph <?= $e($theme['header_class'] ?? '') ?>">
    <?= $theme['logo_html'] ?? '' ?>
    <h1><?= $theme['site_html'] ?></h1>
    <?php if (!empty($theme['tagline'])): ?><p><?= $theme['tagline'] ?></p><?php endif; ?>
  </header>
  <?= $content_html ?>
  <footer class="pf"><?php
    $links = [];
    foreach ($theme['footer_links'] as [$href, $label]) { $links[] = '<a href="' . $e($href) . '">' . $e($label) . '</a>'; }
    echo implode(' &middot; ', $links);
  ?></footer>
</main>
<?php if (!empty($script_src)): ?><script src="<?= $e($script_src) ?>" defer></script><?php endif; ?>
</body>
</html>
