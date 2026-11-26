<?php
// ================================================================
// tests/unit/security_review_test.php — Revisión de seguridad 2026-11-26
//
// Lo que se puede comprobar sin BD ni servidor. Lo que va por HTTP (quién ve
// qué en api/*.php, el visor con ?token=) está en
// tests/integration/authorization_test.php.
//
//   · iarepo_session_user_raw(): una sesión sin la marca de iarepo
//     (source='google') no es una cuenta del sitio, aunque traiga 'id'.
//   · iarepo_strip_token_js(): se EJECUTA en Node y quita ?token= de la URL
//     sin tocar el resto (otros parámetros, el #fragmento).
//   · admin/create.php exige token CSRF y rechaza escrituras de otra web.
//   · auth/google.php regenera el id de sesión y marca la sesión.
// ================================================================

require_once IAREPO_ROOT . '/shared/auth.php';
require_once IAREPO_ROOT . '/shared/srcdoc.php';

function test_solo_vale_la_sesion_con_la_marca_de_iarepo(): void
{
    $saved = $_SESSION ?? null;
    try {
        $_SESSION = ['user' => ['id' => 5, 'role' => 'superadmin', 'name' => 'X', 'email' => 'x@example.test']];
        assert_null(iarepo_session_user_raw(), 'sin source: no es sesión de iarepo');

        $_SESSION['user']['source'] = 'campus';
        assert_null(iarepo_session_user_raw(), 'con otra marca, tampoco');

        $_SESSION['user']['source'] = 'google';
        assert_eq(5, (int) (iarepo_session_user_raw()['id'] ?? 0), 'con la marca, sí');

        $_SESSION = ['user' => ['source' => 'google']];
        assert_null(iarepo_session_user_raw(), 'sin id, no');
        $_SESSION = ['user' => 'texto'];
        assert_null(iarepo_session_user_raw(), 'algo que no es un array, no');
    } finally {
        $_SESSION = $saved ?? [];
    }

    $src = (string) file_get_contents(IAREPO_ROOT . '/shared/auth.php');
    assert_eq(1, substr_count($src, "\$_SESSION['user'] ?? null"),
        'shared/auth.php lee $_SESSION[\'user\'] en un solo sitio: iarepo_session_user_raw()');
}

/** Ejecuta el script con location = $href; devuelve la URL tras replaceState (o null si no cambió). */
function sr_strip(string $href): ?string
{
    $js   = iarepo_strip_token_js();   // el script tal cual, como lo sirve la página
    $h    = json_encode($href);
    $prog = <<<JS
let out = null;
global.location = { href: $h };
global.history = { state: null, replaceState(s, t, u) { out = u; } };
$js
process.stdout.write(JSON.stringify(out));
JS;
    $f = tempnam(sys_get_temp_dir(), 'sr') . '.js';
    file_put_contents($f, $prog);
    $o = shell_exec('node ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return json_decode((string) $o, true);
}

function test_el_script_quita_el_token_y_respeta_lo_demas(): void
{
    exec('command -v node 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
        echo "    SKIP sin node\n";
        return;
    }
    assert_eq('/view/7?mode=present', sr_strip('https://iarepo.com/view/7?mode=present&token=eyJ.a.b'),
        'quita token y deja mode');
    assert_eq('/view/7#parte2', sr_strip('https://iarepo.com/view/7?token=eyJ.a.b#parte2'), 'conserva el #fragmento');
    assert_null(sr_strip('https://iarepo.com/view/7?mode=present'), 'sin token no toca nada');
}

function test_las_paginas_que_pintan_recursos_quitan_el_token_lo_primero(): void
{
    foreach (['viewer/index.php', 'resource/index.php'] as $p) {
        $src  = (string) file_get_contents(IAREPO_ROOT . "/$p");
        $head = strpos($src, '<head>');
        $call = strpos($src, 'iarepo_strip_token_js()');
        assert_true($head !== false && $call !== false && $call > $head, "$p llama a iarepo_strip_token_js() dentro del <head>");
        $firstScript = strpos($src, '<script', $head);
        assert_true($firstScript !== false && $call - $firstScript < 20, "$p: es el PRIMER script del <head>");
    }
}

function test_admin_create_exige_token_csrf(): void
{
    $src = (string) file_get_contents(IAREPO_ROOT . '/admin/create.php');
    assert_contains("iarepo_is_cross_site_write() || !iarepo_csrf_valid(\$_POST['csrf'] ?? null)", $src,
        'el alta rechaza escrituras de otra web y sin token');
    assert_contains('name="csrf" value="<?= h(iarepo_csrf_token()) ?>"', $src, 'el formulario lleva el token');
    $guard  = strpos($src, 'iarepo_csrf_valid(');
    $insert = strpos($src, 'INSERT INTO resources');
    assert_true($guard !== false && $insert !== false && $guard < $insert, 'la comprobación va antes del INSERT');
    assert_contains('session_regenerate_id(true)', $src, 'id de sesión nuevo al entrar como admin');
}

function test_el_login_de_google_regenera_y_marca_la_sesion(): void
{
    $src = (string) file_get_contents(IAREPO_ROOT . '/auth/google.php');
    $regen = strpos($src, 'session_regenerate_id(true)');
    $set   = strpos($src, "\$_SESSION['user'] = [");
    assert_true($regen !== false && $set !== false && $regen < $set, 'regenera el id ANTES de guardar la cuenta');
    assert_contains("'source'     => 'google'", $src, 'y la marca que exige iarepo_session_user_raw()');
}
