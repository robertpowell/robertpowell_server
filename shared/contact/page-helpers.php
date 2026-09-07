<?php
declare(strict_types=1);
/* Shared entry logic for a site's contact.php. Call contact_entry('<site key>') and it either
   answers a POST with JSON and exits, or returns the token for the GET render. */
require_once __DIR__ . '/contact-lib.php';

function contact_entry(string $site): string {
    Contact\boot($site);
    header('Cache-Control: no-store');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST' && isset($_POST['qr'])) {
        return Contact\token_issue();        // QR reveal: contact.php calls hc_qr_serve_if_requested() next, which answers and exits
    }
    if ($method === 'POST') {
        [$status, $payload] = Contact\handle($_POST);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405); header('Allow: GET, HEAD, POST'); exit;
    }
    return Contact\token_issue();
}

function render_fragment(string $file, array $vars): string {
    extract($vars, EXTR_SKIP);
    ob_start(); include $file; return (string)ob_get_clean();
}
