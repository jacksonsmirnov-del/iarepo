<?php
// ================================================================
// tests/integration/cron_heartbeat_test.php — El latido tiene que ESCRIBIR
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// El latido de cron/run.php estuvo desplegado y NO escribía nada. Los jobs
// respondían {"ok":true}, la tabla existía, los permisos eran correctos… y
// `run_count` se quedaba en 0. El INSERT moría con:
//
//   1267 Illegal mix of collations
//        (utf8mb4_general_ci,COERCIBLE) and (utf8mb4_unicode_ci,COERCIBLE)
//        for operation '='
//
// porque el SQL comparaba un parámetro contra un literal — IF(? = 'ok', …) —
// y el parámetro llega con la collation de la CONEXIÓN, mientras la tabla es
// utf8mb4_unicode_ci. El try/catch (mudo a propósito, para que un fallo de
// telemetría no tumbe el job) se lo tragaba entero.
//
// La ironía es el motivo de este fichero: los latidos existen para que un
// cron muerto no pase desapercibido, y el propio latido estuvo muerto sin
// que nada lo dijera. Sólo se destapó redirigiendo error_log a stderr.
//
// ── QUÉ VIGILA ────────────────────────────────────────────────
// Ejecuta el MISMO INSERT que cron/run.php contra una tabla creada con la
// collation de producción, y con la conexión declarando otra distinta —es
// decir, reproduce el desajuste exacto— y exige que la fila quede escrita.
// Si alguien vuelve a meter una comparación de texto en ese SQL, esto se
// pone rojo antes de llegar a producción.
// ================================================================

/**
 * Extrae el INSERT del latido del propio cron/run.php.
 *
 * Se lee del fichero real en vez de copiarlo aquí: una copia se queda
 * desincronizada el primer día y el test pasaría a vigilar un SQL que ya
 * nadie ejecuta — que es justo el fallo que este proyecto ya tuvo con el
 * doble de la API en bootstrap.php.
 */
function it_hb_sql(): string
{
    $src = file_get_contents(dirname(__DIR__, 2) . '/cron/run.php');
    if ($src === false) return '';
    if (!preg_match('/\$sql\s*=\s*"(INSERT INTO cron_heartbeats.*?)";/s', $src, $m)) return '';
    return $m[1];
}

function test_cron_heartbeat_sql_no_compara_texto(): void
{
    $sql = it_hb_sql();
    it_true($sql !== '', 'se pudo extraer el INSERT del latido de cron/run.php');

    // La forma exacta del fallo: un placeholder comparado contra un literal
    // de texto. Con enteros no hay collation que mezclar.
    it_true(
        !preg_match('/\?\s*=\s*\'/', $sql),
        "el INSERT del latido no compara un parámetro contra un literal de texto "
        . "(IF(? = 'ok', …) reventaba con 1267 Illegal mix of collations en producción)"
    );
}

function test_cron_heartbeat_escribe_con_collations_distintas(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    $sql = it_hb_sql();
    it_true($sql !== '', 'se pudo extraer el INSERT del latido de cron/run.php');

    // Tabla con la collation de producción…
    $db->exec('DROP TABLE IF EXISTS it_cron_heartbeats_probe');
    $db->exec("CREATE TABLE it_cron_heartbeats_probe (
        job              VARCHAR(64)  NOT NULL PRIMARY KEY,
        last_run_at      DATETIME     NULL,
        duration_ms      INT UNSIGNED NOT NULL DEFAULT 0,
        items_processed  INT UNSIGNED NOT NULL DEFAULT 0,
        status           ENUM('ok','error') NOT NULL DEFAULT 'ok',
        message          VARCHAR(500) NULL,
        last_ok_at       DATETIME     NULL,
        last_error_at    DATETIME     NULL,
        run_count        INT UNSIGNED NOT NULL DEFAULT 0,
        error_count      INT UNSIGNED NOT NULL DEFAULT 0,
        period_seconds   INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // …y la conexión hablando OTRA. Éste es el desajuste que había en
    // producción y que ninguna prueba local reproducía.
    $db->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

    $probe = str_replace('cron_heartbeats', 'it_cron_heartbeats_probe', $sql);

    $err = null;
    try {
        $db->prepare($probe)->execute(['moderation', 12, 3, 'ok', 'prueba', 1, 0, 0, 120]);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    it_true($err === null, 'el INSERT del latido no lanza con collations distintas — ' . (string) $err);

    $row = $db->query("SELECT run_count, last_run_at, last_ok_at, status
                       FROM it_cron_heartbeats_probe WHERE job = 'moderation'")->fetch(PDO::FETCH_ASSOC);
    it_true(is_array($row), 'la fila del latido existe tras el INSERT');
    it_eq('1', (string) $row['run_count'], 'run_count queda en 1 tras el primer latido');
    it_true($row['last_run_at'] !== null, 'last_run_at queda informado');
    it_true($row['last_ok_at']  !== null, 'last_ok_at queda informado cuando el job va bien');

    // Segunda pasada: ON DUPLICATE KEY UPDATE tiene que acumular, no duplicar.
    $db->prepare($probe)->execute(['moderation', 5, 1, 'error', 'falló', 0, 1, 1, 120]);
    $row = $db->query("SELECT run_count, error_count, status, last_ok_at, last_error_at
                       FROM it_cron_heartbeats_probe WHERE job = 'moderation'")->fetch(PDO::FETCH_ASSOC);
    it_eq('2', (string) $row['run_count'],   'run_count acumula en la segunda ejecución');
    it_eq('1', (string) $row['error_count'], 'error_count sube solo cuando el job falla');
    it_eq('error', (string) $row['status'],  'status refleja la última ejecución');
    it_true($row['last_ok_at'] !== null, 'last_ok_at NO se borra tras un fallo posterior');
    it_true($row['last_error_at'] !== null, 'last_error_at queda informado tras el fallo');

    $db->exec('DROP TABLE IF EXISTS it_cron_heartbeats_probe');
}
