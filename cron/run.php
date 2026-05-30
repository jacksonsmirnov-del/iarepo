<?php
// ================================================================
// cron/run.php — Secure web-callable cron runner
//
// Called by cron-job.org (or any external scheduler) via HTTPS.
// Requires CRON_SECRET token to prevent unauthorized execution.
//
// Jobs:
//   GET /cron/run.php?job=link_check&token=SECRET  (every 6h)
//   GET /cron/run.php?job=moderation&token=SECRET  (every 2min)
//
// Setup on cron-job.org:
//   https://iarepo.com/cron/run.php?job=link_check&token=YOUR_SECRET
//   https://iarepo.com/cron/run.php?job=moderation&token=YOUR_SECRET
// ================================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

require_once __DIR__ . '/../shared/db.php';

// Load env for CRON_SECRET
$envFile = __DIR__ . '/../.env.php';
if (!file_exists($envFile)) {
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'Configuration missing']));
}
$env = require $envFile;

$secret = $env['CRON_SECRET'] ?? '';
$token  = $_GET['token'] ?? '';
$job    = $_GET['job'] ?? '';

if (!$secret || !hash_equals($secret, $token)) {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

if (!in_array($job, ['link_check', 'moderation'])) {
    http_response_code(400);
    die(json_encode(['ok' => false, 'error' => 'Unknown job. Use: link_check, moderation']));
}

$start = microtime(true);
$db    = getResourcesDB();

// ── Job: link_check ──────────────────────────────────────────
if ($job === 'link_check') {
    $stmt = $db->prepare("
        SELECT id, title, code_content
        FROM resources
        WHERE code_type = 'url'
          AND is_active = 1
          AND (link_checked_at IS NULL OR link_checked_at < NOW() - INTERVAL 24 HOUR)
        ORDER BY link_checked_at ASC
        LIMIT 20
    ");
    $stmt->execute();
    $resources = $stmt->fetchAll();

    if (empty($resources)) {
        echo json_encode(['ok' => true, 'job' => 'link_check', 'checked' => 0, 'ms' => 0]);
        exit;
    }

    $update  = $db->prepare("UPDATE resources SET link_status = ?, iframe_blocked = ?, link_checked_at = NOW() WHERE id = ?");
    $checked = 0;
    $broken  = 0;
    $log     = [];

    foreach ($resources as $r) {
        $url = trim($r['code_content']);
        $id  = (int) $r['id'];

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $update->execute(['broken', 0, $id]);
            $log[] = ['id' => $id, 'status' => 'broken', 'reason' => 'invalid_url'];
            $broken++;
            $checked++;
            continue;
        }

        [$status, $iframeBlocked] = _checkUrlFull($url);
        $update->execute([$status, $iframeBlocked ? 1 : 0, $id]);
        if ($status !== 'ok' || $iframeBlocked) {
            $log[] = ['id' => $id, 'title' => $r['title'], 'status' => $status, 'iframe_blocked' => $iframeBlocked];
            if ($status !== 'ok') $broken++;
        }
        $checked++;
        usleep(300_000);
    }

    $ms = (int) ((microtime(true) - $start) * 1000);
    echo json_encode(['ok' => true, 'job' => 'link_check', 'checked' => $checked, 'broken' => $broken, 'ms' => $ms, 'issues' => $log]);
    exit;
}

// ── Job: moderation ───────────────────────────────────────────
if ($job === 'moderation') {
    require_once __DIR__ . '/../shared/similarity.php';

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
            $ms = (int) ((microtime(true) - $start) * 1000);
            echo json_encode(['ok' => true, 'job' => 'moderation', 'processed' => 0, 'ms' => $ms]);
            exit;
        }

        $processed = 0;
        $log       = [];

        foreach ($pending as $resource) {
            $id         = (int) $resource['id'];
            $content    = $resource['code_content'];
            $categoryId = $resource['category_id'] ? (int) $resource['category_id'] : null;

            if (empty(trim($content))) {
                $db->prepare("UPDATE resources SET moderation_status = 'approved' WHERE id = ?")->execute([$id]);
                $log[] = ['id' => $id, 'result' => 'approved', 'reason' => 'empty_content'];
                $processed++;
                continue;
            }

            $matches = findSimilarResources($db, $content, $categoryId, 0.25, 200);
            $matches = array_values(array_filter($matches, fn($m) => $m['id'] !== $id));

            if (empty($matches) || $matches[0]['score'] < 0.5) {
                $db->prepare("UPDATE resources SET moderation_status = 'approved' WHERE id = ?")->execute([$id]);
                $log[] = ['id' => $id, 'result' => 'approved'];
            } elseif ($matches[0]['score'] >= 0.95) {
                $db->prepare("UPDATE resources SET moderation_status = 'rejected' WHERE id = ?")->execute([$id]);
                $log[] = ['id' => $id, 'result' => 'rejected', 'similar_to' => $matches[0]['id'], 'pct' => $matches[0]['percentage']];
            } else {
                $db->prepare("UPDATE resources SET moderation_status = 'under_review' WHERE id = ?")->execute([$id]);
                $log[] = ['id' => $id, 'result' => 'under_review', 'similar_to' => $matches[0]['id'], 'pct' => $matches[0]['percentage']];
            }
            $processed++;
        }

        $db->commit();
        $ms = (int) ((microtime(true) - $start) * 1000);
        echo json_encode(['ok' => true, 'job' => 'moderation', 'processed' => $processed, 'ms' => $ms, 'log' => $log]);
    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'job' => 'moderation', 'error' => $e->getMessage()]);
    }
    exit;
}

// ── URL check helpers ─────────────────────────────────────────

// Returns [status, iframe_blocked]
function _checkUrlFull(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'iarepo-link-checker/1.0 (+https://iarepo.com)',
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_errno($ch);
    curl_close($ch);

    if ($error === CURLE_OPERATION_TIMEDOUT || $error === CURLE_COULDNT_CONNECT) return ['timeout', false];
    if ($error !== 0 || $httpCode === 0) return ['broken', false];
    if ($httpCode >= 400) {
        if (in_array($httpCode, [403, 405, 406])) {
            $status = _checkUrlGet($url);
            return [$status, false];
        }
        return ['broken', false];
    }

    $iframeBlocked = _headersBlockIframe((string) $response);
    return ['ok', $iframeBlocked];
}

function _headersBlockIframe(string $rawHeaders): bool
{
    $headers = strtolower($rawHeaders);
    if (preg_match('/x-frame-options:\s*(deny|sameorigin)/i', $headers)) return true;
    if (preg_match('/content-security-policy:[^\n]*frame-ancestors[^\n]*(\'none\'|\'self\')/i', $headers)) return true;
    return false;
}

function _checkUrlGet(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; iarepo/1.0)',
        CURLOPT_RANGE          => '0-1024',
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_errno($ch);
    curl_close($ch);

    if ($error !== 0 || $httpCode === 0) return 'broken';
    if ($httpCode >= 400) return 'broken';
    return 'ok';
}
