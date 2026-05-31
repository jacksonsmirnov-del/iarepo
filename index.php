<?php
// ================================================================
// index.php — iarepo.com Landing Page + Health Check
//
// Browser request (Accept: text/html) → renders landing page
// API request (Accept: application/json) → returns JSON health check
// ================================================================

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/helpers.php';
$sessionUser = getSessionUser();
$env = require __DIR__ . '/.env.php';
$googleClientId = $env['GOOGLE_CLIENT_ID'] ?? '';

// Featured: top 6 por uso + vistas (server-side para SEO)
$db = getResourcesDB();
$featuredStmt = $db->query("
    SELECT r.id, r.title, r.description, r.code_type, r.source_name,
           r.view_count, r.use_count, r.like_count, r.fork_count,
           r.subject_area, r.level, r.lang,
           c.name AS category_name, c.icon AS category_icon
    FROM resources r
    LEFT JOIN categories c ON c.id = r.category_id
    WHERE r.is_active = 1
      AND r.visibility = 'community'
      AND r.moderation_status = 'approved'
    ORDER BY (r.use_count * 3 + r.view_count + r.like_count * 2) DESC
    LIMIT 8
");
$featured = $featuredStmt->fetchAll();
$levelLabels = ['primary'=>'Primaria','secondary'=>'Secundaria','ib'=>'IB','university'=>'Universidad','general'=>'General'];

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
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iarepo — Repositorio abierto de recursos educativos interactivos</title>
<meta name="description" content="Descubre, comparte y ejecuta simulaciones, herramientas y recursos educativos interactivos. El GitHub para profesores.">
<meta property="og:title" content="iarepo — Recursos educativos interactivos">
<meta property="og:description" content="Repositorio abierto de simulaciones, herramientas y recursos interactivos para la enseñanza.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://iarepo.com">
<link rel="canonical" href="https://iarepo.com">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
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
.hero::before{content:'';position:absolute;top:-200px;left:50%;transform:translateX(-50%);width:800px;height:800px;background:radial-gradient(circle,var(--hero-glow) 0%,transparent 70%);pointer-events:none}
.hero-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;background:var(--badge-bg);border:1px solid var(--badge-border);color:var(--badge-text);font-size:13px;font-weight:500;margin-bottom:20px}
.hero h1{font-size:clamp(2.2rem,5vw,3.8rem);font-weight:800;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:16px;line-height:1.15}
.hero p{font-size:clamp(1rem,2vw,1.2rem);color:var(--text2);max-width:600px;margin:0 auto 32px;line-height:1.6}
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
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:.3s;cursor:pointer;position:relative;box-shadow:var(--shadow)}
.card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:var(--shadow-hover)}
.card-header{padding:20px 20px 12px;display:flex;align-items:flex-start;gap:12px}
.card-icon{width:40px;height:40px;border-radius:10px;background:var(--badge-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--accent2)}
.card-title{font-size:1rem;font-weight:600;line-height:1.3;flex:1}
.card-body{padding:0 20px 16px}
.card-desc{font-size:.85rem;color:var(--text2);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-footer{padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:.8rem;color:var(--text3)}
.card-tags{display:flex;gap:6px;flex-wrap:wrap}
.tag{padding:2px 8px;border-radius:4px;background:var(--source-bg);color:var(--accent2);font-size:.7rem}
.card-meta{display:flex;align-items:center;gap:12px}
.card-meta span{display:flex;align-items:center;gap:4px}
.source-badge{position:absolute;top:12px;right:12px;padding:2px 8px;border-radius:4px;background:var(--source-bg);border:1px solid var(--source-border);color:var(--accent2);font-size:.65rem;font-weight:500}
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
.fcard{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px;box-shadow:var(--shadow);transition:.2s;text-decoration:none;display:flex;flex-direction:column;gap:6px}
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
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body>

<div class="topnav">

<!-- User auth bar -->
<div class="auth-bar">
<?php if ($sessionUser): ?>
  <a href="/dashboard/" class="auth-user" title="Mis Recursos">
    <?php if ($sessionUser['avatar_url']): ?>
      <img src="<?= htmlspecialchars($sessionUser['avatar_url']) ?>" alt="" class="auth-avatar">
    <?php endif; ?>
    <span><?= htmlspecialchars($sessionUser['name']) ?></span>
  </a>
  <a href="/auth/logout.php" class="auth-logout" title="Salir">Salir</a>
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
<button class="present-btn" id="presentBtn" title="Modo presentación">
  <i data-lucide="maximize" style="width:14px;height:14px"></i> Presentar
</button>

<!-- Theme toggle -->
<button class="theme-toggle" title="Cambiar tema" id="theme-btn">
  <i data-lucide="moon" style="width:18px;height:18px" id="theme-icon-dark"></i>
  <i data-lucide="sun" style="width:18px;height:18px" id="theme-icon-light" style="display:none"></i>
</button>

</div><!-- /topnav -->

<!-- Presentation overlay -->
<div class="present-overlay" id="present-overlay">
  <div class="present-content" id="present-content"></div>
  <div class="present-esc">Presiona ESC para salir</div>
</div>

<section class="hero">
  <div class="hero-badge"><i data-lucide="sparkles" style="width:14px;height:14px"></i> Open Educational Resources</div>
  <h1><img src="/assets/img/logo.svg" alt="iarepo" style="height:48px;width:auto;display:inline-block;vertical-align:middle"></h1>
  <p>Repositorio abierto de recursos educativos interactivos. Descubre simulaciones, herramientas y modelos de IA — listos para usar en tu clase.</p>
  <div class="hero-stats">
    <div class="hero-stat"><strong id="stat-total">—</strong><span>Recursos</span></div>
    <div class="hero-stat"><strong id="stat-cats">—</strong><span>Categorías</span></div>
    <div class="hero-stat"><strong id="stat-types">—</strong><span>Tipos</span></div>
  </div>
  <div class="search-wrap">
    <i data-lucide="search" class="search-icon" style="width:20px;height:20px"></i>
    <input type="search" id="search" placeholder="Buscar recursos... (ej: waves, pendulum, pH)" autocomplete="off">
  </div>
</section>

<?php if ($featured): ?>
<section class="featured">
  <div class="featured-header">
    <h2><i data-lucide="flame" style="width:18px;height:18px;color:#f97316"></i> Más usados</h2>
    <a href="/?sort=popular">Ver todos →</a>
  </div>
  <div class="featured-grid">
    <?php foreach ($featured as $f):
      $typeLabel = $f['code_type'] === 'html' ? '⭐ IA' : strtoupper($f['code_type']);
      $typeStyle = $f['code_type'] === 'html'
        ? 'background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(6,182,212,.1));color:var(--accent);border:1px solid rgba(124,58,237,.15)'
        : '';
    ?>
    <a href="/resource/<?= (int)$f['id'] ?>" class="fcard">
      <span class="fcard-type" style="<?= $typeStyle ?>"><?= h($typeLabel) ?></span>
      <div class="fcard-title"><?= h($f['title']) ?></div>
      <div class="fcard-meta">
        <span>👁 <?= (int)$f['view_count'] ?></span>
        <span>❤ <?= (int)$f['like_count'] ?></span>
        <?php if ($f['category_name']): ?><span><?= h($f['category_icon'] ?? '') ?> <?= h($f['category_name']) ?></span><?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$sessionUser): ?>
<section class="how-it-works">
  <div class="hiw-steps">
    <div class="hiw-step">
      <div class="hiw-icon"><i data-lucide="sparkles" style="width:24px;height:24px;color:#fff"></i></div>
      <h3>Genera con IA</h3>
      <p>Pídele a Gemini o ChatGPT una simulación interactiva en HTML para tu clase.</p>
    </div>
    <div class="hiw-arrow">→</div>
    <div class="hiw-step">
      <div class="hiw-icon"><i data-lucide="upload" style="width:24px;height:24px;color:#fff"></i></div>
      <h3>Súbela en 30s</h3>
      <p>Pega el código, elige la materia y publícala. Sin instalación, sin cuenta de pago.</p>
    </div>
    <div class="hiw-arrow">→</div>
    <div class="hiw-step">
      <div class="hiw-icon"><i data-lucide="globe" style="width:24px;height:24px;color:#fff"></i></div>
      <h3>Profesores la usan</h3>
      <p>Cualquier profesor del mundo puede encontrarla, usarla o adaptarla para su curso.</p>
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
    <p style="margin-top:10px;font-size:.78rem;color:var(--text3)">Gratis · Sin tarjeta · Solo con Google</p>
  </div>
</section>
<?php endif; ?>

<div id="categories" class="cats"></div>

<div class="container">
  <div class="toolbar">
    <span class="result-count" id="result-count"></span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select class="sort-select" id="filter-lang" title="Idioma">
        <option value="">🌐 Idioma</option>
        <option value="es">🇪🇸 Español</option>
        <option value="en">🇬🇧 English</option>
        <option value="pt">🇧🇷 Português</option>
      </select>
      <select class="sort-select" id="filter-level" title="Nivel">
        <option value="">📚 Nivel</option>
        <option value="primary">Primaria</option>
        <option value="secondary">Secundaria</option>
        <option value="ib">IB</option>
        <option value="university">Universidad</option>
        <option value="general">General</option>
      </select>
      <select class="sort-select" id="sort">
        <option value="recent">Más recientes</option>
        <option value="popular">Más usados</option>
        <option value="views">Más vistos</option>
        <option value="title">Alfabético</option>
      </select>
    </div>
  </div>
  <div id="grid" class="grid">
    <div class="loading"><div class="spinner"></div>Cargando recursos...</div>
  </div>
</div>

<footer class="footer">
  <p><strong>iarepo.com</strong> — Repositorio abierto de recursos educativos interactivos</p>
  <p style="margin-top:8px">
    <a href="/legal/terms.php">Términos de uso</a> ·
    <a href="https://github.com/claseprivada/iarepo" target="_blank">GitHub (MIT)</a> ·
    <a href="https://claseprivada.com">Clase Privada</a>
  </p>
  <p style="margin-top:6px;font-size:.78rem;color:var(--text3)">Los recursos externos pertenecen a sus respectivos autores. iarepo solo enlaza y cataloga.</p>
</footer>

<script>
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

async function loadResources() {
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
    if (!data.ok) { grid.innerHTML = '<div class="empty">Error loading resources</div>'; return; }

    document.getElementById('stat-total').textContent = data.total;
    document.getElementById('result-count').textContent = `${data.total} recurso${data.total !== 1 ? 's' : ''}`;

    if (data.categories && !document.querySelector('.cat-pill')) renderCategories(data.categories);

    if (data.resources.length === 0) { grid.innerHTML = '<div class="empty">No se encontraron recursos</div>'; return; }

    grid.innerHTML = data.resources.map(r => renderCard(r)).join('');
    lucide.createIcons();
  } catch (e) {
    grid.innerHTML = '<div class="empty">Error de conexión</div>';
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
  const levelLabel = {primary:'Primaria',secondary:'Secundaria',ib:'IB',university:'Universidad',general:'General'}[levelClass] || levelClass;
  const source = r.source_name ? `<span class="source-badge">${r.source_name}</span>` : '';
  const langFlag = {'es':'🇪🇸','en':'🇬🇧','pt':'🇧🇷'}[r.lang] || '🌐';
  // Favicon from source URL
  let favicon = '';
  if (r.source_url) {
    try {
      const domain = new URL(r.source_url).hostname;
      favicon = `<img src="https://www.google.com/s2/favicons?domain=${domain}&sz=32" alt="" style="width:20px;height:20px;border-radius:4px;object-fit:contain" onerror="this.style.display='none'">`;
    } catch(e) {}
  }
  return `<div class="card" data-resource-id="${r.id}">
    ${source}
    <div class="card-header">
      <div class="card-icon">${favicon || `<i data-lucide="${icon}" style="width:20px;height:20px"></i>`}</div>
      <div class="card-title">${esc(r.title)}</div>
    </div>
    <div class="card-body"><div class="card-desc">${esc(r.description || '')}</div></div>
    <div class="card-footer">
      <div class="card-tags">${r.code_type==='html'?'<span class="badge-ia"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg> IA</span>':''}<span class="badge-level ${levelClass}">${levelLabel}</span><span class="tag">${r.code_type==='html'?'HTML':r.code_type}</span><span class="tag">${langFlag}</span>${(r.tags&&r.tags.length)?r.tags.slice(0,3).map(t=>`<a href="/?tag=${encodeURIComponent(t)}" class="tag" style="color:var(--accent2);text-decoration:none" onclick="event.stopPropagation()">${esc(t)}</a>`).join(''):''}</div>
      <div class="card-meta">
        <span><i data-lucide="eye" style="width:12px;height:12px"></i> ${r.view_count||0}</span>
        <span><i data-lucide="heart" style="width:12px;height:12px"></i> ${r.like_count||0}</span>
        <span><i data-lucide="git-fork" style="width:12px;height:12px"></i> ${r.fork_count||0}</span>
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

loadResources();
</script>
</body>
</html>
