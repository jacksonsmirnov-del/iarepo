#!/bin/bash
# ================================================================
# setup/tools/generate-thumbnails.sh
#
# Generates OG image thumbnails for all community resources
# using headless Chrome (locally installed), and uploads them
# to the server over scp.
#
# Usage:
#   ./setup/tools/generate-thumbnails.sh           # All resources
#   ./setup/tools/generate-thumbnails.sh 3 5 100   # Specific IDs
#
# Prerequisites: google-chrome or chromium installed locally.
#
# ── Configuración (OBLIGATORIA) ─────────────────────────────────
# Este repo es PÚBLICO (se espeja en GitHub), así que las coordenadas del
# servidor NO se escriben aquí. Se leen, por este orden de prioridad:
#
#   1. Variables de entorno.
#   2. Un fichero de configuración local, fuera de git:
#        setup/tools/deploy.env      (está en .gitignore)
#      o el que indique $IAREPO_ENV_FILE.
#
# Variables reconocidas:
#   IAREPO_SSH_HOST    usuario@host del servidor          (obligatoria)
#   IAREPO_SSH_PORT    puerto SSH                         (obligatoria)
#   IAREPO_THUMBS_DIR  ruta absoluta de thumbnails/ allí  (obligatoria)
#   IAREPO_BASE_URL    base de las páginas a capturar     (opc., def. iarepo.com)
#
# Para crear el fichero:
#   cp setup/tools/deploy.env.example setup/tools/deploy.env
#   # y rellenarlo con los valores reales
# ================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# El entorno manda sobre el fichero: se guardan los valores del entorno ANTES
# de cargar el fichero, y se reponen después.
ENV_HOST="${IAREPO_SSH_HOST:-}"
ENV_PORT="${IAREPO_SSH_PORT:-}"
ENV_DIR="${IAREPO_THUMBS_DIR:-}"
ENV_BASE="${IAREPO_BASE_URL:-}"

CONFIG_FILE="${IAREPO_ENV_FILE:-$SCRIPT_DIR/deploy.env}"
if [ -f "$CONFIG_FILE" ]; then
    # shellcheck disable=SC1090
    . "$CONFIG_FILE"
fi

REMOTE="${ENV_HOST:-${IAREPO_SSH_HOST:-}}"
PORT="${ENV_PORT:-${IAREPO_SSH_PORT:-}}"
REMOTE_DIR="${ENV_DIR:-${IAREPO_THUMBS_DIR:-}}"
BASE_URL="${ENV_BASE:-${IAREPO_BASE_URL:-https://iarepo.com/view}}"

MISSING=""
[ -n "$REMOTE" ]     || MISSING="$MISSING IAREPO_SSH_HOST"
[ -n "$PORT" ]       || MISSING="$MISSING IAREPO_SSH_PORT"
[ -n "$REMOTE_DIR" ] || MISSING="$MISSING IAREPO_THUMBS_DIR"
if [ -n "$MISSING" ]; then
    echo "❌ Falta configuración:$MISSING" >&2
    echo "" >&2
    echo "   Las coordenadas del servidor no viven en el repo (es público)." >&2
    echo "   Defínelas por entorno, o crea el fichero local (ignorado por git):" >&2
    echo "     cp setup/tools/deploy.env.example setup/tools/deploy.env" >&2
    echo "     \$EDITOR setup/tools/deploy.env" >&2
    echo "" >&2
    echo "   Fichero de configuración buscado: $CONFIG_FILE" >&2
    exit 1
fi

LOCAL_TMP="${IAREPO_THUMBS_TMP:-/tmp/og-thumbnails}"

# Find Chrome.
# El `|| true` es imprescindible: `which a b c` devuelve estado 1 si CUALQUIERA
# de los tres no está, y con `set -euo pipefail` eso mataba el script aquí
# mismo, sin imprimir nada, en toda máquina que no tuviera los tres binarios.
CHROME=$( (which google-chrome chromium chromium-browser 2>/dev/null || true) | head -1)
if [ -z "$CHROME" ]; then
    echo "❌ No Chrome/Chromium found. Install google-chrome first."
    exit 1
fi
echo "🌐 Using: $CHROME"

# Create local temp dir
mkdir -p "$LOCAL_TMP"

# Ensure remote directory exists
ssh -p "$PORT" "$REMOTE" "mkdir -p $REMOTE_DIR"

# Get resource IDs
if [ "$#" -gt 0 ]; then
    IDS=("$@")
else
    echo "📋 Fetching resource IDs from API..."
    IDS=($(curl -s "https://iarepo.com/api/resources.php?limit=100&sort=popular" | python3 -c "
import json, sys
data = json.load(sys.stdin)
if data.get('ok'):
    for r in data['resources']:
        print(r['id'])
"))
fi

if [ "${#IDS[@]}" -eq 0 ]; then
    echo "❌ No hay IDs que capturar (¿respondió la API?)." >&2
    exit 1
fi

echo "📸 Generating thumbnails for ${#IDS[@]} resources..."
echo ""

SUCCESS=0
FAIL=0

for ID in "${IDS[@]}"; do
    URL="${BASE_URL}/${ID}?mode=present"
    OUTPUT="${LOCAL_TMP}/og-${ID}.png"

    echo -n "  [$ID] Capturing... "

    # Capture with headless Chrome
    timeout 20 "$CHROME" \
        --headless=new \
        --disable-gpu \
        --no-sandbox \
        --disable-dev-shm-usage \
        --window-size=1200,630 \
        --screenshot="$OUTPUT" \
        --hide-scrollbars \
        --default-background-color=0 \
        "$URL" 2>/dev/null

    if [ -f "$OUTPUT" ] && [ -s "$OUTPUT" ]; then
        # Upload to server
        scp -P "$PORT" -q "$OUTPUT" "${REMOTE}:${REMOTE_DIR}/og-${ID}.png"
        SIZE=$(du -h "$OUTPUT" | cut -f1)
        echo "✅ ($SIZE) → uploaded"
        # Ojo: nada de ((SUCCESS++)) — con SUCCESS=0 el post-incremento
        # devuelve 0, que en (( )) es estado 1, y `set -e` mataba el script
        # justo en la primera captura buena.
        SUCCESS=$((SUCCESS + 1))
    else
        echo "❌ Failed"
        FAIL=$((FAIL + 1))
    fi
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Success: $SUCCESS"
echo "❌ Failed:  $FAIL"
echo "📁 Remote:  $REMOTE_DIR/"
echo ""
echo "🔗 Test: https://iarepo.com/thumbnails/og-${IDS[0]}.png"
