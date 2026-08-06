-- ================================================================
-- migration_012_engagement.sql
--
-- QUE LAS VISITAS SIGNIFIQUEN PERSONAS.
--
-- ── EL BUG QUE ARREGLA ────────────────────────────────────────
--   `view_count` medía mal por dos motivos a la vez, y los dos empujaban en
--   direcciones contrarias:
--
--   1. NO CONTABA LA PÁGINA DONDE OCURRE EL USO. Sólo incrementaban
--      viewer/index.php y api/resources.php?id=. Pero /resource/N renderiza el
--      recurso FUNCIONANDO en un iframe srcdoc: un alumno abre el enlace, usa
--      el simulador entero, aprende y se va — y el contador no se movía. De ahí
--      el síntoma que lo destapó: 20 alumnos trabajando, 8 visitas registradas.
--
--   2. NO DEDUPLICABA NADA. Un `UPDATE … +1` por carga. Una persona recargando
--      ocho veces son ocho visitas. Así que el 290 de un recurso y el 8 de otro
--      ni siquiera medían lo mismo.
--
-- ── POR QUÉ SE IDENTIFICA EL NAVEGADOR Y NO LA RED ────────────
--   El diseño obvio —hash de IP— habría EMPEORADO justo el caso que lo
--   originó. Los alumnos de un colegio salen por el NAT del centro: una sola
--   IP pública, y si usan los portátiles del aula el User-Agent también
--   coincide. Deduplicar por IP colapsaría los 20 en uno.
--
--   Por eso `viewer_key` NO deriva de la IP. Deriva de:
--     · autenticado → su identidad (tenant + user), estable entre dispositivos
--     · anónimo     → un identificador ALEATORIO que genera su propio
--                     navegador y guarda en localStorage (assets/js/track.js)
--
--   Aquí no se guarda ninguna IP. La única que el sistema toca sigue siendo la
--   de `api_rate_limits`, que es anterior y ajena a esto.
--
-- ── LA SAL DIARIA Y POR QUÉ LA TABLA SE PURGA SOLA ────────────
--   `viewer_key` es sha256(identificador + sal del día). La sal se genera al
--   azar la primera vez que alguien visita ese día y las viejas se BORRAN.
--   Consecuencia buscada: pasada la ventana de retención, ni siquiera con
--   acceso total a la base de datos se puede volver a ligar una fila con el
--   identificador que la produjo, ni cruzar dos días de la misma persona.
--   La anonimización no depende de una promesa: depende de que el dato ya no
--   exista.
--
--   Contrapartida honesta: al rotar la sal a diario, `unique_views` cuenta
--   PERSONA-DÍA, no "personas distintas de siempre". Es exactamente la
--   pregunta que se quería responder ("¿cuántos de mis alumnos lo abrieron
--   hoy?") y hace imposible la que no se quiere poder responder.
--
-- ── `view_count` NO SE TOCA: SE CONGELA ───────────────────────
--   Reescribirlo con el número correcto habría hecho que un recurso con 290
--   visitas amaneciera con 12. Esas cargas ocurrieron: son un histórico real,
--   sólo que de otra magnitud (cargas de página, bots incluidos). Se queda
--   quieto como marca histórica y `unique_views` pasa a ser la métrica viva.
--
--   ⚠️ shared/search.php:1002 sigue desempatando por `view_count`, A PROPÓSITO.
--   Cambiar el desempate hoy, con `unique_views` a 0 en todo el catálogo,
--   aplanaría el orden del buscador de golpe. Ese cambio es su propia tarea,
--   con datos delante y con tests de relevancia — no un efecto colateral de
--   esta migración.
--
-- IDEMPOTENTE
--   CREATE TABLE IF NOT EXISTS ×2 + ADD COLUMN IF NOT EXISTS. Correrla dos
--   veces no altera una fila. No hay tabla de migraciones aplicadas en este
--   proyecto: reejecutar tiene que ser seguro por construcción.
-- ================================================================


-- ── Una fila por persona, recurso y día ─────────────────────────
CREATE TABLE IF NOT EXISTS resource_views (
    resource_id   INT      NOT NULL,

    -- sha256(prefijo + identificador + sal del día). 'a:' anónimo, 'u:' con
    -- sesión. NUNCA contiene ni deriva de una IP.
    viewer_key    CHAR(64) NOT NULL,

    view_day      DATE     NOT NULL,

    -- Segundos con la pestaña VISIBLE. El cliente manda el acumulado de su
    -- sesión de página y el servidor aplica GREATEST, así que un beacon
    -- repetido —o que llegue desordenado— no infla ni hace retroceder el dato.
    --
    -- SMALLINT UNSIGNED llega a 65535 s (18 h). api/track.php lo capa MUY por
    -- debajo: con STRICT_TRANS_TABLES un desbordamiento aborta el INSERT con
    -- ERROR 1264 y perderíamos la visita entera por un dato accesorio.
    engaged_secs  SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- ¿Llegó el foco a entrar en el iframe del recurso? Es lo único que
    -- distingue "lo miró" de "lo usó". El iframe va con
    -- sandbox="allow-scripts" SIN allow-same-origin, así que desde fuera no se
    -- puede leer nada de dentro: sólo se detecta que el foco cruzó.
    interacted    TINYINT(1) NOT NULL DEFAULT 0,

    -- Permite separar el uso de la comunidad del de gente con sesión sin
    -- guardar QUIÉN es. Es un booleano, no una identidad.
    is_authed     TINYINT(1) NOT NULL DEFAULT 0,

    -- Dónde entró: la página de detalle (que es donde ocurre el uso real y
    -- donde no se contaba nada) o el visor a pantalla completa.
    surface       ENUM('detail','viewer') NOT NULL DEFAULT 'detail',

    first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- La clave ES la regla de deduplicación: misma persona + mismo recurso +
    -- mismo día = una fila. api/track.php detecta la fila nueva por
    -- rowCount() == 1 (verificado contra MariaDB 11.8: 1 inserta, 2 actualiza,
    -- 0 no cambia nada) y sólo entonces incrementa el contador de `resources`.
    PRIMARY KEY (resource_id, viewer_key, view_day),

    INDEX idx_view_day (view_day),
    INDEX idx_resource_day (resource_id, view_day),

    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── La sal que se rota y se tira ────────────────────────────────
--
-- Una fila por día. La escribe api/track.php con INSERT IGNORE la primera vez
-- que alguien visita ese día, y en ese mismo momento borra las caducadas: así
-- la purga es DETERMINISTA (una vez al día, exactamente cuando toca) en vez de
-- depender de un sorteo por petición, que con tráfico bajo podría no ocurrir
-- en semanas y dejaría vivo un dato que ya debería haber desaparecido.
CREATE TABLE IF NOT EXISTS view_salts (
    view_day   DATE     NOT NULL PRIMARY KEY,
    salt       CHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── El contador desnormalizado ──────────────────────────────────
--
-- Va SEPARADO de view_count, no lo sustituye: son magnitudes distintas
-- (personas-día contra cargas de página) y fundirlas haría que ningún número
-- del catálogo significara nada. Ver la cabecera.
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS unique_views INT NOT NULL DEFAULT 0 AFTER view_count;
