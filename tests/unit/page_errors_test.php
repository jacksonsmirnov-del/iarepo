<?php
// ================================================================
// tests/unit/page_errors_test.php — shared/page_errors.php
//
// Las páginas HTML no cargan helpers.php (CLAUDE.md §2.1), así que sus errores
// los recoge shared/page_errors.php. Este fichero comprueba, en subprocesos
// limpios (cada uno registra sus propios manejadores), las tres promesas:
//
//   1. Un error fatal NO deja media página: se tira la salida y se sirve una
//      página de error limpia con un código de referencia. Es exactamente el
//      fallo del panel del autor (junio–septiembre de 2026): `t(T.creating)`
//      reventaba dentro de la etiqueta script y la persona veía la página cortada.
//   2. Un aviso no se enseña a la persona, pero queda en el log con el formato
//      estándar "PHP Warning:" (el que busca render_pages_test.php).
//   3. Nunca se enseñan rutas ni trazas.
//
// IAREPO_PAGE_ERRORS_NO_DB impide que el subproceso toque una BD: en el clon
// del mantenedor, .env.php puede apuntar a una base real.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

function pe_run(string $body): array
{
    $code = "<?php\ndefine('IAREPO_PAGE_ERRORS_NO_DB', true);\n"
          . "require 'shared/page_errors.php';\n" . $body;
    return iarepo_php_isolated($code);
}

function test_un_error_fatal_no_deja_media_pagina(): void
{
    // La reproducción literal del fallo de junio: JS pegado dentro de PHP.
    // La etiqueta se parte en dos para que el guard G6 no la tome por JS real.
    $tag = '<scr' . 'ipt>';
    $r = pe_run('echo "<html><body><h1>Panel</h1>' . $tag . 'const T = { creating: ";'
              . "\necho json_encode(T.creating);\necho '}</scr' . 'ipt></body></html>';");
    assert_contains('Algo ha fallado', $r['out'], 'se sirve la página de error');
    assert_not_contains('<h1>Panel</h1>', $r['out'], 'la salida a medias se tira entera');
    assert_not_contains('const T = {', $r['out'], 'no queda un script cortado');
    assert_matches('/ref [0-9a-f]{8}/', $r['out'], 'lleva un código de referencia');
    assert_contains('PHP Fatal error:', $r['err'], 'el error queda en el log con el formato estándar');
    assert_contains('Undefined constant', $r['err'], 'con el mensaje real');
}

function test_una_excepcion_sin_capturar_tambien(): void
{
    $r = pe_run('echo "<p>medio</p>"; throw new RuntimeException("se rompió");');
    assert_contains('Algo ha fallado', $r['out']);
    assert_not_contains('<p>medio</p>', $r['out']);
    assert_not_contains('se rompió', $r['out'], 'el mensaje interno no se enseña');
    assert_contains('Uncaught RuntimeException: se rompió', $r['err']);
}

function test_un_aviso_se_registra_pero_no_se_ve(): void
{
    $r = pe_run('ini_set("display_errors", "1"); // el handler no depende de esto' . "\n"
              . '$x = []; echo "<p>" . @$x["nada"] . "</p>"; echo "<p>" . $x["falta"] . "</p><p>fin</p>";');
    assert_contains('<p>fin</p>', $r['out'], 'la página sigue: un aviso no es un fallo fatal');
    assert_not_contains('Warning', $r['out'], 'el aviso no se enseña');
    assert_not_contains('Algo ha fallado', $r['out']);
    assert_contains('PHP Warning:', $r['err'], 'pero queda en el log');
    assert_contains('Undefined array key "falta"', $r['err']);
    assert_not_contains('"nada"', $r['err'], 'lo silenciado con @ se respeta');
}

function test_la_pagina_de_error_no_enseña_rutas(): void
{
    $r = pe_run('throw new LogicException("fallo en " . __FILE__);');
    assert_not_contains('/shared/', $r['out']);
    assert_not_contains('.php', $r['out'], 'ninguna ruta ni nombre de fichero');
}

/**
 * Toda página HTML carga shared/page_errors.php lo primero. Una página nueva
 * sin él volvería a fallar en silencio. Las API no: allí el JSON de
 * helpers.php es la respuesta correcta.
 */
function test_todas_las_paginas_html_cargan_el_registro_de_errores(): void
{
    $paginas = ['index.php', '404.php', 'resource/index.php', 'viewer/index.php', 'profile/index.php',
                'collection/index.php', 'favorites/index.php', 'dashboard/index.php', 'dashboard/editor.php',
                'auth/signin.php', 'auth/onboarding.php', 'legal/terms.php', 'unsubscribe.php',
                'admin/create.php', 'admin/errors.php'];
    foreach ($paginas as $p) {
        subtest($p, static function () use ($p): void {
            $src = (string) file_get_contents(IAREPO_ROOT . '/' . $p);
            assert_matches('#require_once __DIR__ \. \'/(\.\./)*shared/page_errors\.php\';#', $src, "$p debe cargar shared/page_errors.php");
            $pos = strpos($src, 'page_errors.php');
            $pre = substr($src, 0, (int) $pos);
            assert_false((bool) preg_match('/\?>|echo |print |header\(/', $pre),
                "$p: page_errors.php tiene que ir antes de cualquier salida");
        });
    }
}
