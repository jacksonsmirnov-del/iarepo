<?php
// ================================================================
// tests/integration/comprehension_db_test.php — migration_014 contra MariaDB
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// La pregunta «¿te quedó claro?» sólo vale si la contesta quien de verdad usó
// el recurso. Esa puerta es una consulta sobre `resource_views`, y su modo de
// fallo es silencioso en las dos direcciones:
//
//   · Demasiado abierta → cualquiera inunda un recurso de «me perdí» sin
//     haberlo abierto. La API responde 200 y el único dato pedagógico del
//     sistema queda envenenado.
//   · Demasiado cerrada → nadie puede contestar nunca. Tampoco falla nada:
//     simplemente no llegan respuestas, y se atribuye a que «la gente no
//     participa».
//
// Se prueban las dos con datos reales, además de la propiedad que hace que
// cambiar de opinión no duplique filas.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

const IT_CMP_RES   = 'it_cmp_res';
const IT_CMP_VIEWS = 'it_cmp_views';
const IT_CMP_TABLE = 'it_cmp_comprehension';

/** Umbral real de api/feedback.php, leído del fichero para no desincronizarse. */
function it_cmp_min_secs(): int
{
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/api/feedback.php');
    return preg_match('/IAREPO_FEEDBACK_MIN_SECS\s*=\s*(\d+)/', $src, $m) ? (int) $m[1] : -1;
}

/** Sentencias de migration_014, apuntadas a las tablas de prueba. */
function it_cmp_statements(): array
{
    $raw = (string) file_get_contents(
        dirname(__DIR__, 2) . '/setup/migration_014_comprehension.sql'
    );
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $raw);
    $sql = str_replace('resource_comprehension', IT_CMP_TABLE, $sql);
    $sql = str_replace('resources', IT_CMP_RES, $sql);

    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

function it_cmp_setup(PDO $db): void
{
    foreach ([IT_CMP_TABLE, IT_CMP_VIEWS, IT_CMP_RES] as $t) {
        $db->exec("DROP TABLE IF EXISTS $t");
    }

    $db->exec('CREATE TABLE ' . IT_CMP_RES . ' (id INT AUTO_INCREMENT PRIMARY KEY)
               ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('INSERT INTO ' . IT_CMP_RES . ' (id) VALUES (1), (2)');

    $db->exec('CREATE TABLE ' . IT_CMP_VIEWS . ' (
        resource_id  INT NOT NULL,
        viewer_key   CHAR(64) NOT NULL,
        view_day     DATE NOT NULL,
        engaged_secs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        interacted   TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (resource_id, viewer_key, view_day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    foreach (it_cmp_statements() as $stmt) $db->exec($stmt);
}

function it_cmp_key(string $who): string
{
    // sha256 como en producción: 64 hex, longitud fija. Rellenar con ceros
    // provocaría colisiones entre 'alumno1' y 'alumno10' — ya pasó en
    // tests/integration/tracking_db_test.php.
    return hash('sha256', $who);
}

function it_cmp_view(PDO $db, int $res, string $who, int $secs, int $inter, string $day = '2026-08-06'): void
{
    $db->prepare('INSERT INTO ' . IT_CMP_VIEWS . '
        (resource_id, viewer_key, view_day, engaged_secs, interacted) VALUES (?,?,?,?,?)')
       ->execute([$res, it_cmp_key($who), $day, $secs, $inter]);
}

/** Reproduce la puerta de api/feedback.php. true = puede contestar. */
function it_cmp_gate(PDO $db, int $res, string $who, string $day = '2026-08-06'): bool
{
    $st = $db->prepare('SELECT engaged_secs, interacted FROM ' . IT_CMP_VIEWS . '
                        WHERE resource_id = ? AND viewer_key = ? AND view_day = ?');
    $st->execute([$res, it_cmp_key($who), $day]);
    $v = $st->fetch();

    return $v && (int) $v['interacted'] === 1 && (int) $v['engaged_secs'] >= it_cmp_min_secs();
}

function it_cmp_answer(PDO $db, int $res, string $who, string $answer, string $day = '2026-08-06'): void
{
    $db->prepare('INSERT INTO ' . IT_CMP_TABLE . ' (resource_id, viewer_key, view_day, answer)
                  VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE answer = VALUES(answer)')
       ->execute([$res, it_cmp_key($who), $day, $answer]);
}


// ── La migración ────────────────────────────────────────────────

function test_migration_014_se_aplica_y_es_idempotente(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);
    it_cmp_answer($db, 1, 'ana', 'claro');

    $err = null;
    try {
        foreach (it_cmp_statements() as $stmt) $db->exec($stmt);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    it_true($err === null, 'reejecutar la migración no falla — ' . (string) $err);
    it_eq(1, (int) $db->query('SELECT COUNT(*) FROM ' . IT_CMP_TABLE)->fetchColumn(),
        'y no altera ni una fila');
}

function test_el_esquema_no_admite_una_respuesta_inventada(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);
    $db->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");

    $code = 0;
    try {
        $db->prepare('INSERT INTO ' . IT_CMP_TABLE . ' (resource_id, viewer_key, view_day, answer)
                      VALUES (1, ?, ?, ?)')
           ->execute([it_cmp_key('ana'), '2026-08-06', 'excelente']);
    } catch (\PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? -1);
    }

    it_eq(1265, $code,
        'el ENUM rechaza cualquier valor fuera de las tres opciones. Es lo que '
        . 'impide que esto degenere en una escala de valoración por la puerta '
        . 'de atrás.');
}


// ── La puerta ───────────────────────────────────────────────────

function test_el_umbral_esta_declarado(): void
{
    it_true(it_cmp_min_secs() > 0,
        'se pudo leer IAREPO_FEEDBACK_MIN_SECS de api/feedback.php (vale '
        . it_cmp_min_secs() . 's)');
}

function test_quien_uso_el_recurso_de_verdad_puede_contestar(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);
    it_cmp_view($db, 1, 'ana', it_cmp_min_secs() + 60, 1);

    it_true(it_cmp_gate($db, 1, 'ana'),
        'tiempo activo por encima del umbral e interacción registrada: puede '
        . 'contestar. Si la puerta fuera demasiado estricta no llegaría NINGUNA '
        . 'respuesta y se atribuiría a que "la gente no participa".');
}

function test_quien_solo_paso_por_alli_no_puede(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);

    it_cmp_view($db, 1, 'mira_y_se_va', 8, 0);                        // ni tiempo ni interacción
    it_cmp_view($db, 1, 'pestana_abierta', it_cmp_min_secs() + 600, 0); // tiempo, pero sin tocar nada
    it_cmp_view($db, 1, 'toca_y_huye', 12, 1);                        // toca, pero 12 segundos

    it_true(!it_cmp_gate($db, 1, 'mira_y_se_va'), 'abrir y largarse no da derecho a opinar');
    it_true(!it_cmp_gate($db, 1, 'pestana_abierta'),
        'una pestaña abierta media hora sin tocar nada tampoco: eso no es uso, '
        . 'es una pestaña abierta');
    it_true(!it_cmp_gate($db, 1, 'toca_y_huye'), 'un clic y fuera tampoco');
}

function test_sin_visita_no_se_puede_contestar(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);

    it_true(!it_cmp_gate($db, 1, 'fantasma'),
        'sin fila de visita no hay respuesta posible. Ésta es la puerta que '
        . 'impide inundar un recurso de "me perdí" sin haberlo abierto — y el '
        . 'ataque es trivial, porque el POST se lanza a mano.');
}

function test_la_visita_de_otro_recurso_no_abre_esta_puerta(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);
    it_cmp_view($db, 2, 'ana', it_cmp_min_secs() + 60, 1);   // usó el recurso 2

    it_true(it_cmp_gate($db, 2, 'ana'), 'puede opinar del recurso que usó');
    it_true(!it_cmp_gate($db, 1, 'ana'),
        'pero NO del recurso 1, que no abrió. Sin el resource_id en la puerta, '
        . 'una sola visita daría derecho a opinar de todo el catálogo.');
}

function test_la_visita_de_ayer_no_abre_la_puerta_de_hoy(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);
    it_cmp_view($db, 1, 'ana', it_cmp_min_secs() + 60, 1, '2026-08-05');

    it_true(!it_cmp_gate($db, 1, 'ana', '2026-08-06'),
        'la puerta es del día. No es una restricción arbitraria: la sal rota a '
        . 'diario, así que la viewer_key de ayer YA NO SE PUEDE RECALCULAR — la '
        . 'anonimización y la puerta son la misma propiedad.');
}


// ── Cambiar de opinión ──────────────────────────────────────────

function test_corregir_la_respuesta_no_duplica_filas(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);

    // Alguien marca "me perdí", sigue trasteando y acaba entendiéndolo.
    it_cmp_answer($db, 1, 'ana', 'perdido');
    it_cmp_answer($db, 1, 'ana', 'regular');
    it_cmp_answer($db, 1, 'ana', 'claro');

    it_eq(1, (int) $db->query('SELECT COUNT(*) FROM ' . IT_CMP_TABLE)->fetchColumn(),
        'una sola fila: la clave primaria deduplica por persona, recurso y día');
    it_eq('claro', (string) $db->query('SELECT answer FROM ' . IT_CMP_TABLE)->fetchColumn(),
        'y vale la última. Congelar la primera guardaría la peor versión de la '
        . 'respuesta justo de quien acabó entendiéndolo.');
}

function test_el_agregado_cuenta_personas_no_pulsaciones(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);

    it_cmp_answer($db, 1, 'a', 'claro');
    it_cmp_answer($db, 1, 'b', 'claro');
    it_cmp_answer($db, 1, 'c', 'perdido');
    it_cmp_answer($db, 1, 'c', 'perdido');   // vuelve a pulsar lo mismo
    it_cmp_answer($db, 1, 'd', 'regular');

    $rows = $db->query('SELECT answer, COUNT(*) AS n FROM ' . IT_CMP_TABLE . '
                        WHERE resource_id = 1 GROUP BY answer')
               ->fetchAll(PDO::FETCH_KEY_PAIR);

    // ⚠️ `ORDER BY answer` sobre un ENUM ordena por el ORDINAL de declaración
    // ('claro','regular','perdido'), no alfabéticamente. Es una trampa clásica
    // de MySQL/MariaDB y este test se puso rojo por ella con los MISMOS
    // números en distinto orden de claves. Se ordena aquí para que la prueba
    // hable de cuentas, que es lo que importa, y no del orden en que llegan.
    $rows = array_map('intval', $rows);
    ksort($rows);

    it_eq(['claro' => 2, 'perdido' => 1, 'regular' => 1], $rows,
        'el agregado del panel del autor cuenta personas. Pulsar dos veces no '
        . 'suma dos.');
}

function test_borrar_el_recurso_se_lleva_las_respuestas(): void
{
    $db = it_db_or_skip(__FUNCTION__);
    if ($db === null) return;

    it_cmp_setup($db);
    it_cmp_answer($db, 1, 'ana', 'claro');
    it_cmp_answer($db, 2, 'ana', 'claro');

    $db->exec('DELETE FROM ' . IT_CMP_RES . ' WHERE id = 1');

    it_eq(0, (int) $db->query('SELECT COUNT(*) FROM ' . IT_CMP_TABLE . ' WHERE resource_id = 1')->fetchColumn(),
        'la clave ajena no deja respuestas huérfanas de un recurso borrado');
    it_eq(1, (int) $db->query('SELECT COUNT(*) FROM ' . IT_CMP_TABLE . ' WHERE resource_id = 2')->fetchColumn(),
        'y no se lleva las de los demás');
}
