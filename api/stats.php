<?php
// ================================================================
// api/stats.php — Thesis Metrics API
//
// Returns aggregated usage statistics for research purposes.
// Auth: JWT required (teacher, admin, superadmin)
//
// GET /api/stats.php              Global stats
// GET /api/stats.php?detail=area  Stats grouped by area
// GET /api/stats.php?detail=time  Stats grouped by month
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$user = requireAuth();
$db = getResourcesDB();
$detail = $_GET['detail'] ?? '';

// ── Global stats ──────────────────────────────────────────────
$stats = [];

// Total resources
$stats['total_resources'] = (int)$db->query("SELECT COUNT(*) FROM resources WHERE is_active=1")->fetchColumn();
$stats['total_community'] = (int)$db->query("SELECT COUNT(*) FROM resources WHERE is_active=1 AND visibility='community'")->fetchColumn();

// By visibility
$vis = $db->query("SELECT visibility, COUNT(*) as c FROM resources WHERE is_active=1 GROUP BY visibility")->fetchAll();
$stats['by_visibility'] = array_column($vis, 'c', 'visibility');

// Total versions (indicator of collaboration)
$stats['total_versions'] = (int)$db->query("SELECT COUNT(*) FROM resource_versions")->fetchColumn();
$stats['avg_versions_per_resource'] = round(
    $stats['total_resources'] > 0
        ? $stats['total_versions'] / $stats['total_resources']
        : 0,
    2
);

// Total usage events
$stats['total_usage'] = (int)$db->query("SELECT COUNT(*) FROM resource_usage")->fetchColumn();
$usage = $db->query("SELECT usage_type, COUNT(*) as c FROM resource_usage GROUP BY usage_type")->fetchAll();
$stats['usage_by_type'] = array_column($usage, 'c', 'usage_type');

// Unique authors
$stats['unique_authors'] = (int)$db->query("SELECT COUNT(DISTINCT author_user_id, author_tenant_id) FROM resources WHERE is_active=1")->fetchColumn();

// Unique users who engaged
$stats['unique_users'] = (int)$db->query("SELECT COUNT(DISTINCT user_id, tenant_id) FROM resource_usage")->fetchColumn();

// Forks
$stats['total_forks'] = (int)$db->query("SELECT COUNT(*) FROM resources WHERE fork_of IS NOT NULL AND is_active=1")->fetchColumn();

// Suggestions
$stats['total_suggestions'] = (int)$db->query("SELECT COUNT(*) FROM resource_suggestions")->fetchColumn();

// ── Detail: by area ───────────────────────────────────────────
if ($detail === 'area') {
    $stats['by_area'] = $db->query("
        SELECT subject_area, 
               COUNT(*) as resource_count,
               SUM(use_count) as total_uses,
               SUM(fork_count) as total_forks,
               AVG(current_version) as avg_versions
        FROM resources 
        WHERE is_active = 1 AND subject_area IS NOT NULL
        GROUP BY subject_area
        ORDER BY resource_count DESC
    ")->fetchAll();
}

// ── Detail: by month (adoption curve) ─────────────────────────
if ($detail === 'time') {
    $stats['by_month'] = $db->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
               COUNT(*) as resources_created,
               (SELECT COUNT(*) FROM resource_usage ru 
                WHERE DATE_FORMAT(ru.created_at, '%Y-%m') = DATE_FORMAT(r.created_at, '%Y-%m')
               ) as usage_events
        FROM resources r
        WHERE is_active = 1
        GROUP BY month
        ORDER BY month
    ")->fetchAll();
}

json_ok(['stats' => $stats]);
