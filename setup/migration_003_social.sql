-- ================================================================
-- Migration 003: Schema fixes + Social layer tables
--
-- Aplicar (credenciales desde .env.php del servidor; el repo es público):
--   php setup/run_migration.php setup/migration_003_social.sql
-- ================================================================

-- ── Fix: Add 'prompt' to code_type ENUM (already used in admin/create.php) ──
ALTER TABLE resources MODIFY COLUMN code_type
    ENUM('html','url','embed','python','prompt','other') DEFAULT 'html';

-- ── Add like_count to resources (denormalized counter) ──
ALTER TABLE resources ADD COLUMN IF NOT EXISTS like_count INT DEFAULT 0 AFTER fork_count;

-- ── Add view_count index for sort by views ──
ALTER TABLE resources ADD INDEX IF NOT EXISTS idx_views (view_count DESC);

-- ═══════════════════════════════════════════════════════════════
-- LIKES — Teachers endorsing resources
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,
    user_id INT NOT NULL,
    user_name VARCHAR(150) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_like (resource_id, user_id),
    INDEX idx_resource (resource_id),
    INDEX idx_user (user_id),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════
-- COMMENTS — Public discussions on resources
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,
    user_id INT NOT NULL,
    user_name VARCHAR(150) NOT NULL,
    user_avatar VARCHAR(500) DEFAULT NULL,
    body TEXT NOT NULL,
    parent_id INT DEFAULT NULL COMMENT 'Reply to another comment',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_resource (resource_id),
    INDEX idx_parent (parent_id),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════
-- COLLECTIONS — Teacher-curated resource lists
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    is_public TINYINT(1) DEFAULT 1,
    item_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user (user_id),
    INDEX idx_public (is_public, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    collection_id INT NOT NULL,
    resource_id INT NOT NULL,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_item (collection_id, resource_id),
    INDEX idx_collection (collection_id),
    INDEX idx_resource (resource_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
