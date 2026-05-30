<?php
// ================================================================
// api/suggestions.php — Resource Feedback API
//
// Private suggestions between teachers for improving resources.
//
// POST /api/suggestions.php            Create suggestion
// GET  /api/suggestions.php?resource_id=X  List suggestions for resource
// PUT  /api/suggestions.php?id=X       Mark as read
//
// Auth: JWT required
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();
rateLimit($db, 'suggestions', $method === 'GET' ? 60 : 30);

// ── POST: Create suggestion ───────────────────────────────────
if ($method === 'POST') {
    $user = requireAuth();
    requireRole($user, ['teacher', 'admin', 'superadmin']);

    $data = json_body();
    $resourceId = (int)($data['resource_id'] ?? 0);
    $suggestion = trim($data['suggestion'] ?? '');

    if (!$resourceId) json_error('resource_id required');
    if (!$suggestion) json_error('suggestion text required');
    if (mb_strlen($suggestion) > 2000) json_error('Suggestion too long (max 2000 chars)');

    // Verify resource exists
    $exists = $db->prepare("SELECT id, author_user_id, author_tenant_id FROM resources WHERE id = ? AND is_active = 1");
    $exists->execute([$resourceId]);
    $resource = $exists->fetch();
    if (!$resource) json_error('Resource not found', 404);

    // Can't suggest on own resource
    if ($resource['author_user_id'] == $user['user_id'] && $resource['author_tenant_id'] == $user['tenant_id']) {
        json_error('Cannot suggest on your own resource');
    }

    $db->prepare("
        INSERT INTO resource_suggestions (resource_id, user_id, user_display_name, tenant_name, suggestion)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $resourceId,
        $user['user_id'],
        $user['name'],
        $user['tenant_name'],
        $suggestion,
    ]);

    json_ok(['id' => (int)$db->lastInsertId(), 'message' => 'Suggestion sent']);
}

// ── GET: List suggestions for a resource ──────────────────────
if ($method === 'GET') {
    $user = requireAuth();
    $resourceId = (int)($_GET['resource_id'] ?? 0);

    if ($resourceId) {
        // Suggestions for a specific resource
        $stmt = $db->prepare("
            SELECT id, user_display_name, tenant_name, suggestion, is_read, created_at
            FROM resource_suggestions
            WHERE resource_id = ?
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$resourceId]);
        json_ok(['suggestions' => $stmt->fetchAll()]);
    }

    // My resource suggestions (inbox)
    $stmt = $db->prepare("
        SELECT rs.id, rs.resource_id, r.title AS resource_title,
               rs.user_display_name, rs.tenant_name, rs.suggestion, rs.is_read, rs.created_at
        FROM resource_suggestions rs
        JOIN resources r ON r.id = rs.resource_id
        WHERE r.author_user_id = ? AND r.author_tenant_id = ?
        ORDER BY rs.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$user['user_id'], $user['tenant_id']]);
    json_ok(['suggestions' => $stmt->fetchAll()]);
}

// ── PUT: Mark as read ─────────────────────────────────────────
if ($method === 'PUT') {
    $user = requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing suggestion ID');

    // Only resource author can mark as read
    $stmt = $db->prepare("
        SELECT rs.id FROM resource_suggestions rs
        JOIN resources r ON r.id = rs.resource_id
        WHERE rs.id = ? AND r.author_user_id = ? AND r.author_tenant_id = ?
    ");
    $stmt->execute([$id, $user['user_id'], $user['tenant_id']]);
    if (!$stmt->fetch()) json_error('Not found or not authorized', 404);

    $db->prepare("UPDATE resource_suggestions SET is_read = 1 WHERE id = ?")->execute([$id]);
    json_ok(['message' => 'Marked as read']);
}

json_error('Method not allowed', 405);
