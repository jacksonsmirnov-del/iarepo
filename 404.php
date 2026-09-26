<?php
// ================================================================
// 404.php — «Página no encontrada», con la cabecera y el pie de la web
//
// La sirven el ErrorDocument de Apache y, en LiteSpeed (producción), la regla
// de .htaccess que manda aquí toda ruta que no sea un fichero ni un directorio.
// Página HTML: sin shared/helpers.php (CLAUDE.md §2.1), h() local.
//
// No es un callejón sin salida: lleva un buscador que abre la portada con
// /?search=… y enlaces a explorar. Quien llega por un enlace roto (un recurso
// retirado, una URL mal copiada de la pizarra) sigue buscando.
// ================================================================
// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/shared/page_errors.php';

http_response_code(404);
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/i18n.php';
require_once __DIR__ . '/shared/ui.php';
lang();

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

// La sesión solo se lee si YA existe: este script responde también a cada
// fichero que falta, y abrirla sin cookie crearía una sesión nueva por cada
// petición perdida de un robot.
$user = isset($_COOKIE[session_name()]) ? getSessionUser() : null;
$q    = trim((string) ($_GET['q'] ?? ''));
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h(t('Página no encontrada — iarepo')) ?></title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<style>
/* Solo lo propio de esta página; lo común vive en assets/css/app.css. */
.nf { padding-top: 56px; padding-bottom: 24px; max-width: 720px; }   /* sin tocar el margen lateral de .ia-container */
.nf h1 { margin-bottom: 12px; }
.nf-lead { color: var(--ia-ink-2); font-size: 1.05rem; max-width: 56ch; }
.nf-search { margin: 20px 0 18px; }
.nf-links { display: flex; flex-wrap: wrap; gap: 10px; }
@media (max-width: 559px) { .nf { padding-top: 32px; } }
</style>
<?php require_once __DIR__ . '/shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($user, ''); ?>

<main id="main" class="ia-container nf">
  <p class="ia-eyebrow"><?= h(t('Error 404')) ?></p>
  <h1><?= h(t('Esta página no existe')) ?></h1>
  <p class="nf-lead"><?= h(t('Puede que el enlace esté mal escrito o que el recurso se haya movido o retirado. Busca lo que necesitabas:')) ?></p>
  <!-- GET a la portada: /?search=… es un deep-link que index.php ya entiende. -->
  <form class="ia-search nf-search" role="search" action="/" method="get">
    <i data-lucide="search" aria-hidden="true"></i>
    <label for="nf-q" class="ia-sr-only"><?= h(t('Buscar recursos')) ?></label>
    <input type="search" id="nf-q" name="search" value="<?= h($q) ?>" enterkeyhint="search" autocomplete="off"
           placeholder="<?= h(t('Tema de la clase o lo que quieres entender: fuerzas, fracciones, el átomo…')) ?>">
    <button type="submit" class="ia-btn ia-btn-primary"><?= h(t('Buscar')) ?></button>
  </form>
  <div class="nf-links">
    <a class="ia-btn ia-btn-secondary" href="/?focus=search"><i data-lucide="compass" aria-hidden="true"></i><?= h(t('Explorar recursos')) ?></a>
    <a class="ia-btn ia-btn-ghost" href="/"><i data-lucide="house" aria-hidden="true"></i><?= h(t('Ir al inicio')) ?></a>
  </div>
</main>

<?php iarepo_footer($user); ?>
<?= iarepo_body_assets() ?>
</body>
</html>
