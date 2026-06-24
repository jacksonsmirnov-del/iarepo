-- ================================================================
-- migration_009_student_role_favorites.sql
--
-- Fase 1a — captación de estudiantes:
--   1) Añade el rol 'student' al ENUM de users.role.
--      (Añadir un valor al ENUM NO toca las filas existentes.)
--   2) Crea resource_favorites: guardado rápido y privado de recursos,
--      el "gancho" para que un estudiante se registre.
-- ================================================================

ALTER TABLE users
    MODIFY COLUMN role ENUM('student','teacher','admin','superadmin') DEFAULT 'teacher';

CREATE TABLE IF NOT EXISTS resource_favorites (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    resource_id INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_resource (user_id, resource_id),
    INDEX idx_resource (resource_id),

    CONSTRAINT fk_fav_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_fav_resource FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
