-- migration_005_rate_limits.sql
-- IP-based rate limiting table for API endpoints.
-- Rows are cleaned up probabilistically by the rateLimit() PHP function.

CREATE TABLE IF NOT EXISTS api_rate_limits (
    ip           VARCHAR(45)  NOT NULL,
    endpoint     VARCHAR(80)  NOT NULL,
    requests     INT          NOT NULL DEFAULT 1,
    window_start DATETIME     NOT NULL,
    PRIMARY KEY (ip, endpoint),
    INDEX idx_window (window_start)
);
