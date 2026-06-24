<?php
// ================================================================
// auth/onboarding.php — Onboarding ligero tras el registro
//
// "Soy profesor / Soy estudiante" — SALTABLE (default teacher).
// Al elegir: UPDATE users.role Y refresca $_SESSION['user']['role']
// (si no, el rol no aplica hasta re-login).
//   Profesor → /dashboard/   ·   Estudiante → home
// Respeta ?return_url si venía guardando un recurso (vuelve al recurso).
//
// Página HTML: NO carga shared/helpers.php (su error_handler rompe el HTML).
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
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

$sessionUser = getSessionUser();
if (!$sessionUser) { header('Location: /'); exit; }

$returnUrl = safeLocalPath($_GET['return_url'] ?? $_POST['return_url'] ?? '');

// ── POST: elección de rol ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';
    if (in_array($role, ['teacher', 'student'], true)) {
        $db = getResourcesDB();
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')
           ->execute([$role, (int) $sessionUser['id']]);
        $_SESSION['user']['role'] = $role;   // refresca la sesión en caliente
    }

    // Si venía guardando un recurso, vuelve a él; si no, destino por rol.
    if ($returnUrl) { header('Location: ' . $returnUrl); exit; }
    header('Location: ' . ($role === 'student' ? '/' : '/dashboard/'));
    exit;
}

// "Saltar" mantiene el default (teacher); respeta el retorno si lo hay.
$skipUrl = $returnUrl ?: '/dashboard/';
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('Te damos la bienvenida')) ?> — iarepo</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#7c3aed">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--bg2:#fff;--bg3:#f1f5f9;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--border:#e2e8f0;--shadow:0 10px 40px rgba(124,58,237,.12)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--border:#1e293b;--shadow:0 10px 40px rgba(0,0,0,.4)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);max-width:520px;width:100%;padding:40px 32px;text-align:center}
.logo{height:32px;width:auto;margin-bottom:18px}
h1{font-size:1.55rem;font-weight:800;margin-bottom:8px;line-height:1.2}
.sub{color:var(--text2);font-size:.95rem;line-height:1.55;margin-bottom:28px}
.choices{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
@media(max-width:460px){.choices{grid-template-columns:1fr}}
.choice{appearance:none;cursor:pointer;background:var(--bg3);border:2px solid var(--border);border-radius:14px;padding:24px 18px;font-family:inherit;color:var(--text);text-align:center;transition:.18s}
.choice:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 8px 24px rgba(124,58,237,.14)}
.choice .emoji{font-size:2.2rem;display:block;margin-bottom:10px}
.choice .label{font-size:1.05rem;font-weight:700;display:block;margin-bottom:4px}
.choice .desc{font-size:.82rem;color:var(--text3);display:block;line-height:1.4}
.skip{display:inline-block;margin-top:6px;color:var(--text3);font-size:.88rem;text-decoration:none}
.skip:hover{color:var(--accent)}
</style>
</head>
<body>
<div class="card">
  <img src="/assets/img/logo.svg" alt="iarepo" class="logo">
  <h1><?= h(t('¡Hola')) ?>, <?= h($sessionUser['name'] ?: t('bienvenido')) ?>! 👋</h1>
  <p class="sub"><?= h(t('Para personalizar tu experiencia, cuéntanos cómo usarás iarepo. Puedes cambiarlo después.')) ?></p>

  <form method="post">
    <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
    <div class="choices">
      <button type="submit" name="role" value="teacher" class="choice">
        <span class="emoji">🧑‍🏫</span>
        <span class="label"><?= h(t('Soy profesor')) ?></span>
        <span class="desc"><?= h(t('Creo y publico recursos para mis clases')) ?></span>
      </button>
      <button type="submit" name="role" value="student" class="choice">
        <span class="emoji">🎓</span>
        <span class="label"><?= h(t('Soy estudiante')) ?></span>
        <span class="desc"><?= h(t('Descubro y guardo recursos para aprender')) ?></span>
      </button>
    </div>
  </form>

  <a class="skip" href="<?= h($skipUrl) ?>"><?= h(t('Saltar por ahora')) ?></a>
</div>

<script>
if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');
</script>
</body>
</html>
