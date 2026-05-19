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
