<?php
// ================================================================
// tests/unit/usage_signal_test.php — El contrato de la señal de uso docente
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// 'presented' —"lo usé en clase"— es la señal de calidad de más valor del
// catálogo: la firma un profesional que se jugó 50 minutos de clase. Todo su
// valor depende de tres propiedades que NO son visibles leyendo el código por
// encima, y que se pierden con un refactor bienintencionado:
//
//   1. Sólo la afirman docentes.       (requireRole)
//   2. Una por profesor, recurso y día. (usage_day + uniq_usage_signal)
//   3. Un fallo no publica las tripas.  (nada de $e->getMessage() al cliente)
//
// Ninguna de las tres se rompe RUIDOSAMENTE. Si alguien quita el requireRole,
// la API sigue respondiendo 200 y el contador sigue subiendo: sólo que ahora
// lo alimentan alumnos y la métrica deja de significar lo que dice. Por eso
// se vigilan aquí, en la capa sin BD, donde el fallo sale en 0,3 s.
//
// ── POR QUÉ SE LEE EL FICHERO EN VEZ DE EJECUTARLO ────────────
// api/usage.php es un script, no un módulo: al incluirlo abre conexión,
// llama a cors(), aplica rateLimit() y termina en exit. No se puede cargar
// desde un test unitario. Se audita su TEXTO, que es exactamente lo que hace
// tests/integration/cron_heartbeat_test.php con cron/run.php.
//
// Un test de texto es más frágil que uno de comportamiento, y es una
// concesión consciente: el comportamiento real se prueba contra MariaDB en
// tests/integration/usage_signal_db_test.php. Esto es la red barata que corre
// en cada push; aquélla es la red cara que corre cuando hay Docker.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Código EJECUTABLE de api/usage.php, sin comentarios. */
function usage_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_php_code_only(
            (string) file_get_contents(IAREPO_ROOT . '/api/usage.php')
        );
    }
    return $src;
}

/** Código EJECUTABLE de resource/index.php (el HTML embebido se conserva). */
function usage_page_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_php_code_only(
            (string) file_get_contents(IAREPO_ROOT . '/resource/index.php')
        );
    }
    return $src;
}

/** Sentencias de la migración que sostiene la dedup, sin comentarios '--'. */
function usage_migration_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_sql_code_only(
            (string) file_get_contents(IAREPO_ROOT . '/setup/migration_011_usage_signal.sql')
        );
    }
    return $src;
}

// ── 1 · Sólo docentes ───────────────────────────────────────────

function test_el_post_de_uso_exige_rol_docente(): void
{
    $src = usage_src();

    assert_matches(
        '/requireRole\(\s*\$user\s*,\s*\[[^\]]*[\'"]teacher[\'"]/',
        $src,
        "api/usage.php llama a requireRole() con 'teacher'. Sin esto, un alumno "
        . "puede afirmar 'lo usé en clase' y contaminar la única métrica que "
        . "mide intención docente — sin ningún error visible."
    );

    assert_not_matches(
        '/requireRole\(\s*\$user\s*,\s*\[[^\]]*[\'"]student[\'"]/',
        $src,
        "el rol 'student' NO está entre los autorizados a registrar uso"
    );
}

function test_ocultar_el_boton_no_se_considera_proteccion(): void
{
    // El botón vive tras `$user && !$isStudent` en resource/index.php, pero eso
    // es cosmética: el POST se lanza a mano con dos líneas de consola. Este
    // test fija que la comprobación REAL está en el servidor, para que nadie
    // retire el requireRole razonando que "la UI ya lo esconde".
    assert_contains(
        'usedBtn',
        usage_page_src(),
        'la página de recurso pinta el botón de uso'
    );
    assert_contains(
        'requireRole',
        usage_src(),
        'y la autorización de verdad está en api/usage.php, no en la plantilla'
    );
}

// ── 2 · Una por profesor, recurso y día ─────────────────────────

function test_el_insert_escribe_usage_day(): void
{
    $src = usage_src();

    assert_matches(
        '/INSERT INTO resource_usage\s*\([^)]*usage_day/s',
        $src,
        'el INSERT incluye usage_day. Si se cae de la lista, la columna queda '
        . 'NULL, uniq_usage_signal deja de aplicar (en InnoDB un UNIQUE admite '
        . 'múltiples NULL) y "lo usé en clase" vuelve a ser pulsable infinitas '
        . 'veces — en silencio, porque nada falla.'
    );
}

function test_solo_presented_y_sent_deduplican_por_dia(): void
{
    $src = usage_src();

    // El contrato completo, en una línea, tal y como está escrito:
    //   $usageDay = in_array($usageType, ['presented','sent'], true) ? date(...) : null
    assert_matches(
        '/\$usageDay\s*=\s*in_array\(\s*\$usageType\s*,\s*\[\s*[\'"]presented[\'"]\s*,\s*[\'"]sent[\'"]\s*\]/',
        $src,
        "usage_day sólo se rellena para 'presented' y 'sent'"
    );

    assert_not_matches(
        '/\$usageDay\s*=\s*in_array\(\s*\$usageType\s*,\s*\[[^\]]*[\'"]forked[\'"]/',
        $src,
        "'forked' NUNCA entra en la dedup diaria: forkear dos veces el mismo "
        . "recurso el mismo día es legal hoy y debe seguir siéndolo. Meterlo "
        . "aquí rompería api/resources.php sin tocar api/resources.php."
    );

    assert_not_matches(
        '/\$usageDay\s*=\s*in_array\(\s*\$usageType\s*,\s*\[[^\]]*[\'"]endorsed[\'"]/',
        $src,
        "'endorsed' tampoco: se deduplica PARA SIEMPRE con el chequeo de la "
        . "aplicación, y un índice diario lo debilitaría dejando volver a "
        . "endosar mañana."
    );
}

function test_la_migracion_declara_el_indice_unico(): void
{
    $sql = usage_migration_src();

    assert_contains(
        'uniq_usage_signal',
        $sql,
        'migration_011 crea el índice que impone la dedup'
    );
    assert_matches(
        '/UNIQUE KEY IF NOT EXISTS\s+uniq_usage_signal\s*\(\s*resource_id\s*,\s*user_id\s*,\s*tenant_id\s*,\s*usage_type\s*,\s*usage_day\s*\)/',
        $sql,
        'la clave lleva tenant_id. Los user_id vienen de Campus y sólo son '
        . 'únicos DENTRO de su tenant: sin él, el usuario 7 del colegio A '
        . 'bloquearía al usuario 7 del colegio B.'
    );
}

function test_la_migracion_no_usa_sql_dinamico(): void
{
    // setup/run_migration.php:41 trocea el fichero con explode(';', $sql), un
    // split ingenuo que no entiende literales. Un PREPARE/EXECUTE con ';'
    // dentro de una cadena se partiría por la mitad y la migración fallaría a
    // medias — dejando el esquema en un estado intermedio.
    $sql = usage_migration_src();

    assert_not_contains(
        'PREPARE',
        $sql,
        'la migración no usa SQL dinámico (incompatible con el explode(\';\') '
        . 'de setup/run_migration.php:41)'
    );
}

// ── 3 · Un fallo no publica las tripas ──────────────────────────

function test_ningun_endpoint_devuelve_el_mensaje_de_la_excepcion(): void
{
    // La forma exacta del fallo: json_error('Failed: ' . $e->getMessage())
    // entregaba el error crudo de MariaDB —tablas, columnas y fragmentos de
    // consulta— a cualquiera capaz de provocar una excepción. Estaba en CUATRO
    // sitios (usage, fork, like, update de recursos); se limpiaron los cuatro
    // el 2026-08-06.
    //
    // ── POR QUÉ BARRE TODO api/ Y NO SÓLO usage.php ───────────────
    // Es una CLASE de fallo, no un fallo. Vigilar sólo el fichero donde se vio
    // deja el patrón libre en los otros catorce endpoints, y el siguiente
    // `catch` que alguien escriba copiará el que tenga más cerca. Barriendo el
    // directorio, el guard cubre también los endpoints que aún no existen.
    $ficheros = glob(IAREPO_ROOT . '/api/*.php') ?: [];
    assert_true(count($ficheros) > 10, 'el barrido encontró los endpoints de api/');

    $culpables = [];
    foreach ($ficheros as $f) {
        $src = iarepo_php_code_only((string) file_get_contents($f));
        if (preg_match('/json_error\([^;]*\$e->getMessage\(\)/', $src)) {
            $culpables[] = basename($f);
        }
    }

    assert_eq(
        [],
        $culpables,
        'ningún json_error() de api/ concatena $e->getMessage(). El detalle va '
        . 'al log del servidor con api_log(); al cliente, sólo un mensaje '
        . 'genérico y un código. Filtran: ' . implode(', ', $culpables)
    );
}

function test_sanear_la_respuesta_no_hace_invisible_el_fallo(): void
{
    // El riesgo de arreglar una fuga es pasarse: si se quita el mensaje y no se
    // registra nada, el error deja de existir para quien mantiene el sitio —
    // que es el fallo silencioso que este proyecto ya sufrió con el latido de
    // cron (un try/catch mudo se tragó un 1267 durante semanas).
    assert_matches(
        '/api_log\(\s*[\'"]error[\'"]/',
        usage_src(),
        'api/usage.php registra el detalle con api_log() antes de responder'
    );
}

function test_el_choque_de_dedup_no_es_un_500(): void
{
    $src = usage_src();

    assert_contains(
        '1062',
        $src,
        'se distingue el error 1062 (choque con el UNIQUE)'
    );
    assert_matches(
        '/json_error\([^;]*409\s*,\s*[\'"]ALREADY_RECORDED[\'"]/',
        $src,
        'y se responde 409 ALREADY_RECORDED, no 500. Un choque de dedup es la '
        . 'restricción haciendo su trabajo: devolverlo como error de servidor '
        . 'llenaría el log de fallos falsos y taparía los de verdad.'
    );
}

function test_el_front_traduce_el_codigo_no_el_mensaje(): void
{
    // Las respuestas de api/*.php son un contrato para Campus y van en inglés
    // sin t(). Lo que el usuario LEE lo decide el front a partir del código.
    // Si alguien cambia el texto del mensaje, la UI no debe romperse.
    assert_contains(
        "data.code==='ALREADY_RECORDED'",
        usage_page_src(),
        'el front rama por el CÓDIGO de error, no por el texto del mensaje'
    );
}
