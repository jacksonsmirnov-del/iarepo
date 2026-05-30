<?php
// ================================================================
// sitemap.php — Dynamic XML sitemap for iarepo.com
// ================================================================

require_once __DIR__ . '/shared/db.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$db = getResourcesDB();
$baseUrl = 'https://iarepo.com';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

// Homepage
echo "  <url>\n    <loc>{$baseUrl}/</loc>\n    <changefreq>daily</changefreq>\n    <priority>1.0</priority>\n  </url>\n";

// All active community resources (detail pages only — viewer excluded)
$stmt = $db->query("
    SELECT r.id, r.title, r.updated_at, r.created_at,
           r.source_name,
           CASE WHEN t.og_exists = 1 THEN 1 ELSE 0 END AS has_thumbnail
    FROM resources r
    LEFT JOIN (SELECT 1 AS og_exists) t ON 0=1
    WHERE r.is_active = 1
      AND r.visibility = 'community'
      AND r.moderation_status = 'approved'
    ORDER BY r.id ASC
");

while ($row = $stmt->fetch()) {
    $lastmod = date('Y-m-d', strtotime($row['updated_at'] ?? $row['created_at']));
    $id      = (int) $row['id'];
    $title   = htmlspecialchars($row['title'], ENT_XML1, 'UTF-8');
    $ogUrl   = "{$baseUrl}/api/og-image.php?id={$id}";

    echo "  <url>\n";
    echo "    <loc>{$baseUrl}/resource/{$id}</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "    <image:image>\n";
    echo "      <image:loc>{$ogUrl}</image:loc>\n";
    echo "      <image:title>{$title}</image:title>\n";
    if ($row['source_name']) {
        echo "      <image:caption>" . htmlspecialchars($row['source_name'], ENT_XML1, 'UTF-8') . "</image:caption>\n";
    }
    echo "    </image:image>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
