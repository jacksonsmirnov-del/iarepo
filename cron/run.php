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
//
// LATIDOS: cada job registra en `cron_heartbeats` que ha terminado (bien o
// mal). Sin eso, un cron que deja de ser invocado es invisible — le pasó a
// link_check durante 66 días. Ver el bloque "LATIDO (heartbeat)" más abajo.
// La salida JSON de cada job es la MISMA que antes: el contrato con
// cron-job.org no cambia.
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

// ================================================================
// LATIDO (heartbeat) — que un cron muerto se vea
//
// EL PROBLEMA QUE RESUELVE
//   El link checker dejó de correr el 2026-05-30 y nadie lo supo hasta el
//   2026-08-04: 66 días. No falló — dejó de ser INVOCADO, que es un modo de
//   fallo que no produce ni logs ni errores ni respuestas raras. Este fichero
//   respondía perfectamente… a nadie.
//
//   A partir de aquí, cada job deja constancia de que ha terminado en la tabla
//   `cron_heartbeats` (setup/migration_010_cron_heartbeats.sql). Lo que se
//   vigila no es el resultado, es la ANTIGÜEDAD del último latido:
//   api/health.php la publica y quality/smoke_test.sh la pone en rojo cuando
//   supera el periodo del job por 3.
//
// INVARIANTES QUE NO SE PUEDEN ROMPER
//   1. La salida JSON no cambia. cron-job.org y cualquier otro consumidor
//      siguen viendo exactamente los mismos campos que antes.
//   2. El control del token no se toca: el latido se registra DESPUÉS de la
//      autenticación, así que una petición sin token no puede escribir nada.
//   3. Un fallo al registrar el latido NUNCA tumba el job. Todo va dentro de
//      un try/catch mudo: si la tabla aún no existe (código desplegado antes
//      que la migración, que es el orden normal) el job hace su trabajo igual.
// ================================================================

/**
 * Cada cuánto DEBE correr cada job, en segundos.
 *
 * Es la MISMA información que siembra setup/migration_010_cron_heartbeats.sql,
 * pero este fichero manda: se reescribe en cada latido, así que si alguna vez
 * divergen, el siguiente latido corrige la fila. Cambiar un periodo aquí exige
 * cambiarlo también en la planificación real (cron-job.org) — esta constante
 * describe lo que se espera, no lo que ocurre.
 */
const IAREPO_JOB_PERIODS = [
    'link_check' => 21600,  // 6 h
    'moderation' => 120,    // 2 min
];

/**
 * ¿Se ha registrado ya el latido de esta petición?
 *
 * Vive en una función con `static` en vez de en una global para que la red de
 * seguridad de abajo (register_shutdown_function) pueda consultarlo sin que
 * nadie más pueda pisarlo por accidente.
 */
function _heartbeatDone(?bool $set = null): bool
{
    static $done = false;
    if ($set !== null) $done = $set;
    return $done;
}

/**
 * Deja un mensaje de latido en condiciones de ser PUBLICADO.
 *
 * ESTO NO ES COSMÉTICA. api/health.php publica `message` sin autenticación, y
 * el mensaje de una excepción no capturada trae la ruta absoluta del fichero:
 *
 *   Uncaught PDOException: ... in <ruta absoluta del doc root>/cron/run.php:201
 *   Stack trace: #0 <ruta absoluta del doc root>/...
 *
 * O sea que sin esta función el primer cron que reventara publicaría el usuario
 * del hosting y el doc root en un endpoint abierto a internet. Se hacen tres
 * cosas, en este orden:
 *   1. cortar en "Stack trace:" (nunca aporta nada en 500 caracteres),
 *   2. quitar el prefijo del doc root, que se deduce de __DIR__,
 *   3. reducir cualquier ruta absoluta que quede a su nombre de fichero. Las
 *      URLs sobreviven: el (?<![\w.]) impide casar la barra que va detrás de un
 *      nombre de host.
 * Y por último se recorta a 480 caracteres (la columna es VARCHAR(500)).
 */
function _heartbeatScrub(?string $message): ?string
{
    if ($message === null) return null;

    $m = preg_replace('/\s*Stack trace:.*$/s', '', $message) ?? $message;
    $m = str_replace(dirname(__DIR__), '', $m);
    $m = preg_replace('#(?<![\w.])/(?:[\w.\-]+/)+([\w.\-]+)#', '$1', $m) ?? $m;
    $m = trim(preg_replace('/\s+/', ' ', $m) ?? '');

    if ($m === '') return null;
    return function_exists('mb_substr') ? mb_substr($m, 0, 480) : substr($m, 0, 480);
}

/**
 * Registra el latido del job. NO LANZA NUNCA.
 *
 * @param int    $items   elementos realmente procesados (0 si no había trabajo)
 * @param string $status  'ok' | 'error'
 * @param string $message resumen corto; en caso de error, el motivo
 */
function _heartbeat(?PDO $db, string $job, float $start, int $items, string $status, ?string $message = null): void
{
    _heartbeatDone(true);

    try {
        if (!$db instanceof PDO) return;

        // Si el job murió con una transacción abierta, el INSERT caería dentro
        // de ella y se perdería al cerrarse la conexión — justo en el caso en
        // que MÁS falta hace dejar constancia. Deshacerla aquí no destruye
        // nada: sin commit, el servidor la revertiría igual al colgarse la
        // conexión. Sólo ocurre por la red de seguridad; en el camino normal
        // la transacción ya está confirmada cuando se llama a esta función.
        if ($db->inTransaction()) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
        }

        $ms     = (int) round((microtime(true) - $start) * 1000);
        $st     = ($status === 'error') ? 'error' : 'ok';
        $period = IAREPO_JOB_PERIODS[$job] ?? 0;

        // La columna es VARCHAR(500): con STRICT_TRANS_TABLES un valor más
        // largo abortaría el INSERT y perderíamos el latido por un mensaje.
        $msg = _heartbeatScrub($message);

        // Una fila por job (PRIMARY KEY (job)): la tabla no crece nunca y no
        // necesita purga — una purga sería otro cron capaz de morir en
        // silencio, que es el problema que se está arreglando.
        $sql = "INSERT INTO cron_heartbeats
                    (job, last_run_at, duration_ms, items_processed, status, message,
                     last_ok_at, last_error_at, run_count, error_count, period_seconds)
                VALUES
                    (?, NOW(), ?, ?, ?, ?,
                     IF(? = 'ok', NOW(), NULL), IF(? = 'error', NOW(), NULL),
                     1, IF(? = 'error', 1, 0), ?)
                ON DUPLICATE KEY UPDATE
                    last_run_at     = VALUES(last_run_at),
                    duration_ms     = VALUES(duration_ms),
                    items_processed = VALUES(items_processed),
                    status          = VALUES(status),
                    message         = VALUES(message),
                    last_ok_at      = COALESCE(VALUES(last_ok_at),    last_ok_at),
                    last_error_at   = COALESCE(VALUES(last_error_at), last_error_at),
                    run_count       = run_count  + 1,
                    error_count     = error_count + VALUES(error_count),
                    period_seconds  = VALUES(period_seconds)";

        $db->prepare($sql)->execute([$job, $ms, max(0, $items), $st, $msg, $st, $st, $st, $period]);
    } catch (\Throwable $e) {
        // Mudo A PROPÓSITO. El latido es telemetría: que falte es un problema
        // menor; que su fallo tumbe el link checker sería un problema mayor.
        // Queda rastro en el error_log del servidor y nada más: escribir en la
        // salida rompería el JSON que espera el planificador.
        error_log('[iarepo] heartbeat failed for job=' . $job . ': ' . $e->getMessage());
    }
}

// ── Red de seguridad ─────────────────────────────────────────
// Los latidos del camino feliz se registran justo antes de cada `echo`. Pero
// un job puede morir SIN llegar a ninguno de ellos: un timeout de PHP
// (max_execution_time; link_check hace hasta 20 peticiones HTTP con
// usleep(300 ms) entre ellas), un agotamiento de memoria o una excepción no
// capturada. En esos casos no habría latido y el job parecería no invocado —
// indistinguible del cron muerto que queremos detectar.
//
// register_shutdown_function corre también tras un error fatal y tras `exit`,
// así que aquí se cierra el agujero SIN tocar la salida de ningún camino
// existente: si ya hubo latido, no hace nada.
register_shutdown_function(static function () use ($db, $job, $start): void {
    if (_heartbeatDone()) return;

    $fatal = error_get_last();
    $why   = 'terminó sin registrar resultado (¿timeout de PHP, memoria agotada o exit prematuro?)';
    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $why = $fatal['message'] . ' @ ' . basename((string) ($fatal['file'] ?? '?')) . ':' . ($fatal['line'] ?? 0);
    }

    _heartbeat($db, $job, $start, 0, 'error', $why);
});

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
        // "No había nada que revisar" es una ejecución CORRECTA y tiene que
        // latir igual: si no, el job parecería muerto justo cuando está al día.
        _heartbeat($db, $job, $start, 0, 'ok', 'sin enlaces pendientes de revisar');
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
    _heartbeat($db, $job, $start, $checked, 'ok', "checked=$checked broken=$broken");
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
            // Con la moderación APAGADA (OPEN_REGISTRATION=false) éste es el
            // camino normal de este job: cero pendientes, cada 2 minutos. Es
            // exactamente el latido que demuestra que el planificador vive.
            _heartbeat($db, $job, $start, 0, 'ok', 'sin recursos pendientes de moderar');
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
        // Después del commit: si el commit falla, se cae al catch y el latido
        // se registra como 'error', que es la verdad.
        _heartbeat($db, $job, $start, $processed, 'ok', "processed=$processed");
        echo json_encode(['ok' => true, 'job' => 'moderation', 'processed' => $processed, 'ms' => $ms, 'log' => $log]);
    } catch (Throwable $e) {
        $db->rollBack();
        // items=0 no es una aproximación: la transacción se acaba de revertir,
        // así que no quedó procesado ni un recurso.
        _heartbeat($db, $job, $start, 0, 'error', $e->getMessage());
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
