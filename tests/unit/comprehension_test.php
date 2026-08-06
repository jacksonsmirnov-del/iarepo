<?php
// ================================================================
// tests/unit/comprehension_test.php — El contrato de «¿te quedó claro?»
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// Cuatro propiedades que se rompen sin que nada falle:
//
//   1. Sólo responde quien DEMOSTRÓ uso, y eso se comprueba en el SERVIDOR.
//      Sin esa puerta, cualquiera inunda un recurso de «me perdí» sin haberlo
//      abierto: la API responde 200 y el único dato pedagógico del sistema
//      queda envenenado en silencio.
//   2. La respuesta es anónima. Guardar aquí la identidad convertiría un
//      agregado en un registro nominal de qué menor no entendió qué.
//   3. No hay texto libre. Un campo abierto rellenado por menores es
//      contenido que habría que moderar — y el cron de moderación de este
//      repo ya estuvo parado 66 días sin que nadie lo notara.
//   4. El agregado NO es público. Un contador de «me perdí» a la vista de
//      cualquiera sería una picota para el autor.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Código EJECUTABLE de api/feedback.php. */
function cmp_api_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/api/feedback.php'));
    }
    return $src;
}

/** Sentencias de migration_014, sin comentarios. */
function cmp_migration_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_sql_code_only(
            (string) file_get_contents(IAREPO_ROOT . '/setup/migration_014_comprehension.sql')
        );
    }
    return $src;
}

// ── 1 · La puerta está en el servidor ───────────────────────────

function test_solo_responde_quien_demostro_uso(): void
{
    $src = cmp_api_src();

    assert_matches(
        '/SELECT engaged_secs, interacted FROM resource_views/',
        $src,
        'la puerta consulta la MISMA tabla de visitas: sólo puede contestar '
        . 'quien tiene una visita registrada hoy en este recurso'
    );

    assert_matches(
        '/interacted[\'"]\]\s*!==\s*1/',
        $src,
        'y exige interacción real, no sólo haber abierto la página'
    );

    assert_matches(
        '/engaged_secs[\'"]\]\s*<\s*IAREPO_FEEDBACK_MIN_SECS/',
        $src,
        'y tiempo activo suficiente. Preguntar al que abre y se va en 8 '
        . 'segundos contamina la muestra y molesta.'
    );

    assert_matches(
        '/json_error\([^;]*403,\s*[\'"]NOT_ENGAGED[\'"]/',
        $src,
        'quien no pasa la puerta recibe 403: la petición está bien formada, lo '
        . 'que falta es el permiso'
    );
}

function test_ocultar_el_prompt_no_es_la_proteccion(): void
{
    // El cliente esconde el prompt hasta que track.js confirma uso real, pero
    // el POST se lanza a mano con dos líneas. Este test fija que existen las
    // DOS capas, para que nadie retire la del servidor razonando que la UI ya
    // lo esconde.
    $js = (string) file_get_contents(IAREPO_ROOT . '/assets/js/track.js');
    assert_contains('iarepo:engaged', $js, 'el cliente avisa cuando hay uso real');
    assert_contains('resource_views', cmp_api_src(), 'y el servidor lo verifica contra la BD');
}

function test_el_umbral_del_cliente_y_el_del_servidor_coinciden(): void
{
    // Si divergen, se enseña una pregunta que el servidor va a rechazar: el
    // usuario contesta y recibe un error. No revienta nada, pero es la peor
    // experiencia posible y nadie lo notaría sin probarlo a mano.
    $js = (string) file_get_contents(IAREPO_ROOT . '/assets/js/track.js');

    assert_matches('/MIN_SECS\s*=\s*(\d+)/', $js, 'el cliente declara su umbral');
    preg_match('/MIN_SECS\s*=\s*(\d+)/', $js, $mJs);

    assert_matches('/IAREPO_FEEDBACK_MIN_SECS\s*=\s*(\d+)/', cmp_api_src(), 'y el servidor el suyo');
    preg_match('/IAREPO_FEEDBACK_MIN_SECS\s*=\s*(\d+)/', cmp_api_src(), $mApi);

    assert_eq(
        (int) ($mApi[1] ?? -1),
        (int) ($mJs[1] ?? -2),
        'los dos umbrales tienen que ser el mismo número. Con el del cliente '
        . 'más bajo, se pregunta a gente a la que el servidor dirá 403.'
    );
}

// ── 2 · Anónimo ─────────────────────────────────────────────────

function test_la_respuesta_no_guarda_identidad(): void
{
    $sql = cmp_migration_src();

    foreach (['user_id', 'author_user_id', 'email', 'display_name', 'ip'] as $prohibido) {
        assert_not_matches(
            '/^\s*' . $prohibido . '\s/mi',
            $sql,
            "resource_comprehension no tiene columna `$prohibido`. Con la "
            . "identidad en claro, esto dejaría de ser un agregado anónimo y "
            . "pasaría a ser un registro nominal de qué MENOR no entendió qué."
        );
    }

    assert_contains(
        'viewer_key',
        $sql,
        'la identidad es el mismo viewer_key hasheado que las visitas, con sal '
        . 'que caduca a los 2 días'
    );
}

function test_la_clave_deduplica_por_persona_recurso_y_dia(): void
{
    assert_matches(
        '/PRIMARY KEY \(resource_id, viewer_key, view_day\)/',
        cmp_migration_src(),
        'una respuesta por persona, recurso y día — la misma forma de clave que '
        . 'resource_views, para que hereden la misma anonimización'
    );

    assert_matches(
        '/ON DUPLICATE KEY UPDATE answer = VALUES\(answer\)/',
        cmp_api_src(),
        'y se puede corregir: alguien marca "me perdí", sigue trasteando y lo '
        . 'entiende. Rechazar la corrección congelaría la peor versión de la '
        . 'respuesta.'
    );
}

// ── 3 · Sin texto libre ─────────────────────────────────────────

function test_no_hay_campo_de_texto_libre(): void
{
    $sql = cmp_migration_src();

    assert_not_matches(
        '/\b(TEXT|VARCHAR|MEDIUMTEXT)\b/i',
        $sql,
        'la tabla no admite texto libre. No es pereza: un campo abierto '
        . 'rellenado por menores es contenido que hay que MODERAR, y el cron de '
        . 'moderación de este repo ya estuvo parado 66 días sin que nadie lo '
        . 'notara. Un ENUM no se modera.'
    );

    assert_matches(
        "/ENUM\('claro','regular','perdido'\)/",
        $sql,
        'las tres respuestas posibles están fijadas en el esquema'
    );
}

function test_no_se_calcula_ninguna_media(): void
{
    // Se descartaron las estrellas porque con 546 recursos y tráfico bajo la
    // mayoría tendría 0-3 votos, y una media de dos votos es ruido con aspecto
    // de autoridad. Aquí se CUENTAN respuestas.
    assert_not_matches(
        '/\bAVG\(/i',
        cmp_api_src() . cmp_migration_src(),
        'no hay media: se cuentan respuestas, que es lo único que un volumen '
        . 'pequeño permite afirmar con honestidad'
    );
}

// ── 4 · El agregado no es público ───────────────────────────────

function test_el_agregado_solo_lo_ve_el_autor(): void
{
    $dash = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/dashboard/index.php'));
    $page = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/resource/index.php'));

    // Se busca el USO en SQL, no el nombre a secas: iarepo_php_code_only()
    // conserva el HTML embebido —y con él los <script>—, así que un comentario
    // de JavaScript que mencione la tabla no debe contar como consultarla. Un
    // guard que castiga nombrar algo en un comentario empuja a documentar peor.
    assert_matches(
        '/FROM\s+resource_comprehension/i',
        $dash,
        'el agregado se calcula en el dashboard del autor'
    );

    assert_not_matches(
        '/(FROM|INTO|UPDATE)\s+resource_comprehension/i',
        $page,
        'y la ficha pública NO consulta esa tabla. Un contador de "me perdí" a '
        . 'la vista de cualquiera sería una picota para el autor, y convertiría '
        . 'una herramienta de mejora en una nota.'
    );
}

function test_el_dashboard_degrada_sin_romper_la_pagina(): void
{
    // dashboard/index.php está en quality/baseline_html_helpers.txt: carga
    // helpers.php y con él error_handler.php. Si el despliegue llega antes que
    // migration_014, la tabla no existe y un ERROR 1146 sin capturar sacaría la
    // página a medio renderizar con un JSON incrustado.
    $dash = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/dashboard/index.php'));

    assert_matches(
        '/\$comprehension\s*=\s*\[\];.*try\s*\{.*resource_comprehension.*catch\s*\(\s*Throwable/s',
        $dash,
        'la consulta del agregado va en try/catch y degrada a vacío'
    );
}

function test_el_texto_legal_declara_la_pregunta(): void
{
    // Mismo criterio que con la medición de visitas: el código y lo que se
    // promete al usuario tienen que moverse juntos. Aquí además el dato es
    // sobre la comprensión de menores.
    $legal = (string) file_get_contents(IAREPO_ROOT . '/legal/terms.php');

    assert_matches(
        '/¿te quedó claro\?|quedó claro/iu',
        $legal,
        'legal/terms.php declara la pregunta'
    );
    assert_matches(
        '/no se registra quién contestó qué|ning[úu]n momento se registra qui[ée]n/iu',
        $legal,
        'y declara explícitamente que no se sabe quién contestó qué'
    );
}
