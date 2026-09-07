<?php
/**
 * Check if IP is from Allowed Countries
 *
 * Checks if IP is from: US, UK, EU members, Canada, Switzerland,
 * Australia, Norway, New Zealand, Iceland, and Japan.
 * Also whitelists specific IPs (e.g. updown.io monitoring nodes).
 *
 * If the country lookup fails (returns null or empty), $isAllowed will be set to 0.
 *
 * Usage:
 *   $remoteaddr = $_SERVER['REMOTE_ADDR'];
 *   $isAllowed = checkAllowedIP($remoteaddr);
 *   if ($isAllowed == 1) {
 *       // Allowed country - proceed with UserStack
 *   } else {
 *       // Not in allowed list or lookup failed - skip UserStack
 *   }
 */

/**
 * Check if an IP address is from an allowed country
 *
 * @param string $ip The IP address to check
 * @return int Returns 1 if allowed country, 0 if not
 */
function checkAllowedIP($ip) {
    // Validate IP address
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return 0;
    }

    // Whitelisted IPs bypass country check (updown.io monitoring nodes + web server)
    $whitelistedIPs = [
        '45.32.74.41',      // updown.io - Los Angeles, US
        '104.238.136.194',  // updown.io - Miami, US
        '192.99.37.47',     // updown.io - Montreal, Canada
        '91.121.222.175',   // updown.io - Roubaix, France
        '104.238.159.87',   // updown.io - Frankfurt, Germany
        '102.212.60.78',    // updown.io - Cape Town, South Africa
        '135.181.102.135',  // updown.io - Helsinki, Finland
        '45.32.107.181',    // updown.io - Singapore
        '45.76.104.117',    // updown.io - Tokyo, Japan
        '45.63.29.207',     // updown.io - Sydney, Australia
        '178.63.21.176',    // updown.io - Web server
        '81.103.25.79',     // RP home - always allow
    ];
    if (in_array($ip, $whitelistedIPs)) {
        return 1;
    }

    // Check cache first
    $cachedResult = getAllowedIPCache($ip);
    if ($cachedResult !== null) {
        return $cachedResult;
    }

    // Try to get country code
    $countryCode = getIPCountryCode($ip);

    // FAIL OPEN: a null/empty result means the ip-api lookup itself failed
    // (timeout, rate limit, DNS/network error, malformed JSON) -- NOT that the
    // visitor is from a disallowed country. Denying here turned every ip-api
    // outage into a site-wide 403 that monitoring could not see, because the
    // updown.io probe IPs are hard-whitelisted above and never reach this code.
    // Allow the visitor through and log it. Deliberately NOT cached, so a
    // transient failure cannot pin an IP open for the full 24h TTL.
    if ($countryCode === null || trim($countryCode) === '') {
        error_log("check_allowed_ip: geo lookup failed for {$ip} - failing OPEN");
        return 1;
    }

    // Lookup succeeded, so this is a real allow/deny decision on a known country
    $isAllowed = isCountryAllowed($countryCode) ? 1 : 0;

    // Cache the result
    saveAllowedIPCache($ip, $isAllowed);

    return $isAllowed;
}

/**
 * Check if country code is in allowed list
 *
 * @param string|null $countryCode Two-letter country code
 * @return bool True if allowed, false otherwise
 */
function isCountryAllowed($countryCode) {
    // Return false if country code is null or empty
    if ($countryCode === null || $countryCode === '' || trim($countryCode) === '') {
        return false;
    }

    // Allowed countries: US, UK, EU, CA, CH, AU, NO, NZ, IS, JP
    $allowedCountries = [
        'AT', // Austria
        'BE', // Belgium
        'BG', // Bulgaria
        'CZ', // Czech Republic
        'DE', // Germany
        'DK', // Denmark
        'EE', // Estonia
        'ES', // Spain
        'FI', // Finland
        'FR', // France
        'GB', // United Kingdom
        'GR', // Greece
        'HR', // Croatia
        'HU', // Hungary
        'IE', // Ireland
        'IT', // Italy
        'LT', // Lithuania
        'LU', // Luxembourg
        'LV', // Latvia
        'MT', // Malta
        'NL', // Netherlands
        'PL', // Poland
        'PT', // Portugal
        'RO', // Romania
        'SE', // Sweden
        'SI', // Slovenia
        'SK', // Slovakia
        'US', // United States
        'CA', // Canada
        'CH', // Switzerland
        'AU', // Australia
        'NO', // Norway
        'NZ', // New Zealand
        'IS', // Iceland
        'JP', // Japan
    ];

    return in_array(strtoupper($countryCode), $allowedCountries);
}

/**
 * Get country code from ip-api.com
 *
 * @param string $ip The IP address
 * @return string|null Country code or null
 */
function getIPCountryCode($ip) {
    try {
        $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['status']) && $data['status'] === 'success') {
                return $data['countryCode'] ?? null;
            }
        }
    } catch (Exception $e) {
        error_log("IP lookup failed: " . $e->getMessage());
    }

    return null;
}

/**
 * Get cached IP result
 *
 * @param string $ip The IP address
 * @return int|null Returns 1, 0, or null if not cached
 */
function getAllowedIPCache($ip) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        return null;
    }

    $cacheFile = $cacheDir . '/' . md5($ip) . '_allowed.cache';

    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);

        if ($cacheData && isset($cacheData['expires']) && $cacheData['expires'] > time()) {
            return (int)$cacheData['isAllowed'];
        }
    }

    return null;
}

/**
 * Save IP result to cache
 *
 * @param string $ip The IP address
 * @param int $isAllowed 1 for allowed, 0 for not allowed
 */
function saveAllowedIPCache($ip, $isAllowed) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/' . md5($ip) . '_allowed.cache';
    $cacheData = [
        'ip' => $ip,
        'isAllowed' => $isAllowed,
        'expires' => time() + 86400, // 24 hours
        'cached_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($cacheFile, json_encode($cacheData));
}

/**
 * Get list of allowed country codes
 *
 * @return array Array of allowed country codes
 */
function getAllowedCountries() {
    return [
        'AT', 'BE', 'BG', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GB', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'US', 'CA', 'CH',
        'AU', 'NO', 'NZ', 'IS', 'JP'
    ];
}
