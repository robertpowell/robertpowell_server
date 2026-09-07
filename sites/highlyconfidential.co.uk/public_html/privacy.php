<?php
declare(strict_types=1);
require '/var/www/shared/contact/page-helpers.php';
require '/var/www/highlyconfidential.co.uk/lib/theme.php';
$theme['title'] = 'Privacy — highlyconfidential.co.uk';
$theme['tagline'] = 'Privacy policy';
$theme['footer_links'] = [['/', 'Front page'], ['/contact.php', 'Contact']];
$content_html = render_fragment('/var/www/shared/contact/privacy-content.php', ['contact_url' => '/contact.php']);
include '/var/www/shared/contact/page-standalone.php';
