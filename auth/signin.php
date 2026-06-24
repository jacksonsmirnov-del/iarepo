<?php
// ================================================================
// auth/signin.php — Pantalla de registro/inicio enfocada (conversión)
//
// Un invitado hace clic en ⭐ Guardar → llega aquí con ?save=ID&return_url=.
// La intención viaja al login_uri de Google (y, como respaldo, en una cookie
// SameSite=None) para aplicar el favorito tras autenticarse y volver al recurso.
//
// Página HTML: NO carga shared/helpers.php (su error_handler rompe el HTML).
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/i18n.php';
lang();

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Solo rutas locales ("/algo"), nunca URLs absolutas ni protocol-relative. */
function safeLocalPath(string $url): string {
    $url = urldecode($url);
    return ($url === '/' || preg_match('#^/[^/\\\\]#', $url)) ? $url : '';
}

$saveId    = (int) ($_GET['save'] ?? 0);
$returnUrl = safeLocalPath($_GET['return_url'] ?? '');

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
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('Regístrate para guardar tus favoritos ⭐')) ?> — iarepo</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#7c3aed">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--bg2:#fff;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--border:#e2e8f0;--shadow:0 10px 40px rgba(124,58,237,.12)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--border:#1e293b;--shadow:0 10px 40px rgba(0,0,0,.4)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);max-width:420px;width:100%;padding:40px 32px;text-align:center}
.logo{height:34px;width:auto;margin-bottom:20px}
.star{font-size:2.6rem;line-height:1;margin-bottom:14px}
h1{font-size:1.4rem;font-weight:800;margin-bottom:10px;line-height:1.25}
p{color:var(--text2);font-size:.95rem;line-height:1.55;margin-bottom:24px}
.gbtn{display:flex;justify-content:center;margin-bottom:18px}
.back{color:var(--text3);font-size:.85rem;text-decoration:none}
.back:hover{color:var(--accent)}
.fineprint{margin-top:22px;color:var(--text3);font-size:.78rem;line-height:1.5}
.fineprint a{color:var(--accent2)}
</style>
</head>
<body>
<div class="card">
  <a href="/"><img src="/assets/img/logo.svg" alt="iarepo" class="logo"></a>
  <div class="star">⭐</div>
  <h1><?= h(t('Guarda este recurso en tus favoritos')) ?></h1>
  <p><?= h(t('Crea tu cuenta gratis para guardar recursos y volver a ellos cuando quieras. Es un clic con Google.')) ?></p>

  <div id="g_id_onload"
       data-client_id="<?= h($googleClientId) ?>"
       data-login_uri="<?= h($loginUri) ?>"
       data-auto_prompt="false"></div>
  <div class="gbtn">
    <div class="g_id_signin"
         data-type="standard"
         data-shape="pill"
         data-theme="outline"
         data-text="continue_with"
         data-size="large"
         data-locale="<?= lang() ?>"></div>
  </div>

  <a class="back" href="<?= h($returnUrl ?: '/') ?>">← <?= h(t('Volver')) ?></a>

  <div class="fineprint">
    <?= h(t('Al continuar aceptas nuestros')) ?>
    <a href="/legal/terms.php"><?= h(t('Términos de uso')) ?></a>.
  </div>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');
</script>
</body>
</html>
