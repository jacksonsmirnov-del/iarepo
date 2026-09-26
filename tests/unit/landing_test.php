<?php
// ================================================================
// tests/unit/landing_test.php — La portada (index.php), el 404 y el manifest
//
// Comprobaciones ESTÁTICAS del fuente (sin BD, sin servidor): fijan las
// decisiones del rediseño de 2026-09 que se pueden deshacer sin que nada
// falle a la vista. Lo que solo se ve renderizando está en
// tests/integration/render_pages_test.php (bloque «Portada»).
//
// Cada test nace de un fallo real o de una decisión tomada:
//   · «Más usados» ordenaba por view_count, un contador CONGELADO desde
//     2026-08-06: la portada destacaba para siempre lo que más se cargó antes
//     de esa fecha. Ninguna sección puede volver a ordenar por popularidad.
//   · Google Fonts y el script de Google Sign-In avisaban a terceros de cada
//     visita a una web que abren menores. «Entrar» lleva a /auth/signin.php.
//   · ?lang= filtraba el catálogo Y cambiaba el idioma de la interfaz: el
//     filtro de idioma del recurso en la URL de la portada es ?rlang=.
//   · Posicionamiento: fuera «El GitHub para profesores».
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

function landing_src(string $rel): string
{
    return (string) file_get_contents(IAREPO_ROOT . '/' . $rel);
}

/** Solo el PHP ejecutable (sin comentarios ni HTML/JS en línea). */
function landing_php(string $rel): string
{
    $out = '';
    foreach (token_get_all(landing_src($rel)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true))
            continue;
        $out .= is_array($tok) ? $tok[1] : $tok;
    }
    return $out;
}

/** El JavaScript en línea de la página, sin sus comentarios de línea. */
function landing_js(string $rel): string
{
    // La etiqueta partida a propósito: el guard G6 de quality/guards.sh toma
    // por JavaScript en línea todo lo que haya entre dos etiquetas literales.
    $tag = 'scr' . 'ipt';
    preg_match_all("#<{$tag}>(.*?)</{$tag}>#s", landing_src($rel), $m);
    return (string) preg_replace('#(^|\s)//[^\n]*#', '$1', implode("\n", $m[1]));
}

/** Valor de una constante `const NOMBRE = '…' . "…";` de index.php (literales concatenados). */
function landing_const(string $name): string
{
    $toks = token_get_all(landing_src('index.php'));
    $n    = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_CONST)
            continue;
        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($toks[$j]) || $toks[$j][1] !== $name)
            continue;
        $val = '';
        for ($j++; $j < $n && $toks[$j] !== ';'; $j++) {
            if (!is_array($toks[$j]) || $toks[$j][0] !== T_CONSTANT_ENCAPSED_STRING)
                continue;
            $lit  = $toks[$j][1];
            $body = substr($lit, 1, -1);
            $val .= $lit[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
        }
        return $val;
    }
    test_fail("index.php ya no define la constante $name");
}

/** Lo que llega al navegador: sin comentarios de PHP, de HTML ni de línea de JS. */
function landing_visible(string $rel): string
{
    $out = iarepo_php_code_only(landing_src($rel));
    $out = (string) preg_replace('#<!--.*?-->#s', '', $out);
    return (string) preg_replace('#(^|\s)//[^\n]*#', '$1', $out);
}

function test_la_portada_ya_no_se_presenta_como_github_para_profesores(): void
{
    foreach (['index.php', '404.php', 'manifest.webmanifest'] as $f)
        assert_not_contains('GitHub para profesores', landing_src($f), "$f");
    assert_contains("t('Ciencias y matemáticas que se entienden tocándolas')", landing_src('index.php'),
        'el H1 es el posicionamiento, traducible');
}

/**
 * Ninguna sección de la portada ordena por popularidad: view_count está
 * congelado y use_count/unique_views aún no tienen datos (CLAUDE.md §8).
 */
function test_ninguna_seccion_ordena_por_popularidad(): void
{
    $src = landing_src('index.php');
    $php = landing_php('index.php');
    assert_not_contains('ORDER BY (r.use_count', $src, 'vuelve el «Más usados» de antes');

    $orden = landing_const('IAREPO_HOME_ORDER');
    assert_not_matches('/view_count|use_count|like_count|unique_views|popular/i', $orden, 'el orden de las secciones');
    assert_contains('RAND(TO_DAYS(CURDATE()))', $orden, 'rota cada día, igual para todos durante el día');
    assert_contains("ORDER BY ' . IAREPO_HOME_ORDER", $php, 'las secciones usan ese orden');

    preg_match_all('/ORDER\s+BY\s+[^\n\'"]*/i', $php, $m);
    foreach ($m[0] as $orderBy)
        assert_not_matches('/view_count|use_count|like_count|unique_views/i', $orderBy, "ORDER BY de index.php: $orderBy");
    // Ni siquiera se leen: una sección que no los pide no puede acabar enseñándolos.
    assert_not_matches('/view_count|like_count|use_count/', $php, 'el PHP de la portada no consulta contadores');
}

/** Nada de 👁/❤ ni «Más usados/Más vistos» en la interfaz del catálogo. */
function test_el_catalogo_no_ensena_contadores_ni_ordena_por_ellos(): void
{
    $src = landing_visible('index.php');
    $js  = landing_js('index.php');
    assert_not_matches('/r\.(view_count|like_count|use_count)/', $js, 'la tarjeta no pinta contadores');
    assert_not_contains('👁', $src);
    assert_not_contains('❤', $src);
    assert_not_matches('/<option value="(popular|views)"/', $src, 'el orden por uso o visitas sale de la interfaz');
    assert_not_contains("t('Más usados')", $src);
    assert_not_contains("t('Más vistos')", $src);
    // …pero un enlace viejo ?sort=popular no rompe nada: la portada lo trata
    // como su orden por defecto (el mismo criterio que api/resources.php).
    assert_matches("/\\\$sortOptions\s*=\s*\['relevance',\s*'recent'\];/", landing_php('index.php'));
}

/** Las secciones solo enseñan lo que ve cualquiera y de Primaria/Secundaria. */
function test_las_secciones_filtran_lo_publico_de_primaria_y_secundaria(): void
{
    $where = landing_const('IAREPO_HOME_WHERE');
    foreach (["r.is_active = 1", "r.visibility = 'community'", "r.moderation_status = 'approved'",
              "r.link_status != 'broken'", "'primary'", "'secondary'"] as $cond)
        assert_contains($cond, $where, 'IAREPO_HOME_WHERE');
    assert_not_matches("/'(ib|university|bachillerato|universidad)'/", $where, 'solo Primaria y Secundaria');
}

/**
 * «Para empezar»: principio de palabra, insensible a mayúsculas (la collation
 * de la tabla) y con los acentos cubiertos a mano. Se evalúa con PCRE de PHP,
 * el mismo motor que usa MariaDB para REGEXP.
 */
function test_para_empezar_reconoce_los_titulos_de_introduccion(): void
{
    $rx = '/' . str_replace('/', '\/', landing_const('IAREPO_HOME_BASICS_RX')) . '/iu';
    $si = ['Waves Intro', 'Fractions: Intro', 'Modelo de Áreas: Introducción', 'Forces and Motion: Basics',
           'Escala de pH: Fundamentos', 'Álgebra básica', 'Lo Básico del circuito', 'Introduction to Vectors'];
    $no = ['Superintro', 'Ley de Ohm', 'Retrospectiva', 'Ondas sonoras'];
    foreach ($si as $t)
        assert_true((bool) preg_match($rx, $t), "debería casar «{$t}»");
    foreach ($no as $t)
        assert_false((bool) preg_match($rx, $t), "no debería casar «{$t}»");
}

/** Un fallo de BD en una sección no se calla: se registra (log + client_error_log). */
function test_si_una_seccion_falla_se_registra(): void
{
    $php = landing_php('index.php');
    assert_not_matches('/catch\s*\([^)]*\)\s*\{\s*\}/', $php, 'ningún catch vacío en el PHP de la portada');
    assert_contains('iarepo_page_error_record(', $php, 'el degradado deja constancia en client_error_log');
    preg_match_all('/catch\s*\(Throwable \$e\)\s*\{\s*([^\n]*)/', $php, $m);
    $sinRegistro = array_filter($m[1], static fn($l) => !str_contains($l, 'iarepo_home_degrade(') && !str_contains($l, "\$status['database']"));
    assert_eq([], array_values($sinRegistro), 'cada catch de la portada registra el fallo');
}

/** Sin terceros en la portada ni en el 404 (menores: nadie más se entera de la visita). */
function test_sin_google_fonts_ni_google_sign_in(): void
{
    foreach (['index.php', '404.php'] as $f) {
        $src = landing_src($f);
        assert_not_contains('fonts.googleapis', $src, $f);
        assert_not_contains('fonts.gstatic', $src, $f);
        assert_not_contains('accounts.google.com', $src, $f);
        assert_not_contains('g_id_onload', $src, $f);
    }
}

/**
 * ?lang= es el idioma de la INTERFAZ (shared/i18n.php, cookie de un año); el
 * filtro de idioma del recurso en la URL de la portada es ?rlang=. A la API se
 * le manda 'lang', que allí solo significa filtro.
 */
function test_el_filtro_de_idioma_del_catalogo_va_en_rlang(): void
{
    $js = landing_js('index.php');
    assert_contains("p.set(forUrl ? 'rlang' : 'lang', currentRlang)", $js, 'buildParams(forUrl)');
    assert_contains("p.get('rlang')", $js, 'applyStateFromURL lee rlang');
    assert_not_contains("p.get('lang')", $js, 'la URL de la portada no lee ?lang= como filtro');
    assert_contains("\$_GET['rlang']", landing_php('index.php'), 'el servidor marca el chip desde ?rlang=');
    assert_not_matches('/\$_GET\[\'lang\'\]/', landing_php('index.php'), 'index.php no lee ?lang= como filtro');
}

/** La portada y el 404 usan la base común (cabecera, pie, hoja de estilos). */
function test_portada_y_404_usan_los_componentes_comunes(): void
{
    foreach (['index.php', '404.php'] as $f) {
        $php = landing_php($f);
        assert_matches("#require_once __DIR__ \. '/shared/ui\.php';#", $php, "$f carga shared/ui.php");
        assert_matches('/iarepo_header\(\$\w+,\s*\'[a-z]*\'\)/', $php, "$f llama a iarepo_header");
        assert_contains('iarepo_footer(', $php, "$f llama a iarepo_footer");
        assert_contains('iarepo_head_assets()', $php, "$f carga app.css y theme.js");
        assert_contains('iarepo_body_assets()', $php, "$f carga lucide y ui.js");
        assert_contains('class="ia-page', landing_src($f), "$f: <body class=\"ia-page\">");
    }
    assert_contains("iarepo_header(\$sessionUser, 'explore')", landing_php('index.php'));
    // El conmutador de idioma lo da la cabecera; syncLangSwitch() lo mantiene al día.
    assert_contains("\$('lang-switch')", landing_js('index.php'));
    // El modo «Presentar» de la portada se retiró (duplicaba ids y se colgaba).
    assert_not_contains('present-overlay', landing_src('index.php'));
}

/**
 * Contratos con OTROS ficheros que no fallan ruidosamente si se rompen:
 *  · pwa.js aplica el favorito pendiente del invitado buscando .fav-btn[data-id];
 *  · el 404 manda a /?focus=search y la portada pone el foco en el buscador;
 *  · el formulario del 404 busca con ?search=, el deep-link de la portada.
 */
function test_contratos_con_pwa_js_y_con_el_404(): void
{
    $js  = landing_js('index.php');
    $pwa = landing_src('assets/js/pwa.js');
    assert_contains("'iarepo_pending_fav'", $js, 'la portada guarda la intención con la clave de pwa.js');
    assert_contains("'iarepo_pending_fav'", $pwa);
    assert_contains('.fav-btn[data-id="', $pwa, 'pwa.js marca la estrella por .fav-btn[data-id]');
    assert_matches('/fav-btn[^\n]*data-id="\' \+ rid/', $js, 'y la tarjeta la pinta así');

    $nf = landing_src('404.php');
    assert_contains('href="/?focus=search"', $nf);
    assert_contains("p.has('focus')", $js, 'la portada atiende /?focus=search');
    assert_matches('#<form[^>]*action="/"[^>]*method="get"#', $nf, 'el 404 busca en la portada');
    assert_contains('name="search"', $nf);
}

/** El health check en JSON de index.php sigue ahí y va ANTES de la sesión. */
function test_el_health_check_en_json_sigue_vivo(): void
{
    $php = landing_php('index.php');
    $pos = strpos($php, "'service' => 'iarepo'");
    assert_true($pos !== false, 'index.php responde JSON a Accept: application/json');
    assert_true($pos < (int) strpos($php, 'getSessionUser()'), 'antes de abrir sesión o consultar nada caro');
}

function test_seo_con_una_url_por_idioma(): void
{
    $src = landing_src('index.php');
    foreach (['hreflang="es"', 'hreflang="en"', 'hreflang="x-default"', 'rel="canonical"', 'og:locale', 'application/ld+json'] as $x)
        assert_contains($x, $src);
    assert_contains("'?lang=en'", landing_php('index.php'), 'la versión inglesa es ?lang=en');
}

function test_el_manifest_es_json_valido_con_el_nuevo_posicionamiento(): void
{
    $m = json_decode(landing_src('manifest.webmanifest'), true);
    assert_true(is_array($m), 'manifest.webmanifest es JSON válido');
    foreach (['name', 'short_name', 'description', 'start_url', 'theme_color', 'background_color'] as $k)
        assert_true(!empty($m[$k]), "el manifest trae $k");
    assert_not_matches('/github/i', $m['name'] . ' ' . $m['description']);
    $css = landing_src('assets/css/app.css');
    foreach (['theme_color', 'background_color'] as $k)
        assert_contains(strtoupper($m[$k]), strtoupper($css), "$k es un color de app.css");
}
