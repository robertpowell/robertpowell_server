<?php
declare(strict_types=1);
require '/var/www/shared/contact/page-helpers.php';
$token = contact_entry('highlyconfidential.co.uk');
require '/var/www/highlyconfidential.co.uk/lib/theme.php';
$theme['title'] = 'Contact — highlyconfidential.co.uk';
$theme['tagline'] = 'Send a message. It goes straight to the inbox, nowhere else.';
require '/var/www/shared/contact/qr-mailto.php';
hc_qr_serve_if_requested();               // answers ?qr=1&token=... with JSON and exits
$content_html = render_fragment('/var/www/shared/contact/form-fragment.php', ['token' => $token, 'privacy_url' => '/privacy.php'])
              . hc_qr_card();
$script_src = '/contact.js?v=' . filemtime(__DIR__ . '/contact.js');   // cache-bust on every update
include '/var/www/shared/contact/page-standalone.php';
