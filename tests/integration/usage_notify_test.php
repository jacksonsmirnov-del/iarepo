<?php
// ================================================================
// tests/integration/usage_notify_test.php — «Lo usé en clase» llega al autor
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// «Lo usé en clase» es la señal que más dice de un recurso, y hasta 2026-09
// no se le contaba a su autor por ningún lado: ni correo, ni campana, ni «Mi
// panel». Desde entonces:
//
//   · correo al autor (shared/notify.php, tipo 'presented'): uno por recurso
//     y día aunque lo usen treinta docentes; nunca por el uso propio; nunca
//     si el autor se dio de baja;
//   · campana (api/notifications.php) y «Actividad reciente» de Mi panel, las
//     dos desde shared/activity.php, con el tipo 'used'.
//
// Y lo que tuvo que cerrarse para poder avisar sin abrir otra puerta:
//
//   · api/usage.php solo registra el uso de lo que quien lo afirma puede ver
//     (canView). Si no, cualquier docente podía mandarle correos al autor de
//     un borrador que ni siquiera puede abrir.
//   · Un docente de Campus trae el user_id de SU numeración: con el mismo
//     número que el autor NO es el autor (sí recibe correo el autor), y no
//     recibe la campana de la cuenta de iarepo con ese número.
//
// ── CÓMO ──────────────────────────────────────────────────────
// Por HTTP contra el sitio levantado (site_server.php), que corre con
// sendmail_path apuntando a un fichero: it_render_mails() lee lo que se
// habría enviado. El recurso es IT_RENDER_RES (del profesor 9001), que se
// hace público mientras dura cada test y vuelve a ser borrador al acabar.
// ================================================================

require_once __DIR__ . '/site_server.php';

const IT_NOTIFY_OTHER = 9005;   // otro profesor de iarepo.com (tenant 0)
const IT_NOTIFY_THIRD = 9006;   // y un tercero

/** Sitio levantado, recurso público, sin usos ni correos previos. */
function it_notify_ready(string $visibility = 'community'): ?PDO
{
    if (it_render_server() === null) {
        echo '    SKIP notify: ' . (it_render_state()['skip'] ?? '') . "\n";
        return null;
    }
    $db = iarepo_it_db();
    it_notify_reset($db);
    $db->prepare('UPDATE resources SET visibility = ? WHERE id = ?')->execute([$visibility, IT_RENDER_RES]);
    return $db;
}

function it_notify_reset(PDO $db): void
{
    $r = IT_RENDER_RES;
    $db->exec("DELETE FROM resource_usage WHERE resource_id = $r");
    $db->exec("DELETE FROM notification_log WHERE resource_id = $r");
    $db->exec("UPDATE resources SET use_count = 0, visibility = 'draft' WHERE id = $r");
    $db->exec('UPDATE users SET email_notifications = 1 WHERE id = ' . IT_RENDER_TEACHER);
    file_put_contents(it_render_outbox(), '');
}

/** POST «Lo usé en clase» como $cookie (sesión) o con $bearer (JWT de Campus). */
function it_notify_use(?string $cookie, ?string $bearer = null): array
{
    [$code, , $body] = it_render_request('POST', it_render_state()['base'] . '/api/usage.php', $cookie,
        ['resource_id' => IT_RENDER_RES, 'usage_type' => 'presented'],
        $bearer ? ['Authorization' => "Bearer $bearer"] : []);
    $j = json_decode($body, true);
    return [$code, is_array($j) ? $j : ['_raw' => substr($body, 0, 300)]];
}

/** Token de Campus firmado con el secreto del sitio de pruebas. */
function it_notify_jwt(int $userId, int $tenantId): string
{
    require_once dirname(__DIR__, 2) . '/shared/jwt.php';
    return jwt_encode(['user_id' => $userId, 'name' => "Docente $userId", 'role' => 'teacher',
                       'tenant_id' => $tenantId, 'tenant_name' => "Centro $tenantId", 'areas' => []],
                      (string) it_render_state()['jwt_secret'], 600);
}

function test_usarlo_en_clase_avisa_al_autor_una_vez_al_dia(): void
{
    if (!($db = it_notify_ready()))
        return;
    try {
        [$code] = it_notify_use(it_render_cookie('teacher'));
        it_eq(200, $code, 'el autor puede marcar su propio recurso');
        it_eq([], it_render_mails(), 'pero su propio uso no le manda un correo');

        [$code, $j] = it_notify_use(it_render_cookie('teacher', IT_NOTIFY_OTHER));
        it_eq(200, $code, 'otro docente lo usa en clase: ' . json_encode($j));
        $mails = it_render_mails();
        it_eq(1, count($mails), 'y al autor le llega UN correo');
        it_eq('it-teacher@example.test', $mails[0]['to'] ?? null, 'a su dirección');
        it_true(str_contains($mails[0]['subject'] ?? '', 'Usaron tu recurso en clase')
             && str_contains($mails[0]['subject'] ?? '', 'Recurso de pruebas del panel'), 'con el asunto y el título');
        it_true(str_contains($mails[0]['body'] ?? '', '<strong>Usuario de pruebas</strong> usó en clase tu recurso'), 'dice quién');
        it_true(str_contains($mails[0]['body'] ?? '', 'unsubscribe.php?token='), 'y cómo darse de baja');

        [$code, $j] = it_notify_use(it_render_cookie('teacher', IT_NOTIFY_OTHER));
        it_eq(409, $code, 'el mismo docente, el mismo día: ya estaba registrado');
        [$code] = it_notify_use(it_render_cookie('teacher', IT_NOTIFY_THIRD));
        it_eq(200, $code, 'un tercer docente también lo usa');
        it_eq(1, count(it_render_mails()), 'y sigue siendo un correo al día por recurso, no uno por docente');

        // La campana y Mi panel sí los cuentan todos (menos el propio).
        $author = it_render_cookie('teacher');
        [$code, , $raw] = it_render_request('GET', it_render_state()['base'] . '/api/notifications.php', $author, []);
        $feed = json_decode($raw, true)['notifications'] ?? [];
        $used = array_values(array_filter($feed, static fn($n) => ($n['type'] ?? '') === 'used'
                                                                && (int) $n['resource_id'] === IT_RENDER_RES));
        it_eq(200, $code, 'la campana responde');
        it_eq(2, count($used), 'la campana trae los dos usos de otros, no el propio');
        it_eq(['Usuario de pruebas'], array_values(array_unique(array_column($used, 'actor'))), 'con el nombre del docente');

        [$code, , $page] = it_render_get(it_render_state()['base'] . '/dashboard/', $author);
        it_eq(200, $code, 'Mi panel responde');
        it_true(str_contains($page, 'usó en clase <strong>Recurso de pruebas del panel</strong>'), 'y su «Actividad reciente» lo cuenta');
    } finally {
        it_notify_reset($db);
    }
}

function test_quien_se_dio_de_baja_no_recibe_el_correo(): void
{
    if (!($db = it_notify_ready()))
        return;
    try {
        $db->exec('UPDATE users SET email_notifications = 0 WHERE id = ' . IT_RENDER_TEACHER);
        [$code] = it_notify_use(it_render_cookie('teacher', IT_NOTIFY_OTHER));
        it_eq(200, $code, 'el uso se registra igual');
        it_eq([], it_render_mails(), 'pero no sale ningún correo');
    } finally {
        it_notify_reset($db);
    }
}

function test_no_se_registra_el_uso_de_un_borrador_ajeno(): void
{
    if (!($db = it_notify_ready('draft')))
        return;
    try {
        [$code] = it_notify_use(it_render_cookie('teacher', IT_NOTIFY_OTHER));
        it_eq(404, $code, 'un borrador ajeno responde como si no existiera');
        it_eq(0, (int) $db->query('SELECT COUNT(*) FROM resource_usage WHERE resource_id = ' . IT_RENDER_RES)->fetchColumn(),
            'no se registra el uso');
        it_eq([], it_render_mails(), 'ni se le escribe al autor');

        [$code] = it_notify_use(it_render_cookie('teacher'));
        it_eq(200, $code, 'su autor sí puede marcar su borrador');
    } finally {
        it_notify_reset($db);
    }
}

function test_un_docente_de_campus_con_el_mismo_numero_no_es_el_autor(): void
{
    if (!($db = it_notify_ready()))
        return;
    try {
        // user_id 9001 como el autor, pero del centro 7 de Campus.
        $jwt = it_notify_jwt(IT_RENDER_TEACHER, 7);
        [$code, $j] = it_notify_use(null, $jwt);
        it_eq(200, $code, 'un docente de Campus lo usa en clase: ' . json_encode($j));
        $mails = it_render_mails();
        it_eq(1, count($mails), 'al autor le llega el correo: no es su propio uso');
        it_true(str_contains($mails[0]['body'] ?? '', '<strong>Docente 9001 (Centro 7)</strong>'), 'con el docente y su centro');

        [$code, , $raw] = it_render_request('GET', it_render_state()['base'] . '/api/notifications.php', null, [],
            ['Authorization' => "Bearer $jwt"]);
        it_eq(200, $code, 'la campana responde a Campus');
        it_eq([], json_decode($raw, true)['notifications'] ?? null,
            'pero no le da las novedades de la cuenta de iarepo con su mismo número');

        [$code, , $raw] = it_render_request('GET', it_render_state()['base'] . '/api/notifications.php', it_render_cookie('teacher'), []);
        $feed = json_decode($raw, true)['notifications'] ?? [];
        it_true((bool) array_filter($feed, static fn($n) => ($n['type'] ?? '') === 'used' && ($n['actor'] ?? '') === 'Docente 9001'),
            'y al autor su campana sí le cuenta ese uso');
    } finally {
        it_notify_reset($db);
    }
}
