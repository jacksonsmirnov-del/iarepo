<?php
// ================================================================
// tests/integration/search_db_test.php — El buscador CONTRA BD REAL
//
// Ejecuta shared/search.php con las MISMAS consultas que hace
// api/resources.php (COUNT + página, ver iarepo_it_api_list en
// bootstrap.php) sobre un MariaDB de verdad con el corpus de
// tests/fixtures/seed.sql.
//
// Cada test corresponde a una fila de la tabla de evidencia del
// diagnóstico: son los fallos REPRODUCIDOS en producción antes del
// arreglo. Si uno se pone rojo, la regresión ha vuelto.
//
//   php tests/run.php --integration       (runner común)
//   php tests/integration/_runner.php     (runner autónomo)
//
// Sin Docker, todos los tests imprimen SKIP y pasan.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

// IDs del corpus (ver tests/fixtures/seed.sql).
const IT_ONDAS       = [1000, 1001, 1002];               // 3 recursos con "onda(s)" visibles
const IT_MATEM       = [1003, 1004, 1033];               // 1033 casa por subject_area, no por título
const IT_LAB         = [1030, 1031, 1032, 1033, 1034, 1035];
const IT_VISIBLES    = [1000, 1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008, 1009, 1010,
                        1011, 1012, 1013, 1014, 1030, 1031, 1032, 1033, 1034, 1035]; // 21
const IT_OCULTOS     = [1020, 1021, 1022, 1023];         // draft / broken / inactivo / otro tenant

// ── El catálogo base ──────────────────────────────────────────

function test_it_catalogo_base_sin_busqueda(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $r = iarepo_it_api_list($db, ['limit' => 100]);

    it_eq('none', $r['search']['mode'], 'Sin parámetro search el modo debe ser "none"');
    it_eq(21, $r['total'], 'El anónimo ve exactamente las 21 filas community/activas/no rotas');
    it_same_set(IT_VISIBLES, $r['ids'], 'Conjunto base del catálogo');

    // Las 4 filas ocultas no pueden aparecer NUNCA para un anónimo.
    foreach (IT_OCULTOS as $id)
        it_not_contains($r['ids'], $id, "El recurso oculto $id se ha colado en el catálogo");
}

// ── Prefijos: "matem" daba 0 (el fulltext exige palabra completa) ──

function test_it_prefijo_matem_encuentra_matematicas(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $r = iarepo_it_api_list($db, ['search' => 'matem', 'limit' => 100]);

    it_true($r['total'] > 0, 'REGRESIÓN: "matem" vuelve a devolver 0 resultados');
    it_eq('hybrid', $r['search']['mode'], '"matem" debe ir por el brazo fulltext con comodín');
    it_eq('+matem*', $r['search']['debug']['ft'], 'La cadena fulltext debe llevar el comodín de prefijo');
    it_same_set(IT_MATEM, $r['ids'], '"matem" debe traer los dos de matemáticas y el de subject_area');
    it_first($r['ids'], 1003, '"matem" debe encabezarlo el recurso cuyo TÍTULO empieza por Matemáticas');
}

function test_it_acentos_son_indiferentes(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // El collation es accent-insensitive: no es un problema, pero hay que
    // impedir que una "mejora" futura (quitar acentos a mano) lo rompa.
    $sin  = iarepo_it_ids($db, 'matematicas');
    $con  = iarepo_it_ids($db, 'matemáticas');
    $mixt = iarepo_it_ids($db, 'MaTeMáTiCaS');

    it_same_set($sin, $con, 'Con y sin acento deben dar lo mismo');
    it_same_set($sin, $mixt, 'La búsqueda debe ser insensible a mayúsculas');
    it_same_set(IT_MATEM, $sin, 'Y el conjunto debe ser el de "matem"');
}

// ── Plural/singular: "ondas"=9 y "onda"=2 era el síntoma ──────

function test_it_plural_y_singular_dan_el_mismo_conjunto(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $plural   = iarepo_it_ids($db, 'ondas');
    $singular = iarepo_it_ids($db, 'onda');

    it_same_set(IT_ONDAS, $plural, '"ondas" debe traer los 3 recursos de ondas');
    it_same_set($plural, $singular, 'REGRESIÓN: singular y plural dan conjuntos distintos');
}

// ── Multi-palabra: BOOLEAN MODE sin '+' hacía OR, no AND ──────

function test_it_multipalabra_es_and_no_or(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $ondas = iarepo_it_ids($db, 'ondas');
    $ambas = iarepo_it_ids($db, 'ondas sonido');

    // Con sinónimos cada término es un GRUPO, pero el '+' sigue pegado a
    // cada grupo: el OR vive DENTRO del paréntesis y el AND entre grupos.
    it_eq('+(onda* wave*) +(sonido* sound* acoustic* acustica*)',
        iarepo_it_api_list($db, ['search' => 'ondas sonido'])['search']['debug']['ft'],
        'Los dos grupos deben ir con "+" (si no, el fulltext hace OR)');
    it_same_set([1000], $ambas, 'Sólo 1000 tiene "onda" Y "sonido"');
    it_true(count($ambas) < count($ondas),
        'REGRESIÓN: añadir una palabra no reduce los resultados → se está haciendo OR');

    // El AND tiene que aguantar aunque los dos conceptos lleguen por
    // sinónimo: 1000 lleva la etiqueta "acustica" y dice "sonido", pero
    // 1001 y 1002 (que son ondas y no dicen nada de sonido) deben quedar
    // fuera. Si el grupo se emitiese sin '+', volverían.
    it_not_contains($ambas, 1001, 'REGRESIÓN: "Ondas electromagnéticas" no habla de sonido');
    it_not_contains($ambas, 1002, 'REGRESIÓN: "La onda de choque" no habla de sonido');
}

function test_it_energia_cinetica_excluye_el_senuelo(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Antes: 9 resultados con "Oxígeno: Materia y Energía" (1014) el primero.
    $r = iarepo_it_api_list($db, ['search' => 'energia cinetica', 'limit' => 100]);

    it_same_set([1013], $r['ids'], '"energia cinetica" debe traer sólo el recurso de energía cinética');
    it_not_contains($r['ids'], 1014, 'REGRESIÓN: el señuelo con sólo "energía" vuelve a colarse');
}

// ── Tokens cortos: InnoDB descarta <3 chars ───────────────────

function test_it_token_corto_ph_encuentra_y_ordena(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $r = iarepo_it_api_list($db, ['search' => 'pH', 'limit' => 100]);

    it_true($r['total'] > 0, 'REGRESIÓN: "pH" (el ejemplo del placeholder) vuelve a dar 0');
    it_eq('like', $r['search']['mode'], 'Un token de 2 chars no puede ir por fulltext: modo "like"');
    it_eq('', $r['search']['debug']['ft'], 'JAMÁS debe emitirse "+ph*": anularía la consulta entera');
    it_first($r['ids'], 1005, '"pH" debe encabezarlo "Escala de pH", no el ruido por subcadena');

    // Antes el ruido por subcadena se admitía "porque el ranking lo deja
    // detrás". No basta: con LIKE '%ph%' el contador decía 149 recursos
    // sobre el catálogo real. Ahora un término corto exige PALABRA, así
    // que el ruido no entra siquiera.
    it_same_set([1005], $r['ids'], '"pH" sólo debe traer el recurso de pH');
    it_not_contains($r['ids'], 1007, 'REGRESIÓN: "Photosynthesis" vuelve a colarse por la subcadena "ph"');
    it_not_contains($r['ids'], 1006, 'REGRESIÓN: "PhET" vuelve a colarse por la subcadena "ph"');
}

// ── PRECISIÓN de los términos cortos (el colapso medido en prod) ──

function test_it_termino_corto_no_arrastra_el_catalogo(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Medido contra el catálogo real de 546 filas ANTES del arreglo:
    //   ?search=C++ → 541   ?search=pH → 149   ?search=a → 542
    // El recurso correcto salía primero, pero el "N recursos" que pinta
    // index.php no significaba nada y las páginas 2..55 eran ruido.
    $base = iarepo_it_api_list($db, ['limit' => 100])['total'];

    $casos = [
        'pH'  => [1005],
        'C++' => [1011],   // 'c': sólo "Introducción a C++" tiene una "c" suelta
        'C#'  => [1011],
        '3D'  => [],
        'IA'  => [],
        '0'   => [],
    ];
    foreach ($casos as $q => $esperado) {
        $r = iarepo_it_api_list($db, ['search' => (string) $q, 'limit' => 100]);
        it_same_set($esperado, $r['ids'], "'$q' debe filtrar por palabra completa");
        it_eq($r['total'], count($r['ids']), "COUNT y filas no coinciden para '$q'");
        it_true($r['total'] <= 3,
            "'$q' devuelve {$r['total']} de $base recursos: el término corto vuelve a filtrar por subcadena");
    }
}

function test_it_termino_corto_casa_pese_a_la_puntuacion_pegada(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // La frontera de palabra NO es "rodeado de espacios": tiene que casar
    // "pH." / "(pH)" / "pH-metro" / "C++" igual que " pH ". Si alguien la
    // sustituyese por un LIKE '% ph %' pelado, esto se pone rojo.
    it_same_set([1011], iarepo_it_ids($db, 'C++'),
        '"C++" debe casar la "C" pegada a los "++" del título');
    it_same_set([1005], iarepo_it_ids($db, 'pH'),
        '"pH" debe casar aunque en el corpus vaya seguido de espacio o de puntuación');

    // Y el recall del término corto llega a la DESCRIPCIÓN, no sólo al
    // título: en 1005 "pH" aparece en ambos, pero restringir la búsqueda
    // corta a las columnas de alta señal perdería casos reales (medido
    // sobre el catálogo: 4 aciertos con el haystack completo, 2 sin él).
    $fila = $db->query("SELECT description FROM resources WHERE id = 1005")->fetch();
    it_true(stripos((string) $fila['description'], 'ph') !== false,
        'El corpus se ha degradado: 1005 ya no menciona "pH" en la descripción');
}

function test_it_termino_corto_no_rompe_el_and_multipalabra(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // El brazo fulltext lleva pegada en AND la condición del término corto.
    // Al pasar de subcadena a palabra, ese AND tiene que seguir siendo AND.
    it_same_set([1005], iarepo_it_ids($db, 'pH escala'), '"pH escala" debe exigir AMBOS');
    it_same_set([], iarepo_it_ids($db, 'pH fotosintesis'), 'Y no debe casar por la subcadena "ph" de Photosynthesis');
    it_same_set([], iarepo_it_ids($db, 'pH circuitos'), 'Ni por la subcadena "ph" de PhET');
}

function test_it_termino_corto_ancla_el_and(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Con un OR pelado, "pH escala" devolvería TODO lo que contenga "escala"
    // (el fulltext no puede exigir "ph"). Debe seguir siendo AND.
    $r = iarepo_it_api_list($db, ['search' => 'pH escala', 'limit' => 100]);

    it_eq('+escala*', $r['search']['debug']['ft'], 'Sólo "escala" es indexable');
    it_same_set([1005], $r['ids'], '"pH escala" debe exigir AMBOS términos');
}

// ── Operadores del parser fulltext: el HTTP 500 ───────────────

function test_it_cpp_no_revienta_y_sale_primero(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Antes: HTTP 500 (ERROR 1064, el '+' es operador de BOOLEAN MODE).
    $r = iarepo_it_api_list($db, ['search' => 'C++', 'limit' => 100]);

    it_eq('', $r['search']['debug']['ft'], 'Ningún operador puede llegar a AGAINST()');
    it_first($r['ids'], 1011, '"C++" debe encabezarlo "Introducción a C++" (frase cruda con puntuación)');
}

function test_it_guion_no_se_interpreta_como_negacion(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Antes: el '-' era NOT y "física-química" devolvía 1 resultado erróneo.
    $conGuion = iarepo_it_ids($db, 'física-química');
    $conEsp   = iarepo_it_ids($db, 'física química');

    it_same_set([1012], $conGuion, '"física-química" debe traer el recurso de física y química');
    it_same_set($conEsp, $conGuion, 'El guion debe comportarse como un separador, no como NOT');
}

// ── Stopwords: un '+the*' anula la consulta entera ────────────

function test_it_stopwords_no_anulan_la_consulta(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Antes: 6 resultados y ninguno del ciclo del agua.
    $r = iarepo_it_api_list($db, ['search' => 'the water cycle', 'limit' => 100]);

    // 'water' tiene grupo ('agua'); 'cycle' no está en el diccionario y sale
    // sin paréntesis, igual que antes de los sinónimos.
    it_eq('+(water* agua*) +cycle*', $r['search']['debug']['ft'], '"the" debe quedar FUERA del fulltext');
    it_same_set(['the'], $r['search']['debug']['dropped'], '"the" debe registrarse como descartado');
    it_same_set([1008], $r['ids'], '"the water cycle" debe traer The Water Cycle');

    // 1009 ("Ciclo del agua") SÍ casa ahora el primer grupo por el sinónimo
    // "agua", y aun así debe quedar fuera: no casa el segundo grupo, porque
    // "cycle" no está en el diccionario y "ciclo" no es su subcadena. Es la
    // prueba de que el AND entre grupos sigue mandando sobre el OR interno.
    it_not_contains($r['ids'], 1009, 'REGRESIÓN: el AND entre grupos se ha vuelto OR');

    // Y el plural inglés debe dar lo mismo.
    it_same_set([1008], iarepo_it_ids($db, 'water cycles'), '"water cycles" debe encontrar lo mismo');
}

function test_it_consulta_solo_de_stopwords(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // "de la": si se emitiese '+de* +la*' el fulltext daría 0. shared/search.php
    // cae a la frase completa por LIKE, así que devuelve algo coherente.
    $r = iarepo_it_api_list($db, ['search' => 'de la', 'limit' => 100]);

    it_eq('like', $r['search']['mode'], 'Sólo-stopwords debe caer al brazo LIKE');
    it_eq('', $r['search']['debug']['ft'], 'No puede emitirse "+de* +la*"');
    it_same_set([1001, 1006, 1010], $r['ids'], 'Debe traer las filas que contienen la frase "de la"');
    it_true($r['total'] < 21, 'Una consulta de stopwords no puede devolver el catálogo entero');
}

function test_it_search_cero_no_devuelve_el_catalogo(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // empty('0') es true: ?search=0 se ignoraba y devolvía las 21 filas.
    $r = iarepo_it_api_list($db, ['search' => '0', 'limit' => 100]);

    it_eq('like', $r['search']['mode'], '"0" es una búsqueda real, no un parámetro ausente');
    it_eq(0, $r['total'], 'REGRESIÓN: ?search=0 vuelve a devolver el catálogo entero');
}

// ── Columnas que el índice FULLTEXT no cubre ──────────────────

function test_it_source_name_es_buscable(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // "PhET" no está ni en title, ni en description, ni en topic_tag del
    // recurso 1006: sólo en source_name. Antes devolvía 0 pese a haber
    // más de 100 recursos de esa fuente en producción.
    $r = iarepo_it_api_list($db, ['search' => 'PhET', 'limit' => 100]);

    it_same_set([1006], $r['ids'], 'REGRESIÓN: "PhET" no llega a source_name');

    $fila = $db->query("SELECT title, description, topic_tag FROM resources WHERE id = 1006")->fetch();
    foreach ($fila as $col => $val)
        it_true(stripos((string) $val, 'phet') === false,
            "El corpus se ha degradado: 'phet' aparece en $col, así que el test ya no prueba source_name");
}

function test_it_tags_son_buscables(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // 'simulation' vive SÓLO en resource_tags → sólo lo alcanza el EXISTS.
    $r = iarepo_it_api_list($db, ['search' => 'simulation', 'limit' => 100]);

    it_same_set([1000, 1006, 1032], $r['ids'], 'REGRESIÓN: la búsqueda no llega a resource_tags');
}

// ── Orden por relevancia ──────────────────────────────────────

function test_it_relevancia_ordena_distinto_que_fecha(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // El corpus tiene los "laboratorio" con created_at EXACTAMENTE al revés
    // que su relevancia: si el orden por relevancia no se aplicase, este
    // test no podría pasar por casualidad.
    $rel    = iarepo_it_api_list($db, ['search' => 'laboratorio', 'limit' => 100]);
    $recent = iarepo_it_api_list($db, ['search' => 'laboratorio', 'limit' => 100, 'sort' => 'recent']);

    it_eq('relevance', $rel['sort'], 'Con búsqueda y sin sort explícito debe ordenarse por relevancia');
    it_eq('recent', $recent['sort'], 'El sort explícito del cliente debe respetarse');

    it_same_set($recent['ids'], $rel['ids'], 'El orden no puede cambiar QUÉ filas salen');
    it_true($rel['ids'] !== $recent['ids'], 'REGRESIÓN: relevancia y fecha dan el mismo orden');

    it_first($rel['ids'], 1035, 'Por relevancia manda el título + popularidad');
    it_first($recent['ids'], 1030, 'Por fecha manda created_at');
    it_eq(1032, $rel['ids'][count($rel['ids']) - 1],
        'El que sólo casa por topic_tag (no por título) debe quedar el último');
}

function test_it_sort_explicito_se_respeta(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $porTitulo = iarepo_it_api_list($db, ['search' => 'laboratorio', 'limit' => 100, 'sort' => 'title']);
    it_eq('title', $porTitulo['sort'], 'sort=title debe respetarse aunque haya búsqueda');

    $titulos = array_map(static fn($r) => $r['title'], $porTitulo['rows']);
    it_eq(count(IT_LAB), count($titulos), 'sort=title no puede perder filas');
    it_eq('Laboratorio de electricidad', $titulos[0], 'sort=title debe empezar por el primero alfabético');
    it_eq('Virtual laboratory: microscope', $titulos[5], 'y terminar por el último alfabético');

    // Un sort desconocido cuenta como AUSENTE, nunca como SQL inválido: con
    // búsqueda cae a 'relevance' (el defecto de una petición sin sort) y sin
    // búsqueda a 'recent'. Es la regla de api/resources.php, bloque "── Sort ──",
    // y la misma que replica index.php para pintar el <select>.
    $raro = iarepo_it_api_list($db, ['search' => 'laboratorio', 'limit' => 100, 'sort' => 'r.id; DROP TABLE resources']);
    it_eq('relevance', $raro['sort'], 'Con búsqueda, un sort desconocido cae al defecto: relevance');
    it_eq(6, $raro['total'], 'Y no puede alterar los resultados');

    $raroSinBusqueda = iarepo_it_api_list($db, ['limit' => 100, 'sort' => 'r.id; DROP TABLE resources']);
    it_eq('recent', $raroSinBusqueda['sort'], 'Sin búsqueda, un sort desconocido cae al defecto: recent');
}

function test_it_el_sumando_fulltext_va_acotado(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // 1. Semántica del tope EN EL SERVIDOR. LEAST() con dobles: si MariaDB
    //    hiciese algo raro (redondeo a entero, NULL con mezcla de tipos) el
    //    score entero se iría al garete sin ningún error.
    $stmt = $db->query(
        'SELECT m, LEAST(m * 2, ' . IAREPO_FT_SCORE_CAP . ') AS capped FROM ('
        . 'SELECT 0.0 AS m UNION ALL SELECT 0.49 UNION ALL SELECT 4.813 UNION ALL '
        . 'SELECT 5.9999 UNION ALL SELECT 6.0 UNION ALL SELECT 20.77 UNION ALL SELECT 1000000.0'
        . ') x ORDER BY m'
    );
    foreach ($stmt->fetchAll() as $fila) {
        $m = (float) $fila['m'];
        it_eq(min($m * 2, (float) IAREPO_FT_SCORE_CAP), (float) $fila['capped'],
            "LEAST(MATCH*2, " . IAREPO_FT_SCORE_CAP . ") no acota como se espera para MATCH=$m");
    }

    // 2. El sumando, evaluado sobre el corpus REAL, nunca se pasa del tope.
    foreach (['ondas', 'matem', 'laboratorio', 'energia', 'agua', 'sonido'] as $q) {
        $s = iarepo_build_search($q);
        if ($s['mode'] !== 'hybrid')
            continue;

        $sql = 'SELECT MAX(LEAST((' . IAREPO_MATCH . ') * 2, ' . IAREPO_FT_SCORE_CAP . ')) '
            . 'FROM resources r WHERE r.is_active = 1 AND r.visibility = \'community\'';
        $st = $db->prepare($sql);
        $st->execute([$s['debug']['ft']]);
        $max = (float) $st->fetchColumn();
        it_true($max <= IAREPO_FT_SCORE_CAP,
            "El sumando fulltext de '$q' llega a $max, por encima del tope " . IAREPO_FT_SCORE_CAP);
    }
}

function test_it_el_tope_no_altera_el_orden_en_el_rango_normal(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // EL CASO QUE FIJA EL ORDEN. 'energia' enfrenta al recurso correcto
    // (1013, "Energía cinética y potencial") con el señuelo del
    // diagnóstico (1014, "Oxígeno: materia y energía"), que le saca 1,88
    // puntos de popularidad (view_count 400 vs 25) y sólo pierde por el
    // sumando fulltext: MATCH*2 vale 9,63 en 1013 y 7,22 en 1014.
    //
    // Los dos están POR DEBAJO del tope, así que acotar tiene que ser un
    // no-op aquí. Es el test que impide "arreglar" el sumando de un modo
    // que comprima el RANGO NORMAL en vez de sólo la cola: se probó una
    // curva saturante 12*M/(M+6) y encogía esa diferencia de 2,41 a 0,83,
    // con lo que el señuelo más popular adelantaba al recurso correcto.
    $r = iarepo_it_api_list($db, ['search' => 'energia', 'limit' => 100]);

    // El conjunto ya no es sólo {1013, 1014}: desde los sinónimos, 1007
    // ("How plants convert light into chemical energy") entra por 'energy'.
    // Lo que este test fija es el ORDEN entre el recurso correcto y el
    // señuelo, y ése no puede moverse.
    it_contains($r['ids'], 1013, '"energia" debe traer "Energía cinética y potencial"');
    it_contains($r['ids'], 1014, '"energia" debe traer también el señuelo');
    it_first($r['ids'], 1013, 'REGRESIÓN: el señuelo más popular adelanta a "Energía cinética y potencial"');

    // Y los dos que dicen "energía" van por delante del que sólo dice
    // "energy": el exacto gana al sinónimo.
    it_true(array_search(1014, $r['ids'], true) < array_search(1007, $r['ids'], true),
        'REGRESIÓN: el recurso que sólo casa por el sinónimo adelanta a los que dicen "energía"');

    // Y la premisa que hace significativo el test: ambos por debajo del tope.
    $s  = iarepo_build_search('energia');
    $st = $db->prepare('SELECT r.id, (' . IAREPO_MATCH . ') * 2 AS m FROM resources r WHERE r.id IN (1013, 1014)');
    $st->execute([$s['debug']['ft']]);
    foreach ($st->fetchAll() as $fila)
        it_true((float) $fila['m'] < IAREPO_FT_SCORE_CAP,
            "El corpus ha cambiado: MATCH*2 de {$fila['id']} vale {$fila['m']} y ya no está por debajo del tope");
}

function test_it_relevancia_no_altera_el_conjunto(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // La relevancia es sólo ORDER BY: jamás puede cambiar el conjunto ni el total.
    foreach (['ondas', 'matem', 'pH', 'C++', 'laboratorio', 'simulation'] as $q) {
        $rel  = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100]);
        $rec  = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100, 'sort' => 'recent']);
        $pop  = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100, 'sort' => 'popular']);

        it_eq($rel['total'], $rec['total'], "El total de '$q' cambia con el orden");
        it_eq($rel['total'], $pop['total'], "El total de '$q' cambia con el orden");
        it_same_set($rel['ids'], $rec['ids'], "El conjunto de '$q' cambia con el orden");
        it_same_set($rel['ids'], $pop['ids'], "El conjunto de '$q' cambia con el orden");
    }
}

// ── Coherencia COUNT ↔ filas ──────────────────────────────────

function test_it_total_coincide_con_las_filas_devueltas(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // El COUNT y la página son DOS consultas distintas con listas de
    // parámetros distintas (la página lleva delante los del score). Si el
    // orden de parámetros se descuadra, aquí se ve.
    foreach (['', 'ondas', 'matem', 'pH', 'C++', 'the water cycle', 'de la',
              'laboratorio', 'simulation', 'física-química', 'pH escala'] as $q) {
        $r = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100]);
        it_eq($r['total'], count($r['ids']),
            "COUNT y filas no coinciden para '$q' (¿parámetros descuadrados?)");
    }
}

// ── Paginación ────────────────────────────────────────────────

function test_it_paginacion_no_pierde_ni_repite(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Con limit=10 (el mínimo que admite la API). El caso CON búsqueda ya
    // no puede ser 'C++': antes cruzaba páginas porque devolvía 20 de 21
    // filas por la subcadena "c", que es justo el fallo que se ha
    // arreglado. Se usa 'de' (sólo-stopwords → frase completa) que sigue
    // dando más de una página, y el total se toma de la propia API en vez
    // de codificarlo, para que el test no se rompa al tocar el corpus.
    foreach (['de', ''] as $q) {
        $esperado = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100])['total'];
        it_true($esperado > 10, "'$q' debe cruzar más de una página con limit=10 (total=$esperado)");

        $vistos = [];
        $total  = null;

        for ($p = 1; $p <= 5; $p++) {
            $r = iarepo_it_api_list($db, ['search' => $q, 'limit' => 10, 'page' => $p]);
            if ($total === null)
                $total = $r['total'];

            it_eq($total, $r['total'], "El total de '$q' cambia entre páginas");
            foreach ($r['ids'] as $id) {
                it_true(!isset($vistos[$id]), "La página $p de '$q' repite el id $id");
                $vistos[$id] = true;
            }
            if ($r['ids'] === [])
                break;
        }

        it_eq($esperado, $total, "Total inesperado para '$q'");
        it_eq($esperado, count($vistos), "Recorrer las páginas de '$q' pierde o repite filas");
    }
}

function test_it_paginacion_fina_con_empates(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Páginas de 2 en 2 sobre los 6 "laboratorio": es donde un ORDER BY sin
    // desempate determinista (el ", r.id" final) duplica y pierde filas.
    $vistos = [];
    for ($p = 1; $p <= 3; $p++) {
        $r = iarepo_it_api_list($db, ['search' => 'laboratorio', '_raw_limit' => 2, 'page' => $p]);
        it_eq(6, $r['total'], 'El total debe ser estable entre páginas');
        it_eq(2, count($r['ids']), "La página $p debe traer 2 filas");
        foreach ($r['ids'] as $id) {
            it_true(!isset($vistos[$id]), "La página $p repite el id $id (falta el desempate por r.id)");
            $vistos[$id] = true;
        }
    }
    it_same_set(IT_LAB, array_keys($vistos), 'Las 3 páginas deben cubrir los 6 recursos');
}

// ── Filtros combinados con la búsqueda ────────────────────────

function test_it_filtros_se_combinan_con_la_busqueda(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $chem = (int) $db->query("SELECT id FROM categories WHERE slug = 'chemistry'")->fetchColumn();

    $casos = [
        [['lang' => 'es'],                     [1030, 1031, 1033, 1034]],
        [['lang' => 'en'],                     [1032, 1035]],
        [['level' => 'primaria'],              [1033, 1034, 1035]],
        [['type' => 'url'],                    [1031, 1035]],
        [['area' => 'Física'],                 [1031, 1034]],
        [['tag' => 'quimica'],                 [1030]],
        [['category' => $chem],                [1030]],
        [['lang' => 'en', 'level' => 'primaria'], [1035]],
        [['lang' => 'es', 'type' => 'url'],    [1031]],
    ];

    foreach ($casos as [$filtro, $esperado]) {
        $r = iarepo_it_api_list($db, array_merge(['search' => 'laboratorio', 'limit' => 100], $filtro));
        $etq = json_encode($filtro, JSON_UNESCAPED_UNICODE);
        it_same_set($esperado, $r['ids'], "search=laboratorio + $etq");
        it_eq($r['total'], count($r['ids']), "COUNT descuadrado con el filtro $etq");
    }
}

function test_it_filtro_sin_resultados_no_es_un_error(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $r = iarepo_it_api_list($db, ['search' => 'laboratorio', 'limit' => 100, 'lang' => 'pt']);
    it_eq(0, $r['total'], 'Un filtro que no casa debe dar 0, no un error');
    it_eq([], $r['ids'], 'Y ninguna fila');
}

// ── Visibilidad ───────────────────────────────────────────────

function test_it_visibilidad_respeta_al_usuario(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $anon  = iarepo_it_ids($db, 'ondas');
    $autor = iarepo_it_api_list($db, ['search' => 'ondas', 'limit' => 100], ['tenant_id' => 1, 'user_id' => 1])['ids'];
    $otro  = iarepo_it_api_list($db, ['search' => 'ondas', 'limit' => 100], ['tenant_id' => 1, 'user_id' => 2])['ids'];
    $ajeno = iarepo_it_api_list($db, ['search' => 'ondas', 'limit' => 100], ['tenant_id' => 7, 'user_id' => 70])['ids'];

    it_same_set(IT_ONDAS, $anon, 'El anónimo sólo ve community');
    it_same_set(array_merge(IT_ONDAS, [1020]), $autor, 'El autor ve su propio borrador');
    it_same_set(IT_ONDAS, $otro, 'Otro usuario del mismo tenant NO ve el borrador ajeno');
    it_same_set(array_merge(IT_ONDAS, [1023]), $ajeno, 'El tenant 7 ve su recurso de centro');
    it_not_contains($ajeno, 1020, 'El tenant 7 no puede ver el borrador del tenant 1');

    // Ni el enlace roto ni el inactivo aparecen para NADIE.
    foreach ([$anon, $autor, $otro, $ajeno] as $lista) {
        it_not_contains($lista, 1021, 'Un recurso con link_status=broken no puede listarse');
        it_not_contains($lista, 1022, 'Un recurso con is_active=0 no puede listarse');
    }
}

// ── Premisas del diseño de shared/search.php ──────────────────

function test_it_premisas_del_motor_se_cumplen(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // shared/search.php está construido sobre estas tres premisas. Si el
    // motor de pruebas dejase de cumplirlas, los tests de arriba estarían
    // validando un escenario que no es el de producción.
    $min = (int) $db->query('SELECT @@innodb_ft_min_token_size')->fetchColumn();
    it_eq(IAREPO_FT_MIN, $min,
        'IAREPO_FT_MIN debe coincidir con innodb_ft_min_token_size del servidor');

    $ver = (string) $db->query('SELECT VERSION()')->fetchColumn();
    it_true(stripos($ver, 'mariadb') !== false,
        "Producción es MariaDB; este motor dice '$ver' (¿IAREPO_TEST_DB_IMAGE mal puesto?)");

    $idx   = $db->query("SHOW INDEX FROM resources WHERE Key_name = 'idx_search'")->fetchAll();
    $cols  = array_map(static fn($r) => $r['Column_name'], $idx);
    $falta = array_values(array_diff(['title', 'description', 'topic_tag'], $cols));
    it_true($falta === [],
        'El índice FULLTEXT idx_search de setup/schema.sql no cubre: ' . implode(', ', $falta));
}

function test_it_el_regexp_de_frontera_de_palabra_se_comporta(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // La precisión de los términos cortos descansa ENTERA sobre esta
    // premisa: que el REGEXP del servidor entienda \p{L}/\p{N} y trate
    // ñ/á/中 como caracteres de palabra. Si el MariaDB de producción se
    // comportase de otro modo (o rechazase el patrón con un error 1139 →
    // HTTP 500), este test es el que lo dice. Comprobado en 11.8.
    $casos = [
        // [texto, token, ¿debe casar?]
        ['pH Scale: Basics',      'ph', true],   // seguido de espacio
        ['medir el pH.',          'ph', true],   // pegado a puntuación
        ['Escala (pH) del agua',  'ph', true],   // entre paréntesis
        ['pH-metro',              'ph', true],   // pegado a un guion
        ['pH',                    'ph', true],   // la cadena entera
        ['Photosynthesis',        'ph', false],  // el ruido de siempre
        ['alpha',                 'ph', false],  // en medio de la palabra
        ['Introducción a C++',    'c',  true],   // "C" pegada a los "++"
        ['CalcPlot3D',            'c',  false],
        ['niños',                 'ni', false],  // ñ ES carácter de palabra
        ['español',               'es', false],  // idem
        ['中文字',                 '中', false],  // y los ideogramas también
    ];
    foreach ($casos as [$texto, $token, $debe]) {
        $rx = iarepo_word_regexp($token);
        it_true($rx !== '', "iarepo_word_regexp('$token') no ha producido patrón");

        $st = $db->prepare('SELECT ? REGEXP ?');
        $st->execute([$texto, $rx]);
        it_eq($debe ? 1 : 0, (int) $st->fetchColumn(),
            "El REGEXP del servidor no trata '$token' como palabra dentro de '$texto'");
    }

    // Y un patrón construido a partir de basura JAMÁS puede llegar aquí:
    // iarepo_word_regexp() lo rechaza antes (si no, error 1139 → 500).
    foreach (['c++', '(a+)+', '[', '\\', '.*'] as $malo)
        it_eq('', iarepo_word_regexp($malo), "'$malo' no puede producir un patrón REGEXP");
}

function test_it_el_modo_declarado_es_el_que_documenta_el_contrato(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // La API devuelve search.mode al navegador. El contrato de
    // shared/search.php documenta TRES modos; 'fulltext' no existe porque
    // el segundo brazo está siempre presente. Si alguien lo emitiese, un
    // consumidor que ramificase por el modo se quedaría sin rama.
    $vistos = [];
    foreach (['', 'pH', 'C++', 'matem', 'ondas sonido', 'the water cycle', 'de la', '0',
              'PhET', 'simulation', 'física-química', 'pH escala', '🙂', '+++'] as $q) {
        $modo = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100])['search']['mode'];
        it_true(in_array($modo, ['none', 'like', 'hybrid'], true), "Modo fuera del contrato para '$q': $modo");
        it_true($modo !== 'fulltext', "'fulltext' no es un modo del contrato (consulta '$q')");
        $vistos[$modo] = true;
    }
    $faltan = array_diff(['none', 'like', 'hybrid'], array_keys($vistos));
    it_true($faltan === [],
        'La batería debe ejercitar los TRES modos del contrato; no ha salido: ' . implode(', ', $faltan));
}

// ================================================================
// SINÓNIMOS ES↔EN — cada término es un GRUPO
//
// El catálogo es bilingüe y `lang` no es de fiar, así que "biología"
// devolvía CERO en un catálogo con recursos de biology. Estos tests
// comprueban contra el motor REAL las tres cosas que pueden romperse:
// que MariaDB entienda los grupos, que el AND siga siendo AND, y que
// ensanchar el recall no se lleve por delante la precisión.
// ================================================================

/**
 * PREMISA DEL DISEÑO, verificada contra el motor y no contra la
 * documentación: '+(a* b*)' en BOOLEAN MODE significa "obligatorio al
 * menos uno". Si MariaDB dejase de aceptarlo, TODO lo demás de esta
 * sección sería humo, así que se comprueba lo primero y sin pasar por
 * shared/search.php.
 */
function test_it_mariadb_acepta_los_grupos_del_boolean_mode(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $ids = static function (string $expr) use ($db): array {
        $st = $db->prepare(
            'SELECT r.id FROM resources r WHERE r.id BETWEEN 1000 AND 1099'
            . ' AND MATCH(r.title, r.description, r.topic_tag) AGAINST(? IN BOOLEAN MODE) ORDER BY r.id'
        );
        $st->execute([$expr]);
        return array_map('intval', array_column($st->fetchAll(), 'id'));
    };

    // 1002 "La onda de choque supersónica" es el único de estos tres que
    // no menciona el sonido; 1000 sí. En el corpus no hay ningún "wave".
    $onda  = $ids('+onda*');
    $grupo = $ids('+(onda* wave*)');

    it_true($onda !== [], 'El corpus debe tener recursos con "onda"');
    it_same_set($onda, $grupo, 'El OR dentro del grupo no puede PERDER lo que casaba el término solo');

    // El '+' pegado al paréntesis mantiene el grupo OBLIGATORIO.
    it_same_set([], $ids('+(zzzz* qqqq*)'), 'Un grupo sin coincidencias debe anular la consulta');
    it_same_set([], $ids('+(onda* wave*) +(zzzz*)'), 'El AND entre grupos debe seguir siendo AND');

    // Y el AND entre grupos filtra de verdad: exigir además el sonido deja
    // fuera a los que sólo hablan de ondas.
    $ambos = $ids('+(onda* wave*) +(sonido* sound*)');
    it_true(count($ambos) < count($grupo), 'Añadir un grupo obligatorio tiene que reducir el conjunto');
    it_contains($ambos, 1000, '1000 habla de ondas Y de sonido');
    it_not_contains($ambos, 1002, '1002 habla de ondas pero no de sonido');

    // Un miembro que no casa nada NO puede anular a sus hermanos.
    it_same_set($grupo, $ids('+(onda* wave* zzzz*)'), 'Un miembro sin coincidencias no puede vaciar el grupo');
}

/** RECALL: "biología" devolvía CERO teniendo el catálogo recursos de biology. */
function test_it_sinonimos_biologia_encuentra_los_recursos_en_ingles(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // 1014 está catalogado como 'Biología'; 1007 y 1032 como 'Biology'.
    // Antes de los sinónimos la consulta en español sólo alcanzaba 1014.
    foreach (['biologia', 'biología'] as $q) {
        $r = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100]);
        it_same_set([1014, 1007, 1032], $r['ids'], "'$q' debe alcanzar también los catalogados en inglés");
        it_eq($r['total'], count($r['ids']), "COUNT y filas no coinciden para '$q'");
    }

    // Y al revés: el inglés alcanza al español.
    it_same_set([1014, 1007, 1032], iarepo_it_ids($db, 'biology'), 'El grupo es simétrico');

    // Otros pares del corpus, cada uno por una columna distinta.
    it_contains(iarepo_it_ids($db, 'celula'), 1032, '"celula" debe alcanzar "Explore cells…" (descripción)');
    it_contains(iarepo_it_ids($db, 'luz'), 1007, '"luz" debe alcanzar "…convert light…" (descripción)');
    it_contains(iarepo_it_ids($db, 'agua'), 1008, '"agua" debe alcanzar "The Water Cycle" (título)');
    it_contains(iarepo_it_ids($db, 'ciencias'), 1008, '"ciencias" debe alcanzar "Earth Science" (subject_area)');
    it_contains(iarepo_it_ids($db, 'fotosintesis'), 1007, '"fotosintesis" debe alcanzar "Photosynthesis"');
}

/**
 * ORDEN: quien busca "biología" tiene que ver primero lo que dice
 * "biología". Este test es decisivo porque compara EL MISMO conjunto de
 * tres filas bajo las dos consultas: lo único que cambia es el idioma que
 * escribió el usuario, y el orden se da la vuelta.
 *
 * Además aísla el bono del exacto: 1014 tiene 400 visitas y 1032 tiene 13
 * (el desempate por popularidad le da +2 contra +0,06), y aun así 1032
 * adelanta a 1014 cuando el usuario escribe "biology". Sólo el bono de
 * IAREPO_SCORE_EXACT_ANY puede explicar eso.
 */
function test_it_sinonimos_el_exacto_va_antes_que_el_sinonimo(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $es = iarepo_it_api_list($db, ['search' => 'biologia', 'limit' => 100])['ids'];
    $en = iarepo_it_api_list($db, ['search' => 'biology',  'limit' => 100])['ids'];

    it_same_set($es, $en, 'Las dos consultas deben traer EXACTAMENTE las mismas filas');
    it_first($es, 1014, '"biologia" debe encabezarlo el catalogado como "Biología"');
    it_first($en, 1007, '"biology" debe encabezarlo uno de los catalogados como "Biology"');
    it_true(array_search(1014, $en, true) === count($en) - 1,
        'Con la consulta en inglés, el que sólo casa por sinónimo debe caer al final pese a sus 400 visitas');

    // El mismo vuelco con el par agua/water, esta vez en el TÍTULO:
    // 1009 "Ciclo del agua" contra 1008 "The Water Cycle".
    it_first(iarepo_it_ids($db, 'agua'),  1009, '"agua" debe encabezarlo "Ciclo del agua"');
    it_first(iarepo_it_ids($db, 'water'), 1008, '"water" debe encabezarlo "The Water Cycle"');

    // Y la relevancia sigue sin cambiar el CONJUNTO, sólo el orden.
    it_same_set(iarepo_it_ids($db, 'agua'), iarepo_it_ids($db, 'water'), 'Mismo grupo, mismo conjunto');
}

/** El AND entre términos no puede aflojarse porque ahora haya OR dentro. */
function test_it_sinonimos_el_and_multipalabra_sigue_siendo_and(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // 1032 "Virtual laboratory: microscope" [Biology] es el único que casa
    // los dos conceptos, y casa CADA UNO por una vía distinta: "biologia"
    // por el sinónimo (subject_area 'Biology') y "laboratorio" por el
    // término exacto (topic_tag).
    $r = iarepo_it_api_list($db, ['search' => 'biologia laboratorio', 'limit' => 100]);

    it_eq('+(biologia* biology*) +(laboratorio* lab*)', $r['search']['debug']['ft'],
        'Cada término debe ser un grupo obligatorio');
    it_same_set([1032], $r['ids'], 'Sólo 1032 es de biología Y de laboratorio');

    // Ni el conjunto de biología ni el de laboratorio pueden colarse enteros.
    $bio = iarepo_it_ids($db, 'biologia');
    $lab = iarepo_it_ids($db, 'laboratorio');
    it_true(count($r['ids']) < count($bio), 'REGRESIÓN: el segundo grupo no filtra');
    it_true(count($r['ids']) < count($lab), 'REGRESIÓN: el primer grupo no filtra');
    it_not_contains($r['ids'], 1014, '1014 es de biología pero no de laboratorio');
    it_not_contains($r['ids'], 1030, '1030 es de laboratorio pero no de biología');

    // Y con el término corto de por medio, que va fuera del fulltext.
    it_same_set([1005], iarepo_it_ids($db, 'pH quimica'),
        '"pH quimica" debe exigir ambos: el corto por palabra y el otro por su grupo');
}

/**
 * PRECISIÓN. Es el riesgo real de esta función: un sinónimo buscado como
 * SUBCADENA arrastra el catálogo. Medido sobre producción, el sinónimo
 * 'ion' casaba 439 de 546 recursos (80 %) por "Simulations" y "Motion";
 * el corpus reproduce el mismo desastre en pequeño (15 de 21).
 */
function test_it_el_sinonimo_no_casa_por_dentro_de_una_palabra(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // La premisa: el corpus TIENE de sobra dónde equivocarse.
    $st = $db->prepare('SELECT COUNT(*) FROM resources r WHERE r.id BETWEEN 1000 AND 1099 AND '
        . IAREPO_HAYSTACK . ' LIKE ?');
    $st->execute(['%ion%']);
    $porSubcadena = (int) $st->fetchColumn();
    it_true($porSubcadena >= 10,
        "El corpus ya no sirve para este test: sólo $porSubcadena filas llevan 'ion' dentro de una palabra");

    // 'iones' expande a 'ion'. Si el sinónimo fuese subcadena, "propagación",
    // "radiación", "Introducción" y "condensation" entrarían todas.
    $r = iarepo_it_api_list($db, ['search' => 'iones', 'limit' => 100]);
    it_eq([['ione', 'ion']], $r['search']['debug']['groups'], 'El grupo debe expandirse');
    it_true($r['total'] < $porSubcadena,
        "REGRESIÓN: 'iones' devuelve {$r['total']} filas, tantas como el infijo: el sinónimo volvió a ser subcadena");
    foreach ([1000, 1001, 1008, 1011] as $ruido)
        it_not_contains($r['ids'], $ruido, "REGRESIÓN: $ruido casa 'ion' sólo dentro de una palabra");

    // Y el término CORTO del usuario sigue intacto: la expansión no puede
    // reabrir por otro lado el colapso que costó cerrar.
    it_same_set([1005], iarepo_it_ids($db, 'pH'), 'REGRESIÓN: "pH" vuelve a arrastrar el catálogo');
    it_same_set([1011], iarepo_it_ids($db, 'C++'), 'REGRESIÓN: "C++" vuelve a arrastrar el catálogo');

    // Ninguna consulta expandida puede devolver el catálogo entero: si un
    // sinónimo se colase como subcadena, esto lo caza.
    $catalogo = iarepo_it_api_list($db, ['limit' => 100])['total'];
    foreach (['biologia', 'quimica', 'iones', 'arte', 'sumas', 'celula', 'luz', 'agua', 'juego',
              'simulacion', 'interactivo', 'laboratorio', 'energia', 'ondas'] as $q) {
        $t = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100])['total'];
        it_true($t < $catalogo,
            "'$q' devuelve $t de $catalogo recursos: la expansión ha dejado de filtrar");
    }
}

/**
 * Un término SIN sinónimos tiene que comportarse EXACTAMENTE igual que
 * antes: es lo que garantiza que la expansión no pueda romper nada de lo
 * que ya estaba probado.
 */
function test_it_un_termino_sin_sinonimos_se_comporta_igual_que_antes(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Ninguno de estos está en shared/search_synonyms.php. Cada uno cubre
    // una vía distinta: prefijo, source_name, título y tabla de tags.
    $casos = [
        'matem'      => IT_MATEM,
        'phet'       => [1006],
        'escala'     => [1005],
        'microscope' => [1032],
        'indicadores' => [1005],
    ];
    foreach ($casos as $q => $esperado) {
        $r = iarepo_it_api_list($db, ['search' => $q, 'limit' => 100]);
        it_same_set($esperado, $r['ids'], "'$q' no está en el diccionario: no puede cambiar de conjunto");
        it_eq(1, count($r['search']['debug']['groups']), "'$q' debe dar un solo grupo");
        it_eq(1, count($r['search']['debug']['groups'][0]), "'$q' no puede tener sinónimos");
        it_true(!str_contains($r['search']['debug']['ft'], '('),
            "'$q' no puede emitir paréntesis en la cadena fulltext");
    }
}
