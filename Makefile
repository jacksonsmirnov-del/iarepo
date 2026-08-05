# ================================================================
# Makefile — iarepo.com
#
# Atajos del sistema anti-regresión. Cero dependencias: solo bash, php,
# python3 y (opcional) node — lo mismo que ya necesita el proyecto.
#
#   make hooks    Instala el gate pre-push (HAZLO UNA VEZ tras clonar)
#   make check    lint + guards + test  ← lo que corre el hook
#   make lint     php -l y node --check sobre los ficheros sueltos
#   make guards   Chequeos estáticos (quality/guards.sh)
#   make test     Tests unitarios (php tests/run.php)
#   make integration  Tests con BD real en Docker (no entra en check)
#   make integration-ci  Igual, pero ROJO si la suite se salta (lo usa la CI)
#   make smoke    Smoke tests contra producción (POST-deploy, usa red)
#
# `git push origin main` despliega a producción EN VIVO: no hay staging.
# Por eso `make hooks` no es opcional.
# ================================================================

SHELL := /bin/bash
.DEFAULT_GOAL := help

# Ficheros trackeados por git, para no analizar .git/ ni ficheros temporales.
# Trackeados + nuevos sin trackear (respetando .gitignore). Los `--others` NO
# son opcionales: sin ellos `make lint` se saltaba 13 ficheros .php recién
# creados (shared/search.php, todo tests/, quality/lib/) y `make check` daba
# verde sobre código que nunca había pasado por php -l. El hook pre-push sí los
# revisa, así que `make check` mentía al anunciarse como "lo mismo que el hook".
PHP_FILES := $(shell { git ls-files '*.php'; git ls-files --others --exclude-standard '*.php'; } 2>/dev/null | sort -u)
JS_FILES  := $(shell { git ls-files '*.js';  git ls-files --others --exclude-standard '*.js';  } 2>/dev/null | sort -u)

.PHONY: help lint guards test integration integration-ci check smoke hooks

help:
	@sed -n '2,18p' Makefile | sed 's/^# \{0,1\}//' | grep -v '^=\+$$'

# ── lint ────────────────────────────────────────────────────────
# Sintaxis de los ficheros SUELTOS. El JavaScript inline dentro de los .php
# NO se valida aquí: lo hace el guard G6 de quality/guards.sh, que lo extrae
# de los bloques <script> antes de pasarlo a node.
lint:
	@echo "▶ php -l ($(words $(PHP_FILES)) ficheros)"
	@fail=0; for f in $(PHP_FILES); do \
		php -l "$$f" > /dev/null || { echo "  ✗ $$f"; php -l "$$f" 2>&1 | sed 's/^/    /'; fail=1; }; \
	done; \
	if [ $$fail -eq 1 ]; then echo "❌ lint PHP falló"; exit 1; fi; \
	echo "  ✓ sin errores de sintaxis PHP"
	@if command -v node > /dev/null 2>&1; then \
		echo "▶ node --check ($(words $(JS_FILES)) ficheros)"; \
		fail=0; for f in $(JS_FILES); do \
			node --check "$$f" > /dev/null 2>&1 || { echo "  ✗ $$f"; node --check "$$f" 2>&1 | head -5 | sed 's/^/    /'; fail=1; }; \
		done; \
		if [ $$fail -eq 1 ]; then echo "❌ lint JS falló"; exit 1; fi; \
		echo "  ✓ sin errores de sintaxis JS"; \
	else \
		echo "⚠ node no instalado; se omite el lint de JS"; \
	fi

# ── guards ──────────────────────────────────────────────────────
guards:
	@bash quality/guards.sh

# ── test ────────────────────────────────────────────────────────
# tests/run.php lo mantiene otro agente (contrato: sin argumentos = unitarios
# sin BD; --integration = además la suite con BD real).
test:
	@if [ -f tests/run.php ]; then \
		php tests/run.php; \
	else \
		echo "⚠ tests/run.php aún no existe; se omite"; \
	fi

# ── integration ─────────────────────────────────────────────────
# Suite con BD real (levanta MariaDB en Docker). NO entra en `make check` ni en
# el hook pre-push: necesita Docker y tarda ~6 s en frío.
# Histórico: salía en rojo por una deriva de esquema real
# (setup/migration_002_moderation.sql hacía `ADD COLUMN ... AFTER source_name`
# sobre una columna que ningún fichero de setup/ creaba). Resuelto: el baseline
# de producción es setup/migration_000_prod_baseline.sql —se aplica el PRIMERO—
# y la 002 ya no lleva el AFTER, así que no depende del orden alfabético.
# Sin este target la capa 3 no tenía forma de ejecutarse desde el Makefile.
integration:
	@if [ -f tests/run.php ]; then \
		php tests/run.php --integration; \
	else \
		echo "⚠ tests/run.php no existe"; exit 1; \
	fi

# ── integration-ci ──────────────────────────────────────────────
# Lo mismo que `make integration`, pero EXIGE que la suite con BD se haya
# ejecutado de verdad. Es el target que corre .github/workflows/ci.yml.
#
# POR QUÉ HACE FALTA UN TARGET APARTE. Medido en este repo:
#
#     $ IAREPO_TEST_SKIP=1 php tests/run.php --integration ; echo $$?
#     ✅ 122 test(s) en verde · 89234 aserciones · 0.38s
#     0
#
# Cuando falta Docker (o pdo_mysql, o el puerto está cogido), cada test de
# tests/integration/ hace `if (!($$db = it_db_or_skip(...))) return;` y el
# runner los cuenta como PASADOS —con 0 aserciones—, así que la suite entera
# sale en verde sin haber tocado una sola tabla. En un portátil eso es lo
# correcto (un push no debe bloquearse por no tener Docker levantado: es una
# decisión explícita del diseño de bootstrap.php). En una máquina de CI es
# justo lo contrario: un verde que no prueba nada es peor que un rojo.
#
# La detección se apoya en el aviso que imprime iarepo_it_skip() en
# tests/integration/bootstrap.php. SI ALGÚN DÍA SE REESCRIBE ESE MENSAJE,
# actualiza también la cadena de aquí: el precio de no hacerlo es volver al
# verde falso, sin avisar. Por eso el flujo de CI comprueba ADEMÁS, por otra
# vía que no depende de ningún texto, que la base de datos existe dentro del
# contenedor.
integration-ci:
	@set -o pipefail; \
	log="$$(mktemp)"; trap 'rm -f "$$log"' EXIT; \
	php tests/run.php --integration 2>&1 | tee "$$log"; rc=$$?; \
	if [ $$rc -ne 0 ]; then exit $$rc; fi; \
	if grep -q 'SUITE DE INTEGRACIÓN SALTADA' "$$log"; then \
		echo ""; \
		echo "❌ integration-ci: la suite con BD real se SALTÓ."; \
		echo "   El runner ha anunciado 'en verde' sin conectarse a ninguna base de datos."; \
		echo "   El motivo está arriba, en la línea 'SUITE DE INTEGRACIÓN SALTADA'."; \
		echo "   Comprueba:  docker version  ·  php -m | grep pdo_mysql"; \
		exit 1; \
	fi; \
	if ! grep -q 'tests/integration/' "$$log"; then \
		echo ""; \
		echo "❌ integration-ci: no se ejecutó NINGÚN fichero de tests/integration/."; \
		echo "   O el directorio está vacío, o el runner no lo ha descubierto."; \
		exit 1; \
	fi; \
	echo ""; \
	echo "✅ integration-ci: la suite con BD real se ejecutó entera, sin saltos"

# ── check ───────────────────────────────────────────────────────
# Exactamente lo que valida el hook pre-push, pero sobre TODO el repo.
# Corre esto antes de empujar si quieres saber si el gate te dejará pasar.
check: lint guards test
	@echo ""
	@echo "✅ check completo: lint + guards + tests"

# ── smoke ───────────────────────────────────────────────────────
# POST-deploy: golpea https://iarepo.com por red. No sirve como gate previo
# (cuando falla, el fallo ya está en producción).
#
# TRES códigos de salida, no dos (make trata 1 y 2 igual: como fallo, que es la
# lectura conservadora deliberada):
#   0  corrida limpia
#   1  hay al menos un FAIL real
#   2  0 fallos pero quedaron checks INDETERMINADOS (un 429 del rate limit, o un
#      404 porque ese directorio aún no está desplegado) → la corrida NO valida
#      el deploy. Detalle en docs/RUNBOOK.md §5.
smoke:
	@bash quality/smoke_test.sh

# ── hooks ───────────────────────────────────────────────────────
# core.hooksPath apunta a un directorio versionado, así que el gate viaja con
# el repo y no hay que copiar nada a .git/hooks/ a mano.
hooks:
	@git config core.hooksPath .githooks
	@chmod +x .githooks/* 2>/dev/null || true
	@echo "✅ hooks instalados (core.hooksPath = .githooks)"
	@echo "   Se ejecutarán en cada 'git push'. Verifícalo con:"
	@echo "     git config core.hooksPath"
