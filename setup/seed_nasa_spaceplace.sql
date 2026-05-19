-- ============================================================
-- seed_nasa_spaceplace.sql — NASA Space Place Interactive Games
--
-- Source: https://spaceplace.nasa.gov/menu/play/
-- License: Public Domain (US Government work)
-- Note: These are URL-type resources (X-Frame-Options: SAMEORIGIN)
--       They open in a new tab via the viewer.
-- ============================================================

-- Ensure Physics category exists (id=1)
-- Ensure we have a "Space & Astronomy" or similar category

-- Fix Economics category missing slug
UPDATE categories SET slug = 'economics' WHERE name = 'Economics' AND slug = '';

INSERT INTO categories (name, slug, icon)
SELECT 'Space & Astronomy', 'space-astronomy', 'rocket'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Space & Astronomy');

SET @space_cat = (SELECT id FROM categories WHERE name = 'Space & Astronomy');

-- ── NASA Space Place Games ──────────────────────────────────

INSERT INTO resources (title, description, code_content, code_type, subject_area, topic_tag, lang, level, category_id, visibility, source_name, source_url, author_tenant_id, author_user_id, author_display_name, author_tenant_name)
VALUES
('Explore Mars: A Mars Rover Game',
 'Drive around the Red Planet and gather information in this fun coding game! Control a Mars rover, navigate terrain, and collect data — perfect for learning about Mars exploration and basic programming concepts.',
 'https://spaceplace.nasa.gov/explore-mars/en/',
 'url', 'Physics', 'Mars exploration', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Helios: How the Sun Makes Energy',
 'Interactive game about stellar nucleosynthesis. Learn how the Sun produces energy through nuclear fusion — match protons to create helium and release energy. Great for understanding stellar physics.',
 'https://spaceplace.nasa.gov/helios-game/en/',
 'url', 'Physics', 'Nuclear fusion', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Space Volcanoes Explorer',
 'Explore the many volcanoes in our solar system! Compare volcanic activity on Earth, Mars, Venus, Io, and other bodies. Interactive visualization of geological processes across the solar system.',
 'https://spaceplace.nasa.gov/volcanoes/en/',
 'url', 'Physics', 'Volcanism', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Ocean Currents: Go With the Flow',
 'Use heat and salt to navigate a submarine through ocean currents to find treasure. Learn about thermohaline circulation, density, and how temperature and salinity drive ocean currents.',
 'https://spaceplace.nasa.gov/ocean-currents/en/',
 'url', 'Physics', 'Fluid dynamics', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('DSN Uplink-Downlink: Deep Space Network Game',
 'Help NASA''s big antennas gather data from spacecraft across the solar system. Learn about the Deep Space Network, radio communications, signal processing, and how we communicate with distant spacecraft.',
 'https://spaceplace.nasa.gov/dsn-game/en/',
 'url', 'Physics', 'Radio communications', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Solar System Switch-a-Roo',
 'Put clues together to identify planets and moons in our solar system. Interactive quiz game that teaches planetary science, orbital mechanics, and the unique characteristics of each celestial body.',
 'https://spaceplace.nasa.gov/switch-a-roo/en/',
 'url', 'Physics', 'Solar system', 'en', 'primary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('CubeSat Builder: Build a NASA Spacecraft!',
 'Design and build your own CubeSat spacecraft! Choose components like solar panels, antennas, and instruments. Learn about satellite engineering, power systems, and mission design.',
 'https://spaceplace.nasa.gov/cubesat-builder-game/en/',
 'url', 'Physics', 'Satellite engineering', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Snap it! An Eclipse Photo Adventure',
 'Help the Traveler snap photos of a solar eclipse! Learn about the geometry of eclipses, the Sun-Earth-Moon alignment, umbra and penumbra, and safe solar observation techniques.',
 'https://spaceplace.nasa.gov/snap-it-eclipse-game/en/',
 'url', 'Physics', 'Eclipses', 'en', 'primary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Relay: Laser-Based Space Communications',
 'Learn about laser-based space communications (optical communications) in this interactive game. Understand how NASA is developing faster data transmission using lasers instead of radio waves.',
 'https://spaceplace.nasa.gov/relay-laser-communications-game/en/',
 'url', 'Physics', 'Optical communications', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Your Name in Landsat',
 'Spell out your name using real satellite imagery captured by NASA''s Landsat Earth observation satellites! Each letter is formed by geographic features visible from space. Learn about remote sensing and Earth observation.',
 'https://spaceplace.nasa.gov/your-name-in-landsat/en/',
 'url', 'Physics', 'Remote sensing', 'en', 'primary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Name That Nebula (Hubble)',
 'Can you identify famous nebulae captured by the Hubble Space Telescope? Test your knowledge of deep-space objects including the Crab Nebula, Eagle Nebula, and more.',
 'https://spaceplace.nasa.gov/hubble-name-that-nebula/en/',
 'url', 'Physics', 'Nebulae', 'en', 'secondary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('What Did Hubble See on Your Birthday?',
 'Discover what astronomical image the Hubble Space Telescope captured on your birthday! Enter your birth date and see a stunning deep-space photograph with scientific context.',
 'https://spaceplace.nasa.gov/what-did-hubble-see-on-your-birthday/en/',
 'url', 'Physics', 'Hubble telescope', 'en', 'primary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('Climate Time Machine',
 'See into the past and ahead to the future with NASA''s Climate Time Machine. Visualize changes in sea ice, sea level, carbon emissions, and global temperature over time.',
 'https://climatekids.nasa.gov/time-machine/',
 'url', 'Physics', 'Climate change', 'en', 'secondary', @space_cat, 'community',
 'NASA Climate Kids', 'https://climatekids.nasa.gov/', 0, 1, 'iarepo', ''),

('Coral Bleaching Simulator',
 'Interactive simulation showing how environmental conditions affect coral reefs. Adjust temperature, pollution, and other factors to see their impact on coral health and marine ecosystems.',
 'https://climatekids.nasa.gov/coral-bleaching/',
 'url', 'Biology', 'Marine ecosystems', 'en', 'secondary', @space_cat, 'community',
 'NASA Climate Kids', 'https://climatekids.nasa.gov/', 0, 1, 'iarepo', ''),

('Roman Space Observer Game',
 'Catch as many astrophysical objects and phenomena as possible! Learn about the Nancy Grace Roman Space Telescope and the types of cosmic objects it will study.',
 'https://roman.gsfc.nasa.gov/game.html',
 'url', 'Physics', 'Space telescopes', 'en', 'secondary', @space_cat, 'community',
 'NASA GSFC', 'https://roman.gsfc.nasa.gov/', 0, 1, 'iarepo', ''),

('Mars Rover Game',
 'Mars Rover drivers wanted! Search for water as your rover climbs up and down hills to explore Mars. Learn about Mars geology, rover navigation, and the search for water on the Red Planet.',
 'https://mars.nasa.gov/gamee-rover/',
 'url', 'Physics', 'Mars exploration', 'en', 'primary', @space_cat, 'community',
 'NASA Mars Program', 'https://mars.nasa.gov/', 0, 1, 'iarepo', ''),

('Space Lotería',
 'Play a space-themed version of the classic Mexican game Lotería! Each card features a different space object or concept — from black holes to nebulae. Fun cultural + science mashup.',
 'https://spaceplace.nasa.gov/space-loteria/en/',
 'url', 'Physics', 'Space objects', 'en', 'primary', @space_cat, 'community',
 'NASA Space Place', 'https://spaceplace.nasa.gov/', 0, 1, 'iarepo', ''),

('NASA Home and City',
 'Trace space technology back to your daily life! Explore an interactive city and home to discover how NASA innovations are used in everyday products and technologies.',
 'https://homeandcity.nasa.gov/',
 'url', 'Physics', 'Space technology', 'en', 'secondary', @space_cat, 'community',
 'NASA', 'https://homeandcity.nasa.gov/', 0, 1, 'iarepo', ''),

('Scope It Out! (James Webb)',
 'Learn about telescopes with the James Webb Space Telescope team! Includes an introduction to how telescopes work and two matching games about space observation.',
 'https://www.jwst.nasa.gov/content/features/educational/scopeItOut/index.html',
 'url', 'Physics', 'Telescopes', 'en', 'secondary', @space_cat, 'community',
 'NASA JWST', 'https://www.jwst.nasa.gov/', 0, 1, 'iarepo', ''),

('ViewSpace: Explore the Universe',
 'Explore the universe with interactives and videos from NASA, ESA, and other space agencies. Interactive visualizations of galaxies, nebulae, exoplanets, and cosmic phenomena.',
 'https://viewspace.org/',
 'url', 'Physics', 'Universe exploration', 'en', 'secondary', @space_cat, 'community',
 'NASA / STScI', 'https://viewspace.org/', 0, 1, 'iarepo', '');

-- Done! Resources are now available in the catalog.
