<?php
// ================================================================
// index.php — Portada de iarepo.com (+ health check en JSON)
//
// Navegador (Accept: text/html)          → la portada.
// API (Accept: application/json sin html) → JSON de salud. Lo consultan
//   monitores externos: no cambies su forma ({status, service, version,
//   database, time}).
//
// ── QUÉ HAY EN LA PORTADA (rediseño 2026-09) ──────────────────
//   1. Hero para los tres públicos (docentes, estudiantes, autodidactas):
//      posicionamiento, buscador con «Prueba:» y dos entradas, «Doy clase»
//      (→ #listos) y «Estoy aprendiendo» (→ #para-empezar).
//   2. Filtros ARRIBA, visibles en la primera pantalla del móvil: materia,
//      curso y edad, idioma del recurso y —solo al buscar— orden.
//   3. Dos secciones calculadas AQUÍ, deterministas y sin popularidad:
//      #listos («Listos para clase») y #para-empezar. Sustituyen a «Más
//      usados», que ordenaba por view_count, un contador CONGELADO desde
//      2026-08-06 (CLAUDE.md §6.3): la portada destacaba para siempre lo que
//      más se cargó antes de esa fecha.
//   4. El catálogo (#catalogo), que pinta el JS desde /api/resources.php.
//
// ── LO QUE NO SE PUEDE ROMPER ─────────────────────────────────
//   · Ninguna sección ordena por popularidad (ni view_count, congelado, ni
//     use_count/unique_views, que aún no tienen datos). Lo fija
//     tests/unit/landing_test.php.
//   · ?lang= es el IDIOMA DE LA INTERFAZ; el filtro de idioma del catálogo
//     en la URL de esta página es ?rlang= (a la API se le manda 'lang').
//   · Deep-links ?search ?category ?rlang ?level ?sort y /?focus=search (lo
//     usa el 404), atrás/adelante (pushState/popstate), «Cargar más», estado
//     vacío con sugerencias y estado de error con «Reintentar».
//   · Sin terceros: ni Google Fonts ni el script de Google Sign-In. «Entrar»
//     lleva a /auth/signin.php; la portada la abren menores y no tiene por
//     qué avisar a nadie de cada visita.
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/shared/page_errors.php';

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/i18n.php';
require_once __DIR__ . '/shared/ui.php';
lang(); // resuelve y guarda ES/EN antes de cualquier salida

// ── Health check en JSON ───────────────────────────────────────
// Antes que nada caro: un monitor no necesita sesión ni consultas.
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
    header('Content-Type: application/json; charset=utf-8');
    $status = ['status' => 'ok', 'service' => 'iarepo', 'version' => '1.0.0'];
    try {
        getResourcesDB()->query('SELECT 1');
        $status['database'] = 'connected';
    } catch (Throwable $e) {
        $status['database'] = 'error';
        $status['status'] = 'degraded';
    }
    $status['time'] = date('c');
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// h() local: las páginas HTML no cargan shared/helpers.php (CLAUDE.md §2.1).
if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

$sessionUser = getSessionUser();
$isStudent   = ($sessionUser['role'] ?? '') === 'student';

// ── Secciones de la portada ────────────────────────────────────
// Filtros comunes: lo que ve cualquiera (comunidad, activo, aprobado, enlace
// no roto) y solo Primaria y Secundaria. `level` es texto libre en la BD
// (shared/labels.php): se aceptan también las claves antiguas en español.
const IAREPO_HOME_WHERE = "r.is_active = 1 AND r.visibility = 'community'"
    . " AND r.moderation_status = 'approved'"
    . " AND (r.link_status IS NULL OR r.link_status != 'broken')"
    . " AND r.level IN ('primary', 'secondary', 'primaria', 'secundaria', 'eso')";
// Orden: aleatorio con la FECHA como semilla. Es el mismo para todos durante
// el día y cambia al siguiente: rota sin premiar a nadie y sin depender de
// contadores (view_count está congelado; use_count y unique_views aún no
// tienen datos, CLAUDE.md §8). No metas aquí ninguna métrica de popularidad.
const IAREPO_HOME_ORDER = 'RAND(TO_DAYS(CURDATE()))';
// «Para empezar»: títulos de introducción. Principio de palabra (como los
// sinónimos de shared/search.php): 'intro' casa «Intro», «Introducción» e
// «Introduction», pero no «Superintro». REGEXP ya es insensible a mayúsculas
// con la collation de la tabla; los acentos se cubren a mano (b[aá]sic).
const IAREPO_HOME_BASICS_RX = '(?<![\p{L}\p{N}])(intro|b[aá]sic|fundament)';

/**
 * Hasta 8 recursos para una sección de la portada, con sus etiquetas.
 * $excludeIds: los que ya salen en otra sección (no repetir en la misma página).
 */
function iarepo_home_pick(PDO $db, string $extraWhere = '', array $params = [], array $excludeIds = []): array
{
    $excludeIds = array_values(array_filter(array_map('intval', $excludeIds)));
    if ($excludeIds) {
        $extraWhere .= ' AND r.id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
        $params = array_merge($params, $excludeIds);
    }
    $st = $db->prepare('
        SELECT r.id, r.title, r.code_type, r.lang, r.level, r.topic_tag,
               r.source_name, r.source_url,
               IF(r.code_type = \'url\', r.code_content, NULL) AS link_url,
               c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
        FROM resources r
        LEFT JOIN categories c ON c.id = r.category_id
        WHERE ' . IAREPO_HOME_WHERE . $extraWhere . '
        ORDER BY ' . IAREPO_HOME_ORDER . '
        LIMIT 8
    ');
    $st->execute($params);
    return array_map('iarepo_with_labels', $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Una sección que no se puede calcular no rompe la portada: se omite. Pero
 * no en silencio (la regla del proyecto es que todo error se sepa): queda en
 * el log de PHP y en client_error_log, y api/health.php lo cuenta.
 */
function iarepo_home_degrade(string $what, Throwable $e): void
{
    $msg = "portada: sin «{$what}» — " . get_class($e) . ': ' . $e->getMessage();
    if (function_exists('iarepo_page_error_record'))
        iarepo_page_error_record('Warning', $msg, $e->getFile(), $e->getLine());
    else
        error_log($msg);
}

$catalogTotal = 0;
$basics = [];
$ready  = [];
try {
    $db = getResourcesDB();
    try {
        // El mismo universo que el listado anónimo de api/resources.php.
        $catalogTotal = (int) $db->query("SELECT COUNT(*) FROM resources r
            WHERE r.is_active = 1 AND r.visibility = 'community'
              AND (r.link_status IS NULL OR r.link_status != 'broken')")->fetchColumn();
    } catch (Throwable $e) {
        iarepo_home_degrade('total del catálogo', $e);
    }
    try {
        $basics = iarepo_home_pick($db, ' AND r.title REGEXP ?', [IAREPO_HOME_BASICS_RX]);
    } catch (Throwable $e) {
        iarepo_home_degrade('Para empezar', $e);
    }
    try {
        $ready = iarepo_home_pick($db, '', [], array_column($basics, 'id'));
    } catch (Throwable $e) {
        iarepo_home_degrade('Listos para clase', $e);
    }
} catch (Throwable $e) {
    iarepo_home_degrade('conexión a la BD', $e);
}

// ── Estado inicial de filtros y orden (deep-links) ─────────────
// Se pintan ya marcados para que la primera imagen coincida con lo que el JS
// va a pedir; el JS los vuelve a leer de la URL al arrancar.
$levelLabels = iarepo_level_options() + ['general' => iarepo_level_label('general')];
$rlangLabels = ['es' => t('Español'), 'en' => t('Inglés')];
$querySearch = trim((string) ($_GET['search'] ?? ''));
$qLevel      = (string) ($_GET['level'] ?? '');
$qLevel      = isset($levelLabels[$qLevel]) ? $qLevel : '';
$qRlang      = (string) ($_GET['rlang'] ?? '');
$qRlang      = isset($rlangLabels[$qRlang]) ? $qRlang : '';
$qCategory   = (int) ($_GET['category'] ?? 0);
$browsing    = $querySearch !== '' || $qLevel !== '' || $qRlang !== '' || $qCategory > 0;

// Orden. La portada solo OFRECE «Más relevantes» (con búsqueda) y «Más
// recientes»: ordenar por uso o por visitas sería ordenar por ceros o por un
// contador congelado. La regla la manda api/resources.php (bloque "── Sort ──"):
// un sort desconocido cuenta como AUSENTE. Aquí 'popular', 'views' y 'title'
// —válidos en la API, que sigue aceptándolos para enlaces viejos— cuentan
// también como ausentes: la portada pinta y pide su orden por defecto.
$sortOptions = ['relevance', 'recent'];
$sortParam   = (string) ($_GET['sort'] ?? '');
if (!in_array($sortParam, $sortOptions, true)) $sortParam = '';
if ($sortParam === 'relevance' && $querySearch === '') $sortParam = '';
$sortSelected = $sortParam !== '' ? $sortParam : ($querySearch !== '' ? 'relevance' : 'recent');
/** Marca la <option> del orden vigente (el JS vuelve a normalizarlo al arrancar). */
$sortSel = static fn(string $v): string => $v === $sortSelected ? ' selected' : '';
$pressed = static fn(bool $on): string => $on ? 'true' : 'false';

// ── SEO: una URL por idioma (la versión inglesa es ?lang=en) ───
$siteUrl   = 'https://iarepo.com/';
$canonical = lang() === 'en' ? $siteUrl . '?lang=en' : $siteUrl;
$pageTitle = t('iarepo — Ciencias y matemáticas que se entienden tocándolas');
$pageDesc  = t('Simulaciones gratuitas de PhET, NASA, GeoGebra y otras fuentes, clasificadas por curso y con su autor original citado. Proyéctalas en clase, pásaselas a tus alumnos con un enlace o úsalas por tu cuenta: sin instalar y sin registrarte.');
$jsonLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'WebSite',
    'name'        => 'iarepo',
    'url'         => $siteUrl,
    'inLanguage'  => lang(),
    'description' => $pageDesc,
    'audience'    => [
        ['@type' => 'EducationalAudience', 'educationalRole' => 'teacher'],
        ['@type' => 'EducationalAudience', 'educationalRole' => 'student'],
    ],
    'potentialAction' => [
        '@type'       => 'SearchAction',
        'target'      => $siteUrl . '?search={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
];

/** Filas compactas de una sección: portada pequeña, título, fuente · curso y «Abrir». */
function iarepo_home_rows(array $rows): void
{
    foreach ($rows as $r) {
        // «Fuente · Curso · Idioma», sin las edades: la fila es estrecha (las
        // edades están en la tarjeta del catálogo y en la ficha). El idioma
        // SÍ: la mitad de lo que se ofrece a quien empieza está en inglés, y
        // la fila no enseña el sello «En inglés» de la portada (.ia-row lo
        // oculta) [revisión 2026-09]. El español, en verde, como en la tarjeta.
        $bits = array_filter([$r['source_label'] ?? $r['category_label'], iarepo_level_label($r['level'] ?? null, false)]);
        $meta = h(implode(' · ', $bits));
        if ((string) ($r['lang_label'] ?? '') !== '')
            $meta .= ($bits ? ' · ' : '') . '<span class="home-row-lang' . (($r['lang'] ?? '') === 'es' ? ' ia-lang-es' : '') . '">' . h((string) $r['lang_label']) . '</span>';
        ?>
        <li><a class="ia-row" href="/resource/<?= (int) $r['id'] ?>">
          <?= iarepo_cover($r) ?>
          <div class="ia-row-body">
            <div class="ia-row-title"><?= h((string) $r['title']) ?></div>
            <div class="ia-row-meta"><?= $meta ?></div>
          </div>
          <span class="ia-btn ia-btn-secondary ia-btn-sm home-open" aria-hidden="true"><?= h(t('Abrir')) ?></span>
        </a></li>
<?php
    }
}
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?></title>
<meta name="description" content="<?= h($pageDesc) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">
<link rel="alternate" hreflang="es" href="<?= h($siteUrl) ?>">
<link rel="alternate" hreflang="en" href="<?= h($siteUrl . '?lang=en') ?>">
<link rel="alternate" hreflang="x-default" href="<?= h($siteUrl) ?>">
<meta property="og:title" content="<?= h($pageTitle) ?>">
<meta property="og:description" content="<?= h($pageDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:site_name" content="iarepo">
<meta property="og:locale" content="<?= lang() === 'en' ? 'en_GB' : 'es_ES' ?>">
<meta property="og:image" content="https://iarepo.com/assets/img/og-default.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($pageTitle) ?>">
<meta name="twitter:description" content="<?= h($pageDesc) ?>">
<meta name="twitter:image" content="https://iarepo.com/assets/img/og-default.png">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="<?= h(iarepo_asset('/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<?= iarepo_pwa_script() ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<style>
/* ── Portada: SOLO lo propio de esta página; lo común vive en app.css ── */

/* El encabezado fijo taparía el título al saltar a #listos / #catalogo. */
.home-anchor { scroll-margin-top: 76px; }

/* Hero: texto y buscador a la izquierda; las dos entradas a la derecha. */
.home-hero { padding: 18px 0 16px; }
.home-hero-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
.home-hero h1 { margin: 0 0 10px; text-wrap: balance; }
.home-lead { color: var(--ia-ink-2); font-size: 1.05rem; max-width: 62ch; margin: 0 0 16px; text-wrap: pretty; }
.home-search { max-width: 640px; }
.home-search input::-webkit-search-cancel-button { -webkit-appearance: none; appearance: none; }  /* ya hay botón de limpiar */
.home-try { display: flex; align-items: center; gap: 6px; margin-top: 10px; overflow-x: auto; scrollbar-width: none; font-size: .875rem; color: var(--ia-ink-3); padding: 2px; }
.home-try::-webkit-scrollbar { display: none; }
.home-try .ia-chip { min-height: 34px; padding: 4px 12px; font-size: .875rem; border-width: 1px; }
.home-who { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.home-who > :only-child { grid-column: 1 / -1; }
.home-who a { display: grid; grid-template-columns: 36px minmax(0, 1fr); gap: 2px 10px; align-content: start; padding: 12px;
  border: 1px solid var(--ia-line); border-radius: var(--ia-radius); background: var(--ia-surface); color: var(--ia-ink); text-decoration: none; }
.home-who a:hover { border-color: var(--ia-accent); color: var(--ia-ink); }
.home-who-icon { grid-row: span 2; width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: var(--ia-accent-soft); color: var(--ia-accent); }
.home-who-icon svg { width: 20px; height: 20px; }
.home-who strong { font-size: 1rem; line-height: 1.25; overflow-wrap: anywhere; hyphens: auto; }   /* «aprendiendo» no cabía a 320 px y ensanchaba la página */
.home-who-text { font-size: .875rem; line-height: 1.35; color: var(--ia-ink-3); }
.home-total { margin: 10px 0 0; }
@media (max-width: 559px) {
  .home-hero h1 { font-size: 1.8rem; }
  .home-lead { font-size: .98rem; margin-bottom: 12px; }
  .home-lead-more { display: none; }          /* lo cuentan ya las dos entradas */
  /* En el móvil, las dos entradas se quedan en una línea (icono + título):
     así los filtros entran en la primera pantalla. */
  .home-who a { grid-template-columns: 28px minmax(0, 1fr); align-items: center; align-content: center; padding: 8px 10px; }
  .home-who-icon { grid-row: auto; width: 28px; height: 28px; border-radius: 8px; }
  .home-who-icon svg { width: 17px; height: 17px; }
  .home-who strong { font-size: .95rem; }
  .home-who-text { display: none; }
}
@media (min-width: 960px) {
  .home-hero { padding: 40px 0 28px; }
  .home-hero-grid { grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); align-items: end; gap: 48px; }
  .home-who { grid-template-columns: 1fr; }
}

/* Filtros: una fila por criterio; en móvil cada fila se desliza en horizontal. */
.home-filters { border-block: 1px solid var(--ia-line); background: var(--ia-surface); padding: 10px 0; }
/* minmax(0, 1fr): sin él, la columna de la rejilla crece hasta el ancho de
   todos los chips en fila y la página entera se desborda en el móvil. */
.home-filters .ia-filters { grid-template-columns: minmax(0, 1fr); }
.home-filters .ia-filter-row { flex-wrap: nowrap; min-width: 0; }
.home-filters .ia-filter-label { flex: none; width: 6.4em; min-width: 0; line-height: 1.2; }
.home-chips { flex: 1; min-width: 0; min-height: 44px; flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; padding: 2px; align-items: center; }
.home-chips::-webkit-scrollbar { display: none; }
@media (max-width: 899px) {
  /* La fila se desliza hasta el borde de la pantalla: el chip cortado avisa de que hay más. */
  .home-chips { margin-right: calc(-1 * var(--ia-gutter)); padding-right: var(--ia-gutter); }
}
@media (min-width: 900px) { .home-chips { flex-wrap: wrap; overflow: visible; } }

/* Secciones «Listos para clase» y «Para empezar»: filas compactas. */
.home-picks { padding: 24px 0 4px; }
.home-picks .ia-section-head p { margin: 2px 0 0; }
.home-rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.home-rows .ia-row { height: 100%; color: var(--ia-ink); text-decoration: none; transition: border-color .15s; }
.home-rows .ia-row:hover { color: var(--ia-ink); border-color: var(--ia-line-strong); }
.home-rows .ia-row .ia-cover { width: 56px; }
.home-rows .ia-row-title { white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.home-open { flex: none; pointer-events: none; }
@media (max-width: 719px) {
  /* Tres filas que se deslizan en horizontal: 8 recursos en ~250 px de alto. */
  .home-rows { grid-auto-flow: column; grid-template-rows: repeat(3, auto); grid-auto-columns: 86%;
    overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none;
    margin: 0 calc(-1 * var(--ia-gutter)); padding: 2px var(--ia-gutter) 6px; scroll-padding: 0 var(--ia-gutter); }
  .home-rows::-webkit-scrollbar { display: none; }
  .home-rows > li { scroll-snap-align: start; min-width: 0; }
}
@media (min-width: 720px) { .home-rows { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

/* Catálogo */
.home-catalog-head { align-items: baseline; }
.home-count { font-size: .9rem; color: var(--ia-ink-3); }
.home-active { margin: 0 0 14px; }
.home-active:empty { display: none; }
.home-active .ia-chip { min-height: 36px; font-size: .85rem; }
#grid > .home-state { grid-column: 1 / -1; }
.home-state h3 { margin-bottom: 8px; }
.home-state p { max-width: 460px; margin: 0 auto 14px; }
.home-state .ia-chips { justify-content: center; margin-bottom: 16px; }
.home-spinner { width: 32px; height: 32px; margin: 0 auto 12px; border: 3px solid var(--ia-line); border-top-color: var(--ia-accent);
  border-radius: 50%; animation: home-spin .8s linear infinite; }
@keyframes home-spin { to { transform: rotate(360deg); } }
.home-more { text-align: center; padding-top: 20px; }
.home-more .ia-count { color: var(--ia-ink-3); font-weight: 500; }
#grid .ia-card-meta { display: block; }       /* texto corrido: al partir línea no queda un «·» colgando */
.home-meta-item { white-space: nowrap; }
#categories.home-counts-off .ia-count { display: none; }   /* ver renderActiveFilters() */
.ia-row-meta .ia-lang-es { color: var(--ia-ok); font-weight: 600; }   /* «En español» en las filas, como en la tarjeta */
@media (max-width: 379px) { .home-meta-item { white-space: normal; } }   /* 360 px en inglés: una etiqueta larga ensanchaba la página */
#grid .ia-card-body { min-width: 0; }            /* en la tarjeta-fila del móvil (flex en fila) el texto no empuja la tarjeta más allá de la pantalla */
/* La fuente: en escritorio la enseña el sello de la portada (aria-hidden), así
   que aquí queda solo para lectores de pantalla; en la tarjeta-fila del móvil
   la portada no la lleva y se ve al final de la línea. */
.home-rows-only { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
/* Estrella de Guardados sobre la portada de la tarjeta */
.fav-btn { background: var(--ia-surface); border-color: var(--ia-line); color: var(--ia-ink-3); box-shadow: var(--ia-shadow); }
.fav-btn:hover { color: var(--ia-warn-ink); background: var(--ia-surface); }
.fav-btn.is-fav { color: var(--ia-warn-ink); }
.fav-btn.is-fav svg { fill: currentColor; }
@media (max-width: 559px) {
  #grid .ia-card-title { padding-right: 40px; }   /* en fila, la estrella queda sobre el texto */
  #grid .fav-btn { width: 38px; min-height: 38px; top: 8px; right: 8px; }
  .home-rows-only { position: static; width: auto; height: auto; margin: 0; overflow: visible; clip: auto; white-space: normal; }
  .home-rows-hide { display: none; }
}

/* Modo búsqueda: al buscar o filtrar, el hero se recoge y los resultados suben. */
body.searching .home-hero { padding-bottom: 12px; }
body.searching .home-hero .ia-eyebrow,
body.searching .home-lead,
body.searching .home-try,
body.searching .home-aside,
body.searching .home-picks { display: none; }
body.searching .home-hero-grid { grid-template-columns: minmax(0, 1fr); }
/* El h1 no se quita con display:none: la página no puede quedarse sin
   encabezado accesible al entrar por deep-link (/?search=…). */
body.searching .home-hero h1 { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.home-when-browsing, body.searching .home-when-idle { display: none; }
body.searching .home-when-browsing { display: inline; }
</style>
<?php require_once __DIR__ . '/shared/error_tracker.php'; ?>
</head>
<body class="ia-page<?= $browsing ? ' searching' : '' ?>">
<?php iarepo_header($sessionUser, 'explore'); ?>

<main id="main">
<section class="home-hero">
  <div class="ia-container home-hero-grid">
    <div>
      <p class="ia-eyebrow"><?= h(t('Gratis · sin instalar · sin registro')) ?></p>
      <h1><?= h(t('Ciencias y matemáticas que se entienden tocándolas')) ?></h1>
      <p class="home-lead"><?= h(t('Simulaciones gratuitas de PhET, NASA, GeoGebra y otras fuentes, clasificadas por curso y con su autor original citado.')) ?>
        <span class="home-lead-more"><?= h(t('Proyéctalas en clase, pásaselas a tus alumnos con un enlace o úsalas por tu cuenta: sin instalar y sin registrarte.')) ?></span></p>
      <form class="ia-search home-search" id="search-form" role="search" action="/" method="get">
        <i data-lucide="search" aria-hidden="true"></i>
        <label for="search" class="ia-sr-only"><?= h(t('Buscar recursos')) ?></label>
        <input type="search" id="search" name="search" enterkeyhint="search" autocomplete="off" autocapitalize="off"
               spellcheck="false" aria-describedby="result-count" value="<?= h($querySearch) ?>"
               placeholder="<?= h(t('Tema de la clase o lo que quieres entender: fuerzas, fracciones, el átomo…')) ?>">
        <button type="button" id="search-clear" class="ia-btn ia-btn-ghost ia-btn-icon"<?= $querySearch === '' ? ' hidden' : '' ?>
                title="<?= h(t('Limpiar búsqueda')) ?>" aria-label="<?= h(t('Limpiar búsqueda')) ?>"><i data-lucide="x" aria-hidden="true"></i></button>
        <button type="submit" class="ia-btn ia-btn-primary ia-hide-xs"><?= h(t('Buscar')) ?></button>
      </form>
      <div class="home-try">
        <span><?= h(t('Prueba:')) ?></span>
        <?php foreach (array_filter(array_map('trim', explode(',', t('ondas,fracciones,circuitos,álgebra,sistema solar')))) as $sg): ?>
        <button type="button" class="ia-chip" data-suggest="<?= h($sg) ?>"><?= h($sg) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="home-aside">
      <nav class="home-who" aria-label="<?= h(t('Por dónde empezar')) ?>">
        <?php if (!$isStudent): ?>
        <a href="<?= $ready ? '#listos' : '#catalogo' ?>">
          <span class="home-who-icon"><i data-lucide="presentation" aria-hidden="true"></i></span>
          <strong><?= h(t('Doy clase')) ?></strong>
          <span class="home-who-text"><?= h(t('Proyéctalo, mándalo con un QR o insértalo en tu aula virtual')) ?></span>
        </a>
        <?php endif; ?>
        <a href="<?= $basics ? '#para-empezar' : '#catalogo' ?>">
          <span class="home-who-icon"><i data-lucide="sprout" aria-hidden="true"></i></span>
          <strong><?= h(t('Estoy aprendiendo')) ?></strong>
          <span class="home-who-text"><?= h(t('Empieza por lo básico y avanza a tu ritmo')) ?></span>
        </a>
      </nav>
      <?php if ($catalogTotal > 0): ?>
      <p class="home-total ia-muted ia-small"><?= h(str_replace('%s', (lang() === 'en' ? number_format($catalogTotal) : number_format($catalogTotal, 0, ',', '.')), t('%s recursos en el catálogo, todos gratuitos'))) ?></p>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="home-filters" aria-label="<?= h(t('Filtrar el catálogo')) ?>">
  <div class="ia-container ia-filters">
    <div class="ia-filter-row">
      <span class="ia-filter-label" id="lbl-cat"><?= h(t('Materia')) ?></span>
      <div id="categories" class="ia-chips home-chips" role="group" aria-labelledby="lbl-cat"></div>
    </div>
    <div class="ia-filter-row">
      <span class="ia-filter-label" id="lbl-level"><?= h(t('Curso y edad')) ?></span>
      <div id="levels" class="ia-chips home-chips" role="group" aria-labelledby="lbl-level">
        <button type="button" class="ia-chip" data-level="" aria-pressed="<?= $pressed($qLevel === '') ?>"><?= h(t('Todos')) ?></button>
        <?php foreach (iarepo_level_options() as $k => $label): ?>
        <button type="button" class="ia-chip" data-level="<?= h($k) ?>" aria-pressed="<?= $pressed($qLevel === $k) ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="ia-filter-row">
      <span class="ia-filter-label" id="lbl-rlang"><?= h(t('Idioma del recurso')) ?></span>
      <div id="rlangs" class="ia-chips home-chips" role="group" aria-labelledby="lbl-rlang">
        <button type="button" class="ia-chip" data-rlang="" aria-pressed="<?= $pressed($qRlang === '') ?>"><?= h(t('Todos')) ?></button>
        <?php foreach ($rlangLabels as $k => $label): ?>
        <button type="button" class="ia-chip" data-rlang="<?= h($k) ?>" aria-pressed="<?= $pressed($qRlang === $k) ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- Orden: solo tiene sentido con texto que puntuar. syncSortOptions()
         enseña la fila al buscar y la retira al vaciar la búsqueda. -->
    <div class="ia-filter-row" id="sort-row"<?= $querySearch === '' ? ' hidden' : '' ?>>
      <label class="ia-filter-label" for="sort"><?= h(t('Orden')) ?></label>
      <select class="ia-select" id="sort">
        <option value="relevance" id="sort-relevance"<?= $querySearch !== '' ? '' : ' hidden disabled' ?><?= $sortSel('relevance') ?>><?= h(t('Más relevantes')) ?></option>
        <option value="recent"<?= $sortSel('recent') ?>><?= h(t('Más recientes')) ?></option>
      </select>
    </div>
  </div>
</section>

<?php if ($ready): ?>
<section class="home-picks home-anchor" id="listos" aria-labelledby="listos-title">
  <div class="ia-container">
    <div class="ia-section-head">
      <div>
        <h2 id="listos-title"><?= h(t('Listos para clase · Primaria y Secundaria')) ?></h2>
        <p class="ia-muted ia-small"><?= h(t('Se abren en el navegador, sin instalar nada. La selección cambia cada día.')) ?></p>
      </div>
    </div>
    <ul class="home-rows"><?php iarepo_home_rows($ready); ?></ul>
  </div>
</section>
<?php endif; ?>

<?php if ($basics): ?>
<section class="home-picks home-anchor" id="para-empezar" aria-labelledby="para-empezar-title">
  <div class="ia-container">
    <div class="ia-section-head">
      <div>
        <h2 id="para-empezar-title"><?= h(t('Para empezar')) ?></h2>
        <p class="ia-muted ia-small"><?= h(t('Introducciones y fundamentos: lo básico de cada tema, para ir a tu ritmo.')) ?></p>
      </div>
    </div>
    <ul class="home-rows"><?php iarepo_home_rows($basics); ?></ul>
  </div>
</section>
<?php endif; ?>

<section class="ia-section home-anchor" id="catalogo" aria-labelledby="catalogo-title">
  <div class="ia-container">
    <div class="ia-section-head home-catalog-head">
      <h2 id="catalogo-title"><span class="home-when-idle"><?= h(t('Todo el catálogo')) ?></span><span class="home-when-browsing"><?= h(t('Resultados')) ?></span></h2>
      <span class="home-count" id="result-count" role="status" aria-live="polite" aria-atomic="true"></span>
    </div>
    <div id="active-filters" class="ia-chips home-active" aria-label="<?= h(t('Filtros activos')) ?>"></div>
    <div id="grid" class="ia-grid ia-grid-rows-mobile">
      <div class="home-state ia-empty"><div class="home-spinner"></div><?= h(t('Cargando recursos...')) ?></div>
    </div>
    <div id="more-wrap" class="home-more"></div>
  </div>
</section>
</main>

<?php iarepo_footer($sessionUser); ?>
<?= iarepo_body_assets() ?>
<script>
// Textos para el contenido dinámico: todos pasan por t() (CLAUDE.md §2.3).
const T = {
  noResults: <?= json_encode(t('No se encontraron recursos')) ?>,
  connError: <?= json_encode(t('Error de conexión')) ?>,
  loadError: <?= json_encode(t('Error al cargar recursos')) ?>,
  resource: <?= json_encode(t('recurso')) ?>,
  resources: <?= json_encode(t('recursos')) ?>,
  save: <?= json_encode(t('Guardar (solo tú lo ves)')) ?>,
  favSaved: <?= json_encode(t('Guardado. Lo tienes en «Guardados» y solo tú lo ves.')) ?>,
  favRemoved: <?= json_encode(t('Quitado de Guardados')) ?>,
  loginToSave: <?= json_encode(t('Entra para guardarlo: solo tú verás tus guardados')) ?>,
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
  fLang: <?= json_encode(t('Idioma del recurso')) ?>,
  fLevel: <?= json_encode(t('Curso')) ?>,
  fCategory: <?= json_encode(t('Materia')) ?>,
  removeFilter: <?= json_encode(t('Quitar filtro: %s')) ?>,
  allCats: <?= json_encode(t('Todas')) ?>,
  suggestions: <?= json_encode(t('Prueba con:')) ?>,
  // Lista de sugerencias del estado vacío (y del «Prueba:» del hero): términos
  // VERIFICADOS contra el catálogo real (ES 9/6/5/10/7 · EN 19/9/2/14/3
  // resultados). Se separan por coma.
  suggestList: <?= json_encode(t('ondas,fracciones,circuitos,álgebra,sistema solar')) ?>,
};
// Etiquetas de los filtros (las mismas que pinta el servidor en los chips).
const LEVEL_LABELS = <?= json_encode($levelLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const RLANG_LABELS = <?= json_encode($rlangLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const has = (o, k) => Object.prototype.hasOwnProperty.call(o, k);   // '__proto__' o 'constructor' en la URL no son un filtro

// ── Guardados (⭐ favorito rápido, privado) ──
// NO se unifica con las listas (CLAUDE.md §6.1): es el guardado de un clic.
const FAV_AUTH = <?= $sessionUser ? 'true' : 'false' ?>;
let favSet = new Set();
function setFavBtn(btn, on){ btn.classList.toggle('is-fav', !!on); btn.setAttribute('aria-pressed', on ? 'true' : 'false'); }
function applyFavs(){ document.querySelectorAll('.fav-btn[data-id]').forEach(b => setFavBtn(b, favSet.has(Number(b.dataset.id)))); }
async function loadFavorites(){
  if (!FAV_AUTH) return;
  try {
    const res = await fetch('/api/favorites.php');
    const data = await res.json();
    if (data.ok) favSet = new Set((data.favorite_ids || []).map(Number));
  } catch (e) { /* sin guardados no se rompe nada: las estrellas salen vacías */ }
}
async function toggleFavorite(id, btn){
  id = Number(id);
  if (!FAV_AUTH) { startSaveFlow(id); return; }
  btn.disabled = true;
  try {
    const res = await fetch(`/api/favorites.php?id=${id}`, {method: 'POST'});
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    data.favorited ? favSet.add(id) : favSet.delete(id);
    setFavBtn(btn, data.favorited);
    IA.toast(data.favorited ? T.favSaved : T.favRemoved);
  } catch (e) { IA.toast(e.message || T.connError); }
  finally { btn.disabled = false; }
}
// Invitado: lleva la intención (?save + return_url) a la pantalla de registro;
// tras autenticarse se aplica el favorito y se vuelve aquí.
function startSaveFlow(id){
  // localStorage sobrevive el redirect de Google (el query/cookie no): aquí
  // va la intención + a dónde volver, y pwa.js la aplica tras el login.
  try { localStorage.setItem('iarepo_pending_fav', JSON.stringify({id: id, ret: location.pathname + location.search})); } catch (e) {}
  IA.toast(T.loginToSave);
  const ret = encodeURIComponent(location.pathname + location.search);
  location.href = `/auth/signin.php?save=${id}&return_url=${ret}`;
}

// ── API ──
const API = '/api/resources.php';
const $ = id => document.getElementById(id);
let currentCat = null;    // id de categoría (texto de la URL: nunca va a un selector CSS)
let currentLevel = '';    // clave de LEVEL_LABELS o ''
let currentRlang = '';    // 'es' | 'en' | '' — idioma del RECURSO, no de la interfaz
let debounceTimer = null;
let reqSeq = 0;           // token de secuencia: descarta respuestas tardías
let inflight = null;      // AbortController de la petición en vuelo
let page = 1;             // página actual ("Cargar más")
let shown = 0;            // tarjetas pintadas ahora mismo
let lastTotal = 0;        // total devuelto para la consulta actual
let sortExplicit = false; // ¿el orden lo eligió el usuario (o un deep-link ?sort=)?

const SUBJ_RE = /^s-[a-z-]{2,30}$/;
// String.replace con reemplazo de texto interpreta $&, $` y $': siempre función.
function fill(tpl, token, value){ return String(tpl).replace(token, () => value); }

// ¿El usuario está buscando/filtrando? (entonces se recoge la portada)
function hasQuery(){ return $('search').value.trim() !== ''; }
function hasFilters(){ return !!(currentCat || currentRlang || currentLevel); }
function isBrowsing(){ return hasQuery() || hasFilters() || $('sort').value !== 'recent'; }
function updateBrowseMode(){ document.body.classList.toggle('searching', isBrowsing()); }

// El orden por relevancia sólo tiene sentido con términos que puntuar: la opción
// (y la fila «Orden») aparece al buscar y pasa a ser el defecto —que es justo lo
// que hace la API si no le mandamos 'sort'—, y se retira al vaciar la búsqueda
// devolviendo el select a 'recent'. Una elección deliberada —del usuario o de un
// ?sort= en la URL— se respeta y NO se pisa al seguir tecleando.
function syncSortOptions(){
  const s = $('sort'), opt = $('sort-relevance'), on = hasQuery();
  opt.hidden = !on; opt.disabled = !on;
  $('sort-row').hidden = !on;
  if (on) { if (!sortExplicit) s.value = 'relevance'; }
  else if (s.value === 'relevance') { s.value = 'recent'; sortExplicit = false; }
}

// forUrl: en la URL de la página el filtro de idioma del catálogo se llama
// 'rlang', porque '?lang=' ya es el IDIOMA DE LA INTERFAZ (shared/i18n.php lo
// lee y lo guarda en una cookie de un año). Con el mismo nombre, pulsar «EN»
// filtraba el catálogo a recursos en inglés, y compartir un enlace filtrado
// por «Español» cambiaba el idioma de la web de quien lo abría. A la API se
// le sigue mandando 'lang', que allí solo significa filtro.
function buildParams(forUrl){
  const p = new URLSearchParams();
  const q = $('search').value.trim();
  if (q) p.set('search', q);
  if (currentCat) p.set('category', currentCat);
  if (currentRlang) p.set(forUrl ? 'rlang' : 'lang', currentRlang);
  if (currentLevel) p.set('level', currentLevel);
  // 'recent' YA NO es el defecto universal: con búsqueda, el defecto de la API
  // es 'relevance'. Mandamos 'sort' sólo cuando difiere de ese defecto, así la
  // URL sigue siendo corta y '?search=ondas' significa lo mismo aquí y allí.
  const so = $('sort').value, dflt = q ? 'relevance' : 'recent';
  if (so && so !== dflt) p.set('sort', so);
  return p;
}

// ── Estado ↔ URL: la búsqueda se puede compartir, marcar y deshacer con "atrás" ──
function syncURL(push){
  const qs = buildParams(true).toString();
  const url = qs ? location.pathname + '?' + qs : location.pathname;
  syncLangSwitch(qs);
  if (url === location.pathname + location.search) return;
  if (push) history.pushState(null, '', url); else history.replaceState(null, '', url);
}
// Cambiar de idioma no debe tirar la búsqueda que tienes delante: el enlace
// ES/EN de la cabecera arrastra el estado actual (que no incluye ningún '?lang=').
function syncLangSwitch(qs){
  const a = $('lang-switch');
  if (!a) return;
  const base = a.getAttribute('href').split('&')[0];
  a.setAttribute('href', qs ? base + '&' + qs : base);
}
function applyStateFromURL(){
  const p = new URLSearchParams(location.search);
  $('search').value = p.get('search') || '';
  // Un valor que no existe entre las <option> ('popular', 'views', 'title' de un
  // enlace viejo) deja el select en '': lo devolvemos a su defecto. Un ?sort=
  // válido cuenta como elección explícita y bloquea el salto automático a
  // relevancia (deep-link y botón atrás mandan sobre él).
  $('sort').value = p.get('sort') || '';
  sortExplicit = $('sort').value !== '';
  if (!sortExplicit) $('sort').value = 'recent';
  const rl = p.get('rlang') || '', lv = p.get('level') || '';
  currentRlang = has(RLANG_LABELS, rl) ? rl : '';
  currentLevel = has(LEVEL_LABELS, lv) ? lv : '';
  currentCat = p.get('category') || null;
  syncSortOptions();
  syncClearBtn();
  markChips();
  syncLangSwitch(buildParams(true).toString());
}
window.addEventListener('popstate', () => { applyStateFromURL(); loadResources({push:false}); });

function showState(html){ $('grid').innerHTML = html; IA.icons(); }
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
  // en acciones deliberadas (Enter, select, chip), pushState.
  if (!opt.append && opt.push !== false) syncURL(opt.push === true);

  const p = buildParams();
  p.set('limit', '48');   // múltiplo de 2, 3 y 4 columnas: la última fila no queda coja
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
  else showState('<div class="home-state ia-empty"><div class="home-spinner"></div>' + IA.esc(T.searching) + '</div>');

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
  if (append) { page = Math.max(1, page - 1); renderMore(); IA.toast(msg); return; }
  lastTotal = 0; shown = 0;
  clearCount();
  $('more-wrap').innerHTML = '';
  showState('<div class="home-state ia-empty">' +
    '<p aria-hidden="true"><i data-lucide="triangle-alert"></i></p>' +
    '<h3>' + IA.esc(msg) + '</h3>' +
    '<button class="ia-btn ia-btn-secondary" type="button" data-retry="1">' + IA.esc(T.retry) + '</button>' +
  '</div>');
}

function renderResults(data, append) {
  const grid = $('grid');
  lastTotal = Number(data.total) || 0;
  if (data.categories && !document.querySelector('#categories [data-cat-id]')) renderCategories(data.categories);
  const list = Array.isArray(data.resources) ? data.resources : [];
  if (!append && list.length === 0) {
    shown = 0; setCount(); showState(emptyHTML()); renderMore(); renderActiveFilters();
    return;
  }
  const html = list.map(renderCard).join('');
  if (append) grid.insertAdjacentHTML('beforeend', html); else grid.innerHTML = html;
  shown += list.length;
  setCount(); renderMore(); renderActiveFilters();
  IA.icons();
}

// "546 recursos" y 48 tarjetas era mentira: o se pintan todos, o hay cómo pedir más.
function renderMore(){
  $('more-wrap').innerHTML = (shown > 0 && shown < lastTotal)
    ? '<button class="ia-btn ia-btn-secondary" type="button" id="more-btn">' + IA.esc(T.loadMore) + ' <span class="ia-count">(' + (lastTotal - shown) + ')</span></button>'
    : '';
}
function setMoreBusy(on){
  const b = $('more-btn');
  if (!b) return;
  if (on) { b.disabled = true; b.textContent = T.searching; }
  else if (b.disabled) renderMore();
}

// Estado vacío accionable: qué se buscó, qué está filtrando y por dónde salir.
function suggestChips(){
  return String(T.suggestList).split(',').map(s => s.trim()).filter(Boolean)
    .map(s => '<button class="ia-chip" type="button" data-suggest="' + IA.esc(s) + '">' + IA.esc(s) + '</button>')
    .join('');
}
function emptyHTML(){
  const q = $('search').value.trim();
  const title = q ? fill(T.noResultsFor, '%s', IA.esc(q)) : IA.esc(T.noResults);
  const hint  = hasFilters() ? fill(T.noResultsFilters, '%s', IA.esc(filterSummary())) : IA.esc(T.noResultsHint);
  return '<div class="home-state ia-empty">' +
    '<h3>' + title + '</h3>' +
    '<p>' + hint + '</p>' +
    '<p class="ia-small">' + IA.esc(T.suggestions) + '</p>' +
    '<div class="ia-chips">' + suggestChips() + '</div>' +
    ((q || hasFilters()) ? '<button class="ia-btn ia-btn-secondary" type="button" data-clear="all">' + IA.esc(T.clearFilters) + '</button>' : '') +
  '</div>';
}

// currentCat viene de la URL: nunca lo metemos en un selector CSS (querySelector
// lanzaría con un valor arbitrario). Comparamos leyendo el dataset.
function pillFor(id){
  let found = null;
  document.querySelectorAll('#categories [data-cat-id]').forEach(p => {
    if ((p.dataset.catId || '') === String(id)) found = p;
  });
  return found;
}
function markChips(){
  const mark = (sel, key, val) => document.querySelectorAll(sel).forEach(b =>
    b.setAttribute('aria-pressed', (b.dataset[key] || '') === (val || '') ? 'true' : 'false'));
  mark('#categories [data-cat-id]', 'catId', currentCat);
  mark('#levels [data-level]', 'level', currentLevel);
  mark('#rlangs [data-rlang]', 'rlang', currentRlang);
}
function activeFilterList(){
  const out = [];
  if (currentCat) {
    const pill = pillFor(currentCat);
    out.push({k:'category', label: T.fCategory + (pill ? ': ' + pill.dataset.label : '')});
  }
  if (currentRlang) out.push({k:'lang', label: T.fLang + ': ' + RLANG_LABELS[currentRlang]});
  if (currentLevel) out.push({k:'level', label: T.fLevel + ': ' + LEVEL_LABELS[currentLevel]});
  return out;
}
function filterSummary(){ return activeFilterList().map(f => f.label).join(' · '); }
function renderActiveFilters(){
  const items = activeFilterList();
  const q = $('search').value.trim();
  if (q) items.unshift({k:'search', label: T.fSearch + ': ' + q});
  let html = items.map(f =>
    '<button class="ia-chip" type="button" data-clear="' + IA.esc(f.k) + '" aria-label="' + IA.esc(fill(T.removeFilter, '%s', f.label)) + '">' +
    IA.esc(f.label) + ' <span aria-hidden="true">✕</span></button>').join('');
  if (items.length > 1) html += '<button class="ia-chip" type="button" data-clear="all">' + IA.esc(T.clearFilters) + '</button>';
  $('active-filters').innerHTML = html;
  // Los recuentos de los chips de materia son del catálogo ENTERO: con una
  // búsqueda, un curso o un idioma activos mentían («Física 207» con 0
  // resultados). Entonces se ocultan [revisión 2026-09].
  $('categories').classList.toggle('home-counts-off', !!(q || currentLevel || currentRlang));
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
  $('sort').value = 'recent';
  sortExplicit = false;
  currentCat = null; currentLevel = ''; currentRlang = '';
  markChips(); syncClearBtn();
  clearTimeout(debounceTimer);
  loadResources({push:true});
  $('search').focus();
}

// Chips de materia: etiqueta y color ya calculados por la API (shared/labels.php).
function renderCategories(cats) {
  let html = '<button class="ia-chip" type="button" data-cat-id="" data-label="' + IA.esc(T.allCats) + '" aria-pressed="false">' + IA.esc(T.allCats) + '</button>';
  cats.filter(c => parseInt(c.resource_count, 10) > 0).forEach(c => {
    const subj = SUBJ_RE.test(c.subject_class || '') ? c.subject_class : 's-general';
    const label = c.label || c.name || '';
    html += '<button class="ia-chip ' + subj + '" type="button" data-cat-id="' + IA.esc(c.id) + '" data-label="' + IA.esc(label) + '" aria-pressed="false">' +
      '<span class="ia-dot" aria-hidden="true"></span>' + IA.esc(label) +
      ' <span class="ia-count">' + IA.esc(c.resource_count) + '</span></button>';
  });
  $('categories').innerHTML = html;
  markChips();
}

// Tarjeta del catálogo: portada generativa (IA.cover), título, descripción y
// curso · idioma · cómo se abre. Sin contadores (👁/❤): view_count está
// congelado y un «0» en portada no le dice nada a nadie. Todo valor de la BD
// pasa por IA.esc (también las etiquetas: level es texto libre).
// La tarjeta entera es clicable con .ia-card-link (un <a> real, con nombre:
// el título); la estrella va FUERA del enlace (un <button> dentro de un <a>
// es inválido) y por encima (z-index de .ia-card-fav).
function renderCard(r) {
  const rid = Number(r.id) || 0;
  const fav = favSet.has(rid);
  // Cada dato es un bloque que no se parte («12–16 años», «En español»); la
  // línea solo se corta entre datos, por el separador.
  const item = (txt, cls) => '<span class="home-meta-item' + (cls ? ' ' + cls : '') + '">' + IA.esc(txt) + '</span>';
  const SEP = '<span aria-hidden="true"> · </span>';
  const meta = [];
  if (r.level_label) meta.push(item(r.level_label));
  if (r.lang_label)  meta.push(item(r.lang_label, r.lang === 'es' ? 'ia-lang-es' : ''));
  // «Cómo se abre» se omite en la tarjeta-fila del móvil (ahorra una línea por
  // tarjeta; lo dice la ficha). La fuente la lleva el sello de la portada; en
  // la tarjeta-fila la portada es pequeña y no lo enseña: ahí va al final.
  const opens = r.opens_label ? '<span class="home-rows-hide">' + SEP + item(r.opens_label) + '</span>' : '';
  const src = r.source_label ? '<span class="home-rows-only">' + SEP + item(r.source_label) + '</span>' : '';
  return '<article class="ia-card">' +
      IA.cover(r) +
      '<div class="ia-card-body">' +
        '<h3 class="ia-card-title" id="ct-' + rid + '">' + IA.esc(r.title) + '</h3>' +
        (r.description ? '<p class="ia-card-desc">' + IA.esc(r.description) + '</p>' : '') +
        '<div class="ia-card-meta">' + meta.join(SEP) + opens + src + '</div>' +
      '</div>' +
      '<a class="ia-card-link" href="/resource/' + rid + '" aria-labelledby="ct-' + rid + '"></a>' +
      '<button class="ia-btn ia-btn-icon ia-card-fav fav-btn' + (fav ? ' is-fav' : '') + '" type="button" data-id="' + rid + '"' +
        ' title="' + IA.esc(T.save) + '" aria-label="' + IA.esc(T.save) + '" aria-pressed="' + (fav ? 'true' : 'false') + '">' +
        '<i data-lucide="star" aria-hidden="true"></i></button>' +
    '</article>';
}

// ── Listeners ──
const $search = $('search');
$search.addEventListener('input', () => {
  syncClearBtn();
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => loadResources(), 300);
});
// Enter (o la lupa del teclado del móvil) busca YA, sin esperar el debounce;
// blur cierra el teclado en móvil. Sin JS el formulario hace un GET normal.
$('search-form').addEventListener('submit', e => {
  e.preventDefault();
  clearTimeout(debounceTimer);
  loadResources({push:true});
  $search.blur();
});
$search.addEventListener('keydown', e => {
  if (e.key === 'Escape' && $search.value) { e.preventDefault(); clearSearch(); }
});
$('search-clear').addEventListener('click', () => { clearSearch(); $search.focus(); });
$('sort').addEventListener('change', () => {
  // Elegir el orden a mano lo congela: al seguir tecleando no saltará a relevancia.
  sortExplicit = true;
  clearTimeout(debounceTimer);
  loadResources({push:true});
});

// Chips de filtro (los contenedores existen siempre: se delega una sola vez).
// Pulsar el chip ya marcado lo desmarca (vuelve a «Todos»).
function onChip(sel, get, set){
  return e => {
    const b = e.target.closest(sel);
    if (!b) return;
    const v = get(b);
    set(v && b.getAttribute('aria-pressed') === 'true' ? '' : v);
    markChips();
    clearTimeout(debounceTimer);
    loadResources({push:true});
  };
}
$('categories').addEventListener('click', onChip('[data-cat-id]', b => b.dataset.catId || '', v => { currentCat = v || null; }));
$('levels').addEventListener('click', onChip('[data-level]', b => b.dataset.level || '', v => { currentLevel = v; }));
$('rlangs').addEventListener('click', onChip('[data-rlang]', b => b.dataset.rlang || '', v => { currentRlang = v; }));

// Controles de los estados vacío/error, chips de filtros activos, sugerencias y ⭐
function onControlClick(e){
  const fb = e.target.closest('.fav-btn[data-id]');
  if (fb) { toggleFavorite(fb.dataset.id, fb); return; }
  const sg = e.target.closest('[data-suggest]');
  if (sg) { searchFor(sg.dataset.suggest); return; }
  if (e.target.closest('[data-retry]')) { loadResources({push:false}); return; }
  const cl = e.target.closest('[data-clear]');
  if (!cl) return;
  const k = cl.dataset.clear;
  if (k === 'all') { clearFilters(); return; }
  if (k === 'search') { $('search').value = ''; syncClearBtn(); }
  else if (k === 'category') currentCat = null;
  else if (k === 'lang')  currentRlang = '';
  else if (k === 'level') currentLevel = '';
  markChips();
  clearTimeout(debounceTimer);
  loadResources({push:true});
}
$('grid').addEventListener('click', onControlClick);
$('active-filters').addEventListener('click', onControlClick);
document.querySelector('.home-try').addEventListener('click', onControlClick);
$('more-wrap').addEventListener('click', e => {
  if (!e.target.closest('#more-btn')) return;
  page++;
  loadResources({append:true, push:false});
});

// Deep-link: search, sort, category, rlang y level se leen de la URL — así
// funciona cualquier enlace compartido (y los viejos /?sort=popular: la API
// sigue aceptándolos, la portada los trata como su orden por defecto).
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
// Catálogo y guardados en paralelo: las estrellas se marcan cuando llegan.
loadResources({push:false});
loadFavorites().then(applyFavs);
</script>
</body>
</html>
