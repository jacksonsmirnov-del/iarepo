<?php
// ================================================================
// tests/integration/search_fuzz_test.php — Entradas hostiles contra
// un MariaDB DE VERDAD.
//
// ESTE es el test que habría evitado el HTTP 500 de "C++": no
// comprueba QUÉ devuelve la búsqueda, sino que NINGUNA entrada del
// usuario puede provocar una excepción de SQL. Un test unitario no
// sirve aquí — el error 1064 lo produce el parser de BOOLEAN MODE del
// servidor, no PHP.
//
// Criterio único y binario: 0 excepciones. Cualquier PDOException es
// un HTTP 500 en producción.
//
// Cada entrada se ejecuta por las DOS consultas que hace la API (el
// COUNT y la página con ORDER BY relevancia), porque sólo la segunda
// lleva la expresión de score y su lista de parámetros propia.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

/**
 * Corpus hostil. Determinista: la parte generada usa una semilla fija,
 * así que un fallo siempre se puede reproducir.
 *
 * @return array<string,string> etiqueta => entrada
 */
function iarepo_fuzz_corpus(): array
{
    $c = [];

    // ── 1. Operadores del parser FULLTEXT (los que daban ERROR 1064) ──
    foreach (['+', '-', '*', '"', '(', ')', '~', '<', '>', '@', '\'', '\\'] as $op) {
        $c["op:$op"]        = $op;
        $c["op:$op$op$op"]  = str_repeat($op, 3);
        $c["op:ondas$op"]   = "ondas$op";
        $c["op:{$op}ondas"] = "{$op}ondas";
        $c["op:on{$op}das"] = "on{$op}das";
    }
    $reales = [
        'C++', 'c++', 'C#', 'F#', 'A*', 'físico-químico', 'física-química',
        '+ondas -sonido', '"ondas sonido"', '(ondas', 'ondas)', '(ondas sonido)',
        'ondas~2', '@distance', '>ondas <sonido', '+ +', '- -', '* *', '""', '()',
        '+"ondas"', 'ondas AND sonido', 'ondas OR sonido', 'NOT ondas', 'ondas -',
        '****', '~~~~', '<<<>>>', '@@@@', ')(', '](', '}{', '][',
    ];
    foreach ($reales as $s)
        $c["real:$s"] = $s;

    // ── 2. Comodines y escapes de LIKE ──────────────────────────
    foreach (['%', '_', '%%', '%_%', '100%', 'a_b', '!', '!!', '!%', '!_',
              '\\%', '\\_', '\\\\', '%\\', '_\\_', '!!!%%%___'] as $s)
        $c["like:$s"] = $s;

    // ── 3. Intentos de inyección (no deben ejecutar nada ni romper) ──
    foreach ([
        "' OR 1=1 --", "' OR '1'='1", '" OR ""="', "'; DROP TABLE resources; --",
        '`resources`', "1' UNION SELECT NULL,NULL--", '1;SELECT SLEEP(3)',
        "\\' OR \\'1\\'=\\'1", 'ondas; DELETE FROM resources', '/*!50000 SELECT*/',
        '/* comentario */ ondas', '-- comentario', '#comentario', 'ondas #x',
        'ondas/*', '*/ondas', "0x4f4e444153", 'CHAR(111,110)', 'ondas\0sonido',
    ] as $s)
        $c['inj:' . substr(md5($s), 0, 6)] = $s;

    // ── 4. Unicode ─────────────────────────────────────────────
    foreach ([
        '🙂', '🙂🙂🙂', '🇪🇸', '👨‍👩‍👧‍👦', '☃', '💩ondas💩',
        '中文搜索', 'ロボット', '한국어', 'Ελληνικά', 'Кириллица', 'עברית', 'العربية',
        'नमस्ते', 'ñ', 'Ñ', 'ç', 'ß', 'ø', 'æ', 'ﬁ',
        "e\u{0301}", "\u{200B}", "\u{200E}ondas", "\u{FEFF}ondas", "\u{00A0}ondas",
        "\u{202E}sadno", 'ᴏɴᴅᴀs', 'ＯＮＤＡＳ', 'ⅧⅨ', '½¾', '№§¶',
        "\u{1D7D9}", 'Ⅻondas',
    ] as $s)
        $c['uni:' . substr(md5($s), 0, 6)] = $s;

    // ── 5. UTF-8 INVÁLIDO (rompe preg_replace/u si no se sanea) ──
    foreach ([
        "\xC3\x28",                 // secuencia de 2 bytes truncada
        "\xE2\x28\xA1",             // 3 bytes inválidos
        "\xF0\x28\x8C\x28",         // 4 bytes inválidos
        "\xFF\xFE",                 // BOM UTF-16 en un campo UTF-8
        "\x80\x81\x82",             // bytes de continuación sueltos
        "\xED\xA0\x80",             // surrogate codificado en UTF-8 (CESU-8)
        "ondas\xFFsonido",
        "\xC0\x80",                 // NUL sobrelargo
    ] as $i => $s)
        $c["badutf:$i"] = $s;

    // ── 6. Control y espacios ──────────────────────────────────
    foreach (["\0", "\n", "\r\n", "\t", "\x0B", "\x07", "  ", "\t\t\t",
              "ondas\nsonido", "ondas\0sonido", "ondas\tsonido", " ondas ",
              "\x1B[31mondas\x1B[0m"] as $i => $s)
        $c["ctrl:$i"] = $s;

    // ── 7. Longitudes extremas (el corte está en 120 chars) ────
    foreach ([0, 1, 2, 119, 120, 121, 500, 5000] as $n)
        $c["len:$n"] = str_repeat('a', $n);
    $c['len:multibyte300'] = str_repeat('ó', 300);      // corte en mitad de un multibyte
    $c['len:emoji200']     = str_repeat('🙂', 200);
    $c['len:palabras200']  = trim(str_repeat('onda ', 200));   // 200 términos → tope de 8
    $c['len:token300']     = str_repeat('x', 300) . ' ondas';  // token > IAREPO_MAX_TOKEN
    $c['len:ops500']       = str_repeat('+-*"()~<>@', 50);

    // ── 8. Casos límite semánticos ─────────────────────────────
    foreach (['', ' ', '0', '00', '-0', '1e10', '0x41', 'the', 'de la', 'the the the',
              'a an the of', 'y y y', 'pH', 'ADN', 'C', 'ph escala', 'ondas sonido',
              'matem', 'PhET', 'simulation'] as $s)
        $c['sem:' . ($s === '' ? '(vacio)' : $s)] = $s;

    // ── 9. Generación aleatoria DETERMINISTA ───────────────────
    // Mezcla el alfabeto hostil con texto plausible: es donde aparecen las
    // combinaciones que nadie escribe a mano (p.ej. '+"' seguido de acento).
    mt_srand(20260804);
    $alfabeto = ['+', '-', '*', '"', '(', ')', '~', '<', '>', '@', '%', '_', '!', '\\',
                 "'", '#', ';', '/', '`', '^', '$', '|', '&', '=', '?', '.', ',', ':',
                 ' ', 'a', 'ñ', 'é', '9', '🙂', '中', "\t", 'onda', 'pH', 'C++', 'the'];
    for ($i = 0; $i < 150; $i++) {
        $n = mt_rand(1, 14);
        $s = '';
        for ($j = 0; $j < $n; $j++)
            $s .= $alfabeto[mt_rand(0, count($alfabeto) - 1)];
        $c["rnd:$i"] = $s;
    }

    return $c;
}

// ── El test ───────────────────────────────────────────────────

function test_it_fuzz_ninguna_entrada_produce_error_sql(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $corpus = iarepo_fuzz_corpus();
    it_true(count($corpus) >= 200,
        'El corpus hostil debe tener al menos 200 entradas, tiene ' . count($corpus));

    // Combinaciones de filtros: la búsqueda se concatena con el resto del
    // WHERE, y ahí es donde un parámetro de más o de menos revienta.
    $escenarios = [
        'sola'           => [],
        'con_filtros'    => ['lang' => 'es', 'level' => 'primaria', 'area' => 'Física'],
        'con_tag'        => ['tag' => 'simulation'],
        'orden_recent'   => ['sort' => 'recent'],
        'pagina_2'       => ['page' => 2, 'limit' => 10],
        'autenticado'    => ['__user' => ['tenant_id' => 1, 'user_id' => 1]],
    ];

    $errores  = [];
    $ejecutadas = 0;

    foreach ($corpus as $etiqueta => $entrada) {
        foreach ($escenarios as $nombre => $extra) {
            $user = $extra['__user'] ?? null;
            unset($extra['__user']);

            try {
                $r = iarepo_it_api_list($db, array_merge(['search' => $entrada], $extra), $user);
                $ejecutadas++;

                // Invariantes que deben cumplirse SIEMPRE, no sólo no-reventar.
                if ($r['total'] < 0)
                    $errores[] = "[$etiqueta/$nombre] total negativo";
                if (count($r['ids']) > $r['total'])
                    $errores[] = "[$etiqueta/$nombre] más filas que el COUNT";
            } catch (Throwable $e) {
                // Un solo caso puede fallar en los 6 escenarios: se registra
                // el primero de cada entrada para que el informe sea legible.
                $errores[] = sprintf(
                    "[%s/%s] input=%s (%d bytes)\n        %s",
                    $etiqueta,
                    $nombre,
                    var_export(substr($entrada, 0, 60), true),
                    strlen($entrada),
                    $e->getMessage()
                );
                continue 2;
            }
        }
    }

    if ($errores !== [])
        throw new AssertionError(
            sprintf("%d fallos SQL sobre %d entradas hostiles (cada uno es un HTTP 500):\n      - %s",
                count($errores), count($corpus), implode("\n      - ", array_slice($errores, 0, 15)))
        );

    fwrite(STDOUT, sprintf("      · %d entradas hostiles × %d escenarios = %d consultas, 0 errores SQL\n",
        count($corpus), count($escenarios), $ejecutadas));
}

function test_it_fuzz_invariantes_del_contrato(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Las invariantes del CONTRATO de shared/search.php, verificadas sobre
    // el mismo corpus. Son puras (no necesitan BD) pero se comprueban aquí
    // sobre las MISMAS entradas que se acaban de mandar al servidor: si una
    // se rompe, se sabe que el SQL enviado era inválido por construcción.
    $malos = [];

    foreach (iarepo_fuzz_corpus() as $etiqueta => $entrada) {
        $r = iarepo_build_search($entrada);

        if (substr_count($r['where'], '?') !== count($r['params']))
            $malos[] = "$etiqueta: placeholders del WHERE != params";

        if ($r['score'] !== null && substr_count($r['score'], '?') !== count($r['score_params']))
            $malos[] = "$etiqueta: placeholders del score != score_params";

        $ft = $r['debug']['ft'];
        if ($ft !== '' && !preg_match(IAREPO_FT_SAFE, $ft))
            $malos[] = "$etiqueta: cadena fulltext fuera de la lista blanca: " . var_export($ft, true);

        foreach (IAREPO_STOP as $stop)
            if (strpos($ft, "+$stop*") !== false)
                $malos[] = "$etiqueta: se emitió '+$stop*' (anula la consulta entera)";

        if (!in_array($r['mode'], ['none', 'like', 'hybrid'], true))
            $malos[] = "$etiqueta: modo desconocido '{$r['mode']}'";

        if ($r['mode'] === 'none' && ($r['where'] !== '' || $r['params'] !== []))
            $malos[] = "$etiqueta: modo 'none' con WHERE no vacío";
    }

    it_eq([], array_slice($malos, 0, 10), 'Invariantes del contrato rotas (' . count($malos) . ')');
}

function test_it_fuzz_no_muta_la_base_de_datos(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Una inyección con éxito se vería aquí: el corpus contiene DROP TABLE,
    // DELETE FROM y UNION SELECT. La búsqueda es de sólo lectura.
    $filas = (int) $db->query('SELECT COUNT(*) FROM resources')->fetchColumn();
    $tags  = (int) $db->query('SELECT COUNT(*) FROM resource_tags')->fetchColumn();

    foreach (iarepo_fuzz_corpus() as $entrada)
        iarepo_it_api_list($db, ['search' => $entrada, 'limit' => 10]);

    it_eq($filas, (int) $db->query('SELECT COUNT(*) FROM resources')->fetchColumn(),
        'El fuzzing ha alterado la tabla resources: hay una inyección');
    it_eq($tags, (int) $db->query('SELECT COUNT(*) FROM resource_tags')->fetchColumn(),
        'El fuzzing ha alterado resource_tags: hay una inyección');
}
