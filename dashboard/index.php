<?php
// ================================================================
// dashboard/index.php — Mi panel (el espacio del docente que publica)
//
// Para qué sirve: que quien publica vea, de un vistazo, si lo que ha
// publicado SIRVE. Por eso las cifras de arriba son las del autor, reales y
// también a cero (a él sí se le enseñan: en público solo salen con umbral,
// ver resource/index.php):
//   · Recursos          cuántos, y cuántos son públicos o borradores.
//   · Abierto por       view_count (histórico, CONGELADO desde 2026-08-06)
//                       + unique_views (lo vivo, api/track.php). Es la misma
//                       suma que usa la ficha: el número no se desploma el día
//                       que cambió la medición. AGENTS.md §6.8.
//   · Usos en clase     use_count («Lo usé en clase», solo docentes).
//   · Me gusta          recuento real de resource_likes.
//   · Versiones de otros docentes  versiones PÚBLICAS hechas por otros. NO es
//                       fork_count: ese cuenta también los borradores (casi
//                       todos, porque nacen privados) y prometía versiones que
//                       nadie podía abrir.
//
// Dos pestañas: «Mis recursos» y «Mis listas» (antes «Colecciones»; la tabla
// sigue siendo collections y NO se unifica con los favoritos, CLAUDE.md §6.1).
// /dashboard/#listas abre la segunda; #collections sigue valiendo para los
// enlaces viejos.
//
// Piezas comunes: shared/ui.php (cabecera, pie, portada, diálogo «Mandar a
// mis alumnos»), shared/labels.php (cómo se nombra cada cosa) y
// assets/css/app.css (.ia-*). El <style> de abajo es solo lo propio del panel
// (prefijo .db-).
//
// Antirregresión: tests/unit/account_pages_test.php (cifras, vocabulario,
// pestañas) y tests/integration/render_pages_test.php (la página entera, con
// sesión de profesor, y su script completo).
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/ui.php';
require_once __DIR__ . '/../shared/activity.php';
// h() local — NO se carga shared/helpers.php: su error_handler vuelca JSON y
// corta la página a medias ante cualquier error (CLAUDE.md §2.1).
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

$user = getSessionUser();
if (!$user) { header('Location: /auth/signin.php?return_url=' . rawurlencode('/dashboard/')); exit; }
// Quien está aprendiendo no publica: su espacio es «Guardados».
if (($user['role'] ?? '') === 'student') { header('Location: /favorites/'); exit; }

$db  = getResourcesDB();
$uid = (int) $user['id'];

// ── Utilidades de la página (puras) ────────────────────────────

/** Fecha corta en el idioma de la interfaz. */
function db_date(?string $ts): string
{
    $t = strtotime((string) $ts) ?: time();
    return lang() === 'en' ? date('M j, Y', $t) : date('d/m/Y', $t);
}

/** Singular o plural según $n; las dos plantillas llegan ya traducidas (t() con literal). */
function db_plural(int $n, string $one, string $many): string
{
    return sprintf($n === 1 ? $one : $many, iarepo_num($n));
}

/** «hace 3 h» / «3 h ago»: antigüedad corta de una fecha de la BD. */
function db_ago(?string $ts): string
{
    $d = max(0, time() - (strtotime((string) $ts) ?: time()));
    if ($d < 60)
        return t('ahora');
    $n = match (true) {
        $d < 3600  => round($d / 60) . ' min',
        $d < 86400 => round($d / 3600) . ' h',
        default    => round($d / 86400) . ' d',
    };
    return sprintf(t('hace %s'), $n);
}

/**
 * Visibilidad en palabras → [etiqueta, clase de .ia-tag].
 *
 * 'school' y 'area' significan «el mismo tenant». En iarepo.com todas las
 * cuentas de Google comparten el tenant 0 (shared/auth.php), así que para
 * ellas NO es «tu centro»: es cualquiera con cuenta. Decir «Tu centro» ahí
 * sería prometer una privacidad que no existe. Solo se dice con tenant > 0.
 */
function db_visibility(string $vis, int $tenant = 0): array
{
    return match ($vis) {
        'community'      => [t('Pública'), 'ia-tag-ok'],
        'school', 'area' => [iarepo_restricted_label($vis, $tenant), ''],   // shared/labels.php (la ficha dice lo mismo)
        default          => [t('Solo tú (borrador)'), 'ia-tag-new'],
    };
}

// ── Mis recursos ───────────────────────────────────────────────
//
// «Me gusta» se cuenta en resource_likes (como el listado de la API) y no en
// la columna like_count, que es un contador desnormalizado que puede mentir.
$stmt = $db->prepare("
    SELECT r.id, r.title, r.code_type, r.subject_area, r.topic_tag, r.visibility, r.level, r.lang,
           r.view_count, r.use_count, r.created_at, r.author_tenant_id,
           c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
           (SELECT COUNT(*) FROM resource_likes rl WHERE rl.resource_id = r.id) AS likes
    FROM resources r
    LEFT JOIN categories c ON c.id = r.category_id
    WHERE r.author_user_id = ? AND r.author_tenant_id = 0 AND r.is_active = 1
    ORDER BY r.created_at DESC, r.id DESC
");
$stmt->execute([$uid]);
$resources = array_map('iarepo_with_labels', $stmt->fetchAll(PDO::FETCH_ASSOC));
$myIds     = array_map('intval', array_column($resources, 'id'));

// Visitas únicas (migration_012). Consulta aparte a propósito: si el
// despliegue llegara antes que la migración, un ERROR 1054 aquí no puede
// llevarse el panel entero. Degrada a «solo el histórico» y lo deja en el log.
$uniqueViews = [];
try {
    $u = $db->prepare('SELECT id, unique_views FROM resources
                       WHERE author_user_id = ? AND author_tenant_id = 0 AND is_active = 1');
    $u->execute([$uid]);
    foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $row)
        $uniqueViews[(int) $row['id']] = (int) $row['unique_views'];
} catch (Throwable $e) {
    error_log('dashboard: unique_views no disponible (¿falta migration_012?): ' . $e->getMessage());
    $uniqueViews = [];
}

// Versiones PÚBLICAS hechas por OTROS docentes, por recurso raíz
// (root_id, migration_013). Mismo criterio que «Otras versiones» de la ficha.
// Degrada a cero si root_id no existe todavía.
$versionsBy = [];
try {
    if ($myIds) {
        $in = implode(',', array_fill(0, count($myIds), '?'));
        $v  = $db->prepare("
            SELECT root_id, COUNT(*) AS n
            FROM resources
            WHERE root_id IN ($in) AND id <> root_id AND is_active = 1 AND visibility = 'community'
              AND NOT (author_user_id = ? AND author_tenant_id = 0)
            GROUP BY root_id
        ");
        $v->execute([...$myIds, $uid]);
        foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $row)
            $versionsBy[(int) $row['root_id']] = (int) $row['n'];
    }
} catch (Throwable $e) {
    error_log('dashboard: linaje de versiones no disponible (¿falta migration_013?): ' . $e->getMessage());
    $versionsBy = [];
}

// ── «¿Les quedó claro?» — el agregado, sólo para el autor ────────
//
// Se muestra AQUÍ y no en la ficha pública a propósito: un contador de "me
// perdí" visible para cualquiera sería una picota. Es información para mejorar
// el recurso, no una nota.
//
// Nunca se sabe QUIÉN contestó qué: la tabla guarda un viewer_key hasheado con
// una sal diaria que se borra a los 2 días. El profesor ve cuántos, no cuáles.
//
// GROUP BY en vez de contadores desnormalizados: serían tres columnas más que
// mantener en sincronía, y este repo ya arrastra el caso contrario con
// fork_count. Una consulta de un milisegundo es mejor que un contador que
// puede mentir.
//
// try/catch por lo de siempre: un ERROR 1054 sin capturar —despliegue antes
// que migration_014— tumbaría la página (page_errors.php la cambiaría por un
// 500). Degradando a vacío, la sección simplemente no aparece.
$comprehension = [];
try {
    if ($resources) {
        $ids = array_column($resources, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $cStmt = $db->prepare("
            SELECT resource_id, answer, COUNT(*) AS n
            FROM resource_comprehension
            WHERE resource_id IN ($in)
            GROUP BY resource_id, answer
        ");
        $cStmt->execute($ids);
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $comprehension[(int)$row['resource_id']][$row['answer']] = (int)$row['n'];
        }
    }
} catch (Throwable $e) {
    error_log('dashboard: «¿les quedó claro?» no disponible (¿falta migration_014?): ' . $e->getMessage());
    $comprehension = [];
}

// ── Las cifras del autor (reales, también a cero) ──────────────
$totals = ['public' => 0, 'drafts' => 0, 'opens' => 0, 'uses' => 0, 'likes' => 0, 'versions' => 0];
foreach ($resources as &$r) {
    $rid = (int) $r['id'];
    // Abierto por = histórico congelado + visitas únicas (la ficha suma igual).
    $r['opens']    = (int) $r['view_count'] + ($uniqueViews[$rid] ?? 0);
    $r['uses']     = (int) $r['use_count'];
    $r['likes']    = (int) $r['likes'];
    $r['versions'] = $versionsBy[$rid] ?? 0;
    $totals['opens']    += $r['opens'];
    $totals['uses']     += $r['uses'];
    $totals['likes']    += $r['likes'];
    $totals['versions'] += $r['versions'];
    if ($r['visibility'] === 'community')
        $totals['public']++;
    elseif ($r['visibility'] === 'draft')
        $totals['drafts']++;
}
unset($r);

// ── Actividad reciente: lo que han hecho OTROS con tus recursos ──
// Las mismas fuentes y reglas que la campana: shared/activity.php.
$activity = iarepo_author_activity($db, $uid, $myIds, 6);
// Verbo por tipo. El nombre y el título se escapan al pintar: vienen de
// otras personas (antes se imprimían en crudo).
$activityVerb = ['like' => t('le dio «Me gusta» a'), 'fork' => t('hizo su versión de'), 'comment' => t('comentó en'), 'used' => t('usó en clase')];
$activityIcon = IAREPO_ACTIVITY_ICONS;

// ── Mis listas (tabla collections) ─────────────────────────────
// La descripción SÍ se lee: el diálogo de edición la necesita. Antes no se
// pedía, el diálogo salía vacío y al guardar se borraba la descripción.
$collStmt = $db->prepare('SELECT id, title, description, is_public, item_count, created_at
                          FROM collections WHERE user_id = ? ORDER BY created_at DESC, id DESC');
$collStmt->execute([$uid]);
$collections = $collStmt->fetchAll(PDO::FETCH_ASSOC);

$firstName = trim(explode(' ', (string) ($user['name'] ?? ''))[0]);
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('Mi panel')) ?> — iarepo</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<?= iarepo_pwa_script() ?>
<style>
/* Solo lo propio del panel (.db-*). Botones, etiquetas, filas, diálogo y
   tipografía salen de assets/css/app.css. */
.db-main { padding-top: 24px; }
.db-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px 16px; margin-bottom: 20px; }
.db-head h1 { font-size: clamp(1.7rem, 1.35rem + 1.3vw, 2.3rem); margin: 0; }
.db-head p { margin: 4px 0 0; }
.db-head-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; position: relative; }   /* wrap: a 360 px no cabían campana + perfil + «Publicar» */

/* Cifras del autor */
.db-stats { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0 0 28px; padding: 0; list-style: none; }
@media (min-width: 720px) { .db-stats { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
.db-stat { background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); padding: 14px 16px; }
.db-stat-l { display: block; font-size: .8125rem; font-weight: 700; color: var(--ia-ink-3); }
.db-stat-n { display: block; font-size: 1.9rem; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; }
.db-stat-u { display: block; font-size: .8125rem; color: var(--ia-ink-3); }
@media (max-width: 719px) { .db-stat:first-child { grid-column: 1 / -1; } }

/* Campana de novedades */
.db-bell { position: relative; }
.db-bell-badge { position: absolute; top: 2px; right: 2px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
  background: var(--ia-danger); color: #fff; font-size: .7rem; font-weight: 800; line-height: 18px; text-align: center; }
.db-notif { position: absolute; top: calc(100% + 8px); right: 0; z-index: 40; width: min(360px, calc(100vw - 32px));
  background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); box-shadow: var(--ia-shadow-lg); overflow: hidden; }
.db-notif h2 { font-size: 1rem; margin: 0; padding: 12px 16px; border-bottom: 1px solid var(--ia-line); }
.db-notif-list { max-height: 380px; overflow-y: auto; margin: 0; padding: 0; list-style: none; }

/* Actividad (misma pieza en la campana y en la sección) */
.db-act { display: flex; gap: 10px; align-items: flex-start; padding: 10px 16px; font-size: .9rem; color: var(--ia-ink); text-decoration: none; }
.ia-page a.db-act { color: var(--ia-ink); text-decoration: none; }
.db-act + .db-act { border-top: 1px solid var(--ia-line); }
a.db-act:hover { background: var(--ia-surface-2); }
.db-act-ico { flex: none; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; background: var(--ia-surface-2); color: var(--ia-ink-2); }
.db-act-ico svg { width: 16px; height: 16px; }
.db-act-time { display: block; font-size: .8125rem; color: var(--ia-ink-3); }
.db-activity { margin: 0 0 28px; padding: 0; list-style: none; background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); }
.db-empty-note { padding: 18px 16px; color: var(--ia-ink-3); font-size: .9rem; margin: 0; }

/* Pestañas */
.db-tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--ia-line); margin-bottom: 16px; overflow-x: auto; }
.db-tab { min-height: var(--ia-tap); padding: 8px 14px; margin-bottom: -2px; border: 0; border-bottom: 3px solid transparent; background: none;
  font: 700 1rem/1.2 var(--ia-font); color: var(--ia-ink-3); cursor: pointer; white-space: nowrap; }
.db-tab[aria-selected="true"] { color: var(--ia-ink); border-bottom-color: var(--ia-accent); }
.db-tab .ia-count { font-weight: 600; color: var(--ia-ink-3); }
.db-panel-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.db-panel-head .ia-input { flex: 1 1 220px; max-width: 360px; }

/* Filas de recurso y de lista */
.db-list { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
.db-item { align-items: flex-start; }
.db-item .ia-cover { width: 72px; }
.db-list-ico { flex: none; display: grid; place-items: center; width: 72px; height: 72px; border-radius: 10px; background: var(--ia-accent-soft); color: var(--ia-accent); }
/* En móvil, miniatura más pequeña: así Editar / Ver ficha / Eliminar caben en una fila. */
@media (max-width: 559px) { .db-item .ia-cover, .db-list-ico { width: 48px; } .db-list-ico { height: 48px; } }
.db-item-title { margin: 0 0 4px; font-size: 1.02rem; line-height: 1.3; }
.db-item-title a { color: var(--ia-ink); text-decoration: none; }
.db-item-title a:hover { color: var(--ia-accent); text-decoration: underline; }
.db-item-meta, .db-item-figs { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; margin: 0 0 4px; font-size: .875rem; color: var(--ia-ink-3); }
.db-item-figs { color: var(--ia-ink-2); column-gap: 16px; }
.db-item-figs strong { color: var(--ia-ink); font-variant-numeric: tabular-nums; }
.db-cmp-ok { color: var(--ia-ok); }
.db-cmp-lost { color: var(--ia-danger); }
.db-item-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.db-hint { font-size: .9rem; color: var(--ia-ink-3); margin: -18px 0 28px; }

/* Diálogo de lista */
.db-form { display: grid; gap: 12px; }
.db-form label { display: block; font-weight: 700; font-size: .9rem; margin-bottom: 4px; }
.db-form .ia-input, .db-form .ia-select { width: 100%; }
.db-form textarea.ia-input { min-height: 80px; resize: vertical; line-height: 1.4; }
.db-form-error { color: var(--ia-danger); font-weight: 600; margin: 0; }
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($user, 'teach'); ?>

<main id="main" class="ia-container db-main">
  <div class="db-head">
    <div>
      <h1><?= h(t('Mi panel')) ?></h1>
      <p class="ia-muted"><?= $firstName !== '' ? h(sprintf(t('Hola, %s. Aquí ves tus recursos y si están sirviendo en clase.'), $firstName)) : h(t('Aquí ves tus recursos y si están sirviendo en clase.')) ?></p>
    </div>
    <div class="db-head-tools">
      <button type="button" class="ia-btn ia-btn-secondary ia-btn-icon db-bell" id="notifBell" aria-expanded="false" aria-controls="notifPanel"
              aria-label="<?= h(t('Novedades')) ?>" title="<?= h(t('Novedades')) ?>">
        <i data-lucide="bell"></i><span class="db-bell-badge" id="notifBadge" hidden>0</span>
      </button>
      <div class="db-notif" id="notifPanel" hidden>
        <h2><?= h(t('Novedades')) ?></h2>
        <ul class="db-notif-list" id="notifList"><li class="db-empty-note"><?= h(t('Cargando…')) ?></li></ul>
      </div>
      <?php /* aria-label: en ≤ 480 px el texto se oculta (.ia-hide-xs) y el enlace
               se quedaba sin nombre para el lector de pantalla. */ ?>
      <a class="ia-btn ia-btn-ghost" href="/profile/<?= $uid ?>" aria-label="<?= h(t('Mi perfil público')) ?>"><i data-lucide="user-round"></i><span class="ia-hide-xs"><?= h(t('Mi perfil público')) ?></span></a>
      <a class="ia-btn ia-btn-primary" href="/dashboard/editor.php"><i data-lucide="upload"></i><?= h(t('Publicar un recurso')) ?></a>
    </div>
  </div>

  <?php /* Sin recursos, nada de cifras: un docente nuevo veía un muro de cinco
           ceros y el estado vacío útil («Publicar mi primer recurso») quedaba
           bajo el pliegue en el móvil [revisión 2026-09]. Desde el primero,
           sus cifras reales, también a cero. */ ?>
  <?php if ($resources): ?>
  <ul class="db-stats" aria-label="<?= h(t('Tus cifras')) ?>">
    <li class="db-stat"><span class="db-stat-l"><?= h(t('Recursos')) ?></span><span class="db-stat-n"><?= iarepo_num(count($resources)) ?></span>
      <span class="db-stat-u"><?= h(db_plural($totals['public'], t('%s público'), t('%s públicos')) . ' · ' . db_plural($totals['drafts'], t('%s borrador'), t('%s borradores'))) ?></span></li>
    <li class="db-stat"><span class="db-stat-l"><?= h(t('Abierto por')) ?></span><span class="db-stat-n"><?= iarepo_num($totals['opens']) ?></span>
      <span class="db-stat-u"><?= h(t('personas, en total')) ?></span></li>
    <li class="db-stat"><span class="db-stat-l"><?= h(t('Usos en clase')) ?></span><span class="db-stat-n"><?= iarepo_num($totals['uses']) ?></span>
      <span class="db-stat-u"><?= h(t('marcados por docentes')) ?></span></li>
    <li class="db-stat"><span class="db-stat-l"><?= h(t('Me gusta recibidos')) ?></span><span class="db-stat-n"><?= iarepo_num($totals['likes']) ?></span>
      <span class="db-stat-u"><?= h(t('de otras personas')) ?></span></li>
    <li class="db-stat"><span class="db-stat-l"><?= h(t('Versiones de otros docentes')) ?></span><span class="db-stat-n"><?= iarepo_num($totals['versions']) ?></span>
      <span class="db-stat-u"><?= h(t('públicas, basadas en las tuyas')) ?></span></li>
  </ul>
  <?php endif; ?>
  <?php if ($resources && $totals['opens'] === 0): ?>
  <p class="db-hint"><?= h(t('Cuando alguien abra tus recursos o los marque como usados en clase, lo verás aquí.')) ?></p>
  <?php endif; ?>

  <?php if ($activity): ?>
  <section aria-labelledby="actTitle">
    <div class="ia-section-head"><h2 id="actTitle"><?= h(t('Actividad reciente')) ?></h2></div>
    <ul class="db-activity">
      <?php foreach ($activity as $a): $type = isset($activityVerb[$a['type']]) ? $a['type'] : 'comment'; ?>
      <li><a class="db-act" href="/resource/<?= (int) $a['resource_id'] ?>">
        <span class="db-act-ico"><i data-lucide="<?= $activityIcon[$type] ?>"></i></span>
        <span><strong><?= h((string) ($a['actor'] ?? '') !== '' ? (string) $a['actor'] : t('Alguien que está aprendiendo')) ?></strong> <?= h($activityVerb[$type]) ?> <strong><?= h((string) $a['resource_title']) ?></strong>
          <span class="db-act-time"><?= h(db_ago($a['created_at'])) ?></span></span>
      </a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <div class="db-tabs" role="tablist" aria-label="<?= h(t('Mi panel')) ?>">
    <button type="button" class="db-tab" role="tab" id="tab-recursos" aria-controls="panel-recursos" aria-selected="true">
      <?= h(t('Mis recursos')) ?> <span class="ia-count" data-count="res"><?= count($resources) ?></span></button>
    <button type="button" class="db-tab" role="tab" id="tab-listas" aria-controls="panel-listas" aria-selected="false" tabindex="-1">
      <?= h(t('Mis listas')) ?> <span class="ia-count" data-count="coll"><?= count($collections) ?></span></button>
  </div>

  <!-- Mis recursos -->
  <section role="tabpanel" id="panel-recursos" aria-labelledby="tab-recursos">
    <?php if (!$resources): ?>
      <div class="ia-empty">
        <h3><?= h(t('Aún no has publicado nada')) ?></h3>
        <p><?= h(t('Pega una simulación hecha con IA o el enlace a una que ya exista. En un minuto está en el catálogo, con tu nombre.')) ?></p>
        <a class="ia-btn ia-btn-primary" href="/dashboard/editor.php"><i data-lucide="upload"></i><?= h(t('Publicar mi primer recurso')) ?></a>
      </div>
    <?php else: ?>
      <?php if (count($resources) > 8): ?>
      <div class="db-panel-head">
        <label class="ia-sr-only" for="resFilter"><?= h(t('Buscar en mis recursos')) ?></label>
        <input type="search" class="ia-input" id="resFilter" placeholder="<?= h(t('Buscar en mis recursos')) ?>" autocomplete="off">
      </div>
      <p class="ia-muted" id="resNoMatch" hidden><?= h(t('Ninguno de tus recursos coincide.')) ?></p>
      <?php endif; ?>
      <ul class="db-list" id="resList">
        <?php foreach ($resources as $r):
            $rid = (int) $r['id'];
            [$visLabel, $visClass] = db_visibility((string) $r['visibility'], (int) $r['author_tenant_id']);
            $cmp = $comprehension[$rid] ?? [];
        ?>
        <li class="ia-row db-item" id="res-<?= $rid ?>" data-q="<?= h(mb_strtolower((string) $r['title'])) ?>">
          <?= iarepo_cover($r) ?>
          <div class="ia-row-body">
            <h3 class="db-item-title"><a href="/resource/<?= $rid ?>"><?= h((string) $r['title']) ?></a></h3>
            <p class="db-item-meta">
              <span class="ia-tag <?= $visClass ?>"><?= h($visLabel) ?></span>
              <span><?= h(implode(' · ', array_filter([
                  $r['category_label'], iarepo_level_label($r['level'], false), $r['opens_label'], db_date($r['created_at']),
              ], static fn($x) => trim((string) $x) !== ''))) ?></span>
            </p>
            <p class="db-item-figs">
              <?php
              // Etiqueta + número en negrita, igual que las cifras de arriba: sin
              // plurales que traducir y sin «0 0» pegados. Me gusta y versiones
              // solo si los hay; aperturas y usos siempre (a cero también: es
              // lo que el autor quiere saber).
              $figs = [[t('Abierto por'), $r['opens']], [t('Usos en clase'), $r['uses']]];
              if ($r['likes'] > 0)
                  $figs[] = [t('Me gusta recibidos'), $r['likes']];
              if ($r['versions'] > 0)
                  $figs[] = [t('Versiones de otros docentes'), $r['versions']];
              foreach ($figs as [$label, $n]): ?>
              <span><?= h($label) ?> <strong><?= iarepo_num((int) $n) ?></strong></span>
              <?php endforeach; ?>
            </p>
            <?php
            // Sólo si alguien ha contestado. Un "0 de 0" no dice nada y
            // llenaría el panel de ruido en un catálogo recién estrenado.
            if ($cmp):
                $claro   = (int)($cmp['claro'] ?? 0);
                $regular = (int)($cmp['regular'] ?? 0);
                $perdido = (int)($cmp['perdido'] ?? 0);
            ?>
            <p class="db-item-figs" title="<?= h(t('Respuestas anónimas de quienes usaron el recurso')) ?>">
              <span><?= h(t('¿Les quedó claro?')) ?></span>
              <span class="db-cmp-ok"><?= h(t('Sí')) ?>: <strong><?= $claro ?></strong></span>
              <span><?= h(t('Más o menos')) ?>: <strong><?= $regular ?></strong></span>
              <span class="db-cmp-lost"><?= h(t('Se perdieron')) ?>: <strong><?= $perdido ?></strong></span>
            </p>
            <?php endif; ?>
            <div class="db-item-actions">
              <a class="ia-btn ia-btn-secondary ia-btn-sm" href="/dashboard/editor.php?id=<?= $rid ?>"><i data-lucide="pencil"></i><?= h(t('Editar')) ?></a>
              <a class="ia-btn ia-btn-ghost ia-btn-sm" href="/resource/<?= $rid ?>" aria-label="<?= h(t('Ver ficha')) ?>"><i data-lucide="eye"></i><span class="ia-hide-xs"><?= h(t('Ver ficha')) ?></span></a>
              <button type="button" class="ia-btn ia-btn-ghost ia-btn-sm ia-btn-icon" data-delete-res="<?= $rid ?>" data-title="<?= h((string) $r['title']) ?>"
                      aria-label="<?= h(sprintf(t('Eliminar «%s»'), (string) $r['title'])) ?>" title="<?= h(t('Eliminar')) ?>"><i data-lucide="trash-2"></i></button>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- Mis listas (tabla collections) -->
  <section role="tabpanel" id="panel-listas" aria-labelledby="tab-listas" hidden>
    <div class="db-panel-head">
      <p class="ia-muted" style="margin:0"><?= h(t('Agrupa recursos por tema o por grupo de clase y mándaselos a tus alumnos con un enlace.')) ?></p>
      <button type="button" class="ia-btn ia-btn-primary" id="newListBtn"><i data-lucide="plus"></i><?= h(t('Nueva lista')) ?></button>
    </div>
    <?php if (!$collections): ?>
      <div class="ia-empty">
        <h3><?= h(t('Aún no tienes listas')) ?></h3>
        <p><?= h(t('Crea una aquí o desde cualquier recurso con «Añadir a una lista».')) ?></p>
      </div>
    <?php else: ?>
      <ul class="db-list" id="collList">
        <?php foreach ($collections as $c): $cid = (int) $c['id']; $public = (bool) $c['is_public']; ?>
        <li class="ia-row db-item" id="coll-<?= $cid ?>">
          <span class="db-list-ico" aria-hidden="true"><i data-lucide="list"></i></span>
          <div class="ia-row-body">
            <h3 class="db-item-title"><a href="/collection/?id=<?= $cid ?>"><?= h((string) $c['title']) ?></a></h3>
            <p class="db-item-meta">
              <span class="ia-tag <?= $public ? 'ia-tag-ok' : 'ia-tag-new' ?>"><?= h($public ? t('Pública') : t('Solo tú')) ?></span>
              <span><?= h(db_plural((int) $c['item_count'], t('%s recurso'), t('%s recursos')) . ' · ' . db_date($c['created_at'])) ?></span>
            </p>
            <div class="db-item-actions">
              <?php if ($public): ?>
              <button type="button" class="ia-btn ia-btn-secondary ia-btn-sm" data-send-list="<?= $cid ?>" data-title="<?= h((string) $c['title']) ?>"><i data-lucide="send"></i><?= h(t('Mandar a mis alumnos')) ?></button>
              <?php endif; ?>
              <button type="button" class="ia-btn ia-btn-ghost ia-btn-sm" data-edit-list="<?= $cid ?>" data-title="<?= h((string) $c['title']) ?>"
                      data-desc="<?= h((string) ($c['description'] ?? '')) ?>" data-public="<?= $public ? '1' : '0' ?>"><i data-lucide="pencil"></i><?= h(t('Editar')) ?></button>
              <button type="button" class="ia-btn ia-btn-ghost ia-btn-sm ia-btn-icon" data-delete-list="<?= $cid ?>" data-title="<?= h((string) $c['title']) ?>"
                      aria-label="<?= h(sprintf(t('Eliminar «%s»'), (string) $c['title'])) ?>" title="<?= h(t('Eliminar')) ?>"><i data-lucide="trash-2"></i></button>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>

<!-- Nueva lista / Editar lista: un solo diálogo, dos modos. -->
<dialog class="ia-dialog" id="listDialog" aria-labelledby="listDialogTitle">
  <form class="ia-dialog-inner db-form" id="listForm" method="dialog" novalidate>
    <div class="ia-dialog-head">
      <h2 id="listDialogTitle"><?= h(t('Nueva lista')) ?></h2>
      <button type="button" class="ia-btn ia-btn-ghost ia-btn-icon" data-dialog-close aria-label="<?= h(t('Cerrar')) ?>"><i data-lucide="x"></i></button>
    </div>
    <div>
      <label for="listTitle"><?= h(t('Nombre *')) ?></label>
      <input type="text" class="ia-input" id="listTitle" maxlength="150" placeholder="<?= h(t('Ej.: Ondas — 4.º de ESO')) ?>">
    </div>
    <div>
      <label for="listDesc"><?= h(t('Descripción')) ?></label>
      <textarea class="ia-input" id="listDesc" maxlength="500" placeholder="<?= h(t('Para qué es esta lista (opcional)')) ?>"></textarea>
    </div>
    <div>
      <label for="listPublic"><?= h(t('Quién puede verla')) ?></label>
      <select class="ia-select" id="listPublic">
        <option value="1"><?= h(t('Pública: cualquiera con el enlace')) ?></option>
        <option value="0"><?= h(t('Solo tú')) ?></option>
      </select>
    </div>
    <p class="db-form-error" id="listError" role="alert" hidden></p>
    <div class="ia-dialog-actions">
      <button type="submit" class="ia-btn ia-btn-primary" id="listSave"><?= h(t('Crear lista')) ?></button>
      <button type="button" class="ia-btn ia-btn-secondary" data-dialog-close><?= h(t('Cancelar')) ?></button>
    </div>
  </form>
</dialog>

<?php iarepo_send_dialog(); ?>
<?php iarepo_footer($user); ?>
<?= iarepo_body_assets(true) ?>
<script>
const T = {
  learner: <?= json_encode(t('Alguien que está aprendiendo')) ?>,
  nameRequired: <?= json_encode(t('Ponle un nombre a la lista.')) ?>,
  creating: <?= json_encode(t('Creando...')) ?>,
  saving: <?= json_encode(t('Guardando...')) ?>,
  createList: <?= json_encode(t('Crear lista')) ?>,
  saveChanges: <?= json_encode(t('Guardar cambios')) ?>,
  newList: <?= json_encode(t('Nueva lista')) ?>,
  editList: <?= json_encode(t('Editar lista')) ?>,
  confirmDelList: <?= json_encode(t('¿Eliminar la lista «%s»? Los recursos no se borran.')) ?>,
  confirmDelRes: <?= json_encode(t('¿Eliminar el recurso «%s»? Esta acción no se puede deshacer.')) ?>,
  resDeleted: <?= json_encode(t('Recurso eliminado')) ?>,
  listDeleted: <?= json_encode(t('Lista eliminada')) ?>,
  linkCopied: <?= json_encode(t('Enlace copiado')) ?>,
  // Campana de novedades
  notifEmpty: <?= json_encode(t('Aún no hay novedades. Cuando alguien use en clase un recurso tuyo, haga su versión, lo comente o le dé «Me gusta», aparecerá aquí.')) ?>,
  notifError: <?= json_encode(t('No se pudieron cargar las novedades.')) ?>,
  verb: {
    like: <?= json_encode(t('le dio «Me gusta» a')) ?>,
    fork: <?= json_encode(t('hizo su versión de')) ?>,
    comment: <?= json_encode(t('comentó en')) ?>,
    used: <?= json_encode(t('usó en clase')) ?>,
  },
  now: <?= json_encode(t('ahora')) ?>,
  ago: <?= json_encode(t('hace %s')) ?>,
  // Errores de la API: por CÓDIGO, nunca por el texto (que va en inglés y
  // es un contrato con Campus, no un mensaje para la persona).
  err: {
    MISSING_TITLE: <?= json_encode(t('Ponle un nombre a la lista.')) ?>,
    COLLECTION_LIMIT: <?= json_encode(t('Has llegado al máximo de 20 listas')) ?>,
    COLLECTION_NOT_FOUND: <?= json_encode(t('Esa lista ya no existe. Recarga la página.')) ?>,
    NOT_COLLECTION_OWNER: <?= json_encode(t('Esa lista no es tuya.')) ?>,
    NOT_FOUND: <?= json_encode(t('Ese recurso ya no existe. Recarga la página.')) ?>,
    RATE_LIMITED: <?= json_encode(t('Demasiados cambios seguidos. Espera un minuto y vuelve a intentarlo.')) ?>,
    NETWORK: <?= json_encode(t('Sin conexión. Revisa la red y vuelve a intentarlo.')) ?>,
  },
  errStatus: {
    401: <?= json_encode(t('Tu sesión ha caducado. Vuelve a entrar y repite el cambio.')) ?>,
    403: <?= json_encode(t('No tienes permiso para hacer eso.')) ?>,
  },
  errGeneric: <?= json_encode(t('No se pudo completar. Inténtalo de nuevo en un momento.')) ?>,
};

// ── Llamadas a la API con errores legibles ──────────────────────
// Lo inesperado (una respuesta que no es JSON, un 5xx) se registra en
// /api/log-error.php —el mismo destino que shared/error_tracker.php— para
// que se sepa aunque la persona no avise. Lo esperado (validación, permisos,
// sin red) solo se explica en pantalla.
function report(msg) {
  try {
    const body = JSON.stringify({ message: String(msg).slice(0, 500), source: 'dashboard', lineno: 0, page: location.pathname });
    if (!(navigator.sendBeacon && navigator.sendBeacon('/api/log-error.php', body)))
      fetch('/api/log-error.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true }).catch(() => {});
  } catch (e) { /* registrar un fallo no puede provocar otro */ }
}
async function api(url, opts) {
  let res;
  try { res = await fetch(url, opts); }
  catch (e) { throw Object.assign(new Error('network'), { code: 'NETWORK', status: 0 }); }
  let data = null;
  try { data = await res.json(); } catch (e) { data = null; }
  if (!data || res.status >= 500) report(`${opts && opts.method || 'GET'} ${url} → ${res.status}${data ? ' ' + (data.code || '') : ' (sin JSON)'}`);
  if (!data || !data.ok) throw Object.assign(new Error('api'), { code: (data && data.code) || '', status: res.status });
  return data;
}
function errText(e) { return T.err[e.code] || T.errStatus[e.status] || T.errGeneric; }
const fmt = (tpl, s) => tpl.replace('%s', s);

// ── Pestañas: «Mis recursos» / «Mis listas» ─────────────────────
// #listas abre las listas; #collections sigue valiendo (enlaces viejos).
const tabs = { recursos: document.getElementById('tab-recursos'), listas: document.getElementById('tab-listas') };
function showTab(key, focus) {
  Object.entries(tabs).forEach(([k, tab]) => {
    const on = k === key;
    tab.setAttribute('aria-selected', on ? 'true' : 'false');
    tab.tabIndex = on ? 0 : -1;
    document.getElementById(tab.getAttribute('aria-controls')).hidden = !on;
  });
  if (focus) tabs[key].focus();
}
function tabFromHash() { return /^#(listas|collections)$/.test(location.hash) ? 'listas' : 'recursos'; }
Object.entries(tabs).forEach(([key, tab]) => {
  tab.addEventListener('click', () => { showTab(key); history.replaceState(null, '', '#' + key); });
  tab.addEventListener('keydown', e => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    const next = key === 'recursos' ? 'listas' : 'recursos';
    showTab(next, true); history.replaceState(null, '', '#' + next);
  });
});
window.addEventListener('hashchange', () => showTab(tabFromHash()));
showTab(tabFromHash());

function bumpCount(which, delta) {
  const el = document.querySelector(`[data-count="${which}"]`);
  if (el) el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
}

// ── Buscar en mis recursos (solo filtra lo que ya está en la página) ──
const resFilter = document.getElementById('resFilter');
if (resFilter) {
  resFilter.addEventListener('input', () => {
    const q = resFilter.value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('#resList > li').forEach(li => {
      const hit = !q || (li.dataset.q || '').includes(q);
      li.hidden = !hit;
      if (hit) shown++;
    });
    document.getElementById('resNoMatch').hidden = shown > 0;
  });
}

// ── Acciones por delegación (sin onclick con texto interpolado: un título
//    con apóstrofo rompía el onclick="deleteColl(…, '…')" de antes) ──
document.addEventListener('click', async e => {
  const del = e.target.closest('[data-delete-res]');
  if (del) {
    if (!confirm(fmt(T.confirmDelRes, del.dataset.title))) return;
    const id = parseInt(del.dataset.deleteRes, 10);
    del.disabled = true;
    try {
      await api(`/api/resources.php?id=${id}`, { method: 'DELETE' });
      document.getElementById(`res-${id}`)?.remove();
      bumpCount('res', -1);
      IA.toast(T.resDeleted);
    } catch (err) { del.disabled = false; IA.toast(errText(err)); }
    return;
  }
  const delList = e.target.closest('[data-delete-list]');
  if (delList) {
    if (!confirm(fmt(T.confirmDelList, delList.dataset.title))) return;
    const id = parseInt(delList.dataset.deleteList, 10);
    delList.disabled = true;
    try {
      await api(`/api/collections.php?id=${id}`, { method: 'DELETE' });
      document.getElementById(`coll-${id}`)?.remove();
      bumpCount('coll', -1);
      IA.toast(T.listDeleted);
    } catch (err) { delList.disabled = false; IA.toast(errText(err)); }
    return;
  }
  const send = e.target.closest('[data-send-list]');
  if (send) {
    IA.openSend({ path: '/collection/?id=' + parseInt(send.dataset.sendList, 10), title: send.dataset.title, copiedMsg: T.linkCopied });
    return;
  }
  const edit = e.target.closest('[data-edit-list]');
  if (edit) openListDialog(edit.dataset);
});

// ── Diálogo de lista: crear y editar ────────────────────────────
const listDialog = document.getElementById('listDialog');
const listForm   = document.getElementById('listForm');
const listError  = document.getElementById('listError');
const listSave   = document.getElementById('listSave');
let editingListId = null;

function openListDialog(d) {
  editingListId = d && d.editList ? parseInt(d.editList, 10) : null;
  document.getElementById('listDialogTitle').textContent = editingListId ? T.editList : T.newList;
  document.getElementById('listTitle').value  = editingListId ? d.title : '';
  document.getElementById('listDesc').value   = editingListId ? (d.desc || '') : '';
  document.getElementById('listPublic').value = editingListId ? d.public : '1';
  listSave.textContent = editingListId ? T.saveChanges : T.createList;
  listSave.disabled = false;
  listError.hidden = true;
  listDialog.showModal();
  document.getElementById('listTitle').focus();
}
document.getElementById('newListBtn').addEventListener('click', () => openListDialog(null));

listForm.addEventListener('submit', async e => {
  e.preventDefault();
  const title = document.getElementById('listTitle').value.trim();
  if (!title) { listError.textContent = T.nameRequired; listError.hidden = false; return; }
  listSave.disabled = true;
  listSave.textContent = editingListId ? T.saving : T.creating;
  try {
    await api(editingListId ? `/api/collections.php?id=${editingListId}` : '/api/collections.php', {
      method: editingListId ? 'PUT' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        title,
        description: document.getElementById('listDesc').value.trim(),
        is_public: parseInt(document.getElementById('listPublic').value, 10),
      }),
    });
    history.replaceState(null, '', '#listas');   // al recargar, vuelve a las listas
    location.reload();
  } catch (err) {
    listError.textContent = errText(err);
    listError.hidden = false;
    listSave.disabled = false;
    listSave.textContent = editingListId ? T.saveChanges : T.createList;
  }
});

// ── Campana de novedades (api/notifications.php) ────────────────
(function () {
  const bell  = document.getElementById('notifBell');
  const panel = document.getElementById('notifPanel');
  const badge = document.getElementById('notifBadge');
  const list  = document.getElementById('notifList');
  const icon  = <?= json_encode(IAREPO_ACTIVITY_ICONS) ?>;

  // created_at llega sin zona; como antes, se interpreta en UTC (la de la BD).
  function ago(dt) {
    const d = (Date.now() - new Date(String(dt).replace(' ', 'T') + 'Z').getTime()) / 1000;
    if (!(d >= 60)) return T.now;
    const n = d < 3600 ? Math.round(d / 60) + ' min' : d < 86400 ? Math.round(d / 3600) + ' h' : Math.round(d / 86400) + ' d';
    return fmt(T.ago, n);
  }
  function render(items) {
    if (!items.length) { list.innerHTML = `<li class="db-empty-note">${IA.esc(T.notifEmpty)}</li>`; return; }
    list.innerHTML = items.map(n => `<li><a class="db-act" href="/resource/${parseInt(n.resource_id, 10)}">
      <span class="db-act-ico"><i data-lucide="${icon[n.type] || 'bell'}"></i></span>
      <span><strong>${IA.esc(n.actor || T.learner)}</strong> ${IA.esc(T.verb[n.type] || '')} <strong>${IA.esc(n.resource_title)}</strong>
      <span class="db-act-time">${IA.esc(ago(n.created_at))}</span></span></a></li>`).join('');
    IA.icons();
  }
  async function load() {
    try {
      const data = await api('/api/notifications.php');
      badge.hidden = !(data.unread > 0);
      badge.textContent = data.unread > 9 ? '9+' : String(data.unread);
      render(data.notifications || []);
    } catch (err) {
      list.innerHTML = `<li class="db-empty-note">${IA.esc(T.notifError)}</li>`;
    }
  }
  function close() { panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); }
  bell.addEventListener('click', async e => {
    e.stopPropagation();
    if (!panel.hidden) { close(); return; }
    panel.hidden = false;
    bell.setAttribute('aria-expanded', 'true');
    const hadUnread = !badge.hidden;
    await load();
    if (hadUnread) {
      badge.hidden = true;
      api('/api/notifications.php', { method: 'POST' }).catch(() => { /* marcar como visto es cortesía: si falla, vuelve a salir */ });
    }
  });
  document.addEventListener('click', e => { if (!panel.hidden && !panel.contains(e.target) && !bell.contains(e.target)) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) { close(); bell.focus(); } });
  load();
})();

IA.icons();
</script>
</body>
</html>
