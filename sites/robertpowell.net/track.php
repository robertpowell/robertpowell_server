<?php
/**
 * Visitor tracking script
 * Uses Matomo Device Detector for user agent parsing and the GeoLite2 databases on this box
 * for geolocation (ROP-341; was ip-api.com). Nothing about the visitor leaves the server.
 */

// Load Composer autoloader for Device Detector
require_once '/var/www/vendor/autoload.php';

// Load credentials from secure location outside webroot
require_once '/var/www/config/credentials.php';

use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;

// User agent detection via Device Detector (local, no API)
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$dd = new DeviceDetector($userAgent);
$dd->parse();

// Extract values from Device Detector
$os = $dd->getOs('name') ?? '';
$device = $dd->getDeviceName() ?? '';
$browser = $dd->getClient('name') ?? '';
$ismobile = $dd->isMobile() ? 1 : 0;
$iscrawler = $dd->isBot() ? 1 : 0;

// Get referrer safely
$referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'not_set';
$referrer = str_replace("https://robertpowell.net/", "", $referrer);

// Get page visited and remote address
$pagevisited = $_SERVER['PHP_SELF'] ?? '';
$remoteaddr = $_SERVER['REMOTE_ADDR'] ?? '';
$requestmethod = $_SERVER['REQUEST_METHOD'] ?? '';

// IP geolocation lookup, local (shared/contact/geo-local.php). No Private Relay scan here:
// this runs on every page view and only needs country, city and postcode.
require_once '/var/www/shared/contact/geo-local.php';
$ipapi_result = \GeoLocal\lookup($remoteaddr, false);

// Safely extract geolocation data
$countryCode = $ipapi_result['countryCode'] ?? '';
$city = $ipapi_result['city'] ?? '';
$zip = $ipapi_result['zip'] ?? '';

// Geo-blocking: whitelist of allowed countries
$allowedCountries = [
    'GB', // United Kingdom
    // EU countries
    'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
    'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
    'SI', 'ES', 'SE',
    // EEA/EFTA
    'NO', 'IS', 'LI', 'CH',
    // North America
    'US', 'CA',
    // Other allies
    'AU', 'NZ', 'JP'
];

// Log non-whitelisted countries for fail2ban (only if we got a country code)
if ($countryCode !== '' && !in_array($countryCode, $allowedCountries)) {
    $logLine = date('Y-m-d H:i:s') . " GEO-BLOCK: $remoteaddr from $countryCode\n";
    file_put_contents('/var/log/apache2/geo-block.log', $logLine, FILE_APPEND | LOCK_EX);
}

// Get event (should be set by including page)
if (!isset($event) || $event === '') {
    $event = 'Not known';
}

// Select table based on crawler status
$tablename = ($iscrawler == 1) ? 'bots' : 'events';

// PHP 8.1+ sets mysqli's default error mode to MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT,
// which makes mysqli THROW instead of returning false / setting connect_error. That would
// make every check below unreachable and turn a database outage into an uncaught
// mysqli_sql_exception part-way through rendering the page. Restore return-value
// semantics so the existing fail-silently handling below works as written.
mysqli_report(MYSQLI_REPORT_OFF);

// Create database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    return; // Fail silently - don't expose errors to users
}

// Use prepared statement to prevent SQL injection
$sql = "INSERT INTO {$tablename} (ipaddress, country, city, zip, pagevisited, referringpage, requestmethod, event, os, device, browser, useragent, ismobile, iscrawler) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param(
        "ssssssssssssii",
        $remoteaddr,
        $countryCode,
        $city,
        $zip,
        $pagevisited,
        $referrer,
        $requestmethod,
        $event,
        $os,
        $device,
        $browser,
        $userAgent,
        $ismobile,
        $iscrawler
    );

    if (!$stmt->execute()) {
        error_log("Track insert failed: " . $stmt->error);
    }

    $stmt->close();
} else {
    error_log("Prepare failed: " . $conn->error);
}

$conn->close();
