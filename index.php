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

// ── Orden inicial del <select id="sort"> ──────────────────────
// El defecto del servidor NO es fijo: api/resources.php ordena por RELEVANCIA
// cuando hay texto de búsqueda y el cliente no manda ?sort= (ver su bloque
// "── Sort ──"). Replicamos esa misma decisión aquí para que el desplegable ya
// llegue pintado con el orden real: sin esto mostraría "Más recientes" mientras
// la API devuelve relevancia, y el usuario leería una mentira.
// La relevancia sin términos que puntuar no significa nada → se descarta.
// La lista blanca y su regla ("un sort desconocido cuenta como ausente, no
// como elección explícita") son las de api/resources.php, bloque "── Sort ──":
// esa es la pieza que manda. Aquí sólo se replica para pintar el <select>.
$sortOptions = ['relevance', 'recent', 'popular', 'views', 'title'];
$querySearch = trim((string) ($_GET['search'] ?? ''));
$sortParam   = (string) ($_GET['sort'] ?? '');
if (!in_array($sortParam, $sortOptions, true)) $sortParam = '';       // desconocido ≡ ausente (igual que la API)
if ($sortParam === 'relevance' && $querySearch === '') $sortParam = '';
$sortSelected = $sortParam !== '' ? $sortParam : ($querySearch !== '' ? 'relevance' : 'recent');
/** Marca la <option> del orden vigente (el JS vuelve a normalizarlo al arrancar). */
$sortSel = static fn(string $v): string => $v === $sortSelected ? ' selected' : '';
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

/* Sólo para lectores de pantalla (label del buscador, h1 en modo búsqueda) */
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}

/* Search */
.search-wrap{max-width:600px;margin:0 auto 40px;position:relative}
.search-wrap input{width:100%;padding:14px 46px 14px 48px;border-radius:50px;border:1px solid var(--border);background:var(--bg2);color:var(--text);font-size:1rem;font-family:inherit;outline:none;transition:.3s;box-shadow:var(--shadow)}
.search-wrap input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,58,237,.15)}
/* La ✕ nativa de WebKit duplicaría nuestro botón de limpiar */
.search-wrap input::-webkit-search-cancel-button{-webkit-appearance:none;appearance:none}
.search-wrap .search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--text3)}
.search-clear{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:36px;height:36px;display:flex;align-items:center;justify-content:center;border:none;background:none;color:var(--text3);cursor:pointer;border-radius:50%;transition:.15s}
.search-clear:hover{color:var(--accent);background:var(--bg3)}
.search-clear i,.search-clear svg{width:17px;height:17px}
/* display:flex ganaría al atributo [hidden]: hay que anularlo explícitamente */
.search-clear[hidden]{display:none}

/* Categories */
.cats{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding:0 24px;margin-bottom:40px}
.cat-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;min-height:38px;border-radius:20px;border:1px solid var(--border);background:var(--bg2);color:var(--text2);font-size:.85rem;cursor:pointer;transition:.2s;font-family:inherit;box-shadow:var(--shadow)}
.cat-pill:hover,.cat-pill.active{border-color:var(--accent);color:var(--accent);background:var(--badge-bg)}
.cat-pill .count{font-size:.75rem;color:var(--text3);margin-left:2px}

/* Grid */
.container{max-width:1200px;margin:0 auto;padding:0 24px 80px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:18px}
/* La tarjeta es un <a> real (foco de teclado, abrir en pestaña nueva, SEO);
   el botón ⭐ vive FUERA del enlace porque <button> dentro de <a> es inválido. */
.card-wrap{position:relative;display:flex}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:transform .2s,box-shadow .2s,border-color .2s;cursor:pointer;position:relative;box-shadow:var(--shadow);display:flex;flex-direction:column;width:100%;color:inherit;text-decoration:none}
.card:hover{border-color:var(--accent);transform:translateY(-3px);box-shadow:var(--shadow-hover);opacity:1}
.card:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
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
/* Sin esto, el mensaje es una celda más del grid y se comprime a ~270px */
.grid>.loading,.grid>.empty{grid-column:1/-1}
.loading .spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}
/* El vacío y el error dejan de ser callejones sin salida: dicen qué pasó y ofrecen salida */
.state-icon{font-size:2rem;line-height:1;margin-bottom:12px}
.state-title{font-size:1.05rem;font-weight:700;color:var(--text);margin-bottom:8px}
.state-hint{max-width:460px;margin:0 auto 18px;line-height:1.55;font-size:.9rem}
.state-label{font-size:.82rem;margin-bottom:8px}
.state-chips{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:18px}
.btn-more{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 20px;min-height:40px;border-radius:22px;border:1px solid var(--border);background:var(--bg2);color:var(--text2);font-family:inherit;font-size:.88rem;cursor:pointer;transition:.2s;box-shadow:var(--shadow)}
.btn-more:hover{border-color:var(--accent);color:var(--accent)}
.btn-more[disabled]{opacity:.6;cursor:default}
.btn-more .count{font-size:.78rem;color:var(--text3)}
.more-wrap{text-align:center;padding:24px 0 0}
/* Filtros activos: qué está recortando los resultados, y cómo quitarlo */
.active-filters{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}
.active-filters:empty{display:none}
.chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;min-height:34px;border-radius:17px;border:1px solid var(--badge-border);background:var(--badge-bg);color:var(--badge-text);font-family:inherit;font-size:.8rem;cursor:pointer;transition:.15s}
.chip:hover{border-color:var(--accent);background:var(--card)}
.chip-clear{background:none;border-color:var(--border);color:var(--text2)}

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
.sort-select{padding:8px 12px;min-height:38px;border-radius:8px;border:1px solid var(--border);background:var(--bg2);color:var(--text);font-family:inherit;font-size:.85rem;cursor:pointer}

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
/* overflow:visible es LA clave: un ancestro con overflow:hidden se convierte en
   el scrollport del position:sticky y la barra se despega a los ~130px de scroll.
   El glow de .hero::before (único motivo del hidden) ya está oculto aquí. */
body.searching .hero{padding:70px 24px 8px;overflow:visible}
body.searching .hero::before,
body.searching .hero > :not(.search-wrap):not(.hero-title),
body.searching .featured,
body.searching .how-it-works{display:none}
/* El h1 no se oculta con display:none: la página no puede quedarse sin
   encabezado accesible al entrar por deep-link (/?search=…, /?sort=popular). */
body.searching .hero-title{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
body.searching .topnav{background:var(--bg);border-bottom:1px solid var(--border)}
body.searching .search-wrap{position:sticky;top:58px;z-index:90;background:var(--bg);padding:10px 0;margin-bottom:0}
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
    <label for="search" class="sr-only"><?= h(t('Buscar recursos')) ?></label>
    <i data-lucide="search" class="search-icon" aria-hidden="true" style="width:20px;height:20px"></i>
    <input type="search" id="search" enterkeyhint="search" autocomplete="off" autocapitalize="off"
           spellcheck="false" aria-describedby="result-count"
           placeholder="<?= h(t('Buscar por tema: ondas, fracciones, circuitos…')) ?>">
    <button type="button" id="search-clear" class="search-clear" hidden
            title="<?= h(t('Limpiar búsqueda')) ?>" aria-label="<?= h(t('Limpiar búsqueda')) ?>"><i data-lucide="x" aria-hidden="true"></i></button>
  </div>
  <div class="hero-stats">
    <div class="hero-stat"><strong id="stat-total">—</strong><span><?= h(t('Recursos')) ?></span></div>
    <div class="hero-stat"><strong id="stat-cats">—</strong><span><?= h(t('Categorías')) ?></span></div>
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
    <span class="result-count" id="result-count" role="status" aria-live="polite" aria-atomic="true"></span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select class="sort-select" id="filter-lang" title="<?= h(t('Idioma')) ?>">
        <option value=""><?= h(t('🌐 Idioma')) ?></option>
        <option value="es"><?= h(t('🇪🇸 Español')) ?></option>
        <option value="en"><?= h(t('🇬🇧 English')) ?></option>
        <!-- 'pt' retirado: 0 recursos en catálogo, era un estado vacío garantizado -->
      </select>
      <select class="sort-select" id="filter-level" title="<?= h(t('Nivel')) ?>">
        <option value=""><?= h(t('📚 Nivel')) ?></option>
        <option value="primary"><?= h(t('Primaria')) ?></option>
        <option value="secondary"><?= h(t('Secundaria')) ?></option>
        <option value="ib"><?= h(t('IB')) ?></option>
        <option value="university"><?= h(t('Universidad')) ?></option>
        <option value="general"><?= h(t('General')) ?></option>
      </select>
      <select class="sort-select" id="sort" title="<?= h(t('Orden')) ?>">
        <!-- Relevancia: sólo existe mientras haya texto que puntuar. Fuera de
             ese caso va hidden+disabled (así el navegador tampoco la elige como
             primera opción al resetear el formulario); syncSortOptions() la
             muestra al buscar y la retira al vaciar la búsqueda. -->
        <option value="relevance" id="sort-relevance"<?= $querySearch !== '' ? '' : ' hidden disabled' ?><?= $sortSel('relevance') ?>><?= h(t('Más relevantes')) ?></option>
        <option value="recent"<?= $sortSel('recent') ?>><?= h(t('Más recientes')) ?></option>
        <option value="popular"<?= $sortSel('popular') ?>><?= h(t('Más usados')) ?></option>
        <option value="views"<?= $sortSel('views') ?>><?= h(t('Más vistos')) ?></option>
        <option value="title"<?= $sortSel('title') ?>><?= h(t('Alfabético')) ?></option>
      </select>
    </div>
  </div>
  <div id="active-filters" class="active-filters" aria-label="<?= h(t('Filtros activos')) ?>"></div>
  <div id="grid" class="grid">
    <div class="loading"><div class="spinner"></div><?= h(t('Cargando recursos...')) ?></div>
  </div>
  <div id="more-wrap" class="more-wrap"></div>
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
  // ── Buscador ──
  searching: <?= json_encode(t('Buscando…')) ?>,
  loadMore: <?= json_encode(t('Cargar más')) ?>,
  showingOf: <?= json_encode(t('Mostrando %1 de %2 recursos')) ?>,
  noResultsFor: <?= json_encode(t('Sin resultados para «%s»'), JSON_UNESCAPED_UNICODE) ?>,
  noResultsHint: <?= json_encode(t('Prueba con una palabra más corta, en singular, o en inglés: muchos títulos del catálogo están en inglés.')) ?>,
  noResultsFilters: <?= json_encode(t('Ningún recurso coincide con los filtros activos: %s')) ?>,
  clearFilters: <?= json_encode(t('Limpiar filtros')) ?>,
  retry: <?= json_encode(t('Reintentar')) ?>,
  rateLimited: <?= json_encode(t('Demasiadas búsquedas seguidas. Espera unos segundos y reinténtalo.')) ?>,
  fSearch: <?= json_encode(t('Búsqueda')) ?>,
  fLang: <?= json_encode(t('Idioma')) ?>,
  fLevel: <?= json_encode(t('Nivel')) ?>,
  fCategory: <?= json_encode(t('Categoría')) ?>,
  removeFilter: <?= json_encode(t('Quitar filtro: %s')) ?>,
  allCats: <?= json_encode(t('Todos')) ?>,
  suggestions: <?= json_encode(t('Prueba con:')) ?>,
  // Lista de sugerencias del estado vacío: términos VERIFICADOS contra el
  // catálogo real (ES 9/6/5/10/7 · EN 19/9/2/14/3 resultados). Se separan por coma.
  suggestList: <?= json_encode(t('ondas,fracciones,circuitos,algebra,sistema solar')) ?>,
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
  // Vaciar el clon SIEMPRE: si se queda en el DOM duplica los id (#grid,
  // #result-count, #more-wrap…) y, como el overlay va antes en el documento,
  // getElementById devolvería el clon muerto y el buscador dejaría de pintar.
  document.getElementById('present-content').innerHTML = '';
}
document.addEventListener('fullscreenchange', () => {
  if (!document.fullscreenElement) exitPresent();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') exitPresent();
});

// ── API ──
const API = '/api/resources.php';
const $ = id => document.getElementById(id);
let currentCat = null;
let debounceTimer = null;
let reqSeq = 0;           // token de secuencia: descarta respuestas tardías
let inflight = null;      // AbortController de la petición en vuelo
let page = 1;             // página actual ("Cargar más")
let shown = 0;            // tarjetas pintadas ahora mismo
let lastTotal = 0;        // total devuelto para la consulta actual
let catalogTotal = null;  // total SIN filtros (contador del hero); se fija una vez
let sortExplicit = false; // ¿el orden lo eligió el usuario (o un deep-link ?sort=)?

// Lista blanca: r.level es VARCHAR(50) libre, llega de BD sin validar y no
// puede acabar dentro de una clase CSS ni de HTML sin escapar (XSS almacenado).
const LEVEL_CLASSES = {primary:1, secondary:1, ib:1, university:1, general:1};

function esc(s){ const d = document.createElement('div'); d.textContent = (s === null || s === undefined) ? '' : String(s); return d.innerHTML; }
// esc() NO escapa comillas → nunca vale para un valor de atributo.
function escAttr(s){ return esc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
// String.replace con reemplazo de texto interpreta $&, $` y $': siempre función.
function fill(tpl, token, value){ return String(tpl).replace(token, () => value); }

// ¿El usuario está buscando/filtrando? (entonces colapsamos la portada)
function hasQuery(){ return $('search').value.trim() !== ''; }
function hasFilters(){ return !!(currentCat || $('filter-lang').value || $('filter-level').value); }
function isBrowsing(){ return hasQuery() || hasFilters() || $('sort').value !== 'recent'; }
function updateBrowseMode(){ document.body.classList.toggle('searching', isBrowsing()); }

// El orden por relevancia sólo tiene sentido con términos que puntuar: la opción
// aparece al buscar y pasa a ser el defecto (que es justo lo que hace la API si
// no le mandamos 'sort'), y se retira al vaciar la búsqueda devolviendo el select
// a 'recent'. Una elección deliberada —del usuario o de un ?sort= en la URL— se
// respeta y NO se pisa al seguir tecleando.
function syncSortOptions(){
  const s = $('sort'), opt = $('sort-relevance'), on = hasQuery();
  opt.hidden = !on; opt.disabled = !on;
  if (on) { if (!sortExplicit) s.value = 'relevance'; }
  else if (s.value === 'relevance') { s.value = 'recent'; sortExplicit = false; }
}

function buildParams(){
  const p = new URLSearchParams();
  const q = $('search').value.trim();
  if (q) p.set('search', q);
  if (currentCat) p.set('category', currentCat);
  const lg = $('filter-lang').value;  if (lg) p.set('lang', lg);
  const lv = $('filter-level').value; if (lv) p.set('level', lv);
  // 'recent' YA NO es el defecto universal: con búsqueda, el defecto de la API
  // es 'relevance'. Mandamos 'sort' sólo cuando difiere de ese defecto, así la
  // URL sigue siendo corta y '?search=ondas' significa lo mismo aquí y allí.
  const so = $('sort').value, dflt = q ? 'relevance' : 'recent';
  if (so && so !== dflt) p.set('sort', so);
  return p;
}

// ── Estado ↔ URL: la búsqueda se puede compartir, marcar y deshacer con "atrás" ──
function syncURL(push){
  const qs = buildParams().toString();
  const url = qs ? location.pathname + '?' + qs : location.pathname;
  if (url === location.pathname + location.search) return;
  if (push) history.pushState(null, '', url); else history.replaceState(null, '', url);
}
function applyStateFromURL(){
  const p = new URLSearchParams(location.search);
  $('search').value = p.get('search') || '';
  // Un valor que no existe entre las <option> deja el select en '': lo devolvemos
  // a su defecto. Un ?sort= válido cuenta como elección explícita y bloquea el
  // salto automático a relevancia (deep-link y botón atrás mandan sobre él).
  $('sort').value = p.get('sort') || '';
  sortExplicit = $('sort').value !== '';
  if (!sortExplicit) $('sort').value = 'recent';
  $('filter-lang').value  = p.get('lang')  || '';
  $('filter-level').value = p.get('level') || '';
  currentCat = p.get('category') || null;
  syncSortOptions();
  syncClearBtn();
  markActivePill();
}
window.addEventListener('popstate', () => { applyStateFromURL(); loadResources({push:false}); });

function showState(html){ $('grid').innerHTML = html; lucide.createIcons(); }
function clearCount(){ $('result-count').textContent = ''; }
function setCount(){
  const el = $('result-count');
  if (lastTotal === 0) { el.textContent = T.noResults; return; }
  el.textContent = shown < lastTotal
    ? fill(fill(T.showingOf, '%1', shown), '%2', lastTotal)
    : lastTotal + ' ' + (lastTotal !== 1 ? T.resources : T.resource);
}

async function loadResources(opt) {
  opt = opt || {};
  // Antes de construir la URL: el orden mostrado y el pedido tienen que coincidir.
  syncSortOptions();
  updateBrowseMode();
  const grid = $('grid');
  if (!opt.append) { page = 1; shown = 0; }
  // Al teclear, replaceState (no ensuciar el historial con una entrada por letra);
  // en acciones deliberadas (Enter, select, píldora, chip), pushState.
  if (!opt.append && opt.push !== false) syncURL(opt.push === true);

  const p = buildParams();
  p.set('limit', '50');
  p.set('page', String(page));

  // Doble red contra la carrera del debounce: abortamos la petición vieja Y
  // descartamos por token (su respuesta pudo quedar ya en la cola de microtareas).
  if (inflight) inflight.abort();
  inflight = new AbortController();
  const my = ++reqSeq;
  const signal = inflight.signal;

  grid.setAttribute('aria-busy', 'true');
  renderActiveFilters();
  if (opt.append) setMoreBusy(true);
  else showState('<div class="loading"><div class="spinner"></div>' + esc(T.searching) + '</div>');

  try {
    const res = await fetch(API + '?' + p.toString(), {signal: signal});
    if (my !== reqSeq) return;
    if (res.status === 429) { fail(T.rateLimited, opt.append); return; }
    let data = null;
    try { data = await res.json(); } catch (parseErr) { data = null; }
    if (my !== reqSeq) return;
    if (!res.ok || !data || !data.ok) {
      fail((data && data.error) ? String(data.error) : T.loadError, opt.append);
      return;
    }
    renderResults(data, !!opt.append);
  } catch (e) {
    if (e && e.name === 'AbortError') return;  // cancelación nuestra: no es un error
    if (my !== reqSeq) return;
    fail(T.connError, opt.append);
  } finally {
    if (my === reqSeq) { grid.removeAttribute('aria-busy'); inflight = null; setMoreBusy(false); }
  }
}

// Fallo de red/servidor: pantalla DISTINTA del estado vacío (⚠, sin sugerencias,
// con "Reintentar") y sin contador obsoleto encima. Si lo que falla es un
// "Cargar más" no borramos lo ya pintado: avisamos y dejamos reintentar.
function fail(msg, append){
  if (append) { page = Math.max(1, page - 1); renderMore(); showFavToast(msg); return; }
  lastTotal = 0; shown = 0;
  clearCount();
  $('more-wrap').innerHTML = '';
  showState('<div class="empty">' +
    '<div class="state-icon" aria-hidden="true">⚠️</div>' +
    '<h2 class="state-title">' + esc(msg) + '</h2>' +
    '<div><button class="btn-more" type="button" data-retry="1">' + esc(T.retry) + '</button></div>' +
  '</div>');
}

function renderResults(data, append) {
  const grid = $('grid');
  lastTotal = Number(data.total) || 0;
  if (data.categories && !document.querySelector('#categories .cat-pill')) renderCategories(data.categories);
  // El contador del hero mide el CATÁLOGO, no la búsqueda: se fija una sola vez
  // (primera carga sin filtros) y no se vuelve a tocar.
  if (catalogTotal === null && !hasQuery() && !hasFilters()) {
    catalogTotal = lastTotal;
    $('stat-total').textContent = lastTotal;
  }
  const list = Array.isArray(data.resources) ? data.resources : [];
  if (!append && list.length === 0) {
    shown = 0; setCount(); showState(emptyHTML()); renderMore(); renderActiveFilters();
    return;
  }
  const html = list.map(renderCard).join('');
  if (append) grid.insertAdjacentHTML('beforeend', html); else grid.innerHTML = html;
  shown += list.length;
  setCount(); renderMore(); renderActiveFilters();
  lucide.createIcons();
}

// "546 recursos" y 50 tarjetas era mentira: o se pintan todos, o hay cómo pedir más.
function renderMore(){
  $('more-wrap').innerHTML = (shown > 0 && shown < lastTotal)
    ? '<button class="btn-more" type="button" id="more-btn">' + esc(T.loadMore) + ' <span class="count">(' + (lastTotal - shown) + ')</span></button>'
    : '';
}
function setMoreBusy(on){
  const b = $('more-btn');
  if (!b) return;
  if (on) { b.disabled = true; b.textContent = T.searching; }
  else if (b.disabled) renderMore();
}

// Estado vacío accionable: qué se buscó, qué está filtrando y por dónde salir.
function emptyHTML(){
  const q = $('search').value.trim();
  const chips = String(T.suggestList).split(',').map(s => s.trim()).filter(Boolean)
    .map(s => '<button class="cat-pill" type="button" data-suggest="' + escAttr(s) + '">' + esc(s) + '</button>')
    .join('');
  const title = q ? fill(T.noResultsFor, '%s', esc(q)) : esc(T.noResults);
  const hint  = hasFilters() ? fill(T.noResultsFilters, '%s', esc(filterSummary())) : esc(T.noResultsHint);
  return '<div class="empty">' +
    '<div class="state-icon" aria-hidden="true">🔍</div>' +
    '<h2 class="state-title">' + title + '</h2>' +
    '<p class="state-hint">' + hint + '</p>' +
    '<p class="state-label">' + esc(T.suggestions) + '</p>' +
    '<div class="state-chips">' + chips + '</div>' +
    ((q || hasFilters()) ? '<button class="btn-more" type="button" data-clear="all">' + esc(T.clearFilters) + '</button>' : '') +
  '</div>';
}

// currentCat viene de la URL: nunca lo metemos en un selector CSS (querySelector
// lanzaría con un valor arbitrario). Comparamos leyendo el dataset.
function pillFor(id){
  let found = null;
  document.querySelectorAll('#categories .cat-pill').forEach(p => {
    if ((p.dataset.catId || '') === String(id)) found = p;
  });
  return found;
}
function markActivePill(){
  document.querySelectorAll('#categories .cat-pill').forEach(p =>
    p.classList.toggle('active', (p.dataset.catId || '') === (currentCat || '')));
}
function activeFilterList(){
  const out = [];
  if (currentCat) {
    const pill = pillFor(currentCat);
    out.push({k:'category', label: pill ? pill.textContent.replace(/\s+/g, ' ').trim() : T.fCategory});
  }
  const lg = $('filter-lang');
  if (lg.value) out.push({k:'lang', label: T.fLang + ': ' + lg.options[lg.selectedIndex].text});
  const lv = $('filter-level');
  if (lv.value) out.push({k:'level', label: T.fLevel + ': ' + lv.options[lv.selectedIndex].text});
  return out;
}
function filterSummary(){ return activeFilterList().map(f => f.label).join(' · '); }
function renderActiveFilters(){
  const items = activeFilterList();
  const q = $('search').value.trim();
  if (q) items.unshift({k:'search', label: T.fSearch + ': ' + q});
  let html = items.map(f =>
    '<button class="chip" type="button" data-clear="' + escAttr(f.k) + '" aria-label="' + escAttr(fill(T.removeFilter, '%s', f.label)) + '">' +
    esc(f.label) + ' <span aria-hidden="true">✕</span></button>').join('');
  if (items.length > 1) html += '<button class="chip chip-clear" type="button" data-clear="all">' + esc(T.clearFilters) + '</button>';
  $('active-filters').innerHTML = html;
}

function syncClearBtn(){ $('search-clear').hidden = !$('search').value; }
function searchFor(q){
  const i = $('search');
  i.value = q; syncClearBtn();
  clearTimeout(debounceTimer);
  loadResources({push:true});
  i.focus();
}
function clearSearch(){
  $('search').value = ''; syncClearBtn();
  clearTimeout(debounceTimer);
  loadResources({push:true});
}
function clearFilters(){
  $('search').value = '';
  $('filter-lang').value = '';
  $('filter-level').value = '';
  $('sort').value = 'recent';
  sortExplicit = false;
  currentCat = null;
  markActivePill(); syncClearBtn();
  clearTimeout(debounceTimer);
  loadResources({push:true});
  $('search').focus();
}

function renderCategories(cats) {
  const activeCats = cats.filter(c => parseInt(c.resource_count, 10) > 0);
  $('stat-cats').textContent = activeCats.length;
  let html = '<button class="cat-pill" type="button" data-cat-id="">' + esc(T.allCats) + '</button>';
  activeCats.forEach(c => {
    html += '<button class="cat-pill" type="button" data-cat-id="' + escAttr(c.id) + '">' +
      '<i data-lucide="' + escAttr(c.icon || 'folder') + '" style="width:14px;height:14px" aria-hidden="true"></i> ' +
      esc(c.name) + ' <span class="count">' + esc(c.resource_count) + '</span></button>';
  });
  $('categories').innerHTML = html;
  markActivePill();
  lucide.createIcons();
}


// TODO valor de BD es hostil: title/description ya se escapaban, pero level y
// code_type no, y level es texto libre de 50 chars → XSS almacenado en portada.
function renderCard(r) {
  const rid = Number(r.id) || 0;
  const icon = escAttr(r.category_icon || 'file-code');
  const lvRaw = r.level || 'general';
  const levelKey = LEVEL_CLASSES[lvRaw] ? lvRaw : 'general';   // clase CSS: sólo lista blanca
  // La etiqueta sale de la clave YA filtrada, no del texto de BD: un level
  // inventado se muestra como "General" en vez de escupir 50 chars arbitrarios.
  const levelLabel = esc(T.levels[levelKey] || levelKey);
  const typeLabel = r.code_type === 'html' ? 'HTML' : esc(r.code_type || '');
  const langFlag = {'es':'🇪🇸','en':'🇬🇧','pt':'🇧🇷'}[r.lang] || '🌐';
  const fav = favSet.has(rid);
  const iaBadge = r.code_type==='html'
    ? '<span class="thumb-ia"><svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg> IA</span>'
    : '';
  // <a> real: alcanzable con teclado, abrible en pestaña nueva y rastreable.
  // El botón ⭐ va FUERA del enlace (un <button> dentro de un <a> es inválido).
  return `<div class="card-wrap">
    <a class="card" href="/resource/${rid}">
      <div class="card-thumb">
        <span class="thumb-fallback"><i data-lucide="${icon}" aria-hidden="true"></i></span>
        <img src="/thumbnails/og-${rid}.png" loading="lazy" alt="" onerror="this.remove()">
        ${iaBadge}
      </div>
      <div class="card-content">
        <div class="card-title">${esc(r.title)}</div>
        <div class="card-desc">${esc(r.description || '')}</div>
        <div class="card-footer">
          <div class="card-tags"><span class="badge-level ${levelKey}">${levelLabel}</span><span class="tag">${typeLabel}</span><span class="tag">${langFlag}</span></div>
          <div class="card-meta">
            <span><i data-lucide="eye" style="width:12px;height:12px" aria-hidden="true"></i> ${Number(r.view_count) || 0}</span>
            <span><i data-lucide="heart" style="width:12px;height:12px" aria-hidden="true"></i> ${Number(r.like_count) || 0}</span>
          </div>
        </div>
      </div>
    </a>
    <button class="fav-btn card-fav${fav?' is-fav':''}" type="button" title="${escAttr(T.save)}" aria-label="${escAttr(T.save)}" aria-pressed="${fav?'true':'false'}" onclick="toggleFavorite(${rid},this)"><i data-lucide="star" aria-hidden="true"></i></button>
  </div>`;
}

// ── Listeners ──
const $search = $('search');
$search.addEventListener('input', () => {
  syncClearBtn();
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => loadResources(), 300);
});
$search.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    // Enter busca YA, sin esperar el debounce; blur cierra el teclado en móvil.
    e.preventDefault();
    clearTimeout(debounceTimer);
    loadResources({push:true});
    $search.blur();
  } else if (e.key === 'Escape') {
    e.stopPropagation();   // que no llegue al handler global (salir de presentación)
    if ($search.value) { e.preventDefault(); clearSearch(); }
  }
});
$('search-clear').addEventListener('click', () => { clearSearch(); $search.focus(); });
['filter-lang','filter-level','sort'].forEach(id => $(id).addEventListener('change', () => {
  // Elegir el orden a mano lo congela: al seguir tecleando no saltará a relevancia.
  if (id === 'sort') sortExplicit = true;
  clearTimeout(debounceTimer);
  loadResources({push:true});
}));

// Píldoras de categoría (el contenedor existe siempre: se delega una sola vez)
$('categories').addEventListener('click', e => {
  const pill = e.target.closest('.cat-pill');
  if (!pill) return;
  currentCat = pill.dataset.catId || null;
  markActivePill();
  clearTimeout(debounceTimer);
  loadResources({push:true});
});

// Controles de los estados vacío/error y de los chips de filtros activos
function onControlClick(e){
  const sg = e.target.closest('[data-suggest]');
  if (sg) { searchFor(sg.dataset.suggest); return; }
  if (e.target.closest('[data-retry]')) { loadResources({push:false}); return; }
  const cl = e.target.closest('[data-clear]');
  if (!cl) return;
  const k = cl.dataset.clear;
  if (k === 'all') { clearFilters(); return; }
  if (k === 'search') { $('search').value = ''; syncClearBtn(); }
  else if (k === 'category') { currentCat = null; markActivePill(); }
  else if (k === 'lang')  $('filter-lang').value = '';
  else if (k === 'level') $('filter-level').value = '';
  clearTimeout(debounceTimer);
  loadResources({push:true});
}
$('grid').addEventListener('click', onControlClick);
$('active-filters').addEventListener('click', onControlClick);
$('more-wrap').addEventListener('click', e => {
  if (!e.target.closest('#more-btn')) return;
  page++;
  loadResources({append:true, push:false});
});

function applyFeaturedFavs(){
  document.querySelectorAll('.fav-corner').forEach(btn=>setFavBtn(btn,favSet.has(Number(btn.dataset.fid))));
}

// Deep-link: search, sort, category, lang y level se leen de la URL — así
// funcionan "Ver todos → /?sort=popular" y cualquier enlace compartido.
applyStateFromURL();
// El 404 enlaza a /?focus=search: con el foco puesto (y el teclado abierto en
// móvil) el usuario sigue buscando en vez de aterrizar en una portada estática.
(function(){
  const p = new URLSearchParams(location.search);
  if (!p.has('focus') && !p.has('search')) return;
  try {
    $search.focus({preventScroll:true});
    const n = $search.value.length;
    $search.setSelectionRange(n, n);
  } catch (err) {}
})();
// push:false en el arranque: no reescribimos la URL antes de que el usuario toque nada.
loadFavorites().finally(()=>{ applyFeaturedFavs(); loadResources({push:false}); });
</script>
</body>
</html>
