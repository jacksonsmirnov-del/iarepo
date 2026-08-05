# CLAUDE.md — iarepo.com

> **Este es el único documento que se carga solo en cada sesión de Claude Code.**
> Contiene lo mínimo para no romper producción ni perder el tiempo. Nada más.
>
> - **`AGENTS.md`** (raíz) — referencia profunda: arquitectura, esquema, API, buscador,
>   despliegue, guards, lecciones aprendidas (§15). NO se carga sola: ábrela para el
>   detalle. Es también la que leen los agentes de la convención AGENTS.md.
> - **`docs/RUNBOOK.md`** — procedimientos copiables: **§0 rotar la contraseña**, deploy,
>   verificación, rollback, migraciones, "el sitio da 500". **§10 es lo que solo puede
>   hacer el mantenedor.**
>
> Los tres viajan en git y **se publican** (ver §3). No escribas secretos en ellos.

---

## 1. Qué es esto

iarepo.com: repositorio público de recursos educativos interactivos ("un GitHub para
profesores"). Cualquier profesor sube/busca/forkea recursos; Campus (claseprivada.com)
los consume vía API con JWT. Alias activo: `resources.claseprivada.com`.

**Stack: PHP 8 + MySQL/MariaDB + Vanilla JS. CERO dependencias.** Sin Composer, npm,
frameworks, CDNs ni build step. Si necesitas una librería, se auto-aloja en `assets/`.

---

## 2. ⛔ Reglas que rompen producción en silencio

Estas cinco no dan error visible. Rompen la página, se despliegan, y nadie se entera
hasta que un usuario lo reporta.

1. **NUNCA `require` de `shared/helpers.php` en una página que emite HTML.** Arrastra
   `shared/error_handler.php`, cuyos handlers hacen `echo json_encode(...)` + `exit(1)`:
   ante cualquier error la página sale a medio renderizar con un blob JSON incrustado. En
   su lugar: `h()` local + `shared/error_tracker.php` (ejemplos: `index.php:16`,
   `404.php:9`). En `api/*.php` sí va, ahí el JSON es la respuesta correcta. *Las 7
   páginas heredadas que lo violan están en `quality/baseline_html_helpers.txt`; esa lista
   solo puede encoger.*
2. **Nunca `?>` dentro de un comentario PHP de línea** (`//`, `#`). Cierra el modo PHP y el
   fichero imprime su propio código fuente. **`php -l` NO lo detecta**; sí el guard G2
   (`php quality/lib/analyze.php close-tag <fichero>`). Los comentarios de bloque
   `/* ?> */` **sí** son seguros (verificado en PHP 8.3).
3. **Toda cadena visible al usuario va en `t('texto en español')`** + traducción en
   `shared/i18n_en.php` (las claves SON el español). En JS, `const T = {...}` vía
   `<?= json_encode(t('...')) ?>`. Excepciones: `quality/i18n_ignore.txt`.
4. **Los iconos lucide están AUTO-ALOJADOS** (`assets/js/lucide.min.js`). Jamás volver a un
   CDN: cualquier host externo nuevo en `src=`/`href=` hace fallar G3; lista blanca en
   `quality/allowed_hosts.txt`.
5. **`.env.php` no está en git.** No lo edites, no lo imprimas, no lo commitees, no
   reconstruyas su contenido en un documento. La plantilla es `.env.php.example`.

---

## 3. ⛔ El repo es PÚBLICO. Desplegar = publicar (y no hay staging)

```
git push origin main
   ├─→ hook post-receive del servidor → checkout -f al doc root  = PRODUCCIÓN EN VIVO
   └─→ github.com/jacksonsmirnov-del/iarepo                      = REPO PÚBLICO
```

`origin` tiene **dos** pushURL (`git config --get-all remote.origin.pushurl`): un solo
push despliega y publica a la vez, sin staging ni revisión intermedia.
`https://iarepo.com/AGENTS.md` responde **200** [V 2026-08-04]: los `.md` de la raíz son
legibles desde internet **a posta**, y esto (`CLAUDE.md`) es uno de ellos.

- **Nada de credenciales, IPs con puerto+usuario, nombres de BD ni rutas de servidor en
  ficheros versionados** — tampoco en comentarios ni en estos documentos. Las coordenadas
  viven en `.env.php` y `setup/tools/deploy.env` (los dos fuera de git) y en la memoria
  personal del usuario. Usa marcadores: `<SSH_HOST>`, `<DOC_ROOT>`, `<DB_NAME>`.
- **Un secreto commiteado se filtra por las dos vías a la vez y git conserva el objeto
  aunque se revierta.** Ya pasó: la contraseña real de la BD estuvo en
  `setup/seed_resources.php` (commit `5b6c1e6`) y sigue en el historial público. Sacarla
  del working tree **no** es rotarla (`docs/RUNBOOK.md §0`).
- **Tú (agente) no haces `commit`, `push`, `checkout`, `stash` ni `reset`.**
- **Sí hay CI** desde 2026-08 (`.github/workflows/ci.yml`, `make check` + integración en
  cada push a cualquier rama). **`--no-verify` no la salta**; al hook local sí.

---

## 4. El gate: qué correr antes de que nada salga

```bash
make check     # = lint + guards + test   (~1,5 s, sin red)  ← lo que exige el hook
```

| Comando | Qué hace | Cuándo |
|---|---|---|
| `make lint` | `php -l` + `node --check` sobre ficheros **trackeados** | siempre |
| `make guards` | `quality/guards.sh` — 9 chequeos estáticos (G1-G9), ~0,5 s | siempre |
| `make test` | `php tests/run.php` — unitarios, sin BD, < 5 s | siempre |
| `make integration` | `--integration` — BD real (MariaDB en Docker) | si tocas SQL/búsqueda |
| `make smoke` | `quality/smoke_test.sh` contra **producción** | **DESPUÉS** del deploy |
| `make hooks` | instala el gate local (`git config core.hooksPath .githooks`) | una vez por clon |

`test` e `integration` están hoy **las dos en verde, cero rojos** [V 2026-08-04; córrelas,
no cites la cifra]. `make smoke` **no es un gate**: cuando falla, el fallo ya está en vivo.
Tres estados (PASS / FAIL / **INDETERMINADO**) y tres salidas: `0` limpia · `1` FAIL real ·
`2` checks que no llegaron a ejecutarse (`429`, o 404 de algo sin desplegar) → **no valida
el deploy**. Ojo con `&&`/`set -e`.

⚠️ `make lint` solo mira ficheros **trackeados** (`git ls-files`): uno nuevo sin `git add`
no se lintea; `guards.sh --changed` sí incluye untracked.
⚠️ `git push --no-verify` salta el gate **local** entero (la CI no). Único uso legítimo: el
gate está roto y empujas su arreglo, o reviertes un deploy malo.

Detalles: `quality/guards.sh --help`, `php tests/run.php --help`, `tests/README.md`.

---

## 5. Mapa mínimo

```
index.php          Landing + buscador (todo el JS del catálogo vive aquí, inline)
api/*.php          15 endpoints REST. helpers.php SÍ va aquí.  (ls -1 api/*.php)
shared/            auth jwt db cors helpers error_handler error_tracker i18n i18n_en
                   mailer notify moderation similarity · search + search_synonyms
                   (diccionario ES↔EN, datos puros)
resource/ viewer/ dashboard/ profile/ collection/ favorites/ auth/ admin/ legal/
404.php unsubscribe.php sitemap.php sw.js manifest.webmanifest
setup/             schema*.sql + migration_0NN_*.sql + seed_* (orden ALFABÉTICO = orden
                   de aplicación; el 000 es el baseline de prod, AGENTS.md §2.4)
                   hooks/post-receive = copia VERSIONADA del hook de deploy
                   tools/ = generate-thumbnails.sh, backup_db.sh, deploy.env.example
cron/run.php       jobs link_check y moderation (CRON_SECRET). Cada uno deja un LATIDO
                   en cron_heartbeats (migration_010)
quality/           guards.sh · lib/analyze.php · smoke_test.sh · verify_deploy.sh
                   baselines *.txt (allowed_hosts · baseline_html_helpers ·
                   i18n_ignore · required_tests)
tests/             run.php + unit/ + integration/ + fixtures/
.github/workflows/ci.yml   la CI, segunda red del gate
docs/RUNBOOK.md    procedimientos + §10: lo que solo puede hacer el mantenedor
.githooks/pre-push el gate local  ·  Makefile  ·  .htaccess (rutas + bloqueos)
```

`.htaccess` bloquea `setup/`, `shared/`, `admin/` (salvo `errors.php`/`create.php`),
`*.sql`, `.env.php` y —desde 2026-08-04, aún sin desplegar— `tests/`, `quality/`, `docs/`,
`Makefile` y todo lo que empiece por `.git`. `cron/` **no** se bloquea (lo llama un
scheduler externo); los `.md` de la raíz tampoco.

⚠️ **Nunca metas un bloque `<Directory>` en `.htaccess`**: no es sintaxis válida ahí y
Apache 2.4 devuelve **500 en TODAS las rutas del sitio** (verificado en httpd real). Había
uno para `setup/`; hoy es `RewriteRule ^setup(/|$) - [F,L]`. Se probó en Apache, **no en
LiteSpeed**, que es lo que corre producción.

**Fuera de git, sobreviven al `checkout -f`:** `.env.php`, `thumbnails/`,
`deploy_version.txt` (lo escribe el hook) y `setup/tools/deploy.env` (coordenadas SSH).

---

## 6. Cinco trampas del repo

1. **Favoritos ≠ colecciones. NO los unifiques.** Dos botones de "guardar" en la misma
   página **a propósito**: `resource_favorites` es guardado privado de un clic (gancho de
   captación de estudiantes), `collections` es curaduría pública. `AGENTS.md` §5.3.
2. **Existe el rol `student`** (ENUM desde `migration_009`), con enrutado propio: no
   tiene dashboard (lo redirigen a `/favorites/`) y no ve Fork. Ojo al tocar nav.
3. **Hay un service worker** (`sw.js`, registrado por `assets/js/pwa.js`). Si un cambio
   "no aparece" en el navegador, es la caché del SW, no tu código.
4. **`shared/i18n.php::lang()` cachea el idioma en un `static` irreversible**: un
   proceso PHP solo puede hablar un idioma. Importa al escribir tests.
5. **`resources.lang` NO es de fiar** (§7) y `use_count` es 0 en todo el catálogo. No
   construyas lógica de producto encima sin medir primero.

---

## 7. El buscador

Único punto de búsqueda de toda la app: `index.php` no consulta la BD, delega por
`fetch` a `GET /api/resources.php?search=`.

- El SQL se construye en **`shared/search.php`** (funciones puras; `api/resources.php:142`
  llama a `iarepo_build_search()`). Nada del input del usuario llega crudo a `AGAINST()`.
  Su **único** `require` permitido es `shared/search_synonyms.php` (datos, carga perezosa,
  degrada si falta). `helpers.php` jamás.
- **Cada término del usuario es un GRUPO** `[exacto, sinónimo…]`: AND entre grupos, OR
  dentro (`'ondas'` → `+(onda* wave*)`). El catálogo es bilingüe y **`lang` NO es de
  fiar**, así que la única salida es expandir la **consulta**: "biología" devolvía 0 con
  37 recursos de biología en el catálogo.
- Híbrido: brazo **fulltext** (`idx_search`) **OR** un segundo brazo que alcanza
  `source_name`, `subject_area`, autor y tags. `mode` ∈ `none|like|hybrid`; **`fulltext`
  no existe**, el segundo brazo está siempre (`innodb_ft_min_token_size = 3` es global y
  MariaDB no tiene parser NGRAM).
- ⚠️ **El segundo brazo NO es uniforme: hay TRES formas.** Es lo que más se presta a
  "simplificar" mal; unificarlas rompe cosas medidas contra el catálogo real (546 filas):

  | Miembro | Forma | Si lo unificas |
  |---|---|---|
  | usuario, ≥3 chars | `LIKE '%x%'` **subcadena** | pierdes `matem`→"Matemáticas" y los acentos por collation |
  | usuario, <3 chars | **palabra completa** (`REGEXP`) | `'C++'→'c'` vuelve a casar 546 de 546 |
  | **sinónimo** | **principio de palabra** (`REGEXP`) | `ion` casaba 439 de 546 ("Simulations", "Motion") |

- **Sigue vivo:** el término del usuario de 3+ chars es subcadena, así que **`ADN` casa
  "Chla*dn*i"**. Es una decisión, no un olvido.
- Relevancia: sumando fulltext **acotado** (`LEAST(MATCH*2, 24)`, calibrado midiendo).
  **El exacto gana al sinónimo por construcción** (título 10+12+8=30 contra 5).
- **Nunca emitas `+<stopword>*` ni `+<2 chars>*`** (anula la consulta fulltext entera), ni
  metas en el diccionario un miembro de <3 chars (`+(onda* ph*)` arrastra "Photosynthesis").
  Tope `IAREPO_MAX_SYNONYMS = 6`, con un test que se pone rojo si el diccionario se acerca.
- ⚠️ Ese `REGEXP` **no está probado contra la MariaDB de producción**, y con los sinónimos
  el riesgo ya no son solo los tokens cortos: si no lo soportara, **casi toda búsqueda**
  daría 500. Tras el deploy, `?search=pH` debe dar 200 (`AGENTS.md` §7.4.1: `curl` +
  rollback).

Detalle: **`AGENTS.md` §7** · orden por relevancia en la UI: **§6.7**. Si tocas
`search.php` **o el diccionario**: `php tests/run.php --filter=search` + `make integration`.

---

## 8. Estado y frescura

Ningún número de catálogo, tablas o endpoints está fijado en estos documentos: caducan.

```bash
curl -s https://iarepo.com/api/health.php   # commit VIVO, recursos, BD, latidos de cron
ls -1 api/*.php | wc -l                     # endpoints
git log -1 --date=short --format='%ad %s' -- CLAUDE.md AGENTS.md docs/
```

**`api/health.php` publica `commit` desde 2026-08:** ya se sabe qué está vivo sin SSH. Si
viene `null`, el hook no está instalado o no pudo escribir (`AGENTS.md` §8.5). `version`
es una constante literal y **no** dice nada.

**Deuda abierta hoy (2026-08-04) — `docs/RUNBOOK.md §10` es la lista de lo que falta y
solo puede hacer el mantenedor:**

- 🔴 **La contraseña de la BD de producción está en el historial público** (commit
  `5b6c1e6`). **Rotarla es el paso 0 y no depende del deploy** (`docs/RUNBOOK.md §0`).
- **La tanda del 2026-08-04 está SIN DESPLEGAR** (buscador con sinónimos, hook
  verificable, heartbeats, bloqueos de `.htaccess`, `tests/`, `quality/`, `docs/`):
  producción sirve el código viejo. **No supongas que lo que lees en el repo es lo que
  responde `iarepo.com`** — pregúntale a `health.php` por su `commit`.
- **El cron `link_check` lleva parado desde 2026-05-30** (66 días sin que nadie lo notara).
  Reactivarlo en cron-job.org; los latidos solo hacen que se **vea**.
- El backup existe y está ensayado con restauración real (`setup/tools/backup_db.sh`);
  falta **instalarlo en el cron de hPanel**. Lista completa: `AGENTS.md` §13.

⚠️ **Este repo se mueve rápido y hay varios agentes trabajando a la vez.** No des por
buena ninguna afirmación de estado ("está en rojo", "faltan N cosas") sin comprobarla:
`make check` y `git status --short` tardan dos segundos y mandan sobre el documento.
