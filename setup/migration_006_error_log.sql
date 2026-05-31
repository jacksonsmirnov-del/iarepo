-- migration_006_error_log.sql
-- Client-side JS error log para detectar regresiones en producción.

CREATE TABLE IF NOT EXISTS client_error_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    message    VARCHAR(1000) NOT NULL,
    source     VARCHAR(200),
    lineno     INT DEFAULT 0,
    page_url   VARCHAR(500),
    user_agent VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_source (source(50))
);
