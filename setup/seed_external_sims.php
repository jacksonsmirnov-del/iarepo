<?php
/**
 * seed_external_sims.php — Bulk insert simulators from external sources
 *
 * Sources: Walter Fendt, QuVis (St Andrews), CK-12, Educaplus, Fisicalab
 * Run: php setup/seed_external_sims.php
 */

require_once __DIR__ . '/../shared/db.php';
$db = getResourcesDB();

// ── Walter Fendt (HTML5 Physics — EMBEDDABLE in iframe) ──
$wf = 'https://www.walter-fendt.de/html5/phes/';
$fendt = [
    // Mechanics — Spanish
    ['Aceleración Uniforme', 'Simulación de movimiento con aceleración constante. Gráficas x-t, v-t, a-t.', 'acceleration_es.htm', 'es', 'Mechanics'],
    ['Equilibrio de Fuerzas', 'Simulación del equilibrio de fuerzas concurrentes.', 'equilibriumforces_es.htm', 'es', 'Mechanics'],
    ['Resultante de Fuerzas', 'Composición vectorial de fuerzas. Suma de vectores gráfica.', 'resultant_es.htm', 'es', 'Mechanics'],
    ['Descomposición de Fuerzas', 'Descomponer una fuerza en componentes ortogonales.', 'forceresolution_es.htm', 'es', 'Mechanics'],
    ['Sistema de Poleas', 'Simulación de poleas fijas y móviles. Ventaja mecánica.', 'pulleysystem_es.htm', 'es', 'Mechanics'],
    ['La Palanca', 'Ley de la palanca y equilibrio de momentos.', 'lever_es.htm', 'es', 'Mechanics'],
    ['Plano Inclinado', 'Descomposición de fuerzas en un plano inclinado con fricción.', 'inclinedplane_es.htm', 'es', 'Mechanics'],
    ['Segunda Ley de Newton', 'F=ma — relación entre fuerza, masa y aceleración.', 'newtonlaw2_es.htm', 'es', 'Mechanics'],
    ['Movimiento de Proyectiles', 'Tiro parabólico con resistencia del aire opcional.', 'projectile_es.htm', 'es', 'Mechanics'],
    ['Colisiones', 'Choques elásticos e inelásticos en 1D. Conservación de momento.', 'collision_es.htm', 'es', 'Mechanics'],
    ['Cuna de Newton', 'Simulación de la cuna de Newton (péndulo de Newton).', 'newtoncradle_es.htm', 'es', 'Mechanics'],
    ['Movimiento Circular', 'MCU — velocidad angular, aceleración centrípeta, fuerza centrípeta.', 'circularmotion_es.htm', 'es', 'Mechanics'],
    ['El Carrusel', 'Fuerzas en un carrusel: centrípeta, normal, peso.', 'carousel_es.htm', 'es', 'Mechanics'],
    ['Rizo (Looping)', 'Física de un rizo vertical: velocidad mínima, fuerzas.', 'looping_es.htm', 'es', 'Mechanics'],
    ['Gravitación', 'Ley de gravitación universal de Newton. Órbitas y campos.', 'gravity_es.htm', 'es', 'Mechanics'],
    ['Primera Ley de Kepler', 'Órbitas elípticas — visualización de la 1ª ley de Kepler.', 'keplerlaw1_es.htm', 'es', 'Mechanics'],
    ['Segunda Ley de Kepler', 'Ley de áreas — la línea Sol-planeta barre áreas iguales.', 'keplerlaw2_es.htm', 'es', 'Mechanics'],
    ['Presión Hidrostática', 'Presión en fluidos: p = ρgh.', 'hydrostaticpressure_es.htm', 'es', 'Mechanics'],
    ['Fuerza de Empuje (Arquímedes)', 'Principio de Arquímedes — flotación y empuje.', 'buoyantforce_es.htm', 'es', 'Mechanics'],

    // Oscillations & Waves — Spanish
    ['Péndulo Simple', 'Oscilación de un péndulo simple. Periodo, energía, amortiguamiento.', 'pendulum_es.htm', 'es', 'Waves'],
    ['Péndulo Elástico', 'Oscilación de masa-resorte. MAS, energía, amortiguamiento.', 'springpendulum_es.htm', 'es', 'Waves'],
    ['Péndulos Acoplados', 'Dos péndulos acoplados — modos normales de oscilación.', 'coupledpendula_es.htm', 'es', 'Waves'],
    ['Resonancia', 'Oscilaciones forzadas y resonancia. Amplitud vs frecuencia.', 'resonance_es.htm', 'es', 'Waves'],
    ['Batidos', 'Interferencia de dos ondas de frecuencias similares — pulsaciones.', 'beats_es.htm', 'es', 'Waves'],
    ['Ondas Estacionarias (Reflexión)', 'Ondas estacionarias en una cuerda — nodos y antinodos.', 'standingwavereflection_es.htm', 'es', 'Waves'],
    ['Ondas Estacionarias Longitudinales', 'Ondas estacionarias en tubos — abiertos y cerrados.', 'standinglongitudinalwaves_es.htm', 'es', 'Waves'],
    ['Interferencia de Ondas', 'Interferencia constructiva y destructiva de dos fuentes.', 'interference_es.htm', 'es', 'Waves'],
    ['Efecto Doppler', 'Cambio de frecuencia por movimiento relativo fuente-observador.', 'dopplereffect_es.htm', 'es', 'Waves'],

    // Electromagnetism — Spanish
    ['Campo Magnético (Imán)', 'Líneas de campo magnético de un imán de barra.', 'magneticfieldbar_es.htm', 'es', 'Electromagnetism'],
    ['Campo Magnético (Cable)', 'Campo magnético alrededor de un conductor recto.', 'magneticfieldwire_es.htm', 'es', 'Electromagnetism'],
    ['Fuerza de Lorentz', 'Fuerza magnética sobre una carga en movimiento.', 'lorentzforce_es.htm', 'es', 'Electromagnetism'],
    ['Motor Eléctrico', 'Funcionamiento de un motor eléctrico de corriente continua.', 'electricmotor_es.htm', 'es', 'Electromagnetism'],
    ['Generador Eléctrico', 'Funcionamiento de un generador de corriente alterna.', 'generator_es.htm', 'es', 'Electromagnetism'],
    ['Ley de Ohm', 'V = IR — simulación interactiva de circuitos.', 'ohmslaw_es.htm', 'es', 'Electromagnetism'],
    ['Resistencias (Serie/Paralelo)', 'Combinación de resistencias en serie y paralelo.', 'combinationresistors_es.htm', 'es', 'Electromagnetism'],
    ['Potenciómetro', 'Divisor de tensión — potenciómetro.', 'potentiometer_es.htm', 'es', 'Electromagnetism'],
    ['Puente de Wheatstone', 'Medición de resistencias con puente de Wheatstone.', 'wheatstonebridge_es.htm', 'es', 'Electromagnetism'],
    ['Circuitos de CA', 'Corriente alterna con R, L, C. Impedancia, fase.', 'accircuits_es.htm', 'es', 'Electromagnetism'],
    ['Combinación RLC', 'Circuito RLC en serie — resonancia eléctrica.', 'combinationrlc_es.htm', 'es', 'Electromagnetism'],
    ['Circuito Oscilante', 'Oscilaciones electromagnéticas LC.', 'oscillatingcircuit_es.htm', 'es', 'Electromagnetism'],
    ['Onda Electromagnética', 'Propagación de una onda EM — campos E y B perpendiculares.', 'electromagneticwave_es.htm', 'es', 'Electromagnetism'],

    // Optics — Spanish
    ['Refracción (Ley de Snell)', 'Ley de Snell — refracción en la interfaz de dos medios.', 'refraction_es.htm', 'es', 'Optics'],
    ['Refracción (Huygens)', 'Principio de Huygens aplicado a la refracción.', 'refractionhuygens_es.htm', 'es', 'Optics'],
    ['Imagen en Lente Convergente', 'Formación de imágenes con lentes convergentes.', 'imageconverginglens_es.htm', 'es', 'Optics'],
    ['Telescopio Refractor', 'Funcionamiento óptico de un telescopio refractor.', 'refractor_es.htm', 'es', 'Optics'],
    ['Doble Rendija', 'Experimento de Young — patrón de interferencia.', 'doubleslit_es.htm', 'es', 'Optics'],
    ['Rendija Simple', 'Difracción por una rendija — patrón de Fraunhofer.', 'singleslit_es.htm', 'es', 'Optics'],

    // Thermodynamics — Spanish
    ['Gas Ideal 3D', 'Simulación 3D de partículas de gas ideal. Distribución de velocidades.', 'gas3d_es.htm', 'es', 'Thermodynamics'],
    ['Procesos Termodinámicos', 'Procesos isotérmicos, adiabáticos, isobáricos, isocóricos.', 'gasprocesses_es.htm', 'es', 'Thermodynamics'],
    ['Ciclo de Carnot', 'Motor térmico ideal — ciclo de Carnot con diagramas PV.', 'carnotcycle_es.htm', 'es', 'Thermodynamics'],

    // Modern Physics — Spanish
    ['Efecto Fotoeléctrico', 'Efecto fotoeléctrico — energía de fotones y electrones.', 'photoeffect_es.htm', 'es', 'Modern physics'],
    ['Modelo de Bohr', 'Modelo atómico de Bohr — niveles de energía y transiciones.', 'bohrmodel_es.htm', 'es', 'Modern physics'],
    ['Difracción de Bragg', 'Reflexión de Bragg — difracción de rayos X en cristales.', 'braggreflection_es.htm', 'es', 'Modern physics'],
    ['Dispersión de Rutherford', 'Experimento de Rutherford — dispersión de partículas alfa.', 'rutherfordscattering_es.htm', 'es', 'Modern physics'],
    ['Ley de Desintegración', 'Desintegración radiactiva — vida media y constante de decaimiento.', 'lawdecay_es.htm', 'es', 'Modern physics'],
    ['Cadenas de Desintegración', 'Series radiactivas — cadenas de desintegración nuclear.', 'decaychains_es.htm', 'es', 'Modern physics'],
    ['Dilatación del Tiempo', 'Relatividad especial — dilatación temporal.', 'timedilation_es.htm', 'es', 'Relativity'],
];

// ── QuVis Quantum Mechanics (St Andrews — EMBEDDABLE) ──
$qv = 'https://www.st-andrews.ac.uk/physics/quvis/simulations_html5/sims/';
$quvis = [
    ['Single Photon Lab', 'Explore single-photon experiments: beam splitters, detectors, and quantum measurement.', 'SinglePhotonLab/SinglePhotonLab.html', 'Quantum mechanics'],
    ['Quantum Bomb Detection', 'Elitzur-Vaidman bomb tester — interaction-free measurement.', 'QuantumBombGame/Quantum_bomb.html', 'Quantum mechanics'],
    ['Eigenvectors and Eigenvalues', 'Interactive visualization of eigenvectors and eigenvalues for spin operators.', 'EigenvectorsAndEigenvalues/Eigenvectors_and_Eigenvalues.html', 'Linear algebra'],
    ['Mach-Zehnder Interferometer', 'Single-photon interference in a Mach-Zehnder interferometer.', 'Mach-Zehnder-Interferometer/Mach_Zehnder_Interferometer.html', 'Quantum mechanics'],
    ['Entanglement', 'Explore quantum entanglement and Bell state measurements.', 'entanglement/entanglement.html', 'Quantum mechanics'],
    ['Quantum Cryptography (BBM92)', 'BBM92 quantum key distribution protocol simulation.', 'cryptography/Quantum_Cryptography.html', 'Quantum mechanics'],
    ['Bloch Sphere', 'Interactive Bloch sphere — visualize qubit states on the Bloch sphere.', 'blochsphere/blochsphere.html', 'Quantum mechanics'],
    ['Quantum Eraser', 'Quantum eraser experiment — which-path information and interference.', 'QuantumEraser/QuantumEraser.html', 'Quantum mechanics'],
    ['Quantum Oscillator', 'Quantum harmonic oscillator — energy levels and wavefunctions.', 'QuantumOscillator/oscillator2.html', 'Quantum mechanics'],
    ['1D Particle in a Box', 'Infinite square well — energy eigenvalues and wavefunctions.', 'infwell1d/infwell1d.html', 'Quantum mechanics'],
    ['Gaussian Wave Packet', 'Time evolution of a Gaussian wave packet — dispersion and group velocity.', 'gaussian/gaussian.html', 'Quantum mechanics'],
    ['Superposition States', 'Superposition states in an infinite well — time evolution.', 'SuperpositionStates/SuperpositionStates.html', 'Quantum mechanics'],
    ['Spin Precession', 'Spin precession in a magnetic field — Larmor precession.', 'spin-precession/spin-precession.html', 'Quantum mechanics'],
    ['Fermions and Bosons', 'Exchange symmetry — fermionic vs bosonic wavefunctions.', 'FermionsBosons/FermionsBosons.html', 'Quantum mechanics'],
    ['Density Matrix', 'Density matrix for mixed and pure states.', 'DensityMatrix/DensityMatrix.html', 'Quantum mechanics'],
    ['Probability Current', 'Probability current density in quantum tunneling.', 'ProbabilityCurrent/ProbabilityCurrent.html', 'Quantum mechanics'],
    ['Delayed Choice Experiments', 'Wheeler delayed choice experiment — wave-particle duality.', 'DelayedChoice/DelayedChoice.html', 'Quantum mechanics'],
    ['Photons, Particles & Waves', 'Wave-particle duality — single photon double slit experiment.', 'photons-particles-waves/photons-particles-waves.html', 'Quantum mechanics'],
    ['The Finite Well', 'Finite vs infinite potential well — bound states comparison.', 'finite-infinite-well/finite-infinite-well.html', 'Quantum mechanics'],
    ['Variational Method', 'Variational method for approximating ground state energy.', 'variationalMethod/variationalMethod.html', 'Quantum mechanics'],
];

// ── URL-only resources (not embeddable) ──
$urls = [
    // Educaplus (Spanish, blocked)
    ['Tabla Periódica Interactiva', 'Tabla periódica interactiva con datos de cada elemento.', 'https://www.educaplus.org/game/tabla-periodica', 'es', 'Chemistry', 'Educaplus', 'https://www.educaplus.org/'],
    ['Movimiento Rectilíneo', 'Simulación de MRU y MRUA con gráficas.', 'https://www.educaplus.org/game/movimiento-rectilineo', 'es', 'Physics', 'Educaplus', 'https://www.educaplus.org/'],
    ['Suma de Vectores', 'Suma gráfica y analítica de vectores 2D.', 'https://www.educaplus.org/game/suma-de-vectores-2d', 'es', 'Physics', 'Educaplus', 'https://www.educaplus.org/'],
    ['Ondas Transversales', 'Simulación de propagación de ondas transversales.', 'https://www.educaplus.org/game/ondas-transversales', 'es', 'Physics', 'Educaplus', 'https://www.educaplus.org/'],
    ['Ley de Hooke', 'Simulación de la ley de Hooke — fuerza elástica.', 'https://www.educaplus.org/game/laboratorio-virtual-de-la-ley-de-hooke', 'es', 'Physics', 'Educaplus', 'https://www.educaplus.org/'],
    ['Campo Eléctrico', 'Visualización de campo eléctrico de cargas puntuales.', 'https://www.educaplus.org/game/campo-electrico', 'es', 'Physics', 'Educaplus', 'https://www.educaplus.org/'],

    // CK-12 Simulations
    ['Inclined Plane Simulation', 'Interactive inclined plane — forces, friction, and acceleration.', 'https://interactives.ck12.org/simulations/physics/inclined-plane/', 'en', 'Physics', 'CK-12', 'https://interactives.ck12.org/'],
    ['Projectile Motion Simulation', 'Launch projectiles and explore trajectory, range, and max height.', 'https://interactives.ck12.org/simulations/physics/projectile-motion/', 'en', 'Physics', 'CK-12', 'https://interactives.ck12.org/'],
    ['Electrostatics Simulation', 'Explore electric charges, forces, and fields interactively.', 'https://interactives.ck12.org/simulations/physics/electrostatics/', 'en', 'Physics', 'CK-12', 'https://interactives.ck12.org/'],

    // Fisicalab (Spanish, blocked)
    ['Fisicalab: Cinemática', 'Explicaciones interactivas de cinemática — MRU, MRUA, caída libre.', 'https://www.fisicalab.com/apartado/cinematica-702', 'es', 'Physics', 'Fisicalab', 'https://www.fisicalab.com/'],
    ['Fisicalab: Dinámica', 'Leyes de Newton, rozamiento, plano inclinado — teoría y ejercicios.', 'https://www.fisicalab.com/apartado/dinamica', 'es', 'Physics', 'Fisicalab', 'https://www.fisicalab.com/'],
    ['Fisicalab: Energía', 'Trabajo, energía cinética, potencial, conservación de energía.', 'https://www.fisicalab.com/apartado/trabajo-y-energia', 'es', 'Physics', 'Fisicalab', 'https://www.fisicalab.com/'],
];

$catId = 1; // Physics
$inserted = 0;
$skipped = 0;

// ── INSERT Walter Fendt ──
echo "── Walter Fendt Physics Simulations ──\n";
$stmt = $db->prepare("INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag, lang, level, category_id, visibility, source_name, source_url, author_tenant_id, author_user_id, author_display_name, author_tenant_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,1,'iarepo','')");

foreach ($fendt as [$title, $desc, $file, $lang, $topic]) {
    $url = $wf . $file;
    // Check duplicate
    $dup = $db->prepare("SELECT id FROM resources WHERE code_content = ? AND is_active = 1");
    $dup->execute([$url]);
    if ($dup->fetch()) { $skipped++; continue; }

    $stmt->execute([$title, $desc, $url, 'url', 'Physics', $topic, $lang, 'secondary', $catId, 'community', 'Walter Fendt', 'https://www.walter-fendt.de/']);
    $inserted++;
    echo "  ✅ $title\n";
}

// ── INSERT QuVis ──
echo "\n── QuVis Quantum Mechanics (St Andrews) ──\n";
foreach ($quvis as [$title, $desc, $path, $topic]) {
    $url = $qv . $path;
    $dup = $db->prepare("SELECT id FROM resources WHERE code_content = ? AND is_active = 1");
    $dup->execute([$url]);
    if ($dup->fetch()) { $skipped++; continue; }

    $stmt->execute([$title, $desc, $url, 'url', 'Physics', $topic, 'en', 'university', $catId, 'community', 'QuVis (St Andrews)', 'https://www.st-andrews.ac.uk/physics/quvis/']);
    $inserted++;
    echo "  ✅ $title\n";
}

// ── INSERT URL-only resources ──
echo "\n── External URL Resources ──\n";
$urlStmt = $db->prepare("INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag, lang, level, category_id, visibility, source_name, source_url, author_tenant_id, author_user_id, author_display_name, author_tenant_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,1,'iarepo','')");

foreach ($urls as [$title, $desc, $url, $lang, $area, $source, $sourceUrl]) {
    $dup = $db->prepare("SELECT id FROM resources WHERE code_content = ? AND is_active = 1");
    $dup->execute([$url]);
    if ($dup->fetch()) { $skipped++; continue; }

    $cid = $area === 'Chemistry' ? 3 : 1; // Chemistry=3, Physics=1
    $urlStmt->execute([$title, $desc, $url, 'url', $area, '', $lang, 'secondary', $cid, 'community', $source, $sourceUrl]);
    $inserted++;
    echo "  ✅ $title\n";
}

echo "\n🎉 Done! Inserted: $inserted, Skipped (duplicates): $skipped\n";
echo "Total resources: " . $db->query("SELECT COUNT(*) FROM resources WHERE is_active = 1")->fetchColumn() . "\n";
