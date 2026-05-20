<?php
// ================================================================
// api/og-image.php — Dynamic Open Graph Image Generator
//
// Generates a branded 1200×630 PNG card for social sharing.
// Optimized for MOBILE readability (WhatsApp, Telegram, etc.)
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
imagesavealpha($img, true);

// ── Solid dark background ────────────────────────────────────
$bgColor = imagecolorallocate($img, 15, 23, 42); // #0f172a (slate-900)
imagefilledrectangle($img, 0, 0, $w, $h, $bgColor);

// ── Left accent bar (gradient purple→cyan) ───────────────────
for ($y = 0; $y < $h; $y++) {
    $ratio = $y / $h;
    $cr = (int) (124 + $ratio * (6 - 124));
    $cg = (int) (58 + $ratio * (182 - 58));
    $cb = (int) (237 + $ratio * (212 - 237));
    $barColor = imagecolorallocate($img, $cr, $cg, $cb);
    imagefilledrectangle($img, 0, $y, 8, $y, $barColor);
}

// ── Subtle background glow ───────────────────────────────────
$glow1 = imagecolorallocatealpha($img, 124, 58, 237, 115);
imagefilledellipse($img, $w - 100, 80, 500, 500, $glow1);
$glow2 = imagecolorallocatealpha($img, 6, 182, 212, 118);
imagefilledellipse($img, 200, $h - 50, 400, 400, $glow2);

// ── Fonts (with fallback chain for different servers) ────────
$fontCandidates = [
    'bold' => [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/google-droid/DroidSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSansMono-Bold.ttf',
    ],
    'regular' => [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/google-droid/DroidSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
        '/usr/share/fonts/dejavu/DejaVuSansMono.ttf',
    ],
];

$fontBold = $fontRegular = null;
foreach ($fontCandidates['bold'] as $path) {
    if (file_exists($path)) { $fontBold = $path; break; }
}
foreach ($fontCandidates['regular'] as $path) {
    if (file_exists($path)) { $fontRegular = $path; break; }
}
if (!$fontRegular) $fontRegular = $fontBold = '';
if (!$fontBold)    $fontBold = $fontRegular;

// ── Colors ───────────────────────────────────────────────────
$white       = imagecolorallocate($img, 255, 255, 255);
$lightGray   = imagecolorallocate($img, 203, 213, 225);  // slate-300
$medGray     = imagecolorallocate($img, 148, 163, 184);  // slate-400
$purple      = imagecolorallocate($img, 167, 139, 250);  // purple-400
$cyan        = imagecolorallocate($img, 34, 211, 238);   // cyan-400
$green       = imagecolorallocate($img, 74, 222, 128);   // green-400

// ── Layout constants (generous padding for readability) ──────
$padL = 60;  // left padding
$padR = 60;  // right padding
$maxTextW = $w - $padL - $padR;

// ── Category / Subject pill (top) ────────────────────────────
$area = $r['category_name'] ?? $r['subject_area'] ?? '';
$topY = 60;

if ($area) {
    $areaBbox = imagettfbbox(20, 0, $fontBold, $area);
    $areaPillW = $areaBbox[2] - $areaBbox[0] + 32;
    $pillBg = imagecolorallocatealpha($img, 6, 182, 212, 90);
    // Rounded rectangle approximation
    imagefilledrectangle($img, $padL, $topY, $padL + $areaPillW, $topY + 40, $pillBg);
    imagettftext($img, 20, 0, $padL + 16, $topY + 29, $white, $fontBold, $area);
    $topY += 60;
} else {
    $topY += 10;
}

// ── Title (BIG — optimized for mobile readability) ───────────
$title = mb_substr($r['title'], 0, 70, 'UTF-8');
if (mb_strlen($r['title'], 'UTF-8') > 70) $title .= '...';

$titleLines = wrapText($title, $fontBold, 42, $maxTextW);
foreach ($titleLines as $i => $line) {
    if ($i >= 2) break; // Max 2 lines
    imagettftext($img, 42, 0, $padL, $topY + 50 + ($i * 58), $white, $fontBold, $line);
}
$titleBottomY = $topY + 50 + (min(count($titleLines), 2) * 58);

// ── Description (medium size, readable) ──────────────────────
if ($r['description']) {
    $desc = mb_substr($r['description'], 0, 100, 'UTF-8');
    if (mb_strlen($r['description'], 'UTF-8') > 100) $desc .= '...';

    $descLines = wrapText($desc, $fontRegular, 22, $maxTextW);
    $descY = $titleBottomY + 20;
    foreach ($descLines as $i => $line) {
        if ($i >= 2) break; // Max 2 lines
        imagettftext($img, 22, 0, $padL, $descY + ($i * 34), $lightGray, $fontRegular, $line);
    }
}

// ── Bottom bar ───────────────────────────────────────────────
$bottomY = $h - 80;

// Separator line
$sepColor = imagecolorallocatealpha($img, 148, 163, 184, 100);
imageline($img, $padL, $bottomY - 20, $w - $padR, $bottomY - 20, $sepColor);

// Left: iarepo branding
imagettftext($img, 24, 0, $padL, $bottomY + 8, $purple, $fontBold, 'iarepo');

// Author name after branding
if ($r['author_display_name']) {
    $brandBbox = imagettfbbox(24, 0, $fontBold, 'iarepo');
    $brandW = $brandBbox[2] - $brandBbox[0];
    $separator = '  /  ';
    imagettftext($img, 18, 0, $padL + $brandW + 12, $bottomY + 5, $medGray, $fontRegular, $separator . $r['author_display_name']);
}

// Right: code_type + lang badge
$typeText = strtoupper($r['code_type'] ?? 'HTML');
$langText = match($r['lang'] ?? '') { 'es' => 'ES', 'en' => 'EN', 'pt' => 'PT', default => '' };
$badgeText = $typeText . ($langText ? '  ' . $langText : '');

$badgeBbox = imagettfbbox(18, 0, $fontBold, $badgeText);
$badgeW = $badgeBbox[2] - $badgeBbox[0] + 28;
$badgeX = $w - $padR - $badgeW;
$badgeBg = imagecolorallocatealpha($img, 30, 41, 59, 40);
imagefilledrectangle($img, $badgeX, $bottomY - 12, $w - $padR, $bottomY + 18, $badgeBg);
imagettftext($img, 18, 0, $badgeX + 14, $bottomY + 10, $cyan, $fontBold, $badgeText);

// ── Save & serve ─────────────────────────────────────────────
imagepng($img, $cachePath, 6);
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
