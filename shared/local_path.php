<?php
// ================================================================
// shared/local_path.php — ¿Es esto una ruta DE AQUÍ? (redirecciones abiertas)
//
// Un return_url («vuelve a donde estabas tras entrar») acaba en una cabecera
// Location y en enlaces. Si admite algo que el navegador interpreta como otra
// web, cualquiera puede fabricar un enlace de iarepo.com que acabe en la suya
// (con la cara de iarepo delante, justo lo que busca un phishing).
//
// La trampa que se coló [revisión 2026-09]: las tres copias de safeLocalPath
// (signin, onboarding y google) comprobaban `^/[^/\\]` DESPUÉS de decodificar,
// y un TABULADOR en la segunda posición pasaba el filtro. El navegador borra
// tabuladores y saltos de línea de una URL, así que «/\t/evil.example» se
// convierte en «//evil.example»: otra web. Con el «Entrar» de la cabecera
// común (que mete la URL actual en return_url) bastaba un enlace a
// https://iarepo.com/%09/evil.example.
//
// Regla, UNA vez para todo el sitio:
//   · empieza por «/» y el segundo carácter no es «/» ni «\»;
//   · ningún carácter de control (\x00-\x1F, \x7F) ni «\» en ningún sitio
//     (los navegadores tratan «\» como «/» en URLs http).
// El espacio sí se admite (una búsqueda con dos palabras) y se devuelve
// codificado.
//
// Sin dependencias: lo cargan páginas HTML, la API y shared/i18n.php.
// Antirregresión: tests/unit/account_pages_test.php (ejecuta estas funciones
// con entradas hostiles).
// ================================================================

/** ¿$path es una ruta local, tal cual (sin decodificar)? */
function iarepo_is_local_path(string $path): bool
{
    return (bool) preg_match('#^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*$#', $path);
}

/**
 * return_url saneado: la ruta local que traía (decodificada una vez más, como
 * siempre: llega a veces doblemente codificada) o '' si no es de aquí.
 */
function iarepo_safe_local_path(mixed $url): string
{
    if (!is_string($url))
        return '';   // ?return_url[]=x era un TypeError → 500
    $url = urldecode($url);
    return iarepo_is_local_path($url) ? str_replace(' ', '%20', $url) : '';
}
