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
// 2026-09-27: un token de Campus con el mismo user_id que una cuenta de
// iarepo leía y tocaba sus Guardados, «Me gusta», listas, comentarios y
// novedades (los user_id de Campus son de otra numeración). Último test.
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

// ================================================================
// Campus (claseprivada.com) — entra con JWT, no con sesión
// ================================================================

/** Token de Campus firmado con el secreto del sitio de pruebas. */
function it_campus_jwt(int $userId, int $tenantId, string $role = 'teacher'): string
{
    require_once dirname(__DIR__, 2) . '/shared/jwt.php';
    $secret = it_render_state()['jwt_secret'] ?? '';
    it_true($secret !== '', 'el sitio de pruebas expone su JWT_SECRET');
    return jwt_encode(['user_id' => $userId, 'name' => "Docente $userId", 'role' => $role,
                       'tenant_id' => $tenantId, 'tenant_name' => "Centro $tenantId", 'areas' => []], $secret, 600);
}

/**
 * Un docente de Campus ve quién usó el recurso en SU centro, no en otros.
 * La fuga cerrada era entre centros; cerrarla del todo (nombres solo al
 * autor) le quitaba a Campus lo que tiene sentido enseñar dentro de un colegio.
 */
function test_campus_ve_los_usos_de_su_centro_y_no_los_de_otros(): void
{
    if (!($db = it_authz_ready()))
        return;
    try {
        $url = it_render_state()['base'] . '/api/usage.php?resource_id=1000';
        [$code, , $body] = it_render_request('GET', $url, null, [], ['Authorization' => 'Bearer ' . it_campus_jwt(501, 5)]);
        it_eq(200, $code, 'usage GET con JWT de Campus');
        it_true(str_contains($body, 'Docente Ajena'), 'un docente del centro 5 ve a su compañera del centro 5');

        [$code, , $body] = it_render_request('GET', $url, null, [], ['Authorization' => 'Bearer ' . it_campus_jwt(701, 7)]);
        $j = it_authz_json($body);
        it_eq([], $j['usage'] ?? null, 'un docente del centro 7 no ve nombres del centro 5');
        it_eq(1, $j['summary']['presented'] ?? null, 'pero sí el recuento');
    } finally {
        it_authz_cleanup($db);
    }
}

/**
 * El visor dentro de un iframe (así lo incrusta Campus, con ?token=) no
 * navega dentro del iframe: antes «Ver la ficha» y el título metían la web
 * entera de iarepo dentro de Campus, sin el token.
 */
function test_el_visor_incrustado_no_navega_dentro_del_iframe(): void
{
    if (!($db = it_authz_ready()))
        return;
    try {
        $base  = it_render_state()['base'];
        $frame = ['Sec-Fetch-Dest' => 'iframe'];

        // Recurso restringido (borrador del 9001) visto con el JWT de su autor.
        $tok = it_campus_jwt(IT_RENDER_TEACHER, 0);
        [$code, , $body] = it_render_request('GET', "$base/view/" . IT_RENDER_RES . "?token=$tok", null, null, $frame);
        it_eq(200, $code, 'Campus abre el borrador con el JWT de su autor');
        it_true(!str_contains($body, 'id="btnClose"'), 'incrustado: sin «Ver la ficha»');
        it_true(!str_contains($body, 'href="/resource/' . IT_RENDER_RES . '"'), 'incrustado y restringido: el título no enlaza a la ficha (perdería el token)');

        // Recurso público incrustado: el título abre la ficha en pestaña nueva.
        [$code, , $body] = it_render_request('GET', "$base/view/1000", null, null, $frame);
        it_eq(200, $code, 'visor público incrustado');
        it_true((bool) preg_match('#<a class="title" href="/resource/1000"[^>]*target="_blank"#', $body), 'incrustado y público: la ficha, en pestaña nueva');

        // Fuera de un iframe (QR, enlace directo) sigue ofreciendo la ficha.
        [$code, , $body] = it_render_request('GET', "$base/view/1000");
        it_true(str_contains($body, 'id="btnClose"'), 'abierto directamente: «Ver la ficha» sigue ahí');
    } finally {
        it_authz_cleanup($db);
    }
}

/**
 * Un token de Campus con el MISMO user_id que una cuenta de iarepo no es esa
 * cuenta: los user_id de Campus son de otra numeración. Hasta 2026-09-27 todo
 * lo que va por users.id —guardados, «Me gusta», listas, comentarios,
 * novedades— lo leía y lo tocaba quien tuviera un token con ese número.
 * Ahora exige cuenta de iarepo.com (requireSiteAccount, shared/auth.php).
 */
function test_un_token_de_campus_no_es_la_cuenta_de_iarepo_con_su_numero(): void
{
    if (!($db = it_authz_ready()))
        return;
    $base = it_render_state()['base'];
    $st   = IT_RENDER_STUDENT;   // tiene 1000 en Guardados (site_server.php)
    $t    = IT_RENDER_TEACHER;   // dueño de «Lista de pruebas» y de un comentario
    $coll = (int) it_render_state()['coll'];
    $asStudent = ['Authorization' => 'Bearer ' . it_campus_jwt($st, 7, 'student')];
    $asTeacher = ['Authorization' => 'Bearer ' . it_campus_jwt($t, 7)];
    try {
        $db->exec("INSERT IGNORE INTO resource_likes (resource_id, user_id, user_name) VALUES (1000, $st, NULL)");

        // Guardados: ni leerlos ni tocarlos.
        [$code, , $body] = it_render_request('GET', "$base/api/favorites.php", null, [], $asStudent);
        it_eq(403, $code, 'Guardados con token de Campus: 403');
        it_eq('SITE_ACCOUNT_REQUIRED', it_authz_json($body)['code'] ?? null, 'con su código');
        it_true(!str_contains($body, 'Ondas sonoras'), 'y sin los Guardados de la cuenta de iarepo con su número');
        [$code] = it_render_request('POST', "$base/api/favorites.php?id=1000", null, [], $asStudent);
        it_eq(403, $code, 'tampoco puede quitarlos');
        it_eq(1, (int) $db->query("SELECT COUNT(*) FROM resource_favorites WHERE user_id = $st AND resource_id = 1000")->fetchColumn(),
            'y el guardado de la alumna sigue ahí');

        // «Me gusta»: el recuento es público; «si le gustó» es de cada cuenta.
        [$code, , $body] = it_render_request('GET', "$base/api/likes.php?id=1000", null, [], $asStudent);
        it_eq(200, $code, 'el recuento de «Me gusta» responde');
        it_eq(false, it_authz_json($body)['user_liked'] ?? null, 'pero no dice si le gustó a la alumna de iarepo');
        [$code] = it_render_request('POST', "$base/api/likes.php?id=1000", null, [], $asStudent);
        it_eq(403, $code, 'ni quita su «Me gusta»');
        it_eq(1, (int) $db->query("SELECT COUNT(*) FROM resource_likes WHERE user_id = $st AND resource_id = 1000")->fetchColumn(),
            'que sigue ahí');

        // Listas: una privada no se abre, y ninguna se borra.
        $db->exec("UPDATE collections SET is_public = 0 WHERE id = $coll");
        [$code] = it_render_request('GET', "$base/api/collections.php?id=$coll", null, [], $asTeacher);
        it_eq(403, $code, 'una lista privada no se abre con el mismo número');
        [$code] = it_render_request('DELETE', "$base/api/collections.php?id=$coll", null, [], $asTeacher);
        it_eq(403, $code, 'ni se borra');
        it_eq(1, (int) $db->query("SELECT COUNT(*) FROM collections WHERE id = $coll")->fetchColumn(), 'la lista sigue ahí');

        // Comentarios: no borra los de la cuenta de iarepo.
        $cid = (int) $db->query("SELECT id FROM resource_comments WHERE user_id = $t AND body = 'COMENTARIO-EN-BORRADOR'")->fetchColumn();
        [$code] = it_render_request('DELETE', "$base/api/comments.php?id=$cid", null, [], $asTeacher);
        it_eq(403, $code, 'no borra un comentario de la cuenta con su número');
        it_eq(1, (int) $db->query("SELECT is_active FROM resource_comments WHERE id = $cid")->fetchColumn(), 'que sigue publicado');

        // La ficha con ?token=: no pinta el guardado ni el «Me gusta» de otra persona.
        [$code, , $page] = it_render_request('GET', "$base/resource/1000?token=" . substr($asStudent['Authorization'], 7));
        it_eq(200, $code, 'la ficha con ?token= responde');
        it_true(!preg_match('/id="favBtn"[^>]*aria-pressed="true"/', $page) && !preg_match('/id="likeBtn"[^>]*aria-pressed="true"/', $page),
            'sin «Guardado» ni «Me gusta» marcados de la cuenta de iarepo');

        // Y la cuenta de iarepo, con su sesión, sigue teniendo todo lo suyo.
        [$code, , $body] = it_render_request('GET', "$base/api/favorites.php", it_render_cookie('student'), []);
        it_eq(200, $code, 'con sesión, sus Guardados responden');
        it_true(in_array(1000, it_authz_json($body)['favorite_ids'] ?? [], true), 'con lo que guardó');
    } finally {
        $db->exec("DELETE FROM resource_likes WHERE user_id = $st AND resource_id = 1000");
        $db->exec("UPDATE collections SET is_public = 1 WHERE id = $coll");
        it_authz_cleanup($db);
    }
}
