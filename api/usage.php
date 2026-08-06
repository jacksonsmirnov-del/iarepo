<?php
// ================================================================
// api/usage.php — Resource Usage Tracking API
//
// POST /api/usage.php   Record a usage event
// GET  /api/usage.php?resource_id=X   Get usage history
//
// Auth: JWT o sesión. El POST exige además rol docente (ver abajo).
//
// ── QUÉ MIDE ESTE ENDPOINT Y POR QUÉ IMPORTA ───────────────────
// 'presented' —"lo usé en clase"— es la señal de calidad de más valor del
// catálogo: la afirma un profesional que se jugó 50 minutos de clase, no un
// visitante que pasaba. Las visitas miden curiosidad y los "me gusta" miden
// impulso; esto mide INTENCIÓN, y es lo que debería ordenar el catálogo.
//
// El endpoint existe desde el primer día y nunca se ha llamado: `use_count`
// está a 0 en todo el catálogo. Al cablearlo por fin a un botón hubo que
// cerrarle tres agujeros que no importaban mientras nadie lo usaba.
//
// ── LOS TRES AGUJEROS (y por qué cada arreglo está donde está) ──
//
//   1. CUALQUIERA PODÍA AFIRMAR "lo usé en clase".
//      Existe el rol 'student' (migration_009) y requireRole() existe en
//      shared/auth.php desde siempre — pero NINGÚN endpoint lo llamaba. Un
//      alumno pulsando el botón contamina justo la señal que queremos
//      limpia. Ocultar el botón en la UI NO basta: el POST se lanza a mano
//      con dos líneas. Por eso la comprobación vive aquí, en el servidor.
//
//   2. 'presented' NO DEDUPLICABA.
//      Sólo 'endorsed' lo hacía, y en la aplicación (SELECT y luego INSERT),
//      que además tiene una carrera: dos peticiones simultáneas pasan las dos
//      el SELECT y las dos insertan. Una métrica que aspira a sustituir a las
//      estrellas no puede nacer inflable — sería el mismo defecto por el que
//      se descartaron. La dedup real la impone ahora el índice UNIQUE
//      `uniq_usage_signal` (migration_011): el motor no tiene carreras.
//
//   3. LOS 500 PUBLICABAN EL MENSAJE INTERNO DE LA EXCEPCIÓN.
//      `json_error('Failed: ' . $e->getMessage())` devolvía al cliente el
//      error crudo de MariaDB: nombres de tabla, de columna y fragmentos de
//      consulta, a cualquiera capaz de provocar un fallo. Era ruidoso hacia
//      fuera y mudo hacia dentro. Ahora es al revés: el detalle va al log con
//      api_log() y el cliente recibe un mensaje genérico.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/cors.php';
require_once __DIR__ . '/../shared/helpers.php';

cors();

$db = getResourcesDB();
$method = request_method();

rateLimit($db, 'usage', $method === 'POST' ? 60 : 120);

if ($method === 'POST') {
    $user = requireAuth();

    // Sólo docentes AFIRMAN uso. Existe el rol 'student' (migration_009) y
    // hasta ahora nadie comprobaba el rol en ningún endpoint: un alumno podía
    // registrar "lo usé en clase" y falsear la única métrica que mide
    // intención docente. requireRole() responde 403 y corta aquí mismo.
    requireRole($user, ['teacher', 'admin', 'superadmin']);

    $data = json_body();

    $resourceId = (int)($data['resource_id'] ?? 0);
    $usageType = $data['usage_type'] ?? '';

    if (!$resourceId) json_error('resource_id required');
    if (!in_array($usageType, ['presented', 'sent', 'endorsed'], true)) {
        json_error('usage_type must be: presented, sent, or endorsed');
    }

    // Verify resource exists
    $exists = $db->prepare("SELECT id FROM resources WHERE id = ? AND is_active = 1");
    $exists->execute([$resourceId]);
    if (!$exists->fetch()) json_error('Resource not found', 404);

    // Prevent duplicate endorsements
    //
    // Sigue siendo un chequeo de aplicación A PROPÓSITO: 'endorsed' se
    // deduplica PARA SIEMPRE, no por día, y un índice diario dejaría volver a
    // endosar mañana. Por eso 'endorsed' viaja con usage_day NULL (ver abajo)
    // y queda fuera de uniq_usage_signal. Mantiene su carrera teórica —dos
    // peticiones simultáneas—, pero endosar dos veces es inocuo comparado con
    // inflar la señal de uso, y arreglarlo pediría otro índice.
    if ($usageType === 'endorsed') {
        $dup = $db->prepare("
            SELECT id FROM resource_usage
            WHERE resource_id = ? AND user_id = ? AND tenant_id = ? AND usage_type = 'endorsed'
        ");
        $dup->execute([$resourceId, $user['user_id'], $user['tenant_id']]);
        if ($dup->fetch()) json_error('Already endorsed this resource');
    }

    // El contrato de usage_day, que es lo que hace que uniq_usage_signal
    // (migration_011) deduplique unas filas y otras no:
    //   'presented'/'sent' → CURDATE() ⇒ una por profesor, recurso y día
    //   'endorsed'         → NULL      ⇒ manda el chequeo permanente de arriba
    //   'forked'           → NULL      ⇒ lo inserta api/resources.php, que ni
    //                                    menciona la columna: forkear dos
    //                                    veces el mismo día sigue siendo legal
    // En InnoDB un UNIQUE admite múltiples NULL, así que las exclusiones no
    // necesitan ninguna excepción escrita: se cumplen solas.
    $usageDay = in_array($usageType, ['presented', 'sent'], true) ? date('Y-m-d') : null;

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO resource_usage (resource_id, user_id, tenant_id, user_display_name, tenant_name, usage_type, classroom_name, usage_day)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $resourceId,
            $user['user_id'],
            $user['tenant_id'],
            $user['name'],
            $user['tenant_name'],
            $usageType,
            sanitize($data['classroom_name'] ?? '', 100) ?: null,
            $usageDay,
        ]);

        // Contador desnormalizado.
        //
        // CUENTA presented + sent + endorsed, NUNCA 'forked' — no porque se
        // filtre aquí, sino porque los forks los inserta api/resources.php y
        // ese camino no pasa por este contador. Se deja dicho para que nadie
        // "arregle" la incoherencia dentro de seis meses sumando los forks:
        // fork_count ya los cuenta, y meterlos aquí haría que copiar un
        // recurso valiera lo mismo que dar clase con él.
        $db->prepare("UPDATE resources SET use_count = use_count + 1 WHERE id = ?")->execute([$resourceId]);

        // Se relee DENTRO de la transacción y se devuelve para que el front
        // pinte el número real en vez de sumar 1 por su cuenta: dos docentes
        // registrando a la vez verían cada uno su propio incremento y el
        // contador de la página quedaría por debajo del de la BD.
        $countStmt = $db->prepare("SELECT use_count FROM resources WHERE id = ?");
        $countStmt->execute([$resourceId]);
        $useCount = (int) $countStmt->fetchColumn();

        $db->commit();
        json_ok(['message' => 'Usage recorded', 'use_count' => $useCount]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();

        // 1062 / SQLSTATE 23000 = choque con uniq_usage_signal: este profesor
        // ya registró hoy este uso. NO es un fallo del servidor — es la dedup
        // haciendo exactamente su trabajo. Devolverlo como 500 llenaría el log
        // de errores falsos y le enseñaría al usuario un "algo salió mal"
        // cuando lo que pasa es que ya estaba hecho.
        //
        // El mensaje va en inglés y SIN t(), como todos los de api/*.php: las
        // respuestas de la API son un contrato para Campus, no texto de
        // pantalla. Lo que el usuario lee lo decide el front a partir del
        // código 'ALREADY_RECORDED', y ahí sí pasa por t().
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            json_error('Usage already recorded today', 409, 'ALREADY_RECORDED');
        }

        // Cualquier otra cosa sí es un fallo real. El detalle va al log del
        // servidor; al cliente sólo un mensaje genérico. Publicar
        // $e->getMessage() entregaba el error crudo de MariaDB —tablas,
        // columnas, fragmentos de consulta— a quien supiera provocarlo.
        api_log('error', 'usage insert failed', [
            'resource_id' => $resourceId,
            'usage_type'  => $usageType,
            'error'       => $e->getMessage(),
        ]);
        json_error('Could not record usage', 500, 'USAGE_FAILED');
    }
}

if ($method === 'GET') {
    $user = requireAuth();
    $resourceId = (int)($_GET['resource_id'] ?? 0);
    if (!$resourceId) json_error('resource_id required');

    $stmt = $db->prepare("
        SELECT usage_type, user_display_name, tenant_name, classroom_name, created_at
        FROM resource_usage 
        WHERE resource_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$resourceId]);
    json_ok(['usage' => $stmt->fetchAll()]);
}

json_error('Method not allowed', 405);
