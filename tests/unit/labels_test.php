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
