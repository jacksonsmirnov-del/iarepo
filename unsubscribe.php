<?php
// ================================================================
// unsubscribe.php — One-click email notification opt-out / opt-in
//
// GET  /unsubscribe.php?token=XXX   Show status + toggle button
// POST /unsubscribe.php?token=XXX   One-click unsubscribe (List-Unsubscribe-Post)
//
// NOTE: HTML page — does NOT load shared/helpers.php (its error
// handler would break HTML output). h() is defined locally.
// ================================================================

require_once __DIR__ . '/shared/db.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$token = $_GET['token'] ?? $_POST['token'] ?? '';
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
    $newVal = $_GET['set'] === '1' ? 1 : 0;
    $db->prepare("UPDATE users SET email_notifications = ? WHERE id = ?")->execute([$newVal, $user['id']]);
    $user['email_notifications'] = $newVal;
    $justChanged = true;
}

$enabled = $user ? (int) $user['email_notifications'] === 1 : false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Notificaciones por correo — iarepo</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f1f5f9;color:#1e293b;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.08);max-width:440px;width:100%;overflow:hidden}
.head{background:linear-gradient(135deg,#7c3aed,#06b6d4);padding:20px 28px;color:#fff;font-size:1.2rem;font-weight:700}
.body{padding:28px}
.body h2{font-size:1.05rem;margin-bottom:10px}
.body p{color:#475569;font-size:.92rem;line-height:1.6;margin-bottom:18px}
.btn{display:inline-block;text-decoration:none;border:none;cursor:pointer;font-family:inherit;font-size:.92rem;font-weight:600;padding:11px 24px;border-radius:10px}
.btn-off{background:#ef4444;color:#fff}
.btn-on{background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff}
.note{margin-top:16px;font-size:.82rem;color:#94a3b8}
.ok{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;font-size:.85rem;margin-bottom:18px}
a.link{color:#7c3aed}
</style>
</head>
<body>
<div class="card">
  <div class="head">iarepo</div>
  <div class="body">
    <?php if (!$user): ?>
      <h2>Enlace no válido</h2>
      <p>Este enlace de cancelación no es válido o ya expiró. Puedes gestionar tus notificaciones desde tu panel.</p>
      <a class="btn btn-on" href="/dashboard/">Ir a mi panel</a>
    <?php else: ?>
      <?php if ($justChanged): ?>
        <div class="ok"><?= $enabled ? '✅ Notificaciones activadas de nuevo.' : '✅ Te diste de baja correctamente.' ?></div>
      <?php endif; ?>
      <h2>Hola, <?= h($user['name']) ?></h2>
      <?php if ($enabled): ?>
        <p>Actualmente <strong>recibes</strong> un correo cuando alguien le da like, hace un fork o comenta en tus recursos.</p>
        <a class="btn btn-off" href="/unsubscribe.php?token=<?= h($token) ?>&set=0">Dejar de recibir correos</a>
      <?php else: ?>
        <p>Actualmente <strong>no recibes</strong> correos de notificación. ¿Quieres volver a activarlos?</p>
        <a class="btn btn-on" href="/unsubscribe.php?token=<?= h($token) ?>&set=1">Activar notificaciones</a>
      <?php endif; ?>
      <p class="note">Esto solo afecta a los correos. Tu actividad siempre estará en tu <a class="link" href="/dashboard/">panel</a>.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
