<?php
// ================================================================
// resource/index.php — Resource Detail Page
//
// URL: /resource/{id}
// Shows: preview, metadata, likes, comments, similar resources
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /'); exit; }

$db = getResourcesDB();
$sessionUser = getSessionUser();
$user = authenticate();

// Fetch resource
$stmt = $db->prepare("
    SELECT r.*, c.name AS category_name, c.icon AS category_icon
    FROM resources r
    LEFT JOIN categories c ON r.category_id = c.id
    WHERE r.id = ? AND r.is_active = 1
");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { header('HTTP/1.1 404 Not Found'); header('Location: /'); exit; }

// Visibility check
if ($r['visibility'] !== 'community') {
    if (!$user) { header('Location: /'); exit; }
    $vis = $r['visibility'];
    if ($vis === 'draft' && $user['user_id'] != $r['author_user_id']) { header('Location: /'); exit; }
    if (in_array($vis, ['area','school']) && ($user['tenant_id'] ?? 0) != $r['author_tenant_id']) { header('Location: /'); exit; }
}

// Is the logged-in user the owner of this resource?
$isOwner = $user
    && (int)($user['user_id'] ?? 0) === (int)$r['author_user_id']
    && (int)($user['tenant_id'] ?? 0) === (int)$r['author_tenant_id'];

// Fetch tags
$tagStmt = $db->prepare("SELECT tag FROM resource_tags WHERE resource_id = ? ORDER BY tag");
$tagStmt->execute([$id]);
$resourceTags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

// Check if user liked
$userLiked = false;
if ($user) {
    $likeCheck = $db->prepare("SELECT id FROM resource_likes WHERE resource_id = ? AND user_id = ?");
    $likeCheck->execute([$id, $user['user_id']]);
    $userLiked = (bool)$likeCheck->fetch();
}

// Similar resources (same category)
$similar = [];
if ($r['category_id']) {
    $simStmt = $db->prepare("
        SELECT id, title, code_type, view_count, like_count, author_display_name
        FROM resources
        WHERE category_id = ? AND id != ? AND is_active = 1 AND visibility = 'community'
        ORDER BY like_count DESC, view_count DESC LIMIT 4
    ");
    $simStmt->execute([$r['category_id'], $id]);
    $similar = $simStmt->fetchAll();
}

// More by same author
$byAuthor = [];
if ($r['author_user_id'] && $r['author_tenant_id'] == 0) {
    $authorStmt = $db->prepare("
        SELECT id, title, code_type, view_count, like_count
        FROM resources
        WHERE author_user_id = ? AND author_tenant_id = 0
          AND id != ? AND is_active = 1 AND visibility = 'community'
        ORDER BY like_count DESC, view_count DESC LIMIT 4
    ");
    $authorStmt->execute([$r['author_user_id'], $id]);
    $byAuthor = $authorStmt->fetchAll();
}

$env = require __DIR__ . '/../.env.php';
$googleClientId = $env['GOOGLE_CLIENT_ID'] ?? '';
$levelLabels = ['primary'=>'Primaria','secondary'=>'Secundaria','ib'=>'IB','university'=>'Universidad','general'=>'General'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
$metaDesc = $r['description'] ?: $r['title'];
if ($r['category_name']) $metaDesc .= ' · ' . $r['category_name'];
if ($r['level'] && isset($levelLabels[$r['level']])) $metaDesc .= ' · ' . $levelLabels[$r['level']];
$metaDesc .= ' — iarepo';
$metaDesc = mb_substr($metaDesc, 0, 160);
?>
<title><?= h($r['title']) ?> — <?= h($r['category_name'] ?: $r['subject_area'] ?: 'iarepo') ?></title>
<meta name="description" content="<?= h($metaDesc) ?>">
<meta property="og:title" content="<?= h($r['title']) ?> — iarepo">
<meta property="og:description" content="<?= h($r['description'] ?: 'Recurso educativo interactivo') ?>">
<meta property="og:url" content="https://iarepo.com/resource/<?= $id ?>">
<meta property="og:type" content="article">
<meta property="og:image" content="https://iarepo.com/api/og-image.php?id=<?= $id ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="iarepo">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($r['title']) ?> — iarepo">
<meta name="twitter:description" content="<?= h($r['description'] ?: 'Recurso educativo interactivo') ?>">
<meta name="twitter:image" content="https://iarepo.com/api/og-image.php?id=<?= $id ?>">
<link rel="canonical" href="https://iarepo.com/resource/<?= $id ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#7c3aed">
<script src="/assets/js/pwa.js" defer></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LearningResource",
  "name": <?= json_encode($r['title']) ?>,
  "description": <?= json_encode($r['description'] ?: $r['title']) ?>,
  "url": "https://iarepo.com/resource/<?= $id ?>",
  "image": "https://iarepo.com/api/og-image.php?id=<?= $id ?>",
  "datePublished": "<?= date('Y-m-d', strtotime($r['created_at'])) ?>",
  "dateModified": "<?= date('Y-m-d', strtotime($r['updated_at'] ?? $r['created_at'])) ?>",
  "inLanguage": "<?= h($r['lang'] ?: 'es') ?>",
  "author": {
    "@type": "Person",
    "name": <?= json_encode($r['author_display_name']) ?>
  },
  "provider": {
    "@type": "Organization",
    "name": "iarepo",
    "url": "https://iarepo.com"
  }<?php if ($r['category_name']): ?>,
  "educationalLevel": <?= json_encode($levelLabels[$r['level']] ?? 'General') ?>,
  "about": {
    "@type": "Thing",
    "name": <?= json_encode($r['category_name']) ?>
  }<?php endif; ?><?php if ($r['source_url'] && $r['source_name']): ?>,
  "isBasedOn": {
    "@type": "WebPage",
    "name": <?= json_encode($r['source_name']) ?>,
    "url": <?= json_encode($r['source_url']) ?>
  }<?php endif; ?>
}
</script>
<script src="/assets/js/lucide.min.js"></script>
<?php if (!$sessionUser): ?>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--bg2:#fff;--bg3:#f1f5f9;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--card:#151c2e;--border:#1e293b;--shadow:0 1px 3px rgba(0,0,0,.3)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}
a{color:var(--accent2);text-decoration:none}

.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;background:var(--bg2);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-left a{color:var(--accent);font-weight:600;font-size:.95rem}
.topbar-right{display:flex;align-items:center;gap:10px}
.topbar-right img{width:28px;height:28px;border-radius:50%}

.layout{max-width:1200px;margin:0 auto;padding:24px;display:grid;grid-template-columns:1fr 360px;gap:24px}
.mobile-title{display:none}
@media(max-width:900px){
  .layout{grid-template-columns:1fr;padding:14px;gap:16px}
  .preview-frame{height:68vh;min-height:380px}
  .mobile-title{display:block;font-size:1.2rem;font-weight:700;line-height:1.3;margin:0 2px 10px}
  .meta-card h2{display:none}
}

/* Preview */
.preview-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
.preview-frame{width:100%;height:clamp(440px,calc(100vh - 150px),860px);border:none;background:#fff}
.preview-actions{display:flex;flex-wrap:wrap;gap:8px;padding:12px 16px;border-top:1px solid var(--border);background:var(--bg3)}
.btn{padding:8px 18px;border-radius:8px;border:none;cursor:pointer;font-family:inherit;font-size:.85rem;font-weight:600;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:var(--grad);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(124,58,237,.3)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text2)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.btn-like{background:transparent;border:1px solid var(--border);color:var(--text2);font-size:.9rem}
.btn-like.liked{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.08)}
.btn-like:hover{border-color:#ef4444;color:#ef4444}

/* Sidebar */
.sidebar{display:flex;flex-direction:column;gap:16px}
.meta-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow)}
.meta-card h2{font-size:1.25rem;font-weight:700;margin-bottom:8px;line-height:1.3}
.meta-card p{color:var(--text2);font-size:.9rem;line-height:1.6;margin-bottom:16px}
.meta-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-top:1px solid var(--border);font-size:.85rem}
.meta-label{color:var(--text3)}
.meta-value{font-weight:500;display:flex;align-items:center;gap:4px}
.author-link{display:flex;align-items:center;gap:8px;padding:12px;border-radius:8px;background:var(--bg3);margin-top:12px;font-size:.9rem;font-weight:500;color:var(--text)}
.stats-row{display:flex;gap:16px;margin-top:16px}
.stat-item{flex:1;text-align:center;padding:12px;background:var(--bg3);border-radius:8px}
.stat-item strong{display:block;font-size:1.3rem;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.stat-item span{font-size:.75rem;color:var(--text3)}
.tag{display:inline-block;padding:3px 10px;border-radius:6px;font-size:.78rem;font-weight:500;margin:2px}
.tag-type{background:rgba(124,58,237,.08);color:var(--accent)}
.tag-level{background:rgba(6,182,212,.08);color:var(--accent2)}
.tag-vis{background:rgba(34,197,94,.08);color:#16a34a}

/* Comments */
.comments-section{margin-top:24px;grid-column:1/-1}
.comments-section h3{font-size:1.1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.comment-form{display:flex;gap:12px;margin-bottom:24px}
.comment-form textarea{flex:1;padding:12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text);font-family:inherit;font-size:.9rem;resize:vertical;min-height:60px}
.comment-form textarea:focus{outline:none;border-color:var(--accent)}
.comment{padding:16px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:12px}
.comment-header{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:.85rem}
.comment-header img{width:24px;height:24px;border-radius:50%}
.comment-header strong{color:var(--text)}
.comment-header time{color:var(--text3);font-size:.78rem}
.comment-body{font-size:.9rem;line-height:1.6;color:var(--text2)}
.comment-actions{margin-top:8px;display:flex;gap:12px}
.comment-actions button{background:none;border:none;color:var(--text3);font-size:.78rem;cursor:pointer;font-family:inherit}
.comment-actions button:hover{color:var(--accent)}
.replies{margin-left:32px;margin-top:8px}
.login-prompt{padding:16px;background:var(--bg3);border-radius:8px;text-align:center;color:var(--text2);font-size:.9rem;margin-bottom:24px}

/* Similar */
.similar-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:12px}
.similar-card{padding:14px;background:var(--card);border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:.2s}
.similar-card:hover{border-color:var(--accent);transform:translateY(-1px)}
.similar-card h4{font-size:.85rem;font-weight:600;margin-bottom:4px}
.similar-card span{font-size:.75rem;color:var(--text3)}

.theme-toggle{position:fixed;bottom:16px;right:16px;z-index:100;width:40px;height:40px;border-radius:50%;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow)}
.theme-toggle:hover{border-color:var(--accent);color:var(--accent)}

/* Share FAB + Panel */
.share-fab{position:fixed;bottom:72px;right:16px;z-index:100;width:48px;height:48px;border-radius:50%;border:none;background:var(--grad);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(124,58,237,.35);transition:transform .3s,box-shadow .3s}
.share-fab:hover{transform:scale(1.08);box-shadow:0 6px 24px rgba(124,58,237,.45)}
.share-fab:active{transform:scale(.95)}
.share-fab svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

.share-panel{position:fixed;bottom:130px;right:16px;z-index:101;background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:0 12px 40px rgba(0,0,0,.18);opacity:0;visibility:hidden;transform:translateY(12px) scale(.95);transition:all .25s cubic-bezier(.4,0,.2,1);min-width:220px}
.share-panel.open{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
.share-panel h4{font-size:.85rem;font-weight:600;color:var(--text);margin-bottom:12px;display:flex;align-items:center;gap:6px}
.share-panel .share-options{display:flex;flex-direction:column;gap:6px}
.share-btn{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;border:none;background:transparent;color:var(--text);cursor:pointer;font-family:inherit;font-size:.88rem;font-weight:500;transition:all .15s;width:100%;text-align:left;text-decoration:none}
.share-btn:hover{background:var(--bg3);transform:translateX(2px)}
.share-btn .share-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem}
.share-btn.whatsapp .share-icon{background:rgba(37,211,102,.12);color:#25d366}
.share-btn.twitter .share-icon{background:rgba(29,161,242,.12);color:#1da1f2}
.share-btn.facebook .share-icon{background:rgba(24,119,242,.12);color:#1877f2}
.share-btn.linkedin .share-icon{background:rgba(10,102,194,.12);color:#0a66c2}
.share-btn.telegram .share-icon{background:rgba(0,136,204,.12);color:#0088cc}
.share-btn.copy .share-icon{background:var(--badge-bg);color:var(--accent)}
.share-btn.native .share-icon{background:var(--badge-bg);color:var(--accent)}
.share-divider{height:1px;background:var(--border);margin:4px 0}
.share-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(60px);background:#1e293b;color:#e2e8f0;padding:10px 24px;border-radius:24px;font-size:.85rem;font-weight:500;z-index:200;opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.3)}
.share-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* Desktop inline share buttons (in preview-actions) */
.share-inline{display:none}
@media(min-width:640px){.share-inline{display:flex}}
@media(max-width:639px){.share-fab{display:flex}}
@media(min-width:640px){.share-fab{width:40px;height:40px;bottom:64px}}

/* Embed modal */
.embed-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:200;display:none;align-items:center;justify-content:center;padding:16px}
.embed-modal-overlay.open{display:flex}
.embed-modal{background:var(--bg2);border-radius:16px;padding:28px;width:100%;max-width:520px;box-shadow:0 24px 64px rgba(0,0,0,.25)}
.embed-modal h3{font-size:1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.embed-sizes{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.embed-size-btn{padding:6px 14px;border-radius:8px;border:1px solid var(--border);background:var(--bg3);color:var(--text2);font-size:.8rem;font-weight:600;cursor:pointer;transition:.15s;font-family:inherit}
.embed-size-btn.active,.embed-size-btn:hover{border-color:var(--accent);color:var(--accent);background:rgba(124,58,237,.06)}
.embed-code-wrap{position:relative}
.embed-code{width:100%;padding:12px;border:1px solid var(--border);border-radius:8px;background:var(--bg3);color:var(--text2);font-family:'Fira Code',monospace;font-size:.78rem;resize:none;height:90px;line-height:1.6}
.embed-copy-btn{margin-top:10px;width:100%;padding:10px;border-radius:8px;border:none;background:var(--grad);color:#fff;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;transition:.2s}
.embed-copy-btn:hover{opacity:.9}
.embed-note{margin-top:10px;font-size:.75rem;color:var(--text3);line-height:1.5}

/* Save to collection dropdown */
.save-coll-wrap{position:relative;display:inline-flex}
.coll-dropdown{position:absolute;top:calc(100% + 6px);left:0;z-index:50;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px;box-shadow:0 8px 32px rgba(0,0,0,.15);min-width:220px;display:none}
.coll-dropdown.open{display:block}
.coll-dropdown-item{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:.85rem;font-weight:500;color:var(--text);border:none;background:none;width:100%;text-align:left;transition:.15s}
.coll-dropdown-item:hover{background:var(--bg3);color:var(--accent)}
.coll-dropdown-empty{padding:12px;font-size:.85rem;color:var(--text3);text-align:center}
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/"><img src="/assets/img/logo.svg" alt="iarepo" style="height:24px;width:auto;vertical-align:middle"></a>
    <span style="color:var(--text3);font-size:.85rem">/</span>
    <span style="font-size:.85rem;color:var(--text2)"><?= h($r['title']) ?></span>
  </div>
  <div class="topbar-right">
    <?php if ($sessionUser): ?>
      <?php if ($sessionUser['avatar_url']): ?><img src="<?= h($sessionUser['avatar_url']) ?>" alt=""><?php endif; ?>
      <span style="font-size:.85rem"><?= h($sessionUser['name']) ?></span>
      <a href="/dashboard/" style="font-size:.8rem;margin-left:4px">Dashboard</a>
    <?php else: ?>
      <div id="g_id_onload" data-client_id="<?= h($googleClientId) ?>" data-login_uri="https://iarepo.com/auth/google.php" data-auto_prompt="false"></div>
      <div class="g_id_signin" data-type="standard" data-shape="pill" data-theme="outline" data-size="small"></div>
    <?php endif; ?>
  </div>
</div>

<div class="layout">
  <!-- Preview -->
  <div>
    <h1 class="mobile-title"><?= h($r['title']) ?></h1>
    <div class="preview-card">
      <?php if ($r['code_type'] === 'html'): ?>
        <iframe class="preview-frame" srcdoc="<?= h($r['code_content']) ?>" sandbox="allow-scripts allow-modals allow-popups" title="<?= h($r['title']) ?>"></iframe>
      <?php elseif ($r['code_type'] === 'url'): ?>
        <?php if ($r['iframe_blocked']): ?>
          <div style="height:500px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;background:var(--bg3);padding:32px;text-align:center">
            <div style="font-size:3rem">🔗</div>
            <p style="color:var(--text2);max-width:420px;line-height:1.6">Este sitio no permite ser embebido por políticas de seguridad. Ábrelo directamente.</p>
            <a href="<?= h($r['code_content']) ?>" target="_blank" rel="noopener" class="btn btn-primary" style="padding:12px 28px">🚀 Abrir <?= h($r['source_name'] ?: 'recurso') ?></a>
          </div>
        <?php else: ?>
          <iframe class="preview-frame" id="previewIframe" src="<?= h($r['code_content']) ?>" title="<?= h($r['title']) ?>"></iframe>
          <script>
          (function(){
            const frame=document.getElementById('previewIframe');
            let loaded=false;
            frame.addEventListener('load',()=>loaded=true);
            frame.addEventListener('error',()=>{
              frame.style.display='none';
              document.getElementById('iframeFallback').style.display='flex';
            });
            setTimeout(()=>{
              if(!loaded) return;
              try{
                const doc=frame.contentDocument||frame.contentWindow.document;
                if(!doc||!doc.body||doc.body.innerHTML===''){
                  frame.style.display='none';
                  document.getElementById('iframeFallback').style.display='flex';
                }
              }catch(e){}
            },4000);
          })();
          </script>
          <div id="iframeFallback" style="display:none;height:500px;flex-direction:column;align-items:center;justify-content:center;gap:16px;background:var(--bg3);padding:32px;text-align:center">
            <div style="font-size:3rem">🔗</div>
            <p style="color:var(--text2);max-width:420px;line-height:1.6">Este sitio bloqueó la vista previa. Ábrelo directamente.</p>
            <a href="<?= h($r['code_content']) ?>" target="_blank" rel="noopener" class="btn btn-primary" style="padding:12px 28px">🚀 Abrir <?= h($r['source_name'] ?: 'recurso') ?></a>
          </div>
        <?php endif; ?>
      <?php elseif ($r['code_type'] === 'embed'): ?>
        <iframe class="preview-frame" srcdoc="<?= h($r['code_content']) ?>" sandbox="allow-scripts allow-modals allow-popups allow-forms" title="<?= h($r['title']) ?>"></iframe>
      <?php else: ?>
        <pre style="height:500px;overflow:auto;padding:20px;background:#1e1e2e;color:#cdd6f4;font-size:14px;font-family:'Fira Code',monospace;white-space:pre-wrap"><?= h($r['code_content']) ?></pre>
      <?php endif; ?>
      <div class="preview-actions">
        <a href="/view/<?= $id ?>" target="_blank" class="btn btn-primary"><i data-lucide="maximize" style="width:14px;height:14px"></i> Pantalla completa</a>
        <button class="btn btn-like <?= $userLiked ? 'liked' : '' ?>" id="likeBtn" aria-label="Me gusta" data-id="<?= $id ?>">
          <i data-lucide="heart" style="width:14px;height:14px"></i>
          <span id="likeCount"><?= (int)($r['like_count'] ?? 0) ?></span>
        </button>
        <button class="btn btn-outline" id="forkBtn" data-id="<?= $id ?>"><i data-lucide="git-fork" style="width:14px;height:14px"></i> Fork</button>
        <?php if ($sessionUser || $user): ?>
        <div class="save-coll-wrap">
          <button class="btn btn-outline" id="saveCollBtn"><i data-lucide="bookmark" style="width:14px;height:14px"></i> Guardar</button>
          <div class="coll-dropdown" id="collDropdown"><div class="coll-dropdown-empty">Cargando...</div></div>
        </div>
        <?php endif; ?>
        <?php if ($r['source_url']): ?>
          <a href="<?= h($r['source_url']) ?>" target="_blank" class="btn btn-outline"><i data-lucide="external-link" style="width:14px;height:14px"></i> Fuente</a>
        <?php endif; ?>
        <button class="btn btn-outline share-inline" id="shareInlineBtn"><i data-lucide="share-2" style="width:14px;height:14px"></i> Compartir</button>
        <button class="btn btn-outline" id="embedBtn"><i data-lucide="code-2" style="width:14px;height:14px"></i> Insertar</button>
        <?php if ($isOwner): ?>
          <a href="/dashboard/editor.php?id=<?= $id ?>" class="btn btn-outline"><i data-lucide="pencil" style="width:14px;height:14px"></i> Editar</a>
          <button class="btn btn-outline" id="deleteResBtn" style="color:#ef4444;border-color:rgba(239,68,68,.35)"><i data-lucide="trash-2" style="width:14px;height:14px"></i> Eliminar</button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="sidebar">
    <div class="meta-card">
      <h2><?= h($r['title']) ?></h2>
      <?php if ($r['description']): ?>
        <p><?= h($r['description']) ?></p>
      <?php endif; ?>

      <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:12px">
        <span class="tag tag-type"><?= h($r['code_type']) ?></span>
        <?php if ($r['level']): ?><span class="tag tag-level"><?= h($levelLabels[$r['level']] ?? $r['level']) ?></span><?php endif; ?>
        <span class="tag tag-vis"><?= h($r['visibility']) ?></span>
        <?php if ($r['category_name']): ?><span class="tag" style="background:var(--bg3);color:var(--text2)"><?= h($r['category_name']) ?></span><?php endif; ?>
        <?php if ($r['lang']): ?><span class="tag" style="background:var(--bg3);color:var(--text2)"><?= strtoupper(h($r['lang'])) ?></span><?php endif; ?>
      </div>

      <div class="stats-row">
        <div class="stat-item"><strong><?= (int)$r['view_count'] ?></strong><span>Vistas</span></div>
        <div class="stat-item"><strong><?= (int)($r['like_count'] ?? 0) ?></strong><span>Likes</span></div>
        <div class="stat-item"><strong><?= (int)$r['fork_count'] ?></strong><span>Forks</span></div>
      </div>

      <?php if ($resourceTags): ?>
      <div style="margin:8px 0 4px;display:flex;flex-wrap:wrap;gap:4px">
        <?php foreach ($resourceTags as $tag): ?>
          <a href="/?tag=<?= urlencode($tag) ?>" class="tag" style="background:rgba(124,58,237,.08);color:var(--accent);padding:3px 10px;border-radius:6px;font-size:.75rem;font-weight:500;text-decoration:none;border:1px solid rgba(124,58,237,.15)"><?= h($tag) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="meta-row">
        <span class="meta-label">Autor</span>
        <span class="meta-value">
          <?php if ($r['author_user_id'] && $r['author_tenant_id'] == 0): ?>
            <a href="/profile/<?= (int)$r['author_user_id'] ?>" style="color:var(--accent2)"><?= h($r['author_display_name']) ?></a>
          <?php else: ?>
            <?= h($r['author_display_name']) ?>
          <?php endif; ?>
        </span>
      </div>
      <?php if ($r['subject_area']): ?><div class="meta-row"><span class="meta-label">Área</span><span class="meta-value"><?= h($r['subject_area']) ?></span></div><?php endif; ?>
      <?php if ($r['topic_tag']): ?><div class="meta-row"><span class="meta-label">Tema</span><span class="meta-value"><?= h($r['topic_tag']) ?></span></div><?php endif; ?>
      <div class="meta-row"><span class="meta-label">Versión</span><span class="meta-value">v<?= (int)$r['current_version'] ?></span></div>
      <div class="meta-row"><span class="meta-label">Creado</span><span class="meta-value"><?= date('d/m/Y', strtotime($r['created_at'])) ?></span></div>
      <?php if ($r['source_name']): ?>
        <div class="meta-row"><span class="meta-label">Fuente</span><span class="meta-value"><a href="<?= h($r['source_url'] ?? '#') ?>" target="_blank"><?= h($r['source_name']) ?></a></span></div>
      <?php endif; ?>
      <?php if ($r['source_prompt']): ?>
        <div style="margin-top:12px">
          <details>
            <summary style="cursor:pointer;font-size:.85rem;color:var(--text3)">🤖 Prompt original</summary>
            <pre style="margin-top:8px;padding:12px;background:var(--bg3);border-radius:8px;font-size:.8rem;white-space:pre-wrap;color:var(--text2);max-height:200px;overflow:auto"><?= h($r['source_prompt']) ?></pre>
          </details>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($byAuthor): ?>
    <div class="meta-card">
      <h3 style="font-size:1rem;font-weight:600;margin-bottom:4px;display:flex;justify-content:space-between;align-items:center">
        <span>Más de <?= h($r['author_display_name']) ?></span>
        <a href="/profile/<?= (int)$r['author_user_id'] ?>" style="font-size:.78rem;font-weight:500;color:var(--accent2)">Ver perfil →</a>
      </h3>
      <div class="similar-grid">
        <?php foreach ($byAuthor as $s): ?>
          <a href="/resource/<?= (int)$s['id'] ?>" class="similar-card" style="text-decoration:none;color:var(--text)">
            <h4><?= h($s['title']) ?></h4>
            <span><?= strtoupper(h($s['code_type'])) ?> · 👁 <?= (int)$s['view_count'] ?> · ❤ <?= (int)$s['like_count'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($similar): ?>
    <div class="meta-card">
      <h3 style="font-size:1rem;font-weight:600;margin-bottom:4px;display:flex;justify-content:space-between;align-items:center">
        <span>Más en <?= h($r['category_name'] ?? 'esta categoría') ?></span>
        <?php if ($r['category_id']): ?>
          <a href="/?category=<?= (int)$r['category_id'] ?>" style="font-size:.78rem;font-weight:500;color:var(--accent2)">Ver todos →</a>
        <?php endif; ?>
      </h3>
      <div class="similar-grid">
        <?php foreach ($similar as $s): ?>
          <a href="/resource/<?= (int)$s['id'] ?>" class="similar-card" style="text-decoration:none;color:var(--text)">
            <h4><?= h($s['title']) ?></h4>
            <span><?= h($s['author_display_name']) ?> · 👁 <?= (int)$s['view_count'] ?> · ❤ <?= (int)$s['like_count'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Comments -->
  <div class="comments-section">
    <h3><i data-lucide="message-circle" style="width:18px;height:18px"></i> Comentarios <span id="commentCount" style="color:var(--text3);font-weight:400;font-size:.9rem"></span></h3>
    <?php if ($sessionUser || $user): ?>
      <div class="comment-form">
        <textarea id="commentBody" placeholder="Comparte una idea, sugerencia o cómo usas este recurso..."></textarea>
        <button class="btn btn-primary" id="postComment" style="align-self:flex-end">Enviar</button>
      </div>
    <?php else: ?>
      <div class="login-prompt">Inicia sesión con Google para comentar</div>
    <?php endif; ?>
    <div id="commentsList"></div>
  </div>
</div>

<!-- Embed Modal -->
<div class="embed-modal-overlay" id="embedModal">
  <div class="embed-modal">
    <h3><i data-lucide="code-2" style="width:16px;height:16px"></i> Insertar en tu web o LMS</h3>
    <div class="embed-sizes">
      <button class="embed-size-btn active" onclick="setEmbedSize('responsive')">Responsivo</button>
      <button class="embed-size-btn" onclick="setEmbedSize('medium')">Mediano</button>
      <button class="embed-size-btn" onclick="setEmbedSize('large')">Grande</button>
    </div>
    <div class="embed-code-wrap">
      <textarea class="embed-code" id="embedCode" readonly></textarea>
    </div>
    <button class="embed-copy-btn" id="embedCopyBtn">Copiar código</button>
    <p class="embed-note">Pega este código en cualquier página HTML, Moodle, Google Sites o Notion. El recurso se mostrará en modo presentación completo.</p>
    <button onclick="document.getElementById('embedModal').classList.remove('open')" style="margin-top:12px;background:none;border:none;color:var(--text3);font-size:.82rem;cursor:pointer;font-family:inherit;width:100%">Cerrar</button>
  </div>
</div>

<!-- Share FAB (prominent on mobile) -->
<button class="share-fab" aria-label="Compartir recurso" id="shareFab" title="Compartir recurso">
  <svg viewBox="0 0 24 24"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
</button>

<!-- Share Panel -->
<div class="share-panel" id="sharePanel">
  <h4><i data-lucide="share-2" style="width:16px;height:16px"></i> Compartir recurso</h4>
  <div class="share-options">
    <a class="share-btn whatsapp" id="shareWhatsApp" href="#" target="_blank" rel="noopener">
      <span class="share-icon">💬</span> WhatsApp
    </a>
    <a class="share-btn telegram" id="shareTelegram" href="#" target="_blank" rel="noopener">
      <span class="share-icon">✈️</span> Telegram
    </a>
    <a class="share-btn twitter" id="shareTwitter" href="#" target="_blank" rel="noopener">
      <span class="share-icon">𝕏</span> X (Twitter)
    </a>
    <a class="share-btn facebook" id="shareFacebook" href="#" target="_blank" rel="noopener">
      <span class="share-icon">f</span> Facebook
    </a>
    <a class="share-btn linkedin" id="shareLinkedIn" href="#" target="_blank" rel="noopener">
      <span class="share-icon">in</span> LinkedIn
    </a>
    <div class="share-divider"></div>
    <button class="share-btn copy" id="shareCopy">
      <span class="share-icon"><i data-lucide="link" style="width:16px;height:16px"></i></span> Copiar enlace
    </button>
  </div>
</div>

<div class="share-toast" id="shareToast">✓ Enlace copiado al portapapeles</div>

<button class="theme-toggle" aria-label="Cambiar tema" id="themeBtn" title="Cambiar tema"><i data-lucide="moon" style="width:18px;height:18px"></i></button>

<script>
const RID = <?= $id ?>;
const IS_AUTH = <?= ($sessionUser || $user) ? 'true' : 'false' ?>;

// Theme
(function(){
  if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');
  document.getElementById('themeBtn').addEventListener('click',()=>{
    const d=document.documentElement.getAttribute('data-theme')==='dark';
    d?document.documentElement.removeAttribute('data-theme'):document.documentElement.setAttribute('data-theme','dark');
    localStorage.setItem('iarepo-theme',d?'light':'dark');
  });
})();

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML}

// Like
document.getElementById('likeBtn').addEventListener('click', async()=>{
  if(!IS_AUTH){alert('Inicia sesión para dar like');return}
  try{
    const res=await fetch(`/api/likes.php?id=${RID}`,{method:'POST'});
    const data=await res.json();
    if(!data.ok) throw new Error(data.error);
    document.getElementById('likeCount').textContent=data.like_count;
    const btn=document.getElementById('likeBtn');
    data.user_liked?btn.classList.add('liked'):btn.classList.remove('liked');
  }catch(e){alert(e.message)}
});

// Fork
document.getElementById('forkBtn').addEventListener('click', async()=>{
  if(!IS_AUTH){alert('Inicia sesión para forkear');return}
  if(!confirm('¿Crear un fork de este recurso?')) return;
  try{
    const res=await fetch(`/api/resources.php?action=fork&id=${RID}`,{method:'POST'});
    const data=await res.json();
    if(!data.ok) throw new Error(data.error);
    alert('¡Fork creado! Revisa tu dashboard.');
  }catch(e){alert(e.message)}
});

// Delete (owner only — button only rendered for the author)
const deleteResBtn=document.getElementById('deleteResBtn');
if(deleteResBtn){
  deleteResBtn.addEventListener('click', async()=>{
    if(!confirm('¿Eliminar este recurso? Esta acción no se puede deshacer.')) return;
    deleteResBtn.disabled=true;
    try{
      const res=await fetch(`/api/resources.php?id=${RID}`,{method:'DELETE'});
      const data=await res.json();
      if(!data.ok) throw new Error(data.error);
      window.location='/dashboard/';
    }catch(e){alert(e.message);deleteResBtn.disabled=false}
  });
}

// Comments
async function loadComments(){
  try{
    const res=await fetch(`/api/comments.php?resource_id=${RID}`);
    const data=await res.json();
    if(!data.ok) return;
    document.getElementById('commentCount').textContent=`(${data.total})`;
    const list=document.getElementById('commentsList');
    if(!data.comments.length){list.innerHTML='<p style="color:var(--text3);text-align:center;padding:24px">Aún no hay comentarios. ¡Sé el primero!</p>';return}
    list.innerHTML=data.comments.map(c=>{
      const avatar=c.user_avatar?`<img src="${esc(c.user_avatar)}" alt="">`:'';
      const date=new Date(c.created_at).toLocaleDateString('es',{day:'numeric',month:'short',year:'numeric'});
      const replies=(c.replies||[]).map(r=>{
        const ra=r.user_avatar?`<img src="${esc(r.user_avatar)}" alt="">`:'';
        const rd=new Date(r.created_at).toLocaleDateString('es',{day:'numeric',month:'short',year:'numeric'});
        return`<div class="comment" style="margin-top:8px"><div class="comment-header">${ra}<strong>${esc(r.user_name)}</strong><time>${rd}</time></div><div class="comment-body">${esc(r.body)}</div></div>`;
      }).join('');
      return`<div class="comment"><div class="comment-header">${avatar}<strong>${esc(c.user_name)}</strong><time>${date}</time></div><div class="comment-body">${esc(c.body)}</div>${replies?'<div class="replies">'+replies+'</div>':''}</div>`;
    }).join('');
  }catch(e){console.error(e)}
}

const postBtn=document.getElementById('postComment');
if(postBtn){
  postBtn.addEventListener('click', async()=>{
    const body=document.getElementById('commentBody').value.trim();
    if(!body) return;
    postBtn.disabled=true;postBtn.textContent='⏳...';
    try{
      const res=await fetch('/api/comments.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({resource_id:RID,body})});
      const data=await res.json();
      if(!data.ok) throw new Error(data.error);
      document.getElementById('commentBody').value='';
      loadComments();
    }catch(e){alert(e.message)}
    finally{postBtn.disabled=false;postBtn.textContent='Enviar'}
  });
}

loadComments();

// ── Save to collection ──
const saveCollBtn = document.getElementById('saveCollBtn');
const collDropdown = document.getElementById('collDropdown');
if (saveCollBtn) {
  let collectionsLoaded = false;
  saveCollBtn.addEventListener('click', async (e) => {
    e.stopPropagation();
    collDropdown.classList.toggle('open');
    if (!collectionsLoaded) {
      try {
        const res = await fetch('/api/collections.php');
        const data = await res.json();
        collectionsLoaded = true;
        if (!data.ok || !data.collections.length) {
          collDropdown.innerHTML = '<div class="coll-dropdown-empty">No tienes colecciones.<br><a href="/dashboard/#collections" style="color:var(--accent)">Crear una</a></div>';
        } else {
          collDropdown.innerHTML = data.collections.map(c =>
            `<button class="coll-dropdown-item" onclick="saveToCollection(${c.id}, '${esc(c.title)}')">📁 ${esc(c.title)} <span style="color:var(--text3);font-size:.75rem;margin-left:auto">${c.item_count}</span></button>`
          ).join('');
        }
      } catch { collDropdown.innerHTML = '<div class="coll-dropdown-empty">Error al cargar</div>'; }
    }
  });

  document.addEventListener('click', (e) => {
    if (!saveCollBtn.contains(e.target)) collDropdown.classList.remove('open');
  });
}

async function saveToCollection(collId, collTitle) {
  collDropdown.classList.remove('open');
  try {
    const res = await fetch(`/api/collections.php?action=add&id=${collId}`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({resource_id: RID})
    });
    const data = await res.json();
    const toast = document.getElementById('shareToast');
    toast.textContent = data.ok ? `✓ Guardado en "${collTitle}"` : `Error: ${data.error}`;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2500);
  } catch { alert('Error al guardar'); }
}

// ── Embed ──
const EMBED_SIZES = {
  responsive: { w: '100%',  h: '500' },
  medium:     { w: '640',   h: '480' },
  large:      { w: '100%',  h: '700' },
};
let currentEmbedSize = 'responsive';

function buildEmbedCode(size) {
  const {w, h} = EMBED_SIZES[size];
  return `<iframe\n  src="https://iarepo.com/view/${RID}?mode=present"\n  width="${w}" height="${h}"\n  frameborder="0" allowfullscreen\n  title="${esc(document.title.split(' —')[0])}"\n  style="border:none;border-radius:8px">\n</iframe>`;
}

function setEmbedSize(size) {
  currentEmbedSize = size;
  document.querySelectorAll('.embed-size-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById('embedCode').value = buildEmbedCode(size);
}

document.getElementById('embedBtn')?.addEventListener('click', () => {
  document.getElementById('embedCode').value = buildEmbedCode(currentEmbedSize);
  document.getElementById('embedModal').classList.add('open');
});

document.getElementById('embedCopyBtn')?.addEventListener('click', async () => {
  const code = document.getElementById('embedCode').value;
  try { await navigator.clipboard.writeText(code); }
  catch { const ta=document.createElement('textarea');ta.value=code;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta); }
  const btn = document.getElementById('embedCopyBtn');
  btn.textContent = '✓ Copiado';
  setTimeout(() => btn.textContent = 'Copiar código', 2000);
});

document.getElementById('embedModal')?.addEventListener('click', e => {
  if (e.target === document.getElementById('embedModal'))
    document.getElementById('embedModal').classList.remove('open');
});

// ── Share functionality ──
const SHARE_URL = `https://iarepo.com/resource/${RID}`;
const SHARE_TITLE = document.querySelector('meta[property="og:title"]')?.content || document.title;
const SHARE_DESC = document.querySelector('meta[property="og:description"]')?.content || '';
const SHARE_TEXT = `${SHARE_TITLE}\n${SHARE_DESC}`;

// Set share links
document.getElementById('shareWhatsApp').href = `https://wa.me/?text=${encodeURIComponent(SHARE_TEXT + '\n' + SHARE_URL)}`;
document.getElementById('shareTelegram').href = `https://t.me/share/url?url=${encodeURIComponent(SHARE_URL)}&text=${encodeURIComponent(SHARE_TEXT)}`;
document.getElementById('shareTwitter').href = `https://twitter.com/intent/tweet?text=${encodeURIComponent(SHARE_TITLE)}&url=${encodeURIComponent(SHARE_URL)}`;
document.getElementById('shareFacebook').href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(SHARE_URL)}`;
document.getElementById('shareLinkedIn').href = `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(SHARE_URL)}`;

// Toggle panel
const sharePanel = document.getElementById('sharePanel');
const shareFab = document.getElementById('shareFab');
const shareInlineBtn = document.getElementById('shareInlineBtn');

function toggleSharePanel(e) {
  e.stopPropagation();
  // Use native Web Share API on mobile if available
  if (navigator.share && window.innerWidth < 640) {
    navigator.share({
      title: SHARE_TITLE,
      text: SHARE_DESC,
      url: SHARE_URL
    }).catch(() => {}); // User cancelled
    return;
  }
  sharePanel.classList.toggle('open');
}

shareFab.addEventListener('click', toggleSharePanel);
if (shareInlineBtn) shareInlineBtn.addEventListener('click', toggleSharePanel);

// Close panel on outside click
document.addEventListener('click', (e) => {
  if (!sharePanel.contains(e.target) && e.target !== shareFab && e.target !== shareInlineBtn) {
    sharePanel.classList.remove('open');
  }
});

// Copy link
document.getElementById('shareCopy').addEventListener('click', async () => {
  try {
    await navigator.clipboard.writeText(SHARE_URL);
  } catch {
    // Fallback for older browsers
    const ta = document.createElement('textarea');
    ta.value = SHARE_URL;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }
  sharePanel.classList.remove('open');
  const toast = document.getElementById('shareToast');
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 2500);
});

lucide.createIcons();
</script>
</body>
</html>
