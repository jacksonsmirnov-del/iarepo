-- ================================================================
-- Migration 002: Content quality system
-- Adds moderation infrastructure — activated when registration opens
-- ================================================================

-- Content hash for duplicate detection
--
-- Esta sentencia decía `... NULL AFTER source_name`. Se ha quitado el AFTER
-- [2026-08-04]: `source_name` no la crea ningún fichero anterior a este por
-- número (la declara migration_000_prod_baseline.sql, que existe desde hoy),
-- así que sobre una BD reconstruida desde setup/ la ALTER moría con
-- ERROR 1054 «Unknown column 'source_name'» y arrastraba en cascada las
-- cuatro sentencias siguientes de este mismo fichero.
--
-- El AFTER sólo fija la POSICIÓN de la columna en la tabla: no afecta a
-- datos, tipos, índices ni a ninguna consulta. Quitarlo hace que esta
-- migración no dependa de que otro fichero se haya aplicado antes, que es
-- lo que importa cuando las corre un humano por SSH en el orden que le
-- parece. En producción no cambia nada: content_hash ya existe allí, luego
-- el IF NOT EXISTS se salta la sentencia entera (con AFTER y sin él).
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS content_hash CHAR(32) NULL;

-- El ENUM declaraba ENUM('approved','under_review','rejected') y le faltaba
-- 'pending_review' [añadido 2026-08-04], que es justo el valor que escribe el
-- alta de recursos con la moderación encendida:
--     api/resources.php:384  $moderationStatus = isModerationEnabled() ? 'pending_review' : 'approved';
--     api/resources.php:390  INSERT INTO resources (..., moderation_status)
--     cron/run.php:107       WHERE moderation_status = 'pending_review'
-- Con STRICT_TRANS_TABLES, insertar un valor que el ENUM no admite es
-- ERROR 1265 → 500 al crear un recurso. Hoy no salta porque OPEN_REGISTRATION
-- está apagado y entonces se escribe 'approved'; el día que se encienda, sí.
--
-- Se corrige AQUÍ, en el ADD COLUMN, y NO con un MODIFY COLUMN nuevo:
--   * en producción la columna ya existe → el IF NOT EXISTS salta la
--     sentencia entera y este cambio es un no-op absoluto;
--   * un MODIFY sí tocaría producción, y el ENUM real de allí no se ha
--     podido verificar (nadie puede consultarlo desde el repo): si fuese
--     MÁS ancho que este, un MODIFY lo estrecharía y truncaría datos.
-- La consecuencia es que este arreglo sólo llega a las BD reconstruidas
-- desde setup/ (dev, CI, restauración de backup). Para producción, el día
-- que se abra el registro hay que comprobar el tipo real y, si le falta el
-- valor, ampliarlo A MANO con:
--     SHOW COLUMNS FROM resources LIKE 'moderation_status';
--     ALTER TABLE resources MODIFY COLUMN moderation_status
--         ENUM('approved','under_review','rejected','pending_review') DEFAULT 'approved';
--   (no se deja como sentencia viva: ampliar un ENUM reescribe la tabla y
--    esto es un hosting compartido donde push = producción en vivo).
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS moderation_status ENUM('approved','under_review','rejected','pending_review') DEFAULT 'approved' AFTER content_hash;

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
