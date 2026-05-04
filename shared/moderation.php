<?php
// ================================================================
// shared/moderation.php — Content quality system
//
// Provides duplicate detection, similarity checking, and
// moderation helpers. Infrastructure is always loaded but
// only enforced when OPEN_REGISTRATION is enabled in .env.php.
//
// Usage:
//   require_once __DIR__ . '/moderation.php';
//   $result = checkContentQuality($db, $codeContent, $categoryId);
//   // $result = ['status' => 'approved'|'under_review'|'duplicate', ...]
// ================================================================

/**
 * Check if open moderation is enabled.
 * When false, all content is auto-approved (admin-only mode).
 */
function isModerationEnabled(): bool
{
    static $enabled = null;
    if ($enabled === null) {
        $config = require __DIR__ . '/../.env.php';
        $enabled = (bool) ($config['OPEN_REGISTRATION'] ?? false);
    }
    return $enabled;
}

/**
 * Check content quality: duplicates + similarity.
 *
 * @return array{status: string, reason: string|null, similar_id: int|null}
 */
function checkContentQuality(PDO $db, string $codeContent, ?int $categoryId = null): array
{
    // If moderation is off, everything is approved
    if (!isModerationEnabled()) {
        return ['status' => 'approved', 'reason' => null, 'similar_id' => null];
    }

    if (empty(trim($codeContent))) {
        return ['status' => 'approved', 'reason' => null, 'similar_id' => null];
    }

    // Use the Jaccard similarity engine
    require_once __DIR__ . '/similarity.php';

    $matches = findSimilarResources($db, $codeContent, $categoryId, 0.25, 200);

    if (empty($matches)) {
        return ['status' => 'approved', 'reason' => null, 'similar_id' => null];
    }

    $top = $matches[0];

    if ($top['match_type'] === 'exact_duplicate' || $top['score'] >= 0.95) {
        return [
            'status' => 'duplicate',
            'reason' => "Contenido idéntico al recurso \"{$top['title']}\" (ID: {$top['id']})",
            'similar_id' => $top['id'],
        ];
    }

    if ($top['score'] >= 0.5) {
        return [
            'status' => 'under_review',
            'reason' => "Contenido {$top['percentage']}% similar al recurso \"{$top['title']}\" (ID: {$top['id']})",
            'similar_id' => $top['id'],
        ];
    }

    return ['status' => 'approved', 'reason' => null, 'similar_id' => null];
}

/**
 * Generate content hash for a resource.
 */
function contentHash(string $content): string
{
    return md5($content);
}

/**
 * Check rate limit: max resources per day per user.
 */
function checkRateLimit(PDO $db, int $userId, int $maxPerDay = 5): bool
{
    if (!isModerationEnabled())
        return true;

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM resources
        WHERE author_user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
    ");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() < $maxPerDay;
}
