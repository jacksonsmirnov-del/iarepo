<?php
// ================================================================
// tests/integration/authorization_test.php — Quién ve qué en api/*.php
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// Hasta 2026-09-26 el gate no tenía NI UNA regla de autorización, y la web
// tiene usuarios menores. Tres GET devolvían datos de otras personas a
// cualquiera con sesión (y entrar con Google está abierto a todo el mundo):
//
//   · api/versions.php?id=N     el CÓDIGO de cualquier versión, borradores
//                               ajenos incluidos, enumerando N;
//   · api/usage.php GET          nombre del profesor, colegio y aula de cada
//                               uso, visible también para alumnos;
//   · api/assignments.php GET    aulas y docentes de todos los centros, con
//                               ?tenant_id= a elección de quien pregunta.
//
// Y api/comments.php enseñaba los comentarios de cualquier recurso y dejaba
// publicar a los alumnos con su nombre y su foto.
//
// Cada test entra como "el otro" —otro profesor, un alumno, un anónimo— e
// intenta leer lo que no es suyo. Se hace por HTTP contra el sitio levantado
// (site_server.php), no llamando a funciones: lo que importa es lo que sale
// por la red.
// ================================================================

require_once __DIR__ . '/site_server.php';

const IT_AUTHZ_OTHER   = 9003;   // otro profesor, mismo tenant 0 que el autor
const IT_AUTHZ_VERSION = 99801;

function it_authz_seed(PDO $db): void
{
    $o = IT_AUTHZ_OTHER;
    $r = IT_RENDER_RES;   // borrador del profesor 9001 (site_server.php)
    $db->exec("DELETE FROM users WHERE id = $o");
    $db->exec("INSERT INTO users (id, google_id, email, name, role)
               VALUES ($o, 'it-authz-other', 'it-other@example.test', 'Otro Profe', 'teacher')");

    $db->exec('DELETE FROM resource_versions WHERE id = ' . IT_AUTHZ_VERSION);
    $db->exec('INSERT INTO resource_versions (id, resource_id, version_number, code_content, editor_user_id, editor_display_name)
               VALUES (' . IT_AUTHZ_VERSION . ", $r, 1, '<p>SECRETO-DEL-BORRADOR</p>', 9001, 'Profe de Pruebas')");

    $db->exec('DELETE FROM resource_usage WHERE resource_id = 1000');
    $db->exec("INSERT INTO resource_usage (resource_id, user_id, tenant_id, user_display_name, tenant_name, usage_type, classroom_name, usage_day)
               VALUES (1000, 77, 5, 'Docente Ajena', 'Colegio Ajeno', 'presented', '3ºB', CURDATE())");

    $db->exec('DELETE FROM resource_assignments WHERE resource_id = 1000');
    $db->exec("INSERT INTO resource_assignments (resource_id, tenant_id, classroom_id, classroom_name, assigned_by_user_id, assigned_by_name)
               VALUES (1000, 5, 31, 'Aula de otro colegio', 77, 'Docente Ajena'),
                      (1000, 0, 32, 'Aula propia', 9001, 'Profe de Pruebas')");

    $db->exec("DELETE FROM resource_comments WHERE resource_id IN (1000, $r)");
    $db->exec("INSERT INTO resource_comments (resource_id, user_id, user_name, body)
               VALUES ($r, 9001, 'Profe de Pruebas', 'COMENTARIO-EN-BORRADOR')");
}

function it_authz_cleanup(PDO $db): void
{
    $r = IT_RENDER_RES;
    $db->exec('DELETE FROM resource_versions WHERE id = ' . IT_AUTHZ_VERSION);
    $db->exec('DELETE FROM resource_usage WHERE resource_id = 1000');
    $db->exec('DELETE FROM resource_assignments WHERE resource_id = 1000');
    $db->exec("DELETE FROM resource_comments WHERE resource_id IN (1000, $r)");
    $db->exec('DELETE FROM users WHERE id = ' . IT_AUTHZ_OTHER);
}

/** Levanta el sitio y siembra; null (ya con SKIP impreso) si falta infraestructura. */
function it_authz_ready(): ?PDO
{
    if (it_render_server() === null) {
        echo '    SKIP authz: ' . (it_render_state()['skip'] ?? '') . "\n";
        return null;
    }
    $db = iarepo_it_db();
    it_authz_seed($db);
    return $db;
}

function it_authz_json(string $body): array
{
    $j = json_decode($body, true);
    return is_array($j) ? $j : [];
}

function test_versions_no_entrega_el_codigo_de_un_borrador_ajeno(): void
{
    if (!($db = it_authz_ready()))
        return;
    try {
        $base = it_render_state()['base'];
        $url  = "$base/api/versions.php?id=" . IT_AUTHZ_VERSION;

        [$code, , $body] = it_render_request('GET', $url, it_render_cookie('teacher', IT_AUTHZ_OTHER), []);
        it_eq(404, $code, 'otro profesor no puede abrir una versión del borrador ajeno');
        it_true(!str_contains($body, 'SECRETO-DEL-BORRADOR'), 'el código del borrador no sale en la respuesta');

        [$code, , $body] = it_render_request('GET', $url, it_render_cookie('student'), []);
        it_eq(404, $code, 'un alumno tampoco');

        [$code, , $body] = it_render_request('GET', "$base/api/versions.php?resource_id=" . IT_RENDER_RES,
            it_render_cookie('teacher', IT_AUTHZ_OTHER), []);
        it_eq(404, $code, 'ni siquiera la lista de versiones de un borrador ajeno');

        [$code, , $body] = it_render_request('GET', $url, it_render_cookie('teacher'), []);
        it_eq(200, $code, 'su autor sí la ve');
        it_true(str_contains($body, 'SECRETO-DEL-BORRADOR'), 'y recibe el código');
    } finally {
        it_authz_cleanup($db);
    }
}

function test_usage_solo_da_nombres_al_autor_del_recurso(): void
{
    if (!($db = it_authz_ready()))
        return;
    try {
        $url = it_render_state()['base'] . '/api/usage.php?resource_id=1000';
        foreach (['student' => null, 'teacher' => IT_AUTHZ_OTHER] as $role => $id) {
            [$code, , $body] = it_render_request('GET', $url, it_render_cookie($role, $id), []);
            $j = it_authz_json($body);
            it_eq(200, $code, "usage GET como $role");
            it_eq([], $j['usage'] ?? null, "$role no recibe filas con nombres");
            it_eq(1, $j['summary']['presented'] ?? null, "$role recibe el recuento");
            it_true(!str_contains($body, 'Colegio Ajeno') && !str_contains($body, 'Docente Ajena'),
                "ni el colegio ni el nombre de la docente salen para $role");
        }
    } finally {
        it_authz_cleanup($db);
    }
}

function test_assignments_solo_lista_las_aulas_del_propio_centro(): void
{
    if (!($db = it_authz_ready()))
        return;
    try {
        $base = it_render_state()['base'];
        foreach (["$base/api/assignments.php?resource_id=1000",
                  "$base/api/assignments.php?resource_id=1000&tenant_id=5"] as $url) {
            [$code, , $body] = it_render_request('GET', $url, it_render_cookie('teacher'), []);
            it_eq(200, $code, "assignments GET $url");
            it_true(!str_contains($body, 'Aula de otro colegio'), "no salen aulas de otro centro ($url)");
            it_true(str_contains($body, 'Aula propia'), "sí salen las del propio ($url)");
        }
    } finally {
        it_authz_cleanup($db);
    }
}

function test_comments_respetan_la_visibilidad_y_los_alumnos_no_publican(): void
{
    if (!($db = it_authz_ready()))
        return;
    try {
        $base = it_render_state()['base'];
        $url  = "$base/api/comments.php?resource_id=" . IT_RENDER_RES;

        [$code, , $body] = it_render_request('GET', $url, null, []);
        it_eq(404, $code, 'los comentarios de un borrador no se leen sin ser su autor');
        it_true(!str_contains($body, 'COMENTARIO-EN-BORRADOR'), 'el texto no sale');

        [$code, , $body] = it_render_request('GET', $url, it_render_cookie('teacher'), []);
        it_eq(200, $code, 'su autor sí los lee');

        [$code, , $body] = it_render_request('POST', "$base/api/comments.php", it_render_cookie('student'),
            ['resource_id' => 1000, 'body' => 'Hola, soy una alumna']);
        it_eq(403, $code, 'un alumno no publica comentarios');
        $n = (int) $db->query("SELECT COUNT(*) FROM resource_comments WHERE resource_id = 1000")->fetchColumn();
        it_eq(0, $n, 'y no queda nada guardado');

        [$code, , $body] = it_render_request('POST', "$base/api/comments.php", it_render_cookie('teacher', IT_AUTHZ_OTHER),
            ['resource_id' => 1000, 'body' => 'Lo usé con 2º de ESO']);
        it_eq(200, $code, 'un profesor sí comenta en un recurso público');
    } finally {
        it_authz_cleanup($db);
    }
}

// ── api/collections.php ?id= (integración del rediseño 2026-09) ──
// La página de una lista (collection/index.php) ya filtraba cada recurso con
// canView(), pero la API no: un borrador metido en una lista pública salía
// por GET /api/collections.php?id=N con título y descripción a cualquiera,
// anónimos incluidos. Además la API la devolvía al revés (added_at DESC)
// mientras la página la enseña como secuencia: Campus y la web no coincidían
// en cuál es el «paso 1».

function test_una_lista_publica_no_cuela_borradores_por_la_api(): void
{
    if (!($db = it_authz_ready()))
        return;
    $t   = IT_RENDER_TEACHER;
    $cid = 0;
    try {
        $db->exec("INSERT INTO collections (user_id, title, description, is_public, item_count)
                   VALUES ($t, 'IT-AUTHZ lista pública', '', 1, 3)");
        $cid = (int) $db->lastInsertId();
        // 1031 entra PRIMERO aunque tenga un id mayor que 1000: el orden es
        // el de llegada, no el del id. El borrador del profesor, en medio.
        $db->exec("INSERT INTO collection_items (collection_id, resource_id, added_at) VALUES
                   ($cid, 1031, '2026-01-01 10:00:00'),
                   ($cid, " . IT_RENDER_RES . ", '2026-01-02 10:00:00'),
                   ($cid, 1000, '2026-01-03 10:00:00')");

        $url = it_render_state()['base'] . "/api/collections.php?id=$cid";
        foreach (['anónimo' => [null, null], 'otro profesor' => ['teacher', IT_AUTHZ_OTHER], 'alumno' => ['student', null]] as $who => [$role, $id]) {
            [$code, , $body] = it_render_request('GET', $url, $role ? it_render_cookie($role, $id) : null, []);
            it_eq(200, $code, "la lista pública se lee ($who)");
            $ids = array_map('intval', array_column(it_authz_json($body)['collection']['items'] ?? [], 'id'));
            it_eq([1031, 1000], $ids, "$who: solo lo que puede ver, en el orden en que se añadió");
            it_true(!str_contains($body, 'Recurso de pruebas del panel'), "$who: el título del borrador no sale");
            it_true(!str_contains($body, 'author_user_id'), "$who: la API no añade campos internos al contrato");
        }

        [$code, , $body] = it_render_request('GET', $url, it_render_cookie('teacher'), []);
        $ids = array_map('intval', array_column(it_authz_json($body)['collection']['items'] ?? [], 'id'));
        it_eq([1031, IT_RENDER_RES, 1000], $ids, 'el autor del borrador sí lo ve, en su sitio de la secuencia');
    } finally {
        if ($cid)
            $db->exec("DELETE FROM collections WHERE id = $cid");   // collection_items cae en cascada
        it_authz_cleanup($db);
    }
}
