<?php
declare(strict_types=1);
require '/var/www/shared/contact/page-helpers.php';
$token = contact_entry('rpowell.co.uk');
require __DIR__ . '/theme.inc.php';
$theme['title'] = 'Contact — Robert Powell';
$theme['tagline'] = 'Send a message';
require '/var/www/shared/contact/qr-mailto.php';
hc_qr_serve_if_requested();               // answers ?qr=1&token=... with JSON and exits
$content_html = render_fragment('/var/www/shared/contact/form-fragment.php', ['token' => $token, 'privacy_url' => '/privacy.php'])
              . hc_qr_card();
$script_src = '/contact.js?v=' . filemtime(__DIR__ . '/contact.js');   // cache-bust on every update
include '/var/www/shared/contact/page-standalone.php';
