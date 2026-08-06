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
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/moderation.php';
require_once __DIR__ . '/../shared/notify.php';
require_once __DIR__ . '/../shared/search.php';

cors();

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

        json_ok(['resource' => $resource]);
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
    if (iarepo_get_str('lang') !== '') {
        $where[] = 'r.lang = ?';
        $params[] = sanitize(iarepo_get_str('lang'), 5);
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
    }
    unset($res);

    // Get categories for filter UI
    $categories = $db->query("
        SELECT c.id, c.name, c.slug, c.icon, COUNT(r.id) AS resource_count
        FROM categories c
        LEFT JOIN resources r ON r.category_id = c.id AND r.is_active = 1 AND r.visibility = 'community'
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY c.display_order
    ")->fetchAll();

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
    $data = json_body();
    $title = sanitize($data['title'] ?? '', 255);
    if (!$title)
        json_error('Title is required');

    $codeContent = $data['code_content'] ?? '';
    $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
    $validTypes = ['html', 'url', 'embed', 'python', 'prompt', 'other'];

    // ── Moderation checks (only when OPEN_REGISTRATION is enabled) ──
    if (!checkRateLimit($db, $user['user_id']))
        json_error('Rate limit: máximo 5 recursos por día', 429);

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
            json_error("Contenido duplicado del recurso \"{$existing['title']}\" (ID: {$existing['id']})", 409);
    }

    // ── Blacklist check: prevent re-uploading broken/retired URLs ──
    $sourceUrl = $data['source_url'] ?? '';
    if ($sourceUrl && ($data['code_type'] ?? '') === 'url') {
        // Check exact URL
        $blStmt = $db->prepare("SELECT url, original_title, reason FROM url_blacklist WHERE url = ? LIMIT 1");
        $blStmt->execute([$sourceUrl]);
        $blocked = $blStmt->fetch();
        if ($blocked) {
            json_error(
                "URL retirada: \"{$blocked['original_title']}\" fue eliminada por {$blocked['reason']}. Usa otra fuente.",
                409,
                'BLACKLISTED_URL'
            );
        }

        // Check if domain is heavily blacklisted (3+ URLs from same domain)
        $parsed = parse_url($sourceUrl);
        $domain = $parsed['host'] ?? '';
        if ($domain) {
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

    // Heavy similarity check is deferred to cron (setup/cron_moderation.php)
    $moderationStatus = isModerationEnabled() ? 'pending_review' : 'approved';

    $stmt = $db->prepare("
        INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag,
            lang, level, category_id, source_prompt,
            author_tenant_id, author_user_id, author_display_name, author_tenant_name,
            visibility, content_hash, moderation_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $title,
        sanitize($data['description'] ?? '', 2000),
        $codeContent,
        in_array($data['code_type'] ?? '', $validTypes) ? $data['code_type'] : 'html',
        sanitize($data['subject_area'] ?? '', 100),
        sanitize($data['topic_tag'] ?? '', 100),
        in_array($data['lang'] ?? '', ['es', 'en', 'pt']) ? $data['lang'] : 'es',
        sanitize($data['level'] ?? 'general', 50),
        $categoryId,
        $data['source_prompt'] ?? null,
        $user['tenant_id'],
        $user['user_id'],
        $user['name'],
        $user['tenant_name'],
        in_array($data['visibility'] ?? '', ['draft', 'area', 'school', 'community']) ? $data['visibility'] : 'draft',
        $hash,
        $moderationStatus,
    ]);

    $newId = (int) $db->lastInsertId();

    // Save tags
    if (!empty($data['tags']) && is_array($data['tags'])) {
        $tagStmt = $db->prepare("INSERT IGNORE INTO resource_tags (resource_id, tag) VALUES (?, ?)");
        foreach (array_slice($data['tags'], 0, 20) as $tag) {
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
        json_error('Solo el autor puede editar este recurso. Usa "Fork" para crear tu propia versión.', 403, 'NOT_AUTHOR');

    $data = json_body();
    $newVersion = (int) $res['current_version'] + 1;

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
            sanitize($data['title'] ?? '', 255),
            $data['description'] ?? null,
            $data['code_content'] ?? null,
            $data['code_type'] ?? '',
            sanitize($data['subject_area'] ?? '', 100),
            sanitize($data['topic_tag'] ?? '', 100),
            $data['lang'] ?? '',
            sanitize($data['level'] ?? '', 50),
            !empty($data['category_id']) ? (int) $data['category_id'] : null,
            $data['source_prompt'] ?? null,
            $data['visibility'] ?? '',
            $newVersion,
            $id,
        ]);

        // Save version snapshot
        $db->prepare("
            INSERT INTO resource_versions (resource_id, version_number, code_content, editor_user_id, editor_display_name, editor_tenant_name, change_description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
                    $id,
                    $newVersion,
                    $data['code_content'] ?? $res['code_content'],
                    $user['user_id'],
                    $user['name'],
                    $user['tenant_name'],
                    sanitize($data['change_description'] ?? "Version $newVersion", 500),
                ]);

        // Update tags if provided (replace strategy)
        if (isset($data['tags']) && is_array($data['tags'])) {
            $db->prepare("DELETE FROM resource_tags WHERE resource_id = ?")->execute([$id]);
            $tagStmt = $db->prepare("INSERT IGNORE INTO resource_tags (resource_id, tag) VALUES (?, ?)");
            foreach (array_slice($data['tags'], 0, 20) as $tag) {
                $tag = strtolower(trim((string) $tag));
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

// ══════════════════════════════════════════════════════════════
// HELPER: Visibility check
// ══════════════════════════════════════════════════════════════
function canView(array $resource, ?array $user): bool
{
    $vis = $resource['visibility'];

    if ($vis === 'community')
        return true;
    if (!$user)
        return false;

    $authorTenant = $resource['author_tenant_id'];
    $userTenant = $user['tenant_id'] ?? 0;
    $userId = $user['user_id'] ?? 0;

    return match ($vis) {
        'school' => $userTenant === $authorTenant,
        'area' => $userTenant === $authorTenant, // All teachers in tenant can see — promotes collaboration
        'draft' => $userTenant === $authorTenant && $userId == $resource['author_user_id'],
        default => false,
    };
}
