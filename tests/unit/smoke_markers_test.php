<?php
// ================================================================
// tests/unit/smoke_markers_test.php — Lo que busca el smoke existe en el fuente
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// quality/smoke_test.sh corre contra PRODUCCIÓN después del deploy y, para
// saber que una página se pintó entera, busca un marcador en su HTML
// ('id="catalogo"', 'class="viewer-bar"'…). El rediseño 2026-09 quitó
// 'class="fcard"' de la portada y 'class="preview-card"' de la ficha sin
// tocar el smoke: el gate local seguía verde y el primer aviso habría sido un
// FAIL con la web ya publicada —o peor, alguien «arreglando» un sitio que
// estaba bien—.
//
// Aquí se lee cada `check "…" "/ruta" 200 'marcador'` del smoke cuya ruta es
// una página de este repo y se exige que el marcador aparezca LITERAL en el
// fichero que la sirve. Si cambias un marcador en una página, cambia el smoke
// en el mismo commit (y al revés).
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Ruta pública → fichero que la sirve (las rutas bonitas de .htaccess). */
function sm_page_for(string $path): ?string
{
    $path = (string) parse_url($path, PHP_URL_PATH);
    $map  = ['#^/$#' => 'index.php', '#^/resource/\d+$#' => 'resource/index.php',
             '#^/view/\d+$#' => 'viewer/index.php', '#^/profile/\d+$#' => 'profile/index.php',
             '#^/(collection|favorites|dashboard)/$#' => null];
    foreach ($map as $re => $file) {
        if (preg_match($re, $path, $m))
            return $file ?? $m[1] . '/index.php';
    }
    return null;
}

/** [[desc, ruta, marcador], …] de los check de páginas con 200 y marcador. */
function sm_page_checks(): array
{
    $src = (string) file_get_contents(IAREPO_ROOT . '/quality/smoke_test.sh');
    preg_match_all("/^check\s+\"([^\"]+)\"\s+\"([^\"]+)\"\s+200\s+'([^']+)'/m", $src, $m, PREG_SET_ORDER);
    return array_map(static fn($x) => [$x[1], $x[2], $x[3]], $m);
}

function test_cada_marcador_del_smoke_existe_en_su_pagina(): void
{
    $vistos = 0;
    foreach (sm_page_checks() as [$desc, $path, $marker]) {
        $file = sm_page_for($path);
        if ($file === null)
            continue;   // assets, sitemap, robots…: no son páginas de este repo
        $vistos++;
        assert_true(is_file(IAREPO_ROOT . '/' . $file), "«{$desc}»: no existe $file");
        assert_contains($marker, (string) file_get_contents(IAREPO_ROOT . '/' . $file),
            "«{$desc}» ($path) busca $marker en $file: si el rediseño lo quitó, el smoke dará FAIL en producción");
    }
    // Portada, ficha (2), visor (2) y perfil: si baja de aquí, el parser dejó
    // de ver los check (el test pasaría en vacío).
    assert_true($vistos >= 6, "solo se reconocieron $vistos check de páginas en quality/smoke_test.sh");
}

function test_la_portada_se_comprueba_por_una_seccion_del_servidor(): void
{
    // #listos la calcula el servidor con SQL propio (REGEXP, RAND(TO_DAYS))
    // y desaparece si ese SQL falla: es el marcador que dice que la portada
    // funciona de verdad contra la MariaDB de producción, no solo que carga.
    $markers = array_column(array_filter(sm_page_checks(), static fn($c) => $c[1] === '/'), 2);
    assert_true(in_array('id="listos"', $markers, true), 'el smoke comprueba la sección «Listos para clase» de la portada');
    // [revisión 2026-09] El REGEXP con lookbehind está SOLO en «Para empezar»
    // (su propio try): si falla en producción, #listos sigue saliendo. El
    // marcador que lo vigila es #para-empezar.
    assert_true(in_array('id="para-empezar"', $markers, true), 'el smoke comprueba «Para empezar», la sección del REGEXP');
    $index = (string) file_get_contents(IAREPO_ROOT . '/index.php');
    assert_true((bool) preg_match('/\$basics\s*=\s*iarepo_home_pick\(\$db,\s*\' AND r\.title REGEXP \?\'/', $index),
        'el REGEXP sigue en la consulta de «Para empezar» (si se mueve, hay que mover el marcador)');
    assert_contains('id="para-empezar"', $index, 'y la portada pinta #para-empezar');
}
