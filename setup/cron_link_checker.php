<?php
// ================================================================
// setup/cron_link_checker.php — Background URL health checker
//
// Checks URL-type resources to verify their links are still live.
// Marks broken links so they don't appear in the catalog.
//
// Cron setup (run every 6 hours):
//   0 */6 * * * php /path/to/resources/setup/cron_link_checker.php >> /tmp/link_checker.log 2>&1
// ================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../shared/db.php';

$db = getResourcesDB();

// Fetch URL resources that haven't been checked in 24h (or never)
$stmt = $db->prepare("
    SELECT id, title, code_content, link_status
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
    exit(0);
}

$update = $db->prepare("
    UPDATE resources SET link_status = ?, link_checked_at = NOW() WHERE id = ?
");

$checked = 0;
$broken = 0;

foreach ($resources as $r) {
    $url = trim($r['code_content']);
    $id = (int) $r['id'];

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        $update->execute(['broken', $id]);
        echo date('Y-m-d H:i:s') . " [INVALID]  ID=$id \"{$r['title']}\" — not a valid URL\n";
        $broken++;
        $checked++;
        continue;
    }

    $status = checkUrl($url);
    $update->execute([$status, $id]);

    $icon = match ($status) {
        'ok'      => '✅',
        'broken'  => '❌',
        'timeout' => '⏱️',
        default   => '❓',
    };

    if ($status !== 'ok') {
        echo date('Y-m-d H:i:s') . " $icon ID=$id \"{$r['title']}\" — $status ($url)\n";
        $broken++;
    }

    $checked++;

    // Be polite: 1 second between requests
    usleep(500_000);
}

echo date('Y-m-d H:i:s') . " Checked $checked URLs, $broken issues found\n";

// ── URL check function ──
function checkUrl(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,        // HEAD request only
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,          // 15 second timeout
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'iarepo-link-checker/1.0 (+https://iarepo.com)',
    ]);

    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);

    // Timeout
    if ($error === CURLE_OPERATION_TIMEDOUT || $error === CURLE_COULDNT_CONNECT) {
        return 'timeout';
    }

    // Connection failed
    if ($error !== 0 || $httpCode === 0) {
        return 'broken';
    }

    // Server errors or not found
    if ($httpCode >= 400) {
        // Some sites block HEAD requests, try GET
        if ($httpCode === 403 || $httpCode === 405 || $httpCode === 406) {
            return checkUrlGet($url);
        }
        return 'broken';
    }

    return 'ok';
}

// Fallback: GET request for sites that block HEAD
function checkUrlGet(string $url): string
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
        CURLOPT_RANGE          => '0-1024',  // Only download first 1KB
    ]);

    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);

    if ($error !== 0 || $httpCode === 0) return 'broken';
    if ($httpCode >= 400) return 'broken';
    return 'ok';
}
