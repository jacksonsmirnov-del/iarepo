<?php
// ================================================================
// unsubscribe.php — One-click email notification opt-out / opt-in
//
// GET  /unsubscribe.php?token=XXX   Show status + toggle button
// POST /unsubscribe.php?token=XXX   One-click unsubscribe (List-Unsubscribe-Post)
//
// NOTE: HTML page — does NOT load shared/helpers.php (its error
// handler would break HTML output). h() is defined locally.
//
// Rediseño 2026-09: solo el aspecto (cabecera y pie comunes, app.css) y los
// textos pasan por t() (CLAUDE.md §2.3), con el vocabulario de la web
// («Me gusta», «hacer su versión» en lugar de like/fork). La lógica —token,
// POST de baja en un clic, ?set=0|1— no cambia.
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/shared/page_errors.php';

require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/i18n.php';
require_once __DIR__ . '/shared/ui.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}
lang();

// Solo texto: ?token[]=x era un TypeError en preg_match → 500 (lo mismo que ya
// hacen auth/signin.php y auth/onboarding.php con sus parámetros).
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$token = is_string($token) ? $token : '';
$valid = (bool) preg_match('/^[a-f0-9]{32}$/', $token);

$db = getResourcesDB();
$user = null;
if ($valid) {
    $stmt = $db->prepare("SELECT id, name, email_notifications FROM users WHERE unsubscribe_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
}

// One-click unsubscribe (email clients POST to List-Unsubscribe).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user) {
        $db->prepare("UPDATE users SET email_notifications = 0 WHERE id = ?")->execute([$user['id']]);
    }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unsubscribed';
    exit;
}

// Manual toggle from the page button.
$justChanged = false;
if ($user && isset($_GET['set'])) {
    $newVal = ($_GET['set'] ?? '') === '1' ? 1 : 0;
    $db->prepare("UPDATE users SET email_notifications = ? WHERE id = ?")->execute([$newVal, $user['id']]);
    $user['email_notifications'] = $newVal;
    $justChanged = true;
}

$enabled = $user ? (int) $user['email_notifications'] === 1 : false;

// Cabecera común: la sesión solo se lee si YA existe (quien llega desde un
// correo no necesita una sesión nueva para esto).
$sessionUser = isset($_COOKIE[session_name()]) ? getSessionUser() : null;
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h(t('Notificaciones por correo')) ?> — iarepo</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<style>
/* Solo lo propio de esta página; lo común vive en assets/css/app.css. */
.us { max-width: 560px; padding-top: 40px; }
.us-card { background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius-lg); box-shadow: var(--ia-shadow); padding: 24px; }
.us-card h1 { font-size: 1.5rem; display: flex; align-items: center; gap: 10px; }
.us-card h1 svg { width: 24px; height: 24px; color: var(--ia-accent); flex: none; }
.us-card p { color: var(--ia-ink-2); }
.us-ok { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 10px; background: var(--ia-ok-soft); color: var(--ia-ok); font-weight: 600; margin-bottom: 18px; }
.us-ok svg { width: 18px; height: 18px; flex: none; }
.us-note { margin: 18px 0 0; font-size: .9rem; }
</style>
<?php require_once __DIR__ . '/shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($sessionUser, ''); ?>
<main id="main" class="ia-container us">
  <div class="us-card">
    <?php if (!$user): ?>
      <h1><i data-lucide="mail-x" aria-hidden="true"></i><?= h(t('Enlace no válido')) ?></h1>
      <?php /* El panel NO tiene un ajuste de correos (solo se cambia aquí): el texto
               de antes mandaba a buscarlo allí [revisión 2026-09]. */ ?>
      <p><?= h(t('Este enlace para darte de baja no es válido o ya caducó. Usa el del último correo que te hayamos enviado: cada correo trae el suyo.')) ?></p>
      <a class="ia-btn ia-btn-primary" href="/"><?= h(t('Volver a iarepo')) ?></a>
    <?php else: ?>
      <?php if ($justChanged): ?>
        <p class="us-ok" role="status"><i data-lucide="check-circle-2" aria-hidden="true"></i><?= h($enabled ? t('Notificaciones activadas de nuevo.') : t('Te diste de baja correctamente.')) ?></p>
      <?php endif; ?>
      <h1><i data-lucide="mail" aria-hidden="true"></i><?= h(sprintf(t('Hola, %s'), (string)$user['name'])) ?></h1>
      <?php if ($enabled): ?>
        <p><?= h(t('Ahora recibes un correo cuando alguien le da «Me gusta», hace su versión o comenta en tus recursos.')) ?></p>
        <a class="ia-btn ia-btn-secondary" href="/unsubscribe.php?token=<?= h($token) ?>&amp;set=0"><i data-lucide="bell-off" aria-hidden="true"></i><?= h(t('Dejar de recibir correos')) ?></a>
      <?php else: ?>
        <p><?= h(t('Ahora no recibes correos de notificación. ¿Quieres volver a activarlos?')) ?></p>
        <a class="ia-btn ia-btn-primary" href="/unsubscribe.php?token=<?= h($token) ?>&amp;set=1"><i data-lucide="bell" aria-hidden="true"></i><?= h(t('Activar notificaciones')) ?></a>
      <?php endif; ?>
      <p class="us-note ia-muted"><?= h(t('Esto solo afecta a los correos. Tu actividad siempre estará en tu panel.')) ?> <a href="/dashboard/"><?= h(t('Ir a mi panel')) ?></a></p>
    <?php endif; ?>
  </div>
</main>
<?php iarepo_footer($sessionUser); ?>
<?= iarepo_body_assets() ?>
</body>
</html>
