<?php
// ================================================================
// api/assignments.php — Resource Assignments API
//
// Assign resources to classrooms (from Campus via JWT).
//
// POST   /api/assignments.php            Assign resource to classroom
// GET    /api/assignments.php?resource_id=X   Assignments for a resource
// GET    /api/assignments.php?classroom_id=X  Assignments for a classroom (tenant del token)
// DELETE /api/assignments.php?id=X       Remove assignment
//
// Auth: JWT required (teacher, admin)
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/access.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();

if ($method === 'GET') rateLimit($db, 'assignments_get', 60);
elseif (in_array($method, ['POST', 'DELETE'])) rateLimit($db, 'assignments_write', 30);

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

    // El recurso debe existir y poder verlo quien lo asigna (canView). Sin
    // esto, asignar un id a ciegas y listar luego las asignaciones revelaba
    // título y descripción de borradores ajenos y de recursos de otro centro.
    // Lo que no puedes ver responde igual que lo que no existe (404).
    $exists = $db->prepare("SELECT id, visibility, author_tenant_id, author_user_id
                            FROM resources WHERE id = ? AND is_active = 1");
    $exists->execute([$resourceId]);
    $resource = $exists->fetch();
    if (!$resource || !canView($resource, $user)) json_error('Resource not found', 404);

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

    // Record usage. 'sent' deduplica por profesor, recurso y DÍA con
    // usage_day = CURDATE() (AGENTS.md §5.4): mandar el mismo recurso a dos
    // aulas el mismo día es UN uso docente, no dos. El choque con el índice
    // uniq_usage_signal es la dedup haciendo su trabajo: la asignación ya se
    // guardó arriba y no se deshace; solo no se vuelve a contar.
    $counted = true;
    try {
        $db->prepare("
            INSERT INTO resource_usage (resource_id, user_id, tenant_id, user_display_name, tenant_name, usage_type, classroom_name, usage_day)
            VALUES (?, ?, ?, ?, ?, 'sent', ?, CURDATE())
        ")->execute([
            $resourceId,
            $user['user_id'],
            $user['tenant_id'],
            $user['name'],
            $user['tenant_name'],
            $classroomName,
        ]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        $counted = false;
    }

    // Update use_count
    if ($counted)
        $db->prepare("UPDATE resources SET use_count = use_count + 1 WHERE id = ?")->execute([$resourceId]);

    json_ok(['id' => (int)$db->lastInsertId(), 'message' => 'Resource assigned to classroom']);
}

// ── GET: List assignments ─────────────────────────────────────
if ($method === 'GET') {
    $user = requireAuth();

    $resourceId = (int)($_GET['resource_id'] ?? 0);
    $classroomId = (int)($_GET['classroom_id'] ?? 0);
    // El tenant sale del TOKEN, nunca de la URL: con ?tenant_id= cualquiera
    // listaba las aulas y los docentes de otro colegio. Solo un superadmin
    // puede pedir otro tenant a propósito.
    $tenantId = (int)($user['tenant_id'] ?? 0);
    if (($user['role'] ?? '') === 'superadmin' && isset($_GET['tenant_id']))
        $tenantId = (int)$_GET['tenant_id'];

    if ($resourceId) {
        // Assignments for a resource — solo las de MI centro. Antes salían
        // las aulas y los profesores de todos los centros que lo asignaron.
        $stmt = $db->prepare("
            SELECT id, classroom_name, assigned_by_name, available_from, available_until, created_at
            FROM resource_assignments
            WHERE resource_id = ? AND tenant_id = ? AND is_active = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute([$resourceId, $tenantId]);
        json_ok(['assignments' => $stmt->fetchAll()]);
    }

    if ($classroomId && $tenantId) {
        // Resources assigned to a specific classroom (for student view)
        $stmt = $db->prepare("
            SELECT ra.id AS assignment_id, r.id, r.title, r.description, r.code_type,
                   r.subject_area, r.topic_tag, r.author_display_name,
                   r.visibility, r.author_tenant_id, r.author_user_id,
                   ra.assigned_by_name, ra.available_from, ra.available_until
            FROM resource_assignments ra
            JOIN resources r ON r.id = ra.resource_id AND r.is_active = 1
            WHERE ra.tenant_id = ? AND ra.classroom_id = ? AND ra.is_active = 1
              AND (ra.available_from IS NULL OR ra.available_from <= NOW())
              AND (ra.available_until IS NULL OR ra.available_until >= NOW())
            ORDER BY ra.created_at DESC
        ");
        $stmt->execute([$tenantId, $classroomId]);
        json_ok(['assignments' => assignments_visible($stmt->fetchAll(), $user)]);
    }

    // Default: all my assignments (as teacher)
    $stmt = $db->prepare("
        SELECT ra.id, ra.resource_id, r.title, ra.classroom_name,
               ra.available_from, ra.available_until, ra.created_at,
               r.visibility, r.author_tenant_id, r.author_user_id
        FROM resource_assignments ra
        JOIN resources r ON r.id = ra.resource_id AND r.is_active = 1
        WHERE ra.assigned_by_user_id = ? AND ra.tenant_id = ? AND ra.is_active = 1
        ORDER BY ra.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$user['user_id'], $user['tenant_id']]);
    json_ok(['assignments' => assignments_visible($stmt->fetchAll(), $user)]);
}

// ── DELETE: Remove assignment ─────────────────────────────────
if ($method === 'DELETE') {
    $user = requireAuth();
    requireRole($user, ['teacher', 'admin', 'superadmin']);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Missing assignment ID');

    // Solo quien la asignó, EN SU CENTRO (o un superadmin). El user_id solo no
    // basta: cada centro de Campus numera a sus usuarios desde 1 y las cuentas
    // de iarepo (tenant 0) son otra numeración, así que el «7» de un centro
    // borraba las asignaciones del «7» de todos los demás.
    $check = $db->prepare("
        SELECT id FROM resource_assignments
        WHERE id = ? AND is_active = 1
          AND ((assigned_by_user_id = ? AND tenant_id = ?) OR ? = 'superadmin')
    ");
    $check->execute([$id, (int)$user['user_id'], (int)$user['tenant_id'], $user['role'] ?? '']);
    if (!$check->fetch()) json_error('Not found or not authorized', 404);

    $db->prepare("UPDATE resource_assignments SET is_active = 0 WHERE id = ?")->execute([$id]);
    json_ok(['message' => 'Assignment removed']);
}

json_error('Method not allowed', 405);

/**
 * Las filas cuyo recurso puede ver $user (canView), sin los tres campos que
 * solo sirven para decidirlo. Un recurso asignado cuando era visible puede ser
 * hoy un borrador: entonces deja de listarse.
 */
function assignments_visible(array $rows, array $user): array {
    $out = [];
    foreach ($rows as $row) {
        if (!canView($row, $user))
            continue;
        unset($row['visibility'], $row['author_tenant_id'], $row['author_user_id']);
        $out[] = $row;
    }
    return $out;
}
