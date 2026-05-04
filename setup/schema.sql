-- ================================================================
-- Resources Platform — Database Schema
--
-- DB: u403412230_resources
-- Run once: mysql -u u403412230_ib_ebr -p u403412230_resources < schema.sql
--
-- All author/editor info is DENORMALIZED from JWT tokens.
-- No foreign keys to Campus databases — fully independent.
-- ================================================================

-- ═══════════════════════════════════════════════════════════════
-- RESOURCES — The main content table
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    code_content MEDIUMTEXT,
    code_type ENUM('html','url','embed','python','other') DEFAULT 'html',
    subject_area VARCHAR(100),
    topic_tag VARCHAR(100),

    -- Author identity (denormalized from JWT — no FK to Campus)
    author_tenant_id INT NOT NULL,
    author_user_id INT NOT NULL,
    author_display_name VARCHAR(150) NOT NULL,
    author_tenant_name VARCHAR(150) NOT NULL,

    -- Visibility levels
    -- draft:     only the author sees it
    -- area:      teachers in the same subject_area + same tenant
    -- school:    all teachers in the same tenant
    -- community: everyone (all tenants)
    visibility ENUM('draft','area','school','community') DEFAULT 'draft',

    -- Versioning
    current_version INT DEFAULT 1,

    -- Denormalized counters (updated via triggers or app logic)
    use_count INT DEFAULT 0,
    fork_count INT DEFAULT 0,

    -- Fork tracking
    fork_of INT NULL COMMENT 'FK to resources.id of the original',

    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_area (subject_area),
    INDEX idx_author (author_tenant_id, author_user_id),
    INDEX idx_visibility (visibility),
    INDEX idx_fork (fork_of),
    INDEX idx_popular (use_count DESC),
    INDEX idx_created (created_at DESC),
    FULLTEXT idx_search (title, description, topic_tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════
-- VERSIONS — Change history for each resource
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,
    version_number INT NOT NULL,
    code_content MEDIUMTEXT,
    change_description VARCHAR(500),

    -- Editor identity (denormalized)
    editor_user_id INT NOT NULL,
    editor_display_name VARCHAR(150),
    editor_tenant_name VARCHAR(150),

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_resource_ver (resource_id, version_number),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════
-- USAGE — Track how resources are used (metrics for thesis)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,

    -- Who used it (denormalized)
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    user_display_name VARCHAR(150),
    tenant_name VARCHAR(150),

    -- What they did
    usage_type ENUM('presented','sent','forked','endorsed') NOT NULL,
    classroom_name VARCHAR(100) NULL,
    notes TEXT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_resource (resource_id),
    INDEX idx_user (tenant_id, user_id),
    INDEX idx_type (usage_type),
    INDEX idx_date (created_at),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════
-- SUGGESTIONS — Private feedback between teachers
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,

    -- Suggester (denormalized)
    user_id INT NOT NULL,
    user_display_name VARCHAR(150),
    tenant_name VARCHAR(150),

    suggestion TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_resource (resource_id),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════
-- ASSIGNMENTS — Resources sent to student classrooms
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,

    -- Classroom info (denormalized from Campus)
    tenant_id INT NOT NULL,
    classroom_id INT NOT NULL COMMENT 'ID in Campus tenant DB (referential)',
    classroom_name VARCHAR(100),

    -- Who assigned it (denormalized)
    assigned_by_user_id INT NOT NULL,
    assigned_by_name VARCHAR(150),

    available_from DATETIME DEFAULT CURRENT_TIMESTAMP,
    available_until DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_resource (resource_id),
    INDEX idx_classroom (tenant_id, classroom_id),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
