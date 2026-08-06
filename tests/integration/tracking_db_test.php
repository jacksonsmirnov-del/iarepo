<?php
// ================================================================
// tests/integration/tracking_db_test.php — migration_012 contra MariaDB
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// Todo el arreglo de las visitas se sostiene sobre UNA propiedad del motor:
// que con `INSERT … ON DUPLICATE KEY UPDATE`, rowCount() valga 1 al insertar y
// 2 al actualizar. api/track.php incrementa `unique_views` sólo cuando vale 1.
//
// Si esa suposición fuera falsa —u otra versión de MariaDB la cambiara— no
// habría ningún error: el contador simplemente volvería a sumar en cada beacon
// de tiempo activo y las visitas medirían eventos otra vez, que es EXACTAMENTE
// el bug que se está arreglando. Un fallo silencioso que restaura el bug
// silencioso original.
//
// Se lee la migración REAL de setup/ y sólo se le cambian los nombres de
// tabla; una copia pegada aquí se desincronizaría el primer día.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

const IT_VIEWS_PROBE = 'it_views_probe';
const IT_SALTS_PROBE = 'it_salts_probe';
const IT_RES_PROBE   = 'it_res_probe';

/** Sentencias de migration_012, apuntadas a tablas de prueba. */
function it_track_statements(): array
{
    $raw = (string) file_get_contents(
        dirname(__DIR__, 2) . '/setup/migration_012_engagement.sql'
    );
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $raw);

    // El orden importa: 'resource_views' contiene 'resources' como subcadena,
    // así que hay que sustituir primero el nombre largo.
    $sql = str_replace('resource_views', IT_VIEWS_PROBE, $sql);
    $sql = str_replace('view_salts',     IT_SALTS_PROBE, $sql);
    $sql = str_replace('resources',      IT_RES_PROBE,   $sql);

    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

/** Deja el escenario limpio: tabla `resources` mínima + migración aplicada. */
function it_track_setup(PDO $db): void
{
    $db->exec('DROP TABLE IF EXISTS ' . IT_VIEWS_PROBE);
    $db->exec('DROP TABLE IF EXISTS ' . IT_SALTS_PROBE);
    $db->exec('DROP TABLE IF EXISTS ' . IT_RES_PROBE);

    // Mínima pero con la forma que importa: la clave ajena de resource_views
    // apunta aquí, así que el tipo y el motor tienen que coincidir.
    $db->exec('CREATE TABLE ' . IT_RES_PROBE . ' (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        view_count   INT NOT NULL DEFAULT 0,
        is_active    TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('INSERT INTO ' . IT_RES_PROBE . ' (id, view_count) VALUES (1, 290), (2, 0)');

    foreach (it_track_statements() as $stmt) {
        $db->exec($stmt);
    }
}

/**
 * Reproduce el INSERT de api/track.php y devuelve rowCount().
 * 1 = fila nueva (visita única), 2 = actualización, 0 = sin cambios.
 *
 * ── OJO CON CÓMO SE FABRICA LA CLAVE ──────────────────────────
 * La primera versión de este helper rellenaba con str_pad($key, 64, '0'), y
 * el test de los 20 alumnos daba 18. No era un fallo del código: 'alumno1'
 * rellenado con ceros hasta 64 es IDÉNTICO a 'alumno10' rellenado hasta 64,
 * así que dos parejas colisionaban. En producción viewer_key es siempre un
 * sha256 —64 hex, longitud fija, sin relleno— y esa colisión no puede
 * ocurrir. El helper hashea igual que api/track.php justo para no volver a
 * introducir en la prueba un problema que el sistema real no tiene.
 */
function it_track_beacon(PDO $db, int $res, string $key, string $day, int $secs = 0, int $inter = 0): int
{
    $st = $db->prepare('INSERT INTO ' . IT_VIEWS_PROBE . '
            (resource_id, viewer_key, view_day, engaged_secs, interacted, is_authed, surface)
        VALUES (?, ?, ?, ?, ?, 0, ?)
        ON DUPLICATE KEY UPDATE
            engaged_secs = GREATEST(engaged_secs, VALUES(engaged_secs)),
            interacted   = GREATEST(interacted,   VALUES(interacted))');
    $st->execute([$res, hash('sha256', $key), $day, $secs, $inter, 'detail']);

    if ($st->rowCount() === 1) {
        $db->prepare('UPDATE ' . IT_RES_PROBE . ' SET unique_views = unique_views + 1 WHERE id = ?')
           ->execute([$res]);
    }
    return $st->rowCount();
}

function it_track_unique(PDO $db, int $res): int
{
    $st = $db->prepare('SELECT unique_views FROM ' . IT_RES_PROBE . ' WHERE id = ?');
    $st->execute([$res]);
    return (int) $st->fetchColumn();
}


// ── La migración ────────────────────────────────────────────────

function test_migration_012_se_aplica_y_es_idempotente(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);
    it_track_beacon($db, 1, 'aaa', '2026-08-06');

    $err = null;
    try {
        foreach (it_track_statements() as $stmt) $db->exec($stmt);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    it_true($err === null, 'reejecutar la migración no falla — ' . (string) $err);

    $n = (int) $db->query('SELECT COUNT(*) FROM ' . IT_VIEWS_PROBE)->fetchColumn();
    it_eq(1, $n, 'y no altera ni una fila');
}

function test_migration_012_no_toca_el_historico(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    // El recurso 1 nació con view_count = 290. La migración añade la métrica
    // nueva SIN reescribir la vieja: si alguien "unificara" los contadores, un
    // autor vería sus 290 visitas convertirse en 0 el día del despliegue.
    $st = $db->query('SELECT view_count, unique_views FROM ' . IT_RES_PROBE . ' WHERE id = 1');
    $row = $st->fetch(PDO::FETCH_ASSOC);
    it_eq(290, (int) $row['view_count'], 'view_count histórico intacto tras la migración');
    it_eq(0, (int) $row['unique_views'], 'unique_views arranca en 0: es una magnitud distinta, no una corrección');
}


// ── La propiedad de la que depende todo ─────────────────────────

function test_solo_la_primera_visita_del_dia_cuenta(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    it_eq(1, it_track_beacon($db, 1, 'ana', '2026-08-06'), 'primer beacon: fila NUEVA');
    it_eq(1, it_track_unique($db, 1), 'y cuenta como una visita única');

    // El caso que restauraría el bug: la misma persona genera 3-4 beacons más
    // por sesión (tiempo activo, interacción, salida). Ninguno puede volver a
    // sumar.
    it_eq(2, it_track_beacon($db, 1, 'ana', '2026-08-06', 30), 'beacon de tiempo activo: ACTUALIZA');
    it_eq(2, it_track_beacon($db, 1, 'ana', '2026-08-06', 90, 1), 'beacon de interacción: ACTUALIZA');
    it_eq(0, it_track_beacon($db, 1, 'ana', '2026-08-06', 10), 'beacon con valores menores: NO CAMBIA NADA');

    it_eq(1, it_track_unique($db, 1),
        'tras cuatro beacons más, la visita sigue siendo UNA. Sin la condición '
        . 'rowCount() === 1, aquí habría 5 y el contador volvería a medir '
        . 'eventos en vez de personas — el bug original, restaurado.');
}

function test_veinte_alumnos_cuentan_veinte(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    // El caso que originó todo. Cada alumno trae SU identificador de navegador
    // —no su IP, que sería la misma para toda el aula por el NAT del colegio— y
    // cada uno manda varios beacons durante su sesión.
    for ($i = 1; $i <= 20; $i++) {
        $key = 'alumno' . $i;
        it_track_beacon($db, 1, $key, '2026-08-06');
        it_track_beacon($db, 1, $key, '2026-08-06', 120, 1);
        it_track_beacon($db, 1, $key, '2026-08-06', 240, 1);
    }

    it_eq(20, it_track_unique($db, 1),
        '20 alumnos con 3 beacons cada uno = 20 visitas. Es el número que el '
        . 'sistema no sabía dar: contaba 8.');

    $interactuaron = (int) $db->query('SELECT COUNT(*) FROM ' . IT_VIEWS_PROBE . '
                                       WHERE resource_id = 1 AND interacted = 1')->fetchColumn();
    it_eq(20, $interactuaron, 'y de los 20 consta que interactuaron, no sólo que miraron');
}

function test_el_mismo_navegador_otro_dia_es_otra_visita(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    it_track_beacon($db, 1, 'ana', '2026-08-06');
    it_track_beacon($db, 1, 'ana', '2026-08-07');

    it_eq(2, it_track_unique($db, 1),
        'la dedup es DIARIA: volver mañana es una visita nueva. Es también '
        . 'consecuencia de rotar la sal — sin la sal del día 06 ya no se puede '
        . 'saber que fue la misma persona, y esa imposibilidad es deliberada.');
}

function test_cada_recurso_lleva_su_propia_cuenta(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    it_track_beacon($db, 1, 'ana', '2026-08-06');
    it_track_beacon($db, 2, 'ana', '2026-08-06');

    it_eq(1, it_track_unique($db, 1), 'recurso 1: una visita');
    it_eq(1, it_track_unique($db, 2), 'recurso 2: una visita');
}


// ── El tiempo activo nunca retrocede ────────────────────────────

function test_el_tiempo_activo_solo_puede_crecer(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    it_track_beacon($db, 1, 'ana', '2026-08-06', 0, 0);
    it_track_beacon($db, 1, 'ana', '2026-08-06', 180, 1);
    // Beacon que llega DESORDENADO —la red los reordena— con datos viejos.
    it_track_beacon($db, 1, 'ana', '2026-08-06', 20, 0);

    $row = $db->query('SELECT engaged_secs, interacted FROM ' . IT_VIEWS_PROBE)->fetch(PDO::FETCH_ASSOC);
    it_eq(180, (int) $row['engaged_secs'],
        'GREATEST protege del beacon que llega tarde con datos viejos: sin él, '
        . 'un reordenamiento de red convertiría 3 minutos de atención en 20 '
        . 'segundos');
    it_eq(1, (int) $row['interacted'], 'y una interacción ya registrada no se puede desmarcar');
}

function test_borrar_un_recurso_se_lleva_sus_visitas(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);
    it_track_beacon($db, 1, 'ana', '2026-08-06');
    it_track_beacon($db, 2, 'ana', '2026-08-06');

    $db->exec('DELETE FROM ' . IT_RES_PROBE . ' WHERE id = 1');

    $n = (int) $db->query('SELECT COUNT(*) FROM ' . IT_VIEWS_PROBE . ' WHERE resource_id = 1')->fetchColumn();
    it_eq(0, $n, 'la clave ajena ON DELETE CASCADE no deja visitas huérfanas de un recurso borrado');

    $vivas = (int) $db->query('SELECT COUNT(*) FROM ' . IT_VIEWS_PROBE . ' WHERE resource_id = 2')->fetchColumn();
    it_eq(1, $vivas, 'y no se lleva por delante las de los demás');
}


// ── Las sales ───────────────────────────────────────────────────

function test_las_sales_caducadas_se_borran(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    $db->exec("INSERT INTO " . IT_SALTS_PROBE . " (view_day, salt) VALUES
        ('2026-08-01', REPEAT('a', 64)),
        ('2026-08-05', REPEAT('b', 64)),
        ('2026-08-06', REPEAT('c', 64))");

    // Misma sentencia que api/track.php, con la retención de 2 días.
    $db->prepare('DELETE FROM ' . IT_SALTS_PROBE . ' WHERE view_day < DATE_SUB(?, INTERVAL ? DAY)')
       ->execute(['2026-08-06', 2]);

    $quedan = $db->query('SELECT view_day FROM ' . IT_SALTS_PROBE . ' ORDER BY view_day')
                 ->fetchAll(PDO::FETCH_COLUMN);

    it_eq(['2026-08-05', '2026-08-06'], $quedan,
        'sólo sobreviven las sales dentro de la ventana de retención. La del '
        . '2026-08-01 desaparece, y con ella la posibilidad de recalcular el '
        . 'viewer_key de aquel día: la anonimización deja de ser una promesa.');
}

function test_dos_visitantes_simultaneos_no_rompen_la_sal(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_track_setup($db);

    // La carrera real: dos personas llegan el primer segundo del día y las dos
    // generan una sal. INSERT IGNORE hace que quien pierde no reviente; sólo
    // relee la que ganó. Con INSERT a secas, el segundo visitante se llevaría
    // un 1062 y su visita se perdería.
    $ins = $db->prepare('INSERT IGNORE INTO ' . IT_SALTS_PROBE . ' (view_day, salt) VALUES (?, ?)');

    $err = null;
    try {
        $ins->execute(['2026-08-06', str_repeat('a', 64)]);
        $ins->execute(['2026-08-06', str_repeat('b', 64)]);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    it_true($err === null, 'el segundo visitante no revienta — ' . (string) $err);

    $n = (int) $db->query('SELECT COUNT(*) FROM ' . IT_SALTS_PROBE . " WHERE view_day = '2026-08-06'")->fetchColumn();
    it_eq(1, $n, 'y queda UNA sola sal para el día');
}
