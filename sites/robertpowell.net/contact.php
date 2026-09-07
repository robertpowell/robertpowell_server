<?php
require_once 'check_allowed_ip.php';
if (checkAllowedIP($_SERVER['REMOTE_ADDR']) == 0) {
    http_response_code(403);
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
    include('403.html');
    exit;
}
require '/var/www/shared/contact/page-helpers.php';
$token = contact_entry('robertpowell.net');       // answers POSTs with JSON and exits
require '/var/www/shared/contact/qr-mailto.php';
hc_qr_serve_if_requested();               // answers ?qr=1&token=... with JSON and exits
include ('header.php');
?>

<div id="main" class="container">
    <div class="row">
        <div class="col-lg-8 offset-lg-2">
            <h3><i class="fa-duotone fa-solid fa-envelope" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Contact me</h3>
            <p>Use the form below and I will reply by email. To send, drag the lock across the bar at the bottom.</p>
            <div style="--cf-accent:#fa4a04; --cf-knob:#fa4a04; --cf-knobfg:#fff; --cf-fg:#212529; --cf-muted:#6c757d; --cf-line:#dee2e6; --cf-card:#fff; --cf-field:#fff; --cf-track:#e9ecef; margin-bottom:2rem">
                <?= render_fragment('/var/www/shared/contact/form-fragment.php', ['token' => $token, 'privacy_url' => '/privacy.php']) ?>
                <?= hc_qr_card() ?>
            </div>
        </div>
    </div>
    <script src="/contact.js?v=<?= filemtime(__DIR__ . '/contact.js') ?>" defer></script>
    <?php
    $event="PageVisit";
    include ('footer.php');
    include ('track.php');
    ?>
