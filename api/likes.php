<?php
// ================================================================
// api/likes.php — Resource Likes API
//
// POST /api/likes.php?id=X    Toggle like (add/remove)
// GET  /api/likes.php?id=X    Check if current user liked + count
//
// Auth: Session or JWT required
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();

if ($method === 'POST') rateLimit($db, 'likes_post', 30);
$resourceId = (int) ($_GET['id'] ?? 0);

if (!$resourceId)
    json_error('Resource ID required', 400, 'MISSING_RESOURCE_ID');

// Verify resource exists
$exists = $db->prepare("SELECT id FROM resources WHERE id = ? AND is_active = 1");
$exists->execute([$resourceId]);
if (!$exists->fetch())
    json_error('Resource not found', 404, 'RESOURCE_NOT_FOUND');

// ── GET: Check like status ────────────────────────────────────
if ($method === 'GET') {
    $user = authenticate();

    // Get total likes
    $countStmt = $db->prepare("SELECT COUNT(*) FROM resource_likes WHERE resource_id = ?");
    $countStmt->execute([$resourceId]);
    $likeCount = (int) $countStmt->fetchColumn();

    $userLiked = false;
    if ($user) {
        $likeStmt = $db->prepare("SELECT id FROM resource_likes WHERE resource_id = ? AND user_id = ?");
        $likeStmt->execute([$resourceId, $user['user_id']]);
        $userLiked = (bool) $likeStmt->fetch();
    }

    json_ok([
        'resource_id' => $resourceId,
        'like_count'  => $likeCount,
        'user_liked'  => $userLiked,
    ]);
}

// ── POST: Toggle like ─────────────────────────────────────────
if ($method === 'POST') {
    $user = requireAuth();

    $db->beginTransaction();
    try {
        // Check if already liked
        $check = $db->prepare("SELECT id FROM resource_likes WHERE resource_id = ? AND user_id = ?");
        $check->execute([$resourceId, $user['user_id']]);
        $existing = $check->fetch();

        if ($existing) {
            // Unlike
            $db->prepare("DELETE FROM resource_likes WHERE id = ?")->execute([$existing['id']]);
            $db->prepare("UPDATE resources SET like_count = GREATEST(like_count - 1, 0) WHERE id = ?")->execute([$resourceId]);
            $action = 'unliked';
        } else {
            // Like
            $db->prepare("INSERT INTO resource_likes (resource_id, user_id, user_name) VALUES (?, ?, ?)")
               ->execute([$resourceId, $user['user_id'], $user['name']]);
            $db->prepare("UPDATE resources SET like_count = like_count + 1 WHERE id = ?")->execute([$resourceId]);
            $action = 'liked';
        }

        $db->commit();

        // Get updated count
        $countStmt = $db->prepare("SELECT like_count FROM resources WHERE id = ?");
        $countStmt->execute([$resourceId]);
        $newCount = (int) $countStmt->fetchColumn();

        json_ok([
            'action'     => $action,
            'like_count' => $newCount,
            'user_liked' => $action === 'liked',
        ]);
    } catch (\Throwable $e) {
        $db->rollBack();
        json_error('Like failed: ' . $e->getMessage(), 500);
    }
}

json_error('Method not allowed', 405);
