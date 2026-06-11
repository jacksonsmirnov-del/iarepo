-- ================================================================
-- migration_007_email_notifications.sql
--
-- Adds email-notification support: per-user opt-out + a dedup log
-- so authors get an email when someone likes/forks/comments their
-- resource (without being spammed).
-- ================================================================

ALTER TABLE users
    ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN unsubscribe_token   VARCHAR(64) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS notification_log (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id INT NOT NULL,
    actor_user_id     INT NOT NULL,
    resource_id       INT NOT NULL,
    type              VARCHAR(20) NOT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_dedup (recipient_user_id, actor_user_id, resource_id, type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
