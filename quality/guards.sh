#!/usr/bin/env bash
# ================================================================
# quality/guards.sh — Chequeos estáticos del repo (capa 1 anti-regresión)
#
# Uso:
#   bash quality/guards.sh              # todo el repo (ficheros trackeados)
#   bash quality/guards.sh --changed    # solo lo modificado vs origin/main
#   bash quality/guards.sh --help
#
# Exit code: 0 si no hay BLOQUEANTES (puede haber avisos), 1 si algo bloqueante
# falla, 2 si el entorno no permite ejecutar los chequeos.
#
# Dependencias: bash, git, php, python3. `node` es OPCIONAL (si falta, el check
# de JS inline se degrada a aviso en vez de bloquear el push).
#
# Estos chequeos se ejecutan ANTES del push (.githooks/pre-push). Corren en ~2s.
# Complementan a quality/smoke_test.sh, que corre DESPUÉS del deploy y ya no
# puede evitar que el fallo llegue a producción — aquí no hay staging y
# `git push origin main` es producción en vivo.
#
# FILOSOFÍA — por qué hay ficheros de baseline:
#   El repo tiene violaciones PREEXISTENTES de sus propias reglas (7 páginas que
#   cargan helpers.php, 3 migraciones no idempotentes). Un guard binario fallaría
#   decenas de veces el primer día sobre código que ya está en producción y
#   funcionando; en una semana alguien lo desactiva y la capa entera muere.
#   Por eso lo preexistente va a baseline o a aviso, y lo BLOQUEANTE es solo lo
#   NUEVO. Los baselines solo pueden encoger, y guards.sh avisa cuando sobran.
#
# LOS 9 CHEQUEOS:
#   G1 helpers.php en páginas HTML ....... BLOQUEA (baseline_html_helpers.txt)
#   G2 cierre de PHP en comentario ....... BLOQUEA
#   G3 CDNs / hosts externos ............. BLOQUEA (allowed_hosts.txt)
#   G4 credenciales y .env.php ........... BLOQUEA
#   G5 JSON estático inválido ............ BLOQUEA
#   G6 sintaxis del JavaScript inline .... BLOQUEA (requiere node)
#   G7 cadenas t() sin traducir .......... avisa  (i18n_ignore.txt)
#   G8 migraciones no idempotentes ....... avisa
#   G9 suites de test borradas ........... BLOQUEA (required_tests.txt)
# ================================================================

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

# ── Presentación ────────────────────────────────────────────────
# Colores solo si hay terminal (para que el log del hook sea legible).
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
    BLUE='\033[0;34m'; DIM='\033[2m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; DIM=''; NC=''
fi

PASS=0
FAIL=0
WARN=0
ERRORS=()
WARNINGS=()

ok()   { echo -e "${GREEN}✅ PASS${NC} $1"; PASS=$((PASS + 1)); }
skip() { echo -e "${DIM}⏭  SKIP${NC} $1"; }

# Los detalles de cada hallazgo se acumulan en un fichero, NO se pasan por
# tubería. Con `cosa | fail "..."` la función correría en una SUBSHELL y los
# incrementos de FAIL/WARN se perderían: guards.sh terminaría siempre en exit 0
# y el gate sería un no-op silencioso. (Ocurrió en la primera versión.)
DET=""
det_reset() { DET="$TMPDIR_G/details.$$.txt"; : > "$DET"; }
det()       { printf '%s\n' "$*" >> "$DET"; }
det_file()  { [ -s "${1:-}" ] && cat "$1" >> "$DET"; return 0; }

_emit_details() {
    [ -n "${1:-}" ] && [ -s "$1" ] || return 0
    local color="$2" l
    while IFS= read -r l; do
        [ -n "$l" ] && echo -e "         ${color}·${NC} $l"
    done < "$1"
}

# fail <titulo> [fichero_detalles]
fail() {
    echo -e "${RED}❌ FAIL${NC} $1"
    FAIL=$((FAIL + 1))
    ERRORS+=("$1")
    _emit_details "${2:-}" "$RED"
}

# warn <titulo> [fichero_detalles]
warn() {
    echo -e "${YELLOW}⚠️  WARN${NC} $1"
    WARN=$((WARN + 1))
    WARNINGS+=("$1")
    _emit_details "${2:-}" "$YELLOW"
}

usage() {
    sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' | grep -v '^=\+$'
}

# ── Argumentos ──────────────────────────────────────────────────
MODE="all"
for arg in "$@"; do
    case "$arg" in
        --changed) MODE="changed" ;;
        --help|-h) usage; exit 0 ;;
        *) echo "guards.sh: argumento desconocido '$arg' (usa --help)" >&2; exit 2 ;;
    esac
done

command -v git  >/dev/null 2>&1 || { echo "guards.sh: falta git";  exit 2; }
command -v php  >/dev/null 2>&1 || { echo "guards.sh: falta php";  exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "guards.sh: falta python3"; exit 2; }

# El repo TIENE que ser un repo git: toda la selección de ficheros sale de
# `git ls-files`. Sin .git, git devuelve 0 ficheros, TODOS los chequeos se
# saltan y guards.sh terminaba en exit 0 anunciando "Guards OK" — un fallo
# ABIERTO: el gate parecía verde sin haber revisado ni un byte. Verificado
# ejecutando guards.sh sobre una copia del repo sin .git.
if [ "$(git rev-parse --is-inside-work-tree 2>/dev/null)" != "true" ]; then
    echo "guards.sh: esto no es un repositorio git ($ROOT)." >&2
    echo "           La selección de ficheros depende de git ls-files; sin .git" >&2
    echo "           los chequeos se saltarían y el resultado sería un falso verde." >&2
    exit 2
fi

ANALYZE="$ROOT/quality/lib/analyze.php"
[ -f "$ANALYZE" ] || { echo "guards.sh: falta quality/lib/analyze.php"; exit 2; }

TMPDIR_G="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_G"' EXIT

# ── Selección de ficheros ───────────────────────────────────────
# En modo --changed se comparan los ficheros contra la rama base e incluye
# también lo que está sin commitear (staged, unstaged y sin trackear): lo que se
# va a empujar no es solo lo commiteado, y un fichero sucio puede romper prod.
BASE=""
FILES=()
if [ "$MODE" = "changed" ]; then
    for cand in origin/main main; do
        if git rev-parse --verify -q "$cand" >/dev/null 2>&1; then BASE="$cand"; break; fi
    done
    if [ -z "$BASE" ]; then
        echo -e "${YELLOW}No encuentro origin/main ni main; reviso el repo entero.${NC}"
        MODE="all"
    else
        while IFS= read -r f; do
            [ -n "$f" ] && [ -f "$f" ] && FILES+=("$f")
        done < <(
            {
                git diff --name-only "$BASE"...HEAD 2>/dev/null
                git diff --name-only HEAD 2>/dev/null
                git diff --name-only --cached 2>/dev/null
                git ls-files --others --exclude-standard 2>/dev/null
            } | sort -u
        )
    fi
fi
if [ "$MODE" = "all" ]; then
    # Trackeados + los NUEVOS sin trackear (respetando .gitignore). Sin la
    # segunda mitad, `make check` daba verde ignorando 13 ficheros .php recién
    # creados — entre ellos shared/search.php y todo tests/ — mientras que el
    # hook pre-push (que sí los mira, vía --changed) los habría bloqueado.
    # Un fichero sin trackear es código que se va a desplegar en cuanto se
    # commitee: revisarlo antes es justo el objetivo.
    while IFS= read -r f; do
        [ -n "$f" ] && [ -f "$f" ] && FILES+=("$f")
    done < <( { git ls-files; git ls-files --others --exclude-standard; } | sort -u )

    # Segunda red contra el falso verde: en modo "todo el repo" es IMPOSIBLE
    # que no haya ficheros. Si los hay 0, el entorno está roto (índice vacío,
    # cwd equivocado, checkout a medias), no es que el repo esté limpio.
    # En modo --changed 0 ficheros SÍ es legítimo: no has tocado nada.
    if [ ${#FILES[@]} -eq 0 ]; then
        echo "guards.sh: git ls-files no devolvió ningún fichero en $ROOT." >&2
        echo "           Sin ficheros que revisar el resultado sería un falso verde." >&2
        exit 2
    fi
fi

# Subconjuntos por extensión.
#
# PHP_FILES     = todos los .php (se usa en G2: al propio utillaje también le
#                 puede pasar lo del cierre en comentario — de hecho le pasó a
#                 quality/lib/analyze.php mientras se escribía).
# APP_PHP_FILES = los .php de la APLICACIÓN, sin quality/lib/. Se usa en los
#                 checks que analizan el código como si fuera una página o
#                 markup (G1, G6, G7). El analizador contiene, como cadenas,
#                 los propios patrones que busca ("<script...", "t('...')"), así
#                 que analizarse a sí mismo produce falsos positivos garantizados.
PHP_FILES=(); APP_PHP_FILES=(); JS_FILES=(); JSON_FILES=(); SQL_FILES=(); MARKUP_FILES=()
for f in ${FILES[@]+"${FILES[@]}"}; do
    case "$f" in
        quality/lib/*.php) PHP_FILES+=("$f") ;;
        *.php)          PHP_FILES+=("$f"); APP_PHP_FILES+=("$f"); MARKUP_FILES+=("$f") ;;
        *.js)           JS_FILES+=("$f") ;;
        *.json|*.webmanifest) JSON_FILES+=("$f") ;;
        *.sql)          SQL_FILES+=("$f") ;;
        *.html|*.htm|*.css) MARKUP_FILES+=("$f") ;;
    esac
done

echo ""
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
if [ "$MODE" = "changed" ]; then
    echo -e "${YELLOW} iarepo Guards — modo --changed (base: ${BASE})${NC}"
    echo -e "${DIM} ${#FILES[@]} ficheros modificados${NC}"
else
    echo -e "${YELLOW} iarepo Guards — repo completo${NC}"
    echo -e "${DIM} ${#FILES[@]} ficheros trackeados${NC}"
fi
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# ================================================================
# G1 · Páginas HTML que cargan shared/helpers.php   [BLOQUEANTE]
# ================================================================
# Regla crítica #1. Ver quality/baseline_html_helpers.txt para el porqué del
# baseline. La detección (tokenizador de PHP, cierre transitivo de requires)
# está documentada en quality/lib/analyze.php.
echo -e "${BLUE}── G1 · helpers.php en páginas HTML ──────────────────${NC}"
BASELINE_HELPERS="$ROOT/quality/baseline_html_helpers.txt"
if [ ${#APP_PHP_FILES[@]} -eq 0 ]; then
    skip "G1 · sin ficheros .php que revisar"
else
    php "$ANALYZE" html-pages "${APP_PHP_FILES[@]}" > "$TMPDIR_G/g1.txt" 2>"$TMPDIR_G/g1.err"
    if [ -s "$TMPDIR_G/g1.err" ]; then
        fail "G1 · el analizador falló" "$TMPDIR_G/g1.err"
    else
        # Rutas en baseline (ignorando comentarios y líneas vacías).
        BASE_LIST="$TMPDIR_G/g1_baseline.txt"
        : > "$BASE_LIST"
        if [ -f "$BASELINE_HELPERS" ]; then
            grep -vE '^[[:space:]]*(#|$)' "$BASELINE_HELPERS" | sed 's/[[:space:]]*$//' > "$BASE_LIST"
        fi

        NEW_VIOL="$TMPDIR_G/g1_new.txt"; : > "$NEW_VIOL"
        SEEN="$TMPDIR_G/g1_seen.txt";    : > "$SEEN"
        while IFS= read -r line; do
            [ -n "$line" ] || continue
            file="${line%%:*}"
            echo "$file" >> "$SEEN"
            if ! grep -qxF "$file" "$BASE_LIST"; then
                echo "$line" >> "$NEW_VIOL"
            fi
        done < "$TMPDIR_G/g1.txt"

        if [ -s "$NEW_VIOL" ]; then
            det_reset
            sed 's/\t/  →  /' "$NEW_VIOL" >> "$DET"
            det "Arréglalo: quita el require de helpers.php y define h() local (ver index.php:14)."
            det "NO añadas la ruta a quality/baseline_html_helpers.txt: ese fichero solo puede encoger."
            fail "G1 · página(s) HTML NUEVAS cargando shared/helpers.php" "$DET"
        else
            ok "G1 · ninguna página HTML nueva carga helpers.php"
        fi

        # El baseline solo puede encoger: avisa de entradas ya innecesarias.
        # Solo tiene sentido en modo completo (en --changed no se ha visto todo).
        if [ "$MODE" = "all" ] && [ -s "$BASE_LIST" ]; then
            STALE="$TMPDIR_G/g1_stale.txt"; : > "$STALE"
            while IFS= read -r b; do
                [ -n "$b" ] || continue
                grep -qxF "$b" "$SEEN" || echo "$b ya no carga helpers.php en una página HTML" >> "$STALE"
            done < "$BASE_LIST"
            if [ -s "$STALE" ]; then
                det_reset
                det_file "$STALE"
                det "Bórralas de quality/baseline_html_helpers.txt para que la deuda no vuelva a crecer."
                warn "G1 · entradas obsoletas en el baseline" "$DET"
            fi
        fi
    fi
fi

# ================================================================
# G2 · '?>' dentro de comentarios PHP               [BLOQUEANTE]
# ================================================================
# Regla crítica #2. Es el fallo MÁS peligroso del repo porque `php -l` no lo ve:
# el fichero pasa el lint y en el navegador se imprime el código fuente PHP.
# Solo los comentarios de LÍNEA (// y #) son peligrosos; los de bloque no.
# Detalle y verificación empírica en quality/lib/analyze.php.
echo ""
echo -e "${BLUE}── G2 · cierre de PHP en comentarios ─────────────────${NC}"
if [ ${#PHP_FILES[@]} -eq 0 ]; then
    skip "G2 · sin ficheros .php que revisar"
else
    # Se auditan también las propias herramientas de quality/.
    G2_TARGETS=("${PHP_FILES[@]}")
    if php "$ANALYZE" close-tag "${G2_TARGETS[@]}" > "$TMPDIR_G/g2.txt" 2>&1; then
        ok "G2 · ningún comentario cierra el modo PHP"
    else
        det_reset
        sed 's/\t/  →  /' "$TMPDIR_G/g2.txt" >> "$DET"
        det "php -l NO detecta esto. Quita el cierre del comentario o pásalo a comentario de bloque."
        fail "G2 · cierre de PHP en comentario de línea (rompe la página en silencio)" "$DET"
    fi
fi

# ================================================================
# G3 · CDNs / hosts externos no autorizados         [BLOQUEANTE]
# ================================================================
# La marca del proyecto es AUTO-ALOJAR (regla #4: lucide vive en
# assets/js/lucide.min.js y jamás vuelve a un CDN).
#
# Se revisan SOLO los src=/href= de <script>, <link>, <img> e <iframe> escritos
# en el código: es la superficie por la que entra código/estilo de terceros.
# NO se revisan las URLs del catálogo educativo (phet.colorado.edu, geogebra...):
# son datos que viven en la BD y se embeben en runtime, no dependencias.
# Un guard que las mirara daría ~30 falsos positivos y sería inservible.
echo ""
echo -e "${BLUE}── G3 · hosts externos y CDNs ────────────────────────${NC}"
ALLOWED="$ROOT/quality/allowed_hosts.txt"
if [ ${#MARKUP_FILES[@]} -eq 0 ] && [ ${#JS_FILES[@]} -eq 0 ]; then
    skip "G3 · sin ficheros de markup que revisar"
else
    G3_TARGETS=(${MARKUP_FILES[@]+"${MARKUP_FILES[@]}"} ${JS_FILES[@]+"${JS_FILES[@]}"})
    ALLOW_LIST="$TMPDIR_G/g3_allow.txt"; : > "$ALLOW_LIST"
    [ -f "$ALLOWED" ] && grep -vE '^[[:space:]]*(#|$)' "$ALLOWED" | sed 's/[[:space:]]*$//' > "$ALLOW_LIST"

    # host<TAB>fichero:linea
    grep -nEo '<(script|link|img|iframe)[^>]*(src|href)=["'"'"']https?://[^"'"'"'/]+' \
        ${G3_TARGETS[@]+"${G3_TARGETS[@]}"} 2>/dev/null \
        | sed -E 's|^([^:]+):([0-9]+):.*https?://([^"'"'"'/]+).*|\3\t\1:\2|' \
        | sort -u > "$TMPDIR_G/g3_hosts.txt"

    BAD3="$TMPDIR_G/g3_bad.txt"; : > "$BAD3"
    while IFS=$'\t' read -r host loc; do
        [ -n "$host" ] || continue
        grep -qxF "$host" "$ALLOW_LIST" || echo "$loc  carga desde host NO autorizado: $host" >> "$BAD3"
    done < "$TMPDIR_G/g3_hosts.txt"

    # Lista negra dura: CDNs conocidos en cualquier parte del código, aunque no
    # sea en un atributo src/href (p. ej. construidos en JS).
    grep -nEi 'unpkg\.com|jsdelivr\.net|cdnjs\.cloudflare|cdn\.tailwindcss|stackpath\.|bootstrapcdn|ajax\.googleapis\.com|code\.jquery\.com|esm\.sh|skypack\.dev|cdn\.skypack' \
        ${G3_TARGETS[@]+"${G3_TARGETS[@]}"} 2>/dev/null \
        | sed -E 's|^([^:]+:[0-9]+):.*|\1  referencia a un CDN público (auto-aloja el asset)|' >> "$BAD3"

    if [ -s "$BAD3" ]; then
        det_reset
        sort -u "$BAD3" >> "$DET"
        det "Auto-aloja el asset (como assets/js/lucide.min.js), o si es inevitable"
        det "añade el host a quality/allowed_hosts.txt explicando por qué."
        fail "G3 · dependencia externa no autorizada" "$DET"
    else
        ok "G3 · todos los assets salen de hosts autorizados"
    fi
fi

# ================================================================
# G4 · Credenciales y rastros de .env.php           [BLOQUEANTE]
# ================================================================
# `git push origin main` empuja a la vez a producción y a GitHub (el remoto
# origin tiene dos pushURL). Un secreto commiteado se filtra por dos vías a la
# vez y GitHub conserva el objeto aunque se revierta: aquí no hay vuelta atrás.
echo ""
echo -e "${BLUE}── G4 · secretos y .env.php ──────────────────────────${NC}"
SEC="$TMPDIR_G/g4.txt"; : > "$SEC"

# .env.php nunca debe estar trackeado (está en .gitignore, pero un `git add -f`
# lo saltaría; y AGENTS.md demuestra que .gitignore no protege lo ya trackeado).
git ls-files | grep -E '(^|/)\.env\.php$' \
    | sed 's/$/  → .env.php NO debe estar trackeado (git rm --cached)/' >> "$SEC"

if [ ${#FILES[@]} -gt 0 ]; then
    TEXT_FILES=()
    for f in "${FILES[@]}"; do
        case "$f" in
            *.php|*.js|*.sh|*.sql|*.json|*.webmanifest|*.txt|*.md|*.yml|*.yaml|.env.php.example|.htaccess)
                TEXT_FILES+=("$f") ;;
        esac
    done
    if [ ${#TEXT_FILES[@]} -gt 0 ]; then
        # (a) Clave conocida con valor literal. Se descartan plantillas y
        #     variables de shell/PHP: setup/server_setup.sh usa '$JWT_SECRET'
        #     (variable, no secreto) y .env.php.example usa 'your_...' /
        #     'GENERATE_...'. Sin ese filtro habría 5 falsos positivos fijos.
        #     También se descarta la sustitución de comandos de shell '$(...)':
        #     setup/tools/backup_db.sh usa DB_PASS="$(read_env DB_PASS)", que
        #     LEE el secreto de .env.php en vez de contenerlo.
        grep -nEi "(DB_PASS(WORD)?|JWT_SECRET|CRON_SECRET|ADMIN_PASS(WORD)?|CLIENT_SECRET|API_KEY|APIKEY|SECRET_KEY|SMTP_PASS(WORD)?|ACCESS_TOKEN|AUTH_TOKEN)['\"]?[[:space:]]*(=>|=|:)[[:space:]]*['\"][^'\"]{8,}['\"]" \
            "${TEXT_FILES[@]}" 2>/dev/null \
            | grep -vEi '\$[A-Za-z_{(]|`|\*\*\*|your_|GENERATE_|change_this|CHANGE_ME|REPLACE|EXAMPLE|placeholder|xxxx|TODO|<[a-z]' \
            | sed -E 's|^([^:]+:[0-9]+):.*|\1  → posible credencial con valor literal|' >> "$SEC"

        # (b) Formatos de secreto inconfundibles.
        grep -nE 'AIza[0-9A-Za-z_-]{35}|-----BEGIN [A-Z ]*PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}' \
            "${TEXT_FILES[@]}" 2>/dev/null \
            | sed -E 's|^([^:]+:[0-9]+):.*|\1  → clave de API / clave privada embebida|' >> "$SEC"

        # (c) Hex largo asignado a una variable (secretos generados con
        #     bin2hex(random_bytes(32))). Hoy el repo no tiene ninguno.
        grep -nE "(=>|=|:)[[:space:]]*['\"][0-9a-fA-F]{40,}['\"]" \
            "${TEXT_FILES[@]}" 2>/dev/null \
            | sed -E 's|^([^:]+:[0-9]+):.*|\1  → cadena hex larga asignada (¿secreto generado?)|' >> "$SEC"

        # (d) DSN de conexión escrito a mano. La regla (a) exige que la palabra
        #     DB_PASS esté pegada al valor, así que NO veía una credencial
        #     POSICIONAL: un `new PDO(<DSN literal>, "usuario", "contraseña")`
        #     con los tres argumentos escritos a mano. Ese patrón tuvo la
        #     contraseña real de
        #     producción en setup/seed_resources.php durante meses. Un DSN con
        #     el nombre de la base escrito literalmente delata la conexión
        #     hecha a mano; las conexiones legítimas del repo componen el DSN
        #     con variables ($env['DB_NAME']), así que esto no las toca.
        grep -nE "['\"]mysql:[^'\"]*dbname=[A-Za-z0-9_]" \
            "${TEXT_FILES[@]}" 2>/dev/null \
            | sed -E 's|^([^:]+:[0-9]+):.*|\1  → DSN con la base escrita a mano (¿usuario/contraseña posicionales al lado?)|' >> "$SEC"
    fi
fi

if [ -s "$SEC" ]; then
    det_reset
    sort -u "$SEC" >> "$DET"
    det "Si es un secreto real: sácalo a .env.php y ROTA el valor — un push"
    det "publica a la vez en producción y en GitHub, y GitHub lo conserva."
    fail "G4 · posible credencial commiteada" "$DET"
else
    ok "G4 · sin credenciales ni .env.php en el índice"
fi

# ================================================================
# G5 · JSON válido                                  [BLOQUEANTE]
# ================================================================
# manifest.webmanifest roto = PWA que no instala, sin error visible.
# NO se valida el JSON-LD inline de las páginas: mezcla interpolaciones PHP en
# posición de valor con condicionales que emiten ESTRUCTURA (la coma entre dos
# objetos la pone un `if`), así que ninguna sustitución textual da JSON válido en
# todos los casos. Eso se valida post-deploy sobre la página ya renderizada.
echo ""
echo -e "${BLUE}── G5 · JSON estático ────────────────────────────────${NC}"
if [ ${#JSON_FILES[@]} -eq 0 ]; then
    skip "G5 · sin ficheros .json/.webmanifest que revisar"
else
    BAD5="$TMPDIR_G/g5.txt"; : > "$BAD5"
    for f in "${JSON_FILES[@]}"; do
        if ! err=$(python3 -m json.tool "$f" 2>&1 >/dev/null); then
            echo "$f  → $err" >> "$BAD5"
        fi
    done
    if [ -s "$BAD5" ]; then
        fail "G5 · JSON inválido" "$BAD5"
    else
        ok "G5 · ${#JSON_FILES[@]} fichero(s) JSON válidos"
    fi
fi

# ================================================================
# G6 · Sintaxis del JavaScript inline               [BLOQUEANTE]
# ================================================================
# ~1.900 líneas de JS viven dentro de bloques <script> en .php y no las valida
# nada: `node --check` solo ve los .js sueltos de assets/. Un error de sintaxis
# en el bloque de index.php rompe favoritos, búsqueda y filtros de la portada,
# y el smoke test no lo detecta (el HTML sigue conteniendo 'class="fcard"').
echo ""
echo -e "${BLUE}── G6 · JavaScript inline ────────────────────────────${NC}"
if [ ${#APP_PHP_FILES[@]} -eq 0 ]; then
    skip "G6 · sin ficheros .php que revisar"
elif ! command -v node >/dev/null 2>&1; then
    det_reset
    det "node no está instalado; no se puede validar el JS inline."
    warn "G6 · omitido (falta node)" "$DET"
else
    JSDIR="$TMPDIR_G/js"; mkdir -p "$JSDIR"
    NBLOCKS=$(php "$ANALYZE" extract-js "$JSDIR" "${APP_PHP_FILES[@]}" 2>/dev/null)
    if [ -z "${NBLOCKS:-}" ] || [ "$NBLOCKS" = "0" ]; then
        skip "G6 · sin bloques <script> inline"
    else
        BAD6="$TMPDIR_G/g6.txt"; : > "$BAD6"
        for blk in "$JSDIR"/blk_*.js; do
            [ -f "$blk" ] || continue
            if ! err=$(node --check "$blk" 2>&1); then
                src=$(cat "${blk%.js}.src" 2>/dev/null || echo "?")
                # node reporta líneas relativas al bloque; se indica el origen.
                msg=$(echo "$err" | grep -E 'SyntaxError|Error:' | head -1)
                echo "$src  → $msg" >> "$BAD6"
            fi
        done
        if [ -s "$BAD6" ]; then
            det_reset
            det_file "$BAD6"
            det "La línea indicada es donde empieza el bloque <script>."
            fail "G6 · error de sintaxis en JavaScript inline" "$DET"
        else
            ok "G6 · $NBLOCKS bloque(s) <script> inline con sintaxis válida"
        fi
    fi
fi

# ================================================================
# G7 · Cadenas sin traducir                              [AVISO]
# ================================================================
# POR QUÉ ES AVISO Y NO BLOQUEANTE:
# Hoy hay 21 literales t('...') sin entrada en shared/i18n_en.php, y 19 los
# introdujeron los commits recientes del buscador y el hero. Bloquear el push
# obligaría a traducir 19 cadenas ajenas antes de poder empujar un arreglo de
# una línea — el camino más corto a que alguien use --no-verify por costumbre,
# que es el fallo que mata estos sistemas. Además hay literales legítimamente
# idénticos en inglés (nombres propios, tecnicismos), un conjunto abierto.
# El aviso es ruidoso a propósito: lista fichero:línea y no se puede ignorar.
#
# Cuando el backlog esté a cero, promuévelo a bloqueante con:
#   GUARDS_I18N_STRICT=1 bash quality/guards.sh
# (y pon esa variable en el hook para que quede fijo).
echo ""
echo -e "${BLUE}── G7 · cobertura i18n ───────────────────────────────${NC}"
if [ ${#APP_PHP_FILES[@]} -eq 0 ]; then
    skip "G7 · sin ficheros .php que revisar"
else
    if php "$ANALYZE" i18n "${APP_PHP_FILES[@]}" > "$TMPDIR_G/g7.txt" 2>"$TMPDIR_G/g7.err"; then
        ok "G7 · todas las cadenas t() tienen traducción"
    elif [ -s "$TMPDIR_G/g7.err" ]; then
        warn "G7 · el analizador i18n falló" "$TMPDIR_G/g7.err"
    else
        N7=$(wc -l < "$TMPDIR_G/g7.txt" | tr -d ' ')
        det_reset
        sed 's/\t/  →  /' "$TMPDIR_G/g7.txt" | head -25 >> "$DET"
        [ "$N7" -gt 25 ] && det "... y $((N7 - 25)) más"
        det "Añádelas a shared/i18n_en.php (la clave es el español)."
        det "Si el inglés es idéntico, añádelas a quality/i18n_ignore.txt."
        if [ "${GUARDS_I18N_STRICT:-0}" = "1" ]; then
            fail "G7 · $N7 cadena(s) t() sin traducción (modo estricto)" "$DET"
        else
            warn "G7 · $N7 cadena(s) t() sin traducción en shared/i18n_en.php" "$DET"
        fi
    fi
fi

# ================================================================
# G8 · Migraciones SQL no idempotentes                   [AVISO]
# ================================================================
# POR QUÉ ES AVISO: hay 3 líneas preexistentes así (migration_007 y 008) y no se
# pueden "arreglar" sin tocar migraciones que ya están aplicadas en producción.
# No hay tabla de registro de migraciones, así que reejecutar una es un riesgo
# real: sin IF NOT EXISTS aborta con 'Duplicate column name' a mitad, y no hay
# transacción que lo revierta.
echo ""
echo -e "${BLUE}── G8 · idempotencia de migraciones ──────────────────${NC}"
if [ ${#SQL_FILES[@]} -eq 0 ]; then
    skip "G8 · sin ficheros .sql que revisar"
else
    BAD8="$TMPDIR_G/g8.txt"; : > "$BAD8"
    for f in "${SQL_FILES[@]}"; do
        case "$f" in setup/seed_*|*/seed_*) continue ;; esac   # los seeds son datos
        grep -nEi '^[[:space:]]*(ALTER[[:space:]]+TABLE[[:space:]]+[^;]*)?(ADD[[:space:]]+COLUMN|CREATE[[:space:]]+TABLE|CREATE[[:space:]]+(UNIQUE[[:space:]]+)?INDEX)' "$f" 2>/dev/null \
            | grep -viE 'IF[[:space:]]+NOT[[:space:]]+EXISTS' \
            | sed -E "s|^([0-9]+):[[:space:]]*(.*)|$f:\1  → sin IF NOT EXISTS: \2|" >> "$BAD8"
        grep -nEi '(DROP[[:space:]]+(TABLE|COLUMN|INDEX))' "$f" 2>/dev/null \
            | grep -viE 'IF[[:space:]]+EXISTS' \
            | sed -E "s|^([0-9]+):[[:space:]]*(.*)|$f:\1  → DROP sin IF EXISTS: \2|" >> "$BAD8"
    done
    if [ -s "$BAD8" ]; then
        det_reset
        cut -c1-160 "$BAD8" >> "$DET"
        det "Usa ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS: una migración"
        det "debe poder reejecutarse. No hay registro de qué se aplicó en producción."
        warn "G8 · migración(es) no idempotente(s)" "$DET"
    else
        ok "G8 · migraciones idempotentes"
    fi
fi

# ================================================================
# G9 · Integridad del propio gate                    [BLOQUEANTE]
# ================================================================
# La forma más barata de poner un gate en verde no es arreglar el fallo: es
# BORRAR el test que lo detecta. Verificado empíricamente: al borrar
# tests/unit/search_test.php (35 tests, 82.000 aserciones) `php tests/run.php`
# pasa a "42 tests en verde, exit 0" y guards.sh sigue en verde — el gate entero
# se queda mudo sin que nada lo denuncie.
#
# Este chequeo corre SIEMPRE sobre el repo completo, no sobre --changed: un
# fichero borrado no aparece en la lista de ficheros a revisar, que es
# justamente el motivo de que el borrado pasara inadvertido.
#
# Si borras un test a propósito (porque el código que probaba ya no existe),
# borra también su línea de quality/required_tests.txt en el mismo commit.
echo ""
echo -e "${BLUE}── G9 · integridad del gate ──────────────────────────${NC}"
REQUIRED_TESTS="$ROOT/quality/required_tests.txt"
if [ ! -f "$REQUIRED_TESTS" ]; then
    warn "G9 · falta quality/required_tests.txt (no se puede verificar el gate)"
else
    MISSING9="$TMPDIR_G/g9.txt"; : > "$MISSING9"
    while IFS= read -r line; do
        line="${line%%#*}"
        line="$(echo "$line" | xargs 2>/dev/null)"
        [ -z "$line" ] && continue
        if [ ! -f "$ROOT/$line" ]; then
            echo "$line — declarado en required_tests.txt pero NO existe" >> "$MISSING9"
        elif [ ! -s "$ROOT/$line" ]; then
            echo "$line — existe pero está VACÍO" >> "$MISSING9"
        fi
    done < "$REQUIRED_TESTS"

    if [ -s "$MISSING9" ]; then
        det_reset
        det_file "$MISSING9"
        det "Un test que desaparece deja de proteger sin que nada se ponga rojo."
        det "Si el borrado es intencionado, quita también su línea de"
        det "quality/required_tests.txt en el mismo commit."
        fail "G9 · falta un fichero de test declarado como obligatorio" "$DET"
    else
        ok "G9 · las suites obligatorias siguen presentes"
    fi
fi

# ================================================================
# Resumen
# ================================================================
echo ""
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
if [ $FAIL -eq 0 ]; then
    if [ $WARN -eq 0 ]; then
        echo -e "${GREEN}✅ Guards OK — $PASS chequeos pasados, 0 avisos${NC}"
    else
        echo -e "${GREEN}✅ Guards OK${NC} — $PASS pasados, ${YELLOW}$WARN aviso(s) no bloqueante(s)${NC}"
        for w in "${WARNINGS[@]}"; do echo -e "  ${YELLOW}•${NC} $w"; done
    fi
else
    echo -e "${RED}❌ $FAIL chequeo(s) BLOQUEANTES fallaron${NC} ($PASS pasados, $WARN aviso(s))"
    echo ""
    echo "Bloqueantes:"
    for e in "${ERRORS[@]}"; do echo -e "  ${RED}•${NC} $e"; done
fi
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

[ $FAIL -eq 0 ] && exit 0 || exit 1
