<?php
// ================================================================
// tests/unit/fork_lineage_test.php — El contrato del linaje de forks
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// Tres reglas que, al romperse, NO producen ningún error:
//
//   1. Sólo el autor del recurso RAÍZ destaca versiones. Si se relaja, cada
//      autor destaca su propio fork, "recomendada" pasa a significar "su autor
//      pulsó un botón" y el distintivo deja de valer — sin que nada falle.
//   2. Sólo se destacan versiones PÚBLICAS. Recomendar un borrador ajeno pone
//      en la ficha un enlace que nadie más puede abrir.
//   3. El fork hereda la raíz del padre. Si se pusiera `root_id = $originalId`
//      a secas, un fork de un fork quedaría colgando de su padre y no
//      aparecería entre las versiones del original: invisible, sin error.
//
// Se audita el TEXTO del código (api/resources.php es un script que no se
// puede incluir desde un test) con iarepo_php_code_only(), que descarta los
// comentarios para no suspender por la documentación.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Código EJECUTABLE de api/resources.php. */
function lineage_api_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/api/resources.php'));
    }
    return $src;
}

/** Código EJECUTABLE de la ficha. */
function lineage_page_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/resource/index.php'));
    }
    return $src;
}

/** Sentencias de migration_013, sin comentarios. */
function lineage_migration_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_sql_code_only(
            (string) file_get_contents(IAREPO_ROOT . '/setup/migration_013_fork_lineage.sql')
        );
    }
    return $src;
}

// ── 1 · Sólo el autor de la raíz bendice ────────────────────────

function test_recomendar_exige_ser_autor_de_la_raiz(): void
{
    $src = lineage_api_src();

    assert_contains(
        "action === 'recommend'",
        $src,
        'existe la acción recommend'
    );

    // La comprobación tiene que ir contra el autor de la RAÍZ ($root), no
    // contra el del recurso destacado ($target).
    assert_matches(
        '/\$root\[[\'"]author_user_id[\'"]\]\s*!==\s*\(int\)\s*\$user\[[\'"]user_id[\'"]\]/',
        $src,
        'se compara el autor de la RAÍZ con quien pulsa. Contra el autor del '
        . 'propio fork, cualquiera se autodestacaría y "recomendada" dejaría de '
        . 'significar nada.'
    );

    assert_matches(
        '/author_tenant_id[\'"]\]\s*!==\s*\(int\)\s*\(\$user\[[\'"]tenant_id[\'"]\]/',
        $src,
        'y también el tenant: los user_id vienen de Campus y sólo son únicos '
        . 'dentro de su colegio'
    );
}

function test_un_original_no_se_recomienda_a_si_mismo(): void
{
    assert_matches(
        '/\$rootId\s*===\s*\$targetId.*IS_ROOT/s',
        lineage_api_src(),
        'un original no puede destacarse: ya es la referencia por defecto, así '
        . 'que marcarlo no significaría nada y ensuciaría el listado'
    );
}

function test_solo_se_recomiendan_versiones_publicas(): void
{
    assert_matches(
        '/visibility[\'"]\]\s*!==\s*[\'"]community[\'"].*NOT_PUBLIC/s',
        lineage_api_src(),
        'recomendar un borrador pondría en la ficha un enlace que nadie más '
        . 'puede abrir'
    );
}

function test_ocultar_el_boton_no_es_la_proteccion(): void
{
    // La ficha sólo pinta el botón para el autor de la raíz ($canRecommend),
    // pero el POST se lanza a mano con dos líneas. Este test fija que las DOS
    // capas existen, para que nadie retire la del servidor razonando que la UI
    // ya lo esconde.
    assert_contains('$canRecommend', lineage_page_src(), 'la ficha decide si pinta el botón');
    assert_contains('rec-btn', lineage_page_src(), 'y el botón existe');
    assert_matches('/json_error\([^;]*403\)/', lineage_api_src(), 'y el servidor responde 403 a quien no debe');
}

// ── 2 · El linaje se hereda, no se inventa ──────────────────────

function test_el_fork_hereda_la_raiz_del_padre(): void
{
    $src = lineage_api_src();

    // `$rootId = root_id del padre ?: id del padre`. Si alguien lo simplifica a
    // `$rootId = $originalId`, un fork de un fork colgaría de su padre en vez
    // de la raíz y no saldría entre las versiones del original — invisible y
    // sin ningún error.
    assert_matches(
        '/\$rootId\s*=\s*\(int\)\s*\(\$original\[[\'"]root_id[\'"]\]\s*\?\?\s*0\)\s*\?:\s*\$originalId/',
        $src,
        'el fork hereda la raíz del padre y sólo cae a su id cuando el padre no '
        . 'la tiene resuelta'
    );

    assert_matches(
        '/INSERT INTO resources\s*\([^)]*root_id/s',
        $src,
        'y root_id entra en el INSERT del fork'
    );
}

function test_el_titulo_del_fork_ya_no_lleva_prefijo(): void
{
    assert_not_contains(
        "'Fork: '",
        lineage_api_src(),
        "el título del fork ya no se prefija con 'Fork: '. Ensuciaba la tarjeta "
        . "del catálogo y era redundante: la relación con el original es un dato "
        . "del linaje, no algo que meter dentro del nombre."
    );
}

// ── 3 · Lo que la ficha enseña ──────────────────────────────────

function test_la_ficha_lista_solo_versiones_publicas(): void
{
    $src = lineage_page_src();

    assert_matches(
        '/WHERE root_id = \?[^"]*visibility = [\'"]community[\'"]/s',
        $src,
        'el panel sólo lista versiones community. Un fork nace en draft y casi '
        . 'ninguno se publica: sacarlas todas enseñaría copias privadas de gente '
        . 'trasteando.'
    );

    assert_matches(
        '/ORDER BY is_recommended DESC/',
        $src,
        'la versión bendecida por el autor va primero. Ordenar por conteo bruto '
        . 'de visitas o likes haría ganar SIEMPRE al original —lleva años '
        . 'acumulando— y forkear no podría salir rentable nunca.'
    );
}

function test_la_ficha_no_cuenta_forks_privados(): void
{
    $src = lineage_page_src();

    // fork_count incluye los 'draft', que son casi todos: la ficha decía "12
    // Forks" y al pinchar aparecían 2.
    assert_not_matches(
        '/\(int\)\$r\[[\'"]fork_count[\'"]\]/',
        $src,
        'la ficha ya no muestra fork_count crudo: cuenta las versiones que de '
        . 'verdad se pueden abrir'
    );
    assert_contains(
        'count($versions)',
        $src,
        'y ese número sale del mismo listado que pinta el panel, así que no '
        . 'pueden discrepar'
    );
}

function test_la_consulta_de_linaje_degrada_sin_romper_la_pagina(): void
{
    // resource/index.php está en quality/baseline_html_helpers.txt: carga
    // helpers.php y con él error_handler.php, que ante una excepción hace
    // echo json_encode(...) + exit(1). Si el despliegue llega antes que
    // migration_013, root_id no existe y un ERROR 1054 sin capturar sacaría la
    // página a medio renderizar con un JSON incrustado — trampa nº1 del
    // CLAUDE.md.
    assert_matches(
        '/\$versions\s*=\s*\[\];.*try\s*\{.*root_id.*\}\s*catch\s*\(\s*Throwable/s',
        lineage_page_src(),
        'la consulta del linaje va en try/catch y degrada a lista vacía'
    );
}

// ── 4 · La migración ────────────────────────────────────────────

function test_la_migracion_declara_las_dos_columnas_y_el_indice(): void
{
    $sql = lineage_migration_src();

    assert_matches('/ADD COLUMN IF NOT EXISTS root_id/', $sql, 'root_id es idempotente');
    assert_matches('/ADD COLUMN IF NOT EXISTS is_recommended/', $sql, 'is_recommended es idempotente');
    assert_matches('/ADD INDEX IF NOT EXISTS idx_root/', $sql,
        'hay índice sobre root_id: "todas las versiones de X" es la consulta '
        . 'que hace CADA carga de ficha');
}

function test_el_backfill_aplana_cadenas_de_varios_niveles(): void
{
    $sql = lineage_migration_src();

    // Cada UPDATE ... JOIN sube un nivel. Con uno solo, un fork de un fork
    // quedaría apuntando a su padre en vez de a la raíz.
    $n = preg_match_all('/UPDATE resources r JOIN resources p ON r\.root_id = p\.id/', $sql);
    assert_true(
        $n >= 3,
        "el backfill repite el aplanado al menos 3 veces (hay $n). Cada "
        . 'repetición resuelve UN nivel de la cadena; con una sola, un fork de '
        . 'un fork se quedaría colgando de su padre.'
    );

    assert_matches(
        '/LEFT JOIN resources p ON r\.root_id = p\.id\s*SET r\.root_id = r\.id WHERE p\.id IS NULL/s',
        $sql,
        'y los forks huérfanos —fork_of no tiene clave ajena, así que puede '
        . 'apuntar a un recurso borrado— se convierten en su propia raíz. Sin '
        . 'esto quedarían con un root_id muerto y no aparecerían en NINGÚN '
        . 'listado, sin que nada fallara.'
    );
}

function test_la_migracion_no_reescribe_titulos_de_usuario(): void
{
    // El prefijo 'Fork: ' deja de añadirse en el código, pero los títulos ya
    // creados son contenido de usuario: reescribirlos por lote es una decisión
    // del mantenedor, no un efecto colateral de una migración de esquema.
    assert_not_matches(
        '/UPDATE resources SET title/i',
        lineage_migration_src(),
        'la migración no toca ningún título'
    );
}
