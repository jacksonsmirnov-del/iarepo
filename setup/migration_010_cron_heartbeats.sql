-- ================================================================
-- migration_010_cron_heartbeats.sql
--
-- LATIDOS DE LOS CRON — que un cron muerto se vea.
--
-- POR QUÉ EXISTE
--   El link checker dejó de correr el 2026-05-30 y nadie lo supo en 66 días.
--   No falló ruidosamente: simplemente dejó de ser invocado. No había logs, ni
--   una fila en ninguna tabla, ni nada que envejeciera de forma visible. La
--   única huella era MAX(link_checked_at) en `resources`, que hay que salir a
--   buscar a mano sabiendo ya que existe el problema.
--
--   Esta tabla convierte "no ha pasado nada" en un dato observable: cada job
--   deja constancia de CUÁNDO corrió por última vez, cuánto tardó, cuántos
--   elementos procesó y si terminó bien o mal. api/health.php publica la
--   antigüedad de cada latido y quality/smoke_test.sh la pone en rojo cuando
--   supera el periodo del job por 3.
--
-- UNA FILA POR JOB, NO UN HISTÓRICO
--   Deliberado. Un log crece y necesita una purga… que sería otro cron, o sea
--   otra cosa que puede morir en silencio (exactamente el problema que se está
--   arreglando). Con PRIMARY KEY (job) la tabla tiene tantas filas como jobs
--   —hoy dos— y no crece nunca. Los contadores acumulados (run_count,
--   error_count) y las marcas last_ok_at / last_error_at conservan lo poco del
--   histórico que de verdad se consulta.
--
-- IDEMPOTENTE
--   CREATE TABLE IF NOT EXISTS + INSERT ... ON DUPLICATE KEY UPDATE. Correrla
--   dos veces no altera ningún latido ya registrado: sólo reafirma el periodo
--   esperado de cada job. No hay tabla de migraciones aplicadas en este
--   proyecto, así que reejecutar tiene que ser seguro por construcción.
-- ================================================================

CREATE TABLE IF NOT EXISTS cron_heartbeats (
    -- Nombre del job, tal cual lo recibe cron/run.php en ?job=...
    job             VARCHAR(64)        NOT NULL,

    -- Última ejecución TERMINADA (bien o mal). NULL = el job está declarado
    -- pero no ha corrido nunca; es un estado real y distinto de "corrió hace
    -- mucho", y health.php lo distingue.
    last_run_at     DATETIME           DEFAULT NULL,
    duration_ms     INT UNSIGNED       NOT NULL DEFAULT 0,
    items_processed INT UNSIGNED       NOT NULL DEFAULT 0,

    -- Resultado de esa última ejecución.
    status          ENUM('ok','error') NOT NULL DEFAULT 'ok',
    -- Último mensaje: resumen en caso 'ok', el error en caso 'error'.
    -- VARCHAR(500) y truncado en el código: un stack trace no cabe ni hace
    -- falta, y con STRICT_TRANS_TABLES un valor más largo abortaría el INSERT.
    message         VARCHAR(500)       DEFAULT NULL,

    -- Se conservan por separado para que un fallo puntual no borre la prueba
    -- de que el job SÍ está siendo invocado, ni al revés.
    last_ok_at      DATETIME           DEFAULT NULL,
    last_error_at   DATETIME           DEFAULT NULL,

    run_count       INT UNSIGNED       NOT NULL DEFAULT 0,
    error_count     INT UNSIGNED       NOT NULL DEFAULT 0,

    -- Cada cuánto DEBERÍA correr el job, en segundos. Es configuración, no
    -- medición: sirve para que health.php pueda decir "esto lleva demasiado
    -- sin latir" sin que quien consulta tenga que saberse los periodos.
    -- Debe coincidir con IAREPO_JOB_PERIODS de cron/run.php (que la reescribe
    -- en cada latido, así que ese fichero manda si alguna vez divergen).
    period_seconds  INT UNSIGNED       NOT NULL DEFAULT 0,

    updated_at      DATETIME           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (job),
    INDEX idx_last_run (last_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Filas semilla de los dos jobs de cron/run.php, con last_run_at NULL.
--
-- Sembrarlas NO es cosmético: sin ellas, un job que nunca ha sido invocado no
-- tiene fila, y "no hay fila" es indistinguible de "ese job no existe". Con la
-- fila sembrada, health.php puede afirmar que link_check está declarado y
-- JAMÁS ha latido — que es justo el diagnóstico que faltó durante 66 días.
--
-- ON DUPLICATE KEY UPDATE toca SÓLO period_seconds: reejecutar la migración
-- nunca falsea un latido real.
INSERT INTO cron_heartbeats (job, period_seconds) VALUES
    ('link_check', 21600),   -- cada 6 h
    ('moderation',   120)    -- cada 2 min
ON DUPLICATE KEY UPDATE period_seconds = VALUES(period_seconds);
