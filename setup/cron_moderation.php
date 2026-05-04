<?php
// ================================================================
// setup/cron_moderation.php — Background moderation queue processor
//
// Runs via cron every 1-2 minutes. Processes resources with
// moderation_status = 'pending_review' one by one, running the
// heavy Jaccard similarity check without blocking user requests.
//
// Cron setup:
//   * * * * * php /path/to/resources/setup/cron_moderation.php >> /tmp/moderation.log 2>&1
//
// This script is idempotent and safe to run concurrently (uses
// SELECT FOR UPDATE to prevent double-processing).
// ================================================================

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/similarity.php';

$db = getResourcesDB();

// Lock and fetch next batch of pending resources (max 5 per run)
$db->beginTransaction();
try {
    $stmt = $db->prepare("
        SELECT id, code_content, category_id, title
        FROM resources
        WHERE moderation_status = 'pending_review'
          AND is_active = 1
        ORDER BY created_at ASC
        LIMIT 5
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute();
    $pending = $stmt->fetchAll();

    if (empty($pending)) {
        $db->commit();
        exit(0); // Nothing to process
    }

    $processed = 0;
    foreach ($pending as $resource) {
        $id = (int) $resource['id'];
        $content = $resource['code_content'];
        $categoryId = $resource['category_id'] ? (int) $resource['category_id'] : null;

        // Skip empty content
        if (empty(trim($content))) {
            $db->prepare("UPDATE resources SET moderation_status = 'approved' WHERE id = ?")->execute([$id]);
            $processed++;
            continue;
        }

        // Run Jaccard similarity check (excluding self)
        $matches = findSimilarResources($db, $content, $categoryId, 0.25, 200);

        // Filter out self
        $matches = array_filter($matches, fn($m) => $m['id'] !== $id);
        $matches = array_values($matches);

        if (empty($matches) || $matches[0]['score'] < 0.5) {
            // Original content — approve
            $db->prepare("UPDATE resources SET moderation_status = 'approved' WHERE id = ?")->execute([$id]);
            echo date('Y-m-d H:i:s') . " [APPROVED] ID=$id \"{$resource['title']}\"\n";
        } elseif ($matches[0]['score'] >= 0.95) {
            // Near-duplicate — reject
            $db->prepare("UPDATE resources SET moderation_status = 'rejected' WHERE id = ?")->execute([$id]);
            echo date('Y-m-d H:i:s') . " [REJECTED] ID=$id \"{$resource['title']}\" — {$matches[0]['percentage']}% similar to ID={$matches[0]['id']}\n";
        } else {
            // Similar content — flag for manual review
            $db->prepare("UPDATE resources SET moderation_status = 'under_review' WHERE id = ?")->execute([$id]);
            echo date('Y-m-d H:i:s') . " [REVIEW]   ID=$id \"{$resource['title']}\" — {$matches[0]['percentage']}% similar to ID={$matches[0]['id']}\n";
        }
        $processed++;
    }

    $db->commit();
    if ($processed > 0) {
        echo date('Y-m-d H:i:s') . " Processed $processed resources\n";
    }
} catch (Throwable $e) {
    $db->rollBack();
    echo date('Y-m-d H:i:s') . " [ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
