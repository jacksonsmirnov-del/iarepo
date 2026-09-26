<?php
// ================================================================
// auth/onboarding.php — Una pregunta tras el registro: ¿das clase o aprendes?
//
// Dos respuestas, las dos audiencias de iarepo:
//   · «Doy clase»                                   → role 'teacher' → /dashboard/
//   · «Estoy aprendiendo (en clase o por mi cuenta)» → role 'student' → /
//     (alumnos y autodidactas: sin panel de autor, sin «Hacer mi versión»
//      ni «Lo usé en clase»; su espacio es Guardados).
// SALTABLE: «Saltar por ahora» deja el rol por defecto (teacher) y lleva a la
// portada, no al panel: quien salta aún no ha dicho que publique nada.
//
// Al elegir: UPDATE users.role Y refresca $_SESSION['user']['role'] (si no,
// el rol no aplica hasta volver a entrar). Si venía guardando un recurso
// (?return_url), vuelve a él.
//
// CSRF: el POST exige el token de la sesión (iarepo_csrf_token, shared/auth.php)
// y que no venga de otra web; profile/index.php manda el mismo token.
//
// Protección: solo se cambia entre 'teacher' y 'student' (lista blanca), y
// NUNCA el rol de un admin o superadmin: este formulario lo usa también
// profile/index.php («Uso iarepo como…») y un clic no puede degradar una
// cuenta de administración.
//
// Página HTML: NO carga shared/helpers.php (su error_handler rompe el HTML).
// Piezas comunes: shared/ui.php y assets/css/app.css (.ob-* es lo propio).
// Antirregresión: tests/unit/account_pages_test.php.
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/local_path.php';
require_once __DIR__ . '/../shared/ui.php';
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();


$sessionUser = getSessionUser();
if (!$sessionUser) { header('Location: /'); exit; }

// return_url: solo rutas locales, con la regla común (shared/local_path.php).
$returnUrl = iarepo_safe_local_path($_GET['return_url'] ?? $_POST['return_url'] ?? '');
$currentRole = (string) ($sessionUser['role'] ?? 'teacher');

// ── POST: elección de rol ─────────────────────────────────────
// ⛔ CSRF: sin el token de la sesión, o desde otra web, no se toca nada. Una
// web ajena podía mandar este formulario con la sesión de quien la visitaba
// y convertir a una alumna en «docente» —perfil público con su nombre y su
// foto— o al revés [revisión 2026-09]. La página se vuelve a pintar (403)
// con un token nuevo: un segundo clic de la persona sí vale.
$csrfRejected = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (iarepo_is_cross_site_write() || !iarepo_csrf_valid($_POST['csrf'] ?? null))) {
    error_log('iarepo: cambio de rol rechazado (sin token CSRF válido o desde otra web) — usuario ' . (int) $sessionUser['id']);
    http_response_code(403);
    $csrfRejected = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
    $switchable = in_array($currentRole, ['teacher', 'student'], true);
    if ($switchable && in_array($role, ['teacher', 'student'], true)) {
        $db = getResourcesDB();
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')
           ->execute([$role, (int) $sessionUser['id']]);
        $_SESSION['user']['role'] = $role;   // refresca la sesión en caliente
    } else {
        $role = $currentRole;
    }

    // Si venía guardando un recurso, vuelve a él; si no, destino por rol.
    if ($returnUrl) { header('Location: ' . $returnUrl); exit; }
    header('Location: ' . ($role === 'student' ? '/' : '/dashboard/'));
    exit;
}

// «Saltar» mantiene el rol por defecto (teacher) y lleva a la portada, o de
// vuelta a lo que estaba guardando.
$skipUrl = $returnUrl ?: '/';
$firstName = trim(explode(' ', (string) ($sessionUser['name'] ?? ''))[0]);
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
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<style>
/* Solo lo propio de esta pantalla (.ob-*). */
.ob-main { padding-top: 32px; padding-bottom: 8px; }   /* solo vertical: el lateral es el de .ia-container */
.ob-card { max-width: 640px; margin: 0 auto; text-align: center; }
.ob-card h1 { font-size: clamp(1.7rem, 1.35rem + 1.3vw, 2.3rem); margin-bottom: 8px; }
.ob-lead { color: var(--ia-ink-2); font-size: 1.05rem; margin-bottom: 22px; }
.ob-choices { display: grid; gap: 14px; grid-template-columns: 1fr; margin-bottom: 18px; }
@media (min-width: 560px) { .ob-choices { grid-template-columns: 1fr 1fr; } }
.ob-choice { display: grid; justify-items: center; gap: 6px; padding: 22px 18px; text-align: center; cursor: pointer;
  font: inherit; color: var(--ia-ink); background: var(--ia-surface); border: 2px solid var(--ia-line-strong); border-radius: var(--ia-radius-lg);
  box-shadow: var(--ia-shadow); transition: border-color .15s, transform .15s; }
.ob-choice:hover { border-color: var(--ia-accent); transform: translateY(-2px); }
.ob-choice-ico { display: grid; place-items: center; width: 56px; height: 56px; border-radius: 16px; background: var(--ia-accent-soft); color: var(--ia-accent); margin-bottom: 4px; }
.ob-choice-ico svg { width: 30px; height: 30px; }
.ob-choice strong { font-size: 1.15rem; line-height: 1.25; }
.ob-choice span.ob-desc { font-size: .9rem; color: var(--ia-ink-2); line-height: 1.45; }
.ob-skip { font-weight: 600; }
.ob-later { font-size: .875rem; color: var(--ia-ink-3); margin-top: 14px; }
.ob-error { padding: 10px 14px; border-radius: 10px; font-weight: 600; color: var(--ia-danger);
  background: color-mix(in srgb, var(--ia-danger) 12%, transparent); }
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($sessionUser, ''); ?>

<main id="main" class="ia-container ob-main">
  <div class="ob-card">
    <h1><?= $firstName !== '' ? h(sprintf(t('¡Hola, %s!'), $firstName)) : h(t('Te damos la bienvenida')) ?></h1>
    <p class="ob-lead"><?= h(t('Una pregunta para enseñarte lo que te sirve:')) ?></p>

    <?php if ($csrfRejected): ?>
      <p class="ob-error" role="alert"><?= h(t('No hemos podido guardar tu elección. Vuelve a pulsarla.')) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(iarepo_csrf_token()) ?>">
      <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
      <div class="ob-choices">
        <button type="submit" name="role" value="teacher" class="ob-choice">
          <span class="ob-choice-ico" aria-hidden="true"><i data-lucide="presentation"></i></span>
          <strong><?= h(t('Doy clase')) ?></strong>
          <span class="ob-desc"><?= h(t('Proyecta simulaciones, mándaselas a tus alumnos con un enlace o un QR, haz listas y publica las tuyas.')) ?></span>
        </button>
        <button type="submit" name="role" value="student" class="ob-choice">
          <span class="ob-choice-ico" aria-hidden="true"><i data-lucide="graduation-cap"></i></span>
          <strong><?= h(t('Estoy aprendiendo (en clase o por mi cuenta)')) ?></strong>
          <span class="ob-desc"><?= h(t('Abre simulaciones, guarda las que te sirvan y vuelve a ellas cuando quieras.')) ?></span>
        </button>
      </div>
    </form>

    <a class="ob-skip" href="<?= h($skipUrl) ?>"><?= h(t('Saltar por ahora')) ?></a>
    <p class="ob-later"><?= h(t('Puedes cambiarlo cuando quieras desde tu perfil.')) ?></p>
  </div>
</main>

<?php iarepo_footer($sessionUser); ?>
<?= iarepo_body_assets() ?>
</body>
</html>
