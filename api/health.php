<?php
// ================================================================
// api/health.php — Dedicated Health Check Endpoint
//
// GET /api/health.php → JSON with service status
//
// Used by Campus to verify Resources platform is reachable
// before rendering the resources UI. No auth required.
//
// ── CONTRATO ────────────────────────────────────────────────────
// Estos 8 campos SIEMPRE están y no cambian de nombre ni de tipo (hay
// consumidores externos, empezando por Campus):
//   ok · status · service · version · request_id · time · db · resources
//
// A partir del 2026-08 se AÑADEN (nunca se quitan):
//   commit / commit_full / deployed_at / deploy_subject
//        Qué commit está vivo en producción. Lo escribe el hook post-receive
//        (setup/hooks/post-receive) en deploy_version.txt al desplegar.
//        null si el fichero no existe → el hook no está instalado, o falló al
//        escribir. Nunca revienta por eso.
//   crons
//        Un objeto por job de cron/run.php con la ANTIGÜEDAD de su último
//        latido. null si la BD no responde.
//
// ── POR QUÉ `version` NO BASTA ──────────────────────────────────
// 'version' es la constante literal '1.1.0' escrita a mano: nunca ha cambiado
// y no dice NADA sobre qué código está corriendo. El 2026-08-04 se descubrió
// que el hook post-receive llevaba un mes muerto (permisos 644) y que el smoke
// test daba 44 checks en verde contra la versión vieja sin que nada chirriara.
// Se conserva 'version' porque es parte del contrato, pero lo que hay que mirar
// es 'commit'.
// ================================================================

require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/cors.php';

cors();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$result = [
    'ok'         => true,
    'status'     => 'healthy',
    'service'    => 'iarepo',
    'version'    => '1.1.0',
    'request_id' => request_id(),
    'time'       => date('c'),
];

// ── Qué commit está desplegado ───────────────────────────────
// Lectura de fichero: no depende de la BD, así que se resuelve ANTES del
// bloque de base de datos. Si producción está degradada, saber qué commit la
// dejó así es justo lo primero que hace falta.
//
// Formato de deploy_version.txt (lo escribe setup/hooks/post-receive):
//     commit=f97c284
//     commit_full=f97c28442586152566fc293a5ec4fde2ef9c09a0
//     deployed_at=2026-08-04T20:11:03Z
//     branch=main
//     subject=fix: declutter hero top
//
// Se acepta además la forma degradada "<sha> <fecha>" en una sola línea, por
// si alguna vez se escribe a mano tras un despliegue de emergencia.
$deploy = [
    'commit'         => null,
    'commit_full'    => null,
    'deployed_at'    => null,
    'deploy_subject' => null,
];

$versionFile = __DIR__ . '/../deploy_version.txt';
if (is_file($versionFile) && is_readable($versionFile)) {
    // Tope de tamaño: este fichero son ~200 bytes. Si alguien deja ahí un log
    // de 40 MB, health.php no puede convertirse en el que tumba el servidor.
    $raw = (string) @file_get_contents($versionFile, false, null, 0, 4096);

    if (strpos($raw, '=') !== false) {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = strtolower(trim($k));
            $v = trim($v);
            if ($v === '') continue;
            if ($k === 'commit')      $deploy['commit']         = health_sha($v);
            if ($k === 'commit_full') $deploy['commit_full']    = health_sha($v);
            if ($k === 'deployed_at') $deploy['deployed_at']    = health_stamp($v);
            if ($k === 'subject')     $deploy['deploy_subject'] = health_text($v, 200);
        }
    } else {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        if (isset($parts[0]) && $parts[0] !== '') $deploy['commit']      = health_sha($parts[0]);
        if (isset($parts[1]) && $parts[1] !== '') $deploy['deployed_at'] = health_stamp($parts[1]);
    }
}

$result += $deploy;

// ── Database check ───────────────────────────────────────────
$result['crons'] = null;

try {
    require_once __DIR__ . '/../shared/db.php';
    $db = getResourcesDB();
    $db->query('SELECT 1');
    $result['db'] = 'connected';

    // Resource count (cached insight)
    $count = $db->query("SELECT COUNT(*) FROM resources WHERE is_active = 1")->fetchColumn();
    $result['resources'] = (int) $count;

    $result['crons'] = health_crons($db);
} catch (\Throwable $e) {
    $result['ok'] = false;
    $result['status'] = 'degraded';
    $result['db'] = 'error';
    $result['db_error'] = is_debug() ? $e->getMessage() : 'Connection failed';
    http_response_code(503);
}

// ── Emisión ──────────────────────────────────────────────────
// json_encode() devuelve FALSE —no una excepción— si algún valor lleva UTF-8
// inválido, y `echo false` imprime CADENA VACÍA. O sea: HTTP 200 con cuerpo de
// 0 bytes. Medido: bastaba con que deploy_version.txt tuviera un byte corrupto
// (un fichero medio escrito, un disco lleno a mitad del mv) para que este
// endpoint dejara de devolver JSON y Campus se quedara sin poder comprobar si
// la plataforma está viva — por un fichero informativo.
// Por eso hay dos redes: sustituir los bytes inválidos y, si aun así falla, un
// cuerpo mínimo pero VÁLIDO con 500. Un health check no puede tener un modo de
// fallo silencioso.
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
}
if ($json === false) {
    http_response_code(500);
    $json = '{"ok":false,"status":"degraded","service":"iarepo","error":"health payload not encodable"}';
}
echo $json;

/**
 * Un SHA de git y nada más. Si no lo parece → null.
 *
 * deploy_version.txt lo escribe el hook post-receive, pero es un fichero suelto
 * del doc root: puede quedar a medias, corromperse o escribirlo un humano con
 * prisa durante una incidencia. Validar aquí evita publicar basura como si
 * fuera el commit vivo, que es peor que no publicar nada.
 */
function health_sha(string $v): ?string
{
    return preg_match('/^[0-9a-fA-F]{4,40}$/', $v) ? strtolower($v) : null;
}

/** Marca de tiempo ISO 8601 (o algo que se le parezca lo bastante). */
function health_stamp(string $v): ?string
{
    return preg_match('/^[0-9A-Za-z:+.\-]{4,40}$/', $v) ? $v : null;
}

/** Texto libre: UTF-8 válido, sin caracteres de control y acotado. */
function health_text(string $v, int $max): ?string
{
    if (preg_match('//u', $v) !== 1) return null;          // UTF-8 inválido
    $v = preg_replace('/[\p{C}]/u', '', $v) ?? '';         // control / invisibles
    $v = trim($v);
    if ($v === '') return null;
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

/**
 * Segunda línea de defensa contra publicar rutas del servidor.
 *
 * Quien escribe los mensajes de latido (cron/run.php, _heartbeatScrub) ya los
 * limpia. Esto se repite AQUÍ porque este endpoint es público y sin auth: si
 * algún día otro proceso —o un INSERT a mano durante una incidencia— deja en la
 * tabla el mensaje crudo de una excepción, saldría a internet la ruta absoluta
 * del doc root y el usuario del hosting. La limpieza en el punto de escritura
 * evita el problema; ésta evita que una escritura futura lo reintroduzca.
 */
function health_scrub_path(?string $s): ?string
{
    if ($s === null || $s === '') return $s;
    $s = str_replace(dirname(__DIR__), '', $s);
    return preg_replace('#(?<![\w.])/(?:[\w.\-]+/)+([\w.\-]+)#', '$1', $s) ?? $s;
}

/**
 * Estado de los cron a partir de la tabla de latidos.
 *
 * QUÉ DEVUELVE Y POR QUÉ ASÍ
 *   Un objeto por job, con `age_seconds` = cuánto hace que ese job terminó por
 *   última vez. Ése es el único número que delata un cron muerto: el resultado
 *   del último latido puede ser 'ok' perfecto y el job llevar 66 días parado.
 *
 *   age_seconds = null significa NUNCA HA LATIDO. No es lo mismo que "hace
 *   mucho", y no puede colapsarse a un número grande: un job recién declarado
 *   y un job muerto se arreglan de formas distintas.
 *
 *   `stale` lo calcula el servidor (age > periodo × 3) para que un consumidor
 *   —Campus, un panel, el smoke test— no tenga que saberse los periodos. El
 *   factor 3 da margen a un retraso puntual del planificador sin tragarse un
 *   job realmente parado.
 *
 * SI LA TABLA NO EXISTE devuelve null en vez de reventar: el código se
 * despliega antes que la migración, y durante esa ventana health.php tiene que
 * seguir respondiendo 200.
 */
function health_crons(PDO $db): ?array
{
    try {
        $rows = $db->query(
            'SELECT job, last_run_at, duration_ms, items_processed, status, message,
                    last_ok_at, last_error_at, run_count, error_count, period_seconds,
                    TIMESTAMPDIFF(SECOND, last_run_at, NOW()) AS age_seconds
               FROM cron_heartbeats
              ORDER BY job'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // Tabla ausente (migración 010 sin aplicar) o sin permisos: no es un
        // fallo del servicio, es información que todavía no existe.
        return null;
    }

    $out = [];
    foreach ($rows as $r) {
        $job    = (string) $r['job'];
        $age    = ($r['last_run_at'] === null || $r['age_seconds'] === null) ? null : (int) $r['age_seconds'];
        $period = (int) $r['period_seconds'];

        $out[$job] = [
            'age_seconds'     => $age,
            'last_run_at'     => $r['last_run_at'],
            'status'          => (string) $r['status'],
            'duration_ms'     => (int) $r['duration_ms'],
            'items_processed' => (int) $r['items_processed'],
            'message'         => health_scrub_path($r['message']),
            'last_ok_at'      => $r['last_ok_at'],
            'last_error_at'   => $r['last_error_at'],
            'run_count'       => (int) $r['run_count'],
            'error_count'     => (int) $r['error_count'],
            'period_seconds'  => $period,
            // Sin periodo declarado no se puede opinar: null, no false. Decir
            // "no está caducado" sin saberlo sería exactamente el falso verde
            // que este endpoint existe para eliminar.
            'stale'           => $period > 0 ? ($age === null || $age > $period * 3) : null,
        ];
    }

    return $out;
}
