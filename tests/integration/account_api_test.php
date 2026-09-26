<?php
// ================================================================
// tests/integration/account_api_test.php — Publicar y editar desde el editor
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// Desde el rediseño 2026-09, dashboard/editor.php manda source_name y
// source_url a api/resources.php (POST crear, PUT editar). Antes la API los
// ignoraba: la ficha no podía decir «Creado por PhET» y la lista negra de
// URLs retiradas —que dependía de source_url— no se aplicaba NUNCA desde el
// editor. Ahora sí, y con ello llega un valor del usuario que acaba en un
// href de la ficha y, en un recurso 'url', en el src del visor.
//
// Aquí se prueba por HTTP, contra el sitio levantado (site_server.php), lo
// que importa y no falla ruidosamente si se rompe:
//   · un javascript: (o data:, o //host) como fuente o como enlace → 400 con
//     su CÓDIGO (el editor traduce por código, no por texto);
//   · la fuente válida se guarda y la API la devuelve ya etiquetada;
//   · al EDITAR se puede cambiar y quitar la fuente, y un cliente que no la
//     manda (Campus) no la borra;
//   · la lista negra se aplica al crear Y al editar.
// La lógica pura (qué es una dirección válida) está además en
// tests/unit/account_pages_test.php, que la ejecuta con más entradas.
// ================================================================

require_once __DIR__ . '/site_server.php';

const IT_ACC_BLOCKED = 'https://retirada.it-account.example/sim.html';

/** POST/PUT JSON como el profesor de pruebas. [estado, json]. */
function it_acc_send(string $method, string $query, array $body, string $role = 'teacher'): array
{
    $s = &it_render_state();
    [$code, , $raw] = it_render_request($method, $s['base'] . '/api/resources.php' . $query, it_render_cookie($role), $body);
    $j = json_decode($raw, true);
    return [$code, is_array($j) ? $j : ['_raw' => substr($raw, 0, 300)]];
}

/** Borra lo que hayan creado estos tests (por título, con prefijo propio). */
function it_acc_cleanup(PDO $db): void
{
    $ids = $db->query("SELECT id FROM resources WHERE title LIKE 'IT-ACC %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $db->exec("DELETE FROM resource_tags WHERE resource_id IN ($in)");
        $db->exec("DELETE FROM resource_versions WHERE resource_id IN ($in)");
        $db->exec("DELETE FROM resources WHERE id IN ($in)");
    }
    $db->exec("DELETE FROM url_blacklist WHERE url = '" . IT_ACC_BLOCKED . "'");
}

function it_acc_ready(): ?PDO
{
    if (it_render_server() === null) {
        echo '    SKIP cuenta: ' . (it_render_state()['skip'] ?? '') . "\n";
        return null;
    }
    $db = iarepo_it_db();
    it_acc_cleanup($db);
    return $db;
}

function test_una_fuente_que_no_es_http_se_rechaza_con_su_codigo(): void
{
    if (!($db = it_acc_ready()))
        return;
    try {
        $sim = 'https://phet.colorado.edu/sims/html/it-account/latest/sim_' . bin2hex(random_bytes(3)) . '.html';
        foreach (['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', '//evil.example/x', 'phet.colorado.edu'] as $bad) {
            [$code, $j] = it_acc_send('POST', '', ['title' => 'IT-ACC fuente mala', 'code_type' => 'url',
                                                   'code_content' => $sim, 'source_url' => $bad]);
            it_eq(400, $code, "source_url «{$bad}» se rechaza");
            it_eq('INVALID_SOURCE_URL', $j['code'] ?? null, "con el código INVALID_SOURCE_URL («{$bad}»)");

            [$code, $j] = it_acc_send('POST', '', ['title' => 'IT-ACC enlace malo', 'code_type' => 'url', 'code_content' => $bad]);
            it_eq(400, $code, "un recurso 'url' con «{$bad}» se rechaza");
            it_eq('INVALID_URL', $j['code'] ?? null, "con el código INVALID_URL («{$bad}»)");
        }
        [$code, $j] = it_acc_send('POST', '', ['title' => ['IT-ACC'], 'code_type' => 'html', 'code_content' => '<p>x</p>']);
        it_eq(400, $code, 'un título que no es texto es un 400, no un 500');
        it_eq('MISSING_TITLE', $j['code'] ?? null, 'con su código');

        $n = (int) $db->query("SELECT COUNT(*) FROM resources WHERE title LIKE 'IT-ACC %'")->fetchColumn();
        it_eq(0, $n, 'ningún intento rechazado dejó una fila');
    } finally {
        it_acc_cleanup($db);
    }
}

function test_la_fuente_valida_se_guarda_se_edita_y_no_la_borra_quien_no_la_manda(): void
{
    if (!($db = it_acc_ready()))
        return;
    try {
        $sim = 'https://phet.colorado.edu/sims/html/it-account/latest/sim_' . bin2hex(random_bytes(3)) . '.html';
        [$code, $j] = it_acc_send('POST', '', [
            'title' => 'IT-ACC Gravedad', 'code_type' => 'url', 'code_content' => "  $sim ",
            'source_name' => "PhET Interactive Simulations\n", 'visibility' => 'draft', 'tags' => ['órbitas', ['no'], 7],
        ]);
        it_eq(200, $code, 'crear con fuente válida responde 200: ' . json_encode($j));
        $id = (int) ($j['id'] ?? 0);
        it_true($id > 0, 'y devuelve el id');

        $row = $db->query("SELECT code_content, source_name, source_url FROM resources WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        it_eq($sim, $row['code_content'] ?? null, 'la dirección se guarda sin espacios');
        it_eq('PhET Interactive Simulations', $row['source_name'] ?? null, 'el nombre, en una línea');
        it_eq($sim, $row['source_url'] ?? null, "sin source_url, la fuente de un 'url' es su propia dirección");

        [$code, $j] = it_acc_send('PUT', "?id=$id", ['source_url' => 'javascript:alert(1)']);
        it_eq(400, $code, 'al editar, un javascript: también se rechaza');
        it_eq('INVALID_SOURCE_URL', $j['code'] ?? null, 'con su código');

        $page = 'https://phet.colorado.edu/es/simulations/it-account';
        [$code] = it_acc_send('PUT', "?id=$id", ['source_url' => $page, 'source_name' => 'PhET']);
        it_eq(200, $code, 'editar la fuente responde 200');
        [$code] = it_acc_send('PUT', "?id=$id", ['title' => 'IT-ACC Gravedad y órbitas']);
        it_eq(200, $code, 'una edición sin las claves de la fuente responde 200');
        $row = $db->query("SELECT source_name, source_url FROM resources WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        it_eq(['source_name' => 'PhET', 'source_url' => $page], $row, 'y NO borra la fuente (así Campus no la pisa)');

        [$code] = it_acc_send('PUT', "?id=$id", ['source_name' => '']);
        it_eq(200, $code, 'vaciar el nombre responde 200');
        it_eq(null, $db->query("SELECT source_name FROM resources WHERE id = $id")->fetchColumn(), 'y lo quita (NULL)');

        [$code] = it_acc_send('PUT', "?id=$id", ['lang' => 'xx', 'visibility' => 'nope', 'code_type' => 'bogus']);
        it_eq(200, $code, 'valores fuera del ENUM se ignoran en vez de dar un 500');

        [$code, $j] = it_acc_send('PUT', "?id=$id", ['title' => 'IT-ACC ajeno'], 'student');
        it_eq(403, $code, 'quien no es el autor no puede editar');
        it_eq('NOT_AUTHOR', $j['code'] ?? null, 'con el código NOT_AUTHOR');
    } finally {
        it_acc_cleanup($db);
    }
}

function test_la_lista_negra_se_aplica_al_crear_y_al_editar(): void
{
    if (!($db = it_acc_ready()))
        return;
    try {
        $db->exec("INSERT INTO url_blacklist (url, domain, original_title, reason)
                   VALUES ('" . IT_ACC_BLOCKED . "', 'retirada.it-account.example', 'Sim retirada', 'broken')");

        [$code, $j] = it_acc_send('POST', '', ['title' => 'IT-ACC retirada', 'code_type' => 'url', 'code_content' => IT_ACC_BLOCKED]);
        it_eq(409, $code, 'crear con una dirección retirada: 409');
        it_eq('BLACKLISTED_URL', $j['code'] ?? null, 'con el código BLACKLISTED_URL, aunque el cliente no mande source_url');

        $ok = 'https://phet.colorado.edu/sims/html/it-account/ok_' . bin2hex(random_bytes(3)) . '.html';
        [$code, $j] = it_acc_send('POST', '', ['title' => 'IT-ACC buena', 'code_type' => 'url', 'code_content' => $ok, 'visibility' => 'draft']);
        it_eq(200, $code, 'una dirección que funciona se publica');
        $id = (int) ($j['id'] ?? 0);

        [$code, $j] = it_acc_send('PUT', "?id=$id", ['code_type' => 'url', 'code_content' => IT_ACC_BLOCKED, 'source_url' => IT_ACC_BLOCKED]);
        it_eq(409, $code, 'y no se puede cambiar DESPUÉS por una retirada');
        it_eq('BLACKLISTED_URL', $j['code'] ?? null, 'con el mismo código');
        it_eq($ok, $db->query("SELECT code_content FROM resources WHERE id = $id")->fetchColumn(), 'la dirección buena sigue ahí');
    } finally {
        it_acc_cleanup($db);
    }
}
