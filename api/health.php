<?php
// ================================================================
// api/health.php — Dedicated Health Check Endpoint
//
// GET /api/health.php → JSON with service status
//
// Used by Campus to verify Resources platform is reachable
// before rendering the resources UI. No auth required.
//
// Response:
//   {"ok": true, "status": "healthy", "db": "connected",
//    "version": "1.1.0", "uptime": "...", "request_id": "..."}
// ================================================================

require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/cors.php';

cors();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$result = [
    'ok'         => true,
    'status'     => 'healthy',
    'service'    => 'iarepo',
    'version'    => '1.1.0',
    'request_id' => request_id(),
    'time'       => date('c'),
];

// ── Database check ───────────────────────────────────────────
try {
    require_once __DIR__ . '/../shared/db.php';
    $db = getResourcesDB();
    $db->query('SELECT 1');
    $result['db'] = 'connected';

    // Resource count (cached insight)
    $count = $db->query("SELECT COUNT(*) FROM resources WHERE is_active = 1")->fetchColumn();
    $result['resources'] = (int) $count;
} catch (\Throwable $e) {
    $result['ok'] = false;
    $result['status'] = 'degraded';
    $result['db'] = 'error';
    $result['db_error'] = is_debug() ? $e->getMessage() : 'Connection failed';
    http_response_code(503);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
