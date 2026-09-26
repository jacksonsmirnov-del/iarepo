<?php
// ================================================================
// api/resources.php — Resources CRUD API
//
// Endpoints:
//   GET    /api/resources.php              List resources (filtered)
//   GET    /api/resources.php?id=X         Get single resource
//   POST   /api/resources.php              Create resource
//   PUT    /api/resources.php?id=X         Update resource (creates version)
//   POST   /api/resources.php?action=fork&id=X   Fork a resource
//   POST   /api/resources.php?action=recommend&id=X  Destacar una version
//          (alterna is_recommended · SOLO el autor del recurso raiz)
//   DELETE /api/resources.php?id=X         Soft-delete resource
//
// Auth: JWT required for all write operations.
//       Read operations check visibility rules.
//
// Crear y editar (POST / PUT) aceptan también la fuente original:
//   source_name  texto de una línea, ≤ 150
//   source_url   solo http(s) absoluta, ≤ 500 (INVALID_SOURCE_URL si no)
// En un recurso code_type='url', code_content tiene que ser una dirección
// http(s) (INVALID_URL) y, si no se manda source_url, ella es la fuente. La
// lista negra de URLs retiradas se aplica al crear y al editar. Cada rechazo
// lleva su código: dashboard/editor.php traduce por código, no por texto
// (tests/unit/account_pages_test.php exige que cada código tenga su texto).
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/moderation.php';
require_once __DIR__ . '/../shared/notify.php';
require_once __DIR__ . '/../shared/search.php';
require_once __DIR__ . '/../shared/access.php';
require_once __DIR__ . '/../shared/labels.php';

cors();

// ── ?lang= aquí es el FILTRO, no el idioma de la interfaz ─────
// En esta API '?lang=' filtra el catálogo por idioma del RECURSO (lo mandan
// así la portada y Campus). Pero shared/i18n.php::lang() lee $_GET['lang']
// como IDIOMA DE LA INTERFAZ y, si lo ve, planta una cookie `lang` de un año.
// Desde que iarepo_with_labels() traduce con t() [2026-09], pulsar «Inglés»
// en los filtros de la portada pasaba la web entera a inglés en la siguiente
// carga y mandaba las etiquetas en inglés. Se aparta el filtro ANTES de que
// nada llame a t(): las etiquetas siguen la cookie o Accept-Language, como
// las páginas. tests/integration/api_lang_test.php lo prueba por HTTP.
$filterLang = iarepo_get_str('lang');
unset($_GET['lang']);

$method = request_method();
$db = getResourcesDB();

if ($method === 'GET') rateLimit($db, 'resources_get', 120);
elseif (in_array($method, ['POST', 'PUT', 'DELETE'])) rateLimit($db, 'resources_write', 30);

// ── GET: List or single resource ─────────────────────────────
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);

    if ($id) {
        // Single resource
        $stmt = $db->prepare("
            SELECT r.*,
                   c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
                   (SELECT COUNT(*) FROM resource_versions WHERE resource_id = r.id) AS version_count,
                   (SELECT COUNT(*) FROM resource_usage WHERE resource_id = r.id) AS total_uses
            FROM resources r
            LEFT JOIN categories c ON r.category_id = c.id
            WHERE r.id = ? AND r.is_active = 1
        ");
        $stmt->execute([$id]);
        $resource = $stmt->fetch();
        if (!$resource)
            json_error('Resource not found', 404);

        // Visibility check
        $user = authenticate();
        if (!canView($resource, $user))
            json_error('Access denied', 403);

        // Las visitas ya NO se cuentan aquí [2026-08-06]. `view_count` queda
        // congelado como marca histórica; la métrica viva es `unique_views`,
        // que escribe api/track.php con deduplicación por persona y día.
        //
        // Este incremento era especialmente engañoso: sumaba una visita por
        // cada LECTURA de la API, incluidas las que hace la propia aplicación
        // para pintar una ficha, sin que ningún humano hubiera mirado nada.
        //
        // Consecuencia asumida: una integración que sólo consuma la API sin
        // cargar assets/js/track.js no aparece en las visitas. Es correcto —
        // una lectura de máquina no es una visita— y las incrustaciones sí
        // cuentan, porque apuntan a /viewer/, que lleva el beacon.

        // Fetch tags
        $tags = $db->prepare("SELECT tag FROM resource_tags WHERE resource_id = ? ORDER BY tag");
        $tags->execute([$id]);
        $resource['tags'] = $tags->fetchAll(PDO::FETCH_COLUMN);

        json_ok(['resource' => iarepo_with_labels($resource)]);
    }

    // List resources with filters
    $user = authenticate(); // Optional — affects visibility
    $where = ['r.is_active = 1', "(r.link_status IS NULL OR r.link_status != 'broken')"];
    $params = [];

    // ── Visibility filter ────────────────────────────────────
    if ($user) {
        $tenantId = $user['tenant_id'] ?? 0;
        $userId = $user['user_id'] ?? 0;
        $areas = $user['areas'] ?? [];

        // User can see:
        // 1. Their own drafts
        // 2. Area-level from same tenant + same area
        // 3. School-level from same tenant
        // 4. Community (all)
        $visClauses = [
            "(r.visibility = 'community')",
            "(r.visibility = 'school' AND r.author_tenant_id = ?)",
            "(r.visibility = 'area' AND r.author_tenant_id = ?)",
            "(r.visibility = 'draft' AND r.author_tenant_id = ? AND r.author_user_id = ?)",
        ];
        $where[] = '(' . implode(' OR ', $visClauses) . ')';
        $params[] = $tenantId; // school
        $params[] = $tenantId; // area
        $params[] = $tenantId; // draft
        $params[] = $userId;   // draft
    } else {
        // Unauthenticated: only community resources
        $where[] = "r.visibility = 'community'";
    }

    // ── Optional filters ─────────────────────────────────────
    // OJO: se compara con '' y NO se usa !empty(): empty('0') es true,
    // así que ?search=0 (o cualquier filtro con valor "0") se ignoraba
    // en silencio y devolvía el catálogo entero.
    if (iarepo_get_str('area') !== '') {
        $where[] = 'r.subject_area = ?';
        $params[] = sanitize(iarepo_get_str('area'), 100);
    }
    if ((int) iarepo_get_str('category') > 0) {
        $where[] = 'r.category_id = ?';
        $params[] = (int) iarepo_get_str('category');
    }
    if ($filterLang !== '') {   // apartado arriba, antes de cualquier t()
        $where[] = 'r.lang = ?';
        $params[] = sanitize($filterLang, 5);
    }
    if (iarepo_get_str('level') !== '') {
        $where[] = 'r.level = ?';
        $params[] = sanitize(iarepo_get_str('level'), 50);
    }
    if (iarepo_get_str('type') !== '') {
        $where[] = 'r.code_type = ?';
        $params[] = sanitize(iarepo_get_str('type'), 20);
    }
    if (iarepo_get_str('tag') !== '') {
        $where[] = 'r.id IN (SELECT resource_id FROM resource_tags WHERE tag = ?)';
        $params[] = sanitize(iarepo_get_str('tag'), 50);
    }
    if ((int) iarepo_get_str('author_tenant_id') > 0) {
        $where[] = 'r.author_tenant_id = ?';
        $params[] = (int) iarepo_get_str('author_tenant_id');
    }
    if (iarepo_get_str('visibility') !== '') {
        $where[] = 'r.visibility = ?';
        $params[] = sanitize(iarepo_get_str('visibility'), 20);
    }

    // ── Búsqueda de texto ────────────────────────────────────
    // Todo el saneado y la construcción del SQL viven en shared/search.php
    // (lista blanca: el input crudo NUNCA llega a AGAINST → se acabaron
    //  los 500 con "C++", "(ondas", "@"...). Ver la cabecera de ese archivo.
    $search = iarepo_build_search(iarepo_get_str('search'));
    $hasSearch = $search['mode'] !== 'none';
    if ($hasSearch) {
        $where[] = $search['where'];
        foreach ($search['params'] as $p)
            $params[] = $p;
    }

    // ── Sort ──────────────────────────────────────────────────
    // ESTA es la pieza que manda sobre el orden: es la única que ordena filas
    // de verdad y publica el orden aplicado en la respuesta ('search.sort').
    // index.php (render del <select>) y su JS se limitan a replicar esta regla.
    //
    // Regla, en una línea: un 'sort' NO reconocido se trata como AUSENTE, no
    // como una elección explícita del cliente.
    //   ?sort=title            → title            (elección válida: se respeta)
    //   ?search=x              → relevance        (defecto con búsqueda)
    //   ?sort=bogus&search=x   → relevance        (bogus ≡ no venía)
    //   ?sort=bogus            → recent           (defecto sin búsqueda)
    // Antes, 'bogus' contaba como explícito y caía a 'recent' mientras el
    // desplegable de index.php (y el JS, que no puede meter un valor inexistente
    // en el <select>) mostraban "Más relevantes": el usuario leía una mentira.
    // Los deep-links ?sort= con valor válido no cambian en nada.
    //
    // El '?, r.id' final de cada ORDER BY es el desempate determinista: sin él,
    // dos filas empatadas pueden intercambiarse entre la página 1 y la 2
    // (duplicar una, perder otra).
    $sortAllowed = ['relevance', 'recent', 'popular', 'views', 'title'];
    $sortRaw     = iarepo_get_str('sort');
    if (!in_array($sortRaw, $sortAllowed, true)) $sortRaw = '';
    $sortKey = $sortRaw !== '' ? $sortRaw : ($hasSearch ? 'relevance' : 'recent');
    $useRelevance = ($sortKey === 'relevance' && $hasSearch);
    $sort = match (true) {
        $useRelevance          => '_relevance DESC, r.view_count DESC, r.id DESC',
        $sortKey === 'popular' => 'r.use_count DESC, r.id DESC',
        $sortKey === 'views'   => 'r.view_count DESC, r.id DESC',
        $sortKey === 'title'   => 'r.title ASC, r.id ASC',
        default                => 'r.created_at DESC, r.id DESC',
    };
    $sortEffective = $useRelevance
        ? 'relevance'
        : (in_array($sortKey, ['popular', 'views', 'title'], true) ? $sortKey : 'recent');

    // ── Pagination ────────────────────────────────────────────
    // El tope de $page NO es cosmético: $offset se interpola en el SQL, y sin
    // él ?page=99999999999999999999 desbordaba el int → float → "OFFSET
    // 1.844674407371E+20" → error de sintaxis 1064 → HTTP 500 (preexistente).
    $page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereSQL = implode(' AND ', $where);

    // Count total — SIN el score, y por tanto SIN score_params.
    $countStmt = $db->prepare("SELECT COUNT(*) FROM resources r WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // El score va en el SELECT y el SELECT precede al WHERE ⇒ sus
    // parámetros van DELANTE (PDO sin emulación: '?' es posicional de verdad).
    $relSelect  = '';
    $pageParams = $params;
    if ($useRelevance) {
        $relSelect  = ",\n               " . $search['score'] . ' AS _relevance';
        $pageParams = array_merge($search['score_params'], $params);
    }

    // Fetch page
    $stmt = $db->prepare("
        SELECT r.id, r.title, r.description, r.code_type, r.subject_area, r.topic_tag,
               r.lang, r.level, r.category_id, r.view_count, r.source_prompt,
               r.author_display_name, r.author_tenant_name, r.visibility,
               r.current_version, r.use_count, r.fork_count, r.fork_of,
               r.source_name, r.source_url,
               IF(r.code_type = 'url', r.code_content, NULL) AS link_url,   -- fuente de los enlaces sin source_url (labels.php)
               r.created_at, r.updated_at,
               c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
               (SELECT COUNT(*) FROM resource_likes rl WHERE rl.resource_id = r.id) AS like_count,
               (SELECT GROUP_CONCAT(tag ORDER BY tag SEPARATOR ',') FROM resource_tags rt WHERE rt.resource_id = r.id) AS tags_csv{$relSelect}
        FROM resources r
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE $whereSQL
        ORDER BY $sort
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($pageParams);
    $resources = $stmt->fetchAll();

    foreach ($resources as &$res) {
        $res['tags'] = $res['tags_csv'] ? explode(',', $res['tags_csv']) : [];
        unset($res['tags_csv']);
        unset($res['_relevance']); // detalle interno: no ensucia el JSON
        // Etiquetas ya calculadas y traducidas (shared/labels.php): campos
        // NUEVOS, así que Campus no nota nada; la portada no duplica lógica.
        $res = iarepo_with_labels($res);
        unset($res['link_url']); // solo servía para deducir la fuente: el contrato no cambia
    }
    unset($res);

    // Get categories for filter UI.
    // El recuento excluye los enlaces rotos, igual que el listado: antes la
    // píldora prometía más recursos de los que luego aparecían.
    $categories = $db->query("
        SELECT c.id, c.name, c.slug, c.icon, COUNT(r.id) AS resource_count
        FROM categories c
        LEFT JOIN resources r ON r.category_id = c.id AND r.is_active = 1 AND r.visibility = 'community'
             AND (r.link_status IS NULL OR r.link_status != 'broken')
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY c.display_order
    ")->fetchAll();
    foreach ($categories as &$cat) {
        $cat['label']         = iarepo_category_label($cat['slug'], $cat['name']);
        $cat['subject_class'] = iarepo_subject_class($cat['slug']);
    }
    unset($cat);

    json_ok([
        'resources' => $resources,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
        'categories' => $categories,
        // Para resaltar coincidencias en el frontend y diagnosticar sin acceso a la BD.
        'search' => [
            'mode'  => $search['mode'],
            'terms' => $search['terms'],
            'sort'  => $sortEffective,
        ],
    ]);
}

// ── POST: Create or Fork ──────────────────────────────────────
if ($method === 'POST') {
    $user = requireAuth();
    requireRole($user, ['teacher', 'admin', 'superadmin']);

    $action = $_GET['action'] ?? 'create';

    if ($action === 'fork') {
        // Fork an existing resource
        $originalId = (int) ($_GET['id'] ?? 0);
        if (!$originalId)
            json_error('Missing resource ID to fork');

        $orig = $db->prepare("SELECT * FROM resources WHERE id = ? AND is_active = 1");
        $orig->execute([$originalId]);
        $original = $orig->fetch();
        if (!$original)
            json_error('Original resource not found', 404);
        if (!canView($original, $user))
            json_error('Cannot fork: access denied', 403);

        // Raíz del linaje. Un fork de un fork hereda la raíz del padre, de modo
        // que "todas las versiones de X" siempre es `WHERE root_id = X` sin
        // recorrer la cadena. Si el padre aún no la tiene resuelta —copia sin
        // migrar— se cae a su propio id, que es lo que vale para un original.
        $rootId = (int) ($original['root_id'] ?? 0) ?: $originalId;

        $db->beginTransaction();
        try {
            // Create fork
            //
            // El título ya NO lleva el prefijo 'Fork: '. Ensuciaba la tarjeta
            // del catálogo ("Fork: Simple Harmonic Motion") y era información
            // redundante: la relación con el original ahora es un dato del
            // linaje (root_id / fork_of) que la ficha pinta como "otras
            // versiones", no algo que haya que meter dentro del nombre.
            $stmt = $db->prepare("
                INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag,
                    author_tenant_id, author_user_id, author_display_name, author_tenant_name,
                    visibility, fork_of, root_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
            ");
            $stmt->execute([
                $original['title'],
                $original['description'],
                $original['code_content'],
                $original['code_type'],
                $original['subject_area'],
                $original['topic_tag'],
                $user['tenant_id'],
                $user['user_id'],
                $user['name'],
                $user['tenant_name'],
                $originalId,
                $rootId,
            ]);
            $forkId = (int) $db->lastInsertId();

            // Update fork count on original
            //
            // Cuenta TODOS los forks, incluidos los que se quedan en 'draft'
            // —que son casi todos, porque nacen privados—. Es un dato interno
            // correcto, pero NO es lo que debe ver el usuario: la ficha decía
            // "12 Forks" y al pinchar aparecían 2, porque el resto son
            // borradores ajenos e invisibles. La ficha cuenta hoy las versiones
            // PÚBLICAS, que son las que se pueden abrir (resource/index.php).
            $db->prepare("UPDATE resources SET fork_count = fork_count + 1 WHERE id = ?")
                ->execute([$originalId]);

            // Record usage
            $db->prepare("
                INSERT INTO resource_usage (resource_id, user_id, tenant_id, user_display_name, tenant_name, usage_type)
                VALUES (?, ?, ?, ?, ?, 'forked')
            ")->execute([$originalId, $user['user_id'], $user['tenant_id'], $user['name'], $user['tenant_name']]);

            $db->commit();

            // Notify the original author about the fork (best-effort, never blocks).
            notifyResourceAuthor($db, $originalId, (int) $user['user_id'], (string) $user['name'], 'fork');

            json_ok(['id' => $forkId, 'message' => 'Resource forked successfully']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();

            // El detalle al log, un mensaje genérico al cliente. Concatenar
            // $e->getMessage() entregaba el error crudo de MariaDB —nombres de
            // tabla, de columna y fragmentos de consulta— a cualquiera capaz de
            // provocar un fallo. Mismo saneado que api/usage.php; los dos los
            // vigila tests/unit/usage_signal_test.php.
            api_log('error', 'fork failed', [
                'original_id' => $originalId,
                'error'       => $e->getMessage(),
            ]);
            json_error('Could not fork resource', 500, 'FORK_FAILED');
        }
    }

    // ── POST ?action=recommend&id=X — la bendición del autor ──────
    //
    // El autor del recurso RAÍZ destaca una versión derivada. Es el pull
    // request de los pobres, y existe para resolver un problema concreto del
    // ranking: por conteo bruto de visitas o likes, el original gana SIEMPRE
    // —lleva años acumulando y un fork mejor publicado ayer empieza en cero—,
    // así que forkear no podría salir rentable nunca. Esto le da a una versión
    // un camino al primer puesto que no es un concurso de popularidad.
    //
    // Encaja además con cómo piensa un profesor de verdad: "la versión que hizo
    // María está mejor que la mía".
    if ($action === 'recommend') {
        $targetId = (int) ($_GET['id'] ?? 0);
        if (!$targetId) json_error('Missing resource ID');

        $t = $db->prepare("SELECT id, root_id, fork_of, visibility, is_recommended
                           FROM resources WHERE id = ? AND is_active = 1");
        $t->execute([$targetId]);
        $target = $t->fetch();
        if (!$target) json_error('Resource not found', 404);

        $rootId = (int) ($target['root_id'] ?? 0) ?: (int) ($target['fork_of'] ?? 0) ?: $targetId;

        // Un original no se destaca a sí mismo: ya es la referencia por
        // defecto. Marcarlo no significaría nada y ensuciaría el listado.
        if ($rootId === $targetId) json_error('An original cannot be recommended', 400, 'IS_ROOT');

        // Sólo el autor de la RAÍZ, no el del fork. Si pudiera destacarse uno
        // solo, "recomendada" pasaría a significar "su autor pulsó un botón" y
        // el distintivo perdería todo su valor de golpe.
        $r = $db->prepare("SELECT author_user_id, author_tenant_id FROM resources WHERE id = ?");
        $r->execute([$rootId]);
        $root = $r->fetch();
        if (!$root) json_error('Root resource not found', 404);

        if ((int) $root['author_user_id'] !== (int) $user['user_id']
            || (int) $root['author_tenant_id'] !== (int) ($user['tenant_id'] ?? 0)) {
            json_error('Only the author of the original can recommend a version', 403);
        }

        // Sólo se destacan versiones PÚBLICAS: recomendar un borrador ajeno
        // pondría en la ficha un enlace que nadie más puede abrir.
        if ($target['visibility'] !== 'community') {
            json_error('Only public versions can be recommended', 400, 'NOT_PUBLIC');
        }

        try {
            $new = ((int) $target['is_recommended']) === 1 ? 0 : 1;
            $db->prepare("UPDATE resources SET is_recommended = ? WHERE id = ?")
               ->execute([$new, $targetId]);
            json_ok(['id' => $targetId, 'is_recommended' => $new]);
        } catch (Throwable $e) {
            api_log('error', 'recommend failed', ['resource_id' => $targetId, 'error' => $e->getMessage()]);
            json_error('Could not update the recommendation', 500, 'RECOMMEND_FAILED');
        }
    }

    // Create new resource
    //
    // Cada rechazo lleva su CÓDIGO: el editor (dashboard/editor.php) traduce
    // por código, nunca por el texto, que es para Campus y los logs.
    $data = json_body();
    $title = is_string($data['title'] ?? null) ? sanitize($data['title'], 255) : '';
    if (!$title)
        json_error('Title is required', 400, 'MISSING_TITLE');

    $codeContent = is_string($data['code_content'] ?? null) ? $data['code_content'] : '';
    $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
    $validTypes = ['html', 'url', 'embed', 'python', 'prompt', 'other'];
    $codeType = in_array($data['code_type'] ?? '', $validTypes, true) ? $data['code_type'] : 'html';

    // ── Fuente original (source_name / source_url) ──
    // Lo que acredita a PhET, NASA… en la ficha («Creado por …») y de lo que
    // depende la lista negra de URLs de abajo. Solo http(s): un javascript:
    // aquí acabaría en un href de la ficha.
    $sourceName = iarepo_clean_source_name($data['source_name'] ?? null);
    $sourceUrl  = iarepo_clean_source_url($data['source_url'] ?? null);
    if ($sourceName === null)
        json_error('source_name must be a string', 400, 'INVALID_SOURCE_NAME');
    if ($sourceUrl === null)
        json_error('source_url must be an http(s) URL', 400, 'INVALID_SOURCE_URL');

    // Un recurso 'url' ES una dirección: se exige http(s) y, si no se dio
    // otra fuente, esa dirección es la fuente. Así la lista negra funciona
    // aunque el cliente no mande source_url.
    if ($codeType === 'url') {
        $link = iarepo_clean_source_url($codeContent);
        if ($link === null || $link === '')
            json_error('A url resource needs an http(s) address in code_content', 400, 'INVALID_URL');
        $codeContent = $link;
        if ($sourceUrl === '')
            $sourceUrl = $link;
    }

    // ── Moderation checks (only when OPEN_REGISTRATION is enabled) ──
    if (!checkRateLimit($db, $user['user_id']))
        json_error('Daily limit reached: 5 new resources per day', 429, 'DAILY_LIMIT');

    $hash = $codeContent ? contentHash($codeContent) : null;

    // Safety net: block the same author from re-posting identical content.
    // Runs ALWAYS (even with moderation off) — stops accidental duplicates from
    // double-submits / network retries. Author should edit the existing one instead.
    if ($hash) {
        $selfDup = $db->prepare("
            SELECT id, title FROM resources
            WHERE content_hash = ? AND author_user_id = ? AND author_tenant_id = ? AND is_active = 1
            LIMIT 1
        ");
        $selfDup->execute([$hash, $user['user_id'], $user['tenant_id']]);
        $mine = $selfDup->fetch();
        if ($mine)
            json_error("Ya publicaste este mismo contenido: \"{$mine['title']}\" (ID: {$mine['id']})", 409, 'DUPLICATE_OWN');
    }

    // Fast check: exact duplicate across all authors (1 query, instant)
    if ($hash && isModerationEnabled()) {
        $dup = $db->prepare("SELECT id, title FROM resources WHERE content_hash = ? AND is_active = 1 LIMIT 1");
        $dup->execute([$hash]);
        $existing = $dup->fetch();
        if ($existing)
            json_error("Contenido duplicado del recurso \"{$existing['title']}\" (ID: {$existing['id']})", 409, 'DUPLICATE_CONTENT');
    }

    // ── Blacklist check: prevent re-uploading broken/retired URLs ──
    if ($sourceUrl !== '' && $codeType === 'url')
        iarepo_reject_blacklisted_url($db, $sourceUrl);

    // Heavy similarity check is deferred to cron (setup/cron_moderation.php)
    $moderationStatus = isModerationEnabled() ? 'pending_review' : 'approved';

    $stmt = $db->prepare("
        INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag,
            lang, level, category_id, source_prompt,
            author_tenant_id, author_user_id, author_display_name, author_tenant_name,
            visibility, content_hash, moderation_status, source_name, source_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $title,
        sanitize(iarepo_body_str($data, 'description'), 2000),
        $codeContent,
        $codeType,
        sanitize(iarepo_body_str($data, 'subject_area'), 100),
        sanitize(iarepo_body_str($data, 'topic_tag'), 100),
        in_array($data['lang'] ?? '', ['es', 'en', 'pt'], true) ? $data['lang'] : 'es',
        sanitize(iarepo_body_str($data, 'level') ?: 'general', 50),
        $categoryId,
        is_string($data['source_prompt'] ?? null) ? $data['source_prompt'] : null,
        $user['tenant_id'],
        $user['user_id'],
        $user['name'],
        $user['tenant_name'],
        in_array($data['visibility'] ?? '', ['draft', 'area', 'school', 'community'], true) ? $data['visibility'] : 'draft',
        $hash,
        $moderationStatus,
        $sourceName !== '' ? $sourceName : null,
        $sourceUrl !== '' ? $sourceUrl : null,
    ]);

    $newId = (int) $db->lastInsertId();

    // Save tags
    if (!empty($data['tags']) && is_array($data['tags'])) {
        $tagStmt = $db->prepare("INSERT IGNORE INTO resource_tags (resource_id, tag) VALUES (?, ?)");
        foreach (array_slice($data['tags'], 0, 20) as $tag) {
            if (!is_string($tag))
                continue;   // un [ ] o un número aquí era un TypeError → 500
            $tag = sanitize(trim($tag), 50);
            if ($tag) {
                $tagStmt->execute([$newId, strtolower($tag)]);
            }
        }
    }

    // Save initial version
    $db->prepare("
        INSERT INTO resource_versions (resource_id, version_number, code_content, editor_user_id, editor_display_name, editor_tenant_name, change_description)
        VALUES (?, 1, ?, ?, ?, ?, 'Initial version')
    ")->execute([$newId, $codeContent, $user['user_id'], $user['name'], $user['tenant_name']]);

    $response = ['id' => $newId, 'message' => 'Resource created', 'moderation_status' => $moderationStatus];
    if ($moderationStatus === 'pending_review')
        $response['info'] = 'Tu recurso ha sido publicado y será verificado automáticamente en breve.';

    json_ok($response);
}

// ── PUT: Update resource (creates new version) ────────────────
if ($method === 'PUT') {
    $user = requireAuth();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id)
        json_error('Missing resource ID');

    $resource = $db->prepare("SELECT * FROM resources WHERE id = ? AND is_active = 1");
    $resource->execute([$id]);
    $res = $resource->fetch();
    if (!$res)
        json_error('Resource not found', 404);

    // Only the original author can edit. Superadmins can edit anything.
    // Admin-seeded resources (author_user_id=1, author_tenant_id=0) cannot be
    // edited by regular users — they should fork instead.
    $isAuthor = ($res['author_user_id'] == $user['user_id'] && $res['author_tenant_id'] == $user['tenant_id']);
    $isSuperadmin = (($user['role'] ?? '') === 'superadmin');
    if (!$isAuthor && !$isSuperadmin)
        json_error('Only the author can edit this resource (make your own version instead)', 403, 'NOT_AUTHOR');

    $data = json_body();
    $newVersion = (int) $res['current_version'] + 1;

    // ── Entrada saneada ──
    // Tipos, idioma y visibilidad son ENUM: un valor inventado llegaba tal
    // cual al UPDATE, MariaDB lo rechazaba (ERROR 1265) y la persona veía un
    // «no se pudo guardar» genérico. Ahora un valor no válido se ignora
    // ('' = no cambiar, la regla de siempre de este PUT).
    $validTypes = ['html', 'url', 'embed', 'python', 'prompt', 'other'];
    $putType = in_array($data['code_type'] ?? '', $validTypes, true) ? $data['code_type'] : '';
    $putLang = in_array($data['lang'] ?? '', ['es', 'en', 'pt'], true) ? $data['lang'] : '';
    $putVis  = in_array($data['visibility'] ?? '', ['draft', 'area', 'school', 'community'], true) ? $data['visibility'] : '';
    $putCode = is_string($data['code_content'] ?? null) ? $data['code_content'] : null;
    $effectiveType = $putType !== '' ? $putType : (string) $res['code_type'];

    // Un recurso 'url' ES una dirección: solo http(s) (misma regla que al crear).
    if ($effectiveType === 'url' && $putCode !== null) {
        $link = iarepo_clean_source_url($putCode);
        if ($link === null || $link === '')
            json_error('A url resource needs an http(s) address in code_content', 400, 'INVALID_URL');
        $putCode = $link;
    }
    // …también cuando solo cambia el TIPO: un PUT {"code_type":"url"} sobre un
    // recurso html dejaba su contenido («javascript:…», una URL retirada)
    // como dirección sin pasar por ninguna de las dos reglas; Campus la
    // recibe en ?id= y el cron de enlaces se la pasa a curl [revisión 2026-09].
    $typeBecomesUrl = $effectiveType === 'url' && (string) $res['code_type'] !== 'url';
    if ($typeBecomesUrl && $putCode === null) {
        $link = iarepo_clean_source_url((string) $res['code_content']);
        if ($link === null || $link === '')
            json_error('A url resource needs an http(s) address in code_content', 400, 'INVALID_URL');
        $putCode = $link;   // se guarda ya limpia (y pasa por la lista negra, abajo)
    }

    // Fuente original: solo se toca si la clave viene en el cuerpo (así un
    // cliente que no la conoce —Campus— no la borra). Vacía = quitarla.
    $putSourceName = array_key_exists('source_name', $data) ? iarepo_clean_source_name($data['source_name']) : false;
    $putSourceUrl  = array_key_exists('source_url', $data) ? iarepo_clean_source_url($data['source_url']) : false;
    if ($putSourceName === null)
        json_error('source_name must be a string', 400, 'INVALID_SOURCE_NAME');
    if ($putSourceUrl === null)
        json_error('source_url must be an http(s) URL', 400, 'INVALID_SOURCE_URL');

    // Lista negra: también al EDITAR. Si no, bastaba crear con una dirección
    // válida y cambiarla después por una retirada. Una dirección que no cambia
    // no se vuelve a mirar (un recurso ya publicado no se bloquea al editar
    // su título)… salvo si el recurso PASA a ser un enlace: entonces su
    // contenido es una dirección nueva a efectos de la lista.
    if ($effectiveType === 'url') {
        foreach ([$putCode, $putSourceUrl] as $u)
            if (is_string($u) && $u !== '' && ($typeBecomesUrl
                    || ($u !== (string) $res['code_content'] && $u !== (string) $res['source_url'])))
                iarepo_reject_blacklisted_url($db, $u);
    }

    $db->beginTransaction();
    try {
        // Update resource — use explicit COLLATE to avoid collation mismatches
        $stmt = $db->prepare("
            UPDATE resources SET
                title = COALESCE(NULLIF(? COLLATE utf8mb4_unicode_ci, ''), title),
                description = COALESCE(?, description),
                code_content = COALESCE(?, code_content),
                code_type = COALESCE(NULLIF(? COLLATE utf8mb4_unicode_ci, ''), code_type),
                subject_area = COALESCE(NULLIF(? COLLATE utf8mb4_unicode_ci, ''), subject_area),
                topic_tag = COALESCE(NULLIF(? COLLATE utf8mb4_unicode_ci, ''), topic_tag),
                lang = COALESCE(NULLIF(? COLLATE utf8mb4_unicode_ci, ''), lang),
                level = COALESCE(NULLIF(? COLLATE utf8mb4_unicode_ci, ''), level),
                category_id = COALESCE(?, category_id),
                source_prompt = COALESCE(?, source_prompt),
                visibility = COALESCE(NULLIF(? COLLATE utf8mb4_unicode_ci, ''), visibility),
                current_version = ?
            WHERE id = ?
        ");
        $stmt->execute([
            sanitize(iarepo_body_str($data, 'title'), 255),
            is_string($data['description'] ?? null) ? sanitize($data['description'], 2000) : null,
            $putCode,
            $putType,
            sanitize(iarepo_body_str($data, 'subject_area'), 100),
            sanitize(iarepo_body_str($data, 'topic_tag'), 100),
            $putLang,
            sanitize(iarepo_body_str($data, 'level'), 50),
            !empty($data['category_id']) ? (int) $data['category_id'] : null,
            is_string($data['source_prompt'] ?? null) ? $data['source_prompt'] : null,
            $putVis,
            $newVersion,
            $id,
        ]);

        // Fuente original, solo si vino en el cuerpo ('' → NULL: se quita).
        if (is_string($putSourceName))
            $db->prepare("UPDATE resources SET source_name = ? WHERE id = ?")
               ->execute([$putSourceName !== '' ? $putSourceName : null, $id]);
        if (is_string($putSourceUrl))
            $db->prepare("UPDATE resources SET source_url = ? WHERE id = ?")
               ->execute([$putSourceUrl !== '' ? $putSourceUrl : null, $id]);

        // Save version snapshot
        $db->prepare("
            INSERT INTO resource_versions (resource_id, version_number, code_content, editor_user_id, editor_display_name, editor_tenant_name, change_description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
                    $id,
                    $newVersion,
                    $putCode ?? $res['code_content'],
                    $user['user_id'],
                    $user['name'],
                    $user['tenant_name'],
                    sanitize(iarepo_body_str($data, 'change_description') ?: "Version $newVersion", 500),
                ]);

        // Update tags if provided (replace strategy)
        if (isset($data['tags']) && is_array($data['tags'])) {
            $db->prepare("DELETE FROM resource_tags WHERE resource_id = ?")->execute([$id]);
            $tagStmt = $db->prepare("INSERT IGNORE INTO resource_tags (resource_id, tag) VALUES (?, ?)");
            foreach (array_slice($data['tags'], 0, 20) as $tag) {
                if (!is_string($tag) && !is_int($tag))
                    continue;
                $tag = mb_substr(strtolower(trim((string) $tag)), 0, 50);
                if ($tag !== '') $tagStmt->execute([$id, $tag]);
            }
        }

        $db->commit();
        json_ok(['version' => $newVersion, 'message' => 'Resource updated']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();

        // Detalle al log, mensaje genérico al cliente (ver la cabecera de
        // api/usage.php). Este camino es el más delicado de los tres: la
        // actualización toca el contenido del recurso, así que su excepción
        // puede arrastrar fragmentos de lo que el usuario acaba de enviar.
        api_log('error', 'resource update failed', ['resource_id' => $id, 'error' => $e->getMessage()]);
        json_error('Could not update resource', 500, 'UPDATE_FAILED');
    }
}

// ── DELETE: Soft-delete ───────────────────────────────────────
if ($method === 'DELETE') {
    $user = requireAuth();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id)
        json_error('Missing resource ID');

    $resource = $db->prepare("SELECT author_tenant_id, author_user_id FROM resources WHERE id = ? AND is_active = 1");
    $resource->execute([$id]);
    $res = $resource->fetch();
    if (!$res)
        json_error('Resource not found', 404);

    $isAuthor = ($res['author_tenant_id'] == $user['tenant_id'] && $res['author_user_id'] == $user['user_id']);
    if (!$isAuthor && ($user['role'] ?? '') !== 'superadmin') {
        json_error('Only the author can delete this resource', 403);
    }

    $db->prepare("UPDATE resources SET is_active = 0 WHERE id = ?")->execute([$id]);
    json_ok(['message' => 'Resource deleted']);
}

json_error('Method not allowed', 405);

// ══════════════════════════════════════════════════════════════
// HELPER: Lectura segura de parámetros GET
//
// Devuelve '' si el parámetro falta o llega como array (?search[]=x),
// que de otro modo reventaría sanitize(string) con un TypeError → 500.
// Se compara con '' y no con empty(): empty('0') es true y hacía que
// ?search=0 se ignorase en silencio.
//
// El prefijo iarepo_ NO es cosmético: el proyecto no usa namespaces, así
// que cada función de un fichero incluido vive en el espacio global. Un
// nombre genérico como getStr() choca con el primer helper homónimo que
// aparezca en cualquier otro include ("Cannot redeclare"), y ese error es
// fatal: tumba la API entera, no sólo esta rama.
// ══════════════════════════════════════════════════════════════
function iarepo_get_str(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? (string) $v : '';
}

/**
 * Lo mismo para el cuerpo JSON de POST/PUT: '' si falta o no es texto. Un
 * {"title": ["x"]} llegaba a sanitize(string) y era un TypeError → 500.
 */
function iarepo_body_str(array $data, string $key): string
{
    $v = $data[$key] ?? '';
    return is_string($v) || is_int($v) || is_float($v) ? (string) $v : '';
}

// ══════════════════════════════════════════════════════════════
// Fuente original de un recurso (source_name / source_url)
//
// Funciones PURAS a propósito (sin BD, sin json_error): así
// tests/unit/account_pages_test.php las ejecuta de verdad, en un
// subproceso, con las entradas hostiles.
//
// Devuelven '' si el valor falta o viene vacío y null si no es válido; el
// llamador responde 400 con su código.
// ══════════════════════════════════════════════════════════════

/** Nombre de la fuente: texto de una línea, ≤ 150 (VARCHAR(150)). */
function iarepo_clean_source_name(mixed $v): ?string
{
    if ($v === null)
        return '';
    if (!is_string($v))
        return null;
    $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
    return mb_substr($v, 0, 150);
}

/**
 * Dirección http(s) absoluta, ≤ 500 (VARCHAR(500)), sin espacios ni
 * caracteres de control y con host. Nada de javascript:, data:, //host ni
 * rutas relativas: este valor acaba en el href de «Ver fuente» de la ficha
 * y, en un recurso 'url', en el src del visor.
 * El host lo decide iarepo_http_host() (shared/labels.php): ni «\» ni
 * «usuario@», que PHP y el navegador leían distinto y dejaban firmar como
 * PhET un iframe de otra web y saltarse la lista negra [revisión 2026-09].
 */
function iarepo_clean_source_url(mixed $v): ?string
{
    if ($v === null)
        return '';
    if (!is_string($v))
        return null;
    $v = trim($v);
    if ($v === '')
        return '';
    if (mb_strlen($v) > 500 || preg_match('/[\x00-\x20\x7F]/', $v))
        return null;
    return iarepo_http_host($v) !== null ? $v : null;
}

/**
 * Lista negra de URLs retiradas (tabla url_blacklist, la llena el cron de
 * enlaces rotos). Corta con 409 si la dirección —o su dominio, con 3+
 * retiradas— está en ella. Se usa al crear Y al editar.
 */
function iarepo_reject_blacklisted_url(PDO $db, string $url): void
{
    // Check exact URL
    $blStmt = $db->prepare("SELECT url, original_title, reason FROM url_blacklist WHERE url = ? LIMIT 1");
    $blStmt->execute([$url]);
    $blocked = $blStmt->fetch();
    if ($blocked) {
        json_error(
            "URL retirada: \"{$blocked['original_title']}\" fue eliminada por {$blocked['reason']}. Usa otra fuente.",
            409,
            'BLACKLISTED_URL'
        );
    }

    // Check if domain is heavily blacklisted (3+ URLs from same domain)
    $domain = (string) (parse_url($url, PHP_URL_HOST) ?? '');
    if ($domain !== '') {
        $domStmt = $db->prepare("SELECT COUNT(*) FROM url_blacklist WHERE domain = ?");
        $domStmt->execute([$domain]);
        $domCount = (int) $domStmt->fetchColumn();
        if ($domCount >= 3) {
            json_error(
                "Dominio bloqueado: {$domain} tiene {$domCount} URLs retiradas. Este sitio dejó de funcionar.",
                409,
                'BLACKLISTED_DOMAIN'
            );
        }
    }
}
