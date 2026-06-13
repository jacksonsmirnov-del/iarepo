<?php
// ================================================================
// dashboard/index.php — Teacher Dashboard (Mis Recursos)
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/i18n.php';
lang();

$user = getSessionUser();
if (!$user) { header('Location: /'); exit; }

$db = getResourcesDB();

// Fetch user's resources
$stmt = $db->prepare('SELECT id, title, description, code_type, subject_area, visibility, view_count, like_count, fork_count, lang, created_at FROM resources WHERE author_user_id = ? AND author_tenant_id = 0 AND is_active = 1 ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$totalViews = array_sum(array_column($resources, 'view_count'));
$totalLikes = array_sum(array_column($resources, 'like_count'));
$totalForks = array_sum(array_column($resources, 'fork_count'));

// Recent activity on user's resources
$myIds = array_column($resources, 'id');
$activity = [];
if ($myIds) {
    $inClause = implode(',', array_map('intval', $myIds));

    // Recent likes
    $likeAct = $db->query("
        SELECT 'like' AS type, rl.user_name AS actor, r.title AS resource_title, r.id AS resource_id, rl.created_at
        FROM resource_likes rl JOIN resources r ON r.id = rl.resource_id
        WHERE rl.resource_id IN ($inClause)
        ORDER BY rl.created_at DESC LIMIT 5
    ")->fetchAll();

    // Recent forks
    $forkAct = $db->query("
        SELECT 'fork' AS type, r2.author_display_name AS actor, r.title AS resource_title, r.id AS resource_id, r2.created_at
        FROM resources r2 JOIN resources r ON r.id = r2.fork_of
        WHERE r2.fork_of IN ($inClause) AND r2.is_active = 1
        ORDER BY r2.created_at DESC LIMIT 5
    ")->fetchAll();

    // Recent comments
    $commentAct = $db->query("
        SELECT 'comment' AS type, rc.user_name AS actor, r.title AS resource_title, r.id AS resource_id, rc.created_at
        FROM resource_comments rc JOIN resources r ON r.id = rc.resource_id
        WHERE rc.resource_id IN ($inClause) AND rc.is_active = 1
        ORDER BY rc.created_at DESC LIMIT 5
    ")->fetchAll();

    $activity = array_merge($likeAct, $forkAct, $commentAct);
    usort($activity, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $activity = array_slice($activity, 0, 10);
}

// Collections
$collStmt = $db->prepare("SELECT id, title, is_public, item_count, created_at FROM collections WHERE user_id = ? ORDER BY created_at DESC");
$collStmt->execute([$user['id']]);
$collections = $collStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — iarepo</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#7c3aed">
<script src="/assets/js/pwa.js" defer></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="/assets/js/lucide.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--bg2:#fff;--bg3:#f1f5f9;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06);--shadow-hover:0 8px 24px rgba(124,58,237,.12)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--card:#151c2e;--border:#1e293b;--shadow:0 1px 3px rgba(0,0,0,.3);--shadow-hover:0 8px 24px rgba(124,58,237,.2)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}
a{color:var(--accent2);text-decoration:none}

.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;background:var(--bg2);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-left{display:flex;align-items:center;gap:16px}
.topbar-left a{color:var(--accent);font-weight:600;font-size:.95rem}
.topbar-right{display:flex;align-items:center;gap:12px;font-size:.85rem}
.topbar-right img{width:28px;height:28px;border-radius:50%}

/* Notifications bell */
.notif-wrap{position:relative;display:flex}
.notif-bell{position:relative;width:36px;height:36px;border-radius:50%;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.2s}
.notif-bell:hover{border-color:var(--accent);color:var(--accent)}
.notif-badge{position:absolute;top:-3px;right:-3px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#ef4444;color:#fff;font-size:.66rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg2)}
.notif-panel{position:absolute;top:46px;right:0;width:330px;max-width:88vw;background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.18);opacity:0;visibility:hidden;transform:translateY(8px);transition:all .2s;z-index:300;overflow:hidden}
.notif-panel.open{opacity:1;visibility:visible;transform:translateY(0)}
.notif-head{padding:14px 16px;font-weight:700;font-size:.92rem;border-bottom:1px solid var(--border)}
.notif-list{max-height:380px;overflow-y:auto}
.notif-item{display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);font-size:.84rem;text-decoration:none;color:var(--text);transition:.15s}
.notif-item:hover{background:var(--bg3)}
.notif-item:last-child{border-bottom:none}
.notif-ico{width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.85rem}
.notif-ico.like{background:rgba(239,68,68,.12);color:#ef4444}
.notif-ico.fork{background:rgba(124,58,237,.12);color:var(--accent)}
.notif-ico.comment{background:rgba(6,182,212,.12);color:var(--accent2)}
.notif-txt{flex:1;line-height:1.45}
.notif-txt strong{color:var(--text);font-weight:600}
.notif-time{font-size:.72rem;color:var(--text3);margin-top:2px}
.notif-empty{padding:30px 16px;text-align:center;color:var(--text3);font-size:.85rem}

.container{max-width:1000px;margin:0 auto;padding:32px 24px}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px}
@media(max-width:640px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;text-align:center;box-shadow:var(--shadow)}
.stat-card strong{display:block;font-size:1.8rem;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px}
.stat-card span{font-size:.8rem;color:var(--text3)}

/* Tabs */
.tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:2px solid var(--border);padding-bottom:0}
.tab{padding:10px 20px;border:none;background:none;color:var(--text3);font-family:inherit;font-size:.9rem;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:.2s}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab:hover{color:var(--text)}
.tab-content{display:none}
.tab-content.active{display:block}

.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.header h2{font-size:1.2rem;font-weight:700}
.btn{padding:10px 20px;border-radius:24px;border:none;cursor:pointer;font-family:inherit;font-size:.85rem;font-weight:600;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:var(--grad);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(124,58,237,.3)}
.btn-sm{padding:6px 14px;font-size:.8rem;border-radius:8px}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text2)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}

.resource-list{display:flex;flex-direction:column;gap:12px}
.resource-item{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);transition:.2s;box-shadow:var(--shadow)}
.resource-item:hover{box-shadow:var(--shadow-hover);border-color:var(--accent)}
.resource-info h3{font-size:.95rem;font-weight:600;margin-bottom:4px}
.resource-info h3 a{color:var(--text)}
.resource-meta{display:flex;gap:12px;font-size:.78rem;color:var(--text3);flex-wrap:wrap}
.resource-actions{display:flex;gap:6px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600}
.badge-draft{background:#fef3c7;color:#92400e}
.badge-community{background:#dcfce7;color:#166534}
[data-theme="dark"] .badge-draft{background:rgba(251,191,36,.15);color:#fbbf24}
[data-theme="dark"] .badge-community{background:rgba(34,197,94,.15);color:#4ade80}
.empty{text-align:center;padding:60px 24px;color:var(--text3)}
.empty h3{color:var(--text);margin-bottom:8px}

.theme-toggle{position:fixed;bottom:16px;right:16px;z-index:100;width:40px;height:40px;border-radius:50%;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow)}
.theme-toggle:hover{border-color:var(--accent);color:var(--accent)}

.activity-list{display:flex;flex-direction:column;gap:8px;margin-bottom:32px}
.activity-item{display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--card);border:1px solid var(--border);border-radius:10px;font-size:.85rem}
.activity-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem}
.activity-icon.like{background:rgba(239,68,68,.1);color:#ef4444}
.activity-icon.fork{background:rgba(124,58,237,.1);color:var(--accent)}
.activity-icon.comment{background:rgba(6,182,212,.1);color:var(--accent2)}
.activity-text{flex:1;line-height:1.4}
.activity-text strong{color:var(--text)}
.activity-text a{color:var(--accent2)}
.activity-time{font-size:.75rem;color:var(--text3);white-space:nowrap}

.coll-link{color:var(--text);text-decoration:none}
.coll-link:hover{color:var(--accent)}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--bg2);border-radius:16px;padding:28px;width:100%;max-width:420px;box-shadow:0 24px 64px rgba(0,0,0,.2)}
.modal h3{font-size:1.1rem;font-weight:700;margin-bottom:20px}
.modal label{display:block;font-size:.85rem;font-weight:600;color:var(--text2);margin-bottom:6px}
.modal input,.modal textarea,.modal select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-family:inherit;font-size:.9rem;margin-bottom:14px}
.modal input:focus,.modal textarea:focus{outline:none;border-color:var(--accent)}
.modal textarea{resize:vertical;min-height:72px}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:4px}
.btn-danger{background:transparent;border:1px solid #ef4444;color:#ef4444}
.btn-danger:hover{background:rgba(239,68,68,.08)}
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/"><img src="/assets/img/logo.svg" alt="iarepo" style="height:24px;width:auto;vertical-align:middle"></a>
    <span style="color:var(--text3)">·</span>
    <span style="font-weight:600;font-size:.9rem">Dashboard</span>
  </div>
  <div class="topbar-right">
    <div class="notif-wrap">
      <button class="notif-bell" id="notifBell" aria-label="<?= h(t('Notificaciones')) ?>" title="<?= h(t('Notificaciones')) ?>">
        <i data-lucide="bell" style="width:18px;height:18px"></i>
        <span class="notif-badge" id="notifBadge" style="display:none">0</span>
      </button>
      <div class="notif-panel" id="notifPanel">
        <div class="notif-head"><?= h(t('Notificaciones')) ?></div>
        <div class="notif-list" id="notifList"><div class="notif-empty"><?= h(t('Cargando…')) ?></div></div>
      </div>
    </div>
    <?php if ($user['avatar_url']): ?><img src="<?= h($user['avatar_url']) ?>" alt=""><?php endif; ?>
    <span><?= h($user['name']) ?></span>
    <a href="/profile/<?= (int)$user['id'] ?>" style="font-size:.8rem"><?= h(t('Mi perfil')) ?></a>
    <a href="/auth/logout.php" style="color:var(--text3);font-size:.8rem"><?= h(t('Salir')) ?></a>
  </div>
</div>

<div class="container">
  <div class="stats-grid">
    <div class="stat-card"><strong><?= count($resources) ?></strong><span><?= h(t('Recursos')) ?></span></div>
    <div class="stat-card"><strong><?= $totalViews ?></strong><span><?= h(t('Vistas')) ?></span></div>
    <div class="stat-card"><strong><?= $totalLikes ?></strong><span><?= h(t('Likes')) ?></span></div>
    <div class="stat-card"><strong><?= $totalForks ?></strong><span><?= h(t('Forks')) ?></span></div>
  </div>

  <?php if ($activity): ?>
  <div class="header" style="margin-bottom:12px">
    <h2><?= h(t('Actividad reciente')) ?></h2>
  </div>
  <div class="activity-list">
    <?php foreach ($activity as $act):
      $icons = ['like' => '❤', 'fork' => '⑂', 'comment' => '💬'];
      $labels = [
        'like'    => "<strong>{$act['actor']}</strong> " . t('le dio like a') . " <a href=\"/resource/{$act['resource_id']}\">{$act['resource_title']}</a>",
        'fork'    => "<strong>{$act['actor']}</strong> " . t('forkeó') . " <a href=\"/resource/{$act['resource_id']}\">{$act['resource_title']}</a>",
        'comment' => "<strong>{$act['actor']}</strong> " . t('comentó en') . " <a href=\"/resource/{$act['resource_id']}\">{$act['resource_title']}</a>",
      ];
      $timeAgo = function($dt) {
        $diff = time() - strtotime($dt);
        if ($diff < 3600) return round($diff/60) . 'm';
        if ($diff < 86400) return round($diff/3600) . 'h';
        return round($diff/86400) . 'd';
      };
    ?>
    <div class="activity-item">
      <div class="activity-icon <?= $act['type'] ?>"><?= $icons[$act['type']] ?></div>
      <div class="activity-text"><?= $labels[$act['type']] ?></div>
      <div class="activity-time"><?= lang()==='en' ? $timeAgo($act['created_at']).' ago' : 'hace '.$timeAgo($act['created_at']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="tabs">
    <button class="tab active" data-tab="resources">📦 <?= h(t('Recursos')) ?></button>
    <button class="tab" data-tab="collections">📁 <?= h(t('Colecciones')) ?> (<?= count($collections) ?>)</button>
  </div>

  <!-- Resources Tab -->
  <div class="tab-content active" id="tab-resources">
    <div class="header">
      <h2><?= h(t('Mis Recursos')) ?></h2>
      <a href="/dashboard/editor.php" class="btn btn-primary">➕ <?= h(t('Nuevo Recurso')) ?></a>
    </div>
    <?php if (empty($resources)): ?>
      <div class="empty">
        <h3><?= h(t('Aún no tienes recursos')) ?></h3>
        <p><?= h(t('Comparte tu primer recurso educativo con la comunidad.')) ?></p>
        <a href="/dashboard/editor.php" class="btn btn-primary" style="margin-top:16px">➕ <?= h(t('Crear mi primer recurso')) ?></a>
      </div>
    <?php else: ?>
      <div class="resource-list">
        <?php foreach ($resources as $r): ?>
          <div class="resource-item" id="res-<?= (int)$r['id'] ?>">
            <div class="resource-info">
              <h3>
                <a href="/resource/<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a>
                <span class="badge <?= $r['visibility'] === 'community' ? 'badge-community' : 'badge-draft' ?>"><?= h($r['visibility']) ?></span>
              </h3>
              <div class="resource-meta">
                <span><?= h($r['code_type']) ?></span>
                <span><?= h($r['subject_area'] ?? '—') ?></span>
                <span>👁 <?= (int)$r['view_count'] ?></span>
                <span>❤ <?= (int)($r['like_count'] ?? 0) ?></span>
                <span>🔄 <?= (int)($r['fork_count'] ?? 0) ?></span>
                <span><?= date('d/m/Y', strtotime($r['created_at'])) ?></span>
              </div>
            </div>
            <div class="resource-actions">
              <a href="/view/<?= (int)$r['id'] ?>" target="_blank" class="btn btn-outline btn-sm">👁 <?= h(t('Ver')) ?></a>
              <a href="/dashboard/editor.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">✏️ <?= h(t('Editar')) ?></a>
              <button class="btn btn-outline btn-sm btn-danger" onclick="deleteResource(<?= (int)$r['id'] ?>, '<?= h(addslashes($r['title'])) ?>')" title="<?= h(t('Eliminar recurso')) ?>">🗑</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Collections Tab -->
  <div class="tab-content" id="tab-collections">
    <div class="header">
      <h2><?= h(t('Mis Colecciones')) ?></h2>
      <button class="btn btn-primary" id="newCollBtn">➕ <?= h(t('Nueva Colección')) ?></button>
    </div>
    <?php if (empty($collections)): ?>
      <div class="empty">
        <h3><?= h(t('Sin colecciones')) ?></h3>
        <p><?= h(t('Organiza tus recursos favoritos en colecciones temáticas.')) ?></p>
      </div>
    <?php else: ?>
      <div class="resource-list">
        <?php foreach ($collections as $c): ?>
          <div class="resource-item" id="coll-<?= (int)$c['id'] ?>">
            <div class="resource-info">
              <h3>
                <a href="/collection/?id=<?= (int)$c['id'] ?>" class="coll-link">📁 <?= h($c['title']) ?></a>
                <span class="badge <?= $c['is_public'] ? 'badge-community' : 'badge-draft' ?>"><?= $c['is_public'] ? h(t('público')) : h(t('privado')) ?></span>
              </h3>
              <div class="resource-meta">
                <span>📦 <?= (int)$c['item_count'] ?> <?= h(t('recursos')) ?></span>
                <span><?= date('d/m/Y', strtotime($c['created_at'])) ?></span>
              </div>
            </div>
            <div class="resource-actions">
              <button class="btn btn-outline btn-sm" onclick="openEditColl(<?= (int)$c['id'] ?>, '<?= h(addslashes($c['title'])) ?>', '<?= h(addslashes($c['description'] ?? '')) ?>', <?= $c['is_public'] ? 1 : 0 ?>)">✏️ <?= h(t('Editar')) ?></button>
              <button class="btn btn-outline btn-sm btn-danger" onclick="deleteColl(<?= (int)$c['id'] ?>, '<?= h(addslashes($c['title'])) ?>')">🗑</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- New Collection Modal -->
<div class="modal-overlay" id="newCollModal">
  <div class="modal">
    <h3><?= h(t('Nueva Colección')) ?></h3>
    <label><?= h(t('Nombre *')) ?></label>
    <input type="text" id="newCollTitle" placeholder="<?= h(t('Ej: Simulaciones de Física')) ?>" maxlength="150">
    <label><?= h(t('Descripción')) ?></label>
    <textarea id="newCollDesc" placeholder="<?= h(t('Breve descripción (opcional)')) ?>" maxlength="500"></textarea>
    <label><?= h(t('Visibilidad')) ?></label>
    <select id="newCollPublic">
      <option value="1"><?= h(t('Pública — cualquier profesor puede verla')) ?></option>
      <option value="0"><?= h(t('Privada — solo yo')) ?></option>
    </select>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="document.getElementById('newCollModal').classList.remove('open')"><?= h(t('Cancelar')) ?></button>
      <button class="btn btn-primary" id="newCollSave"><?= h(t('Crear Colección')) ?></button>
    </div>
  </div>
</div>

<!-- Edit Collection Modal -->
<div class="modal-overlay" id="editCollModal">
  <div class="modal">
    <h3><?= h(t('Editar Colección')) ?></h3>
    <input type="hidden" id="editCollId">
    <label><?= h(t('Nombre *')) ?></label>
    <input type="text" id="editCollTitle" maxlength="150">
    <label><?= h(t('Descripción')) ?></label>
    <textarea id="editCollDesc" maxlength="500"></textarea>
    <label><?= h(t('Visibilidad')) ?></label>
    <select id="editCollPublic">
      <option value="1"><?= h(t('Pública')) ?></option>
      <option value="0"><?= h(t('Privada')) ?></option>
    </select>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="document.getElementById('editCollModal').classList.remove('open')"><?= h(t('Cancelar')) ?></button>
      <button class="btn btn-primary" id="editCollSave"><?= h(t('Guardar Cambios')) ?></button>
    </div>
  </div>
</div>

<button class="theme-toggle" aria-label="Cambiar tema" id="themeBtn"><i data-lucide="moon" style="width:18px;height:18px"></i></button>

<script>
const T = {
  nameRequired: <?= json_encode(t('El nombre es obligatorio')) ?>,
  creating: <?= json_encode(t(T.creating)) ?>,
  createColl: <?= json_encode(t('Crear Colección')) ?>,
  saving: <?= json_encode(t(T.saving)) ?>,
  saveChanges: <?= json_encode(t('Guardar Cambios')) ?>,
  confirmDelColl: <?= json_encode(t('¿Eliminar la colección "%s"? Los recursos no se borrarán.')) ?>,
  confirmDelRes: <?= json_encode(t('¿Eliminar el recurso "%s"? Esta acción no se puede deshacer.')) ?>,
};
// Theme
if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');
document.getElementById('themeBtn').addEventListener('click',()=>{
  const d=document.documentElement.getAttribute('data-theme')==='dark';
  d?document.documentElement.removeAttribute('data-theme'):document.documentElement.setAttribute('data-theme','dark');
  localStorage.setItem('iarepo-theme',d?'light':'dark');
});

// Tabs
document.querySelectorAll('.tab').forEach(tab=>{
  tab.addEventListener('click',()=>{
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('tab-'+tab.dataset.tab).classList.add('active');
  });
});

// Collections — new
document.getElementById('newCollBtn')?.addEventListener('click', () => {
  document.getElementById('newCollTitle').value = '';
  document.getElementById('newCollDesc').value = '';
  document.getElementById('newCollPublic').value = '1';
  document.getElementById('newCollModal').classList.add('open');
});

document.getElementById('newCollSave').addEventListener('click', async () => {
  const title = document.getElementById('newCollTitle').value.trim();
  if (!title) { alert(T.nameRequired); return; }
  const btn = document.getElementById('newCollSave');
  btn.disabled = true; btn.textContent = T.creating;
  try {
    const res = await fetch('/api/collections.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        title,
        description: document.getElementById('newCollDesc').value.trim(),
        is_public: parseInt(document.getElementById('newCollPublic').value)
      })
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    location.reload();
  } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = T.createColl; }
});

// Collections — edit
function openEditColl(id, title, desc, isPublic) {
  document.getElementById('editCollId').value = id;
  document.getElementById('editCollTitle').value = title;
  document.getElementById('editCollDesc').value = desc;
  document.getElementById('editCollPublic').value = isPublic ? '1' : '0';
  document.getElementById('editCollModal').classList.add('open');
}

document.getElementById('editCollSave').addEventListener('click', async () => {
  const id = document.getElementById('editCollId').value;
  const title = document.getElementById('editCollTitle').value.trim();
  if (!title) { alert(T.nameRequired); return; }
  const btn = document.getElementById('editCollSave');
  btn.disabled = true; btn.textContent = T.saving;
  try {
    const res = await fetch(`/api/collections.php?id=${id}`, {
      method: 'PUT',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        title,
        description: document.getElementById('editCollDesc').value.trim(),
        is_public: parseInt(document.getElementById('editCollPublic').value)
      })
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    location.reload();
  } catch(e) { alert(e.message); btn.disabled = false; btn.textContent = T.saveChanges; }
});

// Collections — delete
async function deleteColl(id, title) {
  if (!confirm(T.confirmDelColl.replace("%s", title))) return;
  try {
    const res = await fetch(`/api/collections.php?id=${id}`, {method: 'DELETE'});
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    document.getElementById(`coll-${id}`)?.remove();
  } catch(e) { alert(e.message); }
}

// Resources — delete
async function deleteResource(id, title) {
  if (!confirm(T.confirmDelRes.replace("%s", title))) return;
  try {
    const res = await fetch(`/api/resources.php?id=${id}`, {method: 'DELETE'});
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    document.getElementById(`res-${id}`)?.remove();
  } catch(e) { alert(e.message); }
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// Notifications bell
(function(){
  const bell=document.getElementById('notifBell');
  const panel=document.getElementById('notifPanel');
  const badge=document.getElementById('notifBadge');
  const list=document.getElementById('notifList');
  if(!bell) return;
  const ico={like:'❤',fork:'⑂',comment:'💬'};
  const verb={like:'le dio like a',fork:'hizo un fork de',comment:'comentó en'};
  function esc(s){const e=document.createElement('div');e.textContent=s||'';return e.innerHTML;}
  function ago(dt){const d=(Date.now()-new Date(dt.replace(' ','T')+'Z').getTime())/1000;if(d<60)return 'ahora';if(d<3600)return Math.round(d/60)+'m';if(d<86400)return Math.round(d/3600)+'h';return Math.round(d/86400)+'d';}
  async function load(){
    try{
      const res=await fetch('/api/notifications.php');
      const data=await res.json();
      if(!data.ok) return;
      if(data.unread>0){badge.textContent=data.unread>9?'9+':data.unread;badge.style.display='flex';}
      else badge.style.display='none';
      if(!data.notifications.length){list.innerHTML='<div class="notif-empty">Sin notificaciones todavía 🔔</div>';return;}
      list.innerHTML=data.notifications.map(n=>`<a class="notif-item" href="/resource/${n.resource_id}">
        <div class="notif-ico ${n.type}">${ico[n.type]||'•'}</div>
        <div class="notif-txt"><strong>${esc(n.actor)}</strong> ${verb[n.type]||'interactuó con'} <strong>${esc(n.resource_title)}</strong>
        <div class="notif-time">hace ${ago(n.created_at)}</div></div></a>`).join('');
    }catch(e){}
  }
  load();
  bell.addEventListener('click', async (e)=>{
    e.stopPropagation();
    const opening=!panel.classList.contains('open');
    panel.classList.toggle('open');
    if(opening){
      await load();
      if(badge.style.display!=='none'){
        badge.style.display='none';
        fetch('/api/notifications.php',{method:'POST'}).catch(()=>{});
      }
    }
  });
  document.addEventListener('click',(e)=>{ if(!panel.contains(e.target)&&!bell.contains(e.target)) panel.classList.remove('open'); });
})();

lucide.createIcons();
</script>
</body>
</html>
