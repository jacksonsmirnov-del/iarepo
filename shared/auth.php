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
 * ¿Es una petición de ESCRITURA que viene de otra web? (CSRF)
 *
 * La cookie de sesión viaja también cuando otra web manda un formulario a
 * iarepo, y con ella un POST ajeno actuaba en nombre de quien tuviera la
 * sesión abierta: borrar sus recursos (request_method() acepta _method=DELETE
 * desde un formulario), tocar sus listas o cambiarle el rol, del que depende
 * que el perfil de un menor sea público [revisión 2026-09].
 *
 * Se decide con lo que el NAVEGADOR pone y una web no puede falsificar:
 *   · Sec-Fetch-Site (Chrome 76+, Firefox 90+, Safari 16.4+): solo
 *     'same-origin' (o 'none', lo que teclea la persona) es de aquí.
 *   · Si no está, Origin (todos los navegadores lo mandan en un POST ajeno):
 *     su host tiene que ser el nuestro. 'null' (iframe aislado) es ajeno.
 *   · Sin ninguna de las dos no es un navegador (curl, un servidor) y ahí no
 *     hay cookie de nadie que robar: se deja pasar.
 * GET/HEAD/OPTIONS nunca escriben, así que no se miran.
 */
function iarepo_is_cross_site_write(): bool {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true))
        return false;

    $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site !== '')
        return !in_array($site, ['same-origin', 'none'], true);

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '')
        return false;
    $o     = parse_url($origin);
    $oHost = strtolower((string) ($o['host'] ?? '')) . (isset($o['port']) ? ':' . (int) $o['port'] : '');
    $host  = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    // El puerto por defecto puede venir o no en Host (según el proxy): no cuenta.
    $host  = (string) preg_replace('/:(80|443)$/', '', $host);
    $oHost = (string) preg_replace('/:(80|443)$/', '', $oHost);
    return $oHost === '' || $host === '' || $oHost !== $host;
}

/**
 * Token anti-CSRF de los formularios HTML que cambian algo con la sesión
 * (hoy, el rol en auth/onboarding.php, que también usa profile/index.php).
 * Uno por sesión; la página lo pone en un <input type="hidden" name="csrf">.
 */
function iarepo_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    if (!is_string($_SESSION['csrf'] ?? null) || strlen($_SESSION['csrf']) < 32)
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

/** ¿El token recibido es el de esta sesión? (comparación en tiempo constante) */
function iarepo_csrf_valid(mixed $token): bool {
    $mine = $_SESSION['csrf'] ?? null;
    return is_string($token) && is_string($mine) && $mine !== '' && hash_equals($mine, $token);
}

/**
 * Authenticate via PHP session (Google Sign-In users).
 *
 * Una escritura que llega de otra web NO se autentica con la sesión (ver
 * iarepo_is_cross_site_write): para la API es anónima → 401 en lo que pide
 * cuenta. Campus no se ve afectado: entra por JWT (authenticateJWT), no por
 * cookie.
 */
function authenticateSession(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user']['id']))
        return null;

    if (iarepo_is_cross_site_write()) {
        // Se registra: si algún día una escritura legítima cae aquí, se ve.
        error_log('iarepo: sesión ignorada en una escritura de otra web (CSRF) — '
            . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?')
            . ' · Sec-Fetch-Site=' . substr((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '-'), 0, 20)
            . ' · Origin=' . substr((string) ($_SERVER['HTTP_ORIGIN'] ?? '-'), 0, 80));
        return null;
    }

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
 * ¿Es una cuenta de iarepo.com (sesión de Google)?
 *
 * Solo esas tienen fila en `users`, y solo en ellas user_id ES users.id. Un
 * token de Campus trae el user_id de la numeración de Campus: el docente 5 de
 * Campus NO es la cuenta 5 de iarepo, aunque el número coincida. Todo lo que
 * se guarda o se lee por users.id —guardados, «Me gusta», listas,
 * comentarios, novedades— lo exige (requireSiteAccount). Hasta 2026-09-27 no
 * se miraba, y un token de Campus leía y tocaba lo de la cuenta de iarepo con
 * su mismo número.
 */
function iarepo_is_site_account(?array $user): bool {
    return $user !== null && ($user['source'] ?? '') === 'google' && (int) ($user['user_id'] ?? 0) > 0;
}

/**
 * Como requireAuth(), pero solo para cuentas de iarepo.com. Con un token de
 * Campus: 403 y código SITE_ACCOUNT_REQUIRED (la API decide por el código).
 * Antirregresión: tests/integration/usage_notify_test.php.
 */
function requireSiteAccount(): array {
    $user = requireAuth();
    if (!iarepo_is_site_account($user)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['ok' => false, 'error' => 'This needs an iarepo.com account',
                         'code' => 'SITE_ACCOUNT_REQUIRED']));
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
