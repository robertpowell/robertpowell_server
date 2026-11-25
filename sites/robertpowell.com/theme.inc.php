<?php
$mark = '<svg class="mark" viewBox="0 0 100 100" width="64" height="64" aria-hidden="true" style="border-radius:20px"><rect x="2" y="2" width="96" height="96" rx="20" fill="var(--mk-tile)"/>'
      . '<g fill="none" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 16 V46 M20 16 H33 A9 9 0 0 1 33 32 H20 M31 32 L41 46" stroke="var(--mk-line)"/>'
      . '<path d="M20 54 V84 M20 54 H33 A9 9 0 0 1 33 70 H20" stroke="var(--mk-line)"/><circle cx="70" cy="35" r="11" stroke="var(--mk-o)"/><circle cx="70" cy="73" r="11" stroke="var(--mk-o)"/></g></svg>';
$theme = [
  'site_html' => 'RobertPowell<span class="tld">.com</span>',
  'logo_html' => $mark,
  'font' => '-apple-system,BlinkMacSystemFont,"Segoe UI","Helvetica Neue","Noto Sans",Arial,sans-serif',
  'vars_light' => '--bg:#fbfaf8; --fg:#1b1a18; --muted:#5d5a55; --line:#e4e0d9; --card:#fff; --field:#fff; --accent:#a3571a; --track:#efece6; --knob:#a3571a; --knobfg:#fff; --mk-tile:#26304d; --mk-line:#eef1f7; --mk-o:#f0a55e;',
  'vars_dark'  => '--bg:#14130f; --fg:#ece7de; --muted:#a7a196; --line:#2c2822; --card:#1c1a16; --field:#14130f; --accent:#f0a55e; --ok:#4ade80; --bad:#f87171; --track:#2c2822; --knob:#f0a55e; --knobfg:#1b1a18; --mk-tile:#38456b; --mk-line:#f4f6fa; --mk-o:#f7b876;',
  // follow the blog's own theme toggle (stored in localStorage by base.html)
  'head_extra' => "<script>(function(){try{var t=localStorage.getItem('ropo_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>\n<link rel=\"icon\" href=\"/static/favicon.svg\" type=\"image/svg+xml\">",
  'footer_links' => [['/', 'Home'], ['/archive/', 'Archive'], ['/privacy.php', 'Privacy']],
];
