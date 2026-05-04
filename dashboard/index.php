<?php
// ================================================================
// dashboard/index.php — Teacher Dashboard (Mis Recursos)
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';

$user = getSessionUser();
if (!$user) {
    header('Location: /');
    exit;
}

$db = getResourcesDB();

// Fetch user's resources
$stmt = $db->prepare('
    SELECT id, title, description, code_type, subject_area, visibility, view_count, lang, created_at
    FROM resources
    WHERE author_user_id = ? AND author_tenant_id = 0 AND is_active = 1
    ORDER BY created_at DESC
');
$stmt->execute([$user['id']]);
$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mis Recursos — iarepo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f8fafc;--bg2:#fff;--text:#1e293b;--text2:#64748b;--accent:#7c3aed;--accent2:#06b6d4;--border:#e2e8f0;--radius:12px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

.topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 32px;background:var(--bg2);border-bottom:1px solid var(--border)}
.topbar-left{display:flex;align-items:center;gap:16px}
.topbar-left a{color:var(--accent);text-decoration:none;font-weight:600;font-size:1.1rem}
.topbar-user{display:flex;align-items:center;gap:10px;font-size:.9rem}
.topbar-user img{width:32px;height:32px;border-radius:50%}
.topbar-user a{color:var(--text2);text-decoration:none;margin-left:12px;font-size:.8rem}
.topbar-user a:hover{color:var(--accent)}

.container{max-width:960px;margin:0 auto;padding:32px 24px}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px}
.header h1{font-size:1.5rem;font-weight:700}
.btn-new{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff;border:none;border-radius:24px;font-size:.9rem;font-weight:600;text-decoration:none;cursor:pointer;transition:transform .2s,box-shadow .2s}
.btn-new:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(124,58,237,.3)}

.empty{text-align:center;padding:80px 24px;color:var(--text2)}
.empty h2{font-size:1.3rem;margin-bottom:8px;color:var(--text)}
.empty p{margin-bottom:24px}

.resource-list{display:flex;flex-direction:column;gap:12px}
.resource-item{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);transition:box-shadow .2s}
.resource-item:hover{box-shadow:0 4px 16px rgba(0,0,0,.06)}
.resource-info h3{font-size:1rem;font-weight:600;margin-bottom:4px}
.resource-info h3 a{color:var(--text);text-decoration:none}
.resource-info h3 a:hover{color:var(--accent)}
.resource-meta{display:flex;gap:12px;font-size:.8rem;color:var(--text2)}
.resource-actions{display:flex;gap:8px}
.resource-actions a{padding:6px 14px;border-radius:8px;font-size:.8rem;text-decoration:none;font-weight:500;transition:all .2s}
.btn-view{background:#ede9fe;color:#7c3aed}
.btn-view:hover{background:#7c3aed;color:#fff}
.btn-edit{background:#e0f2fe;color:#0284c7}
.btn-edit:hover{background:#0284c7;color:#fff}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600}
.badge-draft{background:#fef3c7;color:#92400e}
.badge-community{background:#dcfce7;color:#166534}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/">← iarepo</a>
  </div>
  <div class="topbar-user">
    <?php if ($user['avatar_url']): ?>
      <img src="<?= h($user['avatar_url']) ?>" alt="">
    <?php endif; ?>
    <span><?= h($user['name']) ?></span>
    <a href="/auth/logout.php">Salir</a>
  </div>
</div>

<div class="container">
  <div class="header">
    <h1>📚 Mis Recursos</h1>
    <a href="/dashboard/editor.php" class="btn-new">➕ Nuevo Recurso</a>
  </div>

  <?php if (empty($resources)): ?>
    <div class="empty">
      <h2>Aún no tienes recursos</h2>
      <p>Comparte tu primer recurso educativo con la comunidad.</p>
      <a href="/dashboard/editor.php" class="btn-new">➕ Crear mi primer recurso</a>
    </div>
  <?php else: ?>
    <div class="resource-list">
      <?php foreach ($resources as $r): ?>
        <div class="resource-item">
          <div class="resource-info">
            <h3>
              <a href="/view/<?= (int) $r['id'] ?>"><?= h($r['title']) ?></a>
              <span class="badge <?= $r['visibility'] === 'community' ? 'badge-community' : 'badge-draft' ?>"><?= h($r['visibility']) ?></span>
            </h3>
            <div class="resource-meta">
              <span><?= h($r['code_type']) ?></span>
              <span><?= h($r['subject_area'] ?? '—') ?></span>
              <span>👁 <?= (int) $r['view_count'] ?></span>
              <span><?= date('d/m/Y', strtotime($r['created_at'])) ?></span>
            </div>
          </div>
          <div class="resource-actions">
            <a href="/view/<?= (int) $r['id'] ?>" class="btn-view">Ver</a>
            <a href="/dashboard/editor.php?id=<?= (int) $r['id'] ?>" class="btn-edit">Editar</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
