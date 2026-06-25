<?php
// ================================================================
// index.php — iarepo.com Landing Page + Health Check
//
// Browser request (Accept: text/html) → renders landing page
// API request (Accept: application/json) → returns JSON health check
// ================================================================

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/i18n.php';
lang(); // resolve + persist ES/EN before any output

// h() local — no cargamos helpers.php porque su error_handler registra
// manejadores de excepción que outputan JSON, rompiendo páginas HTML.
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$sessionUser = getSessionUser();
$isStudent   = ($sessionUser['role'] ?? '') === 'student';
// Estudiantes: "Mis favoritos" en el nav; profesores: "Mis Recursos".
$navHome      = $isStudent ? '/favorites/' : '/dashboard/';
$navHomeLabel = $isStudent ? t('Mis favoritos') : t('Mis Recursos');
$env = require __DIR__ . '/.env.php';
$googleClientId = $env['GOOGLE_CLIENT_ID'] ?? '';

// Featured: top 8 por popularidad — falla silenciosamente si hay error DB
$featured = [];
$levelLabels = ['primary'=>'Primaria','secondary'=>'Secundaria','ib'=>'IB','university'=>'Universidad','general'=>'General'];
try {
    $db = getResourcesDB();
    $featuredStmt = $db->query("
        SELECT r.id, r.title, r.code_type,
               r.view_count, r.like_count,
               c.name AS category_name, c.icon AS category_icon
        FROM resources r
        LEFT JOIN categories c ON c.id = r.category_id
        WHERE r.is_active = 1
          AND r.visibility = 'community'
          AND r.moderation_status = 'approved'
        ORDER BY (r.use_count * 3 + r.view_count + r.like_count * 2) DESC
        LIMIT 8
    ");
    $featured = $featuredStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $featured = [];
}

$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
    header('Content-Type: application/json; charset=utf-8');
    $status = ['status' => 'ok', 'service' => 'iarepo', 'version' => '1.0.0'];
    try {
        require_once __DIR__ . '/shared/db.php';
        $db = getResourcesDB();
        $db->query('SELECT 1');
        $status['database'] = 'connected';
    } catch (Throwable $e) {
        $status['database'] = 'error';
        $status['status'] = 'degraded';
    }
    $status['time'] = date('c');
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('iarepo — Repositorio abierto de recursos educativos interactivos')) ?></title>
<meta name="description" content="<?= h(t('Descubre, comparte y ejecuta simulaciones, herramientas y recursos educativos interactivos. El GitHub para profesores.')) ?>">
<meta property="og:title" content="<?= h(t('iarepo — Recursos educativos interactivos')) ?>">
<meta property="og:description" content="<?= h(t('Repositorio abierto de simulaciones, herramientas y recursos interactivos para la enseñanza.')) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="https://iarepo.com">
<meta property="og:site_name" content="iarepo">
<meta property="og:image" content="https://iarepo.com/assets/img/og-default.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h(t('iarepo — Recursos educativos interactivos')) ?>">
<meta name="twitter:description" content="<?= h(t('Repositorio abierto de simulaciones, herramientas y recursos interactivos para la enseñanza.')) ?>">
<meta name="twitter:image" content="https://iarepo.com/assets/img/og-default.png">
<link rel="canonical" href="https://iarepo.com">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#7c3aed">
<script src="/assets/js/pwa.js" defer></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="/assets/js/lucide.min.js"></script>
<script src="https://accounts.google.com/gsi/client" async defer></script>

<!-- AI Crawlers: JSON-LD Structured Data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebApplication",
  "name": "iarepo",
  "url": "https://iarepo.com",
  "description": "Repositorio abierto de recursos educativos interactivos. Simulaciones de física, química, biología y matemáticas para profesores.",
  "applicationCategory": "EducationalApplication",
  "operatingSystem": "Web",
  "offers": { "@type": "Offer", "price": "0", "priceCurrency": "USD" },
  "author": { "@type": "Organization", "name": "iarepo", "url": "https://iarepo.com" },
  "audience": { "@type": "EducationalAudience", "educationalRole": "teacher" }
}
</script>

<style>
*{margin:0;padding:0;box-sizing:border-box}

/* ── Light Mode (default) ── */
:root{
  --bg:#f8fafc;--bg2:#ffffff;--bg3:#f1f5f9;
  --text:#1e293b;--text2:#475569;--text3:#94a3b8;
  --accent:#7c3aed;--accent2:#0891b2;
  --grad:linear-gradient(135deg,#7c3aed 0%,#06b6d4 100%);
  --card:#ffffff;--border:#e2e8f0;
  --radius:12px;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
  --shadow-hover:0 8px 32px rgba(124,58,237,.12);
  --hero-glow:rgba(124,58,237,.08);
  --badge-bg:rgba(124,58,237,.08);--badge-border:rgba(124,58,237,.2);--badge-text:#7c3aed;
  --source-bg:rgba(8,145,178,.06);--source-border:rgba(8,145,178,.15);
}

/* ── Dark Mode ── */
[data-theme="dark"]{
  --bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;
  --text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;
  --accent:#7c3aed;--accent2:#06b6d4;
  --card:#151c2e;--border:#1e293b;
  --shadow:0 1px 3px rgba(0,0,0,.3);
  --shadow-hover:0 8px 32px rgba(124,58,237,.2);
  --hero-glow:rgba(124,58,237,.15);
  --badge-bg:rgba(124,58,237,.15);--badge-border:rgba(124,58,237,.3);--badge-text:#a78bfa;
  --source-bg:rgba(6,182,212,.08);--source-border:rgba(6,182,212,.2);
}

body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;transition:background .3s,color .3s}
a{color:var(--accent2);text-decoration:none;transition:.2s}
a:hover{opacity:.8}

/* Top nav bar */
.topnav{position:fixed;top:0;right:0;left:0;z-index:1001;display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:12px 20px;pointer-events:none}
.topnav>*{pointer-events:auto}
.topnav-logo{margin-right:auto;display:flex;align-items:center;text-decoration:none}
.topnav-logo img{height:24px;width:auto;display:block}

/* Theme toggle */
.theme-toggle{width:36px;height:36px;border-radius:50%;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.2s;box-shadow:var(--shadow);flex-shrink:0}
.theme-toggle:hover{border-color:var(--accent);color:var(--accent)}

/* Fullscreen present button */
.present-btn{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:20px;border:1px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;font-family:inherit;font-size:.8rem;transition:.2s;box-shadow:var(--shadow)}
.present-btn:hover{border-color:var(--accent);color:var(--accent)}

/* Presentation mode */
.present-overlay{display:none;position:fixed;inset:0;z-index:9999;background:var(--bg)}
.present-overlay.active{display:flex;flex-direction:column}
.present-overlay .present-content{flex:1;overflow-y:auto;padding:24px}
.present-esc{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);padding:8px 20px;border-radius:20px;background:rgba(0,0,0,.7);color:#fff;font-size:.8rem;z-index:10000;opacity:0;transition:opacity .3s;pointer-events:none}
.present-overlay:hover .present-esc{opacity:1}

/* Hero */
.hero{text-align:center;padding:80px 24px 40px;position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;top:-260px;left:50%;transform:translateX(-50%);width:1000px;height:900px;background:radial-gradient(circle at 40% 35%,var(--hero-glow) 0%,transparent 58%),radial-gradient(circle at 66% 18%,rgba(6,182,212,.07) 0%,transparent 52%);pointer-events:none;z-index:0}
.hero>*{position:relative;z-index:1}
.hero-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;background:var(--badge-bg);border:1px solid var(--badge-border);color:var(--badge-text);font-size:13px;font-weight:500;margin-bottom:20px}
.hero-logo{display:inline-block;margin-bottom:18px}
.hero-logo img{height:32px;width:auto;display:block}
.hero-title{font-size:clamp(2.1rem,5.2vw,3.7rem);font-weight:800;color:var(--text);margin:0 auto 16px;line-height:1.12;letter-spacing:-.02em;max-width:820px}
.grad-text{background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:clamp(1rem,2vw,1.18rem);color:var(--text2);max-width:600px;margin:0 auto 30px;line-height:1.6}
.hero-stats{display:flex;gap:32px;justify-content:center;flex-wrap:wrap;margin-bottom:24px}
.hero-stat{text-align:center}
.hero-stat strong{font-size:1.5rem;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-stat span{display:block;font-size:.8rem;color:var(--text3)}

/* Search */
.search-wrap{max-width:600px;margin:0 auto 40px;position:relative}
.search-wrap input{width:100%;padding:14px 20px 14px 48px;border-radius:50px;border:1px solid var(--border);background:var(--bg2);color:var(--text);font-size:1rem;font-family:inherit;outline:none;transition:.3s;box-shadow:var(--shadow)}
.search-wrap input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,58,237,.15)}
.search-wrap .search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--text3)}

/* Categories */
.cats{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding:0 24px;margin-bottom:40px}
.cat-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:20px;border:1px solid var(--border);background:var(--bg2);color:var(--text2);font-size:.85rem;cursor:pointer;transition:.2s;font-family:inherit;box-shadow:var(--shadow)}
.cat-pill:hover,.cat-pill.active{border-color:var(--accent);color:var(--accent);background:var(--badge-bg)}
.cat-pill .count{font-size:.75rem;color:var(--text3);margin-left:2px}

/* Grid */
.container{max-width:1200px;margin:0 auto;padding:0 24px 80px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:18px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:transform .2s,box-shadow .2s,border-color .2s;cursor:pointer;position:relative;box-shadow:var(--shadow);display:flex;flex-direction:column}
.card:hover{border-color:var(--accent);transform:translateY(-3px);box-shadow:var(--shadow-hover)}
.card-thumb{position:relative;aspect-ratio:1200/630;background:linear-gradient(135deg,rgba(124,58,237,.10),rgba(6,182,212,.10));overflow:hidden;display:flex;align-items:center;justify-content:center}
.card-thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s}
.card:hover .card-thumb img{transform:scale(1.045)}
.thumb-fallback{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--accent)}
.thumb-fallback i,.thumb-fallback svg{width:36px;height:36px;opacity:.45}
.thumb-ia{position:absolute;top:9px;left:9px;z-index:2;display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:7px;font-size:.65rem;font-weight:800;background:rgba(255,255,255,.94);color:var(--accent);box-shadow:0 1px 5px rgba(0,0,0,.14)}
[data-theme="dark"] .thumb-ia{background:rgba(21,28,46,.92);color:#a78bfa}
.card-fav{position:absolute;top:7px;right:7px;z-index:2;background:rgba(255,255,255,.94);box-shadow:0 1px 5px rgba(0,0,0,.14)}
[data-theme="dark"] .card-fav{background:rgba(21,28,46,.92)}
.card-content{padding:13px 15px 13px;display:flex;flex-direction:column;gap:7px;flex:1}
.card-title{font-size:.95rem;font-weight:700;line-height:1.32;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-desc{font-size:.82rem;color:var(--text2);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-footer{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto;padding-top:4px;font-size:.76rem;color:var(--text3)}
.card-tags{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.tag{padding:2px 7px;border-radius:5px;background:var(--source-bg);color:var(--accent2);font-size:.7rem;font-weight:500}
.card-meta{display:flex;align-items:center;gap:10px;flex-shrink:0}
.card-meta span{display:flex;align-items:center;gap:4px}
.source-badge{display:none}
/* ⭐ Guardar (favorito rápido) */
.fav-btn{display:inline-flex;align-items:center;justify-content:center;background:none;border:none;cursor:pointer;color:var(--text3);padding:3px;margin:-3px;border-radius:6px;transition:.15s}
.fav-btn:hover{color:#f59e0b;background:var(--bg3)}
.fav-btn i{width:15px;height:15px}
.fav-btn.is-fav{color:#f59e0b}
.fav-btn.is-fav i{fill:#f59e0b}
.fav-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--text);color:var(--bg);padding:10px 18px;border-radius:10px;font-size:.85rem;font-weight:600;opacity:0;pointer-events:none;transition:.25s;z-index:2000;box-shadow:0 8px 24px rgba(0,0,0,.2)}
.fav-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.badge-level{padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:500}
.badge-level.primary{background:rgba(34,197,94,.1);color:#16a34a}
.badge-level.secondary{background:rgba(59,130,246,.1);color:#2563eb}
.badge-level.ib{background:rgba(251,191,36,.1);color:#d97706}
.badge-level.university{background:rgba(168,85,247,.1);color:#7c3aed}
[data-theme="dark"] .badge-level.primary{color:#4ade80}
[data-theme="dark"] .badge-level.secondary{color:#60a5fa}
[data-theme="dark"] .badge-level.ib{color:#fbbf24}
[data-theme="dark"] .badge-level.university{color:#c084fc}

/* Loading/Empty */
.loading,.empty{text-align:center;padding:80px 24px;color:var(--text3)}
.loading .spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}

/* Auth bar */
.auth-bar{display:flex;align-items:center;gap:10px}
.auth-user{display:flex;align-items:center;gap:8px;background:var(--card);border:1px solid var(--border);padding:6px 14px;border-radius:24px;text-decoration:none;color:var(--text);font-size:.85rem;font-weight:500;transition:all .2s}
.auth-user:hover{box-shadow:var(--shadow-hover);border-color:var(--accent)}
.auth-avatar{width:28px;height:28px;border-radius:50%;object-fit:cover}
.auth-logout{font-size:.78rem;color:var(--text3);text-decoration:none;padding:4px 10px;border-radius:12px;transition:all .2s}
.auth-logout:hover{color:var(--accent);background:var(--bg3)}

/* Footer */
.footer{text-align:center;padding:40px 24px;border-top:1px solid var(--border);color:var(--text3);font-size:.85rem}
.footer a{color:var(--accent2)}

/* Toolbar */
.toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.result-count{font-size:.9rem;color:var(--text2)}
.sort-select{padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg2);color:var(--text);font-family:inherit;font-size:.85rem;cursor:pointer}

/* Featured section */
.featured{max-width:1100px;margin:0 auto 8px;padding:0 24px}
.featured-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.featured-header h2{font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:8px}
.featured-header a{font-size:.82rem;color:var(--accent2);text-decoration:none;font-weight:500}
.featured-header a:hover{text-decoration:underline}
.featured-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
@media(max-width:900px){.featured-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:540px){.featured-grid{grid-template-columns:1fr 1fr;gap:10px}}
.fcard{background:var(--card);border:1px solid var(--border);border-radius:13px;overflow:hidden;box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s,border-color .2s;text-decoration:none;display:flex;flex-direction:column;height:100%}
.fcard:hover{transform:translateY(-3px);box-shadow:var(--shadow-hover);border-color:var(--accent)}
.fcard-thumb{position:relative;aspect-ratio:1200/630;background:linear-gradient(135deg,rgba(124,58,237,.10),rgba(6,182,212,.10));overflow:hidden;display:flex;align-items:center;justify-content:center}
.fcard-thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s}
.fcard:hover .fcard-thumb img{transform:scale(1.045)}
.fcard-body{padding:10px 12px 11px;display:flex;flex-direction:column;gap:5px;flex:1}
.fcard-wrap{position:relative}
.fav-corner{position:absolute;top:7px;right:7px;z-index:2;background:rgba(255,255,255,.94);box-shadow:0 1px 5px rgba(0,0,0,.14)}
[data-theme="dark"] .fav-corner{background:rgba(21,28,46,.92)}

/* ── Modo búsqueda: al filtrar/buscar, colapsa la portada y muestra
   resultados de inmediato (la barra de búsqueda queda fija arriba) ── */
body.searching .hero{padding:70px 24px 8px}
body.searching .hero::before,
body.searching .hero > :not(.search-wrap),
body.searching .featured,
body.searching .how-it-works{display:none}
body.searching .search-wrap{position:sticky;top:58px;z-index:90;background:var(--bg);padding:8px 0;margin-bottom:0}
.fcard:hover{box-shadow:var(--shadow-hover);border-color:var(--accent);transform:translateY(-2px)}
.fcard-type{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:5px;background:var(--bg3);color:var(--text3);width:fit-content}
.fcard-title{font-size:.85rem;font-weight:600;color:var(--text);line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.fcard-meta{font-size:.72rem;color:var(--text3);margin-top:auto;display:flex;gap:8px}

/* How it works */
.how-it-works{max-width:800px;margin:0 auto 16px;padding:32px 24px;background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow)}
.hiw-steps{display:flex;align-items:flex-start;gap:8px;justify-content:center;flex-wrap:wrap}
.hiw-step{flex:1;min-width:160px;max-width:220px;text-align:center;padding:0 8px}
.hiw-icon{width:56px;height:56px;border-radius:50%;background:var(--grad);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;box-shadow:0 4px 16px rgba(124,58,237,.25)}
.hiw-step h3{font-size:.95rem;font-weight:700;margin-bottom:6px;color:var(--text)}
.hiw-step p{font-size:.8rem;color:var(--text2);line-height:1.5}
.hiw-arrow{font-size:1.4rem;color:var(--text3);padding-top:28px;flex-shrink:0}
@media(max-width:640px){.hiw-arrow{display:none}.hiw-step{min-width:120px}}

/* IA badge on cards */
.badge-ia{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:.68rem;font-weight:700;background:linear-gradient(135deg,rgba(124,58,237,.12),rgba(6,182,212,.12));color:var(--accent);border:1px solid rgba(124,58,237,.2)}

@media(max-width:640px){
  .hero{padding:48px 16px 24px}
  .grid{grid-template-columns:1fr}
  .hero-stats{gap:20px}
  .present-btn{display:none}
}
</style>
<?php require_once __DIR__ . '/shared/error_tracker.php'; ?>
</head>
<body>

<div class="fav-toast" id="favToast"></div>

<div class="topnav">

<a href="/" class="topnav-logo" aria-label="iarepo"><img src="/assets/img/logo.svg" alt="iarepo"></a>

<!-- User auth bar -->
<div class="auth-bar">
<?php if ($sessionUser): ?>
  <a href="<?= $navHome ?>" class="auth-user" title="<?= h($navHomeLabel) ?>">
    <?php if ($sessionUser['avatar_url']): ?>
      <img src="<?= htmlspecialchars($sessionUser['avatar_url']) ?>" alt="" class="auth-avatar">
    <?php endif; ?>
    <span><?= htmlspecialchars($sessionUser['name']) ?></span>
  </a>
  <a href="/auth/logout.php" class="auth-logout" title="<?= h(t('Salir')) ?>"><?= h(t('Salir')) ?></a>
<?php else: ?>
  <div id="g_id_onload"
       data-client_id="<?= htmlspecialchars($googleClientId) ?>"
       data-login_uri="https://iarepo.com/auth/google.php"
       data-auto_prompt="false"></div>
  <div class="g_id_signin"
       data-type="standard"
       data-shape="pill"
       data-theme="outline"
       data-text="signin_with"
       data-size="medium"
       data-locale="es"></div>
<?php endif; ?>
</div>

<!-- Present mode button -->
<button class="present-btn" id="presentBtn" title="<?= h(t('Modo presentación')) ?>">
  <i data-lucide="maximize" style="width:14px;height:14px"></i> <?= h(t('Presentar')) ?>
</button>

<!-- Language switcher -->
<a class="present-btn" href="<?= h(langSwitchUrl(lang()==='en'?'es':'en')) ?>" title="<?= lang()==='en'?'Cambiar a español':'Switch to English' ?>" style="text-decoration:none;font-weight:700"><?= lang()==='en'?'ES':'EN' ?></a>

<!-- Theme toggle -->
<button class="theme-toggle" aria-label="<?= h(t('Cambiar tema')) ?>" title="<?= h(t('Cambiar tema')) ?>" id="theme-btn">
  <i data-lucide="moon" style="width:18px;height:18px" id="theme-icon-dark"></i>
  <i data-lucide="sun" style="width:18px;height:18px" id="theme-icon-light" style="display:none"></i>
</button>

</div><!-- /topnav -->

<!-- Presentation overlay -->
<div class="present-overlay" id="present-overlay">
  <div class="present-content" id="present-content"></div>
  <div class="present-esc"><?= h(t('Presiona ESC para salir')) ?></div>
</div>

<section class="hero">
  <div class="hero-badge"><i data-lucide="sparkles" style="width:14px;height:14px"></i> Open Educational Resources</div>
  <h1 class="hero-title"><?= h(t('Aprende y enseña con')) ?> <span class="grad-text"><?= h(t('simulaciones interactivas')) ?></span></h1>
  <p class="hero-sub"><?= h(t('Cientos de recursos abiertos —simulaciones, herramientas y modelos con IA— listos para usar. Gratis y sin instalar.')) ?></p>
  <div class="search-wrap">
    <i data-lucide="search" class="search-icon" style="width:20px;height:20px"></i>
    <input type="search" id="search" placeholder="<?= h(t('Buscar recursos... (ej: waves, pendulum, pH)')) ?>" autocomplete="off">
  </div>
  <div class="hero-stats">
    <div class="hero-stat"><strong id="stat-total">—</strong><span><?= h(t('Recursos')) ?></span></div>
    <div class="hero-stat"><strong id="stat-cats">—</strong><span><?= h(t('Categorías')) ?></span></div>
    <div class="hero-stat"><strong id="stat-types">—</strong><span><?= h(t('Tipos')) ?></span></div>
  </div>
</section>

<?php if ($featured): ?>
<section class="featured">
  <div class="featured-header">
    <h2><i data-lucide="flame" style="width:18px;height:18px;color:#f97316"></i> <?= h(t('Más usados')) ?></h2>
    <a href="/?sort=popular"><?= h(t('Ver todos →')) ?></a>
  </div>
  <div class="featured-grid">
    <?php foreach ($featured as $f): ?>
    <div class="fcard-wrap">
    <a href="/resource/<?= (int)$f['id'] ?>" class="fcard">
      <div class="fcard-thumb">
        <span class="thumb-fallback"><i data-lucide="<?= h($f['category_icon'] ?: 'file-code') ?>"></i></span>
        <img src="/thumbnails/og-<?= (int)$f['id'] ?>.png" loading="lazy" alt="" onerror="this.remove()">
        <?php if ($f['code_type'] === 'html'): ?><span class="thumb-ia">✦ IA</span><?php endif; ?>
      </div>
      <div class="fcard-body">
        <div class="fcard-title"><?= h($f['title']) ?></div>
        <div class="fcard-meta">
          <span>👁 <?= (int)$f['view_count'] ?></span>
          <span>❤ <?= (int)$f['like_count'] ?></span>
          <?php if ($f['category_name']): ?><span><?= h($f['category_name']) ?></span><?php endif; ?>
        </div>
      </div>
    </a>
    <button class="fav-btn fav-corner" type="button" data-fid="<?= (int)$f['id'] ?>" title="<?= h(t('Guardar')) ?>" aria-label="<?= h(t('Guardar')) ?>" onclick="toggleFavorite(<?= (int)$f['id'] ?>,this)"><i data-lucide="star"></i></button>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$sessionUser): ?>
<section class="how-it-works">
  <div class="hiw-steps">
    <div class="hiw-step">
      <div class="hiw-icon"><i data-lucide="sparkles" style="width:24px;height:24px;color:#fff"></i></div>
      <h3><?= h(t('Genera con IA')) ?></h3>
      <p><?= h(t('Pídele a Gemini o ChatGPT una simulación interactiva en HTML para tu clase.')) ?></p>
    </div>
    <div class="hiw-arrow">→</div>
    <div class="hiw-step">
      <div class="hiw-icon"><i data-lucide="upload" style="width:24px;height:24px;color:#fff"></i></div>
      <h3><?= h(t('Súbela en 30s')) ?></h3>
      <p><?= h(t('Pega el código, elige la materia y publícala. Sin instalación, sin cuenta de pago.')) ?></p>
    </div>
    <div class="hiw-arrow">→</div>
    <div class="hiw-step">
      <div class="hiw-icon"><i data-lucide="globe" style="width:24px;height:24px;color:#fff"></i></div>
      <h3><?= h(t('Profesores la usan')) ?></h3>
      <p><?= h(t('Cualquier profesor del mundo puede encontrarla, usarla o adaptarla para su curso.')) ?></p>
    </div>
  </div>
  <div style="text-align:center;margin-top:28px">
    <div id="g_id_onload_hiw"
         data-client_id="<?= htmlspecialchars($googleClientId) ?>"
         data-login_uri="https://iarepo.com/auth/google.php"
         data-auto_prompt="false"></div>
    <div class="g_id_signin"
         data-type="standard"
         data-shape="pill"
         data-theme="filled_blue"
         data-text="signup_with"
         data-size="large"
         data-logo_alignment="left">
    </div>
    <p style="margin-top:10px;font-size:.78rem;color:var(--text3)"><?= h(t('Gratis · Sin tarjeta · Solo con Google')) ?></p>
  </div>
</section>
<?php endif; ?>

<div id="categories" class="cats"></div>

<div class="container">
  <div class="toolbar">
    <span class="result-count" id="result-count"></span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select class="sort-select" id="filter-lang" title="<?= h(t('Idioma')) ?>">
        <option value=""><?= h(t('🌐 Idioma')) ?></option>
        <option value="es"><?= h(t('🇪🇸 Español')) ?></option>
        <option value="en"><?= h(t('🇬🇧 English')) ?></option>
        <option value="pt"><?= h(t('🇧🇷 Português')) ?></option>
      </select>
      <select class="sort-select" id="filter-level" title="<?= h(t('Nivel')) ?>">
        <option value=""><?= h(t('📚 Nivel')) ?></option>
        <option value="primary"><?= h(t('Primaria')) ?></option>
        <option value="secondary"><?= h(t('Secundaria')) ?></option>
        <option value="ib"><?= h(t('IB')) ?></option>
        <option value="university"><?= h(t('Universidad')) ?></option>
        <option value="general"><?= h(t('General')) ?></option>
      </select>
      <select class="sort-select" id="sort">
        <option value="recent"><?= h(t('Más recientes')) ?></option>
        <option value="popular"><?= h(t('Más usados')) ?></option>
        <option value="views"><?= h(t('Más vistos')) ?></option>
        <option value="title"><?= h(t('Alfabético')) ?></option>
      </select>
    </div>
  </div>
  <div id="grid" class="grid">
    <div class="loading"><div class="spinner"></div><?= h(t('Cargando recursos...')) ?></div>
  </div>
</div>

<footer class="footer">
  <p><strong>iarepo.com</strong> — <?= h(t('Repositorio abierto de recursos educativos interactivos')) ?></p>
  <p style="margin-top:8px">
    <a href="/legal/terms.php"><?= h(t('Términos de uso')) ?></a> ·
    <a href="https://github.com/claseprivada/iarepo" target="_blank">GitHub (MIT)</a> ·
    <a href="https://claseprivada.com">Clase Privada</a>
  </p>
  <p style="margin-top:6px;font-size:.78rem;color:var(--text3)"><?= h(t('Los recursos externos pertenecen a sus respectivos autores. iarepo solo enlaza y cataloga.')) ?></p>
</footer>

<script>
// i18n strings for dynamic content
const T = {
  noResults: <?= json_encode(t('No se encontraron recursos')) ?>,
  connError: <?= json_encode(t('Error de conexión')) ?>,
  loadError: <?= json_encode(t('Error al cargar recursos')) ?>,
  resource: <?= json_encode(lang()==='en'?'resource':'recurso') ?>,
  resources: <?= json_encode(lang()==='en'?'resources':'recursos') ?>,
  levels: <?= json_encode(['primary'=>t('Primaria'),'secondary'=>t('Secundaria'),'ib'=>t('IB'),'university'=>t('Universidad'),'general'=>t('General')], JSON_UNESCAPED_UNICODE) ?>,
  save: <?= json_encode(t('Guardar')) ?>,
  favSaved: <?= json_encode(t('Guardado en tus favoritos ⭐')) ?>,
  favRemoved: <?= json_encode(t('Quitado de favoritos')) ?>,
  loginToSave: <?= json_encode(t('Regístrate para guardar tus favoritos ⭐')) ?>,
};

// ── Favoritos (⭐ guardado rápido) ──
const FAV_AUTH = <?= $sessionUser ? 'true' : 'false' ?>;
let favSet = new Set();
function showFavToast(msg){const el=document.getElementById('favToast');el.textContent=msg;el.classList.add('show');clearTimeout(el._t);el._t=setTimeout(()=>el.classList.remove('show'),2300);}
function setFavBtn(btn,on){btn.classList.toggle('is-fav',!!on);btn.setAttribute('aria-pressed',on?'true':'false');}
async function loadFavorites(){
  if(!FAV_AUTH) return;
  try{const res=await fetch('/api/favorites.php');const data=await res.json();if(data.ok)favSet=new Set((data.favorite_ids||[]).map(Number));}catch(e){}
}
async function toggleFavorite(id,btn){
  id=Number(id);
  if(!FAV_AUTH){startSaveFlow(id);return;}
  btn.disabled=true;
  try{
    const res=await fetch(`/api/favorites.php?id=${id}`,{method:'POST'});
    const data=await res.json();
    if(!data.ok) throw new Error(data.error);
    data.favorited?favSet.add(id):favSet.delete(id);
    setFavBtn(btn,data.favorited);
    showFavToast(data.favorited?T.favSaved:T.favRemoved);
  }catch(e){showFavToast(e.message||T.connError);}
  finally{btn.disabled=false;}
}
// Invitado: lleva la intención (?save + return_url) a la pantalla de registro;
// tras autenticarse se aplica el favorito y se vuelve aquí.
function startSaveFlow(id){
  // localStorage sobrevive el redirect de Google (el query/cookie no): aquí
  // va la intención + a dónde volver, y pwa.js la aplica tras el login.
  try{localStorage.setItem('iarepo_pending_fav',JSON.stringify({id:id,ret:location.pathname+location.search}))}catch(e){}
  showFavToast(T.loginToSave);
  const ret=encodeURIComponent(location.pathname+location.search);
  location.href=`/auth/signin.php?save=${id}&return_url=${ret}`;
}

// ── Theme ──
function initTheme() {
  const saved = localStorage.getItem('iarepo-theme');
  if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  updateThemeIcons();
}
function toggleTheme() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  if (isDark) {
    document.documentElement.removeAttribute('data-theme');
    localStorage.setItem('iarepo-theme', 'light');
  } else {
    document.documentElement.setAttribute('data-theme', 'dark');
    localStorage.setItem('iarepo-theme', 'dark');
  }
  updateThemeIcons();
}
function updateThemeIcons() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  document.getElementById('theme-icon-dark').style.display = isDark ? 'none' : 'block';
  document.getElementById('theme-icon-light').style.display = isDark ? 'block' : 'none';
}
initTheme();
document.getElementById('theme-btn').addEventListener('click', toggleTheme);

// ── Presentation Mode ──
function enterPresent() {
  const overlay = document.getElementById('present-overlay');
  const content = document.getElementById('present-content');
  content.innerHTML = document.querySelector('.container').innerHTML;
  overlay.classList.add('active');
  document.body.style.overflow = 'hidden';
  if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
  lucide.createIcons();
}
document.getElementById('presentBtn').addEventListener('click', enterPresent);
// Close overlay when fullscreen exits (browser handles ESC → fullscreen exit)
function exitPresent() {
  const overlay = document.getElementById('present-overlay');
  if (overlay.classList.contains('active')) {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }
}
document.addEventListener('fullscreenchange', () => {
  if (!document.fullscreenElement) exitPresent();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') exitPresent();
});

// ── API ──
const API = '/api/resources.php';
let currentCat = null;
let debounceTimer = null;

// ¿El usuario está buscando/filtrando? (entonces colapsamos la portada)
function isBrowsing(){
  return !!(document.getElementById('search').value.trim()
    || currentCat
    || document.getElementById('filter-lang').value
    || document.getElementById('filter-level').value
    || document.getElementById('sort').value !== 'recent');
}
function updateBrowseMode(){ document.body.classList.toggle('searching', isBrowsing()); }

async function loadResources() {
  updateBrowseMode();
  const grid = document.getElementById('grid');
  const search = document.getElementById('search').value.trim();
  const sort = document.getElementById('sort').value;

  let url = `${API}?limit=50&sort=${sort}`;
  if (currentCat) url += `&category=${currentCat}`;
  if (search) url += `&search=${encodeURIComponent(search)}`;
  const lang = document.getElementById('filter-lang').value;
  const level = document.getElementById('filter-level').value;
  if (lang) url += `&lang=${lang}`;
  if (level) url += `&level=${level}`;

  try {
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) { grid.innerHTML = `<div class="empty">${T.loadError}</div>`; return; }

    document.getElementById('stat-total').textContent = data.total;
    document.getElementById('result-count').textContent = `${data.total} ${data.total !== 1 ? T.resources : T.resource}`;

    if (data.categories && !document.querySelector('.cat-pill')) renderCategories(data.categories);

    if (data.resources.length === 0) { grid.innerHTML = `<div class="empty">${T.noResults}</div>`; return; }

    grid.innerHTML = data.resources.map(r => renderCard(r)).join('');
    lucide.createIcons();
  } catch (e) {
    grid.innerHTML = `<div class="empty">${T.connError}</div>`;
  }
}

function renderCategories(cats) {
  const activeCats = cats.filter(c => parseInt(c.resource_count) > 0);
  document.getElementById('stat-cats').textContent = activeCats.length;
  document.getElementById('stat-types').textContent = '6';
  const el = document.getElementById('categories');
  let html = `<button class="cat-pill active" data-cat-id="">Todos</button>`;
  cats.forEach(c => {
    if (parseInt(c.resource_count) > 0)
      html += `<button class="cat-pill" data-cat-id="${c.id}"><i data-lucide="${c.icon}" style="width:14px;height:14px"></i> ${c.name} <span class="count">${c.resource_count}</span></button>`;
  });
  el.innerHTML = html;
  lucide.createIcons();
  // Delegated click for category pills
  el.addEventListener('click', e => {
    const pill = e.target.closest('.cat-pill');
    if (!pill) return;
    currentCat = pill.dataset.catId || null;
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    loadResources();
  });
}


function renderCard(r) {
  const icon = r.category_icon || 'file-code';
  const levelClass = r.level || 'general';
  const levelLabel = T.levels[levelClass] || levelClass;
  const langFlag = {'es':'🇪🇸','en':'🇬🇧','pt':'🇧🇷'}[r.lang] || '🌐';
  const fav = favSet.has(Number(r.id));
  const iaBadge = r.code_type==='html'
    ? '<span class="thumb-ia"><svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg> IA</span>'
    : '';
  return `<div class="card" data-resource-id="${r.id}">
    <div class="card-thumb">
      <span class="thumb-fallback"><i data-lucide="${icon}"></i></span>
      <img src="/thumbnails/og-${r.id}.png" loading="lazy" alt="" onerror="this.remove()">
      ${iaBadge}
      <button class="fav-btn card-fav${fav?' is-fav':''}" type="button" title="${T.save}" aria-label="${T.save}" aria-pressed="${fav?'true':'false'}" onclick="event.stopPropagation();toggleFavorite(${r.id},this)"><i data-lucide="star"></i></button>
    </div>
    <div class="card-content">
      <div class="card-title">${esc(r.title)}</div>
      <div class="card-desc">${esc(r.description || '')}</div>
      <div class="card-footer">
        <div class="card-tags"><span class="badge-level ${levelClass}">${levelLabel}</span><span class="tag">${r.code_type==='html'?'HTML':r.code_type}</span><span class="tag">${langFlag}</span></div>
        <div class="card-meta">
          <span><i data-lucide="eye" style="width:12px;height:12px"></i> ${r.view_count||0}</span>
          <span><i data-lucide="heart" style="width:12px;height:12px"></i> ${r.like_count||0}</span>
        </div>
      </div>
    </div>
  </div>`;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

document.getElementById('search').addEventListener('input', () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadResources, 300);
});
document.getElementById('filter-lang').addEventListener('change', loadResources);
document.getElementById('filter-level').addEventListener('change', loadResources);
document.getElementById('sort').addEventListener('change', loadResources);

// Delegated click for resource cards
document.addEventListener('click', e => {
  const card = e.target.closest('[data-resource-id]');
  if (card) window.location = '/resource/' + card.dataset.resourceId;
});

function applyFeaturedFavs(){
  document.querySelectorAll('.fav-corner').forEach(btn=>setFavBtn(btn,favSet.has(Number(btn.dataset.fid))));
}
// Deep-link: prefijar búsqueda/orden desde la URL (?search=, ?sort=) — también
// hace que funcionen enlaces como "Ver todos → /?sort=popular".
(function(){
  const p = new URLSearchParams(location.search);
  if (p.get('search')) document.getElementById('search').value = p.get('search');
  if (p.get('sort'))   document.getElementById('sort').value   = p.get('sort');
})();
loadFavorites().finally(()=>{ applyFeaturedFavs(); loadResources(); });
</script>
</body>
</html>
