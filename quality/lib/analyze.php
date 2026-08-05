<?php
// ================================================================
// quality/lib/analyze.php — Analizadores PHP para quality/guards.sh
//
// NO es parte de la aplicación. Nunca se carga en producción.
// Se invoca solo desde quality/guards.sh (capa 1 del sistema anti-regresión).
//
// IMPORTANTE: este fichero NO debe requerir shared/helpers.php ni ningún
// módulo de la app. helpers.php carga error_handler.php, que registra
// manejadores que hacen echo json_encode(...) + exit(1); eso convertiría
// cualquier fallo del analizador en un blob JSON sin diagnóstico.
// (Única excepción: shared/i18n_en.php, que es un `return [...]` puro.)
//
// Uso:
//   php quality/lib/analyze.php close-tag  <fichero.php>...
//   php quality/lib/analyze.php html-pages <fichero.php>...
//   php quality/lib/analyze.php i18n       <fichero.php>...
//   php quality/lib/analyze.php extract-js <dir_salida> <fichero.php>...
//
// Salida: una línea por hallazgo, formato `ruta:linea<TAB>mensaje`.
// Exit code: 0 = sin hallazgos, 1 = hay hallazgos, 2 = error de uso.
//
// ── CONVENCIÓN DE ESCRITURA EN ESTE FICHERO ─────────────────────
// En los comentarios, la etiqueta de cierre de PHP se escribe SIEMPRE con un
// espacio en medio: «?  >». Escribirla junta dentro de un comentario de línea
// cerraría el modo PHP aquí mismo y rompería este analizador — que es
// justamente el fallo que detecta el check `close-tag`. Ocurrió de verdad al
// escribir este fichero: la primera versión reventó con
// «Parse error: unexpected token "..."». Se conserva la convención a propósito.
// ================================================================

declare(strict_types=1);

$cmd = $argv[1] ?? '';
$args = array_slice($argv, 2);

// La raíz del repo es el padre de quality/lib/.
$ROOT = dirname(__DIR__, 2);

/** Emite un hallazgo en el formato que consume guards.sh. */
function finding(string $file, int $line, string $msg): void
{
    echo $file . ':' . $line . "\t" . $msg . "\n";
}

/**
 * Devuelve el código fuente de $file con TODOS los comentarios sustituidos por
 * espacios en blanco (conservando saltos de línea, para no descuadrar los
 * números de línea). Sirve para buscar `require` reales y no comentados.
 */
function strip_comments(string $src): string
{
    $out = '';
    foreach (@token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                // Conserva solo los saltos de línea del comentario.
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

// ================================================================
// close-tag — detecta la etiqueta de cierre «?  >» en un comentario de LÍNEA
// ================================================================
//
// Por qué existe (regla crítica #2 del proyecto): `php -l` NO ve este fallo.
// Verificado en PHP 8.3: un fichero cuyo comentario de línea contiene la
// etiqueta de cierre seguida de texto pasa el lint ("No syntax errors
// detected") y, al ejecutarse, imprime el código fuente crudo en el navegador
// sin ejecutar nada. Fallo 100 % silencioso.
//
// PRECISIÓN — comprobado empíricamente en PHP 8.3, NO todos los comentarios
// son peligrosos (cierre escrito «?  >» según la convención de la cabecera):
//   «//  comentario ?  > fuga»       → PELIGROSO, cierra el modo PHP
//   «#   comentario ?  > fuga»       → PELIGROSO, cierra el modo PHP
//   «/*  bloque con ?  > dentro */»  → SEGURO, NO cierra el modo PHP
//   «/** docblock con ?  > */»       → SEGURO, ídem
// Por eso este check solo mira comentarios de LÍNEA. El grep ingenuo que suele
// proponerse para esto —una alternancia que incluye el patrón de comentario de
// bloque `/*` seguido del cierre— marca comentarios de bloque, que son seguros:
// sería una fuente permanente de falsos positivos sobre docblocks legítimos
// que documentan sintaxis PHP con ejemplos.
//
// Se usa el tokenizador de PHP en vez de grep para no marcar tampoco los
// cierres que viven dentro de cadenas, heredocs o expresiones regulares —
// donde tampoco cierran el modo PHP.
if ($cmd === 'close-tag') {
    $found = false;
    foreach ($args as $file) {
        $src = @file_get_contents($file);
        if ($src === false) continue;
        $tokens = @token_get_all($src);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_COMMENT) continue;
            // Solo comentarios de línea (// o #). Los de bloque son seguros.
            if (!preg_match('~^(//|#)~', $tok[1])) continue;
            // Si el comentario contiene un salto de línea, terminó por fin de
            // línea (caso normal). Si NO lo contiene, terminó por otra cosa:
            // el tokenizador lo cortó justo antes de un cierre «?  >» en la misma línea.
            if (strpos($tok[1], "\n") !== false) continue;
            // Confirmación: el token siguiente es el cierre de PHP.
            $next = $tokens[$i + 1] ?? null;
            if (!is_array($next) || $next[0] !== T_CLOSE_TAG) continue;

            finding(
                $file,
                $tok[2],
                "'?>' dentro de un comentario de linea: cierra el modo PHP y el "
                . "resto del fichero se imprime como texto plano. php -l NO lo detecta. "
                . "Comentario: " . trim($tok[1])
            );
            $found = true;
        }
    }
    exit($found ? 1 : 0);
}

// ================================================================
// html-pages — páginas HTML que cargan (directa o transitivamente) helpers.php
// ================================================================
//
// Regla crítica #1 del proyecto: shared/helpers.php hace
// `require_once error_handler.php`, que registra set_exception_handler /
// set_error_handler / register_shutdown_function. Esos manejadores hacen
// `echo json_encode(...)` y `exit(1)`. En una página HTML el resultado es un
// documento a medio renderizar con un blob JSON incrustado: el fallo silencioso
// que ya rompió el proyecto. En api/ es el comportamiento correcto y deseado.
//
// DEFINICIÓN de "página HTML" en este repo (deliberadamente estrecha para no
// generar falsos positivos):
//   Un .php es página HTML si emite '<!DOCTYPE html' como TEXTO LITERAL fuera
//   de PHP, es decir dentro de un token T_INLINE_HTML.
//
// Esa definición, y no un grep de '<!DOCTYPE html', es la que distingue bien
// los casos reales del repo:
//   - index.php, 404.php, resource/index.php, viewer/index.php...  → SÍ son
//     páginas: el DOCTYPE está en el markup, fuera de los bloques PHP.
//   - shared/mailer.php:61 → NO es página: devuelve '<!DOCTYPE html><html>...'
//     como cadena PHP dentro de una función (es una plantilla de email).
//     Un grep textual lo marcaría; el tokenizador no.
//   - api/*.php, cron/*.php, sitemap.php → NO son páginas: nunca emiten HTML
//     literal, así que jamás entran en este check aunque carguen helpers.php.
//
// Se sigue la cadena de require/include (cierre transitivo) porque el fallo
// también ocurre si una página carga un módulo que a su vez carga helpers.php.
// Hoy ningún módulo de shared/ lo hace, pero el día que alguien añada
// `require_once helpers.php` a shared/auth.php se romperían todas las páginas
// a la vez y un check solo-directo no lo vería.
if ($cmd === 'html-pages') {
    /** Resuelve los require/include de un fichero a rutas absolutas. */
    $requiresOf = static function (string $file) use ($ROOT): array {
        $src = @file_get_contents($file);
        if ($src === false) return [];
        $code = strip_comments($src);
        $deps = [];
        // Patrones reales del repo: require_once __DIR__ . '/../shared/x.php'
        // y require_once 'ruta.php'.
        $re = '~\b(?:require|include)(?:_once)?\s*\(?\s*'
            . '(?:__DIR__\s*\.\s*)?'
            . '([\'"])([^\'"]+)\1~';
        if (preg_match_all($re, $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $path = $hit[2];
                $abs = realpath(dirname($file) . '/' . ltrim($path, '/'));
                if ($abs === false) $abs = realpath($ROOT . '/' . ltrim($path, '/'));
                if ($abs !== false) $deps[] = $abs;
            }
        }
        return $deps;
    };

    $helpers = realpath($ROOT . '/shared/helpers.php');
    $memo = [];

    /** ¿Alcanza $file a helpers.php siguiendo requires? Devuelve la cadena. */
    $reaches = static function (string $file, array $seen = []) use (&$reaches, $requiresOf, $helpers, &$memo) {
        if ($helpers === false) return null;
        if (isset($seen[$file])) return null;
        $seen[$file] = true;
        if (array_key_exists($file, $memo)) return $memo[$file];
        $result = null;
        foreach ($requiresOf($file) as $dep) {
            if ($dep === $helpers) { $result = [$dep]; break; }
            $sub = $reaches($dep, $seen);
            if ($sub !== null) { $result = array_merge([$dep], $sub); break; }
        }
        $memo[$file] = $result;
        return $result;
    };

    $found = false;
    foreach ($args as $file) {
        $abs = realpath($file);
        if ($abs === false) continue;
        $src = @file_get_contents($abs);
        if ($src === false) continue;

        // ¿Es página HTML? DOCTYPE en T_INLINE_HTML.
        $isPage = false;
        $pageLine = 1;
        foreach (@token_get_all($src) as $tok) {
            if (is_array($tok) && $tok[0] === T_INLINE_HTML
                && stripos($tok[1], '<!DOCTYPE html') !== false) {
                $isPage = true;
                $pageLine = $tok[2];
                break;
            }
        }
        if (!$isPage) continue;

        $chain = $reaches($abs);
        if ($chain === null) continue;

        // Línea del require culpable, para que el mensaje sea accionable.
        $line = $pageLine;
        $code = strip_comments($src);
        $firstDep = basename($chain[0]);
        if (preg_match('~\b(?:require|include)(?:_once)?[^;\n]*' . preg_quote($firstDep, '~') . '~', $code, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
        }

        $rel = static fn(string $p): string => str_replace($ROOT . '/', '', $p);
        $via = count($chain) > 1
            ? ' (transitivamente via ' . implode(' -> ', array_map($rel, $chain)) . ')'
            : '';

        finding(
            str_replace($ROOT . '/', '', $abs),
            $line,
            "pagina HTML que carga shared/helpers.php" . $via
            . ". helpers.php registra manejadores que hacen echo json_encode()+exit; "
            . "en una pagina HTML eso emite un blob JSON dentro del markup. "
            . "Solucion: no cargar helpers.php y definir h() local (ver index.php:14, 404.php:9)."
        );
        $found = true;
    }
    exit($found ? 1 : 0);
}

// ================================================================
// i18n — literales t('...') sin entrada en shared/i18n_en.php
// ================================================================
//
// Regla #3 del proyecto: toda cadena visible va en t('español') y se añade a
// shared/i18n_en.php (las claves SON el español).
//
// Solo se miran literales de una sola cadena. t($var), t("Hola $nombre") y
// t('a' . 'b') se ignoran a propósito: no son resolubles estáticamente y
// marcarlos daría ruido sin señal.
if ($cmd === 'i18n') {
    $enFile = $ROOT . '/shared/i18n_en.php';
    if (!is_file($enFile)) {
        fwrite(STDERR, "analyze.php: no encuentro shared/i18n_en.php\n");
        exit(2);
    }
    /** @var array<string,string> $en */
    $en = require $enFile;
    if (!is_array($en)) {
        fwrite(STDERR, "analyze.php: shared/i18n_en.php no devuelve un array\n");
        exit(2);
    }

    // Lista de excepciones legítimas (una por línea, '#' = comentario).
    $ignore = [];
    $ignoreFile = $ROOT . '/quality/i18n_ignore.txt';
    if (is_file($ignoreFile)) {
        foreach (file($ignoreFile, FILE_IGNORE_NEW_LINES) as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#') continue;
            $ignore[$l] = true;
        }
    }

    $found = false;
    foreach ($args as $file) {
        // El propio módulo i18n y su diccionario no se auditan.
        $norm = str_replace($ROOT . '/', '', (string) realpath($file) ?: $file);
        if ($norm === 'shared/i18n_en.php' || $norm === 'shared/i18n.php') continue;

        $src = @file_get_contents($file);
        if ($src === false) continue;
        $code = strip_comments($src); // no auditar ejemplos en comentarios

        $re = '~\bt\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")\s*[,)]~';
        if (!preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE)) continue;

        $seen = [];
        foreach ($m[1] as $hit) {
            $lit = $hit[0];
            $raw = substr($lit, 1, -1);
            $val = $lit[0] === "'"
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], $raw)
                : stripcslashes($raw);
            if ($val === '' || isset($en[$val]) || isset($ignore[$val])) continue;

            $line = substr_count(substr($code, 0, $hit[1]), "\n") + 1;
            // Una misma cadena puede repetirse en la misma línea (p. ej. title=
            // y aria-label=); se reporta una sola vez.
            $key = $line . "\0" . $val;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            finding(
                $norm,
                $line,
                "t('" . $val . "') no tiene traduccion en shared/i18n_en.php. "
                . "Anade la clave, o si el ingles es identico anadela a quality/i18n_ignore.txt."
            );
            $found = true;
        }
    }
    exit($found ? 1 : 0);
}

// ================================================================
// extract-js — extrae los bloques <script> inline a ficheros .js sueltos
// ================================================================
//
// ~1.900 líneas de JS viven inline dentro de .php y hoy no las valida nada:
// `node --check` solo cubre los 3 .js de assets/. Un error de sintaxis en el
// bloque de index.php rompe favoritos, búsqueda y filtros de la portada, y el
// smoke test no lo ve (sigue habiendo 'class="fcard"' en el HTML).
//
// Las interpolaciones PHP de tipo «echo corto» se sustituyen por el literal
// neutro `null`, válido en posición de expresión —que es donde aparecen
// siempre en este repo (p. ej. `const T = {interpolacion};`).
// Verificado: los 14 bloques inline del repo validan tras la sustitución.
//
// Se saltan los <script> con src= (no tienen cuerpo) y los de type no-JS
// (application/ld+json). El JSON-LD NO es validable estáticamente: mezcla
// interpolaciones en posición de valor con condicionales PHP que emiten
// ESTRUCTURA (un `if` que aporta la coma entre dos objetos), así que ninguna
// sustitución textual produce JSON válido en todos los casos. Eso se valida
// post-deploy en el smoke test, contra la página ya renderizada.
if ($cmd === 'extract-js') {
    $outDir = $args[0] ?? '';
    $files = array_slice($args, 1);
    if ($outDir === '' || !is_dir($outDir)) {
        fwrite(STDERR, "uso: analyze.php extract-js <dir_salida> <ficheros...>\n");
        exit(2);
    }

    $n = 0;
    foreach ($files as $file) {
        $src = @file_get_contents($file);
        if ($src === false) continue;
        if (!preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m as $set) {
            $attrs = $set[1][0];
            $body = $set[2][0];

            if (preg_match('~\bsrc\s*=~i', $attrs)) continue;
            if (preg_match('~\btype\s*=\s*["\']?([^"\'\s>]+)~i', $attrs, $t)) {
                $type = strtolower($t[1]);
                $okTypes = ['text/javascript', 'application/javascript', 'module'];
                if (!in_array($type, $okTypes, true)) continue;
            }
            if (trim($body) === '') continue;

            $line = substr_count(substr($src, 0, $set[2][1]), "\n") + 1;

            // Neutraliza interpolaciones PHP (incluida una sin cerrar al final).
            $js = preg_replace('~<\?(?:=|php\b).*?\?>~s', 'null', $body);
            $js = preg_replace('~<\?(?:=|php\b).*$~s', 'null', (string) $js);

            $n++;
            file_put_contents($outDir . '/blk_' . $n . '.js', (string) $js);
            // El .src guarda el origen para poder mapear el error de node al fichero real.
            file_put_contents($outDir . '/blk_' . $n . '.src', $file . ':' . $line);
        }
    }
    echo $n . "\n";
    exit(0);
}

fwrite(STDERR, "uso: analyze.php {close-tag|html-pages|i18n|extract-js} ...\n");
exit(2);
