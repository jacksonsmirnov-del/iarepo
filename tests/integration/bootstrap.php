<?php
// ================================================================
// tests/integration/bootstrap.php — Arranque de la capa 3 (BD real)
//
// Levanta un MariaDB efímero en Docker, reconstruye el esquema DESDE
// EL REPO (setup/schema.sql + setup/migration_*.sql) y siembra
// tests/fixtures/seed.sql. Devuelve un PDO listo para usar.
//
// ── CÓMO SE INVOCA ────────────────────────────────────────────
// Este archivo NO define ningún test_*: es seguro que el runner lo
// incluya con glob('tests/integration/*.php').
//
//   php tests/run.php --integration      (contrato del runner común)
//   php tests/integration/_runner.php    (runner autónomo de esta suite)
//
// Contrato con el runner: cada tests/integration/*_test.php define
// funciones `test_*()` sin argumentos. Fallo = excepción lanzada.
// Los asserts (it_eq, it_true, ...) los define ESTE archivo, así que
// no dependen de cómo llame el runner a los suyos.
//
// ── SI NO HAY DOCKER ──────────────────────────────────────────
// Nada falla: iarepo_it_db() devuelve null y cada test imprime un
// SKIP con el motivo y retorna. Un push nunca se bloquea por no
// tener Docker levantado (requisito explícito del diseño).
//
// ── EL CONTENEDOR ─────────────────────────────────────────────
//   nombre  iarepo_test_db     (no toca qb_local_db, que vive en 3307)
//   puerto  127.0.0.1:3398     (sólo loopback: no se expone a la LAN)
//   imagen  mariadb:11         (ver justificación abajo)
//
// Se REUTILIZA entre ejecuciones: arrancarlo en frío cuesta ~1,5 s y
// reconstruir el esquema ~0,7 s, así que dejarlo vivo es lo que hace
// viable el gate pre-push. Para borrarlo a mano:
//
//   docker rm -f iarepo_test_db
//
// o `php tests/integration/_runner.php --down`.
//
// ── POR QUÉ mariadb:11 Y NO mysql:8 ───────────────────────────
// Producción (Hostinger Business compartido) es MariaDB, no MySQL 8.
// Prueba dentro del propio repo: setup/migration_001..003 y 010 usan
// `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS`, sintaxis que
// MySQL 8 RECHAZA con error de sintaxis y que MariaDB acepta desde
// 10.0.2. Si prod fuese MySQL 8 esas migraciones jamás habrían podido
// aplicarse. Además MariaDB no tiene parser NGRAM, que es justo la
// restricción sobre la que está diseñado shared/search.php.
// La rama 11 es la que ya está cacheada en este equipo (0 descarga en
// el gate). Si algún día se confirma la versión exacta de Hostinger
// con `SELECT VERSION()`, se fija sin tocar código:
//
//   IAREPO_TEST_DB_IMAGE=mariadb:10.11 php tests/run.php --integration
//
// Variables de entorno admitidas:
//   IAREPO_TEST_DB_IMAGE  (def. mariadb:11)
//   IAREPO_TEST_DB_PORT   (def. 3398)
//   IAREPO_TEST_DB_NAME   (def. iarepo_test)
//   IAREPO_TEST_DB_CONT   (def. iarepo_test_db)
//   IAREPO_TEST_DB_WAIT   (def. 60, segundos de espera al arranque)
//   IAREPO_TEST_SKIP=1    fuerza el salto de toda la suite
// ================================================================

require_once dirname(__DIR__, 2) . '/shared/search.php';

// ── Configuración ─────────────────────────────────────────────

function iarepo_it_cfg(string $key, string $default): string
{
    $v = getenv('IAREPO_TEST_' . $key);
    return ($v === false || $v === '') ? $default : $v;
}

function iarepo_it_root(): string
{
    return dirname(__DIR__, 2);
}

const IAREPO_IT_PASS = 'iarepo_test'; // contraseña de root del contenedor efímero: no es un secreto

// ── Estado del proceso ────────────────────────────────────────
// Todo memoizado: el esquema se reconstruye UNA vez por proceso PHP.

function &iarepo_it_state(): array
{
    static $s = [
        'tried'      => false,
        'pdo'        => null,
        'skip'       => null,   // motivo del salto, o null
        'prep_error' => null,   // fallo AL CONSTRUIR el esquema/corpus (= defecto, no infra)
        'created'    => false,  // ¿creamos NOSOTROS el contenedor?
        'schema'     => null,   // informe de carga del esquema
        'skips_seen' => [],
        'boot_ms'    => 0.0,
    ];
    return $s;
}

// ── Utilidades de shell ───────────────────────────────────────

/** Ejecuta un comando capturando salida y código de retorno. */
function iarepo_it_sh(string $cmd, int $timeout = 30): array
{
    static $tieneTimeout = null;
    if ($tieneTimeout === null) {
        // Sin coreutils no hay `timeout`; se ejecuta igualmente (mejor eso que
        // saltarse la suite entera por una utilidad ausente).
        $probe = [];
        $rc    = 1;
        exec('command -v timeout 2>/dev/null', $probe, $rc);
        $tieneTimeout = ($rc === 0);
    }

    $full = ($tieneTimeout ? 'timeout ' . $timeout . ' ' : '') . $cmd . ' 2>&1';
    $out  = [];
    $code = 1;
    exec($full, $out, $code);
    return ['code' => $code, 'out' => trim(implode("\n", $out))];
}

function iarepo_it_docker_ok(): bool
{
    static $ok = null;
    if ($ok !== null)
        return $ok;

    // `docker version` con --format sólo del servidor: falla rápido si el
    // demonio no responde (a diferencia de `docker info`, que puede colgarse).
    $r  = iarepo_it_sh("docker version --format '{{.Server.Version}}'", 15);
    $ok = ($r['code'] === 0 && $r['out'] !== '');
    return $ok;
}

// ── Troceador de SQL ──────────────────────────────────────────

/**
 * Parte un script SQL en sentencias respetando comillas simples/dobles,
 * backticks, escapes y comentarios (-- , # y bloques).
 *
 * El `explode(';', $sql)` de setup/run_migration.php:41 NO hace esto:
 * parte a la primera ';' que aparezca dentro de una cadena o de un
 * comentario de bloque. Aquí hace falta hacerlo bien porque el corpus
 * de fixtures sí contiene ';' dentro de literales.
 *
 * @return string[]
 */
function iarepo_it_split_sql(string $sql): array
{
    $out = [];
    $cur = '';
    $n   = strlen($sql);
    $i   = 0;
    $q   = null; // comilla abierta

    while ($i < $n) {
        $c  = $sql[$i];
        $c2 = substr($sql, $i, 2);

        if ($q === null) {
            // Comentario de línea '-- ' (el guion doble sólo abre comentario
            // si le sigue espacio o fin de línea: '--' pegado es un operador).
            if ($c2 === '--' && ($i + 2 >= $n || strpbrk($sql[$i + 2], " \t\r\n") !== false)) {
                $j = strpos($sql, "\n", $i);
                if ($j === false)
                    break;
                $i = $j + 1;
                $cur .= "\n";
                continue;
            }
            if ($c === '#') {
                $j = strpos($sql, "\n", $i);
                if ($j === false)
                    break;
                $i = $j + 1;
                $cur .= "\n";
                continue;
            }
            if ($c2 === '/*') {
                $j = strpos($sql, '*/', $i + 2);
                if ($j === false)
                    break;
                $i = $j + 2;
                $cur .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
                $cur .= $c;
                $i++;
                continue;
            }
            if ($c === ';') {
                $s = trim($cur);
                if ($s !== '')
                    $out[] = $s;
                $cur = '';
                $i++;
                continue;
            }
            $cur .= $c;
            $i++;
            continue;
        }

        // Dentro de una cadena
        if ($c === '\\' && $q !== '`' && $i + 1 < $n) {
            $cur .= $c2;
            $i += 2;
            continue;
        }
        if ($c === $q) {
            if ($i + 1 < $n && $sql[$i + 1] === $q) { // '' o "" escapada
                $cur .= $c2;
                $i += 2;
                continue;
            }
            $q = null;
            $cur .= $c;
            $i++;
            continue;
        }
        $cur .= $c;
        $i++;
    }

    $s = trim($cur);
    if ($s !== '')
        $out[] = $s;

    return $out;
}

// ── Arranque del contenedor ───────────────────────────────────

/** @return string '' si todo bien, o el motivo del fallo. */
function iarepo_it_container_up(): string
{
    $cont  = iarepo_it_cfg('DB_CONT', 'iarepo_test_db');
    $port  = iarepo_it_cfg('DB_PORT', '3398');
    $image = iarepo_it_cfg('DB_IMAGE', 'mariadb:11');
    $state = &iarepo_it_state();

    $insp = iarepo_it_sh('docker inspect -f {{.State.Running}} ' . escapeshellarg($cont), 15);

    if ($insp['code'] !== 0) {
        // No existe → lo creamos nosotros (y por tanto podemos borrarlo).
        $run = iarepo_it_sh(
            'docker run -d'
            . ' --name ' . escapeshellarg($cont)
            . ' -e MARIADB_ROOT_PASSWORD=' . escapeshellarg(IAREPO_IT_PASS)
            . ' -e MARIADB_DATABASE=' . escapeshellarg(iarepo_it_cfg('DB_NAME', 'iarepo_test'))
            . ' -p 127.0.0.1:' . escapeshellarg($port) . ':3306'
            . ' ' . escapeshellarg($image),
            180
        );
        if ($run['code'] !== 0)
            return "no se pudo crear el contenedor '$cont' con la imagen '$image': " . $run['out'];

        $state['created'] = true;
        return '';
    }

    if (trim($insp['out']) !== 'true') {
        $st = iarepo_it_sh('docker start ' . escapeshellarg($cont), 60);
        if ($st['code'] !== 0)
            return "el contenedor '$cont' existe pero no arranca: " . $st['out'];
        return '';
    }

    // Ya corriendo: comprobamos que el puerto publicado es el que esperamos.
    $p = iarepo_it_sh('docker port ' . escapeshellarg($cont) . ' 3306/tcp', 15);
    if ($p['code'] === 0 && $p['out'] !== '' && !preg_match('/:' . preg_quote($port, '/') . '\b/', $p['out']))
        return "el contenedor '$cont' ya existe pero publica '{$p['out']}' en vez del puerto $port"
            . " (bórralo con: docker rm -f $cont)";

    return '';
}

/** Espera a que el servidor acepte conexiones. @return string '' u motivo. */
function iarepo_it_wait(int $seconds): string
{
    $port = (int) iarepo_it_cfg('DB_PORT', '3398');
    $dsn  = "mysql:host=127.0.0.1;port=$port;charset=utf8mb4";
    $t0   = microtime(true);
    $last = '';

    while (microtime(true) - $t0 < $seconds) {
        try {
            new PDO($dsn, 'root', IAREPO_IT_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            return '';
        } catch (PDOException $e) {
            $last = $e->getMessage();
            usleep(250000);
        }
    }

    return "el servidor no aceptó conexiones en {$seconds}s (127.0.0.1:$port): $last";
}

// ── Construcción del esquema ──────────────────────────────────

/**
 * Ficheros de esquema del repo, EN EL ORDEN EN QUE SE APLICAN.
 * schema.sql y schema_users.sql primero; después las migraciones por
 * nombre (que es el orden en que las aplicaría un humano por SSH).
 * Los seed_*.sql de setup/ NO se cargan: los datos los pone
 * tests/fixtures/seed.sql, que es determinista.
 *
 * @return string[] rutas absolutas
 */
function iarepo_it_schema_files(): array
{
    $s     = iarepo_it_root() . '/setup';
    $migs  = glob("$s/migration_*.sql") ?: [];
    sort($migs, SORT_STRING);
    return array_merge(["$s/schema.sql", "$s/schema_users.sql"], $migs);
}

/**
 * Aplica el esquema del repo sobre una BD recién creada.
 *
 * NO aborta al primer error (a diferencia de setup/run_migration.php):
 * ejecuta TODAS las sentencias y devuelve el parte de bajas, que es
 * justo lo que audita tests/integration/schema_drift_test.php.
 *
 * @return array{ok:int,failed:array<int,array{file:string,index:int,sql:string,error:string}>}
 */
function iarepo_it_build_schema(PDO $pdo): array
{
    $ok     = 0;
    $failed = [];

    foreach (iarepo_it_schema_files() as $file) {
        if (!is_file($file))
            continue;
        foreach (iarepo_it_split_sql((string) file_get_contents($file)) as $k => $stmt) {
            try {
                $pdo->exec($stmt);
                $ok++;
            } catch (PDOException $e) {
                $failed[] = [
                    'file'  => basename($file),
                    'index' => $k + 1,
                    'sql'   => substr((string) preg_replace('/\s+/', ' ', $stmt), 0, 120),
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    return ['ok' => $ok, 'failed' => $failed];
}

/**
 * Siembra tests/fixtures/seed.sql y COMPRUEBA que ha aterrizado entero.
 *
 * Sin esta comprobación, una deriva de esquema que rompa el corpus (p.ej.
 * que migration_001 deje de sembrar categories) degradaría los tests en
 * silencio: seguirían verdes buscando sobre un corpus incompleto.
 */
function iarepo_it_seed(PDO $pdo): void
{
    $file = iarepo_it_root() . '/tests/fixtures/seed.sql';
    if (!is_file($file))
        throw new RuntimeException("Falta el corpus de fixtures: $file");

    foreach (iarepo_it_split_sql((string) file_get_contents($file)) as $stmt)
        $pdo->exec($stmt);

    $filas = (int) $pdo->query('SELECT COUNT(*) FROM resources WHERE id BETWEEN 1000 AND 1099')->fetchColumn();
    if ($filas !== 25)
        throw new RuntimeException("El corpus debía sembrar 25 recursos y sembró $filas");

    $sinCat = (int) $pdo->query(
        'SELECT COUNT(*) FROM resources WHERE id BETWEEN 1000 AND 1099 AND category_id IS NULL'
    )->fetchColumn();
    if ($sinCat !== 0)
        throw new RuntimeException(
            "$sinCat recursos del corpus se han quedado sin category_id: "
            . 'las categorías de migration_001 no se han sembrado'
        );

    $tags = (int) $pdo->query('SELECT COUNT(*) FROM resource_tags WHERE resource_id BETWEEN 1000 AND 1099')->fetchColumn();
    if ($tags !== 9)
        throw new RuntimeException("El corpus debía sembrar 9 etiquetas y sembró $tags");
}

// ── Punto de entrada ──────────────────────────────────────────

/**
 * Conexión a la BD de integración, ya con esquema y fixtures cargados.
 * Devuelve null si la suite debe saltarse (motivo en iarepo_it_skip_reason()).
 */
function iarepo_it_db(): ?PDO
{
    $state = &iarepo_it_state();
    if ($state['tried'])
        return $state['pdo'];

    $state['tried'] = true;
    $t0 = microtime(true);

    if (iarepo_it_cfg('SKIP', '') !== '') {
        $state['skip'] = 'IAREPO_TEST_SKIP está definido';
        return null;
    }
    if (!iarepo_it_docker_ok()) {
        $state['skip'] = 'Docker no está disponible (¿demonio parado o sin permisos?)';
        return null;
    }

    if (($why = iarepo_it_container_up()) !== '') {
        $state['skip'] = $why;
        return null;
    }
    if (($why = iarepo_it_wait((int) iarepo_it_cfg('DB_WAIT', '60'))) !== '') {
        $state['skip'] = $why;
        return null;
    }

    $port = (int) iarepo_it_cfg('DB_PORT', '3398');
    $name = iarepo_it_cfg('DB_NAME', 'iarepo_test');

    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', IAREPO_IT_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Igual que shared/db.php: sin emulación, '?' es posicional DE VERDAD.
            // Con emulación activada varios bugs de orden de parámetros no se ven.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Idempotencia: se puede correr dos veces seguidas porque la BD se
        // tira y se rehace. El contenedor sobrevive; los datos no.
        $pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");

        $state['schema'] = iarepo_it_build_schema($pdo);
        iarepo_it_seed($pdo);
    } catch (Throwable $e) {
        // OJO: esto NO es "falta infraestructura", es "el repo no puede
        // construir su propia BD" — un defecto. Se salta el resto de la
        // suite para no escupir 30 errores en cascada, pero
        // test_it_bootstrap_pudo_preparar_la_bd() se pone ROJO con el motivo.
        $state['prep_error'] = $e->getMessage();
        $state['skip']       = 'el esquema/corpus no se pudo preparar: ' . $e->getMessage();
        return null;
    }

    $state['pdo']     = $pdo;
    $state['boot_ms'] = (microtime(true) - $t0) * 1000;
    return $pdo;
}

function iarepo_it_skip_reason(): ?string
{
    $s = &iarepo_it_state();
    return $s['skip'];
}

/**
 * Motivo por el que NO se pudo construir el esquema/corpus, o null.
 * A diferencia de iarepo_it_skip_reason(), esto es siempre un defecto del
 * repo (no "falta Docker") y debe ponerse rojo.
 */
function iarepo_it_prep_error(): ?string
{
    iarepo_it_db();
    $s = &iarepo_it_state();
    return $s['prep_error'];
}

/** Informe de carga del esquema del repo (null si no se llegó a cargar). */
function iarepo_it_schema_report(): ?array
{
    iarepo_it_db();
    $s = &iarepo_it_state();
    return $s['schema'];
}

function iarepo_it_created_container(): bool
{
    $s = &iarepo_it_state();
    return $s['created'];
}

function iarepo_it_boot_ms(): float
{
    $s = &iarepo_it_state();
    return $s['boot_ms'];
}

/**
 * Marca un test como saltado. Imprime el motivo UNA sola vez por proceso
 * (si no, 30 tests escupen 30 veces la misma línea) y retorna false para
 * poder escribir:  if (!($db = it_db_or_skip(__FUNCTION__))) return;
 */
function iarepo_it_skip(string $test): void
{
    $s      = &iarepo_it_state();
    $reason = $s['skip'] ?? 'motivo desconocido';

    if (!isset($s['skips_seen'][$reason])) {
        $s['skips_seen'][$reason] = 0;
        fwrite(STDOUT, "\n  ⏭  SUITE DE INTEGRACIÓN SALTADA: $reason\n"
            . "     (no es un fallo; levántala con: docker version && php tests/integration/_runner.php)\n\n");
    }
    $s['skips_seen'][$reason]++;
}

/** Azúcar: devuelve el PDO o null habiendo ya impreso el SKIP. */
function it_db_or_skip(string $test = ''): ?PDO
{
    $db = iarepo_it_db();
    if ($db === null)
        iarepo_it_skip($test);
    return $db;
}

// ================================================================
// RÉPLICA DE LA CONSULTA DE api/resources.php (rama GET lista)
//
// Reproduce literalmente api/resources.php:68-200: mismo WHERE, mismos
// filtros, mismo COUNT, mismo ORDER BY, mismo orden de parámetros
// (score_params ANTES que params). Es lo que permite testear el SQL
// real sin levantar un servidor HTTP ni tocar .env.php.
//
// RIESGO ASUMIDO Y CONSCIENTE: es una copia, no el código de producción.
// Si alguien cambia api/resources.php y no toca esto, los tests siguen
// verdes sobre la versión vieja. Mitigación: schema_drift_test.php lee
// api/resources.php de verdad y verifica sus columnas.
// ================================================================

/** Equivalente a iarepo_get_str() de api/resources.php:568 sobre un array dado. */
function iarepo_it_getstr(array $q, string $key): string
{
    $v = $q[$key] ?? '';
    return is_scalar($v) ? (string) $v : '';
}

/**
 * @param array      $q     parámetros "GET" (search, area, lang, level, type,
 *                          tag, category, visibility, author_tenant_id, sort,
 *                          page, limit)
 * @param array|null $user  null = anónimo; si no ['tenant_id'=>, 'user_id'=>]
 *
 * @return array{total:int,page:int,pages:int,ids:int[],rows:array,search:array,sort:string,sql:string}
 */
function iarepo_it_api_list(PDO $db, array $q, ?array $user = null): array
{
    $where  = ['r.is_active = 1', "(r.link_status IS NULL OR r.link_status != 'broken')"];
    $params = [];

    // ── Visibilidad ───────────────────────────────────────────
    if ($user) {
        $where[] = "((r.visibility = 'community')"
            . " OR (r.visibility = 'school' AND r.author_tenant_id = ?)"
            . " OR (r.visibility = 'area' AND r.author_tenant_id = ?)"
            . " OR (r.visibility = 'draft' AND r.author_tenant_id = ? AND r.author_user_id = ?))";
        $params[] = $user['tenant_id'] ?? 0;
        $params[] = $user['tenant_id'] ?? 0;
        $params[] = $user['tenant_id'] ?? 0;
        $params[] = $user['user_id'] ?? 0;
    } else {
        $where[] = "r.visibility = 'community'";
    }

    // ── Filtros opcionales ────────────────────────────────────
    $simple = ['area' => ['r.subject_area', 100], 'lang' => ['r.lang', 5],
               'level' => ['r.level', 50], 'type' => ['r.code_type', 20],
               'visibility' => ['r.visibility', 20]];
    foreach ($simple as $key => [$col, $len]) {
        if (iarepo_it_getstr($q, $key) !== '') {
            $where[]  = "$col = ?";
            $params[] = mb_substr(trim(iarepo_it_getstr($q, $key)), 0, $len);
        }
    }
    if ((int) iarepo_it_getstr($q, 'category') > 0) {
        $where[]  = 'r.category_id = ?';
        $params[] = (int) iarepo_it_getstr($q, 'category');
    }
    if (iarepo_it_getstr($q, 'tag') !== '') {
        $where[]  = 'r.id IN (SELECT resource_id FROM resource_tags WHERE tag = ?)';
        $params[] = mb_substr(trim(iarepo_it_getstr($q, 'tag')), 0, 50);
    }
    if ((int) iarepo_it_getstr($q, 'author_tenant_id') > 0) {
        $where[]  = 'r.author_tenant_id = ?';
        $params[] = (int) iarepo_it_getstr($q, 'author_tenant_id');
    }

    // ── Búsqueda ──────────────────────────────────────────────
    $search    = iarepo_build_search(iarepo_it_getstr($q, 'search'));
    $hasSearch = $search['mode'] !== 'none';
    if ($hasSearch) {
        $where[] = $search['where'];
        foreach ($search['params'] as $p)
            $params[] = $p;
    }

    // ── Orden ─────────────────────────────────────────────────
    // Réplica LITERAL del bloque "── Sort ──" de api/resources.php, que es la
    // pieza que manda. Regla: un 'sort' que no esté en la lista blanca cuenta
    // como AUSENTE, no como elección explícita — así '?sort=bogus&search=x'
    // ordena por relevancia (igual que index.php), no por fecha.
    // Si esta réplica se desincroniza, este doble deja de probar la API real:
    // los tests seguirían verdes defendiendo un contrato muerto.
    $sortAllowed   = ['relevance', 'recent', 'popular', 'views', 'title'];
    $sortRaw       = iarepo_it_getstr($q, 'sort');
    if (!in_array($sortRaw, $sortAllowed, true)) $sortRaw = '';
    $sortKey       = $sortRaw !== '' ? $sortRaw : ($hasSearch ? 'relevance' : 'recent');
    $useRelevance  = ($sortKey === 'relevance' && $hasSearch);
    $sort = match (true) {
        $useRelevance          => '_relevance DESC, r.view_count DESC, r.id DESC',
        $sortKey === 'popular' => 'r.use_count DESC, r.id DESC',
        $sortKey === 'views'   => 'r.view_count DESC, r.id DESC',
        $sortKey === 'title'   => 'r.title ASC, r.id ASC',
        default                => 'r.created_at DESC, r.id DESC',
    };
    $sortEffective = $useRelevance
        ? 'relevance'
        : (in_array($sortKey, ['popular', 'views', 'title'], true) ? $sortKey : 'recent');

    // ── Paginación ────────────────────────────────────────────
    $page   = max(1, (int) ($q['page'] ?? 1));
    $limit  = min(100, max(10, (int) ($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    // OJO: la API acota limit a [10,100]. Para poder testear paginación con
    // páginas pequeñas se admite un limit crudo SÓLO desde los tests.
    if (isset($q['_raw_limit'])) {
        $limit  = max(1, (int) $q['_raw_limit']);
        $offset = ($page - 1) * $limit;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM resources r WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $relSelect  = '';
    $pageParams = $params;
    if ($useRelevance) {
        $relSelect  = ",\n               " . $search['score'] . ' AS _relevance';
        $pageParams = array_merge($search['score_params'], $params);
    }

    $sql = "
        SELECT r.id, r.title, r.description, r.code_type, r.subject_area, r.topic_tag,
               r.lang, r.level, r.category_id, r.view_count, r.source_prompt,
               r.author_display_name, r.author_tenant_name, r.visibility,
               r.current_version, r.use_count, r.fork_count, r.fork_of,
               r.source_name, r.source_url,
               r.created_at, r.updated_at,
               c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
               (SELECT COUNT(*) FROM resource_likes rl WHERE rl.resource_id = r.id) AS like_count,
               (SELECT GROUP_CONCAT(tag ORDER BY tag SEPARATOR ',') FROM resource_tags rt WHERE rt.resource_id = r.id) AS tags_csv{$relSelect}
        FROM resources r
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE $whereSQL
        ORDER BY $sort
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($pageParams);
    $rows = $stmt->fetchAll();

    return [
        'total'  => $total,
        'page'   => $page,
        'pages'  => (int) ceil($total / max(1, $limit)),
        'ids'    => array_map(static fn($r) => (int) $r['id'], $rows),
        'rows'   => $rows,
        'search' => ['mode' => $search['mode'], 'terms' => $search['terms'], 'debug' => $search['debug']],
        'sort'   => $sortEffective,
        'sql'    => $sql,
    ];
}

/** Atajo: sólo los ids devueltos por una búsqueda anónima. @return int[] */
function iarepo_it_ids(PDO $db, string $search, array $extra = []): array
{
    return iarepo_it_api_list($db, array_merge(['search' => $search, '_raw_limit' => 100], $extra))['ids'];
}

// ================================================================
// ASSERTS
//
// Prefijo it_* propio para NO chocar con los del runner común
// (tests/run.php define los suyos: assert_eq, assert_true...).
// Un fallo lanza AssertionError, que cualquier runner cuenta como
// fallo del test sin necesidad de conocer este archivo.
// ================================================================

function iarepo_it_show(mixed $v): string
{
    if (is_array($v))
        return '[' . implode(', ', array_map('iarepo_it_show', $v)) . ']';
    if (is_bool($v))
        return $v ? 'true' : 'false';
    if ($v === null)
        return 'null';
    return is_string($v) ? "'" . $v . "'" : (string) $v;
}

function it_true(bool $cond, string $msg): void
{
    if (!$cond)
        throw new AssertionError($msg);
}

function it_eq(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual)
        throw new AssertionError(
            $msg . "\n      esperado: " . iarepo_it_show($expected)
                 . "\n      obtenido: " . iarepo_it_show($actual)
        );
}

/** Compara conjuntos de ids sin importar el orden. */
function it_same_set(array $expected, array $actual, string $msg): void
{
    $e = array_values(array_unique(array_map('intval', $expected)));
    $a = array_values(array_unique(array_map('intval', $actual)));
    sort($e);
    sort($a);
    if ($e !== $a)
        throw new AssertionError(
            $msg . "\n      esperado: " . iarepo_it_show($e)
                 . "\n      obtenido: " . iarepo_it_show($a)
                 . "\n      sobran:   " . iarepo_it_show(array_values(array_diff($a, $e)))
                 . "\n      faltan:   " . iarepo_it_show(array_values(array_diff($e, $a)))
        );
}

function it_contains(array $haystack, int $needle, string $msg): void
{
    if (!in_array($needle, array_map('intval', $haystack), true))
        throw new AssertionError($msg . "\n      no está $needle en " . iarepo_it_show($haystack));
}

function it_not_contains(array $haystack, int $needle, string $msg): void
{
    if (in_array($needle, array_map('intval', $haystack), true))
        throw new AssertionError($msg . "\n      NO debía estar $needle en " . iarepo_it_show($haystack));
}

function it_first(array $ids, int $expected, string $msg): void
{
    $got = $ids[0] ?? 0;
    if ($got !== $expected)
        throw new AssertionError(
            $msg . "\n      primero esperado: $expected\n      primero obtenido: $got"
                 . "\n      orden completo:   " . iarepo_it_show($ids)
        );
}
