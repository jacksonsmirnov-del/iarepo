<?php
// ================================================================
// api/collections.php — Collections API
//
// GET    /api/collections.php              My collections
// GET    /api/collections.php?user_id=X    User's public collections
// GET    /api/collections.php?id=X         Single collection with items
// POST   /api/collections.php              Create collection
// PUT    /api/collections.php?id=X         Update collection
// DELETE /api/collections.php?id=X         Delete collection
//
// POST   /api/collections.php?action=add&id=X       Add resource to collection
// POST   /api/collections.php?action=remove&id=X    Remove resource from collection
//
// Auth: Required for write ops. Public collections readable by anyone.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $userId = (int) ($_GET['user_id'] ?? 0);

    // Single collection with items
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM collections WHERE id = ?");
        $stmt->execute([$id]);
        $collection = $stmt->fetch();
        if (!$collection)
            json_error('Collection not found', 404, 'COLLECTION_NOT_FOUND');

        // Check access: public or own
        $user = authenticate();
        $isOwner = $user && (int) $collection['user_id'] === $user['user_id'];
        if (!$collection['is_public'] && !$isOwner)
            json_error('Collection is private', 403, 'COLLECTION_PRIVATE');

        // Get items with resource details
        $items = $db->prepare("
            SELECT ci.id AS item_id, ci.added_at,
                   r.id, r.title, r.description, r.code_type, r.subject_area,
                   r.level, r.view_count, r.like_count, r.fork_count,
                   r.author_display_name, r.visibility,
                   c.name AS category_name, c.icon AS category_icon
            FROM collection_items ci
            JOIN resources r ON r.id = ci.resource_id AND r.is_active = 1
            LEFT JOIN categories c ON r.category_id = c.id
            WHERE ci.collection_id = ?
            ORDER BY ci.added_at DESC
        ");
        $items->execute([$id]);

        $collection['items'] = $items->fetchAll();
        json_ok(['collection' => $collection]);
    }

    // User's collections
    if ($userId) {
        $stmt = $db->prepare("
            SELECT id, title, description, is_public, item_count, created_at
            FROM collections
            WHERE user_id = ? AND is_public = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        json_ok(['collections' => $stmt->fetchAll()]);
    }

    // My collections (all, including private)
    $user = requireAuth();
    $stmt = $db->prepare("
        SELECT id, title, description, is_public, item_count, created_at
        FROM collections
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user['user_id']]);
    json_ok(['collections' => $stmt->fetchAll()]);
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $user = requireAuth();
    $action = $_GET['action'] ?? 'create';
    $id = (int) ($_GET['id'] ?? 0);

    // Add resource to collection
    if ($action === 'add' && $id) {
        $data = json_body();
        $resourceId = (int) ($data['resource_id'] ?? 0);
        if (!$resourceId)
            json_error('resource_id required', 400, 'MISSING_RESOURCE_ID');

        // Verify ownership
        $coll = $db->prepare("SELECT user_id FROM collections WHERE id = ?");
        $coll->execute([$id]);
        $collection = $coll->fetch();
        if (!$collection)
            json_error('Collection not found', 404, 'COLLECTION_NOT_FOUND');
        if ((int) $collection['user_id'] !== $user['user_id'])
            json_error('Not your collection', 403, 'NOT_COLLECTION_OWNER');

        // Verify resource exists
        $res = $db->prepare("SELECT id FROM resources WHERE id = ? AND is_active = 1");
        $res->execute([$resourceId]);
        if (!$res->fetch())
            json_error('Resource not found', 404, 'RESOURCE_NOT_FOUND');

        // Check duplicate
        $dup = $db->prepare("SELECT id FROM collection_items WHERE collection_id = ? AND resource_id = ?");
        $dup->execute([$id, $resourceId]);
        if ($dup->fetch())
            json_error('Resource already in collection', 409, 'ALREADY_IN_COLLECTION');

        $db->prepare("INSERT INTO collection_items (collection_id, resource_id) VALUES (?, ?)")
           ->execute([$id, $resourceId]);
        $db->prepare("UPDATE collections SET item_count = item_count + 1 WHERE id = ?")
           ->execute([$id]);

        json_ok(['message' => 'Resource added to collection']);
    }

    // Remove resource from collection
    if ($action === 'remove' && $id) {
        $data = json_body();
        $resourceId = (int) ($data['resource_id'] ?? 0);
        if (!$resourceId)
            json_error('resource_id required', 400, 'MISSING_RESOURCE_ID');

        // Verify ownership
        $coll = $db->prepare("SELECT user_id FROM collections WHERE id = ?");
        $coll->execute([$id]);
        $collection = $coll->fetch();
        if (!$collection || (int) $collection['user_id'] !== $user['user_id'])
            json_error('Not your collection', 403, 'NOT_COLLECTION_OWNER');

        $deleted = $db->prepare("DELETE FROM collection_items WHERE collection_id = ? AND resource_id = ?");
        $deleted->execute([$id, $resourceId]);
        if ($deleted->rowCount() > 0) {
            $db->prepare("UPDATE collections SET item_count = GREATEST(item_count - 1, 0) WHERE id = ?")->execute([$id]);
        }

        json_ok(['message' => 'Resource removed from collection']);
    }

    // Create new collection
    $data = json_body();
    $title = sanitize($data['title'] ?? '', 255);
    if (!$title)
        json_error('Title required', 400, 'MISSING_TITLE');

    // Rate limit: max 20 collections per user
    $countCheck = $db->prepare("SELECT COUNT(*) FROM collections WHERE user_id = ?");
    $countCheck->execute([$user['user_id']]);
    if ((int) $countCheck->fetchColumn() >= 20)
        json_error('Maximum 20 collections allowed', 429, 'COLLECTION_LIMIT');

    $db->prepare("INSERT INTO collections (user_id, title, description, is_public) VALUES (?, ?, ?, ?)")
       ->execute([
           $user['user_id'],
           $title,
           sanitize($data['description'] ?? '', 500),
           isset($data['is_public']) ? (int) (bool) $data['is_public'] : 1,
       ]);

    json_ok([
        'id'      => (int) $db->lastInsertId(),
        'message' => 'Collection created',
    ]);
}

// ── PUT: Update collection ────────────────────────────────────
if ($method === 'PUT') {
    $user = requireAuth();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id)
        json_error('Collection ID required', 400, 'MISSING_COLLECTION_ID');

    // Verify ownership
    $coll = $db->prepare("SELECT user_id FROM collections WHERE id = ?");
    $coll->execute([$id]);
    $collection = $coll->fetch();
    if (!$collection)
        json_error('Collection not found', 404, 'COLLECTION_NOT_FOUND');
    if ((int) $collection['user_id'] !== $user['user_id'])
        json_error('Not your collection', 403, 'NOT_COLLECTION_OWNER');

    $data = json_body();
    $db->prepare("
        UPDATE collections SET
            title = COALESCE(NULLIF(?, ''), title),
            description = COALESCE(?, description),
            is_public = COALESCE(?, is_public)
        WHERE id = ?
    ")->execute([
        sanitize($data['title'] ?? '', 255),
        isset($data['description']) ? sanitize($data['description'], 500) : null,
        isset($data['is_public']) ? (int) (bool) $data['is_public'] : null,
        $id,
    ]);

    json_ok(['message' => 'Collection updated']);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $user = requireAuth();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id)
        json_error('Collection ID required', 400, 'MISSING_COLLECTION_ID');

    $coll = $db->prepare("SELECT user_id FROM collections WHERE id = ?");
    $coll->execute([$id]);
    $collection = $coll->fetch();
    if (!$collection)
        json_error('Collection not found', 404, 'COLLECTION_NOT_FOUND');
    if ((int) $collection['user_id'] !== $user['user_id'] && ($user['role'] ?? '') !== 'superadmin')
        json_error('Not your collection', 403, 'NOT_COLLECTION_OWNER');

    // CASCADE will delete items too
    $db->prepare("DELETE FROM collections WHERE id = ?")->execute([$id]);
    json_ok(['message' => 'Collection deleted']);
}

json_error('Method not allowed', 405);
