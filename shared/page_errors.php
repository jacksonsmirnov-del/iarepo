<?php
// ================================================================
// shared/page_errors.php — Errores de las PÁGINAS HTML: registrados y visibles
//
// ── EL PROBLEMA ───────────────────────────────────────────────
// Las páginas HTML no pueden cargar shared/helpers.php: su error_handler
// responde con JSON y deja la página a medias (CLAUDE.md §2.1). Pero sin él,
// un error en una página iba al log de PHP del hosting —que nadie mira— y la
// persona veía una página rota o en blanco. Así pasó tres meses el panel del
// autor (junio–septiembre de 2026) sin que nadie lo supiera.
//
// ── LO QUE HACE ESTE FICHERO (al incluirlo, sin llamar a nada) ─
//   1. Nunca enseña rutas ni trazas: display_errors = 0.
//   2. Todo aviso, excepción o error fatal:
//        · se escribe en el log de PHP con el formato estándar
//          ("PHP Warning:  … in F on line L"), que es lo que buscan
//          tests/integration/render_pages_test.php y cualquier grep;
//        · y se guarda en client_error_log con source = 'server:<fichero>',
//          la misma tabla que los errores de JavaScript. Así lo ven
//          admin/errors.php (el detalle) y api/health.php (el recuento de
//          las últimas 24 h, que publica sin autenticación).
//   3. Una excepción sin capturar o un error fatal NO dejan media página: la
//      salida va en un búfer, y si algo revienta se tira entera y se sirve un
//      500 con una página de error limpia y un código de referencia.
//
// ── REGLAS ────────────────────────────────────────────────────
//   · Se incluye LO PRIMERO en cada página HTML, antes de cualquier salida.
//   · Sin require de nada: abre su propia conexión solo si tiene que guardar
//     un error, con su propio try/catch. Si la BD tampoco responde, el error
//     queda al menos en el log de PHP. Nunca puede empeorar el fallo original.
//   · Tope de 10 filas por petición: un bucle con avisos no llena la tabla.
// ================================================================

if (!defined('IAREPO_PAGE_ERRORS')) {
    define('IAREPO_PAGE_ERRORS', true);

    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    /** Código corto para cruzar lo que ve la persona con lo registrado. */
    function iarepo_page_error_ref(): string
    {
        static $ref = null;
        return $ref ??= bin2hex(random_bytes(4));
    }

    /**
     * Registra un error de página: log de PHP + fila en client_error_log.
     * Nunca lanza.
     */
    function iarepo_page_error_record(string $label, string $message, string $file, int $line): void
    {
        static $saved = 0;

        $root = dirname(__DIR__);
        $rel  = str_starts_with($file, $root) ? ltrim(substr($file, strlen($root)), '/') : basename($file);
        error_log(sprintf('PHP %s:  %s in %s on line %d [ref %s]', $label, $message, $file, $line, iarepo_page_error_ref()));

        // Los tests unitarios lo definen para no tocar NUNCA una BD: en el clon
        // del mantenedor, .env.php podría apuntar a una base real.
        if (++$saved > 10 || defined('IAREPO_PAGE_ERRORS_NO_DB'))
            return;
        try {
            $env = @include $root . '/.env.php';
            if (!is_array($env) || empty($env['DB_HOST']))
                return;
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_NAME']),
                $env['DB_USER'], $env['DB_PASS'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
            $pdo->prepare('INSERT INTO client_error_log (message, source, lineno, page_url, user_agent) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    mb_substr("$label: $message [ref " . iarepo_page_error_ref() . ']', 0, 1000),
                    mb_substr('server:' . $rel, 0, 200),
                    $line,
                    mb_substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), 0, 500),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);
        } catch (Throwable $e) {
            error_log('[iarepo] no se pudo guardar el error de página: ' . $e->getMessage());
        }
    }

    /** Tira la salida a medias y sirve una página de error limpia (500). */
    function iarepo_page_error_render(): void
    {
        while (ob_get_level() > 0)
            ob_end_clean();

        $ref   = iarepo_page_error_ref();
        $title = function_exists('t') ? t('Algo ha fallado') : 'Algo ha fallado';
        $body  = function_exists('t')
            ? t('No hemos podido cargar esta página. El error ya ha quedado registrado; prueba de nuevo en unos minutos.')
            : 'No hemos podido cargar esta página. El error ya ha quedado registrado; prueba de nuevo en unos minutos.';
        $home  = function_exists('t') ? t('Volver al inicio') : 'Volver al inicio';
        $lang  = function_exists('lang') ? lang() : 'es';

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        } else {
            // Ya salió algo (no debería, con el búfer): cerrar los contextos de
            // texto crudo para que el aviso se lea como HTML y no como código.
            echo "\n</script></style></textarea>";
        }
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        echo '<!DOCTYPE html><html lang="' . $e($lang) . '"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta name="robots" content="noindex"><title>' . $e($title) . ' · iarepo</title>'
           . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#F6F7F9;color:#11141A;'
           . 'display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}main{max-width:32rem}'
           . 'h1{font-size:1.6rem;margin:0 0 .5rem}p{color:#444C5A;line-height:1.55}a{color:#6D28D9;font-weight:600}'
           . 'code{background:#EEF0F3;padding:2px 6px;border-radius:4px}</style></head><body><main>'
           . '<h1>' . $e($title) . '</h1><p>' . $e($body) . '</p>'
           . '<p><a href="/">' . $e($home) . '</a> · <code>ref ' . $e($ref) . '</code></p>'
           . '</main></body></html>';
    }

    set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
        // Respeta @ y error_reporting(). E_DEPRECATED es ruido de versión de
        // PHP, no un fallo de la página: queda en el log estándar, sin fila.
        if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true))
            return false;
        $label = match ($no) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE   => 'Notice',
            default                   => 'Error',
        };
        iarepo_page_error_record($label, $msg, $file, $line);
        return true;
    });

    set_exception_handler(static function (Throwable $ex): void {
        iarepo_page_error_record('Fatal error', 'Uncaught ' . get_class($ex) . ': ' . $ex->getMessage(), $ex->getFile(), $ex->getLine());
        iarepo_page_error_render();
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            iarepo_page_error_record('Fatal error', $err['message'], $err['file'], $err['line']);
            iarepo_page_error_render();
        }
    });

    // Búfer: si algo revienta a mitad, no sale media página.
    ob_start();
}
