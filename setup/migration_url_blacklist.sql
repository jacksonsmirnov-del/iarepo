-- Migration: URL Blacklist
-- Date: 2026-05-20
-- Purpose: Track retired URLs to prevent re-uploading broken/blocked resources
-- Note: Populated automatically when resources are soft-deleted via link checker

CREATE TABLE IF NOT EXISTS url_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(500) NOT NULL,
    domain VARCHAR(200) NOT NULL,
    original_title VARCHAR(255),
    original_source VARCHAR(150),
    reason ENUM('broken','forbidden','timeout','gone','blocked') DEFAULT 'broken',
    http_code INT DEFAULT 0,
    retired_resource_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_url (url(255)),
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
