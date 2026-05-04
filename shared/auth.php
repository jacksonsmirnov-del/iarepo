<?php
// ================================================================
// shared/auth.php — Authentication Middleware
//
// Supports TWO auth sources:
//   1. JWT Bearer token (from Campus)
//   2. PHP Session (from Google Sign-In)
//
// Both produce a normalized user array with:
//   [user_id, name, role, tenant_id, tenant_name, source]
// ================================================================

require_once __DIR__ . '/jwt.php';

/**
 * Try to authenticate the current request.
 * Checks JWT first, then session.
 *
 * @return array|null  User info, or null
 */
function authenticate(): ?array {
    // 1. Try JWT (Campus users)
    $jwtUser = authenticateJWT();
    if ($jwtUser) return $jwtUser;

    // 2. Try Session (Google users)
    return authenticateSession();
}

/**
 * Authenticate via JWT Bearer token (Campus).
 */
function authenticateJWT(): ?array {
    $env = require dirname(__DIR__) . '/.env.php';

    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    $token = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        $token = $m[1];
    } elseif (!empty($_GET['token'])) {
        $token = $_GET['token'];
    }

    if (!$token) return null;

    $payload = jwt_decode($token, $env['JWT_SECRET']);
    if (!$payload) return null;

    // Normalize to common format
    return [
        'user_id'     => (int) ($payload['user_id'] ?? 0),
        'name'        => $payload['name'] ?? '',
        'role'        => $payload['role'] ?? 'teacher',
        'tenant_id'   => (int) ($payload['tenant_id'] ?? 0),
        'tenant_name' => $payload['tenant_name'] ?? '',
        'areas'       => $payload['areas'] ?? [],
        'source'      => 'campus',
    ];
}

/**
 * Authenticate via PHP session (Google Sign-In users).
 */
function authenticateSession(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user']['id']))
        return null;

    $u = $_SESSION['user'];
    return [
        'user_id'     => (int) $u['id'],
        'name'        => $u['name'] ?? '',
        'role'        => $u['role'] ?? 'teacher',
        'tenant_id'   => 0,  // External user — no tenant
        'tenant_name' => '',
        'areas'       => [],
        'email'       => $u['email'] ?? '',
        'avatar_url'  => $u['avatar_url'] ?? '',
        'source'      => 'google',
    ];
}

/**
 * Require authentication. Returns user info or dies with 401.
 *
 * @return array  User info
 */
function requireAuth(): array {
    $user = authenticate();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode(['ok' => false, 'error' => 'Unauthorized — invalid or missing token']));
    }
    return $user;
}

/**
 * Require a specific role. Dies with 403 if role doesn't match.
 *
 * @param array $user           User info from requireAuth()
 * @param array $allowedRoles   e.g. ['teacher', 'admin', 'superadmin']
 */
function requireRole(array $user, array $allowedRoles): void {
    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['ok' => false, 'error' => 'Forbidden — insufficient role']));
    }
}

/**
 * Get the current session user (for pages, not API).
 * Returns null if not logged in.
 */
function getSessionUser(): ?array {
    if (session_status() === PHP_SESSION_NONE)
        session_start();

    return $_SESSION['user'] ?? null;
}
