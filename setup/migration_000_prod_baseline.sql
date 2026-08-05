-- ================================================================
-- migration_000_prod_baseline.sql
--
-- Declara en el repo las columnas de `resources` que EXISTEN EN
-- PRODUCCIÓN desde antes que las migraciones numeradas y que ningún
-- fichero de setup/ creaba: se añadieron a mano por SSH y nunca
-- llegaron al repositorio. Eso es la "deriva de esquema": mientras
-- nadie reconstruya la BD no se nota, pero el día que haya que
-- restaurar un backup el esquema que sale de setup/ NO es el de
-- producción y la aplicación da 500.
--
-- Columnas que declara:
--   source_name, source_url        el listado y el detalle las devuelven
--                                  y el buscador filtra por source_name
--   link_status, link_checked_at   las escribe el cron de enlaces
--   iframe_blocked                 la escribe el cron de enlaces y la lee
--                                  el visor para decidir si puede empotrar
--
-- ── POR QUÉ SE LLAMA 000 Y NO 010 ───────────────────────────────
-- Estas columnas son ANTERIORES a migration_002 en producción, y hay
-- prueba dentro del propio repo:
--
--   migration_002_moderation.sql se escribió con
--       ADD COLUMN ... AFTER source_name
--   y está aplicada en producción (content_hash y moderation_status
--   existen allí: api/resources.php inserta en las dos y funciona).
--   MariaDB rechaza un AFTER sobre una columna inexistente con
--   ERROR 1054, luego source_name YA estaba en producción cuando se
--   corrió la 002.
--
-- El nombre 010 invertía esa historia: colocaba el baseline al final y
-- rompía la reconstrucción desde cero (5 sentencias de la 002 en error,
-- cascada incluida). Con el prefijo 000 el orden del repo reproduce el
-- orden real de producción.
--
-- El renombrado por sí solo NO bastaba, así que además se quitó el
-- `AFTER source_name` de migration_002_moderation.sql: una migración no
-- debe depender de que OTRO fichero se haya aplicado antes (por SSH las
-- corre un humano, en el orden que le parezca). Las dos medidas son
-- complementarias: el 000 aporta las columnas, quitar el AFTER hace que
-- la 002 ya no dependa del orden.
--
-- ── EN PRODUCCIÓN ES UN NO-OP ───────────────────────────────────
-- Las cinco columnas ya existen allí, así que las cinco ALTER se saltan
-- enteras. No hay DROP, ni cambios de tipo, ni UPDATE de datos: esta
-- migración no puede alterar producción ni siquiera si se ejecuta por
-- error, y se puede reejecutar tantas veces como se quiera.
--
-- Correrla no es urgente ni necesario para desplegar: sirve para que
-- dev, CI y una restauración de backup levanten un esquema equivalente
-- al de producción.
--
-- Cómo correrla (desde el doc root del servidor, donde vive .env.php):
--   php setup/run_migration.php setup/migration_000_prod_baseline.sql
--
-- ── COMPATIBILIDAD ──────────────────────────────────────────────
-- Producción es MariaDB (10.0.2+), que admite IF NOT EXISTS en ADD
-- COLUMN. MySQL 8 NO lo admite; allí hay que ejecutar las mismas cinco
-- ALTER sin el "IF NOT EXISTS" (ver el bloque comentado del final).
--
-- ── EL HUECO DEL 004 ────────────────────────────────────────────
-- No existe migration_004: la numeración va 001, 002, 003, 005…009.
-- No es una migración perdida. `git log --diff-filter=A` sobre setup/
-- no registra ningún migration_004 en toda la historia del repo, y las
-- fechas de alta encajan con que el cuarto fichero de migración se
-- llamase por su tema en vez de por su número:
--   migration_003_social.sql      2026-05-18
--   migration_url_blacklist.sql   2026-05-19   <- ocupa el turno del 004
--   migration_005_rate_limits.sql 2026-05-30
-- Es decir: el 004 se "gastó" en migration_url_blacklist.sql, que se
-- quedó sin numerar. NO se renombra aquí porque es un fichero ya
-- aplicado en producción y renombrarlo no arregla nada; queda anotado
-- para que nadie vuelva a buscar una migración que no existe.
--
-- Sigue sin haber tabla de registro de migraciones aplicadas: nadie
-- sabe con certeza qué se corrió en producción. Por eso TODO lo de este
-- fichero es idempotente.
-- ================================================================

-- Nombre de la fuente original del recurso ("PhET Interactive Simulations",
-- "NASA SpacePlace", "UNAM"...). La búsqueda lo mira: sin esto, buscar
-- "PhET" devolvía 0 pese a haber >100 recursos de esa fuente.
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS source_name VARCHAR(150) NULL;

-- URL original del recurso (se devuelve en el listado y en el detalle).
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS source_url VARCHAR(500) NULL;

-- Estado del enlace, lo escriben cron/run.php:66 y setup/cron_link_checker.php:39.
-- El listado excluye los 'broken' (api/resources.php: r.link_status != 'broken').
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS link_status VARCHAR(20) NULL;

-- Última comprobación del enlace (mismos dos ficheros que link_status).
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS link_checked_at DATETIME NULL;

-- ¿El destino prohíbe empotrarse en un iframe (X-Frame-Options / CSP)?
-- La escribe el cron de enlaces con 0 o 1 -- cron/run.php:84 pasa
-- `$iframeBlocked ? 1 : 0` -- y la leen viewer/index.php:30 y
-- resource/index.php:337 como booleano. De ahí TINYINT(1) DEFAULT 0,
-- el mismo tipo que is_active / is_read en el resto del esquema.
-- Ojo: hasta ahora NINGÚN fichero de setup/ la creaba. En producción
-- existe (el cron lleva meses escribiéndola sin error); en una BD
-- reconstruida no existía, y el cron habría reventado cada 6 h.
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS iframe_blocked TINYINT(1) DEFAULT 0;


-- ────────────────────────────────────────────────────────────────
-- Equivalente para MySQL 8 (sin IF NOT EXISTS; falla si ya existen):
--
--   ALTER TABLE resources ADD COLUMN source_name     VARCHAR(150) NULL;
--   ALTER TABLE resources ADD COLUMN source_url      VARCHAR(500) NULL;
--   ALTER TABLE resources ADD COLUMN link_status     VARCHAR(20)  NULL;
--   ALTER TABLE resources ADD COLUMN link_checked_at DATETIME     NULL;
--   ALTER TABLE resources ADD COLUMN iframe_blocked  TINYINT(1)   DEFAULT 0;
--
-- NOTA sobre el índice FULLTEXT: el buscador NO necesita ninguno nuevo.
-- Sigue usando idx_search (title, description, topic_tag) de schema.sql
-- y alcanza subject_area / source_name / author_display_name / tags por
-- el brazo LIKE, deliberadamente SIN índice: así el arreglo no exige
-- ningún ALTER en un hosting compartido donde push = producción en vivo.
-- ────────────────────────────────────────────────────────────────
