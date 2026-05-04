<?php
// ================================================================
// shared/similarity.php — Content Similarity Engine
//
// Uses shingling + Jaccard coefficient for robust plagiarism detection.
// Works with any text: HTML, prompts, code, etc.
// No external dependencies — pure PHP.
//
// Usage:
//   require_once __DIR__ . '/similarity.php';
//   $score = jaccardSimilarity($textA, $textB);       // 0.0–1.0
//   $matches = findSimilarResources($db, $content);    // [{id, title, score}, ...]
// ================================================================

/**
 * Normalize content for comparison:
 * - Strip HTML tags
 * - Lowercase
 * - Collapse whitespace
 * - Remove common noise (comments, boilerplate)
 */
function normalizeContent(string $content): string
{
    // Strip HTML tags
    $text = strip_tags($content);

    // Remove JS/CSS comments
    $text = preg_replace('#/\*.*?\*/#s', '', $text);
    $text = preg_replace('#//[^\n]*#', '', $text);

    // Remove common HTML boilerplate keywords
    $text = preg_replace('/\b(DOCTYPE|html|head|body|meta|charset|viewport|script|style|link|div|span|class|function|var|let|const|return)\b/i', '', $text);

    // Lowercase + collapse whitespace
    $text = mb_strtolower($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    return $text;
}

/**
 * Create shingles (overlapping n-grams of words) from text.
 *
 * @param string $text  Normalized text
 * @param int    $n     Shingle size (default 3 words)
 * @return array<string> Set of shingles
 */
function createShingles(string $text, int $n = 3): array
{
    $words = preg_split('/\s+/', $text);
    if (count($words) < $n)
        return [$text]; // Too short — use whole text as single shingle

    $shingles = [];
    $count = count($words) - $n + 1;
    for ($i = 0; $i < $count; $i++) {
        $shingles[] = implode(' ', array_slice($words, $i, $n));
    }

    return array_unique($shingles);
}

/**
 * Jaccard similarity coefficient between two sets.
 * J(A,B) = |A ∩ B| / |A ∪ B|
 *
 * @return float 0.0 (completely different) to 1.0 (identical)
 */
function jaccardSimilarity(string $textA, string $textB, int $shingleSize = 3): float
{
    $normalA = normalizeContent($textA);
    $normalB = normalizeContent($textB);

    // Too short for meaningful comparison
    if (strlen($normalA) < 50 || strlen($normalB) < 50)
        return 0.0;

    $shinglesA = createShingles($normalA, $shingleSize);
    $shinglesB = createShingles($normalB, $shingleSize);

    $intersection = count(array_intersect($shinglesA, $shinglesB));
    $union = count(array_unique(array_merge($shinglesA, $shinglesB)));

    if ($union === 0)
        return 0.0;

    return round($intersection / $union, 4);
}

/**
 * Find resources similar to the given content.
 *
 * @param PDO    $db         Database connection
 * @param string $content    Code content to check
 * @param int    $categoryId Optional — limit to same category (faster)
 * @param float  $threshold  Minimum similarity score (0.0–1.0)
 * @param int    $limit      Max candidates to compare against
 * @return array [{id, title, score, category_name}, ...] sorted by score DESC
 */
function findSimilarResources(
    PDO $db,
    string $content,
    ?int $categoryId = null,
    float $threshold = 0.25,
    int $limit = 200
): array {
    if (empty(trim($content)))
        return [];

    // 1. Quick check: exact hash match
    $hash = md5($content);
    $stmt = $db->prepare("
        SELECT id, title, 'exact' AS match_type
        FROM resources
        WHERE content_hash = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$hash]);
    $exact = $stmt->fetch();
    if ($exact) {
        return [[
            'id' => (int) $exact['id'],
            'title' => $exact['title'],
            'score' => 1.0,
            'match_type' => 'exact_duplicate',
        ]];
    }

    // 2. Fetch candidates for comparison
    $params = [];
    $catFilter = '';
    if ($categoryId) {
        $catFilter = 'AND r.category_id = ?';
        $params[] = $categoryId;
    }

    $stmt = $db->prepare("
        SELECT r.id, r.title, r.code_content, c.name AS category_name
        FROM resources r
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE r.is_active = 1
          AND r.code_content IS NOT NULL
          AND LENGTH(r.code_content) > 100
          $catFilter
        ORDER BY r.created_at DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $candidates = $stmt->fetchAll();

    // 3. Compare using Jaccard similarity
    $matches = [];
    foreach ($candidates as $candidate) {
        $score = jaccardSimilarity($content, $candidate['code_content']);

        if ($score >= $threshold) {
            $matches[] = [
                'id' => (int) $candidate['id'],
                'title' => $candidate['title'],
                'score' => $score,
                'percentage' => round($score * 100, 1),
                'category_name' => $candidate['category_name'],
                'match_type' => $score > 0.8 ? 'very_similar' : ($score > 0.5 ? 'similar' : 'partial'),
            ];
        }
    }

    // Sort by score descending
    usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($matches, 0, 10); // Top 10 matches
}
