#!/bin/bash
# ================================================================
# iarepo_backup.sh — Backup diario de la BD de iarepo
#
# INDEPENDIENTE del backup de Campus: no lee, no escribe y no importa
# nada de ~/backups/. Vive en su propia carpeta y tiene su propio cron.
#
# Instalación (en el servidor, una vez):
#   mkdir -p ~/iarepo-backups/db
#   cp iarepo_backup.sh ~/iarepo-backups/run_backup.sh
#   chmod +x ~/iarepo-backups/run_backup.sh
#   ~/iarepo-backups/run_backup.sh          # primera corrida manual
#
# Cron (hPanel → Cron Jobs, diario a las 04:15; Campus corre a las 03:00,
# así que no compiten por el mismo minuto):
#   15 4 * * *  $HOME/iarepo-backups/run_backup.sh >> $HOME/iarepo-backups/backup.log 2>&1
#   (sustituye $HOME por la ruta absoluta que te pida el panel; NO se escribe
#    aquí: el repo es público y la regla 5 prohíbe usuario y rutas del servidor)
#
# Notas de diseño:
# - Las credenciales NO se escriben aquí: se leen de .env.php (fuera de git)
#   con php, y se pasan a mysqldump por un fichero de opciones temporal con
#   permisos 600 (MYSQL_PWD sería visible en /proc para otros procesos).
# - shell_exec() está deshabilitado en el PHP del servidor, por eso esto es
#   un script de shell y no un .php.
# - La BD ocupa ~6 MB; un dump comprimido son unos cientos de KB.
# ================================================================

set -euo pipefail

# Las tres rutas se pueden sobrescribir por entorno para poder ENSAYARLO
# fuera de producción (así se probó antes de instalarlo).
# El doc root NO se escribe en el repo (público, regla 5). Se toma de
# IAREPO_DOCROOT, o de `git config deploy.worktree` del repo bare —el mismo
# sitio del que lo lee setup/hooks/post-receive—, o del directorio del script.
DOCROOT="${IAREPO_DOCROOT:-}"
if [ -z "$DOCROOT" ]; then
    DOCROOT="$(git config --get deploy.worktree 2>/dev/null || true)"
fi
if [ -z "$DOCROOT" ]; then
    # Instalado dentro del propio doc root: setup/tools/ → doc root
    DOCROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fi
DEST="${IAREPO_BACKUP_DEST:-$HOME/iarepo-backups}"
KEEP_DAYS="${IAREPO_KEEP_DAYS:-14}"
STAMP="$(date +%F)"
OUT_DIR="$DEST/db/$STAMP"

log() { echo "[$(date '+%F %T')] $*"; }

# Binario de volcado. Se puede forzar por entorno (así se ensayó este script
# contra una MariaDB en Docker antes de instalarlo). Si no se fuerza, se busca
# 'mysqldump' y, si no está, 'mariadb-dump': MariaDB 11 renombró el binario y
# el alias viejo no siempre viene. Hoy el servidor tiene /usr/bin/mysqldump.
if [ -n "${IAREPO_MYSQLDUMP:-}" ]; then
    MYSQLDUMP="$IAREPO_MYSQLDUMP"
elif command -v mysqldump >/dev/null 2>&1; then
    MYSQLDUMP="mysqldump"
elif command -v mariadb-dump >/dev/null 2>&1; then
    MYSQLDUMP="mariadb-dump"
else
    MYSQLDUMP=""
fi

# ── 0. Comprobaciones previas ────────────────────────────────────
# Sin esto, un binario ausente dejaba un .gz de 20 bytes en la carpeta del
# día: un fichero que PARECE el backup y no lo es. Detectado ensayando.
if [ -z "$MYSQLDUMP" ] || ! command -v "$MYSQLDUMP" >/dev/null 2>&1; then
    log "❌ No encuentro mysqldump ni mariadb-dump en el PATH — no puedo respaldar"
    exit 1
fi
for bin in php gzip; do
    command -v "$bin" >/dev/null 2>&1 || { log "❌ Falta '$bin' en el PATH — no puedo respaldar"; exit 1; }
done

# ── 1. Credenciales desde .env.php (nunca se imprimen) ───────────
if [ ! -f "$DOCROOT/.env.php" ]; then
    log "❌ No encuentro $DOCROOT/.env.php — ¿cambió el doc root?"
    exit 1
fi
read_env() {
    php -r '$e = require $argv[1]; $k = $argv[2]; echo isset($e[$k]) ? $e[$k] : "";' "$DOCROOT/.env.php" "$1"
}
DB_HOST="$(read_env DB_HOST)"
DB_NAME="$(read_env DB_NAME)"
DB_USER="$(read_env DB_USER)"
DB_PASS="$(read_env DB_PASS)"
DB_PORT="$(read_env DB_PORT)"   # opcional; producción usa el socket local

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    log "❌ No pude leer las credenciales de .env.php"
    exit 1
fi

# Cinturón anti-Campus: este script solo puede tocar la BD de iarepo.
case "$DB_NAME" in
    *resources*) : ;;
    *) log "❌ ABORTO: DB_NAME='$DB_NAME' no parece la BD de iarepo. Este script no toca otras bases."; exit 1 ;;
esac

# ── 2. Fichero de opciones temporal (600) ────────────────────────
umask 077
CNF="$(mktemp "${TMPDIR:-/tmp}/iarepo_cnf.XXXXXX")"
trap 'rm -f "$CNF"' EXIT
{
    echo "[client]"
    echo "host=${DB_HOST:-localhost}"
    [ -n "$DB_PORT" ] && echo "port=$DB_PORT"
    echo "user=$DB_USER"
    echo "password=$DB_PASS"
} > "$CNF"

# ── 3. Dump ──────────────────────────────────────────────────────
mkdir -p "$OUT_DIR"
DUMP="$OUT_DIR/resources.sql.gz"
PARTIAL="$DUMP.partial"
# El dump se escribe SIEMPRE en .partial y solo se renombra si pasa las
# verificaciones: así nunca queda en su sitio un fichero a medias que
# parezca el backup del día.
trap 'rm -f "$CNF" "$PARTIAL"' EXIT
log "▶ Volcando $DB_NAME → $DUMP"

"$MYSQLDUMP" --defaults-extra-file="$CNF" \
    --single-transaction --quick --skip-lock-tables \
    --default-character-set=utf8mb4 \
    --routines --events --triggers \
    "$DB_NAME" | gzip -9 > "$PARTIAL"

# ── 4. Verificación (un backup sin verificar no es un backup) ────
DUMP_CHECK="$PARTIAL"
if ! gzip -t "$DUMP_CHECK" 2>/dev/null; then
    log "❌ El gzip está corrupto: $DUMP_CHECK"
    exit 1
fi
SIZE=$(stat -c %s "$DUMP_CHECK")
TABLES=$(zcat "$DUMP_CHECK" | grep -c '^CREATE TABLE' || true)
ROWS=$(zcat "$DUMP_CHECK" | grep -c '^INSERT INTO' || true)

# (a) Marcador de fin: mysqldump lo escribe al terminar bien. Es la señal
#     fiable de "no truncado"; el tamaño no lo es (una BD pequeña da un
#     dump pequeño y legítimo — se aprendió ensayando este script).
if ! zcat "$DUMP_CHECK" | tail -5 | grep -q '^-- Dump completed'; then
    log "❌ El dump no termina en '-- Dump completed' → truncado o interrumpido"
    exit 1
fi

# (b) Tantos CREATE TABLE como tablas tenga la BD viva. Detecta un volcado
#     parcial por permisos, que es el fallo silencioso clásico.
LIVE_TABLES="$(php -r '
    $e = require $argv[1];
    $dsn = "mysql:host=" . ($e["DB_HOST"] ?? "localhost")
         . (isset($e["DB_PORT"]) && $e["DB_PORT"] !== "" ? ";port=" . $e["DB_PORT"] : "")
         . ";dbname=" . $e["DB_NAME"];
    try {
        $db = new PDO($dsn, $e["DB_USER"], $e["DB_PASS"]);
        echo (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    } catch (Throwable $ex) { echo "0"; }
' "$DOCROOT/.env.php" 2>/dev/null || echo 0)"

if [ "$LIVE_TABLES" -gt 0 ] && [ "$TABLES" -lt "$LIVE_TABLES" ]; then
    log "❌ El dump trae $TABLES tablas y la BD tiene $LIVE_TABLES — volcado incompleto"
    exit 1
fi
if [ "$SIZE" -lt 1024 ]; then
    log "❌ El dump pesa solo $SIZE bytes — absurdo, no lo doy por bueno"
    exit 1
fi

# Superadas las comprobaciones: ahora sí ocupa su sitio.
mv "$PARTIAL" "$DUMP"
trap 'rm -f "$CNF"' EXIT
log "✅ OK · $(numfmt --to=iec "$SIZE" 2>/dev/null || echo "$SIZE B") · $TABLES/$LIVE_TABLES tablas · $ROWS sentencias INSERT"

# ── 5. Retención ─────────────────────────────────────────────────
find "$DEST/db" -maxdepth 1 -type d -name '20*' -mtime "+$KEEP_DAYS" -print -exec rm -rf {} + 2>/dev/null | \
    while read -r old; do log "🗑  purgado $old"; done || true

# ── 6. Miniaturas: semanal (domingos), no están en git ───────────
if [ "$(date +%u)" = "7" ]; then
    THUMBS="$DOCROOT/thumbnails"
    if [ -d "$THUMBS" ]; then
        TAR="$DEST/db/$STAMP/thumbnails.tar.gz"
        tar -czf "$TAR" -C "$DOCROOT" thumbnails
        log "✅ miniaturas · $(numfmt --to=iec "$(stat -c %s "$TAR")" 2>/dev/null || stat -c %s "$TAR") · $(ls -1 "$THUMBS" | wc -l) ficheros"
    fi
fi

log "🏁 Backup completo en $OUT_DIR"
