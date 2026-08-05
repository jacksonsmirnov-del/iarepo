-- ================================================================
-- Migration: Add categories, tags, and new resource columns
-- for iarepo.com platform expansion.
--
-- Aplicar (credenciales desde .env.php del servidor; el repo es público):
--   php setup/run_migration.php setup/migration_001_categories.sql
-- ================================================================

-- ═══════════════════════════════════════════════════════════════
-- CATEGORIES — Dynamic subject categories (not fixed ENUM)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(50) DEFAULT 'book-open',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial categories (icon = Lucide icon name, rendered via lucide-icons CDN)
INSERT IGNORE INTO categories (name, slug, icon, display_order) VALUES
('Physics', 'physics', 'atom', 1),
('Mathematics', 'mathematics', 'calculator', 2),
('Chemistry', 'chemistry', 'flask-conical', 3),
('Biology', 'biology', 'dna', 4),
('Languages', 'languages', 'languages', 5),
('Social Studies', 'social-studies', 'globe', 6),
('Computer Science', 'computer-science', 'code', 7),
('AI Prompts', 'ai-prompts', 'bot', 8),
('Art & Music', 'art-music', 'palette', 9),
('Health & PE', 'health-pe', 'heart-pulse', 10),
('General / Tools', 'general', 'wrench', 11);


-- ═══════════════════════════════════════════════════════════════
-- RESOURCE_TAGS — Free-form tags (like GitHub topics)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_tags (
    resource_id INT NOT NULL,
    tag VARCHAR(50) NOT NULL,
    PRIMARY KEY (resource_id, tag),
    INDEX idx_tag (tag),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════
-- ALTER resources — Add new columns for platform expansion
-- ═══════════════════════════════════════════════════════════════

-- Language of the resource content
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS lang ENUM('es','en','pt') DEFAULT 'es' AFTER topic_tag;

-- Educational level (free-form, not restricted ENUM)
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS level VARCHAR(50) DEFAULT 'general' AFTER lang;

-- Dynamic category (FK to categories table)
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS category_id INT NULL AFTER level;

-- Thumbnail for card previews
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS thumbnail_url VARCHAR(500) NULL AFTER category_id;

-- The original AI prompt used to generate this resource (optional)
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS source_prompt TEXT NULL AFTER thumbnail_url;

-- View counter for basic analytics
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS view_count INT DEFAULT 0 AFTER fork_count;

-- Expand code_type to support 'prompt' type
ALTER TABLE resources
    MODIFY COLUMN code_type ENUM('html','url','embed','python','prompt','other') DEFAULT 'html';

-- Add category index
ALTER TABLE resources
    ADD INDEX IF NOT EXISTS idx_category (category_id);

-- Add language index
ALTER TABLE resources
    ADD INDEX IF NOT EXISTS idx_lang (lang);

-- Map existing subject_area to category_id where possible
UPDATE resources r
    JOIN categories c ON (
        (r.subject_area = 'Physics' AND c.slug = 'physics') OR
        (r.subject_area = 'Física' AND c.slug = 'physics') OR
        (r.subject_area = 'General' AND c.slug = 'general')
    )
SET r.category_id = c.id
WHERE r.category_id IS NULL;

-- Set language based on existing subject_area naming convention
UPDATE resources SET lang = 'en' WHERE subject_area = 'Physics';
UPDATE resources SET lang = 'es' WHERE subject_area = 'Física';
