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
    $casos = [
        '/resource/3?lang=en&x=1' => ['es' => '/resource/3?lang=es', 'en' => '/resource/3?lang=en'],
        '/'                       => ['es' => '/?lang=es',           'en' => '/?lang=en'],
        '/profile/12'             => ['es' => '/profile/12?lang=es', 'en' => '/profile/12?lang=en'],
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
