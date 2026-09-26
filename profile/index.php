<?php
// ================================================================
// profile/index.php — Perfil de una persona en iarepo
// URL: /profile/{user_id}   (?page=N pagina sus recursos)
//
// ── QUÉ ENSEÑA (rediseño 2026-09) ─────────────────────────────
//   · Docente: nombre, avatar, «Docente en iarepo desde…», sus recursos
//     públicos en rejilla con portada y sus listas públicas.
//   · Cifras: en público SOLO por encima de un umbral, las mismas que la
//     ficha del recurso (resource/index.php, RES_PROOF_MIN_*), y siempre
//     aperturas = view_count + unique_views: view_count está CONGELADO desde
//     2026-08-06 y a solas mentiría a la baja (CLAUDE.md §6.3). El autor, en
//     su propio perfil, ve las suyas siempre, también a cero.
//   · Alumno: su perfil NO es público (son menores). Quien no es él recibe
//     el 404 de la web —el mismo que una dirección que no existe, para no
//     confirmar que ahí hay una cuenta— y el suyo lleva noindex.
//   · Cuenta que no ha publicado nada (ni recursos ni listas públicas): el
//     mismo 404 para todos menos su dueño. Sin recursos públicos, noindex.
//   · El email no se lee: esta página no lo usa y no tiene por qué tocarlo.
//
// Antes: ordenaba por like_count/view_count (popularidad, sin datos), ponía
// 👁 ❤ 🔄 con ceros en cada tarjeta y abría el perfil de cualquier alumno.
// Lo fijan tests/unit/lists_profile_test.php y el bloque «Listas y perfil»
// de tests/integration/render_pages_test.php.
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

// Umbrales de las cifras públicas: IAREPO_PROOF_MIN_* de shared/labels.php,
// los MISMOS que la ficha.
const PF_PER_PAGE           = 24;   // la cuenta del catálogo tiene cientos de recursos

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { header('Location: /'); exit; }

$db          = getResourcesDB();
$sessionUser = getSessionUser();
$viewer      = authenticate();   // la forma que espera canView()
$isOwn       = $sessionUser && (int)$sessionUser['id'] === $userId;

// Persona. Sin email: no se usa.
$userStmt = $db->prepare("SELECT name, avatar_url, role, created_at FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$dbUser = $userStmt->fetch() ?: null;

// ⛔ Perfil de alumno: solo lo ve él. Al resto, el 404 de la web.
$isPrivateStudentProfile = ($dbUser['role'] ?? '') === 'student' && !$isOwn;
if ($isPrivateStudentProfile) { require __DIR__ . '/../404.php'; exit; }
$isStudent = ($dbUser['role'] ?? '') === 'student';

// Totales de lo público. Una sola fila (sin GROUP BY), también con 0 recursos.
$statsStmt = $db->prepare("
    SELECT COUNT(*) AS resource_count,
           MAX(author_display_name) AS display_name,
           COALESCE(SUM(view_count + unique_views), 0) AS total_opens,
           COALESCE(SUM(use_count), 0) AS total_uses,
           COALESCE(SUM(like_count), 0) AS total_likes,
           MIN(created_at) AS first_published
    FROM resources
    WHERE author_user_id = ? AND author_tenant_id = 0 AND is_active = 1 AND visibility = 'community'
");
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch() ?: [];
$resourceCount = (int)($stats['resource_count'] ?? 0);

// Ni cuenta ni recursos: no hay perfil que enseñar.
if (!$dbUser && $resourceCount === 0) { require __DIR__ . '/../404.php'; exit; }

// ⛔ Un perfil solo es público si su dueño ha PUBLICADO algo: un recurso o
// una lista pública. users.role vale 'teacher' por defecto y «Saltar por
// ahora» lo conserva, así que un alumno que se salta la pregunta tenía un
// perfil público e indexable con su nombre real y su foto de Google, y los
// id son secuenciales [revisión 2026-09]. Mismo 404 que un alumno (no
// confirma que la cuenta exista). Su dueño sí lo ve.
if (!$isOwn && $resourceCount === 0) {
    $pubLists = $db->prepare("SELECT COUNT(*) FROM collections WHERE user_id = ? AND is_public = 1");
    $pubLists->execute([$userId]);
    if ((int) $pubLists->fetchColumn() === 0) { require __DIR__ . '/../404.php'; exit; }
}
// A los buscadores, solo el perfil de quien publica recursos.
$indexable = !$isStudent && $resourceCount > 0;

$name        = (string)($dbUser['name'] ?? '') !== '' ? (string)$dbUser['name'] : (string)($stats['display_name'] ?? t('Docente'));
$avatar      = (string)($dbUser['avatar_url'] ?? '');
$memberSince = (string)($dbUser['created_at'] ?? ($stats['first_published'] ?? ''));

// Recursos públicos, paginados. Orden: lo último publicado primero — NUNCA
// por popularidad (no hay datos todavía, AGENTS.md §6.8).
$pages  = max(1, (int)ceil($resourceCount / PF_PER_PAGE));
$page   = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * PF_PER_PAGE;
$resources = [];
if ($resourceCount > 0) {
    $resStmt = $db->prepare("
        SELECT r.id, r.title, r.description, r.code_type, r.level, r.lang, r.topic_tag,
               r.source_name, r.source_url, r.view_count, r.unique_views, r.use_count,
               IF(r.code_type = 'url', r.code_content, NULL) AS link_url,
               c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
        FROM resources r LEFT JOIN categories c ON r.category_id = c.id
        WHERE r.author_user_id = ? AND r.author_tenant_id = 0 AND r.is_active = 1 AND r.visibility = 'community'
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT " . PF_PER_PAGE . " OFFSET " . (int)$offset);
    $resStmt->execute([$userId]);
    $resources = array_map('iarepo_with_labels', $resStmt->fetchAll());
}

// Listas: las públicas; en tu perfil, también las privadas.
$collStmt = $db->prepare("SELECT id, title, description, is_public FROM collections WHERE user_id = ?"
    . ($isOwn ? '' : ' AND is_public = 1') . " ORDER BY created_at DESC, id DESC");
$collStmt->execute([$userId]);
$collections = $collStmt->fetchAll();

// Por lista: cuántos recursos ve QUIEN MIRA (item_count es un contador
// desnormalizado que incluye borradores) y las portadas de los tres primeros.
$collPreview = [];
if ($collections) {
    $ids = implode(',', array_map(static fn($c) => (int)$c['id'], $collections));
    $rows = $db->query("
        SELECT ci.collection_id, r.visibility, r.author_tenant_id, r.author_user_id,
               c.slug AS category_slug, c.icon AS category_icon, c.name AS category_name
        FROM collection_items ci
        JOIN resources r ON r.id = ci.resource_id AND r.is_active = 1
        LEFT JOIN categories c ON c.id = r.category_id
        WHERE ci.collection_id IN ($ids)
        ORDER BY ci.collection_id, ci.added_at ASC, ci.id ASC
    ")->fetchAll();
    foreach ($rows as $row) {
        if (!canView($row, $viewer))
            continue;
        $cid = (int)$row['collection_id'];
        $collPreview[$cid]['count'] = ($collPreview[$cid]['count'] ?? 0) + 1;
        if (count($collPreview[$cid]['covers'] ?? []) < 3)
            $collPreview[$cid]['covers'][] = $row;
    }
}

/** «septiembre de 2026» / «September 2026». Sin intl: puede no estar en el hosting. */
function pf_month_year(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false)
        return '';
    $months = [t('enero'), t('febrero'), t('marzo'), t('abril'), t('mayo'), t('junio'), t('julio'),
               t('agosto'), t('septiembre'), t('octubre'), t('noviembre'), t('diciembre')];
    return sprintf(t('%1$s de %2$s'), $months[(int)date('n', $ts) - 1], date('Y', $ts));
}


/**
 * Tarjeta de un recurso: portada, título, descripción, curso · idioma y, solo
 * si superan el umbral, las cifras públicas. Toda la tarjeta enlaza a la ficha
 * (.ia-card-link, con el título como nombre accesible).
 */
function pf_card(array $r): string
{
    $rid   = (int)$r['id'];
    $opens = (int)$r['view_count'] + (int)$r['unique_views'];
    $uses  = (int)$r['use_count'];
    $proof = [];
    if ($opens >= IAREPO_PROOF_MIN_OPENS)
        $proof[] = '<span><i data-lucide="users" aria-hidden="true"></i> ' . h(sprintf(t('Abierto por %s personas'), iarepo_num($opens))) . '</span>';
    if ($uses >= IAREPO_PROOF_MIN_TEACHERS)
        $proof[] = '<span><i data-lucide="graduation-cap" aria-hidden="true"></i> ' . h(t('Usado en clase por')) . ' ' . iarepo_num($uses) . ' ' . h(t('docentes')) . '</span>';

    return '<article class="ia-card">'
        . iarepo_cover($r)
        . '<div class="ia-card-body">'
        .   '<h3 class="ia-card-title" id="pr-' . $rid . '">' . h((string)$r['title']) . '</h3>'
        .   ((string)$r['description'] !== '' ? '<p class="ia-card-desc">' . h((string)$r['description']) . '</p>' : '')
        .   '<div class="ia-card-meta">' . iarepo_card_meta($r, false) . '</div>'
        .   ($proof ? '<div class="ia-card-meta pf-proof">' . implode('', $proof) . '</div>' : '')
        . '</div>'
        . '<a class="ia-card-link" href="/resource/' . $rid . '" aria-labelledby="pr-' . $rid . '"></a>'
        . '</article>';
}

// ── Textos de cabecera y metadatos ──────────────────────────────
$since      = $memberSince !== '' ? pf_month_year($memberSince) : '';
$sinceText  = $since === '' ? '' : sprintf($isStudent ? t('Aprendiendo en iarepo desde %s') : t('Docente en iarepo desde %s'), $since);
$countText  = $resourceCount === 1 ? t('1 recurso publicado') : sprintf(t('%s recursos publicados'), iarepo_num($resourceCount));
$metaDesc   = $resourceCount > 0
    ? sprintf(t('%1$s comparte %2$s recursos educativos interactivos en iarepo: simulaciones gratuitas, clasificadas por curso.'), $name, iarepo_num($resourceCount))
    : sprintf(t('Perfil de %s, docente en iarepo.'), $name);
$totalOpens = (int)($stats['total_opens'] ?? 0);
$totalUses  = (int)($stats['total_uses'] ?? 0);
$selfPath   = '/profile/' . $userId;
$canonical  = 'https://iarepo.com' . $selfPath . ($page > 1 ? '?page=' . $page : '');
$pageUrl    = static fn(int $p): string => $selfPath . ($p > 1 ? '?page=' . $p : '');
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($name) ?> — <?= h(t('Recursos educativos en iarepo')) ?></title>
<?php if (!$indexable): ?>
<meta name="robots" content="noindex">
<?php else: ?>
<meta name="description" content="<?= h($metaDesc) ?>">
<meta property="og:title" content="<?= h($name) ?> — iarepo">
<meta property="og:description" content="<?= h($metaDesc) ?>">
<meta property="og:type" content="profile">
<meta property="og:url" content="<?= h($canonical) ?>">
<?php if ($avatar): ?><meta property="og:image" content="<?= h($avatar) ?>"><?php endif; ?>
<link rel="canonical" href="<?= h($canonical) ?>">
<script type="application/ld+json">
<?= json_encode(array_filter([
    '@context'   => 'https://schema.org',
    '@type'      => 'Person',
    'name'       => $name,
    'url'        => 'https://iarepo.com' . $selfPath,
    'image'      => $avatar ?: null,
    'jobTitle'   => t('Docente'),
    'knowsAbout' => t('Recursos educativos interactivos'),
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>

</script>
<?php endif; ?>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<?= iarepo_pwa_script() ?>
<style>
/* Solo lo propio del perfil; lo común vive en assets/css/app.css. */
/* Tarjetas: .ia-cards-meta (app.css) + iarepo_card_meta() (shared/ui.php). */
.profile-header { padding: 36px 0 8px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.pf-avatar { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; flex: none; border: 3px solid var(--ia-surface); box-shadow: var(--ia-shadow); }
.pf-initial { display: grid; place-items: center; background: var(--ia-accent-soft); color: var(--ia-accent); font-size: 2.4rem; font-weight: 800; }
.pf-id { min-width: 0; flex: 1 1 280px; }
.pf-id h1 { margin-bottom: 6px; overflow-wrap: anywhere; }
.pf-facts { display: flex; flex-wrap: wrap; gap: 6px 14px; color: var(--ia-ink-2); margin: 0; }
.pf-facts span { display: inline-flex; align-items: center; gap: 6px; }
.pf-facts svg, .pf-proof svg { width: 16px; height: 16px; flex: none; }
.pf-proof { gap: 4px 12px; padding-top: 2px; }
.pf-proof span { display: inline-flex; align-items: center; gap: 5px; }
/* Bloque «solo tú lo ves»: tus cifras y cómo usas iarepo. */
.pf-own { display: grid; gap: 16px; margin-top: 20px; padding: 16px; border-radius: var(--ia-radius); background: var(--ia-surface); border: 1px dashed var(--ia-line-strong); }
.pf-own-head { display: flex; align-items: center; justify-content: space-between; gap: 8px 12px; flex-wrap: wrap; }
.pf-own-head p { margin: 0; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
.pf-own-head svg { width: 18px; height: 18px; }
.pf-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin: 0; }
.pf-stats div { padding: 10px 12px; border-radius: 10px; background: var(--ia-surface-2); }
.pf-stats dt { font-size: .8125rem; color: var(--ia-ink-3); font-weight: 600; }
.pf-stats dd { margin: 0; font-size: 1.4rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.pf-role { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.pf-role form { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; }
.pf-role .ia-muted { margin: 0; flex-basis: 100%; }
.pf-role .ia-chip { white-space: normal; text-align: left; }   /* «Estoy aprendiendo (en clase o por mi cuenta)» no cabe en una línea de móvil */
/* Lista: tira de hasta tres portadas pequeñas (las de sus primeros recursos). */
.pf-list-covers { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2px; aspect-ratio: 16 / 7; background: var(--ia-line); }
.pf-list-covers .ia-cover { aspect-ratio: auto; height: 100%; }
.pf-list-covers .ia-cover-icon { right: 50%; transform: translate(50%, -50%); width: 46%; }
.pf-list-covers .ia-cover-source, .pf-list-covers .ia-cover-topic, .pf-list-covers .ia-cover-badge { display: none; }
.pf-list-covers-1 { grid-template-columns: 1fr; }
.pf-list-covers-2 { grid-template-columns: 1fr 1fr; }
.pf-pager { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
@media (max-width: 559px) {
  .profile-header { padding-top: 24px; gap: 14px; }
  .pf-avatar { width: 72px; height: 72px; }
  .pf-initial { font-size: 1.8rem; }
  .pf-list-covers { aspect-ratio: 16 / 5; }   /* en móvil, la tira no se come la pantalla */
}
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($sessionUser, ''); ?>

<main id="main" class="ia-container">
  <!-- .profile-header: la busca quality/smoke_test.sh («Profile page») -->
  <section class="profile-header" aria-labelledby="pf-name">
    <?php if ($avatar): ?>
      <img class="pf-avatar" src="<?= h($avatar) ?>" alt="" width="96" height="96" referrerpolicy="no-referrer">
    <?php else: ?>
      <div class="pf-avatar pf-initial" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></div>
    <?php endif; ?>
    <div class="pf-id">
      <h1 id="pf-name"><?= h($name) ?></h1>
      <p class="pf-facts">
        <?php if ($sinceText !== ''): ?><span><i data-lucide="<?= $isStudent ? 'book-open' : 'school' ?>" aria-hidden="true"></i><?= h($sinceText) ?></span><?php endif; ?>
        <?php if ($resourceCount > 0): ?><span><i data-lucide="layout-grid" aria-hidden="true"></i><?= h($countText) ?></span><?php endif; ?>
        <?php if (!$isOwn && $totalOpens >= IAREPO_PROOF_MIN_OPENS): ?><span><i data-lucide="users" aria-hidden="true"></i><?= h(sprintf(t('abiertos %s veces'), iarepo_num($totalOpens))) ?></span><?php endif; ?>
        <?php if (!$isOwn && $totalUses >= IAREPO_PROOF_MIN_TEACHERS): ?><span><i data-lucide="graduation-cap" aria-hidden="true"></i><?= h(sprintf(t('usados en clase %s veces'), iarepo_num($totalUses))) ?></span><?php endif; ?>
      </p>
    </div>
  </section>

  <?php if ($isOwn): ?>
  <!-- Solo en tu propio perfil: tus cifras reales (también a cero) y cómo usas iarepo. -->
  <section class="pf-own" aria-labelledby="pf-own-title">
    <div class="pf-own-head">
      <p id="pf-own-title"><i data-lucide="eye-off" aria-hidden="true"></i><?= h(t('Solo tú ves este bloque')) ?></p>
      <?php if (!$isStudent): ?><a class="ia-btn ia-btn-secondary ia-btn-sm" href="/dashboard/"><i data-lucide="layout-dashboard" aria-hidden="true"></i><?= h(t('Mi panel')) ?></a><?php endif; ?>
    </div>
    <?php if (!$isStudent): ?>
    <dl class="pf-stats">
      <div><dt><?= h(t('Recursos públicos')) ?></dt><dd><?= iarepo_num($resourceCount) ?></dd></div>
      <div><dt><?= h(t('Aperturas')) ?></dt><dd><?= iarepo_num($totalOpens) ?></dd></div>
      <div><dt><?= h(t('Usos en clase')) ?></dt><dd><?= iarepo_num($totalUses) ?></dd></div>
      <div><dt><?= h(t('Me gusta')) ?></dt><dd><?= iarepo_num((int)($stats['total_likes'] ?? 0)) ?></dd></div>
    </dl>
    <?php endif; ?>
    <?php if (in_array($sessionUser['role'] ?? 'teacher', ['teacher', 'student'], true)): ?>
    <div class="pf-role">
      <span class="ia-small"><strong><?= h(t('Uso iarepo como:')) ?></strong></span>
      <form method="post" action="/auth/onboarding.php">
        <input type="hidden" name="csrf" value="<?= h(iarepo_csrf_token()) ?>">
        <input type="hidden" name="return_url" value="<?= h($selfPath) ?>">
        <button type="submit" name="role" value="teacher" class="ia-chip" aria-pressed="<?= $isStudent ? 'false' : 'true' ?>"><?= h(t('Doy clase')) ?></button>
        <button type="submit" name="role" value="student" class="ia-chip" aria-pressed="<?= $isStudent ? 'true' : 'false' ?>"><?= h(t('Estoy aprendiendo (en clase o por mi cuenta)')) ?></button>
      </form>
      <p class="ia-muted ia-small"><?= h($isStudent ? t('Tu perfil no es público: solo tú lo ves.') : t('Si eliges «Estoy aprendiendo», tu perfil deja de ser público.')) ?></p>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php /* Las listas van ANTES que los recursos: son pocas (20 como mucho) y
           curadas; detrás de 24 tarjetas y un paginador no las encontraba nadie.
           Solo en la primera página: la ?page=N es para seguir viendo recursos. */ ?>
  <?php if ($collections && $page === 1): ?>
  <section class="ia-section" aria-labelledby="pf-lists-title">
    <div class="ia-section-head"><h2 id="pf-lists-title"><?= h($isOwn ? t('Tus listas') : t('Listas')) ?></h2></div>
    <div class="ia-grid">
      <?php foreach ($collections as $col):
        $cid    = (int)$col['id'];
        $covers = $collPreview[$cid]['covers'] ?? [];
        $n      = (int)($collPreview[$cid]['count'] ?? 0);
        $nCov   = max(1, count($covers));
      ?>
        <article class="ia-card">
          <div class="pf-list-covers pf-list-covers-<?= $nCov ?>" aria-hidden="true">
            <?php if ($covers): foreach ($covers as $cv) echo iarepo_cover($cv, ''); else: ?>
              <?= iarepo_cover(['category_icon' => 'list-ordered'], '') ?>
            <?php endif; ?>
          </div>
          <div class="ia-card-body">
            <h3 class="ia-card-title" id="pl-<?= $cid ?>"><?= h((string)$col['title']) ?></h3>
            <?php if ((string)$col['description'] !== ''): ?><p class="ia-card-desc"><?= h((string)$col['description']) ?></p><?php endif; ?>
            <div class="ia-card-meta">
              <span><?= h($n === 1 ? t('1 recurso') : sprintf(t('%s recursos'), iarepo_num($n))) ?></span>
              <?php if ($isOwn): ?><span class="ia-tag<?= $col['is_public'] ? ' ia-tag-ok' : '' ?>"><?= h($col['is_public'] ? t('Pública') : t('Privada')) ?></span><?php endif; ?>
            </div>
          </div>
          <a class="ia-card-link" href="/collection/?id=<?= $cid ?>" aria-labelledby="pl-<?= $cid ?>"></a>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!$isStudent || $resourceCount > 0): ?>
  <section class="ia-section" aria-labelledby="pf-res-title">
    <div class="ia-section-head">
      <h2 id="pf-res-title"><?= h($isOwn ? t('Tus recursos públicos') : t('Recursos publicados')) ?></h2>
      <?php if ($pages > 1): ?><span class="ia-muted ia-small"><?= h(sprintf(t('Página %1$s de %2$s'), $page, $pages)) ?></span><?php endif; ?>
    </div>
    <?php if ($resources): ?>
      <div class="ia-grid ia-grid-rows-mobile ia-cards-meta">
        <?php foreach ($resources as $r) echo pf_card($r); ?>
      </div>
      <?php if ($pages > 1): ?>
      <nav class="pf-pager" aria-label="<?= h(t('Páginas')) ?>">
        <?php if ($page > 1): ?><a class="ia-btn ia-btn-secondary" rel="prev" href="<?= h($pageUrl($page - 1)) ?>"><i data-lucide="chevron-left" aria-hidden="true"></i><?= h(t('Anteriores')) ?></a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="ia-btn ia-btn-secondary" rel="next" href="<?= h($pageUrl($page + 1)) ?>"><?= h(t('Siguientes')) ?><i data-lucide="chevron-right" aria-hidden="true"></i></a><?php endif; ?>
      </nav>
      <?php endif; ?>
    <?php elseif ($isOwn): ?>
      <div class="ia-empty">
        <h3><?= h(t('Aún no has publicado nada')) ?></h3>
        <p><?= h(t('¿Has hecho una simulación con IA para tu clase? Compártela con otros docentes.')) ?></p>
        <a class="ia-btn ia-btn-primary" href="/dashboard/editor.php"><i data-lucide="upload" aria-hidden="true"></i><?= h(t('Publicar un recurso')) ?></a>
      </div>
    <?php else: ?>
      <div class="ia-empty"><p><?= h(t('Aún no ha publicado recursos.')) ?></p></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($isStudent): ?>
  <section class="ia-section">
    <div class="ia-empty">
      <h3><?= h(t('Lo que guardas está en Guardados')) ?></h3>
      <a class="ia-btn ia-btn-primary" href="/favorites/"><i data-lucide="star" aria-hidden="true"></i><?= h(t('Ir a Guardados')) ?></a>
    </div>
  </section>
  <?php endif; ?>
</main>

<?php iarepo_footer($sessionUser); ?>
<?= iarepo_body_assets() ?>
</body>
</html>
