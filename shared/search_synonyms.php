<?php
// ================================================================
// shared/search_synonyms.php — Diccionario ES↔EN del dominio educativo
//
// Por qué existe: el catálogo es bilingüe (371 recursos marcados 'es',
// 192 'en') PERO el campo `lang` no es de fiar — entre los títulos
// marcados como español, los términos más frecuentes son 'mechanics',
// 'electromagnetism', 'waves', 'law', 'motion', 'quantum', 'chemistry'.
// Y los tags están duplicados por idioma: simulation(188)/simulación(96),
// interactive(248)/interactivo(93), physics/fisica.
// Por eso NO se puede resolver filtrando por `lang`: hay que expandir
// los términos de la consulta.  [datos verificados en prod 2026-08-04]
//
// Cómo se usa (implementado en shared/search.php):
//   cada término normalizado se expande a su grupo de equivalentes y el
//   AND entre términos se mantiene:
//     'ondas sonido' → +(onda* wave*) +(sonido* sound* acoustic* acustica*)
//   MariaDB BOOLEAN MODE admite el grupo con paréntesis: '+(a* b*)'
//   significa "obligatorio al menos uno" (verificado contra el motor, no
//   contra la documentación). El brazo LIKE hace lo mismo: OR dentro del
//   grupo y AND entre grupos.
//
// Los grupos se despluralizan y se PODAN por prefijo antes de emitirse,
// así que lo que escribas aquí no es lo que acaba en la consulta: si
// pones 'magnet', 'magnetism' y 'magnetic', sólo sale 'magnet*', que ya
// los cubre a los tres. Escribe las variantes que quieras; no cuestan.
//
// Reglas de mantenimiento:
// - Claves y valores SIEMPRE normalizados: minúsculas, sin tildes, sin
//   puntuación (igual que iarepo_normalize()), porque la expansión ocurre
//   DESPUÉS de normalizar. Las tildes de la CONSULTA se pliegan al
//   consultar este diccionario, así que "física" encuentra 'fisica'.
// - Es simétrico por construcción: iarepo_synonyms() cierra los grupos en
//   ambos sentidos, no hace falta escribir cada par dos veces.
// - Un grupo = un concepto. No metas "cuasi-sinónimos" (energia/fuerza):
//   ensucian la precisión que tanto costó conseguir.
// - NADA de menos de 3 caracteres: iarepo_synonyms() lo rechaza al cargar.
//   Está medido: el miembro 'ph' dentro de un grupo hace que '+(onda* ph*)'
//   devuelva "Photosynthesis". El único término corto admisible es el que
//   escriba el usuario, y ése va por frontera de palabra.
// - Nada de expresiones de varias palabras: la expansión ocurre token a
//   token, así que 'tabla periodica' es inalcanzable y se ignora al cargar.
//   Las que quedan abajo son inertes; están por documentar el concepto.
// - Un sinónimo se busca por PRINCIPIO DE PALABRA, no por subcadena. Por
//   eso 'math' alcanza "Mathematics" pero 'ion' ya no alcanza
//   "Simulations" (casaba 439 de 546 recursos del catálogo real).
//   Aun así, cuidado con los miembros cortos y comunes: 'par' llegó a estar
//   en el grupo de 'torque' y alcanzaba "para" y "partícula" (32 recursos);
//   se quitó por eso (ver el grupo más abajo). El principio de palabra
//   reduce el daño, no lo elimina: un sinónimo de 3 letras muy común sigue
//   siendo mala idea.
// - Al añadir un grupo, comprueba que no supera IAREPO_MAX_SYNONYMS tras
//   podar: test_el_tope_de_expansion_nunca_recorta_el_diccionario() se
//   pone rojo antes de que la poda ocurra en silencio.
// ================================================================

return [
    // ── Áreas (subject_area se guarda en inglés: buscar "física" debe
    //    alcanzar los 308 recursos con subject_area='Physics') ────────
    ['fisica', 'physics'],
    ['matematicas', 'matematica', 'mathematics', 'math', 'maths'],
    ['biologia', 'biology'],
    ['quimica', 'chemistry'],
    ['ciencias', 'ciencia', 'science', 'sciences'],
    ['geografia', 'geography'],
    ['historia', 'history'],
    ['economia', 'economics'],
    ['musica', 'music'],
    ['arte', 'art'],
    ['idiomas', 'lenguas', 'languages', 'language'],
    ['informatica', 'computacion', 'computer', 'computing'],
    ['salud', 'health'],
    ['astronomia', 'astronomy'],
    ['espacio', 'space'],

    // ── Física: mecánica ────────────────────────────────────────────
    ['mecanica', 'mechanics'],
    ['movimiento', 'motion'],
    ['fuerza', 'fuerzas', 'force', 'forces'],
    ['velocidad', 'speed', 'velocity'],
    ['aceleracion', 'acceleration'],
    ['gravedad', 'gravitacion', 'gravity', 'gravitational', 'gravitation'],
    ['peso', 'weight'],
    ['masa', 'mass'],
    ['energia', 'energy'],
    ['trabajo', 'work'],
    ['potencia', 'power'],
    ['cinetica', 'kinetic', 'kinetics'],
    ['potencial', 'potential'],
    ['momento', 'momentum'],
    ['impulso', 'impulse'],
    ['friccion', 'rozamiento', 'friction'],
    ['proyectil', 'proyectiles', 'projectile', 'projectiles'],
    ['pendulo', 'pendulum'],
    ['oscilacion', 'oscilaciones', 'oscillation', 'oscillations'],
    ['resorte', 'muelle', 'spring'],
    ['colision', 'colisiones', 'choque', 'collision', 'collisions'],
    ['equilibrio', 'equilibrium', 'balance'],
    ['palanca', 'lever'],
    ['plano inclinado', 'inclined plane'],
    ['rotacion', 'rotation', 'rotational'],
    // 'par' se quitó a propósito: como sinónimo alcanzaba por principio de
    // palabra "para", "parte" y "partícula" — 32 recursos de ruido para quien
    // busca "torque". Un sinónimo tan corto y tan común no compensa.
    ['torque', 'torsion'],
    ['orbita', 'orbitas', 'orbit', 'orbits', 'orbital'],
    ['ley', 'leyes', 'law', 'laws'],
    ['principio', 'principle'],

    // ── Física: fluidos, calor ──────────────────────────────────────
    ['hidrostatica', 'hydrostatics'],
    ['fluido', 'fluidos', 'fluid', 'fluids'],
    ['presion', 'pressure'],
    ['densidad', 'density'],
    ['flotacion', 'empuje', 'buoyancy', 'floating'],
    ['volumen', 'volume'],
    ['temperatura', 'temperature'],
    ['calor', 'heat'],
    ['termodinamica', 'thermodynamics', 'thermal'],
    ['gas', 'gases'],

    // ── Física: ondas, óptica, sonido ───────────────────────────────
    ['onda', 'ondas', 'wave', 'waves'],
    ['sonido', 'sound', 'acoustics', 'acustica'],
    ['frecuencia', 'frequency'],
    ['longitud de onda', 'wavelength'],
    ['amplitud', 'amplitude'],
    ['interferencia', 'interference'],
    ['difraccion', 'diffraction'],
    ['resonancia', 'resonance'],
    ['optica', 'optics', 'optical'],
    ['luz', 'light'],
    ['lente', 'lentes', 'lens', 'lenses'],
    ['espejo', 'espejos', 'mirror', 'mirrors'],
    ['refraccion', 'refraction'],
    ['reflexion', 'reflection'],
    ['color', 'colores', 'colour', 'colors'],
    ['espectro', 'spectrum'],

    // ── Física: electricidad y magnetismo ───────────────────────────
    ['electricidad', 'electricity', 'electric'],
    ['electromagnetismo', 'electromagnetism', 'electromagnetic'],
    ['circuito', 'circuitos', 'circuit', 'circuits'],
    ['corriente', 'current'],
    ['voltaje', 'tension', 'voltage'],
    ['resistencia', 'resistance', 'resistor'],
    ['carga', 'cargas', 'charge', 'charges'],
    ['campo', 'campos', 'field', 'fields'],
    ['iman', 'imanes', 'magnetismo', 'magnet', 'magnets', 'magnetism', 'magnetic'],
    ['induccion', 'induction'],
    ['condensador', 'capacitor'],

    // ── Física moderna ──────────────────────────────────────────────
    ['cuantica', 'cuantico', 'quantum'],
    ['atomo', 'atomos', 'atom', 'atoms', 'atomic'],
    ['radiactividad', 'radioactivity', 'radioactive'],
    ['fotoelectrico', 'photoelectric'],
    ['relatividad', 'relativity'],
    ['particula', 'particulas', 'particle', 'particles'],

    // ── Química ─────────────────────────────────────────────────────
    ['elemento', 'elementos', 'element', 'elements'],
    ['tabla periodica', 'periodic table'],
    ['molecula', 'moleculas', 'molecule', 'molecules', 'molecular'],
    ['enlace', 'enlaces', 'bond', 'bonds', 'bonding'],
    ['reaccion', 'reacciones', 'reaction', 'reactions'],
    ['acido', 'acidos', 'acid', 'acids'],
    ['base', 'bases', 'alkali'],
    ['disolucion', 'solucion', 'solution', 'solutions'],
    ['concentracion', 'concentration'],
    ['equilibrio quimico', 'chemical equilibrium'],
    ['estequiometria', 'stoichiometry'],
    ['mol', 'mole'],
    ['ion', 'iones', 'ions'],
    ['electron', 'electrones', 'electrons'],
    ['isotopo', 'isotope', 'isotopes'],

    // ── Biología ────────────────────────────────────────────────────
    ['celula', 'celulas', 'cell', 'cells', 'cellular'],
    ['adn', 'dna'],
    ['arn', 'rna'],
    ['gen', 'genes', 'gene', 'genetics', 'genetica'],
    ['herencia', 'inheritance', 'heredity'],
    ['evolucion', 'evolution', 'evolutionary'],
    ['seleccion natural', 'natural selection'],
    ['fotosintesis', 'photosynthesis'],
    ['respiracion', 'respiration', 'breathing'],
    ['ecosistema', 'ecosistemas', 'ecosystem', 'ecosystems'],
    ['ecologia', 'ecology', 'ecological'],
    ['poblacion', 'poblaciones', 'population', 'populations'],
    ['cuerpo humano', 'human body', 'anatomy', 'anatomia'],
    ['organo', 'organos', 'organ', 'organs'],
    ['musculo', 'musculos', 'muscle', 'muscles'],
    ['esqueleto', 'huesos', 'skeleton', 'bones'],
    ['corazon', 'heart'],
    ['sangre', 'blood'],
    ['neurona', 'neuronas', 'neuron', 'neurons'],
    ['proteina', 'proteinas', 'protein', 'proteins'],
    ['enzima', 'enzimas', 'enzyme', 'enzymes'],
    ['bacteria', 'bacterias', 'bacterial'],
    ['virus', 'viral'],
    ['planta', 'plantas', 'plant', 'plants'],
    ['animal', 'animales', 'animals'],

    // ── Matemáticas ─────────────────────────────────────────────────
    ['numero', 'numeros', 'numerica', 'number', 'numbers', 'numeric'],
    ['fraccion', 'fracciones', 'fraction', 'fractions'],
    ['decimal', 'decimales', 'decimals'],
    ['porcentaje', 'percent', 'percentage'],
    ['suma', 'adicion', 'addition', 'sum', 'adding'],
    ['resta', 'sustraccion', 'subtraction', 'subtracting'],
    ['multiplicacion', 'multiplication', 'multiply'],
    ['division', 'dividing'],
    ['algebra', 'algebraic'],
    ['ecuacion', 'ecuaciones', 'equation', 'equations'],
    ['funcion', 'funciones', 'function', 'functions'],
    ['grafica', 'graficas', 'grafico', 'graph', 'graphing', 'chart', 'charts'],
    ['geometria', 'geometry', 'geometric'],
    ['angulo', 'angulos', 'angle', 'angles'],
    ['triangulo', 'triangulos', 'triangle', 'triangles'],
    ['circulo', 'circle', 'circles'],
    ['area', 'areas'],
    ['perimetro', 'perimeter'],
    ['poligono', 'poligonos', 'polygon', 'polygons'],
    ['simetria', 'symmetry', 'symmetric'],
    ['trigonometria', 'trigonometry', 'trigonometric'],
    ['estadistica', 'statistics', 'statistical'],
    ['probabilidad', 'probability'],
    ['media', 'promedio', 'mean', 'average'],
    ['datos', 'data'],
    ['medicion', 'medida', 'measurement', 'measuring'],
    ['valor posicional', 'place value'],
    ['patron', 'patrones', 'pattern', 'patterns'],
    ['secuencia', 'sequence', 'sequences'],
    ['dinero', 'money', 'coins', 'monedas'],
    ['reloj', 'hora', 'clock', 'time telling'],
    ['calculadora', 'calculator'],
    ['calculo', 'calculus'],
    ['derivada', 'derivative', 'derivatives'],
    ['integral', 'integrals'],
    ['vector', 'vectores', 'vectors'],
    ['matriz', 'matrices', 'matrix'],
    ['coordenada', 'coordenadas', 'coordinate', 'coordinates'],
    ['recta', 'linea', 'lineas', 'line', 'lines', 'linear'],

    // ── Tierra, espacio ─────────────────────────────────────────────
    ['planeta', 'planetas', 'planet', 'planets', 'planetary'],
    ['sistema solar', 'solar system'],
    ['estrella', 'estrellas', 'star', 'stars'],
    ['galaxia', 'galaxy', 'galaxies'],
    ['luna', 'moon'],
    ['sol', 'sun', 'solar'],
    ['marte', 'mars'],
    ['agua', 'water'],
    ['ciclo del agua', 'water cycle'],
    ['clima', 'climate', 'weather'],
    ['atmosfera', 'atmosphere', 'atmospheric'],
    ['terremoto', 'sismo', 'earthquake', 'seismic'],
    ['volcan', 'volcanes', 'volcano', 'volcanoes'],
    ['roca', 'rocas', 'rock', 'rocks', 'mineral'],
    ['tierra', 'earth'],

    // ── Informática ─────────────────────────────────────────────────
    ['programacion', 'programming', 'coding'],
    ['algoritmo', 'algoritmos', 'algorithm', 'algorithms'],
    ['codigo', 'code'],
    ['bloque', 'bloques', 'block', 'blocks'],
    ['ordenamiento', 'sorting', 'sort'],
    ['busqueda', 'search', 'searching'],
    ['binario', 'binary'],
    ['red', 'redes', 'network', 'networks'],

    // ── Tipo de recurso y pedagogía (los tags duplicados) ───────────
    ['simulacion', 'simulaciones', 'simulation', 'simulations', 'simulator', 'sim'],
    ['interactivo', 'interactiva', 'interactive'],
    ['laboratorio', 'lab', 'labs', 'laboratory', 'virtual lab'],
    ['juego', 'juegos', 'game', 'games'],
    ['ejercicio', 'ejercicios', 'exercise', 'exercises', 'practice'],
    ['actividad', 'actividades', 'activity', 'activities'],
    ['modelo', 'modelos', 'model', 'models', 'modeling'],
    ['visualizacion', 'visualization', 'visualisation', 'visual'],
    ['manipulativo', 'manipulativos', 'manipulative', 'manipulatives'],
    ['gratis', 'gratuito', 'free'],
    ['primaria', 'elementary', 'primary'],
    ['secundaria', 'secondary', 'middle school'],
    ['bachillerato', 'high school'],
    ['profesor', 'docente', 'teacher'],
    ['estudiante', 'alumno', 'student'],
    ['clase', 'aula', 'classroom', 'class'],
    ['leccion', 'lesson'],
    ['examen', 'prueba', 'quiz', 'test'],
    ['grafico interactivo', 'interactive chart'],
    ['introduccion', 'intro', 'introduction'],
    ['avanzado', 'advanced'],
    ['basico', 'basic', 'beginner'],
];
