<?php
require '/var/www/shared/contact/geo-local.php';
$t = microtime(true);
foreach ($argv as $i => $ip) { if ($i === 0) continue;
  $g = GeoLocal\lookup($ip);
  unset($g['org']); echo str_pad($ip, 40), json_encode($g, JSON_UNESCAPED_UNICODE), "\n"; }
printf("country(8.8.8.8)=%s  %.1f ms total, db age %d d\n", GeoLocal\country('8.8.8.8'), (microtime(true)-$t)*1000, GeoLocal\age_days());
