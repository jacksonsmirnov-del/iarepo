<?php
// ================================================================
// tests/integration/render_pages_test.php — Cada página HTML, abierta de verdad
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// dashboard/index.php estuvo roto del 2026-06-13 al 2026-09-26: dos llamadas
// t(T.creating) —JavaScript pegado dentro de PHP— lanzaban "Undefined
// constant T", el error_handler volcaba un JSON en mitad del <script> y todo
// el JS del panel moría. En esos tres meses pasaron 136 tests, 9 guards, la CI
// y el smoke: NINGUNA capa abría la página tal como la ve una persona, y
// menos aún con sesión iniciada.
//
// Este fichero lo hace. Levanta el sitio con `php -S` sobre la BD de
// integración, entra como anónimo, alumno y profesor, y pide cada página.
// Una página pasa si:
//   · responde con el estado esperado para ese rol (200, o 302 si no es suya);
//   · si es HTML, llega entera hasta </html>;
//   · no lleva dentro un volcado JSON de error ({"ok":false…);
//   · no imprime avisos ni errores de PHP (se arranca con display_errors=1).
//
// ── NADA DE ESTO QUEDA EN EL ÁRBOL SERVIDO ────────────────────
// El sitio se copia a un directorio temporal (git ls-files) y es AHÍ donde se
// escriben el .env.php de pruebas, el router y el atajo de login. Ninguna vía
// de autenticación de pruebas existe como fichero del repo: con push =
// producción, un login de pruebas versionado sería un bypass en vivo.
// ================================================================

require_once __DIR__ . '/site_server.php';

/**
 * Páginas y estado esperado por rol. [ruta, anónimo, alumno, profesor].
 * Un 302 es correcto cuando la página no es para ese rol.
 */
function it_render_matrix(): array
{
    $s   = &it_render_state();
    $res = IT_RENDER_RES;
    return [
        ['/',                                   200, 200, 200],
        ['/?search=ondas',                      200, 200, 200],
        ['/resource/1000',                      200, 200, 200],
        ['/view/1000',                          200, 200, 200],
        ['/profile/' . IT_RENDER_TEACHER,       200, 200, 200],
        ['/profile/' . IT_RENDER_STUDENT,       404, 200, 404],   // un alumno: solo lo ve él
        ['/collection/?id=' . $s['coll'],       200, 200, 200],
        ['/favorites/',                         302, 200, 200],
        ['/dashboard/',                         302, 302, 200],
        ['/dashboard/editor.php',               302, 302, 200],
        ["/dashboard/editor.php?id=$res",       302, 302, 200],
        ['/auth/signin.php',                    200, 302, 302],   // con sesión, fuera
        ['/legal/terms.php',                    200, 200, 200],
        ['/unsubscribe.php?token=' . str_repeat('0', 32), 200, 200, 200],   // enlace no válido: página entera
        ['/esta-ruta-no-existe',                404, 404, 404],
    ];
}

function it_render_check_html(string $label, array $hdrs, string $body): void
{
    $isHtml = false;
    foreach ($hdrs as $h)
        if (stripos($h, 'Content-Type:') === 0)
            $isHtml = stripos($h, 'text/html') !== false;
    it_true($isHtml, "$label: responde HTML");
    it_true(stripos($body, '</html>') !== false, "$label: la página llega entera hasta </html>");
    it_true(!str_contains($body, '{"ok":false'), "$label: no lleva un volcado JSON de error dentro");
    it_true(!preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>|Uncaught /', $body, $m),
        "$label: sin avisos ni errores de PHP" . (isset($m[0]) ? " (encontrado: {$m[0]})" : ''));
}

function test_cada_pagina_se_renderiza_entera_para_cada_rol(): void
{
    if (it_render_server() === null) {
        $s = &it_render_state();
        echo "    SKIP render: {$s['skip']}\n";
        return;
    }
    $s = &it_render_state();

    foreach (['' => 1, 'student' => 2, 'teacher' => 3] as $role => $col) {
        $cookie = it_render_cookie($role);
        $who    = $role === '' ? 'anónimo' : $role;
        foreach (it_render_matrix() as $row) {
            $path  = $row[0];
            $label = "$path como $who";
            [$code, $hdrs, $body] = it_render_get($s['base'] . $path, $cookie);
            it_eq($row[$col], $code, "$label: estado HTTP");
            if ($code === 200 || $code === 404)
                it_render_check_html($label, $hdrs, $body);
        }
    }
}

function test_el_panel_del_autor_ejecuta_su_javascript(): void
{
    if (it_render_server() === null) {
        echo "    SKIP render: " . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s = &it_render_state();
    [$code, , $body] = it_render_get($s['base'] . '/dashboard/', it_render_cookie('teacher'));
    it_eq(200, $code, 'el panel responde al profesor');
    // El fallo de junio cortaba la página justo aquí: el objeto T nunca se
    // cerraba y nada de lo que venía detrás llegaba al navegador.
    it_true((bool) preg_match('/creating: "[^"]*Creando\.\.\."/', $body), 'el objeto T del panel lleva sus textos');
    // Desde el rediseño 2026-09 el panel termina su script con IA.icons()
    // (assets/js/ui.js, que llama a lucide.createIcons). Lo que importa sigue
    // siendo lo mismo: que la ÚLTIMA sentencia del script llegue al navegador.
    it_true((bool) preg_match('/IA\.icons\(\);\s*<\/script>/', $body), 'el script del panel llega hasta el final');
    it_true(str_contains($body, 'Recurso de pruebas del panel'), 'el panel lista los recursos del autor');
}

function test_el_servidor_no_registra_errores_de_php(): void
{
    if (it_render_server() === null) {
        echo "    SKIP render: " . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s   = &it_render_state();
    $log = (string) @file_get_contents($s['dir'] . '/server.log');
    it_true(!preg_match('/PHP (Warning|Notice|Fatal error|Parse error)[^\n]*/', $log, $m),
        'php -S no registró avisos ni errores' . (isset($m[0]) ? ": {$m[0]}" : ''));
}

/**
 * Un error en una página HTML se VE: 500 limpio para la persona, fila en
 * client_error_log con su referencia, y recuento en api/health.php.
 *
 * La página rota se escribe SOLO en la copia temporal del sitio. Reproduce el
 * fallo de junio: JavaScript pegado dentro de PHP a mitad de un script.
 */
function test_un_error_en_una_pagina_queda_registrado_y_se_cuenta_en_health(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s  = &it_render_state();
    $db = iarepo_it_db();
    $db->exec("DELETE FROM client_error_log WHERE source LIKE 'server:%'");

    [$code, , $body] = it_render_get($s['base'] . '/api/health.php');
    $h = json_decode($body, true) ?: [];
    it_eq(0, $h['errors_24h']['server'] ?? null, 'health parte de cero errores de servidor');

    $broken = '__it_broken_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($s['dir'] . "/site/$broken", "<?php\nrequire_once __DIR__ . '/shared/page_errors.php';\n"
        . "echo '<html><body><h1>Panel</h1><scr' . 'ipt>const T = { creating: ';\necho json_encode(T.creating);\n");

    [$code, $hdrs, $body] = it_render_get($s['base'] . "/$broken");
    it_eq(500, $code, 'la página rota responde 500, no un 200 a medias');
    it_true(str_contains($body, 'Algo ha fallado'), 'con la página de error');
    it_true(!str_contains($body, '<h1>Panel</h1>'), 'sin la mitad de la página rota');
    it_true((bool) preg_match('/ref ([0-9a-f]{8})/', $body, $m), 'y un código de referencia');

    $row = $db->query("SELECT message, source FROM client_error_log WHERE source LIKE 'server:%' ORDER BY id DESC LIMIT 1")->fetch();
    it_true((bool) $row, 'el error queda guardado en client_error_log');
    it_true(str_contains((string) ($row['message'] ?? ''), 'Undefined constant'), 'con el mensaje real');
    it_true(str_contains((string) ($row['message'] ?? ''), $m[1] ?? '~'), 'y la misma referencia que vio la persona');
    it_eq('server:' . $broken, $row['source'] ?? null, 'y el fichero que falló');

    [$code, , $body] = it_render_get($s['base'] . '/api/health.php');
    $h = json_decode($body, true) ?: [];
    it_eq(1, $h['errors_24h']['server'] ?? null, 'health.php lo cuenta sin autenticación');

    @unlink($s['dir'] . "/site/$broken");
    $db->exec("DELETE FROM client_error_log WHERE source LIKE 'server:%'");
}

/** Ninguna página de la matriz deja errores de servidor registrados. */
function test_recorrer_las_paginas_no_deja_errores_registrados(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s  = &it_render_state();
    $db = iarepo_it_db();
    $db->exec("DELETE FROM client_error_log WHERE source LIKE 'server:%'");
    foreach (['', 'student', 'teacher'] as $role) {
        $cookie = it_render_cookie($role);
        foreach (it_render_matrix() as $row)
            it_render_get($s['base'] . $row[0], $cookie);
    }
    $rows = $db->query("SELECT CONCAT(source, ':', lineno, ' ', message) FROM client_error_log WHERE source LIKE 'server:%'")->fetchAll(PDO::FETCH_COLUMN);
    it_eq([], $rows, 'errores de servidor registrados al recorrer las páginas');
}

// ================================================================
// Portada (index.php) y 404 — rediseño 2026-09
//
// Lo que solo se ve renderizando: que las dos secciones calculadas en el
// servidor («Listos para clase», «Para empezar») salen, con qué recursos y en
// un orden estable durante el día; que los deep-links llegan ya pintados; que
// el health check en JSON sigue respondiendo. Las decisiones que se ven en el
// fuente (sin popularidad, sin terceros, ?rlang=) están en
// tests/unit/landing_test.php.
// ================================================================

/** Recurso temporal: una introducción pública de Primaria (alimenta #para-empezar). */
const IT_HOME_BASICS = 1097;

/** ids de /resource/N dentro del bloque HTML con ese id (hasta el siguiente <section). */
function it_home_section_ids(string $body, string $id): ?array
{
    if (!preg_match('#<section[^>]*id="' . preg_quote($id, '#') . '"(.*?)(?=<section|</main>)#s', $body, $m))
        return null;
    preg_match_all('#href="/resource/(\d+)"#', $m[1], $ids);
    return array_map('intval', $ids[1]);
}

function test_la_portada_calcula_sus_secciones_sin_popularidad(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s  = &it_render_state();
    $db = iarepo_it_db();
    $id = IT_HOME_BASICS;
    $db->exec("DELETE FROM resources WHERE id = $id");
    try {
        $db->exec("INSERT INTO resources
            (id, title, description, code_content, code_type, subject_area, lang, level, category_id,
             author_tenant_id, author_user_id, author_display_name, author_tenant_name, visibility, is_active)
            VALUES ($id, 'Fracciones: Intro (test de portada)', 'Solo para el test de la portada.', '<p>demo</p>',
                    'html', 'Matemáticas', 'es', 'primary', (SELECT id FROM categories WHERE slug = 'mathematics'),
                    1, 1, 'Ana Docente', 'IES Central', 'community', 1)");

        [$code, , $body] = it_render_get($s['base'] . '/');
        it_eq(200, $code, 'la portada responde');
        $basics = it_home_section_ids($body, 'para-empezar');
        $ready  = it_home_section_ids($body, 'listos');
        it_true($basics !== null, 'sale la sección «Para empezar»');
        it_true($ready !== null && count($ready) > 0, 'sale la sección «Listos para clase» con recursos');
        it_contains($basics ?? [], $id, 'una introducción pública de Primaria entra en «Para empezar»');
        it_not_contains($ready ?? [], $id, 'y no se repite en «Listos para clase»');
        it_true(count($ready ?? []) <= 8 && count($basics ?? []) <= 8, 'como mucho 8 por sección');

        // Solo lo público, activo y de Primaria/Secundaria (el borrador del
        // profesor de pruebas, 1098, no puede aparecer).
        $all = array_merge($ready ?? [], $basics ?? []);
        it_not_contains($all, IT_RENDER_RES, 'un borrador no sale en la portada');
        if ($all) {
            $in   = implode(',', array_map('intval', $all));
            $rows = $db->query("SELECT id, level, visibility, is_active FROM resources WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                it_true(in_array(strtolower((string) $r['level']), ['primary', 'secondary', 'primaria', 'secundaria', 'eso'], true),
                    "el recurso {$r['id']} de la portada es de Primaria o Secundaria (level={$r['level']})");
                it_true($r['visibility'] === 'community' && (int) $r['is_active'] === 1, "el recurso {$r['id']} es público y activo");
            }
        }

        // Orden rotatorio por día, no aleatorio por visita: dos visitas seguidas ven lo mismo.
        [, , $again] = it_render_get($s['base'] . '/');
        it_eq($ready, it_home_section_ids($again, 'listos'), 'el orden de «Listos para clase» es estable durante el día');

        foreach (['fonts.googleapis', 'accounts.google.com', 'GitHub para profesores', 'present-overlay'] as $x)
            it_true(!str_contains($body, $x), "la portada no lleva «{$x}»");
        foreach (['id="catalogo"', 'id="categories"', 'data-rlang="es"', 'data-level="primary"', 'id="lang-switch"', 'hreflang="en"'] as $x)
            it_true(str_contains($body, $x), "la portada lleva {$x}");
    } finally {
        $db->exec("DELETE FROM resources WHERE id = $id");
    }
}

/** Un deep-link llega ya pintado: sin parpadeo de la portada entera antes de recogerse. */
function test_los_deep_links_de_la_portada_llegan_ya_marcados(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s = &it_render_state();
    [$code, , $body] = it_render_get($s['base'] . '/?search=ondas&rlang=es&level=secondary');
    it_eq(200, $code, 'deep-link con búsqueda y filtros');
    it_true(str_contains($body, 'class="ia-page searching"'), 'la portada llega recogida (modo búsqueda)');
    it_true((bool) preg_match('/data-rlang="es" aria-pressed="true"/', $body), '?rlang=es marca «Español»');
    it_true((bool) preg_match('/data-level="secondary" aria-pressed="true"/', $body), '?level=secondary marca Secundaria');
    it_true((bool) preg_match('/id="search"[^>]*value="ondas"/', $body), 'el buscador trae el texto');
    it_true((bool) preg_match('/id="sort-row">/', $body), 'con búsqueda se ve la fila «Orden»');

    // Un enlace viejo con un orden que la portada ya no ofrece no rompe nada.
    [$code, , $body] = it_render_get($s['base'] . '/?sort=popular');
    it_eq(200, $code, '/?sort=popular sigue abriendo');
    it_true(!str_contains($body, 'value="popular"'), 'sin opción «Más usados»');
    it_true(str_contains($body, '<option value="recent" selected>'), 'y con su orden por defecto');

    // ?lang=en es solo el idioma de la interfaz: no marca ningún filtro de idioma.
    [, , $body] = it_render_get($s['base'] . '/?lang=en');
    it_true((bool) preg_match('/data-rlang="" aria-pressed="true"/', $body), '?lang=en no filtra el catálogo');
    it_true(str_contains($body, '<html lang="en">'), 'pero sí cambia el idioma de la página');
}

function test_la_portada_se_adapta_al_rol(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s = &it_render_state();
    [, , $anon]    = it_render_get($s['base'] . '/');
    [, , $student] = it_render_get($s['base'] . '/', it_render_cookie('student'));
    it_true(str_contains($anon, 'Doy clase') && str_contains($anon, 'Estoy aprendiendo'), 'el anónimo ve las dos entradas');
    it_true(!str_contains($student, 'Doy clase'), 'al alumno no se le ofrece «Doy clase»');
    it_true(str_contains($student, 'Estoy aprendiendo'), 'pero sí «Estoy aprendiendo»');
    it_true(str_contains($anon, '/auth/signin.php'), '«Entrar» lleva a la pantalla propia de acceso');
}

/** index.php es también el health check de monitores externos: no puede dejar de serlo. */
function test_la_portada_sigue_respondiendo_el_health_check_en_json(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s = &it_render_state();
    [$code, $hdrs, $body] = it_render_request('GET', $s['base'] . '/', null, []);
    it_eq(200, $code, 'Accept: application/json en / responde 200');
    $j = json_decode($body, true) ?: [];
    it_eq('iarepo', $j['service'] ?? null, 'con el JSON de salud');
    it_eq('connected', $j['database'] ?? null, 'y la BD conectada');
}

function test_el_404_ofrece_buscar_y_volver(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s = &it_render_state();
    [$code, , $body] = it_render_get($s['base'] . '/esta-ruta-tampoco-existe');
    it_eq(404, $code, 'una ruta que no existe responde 404');
    it_true((bool) preg_match('#<form[^>]*action="/"#', $body) && str_contains($body, 'name="search"'), 'con un buscador que abre la portada');
    it_true(str_contains($body, 'href="/?focus=search"'), 'y un enlace a explorar');
    it_true(str_contains($body, 'class="ia-header"') && str_contains($body, 'class="ia-footer"'), 'con la cabecera y el pie de la web');
}

// ================================================================
// La ficha (resource/index.php) y el visor (viewer/index.php) — rediseño 2026-09
//
// Lo que solo se ve renderizando: qué acciones recibe cada rol, que las cifras
// públicas respetan su umbral (y el autor ve las suyas siempre), que un
// borrador no se abre y que el visor responde con el código y el idioma
// correctos. Lo que se ve en el fuente (umbrales, sandbox, sin onclick, sin
// pwa.js) está en tests/unit/resource_page_test.php.
//
// Datos: los del fixture (1000 html con 120 aperturas y 4 usos; 1031 url sin
// usos; 1020 borrador de otro tenant) y el borrador del profesor de pruebas
// (IT_RENDER_RES), que sirve para ver la ficha como su AUTOR.
// ================================================================

/** GET de una ficha o del visor como un rol ('' = anónimo). [estado, cuerpo]. */
function it_ficha_get(string $path, string $role = ''): array
{
    $s = &it_render_state();
    [$code, , $body] = it_render_get($s['base'] . $path, it_render_cookie($role));
    return [$code, $body];
}

function test_la_ficha_da_a_cada_rol_sus_acciones(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    [$code, $anon] = it_ficha_get('/resource/1000');
    [, $student]   = it_ficha_get('/resource/1000', 'student');
    [, $teacher]   = it_ficha_get('/resource/1000', 'teacher');
    it_eq(200, $code, 'la ficha de un recurso público responde');

    foreach (['anónimo' => $anon, 'alumno' => $student, 'profesor' => $teacher] as $who => $b) {
        it_true(str_contains($b, 'id="projectBtn"'), "$who: la acción principal es proyectar");
        it_true(str_contains($b, 'id="favBtn"'), "$who: Guardar");
        it_true(!preg_match('/\bLikes\b/', $b), "$who: «Likes» ya no aparece");
        it_true(!str_contains($b, 'stat-item'), "$who: sin la fila de contadores a cero");
        it_true(!str_contains($b, 'accounts.google.com') && !str_contains($b, 'fonts.googleapis'), "$who: sin scripts ni fuentes de Google");
        it_true(str_contains($b, 'class="ia-header"') && str_contains($b, 'class="ia-footer"'), "$who: cabecera y pie comunes");
    }

    // «Mandar a mis alumnos»: para docentes, también sin cuenta; no para alumnos.
    it_true(str_contains($anon, 'id="sendBtn"') && str_contains($anon, 'id="ia-send"'), 'anónimo: «Mandar a mis alumnos» con su diálogo');
    it_true(str_contains($anon, 'qrcode.js'), 'y el QR se genera en el navegador');
    it_true(!str_contains($student, 'id="sendBtn"'), 'alumno: sin «Mandar a mis alumnos»');

    // Para docentes: el alumno no ve «Lo usé en clase» ni «Hacer mi versión».
    it_true(str_contains($teacher, 'id="usedBtn"'), 'profesor: «Lo usé en clase»');
    it_true(str_contains($teacher, 'id="forkBtn"'), 'profesor y recurso html: «Hacer mi versión»');
    it_true(!str_contains($student, 'id="usedBtn"') && !str_contains($student, 'id="forkBtn"') && !str_contains($student, 'id="embedBtn"'),
        'alumno: ninguna acción docente');
    it_true(!str_contains($anon, 'id="usedBtn"') && str_contains($anon, 'Entra para marcarlo'), 'anónimo: «Lo usé en clase» lleva a entrar');

    // Comentarios: escriben solo docentes; los demás leen por qué no.
    it_true(str_contains($teacher, 'id="commentBody"'), 'profesor: formulario de comentarios');
    it_true(!str_contains($student, 'id="commentBody"') && str_contains($student, 'Los comentarios son para docentes'), 'alumno: sin formulario y con el motivo');
    it_true(!str_contains($anon, 'id="commentBody"') && str_contains($anon, 'Entra para comentar'), 'anónimo: sin formulario y con el enlace para entrar');

    // Un recurso 'url' no se «forkea»: duplicaría un enlace.
    [$code, $url] = it_ficha_get('/resource/1031', 'teacher');
    it_eq(200, $code, 'la ficha de un recurso url responde');
    it_true(str_contains($url, 'id="projectBtn"') && !str_contains($url, 'id="forkBtn"'), 'recurso url: sin «Hacer mi versión»');
}

function test_la_ficha_solo_ensena_cifras_por_encima_del_umbral(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    [, $b] = it_ficha_get('/resource/1000');   // 120 aperturas, 4 usos en el fixture
    it_true(str_contains($b, 'Abierto por'), '≥ 10 aperturas: se dice');
    it_true(str_contains($b, 'Usado en clase por'), '≥ 3 docentes: se dice');
    it_true(!str_contains($b, 'Es tu recurso'), 'a quien no es el autor no se le enseñan las cifras del autor');

    [, $b] = it_ficha_get('/resource/1031');   // 0 usos en el fixture
    it_true(!str_contains($b, 'Usado en clase por'), 'con 0 usos no se dice nada');

    // El autor SÍ ve sus cifras, también a cero (IT_RENDER_RES es su borrador).
    $own = '/resource/' . IT_RENDER_RES;
    [$code, $b] = it_ficha_get($own, 'teacher');
    it_eq(200, $code, 'el autor abre su borrador');
    it_true(str_contains($b, 'Es tu recurso') && str_contains($b, 'Aperturas'), 'el autor ve sus cifras');
    it_true(str_contains($b, 'Borrador · solo tú lo ves'), 'y la visibilidad en palabras, no «draft»');
    it_true((bool) preg_match('/id="likeCount" hidden>/', $b), '«Me gusta» sin número cuando es 0');

    // Un borrador ajeno no se abre (canView, la misma regla que la API).
    it_eq(302, it_ficha_get($own)[0], 'anónimo: un borrador no se abre');
    it_eq(302, it_ficha_get($own, 'student')[0], 'alumno: tampoco');
    it_eq(302, it_ficha_get('/resource/1020', 'teacher')[0], 'profesor de otro tenant: tampoco');
}

function test_el_visor_responde_con_su_codigo_y_sin_pwa(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    [$code, $b] = it_ficha_get('/view/1000?mode=present');
    it_eq(200, $code, 'el modo proyector responde');
    it_true(str_contains($b, 'id="fsBtn"'), 'con su botón de pantalla completa');
    it_true(!str_contains($b, 'pwa.js'), 'sin pwa.js: su «Instalar app» tapaba la proyección');
    it_true(str_contains($b, '<html lang="es">'), 'en el idioma de la interfaz');

    [$code, $b] = it_ficha_get('/view/1000?lang=en');
    // «Proyectar» en inglés es «Present» desde la revisión 2026-09 («Project» a
    // secas se leía como sustantivo). Lo medido sigue igual: el visor, en inglés.
    it_true($code === 200 && str_contains($b, '<html lang="en">') && str_contains($b, '>Present<'), 'el visor también habla inglés');

    [$code, $b] = it_ficha_get('/view/1020');
    it_eq(401, $code, 'anónimo ante un borrador: 401');
    it_true(str_contains($b, '/auth/signin.php?return_url='), 'con un enlace para entrar');
    [$code, $b] = it_ficha_get('/view/1020', 'teacher');
    it_eq(403, $code, 'docente que no es el autor: 403');
    it_true(str_contains($b, '</html>') && !str_contains($b, '{"ok":false'), 'página de error entera, no un JSON');
    it_eq(404, it_ficha_get('/view/999999')[0], 'un recurso que no existe: 404');
}

// ================================================================
// Listas y perfil (profile/, collection/, favorites/) — rediseño 2026-09
//
// Lo que solo se ve renderizando: que el perfil de un alumno responde 404 a
// todos menos a él, que una lista sale en el orden en que se armó (added_at
// ASC, no por id ni al revés) y que un borrador metido en una lista pública
// no se le enseña a nadie más que a su autor. Lo que se ve en el fuente
// (sin email, umbrales, sin contadores) está en tests/unit/lists_profile_test.php.
//
// Datos: el profesor (IT_RENDER_TEACHER) y la alumna (IT_RENDER_STUDENT) de
// site_server.php, su «Lista de pruebas» (con el 1000) y el borrador del
// profesor (IT_RENDER_RES). Lo que añade cada test lo quita en su finally.
// ================================================================

/** GET como un rol ('' = anónimo). [estado, cuerpo]. Propio del bloque: no depende de los de otros. */
function it_lp_get(string $path, string $role = ''): array
{
    $s = &it_render_state();
    [$code, , $body] = it_render_get($s['base'] . $path, it_render_cookie($role));
    return [$code, $body];
}

/** ids de /resource/N dentro de la secuencia de una lista, en orden. */
function it_lp_step_ids(string $body): array
{
    if (!preg_match('#<ol class="cl-steps[^"]*"[^>]*>(.*?)</ol>#s', $body, $m))
        return [];
    // Desde la revisión 2026-09 cada paso enlaza con ?list=<id> (la ficha
    // enseña «Paso N de M»): se acepta, y se exige, esa cola.
    preg_match_all('#href="/resource/(\d+)\?list=\d+"#', $m[1], $ids);
    return array_map('intval', $ids[1]);
}

function test_el_perfil_de_un_alumno_no_es_publico(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $path = '/profile/' . IT_RENDER_STUDENT;
    foreach (['' => 'anónimo', 'teacher' => 'profesor'] as $role => $who) {
        [$code, $b] = it_lp_get($path, $role);
        it_eq(404, $code, "$who: el perfil de un alumno responde 404");
        it_true(str_contains($b, '</html>') && !str_contains($b, 'Alumna de Pruebas'), "$who: página 404 entera y sin su nombre");
    }
    [$code, $b] = it_lp_get($path, 'student');
    it_eq(200, $code, 'el alumno sí ve su propio perfil');
    it_true(str_contains($b, 'Alumna de Pruebas') && str_contains($b, '<meta name="robots" content="noindex">'), 'con su nombre y noindex');

    [$code, $b] = it_lp_get('/profile/' . IT_RENDER_TEACHER);
    it_eq(200, $code, 'el perfil de un docente es público');
    it_true(str_contains($b, 'class="profile-header"'), 'con la cabecera que busca el smoke');
    it_true(!str_contains($b, 'it-teacher@example.test'), 'sin su email');
    it_true(str_contains($b, 'Lista de pruebas'), 'con sus listas públicas');
    it_true(!str_contains($b, 'Recurso de pruebas del panel'), 'y sin sus borradores');
    it_eq(404, it_lp_get('/profile/987654')[0], 'un perfil que no existe: 404');
}

function test_la_lista_es_una_secuencia_y_no_cuela_borradores(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s   = &it_render_state();
    $db  = iarepo_it_db();
    $c   = (int) $s['coll'];
    $res = IT_RENDER_RES;
    try {
        // 1031 entra DESPUÉS que el 1000 (id mayor) pero con fecha ANTERIOR:
        // ordenar por id, o al revés, lo delataría.
        $db->exec("UPDATE collection_items SET added_at = '2026-01-02 10:00:00' WHERE collection_id = $c AND resource_id = 1000");
        $db->exec("INSERT INTO collection_items (collection_id, resource_id, added_at) VALUES
                   ($c, 1031, '2026-01-01 10:00:00'), ($c, $res, '2026-01-03 10:00:00')");

        [$code, $anon] = it_lp_get("/collection/?id=$c");
        it_eq(200, $code, 'una lista pública se abre sin cuenta');
        it_eq([1031, 1000], it_lp_step_ids($anon), 'los pasos van en el orden en que se añadieron, sin el borrador');
        it_true(!str_contains($anon, 'Recurso de pruebas del panel'), 'el título del borrador no sale a quien no es su autor');
        it_true(str_contains($anon, 'id="sendListBtn"') && str_contains($anon, 'id="ia-send"') && str_contains($anon, 'qrcode.js'),
            'anónimo: «Mandar a mis alumnos» con su diálogo y el QR');
        it_true(str_contains($anon, 'Profe de Pruebas'), 'se nombra al docente que la armó');

        [, $owner] = it_lp_get("/collection/?id=$c", 'teacher');
        it_eq([1031, 1000, $res], it_lp_step_ids($owner), 'su dueño (y autor del borrador) ve los tres pasos');
        it_true(str_contains($owner, 'No es público: solo lo ves tú'), 'y un aviso en el paso que sus alumnos no verán');
        it_true(str_contains($owner, 'data-remove="1031"'), 'con el botón de quitar');

        [, $student] = it_lp_get("/collection/?id=$c", 'student');
        it_eq([1031, 1000], it_lp_step_ids($student), 'el alumno ve la secuencia pública');
        it_true(!str_contains($student, 'id="sendListBtn"') && !str_contains($student, 'data-remove='), 'sin «Mandar a mis alumnos» ni quitar');
        it_true(str_contains($student, 'href="/resource/1031?list=' . $c . '"') && str_contains($student, 'Empezar por el paso 1'), 'y con «Empezar por el paso 1» (con ?list=, revisión 2026-09)');
    } finally {
        $db->exec("DELETE FROM collection_items WHERE collection_id = $c AND resource_id IN (1031, $res)");
    }
}

function test_guardados_tiene_portadas_y_ningun_contador(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    [$code, $b] = it_lp_get('/favorites/', 'student');
    it_eq(200, $code, 'Guardados responde a la alumna');
    it_true(str_contains($b, 'Ondas sonoras y su propagación') && str_contains($b, 'data-fav="1000"'), 'con su guardado y la estrella para quitarlo');
    it_true(str_contains($b, 'class="ia-cover '), 'con portada');
    it_true(!str_contains($b, '👁') && !str_contains($b, 'Vistas'), 'sin contadores de vistas');
    it_true(str_contains($b, 'aria-current="page">Guardados<'), 'y la cabecera marca «Guardados»');

    [$code, $hdrs] = it_render_get(it_render_state()['base'] . '/favorites/');
    $loc = '';
    foreach ($hdrs as $h)
        if (stripos($h, 'Location:') === 0)
            $loc = trim(substr($h, 9));
    it_eq(302, $code, 'anónimo: a entrar');
    it_eq('/auth/signin.php?return_url=%2Ffavorites%2F', $loc, 'y de vuelta a Guardados después');
}

// ================================================================
// Cuenta: Mi panel, editor, acceso y bienvenida — rediseño 2026-09
//
// Lo que solo se ve renderizando con sesión: que el panel enseña al autor sus
// cifras con el vocabulario nuevo (nada de Forks/Likes/Dashboard), que la
// pestaña «Mis listas» trae sus listas, que el editor pliega lo secundario y
// que la bienvenida ofrece las dos entradas y no degrada a un admin. Lo que se
// ve en el fuente está en tests/unit/account_pages_test.php; la API de
// publicar/editar, en tests/integration/account_api_test.php.
// ================================================================

/** POST de formulario (application/x-www-form-urlencoded), sin seguir redirecciones. */
function it_acc_form_post(string $url, ?string $cookie, array $fields): array
{
    $headers = "Content-Type: application/x-www-form-urlencoded\r\n" . ($cookie ? "Cookie: $cookie\r\n" : '');
    $ctx  = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => http_build_query($fields),
                                              'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
    $body = @file_get_contents($url, false, $ctx);
    $hdrs = $http_response_header ?? [];
    $code = $hdrs && preg_match('#^HTTP/\S+\s+(\d{3})#', $hdrs[0], $m) ? (int) $m[1] : 0;
    $loc  = '';
    foreach ($hdrs as $h)
        if (stripos($h, 'Location:') === 0)
            $loc = trim(substr($h, 9));
    return [$code, $loc, (string) $body];
}

function test_el_panel_ensena_al_autor_sus_cifras_con_el_vocabulario_nuevo(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    [$code, $b] = it_ficha_get('/dashboard/', 'teacher');
    it_eq(200, $code, 'el panel responde al profesor');
    foreach (['Mi panel', 'Abierto por', 'Usos en clase', 'Versiones de otros docentes', 'Mis listas'] as $x)
        it_true(str_contains($b, $x), "el panel dice «{$x}»");
    it_true(str_contains($b, 'Solo tú (borrador)'), 'la visibilidad de su borrador, en palabras (no «draft»)');
    it_true(str_contains($b, 'Lista de pruebas'), 'la pestaña «Mis listas» trae sus listas');
    it_true(str_contains($b, 'aria-current="page">Para docentes<'), 'la cabecera común marca «Para docentes»');
    foreach (['/\bForks?\b/', '/\bLikes\b/', '/>\s*Dashboard\s*</', '/Colecci[oó]n/u', '/fonts\.googleapis/', '/👁|❤|🔄/u'] as $re)
        it_true(!preg_match($re, $b, $m), 'el panel no lleva ' . ($m[0] ?? $re));
    it_true(str_contains($b, 'data-theme-toggle') && !str_contains($b, 'id="themeBtn"'), 'un solo conmutador de tema, el común');

    // El alumno no tiene panel de autor: sus Guardados.
    [$code] = it_ficha_get('/dashboard/', 'student');
    it_eq(302, $code, 'al alumno se le manda a Guardados');
}

function test_el_editor_pliega_lo_secundario_y_acredita_la_fuente(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    [$code, $b] = it_ficha_get('/dashboard/editor.php', 'teacher');
    it_eq(200, $code, 'el editor responde');
    it_true((bool) preg_match('#<details class="ed-more" id="more">#', $b), 'nuevo recurso: «Más opciones» llega plegado');
    it_true(str_contains($b, 'id="sourceName"') && str_contains($b, 'id="codeUrl"'), 'con «Fuente original» y la dirección de la simulación');
    it_true(!str_contains($b, 'id="subjectArea"'), 'sin el «Área / Materia» de texto libre');
    it_true(!str_contains($b, "value=\"school\""), 'sin «Tu centro»: en iarepo.com no significaría eso');

    [$code, $b] = it_ficha_get('/dashboard/editor.php?id=' . IT_RENDER_RES, 'teacher');
    it_eq(200, $code, 'editar su propio recurso responde');
    it_true(str_contains($b, 'value="Recurso de pruebas del panel"'), 'con su título');
    it_true((bool) preg_match('#<details class="ed-more" id="more" open>#', $b), 'al editar, «Más opciones» llega abierto');
}

function test_entrar_habla_a_los_dos_publicos_y_conserva_el_guardado(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    [$code, $b] = it_ficha_get('/auth/signin.php');
    it_eq(200, $code, 'la pantalla de acceso responde');
    it_true(str_contains($b, 'Entra en iarepo') && str_contains($b, 'Si das clase, además'), 'sin intención: beneficios para quien aprende y para quien enseña');
    it_true(str_contains($b, 'no hace falta cuenta'), 'y recuerda que usar las simulaciones no pide cuenta');

    [$code, $b] = it_ficha_get('/auth/signin.php?save=1000&return_url=%2Fresource%2F1000');
    it_eq(200, $code, 'con intención de guardar responde');
    it_true(str_contains($b, 'Guarda este recurso') && !str_contains($b, 'Si das clase, además'), 'con save= el mensaje es el de guardar');
    it_true(str_contains($b, 'auth/google.php?save=1000&amp;return_url=%2Fresource%2F1000'), 'la intención viaja al login de Google');

    // (La dirección sí aparece, codificada, en los enlaces de idioma y «Entrar»
    // de la cabecera, que conservan la URL actual: por eso se mira dónde.)
    [, $b] = it_ficha_get('/auth/signin.php?return_url=' . rawurlencode('//evil.example/x'));
    it_true(str_contains($b, 'data-login_uri="https://iarepo.com/auth/google.php"'), 'un return_url que no es una ruta local no viaja a Google');
    it_true((bool) preg_match('#<a href="/">← #u', $b), 'y «Volver» lleva a la portada, no fuera de la web');
}

function test_la_bienvenida_ofrece_las_dos_entradas_y_no_degrada_a_un_admin(): void
{
    if (it_render_server() === null) {
        echo '    SKIP render: ' . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s  = &it_render_state();
    $db = iarepo_it_db();
    [$code, $b] = it_ficha_get('/auth/onboarding.php', 'teacher');
    it_eq(200, $code, 'la bienvenida responde');
    it_true(str_contains($b, 'value="teacher"') && str_contains($b, 'Doy clase'), '«Doy clase»');
    it_true(str_contains($b, 'value="student"') && str_contains($b, 'Estoy aprendiendo (en clase o por mi cuenta)'), '«Estoy aprendiendo (en clase o por mi cuenta)»');
    it_true((bool) preg_match('#<a class="ob-skip" href="/">#', $b), '«Saltar por ahora» lleva a la portada, no al panel');
    it_eq(302, it_ficha_get('/auth/onboarding.php')[0], 'sin sesión, fuera');

    // Un admin que pulsa «Estoy aprendiendo» (aquí o en su perfil) no se
    // degrada: el formulario solo cambia entre teacher y student.
    $t = IT_RENDER_TEACHER;
    try {
        $db->exec("UPDATE users SET role = 'admin' WHERE id = $t");
        // Desde la revisión 2026-09 el POST lleva el token CSRF de la sesión
        // (sin él, 403 y nada cambia: lo prueba it_fix_* más abajo). Se lee
        // del formulario como haría el navegador; lo que se mide aquí sigue
        // siendo lo mismo: que un admin no se degrada.
        $adminCookie = it_render_cookie('admin', $t);
        [, , $form] = it_render_get($s['base'] . '/auth/onboarding.php', $adminCookie);
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $form, $tok);
        [$code, $loc] = it_acc_form_post($s['base'] . '/auth/onboarding.php', $adminCookie, ['role' => 'student', 'csrf' => $tok[1] ?? '']);
        it_eq(302, $code, 'el POST redirige');
        it_eq('admin', $db->query("SELECT role FROM users WHERE id = $t")->fetchColumn(), 'y el admin sigue siendo admin');
        it_eq('/dashboard/', $loc, 'con su destino de siempre');
    } finally {
        $db->exec("UPDATE users SET role = 'teacher' WHERE id = $t");
    }
}
