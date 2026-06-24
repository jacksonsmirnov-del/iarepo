<?php
// ================================================================
// setup/run_migration.php — Minimal migration runner (CLI only)
//
// Patrón de migraciones 7/8: se corre EN EL SERVIDOR por SSH, leyendo
// las credenciales de la DB desde el .env.php del doc root (no en git).
//
// Uso (desde el doc root, donde vive .env.php):
//   php setup/run_migration.php setup/migration_009_student_role_favorites.sql
//
// Divide el archivo en sentencias por ';' y las ejecuta en orden.
// ================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$file = $argv[1] ?? '';
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php setup/run_migration.php <file.sql>\n");
    exit(1);
}

$env = require __DIR__ . '/../.env.php';

try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'],
        $env['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\PDOException $e) {
    fwrite(STDERR, "DB connection failed: {$e->getMessage()}\n");
    exit(1);
}

// Quita comentarios de línea (-- ...) y separa por ';'.
$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $stmt) {
    $preview = substr(preg_replace('/\s+/', ' ', $stmt), 0, 72);
    echo "▶ {$preview}...\n";
    $pdo->exec($stmt);
}

echo "✅ Migración aplicada: {$file}\n";
