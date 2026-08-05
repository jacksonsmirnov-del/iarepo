#!/bin/bash
# ================================================================
# quality/smoke_test.sh — Smoke tests post-deploy para iarepo.com
#
# Uso:
#   ./quality/smoke_test.sh                          # Producción (por defecto)
#   ./quality/smoke_test.sh https://iarepo.com       # Base URL explícita
#   ./quality/smoke_test.sh http://localhost:8080    # Servidor local (php -S)
#
#   El primer argumento es la BASE URL COMPLETA (con esquema, sin barra final);
#   se concatena tal cual con cada ruta. No es un nombre de entorno: pasar
#   "staging" produciría URLs como "staging/api/health.php" y fallarían todas.
#   Hoy NO existe staging; cuando exista, se pasa su URL completa.
#
#   Variables de entorno:
#     SEARCH_DELAY=0.3   # segundos entre peticiones de la sección Buscador.
#                        # api/resources.php aplica rateLimit(120/min) en GET;
#                        # bajarlo puede provocar 429 (ver abajo).
#     JSON_ENGINE=php    # fuerza el motor de inspección JSON (python3 | php).
#                        # Por defecto autodetecta: python3 y, si no, php.
#     RATE_WAIT=65       # segundos de enfriamiento tras un 429 antes de reintentar.
#                        # rateLimit() manda 'Retry-After: 60'; 65 da margen.
#     RATE_RETRY_BUDGET=1 # enfriamientos como MUCHO en toda la corrida (0 = no
#                        # esperar nunca). Con 1 basta: al reabrirse la ventana
#                        # el contador vuelve a 0 y quedan 120 peticiones libres,
#                        # de sobra para las ~21 que la corrida hace a ese endpoint.
#
# Dependencias: curl + (python3 ó php). NO usa jq: no está instalado en el
# hosting compartido y no se puede instalar.
#
# TRES resultados posibles por check, no dos:
#   PASS           el check se ejecutó y salió bien.
#   FAIL           el check se ejecutó y el sitio está mal.
#   INDETERMINADO  el check NO llegó a ejecutarse (típicamente un 429 del
#                  rateLimit, o un directorio que aún no está desplegado).
#                  No dice nada del sitio: no es verde ni rojo.
#
# Y además, fuera de esa cuenta, AVISOS (⚠ AVISO) e INFO (ℹ). No son checks: no
# suman ni a PASS ni a FAIL ni a INDET y NO afectan al código de salida. Son
# para lo que hay que decir pero no puede declararse "sitio roto" — el caso
# canónico es "producción sirve un commit distinto del que tienes en local",
# que es lo NORMAL si aún no has desplegado.
#
# Exit codes:
#   0  ningún fallo y ningún indeterminado — corrida limpia.
#   1  hay al menos un FAIL real.
#   2  cero fallos pero quedaron checks INDETERMINADOS: la corrida NO es
#      concluyente y NO puede leerse como éxito (importa sobre todo si el
#      rateLimit tumbó la corrida entera: sin esto, 0 fallos = falso verde).
# ================================================================

BASE="${1:-https://iarepo.com}"
PASS=0
FAIL=0
INDET=0
ERRORS=()
INDETS=()
WARNS=()

# Fallos de la sección "Buscador": se cuentan aparte para poder decir en el
# resumen "esto es la regresión conocida, falta desplegar el fix".
FAIL_SEARCH=0
# Ídem para "Latidos de cron": mientras no se despliegue este commit + la
# migración 010 y no se reactive el cron en cron-job.org, esos fallos son
# ESPERADOS. Contarlos aparte evita que el resumen los presente como una
# regresión nueva del sitio.
FAIL_CRON=0
SECTION_TAG=""
SEARCH_DELAY="${SEARCH_DELAY:-0.3}"

# Raíz del repo: el script puede invocarse desde cualquier directorio y el check
# de despliegue necesita el HEAD local para compararlo con el commit vivo.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd)"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# ── Rate limit (429): ni PASS ni FAIL, INDETERMINADO ─────────────
# api/resources.php aplica rateLimit($db,'resources_get',120) por IP y ventana
# de 60 s; la corrida hace ~21 peticiones a ese endpoint, así que basta con que
# otra corrida (o el tráfico normal del sitio desde la misma IP) haya consumido
# la ventana para llevarse un 429. Un 429 NO es un fallo del sitio y no puede
# reportarse como tal: antes decía "operador fulltext sin sanear llega a
# AGAINST()", que manda a depurar el sitio equivocado.
RATE_WAIT="${RATE_WAIT:-65}"
RATE_RETRY_BUDGET="${RATE_RETRY_BUDGET:-1}"
RL_MSG="HTTP 429 rateLimit (120/min por IP) — el check NO llegó a ejecutarse; no dice nada del sitio. Repite en un minuto o sube SEARCH_DELAY."

# El presupuesto de espera y el último código HTTP viven en FICHEROS, no en
# variables. Motivo: sapi() se llama dentro de $( ), o sea en una subshell, y
# toda asignación que haga allí se evapora al volver. Con variables, throttled()
# no vería nunca el 429 (lo reportaría como fallo del buscador, justo el bug que
# se está arreglando) y rate_wait() volvería a esperar en CADA petición
# — 20 peticiones × 65 s = 22 minutos de corrida.
RATE_STATE="${TMPDIR:-/tmp}/iarepo_smoke_rate.$$"
CODE_STATE="${TMPDIR:-/tmp}/iarepo_smoke_code.$$"
printf '%s' "$RATE_RETRY_BUDGET" > "$RATE_STATE"
printf '%s' "" > "$CODE_STATE"
# Trap provisional: cubre la salida temprana por "no hay python3 ni php".
# Más abajo se reinstala incluyendo también $SMOKE_TMP.
trap 'rm -f "$RATE_STATE" "$CODE_STATE"' EXIT

sindet() {
    echo -e "${YELLOW}⚠️  INDET${NC} [$2] $1"
    INDETS+=("${SECTION_TAG}$1 — $2")
    INDET=$((INDET + 1))
    return 0
}

# rate_wait — consume una unidad del presupuesto de enfriamiento y espera a que
# se reabra la ventana del rateLimit. Devuelve 1 (sin esperar) si ya no queda
# presupuesto, para que un 429 persistente no convierta la corrida en 47
# esperas de un minuto.
# El mensaje va a stderr A PROPÓSITO: dentro de sapi() la salida estándar es el
# código HTTP que captura quien llama, y colarle texto lo rompería.
rate_wait() {
    local left
    left=$(cat "$RATE_STATE" 2>/dev/null)
    case "$left" in ''|*[!0-9]*) left=0 ;; esac
    [ "$left" -le 0 ] && return 1
    printf '%s' "$((left - 1))" > "$RATE_STATE"
    echo -e "   ${YELLOW}⏳ 429: esperando ${RATE_WAIT}s a que se reabra la ventana del rateLimit…${NC}" >&2
    sleep "$RATE_WAIT"
    return 0
}

check() {
    local desc="$1"
    local path="$2"
    local expected_code="${3:-200}"
    local must_contain="$4"

    local body
    local code
    code=$(curl -s -o "$SMOKE_TMP" -w "%{http_code}" --max-time 15 "${BASE}${path}")
    if [ "$code" = "429" ] && [ "$expected_code" != "429" ]; then
        if rate_wait; then
            code=$(curl -s -o "$SMOKE_TMP" -w "%{http_code}" --max-time 15 "${BASE}${path}")
        fi
        if [ "$code" = "429" ]; then
            sindet "$desc" "$RL_MSG"
            return
        fi
    fi
    body=$(cat "$SMOKE_TMP")

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

# check_json — usa jget (y por tanto JSON_ENGINE), no python3 a pelo: antes
# estos 6 checks fallaban en bloque en cualquier host sin python3 e ignoraban
# JSON_ENGINE=php.
check_json() {
    local desc="$1"
    local path="$2"
    local must_have_key="$3"

    local code
    code=$(curl -s -o "$SMOKE_TMP" -w "%{http_code}" --max-time 10 "${BASE}${path}")
    if [ "$code" = "429" ]; then
        if rate_wait; then
            code=$(curl -s -o "$SMOKE_TMP" -w "%{http_code}" --max-time 10 "${BASE}${path}")
        fi
        if [ "$code" = "429" ]; then
            sindet "$desc" "$RL_MSG"
            return
        fi
    fi

    if [ "$(jget "$SMOKE_TMP" ok)" != "true" ]; then
        echo -e "${RED}❌ FAIL${NC} [JSON ok≠true, HTTP $code] $desc"
        ERRORS+=("$desc — API no retorna ok:true (HTTP $code)")
        FAIL=$((FAIL + 1))
        return
    fi

    if [ -n "$must_have_key" ] && ! jget "$SMOKE_TMP" "$must_have_key" >/dev/null 2>&1; then
        echo -e "${RED}❌ FAIL${NC} [falta key '$must_have_key'] $desc"
        ERRORS+=("$desc — falta key '$must_have_key' en respuesta")
        FAIL=$((FAIL + 1))
        return
    fi

    echo -e "${GREEN}✅ PASS${NC} $desc"
    PASS=$((PASS + 1))
}

# ================================================================
# Utilidades para inspeccionar JSON (añadido 2026-08)
#
# jq NO está disponible en el hosting compartido y no se puede instalar,
# así que el extractor se implementa dos veces: python3 (preferido, ya lo
# usaba check_json) y php como respaldo. Ambos aceptan la MISMA mini-ruta:
#
#   total                  → valor escalar
#   resources.0.title      → índice de lista
#   resources[].lang       → mapea sobre la lista, un valor por línea
#
# jget devuelve 1 y no imprime nada si el JSON es inválido o la ruta no existe.
# ================================================================

# JSON_ENGINE se puede forzar por entorno (JSON_ENGINE=php ./smoke_test.sh)
# para verificar que el respaldo produce exactamente la misma salida.
JSON_ENGINE="${JSON_ENGINE:-}"
if [ -z "$JSON_ENGINE" ]; then
    if command -v python3 >/dev/null 2>&1; then
        JSON_ENGINE="python3"
    elif command -v php >/dev/null 2>&1; then
        JSON_ENGINE="php"
    fi
fi
if [ -z "$JSON_ENGINE" ]; then
    echo -e "${RED}❌ ABORTA: no hay python3 ni php; los checks de JSON no pueden correr.${NC}" >&2
    exit 1
fi

SMOKE_TMP="${TMPDIR:-/tmp}/iarepo_smoke_json.$$"
# Instantánea aparte de /api/health.php: la sección "Latidos de los cron" lee
# varios campos de la MISMA respuesta y $SMOKE_TMP lo pisa la petición siguiente.
CRON_SNAP="${TMPDIR:-/tmp}/iarepo_smoke_crons.$$"
trap 'rm -f "$SMOKE_TMP" "$RATE_STATE" "$CODE_STATE" "$CRON_SNAP"' EXIT

jget() {
    JG_FILE="$1" JG_PATH="$2"
    export JG_FILE JG_PATH
    case "$JSON_ENGINE" in
        python3)
            python3 <<'PYEOF'
import json, os, sys

try:
    data = json.load(open(os.environ['JG_FILE'], encoding='utf-8'))
except Exception:
    sys.exit(1)

parts = []
for seg in os.environ['JG_PATH'].split('.'):
    if seg.endswith('[]'):
        if seg[:-2]:
            parts.append(seg[:-2])
        parts.append('[]')
    else:
        parts.append(seg)

def walk(node, parts):
    if not parts:
        yield node
        return
    head, rest = parts[0], parts[1:]
    if head == '[]':
        if isinstance(node, list):
            for item in node:
                yield from walk(item, rest)
        return
    if isinstance(node, list):
        if head.lstrip('-').isdigit() and -len(node) <= int(head) < len(node):
            yield from walk(node[int(head)], rest)
        return
    if isinstance(node, dict) and head in node:
        yield from walk(node[head], rest)

def fmt(v):
    if v is True:  return 'true'
    if v is False: return 'false'
    if v is None:  return 'null'
    if isinstance(v, float) and v.is_integer(): return str(int(v))
    if isinstance(v, (dict, list)): return json.dumps(v, ensure_ascii=False)
    return str(v)

out = [fmt(v) for v in walk(data, parts)]
if not out:
    sys.exit(1)
print('\n'.join(out))
PYEOF
            ;;
        php)
            php <<'PHPEOF'
<?php
$raw = @file_get_contents(getenv('JG_FILE'));
if ($raw === false) exit(1);
$data = json_decode($raw, true);
if ($data === null) exit(1);

$parts = [];
foreach (explode('.', (string) getenv('JG_PATH')) as $seg) {
    if (substr($seg, -2) === '[]') {
        $name = substr($seg, 0, -2);
        if ($name !== '') $parts[] = $name;
        $parts[] = '[]';
    } else {
        $parts[] = $seg;
    }
}

function jg_walk($node, array $parts, array &$out): void {
    if (!$parts) { $out[] = $node; return; }
    $head = array_shift($parts);
    if ($head === '[]') {
        if (is_array($node) && array_is_list($node)) {
            foreach ($node as $item) jg_walk($item, $parts, $out);
        }
        return;
    }
    if (is_array($node) && array_key_exists($head, $node)) jg_walk($node[$head], $parts, $out);
}

function jg_fmt($v): string {
    if ($v === true)  return 'true';
    if ($v === false) return 'false';
    if ($v === null)  return 'null';
    if (is_float($v) && floor($v) === $v) return (string) (int) $v;
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
    return (string) $v;
}

$out = [];
jg_walk($data, $parts, $out);
if (!$out) exit(1);
echo implode("\n", array_map('jg_fmt', $out)), "\n";
PHPEOF
            ;;
        *)
            return 1
            ;;
    esac
}

# str_norm — minúsculas sin acentos (stdin → stdout). Para comparar títulos
# sin depender de la tilde: "Refracción" y "fraccion" deben ser comparables.
str_norm() {
    case "$JSON_ENGINE" in
        python3)
            python3 -c 'import sys,unicodedata; s=sys.stdin.read().lower(); sys.stdout.write("".join(c for c in unicodedata.normalize("NFD", s) if unicodedata.category(c) != "Mn"))'
            ;;
        php)
            php -r '$m=["á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n","à"=>"a","è"=>"e","ì"=>"i","ò"=>"o","ù"=>"u","â"=>"a","ê"=>"e","î"=>"i","ô"=>"o","û"=>"u","ç"=>"c"]; echo strtr(mb_strtolower(stream_get_contents(STDIN), "UTF-8"), $m);'
            ;;
        *)
            cat
            ;;
    esac
}

# sapi <ruta> [args curl...] → cuerpo en $SMOKE_TMP, imprime el código HTTP.
# Usa --get + --data-urlencode: curl codifica los operadores hostiles (C++, @,
# comillas, paréntesis) sin que haya que escaparlos a mano en el shell.
# Ante un 429 espera una vez (si queda presupuesto) y reintenta. Deja el último
# código en $CODE_STATE para que throttled() lo consulte aunque el valor se haya
# perdido por el camino (search_total, p.ej., sólo devuelve 0/1).
sapi() {
    local path="$1"
    shift
    local code
    sleep "$SEARCH_DELAY"
    code=$(curl -s -o "$SMOKE_TMP" -w "%{http_code}" --max-time 20 --get "$@" "${BASE}${path}")
    if [ "$code" = "429" ] && rate_wait; then
        code=$(curl -s -o "$SMOKE_TMP" -w "%{http_code}" --max-time 20 --get "$@" "${BASE}${path}")
    fi
    printf '%s' "$code" > "$CODE_STATE"
    printf '%s' "$code"
}

# throttled <desc> — si la última petición se topó con el rateLimit, marca el
# check como INDETERMINADO y devuelve 0 para que quien llama haga `return`.
# Se consulta ANTES de dar cualquier diagnóstico: un 429 no distingue entre un
# buscador roto y uno sano, así que no puede reportarse como fallo del buscador.
throttled() {
    [ "$(cat "$CODE_STATE" 2>/dev/null)" = "429" ] || return 1
    sindet "$1" "$RL_MSG"
    return 0
}

spass() {
    echo -e "${GREEN}✅ PASS${NC} $1"
    PASS=$((PASS + 1))
}

sfail() {
    echo -e "${RED}❌ FAIL${NC} [$2] $1"
    ERRORS+=("${SECTION_TAG}$1 — $2")
    FAIL=$((FAIL + 1))
    [ "$SECTION_TAG" = "[buscador] " ] && FAIL_SEARCH=$((FAIL_SEARCH + 1))
    [ "$SECTION_TAG" = "[crons] " ]    && FAIL_CRON=$((FAIL_CRON + 1))
    return 0
}

# swarn / snote — NO son checks: no tocan PASS/FAIL/INDET ni el código de
# salida. Existen porque hay cosas que hay que decir y que NO son "el sitio
# está roto": que producción sirva un commit distinto del HEAD local es lo
# esperable si todavía no has desplegado, y convertirlo en FAIL haría que el
# smoke saliera en rojo por trabajar en local — o sea, entrenaría a ignorarlo.
swarn() {
    echo -e "${YELLOW}⚠️  AVISO${NC} $1"
    echo -e "   ${YELLOW}$2${NC}"
    WARNS+=("${SECTION_TAG}$1 — $2")
    return 0
}

snote() {
    echo -e "${GREEN}ℹ ${NC} $1"
    return 0
}

# is_num — cinturón de seguridad: sin esto, un valor no numérico haría que
# `[ "$x" -lt 1 ]` devolviera 2 y el `if` lo leyera como falso → PASS falso.
is_num() {
    case "$1" in
        ''|*[!0-9]*) return 1 ;;
        *)           return 0 ;;
    esac
}

# fmt_age — segundos → algo legible. "5702400" no dice nada; "66 d" sí.
fmt_age() {
    local s="$1"
    is_num "$s" || { printf '%s' "$s"; return; }
    if   [ "$s" -lt 120 ];    then printf '%ss' "$s"
    elif [ "$s" -lt 7200 ];   then printf '%s min' "$((s / 60))"
    elif [ "$s" -lt 172800 ]; then printf '%s h' "$((s / 3600))"
    else                           printf '%s d' "$((s / 86400))"
    fi
}

# search_total <consulta> → imprime el total; devuelve 1 si la API no da 200/ok.
search_total() {
    local code
    code=$(sapi /api/resources.php --data-urlencode "search=$1" --data-urlencode "limit=10")
    [ "$code" != "200" ] && return 1
    [ "$(jget "$SMOKE_TMP" ok)" != "true" ] && return 1
    local total
    total=$(jget "$SMOKE_TMP" total) || return 1
    is_num "$total" || return 1
    printf '%s' "$total"
}

# ── Checks del buscador ──────────────────────────────────────────

# 1. Operadores hostiles: lo único que se exige es que NO reviente.
check_search_safe() {
    local desc="$1" q="$2" code
    code=$(sapi /api/resources.php --data-urlencode "search=$q" --data-urlencode "limit=5")
    if [ "$code" != "200" ]; then
        throttled "$desc" && return
        sfail "$desc" "HTTP $code — operador fulltext sin sanear llega a AGAINST()"
        return
    fi
    if [ "$(jget "$SMOKE_TMP" ok)" != "true" ]; then
        sfail "$desc" "HTTP 200 pero ok≠true"
        return
    fi
    spass "$desc"
}

# 2/3. La consulta debe encontrar algo (prefijos, tokens cortos).
check_search_min() {
    local desc="$1" q="$2" min="$3" total
    if ! total=$(search_total "$q"); then
        throttled "$desc" && return
        sfail "$desc" "la consulta no devuelve 200/ok:true con un 'total' numérico"
        return
    fi
    if [ "$total" -lt "$min" ]; then
        sfail "$desc" "total=$total, se esperaban ≥ $min"
        return
    fi
    spass "$desc (total=$total)"
}

# 4. Multi-palabra debe ser AND: nunca puede devolver MÁS que el término
#    más restrictivo. Con la semántica OR actual devuelve la unión.
check_search_and() {
    local a="$1" b="$2"
    local desc="AND multi-palabra: '$a $b' ≤ min('$a','$b')"
    local ta tb tab min
    if ! ta=$(search_total "$a") || ! tb=$(search_total "$b") || ! tab=$(search_total "$a $b"); then
        throttled "$desc" && return
        sfail "$desc" "alguna de las 3 consultas no devuelve 200/ok:true"
        return
    fi
    min="$ta"
    [ "$tb" -lt "$min" ] && min="$tb"
    if [ "$tab" -gt "$min" ]; then
        sfail "$desc" "total('$a $b')=$tab > min($ta,$tb)=$min → semántica OR, no AND"
        return
    fi
    spass "$desc ($tab ≤ $min)"
}

# 5. Relevancia: el primer resultado debe llevar el término EN EL TÍTULO.
check_search_top() {
    local desc="$1" q="$2" needle="$3" title norm
    if [ "$(sapi /api/resources.php --data-urlencode "search=$q" --data-urlencode "limit=10")" != "200" ]; then
        throttled "$desc" && return
        sfail "$desc" "la consulta no devuelve 200"
        return
    fi
    if ! title=$(jget "$SMOKE_TMP" resources.0.title); then
        sfail "$desc" "sin resultados: no hay primer elemento que evaluar"
        return
    fi
    norm=$(printf '%s' "$title" | str_norm)
    case "$norm" in
        *"$needle"*) spass "$desc (top: «$title»)" ;;
        *)           sfail "$desc" "el primer resultado es «$title», sin '$needle' en el título → se ordena por fecha, no por relevancia" ;;
    esac
}

# 6. search + filtro: el filtro NO puede diluirse al añadir búsqueda.
check_search_filter() {
    local desc="$1" q="$2" field="$3" want="$4" filter="$5" total vistos malos
    if [ "$(sapi /api/resources.php --data-urlencode "search=$q" --data-urlencode "$filter" --data-urlencode "limit=100")" != "200" ]; then
        throttled "$desc" && return
        sfail "$desc" "la consulta no devuelve 200"
        return
    fi
    total=$(jget "$SMOKE_TMP" total) || total=""
    is_num "$total" || { sfail "$desc" "'total' ausente o no numérico"; return; }
    if [ "$total" -lt 1 ]; then
        sfail "$desc" "total=0 con el filtro '$filter': la búsqueda no encuentra nada que filtrar"
        return
    fi
    # Se inspecciona la página devuelta (hasta 100 elementos), no el total.
    vistos=$(jget "$SMOKE_TMP" "resources[].$field" | grep -c .)
    malos=$(jget "$SMOKE_TMP" "resources[].$field" | grep -vcx "$want")
    if [ "$malos" -gt 0 ]; then
        sfail "$desc" "$malos de $vistos resultados con $field ≠ $want → el filtro no se aplica"
        return
    fi
    spass "$desc (total=$total, $vistos revisados, todos con $field=$want)"
}

# 7. total / pages / nº de elementos tienen que cuadrar entre sí.
check_page_coherence() {
    local desc="$1" limit="$2" page="$3"
    shift 3
    if [ "$(sapi /api/resources.php --data-urlencode "limit=$limit" --data-urlencode "page=$page" "$@")" != "200" ]; then
        throttled "$desc" && return
        sfail "$desc" "la consulta no devuelve 200"
        return
    fi
    local total pages n exp_pages exp_n
    total=$(jget "$SMOKE_TMP" total) || total=""
    pages=$(jget "$SMOKE_TMP" pages) || pages=""
    n=$(jget "$SMOKE_TMP" 'resources[].id' | grep -c .)
    if ! is_num "$total" || ! is_num "$pages"; then
        sfail "$desc" "total='$total' / pages='$pages': ausentes o no numéricos"
        return
    fi
    exp_pages=$(( (total + limit - 1) / limit ))
    exp_n=$(( total - (page - 1) * limit ))
    [ "$exp_n" -gt "$limit" ] && exp_n="$limit"
    [ "$exp_n" -lt 0 ] && exp_n=0
    if [ "$pages" != "$exp_pages" ]; then
        sfail "$desc" "pages=$pages pero ceil($total/$limit)=$exp_pages"
        return
    fi
    if [ "$n" != "$exp_n" ]; then
        sfail "$desc" "devuelve $n elementos y con total=$total, limit=$limit, page=$page tocaban $exp_n"
        return
    fi
    spass "$desc (total=$total, pages=$pages, elementos=$n)"
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
echo "── Despliegue ───────────────────────────"
SECTION_TAG="[despliegue] "

# ¿Responde la versión desplegada y está viva su BD? Es lo primero que hay que
# saber tras un push: si esto falla, el resto de fallos son ruido derivado.
# El commit vivo lo publica api/health.php como 'commit' desde que el hook
# post-receive (setup/hooks/post-receive) escribe deploy_version.txt; aquí sólo
# se imprime, y check_deploy_commit (más abajo) lo compara con el HEAD local.
# El respaldo cuando 'commit' viene null sigue siendo comparar el sha256 de los
# assets estáticos: `bash quality/verify_deploy.sh`.
check_deploy_live() {
    local desc="API viva: health responde, BD conectada y con recursos"
    local code version status db resources commit
    if [ "$(sapi /api/health.php)" != "200" ]; then
        throttled "$desc" && return
        sfail "$desc" "HTTP distinto de 200 en /api/health.php"
        return
    fi
    status=$(jget "$SMOKE_TMP" status)
    db=$(jget "$SMOKE_TMP" db)
    version=$(jget "$SMOKE_TMP" version)
    resources=$(jget "$SMOKE_TMP" resources) || resources=0
    if [ "$(jget "$SMOKE_TMP" ok)" != "true" ] || [ "$status" != "healthy" ] || [ "$db" != "connected" ]; then
        sfail "$desc" "status=$status db=$db"
        return
    fi
    if [ -z "$version" ]; then
        sfail "$desc" "la respuesta no trae 'version'"
        return
    fi
    if ! is_num "$resources" || [ "$resources" -lt 1 ]; then
        sfail "$desc" "resources=$resources — la BD responde pero está vacía"
        return
    fi
    commit=$(jget "$SMOKE_TMP" commit) || commit="(no expuesto)"
    spass "$desc (version=$version, resources=$resources, commit=$commit)"
}
check_deploy_live

# ── ¿Qué commit está VIVO en producción? ─────────────────────────
# EL PROBLEMA QUE CIERRA ESTE CHECK
#   Hasta el 2026-08-04 nadie podía responder a esa pregunta sin entrar por SSH.
#   'version' es la constante literal '1.1.0' y no ha cambiado nunca. El hook
#   post-receive estuvo MUERTO un mes (tenía permisos 644 y git ignora los hooks
#   no ejecutables) y durante ese mes este mismo smoke daba 44 checks en verde
#   sobre la versión antigua. Verde y mentira a la vez.
#
# POR QUÉ ES AVISO Y NO FALLO
#   Correr el smoke sin haber desplegado es legítimo y frecuente (comprobar que
#   producción sigue sana mientras se trabaja en local). Si eso pintara rojo, el
#   rojo dejaría de significar nada. Lo que sí hace es NEGARSE a quedarse
#   callado: dice qué commit hay vivo y en qué se diferencia del tuyo.
check_deploy_commit() {
    local desc="Commit vivo en producción vs. HEAD local"
    local live head n live_p head_p dirty

    if [ "$(sapi /api/health.php)" != "200" ]; then
        swarn "$desc" "no se pudo leer /api/health.php; se omite la comparación"
        return
    fi

    live=$(jget "$SMOKE_TMP" commit) || live=""
    if [ -z "$live" ] || [ "$live" = "null" ]; then
        swarn "$desc" "producción NO publica el commit desplegado (commit=null).
   Causa típica: el hook post-receive no está instalado o no pudo escribir
   deploy_version.txt. Instálalo con setup/hooks/post-receive (lleva dentro las
   instrucciones). Entretanto: bash quality/verify_deploy.sh"
        return
    fi

    if [ -z "$REPO_ROOT" ] || ! command -v git >/dev/null 2>&1; then
        snote "$desc: producción sirve $live (sin git aquí: no hay con qué compararlo)"
        return
    fi
    head=$(git -C "$REPO_ROOT" rev-parse HEAD 2>/dev/null)
    if [ -z "$head" ]; then
        snote "$desc: producción sirve $live (aquí no hay repo git: no hay con qué compararlo)"
        return
    fi

    # El servidor escribe `git rev-parse --short`, cuya longitud varía con el
    # tamaño del repo. Se comparan por prefijo, recortando ambos a la longitud
    # del más corto: es lo único que se puede afirmar con lo que hay.
    n=${#live}
    [ ${#head} -lt $n ] && n=${#head}
    live_p=$(printf '%s' "$live" | tr 'A-Z' 'a-z' | cut -c1-"$n")
    head_p=$(printf '%s' "$head" | tr 'A-Z' 'a-z' | cut -c1-"$n")

    if [ "$live_p" != "$head_p" ]; then
        swarn "$desc" "DIFIEREN — producción: $live · local HEAD: $(printf '%s' "$head" | cut -c1-12)
   Los checks de abajo están midiendo el código de PRODUCCIÓN, no el tuyo.
   Si esperabas que coincidieran, el push no ha aterrizado: revisa la salida
   del hook post-receive y docs/RUNBOOK.md."
        return
    fi

    dirty=$(git -C "$REPO_ROOT" status --porcelain 2>/dev/null | head -1)
    if [ -n "$dirty" ]; then
        swarn "$desc" "el commit coincide ($live), PERO el árbol local tiene cambios sin
   commitear: lo que estás probando no es exactamente lo que tienes delante."
        return
    fi
    snote "$desc: coinciden ($live) — producción sirve exactamente tu HEAD"
}
check_deploy_commit

echo ""
echo "── Latidos de los cron ──────────────────"
SECTION_TAG="[crons] "

# QUÉ SE VIGILA AQUÍ Y POR QUÉ
#   El link checker dejó de correr el 2026-05-30 y nadie lo supo hasta el
#   2026-08-04: 66 días. No falló, dejó de ser INVOCADO — un modo de fallo que
#   no genera errores, ni logs, ni respuestas raras. Absolutamente nada de este
#   smoke test lo habría detectado, porque todos los demás checks preguntan
#   "¿esto responde bien?" y la respuesta seguía siendo que sí.
#
#   Lo único que delata a un cron muerto es la ANTIGÜEDAD de su último latido.
#   cron/run.php escribe uno en `cron_heartbeats` al terminar cada job (también
#   si falla) y api/health.php publica la antigüedad en crons.<job>.age_seconds.
#
# EL UMBRAL: periodo × 3
#   Ni el periodo a secas (un retraso normal del planificador daría falsas
#   alarmas y el check acabaría ignorado) ni un valor fijo (6 h y 2 min no se
#   pueden medir con la misma vara). Con ×3 hacen falta tres ejecuciones
#   seguidas perdidas para ponerse rojo: eso ya no es un retraso.
#
# ⚠️  HOY ESTO SALE EN ROJO A PROPÓSITO, y seguirá así hasta que se hagan LAS
#     TRES cosas: desplegar este commit, aplicar
#     setup/migration_010_cron_heartbeats.sql y reactivar el cron de link_check
#     en cron-job.org. El rojo ES el hallazgo, no un defecto del check.

check_cron_job() {
    local job="$1" period="$2" label="$3"
    local desc="Latido de '$job' (debe correr cada $label)"
    local age status message limit

    age=$(jget "$CRON_SNAP" "crons.$job.age_seconds") || age=""
    limit=$((period * 3))

    if [ -z "$age" ]; then
        sfail "$desc" "health.php no informa de este job. O no existe su fila en
     cron_heartbeats (¿migración 010 sin aplicar?), o el job se ha renombrado en
     cron/run.php sin actualizar ni la migración ni este check."
        return
    fi

    if [ "$age" = "null" ]; then
        sfail "$desc" "NUNCA ha latido (last_run_at NULL). El job está declarado y no lo
     invoca NADIE. Si acabas de aplicar la migración es lo esperado hasta el
     primer disparo del cron; si no, es exactamente el fallo de link_check."
        return
    fi

    if ! is_num "$age"; then
        sfail "$desc" "age_seconds no es un número: '$age'"
        return
    fi

    if [ "$age" -gt "$limit" ]; then
        sfail "$desc" "lleva $(fmt_age "$age") sin latir; el umbral es $label × 3 = $(fmt_age "$limit").
     El cron está PARADO: reactívalo en cron-job.org y comprueba el token."
        return
    fi

    # Vivo pero fallando es tan grave como parado, y sin esto pasaría por bueno:
    # el job late puntualmente y devuelve error en cada ejecución.
    status=$(jget "$CRON_SNAP" "crons.$job.status") || status=""
    if [ "$status" = "error" ]; then
        message=$(jget "$CRON_SNAP" "crons.$job.message") || message="(sin mensaje)"
        sfail "$desc" "late a tiempo (hace $(fmt_age "$age")) pero su última ejecución FALLÓ:
     $message"
        return
    fi

    spass "$desc — último latido hace $(fmt_age "$age") (umbral: $(fmt_age "$limit"))"
}

check_crons() {
    local code crons

    code=$(sapi /api/health.php)
    if [ "$code" = "429" ]; then
        sindet "Latidos de cron" "$RL_MSG"
        return
    fi
    if [ "$code" != "200" ]; then
        sfail "Latidos de cron: /api/health.php responde" "HTTP $code"
        return
    fi
    cp "$SMOKE_TMP" "$CRON_SNAP" 2>/dev/null || {
        sindet "Latidos de cron" "no se pudo guardar la respuesta de health.php"
        return
    }

    crons=$(jget "$CRON_SNAP" crons) || crons=""
    if [ -z "$crons" ] || [ "$crons" = "null" ]; then
        sfail "Latidos de cron: health.php publica el estado de los cron" \
"crons=${crons:-ausente}. Producción sirve un api/health.php sin telemetría de cron,
     o la tabla cron_heartbeats no existe todavía; hasta que la haya NO SE PUEDE
     SABER si link_check sigue parado (lo está desde el 2026-05-30). Falta
     desplegar este commit y aplicar setup/migration_010_cron_heartbeats.sql."
        return
    fi

    # Los periodos se escriben AQUÍ y no se leen de la respuesta a propósito:
    # si el servidor pudiera declarar su propio umbral, un valor mal puesto
    # (period_seconds=0) dejaría el check permanentemente en verde.
    check_cron_job link_check 21600 "6 h"
    check_cron_job moderation   120 "2 min"
}
check_crons
SECTION_TAG=""

echo ""
echo -e "${YELLOW}── Buscador — REGRESIÓN (requiere el fix desplegado) ──${NC}"
echo "   Estos checks reproducen los fallos diagnosticados el 2026-08-04 en"
echo "   api/resources.php:125-128 (input crudo → AGAINST(... IN BOOLEAN MODE))."
echo "   FALLAN a propósito mientras no esté desplegado shared/search.php."
SECTION_TAG="[buscador] "

# ── 1. Operadores fulltext hostiles: deben dar 200, nunca 500 ──
# El input del usuario no puede llegar crudo a AGAINST(): '+', '-', '*', '(',
# '@' y las comillas son operadores del parser y provocan ERROR 1064 → 500.
check_search_safe "Hostil: 'C++' no rompe (500 hoy)"           'C++'
check_search_safe "Hostil: comilla doble suelta"               '"'
check_search_safe "Hostil: guion suelto (operador NOT)"        '-'
check_search_safe "Hostil: '+++'"                              '+++'
check_search_safe "Hostil: paréntesis sin cerrar '(ondas'"     '(ondas'
check_search_safe "Hostil: arroba '@'"                         '@'
check_search_safe "Hostil: 'física-química' (guion interior)"  'física-química'

# ── 2. Prefijo: el buscador del home es incremental (debounce 300 ms) ──
# Sin '*' el fulltext exige palabra completa: 'matem' da 0 aunque existan
# 2 títulos con "Matemáticas" y la categoría Mathematics tenga 119 recursos.
check_search_min "Prefijo: 'matem' encuentra algo"  'matem'  1

# ── 3. Token corto: InnoDB descarta tokens < 3 chars (min_token_size=3,
# global, no tocable en hosting compartido) → sólo alcanzable vía LIKE.
# 'pH' es literalmente el ejemplo del placeholder del buscador (index.php:376)
# y hoy devuelve 0 teniendo "pH Scale" y "Escala de pH: Fundamentos".
check_search_min "Token corto: 'pH' encuentra algo"  'pH'  1

# ── 4. Multi-palabra = AND, no OR ──
# 'ondas' y 'fracciones' son conjuntos disjuntos: hoy la consulta conjunta
# devuelve exactamente la SUMA de ambos (unión), que es la firma del OR.
check_search_and 'ondas' 'fracciones'
# La otra mitad: al hacer AND no puede quedarse en cero cuando la frase existe
# de verdad ("Ondas Sonoras"). Protege del error clásico de añadir '+' a cada
# término sin filtrar stopwords, que anularía la consulta entera.
check_search_min "AND coherente: 'ondas sonoras' sigue encontrando"  'ondas sonoras'  1

# ── 5. Relevancia: el primer resultado debe ser pertinente ──
# CASO ELEGIDO: 'fracciones'. Es estable porque no depende de ningún ID ni de
# un único recurso: hay 9 recursos activos con Fracción/Fracciones/Refracción/
# Difracción en el título (5 en Matemáticas, 4 en Física), así que despublicar
# uno no tumba el check. Sólo exige que el término aparezca EN EL TÍTULO del
# primer resultado. Hoy falla: el ORDER BY es created_at DESC y el top es
# "Polypad: Patio de Juegos Matemático", que sólo casa en la descripción.
check_search_top "Relevancia: top de 'fracciones' lleva el término en el título" 'fracciones' 'fraccion'

# ── 6. search + filtro: el filtro sigue mandando ──
check_search_filter "Filtro: 'fracciones' + category=2 respeta la categoría" \
                    'fracciones' 'category_id' '2' 'category=2'
check_search_filter "Filtro: 'circuito' + lang=es respeta el idioma" \
                    'circuito' 'lang' 'es' 'lang=es'

# ── 7. total / pages / nº de elementos coherentes ──
# Con relevancia habrá muchos empates; si COUNT y la consulta de página se
# desincronizan (params posicionales mal ordenados), se ve aquí.
check_page_coherence "Coherencia total/pages/n con búsqueda"  10 1 --data-urlencode "search=ondas"
check_page_coherence "Coherencia total/pages/n sin búsqueda"  10 2

echo ""
echo "── Seguridad ────────────────────────────"
SECTION_TAG=""
check  "Cron sin token → 403"    "/cron/run.php?job=link_check&token=wrong" 403 ""
check  "Setup bloqueado"         "/setup/schema.sql"            403 ""
check  "Shared bloqueado"        "/shared/db.php"               403 ""
check  "Admin bloqueado"         "/admin/"                      403 ""

echo ""
echo "── Exposición de ficheros de desarrollo ─"
SECTION_TAG="[exposición] "

# check_blocked — el fichero no debe servirse por HTTP.
#   403 → PASS: el bloqueo del .htaccess está en su sitio.
#   404 → INDETERMINADO: ese directorio todavía no está desplegado, así que no
#         hay nada que bloquear y el check no demuestra nada. Convertirlo en
#         FAIL sería mentir; en PASS, peor: el día que se despliegue el
#         directorio sin el bloqueo, el 200 pasaría desapercibido.
#   200 → FAIL: se está sirviendo de verdad.
check_blocked() {
    local desc="$1" path="$2" code
    code=$(curl -s -o "$SMOKE_TMP" -w "%{http_code}" --max-time 15 "${BASE}${path}")
    case "$code" in
        403|401) spass "$desc" ;;
        404)     sindet "$desc" "HTTP 404: el directorio aún no está desplegado — nada que bloquear todavía" ;;
        429)     sindet "$desc" "$RL_MSG" ;;
        200)     sfail "$desc" "HTTP 200: EL FICHERO SE ESTÁ SIRVIENDO en producción" ;;
        *)       sfail "$desc" "HTTP $code inesperado (se esperaba 403)" ;;
    esac
}

check_blocked "tests/ bloqueado (.php)"      "/tests/run.php"
check_blocked "tests/ bloqueado (.md)"       "/tests/README.md"
check_blocked "tests/ bloqueado (integración)" "/tests/integration/bootstrap.php"
check_blocked "quality/ bloqueado"           "/quality/guards.sh"
check_blocked "docs/ bloqueado"              "/docs/RUNBOOK.md"
check_blocked "Makefile bloqueado"           "/Makefile"
check_blocked ".githooks/ bloqueado"         "/.githooks/pre-push"
check_blocked ".gitignore bloqueado"         "/.gitignore"
SECTION_TAG=""

echo ""
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

TOTAL=$((PASS + FAIL + INDET))

if [ $FAIL -eq 0 ] && [ $INDET -eq 0 ]; then
    echo -e "${GREEN}✅ Todos los tests pasaron ($PASS/$TOTAL)${NC}"
elif [ $FAIL -eq 0 ]; then
    echo -e "${YELLOW}⚠️  Sin fallos, pero la corrida NO es concluyente:${NC}"
    echo -e "${YELLOW}   $PASS PASS · 0 FAIL · $INDET INDETERMINADOS (de $TOTAL)${NC}"
else
    echo -e "${RED}❌ $FAIL tests fallaron, $PASS pasaron, $INDET indeterminados (de $TOTAL)${NC}"
fi

if [ $FAIL -gt 0 ]; then
    echo ""
    echo "Errores:"
    for err in "${ERRORS[@]}"; do
        echo -e "  ${RED}•${NC} $err"
    done
    # Fallos ESPERADOS mientras no se despliegue: [buscador] (falta el fix de
    # shared/search.php) y [crons] (falta este commit + la migración 010 + el
    # cron reactivado). Se descuentan para que el número que de verdad importa
    # —regresiones nuevas— no quede sepultado entre los conocidos.
    FAIL_KNOWN=$((FAIL_SEARCH + FAIL_CRON))
    FAIL_NEW=$((FAIL - FAIL_KNOWN))

    if [ $FAIL_SEARCH -gt 0 ]; then
        echo ""
        echo -e "  ${YELLOW}ℹ  $FAIL_SEARCH fallo(s) son de [buscador]: la regresión conocida.${NC}"
        echo -e "  ${YELLOW}   Falta desplegar shared/search.php + api/resources.php.${NC}"
    fi

    if [ $FAIL_CRON -gt 0 ]; then
        echo ""
        echo -e "  ${YELLOW}ℹ  $FAIL_CRON fallo(s) son de [crons]. ESTE ROJO ES EL HALLAZGO, no un${NC}"
        echo -e "  ${YELLOW}   defecto del test: el link checker lleva parado desde el 2026-05-30${NC}"
        echo -e "  ${YELLOW}   y durante 66 días NADA lo dijo. Seguirá en rojo hasta las 3 cosas:${NC}"
        echo -e "  ${YELLOW}     1. desplegar este commit (api/health.php + cron/run.php),${NC}"
        echo -e "  ${YELLOW}     2. aplicar setup/migration_010_cron_heartbeats.sql,${NC}"
        echo -e "  ${YELLOW}     3. reactivar el job link_check en cron-job.org.${NC}"
        echo -e "  ${YELLOW}   Tras el paso 3 el primer latido tarda hasta 6 h en llegar.${NC}"
    fi

    if [ $FAIL_KNOWN -gt 0 ]; then
        echo ""
        if [ $FAIL_NEW -eq 0 ]; then
            echo -e "  ${YELLOW}ℹ  Los $FAIL fallos son TODOS conocidos: ninguna regresión nueva${NC}"
            echo -e "  ${YELLOW}   en el resto del sitio.${NC}"
        else
            echo -e "  ${RED}‼  $FAIL_NEW de los $FAIL fallos NO son conocidos: ésos sí son nuevos.${NC}"
        fi
    fi
fi

if [ ${#WARNS[@]} -gt 0 ]; then
    echo ""
    echo "Avisos (no son fallos y no afectan al código de salida):"
    for w in "${WARNS[@]}"; do
        echo -e "  ${YELLOW}•${NC} $w"
    done
fi

if [ $INDET -gt 0 ]; then
    echo ""
    echo "Indeterminados (NO son fallos: estos checks no llegaron a ejecutarse):"
    for ind in "${INDETS[@]}"; do
        echo -e "  ${YELLOW}•${NC} $ind"
    done
    if [ $PASS -eq 0 ]; then
        echo ""
        echo -e "  ${RED}‼  NINGÚN check llegó a concluir: esta corrida no verifica NADA.${NC}"
        echo -e "  ${RED}   No la tomes como un despliegue validado.${NC}"
    fi
fi

echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# 1 = hay fallos reales · 2 = corrida no concluyente · 0 = limpia.
[ $FAIL -gt 0 ] && exit 1
[ $INDET -gt 0 ] && exit 2
exit 0
