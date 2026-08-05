<?php
// ================================================================
// shared/search.php — Construcción SEGURA de la búsqueda de recursos
//
// Funciones PURAS: sin efectos secundarios, sin salida y sin BD. Se
// puede cargar desde la API y desde los tests.
// (Regla crítica del proyecto: jamás requerir shared/helpers.php aquí,
//  arrastra error_handler.php y sus handlers que hacen exit.)
//
// El ÚNICO require de este fichero es shared/search_synonyms.php, que
// no es código: es un `return [...]` de datos, se carga PEREZOSAMENTE
// (sólo la primera vez que alguien busca) y si faltase se degrada a
// "sin sinónimos" en vez de reventar. Nada más puede requerirse aquí.
//
// ── Por qué existe ────────────────────────────────────────────
// El input crudo del usuario NUNCA puede llegar a AGAINST(... IN
// BOOLEAN MODE): los operadores del parser fulltext (+ - * " ( ) ~
// < > @) provocan ERROR 1064 → HTTP 500 ("C++", "(ondas", "@").
// La defensa NO es una lista negra de operadores, sino una LISTA
// BLANCA: tras normalizar sólo sobreviven \p{L} y \p{N}, de modo
// que la cadena que llega a AGAINST() sólo puede ser una sucesión de
// '+termino*' y de grupos '+(termino* termino*)' — ver IAREPO_FT_SAFE,
// que se vuelve a comprobar antes de devolverla (cinturón + tirantes).
//
// ── Estrategia: híbrido MATCH OR LIKE ─────────────────────────
// Producción es MariaDB (las migraciones usan ADD COLUMN IF NOT
// EXISTS, sintaxis que MySQL 8 rechaza) ⇒ no hay parser NGRAM y
// innodb_ft_min_token_size (3) es global e intocable en hosting
// compartido. Por eso cada término se busca por DOS vías:
//   1. fulltext  '+termino*'  → rápido, usa idx_search, da prefijo.
//   2. LIKE '%termino%' sobre título/descripción/topic/área/fuente/
//      autor + los tags (EXISTS) → alcanza columnas que el índice no
//      cubre ('PhET' vive sólo en source_name).
//
// TRAMPA CONOCIDA (no tocar sin leerla): un stopword o un token de
// menos de 3 caracteres emitido con '+' ANULA la consulta fulltext
// entera ('+the* +water*' → 0 filas). Por eso los stopwords se
// descartan del brazo fulltext y los términos cortos se atienden
// SÓLO fuera de él. Nunca se emite '+<stopword>*' ni '+<2 chars>*'.
//
// Y por eso mismo el brazo fulltext lleva pegados con AND los
// términos cortos: si el OR fuese pelado, 'pH escala' devolvería
// todo lo que contenga "escala" (el fulltext no puede exigir 'ph')
// y multi-palabra dejaría de ser AND.
//
// ── Términos CORTOS: frontera de palabra, no subcadena ────────
// Un token de <3 caracteres no puede ir por fulltext, así que el
// único filtro que le queda es el segundo brazo. Con LIKE '%c%' eso
// era un desastre medido sobre el catálogo real de 546 filas:
//
//   consulta      total con LIKE '%t%'   total con frontera de palabra
//   'C++' → 'c'          546  (100 %)                3
//   'pH'                 391  ( 72 %)                4
//   'IA'                 536  ( 98 %)                0
//   'a'                  546  (100 %)               82
//
// El recurso correcto salía primero (lo coloca el ranking), pero el
// contador "N recursos" y las páginas 2..55 eran ruido puro. Por eso
// un término corto NO se busca como subcadena sino como PALABRA:
//
//   (?<![\p{L}\p{N}])ph(?![\p{L}\p{N}])
//
// que es exactamente "donde cortaría iarepo_normalize()". Así 'ph'
// casa "Escala de pH", "pH-metro" y "(pH)" pero NO "Photosynthesis".
// Se comprobó empíricamente en MariaDB 11.8 que REGEXP trata ñ/á como
// carácter de palabra (PCRE con propiedades Unicode): 'ni' NO casa
// "niños" y 'es' NO casa "español". El OR con
// CONCAT(' ', haystack, ' ') LIKE '% ph %' es el plan B: pasa por la
// collation, que sí es insensible a acentos, de modo que una palabra
// suelta y separada por espacios se encuentra pase lo que pase.
//
// Los términos LARGOS siguen yendo por LIKE '%termino%' a propósito:
// ahí la precisión la pone el brazo fulltext, y el LIKE aporta el
// prefijo y las columnas no indexadas ('matem' → "Matemáticas",
// 'matematicas' → "Matemáticas": REGEXP no es insensible a acentos y
// LIKE sí, porque va por la collation).
//
// ── Sinónimos ES↔EN: cada término es un GRUPO ─────────────────
// El catálogo es bilingüe y el campo `lang` NO es de fiar (entre los
// títulos marcados 'es' los términos más frecuentes son 'mechanics',
// 'electromagnetism', 'waves', 'quantum'), así que la única salida es
// expandir la CONSULTA. shared/search_synonyms.php trae los grupos.
//
// Forma que produce (medida contra MariaDB 11.8.8, ver más abajo):
//   'ondas'        → +(onda* wave*)
//   'ondas sonido' → +(onda* wave*) +(sonido* sound* acoustic* acustica*)
//
// Lo que se gana, contado sobre el catálogo REAL de producción (546
// recursos visibles, traídos por la API pública de sólo lectura):
//
//   biologia 0→37 · matematicas 2→117 · quimica 1→39 · espacio 1→23
//   fisica 18→321 · juego 3→22 · ondas 11→46 · luz 3→13 · celula 3→13
//   interactivo 93→351 · laboratorio 15→36 · energia 8→26
//   Total de 24 consultas típicas: 320 → 1951 resultados.
//
// Buscar "biología" devolvía CERO en un catálogo con 37 recursos de
// biología, porque están catalogados como 'Biology'.
//
// El AND entre términos se MANTIENE y el OR queda DENTRO del grupo.
// Verificado empíricamente contra el contenedor de integración (no es
// una suposición sobre la documentación de MariaDB):
//
//   +onda* +wave*                     → []          (AND estricto)
//   +(onda* wave*)                    → [1, 2, 5]   (OR dentro)
//   +(onda* wave*) +(sonido* sound*)  → [1, 2, 5]   (AND entre grupos)
//   +(onda* wave*) +(zzz*)            → []          (un grupo sin
//                                        coincidencias anula la consulta)
//
// TRAMPA MEDIDA: un token de MENOS de 3 caracteres dentro del grupo NO
// se ignora, se busca por prefijo y arrastra el catálogo —
// '+(onda* ph*)' devolvió también "Photosynthesis", que no tiene nada
// que ver con "onda". Por eso iarepo_synonyms() RECHAZA al cargar
// cualquier miembro de menos de IAREPO_FT_MIN caracteres: un sinónimo
// corto no puede colarse ni por fulltext ni por LIKE desnudo. El único
// término corto admisible sigue siendo el que escribió el usuario, y
// sigue yendo por frontera de palabra.
//
// Un stopword dentro del grupo no lo anula ('+(onda* the*)' devolvió
// lo mismo que '+onda*'), pero un grupo que fuese SÓLO stopwords sí
// ('+(the*)' → []): por eso tampoco se emiten.
//
// El índice fulltext usa la collation (utf8mb4_unicode_ci) y por tanto
// es INSENSIBLE A ACENTOS — medido: '+fisica*' y '+física*' devuelven
// la misma fila. Como iarepo_normalize() NO quita tildes ('física'
// sigue siendo 'física'), el diccionario se consulta plegando los
// acentos (iarepo_fold) y el miembro del diccionario que sólo difiere
// en la tilde se descarta por redundante: 'física' → +(física* physic*),
// no +(física* fisica* physic*).
//
// ── EL SINÓNIMO NO SE BUSCA COMO SUBCADENA ────────────────────
// El brazo LIKE hace lo mismo que el fulltext (OR dentro del grupo, AND
// entre grupos) PERO un sinónimo filtra por PRINCIPIO DE PALABRA, no por
// subcadena. Es la decisión que salva la precisión y está medida sobre el
// catálogo real: el sinónimo 'ion' (grupo ion/iones/ions) como
// LIKE '%ion%' casaba 439 de 546 recursos — el 80 % del catálogo — porque
// "Simulations", "Motion" y "Combinación" llevan "ion" dentro. Por
// principio de palabra casa 0 falsos. Lo mismo con 'arn' (30 → 0, era
// "Learn") o 'art' (37 → 6, era "Partícula" y "Marte").
// Y no cuesta recall: 'physic' 307→307, 'math' 116→116, 'biology' 37→37,
// 'wave' 40→40. Sobre las 24 consultas típicas, el infijo sólo añadía 26
// filas de 1977 (1,3 %), y al mirarlas una a una eran "Refraction" para
// quien busca "fracciones" y "Regex101" para quien busca "luz".
// El término que escribió el USUARIO sí sigue yendo por subcadena: 'matem'
// → "Matemáticas" es una prestación deliberada y probada. Ver
// iarepo_prefix_regexp() para la tabla completa.
//
// ── Relevancia ────────────────────────────────────────────────
//   score = LEAST(MATCH*2, 24)  (sólo si hay brazo fulltext)
//         + 30 frase normalizada en el título
//         + 25 frase TAL CUAL, con puntuación, en el título ("C++")
//         + 10 por término EXACTO en el título
//         + 12 por término EXACTO como palabra completa del título
//              (sin esto "pH" puntúa igual en "Photosynthesis")
//         +  8 por término EXACTO en cualquier columna  ← sólo si el
//              término se expandió: es lo que hace que quien busca
//              "ondas" vea antes lo que dice "ondas" que lo que dice
//              "waves", aunque el sinónimo esté en el título.
//         +  5 por SINÓNIMO en el título (la mitad que el exacto)
//         + LEAST(view_count/200, 3) desempate suave por popularidad
//
// La invariante que se pedía es "a igualdad de posición gana el
// exacto", y sale por construcción: en el título 10+12+8=30 contra 5,
// y fuera del título 8 contra 0. El sumando fulltext NO puede romperla
// porque el MATCH se calcula sobre el grupo entero y da lo mismo a
// quien casa por el término que a quien casa por el sinónimo.
//
// El sumando fulltext era MATCH*2, que NO está acotado: crece con la
// frecuencia del término. Distribución medida sobre las 546 filas del
// catálogo real (295 consultas, 2.668 filas puntuadas):
//
//   mediana 6,0 · p90 13,5 · p99 23,7 · MÁXIMO 47,5
//   el 0,4 % de las filas pasaba de 30
//
// Es decir: repetir la palabra en la descripción se comía el bono de
// 30 de "la frase entera está en el título". Ahora va con tope:
//
//   LEAST(MATCH*2, 24)
//
// El 24 se eligió BARRIENDO topes sobre ese mismo corpus y mirando
// cuántas consultas cambiaban de primer resultado:
//
//   tope   filas recortadas   consultas que cambian de 1º
//      8        30,4 %                18 / 295
//     12        12,3 %                 5 / 295
//     18         4,9 %                 3 / 295
//     24         0,9 %                 0 / 295   ← elegido
//     25         0,6 %                 0 / 295   rompe el bono de 25
//     30         0,4 %                 0 / 295   rompe el bono de 30
//
// 24 es el MAYOR entero que sigue por debajo de los dos bonos de
// FRASE (25 y 30), que son los que el sumando sin acotar adelantaba;
// y a la vez el más barato: recorta sólo la cola patológica (0,9 %) y
// no mueve ni un primer resultado. Bajarlo a 12 no protege ninguna
// invariante adicional y sí desordena 5 consultas de cada 295.
// NOTA: el sumando SÍ puede superar los bonos por término (+10, +12).
// Es deliberado: un MATCH alto es evidencia real, y lo que no puede
// hacer es ganarle a que la FRASE ENTERA esté en el título.
//
// ── Contrato de iarepo_build_search(string $raw): array ───────
//   'mode'         string  'none' | 'like' | 'hybrid'  — y NINGÚN otro.
//                          NO existe el modo 'fulltext': el segundo brazo
//                          está SIEMPRE presente (es el único que alcanza
//                          source_name, los tags y los términos cortos),
//                          así que en cuanto hay búsqueda el modo es
//                          'like' (sin brazo fulltext) o 'hybrid' (con él).
//                          'none' = no hay búsqueda: no añadas nada al WHERE.
//   'where'        string  SQL con placeholders posicionales '?'. Vacío si mode='none'.
//                          El llamador DEBE aliasar la tabla como `r`.
//   'params'       array   valores de 'where', EN ORDEN.
//   'score'        ?string expresión SQL de relevancia (o null si mode='none').
//   'score_params' array   valores de 'score', EN ORDEN.
//   'terms'        array   tokens normalizados (para resaltar en la UI).
//                          NO incluye los sinónimos: es lo que el usuario
//                          escribió y lo único que el frontend resalta hoy.
//   'debug'        array   ['ft'=>string, 'like'=>array, 'short'=>array,
//                           'dropped'=>array, 'groups'=>array]
//                          'like'   = un término EXACTO por grupo (lo que el
//                                     usuario escribió, ya despluralizado).
//                          'short'  = los términos que filtran por FRONTERA DE
//                                     PALABRA (los invisibles para el fulltext).
//                          'groups' = la expansión completa, alineada con
//                                     'like': groups[i][0] === like[i].
//
// Invariantes garantizadas (las verifica tests/unit/search_test.php):
//   substr_count(where, '?') === count(params)
//   substr_count(score, '?') === count(score_params)
//   debug['ft'] === '' o casa la regex de la lista blanca
//   todo parámetro es la cadena fulltext, un LIKE con comodines
//     escapados, o un patrón que casa iarepo_is_word_regexp()
//   count(groups[i]) <= IAREPO_MAX_SYNONYMS y groups[i][0] === like[i]
//   ningún miembro de un grupo mide menos de IAREPO_FT_MIN caracteres
//     salvo que sea el propio término del usuario
//
// ORDEN DE PARÁMETROS (importante, PDO va sin emulación):
//   la expresión 'score' va en el SELECT y el SELECT precede al
//   WHERE ⇒ score_params SIEMPRE antes que params. La consulta de
//   COUNT no lleva score y por tanto tampoco score_params.
// ================================================================

// ── Sintonía ──────────────────────────────────────────────────
const IAREPO_FT_MIN    = 3;    // = innodb_ft_min_token_size (global, no configurable en Hostinger)
const IAREPO_MAX_TERMS = 8;    // techo de términos: acota el coste de la consulta
const IAREPO_MAX_RAW   = 120;  // techo de caracteres del input
const IAREPO_MAX_TOKEN = 40;   // techo de caracteres por token

// Tope del sumando fulltext del score: LEAST(MATCH*2, IAREPO_FT_SCORE_CAP).
// 24 es el MAYOR entero que no llega a los bonos de FRASE (25 la frase
// cruda con puntuación, 30 la frase normalizada en el título), que son los
// que el sumando sin acotar se comía. Elegido midiendo, no a ojo: ver la
// tabla de la cabecera.
const IAREPO_FT_SCORE_CAP = 24;

// Tope de miembros por grupo de sinónimos, INCLUIDO el término exacto.
//
// Por qué hace falta: el peor caso es IAREPO_MAX_TERMS grupos por este tope,
// y cada miembro cuesta en el brazo LIKE un CONCAT_WS de 6 columnas más un
// EXISTS sobre resource_tags. 8 × 6 = 48 ramas es el techo duro.
//
// Por qué 6: medido sobre los 631 términos de una sola palabra del
// diccionario, DESPUÉS de despluralizar, plegar acentos y podar por prefijo
// (ver iarepo_expand), el grupo más grande tiene 5 miembros y la media es
// 2,32 (el 80 % son de 2 o 3). El 6 deja un hueco de margen para que añadir
// un sinónimo no obligue a tocar código, y pone techo a un grupo mal
// mantenido. Que el tope NUNCA recorte el diccionario de hoy lo vigila
// test_el_tope_de_expansion_nunca_recorta_el_diccionario().
//
// Coste real medido contra el catálogo de producción (546 recursos,
// consulta completa con score y ORDER BY, media de 20 ejecuciones):
//
//   consulta                        grupos  ramas  params    antes   ahora
//   'fisica'                             1      3      12   0,82 ms 1,96 ms
//   'ondas sonido'                       2     10      29   1,06 ms 2,53 ms
//   8 términos con los grupos MÁS
//   grandes del diccionario              8     54     135   1,01 ms 4,24 ms
//
// 4,2 ms en el peor caso que el tokenizador permite construir, sobre el
// catálogo entero y sin filtros que lo acoten. Se paga.
const IAREPO_MAX_SYNONYMS = 6;

// Bonos de relevancia. Los tres primeros son los de siempre; los dos
// últimos existen para que el EXACTO gane siempre al SINÓNIMO en igualdad
// de posición (10+12+8 = 30 contra 5 en el título, 8 contra 0 fuera de él).
const IAREPO_SCORE_TITLE      = 10;
const IAREPO_SCORE_TITLE_WORD = 12;
const IAREPO_SCORE_EXACT_ANY  = 8;
const IAREPO_SCORE_SYN_TITLE  = 5;

// Stopwords de InnoDB (verificadas en INNODB_FT_DEFAULT_STOPWORD) + castellano.
// Un término de esta lista NUNCA puede emitirse con '+': anularía la consulta entera.
const IAREPO_STOP = [
    'a', 'about', 'al', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'com', 'como', 'con',
    'de', 'del', 'el', 'en', 'es', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la',
    'las', 'los', 'of', 'on', 'or', 'para', 'por', 'que', 'se', 'su', 'that', 'the',
    'this', 'to', 'un', 'una', 'und', 'was', 'what', 'when', 'where', 'who', 'will',
    'with', 'www', 'y',
];

// ── Piezas SQL ────────────────────────────────────────────────
// OJO: el llamador DEBE aliasar la tabla principal como `r`.

// Sólo columnas de `resources`: así es imposible un "Illegal mix of collations".
const IAREPO_HAYSTACK = "CONCAT_WS(' ', r.title, r.description, r.topic_tag, r.subject_area, r.source_name, r.author_display_name)";

// Los tags viven en otra tabla: EXISTS (usa la PK (resource_id, tag)) en vez de
// GROUP_CONCAT — más barato, sin límite de group_concat_max_len y sin mezclar collations.
const IAREPO_TAG_MATCH = "EXISTS (SELECT 1 FROM resource_tags rts WHERE rts.resource_id = r.id AND rts.tag LIKE ? ESCAPE '!')";

// Condición de UN término por la vía LIKE (subcadena). Consume 2 parámetros.
const IAREPO_TERM_LIKE = '(' . IAREPO_HAYSTACK . " LIKE ? ESCAPE '!' OR " . IAREPO_TAG_MATCH . ')';

// ── Frontera de palabra (términos cortos) ─────────────────────
// Clase de "carácter de palabra": EXACTAMENTE la lista blanca de
// iarepo_normalize(), para que el corte del patrón y el del tokenizador
// no puedan discrepar nunca.
const IAREPO_RX_WORD = '[\p{L}\p{N}]';

// Los patrones SIEMPRE van como parámetro ligado: MariaDB los recibe tal
// cual (no son literales SQL, así que no dependen de NO_BACKSLASH_ESCAPES)
// y jamás se construyen con la frase cruda, sólo con tokens normalizados
// (ver iarepo_word_regexp: metacaracteres y backtracking imposibles).
const IAREPO_HAY_WORD   = IAREPO_HAYSTACK . ' REGEXP ?';
const IAREPO_TAG_WORD   = 'EXISTS (SELECT 1 FROM resource_tags rts WHERE rts.resource_id = r.id AND rts.tag REGEXP ?)';
// Plan B por collation (insensible a acentos, que REGEXP no es).
const IAREPO_HAY_SPACED = "CONCAT(' ', " . IAREPO_HAYSTACK . ", ' ') LIKE ? ESCAPE '!'";

// Condición de UN término corto. Consume 3 parámetros (haystack, tag, spaced).
// La MISMA forma SQL sirve para los sinónimos (ver iarepo_syn_condition):
// lo único que cambia son los parámetros, porque un sinónimo filtra por
// PRINCIPIO de palabra y un término corto por palabra completa.
const IAREPO_TERM_WORD = '(' . IAREPO_HAY_WORD . ' OR ' . IAREPO_TAG_WORD . ' OR ' . IAREPO_HAY_SPACED . ')';

// Brazo fulltext. Consume 1 parámetro.
const IAREPO_MATCH = 'MATCH(r.title, r.description, r.topic_tag) AGAINST(? IN BOOLEAN MODE)';

// Lista blanca: forma ÚNICA admisible de la cadena que entra en AGAINST().
//
// Un "átomo" es un término obligatorio con comodín ('+onda*') o un GRUPO
// obligatorio de alternativas ('+(onda* wave*)'). Se sigue construyendo por
// lista blanca, no por lista negra: los únicos caracteres que pueden
// aparecer son \p{L}, \p{N}, '+', '*', '(', ')' y el espacio separador, y
// los paréntesis sólo en la posición exacta que abre y cierra un grupo. Un
// operador del parser fulltext (- ~ < > @ " ) suelto sigue siendo imposible.
const IAREPO_FT_TERM  = '[\p{L}\p{N}]+\*';
const IAREPO_FT_ATOM  = '\+(?:' . IAREPO_FT_TERM . '|\(' . IAREPO_FT_TERM . '(?: ' . IAREPO_FT_TERM . ')*\))';
const IAREPO_FT_SAFE  = '/^' . IAREPO_FT_ATOM . '(?: ' . IAREPO_FT_ATOM . ')*$/u';

/**
 * Normaliza el input: recorta, minúsculas y deja SÓLO letras y dígitos.
 * Todo lo demás (operadores fulltext, comodines de LIKE, emojis, control)
 * se convierte en separador. Es la lista blanca de la que depende todo.
 */
function iarepo_normalize(string $raw): string
{
    // UTF-8 inválido rompería preg_replace/u (devuelve null) → se limpia antes.
    if (!mb_check_encoding($raw, 'UTF-8'))
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');

    $s = mb_substr(trim($raw), 0, IAREPO_MAX_RAW, 'UTF-8');
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';

    return trim($s);
}

/**
 * Parte el input normalizado en tokens únicos (máx. IAREPO_MAX_TERMS),
 * preservando el orden de aparición.
 *
 * @return string[]
 */
function iarepo_tokenize(string $raw): array
{
    $n = iarepo_normalize($raw);
    if ($n === '')
        return [];

    $out = [];
    foreach (explode(' ', $n) as $t) {
        if ($t === '')
            continue;
        $t = mb_substr($t, 0, IAREPO_MAX_TOKEN, 'UTF-8');
        $out[$t] = true; // dedup preservando orden
        if (count($out) >= IAREPO_MAX_TERMS)
            break;
    }

    // array_keys() devuelve INT en las claves que parecen enteros canónicos
    // ('2024' → 2024), así que sin strval la API emitiría {"terms":[2024,"examen"]}
    // y cualquier t.toLowerCase() del frontend reventaría con TypeError.
    return array_map('strval', array_keys($out));
}

/**
 * Despluralización conservadora, SÓLO del lado de la consulta (nunca del dato).
 * Al término resultante siempre se le añade '*' en el brazo fulltext y se usa
 * como subcadena en el brazo LIKE, así que quedarse corto sólo ensancha la
 * búsqueda; nunca pierde resultados.
 *
 *   ondas → onda   waves → wave   cycles → cycl   valores → valor
 *   class → class  gas → gas      los → los       gases → gase
 */
function iarepo_stem(string $t): string
{
    $l = mb_strlen($t, 'UTF-8');

    if ($l >= 6 && mb_substr($t, -2, null, 'UTF-8') === 'es')
        return mb_substr($t, 0, $l - 2, 'UTF-8');   // cycles → cycl, valores → valor

    if ($l >= 4 && mb_substr($t, -1, null, 'UTF-8') === 's' && mb_substr($t, -2, null, 'UTF-8') !== 'ss')
        return mb_substr($t, 0, $l - 1, 'UTF-8');   // ondas → onda, waves → wave

    return $t;
}

/**
 * Pliega los acentos. SÓLO se usa para consultar el diccionario de
 * sinónimos y para deduplicar términos; jamás para construir el SQL.
 *
 * Por qué hace falta: iarepo_normalize() conserva las tildes a propósito
 * ('física' → 'física', porque \p{L} las incluye), pero las claves del
 * diccionario están sin tilde. Sin plegar, "física", "biología",
 * "matemáticas" o "química" —las consultas que MÁS ganan con esto— no
 * encontrarían su grupo. No hace falta plegar para BUSCAR: la collation
 * utf8mb4_unicode_ci ya es insensible a acentos tanto en LIKE como en el
 * índice fulltext (medido: '+fisica*' y '+física*' dan la misma fila).
 *
 * La 'ñ' NO se pliega: en castellano es una letra distinta, ninguna clave
 * del diccionario la usa, y plegarla convertiría 'año' en 'ano'.
 */
function iarepo_fold(string $s): string
{
    static $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ç' => 'c',
    ];

    if (!mb_check_encoding($s, 'UTF-8'))
        return $s; // basura binaria: no la toquemos, no casará con nada del diccionario

    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}

/**
 * Grupo de equivalentes de $term (él PRIMERO), o [$term] si no está en el
 * diccionario. Es la única puerta de entrada a shared/search_synonyms.php.
 *
 * El diccionario se carga UNA vez por proceso y se indexa término→grupo.
 * `require` de un fichero que sólo hace `return [...]`: sin efectos
 * secundarios, sin salida, sin arrastrar nada — la regla de este archivo.
 * Si el fichero faltase o no devolviese un array, se degrada a
 * "sin sinónimos" en vez de reventar: el buscador seguiría siendo el de
 * antes, que es exactamente el peor caso aceptable.
 *
 * Qué se RECHAZA al cargar (y por qué):
 *   · miembros con espacios ('tabla periodica'): la expansión ocurre token
 *     a token, así que jamás podrían casar, y colados dentro de '+(...)'
 *     romperían la lista blanca de la cadena fulltext.
 *   · miembros de menos de IAREPO_FT_MIN caracteres: medido, '+(onda* ph*)'
 *     arrastra "Photosynthesis". Un sinónimo corto destruiría la precisión
 *     que costó conseguir.
 *   · stopwords: un grupo que sólo tuviese stopwords anularía la consulta.
 *   · grupos que se quedan con menos de 2 miembros: no expanden nada.
 *
 * @return string[]
 */
function iarepo_synonyms(string $term): array
{
    static $index = null;

    if ($index === null) {
        $index = [];
        $file  = __DIR__ . '/search_synonyms.php';
        $raw   = is_file($file) ? require $file : [];

        foreach (is_array($raw) ? $raw : [] as $group) {
            if (!is_array($group))
                continue;

            $clean = [];
            foreach ($group as $t) {
                if (!is_string($t))
                    continue;
                $t = iarepo_fold($t);
                if (!preg_match('/^[\p{L}\p{N}]+$/u', $t))
                    continue;
                if (mb_strlen($t, 'UTF-8') < IAREPO_FT_MIN)
                    continue;
                if (in_array($t, IAREPO_STOP, true))
                    continue;
                $clean[$t] = true;
            }

            if (count($clean) < 2)
                continue;

            $members = array_keys($clean);
            foreach ($members as $m)
                $index[$m] ??= $members; // sin ambigüedad: el primer grupo que lo declare manda
        }
    }

    $key = iarepo_fold($term);
    if (!isset($index[$key]))
        return [$term];

    // El término va SIEMPRE el primero y TAL CUAL lo escribió el usuario
    // (con su tilde): el resto del motor distingue "exacto" de "sinónimo"
    // por esa posición.
    $out = [$term];
    foreach ($index[$key] as $m)
        if ($m !== $key)
            $out[] = $m;

    return $out;
}

/**
 * ¿Puede $t exigirse por el índice fulltext?
 *
 * Reúne las tres razones por las que un término NO puede llevar '+':
 * es más corto que innodb_ft_min_token_size, no es un token único
 * (la frase de respaldo 'de la' lleva espacio) o es un stopword —
 * y cualquiera de las tres anularía la consulta entera o la ensuciaría.
 */
function iarepo_ft_indexable(string $t): bool
{
    return mb_strlen($t, 'UTF-8') >= IAREPO_FT_MIN
        && preg_match('/^[\p{L}\p{N}]+$/u', $t) === 1
        && !in_array($t, IAREPO_STOP, true);
}

/**
 * Grupo FINAL de un token: su despluralizado el primero (el término
 * EXACTO) seguido de los sinónimos ya despluralizados, deduplicados y
 * podados. Nunca más de IAREPO_MAX_SYNONYMS miembros.
 *
 * Tres reglas, en este orden:
 *  1. Se busca el grupo por el TOKEN y, si no está, por su despluralizado.
 *     Hacen falta las dos: 'matematicas' sólo aparece en el diccionario en
 *     singular ('matematica') y 'gases' sólo en plural (su stem es 'gase',
 *     que no es ninguna clave).
 *  2. Se deduplica plegando acentos: el miembro del diccionario que sólo
 *     difiere en la tilde ya lo cubre el término del usuario, porque tanto
 *     LIKE como el índice fulltext van por la collation.
 *  3. PODA POR PREFIJO: como todo miembro se emite con '*' en fulltext y
 *     por PRINCIPIO DE PALABRA en el brazo LIKE, un miembro que empieza
 *     por otro ya emitido casa siempre un subconjunto y es redundante.
 *     'math' cubre 'mathematic'; 'magnet' cubre 'magnetismo', 'magnetism'
 *     y 'magnetic'. Los candidatos se ordenan de MÁS CORTO a más largo
 *     antes de podar: si no, el resultado dependería del orden en que
 *     estén escritos en el diccionario ('magnetismo' antes que 'magnet'
 *     dejaba los dos). Es lo que baja los grupos de 3,51 miembros de
 *     media a 2,32, y es exacto: no pierde ni una fila.
 *     El término del usuario NUNCA se poda, aunque un sinónimo lo cubra:
 *     el score lo necesita para distinguir exacto de sinónimo.
 *
 * @return string[] con al menos un elemento; [0] es el término exacto
 */
function iarepo_expand(string $token, string $stem): array
{
    $group = iarepo_synonyms($token);
    if (count($group) < 2 && $stem !== $token)
        $group = iarepo_synonyms($stem);

    // Candidatos: despluralizados, sin el que ya representa el término
    // del usuario y sin repetidos.
    $seen  = [iarepo_fold($stem) => true];
    $cands = [];
    foreach ($group as $syn) {
        $s = iarepo_stem($syn);
        $f = iarepo_fold($s);
        if ($f === '' || isset($seen[$f]))
            continue;
        $seen[$f] = true;
        $cands[]  = $s;
    }

    // De más corto a más largo. El sort de PHP 8 es estable, así que a
    // igual longitud manda el orden del diccionario y la salida es
    // determinista (de eso dependen los tests y el plan de la consulta).
    usort($cands, static fn($a, $b) => mb_strlen($a, 'UTF-8') <=> mb_strlen($b, 'UTF-8'));

    $out = [$stem];
    foreach ($cands as $s) {
        if (count($out) >= IAREPO_MAX_SYNONYMS)
            break;

        $f = iarepo_fold($s);
        foreach ($out as $kept) {
            if (str_starts_with($f, iarepo_fold($kept)))
                continue 2; // ya cubierto por prefijo
        }

        $out[] = $s;
    }

    return $out;
}

/**
 * Escapa los comodines de LIKE. Se usa ESCAPE '!' (y no la barra invertida)
 * para no depender del sql_mode NO_BACKSLASH_ESCAPES del servidor.
 */
function iarepo_like_escape(string $s): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
}

/**
 * Patrón REGEXP que exige FRONTERA DE PALABRA alrededor de $token.
 *
 * SEGURIDAD: sólo admite tokens YA NORMALIZADOS (nada más que \p{L} y
 * \p{N}). Con cualquier otra cosa devuelve '' y el llamador cae al LIKE.
 * Es lo que hace imposible que un metacarácter del usuario ('(', '*',
 * '\', '{2,}'...) llegue al motor de expresiones regulares de MariaDB:
 * ni sintaxis inválida (error 1139 → 500) ni backtracking catastrófico.
 * La FRASE CRUDA con puntuación jamás pasa por aquí: va por LIKE con
 * sus comodines escapados (ver iarepo_raw_phrase).
 *
 *   'ph' → '(?<![\p{L}\p{N}])ph(?![\p{L}\p{N}])'
 *   'C+' → ''  (no está normalizado)
 */
function iarepo_word_regexp(string $token): string
{
    if ($token === '' || !preg_match('/^[\p{L}\p{N}]+$/u', $token))
        return '';

    return '(?<!' . IAREPO_RX_WORD . ')' . $token . '(?!' . IAREPO_RX_WORD . ')';
}

/**
 * ¿Es $p un patrón salido de iarepo_word_regexp()?
 *
 * Cinturón + tirantes, igual que IAREPO_FT_SAFE para la cadena fulltext:
 * se vuelve a comprobar antes de emitirlo y lo usan los tests como lista
 * blanca de los parámetros que NO son LIKE.
 */
function iarepo_is_word_regexp(string $p): bool
{
    $pre  = '(?<!' . IAREPO_RX_WORD . ')';
    $post = '(?!' . IAREPO_RX_WORD . ')';

    if (!str_starts_with($p, $pre) || !str_ends_with($p, $post))
        return false;

    $token = substr($p, strlen($pre), strlen($p) - strlen($pre) - strlen($post));

    return $token !== '' && (bool) preg_match('/^[\p{L}\p{N}]+$/u', $token);
}

/**
 * Patrón REGEXP que exige PRINCIPIO DE PALABRA (sin cerrar por la derecha).
 * Es lo que filtra a los SINÓNIMOS, y la diferencia con la subcadena está
 * medida sobre el catálogo real de producción (546 recursos visibles):
 *
 *   sinónimo   LIKE '%x%'   principio de palabra   qué era el ruido
 *   'ion'         439 (80 %)          0            "Simulations", "Motion"
 *   'arn'          30                 0            "Learn"
 *   'iones'        42                 0            "Simulations"
 *   'art'          37                 6            "Partícula", "Marte"
 *   'heat'         44                 5            source_name de terceros
 *   'len'          24                10            "lenguaje", "excelente"
 *   'math'        116               116            ← intacto (subject_area)
 *   'physic'      307               307            ← intacto
 *   'biology'      37                37            ← intacto
 *   'wave'         40                40            ← intacto
 *
 * O sea: quita TODO el ruido de infijo y no cuesta ni una fila de las que
 * justificaban la función. Buscar "iones" con subcadena devolvía el 80 %
 * del catálogo; con principio de palabra devuelve lo que dice "iones".
 *
 * Por qué el término del USUARIO sigue yendo por subcadena y el sinónimo
 * no: 'matem' → "Matemáticas" es una prestación deliberada y probada, y el
 * usuario la escribió. Un sinónimo que él no escribió no gana nada
 * casando por dentro de una palabra: nadie que busque "iones" quiere
 * "Simulations". Además así el brazo LIKE dice lo MISMO que el brazo
 * fulltext, que ya casaba por prefijo ('+ion*').
 *
 * Misma lista blanca que iarepo_word_regexp(): sólo tokens normalizados.
 */
function iarepo_prefix_regexp(string $token): string
{
    if ($token === '' || !preg_match('/^[\p{L}\p{N}]+$/u', $token))
        return '';

    return '(?<!' . IAREPO_RX_WORD . ')' . $token;
}

/** ¿Es $p un patrón salido de iarepo_prefix_regexp()? (cinturón + tirantes) */
function iarepo_is_prefix_regexp(string $p): bool
{
    $pre = '(?<!' . IAREPO_RX_WORD . ')';

    if (!str_starts_with($p, $pre))
        return false;

    $token = substr($p, strlen($pre));

    return $token !== '' && (bool) preg_match('/^[\p{L}\p{N}]+$/u', $token);
}

/**
 * Condición SQL de UN SINÓNIMO, con sus parámetros EN ORDEN.
 *
 * Comparte la forma SQL de IAREPO_TERM_WORD (3 parámetros) y sólo cambia
 * los patrones: aquí no hay frontera por la derecha, así que 'math' alcanza
 * "Mathematics" y 'wave' alcanza "waves", pero 'ion' ya no alcanza
 * "Simulations". El tercer parámetro es el plan B por collation: REGEXP no
 * es insensible a acentos y LIKE sí, de modo que el sinónimo 'fraccion'
 * sigue alcanzando "Fracción".
 *
 * Devuelve [null, []] si el sinónimo no supera la lista blanca. Al
 * contrario que iarepo_term_condition(), NO cae a la subcadena: un sinónimo
 * es recall opcional, y ensanchar sin control es justo lo que no puede
 * pasar. Ante la duda, el sinónimo se descarta.
 *
 * @return array{0:?string,1:string[]}  [sql|null, params]
 */
function iarepo_syn_condition(string $syn): array
{
    $rx = iarepo_prefix_regexp($syn);
    if ($rx === '' || !iarepo_is_prefix_regexp($rx))
        return [null, []];

    return [IAREPO_TERM_WORD, [$rx, $rx, '% ' . iarepo_like_escape($syn) . '%']];
}

/**
 * Frase del usuario con la puntuación INTACTA (sólo recorte, minúsculas y
 * espacios colapsados). Se usa EXCLUSIVAMENTE para puntuar, nunca para
 * filtrar: es lo único que permite que "C++" gane a cualquier título que
 * simplemente contenga una "c". Va siempre como parámetro escapado de LIKE.
 */
function iarepo_raw_phrase(string $raw): string
{
    if (!mb_check_encoding($raw, 'UTF-8'))
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');

    $s = mb_substr(trim($raw), 0, IAREPO_MAX_RAW, 'UTF-8');
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';

    return trim($s);
}

/**
 * Condición SQL de UN término del filtro, con sus parámetros EN ORDEN.
 *
 *   $word = false → subcadena: LIKE '%termino%' (prefijos + acentos por
 *                   collation). Es lo que necesitan los términos que YA
 *                   van por el índice fulltext.
 *   $word = true  → palabra completa (frontera). Es lo que necesitan los
 *                   términos cortos, que no tienen brazo fulltext y con
 *                   subcadena devolvían el catálogo entero.
 *
 * Si el token no supera la lista blanca del patrón (imposible con un
 * token normalizado, pero es la red de seguridad) cae a la subcadena:
 * peor precisión, nunca un error.
 *
 * @return array{0:string,1:string[]}  [sql, params]
 */
function iarepo_term_condition(string $term, bool $word): array
{
    if ($word) {
        $rx = iarepo_word_regexp($term);
        if ($rx !== '' && iarepo_is_word_regexp($rx))
            return [IAREPO_TERM_WORD, [$rx, $rx, '% ' . iarepo_like_escape($term) . ' %']];
    }

    $needle = '%' . iarepo_like_escape($term) . '%';

    return [IAREPO_TERM_LIKE, [$needle, $needle]];
}

/**
 * Construye el WHERE y la expresión de relevancia para una búsqueda.
 * Ningún input puede producir SQL inválido: ver cabecera del archivo.
 *
 * @return array{mode:string,where:string,params:array,score:?string,score_params:array,terms:array,debug:array}
 */
function iarepo_build_search(string $raw): array
{
    $none = [
        'mode'         => 'none',
        'where'        => '',
        'params'       => [],
        'score'        => null,
        'score_params' => [],
        'terms'        => [],
        'debug'        => ['ft' => '', 'like' => [], 'short' => [], 'dropped' => [], 'groups' => []],
    ];

    $phrase = iarepo_normalize($raw);
    if ($phrase === '')
        return $none; // vacío, sólo espacios o sólo símbolos/emojis → sin filtro de búsqueda

    // ── Clasificación de términos ─────────────────────────────
    // Cada token del usuario se convierte en un GRUPO: [exacto, sinónimo...].
    // Un token sin sinónimos da un grupo de un solo miembro y a partir de ahí
    // todo el motor se comporta EXACTAMENTE igual que antes de los sinónimos.
    $groups  = [];  // string[][] — groups[i][0] es siempre el término exacto
    $dropped = [];  // stopwords: fuera del AND, jamás con '+'
    $seen    = [];

    foreach (iarepo_tokenize($raw) as $token) {
        $stem = iarepo_stem($token);

        if (in_array($token, IAREPO_STOP, true) || in_array($stem, IAREPO_STOP, true)) {
            $dropped[] = $token;
            continue;
        }
        // Se deduplica plegando acentos y por CUALQUIER miembro del grupo:
        // 'ondas onda' es un término, y 'ondas waves' también (serían dos
        // grupos idénticos unidos por AND).
        if (isset($seen[iarepo_fold($stem)]))
            continue;

        $members = iarepo_expand($token, $stem);
        foreach ($members as $m)
            $seen[iarepo_fold($m)] = true;

        $groups[] = $members;
    }

    // Todo eran stopwords ('de la', 'the') → honramos la intención con la frase
    // completa. Sale sola sin brazo fulltext: 'de la' lleva espacio y 'the' es
    // stopword, y iarepo_ft_indexable() rechaza las dos cosas.
    if (!$groups)
        $groups = [[$phrase]];

    // ── Piezas SQL, grupo a grupo ─────────────────────────────
    $ftParts     = [];  // '+onda*' o '+(onda* wave*)'
    $ftAndConds  = [];  // grupos SIN ningún miembro indexable: se exigen aparte
    $ftAndParams = [];
    $likeConds   = [];
    $likeParams  = [];
    $shortTerms  = [];  // los que filtran por frontera de palabra (para debug)
    $likeTerms   = [];  // el término exacto de cada grupo (para debug y score)

    foreach ($groups as $gi => $members) {
        $likeTerms[] = $members[0];

        $kept       = [];  // los miembros que SÍ han producido condición
        $ftMembers  = [];
        $groupConds = [];
        $groupPs    = [];

        foreach ($members as $i => $m) {
            if ($i === 0) {
                // El término del USUARIO: subcadena, o palabra completa si es
                // corto ('a' o 'de' sueltos con LIKE '%a%' devuelven el
                // catálogo entero). Exactamente como antes de los sinónimos.
                $short = mb_strlen($m, 'UTF-8') < IAREPO_FT_MIN;
                if ($short)
                    $shortTerms[] = $m;
                [$sql, $ps] = iarepo_term_condition($m, $short);
            } else {
                // Un SINÓNIMO: principio de palabra, nunca subcadena.
                [$sql, $ps] = iarepo_syn_condition($m);
                if ($sql === null)
                    continue; // no se pudo construir el patrón → se descarta
            }

            if (iarepo_ft_indexable($m))
                $ftMembers[] = $m;

            $kept[]       = $m;
            $groupConds[] = $sql;
            foreach ($ps as $p)
                $groupPs[] = $p;
        }

        // El grupo que se PUNTÚA y el que se informa en debug son el que
        // realmente FILTRA, no el que salió del diccionario. Así no puede
        // haber un sinónimo que puntúe sin filtrar.
        $groups[$gi] = $kept;

        // Un grupo de un solo miembro se emite SIN paréntesis extra: así el SQL
        // de un término sin sinónimos es byte a byte el de siempre.
        if ($ftMembers) {
            $ftParts[] = count($ftMembers) === 1
                ? '+' . $ftMembers[0] . '*'
                : '+(' . implode(' ', array_map(static fn($m) => $m . '*', $ftMembers)) . ')';
        }

        $cond = count($groupConds) === 1 ? $groupConds[0] : '(' . implode(' OR ', $groupConds) . ')';

        $likeConds[] = $cond;
        foreach ($groupPs as $p)
            $likeParams[] = $p;

        // El fulltext no puede exigir este grupo (todos sus miembros son
        // cortos, o es la frase de respaldo): se exige con AND fuera del
        // MATCH para que multi-palabra siga siendo AND real. 'pH escala' NO
        // debe devolver todo lo que contenga "escala".
        if (!$ftMembers) {
            $ftAndConds[] = $cond;
            foreach ($groupPs as $p)
                $ftAndParams[] = $p;
        }
    }

    $likeSQL = '(' . implode(' AND ', $likeConds) . ')';

    $ft = implode(' ', $ftParts);
    // Cinturón de seguridad: si algo se colase, se descarta el brazo fulltext entero.
    if ($ft !== '' && !preg_match(IAREPO_FT_SAFE, $ft))
        $ft = '';

    // ── Brazo fulltext + unión ────────────────────────────────
    if ($ft !== '') {
        $mode = 'hybrid';

        $ftArm  = IAREPO_MATCH;
        $params = [$ft];
        foreach ($ftAndConds as $cond)
            $ftArm .= ' AND ' . $cond;
        foreach ($ftAndParams as $p)
            $params[] = $p;

        $where  = '((' . $ftArm . ') OR ' . $likeSQL . ')';
        $params = array_merge($params, $likeParams);
    } else {
        $mode   = 'like';
        $where  = $likeSQL;
        $params = $likeParams;
    }

    // ── Relevancia ────────────────────────────────────────────
    $scoreParts = [];
    $scoreParams = [];

    if ($ft !== '') {
        // ACOTADO. Era MATCH*2 pelado, que crece con la frecuencia del
        // término y llegaba a 41,5 sobre el catálogo real: repetir la
        // palabra en la descripción ganaba al bono de 30 de tener la frase
        // entera en el título. Ver la cabecera para la elección del tope.
        $scoreParts[] = 'LEAST((' . IAREPO_MATCH . ') * 2, ' . IAREPO_FT_SCORE_CAP . ')';
        $scoreParams[] = $ft;
    }

    // La frase completa en el título manda sobre todo lo demás.
    $scoreParts[] = "(CASE WHEN r.title LIKE ? ESCAPE '!' THEN 30 ELSE 0 END)";
    $scoreParams[] = '%' . iarepo_like_escape($phrase) . '%';

    // La frase TAL CUAL la escribió el usuario, con puntuación: "C++" en
    // "Introducción a C++" vale más que la "c" suelta de cualquier otro título.
    $rawPhrase = iarepo_raw_phrase($raw);
    if ($rawPhrase !== '' && $rawPhrase !== $phrase) {
        $scoreParts[] = "(CASE WHEN r.title LIKE ? ESCAPE '!' THEN 25 ELSE 0 END)";
        $scoreParams[] = '%' . iarepo_like_escape($rawPhrase) . '%';
    }

    foreach ($groups as $members) {
        $needle = iarepo_like_escape($members[0]);

        // Coincidencia en cualquier parte del título.
        $scoreParts[]  = "(CASE WHEN r.title LIKE ? ESCAPE '!' THEN " . IAREPO_SCORE_TITLE . ' ELSE 0 END)';
        $scoreParams[] = '%' . $needle . '%';

        // Coincidencia como PALABRA COMPLETA del título: sin esto, "pH"
        // puntúa igual en "Escala de pH" que en "Photosynthesis".
        $scoreParts[]  = "(CASE WHEN CONCAT(' ', r.title, ' ') LIKE ? ESCAPE '!' THEN "
            . IAREPO_SCORE_TITLE_WORD . ' ELSE 0 END)';
        $scoreParams[] = '% ' . $needle . ' %';

        // Sólo si el término se EXPANDIÓ. Sin sinónimos no hay nada que
        // desempatar y el score queda idéntico al de siempre.
        if (count($members) < 2)
            continue;

        // El término EXACTO en CUALQUIER columna. Es el sumando que garantiza
        // "primero quien dice ondas, después quien dice waves" incluso cuando
        // el sinónimo está en el título y el término exacto sólo en la
        // descripción (8 contra 5). Cuesta un CONCAT_WS por grupo expandido y
        // por fila puntuada; por eso no se emite cuando no aporta nada.
        $scoreParts[]  = '(CASE WHEN ' . IAREPO_HAYSTACK . " LIKE ? ESCAPE '!' THEN "
            . IAREPO_SCORE_EXACT_ANY . ' ELSE 0 END)';
        $scoreParams[] = '%' . $needle . '%';

        // El sinónimo en el título vale la MITAD que el término exacto, y se
        // mide por PRINCIPIO DE PALABRA igual que en el filtro: si no, 'ion'
        // premiaría a "Simulations". Va por la vía del espacio y no por
        // REGEXP porque un bono de desempate no merece el coste del motor de
        // expresiones regulares, y de paso es insensible a acentos.
        // No lleva bono de palabra completa a propósito: así la poda por
        // prefijo de iarepo_expand() ('math' cubre 'mathematic') no puede
        // alterar el orden, sólo el conjunto, que es idéntico.
        foreach (array_slice($members, 1) as $syn) {
            $scoreParts[]  = "(CASE WHEN CONCAT(' ', r.title, ' ') LIKE ? ESCAPE '!' THEN "
                . IAREPO_SCORE_SYN_TITLE . ' ELSE 0 END)';
            $scoreParams[] = '% ' . iarepo_like_escape($syn) . '%';
        }
    }

    // Desempate suave por popularidad (tope 3 para que nunca domine al texto).
    $scoreParts[] = 'LEAST(COALESCE(r.view_count, 0) / 200, 3)';

    return [
        'mode'         => $mode,
        'where'        => $where,
        'params'       => $params,
        'score'        => '(' . implode(' + ', $scoreParts) . ')',
        'score_params' => $scoreParams,
        'terms'        => iarepo_tokenize($raw),
        'debug'        => [
            'ft'      => $ft,
            'like'    => $likeTerms,
            'short'   => $shortTerms,
            'dropped' => $dropped,
            'groups'  => $groups,
        ],
    ];
}
