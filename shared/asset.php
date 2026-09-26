<?php
// ================================================================
// shared/asset.php — URL versionada de un asset propio
//
// sw.js sirve los assets del propio dominio con cache-first: una vez en la
// caché, un fichero con la MISMA URL no se vuelve a pedir nunca. Sin versión
// en la URL, cambiar assets/css/app.css no le llegaría a quien ya visitó la
// web («mi cambio no aparece», CLAUDE.md §6.3).
//
// iarepo_asset('/assets/css/app.css') → '/assets/css/app.css?v=3f9a1c0e'
// La versión es un hash corto del contenido: cambia cuando cambia el fichero
// y solo entonces. Sin require de nada; se puede usar en cualquier página.
// ================================================================

function iarepo_asset(string $path): string
{
    static $memo = [];
    if (isset($memo[$path]))
        return $memo[$path];

    // Solo rutas locales conocidas: nunca se versiona algo fuera de la raíz.
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    $real = realpath($file);
    $root = realpath(dirname(__DIR__));
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR))
        return $memo[$path] = $path;

    $v = substr((string) @md5_file($real), 0, 8);
    return $memo[$path] = $v !== '' ? $path . '?v=' . $v : $path;
}

/**
 * Captura real de un recurso (thumbnails/og-N.png) o null si no la hay.
 *
 * Las capturas se generan a mano (setup/tools/generate-thumbnails.sh), viven
 * fuera de git y sobreviven al checkout -f del deploy. La portada antigua las
 * pedía SIEMPRE con un <img onerror>, y cada una que faltaba era una petición
 * que el catch-all de .htaccess convertía en 404.php: hasta 50 ejecuciones de
 * PHP por página. Aquí se mira el disco (un stat por fila) y solo se enlaza la
 * que existe, con ?v=<mtime> para que el service worker no sirva una vieja
 * tras regenerarla. Sin ella, la tarjeta usa la portada generativa.
 */
function iarepo_thumb(int $id): ?string
{
    if ($id <= 0)
        return null;
    $mtime = @filemtime(dirname(__DIR__) . "/thumbnails/og-$id.png");
    return $mtime ? "/thumbnails/og-$id.png?v=$mtime" : null;
}

