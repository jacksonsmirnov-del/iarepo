<?php
// ================================================================
// api/feedback.php — «¿Te quedó claro?»
//
// POST /api/feedback.php   {resource_id, vid, answer}
//   answer ∈ claro | regular | perdido
//
// Auth: OPCIONAL. Quien USA los recursos son alumnos, y muchos no tienen
// cuenta. Exigir sesión dejaría fuera justo a quien se quiere escuchar.
//
// ── POR QUÉ ESTA PREGUNTA Y NO ESTRELLAS ──────────────────────
// «¿Te gustó?» invita a quedar bien: lo contesta un menor delante de su
// profesor. «¿Te quedó claro?» es una pregunta sobre uno mismo, no un juicio
// sobre el trabajo de otro — y es el dato que el profesor quería de verdad:
// no «¿les gustó la interfaz?», sino «¿sirvió?».
//
// Además, con 546 recursos y tráfico bajo, una media de estrellas de dos votos
// es ruido con aspecto de autoridad. Aquí no hay media: se cuentan respuestas.
//
// ── LA PUERTA ESTÁ EN EL SERVIDOR, NO EN EL CLIENTE ───────────
// Sólo puede responder quien DEMOSTRÓ haber usado el recurso: tiene que
// existir su fila en `resource_views` (hoy, este recurso) con tiempo activo
// suficiente Y con interacción registrada.
//
// El cliente ya oculta el prompt hasta ese momento, pero eso es cosmética: el
// POST se lanza a mano con dos líneas. Sin la comprobación aquí, cualquiera
// podría inundar un recurso de «me perdí» sin haberlo abierto siquiera, y el
// único dato pedagógico del sistema quedaría envenenado — en silencio, porque
// nada fallaría.
//
// Que la puerta sea la MISMA tabla de visitas tiene un efecto útil: preguntar
// sólo a quien de verdad usó el recurso sube la tasa de respuesta y evita la
// muestra basura de preguntar a todo el que pasa.
//
// ── PRIVACIDAD ────────────────────────────────────────────────
// La respuesta se guarda contra el mismo `viewer_key` anónimo que las visitas
// (shared/viewer_key.php): hash con una sal diaria que se BORRA a los 2 días.
// Pasado ese plazo, el «me perdí» de un alumno concreto es irrecuperable
// incluso con acceso total a la base de datos.
//
// ⚠️ NUNCA guardes aquí la identidad en claro. Convertiría un agregado anónimo
// en un registro nominal de quién no entendió qué.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/viewer_key.php';

cors();

$db = getResourcesDB();

if (request_method() !== 'POST') json_error('Method not allowed', 405);

rateLimit($db, 'feedback', 60);

// El umbral de la puerta. Tiene que coincidir con el del cliente
// (assets/js/track.js), que decide cuándo enseñar el prompt; si divergen, el
// usuario vería una pregunta que el servidor va a rechazar.
const IAREPO_FEEDBACK_MIN_SECS = 180;

$data = json_body();

$resourceId = (int) ($data['resource_id'] ?? 0);
if (!$resourceId) json_error('resource_id required');

$answer = (string) ($data['answer'] ?? '');
if (!in_array($answer, ['claro', 'regular', 'perdido'], true)) {
    json_error('answer must be: claro, regular or perdido');
}

$user = authenticate();
$day  = date('Y-m-d');

$viewerKey = iarepo_viewer_key($db, $user, (string) ($data['vid'] ?? ''), $day);
if ($viewerKey === null) json_error('Invalid visitor id');

// ── La puerta ────────────────────────────────────────────────
//
// Se comprueba contra la MISMA clave y el MISMO día con que se registró la
// visita. Como la sal rota a diario, esto no se puede falsear reutilizando una
// clave vieja: la de ayer ya no se puede recalcular.
$gate = $db->prepare("
    SELECT engaged_secs, interacted FROM resource_views
    WHERE resource_id = ? AND viewer_key = ? AND view_day = ?
");
$gate->execute([$resourceId, $viewerKey, $day]);
$view = $gate->fetch();

if (!$view || (int) $view['interacted'] !== 1
    || (int) $view['engaged_secs'] < IAREPO_FEEDBACK_MIN_SECS) {
    // 403 y no 400: la petición está bien formada, es el permiso lo que falta.
    json_error('Not enough engagement to answer', 403, 'NOT_ENGAGED');
}

try {
    // ON DUPLICATE KEY UPDATE, no INSERT: cambiar de opinión es legítimo —
    // alguien marca «me perdí», sigue trasteando y lo entiende. Rechazar la
    // corrección congelaría la peor versión de la respuesta.
    $db->prepare("
        INSERT INTO resource_comprehension (resource_id, viewer_key, view_day, answer)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE answer = VALUES(answer)
    ")->execute([$resourceId, $viewerKey, $day, $answer]);
} catch (Throwable $e) {
    // Detalle al log, mensaje genérico al cliente (ver AGENTS.md §9).
    api_log('error', 'feedback failed', ['resource_id' => $resourceId, 'error' => $e->getMessage()]);
    json_error('Could not record the answer', 500, 'FEEDBACK_FAILED');
}

json_ok(['answer' => $answer]);
