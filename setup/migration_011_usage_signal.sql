-- ================================================================
-- migration_011_usage_signal.sql
--
-- LA SEÑAL DE PROFESOR — que "lo usé en clase" sea un dato fiable.
--
-- POR QUÉ EXISTE
--   El catálogo no tiene ninguna medida de si un recurso SIRVE. Las visitas
--   miden curiosidad y los "me gusta" miden impulso; ninguna de las dos dice
--   si alguien se jugó 50 minutos de clase con el recurso delante de treinta
--   alumnos. Esa afirmación —`usage_type = 'presented'`— es la señal de más
--   valor del sistema y api/usage.php ya sabe registrarla desde el primer día.
--
--   Nunca se ha disparado: `use_count` está a 0 en TODO el catálogo. La API
--   existe, la tabla existe, y no hay ni un botón que las llame. Esta
--   migración prepara el terreno para ese botón.
--
-- ── POR QUÉ ESTA MIGRACIÓN ES "DEFENSIVA" Y NO SÓLO ADITIVA ─────
--   No se puede demostrar DESDE EL REPO qué contiene `resource_usage` en
--   producción. migration_000_prod_baseline.sql documenta que este proyecto
--   ya sufrió deriva de esquema —columnas creadas a mano por SSH que ningún
--   fichero de setup/ creaba—, así que setup/schema.sql NO es prueba de lo
--   que hay en el servidor: es prueba de lo que alguien escribió en el repo.
--
--   Hay una prueba INDIRECTA de que la tabla existe: api/resources.php
--   inserta una fila 'forked' DENTRO de la transacción del fork, y en
--   producción hay forks creados. Si la tabla faltara, esa transacción habría
--   hecho rollback y no existirían. Luego la tabla está y el ENUM acepta
--   'forked'.
--
--   Lo que esa prueba NO cubre es el valor 'presented', que es justo el que
--   vamos a empezar a escribir. Con STRICT_TRANS_TABLES, un ENUM que no
--   contiene el valor no avisa: aborta el INSERT con ERROR 1265, la
--   transacción hace rollback y el botón falla SIEMPRE, en silencio y para
--   todo el mundo. Por eso la migración no asume el estado: lo GARANTIZA.
--
--   De ahí las tres capas de abajo. Cada una cubre un escenario distinto y
--   las tres son inofensivas si el escenario no se da.
--
-- ── POR QUÉ NO HAY SQL DINÁMICO ────────────────────────────────
--   La forma "elegante" de esto sería consultar information_schema y lanzar
--   el ALTER sólo si hace falta, con PREPARE/EXECUTE. No se puede:
--   setup/run_migration.php:41 parte el fichero con `explode(';', $sql)`, un
--   split ingenuo que no entiende literales ni bloques. Todo lo que hay aquí
--   sobrevive a ese troceado: ninguna sentencia lleva ';' dentro de una
--   cadena. Es una restricción real del proyecto, no una preferencia.
--
-- IDEMPOTENTE
--   CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS + ADD UNIQUE KEY
--   IF NOT EXISTS + un MODIFY que reafirma una definición ya correcta.
--   Correrla dos veces no cambia ni una fila. No hay tabla de migraciones
--   aplicadas en este proyecto, así que reejecutar tiene que ser seguro por
--   construcción.
-- ================================================================


-- ── CAPA 1 · La tabla existe ────────────────────────────────────
-- Escenario que cubre: `resource_usage` NO está en producción.
--
-- Es el escenario improbable (ver la prueba indirecta de los forks, arriba)
-- pero es también el único que se rompe de forma total, así que se cubre.
-- Definición copiada de setup/schema.sql:86 para que las dos rutas de
-- construcción —schema.sql en una BD nueva, esta migración en una BD que
-- perdió la tabla— converjan en el MISMO esquema.
--
-- En la reconstrucción normal (schema.sql ya creó la tabla) esta sentencia es
-- un no-op y el trabajo lo hacen las capas 2 y 3.
CREATE TABLE IF NOT EXISTS resource_usage (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    resource_id       INT NOT NULL,

    -- Quién lo usó (desnormalizado: el usuario vive en Campus, no aquí)
    user_id           INT NOT NULL,
    tenant_id         INT NOT NULL,
    user_display_name VARCHAR(150),
    tenant_name       VARCHAR(150),

    -- Qué hizo. 'forked' lo escribe api/resources.php; los otros tres,
    -- api/usage.php.
    usage_type        ENUM('presented','sent','forked','endorsed') NOT NULL,
    classroom_name    VARCHAR(100) NULL,
    notes             TEXT NULL,

    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_resource (resource_id),
    INDEX idx_user (tenant_id, user_id),
    INDEX idx_type (usage_type),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── CAPA 2 · El ENUM acepta los cuatro valores ──────────────────
-- Escenario que cubre: la tabla existe pero su ENUM derivó y le falta algún
-- valor (el temido 'presented').
--
-- MODIFY no admite IF NOT EXISTS, pero es idempotente por naturaleza:
-- reafirmar una definición ya correcta deja la columna exactamente igual. La
-- tabla es diminuta (use_count = 0 en todo el catálogo, o sea que las únicas
-- filas son 'forked'), así que la reconstrucción que implica es instantánea.
--
-- MariaDB convierte los valores de un ENUM POR CADENA, no por ordinal,
-- mientras el valor viejo exista en la definición nueva. Como la definición
-- nueva es la de schema.sql —de la que salió la tabla— cualquier fila
-- existente encuentra su cadena. Si alguna no la encontrara, el ALTER falla
-- RUIDOSAMENTE, que es el comportamiento correcto para una migración: mejor
-- parar aquí que descubrirlo en caliente.
ALTER TABLE resource_usage
    MODIFY COLUMN usage_type ENUM('presented','sent','forked','endorsed') NOT NULL;


-- ── CAPA 3 · Deduplicación real de la señal ─────────────────────
-- Escenario que cubre: el mismo profesor pulsando el botón cinco veces.
--
-- Hoy sólo 'endorsed' se deduplica, y lo hace en la APLICACIÓN
-- (api/usage.php: SELECT y luego INSERT). Eso deja dos agujeros: 'presented'
-- no se deduplica en absoluto, y la comprobación en dos pasos tiene una
-- carrera —dos peticiones simultáneas pasan las dos el SELECT y las dos
-- insertan—. Una métrica que aspira a sustituir a las estrellas no puede
-- nacer inflable: sería el mismo defecto por el que se descartaron.
--
-- ── POR QUÉ UNA COLUMNA NORMAL Y NO UNA GENERADA ───────────────
--   Lo natural sería `usage_day DATE GENERATED ALWAYS AS (DATE(created_at))`.
--   Se descartó por dos motivos concretos:
--     1. La versión de MariaDB de producción es DESCONOCIDA (la CI fija
--        mariadb:11.8, pero eso es la CI). Las columnas generadas piden
--        10.2+; casi seguro que está, pero "casi seguro" es exactamente lo
--        que esta migración existe para evitar.
--     2. Una columna generada se calcularía para TODAS las filas, incluidas
--        las 'forked' — y eso rompería forkear dos veces el mismo recurso el
--        mismo día, que hoy es legal. Introducir esa regresión mientras se
--        arregla otra cosa es justo lo que no se quiere.
--
--   Con una columna normal, quien no la escribe se queda a NULL. Y en
--   InnoDB un índice UNIQUE **admite múltiples NULL**: las filas 'forked'
--   —que inserta api/resources.php sin tocar esta columna— quedan fuera de
--   la restricción sin necesidad de ninguna excepción. La regla se cumple
--   sola.
--
-- QUIÉN ESCRIBE QUÉ (el contrato, para que no se rompa por descuido):
--   'presented' / 'sent'  → api/usage.php escribe CURDATE()  ⇒ 1 por día
--   'endorsed'            → api/usage.php deja NULL          ⇒ el chequeo
--                           permanente de la aplicación sigue mandando, sin
--                           que un índice DIARIO lo debilite dejando volver a
--                           endosar mañana
--   'forked'              → api/resources.php ni la menciona ⇒ nunca dedup
ALTER TABLE resource_usage
    ADD COLUMN IF NOT EXISTS usage_day DATE DEFAULT NULL
    COMMENT 'Dia de la señal deduplicable. NULL = esta fila no deduplica.';

-- tenant_id va en la clave porque la identidad real de un usuario en este
-- sistema es (tenant_id, user_id): los user_id vienen de Campus y sólo son
-- únicos dentro de su tenant. Sin él, el usuario 7 del colegio A bloquearía
-- al usuario 7 del colegio B.
ALTER TABLE resource_usage
    ADD UNIQUE KEY IF NOT EXISTS uniq_usage_signal
    (resource_id, user_id, tenant_id, usage_type, usage_day);
