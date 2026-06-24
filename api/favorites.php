<?php
// ================================================================
// api/favorites.php — Resource Favorites API (⭐ guardado rápido y privado)
//
// GET  /api/favorites.php          Mis favoritos (lista + ids)
// POST /api/favorites.php?id=X     Toggle favorito (add/remove)
//
// Auth: requerida (Session o JWT). La lista va ESTRICTAMENTE por el
// user_id de sesión y filtra resources.is_active = 1.
//
// Distinto de "Guardar a colección" (curaduría pública/avanzada): esto
// es un guardado personal de un clic, el gancho para que un estudiante
// se registre.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();

if ($method === 'GET')       rateLimit($db, 'favorites_get', 60);
elseif ($method === 'POST')  rateLimit($db, 'favorites_post', 30);

// ── GET: mis favoritos ────────────────────────────────────────
if ($method === 'GET') {
    $user = requireAuth();

    $stmt = $db->prepare("
        SELECT r.id, r.title, r.description, r.code_type, r.subject_area, r.level,
               r.view_count, r.like_count, r.fork_count, r.lang,
               r.author_user_id, r.author_display_name, r.source_name, r.source_url,
               c.name AS category_name, c.icon AS category_icon,
               rf.created_at AS favorited_at
        FROM resource_favorites rf
        JOIN resources r ON r.id = rf.resource_id AND r.is_active = 1
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE rf.user_id = ?
        ORDER BY rf.created_at DESC
    ");
    $stmt->execute([$user['user_id']]);
    $favorites = $stmt->fetchAll();

    json_ok([
        'favorites'    => $favorites,
        'favorite_ids' => array_map(fn($r) => (int) $r['id'], $favorites),
    ]);
}

// ── POST: toggle favorito ─────────────────────────────────────
if ($method === 'POST') {
    $user = requireAuth();

    $resourceId = (int) ($_GET['id'] ?? 0);
    if (!$resourceId)
        json_error('Resource ID required', 400, 'MISSING_RESOURCE_ID');

    // El recurso debe existir y estar activo.
    $exists = $db->prepare("SELECT id FROM resources WHERE id = ? AND is_active = 1");
    $exists->execute([$resourceId]);
    if (!$exists->fetch())
        json_error('Resource not found', 404, 'RESOURCE_NOT_FOUND');

    // ¿Ya es favorito de ESTE usuario?
    $check = $db->prepare("SELECT id FROM resource_favorites WHERE user_id = ? AND resource_id = ?");
    $check->execute([$user['user_id'], $resourceId]);
    $existing = $check->fetch();

    if ($existing) {
        $db->prepare("DELETE FROM resource_favorites WHERE id = ?")->execute([$existing['id']]);
        json_ok(['action' => 'removed', 'favorited' => false]);
    }

    $db->prepare("INSERT INTO resource_favorites (user_id, resource_id) VALUES (?, ?)")
       ->execute([$user['user_id'], $resourceId]);
    json_ok(['action' => 'added', 'favorited' => true]);
}

json_error('Method not allowed', 405);
