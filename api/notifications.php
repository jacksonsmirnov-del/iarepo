<?php
// ================================================================
// api/notifications.php — In-app notifications feed
//
// GET  /api/notifications.php          Recent activity on my resources + unread count
// POST /api/notifications.php          Mark all as seen (updates notifications_seen_at)
//
// Auth: session/JWT required. "Activity" = likes/forks/comments by OTHERS
// on resources the current user authored.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();
$user = requireAuth();
$uid = (int) $user['user_id'];

// ── POST: mark all as seen ────────────────────────────────────
if ($method === 'POST') {
    rateLimit($db, 'notif_seen', 60);
    $db->prepare("UPDATE users SET notifications_seen_at = NOW() WHERE id = ?")->execute([$uid]);
    json_ok(['seen' => true]);
}

// ── GET: feed + unread count ──────────────────────────────────
rateLimit($db, 'notif_get', 120);

$idsStmt = $db->prepare("SELECT id FROM resources WHERE author_user_id = ? AND author_tenant_id = 0 AND is_active = 1");
$idsStmt->execute([$uid]);
$myIds = $idsStmt->fetchAll(PDO::FETCH_COLUMN);

if (!$myIds) {
    json_ok(['notifications' => [], 'unread' => 0]);
}
$in = implode(',', array_map('intval', $myIds));

$seenStmt = $db->prepare("SELECT notifications_seen_at FROM users WHERE id = ?");
$seenStmt->execute([$uid]);
$seenAt = $seenStmt->fetchColumn() ?: '1970-01-01 00:00:00';

$likes = $db->prepare("
    SELECT 'like' AS type, rl.user_name AS actor, r.title AS resource_title, r.id AS resource_id, rl.created_at
    FROM resource_likes rl JOIN resources r ON r.id = rl.resource_id
    WHERE rl.resource_id IN ($in) AND rl.user_id != ?
    ORDER BY rl.created_at DESC LIMIT 15");
$likes->execute([$uid]);

$forks = $db->prepare("
    SELECT 'fork' AS type, r2.author_display_name AS actor, r.title AS resource_title, r.id AS resource_id, r2.created_at
    FROM resources r2 JOIN resources r ON r.id = r2.fork_of
    WHERE r2.fork_of IN ($in) AND r2.is_active = 1 AND r2.author_user_id != ?
    ORDER BY r2.created_at DESC LIMIT 15");
$forks->execute([$uid]);

$comments = $db->prepare("
    SELECT 'comment' AS type, rc.user_name AS actor, r.title AS resource_title, r.id AS resource_id, rc.created_at
    FROM resource_comments rc JOIN resources r ON r.id = rc.resource_id
    WHERE rc.resource_id IN ($in) AND rc.is_active = 1 AND rc.user_id != ?
    ORDER BY rc.created_at DESC LIMIT 15");
$comments->execute([$uid]);

$all = array_merge($likes->fetchAll(), $forks->fetchAll(), $comments->fetchAll());
usort($all, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$all = array_slice($all, 0, 15);

$unread = 0;
foreach ($all as $n) {
    if ($n['created_at'] > $seenAt) $unread++;
}

json_ok(['notifications' => $all, 'unread' => $unread]);
