<?php
// Generate a random nonce for CSP - must be unique per request
$csp_nonce = base64_encode(random_bytes(16));

// Content-Security-Policy is sent as an HTTP HEADER, not a <meta> tag.
// Headers are authoritative, and frame-ancestors is IGNORED in meta entirely.
// .htaccess deliberately no longer sets a global CSP (it would override this);
// it now scopes its own policy to .html only, so each response gets exactly one.
// Fixed here vs the old meta policy (all were broken and blocking):
//   - "https://*.fontawesome.com/*"  -> "/*" is a literal PATH in CSP, not a
//     wildcard, so FontAwesome was never actually allowed. Path dropped.
//   - "connect-src https://*/fontawesome.com/*/"  -> malformed host+path.
//   - "worker-src 'self' child-src blob:"  -> missing semicolon, so "child-src"
//     was parsed as a source expression and no child-src directive existed.
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'nonce-{$csp_nonce}' https://*.fontawesome.com; "
    . "style-src 'self' 'unsafe-inline' https://*.fontawesome.com; "
    . "font-src 'self' data: https://*.fontawesome.com; "
    . "img-src * data: blob:; "
    . "connect-src 'self' https://*.fontawesome.com; "
    . "worker-src 'self' blob:; "
    . "child-src 'self' blob:; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'; "
    . "frame-ancestors 'none'");
header("X-Frame-Options: DENY");   // OWASP review 2026-09-05: legacy twin of frame-ancestors 'none'

// Canonical URL. The host is HARD-CODED rather than taken from $_SERVER['HTTP_HOST']
// so a spoofed Host header cannot inject a foreign canonical, and so that
// www.robertpowell.net (a ServerAlias with no redirect, serving identical content)
// canonicalises to the non-www form used in sitemap.xml.
// "/index.php" and "/" are byte-identical, so index.php canonicalises to "/".
$canonical_path = $_SERVER['SCRIPT_NAME'] ?? '/';
if ($canonical_path === '/index.php') { $canonical_path = '/'; }
$canonical_url = 'https://robertpowell.net' . $canonical_path;
?>
<!doctype html>
<!-- dev copy -->
<!--[if lt IE 7]>      <html class="no-js lt-ie9 lt-ie8 lt-ie7" lang=""> <![endif]-->
<!--[if IE 7]>         <html class="no-js lt-ie9 lt-ie8" lang=""> <![endif]-->
<!--[if IE 8]>         <html class="no-js lt-ie9" lang=""> <![endif]-->
<!--[if gt IE 8]><!-->
<html class="no-js" lang="en">
<!--<![endif]-->

<head>
    <meta charset="utf-8">
    <link rel="canonical" href="<?= htmlspecialchars($canonical_url, ENT_QUOTES) ?>">
    <meta http-equiv="Referrer-Policy" content="no-referrer, strict-origin-when-cross-origin">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="description" content="Robert Powell - Consultancy - Data Surveillance and Forensic analysis">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Fontawesome -->
    <link type="text/css" href="/css/all.min.css" rel="stylesheet">


    <!-- <script src="https://kit.fontawesome.com/c23551763c.js" crossorigin="anonymous"></script> -->

    <!-- Bootstrap  CSS -->
    <link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
    <!-- My Styles -->
    <link rel="stylesheet" href="css/main.css">
    <!-- Fav Icons -->
    <link rel="apple-touch-icon" href="foot.png">
    <link rel="icon" href="favicon.ico">
    
    <title>Robert Powell</title>
</head>

<body class="pt-5">
    <!-- Navigation Start -->
    <nav class="navbar navbar-expand-lg fixed-top bg-dark navbar-dark" id="mynavbar">
        <a class="navbar-brand" href="index.php">Robert Powell - Consultancy</a>

        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span><i class="fal fa-bars"></i></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item" id="index">
                    <a class="nav-link" href="index.php"><i class="fa-duotone fa-solid fa-house" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Home <span class="sr-only">(current)</span></a>
                </li>
                <li class="nav-item" id="privacy">
                    <a class="nav-link" href="privacy.php"><i class="fa-duotone fa-regular fa-lock-keyhole" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Privacy</a>
                </li>
                <li class="nav-item" id="contact">
                    <a class="nav-link" href="contact.php"><i class="fa-duotone fa-solid fa-envelope" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Contact</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link button" href="#colourToggle" id="colortoggle"><i class="fa-duotone fa-thin fa-moon" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i>/<i class="fa-duotone fa-solid fa-sun" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i></i>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link button" href="#bigger" id="bigger"><i class="fa-duotone fa-solid fa-plus" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Larger Text</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link button" href="#smaller" id="smaller"><i class="fa-duotone fa-solid fa-minus" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Smaller Text</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link button" href="#reload" id="reload"><i class="fa-duotone fa-solid fa-check" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Reset Text</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link button" href="#mymodal" id="covid19"><i class="fa-duotone fa-solid fa-triangle-exclamation" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i></i> Update</a>
                </li>

            </ul>
        </div>
    </nav>


    <!-- Main jumbotron -->
    <div class="jumbotron jumbotron-fluid" id="header">
        <div class="container">
            <br>
            <div class="logosmall">
                <h1 class="text-center d-block d-sm-none">Robert Powell</h1>
                <h1 class="text-center display-1 d-none d-sm-block">Robert Powell</h1>
            </div>
            <h5 class="text-center">Forensic Digital Evidence &amp; Communication Surveillance Consultancy</h5>
            <h3 class="text-center">
                <i id="phone" class="fa-duotone fa-solid fa-phone" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;" ></i> <a href="tel:+447970123407">07970 123 407</a></h3>
            <h6 class=" text-center d-block d-sm-none">
                (Tap to dial)
            </h6>
            <div class="d-block d-lg-none"><br></div>
            <h3 class="text-center">
                <p><i id="globe" class="fa-duotone fa-solid fa-globe" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i> Available Worlwide</p></h3>
            </h3>
            <h3 class="text-center">
			<i id="contact" class="fa-duotone fa-solid fa-envelope" style="--fa-primary-color: #fa4a04; --fa-secondary-color: #fa4a04;"></i>
              <a href="mailto:mail@robertpowell.net?subject=Message from robertpowell.net">mail@robertpowell.net</a>
            </h3>
        </div>
    </div>
