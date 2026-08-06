<?php
// ================================================================
// tests/unit/tracking_test.php — El contrato de la medición de visitas
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// Cuatro propiedades que se rompen SIN QUE NADA FALLE:
//
//   1. No se guarda ninguna IP.        (privacidad — y hay menores usando esto)
//   2. La identidad va hasheada con la sal del día. (anonimización con caducidad)
//   3. Una fila nueva = una visita única. (que el contador cuente personas)
//   4. Nadie vuelve a meter un `view_count + 1`. (que las dos métricas sigan
//      siendo comparables entre sí)
//
// Si alguien añade un clientIp() "para depurar", la API sigue respondiendo 200
// y el contador sigue subiendo. Nada se pone rojo, nadie se entera, y el texto
// legal publicado pasa a ser falso. Esa es la clase de fallo que se vigila
// aquí.
//
// ── POR QUÉ SE AUDITA EL TEXTO DEL CÓDIGO ─────────────────────
// api/track.php es un script, no un módulo: incluirlo abre conexión, llama a
// cors() y termina en exit. No se puede cargar desde un test unitario. Se
// audita su código con iarepo_php_code_only(), que descarta los comentarios —
// si no, estos tests suspenderían por la documentación que explica lo que NO
// se hace. El comportamiento real se prueba contra MariaDB en
// tests/integration/tracking_db_test.php.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Código EJECUTABLE de api/track.php. */
function track_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/api/track.php'));
    }
    return $src;
}

/**
 * Código EJECUTABLE de shared/viewer_key.php — la identidad del visitante.
 *
 * Vivía dentro de api/track.php hasta que api/feedback.php necesitó
 * exactamente el mismo cálculo. Duplicarlo habría sido la peor clase de
 * duplicación: dos copias que pueden divergir sin que nada falle, y la que se
 * quedara atrás produciría claves distintas para la misma persona, rompiendo
 * la deduplicación en silencio.
 */
function track_identity_src(): string
{
    static $src = null;
    if ($src === null) {
        $src = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/shared/viewer_key.php'));
    }
    return $src;
}

/**
 * Toda la superficie que participa en medir: los endpoints y el módulo de
 * identidad. Las reglas de privacidad son de la SUPERFICIE, no de un fichero
 * — si sólo se vigilara uno, mover el código bastaría para saltárselas, que es
 * justo lo que estuvo a punto de pasar al extraer shared/viewer_key.php.
 */
function track_surface_files(): array
{
    return [
        IAREPO_ROOT . '/api/track.php',
        IAREPO_ROOT . '/api/feedback.php',
        IAREPO_ROOT . '/shared/viewer_key.php',
    ];
}

/** El cliente. No es PHP: se lee entero, comentarios incluidos. */
function track_js(): string
{
    static $src = null;
    if ($src === null) {
        $src = (string) file_get_contents(IAREPO_ROOT . '/assets/js/track.js');
    }
    return $src;
}

// ── 1 · Ninguna IP, en ningún sitio ─────────────────────────────

function test_ninguna_superficie_de_medicion_toca_la_ip(): void
{
    // clientIp() existe en helpers.php y está a un require de distancia. Basta
    // con que alguien la use "para depurar" y se quede.
    $culpables = [];
    foreach (track_surface_files() as $f) {
        if (!is_file($f)) continue;
        $src = iarepo_php_code_only((string) file_get_contents($f));
        foreach (['clientIp', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR'] as $prohibido) {
            if (str_contains($src, $prohibido)) $culpables[] = basename($f) . ':' . $prohibido;
        }
    }

    assert_eq(
        [],
        $culpables,
        'ninguna superficie de medición toca la IP. Una IP identifica a una '
        . 'persona, y aquí se está midiendo a MENORES; además haría falso el '
        . 'punto 10 de legal/terms.php, que es un texto publicado. Filtran: '
        . implode(', ', $culpables)
    );
}

function test_la_dedup_no_puede_volver_a_basarse_en_la_red(): void
{
    // No es sólo privacidad: deduplicar por IP EMPEORARÍA la medición. Los
    // alumnos de un colegio salen por el NAT del centro —una IP para toda la
    // clase— así que 20 alumnos contarían como uno. Es exactamente el síntoma
    // que originó este trabajo, y el error es tentador porque parece "más
    // robusto" que fiarse del navegador.
    assert_matches(
        '/preg_match\(\s*[\'"][^\'"]*\[a-f0-9\]\{32\}/',
        track_identity_src(),
        'el identificador anónimo es el token de 32 hex que genera el navegador, '
        . 'validado en el servidor para que nadie pueda elegir su viewer_key'
    );
}

function test_el_modulo_de_identidad_no_arrastra_helpers(): void
{
    // shared/viewer_key.php lo puede necesitar cualquier superficie, incluida
    // alguna página HTML el día de mañana. Si arrastrara helpers.php —y con él
    // error_handler.php, cuyos handlers hacen echo json_encode(...) + exit(1)—
    // convertiría cualquier fallo en una página a medio renderizar con un JSON
    // incrustado. Misma regla que cumple shared/search.php.
    assert_not_matches(
        '/require(_once)?[^;]*helpers\.php/',
        track_identity_src(),
        'shared/viewer_key.php no carga helpers.php (regla nº1 del CLAUDE.md)'
    );
}

// ── 2 · Hash con sal caducable ──────────────────────────────────

function test_la_identidad_se_hashea_con_la_sal_del_dia(): void
{

    assert_matches(
        '/hash\(\s*[\'"]sha256[\'"].*iarepo_daily_salt/s',
        track_identity_src(),
        'viewer_key = sha256(identificador + sal del día). Guardar el '
        . 'identificador en claro permitiría cruzar toda la actividad de un '
        . 'navegador para siempre.'
    );
}

function test_las_sales_viejas_se_borran(): void
{
    $src = track_identity_src();

    assert_contains(
        'DELETE FROM view_salts',
        $src,
        'las sales caducadas se borran: es lo que convierte la anonimización '
        . 'en un hecho y no en una promesa. Sin la purga, cualquiera con acceso '
        . 'a la BD puede recalcular el viewer_key de cualquier día.'
    );

    // La purga cuelga del alta de la sal (una vez al día, determinista) y no de
    // un sorteo por petición como el de rateLimit: con el tráfico de este
    // sitio, un random_int(1,100)===1 podría no salir en semanas y la ventana
    // de retención dejaría de cumplirse justo en el caso silencioso.
    assert_not_matches(
        '/random_int\([^)]*\)\s*===?\s*1/',
        $src,
        'la purga no depende de un sorteo por petición'
    );
}

// ── 3 · Una fila nueva = una visita única ───────────────────────

function test_solo_la_fila_nueva_incrementa_el_contador(): void
{
    $src = track_src();

    // Verificado contra MariaDB 11.8: con ON DUPLICATE KEY UPDATE, rowCount()
    // devuelve 1 al insertar, 2 al actualizar y 0 si no cambia nada.
    assert_matches(
        '/rowCount\(\)\s*===\s*1/',
        $src,
        'el incremento de unique_views va condicionado a rowCount() === 1. Sin '
        . 'esa condición, CADA beacon de tiempo activo sumaría otra visita y el '
        . 'contador volvería a medir eventos — el bug que se está arreglando.'
    );

    assert_matches(
        '/GREATEST\(engaged_secs/',
        $src,
        'el tiempo activo se consolida con GREATEST: el cliente manda el '
        . 'acumulado, así que un beacon repetido o desordenado no infla ni hace '
        . 'retroceder el dato'
    );
}

function test_el_tiempo_activo_se_capa_antes_de_la_bd(): void
{
    $src = track_src();

    assert_contains(
        'IAREPO_MAX_ENGAGED_SECS',
        $src,
        'hay un tope explícito para engaged_secs'
    );
    assert_matches(
        '/min\(\s*\$engaged\s*,\s*IAREPO_MAX_ENGAGED_SECS\s*\)/',
        $src,
        'y se aplica ANTES del INSERT. engaged_secs es SMALLINT UNSIGNED: con '
        . 'STRICT_TRANS_TABLES un desbordamiento aborta con ERROR 1264 y se '
        . 'perdería la VISITA ENTERA por culpa de un dato accesorio.'
    );
}

// ── 4 · view_count queda congelado ──────────────────────────────

function test_nadie_vuelve_a_incrementar_view_count(): void
{
    // Los dos sitios que lo hacían: viewer/index.php y api/resources.php. Si
    // vuelve cualquiera de los dos, la misma carga contaría en las DOS métricas
    // y dejarían de poder compararse — sin ningún error visible.
    $ficheros = array_merge(
        glob(IAREPO_ROOT . '/api/*.php') ?: [],
        [IAREPO_ROOT . '/viewer/index.php', IAREPO_ROOT . '/resource/index.php']
    );

    $culpables = [];
    foreach ($ficheros as $f) {
        $src = iarepo_php_code_only((string) file_get_contents($f));
        if (preg_match('/UPDATE\s+resources\s+SET\s+view_count\s*=\s*view_count\s*\+/i', $src)) {
            $culpables[] = basename(dirname($f)) . '/' . basename($f);
        }
    }

    assert_eq(
        [],
        $culpables,
        'view_count está congelado como marca histórica; la métrica viva es '
        . 'unique_views, que escribe api/track.php con deduplicación. '
        . 'Lo incrementan: ' . implode(', ', $culpables)
    );
}

function test_la_pagina_de_detalle_mide_por_beacon(): void
{
    // El bug original: /resource/N renderiza el recurso FUNCIONANDO en un
    // iframe srcdoc y no contaba nada. Es la página donde ocurre el uso real.
    $page = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/resource/index.php'));

    assert_contains(
        '/assets/js/track.js',
        $page,
        'la página de detalle carga el beacon: era la que no contaba nada pese '
        . 'a ser donde de verdad se usa el recurso'
    );
    assert_contains(
        'data-surface="detail"',
        $page,
        'y se identifica como superficie "detail"'
    );
}

// ── 5 · El código y el texto legal no pueden divergir ───────────

function test_el_texto_legal_declara_lo_que_el_codigo_hace(): void
{
    // Éste es el test raro del fichero, y es a propósito.
    //
    // legal/terms.php es un documento PUBLICADO con el nombre del responsable
    // encima. Antes decía "No recopilamos datos personales de visitantes
    // anónimos"; empezar a guardar un identificador de navegador sin tocar esa
    // línea no habría roto ningún test... y habría dejado publicada una
    // afirmación falsa. El código y lo que se promete al usuario tienen que
    // moverse juntos.
    $legal = (string) file_get_contents(IAREPO_ROOT . '/legal/terms.php');

    assert_not_contains(
        'No recopilamos datos personales de visitantes anónimos',
        $legal,
        'esa frase dejó de ser cierta al empezar a medir visitas: el navegador '
        . 'guarda un identificador. Si vuelve al texto, o se retira la medición '
        . 'o se está publicando algo falso.'
    );

    assert_matches(
        '/almacenamiento local|localStorage/i',
        $legal,
        'el texto legal declara que se guarda algo en el navegador'
    );
    assert_matches(
        '/No guardamos direcciones IP|no.{0,40}direcciones IP/i',
        $legal,
        'y declara que no se guardan IPs — que es justo lo que vigila '
        . 'test_el_tracking_no_toca_la_ip_del_visitante'
    );
}

// ── 6 · El cliente ──────────────────────────────────────────────

function test_el_cliente_degrada_en_silencio(): void
{
    $js = track_js();

    assert_matches(
        '/try\s*\{[^}]*localStorage/s',
        $js,
        'el acceso a localStorage va en try/catch: en modo privado restrictivo '
        . 'o con políticas de empresa lanza, y medir NUNCA puede ser requisito '
        . 'para poder usar el sitio'
    );
    assert_contains(
        'sendBeacon',
        $js,
        'usa sendBeacon, el único envío que el navegador garantiza durante la '
        . 'descarga de la página'
    );
}

function test_el_cliente_solo_cuenta_tiempo_visible(): void
{
    $js = track_js();

    assert_contains(
        'visibilityState',
        $js,
        'el reloj sólo corre con la pestaña visible. Sin esto, una pestaña '
        . 'olvidada de fondo produciría "3 horas de atención" y el dato dejaría '
        . 'de significar nada.'
    );
}

function test_el_cliente_no_manda_nada_que_no_este_declarado(): void
{
    // El contrato con la página legal: qué sale del navegador. Si alguien añade
    // un campo (la URL de referencia, el idioma, la resolución…), este test se
    // pone rojo y obliga a decidirlo conscientemente y a declararlo.
    $js = track_js();

    if (!preg_match('/JSON\.stringify\(\{(.*?)\}\)/s', $js, $m)) {
        assert_true(false, 'se pudo localizar el cuerpo que manda el beacon');
        return;
    }

    // Sólo claves de propiedad: precedidas por '{' o por ',' y al principio de
    // línea. Un `\w+\s*:` a secas también captura el '1' de un ternario como
    // `interacted ? 1 : 0`, que estaba justo en el objeto que se audita.
    preg_match_all('/(?:^|[{,])\s*(\w+)\s*:/m', $m[1], $campos);
    sort($campos[1]);

    assert_eq(
        ['engaged_secs', 'interacted', 'resource_id', 'surface', 'vid'],
        $campos[1],
        'el beacon manda EXACTAMENTE estos cinco campos. Cualquier añadido hay '
        . 'que reflejarlo en legal/terms.php §10.1 antes de desplegarlo.'
    );
}
