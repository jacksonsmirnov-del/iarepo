<?php
// ================================================================
// tests/unit/search_test.php — shared/search.php
//
// La razón de ser de este fichero: el buscador construye SQL a mano a
// partir de una cadena que escribe cualquier visitante anónimo. Antes
// del arreglo, "C++" devolvía HTTP 500 porque el input crudo entraba en
// AGAINST(... IN BOOLEAN MODE). Estos tests fijan las invariantes que
// hacen imposible repetirlo, y cubren los 9 casos de la tabla de
// evidencia reproducida en producción.
//
// Se organizan en tres bloques:
//   1. INVARIANTES  — se comprueban sobre TODO el corpus hostil y sobre
//                     un fuzz determinista. Son las que no pueden fallar
//                     nunca, con ninguna entrada.
//   2. COMPORTAMIENTO — los casos concretos de la tabla de evidencia.
//   3. FUNCIONES PURAS — normalize/tokenize/stem/like_escape/raw_phrase.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

require_once IAREPO_ROOT . '/shared/search.php';

// ================================================================
// Vocabulario SQL admisible
// ================================================================
// El texto del SQL generado se compone SOLO de fragmentos constantes:
// ningún byte del usuario debe llegar a él (todo va por parámetros).
// Estos son los únicos caracteres que aparecen en esos fragmentos:
//   letras, dígitos, _  → identificadores (r.title, CONCAT_WS, MATCH…)
//   espacio , . ( )     → sintaxis
//   ' !                 → los literales ' ' y '!' de ESCAPE '!'
//   ?                   → placeholders
//   * / + =             → "* 2", "/ 200", " + " del score, "rts.id = r.id"
// Cualquier otro carácter (comillas dobles, ;, --, %, \, emoji, control)
// significa que el input se ha interpolado en el SQL: inyección.
const ST_SQL_CHARS = '#^[A-Za-z0-9_ ,.()\'?!*/+=]+$#';

/**
 * Corpus de entradas: normales, hostiles, unicode, de tamaño y de
 * inyección. La clave es la etiqueta que sale en el informe si falla.
 *
 * @return array<string,string>
 */
function st_corpus(): array
{
    return [
        // ── normales ─────────────────────────────────────────────
        'una palabra'           => 'matematicas',
        'prefijo'               => 'matem',
        'dos palabras'          => 'ondas sonido',
        'tres palabras'         => 'the water cycle',
        'mayusculas'            => 'MATEMÁTICAS',
        'caja mixta'            => 'EnErGíA CinÉtica',
        'acentos'               => 'física química',
        'guion interior'        => 'física-química',
        'eñe'                   => 'diseño de niños',
        'digitos'               => '2024 examen',
        'espacios sobrantes'    => '   ondas    sonido   ',
        'singular'              => 'onda',
        'plural'                => 'ondas',

        // ── operadores del parser fulltext ───────────────────────
        'vacio'                 => '',
        'solo espacios'         => '     ',
        'tabs y saltos'         => "\t\n\r ",
        'mas'                   => '+',
        'mas triple'            => '+++',
        'menos'                 => '-',
        'asterisco'             => '*',
        'comilla doble'         => '"',
        'comillas dobles'       => '""',
        'comilla simple'        => "'",
        'comillas simples'      => "''",
        'parentesis'            => '()',
        'parentesis abierto'    => '(ondas',
        'tilde'                 => '~',
        'arroba'                => '@',
        'menor mayor'           => '<>',
        'todos los operadores'  => '+ - * " ( ) ~ < > @',
        'operadores pegados'    => '+-*"()~<>@',
        'C++'                   => 'C++',
        'C++ en una frase'      => 'introduccion a C++',
        'C# y .NET'             => 'C# .NET',

        // ── comodines de LIKE y escapes ──────────────────────────
        'porcentaje'            => '100%',
        'guion bajo'            => 'a_b',
        'admiracion'            => 'a!b',
        'los tres comodines'    => '%_!',
        'barra invertida'       => '\\',
        'barra invertida doble' => '\\\\',

        // ── inyección SQL ────────────────────────────────────────
        'inyeccion clasica'     => "' OR 1=1 --",
        'inyeccion drop'        => "'; DROP TABLE resources; --",
        'inyeccion union'       => "1' UNION SELECT password FROM users --",
        'inyeccion comentario'  => "ondas'/*",
        'inyeccion backtick'    => '`resources`',
        'inyeccion escape'      => "\\' OR '1'='1",
        'punto y coma'          => ';',
        'guiones de comentario' => '-- ',

        // ── unicode y bytes ──────────────────────────────────────
        'emojis'                => '🙂🙂',
        'emoji con texto'       => '🙂 ondas 🙂',
        'CJK'                   => '中文 검색',
        'arabe'                 => 'الفيزياء',
        'griego'                => 'φυσική',
        'caracteres de control' => "\x00\x01\x02\x1f\x7f",
        'BOM al principio'      => "\u{FEFF}ondas",
        'UTF-8 invalido'        => "\xC3\x28",
        'bytes crudos'          => "\xff\xfe\xfd",
        'nulo en medio'         => "on\x00das",
        'ligadura'              => "\u{FB01}sica",

        // ── tamaños ──────────────────────────────────────────────
        '200 caracteres'        => str_repeat('x', 200),
        '480 caracteres'        => trim(str_repeat('palabra ', 60)),
        '20 palabras'           => 'termino1 termino2 termino3 termino4 termino5 termino6 termino7 '
                                 . 'termino8 termino9 termino10 termino11 termino12 termino13 '
                                 . 'termino14 termino15 termino16 termino17 termino18 termino19 termino20',
        'token de 300'          => str_repeat('a', 300),

        // ── stopwords ────────────────────────────────────────────
        'solo un stopword'      => 'the',
        'solo stopwords es'     => 'de la',
        'stopwords es y en'     => 'the of the and de la el',
        'stopword y termino'    => 'la energia',

        // ── tokens cortos ────────────────────────────────────────
        'token de 1'            => 'a',
        'token de 2'            => 'pH',
        'token de 3'            => 'ADN',
        'corto mas largo'       => 'pH escala',
        'cero'                  => '0',
    ];
}

/**
 * Clasifica los '?' del SQL POR EL OPERADOR QUE LOS CONSUME, en orden.
 *
 * Sin esto sólo podíamos comprobar "los parámetros tienen buena pinta".
 * Con esto comprobamos que CADA parámetro alimenta al operador correcto:
 * un `CONCAT_WS(...) REGEXP '%ph%'` es SQL perfectamente válido, no lanza
 * ningún error y devuelve filas equivocadas en silencio. Es exactamente
 * el fallo que un descuadre de orden produciría.
 *
 * @return string[] 'ft' | 'rx' | 'like', uno por '?' del SQL
 */
function st_placeholder_kinds(string $sql): array
{
    preg_match_all('/(AGAINST\(|REGEXP |LIKE )\?/', $sql, $m);

    $kinds = [];
    foreach ($m[1] as $op) {
        $kinds[] = match (trim($op)) {
            'AGAINST(' => 'ft',
            'REGEXP'   => 'rx',
            default    => 'like',
        };
    }

    // Si esto no cuadra hay un '?' que no consume ninguno de los tres
    // operadores conocidos: el clasificador se ha quedado obsoleto y el
    // resto de comprobaciones estaría midiendo el aire.
    assert_eq(substr_count($sql, '?'), count($kinds), 'hay un "?" que no alimenta a AGAINST/REGEXP/LIKE');

    return $kinds;
}

/** ¿Es un parámetro de LIKE bien formado y con los comodines escapados? */
function st_is_escaped_needle(string $p): bool
{
    if (strlen($p) < 2 || $p[0] !== '%' || substr($p, -1) !== '%') {
        return false;
    }
    // Quita las secuencias escapadas (!!, !%, !_); si sobra algún comodín,
    // es un comodín que el usuario ha logrado colar sin escapar.
    // A nivel de BYTES a propósito: un parámetro puede llevar cualquier
    // basura del usuario y /u devolvería null ante UTF-8 inválido.
    $rest = (string) preg_replace('/!(.)/s', '', substr($p, 1, -1));

    return !str_contains($rest, '%') && !str_contains($rest, '_');
}

/**
 * TODAS las invariantes que deben cumplirse con CUALQUIER entrada.
 * Es el corazón del fichero: si algo aquí se rompe, hay un 500 esperando
 * en producción o, peor, una inyección.
 */
function st_check_invariants(array $r): void
{
    // ── Forma del contrato ───────────────────────────────────────
    foreach (['mode', 'where', 'params', 'score', 'score_params', 'terms', 'debug'] as $k) {
        assert_true(array_key_exists($k, $r), "falta la clave del contrato '{$k}'");
    }
    // El contrato del fichero documenta estos tres modos. 'fulltext' no
    // se emite nunca (siempre hay brazo LIKE); ver tests/README.md.
    assert_true(in_array($r['mode'], ['none', 'like', 'hybrid'], true), 'mode desconocido: ' . $r['mode']);
    assert_true(is_string($r['where']), 'where debe ser string');
    assert_true(is_array($r['params']), 'params debe ser array');
    assert_true(is_array($r['terms']), 'terms debe ser array');
    assert_true(is_array($r['score_params']), 'score_params debe ser array');
    foreach (['ft', 'like', 'short', 'dropped', 'groups'] as $k) {
        assert_true(array_key_exists($k, $r['debug']), "falta debug['{$k}']");
    }

    // ── mode 'none': el llamador no debe añadir NADA al WHERE ────
    if ($r['mode'] === 'none') {
        assert_eq('', $r['where'], 'mode none no puede traer WHERE');
        assert_eq([], $r['params'], 'mode none no puede traer params');
        assert_null($r['score'], 'mode none no puede traer score');
        assert_eq([], $r['score_params'], 'mode none no puede traer score_params');
        assert_eq([], $r['terms'], 'mode none no puede traer terms');
        assert_eq('', $r['debug']['ft'], 'mode none no puede traer cadena fulltext');
        return;
    }

    $w  = $r['where'];
    $sc = (string) $r['score'];
    assert_neq('', $w, 'un modo distinto de none exige WHERE');
    assert_not_null($r['score'], 'un modo distinto de none exige score');

    // ── Placeholders == parámetros ───────────────────────────────
    // Descuadrar esto es SQLSTATE[HY093] en producción, no un test rojo.
    assert_eq(substr_count($w, '?'), count($r['params']), 'placeholders del WHERE vs params');
    assert_eq(substr_count($sc, '?'), count($r['score_params']), 'placeholders del score vs score_params');

    // ── Ni un byte del usuario en el TEXTO del SQL ───────────────
    assert_matches(ST_SQL_CHARS, $w, 'carácter ajeno al vocabulario SQL en el WHERE (¿interpolación?)');
    assert_matches(ST_SQL_CHARS, $sc, 'carácter ajeno al vocabulario SQL en el score (¿interpolación?)');
    foreach (['DROP', 'UNION', 'SELECT password', 'DELETE', 'INSERT', 'UPDATE', '--', '/*'] as $needle) {
        assert_not_contains($needle, strtoupper($w), 'palabra peligrosa en el WHERE');
    }

    // ── Paréntesis balanceados ───────────────────────────────────
    assert_eq(substr_count($w, '('), substr_count($w, ')'), 'paréntesis desbalanceados en el WHERE');
    assert_eq(substr_count($sc, '('), substr_count($sc, ')'), 'paréntesis desbalanceados en el score');

    // ── Comillas: los únicos literales son ' ' y '!' ─────────────
    foreach (['where' => $w, 'score' => $sc] as $etiqueta => $sql) {
        assert_eq(0, substr_count($sql, "'") % 2, "comillas simples desbalanceadas en el {$etiqueta}");
        preg_match_all("/'([^']*)'/", $sql, $m);
        $unexpected = array_values(array_diff(array_unique($m[1]), ['!', ' ']));
        assert_eq([], $unexpected, "literal SQL inesperado en el {$etiqueta}");
    }

    // ── Cadena fulltext: lista blanca ────────────────────────────
    $ft = $r['debug']['ft'];
    assert_true(
        $ft === '' || (bool) preg_match(IAREPO_FT_SAFE, $ft),
        'cadena fulltext fuera de la lista blanca: ' . $ft
    );
    assert_eq($ft !== '' ? 'hybrid' : 'like', $r['mode'], 'mode incoherente con la presencia de brazo fulltext');

    // ── Ningún stopword con '+' ──────────────────────────────────
    // Un solo '+<stopword>*' devuelve CERO filas para toda la consulta.
    $offenders = [];
    foreach (IAREPO_STOP as $s) {
        if (str_contains($ft, '+' . $s . '*')) {
            $offenders[] = $s;
        }
    }
    assert_eq([], $offenders, 'stopword emitido con "+" (anula la consulta fulltext entera)');

    // ── Parámetros ───────────────────────────────────────────────
    $all = array_merge($r['params'], $r['score_params']);
    foreach ($all as $i => $p) {
        assert_true(is_string($p), "el parámetro #{$i} no es string: " . iarepo_show($p));
    }
    if ($r['mode'] === 'hybrid') {
        assert_eq($ft, $r['params'][0], 'el primer parámetro del WHERE debe ser la cadena fulltext');
    }

    // Sólo hay TRES formas admisibles de parámetro, y cada una tiene que
    // caer justo en el operador que le corresponde:
    //   ft   → la cadena de AGAINST(), ya validada contra IAREPO_FT_SAFE
    //   rx   → patrón de frontera de palabra (término corto del usuario) o de
    //          PRINCIPIO de palabra (sinónimo), construido SÓLO con un token
    //          normalizado (jamás con la frase cruda: metacaracteres)
    //   like → aguja con los comodines del usuario escapados
    foreach (['where' => [$w, $r['params']], 'score' => [$sc, $r['score_params']]] as $etiqueta => [$sql, $params]) {
        foreach (st_placeholder_kinds($sql) as $i => $kind) {
            $p = $params[$i];
            $ok = match ($kind) {
                'ft'   => $p === $ft && (bool) preg_match(IAREPO_FT_SAFE, $p),
                'rx'   => iarepo_is_word_regexp($p) || iarepo_is_prefix_regexp($p),
                default => st_is_escaped_needle($p),
            };
            assert_true($ok, "{$etiqueta}: el parámetro #{$i} no vale para un '{$kind}': " . iarepo_show($p));
        }
    }

    // ── Grupos de sinónimos ──────────────────────────────────────
    $groups = $r['debug']['groups'];
    assert_true(is_array($groups), 'debug[groups] debe ser array');
    assert_eq(count($r['debug']['like']), count($groups), 'un grupo por término exacto');
    foreach ($groups as $i => $g) {
        assert_true(is_array($g) && $g !== [], "el grupo #{$i} está vacío");
        // El término EXACTO es SIEMPRE el primero: de eso depende que el
        // score sepa distinguir "lo que el usuario escribió" del sinónimo.
        assert_eq($r['debug']['like'][$i], $g[0], "groups[{$i}][0] debe ser el término exacto");
        assert_true(count($g) <= IAREPO_MAX_SYNONYMS, "el grupo #{$i} supera IAREPO_MAX_SYNONYMS");
        assert_eq(count($g), count(array_unique($g)), "el grupo #{$i} tiene miembros repetidos");

        // Un SINÓNIMO corto se colaría por el brazo LIKE y arrastraría el
        // catálogo ('+(onda* ph*)' trae "Photosynthesis"). Sólo el término
        // del usuario puede ser corto, y ése va por frontera de palabra.
        foreach (array_slice($g, 1) as $syn) {
            assert_true(
                mb_strlen($syn, 'UTF-8') >= IAREPO_FT_MIN,
                "el sinónimo '{$syn}' del grupo #{$i} es más corto que IAREPO_FT_MIN"
            );
            assert_matches('/^[\p{L}\p{N}]+$/u', $syn, "el sinónimo '{$syn}' no está normalizado");
            assert_false(in_array($syn, IAREPO_STOP, true), "el sinónimo '{$syn}' es un stopword");
        }
    }

    // ── terms se devuelve al navegador (API: search.terms) ───────
    // El TIPO de cada término lo comprueba aparte
    // test_BUG_terms_numericos_salen_como_int_en_vez_de_string(): hoy
    // está en rojo a propósito. Aquí solo validamos la forma.
    foreach ($r['terms'] as $t) {
        assert_matches('/^[\p{L}\p{N}]+$/u', $t, 'un término contiene algo que no es letra ni dígito');
        assert_true(mb_strlen($t, 'UTF-8') <= IAREPO_MAX_TOKEN, 'término más largo que IAREPO_MAX_TOKEN');
    }
    assert_true(count($r['terms']) <= IAREPO_MAX_TERMS, 'más términos que IAREPO_MAX_TERMS');
}

// ================================================================
// 1 · INVARIANTES
// ================================================================

/** Todo el corpus hostil, entrada por entrada. */
function test_invariantes_sobre_el_corpus_hostil(): void
{
    foreach (st_corpus() as $label => $raw) {
        subtest($label, static function () use ($raw): void {
            st_check_invariants(iarepo_build_search($raw));
        });
    }
}

/**
 * Fuzz determinista (semilla fija: reproducible, no "flaky"). Genera
 * cadenas con el alfabeto que más daño hace, incluidos bytes que no son
 * UTF-8 válido, y exige las mismas invariantes.
 */
function test_invariantes_bajo_fuzz_determinista(): void
{
    $alfabeto = [
        'a', 'e', 'o', 's', 'z', 'á', 'ñ', 'ü', '0', '9', ' ', '  ',
        '+', '-', '*', '"', "'", '(', ')', '~', '<', '>', '@', '%', '_', '!',
        '\\', '/', ';', '#', '&', '|', '^', '$', '.', ',', ':', '=',
        "\n", "\t", "\x00", "\x1b", '🙂', '中', 'ß', 'İ', "\u{FEFF}",
    ];
    $n = count($alfabeto);

    mt_srand(20260804); // reproducible
    $fallos = 0;
    for ($i = 0; $i < 1500; $i++) {
        $len = mt_rand(0, 14);
        $s   = '';
        for ($j = 0; $j < $len; $j++) {
            $s .= $alfabeto[mt_rand(0, $n - 1)];
        }
        if (mt_rand(0, 15) === 0) {
            $s .= chr(mt_rand(128, 255)); // byte suelto: UTF-8 inválido a propósito
        }

        if (!subtest('fuzz#' . $i . ' ' . bin2hex(substr($s, 0, 24)), static function () use ($s): void {
            st_check_invariants(iarepo_build_search($s));
        })) {
            if (++$fallos >= 5) {
                return; // ya está demostrado; no inundes el informe
            }
        }
    }
}

/** Funciones puras: dos llamadas idénticas devuelven exactamente lo mismo. */
function test_es_pura_e_idempotente(): void
{
    foreach (st_corpus() as $label => $raw) {
        subtest($label, static function () use ($raw): void {
            assert_eq(
                serialize(iarepo_build_search($raw)),
                serialize(iarepo_build_search($raw)),
                'dos llamadas con la misma entrada difieren'
            );
        });
    }
}

/** Ninguna función pura puede imprimir nada (romperían las cabeceras). */
function test_no_imprime_nada(): void
{
    foreach (st_corpus() as $label => $raw) {
        subtest($label, static function () use ($raw): void {
            assert_no_output(static function () use ($raw): void {
                iarepo_build_search($raw);
                iarepo_normalize($raw);
                iarepo_tokenize($raw);
                iarepo_raw_phrase($raw);
            });
        });
    }
}

/**
 * La lista de stopwords DEBE cubrir la lista por defecto de InnoDB.
 * Si alguien recorta IAREPO_STOP y se cuela un '+the*', la búsqueda
 * devuelve cero filas sin ningún error: fallo silencioso puro.
 */
function test_stopwords_cubren_la_lista_por_defecto_de_innodb(): void
{
    $innodb = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for',
        'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the',
        'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];
    $faltan = array_values(array_diff($innodb, IAREPO_STOP));
    assert_eq([], $faltan, 'stopwords de InnoDB ausentes de IAREPO_STOP');

    // Y ninguna de ellas puede acabar en el brazo fulltext acompañando a
    // un término real (que sí debe sobrevivir).
    foreach ($innodb as $s) {
        subtest($s, static function () use ($s): void {
            $ft = iarepo_build_search($s . ' ondas')['debug']['ft'];
            assert_not_contains('+' . $s . '*', $ft, 'el stopword se ha emitido con "+"');
            assert_not_contains('(' . $s . '*', $ft, 'el stopword se ha colado al abrir un grupo');
            assert_not_contains(' ' . $s . '*', $ft, 'el stopword se ha colado dentro de un grupo');
            // 'ondas' se expande a '+(onda* wave*)': lo que importa es que el
            // término real siga exigido, con grupo o sin él.
            assert_contains('onda*', $ft, 'el término real debe sobrevivir al descarte del stopword');
        });
    }
}

// ================================================================
// 2 · COMPORTAMIENTO (tabla de evidencia reproducida en producción)
// ================================================================

/** 'C++' daba HTTP 500. Ahora es una búsqueda LIKE con bonus de frase cruda. */
function test_evidencia_c_plus_plus_no_puede_romper(): void
{
    $r = iarepo_build_search('C++');

    assert_eq('like', $r['mode'], "'C++' se queda en 'c': token corto, solo LIKE");
    assert_eq('', $r['debug']['ft'], 'no puede haber brazo fulltext con un token de 1 carácter');
    assert_eq(['c'], $r['terms']);

    // El bonus de 25 puntos es lo que hace que "Introducción a C++" gane
    // a cualquier título que simplemente contenga una "c".
    assert_contains('THEN 25', (string) $r['score'], 'falta el bonus de la frase con puntuación');
    assert_true(in_array('%c++%', $r['score_params'], true), 'la frase cruda "c++" debe ir como parámetro');

    // Y el "++" jamás aparece en el texto del SQL.
    assert_not_contains('+', str_replace(' + ', ' ', $r['where']), 'el "++" no puede llegar al WHERE');
}

/** 'matem' no devolvía nada: el fulltext exigía palabra completa. */
function test_evidencia_prefijo_matem(): void
{
    $r = iarepo_build_search('matem');
    assert_eq('hybrid', $r['mode']);
    assert_eq('+matem*', $r['debug']['ft'], 'el prefijo debe llevar comodín final');
}

/** 'ondas' y 'onda' daban conjuntos distintos. Ahora filtran igual. */
function test_evidencia_singular_y_plural_filtran_igual(): void
{
    foreach ([['onda', 'ondas'], ['wave', 'waves'], ['valor', 'valores']] as [$sing, $plur]) {
        subtest($sing . '/' . $plur, static function () use ($sing, $plur): void {
            $a = iarepo_build_search($sing);
            $b = iarepo_build_search($plur);
            assert_eq($a['where'], $b['where'], 'el WHERE debe ser idéntico');
            assert_eq($a['params'], $b['params'], 'los parámetros deben ser idénticos');
            assert_eq($a['debug']['ft'], $b['debug']['ft'], 'la cadena fulltext debe ser idéntica');
        });
    }
    // El score SÍ difiere a propósito: la forma exacta que escribió el
    // usuario puntúa +30 en el título, así que "ondas" prefiere "Ondas…".
    assert_neq(
        iarepo_build_search('onda')['score_params'],
        iarepo_build_search('ondas')['score_params'],
        'el desempate por frase exacta debe distinguir singular de plural'
    );
}

/** 'pH' devolvía 0: InnoDB descarta tokens de menos de 3 caracteres. */
function test_evidencia_tokens_cortos_se_quedan_fuera_del_fulltext(): void
{
    $casos = [
        'pH'  => ['mode' => 'like',   'ft' => ''],
        'a1'  => ['mode' => 'like',   'ft' => ''],
        // 3 = IAREPO_FT_MIN, ya indexable. Y con sinónimo: 'adn' → 'dna'.
        'ADN' => ['mode' => 'hybrid', 'ft' => '+(adn* dna*)'],
    ];
    foreach ($casos as $q => $esperado) {
        subtest($q, static function () use ($q, $esperado): void {
            $r = iarepo_build_search($q);
            assert_eq($esperado['mode'], $r['mode']);
            assert_eq($esperado['ft'], $r['debug']['ft']);
        });
    }
    assert_eq(IAREPO_FT_MIN, 3, 'IAREPO_FT_MIN debe seguir el innodb_ft_min_token_size del servidor');
}

/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║ COLAPSO DE PRECISIÓN con términos cortos (arreglado).         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Un token de <3 caracteres no tiene brazo fulltext, así que el segundo
 * brazo es su ÚNICO filtro. Con LIKE '%c%' eso devolvía, medido sobre el
 * catálogo real de 546 filas:
 *
 *     ?search=C++  →  541 de 542   ?search=pH →  149     ?search=a → 542
 *
 * El recurso correcto salía primero (eso lo pone el ranking), pero el
 * contador "N recursos" no significaba nada y las páginas 2..55 eran
 * ruido. Ahora un término corto exige FRONTERA DE PALABRA y los mismos
 * números bajan a 3, 4 y 82.
 *
 * Aquí se fija la FORMA del SQL; los totales reales contra MariaDB los
 * fija tests/integration/search_db_test.php.
 */
function test_termino_corto_filtra_por_palabra_no_por_subcadena(): void
{
    foreach (['pH', 'C++', '0', 'IA', 'a'] as $q) {
        subtest($q, static function () use ($q): void {
            $r = iarepo_build_search($q);
            $t = $r['debug']['like'][0];

            assert_eq([$t], $r['debug']['short'], 'el término debe estar marcado como corto');
            assert_contains('REGEXP ?', $r['where'], 'un término corto debe exigir frontera de palabra');
            assert_eq(
                [iarepo_word_regexp($t), iarepo_word_regexp($t), '% ' . iarepo_like_escape($t) . ' %'],
                $r['params'],
                'los parámetros del término corto (haystack, tags, plan B por collation)'
            );

            // La subcadena desnuda es justo lo que NO puede quedar: era el
            // único filtro y hacía casar el catálogo entero.
            assert_not_contains(
                '%' . iarepo_like_escape($t) . '%',
                implode('|', $r['params']),
                'el término corto vuelve a filtrar por subcadena'
            );
        });
    }
}

/**
 * El plan B del término corto (CONCAT(' ',…,' ') LIKE '% t %') no está de
 * adorno: REGEXP compara byte a byte y NO es insensible a acentos, pero la
 * collation utf8mb4_unicode_ci sí. Ese OR garantiza que una palabra suelta
 * separada por espacios se encuentre pase lo que pase con el motor.
 */
function test_el_termino_corto_conserva_una_via_por_collation(): void
{
    $r = iarepo_build_search('pH');
    assert_contains("CONCAT(' ', CONCAT_WS(", (string) $r['where'], 'falta la vía por collation');
    assert_contains("LIKE ? ESCAPE '!'", (string) $r['where'], 'y debe ir por LIKE, no por REGEXP');
    assert_true(in_array('% ph %', $r['params'], true), 'la aguja debe delimitar la palabra con espacios');
}

/**
 * Los términos LARGOS deben seguir buscándose como SUBCADENA. No es un
 * descuido: ahí la precisión la pone el brazo fulltext, y el LIKE es lo
 * único que da (a) prefijos sobre columnas no indexadas y (b) acentos
 * indiferentes ('matematicas' == 'matemáticas' lo resuelve la collation,
 * que REGEXP no usa). Cambiarlo a frontera de palabra rompería los dos.
 */
function test_termino_largo_sigue_buscandose_como_subcadena(): void
{
    foreach (['matem', 'laboratorio', 'phet'] as $q) {
        subtest($q, static function () use ($q): void {
            $r = iarepo_build_search($q);
            assert_eq([], $r['debug']['short'], 'un término indexable no es corto');
            assert_true(in_array('%' . $q . '%', $r['params'], true), 'debe buscarse como subcadena');

            // Puede haber REGEXP en el WHERE, pero SÓLO de los sinónimos (que
            // filtran por principio de palabra). El término del usuario jamás
            // puede exigir frontera: 'matem' dejaría de encontrar "Matemáticas".
            assert_false(
                in_array(iarepo_word_regexp($q), $r['params'], true),
                'el término del usuario no puede exigir frontera de palabra'
            );
            assert_false(
                in_array(iarepo_prefix_regexp($q), $r['params'], true),
                'el término del usuario no puede exigir principio de palabra'
            );
            foreach ($r['params'] as $p) {
                if (str_contains($p, IAREPO_RX_WORD)) {
                    assert_true(iarepo_is_prefix_regexp($p), 'el único REGEXP admisible aquí es el de un sinónimo');
                    assert_false(in_array(substr($p, strlen('(?<!' . IAREPO_RX_WORD . ')')), [$q], true));
                }
            }
        });
    }
}

/**
 * SEGURIDAD del patrón REGEXP: sólo puede construirse con tokens ya
 * normalizados. Si la frase cruda llegase al motor de expresiones
 * regulares, un '(' daría error 1139 (→ HTTP 500) y un '(a+)+$' abriría
 * la puerta al backtracking catastrófico.
 */
function test_el_patron_regexp_solo_admite_tokens_normalizados(): void
{
    foreach (['', 'c++', 'a b', '(a+)+', '%', '.*', 'a\\b', "on\x00das", "\xff\xfe"] as $malo) {
        subtest(iarepo_show($malo), static function () use ($malo): void {
            assert_eq('', iarepo_word_regexp($malo), 'este token NO puede producir patrón');
            assert_true(!iarepo_is_word_regexp($malo), 'y tampoco puede pasar por patrón válido');
        });
    }
    foreach (['ph', 'c', '0', 'ñ', '中文', 'físicas'] as $bueno) {
        subtest($bueno, static function () use ($bueno): void {
            $rx = iarepo_word_regexp($bueno);
            assert_neq('', $rx, 'un token normalizado sí debe producir patrón');
            assert_true(iarepo_is_word_regexp($rx), 'y debe pasar su propia lista blanca');
            // Y el patrón tiene que ser una regex válida de verdad.
            assert_true(@preg_match('/' . $rx . '/u', '') !== false, 'el patrón no compila');
        });
    }

    // Sobre TODO el corpus hostil: ningún parámetro que alimente a REGEXP
    // puede llevar un metacarácter fuera del envoltorio fijo.
    foreach (st_corpus() as $label => $raw) {
        subtest($label, static function () use ($raw): void {
            $r = iarepo_build_search($raw);
            if ($r['mode'] === 'none') {
                return;
            }
            foreach (['where' => [$r['where'], $r['params']], 'score' => [(string) $r['score'], $r['score_params']]]
                     as $etiqueta => [$sql, $params]) {
                foreach (st_placeholder_kinds($sql) as $i => $kind) {
                    if ($kind === 'rx') {
                        assert_true(
                            iarepo_is_word_regexp($params[$i]) || iarepo_is_prefix_regexp($params[$i]),
                            "{$etiqueta}: patrón REGEXP fuera de la lista blanca: " . iarepo_show($params[$i])
                        );
                    }
                }
            }
        });
    }
}

/**
 * 'ondas sonido' devolvía lo mismo que 'ondas': BOOLEAN MODE sin '+' es OR.
 * Ahora los dos brazos exigen AND real.
 */
function test_evidencia_multi_palabra_es_and(): void
{
    $r = iarepo_build_search('ondas sonido');
    assert_eq('hybrid', $r['mode']);
    // Cada término es un GRUPO y el AND entre grupos se mantiene: el '+' va
    // pegado al paréntesis, así que ninguno de los dos conceptos es opcional.
    assert_eq(
        '+(onda* wave*) +(sonido* sound* acoustic* acustica*)',
        $r['debug']['ft'],
        'ambos grupos deben ir con "+"'
    );
    assert_eq(2, substr_count($r['debug']['ft'], '+'), 'exactamente dos grupos obligatorios');
    assert_contains(' AND ', $r['where'], 'el brazo LIKE debe unir los grupos con AND');
    assert_eq(2, count($r['debug']['groups']), 'dos grupos');

    // El OR sólo puede vivir DENTRO de un grupo: el brazo LIKE tiene tantos
    // bloques unidos por AND como grupos, ni uno menos.
    assert_eq(
        1,
        substr_count($r['where'], ') AND ('),
        'los dos grupos del brazo LIKE deben unirse con un único AND de nivel superior'
    );
}

/** 'pH escala': el término corto NO puede quedarse fuera del AND. */
function test_evidencia_corto_mas_largo_sigue_siendo_and(): void
{
    $r = iarepo_build_search('pH escala');
    assert_eq('hybrid', $r['mode']);
    assert_eq('+escala*', $r['debug']['ft'], 'el fulltext solo puede exigir el término indexable');
    assert_eq(['ph'], $r['debug']['short']);

    // El brazo fulltext lleva pegada en AND la condición del término corto;
    // si no, 'pH escala' devolvería todo lo que contenga "escala".
    // Se recorta por 'AGAINST(?)' + lo que sigue hasta el ' OR ' de nivel
    // superior, que es el ÚNICO ' OR ' precedido de ')) ' en el WHERE.
    assert_contains('IN BOOLEAN MODE) AND ' . IAREPO_TERM_WORD, $r['where'],
        'el término corto debe ir en AND con el MATCH, y por frontera de palabra');

    // Y los parámetros del AND van justo detrás de la cadena fulltext.
    assert_eq(
        ['+escala*', iarepo_word_regexp('ph'), iarepo_word_regexp('ph'), '% ph %'],
        array_slice($r['params'], 0, 4),
        'orden de los parámetros del brazo fulltext'
    );
}

/** 'the water cycle': stopwords fuera, sin anular la consulta. */
function test_evidencia_stopwords_se_descartan(): void
{
    $r = iarepo_build_search('the water cycle');
    assert_eq('hybrid', $r['mode']);
    // 'water' tiene grupo ('agua'); 'cycle' no está en el diccionario y sale
    // exactamente igual que antes de los sinónimos, sin paréntesis.
    assert_eq('+(water* agua*) +cycle*', $r['debug']['ft']);
    assert_eq(['the'], $r['debug']['dropped']);
    assert_eq(['water', 'cycle'], $r['debug']['like']);
    assert_eq([['water', 'agua'], ['cycle']], $r['debug']['groups']);
}

/** Si TODO son stopwords, se busca la frase entera en vez de no buscar nada. */
function test_solo_stopwords_cae_a_la_frase_completa(): void
{
    $r = iarepo_build_search('de la');
    assert_eq('like', $r['mode']);
    assert_eq(['de la'], $r['debug']['like'], 'debe usarse la frase completa');
    assert_eq('', $r['debug']['ft']);
    assert_eq(['%de la%', '%de la%'], $r['params']);
}

/** 'física-química': el guion se interpretaba como NOT. */
function test_evidencia_guion_no_es_un_operador(): void
{
    $r = iarepo_build_search('física-química');
    assert_eq('hybrid', $r['mode']);
    assert_eq('+(física* physic*) +(química* chemistry*)', $r['debug']['ft'], 'el guion solo separa términos');
    assert_matches(IAREPO_FT_SAFE, $r['debug']['ft'], 'los acentos deben sobrevivir a la lista blanca');
    // Y la frase con guion puntúa aparte.
    assert_true(in_array('%física-química%', $r['score_params'], true));
}

/** Los acentos los resuelve la collation de la BD, no el normalizador. */
function test_acentos_se_conservan_tal_cual(): void
{
    assert_eq('física', iarepo_normalize('FÍSICA'), 'no se deben quitar los acentos');
    foreach (['física', 'diseño', 'ángulo', 'φυσική', 'الفيزياء'] as $q) {
        subtest($q, static function () use ($q): void {
            $r = iarepo_build_search($q);
            assert_eq('hybrid', $r['mode'], 'una palabra acentuada de 3+ letras debe usar el índice');
            assert_matches(IAREPO_FT_SAFE, $r['debug']['ft'], 'los acentos deben pasar la lista blanca');
        });
    }
}

/**
 * CARACTERIZACIÓN (no es un fallo del módulo, es un límite del motor):
 * el chino/japonés/coreano no lleva espacios, así que una consulta CJK
 * corta es UN token de 1-2 caracteres y cae al brazo LIKE. Con 3+ sí
 * entra al fulltext, pero el parser por defecto de InnoDB tampoco sabe
 * segmentar CJK (haría falta el parser NGRAM, que MariaDB no tiene).
 * Lo importante para nosotros es que NUNCA rompe. Ver riesgos.
 */
function test_cjk_no_rompe_aunque_el_indice_no_lo_entienda(): void
{
    foreach (['中文' => 'like', '中文字' => 'hybrid', '검색어' => 'hybrid'] as $q => $modo) {
        subtest($q, static function () use ($q, $modo): void {
            $r = iarepo_build_search($q);
            assert_eq($modo, $r['mode']);
            st_check_invariants($r);
        });
    }
}

/** Mayúsculas y espacios sobrantes no pueden cambiar el resultado. */
function test_caja_y_espacios_no_cambian_nada(): void
{
    $base = iarepo_build_search('ondas sonido');
    foreach (['ONDAS SONIDO', '  Ondas   Sonido  ', "\tondas\nsonido\r"] as $variante) {
        subtest($variante, static function () use ($base, $variante): void {
            $r = iarepo_build_search($variante);
            assert_eq($base['where'], $r['where']);
            assert_eq($base['params'], $r['params']);
            assert_eq($base['debug']['ft'], $r['debug']['ft']);
        });
    }
}

/** Techos de tamaño: nada de consultas de 5.000 caracteres. */
function test_limites_de_tamano(): void
{
    $r = iarepo_build_search(str_repeat('a', 300));
    assert_count(1, $r['terms']);
    assert_eq(IAREPO_MAX_TOKEN, mb_strlen($r['terms'][0], 'UTF-8'), 'el token debe recortarse a IAREPO_MAX_TOKEN');

    $muchas = [];
    for ($i = 1; $i <= 30; $i++) {
        $muchas[] = 'termino' . $i;
    }
    $r = iarepo_build_search(implode(' ', $muchas));
    assert_eq(IAREPO_MAX_TERMS, count($r['terms']), 'como mucho IAREPO_MAX_TERMS términos');
    assert_eq(IAREPO_MAX_TERMS, substr_count($r['debug']['ft'], '+'));

    assert_true(
        mb_strlen(iarepo_normalize(str_repeat('ab ', 200)), 'UTF-8') <= IAREPO_MAX_RAW,
        'la frase normalizada nunca puede superar IAREPO_MAX_RAW'
    );
}

/** '0' se ignoraba por un empty() en la API. Aquí solo comprobamos el módulo. */
function test_el_cero_es_una_busqueda_valida(): void
{
    $r = iarepo_build_search('0');
    assert_eq('like', $r['mode'], "'0' es un token corto, pero es una búsqueda real");
    assert_neq('', $r['where']);

    // Es un token de 1 carácter ⇒ filtra por PALABRA COMPLETA. Con la
    // subcadena '%0%' casaba "Regex101", "Base Ten Blocks (10)" y todo lo
    // que llevase un cero en cualquier número.
    assert_eq(
        [iarepo_word_regexp('0'), iarepo_word_regexp('0'), '% 0 %'],
        $r['params'],
        'debe filtrar por palabra completa, no por subcadena'
    );
}

/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║ BUG CONFIRMADO en shared/search.php — ESTE TEST DEBE ESTAR    ║
 * ║ EN ROJO hasta que lo arregle quien mantiene ese fichero.      ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * iarepo_tokenize() deduplica con `$out[$t] = true` (search.php:143) y
 * PHP convierte las claves de array que parecen enteros canónicos a int.
 * array_keys() (search.php:148) las devuelve YA como int, así que
 * 'terms' sale con tipos mezclados:
 *
 *     iarepo_build_search('2024 examen')['terms']  →  [2024, 'examen']
 *
 * api/resources.php devuelve eso tal cual en `search.terms`, de modo que
 * el navegador recibe {"terms":[2024,"examen"]}. Cualquier código de
 * resaltado que haga t.toLowerCase() / t.replace() revienta con
 * "TypeError: t.toLowerCase is not a function" en cuanto alguien busca
 * un año, una cantidad o un número de ejercicio. Y no es consistente:
 * '007' y los números enormes SÍ salen como string.
 *
 * ARREGLO (una línea, en shared/search.php:148):
 *     return array_map('strval', array_keys($out));
 */
function test_BUG_terms_numericos_salen_como_int_en_vez_de_string(): void
{
    foreach (['0', '2024', '1 2 3', '2024 examen'] as $q) {
        subtest($q, static function () use ($q): void {
            foreach (iarepo_build_search($q)['terms'] as $i => $t) {
                assert_true(
                    is_string($t),
                    "terms[{$i}] llegó como " . get_debug_type($t) . ' (' . var_export($t, true) . ')'
                    . ' — el JSON de la API sale con tipos mezclados. Arreglo:'
                    . " array_map('strval', array_keys(\$out)) en shared/search.php:148"
                );
            }
        });
    }
}

/** Vacío, espacios y basura sin letras: no hay búsqueda, no hay filtro. */
function test_entradas_sin_contenido_dan_mode_none(): void
{
    foreach (['', ' ', "\t\n", '+++', '()', '~@<>', '"""', '%%%', '___', '---', '🙂🙂', "\x00\x01"] as $q) {
        subtest(iarepo_show($q), static function () use ($q): void {
            assert_eq('none', iarepo_build_search($q)['mode']);
        });
    }
}

/** Los comodines de LIKE del usuario van escapados, no interpretados. */
function test_comodines_de_like_se_escapan(): void
{
    $r = iarepo_build_search('100%');
    assert_true(in_array('%100!%%', $r['score_params'], true), 'el % del usuario debe ir escapado con !');

    $r = iarepo_build_search('a_b');
    assert_true(in_array('%a!_b%', $r['score_params'], true), 'el _ del usuario debe ir escapado con !');

    $r = iarepo_build_search('a!b');
    assert_true(in_array('%a!!b%', $r['score_params'], true), 'el ! del usuario debe ir escapado');

    // Y el modificador ESCAPE tiene que acompañar a TODOS los LIKE.
    foreach (st_corpus() as $label => $raw) {
        subtest($label, static function () use ($raw): void {
            $r = iarepo_build_search($raw);
            $sql = $r['where'] . ' ' . (string) $r['score'];
            assert_eq(
                substr_count($sql, 'LIKE ?'),
                substr_count($sql, "LIKE ? ESCAPE '!'"),
                'hay un LIKE sin ESCAPE: el usuario podría colar comodines'
            );
        });
    }
}

/** El score siempre incluye el desempate suave por popularidad. */
function test_el_score_tiene_desempate_por_popularidad(): void
{
    $r = iarepo_build_search('ondas');
    assert_contains('LEAST(COALESCE(r.view_count, 0) / 200, 3)', (string) $r['score']);
    assert_contains('THEN 30', (string) $r['score'], 'falta el bonus de frase en el título');
    assert_contains('THEN 12', (string) $r['score'], 'falta el bonus de palabra completa');
}

/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║ El sumando fulltext del score NO puede ser ilimitado.          ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Era MATCH(...)*2, que crece con la frecuencia del término. Medido sobre
 * el catálogo real (546 filas, 295 consultas, 2.668 filas puntuadas):
 * mediana 6,0, p90 13,5, p99 23,7, MÁXIMO 47,5, y el 0,4 % pasaba de 30.
 * O sea que repetir la palabra en la descripción ganaba al bono de 30 de
 * tener la frase entera en el título. Ahora va con tope.
 */
function test_el_sumando_fulltext_esta_acotado(): void
{
    $score = (string) iarepo_build_search('ondas')['score'];
    assert_contains('AGAINST', $score, 'con brazo fulltext el score debe puntuarlo');
    assert_contains('LEAST((' . IAREPO_MATCH . ') * 2, ' . IAREPO_FT_SCORE_CAP . ')', $score,
        'el sumando fulltext vuelve a estar sin acotar');

    // Sin brazo fulltext no hay sumando que acotar (ni MATCH que evaluar).
    assert_not_contains('AGAINST', (string) iarepo_build_search('pH')['score'],
        'sin brazo fulltext el score no puede llamar a MATCH');

    // LA INVARIANTE: el sumando fulltext no puede ganarle a que la FRASE
    // esté en el título. Ésos son los bonos que adelantaba sin acotar.
    $frases = [25 => 'frase cruda con puntuación en el título', 30 => 'frase normalizada en el título'];
    foreach ($frases as $bono => $que) {
        subtest('no llega al bono de ' . $bono, static function () use ($bono, $que): void {
            assert_true(IAREPO_FT_SCORE_CAP < $bono,
                "el sumando fulltext puede superar el bono de {$bono} ({$que})");
        });
    }

    // …y es JUSTO el mayor que cumple lo anterior. Bajarlo no protege
    // ninguna invariante más y sí desordena el ranking: medido sobre el
    // catálogo real, con tope 24 no cambia el primer resultado de ninguna
    // de las 295 consultas; con tope 12 cambian 5.
    assert_eq(24, IAREPO_FT_SCORE_CAP, 'el tope debe ser el mayor entero por debajo del bono de 25');
    assert_eq(min(array_keys($frases)) - 1, IAREPO_FT_SCORE_CAP,
        'si cambia el bono de la frase cruda, el tope tiene que seguirlo');

    // Forma del sumando: por debajo del tope, idéntico a lo de antes.
    $f = static fn(float $m): float => min($m * 2, (float) IAREPO_FT_SCORE_CAP);
    foreach ([0.0, 0.49, 4.813, 6.04, 11.9] as $m) {
        subtest('MATCH=' . $m . ' (rango normal)', static function () use ($f, $m): void {
            assert_eq($m * 2, $f($m), 'acotar debe ser un no-op por debajo del tope');
        });
    }
    foreach ([12.0, 20.77, 23.74, 1e6] as $m) {
        subtest('MATCH=' . $m . ' (cola)', static function () use ($f, $m): void {
            assert_eq((float) IAREPO_FT_SCORE_CAP, $f($m), 'la cola debe quedar recortada al tope');
        });
    }
}

/**
 * El contrato documenta TRES modos y sólo tres. 'fulltext' no existe
 * porque el segundo brazo está siempre presente (es lo único que alcanza
 * source_name, los tags y los términos cortos). Si alguien lo emitiese,
 * cualquier consumidor que ramifique por el modo se quedaría sin rama.
 */
function test_el_modo_fulltext_no_existe(): void
{
    foreach (st_corpus() as $label => $raw) {
        subtest($label, static function () use ($raw): void {
            $r = iarepo_build_search($raw);
            assert_neq('fulltext', $r['mode'], "'fulltext' no es un modo del contrato");
            assert_true(in_array($r['mode'], ['none', 'like', 'hybrid'], true), 'modo fuera del contrato: ' . $r['mode']);
            // Y la equivalencia que hace imposible el cuarto modo:
            if ($r['mode'] !== 'none') {
                assert_contains('LIKE ?', $r['where'], 'el segundo brazo debe estar SIEMPRE presente');
            }
        });
    }
}

/** El brazo LIKE debe cubrir también los tags (tabla aparte). */
function test_el_brazo_like_cubre_los_tags(): void
{
    $r = iarepo_build_search('phet');
    assert_contains('resource_tags', $r['where'], 'los tags deben entrar en la búsqueda');
    assert_contains('EXISTS', $r['where'], 'los tags se buscan con EXISTS, no con GROUP_CONCAT');
    foreach (['source_name', 'author_display_name', 'subject_area', 'topic_tag'] as $col) {
        subtest($col, static function () use ($r, $col): void {
            assert_contains($col, $r['where'], 'columna ausente del haystack');
        });
    }
}

// ================================================================
// 2b · SINÓNIMOS ES↔EN
//
// El catálogo es bilingüe y `lang` no es de fiar, así que "biología"
// devolvía CERO en un catálogo con 37 recursos de biology. Cada término
// pasa a ser un GRUPO: el AND entre términos se mantiene y el OR queda
// dentro del grupo.
// ================================================================

/** Contrato de iarepo_synonyms(): el término SIEMPRE primero. */
function test_synonyms_devuelve_el_grupo_con_el_termino_primero(): void
{
    $casos = [
        'ondas'   => ['ondas', 'onda', 'wave', 'waves'],
        'wave'    => ['wave', 'onda', 'ondas', 'waves'],
        'biology' => ['biology', 'biologia'],
    ];
    foreach ($casos as $q => $esperado) {
        subtest($q, static function () use ($q, $esperado): void {
            assert_eq($esperado, iarepo_synonyms($q));
        });
    }

    // Los acentos se pliegan SÓLO para consultar el diccionario: el término
    // vuelve tal cual lo escribió el usuario, con su tilde.
    assert_eq(['física', 'physics'], iarepo_synonyms('física'));
    assert_eq(['biología', 'biology'], iarepo_synonyms('biología'));
}

/** Un término que no está en el diccionario se devuelve solo, sin tocar. */
function test_synonyms_termino_desconocido_se_devuelve_intacto(): void
{
    foreach (['escala', 'phet', 'xyzzy', 'ph', 'c', '0', '中文', 'ñ'] as $q) {
        subtest($q, static function () use ($q): void {
            assert_eq([$q], iarepo_synonyms($q));
        });
    }
}

/**
 * Si shared/search_synonyms.php desapareciese, el buscador debe seguir
 * funcionando SIN sinónimos, no reventar. Se comprueba en un subproceso
 * con una copia de shared/search.php sin diccionario al lado, porque el
 * índice se memoiza en un `static` y no se puede rebobinar.
 */
function test_sin_diccionario_degrada_a_sin_sinonimos(): void
{
    $tmp = sys_get_temp_dir() . '/iarepo_syn_' . getmypid();
    @mkdir($tmp, 0700, true);
    copy(IAREPO_ROOT . '/shared/search.php', $tmp . '/search.php');
    assert_false(is_file($tmp . '/search_synonyms.php'), 'la copia NO debe tener diccionario al lado');

    $res = iarepo_php_isolated(<<<PHP
        <?php
        require '{$tmp}/search.php';
        \$r = iarepo_build_search('ondas sonido');
        echo json_encode([\$r['mode'], \$r['debug']['ft'], iarepo_synonyms('ondas')]);
        PHP);

    @unlink($tmp . '/search.php');
    @rmdir($tmp);

    assert_eq(0, $res['code'], 'sin diccionario NO puede terminar en error: ' . $res['err']);
    assert_eq('', $res['err'], 'sin diccionario no puede emitir avisos');
    assert_eq(
        ['hybrid', '+onda* +sonido*', ['ondas']],
        json_decode($res['out'], true),
        'sin diccionario debe comportarse como antes de los sinónimos'
    );
}

/**
 * REGRESIÓN: un término SIN sinónimos tiene que producir exactamente el
 * mismo SQL que antes de existir esta función. Es lo que garantiza que la
 * expansión no pueda romper lo que ya funcionaba.
 */
function test_un_termino_sin_sinonimos_produce_el_sql_de_siempre(): void
{
    $r = iarepo_build_search('escala');

    assert_eq('hybrid', $r['mode']);
    assert_eq('+escala*', $r['debug']['ft'], 'sin grupo no puede haber paréntesis');
    assert_eq([['escala']], $r['debug']['groups']);
    assert_eq('((' . IAREPO_MATCH . ') OR (' . IAREPO_TERM_LIKE . '))', $r['where'],
        'un término sin sinónimos no puede añadir ni un paréntesis');
    assert_eq(['+escala*', '%escala%', '%escala%'], $r['params']);
    assert_not_contains('REGEXP', $r['where'], 'sin sinónimos no hay REGEXP para un término largo');

    // Y el score tampoco crece: nada de bono "exacto en cualquier columna"
    // ni de bonos de sinónimo cuando no hay a quién ganarle.
    assert_eq(4, count($r['score_params']));
    assert_not_contains('THEN ' . IAREPO_SCORE_EXACT_ANY, (string) $r['score']);
    assert_not_contains('THEN ' . IAREPO_SCORE_SYN_TITLE, (string) $r['score']);
}

/**
 * Los grupos del fulltext tienen que estar BIEN CERRADOS: '+(' abre,
 * ')' cierra, un '+' por grupo y nada suelto. Un paréntesis descolgado
 * es ERROR 1064 → HTTP 500, que es justo el fallo que originó el fichero.
 */
function test_los_grupos_del_fulltext_estan_bien_cerrados(): void
{
    $consultas = array_merge(array_values(st_corpus()), [
        'ondas', 'ondas sonido', 'biología', 'matemáticas física química biología',
        'gravedad suma grafica sonido flotacion medicion terremoto simulacion',
    ]);

    foreach ($consultas as $q) {
        subtest(iarepo_show($q), static function () use ($q): void {
            $ft = iarepo_build_search($q)['debug']['ft'];
            if ($ft === '') {
                return;
            }
            assert_matches(IAREPO_FT_SAFE, $ft, 'cadena fulltext fuera de la lista blanca');
            assert_eq(substr_count($ft, '('), substr_count($ft, ')'), 'paréntesis desbalanceados');
            // Todo paréntesis de apertura va precedido de '+': un grupo
            // opcional (sin '+') convertiría el AND entre términos en OR.
            assert_eq(substr_count($ft, '('), substr_count($ft, '+('), 'un "(" sin su "+" delante');
            // Un '+' por átomo y ninguno dentro del grupo.
            assert_eq(count(explode(' +', ' ' . $ft)) - 1, substr_count($ft, '+'), '"+" en un sitio raro');
        });
    }
}

/**
 * CINTURÓN: la lista blanca de la cadena fulltext se ha ampliado para
 * admitir grupos, y no se ha abierto la mano. Todo lo que no sea
 * exactamente '+termino*' o '+(termino* termino*)' se rechaza.
 */
function test_el_cinturon_ft_safe_sigue_siendo_lista_blanca(): void
{
    $validas = ['+onda*', '+onda* +sonido*', '+(onda* wave*)', '+(onda* wave*) +sonido*',
                '+sonido* +(onda* wave*)', '+(a1* b2* c3*)', '+(física* physic*)'];
    foreach ($validas as $ok) {
        subtest('ok ' . $ok, static function () use ($ok): void {
            assert_matches(IAREPO_FT_SAFE, $ok);
        });
    }

    $invalidas = [
        '(onda* wave*)',      // grupo sin '+': dejaría de ser obligatorio
        '+(onda* wave*',      // sin cerrar → ERROR 1064
        '+onda* wave*)',      // cierre suelto
        '+()',                // grupo vacío: anula la consulta entera
        '+(onda*)extra',      // cola pegada
        '+(onda* -wave*)',    // operador NOT dentro del grupo
        '+(onda* +wave*)',    // AND dentro de un OR
        '+((onda*))',         // anidamiento
        '+(onda*  wave*)',    // doble espacio
        '+(onda* wave*) ',    // espacio final
        '+(onda wave*)',      // miembro sin comodín
        '+(onda* "wave"*)',   // comillas: operador de frase
        '+(onda* wave*) @2',  // operador de distancia
        '+onda* +(the*) ~x*', // basura al final
        '+ (onda* wave*)',    // espacio entre '+' y '('
    ];
    foreach ($invalidas as $mala) {
        subtest('ko ' . $mala, static function () use ($mala): void {
            assert_not_matches(IAREPO_FT_SAFE, $mala, 'la lista blanca ha dejado pasar basura');
        });
    }
}

/**
 * El tope de expansión existe para que un grupo mal mantenido no se lleve
 * la consulta por delante, pero HOY no debe recortar nada: si alguien mete
 * un séptimo sinónimo, este test se pone rojo ANTES de que la poda ocurra
 * en silencio.
 */
function test_el_tope_de_expansion_nunca_recorta_el_diccionario(): void
{
    $dict = require IAREPO_ROOT . '/shared/search_synonyms.php';
    $peor = 0;
    $cual = '';

    foreach ($dict as $grupo) {
        foreach ($grupo as $t) {
            if (str_contains($t, ' ')) {
                continue; // los multi-palabra son inalcanzables token a token
            }
            $n = count(iarepo_expand($t, iarepo_stem($t)));
            if ($n > $peor) {
                $peor = $n;
                $cual = $t;
            }
        }
    }

    assert_true($peor >= 2, 'el diccionario no está expandiendo nada, ¿se ha cargado?');
    assert_true(
        $peor < IAREPO_MAX_SYNONYMS,
        "el grupo de '{$cual}' llega a {$peor} miembros y el tope es "
        . IAREPO_MAX_SYNONYMS . ': la expansión está a punto de recortarse en silencio'
    );
}

/**
 * SALUD DEL DICCIONARIO. Se comprueba lo que el motor da por hecho:
 * ningún término en dos grupos (sería ambiguo), nada sin normalizar, y
 * ningún miembro corto o stopword, que son los dos que rompen la
 * precisión y la consulta fulltext respectivamente.
 */
function test_el_diccionario_cumple_sus_propias_reglas(): void
{
    $dict = require IAREPO_ROOT . '/shared/search_synonyms.php';
    assert_true(is_array($dict) && count($dict) > 100, 'el diccionario debe ser un array de grupos');

    $visto = [];
    foreach ($dict as $i => $grupo) {
        assert_true(is_array($grupo) && count($grupo) >= 2, "el grupo #{$i} no llega a dos miembros");
        foreach ($grupo as $t) {
            subtest($t, static function () use ($t, $i, &$visto): void {
                assert_matches('/^[\p{Ll}\p{N}]+( [\p{Ll}\p{N}]+)*$/u', $t,
                    'debe estar normalizado: minúsculas, sin tildes, sin puntuación');
                assert_eq($t, iarepo_fold($t), 'no puede llevar tildes: la búsqueda se pliega antes');
                assert_false(isset($visto[$t]), "'{$t}' está en dos grupos (grupo #{$i} y #" . ($visto[$t] ?? -1) . ')');
                $visto[$t] = $i;

                if (!str_contains($t, ' ')) {
                    assert_true(mb_strlen($t, 'UTF-8') >= IAREPO_FT_MIN,
                        'un sinónimo corto arrastra el catálogo por el brazo LIKE');
                    assert_false(in_array($t, IAREPO_STOP, true), 'un stopword anularía el grupo');
                }
            });
        }
    }
}

/**
 * PRECISIÓN: un sinónimo filtra por PRINCIPIO DE PALABRA, nunca por
 * subcadena. Medido sobre el catálogo real: el sinónimo 'ion' como
 * LIKE '%ion%' casaba 439 de 546 recursos (el 80 %: "Simulations",
 * "Motion", "Combinación"); por principio de palabra, ninguno falso.
 */
function test_el_sinonimo_filtra_por_principio_de_palabra(): void
{
    $r = iarepo_build_search('ondas');

    // El término del usuario: subcadena (es lo que él escribió).
    assert_true(in_array('%onda%', $r['params'], true), 'el término exacto va como subcadena');
    // El sinónimo: principio de palabra, jamás '%wave%'.
    assert_false(in_array('%wave%', $r['params'], true), 'un sinónimo NO puede ir como subcadena');
    assert_true(in_array(iarepo_prefix_regexp('wave'), $r['params'], true), 'el sinónimo va por principio de palabra');
    assert_true(in_array('% wave%', $r['params'], true), 'y con su plan B por collation');
    // Y NO por palabra completa: 'wave' tiene que seguir alcanzando "waves".
    assert_false(in_array(iarepo_word_regexp('wave'), $r['params'], true), 'el sinónimo no exige palabra completa');

    // Sobre todo el diccionario: ningún sinónimo puede acabar en un '%x%'.
    foreach (['biologia', 'matematicas', 'quimica', 'iones', 'laboratorio', 'simulacion', 'arte'] as $q) {
        subtest($q, static function () use ($q): void {
            $r = iarepo_build_search($q);
            foreach (array_slice($r['debug']['groups'][0], 1) as $syn) {
                assert_false(in_array('%' . $syn . '%', $r['params'], true),
                    "el sinónimo '{$syn}' se ha colado como subcadena");
                assert_true(in_array(iarepo_prefix_regexp($syn), $r['params'], true),
                    "falta el patrón de principio de palabra de '{$syn}'");
            }
        });
    }
}

/**
 * RELEVANCIA: quien busca "ondas" tiene que ver primero lo que dice
 * "ondas", no lo que dice "waves". A igualdad de posición el exacto gana
 * por construcción: en el título 10+12+8 = 30 contra 5, y fuera del
 * título 8 contra 0.
 */
function test_el_score_premia_el_exacto_sobre_el_sinonimo(): void
{
    $sc = (string) iarepo_build_search('ondas')['score'];

    assert_contains('THEN ' . IAREPO_SCORE_TITLE . ' ', $sc, 'falta el bono del exacto en el título');
    assert_contains('THEN ' . IAREPO_SCORE_TITLE_WORD . ' ', $sc, 'falta el bono de palabra completa del exacto');
    assert_contains('THEN ' . IAREPO_SCORE_EXACT_ANY . ' ', $sc, 'falta el bono del exacto en cualquier columna');
    assert_contains('THEN ' . IAREPO_SCORE_SYN_TITLE . ' ', $sc, 'falta el bono del sinónimo');

    // La desigualdad de la que depende todo el orden.
    assert_true(
        IAREPO_SCORE_TITLE + IAREPO_SCORE_TITLE_WORD + IAREPO_SCORE_EXACT_ANY > IAREPO_SCORE_SYN_TITLE,
        'en el título el exacto debe ganar al sinónimo'
    );
    assert_true(IAREPO_SCORE_EXACT_ANY > IAREPO_SCORE_SYN_TITLE,
        'el exacto en cualquier columna debe ganar al sinónimo en el título');

    // El bono del exacto mira TODAS las columnas; el del sinónimo, sólo el
    // título (y por principio de palabra, no por subcadena).
    $r = iarepo_build_search('ondas');
    assert_contains('(CASE WHEN ' . IAREPO_HAYSTACK . " LIKE ? ESCAPE '!' THEN " . IAREPO_SCORE_EXACT_ANY, $sc);
    assert_true(in_array('% wave%', $r['score_params'], true), 'el sinónimo puntúa por principio de palabra');
    assert_false(in_array('%wave%', $r['score_params'], true), 'el sinónimo no puede puntuar por subcadena');

    // El sumando fulltext NO puede romper el desempate: se calcula sobre el
    // grupo entero y vale lo mismo casando por el término o por el sinónimo.
    assert_contains('LEAST((' . IAREPO_MATCH . ') * 2, ' . IAREPO_FT_SCORE_CAP . ')', $sc);
}

/** Plegado de acentos: sólo para consultar el diccionario, y la ñ se respeta. */
function test_fold(): void
{
    assert_eq('fisica', iarepo_fold('física'));
    assert_eq('biologia', iarepo_fold('BIOLOGÍA'));
    assert_eq('matematicas', iarepo_fold('matemáticas'));
    assert_eq('aeiou', iarepo_fold('áéíóú'));
    assert_eq('aeiou', iarepo_fold('àèìòù'));
    assert_eq('aeiou', iarepo_fold('äëïöü'));
    assert_eq('ano', iarepo_fold('ano'));
    assert_eq('niño', iarepo_fold('niño'), 'la ñ NO se pliega: es otra letra');
    assert_eq('año', iarepo_fold('año'), 'plegar la ñ convertiría "año" en "ano"');
    assert_eq('中文', iarepo_fold('中文'));
    assert_eq('', iarepo_fold(''));
    // Idempotente, que es lo que permite usarlo como clave.
    foreach (['física', 'año', 'wave', '中文', 'ç'] as $s) {
        subtest($s, static function () use ($s): void {
            assert_eq(iarepo_fold($s), iarepo_fold(iarepo_fold($s)));
        });
    }
}

/** Patrón de PRINCIPIO de palabra: misma lista blanca que el de frontera. */
function test_prefix_regexp(): void
{
    assert_eq('(?<![\p{L}\p{N}])wave', iarepo_prefix_regexp('wave'));
    assert_true(iarepo_is_prefix_regexp(iarepo_prefix_regexp('wave')));

    foreach (['', 'c++', 'a b', '(a+)+', '%', '.*', 'a\\b', "on\x00das"] as $malo) {
        subtest(iarepo_show($malo), static function () use ($malo): void {
            assert_eq('', iarepo_prefix_regexp($malo), 'este token NO puede producir patrón');
            assert_false(iarepo_is_prefix_regexp($malo), 'ni pasar por patrón válido');
            assert_eq([null, []], iarepo_syn_condition($malo), 'un sinónimo dudoso se descarta, no se ensancha');
        });
    }
    foreach (['wave', 'biology', 'ph', '0', 'físicas'] as $bueno) {
        subtest($bueno, static function () use ($bueno): void {
            $rx = iarepo_prefix_regexp($bueno);
            assert_true(iarepo_is_prefix_regexp($rx));
            assert_true(@preg_match('/' . $rx . '/u', '') !== false, 'el patrón no compila');
            // Y el de frontera NO puede pasar por el de prefijo ni al revés.
            assert_false(iarepo_is_word_regexp($rx), 'un patrón de prefijo no es uno de frontera');
        });
    }
}

/**
 * La poda por prefijo de iarepo_expand() es EXACTA: si 'math' queda,
 * 'mathematic' sobra, porque tanto '+math*' como el principio de palabra
 * 'math' ya cubren todo lo que cubriría el más largo.
 */
function test_la_poda_por_prefijo_no_deja_miembros_redundantes(): void
{
    foreach (['matematicas', 'imanes', 'gravedad', 'simulacion', 'moleculas', 'lentes'] as $q) {
        subtest($q, static function () use ($q): void {
            $g = iarepo_build_search($q)['debug']['groups'][0];
            foreach ($g as $i => $a) {
                foreach ($g as $j => $b) {
                    if ($i === $j || $j === 0) {
                        continue; // el término del usuario nunca se poda
                    }
                    assert_false(str_starts_with(iarepo_fold($b), iarepo_fold($a)),
                        "'{$b}' es redundante: '{$a}' ya lo cubre");
                }
            }
        });
    }
}

/**
 * Dos formas de escribir el mismo concepto dan UN grupo, no dos.
 * (Sin esto, 'ondas waves' emitiría dos grupos idénticos unidos por AND.)
 */
function test_terminos_del_mismo_grupo_no_se_duplican(): void
{
    foreach (['ondas onda', 'ondas waves', 'waves ondas', 'onda wave waves'] as $q) {
        subtest($q, static function () use ($q): void {
            $r = iarepo_build_search($q);
            assert_eq(1, count($r['debug']['groups']), 'un solo concepto = un solo grupo');
            $g = $r['debug']['groups'][0];
            sort($g);
            assert_eq(['onda', 'wave'], $g, 'los mismos miembros, escriba el usuario lo que escriba');
        });
    }

    // El ORDEN sí depende de lo que escribió el usuario, y debe: el primero
    // es el término exacto y es el que se lleva los bonos de relevancia.
    assert_eq('+(onda* wave*)', iarepo_build_search('ondas')['debug']['ft']);
    assert_eq('+(wave* onda*)', iarepo_build_search('waves')['debug']['ft']);
    assert_eq('onda', iarepo_build_search('ondas')['debug']['like'][0]);
    assert_eq('wave', iarepo_build_search('waves')['debug']['like'][0]);
}

// ================================================================
// 3 · FUNCIONES PURAS
// ================================================================

function test_normalize(): void
{
    $casos = [
        'Matemáticas'        => 'matemáticas',
        '  ONDAS  '          => 'ondas',
        "a\tb\nc"            => 'a b c',
        'física-química'     => 'física química',
        'C++'                => 'c',
        "' OR 1=1 --"        => 'or 1 1',
        '100%'               => '100',
        '🙂'                 => '',
        ''                   => '',
        '   '                => '',
        "\x00\x01"           => '',
        'a  b   c'           => 'a b c',
    ];
    foreach ($casos as $in => $esperado) {
        subtest(iarepo_show($in), static function () use ($in, $esperado): void {
            assert_eq($esperado, iarepo_normalize((string) $in));
        });
    }
    assert_eq(IAREPO_MAX_RAW, mb_strlen(iarepo_normalize(str_repeat('a', 500)), 'UTF-8'), 'recorte a IAREPO_MAX_RAW');
}

function test_tokenize(): void
{
    assert_eq(['ondas', 'sonido'], iarepo_tokenize('Ondas  Sonido'));
    assert_eq(['a', 'b'], iarepo_tokenize('a b a b a'), 'debe deduplicar preservando el orden');
    assert_eq([], iarepo_tokenize('   '));
    assert_eq([], iarepo_tokenize('🙂'));
    assert_count(IAREPO_MAX_TERMS, iarepo_tokenize('t1 t2 t3 t4 t5 t6 t7 t8 t9 t10'));
    assert_eq(IAREPO_MAX_TOKEN, mb_strlen(iarepo_tokenize(str_repeat('z', 200))[0], 'UTF-8'));
}

function test_stem(): void
{
    $casos = [
        // plurales que deben reducirse
        'ondas'   => 'onda',
        'waves'   => 'wave',
        'cycles'  => 'cycl',
        'valores' => 'valor',
        'classes' => 'class',
        'gases'   => 'gase',   // conservador a propósito: solo ensancha
        // singulares y palabras que NO deben tocarse
        'onda'    => 'onda',
        'class'   => 'class',
        'gas'     => 'gas',
        'los'     => 'los',
        'ph'      => 'ph',
        'c'       => 'c',
        'less'    => 'less',
        'físicas' => 'física',
    ];
    foreach ($casos as $in => $esperado) {
        subtest($in, static function () use ($in, $esperado): void {
            assert_eq($esperado, iarepo_stem((string) $in));
        });
    }

    // Invariante clave: el stem SIEMPRE es un prefijo del término, así que
    // LIKE '%stem%' nunca puede perder un resultado que el término sí daría.
    foreach (st_corpus() as $label => $raw) {
        subtest('prefijo: ' . $label, static function () use ($raw): void {
            foreach (iarepo_tokenize($raw) as $t) {
                $s = iarepo_stem($t);
                assert_true(str_starts_with($t, $s), "el stem '{$s}' no es prefijo de '{$t}'");
                assert_true(mb_strlen($s, 'UTF-8') > 0, 'un stem no puede quedarse vacío');
            }
        });
    }
}

function test_like_escape(): void
{
    assert_eq('!!', iarepo_like_escape('!'));
    assert_eq('!%', iarepo_like_escape('%'));
    assert_eq('!_', iarepo_like_escape('_'));
    // El orden importa: si se escapara el ! al final, se duplicarían los
    // escapes que la propia función acaba de introducir.
    assert_eq('!!!%!_', iarepo_like_escape('!%_'));
    assert_eq('100!%', iarepo_like_escape('100%'));
    assert_eq('ondas', iarepo_like_escape('ondas'), 'sin comodines no debe tocar nada');
    assert_eq('', iarepo_like_escape(''));

    // Doble escapado = idempotencia rota; comprobamos que NO es idempotente
    // a propósito (escapar dos veces sería un bug del llamador, no de aquí).
    assert_neq(iarepo_like_escape('%'), iarepo_like_escape(iarepo_like_escape('%')));
}

function test_raw_phrase(): void
{
    assert_eq('c++', iarepo_raw_phrase('C++'), 'debe conservar la puntuación');
    assert_eq('física-química', iarepo_raw_phrase('  Física-Química  '));
    assert_eq('a b', iarepo_raw_phrase("a\t\n b"), 'colapsa los espacios');
    assert_eq('', iarepo_raw_phrase('   '));
    assert_eq(IAREPO_MAX_RAW, mb_strlen(iarepo_raw_phrase(str_repeat('x', 400)), 'UTF-8'));

    // Solo se usa para puntuar; nunca para filtrar.
    $r = iarepo_build_search('C++');
    assert_not_contains('%c++%', implode('|', $r['params']), 'la frase cruda no puede filtrar');
}
