<?php
// ================================================================
// tests/integration/_runner.php — Runner AUTÓNOMO de la capa 3
//
// Existe para poder correr la suite de integración antes de que
// tests/run.php (que mantiene otro agente) esté disponible, y para
// depurarla en aislamiento.
//
//   php tests/integration/_runner.php            corre toda la suite
//   php tests/integration/_runner.php search     sólo los test_* que casen
//   php tests/integration/_runner.php --down     borra el contenedor y sale
//
// Sale con 1 si algún test falla, 0 si todos pasan o si la suite se salta.
//
// ⚠️ SE AUTODESACTIVA SI LO INCLUYE OTRO SCRIPT. tests/run.php descubre
// los tests con glob('tests/integration/*.php') y también incluiría este
// fichero; si no se protegiese, la suite se ejecutaría dos veces (una
// anidada dentro de la otra). El nombre empieza por '_' y además no
// define ninguna función test_*, así que para el runner común es inerte.
// ================================================================

$iarepo_self   = realpath(__FILE__);
$iarepo_script = realpath($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? ''));

if ($iarepo_self !== $iarepo_script)
    return; // incluido por otro runner: no hacemos nada

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/bootstrap.php';

$args   = array_slice($_SERVER['argv'], 1);
$filtro = '';
foreach ($args as $a) {
    if ($a === '--down') {
        $cont = iarepo_it_cfg('DB_CONT', 'iarepo_test_db');
        $r = iarepo_it_sh('docker rm -f ' . escapeshellarg($cont), 60);
        echo $r['code'] === 0
            ? "🗑  Contenedor '$cont' eliminado.\n"
            : "No se pudo eliminar '$cont': {$r['out']}\n";
        exit($r['code'] === 0 ? 0 : 1);
    }
    if ($a[0] !== '-')
        $filtro = $a;
}

// ── Descubrimiento ────────────────────────────────────────────
$archivos = array_values(array_filter(
    glob(__DIR__ . '/*_test.php') ?: [],
    'is_file'
));
sort($archivos);

$antes = get_defined_functions()['user'];
foreach ($archivos as $f)
    require_once $f;
$nuevas = array_diff(get_defined_functions()['user'], $antes);

$tests = array_values(array_filter(
    $nuevas,
    static fn($fn) => str_starts_with($fn, 'test_') && ($filtro === '' || stripos($fn, $filtro) !== false)
));
sort($tests);

// ── Ejecución ─────────────────────────────────────────────────
echo "\n  Suite de integración — " . count($tests) . " tests en "
    . count($archivos) . " ficheros\n";
echo "  " . str_repeat('─', 62) . "\n";

$t0     = microtime(true);
$pasan  = 0;
$fallan = [];

foreach ($tests as $test) {
    $ti = microtime(true);
    try {
        $test();
        $ms = (microtime(true) - $ti) * 1000;
        printf("  ✓ %-58s %6.0f ms\n", $test, $ms);
        $pasan++;
    } catch (Throwable $e) {
        $ms = (microtime(true) - $ti) * 1000;
        printf("  ✗ %-58s %6.0f ms\n", $test, $ms);
        $fallan[$test] = $e;
    }
}

$segundos = microtime(true) - $t0;

echo "  " . str_repeat('─', 62) . "\n";

if ($fallan) {
    echo "\n  FALLOS\n";
    foreach ($fallan as $test => $e) {
        echo "\n  ✗ $test\n";
        echo "    " . get_class($e) . ': ' . str_replace("\n", "\n    ", $e->getMessage()) . "\n";
        if (!($e instanceof AssertionError)) {
            $orig = $e->getFile() . ':' . $e->getLine();
            echo "    en $orig\n";
        }
    }
    echo "\n";
}

printf("  %d/%d pasan · %.2f s%s\n",
    $pasan, count($tests), $segundos,
    iarepo_it_skip_reason() ? ' · SUITE SALTADA' : sprintf(' (arranque BD: %.0f ms)', iarepo_it_boot_ms()));

if (iarepo_it_created_container())
    echo "  El contenedor '" . iarepo_it_cfg('DB_CONT', 'iarepo_test_db')
        . "' se ha dejado vivo para la próxima ejecución.\n"
        . "  Bórralo con: php tests/integration/_runner.php --down\n";

echo "\n";
exit($fallan ? 1 : 0);
