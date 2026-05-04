-- ================================================================
-- Seed: Populate catalog with PhET, GeoGebra, and Desmos resources
-- Run: mysql -u u403412230_ib_ebr -p u403412230_resources < setup/seed_catalog.sql
-- ================================================================

-- PhET Simulations (code_type = 'url')
INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag, lang, level, category_id, visibility, author_tenant_id, author_user_id, author_display_name, author_tenant_name) VALUES
('Forces and Motion: Basics', 'Explore forces and motion by pushing objects. Create applied force, friction, and see how they affect acceleration.', 'https://phet.colorado.edu/sims/html/forces-and-motion-basics/latest/forces-and-motion-basics_en.html', 'url', 'Physics', 'Forces,Newton Laws', 'en', 'secondary', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Energy Skate Park', 'Learn about conservation of energy with a skater. Build tracks, ramps, and jumps for the skater and view kinetic, potential, and thermal energy.', 'https://phet.colorado.edu/sims/html/energy-skate-park/latest/energy-skate-park_en.html', 'url', 'Physics', 'Energy,Conservation', 'en', 'secondary', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Projectile Motion', 'Blast a car out of a cannon, and challenge yourself to hit a target. Learn about projectile motion by firing various objects.', 'https://phet.colorado.edu/sims/html/projectile-motion/latest/projectile-motion_en.html', 'url', 'Physics', 'Kinematics,Projectile', 'en', 'secondary', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Wave Interference', 'Make waves with a dripping faucet, audio speaker, or laser. Explore interference patterns of two sources.', 'https://phet.colorado.edu/sims/html/wave-interference/latest/wave-interference_en.html', 'url', 'Physics', 'Waves,Interference', 'en', 'ib', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Gravity and Orbits', 'Move the sun, earth, moon, and space station to see how gravity affects their orbits.', 'https://phet.colorado.edu/sims/html/gravity-and-orbits/latest/gravity-and-orbits_en.html', 'url', 'Physics', 'Gravity,Orbits', 'en', 'secondary', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Pendulum Lab', 'Play with one or two pendulums and discover how the period of a simple pendulum depends on length, mass, and amplitude.', 'https://phet.colorado.edu/sims/html/pendulum-lab/latest/pendulum-lab_en.html', 'url', 'Physics', 'Oscillations,Pendulum', 'en', 'ib', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Circuit Construction Kit: DC', 'Build circuits with batteries, resistors, light bulbs, fuses, and switches. Take measurements with ammeter and voltmeter.', 'https://phet.colorado.edu/sims/html/circuit-construction-kit-dc/latest/circuit-construction-kit-dc_en.html', 'url', 'Physics', 'Circuits,Electricity', 'en', 'secondary', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Coulombs Law', 'Visualize the electrostatic force between two charged objects. Adjust the charges and distance to see how they affect the force.', 'https://phet.colorado.edu/sims/html/coulombs-law/latest/coulombs-law_en.html', 'url', 'Physics', 'Electrostatics,Coulomb', 'en', 'ib', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Waves Intro', 'Even observe a string vibrate in slow motion. Wiggle the end of the string and make waves, or adjust the frequency and amplitude.', 'https://phet.colorado.edu/sims/html/waves-intro/latest/waves-intro_en.html', 'url', 'Physics', 'Waves,Introduction', 'en', 'secondary', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Faradays Law', 'Investigate Faraday''s law and how a changing magnetic flux can produce a flow of electricity.', 'https://phet.colorado.edu/sims/html/faradays-law/latest/faradays-law_en.html', 'url', 'Physics', 'Electromagnetism,Induction', 'en', 'ib', 1, 'community', 1, 1, 'iarepo', 'iarepo.com'),

-- PhET Chemistry
('Build a Molecule', 'Build molecules from atoms. Explore molecular structures and learn about molecular formulas.', 'https://phet.colorado.edu/sims/html/build-a-molecule/latest/build-a-molecule_en.html', 'url', 'Chemistry', 'Molecules,Bonds', 'en', 'secondary', 3, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Balancing Chemical Equations', 'Balance chemical equations by adjusting coefficients. Practice your skills.', 'https://phet.colorado.edu/sims/html/balancing-chemical-equations/latest/balancing-chemical-equations_en.html', 'url', 'Chemistry', 'Equations,Stoichiometry', 'en', 'secondary', 3, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Concentration', 'Observe how concentration changes as solute is added or water is removed. Explore molarity.', 'https://phet.colorado.edu/sims/html/concentration/latest/concentration_en.html', 'url', 'Chemistry', 'Solutions,Concentration', 'en', 'secondary', 3, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('pH Scale', 'Test the pH of everyday liquids like coffee, spit, and soap. Explore acids, bases, and neutral solutions.', 'https://phet.colorado.edu/sims/html/ph-scale/latest/ph-scale_en.html', 'url', 'Chemistry', 'Acids,Bases,pH', 'en', 'secondary', 3, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('States of Matter', 'Watch different types of molecules form a solid, liquid, or gas. Explore phase changes.', 'https://phet.colorado.edu/sims/html/states-of-matter/latest/states-of-matter_en.html', 'url', 'Chemistry', 'States,Phase Changes', 'en', 'secondary', 3, 'community', 1, 1, 'iarepo', 'iarepo.com'),

-- PhET Biology
('Natural Selection', 'Explore how natural selection works by controlling the environment, mutation rate, and population size.', 'https://phet.colorado.edu/sims/html/natural-selection/latest/natural-selection_en.html', 'url', 'Biology', 'Evolution,Natural Selection', 'en', 'secondary', 4, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Gene Expression Essentials', 'Express your genes! Explore transcription and translation with this interactive simulation.', 'https://phet.colorado.edu/sims/html/gene-expression-essentials/latest/gene-expression-essentials_en.html', 'url', 'Biology', 'Genetics,DNA', 'en', 'ib', 4, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Neuron', 'Stimulate a neuron and monitor what happens. Pause, rewind, and move forward in time to observe action potentials.', 'https://phet.colorado.edu/sims/html/neuron/latest/neuron_en.html', 'url', 'Biology', 'Neuroscience,Action Potential', 'en', 'ib', 4, 'community', 1, 1, 'iarepo', 'iarepo.com'),

-- PhET Math
('Graphing Lines', 'Explore the world of lines. Investigate the relationships between linear equations, slope, and graphs.', 'https://phet.colorado.edu/sims/html/graphing-lines/latest/graphing-lines_en.html', 'url', 'Mathematics', 'Linear Functions,Slope', 'en', 'secondary', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Area Builder', 'Build shapes and explore the relationship between area and perimeter.', 'https://phet.colorado.edu/sims/html/area-builder/latest/area-builder_en.html', 'url', 'Mathematics', 'Geometry,Area', 'en', 'primary', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Fractions: Intro', 'Build fractions using fun interactive tools. Match shapes and numbers to earn stars.', 'https://phet.colorado.edu/sims/html/fractions-intro/latest/fractions-intro_en.html', 'url', 'Mathematics', 'Fractions,Number Sense', 'en', 'primary', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Function Builder', 'Build functions by combining simple mathematical operations. See how inputs map to outputs.', 'https://phet.colorado.edu/sims/html/function-builder/latest/function-builder_en.html', 'url', 'Mathematics', 'Functions,Algebra', 'en', 'secondary', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Vector Addition', 'Explore vectors in 1D and 2D. Experiment with vector addition and learn about components.', 'https://phet.colorado.edu/sims/html/vector-addition/latest/vector-addition_en.html', 'url', 'Mathematics', 'Vectors,Components', 'en', 'ib', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),

-- GeoGebra Activities (code_type = 'url')
('Pythagorean Theorem Proof', 'Visual proof of the Pythagorean theorem. Drag the vertices and see how the squares on each side relate.', 'https://www.geogebra.org/material/iframe/id/RMGpBV5n', 'url', 'Mathematics', 'Geometry,Pythagoras', 'en', 'secondary', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Unit Circle', 'Explore the unit circle and trigonometric functions interactively.', 'https://www.geogebra.org/material/iframe/id/UKdJfxbr', 'url', 'Mathematics', 'Trigonometry,Unit Circle', 'en', 'secondary', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('3D Graphing Calculator', 'Graph functions in 3D space. Rotate and explore mathematical surfaces.', 'https://www.geogebra.org/3d', 'url', 'Mathematics', '3D Graphs,Calculus', 'en', 'university', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Derivative Visualizer', 'See the derivative of a function as the slope of the tangent line at each point.', 'https://www.geogebra.org/material/iframe/id/ANEFpnMa', 'url', 'Mathematics', 'Calculus,Derivatives', 'en', 'university', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Linear Transformation', 'Visualize how matrices transform vectors and shapes in 2D space.', 'https://www.geogebra.org/material/iframe/id/mCDMdpSH', 'url', 'Mathematics', 'Linear Algebra,Matrices', 'en', 'university', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),

-- Desmos Activities (code_type = 'url')
('Graphing Calculator', 'Desmos graphing calculator — plot functions, create tables, add sliders, animate graphs, and more.', 'https://www.desmos.com/calculator', 'url', 'Mathematics', 'Functions,Graphing', 'en', 'general', 2, 'community', 1, 1, 'iarepo', 'iarepo.com'),
('Geometry Tool', 'Desmos interactive geometry tool — construct, measure, and explore geometric shapes.', 'https://www.desmos.com/geometry', 'url', 'Mathematics', 'Geometry,Construction', 'en', 'general', 2, 'community', 1, 1, 'iarepo', 'iarepo.com');

-- Tags for PhET resources
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'phet' FROM resources WHERE author_display_name = 'iarepo' AND code_content LIKE '%phet.colorado.edu%';
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'simulation' FROM resources WHERE author_display_name = 'iarepo' AND code_type = 'url';
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'interactive' FROM resources WHERE author_display_name = 'iarepo' AND code_type = 'url';
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'geogebra' FROM resources WHERE author_display_name = 'iarepo' AND code_content LIKE '%geogebra.org%';
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'desmos' FROM resources WHERE author_display_name = 'iarepo' AND code_content LIKE '%desmos.com%';
INSERT IGNORE INTO resource_tags (resource_id, tag)
SELECT id, 'free' FROM resources WHERE author_display_name = 'iarepo';
