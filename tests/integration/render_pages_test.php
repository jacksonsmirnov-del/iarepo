<?php
// ================================================================
// tests/integration/render_pages_test.php — Cada página HTML, abierta de verdad
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// dashboard/index.php estuvo roto del 2026-06-13 al 2026-09-26: dos llamadas
// t(T.creating) —JavaScript pegado dentro de PHP— lanzaban "Undefined
// constant T", el error_handler volcaba un JSON en mitad del <script> y todo
// el JS del panel moría. En esos tres meses pasaron 136 tests, 9 guards, la CI
// y el smoke: NINGUNA capa abría la página tal como la ve una persona, y
// menos aún con sesión iniciada.
//
// Este fichero lo hace. Levanta el sitio con `php -S` sobre la BD de
// integración, entra como anónimo, alumno y profesor, y pide cada página.
// Una página pasa si:
//   · responde con el estado esperado para ese rol (200, o 302 si no es suya);
//   · si es HTML, llega entera hasta </html>;
//   · no lleva dentro un volcado JSON de error ({"ok":false…);
//   · no imprime avisos ni errores de PHP (se arranca con display_errors=1).
//
// ── NADA DE ESTO QUEDA EN EL ÁRBOL SERVIDO ────────────────────
// El sitio se copia a un directorio temporal (git ls-files) y es AHÍ donde se
// escriben el .env.php de pruebas, el router y el atajo de login. Ninguna vía
// de autenticación de pruebas existe como fichero del repo: con push =
// producción, un login de pruebas versionado sería un bypass en vivo.
// ================================================================

require_once __DIR__ . '/bootstrap.php';

const IT_RENDER_TEACHER = 9001;
const IT_RENDER_STUDENT = 9002;
const IT_RENDER_RES     = 1098;   // recurso privado del profesor de pruebas

/** Estado del servidor de pruebas, memoizado por proceso. */
function &it_render_state(): array
{
    static $s = ['tried' => false, 'base' => null, 'dir' => null, 'proc' => null,
                 'login' => null, 'skip' => null, 'error' => null, 'coll' => 0];
    return $s;
}

function it_render_rrmdir(string $dir): void
{
    if (!is_dir($dir))
        return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f)
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

/** Copia los ficheros versionados del repo a $dst. */
function it_render_copy_site(string $dst): ?string
{
    $root = iarepo_it_root();
    $r    = iarepo_it_sh('git -C ' . escapeshellarg($root) . ' ls-files -z', 30);
    if ($r['code'] !== 0 || $r['out'] === '')
        return 'git ls-files no está disponible';

    foreach (explode("\0", $r['out']) as $rel) {
        if ($rel === '' || str_starts_with($rel, '.git'))
            continue;
        $src = "$root/$rel";
        if (!is_file($src))
            continue;
        $to = "$dst/$rel";
        if (!is_dir(dirname($to)))
            mkdir(dirname($to), 0777, true);
        copy($src, $to);
    }
    return null;
}

/** Datos mínimos para que las páginas con sesión tengan algo que pintar. */
function it_render_seed(PDO $db): int
{
    $t = IT_RENDER_TEACHER;
    $s = IT_RENDER_STUDENT;
    $r = IT_RENDER_RES;

    $db->exec("DELETE FROM users WHERE id IN ($t, $s)");
    $db->exec("INSERT INTO users (id, google_id, email, name, role)
               VALUES ($t, 'it-render-teacher', 'it-teacher@example.test', 'Profe de Pruebas', 'teacher'),
                      ($s, 'it-render-student', 'it-student@example.test', 'Alumna de Pruebas', 'student')");

    // Borrador: no aparece en el buscador, así que no altera el corpus que
    // miden search_db_test.php y compañía.
    $db->exec("DELETE FROM resources WHERE id = $r");
    $db->exec("INSERT INTO resources
        (id, title, description, code_content, code_type, subject_area, lang, level,
         author_tenant_id, author_user_id, author_display_name, author_tenant_name, visibility, is_active)
        VALUES ($r, 'Recurso de pruebas del panel', 'Solo lo ve su autor.', '<p>demo</p>',
                'html', 'Física', 'es', 'secondary', 0, $t, 'Profe de Pruebas', '', 'draft', 1)");

    $db->exec("DELETE FROM collections WHERE user_id = $t AND title = 'Lista de pruebas'");
    $db->exec("INSERT INTO collections (user_id, title, description, is_public, item_count)
               VALUES ($t, 'Lista de pruebas', 'Para el test de render', 1, 1)");
    $cid = (int) $db->lastInsertId();
    $db->exec("INSERT INTO collection_items (collection_id, resource_id) VALUES ($cid, 1000)");

    $db->exec("INSERT IGNORE INTO resource_favorites (user_id, resource_id) VALUES ($s, 1000)");
    return $cid;
}

function it_render_cleanup(): void
{
    $s = &it_render_state();
    if (is_resource($s['proc'])) {
        proc_terminate($s['proc']);
        proc_close($s['proc']);
    }
    if ($s['dir'])
        it_render_rrmdir($s['dir']);

    $db = iarepo_it_db();
    if ($db) {
        $t = IT_RENDER_TEACHER;
        $st = IT_RENDER_STUDENT;
        $db->exec("DELETE FROM collection_items WHERE collection_id IN (SELECT id FROM collections WHERE user_id = $t)");
        $db->exec("DELETE FROM collections WHERE user_id = $t");
        $db->exec("DELETE FROM resource_favorites WHERE user_id IN ($t, $st)");
        $db->exec('DELETE FROM resources WHERE id = ' . IT_RENDER_RES);
        $db->exec("DELETE FROM users WHERE id IN ($t, $st)");
    }
}

/**
 * Arranca el sitio. Devuelve la URL base, o null si falta infraestructura
 * (motivo en 'skip'). Un fallo AL PREPARAR —esquema, datos, copia— no es falta
 * de infraestructura sino un defecto: se relanza en cada test que lo pida, para
 * que ninguno pase en verde con cero aserciones.
 */
function it_render_server(): ?string
{
    $s = &it_render_state();
    if ($s['error'] !== null)
        throw new RuntimeException('el sitio de pruebas no se pudo preparar: ' . $s['error']);
    if ($s['tried'])
        return $s['base'];
    $s['tried'] = true;
    try {
        return it_render_boot();
    } catch (Throwable $e) {
        $s['error'] = $e->getMessage();
        throw $e;
    }
}

function it_render_boot(): ?string
{
    $s = &it_render_state();

    $db = iarepo_it_db();
    if ($db === null) {
        $s['skip'] = iarepo_it_skip_reason() ?? 'sin BD';
        return null;
    }

    $dir  = sys_get_temp_dir() . '/iarepo_render_' . getmypid() . '_' . bin2hex(random_bytes(3));
    $site = "$dir/site";
    mkdir($site, 0777, true);
    $s['dir'] = $dir;
    register_shutdown_function('it_render_cleanup');

    if (($why = it_render_copy_site($site)) !== null) {
        $s['skip'] = $why;
        return null;
    }

    $port = (int) iarepo_it_cfg('DB_PORT', '3398');
    $name = iarepo_it_cfg('DB_NAME', 'iarepo_test');
    $env  = [
        'DB_HOST' => "127.0.0.1;port=$port", 'DB_NAME' => $name,
        'DB_USER' => 'root', 'DB_PASS' => IAREPO_IT_PASS,
        'JWT_SECRET' => bin2hex(random_bytes(32)), 'GOOGLE_CLIENT_ID' => 'it-render',
        'ADMIN_PASS' => bin2hex(random_bytes(16)), 'CRON_SECRET' => bin2hex(random_bytes(16)),
        'OPEN_REGISTRATION' => false, 'DEBUG' => false, 'ALLOWED_ORIGINS' => [],
    ];
    file_put_contents("$site/.env.php", '<?php return ' . var_export($env, true) . ';');

    // Atajo de login SOLO en la copia temporal, con nombre imprevisible.
    $login = '__it_login_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents("$site/$login", '<?php
session_start();
$_SESSION["user"] = ["id" => (int) $_GET["id"], "role" => (string) $_GET["role"],
                     "name" => "Usuario de pruebas", "email" => "it@example.test", "avatar_url" => ""];
echo "ok";');
    $s['login'] = $login;

    // Router: lo mismo que hace .htaccess con las rutas bonitas y el 404.
    file_put_contents("$dir/router.php", '<?php
$root = ' . var_export($site, true) . ';
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($p === "/sitemap.xml") { chdir($root); require "$root/sitemap.php"; return true; }
foreach (["#^/view/(\d+)$#" => "viewer", "#^/resource/(\d+)$#" => "resource", "#^/profile/(\d+)$#" => "profile"] as $re => $d) {
    if (preg_match($re, $p, $m)) { $_GET["id"] = $m[1]; chdir("$root/$d"); require "$root/$d/index.php"; return true; }
}
if (is_dir("$root$p") && is_file(rtrim("$root$p", "/") . "/index.php")) { chdir("$root$p"); require rtrim("$root$p", "/") . "/index.php"; return true; }
if (is_file("$root$p")) {
    if (str_ends_with($p, ".php")) { chdir(dirname("$root$p")); require "$root$p"; return true; }
    return false;
}
http_response_code(404); chdir($root); require "$root/404.php"; return true;');

    $s['coll'] = it_render_seed($db);

    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $addr = stream_socket_get_name($sock, false);
    fclose($sock);
    $httpPort = (int) substr((string) strrchr($addr, ':'), 1);

    $cmd = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL,
            '-S', "127.0.0.1:$httpPort", '-t', $site, "$dir/router.php"];
    $s['proc'] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', "$dir/server.log", 'a'],
                                  2 => ['file', "$dir/server.log", 'a']], $pipes, $site);
    if (!is_resource($s['proc'])) {
        $s['skip'] = 'no se pudo arrancar php -S';
        return null;
    }

    $base = "http://127.0.0.1:$httpPort";
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $httpPort, $en, $es, 0.2);
        if ($c) {
            fclose($c);
            $s['base'] = $base;
            return $base;
        }
        usleep(100000);
    }
    $s['skip'] = 'php -S no respondió en 5 s';
    return null;
}

/** GET sin seguir redirecciones. Devuelve [estado, cabeceras, cuerpo]. */
function it_render_get(string $url, ?string $cookie = null): array
{
    $headers = "Accept: text/html,application/xhtml+xml\r\nAccept-Language: es-ES,es;q=0.9\r\n";
    if ($cookie)
        $headers .= "Cookie: $cookie\r\n";
    $ctx  = stream_context_create(['http' => [
        'method' => 'GET', 'header' => $headers, 'ignore_errors' => true,
        'follow_location' => 0, 'timeout' => 20,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $hdrs = $http_response_header ?? [];
    $code = 0;
    if ($hdrs && preg_match('#^HTTP/\S+\s+(\d{3})#', $hdrs[0], $m))
        $code = (int) $m[1];
    return [$code, $hdrs, (string) $body];
}

/** Cookie de sesión para un rol ('' = anónimo). */
function it_render_cookie(string $role): ?string
{
    if ($role === '')
        return null;
    $s  = &it_render_state();
    $id = $role === 'student' ? IT_RENDER_STUDENT : IT_RENDER_TEACHER;
    [$code, $hdrs] = it_render_get($s['base'] . '/' . $s['login'] . "?id=$id&role=$role");
    it_eq(200, $code, "el atajo de login de pruebas responde ($role)");
    foreach ($hdrs as $h)
        if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $h, $m))
            return $m[1];
    throw new RuntimeException("el atajo de login no devolvió cookie de sesión ($role)");
}

/**
 * Páginas y estado esperado por rol. [ruta, anónimo, alumno, profesor].
 * Un 302 es correcto cuando la página no es para ese rol.
 */
function it_render_matrix(): array
{
    $s   = &it_render_state();
    $res = IT_RENDER_RES;
    return [
        ['/',                                   200, 200, 200],
        ['/?search=ondas',                      200, 200, 200],
        ['/resource/1000',                      200, 200, 200],
        ['/view/1000',                          200, 200, 200],
        ['/profile/' . IT_RENDER_TEACHER,       200, 200, 200],
        ['/collection/?id=' . $s['coll'],       200, 200, 200],
        ['/favorites/',                         302, 200, 200],
        ['/dashboard/',                         302, 302, 200],
        ['/dashboard/editor.php',               302, 302, 200],
        ["/dashboard/editor.php?id=$res",       302, 302, 200],
        ['/auth/signin.php',                    200, 302, 302],   // con sesión, fuera
        ['/legal/terms.php',                    200, 200, 200],
        ['/esta-ruta-no-existe',                404, 404, 404],
    ];
}

function it_render_check_html(string $label, array $hdrs, string $body): void
{
    $isHtml = false;
    foreach ($hdrs as $h)
        if (stripos($h, 'Content-Type:') === 0)
            $isHtml = stripos($h, 'text/html') !== false;
    it_true($isHtml, "$label: responde HTML");
    it_true(stripos($body, '</html>') !== false, "$label: la página llega entera hasta </html>");
    it_true(!str_contains($body, '{"ok":false'), "$label: no lleva un volcado JSON de error dentro");
    it_true(!preg_match('/<b>(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>|Uncaught /', $body, $m),
        "$label: sin avisos ni errores de PHP" . (isset($m[0]) ? " (encontrado: {$m[0]})" : ''));
}

function test_cada_pagina_se_renderiza_entera_para_cada_rol(): void
{
    if (it_render_server() === null) {
        $s = &it_render_state();
        echo "    SKIP render: {$s['skip']}\n";
        return;
    }
    $s = &it_render_state();

    foreach (['' => 1, 'student' => 2, 'teacher' => 3] as $role => $col) {
        $cookie = it_render_cookie($role);
        $who    = $role === '' ? 'anónimo' : $role;
        foreach (it_render_matrix() as $row) {
            $path  = $row[0];
            $label = "$path como $who";
            [$code, $hdrs, $body] = it_render_get($s['base'] . $path, $cookie);
            it_eq($row[$col], $code, "$label: estado HTTP");
            if ($code === 200 || $code === 404)
                it_render_check_html($label, $hdrs, $body);
        }
    }
}

function test_el_panel_del_autor_ejecuta_su_javascript(): void
{
    if (it_render_server() === null) {
        echo "    SKIP render: " . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s = &it_render_state();
    [$code, , $body] = it_render_get($s['base'] . '/dashboard/', it_render_cookie('teacher'));
    it_eq(200, $code, 'el panel responde al profesor');
    // El fallo de junio cortaba la página justo aquí: el objeto T nunca se
    // cerraba y nada de lo que venía detrás llegaba al navegador.
    it_true((bool) preg_match('/creating: "[^"]*Creando\.\.\."/', $body), 'el objeto T del panel lleva sus textos');
    it_true(str_contains($body, 'lucide.createIcons'), 'el script del panel llega hasta el final');
    it_true(str_contains($body, 'Recurso de pruebas del panel'), 'el panel lista los recursos del autor');
}

function test_el_servidor_no_registra_errores_de_php(): void
{
    if (it_render_server() === null) {
        echo "    SKIP render: " . (it_render_state()['skip'] ?? '') . "\n";
        return;
    }
    $s   = &it_render_state();
    $log = (string) @file_get_contents($s['dir'] . '/server.log');
    it_true(!preg_match('/PHP (Warning|Notice|Fatal error|Parse error)[^\n]*/', $log, $m),
        'php -S no registró avisos ni errores' . (isset($m[0]) ? ": {$m[0]}" : ''));
}
