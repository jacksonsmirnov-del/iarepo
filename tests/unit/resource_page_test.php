<?php
// ================================================================
// tests/unit/resource_page_test.php — La ficha y el visor (rediseño 2026-09)
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// resource/index.php es donde de verdad se USA un recurso: el profesor lo
// proyecta o lo manda, el alumno lo abre. El rediseño de 2026-09 fijó
// decisiones de producto que NO fallan ruidosamente si alguien las deshace:
//
//   · Ningún contador público a cero ni congelado como protagonista: «Abierto
//     por N personas» solo si N ≥ 10, «Usado en clase por N docentes» solo si
//     N ≥ 3. Volver a la fila «0 Vistas / 0 Likes / 0 Versiones / 0 Usos» no
//     rompe nada: solo hace que el catálogo parezca abandonado.
//   · «Hacer mi versión» (el fork) solo en html/embed. En un recurso 'url'
//     un fork duplicaría un enlace.
//   · «Mandar a mis alumnos» usa el diálogo común (QR generado en local).
//   · Nada de onclick con texto interpolado: un título con apóstrofo rompía
//     el antiguo onclick="saveToCollection(…, '…')".
//   · El visor no carga pwa.js: su botón «Instalar app» tapaba la proyección.
//   · Los atributos sandbox de los iframes son la frontera entre el código
//     del autor y la sesión de quien mira: un rediseño no los toca.
//
// ── POR QUÉ SE LEE EL FICHERO EN VEZ DE EJECUTARLO ────────────
// Son páginas: abren BD y sesión. Aquí se audita su TEXTO (la red barata, en
// cada push); tests/integration/render_pages_test.php las abre de verdad como
// anónimo, alumno y profesor (bloque «La ficha»).
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Código EJECUTABLE de la ficha (sin comentarios PHP; el HTML se conserva). */
function rp_page_src(): string
{
    static $src = null;
    if ($src === null)
        $src = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/resource/index.php'));
    return $src;
}

/** Código EJECUTABLE del visor. */
function rp_viewer_src(): string
{
    static $src = null;
    if ($src === null)
        $src = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/viewer/index.php'));
    return $src;
}

// ── 1 · Cifras: solo con umbral ─────────────────────────────────

function test_la_prueba_social_tiene_umbral_de_10_y_3(): void
{
    $src = rp_page_src();

    // Los umbrales viven en shared/labels.php desde la integración de 2026-09
    // (antes, RES_PROOF_MIN_* aquí y PF_PROOF_MIN_* en el perfil).
    $labels = iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/shared/labels.php'));
    assert_matches('/const IAREPO_PROOF_MIN_OPENS\s*=\s*10;/', $labels,
        '«Abierto por N personas» solo a partir de 10: por debajo, un número pequeño no ayuda a decidir');
    assert_matches('/const IAREPO_PROOF_MIN_TEACHERS\s*=\s*3;/', $labels,
        '«Usado en clase por N docentes» solo a partir de 3');
    assert_not_matches('/const \w*PROOF_MIN/', $src, 'la ficha no define sus propios umbrales');
    assert_matches('/\$opens\s*=\s*\$viewsLegacy\s*\+\s*\$viewsUnique;/', $src,
        'las aperturas suman el histórico congelado y las visitas únicas (el número no se desploma el día que cambió la medición)');
    assert_matches('/\$viewsLegacy\s*=\s*\(int\)\s*\(\$r\[\'view_count\'\]/', $src, 'histórico = view_count');
    assert_matches('/\$viewsUnique\s*=\s*\(int\)\s*\(\$r\[\'unique_views\'\]/', $src, 'vivo = unique_views');
    assert_matches('/\$showOpens\s*=\s*\$opens\s*>=\s*IAREPO_PROOF_MIN_OPENS;/', $src, 'el umbral de aperturas se aplica');
    assert_matches('/\$showUses\s*=\s*\$uses\s*>=\s*IAREPO_PROOF_MIN_TEACHERS;/', $src, 'el umbral de usos se aplica');
    assert_matches('/<\?php if \(\$showOpens\): \?>/', $src, 'la línea de aperturas depende del umbral');
    assert_matches('/<\?php if \(\$showUses\): \?>/', $src, 'la de usos también');
}

function test_no_vuelve_la_fila_de_contadores_a_cero(): void
{
    $src = rp_page_src();
    assert_not_contains('stat-item', $src, 'la fila de cuatro casillas (0 Vistas / 0 Likes / 0 Versiones / 0 Usos) no vuelve');
    assert_not_matches('/\bLikes\b/', $src, '«Likes» → «Me gusta»');
    assert_not_contains('👁', $src, 'nada de ojos con contador en los listados');
    assert_not_contains('❤', $src, 'ni corazones con contador');
    // El contador de «Me gusta» y el de comentarios se ocultan en cero.
    assert_matches('/id="likeCount"<\?= \$likes > 0 \? \'\' : \' hidden\' \?>/', $src, '«Me gusta» sin número cuando es 0');
    assert_contains('data.total > 0', $src, 'y los comentarios sin «(0)»');
}

function test_nada_se_ordena_por_popularidad(): void
{
    // Aún no hay datos (use_count y unique_views casi a cero): ordenar por
    // ellos es ordenar por azar con aspecto de criterio. view_count, además,
    // está congelado.
    assert_not_matches('/ORDER BY[^;]*\b(like_count|view_count|use_count|unique_views)\b/i', rp_page_src(),
        '«Siguiente paso» y «Más de…» no se ordenan por popularidad');
}

// ── 2 · Acciones según el recurso y el rol ──────────────────────

function test_hacer_mi_version_solo_en_html_o_embed(): void
{
    $src = rp_page_src();
    assert_matches('/\$canFork\s*=\s*in_array\(\$r\[\'code_type\'\],\s*\[\'html\',\s*\'embed\'\],\s*true\);/', $src,
        'el fork solo existe si hay código que editar');
    assert_matches('/<\?php if \(\$canFork\): \?>\s*<li>\s*<button[^>]*id="forkBtn"/', $src,
        'el botón «Hacer mi versión» se pinta SOLO bajo $canFork');
    assert_eq(1, substr_count($src, 'id="forkBtn"'), 'y no hay otro botón de fork suelto');
    assert_not_contains("t('Fork')", $src, '«Fork» ya no es texto visible');
    assert_contains("'/dashboard/editor.php?id=' + data.id", $src, 'al terminar, al editor de la versión nueva');
}

function test_el_alumno_no_ve_acciones_docentes(): void
{
    $src = rp_page_src();
    // El panel «Para docentes» (lo usé en clase, hacer mi versión, insertar)
    // cuelga entero de !$isStudent. La API lo impide igual (requireRole).
    assert_matches('/<\?php if \(!\$isStudent\): \?>\s*<!--[^>]*-->\s*<section class="rf-card" aria-labelledby="teachTitle">/', $src,
        'el panel Para docentes no se pinta para alumnos');
    assert_matches('/<\?php if \(!\$isStudent\): \?>\s*<button[^>]*id="sendBtn"/', $src,
        '«Mandar a mis alumnos» tampoco');
    assert_matches('/<\?php if \(\$isTeacher\): \?>\s*<div class="rf-comment-form">/', $src,
        'el formulario de comentarios es solo para docentes');
    assert_contains("t('Los comentarios son para docentes.')", $src, 'el alumno lee por qué no puede comentar');
    assert_contains("t('Entra para comentar')", $src, 'el anónimo, cómo hacerlo');
}

function test_mandar_a_mis_alumnos_usa_el_dialogo_comun(): void
{
    $src = rp_page_src();
    assert_contains('iarepo_send_dialog();', $src, 'la ficha imprime el diálogo común de shared/ui.php');
    assert_contains('iarepo_body_assets(true)', $src, 'y carga qrcode.js: el QR se genera en el navegador, sin servicios externos');
    assert_contains('IA.openSend({ id: RID', $src, 'el botón abre ese diálogo con el id del recurso');
}

function test_proyectar_es_la_accion_principal(): void
{
    $src = rp_page_src();
    assert_matches('/class="ia-btn ia-btn-primary ia-btn-lg" id="projectBtn"/', $src, '«Proyectar» es el botón principal');
    assert_contains('requestFullscreen', $src, 'un clic y el recurso a pantalla completa');
    assert_contains('stage.dataset.blocked', $src, 'si el sitio no se deja embeber, se abre la web original');
    assert_contains('T.openedOutside', $src, '… y se dice');
    assert_not_contains("t('Pantalla completa')", $src, '«Pantalla completa» → «Proyectar»');
}

// ── 3 · Fallos que ya se vieron ─────────────────────────────────

function test_sin_onclick_con_texto_interpolado(): void
{
    $src = rp_page_src();
    assert_not_contains('saveToCollection(', $src, 'el onclick con el título sin escapar no vuelve');
    assert_not_matches('/\sonclick\s*=/i', $src, 'ningún manejador inline: todo con addEventListener');
    assert_not_matches('/\bfunction esc\(/', $src, 'el escape es IA.esc (escapa también la comilla simple)');
}

function test_los_avisos_usan_ia_toast_y_no_alert(): void
{
    $src = rp_page_src();
    assert_not_contains('shareToast', $src, 'el aviso viejo reutilizaba el último texto');
    assert_not_matches('/\balert\(/', $src, 'nada de alert(): un invitado va a entrar, un error va al aviso');
    assert_contains('IA.toast(', $src, 'avisos con IA.toast');
}

function test_nada_se_ensena_en_crudo(): void
{
    $src = rp_page_src();
    assert_not_contains("h(\$r['visibility'])", $src, '«community» no es una etiqueta');
    assert_not_contains("h(\$r['code_type'])", $src, '«url» / «html» tampoco');
    assert_not_contains("strtoupper(h(\$r['lang']))", $src, 'ni «EN» a secas');
    assert_contains("\$r['level_label']", $src, 'el nivel sale con edades (shared/labels.php)');
    assert_contains('rf_labels($r)', $src, 'y las etiquetas salen de shared/labels.php');
}

function test_la_cuenta_semilla_elige_pero_no_firma(): void
{
    $src = rp_page_src();
    assert_contains("strcasecmp(trim((string) \$r['author_display_name']), 'iarepo') === 0", $src, 'se reconoce la cuenta semilla');
    assert_matches('/\$authorHasProfile\s*=[^;]*!\$isSeedAuthor;/', $src, '«Más de…» y el enlace al perfil, solo para personas');
    assert_contains("t('Elegido por')", $src, '«Elegido por iarepo», no «Autor: iarepo»');
    assert_contains("t('Creado por')", $src, 'la fuente original firma');
}

function test_sin_dependencias_externas_ni_login_de_google_en_la_pagina(): void
{
    foreach (['ficha' => rp_page_src(), 'visor' => rp_viewer_src()] as $k => $src) {
        assert_not_contains('fonts.googleapis.com', $src, "$k: fuentes del sistema (app.css), no Google Fonts");
        assert_not_contains('accounts.google.com/gsi', $src, "$k: entrar es un enlace a /auth/signin.php");
    }
    $src = rp_page_src();
    assert_contains('iarepo_header(', $src, 'cabecera común');
    assert_contains('iarepo_footer(', $src, 'pie común');
    assert_contains('iarepo_head_assets()', $src, 'hoja común');
}

// ── 4 · Lo que el rediseño NO podía tocar ───────────────────────

function test_los_sandbox_no_cambian(): void
{
    foreach (['resource/index.php' => rp_page_src(), 'viewer/index.php' => rp_viewer_src()] as $f => $src) {
        assert_eq(1, substr_count($src, 'sandbox="allow-scripts allow-modals allow-popups"'), "$f: html con su sandbox de siempre");
        assert_eq(1, substr_count($src, 'sandbox="allow-scripts allow-modals allow-popups allow-forms"'), "$f: embed con el suyo");
        assert_not_matches('/sandbox="[^"]*allow-same-origin/', $src, "$f: nunca allow-same-origin junto a allow-scripts");
    }
}

function test_la_visibilidad_es_la_de_la_api(): void
{
    assert_contains('canView($r, $user)', rp_page_src(), 'la ficha usa canView() (shared/access.php)');
    assert_contains('canView($resource, $user)', rp_viewer_src(), 'el visor también');
}

function test_solo_urls_http_en_iframes_y_enlaces(): void
{
    // code_content y source_url vienen de la BD: un «javascript:» en el src de
    // un iframe se ejecuta con el origen de la página.
    // Desde la revisión 2026-09 las dos delegan en iarepo_safe_http_url()
    // (shared/labels.php), que además rechaza «\\» y «usuario@» (PHP y el
    // navegador leían hosts distintos). El filtro de esquema vive allí.
    assert_contains('iarepo_safe_http_url($u)', rp_page_src(), 'la ficha filtra el esquema');
    assert_contains('iarepo_safe_http_url($u)', rp_viewer_src(), 'el visor también');
    assert_contains("preg_match('#^https?://#i', \$url)", (string) file_get_contents(IAREPO_ROOT . '/shared/labels.php'),
        'y iarepo_http_host() exige http(s)');
    assert_not_matches('/src="<\?= h\(\$r\[\'code_content\'\]\)/', rp_page_src(), 'el iframe de url no usa code_content en crudo');
}

// ── 5 · El visor ────────────────────────────────────────────────

function test_el_visor_no_carga_pwa_js(): void
{
    assert_not_contains('pwa.js', rp_viewer_src(), 'su botón «Instalar app» tapaba la proyección');
    assert_not_contains('manifest.webmanifest', rp_viewer_src(), 'ni el manifiesto que lo dispara');
}

function test_el_visor_tiene_modo_proyector(): void
{
    $src = rp_viewer_src();
    assert_contains('id="fsBtn"', $src, 'botón discreto de pantalla completa');
    assert_matches("/classList\\.add\\('is-idle'\\);\\s*\\},\\s*3000\\)/", $src, 'que se esconde tras 3 s sin mover el ratón');
    assert_contains("e.key === 'Escape'", $src, 'y Esc para salir');
    assert_contains('data-surface="viewer"', $src, 'el visor sigue midiendo con el beacon');
}

function test_el_visor_habla_el_idioma_de_la_interfaz(): void
{
    $src = rp_viewer_src();
    assert_eq(2, substr_count($src, '<html lang="<?= lang() ?>">'), 'la página y la de error declaran el idioma real');
    assert_not_contains('<html lang="es">', $src, 'no un «es» fijo');
    assert_not_matches("/showViewerError\\(\\d+,\\s*'/", $src, 'los errores del visor van por t(), no en inglés o español fijos');
}

function test_las_capturas_automaticas_pueden_pedir_el_visor_sin_controles(): void
{
    // setup/tools/generate-thumbnails.sh fotografía ?mode=present con Chrome
    // headless: sin ?ui=0, el botón de pantalla completa saldría en cada
    // miniatura (y en cada imagen OG compartida en redes).
    $src = rp_viewer_src();
    assert_contains("(\$_GET['ui'] ?? '') === '0'", $src, 'el visor acepta ?ui=0');
    assert_contains('.no-ui .fs-btn, .no-ui .ext-link { display: none !important; }', $src, 'y entonces no pinta ningún control');
}

// ── Integración 2026-09 ───────────────────────────────────────────
/** Las miniaturas OG se capturan del visor sin controles (?ui=0). */
function test_las_miniaturas_se_capturan_sin_controles_del_visor(): void
{
    $sh = (string) file_get_contents(IAREPO_ROOT . '/setup/tools/generate-thumbnails.sh');
    assert_matches('/URL="\$\{BASE_URL\}\/\$\{ID\}\?mode=present&ui=0"/', $sh,
        'sin ui=0, el botón de pantalla completa y «Abrir en…» salen en cada imagen al compartir');
}
