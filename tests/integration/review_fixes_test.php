<?php
// ================================================================
// tests/integration/review_fixes_test.php — Lo que encontró la revisión 2026-09
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// Tres revisores independientes (corrección, seguridad/privacidad y
// experiencia) recorrieron el rediseño de 2026-09 con el sitio levantado y
// encontraron fallos que ningún test veía. Cada test de aquí reproduce uno
// POR HTTP, contra el sitio de pruebas (site_server.php), tal como lo vio el
// revisor, y falla si vuelve:
//
//   · CSRF: una web ajena cambiaba el rol (perfil público de un menor) o
//     borraba recursos con la sesión de quien la visitaba.
//   · Un perfil sin nada publicado era público e indexable (nombre y foto).
//   · Cambiar de idioma tiraba la query: la lista volvía a la portada, el
//     editor abría un formulario vacío.
//   · El nombre de un alumno llegaba al autor con cada «Me gusta».
//   · Una versión pública enseñaba el título de un original ya privado.
//   · Editar un enlace pisaba su fuente; cambiar solo el tipo a 'url' se
//     saltaba la validación y la lista negra; «https://x\@phet…» firmaba PhET.
//   · «Siguiente paso» en inglés a quien estaba en un recurso en español.
//   · «Solo para tu centro» en algo que ve cualquiera con cuenta.
//   · Sin «Salir» en escritorio; «Cerrar» del visor muerto; muro de ceros
//     en el panel de un docente nuevo; ?token[]= → 500; return_url con TAB.
// Lo que se ve en el fuente está en tests/unit/review_fixes_test.php.
//
// Datos: usuarios 9011-9012 y recursos 99901-99909, propios de este
// fichero; cada test borra lo suyo en su finally.
// ================================================================

require_once __DIR__ . '/site_server.php';

const IT_FIX_NEWBIE = 9011;   // docente sin nada publicado
const IT_FIX_OTHER  = 9012;   // otra docente (tenant 0, como todas)
const IT_FIX_RES    = [99901, 99902, 99903, 99904, 99905, 99906, 99907, 99908, 99909];

/**
 * Petición HTTP con cabeceras propias (Origin, Sec-Fetch-Site…), sin seguir
 * redirecciones. Devuelve [estado, cabeceras, cuerpo, Location].
 */
function it_fix_http(string $method, string $path, ?string $cookie = null, array $headers = [], ?string $body = null): array
{
    $s = &it_render_state();
    $h = "Accept-Language: es-ES,es;q=0.9\r\n" . ($cookie ? "Cookie: $cookie\r\n" : '');
    foreach ($headers as $k => $v)
        $h .= "$k: $v\r\n";
    $opts = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20];
    if ($body !== null)
        $opts['content'] = $body;
    $out  = @file_get_contents($s['base'] . $path, false, stream_context_create(['http' => $opts]));
    $hdrs = $http_response_header ?? [];
    $code = $hdrs && preg_match('#^HTTP/\S+\s+(\d{3})#', $hdrs[0], $m) ? (int) $m[1] : 0;
    $loc  = '';
    foreach ($hdrs as $line)
        if (stripos($line, 'Location:') === 0)
            $loc = trim(substr($line, 9));
    return [$code, $hdrs, (string) $out, $loc];
}

/** JSON a la API como $cookie, con cabeceras opcionales. [estado, json]. */
function it_fix_api(string $method, string $path, ?string $cookie, array $body, array $headers = []): array
{
    [$code, , $raw] = it_fix_http($method, $path, $cookie, ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers, json_encode($body));
    $j = json_decode($raw, true);
    return [$code, is_array($j) ? $j : ['_raw' => substr($raw, 0, 300)]];
}

function it_fix_cleanup(PDO $db): void
{
    $in = implode(',', IT_FIX_RES);
    $db->exec("DELETE FROM resource_likes WHERE resource_id IN ($in)");
    $db->exec("DELETE FROM resource_versions WHERE resource_id IN ($in)");
    $db->exec("DELETE FROM resource_tags WHERE resource_id IN ($in)");
    $db->exec("DELETE FROM resources WHERE id IN ($in)");
    $db->exec("DELETE FROM resources WHERE title LIKE 'IT-FIX %'");
    $db->exec("DELETE FROM collections WHERE user_id IN (" . IT_FIX_NEWBIE . ', ' . IT_FIX_OTHER . ')');
    $db->exec("DELETE FROM users WHERE id IN (" . IT_FIX_NEWBIE . ', ' . IT_FIX_OTHER . ')');
    $db->exec("DELETE FROM url_blacklist WHERE domain = 'bloqueada.it-fix.example'");
}

/** Sitio levantado y usuarios propios; null (con SKIP impreso) si falta infraestructura. */
function it_fix_ready(): ?PDO
{
    if (it_render_server() === null) {
        echo '    SKIP arreglos: ' . (it_render_state()['skip'] ?? '') . "\n";
        return null;
    }
    $db = iarepo_it_db();
    it_fix_cleanup($db);
    $n = IT_FIX_NEWBIE;
    $o = IT_FIX_OTHER;
    $db->exec("INSERT INTO users (id, google_id, email, name, role)
               VALUES ($n, 'it-fix-newbie', 'it-newbie@example.test', 'Menor Que Se Salto', 'teacher'),
                      ($o, 'it-fix-other', 'it-fix-other@example.test', 'Otra Docente Fix', 'teacher')");
    // La API de escritura limita a 30 por minuto y por IP; aquí todo sale de
    // 127.0.0.1 y otros ficheros ya escriben: sin esto, un 429 ajeno.
    $db->exec("DELETE FROM api_rate_limits WHERE endpoint IN ('resources_write', 'likes_post')");
    return $db;
}

/** Inserta un recurso de pruebas (html salvo que se diga). */
function it_fix_resource(PDO $db, int $id, array $f = []): void
{
    $f += ['title' => "IT-FIX recurso $id", 'code' => '<p>demo</p>', 'type' => 'html', 'lang' => 'es', 'level' => 'secondary',
           'cat' => 'physics', 'author' => IT_RENDER_TEACHER, 'name' => 'Profe de Pruebas', 'vis' => 'community',
           'topic' => null, 'root' => null, 'fork_of' => null, 'source_url' => null];
    $st = $db->prepare("INSERT INTO resources
        (id, title, description, code_content, code_type, subject_area, topic_tag, lang, level, category_id,
         author_tenant_id, author_user_id, author_display_name, author_tenant_name, visibility, is_active,
         source_url, root_id, fork_of)
        VALUES (?, ?, '', ?, ?, '', ?, ?, ?, (SELECT id FROM categories WHERE slug = ?), 0, ?, ?, '', ?, 1, ?, ?, ?)");
    $st->execute([$id, $f['title'], $f['code'], $f['type'], $f['topic'], $f['lang'], $f['level'], $f['cat'],
                  $f['author'], $f['name'], $f['vis'], $f['source_url'], $f['root'] ?? $id, $f['fork_of']]);
}

// ── Seguridad: CSRF ──────────────────────────────────────────────

function test_fix_el_cambio_de_rol_exige_token_y_mismo_origen(): void
{
    if (!($db = it_fix_ready()))
        return;
    $st = IT_RENDER_STUDENT;
    $role = static fn(): string => (string) $db->query("SELECT role FROM users WHERE id = $st")->fetchColumn();
    try {
        $cookie = it_render_cookie('student');
        $post   = static fn(array $fields, array $extra = []) => it_fix_http('POST', '/auth/onboarding.php', $cookie,
            ['Content-Type' => 'application/x-www-form-urlencoded'] + $extra, http_build_query($fields));

        [$code, , $body] = $post(['role' => 'teacher', 'return_url' => '/profile/' . $st]);
        it_eq(403, $code, 'sin token: 403');
        it_eq('student', $role(), 'y el rol NO cambia (una web ajena ya no convierte a una alumna en docente pública)');
        it_true(str_contains($body, 'role="alert"') && str_contains($body, '</html>'), 'la página se repinta entera, con aviso');

        [, , $page] = it_fix_http('GET', '/auth/onboarding.php', $cookie);
        it_true((bool) preg_match('/name="csrf" value="([0-9a-f]{32})"/', $page, $m), 'el formulario lleva su token');
        $tok = $m[1] ?? '';

        [$code] = $post(['role' => 'teacher', 'csrf' => $tok], ['Origin' => 'http://evil.example']);
        it_eq(403, $code, 'con token pero desde otra web (Origin): 403');
        [$code] = $post(['role' => 'teacher', 'csrf' => $tok], ['Sec-Fetch-Site' => 'cross-site']);
        it_eq(403, $code, 'con token pero Sec-Fetch-Site: cross-site: 403');
        it_eq('student', $role(), 'el rol sigue sin cambiar');

        [, , $prof] = it_fix_http('GET', '/profile/' . $st, $cookie);
        it_true(str_contains($prof, 'name="csrf" value="' . $tok . '"'), 'el formulario «Uso iarepo como» del perfil manda el mismo token');

        [$code, , , $loc] = $post(['role' => 'teacher', 'csrf' => $tok, 'return_url' => '/profile/' . $st],
            ['Origin' => it_render_state()['base'], 'Sec-Fetch-Site' => 'same-origin']);
        it_eq(302, $code, 'con su token y desde aquí: funciona');
        it_eq('/profile/' . $st, $loc, 'y vuelve a donde estaba');
        it_eq('teacher', $role(), 'el rol cambia');
    } finally {
        $db->exec("UPDATE users SET role = 'student' WHERE id = $st");
        it_fix_cleanup($db);
    }
}

function test_fix_la_api_ignora_la_sesion_en_escrituras_de_otra_web(): void
{
    if (!($db = it_fix_ready()))
        return;
    $rid = IT_FIX_RES[0];
    $active = static fn(): int => (int) $db->query("SELECT is_active FROM resources WHERE id = $rid")->fetchColumn();
    try {
        it_fix_resource($db, $rid, ['vis' => 'draft']);
        $cookie = it_render_cookie('teacher');
        $form   = ['Content-Type' => 'application/x-www-form-urlencoded'];
        foreach ([['Origin' => 'http://evil.example'], ['Sec-Fetch-Site' => 'cross-site'], ['Sec-Fetch-Site' => 'same-site'], ['Origin' => 'null']] as $h) {
            $what = key($h) . ': ' . current($h);
            [$code] = it_fix_http('POST', "/api/resources.php?id=$rid", $cookie, $form + $h, '_method=DELETE');
            it_eq(401, $code, "formulario ajeno con _method=DELETE ($what): la sesión no cuenta → 401");
            it_eq(1, $active(), "y el recurso sigue ahí ($what)");
        }
        [$code] = it_fix_http('POST', "/api/resources.php?id=$rid", $cookie, ['Content-Type' => 'text/plain', 'Origin' => 'http://evil.example'],
            json_encode(['title' => 'IT-FIX pisado']));
        it_eq(401, $code, 'ni con un cuerpo JSON en text/plain');

        [$code, $j] = it_fix_api('DELETE', "/api/resources.php?id=$rid", $cookie, [],
            ['Origin' => it_render_state()['base'], 'Sec-Fetch-Site' => 'same-origin']);
        it_eq(200, $code, 'desde la propia web, el autor sí borra: ' . json_encode($j));
        it_eq(0, $active(), 'y el recurso se retira');
    } finally {
        it_fix_cleanup($db);
    }
}

// ── Privacidad ───────────────────────────────────────────────────

function test_fix_un_perfil_sin_nada_publicado_es_404_salvo_para_su_dueno(): void
{
    if (!($db = it_fix_ready()))
        return;
    $n = IT_FIX_NEWBIE;
    try {
        foreach (['' => 'anónimo', 'teacher' => 'otro docente'] as $role => $who) {
            [$code, , $b] = it_fix_http('GET', "/profile/$n", it_render_cookie($role));
            it_eq(404, $code, "$who: una cuenta sin nada publicado (rol teacher por defecto) no tiene perfil público");
            it_true(!str_contains($b, 'Menor Que Se Salto'), "$who: ni su nombre");
        }
        [$code, , $b] = it_fix_http('GET', "/profile/$n", it_render_cookie('teacher', $n));
        it_eq(200, $code, 'su dueño sí lo ve');
        it_true(str_contains($b, '<meta name="robots" content="noindex">'), 'con noindex');

        $db->exec("INSERT INTO collections (user_id, title, description, is_public, item_count) VALUES ($n, 'IT-FIX lista pública', '', 1, 0)");
        [$code, , $b] = it_fix_http('GET', "/profile/$n");
        it_eq(200, $code, 'con una lista pública, el perfil ya es público');
        it_true(str_contains($b, '<meta name="robots" content="noindex">') && !str_contains($b, 'application/ld+json'),
            'pero sin recursos públicos no se indexa ni lleva JSON-LD');
    } finally {
        it_fix_cleanup($db);
    }
}

function test_fix_un_me_gusta_de_quien_aprende_no_lleva_su_nombre(): void
{
    if (!($db = it_fix_ready()))
        return;
    [$r1, $r2] = [IT_FIX_RES[1], IT_FIX_RES[2]];
    $st = IT_RENDER_STUDENT;
    try {
        it_fix_resource($db, $r1, ['title' => 'IT-FIX con me gusta nuevo']);
        it_fix_resource($db, $r2, ['title' => 'IT-FIX con me gusta viejo']);
        // Una fila de ANTES del arreglo, con el nombre guardado.
        $db->exec("INSERT INTO resource_likes (resource_id, user_id, user_name) VALUES ($r2, $st, 'Alumna de Pruebas')");

        [$code, $j] = it_fix_api('POST', "/api/likes.php?id=$r1", it_render_cookie('student'), []);
        it_eq(200, $code, 'la alumna da «Me gusta»: ' . json_encode($j));
        it_eq(null, $db->query("SELECT user_name FROM resource_likes WHERE resource_id = $r1 AND user_id = $st")->fetchColumn(),
            'y su nombre NO se guarda');
        it_eq(1, (int) $db->query("SELECT like_count FROM resources WHERE id = $r1")->fetchColumn(), 'el recuento sí sube');

        $teacher = it_render_cookie('teacher');
        [, , $dash] = it_fix_http('GET', '/dashboard/', $teacher);
        it_true(str_contains($dash, 'Alguien que está aprendiendo'), 'Mi panel: «Alguien que está aprendiendo»');
        it_true(!str_contains($dash, 'Alumna de Pruebas'), 'y ni rastro del nombre, tampoco en las filas de antes');

        [, , $raw] = it_fix_http('GET', '/api/notifications.php', $teacher, ['Accept' => 'application/json']);
        $mine = array_values(array_filter(json_decode($raw, true)['notifications'] ?? [],
            static fn($x) => in_array((int) $x['resource_id'], [$r1, $r2], true)));
        it_eq(2, count($mine), 'la campana trae los dos «Me gusta»');
        it_eq([null, null], array_column($mine, 'actor'), 'sin nombre (el cliente pinta «Alguien que está aprendiendo»)');
    } finally {
        it_fix_cleanup($db);
    }
}

function test_fix_la_version_no_ensena_un_original_que_ya_no_es_publico(): void
{
    if (!($db = it_fix_ready()))
        return;
    [$root, $ver] = [IT_FIX_RES[3], IT_FIX_RES[4]];
    try {
        it_fix_resource($db, $root, ['title' => 'IT-FIX Borrador privado RENOMBRADO', 'vis' => 'draft']);
        it_fix_resource($db, $ver, ['title' => 'IT-FIX Mi versión pública', 'author' => IT_FIX_OTHER, 'name' => 'Otra Docente Fix',
                                    'root' => $root, 'fork_of' => $root]);
        foreach (['' => 'anónimo', 'student' => 'alumna'] as $role => $who) {
            [$code, , $b] = it_fix_http('GET', "/resource/$ver", it_render_cookie($role));
            it_eq(200, $code, "$who: la versión pública se abre");
            it_true(!str_contains($b, 'RENOMBRADO') && !str_contains($b, "href=\"/resource/$root\""),
                "$who: sin el título ni el enlace de un original que ya es un borrador");
        }
        [, , $b] = it_fix_http('GET', "/resource/$ver", it_render_cookie('teacher'));
        it_true(str_contains($b, 'RENOMBRADO'), 'su autor (que sí puede verlo) sigue viendo «Original de…»');

        $db->exec("UPDATE resources SET visibility = 'community' WHERE id = $root");
        [, , $b] = it_fix_http('GET', "/resource/$ver");
        it_true(str_contains($b, 'RENOMBRADO') && str_contains($b, "href=\"/resource/$root\""), 'control: con el original público, sale');
    } finally {
        it_fix_cleanup($db);
    }
}

function test_fix_restringido_con_tenant_0_dice_con_cuenta_en_iarepo(): void
{
    if (!($db = it_fix_ready()))
        return;
    $rid = IT_FIX_RES[8];
    try {
        it_fix_resource($db, $rid, ['vis' => 'school']);
        [$code, , $b] = it_fix_http('GET', "/resource/$rid", it_render_cookie('teacher', IT_FIX_OTHER));
        it_eq(200, $code, 'otra docente (tenant 0) lo abre: canView() la deja');
        it_true(str_contains($b, 'Con cuenta en iarepo') && !str_contains($b, 'Solo para tu centro'),
            'y la ficha lo dice con verdad: «Con cuenta en iarepo», no «Solo para tu centro»');
    } finally {
        it_fix_cleanup($db);
    }
}

// ── Editar y validar direcciones ─────────────────────────────────

function test_fix_editar_un_enlace_conserva_su_fuente_y_cambiar_el_tipo_valida(): void
{
    if (!($db = it_fix_ready()))
        return;
    [$link, $js, $bl] = [IT_FIX_RES[5], IT_FIX_RES[6], IT_FIX_RES[7]];
    $cookie = it_render_cookie('teacher');
    try {
        $sim  = 'https://media.it-fix.example/lizard-evolution/?qa=1';
        $page = 'https://www.it-fix.example/classroom-resources/lizard-evolution-virtual-lab';
        it_fix_resource($db, $link, ['type' => 'url', 'code' => $sim, 'source_url' => $page]);
        // El cuerpo que manda el editor al cambiar SOLO el título de un enlace
        // cuya fuente es otra página: sin source_url (dashboard/editor.php).
        [$code, $j] = it_fix_api('PUT', "/api/resources.php?id=$link", $cookie, [
            'title' => 'IT-FIX título nuevo', 'description' => '', 'code_content' => $sim, 'code_type' => 'url',
            'visibility' => 'community', 'subject_area' => 'Física', 'category_id' => null, 'level' => 'secondary',
            'lang' => 'es', 'tags' => [], 'source_name' => '',
        ]);
        it_eq(200, $code, 'editar el título de un enlace responde 200: ' . json_encode($j));
        it_eq($page, $db->query("SELECT source_url FROM resources WHERE id = $link")->fetchColumn(), 'y su fuente (la página del autor) se conserva');

        [$code, $j] = it_fix_api('PUT', "/api/resources.php?id=$link", $cookie, ['source_url' => 'https://evil.example\\@phet.colorado.edu/sims/x']);
        it_eq(400, $code, '«https://evil\\@phet…» (PHP y el navegador leen hosts distintos) se rechaza');
        it_eq('INVALID_SOURCE_URL', $j['code'] ?? null, 'con su código');

        it_fix_resource($db, $js, ['code' => 'javascript:alert(document.domain)//IT-FIX']);
        [$code, $j] = it_fix_api('PUT', "/api/resources.php?id=$js", $cookie, ['code_type' => 'url']);
        it_eq(400, $code, 'pasar a «enlace» un recurso cuyo contenido no es http(s): 400');
        it_eq('INVALID_URL', $j['code'] ?? null, 'con el código INVALID_URL');
        it_eq('html', $db->query("SELECT code_type FROM resources WHERE id = $js")->fetchColumn(), 'y el tipo no cambia');

        $blocked = 'https://bloqueada.it-fix.example/sim.html';
        $db->exec("INSERT INTO url_blacklist (url, domain, original_title, reason) VALUES ('$blocked', 'bloqueada.it-fix.example', 'IT-FIX', 'broken')");
        it_fix_resource($db, $bl, ['code' => $blocked]);
        [$code, $j] = it_fix_api('PUT', "/api/resources.php?id=$bl", $cookie, ['code_type' => 'url']);
        it_eq(409, $code, 'pasar a «enlace» un contenido que es una URL retirada: 409');
        it_eq('BLACKLISTED_URL', $j['code'] ?? null, 'con el código de la lista negra');
    } finally {
        it_fix_cleanup($db);
    }
}

function test_fix_el_siguiente_paso_sigue_en_el_idioma_del_recurso(): void
{
    if (!($db = it_fix_ready()))
        return;
    [$cur, $en, $es] = [IT_FIX_RES[5], IT_FIX_RES[6], IT_FIX_RES[7]];
    try {
        // Categoría sin datos del corpus: solo compiten estos tres. El inglés
        // tiene tema y está más cerca por id; el español no tiene tema.
        it_fix_resource($db, $cur, ['title' => 'IT-FIX Flotabilidad: Intro', 'cat' => 'art-music']);
        it_fix_resource($db, $en,  ['title' => 'IT-FIX Waves Intro', 'cat' => 'art-music', 'lang' => 'en', 'topic' => 'waves']);
        it_fix_resource($db, $es,  ['title' => 'IT-FIX Densidad', 'cat' => 'art-music']);
        foreach (['' => 'interfaz en español', '?lang=en' => 'interfaz en inglés'] as $q => $who) {
            [$code, , $b] = it_fix_http('GET', "/resource/$cur$q");
            it_eq(200, $code, "la ficha responde ($who)");
            preg_match('#id="siguiente".*?</section>#s', $b, $sec);
            preg_match_all('#href="/resource/(\d+)"#', $sec[0] ?? '', $ids);
            it_eq($es, (int) ($ids[1][0] ?? 0), "$who: el primer «Siguiente paso» de un recurso en español es en español");
        }
    } finally {
        it_fix_cleanup($db);
    }
}

// ── Navegación y páginas ─────────────────────────────────────────

function test_fix_cambiar_de_idioma_conserva_la_pagina(): void
{
    if (!($db = it_fix_ready()))
        return;
    $s = &it_render_state();
    $c = (int) $s['coll'];
    [, , $b] = it_fix_http('GET', "/collection/?id=$c");
    it_true(str_contains($b, 'href="/collection/?lang=en&amp;id=' . $c . '"'), 'en una lista, «EN» sigue en la lista (antes: a la portada)');
    [, , $b] = it_fix_http('GET', '/dashboard/editor.php?id=' . IT_RENDER_RES, it_render_cookie('teacher'));
    it_true(str_contains($b, 'href="/dashboard/editor.php?lang=en&amp;id=' . IT_RENDER_RES . '"'), 'en el editor, sigue en el recurso que editas');
    [, , $b] = it_fix_http('GET', '/auth/signin.php?return_url=%2Ffavorites%2F&save=1000');
    it_true(str_contains($b, 'href="/auth/signin.php?lang=en&amp;return_url=%2Ffavorites%2F&amp;save=1000"'), 'en Entrar, conserva a dónde volver y el guardado');
    it_fix_cleanup($db);
}

function test_fix_la_cabecera_deja_salir_en_escritorio_y_el_salto_se_ve(): void
{
    if (!($db = it_fix_ready()))
        return;
    [, , $b] = it_fix_http('GET', '/', it_render_cookie('teacher'));
    $mobileNav = preg_match('#<nav class="ia-container ia-mobile-nav".*?</nav>#s', $b, $m) ? $m[0] : '';
    $outside   = str_replace($mobileNav, '', $b);
    it_true(str_contains($outside, 'class="ia-btn ia-btn-ghost ia-btn-sm ia-logout" href="/auth/logout.php"'),
        'con sesión, «Salir» está en la cabecera y no solo en el menú móvil (oculto desde 900 px)');
    it_true(str_contains($b, 'class="ia-sr-only ia-skip" href="#main"'), '«Saltar al contenido» lleva la clase que lo muestra al enfocarlo');
    [, , $anon] = it_fix_http('GET', '/');
    it_true(!str_contains($anon, '/auth/logout.php'), 'sin sesión no hay «Salir»');
    it_true(str_contains($anon, '>Para docentes</a>') && str_contains($anon, 'href="/#listos"'), 'sin sesión «Para docentes» lleva a «Listos para clase», no a un muro');
    it_fix_cleanup($db);
}

function test_fix_un_docente_nuevo_no_ve_un_muro_de_ceros(): void
{
    if (!($db = it_fix_ready()))
        return;
    try {
        [$code, , $b] = it_fix_http('GET', '/dashboard/', it_render_cookie('teacher', IT_FIX_NEWBIE));
        it_eq(200, $code, 'el panel de un docente sin recursos responde');
        it_true(!str_contains($b, 'class="db-stats"'), 'sin la fila de cifras a cero');
        it_true(str_contains($b, 'Publicar mi primer recurso'), 'y con el estado vacío útil');
        it_true(str_contains($b, 'aria-label="Mi perfil público"'), 'el enlace al perfil tiene nombre también en móvil');
        [, , $b] = it_fix_http('GET', '/dashboard/', it_render_cookie('teacher'));
        it_true(str_contains($b, 'class="db-stats"'), 'control: con recursos, sus cifras');
    } finally {
        it_fix_cleanup($db);
    }
}

function test_fix_el_visor_no_tiene_un_cerrar_muerto(): void
{
    if (!($db = it_fix_ready()))
        return;
    [$code, , $b] = it_fix_http('GET', '/view/1000');
    it_eq(200, $code, 'el visor responde');
    it_true(str_contains($b, '<a class="btn btn-close" id="btnClose" href="/resource/1000"'),
        'quien llega por el QR tiene un enlace a la ficha, no un «Cerrar» que no hace nada');
    it_fix_cleanup($db);
}

function test_fix_parametros_hostiles_no_rompen_ni_sacan_de_la_web(): void
{
    if (!($db = it_fix_ready()))
        return;
    [$code, , $b] = it_fix_http('GET', '/unsubscribe.php?token[]=x');
    it_eq(200, $code, '?token[]=x en la baja: la página de enlace no válido, no un 500');
    it_true(str_contains($b, '</html>'), 'entera');

    [$code, , , $loc] = it_fix_http('GET', '/auth/signin.php?return_url=/%09/evil.example/x', it_render_cookie('teacher'));
    it_eq(302, $code, 'con sesión, Entrar redirige');
    it_eq('/', $loc, 'pero a la portada: «/<TAB>/otra.web» no es una ruta de aquí');

    [, , $b] = it_fix_http('GET', '/auth/signin.php?return_url=%2F%2509%2Fevil.example');
    it_true((bool) preg_match('#<a href="/">← #u', $b) && !str_contains($b, 'evil.example/"'), 'sin sesión, «Volver» lleva a la portada');
    it_fix_cleanup($db);
}

function test_fix_las_filas_de_la_portada_dicen_el_idioma(): void
{
    if (!($db = it_fix_ready()))
        return;
    [, , $b] = it_fix_http('GET', '/');
    if (!str_contains($b, 'id="listos"') && !str_contains($b, 'id="para-empezar"')) {
        echo "    SKIP filas: el corpus no llena ninguna sección de la portada\n";
        it_fix_cleanup($db);
        return;
    }
    it_true(str_contains($b, 'class="home-row-lang'), 'cada fila de «Listos para clase» / «Para empezar» dice su idioma');
    it_fix_cleanup($db);
}

function test_fix_la_ficha_sigue_la_secuencia_de_la_lista(): void
{
    if (!($db = it_fix_ready()))
        return;
    $s = &it_render_state();
    $c = (int) $s['coll'];   // «Lista de pruebas» (pública) con el 1000
    try {
        // 1031 va ANTES que el 1000 (fecha anterior): la lista es 1031 → 1000.
        $db->exec("UPDATE collection_items SET added_at = '2026-01-02 10:00:00' WHERE collection_id = $c AND resource_id = 1000");
        $db->exec("INSERT INTO collection_items (collection_id, resource_id, added_at) VALUES ($c, 1031, '2026-01-01 10:00:00')");

        [, , $list] = it_fix_http('GET', "/collection/?id=$c");
        it_true(str_contains($list, 'href="/resource/1031?list=' . $c . '"'), 'la lista enlaza sus pasos con ?list=');

        [$code, , $b] = it_fix_http('GET', "/resource/1031?list=$c", it_render_cookie('student'));
        it_eq(200, $code, 'el primer paso se abre');
        it_true(str_contains($b, 'class="rf-listnav"') && str_contains($b, 'Paso 1 de 2') && str_contains($b, 'Lista de pruebas'),
            'con «Paso 1 de 2 · <lista>»');
        it_true(str_contains($b, 'id="listNext" href="/resource/1000?list=' . $c . '"'), 'y el siguiente paso DE LA LISTA');
        it_true(str_contains($b, 'href="/collection/?id=' . $c . '"'), 'y volver a la lista');

        [, , $b] = it_fix_http('GET', "/resource/1000?list=$c");
        it_true(str_contains($b, 'Paso 2 de 2') && str_contains($b, 'Último paso de la lista') && !str_contains($b, 'id="listNext"'), 'el último paso lo dice');

        [, , $b] = it_fix_http('GET', "/resource/1002?list=$c");
        it_true(!str_contains($b, 'class="rf-listnav"'), 'un recurso que no está en la lista no inventa una secuencia');
        [, , $b] = it_fix_http('GET', '/resource/1031?list[]=1');
        it_true(!str_contains($b, 'class="rf-listnav"') && str_contains($b, '</html>'), '?list[]= no rompe nada');

        $db->exec("UPDATE collections SET is_public = 0 WHERE id = $c");
        [, , $b] = it_fix_http('GET', "/resource/1031?list=$c");
        it_true(!str_contains($b, 'Lista de pruebas'), 'una lista privada no se enseña a quien no es su dueño');
        [, , $b] = it_fix_http('GET', "/resource/1031?list=$c", it_render_cookie('teacher'));
        it_true(str_contains($b, 'Paso 1 de 2'), 'a su dueño, sí');
    } finally {
        $db->exec("UPDATE collections SET is_public = 1 WHERE id = $c");
        $db->exec("DELETE FROM collection_items WHERE collection_id = $c AND resource_id = 1031");
        it_fix_cleanup($db);
    }
}
