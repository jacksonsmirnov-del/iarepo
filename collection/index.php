<?php
// ================================================================
// collection/index.php — Collection Detail Page
// URL: /collection/?id=X
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /'); exit; }

$db = getResourcesDB();
$sessionUser = getSessionUser();

// Fetch collection
$stmt = $db->prepare("SELECT * FROM collections WHERE id = ?");
$stmt->execute([$id]);
$coll = $stmt->fetch();
if (!$coll) { header('HTTP/1.1 404 Not Found'); header('Location: /'); exit; }

$isOwner = $sessionUser && (int)$coll['user_id'] === (int)$sessionUser['id'];
if (!$coll['is_public'] && !$isOwner) { header('Location: /'); exit; }

// Fetch items
$items = $db->prepare("
    SELECT ci.id AS item_id, r.id, r.title, r.description, r.code_type,
           r.subject_area, r.level, r.lang, r.view_count, r.like_count, r.fork_count,
           r.author_display_name, r.visibility, r.category_id,
           c.name AS category_name, c.icon AS category_icon
    FROM collection_items ci
    JOIN resources r ON r.id = ci.resource_id
    LEFT JOIN categories c ON c.id = r.category_id
    WHERE ci.collection_id = ? AND r.is_active = 1
    ORDER BY ci.added_at DESC
");
$items->execute([$id]);
$resources = $items->fetchAll();

$levelLabels = ['primary'=>'Primaria','secondary'=>'Secundaria','ib'=>'IB','university'=>'Universidad','general'=>'General'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($coll['title']) ?> — Colección · iarepo</title>
<meta name="description" content="<?= h($coll['description'] ?: 'Colección de recursos educativos en iarepo') ?>">
<meta property="og:title" content="<?= h($coll['title']) ?> — iarepo">
<meta property="og:description" content="<?= h($coll['description'] ?: 'Colección de recursos educativos') ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="iarepo">
<link rel="canonical" href="https://iarepo.com/collection/?id=<?= $id ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#7c3aed">
<script src="/assets/js/pwa.js" defer></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--bg2:#fff;--bg3:#f1f5f9;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06);--shadow-hover:0 8px 24px rgba(124,58,237,.12)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--card:#151c2e;--border:#1e293b;--shadow:0 1px 3px rgba(0,0,0,.3);--shadow-hover:0 8px 24px rgba(124,58,237,.2)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}
a{color:var(--accent2);text-decoration:none}

.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;background:var(--bg2);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-left a{color:var(--accent);font-weight:600;font-size:.95rem}

.container{max-width:1100px;margin:0 auto;padding:32px 24px}

.coll-header{margin-bottom:32px}
.coll-header h1{font-size:1.8rem;font-weight:800;margin-bottom:8px}
.coll-header p{color:var(--text2);font-size:.95rem;line-height:1.6;max-width:600px}
.coll-meta{display:flex;gap:16px;margin-top:12px;flex-wrap:wrap;font-size:.85rem;color:var(--text3)}
.badge{display:inline-block;padding:3px 10px;border-radius:10px;font-size:.75rem;font-weight:600}
.badge-pub{background:#dcfce7;color:#166534}
.badge-priv{background:#fef3c7;color:#92400e}
[data-theme="dark"] .badge-pub{background:rgba(34,197,94,.15);color:#4ade80}
[data-theme="dark"] .badge-priv{background:rgba(251,191,36,.15);color:#fbbf24}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow);transition:.2s;position:relative}
.card:hover{box-shadow:var(--shadow-hover);border-color:var(--accent);transform:translateY(-2px)}
.card-header{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px}
.card-icon{font-size:1.4rem;flex-shrink:0}
.card-title{font-size:.95rem;font-weight:700;line-height:1.3;color:var(--text)}
.card-title a{color:var(--text)}
.card-title a:hover{color:var(--accent)}
.card-desc{font-size:.82rem;color:var(--text2);line-height:1.5;margin-bottom:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-meta{display:flex;gap:8px;flex-wrap:wrap;font-size:.75rem;color:var(--text3);margin-bottom:10px}
.card-stats{display:flex;gap:12px;font-size:.78rem;color:var(--text3)}
.card-actions{display:flex;gap:6px;margin-top:12px}
.btn{padding:6px 14px;border-radius:8px;border:none;cursor:pointer;font-family:inherit;font-size:.8rem;font-weight:600;transition:all .2s;display:inline-flex;align-items:center;gap:4px;text-decoration:none}
.btn-primary{background:var(--grad);color:#fff}
.btn-primary:hover{transform:translateY(-1px)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text2)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.btn-danger-sm{background:transparent;border:1px solid transparent;color:var(--text3);font-size:.75rem;padding:4px 8px}
.btn-danger-sm:hover{border-color:#ef4444;color:#ef4444}

.remove-btn{position:absolute;top:10px;right:10px}

.type-icon{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0}
.type-html{background:rgba(239,68,68,.1);color:#dc2626}
.type-url{background:rgba(6,182,212,.1);color:#0891b2}
.type-embed{background:rgba(168,85,247,.1);color:#7c3aed}
.type-python{background:rgba(59,130,246,.1);color:#2563eb}
.type-prompt{background:rgba(245,158,11,.1);color:#d97706}
.type-other{background:var(--bg3);color:var(--text3)}

.empty{text-align:center;padding:80px 24px;color:var(--text3)}
.empty h3{color:var(--text);margin-bottom:8px;font-size:1.2rem}

.theme-toggle{position:fixed;bottom:16px;right:16px;z-index:100;width:40px;height:40px;border-radius:50%;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow)}
.theme-toggle:hover{border-color:var(--accent);color:var(--accent)}
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/"><img src="/assets/img/logo.svg" alt="iarepo" style="height:24px;width:auto;vertical-align:middle"></a>
    <span style="color:var(--text3)">/</span>
    <span style="font-size:.85rem;color:var(--text2)">Colección</span>
  </div>
  <div style="display:flex;align-items:center;gap:10px;font-size:.85rem">
    <?php if ($sessionUser): ?>
      <?php if ($sessionUser['avatar_url']): ?><img src="<?= h($sessionUser['avatar_url']) ?>" style="width:28px;height:28px;border-radius:50%"><?php endif; ?>
      <a href="/dashboard/">Dashboard</a>
    <?php endif; ?>
  </div>
</div>

<div class="container">
  <div class="coll-header">
    <h1>📁 <?= h($coll['title']) ?></h1>
    <?php if ($coll['description']): ?>
      <p><?= h($coll['description']) ?></p>
    <?php endif; ?>
    <div class="coll-meta">
      <span class="badge <?= $coll['is_public'] ? 'badge-pub' : 'badge-priv' ?>"><?= $coll['is_public'] ? 'Pública' : 'Privada' ?></span>
      <span>📦 <?= count($resources) ?> recursos</span>
      <span>Creada <?= date('d/m/Y', strtotime($coll['created_at'])) ?></span>
      <?php if ($isOwner): ?>
        <a href="/dashboard/" style="color:var(--accent)">← Ir a mi dashboard</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($resources)): ?>
    <div class="empty">
      <h3>Esta colección está vacía</h3>
      <p>Agrega recursos desde sus páginas de detalle.</p>
      <?php if ($isOwner): ?>
        <a href="/" class="btn btn-primary" style="margin-top:16px;padding:10px 24px">Explorar recursos</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="grid" id="resourceGrid">
      <?php foreach ($resources as $r): ?>
        <?php
          $typeClass = 'type-' . ($r['code_type'] ?? 'other');
          $typeLabel = strtoupper($r['code_type'] ?? '');
        ?>
        <div class="card" id="item-<?= (int)$r['item_id'] ?>">
          <?php if ($isOwner): ?>
            <button class="btn btn-danger-sm remove-btn" onclick="removeFromCollection(<?= (int)$r['item_id'] ?>, <?= (int)$r['id'] ?>)" title="Quitar de la colección">✕</button>
          <?php endif; ?>
          <div class="card-header">
            <div class="type-icon <?= $typeClass ?>"><?= $typeLabel ?></div>
            <div class="card-title">
              <a href="/resource/<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a>
            </div>
          </div>
          <?php if ($r['description']): ?>
            <div class="card-desc"><?= h($r['description']) ?></div>
          <?php endif; ?>
          <div class="card-meta">
            <?php if ($r['category_name']): ?><span><?= h($r['category_icon'] ?? '') ?> <?= h($r['category_name']) ?></span><?php endif; ?>
            <?php if ($r['level']): ?><span><?= h($levelLabels[$r['level']] ?? $r['level']) ?></span><?php endif; ?>
            <?php if ($r['lang']): ?><span><?= strtoupper(h($r['lang'])) ?></span><?php endif; ?>
          </div>
          <div class="card-stats">
            <span>👁 <?= (int)$r['view_count'] ?></span>
            <span>❤ <?= (int)$r['like_count'] ?></span>
            <span>🔄 <?= (int)$r['fork_count'] ?></span>
            <span>· <?= h($r['author_display_name']) ?></span>
          </div>
          <div class="card-actions">
            <a href="/resource/<?= (int)$r['id'] ?>" class="btn btn-primary">Ver recurso</a>
            <a href="/view/<?= (int)$r['id'] ?>" target="_blank" class="btn btn-outline">Abrir</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<button class="theme-toggle" id="themeBtn"><i data-lucide="moon" style="width:18px;height:18px"></i></button>

<script>
const COLL_ID = <?= $id ?>;
const IS_OWNER = <?= $isOwner ? 'true' : 'false' ?>;

if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');
document.getElementById('themeBtn').addEventListener('click',()=>{
  const d=document.documentElement.getAttribute('data-theme')==='dark';
  d?document.documentElement.removeAttribute('data-theme'):document.documentElement.setAttribute('data-theme','dark');
  localStorage.setItem('iarepo-theme',d?'light':'dark');
});

async function removeFromCollection(itemId, resourceId) {
  if (!IS_OWNER) return;
  if (!confirm('¿Quitar este recurso de la colección?')) return;
  try {
    const res = await fetch(`/api/collections.php?action=remove&id=${COLL_ID}`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({resource_id: resourceId})
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    document.getElementById(`item-${itemId}`)?.remove();
  } catch(e) { alert(e.message); }
}

lucide.createIcons();
</script>
</body>
</html>
