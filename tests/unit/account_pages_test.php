<?php
// ================================================================
// tests/unit/account_pages_test.php — Cuenta y autoría (rediseño 2026-09)
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// Mi panel (dashboard/index.php), el editor (dashboard/editor.php), Entrar
// (auth/signin.php), la bienvenida (auth/onboarding.php) y las ramas POST/PUT
// de api/resources.php que usa el editor. Decisiones que NO fallan
// ruidosamente si alguien las deshace:
//
//   · Las cifras del autor son las reales: «Abierto por» = view_count
//     (congelado) + unique_views; «Versiones de otros docentes» cuenta las
//     PÚBLICAS de otros, no fork_count (que incluye borradores y prometía
//     versiones que nadie podía abrir). Volver a fork_count no rompe nada:
//     solo miente.
//   · Vocabulario: nada de Forks / Likes / Dashboard / Colecciones.
//   · El editor pliega lo secundario en «Más opciones», no pide un «Área /
//     Materia» de texto libre y manda source_url (sin ella, la lista negra de
//     URLs retiradas no se aplicaba nunca desde el editor).
//   · source_url solo http(s): acaba en un href de la ficha y en el src del
//     visor. Un javascript: ahí es un XSS guardado.
//   · Los errores de la API se traducen por CÓDIGO, y cada código que puede
//     devolver crear/editar tiene su texto en el editor.
//   · Tu versión de otro recurso (un fork) se DICE en el editor: «Hacer mi
//     versión» aterriza allí con el mismo título que el original, y sin el
//     aviso no se sabe si se toca el original ni quién lo ve. El original se
//     nombra solo si canView() lo deja.
//   · La bienvenida ofrece «Estoy aprendiendo (en clase o por mi cuenta)»,
//     «Saltar» va a la portada, y un admin no se degrada con un clic.
//
// ── CÓMO ──────────────────────────────────────────────────────
// Son páginas y un endpoint: abren BD y sesión. Se audita su TEXTO (sin
// comentarios PHP, con iarepo_php_code_only) y, donde hay lógica pura
// (validar una dirección, iarepo_safe_local_path), se EJECUTA esa función en un
// subproceso con entradas hostiles. tests/integration/render_pages_test.php
// (bloque «Cuenta») y tests/integration/account_api_test.php las prueban de
// verdad con el sitio levantado.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Código ejecutable (sin comentarios PHP; el HTML y el JS se conservan). */
function acc_src(string $rel): string
{
    static $cache = [];
    return $cache[$rel] ??= iarepo_php_code_only((string) file_get_contents(IAREPO_ROOT . '/' . $rel));
}

/** Texto fuente de la función $name de un fichero (para ejecutarla aparte). */
function acc_function_source(string $rel, string $name): string
{
    $toks = token_get_all((string) file_get_contents(IAREPO_ROOT . '/' . $rel));
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_FUNCTION)
            continue;
        $j = $i + 1;
        while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE)
            $j++;
        if (!is_array($toks[$j]) || $toks[$j][1] !== $name)
            continue;
        $out = '';
        $depth = 0;
        $opened = false;
        for ($k = $i; $k < $n; $k++) {
            $t = is_array($toks[$k]) ? $toks[$k][1] : $toks[$k];
            $out .= $t;
            if ($t === '{' || (is_array($toks[$k]) && in_array($toks[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($t === '}') {
                $depth--;
                if ($opened && $depth === 0)
                    return $out;
            }
        }
    }
    test_fail("no encuentro la función $name en $rel");
}

/** Ejecuta $fn (fuente) sobre cada caso en un subproceso; devuelve los resultados. */
function acc_run_pure(string $fnSource, string $call, array $cases): array
{
    $code = "<?php\n" . $fnSource . "\n"
          . '$out = [];' . "\n"
          . 'foreach (' . var_export($cases, true) . ' as $k => $v) $out[$k] = ' . $call . '($v);' . "\n"
          . 'echo json_encode($out);';
    $r = iarepo_php_isolated($code);
    if ($r['code'] !== 0)
        test_fail('el subproceso falló: ' . trim($r['out'] . $r['err']));
    $out = json_decode($r['out'], true);
    if (!is_array($out))
        test_fail('el subproceso no devolvió JSON: ' . iarepo_show($r['out'] . $r['err']));
    return $out;
}

// ── 1 · Mi panel: cifras reales del autor ───────────────────────

function test_el_panel_suma_visitas_unicas_y_no_usa_fork_count(): void
{
    $src = acc_src('dashboard/index.php');
    assert_matches("/\\\$r\\['opens'\\]\\s*=\\s*\\(int\\) \\\$r\\['view_count'\\] \\+ \\(\\\$uniqueViews\\[\\\$rid\\] \\?\\? 0\\);/", $src,
        '«Abierto por» = view_count (congelado) + unique_views, la misma suma que la ficha');
    assert_matches('/\$uniqueViews = \[\];\s*try \{.*?SELECT id, unique_views FROM resources.*?\} catch \(Throwable \$e\) \{\s*error_log\(/s', $src,
        'unique_views en una consulta aparte que degrada Y deja constancia en el log (despliegue antes que migration_012)');
    assert_matches("/\\\$r\\['uses'\\]\\s*=\\s*\\(int\\) \\\$r\\['use_count'\\];/", $src, '«Usos en clase» = use_count');
    assert_not_contains('fork_count', $src,
        'la cifra de versiones NO sale de fork_count: cuenta borradores ajenos que nadie puede abrir');
    assert_matches("/root_id IN \\(\\\$in\\).*visibility = 'community'.*NOT \\(author_user_id = \\? AND author_tenant_id = 0\\)/s", $src,
        '«Versiones de otros docentes» = versiones públicas hechas por OTROS');
    assert_matches('/\(SELECT COUNT\(\*\) FROM resource_likes rl WHERE rl\.resource_id = r\.id\) AS likes/', $src,
        '«Me gusta» se cuenta en resource_likes, no en el contador desnormalizado');
}

function test_el_panel_no_vuelve_al_vocabulario_viejo(): void
{
    $src = acc_src('dashboard/index.php');
    foreach (['/\bForks?\b/' => 'Fork(s)', '/\bLikes?\b/' => 'Like(s)', '/\bDashboard\b/' => 'Dashboard',
              '/Colecci[oó]n/u' => 'Colección', '/👁|❤|🔄|📦|📁/u' => 'emojis de contador'] as $re => $what)
        assert_not_matches($re, $src, "«{$what}» no vuelve al panel");
    assert_contains("t('Mi panel')", $src, 'el título es «Mi panel»');
    assert_contains("t('Mis listas')", $src, 'la pestaña es «Mis listas»');
    assert_contains("t('Versiones de otros docentes')", $src, 'y la cifra de versiones se llama así');
    assert_matches('/function db_visibility\(/', $src, 'la visibilidad se traduce (nunca «draft»/«community» en crudo)');
    assert_not_matches("/h\\(\\\$r\\['(visibility|code_type)'\\]\\)/", $src, 'ni visibilidad ni tipo se pintan en crudo');
    assert_contains("\$r['opens_label']", $src, 'el tipo se dice con iarepo_opens_label()');
}

function test_el_panel_escapa_lo_que_escriben_otros_y_no_usa_onclick(): void
{
    $src = acc_src('dashboard/index.php');
    // Desde la revisión 2026-09 un «Me gusta» de quien está aprendiendo llega
    // sin nombre (actor NULL) y se pinta «Alguien que está aprendiendo»: la
    // expresión cambió, el escape no.
    assert_contains("h((string) (\$a['actor'] ?? '') !== '' ? (string) \$a['actor'] : t('Alguien que está aprendiendo'))", $src,
        'el nombre de quien comenta o da «Me gusta» se escapa (antes iba en crudo)');
    assert_contains("h((string) \$a['resource_title'])", $src, 'y el título');
    assert_contains('IA.esc(n.actor || T.learner)', $src, 'también en la campana');
    assert_not_matches('/<[a-z][^>]*\sonclick=/i', $src, 'sin onclick con texto interpolado: un título con apóstrofo lo rompía');
    assert_matches('/SELECT id, title, description, is_public/', $src,
        'las listas traen su descripción: sin ella, editar una lista la borraba');
}

function test_el_panel_abre_las_listas_con_su_ancla(): void
{
    $src = acc_src('dashboard/index.php');
    assert_contains('/^#(listas|collections)$/', $src, '/dashboard/#listas abre «Mis listas»; #collections sigue valiendo');
    assert_contains('id="panel-listas"', $src, 'la pestaña existe');
    assert_contains("IA.openSend({ path: '/collection/?id='", $src, 'una lista pública se manda a los alumnos con el diálogo común');
}

// ── 2 · Todas las páginas de la cuenta usan la base común ───────

function test_las_paginas_de_la_cuenta_usan_la_base_comun(): void
{
    foreach (['dashboard/index.php', 'dashboard/editor.php', 'auth/signin.php', 'auth/onboarding.php'] as $rel) {
        subtest($rel, static function () use ($rel): void {
            $src = acc_src($rel);
            assert_contains('<body class="ia-page">', $src, 'body.ia-page');
            assert_contains('iarepo_head_assets()', $src, 'app.css + tema en el <head>');
            assert_matches('/iarepo_header\(/', $src, 'cabecera común');
            assert_matches('/iarepo_footer\(/', $src, 'pie común');
            assert_not_contains('fonts.googleapis', $src, 'sin Google Fonts');
            assert_not_contains('themeBtn', $src, 'sin conmutador de tema propio');
            assert_not_matches("/localStorage\\.getItem\\('iarepo-theme'\\)/", $src, 'el tema lo aplica assets/js/theme.js');
            assert_not_matches('/^\s*:root\s*\{/m', $src, 'sin tokens de color propios: son los --ia-* de app.css');
            assert_not_matches('#require_once[^;]*shared/helpers\.php#', $src, 'nunca helpers.php en una página HTML (CLAUDE.md §2.1)');
        });
    }
    assert_contains("iarepo_header(\$user, 'teach')", acc_src('dashboard/index.php'), 'el panel marca «Para docentes»');
    assert_contains("iarepo_header(\$user, 'teach')", acc_src('dashboard/editor.php'), 'el editor también');
}

// ── 3 · El editor ───────────────────────────────────────────────

function test_el_editor_es_corto_y_pliega_lo_secundario(): void
{
    $src = acc_src('dashboard/editor.php');
    assert_matches('#<details class="ed-more" id="more"#', $src, '«Más opciones» es un <details>');
    $more = substr($src, (int) strpos($src, '<details class="ed-more"'), (int) strpos($src, '</details>') - (int) strpos($src, '<details class="ed-more"'));
    foreach (['id="description"', 'id="lang"', 'id="visibility"', 'id="tagInput"', 'id="sourceName"'] as $id)
        assert_contains($id, $more, "{$id} va dentro de «Más opciones»");
    foreach (['id="title"', 'id="codeContent"', 'id="codeUrl"', 'id="categoryId"', 'id="level"'] as $id)
        assert_not_contains($id, $more, "{$id} está a la vista, fuera de «Más opciones»");
    assert_not_contains('id="subjectArea"', $src, 'sin el «Área / Materia» de texto libre duplicado');
    assert_contains('subject_area: subjectArea()', $src, 'subject_area se rellena con la etiqueta de la materia (el buscador lo usa)');
    assert_contains('iarepo_level_options()', $src, 'curso y edad con edades');
    assert_contains('iarepo_category_label(', $src, 'materias con su nombre traducido');
    assert_not_matches("/'school'\\s*=>\\s*t\\(/", $src, 'no se OFRECE «Tu centro»: en iarepo.com el tenant es 0 para todos');
}

function test_el_editor_dice_que_es_tu_version_y_quien_la_ve(): void
{
    $src = acc_src('dashboard/editor.php');
    assert_matches("/\\\$isFork\s*=\s*\\\$isEdit\s*&&\s*\(int\)\s*\(\\\$resource\['fork_of'\]/", $src, 'una copia se reconoce por fork_of');
    assert_matches('/<\?php if \(\$isFork\): \?>\s*<div class="ed-fork" id="forkNote"/', $src, 'y lleva su aviso encima del formulario');
    assert_contains("canView(\$row, authenticate())", $src, 'el original solo se nombra si quien edita puede verlo');
    assert_contains("t('Esta es tu copia: el original no se toca.')", $src, 'dice que el original no se toca');
    assert_matches("/if \\(\\\$currentVis === 'draft'\\):.*?Es un borrador: solo la ves tú/s", $src, 'y, si es borrador, quién la ve y cómo compartirla');

    // La ficha, por su parte, lleva al editor de la copia (no deja al docente
    // en la misma página con un aviso, como antes).
    $ficha = acc_src('resource/index.php');
    assert_matches("#location = '/dashboard/editor\.php\?id=' \+ data\.id#", $ficha, '«Hacer mi versión» abre el editor de la copia');
}

function test_el_editor_manda_la_fuente_y_traduce_errores_por_codigo(): void
{
    $src = acc_src('dashboard/editor.php');
    // Al crear, la dirección viaja como source_url. Al EDITAR, solo si la
    // fuente seguía a la dirección: mandarla siempre pisaba la página del
    // autor (source_url ≠ code_content en 139 de 361 enlaces) con la de la
    // simulación al cambiar solo el título [revisión 2026-09].
    assert_matches('/if \(!EDIT_ID \|\| !ORIG\.sourceUrl \|\| ORIG\.sourceUrl === ORIG\.code\) body\.source_url = url;/', $src,
        'en un enlace, la dirección viaja como source_url sin pisar una fuente distinta al editar');
    assert_not_matches('/\{\s*body\.source_url = url;/', $src, 'nunca incondicional (el fallo de la revisión)');
    assert_matches("/'sourceUrl'\\s*=>\\s*\\\$v\\('source_url'\\)/", $src, 'ORIG lleva la fuente original');
    assert_matches("/'code'\\s*=>\\s*\\\$currentType === 'url' \\? \\\$v\\('code_content'\\)/", $src, 'y la dirección original');
    assert_contains("body.source_name = \$('sourceName').value.trim()", $src, 'y la «Fuente original» como source_name');
    assert_contains('T.err[errCode]', $src, 'el mensaje sale del CÓDIGO de error, no del texto de la API');
    assert_not_matches('/showStatus\([^)]*data\.error/', $src, 'el texto de la API (inglés, para Campus) no llega a la persona');
    assert_matches('/sandbox="allow-scripts allow-modals allow-popups"/', $src, 'la vista previa va en un iframe aislado');
    assert_not_matches('/<iframe[^>]*allow-same-origin/', $src, 'sin allow-same-origin: el HTML pegado no toca la sesión');
    assert_contains("addEventListener('beforeunload'", $src, 'no se pierde el HTML pegado por recargar sin querer');

    // Cada código que pueden devolver crear/editar tiene su texto en el editor.
    $api = acc_src('api/resources.php');
    // Desde el primer json_body() (crear: fork y recommend no leen cuerpo)
    // hasta la rama DELETE, más la función de la lista negra.
    $from = (int) strpos($api, '$data = json_body();');
    $to   = (int) strpos($api, "if (\$method === 'DELETE')");
    $segment = substr($api, $from, $to - $from) . acc_function_source('api/resources.php', 'iarepo_reject_blacklisted_url');
    // El tercer argumento de json_error(msg, http, 'CODIGO'), en una línea o en varias.
    preg_match_all("/,\\s*\\d{3},\\s*'([A-Z_]+)'\\s*\\)/", $segment, $m);
    $codes = array_values(array_unique($m[1]));
    foreach (['INVALID_SOURCE_URL', 'INVALID_URL', 'NOT_AUTHOR', 'BLACKLISTED_URL', 'BLACKLISTED_DOMAIN'] as $witness)
        assert_contains($witness, implode(' ', $codes), "el escáner de códigos ve {$witness} (si no, se ha quedado ciego)");
    preg_match('/err: \{(.*?)\n  \},/s', $src, $errBlock);
    foreach ($codes as $c)
        assert_matches('/\b' . $c . ':/', $errBlock[1] ?? '', "el editor traduce el código {$c}");
}

// ── 4 · api/resources.php: la fuente original ──────────────────

function test_source_url_solo_acepta_http_y_https(): void
{
    // Desde la revisión 2026-09 el host lo decide iarepo_http_host()
    // (shared/labels.php, pura): se carga en el subproceso junto a la función.
    $fn = 'require ' . var_export(IAREPO_ROOT . '/shared/labels.php', true) . ";\n"
        . acc_function_source('api/resources.php', 'iarepo_clean_source_url');
    $cases = [
        'vacío'            => '',
        'null'             => null,
        'phet'             => 'https://phet.colorado.edu/sims/html/x/latest/x_es.html',
        'con espacios'     => '  https://phet.colorado.edu/x  ',
        'http mayúsculas'  => 'HTTP://EXAMPLE.ORG/a',
        'javascript'       => 'javascript:alert(1)',
        'javascript mixto' => ' JaVaScRiPt:alert(document.cookie)',
        'javascript tab'   => "java\tscript:alert(1)",
        'data'             => 'data:text/html,<script>alert(1)</script>',
        'vbscript'         => 'vbscript:msgbox(1)',
        'protocol-relative'=> '//evil.example/x',
        'relativa'         => '/resource/1',
        'sin esquema'      => 'phet.colorado.edu',
        'sin host'         => 'https://',
        'espacio dentro'   => 'https://exa mple.org/',
        'salto de línea'   => "https://example.org/\njavascript:alert(1)",
        'demasiado larga'  => 'https://example.org/' . str_repeat('a', 490),
        'array'            => ['https://example.org/'],
        'número'           => 42,
        // PHP y el navegador leían hosts distintos: PHP «phet.colorado.edu»,
        // el navegador evil.example (trata «\» como «/»). La ficha firmaba
        // «Creado por PhET» con el iframe en otra web [revisión 2026-09].
        'barra invertida'  => 'https://evil.example\\@phet.colorado.edu/sims/x',
        'usuario@'         => 'https://evil.example@phet.colorado.edu/x',
        'usuario:clave@'   => 'https://u:p@phet.colorado.edu/x',
        'host con _'       => 'https://ev_il.example/x',
    ];
    $got = acc_run_pure($fn, 'iarepo_clean_source_url', $cases);
    $want = [
        'vacío' => '', 'null' => '', 'phet' => $cases['phet'], 'con espacios' => 'https://phet.colorado.edu/x',
        'http mayúsculas' => 'HTTP://EXAMPLE.ORG/a',
    ];
    foreach ($cases as $k => $_) {
        subtest($k, static function () use ($k, $got, $want): void {
            assert_true(array_key_exists($k, $got), "el subproceso devolvió «{$k}»");
            assert_eq($want[$k] ?? null, $got[$k], "«{$k}»");
        });
    }
}

function test_source_name_es_texto_de_una_linea_y_acotado(): void
{
    $fn  = acc_function_source('api/resources.php', 'iarepo_clean_source_name');
    $got = acc_run_pure($fn, 'iarepo_clean_source_name', [
        'normal' => '  PhET Interactive Simulations ', 'saltos' => "PhET\r\nInteractive", 'null' => null,
        'largo' => str_repeat('á', 200), 'array' => ['x'],
    ]);
    assert_eq('PhET Interactive Simulations', $got['normal'], 'se recorta');
    assert_eq('PhET Interactive', $got['saltos'], 'los saltos de línea no llegan a la BD');
    assert_eq('', $got['null'], 'ausente = vacío');
    assert_eq(150, mb_strlen((string) $got['largo']), 'como mucho 150 caracteres (VARCHAR(150))');
    assert_null($got['array'], 'un array no es un nombre');
}

function test_crear_y_editar_validan_la_fuente_y_aplican_la_lista_negra(): void
{
    $api = acc_src('api/resources.php');
    assert_matches("/\\\$sourceUrl\\s*=\\s*iarepo_clean_source_url\\(\\\$data\\['source_url'\\] \\?\\? null\\);/", $api, 'POST valida source_url');
    assert_matches("/json_error\\('source_url must be an http\\(s\\) URL', 400, 'INVALID_SOURCE_URL'\\)/", $api, 'y la rechaza con su código');
    assert_matches('/INSERT INTO resources \([^)]*source_name, source_url\)/s', $api, 'y la guarda');
    assert_matches("/array_key_exists\\('source_url', \\\$data\\) \\? iarepo_clean_source_url\\(/", $api,
        'PUT solo toca la fuente si viene en el cuerpo (Campus no la borra)');
    assert_matches("/if \\(\\\$codeType === 'url'\\) \\{\\s*\\\$link = iarepo_clean_source_url\\(\\\$codeContent\\);/", $api,
        "un recurso 'url' exige una dirección http(s) al crear");
    assert_matches("/if \\(\\\$effectiveType === 'url' && \\\$putCode !== null\\) \\{\\s*\\\$link = iarepo_clean_source_url\\(\\\$putCode\\);/", $api,
        'y al editar');
    $calls = preg_match_all('/iarepo_reject_blacklisted_url\(\$db,/', $api);
    assert_true($calls >= 2, 'la lista negra de URLs se aplica al crear Y al editar (llamadas: ' . $calls . ')');
}

// ── 5 · Entrar y bienvenida ─────────────────────────────────────

function test_safe_local_path_solo_deja_rutas_propias(): void
{
    // Desde la revisión 2026-09 hay UNA regla (shared/local_path.php) en vez
    // de tres copias de safeLocalPath (signin, onboarding, google), que
    // dejaban pasar «/%09/otra.web»: el navegador borra el tabulador y
    // navega a //otra.web. Se ejecuta la función de verdad, aislada.
    $code = "<?php\nrequire " . var_export(IAREPO_ROOT . '/shared/local_path.php', true) . ";\n"
          . 'echo json_encode(array_map("iarepo_safe_local_path", ' . var_export([
              'raíz' => '/', 'ficha' => '/resource/1000', 'codificada' => '%2Fresource%2F1000',
              'con query' => '/resource/1?x=y', 'búsqueda con espacio' => '/?search=ondas%20sonoras',
              'absoluta' => 'https://evil.example/', 'protocol-relative' => '//evil.example/x',
              'barra invertida' => '/\\evil.example', 'codificada doble barra' => '%2F%2Fevil.example',
              'barra invertida codificada' => '/%5Cevil.example',
              'tabulador' => '/%09/evil.example', 'salto de línea' => '/%0a/evil.example', 'retorno' => "/\r/evil.example",
              'nulo' => '/%00/x', 'tab al final' => "/resource/1\t",
              'javascript' => 'javascript:alert(1)', 'vacía' => '',
          ], true) . '));';
    $r = iarepo_php_isolated($code);
    assert_eq(0, $r['code'], 'el subproceso termina bien: ' . trim($r['err']));
    $got = json_decode($r['out'], true);
    assert_eq(['raíz' => '/', 'ficha' => '/resource/1000', 'codificada' => '/resource/1000',
               'con query' => '/resource/1?x=y', 'búsqueda con espacio' => '/?search=ondas%20sonoras',
               'absoluta' => '', 'protocol-relative' => '', 'barra invertida' => '', 'codificada doble barra' => '',
               'barra invertida codificada' => '', 'tabulador' => '', 'salto de línea' => '', 'retorno' => '',
               'nulo' => '', 'tab al final' => '', 'javascript' => '', 'vacía' => ''], $got,
        'return_url solo acepta rutas locales');

    $r = iarepo_php_isolated("<?php\nrequire " . var_export(IAREPO_ROOT . '/shared/local_path.php', true)
        . ";\necho json_encode([iarepo_safe_local_path(['/x']), iarepo_safe_local_path(null)]);");
    assert_eq(['', ''], json_decode($r['out'], true), 'un array (?return_url[]=) o nada no es un 500');

    // Las tres entradas usan la regla común y ninguna vuelve a tener su copia.
    foreach (['auth/signin.php', 'auth/onboarding.php', 'auth/google.php'] as $rel) {
        $src = acc_src($rel);
        assert_contains('iarepo_safe_local_path(', $src, "$rel usa iarepo_safe_local_path()");
        assert_not_matches('/function\s+safeLocalPath\b/', $src, "$rel no define su propia copia");
    }
    assert_contains('iarepo_is_local_path($uri)', acc_src('shared/ui.php'), 'el «Entrar» de la cabecera usa la misma regla para la URL actual');
}

function test_entrar_habla_a_los_dos_publicos_y_conserva_el_flujo_de_guardar(): void
{
    $src = acc_src('auth/signin.php');
    assert_contains("t('Entra en iarepo')", $src, 'sin intención de guardar: «Entra en iarepo»');
    assert_contains("t('Si das clase, además')", $src, 'con los beneficios para quien da clase');
    assert_contains("t('Guardar simulaciones para volver a ellas (solo tú las ves).')", $src, 'y para quien aprende');
    assert_contains("t('Guarda este recurso')", $src, 'con save=, el mensaje de guardar');
    assert_matches("/setcookie\\('fav_intent'/", $src, 'la cookie de respaldo del guardado sigue ahí');
    assert_contains("'https://iarepo.com/auth/google.php'", $src, 'el login_uri de Google no cambia');
    assert_contains('https://accounts.google.com/gsi/client', $src, 'ni el script de Google');
    assert_contains("getElementById('gsiFail')", $src, 'si el botón de Google no carga, se dice');
}

function test_la_bienvenida_ofrece_aprender_y_saltar_va_a_la_portada(): void
{
    $src = acc_src('auth/onboarding.php');
    assert_matches('#name="role" value="student"[^>]*>.*?t\(\'Estoy aprendiendo \(en clase o por mi cuenta\)\'\)#s', $src,
        '«Estoy aprendiendo (en clase o por mi cuenta)» → rol student');
    assert_matches('#name="role" value="teacher"[^>]*>.*?t\(\'Doy clase\'\)#s', $src, '«Doy clase» → rol teacher');
    assert_contains("\$skipUrl = \$returnUrl ?: '/';", $src, '«Saltar por ahora» va a la portada (o a lo que estaba guardando), no al panel');
    assert_contains("in_array(\$role, ['teacher', 'student'], true)", $src, 'solo esos dos roles se pueden elegir');
    assert_contains("\$switchable = in_array(\$currentRole, ['teacher', 'student'], true);", $src, 'y un admin no se degrada con un clic');
    assert_contains("\$_SESSION['user']['role'] = \$role;", $src, 'el rol se aplica ya, sin volver a entrar');
    assert_not_contains('Soy estudiante', $src, '«Soy estudiante» → «Estoy aprendiendo»');
}
