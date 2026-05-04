<?php
// ================================================================
// shared/helpers.php — Common Utility Functions
// ================================================================

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
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send an error JSON response and exit.
 *
 * @param string $msg   Error message
 * @param int    $code  HTTP status code
 */
function json_error(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
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
    if (!is_array($data)) json_error('Invalid JSON body');
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
