-- ================================================================
-- Users table for iarepo — Google Sign-In users
-- ================================================================

CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    google_id       VARCHAR(255) UNIQUE NOT NULL,
    email           VARCHAR(255) UNIQUE NOT NULL,
    name            VARCHAR(150) NOT NULL,
    avatar_url      VARCHAR(500) DEFAULT NULL,
    role            ENUM('teacher','admin','superadmin') DEFAULT 'teacher',
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login      DATETIME DEFAULT NULL,

    INDEX idx_email (email),
    INDEX idx_google (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
