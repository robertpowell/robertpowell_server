<?php
declare(strict_types=1);
require '/var/www/shared/contact/page-helpers.php';
require __DIR__ . '/theme.inc.php';
$theme['title'] = 'Privacy — Robert Powell';
$theme['tagline'] = 'Privacy policy';
$theme['footer_links'] = [['/', 'Home'], ['/contact.php', 'Contact']];
$content_html = render_fragment('/var/www/shared/contact/privacy-content.php', ['contact_url' => '/contact.php']);
include '/var/www/shared/contact/page-standalone.php';
