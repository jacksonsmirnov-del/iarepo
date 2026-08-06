-- ================================================================
-- migration_014_comprehension.sql
--
-- «¿TE QUEDÓ CLARO?» — la pregunta que un alumno sí puede contestar.
--
-- ── POR QUÉ ESTA PREGUNTA Y NO UNA VALORACIÓN ─────────────────
--   Se descartó un sistema de estrellas, y no por gusto:
--     · Las escalas de 5 estrellas colapsan en «5 o 1». La diferencia entre un
--       4,8 y un 4,7 no informa de nada.
--     · Con 546 recursos y tráfico bajo, la mayoría tendría 0-3 votos. Una
--       media de dos votos es ruido con aspecto de autoridad, y si alimenta el
--       ranking, ordena mal.
--     · Pedir una razón al puntuar bajo SUPRIME el feedback negativo: la gente
--       no escribe la justificación, simplemente no puntúa. Queda una muestra
--       sesgada al alza.
--
--   Y sobre todo: el público que USA los recursos son alumnos, muchos menores,
--   contestando delante de su profesor. «¿Te gustó?» invita a quedar bien.
--   «¿Te quedó claro?» es una pregunta sobre uno mismo, no un juicio sobre el
--   trabajo de su profesor — y es además el dato que el profesor quería de
--   verdad: no «¿les gustó la interfaz?», sino «¿sirvió?».
--
--   Deja de ser una encuesta y pasa a ser un chequeo de comprensión, que tiene
--   valor pedagógico por sí solo. La métrica del catálogo sale de regalo al
--   agregar.
--
-- ── SIN TEXTO LIBRE, A PROPÓSITO ──────────────────────────────
--   Tres botones y nada más. No es pereza: un campo de texto abierto rellenado
--   por menores es contenido que hay que MODERAR, y este proyecto ya tiene un
--   cron de moderación que estuvo parado 66 días sin que nadie lo notara. Un
--   ENUM no se modera.
--
-- ── LA CLAVE ES LA MISMA QUE LA DE LAS VISITAS ────────────────
--   (resource_id, viewer_key, view_day), igual que `resource_views`
--   (migration_012). Eso da tres cosas de golpe:
--     · una respuesta por persona, recurso y día;
--     · se puede CORREGIR (ON DUPLICATE KEY UPDATE) sin duplicar filas;
--     · hereda la anonimización: el viewer_key va hasheado con la sal del día,
--       que se BORRA a los 2 días. Pasado ese plazo, la respuesta «me perdí»
--       de un alumno concreto es irrecuperable incluso con acceso total a la
--       base de datos. Para un dato sobre la comprensión de un menor, esa
--       propiedad no es un adorno.
--
--   ⚠️ NUNCA añadas aquí una columna con la identidad en claro (user_id,
--   nombre, email). Convertiría un agregado anónimo en un registro nominal de
--   quién no entendió qué, que es exactamente lo que este diseño evita.
--
-- ── NO HAY CONTADOR DESNORMALIZADO ────────────────────────────
--   Los agregados se calculan con un GROUP BY cuando el autor mira su panel.
--   Se descartó denormalizar en `resources` porque serían TRES contadores más
--   que mantener en sincronía, y este repo ya arrastra el caso contrario:
--   `fork_count` cuenta cosas que la interfaz no enseña. Un contador que puede
--   desincronizarse es peor que una consulta que tarda un milisegundo.
--
-- IDEMPOTENTE
--   CREATE TABLE IF NOT EXISTS. Reejecutarla no cambia una fila.
-- ================================================================

CREATE TABLE IF NOT EXISTS resource_comprehension (
    resource_id INT      NOT NULL,

    -- sha256(identificador + sal del día), idéntico al de resource_views.
    -- NUNCA contiene ni deriva de una IP, ni de un nombre.
    viewer_key  CHAR(64) NOT NULL,

    view_day    DATE     NOT NULL,

    -- 'claro'    → lo entendí
    -- 'regular'  → más o menos
    -- 'perdido'  → me perdí
    --
    -- Un ENUM y no un número: no hay media que calcular ni ranking de
    -- estrellas que inventar. Se cuentan respuestas, que es lo único que un
    -- volumen pequeño permite afirmar con honestidad.
    answer      ENUM('claro','regular','perdido') NOT NULL,

    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (resource_id, viewer_key, view_day),

    -- Para el GROUP BY del panel del autor.
    INDEX idx_resource_answer (resource_id, answer),

    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
