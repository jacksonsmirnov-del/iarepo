<?php
// ================================================================
// tests/integration/fork_lineage_db_test.php — migration_013 contra MariaDB
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// El backfill de `root_id` aplana cadenas de forks repitiendo cuatro veces el
// MISMO `UPDATE … JOIN`. Cada repetición sube un nivel. Es un idioma poco
// habitual —se eligió porque setup/run_migration.php:41 trocea el fichero con
// explode(';') y un WITH RECURSIVE ataría el resultado a una versión de
// MariaDB que en producción es DESCONOCIDA— y hay que demostrar que funciona,
// no suponerlo.
//
// Y el modo de fallo es silencioso: si el aplanado se quedara a medias, un
// fork de un fork apuntaría a su padre en vez de a la raíz y NO APARECERÍA
// entre las versiones del original. Sin error, sin log, sin nada: simplemente
// una versión que nadie encuentra.
//
// Se lee la migración REAL de setup/ y sólo se le cambia el nombre de la tabla.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

const IT_LIN_PROBE = 'it_lineage_probe';

/** Sentencias de migration_013, apuntadas a la tabla de prueba. */
function it_lin_statements(): array
{
    $raw = (string) file_get_contents(
        dirname(__DIR__, 2) . '/setup/migration_013_fork_lineage.sql'
    );
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $raw);
    $sql = str_replace('resources', IT_LIN_PROBE, $sql);

    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

/**
 * Tabla con la forma PREVIA a la migración: con fork_of y sin root_id.
 * Es el estado real de producción.
 */
function it_lin_setup(PDO $db): void
{
    $t = IT_LIN_PROBE;
    $db->exec("DROP TABLE IF EXISTS $t");
    $db->exec("CREATE TABLE $t (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(255) NOT NULL,
        fork_of     INT NULL,
        visibility  ENUM('draft','area','school','community') DEFAULT 'draft',
        is_active   TINYINT(1) DEFAULT 1,
        INDEX idx_fork (fork_of)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function it_lin_apply(PDO $db): ?string
{
    foreach (it_lin_statements() as $stmt) {
        try {
            $db->exec($stmt);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
    return null;
}

function it_lin_roots(PDO $db): array
{
    $out = [];
    foreach ($db->query('SELECT id, root_id FROM ' . IT_LIN_PROBE . ' ORDER BY id') as $r) {
        $out[(int) $r['id']] = (int) $r['root_id'];
    }
    return $out;
}


// ── El aplanado ─────────────────────────────────────────────────

function test_el_backfill_resuelve_una_cadena_de_cuatro_niveles(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_lin_setup($db);
    $t = IT_LIN_PROBE;

    // 1 original · 2 fork de 1 · 3 fork de 2 · 4 fork de 3 · 5 original suelto
    $db->exec("INSERT INTO $t (id, title, fork_of) VALUES
        (1,'raiz',NULL), (2,'v2',1), (3,'v3',2), (4,'v4',3), (5,'otra raiz',NULL)");

    $err = it_lin_apply($db);
    it_true($err === null, 'la migración se aplica sin error — ' . (string) $err);

    it_eq(
        [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 5],
        it_lin_roots($db),
        'toda la cadena apunta a la RAÍZ, no al padre inmediato. Si el aplanado '
        . 'se quedara a medias, la v4 colgaría de la v3 y no aparecería entre '
        . 'las versiones del original — invisible y sin ningún error.'
    );
}

function test_un_original_es_su_propia_raiz(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_lin_setup($db);
    $db->exec('INSERT INTO ' . IT_LIN_PROBE . " (id, title, fork_of) VALUES (7,'sola',NULL)");
    it_lin_apply($db);

    it_eq([7 => 7], it_lin_roots($db),
        'root_id = su propio id. Gracias a eso, "dame todas las versiones de X" '
        . 'es siempre WHERE root_id = X, sin un caso especial para el original.');
}

function test_los_forks_huerfanos_no_quedan_invisibles(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_lin_setup($db);
    $t = IT_LIN_PROBE;

    // fork_of NO tiene clave ajena, así que puede apuntar a un recurso que ya
    // no existe. Es un estado alcanzable en producción hoy.
    $db->exec("INSERT INTO $t (id, title, fork_of) VALUES (9,'huerfano',999)");
    it_lin_apply($db);

    it_eq([9 => 9], it_lin_roots($db),
        'un fork cuyo padre ya no existe pasa a ser su propia raíz. Sin el paso '
        . '3 del backfill se quedaría con root_id = 999 y no saldría en NINGÚN '
        . 'listado de versiones, sin que nada fallara.');
}

function test_el_backfill_es_idempotente(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_lin_setup($db);
    $db->exec('INSERT INTO ' . IT_LIN_PROBE . " (id, title, fork_of) VALUES
        (1,'raiz',NULL), (2,'v2',1), (3,'v3',2)");

    it_lin_apply($db);
    $primera = it_lin_roots($db);

    $err = it_lin_apply($db);
    it_true($err === null, 'reejecutar no falla — ' . (string) $err);
    it_eq($primera, it_lin_roots($db), 'y el linaje no cambia');
}

function test_la_migracion_no_toca_los_titulos(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_lin_setup($db);
    $db->exec('INSERT INTO ' . IT_LIN_PROBE . " (id, title, fork_of) VALUES (1,'Fork: Movimiento',NULL)");
    it_lin_apply($db);

    $tit = (string) $db->query('SELECT title FROM ' . IT_LIN_PROBE . ' WHERE id = 1')->fetchColumn();
    it_eq('Fork: Movimiento', $tit,
        'los títulos ya creados son CONTENIDO DE USUARIO. El prefijo deja de '
        . 'añadirse en el código, pero reescribir por lote lo existente es una '
        . 'decisión del mantenedor, no un efecto colateral de una migración.');
}


// ── La consulta que hace cada carga de ficha ────────────────────

function test_el_panel_solo_ve_versiones_publicas(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_lin_setup($db);
    $t = IT_LIN_PROBE;

    $db->exec("INSERT INTO $t (id, title, fork_of, visibility) VALUES
        (1,'raiz',      NULL, 'community'),
        (2,'publicada', 1,    'community'),
        (3,'borrador',  1,    'draft'),
        (4,'del cole',  1,    'school'),
        (5,'borrada',   1,    'community')");
    $db->exec("UPDATE $t SET is_active = 0 WHERE id = 5");
    it_lin_apply($db);

    // La MISMA cláusula WHERE que resource/index.php. Se ordena por id en vez
    // de por created_at porque la tabla de sonda no lleva esa columna y aquí
    // sólo importa QUÉ filas entran, no en qué orden.
    $st = $db->prepare("SELECT id FROM $t
        WHERE root_id = ? AND id <> ? AND is_active = 1 AND visibility = 'community'
        ORDER BY id DESC");
    $st->execute([1, 1]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    it_eq([2], $ids,
        'sólo la versión pública y activa. El borrador y la del colegio son '
        . 'invisibles para terceros: listarlas pondría en la ficha enlaces que '
        . 'nadie más puede abrir. Y la borrada no vuelve.');
}

function test_desde_una_version_se_ve_el_resto_del_linaje(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_lin_setup($db);
    $t = IT_LIN_PROBE;

    $db->exec("INSERT INTO $t (id, title, fork_of, visibility) VALUES
        (1,'raiz', NULL,'community'),
        (2,'v2',   1,   'community'),
        (3,'v3',   1,   'community')");
    it_lin_apply($db);

    // Mirando desde la v2: tiene que ver la raíz y la v3, no sólo sus hijos
    // (que no tiene ninguno). Es lo que hace que el panel funcione igual desde
    // cualquier punto del linaje.
    $st = $db->prepare("SELECT id FROM $t WHERE root_id = ? AND id <> ? AND visibility = 'community' ORDER BY id");
    $st->execute([1, 2]);
    it_eq([1, 3], array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
        'desde una versión derivada se ven el original y las versiones hermanas');
}
