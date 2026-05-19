<?php
// ================================================================
// api/og-image.php — Dynamic Open Graph Image Generator
//
// Generates a branded 1200×630 PNG card for social sharing.
// Used as og:image for resource detail pages.
//
// URL: /api/og-image.php?id={resource_id}
// Auth: None (public)
// Cache: Images are cached in /tmp/og-cache/ for 24 hours
// ================================================================

require_once __DIR__ . '/../shared/db.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Missing id');
}

// ── Cache check ──────────────────────────────────────────────
$cacheDir = sys_get_temp_dir() . '/og-cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$cachePath = $cacheDir . '/og-' . $id . '.png';
$cacheMaxAge = 86400; // 24 hours

if (file_exists($cachePath) && (time() - filemtime($cachePath)) < $cacheMaxAge) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    readfile($cachePath);
    exit;
}

// ── Fetch resource ───────────────────────────────────────────
$db = getResourcesDB();
$stmt = $db->prepare("
    SELECT r.title, r.description, r.subject_area, r.code_type,
           r.author_display_name, r.view_count, r.like_count, r.lang,
           r.level, r.source_name, c.name AS category_name
    FROM resources r
    LEFT JOIN categories c ON r.category_id = c.id
    WHERE r.id = ? AND r.is_active = 1 AND r.visibility = 'community'
");
$stmt->execute([$id]);
$r = $stmt->fetch();

if (!$r) {
    http_response_code(404);
    exit('Resource not found');
}

// ── Create image ─────────────────────────────────────────────
$w = 1200;
$h = 630;
$img = imagecreatetruecolor($w, $h);
imagealphablending($img, true);

// ── Gradient background ──────────────────────────────────────
// Deep purple → teal gradient (matching the iarepo brand)
for ($y = 0; $y < $h; $y++) {
    $ratio = $y / $h;
    $red   = (int) (15 + $ratio * 5);       // 15 → 20
    $green = (int) (14 + $ratio * 25);       // 14 → 39
    $blue  = (int) (40 + $ratio * 20);       // 40 → 60
    $color = imagecolorallocate($img, $red, $green, $blue);
    imageline($img, 0, $y, $w, $y, $color);
}

// ── Decorative accent circles ────────────────────────────────
// Top-right glow (purple)
$purple = imagecolorallocatealpha($img, 124, 58, 237, 100);
imagefilledellipse($img, $w - 120, -40, 400, 400, $purple);

// Bottom-left glow (cyan)
$cyan = imagecolorallocatealpha($img, 6, 182, 212, 105);
imagefilledellipse($img, 100, $h + 60, 350, 350, $cyan);

// ── Fonts ────────────────────────────────────────────────────
$fontBold   = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

if (!file_exists($fontBold)) {
    $fontBold = $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
}

// ── Colors ───────────────────────────────────────────────────
$white      = imagecolorallocate($img, 226, 232, 240);  // --text light
$whiteTitle = imagecolorallocate($img, 248, 250, 252);  // brighter for title
$gray       = imagecolorallocate($img, 148, 163, 184);  // --text3
$accentPurple = imagecolorallocate($img, 167, 139, 250); // lighter purple for accent
$accentCyan   = imagecolorallocate($img, 6, 182, 212);   // cyan accent

// ── Card background (semi-transparent) ──────────────────────
$cardBg = imagecolorallocatealpha($img, 30, 41, 59, 60); // #1e293b with alpha
imagefilledrectangle($img, 60, 60, $w - 60, $h - 60, $cardBg);

// Card border top accent line (gradient-like purple→cyan)
for ($x = 60; $x < $w - 60; $x++) {
    $ratio = ($x - 60) / ($w - 120);
    $cr = (int) (124 + $ratio * (6 - 124));
    $cg = (int) (58 + $ratio * (182 - 58));
    $cb = (int) (237 + $ratio * (212 - 237));
    $lineColor = imagecolorallocate($img, $cr, $cg, $cb);
    imageline($img, $x, 60, $x, 63, $lineColor);
}

// ── "iarepo" badge (top-left) ────────────────────────────────
// Badge background
$badgeBg = imagecolorallocatealpha($img, 124, 58, 237, 80);
imagefilledrectangle($img, 80, 85, 210, 120, $badgeBg);

imagettftext($img, 14, 0, 92, 112, $whiteTitle, $fontBold, 'iarepo');

// ── Code type + Language badge (top-right) ───────────────────
$typeText = strtoupper($r['code_type'] ?? 'HTML');
$langFlag = match($r['lang'] ?? '') { 'es' => 'ES', 'en' => 'EN', 'pt' => 'PT', default => '' };
$badgeText = $typeText . ($langFlag ? ' · ' . $langFlag : '');

$bbox = imagettfbbox(11, 0, $fontRegular, $badgeText);
$badgeWidth = $bbox[2] - $bbox[0] + 24;
$badgeX = $w - 80 - $badgeWidth;

$typeBadgeBg = imagecolorallocatealpha($img, 6, 182, 212, 85);
imagefilledrectangle($img, $badgeX, 85, $w - 80, 118, $typeBadgeBg);
imagettftext($img, 11, 0, $badgeX + 12, 109, $whiteTitle, $fontRegular, $badgeText);

// ── Title ────────────────────────────────────────────────────
$title = mb_substr($r['title'], 0, 80, 'UTF-8');
if (mb_strlen($r['title'], 'UTF-8') > 80) $title .= '…';

// Word-wrap title for multi-line rendering
$titleLines = wrapText($title, $fontBold, 28, $w - 200);
$titleY = 190;
foreach ($titleLines as $i => $line) {
    if ($i >= 3) break; // Max 3 lines
    imagettftext($img, 28, 0, 90, $titleY, $whiteTitle, $fontBold, $line);
    $titleY += 42;
}

// ── Description ──────────────────────────────────────────────
if ($r['description']) {
    $desc = mb_substr($r['description'], 0, 120, 'UTF-8');
    if (mb_strlen($r['description'], 'UTF-8') > 120) $desc .= '…';

    $descLines = wrapText($desc, $fontRegular, 14, $w - 200);
    $descY = $titleY + 16;
    foreach ($descLines as $i => $line) {
        if ($i >= 2) break; // Max 2 lines
        imagettftext($img, 14, 0, 90, $descY, $gray, $fontRegular, $line);
        $descY += 24;
    }
}

// ── Bottom metadata bar ──────────────────────────────────────
$metaY = $h - 110;

// Subject area / Category
$area = $r['category_name'] ?? $r['subject_area'] ?? '';
if ($area) {
    imagettftext($img, 13, 0, 90, $metaY, $accentCyan, $fontRegular, $area);
}

// Author
if ($r['author_display_name']) {
    $authorText = '👤 ' . $r['author_display_name'];
    imagettftext($img, 12, 0, 90, $metaY + 30, $gray, $fontRegular, $authorText);
}

// Stats (right side)
$statsText = '👁 ' . number_format((int) $r['view_count']) . '   ❤ ' . number_format((int) $r['like_count']);
$statsBbox = imagettfbbox(12, 0, $fontRegular, $statsText);
$statsWidth = $statsBbox[2] - $statsBbox[0];
imagettftext($img, 12, 0, $w - 90 - $statsWidth, $metaY + 30, $gray, $fontRegular, $statsText);

// Source
if ($r['source_name']) {
    $sourceText = '📎 ' . $r['source_name'];
    $srcBbox = imagettfbbox(11, 0, $fontRegular, $sourceText);
    $srcWidth = $srcBbox[2] - $srcBbox[0];
    imagettftext($img, 11, 0, $w - 90 - $srcWidth, $metaY, $accentPurple, $fontRegular, $sourceText);
}

// ── Bottom line (branding) ───────────────────────────────────
$brandText = 'iarepo.com — Open Educational Resources';
$brandBbox = imagettfbbox(11, 0, $fontRegular, $brandText);
$brandWidth = $brandBbox[2] - $brandBbox[0];
imagettftext($img, 11, 0, ($w - $brandWidth) / 2, $h - 35, $gray, $fontRegular, $brandText);

// ── Save & serve ─────────────────────────────────────────────
imagepng($img, $cachePath, 6); // quality 6 (0=no compression, 9=max)
imagedestroy($img);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
readfile($cachePath);
exit;


// ══════════════════════════════════════════════════════════════
// HELPER: Word-wrap text to fit within a max pixel width
// ══════════════════════════════════════════════════════════════
function wrapText(string $text, string $font, int $fontSize, int $maxWidth): array {
    $words = explode(' ', $text);
    $lines = [];
    $currentLine = '';

    foreach ($words as $word) {
        $testLine = $currentLine ? $currentLine . ' ' . $word : $word;
        $bbox = imagettfbbox($fontSize, 0, $font, $testLine);
        $lineWidth = $bbox[2] - $bbox[0];

        if ($lineWidth > $maxWidth && $currentLine !== '') {
            $lines[] = $currentLine;
            $currentLine = $word;
        } else {
            $currentLine = $testLine;
        }
    }
    if ($currentLine !== '') {
        $lines[] = $currentLine;
    }

    return $lines;
}
