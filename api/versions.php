<?php
// ================================================================
// api/versions.php — Resource Version History API
//
// GET /api/versions.php?resource_id=X   List all versions
// GET /api/versions.php?id=X           Get specific version content
//
// Auth: JWT required
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$user = requireAuth();
$db = getResourcesDB();

rateLimit($db, 'versions_get', 60);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed', 405);

$resourceId = (int)($_GET['resource_id'] ?? 0);
$versionId = (int)($_GET['id'] ?? 0);

if ($versionId) {
    // Get specific version with content
    $stmt = $db->prepare("
        SELECT rv.*, r.title AS resource_title
        FROM resource_versions rv
        JOIN resources r ON r.id = rv.resource_id
        WHERE rv.id = ?
    ");
    $stmt->execute([$versionId]);
    $version = $stmt->fetch();
    if (!$version) json_error('Version not found', 404);
    json_ok(['version' => $version]);
}

if ($resourceId) {
    // List all versions (without content for performance)
    $stmt = $db->prepare("
        SELECT id, resource_id, version_number, editor_display_name, editor_tenant_name,
               change_description, created_at
        FROM resource_versions
        WHERE resource_id = ?
        ORDER BY version_number DESC
    ");
    $stmt->execute([$resourceId]);
    json_ok(['versions' => $stmt->fetchAll()]);
}

json_error('resource_id or id parameter required');
