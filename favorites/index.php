<?php
// ================================================================
// favorites/index.php — "Mis favoritos" (⭐ guardado rápido y privado)
//
// Lista los recursos que el usuario marcó con ⭐. Buen estado vacío que
// empuja a explorar. La lista la sirve /api/favorites.php (por sesión).
//
// Página HTML: NO carga shared/helpers.php (su error_handler rompe el HTML).
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/i18n.php';
lang();

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$sessionUser = getSessionUser();
if (!$sessionUser) { header('Location: /'); exit; }
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('Mis favoritos')) ?> — iarepo</title>
<meta name="robots" content="noindex">
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
:root{--bg:#f8fafc;--bg2:#fff;--bg3:#f1f5f9;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--card:#151c2e;--border:#1e293b;--shadow:0 1px 3px rgba(0,0,0,.3)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
a{color:var(--accent2);text-decoration:none}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;background:var(--bg2);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-left a{color:var(--accent);font-weight:600;font-size:.95rem}
.topbar-right{display:flex;align-items:center;gap:14px;font-size:.85rem}
.topbar-right a{color:var(--text2)}
.topbar-right a:hover{color:var(--accent)}
.topbar-right img{width:28px;height:28px;border-radius:50%;vertical-align:middle}
.container{max-width:1100px;margin:0 auto;padding:32px 24px}
.head{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.head h1{font-size:1.5rem;font-weight:800}
.head .star{color:#f59e0b}
.lead{color:var(--text3);font-size:.9rem;margin-bottom:24px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.card{position:relative;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;transition:.2s;box-shadow:var(--shadow);cursor:pointer}
.card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 8px 24px rgba(124,58,237,.12)}
.card h3{font-size:.95rem;font-weight:600;margin-bottom:6px;padding-right:28px}
.card h3 a{color:var(--text)}
.card p{font-size:.85rem;color:var(--text2);line-height:1.5;margin-bottom:10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-meta{display:flex;gap:12px;font-size:.78rem;color:var(--text3)}
.tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:500;background:rgba(124,58,237,.08);color:var(--accent);margin-right:4px}
.fav-remove{position:absolute;top:14px;right:12px;background:none;border:none;cursor:pointer;color:#f59e0b;font-size:1.25rem;line-height:1;padding:2px;border-radius:6px;transition:.15s}
.fav-remove:hover{background:var(--bg3);transform:scale(1.1)}
.empty{text-align:center;padding:64px 24px;color:var(--text3)}
.empty .big{font-size:3rem;margin-bottom:16px}
.empty h2{font-size:1.2rem;font-weight:700;color:var(--text);margin-bottom:8px}
.empty p{font-size:.92rem;margin-bottom:22px;max-width:420px;margin-left:auto;margin-right:auto;line-height:1.55}
.btn-explore{display:inline-flex;align-items:center;gap:8px;background:var(--grad);color:#fff;font-weight:700;font-size:.9rem;padding:11px 22px;border-radius:10px;border:none;cursor:pointer}
.loading{text-align:center;padding:48px;color:var(--text3)}
.fav-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--text);color:var(--bg);padding:10px 18px;border-radius:10px;font-size:.85rem;font-weight:600;opacity:0;pointer-events:none;transition:.25s;z-index:2000;box-shadow:0 8px 24px rgba(0,0,0,.2)}
.fav-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.theme-toggle{position:fixed;bottom:16px;right:16px;z-index:100;width:40px;height:40px;border-radius:50%;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow)}
</style>
</head>
<body>

<div class="fav-toast" id="favToast"></div>

<div class="topbar">
  <div class="topbar-left"><a href="/"><img src="/assets/img/logo.svg" alt="iarepo" style="height:24px;width:auto;vertical-align:middle"></a></div>
  <div class="topbar-right">
    <a href="/"><?= h(t('Explorar')) ?></a>
    <a href="/profile/<?= (int)$sessionUser['id'] ?>"><?= h(t('Mi perfil')) ?></a>
    <a href="/auth/logout.php"><?= h(t('Salir')) ?></a>
  </div>
</div>

<div class="container">
  <div class="head">
    <i data-lucide="star" class="star" style="width:24px;height:24px;fill:#f59e0b"></i>
    <h1><?= h(t('Mis favoritos')) ?></h1>
  </div>
  <p class="lead"><?= h(t('Tu guardado rápido y privado. Solo tú ves esta lista.')) ?></p>

  <div id="content"><div class="loading"><?= h(t('Cargando...')) ?></div></div>
</div>

<button class="theme-toggle" aria-label="<?= h(t('Cambiar tema')) ?>" id="themeBtn"><i data-lucide="moon" style="width:18px;height:18px"></i></button>

<script>
const T = {
  views: <?= json_encode(t('Vistas')) ?>,
  likes: <?= json_encode(t('Likes')) ?>,
  removed: <?= json_encode(t('Quitado de favoritos')) ?>,
  remove: <?= json_encode(t('Quitar de favoritos')) ?>,
  loadError: <?= json_encode(t('Error al cargar recursos')) ?>,
};
const levels = <?= json_encode(['primary'=>t('Primaria'),'secondary'=>t('Secundaria'),'ib'=>t('IB'),'university'=>t('Universidad'),'general'=>t('General')], JSON_UNESCAPED_UNICODE) ?>;

if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');
document.getElementById('themeBtn').addEventListener('click',()=>{
  const d=document.documentElement.getAttribute('data-theme')==='dark';
  d?document.documentElement.removeAttribute('data-theme'):document.documentElement.setAttribute('data-theme','dark');
  localStorage.setItem('iarepo-theme',d?'light':'dark');
});

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML}
function showFavToast(msg){const el=document.getElementById('favToast');el.textContent=msg;el.classList.add('show');clearTimeout(el._t);el._t=setTimeout(()=>el.classList.remove('show'),2300);}

const EMPTY = `<div class="empty">
  <div class="big">⭐</div>
  <h2><?= h(t('Aún no tienes favoritos')) ?></h2>
  <p><?= h(t('Pulsa la ⭐ en cualquier recurso para guardarlo aquí y volver a él cuando quieras.')) ?></p>
  <a class="btn-explore" href="/"><i data-lucide="compass" style="width:16px;height:16px"></i> <?= h(t('Explorar recursos')) ?></a>
</div>`;

function cardHtml(r){
  const lvl = levels[r.level] || '';
  return `<div class="card" data-id="${r.id}" onclick="location='/resource/${r.id}'">
    <button class="fav-remove" type="button" title="${T.remove}" aria-label="${T.remove}" onclick="event.stopPropagation();removeFav(${r.id})">★</button>
    <h3><a href="/resource/${r.id}" onclick="event.stopPropagation()">${esc(r.title)}</a></h3>
    ${r.description?`<p>${esc(r.description)}</p>`:''}
    <div style="margin-bottom:8px">
      <span class="tag">${esc(r.code_type)}</span>
      ${lvl?`<span class="tag">${esc(lvl)}</span>`:''}
      ${r.category_name?`<span class="tag" style="background:var(--bg3);color:var(--text2)">${esc(r.category_name)}</span>`:''}
    </div>
    <div class="card-meta">
      <span>👁 ${r.view_count||0} ${T.views}</span>
      <span>❤ ${r.like_count||0} ${T.likes}</span>
    </div>
  </div>`;
}

async function load(){
  const content=document.getElementById('content');
  try{
    const res=await fetch('/api/favorites.php');
    const data=await res.json();
    if(!data.ok) throw new Error(data.error);
    if(!data.favorites.length){ content.innerHTML=EMPTY; lucide.createIcons(); return; }
    content.innerHTML=`<div class="grid">${data.favorites.map(cardHtml).join('')}</div>`;
    lucide.createIcons();
  }catch(e){
    content.innerHTML=`<div class="empty"><p>${T.loadError}</p></div>`;
  }
}

async function removeFav(id){
  try{
    const res=await fetch(`/api/favorites.php?id=${id}`,{method:'POST'});
    const data=await res.json();
    if(!data.ok) throw new Error(data.error);
    const card=document.querySelector(`.card[data-id="${id}"]`);
    if(card) card.remove();
    showFavToast(T.removed);
    if(!document.querySelector('.card')){ document.getElementById('content').innerHTML=EMPTY; lucide.createIcons(); }
  }catch(e){ showFavToast(e.message); }
}

load();
</script>
</body>
</html>
