<?php
// ================================================================
// tests/unit/i18n_test.php — shared/i18n.php
//
// La regla #3 del proyecto es que toda cadena visible vaya en t(). Si
// t() dejara de traducir o dejara de hacer fallback, media web saldría
// en blanco o en español para un usuario inglés, sin ningún error.
//
// ── TRAMPA DE ESTE MÓDULO ────────────────────────────────────
// lang() guarda el idioma en un `static` que se resuelve UNA sola vez
// por proceso: no hay forma de "resetearlo". Por eso:
//   · el runner solo puede ejercitar UN idioma en su propio proceso —
//     fijamos 'en' aquí abajo, antes de que nadie llame a lang();
//   · el resto de escenarios (español por defecto, cookie, cabecera
//     Accept-Language, valor inválido) van en subprocesos limpios.
// Es también la razón de que este fichero no se pueda fusionar con otro
// que use t(): el primero que llame a lang() fija el idioma de todos.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

// ANTES del require: en cuanto algo llame a lang(), el idioma queda fijo.
$_GET['lang'] = 'en';

require_once IAREPO_ROOT . '/shared/i18n.php';

/** Escenario de idioma en un proceso limpio. Devuelve [lang, t('Salir')]. */
function i18_scenario(array $get = [], array $cookie = [], string $accept = ''): array
{
    $code = "<?php\n"
          . '$_GET = ' . var_export($get, true) . ";\n"
          . '$_COOKIE = ' . var_export($cookie, true) . ";\n"
          . '$_SERVER["HTTP_ACCEPT_LANGUAGE"] = ' . var_export($accept, true) . ";\n"
          . "require 'shared/i18n.php';\n"
          . "echo json_encode([lang(), t('Salir')]);";

    $r = iarepo_php_isolated($code);
    if ($r['code'] !== 0) {
        test_fail('subproceso con código ' . $r['code'] . ': ' . trim($r['out'] . $r['err']));
    }
    $out = json_decode($r['out'], true);
    if (!is_array($out)) {
        test_fail('el subproceso no devolvió JSON: ' . iarepo_show($r['out'] . $r['err']));
    }

    return $out;
}

// ================================================================
// En el propio proceso (idioma fijado a 'en' arriba)
// ================================================================

function test_lang_respeta_el_parametro_get(): void
{
    assert_eq('en', lang(), '$_GET[lang]=en debe ganar');
}

function test_t_traduce_al_ingles(): void
{
    assert_eq('Sign out', t('Salir'));
    assert_eq('My profile', t('Mi perfil'));
}

/** Sin entrada en i18n_en.php, t() devuelve el español: nunca vacío. */
function test_t_hace_fallback_al_espanol(): void
{
    // La clave se construye en tiempo de ejecución a propósito, para que
    // el guard de i18n de quality/guards.sh no la vea como una cadena
    // pendiente de traducir: es un dato del test, no texto de la web.
    $inventada = 'cadena inventada que jamás ' . 'estará en el diccionario';
    assert_eq($inventada, t($inventada), 'sin traducción debe devolver el original, no vacío');
    assert_eq('', t(''), 'la cadena vacía no puede reventar');
}

function test_el_diccionario_ingles_es_un_array_de_strings(): void
{
    $dict = require IAREPO_ROOT . '/shared/i18n_en.php';

    assert_true(is_array($dict), 'i18n_en.php debe devolver un array');
    assert_true(count($dict) > 100, 'el diccionario tiene sospechosamente pocas entradas: ' . count($dict));

    $malas = [];
    foreach ($dict as $es => $en) {
        if (!is_string($es) || !is_string($en) || $es === '' || $en === '') {
            $malas[] = iarepo_show($es) . ' => ' . iarepo_show($en);
        }
    }
    assert_eq([], $malas, 'entradas con clave o valor no-string (o vacíos) en i18n_en.php');
}

/**
 * Las traducciones acaban dentro de atributos HTML y de objetos JS
 * (index.php inyecta `const T = {...}` con json_encode(t(...))). Una
 * comilla o un salto de línea sueltos ahí rompen la página entera.
 */
function test_las_traducciones_no_traen_html_ni_saltos_de_linea(): void
{
    $dict  = require IAREPO_ROOT . '/shared/i18n_en.php';
    $malas = [];
    foreach ($dict as $es => $en) {
        if (preg_match('/[\r\n\x00-\x08]/', $en) || preg_match('/<\s*(script|iframe|img|svg)\b/i', $en)) {
            $malas[] = $es;
        }
    }
    assert_eq([], $malas, 'traducciones con saltos de línea o etiquetas peligrosas');
}

function test_langswitchurl_conserva_la_ruta(): void
{
    // Desde la revisión 2026-09 conserva TAMBIÉN la query (quitando solo
    // 'lang', que va primero). Antes '/resource/3?lang=en&x=1' daba
    // '/resource/3?lang=es': correcto solo en la ficha; con la cabecera común
    // en todas las páginas, cambiar de idioma en una lista, el editor, la
    // baja de correos o Entrar perdía la lista, el recurso, el token o el
    // destino.
    $casos = [
        '/resource/3?lang=en&x=1' => ['es' => '/resource/3?lang=es&x=1', 'en' => '/resource/3?lang=en&x=1'],
        '/'                       => ['es' => '/?lang=es',           'en' => '/?lang=en'],
        '/profile/12'             => ['es' => '/profile/12?lang=es', 'en' => '/profile/12?lang=en'],
        '/collection/?id=5'       => ['en' => '/collection/?lang=en&id=5'],
        '/dashboard/editor.php?id=40&lang=es' => ['en' => '/dashboard/editor.php?lang=en&id=40'],
        '/unsubscribe.php?token=ab12' => ['en' => '/unsubscribe.php?lang=en&token=ab12'],
        '/auth/signin.php?return_url=%2Ffavorites%2F&save=3' => ['en' => '/auth/signin.php?lang=en&return_url=%2Ffavorites%2F&save=3'],
        '/profile/1?page=2&lang=en&lang=es' => ['en' => '/profile/1?lang=en&page=2'],
        '/?search=a%20b&rlang=es' => ['en' => '/?lang=en&search=a%20b&rlang=es'],
        // Una ruta que no es propia no se convierte en un enlace a otra web.
        '//evil.example/x?y=1'    => ['en' => '/?lang=en&y=1'],
        "/\\evil.example"         => ['en' => '/?lang=en'],
        "/\tevil"                  => ['en' => '/?lang=en'],
    ];
    foreach ($casos as $uri => $esperado) {
        foreach ($esperado as $to => $url) {
            subtest($uri . ' → ' . $to, static function () use ($uri, $to, $url): void {
                $_SERVER['REQUEST_URI'] = $uri;
                assert_eq($url, langSwitchUrl($to));
            });
        }
    }

    // Cualquier valor que no sea 'en' se trata como 'es': nada de reflejar
    // el parámetro del usuario en el HTML.
    $_SERVER['REQUEST_URI'] = '/';
    foreach (['xx', '', '"><img src=x onerror=1>', 'ES', 'en-US'] as $sucio) {
        subtest(iarepo_show($sucio), static function () use ($sucio): void {
            assert_eq('/?lang=es', langSwitchUrl($sucio), 'un idioma desconocido debe caer a es');
        });
    }
}

// ================================================================
// En subprocesos limpios (un idioma por proceso)
// ================================================================

function test_escenarios_de_resolucion_de_idioma(): void
{
    $casos = [
        'sin nada → es'              => [[],                 [],                 '',        'es'],
        'GET en → en'                => [['lang' => 'en'],   [],                 '',        'en'],
        'GET es gana a la cookie'    => [['lang' => 'es'],   ['lang' => 'en'],   '',        'es'],
        'cookie en → en'             => [[],                 ['lang' => 'en'],   '',        'en'],
        'cookie es → es'             => [[],                 ['lang' => 'es'],   '',        'es'],
        'Accept-Language en-US → en' => [[],                 [],                 'en-US,en', 'en'],
        'Accept-Language es-ES → es' => [[],                 [],                 'es-ES,es', 'es'],
        'Accept-Language fr → es'    => [[],                 [],                 'fr-FR',   'es'],
        'GET basura → es'            => [['lang' => 'xx'],   [],                 '',        'es'],
        'GET inyección → es'         => [['lang' => '"><img src=x>'], [],        '',        'es'],
        'GET en-US se recorta a en'  => [['lang' => 'en-US'], [],                '',        'en'],
        'cookie basura → es'         => [[],                 ['lang' => 'zz'],   '',        'es'],
    ];

    foreach ($casos as $label => [$get, $cookie, $accept, $esperado]) {
        subtest($label, static function () use ($get, $cookie, $accept, $esperado): void {
            [$lang, $salir] = i18_scenario($get, $cookie, $accept);
            assert_eq($esperado, $lang);
            assert_eq($esperado === 'en' ? 'Sign out' : 'Salir', $salir, 't() debe seguir a lang()');
        });
    }
}

/** lang() no puede imprimir nada: se llama antes de la primera línea de HTML. */
function test_lang_no_imprime_aunque_intente_poner_la_cookie(): void
{
    $r = iarepo_php_isolated(
        "<?php \$_GET['lang'] = 'en'; require 'shared/i18n.php'; lang(); echo 'FIN';"
    );
    assert_eq(0, $r['code'], 'no puede fallar: ' . trim($r['err']));
    assert_eq('FIN', $r['out'], 'lang() ha impreso algo antes de la página');
}

/**
 * t() solo acepta un texto literal en PHP.
 *
 * El 2026-06-13 un reemplazo en bloque dejó `t(T.creating)` —la sintaxis del
 * objeto T de JavaScript— dentro de un `<?= ?>` de dashboard/index.php. En PHP
 * eso es la constante T concatenada con la constante creating: "Undefined
 * constant T", error fatal, y el panel del autor murió tres meses sin que
 * `php -l` ni ningún test lo notaran.
 *
 * Además, con un argumento que no es literal, la clave no se puede buscar en
 * shared/i18n_en.php: se queda sin traducir en silencio. Se recorre con el
 * tokenizador de PHP (no con regex) para no confundir `t(` con `->t(` o con
 * texto dentro de cadenas y comentarios.
 */
function test_t_solo_acepta_literales_en_php(): void
{
    $out = [];
    exec('git -C ' . escapeshellarg(IAREPO_ROOT) . ' ls-files "*.php"', $out, $rc);
    assert_eq(0, $rc, 'git ls-files debe funcionar');

    $malos = [];
    foreach ($out as $rel) {
        if (str_starts_with($rel, 'tests/'))
            continue;   // los tests llaman a t($var) a propósito
        $toks = token_get_all((string) file_get_contents(IAREPO_ROOT . '/' . $rel));
        $n    = count($toks);
        $sig  = static function (int $i, int $dir) use ($toks, $n): int {
            for ($i += $dir; $i >= 0 && $i < $n; $i += $dir)
                if (!is_array($toks[$i]) || !in_array($toks[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
                    return $i;
            return -1;
        };
        for ($i = 0; $i < $n; $i++) {
            $tk = $toks[$i];
            if (!is_array($tk) || $tk[0] !== T_STRING || $tk[1] !== 't')
                continue;
            $prev = $sig($i, -1);
            if ($prev >= 0 && is_array($toks[$prev])
                && in_array($toks[$prev][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true))
                continue;
            $open = $sig($i, 1);
            if ($open < 0 || $toks[$open] !== '(')
                continue;
            $arg  = $sig($open, 1);
            $next = $arg >= 0 ? $sig($arg, 1) : -1;
            $ok   = $arg >= 0 && is_array($toks[$arg]) && $toks[$arg][0] === T_CONSTANT_ENCAPSED_STRING
                 && $next >= 0 && $toks[$next] === ')';
            if (!$ok)
                $malos[] = "$rel:{$tk[2]}";
        }
    }
    assert_eq([], $malos, 't() con un argumento que no es un texto literal');
}

/**
 * En api/*.php, '?lang=' es un FILTRO (idioma del recurso), no el idioma de
 * la interfaz. Si una API que traduce (carga shared/labels.php, i18n.php o
 * ui.php) deja $_GET['lang'] a la vista de lang(), el filtro planta una
 * cookie `lang` de un año: pulsar «Inglés» en los filtros de la portada
 * pasaba la web entera a inglés [2026-09]. La API tiene que apartar el
 * filtro ANTES de la primera llamada que traduce. Esta es la red barata (sin
 * BD); tests/integration/api_lang_test.php lo prueba por HTTP.
 */
function test_ninguna_api_que_traduce_lee_el_filtro_lang_como_idioma(): void
{
    $vistas = 0;
    foreach (glob(IAREPO_ROOT . '/api/*.php') as $f) {
        $src = iarepo_php_code_only((string) file_get_contents($f));
        if (!preg_match('#require(_once)?\s*[^;]*shared/(labels|i18n|ui)\.php#', $src))
            continue;
        $vistas++;
        $rel = 'api/' . basename($f);

        $unset = strpos($src, "unset(\$_GET['lang'])");
        assert_true($unset !== false, "$rel traduce pero no aparta \$_GET['lang'] antes de hacerlo");

        // Primera llamada que puede acabar en lang(): t(), lang() o una
        // función de shared/labels.php (todas empiezan por iarepo_ y
        // terminan en _label/_labels/_options).
        preg_match('/(?<![\w>:$])(t|lang|iarepo_\w*label\w*|iarepo_level_options)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
        if ($m)
            assert_true($unset < $m[0][1], "$rel llama a {$m[1][0]}() antes de apartar el filtro ?lang=");

        // Después de apartarlo, nadie vuelve a leer $_GET['lang'] (volvería a
        // estar vacío: el filtro dejaría de filtrar en silencio).
        assert_not_matches('/\$_GET\[\s*[\'"]lang[\'"]\s*\]|iarepo_get_str\(\s*[\'"]lang[\'"]\s*\)/',
            substr($src, $unset + 20), "$rel lee ?lang= después de apartarlo");
    }
    assert_true($vistas > 0, 'api/resources.php traduce etiquetas: si ya no, revisa este test');
}

/** Todos los literales que se pasan a t() en el código servido (no en tests/). */
function i18_t_literals(): array
{
    $out = [];
    exec('git -C ' . escapeshellarg(IAREPO_ROOT) . ' ls-files -co --exclude-standard "*.php"', $out, $rc);
    assert_eq(0, $rc, 'git ls-files debe funcionar');

    $used = [];
    foreach ($out as $rel) {
        if (str_starts_with($rel, 'tests/') || $rel === 'shared/i18n_en.php')
            continue;
        $toks = token_get_all((string) file_get_contents(IAREPO_ROOT . '/' . $rel));
        $n    = count($toks);
        $sig  = static function (int $i) use ($toks, $n): int {
            for ($i++; $i < $n; $i++)
                if (!is_array($toks[$i]) || $toks[$i][0] !== T_WHITESPACE)
                    return $i;
            return -1;
        };
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($toks[$i]) || $toks[$i][0] !== T_STRING || $toks[$i][1] !== 't')
                continue;
            $open = $sig($i);
            if ($open < 0 || $toks[$open] !== '(')
                continue;
            $arg = $sig($open);
            if ($arg >= 0 && is_array($toks[$arg]) && $toks[$arg][0] === T_CONSTANT_ENCAPSED_STRING) {
                // Literal PHP → su valor. Entre comillas simples solo \\ y \' son escapes.
                $lit = $toks[$arg][1];
                $used[$lit[0] === "'" ? strtr(substr($lit, 1, -1), ['\\\\' => '\\', "\\'" => "'"])
                                      : stripcslashes(substr($lit, 1, -1))] = true;
            }
        }
    }
    return $used;
}

/**
 * El diccionario no guarda traducciones que ningún t() usa. El rediseño
 * 2026-09 dejó 144 claves huérfanas —«Fork», «Likes», «Dashboard», «Mis
 * favoritos», «El GitHub para profesores»…— y 4 duplicadas entre secciones
 * (con claves repetidas gana la ÚLTIMA: cambiar la primera no hacía nada).
 * Las huérfanas no rompen nada, pero esconden qué vocabulario está vivo y
 * hacen que alguien traduzca dos veces lo mismo. Si quitas un t(), quita su
 * clave en el mismo cambio.
 */
function test_el_diccionario_no_tiene_claves_sin_uso_ni_repetidas(): void
{
    $used = i18_t_literals();
    $dict = require IAREPO_ROOT . '/shared/i18n_en.php';
    assert_true(count($used) > 100, 'el escáner de t() no encontró casi nada: revisa i18_t_literals()');
    assert_eq([], array_values(array_filter(array_keys($dict), static fn($k) => !isset($used[$k]))),
        'claves de shared/i18n_en.php que ningún t() usa');

    // Repetidas: el array PHP ya las fusionó, así que se cuentan en el texto.
    $toks = token_get_all((string) file_get_contents(IAREPO_ROOT . '/shared/i18n_en.php'));
    $seen = [];
    $dups = [];
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        if (!is_array($toks[$i]) || $toks[$i][0] !== T_CONSTANT_ENCAPSED_STRING)
            continue;
        for ($j = $i + 1; $j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE; $j++);
        if (!is_array($toks[$j] ?? null) || $toks[$j][0] !== T_DOUBLE_ARROW)
            continue;
        $k = $toks[$i][1];
        if (isset($seen[$k]))
            $dups[] = $k . ' (líneas ' . $seen[$k] . ' y ' . $toks[$i][2] . ')';
        $seen[$k] = $toks[$i][2];
    }
    assert_eq([], $dups, 'claves repetidas en shared/i18n_en.php (gana la última: deja una sola)');
}

/** Lo mismo para las excepciones de quality/i18n_ignore.txt. */
function test_las_excepciones_de_i18n_se_siguen_usando(): void
{
    $used   = i18_t_literals();
    $lines  = file(IAREPO_ROOT . '/quality/i18n_ignore.txt', FILE_IGNORE_NEW_LINES);
    $sobran = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln !== '' && $ln[0] !== '#' && !isset($used[$ln]))
            $sobran[] = $ln;
    }
    assert_eq([], $sobran, 'entradas de quality/i18n_ignore.txt que ya no usa ningún t(): bórralas');
}
