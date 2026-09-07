#!/usr/bin/env php
<?php
/**
 * Daily Visitor Report Script
 * Sends email with last 20 visitors, excluding updown.io
 */

// Load credentials
require_once '/var/www/config/credentials.php';

// Configuration
$recipientEmail = 'mail@robertpowell.com';
$senderEmail = 'server@robertpowell.net';
$subject = 'Daily Visitor Report - ' . date('Y-m-d');

/**
 * Get organization name for IP address using ip-api.com
 */
function getIpOrganization($ip) {
    $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=org,isp";

    $context = stream_context_create([
        'http' => ['timeout' => 5]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return '-';
    }

    $data = json_decode($response, true);
    if ($data && isset($data['org']) && !empty($data['org'])) {
        return $data['org'];
    } elseif ($data && isset($data['isp']) && !empty($data['isp'])) {
        return $data['isp'];
    }

    return '-';
}

/**
 * Calculate time ago string
 */
function timeAgo($timestamp) {
    $now = new DateTime();
    $past = new DateTime($timestamp);
    $diff = $now->diff($past);

    $parts = [];
    if ($diff->d > 0) $parts[] = $diff->d . 'd';
    if ($diff->h > 0) $parts[] = $diff->h . 'h';
    if ($diff->i > 0) $parts[] = $diff->i . 'm';

    if (empty($parts)) return 'just now';
    return implode('', $parts) . ' ago';
}

// Connect to database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log("Visitor report: Database connection failed: " . $conn->connect_error);
    exit(1);
}

// Query last 20 visitors, excluding updown.io user agent
$sql = "SELECT id, timestamp, ipaddress, country, city, pagevisited, os, device, browser, useragent
        FROM events
        WHERE useragent NOT LIKE '%updown.io%' OR useragent IS NULL
        ORDER BY id DESC
        LIMIT 20";

$result = $conn->query($sql);

if (!$result) {
    error_log("Visitor report: Query failed: " . $conn->error);
    $conn->close();
    exit(1);
}

// Build HTML email
$html = '<!DOCTYPE html>
<html>
<head>
<style>
body { font-family: Arial, sans-serif; font-size: 14px; }
h2 { color: #333; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th { background-color: #f47321; color: white; padding: 10px; text-align: left; font-size: 12px; }
td { padding: 8px; border-bottom: 1px solid #ddd; font-size: 12px; }
tr:nth-child(even) { background-color: #f9f9f9; }
tr:hover { background-color: #f5f5f5; }
.time-ago { color: #666; font-style: italic; }
.location { white-space: nowrap; }
</style>
</head>
<body>
<h2>Daily Visitor Report</h2>
<p>Generated: ' . date('Y-m-d H:i:s') . '</p>
<table>
<tr>
<th>#</th>
<th>When</th>
<th>IP Address</th>
<th>Organization</th>
<th>Location</th>
<th>Page</th>
<th>Browser</th>
<th>OS</th>
<th>Device</th>
</tr>';

$count = 0;
while ($row = $result->fetch_assoc()) {
    $count++;
    $ago = timeAgo($row['timestamp']);
    $location = trim($row['city'] . ', ' . $row['country'], ', ');
    $page = basename($row['pagevisited'] ?: '/');
    $org = getIpOrganization($row['ipaddress']);

    $html .= '<tr>';
    $html .= '<td>' . $count . '</td>';
    $html .= '<td><span class="time-ago">' . htmlspecialchars($ago) . '</span></td>';
    $html .= '<td>' . htmlspecialchars($row['ipaddress']) . '</td>';
    $html .= '<td>' . htmlspecialchars($org) . '</td>';
    $html .= '<td class="location">' . htmlspecialchars($location) . '</td>';
    $html .= '<td>' . htmlspecialchars($page) . '</td>';
    $html .= '<td>' . htmlspecialchars($row['browser'] ?: '-') . '</td>';
    $html .= '<td>' . htmlspecialchars($row['os'] ?: '-') . '</td>';
    $html .= '<td>' . htmlspecialchars($row['device'] ?: '-') . '</td>';
    $html .= '</tr>';
}

$result->free();
$conn->close();

if ($count == 0) {
    $html .= '<tr><td colspan="9" style="text-align:center;">No visitors recorded (excluding updown.io monitoring)</td></tr>';
}

$html .= '</table>
<p style="color:#666; font-size:11px; margin-top:20px;">This report excludes visits from updown.io monitoring service.</p>
</body>
</html>';

// Send HTML email
$headers = "From: $senderEmail\r\n";
$headers .= "Date: " . date("r") . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

if (mail($recipientEmail, $subject, $html, $headers)) {
    echo "Visitor report sent successfully to $recipientEmail\n";
    exit(0);
} else {
    error_log("Visitor report: Failed to send email");
    exit(1);
}
