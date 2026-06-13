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

/** Build a URL to switch language, preserving the current path. */
function langSwitchUrl(string $to): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $path . '?lang=' . ($to === 'en' ? 'en' : 'es');
}
