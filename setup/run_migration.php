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

// ── Cinturón: ¿esta BD es la de iarepo? ───────────────────────
//
// La ruta normal ya es segura por construcción: el `require __DIR__` de arriba
// lee el .env.php que está JUNTO a este script, no el del directorio donde te
// encuentres, así que invocar este runner usa siempre las credenciales de
// iarepo. Verificado en el servidor [2026-08-06]: desde el doc root de Campus,
// `php setup/run_migration.php` responde "Could not open input file" — Campus
// no tiene este script.
//
// Lo que este cinturón cubre es lo OTRO: que el .env.php de al lado apunte a
// una base de datos que no es la de iarepo. Pasa si alguien copia el doc root
// para hacer un staging y se deja el .env.php del original, o al revés. Ahí no
// hay ningún error — las sentencias se aplican, sin más, contra la BD
// equivocada.
//
// La comprobación es la misma idea que ya usa setup/tools/backup_db.sh, que
// aborta si DB_NAME no parece la de iarepo. Se mira la ESTRUCTURA y no el
// nombre, porque el nombre de la BD puede cambiar legítimamente (restauración,
// migración de hosting) y la estructura no.
$señas = ['resources', 'categories', 'resource_tags'];
$faltan = [];
foreach ($señas as $t) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$t]);
    if (!$q->fetchColumn()) $faltan[] = $t;
}

// Se permite la base VACÍA (reconstrucción desde cero: schema.sql aún no ha
// corrido). Lo que se rechaza es una base CON CONTENIDO que no es el de iarepo
// — ahí es donde el error sería silencioso y caro.
$total = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();

if ($faltan && $total > 0) {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    fwrite(STDERR,
        "❌ ABORTO: la BD '{$db}' tiene {$total} tabla(s) pero le faltan señas de iarepo ("
        . implode(', ', $faltan) . ").\n"
        . "   Este script no toca otras bases de datos. Comprueba que estás en el doc root\n"
        . "   de iarepo — el único con deploy_version.txt (docs/RUNBOOK.md §4.1).\n");
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
