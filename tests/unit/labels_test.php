<?php
// ================================================================
// tests/unit/labels_test.php — shared/labels.php, shared/asset.php, IA.esc
//
// La base común del rediseño de 2026-09. Cada test fija un fallo que se vio
// en la web en español: categorías en inglés («Más en Mathematics»), niveles
// sin edades, «url» como etiqueta, 30 recursos de PhET firmados «Autor:
// iarepo», y un título con apóstrofo que rompía el JS de la ficha.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

require_once IAREPO_ROOT . '/shared/labels.php';
require_once IAREPO_ROOT . '/shared/asset.php';
require_once IAREPO_ROOT . '/shared/ui.php';

/** Slugs sembrados por las migraciones: todos deben tener etiqueta propia. */
function lbl_seeded_slugs(): array
{
    $sql = '';
    foreach (glob(IAREPO_ROOT . '/setup/*.sql') ?: [] as $f)
        $sql .= file_get_contents($f);
    preg_match_all("/INSERT IGNORE INTO categories[^;]*;/s", $sql, $blocks);
    $slugs = [];
    foreach ($blocks[0] as $b) {
        preg_match_all("/\\('[^']*',\\s*'([a-z0-9-]+)'/", $b, $m);
        $slugs = array_merge($slugs, $m[1]);
    }
    return array_values(array_unique($slugs));
}

function test_cada_categoria_sembrada_tiene_etiqueta_y_color_propios(): void
{
    $slugs = lbl_seeded_slugs();
    assert_true(count($slugs) >= 11, 'se encuentran las categorías sembradas en setup/');
    $css = (string) file_get_contents(IAREPO_ROOT . '/assets/css/app.css');
    foreach ($slugs as $slug) {
        subtest($slug, static function () use ($slug, $css): void {
            assert_neq('Otros', iarepo_category_label($slug), "$slug no puede caer al genérico");
            assert_eq("s-$slug", iarepo_subject_class($slug));
            assert_contains(".s-$slug", $css, "falta el color de $slug en app.css");
        });
    }
}

function test_una_categoria_desconocida_usa_su_nombre_y_un_color_neutro(): void
{
    assert_eq('Robótica', iarepo_category_label('robotica', 'Robótica'));
    assert_eq('s-general', iarepo_subject_class('robotica'));
    assert_eq('s-general', iarepo_subject_class('"><script>'), 'nunca se refleja texto libre en class');
}

function test_los_niveles_llevan_edades_y_aceptan_las_claves_en_espanol(): void
{
    assert_contains('12–16', iarepo_level_label('secondary'));
    assert_eq(iarepo_level_label('secondary'), iarepo_level_label('secundaria'), 'secundaria = secondary');
    assert_eq(iarepo_level_label('ib'), iarepo_level_label('bachillerato'));
    assert_eq(iarepo_level_label('primary', false), iarepo_level_label('Primaria', false));
    assert_eq(['primary', 'secondary', 'ib', 'university'], array_keys(iarepo_level_options()), 'el filtro va en orden de edad');
    assert_eq('', iarepo_level_label(''), 'sin nivel, sin etiqueta');
}

function test_la_fuente_se_deduce_del_dominio_cuando_falta_el_nombre(): void
{
    assert_eq('PhET', iarepo_source_label(['source_name' => '', 'source_url' => 'https://phet.colorado.edu/es/simulations/wave-on-a-string']));
    assert_eq('PhET', iarepo_source_label(['source_name' => 'PhET Interactive Simulations']));
    assert_eq('GeoGebra', iarepo_source_label(['source_url' => 'https://www.geogebra.org/m/abc']));
    assert_eq('ejemplo.org', iarepo_source_label(['source_url' => 'https://www.ejemplo.org/x']));
    assert_null(iarepo_source_label(['source_name' => null, 'source_url' => null]), 'propio: sin sello de fuente');
    assert_eq('PS', iarepo_source_mono('Physics Simulations'));
    assert_eq('Ph', iarepo_source_mono('PhET'));
}

function test_ningun_tipo_se_ensena_en_crudo(): void
{
    foreach (['url', 'html', 'embed', 'prompt', 'python', 'raro'] as $type)
        assert_neq($type, iarepo_opens_label($type), "«{$type}» no puede llegar tal cual a la persona");
}

function test_with_labels_solo_anade_campos(): void
{
    $row = ['id' => 7, 'title' => 'Onda', 'category_slug' => 'physics', 'category_name' => 'Physics',
            'level' => 'secondary', 'code_type' => 'url', 'lang' => 'es', 'source_url' => 'https://phet.colorado.edu/x'];
    $out = iarepo_with_labels($row);
    foreach ($row as $k => $v)
        assert_eq($v, $out[$k], "la columna $k no cambia (contrato con Campus)");
    foreach (['category_label', 'subject_class', 'level_label', 'source_label', 'source_mono', 'opens_label', 'lang_label'] as $k)
        assert_true(array_key_exists($k, $out), "añade $k");
}

function test_asset_versiona_por_contenido_y_solo_dentro_del_repo(): void
{
    $url = iarepo_asset('/assets/css/app.css');
    assert_matches('#^/assets/css/app\.css\?v=[0-9a-f]{8}$#', $url);
    assert_eq('/no/existe.css', iarepo_asset('/no/existe.css'), 'lo que no existe se deja como está');
    assert_eq('/../../etc/passwd', iarepo_asset('/../../etc/passwd'), 'nada fuera de la raíz');
}

/** IA.esc escapa también la comilla simple (el bug del onclick de la ficha). */
function test_ia_esc_escapa_comillas_simples_y_dobles(): void
{
    exec('command -v node 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
        echo "    SKIP node no está instalado\n";
        return;
    }
    $js = 'global.window = {}; global.document = { readyState: "complete", addEventListener(){} };'
        . 'require(' . json_encode(IAREPO_ROOT . '/assets/js/ui.js') . ');'
        . 'process.stdout.write(window.IA.esc("Física de 1º d\'ESO <b>\\"x\\"</b> & más"));';
    $out = shell_exec('node -e ' . escapeshellarg($js) . ' 2>&1');
    assert_eq('Física de 1º d&#39;ESO &lt;b&gt;&quot;x&quot;&lt;/b&gt; &amp; más', $out);
}

// ── Integración del rediseño 2026-09 ─────────────────────────────
// Defectos que vieron los agentes de la portada, la ficha y las listas en
// la base común y que se arreglaron en shared/ (no en cada página).

function test_un_enlace_sin_source_url_firma_con_su_propia_direccion(): void
{
    // 30 recursos antiguos de PhET: code_type 'url', sin source_name ni source_url.
    $full = ['code_type' => 'url', 'code_content' => 'https://phet.colorado.edu/sims/html/x/latest/x_es.html'];
    assert_eq('PhET', iarepo_source_label($full), 'con la fila completa (code_content)');
    assert_eq('PhET', iarepo_source_label(['code_type' => 'url', 'link_url' => $full['code_content']]),
        'con link_url (listados que no traen code_content)');
    assert_null(iarepo_source_label(['code_type' => 'html', 'code_content' => 'https://phet.colorado.edu/']),
        'un HTML propio no firma con una dirección que aparezca en su código');
    assert_null(iarepo_source_label(['code_type' => 'url', 'code_content' => 'javascript:alert(1)//phet.colorado.edu']),
        'de algo que no es http(s) no se deduce ninguna fuente');
    assert_eq('GeoGebra', iarepo_source_label(['code_type' => 'url', 'source_url' => 'https://www.geogebra.org/m/1',
        'code_content' => 'https://phet.colorado.edu/x']), 'source_url sigue mandando');

    // Y los listados traen link_url: sin él, la portada y la API no ven la fuente.
    foreach (['api/resources.php', 'index.php', 'profile/index.php', 'collection/index.php', 'favorites/index.php'] as $rel)
        assert_contains("code_content, NULL) AS link_url", (string) file_get_contents(IAREPO_ROOT . "/$rel"),
            "$rel: el SELECT del listado trae link_url para deducir la fuente de los enlaces");
}

function test_el_sello_de_la_fuente_ignora_los_simbolos_sueltos(): void
{
    assert_eq('NS', iarepo_source_mono('NASA / STScI'), 'daba «N/»');
    assert_eq('PS', iarepo_source_mono('Physics & Simulations'));
    assert_eq('Ph', iarepo_source_mono('PhET'));
    assert_eq('ed', iarepo_source_mono('educaplus.org'), 'un dominio es una sola palabra');
    assert_eq('', iarepo_source_mono(' / '));
}

function test_la_portada_generativa_pinta_un_solo_tema(): void
{
    assert_eq('waves', iarepo_topic_label('waves,introduction'), 'pintaba «WAVES,INTRODU…»');
    assert_eq('ondas', iarepo_topic_label(' , ondas ,luz'));
    assert_eq('', iarepo_topic_label(null));
    assert_eq('waves', iarepo_with_labels(['topic_tag' => 'waves,intro'])['topic_label'] ?? null,
        'la API y las páginas reciben topic_label ya cortado');

    exec('command -v node 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
        echo "    SKIP node no está instalado (IA.cover)\n";
        return;
    }
    $js = 'global.window = {}; global.document = { readyState: "complete", addEventListener(){} };'
        . 'require(' . json_encode(IAREPO_ROOT . '/assets/js/ui.js') . ');'
        . 'process.stdout.write(window.IA.cover({topic_tag: "waves,introduction", category_label: "Física"}));';
    $out = (string) shell_exec('node -e ' . escapeshellarg($js) . ' 2>&1');
    assert_contains('<span class="ia-cover-topic">waves</span>', $out, 'IA.cover (JS) también corta la lista');
}

function test_la_hoja_comun_hace_que_hidden_gane_y_pinta_un_solo_foco(): void
{
    $css = (string) file_get_contents(IAREPO_ROOT . '/assets/css/app.css');
    // .ia-btn, .ia-chip… fijan display y anulaban [hidden] sin avisar: el JS
    // «ocultaba» botones que seguían viéndose. Vivía copiado en 3 páginas.
    assert_matches('/\.ia-page \[hidden\]\s*\{\s*display:\s*none\s*!important;?\s*\}/', $css, '[hidden] gana en app.css');
    // Buscador: el :focus-visible general pintaba un segundo anillo dentro de la píldora.
    assert_matches('/\.ia-search input:focus-visible\s*\{[^}]*outline:\s*none/', $css, 'un solo anillo de foco en .ia-search');
    foreach (['index.php', '404.php', 'resource/index.php'] as $rel)
        assert_not_contains('[hidden] { display: none !important; }', (string) file_get_contents(IAREPO_ROOT . "/$rel"),
            "$rel: la regla de [hidden] vive en app.css, no copiada en la página");
}

/**
 * pwa.js es un .js estático: sus textos llegan traducidos por data-* desde
 * iarepo_pwa_script(). Antes decía «Instalar app» y «Guardado en tus
 * favoritos ⭐» a quien usaba la web en inglés, con el vocabulario viejo, y
 * pintaba un theme-color distinto del de theme.js (parpadeo).
 */
function test_pwa_js_recibe_sus_textos_traducidos_y_no_pisa_el_tema(): void
{
    $pwa = (string) file_get_contents(IAREPO_ROOT . '/assets/js/pwa.js');
    assert_not_contains('favoritos', $pwa, 'vocabulario nuevo: «Guardados»');
    assert_not_contains('theme-color', preg_replace('#^\s*//.*$#m', '', $pwa), 'el theme-color es cosa de theme.js');
    assert_contains('document.currentScript', $pwa, 'lee sus textos del <script> que lo carga');

    $html = iarepo_pwa_script();
    foreach (['data-install', 'data-hide', 'data-saved'] as $attr)
        assert_contains($attr . '="', $html, "iarepo_pwa_script() pasa $attr");

    // Todas las páginas que cargan pwa.js lo hacen por el helper (con textos).
    exec('git -C ' . escapeshellarg(IAREPO_ROOT) . ' ls-files -co --exclude-standard "*.php"', $files);
    foreach ($files as $rel) {
        if (str_starts_with($rel, 'tests/') || $rel === 'shared/ui.php')
            continue;
        $src = (string) file_get_contents(IAREPO_ROOT . '/' . $rel);
        assert_not_matches('#<script[^>]+pwa\.js#', $src, "$rel carga pwa.js a mano: usa iarepo_pwa_script()");
    }
}

/**
 * La captura real se usa cuando EXISTE, y solo entonces.
 *
 * El rediseño de 2026-09 se probó en local, donde no hay capturas
 * (thumbnails/ vive fuera de git), y las tarjetas pasaron a pintar siempre la
 * portada generativa: en producción se habrían perdido las capturas reales.
 * Además no debe pedirse NUNCA una captura que no existe (cada 404 es una
 * ejecución de 404.php por el catch-all de .htaccess).
 */
function test_la_captura_real_se_usa_si_existe_y_si_no_la_portada_generativa(): void
{
    require_once IAREPO_ROOT . '/shared/ui.php';
    $dir     = IAREPO_ROOT . '/thumbnails';
    $made    = !is_dir($dir) && mkdir($dir);
    $id      = 987654321;
    $file    = "$dir/og-$id.png";
    try {
        assert_null(iarepo_thumb($id), 'sin fichero, sin captura');
        $sin = iarepo_cover(['id' => $id, 'category_slug' => 'physics', 'category_icon' => 'atom']);
        assert_not_contains('<img', $sin, 'sin captura no se pide ninguna imagen');
        assert_contains('ia-cover-icon', $sin, 'y queda la portada generativa');

        file_put_contents($file, 'png');
        assert_matches('#^/thumbnails/og-' . $id . '\.png\?v=\d+$#', (string) iarepo_thumb($id), 'con fichero, su URL versionada');
        $con = iarepo_cover(iarepo_with_labels(['id' => $id, 'category_slug' => 'physics', 'category_icon' => 'atom']));
        assert_matches('#<img class="ia-cover-img" src="/thumbnails/og-' . $id . '\.png\?v=\d+"#', $con, 'la tarjeta enseña la captura real');
    } finally {
        @unlink($file);
        if ($made) @rmdir($dir);
    }

    // La versión JS hace lo mismo con r.thumb (que la API solo manda si existe)
    // y no acepta otra cosa que una ruta de thumbnails/.
    exec('command -v node 2>/dev/null', $o, $rc);
    if ($rc !== 0)
        return;
    $js = 'global.window = {}; global.document = { readyState: "complete", addEventListener(){} };'
        . 'require(' . json_encode(IAREPO_ROOT . '/assets/js/ui.js') . ');'
        . 'const c = window.IA.cover;'
        . 'process.stdout.write(JSON.stringify(['
        . '  c({id: 5, thumb: "/thumbnails/og-5.png?v=17"}).includes(\'src="/thumbnails/og-5.png?v=17"\'),'
        . '  c({id: 5}).includes("<img"),'
        . '  c({id: 5, thumb: "javascript:alert(1)"}).includes("<img"),'
        . '  c({id: 5, thumb: "https://evil.example/x.png"}).includes("<img")'
        . ']));';
    assert_eq('[true,false,false,false]', trim((string) shell_exec('node -e ' . escapeshellarg($js) . ' 2>&1')));
}
