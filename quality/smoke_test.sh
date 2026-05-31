#!/bin/bash
# ================================================================
# quality/smoke_test.sh — Smoke tests post-deploy para iarepo.com
#
# Uso:
#   ./quality/smoke_test.sh              # Testea producción
#   ./quality/smoke_test.sh staging      # Testea staging (si existe)
#
# Retorna exit code 1 si algún test falla.
# ================================================================

BASE="${1:-https://iarepo.com}"
PASS=0
FAIL=0
ERRORS=()

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

check() {
    local desc="$1"
    local path="$2"
    local expected_code="${3:-200}"
    local must_contain="$4"

    local body
    local code
    code=$(curl -s -o /tmp/iarepo_smoke -w "%{http_code}" --max-time 15 "${BASE}${path}")
    body=$(cat /tmp/iarepo_smoke)

    if [ "$code" != "$expected_code" ]; then
        echo -e "${RED}❌ FAIL${NC} [HTTP $code, esperado $expected_code] $desc"
        ERRORS+=("$desc — HTTP $code en ${BASE}${path}")
        FAIL=$((FAIL + 1))
        return
    fi

    if [ -n "$must_contain" ] && ! echo "$body" | grep -q "$must_contain"; then
        echo -e "${RED}❌ FAIL${NC} [falta: '$must_contain'] $desc"
        ERRORS+=("$desc — respuesta sin '$must_contain'")
        FAIL=$((FAIL + 1))
        return
    fi

    echo -e "${GREEN}✅ PASS${NC} $desc"
    PASS=$((PASS + 1))
}

check_json() {
    local desc="$1"
    local path="$2"
    local must_have_key="$3"

    local body
    body=$(curl -s --max-time 10 "${BASE}${path}")
    local ok
    ok=$(echo "$body" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('ok') else 'no')" 2>/dev/null)

    if [ "$ok" != "yes" ]; then
        echo -e "${RED}❌ FAIL${NC} [JSON ok≠true] $desc"
        ERRORS+=("$desc — API no retorna ok:true")
        FAIL=$((FAIL + 1))
        return
    fi

    if [ -n "$must_have_key" ]; then
        local has_key
        has_key=$(echo "$body" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if '$must_have_key' in d else 'no')" 2>/dev/null)
        if [ "$has_key" != "yes" ]; then
            echo -e "${RED}❌ FAIL${NC} [falta key '$must_have_key'] $desc"
            ERRORS+=("$desc — falta key '$must_have_key' en respuesta")
            FAIL=$((FAIL + 1))
            return
        fi
    fi

    echo -e "${GREEN}✅ PASS${NC} $desc"
    PASS=$((PASS + 1))
}

echo ""
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${YELLOW} iarepo Smoke Tests — $BASE${NC}"
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

echo "── Páginas ──────────────────────────────"
check  "Landing homepage"        "/"                    200  'class="fcard"'
check  "Landing: 8 featured"     "/"                    200  'class="fcard"'
check  "Resource detail"         "/resource/3"          200  'class="preview-card"'
check  "Resource con JSON-LD"    "/resource/3"          200  'LearningResource'
check  "Viewer page"             "/view/3"              200  'class="viewer-bar"'
check  "Viewer noindex"          "/view/3"              200  'noindex'
check  "Profile page"            "/profile/1"           200  'class="profile-header"'
check  "Dashboard redirect"      "/dashboard/"          302  ""
check  "Collection redirect"     "/collection/?id=9999" 302  ""

echo ""
echo "── Assets ───────────────────────────────"
check  "Favicon SVG"             "/favicon.svg"                200  '<svg'
check  "Logo SVG"                "/assets/img/logo.svg"        200  '<svg'
check  "Logo icon SVG"           "/assets/img/logo-icon.svg"   200  '<svg'
check  "Google verification"     "/googlea678a8f7662b4bda.html" 200  'google-site-verification'

echo ""
echo "── SEO ──────────────────────────────────"
check  "Sitemap XML"             "/sitemap.xml"         200  '<urlset'
check  "Sitemap sin /view/"      "/sitemap.xml"         200  'image:image'
check  "Robots.txt"              "/robots.txt"          200  'Sitemap:'
check  "llms.txt"                "/llms.txt"            200  ""

echo ""
echo "── API ──────────────────────────────────"
check_json "Health check"              "/api/health.php"              "resources"
check_json "Resources list"            "/api/resources.php?limit=1"   "resources"
check_json "Resource single"           "/api/resources.php?id=3"      "resource"
check_json "Resource con tags"         "/api/resources.php?id=3"      "resource"
check      "Comments (público)"        "/api/comments.php?resource_id=3" 200 '"ok"'
check      "Likes (público)"           "/api/likes.php?id=3"          200 '"ok"'

echo ""
echo "── Seguridad ────────────────────────────"
check  "Cron sin token → 403"    "/cron/run.php?job=link_check&token=wrong" 403 ""
check  "Setup bloqueado"         "/setup/schema.sql"            403 ""
check  "Shared bloqueado"        "/shared/db.php"               403 ""
check  "Admin bloqueado"         "/admin/"                      403 ""

echo ""
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

if [ $FAIL -eq 0 ]; then
    echo -e "${GREEN}✅ Todos los tests pasaron ($PASS/$((PASS + FAIL)))${NC}"
else
    echo -e "${RED}❌ $FAIL tests fallaron, $PASS pasaron${NC}"
    echo ""
    echo "Errores:"
    for err in "${ERRORS[@]}"; do
        echo -e "  ${RED}•${NC} $err"
    done
fi

echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

[ $FAIL -eq 0 ] && exit 0 || exit 1
