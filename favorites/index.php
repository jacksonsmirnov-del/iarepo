<?php
// ================================================================
// favorites/index.php — «Guardados» (solo tú los ves)
//
// El guardado privado de un clic (la estrella). NO es una lista: las listas
// (collections) son curaduría pública y viven en otra tabla; esto es
// resource_favorites. Se renombra, no se unifica (CLAUDE.md §6.1).
// Para un alumno es su página de inicio: sin panel, el panel le trae aquí.
//
// Se pinta en el servidor, con la portada de cada recurso (iarepo_cover) y la
// misma regla de acceso que la API (canView): antes la pintaba el JS a partir
// de /api/favorites.php, sin portadas y con 👁/❤ a cero en cada tarjeta.
// Quitar sigue yendo por la API (POST /api/favorites.php?id=N, que ALTERNA):
// la tarjeta no desaparece al momento, se atenúa y la estrella vuelve a
// guardarla. Un toque sin querer se deshace con otro toque.
//
// Página HTML: NO carga shared/helpers.php (su error_handler rompe el HTML).
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/access.php';
require_once __DIR__ . '/../shared/i18n.php';
require_once __DIR__ . '/../shared/ui.php';
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

$sessionUser = getSessionUser();
if (!$sessionUser) {
    header('Location: /auth/signin.php?return_url=' . rawurlencode('/favorites/'));
    exit;
}
$viewer = authenticate();   // la forma que espera canView()

// Estrictamente los de ESTA sesión; lo último guardado, primero.
$stmt = getResourcesDB()->prepare("
    SELECT r.id, r.title, r.description, r.code_type, r.level, r.lang, r.topic_tag,
           r.source_name, r.source_url, r.author_display_name,
           IF(r.code_type = 'url', r.code_content, NULL) AS link_url,
           r.visibility, r.author_tenant_id, r.author_user_id,
           c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
    FROM resource_favorites rf
    JOIN resources r ON r.id = rf.resource_id AND r.is_active = 1
    LEFT JOIN categories c ON c.id = r.category_id
    WHERE rf.user_id = ?
    ORDER BY rf.created_at DESC, rf.id DESC
");
$stmt->execute([(int)$sessionUser['id']]);
$saved = [];
foreach ($stmt->fetchAll() as $row)
    if (canView($row, $viewer))   // si un recurso guardado pasó a borrador, deja de verse
        $saved[] = iarepo_with_labels($row);


/** Tarjeta de un guardado: portada, título, «fuente · curso · idioma» y la estrella. */
function fv_card(array $r): string
{
    $rid  = (int)$r['id'];
    // Botón conmutador: nombre fijo + aria-pressed (el estado lo dice el lector
    // de pantalla); el title cambia con el estado para quien pasa el ratón.
    $name = t('Guardar (solo tú lo ves)') . ': ' . (string)$r['title'];
    return '<article class="ia-card fv-card" data-card="' . $rid . '">'
        . iarepo_cover($r)
        . '<div class="ia-card-body">'
        .   '<h3 class="ia-card-title" id="fv-' . $rid . '">' . h((string)$r['title']) . '</h3>'
        .   ((string)$r['description'] !== '' ? '<p class="ia-card-desc">' . h((string)$r['description']) . '</p>' : '')
        .   '<div class="ia-card-meta">' . iarepo_card_meta($r) . '</div>'
        . '</div>'
        . '<a class="ia-card-link" href="/resource/' . $rid . '" aria-labelledby="fv-' . $rid . '"></a>'
        . '<button type="button" class="ia-btn ia-btn-icon ia-card-fav fv-star" data-fav="' . $rid . '" aria-pressed="true"'
        .   ' title="' . h(t('Quitar de Guardados')) . '" aria-label="' . h($name) . '">'
        .   '<i data-lucide="star" aria-hidden="true"></i></button>'
        . '</article>';
}
$n = count($saved);
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('Guardados')) ?> — iarepo</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<?= iarepo_pwa_script() ?>
<style>
/* Solo lo propio de Guardados; lo común vive en assets/css/app.css. */
/* Tarjetas: .ia-cards-meta (app.css) + iarepo_card_meta() (shared/ui.php). */
.fv-head { padding: 32px 0 4px; }
.fv-head h1 { display: flex; align-items: center; gap: 10px; }
.fv-head h1 svg { width: 1em; height: 1em; color: var(--ia-warn-ink); fill: currentColor; flex: none; }
.fv-lead { color: var(--ia-ink-2); margin: 0; display: inline-flex; align-items: center; gap: 6px; }
.fv-lead svg { width: 18px; height: 18px; }
/* La estrella sobre la portada: rellena = guardado. */
.fv-star { background: var(--ia-surface); border-color: var(--ia-line); color: var(--ia-ink-3); box-shadow: var(--ia-shadow); width: 40px; min-height: 40px; }
.fv-star:hover { background: var(--ia-surface); color: var(--ia-warn-ink); }
.fv-star[aria-pressed="true"] { color: var(--ia-warn-ink); }
.fv-star[aria-pressed="true"] svg { fill: currentColor; }
.fv-card.is-off .ia-cover, .fv-card.is-off .ia-card-body { opacity: .45; }
.fv-card .ia-card-title { padding-right: 0; }
@media (max-width: 559px) {
  .fv-card .ia-card-title { padding-right: 40px; }   /* en fila, la estrella queda sobre el texto */
  .fv-star { top: 8px; right: 8px; width: 38px; min-height: 38px; }
}
.fv-empty-icon { width: 48px; height: 48px; color: var(--ia-warn-ink); }
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($sessionUser, 'saved'); ?>

<main id="main" class="ia-container">
  <header class="fv-head">
    <h1><i data-lucide="star" aria-hidden="true"></i><?= h(t('Guardados')) ?></h1>
    <p class="fv-lead"><i data-lucide="lock" aria-hidden="true"></i><?= h(t('Solo tú los ves.')) ?>
      <?php if ($n): ?><span class="ia-muted">· <?= h($n === 1 ? t('1 recurso') : sprintf(t('%s recursos'), $n)) ?></span><?php endif; ?></p>
  </header>

  <section class="ia-section" aria-labelledby="fv-list-title">
    <h2 id="fv-list-title" class="ia-sr-only"><?= h(t('Tus recursos guardados')) ?></h2>
    <?php if ($saved): ?>
      <div class="ia-grid ia-grid-rows-mobile ia-cards-meta">
        <?php foreach ($saved as $r) echo fv_card($r); ?>
      </div>
    <?php else: ?>
      <div class="ia-empty">
        <i data-lucide="star" class="fv-empty-icon" aria-hidden="true"></i>
        <h3><?= h(t('Aún no has guardado nada')) ?></h3>
        <p><?= h(t('Pulsa la estrella de cualquier recurso para tenerlo aquí, a mano, la próxima vez.')) ?></p>
        <a class="ia-btn ia-btn-primary" href="/"><i data-lucide="compass" aria-hidden="true"></i><?= h(t('Explorar recursos')) ?></a>
      </div>
    <?php endif; ?>
  </section>
</main>

<?php iarepo_footer($sessionUser); ?>
<?= iarepo_body_assets() ?>
<script>
(function () {
  const T = <?= json_encode([
      'removed' => t('Quitado de Guardados. Pulsa la estrella para volver a guardarlo.'),
      'saved'   => t('Guardado otra vez'),
      'remove'  => t('Quitar de Guardados'),
      'save'    => t('Volver a guardar'),
      'error'   => t('No se pudo cambiar. Inténtalo de nuevo.'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

  // La API alterna (añade o quita). La respuesta manda: 'favorited' dice cómo
  // quedó de verdad, así que dos pestañas abiertas no se desincronizan.
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-fav]');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    try {
      const res  = await fetch('/api/favorites.php?id=' + Number(btn.dataset.fav), { method: 'POST' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
      const on = !!data.favorited;
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.title = on ? T.remove : T.save;
      btn.closest('.fv-card').classList.toggle('is-off', !on);
      IA.toast(on ? T.saved : T.removed);
    } catch (err) {
      // Visible para la persona y en la consola; no se traga en silencio.
      console.error('favorites toggle', err);
      IA.toast(T.error);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
</body>
</html>
