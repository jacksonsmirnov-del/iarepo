<?php
// ================================================================
// api/assignments.php — Resource Assignments API
//
// Assign resources to classrooms (from Campus via JWT).
//
// POST   /api/assignments.php            Assign resource to classroom
// GET    /api/assignments.php?resource_id=X   Assignments for a resource
// GET    /api/assignments.php?classroom=X&tenant_id=X  Assignments for a classroom
// DELETE /api/assignments.php?id=X       Remove assignment
//
// Auth: JWT required (teacher, admin)
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();

// ── POST: Create assignment ───────────────────────────────────
if ($method === 'POST') {
    $user = requireAuth();
    requireRole($user, ['teacher', 'admin', 'superadmin']);

    $data = json_body();
    $resourceId = (int)($data['resource_id'] ?? 0);
    $classroomId = (int)($data['classroom_id'] ?? 0);
    $classroomName = sanitize($data['classroom_name'] ?? '', 100);

    if (!$resourceId) json_error('resource_id required');
    if (!$classroomId) json_error('classroom_id required');

    // Verify resource exists and user has access
    $exists = $db->prepare("SELECT id FROM resources WHERE id = ? AND is_active = 1");
    $exists->execute([$resourceId]);
    if (!$exists->fetch()) json_error('Resource not found', 404);

    // Check for duplicate assignment
    $dup = $db->prepare("
        SELECT id FROM resource_assignments
        WHERE resource_id = ? AND tenant_id = ? AND classroom_id = ? AND is_active = 1
    ");
    $dup->execute([$resourceId, $user['tenant_id'], $classroomId]);
    if ($dup->fetch()) json_error('Resource already assigned to this classroom');

    $db->prepare("
        INSERT INTO resource_assignments
            (resource_id, tenant_id, classroom_id, classroom_name,
             assigned_by_user_id, assigned_by_name, available_from, available_until)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $resourceId,
        $user['tenant_id'],
        $classroomId,
        $classroomName,
        $user['user_id'],
        $user['name'],
        $data['available_from'] ?? date('Y-m-d H:i:s'),
        !empty($data['available_until']) ? $data['available_until'] : null,
    ]);

    // Record usage
    $db->prepare("
        INSERT INTO resource_usage (resource_id, user_id, tenant_id, user_display_name, tenant_name, usage_type, classroom_name)
        VALUES (?, ?, ?, ?, ?, 'sent', ?)
    ")->execute([
        $resourceId,
        $user['user_id'],
        $user['tenant_id'],
        $user['name'],
        $user['tenant_name'],
        $classroomName,
    ]);

    // Update use_count
    $db->prepare("UPDATE resources SET use_count = use_count + 1 WHERE id = ?")->execute([$resourceId]);

    json_ok(['id' => (int)$db->lastInsertId(), 'message' => 'Resource assigned to classroom']);
}

// ── GET: List assignments ─────────────────────────────────────
if ($method === 'GET') {
    $user = requireAuth();

    $resourceId = (int)($_GET['resource_id'] ?? 0);
    $classroomId = (int)($_GET['classroom_id'] ?? 0);
    $tenantId = (int)($_GET['tenant_id'] ?? $user['tenant_id'] ?? 0);

    if ($resourceId) {
        // All assignments for a resource
        $stmt = $db->prepare("
            SELECT id, classroom_name, assigned_by_name, available_from, available_until, created_at
            FROM resource_assignments
            WHERE resource_id = ? AND is_active = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute([$resourceId]);
        json_ok(['assignments' => $stmt->fetchAll()]);
    }

    if ($classroomId && $tenantId) {
        // Resources assigned to a specific classroom (for student view)
        $stmt = $db->prepare("
            SELECT ra.id AS assignment_id, r.id, r.title, r.description, r.code_type,
                   r.subject_area, r.topic_tag, r.author_display_name,
                   ra.assigned_by_name, ra.available_from, ra.available_until
            FROM resource_assignments ra
            JOIN resources r ON r.id = ra.resource_id AND r.is_active = 1
            WHERE ra.tenant_id = ? AND ra.classroom_id = ? AND ra.is_active = 1
              AND (ra.available_from IS NULL OR ra.available_from <= NOW())
              AND (ra.available_until IS NULL OR ra.available_until >= NOW())
            ORDER BY ra.created_at DESC
        ");
        $stmt->execute([$tenantId, $classroomId]);
        json_ok(['assignments' => $stmt->fetchAll()]);
    }

    // Default: all my assignments (as teacher)
    $stmt = $db->prepare("
        SELECT ra.id, ra.resource_id, r.title, ra.classroom_name,
               ra.available_from, ra.available_until, ra.created_at
        FROM resource_assignments ra
        JOIN resources r ON r.id = ra.resource_id
        WHERE ra.assigned_by_user_id = ? AND ra.tenant_id = ? AND ra.is_active = 1
        ORDER BY ra.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$user['user_id'], $user['tenant_id']]);
    json_ok(['assignments' => $stmt->fetchAll()]);
}

// ── DELETE: Remove assignment ─────────────────────────────────
if ($method === 'DELETE') {
    $user = requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing assignment ID');

    // Only the assigner or admin can remove
    $check = $db->prepare("
        SELECT id FROM resource_assignments
        WHERE id = ? AND (assigned_by_user_id = ? OR ? = 'superadmin') AND is_active = 1
    ");
    $check->execute([$id, $user['user_id'], $user['role'] ?? '']);
    if (!$check->fetch()) json_error('Not found or not authorized', 404);

    $db->prepare("UPDATE resource_assignments SET is_active = 0 WHERE id = ?")->execute([$id]);
    json_ok(['message' => 'Assignment removed']);
}

json_error('Method not allowed', 405);
