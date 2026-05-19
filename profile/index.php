<?php
// ================================================================
// profile/index.php — Public Teacher Profile
// URL: /profile/{user_id}
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { header('Location: /'); exit; }

$db = getResourcesDB();

// Get user info from their resources (denormalized)
$authorStmt = $db->prepare("
    SELECT author_display_name, author_tenant_name, COUNT(*) AS resource_count,
           SUM(view_count) AS total_views, SUM(like_count) AS total_likes,
           SUM(fork_count) AS total_forks, MIN(created_at) AS member_since
    FROM resources
    WHERE author_user_id = ? AND author_tenant_id = 0 AND is_active = 1 AND visibility = 'community'
    GROUP BY author_display_name, author_tenant_name
");
$authorStmt->execute([$userId]);
$profile = $authorStmt->fetch();

// Try users table for avatar
$userStmt = $db->prepare("SELECT name, email, avatar_url, created_at FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$dbUser = $userStmt->fetch();

if (!$profile && !$dbUser) { header('Location: /'); exit; }

$name = $dbUser['name'] ?? $profile['author_display_name'] ?? 'Usuario';
$avatar = $dbUser['avatar_url'] ?? '';
$memberSince = $dbUser['created_at'] ?? ($profile['member_since'] ?? '');

// Resources
$resStmt = $db->prepare("
    SELECT r.id, r.title, r.description, r.code_type, r.subject_area, r.level,
           r.view_count, r.like_count, r.fork_count, r.created_at,
           c.name AS category_name, c.icon AS category_icon
    FROM resources r LEFT JOIN categories c ON r.category_id = c.id
    WHERE r.author_user_id = ? AND r.author_tenant_id = 0 AND r.is_active = 1 AND r.visibility = 'community'
    ORDER BY r.like_count DESC, r.view_count DESC
");
$resStmt->execute([$userId]);
$resources = $resStmt->fetchAll();

// Collections
$collStmt = $db->prepare("SELECT id, title, description, item_count, created_at FROM collections WHERE user_id = ? AND is_public = 1 ORDER BY created_at DESC");
$collStmt->execute([$userId]);
$collections = $collStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($name) ?> — iarepo</title>
<meta name="description" content="Perfil de <?= h($name) ?> en iarepo — recursos educativos interactivos">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--bg2:#fff;--bg3:#f1f5f9;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--card:#151c2e;--border:#1e293b;--shadow:0 1px 3px rgba(0,0,0,.3)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
a{color:var(--accent2);text-decoration:none}

.topbar{padding:12px 24px;background:var(--bg2);border-bottom:1px solid var(--border)}
.topbar a{color:var(--accent);font-weight:600;font-size:.95rem}

.profile-header{text-align:center;padding:48px 24px 32px;background:var(--bg2);border-bottom:1px solid var(--border)}
.profile-avatar{width:96px;height:96px;border-radius:50%;border:3px solid var(--accent);margin-bottom:16px;object-fit:cover}
.profile-avatar-placeholder{width:96px;height:96px;border-radius:50%;background:var(--grad);display:inline-flex;align-items:center;justify-content:center;font-size:2.5rem;color:#fff;margin-bottom:16px}
.profile-name{font-size:1.8rem;font-weight:800;margin-bottom:4px}
.profile-joined{color:var(--text3);font-size:.85rem;margin-bottom:20px}
.profile-stats{display:flex;gap:32px;justify-content:center}
.profile-stat{text-align:center}
.profile-stat strong{font-size:1.5rem;display:block;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.profile-stat span{font-size:.8rem;color:var(--text3)}

.container{max-width:1000px;margin:0 auto;padding:32px 24px}
.section-title{font-size:1.1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:40px}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;transition:.2s;box-shadow:var(--shadow)}
.card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 8px 24px rgba(124,58,237,.12)}
.card h3{font-size:.95rem;font-weight:600;margin-bottom:6px}
.card h3 a{color:var(--text)}
.card p{font-size:.85rem;color:var(--text2);line-height:1.5;margin-bottom:10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-meta{display:flex;gap:12px;font-size:.78rem;color:var(--text3)}
.tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:500;background:rgba(124,58,237,.08);color:var(--accent);margin-right:4px}
.coll-card{cursor:pointer}
.coll-card h3 a{color:var(--text)}
.empty{text-align:center;padding:40px;color:var(--text3);font-size:.9rem}

.theme-toggle{position:fixed;bottom:16px;right:16px;z-index:100;width:40px;height:40px;border-radius:50%;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow)}
</style>
</head>
<body>

<div class="topbar"><a href="/">← iarepo</a></div>

<div class="profile-header">
  <?php if ($avatar): ?>
    <img src="<?= h($avatar) ?>" alt="" class="profile-avatar">
  <?php else: ?>
    <div class="profile-avatar-placeholder"><?= mb_substr($name, 0, 1) ?></div>
  <?php endif; ?>
  <div class="profile-name"><?= h($name) ?></div>
  <?php if ($memberSince): ?>
    <div class="profile-joined">Miembro desde <?= date('M Y', strtotime($memberSince)) ?></div>
  <?php endif; ?>
  <div class="profile-stats">
    <div class="profile-stat"><strong><?= (int)($profile['resource_count'] ?? 0) ?></strong><span>Recursos</span></div>
    <div class="profile-stat"><strong><?= (int)($profile['total_views'] ?? 0) ?></strong><span>Vistas</span></div>
    <div class="profile-stat"><strong><?= (int)($profile['total_likes'] ?? 0) ?></strong><span>Likes</span></div>
    <div class="profile-stat"><strong><?= (int)($profile['total_forks'] ?? 0) ?></strong><span>Forks</span></div>
  </div>
</div>

<div class="container">
  <h2 class="section-title"><i data-lucide="file-code" style="width:18px;height:18px"></i> Recursos publicados</h2>
  <?php if ($resources): ?>
    <div class="grid">
      <?php foreach ($resources as $res): ?>
        <div class="card">
          <h3><a href="/resource/<?= (int)$res['id'] ?>"><?= h($res['title']) ?></a></h3>
          <?php if ($res['description']): ?><p><?= h($res['description']) ?></p><?php endif; ?>
          <div style="margin-bottom:8px">
            <span class="tag"><?= h($res['code_type']) ?></span>
            <?php if ($res['subject_area']): ?><span class="tag"><?= h($res['subject_area']) ?></span><?php endif; ?>
          </div>
          <div class="card-meta">
            <span>👁 <?= (int)$res['view_count'] ?></span>
            <span>❤ <?= (int)$res['like_count'] ?></span>
            <span>🔄 <?= (int)$res['fork_count'] ?></span>
            <span><?= date('d/m/Y', strtotime($res['created_at'])) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty">Este usuario aún no tiene recursos públicos.</div>
  <?php endif; ?>

  <?php if ($collections): ?>
    <h2 class="section-title"><i data-lucide="folder" style="width:18px;height:18px"></i> Colecciones</h2>
    <div class="grid">
      <?php foreach ($collections as $col): ?>
        <div class="card coll-card" onclick="location='/api/collections.php?id=<?= (int)$col['id'] ?>'">
          <h3><?= h($col['title']) ?></h3>
          <?php if ($col['description']): ?><p><?= h($col['description']) ?></p><?php endif; ?>
          <div class="card-meta"><span>📦 <?= (int)$col['item_count'] ?> recursos</span></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<button class="theme-toggle" id="themeBtn"><i data-lucide="moon" style="width:18px;height:18px"></i></button>

<script>
if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');
document.getElementById('themeBtn').addEventListener('click',()=>{
  const d=document.documentElement.getAttribute('data-theme')==='dark';
  d?document.documentElement.removeAttribute('data-theme'):document.documentElement.setAttribute('data-theme','dark');
  localStorage.setItem('iarepo-theme',d?'light':'dark');
});
lucide.createIcons();
</script>
</body>
</html>
