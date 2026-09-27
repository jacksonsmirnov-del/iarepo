<?php
// ================================================================
// tests/unit/srcdoc_test.php — Los enlaces internos no sacan del recurso
//
// ── QUÉ PROTEGE ───────────────────────────────────────────────
// Un recurso 'html' se pinta en <iframe srcdoc>, y un srcdoc resuelve sus
// enlaces contra la URL de la página que lo contiene: <a href="#ecuaciones">
// cargaba /resource/610#ecuaciones DENTRO del iframe (la ficha de iarepo
// dentro de sí misma) y el recurso desaparecía [2026-09-27]. Arreglo:
// shared/srcdoc.php añade un script al final del HTML del autor.
//
//   · El script: #id desplaza, # sube, un relativo no hace nada; lo absoluto,
//     con target, con Ctrl/Cmd/Mayús o ya gestionado por el recurso, no se
//     toca. Se EJECUTA en Node contra un documento de mentira.
//   · Va AL FINAL: delante del <!doctype> pondría la página en quirks.
//   · Toda incrustación srcdoc del repo pasa por iarepo_srcdoc() (o por el
//     mismo script en las vistas previas de JS). Una nueva que no lo haga
//     vuelve a tener el fallo sin que nada lo avise.
// ================================================================

require_once IAREPO_ROOT . '/shared/srcdoc.php';

/**
 * Ejecuta el script en Node con un documento de mentira y le lanza un clic.
 * Devuelve lo que pasó: si se evitó la navegación y a dónde se desplazó.
 */
function sd_click(string $href, array $opts = []): ?array
{
    exec('command -v node 2>/dev/null', $o, $rc);
    if ($rc !== 0)
        return null;
    $shim = iarepo_srcdoc_shim();
    $js   = substr($shim, strpos($shim, '>') + 1, -strlen('</script>'));
    $case = json_encode(['href' => $href] + $opts);
    $prog = <<<JS
const c = $case;
const log = { prevented: false, scrolledTo: null, top: false };
const els = { ecuaciones: { id: 'ecuaciones' }, 'café': { id: 'café' } };
for (const k in els) els[k].scrollIntoView = () => { log.scrolledTo = k; };
const named = { seccion2: { scrollIntoView(){ log.scrolledTo = 'name:seccion2'; } } };
let handler = null;
global.document = {
  addEventListener(type, fn) { if (type === 'click') handler = fn; },
  getElementById(id) { return els[id] || null; },
  getElementsByName(n) { return named[n] ? [named[n]] : []; },
};
global.window = { scrollTo() { log.top = true; } };
$js
const a = { getAttribute(n) { return n === 'href' ? c.href : (n === 'target' ? (c.target || null) : null); } };
const e = {
  target: { closest: () => a }, button: c.button || 0, defaultPrevented: !!c.already,
  metaKey: false, ctrlKey: !!c.ctrl, shiftKey: false, altKey: false,
  preventDefault() { log.prevented = true; },
};
handler(e);
process.stdout.write(JSON.stringify(log));
JS;
    $out = shell_exec('node -e ' . escapeshellarg($prog) . ' 2>&1');
    $j = json_decode((string) $out, true);
    if (!is_array($j))
        throw new RuntimeException('el script no se pudo ejecutar en Node: ' . substr((string) $out, 0, 300));
    return $j;
}

function test_los_enlaces_internos_se_quedan_dentro_del_recurso(): void
{
    if (($r = sd_click('#ecuaciones')) === null) {
        echo "    SKIP node no está instalado\n";
        return;
    }
    assert_eq(['prevented' => true, 'scrolledTo' => 'ecuaciones', 'top' => false], $r,
        '#ecuaciones: no navega, desplaza hasta la sección');
    assert_eq('name:seccion2', sd_click('#seccion2')['scrolledTo'], 'también con <a name="…">');
    assert_eq('café', sd_click('#caf%C3%A9')['scrolledTo'], 'y con el id codificado en la URL');
    assert_eq(['prevented' => true, 'scrolledTo' => null, 'top' => true], sd_click('#'), '«#» vuelve arriba');
    assert_eq(['prevented' => true, 'scrolledTo' => null, 'top' => false], sd_click('#no-existe'),
        'un ancla que no existe no hace nada (antes cargaba la ficha dentro)');
    assert_eq(true, sd_click('tema2.html')['prevented'], 'un relativo no carga iarepo dentro del iframe');
    assert_eq(true, sd_click('')['prevented'], 'ni un href vacío');
}

function test_lo_que_no_es_interno_no_se_toca(): void
{
    if (sd_click('#x') === null) {
        echo "    SKIP node no está instalado\n";
        return;
    }
    $untouched = ['prevented' => false, 'scrolledTo' => null, 'top' => false];
    assert_eq($untouched, sd_click('https://phet.colorado.edu/'), 'un enlace absoluto sigue su curso');
    assert_eq($untouched, sd_click('//cdn.example/x'), 'uno sin esquema (//) también');
    assert_eq($untouched, sd_click('mailto:a@example.test'), 'mailto: también');
    assert_eq($untouched, sd_click('#ecuaciones', ['target' => '_blank']), 'con target, lo decide el autor');
    assert_eq($untouched, sd_click('#ecuaciones', ['ctrl' => true]), 'Ctrl/Cmd+clic no se intercepta');
    assert_eq($untouched, sd_click('#ecuaciones', ['button' => 1]), 'ni el botón central');
    assert_eq($untouched, sd_click('#tab2', ['already' => true]), 'si el recurso ya lo gestionó (pestañas), no se pisa');
}

function test_el_script_va_al_final_y_no_rompe_el_html(): void
{
    $html = "<!doctype html>\n<html><head><title>x</title></head><body>hola</body></html>";
    $out  = iarepo_srcdoc($html);
    assert_true(str_starts_with($out, $html), 'el HTML del autor queda intacto y primero (el doctype sigue siendo lo primero)');
    assert_true(str_ends_with($out, '</script>'), 'el script va al final');
    $shim = iarepo_srcdoc_shim();
    assert_eq(1, substr_count(strtolower($shim), '</script'), 'un solo cierre de script: el suyo');
    assert_not_contains("'", $shim, 'sin comillas simples: viaja en un atributo y en un literal JS');
}

function test_toda_incrustacion_srcdoc_pasa_por_iarepo_srcdoc(): void
{
    $r = shell_exec('git -C ' . escapeshellarg(IAREPO_ROOT) . ' ls-files "*.php" 2>/dev/null');
    if (!is_string($r) || trim($r) === '') {
        echo "    SKIP git no está disponible\n";
        return;
    }
    $n = 0;
    foreach (array_filter(explode("\n", trim($r))) as $rel) {
        if (str_starts_with($rel, 'tests/') || $rel === 'shared/srcdoc.php')
            continue;
        $src = (string) file_get_contents(IAREPO_ROOT . '/' . $rel);
        // Atributos en PHP: srcdoc="<?= … ?…" siempre con iarepo_srcdoc().
        preg_match_all('/srcdoc="<\?=\s*([^"]*)"/', $src, $m);
        foreach ($m[1] as $expr) {
            $n++;
            assert_contains('iarepo_srcdoc(', $expr, "$rel: srcdoc sin iarepo_srcdoc() → sus enlaces #… sacan del recurso");
        }
        // Vistas previas en JS: frame.srcdoc = code… con el mismo script.
        preg_match_all('/\.srcdoc\s*=\s*code\b([^;\n]*)/', $src, $j);
        foreach ($j[1] as $tail) {
            $n++;
            assert_matches('/SRCDOC_SHIM|iarepo_srcdoc_shim\(\)/', $tail, "$rel: la vista previa también lleva el script de anclas");
        }
    }
    assert_eq(6, $n, 'ficha ×2, visor ×2, editor y admin (si cambia, revisa que la nueva pase por aquí)');
}
