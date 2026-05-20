#!/bin/bash
# ================================================================
# setup/tools/generate-thumbnails.sh
#
# Generates OG image thumbnails for all community resources
# using headless Chrome (locally installed).
#
# Usage:
#   ./setup/tools/generate-thumbnails.sh           # All resources
#   ./setup/tools/generate-thumbnails.sh 3 5 100   # Specific IDs
#
# Prerequisites: google-chrome or chromium installed locally
# Output: Uploads PNGs to server at /home/u403412230/domains/resources.claseprivada.com/public_html/thumbnails/
# ================================================================

set -euo pipefail

REMOTE="u403412230@145.223.106.219"
PORT="65002"
REMOTE_DIR="/home/u403412230/domains/resources.claseprivada.com/public_html/thumbnails"
LOCAL_TMP="/tmp/og-thumbnails"
BASE_URL="https://iarepo.com/view"

# Find Chrome
CHROME=$(which google-chrome chromium chromium-browser 2>/dev/null | head -1)
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
        ((SUCCESS++))
    else
        echo "❌ Failed"
        ((FAIL++))
    fi
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Success: $SUCCESS"
echo "❌ Failed:  $FAIL"
echo "📁 Remote:  $REMOTE_DIR/"
echo ""
echo "🔗 Test: https://iarepo.com/thumbnails/og-${IDS[0]}.png"
