<?php
// ================================================================
// tests/integration/api_lang_test.php — ?lang= en la API es un FILTRO
//
// ── POR QUÉ EXISTE ────────────────────────────────────────────
// En api/resources.php, '?lang=' filtra el catálogo por el idioma del
// RECURSO (lo mandan así la portada —chips «Idioma del recurso»— y Campus).
// En las páginas, '?lang=' es el IDIOMA DE LA INTERFAZ, y shared/i18n.php
// lo persiste en una cookie `lang` de un año.
//
// Mientras la API no tradujo nada, la ambigüedad no se notaba. Desde el
// rediseño 2026-09 la API devuelve etiquetas ya traducidas con t()
// (iarepo_with_labels, categories[].label), y lang() leía el filtro como
// idioma de interfaz: pulsar «Inglés» en los filtros de la portada devolvía
// Set-Cookie lang=en (la web entera pasaba a inglés en la siguiente carga) y
// las etiquetas llegaban en inglés a una portada en español. Nada fallaba:
// 200, JSON válido, y la web cambiaba de idioma sola.
//
// Aquí se prueba por HTTP, contra el sitio levantado (site_server.php):
//   · el filtro filtra;
//   · la API NUNCA planta la cookie de idioma;
//   · las etiquetas siguen la cookie (o Accept-Language), no el filtro;
//   · y, para que el test no pase por vacío, una PÁGINA con ?lang= sí la
//     planta (el selector de idioma de la interfaz sigue funcionando).
// ================================================================

require_once __DIR__ . '/site_server.php';

/** GET a la API como anónimo, con la cookie $cookie. [estado, cabeceras, json]. */
function it_lang_api(string $query, ?string $cookie): array
{
    $base = it_render_state()['base'];
    [$code, $hdrs, $body] = it_render_request('GET', "$base/api/resources.php?$query", $cookie, []);
    $j = json_decode($body, true);
    return [$code, $hdrs, is_array($j) ? $j : []];
}

/** ¿Alguna cabecera Set-Cookie de idioma? */
function it_lang_sets_cookie(array $hdrs): bool
{
    foreach ($hdrs as $h)
        if (preg_match('/^Set-Cookie:\s*lang=/i', $h))
            return true;
    return false;
}

function it_lang_ready(): bool
{
    if (it_render_server() === null) {
        echo '    SKIP api_lang: ' . (it_render_state()['skip'] ?? '') . "\n";
        return false;
    }
    return true;
}

function test_el_filtro_de_idioma_de_la_api_no_cambia_el_idioma_de_la_web(): void
{
    if (!it_lang_ready())
        return;

    // Sin cookie y con Accept-Language: es (lo que manda it_render_request).
    [$code, $hdrs, $j] = it_lang_api('lang=en&limit=100', null);
    it_eq(200, $code, 'la API responde al filtro ?lang=en');
    $rows = $j['resources'] ?? [];
    it_true(count($rows) > 0, 'el corpus tiene recursos en inglés (si no, el test no prueba nada)');
    it_eq(['en'], array_values(array_unique(array_column($rows, 'lang'))), 'el filtro filtra: solo recursos en inglés');
    it_true(!it_lang_sets_cookie($hdrs), 'la API NO planta la cookie de idioma de la interfaz');

    // Las etiquetas siguen el idioma de quien mira (español), no el filtro.
    it_eq(['En inglés'], array_values(array_unique(array_column($rows, 'lang_label'))),
        'lang_label en el idioma de la interfaz (español), no en el del filtro');
    // level_label lleva las edades («Secundaria · 12–16 años»): basta el principio.
    $levels = array_values(array_unique(array_column($rows, 'level_label')));
    it_true($levels !== [] && preg_grep('/^(Primaria|Secundaria)\b/u', $levels) === $levels,
        'level_label en español: ' . implode(', ', $levels));
    $cats = array_column($j['categories'] ?? [], 'label');
    it_true(in_array('Física', $cats, true), 'categories[].label en español');
}

function test_las_etiquetas_de_la_api_siguen_la_cookie_y_no_el_filtro(): void
{
    if (!it_lang_ready())
        return;

    // Interfaz en inglés (cookie) filtrando recursos en español.
    [$code, $hdrs, $j] = it_lang_api('lang=es&limit=100', 'lang=en');
    it_eq(200, $code, 'la API responde con cookie lang=en y filtro ?lang=es');
    $rows = $j['resources'] ?? [];
    it_true(count($rows) > 0, 'el corpus tiene recursos en español');
    it_eq(['es'], array_values(array_unique(array_column($rows, 'lang'))), 'el filtro filtra: solo recursos en español');
    it_eq(['In Spanish'], array_values(array_unique(array_column($rows, 'lang_label'))),
        'lang_label en inglés: manda la cookie de interfaz, no el filtro');
    it_true(!it_lang_sets_cookie($hdrs), 'la API tampoco reescribe la cookie');

    // La ficha por ?id= sigue la misma regla.
    [$code, $hdrs, $j] = it_lang_api('id=1000&lang=es', 'lang=en');
    it_eq(200, $code, '?id= con cookie lang=en');
    it_eq('In Spanish', $j['resource']['lang_label'] ?? null, '?id= etiqueta en el idioma de la cookie');
    it_true(!it_lang_sets_cookie($hdrs), '?id= no planta la cookie');
}

function test_una_pagina_con_lang_si_cambia_el_idioma_de_la_interfaz(): void
{
    if (!it_lang_ready())
        return;

    // Contraste: en una PÁGINA, ?lang= es el selector de idioma y DEBE
    // persistir. Si esto dejara de cumplirse, los dos tests de arriba
    // pasarían aunque nadie plantara nunca la cookie.
    [$code, $hdrs] = it_render_get(it_render_state()['base'] . '/404.php?lang=en');
    it_true(in_array($code, [200, 404], true), "el 404 responde ($code)");
    it_true(it_lang_sets_cookie($hdrs), 'una página con ?lang=en planta la cookie de idioma');
}
