<?php
// ================================================================
// shared/cors.php — CORS Handler
//
// Call cors() at the top of every API endpoint.
// Handles preflight OPTIONS requests and sets proper headers.
//
// Supports:
//   - Any *.claseprivada.com subdomain (multi-tenant Campus)
//   - iarepo.com (main domain)
//   - Custom origins from .env.php
// ================================================================

/**
 * Handle CORS headers and preflight requests.
 * Call this at the very top of API endpoints.
 */
function cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Fallback: try Referer if Origin is empty (some servers strip Origin on OPTIONS)
    if (!$origin && !empty($_SERVER['HTTP_REFERER'])) {
        $parsed = parse_url($_SERVER['HTTP_REFERER']);
        if ($parsed) {
            $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        }
    }

    // Default allowed origins
    $allowed = [
        'https://claseprivada.com',
        'https://staging.claseprivada.com',
        'https://resources.claseprivada.com',
        'https://iarepo.com',
        'https://www.iarepo.com',
    ];

    // Also allow from .env if configured
    $env = require dirname(__DIR__) . '/.env.php';
    if (!empty($env['ALLOWED_ORIGINS'])) {
        $allowed = array_merge($allowed, $env['ALLOWED_ORIGINS']);
    }

    // Allow any subdomain of claseprivada.com (multi-tenant support)
    $isAllowed = in_array($origin, $allowed, true)
              || preg_match('#^https://[a-z0-9-]+\.claseprivada\.com$#', $origin);

    if ($isAllowed && $origin) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');

    // Handle preflight — always respond with origin if we can detect it
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        // If origin wasn't detected (LiteSpeed strips it), allow all claseprivada subdomains
        if (!$origin || !$isAllowed) {
            header('Access-Control-Allow-Origin: *');
            // Note: can't use credentials with *, but preflight just needs the 200 + headers
            header_remove('Access-Control-Allow-Credentials');
        }
        http_response_code(200);
        exit;
    }
}
