-- ================================================================
-- seed_catalog_v3.sql — More platforms: Didax, Math Learning Center,
-- OSP Singapore, MathsBot, ChemCollective, Toy Theater
-- ================================================================
-- author_tenant_name va explícito en cada fila: la columna es NOT NULL
-- SIN DEFAULT y con STRICT_TRANS_TABLES (producción: MariaDB 11.8) el
-- INSERT entero aborta con ERROR 1364 si se omite. Valor '' = el mismo
-- que escribe la app para recursos de comunidad (api/resources.php).

-- ── Didax Virtual Manipulatives — Math (Primary/Elementary) ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Base Ten Blocks','Explore place value with interactive base-10 blocks. Drag and drop ones, tens, hundreds, and thousands.','https://www.didax.com/apps/base-ten-blocks','url','Mathematics','community','en','primary',3,'https://www.didax.com/apps/base-ten-blocks','Didax',MD5('didax-base-ten'),0,1,'iarepo','','approved'),
('Fraction Tiles','Visualize fractions with colored tiles on a number line. Compare and order fractions.','https://www.didax.com/apps/fraction-tiles','url','Mathematics','community','en','primary',3,'https://www.didax.com/apps/fraction-tiles','Didax',MD5('didax-fraction-tiles'),0,1,'iarepo','','approved'),
('Algebra Tiles','Model algebraic expressions and equations with virtual algebra tiles.','https://www.didax.com/apps/algebra-tiles','url','Mathematics','community','en','secondary',3,'https://www.didax.com/apps/algebra-tiles','Didax',MD5('didax-algebra-tiles'),0,1,'iarepo','','approved'),
('Pattern Blocks','Create geometric patterns and explore symmetry with colorful pattern blocks.','https://www.didax.com/apps/pattern-blocks','url','Mathematics','community','en','primary',3,'https://www.didax.com/apps/pattern-blocks','Didax',MD5('didax-pattern-blocks'),0,1,'iarepo','','approved'),
('Unifix Cubes','Build number towers and explore counting, addition, and subtraction.','https://www.didax.com/apps/unifix-cubes','url','Mathematics','community','en','primary',3,'https://www.didax.com/apps/unifix-cubes','Didax',MD5('didax-unifix'),0,1,'iarepo','','approved');

-- ── Math Learning Center — Free Apps ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Number Frames','Organize counters in frames of 5, 10, and 20 to develop number sense.','https://apps.mathlearningcenter.org/number-frames/','url','Mathematics','community','en','primary',3,'https://www.mathlearningcenter.org/apps/number-frames','Math Learning Center',MD5('mlc-number-frames'),0,1,'iarepo','','approved'),
('Geoboard','Stretch bands around pegs to form shapes and explore area, perimeter, and angles.','https://apps.mathlearningcenter.org/geoboard/','url','Mathematics','community','en','primary',3,'https://www.mathlearningcenter.org/apps/geoboard','Math Learning Center',MD5('mlc-geoboard'),0,1,'iarepo','','approved'),
('Number Pieces','Work with base-ten blocks to model multi-digit operations visually.','https://apps.mathlearningcenter.org/number-pieces/','url','Mathematics','community','en','primary',3,'https://www.mathlearningcenter.org/apps/number-pieces','Math Learning Center',MD5('mlc-number-pieces'),0,1,'iarepo','','approved'),
('Number Line','Jump, slide, and bounce along a number line to model addition and subtraction.','https://apps.mathlearningcenter.org/number-line/','url','Mathematics','community','en','primary',3,'https://www.mathlearningcenter.org/apps/number-line','Math Learning Center',MD5('mlc-number-line'),0,1,'iarepo','','approved'),
('Money Pieces','Count and make change with US coins and bills in an interactive workspace.','https://apps.mathlearningcenter.org/money-pieces/','url','Mathematics','community','en','primary',3,'https://www.mathlearningcenter.org/apps/money-pieces','Math Learning Center',MD5('mlc-money-pieces'),0,1,'iarepo','','approved');

-- ── Open Source Physics @ Singapore (iwant2study.org) ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Vernier Caliper Simulator','Practice reading a vernier caliper with interactive jaws and scale.','https://iwant2study.org/lookangejss/02measurements/ejss_model_VernierCaliper10/VernierCaliper10_Simulation.xhtml','url','Physics','community','en','secondary',1,'https://iwant2study.org/','OSP Singapore',MD5('osp-vernier'),0,1,'iarepo','','approved'),
('Micrometer Screw Gauge','Learn to read a micrometer with interactive thimble and anvil.','https://iwant2study.org/lookangejss/02measurements/ejss_model_Micrometer06/Micrometer06_Simulation.xhtml','url','Physics','community','en','secondary',1,'https://iwant2study.org/','OSP Singapore',MD5('osp-micrometer'),0,1,'iarepo','','approved'),
('Free Body Diagram','Draw and analyze free body diagrams with interactive force arrows.','https://iwant2study.org/lookangejss/04dynamics/ejss_model_FreeBodyDiagram02/FreeBodyDiagram02_Simulation.xhtml','url','Physics','community','en','secondary',1,'https://iwant2study.org/','OSP Singapore',MD5('osp-fbd'),0,1,'iarepo','','approved'),
('DC Motor Model','Explore how a DC motor works with interactive coil, magnets, and commutator.','https://iwant2study.org/lookangejss/09magnetism/ejss_model_DCMotor01/DCMotor01_Simulation.xhtml','url','Physics','community','en','secondary',1,'https://iwant2study.org/','OSP Singapore',MD5('osp-dc-motor'),0,1,'iarepo','','approved'),
('Bohr Model of the Atom','Visualize electron energy levels and photon emission in the Bohr model.','https://iwant2study.org/lookangejss/11quantumphysics/ejss_model_BohrModelAtom01/BohrModelAtom01_Simulation.xhtml','url','Physics','community','en','ib',1,'https://iwant2study.org/','OSP Singapore',MD5('osp-bohr'),0,1,'iarepo','','approved');

-- ── MathsBot — Interactive Math Tools ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Interactive Number Line','Drag and place numbers on an adjustable number line. Supports integers, fractions, and decimals.','https://mathsbot.com/manipulatives/numberLine','url','Mathematics','community','en','primary',3,'https://mathsbot.com/manipulatives/numberLine','MathsBot',MD5('mathsbot-numberline'),0,1,'iarepo','','approved'),
('Virtual Dice Roller','Roll customizable dice for probability experiments. Supports multiple dice and sides.','https://mathsbot.com/manipulatives/dice','url','Mathematics','community','en','primary',3,'https://mathsbot.com/manipulatives/dice','MathsBot',MD5('mathsbot-dice'),0,1,'iarepo','','approved'),
('Fraction Wall','Compare fractions visually using a fraction wall diagram.','https://mathsbot.com/manipulatives/fractionWall','url','Mathematics','community','en','primary',3,'https://mathsbot.com/manipulatives/fractionWall','MathsBot',MD5('mathsbot-fractionwall'),0,1,'iarepo','','approved');

-- ── ChemCollective — Carnegie Mellon Chemistry ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Virtual Lab: Stoichiometry','Perform stoichiometry experiments in a virtual chemistry lab. Mix solutions and measure results.','https://chemcollective.org/vlab/vlab.php','url','Chemistry','community','en','ib',2,'https://chemcollective.org/','ChemCollective (CMU)',MD5('chemcoll-vlab'),0,1,'iarepo','','approved'),
('Equilibrium: Le Chatelier','Explore how changes in concentration, temperature, and pressure affect chemical equilibrium.','https://chemcollective.org/activities/tutorials/equilibrium','url','Chemistry','community','en','ib',2,'https://chemcollective.org/','ChemCollective (CMU)',MD5('chemcoll-equilibrium'),0,1,'iarepo','','approved');

-- ── Toy Theater — Elementary Math/Science ──
INSERT INTO resources (title, description, code_content, code_type, subject_area, visibility, lang, level, category_id, source_url, source_name, content_hash, author_tenant_id, author_user_id, author_display_name, author_tenant_name, moderation_status) VALUES
('Clock: Tell Time','Interactive analog clock for learning to tell time. Drag hands to set times.','https://toytheater.com/clock/','url','Mathematics','community','en','primary',3,'https://toytheater.com/clock/','Toy Theater',MD5('toytheater-clock'),0,1,'iarepo','','approved'),
('Balance Scale','Place weights on a virtual balance scale to explore equality and comparison.','https://toytheater.com/balance-scale/','url','Mathematics','community','en','primary',3,'https://toytheater.com/balance-scale/','Toy Theater',MD5('toytheater-balance'),0,1,'iarepo','','approved'),
('Hundred Chart','Interactive hundred chart for exploring number patterns and skip counting.','https://toytheater.com/hundred-chart/','url','Mathematics','community','en','primary',3,'https://toytheater.com/hundred-chart/','Toy Theater',MD5('toytheater-hundred'),0,1,'iarepo','','approved');

-- ── Tags ──
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'interactive' FROM resources WHERE id > 72;
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'free' FROM resources WHERE id > 72;
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, LOWER(source_name) FROM resources WHERE source_name IS NOT NULL AND id > 72;
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'manipulative' FROM resources WHERE source_name IN ('Didax','Math Learning Center','MathsBot','Toy Theater') AND id > 72;
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'elementary' FROM resources WHERE level = 'primary' AND id > 72;
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'simulation' FROM resources WHERE source_name IN ('OSP Singapore','ChemCollective (CMU)') AND id > 72;
