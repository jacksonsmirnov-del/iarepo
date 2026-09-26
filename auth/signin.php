<?php
// ================================================================
// auth/signin.php — Entrar en iarepo (una sola pantalla, con Google)
//
// Dos maneras de llegar:
//   · Con intención de guardar: un invitado pulsó «Guardar» en un recurso →
//     ?save=ID&return_url=. La intención viaja al login_uri de Google (y, como
//     respaldo, en una cookie SameSite=None) para aplicar el guardado tras
//     autenticarse y volver al recurso (lo aplica auth/google.php).
//   · Sin intención: «Entrar» de la cabecera. Se explica QUÉ se gana con una
//     cuenta, a los dos públicos: quien aprende (guardar, hacer listas) y
//     quien da clase (además, publicar y ver si sus recursos sirven). Y se
//     recuerda lo importante para un alumno: para USAR las simulaciones no
//     hace falta cuenta.
//
// return_url solo acepta rutas locales (iarepo_safe_local_path, en
// shared/local_path.php): nunca una URL absoluta, //host ni «/\t/host» (el
// navegador borra el tabulador), que convertirían esta página en un
// redirector abierto.
//
// Página HTML: NO carga shared/helpers.php (su error_handler rompe el HTML).
// Piezas comunes: shared/ui.php y assets/css/app.css (.au-* es lo propio).
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/local_path.php';
require_once __DIR__ . '/../shared/ui.php';
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

// return_url: solo rutas locales, con la regla común (shared/local_path.php).
// La copia local de safeLocalPath dejaba pasar «/%09/otra.web».
$saveId    = (int) ($_GET['save'] ?? 0);
$returnUrl = iarepo_safe_local_path($_GET['return_url'] ?? '');

// Ya autenticado: no hay registro que hacer, vuelve a donde iba.
if (getSessionUser()) {
    header('Location: ' . ($returnUrl ?: '/'));
    exit;
}

// Respaldo del flujo de conversión: cookie que sobrevive al POST de Google.
if ($saveId) {
    $opts = ['expires' => time() + 1800, 'path' => '/', 'samesite' => 'None', 'secure' => true];
    setcookie('fav_intent', (string) $saveId, $opts);
    if ($returnUrl) setcookie('fav_return', $returnUrl, $opts);
}

$env = require dirname(__DIR__) . '/.env.php';
$googleClientId = $env['GOOGLE_CLIENT_ID'] ?? '';

// El login_uri lleva la intención como query (origen iarepo.com ya autorizado).
$loginUri = 'https://iarepo.com/auth/google.php';
$qs = array_filter(['save' => $saveId ?: null, 'return_url' => $returnUrl ?: null]);
if ($qs) $loginUri .= '?' . http_build_query($qs);

$pageTitle = $saveId ? t('Guarda este recurso') : t('Entra en iarepo');
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — iarepo</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<style>
/* Solo lo propio de esta pantalla (.au-*). */
.au-main { padding-top: 32px; padding-bottom: 8px; }   /* solo vertical: el lateral es el de .ia-container */
.au-card { max-width: 520px; margin: 0 auto; padding: 28px 24px; background: var(--ia-surface); border: 1px solid var(--ia-line);
  border-radius: var(--ia-radius-lg); box-shadow: var(--ia-shadow); }
.au-card h1 { font-size: clamp(1.6rem, 1.3rem + 1.2vw, 2.1rem); margin-bottom: 8px; }
.au-lead { color: var(--ia-ink-2); font-size: 1.05rem; }
.au-icon { display: grid; place-items: center; width: 52px; height: 52px; border-radius: 14px; background: var(--ia-accent-soft); color: var(--ia-accent); margin-bottom: 14px; }
.au-icon svg { width: 28px; height: 28px; }
.au-benefits { display: grid; gap: 14px; margin: 18px 0 22px; }
.au-benefits h2 { font-size: .8rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--ia-ink-3); margin: 0 0 6px; }
.au-benefits ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.au-benefits li { display: grid; grid-template-columns: 22px 1fr; gap: 8px; align-items: start; }
.au-benefits li svg { width: 20px; height: 20px; color: var(--ia-ok); margin-top: 2px; }
.au-google { display: flex; justify-content: center; min-height: 44px; margin: 8px 0 12px; }
.au-fail { background: var(--ia-warn-soft); color: var(--ia-warn-ink); padding: 10px 14px; border-radius: 10px; font-weight: 600; }
.au-note { display: flex; gap: 8px; align-items: flex-start; font-size: .9rem; color: var(--ia-ink-2); background: var(--ia-surface-2);
  padding: 10px 12px; border-radius: 10px; margin: 16px 0 0; }
.au-note svg { width: 18px; height: 18px; flex: none; margin-top: 2px; }
.au-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin-top: 18px; font-size: .875rem; color: var(--ia-ink-3); }
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header(null, ''); ?>

<main id="main" class="ia-container au-main">
  <div class="au-card">
    <?php if ($saveId): ?>
      <span class="au-icon" aria-hidden="true"><i data-lucide="bookmark"></i></span>
      <h1><?= h(t('Guarda este recurso')) ?></h1>
      <p class="au-lead"><?= h(t('Entra gratis con tu cuenta de Google y lo tendrás en Guardados (solo tú los ves), para volver a él cuando quieras.')) ?></p>
    <?php else: ?>
      <span class="au-icon" aria-hidden="true"><i data-lucide="log-in"></i></span>
      <h1><?= h(t('Entra en iarepo')) ?></h1>
      <p class="au-lead"><?= h(t('Gratis, con tu cuenta de Google. Con una cuenta puedes:')) ?></p>
      <div class="au-benefits">
        <div>
          <h2><?= h(t('Para todos')) ?></h2>
          <ul>
            <li><i data-lucide="check"></i><span><?= h(t('Guardar simulaciones para volver a ellas (solo tú las ves).')) ?></span></li>
            <li><i data-lucide="check"></i><span><?= h(t('Hacer listas por tema o por curso y compartirlas con un enlace.')) ?></span></li>
          </ul>
        </div>
        <div>
          <h2><?= h(t('Si das clase, además')) ?></h2>
          <ul>
            <li><i data-lucide="check"></i><span><?= h(t('Publicar tus propias simulaciones, con tu nombre.')) ?></span></li>
            <li><i data-lucide="check"></i><span><?= h(t('Ver si tus recursos sirven: cuántas personas los abren y cuántos docentes los usan en clase.')) ?></span></li>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <div id="g_id_onload"
         data-client_id="<?= h($googleClientId) ?>"
         data-login_uri="<?= h($loginUri) ?>"
         data-auto_prompt="false"></div>
    <div class="au-google">
      <div class="g_id_signin"
           data-type="standard"
           data-shape="pill"
           data-theme="outline"
           data-text="continue_with"
           data-size="large"
           data-locale="<?= lang() ?>"></div>
    </div>
    <p class="au-fail" id="gsiFail" role="alert" hidden><?= h(t('No se pudo cargar el botón de Google. Comprueba la conexión (o el bloqueador de anuncios) y recarga la página.')) ?></p>

    <p class="au-note"><i data-lucide="info"></i><span><?= h(t('Para abrir y usar las simulaciones no hace falta cuenta.')) ?></span></p>

    <div class="au-foot">
      <a href="<?= h($returnUrl ?: '/') ?>">← <?= h(t('Volver')) ?></a>
      <span><?= h(t('Al continuar aceptas nuestros')) ?> <a href="/legal/terms.php"><?= h(t('Términos de uso')) ?></a>.</span>
    </div>
  </div>
</main>

<?php iarepo_footer(null, false); /* sin la banda «Publicar»: aquí se viene a entrar */ ?>
<?= iarepo_body_assets() ?>
<!-- Google Identity Services: el único script de terceros de la web, y solo
     aquí. Si no carga (red del centro, bloqueador), se dice en vez de dejar
     un hueco vacío donde debería estar el botón. -->
<script src="https://accounts.google.com/gsi/client" async defer onerror="document.getElementById('gsiFail').hidden=false"></script>
<script>
setTimeout(function () {
  if (!(window.google && window.google.accounts)) document.getElementById('gsiFail').hidden = false;
}, 8000);
</script>
</body>
</html>
