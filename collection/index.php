<?php
// ================================================================
// collection/index.php — Una lista, como SECUENCIA de pasos
// URL: /collection/?id=X
//
// ── POR QUÉ UNA SECUENCIA ─────────────────────────────────────
// Una lista la arma un docente para una clase o un tema: el orden en que
// añadió los recursos ES el orden en que los quiere usar. Antes se enseñaba
// al revés (added_at DESC: lo último añadido, primero) y sin numerar, como
// un cajón. Ahora: orden de llegada (added_at ASC, id ASC para desempatar),
// pasos 1, 2, 3… con portada, fuente y curso, y un «Mandar a mis alumnos» de
// la lista ENTERA (QR + enlace, assets/js/ui.js → IA.openSend({path})).
//
// ── QUIÉN VE QUÉ ──────────────────────────────────────────────
//   · La lista: pública, o su dueño (si no, a la portada; lo fija
//     quality/smoke_test.sh con un 302).
//   · Cada recurso de la lista pasa por canView() (shared/access.php), la
//     MISMA regla que la API: un borrador metido en una lista pública no se
//     le enseña a nadie más que a su autor. Antes salía entero —título y
//     descripción— a cualquiera que abriera la lista.
//   · El nombre del dueño solo se enseña si NO es alumno: los perfiles de
//     alumno no son públicos (profile/index.php).
//   · «Mandar a mis alumnos» no se le ofrece a un alumno (igual que la ficha),
//     ni «Editar en Mi panel»: un alumno no tiene panel (le lleva a Guardados).
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
// h() local — NO se carga shared/helpers.php: su error_handler vuelca JSON y
// corta la página a medias ante cualquier error (CLAUDE.md §2.1).
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /'); exit; }

$db          = getResourcesDB();
$sessionUser = getSessionUser();
$viewer      = authenticate();   // la forma que espera canView()

$stmt = $db->prepare("
    SELECT col.id, col.user_id, col.title, col.description, col.is_public, col.created_at,
           u.name AS owner_name, u.role AS owner_role
    FROM collections col
    LEFT JOIN users u ON u.id = col.user_id
    WHERE col.id = ?
");
$stmt->execute([$id]);
$coll = $stmt->fetch();
if (!$coll) { header('Location: /'); exit; }

$isOwner = $sessionUser && (int)$coll['user_id'] === (int)$sessionUser['id'];
if (!$coll['is_public'] && !$isOwner) { header('Location: /'); exit; }

// Pasos: en el orden en que se añadieron.
$items = $db->prepare("
    SELECT ci.id AS item_id, r.id, r.title, r.description, r.code_type, r.level, r.lang, r.topic_tag,
           r.source_name, r.source_url, r.author_display_name,
           IF(r.code_type = 'url', r.code_content, NULL) AS link_url,
           r.visibility, r.author_tenant_id, r.author_user_id,
           c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
    FROM collection_items ci
    JOIN resources r ON r.id = ci.resource_id AND r.is_active = 1
    LEFT JOIN categories c ON c.id = r.category_id
    WHERE ci.collection_id = ?
    ORDER BY ci.added_at ASC, ci.id ASC
");
$items->execute([$id]);
$steps = [];
foreach ($items->fetchAll() as $row)
    if (canView($row, $viewer))
        $steps[] = iarepo_with_labels($row);

$isStudentViewer = ($sessionUser['role'] ?? '') === 'student';
$showOwner       = ($coll['owner_role'] ?? '') !== 'student' && (string)($coll['owner_name'] ?? '') !== '';
$canSend         = !$isStudentViewer && (bool)$coll['is_public'] && $steps;
$title           = (string)$coll['title'];
$desc            = (string)($coll['description'] ?? '');
$n               = count($steps);
$countText       = $n === 1 ? t('1 recurso') : sprintf(t('%s recursos'), $n);
$metaDesc        = $desc !== '' ? $desc : sprintf(t('Lista de %s recursos educativos interactivos en iarepo, en orden para usarlos en clase.'), $n);
$selfPath        = '/collection/?id=' . $id;
$canonical       = 'https://iarepo.com' . $selfPath;


/**
 * Un paso: número, portada, título, descripción y «fuente · curso · idioma».
 * Toda la tarjeta enlaza a la ficha (.ia-card-link); el dueño tiene además
 * un botón para quitarlo, FUERA del enlace (un <button> dentro de un <a> es
 * inválido) y por encima (z-index de .ia-card-fav).
 */
function cl_step(array $r, int $n, bool $isOwner, int $listId): string
{
    $rid  = (int)$r['id'];
    $out  = '<li class="cl-step" id="item-' . (int)$r['item_id'] . '">'
          . '<span class="cl-step-n" aria-hidden="true">' . $n . '</span>'
          . '<article class="ia-card cl-card">'
          . iarepo_cover($r)
          . '<div class="ia-card-body">'
          .   '<p class="cl-step-label"><span data-step-label>' . h(sprintf(t('Paso %s'), $n)) . '</span>'
          .   ($isOwner && ($r['visibility'] ?? '') !== 'community' ? ' <span class="ia-tag ia-tag-new">' . h(t('No es público: solo lo ves tú')) . '</span>' : '')
          .   '</p>'
          .   '<h3 class="ia-card-title" id="st-' . $rid . '">' . h((string)$r['title']) . '</h3>'
          .   ((string)$r['description'] !== '' ? '<p class="ia-card-desc">' . h((string)$r['description']) . '</p>' : '')
          .   '<div class="ia-card-meta">' . iarepo_card_meta($r) . '</div>'
          . '</div>'
          // ?list=: la ficha enseña «Paso N de M» y el paso siguiente DE LA LISTA.
          . '<a class="ia-card-link" href="/resource/' . $rid . '?list=' . $listId . '" aria-labelledby="st-' . $rid . '"></a>';
    if ($isOwner)
        $out .= '<button type="button" class="ia-btn ia-btn-secondary ia-btn-icon ia-card-fav cl-remove" data-remove="' . $rid . '"'
              . ' title="' . h(t('Quitar de la lista')) . '" aria-label="' . h(t('Quitar de la lista')) . ': ' . h((string)$r['title']) . '">'
              . '<i data-lucide="x" aria-hidden="true"></i></button>';
    return $out . '</article></li>';
}
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — <?= h(t('Lista')) ?> · iarepo</title>
<?php if (!$coll['is_public']): ?><meta name="robots" content="noindex"><?php endif; ?>
<meta name="description" content="<?= h($metaDesc) ?>">
<meta property="og:title" content="<?= h($title) ?> — iarepo">
<meta property="og:description" content="<?= h($metaDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="iarepo">
<link rel="canonical" href="<?= h($canonical) ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<?= iarepo_pwa_script() ?>
<style>
/* Solo lo propio de la lista; lo común vive en assets/css/app.css. */
/* Tarjetas: .ia-cards-meta (app.css) + iarepo_card_meta() (shared/ui.php). */
.cl-head { padding: 32px 0 8px; max-width: 760px; }
.cl-head h1 { overflow-wrap: anywhere; }
.cl-desc { color: var(--ia-ink-2); font-size: 1.05rem; max-width: 62ch; }
.cl-by { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; color: var(--ia-ink-3); font-size: .9rem; margin-bottom: 16px; }
.cl-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.cl-note { margin-top: 12px; }
/* La secuencia: número en una columna a la izquierda, unido por una línea. */
.cl-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 16px; max-width: 980px; }
.cl-step { position: relative; display: grid; grid-template-columns: 44px 1fr; gap: 14px; align-items: start; }
.cl-step:not(:last-child)::before { content: ""; position: absolute; left: 21px; top: 48px; bottom: -16px; width: 2px; background: var(--ia-line-strong); }
.cl-step-n { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 50%; background: var(--ia-ink); color: var(--ia-bg);
  font-weight: 800; font-size: 1.1rem; font-variant-numeric: tabular-nums; position: relative; }
.cl-card { flex-direction: row; min-width: 0; }
.cl-card .ia-cover { width: 34%; max-width: 280px; flex: none; }
.cl-card .ia-card-body { min-width: 0; justify-content: center; }
.cl-card .ia-card-title { padding-right: 44px; }   /* sitio para el botón de quitar */
.cl-step-label { margin: 0; font-size: .8rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--ia-ink-3); }
.cl-step-label .ia-tag { text-transform: none; letter-spacing: 0; }
.cl-remove { width: 38px; min-height: 38px; }
.cl-step.is-removing { opacity: .4; pointer-events: none; }
@media (max-width: 559px) {
  .cl-step { grid-template-columns: 32px 1fr; gap: 10px; }
  .cl-step:not(:last-child)::before { left: 15px; top: 36px; }
  .cl-step-n { width: 32px; height: 32px; font-size: .95rem; }
  .cl-card .ia-cover { width: 96px; aspect-ratio: auto; min-height: 96px; }
  .cl-card .ia-cover-icon { right: 50%; transform: translate(50%, -50%); width: 52%; }
  .cl-card .ia-cover-source, .cl-card .ia-cover-topic, .cl-card .ia-cover-badge { display: none; }
  .cl-card .ia-card-desc { display: none; }
  .cl-step-label [data-step-label] { display: none; }   /* el número ya está en el círculo; en móvil cada línea cuenta */   /* en móvil, título + fuente · curso: cabe la secuencia entera */
  .cl-card:hover { transform: none; }
}
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($sessionUser, ''); ?>

<main id="main" class="ia-container">
  <header class="cl-head">
    <p class="ia-eyebrow"><?= h(t('Lista')) ?> · <span data-step-count><?= h($countText) ?></span></p>
    <h1><?= h($title) ?></h1>
    <?php if ($desc !== ''): ?><p class="cl-desc"><?= h($desc) ?></p><?php endif; ?>
    <div class="cl-by">
      <?php if ($showOwner): ?><span><?= h(t('Lista de')) ?> <a href="/profile/<?= (int)$coll['user_id'] ?>"><?= h((string)$coll['owner_name']) ?></a></span><?php endif; ?>
      <?php if ($isOwner): ?>
        <span class="ia-tag<?= $coll['is_public'] ? ' ia-tag-ok' : '' ?>"><?= h($coll['is_public'] ? t('Pública') : t('Privada')) ?></span>
        <?php if (!$isStudentViewer): ?><a href="/dashboard/"><?= h(t('Editar en Mi panel')) ?></a><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($steps): ?>
    <div class="cl-actions">
      <?php if ($canSend): ?>
        <button type="button" class="ia-btn ia-btn-primary" id="sendListBtn"><i data-lucide="send" aria-hidden="true"></i><?= h(t('Mandar a mis alumnos')) ?></button>
      <?php endif; ?>
      <a class="ia-btn <?= $canSend ? 'ia-btn-secondary' : 'ia-btn-primary' ?>" href="/resource/<?= (int)$steps[0]['id'] ?>?list=<?= $id ?>"><i data-lucide="play" aria-hidden="true"></i><?= h(t('Empezar por el paso 1')) ?></a>
    </div>
    <?php endif; ?>
    <?php if ($isOwner && !$isStudentViewer && !$coll['is_public']): ?>
      <p class="cl-note ia-muted ia-small"><?= h(t('Esta lista es privada: para mandársela a tus alumnos, hazla pública en Mi panel.')) ?></p>
    <?php endif; ?>
  </header>

  <section class="ia-section" aria-labelledby="cl-steps-title">
    <h2 id="cl-steps-title" class="ia-sr-only"><?= h(t('Recursos de la lista, en orden')) ?></h2>
    <?php if ($steps): ?>
      <ol class="cl-steps ia-cards-meta" role="list">
        <?php foreach ($steps as $i => $r) echo cl_step($r, $i + 1, $isOwner, $id); ?>
      </ol>
    <?php endif; ?>
    <div class="ia-empty<?= $steps ? ' ia-hidden' : '' ?>" id="clEmpty">
      <h3><?= h(t('Esta lista está vacía')) ?></h3>
      <p><?= h(t('Añade recursos desde su ficha, con «Añadir a una lista».')) ?></p>
      <?php if ($isOwner): ?><a class="ia-btn ia-btn-primary" href="/"><i data-lucide="compass" aria-hidden="true"></i><?= h(t('Explorar recursos')) ?></a><?php endif; ?>
    </div>
  </section>
</main>

<?php iarepo_footer($sessionUser); ?>
<?php if ($canSend) iarepo_send_dialog(); ?>
<?= iarepo_body_assets($canSend) ?>
<script>
(function () {
  const COLL_ID = <?= $id ?>;
  const T = <?= json_encode([
      'title'         => $title,
      'copied'        => t('Enlace copiado'),
      'confirmRemove' => t('¿Quitar este recurso de la lista?'),
      'removed'       => t('Quitado de la lista'),
      'removeError'   => t('No se pudo quitar de la lista'),
      'step'          => t('Paso %s'),
      'one'           => t('1 recurso'),
      'many'          => t('%s recursos'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

  const send = document.getElementById('sendListBtn');
  if (send) send.addEventListener('click', () =>
    IA.openSend({ path: '/collection/?id=' + COLL_ID, title: T.title, copiedMsg: T.copied }));

  // Tras quitar un paso, los demás se renumeran: la secuencia sigue siendo 1, 2, 3…
  function renumber() {
    const steps = document.querySelectorAll('.cl-step');
    steps.forEach((li, i) => {
      li.querySelector('.cl-step-n').textContent = i + 1;
      const label = li.querySelector('[data-step-label]');
      if (label) label.textContent = T.step.replace('%s', i + 1);
    });
    const count = document.querySelector('[data-step-count]');
    if (count) count.textContent = steps.length === 1 ? T.one : T.many.replace('%s', steps.length);
    if (!steps.length) {
      document.getElementById('clEmpty').classList.remove('ia-hidden');
      document.querySelector('.cl-actions')?.remove();
    }
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-remove]');
    if (!btn || !confirm(T.confirmRemove)) return;
    const li = btn.closest('.cl-step');
    li.classList.add('is-removing');
    try {
      const res  = await fetch('/api/collections.php?action=remove&id=' + COLL_ID, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ resource_id: Number(btn.dataset.remove) })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
      li.remove();
      renumber();
      IA.toast(T.removed);
    } catch (err) {
      // Visible para la persona y en la consola; no se traga en silencio.
      li.classList.remove('is-removing');
      console.error('collection remove', err);
      IA.toast(T.removeError);
    }
  });
})();
</script>
</body>
</html>
