<?php
// ================================================================
// tests/unit/helpers_isolation_test.php
//
// Dos cosas a la vez:
//
//  1. Convierte la REGLA CRÍTICA #1 del proyecto en un test ejecutable.
//     "Nunca cargues shared/helpers.php en una página HTML" no es una
//     recomendación de estilo: helpers.php arrastra error_handler.php,
//     que registra handlers que imprimen JSON y hacen exit(1). Aquí se
//     demuestra en vivo, con un subproceso, en vez de repetirlo en un
//     comentario que nadie lee.
//
//  2. Prueba las funciones puras de helpers.php — sanitize() y h() —
//     que NO se pueden cargar en el runner justo por lo anterior. Se
//     ejecutan en un subproceso aislado (iarepo_php_isolated()), que es
//     la única forma honesta de probarlas sin reimplementarlas aquí:
//     un test que copia la función que dice probar no prueba nada.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

/** Lanza código en un subproceso con helpers.php ya cargado y devuelve su JSON. */
function hi_with_helpers(string $php): array
{
    $r = iarepo_php_isolated("<?php require 'shared/helpers.php'; " . $php);
    if ($r['code'] !== 0) {
        test_fail('el subproceso murió con código ' . $r['code'] . ': ' . trim($r['out'] . ' ' . $r['err']));
    }
    $data = json_decode($r['out'], true);
    if (!is_array($data)) {
        test_fail('el subproceso no devolvió JSON: ' . iarepo_show($r['out'] . $r['err']));
    }

    return $data;
}

// ================================================================
// 1 · La regla crítica #1, como test
// ================================================================

/** El propio runner tiene que estar limpio: si no, mentiría al fallar. */
function test_el_runner_no_ha_cargado_helpers_ni_error_handler(): void
{
    foreach (get_included_files() as $f) {
        assert_not_matches(
            '#/shared/(helpers|error_handler)\.php$#',
            $f,
            'un test ha cargado helpers.php dentro del runner: sus handlers harían exit(1) al primer fallo'
        );
    }
}

/**
 * La demostración: cargar helpers.php registra un exception handler que
 * IMPRIME JSON Y TERMINA EL PROCESO. En una página HTML eso significa
 * medio HTML + un blob JSON pegado; en el runner, cero informe.
 */
function test_helpers_registra_handlers_que_imprimen_json_y_hacen_exit(): void
{
    $r = iarepo_php_isolated(
        "<?php require 'shared/helpers.php'; echo 'HTML PARCIAL'; throw new RuntimeException('boom');"
    );

    assert_eq(1, $r['code'], 'el handler termina el proceso con exit(1)');
    assert_contains('HTML PARCIAL', $r['out'], 'lo ya impreso se queda a medias');
    assert_contains('"ok":false', $r['out'], 'y encima le pega un JSON');
    assert_contains('INTERNAL_ERROR', $r['out']);
    assert_matches('/HTML PARCIAL\{/', $r['out'], 'el JSON se concatena al HTML: exactamente el fallo silencioso');
}

/** Sin helpers.php una excepción se comporta como debe: nada de JSON. */
function test_sin_helpers_una_excepcion_no_escupe_json(): void
{
    $r = iarepo_php_isolated("<?php throw new RuntimeException('boom');");

    assert_eq(255, $r['code'], 'un fatal normal de PHP sale con 255');
    assert_not_contains('"ok":false', $r['out'], 'sin helpers.php no hay JSON de por medio');
}

// ================================================================
// 2 · Funciones puras de helpers.php (en subproceso)
// ================================================================

function test_sanitize(): void
{
    $casos = [
        'recorta espacios'   => ['  hola  ', 255, 'hola'],
        'tabs y saltos'      => ["\n\thola\r\n", 255, 'hola'],
        'no toca el interior'=> ['a  b', 255, 'a  b'],
        'cadena vacía'       => ['', 255, ''],
        'solo espacios'      => ['     ', 255, ''],
        'trunca'             => ['abcdefghij', 4, 'abcd'],
        'trunca por CARACTER'=> ['ááááá', 3, 'ááá'],
        'acentos intactos'   => ['Ana Pérez', 255, 'Ana Pérez'],
        'emoji'              => ['🙂🙂🙂', 2, '🙂🙂'],
        'recorta y luego trunca' => ['   abcdef', 3, 'abc'],
    ];

    $code = 'echo json_encode(array_map(fn($c) => sanitize($c[0], $c[1]), '
          . var_export(array_map(static fn(array $c): array => [$c[0], $c[1]], $casos), true) . '));';
    $got = hi_with_helpers($code);

    foreach ($casos as $label => [, , $esperado]) {
        subtest($label, static function () use ($label, $esperado, $got): void {
            assert_eq($esperado, $got[$label] ?? null);
        });
    }
}

/**
 * sanitize() NO escapa nada: solo recorta y acota. Es un test de
 * expectativas, no de seguridad — si alguien creyera que "sanitize"
 * limpia HTML, metería XSS almacenado sin darse cuenta.
 */
function test_sanitize_no_escapa_html_a_pesar_del_nombre(): void
{
    $got = hi_with_helpers('echo json_encode([sanitize(\'<b onclick="x">&\')]);');
    assert_eq('<b onclick="x">&', $got[0], 'sanitize NO es un escapador: el escapado es cosa de h()');
}

function test_h_escapa_para_html(): void
{
    $casos = [
        'menor y mayor'   => ['<b>',            '&lt;b&gt;'],
        'ampersand'       => ['a & b',          'a &amp; b'],
        'comilla doble'   => ['di "hola"',      'di &quot;hola&quot;'],
        'comilla simple'  => ["d'Artagnan",     'd&apos;Artagnan'],
        'atributo hostil' => ['x" onerror="a',  'x&quot; onerror=&quot;a'],
        'imagen hostil'   => ['<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;'],
        'acentos'         => ['Ana Pérez',      'Ana Pérez'],
        'emoji'           => ['🙂',             '🙂'],
        'vacío'           => ['',               ''],
        'ya escapado'     => ['&amp;',          '&amp;amp;'],
    ];

    $got = hi_with_helpers('echo json_encode(array_map("h", '
        . var_export(array_map(static fn(array $c): string => $c[0], $casos), true) . '));');

    foreach ($casos as $label => [$in, $esperado]) {
        subtest($label, static function () use ($label, $esperado, $got): void {
            assert_eq($esperado, $got[$label] ?? null);
        });
    }
}

/** Ficheros .php del repo (sin .git ni los propios tests). */
function hi_php_files(): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(IAREPO_ROOT, FilesystemIterator::SKIP_DOTS),
            static function ($file, $key, $iter): bool {
                $name = $file->getFilename();
                if ($iter->hasChildren()) {
                    return !in_array($name, ['.git', 'tests', 'node_modules', 'thumbnails', 'assets'], true);
                }

                return str_ends_with($name, '.php');
            }
        )
    );
    foreach ($it as $f) {
        $out[] = $f->getPathname();
    }
    sort($out);

    return $out;
}

/**
 * Las páginas HTML NO pueden cargar helpers.php (regla #1), así que cada
 * una define su propia h(). Hoy hay 7 copias. Si a alguna se le olvida
 * ENT_QUOTES, htmlspecialchars deja pasar las comillas simples y
 * cualquier atributo escrito con comilla simple queda abierto a XSS:
 *     <a title='<?= h($x) ?>'>   con $x = "' onmouseover=alert(1) '"
 * Este test recorre TODAS las definiciones del repo, no una lista fija,
 * así que también cubre las páginas que aún no existen.
 *
 * NO exige que las 7 sean idénticas: dos usan ENT_QUOTES a secas y las
 * otras ENT_QUOTES|ENT_HTML5. La diferencia es cosmética (&#039; frente
 * a &apos;), ambas escapan igual de bien, y bloquear por eso sería el
 * falso positivo que hace que alguien desactive el gate.
 */
function test_toda_h_del_repo_escapa_con_ent_quotes_y_utf8(): void
{
    $vistos = 0;

    foreach (hi_php_files() as $path) {
        $src = (string) file_get_contents($path);
        if (!preg_match('/function\s+h\s*\([^)]*\)[^{]*\{(.*?)\n?\}/s', $src, $m)) {
            continue;
        }
        $vistos++;
        $rel    = iarepo_relpath($path);
        $cuerpo = trim((string) preg_replace('/\s+/', ' ', $m[1]));

        subtest($rel, static function () use ($cuerpo, $rel): void {
            assert_contains('htmlspecialchars', $cuerpo, "{$rel}: h() debe escapar de verdad");
            assert_contains('ENT_QUOTES', $cuerpo, "{$rel}: sin ENT_QUOTES las comillas simples pasan sin escapar");
            assert_contains('UTF-8', $cuerpo, "{$rel}: h() debe fijar el juego de caracteres a UTF-8");
        });
    }

    assert_true($vistos >= 5, "solo he encontrado {$vistos} definiciones de h(); ¿ha cambiado el patrón?");
}
