<?php
// ================================================================
// tests/unit/lists_profile_test.php — Perfil, listas y Guardados (rediseño 2026-09)
//
// Comprobaciones ESTÁTICAS del fuente (sin BD, sin servidor) de
// profile/index.php, collection/index.php y favorites/index.php, más el
// aspecto de legal/terms.php y unsubscribe.php. Lo que solo se ve
// renderizando (el 404 de un alumno, el orden real de una lista, que un
// borrador no se cuela) está en tests/integration/render_pages_test.php,
// bloque «Listas y perfil».
//
// Cada test nace de un fallo real o de una decisión tomada:
//   · El perfil de un ALUMNO (menor) se abría para cualquiera en
//     /profile/N, con nombre y avatar, y la página leía su email sin usarlo.
//   · El perfil y las listas pintaban 👁 ❤ 🔄 con ceros en cada tarjeta y
//     sumaban view_count a solas: un contador CONGELADO desde 2026-08-06
//     (CLAUDE.md §6.3). Las cifras públicas van con umbral y sumando
//     unique_views; los umbrales son los de la ficha.
//   · Una lista se enseñaba al revés (lo último añadido, primero) y sin
//     numerar; ahora es una secuencia en el orden en que se armó.
//   · Un borrador metido en una lista pública salía entero en la lista.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

function lp_src(string $rel): string
{
    return (string) file_get_contents(IAREPO_ROOT . '/' . $rel);
}

/** Solo el PHP ejecutable: sin comentarios ni HTML/JS en línea. */
function lp_php(string $rel): string
{
    $out = '';
    foreach (token_get_all(lp_src($rel)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true))
            continue;
        $out .= is_array($tok) ? $tok[1] : $tok;
    }
    return $out;
}

/** El fuente sin comentarios de PHP ni de HTML (lo que puede llegar al navegador o a la BD). */
function lp_code(string $rel): string
{
    return (string) preg_replace('#<!--.*?-->#s', '', iarepo_php_code_only(lp_src($rel)));
}

/** Valor entero de `const NOMBRE = N;` en un fichero, o null si no está. */
function lp_int_const(string $rel, string $name): ?int
{
    return preg_match('/\bconst\s+' . preg_quote($name, '/') . '\s*=\s*(\d+)\s*;/', lp_src($rel), $m) ? (int) $m[1] : null;
}

const LP_PAGES = ['profile/index.php', 'collection/index.php', 'favorites/index.php', 'legal/terms.php', 'unsubscribe.php'];

// ── Perfil ──────────────────────────────────────────────────────

function test_perfil_no_lee_el_email(): void
{
    // No se usa en la página: no hay por qué sacarlo de la BD (y es el
    // perfil de personas que pueden ser menores).
    $php = lp_php('profile/index.php');
    preg_match_all('/SELECT\b.*?\bFROM\b/is', $php, $m);
    assert_true(count($m[0]) > 0, 'profile/index.php hace alguna consulta');
    foreach ($m[0] as $select)
        assert_not_matches('/\bemail\b/i', $select, 'profile/index.php no selecciona el email');
}

function test_perfil_de_alumno_ajeno_responde_404_antes_de_nada(): void
{
    $php = lp_php('profile/index.php');
    // La condición: rol de alumno y quien mira no es él.
    assert_matches(
        '/\$isPrivateStudentProfile\s*=\s*\(\$dbUser\[\'role\'\]\s*\?\?\s*\'\'\)\s*===\s*\'student\'\s*&&\s*!\$isOwn\s*;/',
        $php, 'el perfil calcula «es de un alumno y no eres tú»'
    );
    // La respuesta: el 404 de la web (no una redirección, que confirmaría la cuenta).
    assert_matches(
        '/if\s*\(\$isPrivateStudentProfile\)\s*\{\s*require __DIR__ \. \'\/\.\.\/404\.php\';\s*exit;\s*\}/',
        $php, 'y responde con 404.php'
    );
    // Y lo hace ANTES de leer sus recursos o sus listas.
    $gate = strpos($php, 'if ($isPrivateStudentProfile)');
    foreach (['FROM resources', 'FROM collections'] as $q)
        assert_true($gate !== false && $gate < (int) strpos($php, $q), "el 404 del alumno va antes de «{$q}»");
}

function test_el_perfil_propio_de_un_alumno_no_se_indexa(): void
{
    // Desde la revisión 2026-09 la condición es $indexable (alumno O sin
    // recursos públicos → noindex); el alumno sigue dentro de ella.
    $php = lp_php('profile/index.php');
    assert_matches('/\$indexable\s*=\s*!\$isStudent\s*&&\s*\$resourceCount\s*>\s*0\s*;/', $php,
        'indexable = ni alumno ni perfil sin recursos públicos');
    assert_matches(
        '/<\?php if \(!\$indexable\): \?>\s*<meta name="robots" content="noindex">/',
        lp_src('profile/index.php'),
        'el perfil de un alumno (que solo ve él) lleva noindex'
    );
}

/**
 * [revisión 2026-09] users.role vale 'teacher' por defecto y «Saltar por
 * ahora» lo conserva: un alumno que se saltaba la pregunta tenía un perfil
 * público con su nombre y su foto. Sin nada publicado, 404 salvo para él.
 */
function test_un_perfil_sin_nada_publicado_no_es_publico(): void
{
    $php = lp_php('profile/index.php');
    assert_matches('/if \(!\$isOwn && \$resourceCount === 0\) \{.*?FROM collections WHERE user_id = \? AND is_public = 1.*?404\.php/s', $php,
        'sin recursos ni listas públicas, el perfil ajeno responde 404');
    $gate = strpos($php, 'if (!$isOwn && $resourceCount === 0)');
    assert_true($gate !== false && $gate < (int) strpos($php, 'FROM resources r LEFT JOIN'), 'y lo decide antes de leer sus recursos');
}

function test_perfil_suma_unique_views_y_nunca_view_count_a_solas(): void
{
    $php = lp_php('profile/index.php');
    assert_matches('/SUM\(view_count\s*\+\s*unique_views\)/', $php, 'el total de aperturas suma las dos métricas');
    assert_matches('/\(int\)\$r\[\'view_count\'\]\s*\+\s*\(int\)\$r\[\'unique_views\'\]/', $php, 'y cada tarjeta también');
    // Cada mención de view_count va acompañada de unique_views: nunca sola.
    preg_match_all('/view_count/', $php, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [, $pos])
        assert_contains('unique_views', substr($php, $pos, 60), 'view_count aparece sin unique_views al lado (posición ' . $pos . ')');
}

function test_perfil_no_ordena_por_popularidad(): void
{
    // No hay datos de uso todavía (AGENTS.md §6.8): ordenar por ellos aplana
    // o congela el orden. Lo último publicado, primero.
    $php = lp_php('profile/index.php');
    preg_match_all('/ORDER BY[^;"]*/i', $php, $m);
    foreach ($m[0] as $order)
        assert_not_matches('/like_count|view_count|use_count|unique_views|fork_count/i', $order, "orden por popularidad: {$order}");
    assert_contains('ORDER BY r.created_at DESC, r.id DESC', $php, 'los recursos del perfil, del más reciente al más antiguo');
}

function test_los_umbrales_del_perfil_son_los_de_la_ficha(): void
{
    // Dos páginas, una regla: si la ficha sube el umbral y el perfil no, el
    // mismo recurso diría «Abierto por 12 personas» en un sitio y nada en otro.
    // Desde la integración de 2026-09 hay UNA definición (shared/labels.php,
    // IAREPO_PROOF_MIN_*); antes eran PF_PROOF_MIN_* y RES_PROOF_MIN_*.
    foreach (['OPENS' => 10, 'TEACHERS' => 3] as $k => $expected) {
        assert_eq($expected, lp_int_const('shared/labels.php', "IAREPO_PROOF_MIN_$k"), "IAREPO_PROOF_MIN_$k");
        foreach (['profile/index.php', 'resource/index.php'] as $p)
            assert_null(lp_int_const($p, "PF_PROOF_MIN_$k") ?? lp_int_const($p, "RES_PROOF_MIN_$k"),
                "$p no define su propio umbral (vuelve a separarse de la otra página)");
    }
    $php = lp_php('profile/index.php');
    assert_matches('/\$opens\s*>=\s*IAREPO_PROOF_MIN_OPENS/', $php, 'la tarjeta solo dice «Abierto por» por encima del umbral');
    assert_matches('/\$uses\s*>=\s*IAREPO_PROOF_MIN_TEACHERS/', $php, 'y «Usado en clase por» igual');
    assert_matches('/!\$isOwn && \$totalOpens >= IAREPO_PROOF_MIN_OPENS/', $php, 'el total público de aperturas, con umbral');
}

// ── Lista ───────────────────────────────────────────────────────

function test_la_lista_es_una_secuencia_en_orden_de_llegada(): void
{
    $php = lp_php('collection/index.php');
    assert_contains('ORDER BY ci.added_at ASC, ci.id ASC', $php, 'orden en que se añadieron, con desempate estable');
    assert_not_matches('/added_at\s+DESC/i', $php, 'nunca lo último añadido primero');
    assert_contains('<ol class="cl-steps', lp_src('collection/index.php'), 'los pasos son una lista ordenada');
}

function test_la_lista_no_ensena_lo_que_quien_mira_no_puede_ver(): void
{
    $php = lp_php('collection/index.php');
    assert_contains("require_once __DIR__ . '/../shared/access.php';", $php, 'usa la regla común de acceso');
    assert_matches('/if \(canView\(\$row, \$viewer\)\)/', $php, 'cada recurso de la lista pasa por canView()');
    assert_matches('/if \(!\$coll\[\'is_public\'\] && !\$isOwner\)/', $php, 'una lista privada solo la ve su dueño');
    // El nombre de un dueño alumno no se publica (su perfil no es público).
    assert_matches('/\$showOwner\s*=\s*\(\$coll\[\'owner_role\'\] \?\? \'\'\) !== \'student\'/', $php, 'no se nombra a un dueño alumno');
}

function test_la_lista_entera_se_manda_a_los_alumnos(): void
{
    $src = lp_src('collection/index.php');
    assert_matches('/\$canSend\s*=\s*!\$isStudentViewer && \(bool\)\$coll\[\'is_public\'\]/', $src,
        'se ofrece a quien no es alumno y solo si la lista es pública (una privada no la abriría nadie)');
    assert_contains('iarepo_send_dialog()', $src, 'con el diálogo común');
    assert_contains('iarepo_body_assets($canSend)', $src, 'y el QR (qrcode.js) cuando hace falta');
    assert_matches("#IA\\.openSend\\(\\{ path: '/collection/\\?id=' \\+ COLL_ID#", $src, 'la dirección es la de la lista, no la de un recurso');
}

// ── Guardados ───────────────────────────────────────────────────

function test_guardados_no_pinta_contadores(): void
{
    $code = lp_code('favorites/index.php');
    foreach (['view_count', 'like_count', 'fork_count', '👁', '❤'] as $x)
        assert_not_contains($x, $code, "favorites/index.php no pinta {$x}");
    assert_contains("t('Guardados')", $code, 'se llama «Guardados»');
    assert_contains("t('Solo tú los ves.')", $code, 'y dice que solo los ves tú');
    assert_matches('/WHERE rf\.user_id = \?/', $code, 'estrictamente los de la sesión');
}

// ── Las cinco páginas ───────────────────────────────────────────

function test_las_paginas_de_listas_usan_la_base_comun(): void
{
    foreach (LP_PAGES as $p) {
        subtest($p, static function () use ($p): void {
            $code = lp_code($p);
            assert_not_contains('fonts.googleapis', $code, "$p: sin Google Fonts (avisaba a un tercero de cada visita)");
            assert_contains('<body class="ia-page">', $code, "$p: body.ia-page");
            foreach (['iarepo_head_assets()', 'iarepo_header(', 'iarepo_footer(', 'iarepo_body_assets('] as $fn)
                assert_contains($fn, $code, "$p: usa {$fn}");
            // El tema lo lleva la cabecera común ([data-theme-toggle], assets/js/theme.js).
            assert_not_contains('themeBtn', $code, "$p: sin conmutador de tema propio");
            assert_not_matches("/localStorage\\.setItem\\('iarepo-theme'/", $code, "$p: sin guardar el tema por su cuenta");
            // Contadores con emoji delante de una cifra (👁 <?= … o 👁 ${…}). El
            // «Hecho con ❤️» del pie del texto legal es texto, no un contador.
            assert_not_matches('/[👁❤🔄📦📁]\x{FE0F}?\s*(<\?=|\$\{)/u', $code, "$p: sin contadores con emoji");
        });
    }
}

function test_la_linea_de_datos_de_las_tarjetas_es_una_sola(): void
{
    // lp_meta() vivió copiada en las tres páginas (con un test que exigía que
    // las copias fueran idénticas). Desde la integración de 2026-09 es
    // iarepo_card_meta() en shared/ui.php, con su CSS en app.css: ninguna
    // página vuelve a tener su copia.
    foreach (['profile/index.php', 'collection/index.php', 'favorites/index.php'] as $p) {
        $src = lp_src($p);
        assert_not_matches('/\nfunction \w*_meta\(/', $src, "$p define su propia línea de datos: usa iarepo_card_meta()");
        assert_contains('iarepo_card_meta(', $src, "$p pinta la línea de datos con iarepo_card_meta()");
        assert_contains('ia-cards-meta', $src, "$p envuelve sus tarjetas en .ia-cards-meta (app.css)");
    }
    $css = lp_src('assets/css/app.css');
    foreach (['.ia-cards-meta .ia-card-meta', '.ia-meta-src', '.ia-meta {'] as $rule)
        assert_contains($rule, $css, "app.css define $rule");

    require_once IAREPO_ROOT . '/shared/ui.php';
    $r = ['level_label' => 'Secundaria · 12–16 años', 'lang_label' => 'En español', 'lang' => 'es',
          'source_label' => 'PhET', 'author_display_name' => 'Ana <b>'];
    $out = iarepo_card_meta($r);
    assert_contains('<span class="ia-meta-src"><span class="ia-meta">PhET</span>', $out, 'con fuente, la fuente abre la línea');
    assert_contains('ia-lang-es', $out, 'el español se resalta');
    assert_not_contains('Ana', $out, 'con fuente no se nombra al autor');
    $noSrc = iarepo_card_meta(['source_label' => null] + $r);
    assert_contains('Ana &lt;b&gt;', $noSrc, 'sin fuente, el autor, escapado');
    assert_not_contains('Ana', iarepo_card_meta(['source_label' => null] + $r, false), 'sin autor si no se pide');
}

function test_la_baja_en_un_clic_sigue_antes_del_html(): void
{
    // RFC 8058 (List-Unsubscribe-Post): el cliente de correo hace un POST y
    // espera un 200 sin interacción. El rediseño no puede moverlo detrás del HTML.
    $src  = lp_src('unsubscribe.php');
    $post = strpos($src, "\$_SERVER['REQUEST_METHOD'] === 'POST'");
    assert_true($post !== false && $post < (int) strpos($src, '<!DOCTYPE html>'), 'el POST de baja se atiende antes de pintar nada');
    assert_contains("echo 'Unsubscribed';", $src, 'y responde en texto plano');
}

function test_el_texto_legal_solo_existe_en_espanol_y_se_marca_asi(): void
{
    $src = lp_src('legal/terms.php');
    assert_contains('<main id="main" class="ia-container legal" lang="es">', $src,
        'el texto legal va marcado lang="es" aunque la interfaz esté en inglés');
    assert_contains("t('Este texto legal solo está disponible en español.')", $src, 'y en inglés se avisa');
}
