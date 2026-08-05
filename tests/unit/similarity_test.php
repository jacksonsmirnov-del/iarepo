<?php
// ================================================================
// tests/unit/similarity_test.php — shared/similarity.php
//
// Es el detector de duplicados que decide si un recurso que sube un
// profesor es plagio de otro. Sus tres funciones de texto son puras y
// el fichero no hace ningún require, así que se cargan sin problema.
// (findSimilarResources() necesita PDO: es cosa de la suite de
// integración, aquí no se toca.)
//
// Lo que se protege: un falso positivo bloquea a un profesor legítimo y
// un falso negativo deja pasar una copia. El umbral vive en el llamador,
// pero la puntuación sale de aquí.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

require_once IAREPO_ROOT . '/shared/similarity.php';

/** Texto suficientemente largo (jaccardSimilarity ignora lo de <50 chars). */
function sim_texto(string $semilla = ''): string
{
    return 'La energía cinética de un cuerpo es el trabajo necesario para acelerarlo '
         . 'desde el reposo hasta una velocidad dada, y depende de la masa. ' . $semilla;
}

// ================================================================
// normalizeContent
// ================================================================

function test_normalize_content_quita_etiquetas_y_normaliza(): void
{
    $casos = [
        'quita etiquetas'      => ['<p>hola mundo</p>',        'hola mundo'],
        'minúsculas'           => ['HOLA MUNDO',               'hola mundo'],
        'colapsa espacios'     => ["hola \n\t  mundo",         'hola mundo'],
        'recorta'              => ['   hola   ',               'hola'],
        'quita comentario /**/'=> ['uno /* fuera */ dos',      'uno dos'],
        'vacío'                => ['',                         ''],
        'solo etiquetas'       => ['<br><hr>',                 ''],
    ];
    foreach ($casos as $label => [$in, $esperado]) {
        subtest($label, static function () use ($in, $esperado): void {
            assert_eq($esperado, normalizeContent($in));
        });
    }
}

/** El ruido estructural se elimina para que no infle el parecido. */
function test_normalize_content_quita_palabras_de_andamiaje(): void
{
    foreach (['function', 'const', 'return', 'div', 'span', 'class', 'script', 'style'] as $ruido) {
        subtest($ruido, static function () use ($ruido): void {
            assert_eq('', trim(normalizeContent($ruido)), "'{$ruido}' debería considerarse andamiaje");
        });
    }
    // Pero solo como PALABRA completa: 'classification' no debe mutilarse.
    assert_contains('classification', normalizeContent('classification'));
}

/**
 * CARACTERIZACIÓN de un efecto colateral real: el limpiador de
 * comentarios de línea usa la regex `//[^\n]*`, que no distingue el
 * comentario de una URL. Cualquier http:// se lleva por delante TODO lo
 * que quede en esa línea. Para comparar código es tolerable (el ruido se
 * va por igual en ambos textos), pero conviene tenerlo fijado: si un día
 * se usa esta función para otra cosa, hay que saberlo. Ver riesgos.
 */
function test_caracterizacion_las_urls_se_comen_el_resto_de_la_linea(): void
{
    assert_eq('ver https:', normalizeContent('ver https://ejemplo.com/pagina y mucho mas texto'));
    assert_eq('antes https: despues', normalizeContent("antes https://x.com/y\ndespues"));
}

// ================================================================
// createShingles
// ================================================================

function test_create_shingles(): void
{
    assert_eq(['a b c', 'b c d'], array_values(createShingles('a b c d', 3)));
    assert_eq(['a b', 'b c', 'c d'], array_values(createShingles('a b c d', 2)));

    // Menos palabras que el tamaño de shingle: se usa el texto entero.
    assert_eq(['a b'], createShingles('a b', 3));
    assert_eq([''], createShingles('', 3));

    // n palabras ⇒ n - k + 1 shingles.
    $texto = implode(' ', array_map(static fn(int $i): string => 'w' . $i, range(1, 20)));
    assert_count(18, createShingles($texto, 3));
    assert_count(20, createShingles($texto, 1));

    // Repeticiones: se deduplica.
    assert_count(1, createShingles('a a a a a a', 3));
}

// ================================================================
// jaccardSimilarity
// ================================================================

function test_jaccard_textos_identicos_dan_uno(): void
{
    $t = sim_texto();
    assert_true(strlen(normalizeContent($t)) >= 50, 'el texto de prueba debe superar el umbral de longitud');
    assert_eq(1.0, jaccardSimilarity($t, $t));
}

function test_jaccard_es_simetrico(): void
{
    $a = sim_texto('Un ejemplo con masa y velocidad al cuadrado dividido entre dos.');
    $b = sim_texto('Otro ejemplo totalmente distinto sobre fotosíntesis y cloroplastos.');
    assert_eq(jaccardSimilarity($a, $b), jaccardSimilarity($b, $a));
}

function test_jaccard_textos_sin_relacion_dan_casi_cero(): void
{
    $a = 'La fotosíntesis convierte la luz solar en energía química dentro de los cloroplastos '
       . 'de las células vegetales, produciendo glucosa y oxígeno como resultado.';
    $b = 'El teorema de Pitágoras relaciona los catetos y la hipotenusa de un triángulo '
       . 'rectángulo mediante la suma de los cuadrados de sus lados menores.';

    $score = jaccardSimilarity($a, $b);
    assert_true($score < 0.05, 'textos sin relación no pueden parecerse: ' . $score);
    assert_true($score >= 0.0, 'la puntuación nunca es negativa');
}

function test_jaccard_detecta_una_copia_con_retoques(): void
{
    $original = 'La energía cinética de un cuerpo es el trabajo necesario para acelerarlo desde '
              . 'el reposo hasta una velocidad determinada. Depende de la masa y de la velocidad '
              . 'al cuadrado, y se mide en julios dentro del sistema internacional de unidades.';
    $copia    = str_replace('determinada', 'concreta', $original) . ' Añadido final.';

    $score = jaccardSimilarity($original, $copia);
    assert_true($score > 0.5, 'una copia con retoques debe puntuar alto, no ' . $score);
    assert_true($score < 1.0, 'pero no puede dar exactamente 1.0 si el texto cambió');
}

/** El HTML no puede inflar el parecido: dos páginas distintas con el mismo esqueleto. */
function test_jaccard_el_esqueleto_html_no_infla_el_parecido(): void
{
    $marco = '<html><head><meta charset="utf-8"></head><body><div class="wrap"><span>';
    $a = $marco . 'La fotosíntesis ocurre en los cloroplastos de las células vegetales expuestas a la luz.';
    $b = $marco . 'El teorema de Pitágoras relaciona catetos e hipotenusa en un triángulo rectángulo.';

    assert_true(jaccardSimilarity($a, $b) < 0.3, 'el marco HTML común no debe disparar el detector');
}

/** Textos cortos: sin datos suficientes se devuelve 0.0, no un falso positivo. */
function test_jaccard_devuelve_cero_con_textos_cortos(): void
{
    $casos = [
        'ambos vacíos'    => ['', ''],
        'uno vacío'       => [sim_texto(), ''],
        'ambos cortos'    => ['hola', 'hola'],
        'idénticos cortos'=> ['<p>abc</p>', '<p>abc</p>'],
        'solo espacios'   => ['     ', '     '],
    ];
    foreach ($casos as $label => [$a, $b]) {
        subtest($label, static function () use ($a, $b): void {
            assert_eq(0.0, jaccardSimilarity($a, $b), 'con poco texto no se puede afirmar nada');
        });
    }
}

function test_jaccard_siempre_devuelve_un_float_entre_cero_y_uno(): void
{
    $muestras = [
        '', ' ', 'a', str_repeat('x', 5000), sim_texto(), '<b>' . sim_texto() . '</b>',
        "\x00\x01" . sim_texto(), '🙂 ' . sim_texto(), "\xff\xfe" . sim_texto(),
        '/* ' . sim_texto() . ' */', '// ' . sim_texto(),
    ];
    foreach ($muestras as $i => $a) {
        foreach ($muestras as $j => $b) {
            subtest("#{$i}×#{$j}", static function () use ($a, $b): void {
                $s = jaccardSimilarity($a, $b);
                assert_true(is_float($s), 'debe ser float, no ' . get_debug_type($s));
                assert_true($s >= 0.0 && $s <= 1.0, 'fuera de rango: ' . $s);
            });
        }
    }
}

function test_las_funciones_de_texto_no_imprimen_nada(): void
{
    assert_no_output(static function (): void {
        normalizeContent('<p>hola</p>');
        createShingles('a b c d', 3);
        jaccardSimilarity(sim_texto(), sim_texto('x'));
    });
}
