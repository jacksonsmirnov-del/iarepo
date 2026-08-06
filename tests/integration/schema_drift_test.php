<?php
// ================================================================
// tests/integration/schema_drift_test.php — ¿Puede el repo reconstruir
// el esquema que el código necesita?
//
// Levanta una BD VACÍA, le aplica setup/schema.sql + setup/schema_users.sql
// + setup/migration_*.sql en orden, y compara el resultado con las
// columnas que el código LEE DE VERDAD (parseadas de los .php, no de una
// lista escrita a mano que se quedaría obsoleta el primer día).
//
// Detecta la clase de fallo que ya lleva meses viva en este proyecto: una
// columna que existe en producción porque alguien la creó a mano por SSH
// y que ningún fichero del repo crea. Mientras nadie reconstruya la BD no
// se nota; el día que haya que restaurar un backup, no hay esquema.
//
// A diferencia de setup/run_migration.php, el bootstrap NO aborta a la
// primera: ejecuta todas las sentencias para poder dar el parte completo.
//
// ── POR QUÉ SE ENDURECIÓ [2026-08-04] ─────────────────────────
// La primera versión de este fichero NO habría cazado `iframe_blocked`
// por sí sola. Tenía dos parsers genéricos y los dos eran ciegos a ella:
//
//   1. `iarepo_drift_alias_cols()` sólo reconoce columnas escritas con el
//      alias `r.` (`r.source_name`), y sólo se aplicaba a api/resources.php
//      y shared/search.php. cron/*.php no se miraba en absoluto.
//   2. Aunque se hubiera mirado, el SQL del cron NO usa alias:
//         UPDATE resources SET link_status = ?, iframe_blocked = ?, ...
//      No hay ningún `r.` que capturar, y no existía brazo para las listas
//      de UPDATE ... SET.
//
// `iframe_blocked` salía en rojo por test_it_columnas_de_los_cron_existen()
// y sólo porque alguien la había escrito A MANO en una lista literal. Eso
// no es un guard: es una nota. La siguiente columna que se añada por SSH
// no estará en ninguna lista y volverá a pasar desapercibida.
//
// Ahora el escáner (iarepo_drift_cols_de_fichero) recorre TODOS los
// api/*.php y cron/*.php y extrae columnas de `resources` por cuatro vías
// independientes: alias `r.`, listas de INSERT, listas de UPDATE ... SET, y
// sentencias de una sola tabla sin alias (SELECT/WHERE/ORDER BY). El
// resultado se compara contra information_schema de la BD reconstruida.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

// ── Lectura del código de producción ──────────────────────────

/**
 * Ficheros de aplicación que se auditan.
 * api/*.php y cron/*.php: todo lo que habla con la tabla `resources` en
 * caliente. No se incluyen setup/*.php (scripts de siembra manuales) ni
 * las páginas HTML, que no se pueden tokenizar con la misma garantía.
 *
 * @return string[] rutas relativas a la raíz del repo
 */
function iarepo_drift_files(): array
{
    $raiz  = iarepo_it_root();
    $files = array_merge(glob("$raiz/api/*.php") ?: [], glob("$raiz/cron/*.php") ?: []);
    sort($files, SORT_STRING);
    // substr y no str_replace: si la raíz se repitiera dentro de la ruta
    // (/home/x/resources/vendor/resources/...) str_replace la borraría dos veces.
    return array_map(static fn($f) => ltrim(substr($f, strlen($raiz)), '/'), $files);
}

/**
 * Palabras del SQL que NUNCA son nombres de columna.
 *
 * Las funciones (COUNT, COALESCE, DATE_FORMAT...) NO hacen falta aquí: el
 * extractor descarta todo identificador seguido de '(' . Esta lista es sólo
 * para lo que aparece suelto (NULL, INTERVAL, HOUR, ASC...).
 *
 * SI ESTE TEST TE SALE EN ROJO POR UNA PALABRA QUE NO ES UNA COLUMNA:
 * añádela aquí. Es la reparación correcta; borrar el test no lo es.
 */
const IAREPO_DRIFT_KEYWORDS = [
    'select', 'from', 'where', 'and', 'or', 'not', 'null', 'is', 'in', 'like', 'order', 'by',
    'group', 'having', 'limit', 'offset', 'asc', 'desc', 'as', 'on', 'join', 'left', 'right',
    'inner', 'outer', 'cross', 'straight_join', 'set', 'update', 'insert', 'into', 'values',
    'delete', 'distinct', 'all', 'union', 'for', 'skip', 'locked', 'between', 'case', 'when',
    'then', 'else', 'end', 'exists', 'interval', 'microsecond', 'second', 'minute', 'hour',
    'day', 'week', 'month', 'quarter', 'year', 'collate', 'default', 'true', 'false', 'unknown',
    'binary', 'using', 'natural', 'ignore', 'force', 'use', 'index', 'key', 'with', 'recursive',
    'partition', 'window', 'over', 'separator', 'against', 'boolean', 'mode', 'expansion',
    'language', 'duplicate', 'low_priority', 'quick', 'div', 'mod', 'xor', 'rlike', 'regexp',
    'escape', 'of', 'nowait', 'share', 'signed', 'unsigned', 'char', 'nchar', 'resources',
];

/**
 * Columnas de `resources` que un fichero PHP usa con el alias `r.`.
 * El límite de palabra impide confundirlas con `rl.`, `rt.` o `rts.`.
 *
 * Se aplica al fichero ENTERO (no a cada sentencia SQL) a propósito:
 * api/resources.php monta el WHERE en un array de PHP —
 *   $where = ['r.is_active = 1', "(r.link_status IS NULL OR ...)"]
 * — y esos trozos son literales sueltos que no contienen la palabra
 * 'resources', así que ningún troceador por sentencia los vería.
 *
 * @return string[]
 */
function iarepo_drift_alias_cols(string $php): array
{
    preg_match_all('/(?<![A-Za-z0-9_$])r\.([a-z_][a-z0-9_]*)/', $php, $m);
    return array_values(array_unique($m[1]));
}

/**
 * ¿Este fichero ata el alias `r` a `resources` (FROM/JOIN resources r)?
 *
 * El escáner masivo sólo se fía del brazo 'alias' cuando la respuesta es sí:
 * en un fichero cualquiera un `r.` podría ser el alias de otra tabla y
 * atribuir esas columnas a `resources` sería un falso positivo.
 *
 * NO se aplica en test_it_columnas_de_shared_search_existen(): shared/search.php
 * sólo produce TROZOS de WHERE (`r.source_name LIKE ?`), nunca la sentencia
 * completa, así que ahí no hay ningún FROM que encontrar — y sabemos por
 * contrato que ese `r` es el de api/resources.php.
 */
function iarepo_drift_ata_alias_r(string $php): bool
{
    return (bool) preg_match('/\bresources\s+(?:AS\s+)?r\b/i', $php);
}

/**
 * Columnas de las listas `INSERT INTO resources (...)`.
 *
 * @return string[]
 */
function iarepo_drift_insert_cols(string $php): array
{
    preg_match_all('/INSERT\s+INTO\s+resources\s*\(([^)]*)\)/i', $php, $m);
    $cols = [];
    foreach ($m[1] as $lista)
        foreach (explode(',', $lista) as $c) {
            $c = trim($c);
            if ($c !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $c))
                $cols[] = $c;
        }
    return array_values(array_unique($cols));
}

/**
 * Trozos de SQL embebidos en un .php, uno por literal de cadena.
 *
 * Usa el tokenizador de PHP (token_get_all) en vez de una expresión
 * regular: así las comillas, los escapes y los heredocs los delimita el
 * propio PHP y no hay que adivinar dónde empieza y acaba cada consulta.
 * Las interpolaciones ("... WHERE $whereSQL") se sustituyen por __VAR__
 * para no inventarse identificadores que no están escritos.
 *
 * Sólo devuelve los literales que hablan de `resources`.
 *
 * @return string[] SQL con los espacios normalizados
 */
function iarepo_drift_sql_chunks(string $php): array
{
    $tokens = @token_get_all($php);
    if (!is_array($tokens))
        return [];

    $literales = [];
    $buf       = null; // cadena interpolada / heredoc en construcción

    foreach ($tokens as $t) {
        if (is_string($t)) {
            if ($t === '"') {
                if ($buf === null) $buf = '';
                else { $literales[] = $buf; $buf = null; }
            }
            continue;
        }

        [$id, $text] = $t;

        if ($id === T_CONSTANT_ENCAPSED_STRING && $buf === null) {
            $literales[] = substr($text, 1, -1);   // sin las comillas
            continue;
        }
        if ($id === T_START_HEREDOC) { $buf = ''; continue; }
        if ($id === T_END_HEREDOC) {
            if ($buf !== null) { $literales[] = $buf; $buf = null; }
            continue;
        }
        if ($buf === null)
            continue;
        if ($id === T_ENCAPSED_AND_WHITESPACE) { $buf .= $text; continue; }
        if ($id === T_VARIABLE) { $buf .= ' __VAR__ '; continue; }
        $buf .= ' ';                                // ${...}, ->prop, [idx]...
    }

    $sql = [];
    foreach ($literales as $s)
        if (preg_match('/\bresources\b/i', $s) && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $s))
            $sql[] = trim((string) preg_replace('/\s+/', ' ', $s));

    return $sql;
}

/** Trocea por las comas de nivel 0 (respeta los paréntesis). @return string[] */
function iarepo_drift_split_top(string $s): array
{
    $out   = [];
    $cur   = '';
    $depth = 0;
    $n     = strlen($s);

    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '(') $depth++;
        elseif ($c === ')') $depth--;
        elseif ($c === ',' && $depth === 0) { $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '')
        $out[] = trim($cur);

    return $out;
}

/** Corta la cadena en el primer WHERE de nivel 0 (el de la propia sentencia). */
function iarepo_drift_cut_where(string $s): string
{
    $depth = 0;
    $n     = strlen($s);

    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '(') { $depth++; continue; }
        if ($c === ')') { $depth--; continue; }
        if ($depth !== 0 || ($c !== 'W' && $c !== 'w'))
            continue;
        if ($i > 0 && preg_match('/[A-Za-z0-9_]/', $s[$i - 1]))
            continue;
        if (preg_match('/^WHERE\b/i', substr($s, $i, 6)))
            return substr($s, 0, $i);
    }
    return $s;
}

/**
 * Identificadores sueltos de un trozo de SQL: lo que queda tras quitar
 * literales, llamadas a función, alias de salida (`AS x`) y palabras
 * reservadas. Sobre una sentencia de UNA sola tabla, eso son sus columnas.
 *
 * @return string[]
 */
function iarepo_drift_bare_idents(string $sql): array
{
    $alias = iarepo_drift_output_aliases($sql);

    $s = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", " '' ", $sql);  // literales
    $s = (string) preg_replace('/\bCOLLATE\s+[a-z0-9_]+/i', ' ', $s);        // COLLATE utf8mb4_...
    $s = (string) preg_replace('/\bAS\s+`?[a-z_][a-z0-9_]*`?/i', ' ', $s);   // alias de salida
    $s = str_replace('__VAR__', ' ', $s);

    preg_match_all('/(?<![A-Za-z0-9_.`])([a-z_][a-z0-9_]*)\s*(\(?)/i', $s, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $g) {
        if ($g[2] === '(')                                   // COUNT(, COALESCE(...
            continue;
        $w = strtolower($g[1]);
        if (in_array($w, IAREPO_DRIFT_KEYWORDS, true))
            continue;
        if (in_array($w, $alias, true))                      // ORDER BY <alias de salida>
            continue;
        $out[$w] = true;
    }
    return array_keys($out);
}

/** Alias de salida declarados con `AS x` (no son columnas). @return string[] */
function iarepo_drift_output_aliases(string $sql): array
{
    preg_match_all('/\bAS\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $m);
    return array_values(array_unique(array_map('strtolower', $m[1])));
}

/**
 * TODAS las columnas de `resources` que un fichero usa, con la vía por la
 * que se han detectado (para que el mensaje de error diga dónde mirar).
 *
 * Cuatro brazos independientes:
 *   alias   r.columna                      (api/resources.php, og-image...)
 *   insert  INSERT INTO resources (...)    (alta y fork de recursos)
 *   update  UPDATE resources SET col = ?   (contadores y los dos cron)
 *   bare    sentencia de una sola tabla sin alias: lista SELECT + WHERE +
 *           ORDER BY (cron/run.php, api/stats.php, los SELECT de control)
 *
 * @return array<string,string[]> columna => brazos que la han visto
 */
function iarepo_drift_cols_de_fichero(string $php): array
{
    $cols = [];
    $add  = static function (string $col, string $brazo) use (&$cols): void {
        $col = strtolower($col);
        if (!isset($cols[$col]))
            $cols[$col] = [];
        if (!in_array($brazo, $cols[$col], true))
            $cols[$col][] = $brazo;
    };

    if (iarepo_drift_ata_alias_r($php))
        foreach (iarepo_drift_alias_cols($php) as $c)
            $add($c, 'alias');

    foreach (iarepo_drift_sql_chunks($php) as $sql) {
        foreach (iarepo_drift_insert_cols($sql) as $c)
            $add($c, 'insert');

        if (preg_match('/\bUPDATE\s+resources\b(?:\s+(?:AS\s+)?[a-z_][a-z0-9_]*)?\s+SET\s+(.*)$/i', $sql, $m))
            foreach (iarepo_drift_split_top(iarepo_drift_cut_where($m[1])) as $asignacion)
                if (preg_match('/^`?([a-z_][a-z0-9_]*)`?\s*=/i', $asignacion, $mm))
                    $add($mm[1], 'update');

        // Brazo 'bare': sólo si la sentencia toca UNA tabla, es `resources`,
        // no lleva alias y no tiene subconsultas. En cuanto hay una segunda
        // tabla, un identificador suelto podría ser de la otra y atribuirlo a
        // `resources` sería un falso positivo; esos casos los cubre el brazo
        // 'alias', que sí sabe de quién es cada columna.
        preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $m);
        $tablas = array_values(array_unique(array_map('strtolower', $m[1])));

        $conAlias = (bool) preg_match(
            '/\bresources\s+(?!SET\b|WHERE\b|VALUES\b|ON\b|GROUP\b|ORDER\b|LIMIT\b)(?:AS\s+)?[a-z_][a-z0-9_]*/i',
            $sql
        );
        $conSub = (bool) preg_match('/\(\s*SELECT\b/i', $sql);

        if ($tablas === ['resources'] && !$conAlias && !$conSub)
            foreach (iarepo_drift_bare_idents($sql) as $c)
                $add($c, 'bare');
    }

    return $cols;
}

/** Columnas reales de una tabla en la BD reconstruida. @return string[] */
function iarepo_drift_db_cols(PDO $db, string $tabla): array
{
    $st = $db->prepare(
        'SELECT column_name FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute([$tabla]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function iarepo_drift_leer(string $rel): string
{
    $ruta = iarepo_it_root() . '/' . $rel;
    if (!is_file($ruta))
        throw new AssertionError("No existe $rel");
    return (string) file_get_contents($ruta);
}

// ── 0. ¿Pudo siquiera construirse la BD de pruebas? ───────────

function test_it_bootstrap_pudo_preparar_la_bd(): void
{
    // Este test NO se salta cuando el bootstrap falla al construir el
    // esquema o sembrar el corpus: esos casos son defectos del repo y
    // deben verse en rojo, no esconderse tras un SKIP. Sólo se salta si
    // falta la infraestructura (Docker parado, puerto ocupado...).
    $error = iarepo_it_prep_error();

    if ($error !== null)
        throw new AssertionError(
            "El bootstrap no pudo construir la BD de pruebas desde el repo:\n"
            . "      $error\n"
            . '      El resto de la suite se ha saltado porque no tenía dónde ejecutarse.'
        );

    if (!it_db_or_skip(__FUNCTION__)) return;

    it_true(iarepo_it_schema_report() !== null, 'No hay informe de carga del esquema');
}

// ── 1. ¿Se aplica el esquema del repo sin errores? ────────────

function test_it_esquema_del_repo_se_aplica_sin_errores(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $informe = iarepo_it_schema_report();
    it_true($informe !== null, 'No se pudo cargar el esquema');

    if ($informe['failed'] === [])
        return;

    $detalle = [];
    foreach ($informe['failed'] as $f)
        $detalle[] = sprintf("%s (sentencia #%d)\n          %s\n          → %s",
            $f['file'], $f['index'], $f['sql'], $f['error']);

    throw new AssertionError(
        sprintf(
            "%d de %d sentencias de setup/ fallan al reconstruir la BD desde cero.\n"
            . "      Si esto está en rojo, el repo NO puede rehacer producción: el día que\n"
            . "      haya que restaurar un backup, el esquema que sale de setup/ no es el bueno.\n\n"
            . "      La causa histórica (arreglada el 2026-08-04) fue una migración que dependía\n"
            . "      del ORDEN: migration_002 hacía 'ADD COLUMN ... AFTER source_name' y\n"
            . "      source_name la declaraba un fichero que se aplicaba DESPUÉS. Si vuelve a\n"
            . "      pasar algo parecido, la regla es la misma: cada migración debe poder\n"
            . "      aplicarse sola, sin suponer que otra se corrió antes (por SSH las corre\n"
            . "      un humano, en el orden que le parece). El 'AFTER' es cosmético — quítalo.\n\n      - %s",
            count($informe['failed']),
            $informe['ok'] + count($informe['failed']),
            implode("\n      - ", $detalle)
        )
    );
}

// ── 2. Columnas que el LISTADO de la API necesita ─────────────

function test_it_columnas_del_listado_de_api_resources_existen(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $php     = iarepo_drift_leer('api/resources.php');
    $usadas  = iarepo_drift_alias_cols($php);
    $reales  = iarepo_drift_db_cols($db, 'resources');
    $faltan  = array_values(array_diff($usadas, $reales));

    it_true(count($usadas) >= 20,
        'El parser de columnas ha encontrado sólo ' . count($usadas) . ': ¿cambió el formato del SQL?');

    it_eq([], $faltan,
        "api/resources.php hace SELECT/WHERE sobre columnas que el repo no crea.\n"
        . '      Cada una es un 500 garantizado sobre una BD reconstruida desde setup/.');
}

// ── 3. Columnas que el ALTA de recursos necesita ───────────────

function test_it_columnas_del_insert_de_api_resources_existen(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $usadas = iarepo_drift_insert_cols(iarepo_drift_leer('api/resources.php'));
    $reales = iarepo_drift_db_cols($db, 'resources');
    $faltan = array_values(array_diff($usadas, $reales));

    it_true(count($usadas) >= 12,
        'El parser de INSERT ha encontrado sólo ' . count($usadas) . ' columnas');

    it_eq([], $faltan,
        "api/resources.php inserta en columnas que el repo no crea.\n"
        . "      Sobre una BD reconstruida desde setup/, crear un recurso da PDOException → 500.\n"
        . '      Misma causa raíz que test_it_esquema_del_repo_se_aplica_sin_errores.');
}

// ── 4. Columnas que usa el motor de búsqueda nuevo ────────────

function test_it_columnas_de_shared_search_existen(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // shared/search.php es código NUEVO: si mete en el WHERE una columna
    // que no existe, cada búsqueda es un 500. Es la comprobación más
    // barata que impide repetir la historia de source_name.
    $usadas = iarepo_drift_alias_cols(iarepo_drift_leer('shared/search.php'));
    $reales = iarepo_drift_db_cols($db, 'resources');
    $faltan = array_values(array_diff($usadas, $reales));

    it_true(in_array('source_name', $usadas, true),
        'El parser no ve r.source_name en shared/search.php: revisa la regex');
    it_eq([], $faltan, 'shared/search.php referencia columnas de resources que no existen');

    // Y la tabla del brazo EXISTS de los tags.
    $tags = iarepo_drift_db_cols($db, 'resource_tags');
    it_eq([], array_values(array_diff(['resource_id', 'tag'], $tags)),
        'Falta resource_tags(resource_id, tag): el brazo EXISTS de la búsqueda no puede funcionar');
}

// ── 5. Tablas que el listado consulta ─────────────────────────

function test_it_tablas_del_listado_existen(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $reales = $db->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);

    // resource_usage entró en esta lista al cablear "lo usé en clase"
    // [2026-08-06]. No estaba, y por eso el escáner no habría dicho nada si el
    // repo dejara de crearla: api/usage.php y el fork de api/resources.php
    // escriben en ella, así que su ausencia rompe las dos cosas — y el fork lo
    // haría dentro de una transacción, con rollback y sin rastro.
    // resource_views y view_salts entraron con la medición de visitas
    // [2026-08-06]. Si el repo dejara de crearlas, api/track.php fallaría en
    // CADA visita — y como el beacon ignora la respuesta, nadie lo vería en la
    // web: sólo dejarían de contarse las visitas, en silencio.
    $necesarias = ['resources', 'categories', 'resource_tags', 'resource_likes', 'resource_usage',
                   'resource_views', 'view_salts'];
    it_eq([], array_values(array_diff($necesarias, $reales)),
        'Faltan tablas que consulta el listado de api/resources.php');
}

// ── 6. El ENUM de moderación contra lo que escribe el código ──

function test_it_moderation_status_admite_pending_review(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $st = $db->prepare(
        "SELECT column_type FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'resources'
            AND column_name = 'moderation_status'"
    );
    $st->execute();
    $tipo = $st->fetchColumn();

    it_true($tipo !== false,
        "La columna moderation_status no existe en la BD reconstruida.\n"
        . '      La crea setup/migration_002_moderation.sql; si falta, mira el rojo de '
        . 'test_it_esquema_del_repo_se_aplica_sin_errores.');

    // api/resources.php:384 escribe 'pending_review'; cron/run.php:107 y
    // setup/cron_moderation.php:33 filtran por ese valor. Si el ENUM no lo
    // contiene, con STRICT_TRANS_TABLES el INSERT aborta (ERROR 1265).
    //
    // El recorte `[^;\n]*` es deliberado: sin él, el `[^;]*` de la versión
    // anterior saltaba de línea y capturaba el literal de la línea siguiente
    // ('info', de $response['info'] = '...'), inventándose un valor de ENUM
    // que el código nunca escribe.
    $codigo = iarepo_drift_leer('api/resources.php') . "\n" . iarepo_drift_leer('cron/run.php');
    preg_match_all('/(?:\$moderationStatus|moderation_status)([^;\n]*)/', $codigo, $m);

    $valores = [];
    foreach ($m[1] as $resto)
        if (preg_match_all("/'([a-z_]+)'/", $resto, $mm))
            foreach ($mm[1] as $v)
                $valores[$v] = true;
    $valores = array_keys($valores);

    it_true(in_array('pending_review', $valores, true),
        'El parser de valores del ENUM no ve pending_review en el código: revisa la regex');

    $faltan = [];
    foreach ($valores as $v)
        if (stripos((string) $tipo, "'$v'") === false)
            $faltan[] = $v;

    it_eq([], $faltan,
        "El código escribe valores que el ENUM declarado no admite ($tipo).\n"
        . '      Con STRICT_TRANS_TABLES el INSERT aborta con ERROR 1265, no avisa.');
}

// ── 7. Pin de regresión: las columnas que escriben los cron ───

function test_it_columnas_de_los_cron_existen(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    // Esta lista es literal A PROPÓSITO: es un PIN de las tres columnas que
    // estuvieron meses viviendo sólo en producción. Si alguien borra su
    // declaración de setup/, esto se pone rojo aunque el escáner genérico
    // cambie o se rompa.
    //
    // No confundir con un guard: una lista escrita a mano sólo protege lo
    // que alguien se acordó de escribir — de hecho así se descubrió
    // iframe_blocked, y por eso existe además
    // test_it_ninguna_columna_usada_por_el_codigo_falta_en_el_esquema(),
    // que deriva la lista del código y no de la memoria de nadie.
    $reales = iarepo_drift_db_cols($db, 'resources');
    $faltan = array_values(array_diff(['link_status', 'iframe_blocked', 'link_checked_at'], $reales));

    it_eq([], $faltan,
        "cron/run.php:66 y setup/cron_link_checker.php:39 hacen UPDATE de estas columnas.\n"
        . '      Las declara setup/migration_000_prod_baseline.sql.');
}

// ── 8. EL GUARD: código vs esquema, sin listas a mano ─────────

function test_it_ninguna_columna_usada_por_el_codigo_falta_en_el_esquema(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $reales = array_map('strtolower', iarepo_drift_db_cols($db, 'resources'));
    $faltan = [];

    foreach (iarepo_drift_files() as $rel) {
        $php = iarepo_drift_leer($rel);
        foreach (iarepo_drift_cols_de_fichero($php) as $col => $brazos)
            if (!in_array($col, $reales, true))
                $faltan[] = sprintf('%s → %s   (visto en: %s)', $rel, $col, implode('+', $brazos));
    }

    it_eq([], $faltan,
        "Hay columnas de `resources` que el CÓDIGO usa y que el ESQUEMA del repo NO crea.\n"
        . "      Sobre una BD reconstruida desde setup/ cada una es un 500 (o un cron que\n"
        . "      revienta en silencio). Arréglalo declarándolas en setup/, no quitando el test:\n"
        . "        · si la columna existe en producción → decláralas en\n"
        . "          setup/migration_000_prod_baseline.sql (ADD COLUMN IF NOT EXISTS: allí es no-op)\n"
        . "        · si es una columna nueva → migración nueva, idempotente\n"
        . "      Si lo que sale NO es una columna sino una palabra del SQL, el arreglo es\n"
        . '      añadirla a IAREPO_DRIFT_KEYWORDS (arriba en este mismo fichero).');
}

// ── 9. El escáner del test 8 no puede quedarse ciego ──────────

function test_it_el_escaner_de_columnas_sigue_viendo_el_codigo(): void
{
    if (!it_db_or_skip(__FUNCTION__)) return;

    // Un escáner basado en expresiones regulares tiene un modo de fallo
    // peor que el falso positivo: dejar de encontrar nada y quedarse verde
    // para siempre. Esto lo ancla con testigos de LOS CUATRO brazos —
    // ninguno de los cuales se puede detectar por otra vía:
    //
    //   alias   r.link_status vive en un literal suelto del array $where de
    //           api/resources.php, no en una sentencia completa
    //   insert  content_hash sólo aparece en la lista del INSERT
    //   update  iframe_blocked SÓLO aparece en el UPDATE ... SET del cron
    //           (es la columna que la versión anterior del test no cazaba)
    //   bare    link_checked_at, en el SELECT sin alias de cron/run.php
    $ficheros = iarepo_drift_files();
    it_true(count($ficheros) >= 12,
        'Sólo se han encontrado ' . count($ficheros) . ' ficheros que auditar en api/ y cron/');

    $porFichero = [];
    $todas      = [];
    foreach ($ficheros as $rel) {
        $cols              = iarepo_drift_cols_de_fichero(iarepo_drift_leer($rel));
        $porFichero[$rel]  = $cols;
        foreach ($cols as $col => $brazos)
            $todas[$col] = true;
    }

    it_true(count($todas) >= 25,
        'El escáner sólo ve ' . count($todas) . ' columnas distintas en api/ + cron/: se ha quedado ciego');

    $testigos = [
        ['api/resources.php', 'link_status',     'alias'],
        ['api/resources.php', 'content_hash',    'insert'],
        ['cron/run.php',      'iframe_blocked',  'update'],
        ['cron/run.php',      'link_checked_at', 'bare'],
    ];

    foreach ($testigos as [$rel, $col, $brazo]) {
        $brazos = $porFichero[$rel][$col] ?? null;
        it_true($brazos !== null, "El escáner ya no ve $col en $rel: el brazo '$brazo' está roto");
        it_true(in_array($brazo, $brazos, true),
            "$col se ve en $rel pero no por el brazo '$brazo' (sino por " . implode('+', $brazos) . "):\n"
            . "      ese brazo es el único que caza esa forma de SQL, y si se rompe la próxima\n"
            . '      columna añadida a mano en producción volverá a pasar desapercibida.');
    }
}

// ── 7. El cinturón de run_migration.php ───────────────────────
//
// El runner es seguro por construcción en la ruta normal: lee el .env.php que
// está JUNTO a él (`require __DIR__`), no el del directorio donde estés, así
// que invocarlo usa siempre las credenciales de iarepo. Verificado en el
// servidor [2026-08-06]: desde el doc root de Campus responde "Could not open
// input file", porque Campus no tiene este script.
//
// Lo que NO cubre esa construcción, y sí este cinturón, es que el .env.php de
// al lado apunte a otra base de datos —copiar un doc root para montar un
// staging y dejarse el .env.php del original, o al revés—. Ahí no hay ningún
// error: las sentencias se aplican, sin más, contra la BD equivocada.

/** Reproduce la decisión del cinturón de setup/run_migration.php. */
function it_rm_aborta(PDO $db): bool
{
    $faltan = 0;
    foreach (['resources', 'categories', 'resource_tags'] as $t) {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$t]);
        if (!$q->fetchColumn()) $faltan++;
    }
    $total = (int) $db->query('SELECT COUNT(*) FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
    return $faltan > 0 && $total > 0;
}

function test_it_el_runner_deja_pasar_la_bd_de_iarepo(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    it_true(!it_rm_aborta($db),
        'sobre la BD reconstruida desde el repo, el cinturón DEJA PASAR. Un guard '
        . 'que bloquea el caso bueno se desactiva el primer día, y entonces no '
        . 'protege de nada.');
}

function test_it_el_runner_aborta_en_una_bd_ajena(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $db->exec('DROP DATABASE IF EXISTS it_bd_ajena');
    $db->exec('CREATE DATABASE it_bd_ajena');
    $db->exec('USE it_bd_ajena');
    // Una BD con contenido, pero de otra aplicación (p. ej. Campus).
    $db->exec('CREATE TABLE usuarios (id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE clases (id INT PRIMARY KEY) ENGINE=InnoDB');

    $aborta = it_rm_aborta($db);

    $db->exec('USE ' . iarepo_it_cfg('DB_NAME', 'iarepo_test'));
    $db->exec('DROP DATABASE IF EXISTS it_bd_ajena');

    it_true($aborta,
        'una BD CON CONTENIDO y sin las señas de iarepo se rechaza. Sin esto, '
        . 'las migraciones se aplicarían contra la base equivocada sin ningún '
        . 'error visible.');
}

function test_it_el_runner_permite_una_bd_vacia(): void
{
    if (!($db = it_db_or_skip(__FUNCTION__))) return;

    $db->exec('DROP DATABASE IF EXISTS it_bd_vacia');
    $db->exec('CREATE DATABASE it_bd_vacia');
    $db->exec('USE it_bd_vacia');

    $aborta = it_rm_aborta($db);

    $db->exec('USE ' . iarepo_it_cfg('DB_NAME', 'iarepo_test'));
    $db->exec('DROP DATABASE IF EXISTS it_bd_vacia');

    it_true(!$aborta,
        'una BD VACÍA sí pasa: es la reconstrucción desde cero, cuando schema.sql '
        . 'todavía no ha corrido. Bloquearla rompería el arranque de un clon nuevo '
        . 'y el propio bootstrap de estos tests.');
}
