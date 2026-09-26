<?php
// ================================================================
// tests/unit/review_fixes_test.php — Lo que encontró la revisión 2026-09 (fuente)
//
// La otra mitad de tests/integration/review_fixes_test.php: lo que se puede
// fijar sin levantar el sitio. Lógica pura que se EJECUTA (qué petición es de
// otra web, qué dirección es limpia, qué etiqueta de visibilidad se enseña) y
// comprobaciones estáticas del fuente para lo que solo vive en CSS/JS
// (el «Salir» de escritorio, el enlace de salto visible, el aviso dentro del
// diálogo, el QR en iPhone, el contraste del verde…). Cada test nombra el
// fallo que reproduce: si alguien deshace el arreglo, este fichero lo dice.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

require_once IAREPO_ROOT . '/shared/labels.php';
require_once IAREPO_ROOT . '/shared/ui.php';

/** Código ejecutable de un fichero (sin comentarios PHP; HTML y JS se conservan). */
function rfx_src(string $rel): string
{
    static $cache = [];
    return $cache[$rel] ??= iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/' . $rel));
}

function rfx_raw(string $rel): string
{
    return (string) file_get_contents(IAREPO_ROOT . '/' . $rel);
}

/** Ejecuta $code (PHP sin «<?php») en un subproceso y devuelve su JSON. */
function rfx_run(string $code): mixed
{
    $r = iarepo_php_isolated("<?php\n" . $code);
    if ($r['code'] !== 0)
        test_fail('el subproceso falló: ' . trim($r['out'] . $r['err']));
    return json_decode($r['out'], true);
}

// ── CSRF ─────────────────────────────────────────────────────────

/**
 * La regla que decide si una escritura viene de otra web. Se ejecuta con las
 * cabeceras que pondría cada navegador: la sesión no debe contar en ninguna
 * escritura ajena, y sí en las propias y en las que no traen cabeceras de
 * navegador (curl, Campus por JWT).
 */
function test_una_escritura_de_otra_web_se_reconoce_por_sus_cabeceras(): void
{
    $cases = [
        'GET ajeno (no escribe)'       => ['GET',  ['HTTP_ORIGIN' => 'https://evil.example'], false],
        'POST mismo origen (fetch)'    => ['POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => 'https://iarepo.com'], false],
        'POST tecleado (none)'         => ['POST', ['HTTP_SEC_FETCH_SITE' => 'none'], false],
        'POST cross-site'              => ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example'], true],
        'POST same-site (subdominio)'  => ['POST', ['HTTP_SEC_FETCH_SITE' => 'same-site'], true],
        'DELETE Origin ajeno'          => ['DELETE', ['HTTP_ORIGIN' => 'https://evil.example'], true],
        'POST Origin null (sandbox)'   => ['POST', ['HTTP_ORIGIN' => 'null'], true],
        'POST Origin propio'           => ['POST', ['HTTP_ORIGIN' => 'https://iarepo.com'], false],
        'POST Origin con otro puerto'  => ['POST', ['HTTP_ORIGIN' => 'https://iarepo.com:8443'], true],
        'POST sin cabeceras (curl)'    => ['POST', [], false],
        'PUT Origin de un subdominio'  => ['PUT', ['HTTP_ORIGIN' => 'https://evil.iarepo.com'], true],
        'POST puerto por defecto'      => ['POST', ['HTTP_ORIGIN' => 'https://iarepo.com:443'], false],
    ];
    $code = 'require ' . var_export(IAREPO_ROOT . '/shared/auth.php', true) . ";\n"
          . '$out = [];' . "\n"
          . 'foreach (' . var_export($cases, true) . ' as $k => [$m, $h]) {' . "\n"
          . '  $_SERVER = ["REQUEST_METHOD" => $m, "HTTP_HOST" => "iarepo.com"] + $h;' . "\n"
          . '  $out[$k] = iarepo_is_cross_site_write();' . "\n"
          . '}' . "\n"
          . 'echo json_encode($out);';
    $got = rfx_run($code);
    foreach ($cases as $k => [, , $want])
        assert_eq($want, $got[$k] ?? null, "«{$k}»");
}

function test_la_sesion_no_autentica_escrituras_de_otra_web(): void
{
    $auth = rfx_src('shared/auth.php');
    assert_matches('/function authenticateSession\(\).*?if \(iarepo_is_cross_site_write\(\)\) \{.*?error_log\(.*?return null;/s', $auth,
        'authenticateSession() ignora la sesión (y lo registra) en una escritura de otra web: la API responde 401');
    assert_matches('/function authenticate\(\).*?authenticateJWT\(\).*?authenticateSession\(\)/s', $auth,
        'el JWT (Campus) va antes y no se ve afectado');
}

function test_el_cambio_de_rol_exige_el_token_de_la_sesion(): void
{
    $onb = rfx_src('auth/onboarding.php');
    assert_matches("/\\\$_SERVER\\['REQUEST_METHOD'\\] === 'POST'\\s*&& \\(iarepo_is_cross_site_write\\(\\) \\|\\| !iarepo_csrf_valid\\(\\\$_POST\\['csrf'\\] \\?\\? null\\)\\)/", $onb,
        'el POST de la bienvenida comprueba origen y token');
    assert_contains('http_response_code(403);', $onb, 'y sin ellos responde 403');
    $post = strpos($onb, 'iarepo_csrf_valid(');
    $update = strpos($onb, 'UPDATE users SET role');
    assert_true($post !== false && $update !== false && $post < $update, 'la comprobación va ANTES del UPDATE del rol');
    assert_contains('name="csrf" value="<?= h(iarepo_csrf_token()) ?>"', $onb, 'el formulario de la bienvenida lleva el token');
    assert_contains('name="csrf" value="<?= h(iarepo_csrf_token()) ?>"', rfx_src('profile/index.php'), 'y el de «Uso iarepo como» del perfil');

    $got = rfx_run('require ' . var_export(IAREPO_ROOT . '/shared/auth.php', true) . ";\n"
        . '$_SESSION = []; $t = iarepo_csrf_token();' . "\n"
        . 'echo json_encode([strlen($t), iarepo_csrf_token() === $t, iarepo_csrf_valid($t), iarepo_csrf_valid(""), '
        . 'iarepo_csrf_valid(null), iarepo_csrf_valid([$t]), iarepo_csrf_valid(strtoupper($t) . "x")]);');
    assert_eq([32, true, true, false, false, false, false], $got, 'token de 32 hex, estable en la sesión, y solo vale el suyo');
}

// ── Privacidad de menores ────────────────────────────────────────

function test_un_me_gusta_de_quien_aprende_no_guarda_ni_ensena_su_nombre(): void
{
    $likes = rfx_src('api/likes.php');
    assert_contains("\$isLearner = (\$user['role'] ?? '') === 'student';", $likes, 'la API sabe si quien da «Me gusta» está aprendiendo');
    assert_contains("\$isLearner ? null : \$user['name']", $likes, 'y entonces no guarda su nombre');
    assert_contains("\$isLearner ? '' : (string) \$user['name']", $likes, 'ni lo manda en el correo al autor');
    foreach (['dashboard/index.php', 'api/notifications.php'] as $f)
        assert_contains("IF(u.role = 'student', NULL, rl.user_name) AS actor", rfx_src($f), "$f oculta también el nombre de las filas de antes");
}

// ── Direcciones: una sola regla ──────────────────────────────────

function test_una_direccion_limpia_la_leen_igual_php_y_el_navegador(): void
{
    $cases = [
        'https://phet.colorado.edu/sims/html/x/latest/x_es.html' => 'phet.colorado.edu',
        'HTTP://WWW.GeoGebra.org/m/abc'                          => 'www.geogebra.org',
        'https://example.com/p/@usuario'                         => 'example.com',    // «@» en la ruta: no es usuario
        'https://evil.example\\@phet.colorado.edu/sims/x'        => null,             // PHP: phet; navegador: evil
        'https://evil.example@phet.colorado.edu/x'               => null,
        'https://u:p@phet.colorado.edu/x'                        => null,
        "https://phet.colorado.edu/\tx"                          => null,
        'https://exa mple.org/'                                  => null,
        'https://[::1]/'                                         => null,
        'https://ev_il.example/'                                 => null,
        'javascript:alert(1)'                                    => null,
        '//evil.example/x'                                       => null,
        ''                                                       => null,
    ];
    foreach ($cases as $url => $host) {
        subtest(iarepo_show($url), static function () use ($url, $host): void {
            assert_eq($host, iarepo_http_host($url));
            assert_eq($host === null ? '' : trim($url), iarepo_safe_http_url($url));
        });
    }
    // La etiqueta de la fuente sale del MISMO host que carga el navegador.
    assert_null(iarepo_source_label(['code_type' => 'url', 'code_content' => 'https://evil.example\\@phet.colorado.edu/x']),
        'no se firma «PhET» con un iframe que carga otra web');
    foreach (['resource/index.php' => 'iarepo_safe_http_url($u)', 'viewer/index.php' => 'iarepo_safe_http_url($u)',
              'api/resources.php' => 'iarepo_http_host($v) !== null'] as $f => $needle)
        assert_contains($needle, rfx_src($f), "$f usa la regla común");
}

function test_cambiar_solo_el_tipo_a_enlace_valida_la_direccion(): void
{
    $api = rfx_src('api/resources.php');
    assert_matches("/\\\$typeBecomesUrl = \\\$effectiveType === 'url' && \\(string\\) \\\$res\\['code_type'\\] !== 'url';/", $api,
        'el PUT detecta que el recurso PASA a ser un enlace');
    assert_matches("/if \\(\\\$typeBecomesUrl && \\\$putCode === null\\) \\{\\s*\\\$link = iarepo_clean_source_url\\(\\(string\\) \\\$res\\['code_content'\\]\\);/", $api,
        'y valida su contenido como dirección aunque no venga code_content');
    assert_matches('/\(\$typeBecomesUrl\s*\|\|\s*\(\$u !== \(string\) \$res\[\'code_content\'\]/', $api,
        'y lo pasa por la lista negra aunque la dirección «no cambie»');
    assert_contains("!preg_match('#^https?://#i', \$url)", rfx_src('cron/run.php'), 'el cron de enlaces solo le pasa http(s) a curl');
}

function test_editar_un_enlace_no_pisa_su_fuente(): void
{
    $ed = rfx_src('dashboard/editor.php');
    assert_contains('if (!EDIT_ID || !ORIG.sourceUrl || ORIG.sourceUrl === ORIG.code) body.source_url = url;', $ed,
        'al editar, source_url solo viaja si seguía a la dirección');
}

// ── Lo que dice la página ────────────────────────────────────────

function test_restringido_con_tenant_0_no_promete_tu_centro(): void
{
    // t(): el idioma de este proceso lo fija el primer test que lo pide
    // (lang() cachea en un static, CLAUDE.md §6.4).
    assert_eq(t('Con cuenta en iarepo'), iarepo_restricted_label('school', 0), 'school con tenant 0: lo ve cualquiera con cuenta');
    assert_eq(t('Con cuenta en iarepo'), iarepo_restricted_label('area', 0), 'area con tenant 0: igual');
    assert_eq(t('Solo para tu centro'), iarepo_restricted_label('school', 7), 'con un centro de verdad (Campus), sí');
    assert_eq(t('Solo para tu departamento'), iarepo_restricted_label('area', 7));
    assert_contains("iarepo_restricted_label((string) \$r['visibility'], (int) \$r['author_tenant_id'])", rfx_src('resource/index.php'), 'la ficha la usa');
    assert_contains('iarepo_restricted_label($vis, $tenant)', rfx_src('dashboard/index.php'), 'y Mi panel también (dicen lo mismo)');
}

function test_el_original_de_una_version_pasa_por_canview(): void
{
    assert_matches('/\$lineageRoot = \$rStmt->fetch\(\) \?: null;\s*if \(\$lineageRoot && !canView\(\$lineageRoot, \$user\)\)\s*\$lineageRoot = null;/',
        rfx_src('resource/index.php'), 'un original que ya no es público no se enseña desde sus versiones');
}

function test_el_siguiente_paso_prefiere_el_idioma_del_recurso(): void
{
    $src = rfx_src('resource/index.php');
    assert_contains("' ORDER BY (r.lang = ?) DESC, (r.lang = ?) DESC'", $src, 'primero el idioma del recurso, luego el de la interfaz');
    assert_contains("COALESCE(r.topic_tag = ?, 0) DESC", $src, 'el tema, con COALESCE (topic_tag NULL no manda al final)');
    assert_contains("array_push(\$params, (string) \$r['lang'], lang());", $src, 'con el idioma del propio recurso como primer criterio');
}

function test_las_filas_de_la_portada_dicen_el_idioma(): void
{
    assert_contains("'<span class=\"home-row-lang'", rfx_src('index.php'), 'cada fila de la portada lleva su idioma');
}

function test_los_recuentos_de_materia_no_mienten_con_filtros(): void
{
    $idx = rfx_src('index.php');
    assert_contains("\$('categories').classList.toggle('home-counts-off', !!(q || currentLevel || currentRlang));", $idx,
        'con búsqueda, curso o idioma activos se ocultan los recuentos del catálogo entero');
    assert_contains('#categories.home-counts-off .ia-count { display: none; }', $idx, 'y el CSS los esconde');
}

function test_json_en_un_script_no_puede_cerrar_el_script(): void
{
    // Un título con «<!--<script» dejaba la ficha en blanco: json_encode sin
    // JSON_HEX_TAG dentro de <script>. Toda llamada con datos (no un t() ni
    // lang()) lleva JSON_HEX_TAG.
    foreach (['resource/index.php', 'dashboard/editor.php'] as $f) {
        $src = rfx_src($f);
        preg_match_all('/<\?= json_encode\((.*?)\) \?>/s', $src, $m);
        assert_true(count($m[1]) >= 3, "$f: el escáner ve sus json_encode");
        foreach ($m[1] as $arg) {
            if (preg_match('/^(t\(|lang\(\))/', $arg))
                continue;
            assert_contains('JSON_HEX_TAG', $arg, "$f: json_encode(" . substr(trim($arg), 0, 40) . '…) lleva JSON_HEX_TAG');
        }
    }
}

// ── Cabecera, pie y avisos ───────────────────────────────────────

function test_con_sesion_se_puede_salir_tambien_en_escritorio(): void
{
    $_SERVER['REQUEST_URI'] = '/';
    ob_start();
    iarepo_header(['id' => 7, 'name' => 'Ana Docente', 'role' => 'teacher'], '');
    $html = (string) ob_get_clean();
    $mobile = preg_match('#<nav class="ia-container ia-mobile-nav".*?</nav>#s', $html, $m) ? $m[0] : '';
    assert_contains('class="ia-btn ia-btn-ghost ia-btn-sm ia-logout" href="/auth/logout.php"', str_replace($mobile, '', $html),
        '«Salir» está en la cabecera, fuera del menú móvil (que se oculta desde 900 px)');
    $css = rfx_raw('assets/css/app.css');
    assert_matches('/@media \(min-width: 900px\) \{\s*\.ia-nav \{ display: flex; \}\s*\.ia-menu-btn[^}]*\}\s*\.ia-page \.ia-logout \{ display: inline-flex; \}/', $css,
        'y se ve justo cuando el menú desaparece');

    ob_start();
    iarepo_header(null, '');
    $anon = (string) ob_get_clean();
    assert_not_contains('/auth/logout.php', $anon, 'sin sesión no hay «Salir»');
    assert_contains('href="/#listos"', $anon, 'sin sesión, «Para docentes» lleva a «Listos para clase», no a Entrar');
}

function test_saltar_al_contenido_se_ve_al_enfocarlo(): void
{
    $_SERVER['REQUEST_URI'] = '/';
    ob_start();
    iarepo_header(null, '');
    $html = (string) ob_get_clean();
    assert_contains('<a class="ia-sr-only ia-skip" href="#main">', $html, 'el enlace de salto lleva .ia-skip');
    assert_matches('/\.ia-page \.ia-skip:focus, \.ia-page \.ia-skip:focus-visible \{[^}]*position: fixed !important;[^}]*width: auto;[^}]*clip: auto;/s',
        rfx_raw('assets/css/app.css'), 'y al recibir el foco deja de ser un punto de 1×1 px (WCAG 2.4.7)');
}

function test_la_cabecera_cabe_en_360_y_320_px(): void
{
    $css = rfx_raw('assets/css/app.css');
    assert_matches('/@media \(max-width: 379px\) \{[^}]*\.ia-header-inner \{ gap: 6px; \}.*?\.ia-lang-code \{ display: none; \}/s', $css,
        'por debajo de 380 px la cabecera se aprieta y el idioma se queda en el globo');
    assert_matches('/@media \(max-width: 339px\) \{ \.ia-logo span \{ display: none; \} \}/', $css, 'y por debajo de 340 px, el logo sin texto');
    $_SERVER['REQUEST_URI'] = '/';
    ob_start();
    iarepo_header(null, '');
    $html = (string) ob_get_clean();
    assert_contains('<a class="ia-logo" href="/" aria-label="iarepo">', $html, 'el logo conserva su nombre accesible sin el texto');
    assert_matches('#<span class="ia-lang-code">(EN|ES)</span>#', $html, 'el código de idioma va en su propio span');
}

function test_el_aviso_se_ve_encima_de_un_dialogo_abierto(): void
{
    $js = rfx_raw('assets/js/ui.js');
    assert_contains("document.querySelector('dialog:modal')", $js, 'IA.toast busca el diálogo modal abierto');
    assert_matches('/if \(dlg && el\.parentNode !== dlg\) \{\s*dlg\.appendChild\(el\);/', $js, 'y mete el aviso DENTRO mientras dure');
    assert_contains("dlg.addEventListener('close', function () { document.body.appendChild(el); }, { once: true });", $js, 'y lo devuelve al cerrarse');
}

function test_proyectar_el_qr_funciona_sin_pantalla_completa(): void
{
    $js = rfx_raw('assets/js/ui.js');
    $at = strpos($js, "q('[data-send-project]').onclick");
    $block = $at === false ? '' : substr($js, $at, 1400);
    $close = strpos($block, 'dlg.close()');
    $open  = strpos($block, "full.classList.add('is-open')");
    assert_true($close !== false && $open !== false && $close < $open, 'primero se cierra el diálogo (tapaba el QR en iPhone), luego se abre el QR');
    assert_contains('full.requestFullscreen || full.webkitRequestFullscreen', $block, 'y se prueba también la API con prefijo webkit');
}

function test_el_visor_no_tiene_un_cerrar_muerto(): void
{
    $v = rfx_src('viewer/index.php');
    assert_contains('<a class="btn btn-close" id="btnClose" href="/resource/<?= $id ?>"', $v, '«Cerrar» es un enlace a la ficha');
    // La condición lleva además la guarda del iframe (dentro de Campus el botón
    // no existe): lo que se fija es que window.close() depende de window.opener.
    assert_matches('/if \([^)]*window\.opener\) \{.*?window\.close\(\);/s', $v, 'y solo cierra si otra página abrió la pestaña');
    assert_contains('<span class="lbl-narrow"><?= h(t(\'Pantalla completa\')) ?></span>', $v, 'en el móvil, «Pantalla completa» en vez de «Proyectar»');
}

function test_mi_panel_sin_recursos_no_es_un_muro_de_ceros(): void
{
    $d = rfx_src('dashboard/index.php');
    assert_matches('/<\?php if \(\$resources\): \?>\s*<ul class="db-stats"/', $d, 'las cifras solo con algún recurso');
    assert_matches('#<a class="ia-btn ia-btn-ghost" href="/profile/<\?= \$uid \?>" aria-label="<\?= h\(t\(\'Mi perfil público\'\)\) \?>">#', $d,
        'el enlace al perfil tiene nombre aunque el texto se oculte en móvil');
}

function test_el_pie_no_invita_a_publicar_donde_sobra(): void
{
    assert_contains('iarepo_footer($user, false);', rfx_src('dashboard/editor.php'), 'en el editor, sin la banda «Publicar»');
    assert_contains('iarepo_footer(null, false);', rfx_src('auth/signin.php'), 'ni en Entrar');
}

function test_la_baja_de_correos_no_revienta_ni_promete_lo_que_no_hay(): void
{
    $u = rfx_src('unsubscribe.php');
    assert_contains('$token = is_string($token) ? $token : \'\';', $u, '?token[]=x ya no es un TypeError → 500');
    assert_not_contains('desde tu panel', $u, 'no manda a gestionar los correos a un panel que no tiene ese ajuste');
}

// ── Textos y estilo ──────────────────────────────────────────────

function test_el_ingles_es_coherente(): void
{
    $dict = require IAREPO_ROOT . '/shared/i18n_en.php';
    $bad = array_keys(array_filter($dict, static fn($en) => (bool) preg_match('/\bcatalog\b/', $en)));
    assert_eq([], $bad, 'una sola variante: «catalogue» (británico, como «maths»)');
    assert_eq('Present', $dict['Proyectar'] ?? null, '«Project» a secas se leía como sustantivo');
    assert_eq('Full screen', $dict['Pantalla completa'] ?? null, 'y «Full screen», como en «View full screen»');
    assert_contains('álgebra', rfx_src('index.php'), 'la sugerencia «álgebra» lleva su tilde');
}

function test_el_verde_de_publica_pasa_aa(): void
{
    $css = rfx_raw('assets/css/app.css');
    preg_match('/:root \{.*?--ia-ok: (#[0-9A-Fa-f]{6});.*?--ia-ok-soft: (#[0-9A-Fa-f]{6});/s', $css, $m);
    assert_true(isset($m[2]), 'se leen los tokens --ia-ok y --ia-ok-soft del tema claro');
    $lum = static function (string $hex): float {
        $c = array_map(static fn($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
        $c = array_map(static fn($x) => $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$a, $b] = [$lum($m[1]), $lum($m[2])];
    $ratio = (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    assert_true($ratio >= 4.5, sprintf('«Pública» (--ia-ok sobre --ia-ok-soft) da %.2f:1; AA pide 4,5:1', $ratio));
}

function test_los_mensajes_del_gate_no_ejecutan_comillas_invertidas(): void
{
    // Una `…` dentro de "…" en bash es una sustitución de órdenes: el G1 sacaba
    // «syntax error» y el consejo llegaba cortado.
    $bad = [];
    foreach (explode("\n", rfx_raw('quality/guards.sh')) as $n => $line) {
        if (preg_match('/^\s*#/', $line))
            continue;
        if (preg_match('/"[^"$]*`[^"]*"/', $line))
            $bad[] = ($n + 1) . ': ' . trim($line);
    }
    assert_eq([], $bad, 'quality/guards.sh no tiene comillas invertidas dentro de comillas dobles');
}

function test_una_lista_se_recorre_en_su_orden_desde_la_ficha(): void
{
    // El alumno que llegaba por el QR de una lista perdía la secuencia en el
    // primer recurso: la ficha proponía otro «Siguiente paso».
    $col = rfx_src('collection/index.php');
    assert_contains("'<a class=\"ia-card-link\" href=\"/resource/' . \$rid . '?list=' . \$listId . '\"", $col, 'cada paso enlaza con ?list=');
    assert_contains('?list=<?= $id ?>"><i data-lucide="play"', $col, 'y «Empezar por el paso 1» también');
    $res = rfx_src('resource/index.php');
    assert_contains("\$listId = is_string(\$listParam) && ctype_digit(\$listParam) ? (int) \$listParam : 0;", $res, '?list= solo acepta un número');
    assert_matches("/\\(int\\) \\\$list\\['is_public'\\] === 1 \\|\\| \\\$mine/", $res, 'solo con una lista pública o propia');
    assert_contains('static fn($x) => canView($x, $user)', $res, 'los pasos pasan por canView(), como en la página de la lista');
    assert_contains('ORDER BY ci.added_at ASC, ci.id ASC', $res, 'y en el mismo orden');
}
