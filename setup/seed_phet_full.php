<?php
// Generates INSERT statements for all missing PhET HTML5 simulations (Spanish)
// Run: php setup/seed_phet_full.php | mysql -u USER -p DB

$existing_slugs = [
    'forces-and-motion-basics','energy-skate-park','projectile-motion',
    'wave-interference','gravity-and-orbits','pendulum-lab',
    'circuit-construction-kit-dc','coulombs-law','waves-intro',
    'faradays-law','build-a-molecule','balancing-chemical-equations',
    'concentration','ph-scale','states-of-matter','natural-selection',
    'gene-expression-essentials','neuron','graphing-lines','area-builder',
    'fractions-intro','function-builder','vector-addition'
];

$sims = [
    // [slug, title_es, description_es, subject, level, cat_id]
    ['membrane-transport','Transporte de Membrana','Simula el transporte activo y pasivo a través de membranas celulares.','Biología','secondary',4],
    ['quantum-coin-toss','Lanzamiento de Moneda Cuántica','Explora la superposición cuántica con un lanzamiento de moneda.','Física','university',1],
    ['number-pairs','Pares de Números','Explora combinaciones de números que suman un total dado.','Matemáticas','primary',2],
    ['quantum-measurement','Medición Cuántica','Explora cómo la medición afecta los estados cuánticos.','Física','university',1],
    ['models-of-the-hydrogen-atom','Modelos del Átomo de Hidrógeno','Compara modelos atómicos: Bohr, de Broglie, Schrödinger.','Física','university',1],
    ['buoyancy-basics','Flotabilidad: Intro','Explora por qué los objetos flotan o se hunden.','Física','secondary',1],
    ['buoyancy','Flotabilidad','Investiga la flotabilidad con densidad y volumen.','Física','secondary',1],
    ['mean-share-and-balance','Media: Distribuye y Equilibra','Explora el concepto de media distribuyendo y equilibrando.','Matemáticas','primary',2],
    ['generator','Generador','Genera electricidad con un imán y una bobina.','Física','secondary',1],
    ['magnets-and-electromagnets','Imanes y Electroimanes','Investiga campos magnéticos de imanes y electroimanes.','Física','secondary',1],
    ['magnet-and-compass','Imán y Brújula','Observa cómo un imán afecta una brújula.','Física','secondary',1],
    ['faradays-electromagnetic-lab','Laboratorio Electromagnético de Faraday','Experimenta con transformadores, generadores y electroimanes.','Física','ib',1],
    ['projectile-sampling-distributions','Distribuciones de Muestreo','Explora distribuciones de muestreo con datos de proyectiles.','Matemáticas','university',2],
    ['projectile-data-lab','Laboratorio de Datos de Proyectiles','Analiza datos de lanzamiento de proyectiles estadísticamente.','Matemáticas','university',2],
    ['center-and-variability','Centro y Variabilidad','Explora media, mediana y variabilidad de datos.','Matemáticas','secondary',2],
    ['build-a-nucleus','Construye un Núcleo','Construye núcleos atómicos y explora la estabilidad nuclear.','Física','ib',1],
    ['keplers-laws','Leyes de Kepler','Simula órbitas planetarias y las tres leyes de Kepler.','Física','ib',1],
    ['sound-waves','Ondas Sonoras','Visualiza ondas de sonido con frecuencia y amplitud ajustables.','Física','secondary',1],
    ['quadrilateral','Cuadrilátero','Explora propiedades de cuadriláteros interactivamente.','Matemáticas','secondary',2],
    ['my-solar-system','Mi Sistema Solar','Crea tu propio sistema solar con múltiples cuerpos.','Física','secondary',1],
    ['calculus-grapher','Graficador para Cálculo','Dibuja f(x) y observa f\'(x) y ∫f(x)dx en tiempo real.','Matemáticas','university',2],
    ['number-compare','Comparar Números','Compara cantidades usando representaciones visuales.','Matemáticas','primary',2],
    ['number-play','Juego de Números','Explora números del 1 al 20 con representaciones visuales.','Matemáticas','primary',2],
    ['greenhouse-effect','Efecto Invernadero','Simula el efecto invernadero y el cambio climático.','Física','secondary',1],
    ['geometric-optics-basics','Óptica Geométrica: Intro','Explora lentes y espejos con trazado de rayos simplificado.','Física','secondary',1],
    ['geometric-optics','Óptica Geométrica','Laboratorio completo de óptica geométrica con lentes y espejos.','Física','ib',1],
    ['density','Densidad','Explora masa, volumen y densidad con objetos flotantes.','Física','secondary',1],
    ['circuit-construction-kit-ac','Kit de Circuitos: CA','Construye circuitos de corriente alterna con capacitores e inductores.','Física','ib',1],
    ['circuit-construction-kit-ac-virtual-lab','Kit de Circuitos CA: Lab Virtual','Laboratorio virtual completo de circuitos AC.','Física','ib',1],
    ['normal-modes','Modos Normales','Visualiza modos normales de oscilación en cadenas de masas.','Física','university',1],
    ['fourier-making-waves','Fourier: Creando Ondas','Construye ondas sumando armónicos con series de Fourier.','Física','university',1],
    ['number-line-distance','Recta Numérica: Distancia','Explora distancia y valor absoluto en la recta numérica.','Matemáticas','secondary',2],
    ['ratio-and-proportion','Razón y Proporción','Explora razones y proporciones con representaciones visuales.','Matemáticas','secondary',2],
    ['collision-lab','Laboratorio de Colisiones','Investiga colisiones elásticas e inelásticas en 1D y 2D.','Física','ib',1],
    ['number-line-operations','Recta Numérica: Operaciones','Suma y resta en la recta numérica.','Matemáticas','primary',2],
    ['masses-and-springs','Masas y Resortes','Explora la ley de Hooke y el movimiento armónico simple.','Física','secondary',1],
    ['equality-explorer-two-variables','Explorador de Igualdades: Dos Variables','Resuelve ecuaciones con dos variables visualmente.','Matemáticas','secondary',2],
    ['equality-explorer-basics','Explorador de Igualdades: Intro','Introducción visual al concepto de igualdad.','Matemáticas','primary',2],
    ['equality-explorer','Explorador de Igualdades','Resuelve ecuaciones usando una balanza interactiva.','Matemáticas','secondary',2],
    ['area-model-algebra','Modelo de Áreas: Álgebra','Multiplica expresiones algebraicas con el modelo de áreas.','Matemáticas','secondary',2],
    ['area-model-decimals','Modelo de Áreas: Decimales','Multiplica decimales con el modelo de áreas.','Matemáticas','primary',2],
    ['area-model-multiplication','Modelo de Áreas: Multiplicación','Aprende multiplicación con el modelo de áreas.','Matemáticas','primary',2],
    ['area-model-introduction','Modelo de Áreas: Introducción','Introducción a la multiplicación con áreas.','Matemáticas','primary',2],
    ['capacitor-lab-basics','Lab de Condensadores: Intro','Explora cómo funciona un condensador en un circuito.','Física','ib',1],
    ['circuit-construction-kit-dc-virtual-lab','Kit de Circuitos CD: Lab Virtual','Laboratorio virtual completo de circuitos DC.','Física','secondary',1],
    ['molecule-polarity','Polaridad de la Molécula','Explora la polaridad molecular y el momento dipolar.','Química','ib',3],
    ['expression-exchange','Cambio de Expresiones','Simplifica expresiones algebraicas con fichas.','Matemáticas','secondary',2],
    ['graphing-slope-intercept','Graficando Rectas: Pendiente-Intersección','Grafica rectas en forma pendiente-intersección.','Matemáticas','secondary',2],
    ['function-builder-basics','Generador de Funciones: Intro','Introducción a funciones con máquinas de entrada/salida.','Matemáticas','primary',2],
    ['proportion-playground','Pista de Proporciones','Explora razones y proporciones jugando.','Matemáticas','secondary',2],
    ['unit-rates','Razón Unitaria','Compara precios unitarios y razones.','Matemáticas','secondary',2],
    ['make-a-ten','Haz un Diez','Suma números formando decenas.','Matemáticas','primary',2],
    ['states-of-matter-basics','Estados de la Materia: Intro','Intro a sólidos, líquidos y gases a nivel molecular.','Química','secondary',3],
    ['atomic-interactions','Interacciones Atómicas','Explora fuerzas entre átomos y el potencial de Lennard-Jones.','Química','ib',3],
    ['charges-and-fields','Cargas y Campos','Coloca cargas y visualiza el campo eléctrico.','Física','ib',1],
    ['rutherford-scattering','Dispersión de Rutherford','Simula el experimento de Rutherford con partículas alfa.','Física','ib',1],
    ['isotopes-and-atomic-mass','Isótopos y Masa Atómica','Explora isótopos y calcula masa atómica promedio.','Química','secondary',3],
    ['trig-tour','Tour Trigonométrico','Explora seno, coseno y tangente en el círculo unitario.','Matemáticas','secondary',2],
    ['bending-light','Reflexión y Refracción','Explora la ley de Snell con rayos de luz interactivos.','Física','secondary',1],
    ['arithmetic','Aritmética','Practica multiplicación, división y factorización.','Matemáticas','primary',2],
    ['hookes-law','Ley de Hooke','Estira resortes y explora la constante elástica.','Física','secondary',1],
    ['molecules-and-light','Moléculas y Luz','Observa cómo las moléculas interactúan con fotones.','Química','ib',3],
    ['least-squares-regression','Regresión de Mínimos Cuadrados','Ajusta rectas a datos usando mínimos cuadrados.','Matemáticas','university',2],
    ['molecule-shapes','Forma de la Molécula','Explora geometrías moleculares y VSEPR.','Química','ib',3],
    ['molecule-shapes-basics','Formas de la Molécula: Intro','Introducción a la geometría molecular.','Química','secondary',3],
    ['wave-on-a-string','Onda en una Cuerda','Genera ondas en una cuerda con extremos fijos o libres.','Física','secondary',1],
    ['color-vision','Visión del Color','Explora cómo se mezclan los colores RGB de luz.','Física','secondary',1],
    ['fraction-matcher','Parejas de Fracciones','Empareja fracciones equivalentes en un juego.','Matemáticas','primary',2],
    ['balancing-act','Ley de Equilibrio','Equilibra objetos en una balanza — momentos de fuerza.','Física','secondary',1],
    ['acid-base-solutions','Soluciones Ácido-Base','Investiga ácidos, bases y pH a nivel molecular.','Química','ib',3],
    ['under-pressure','Bajo Presión','Explora presión en fluidos y la ecuación de Bernoulli.','Física','secondary',1],
    ['friction','Fricción','Explora fricción estática y cinética con fuerzas.','Física','secondary',1],
    ['build-a-fraction','Construye una Fracción','Construye fracciones con piezas interactivas.','Matemáticas','primary',2],
    ['fractions-equality','Fracciones: Igualdades','Encuentra fracciones equivalentes visualmente.','Matemáticas','primary',2],
    ['fractions-mixed-numbers','Fracciones: Números Mixtos','Explora fracciones impropias y números mixtos.','Matemáticas','primary',2],
    ['energy-forms-and-changes','Formas y Cambios de Energía','Observa transferencias de energía entre objetos.','Física','secondary',1],
    ['masses-and-springs-basics','Masas y Resortes: Intro','Intro al movimiento armónico con masas y resortes.','Física','secondary',1],
    ['john-travoltage','Travoltaje','Genera electricidad estática frotando los pies.','Física','primary',1],
    ['gravity-force-lab','Lab de Fuerza de Gravedad','Mide la fuerza gravitacional entre dos masas.','Física','secondary',1],
    ['balloons-and-static-electricity','Globos y Electricidad Estática','Frota un globo y observa cargas estáticas.','Física','primary',1],
    ['beers-law-lab','Lab de la Ley de Beer','Explora absorbancia, transmitancia y concentración.','Química','ib',3],
    ['molarity','Molaridad','Calcula molaridad de soluciones interactivamente.','Química','secondary',3],
    ['ohms-law','Ley de Ohm','Explora V = IR con voltaje, corriente y resistencia.','Física','secondary',1],
    ['resistance-in-a-wire','Resistencia en un Alambre','Explora cómo longitud y área afectan la resistencia.','Física','secondary',1],
    ['build-an-atom','Construye un Átomo','Construye átomos con protones, neutrones y electrones.','Química','secondary',3],
    ['pendulum-lab-basics','Lab de Péndulo: Intro','Introducción al péndulo simple.','Física','secondary',1],
    ['circuit-construction-kit-dc-basics','Kit de Circuitos CD: Intro','Intro a circuitos eléctricos DC.','Física','secondary',1],
    ['diffusion','Difusión','Visualiza la difusión de partículas a nivel molecular.','Química','secondary',3],
    ['gases-intro','Gases: Intro','Intro a las propiedades de los gases ideales.','Química','secondary',3],
    ['gas-properties','Propiedades de los Gases','Explora presión, volumen y temperatura de gases.','Química','ib',3],
    ['blackbody-spectrum','Espectro de Cuerpo Negro','Visualiza el espectro de radiación según temperatura.','Física','ib',1],
    ['area-model-division','División: Modelo de Áreas','Aprende división con el modelo de áreas.','Matemáticas','primary',2],
    ['ph-scale-basics','Escala de pH: Fundamentos','Introducción al pH con soluciones comunes.','Química','secondary',3],
];

echo "-- PhET Full Catalog: " . count($sims) . " new simulations\n\n";

foreach ($sims as $s) {
    [$slug, $title, $desc, $subj, $level, $cat] = $s;
    if (in_array($slug, $existing_slugs)) continue;
    
    $url = "https://phet.colorado.edu/es/simulations/$slug";
    $t = addslashes($title);
    $d = addslashes($desc);
    $hash = md5("phet-es-$slug");
    
    echo "INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, moderation_status) VALUES ('$t','$d','$url','url','$subj','community','es','$level',$cat,'$url','PhET Interactive Simulations','$hash',0,1,'iarepo','approved');\n";
}

echo "\n-- Tags\n";
echo "INSERT IGNORE INTO resource_tags (resource_id, tag) SELECT id, 'simulación' FROM resources WHERE source_name = 'PhET Interactive Simulations' AND id > 256;\n";
echo "INSERT IGNORE INTO resource_tags (resource_id, tag) SELECT id, 'interactivo' FROM resources WHERE source_name = 'PhET Interactive Simulations' AND id > 256;\n";
echo "INSERT IGNORE INTO resource_tags (resource_id, tag) SELECT id, 'phet' FROM resources WHERE source_name = 'PhET Interactive Simulations' AND id > 256;\n";
echo "INSERT IGNORE INTO resource_tags (resource_id, tag) SELECT id, 'html5' FROM resources WHERE source_name = 'PhET Interactive Simulations' AND id > 256;\n";
