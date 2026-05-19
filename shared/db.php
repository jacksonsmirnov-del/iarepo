<?php
// ================================================================
// shared/db.php — Database Connection (PDO)
//
// Single connection to the Resources Platform DB.
// This DB is completely independent from Campus.
//
// Enhanced with:
//   - Graceful error handling (returns 503 instead of crashing)
//   - Connection logging for diagnostics
// ================================================================

/**
 * Get the Resources Platform database connection.
 *
 * @return PDO
 */
function getResourcesDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $env = require dirname(__DIR__) . '/.env.php';

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'],
        $env['DB_NAME']
    );

    try {
        $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } catch (\PDOException $e) {
        // Log the real error but don't expose credentials
        if (function_exists('api_log')) {
            api_log('error', 'Database connection failed', [
                'host'  => $env['DB_HOST'],
                'db'    => $env['DB_NAME'],
                'error' => $e->getMessage(),
            ]);
        } else {
            error_log('[iarepo] DB connection failed: ' . $e->getMessage());
        }

        // Return clean error to client
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        $requestId = function_exists('request_id') ? request_id() : 'unknown';
        echo json_encode([
            'ok'         => false,
            'error'      => 'Service temporarily unavailable — database connection failed',
            'code'       => 'DB_UNAVAILABLE',
            'request_id' => $requestId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $pdo;
}
