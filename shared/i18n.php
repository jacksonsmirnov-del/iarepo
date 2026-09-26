<?php
// ================================================================
// shared/i18n.php — Lightweight bilingual (ES/EN) helper
//
// Spanish strings ARE the keys; English overrides live in i18n_en.php.
// Safe to include in HTML pages (no dependency on helpers.php).
//
// Usage (call lang() once at the TOP of the page, before any output,
// so the cookie can persist):
//   require_once __DIR__.'/../shared/i18n.php'; lang();
//   then in the markup:  echo t('Recursos educativos');
// ================================================================

require_once __DIR__ . '/local_path.php';   // iarepo_is_local_path(), sin dependencias

/** Resolve and (when possible) persist the active language: 'es' | 'en'. */
function lang(): string
{
    static $lang = null;
    if ($lang !== null) return $lang;

    $l = strtolower(substr((string) ($_GET['lang'] ?? $_COOKIE['lang'] ?? ''), 0, 2));
    if (!in_array($l, ['es', 'en'], true)) {
        $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $l = str_starts_with($accept, 'en') ? 'en' : 'es';
    }

    // Persist an explicit choice (only if headers not yet sent).
    if (isset($_GET['lang']) && in_array($l, ['es', 'en'], true) && !headers_sent()) {
        setcookie('lang', $l, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        $_COOKIE['lang'] = $l;
    }

    return $lang = $l;
}

/** Translate a Spanish source string to the active language. */
function t(string $es): string
{
    static $dict = null;
    if (lang() === 'es') return $es;
    if ($dict === null) {
        $f = __DIR__ . '/i18n_en.php';
        $dict = is_file($f) ? require $f : [];
    }
    return $dict[$es] ?? $es;
}

/**
 * Enlace para cambiar de idioma SIN perder dónde estás: misma ruta y misma
 * query, con 'lang' cambiado (y siempre el primero: index.php::syncLangSwitch
 * corta por el primer '&' para añadir el estado del buscador).
 *
 * Antes tiraba la query entera. Mientras solo lo usaban la portada y la ficha
 * daba igual; desde que la cabecera común (shared/ui.php) lo pone en TODAS las
 * páginas, pulsar «EN» en una lista (/collection/?id=5) llevaba a la portada,
 * en el editor abría un formulario vacío, en la baja de correos invalidaba el
 * enlace y en Entrar perdía a dónde volver [revisión 2026-09].
 *
 * La query sale de REQUEST_URI (lo que pidió el navegador), NO de $_GET: las
 * rutas bonitas (/resource/3 → resource/index.php?id=3) añaden claves que la
 * URL no lleva. Nunca refleja el idioma recibido ('en' o 'es'), y una ruta que
 * no es propia ('//otra.web', barras invertidas, controles) cae a '/'.
 */
function langSwitchUrl(string $to): string
{
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $cut  = strpos($uri, '?');
    $path = $cut === false ? $uri : substr($uri, 0, $cut);
    $qs   = $cut === false ? '' : substr($uri, $cut + 1);
    if (!iarepo_is_local_path($path))
        $path = '/';
    $query = [];
    parse_str($qs, $query);
    unset($query['lang']);
    return $path . '?' . http_build_query(['lang' => $to === 'en' ? 'en' : 'es'] + $query, '', '&', PHP_QUERY_RFC3986);
}
