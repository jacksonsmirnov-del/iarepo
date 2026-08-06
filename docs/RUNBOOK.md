# RUNBOOK — iarepo.com

> Procedimientos operativos, paso a paso y copiables. Nada de arquitectura aquí:
> eso está en `AGENTS.md`. Las reglas que se cargan en cada sesión, en `CLAUDE.md`.
>
> **⚠️ Este fichero es público** (`https://iarepo.com/*.md` responde 200 y `origin`
> empuja también a un repo público de GitHub). Por eso los comandos que tocan el
> servidor usan **marcadores** en lugar de coordenadas: `<SSH_HOST>`, `<SSH_PORT>`,
> `<SSH_USER>`, `<DOC_ROOT>`, `<DB_NAME>`, `<IP_PUBLICA>`. Los valores reales están en
> `.env.php` y en la memoria personal del mantenedor. **No los pegues aquí**, ni
> siquiera los que parezcan inofensivos: la IP ya cambió una vez (migración de servidor
> del 2026-07-13), así que además de ser una coordenada es un dato que caduca. Se obtiene
> en un segundo con `dig +short iarepo.com`.

---

## 0. Antes de tocar nada

### 0.1 🔴 PASO 0 — Rotar la contraseña de la BD (URGENTE · NO espera al deploy)

**Qué pasó:** `setup/seed_resources.php` contenía la contraseña real de la BD de
producción **en claro**. Ya no está en el working tree, pero **sigue en el historial de
git** (commit `5b6c1e6`) — y este repositorio se espeja en un **GitHub público**. Git
conserva el objeto aunque el fichero desaparezca; `git log -p` la sirve a cualquiera.

**Sacar el secreto del working tree NO es rotarlo.** Son dos acciones distintas y la
segunda es la única que cierra la exposición. Esto es **independiente del deploy**: hazlo
ya, antes que nada de lo demás, y no lo mezcles con el push de la tanda.

```bash
# 1. Genera una contraseña nueva (no la escribas en ningún fichero versionado)
openssl rand -base64 24

# 2. Cámbiala en el panel de la BD (hPanel → Bases de datos MySQL → usuario de iarepo)

# 3. Actualízala en el .env.php del servidor. NO está en git: se edita in situ.
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
cd <DOC_ROOT>
cp .env.php .env.php.bak.$(date +%F)      # red de seguridad, 30 segundos
$EDITOR .env.php                          # cambia SOLO la clave DB_PASS

# 4. Verifica INMEDIATAMENTE (si esto falla, el sitio está caído)
curl -s https://iarepo.com/api/health.php
#    Espera: "db":"connected".  Si dice "degraded" → la contraseña no coincide:
#    restaura .env.php.bak y vuelve al paso 3.
```

**Qué más hay que tocar con la contraseña nueva** — todo lo que lee `DB_PASS`:

| Consumidor | Qué hacer |
|---|---|
| `.env.php` del doc root | Paso 3 de arriba. Es la fuente de todo lo demás |
| `setup/tools/backup_db.sh` | **Nada**: lee de `.env.php` (`read_env DB_PASS`). Verifica con una corrida manual |
| Cron de hPanel | **Nada**: invoca el script, no lleva credenciales |
| Campus | **Nada**: usa `JWT_SECRET`, no la BD de iarepo |
| Cualquier cliente MySQL tuyo (Workbench, DBeaver) | Actualízalo a mano |

**Después, borra la copia de seguridad del `.env.php`** (`rm .env.php.bak.*`): un fichero
con la contraseña VIEJA dentro del doc root es exactamente el problema otra vez, y el
`.htaccess` solo protege `.env.php` por nombre exacto (`<FilesMatch "^\.env\.php$">`).

⚠️ **Reescribir el historial (`git filter-repo`, BFG) no es la respuesta aquí.** Descuadra
el bare del servidor y el remoto de GitHub, que cuelgan del mismo `origin`, y no borra lo
que ya se haya clonado o cacheado. La contraseña vieja hay que darla por comprometida para
siempre; lo que la desactiva es rotarla.

### 0.2 El modelo mental: push = producción + publicación

```
git push origin main
   ├─→ hook post-receive → checkout -f al doc root   = PRODUCCIÓN EN VIVO, sin staging
   └─→ github.com/jacksonsmirnov-del/iarepo          = REPO PÚBLICO
```

No hay entorno intermedio. El push **es** el despliegue. Los puntos donde se puede parar
algo son dos: el hook `pre-push` **local** (solo existe si alguien ejecutó `make hooks` en
ese clon) y, desde 2026-08, la **CI de GitHub** (`.github/workflows/ci.yml`), que corre
igual en cada push y **no la salta `--no-verify`**. La CI, eso sí, avisa *después* de que
el push ya haya desplegado: es una red, no un freno.

**Los agentes no ejecutan `git commit`, `push`, `checkout`, `stash` ni `reset`, ni se
conectan por SSH a producción.** Este runbook lo ejecuta el mantenedor.

---

## 1. Preparar un clon (una sola vez)

```bash
cd /home/smirnov/resources
make hooks                      # git config core.hooksPath .githooks
git config core.hooksPath       # debe imprimir: .githooks
```

Sin esto los ficheros de `quality/`, `tests/` y `.githooks/` son decorativos. El hook es
local por máquina: quien clone el repo tiene que repetirlo. **La CI de GitHub sí viaja con
el repo** y corre igual en cualquier clon, pero avisa *después* del push.

Requisitos del entorno: `bash`, `git`, `php` 8, `python3`, `curl`. `node` es opcional
(sin él, el guard de JavaScript inline degrada a aviso). `docker` solo para la capa 3.

---

## 2. Checklist pre-deploy

Recórrela entera. No es ceremonia: cada punto corresponde a un fallo que ya ocurrió.

- [ ] **`make check` en verde** (lint + guards + tests). Si algo está rojo, no se empuja.
- [ ] **El árbol está limpio.** `git status --short` vacío salvo lo que vas a commitear.
      Con el árbol sucio se despliega algo distinto de lo que probaste, y el hook aborta.
- [ ] **Ningún secreto en el diff.** `git diff --cached` revisado **a ojo**, no solo por el
      guard G4. Recuerda que el push publica en GitHub y el objeto sobrevive al revert —
      y que G4 ya dejó escapar una credencial posicional durante meses (§11.2).
- [ ] **Ninguna coordenada del servidor en el diff.** Ni IP, ni usuario SSH, ni puerto, ni
      nombre de BD, ni doc root — tampoco en comentarios.
      `setup/tools/deploy.env` **no se commitea nunca**.
- [ ] **Cadenas nuevas envueltas en `t()`** y añadidas a `shared/i18n_en.php`.
- [ ] **Ninguna página HTML nueva carga `shared/helpers.php`** (G1 lo comprueba, pero
      no añadas la ruta al baseline para silenciarlo).
- [ ] **Si tocaste SQL, el buscador o el diccionario de sinónimos:** `make integration`
      en verde. `shared/search_synonyms.php` no es código, pero cambia qué filas devuelve
      cada consulta.
- [ ] **Si el cambio incluye una migración:** NO va en el mismo push que el código que
      la usa. Ver §6.
- [ ] **Si tocaste `index.php`:** el JS del catálogo es inline; G6 valida su sintaxis,
      pero el CSS y el foco no los valida nadie. Míralo en el navegador tras el deploy.
- [ ] **Si tocaste `.htaccess`:** ni un `<Directory>` (§8.4). Y recuerda que **nada lo
      valida antes del deploy**: es lo primero a revisar si el sitio devuelve 500.

---

## 3. Comandos de validación

```bash
cd /home/smirnov/resources

# ── Gate completo (lo que exige el hook, sobre todo el repo) ──────
make check                      # lint + guards + test        ~1,5 s, sin red

# ── Piezas sueltas ───────────────────────────────────────────────
make lint                       # php -l + node --check sobre ficheros TRACKEADOS
make guards                     # quality/guards.sh — 9 chequeos estáticos (G1-G9)
bash quality/guards.sh --changed        # solo lo modificado vs origin/main (+ untracked)
bash quality/guards.sh --help           # uso, flags y dependencias
#   OJO: --help NO lista los guards. La lista viva son los encabezados '── Gn ·'
#   que imprime `bash quality/guards.sh`, o la cabecera del propio script.
make test                       # php tests/run.php — unitarios, sin BD
php tests/run.php --help
php tests/run.php --filter=search       # repetir solo una suite
php tests/run.php --list

# ── Capa 3: integración con MariaDB real en Docker ───────────────
make integration                        # = php tests/run.php --integration
php tests/integration/_runner.php       # runner autónomo de esa suite
php tests/integration/_runner.php --down   # borra el contenedor de test

# ── Capa 6: POST-deploy, usa red contra producción ───────────────
make smoke                              # = bash quality/smoke_test.sh
bash quality/smoke_test.sh https://iarepo.com
SEARCH_DELAY=0.6 bash quality/smoke_test.sh

# ── Buscador: ver la forma real de una consulta, sin BD ──────────
php -r 'require "shared/search.php"; print_r(iarepo_build_search("ondas sonido"));'
php -r '$g = require "shared/search_synonyms.php";
  $n = 0; foreach ($g as $x) $n += count($x);
  echo count($g), " grupos / $n términos\n";'
php tests/run.php --filter=search        # obligatorio si tocas search.php o el diccionario
```

**La CI corre lo mismo** (`.github/workflows/ci.yml`): el trabajo `gate` es `make check` y
el trabajo `integracion` es `make integration-ci`, que además **falla si la suite se
salta** — sin `pdo_mysql` o sin Docker, `--integration` imprime "en verde" con exit 0 sin
haber tocado una tabla. Para reproducir la CI en local: `make check` + `make integration-ci`.

**Nota sobre `make lint`:** solo mira ficheros trackeados (`git ls-files`). Un fichero
nuevo sin `git add` no se lintea. `guards.sh --changed` sí incluye untracked.

**Nota sobre `make smoke`:** hace decenas de peticiones y el listado limita a 120 GET/min
por IP, así que dos corridas seguidas en el mismo minuto pueden dar `429`. Desde
2026-08-04 eso **ya no se reporta como regresión**: un `429` se marca `INDETERMINADO`, el
script espera una vez y reintenta (§5). Aun así, espera un minuto entre corridas si
quieres una corrida concluyente.

**Estado de las capas 2 y 3 [V 2026-08-04]:** las dos en verde, cero tests en rojo. El
número exacto de tests cambia con cada añadido — córrelos, no cites la cifra.

**`make integration` existe** desde 2026-08-04 (antes este runbook decía que no). No
entra en `make check` ni en el hook: necesita Docker.

---

## 4. Deploy

> **Para la tanda pendiente del 2026-08-04 no uses esta sección: usa §10**, que lleva el
> orden completo (rotar la contraseña, hook, push, migración 010, crons, backup). Esto de
> aquí es el procedimiento **rutinario**, para un cambio normal sobre un servidor que ya
> está al día.

```bash
cd /home/smirnov/resources

# 1. Gate
make check

# 2. Commit (lo hace el mantenedor, nunca el agente)
git status --short
git add <ficheros>
git commit -m "..."

# 3. Empujar = desplegar y publicar a la vez
git push origin main

# 4. Verificación inmediata (§5)
sleep 5
curl -s https://iarepo.com/api/health.php | grep commit   # ¿aterrizó TU commit?
git rev-parse --short HEAD                                # tiene que coincidir
bash quality/smoke_test.sh
```

Si el hook aborta el push, **lee lo que dice antes de pensar en `--no-verify`**.
`git push --no-verify` no salta "un chequeo lento": deja el despliegue sin ninguna
barrera. Único uso legítimo: el propio gate está roto y estás empujando su arreglo, o
estás revirtiendo un deploy malo con el árbol ya en mal estado.

### 4.1 ⛔ Antes de tocar NADA por SSH: ¿estás en iarepo o en Campus?

**La cuenta de hosting sirve cuatro aplicaciones distintas** bajo el mismo
`public_html` [V 2026-08-06]:

| App | Qué es | Señas |
|---|---|---|
| `edu/` | **Campus** (claseprivada.com), en producción | `includes/services/`, páginas `401.php`–`503.php`, su propio `AGENTS.md` |
| `staging/` | **staging de Campus** — no de iarepo | copia casi idéntica de `edu/` |
| (un tercero, pequeño) | otro sitio | ~8 ficheros, `papers/` |
| **`resources/`** | **iarepo** | **`deploy_version.txt`**, `admin/`, `AGENTS.md` que empieza por «Relación con los otros dos documentos» |

⚠️ **Las cuatro tienen su propio `.env.php`, es decir su propia base de datos.**

**El fallo que esto evita** es silencioso y caro: correr una migración de iarepo estando
en el directorio de Campus. No da error de permisos ni de conexión — conecta
perfectamente **a la base de datos equivocada**. Estuvo a punto de ocurrir el 2026-08-06
porque el reflejo natural falla:

```bash
find ~ -name .env.php | head -1     # ⛔ DEVUELVE CAMPUS, NO IAREPO
```

**Usa siempre esto**, que se apoya en el único fichero que sólo existe en iarepo (lo
escribe su hook `post-receive`):

```bash
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
pwd
cat deploy_version.txt          # commit=, deployed_at=, branch=main, subject=
```

Si `deploy_version.txt` no aparece, **para**: o el hook no está instalado (`§10.3`) o no
estás en la cuenta correcta. No sigas a ojo.

**Comprobación de una línea antes de cualquier migración:**

```bash
[ -f deploy_version.txt ] && [ -f setup/run_migration.php ] \
  && echo "✓ iarepo — puedes continuar" \
  || echo "⛔ PARA: esto no es el doc root de iarepo"
```

### Empujar solo a GitHub (sin desplegar)

```bash
git push github main
```

`github` es un remoto propio además de ser uno de los dos pushURL de `origin`.

---

## 5. Verificación post-deploy

```bash
# 1. ¿Está viva la API, y QUÉ COMMIT está sirviendo?
curl -s https://iarepo.com/api/health.php
#    Espera: {"ok":true,"status":"healthy","db":"connected","resources":N,
#             "commit":"<sha corto>","deployed_at":"...","crons":{...}}

# 2. ¿Coincide con lo que tienes delante?
git rev-parse --short HEAD          # debe ser el mismo 'commit' de arriba

# 3. Smoke completo (ya compara commit y latidos por su cuenta)
bash quality/smoke_test.sh          # imprime PASS / FAIL / INDETERMINADO

# 4. Respaldo si 'commit' viene null (hook no instalado o sin permisos)
bash quality/verify_deploy.sh       # compara el sha256 de 5 assets estáticos
```

**Cómo leer los campos nuevos de `health.php`** (contrato completo: `AGENTS.md` §4.5):

| Lo que ves | Qué significa | Qué hacer |
|---|---|---|
| `commit` = tu HEAD | El `checkout -f` aterrizó | nada |
| `commit` ≠ tu HEAD | **Producción sirve otra cosa** | ¿el push falló? ¿alguien empujó después? Mira `deployed_at` y `deploy_subject` |
| `commit: null` | El hook no está instalado, no es ejecutable, o no pudo escribir el doc root | §10.3. `ls -l hooks/post-receive` debe ser `-rwxr-xr-x` |
| `crons: null` | La migración 010 no está aplicada, o la BD no responde | §10.5 |
| `crons.<job>.age_seconds: null` | **El job NUNCA ha latido**: declarado y no invocado | reactívalo en cron-job.org (§10.6) |
| `crons.<job>.stale: true` | Lleva más de su periodo × 3 sin correr | igual que arriba: el cron está parado |

⚠️ **`version` es una constante literal (`'1.1.0'`) y no dice nada sobre qué código corre.
Mira `commit`.** Esa confusión costó un mes de deploys que no desplegaban (§11.1).

**Códigos de salida de `smoke_test.sh`** (tres estados, no dos):

| exit | significado |
|------|-------------|
| `0`  | corrida limpia: todo lo que se pudo comprobar, pasó |
| `1`  | hay al menos un **FAIL** real |
| `2`  | 0 fallos, pero quedaron checks **INDETERMINADOS** (429 del rate limit, o un 404 porque el directorio aún no está desplegado) — la corrida **no valida el deploy** |

Un `429` no se pinta ni de verde ni de rojo: significa que el check no llegó a
ejecutarse. El script espera una vez (`RATE_WAIT=65`, el `Retry-After` que manda
`rateLimit()`) y reintenta; con `RATE_RETRY_BUDGET=0` se salta la espera y marca
INDETERMINADO de inmediato. Ojo si encadenas con `&&` o `set -e`: el `2` es nuevo.

`verify_deploy.sh` acepta base URL y lista de ficheros por argumento, y distingue «no
coincide» (exit 1) de «no se pudo comparar» (exit 2: fichero local ausente o descarga
que no da 200). Esos cinco ficheros salen verbatim del checkout, así que si alguno
difiere el deploy no aterrizó. Puede dar un DIFF falso por caché de LiteSpeed o del
service worker (ver §7.3). **Desde que `health.php` publica `commit`, esto es el plan B**,
no el método principal.

El smoke tiene además dos secciones que solo tienen sentido tras el deploy:

- **«Despliegue»** — compara el `commit` vivo con tu HEAD local. Si coinciden pero el
  árbol local está sucio, avisa: *lo que estás probando no es exactamente lo que tienes
  delante*.
- **«Latidos de los cron»** — `check_cron_job link_check 21600` y
  `check_cron_job moderation 120`. Un rojo aquí **es el hallazgo**, no un defecto del
  check: quiere decir que el job no está corriendo.

**A ojo, en el navegador** (nada de esto lo cubre ningún test automático):
la portada en móvil, que la barra de búsqueda pegajosa no tape la primera fila de
resultados, que Tab llegue a las tarjetas, y —si tocaste el frontend— **recarga forzada**
para descartar la caché del service worker.

---

## 6. Correr una migración en producción

**Regla de oro: nunca despliegues código y migración en el mismo push.**
Primero la migración (aditiva e idempotente), confirmas que el código viejo sigue
funcionando, y en un push posterior el código que la usa.

```bash
# 1. Probarla ANTES en la BD efímera de Docker. Sin este paso no se toca producción.
php tests/integration/_runner.php --down
make integration                         # levanta MariaDB y carga setup/ + fixtures

# 2. Revisar la migración a ojo:
#    - ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS (reejecutable)
#    - sin MODIFY que reescriba datos si no es imprescindible
#    - sin ';' dentro de literales: run_migration.php parte por ';' a ciegas
bash quality/guards.sh                   # G8 avisa de migraciones no idempotentes

# 3. Empujar SOLO la migración
git add setup/migration_0NN_*.sql && git commit -m "migration NNN: ..." && git push origin main

# 4. Ejecutarla en el servidor, desde el doc root (donde vive .env.php)
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

# ⛔ Sitúate por deploy_version.txt, NO por `find -name .env.php`, que devuelve
#    Campus. Ver §4.1: aplicar esto en Campus toca la BD equivocada sin avisar.
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
if [ ! -f deploy_version.txt ] || [ ! -f setup/run_migration.php ]; then
  echo "⛔ PARA: esto no es el doc root de iarepo. No se ha ejecutado nada."
else
  php setup/run_migration.php setup/migration_0NN_....sql
fi

# 5. Verificar
curl -s https://iarepo.com/api/health.php
bash quality/smoke_test.sh
```

**Limitaciones conocidas de `setup/run_migration.php`** (punto 13 de `AGENTS.md` §13): parte el
SQL con `explode(';', ...)`, no usa transacción, no registra qué aplicó y no tiene
`--dry-run`. Se ejecuta a mano contra la única copia de los datos. Léelo antes de
confiar en él con algo grande.

**No hay rollback de base de datos.** Hasta que §8.1 esté hecho, una migración mala es
irreversible. Dilo en voz alta antes de pulsar enter.

---

## 7. Incidentes

### 7.1 Rollback de un deploy malo

```bash
# 1. Identificar el commit culpable
git log --oneline -10

# 2. REVERT, no reset. El hook hace `checkout -f` desde el bare: revert avanza el
#    historial, deja rastro auditable y no descuadra el remoto de GitHub.
git revert --no-edit <sha_malo>
git push origin main

# 3. Verificar
sleep 5
bash quality/smoke_test.sh
```

Tiempo estimado: menos de 2 minutos. Si el hook bloquea el revert porque el árbol ya
estaba roto, ese es el uso legítimo de `--no-verify`.

**No uses `push --force` de un SHA viejo:** también despliega, pero descuadra el bare
repo y el remoto de GitHub que cuelgan del mismo `origin`.

**`.env.php` y `thumbnails/` no están en git**, así que un `checkout -f` no los toca.
El peligro real sería un `git clean -fdx` dentro del hook post-receive: confirma que no
está ahí, y si está, quítalo.

### 7.2 El sitio devuelve 500

En orden, de lo más barato a lo más caro:

```bash
# 1. ¿Es la BD o es el código? ¿Y qué commit lo causó?
curl -s https://iarepo.com/api/health.php
#    "db":"connected"  → el problema es el código; mira 'commit' y 'deploy_subject'
#    "degraded" / sin respuesta → credenciales o BD caída
#    (¿acabas de rotar la contraseña? → §0.1, restaura .env.php.bak)
```

⚠️ **Si el 500 es en TODAS las rutas y acabas de tocar `.htaccess`, empieza por ahí.** Un
bloque `<Directory>` en un `.htaccess` tumba el sitio entero bajo Apache (§8.4). Es el
fallo más barato de descartar y el más caro de no ver: `health.php` tampoco responde, así
que el paso 1 no te dirá nada útil.

2. **¿Es solo una ruta?** Prueba `/`, `/resource/<id>`, `/api/resources.php?limit=1`.
   Si la API responde y las páginas HTML no, sospecha de la regla #1
   (`helpers.php` en una página HTML: busca un blob `{"ok":false,...}` en el HTML).

3. **¿La página imprime su propio código fuente?** Es la regla #2 (`?>` en un comentario
   de línea). `php -l` no lo ve; usa:
   ```bash
   php quality/lib/analyze.php close-tag <fichero>
   ```

4. **Errores JS del cliente:** `https://iarepo.com/admin/errors.php?pass=<ADMIN_PASS>`
   (agrupados, últimos 7 días).

5. **Errores PHP:** el `error_log` del doc root, por SSH. No está en git.

6. **Si el 500 es del buscador** con una consulta concreta, reprodúcelo en local sin BD:
   ```bash
   php -r 'require "shared/search.php"; var_dump(iarepo_build_search("<consulta>"));'
   ```
   Por diseño ningún input puede producir SQL inválido; si lo hace, es un bug de
   `shared/search.php` y hay un test que debería haberlo cazado.

   **Si el SQL que sale es válido y el 500 solo ocurre en producción, es el `REGEXP`**
   (`AGENTS.md` §7.4.1). Distingue el alcance antes de decidir el rollback:

   ```bash
   curl -s -o /dev/null -w 'pH:%{http_code}\n'    'https://iarepo.com/api/resources.php?search=pH'
   curl -s -o /dev/null -w 'ondas:%{http_code}\n' 'https://iarepo.com/api/resources.php?search=ondas'
   curl -s -o /dev/null -w 'zxqv:%{http_code}\n'  'https://iarepo.com/api/resources.php?search=zxqv'
   ```

   | Resultado | Qué falla | Rollback |
   |---|---|---|
   | solo `pH` da 500 | la frontera de palabra (tokens < 3) | `iarepo_term_condition()`: `if ($word) {` → `if (false) {` |
   | `pH` **y** `ondas` dan 500, `zxqv` no | también el principio de palabra de los **sinónimos** | además: `iarepo_syn_condition()` devuelve `[null, []]` siempre |
   | los tres dan 500 | no es el `REGEXP`: mira `error_log` | revierte el push (§7.1) |

7. **Si nada de lo anterior:** revierte (§7.1) y diagnostica con el sitio ya sano.

### 7.3 El deploy "no se ve"

**Primero descarta lo único que se puede responder con un dato**, no con una sospecha:

```bash
curl -s https://iarepo.com/api/health.php | grep -E 'commit|deployed_at'
git rev-parse --short HEAD
```

- **Coinciden** → el código está desplegado y lo que ves es caché: **service worker**
  (recarga forzada / DevTools → Application → Unregister) o caché de LiteSpeed.
- **No coinciden** → el `checkout -f` no aterrizó, o alguien empujó después. Mira
  `deploy_subject`.
- **`commit` es `null`** → el hook no está instalado, no es ejecutable o no pudo escribir
  el doc root (§10.3). **Esto es lo que estuvo un mes sin detectarse** (§11.1); usa
  `bash quality/verify_deploy.sh` como plan B mientras lo arreglas.

---

## 8. Tareas de infraestructura pendientes

Ordenadas por lo que más duele. Ninguna la puede hacer un agente: requieren SSH, DNS o
decisiones del mantenedor.

### 8.1 ~~[P0] Backup de la BD~~ — **HECHO** [V 2026-08-06]

✅ **El cron está instalado y corriendo.** Verificado en el servidor: existe
`~/iarepo-backups/db/<fecha>/resources.sql.gz`, con `mtime` a las **04:15** —la hora
programada, no una corrida manual—, gzip íntegro y cerrado con `-- Dump completed`.

⚠️ **Un backup sólo cubre el esquema que existía cuando corrió.** El del 2026-08-06 se
hizo a las 04:15 y las migraciones 011-014 se aplicaron a las ~13:00, así que ese dump
trae 19 tablas y **no** `resource_views`, `view_salts` ni `resource_comprehension`.
Restaurar desde él obliga a **volver a aplicar las migraciones** (son idempotentes, así
que es seguro). A partir del día siguiente a una migración, el dump ya la incluye.

El texto original de esta sección, ya cumplido:

`setup/tools/backup_db.sh` (755) existe, está parametrizado y **se ensayó con una
restauración real**: 18/18 tablas, filas idénticas, FULLTEXT preservado [V 2026-08-04].
Lo que falta es darle un cron. Procedimiento completo en **§10.7**.

Qué hace y por qué así (detalle en `AGENTS.md` §8.8):

- **Independiente del backup de Campus**: su propia carpeta (`~/iarepo-backups`), su
  propio cron. No lee ni escribe nada de `~/backups/`.
- Credenciales **leídas de `.env.php`** y pasadas a `mysqldump` por un fichero de opciones
  con permisos 600 (`MYSQL_PWD` sería visible en `/proc`). Por eso el paso 0 (rotar la
  contraseña) no obliga a tocar el script.
- **Cinturón anti-Campus**: si `DB_NAME` no contiene `resources`, aborta.
- **Escritura atómica** (`.partial` → `mv`) y **verificación**: `gzip -t`, marcador
  `-- Dump completed`, y tantos `CREATE TABLE` como tablas tenga la BD viva. El **tamaño
  no es señal fiable** — se aprendió ensayando: un binario ausente dejaba un `.gz` de 20
  bytes que *parecía* el backup del día.
- Retención 14 días · tar semanal de `thumbnails/` los domingos.

⚠️ **En este hosting NO existe el comando `crontab`.** Se instala por hPanel → Cron Jobs
(§10.7). No pierdas el tiempo buscándolo por SSH.

**Volver a probar la restauración** después de cualquier cambio de esquema:

```bash
zcat ~/iarepo-backups/db/<fecha>/resources.sql.gz | head -40   # ¿pinta bien?
# y cargarlo en la BD efímera de Docker para comprobar que la app arranca contra ella
```

### 8.2 [P0] `setup/schema_baseline.sql` — que el repo pueda reconstruir producción

```bash
# En el servidor:
mysqldump --no-data --skip-add-drop-table --skip-comments <DB_NAME> > schema_baseline.sql
# Traerlo a setup/schema_baseline.sql y commitearlo.
```

Pasa a ser la única fuente de verdad del esquema; `schema.sql` + migraciones quedan como
historia. Con eso:

- Un `mysqldump` es la verdad literal de producción; lo que hay hoy en `setup/` es una
  reconstrucción por inferencia (fiel para todo el SQL que ejecuta el código, pero no
  verificada columna a columna contra prod).
- Se puede reconstruir la BD desde cero tras un desastre.

**Ya NO hace falta arreglar** lo que `AGENTS.md` §2.4 listaba: el `AFTER source_name` de
`migration_002` está quitado, `iframe_blocked` la crea
`setup/migration_000_prod_baseline.sql` y el `ENUM` de `moderation_status` ya declara
`pending_review`. La reconstrucción desde `setup/` aplica **0 errores SQL** y
`php tests/run.php --integration` está en verde.

Lo único que sigue vivo de aquello: la ampliación del ENUM viaja dentro de un
`ADD COLUMN IF NOT EXISTS`, que en producción es **no-op**, así que el ENUM real de prod
sigue sin verificar. Sólo importa el día que se encienda `OPEN_REGISTRATION`; antes de
hacerlo, comprobar el tipo real con `SHOW COLUMNS FROM resources LIKE
'moderation_status'` y, si falta `pending_review`, aplicar el `MODIFY COLUMN` manual que
documenta `setup/migration_002_moderation.sql` (reordenar un ENUM reescribe datos).

### 8.3 [P1] SPF y DMARC para `iarepo.com`

Verificado 2026-08-04: `dig +short TXT iarepo.com` → vacío; `dig +short TXT
_dmarc.iarepo.com` → vacío; el MX apunta al parking del registrador. El correo
transaccional (`shared/mailer.php`, notificaciones y `unsubscribe.php`) sale sin
autenticación de dominio, y además la IP emisora cambió con la migración de servidor de
2026-07-13. Riesgo alto de que `noreply@iarepo.com` acabe en spam.

Registros a crear en el DNS de `iarepo.com` (sustituye `<IP_PUBLICA>`, que sale de
`dig +short iarepo.com`):

```
iarepo.com.          TXT   "v=spf1 ip4:<IP_PUBLICA> ~all"
_dmarc.iarepo.com.   TXT   "v=DMARC1; p=none; rua=mailto:<buzón_de_informes>"
```

⚠️ **No des por buena esa IP sin preguntar.** El `ip4:` solo es correcto si el correo
sale de la misma máquina que sirve la web. Si Hostinger lo emite desde otra IP o desde
un relay propio, hay que usar el `include:` que ellos recomienden. **Confírmalo con
soporte antes de publicar el registro:** un SPF mal puesto empeora la entregabilidad en
vez de mejorarla, porque pasa a declarar como no autorizado un emisor que sí lo es.

Verificación:

```bash
dig +short iarepo.com                 # la IP a la que resuelve hoy
dig +short TXT iarepo.com             # deja de estar vacío
dig +short TXT _dmarc.iarepo.com      # deja de estar vacío
```

### 8.4 ~~Cerrar las superficies servidas por HTTP~~ — HECHO [V 2026-08-04]

`.htaccess` ya bloquea `tests/`, `quality/`, `docs/`, `Makefile` y todo lo que empiece
por `.git` (`.githooks/`, `.gitignore`), con el mismo idioma `RewriteRule ^dir/ - [F,L]`
que ya usaba `setup/`. El smoke test lleva una sección «Exposición de ficheros de
desarrollo» con 8 checks que lo verifican tras el deploy (403 → PASS, 200 → FAIL,
404 → INDETERMINADO porque el directorio aún no está desplegado).

**Lo que NO se hizo, a propósito:** el `<FilesMatch "\.(md|sh|...)$">` global que
proponía la versión anterior de esta sección habría tumbado `AGENTS.md`, `CLAUDE.md` y
`README.md`, que se sirven públicamente **a posta** (convención `AGENTS.md`/`llms.txt`,
regla 6 del proyecto). Si algún día se quiere ese cambio, es una decisión de producto.

**Dos cosas que conviene saber:**

1. **Orden de despliegue.** Si `tests/` o `quality/` se despliegan en un push y el
   `.htaccess` en otro posterior, hay una ventana en la que los ficheros se sirven.
   Van en el **mismo commit**, o primero el `.htaccess`.
2. ~~**Bomba dormida: el bloque `<Directory "setup">`**~~ — **DESACTIVADA el 2026-08-04.**
   `<Directory>` **no es sintaxis válida dentro de un `.htaccess`**: verificado contra un
   httpd 2.4 real con mod_rewrite, con ese bloque Apache devuelve **500 en TODAS las rutas
   del sitio** (`<Directory not allowed here`) — 24/24 rutas probadas. Sobrevivía solo
   porque LiteSpeed lo ignora y el trabajo lo hacía el `RewriteRule` de respaldo. Se
   sustituyó por `RewriteRule ^setup(/|$) - [F,L]`, que además cubre `/setup` sin barra.

   ⚠️ **Aviso honesto: la corrección se probó en Apache, NO en LiteSpeed**, que es lo que
   corre producción. Que funcione es inferencia fuerte —es el mismo idioma que ya usan
   `shared/` y `admin/` allí hoy—, no una comprobación. **Nunca reintroduzcas un
   `<Directory>` en este fichero.**

### 8.5 ~~[P1] Saber qué commit está desplegado~~ — HECHO [V 2026-08-04]

`setup/hooks/post-receive` (copia versionada del hook del servidor) escribe
`deploy_version.txt` en el doc root y `api/health.php` lo publica como `commit`,
`commit_full`, `deployed_at` y `deploy_subject`. `quality/smoke_test.sh` lo compara con el
HEAD local. **La pregunta "¿qué commit está vivo?" ya se responde sin SSH.**

Lo que queda es **instalarlo en el servidor** (§10.3). Dos cosas que no se pueden olvidar:

- El destino **no** está escrito en el hook (el repo es público): sale de
  `git config deploy.worktree`. **Si no está configurado, el hook ABORTA** e imprime la
  orden que falta. Por eso el `git config` va **antes** de copiar el hook.
- **`chmod +x`.** Git ignora los hooks no ejecutables **en silencio**: así fue como el
  anterior estuvo muerto un mes (§11.1).

### 8.6 [P2] Staging

⚠️ **iarepo NO tiene staging. Campus SÍ** [V 2026-08-06]. En el servidor hay un
directorio `staging/`, y es **de Campus**: misma estructura que su producción
(`includes/services/`, páginas `401.php`–`503.php`), no de iarepo. Cuando `CLAUDE.md`
dice «no hay staging» se refiere a este repo, y es cierto. No lo reutilices sin hablarlo:
tiene su propio `.env.php`, es decir su propia base de datos. Ver `§4.1` y `AGENTS.md §1.1`.

**Vale la pena, pero después de §8.1 y §8.2.** Su argumento decisivo es único:
`.htaccess` bajo LiteSpeed (rewrites de `/view/`, `/resource/`, `/profile/`, bloqueos de
`setup/`/`shared/`/`admin/`, regex de CORS y el catch-all de 404) **es hoy incomprobable
fuera del servidor real**.

Coste estimado: 3-4 h. Rama `dev` + segundo repo bare con su `post-receive` → un doc root
aparte; BD propia sembrada desde el dump del backup; `.env.php` propio; una URI de
redirección más en Google OAuth; `robots.txt` noindex + Basic Auth.

Tres riesgos concretos, los tres verificados en el código:

1. **`JWT_SECRET`.** Si staging comparte secreto con producción, se convierte en un
   oráculo que emite tokens válidos para prod: `shared/auth.php` valida cualquier token
   firmado con ese secreto, sin comprobar audiencia. Staging **debe** usar otro secreto,
   asumiendo que la integración con Campus no se prueba allí.
2. **CORS.** La regla de `.htaccess:78-79` es `^https://([a-z0-9-]+\.)?claseprivada\.com$`:
   cualquier subdominio de staging entra automáticamente en la lista blanca de
   producción. Revisar antes de elegir el subdominio.
3. **Apuntar staging a la BD de producción.** Es el error clásico. Defensa: un check en
   el smoke que exija que el `resources` count de staging sea distinto al de prod.

`quality/smoke_test.sh` ya acepta una base URL completa como `$1`, así que esa mitad
está hecha.

### 8.7 ~~Resolver `.gitignore` vs `AGENTS.md`~~ — HECHO [V 2026-08-04]

`.gitignore` listaba `AGENTS.md` mientras el fichero estaba trackeado y publicado. **La
línea era inerte** —git no ignora lo ya trackeado— y su único efecto era inducir a creer
que el fichero era privado. Se quitó, y en su lugar hay un comentario que dice
explícitamente que `AGENTS.md`, `CLAUDE.md` y `docs/` **sí** viajan y **sí** se sirven
públicamente.

Lo que `.gitignore` sí ignora hoy y conviene saber: `.env.php`,
`setup/tools/deploy.env` (coordenadas SSH de los scripts de `setup/tools/`; la plantilla
versionada es `deploy.env.example`), `deploy_version.txt` (lo escribe el servidor en cada
deploy — **si se commiteara, el `checkout -f` lo machacaría con un valor equivocado y
`health.php` mentiría sobre qué commit está vivo**) y `.claude/`, así que los settings del
proyecto no viajan con el repo. Es una decisión consciente; `CLAUDE.md` sí viaja.

### 8.8 [HECHO] El rojo del gate

`php tests/run.php` estuvo en rojo por un bug real de una línea en
`shared/search.php` (punto 4 de `AGENTS.md` §13). **Arreglado el 2026-08-04**; verificado con
`php tests/run.php` → exit 0, ningún test en rojo. Se deja anotado como precedente:
cuando el gate se ponga rojo, la respuesta es arreglar el bug, nunca silenciar el test —
y desde que existe el guard G9, borrar la suite tampoco funciona.

Comprueba siempre que ese clon tiene el gate instalado antes de dar por hecho que algo
te protege:

```bash
git config core.hooksPath      # debe imprimir .githooks; si no imprime nada, NO hay gate
```

---

## 9. Referencia rápida

| Quiero… | Comando |
|---|---|
| Saber si puedo empujar | `make check` |
| Ver qué comprueba cada guard | `bash quality/guards.sh` (los encabezados `── Gn ·`) |
| Repetir solo un test | `php tests/run.php --filter=<texto>` |
| Probar con BD real | `make integration` (= `php tests/run.php --integration`) |
| Verificar producción | `bash quality/smoke_test.sh` |
| Estado vivo del sitio | `curl -s https://iarepo.com/api/health.php` |
| **Saber qué commit está vivo** | `curl -s https://iarepo.com/api/health.php` → campo `commit` |
| **Comparar con lo que tengo** | `git rev-parse --short HEAD` |
| **Saber si un cron está muerto** | `health.php` → `crons.<job>.stale` / `age_seconds` |
| Ver errores JS de usuarios | `https://iarepo.com/admin/errors.php?pass=<ADMIN_PASS>` |
| Instalar el gate local | `make hooks` · comprobar: `git config core.hooksPath` |
| Comprobar el deploy sin `commit` (plan B) | `bash quality/verify_deploy.sh` |
| **Ver la forma real de una búsqueda, sin BD** | `php -r 'require "shared/search.php"; print_r(iarepo_build_search("<consulta>"));'` |
| **Contar el diccionario de sinónimos** | `php -r '$g=require "shared/search_synonyms.php"; echo count($g);'` |
| **Lanzar un backup a mano** | `~/iarepo-backups/run_backup.sh` (en el servidor) |
| Frescura de la documentación | `git log -1 --date=short -- CLAUDE.md AGENTS.md docs/` |

---

## 10. Cierre: lo que solo puedes hacer tú — **en este orden**

Todo lo de esta sección requiere tu clon, tus credenciales, SSH o el panel de hosting.
**Ningún agente puede hacer nada de esto por ti**, y hasta que no lo hagas el trabajo de
la tanda del 2026-08-04 vive únicamente en tu working tree.

> Las coordenadas del servidor (host, puerto SSH, usuario, doc root, nombre de BD, ruta
> del repo bare) **no están en este fichero a propósito**: es público. Están en `.env.php`
> y en tu memoria personal. Donde aparezcan `<SSH_HOST>`, `<SSH_PORT>`, `<SSH_USER>`,
> `<DOC_ROOT>`, `<BARE_REPO>`, `<DB_NAME>` o `<IP_PUBLICA>`, sustitúyelas tú.

**El orden importa y no es negociable.** Cada paso depende del anterior:

| # | Paso | Por qué va aquí |
|---|---|---|
| 0 | **Rotar la contraseña** (§0.1) | Urgente y **NO depende del deploy**. Está publicada ahora mismo |
| 1 | Gate en verde (§10.1) | Si está rojo no hay nada que empujar |
| 2 | Instalar el hook (§10.3) | **ANTES del push**, o el push despliega a ciegas otra vez. Sube el fichero **desde tu clon** (`scp`): aún no está en el doc root |
| 3 | Commit + push (§10.4) | Despliega y publica en el espejo a la vez |
| 4 | Migración 010 (§10.5) | Después del código: `health.php` tolera que la tabla no exista |
| 5 | Reactivar `link_check` (§10.6) | Después de la 010, si no el latido no tiene dónde escribirse |
| 6 | Cron del backup (§10.7) | Independiente, pero hazlo el mismo día |
| 7 | Verificar todo (§10.8) | Lo único que convierte "he empujado" en "está desplegado" |

### 10.1 El gate en verde

```bash
cd /home/smirnov/resources
make check                       # lint + guards + test. exit 0 OBLIGATORIO
make integration                 # esta tanda toca el buscador: la capa 3 NO es opcional
git status --short               # MÍRALO: hay mucho untracked en esta tanda
```

⚠️ Si `make check` sale rojo, **el gate está haciendo su trabajo**. G4 marca en bloqueante
cualquier línea que *parezca* una credencial literal (§8.3 de `AGENTS.md`), y desde
2026-08-04 también las **posicionales** — que son justo las que dejó escapar durante meses.
Resuélvelo arreglando la línea o justificando la excepción, **nunca con `--no-verify`**.

### 10.2 Instalar el gate local en este clon (una vez)

```bash
cd /home/smirnov/resources
make hooks
git config core.hooksPath        # DEBE imprimir: .githooks. Si no imprime nada, NO hay gate
```

Es configuración **local por clon**, no viaja con el repo. Desde 2026-08 la CI de GitHub
es la segunda red, pero avisa *después* de que el push ya haya desplegado.

### 10.3 Instalar el hook de deploy en el servidor — **`git config` ANTES de copiar**

Sin esto el push sigue desplegando, pero **a ciegas**: `health.php` seguirá devolviendo
`commit: null` y no habrá forma de saber si el checkout aterrizó. Es exactamente el fallo
que estuvo un mes sin detectarse (§11.1).

🔴 **De dónde sale el fichero: de TU CLON, no del doc root.** `setup/hooks/post-receive`
es **nuevo en esta tanda y todavía no está commiteado**, así que `<DOC_ROOT>/setup/hooks/`
**no existe** en el servidor. Y no puede existir antes del push, porque el push es
justamente lo que no se puede verificar sin el hook. Se rompe el círculo subiéndolo desde
el clon local, que es donde el fichero sí está:

```bash
# ── DESDE TU MÁQUINA, antes de conectarte ────────────────────────
cd /home/smirnov/resources
bash -n setup/hooks/post-receive && echo "sintaxis OK"      # antes de subir nada
scp -P <SSH_PORT> setup/hooks/post-receive \
    <SSH_USER>@<SSH_HOST>:<BARE_REPO>/hooks/post-receive.new
```

```bash
# ── YA EN EL SERVIDOR ────────────────────────────────────────────
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
cd <BARE_REPO>                   # el repo BARE (…​.git), no el doc root

# 1. PRIMERO la configuración. El hook NO adivina el destino: si esto falta, ABORTA.
git config deploy.worktree <DOC_ROOT>
git config --get deploy.worktree          # verifica que quedó escrito

# 2. Guarda el hook viejo por si acaso, y pon el nuevo en su sitio
cp hooks/post-receive hooks/post-receive.bak.$(date +%F) 2>/dev/null || true
mv hooks/post-receive.new hooks/post-receive

# 3. EL PASO QUE NADIE PUEDE OLVIDAR
chmod +x hooks/post-receive
ls -l hooks/post-receive                  # DEBE verse -rwxr-xr-x

# 4. Sintaxis, ya en destino
bash -n hooks/post-receive && echo "sintaxis OK"
```

A partir del push de §10.4 el fichero **sí** estará en `<DOC_ROOT>/setup/hooks/`, y las
actualizaciones futuras del hook ya se pueden hacer con
`cp <DOC_ROOT>/setup/hooks/post-receive hooks/post-receive && chmod +x hooks/post-receive`.
La copia versionada existe precisamente para que esa segunda vez sea trivial; esta primera
no puede serlo.

⚠️ **Git ignora los hooks no ejecutables EN SILENCIO.** Ni error, ni aviso: el push sale
bien y no despliega nada. El `chmod +x` y el `ls -l` son parte del procedimiento, no una
comprobación opcional.

⚠️ **Si copias el hook antes del `git config`**, el primer push aborta con un mensaje que
te dice exactamente qué falta. No es grave —el commit **sí** queda recibido—, pero tendrás
que volver a empujar (o correr el `checkout` a mano) después de configurarlo.

### 10.4 Desplegar (el push que falta)

**Orden importante — todo en el MISMO commit.** Tres acoplamientos reales, los tres
verificados:

1. `index.php` (el `<select>` de orden) depende de la rama `relevance` de
   `api/resources.php`. Por separado, el desplegable vuelve a mentir, solo que al revés.
2. `api/resources.php` depende de `shared/search.php`, que a su vez depende de
   `shared/search_synonyms.php`. **Si el diccionario no viaja, el buscador se degrada a
   "sin sinónimos"** — no revienta, pero "biología" vuelve a devolver cero.
3. El bloqueo de `.htaccess` debe llegar **con o antes que** `tests/`, `quality/` y
   `docs/`. Al revés hay una ventana en la que esos ficheros se sirven por HTTP.

```bash
cd /home/smirnov/resources
git status --short               # MÍRALO ANTES: hay mucho untracked de esta tanda
make check                       # exit 0 OBLIGATORIO. Si falla, lee lo que dice
git add <ficheros>
git commit -m "buscador bilingüe, deploy verificable, latidos de cron, backup y CI"
git push origin main             # despliega Y publica en GitHub a la vez
```

⚠️ **Evita `git add -A` a ciegas.** El árbol lleva bastante untracked y no todo tiene por
qué entrar en el mismo commit. En particular, **no metas `setup/tools/deploy.env`** si lo
has creado: lleva las coordenadas del servidor y el repo es público (está en `.gitignore`,
pero un `git add -f` lo saltaría).

**Qué debe imprimir el push si el hook está bien instalado** (git antepone `remote:`):

```
remote: post-receive: desplegando main → doc root (destino tomado de: git config deploy.worktree)
remote: post-receive: ✅ checkout hecho — <sha>  <asunto>
remote: post-receive: ✅ deploy_version.txt actualizado — commit=<sha> deployed_at=...
```

Si en su lugar ves `❌ DEPLOY ABORTADO — no sé dónde está el doc root`, te saltaste el
`git config` de §10.3. Si **no ves nada**, el hook no es ejecutable.

### 10.5 Aplicar la migración 010 (latidos de cron)

Va **después** del código, y es seguro que sea así: `api/health.php` devuelve
`crons: null` si la tabla no existe, en vez de reventar. La migración es **idempotente**
(`CREATE TABLE IF NOT EXISTS` + `INSERT … ON DUPLICATE KEY UPDATE`): correrla dos veces no
altera ningún latido ya registrado, solo reafirma el periodo esperado de cada job.

```bash
# 1. Probarla ANTES en la BD efímera de Docker (esto ya lo cubre `make integration`)
make integration

# 2. En el servidor, desde el doc root (donde vive .env.php)
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

# ⛔ Sitúate por deploy_version.txt, NO por `find -name .env.php`, que devuelve
#    Campus. Ver §4.1: aplicar esto en Campus toca la BD equivocada sin avisar.
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
if [ ! -f deploy_version.txt ] || [ ! -f setup/run_migration.php ]; then
  echo "⛔ PARA: esto no es el doc root de iarepo. No se ha ejecutado nada."
else
  php setup/run_migration.php setup/migration_010_cron_heartbeats.sql
fi

# 3. Verificar desde fuera: 'crons' deja de ser null
curl -s https://iarepo.com/api/health.php
```

Tras aplicarla, los dos jobs aparecerán con **`age_seconds: null`** — "declarado pero
nunca ha latido". Es lo correcto: todavía no ha corrido ninguno. `moderation` debería
latir en los 2 minutos siguientes; `link_check` **no latirá hasta que lo reactives**
(§10.6), y ese es justo el hallazgo.

### 10.5.1 Aplicar la migración 011 (señal "lo usé en clase")

⚠️ **Va DESPUÉS del push, y no hay alternativa:** este fichero de migración **no existe
en el servidor hasta que el push lo deposita allí** (`setup/` se despliega por el
checkout del hook). Cualquier instrucción de "aplicarla antes" es inejecutable.

**Y por eso todas las consultas nuevas degradan.** Entre el push y la migración hay una
ventana en la que el código pide columnas que aún no existen; está cubierta a propósito:
la consulta de la página va en `try/catch` y degrada a "no registrado". Sin eso, un
`ERROR 1054` sin capturar sacaría la página a medio renderizar con un JSON incrustado
(trampa nº1 del `CLAUDE.md`). El botón queda **inerte** durante esa ventana —pulsarlo da
un 500 genérico y el motivo real queda en el log— pero **ninguna página se rompe**.
Cierra la ventana aplicando la migración justo después del push.

Es **idempotente** (`CREATE TABLE IF NOT EXISTS` + `ADD COLUMN IF NOT EXISTS` + `ADD
UNIQUE KEY IF NOT EXISTS` + un `MODIFY` que reafirma una definición ya correcta) y
**defensiva**: no asume qué contiene `resource_usage` en producción, lo garantiza.

```bash
# 1. Probarla ANTES contra MariaDB real. Aplica la migración sobre una tabla
#    con la forma de producción Y CON DATOS, y exige que forkear dos veces el
#    mismo día siga siendo legal.
make integration        # tests/integration/usage_signal_db_test.php

# 2. En el servidor, desde el doc root
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

# ⛔ Sitúate por deploy_version.txt, NO por `find -name .env.php`, que devuelve
#    Campus. Ver §4.1: aplicar esto en Campus toca la BD equivocada sin avisar.
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
if [ ! -f deploy_version.txt ] || [ ! -f setup/run_migration.php ]; then
  echo "⛔ PARA: esto no es el doc root de iarepo. No se ha ejecutado nada."
else
  php setup/run_migration.php setup/migration_011_usage_signal.sql
fi

# 3. Verificar el esquema. Usa shared/db.php, que lee .env.php por dentro: así
#    las credenciales NO acaban en la línea de comandos ni en el historial.
php -r 'require "shared/db.php"; $d = getResourcesDB();
foreach ($d->query("SHOW COLUMNS FROM resource_usage LIKE \"usage_day\"") as $r) { echo $r["Field"], " ", $r["Type"], PHP_EOL; }'
```

**Verificar después del deploy**, ya con el código arriba: entra en cualquier recurso
como docente, pulsa **"Lo usé en clase"** y comprueba que

- el botón queda en verde y dice "Registrado hoy",
- el contador **Usos en clase** sube,
- **pulsarlo otra vez tras recargar no lo vuelve a subir** (ésa es la dedup), y
- un usuario con rol `student` **no ve el botón** y, si lanza el `POST` a mano, recibe
  **403**.

### 10.5.2 Aplicar la migración 012 (medición de visitas)

⚠️ **Va DESPUÉS del push**, por lo mismo que la 011: el fichero llega con el checkout.
Durante la ventana, la ficha muestra sólo el histórico (hay un `?? 0` que lo cubre) y el
beacon devuelve 500 sin romper ninguna página — pero no se cuenta nada.

Idempotente: `CREATE TABLE IF NOT EXISTS` ×2 + `ADD COLUMN IF NOT EXISTS`.

```bash
# 1. Probarla contra MariaDB real ANTES. Verifica lo que sostiene todo el
#    arreglo: que rowCount() valga 1 al insertar y 2 al actualizar.
make integration        # tests/integration/tracking_db_test.php

# 2. En el servidor, desde el doc root
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

# ⛔ Sitúate por deploy_version.txt, NO por `find -name .env.php`, que devuelve
#    Campus. Ver §4.1: aplicar esto en Campus toca la BD equivocada sin avisar.
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
if [ ! -f deploy_version.txt ] || [ ! -f setup/run_migration.php ]; then
  echo "⛔ PARA: esto no es el doc root de iarepo. No se ha ejecutado nada."
else
  php setup/run_migration.php setup/migration_012_engagement.sql
fi
```

**Verificar después del deploy**, con el código arriba:

```bash
# La ficha ya no debe dar 500 y el beacon debe aceptar una visita de prueba
curl -s -o /dev/null -w '%{http_code}\n' https://iarepo.com/resource/1
curl -s -X POST https://iarepo.com/api/track.php \
     -d '{"resource_id":1,"vid":"0123456789abcdef0123456789abcdef","surface":"detail","engaged_secs":0,"interacted":0}'
#    Espera: {"ok":true,...}
```

Luego abre `/resource/<id>` en el navegador y comprueba que:

- el número de **Vistas** sube en **uno** (no en dos ni en cada recarga: recargar el
  mismo día **no** debe volver a subirlo — ésa es la deduplicación),
- pasar el ratón por encima muestra el desglose «visitas únicas · histórico anterior»,
- `localStorage.getItem('iarepo_vid')` devuelve 32 caracteres hex.

**Y una comprobación de privacidad que conviene hacer una vez**, porque es lo que se
promete en `legal/terms.php`:

```sql
-- No debe existir ninguna columna de red en la tabla de visitas
SHOW COLUMNS FROM resource_views;
-- Y a los 3 días no debe quedar la sal del primer día
SELECT view_day FROM view_salts ORDER BY view_day;
```

### 10.5.3 Aplicar la migración 013 (linaje de versiones)

⚠️ **Después del push**, como las anteriores. Durante la ventana el panel de versiones no
aparece (hay `try/catch` que degrada a lista vacía) y la ficha muestra 0 versiones. No se
rompe nada, pero no funciona.

A diferencia de las otras dos, ésta **modifica filas existentes**: rellena `root_id` en
todo el catálogo. Es idempotente y no toca ningún título, pero conviene mirar el resultado.

```bash
# 1. Probarla contra MariaDB real ANTES. Aquí ya se cazó un ERROR 1064 en la
#    propia migración que habría parado el ALTER a medias en el servidor.
make integration        # tests/integration/fork_lineage_db_test.php

# 2. En el servidor
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

# ⛔ Sitúate por deploy_version.txt, NO por `find -name .env.php`, que devuelve
#    Campus. Ver §4.1: aplicar esto en Campus toca la BD equivocada sin avisar.
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
if [ ! -f deploy_version.txt ] || [ ! -f setup/run_migration.php ]; then
  echo "⛔ PARA: esto no es el doc root de iarepo. No se ha ejecutado nada."
else
  php setup/run_migration.php setup/migration_013_fork_lineage.sql
fi
```

**Verificar el backfill** — ninguna de estas dos consultas debe devolver filas:

```sql
-- (a) Nadie sin raíz asignada
SELECT COUNT(*) FROM resources WHERE root_id IS NULL;

-- (b) Ninguna raíz apunta a un recurso que a su vez es un fork.
--     Si sale algo, el aplanado se quedó corto: hay linajes de más de 5
--     niveles y hay que repetir el UPDATE ... JOIN alguna vez más.
SELECT r.id, r.root_id FROM resources r
  JOIN resources p ON r.root_id = p.id
 WHERE p.fork_of IS NOT NULL;
```

**Verificar en el navegador**, ya con el código arriba:

- En un recurso que tenga forks públicos, aparece el panel **«Otras versiones»** con
  autor y fecha, y el contador de la ficha dice **Versiones** (no «Forks»).
- Abriendo una de esas versiones, el panel muestra el **original** arriba y marcado.
- Como autor del original, sale **«Recomendar esta versión»**; al pulsarlo la versión
  sube al principio con **★**.
- Como **otra persona**, ese botón no existe — y lanzando el `POST` a mano debe responder
  **403**:

```bash
curl -s -X POST 'https://iarepo.com/api/resources.php?action=recommend&id=<ID_DE_UN_FORK>'
#   Espera 401 sin sesión, o 403 si la sesión no es la del autor del original
```

**Nota sobre los títulos:** los forks ya creados conservan el prefijo `Fork: ` porque son
contenido de usuario y la migración no los toca. Si quieres limpiarlos, es una decisión
tuya y va aparte:

```sql
-- OPCIONAL, y sólo si estás de acuerdo. Repásalo con un SELECT antes.
SELECT id, title FROM resources WHERE title LIKE 'Fork: %';
-- UPDATE resources SET title = TRIM(SUBSTRING(title, 7)) WHERE title LIKE 'Fork: %';
```

### 10.5.4 Aplicar la migración 014 (check de comprensión)

⚠️ **Después del push**, como las otras tres. Durante la ventana el panel del autor no
muestra el bloque «¿Les quedó claro?» (hay `try/catch` que degrada) y el prompt devuelve
500 sin romper ninguna página.

**Depende de la 012**: la puerta que decide quién puede contestar consulta
`resource_views`. Aplícalas en orden.

```bash
make integration        # tests/integration/comprehension_db_test.php

ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

# ⛔ Sitúate por deploy_version.txt, NO por `find -name .env.php`, que devuelve
#    Campus. Ver §4.1: aplicar esto en Campus toca la BD equivocada sin avisar.
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
if [ ! -f deploy_version.txt ] || [ ! -f setup/run_migration.php ]; then
  echo "⛔ PARA: esto no es el doc root de iarepo. No se ha ejecutado nada."
else
  php setup/run_migration.php setup/migration_014_comprehension.sql
fi
```

**Verificar la puerta**, que es lo único que impide envenenar el dato. Sin haber usado el
recurso, el `POST` a mano tiene que dar **403**:

```bash
curl -s -X POST https://iarepo.com/api/feedback.php \
     -d '{"resource_id":1,"vid":"0123456789abcdef0123456789abcdef","answer":"perdido"}'
#    Espera: {"ok":false,...,"code":"NOT_ENGAGED"}
```

**Verificar el camino normal:** abre un recurso, interactúa con él y **déjalo abierto y
visible 3 minutos**. Debe aparecer abajo a la derecha «¿Te quedó claro este recurso?» con
tres botones. Al contestar, el bloque **«¿Les quedó claro?»** aparece en el dashboard del
autor de ese recurso — **y en ningún sitio público**.

⚠️ **Comprobación de privacidad**, porque es lo que promete `legal/terms.php` §10.2:

```sql
-- No debe existir NINGUNA columna con identidad ni texto libre
SHOW COLUMNS FROM resource_comprehension;
--   Esperado: resource_id, viewer_key, view_day, answer, created_at, updated_at
--   Si aparece un user_id, un nombre o un VARCHAR de comentarios, algo se coló:
--   dejaría de ser un agregado anónimo y pasaría a ser un registro nominal de
--   qué menor no entendió qué.
```

### 10.6 Reactivar el cron de `link_check`

**Lleva parado desde 2026-05-30.** 66 días sin que nadie lo supiera: no falló, simplemente
dejó de ser invocado. Los latidos hacen que se **vea**; no lo arreglan.

En **cron-job.org** (o el scheduler que estés usando), con el `CRON_SECRET` de `.env.php`:

```
https://iarepo.com/cron/run.php?job=link_check&token=<CRON_SECRET>     cada 6 h
https://iarepo.com/cron/run.php?job=moderation&token=<CRON_SECRET>     cada 2 min
```

Los periodos tienen que coincidir con `IAREPO_JOB_PERIODS` (`cron/run.php`:
`link_check` 21600 s, `moderation` 120 s) y con lo que siembra la migración 010. **Si
cambias la planificación real, cambia también la constante**, o el smoke marcará como
muerto un job que está vivo (o al revés, que es peor).

**Verificar:** dispara el job a mano una vez y comprueba que late.

```bash
curl -s 'https://iarepo.com/cron/run.php?job=link_check&token=<CRON_SECRET>'
curl -s https://iarepo.com/api/health.php     # crons.link_check.age_seconds ya no es null
```

### 10.7 ~~Instalar el cron del backup~~ — **HECHO** [V 2026-08-06]

✅ Ya está instalado y ha corrido (ver §8.1). Lo que sigue se conserva como referencia
para reinstalarlo o para montarlo en otro servidor.

⚠️ **Lo que sigue pendiente es la PRUEBA DE RESTAURACIÓN periódica** (final de esta
sección): un backup que nunca se ha restaurado es una hipótesis, no un backup.

#### Procedimiento original

⚠️ **En este hosting NO existe el comando `crontab`.** Se hace por hPanel → Cron Jobs.

```bash
# 1. En el servidor: colocar el script en su propia carpeta
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>
mkdir -p ~/iarepo-backups/db
cp <DOC_ROOT>/setup/tools/backup_db.sh ~/iarepo-backups/run_backup.sh
chmod +x ~/iarepo-backups/run_backup.sh

# 2. PRIMERA CORRIDA A MANO. No programes nada que no hayas visto funcionar.
~/iarepo-backups/run_backup.sh
#    Espera: "✅ OK · <tamaño> · N/N tablas · M sentencias INSERT"
#    Si dice "❌ ABORTO: DB_NAME=… no parece la BD de iarepo" → estás en el sitio
#    equivocado o IAREPO_DOCROOT apunta mal.

# 3. Comprobar el resultado
ls -la ~/iarepo-backups/db/$(date +%F)/
zcat ~/iarepo-backups/db/$(date +%F)/resources.sql.gz | tail -3   # '-- Dump completed'
```

**3. En hPanel → Cron Jobs**, diario a las 04:15 (Campus corre a las 03:00, así que no
compiten por el mismo minuto):

```
15 4 * * *  $HOME/iarepo-backups/run_backup.sh >> $HOME/iarepo-backups/backup.log 2>&1
```

Sustituye `$HOME` por la ruta absoluta si el panel la exige. **No la escribas aquí**: este
fichero es público.

**Verificar al día siguiente:** que exista `~/iarepo-backups/db/<fecha de hoy>/` y que
`backup.log` termine en `🏁 Backup completo`.

**Y una vez al trimestre, prueba la RESTAURACIÓN**, no solo el dump: cárgalo en la BD
efímera de Docker y arranca la app contra ella. Un backup que nunca se ha restaurado es
una hipótesis.

### 10.8 Verificar todo, en este orden

```bash
sleep 5

# 1. ¿Está viva, y sirviendo TU commit?
curl -s https://iarepo.com/api/health.php
git rev-parse --short HEAD                 # debe coincidir con el campo 'commit'

# 2. Smoke completo
bash quality/smoke_test.sh                 # 0 = limpia · 1 = FAIL · 2 = no concluyente
```

**Qué debe haber cambiado respecto a antes del push:**

| Check | Antes | Después |
|---|---|---|
| Fallos con etiqueta `[buscador]` | varios | **cero** — son exactamente la regresión que arregla este push |
| `[despliegue]` commit vivo | `commit=null` | tu SHA |
| `[crons]` latidos | INDETERMINADO / rojo | verde tras §10.5 + §10.6 |
| `/tests/…`, `/quality/…`, `/docs/…` | INDETERMINADO (404) | **PASS (403)** |

Cualquier FAIL **sin** la etiqueta `[buscador]` es una regresión nueva. Y si alguno de los
ficheros de desarrollo sale **200**, LiteSpeed no está aplicando las reglas nuevas y hay
ficheros de desarrollo servidos: FAIL real, no cosmético.

**El check crítico, el que decide si hay rollback:**

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'https://iarepo.com/api/resources.php?search=pH'
curl -s -o /dev/null -w '%{http_code}\n' 'https://iarepo.com/api/resources.php?search=ondas'
```

- **200 los dos** → la MariaDB de producción acepta el `REGEXP`. Todo bien.
- **500** → no lo acepta (`AGENTS.md` §7.4.1). **Rollback:** en `shared/search.php`,
  `iarepo_term_condition()` → `if ($word) {` por `if (false) {`; y si el 500 también
  aparece en `?search=ondas`, además `iarepo_syn_condition()` debe devolver `[null, []]`
  incondicionalmente. Empuja. Se pierde precisión y sinónimos, pero el sitio queda vivo.

**Y a ojo, en el navegador** (nada de esto lo cubre ningún test): la portada en móvil, que
la barra de búsqueda pegajosa no tape la primera fila, que Tab llegue a las tarjetas, y
**recarga forzada** para descartar la caché del service worker.

### 10.9 Si algo sale mal

- **El sitio devuelve 500** → §7.2. Si es en **todas** las rutas y acabas de tocar
  `.htaccess`, sospecha de un bloque `<Directory>` (§8.4).
- **Hay que revertir** → §7.1: `git revert`, **nunca `push --force`** de un SHA viejo.
- **El deploy "no se ve"** → §7.3, y ahora hay una respuesta directa: compara el `commit`
  de `health.php` con tu HEAD.

### 10.10 Infraestructura que sigue pendiente (por orden de dolor)

Detalle completo en §8; aquí solo la acción y su verificación.

| # | Tarea | Acción | Verificar después |
|---|---|---|---|
| 1 | **[P0] `schema_baseline.sql`** (§8.2) | `mysqldump --no-data --skip-add-drop-table --skip-comments <DB_NAME> > setup/schema_baseline.sql` y commitearlo | Es la única forma de comparar prod columna a columna con lo que sale de `setup/`, que hoy es una reconstrucción por inferencia |
| 2 | **[P1] SPF y DMARC** (§8.3) | Crear los dos TXT de §8.3 en el DNS de `iarepo.com`. **Confirma antes con soporte** si Hostinger emite desde un relay propio: un SPF mal puesto empeora la entregabilidad | `dig +short TXT iarepo.com` y `dig +short TXT _dmarc.iarepo.com` dejan de estar vacíos. Luego, un correo de prueba a Gmail que llegue a bandeja |
| 3 | **[P1] ENUM de `moderation_status`** (§10.11) | Solo **antes** de encender `OPEN_REGISTRATION` | `SHOW COLUMNS FROM resources LIKE 'moderation_status'` incluye `pending_review` |
| 4 | **[P2] Staging** (§8.6) | Después de 1. Rama `dev` + segundo bare + doc root aparte | Es lo **único** que permite probar `.htaccess` bajo LiteSpeed antes de que sea producción. Ojo a los tres riesgos de §8.6: `JWT_SECRET` distinto, la regex de CORS que ya deja pasar cualquier subdominio, y no apuntar staging a la BD de producción |

### 10.11 Las otras migraciones (**opcional en este push**, y es una excepción)

La regla de oro de §6 dice que migración y código no van en el mismo push. **Para
`migration_000` y `migration_002` no aplica, y está comprobado:** el código nuevo no exige
ni una columna más que el que ya está en producción, y las dos son **no-op** contra prod
(declaran columnas que allí ya existen, todo con `IF NOT EXISTS`). Es decir: **puedes
empujar §10.4 sin correr nada**.

Correrlas sirve para dejar producción y repo alineados de cara al día que haya que
restaurar un backup:

```bash
ssh -p <SSH_PORT> <SSH_USER>@<SSH_HOST>

# ⛔ Sitúate por deploy_version.txt, NO por `find -name .env.php`, que devuelve
#    Campus. Ver §4.1: aplicar esto en Campus toca la BD equivocada sin avisar.
cd "$(dirname "$(find ~ -maxdepth 6 -name deploy_version.txt 2>/dev/null | head -1)")"
if [ ! -f deploy_version.txt ] || [ ! -f setup/run_migration.php ]; then
  echo "⛔ PARA: esto no es el doc root de iarepo. No se ha ejecutado nada."
else
  php setup/run_migration.php setup/migration_000_prod_baseline.sql
  php setup/run_migration.php setup/migration_002_moderation.sql
fi
```

**Verificar después:** `curl -s https://iarepo.com/api/health.php` sigue dando
`"db":"connected"`, y `bash quality/smoke_test.sh` sigue limpio.

⚠️ **Lo que estas migraciones NO arreglan en producción:** la ampliación del `ENUM` de
`moderation_status` viaja dentro de un `ADD COLUMN IF NOT EXISTS`, y como la columna ya
existe allí, es no-op. Solo importa el día que enciendas `OPEN_REGISTRATION`. **Antes de
encenderlo**, comprueba el tipo real y amplíalo a mano si hace falta:

```sql
SHOW COLUMNS FROM resources LIKE 'moderation_status';
-- si NO aparece pending_review:
ALTER TABLE resources MODIFY COLUMN moderation_status
    ENUM('approved','under_review','rejected','pending_review') DEFAULT 'approved';
```

No se dejó como sentencia viva a propósito: si el ENUM real de prod fuese **más** ancho,
un `MODIFY` lo estrecharía y truncaría datos.

⚠️ **`migration_007` y `migration_008` NO son idempotentes** (`ADD COLUMN` sin
`IF NOT EXISTS`; G8 lo marca en aviso). Como no hay tabla de migraciones aplicadas, si las
reejecutas fallarán en vez de no hacer nada. No las corras "por si acaso".


---

## 11. Lecciones aprendidas

Tres cosas que costaron descubrir, que no se ven leyendo el código, y que no deben volver
a perderse. La versión larga está en `AGENTS.md` §15; aquí está lo operativo.

### 11.1 Un push que no despliega y no avisa

El hook `post-receive` del servidor tenía permisos **644**. **Git ignora los hooks no
ejecutables en silencio**: no hay error, no hay aviso, el push sale bien. Estuvo así **un
mes**, después de la migración de servidor del 2026-07-13, que se llevó por delante el bit
de ejecución. Durante ese mes producción sirvió código viejo, `git log` decía que todo
estaba desplegado, y el smoke test daba **44 checks en verde** contra la versión antigua —
porque comprobaba que el sitio funciona, no que sirva lo que tienes delante.

**Qué hacer siempre:**

```bash
# Tras cualquier tocamiento del servidor o del hook:
ls -l <BARE_REPO>/hooks/post-receive     # DEBE ser -rwxr-xr-x
# Y tras cualquier push:
curl -s https://iarepo.com/api/health.php | grep commit
git rev-parse --short HEAD               # tienen que coincidir
```

**La regla:** *un canal de despliegue que solo puede fallar en silencio no es un canal de
despliegue.* No des por desplegado nada que no te haya devuelto su SHA. Y desconfía de un
verde que no distingue "funciona" de "es lo que empujé": los 44 checks no mentían, es que
no estaban respondiendo a esa pregunta.

### 11.2 Una credencial publicada que un guard mal calibrado no vio

`setup/seed_resources.php` tuvo la **contraseña real de la BD de producción en claro**
durante meses, con el guard G4 en verde todo el tiempo. No fue un bug: la regla exigía que
la palabra clave (`DB_PASS`) estuviera pegada al valor, y la credencial era **posicional**
—`new PDO($dsn, "usuario", "contraseña")`—, sin ninguna palabra clave que la delatara. El
guard hacía exactamente lo que se le pidió; lo que se le pidió no cubría el caso realista.

Y como el push publica por **dos** vías (producción y GitHub público), salió por las dos, y
git conserva el objeto aunque se revierta.

**Qué hacer siempre:**

- **Antes de confiar en un guard, escribe el caso que quieres que atrape y comprueba que
  se pone rojo.** Un verde no es evidencia de ausencia.
- **Revisa el diff a ojo**, no solo con G4 (`git diff --cached`). Está en la checklist de
  §2 porque ya falló una vez.
- **Sacar el secreto del working tree NO es rotarlo.** Son dos acciones y la segunda es la
  que cierra la exposición: §0.1.
- Las listas escritas a mano caducan. El mismo error de clase tumbó la detección de deriva
  de esquema (`iframe_blocked`): un guard basado en una lista manual está verde hasta el
  día en que alguien añade algo, y ese día no avisa.

### 11.3 `lang` no es de fiar

La columna `resources.lang` dice `es` en 371 filas y `en` en 192. Entre los títulos
marcados **`es`**, los términos más frecuentes son `mechanics`, `electromagnetism`,
`waves`, `motion`, `quantum`, `chemistry`. Los tags están duplicados por idioma
(`simulation` 188 / `simulación` 96). Y `subject_area` está normalizado **a inglés**: por
eso buscar "biología" devolvía **cero** en un catálogo con 37 recursos de biología.

**Qué hacer siempre:**

- **No filtres por `lang` para resolver un problema de idioma.** La solución fue expandir
  la **consulta** con el diccionario de sinónimos (`AGENTS.md` §7.3), no acotar el
  conjunto. El filtro `?lang=` de la API sigue existiendo y sigue siendo tan poco fiable
  como la columna.
- **Antes de añadir una `<option>` a un filtro, consulta la BD.** Un valor que el catálogo
  no tiene (`lang=pt`) produce una opción que siempre devuelve cero, y eso parece un bug
  del buscador.
- Lo mismo vale para `use_count`, que es **0 en todo el catálogo**: `sort=popular` ordena
  por un campo vacío y devuelve un orden arbitrario.

**La regla:** los campos que rellena un humano —o un seed de hace meses— son una **pista**,
no un dato. Cualquier lógica de producto que dependa de ellos hereda su fiabilidad.
Mídela antes de construir encima.
