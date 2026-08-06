<?php
// ================================================================
// tests/integration/usage_signal_db_test.php — migration_011 contra MariaDB
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// migration_011 se aplicará sobre una tabla que NADIE HA VISTO. No se puede
// demostrar desde el repo qué contiene `resource_usage` en producción:
// migration_000_prod_baseline.sql documenta que este proyecto ya sufrió
// deriva de esquema —columnas creadas a mano por SSH que ningún fichero de
// setup/ creaba—, así que setup/schema.sql prueba lo que alguien escribió en
// el repo, no lo que responde el servidor.
//
// Una migración que se aplica a ciegas sobre una tabla con datos puede fallar
// de dos formas, y la segunda es la mala:
//   · Falla y para          → ruidoso, se ve, se arregla.
//   · Aplica y ROMPE OTRA COSA → silencioso. El caso concreto aquí: un índice
//     UNIQUE mal diseñado que, de paso, impide forkear dos veces el mismo
//     recurso el mismo día. api/resources.php se rompería sin que nadie
//     tocara api/resources.php.
//
// Este fichero reproduce el escenario de producción —tabla con la forma de
// schema.sql y filas 'forked' dentro— y exige que tras la migración TODO lo
// que era legal siga siéndolo.
//
// ── SE LEE LA MIGRACIÓN REAL, NO UNA COPIA ────────────────────
// El SQL sale de setup/migration_011_usage_signal.sql y sólo se le cambia el
// nombre de la tabla. Una copia pegada aquí se desincroniza el primer día y
// el test pasaría a vigilar un SQL que ya nadie ejecuta — el fallo que este
// proyecto ya tuvo con el doble de la API en bootstrap.php.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

const IT_USAGE_PROBE = 'it_usage_probe';

/**
 * Sentencias de la migración real, apuntadas a la tabla de pruebas.
 *
 * Trocea igual que setup/run_migration.php:39-41 —quitar comentarios '--' y
 * explode(';')— para ejercitar exactamente el mismo camino que correrá el
 * mantenedor. Si la migración dejara de sobrevivir a ese troceado, se ve aquí.
 */
function it_usage_migration_statements(): array
{
    $raw = (string) file_get_contents(
        dirname(__DIR__, 2) . '/setup/migration_011_usage_signal.sql'
    );
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $raw);
    $sql = str_replace('resource_usage', IT_USAGE_PROBE, $sql);

    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

/** Crea la tabla TAL Y COMO ESTÁ EN PRODUCCIÓN: sin usage_day y sin UNIQUE. */
function it_usage_seed_prod_shape(PDO $db): void
{
    $t = IT_USAGE_PROBE;
    $db->exec("DROP TABLE IF EXISTS $t");
    $db->exec("CREATE TABLE $t (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        resource_id       INT NOT NULL,
        user_id           INT NOT NULL,
        tenant_id         INT NOT NULL,
        user_display_name VARCHAR(150),
        tenant_name       VARCHAR(150),
        usage_type        ENUM('presented','sent','forked','endorsed') NOT NULL,
        classroom_name    VARCHAR(100) NULL,
        notes             TEXT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_resource (resource_id),
        INDEX idx_user (tenant_id, user_id),
        INDEX idx_type (usage_type),
        INDEX idx_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function it_usage_apply(PDO $db): ?string
{
    foreach (it_usage_migration_statements() as $stmt) {
        try {
            $db->exec($stmt);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
    return null;
}

/** Inserta y devuelve el código de error de MariaDB (0 = fue bien). */
function it_usage_insert(PDO $db, int $res, int $user, int $tenant, string $type, ?string $day): int
{
    $t = IT_USAGE_PROBE;
    try {
        $db->prepare("INSERT INTO $t (resource_id, user_id, tenant_id, usage_type, usage_day)
                      VALUES (?, ?, ?, ?, ?)")
           ->execute([$res, $user, $tenant, $type, $day]);
        return 0;
    } catch (\PDOException $e) {
        return (int) ($e->errorInfo[1] ?? -1);
    }
}


// ── La migración se aplica sobre datos reales ───────────────────

function test_migration_011_se_aplica_sobre_la_forma_de_produccion(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_usage_seed_prod_shape($db);

    // Dos forks del MISMO recurso, MISMO usuario, MISMO día. Hoy es legal:
    // api/resources.php no pone ninguna restricción.
    $t = IT_USAGE_PROBE;
    $db->exec("INSERT INTO $t (resource_id, user_id, tenant_id, usage_type) VALUES (1, 7, 0, 'forked')");
    $db->exec("INSERT INTO $t (resource_id, user_id, tenant_id, usage_type) VALUES (1, 7, 0, 'forked')");

    $err = it_usage_apply($db);
    it_true($err === null, 'la migración se aplica sin error sobre una tabla con datos — ' . (string) $err);

    $n = (int) $db->query("SELECT COUNT(*) FROM $t WHERE usage_type = 'forked'")->fetchColumn();
    it_eq(2, $n, 'los forks que ya existían SOBREVIVEN a la migración');
}

function test_migration_011_deja_el_esquema_esperado(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_usage_seed_prod_shape($db);
    it_usage_apply($db);

    $t = IT_USAGE_PROBE;
    $col = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'
                         AND COLUMN_NAME = 'usage_day'")->fetch(PDO::FETCH_ASSOC);
    it_true((bool) $col, 'la columna usage_day existe tras la migración');
    it_eq('YES', $col['IS_NULLABLE'] ?? '', 'usage_day admite NULL — es lo que deja fuera de la dedup a forked/endorsed');

    // El ENUM tiene que aceptar 'presented'. Es el valor que el botón nuevo
    // empieza a escribir y el que NO estaba demostrado en producción: con
    // STRICT_TRANS_TABLES, un ENUM sin él aborta el INSERT con ERROR 1265 y
    // el botón falla siempre, en silencio y para todo el mundo.
    $enum = (string) $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'
                                   AND COLUMN_NAME = 'usage_type'")->fetchColumn();
    foreach (['presented', 'sent', 'forked', 'endorsed'] as $v) {
        it_true(str_contains($enum, "'$v'"), "el ENUM usage_type acepta '$v' — $enum");
    }

    $idx = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'
                         AND INDEX_NAME = 'uniq_usage_signal' AND NON_UNIQUE = 0")->fetchColumn();
    it_eq(5, (int) $idx, 'uniq_usage_signal existe, es UNIQUE y cubre las 5 columnas del contrato');
}

function test_migration_011_es_idempotente(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_usage_seed_prod_shape($db);
    it_usage_apply($db);

    $t = IT_USAGE_PROBE;
    $db->exec("INSERT INTO $t (resource_id, user_id, tenant_id, usage_type, usage_day)
               VALUES (1, 7, 0, 'presented', CURDATE())");

    // No hay tabla de migraciones aplicadas en este proyecto: las corre un
    // humano por SSH y puede repetirlas. Reejecutar tiene que ser inocuo.
    $err = it_usage_apply($db);
    it_true($err === null, 'reejecutar la migración no falla — ' . (string) $err);

    $n = (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    it_eq(1, $n, 'y no altera ni una fila');
}


// ── El comportamiento que la señal necesita ─────────────────────

function test_presented_solo_cuenta_una_vez_por_profesor_y_dia(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_usage_seed_prod_shape($db);
    it_usage_apply($db);

    $hoy = date('Y-m-d');
    it_eq(0, it_usage_insert($db, 1, 7, 0, 'presented', $hoy), 'el primer registro del día entra');
    it_eq(1062, it_usage_insert($db, 1, 7, 0, 'presented', $hoy),
        'el segundo choca con uniq_usage_signal (1062). Sin esto, un profesor '
        . 'pulsando cinco veces valdría cinco usos y la métrica nacería inflable '
        . '— el mismo defecto por el que se descartaron las estrellas.');

    it_eq(0, it_usage_insert($db, 1, 7, 0, 'presented', '2020-01-01'),
        'OTRO día sí entra: la dedup es diaria, no permanente. Usar el mismo '
        . 'recurso en dos clases distintas son dos usos de verdad.');

    it_eq(0, it_usage_insert($db, 1, 7, 9, 'presented', $hoy),
        'MISMO user_id en OTRO tenant entra: los user_id vienen de Campus y '
        . 'sólo son únicos dentro de su colegio. Sin tenant_id en la clave, el '
        . 'usuario 7 del colegio A bloquearía al usuario 7 del colegio B.');
}

function test_forkear_dos_veces_el_mismo_dia_sigue_siendo_legal(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_usage_seed_prod_shape($db);
    it_usage_apply($db);

    // api/resources.php inserta los forks SIN mencionar usage_day, así que
    // llegan con NULL. En InnoDB un UNIQUE admite múltiples NULL, y de ahí sale
    // la exención sin escribir ninguna excepción.
    it_eq(0, it_usage_insert($db, 1, 7, 0, 'forked', null), 'primer fork');
    it_eq(0, it_usage_insert($db, 1, 7, 0, 'forked', null),
        'segundo fork el mismo día: NO debe chocar. Ésta es la regresión que '
        . 'un índice UNIQUE mal planteado habría introducido en api/resources.php '
        . 'sin tocar api/resources.php.');
    it_eq(0, it_usage_insert($db, 1, 7, 0, 'forked', null), 'y un tercero tampoco');
}

function test_endorsed_queda_fuera_del_indice_diario(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_usage_seed_prod_shape($db);
    it_usage_apply($db);

    // 'endorsed' se deduplica PARA SIEMPRE, y eso lo decide el chequeo de
    // api/usage.php, no este índice: un UNIQUE diario lo debilitaría dejando
    // volver a endosar mañana. Por eso viaja con usage_day NULL y la BD no lo
    // frena. El test fija ese reparto para que nadie "complete" el índice
    // creyendo que arregla un olvido.
    it_eq(0, it_usage_insert($db, 1, 7, 0, 'endorsed', null), 'primer endorse');
    it_eq(0, it_usage_insert($db, 1, 7, 0, 'endorsed', null),
        'la BD no frena el segundo endorse: quien deduplica es el SELECT de '
        . 'api/usage.php, a propósito y para siempre');
}

function test_el_insert_de_la_api_cabe_en_el_esquema_migrado(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_usage_seed_prod_shape($db);
    it_usage_apply($db);

    // Se ejecuta el INSERT REAL extraído de api/usage.php, no una paráfrasis:
    // si a alguien se le va una columna de la lista, se ve aquí y no en
    // producción.
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/api/usage.php');
    $ok  = preg_match('/(INSERT INTO resource_usage\s*\([^)]*\)\s*VALUES\s*\([^)]*\))/s', $src, $m);
    it_true((bool) $ok, 'se pudo extraer el INSERT de api/usage.php');

    $probe = str_replace('resource_usage', IT_USAGE_PROBE, $m[1]);
    $err = null;
    try {
        $db->prepare($probe)->execute([1, 7, 0, 'Ana', 'Colegio', 'presented', null, date('Y-m-d')]);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    it_true($err === null, 'el INSERT real de la API entra en el esquema migrado — ' . (string) $err);

    $row = $db->query("SELECT usage_type, usage_day FROM " . IT_USAGE_PROBE)->fetch(PDO::FETCH_ASSOC);
    it_eq('presented', $row['usage_type'] ?? '', 'se guardó el tipo correcto');
    it_eq(date('Y-m-d'), $row['usage_day'] ?? '', 'y usage_day quedó relleno: la dedup está activa');
}
