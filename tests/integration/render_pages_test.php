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
        ['/collection/?id=' . $s['coll'],       200, 200, 200],
        ['/favorites/',                         302, 200, 200],
        ['/dashboard/',                         302, 302, 200],
        ['/dashboard/editor.php',               302, 302, 200],
        ["/dashboard/editor.php?id=$res",       302, 302, 200],
        ['/auth/signin.php',                    200, 302, 302],   // con sesión, fuera
        ['/legal/terms.php',                    200, 200, 200],
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
    it_true(str_contains($body, 'lucide.createIcons'), 'el script del panel llega hasta el final');
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
