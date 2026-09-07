<?php
require_once 'check_allowed_ip.php';
if (checkAllowedIP($_SERVER['REMOTE_ADDR']) == 0) {
    http_response_code(403);
    // The 403 short-circuits before header.php, so send the baseline CSP here.
    // Apache cannot supply it: this response is PHP at URI '/', not a .html path.
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
    include('403.html');
    exit;
}
include ('header.php');
?>

<div id="main" class="container text-justify">
    <div class="col col-sm-1"></div>
    <div class="col col-sm-10" style="margin-left: auto; margin-right: auto">
        <h3><i class="fal fa-lock-alt"></i> Privacy Policy</h3>
        <p>This policy applies from 1st May 2018 - Version 1.0</p>
        <p class="text-justify">This Privacy Policy describes how and when I collect & use information when you visit my website. This is to comply with the General Data Protection Regulations (GDPR) 2018.</p>
        <h3><i class="fal fa-notes-medical"></i> Information I Collect</h3>
        <p class="text-justify">I do not collect any personal information on this website. I do collect information about the user agent, IP address and other meta data about your visit. By visiting this website you are giving consent to the collection of this data. This data may be retained indefinitly. The data is used for statistical analysis and cannot be used to identify an invidual. The site does not use cookies. I do not track visitors.</p>
        <h3><i class="fal fa-envelope"></i> How to Contact Me</h3>
        <p class="text-justify">For purposes of the GDPR, I, Robert Powell, am the data controller of the information collected on this website. Contact Details are above.</p>
    </div>
    <div class="col col-sm-1"></div>
    <?php
    // this event added to allow stats to work correctly
    $event="PageVisit";
    include ('footer.php');
    // tracking goes after this comment
    include ('track.php');
    ?>
