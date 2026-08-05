#!/bin/bash
# ================================================================
# quality/verify_deploy.sh — ¿aterrizó de verdad el commit en producción?
#
# Uso:
#   bash quality/verify_deploy.sh                        # contra producción
#   bash quality/verify_deploy.sh https://iarepo.com     # base URL explícita
#   bash quality/verify_deploy.sh https://iarepo.com sw.js robots.txt
#
# Mientras api/health.php no publique el SHA desplegado (RUNBOOK §8.5), la
# única forma de saber si el `checkout -f` aterrizó es comparar el sha256 de
# unos cuantos ficheros ESTÁTICOS del checkout con lo que sirve el servidor.
# Es un apaño, y lo dice el RUNBOOK: la solución buena es el post-receive
# escribiendo el SHA. Este script sólo convierte en algo reejecutable el bucle
# suelto de RUNBOOK §5.
#
# Sólo se comparan ficheros que viajan VERBATIM en el repo (nada de PHP, que
# se ejecuta, ni de HTML generado): si uno difiere, el deploy no llegó.
#
# Exit codes:
#   0  todos los ficheros coinciden.
#   1  al menos uno difiere → el deploy NO aterrizó (o hay caché por medio).
#   2  no se pudo comparar (fichero local ausente, la descarga no dio 200…):
#      resultado NO concluyente, que no es lo mismo que "todo bien".
# ================================================================

BASE="https://iarepo.com"
case "$1" in
    http://*|https://*) BASE="$1"; shift ;;
esac

# Ficheros por defecto (los de RUNBOOK §5). Se pueden pasar otros como args.
FILES=("$@")
if [ ${#FILES[@]} -eq 0 ]; then
    FILES=(assets/js/pwa.js sw.js favicon.svg assets/img/logo.svg assets/js/lucide.min.js)
fi

# El script se ejecuta desde donde sea; las rutas son relativas a la raíz del repo.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# hash_stdin — sha256 de stdin, con los tres binarios que puede haber a mano
# (Hostinger compartido no siempre trae coreutils completo).
HASHER=""
if   command -v sha256sum >/dev/null 2>&1; then HASHER="sha256sum"
elif command -v shasum    >/dev/null 2>&1; then HASHER="shasum -a 256"
elif command -v php       >/dev/null 2>&1; then HASHER="php"
fi
if [ -z "$HASHER" ]; then
    echo -e "${RED}❌ ABORTA: no hay sha256sum, shasum ni php para calcular hashes.${NC}" >&2
    exit 2
fi

hash_stdin() {
    case "$HASHER" in
        php) php -r 'echo hash("sha256", stream_get_contents(STDIN));' ;;
        *)   $HASHER | cut -d" " -f1 ;;
    esac
}

TMP="${TMPDIR:-/tmp}/iarepo_verify_deploy.$$"
trap 'rm -f "$TMP"' EXIT

OK=0
DIFF=0
UNKNOWN=0

echo ""
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${YELLOW} verify_deploy — $BASE${NC}"
echo -e "${YELLOW} local: $ROOT${NC}"
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

for f in "${FILES[@]}"; do
    if [ ! -f "$ROOT/$f" ]; then
        echo -e "${YELLOW}⚠️  INDET${NC} $f — no existe en el checkout local"
        UNKNOWN=$((UNKNOWN + 1))
        continue
    fi

    code=$(curl -s -o "$TMP" -w "%{http_code}" --max-time 20 "$BASE/$f")
    if [ "$code" != "200" ]; then
        echo -e "${YELLOW}⚠️  INDET${NC} $f — HTTP $code al descargarlo (no hay nada que comparar)"
        UNKNOWN=$((UNKNOWN + 1))
        continue
    fi

    local_h=$(hash_stdin < "$ROOT/$f" | cut -c1-16)
    prod_h=$(hash_stdin < "$TMP" | cut -c1-16)

    if [ "$local_h" = "$prod_h" ]; then
        echo -e "${GREEN}✅ ok  ${NC} $f"
        OK=$((OK + 1))
    else
        echo -e "${RED}❌ DIFF${NC} $f  local=$local_h prod=$prod_h"
        DIFF=$((DIFF + 1))
    fi
done

echo ""
if [ $DIFF -gt 0 ]; then
    echo -e "${RED}❌ $DIFF de ${#FILES[@]} ficheros NO coinciden: el deploy no aterrizó.${NC}"
    echo -e "   Antes de dar por malo el push, descarta la caché (LiteSpeed y el"
    echo -e "   service worker sirven copias viejas) y comprueba que el checkout"
    echo -e "   del servidor apunta al commit que empujaste (RUNBOOK §7.3)."
elif [ $UNKNOWN -gt 0 ] && [ $OK -eq 0 ]; then
    echo -e "${YELLOW}⚠️  No se pudo comparar ningún fichero: resultado NO concluyente.${NC}"
elif [ $UNKNOWN -gt 0 ]; then
    echo -e "${YELLOW}⚠️  $OK coinciden, pero $UNKNOWN no se pudieron comparar.${NC}"
else
    echo -e "${GREEN}✅ Los $OK ficheros coinciden: el checkout desplegado es este.${NC}"
fi
echo ""

[ $DIFF -gt 0 ] && exit 1
[ $UNKNOWN -gt 0 ] && exit 2
exit 0
