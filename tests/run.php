<?php
// ================================================================
// tests/run.php — Runner de tests de iarepo. CERO dependencias.
//
//   php tests/run.php                  unitarios (sin BD, < 5s)
//   php tests/run.php --integration    + suite con BD real
//   php tests/run.php --filter=search  solo lo que case con "search"
//   php tests/run.php --list           lista los tests sin ejecutarlos
//   php tests/run.php --verbose        muestra también los subcasos en verde
//   php tests/run.php --help
//
// Exit code 0 si todo pasa, 1 si algo falla (o si no hay tests que correr).
//
// ── PROTOCOLO DE UN FICHERO DE TEST ───────────────────────────
// Un fichero de test es un .php normal dentro de tests/unit/ o
// tests/integration/ que declara funciones globales llamadas test_*:
//
//     <?php
//     require_once IAREPO_ROOT . '/shared/search.php';
//
//     function test_algo_que_importa(): void {
//         assert_eq('hybrid', iarepo_build_search('ondas')['mode']);
//     }
//
// El runner incluye el fichero, detecta las funciones test_* que ha
// declarado (en orden de declaración) y las ejecuta. Sin clases, sin
// anotaciones, sin autoload. Un test pasa si NO lanza nada.
//
// Las aserciones (assert_eq, assert_true, subtest, ...) las define ESTE
// fichero antes de incluir nada, así que están disponibles en cualquier
// test sin ningún require. Ver "API de aserciones" más abajo.
//
// Constantes disponibles para los tests:
//   IAREPO_ROOT         raíz del repo (nunca uses rutas relativas: el
//                       runner puede invocarse desde cualquier cwd)
//   IAREPO_INTEGRATION  true si se pasó --integration
//
// ── POR QUÉ ESTE RUNNER PROHÍBE shared/helpers.php ────────────
// helpers.php arrastra shared/error_handler.php, que registra un
// set_exception_handler y un register_shutdown_function que hacen
// `echo json_encode(...)` y `exit(1)`. Dentro del runner eso convierte
// un test fallido en un blob JSON y mata el proceso SIN informe: los
// tests dirían "todo bien" porque no llegan a imprimir nada.
// Es la regla crítica #1 del proyecto, aquí aplicada al runner.
// Si algún test lo carga, el runner aborta con un mensaje explícito.
// Para probar código de helpers.php se usa un SUBPROCESO aislado
// (ver tests/unit/helpers_isolation_test.php).
//
// ── AVISOS DE PHP = FALLO ─────────────────────────────────────
// El runner instala su propio manejador de errores: cualquier warning o
// notice emitido durante un test lo hace fallar, con fichero y línea.
// (E_DEPRECATED se acumula y se informa al final, sin bloquear: depende
// de la versión de PHP y no es una regresión del repo.)
// ================================================================

// Este fichero se despliega dentro de public_html y tests/ NO está
// bloqueado en .htaccess: ejecutarlo por HTTP sería catastrófico.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

define('IAREPO_ROOT', dirname(__DIR__));

// ================================================================
// Argumentos
// ================================================================

$OPT = [
    'integration' => false,
    'filter'      => '',
    'list'        => false,
    'verbose'     => false,
    'color'       => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--integration')                 { $OPT['integration'] = true; continue; }
    if ($arg === '--list')                        { $OPT['list']        = true; continue; }
    if ($arg === '--verbose' || $arg === '-v')    { $OPT['verbose']     = true; continue; }
    if ($arg === '--no-color')                    { $OPT['color']       = false; continue; }
    if ($arg === '--color')                       { $OPT['color']       = true; continue; }
    if (str_starts_with($arg, '--filter='))       { $OPT['filter'] = substr($arg, 9); continue; }
    if ($arg === '--help' || $arg === '-h') {
        $doc = file(__FILE__);
        foreach (array_slice($doc, 1, 12) as $line) {
            echo preg_replace('/^\/\/ ?/', '', rtrim($line)), "\n";
        }
        exit(0);
    }
    fwrite(STDERR, "Argumento desconocido: {$arg}\nUsa --help.\n");
    exit(2);
}

define('IAREPO_INTEGRATION', $OPT['integration']);

// ── Color: solo si hay TTY y NO_COLOR no está puesto ─────────────
$USE_COLOR = $OPT['color'];
if ($USE_COLOR === null) {
    $USE_COLOR = getenv('NO_COLOR') === false
        && function_exists('stream_isatty')
        && @stream_isatty(STDOUT);
}
$C = $USE_COLOR
    ? ['r' => "\033[0;31m", 'g' => "\033[0;32m", 'y' => "\033[1;33m",
       'b' => "\033[0;34m", 'd' => "\033[2m",    'n' => "\033[0m"]
    : ['r' => '', 'g' => '', 'y' => '', 'b' => '', 'd' => '', 'n' => ''];

// ================================================================
// Estado del runner
// ================================================================

final class IarepoAssertionFailed extends Exception {}
final class IarepoSkipped extends Exception {}

$RUN = [
    'assertions'  => 0,
    'tests_ok'    => 0,
    'tests_fail'  => 0,
    'tests_skip'  => 0,
    'failures'    => [],   // [ ['test'=>, 'case'=>, 'msg'=>, 'where'=>] ]
    'deprecated'  => [],
    'current'     => '',   // test en curso, para el manejador de fatales
    'subcase'     => '',
    'subcases'    => 0,    // subcasos ejecutados por el test en curso
    'finished'    => false,
];

// ================================================================
// API de aserciones (global; disponible en todos los ficheros de test)
// ================================================================

/** Formatea un valor para el mensaje de error, acotado. */
function iarepo_show(mixed $v, int $max = 220): string
{
    if (is_string($v)) {
        $s = $v;
        // Los tests trabajan con bytes hostiles: hazlos visibles.
        if (!mb_check_encoding($s, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s)) {
            $s = '0x' . bin2hex($s);
        }
        $s = "'" . $s . "'";
    } else {
        $s = var_export($v, true);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    }
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
}

/** Marca una aserción como ejecutada. */
function iarepo_tick(): void
{
    $GLOBALS['RUN']['assertions']++;
}

/** Aborta el test en curso con un mensaje. */
function iarepo_bail(string $msg): never
{
    throw new IarepoAssertionFailed($msg);
}

function assert_true(mixed $cond, string $msg = ''): void
{
    iarepo_tick();
    if ($cond !== true && !(bool) $cond) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '') . 'esperaba verdadero, llegó ' . iarepo_show($cond));
    }
}

function assert_false(mixed $cond, string $msg = ''): void
{
    iarepo_tick();
    if ((bool) $cond) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '') . 'esperaba falso, llegó ' . iarepo_show($cond));
    }
}

/** Igualdad ESTRICTA (===). Para arrays compara tipo, orden y claves. */
function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    iarepo_tick();
    if ($expected !== $actual) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '')
            . 'esperaba ' . iarepo_show($expected) . ' pero llegó ' . iarepo_show($actual));
    }
}

function assert_neq(mixed $unexpected, mixed $actual, string $msg = ''): void
{
    iarepo_tick();
    if ($unexpected === $actual) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '') . 'esperaba algo distinto de ' . iarepo_show($actual));
    }
}

function assert_null(mixed $v, string $msg = ''): void
{
    iarepo_tick();
    if ($v !== null) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '') . 'esperaba null, llegó ' . iarepo_show($v));
    }
}

function assert_not_null(mixed $v, string $msg = ''): void
{
    iarepo_tick();
    if ($v === null) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '') . 'esperaba algo no nulo');
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    iarepo_tick();
    if (!str_contains($haystack, $needle)) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '')
            . iarepo_show($haystack) . ' no contiene ' . iarepo_show($needle));
    }
}

function assert_not_contains(string $needle, string $haystack, string $msg = ''): void
{
    iarepo_tick();
    if (str_contains($haystack, $needle)) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '')
            . iarepo_show($haystack) . ' NO debería contener ' . iarepo_show($needle));
    }
}

function assert_matches(string $pattern, string $subject, string $msg = ''): void
{
    iarepo_tick();
    if (!preg_match($pattern, $subject)) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '')
            . iarepo_show($subject) . ' no casa con ' . $pattern);
    }
}

function assert_not_matches(string $pattern, string $subject, string $msg = ''): void
{
    iarepo_tick();
    if (preg_match($pattern, $subject)) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '')
            . iarepo_show($subject) . ' NO debería casar con ' . $pattern);
    }
}

function assert_count(int $n, array $arr, string $msg = ''): void
{
    iarepo_tick();
    if (count($arr) !== $n) {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '')
            . 'esperaba ' . $n . ' elemento(s), llegaron ' . count($arr) . ': ' . iarepo_show($arr));
    }
}

/** El callable debe lanzar una excepción de la clase indicada. */
function assert_throws(callable $fn, string $class = 'Throwable', string $msg = ''): void
{
    iarepo_tick();
    try {
        $fn();
    } catch (IarepoAssertionFailed|IarepoSkipped $e) {
        throw $e; // nunca capturamos el control del propio runner
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            iarepo_bail(($msg !== '' ? $msg . ' — ' : '')
                . 'esperaba ' . $class . ', llegó ' . get_class($e) . ': ' . $e->getMessage());
        }
        return;
    }
    iarepo_bail(($msg !== '' ? $msg . ' — ' : '') . 'esperaba una excepción ' . $class . ' y no hubo ninguna');
}

/** El callable no debe imprimir NADA (las funciones puras no imprimen). */
function assert_no_output(callable $fn, string $msg = ''): void
{
    iarepo_tick();
    ob_start();
    try {
        $fn();
    } finally {
        $out = ob_get_clean();
    }
    if ($out !== '') {
        iarepo_bail(($msg !== '' ? $msg . ' — ' : '') . 'imprimió ' . iarepo_show($out));
    }
}

/** Falla el test explícitamente. */
function test_fail(string $msg): never
{
    iarepo_tick();
    iarepo_bail($msg);
}

/** Salta el test en curso (dependencia externa ausente, etc.). */
function test_skip(string $why): never
{
    throw new IarepoSkipped($why);
}

/**
 * Subcaso dentro de un test. Su gracia: si falla NO aborta el test, así
 * que un bucle sobre 300 entradas hostiles reporta TODAS las que fallan,
 * no solo la primera. El nombre aparece en el informe.
 */
function subtest(string $name, callable $fn): bool
{
    $prev = $GLOBALS['RUN']['subcase'];
    $GLOBALS['RUN']['subcase'] = $name;
    $GLOBALS['RUN']['subcases']++;
    try {
        $fn();
        return true;
    } catch (IarepoSkipped $e) {
        throw $e;
    } catch (IarepoAssertionFailed $e) {
        iarepo_record_failure($GLOBALS['RUN']['current'], $name, $e->getMessage(), iarepo_origin($e));
        return false;
    } catch (Throwable $e) {
        iarepo_record_failure(
            $GLOBALS['RUN']['current'],
            $name,
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile() . ':' . $e->getLine()
        );
        return false;
    } finally {
        $GLOBALS['RUN']['subcase'] = $prev;
    }
}

/**
 * Ejecuta código PHP en un proceso APARTE, con la raíz del repo como cwd.
 *
 * Para qué: hay código que no se puede cargar en el runner sin destruirlo
 * (shared/helpers.php y sus handlers) y estado que no se puede rebobinar
 * dentro de un proceso (el `static $lang` de shared/i18n.php: una vez
 * resuelto el idioma, ya no cambia). Un subproceso da un intérprete
 * limpio y además captura el código de salida.
 *
 * El código se pasa por STDIN, así que no hay comillas que escapar ni
 * ficheros temporales que limpiar.
 *
 * @param string $code PHP completo, empezando por la etiqueta de apertura
 * @return array{code:int,out:string,err:string}
 */
function iarepo_php_isolated(string $code): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, '-d', 'error_log='], $spec, $pipes, IAREPO_ROOT);
    if (!is_resource($proc)) {
        iarepo_bail('no se pudo lanzar un subproceso PHP');
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($proc), 'out' => $out, 'err' => $err];
}

// ================================================================
// Motor
// ================================================================

/** Primer frame de la traza que vive en un fichero de test. */
function iarepo_origin(Throwable $e): string
{
    foreach (array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace()) as $f) {
        if (!isset($f['file'])) {
            continue;
        }
        if (str_contains($f['file'], '/tests/') && !str_ends_with($f['file'], '/run.php')) {
            return iarepo_relpath($f['file']) . ':' . ($f['line'] ?? 0);
        }
    }
    return iarepo_relpath($e->getFile()) . ':' . $e->getLine();
}

function iarepo_relpath(string $abs): string
{
    return str_starts_with($abs, IAREPO_ROOT . '/') ? substr($abs, strlen(IAREPO_ROOT) + 1) : $abs;
}

function iarepo_record_failure(string $test, string $case, string $msg, string $where): void
{
    $GLOBALS['RUN']['failures'][] = ['test' => $test, 'case' => $case, 'msg' => $msg, 'where' => $where];
}

/**
 * Convierte cualquier warning/notice en fallo del test en curso.
 * Sin esto, un preg_replace que devuelve null por UTF-8 inválido pasaría
 * inadvertido y el test seguiría verde con datos basura.
 */
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return true; // silenciado con @
    }
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        $key = iarepo_relpath($file) . ':' . $line . ' ' . $message;
        $GLOBALS['RUN']['deprecated'][$key] = true;
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('assert.exception', '1');

/** Red de seguridad: un fatal (OOM, recursión, parse error de un test) no puede pasar por verde. */
register_shutdown_function(static function (): void {
    if ($GLOBALS['RUN']['finished']) {
        return;
    }
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $msg = 'ERROR FATAL — la suite NO se completó: '
             . $e['message'] . ' en ' . iarepo_relpath($e['file']) . ':' . $e['line'];
    } elseif ($GLOBALS['RUN']['current'] !== '') {
        $msg = 'La suite NO se completó durante ' . $GLOBALS['RUN']['current']
             . ' — un test ha llamado a exit()/die() y se ha llevado por delante el informe.';
    } else {
        $msg = 'La suite terminó sin imprimir el resumen (salida cortada, p. ej. por "| head").';
    }
    fwrite(STDERR, "\n\033[0;31m❌ {$msg}\033[0m\n");
    exit(1);
});

// ── Descubrimiento ──────────────────────────────────────────────

/** @return array<string,string[]> fichero => nombres de función test_* */
function iarepo_load_suite(string $dir): array
{
    $found = [];
    $files = glob($dir . '/*.php') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        if (str_starts_with(basename($file), '_')) {
            continue; // convención: _algo.php es un helper, no una suite
        }
        $before = get_defined_functions()['user'];
        (static function (string $__iarepo_file): void {
            require_once $__iarepo_file;
        })($file);
        $new = array_diff(get_defined_functions()['user'], $before);

        $tests = [];
        foreach ($new as $fn) {
            if (str_starts_with($fn, 'test_')) {
                $tests[] = $fn;   // get_defined_functions preserva el orden de declaración
            }
        }
        $found[iarepo_relpath($file)] = $tests;
    }
    return $found;
}

/**
 * Regla crítica #1 del proyecto, aplicada al runner: si helpers.php o
 * error_handler.php entran en el proceso, sus handlers harían exit(1)
 * al primer fallo y la suite mentiría.
 */
function iarepo_assert_no_error_handler(string $when): void
{
    foreach (get_included_files() as $f) {
        if (preg_match('#/shared/(helpers|error_handler)\.php$#', $f)) {
            fwrite(STDERR,
                "\n❌ ABORTADO: se ha cargado " . iarepo_relpath($f) . " ({$when}).\n"
                . "   error_handler.php registra un exception handler que hace exit(1) tras\n"
                . "   imprimir JSON: convertiría cualquier fallo en un runner mudo.\n"
                . "   Para probar código de helpers.php usa un subproceso aislado\n"
                . "   (patrón en tests/unit/helpers_isolation_test.php).\n");
            $GLOBALS['RUN']['finished'] = true; // el mensaje ya está dado
            exit(1);
        }
    }
}

// ── Carga ───────────────────────────────────────────────────────

$suites = [];
$unitDir = IAREPO_ROOT . '/tests/unit';
if (is_dir($unitDir)) {
    $suites += iarepo_load_suite($unitDir);
} else {
    fwrite(STDERR, "❌ No existe " . iarepo_relpath($unitDir) . "\n");
    $RUN['finished'] = true;
    exit(1);
}

$integrationNote = '';
if (IAREPO_INTEGRATION) {
    $intDir = IAREPO_ROOT . '/tests/integration';
    if (!is_dir($intDir)) {
        $integrationNote = 'tests/integration/ todavía no existe; solo se corren los unitarios';
    } else {
        $loaded = iarepo_load_suite($intDir);
        if (!$loaded) {
            $integrationNote = 'tests/integration/ está vacío; solo se corren los unitarios';
        }
        $suites += $loaded;
    }
}

iarepo_assert_no_error_handler('al incluir los ficheros de test');

// ── Filtro ──────────────────────────────────────────────────────

$plan = [];
$totalTests = 0;
foreach ($suites as $file => $tests) {
    $keep = [];
    foreach ($tests as $t) {
        if ($OPT['filter'] === '' || stripos($file . '::' . $t, $OPT['filter']) !== false) {
            $keep[] = $t;
        }
    }
    if ($keep) {
        $plan[$file] = $keep;
        $totalTests += count($keep);
    }
}

if ($OPT['list']) {
    foreach ($plan as $file => $tests) {
        foreach ($tests as $t) {
            echo $file, '::', $t, "\n";
        }
    }
    $RUN['finished'] = true;
    exit(0);
}

// ── Cabecera ────────────────────────────────────────────────────

$title = IAREPO_INTEGRATION ? 'unitarios + integración' : 'unitarios (sin BD)';
echo "\n{$C['y']}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$C['n']}\n";
echo "{$C['y']} iarepo Tests — {$title}{$C['n']}\n";
echo "{$C['d']} " . count($plan) . ' fichero(s) · ' . $totalTests . ' test(s)'
   . ($OPT['filter'] !== '' ? " · filtro \"{$OPT['filter']}\"" : '') . "{$C['n']}\n";
echo "{$C['y']}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$C['n']}\n";
if ($integrationNote !== '') {
    echo "{$C['y']}⚠ {$integrationNote}{$C['n']}\n";
}

if ($totalTests === 0) {
    echo "\n{$C['r']}❌ No hay ningún test que ejecutar"
       . ($OPT['filter'] !== '' ? " con el filtro \"{$OPT['filter']}\"" : '') . ".{$C['n']}\n\n";
    $RUN['finished'] = true;
    exit(1);
}

// ── Ejecución ───────────────────────────────────────────────────

$t0 = microtime(true);

foreach ($plan as $file => $tests) {
    echo "\n{$C['b']}── {$file} " . str_repeat('─', max(1, 46 - mb_strlen($file))) . "{$C['n']}\n";

    foreach ($tests as $fn) {
        $RUN['current']  = $file . '::' . $fn;
        $label           = substr($fn, 5);           // sin el prefijo test_
        $before          = $RUN['assertions'];
        $failsBefore     = count($RUN['failures']);
        $RUN['subcases'] = 0;
        $skipReason      = null;
        $ts              = microtime(true);

        try {
            $fn();
        } catch (IarepoSkipped $e) {
            $skipReason = $e->getMessage();
        } catch (IarepoAssertionFailed $e) {
            iarepo_record_failure($RUN['current'], '', $e->getMessage(), iarepo_origin($e));
        } catch (Throwable $e) {
            iarepo_record_failure(
                $RUN['current'],
                '',
                get_class($e) . ': ' . $e->getMessage(),
                iarepo_origin($e)
            );
        }

        $ms      = (microtime(true) - $ts) * 1000;
        $n       = $RUN['assertions'] - $before;
        $newFail = array_slice($RUN['failures'], $failsBefore);
        $stat    = sprintf('%5d aserc. %7.1fms', $n, $ms);
        if ($OPT['verbose'] && $RUN['subcases'] > 0) {
            $stat .= sprintf(' · %d subcasos', $RUN['subcases']);
        }

        if ($skipReason !== null) {
            $RUN['tests_skip']++;
            echo "  {$C['y']}⊘{$C['n']} {$C['d']}" . str_pad($label, 48) . " saltado{$C['n']}\n";
            echo "      {$C['d']}↳ {$skipReason}{$C['n']}\n";
        } elseif ($newFail) {
            $RUN['tests_fail']++;
            echo "  {$C['r']}✗{$C['n']} " . str_pad($label, 48) . " {$C['d']}{$stat}{$C['n']}\n";
            foreach (array_slice($newFail, 0, 8) as $f) {
                $case = $f['case'] !== '' ? "{$C['y']}[{$f['case']}]{$C['n']} " : '';
                echo "      {$C['r']}↳{$C['n']} {$case}{$f['msg']}\n";
                echo "        {$C['d']}{$f['where']}{$C['n']}\n";
            }
            if (count($newFail) > 8) {
                echo "      {$C['d']}… y " . (count($newFail) - 8) . " subcaso(s) más en rojo{$C['n']}\n";
            }
        } else {
            $RUN['tests_ok']++;
            echo "  {$C['g']}✓{$C['n']} " . str_pad($label, 48) . " {$C['d']}{$stat}{$C['n']}\n";
        }
    }
}

$RUN['current'] = '';
$elapsed = microtime(true) - $t0;

iarepo_assert_no_error_handler('durante la ejecución de los tests');

// ── Resumen ─────────────────────────────────────────────────────

if ($RUN['deprecated']) {
    echo "\n{$C['y']}⚠ Avisos de obsolescencia de PHP " . PHP_VERSION . " (no bloquean):{$C['n']}\n";
    foreach (array_slice(array_keys($RUN['deprecated']), 0, 5) as $d) {
        echo "   {$C['d']}{$d}{$C['n']}\n";
    }
}

$failedTests = $RUN['tests_fail'];
echo "\n{$C['y']}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$C['n']}\n";

if ($failedTests === 0) {
    echo "{$C['g']}✅ {$RUN['tests_ok']} test(s) en verde{$C['n']}"
       . ($RUN['tests_skip'] ? " · {$RUN['tests_skip']} saltado(s)" : '')
       . " · {$RUN['assertions']} aserciones · "
       . sprintf('%.2fs', $elapsed) . "\n\n";
    $RUN['finished'] = true;
    exit(0);
}

echo "{$C['r']}❌ {$failedTests} de {$totalTests} test(s) en rojo{$C['n']}"
   . ($RUN['tests_skip'] ? " · {$RUN['tests_skip']} saltado(s)" : '')
   . " · " . count($RUN['failures']) . " aserción(es) fallidas de {$RUN['assertions']} · "
   . sprintf('%.2fs', $elapsed) . "\n";
echo "{$C['d']}Repite solo lo que falla:  php tests/run.php --filter="
   . explode('::', $RUN['failures'][0]['test'])[1] . "{$C['n']}\n\n";

$RUN['finished'] = true;
exit(1);
