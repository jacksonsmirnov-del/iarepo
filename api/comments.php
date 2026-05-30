<?php
// ================================================================
// api/comments.php — Resource Comments API
//
// GET    /api/comments.php?resource_id=X   List comments (with replies)
// POST   /api/comments.php                 Create comment
// DELETE /api/comments.php?id=X            Delete own comment
//
// Auth: GET is public for community resources. POST/DELETE require auth.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();

if ($method === 'GET') rateLimit($db, 'comments_get', 60);
elseif (in_array($method, ['POST', 'DELETE'])) rateLimit($db, 'comments_write', 30);

// ── GET: List comments ────────────────────────────────────────
if ($method === 'GET') {
    $resourceId = (int) ($_GET['resource_id'] ?? 0);
    if (!$resourceId)
        json_error('resource_id required', 400, 'MISSING_RESOURCE_ID');

    // Verify resource exists and is accessible
    $res = $db->prepare("SELECT visibility FROM resources WHERE id = ? AND is_active = 1");
    $res->execute([$resourceId]);
    $resource = $res->fetch();
    if (!$resource)
        json_error('Resource not found', 404, 'RESOURCE_NOT_FOUND');

    // Fetch top-level comments
    $stmt = $db->prepare("
        SELECT id, user_id, user_name, user_avatar, body, parent_id, created_at
        FROM resource_comments
        WHERE resource_id = ? AND is_active = 1
        ORDER BY created_at ASC
        LIMIT 200
    ");
    $stmt->execute([$resourceId]);
    $allComments = $stmt->fetchAll();

    // Build threaded structure
    $topLevel = [];
    $replies = [];
    foreach ($allComments as $c) {
        $c['id'] = (int) $c['id'];
        $c['user_id'] = (int) $c['user_id'];
        $c['parent_id'] = $c['parent_id'] ? (int) $c['parent_id'] : null;
        if ($c['parent_id']) {
            $replies[$c['parent_id']][] = $c;
        } else {
            $topLevel[] = $c;
        }
    }

    // Attach replies to parents
    foreach ($topLevel as &$comment) {
        $comment['replies'] = $replies[$comment['id']] ?? [];
    }
    unset($comment);

    json_ok([
        'comments' => $topLevel,
        'total'    => count($allComments),
    ]);
}

// ── POST: Create comment ──────────────────────────────────────
if ($method === 'POST') {
    $user = requireAuth();

    $data = json_body();
    $resourceId = (int) ($data['resource_id'] ?? 0);
    $body = trim($data['body'] ?? '');
    $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;

    if (!$resourceId)
        json_error('resource_id required', 400, 'MISSING_RESOURCE_ID');
    if (!$body)
        json_error('Comment body required', 400, 'EMPTY_COMMENT');
    if (mb_strlen($body) > 2000)
        json_error('Comment too long (max 2000 characters)', 400, 'COMMENT_TOO_LONG');

    // Verify resource exists
    $exists = $db->prepare("SELECT id FROM resources WHERE id = ? AND is_active = 1");
    $exists->execute([$resourceId]);
    if (!$exists->fetch())
        json_error('Resource not found', 404, 'RESOURCE_NOT_FOUND');

    // Verify parent comment exists (if replying)
    if ($parentId) {
        $parent = $db->prepare("SELECT id FROM resource_comments WHERE id = ? AND resource_id = ? AND is_active = 1");
        $parent->execute([$parentId, $resourceId]);
        if (!$parent->fetch())
            json_error('Parent comment not found', 404, 'PARENT_NOT_FOUND');
    }

    // Rate limit: max 20 comments per day per user
    $rateCheck = $db->prepare("
        SELECT COUNT(*) FROM resource_comments
        WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
    ");
    $rateCheck->execute([$user['user_id']]);
    if ((int) $rateCheck->fetchColumn() >= 20)
        json_error('Rate limit: max 20 comments per day', 429, 'COMMENT_RATE_LIMITED');

    $avatar = $user['avatar_url'] ?? null;

    $db->prepare("
        INSERT INTO resource_comments (resource_id, user_id, user_name, user_avatar, body, parent_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $resourceId,
        $user['user_id'],
        $user['name'],
        $avatar,
        $body,
        $parentId,
    ]);

    $commentId = (int) $db->lastInsertId();

    json_ok([
        'id'      => $commentId,
        'message' => 'Comment posted',
    ]);
}

// ── DELETE: Soft-delete own comment ───────────────────────────
if ($method === 'DELETE') {
    $user = requireAuth();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id)
        json_error('Comment ID required', 400, 'MISSING_COMMENT_ID');

    // Only author or superadmin can delete
    $check = $db->prepare("SELECT user_id FROM resource_comments WHERE id = ? AND is_active = 1");
    $check->execute([$id]);
    $comment = $check->fetch();

    if (!$comment)
        json_error('Comment not found', 404, 'COMMENT_NOT_FOUND');

    if ((int) $comment['user_id'] !== $user['user_id'] && ($user['role'] ?? '') !== 'superadmin')
        json_error('Only the author can delete this comment', 403, 'NOT_COMMENT_AUTHOR');

    $db->prepare("UPDATE resource_comments SET is_active = 0 WHERE id = ?")->execute([$id]);
    json_ok(['message' => 'Comment deleted']);
}

json_error('Method not allowed', 405);
