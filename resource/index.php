<?php
// ================================================================
// resource/index.php — La ficha de un recurso
//
// URL: /resource/{id}
//
// Es donde de verdad se USA el recurso: el profesor lo proyecta o se lo manda
// a sus alumnos; el alumno —o quien aprende por su cuenta— lo abre aquí mismo,
// funcionando, y al terminar sabe qué hacer después.
//
// Orden de la página, de lo más a lo menos urgente:
//   1. Qué es: materia, nivel con edades, idioma, título, quién lo CREÓ (la
//      fuente original, enlazada) y quién lo ELIGIÓ para el catálogo.
//   2. Acciones de aula: Proyectar (un clic, pantalla completa), Mandar a mis
//      alumnos (QR + dirección corta), Guardar, Añadir a una lista, Compartir.
//   3. El recurso funcionando (iframe según code_type).
//   4. Para docentes, detalles, otras versiones, siguiente paso, más del
//      autor y comentarios.
//
// Cifras: NINGÚN contador público a cero ni congelado como protagonista
// (view_count está congelado desde 2026-08-06, AGENTS.md §6.8). La prueba
// social sale solo con umbral (IAREPO_PROOF_MIN_* de shared/labels.php); al AUTOR sí se le enseñan
// sus cifras reales.
//
// Piezas comunes: shared/ui.php (cabecera, pie, portada, diálogo «Mandar a
// mis alumnos»), shared/labels.php (cómo se nombra cada cosa) y
// assets/css/app.css (.ia-*). El <style> de abajo es solo lo propio de la
// ficha (prefijo .rf-).
//
// Antirregresión: tests/unit/resource_page_test.php (y los contratos de
// usage_signal_test, fork_lineage_test, tracking_test y comprehension_test,
// que también leen este fichero).
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/access.php';
require_once __DIR__ . '/../shared/ui.php';
require_once __DIR__ . '/../shared/srcdoc.php';   // enlaces internos del recurso (#…) dentro del iframe
// h() local — NO se carga shared/helpers.php: su error_handler vuelca JSON y
// corta la página a medias ante cualquier error (CLAUDE.md §2.1).
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

// ── Utilidades de la página (puras) ────────────────────────────

/**
 * Solo http(s) limpia (iarepo_http_host, shared/labels.php). Nunca un
 * javascript: en un href ni en el src de un iframe, ni una dirección que PHP
 * y el navegador leen con hosts distintos («https://otra.web\@phet…»).
 */
function rf_http_url(?string $u): string
{
    return iarepo_safe_http_url($u);
}

/** Dominio legible de una URL, sin «www.» (el MISMO host que carga el navegador). */
function rf_host(string $u): string
{
    return (string) preg_replace('/^www\./', '', (string) iarepo_http_host($u));
}

/** Fecha corta en el idioma de la interfaz. */
function rf_date(?string $ts): string
{
    $t = strtotime((string) $ts) ?: time();
    return lang() === 'en' ? date('M j, Y', $t) : date('d/m/Y', $t);
}

/**
 * Etiquetas visibles de una fila (shared/labels.php). El NOMBRE de la fuente
 * de un 'url' sin source_url ya lo deduce iarepo_source_label(); aquí además
 * se rellena source_url con esa dirección (solo http/https), porque la ficha
 * la enlaza en «Creado por <fuente> · <dominio>».
 */
function rf_labels(array $row): array
{
    if (trim((string) ($row['source_url'] ?? '')) === '' && ($row['code_type'] ?? '') === 'url')
        $row['source_url'] = rf_http_url($row['link_url'] ?? $row['code_content'] ?? '') ?: null;
    return iarepo_with_labels($row);
}

/**
 * Fila compacta (.ia-row) hacia otra ficha: portada de la materia, título y
 * fuente/edad/idioma. Sin ojos ni corazones: no hay contadores en listados.
 */
function rf_row(array $s, ?string $meta = null): string
{
    $s = rf_labels($s);
    if ($meta === null) {
        $bits = [$s['level_label'], $s['source_label'] ?? ''];
        if (($s['lang'] ?? '') !== '' && $s['lang'] !== lang())
            $bits[] = $s['lang_label'];
        $meta = implode(' · ', array_filter($bits, static fn($b) => trim((string) $b) !== ''));
    }
    return '<a class="ia-row rf-row-link" href="/resource/' . (int) $s['id'] . '">'
         . iarepo_cover($s)
         . '<span class="ia-row-body"><span class="ia-row-title">' . h((string) $s['title']) . '</span>'
         . ($meta !== '' ? '<span class="ia-row-meta">' . h($meta) . '</span>' : '')
         . '</span><i data-lucide="chevron-right" class="rf-row-go"></i></a>';
}

// ── El recurso ─────────────────────────────────────────────────

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /'); exit; }

$db = getResourcesDB();
$sessionUser = getSessionUser();
$user = authenticate();

$stmt = $db->prepare("
    SELECT r.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
    FROM resources r
    LEFT JOIN categories c ON r.category_id = c.id
    WHERE r.id = ? AND r.is_active = 1
");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { header('HTTP/1.1 404 Not Found'); header('Location: /'); exit; }

// Quién puede verlo: la MISMA regla que la API (shared/access.php). Antes esta
// página llevaba su propia copia y un borrador se abría con solo coincidir el
// user_id, sin mirar el colegio (tenant).
if (!canView($r, $user)) { header('Location: /'); exit; }

$r = rf_labels($r);

// Is the logged-in user the owner of this resource?
$isOwner = $user
    && (int)($user['user_id'] ?? 0) === (int)$r['author_user_id']
    && (int)($user['tenant_id'] ?? 0) === (int)$r['author_tenant_id'];

// Estudiante: oculta los CTA de creación/fork (la API igual los bloquea por rol).
//
// Mira las DOS identidades. Sólo consultaba $sessionUser (Google), así que un
// alumno que entra desde Campus —donde la identidad viaja en el JWT y
// $sessionUser es null— salía clasificado como docente y veía el botón Fork,
// contra lo que dice el propio comentario. authenticate() ya devuelve el rol
// normalizado de las dos fuentes.
$isStudent = ($sessionUser['role'] ?? '') === 'student'
          || ($user['role'] ?? '') === 'student';
$isAuth    = (bool) ($sessionUser || $user);
$isTeacher = $isAuth && !$isStudent;   // con sesión y no alumno: comenta, marca uso, hace su versión

// La cuenta semilla: 'iarepo' eligió la mayor parte del catálogo, pero NO lo
// creó. Se muestra como «Elegido por iarepo» y no tiene «Más de…».
$isSeedAuthor = strcasecmp(trim((string) $r['author_display_name']), 'iarepo') === 0;
$authorHasProfile = (int) $r['author_user_id'] > 0 && (int) $r['author_tenant_id'] === 0 && !$isSeedAuthor;

// Fuente original y cómo se abre.
$sourceUrl  = rf_http_url($r['source_url'] ?? '');
$sourceHost = $sourceUrl !== '' ? rf_host($sourceUrl) : '';
$linkUrl    = $r['code_type'] === 'url' ? rf_http_url($r['code_content']) : '';
// «A dónde ir si no se puede proyectar aquí»: la propia dirección si es un
// enlace, si no la fuente.
$openUrl    = $linkUrl ?: $sourceUrl;
$openName   = $r['source_label'] ?: ($openUrl !== '' ? rf_host($openUrl) : '');
// Sitio que no se deja embeber (o 'url' sin dirección válida): «Proyectar»
// abre la web original en otra pestaña en lugar de una pantalla completa vacía.
$embedBlocked = $r['code_type'] === 'url' && ((int) ($r['iframe_blocked'] ?? 0) === 1 || $linkUrl === '');
// «Hacer mi versión» solo tiene sentido si hay código que editar. En un
// recurso 'url' un fork duplicaría un enlace: desaparece.
$canFork = in_array($r['code_type'], ['html', 'embed'], true);

// 'school'/'area' con tenant 0 (toda cuenta de iarepo.com) los ve cualquiera
// con cuenta: iarepo_restricted_label() lo dice así, igual que Mi panel.
$visLabel = match ((string) $r['visibility']) {
    'draft'          => t('Borrador · solo tú lo ves'),
    'school', 'area' => iarepo_restricted_label((string) $r['visibility'], (int) $r['author_tenant_id']),
    default          => '',   // community: público, no hace falta decirlo
};

// Fetch tags
$tagStmt = $db->prepare("SELECT tag FROM resource_tags WHERE resource_id = ? ORDER BY tag");
$tagStmt->execute([$id]);
$resourceTags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

// Check if user liked
$userLiked = false;
$userFavorited = false;
// Van por users.id: solo con cuenta de iarepo.com. Con ?token= de Campus
// serían los de OTRA persona con el mismo número (shared/auth.php).
if (iarepo_is_site_account($user)) {
    $likeCheck = $db->prepare("SELECT id FROM resource_likes WHERE resource_id = ? AND user_id = ?");
    $likeCheck->execute([$id, $user['user_id']]);
    $userLiked = (bool)$likeCheck->fetch();

    $favCheck = $db->prepare("SELECT id FROM resource_favorites WHERE resource_id = ? AND user_id = ?");
    $favCheck->execute([$id, $user['user_id']]);
    $userFavorited = (bool)$favCheck->fetch();
}

// ¿Este docente ya declaró hoy que usó el recurso en clase?
//
// Sólo decide el estado inicial del botón: la verdad la impone el índice
// UNIQUE uniq_usage_signal (migration_011), no esta consulta.
//
// ── POR QUÉ VA ENVUELTA EN try/catch ───────────────────────────
// Si el despliegue llega ANTES que la migración —que se aplica a mano,
// RUNBOOK §4— la columna usage_day no existe y el driver lanza ERROR 1054.
// Sin capturarlo, la página se cortaría a medio renderizar (hasta 2026-09-26
// además con un JSON incrustado, cuando esta página cargaba helpers.php).
// Degradando a false, la única consecuencia de ese orden de despliegue es que
// el botón aparece sin marcar. La página nunca se rompe, y el fallo queda en
// el log del servidor.
$usedInClassToday = false;
if ($user && !$isStudent) {
    try {
        $useCheck = $db->prepare("
            SELECT id FROM resource_usage
            WHERE resource_id = ? AND user_id = ? AND tenant_id = ?
              AND usage_type = 'presented' AND usage_day = ?
        ");
        $useCheck->execute([$id, $user['user_id'], $user['tenant_id'] ?? 0, date('Y-m-d')]);
        $usedInClassToday = (bool)$useCheck->fetch();
    } catch (Throwable $e) {
        error_log('resource/index.php: estado de «lo usé en clase» no disponible: ' . $e->getMessage());
        $usedInClassToday = false;
    }
}

// ── El linaje: otras versiones públicas de este recurso ──────────
//
// `root_id` (migration_013) hace que "todas las versiones de esto" sea una
// consulta por índice y no un recorrido de la cadena de forks. Un original
// tiene root_id = su propio id, así que la MISMA consulta sirve para los dos
// casos: mirando desde el original o desde una de sus versiones.
//
// Sólo entran las 'community'. Un fork nace en 'draft' y casi ninguno se
// publica, así que la mayoría son copias privadas de gente trasteando: sacarlas
// aquí enseñaría enlaces que nadie más puede abrir.
//
// try/catch por lo mismo que arriba: si el despliegue llega antes que la
// migración, root_id no existe y el driver lanza ERROR 1054. Degradando a
// lista vacía, el panel simplemente no aparece (y el fallo queda en el log).
$versions   = [];
$lineageRoot = null;   // el original, cuando ESTA página es una versión derivada
$rootId     = $id;

try {
    $rootId = (int)($r['root_id'] ?? 0) ?: (int)($r['fork_of'] ?? 0) ?: $id;

    $vStmt = $db->prepare("
        SELECT id, title, author_display_name, author_user_id, created_at, is_recommended
        FROM resources
        WHERE root_id = ? AND id <> ? AND is_active = 1 AND visibility = 'community'
        ORDER BY is_recommended DESC, created_at DESC
        LIMIT 20
    ");
    $vStmt->execute([$rootId, $id]);
    $versions = $vStmt->fetchAll();

    if ($rootId !== $id) {
        $rStmt = $db->prepare("SELECT id, title, author_display_name, author_user_id, author_tenant_id, visibility
                               FROM resources WHERE id = ? AND is_active = 1");
        $rStmt->execute([$rootId]);
        $lineageRoot = $rStmt->fetch() ?: null;
        // ⛔ El original puede haber pasado a borrador (o a restringido)
        // después de que otro publicara su versión: entonces ni su título ni
        // su autor se enseñan a quien no puede verlo, y la fila desaparece.
        // Antes salía «<título nuevo del borrador> · Original de …» con un
        // enlace que daba 302 [revisión 2026-09].
        if ($lineageRoot && !canView($lineageRoot, $user))
            $lineageRoot = null;
    }
} catch (Throwable $e) {
    error_log('resource/index.php: linaje de versiones no disponible: ' . $e->getMessage());
    $versions = [];
    $lineageRoot = null;
    $rootId = $id;
}
// Mirando desde una versión derivada, la consulta devuelve también el original
// (a propósito: es la misma cláusula que prueba fork_lineage_db_test). Como el
// original ya se pinta aparte, arriba y marcado, no se repite en la lista.
if ($lineageRoot)
    $versions = array_values(array_filter($versions, static fn($v) => (int) $v['id'] !== (int) $lineageRoot['id']));

// Versiones PÚBLICAS, no `fork_count`: fork_count cuenta también los 'draft'
// —casi todos, porque nacen privados—, así que la ficha decía "12 Forks" y al
// pinchar aparecían 2. Este número sale del mismo listado que se pinta.
$versionCount = count($versions);

// ¿Puede esta persona destacar versiones? Sólo el autor de la RAÍZ. Si pudiera
// hacerlo el autor de cada fork, "recomendada" pasaría a significar "su autor
// pulsó un botón" y el distintivo no valdría nada. La API lo comprueba igual.
$canRecommend = false;
if ($user && $rootId === $id) {
    $canRecommend = (int)($user['user_id'] ?? 0) === (int)$r['author_user_id']
                 && (int)($user['tenant_id'] ?? 0) === (int)$r['author_tenant_id'];
}

// ── Siguiente paso: misma materia y, si lo tiene, mismo nivel ────
//
// NO se ordena por popularidad (ni use_count ni unique_views: aún no hay
// datos, y ordenar por ceros es ordenar por azar con aspecto de criterio).
// Orden: el IDIOMA DEL RECURSO primero (quien está en uno en español sigue
// en español), luego el de la interfaz, el mismo tema si lo tiene y la
// cercanía de id — los recursos se dieron de alta por colecciones, así que
// los vecinos suelen ser de la misma familia. Si el nivel deja la lista
// vacía, se relaja.
// Antes el tema iba primero y con «=» a secas: con topic_tag NULL (311 de
// 361, entre ellos los 94 en español) la expresión valía NULL, que en DESC va
// al final, y el idioma no llegaba a decidir nada: «Flotabilidad: Intro»
// sugería cuatro simulaciones en inglés habiendo otras en español de la misma
// materia y curso [revisión 2026-09].
$nextSteps = [];
if ($r['category_id']) {
    $topic = trim((string) ($r['topic_tag'] ?? ''));
    $fetchNext = static function (bool $sameLevel) use ($db, $r, $id, $topic): array {
        $sql = "SELECT r.id, r.title, r.code_type, r.level, r.lang, r.topic_tag,
                       r.source_name, r.source_url,
                       IF(r.code_type = 'url', r.code_content, NULL) AS link_url,
                       c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
                FROM resources r
                LEFT JOIN categories c ON r.category_id = c.id
                WHERE r.category_id = ? AND r.id <> ? AND r.is_active = 1 AND r.visibility = 'community'"
             . ($sameLevel ? ' AND r.level = ?' : '')
             . ' ORDER BY (r.lang = ?) DESC, (r.lang = ?) DESC'
             . ($topic !== '' ? ', COALESCE(r.topic_tag = ?, 0) DESC' : '')
             . ', ABS(r.id - ?) ASC LIMIT 8';
        $params = [(int) $r['category_id'], $id];
        if ($sameLevel)
            $params[] = (string) $r['level'];
        array_push($params, (string) $r['lang'], lang());
        if ($topic !== '')
            $params[] = $topic;
        $params[] = $id;
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    };
    // Fuera lo que ya sale en «Otras versiones»: el mismo recurso dos veces
    // no es un paso siguiente. Se filtra aquí y no en SQL para no depender de
    // root_id (migration_013), que la consulta del linaje ya protege aparte.
    $sameLineage = array_map('intval', array_column($versions, 'id'));
    if ($lineageRoot)
        $sameLineage[] = (int) $lineageRoot['id'];
    $keep = static fn(array $rows): array => array_slice(array_values(array_filter(
        $rows, static fn($x) => !in_array((int) $x['id'], $sameLineage, true))), 0, 4);
    $nextSteps = trim((string) $r['level']) !== '' ? $keep($fetchNext(true)) : [];
    if (!$nextSteps)
        $nextSteps = $keep($fetchNext(false));
}
// ── Viene de una lista (?list=ID): la secuencia del docente manda ──
//
// «Empezar por el paso 1» y cada paso de una lista enlazan aquí con ?list=.
// Sin esto, el alumno que llegaba por el QR de la lista perdía la secuencia
// en el primer recurso: la ficha le proponía OTRO «Siguiente paso» y solo
// podía volver con «atrás» [revisión 2026-09]. Se enseña «Paso 2 de 4 ·
// <lista>», volver a la lista y el paso siguiente DE LA LISTA, solo si quien
// mira puede ver la lista (pública, o suya) y este recurso está en ella. Los
// pasos pasan por canView(), como en la página de la lista: la numeración es
// la misma que allí.
$listNav = null;
$listParam = $_GET['list'] ?? null;
$listId = is_string($listParam) && ctype_digit($listParam) ? (int) $listParam : 0;
if ($listId > 0) {
    try {
        $ls = $db->prepare('SELECT id, user_id, title, is_public FROM collections WHERE id = ?');
        $ls->execute([$listId]);
        $list = $ls->fetch();
        $mine = $list && $sessionUser && (int) $sessionUser['id'] === (int) $list['user_id'];
        if ($list && ((int) $list['is_public'] === 1 || $mine)) {
            $li = $db->prepare("SELECT r.id, r.title, r.visibility, r.author_tenant_id, r.author_user_id
                                FROM collection_items ci JOIN resources r ON r.id = ci.resource_id AND r.is_active = 1
                                WHERE ci.collection_id = ? ORDER BY ci.added_at ASC, ci.id ASC");
            $li->execute([$listId]);
            $seq = array_values(array_filter($li->fetchAll(), static fn($x) => canView($x, $user)));
            $pos = array_search($id, array_map('intval', array_column($seq, 'id')), true);
            if ($pos !== false)
                $listNav = ['id' => $listId, 'title' => (string) $list['title'], 'pos' => $pos + 1, 'total' => count($seq),
                            'next' => $seq[$pos + 1] ?? null];
        }
    } catch (Throwable $e) {
        // Sin la barra de la lista la ficha sigue sirviendo; el fallo, al log.
        error_log('resource/index.php: navegación de la lista ' . $listId . ' no disponible: ' . $e->getMessage());
        $listNav = null;
    }
}

$nextHref = '/?category=' . (int) $r['category_id']
          . (trim((string) $r['level']) !== '' ? '&level=' . rawurlencode((string) $r['level']) : '');

// ── Más de este autor (solo personas, no la cuenta semilla) ──────
$byAuthor = [];
if ($authorHasProfile) {
    $authorStmt = $db->prepare("
        SELECT r.id, r.title, r.code_type, r.level, r.lang, r.topic_tag, r.source_name, r.source_url,
               IF(r.code_type = 'url', r.code_content, NULL) AS link_url,
               c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon
        FROM resources r
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE r.author_user_id = ? AND r.author_tenant_id = 0
          AND r.id != ? AND r.is_active = 1 AND r.visibility = 'community'
        ORDER BY r.created_at DESC LIMIT 4
    ");
    $authorStmt->execute([$r['author_user_id'], $id]);
    $byAuthor = $authorStmt->fetchAll();
}

// ── Cifras ──────────────────────────────────────────────────────
//
// Aperturas = histórico congelado + visitas únicas reales. Se SUMAN (aunque
// midan cosas distintas: cargas con bots incluidos contra personas-día) para
// que el número no se desplome el día que se mejoró la forma de medir. Para
// RANKING se usaría siempre `unique_views` a solas.
//
// En público solo se enseñan por encima de un umbral: un «0 vistas» o un
// «1 uso» no ayuda a nadie a decidir y hace que el recurso parezca abandonado.
// El autor ve siempre las suyas (bloque «Es tu recurso»).
// Umbrales: IAREPO_PROOF_MIN_* en shared/labels.php (los mismos que el perfil).
$viewsLegacy = (int)($r['view_count'] ?? 0);
$viewsUnique = (int)($r['unique_views'] ?? 0);   // ?? 0 → sobrevive a que aún no se haya aplicado migration_012
$opens       = $viewsLegacy + $viewsUnique;
$uses        = (int)($r['use_count'] ?? 0);
$likes       = (int)($r['like_count'] ?? 0);
$showOpens   = $opens >= IAREPO_PROOF_MIN_OPENS;
$showUses    = $uses >= IAREPO_PROOF_MIN_TEACHERS;

// Rutas y textos de apoyo.
$selfPath   = '/resource/' . $id;
$signinUrl  = '/auth/signin.php?return_url=' . rawurlencode($selfPath);
$authorName = (string) $r['author_display_name'];
$titleText  = (string) $r['title'];
$shareUrl   = 'https://iarepo.com' . $selfPath;
$ogDesc     = $r['description'] ?: t('Recurso educativo interactivo, gratis y sin registro');

$metaDesc = $r['description'] ?: $titleText;
$metaDesc .= ' · ' . $r['category_label'];
if ($r['level_label'] !== '') $metaDesc .= ' · ' . iarepo_level_label($r['level']);
$metaDesc = mb_substr($metaDesc . ' — iarepo', 0, 160);
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titleText) ?> — <?= h($r['category_label']) ?> · iarepo</title>
<meta name="description" content="<?= h($metaDesc) ?>">
<meta property="og:title" content="<?= h($titleText) ?> — iarepo">
<meta property="og:description" content="<?= h($ogDesc) ?>">
<meta property="og:url" content="https://iarepo.com/resource/<?= $id ?>">
<meta property="og:type" content="article">
<meta property="og:image" content="https://iarepo.com/api/og-image.php?id=<?= $id ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="iarepo">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($titleText) ?> — iarepo">
<meta name="twitter:description" content="<?= h($ogDesc) ?>">
<meta name="twitter:image" content="https://iarepo.com/api/og-image.php?id=<?= $id ?>">
<link rel="canonical" href="https://iarepo.com/resource/<?= $id ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#6D28D9">
<?= iarepo_head_assets() ?>
<!-- pwa.js: service worker y el «guardar pendiente» de un invitado que pulsó
     Guardar antes de entrar (lo aplica al volver, busca #favBtn[data-id]). -->
<?= iarepo_pwa_script() ?>
<!-- Medición de visitas. ESTA es la página que no contaba nada pese a ser
     donde de verdad se usa el recurso (se renderiza funcionando ahí abajo, en
     un iframe): 20 alumnos trabajando daban 8 visitas. Va por beacon y no por
     un UPDATE en PHP a propósito: un fallo escribiendo en la BD no puede
     costar la página. Ruta: /assets/js/track.js (versionada con ?v=). -->
<script src="<?= h(iarepo_asset('/assets/js/track.js')) ?>" data-resource-id="<?= $id ?>" data-surface="detail" defer></script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LearningResource",
  "name": <?= json_encode($titleText, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  "description": <?= json_encode($r['description'] ?: $titleText, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  "url": "https://iarepo.com/resource/<?= $id ?>",
  "image": "https://iarepo.com/api/og-image.php?id=<?= $id ?>",
  "datePublished": "<?= date('Y-m-d', strtotime($r['created_at'])) ?>",
  "dateModified": "<?= date('Y-m-d', strtotime($r['updated_at'] ?? $r['created_at'])) ?>",
  "inLanguage": "<?= h($r['lang'] ?: 'es') ?>",
  "isAccessibleForFree": true,
  "author": {
    "@type": <?= json_encode($r['source_label'] ? 'Organization' : 'Person', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    "name": <?= json_encode($r['source_label'] ?: $authorName, JSON_HEX_TAG | JSON_HEX_AMP) ?>
  },
  "provider": {
    "@type": "Organization",
    "name": "iarepo",
    "url": "https://iarepo.com"
  }<?php if ($r['category_id']): ?>,
  "educationalLevel": <?= json_encode(iarepo_level_label($r['level'], false) ?: 'General', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  "about": {
    "@type": "Thing",
    "name": <?= json_encode($r['category_label'], JSON_HEX_TAG | JSON_HEX_AMP) ?>
  }<?php endif; ?><?php if ($sourceUrl !== ''): ?>,
  "isBasedOn": {
    "@type": "WebPage",
    "name": <?= json_encode($r['source_name'] ?: $r['source_label'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    "url": <?= json_encode($sourceUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>
  }<?php endif; ?>
}
</script>
<style>
/* ── Ficha (resource/index.php) — solo lo propio de esta página ──
   Botones, etiquetas, portadas, filas, diálogos y aviso: app.css (.ia-*). */
.rf-main { padding-top: 8px; }
/* Barra «Paso 2 de 4 · <lista>» cuando se llega desde una lista (?list=). */
.rf-listnav { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin: 4px 0 14px; padding: 10px 14px;
  border-radius: var(--ia-radius); background: var(--ia-accent-soft); color: var(--ia-ink); }
.rf-listnav-back { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
.rf-listnav-back svg { width: 18px; height: 18px; }
.rf-listnav-where { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
.rf-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 6px; margin: 8px 0 14px; font-size: .875rem; color: var(--ia-ink-3); }
.rf-crumbs a { color: var(--ia-ink-2); font-weight: 600; text-decoration: none; }
.rf-crumbs a:hover { text-decoration: underline; }
.rf-crumbs svg { width: 14px; height: 14px; }
.rf-crumbs [aria-current] { min-width: 0; max-width: 42ch; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Cabecera: qué es y quién lo hizo. */
.rf-head > p, .rf-head h1 { max-width: 920px; }
.rf-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.ia-page .rf-head h1 { font-size: clamp(1.65rem, 1.2rem + 1.7vw, 2.5rem); margin-bottom: .3em; overflow-wrap: anywhere; }
.rf-attrib { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 14px; margin: 0 0 6px; color: var(--ia-ink-2); }
.rf-attrib > span { display: inline-flex; align-items: center; gap: 6px; min-width: 0; }
.rf-attrib a { font-weight: 700; overflow-wrap: anywhere; }
.rf-attrib .ia-cover-mono { display: inline-grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: var(--subj); color: #fff; font-size: .68rem; font-weight: 800; flex: none; }
.rf-proof { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 0 0 8px; font-size: .9rem; color: var(--ia-ink-3); }
.rf-proof span { display: inline-flex; align-items: center; gap: 6px; }
.rf-proof svg, .rf-attrib svg { width: 16px; height: 16px; }
.rf-desc { max-width: 72ch; color: var(--ia-ink-2); font-size: 1.02rem; margin: 6px 0 0; }

/* Acciones de aula. En móvil, las dos grandes ocupan la fila entera. */
.rf-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 18px 0; }
.rf-actions-minor { display: flex; flex-wrap: wrap; gap: 4px; }
@media (max-width: 559px) {
  .rf-actions { gap: 8px; }
  .rf-actions > .ia-btn-lg { flex: 1 1 100%; }
  .rf-actions > .ia-btn-secondary, .rf-menu-wrap > .ia-btn { padding-left: 12px; padding-right: 12px; }
}
.rf-count { font-variant-numeric: tabular-nums; }
/* Estado pulsado por aria-pressed (fuente única): assets/js/pwa.js, al aplicar
   el «guardar» pendiente de un invitado, marca aria-pressed pero no .is-on. */
#favBtn[aria-pressed="true"], #likeBtn[aria-pressed="true"] { background: var(--ia-accent-soft); border-color: var(--ia-accent); color: var(--ia-accent); }
#favBtn[aria-pressed="true"] svg, #likeBtn[aria-pressed="true"] svg { fill: currentColor; }
.rf-menu-wrap { position: relative; display: inline-flex; }
.rf-menu { position: absolute; top: calc(100% + 6px); left: 0; z-index: 40; width: 300px; max-width: calc(100vw - 32px);
  padding: 6px; background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); box-shadow: var(--ia-shadow-lg); }
@media (max-width: 559px) { .rf-menu { position: fixed; top: auto; left: 12px; right: 12px; bottom: 12px; width: auto; max-width: none; max-height: 70vh; overflow: auto; } }
.rf-menu-title { margin: 6px 10px 4px; font-size: .8rem; font-weight: 700; color: var(--ia-ink-3); text-transform: uppercase; letter-spacing: .05em; }
.rf-menu-item { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 44px; padding: 8px 10px; border: 0; border-radius: 8px;
  background: none; color: var(--ia-ink); font: 600 .95rem/1.25 var(--ia-font); text-align: left; cursor: pointer; }
.rf-menu-item:hover { background: var(--ia-surface-2); }
.rf-menu-item svg { width: 18px; height: 18px; flex: none; color: var(--ia-ink-3); }
.rf-menu-item .rf-menu-n { margin-left: auto; font-weight: 500; color: var(--ia-ink-3); font-variant-numeric: tabular-nums; }
.rf-menu-empty { margin: 6px 10px 8px; font-size: .9rem; color: var(--ia-ink-3); }
.rf-newlist { display: flex; gap: 6px; padding: 6px; border-top: 1px solid var(--ia-line); margin-top: 4px; }
.rf-newlist .ia-input { flex: 1; min-width: 0; min-height: 40px; }

/* El escenario: el recurso funcionando. «Proyectar» lo pone a pantalla
   completa; sin Fullscreen API (iPhone) se simula con .is-full. */
.rf-stage { position: relative; overflow: hidden; background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); box-shadow: var(--ia-shadow); }
.rf-frame { display: block; width: 100%; height: clamp(420px, calc(100vh - 140px), 860px); border: 0; background: #fff; }
@media (max-width: 899px) { .rf-frame { height: 68vh; min-height: 380px; } }
.rf-stage:fullscreen { border: 0; border-radius: 0; background: #000; }
.rf-stage:-webkit-full-screen { border: 0; border-radius: 0; background: #000; }
.rf-stage.is-full { position: fixed; inset: 0; z-index: 1000; border: 0; border-radius: 0; background: #000; }
.rf-stage:fullscreen .rf-frame, .rf-stage.is-full .rf-frame { height: 100%; min-height: 0; }
.rf-stage:-webkit-full-screen .rf-frame { height: 100%; min-height: 0; }
.rf-noscroll { overflow: hidden; }
.rf-exit { position: absolute; top: 12px; right: 12px; z-index: 3; display: none; align-items: center; gap: 6px; min-height: 40px; padding: 6px 14px;
  border: 1px solid rgba(255, 255, 255, .3); border-radius: 999px; background: rgba(13, 16, 21, .78); color: #fff; font: 600 .9rem var(--ia-font); cursor: pointer; transition: opacity .3s; }
.rf-exit svg { width: 16px; height: 16px; }
.rf-stage:fullscreen .rf-exit, .rf-stage.is-full .rf-exit { display: inline-flex; }
.rf-stage:-webkit-full-screen .rf-exit { display: inline-flex; }
.rf-exit.is-idle { opacity: .4; }   /* táctil: el toque en el iframe no avisa a la página; atenuado, no invisible */
@media (hover: hover) { .rf-exit.is-idle { opacity: 0; } }
.rf-exit:hover, .rf-exit:focus-visible { opacity: 1; }
.rf-fallback { display: grid; place-items: center; align-content: center; gap: 14px; min-height: 420px; padding: 32px 20px; text-align: center; background: var(--ia-surface-2); }
.rf-fallback p { max-width: 46ch; margin: 0; color: var(--ia-ink-2); }
.rf-fallback .rf-fallback-ico { width: 48px; height: 48px; color: var(--ia-ink-3); }
.rf-code { height: 500px; margin: 0; padding: 20px; overflow: auto; background: #1e1e2e; color: #cdd6f4; font: 14px/1.5 var(--ia-mono); white-space: pre-wrap; }
.rf-stage-note { margin: 8px 2px 0; font-size: .875rem; color: var(--ia-ink-3); }

/* Debajo del recurso: contenido + columna lateral. En móvil la lateral
   (Para docentes, detalles) va primero: es lo que el docente busca. */
.rf-layout { display: grid; gap: 24px; margin-top: 28px; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 960px) { .rf-layout { grid-template-columns: minmax(0, 1fr) 340px; align-items: start; } }
@media (max-width: 959px) { .rf-aside { order: -1; } }
.rf-col, .rf-aside { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; min-width: 0; align-content: start; }
.rf-card { padding: 18px; background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); }
.ia-page .rf-card h2, .ia-page .rf-block h2 { display: flex; align-items: center; gap: 8px; font-size: 1.15rem; margin-bottom: 10px; }
.rf-card h2 svg, .rf-block h2 svg { width: 20px; height: 20px; color: var(--ia-ink-3); }
.rf-block { scroll-margin-top: 84px; }   /* que la cabecera fija no tape el título al saltar a #versiones */
.rf-block-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; margin-bottom: 10px; }
.ia-page .rf-block-head h2 { margin: 0; }
.rf-block-head p { margin: 0; flex-basis: 100%; }

.rf-teach-list, .rf-list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: minmax(0, 1fr); }
.rf-teach-item { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 60px; padding: 10px 6px; border: 0; border-top: 1px solid var(--ia-line);
  background: none; color: var(--ia-ink); font: inherit; text-align: left; text-decoration: none; cursor: pointer; border-radius: 0; }
.ia-page a.rf-teach-item { color: var(--ia-ink); text-decoration: none; }
.rf-teach-item:hover:not(:disabled) { background: var(--ia-surface-2); }
.rf-teach-item:disabled { cursor: default; }
.rf-teach-item strong { display: block; font-size: .98rem; }
.rf-teach-item small { display: block; font-size: .84rem; color: var(--ia-ink-3); }
.rf-teach-item .rf-ico { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 10px; background: var(--ia-surface-2); color: var(--ia-ink-2); }
.rf-teach-item .rf-ico svg { width: 18px; height: 18px; }
/* "Lo usé en clase": verde, distinto del violeta de las acciones. Es una
   afirmación docente, no un gesto de preferencia. */
.rf-teach-item.is-used .rf-ico { background: var(--ia-ok-soft); color: var(--ia-ok); }
.rf-teach-item.is-used strong { color: var(--ia-ok); }

.rf-owner-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 0 0 12px; }
.rf-owner-stats div { padding: 10px; border-radius: 10px; background: var(--ia-surface-2); }
.rf-owner-stats dt { font-size: .8rem; color: var(--ia-ink-3); }
.rf-owner-stats dd { margin: 0; font-size: 1.25rem; font-weight: 800; font-variant-numeric: tabular-nums; }

.rf-details { margin: 0; display: grid; }
.rf-details > div { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-top: 1px solid var(--ia-line); font-size: .92rem; }
.rf-details dt { color: var(--ia-ink-3); }
.rf-details dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.rf-topics { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.ia-page .rf-topics a.ia-tag { text-decoration: none; }
.rf-prompt { margin-top: 12px; font-size: .9rem; }
.rf-prompt summary { cursor: pointer; color: var(--ia-ink-2); font-weight: 600; }
.rf-prompt pre { margin: 8px 0 0; max-height: 220px; overflow: auto; padding: 12px; border-radius: 8px; background: var(--ia-surface-2); color: var(--ia-ink-2); font: .82rem/1.5 var(--ia-mono); white-space: pre-wrap; }

.rf-list { gap: 10px; }
.ia-page .rf-row-link { color: var(--ia-ink); text-decoration: none; transition: border-color .15s; }
.rf-row-link:hover { border-color: var(--ia-line-strong); }
.rf-row-go { width: 18px; height: 18px; flex: none; color: var(--ia-ink-3); }
.rf-version { display: grid; grid-template-columns: minmax(0, 1fr); gap: 6px; }
.rf-version .rec-btn { justify-self: start; }
.rf-origin .ia-row { border-color: var(--ia-accent); }

/* Comentarios */
.rf-comment-form { display: grid; gap: 10px; margin-bottom: 18px; }
.rf-comment-form textarea { width: 100%; min-height: 84px; resize: vertical; border-radius: 12px; font: 500 .95rem/1.45 var(--ia-font); }
.rf-comment-form .ia-btn { justify-self: end; }
.rf-note { padding: 14px 16px; border-radius: 12px; background: var(--ia-surface-2); color: var(--ia-ink-2); margin: 0 0 18px; }
.rf-comment { padding: 14px 16px; margin-bottom: 10px; background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); }
.rf-comment-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: .88rem; }
.rf-comment-head img { width: 24px; height: 24px; border-radius: 50%; }
.rf-comment-head time { color: var(--ia-ink-3); font-size: .8rem; }
.rf-comment-body { color: var(--ia-ink-2); font-size: .95rem; overflow-wrap: anywhere; white-space: pre-line; }
.rf-replies { margin: 10px 0 0 18px; }

/* Compartir e Insertar (diálogos secundarios) */
.rf-share { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
.rf-share .ia-btn { justify-content: flex-start; }
.rf-embed-sizes { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.rf-embed-code { width: 100%; height: 110px; padding: 12px; resize: none; border: 2px solid var(--ia-line-strong); border-radius: 10px;
  background: var(--ia-surface-2); color: var(--ia-ink); font: .8rem/1.55 var(--ia-mono); }

/* «¿Te quedó claro?» — abajo a la derecha y solo tras uso real. */
.cmp-prompt { position: fixed; right: 16px; bottom: 16px; z-index: 150; width: 330px; max-width: calc(100vw - 32px); display: none;
  padding: 16px; background: var(--ia-surface); color: var(--ia-ink); border: 1px solid var(--ia-line-strong); border-radius: 16px; box-shadow: var(--ia-shadow-lg); }
.cmp-prompt.open { display: block; }
.cmp-prompt p { margin: 0 28px 10px 0; font-weight: 700; }
.cmp-opts, .cmp-follow { display: grid; gap: 6px; }
.cmp-opt { min-height: 44px; padding: 8px 12px; border: 2px solid var(--ia-line); border-radius: 10px; background: var(--ia-surface-2);
  color: var(--ia-ink); font: 600 .92rem var(--ia-font); text-align: left; cursor: pointer; }
.cmp-opt:hover:not(:disabled) { border-color: var(--ia-accent); }
.cmp-follow a { display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 8px 12px; border-radius: 10px; background: var(--ia-accent-soft); font-weight: 600; text-decoration: none; }
.cmp-follow svg { width: 18px; height: 18px; flex: none; }
.cmp-close { position: absolute; top: 6px; right: 6px; }
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($sessionUser, 'explore'); ?>

<main id="main" class="ia-container rf-main">
  <nav class="rf-crumbs" aria-label="<?= h(t('Estás en')) ?>">
    <a href="/"><?= h(t('Inicio')) ?></a>
    <?php if ($r['category_id']): ?>
      <i data-lucide="chevron-right" aria-hidden="true"></i>
      <a href="/?category=<?= (int) $r['category_id'] ?>"><?= h($r['category_label']) ?></a>
    <?php endif; ?>
    <i data-lucide="chevron-right" aria-hidden="true"></i>
    <span aria-current="page"><?= h($titleText) ?></span>
  </nav>

  <?php if ($listNav): ?>
  <!-- Secuencia de una lista (?list=): lo que el docente quiere que hagas después. -->
  <nav class="rf-listnav" aria-label="<?= h(t('Lista')) ?>">
    <a class="rf-listnav-back" href="/collection/?id=<?= (int) $listNav['id'] ?>"><i data-lucide="list-ordered" aria-hidden="true"></i><?= h(t('Volver a la lista')) ?></a>
    <span class="rf-listnav-where"><strong><?= h(sprintf(t('Paso %1$s de %2$s'), $listNav['pos'], $listNav['total'])) ?></strong> · <?= h($listNav['title']) ?></span>
    <?php if ($listNav['next']): ?>
      <a class="ia-btn ia-btn-primary ia-btn-sm" id="listNext" href="/resource/<?= (int) $listNav['next']['id'] ?>?list=<?= (int) $listNav['id'] ?>"><?= h(t('Siguiente paso de la lista')) ?><i data-lucide="arrow-right" aria-hidden="true"></i></a>
    <?php else: ?>
      <span class="ia-tag ia-tag-ok"><?= h(t('Último paso de la lista')) ?></span>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <header class="rf-head <?= h($r['subject_class']) ?>">
    <div class="rf-tags">
      <span class="ia-tag ia-tag-subject"><?= h($r['category_label']) ?></span>
      <?php if ($r['level_label'] !== ''): ?><span class="ia-tag"><?= h($r['level_label']) ?></span><?php endif; ?>
      <?php if ($r['lang_label'] !== ''): ?><span class="ia-tag"><?= h($r['lang_label']) ?></span><?php endif; ?>
      <?php if ($visLabel !== ''): ?><span class="ia-tag ia-tag-new"><i data-lucide="lock" style="width:14px;height:14px"></i><?= h($visLabel) ?></span><?php endif; ?>
    </div>
    <h1><?= h($titleText) ?></h1>

    <!-- Atribución: quién lo CREÓ (la fuente original, enlazada) y quién lo
         ELIGIÓ. Antes 30 recursos de PhET firmaban «Autor: iarepo». -->
    <p class="rf-attrib">
      <?php if ($r['source_label']): ?>
        <span>
          <span class="ia-cover-mono" aria-hidden="true"><?= h((string) $r['source_mono']) ?></span>
          <?= h(t('Creado por')) ?>
          <?php if ($sourceUrl !== ''): ?>
            <a href="<?= h($sourceUrl) ?>" target="_blank" rel="noopener"><?= h($r['source_label']) ?><?= $sourceHost !== '' && strcasecmp($sourceHost, (string) $r['source_label']) !== 0 ? ' · ' . h($sourceHost) : '' ?></a>
          <?php else: ?>
            <strong><?= h($r['source_label']) ?></strong>
          <?php endif; ?>
        </span>
        <span><?= h(t('Elegido por')) ?>
          <?php if ($authorHasProfile): ?><a href="/profile/<?= (int) $r['author_user_id'] ?>"><?= h($authorName) ?></a>
          <?php else: ?><strong><?= h($authorName) ?></strong><?php endif; ?>
        </span>
      <?php else: ?>
        <span><i data-lucide="user-round" aria-hidden="true"></i><?= h(t('Creado por')) ?>
          <?php if ($authorHasProfile): ?><a href="/profile/<?= (int) $r['author_user_id'] ?>"><?= h($authorName) ?></a>
          <?php else: ?><strong><?= h($authorName) ?></strong><?php endif; ?>
        </span>
      <?php endif; ?>
    </p>

    <?php if ($showOpens || $showUses): ?>
    <p class="rf-proof">
      <?php if ($showOpens): ?><span title="<?= h(t('Visitas únicas')) ?>: <?= $viewsUnique ?> · <?= h(t('histórico anterior')) ?>: <?= $viewsLegacy ?>"><i data-lucide="users" aria-hidden="true"></i><?= h(sprintf(t('Abierto por %s personas'), iarepo_num($opens))) ?></span><?php endif; ?>
      <?php if ($showUses): ?><span><i data-lucide="graduation-cap" aria-hidden="true"></i><?= h(t('Usado en clase por')) ?> <strong id="useCount"><?= iarepo_num($uses) ?></strong> <?= h(t('docentes')) ?></span><?php endif; ?>
    </p>
    <?php endif; ?>

    <?php if ($r['description']): ?><p class="rf-desc"><?= h((string) $r['description']) ?></p><?php endif; ?>

    <!-- Acciones de aula. «Proyectar» es la principal: un clic y el recurso
         ocupa la pantalla. «Compartir» en redes e «Insertar» son secundarias. -->
    <div class="rf-actions">
      <button type="button" class="ia-btn ia-btn-primary ia-btn-lg" id="projectBtn">
        <i data-lucide="<?= $embedBlocked ? 'external-link' : 'presentation' ?>"></i><?= $isStudent ? h(t('Ver a pantalla completa')) : h(t('Proyectar')) ?>
      </button>
      <?php if (!$isStudent): ?>
      <button type="button" class="ia-btn ia-btn-secondary ia-btn-lg" id="sendBtn"><i data-lucide="qr-code"></i><?= h(t('Mandar a mis alumnos')) ?></button>
      <?php endif; ?>
      <button type="button" class="ia-btn ia-btn-secondary<?= $userFavorited ? ' is-on' : '' ?>" id="favBtn" data-id="<?= $id ?>" aria-pressed="<?= $userFavorited ? 'true' : 'false' ?>" title="<?= h(t('Guardados: solo tú los ves')) ?>">
        <i data-lucide="star"></i><span><?= h(t('Guardar')) ?></span>
      </button>
      <?php if ($isAuth): ?>
      <div class="rf-menu-wrap">
        <button type="button" class="ia-btn ia-btn-secondary" id="saveCollBtn" aria-haspopup="true" aria-expanded="false" aria-controls="collDropdown">
          <i data-lucide="list-plus"></i><?= h(t('Añadir a una lista')) ?>
        </button>
        <div class="rf-menu" id="collDropdown" hidden>
          <p class="rf-menu-title"><?= h(t('Mis listas')) ?></p>
          <div id="collItems"><p class="rf-menu-empty"><?= h(t('Cargando...')) ?></p></div>
          <form class="rf-newlist" id="newListForm" autocomplete="off">
            <label class="ia-sr-only" for="newListTitle"><?= h(t('Nombre de la lista')) ?></label>
            <input class="ia-input" id="newListTitle" maxlength="255" placeholder="<?= h(t('+ Nueva lista')) ?>" required>
            <button type="submit" class="ia-btn ia-btn-primary ia-btn-sm"><?= h(t('Crear')) ?></button>
          </form>
        </div>
      </div>
      <?php endif; ?>
      <!-- Gestos menores: van juntos para que, si no caben, bajen de línea
           como un bloque y no quede un botón huérfano. -->
      <div class="rf-actions-minor">
      <button type="button" class="ia-btn ia-btn-ghost<?= $userLiked ? ' is-on' : '' ?>" id="likeBtn" data-id="<?= $id ?>" aria-pressed="<?= $userLiked ? 'true' : 'false' ?>" aria-label="<?= h(t('Me gusta')) ?>">
        <i data-lucide="heart"></i><span><?= h(t('Me gusta')) ?></span><span class="rf-count" id="likeCount"<?= $likes > 0 ? '' : ' hidden' ?>><?= $likes ?></span>
      </button>
      <button type="button" class="ia-btn ia-btn-ghost" id="shareBtn"><i data-lucide="share-2"></i><?= h(t('Compartir')) ?></button>
      </div>
    </div>
  </header>

  <!-- El recurso, funcionando. Los atributos sandbox NO se tocan: son la
       frontera entre el código del autor y la sesión de quien lo mira. -->
  <section class="rf-stage" id="stage" aria-label="<?= h(t('El recurso')) ?>"<?= $embedBlocked ? ' data-blocked="1"' : '' ?>>
    <?php if ($r['code_type'] === 'html'): ?>
      <iframe class="rf-frame" srcdoc="<?= h(iarepo_srcdoc((string) $r['code_content'])) ?>" sandbox="allow-scripts allow-modals allow-popups" title="<?= h($titleText) ?>"></iframe>
    <?php elseif ($r['code_type'] === 'url'): ?>
      <?php if ($embedBlocked): ?>
        <div class="rf-fallback">
          <i data-lucide="external-link" class="rf-fallback-ico" aria-hidden="true"></i>
          <p><?= h(t('Este sitio no deja que se muestre dentro de iarepo. Ábrelo en su web original: funciona igual.')) ?></p>
          <?php if ($openUrl !== ''): ?><a href="<?= h($openUrl) ?>" target="_blank" rel="noopener" class="ia-btn ia-btn-primary ia-btn-lg"><i data-lucide="external-link"></i><?= h(t('Abrir en')) ?> <?= h($openName) ?></a><?php endif; ?>
        </div>
      <?php else: ?>
        <iframe class="rf-frame" id="previewIframe" src="<?= h($linkUrl) ?>" title="<?= h($titleText) ?>"></iframe>
        <div class="rf-fallback" id="iframeFallback" hidden>
          <i data-lucide="external-link" class="rf-fallback-ico" aria-hidden="true"></i>
          <p><?= h(t('Este sitio bloqueó la vista previa. Ábrelo en su web original: funciona igual.')) ?></p>
          <a href="<?= h($linkUrl) ?>" target="_blank" rel="noopener" class="ia-btn ia-btn-primary ia-btn-lg"><i data-lucide="external-link"></i><?= h(t('Abrir en')) ?> <?= h($openName) ?></a>
        </div>
      <?php endif; ?>
    <?php elseif ($r['code_type'] === 'embed'): ?>
      <iframe class="rf-frame" srcdoc="<?= h(iarepo_srcdoc((string) $r['code_content'])) ?>" sandbox="allow-scripts allow-modals allow-popups allow-forms" title="<?= h($titleText) ?>"></iframe>
    <?php else: ?>
      <pre class="rf-code"><?= h((string) $r['code_content']) ?></pre>
    <?php endif; ?>
    <button type="button" class="rf-exit" id="stageExit"><i data-lucide="minimize-2"></i><?= h(t('Salir de pantalla completa')) ?></button>
  </section>
  <?php if ($r['code_type'] === 'url' && !$embedBlocked): ?>
    <p class="rf-stage-note"><?= h(t('¿No se ve bien?')) ?> <a href="<?= h($linkUrl) ?>" target="_blank" rel="noopener"><?= h(t('Ábrelo en')) ?> <?= h($openName) ?></a></p>
  <?php endif; ?>

  <div class="rf-layout">
    <div class="rf-col">
      <?php if ($lineageRoot || $versions): ?>
      <!-- ── El linaje ───────────────────────────────────────────────
           Un fork deja de ser "otro recurso suelto en el catálogo" y pasa a
           verse como lo que es: otra versión de lo mismo. Los forks NO se
           esconden del catálogo a propósito — si alguien mejora un recurso de
           verdad, esconderlo bajo el original lo castiga. Se agrupan, no se
           ocultan. -->
      <section class="rf-block" id="versiones" aria-labelledby="versionsTitle">
        <div class="rf-block-head">
          <h2 id="versionsTitle"><i data-lucide="git-branch" aria-hidden="true"></i><?= h(t('Otras versiones')) ?><?= $versionCount > 0 ? ' (' . $versionCount . ')' : '' ?></h2>
        </div>
        <ul class="rf-list">
          <?php if ($lineageRoot): ?>
            <!-- Esta página ES una versión derivada: el original va primero y
                 marcado, para que se sepa de dónde viene lo que estás mirando. -->
            <li class="rf-origin"><?= rf_row(['id' => $lineageRoot['id'], 'title' => $lineageRoot['title']] + $r, t('Original de') . ' ' . $lineageRoot['author_display_name']) ?></li>
          <?php endif; ?>
          <?php foreach ($versions as $v): ?>
            <li class="rf-version">
              <?= rf_row(['id' => $v['id'], 'title' => ((int) $v['is_recommended'] ? '★ ' : '') . $v['title']] + $r,
                         $v['author_display_name'] . ' · ' . rf_date((string) $v['created_at'])
                         . ((int) $v['is_recommended'] ? ' · ' . t('Versión recomendada por el autor del original') : '')) ?>
              <?php if ($canRecommend): ?>
                <!-- Sólo lo ve el autor de la raíz. La API lo comprueba igual:
                     ocultar el botón no protege nada. -->
                <button type="button" class="ia-btn ia-btn-ghost ia-btn-sm rec-btn" data-id="<?= (int)$v['id'] ?>">
                  <?= (int)$v['is_recommended'] ? h(t('Quitar recomendación')) : h(t('Recomendar esta versión')) ?>
                </button>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$versions): ?>
          <p class="ia-muted ia-small"><?= h(t('Aún no hay otras versiones públicas.')) ?></p>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($nextSteps): ?>
      <!-- «Siguiente paso»: lo que un alumno o un autodidacta abre después. -->
      <section class="rf-block" id="siguiente" aria-labelledby="nextTitle">
        <div class="rf-block-head">
          <h2 id="nextTitle"><i data-lucide="footprints" aria-hidden="true"></i><?= h(t('Siguiente paso')) ?></h2>
          <a class="ia-small" href="<?= h($nextHref) ?>"><?= h(t('Ver todo')) ?> <?= h($r['category_label']) ?> →</a>
          <p class="ia-muted ia-small"><?= h(t('Más de')) ?> <?= h($r['category_label']) ?><?= $r['level_label'] !== '' ? ' · ' . h($r['level_label']) : '' ?></p>
        </div>
        <ul class="rf-list">
          <?php foreach ($nextSteps as $s): ?><li><?= rf_row($s) ?></li><?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <?php if ($byAuthor): ?>
      <section class="rf-block" aria-labelledby="authorTitle">
        <div class="rf-block-head">
          <h2 id="authorTitle"><i data-lucide="user-round" aria-hidden="true"></i><?= h(t('Más de')) ?> <?= h($authorName) ?></h2>
          <a class="ia-small" href="/profile/<?= (int)$r['author_user_id'] ?>"><?= h(t('Ver perfil →')) ?></a>
        </div>
        <ul class="rf-list">
          <?php foreach ($byAuthor as $s): ?><li><?= rf_row($s) ?></li><?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- Comentarios: escriben solo docentes (api/comments.php lo exige; los
           alumnos son menores y no publican con su nombre). Leer, cualquiera. -->
      <section class="rf-block" id="comentarios" aria-labelledby="commentsTitle">
        <div class="rf-block-head">
          <h2 id="commentsTitle"><i data-lucide="message-circle" aria-hidden="true"></i><?= h(t('Comentarios')) ?> <span id="commentCount" class="ia-muted" style="font-weight:500"></span></h2>
        </div>
        <?php if ($isTeacher): ?>
          <div class="rf-comment-form">
            <label class="ia-sr-only" for="commentBody"><?= h(t('Tu comentario')) ?></label>
            <textarea class="ia-input" id="commentBody" maxlength="2000" placeholder="<?= h(t('Comparte una idea, sugerencia o cómo usas este recurso...')) ?>"></textarea>
            <button type="button" class="ia-btn ia-btn-primary" id="postComment"><?= h(t('Enviar')) ?></button>
          </div>
        <?php elseif ($isStudent): ?>
          <p class="rf-note"><?= h(t('Los comentarios son para docentes.')) ?></p>
        <?php else: ?>
          <p class="rf-note"><a href="<?= h($signinUrl . rawurlencode('#comentarios')) ?>"><?= h(t('Entra para comentar')) ?></a></p>
        <?php endif; ?>
        <div id="commentsList"></div>
      </section>
    </div>

    <aside class="rf-aside">
      <?php if ($isOwner): ?>
      <!-- Al AUTOR sí se le enseñan sus cifras reales, también las bajas. -->
      <section class="rf-card" aria-labelledby="ownerTitle">
        <h2 id="ownerTitle"><i data-lucide="badge-check" aria-hidden="true"></i><?= h(t('Es tu recurso')) ?></h2>
        <dl class="rf-owner-stats">
          <div title="<?= h(t('Visitas únicas')) ?>: <?= $viewsUnique ?> · <?= h(t('histórico anterior')) ?>: <?= $viewsLegacy ?>"><dt><?= h(t('Aperturas')) ?></dt><dd><?= iarepo_num($opens) ?></dd></div>
          <div><dt><?= h(t('Usos en clase')) ?></dt><dd><?= iarepo_num($uses) ?></dd></div>
          <div><dt><?= h(t('Me gusta')) ?></dt><dd><?= iarepo_num($likes) ?></dd></div>
          <div title="<?= h(t('Versiones públicas de este recurso')) ?>"><dt><?= h(t('Versiones')) ?></dt><dd><?= $versionCount ?></dd></div>
        </dl>
        <div class="ia-row-gap">
          <a href="/dashboard/editor.php?id=<?= $id ?>" class="ia-btn ia-btn-secondary ia-btn-sm"><i data-lucide="pencil"></i><?= h(t('Editar')) ?></a>
          <button type="button" class="ia-btn ia-btn-ghost ia-btn-sm" id="deleteResBtn" style="color:var(--ia-danger)"><i data-lucide="trash-2"></i><?= h(t('Eliminar')) ?></button>
        </div>
      </section>
      <?php endif; ?>

      <?php if (!$isStudent): ?>
      <!-- Para docentes. El rol student no ve «Hacer mi versión» ni «Lo usé
           en clase» (y la API se lo impide igual: requireRole). -->
      <section class="rf-card" aria-labelledby="teachTitle">
        <h2 id="teachTitle"><i data-lucide="graduation-cap" aria-hidden="true"></i><?= h(t('Para docentes')) ?></h2>
        <ul class="rf-teach-list">
          <li>
            <?php if ($user): ?>
            <!-- La señal de profesor. Deshabilitado si ya se registró hoy: el
                 índice UNIQUE lo rechazaría igual, pero es mejor no ofrecer un
                 clic que sólo puede devolver un 409. -->
            <button type="button" class="rf-teach-item used-btn<?= $usedInClassToday ? ' is-used' : '' ?>" id="usedBtn" data-id="<?= $id ?>"<?= $usedInClassToday ? ' disabled' : '' ?>>
              <span class="rf-ico"><i data-lucide="circle-check"></i></span>
              <span><strong id="usedBtnLabel"><?= $usedInClassToday ? h(t('Registrado hoy')) : h(t('Lo usé en clase')) ?></strong>
              <small><?= h(t('Así otros docentes saben que funciona en un aula de verdad.')) ?></small></span>
            </button>
            <?php else: ?>
            <a class="rf-teach-item" href="<?= h($signinUrl) ?>">
              <span class="rf-ico"><i data-lucide="circle-check"></i></span>
              <span><strong><?= h(t('Lo usé en clase')) ?></strong><small><?= h(t('Entra para marcarlo.')) ?></small></span>
            </a>
            <?php endif; ?>
          </li>
          <?php if ($canFork): ?>
          <li>
            <button type="button" class="rf-teach-item" id="forkBtn" data-id="<?= $id ?>">
              <span class="rf-ico"><i data-lucide="copy-plus"></i></span>
              <span><strong><?= h(t('Hacer mi versión')) ?></strong><small><?= h(t('Una copia para adaptarla, en tu panel y en borrador.')) ?></small></span>
            </button>
          </li>
          <?php endif; ?>
          <li>
            <button type="button" class="rf-teach-item" id="embedBtn">
              <span class="rf-ico"><i data-lucide="code-xml"></i></span>
              <span><strong><?= h(t('Insertar en tu aula virtual')) ?></strong><small><?= h(t('Moodle, Google Classroom, Google Sites…')) ?></small></span>
            </button>
          </li>
          <?php if ($sourceUrl !== ''): ?>
          <li>
            <a class="rf-teach-item" href="<?= h($sourceUrl) ?>" target="_blank" rel="noopener">
              <span class="rf-ico"><i data-lucide="external-link"></i></span>
              <span><strong><?= h(t('Ver fuente')) ?></strong><small><?= h($sourceHost) ?></small></span>
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </section>
      <?php endif; ?>

      <section class="rf-card" aria-labelledby="detailsTitle">
        <h2 id="detailsTitle"><i data-lucide="info" aria-hidden="true"></i><?= h(t('Detalles')) ?></h2>
        <dl class="rf-details">
          <div><dt><?= h(t('Materia')) ?></dt><dd><?= h($r['category_label']) ?></dd></div>
          <?php if ($r['level_label'] !== ''): ?><div><dt><?= h(t('Nivel')) ?></dt><dd><?= h($r['level_label']) ?></dd></div><?php endif; ?>
          <?php if ($r['lang_label'] !== ''): ?><div><dt><?= h(t('Idioma')) ?></dt><dd><?= h($r['lang_label']) ?></dd></div><?php endif; ?>
          <div><dt><?= h(t('Cómo se abre')) ?></dt><dd><?= h($r['opens_label']) ?></dd></div>
          <?php if ($r['source_label']): ?><div><dt><?= h(t('Fuente')) ?></dt><dd><?= $sourceUrl !== '' ? '<a href="' . h($sourceUrl) . '" target="_blank" rel="noopener">' . h((string) ($r['source_name'] ?: $r['source_label'])) . '</a>' : h((string) $r['source_label']) ?></dd></div><?php endif; ?>
          <div><dt><?= $r['source_label'] ? h(t('Elegido por')) : h(t('Autor')) ?></dt><dd><?= h($authorName) ?></dd></div>
          <?php if (trim((string) $r['topic_tag']) !== ''): ?><div><dt><?= h(t('Tema')) ?></dt><dd><?= h(str_replace(',', ', ', (string) $r['topic_tag'])) ?></dd></div><?php endif; ?>
          <div><dt><?= h(t('Añadido')) ?></dt><dd><?= h(rf_date((string) $r['created_at'])) ?></dd></div>
          <?php if ((int) $r['current_version'] > 1): ?><div><dt><?= h(t('Versión')) ?></dt><dd>v<?= (int)$r['current_version'] ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($resourceTags): ?>
        <!-- Cada etiqueta busca en el catálogo (la portada lee ?search=; el
             antiguo ?tag= no lo leía nadie y el enlace no filtraba nada). -->
        <div class="rf-topics">
          <?php foreach ($resourceTags as $tag): ?>
            <a class="ia-tag" href="/?search=<?= rawurlencode((string) $tag) ?>">#<?= h((string) $tag) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($r['source_prompt']): ?>
        <details class="rf-prompt">
          <summary><?= h(t('Prompt original')) ?></summary>
          <pre><?= h((string) $r['source_prompt']) ?></pre>
        </details>
        <?php endif; ?>
      </section>
    </aside>
  </div>
</main>

<?php iarepo_footer($sessionUser); ?>

<!-- Mandar a mis alumnos (QR + dirección corta + Classroom): shared/ui.php -->
<?php iarepo_send_dialog(); ?>

<!-- Compartir en redes: secundario. Enlaces construidos aquí, sin JS. -->
<dialog class="ia-dialog" id="shareDlg" aria-labelledby="shareTitle">
  <div class="ia-dialog-inner">
    <div class="ia-dialog-head">
      <h2 id="shareTitle"><?= h(t('Compartir')) ?></h2>
      <button type="button" class="ia-btn ia-btn-ghost ia-btn-icon" data-dialog-close aria-label="<?= h(t('Cerrar')) ?>"><i data-lucide="x"></i></button>
    </div>
    <?php $shareText = $titleText . ' — iarepo'; ?>
    <div class="rf-share">
      <a class="ia-btn ia-btn-secondary" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($shareText . "\n" . $shareUrl) ?>">WhatsApp</a>
      <a class="ia-btn ia-btn-secondary" target="_blank" rel="noopener" href="https://t.me/share/url?url=<?= rawurlencode($shareUrl) ?>&amp;text=<?= rawurlencode($shareText) ?>">Telegram</a>
      <a class="ia-btn ia-btn-secondary" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?= rawurlencode($shareText) ?>&amp;url=<?= rawurlencode($shareUrl) ?>">X (Twitter)</a>
      <a class="ia-btn ia-btn-secondary" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($shareUrl) ?>">Facebook</a>
      <a class="ia-btn ia-btn-secondary" target="_blank" rel="noopener" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode($shareUrl) ?>">LinkedIn</a>
      <button type="button" class="ia-btn ia-btn-secondary" id="shareCopy"><i data-lucide="link"></i><?= h(t('Copiar enlace')) ?></button>
    </div>
  </div>
</dialog>

<!-- Insertar en tu aula virtual -->
<dialog class="ia-dialog" id="embedDlg" aria-labelledby="embedTitle">
  <div class="ia-dialog-inner">
    <div class="ia-dialog-head">
      <h2 id="embedTitle"><?= h(t('Insertar en tu aula virtual')) ?></h2>
      <button type="button" class="ia-btn ia-btn-ghost ia-btn-icon" data-dialog-close aria-label="<?= h(t('Cerrar')) ?>"><i data-lucide="x"></i></button>
    </div>
    <div class="rf-embed-sizes" role="group" aria-label="<?= h(t('Tamaño')) ?>">
      <button type="button" class="ia-chip" data-size="responsive" aria-pressed="true"><?= h(t('Responsivo')) ?></button>
      <button type="button" class="ia-chip" data-size="medium" aria-pressed="false"><?= h(t('Mediano')) ?></button>
      <button type="button" class="ia-chip" data-size="large" aria-pressed="false"><?= h(t('Grande')) ?></button>
    </div>
    <label class="ia-sr-only" for="embedCode"><?= h(t('Código para insertar')) ?></label>
    <textarea class="rf-embed-code" id="embedCode" readonly></textarea>
    <div class="ia-dialog-actions"><button type="button" class="ia-btn ia-btn-primary" id="embedCopyBtn"><i data-lucide="copy"></i><?= h(t('Copiar código')) ?></button></div>
    <p class="ia-note"><?= h(t('Pega este código en cualquier página HTML, Moodle, Google Sites o Notion. El recurso se mostrará en modo presentación completo.')) ?></p>
  </div>
</dialog>

<!-- ── «¿Te quedó claro?» ──────────────────────────────────────────
     NO es una valoración. Un menor contestando delante de su profesor no
     puede decir con honestidad si algo "le gustó"; sí puede decir si lo
     entendió — y eso es lo que el profesor quería saber de verdad.
     Se muestra sólo tras uso real (evento iarepo:engaged de track.js) y
     api/feedback.php vuelve a comprobarlo contra la BD: ocultarlo aquí no
     protege nada. Sin texto libre a propósito: un campo abierto rellenado
     por menores es contenido que habría que moderar.
     Tras contestar se ofrece, EN EL CLIENTE y sin mandar nada más, a dónde
     ir: otra versión o el siguiente paso si se perdió; el siguiente paso si
     le quedó claro. -->
<div class="cmp-prompt" id="cmpPrompt" role="dialog" aria-live="polite" aria-labelledby="cmpQ">
  <button type="button" class="ia-btn ia-btn-ghost ia-btn-icon ia-btn-sm cmp-close" id="cmpClose" aria-label="<?= h(t('Cerrar')) ?>"><i data-lucide="x"></i></button>
  <p id="cmpQ"><?= h(t('¿Te quedó claro este recurso?')) ?></p>
  <div class="cmp-opts">
    <button type="button" class="cmp-opt" data-answer="claro"><?= h(t('Me quedó claro')) ?></button>
    <button type="button" class="cmp-opt" data-answer="regular"><?= h(t('Más o menos')) ?></button>
    <button type="button" class="cmp-opt" data-answer="perdido"><?= h(t('Me perdí')) ?></button>
  </div>
  <div class="cmp-follow" id="cmpFollow" hidden>
    <?php if ($lineageRoot || $versions): ?>
      <a href="#versiones" data-when="regular perdido"><i data-lucide="git-branch"></i><?= h(t('Prueba otra versión de esto')) ?></a>
    <?php endif; ?>
    <?php if ($nextSteps): ?>
      <a href="/resource/<?= (int) $nextSteps[0]['id'] ?>" data-when="claro regular perdido"><i data-lucide="footprints"></i><span><?= h(t('Siguiente paso')) ?>: <?= h((string) $nextSteps[0]['title']) ?></span></a>
    <?php endif; ?>
  </div>
</div>

<?= iarepo_body_assets(true) ?>
<script>
const RID = <?= $id ?>;
const IS_AUTH = <?= $isAuth ? 'true' : 'false' ?>;
const IS_STUDENT = <?= $isStudent ? 'true' : 'false' ?>;
const TITLE = <?= json_encode($titleText, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const OPEN_URL = <?= json_encode($openUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const SIGNIN = <?= json_encode($signinUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const SHARE_URL = <?= json_encode($shareUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const LOCALE = <?= json_encode(lang()) ?>;
const T = <?= json_encode([
    'confirmFork'   => t('¿Hacer tu propia versión? Se guarda como borrador privado en tu panel y la abrirás en el editor.'),
    'forkDone'      => t('Tu versión está lista. Abriendo el editor…'),
    'forkError'     => t('No se pudo crear tu versión'),
    'confirmDelete' => t('¿Eliminar este recurso? Esta acción no se puede deshacer.'),
    'deleteError'   => t('No se pudo eliminar el recurso'),
    'noComments'    => t('Aún no hay comentarios.'),
    'commentError'  => t('No se pudo publicar el comentario'),
    'sending'       => t('Enviando…'),
    'send'          => t('Enviar'),
    'copied'        => t('¡Copiado!'),
    'copyCode'      => t('Copiar código'),
    'linkCopied'    => t('Enlace copiado'),
    'favSaved'      => t('Guardado. Solo tú lo ves, en Guardados.'),
    'favRemoved'    => t('Quitado de Guardados'),
    'loginToSave'   => t('Entra para guardarlo: te traemos de vuelta aquí.'),
    'likeError'     => t('No se pudo marcar «Me gusta»'),
    'usedConfirm'   => t('¿Confirmas que usaste este recurso en una clase?'),
    'usedDone'      => t('¡Registrado! Esto ayuda a otros profesores a encontrarlo.'),
    'usedAlready'   => t('Ya registraste este uso hoy'),
    'usedLabelDone' => t('Registrado hoy'),
    'usedError'     => t('No se pudo registrar el uso'),
    'recAdd'        => t('Recomendar esta versión'),
    'recRemove'     => t('Quitar recomendación'),
    'recDone'       => t('Versión recomendada ★'),
    'recUndone'     => t('Recomendación retirada'),
    'recError'      => t('No se pudo cambiar la recomendación'),
    'cmpThanks'     => t('¡Gracias! Esto ayuda a mejorar el recurso.'),
    'cmpError'      => t('No se pudo enviar tu respuesta'),
    'cmpNext'       => t('Para seguir:'),
    'openedOutside' => t('Este sitio no se puede proyectar desde iarepo: lo abrimos en su web, en otra pestaña.'),
    'noLists'       => t('Aún no tienes listas. Crea la primera aquí abajo.'),
    'listsError'    => t('No se pudieron cargar tus listas'),
    'addedTo'       => t('Añadido a'),
    'alreadyIn'     => t('Ya estaba en'),
    'listLimit'     => t('Has llegado al máximo de 20 listas'),
    'listError'     => t('No se pudo añadir a la lista'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

const $ = (id) => document.getElementById(id);
const toast = (msg) => IA.toast(msg);
/** POST con JSON (o sin cuerpo) → respuesta JSON; lanza con el código de la API. */
async function postJSON(url, body) {
  const opt = { method: 'POST' };
  if (body !== undefined) { opt.headers = { 'Content-Type': 'application/json' }; opt.body = JSON.stringify(body); }
  const res = await fetch(url, opt);
  // Sesión caducada a mitad de visita: a entrar y volver aquí, no un aviso en inglés de la API.
  if (res.status === 401) { toSignin(); throw new Error(''); }
  let data = null;
  try { data = await res.json(); } catch (e) { data = null; }
  if (!data) throw new Error('HTTP ' + res.status);
  return data;
}
const toSignin = () => { location.href = SIGNIN; };

// ── Proyectar ─────────────────────────────────────────────────
// Un clic: el contenedor del recurso a pantalla completa. Si el sitio no se
// deja embeber (iframe_blocked, o el iframe se quedó vacío), abrir la web
// original en otra pestaña y DECIRLO, en vez de proyectar una caja vacía.
// Sin Fullscreen API (iPhone) se simula con .is-full; Esc o el botón salen.
const stage = $('stage');
const stageExit = $('stageExit');
let idleTimer = null;
function isBlocked() { return stage.dataset.blocked === '1'; }
function wakeExit() {
  stageExit.classList.remove('is-idle');
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => stageExit.classList.add('is-idle'), 3000);
}
function enterPseudo() { stage.classList.add('is-full'); document.body.classList.add('rf-noscroll'); wakeExit(); }
function leavePseudo() { stage.classList.remove('is-full'); document.body.classList.remove('rf-noscroll'); }
$('projectBtn').addEventListener('click', () => {
  if (isBlocked()) {
    if (OPEN_URL) window.open(OPEN_URL, '_blank', 'noopener');
    toast(T.openedOutside);
    return;
  }
  const rfs = stage.requestFullscreen || stage.webkitRequestFullscreen;
  if (!rfs) { enterPseudo(); return; }
  try {
    const p = rfs.call(stage);
    if (p && p.catch) p.catch(enterPseudo);
    wakeExit();
  } catch (e) { enterPseudo(); }
});
stageExit.addEventListener('click', () => {
  if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
  else if (document.webkitFullscreenElement && document.webkitExitFullscreen) document.webkitExitFullscreen();
  leavePseudo();
});
stage.addEventListener('mousemove', wakeExit);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && stage.classList.contains('is-full')) leavePseudo(); });

// Un iframe de 'url' que el sitio bloquea no avisa: se detecta como puede
// (error, o documento vacío accesible) y se pasa al modo «abrir fuera».
(function () {
  const frame = $('previewIframe');
  const fallback = $('iframeFallback');
  if (!frame || !fallback) return;
  let loaded = false;
  const block = () => { frame.hidden = true; fallback.hidden = false; stage.dataset.blocked = '1'; IA.icons(); };
  frame.addEventListener('load', () => { loaded = true; });
  frame.addEventListener('error', block);
  setTimeout(() => {
    if (!loaded) return;
    try {
      const doc = frame.contentDocument || frame.contentWindow.document;
      if (!doc || !doc.body || doc.body.innerHTML === '') block();
    } catch (e) { /* otro origen: cargó y no se puede mirar dentro; se deja */ }
  }, 4000);
})();

// ── Mandar a mis alumnos (diálogo común de shared/ui.php) ──────
const sendBtn = $('sendBtn');
if (sendBtn) sendBtn.addEventListener('click', () => IA.openSend({ id: RID, title: TITLE, copiedMsg: T.linkCopied }));

// ── Guardar (Guardados = favoritos: privado, un clic) ──────────
$('favBtn').addEventListener('click', async () => {
  const btn = $('favBtn');
  if (!IS_AUTH) {
    // Invitado: guarda la intención en localStorage (sobrevive el redirect de
    // Google, a diferencia del query/cookie) y vuelve a ESTE recurso tras login.
    // La aplica assets/js/pwa.js al volver.
    try { localStorage.setItem('iarepo_pending_fav', JSON.stringify({ id: RID, ret: location.pathname + location.search })); } catch (e) {}
    toast(T.loginToSave);
    location.href = `/auth/signin.php?save=${RID}&return_url=${encodeURIComponent(location.pathname + location.search)}`;
    return;
  }
  btn.disabled = true;
  try {
    const data = await postJSON(`/api/favorites.php?id=${RID}`);
    if (!data.ok) throw new Error(data.error);
    btn.classList.toggle('is-on', !!data.favorited);
    btn.setAttribute('aria-pressed', data.favorited ? 'true' : 'false');
    toast(data.favorited ? T.favSaved : T.favRemoved);
  } catch (e) { toast(e.message); }
  finally { btn.disabled = false; }
});

// ── Me gusta ──────────────────────────────────────────────────
// El número solo se enseña si es mayor que cero: nada de «♥ 0».
$('likeBtn').addEventListener('click', async () => {
  if (!IS_AUTH) { toSignin(); return; }
  const btn = $('likeBtn');
  btn.disabled = true;
  try {
    const data = await postJSON(`/api/likes.php?id=${RID}`);
    if (!data.ok) throw new Error(data.error || T.likeError);
    const n = parseInt(data.like_count, 10) || 0;
    const c = $('likeCount');
    c.textContent = n; c.hidden = n < 1;
    btn.classList.toggle('is-on', !!data.user_liked);
    btn.setAttribute('aria-pressed', data.user_liked ? 'true' : 'false');
  } catch (e) { toast(e.message || T.likeError); }
  finally { btn.disabled = false; }
});

// ── Hacer mi versión (el fork; solo html/embed) ────────────────
const forkBtnEl = $('forkBtn');
if (forkBtnEl) forkBtnEl.addEventListener('click', async () => {
  if (!IS_AUTH) { toSignin(); return; }
  if (!confirm(T.confirmFork)) return;
  forkBtnEl.disabled = true;
  try {
    const data = await postJSON(`/api/resources.php?action=fork&id=${RID}`);
    if (!data.ok || !data.id) throw new Error(data.error || T.forkError);
    toast(T.forkDone);
    setTimeout(() => { location = '/dashboard/editor.php?id=' + data.id; }, 700);
  } catch (e) { toast(e.message || T.forkError); forkBtnEl.disabled = false; }
});

// "Lo usé en clase" — la señal de profesor.
//
// El botón sólo se pinta para docentes autenticados, pero eso es cosmética:
// quien mande el POST a mano se topa con requireRole() en api/usage.php. Aquí
// no se defiende nada, sólo se evita ofrecer un clic que va a fallar.
//
// Pide confirmación a propósito: es una AFIRMACIÓN sobre lo que hiciste en tu
// clase, no un "me gusta". La fricción es la que hace que el dato valga.
const usedBtnEl = $('usedBtn');
if (usedBtnEl) usedBtnEl.addEventListener('click', async () => {
  if (!confirm(T.usedConfirm)) return;
  usedBtnEl.disabled = true;
  try {
    // Este endpoint lee el cuerpo con json_body(), no query params como
    // likes/fork: sin el Content-Type y el JSON responde 400 INVALID_JSON.
    const res = await fetch('/api/usage.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ resource_id: RID, usage_type: 'presented' })
    });
    const data = await res.json();
    if (!data.ok) {
      // 409 no es un fallo: es la dedup diaria funcionando. Se trata como
      // éxito en la UI —el botón queda marcado igual— porque para el docente
      // "ya estaba registrado" y "acabo de registrarlo" son el mismo estado.
      if (data.code==='ALREADY_RECORDED') { markUsed(T.usedAlready); return; }
      throw new Error(data.error || T.usedError);
    }
    // El contador público solo existe si pasa el umbral (IAREPO_PROOF_MIN_TEACHERS).
    const uc = $('useCount');
    if (uc && typeof data.use_count === 'number') uc.textContent = data.use_count;
    markUsed(T.usedDone);
  } catch (e) {
    toast(e.message || T.usedError);
    usedBtnEl.disabled = false;
  }
});
function markUsed(msg) {
  usedBtnEl.classList.add('is-used');
  usedBtnEl.disabled = true;
  $('usedBtnLabel').textContent = T.usedLabelDone;
  toast(msg);
}

// Recomendar una versión (sólo el autor del recurso raíz)
//
// El botón sólo se pinta para él, pero eso es cosmética: la API comprueba la
// autoría de la RAÍZ y responde 403 a cualquier otro. Si pudiera destacarse
// cada autor su propio fork, "recomendada" significaría "su autor pulsó un
// botón" y el distintivo perdería todo su valor.
document.querySelectorAll('.rec-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const data = await postJSON(`/api/resources.php?action=recommend&id=${btn.dataset.id}`);
      if (!data.ok) throw new Error(data.error || T.recError);
      btn.textContent = data.is_recommended ? T.recRemove : T.recAdd;
      toast(data.is_recommended ? T.recDone : T.recUndone);
    } catch (e) { toast(e.message || T.recError); }
    finally { btn.disabled = false; }
  });
});

// «¿Te quedó claro?» — se arma cuando track.js confirma uso real.
//
// Una sola vez por carga y recordando en localStorage que ya se contestó, para
// no volver a preguntar en cada visita. La verdad la impone la clave PRIMARY de
// resource_comprehension (una respuesta por persona, recurso y día); esto sólo
// evita ser pesado.
(function () {
  const box = $('cmpPrompt');
  if (!box) return;
  const seenKey = 'iarepo_cmp_' + RID;
  try { if (localStorage.getItem(seenKey)) return; } catch (e) {}

  let vid = null;
  document.addEventListener('iarepo:engaged', function (ev) {
    if (ev.detail && ev.detail.resourceId !== RID) return;
    vid = ev.detail ? ev.detail.vid : null;
    box.classList.add('open');
  });

  // Tras contestar: enlaces útiles según la respuesta, pintados en el
  // servidor y filtrados aquí. No se manda nada más.
  function done(msg, answer) {
    try { localStorage.setItem(seenKey, '1'); } catch (e) {}
    toast(msg);
    const follow = $('cmpFollow');
    const links = follow ? Array.from(follow.querySelectorAll('a[data-when]')) : [];
    let any = false;
    links.forEach((a) => { const on = a.dataset.when.split(' ').includes(answer); a.hidden = !on; any = any || on; });
    if (!any) { box.classList.remove('open'); return; }
    box.querySelector('.cmp-opts').hidden = true;
    $('cmpQ').textContent = T.cmpNext;
    follow.hidden = false;
    follow.addEventListener('click', () => box.classList.remove('open'));
  }

  $('cmpClose').addEventListener('click', function () {
    // Cerrar sin contestar también se recuerda: insistir a quien ya dijo que
    // no es la forma más rápida de que deje de leer cualquier cosa del sitio.
    try { localStorage.setItem(seenKey, '1'); } catch (e) {}
    box.classList.remove('open');
  });

  box.querySelectorAll('.cmp-opt').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      box.querySelectorAll('.cmp-opt').forEach((b) => { b.disabled = true; });
      try {
        const res = await fetch('/api/feedback.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ resource_id: RID, vid: vid, answer: btn.dataset.answer })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || T.cmpError);
        done(T.cmpThanks, btn.dataset.answer);
      } catch (e) {
        box.querySelectorAll('.cmp-opt').forEach((b) => { b.disabled = false; });
        toast(e.message || T.cmpError);
      }
    });
  });
})();

// Eliminar (solo el autor: el botón solo se pinta para él y la API lo exige)
const deleteResBtn = $('deleteResBtn');
if (deleteResBtn) deleteResBtn.addEventListener('click', async () => {
  if (!confirm(T.confirmDelete)) return;
  deleteResBtn.disabled = true;
  try {
    const res = await fetch(`/api/resources.php?id=${RID}`, { method: 'DELETE' });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || T.deleteError);
    window.location = '/dashboard/';
  } catch (e) { toast(e.message || T.deleteError); deleteResBtn.disabled = false; }
});

// ── Comentarios ───────────────────────────────────────────────
function commentHtml(c, extraClass) {
  const avatar = c.user_avatar ? `<img src="${IA.esc(c.user_avatar)}" alt="" referrerpolicy="no-referrer">` : '';
  const d = new Date(String(c.created_at).replace(' ', 'T'));
  const date = isNaN(d) ? '' : d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
  const replies = (c.replies || []).map((r) => commentHtml(r, '')).join('');
  return `<article class="rf-comment ${extraClass}"><div class="rf-comment-head">${avatar}<strong>${IA.esc(c.user_name)}</strong><time>${IA.esc(date)}</time></div>`
       + `<div class="rf-comment-body">${IA.esc(c.body)}</div>${replies ? '<div class="rf-replies">' + replies + '</div>' : ''}</article>`;
}
async function loadComments() {
  const list = $('commentsList');
  try {
    const res = await fetch(`/api/comments.php?resource_id=${RID}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'comments');
    // Sin «(0)»: el número solo aparece si hay algo que contar.
    $('commentCount').textContent = data.total > 0 ? `(${data.total})` : '';
    list.innerHTML = data.comments.length
      ? data.comments.map((c) => commentHtml(c, '')).join('')
      : `<p class="ia-muted">${IA.esc(T.noComments)}</p>`;
  } catch (e) {
    // Se deja constancia (error_tracker.php recoge los errores de consola no
    // capturados; este sí se captura, así que se registra a mano).
    console.error('comentarios:', e);
  }
}
const postBtn = $('postComment');
if (postBtn) postBtn.addEventListener('click', async () => {
  const body = $('commentBody').value.trim();
  if (!body) return;
  postBtn.disabled = true; postBtn.textContent = T.sending;
  try {
    const data = await postJSON('/api/comments.php', { resource_id: RID, body });
    if (!data.ok) throw new Error(data.error || T.commentError);
    $('commentBody').value = '';
    loadComments();
  } catch (e) { toast(e.message || T.commentError); }
  finally { postBtn.disabled = false; postBtn.textContent = T.send; }
});
loadComments();

// ── Añadir a una lista ────────────────────────────────────────
// Listas = curaduría (tabla collections), distintas de Guardados (favoritos):
// NO se unifican, solo se renombran. «+ Nueva lista» la crea aquí mismo y
// guarda el recurso en ella, sin mandar al panel.
(function () {
  const btn = $('saveCollBtn');
  const menu = $('collDropdown');
  if (!btn || !menu) return;
  const items = $('collItems');
  let loaded = false;

  function setOpen(open) {
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function render(colls) {
    if (!colls.length) { items.innerHTML = `<p class="rf-menu-empty">${IA.esc(T.noLists)}</p>`; return; }
    // Sin HTML inline con el título: data-id + delegación. Un título con
    // apóstrofo rompía el manejador en línea que había antes (con el título dentro).
    items.innerHTML = colls.map((c) =>
      `<button type="button" class="rf-menu-item" data-coll="${parseInt(c.id, 10)}"><i data-lucide="list"></i><span>${IA.esc(c.title)}</span><span class="rf-menu-n">${parseInt(c.item_count, 10) || ''}</span></button>`
    ).join('');
    IA.icons();
  }
  async function load() {
    try {
      const res = await fetch('/api/collections.php');
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);
      loaded = true;
      render(data.collections || []);
    } catch (e) {
      items.innerHTML = `<p class="rf-menu-empty">${IA.esc(T.listsError)}</p>`;
      console.error('listas:', e);
    }
  }
  async function addTo(collId, title) {
    try {
      const data = await postJSON(`/api/collections.php?action=add&id=${collId}`, { resource_id: RID });
      if (data.ok) toast(`${T.addedTo} «${title}»`);
      else if (data.code === 'ALREADY_IN_COLLECTION') toast(`${T.alreadyIn} «${title}»`);
      else throw new Error(data.error || T.listError);
      setOpen(false);
      loaded = false;   // el recuento cambió: se recarga al volver a abrir
    } catch (e) { toast(e.message || T.listError); }
  }

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = menu.hidden;
    setOpen(open);
    if (open && !loaded) load();
  });
  items.addEventListener('click', (e) => {
    const b = e.target.closest('[data-coll]');
    if (b) addTo(parseInt(b.dataset.coll, 10), b.querySelector('span').textContent);
  });
  $('newListForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = $('newListTitle');
    const title = input.value.trim();
    if (!title) return;
    try {
      // Una lista es pública por defecto (es curaduría). La de un alumno
      // nace privada: es menor y no tiene por qué publicar con su nombre.
      const data = await postJSON('/api/collections.php', { title, is_public: IS_STUDENT ? 0 : 1 });
      if (!data.ok) throw new Error(data.code === 'COLLECTION_LIMIT' ? T.listLimit : (data.error || T.listError));
      input.value = '';
      await addTo(data.id, title);
    } catch (err) { toast(err.message || T.listError); }
  });
  menu.addEventListener('click', (e) => e.stopPropagation());
  document.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) { setOpen(false); btn.focus(); } });
})();

// ── Insertar en tu aula virtual ───────────────────────────────
(function () {
  const dlg = $('embedDlg');
  const open = $('embedBtn');
  if (!dlg || !open) return;
  const SIZES = { responsive: { w: '100%', h: '500' }, medium: { w: '640', h: '480' }, large: { w: '100%', h: '700' } };
  let size = 'responsive';
  const code = () => `<iframe\n  src="https://iarepo.com/view/${RID}?mode=present"\n  width="${SIZES[size].w}" height="${SIZES[size].h}"\n  frameborder="0" allowfullscreen\n  title="${IA.esc(TITLE)}"\n  style="border:none;border-radius:8px">\n</iframe>`;
  const paint = () => {
    $('embedCode').value = code();
    dlg.querySelectorAll('[data-size]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.size === size ? 'true' : 'false'));
  };
  open.addEventListener('click', () => { paint(); dlg.showModal ? dlg.showModal() : dlg.setAttribute('open', ''); IA.icons(); });
  dlg.querySelectorAll('[data-size]').forEach((b) => b.addEventListener('click', () => { size = b.dataset.size; paint(); }));
  $('embedCopyBtn').addEventListener('click', () => {
    IA.copy($('embedCode').value).then(() => toast(T.copied), () => toast(T.copyCode));
  });
})();

// ── Compartir (secundario) ────────────────────────────────────
// En móvil, la hoja nativa del sistema; si no existe, el diálogo con redes.
$('shareBtn').addEventListener('click', () => {
  if (navigator.share && window.matchMedia('(max-width: 639px)').matches) {
    navigator.share({ title: TITLE, url: SHARE_URL }).catch(() => {});   // cancelar no es un error
    return;
  }
  const dlg = $('shareDlg');
  dlg.showModal ? dlg.showModal() : dlg.setAttribute('open', '');
});
$('shareCopy').addEventListener('click', () => {
  IA.copy(SHARE_URL).then(() => toast(T.linkCopied), () => toast(SHARE_URL));
});
</script>
</body>
</html>
