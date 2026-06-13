-- ================================================================
-- migration_008_notifications_seen.sql
--
-- Tracks when a user last opened their in-app notifications bell, so
-- the dashboard can show an unread count (activity newer than this).
-- ================================================================

ALTER TABLE users
    ADD COLUMN notifications_seen_at DATETIME DEFAULT NULL;
