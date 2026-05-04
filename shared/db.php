<?php
// ================================================================
// shared/db.php — Database Connection (PDO)
//
// Single connection to the Resources Platform DB.
// This DB is completely independent from Campus.
// ================================================================

/**
 * Get the Resources Platform database connection.
 *
 * @return PDO
 * @throws PDOException on connection failure
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

    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
