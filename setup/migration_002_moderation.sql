-- ================================================================
-- Migration 002: Content quality system
-- Adds moderation infrastructure — activated when registration opens
-- ================================================================

-- Content hash for duplicate detection
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS content_hash CHAR(32) NULL AFTER source_name;

ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS moderation_status ENUM('approved','under_review','rejected') DEFAULT 'approved' AFTER content_hash;

-- Index for fast hash lookups
ALTER TABLE resources
    ADD INDEX IF NOT EXISTS idx_content_hash (content_hash);

ALTER TABLE resources
    ADD INDEX IF NOT EXISTS idx_moderation (moderation_status);

-- Backfill hashes for existing resources
UPDATE resources SET content_hash = MD5(code_content) WHERE content_hash IS NULL AND code_content IS NOT NULL;

-- ═══════════════════════════════════════════════════════════════
-- REPORTS — Community-driven moderation
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS resource_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,

    -- Reporter (denormalized)
    reporter_user_id INT NOT NULL,
    reporter_display_name VARCHAR(150),

    reason ENUM('duplicate','spam','inappropriate','plagiarism','broken') NOT NULL,
    details TEXT,
    status ENUM('pending','resolved','dismissed') DEFAULT 'pending',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,

    INDEX idx_resource (resource_id),
    INDEX idx_status (status),
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
