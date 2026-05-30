<?php
// ================================================================
// api/usage.php — Resource Usage Tracking API
//
// POST /api/usage.php   Record a usage event
// GET  /api/usage.php?resource_id=X   Get usage history
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

rateLimit($db, 'usage', $method === 'POST' ? 60 : 120);

if ($method === 'POST') {
    $user = requireAuth();
    $data = json_body();

    $resourceId = (int)($data['resource_id'] ?? 0);
    $usageType = $data['usage_type'] ?? '';

    if (!$resourceId) json_error('resource_id required');
    if (!in_array($usageType, ['presented', 'sent', 'endorsed'])) {
        json_error('usage_type must be: presented, sent, or endorsed');
    }

    // Verify resource exists
    $exists = $db->prepare("SELECT id FROM resources WHERE id = ? AND is_active = 1");
    $exists->execute([$resourceId]);
    if (!$exists->fetch()) json_error('Resource not found', 404);

    // Prevent duplicate endorsements
    if ($usageType === 'endorsed') {
        $dup = $db->prepare("
            SELECT id FROM resource_usage 
            WHERE resource_id = ? AND user_id = ? AND tenant_id = ? AND usage_type = 'endorsed'
        ");
        $dup->execute([$resourceId, $user['user_id'], $user['tenant_id']]);
        if ($dup->fetch()) json_error('Already endorsed this resource');
    }

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO resource_usage (resource_id, user_id, tenant_id, user_display_name, tenant_name, usage_type, classroom_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $resourceId,
            $user['user_id'],
            $user['tenant_id'],
            $user['name'],
            $user['tenant_name'],
            $usageType,
            sanitize($data['classroom_name'] ?? '', 100) ?: null,
        ]);

        // Update counter
        $db->prepare("UPDATE resources SET use_count = use_count + 1 WHERE id = ?")->execute([$resourceId]);

        $db->commit();
        json_ok(['message' => 'Usage recorded']);
    } catch (Throwable $e) {
        $db->rollBack();
        json_error('Failed: ' . $e->getMessage(), 500);
    }
}

if ($method === 'GET') {
    $user = requireAuth();
    $resourceId = (int)($_GET['resource_id'] ?? 0);
    if (!$resourceId) json_error('resource_id required');

    $stmt = $db->prepare("
        SELECT usage_type, user_display_name, tenant_name, classroom_name, created_at
        FROM resource_usage 
        WHERE resource_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$resourceId]);
    json_ok(['usage' => $stmt->fetchAll()]);
}

json_error('Method not allowed', 405);
