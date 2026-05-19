<?php
// ================================================================
// sitemap.php — Dynamic XML sitemap for iarepo.com
//
// URL: /sitemap.xml (via .htaccess rewrite)
// Generates a sitemap with all active, community-visible resources.
// ================================================================

require_once __DIR__ . '/shared/db.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600'); // Cache 1 hour

$db = getResourcesDB();

$baseUrl = 'https://iarepo.com';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Homepage
echo "  <url>\n";
echo "    <loc>{$baseUrl}/</loc>\n";
echo "    <changefreq>daily</changefreq>\n";
echo "    <priority>1.0</priority>\n";
echo "  </url>\n";

// All active community resources
$stmt = $db->query("
    SELECT id, updated_at, created_at
    FROM resources
    WHERE is_active = 1
      AND visibility = 'community'
    ORDER BY id ASC
");

while ($row = $stmt->fetch()) {
    $lastmod = date('Y-m-d', strtotime($row['updated_at'] ?? $row['created_at']));
    $id = (int) $row['id'];

    // Resource detail page
    echo "  <url>\n";
    echo "    <loc>{$baseUrl}/resource/{$id}</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";

    // Viewer page
    echo "  <url>\n";
    echo "    <loc>{$baseUrl}/view/{$id}</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
