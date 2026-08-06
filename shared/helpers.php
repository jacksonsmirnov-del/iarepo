<?php
// ================================================================
// shared/helpers.php — Common Utility Functions
//
// Enhanced with:
//   - Error codes for machine-readable API errors
//   - Request ID correlation
//   - Structured logging via api_log()
// ================================================================

// Load error handler (auto-registers exception/error handlers)
require_once __DIR__ . '/error_handler.php';

/**
 * HTML-escape a string for safe output.
 */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Send a success JSON response and exit.
 *
 * @param array $data  Additional data to include
 */
function json_ok(array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['ok' => true, 'request_id' => request_id()] + $data;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send an error JSON response and exit.
 *
 * Produces structured errors:
 *   {"ok": false, "error": "Human message", "code": "ERROR_CODE", "request_id": "abc123"}
 *
 * @param string $msg       Human-readable error message
 * @param int    $httpCode  HTTP status code (default: 400)
 * @param string $errorCode Machine-readable error code (default: auto-generated from HTTP code)
 */
function json_error(string $msg, int $httpCode = 400, string $errorCode = ''): never {
    // Auto-generate error code from HTTP status if not provided
    if (!$errorCode) {
        $errorCode = match ($httpCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            429 => 'RATE_LIMITED',
            500 => 'INTERNAL_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'ERROR',
        };
    }

    // Log server errors (5xx) automatically
    if ($httpCode >= 500) {
        api_log('error', $msg, ['http_code' => $httpCode, 'error_code' => $errorCode]);
    }

    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'         => false,
        'error'      => $msg,
        'code'       => $errorCode,
        'request_id' => request_id(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get JSON body from POST/PUT request.
 *
 * @return array  Decoded JSON body
 */
function json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Invalid JSON body', 400, 'INVALID_JSON');
    return $data;
}

/**
 * Get the HTTP method, supporting _method override for forms.
 */
function request_method(): string {
    return strtoupper($_POST['_method'] ?? $_SERVER['REQUEST_METHOD']);
}

/**
 * Sanitize a string for safe storage: trim + limit length.
 */
function sanitize(string $input, int $maxLen = 255): string {
    return mb_substr(trim($input), 0, $maxLen);
}

/**
 * Get the real client IP, respecting X-Forwarded-For (Hostinger proxy).
 */
function clientIp(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded) {
        return trim(explode(',', $forwarded)[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * IP-based rate limiter. Exits with 429 if limit is exceeded.
 *
 * Uses api_rate_limits table. Window resets atomically via ON DUPLICATE KEY UPDATE.
 * Cleans up expired rows with 1% probability to avoid a dedicated cron task.
 *
 * @param PDO    $db         DB connection
 * @param string $endpoint   Short key identifying the endpoint (e.g. 'resources_get')
 * @param int    $limit      Max requests allowed in the window
 * @param int    $windowSecs Window size in seconds (default: 60)
 */
function rateLimit(PDO $db, string $endpoint, int $limit, int $windowSecs = 60): void {
    $ip = clientIp();

    // Atomic upsert: reset counter if window expired, otherwise increment
    $db->prepare("
        INSERT INTO api_rate_limits (ip, endpoint, requests, window_start)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            requests     = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, requests + 1),
            window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), window_start)
    ")->execute([$ip, $endpoint, $windowSecs, $windowSecs]);

    $stmt = $db->prepare("SELECT requests FROM api_rate_limits WHERE ip = ? AND endpoint = ?");
    $stmt->execute([$ip, $endpoint]);
    $count = (int) $stmt->fetchColumn();

    if ($count > $limit) {
        http_response_code(429);
        header('Retry-After: ' . $windowSecs);
        json_error('Too many requests. Please slow down.', 429, 'RATE_LIMITED');
    }

    // ── Purga de filas caducadas ─────────────────────────────
    //
    // Era `if (random_int(1, 100) === 1)`. El sorteo bastaba para el objetivo
    // original —que la tabla no engorde— pero no para el que importa ahora:
    // esta tabla guarda **direcciones IP en claro**, y desde 2026-08-06
    // legal/terms.php §10.3 promete que esos registros "se borran
    // automáticamente". Una promesa de retención no puede depender de un dado.
    //
    // Con poco tráfico el sorteo puede tardar en salir, y entonces la
    // retención real la decide el volumen de visitas en vez del código.
    // (Medido en producción el 2026-08-06: 4 filas, la más antigua de hacía 22
    // minutos — el sorteo funcionaba razonablemente. El cambio es para que
    // deje de depender de eso.)
    //
    // ── POR QUÉ INCONDICIONAL Y NO UN SORTEO MÁS PROBABLE ────
    // La tabla tiene una fila por (IP, endpoint) activa: unidades, no miles.
    // Con `idx_window` el DELETE es un rango indexado sobre una tabla diminuta,
    // así que el coste que el sorteo evitaba no existe a esta escala. Es la
    // misma decisión que en iarepo_daily_salt(): purga determinista, porque de
    // ella depende una promesa de privacidad y no sólo el tamaño de una tabla.
    $db->prepare("DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)")
       ->execute([$windowSecs * 2]);
}
