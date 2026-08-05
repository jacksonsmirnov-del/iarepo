-- ================================================================
-- tests/fixtures/seed.sql — Corpus determinista para la suite de
-- integración del buscador (tests/integration/*.php).
--
-- NO es una muestra de producción: cada fila existe para ejercitar UN
-- fallo concreto de los documentados en el diagnóstico del buscador.
-- Si tocas una fila, mira antes qué test la usa (columna "cubre").
--
-- Reglas del corpus:
--   · IDs FIJOS en el rango 1000-1099 (nunca chocan con datos reales:
--     prod usa el autoincremento desde 1). Los tests referencian IDs.
--   · view_count controlado: el desempate por popularidad de
--     shared/search.php está capeado en +3 (LEAST(view_count/200,3)),
--     así que los márgenes de relevancia entre filas son >= 9 para que
--     ningún test dependa de la popularidad.
--   · created_at DESCENDENTE respecto al id en algunos bloques a
--     propósito: así 'sort=recent' y 'sort=relevance' dan órdenes
--     DISTINTOS y el test de relevancia no puede pasar por accidente.
--   · Todo lo visible es visibility='community', is_active=1 y
--     link_status NULL, que es lo que ve un usuario anónimo.
--
-- Se carga tras setup/schema.sql + setup/migration_*.sql. Depende de
-- las categorías que siembra migration_001 (por slug, no por id).
-- ================================================================

DELETE FROM resource_tags  WHERE resource_id BETWEEN 1000 AND 1099;
DELETE FROM resource_likes WHERE resource_id BETWEEN 1000 AND 1099;
DELETE FROM resources      WHERE id          BETWEEN 1000 AND 1099;

SET @cat_phys = (SELECT id FROM categories WHERE slug = 'physics');
SET @cat_math = (SELECT id FROM categories WHERE slug = 'mathematics');
SET @cat_chem = (SELECT id FROM categories WHERE slug = 'chemistry');
SET @cat_bio  = (SELECT id FROM categories WHERE slug = 'biology');
SET @cat_cs   = (SELECT id FROM categories WHERE slug = 'computer-science');
SET @cat_soc  = (SELECT id FROM categories WHERE slug = 'social-studies');
SET @cat_gen  = (SELECT id FROM categories WHERE slug = 'general');

INSERT INTO resources
    (id, title, description, code_content, code_type, subject_area, topic_tag,
     lang, level, category_id, author_tenant_id, author_user_id,
     author_display_name, author_tenant_name, visibility,
     view_count, use_count, is_active, source_name, source_url, link_status,
     created_at, updated_at)
VALUES

-- ── A · ONDAS: plural/singular y AND multi-palabra ──────────────
-- cubre: iarepo_stem (ondas→onda), "ondas sonido" como AND real.
--        1000 es el ÚNICO que tiene además "sonido".
(1000, 'Ondas sonoras y su propagación',
 'Cómo viaja el sonido por el aire, el agua y los sólidos. Incluye la velocidad del sonido.',
 '<p>demo</p>', 'html', 'Física', 'ondas', 'es', 'secundaria', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 120, 4, 1, NULL, NULL, NULL,
 '2026-01-10 10:00:00', '2026-01-10 10:00:00'),

-- cubre: aparece en "ondas"/"onda" pero NO en "ondas sonido" (no dice sonido).
(1001, 'Ondas electromagnéticas',
 'Espectro, longitud de onda y frecuencia de la radiación.',
 '<p>demo</p>', 'html', 'Física', 'ondas', 'es', 'bachillerato', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 80, 2, 1, NULL, NULL, NULL,
 '2026-01-09 10:00:00', '2026-01-09 10:00:00'),

-- cubre: el SINGULAR en el dato. La consulta "ondas" debe encontrarlo
--        (stem) y la consulta "onda" también → mismo conjunto de ids.
--        OJO: ni el título ni la descripción dicen "sonido" (dicen
--        "supersónica"), a propósito: así "ondas sonido" deja fuera a
--        1001 Y a 1002, y el AND se prueba de verdad.
(1002, 'La onda de choque supersónica',
 'Qué ocurre al superar la velocidad del avión en el aire.',
 '<p>demo</p>', 'html', 'Física', 'onda', 'es', 'bachillerato', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 40, 1, 1, NULL, NULL, NULL,
 '2026-01-08 10:00:00', '2026-01-08 10:00:00'),

-- ── B · PREFIJOS: "matem" no casaba (fulltext exige palabra entera) ─
(1003, 'Matemáticas: fracciones equivalentes',
 'Manipulable para comparar fracciones con el mismo valor.',
 '<p>demo</p>', 'html', 'Matemáticas', 'fracciones', 'es', 'primaria', @cat_math,
 1, 2, 'Luis Profe', 'IES Central', 'community', 200, 9, 1, NULL, NULL, NULL,
 '2026-01-07 10:00:00', '2026-01-07 10:00:00'),

-- cubre: prefijo "matem" con la palabra en MINÚSCULAS y en medio del título.
(1004, 'Geometría para matemáticas de primaria',
 'Áreas y perímetros con figuras arrastrables.',
 '<p>demo</p>', 'html', 'Matemáticas', 'geometria', 'es', 'primaria', @cat_math,
 1, 2, 'Luis Profe', 'IES Central', 'community', 60, 3, 1, NULL, NULL, NULL,
 '2026-01-06 10:00:00', '2026-01-06 10:00:00'),

-- ── C · TOKEN CORTO "pH" (<3 chars: InnoDB no lo indexa) ────────
-- cubre: 1005 DEBE salir el primero pese a que 1006/1007 también
--        contienen la subcadena "ph". Es puro ranking, no filtro.
(1005, 'Escala de pH',
 'Ácidos y bases: cómo medir el pH de una disolución con indicadores.',
 '<p>demo</p>', 'html', 'Química', 'ph', 'es', 'secundaria', @cat_chem,
 1, 3, 'Marta Quim', 'IES Central', 'community', 50, 2, 1, NULL, NULL, NULL,
 '2026-01-05 10:00:00', '2026-01-05 10:00:00'),

-- cubre: (a) ruido inevitable de "ph" por subcadena ("PhET" está en
--        source_name); (b) el caso PhET/simulation: el término NO
--        aparece ni en title ni en description ni en topic_tag, sólo
--        en source_name y en los tags → sólo lo alcanza el brazo LIKE.
(1006, 'Circuitos eléctricos interactivos',
 'Construye circuitos con pilas, bombillas y resistencias y mide la corriente.',
 'https://phet.colorado.edu/x', 'url', 'Física', 'circuitos', 'en', 'secundaria', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 300, 12, 1,
 'PhET Interactive Simulations', 'https://phet.colorado.edu/x', NULL,
 '2026-01-04 10:00:00', '2026-01-04 10:00:00'),

-- cubre: ruido de "ph" en inglés (Photosynthesis). Debe salir DESPUÉS de 1005.
(1007, 'Photosynthesis explained',
 'How plants convert light into chemical energy.',
 '<p>demo</p>', 'html', 'Biology', 'photosynthesis', 'en', 'secundaria', @cat_bio,
 1, 4, 'John Teacher', 'IES Central', 'community', 90, 5, 1, NULL, NULL, NULL,
 '2026-01-03 10:00:00', '2026-01-03 10:00:00'),

-- ── D · STOPWORDS ("the water cycle" daba 6 resultados, ninguno del tema) ─
(1008, 'The Water Cycle',
 'Evaporation, condensation and precipitation explained step by step.',
 '<p>demo</p>', 'html', 'Earth Science', 'water cycle', 'en', 'primaria', @cat_gen,
 1, 4, 'John Teacher', 'IES Central', 'community', 70, 3, 1, NULL, NULL, NULL,
 '2026-01-02 10:00:00', '2026-01-02 10:00:00'),

-- cubre: el mismo tema en español. NO debe salir en "the water cycle"
--        (ni 'water' ni 'cycl' son subcadena de "ciclo del agua").
(1009, 'Ciclo del agua',
 'Evaporación, condensación y precipitación paso a paso.',
 '<p>demo</p>', 'html', 'Ciencias Naturales', 'ciclo', 'es', 'primaria', @cat_gen,
 1, 4, 'John Teacher', 'IES Central', 'community', 65, 3, 1, NULL, NULL, NULL,
 '2026-01-01 10:00:00', '2026-01-01 10:00:00'),

-- cubre: consulta compuesta SÓLO de stopwords ("de la"): shared/search.php
--        cae a la frase completa. Esta fila es la única con "de la" literal.
(1010, 'Historia de la imprenta',
 'De Gutenberg a la rotativa.',
 '<p>demo</p>', 'html', 'Historia', 'imprenta', 'es', 'secundaria', @cat_soc,
 1, 5, 'Rosa Hist', 'IES Central', 'community', 30, 1, 1, NULL, NULL, NULL,
 '2025-12-31 10:00:00', '2025-12-31 10:00:00'),

-- ── E · PUNTUACIÓN Y RELEVANCIA ────────────────────────────────
-- cubre: "C++" daba HTTP 500. Además debe salir PRIMERO: la frase cruda
--        con puntuación intacta ("c++") sólo casa en este título.
(1011, 'Introducción a C++',
 'Punteros, clases y plantillas para bachillerato.',
 'print(1)', 'python', 'Informática', 'cpp', 'es', 'bachillerato', @cat_cs,
 1, 6, 'Iván Code', 'IES Central', 'community', 45, 2, 1, NULL, NULL, NULL,
 '2025-12-30 10:00:00', '2025-12-30 10:00:00'),

-- cubre: "física-química" (el guion se interpretaba como NOT y daba 1
--        resultado erróneo). También acentos: "fisica" == "física".
(1012, 'Física y química: enlaces covalentes',
 'Modelo de enlace y geometría molecular.',
 '<p>demo</p>', 'html', 'Ciencias', 'enlaces', 'es', 'bachillerato', @cat_chem,
 1, 3, 'Marta Quim', 'IES Central', 'community', 55, 2, 1, NULL, NULL, NULL,
 '2025-12-29 10:00:00', '2025-12-29 10:00:00'),

-- cubre: "energia cinetica" debe devolver ESTE y sólo este. created_at
--        deliberadamente MÁS ANTIGUO que el del señuelo 1014, para que
--        ordenar por fecha lo ponga segundo y por relevancia primero.
(1013, 'Energía cinética y potencial',
 'Conversión entre energía cinética y energía potencial en un péndulo.',
 '<p>demo</p>', 'html', 'Física', 'energia', 'es', 'bachillerato', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 25, 1, 1, NULL, NULL, NULL,
 '2025-12-20 10:00:00', '2025-12-20 10:00:00'),

-- cubre: el SEÑUELO real del diagnóstico ("Oxígeno: Materia y Energía"
--        salía primero en "energia cinetica"). Tiene "energía" pero NO
--        "cinética" → el AND debe excluirlo. Y es el MÁS NUEVO del corpus.
(1014, 'Oxígeno: materia y energía',
 'La energía en los seres vivos y el intercambio gaseoso.',
 '<p>demo</p>', 'html', 'Biología', 'energia', 'es', 'secundaria', @cat_bio,
 1, 4, 'John Teacher', 'IES Central', 'community', 400, 20, 1, NULL, NULL, NULL,
 '2026-02-01 10:00:00', '2026-02-01 10:00:00'),

-- ── F · FILAS QUE NUNCA DEBEN APARECER (para un anónimo) ────────
-- Todas contienen "onda" a propósito: si alguna se cuela en la búsqueda
-- de "ondas", el filtro de visibilidad/estado se ha roto.
(1020, 'Ondas gravitacionales (borrador)',
 'Borrador privado del autor sobre LIGO y el sonido del cosmos.',
 '<p>demo</p>', 'html', 'Física', 'ondas', 'es', 'bachillerato', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'draft', 0, 0, 1, NULL, NULL, NULL,
 '2025-12-10 10:00:00', '2025-12-10 10:00:00'),

(1021, 'Ondas de radio (enlace roto)',
 'Recurso cuyo enlace externo ya no responde; el sonido no carga.',
 'https://muerto.example/x', 'url', 'Física', 'ondas', 'es', 'secundaria', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 10, 0, 1,
 'Sitio Muerto', 'https://muerto.example/x', 'broken',
 '2025-12-09 10:00:00', '2025-12-09 10:00:00'),

(1022, 'Ondas mecánicas (retirado)',
 'Recurso desactivado por el autor; hablaba del sonido en cuerdas.',
 '<p>demo</p>', 'html', 'Física', 'ondas', 'es', 'secundaria', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 10, 0, 0, NULL, NULL, NULL,
 '2025-12-08 10:00:00', '2025-12-08 10:00:00'),

-- cubre: visibilidad 'school' de OTRO tenant (7): invisible para el
--        anónimo y para el tenant 1, visible para un docente del 7.
(1023, 'Ondas estacionarias (uso interno)',
 'Material interno del centro sobre el sonido en tubos.',
 '<p>demo</p>', 'html', 'Física', 'ondas', 'es', 'bachillerato', @cat_phys,
 7, 70, 'Docente Ajeno', 'Colegio Lejano', 'school', 5, 0, 1, NULL, NULL, NULL,
 '2025-12-07 10:00:00', '2025-12-07 10:00:00'),

-- ── G · PAGINACIÓN Y FILTROS COMBINADOS ────────────────────────
-- 6 filas que comparten el término "laboratorio" y varían lang / level /
-- code_type / category para poder cruzar filtros con la búsqueda.
-- created_at consecutivos y decrecientes → orden 'recent' determinista.
(1030, 'Laboratorio virtual de titulación', 'Práctica guiada de ácido-base.',
 '<p>demo</p>', 'html', 'Química', 'laboratorio', 'es', 'bachillerato', @cat_chem,
 1, 3, 'Marta Quim', 'IES Central', 'community', 11, 0, 1, NULL, NULL, NULL,
 '2025-11-06 10:00:00', '2025-11-06 10:00:00'),
(1031, 'Laboratorio de electricidad', 'Monta circuitos en serie y paralelo.',
 'https://ej.example/e', 'url', 'Física', 'laboratorio', 'es', 'secundaria', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 12, 0, 1, NULL, NULL, NULL,
 '2025-11-05 10:00:00', '2025-11-05 10:00:00'),
(1032, 'Virtual laboratory: microscope', 'Explore cells with a virtual microscope.',
 '<p>demo</p>', 'html', 'Biology', 'laboratorio', 'en', 'secundaria', @cat_bio,
 1, 4, 'John Teacher', 'IES Central', 'community', 13, 0, 1, NULL, NULL, NULL,
 '2025-11-04 10:00:00', '2025-11-04 10:00:00'),
(1033, 'Laboratorio de estadística', 'Histogramas y medidas de dispersión.',
 '<p>demo</p>', 'html', 'Matemáticas', 'laboratorio', 'es', 'primaria', @cat_math,
 1, 2, 'Luis Profe', 'IES Central', 'community', 14, 0, 1, NULL, NULL, NULL,
 '2025-11-03 10:00:00', '2025-11-03 10:00:00'),
(1034, 'Laboratorio de sonido', 'Mide frecuencias con el micrófono del portátil.',
 '<p>demo</p>', 'html', 'Física', 'laboratorio', 'es', 'primaria', @cat_phys,
 1, 1, 'Ana Docente', 'IES Central', 'community', 15, 0, 1, NULL, NULL, NULL,
 '2025-11-02 10:00:00', '2025-11-02 10:00:00'),
(1035, 'Laboratorio de idiomas', 'Ejercicios de pronunciación con audio.',
 'https://ej.example/i', 'url', 'Lenguas', 'laboratorio', 'en', 'primaria', @cat_gen,
 1, 5, 'Rosa Hist', 'IES Central', 'community', 16, 0, 1, NULL, NULL, NULL,
 '2025-11-01 10:00:00', '2025-11-01 10:00:00');


-- ── Tags ────────────────────────────────────────────────────────
-- 'simulation' vive SÓLO aquí (en ninguna columna de resources): es el
-- caso que prueba el brazo EXISTS sobre resource_tags de shared/search.php.
-- 1006 NO lleva la etiqueta 'phet' a propósito: así la consulta "PhET"
-- sólo puede encontrarlo por source_name, que es la columna que el índice
-- FULLTEXT no cubre. Si alguien añade aquí (1006,'phet'), el test deja de
-- probar lo que cree que prueba.
INSERT INTO resource_tags (resource_id, tag) VALUES
    (1006, 'simulation'), (1006, 'circuitos'),
    (1000, 'acustica'),   (1000, 'simulation'),
    (1005, 'quimica'),    (1005, 'indicadores'),
    (1011, 'programacion'),
    (1030, 'quimica'),    (1032, 'simulation');

-- ── Likes (sólo para que el subquery like_count del listado no sea 0) ──
INSERT INTO resource_likes (resource_id, user_id, user_name) VALUES
    (1006, 901, 'Tester Uno'), (1006, 902, 'Tester Dos'), (1013, 901, 'Tester Uno');
