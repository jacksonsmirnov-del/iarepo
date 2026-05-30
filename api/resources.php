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

        // Increment view count
        $db->prepare("UPDATE resources SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);

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
    if (!empty($_GET['area'])) {
        $where[] = 'r.subject_area = ?';
        $params[] = sanitize($_GET['area'], 100);
    }
    if (!empty($_GET['category'])) {
        $where[] = 'r.category_id = ?';
        $params[] = (int) $_GET['category'];
    }
    if (!empty($_GET['lang'])) {
        $where[] = 'r.lang = ?';
        $params[] = sanitize($_GET['lang'], 5);
    }
    if (!empty($_GET['level'])) {
        $where[] = 'r.level = ?';
        $params[] = sanitize($_GET['level'], 50);
    }
    if (!empty($_GET['type'])) {
        $where[] = 'r.code_type = ?';
        $params[] = sanitize($_GET['type'], 20);
    }
    if (!empty($_GET['tag'])) {
        $where[] = 'r.id IN (SELECT resource_id FROM resource_tags WHERE tag = ?)';
        $params[] = sanitize($_GET['tag'], 50);
    }
    if (!empty($_GET['search'])) {
        $where[] = 'MATCH(r.title, r.description, r.topic_tag) AGAINST(? IN BOOLEAN MODE)';
        $params[] = sanitize($_GET['search'], 200);
    }
    if (!empty($_GET['author_tenant_id'])) {
        $where[] = 'r.author_tenant_id = ?';
        $params[] = (int) $_GET['author_tenant_id'];
    }
    if (!empty($_GET['visibility'])) {
        $where[] = 'r.visibility = ?';
        $params[] = sanitize($_GET['visibility'], 20);
    }

    // ── Sort ──────────────────────────────────────────────────
    $sort = match ($_GET['sort'] ?? 'recent') {
        'popular' => 'r.use_count DESC',
        'views'   => 'r.view_count DESC',
        'title'   => 'r.title ASC',
        default   => 'r.created_at DESC',
    };

    // ── Pagination ────────────────────────────────────────────
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereSQL = implode(' AND ', $where);

    // Count total
    $countStmt = $db->prepare("SELECT COUNT(*) FROM resources r WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

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
               (SELECT GROUP_CONCAT(tag ORDER BY tag SEPARATOR ',') FROM resource_tags rt WHERE rt.resource_id = r.id) AS tags_csv
        FROM resources r
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE $whereSQL
        ORDER BY $sort
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $resources = $stmt->fetchAll();

    foreach ($resources as &$res) {
        $res['tags'] = $res['tags_csv'] ? explode(',', $res['tags_csv']) : [];
        unset($res['tags_csv']);
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

        $db->beginTransaction();
        try {
            // Create fork
            $stmt = $db->prepare("
                INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag,
                    author_tenant_id, author_user_id, author_display_name, author_tenant_name,
                    visibility, fork_of)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
            ");
            $stmt->execute([
                'Fork: ' . $original['title'],
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
            ]);
            $forkId = (int) $db->lastInsertId();

            // Update fork count on original
            $db->prepare("UPDATE resources SET fork_count = fork_count + 1 WHERE id = ?")
                ->execute([$originalId]);

            // Record usage
            $db->prepare("
                INSERT INTO resource_usage (resource_id, user_id, tenant_id, user_display_name, tenant_name, usage_type)
                VALUES (?, ?, ?, ?, ?, 'forked')
            ")->execute([$originalId, $user['user_id'], $user['tenant_id'], $user['name'], $user['tenant_name']]);

            $db->commit();
            json_ok(['id' => $forkId, 'message' => 'Resource forked successfully']);
        } catch (Throwable $e) {
            $db->rollBack();
            json_error('Fork failed: ' . $e->getMessage(), 500);
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

    // Fast check: exact duplicate (1 query, instant)
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
        $db->rollBack();
        json_error('Update failed: ' . $e->getMessage(), 500);
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
