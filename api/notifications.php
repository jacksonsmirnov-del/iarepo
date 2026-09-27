<?php
// ================================================================
// api/notifications.php — In-app notifications feed
//
// GET  /api/notifications.php          Recent activity on my resources + unread count
// POST /api/notifications.php          Mark all as seen (updates notifications_seen_at)
//
// Auth: session/JWT required. "Activity" = likes / versions / comments /
// «lo usé en clase» by OTHERS on resources the current user authored. The
// sources and their rules live in shared/activity.php (shared with Mi panel).
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/activity.php';

cors();

$db = getResourcesDB();
$method = request_method();
// Las novedades son de las cuentas de iarepo.com: van por users.id. Un token
// de Campus trae el user_id de SU numeración, y sin este corte el docente 5 de
// Campus recibía las de la cuenta 5 de iarepo (quién le dio «Me gusta», quién
// comentó, quién lo usó en clase) y podía marcarlas como vistas.
$user = requireSiteAccount();
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

$seenStmt = $db->prepare("SELECT notifications_seen_at FROM users WHERE id = ?");
$seenStmt->execute([$uid]);
$seenAt = $seenStmt->fetchColumn() ?: '1970-01-01 00:00:00';

// Quién aparece y con qué nombre (quien aprende, sin él): shared/activity.php.
$all = iarepo_author_activity($db, $uid, $myIds, 15);

$unread = 0;
foreach ($all as $n) {
    if ($n['created_at'] > $seenAt) $unread++;
}

json_ok(['notifications' => $all, 'unread' => $unread]);
