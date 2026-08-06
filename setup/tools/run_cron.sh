#!/bin/bash
# ================================================================
# setup/tools/run_cron.sh — Lanzador local de los jobs de cron/run.php
#
# POR QUÉ EXISTE
#   Los jobs se disparaban desde cron-job.org (servicio externo). El job
#   `link_check` dejó de invocarse el 2026-05-30 y NADIE se enteró durante
#   66 días: el endpoint respondía perfectamente… a nadie. Un scheduler
#   externo es una dependencia más que se puede caer sin avisar.
#
#   Con este lanzador los crons viven en el panel del propio hosting, junto
#   al del backup, y el secreto NO se escribe en el panel: se lee de
#   .env.php en cada ejecución.
#
# USO
#   run_cron.sh <job>          job ∈ link_check | moderation
#
# ALTA EN EL PANEL (hPanel → Cron Jobs). Sustituye <DOC_ROOT> y <LOG_DIR>
# por tus rutas absolutas (aquí no se escriben: el repo es público):
#
#   0 */6 * * *   <DOC_ROOT>/setup/tools/run_cron.sh link_check  >> <LOG_DIR>/cron.log 2>&1
#   */15 * * * *  <DOC_ROOT>/setup/tools/run_cron.sh moderation  >> <LOG_DIR>/cron.log 2>&1
#
#   ⚠️ `moderation` cada 2 minutos era lo que pedía la documentación vieja.
#   Con OPEN_REGISTRATION apagado ese job no hace nada, y el plan de hosting
#   es COMPARTIDO con Campus (60 workers PHP): 720 ejecuciones diarias en
#   vacío es tributo que le quitas a otro. Cada 15 min sobra; súbelo cuando
#   enciendas la moderación.
#
# El latido queda en la tabla `cron_heartbeats`, así que la próxima vez que
# uno de estos jobs deje de correr se verá en api/health.php y el smoke test
# se pondrá en rojo — que es exactamente lo que no pasó en mayo.
# ================================================================

set -uo pipefail

JOB="${1:-}"
case "$JOB" in
    link_check|moderation) : ;;
    *) echo "uso: $(basename "$0") <link_check|moderation>" >&2; exit 2 ;;
esac

# El doc root sale de la ubicación del propio script (setup/tools/ → raíz),
# o de IAREPO_DOCROOT si se quiere forzar para pruebas.
DOCROOT="${IAREPO_DOCROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
BASE_URL="${IAREPO_BASE_URL:-https://iarepo.com}"

log() { echo "[$(date '+%F %T')] [$JOB] $*"; }

if [ ! -f "$DOCROOT/.env.php" ]; then
    log "❌ No encuentro $DOCROOT/.env.php"
    exit 1
fi
command -v php  >/dev/null 2>&1 || { log "❌ falta php";  exit 1; }
command -v curl >/dev/null 2>&1 || { log "❌ falta curl"; exit 1; }

TOKEN="$(php -r '$e = require $argv[1]; echo $e["CRON_SECRET"] ?? "";' "$DOCROOT/.env.php")"
if [ -z "$TOKEN" ]; then
    log "❌ CRON_SECRET vacío en .env.php"
    exit 1
fi

# --get + --data-urlencode: el token nunca se interpola en la URL a mano, así
# que un secreto con caracteres raros no rompe la petición.
BODY="$(curl -sS --max-time 300 --get \
    --data-urlencode "job=$JOB" \
    --data-urlencode "token=$TOKEN" \
    -w '\n%{http_code}' \
    "$BASE_URL/cron/run.php" 2>&1)"
CODE="$(printf '%s' "$BODY" | tail -n1)"
JSON="$(printf '%s' "$BODY" | sed '$d')"

if [ "$CODE" != "200" ]; then
    log "❌ HTTP $CODE — $JSON"
    exit 1
fi
case "$JSON" in
    *'"ok":true'*) log "✅ $JSON" ;;
    *) log "❌ respuesta inesperada — $JSON"; exit 1 ;;
esac
