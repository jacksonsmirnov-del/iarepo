-- ================================================================
-- migration_013_fork_lineage.sql
--
-- QUE LOS FORKS SE VEAN COMO VERSIONES, NO COMO RUIDO.
--
-- ── EL PROBLEMA ───────────────────────────────────────────────
--   Un fork es un recurso independiente con `fork_of` apuntando a su padre.
--   Funciona, pero deja dos agujeros para presentarlos como "otras versiones
--   de esto":
--
--   1. `fork_of` es de UN SOLO NIVEL. Un fork de un fork apunta a su padre
--      inmediato, no a la raíz. Preguntar "¿qué versiones hay de este
--      recurso?" exigiría recorrer la cadena hacia arriba en cada carga de
--      página, o un CTE recursivo. `root_id` responde con un índice.
--
--   2. NO HABÍA FORMA DE DESTACAR UNA VERSIÓN. Y ordenar por votos no sirve:
--      el original lleva años acumulando visitas y un fork mejor publicado
--      ayer empieza en cero, así que por conteo bruto el original gana SIEMPRE
--      — un sistema donde forkear no puede salir rentable. `is_recommended`
--      le da a un fork un camino al primer puesto que no es un concurso de
--      popularidad: lo decide quien escribió el original.
--
-- ── LO QUE ESTA MIGRACIÓN NO HACE ─────────────────────────────
--   No toca los TÍTULOS existentes. api/resources.php prefijaba 'Fork: ' al
--   crear, y eso ensucia la tarjeta del catálogo; el prefijo se deja de añadir
--   en el código, pero los títulos ya creados son CONTENIDO DE USUARIO y
--   reescribirlos por lote es una decisión del mantenedor, no un efecto
--   colateral de una migración de esquema.
--
--   Tampoco toca `fork_count`, que seguirá contando TODOS los forks incluidos
--   los privados. Es lo que dice contar y es un dato interno útil; lo que se
--   arregla es la INTERFAZ, que decía "12 Forks" y al pinchar mostraba 2
--   porque el resto son borradores de otra gente. La ficha pasa a mostrar las
--   versiones públicas, que son las que se pueden abrir.
--
-- IDEMPOTENTE
--   ADD COLUMN/INDEX IF NOT EXISTS + un backfill que sólo actúa sobre filas
--   sin resolver. Reejecutarla no cambia una fila ya asentada.
-- ================================================================


-- ── Las dos columnas ────────────────────────────────────────────

-- Raíz del linaje. Para un recurso original vale su propio id, de modo que
-- "dame todas las versiones de X" es siempre `WHERE root_id = X`, sin ramas ni
-- casos especiales para el original.
--
-- ⚠️ OJO AL ORDEN: en MariaDB el COMMENT es parte de la DEFINICIÓN de la
-- columna y va ANTES de la cláusula de posición (AFTER). Escrito al revés
-- —«… INT NULL AFTER fork_of COMMENT '…'»— da ERROR 1064 y la migración se
-- para a medias, dejando el esquema en un estado intermedio. Pasó al escribir
-- este fichero; lo cazó tests/integration/fork_lineage_db_test.php antes de
-- llegar al servidor.
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS root_id INT NULL
    COMMENT 'Raiz del linaje de forks. Para un original, su propio id.'
    AFTER fork_of;

-- La bendición del autor del original: el pull request de los pobres.
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS is_recommended TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'El autor de la raiz destaca esta version.'
    AFTER root_id;

ALTER TABLE resources
    ADD INDEX IF NOT EXISTS idx_root (root_id);


-- ── Backfill del linaje ─────────────────────────────────────────
--
-- Paso 1: cada fila apunta provisionalmente a su padre inmediato (o a sí misma
-- si es un original). Sólo toca filas sin resolver, que es lo que hace el
-- backfill idempotente.
UPDATE resources SET root_id = COALESCE(fork_of, id) WHERE root_id IS NULL;

-- Paso 2: aplanar la cadena. Cada repetición sube UN nivel: si mi "raíz"
-- resulta ser a su vez un fork, adopto la suya. Verificado contra MariaDB
-- 11.8 con una cadena de 4 niveles (1→2→3→4): las cuatro filas acaban
-- apuntando a 1.
--
-- ── POR QUÉ REPETIDO A MANO Y NO UN CTE RECURSIVO ─────────────
--   setup/run_migration.php:41 trocea el fichero con explode(';', $sql). Un
--   WITH RECURSIVE cabría, pero deja el resultado atado a que la versión de
--   MariaDB de producción —que es DESCONOCIDA— lo soporte. Cuatro UPDATE
--   idénticos funcionan en cualquier versión y cubren linajes de 5 niveles,
--   holgadamente más de lo que hay: los forks nacen en 'draft' y casi ninguno
--   llega a publicarse, así que hoy las cadenas son de longitud 1.
--
--   Si algún día hicieran falta más niveles, la señal es visible: quedarían
--   filas cuyo root_id apunta a otra fila con fork_of no nulo.
UPDATE resources r JOIN resources p ON r.root_id = p.id
    SET r.root_id = p.root_id WHERE p.root_id IS NOT NULL AND p.root_id <> r.root_id;
UPDATE resources r JOIN resources p ON r.root_id = p.id
    SET r.root_id = p.root_id WHERE p.root_id IS NOT NULL AND p.root_id <> r.root_id;
UPDATE resources r JOIN resources p ON r.root_id = p.id
    SET r.root_id = p.root_id WHERE p.root_id IS NOT NULL AND p.root_id <> r.root_id;
UPDATE resources r JOIN resources p ON r.root_id = p.id
    SET r.root_id = p.root_id WHERE p.root_id IS NOT NULL AND p.root_id <> r.root_id;

-- Paso 3: forks huérfanos. `fork_of` no tiene clave ajena, así que puede
-- apuntar a un recurso que ya no existe. Sin esto, esas filas quedarían con un
-- root_id muerto y no aparecerían en NINGÚN listado de versiones — invisibles
-- sin que nada fallara. Se les devuelve su propio id: pasan a ser su propia
-- raíz, que es lo que de hecho son.
UPDATE resources r LEFT JOIN resources p ON r.root_id = p.id
    SET r.root_id = r.id WHERE p.id IS NULL;
