<?php
/**
 * seed_math_manipulatives.php — Math manipulatives & tools
 *
 * Sources: Polypad, ToyTheater, Didax, Educaplus (math)
 * Run: php setup/seed_math_manipulatives.php
 */

require_once __DIR__ . '/../shared/db.php';
$db = getResourcesDB();
$inserted = 0; $skipped = 0;

function ins($db, $t, $d, $u, $type, $area, $topic, $lang, $level, $cat, $src, $srcUrl) {
    global $inserted, $skipped;
    $dup = $db->prepare("SELECT id FROM resources WHERE code_content = ? AND is_active = 1");
    $dup->execute([$u]);
    if ($dup->fetch()) { $skipped++; return; }
    $db->prepare("INSERT INTO resources (title,description,code_content,code_type,subject_area,topic_tag,lang,level,category_id,visibility,source_name,source_url,author_tenant_id,author_user_id,author_display_name,author_tenant_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,1,'iarepo','')")
       ->execute([$t,$d,$u,$type,$area,$topic,$lang,$level,$cat,'community',$src,$srcUrl]);
    $inserted++; echo "  ✅ $t\n";
}

$M = 2; // Mathematics

// ══ Polypad (URL — blocked for iframe, but amazing tool) ══
echo "── Polypad ──\n";
ins($db, 'Polypad: Patio de Juegos Matemático', 'Los mejores manipulativos virtuales del mundo — polígonos, fracciones, álgebra tiles, números, geometría, probabilidad y más. Herramienta todo-en-uno.', 'https://polypad.amplify.com/es/', 'url', 'Mathematics', 'Manipulatives', 'es', 'primary', $M, 'Amplify', 'https://polypad.amplify.com/');

// ══ ToyTheater (EMBEDDABLE — great for K-5) ══
echo "── ToyTheater ──\n";
$tt = 'https://toytheater.com/';
$toys = [
    // Fractions
    ['Fraction Bars', 'Interactive fraction bars — compare, add, subtract fractions visually.', 'fraction-bars/', 'Fractions'],
    ['Fraction Strips', 'Fraction strips for comparing and ordering fractions.', 'fraction-strips/', 'Fractions'],
    ['Fraction Circles', 'Fraction circles — visualize parts of a whole.', 'fraction-circles/', 'Fractions'],
    ['Equivalent Fractions (Circles)', 'Find equivalent fractions using circle models.', 'equivalent-fraction-circles/', 'Fractions'],
    ['Equivalent Fractions (Bars)', 'Find equivalent fractions using bar models.', 'equivalent-fraction-bars/', 'Fractions'],
    ['Decimal Strips', 'Decimal strips — connect fractions to decimals.', 'decimal-strips/', 'Decimals'],
    ['Percentage Strips', 'Percentage strips — fractions, decimals, and percents.', 'percentage-strips/', 'Percentages'],

    // Geometry
    ['Geoboard', 'Virtual geoboard — create shapes with rubber bands on a peg grid.', 'geoboard/', 'Geometry'],
    ['Tangram', 'Classic tangram puzzle — build shapes from 7 pieces.', 'tangram/', 'Geometry'],
    ['Pattern Blocks', 'Pattern blocks — tessellations, symmetry, area.', 'pattern-blocks/', 'Geometry'],
    ['Protractor', 'Virtual protractor — measure and draw angles.', 'protractor/', 'Angles'],
    ['Angle Tool', 'Interactive angle tool — explore acute, right, obtuse angles.', 'angle/', 'Angles'],
    ['Area & Perimeter Explorer', 'Explore area and perimeter of rectangles interactively.', 'area-perimeter-explorer/', 'Measurement'],
    ['Pentomino', 'Pentomino puzzle — fit all 12 pentominoes on a grid.', 'pentomino/', 'Geometry'],

    // Numbers & Place Value
    ['Base Ten Blocks', 'Virtual base ten blocks — ones, tens, hundreds, thousands.', 'base-ten-blocks/', 'Place value'],
    ['Place Value Disks', 'Place value disks — model addition, subtraction with regrouping.', 'place-value-disks/', 'Place value'],
    ['Place Value Chart', 'Interactive place value chart.', 'place-value-chart/', 'Place value'],
    ['Number Line', 'Interactive number line — drag, zoom, mark points.', 'number-line/', 'Number sense'],
    ['Number Line Jump', 'Number line with jump arcs for skip counting and addition.', 'number-line-jump/', 'Number sense'],
    ['Hundreds Chart', 'Interactive hundreds chart — color, count, find patterns.', 'hundreds-chart/', 'Number sense'],
    ['Abacus', 'Virtual abacus for counting and place value.', 'abacus/', 'Counting'],
    ['Rekenrek', 'Rekenrek (arithmetic rack) — build number sense.', 'rekenrek/', 'Number sense'],

    // Operations
    ['Array', 'Array builder — visualize multiplication as area.', 'array/', 'Multiplication'],
    ['Times Table', 'Interactive times table — drill multiplication facts.', 'times-table/', 'Multiplication'],
    ['Multiplication Chart', 'Full multiplication chart with highlighting.', 'multiplication-chart/', 'Multiplication'],
    ['Number Bonds', 'Number bonds — part-part-whole relationships.', 'number-bonds/', 'Addition'],
    ['Part-Part-Whole', 'Part-part-whole model for addition and subtraction.', 'part-part-whole/', 'Addition'],

    // Algebra
    ['Algebra Tiles', 'Virtual algebra tiles — model expressions, factor polynomials.', 'algebra-tiles/', 'Algebra'],
    ['Coordinate Graph', 'Coordinate plane — plot points, graph functions.', 'coordinate-graph/', 'Graphing'],

    // Probability & Data
    ['Spinner', 'Probability spinner — customize sections and spin.', 'spinner/', 'Probability'],
    ['Dice', 'Virtual dice — roll 1-6 dice, record results.', 'dice/', 'Probability'],
    ['Coin Flip', 'Virtual coin flip — heads or tails with counter.', 'coin-flip/', 'Probability'],
    ['Bar Graph Builder', 'Build bar graphs from data.', 'bar-graph/', 'Data'],
    ['Pie Chart Builder', 'Build pie charts interactively.', 'pie-chart/', 'Data'],

    // Measurement
    ['Interactive Clock', 'Interactive analog clock — tell time, set alarms.', 'clock/', 'Time'],
    ['Ruler (Centimeters)', 'Virtual ruler for measuring in centimeters.', 'ruler-centimeter/', 'Measurement'],
    ['Thermometer', 'Virtual thermometer — Celsius and Fahrenheit.', 'thermometer/', 'Measurement'],
    ['Scale (Balance)', 'Virtual balance scale — compare weights.', 'scale/', 'Measurement'],
];
foreach ($toys as [$t,$d,$path,$topic])
    ins($db, $t, $d, $tt.$path, 'url', 'Mathematics', $topic, 'en', 'primary', $M, 'Toy Theater', 'https://toytheater.com/');

// ══ Didax Apps (EMBEDDABLE) ══
echo "── Didax ──\n";
$didax = [
    ['Didax: Base Ten Blocks', 'Drag and snap base ten blocks — model numbers, addition, subtraction.', 'https://www.didax.com/apps/base-ten-blocks/', 'Place value'],
    ['Didax: Fraction Tiles', 'Interactive fraction tiles for comparing and computing fractions.', 'https://www.didax.com/apps/fraction-tiles/', 'Fractions'],
    ['Didax: Two Color Counters', 'Two-color counters for modeling addition, subtraction, integers.', 'https://www.didax.com/apps/two-color-counters/', 'Integers'],
    ['Didax: Pattern Blocks', 'Virtual pattern blocks for geometry and tessellation.', 'https://www.didax.com/apps/pattern-blocks/', 'Geometry'],
    ['Didax: Unifix Cubes', 'Virtual Unifix cubes — counting, patterns, grouping.', 'https://www.didax.com/apps/unifix-cubes/', 'Counting'],
    ['Didax: Algebra Tiles', 'Algebra tiles for modeling expressions and equations.', 'https://www.didax.com/apps/algebra-tiles/', 'Algebra'],
    ['Didax: Color Tiles', 'Color tiles for area, patterns, and sorting.', 'https://www.didax.com/apps/color-tiles/', 'Area'],
    ['Didax: Money', 'Virtual coins and bills — counting money, making change.', 'https://www.didax.com/apps/money/', 'Money'],
];
foreach ($didax as [$t,$d,$u,$topic])
    ins($db, $t, $d, $u, 'url', 'Mathematics', $topic, 'en', 'primary', $M, 'Didax', 'https://www.didax.com/');

// ══ Educaplus Math (URL — blocked) ══
echo "── Educaplus (Matemáticas) ──\n";
$ep = [
    ['Balanza Numérica', 'Equilibra la balanza con pesos — álgebra visual.', 'https://www.educaplus.org/game/balanza-numérica', 'Álgebra'],
    ['Ecuaciones Visuales', 'Resuelve ecuaciones con balanza interactiva.', 'https://www.educaplus.org/game/ecuaciones-visuales', 'Ecuaciones'],
    ['Fracciones Equivalentes', 'Encuentra fracciones equivalentes de forma visual.', 'https://www.educaplus.org/game/fracciones-equivalentes', 'Fracciones'],
    ['Perímetros', 'Calcula perímetros de figuras geométricas.', 'https://www.educaplus.org/game/perimetros', 'Geometría'],
    ['Áreas de Figuras Planas', 'Calcula áreas de figuras planas interactivamente.', 'https://www.educaplus.org/game/areas-de-figuras-planas', 'Geometría'],
    ['Coordenadas Cartesianas', 'Practica el plano cartesiano — localiza puntos.', 'https://www.educaplus.org/game/coordenadas-cartesianas', 'Coordenadas'],
    ['Simetría Axial', 'Construye figuras simétricas en una cuadrícula.', 'https://www.educaplus.org/game/simetria-axial', 'Geometría'],
    ['Estadística Descriptiva', 'Media, mediana, moda — estadística interactiva.', 'https://www.educaplus.org/game/estadistica-descriptiva', 'Estadística'],
    ['Ángulos Internos de Polígonos', 'Explora la suma de ángulos internos de polígonos.', 'https://www.educaplus.org/game/angulos-internos-poligonos', 'Geometría'],
    ['Teorema de Pitágoras', 'Demostración visual del teorema de Pitágoras.', 'https://www.educaplus.org/game/teorema-de-pitagoras', 'Geometría'],
];
foreach ($ep as [$t,$d,$u,$topic])
    ins($db, $t, $d, $u, 'url', 'Mathematics', $topic, 'es', 'secondary', $M, 'Educaplus', 'https://www.educaplus.org/');

echo "\n🎉 Done! Inserted: $inserted, Skipped (duplicates): $skipped\n";
echo "Total resources: " . $db->query("SELECT COUNT(*) FROM resources WHERE is_active = 1")->fetchColumn() . "\n";
