<?php
// ================================================================
// shared/error_handler.php — Professional Error Handling
//
// Provides:
//   - Global exception handler (converts PHP errors to JSON)
//   - Structured error logging with request IDs
//   - Debug mode toggle (stack traces in dev, clean errors in prod)
//
// Usage:
//   require_once __DIR__ . '/error_handler.php';
//   // Auto-registers handlers on require
//
// All API endpoints should require this file.
// ================================================================

// ── Request ID ────────────────────────────────────────────────
// Unique per-request identifier for log correlation.
// Campus can read this from the response to trace errors.

function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = substr(bin2hex(random_bytes(8)), 0, 12);
    }
    return $id;
}

// ── Debug mode ────────────────────────────────────────────────

function is_debug(): bool
{
    static $debug = null;
    if ($debug === null) {
        $env = @include dirname(__DIR__) . '/.env.php';
        $debug = (bool) ($env['DEBUG'] ?? false);
    }
    return $debug;
}

// ── Logging ───────────────────────────────────────────────────

/**
 * Log a structured message to the error log.
 *
 * @param string $level   'error', 'warn', 'info'
 * @param string $message Human-readable message
 * @param array  $context Additional data (user_id, endpoint, etc.)
 */
function api_log(string $level, string $message, array $context = []): void
{
    $entry = [
        'time'       => date('c'),
        'level'      => $level,
        'request_id' => request_id(),
        'message'    => $message,
        'method'     => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'uri'        => $_SERVER['REQUEST_URI'] ?? '',
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    if ($context) {
        $entry['context'] = $context;
    }

    error_log('[iarepo] ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ── Exception Handler ─────────────────────────────────────────

/**
 * Global exception handler.
 * Converts uncaught exceptions to clean JSON error responses.
 */
function iarepo_exception_handler(\Throwable $e): void
{
    // Determine HTTP status code
    $code = 500;
    if ($e instanceof \InvalidArgumentException) {
        $code = 400;
    }

    // Log the full error
    api_log('error', $e->getMessage(), [
        'exception' => get_class($e),
        'file'      => $e->getFile() . ':' . $e->getLine(),
        'trace'     => is_debug() ? $e->getTraceAsString() : null,
    ]);

    // Send clean response
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }

    $response = [
        'ok'         => false,
        'error'      => is_debug()
            ? $e->getMessage()
            : 'Internal server error',
        'code'       => 'INTERNAL_ERROR',
        'request_id' => request_id(),
    ];

    if (is_debug()) {
        $response['debug'] = [
            'exception' => get_class($e),
            'file'      => $e->getFile() . ':' . $e->getLine(),
            'trace'     => explode("\n", $e->getTraceAsString()),
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit(1);
}

// ── Error Handler ─────────────────────────────────────────────

/**
 * Converts PHP warnings/notices into logged entries.
 * In strict mode, converts to exceptions. Otherwise, logs silently.
 */
function iarepo_error_handler(int $severity, string $message, string $file, int $line): bool
{
    // Don't handle suppressed errors (@operator)
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $levelMap = [
        E_WARNING         => 'warn',
        E_NOTICE          => 'info',
        E_USER_WARNING    => 'warn',
        E_USER_NOTICE     => 'info',
        E_USER_ERROR      => 'error',
        E_DEPRECATED      => 'info',
        E_USER_DEPRECATED => 'info',
        E_STRICT          => 'info',
    ];

    $level = $levelMap[$severity] ?? 'error';

    // Fatal-level errors: convert to exception
    if ($severity === E_USER_ERROR || $severity === E_ERROR || $severity === E_RECOVERABLE_ERROR) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    // Non-fatal: log and continue
    api_log($level, "PHP {$level}: {$message}", [
        'file' => "{$file}:{$line}",
    ]);

    return true; // Don't execute PHP's internal handler
}

// ── Shutdown Handler ──────────────────────────────────────────

/**
 * Catches fatal errors that bypass set_error_handler.
 */
function iarepo_shutdown_handler(): void
{
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        api_log('error', 'Fatal: ' . $error['message'], [
            'file' => $error['file'] . ':' . $error['line'],
            'type' => $error['type'],
        ]);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'         => false,
                'error'      => is_debug() ? $error['message'] : 'Internal server error',
                'code'       => 'FATAL_ERROR',
                'request_id' => request_id(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}

// ── Auto-register handlers on require ─────────────────────────

set_exception_handler('iarepo_exception_handler');
set_error_handler('iarepo_error_handler');
register_shutdown_function('iarepo_shutdown_handler');

// Ensure JSON errors don't leak HTML
ini_set('display_errors', '0');
ini_set('log_errors', '1');
