<?php
// ================================================================
// api/check_similarity.php — Content Similarity Check Endpoint
//
// POST /api/check_similarity.php
// Body: { "code_content": "...", "category_id": 1 }
//
// Returns similar resources found in the catalog.
// Used by the frontend to warn BEFORE publishing.
//
// Auth: JWT required (only registered users can check)
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/similarity.php';

cors();

if (request_method() !== 'POST')
    json_error('Method not allowed', 405);

$user = requireAuth();
$db = getResourcesDB();
$data = json_body();

$content = $data['code_content'] ?? '';
if (strlen($content) < 50)
    json_error('Content too short to analyze (min 50 chars)');

$categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
$threshold = min(0.9, max(0.1, (float) ($data['threshold'] ?? 0.25)));

$matches = findSimilarResources($db, $content, $categoryId, $threshold);

$status = 'original';
if (!empty($matches)) {
    $topScore = $matches[0]['score'];
    if ($topScore >= 0.95) {
        $status = 'duplicate';
    } elseif ($topScore >= 0.5) {
        $status = 'similar';
    } else {
        $status = 'low_similarity';
    }
}

json_ok([
    'status' => $status,
    'matches' => $matches,
    'total_checked' => count($matches),
    'message' => match ($status) {
        'duplicate' => '⚠️ Este contenido ya existe en iarepo',
        'similar' => '⚠️ Se encontraron recursos similares — verifica antes de publicar',
        'low_similarity' => 'ℹ️ Se encontraron coincidencias parciales',
        default => '✅ Contenido original — no se encontraron duplicados',
    },
]);
