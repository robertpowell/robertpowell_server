<?php
require_once 'check_allowed_ip.php';
$remoteaddr = $_SERVER['REMOTE_ADDR'];
$isAllowed = checkAllowedIP($remoteaddr);
if ($isAllowed == 0) {
    http_response_code(403);
    // The 403 short-circuits before header.php, so send the baseline CSP here.
    // Apache cannot supply it: this response is PHP at URI '/', not a .html path.
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
    include('403.html');
    exit;
}






include ('header.php');
?>
    <!-- Main content -->
    <div id="main" role="main" class="container">
        <!-- A row of columns -->
        <div class="row">
            <div class="col-sm-4 col-md-4"> 
                <h2>
<i id="gear" class="fa-duotone fa-thin fa-gear" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Services</h2>
<ul class="pl-2" style="list-style-type:none;">
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i>
 Fintec Product Management</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Forensic Digital data recovery and evidence processing</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Evidence processing</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 GDPR consultancy</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Subject Data Access Requests Consultancy</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Government Agency Support</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Police and Crime Agencies Support</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 eComms Surveillance and threat analysis</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Cyber Threat analysis</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Expert Witness</li>
<li><i class="fa-duotone fa-solid fa-square-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> 
 Mobile Phone Data Analytics</li>
                </ul>
            </div>
            <div class="col-sm-4 col-md-4">
                <h2><i id="location" class="fa-duotone fa-location-dot" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> About me</h2>
                <p>I have over 30 years of experience in financial markets crime, evidence discovery and surveillance working with Financial Markets Regulators, Security Services, Police Forces and Crime Agencies in over 83 countries. </p>
                <p>I have worked on some of the most high profile cases in the last 20 years and continue to provide support to Police forces and Governments in the detection of financial markets crime.</p>
                <p>Digital evidence collection, timelines and forensic analysis of data and evidence continues to be the bedrock of my work.</p>
            </div>
            <div class="col-sm-4 col-md-4">
                <h2><i id="contact" class="fa-duotone fa-inbox-in" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Contact me</h2>
                <ul class="pl-2" style="list-style-type:none;">
                    <p>Please contact me for further information.</p>
                    <p></p>
                <p></p>
                <hr>
            </div>
        </div>
    <!-- /container -->

    <?php
    // this event added to allow stats to work correctly
    $event="PageVisit";
    include ('footer.php');
    // add tracking php after this.
    include ('track.php');
    ?>
