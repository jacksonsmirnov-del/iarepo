<?php
/**
 * seed_sciences_v2.php — Bulk insert Chemistry, Biology, and Math simulators
 *
 * Sources: ChemCollective, UNAM, PhET (missing chem/math), BioInteractive,
 *          VirtualBiologyLab, BioManBio, Concord Modeler, ChemReax
 *
 * Run: php setup/seed_sciences_v2.php
 */

require_once __DIR__ . '/../shared/db.php';
$db = getResourcesDB();

$inserted = 0;
$skipped = 0;

function insertResource($db, $title, $desc, $url, $type, $area, $topic, $lang, $level, $catId, $source, $sourceUrl) {
    global $inserted, $skipped;
    $dup = $db->prepare("SELECT id FROM resources WHERE code_content = ? AND is_active = 1");
    $dup->execute([$url]);
    if ($dup->fetch()) { $skipped++; return; }

    $db->prepare("INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag, lang, level, category_id, visibility, source_name, source_url, author_tenant_id, author_user_id, author_display_name, author_tenant_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,1,'iarepo','')")
       ->execute([$title, $desc, $url, $type, $area, $topic, $lang, $level, $catId, 'community', $source, $sourceUrl]);
    $inserted++;
    echo "  ✅ $title\n";
}

// Category IDs
$CHEM = 3;  // Chemistry
$BIO = 4;   // Biology
$PHYS = 1;  // Physics
$MATH = 2;  // Mathematics

// ══════════════════════════════════════════════════════════════
// ── CHEMISTRY ────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════
echo "══ CHEMISTRY ══\n";

// ChemCollective Virtual Labs (EMBEDDABLE)
echo "── ChemCollective ──\n";
$cc = [
    ['Virtual Lab: Titration', 'Perform acid-base titrations with indicators, pH meter, and concentration data.', 'https://chemcollective.org/vlab/vlab.php', 'Titration', 'en'],
    ['Iodide-Persulfate Clock Reaction', 'Virtual kinetics experiment — measure reaction rates of the iodine clock.', 'https://chemcollective.org/vlabdirect/load/kinetics/iodide_persulfate', 'Chemical kinetics', 'en'],
    ['Bleaching Food Dyes Kinetics', 'Study the kinetics of bleaching food dyes — reaction rates and orders.', 'https://chemcollective.org/chem/kinetics', 'Chemical kinetics', 'en'],
];
foreach ($cc as [$t,$d,$u,$topic,$lang])
    insertResource($db, $t, $d, $u, 'url', 'Chemistry', $topic, $lang, 'university', $CHEM, 'ChemCollective', 'https://chemcollective.org/');

// UNAM Interactive Objects (EMBEDDABLE)
echo "── UNAM ──\n";
$unam = [
    ['Oxígeno: Materia y Energía (UNAM)', 'Objeto interactivo de la UNAM sobre el oxígeno, materia, nomenclatura y reacciones.', 'http://www.objetos.unam.mx/quimica/oxigeno_mnm/', 'es'],
    ['Tabla Periódica Interactiva (UNAM)', 'Tabla periódica interactiva con propiedades, historia y configuración electrónica.', 'http://www.objetos.unam.mx/quimica/tablaPeriodicaA/', 'es'],
    ['Enlace Químico (UNAM)', 'Tipos de enlace químico: iónico, covalente, metálico.', 'http://www.objetos.unam.mx/quimica/enlaceQuimico/', 'es'],
    ['Reacciones Químicas (UNAM)', 'Tipos de reacciones: síntesis, descomposición, sustitución, combustión.', 'http://www.objetos.unam.mx/quimica/reaccionesQuimicas/', 'es'],
    ['Estequiometría (UNAM)', 'Cálculos estequiométricos: mol, masa molar, reactivo limitante.', 'http://www.objetos.unam.mx/quimica/estequiometria/', 'es'],
    ['Disoluciones (UNAM)', 'Concentración, dilución, propiedades coligativas.', 'http://www.objetos.unam.mx/quimica/disoluciones/', 'es'],
    ['Ácidos y Bases (UNAM)', 'pH, indicadores, teorías ácido-base.', 'http://www.objetos.unam.mx/quimica/acidosYBases/', 'es'],
];
foreach ($unam as [$t,$d,$u,$lang])
    insertResource($db, $t, $d, $u, 'url', 'Chemistry', 'General chemistry', $lang, 'secondary', $CHEM, 'UNAM', 'http://www.objetos.unam.mx/');

// PhET Chemistry (missing ones)
echo "── PhET Chemistry (missing) ──\n";
$phet = 'https://phet.colorado.edu/sims/html/';
$phetChem = [
    ['Isótopos y Masa Atómica', 'Explorar isótopos, masa atómica promedio y abundancia natural.', 'isotopes-and-atomic-mass/latest/isotopes-and-atomic-mass_es.html', 'es', 'Atomic structure'],
    ['Interacciones Atómicas', 'Fuerzas intermoleculares: Van der Waals, dipolo-dipolo.', 'atomic-interactions/latest/atomic-interactions_es.html', 'es', 'Intermolecular forces'],
    ['Polaridad Molecular', 'Distribución de carga y momento dipolar en moléculas.', 'molecule-polarity/latest/molecule-polarity_es.html', 'es', 'Molecular polarity'],
    ['Formas Moleculares', 'VSEPR — geometría molecular y ángulos de enlace.', 'molecule-shapes/latest/molecule-shapes_es.html', 'es', 'VSEPR'],
    ['Escala de pH', 'Medir pH de soluciones comunes con indicador universal.', 'ph-scale/latest/ph-scale_es.html', 'es', 'Acids and bases'],
    ['Transporte de Membrana', 'Ósmosis, difusión y transporte activo a través de membranas.', 'membrane-transport/latest/membrane-transport_es.html', 'es', 'Cell biology'],
];
foreach ($phetChem as [$t,$d,$f,$lang,$topic])
    insertResource($db, $t, $d, $phet.$f, 'url', 'Chemistry', $topic, $lang, 'secondary', $CHEM, 'PhET', 'https://phet.colorado.edu/');

// ChemReax
echo "── ChemReax ──\n";
insertResource($db, 'ChemReax: Chemical Reaction Simulator', 'Interactive chemical reaction balancer and simulator. Visualize reactants, products, and stoichiometry.', 'https://chemreax.com/', 'url', 'Chemistry', 'Chemical reactions', 'en', 'secondary', $CHEM, 'ChemReax', 'https://chemreax.com/');

// Concord Modeler
echo "── Concord Molecular Workbench ──\n";
insertResource($db, 'Molecular Workbench (Concord)', 'Molecular dynamics simulator — model atoms, molecules, gases, and phase transitions at the atomic level.', 'http://mw.concord.org/modeler/', 'url', 'Chemistry', 'Molecular dynamics', 'en', 'university', $CHEM, 'Concord Consortium', 'http://mw.concord.org/');

// ══════════════════════════════════════════════════════════════
// ── BIOLOGY ──────────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════
echo "\n══ BIOLOGY ══\n";

// HHMI BioInteractive (URL only — blocked for iframe)
echo "── HHMI BioInteractive ──\n";
$bio_hhmi = [
    ['Photosynthesis (BioInteractive)', 'Click & Learn: Explore the light reactions and Calvin cycle of photosynthesis.', 'https://www.biointeractive.org/classroom-resources/photosynthesis', 'Photosynthesis'],
    ['Cell Signaling (BioInteractive)', 'Interactive exploration of cell signaling pathways and signal transduction.', 'https://www.biointeractive.org/classroom-resources/cell-signaling', 'Cell signaling'],
    ['The Eukaryotic Cell (BioInteractive)', 'Explore organelles and structures of eukaryotic cells.', 'https://www.biointeractive.org/classroom-resources/eukaryotic-cell', 'Cell biology'],
    ['Human Evolution (BioInteractive)', 'Explore the evidence for human evolution — fossils, DNA, anatomy.', 'https://www.biointeractive.org/classroom-resources/human-evolution', 'Evolution'],
    ['Genetic Drift (BioInteractive)', 'Simulate genetic drift in small populations — founder effect and bottleneck.', 'https://www.biointeractive.org/classroom-resources/genetic-drift-and-natural-selection', 'Population genetics'],
    ['DNA Replication (BioInteractive)', 'Step-by-step animation of DNA replication: helicase, primase, polymerase.', 'https://www.biointeractive.org/classroom-resources/dna-replication', 'Molecular biology'],
    ['Immunology (BioInteractive)', 'How the immune system works — innate and adaptive immunity.', 'https://www.biointeractive.org/classroom-resources/immunology', 'Immunology'],
    ['Ecology: Coral Reef Ecosystem', 'Explore biodiversity, trophic levels, and ecological interactions in coral reefs.', 'https://www.biointeractive.org/classroom-resources/coral-reef-ecosystem', 'Ecology'],
];
foreach ($bio_hhmi as [$t,$d,$u,$topic])
    insertResource($db, $t, $d, $u, 'url', 'Biology', $topic, 'en', 'secondary', $BIO, 'HHMI BioInteractive', 'https://www.biointeractive.org/');

// Virtual Biology Lab (EMBEDDABLE)
echo "── Virtual Biology Lab ──\n";
$vbl = [
    ['Population Ecology Simulator', 'Model population growth: exponential, logistic, carrying capacity, density dependence.', 'https://virtualbiologylab.org/population-ecology/', 'Population ecology'],
    ['Community Ecology Simulator', 'Predator-prey dynamics, competition, and species interactions.', 'https://virtualbiologylab.org/community-ecology/', 'Community ecology'],
    ['Behavioral Ecology Simulator', 'Simulate animal behavior: foraging, mating strategies, territoriality.', 'https://virtualbiologylab.org/behavioral-ecology/', 'Behavioral ecology'],
    ['Conservation Ecology Simulator', 'Habitat fragmentation, species extinction, and conservation strategies.', 'https://virtualbiologylab.org/conservation-ecology/', 'Conservation'],
    ['Biodiversity Ecology Simulator', 'Species richness, diversity indices, and island biogeography.', 'https://virtualbiologylab.org/biodiversity-ecology/', 'Biodiversity'],
    ['Population Genetics Simulator', 'Hardy-Weinberg equilibrium, genetic drift, selection, migration.', 'https://virtualbiologylab.org/population-genetics/', 'Population genetics'],
    ['Natural Selection Simulator', 'Directional, stabilizing, and disruptive selection on phenotypes.', 'https://virtualbiologylab.org/selection/', 'Natural selection'],
    ['Membrane Transport Simulator', 'Osmosis, diffusion, active transport across cell membranes.', 'https://virtualbiologylab.org/membranes/', 'Cell biology'],
];
foreach ($vbl as [$t,$d,$u,$topic])
    insertResource($db, $t, $d, $u, 'url', 'Biology', $topic, 'en', 'university', $BIO, 'Virtual Biology Lab', 'https://virtualbiologylab.org/');

// BioMan Biology (URL — blocked for iframe)
echo "── BioMan Biology ──\n";
$bioman = [
    ['Cell Respiration Game', 'Interactive game about cellular respiration: glycolysis, Krebs cycle, ETC.', 'https://biomanbio.com/HTML5GamesandLabs/LifeChemgames/cellrespirationhtml5page.html', 'Cell respiration'],
    ['Photosynthesis Game', 'Learn the stages of photosynthesis in this interactive game.', 'https://biomanbio.com/HTML5GamesandLabs/LifeChemgames/photosynthesishtml5page.html', 'Photosynthesis'],
    ['DNA Game', 'Build DNA molecules — match nucleotide bases and build the double helix.', 'https://biomanbio.com/HTML5GamesandLabs/Genegames/dnahtml5page.html', 'DNA structure'],
    ['Cell Defense: Immune System', 'Tower defense game about the immune system — white blood cells vs pathogens.', 'https://biomanbio.com/HTML5GamesandLabs/Bodygames/celldefensehtml5page.html', 'Immunology'],
    ['Evolution Lab', 'Explore natural selection, adaptation, and evolutionary mechanisms.', 'https://biomanbio.com/HTML5GamesandLabs/Evogames/evolutionhtml5page.html', 'Evolution'],
    ['Mitosis Mover', 'Drag and drop stages of mitosis in the correct order.', 'https://biomanbio.com/HTML5GamesandLabs/Cellgames/mitosismoverhtml5page.html', 'Cell division'],
    ['Meiosis Game', 'Interactive game on meiosis — chromosome separation and crossing over.', 'https://biomanbio.com/HTML5GamesandLabs/Genegames/meiosishtml5page.html', 'Meiosis'],
    ['Ecology Game: Food Webs', 'Build food webs and explore energy flow through ecosystems.', 'https://biomanbio.com/HTML5GamesandLabs/Ecogames/foodwebhtml5page.html', 'Ecology'],
    ['Genetics: Punnett Square', 'Practice Punnett squares for monohybrid and dihybrid crosses.', 'https://biomanbio.com/HTML5GamesandLabs/Genegames/paborhtml5page.html', 'Genetics'],
    ['Body Systems Game', 'Learn about human body organ systems interactively.', 'https://biomanbio.com/HTML5GamesandLabs/Bodygames/bodysystemshtml5page.html', 'Anatomy'],
];
foreach ($bioman as [$t,$d,$u,$topic])
    insertResource($db, $t, $d, $u, 'url', 'Biology', $topic, 'en', 'secondary', $BIO, 'BioMan Biology', 'https://biomanbio.com/');

// PraxiLabs Virtual Labs
echo "── PraxiLabs ──\n";
insertResource($db, 'PraxiLabs: Virtual Science Labs', 'Collection of 3D virtual labs for biology, chemistry, and physics. Realistic lab simulations.', 'https://praxilabs.com/en/virtual-labs', 'url', 'Biology', 'Virtual labs', 'en', 'university', $BIO, 'PraxiLabs', 'https://praxilabs.com/');

// UNAM Biology
echo "── UNAM Biology ──\n";
$unamBio = [
    ['Célula Animal (UNAM)', 'Estructura y orgánulos de la célula animal interactiva.', 'http://www.objetos.unam.mx/biologia/celulaAnimal/', 'es'],
    ['Célula Vegetal (UNAM)', 'Estructura de la célula vegetal — cloroplastos, pared celular, vacuola.', 'http://www.objetos.unam.mx/biologia/celulaVegetal/', 'es'],
    ['Mitosis (UNAM)', 'Fases de la mitosis con animaciones interactivas.', 'http://www.objetos.unam.mx/biologia/mitosis/', 'es'],
    ['Meiosis (UNAM)', 'Fases de la meiosis y variabilidad genética.', 'http://www.objetos.unam.mx/biologia/meiosis/', 'es'],
    ['Fotosíntesis (UNAM)', 'Fase luminosa y ciclo de Calvin — fotosíntesis interactiva.', 'http://www.objetos.unam.mx/biologia/fotosintesis/', 'es'],
    ['Respiración Celular (UNAM)', 'Glucólisis, ciclo de Krebs y cadena de transporte de electrones.', 'http://www.objetos.unam.mx/biologia/respiracion/', 'es'],
];
foreach ($unamBio as [$t,$d,$u,$lang])
    insertResource($db, $t, $d, $u, 'url', 'Biology', 'Cell biology', $lang, 'secondary', $BIO, 'UNAM', 'http://www.objetos.unam.mx/');

// ══════════════════════════════════════════════════════════════
// ── MATHEMATICS (filling gaps) ──────────────────────────────
// ══════════════════════════════════════════════════════════════
echo "\n══ MATHEMATICS ══\n";

// PhET Math sims we might be missing
echo "── PhET Math (missing) ──\n";
$phetMath = [
    ['Number Line: Distance', 'Visualize absolute value and distance on a number line.', 'number-line-distance/latest/number-line-distance_es.html', 'es', 'Number sense'],
    ['Probability (Plinko)', 'Probability and statistics with Galton board (Plinko).', 'plinko-probability/latest/plinko-probability_es.html', 'es', 'Probability'],
    ['Regression (Least Squares)', 'Fit lines and curves to data — least squares regression.', 'least-squares-regression/latest/least-squares-regression_es.html', 'es', 'Statistics'],
    ['Calculus Grapher', 'Graph functions and explore derivatives and integrals visually.', 'calculus-grapher/latest/calculus-grapher_es.html', 'es', 'Calculus'],
    ['Trigonometry Tour', 'Unit circle, sine, cosine, tangent — interactive trig explorer.', 'trig-tour/latest/trig-tour_es.html', 'es', 'Trigonometry'],
];
foreach ($phetMath as [$t,$d,$f,$lang,$topic])
    insertResource($db, $t, $d, $phet.$f, 'url', 'Mathematics', $topic, $lang, 'secondary', $MATH, 'PhET', 'https://phet.colorado.edu/');

// GeoGebra classic tools
echo "── GeoGebra ──\n";
$gg = [
    ['GeoGebra: Graphing Calculator', 'Free online graphing calculator — plot functions, create sliders, animate.', 'https://www.geogebra.org/graphing', 'en', 'Graphing'],
    ['GeoGebra: 3D Calculator', '3D graphing calculator — plot surfaces, vectors, and 3D geometry.', 'https://www.geogebra.org/3d', 'en', '3D geometry'],
    ['GeoGebra: Geometry', 'Dynamic geometry — constructions, transformations, and proofs.', 'https://www.geogebra.org/geometry', 'en', 'Geometry'],
    ['GeoGebra: CAS Calculator', 'Computer algebra system — symbolic math, equations, and calculus.', 'https://www.geogebra.org/cas', 'en', 'Algebra'],
    ['GeoGebra: Probability Calculator', 'Normal, binomial, Poisson distributions — interactive probability.', 'https://www.geogebra.org/probability', 'en', 'Probability'],
    ['GeoGebra: Spreadsheet', 'Spreadsheet with charts and statistical analysis tools.', 'https://www.geogebra.org/spreadsheet', 'en', 'Statistics'],
];
foreach ($gg as [$t,$d,$u,$lang,$topic])
    insertResource($db, $t, $d, $u, 'url', 'Mathematics', $topic, $lang, 'secondary', $MATH, 'GeoGebra', 'https://www.geogebra.org/');

// Desmos
echo "── Desmos ──\n";
$desmos = [
    ['Desmos: Graphing Calculator', 'Powerful online graphing calculator — functions, inequalities, statistics, sliders.', 'https://www.desmos.com/calculator', 'en', 'Graphing'],
    ['Desmos: Scientific Calculator', 'Free scientific calculator with history and variable storage.', 'https://www.desmos.com/scientific', 'en', 'Arithmetic'],
    ['Desmos: Matrix Calculator', 'Matrix operations: multiplication, determinants, eigenvalues, inverses.', 'https://www.desmos.com/matrix', 'en', 'Linear algebra'],
    ['Desmos: Geometry Tool', 'Dynamic geometry — compass, straightedge, transformations.', 'https://www.desmos.com/geometry', 'en', 'Geometry'],
];
foreach ($desmos as [$t,$d,$u,$lang,$topic])
    insertResource($db, $t, $d, $u, 'url', 'Mathematics', $topic, $lang, 'secondary', $MATH, 'Desmos', 'https://www.desmos.com/');

// Wolfram
echo "── Wolfram Alpha ──\n";
insertResource($db, 'Wolfram Alpha: Computational Knowledge', 'Computational knowledge engine — solve math, plot functions, explore data.', 'https://www.wolframalpha.com/', 'url', 'Mathematics', 'Computation', 'en', 'secondary', $MATH, 'Wolfram', 'https://www.wolframalpha.com/');

echo "\n🎉 Done! Inserted: $inserted, Skipped (duplicates): $skipped\n";
echo "Total resources: " . $db->query("SELECT COUNT(*) FROM resources WHERE is_active = 1")->fetchColumn() . "\n";
