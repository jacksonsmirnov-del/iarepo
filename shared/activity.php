<?php
// ================================================================
// shared/activity.php — Lo que han hecho OTROS con tus recursos
//
// UNA sola definición para los dos sitios que lo enseñan: la campana de
// novedades (api/notifications.php) y «Actividad reciente» de Mi panel
// (dashboard/index.php). Eran dos copias del mismo SQL, y añadir una fuente
// en una sola era cuestión de tiempo.
//
// Fuentes (type → qué pasó):
//   like     alguien marcó «Me gusta»
//   fork     otro docente hizo su versión
//   comment  alguien comentó
//   used     un docente lo marcó como «Lo usé en clase» (resource_usage,
//            usage_type 'presented'): la señal que más dice de un recurso,
//            y hasta 2026-09 no se le contaba al autor en ningún sitio.
//
// Reglas que viven aquí y en ningún otro sitio:
//   · Lo que hace uno mismo no es una novedad: se excluye en cada fuente.
//     En 'used' la identidad es (user_id, tenant_id): un docente de Campus
//     con el mismo user_id que el autor NO es el autor (tenant ≠ 0).
//   · Un «Me gusta» de quien está aprendiendo (rol student, puede ser menor)
//     sale sin nombre: actor NULL → el cliente pinta «Alguien que está
//     aprendiendo». El JOIN cubre las filas de antes de que api/likes.php
//     dejara de guardar el nombre.
//   · 'used' solo lo afirman docentes (api/usage.php exige el rol).
//
// Funciones puras salvo la consulta: sin require, sin salida. La cargan una
// página HTML y un endpoint, así que NUNCA helpers.php (CLAUDE.md §2.1).
// Antirregresión: tests/unit/review_fixes_test.php (el nombre de quien
// aprende) y tests/integration/usage_notify_test.php (las cuatro fuentes).
// ================================================================

/** Icono lucide de cada tipo (el verbo, traducido, lo pone cada página). */
const IAREPO_ACTIVITY_ICONS = [
    'like'    => 'heart',
    'fork'    => 'git-branch',
    'comment' => 'message-circle',
    'used'    => 'graduation-cap',
];

/**
 * Actividad de otros sobre los recursos de un autor, de la más reciente a la
 * más antigua.
 *
 * @param int[] $resourceIds  Recursos del autor.
 * @return list<array{type: string, actor: ?string, resource_title: string, resource_id: int|string, created_at: string}>
 */
function iarepo_author_activity(PDO $db, int $uid, array $resourceIds, int $limit): array
{
    $ids = array_values(array_filter(array_map('intval', $resourceIds), static fn(int $i): bool => $i > 0));
    if (!$ids || $limit < 1)
        return [];
    $in  = implode(',', $ids);   // enteros: array_map('intval') justo arriba
    $lim = (int) $limit;

    $sources = [
        "SELECT 'like' AS type, IF(u.role = 'student', NULL, rl.user_name) AS actor, r.title AS resource_title, r.id AS resource_id, rl.created_at
         FROM resource_likes rl JOIN resources r ON r.id = rl.resource_id
         LEFT JOIN users u ON u.id = rl.user_id
         WHERE rl.resource_id IN ($in) AND rl.user_id <> ?
         ORDER BY rl.created_at DESC LIMIT $lim",
        "SELECT 'fork' AS type, r2.author_display_name AS actor, r.title AS resource_title, r.id AS resource_id, r2.created_at
         FROM resources r2 JOIN resources r ON r.id = r2.fork_of
         WHERE r2.fork_of IN ($in) AND r2.is_active = 1 AND r2.author_user_id <> ?
         ORDER BY r2.created_at DESC LIMIT $lim",
        "SELECT 'comment' AS type, rc.user_name AS actor, r.title AS resource_title, r.id AS resource_id, rc.created_at
         FROM resource_comments rc JOIN resources r ON r.id = rc.resource_id
         WHERE rc.resource_id IN ($in) AND rc.is_active = 1 AND rc.user_id <> ?
         ORDER BY rc.created_at DESC LIMIT $lim",
        "SELECT 'used' AS type, ru.user_display_name AS actor, r.title AS resource_title, r.id AS resource_id, ru.created_at
         FROM resource_usage ru JOIN resources r ON r.id = ru.resource_id
         WHERE ru.resource_id IN ($in) AND ru.usage_type = 'presented'
           AND NOT (ru.user_id = ? AND ru.tenant_id = 0)
         ORDER BY ru.created_at DESC LIMIT $lim",
    ];

    $all = [];
    foreach ($sources as $sql) {
        $s = $db->prepare($sql);
        $s->execute([$uid]);
        array_push($all, ...$s->fetchAll(PDO::FETCH_ASSOC));
    }
    usort($all, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
    return array_slice($all, 0, $lim);
}
