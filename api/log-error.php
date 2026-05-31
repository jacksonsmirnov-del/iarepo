<?php
// ================================================================
// api/log-error.php — Receptor de errores JS del cliente
//
// POST /api/log-error.php
// Body: {message, source, lineno, page}
//
// Sin auth requerida — solo acepta POST, sin datos sensibles.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/cors.php';

cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['message'])) {
    http_response_code(400);
    exit;
}

$message   = mb_substr(trim($data['message'] ?? ''), 0, 1000);
$source    = mb_substr(trim($data['source'] ?? ''), 0, 200);
$lineno    = (int) ($data['lineno'] ?? 0);
$page      = mb_substr(trim($data['page'] ?? ''), 0, 500);
$userAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

// Ignorar errores de extensiones de browser y cross-origin genéricos
if ($message === 'Script error.' || $message === '') {
    http_response_code(204);
    exit;
}

try {
    $db = getResourcesDB();
    $db->prepare("
        INSERT INTO client_error_log (message, source, lineno, page_url, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$message, $source, $lineno, $page, $userAgent]);
} catch (Throwable $e) {
    // Silencioso — no queremos cascada de errores
}

http_response_code(204);
