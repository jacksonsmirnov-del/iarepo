<?php
// ================================================================
// api/track.php — Registro de visitas y engagement
//
// POST /api/track.php   {resource_id, vid, surface, engaged_secs, interacted}
//
// Auth: OPCIONAL. Un visitante anónimo tiene que poder contar — es la mayoría
// del tráfico y justo la que interesa medir.
//
// ── QUÉ ARREGLA ───────────────────────────────────────────────
// `view_count` no contaba /resource/N, que es donde de verdad se usa el
// recurso (la página lo renderiza funcionando en un iframe srcdoc), y donde
// contaba lo hacía por CARGA, sin deduplicar. Síntoma que lo destapó: 20
// alumnos trabajando, 8 visitas registradas.
//
// ── POR QUÉ UN ENDPOINT Y NO UN UPDATE EN LA PÁGINA ───────────
//   1. resource/index.php emite HTML y está en quality/baseline_html_helpers.txt:
//      carga helpers.php y con él error_handler.php, cuyos handlers hacen
//      echo json_encode(...) + exit(1). Cualquier fallo escribiendo la visita
//      sacaría la página a medio renderizar con un JSON incrustado — la trampa
//      nº1 del CLAUDE.md. Con un beacon, la página no toca la BD para esto.
//   2. Contar por beacon FILTRA LOS BOTS, que no ejecutan JavaScript. Parte de
//      las visitas históricas son crawlers; la métrica nueva nace limpia.
//   3. Permite enriquecer la MISMA fila con el tiempo activo sin una segunda
//      tabla ni una segunda petición.
//
// Contrapartida asumida: quien navegue sin JavaScript no cuenta. Es
// intrascendente aquí — todos los recursos del catálogo son simulaciones
// interactivas en JavaScript, así que sin JS no hay nada que visitar.
//
// ── PRIVACIDAD: LO QUE ESTE FICHERO NO HACE ───────────────────
// No guarda IPs. Ni en claro ni hasheadas. El identificador anónimo lo genera
// el propio navegador (assets/js/track.js) y sólo se almacena su sha256 con
// una sal diaria que se BORRA a los 2 días: pasada esa ventana, ni con acceso
// total a la base de datos se puede ligar una fila a un navegador, ni cruzar
// dos días de la misma persona.
//
// Se descartó el hash de IP —el diseño obvio— porque habría empeorado el caso
// que originó todo: los alumnos de un colegio salen por un NAT común, así que
// habría colapsado una clase entera en un único visitante.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/viewer_key.php';

cors();

$db = getResourcesDB();

if (request_method() !== 'POST') json_error('Method not allowed', 405);

// Límite alto a propósito: un aula entera comparte la IP del NAT del colegio.
// 20 alumnos × ~4 beacons por sesión no puede parecerse a un abuso, o
// estaríamos tirando justo las visitas que este endpoint existe para contar.
rateLimit($db, 'track', 600);

// Segundos de atención que se aceptan como máximo por fila.
//
// No es una preferencia de producto: engaged_secs es SMALLINT UNSIGNED y con
// STRICT_TRANS_TABLES un desbordamiento aborta el INSERT con ERROR 1264 — se
// perdería la VISITA ENTERA por culpa de un dato accesorio. 2 h cubre
// cualquier uso real de un simulador; una pestaña abierta toda la noche no es
// atención, es una pestaña abierta.
const IAREPO_MAX_ENGAGED_SECS = 7200;

// La identidad del visitante —la sal diaria, su caducidad y el hash— vive en
// shared/viewer_key.php desde que api/feedback.php necesitó exactamente lo
// mismo. Duplicar ese cálculo habría sido la peor clase de duplicación
// posible: dos copias que pueden divergir sin que nada falle, y la que se
// quedara atrás produciría claves distintas para la misma persona, rompiendo
// la deduplicación en silencio.

$data = json_body();

$resourceId = (int) ($data['resource_id'] ?? 0);
if (!$resourceId) json_error('resource_id required');

$surface = $data['surface'] ?? 'detail';
if (!in_array($surface, ['detail', 'viewer'], true)) $surface = 'detail';

// Se capa ANTES de tocar la BD, por lo dicho en IAREPO_MAX_ENGAGED_SECS. Un
// cliente manipulado o un reloj que dé un salto no pueden costar la visita.
$engaged = (int) ($data['engaged_secs'] ?? 0);
$engaged = max(0, min($engaged, IAREPO_MAX_ENGAGED_SECS));

$interacted = !empty($data['interacted']) ? 1 : 0;

// El recurso tiene que existir y estar activo. Sin esto, la clave ajena de
// resource_views rechazaría el INSERT con un 1452 críptico, y además
// cualquiera podría sondear qué IDs existen por el código de respuesta.
$exists = $db->prepare('SELECT id FROM resources WHERE id = ? AND is_active = 1');
$exists->execute([$resourceId]);
if (!$exists->fetch()) json_error('Resource not found', 404);

// ── La identidad del visitante ───────────────────────────────
//
// Con sesión manda la identidad real: es estable entre dispositivos, así que
// el mismo profesor en el móvil y en el portátil cuenta una vez. Sin sesión,
// el identificador que generó su navegador.
$user     = authenticate();
$day      = date('Y-m-d');
$isAuthed = $user ? 1 : 0;

// Devuelve null cuando un visitante anónimo no trae un identificador con el
// formato esperado. Se valida en vez de aceptar lo que llegue para que nadie
// pueda elegir su propia viewer_key —y, por ejemplo, fijar la de otro—.
$viewerKey = iarepo_viewer_key($db, $user, (string) ($data['vid'] ?? ''), $day);
if ($viewerKey === null) json_error('Invalid visitor id');

try {
    // GREATEST en los dos campos acumulables: el cliente manda el ACUMULADO de
    // su sesión de página, así que un beacon repetido, duplicado o que llegue
    // desordenado nunca infla el tiempo ni desmarca una interacción ya vista.
    $stmt = $db->prepare("
        INSERT INTO resource_views
            (resource_id, viewer_key, view_day, engaged_secs, interacted, is_authed, surface)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            engaged_secs = GREATEST(engaged_secs, VALUES(engaged_secs)),
            interacted   = GREATEST(interacted,   VALUES(interacted))
    ");
    $stmt->execute([$resourceId, $viewerKey, $day, $engaged, $interacted, $isAuthed, $surface]);

    // rowCount() == 1 significa FILA NUEVA; 2 es actualización y 0 es "no
    // cambió nada" (verificado contra MariaDB 11.8). Sólo la fila nueva es una
    // visita única: sin esta comprobación, cada beacon de tiempo activo
    // sumaría otra vez y el contador volvería a medir eventos, que es el bug
    // que se está arreglando.
    if ($stmt->rowCount() === 1) {
        $db->prepare('UPDATE resources SET unique_views = unique_views + 1 WHERE id = ?')
           ->execute([$resourceId]);
    }
} catch (Throwable $e) {
    // El detalle al log, mensaje genérico al cliente (ver AGENTS.md §9).
    api_log('error', 'track failed', ['resource_id' => $resourceId, 'error' => $e->getMessage()]);
    json_error('Could not record view', 500, 'TRACK_FAILED');
}

json_ok();
