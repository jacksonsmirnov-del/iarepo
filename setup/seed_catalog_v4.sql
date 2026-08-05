-- ================================================================
-- seed_catalog_v4.sql — Beyond STEM: History, Geography, CS,
-- Music, Languages, Anatomy, Economics
-- ================================================================
-- author_tenant_name va explícito en cada fila: la columna es NOT NULL
-- SIN DEFAULT y con STRICT_TRANS_TABLES (producción: MariaDB 11.8) el
-- INSERT entero aborta con ERROR 1364 si se omite. Valor '' = el mismo
-- que escribe la app para recursos de comunidad (api/resources.php).

-- ── Geography (cat=6 Social Studies) ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Seterra: World Countries','Interactive map quiz — identify all countries of the world by clicking on a map.','https://www.seterra.com/en/vgp/3355','url','Social Studies','community','en','secondary',6,'https://www.seterra.com/','Seterra',MD5('seterra-world'),0,1,'iarepo','','approved'),
('Seterra: South America','Learn all South American countries by clicking on an interactive map.','https://www.seterra.com/en/vgp/3271','url','Social Studies','community','en','secondary',6,'https://www.seterra.com/','Seterra',MD5('seterra-southam'),0,1,'iarepo','','approved'),
('Seterra: European Countries','Identify all European countries on an interactive map quiz.','https://www.seterra.com/en/vgp/3007','url','Social Studies','community','en','secondary',6,'https://www.seterra.com/','Seterra',MD5('seterra-europe'),0,1,'iarepo','','approved'),
('Seterra: Africa','Learn African countries geography with interactive map quizzes.','https://www.seterra.com/en/vgp/3163','url','Social Studies','community','en','secondary',6,'https://www.seterra.com/','Seterra',MD5('seterra-africa'),0,1,'iarepo','','approved'),
('Seterra: Asia','Identify Asian countries on an interactive map.','https://www.seterra.com/en/vgp/3164','url','Social Studies','community','en','secondary',6,'https://www.seterra.com/','Seterra',MD5('seterra-asia'),0,1,'iarepo','','approved'),
('Seterra: World Capitals','Match world capitals to their countries on an interactive map.','https://www.seterra.com/en/vgp/3004','url','Social Studies','community','en','secondary',6,'https://www.seterra.com/','Seterra',MD5('seterra-capitals'),0,1,'iarepo','','approved');

-- ── History ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Chronas: Interactive World History Atlas','Explore world history on an interactive map — browse events, civilizations, and rulers by era.','https://chronas.org/history','url','Social Studies','community','en','secondary',6,'https://chronas.org/','Chronas',MD5('chronas-atlas'),0,1,'iarepo','','approved'),
('TimelineJS: Create Timelines','Open-source tool to create beautiful interactive timelines from a Google Spreadsheet.','https://timeline.knightlab.com/','url','Social Studies','community','en','secondary',6,'https://timeline.knightlab.com/','Knight Lab (Northwestern)',MD5('knightlab-timeline'),0,1,'iarepo','','approved'),
('HistoryMaps: Ancient Civilizations','Explore the rise and fall of ancient civilizations on interactive maps with timelines.','https://history-maps.com/','url','Social Studies','community','en','secondary',6,'https://history-maps.com/','HistoryMaps',MD5('historymaps-ancient'),0,1,'iarepo','','approved');

-- ── Computer Science ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Scratch: Create Stories & Games','Visual block-based programming environment. Create interactive stories, games, and animations.','https://scratch.mit.edu/projects/editor/','url','Computer Science','community','en','primary',7,'https://scratch.mit.edu/','MIT Scratch',MD5('scratch-editor'),0,1,'iarepo','','approved'),
('Blockly Games: Maze','Learn programming concepts by guiding a character through mazes using visual blocks.','https://blockly.games/maze','url','Computer Science','community','en','primary',7,'https://blockly.games/','Google Blockly',MD5('blockly-maze'),0,1,'iarepo','','approved'),
('Blockly Games: Turtle','Draw shapes and patterns by programming a turtle with visual blocks.','https://blockly.games/turtle','url','Computer Science','community','en','primary',7,'https://blockly.games/','Google Blockly',MD5('blockly-turtle'),0,1,'iarepo','','approved'),
('VisuAlgo: Sorting Algorithms','Visualize how sorting algorithms work step by step — bubble, merge, quick, insertion sort.','https://visualgo.net/en/sorting','url','Computer Science','community','en','university',7,'https://visualgo.net/','VisuAlgo (NUS)',MD5('visualgo-sorting'),0,1,'iarepo','','approved'),
('VisuAlgo: Graph Traversal','Visualize BFS, DFS, and shortest path algorithms on interactive graphs.','https://visualgo.net/en/dfsbfs','url','Computer Science','community','en','university',7,'https://visualgo.net/','VisuAlgo (NUS)',MD5('visualgo-graphs'),0,1,'iarepo','','approved'),
('VisuAlgo: Binary Search Tree','Interactive binary search tree — insert, delete, search with step-by-step animation.','https://visualgo.net/en/bst','url','Computer Science','community','en','university',7,'https://visualgo.net/','VisuAlgo (NUS)',MD5('visualgo-bst'),0,1,'iarepo','','approved'),
('Regex101: Regular Expressions','Build and test regular expressions with real-time explanation and match highlighting.','https://regex101.com/','url','Computer Science','community','en','university',7,'https://regex101.com/','Regex101',MD5('regex101'),0,1,'iarepo','','approved'),
('Python Tutor: Visualize Code','Step through Python, Java, C, and JavaScript code execution visually.','https://pythontutor.com/visualize.html','url','Computer Science','community','en','secondary',7,'https://pythontutor.com/','Python Tutor',MD5('pythontutor'),0,1,'iarepo','','approved');

-- ── Music & Arts ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('MusicTheory.net: Lessons','Interactive music theory lessons — staff, clefs, notes, scales, intervals, chords.','https://www.musictheory.net/lessons','url','Art & Music','community','en','secondary',9,'https://www.musictheory.net/','musictheory.net',MD5('musictheory-lessons'),0,1,'iarepo','','approved'),
('MusicTheory.net: Ear Training','Train your ear to identify intervals, scales, and chords with audio exercises.','https://www.musictheory.net/exercises','url','Art & Music','community','en','secondary',9,'https://www.musictheory.net/','musictheory.net',MD5('musictheory-ear'),0,1,'iarepo','','approved'),
('Chrome Music Lab: Song Maker','Create and share songs using a simple grid-based music maker.','https://musiclab.chromeexperiments.com/Song-Maker/','url','Art & Music','community','en','primary',9,'https://musiclab.chromeexperiments.com/','Chrome Music Lab',MD5('musiclab-songmaker'),0,1,'iarepo','','approved'),
('Chrome Music Lab: Rhythm','Explore rhythm patterns with an interactive visual drum machine.','https://musiclab.chromeexperiments.com/Rhythm/','url','Art & Music','community','en','primary',9,'https://musiclab.chromeexperiments.com/','Chrome Music Lab',MD5('musiclab-rhythm'),0,1,'iarepo','','approved'),
('Chrome Music Lab: Harmonics','Visualize sound waves and harmonics with interactive oscilloscope.','https://musiclab.chromeexperiments.com/Harmonics/','url','Art & Music','community','en','secondary',9,'https://musiclab.chromeexperiments.com/','Chrome Music Lab',MD5('musiclab-harmonics'),0,1,'iarepo','','approved'),
('Chrome Music Lab: Spectrogram','See the frequency spectrum of sounds in real time using your microphone.','https://musiclab.chromeexperiments.com/Spectrogram/','url','Art & Music','community','en','secondary',9,'https://musiclab.chromeexperiments.com/','Chrome Music Lab',MD5('musiclab-spectrogram'),0,1,'iarepo','','approved'),
('Quick, Draw! by Google','AI guesses what you\'re drawing — fun art + machine learning experiment.','https://quickdraw.withgoogle.com/','url','Art & Music','community','en','primary',9,'https://quickdraw.withgoogle.com/','Google Creative Lab',MD5('quickdraw'),0,1,'iarepo','','approved');

-- ── Anatomy & Health ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('BioDigital Human: 3D Anatomy','Explore the human body in 3D — skeletal, muscular, circulatory systems.','https://human.biodigital.com/explore','url','Health & PE','community','en','secondary',10,'https://www.biodigital.com/','BioDigital',MD5('biodigital-human'),0,1,'iarepo','','approved'),
('InnerBody: Human Anatomy','Interactive 2D/3D human anatomy explorer with detailed system views.','https://www.innerbody.com/htm/body.html','url','Health & PE','community','en','secondary',10,'https://www.innerbody.com/','InnerBody',MD5('innerbody-anatomy'),0,1,'iarepo','','approved'),
('GetBodySmart: Anatomy Quizzes','Interactive anatomy quizzes with labeled diagrams — muscles, bones, organs.','https://www.getbodysmart.com/','url','Health & PE','community','en','secondary',10,'https://www.getbodysmart.com/','GetBodySmart',MD5('getbodysmart'),0,1,'iarepo','','approved');

-- ── Languages ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Conjuguemos: Spanish Verb Practice','Practice Spanish verb conjugations with interactive exercises and instant feedback.','https://conjuguemos.com/verb/homework/es','url','Languages','community','es','secondary',5,'https://conjuguemos.com/','Conjuguemos',MD5('conjuguemos-es'),0,1,'iarepo','','approved'),
('Forvo: Pronunciation Dictionary','Listen to native speaker pronunciations of words in 300+ languages.','https://forvo.com/','url','Languages','community','en','secondary',5,'https://forvo.com/','Forvo',MD5('forvo'),0,1,'iarepo','','approved'),
('Youglish: English Pronunciation in Context','Search any English word and see it used in real YouTube videos with subtitles.','https://youglish.com/','url','Languages','community','en','secondary',5,'https://youglish.com/','YouGlish',MD5('youglish'),0,1,'iarepo','','approved');

-- ── Economics ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('FRED Economic Data','Explore economic data interactively — GDP, inflation, unemployment, interest rates from the Federal Reserve.','https://fred.stlouisfed.org/','url','Economics','community','en','university',19,'https://fred.stlouisfed.org/','FRED (St. Louis Fed)',MD5('fred-economics'),0,1,'iarepo','','approved'),
('Our World in Data','Interactive charts and data visualizations on global issues — poverty, health, education, environment.','https://ourworldindata.org/','url','Economics','community','en','ib',19,'https://ourworldindata.org/','Our World in Data',MD5('owid'),0,1,'iarepo','','approved'),
('Gapminder Tools','Explore world development data with interactive bubble charts — income, health, population.','https://www.gapminder.org/tools/','url','Economics','community','en','ib',19,'https://www.gapminder.org/','Gapminder',MD5('gapminder-tools'),0,1,'iarepo','','approved');

-- ── Tags ──
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'interactive' FROM resources WHERE id > 95;
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'free' FROM resources WHERE id > 95;
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, LOWER(source_name) FROM resources WHERE source_name IS NOT NULL AND id > 95;
