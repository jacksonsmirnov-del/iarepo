# AGENTS.md — iarepo.com · Referencia técnica

> **Relación con los otros dos documentos:**
> - **`CLAUDE.md`** (raíz) es lo que Claude Code carga automáticamente en cada sesión:
>   reglas críticas, comandos del gate, mapa mínimo. **Léelo primero.** Este fichero
>   NO se carga solo — es el detalle que se abre bajo demanda.
> - **`docs/RUNBOOK.md`** son los procedimientos operativos copiables (deploy,
>   rollback, migraciones, incidentes, infra pendiente).
> - Este fichero es además el que leen los agentes que siguen la convención
>   `AGENTS.md` (Codex y similares). Para ellos, la información de `CLAUDE.md` §2
>   (reglas que rompen producción) está también resumida aquí en §0.2.
>
> **⚠️ ESTE DOCUMENTO ES PÚBLICO.** Verificado 2026-08-04: `https://iarepo.com/AGENTS.md`
> devuelve **HTTP 200** sin autenticación, y `git push origin main` lo publica además en
> `github.com/jacksonsmirnov-del/iarepo`, que es un repo público. Por eso aquí **no hay**
> puerto SSH, usuario del servidor, nombres de BD, contraseñas ni rutas del doc root:
> esas coordenadas viven en `.env.php` (no versionado) y en la memoria personal del
> mantenedor. No las reintroduzcas.

---

## 0. Cómo leer este documento

### 0.1 Qué es dato y qué es supuesto

Cada afirmación operativa lleva marca. La marca es del **contenido**, no del fichero.

- **[V 2026-08-04]** — verificado ese día leyendo el código del repo, ejecutando un
  comando local, o con un GET de lectura a `https://iarepo.com`.
- **[S]** — supuesto razonado, no comprobable desde el entorno del agente (que tiene
  prohibido SSH a producción y escritura contra la BD real). Trátalo como hipótesis.

Cifras que caducan (nº de recursos, de tablas, de endpoints, URLs del sitemap) **no
se fijan** en este documento: se obtienen con los comandos de `CLAUDE.md` §8. Tres
cifras contradictorias en la versión anterior de este fichero eran las tres falsas.

Frescura real del documento:

```bash
git log -1 --date=short --format='%ad %s' -- AGENTS.md CLAUDE.md docs/
```

### 0.2 Resumen de las reglas que rompen producción en silencio

Detalle y ejemplos en `CLAUDE.md` §2. En una línea cada una:

1. `shared/helpers.php` **jamás** en una página que emite HTML (arrastra
   `error_handler.php`, cuyos handlers imprimen JSON y hacen `exit`).
2. Nunca `?>` dentro de un comentario de línea PHP (`//`, `#`). `php -l` no lo ve.
3. Toda cadena visible en `t('español')` + entrada en `shared/i18n_en.php`.
4. Cero CDNs: lucide y todo lo demás, auto-alojado en `assets/`.
5. `.env.php` fuera de git, fuera de los logs, fuera de los documentos.

---

## 1. Qué es iarepo

Repositorio público de recursos educativos interactivos. Cualquier profesor se registra
(Google Sign-In), sube recursos (HTML interactivo, URL, embed, Python, prompt) y los
comparte. Campus (`claseprivada.com`) consume el catálogo por API con JWT firmado con
un secreto compartido.

| | |
|---|---|
| Dominio | `iarepo.com` (principal) · `resources.claseprivada.com` (alias, activo) |
| Stack | PHP 8, MySQL/MariaDB, Vanilla JS. **Cero dependencias**: sin Composer, npm, frameworks ni CDNs |
| Auth | Google Sign-In (sesión PHP) para el frontend · JWT HMAC-SHA256 para Campus |
| Hosting | Hostinger Business **compartido**, DC São Paulo. Coordenadas: no aquí (ver aviso de cabecera) |
| Relación con Campus | Unidireccional: Campus llama a iarepo. iarepo nunca llama a Campus |

**El registro directo NO es futuro: está implementado y en producción** [V 2026-08-04]
— `auth/signin.php`, `auth/google.php`, `auth/onboarding.php`. La versión anterior de
este documento lo describía de tres formas contradictorias en la misma página.

---

## 2. Arquitectura y base de datos

### 2.1 Principio de independencia

iarepo **no tiene foreign keys a Campus**. Toda la identidad del usuario está
**denormalizada**: se copia del JWT a cada fila en el momento de escribir. Si Campus
desaparece, iarepo sigue funcionando.

```sql
author_tenant_id     INT           -- 0 = profesor externo, sin colegio
author_user_id       INT
author_display_name  VARCHAR(150)
author_tenant_name   VARCHAR(150)  -- vacío para externos
```

### 2.2 Las tablas [19 V 2026-08-04 · +3 el 2026-08-06: `resource_views`, `view_salts` (§6.8) y `resource_comprehension` (§6.9)]

Fuente: `CREATE TABLE` en `setup/*.sql`. Recuento vivo (no lo cites, córrelo):

```bash
grep -rhoiE 'CREATE TABLE (IF NOT EXISTS )?`?[a-z_]+' setup/*.sql \
  | awk '{print tolower($NF)}' | tr -d '`' | grep -v '^if$' | sort -u
```

| Tabla | Función | Origen |
|---|---|---|
| `resources` | Contenido principal | `schema.sql` |
| `resource_versions` | Snapshot por cada UPDATE | `schema.sql` |
| `resource_usage` | Tracking (presented, sent, forked, endorsed) | `schema.sql` |
| `resource_suggestions` | Feedback privado entre profesores | `schema.sql` |
| `resource_assignments` | Recursos asignados a aulas | `schema.sql` |
| `resource_tags` | Tags libres (PK `resource_id`+`tag`) | `migration_001` |
| `categories` | Categorías dinámicas | `migration_001` |
| `users` | Cuentas Google Sign-In | `schema_users.sql` |
| `resource_likes` | Likes | `migration_003` |
| `resource_comments` | Comentarios | `migration_003` |
| `collections` / `collection_items` | Colecciones temáticas (curaduría) | `migration_003` |
| `resource_reports` | Reportes de contenido | `migration_002` |
| `api_rate_limits` | Contadores de `rateLimit()` por IP | `migration_005` |
| `client_error_log` | Errores JS del cliente | `migration_006` |
| `notification_log` | Deduplicación de emails (ventana 24 h) | `migration_007` |
| `resource_favorites` | ⭐ guardado privado, 1 clic | `migration_009` |
| `url_blacklist` | Dominios/URLs retirados por el link checker | `migration_url_blacklist` |
| `cron_heartbeats` | 💓 Un latido por job de cron: cuándo corrió, cuánto tardó, si falló | `migration_010` |

### 2.3 Campos relevantes de `resources`

De `schema.sql` más lo que añaden las migraciones:

```sql
code_type   ENUM('html','url','embed','python','prompt','other')  -- 'prompt' lo añade migration_001/003
visibility  ENUM('draft','area','school','community') DEFAULT 'draft'
lang        ENUM('es','en','pt') DEFAULT 'es'        -- migration_001
level       VARCHAR(50) DEFAULT 'general'            -- migration_001. LIBRE, no ENUM (ver §9)
category_id INT NULL                                 -- migration_001, → categories
thumbnail_url, source_prompt, view_count             -- migration_001
subject_area VARCHAR(100), topic_tag VARCHAR(100)
use_count, fork_count, fork_of, current_version, is_active
source_name, source_url, link_status, link_checked_at  -- migration_000_prod_baseline
content_hash, moderation_status                        -- migration_002
iframe_blocked                                         -- migration_000_prod_baseline
FULLTEXT idx_search (title, description, topic_tag)   -- schema.sql:57
```

`subject_area` está normalizado a inglés (Physics, Mathematics, Biology, Chemistry,
Space & Astronomy, Social Studies, Computer Science, Art & Music, Economics, Languages,
Health & PE, General). El reparto por área cambia con el catálogo; consúltalo en la BD,
no aquí.

### 2.4 Deriva de esquema: CERRADA [V 2026-08-04]

Durante meses el repo NO podía reconstruir producción. Ya sí: `make integration`
reconstruye la BD desde `setup/` con **0 errores SQL** y toda la suite de
`tests/integration/schema_drift_test.php` pasa. Compruébalo, no te lo creas:

```bash
make integration                                  # exit 0 y ningún test en rojo
ls -1 setup/migration_*.sql                       # el orden alfabético ES el de aplicación
```

Qué se hizo:

1. **Baseline de producción.** `setup/migration_000_prod_baseline.sql` declara las
   columnas que existían en prod ANTES de la 002 (`source_name`, `source_url`,
   `link_status`, `link_checked_at`, `iframe_blocked`). Va numerado `000` porque se
   aplica el **primero**: la 002 usaba `ADD COLUMN ... AFTER source_name` y MariaDB
   rechaza un `AFTER` sobre columna inexistente (`ERROR 1054`).
2. **La 002 ya no depende del orden.** Se le quitó el `AFTER source_name`
   (`setup/migration_002_moderation.sql`). Es cosmético —fija la POSICIÓN de la
   columna—, así que ninguna migración supone ya que otra corrió antes. Las
   migraciones las lanza un humano por SSH en el orden que le parece.
3. **`iframe_blocked`** la crea el baseline como `TINYINT(1) DEFAULT 0`, que es el tipo
   que exige el código (`cron/run.php:265` pasa `$iframeBlocked ? 1 : 0`).
4. **Hueco del `004`:** se lo quedó `migration_url_blacklist.sql`, que nació sin
   numerar (`git log --diff-filter=A` no registra ningún `migration_004` y las fechas
   encajan). No se renombra: está trackeado y aplicado en producción.

**Sigue pendiente (no bloquea el deploy):**

- **El ENUM real de `moderation_status` en producción no está verificado.** El repo lo
  declara `ENUM('approved','under_review','rejected','pending_review')`, pero la
  ampliación vive dentro de un `ADD COLUMN IF NOT EXISTS`, que en prod es **no-op**
  (la columna ya existe). Hoy no explota porque con `OPEN_REGISTRATION` apagado se
  escribe `'approved'`; el día que se abra el registro, si el ENUM real de prod no
  admite `pending_review`, crear un recurso es `ERROR 1265` → 500. Antes de encender
  el registro: `SHOW COLUMNS FROM resources LIKE 'moderation_status'` y, si hace
  falta, un `MODIFY COLUMN` manual (documentado en `migration_002`). **No** se dejó un
  `MODIFY` vivo: estrecharía el ENUM si el real fuese más ancho.
- **No hay tabla de registro de migraciones**: nadie sabe con certeza qué se corrió en
  producción. Por eso todo el SQL de `setup/` es idempotente. Crear esa tabla exige
  escribir en prod.
- El orden físico de las columnas en una BD reconstruida difiere del de prod (cosmético:
  todo el consumo es `PDO::FETCH_ASSOC`).
- Nadie ha comparado columna a columna la BD reconstruida con producción: haría falta un
  `mysqldump --no-data` de prod (§8.2 del RUNBOOK). Lo que sí está garantizado es lo
  comprobable desde el repo: el esquema que sale de `setup/` soporta **todo** el SQL que
  ejecutan `api/*.php` y `cron/*.php`.

**Cómo se caza ahora una columna nueva sin declarar.** `iframe_blocked` se coló porque el
guard antiguo era una **lista escrita a mano** —esa clase de guard caduca— y porque el
parser solo reconocía columnas con alias (`r.columna`) en `api/resources.php` y
`shared/search.php`; el SQL del cron (`UPDATE resources SET iframe_blocked = ?`) no tiene
alias y `cron/` ni se miraba. El escáner nuevo recorre **todos** los `api/*.php` y
`cron/*.php` y extrae columnas por cuatro vías: alias `r.`, listas de `INSERT`, listas de
`UPDATE … SET`, y sentencias de una sola tabla sin alias. Trocea el SQL con
`token_get_all()` de PHP, no con regex, así que comillas y escapes los delimita el propio
PHP. Hay un **meta-guard** que ancla un testigo de cada una de las cuatro vías, para que
el escáner no pueda quedarse ciego y verde a la vez.

Si añades SQL en un fichero fuera de `api/` o `cron/` (por ejemplo
`setup/cron_link_checker.php` o `viewer/index.php`), **ese escáner no lo mira**: hoy no
hay ninguna columna que se escape solo por ahí, y ampliar `iarepo_drift_files()` es una
línea. Y como el escáner es genérico, una palabra reservada exótica puede dar un falso
positivo; la reparación es añadirla a `IAREPO_DRIFT_KEYWORDS`. Se prefirió eso a relajar
el guard: la suite de integración no entra en `make check` ni en el hook, así que un
falso rojo no bloquea ningún push, y un falso **negativo** es justo como se coló
`iframe_blocked`.

---

## 3. Estructura del código

```
index.php                 Landing + catálogo + buscador. TODO el JS del catálogo es inline aquí.
                          También responde health JSON si Accept: application/json (index.php:51).
sitemap.php               → /sitemap.xml. Home + recursos community aprobados. Las /view/ se
                          excluyen a propósito (evita duplicado de contenido).
404.php  unsubscribe.php  sw.js  manifest.webmanifest  robots.txt  llms.txt
.htaccess                 Rewrites (/view/N, /resource/N, /profile/N, /sitemap.xml), CORS,
                          bloqueos, catch-all a 404.php.

api/                      15 endpoints REST. helpers.php SÍ va aquí. Detalle en §4.
auth/                     google.php (callback OAuth) · signin.php · onboarding.php · logout.php
shared/                   auth.php jwt.php db.php cors.php helpers.php error_handler.php
                          error_tracker.php i18n.php i18n_en.php mailer.php notify.php
                          moderation.php similarity.php search.php
                          search_synonyms.php   ← diccionario ES↔EN, datos puros (§7.3)
resource/index.php        /resource/N — detalle: preview, likes, comentarios, similares,
                          share panel, embed, JSON-LD LearningResource
viewer/index.php          /view/N — iframe sandbox + modo presentación (?mode=present)
dashboard/                index.php (mis recursos + actividad) · editor.php
profile/index.php         /profile/N — perfil público
collection/index.php      /collection/?id=N
favorites/index.php       /favorites/ — ⭐ guardado privado (destino de los estudiantes)
admin/                    create.php · errors.php   (se autoprotegen con ADMIN_PASS, ver §9)
legal/terms.php
assets/                   img/ (logo, iconos PWA, og-default) · js/lucide.min.js · js/pwa.js
cron/run.php              Jobs link_check y moderation, autenticados con CRON_SECRET.
                          Cada job deja un LATIDO en cron_heartbeats (§8.6).
setup/                    schema.sql, schema_users.sql, migration_0NN_*.sql, seed_*,
                          run_migration.php, cron_link_checker.php, cron_moderation.php
setup/hooks/post-receive  Copia VERSIONADA del hook de deploy del servidor (§8.5)
setup/tools/              generate-thumbnails.sh · backup_db.sh · deploy.env.example
quality/                  guards.sh · lib/analyze.php · smoke_test.sh · verify_deploy.sh
                          baselines *.txt
tests/                    run.php · unit/ · integration/ · fixtures/ · README.md
.github/workflows/ci.yml  Segunda red del gate, en GitHub (§8.7)
.githooks/pre-push        Makefile        CLAUDE.md        AGENTS.md        docs/
```

**No están en git** (existen solo en el servidor y sobreviven al `checkout -f`):
`.env.php`, `thumbnails/` (screenshots OG 1200×630, generados en local con Chrome
headless y subidos por SCP), `deploy_version.txt` (lo escribe el hook en cada deploy) y
`setup/tools/deploy.env` (coordenadas SSH; la plantilla versionada es
`deploy.env.example`).

---

## 4. API REST

### 4.1 Los endpoints [17 V 2026-08-06: `ls -1 api/*.php | wc -l` → 17. Nuevos: `track.php` (§6.8) y `feedback.php` (§6.9)]

| Endpoint | Métodos | Auth | Función |
|---|---|---|---|
| `api/resources.php` | GET/POST/PUT/DELETE | GET opcional, resto obligatoria | CRUD, fork (`?action=fork&id=`), versionado, listado con filtros y búsqueda |
| `api/assignments.php` | GET/POST/DELETE | obligatoria + `requireRole` | Recursos asignados a aulas |
| `api/usage.php` | GET/POST | obligatoria | Tracking de uso |
| `api/stats.php` | GET | obligatoria | Métricas |
| `api/suggestions.php` | GET/POST/PUT | obligatoria + `requireRole` | Feedback entre profesores |
| `api/versions.php` | GET | obligatoria | Historial de versiones |
| `api/likes.php` | GET/POST | obligatoria en POST | Like / unlike — **el unlike es el mismo POST (toggle), no un DELETE** |
| `api/comments.php` | GET/POST/DELETE | obligatoria salvo GET | Comentarios |
| `api/collections.php` | GET/POST/PUT/DELETE | obligatoria salvo `GET ?id=` / `?user_id=` | Colecciones (curaduría). El GET **sin** parámetros ("mis colecciones") sí exige auth |
| `api/favorites.php` | GET, POST `?id=` | obligatoria | ⭐ favorito privado — **distinto de colecciones, ver §5.3** |
| `api/notifications.php` | GET, POST | obligatoria | GET = feed + no leídos · POST = marcar visto (`users.notifications_seen_at`) |
| `api/log-error.php` | POST | No | Receptor de errores JS → `client_error_log` |
| `api/check_similarity.php` | POST | obligatoria | Detección de duplicados antes de publicar |
| `api/og-image.php` | GET `?id=` | No | OG image: `thumbnails/og-{id}.png` → fallback GD |
| `api/health.php` | GET | No | 8 campos de contrato + `commit`/`deployed_at`/`crons` — ver §4.5 |

⚠️ **"Auth obligatoria" NO significa "solo JWT"** [V 2026-08-04]. Todos esos endpoints
llaman a `requireAuth()`, y `requireAuth()` → `authenticate()` prueba **primero JWT y
luego sesión** (`shared/auth.php:21-28`). Es decir: un profesor logueado con Google puede
llamar a `api/stats.php` o `api/check_similarity.php` igual que Campus. La columna «Auth»
de la versión anterior de este documento decía «JWT» en seis filas y eso inducía a error.
Los únicos endpoints realmente abiertos son `log-error`, `og-image` y `health`; el único
con auth *opcional* es el GET de `api/resources.php` (usa `authenticate()`, que devuelve
`?array`).

Rutas HTML con rewrite (no son endpoints): `/view/{id}`, `/resource/{id}`,
`/profile/{id}`, `/sitemap.xml`.

### 4.2 Filtros de `GET /api/resources.php` [V 2026-08-04]

| Param | Efecto |
|---|---|
| `search` | Búsqueda (ver §7) |
| `area` | `subject_area` exacto |
| `category` | `category_id` (se ignora si ≤ 0) |
| `lang` | `es` / `en` / `pt` |
| `level` | `primary`, `secondary`, `ib`, `university`, `general` (columna libre, no ENUM) |
| `type` | `code_type` — **no estaba documentado** |
| `tag` | subconsulta a `resource_tags` — **no estaba documentado**; única vía de consultar esa tabla desde la API |
| `author_tenant_id` | Colegio de origen (se ignora si ≤ 0) |
| `visibility` | Nivel de visibilidad |
| `sort` | `recent` (default sin búsqueda), `relevance` (default **con** búsqueda), `popular`, `views`, `title`. Un valor desconocido se trata como **ausente** (cae al default que toque), no como `recent` fijo — ver la tabla de abajo |
| `page` / `limit` | `limit` acotado a 10-100, default 20 |

Todos los filtros pasan por **`iarepo_get_str()`** [V 2026-08-04: `grep -n 'function
iarepo_get_str' api/resources.php` → 585], que devuelve `''` si el parámetro falta **o
llega como array** (`?search[]=x` provocaba `TypeError` → 500). Ojo al comportamiento
corregido: antes se usaba `!empty()`, y como `empty('0')` es `true`, `?search=0` devolvía
el catálogo entero.

⚠️ **El prefijo `iarepo_` no es cosmético.** La función se llamaba `getStr()`. En un
proyecto sin namespaces, un helper global con nombre genérico choca con cualquier
homónimo que entre por otro `require`, y un `Cannot redeclare` es **fatal**: tumba la API
entera, no solo el endpoint. Verifica que no queda ninguna llamada al nombre viejo con
`grep -rn '\bgetStr\b' --include='*.php' .` (hoy solo aparece dentro de un comentario que
explica precisamente esto).

✅ **La divergencia con la UI ante un `sort` inválido está CERRADA** [V 2026-08-04,
`api/resources.php:169-172`]. La regla, en una línea: **un `sort` no reconocido se trata
como AUSENTE, no como una elección explícita del cliente.**

| Petición | Orden aplicado |
|---|---|
| `?sort=title` | `title` — elección válida, se respeta |
| `?search=x` | `relevance` — defecto con búsqueda |
| `?sort=bogus&search=x` | `relevance` — `bogus` ≡ no venía |
| `?sort=bogus` | `recent` — defecto sin búsqueda |

Antes, `bogus` contaba como explícito y caía a `recent` mientras el desplegable de
`index.php` mostraba "Más relevantes": el usuario leía una mentira. **`api/resources.php`
es la pieza que manda** —es la única que ordena filas de verdad y publica el orden
aplicado en `search.sort`—; `index.php` y su JS se limitan a replicar la regla. Los
deep-links `?sort=` con valor válido no cambian en nada. Ningún test fija todavía esta
semántica.

La respuesta del listado incluye un bloque de diagnóstico:

```json
{"ok":true,"resources":[…],"total":N,"page":1,"pages":M,"categories":[…],
 "search":{"mode":"hybrid","terms":["onda"],"sort":"relevance"}}
```

⚠️ **`debug` NO se expone por la API**: el endpoint publica solo `mode`, `terms` y `sort`.
Las claves de diagnóstico del buscador (`ft`, `like`, `short`, `dropped`, `groups`, §7.3)
se miran desde PHP en local, no por HTTP. `terms` son los tokens del usuario, **sin
sinónimos**: es lo que el frontend resalta.

### 4.3 Formato y auth

```php
json_ok(['resource' => $data]);   // {"ok": true,  "resource": {...}}
json_error('Not found', 404);     // {"ok": false, "error": "Not found"}
```

- **JWT** (Campus): `Authorization: Bearer <token>`. `shared/jwt.php` implementa
  HMAC-SHA256 a mano (`jwt_encode`, `jwt_decode`; `base64url_*`).
- **Sesión** (Google Sign-In): cookie PHP. `getSessionUser()`.
- `authenticate()` = opcional (devuelve `?array`), `requireAuth()` = obligatorio,
  `requireRole($user, [...])`.
- **Lectura sin auth:** solo recursos `community`.
- **Escritura:** roles `teacher`, `admin`, `superadmin`. Los `student` no escriben.

### 4.4 Rate limiting — cobertura real [V 2026-08-04]

`rateLimit(PDO, endpoint, limite, ventana)` en `shared/helpers.php:124`, tabla
`api_rate_limits`, IP vía `clientIp()`. **12 de los 15 endpoints lo aplican.**

**NO lo aplican:** `api/check_similarity.php`, `api/health.php`, `api/og-image.php`.
De los tres, los dos caros por petición son `check_similarity` (comparación de shingles
contra el catálogo) y `og-image` (generación de imagen con GD); `health.php` es barato
(un `SELECT 1` y un `COUNT(*)`) y su exención es deliberada, porque Campus lo sondea.
La versión anterior de este documento afirmaba "aplicado en todos los endpoints API":
era falso.

El listado usa 120 peticiones/minuto por IP en GET — relevante para el smoke test, que
hace decenas de peticiones por corrida (`SEARCH_DELAY` existe por esto; el total exacto
lo imprime el propio script al final, no lo fijes aquí). Desde 2026-08-04 un `429` **ya
no se reporta como fallo**: el smoke lo marca `INDETERMINADO`, espera una vez y reintenta
(`docs/RUNBOOK.md §5`).

### 4.5 `api/health.php` — el contrato y lo que se le añadió [V 2026-08-04]

**Ocho campos que SIEMPRE están y no cambian de nombre ni de tipo** (hay consumidores
externos, empezando por Campus):

```
ok · status · service · version · request_id · time · db · resources
```

A partir de 2026-08 se **AÑADEN** campos (nunca se quitan):

| Campo | Qué es | Cuándo es `null` |
|---|---|---|
| `commit` / `commit_full` | El SHA que está VIVO en producción | El hook no está instalado, o no pudo escribir |
| `deployed_at` | Marca UTC del `checkout -f` | ídem |
| `deploy_subject` | Asunto del commit desplegado (≤ 200 chars, saneado) | ídem |
| `crons` | Un objeto por job de `cron/run.php` con `age_seconds`, `status`, `stale`… | La BD no responde, o la tabla `cron_heartbeats` no existe |

⚠️ **`version` es la constante literal `'1.1.0'` escrita a mano**: nunca ha cambiado y no
dice NADA sobre qué código corre. Se conserva porque es parte del contrato, pero **lo que
hay que mirar es `commit`**. El 2026-08-04 se descubrió que el hook `post-receive` llevaba
un mes muerto (permisos 644) y que el smoke test daba 44 checks en verde contra la versión
vieja sin que nada chirriara.

De dónde sale `commit`: el hook escribe `deploy_version.txt` en el doc root
(`commit=…`, `commit_full=…`, `deployed_at=…`, `branch=…`, `subject=…`) y `health.php` lo
**lee y lo valida** — un SHA que no lo parezca se publica como `null`, porque publicar
basura como si fuera el commit vivo es peor que no publicar nada. La lectura ocurre
**antes** del bloque de BD y con tope de 4 KB: si producción está degradada, saber qué
commit la dejó así es lo primero que hace falta, y un log de 40 MB en esa ruta no puede
convertir el health check en el que tumba el servidor.

⚠️ **`json_encode()` devuelve `false` —no una excepción— con UTF-8 inválido, y
`echo false` imprime cadena vacía**: HTTP 200 con cuerpo de 0 bytes. Bastaba un byte
corrupto en `deploy_version.txt` para dejar a Campus sin poder comprobar si la plataforma
está viva, por un fichero informativo. Hay dos redes: reintento con
`JSON_INVALID_UTF8_SUBSTITUTE` y, si aun así falla, un cuerpo mínimo pero **válido** con
500. No toques ese cierre.

`health_crons()` devuelve **`null` si la tabla no existe** en vez de reventar: el código se
despliega antes que la migración y durante esa ventana `health.php` tiene que seguir
respondiendo 200. Y `age_seconds = null` significa **NUNCA HA LATIDO**, que no es lo mismo
que "hace mucho" y no puede colapsarse a un número grande: un job recién declarado y un
job muerto se arreglan de formas distintas.

---

## 5. Visibilidad y roles

### 5.1 Niveles y `canView()` [V 2026-08-04: `api/resources.php:594-613`, fiel a lo documentado]

| Nivel | Quién ve |
|---|---|
| `draft` | Solo el autor (mismo tenant **y** mismo user) |
| `area` | Todos los profesores del mismo tenant |
| `school` | Todos los profesores del mismo tenant |
| `community` | Todos, incluidos anónimos |

`area` **no** filtra por materia. Es intencional: promueve colaboración
interdisciplinaria. El listado replica esta lógica en el WHERE
(`api/resources.php:71-98`) y además excluye `is_active = 0` y
`link_status = 'broken'`.

### 5.2 Deuda abierta: `tenant_id = 0` [problema activo, no hipótesis futura]

El modelo se diseñó asumiendo usuarios con `tenant_id` de Campus. Los profesores
externos (registro directo, ya en producción) tienen `tenant_id = 0`, así que `school`
y `area` no tienen semántica útil para ellos: todos los externos comparten el "tenant"
0 y se ven entre sí como si fueran el mismo colegio. `dashboard/index.php:21` filtra por
`author_tenant_id = 0` para el caso externo. **Sin resolver.**

### 5.3 Roles, y los dos sistemas de guardado

`users.role` es `ENUM('student','teacher','admin','superadmin')` desde `migration_009`
(`schema_users.sql` aún declara el ENUM viejo sin `student`).

**El rol `student` tiene enrutado propio** [V 2026-08-04]: `dashboard/index.php:16` y
`dashboard/editor.php:19` lo redirigen a `/favorites/`; `index.php:21` y
`resource/index.php:66` le ocultan acciones de autoría (Fork); `profile/index.php:161`
permite cambiar entre profesor y estudiante; `auth/onboarding.php:47` decide el destino
tras el alta.

⚠️ **Ese enrutado era sólo cosmético hasta 2026-08-06: NINGÚN endpoint comprobaba el
rol.** `requireRole()` existe en `shared/auth.php:110` desde siempre y no lo llamaba
nadie; ocultar un botón no protege nada, porque el `POST` se lanza a mano con dos
líneas. El primero en usarlo es `api/usage.php` (§5.4). Si añades una acción reservada
a docentes, la comprobación va **en el servidor**; la plantilla sólo evita ofrecer un
clic que va a fallar.

Segundo detalle del mismo arreglo: `$isStudent` sólo miraba `$sessionUser` (Google), así
que **un alumno entrando desde Campus —identidad en el JWT, `$sessionUser` a `null`—
salía clasificado como docente** y veía Fork, contra lo que decía su propio comentario.
Hoy mira las dos identidades. Al escribir comprobaciones de rol en páginas, recuerda que
hay **dos** fuentes de identidad y `authenticate()` normaliza ambas.

**Favoritos y colecciones son DOS sistemas distintos, a propósito. NO los unifiques:**

| | Favorito ⭐ | Colección 🔖 |
|---|---|---|
| Tabla | `resource_favorites` (UNIQUE user+resource) | `collections` + `collection_items` |
| API | `api/favorites.php` | `api/collections.php` |
| UI | 1 clic en la card | Modal "Guardar en colección" (`resource/index.php:389`) |
| Semántica | Privado, personal, sin nombre | Curaduría con nombre, compartible |
| Razón de existir | Gancho de captación de estudiantes | Organización avanzada del profesor |

Ver dos botones de "guardar" en la misma página y fusionarlos destruye el embudo de
registro de estudiantes.

### 5.4 La señal de uso docente — "lo usé en clase" [2026-08-06]

**Qué mide y por qué es distinta.** Las visitas miden curiosidad y los `like` miden
impulso. `usage_type = 'presented'` mide **intención**: lo afirma un profesional que se
jugó 50 minutos de clase. Es la señal que debería ordenar el catálogo, y es la razón por
la que **no se implementó un sistema de estrellas** — con 546 recursos y tráfico bajo, la
mayoría tendría 0-3 votos, y una media de dos votos es ruido con aspecto de autoridad.

`api/usage.php` sabía registrarla desde el primer día y **nunca se llamó**: `use_count`
estaba a 0 en todo el catálogo. Al cablearlo por fin a un botón
(`resource/index.php`, `#usedBtn`) hubo que cerrar tres agujeros que no importaban
mientras el endpoint estaba muerto:

| Agujero | Dónde se cerró | Qué pasaba si no |
|---|---|---|
| Cualquiera podía afirmar uso | `requireRole()` en `api/usage.php` | un alumno alimenta la métrica docente |
| `presented` no deduplicaba | índice `uniq_usage_signal` (`migration_011`) | pulsar 5 veces = 5 usos |
| El 500 publicaba `$e->getMessage()` | `api_log()` + mensaje genérico | tablas y consultas de MariaDB a quien provoque un fallo |

**El contrato de `usage_day` — es lo que más se presta a "arreglarse" mal.** La columna
decide qué filas deduplica el índice UNIQUE y cuáles no:

| `usage_type` | `usage_day` | Quién lo escribe | Efecto |
|---|---|---|---|
| `presented`, `sent` | `CURDATE()` | `api/usage.php` | 1 por profesor, recurso y **día** |
| `endorsed` | `NULL` | `api/usage.php` | lo deduplica **para siempre** el `SELECT` de la app; un índice diario lo debilitaría dejando volver a endosar mañana |
| `forked` | `NULL` | `api/resources.php` (ni menciona la columna) | **nunca** deduplica: forkear dos veces el mismo día es legal |

En InnoDB un UNIQUE **admite múltiples `NULL`**, así que las dos exenciones se cumplen
solas, sin ninguna excepción escrita. ⚠️ **Completar el índice para que cubra `forked`
rompería `api/resources.php` sin tocar `api/resources.php`.** Hay un test por cada fila
de esa tabla (`tests/integration/usage_signal_db_test.php`).

**`use_count` cuenta `presented` + `sent` + `endorsed`, nunca `forked`** — no porque se
filtre, sino porque los forks no pasan por ese contador. `fork_count` ya los cuenta;
sumarlos aquí haría que copiar un recurso valiera lo mismo que dar clase con él.

**La clave lleva `tenant_id`.** Los `user_id` vienen de Campus y sólo son únicos dentro
de su tenant: sin él, el usuario 7 del colegio A bloquearía al usuario 7 del colegio B.

**El 409 no es un error.** Un choque con la dedup responde `409 ALREADY_RECORDED`, no
500: es la restricción haciendo su trabajo. El front rama por el **código**, nunca por el
texto del mensaje — las respuestas de `api/*.php` van en inglés y sin `t()` porque son
contrato para Campus; lo que el usuario lee lo decide la página.

⚠️ **`migration_011` se aplica a mano y el despliegue no la espera.** Si el código llega
antes que la migración, `resource/index.php` consultaría una columna inexistente — y esa
página carga `helpers.php` (está en `quality/baseline_html_helpers.txt`), así que un
`ERROR 1054` sin capturar la sacaría a medio renderizar con un JSON incrustado: la
trampa nº1 del `CLAUDE.md`. Por eso esa consulta va envuelta en `try/catch` y degrada a
"no registrado". El botón queda inerte hasta que la migración corra, pero **la página
nunca se rompe**.

### 5.5 Linaje de forks: "otras versiones" [2026-08-06]

**El problema que resuelve.** Un fork era un recurso suelto más en el catálogo, con
`fork_of` apuntando a su padre. Eso dejaba tres cosas rotas:

| Síntoma | Causa |
|---|---|
| «12 Forks» en la ficha, 2 al pinchar | `fork_count` cuenta también los `draft`, que son **casi todos** (un fork nace privado) |
| «Fork: Simple Harmonic Motion» en la tarjeta | el prefijo `'Fork: '` iba dentro del título |
| No hay forma de destacar una versión | ordenar por votos hace ganar **siempre** al original |

Ese último punto es el importante y el menos obvio: **el original lleva años acumulando
visitas y un fork mejor publicado ayer empieza en cero.** Con ranking por conteo bruto no
lo alcanza nunca —no por peor, por posterior— y forkear no puede salir rentable. Es un
bloqueo estructural, no un ajuste de pesos.

**Cómo se modela ahora.**

- **`root_id`** — raíz del linaje. Para un original vale **su propio id**, así que "dame
  todas las versiones de X" es siempre `WHERE root_id = X`, sin caso especial y sin
  recorrer la cadena. `fork_of` sigue existiendo y sigue siendo el padre inmediato.
- **`is_recommended`** — el autor de la **raíz** destaca una versión. Es el pull request
  de los pobres: le da al fork un camino al primer puesto que **no** es un concurso de
  popularidad, y encaja con cómo piensa un profesor («la versión de María está mejor que
  la mía»).

⚠️ **Sólo el autor de la RAÍZ puede destacar, y sólo versiones `community`.** Si pudiera
hacerlo el autor de cada fork, «recomendada» pasaría a significar «su autor pulsó un
botón». La comprobación vive en `api/resources.php?action=recommend`; ocultar el botón en
la ficha **no es la protección** (hay test que fija las dos capas).

**Los forks NO se esconden del catálogo.** Si alguien mejora un recurso de verdad,
esconderlo bajo el original lo castiga. Se **agrupan**, no se ocultan: siguen siendo
buscables y la ficha muestra el linaje desde cualquier punto —desde el original se ven
las versiones, desde una versión se ven el original y las hermanas—.

**`fork_count` no se tocó** y sigue contando todos los forks incluidos los privados: es un
dato interno correcto. Lo que cambió es la **interfaz**, que ahora muestra las versiones
públicas, y sale del mismo listado que pinta el panel, así que no pueden discrepar.

**El backfill del linaje aplana la cadena repitiendo cuatro veces el mismo
`UPDATE … JOIN`** (cada repetición sube un nivel). Se eligió ese idioma poco habitual
porque `setup/run_migration.php:41` trocea con `explode(';')` y un `WITH RECURSIVE` ataría
el resultado a una versión de MariaDB que en producción es **desconocida**. Cubre linajes
de 5 niveles; hoy las cadenas son de longitud 1.

⚠️ **Un fork huérfano** (`fork_of` no tiene clave ajena, así que puede apuntar a un
recurso borrado) se convierte en su propia raíz. Sin ese paso quedaría con un `root_id`
muerto y **no aparecería en ningún listado**, sin que nada fallara.

**La migración no reescribe títulos.** El prefijo `'Fork: '` deja de añadirse, pero los
títulos ya creados son **contenido de usuario**: limpiarlos por lote es una decisión del
mantenedor, no un efecto colateral de una migración de esquema.

⚠️ Al escribir `migration_013` se coló un **`ERROR 1064`**: en MariaDB el `COMMENT` es
parte de la definición de la columna y va **antes** de `AFTER`. Escrito al revés, el
`ALTER` se para y deja el esquema a medias. Lo cazó la suite de integración antes de
llegar al servidor — es la razón de que esa suite exista.

---

## 6. Frontend

### 6.1 Ruteo

Todo pasa por `.htaccess`: `^view/(\d+)$`, `^resource/(\d+)$`, `^profile/(\d+)$`,
`^sitemap\.xml$`. Al final hay un **catch-all** que manda cualquier ruta que no sea
fichero ni directorio a `/404.php`, excluyendo `/api/`. Existe porque
**LiteSpeed/Hostinger ignora `ErrorDocument` en `.htaccess`** — si lo quitas, los 404
vuelven a ser la página genérica del servidor.

### 6.2 i18n (implementado y completo — no es un pendiente)

`shared/i18n.php` expone `lang()`, `t(string $es)`, `langSwitchUrl()`.
`shared/i18n_en.php` es un array `español => inglés`. Las **claves son el español**, así
que una cadena sin traducir se muestra en español (fallback silencioso), no se rompe.

- En JS: se inyecta `const T = { clave: <?= json_encode(t('...')) ?> , ... }`.
- El guard G7 avisa (no bloquea) si una cadena `t()` no tiene traducción.
  Se vuelve bloqueante con `GUARDS_I18N_STRICT=1`.
- **`lang()` cachea en un `static` irreversible**: un proceso PHP solo puede servir un
  idioma. Los tests que necesitan otro idioma lanzan un subproceso.

### 6.3 PWA

`manifest.webmanifest` + `sw.js` (raíz) + `assets/js/pwa.js:18`, que registra el
service worker. El `<link rel="manifest">` está en 8 páginas (index, resource, viewer,
dashboard, editor, profile, collection, favorites).

**Trampa clásica:** si un cambio no se refleja en el navegador, es la caché del service
worker. No persigas el bug en el servidor.

### 6.4 Viewer

Ramas reales de `viewer/index.php` y `resource/index.php` [V 2026-08-04]: son solo
**tres** `if`, más un `else`. No hay ninguna rama por `code_type` fuera de estas.

| `code_type` | Renderizado |
|---|---|
| `html` | `<iframe srcdoc>` + `sandbox="allow-scripts allow-modals allow-popups"` |
| `embed` | `<iframe srcdoc>` + el mismo sandbox **más `allow-forms`** |
| `url` | `<iframe src>` + autodetección de bloqueo (JS, 4 s) + fallback "Abrir externo" |
| `prompt`, `python`, `other` | caen todos al `else`: un `<pre>` de texto plano |

⚠️ **Dos correcciones sobre la versión anterior de este documento:**

1. **NO existe un "viewer específico para prompts de IA".** `grep -n prompt viewer/index.php`
   no devuelve nada: `prompt` cae en el mismo `else` que `python` y `other`. Lo que sí
   existe es otra cosa con nombre parecido: la columna `resources.source_prompt` (el
   prompt con el que se generó el recurso), que se muestra en `resource/index.php:452`.
2. **El `<pre>` no tiene resaltado de sintaxis.** Es `<pre>` a secas con fondo oscuro y
   Fira Code. No hay ninguna librería de highlighting en el repo (coherente con "cero
   dependencias"): `git grep -l 'hljs\|highlight\.js' -- 'assets/**' '*.php'` → nada
   [V 2026-08-05]. Ojo con la versión con `prism`: `git grep -l 'hljs\|prism\|highlight.js'`
   **sí** devuelve dos ficheros, y ninguno es una librería — este mismo `AGENTS.md` y
   `setup/seed_catalog_v5.sql`, que tiene un recurso titulado "Prism Light Dispersion".

Sandbox [V 2026-08-04]: `allow-scripts allow-modals allow-popups`
(`viewer/index.php:220`, `resource/index.php:335`), con la variante que añade
`allow-forms` para `embed` (`viewer/index.php:282`, `resource/index.php:373`).
**Nunca `allow-same-origin`** — es lo que impide que el recurso embebido toque la
sesión del usuario.

⚠️ **El iframe de `code_type = 'url'` NO lleva atributo `sandbox` en absoluto**
(`viewer/index.php:225-230`). No es lo mismo que "sandbox sin `allow-same-origin`": es
sin sandbox. Se hizo así porque muchos sitios externos (PhET, GeoGebra) se rompen
sandboxeados, pero conviene saberlo antes de afirmar que "el viewer está sandboxeado".

`/view/{id}?mode=present` = fullscreen sin barra; ESC sale (sincronizado con
`fullscreenchange`).

### 6.5 OG images y thumbnails

`api/og-image.php` en dos niveles: busca `thumbnails/og-{id}.png` (screenshot real,
1200×630) y si no existe genera una tarjeta de texto con GD. Cadena de fuentes del
fallback: DejaVuSans → **DroidSans** (lo que hay en el servidor) → DejaVuSansMono.

Los thumbnails se generan **en local** con `setup/tools/generate-thumbnails.sh` (Chrome
headless sobre `/view/{id}?mode=present`) y se suben por SCP. Cobertura al 100 % desde
2026-06-10 [S: registrado en la memoria del mantenedor, no verificable desde aquí].

*Nota histórica:* hubo un intento de generar los screenshots en el propio servidor con
Node + Puppeteer; no arrancó por falta de `libatk-bridge-2.0.so.0` y no hay sudo. Tras
la migración de servidor de 2026-07-13 eso ya no es comprobable ni bloquea nada.

### 6.6 Notificaciones y email

- **In-app:** `api/notifications.php` (GET feed + no leídos, POST marcar leído) sobre
  `users.notifications_seen_at` (`migration_008`).
- **Email:** `shared/mailer.php` (`sendMail`, `mailFromDefault`, `emailShell`) y
  `shared/notify.php` (`notifyResourceAuthor`). Deduplicación en `notification_log`
  (ventana 24 h, `migration_007`). Opt-out por `unsubscribe.php` con
  `users.unsubscribe_token`.
- `mb_send_mail` está en `disable_functions` del hosting; se usa `mail()` [S].
- **`iarepo.com` no tiene registro SPF ni DMARC** [V 2026-08-04: `dig +short TXT
  iarepo.com` y `_dmarc.iarepo.com` devuelven vacío; el MX apunta al parking del
  registrador]. Riesgo real de que el correo caiga en spam. Tarea en
  `docs/RUNBOOK.md §8.3`, con los registros exactos; resumida en el cierre (§10).

### 6.7 El `<select id="sort">` y el orden por relevancia [V 2026-08-04]

El desplegable **mentía**: con búsqueda y sin `?sort=`, la API ordena por relevancia
(§7.3) mientras el `<select>` seguía mostrando "Más recientes". Arreglado en dos capas, y
las dos hay que tocarlas a la vez si se cambia el defecto:

- **PHP** (`index.php:78-83`): replica la MISMA decisión que toma la API, para que el
  desplegable llegue ya pintado con el orden real, sin parpadeo y sin depender del JS.
  Valida `?sort=` contra `['relevance','recent','popular','views','title']`, descarta
  `relevance` **sin** texto de búsqueda (ordenar por relevancia sin términos que puntuar
  no significa nada) y deja `$sortSelected`.
- **Markup** (`index.php:543`): `<option value="relevance">` es la primera de la lista y
  sale `hidden disabled` cuando no hay búsqueda. El `disabled` no es redundante: impide
  que el navegador la elija como "primera opción no deshabilitada" al resetear el
  formulario, así que sin JS tampoco puede mentir.
- **JS**: `syncSortOptions()` muestra/oculta la opción y sincroniza el valor; la llaman
  `loadResources()` y `applyStateFromURL()`, o sea **todas** las rutas (teclear, Enter,
  chip, píldora, ✕, `popstate`, arranque).

**La bandera `sortExplicit` es la decisión de producto que conviene entender antes de
tocarla:** la ponen a `true` el `change` del `<select>` y un `?sort=` válido en la URL.
Mientras esté a `false`, escribir en el buscador salta a `relevance`; una vez el usuario
ha elegido orden, seguir tecleando **no** se lo pisa. `buildParams()` manda `sort` solo
cuando difiere del defecto **efectivo** (`relevance` si hay texto, `recent` si no), de
modo que `?search=ondas` significa lo mismo en la URL y en la API.

### 6.8 Medición de visitas y atención [2026-08-06]

**El bug que arregla.** `view_count` medía mal por dos motivos a la vez:

1. **No contaba la página donde ocurre el uso.** Sólo incrementaban `viewer/index.php` y
   `api/resources.php?id=`. Pero `/resource/N` renderiza el recurso **funcionando** en un
   iframe `srcdoc`: se usa el simulador entero sin salir de ahí, y el contador no se
   movía. Síntoma que lo destapó: **20 alumnos trabajando, 8 visitas registradas**.
2. **No deduplicaba nada.** Un `+1` por carga, crawlers incluidos. Una persona recargando
   ocho veces valía ocho visitas, así que el 290 de un recurso y el 8 de otro no medían
   lo mismo.

**Cómo se mide ahora.** `assets/js/track.js` (beacon) → `api/track.php` → una fila en
`resource_views` por **persona, recurso y día**. El contador vivo es
`resources.unique_views`.

⚠️ **`view_count` está CONGELADO**, no reescrito. Los dos incrementos crudos se
retiraron. Reescribirlo con el número correcto habría hecho que un recurso con 290
visitas amaneciera con 12; esas cargas ocurrieron y son un histórico real, sólo que de
otra magnitud. La ficha muestra **la suma** de ambos (desglose en el `title`) para que el
número no se desplome; para **ranking se usa `unique_views` a solas**, donde sí importa
que la unidad sea limpia.

⚠️ **`shared/search.php:1002` sigue desempatando por `view_count`, a propósito.** Con
`unique_views` a 0 en todo el catálogo, cambiarlo hoy aplanaría el orden de golpe. Es su
propia tarea, con datos delante y tests de relevancia.

**Por qué se identifica el navegador y NO la IP.** El diseño obvio —hash de IP— habría
**empeorado el caso que lo originó**: los alumnos de un colegio salen por el NAT del
centro (una IP para toda el aula) y con los equipos del aula hasta el `User-Agent`
coincide, así que 20 alumnos habrían contado como uno. El identificador anónimo lo genera
el navegador (`localStorage`, 32 hex); el autenticado sale de su identidad real.

**Privacidad — no es opcional, hay menores usando el sitio:**

| Regla | Dónde vive |
|---|---|
| No se guarda ninguna IP, ni en claro ni hasheada | `api/track.php` (hay test que lo prohíbe) |
| `viewer_key` = `sha256(identificador + sal del día)` | `iarepo_daily_salt()` |
| Las sales se **borran** a los 2 días → pasado el plazo la fila es irreversible y no se pueden cruzar dos días de la misma persona | `DELETE FROM view_salts`, colgado del alta de la sal |
| El beacon manda **exactamente 5 campos** y nada más | test `el_cliente_no_manda_nada_que_no_este_declarado` |
| `legal/terms.php` §10.1 declara todo lo anterior | test `el_texto_legal_declara_lo_que_el_codigo_hace` |

Ese último test es raro a propósito: `legal/terms.php` decía *"No recopilamos datos
personales de visitantes anónimos"*, y empezar a medir sin tocar esa línea **no habría
roto ningún test** — habría dejado publicada una afirmación falsa con el nombre del
responsable encima. El código y lo que se promete al usuario tienen que moverse juntos.

**Consecuencia de rotar la sal:** `unique_views` cuenta **persona-día**, no "personas
distintas de siempre". Es justo la pregunta que se quería responder ("¿cuántos abrieron
esto hoy?") y hace imposible la que no se quiere poder responder.

**Detalles que parecen arbitrarios y no lo son:**

- `rowCount() === 1` es lo que distingue fila nueva de actualización (verificado contra
  MariaDB 11.8: 1 inserta, 2 actualiza, 0 no cambia). **Sin esa condición, cada beacon de
  tiempo activo sumaría otra visita** y el contador volvería a medir eventos.
- `engaged_secs` se capa **antes** del INSERT: es `SMALLINT UNSIGNED` y con
  `STRICT_TRANS_TABLES` un desbordamiento aborta con `ERROR 1264` — se perdería la visita
  entera por culpa de un dato accesorio.
- `GREATEST` en los acumulables: el cliente manda el **acumulado**, así que un beacon
  repetido o que llegue desordenado no infla ni hace retroceder el dato.
- El reloj sólo corre con la pestaña **visible**; si no, una pestaña olvidada daría "3
  horas de atención".
- `interacted` sale de detectar que el foco entró en el iframe. Es lo único observable:
  el iframe va con `sandbox="allow-scripts"` **sin** `allow-same-origin`, así que el
  navegador impide leer su interior.

**Contrapartida asumida:** quien navegue sin JavaScript no cuenta. Es intrascendente
—todos los recursos del catálogo son simulaciones en JavaScript— y de paso **filtra los
bots**, que no ejecutan JS.

### 6.9 «¿Te quedó claro?» — el check de comprensión [2026-08-06]

**Por qué esta pregunta y no estrellas.** Se descartó un sistema de valoración, y no por
gusto:

- Las escalas de 5 estrellas colapsan en «5 o 1»; la diferencia entre 4,8 y 4,7 no informa.
- Con 546 recursos y tráfico bajo, la mayoría tendría 0-3 votos. **Una media de dos votos
  es ruido con aspecto de autoridad**, y si alimenta el ranking, ordena mal.
- Pedir una razón al puntuar bajo **suprime** el feedback negativo: la gente no escribe la
  justificación, simplemente no puntúa. Queda una muestra sesgada al alza.

Y sobre todo: quien **usa** los recursos son alumnos, muchos menores, contestando delante
de su profesor. *«¿Te gustó?»* invita a quedar bien. *«¿Te quedó claro?»* es una pregunta
sobre uno mismo, no un juicio sobre el trabajo de otro — y **es el dato que el profesor
quería de verdad**: no «¿les gustó la interfaz?» sino «¿sirvió?». Deja de ser una encuesta
y pasa a ser un chequeo de comprensión, con valor pedagógico propio; la métrica del
catálogo sale de regalo al agregar.

**La puerta está en el servidor.** Sólo responde quien tiene fila en `resource_views`
(hoy, este recurso) con `interacted = 1` y `engaged_secs >= IAREPO_FEEDBACK_MIN_SECS`.

⚠️ **El cliente esconde el prompt hasta ese momento, pero eso es cosmética**: el `POST` se
lanza a mano con dos líneas. Sin la comprobación en `api/feedback.php`, cualquiera inunda
un recurso de «me perdí» sin haberlo abierto y **la API responde 200** — el único dato
pedagógico del sistema, envenenado en silencio.

⚠️ **`MIN_SECS` de `assets/js/track.js` e `IAREPO_FEEDBACK_MIN_SECS` de
`api/feedback.php` tienen que ser el mismo número.** Si el del cliente es menor, se
pregunta a gente a la que el servidor responderá 403: el usuario contesta y recibe un
error. Hay test que compara los dos valores.

**Que la puerta sea del día no es arbitrario:** la sal rota a diario, así que la
`viewer_key` de ayer **ya no se puede recalcular**. La anonimización y la puerta son la
misma propiedad.

| Regla | Por qué |
|---|---|
| **Sin texto libre**, sólo tres opciones | Un campo abierto rellenado por menores es contenido que hay que **moderar**, y el cron de moderación de este repo ya estuvo parado 66 días sin que nadie lo notara. Un `ENUM` no se modera. |
| **Sin identidad** en `resource_comprehension` | Con `user_id` o nombre dejaría de ser un agregado y pasaría a ser un registro nominal de qué menor no entendió qué |
| **Sin media**: se cuentan respuestas | Es lo único que un volumen pequeño permite afirmar con honestidad |
| El agregado **sólo lo ve el autor**, en su dashboard | Un contador de «me perdí» público sería una picota, y convertiría una herramienta de mejora en una nota |
| **Sin contadores desnormalizados**: `GROUP BY` al vuelo | Serían tres columnas más que mantener en sincronía; este repo ya arrastra el caso contrario con `fork_count` |

**Se puede corregir la respuesta** (`ON DUPLICATE KEY UPDATE`): alguien marca «me perdí»,
sigue trasteando y lo entiende. Congelar la primera guardaría la peor versión justo de
quien acabó entendiéndolo.

⚠️ **`ORDER BY` sobre un `ENUM` ordena por el ORDINAL de declaración, no
alfabéticamente.** Trampa clásica de MariaDB; puso en rojo el test del agregado con los
mismos números en distinto orden de claves.

**La identidad del visitante vive en `shared/viewer_key.php`**, compartida con
`api/track.php`. Estaba dentro de `track.php` hasta que este endpoint necesitó lo mismo:
duplicarla habría sido la peor clase de duplicación —dos copias que divergen sin que nada
falle, y la que se quedara atrás produciría claves distintas para la misma persona,
rompiendo la deduplicación en silencio—. Ese módulo **no carga `helpers.php`**, igual que
`shared/search.php`.

---

## 7. El buscador

Sección nueva. Antes toda la documentación de la búsqueda eran dos líneas, y una de
ellas ("busca en tags") era falsa.

### 7.1 Dónde vive

`index.php` **no consulta la BD**: delega por `fetch` a `GET /api/resources.php?search=`.
Ese endpoint es el **único punto de búsqueda de toda la aplicación**; ni
`/favorites/`, ni `/dashboard/`, ni `/collection/`, ni `/profile/` tienen buscador.

La construcción del SQL está aislada en **`shared/search.php`**: funciones puras, sin
salida y sin BD, por lo que se carga igual desde la API y desde los tests.

⚠️ **Tiene exactamente UN `require`, y es una excepción acotada:**
`shared/search_synonyms.php`, que no es código sino un `return [...]` de datos. Se carga
**perezosamente** (solo la primera vez que alguien busca), no tiene efectos secundarios ni
salida, y si faltase se degrada a "sin sinónimos". **Nada más puede requerirse aquí**: un
`require` de `helpers.php` arrastraría `error_handler.php` y sus handlers que hacen
`exit` (regla #1 del proyecto).

```
index.php (JS)  ──fetch──▶  api/resources.php:142  ──▶  iarepo_build_search($raw)
                                                          └─▶ where / params / score / terms
```

### 7.2 Por qué se reescribió

El código anterior (`MATCH(...) AGAINST(? IN BOOLEAN MODE)` con el input crudo del
usuario) fallaba así — evidencia reproducida contra producción antes del arreglo:

| Consulta | Síntoma |
|---|---|
| `C++`, `(ondas`, `@`, `+++`, `-` | **HTTP 500**: operadores del parser fulltext sin sanear |
| `matem` | 0 resultados: el fulltext exige palabra completa |
| `ondas` vs `onda` | Conjuntos distintos: sin despluralización |
| `pH` | 0: InnoDB descarta tokens de < 3 caracteres |
| `ondas sonido` | Igual que `ondas`: BOOLEAN MODE sin `+` es **OR**, no AND |
| `energia cinetica` | Ordenaba por `created_at`, no por relevancia |
| `física-química` | El guion se interpretaba como NOT |

Los acentos **sí** funcionaban y siguen funcionando (collation accent-insensitive):
`fisica` y `física` dan lo mismo. No persigas un problema de acentos que no existe.

### 7.3 Cómo funciona ahora

**Defensa: lista blanca, no lista negra.** `iarepo_normalize()` valida UTF-8, recorta a
120 caracteres, pasa a minúsculas y reemplaza todo lo que no sea `\p{L}` o `\p{N}` por
espacio. Ningún operador de fulltext puede sobrevivir, así que el 500 es imposible por
construcción. Antes de devolver, la cadena fulltext se vuelve a validar contra
`IAREPO_FT_SAFE` y, si no casa, se descarta el brazo entero.

⚠️ **`IAREPO_FT_SAFE` se amplió en 2026-08 para admitir GRUPOS**, y sigue siendo lista
blanca. Un "átomo" es un término obligatorio con comodín **o** un grupo obligatorio de
alternativas:

```php
IAREPO_FT_TERM = '[\p{L}\p{N}]+\*';                                   // onda*
IAREPO_FT_ATOM = '\+(?:TERM|\(TERM(?: TERM)*\))';                     // +onda*  |  +(onda* wave*)
IAREPO_FT_SAFE = '/^ATOM(?: ATOM)*$/u';
```

Los únicos caracteres que pueden aparecer son `\p{L}`, `\p{N}`, `+`, `*`, `(`, `)` y el
espacio separador, y los paréntesis **solo** en la posición exacta que abre y cierra un
grupo. Un operador del parser (`-`, `~`, `<`, `>`, `@`, `"`) suelto sigue siendo imposible.

**Pipeline:** `iarepo_normalize` → `iarepo_tokenize` (máx. 8 términos únicos de 40
chars) → `iarepo_stem` (despluralización conservadora, solo del lado de la consulta:
`ondas`→`onda`, `waves`→`wave`; `clase`/`gas`/`los` intactos) → **`iarepo_expand`**
(cada token se convierte en un GRUPO de equivalentes) → `iarepo_build_search`.

#### Cada término del usuario es un GRUPO [V 2026-08-04, `shared/search.php:786`]

El catálogo es **bilingüe** y la columna `lang` **no es de fiar**: entre los títulos
marcados `es` los términos más frecuentes son `mechanics`, `electromagnetism`, `waves`,
`quantum`; y los tags están duplicados por idioma (`simulation`/`simulación`,
`interactive`/`interactivo`). Filtrar por `lang` no arregla nada — hay que expandir la
**consulta**. `shared/search_synonyms.php` es un `return [...]` de datos puros
(hoy **211 grupos / 654 términos**; recuento vivo:
`php -r '$g=require "shared/search_synonyms.php"; $n=0; foreach($g as $x)$n+=count($x); echo count($g)," grupos / $n términos\n";'`).

Cada token pasa a ser `[exacto, sinónimo, sinónimo…]`. **El AND entre términos se
mantiene; el OR queda DENTRO del grupo**, en los dos brazos:

```
'ondas'         → +(onda* wave*)
'ondas sonido'  → +(onda* wave*) +(sonido* sound* acoustic* acustica*)
```

Verificado contra el motor, no contra la documentación: `+onda* +wave*` → `[]` (AND
estricto), `+(onda* wave*)` → filas, `+(onda* wave*) +(zzz*)` → `[]` (un grupo sin
coincidencias anula la consulta). Ganancia medida contra el catálogo real de producción
(546 recursos visibles): `biologia` 0→37, `fisica` 18→321, `matematicas` 2→117,
`quimica` 1→39, `ondas` 11→46, `simulation` 270→302. Buscar "biología" devolvía **cero**
en un catálogo con 37 recursos de biología, porque están catalogados como `Biology`.

Detalles que no son evidentes leyendo el SQL:

- **`iarepo_fold()` pliega los acentos SOLO para consultar el diccionario**, nunca para
  construir SQL. `iarepo_normalize()` conserva las tildes a propósito (`\p{L}` las
  incluye) y no hace falta quitarlas para buscar: la collation `utf8mb4_unicode_ci` ya es
  insensible a acentos en `LIKE` y en el índice fulltext (medido: `+fisica*` y `+física*`
  devuelven la misma fila). Pero las claves del diccionario van sin tilde, así que
  "física", "biología" o "matemáticas" —las consultas que más ganan— no encontrarían su
  grupo. **La `ñ` NO se pliega**: en castellano es una letra distinta, ninguna clave la
  usa, y plegarla convertiría `año` en `ano`.
- **Tope `IAREPO_MAX_SYNONYMS = 6`**, incluido el término exacto. El peor caso es
  8 grupos × 6 miembros = 48 ramas del brazo LIKE. Medido tras despluralizar, plegar y
  **podar por prefijo** (`math` cubre `mathematic`; `magnet` cubre `magnetismo`,
  `magnetism`, `magnetic`), el grupo más grande del diccionario tiene 5 miembros y la
  media es 2,32. Que el tope nunca recorte el diccionario de hoy lo vigila
  `test_el_tope_de_expansion_nunca_recorta_el_diccionario()`: se pone rojo **antes** de
  que la poda ocurra en silencio.
- **`iarepo_synonyms()` rechaza al cargar** los miembros con espacios (`tabla periodica`
  es inalcanzable: la expansión es token a token), los de menos de `IAREPO_FT_MIN`
  caracteres, los stopwords, y los grupos que se quedan con menos de 2 miembros. El
  miembro corto está medido: `+(onda* ph*)` arrastraba "Photosynthesis". **El único
  término corto admisible sigue siendo el que escribió el usuario.**
- Si `search_synonyms.php` faltara, `iarepo_synonyms()` **se degrada a "sin sinónimos"**
  en vez de reventar: el buscador vuelve a ser el de antes, que es el peor caso aceptable.

**Estrategia híbrida — dos brazos por GRUPO:**

1. **Fulltext** `+(miembro* miembro*)` sobre el índice `idx_search (title, description,
   topic_tag)` de `schema.sql:57`. Rápido, y el `*` da búsqueda por prefijo (`matem`).
   Un grupo de un solo miembro se emite **sin paréntesis** (`+onda*`): así el SQL de un
   término sin sinónimos es byte a byte el de siempre.
2. **Segundo brazo**, sobre el `CONCAT_WS` de `title`, `description`, `topic_tag`,
   `subject_area`, `source_name`, `author_display_name` y **los tags** (`EXISTS` sobre
   `resource_tags`). Alcanza lo que el índice no cubre: tokens cortos y columnas fuera
   del índice (`PhET` → `source_name`, `simulation` → `resource_tags`).

⚠️ **El segundo brazo NO es un `LIKE` uniforme: hay TRES formas, y la que toca depende de
si el miembro lo escribió el usuario y de cuánto mide.** Es lo que más se presta a
"simplificar" mal [V 2026-08-04, `shared/search.php:767` `iarepo_term_condition()` y
`shared/search.php:724` `iarepo_syn_condition()`].

| Miembro del grupo | Forma | Por qué |
|---|---|---|
| **Término del usuario, ≥ 3 caracteres** (`onda`, `matem`, `adn`) | `LIKE '%termino%'` — **subcadena** | Ahí la precisión la pone el brazo fulltext. La subcadena es lo único que da el **prefijo** (`matem` → "Matemáticas") y la **indiferencia a acentos** (`matematicas` → "Matemáticas", que sale de la collation) |
| **Término del usuario, < 3 caracteres** (`ph`, `c`, `0`) | **palabra completa**: `haystack REGEXP '(?<![\p{L}\p{N}])ph(?![\p{L}\p{N}])'` OR el mismo patrón sobre los tags OR `CONCAT(' ',haystack,' ') LIKE '% ph %'` | No tienen brazo fulltext (`innodb_ft_min_token_size = 3`), así que la subcadena era su **único** filtro: `'C++'→'c'` casaba 546 de 546 recursos, `pH` 391, `IA` 536. Con frontera: 3, 4 y 0 |
| **SINÓNIMO** (`wave`, `physic`, `ion`) | **principio de palabra**: `REGEXP '(?<![\p{L}\p{N}])wave'` (sin cerrar por la derecha) OR sobre los tags OR `LIKE '% wave%'` | Como subcadena, el sinónimo `ion` (grupo ion/iones/ions) casaba **439 de 546** recursos —el 80 % del catálogo— porque "Simulations", "Motion" y "Combinación" llevan `ion` dentro. Por principio de palabra: 0 falsos. Y no cuesta recall: `physic` 307→307, `math` 116→116, `biology` 37→37, `wave` 40→40 |

La clase de "carácter de palabra" del patrón (`[\p{L}\p{N}]`) es **exactamente** la lista
blanca de `iarepo_normalize()`, para que el corte del patrón y el del tokenizador no
puedan discrepar. Así `ph` casa "Escala de pH", "pH-metro" y "(pH)" pero **no**
"Photosynthesis". El tercer OR de las dos formas `REGEXP` es el plan B: pasa por la
collation, que sí es insensible a acentos (el `REGEXP` no lo es), de modo que el sinónimo
`fraccion` sigue alcanzando "Fracción".

⚠️ **Un sinónimo que no supera la lista blanca se DESCARTA; un término del usuario que no
la supera cae al `LIKE`.** No es una asimetría por descuido: un sinónimo es recall
opcional, y ensanchar sin control es justo lo que no puede pasar
(`iarepo_syn_condition()` devuelve `[null, []]`).

**Seguridad del patrón:** `iarepo_word_regexp()` e `iarepo_prefix_regexp()` solo aceptan
tokens ya normalizados (`/^[\p{L}\p{N}]+$/u`) y devuelven `''` con cualquier otra cosa;
el patrón se revalida con `iarepo_is_word_regexp()` / `iarepo_is_prefix_regexp()` antes de
emitirlo (cinturón + tirantes, igual que `IAREPO_FT_SAFE`) y viaja **siempre** como
parámetro ligado. Un metacarácter del usuario no puede llegar al motor de regex: ni error
1139, ni backtracking catastrófico. La frase cruda con puntuación jamás pasa por ahí.

El resultado es `(MATCH(...) AND grupos_no_indexables) OR (segundo_brazo AND …)`.
Los grupos que el fulltext no puede exigir —todos sus miembros son cortos, o es la frase
de respaldo— van pegados con AND al brazo fulltext porque, si el OR fuera pelado,
`pH escala` devolvería todo lo que contuviera "escala" (el fulltext no puede exigir
`ph`) y el AND multi-palabra dejaría de existir.

⚠️ **La cadena fulltext se sigue revalidando entera** contra `IAREPO_FT_SAFE`, que ahora
admite además el átomo de grupo `\+\(termino\*( termino\*)*\)`. Los únicos caracteres
posibles siguen siendo `\p{L}`, `\p{N}`, `+`, `*`, `(`, `)` y el espacio, y los paréntesis
solo en la posición que abre y cierra un grupo. Si algo se colara, se descarta el brazo
fulltext **entero** (`$ft = ''` → `mode = 'like'`), nunca se emite a medias.

Comprobar la forma real sin BD, que es la manera barata de no creerse esta tabla:

```bash
php -r 'require "shared/search.php";
  foreach (["ondas","pH","ADN","fisica","ondas sonido"] as $q) { $r = iarepo_build_search($q);
    printf("%-13s mode=%-7s ft=[%s]\n  groups=%s short=%s\n", $q, $r["mode"], $r["debug"]["ft"],
      json_encode($r["debug"]["groups"], JSON_UNESCAPED_UNICODE), json_encode($r["debug"]["short"])); }'
```

Salida real [V 2026-08-04]: `ondas` → `+(onda* wave*)`, `pH` → `mode=like` con
`short=["ph"]`, `fisica` → `+(fisica* physic*)`.

**Contrato de `iarepo_build_search(string $raw): array`:**

| Clave | Tipo | Significado |
|---|---|---|
| `mode` | string | `none` \| `like` \| `hybrid`. **`fulltext` no se emite nunca**: siempre hay brazo LIKE |
| `where` | string | SQL con placeholders posicionales `?`. Vacío si `mode = 'none'`. El llamador **debe** aliasar la tabla como `r` |
| `params` | array | Valores de `where`, en orden |
| `score` | ?string | Expresión SQL de relevancia, o `null` |
| `score_params` | array | Valores de `score`, en orden |
| `terms` | array | Tokens normalizados (para resaltar en la UI). **NO incluye los sinónimos**: es lo que el usuario escribió y lo único que el frontend resalta hoy. Siempre `string`, nunca `int` (§13, punto 4) |
| `debug` | array | `ft`, `like`, `short`, `dropped`, **`groups`**. Ver abajo |

**La clave `debug` [V 2026-08-04, `shared/search.php:1011`]:**

| Subclave | Qué es |
|---|---|
| `ft` | La cadena literal que entra en `AGAINST()` (`+(onda* wave*)`), o `''` |
| `like` | **Un término EXACTO por grupo** — lo que escribió el usuario, ya despluralizado |
| `short` | Los términos que filtran por **FRONTERA DE PALABRA**, es decir los invisibles para el fulltext |
| `dropped` | Stopwords descartadas: fuera del AND, jamás con `+` |
| `groups` | **NUEVA (2026-08).** La expansión completa por sinónimos, **alineada con `like`**: `groups[i][0] === like[i]` |

⚠️ `groups` es lo que de verdad **FILTRA**, no lo que salió del diccionario: un sinónimo
cuyo patrón no se pudo construir se descarta del grupo *antes* de informarlo, para que no
pueda haber un sinónimo que puntúe sin filtrar (`shared/search.php:881`). Invariantes que
fija `tests/unit/search_test.php`: `count(groups[i]) <= IAREPO_MAX_SYNONYMS`,
`groups[i][0] === like[i]`, y ningún miembro mide menos de `IAREPO_FT_MIN` salvo que sea
el propio término del usuario.

⚠️ **El contrato de `iarepo_build_search()` NO cambió con los sinónimos.** Se AÑADIÓ
`debug['groups']`; ninguna clave existente cambió de nombre, tipo ni semántica. Un token
sin sinónimos produce un grupo de un solo miembro y todo el motor se comporta
exactamente igual que antes.

⚠️ **`mode` nunca vale `fulltext`, y no es un descuido.** El segundo brazo está *siempre*
presente (es el único que alcanza `source_name`, los tags y los términos cortos), así que
en cuanto hay búsqueda el modo es `like` (sin brazo fulltext) o `hybrid` (con él). No
escribas un consumidor que ramifique por `'fulltext'`: hay tests que fijan que ese valor
no existe. Hoy ningún consumidor ramifica por `mode` — `index.php` ni siquiera lo lee.

**Orden de parámetros (crítico):** `score` va en el `SELECT` y el `SELECT` precede al
`WHERE`, así que `score_params` van **siempre antes** que `params`. La consulta de
`COUNT` no lleva score y por tanto tampoco `score_params`. PDO corre con
`ATTR_EMULATE_PREPARES => false`: los `?` son posicionales de verdad y equivocarse de
orden no da error, da resultados silenciosamente mal.

**Relevancia** (`_relevance`, se elimina del JSON antes de responder):

```
  LEAST(MATCH*2, 24)        ← ACOTADO; solo si hay brazo fulltext
+ 30   la frase normalizada está en el título
+ 25   la frase TAL CUAL, con su puntuación, está en el título ("C++")
+ 10   por término EXACTO, en cualquier parte del título
+ 12   por término EXACTO, como PALABRA COMPLETA del título
+  8   por término EXACTO en CUALQUIER columna   ← solo si el grupo se expandió
+  5   por SINÓNIMO en el título (principio de palabra)  ← la mitad que el exacto
+ LEAST(view_count/200, 3)  ← desempate suave por popularidad
```

**El resultado exacto gana al sinónimo POR CONSTRUCCIÓN**, no por afinar pesos: en el
título son 10+12+8 = **30 contra 5**, y fuera del título **8 contra 0**. Quien busca
"ondas" ve antes lo que dice "ondas" que lo que dice "waves", aunque el sinónimo esté en
el título y el término exacto solo en la descripción. El sumando fulltext **no puede
romper la invariante**: el `MATCH` se calcula sobre el grupo entero y da lo mismo a quien
casa por el término que a quien casa por el sinónimo.

Los dos sumandos nuevos (+8 y +5) **solo se emiten si el grupo tiene 2 o más miembros**:
sin sinónimos no hay nada que desempatar y el score queda idéntico al de siempre. El bono
de sinónimo va por la vía del espacio (`CONCAT(' ', r.title, ' ') LIKE '% wave%'`) y no
por `REGEXP`: un bono de desempate no merece el coste del motor de expresiones regulares,
y de paso es insensible a acentos. **No lleva bono de palabra completa a propósito**, para
que la poda por prefijo de `iarepo_expand()` (`math` cubre `mathematic`) no pueda alterar
el orden — solo el conjunto, que es idéntico.

⚠️ **El sumando fulltext va con tope (`IAREPO_FT_SCORE_CAP = 24`) desde 2026-08-04.** Era
`MATCH*2` pelado, que no está acotado: crece con la frecuencia del término, así que
repetir la palabra en la descripción se comía el bono de 30 de "la frase entera está en
el título". El 24 se eligió **midiendo**, no a ojo — es el mayor entero por debajo de los
dos bonos de FRASE (25 y 30), y sobre el catálogo real recorta solo la cola patológica
(~1 % de las filas) sin cambiar el primer resultado de ninguna consulta del barrido. La
tabla del barrido está en la cabecera de `shared/search.php`. Que el sumando **sí** pueda
superar los bonos por término (+10, +12) es deliberado: un `MATCH` alto es evidencia
real; lo que no puede es ganarle a que la frase entera esté en el título.

Con búsqueda y sin `sort` explícito, el orden por defecto es `relevance` — y desde
2026-08-04 **la UI lo dice** (§6.7). Todos los `ORDER BY` llevan `, r.id` de desempate:
sin él dos filas empatadas pueden intercambiarse entre páginas (duplicar una y perder
otra).

### 7.4 Límites conocidos (no son bugs, son el diseño)

- **`innodb_ft_min_token_size = 3`** es una variable **global** del servidor: en
  hosting compartido no se puede tocar. Los tokens de 1-2 caracteres nunca entran en el
  índice fulltext; por eso existe el segundo brazo, y por eso a esos tokens se les aplica
  frontera de palabra (§7.3).
- **MariaDB no tiene parser NGRAM**, así que CJK no se segmenta: una consulta en chino o
  coreano cae al segundo brazo. Los tests garantizan que **no rompe**, no que encuentre.
- **Nunca emitas `+<stopword>*` ni `+<2 chars>*`.** Anula la consulta fulltext entera
  (`+the* +water*` → 0 filas). La lista está en `IAREPO_STOP` (stopwords por defecto de
  InnoDB verificadas + castellano).
- **El segundo brazo impide usar el índice** (`EXPLAIN` → `type: ALL`), y el `REGEXP` de
  los términos cortos escanea igual que el `LIKE '%x%'` al que sustituye: no hay
  regresión de latencia, pero tampoco mejora. **Coste medido de los sinónimos** contra el
  catálogo de producción (546 recursos, consulta completa con score y `ORDER BY`, media de
  20 ejecuciones; tabla en la cabecera de `shared/search.php`): `fisica` 0,82→1,96 ms,
  `ondas sonido` 1,06→2,53 ms, y el peor caso que el tokenizador permite construir
  (8 términos con los grupos más grandes = 54 ramas, 135 parámetros) 1,01→**4,24 ms**.
  Se paga. Cifras a otra escala [S: medidas en una sesión anterior contra MariaDB en
  Docker; **no hay script ni test que las reproduzca**]: 13-26 ms con ~7.000 filas. El
  umbral de acción está en el orden de 15-20k recursos, y el arreglo natural es "dos
  viajes" (fulltext primero, LIKE solo si `total = 0`), que la salida ya permite porque
  viene separada en brazos.
- ⚠️ **La imprecisión por subcadena SIGUE VIVA para el término del usuario de 3+
  caracteres**, y es una decisión, no un olvido: **`ADN` sigue devolviendo
  "Chla**dn**i Patterns"** y `simulation` casa media base. Los sinónimos SÍ se acotaron
  (principio de palabra, 2026-08-04), pero al término que escribió el usuario aplicarle la
  frontera rompería el prefijo (`matem` → "Matemáticas") y la indiferencia a acentos
  (`matematicas` → "Matemáticas"), que son dos de los siete síntomas de §7.2.
  **El arreglo de precisión está acotado a los tokens de < 3 caracteres del usuario y a
  los sinónimos; el término largo del usuario sigue siendo subcadena.**
- **Un sinónimo corto y común puede seguir haciendo ruido**, aunque vaya por principio de
  palabra. Por eso se quitó `par` del grupo `torque`: alcanzaba "para" y "partícula"
  (32 recursos) para quien buscaba "torque". La regla de mantenimiento está en la cabecera
  de `shared/search_synonyms.php`; un grupo es un CONCEPTO, no cuasi-sinónimos.
- **La columna `lang` no es de fiar** y por eso no se usa para nada del buscador. Entre
  los títulos marcados `es`, los términos más frecuentes son `mechanics`,
  `electromagnetism`, `waves`, `quantum` [V 2026-08-04 contra el catálogo de producción].
  El filtro `?lang=` de la API sigue existiendo y sigue mintiendo lo mismo que la columna.
- **Una consulta que es solo un stopword devuelve mucho.** `a` casa como palabra suelta
  en una fracción grande del catálogo. Es coherente con la frontera de palabra y no es un
  bug; decidir si buscar `a` debe dar 0 o darlo todo es un cambio de producto.
- **Los pesos de relevancia son heurística.** El **tope** del sumando fulltext (24) sí se
  calibró contra el catálogo real de producción, pero los bonos (30/25/12/10) se afinaron
  contra una semilla pequeña. Solo afectan al `ORDER BY`; nunca cambian qué filas salen
  ni pueden provocar un error. Y el tope se calibró con el catálogo de **hoy**: `MATCH`
  depende de la IDF, o sea del corpus, así que si el catálogo crece o cambia mucho de
  composición hay que repetir el barrido. Nada avisa de eso automáticamente.
- **Incoherencia menor entre filtro y ranking en los términos cortos:** el filtro es
  frontera de palabra, pero el bono de +10 sigue siendo `LIKE '%c%'` sobre el título.
  Dentro del conjunto ya filtrado puede premiar un título que solo contiene la letra
  suelta. Inofensivo mientras el filtro sea estrecho, pero está ahí.
- **`api/resources.php` no filtra por `moderation_status`** en el listado, pero la
  sección "Más usados" de la portada sí. La búsqueda puede servir un recurso pendiente
  que la portada oculta en el mismo pantallazo. Sin unificar.

### 7.4.1 ⚠️ El riesgo abierto: el `REGEXP` no está probado contra la MariaDB de producción

Toda la precisión de los términos cortos depende de que el servidor acepte el lookbehind
PCRE `(?<![\p{L}\p{N}])ph(?![\p{L}\p{N}])`.

- **[V]** Funciona en la MariaDB del contenedor de pruebas, verificado empíricamente
  (casa `pH.`, `(pH)`, `pH-metro`, la `C` de `C++`; **no** casa `Photosynthesis`; trata
  `ñ`/`á` como carácter de palabra, así que `ni` no casa "niños").
  `tests/integration/search_db_test.php` lo fija: en cualquier motor que se desvíe, rojo.
- **[S]** La versión de MariaDB del hosting **no es averiguable desde aquí**:
  `api/health.php` no expone `VERSION()` y los agentes no tienen SSH. MariaDB usa PCRE
  desde 10.0.5 (2013) y un plan Business de 2026 va muy por encima, así que la
  probabilidad es baja — pero es una inferencia, no una comprobación.
- **Si el motor fuese anterior**, el `REGEXP` daría `ERROR 1139` y **toda búsqueda con un
  token corto sería HTTP 500**. Ojo: el `OR` con el plan B por collation **no salva
  nada** si el `REGEXP` lanza error en vez de devolver false.
- **Verificación tras el deploy** (`quality/smoke_test.sh` ya la cubre):
  ```bash
  curl -s -o /dev/null -w '%{http_code}\n' 'https://iarepo.com/api/resources.php?search=pH'
  # 200 = el motor acepta el patrón.  500 = rollback.
  ```
- **Rollback de una línea:** en `iarepo_term_condition()` (`shared/search.php:769`,
  localízalo con `grep -nF 'if ($word) {' shared/search.php` — **el `-F` no es opcional**:
  sin él GNU grep trata el `$` de `$word` como ancla de fin de línea y devuelve **cero
  líneas**, que parece "el código ya no está ahí"), cambiar `if ($word) {` por
  `if (false) {`.
  Cae por la rama `LIKE` y se vuelve al comportamiento anterior (impreciso, pero vivo).
- ⚠️ **Los SINÓNIMOS usan el mismo motor de regex** (`iarepo_prefix_regexp()`), así que
  desde 2026-08 el riesgo ya no está acotado a las consultas con token corto: si el motor
  no aceptara el lookbehind, **cualquier consulta con un término que esté en el
  diccionario** daría 500 — o sea, casi todas. El rollback de arriba **no cubre** ese
  caso; para desactivar también los sinónimos, en `iarepo_syn_condition()`
  (`shared/search.php:724`) hacer que devuelva `[null, []]` incondicionalmente. Los dos
  cambios juntos devuelven el buscador a su forma de julio.

Efecto colateral aceptado y **no cubierto por ningún test**: en la vía `REGEXP` los
términos cortos **pierden la indiferencia a acentos** (compara carácter a carácter; la
collation no interviene). El plan B por `LIKE` solo los recupera cuando la palabra va
delimitada por espacios, no con puntuación pegada: buscar `si` no encuentra `"sí."`
aunque sí encuentre `" sí "`. Tokens de 1-2 caracteres acentuados son rarísimos en este
catálogo, pero conviene saberlo.

### 7.5 Qué probar cuando lo toques

`tests/unit/search_test.php` fija las invariantes sobre un corpus hostil y un fuzz
determinista: placeholders == params, ni un byte del usuario en el texto del SQL,
comillas y paréntesis balanceados, ningún `+stopword*`, y —lo más fuerte— **cada `?` se
clasifica por el operador que lo consume** (`AGAINST` / `REGEXP` / `LIKE`) y se exige que
el parámetro de esa posición sea del tipo correcto. Esa invariante existe porque un
descuadre de orden dejaría un `'%ph%'` alimentando a un `REGEXP`: SQL válido, cero
errores, resultados equivocados **en silencio**.

Sobre los sinónimos fija además, con estos nombres exactos [V 2026-08-04,
`php tests/run.php --list | grep search_test`]:

| Test | Qué ancla |
|---|---|
| `test_un_termino_sin_sinonimos_produce_el_sql_de_siempre` | La compatibilidad hacia atrás: sin expansión, el SQL es byte a byte el de antes |
| `test_los_grupos_del_fulltext_estan_bien_cerrados` | Que `+(a* b*)` no pueda salir malformado ni romper `IAREPO_FT_SAFE` |
| `test_el_tope_de_expansion_nunca_recorta_el_diccionario` | Rojo **antes** de que `IAREPO_MAX_SYNONYMS` empiece a podar en silencio |
| `test_el_sinonimo_filtra_por_principio_de_palabra` | Que un sinónimo nunca vuelva a ser subcadena (el caso `ion` → 80 % del catálogo) |
| `test_el_score_premia_el_exacto_sobre_el_sinonimo` | La invariante 30 contra 5 |
| `test_sin_diccionario_degrada_a_sin_sinonimos` | Que un `search_synonyms.php` ausente no tumbe el buscador |
| `test_fold` | El plegado de acentos, incluida la `ñ` que **no** se pliega |

`tests/integration/search_db_test.php` y `search_fuzz_test.php` las verifican contra
MariaDB real, incluido el comportamiento del propio motor de regex (§7.4.1). Si cambias
`shared/search.php` **o `shared/search_synonyms.php`**, corre las dos capas:

```bash
php tests/run.php --filter=search
make integration          # = php tests/run.php --integration
```

⚠️ **Tocar el diccionario es tocar el buscador.** `search_synonyms.php` no es código, pero
un grupo mal puesto cambia qué filas devuelve cada consulta del catálogo. No entra en
`make check` por otra vía que la suite de search: no lo edites sin correrla.

---

## 8. Validación y despliegue

### 8.1 El modelo mental: push = producción + publicación

```
git push origin main
   ├─→ hook post-receive → checkout -f al doc root       PRODUCCIÓN EN VIVO
   └─→ github.com/jacksonsmirnov-del/iarepo              REPO PÚBLICO
```

`origin` tiene **dos** pushURL [V 2026-08-04: `git config --get-all
remote.origin.pushurl`]. **No hay staging ni revisión intermedia**; **sí hay CI** desde
2026-08 (§8.7), pero corre en GitHub *después* de que el push ya haya desplegado: es una
red, no un freno. Un secreto commiteado se filtra por dos vías a la vez y GitHub conserva
el objeto aunque se revierta.

**Los agentes no hacen `commit`, `push`, `checkout`, `stash` ni `reset`.** Editan el
working tree; el usuario decide.

### 8.2 Las seis capas

| Capa | Herramienta | Cuándo | ¿Es un gate? |
|---|---|---|---|
| 1 · estáticos | `quality/guards.sh` (9 chequeos, ~0,5 s) | antes del push | **sí** |
| 2 · unitarios | `php tests/run.php` (sin BD, < 5 s) | antes del push | **sí** |
| 3 · integración | `make integration` (= `php tests/run.php --integration`, MariaDB en Docker) | al tocar SQL/búsqueda | opcional |
| 4 · gate local | `.githooks/pre-push` (instálalo con `make hooks`) | en el push | **es EL gate local** |
| 5 · **CI** | `.github/workflows/ci.yml` en GitHub | en cada push a cualquier rama y en cada PR | **sí, y `--no-verify` NO la salta** (§8.7) |
| 6 · post-deploy | `quality/smoke_test.sh` (red, contra producción) | después del push | **no** |

```bash
make check        # lint + guards + test  ← lo mismo que exige el hook, sobre todo el repo
make integration  # capa 3: NO entra en check ni en el hook (necesita Docker)
make smoke        # verificación POST-deploy contra https://iarepo.com
```

Estado de las capas 2 y 3 [V 2026-08-04, salida literal de los comandos]: `php
tests/run.php` → `91 test(s) en verde`; `php tests/run.php --integration` → `142 test(s)
en verde`. Los dos números caducan en cuanto alguien añada un test: **córrelos, no los
cites.** Lo que sí es una afirmación estable es que hoy **no queda ninguno en rojo**, ni
en unitarios ni en integración.

⚠️ **La capa 4 es configuración LOCAL por clon**, no del repo: `git config
core.hooksPath` tiene que imprimir `.githooks` o no hay gate. La capa 5 existe
precisamente porque la 4 depende de la disciplina y del entorno de quien empuja.

`quality/smoke_test.sh` tiene **tres** estados (PASS / FAIL / INDETERMINADO) y tres
códigos de salida (`0` / `1` / `2`). Un `429` es INDETERMINADO, no FAIL. Detalle en
`docs/RUNBOOK.md §5`; si encadenas con `&&` o `set -e`, el `2` te va a morder.

`make smoke` **no valida nada a tiempo**: cuando falla, el fallo ya está en vivo. La
versión anterior de este documento lo presentaba como si fuera la validación del
proyecto; era el hueco más caro que tenía.

### 8.3 Qué comprueba cada guard

**La lista viva sale de correr `bash quality/guards.sh`** (los encabezados `── Gn ·`), no
de `--help`: `--help` solo documenta uso, flags y dependencias. La cabecera del propio
script (`quality/guards.sh`, bloque "LOS N CHEQUEOS") lleva la misma lista.
Hoy son **9** [V 2026-08-04]; si el número no cuadra con lo que ves, manda el script.

| ID | Chequeo | Bloquea |
|---|---|---|
| G1 | `helpers.php` en una página HTML (sigue el cierre transitivo de `require`) | sí |
| G2 | `?>` dentro de un comentario **de línea** PHP | sí |
| G3 | Hosts externos fuera de `quality/allowed_hosts.txt` + lista negra de CDNs | sí |
| G4 | Credenciales con valor literal **o posicionales**, `.env.php` en el índice de git | sí |
| G5 | JSON estático inválido (`manifest.webmanifest`, `*.json`) | sí |
| G6 | Sintaxis del JavaScript **inline** de los `<script>` (necesita `node`) | sí |
| G7 | Cadenas `t()` sin traducción en `i18n_en.php` | aviso (`GUARDS_I18N_STRICT=1` lo hace bloqueante) |
| G8 | Migraciones sin `IF NOT EXISTS` | aviso |
| G9 | Una suite declarada en `quality/required_tests.txt` ha desaparecido o está vacía | sí |

**G9 tapa el agujero obvio del gate:** la forma más barata de poner los tests en verde no
es arreglar el fallo, es borrar el test. Sin G9, borrar `tests/unit/search_test.php` deja
`php tests/run.php` en verde con exit 0 y el push pasa. G9 **no** mira el contenido: una
suite vaciada por dentro sigue contando como presente.

#### ⚠️ G4 se endureció el 2026-08-04, y el motivo importa

**Un guard mal calibrado no es una red: es una red con un agujero exactamente donde hace
falta.** G4 llevaba meses en verde mientras `setup/seed_resources.php` contenía la
**contraseña real de la BD de producción en claro**. La regla que tenía exigía que la
palabra clave (`DB_PASS`, `JWT_SECRET`…) estuviera **pegada al valor**:

```php
$db = new PDO('mysql:host=localhost;dbname=…', 'usuario', 'contraseña');   // ← G4 NO lo veía
```

Eso es una credencial **POSICIONAL**: los tres argumentos escritos a mano, sin ninguna
palabra clave que la delatara. Se añadió una cuarta regla (d) que busca el DSN escrito a
mano —`'mysql:…dbname=<literal>'`—, porque las conexiones legítimas del repo componen el
DSN con variables (`$env['DB_NAME']`) y por tanto no lo disparan. Las otras tres reglas de
G4: (a) clave conocida con valor literal, (b) formatos inconfundibles (`AIza…`,
`-----BEGIN … PRIVATE KEY-----`, `AKIA…`, `ghp_…`), (c) hex de 40+ asignado a una
variable.

Dos consecuencias que hay que tener presentes:

1. **La credencial sigue en el historial público** (commit `5b6c1e6`). Ya no está en el
   working tree, pero `git` conserva el objeto y GitHub también. **Un guard nuevo no rota
   una contraseña**: el paso 0 del `docs/RUNBOOK.md` es rotarla, y es independiente del
   deploy.
2. **G4 tiene falsos positivos por diseño.** Marca en bloqueante cualquier línea que
   *parezca* una credencial literal. La lista de exclusiones (`your_`, `GENERATE_`,
   `$variable`, `$(...)`, backticks…) está calibrada para el repo de hoy: `setup/
   server_setup.sh` usa `$JWT_SECRET` (variable) y `setup/tools/backup_db.sh` usa
   `DB_PASS="$(read_env DB_PASS)"`, que **lee** el secreto de `.env.php` en vez de
   contenerlo. Si G4 se pone rojo, la respuesta es arreglar la línea o justificar la
   excepción — **nunca `--no-verify`**.

`G2` no es reemplazable por `php -l`: **`php -l` no detecta el cierre en comentario**
(verificado). Los comentarios **de bloque** `/* ?> */` son seguros y no se marcan.

**Los baselines son parte del diseño, no un parche.** Sin ellos la capa 1 nacería
con más de 20 fallos sobre código correcto y el gate se desactivaría en una semana.
Son cuatro ficheros de datos en `quality/`; los tres primeros solo pueden encoger, el
cuarto (`required_tests.txt`) es lo contrario: solo debería crecer.

- `quality/baseline_html_helpers.txt` — las 7 páginas heredadas que violan la regla #1.
  **Solo puede encoger.** Añadir una línea es exactamente lo que el guard existe para
  impedir.
- `quality/allowed_hosts.txt` — `fonts.googleapis.com` (**12 páginas** [V 2026-08-04:
  `git grep -l fonts.googleapis.com -- '*.php' | wc -l`]; el comentario del propio
  baseline dice 13, y ese 13 sale de contar también este AGENTS.md), `fonts.gstatic.com`
  (en la lista blanca por precaución: **ningún fichero del repo lo nombra**, lo pide el
  CSS que sirve Google Fonts en runtime), `accounts.google.com` y `oauth2.googleapis.com`
  (Google Sign-In, no auto-alojable), dominio propio. La regla "cero CDNs" se cumple
  para lucide, no para las fuentes.
- `quality/i18n_ignore.txt` — literales idénticos en inglés (`Embed`, `Python`).
- `quality/required_tests.txt` — suites y piezas del gate que G9 exige que sigan
  existiendo: el runner, las unitarias, las de integración, los fixtures y el propio
  `pre-push` (`grep -vcE '^\s*#|^\s*$' quality/required_tests.txt` para el recuento vivo).
  Si borras un test porque su código ya no existe, borra su línea **en el mismo commit**.
  G9 solo comprueba que lo listado exista: `quality/verify_deploy.sh` y
  `tests/integration/_runner.php` aún no están listados, y eso es una laguna de
  cobertura, no una incoherencia.

### 8.4 Lo que NINGUNA capa valida

- **`.htaccess`**: solo es comprobable bajo LiteSpeed, en el servidor real. Rewrites,
  bloqueos y la regex de CORS no se pueden probar en local. Es el argumento decisivo a
  favor de montar staging (`docs/RUNBOOK.md §8.6`).
- **JSON-LD inline** (`resource/index.php`, `profile/index.php`): mezcla condicionales
  PHP que emiten estructura, así que ninguna sustitución textual produce JSON válido.
  Solo se puede validar sobre la página renderizada (capa 6).
- **La base de datos**: los guards protegen el código. Una migración mala sigue siendo
  irreversible; lo que sí existe ya es un backup diario **verificado** (§8.8).
- **Que el cron esté realmente siendo invocado**: nada del repo puede comprobarlo. Lo más
  cerca que se llega es el latido de §8.6, y ese solo se ve **después** del deploy.

~~**Qué commit está vivo**~~ — **resuelto el 2026-08-04** (§8.5): `api/health.php` publica
`commit`, y `quality/smoke_test.sh` lo compara con el HEAD local. `bash
quality/verify_deploy.sh` (sha256 de cinco assets estáticos) sigue siendo el respaldo para
cuando `commit` viene `null`.

### 8.5 Deploy verificable: el hook `post-receive` [V 2026-08-04]

`setup/hooks/post-receive` es la **copia versionada** del hook que vive en el repo bare del
servidor. Está en el repo para que exista revisión, historia y `bash -n`: hasta hoy el hook
solo existía en el servidor, en un fichero que nadie miraba, y estuvo **MUERTO un mes
entero** porque tenía permisos 644 y git ignora los hooks no ejecutables. Nadie se enteró:
los push seguían saliendo bien y el sitio servía código viejo.

Qué hace, en orden:

1. Lee por stdin las refs recibidas y **solo despliega `refs/heads/main`**: un push de otra
   rama o de una etiqueta no puede machacar producción. Si la rama se ha **borrado**
   (`newrev` todo ceros), no toca el doc root.
2. `git checkout -f main` sobre el doc root.
3. Escribe `deploy_version.txt` (commit, commit_full, deployed_at, branch, subject) de
   forma **atómica** (`.tmp` → `mv -f`). Todo lo de este paso es **informativo**: si falla,
   el deploy sigue siendo válido y el hook lo dice explícitamente en vez de fingir un
   error.
4. Imprime lo que ha hecho. Git antepone `remote:` a esas líneas, así que el resultado se
   ve en la terminal de quien empuja.

⚠️ **El destino NO está escrito en el hook, y es deliberado:** este repositorio es público.
Sale de `$DEPLOY_TARGET` → `git config deploy.worktree` → `git config core.worktree`, en
ese orden. **Si ninguna está definida, el hook ABORTA e imprime la orden que falta.** No
adivina: un `checkout` a un destino inventado es peor que no desplegar. Consecuencia
operativa: **el `git config deploy.worktree` va ANTES de copiar el hook**
(`docs/RUNBOOK.md §4`), o el primer push falla.

### 8.6 Latidos de cron [V 2026-08-04]

**El link checker dejó de correr el 2026-05-30 y nadie lo supo en 66 días.** No falló
ruidosamente: simplemente dejó de ser invocado. No había logs, ni una fila en ninguna
tabla, ni nada que envejeciera de forma visible. La única huella era
`MAX(link_checked_at)` en `resources`, que hay que salir a buscar a mano sabiendo ya que
el problema existe.

`setup/migration_010_cron_heartbeats.sql` convierte "no ha pasado nada" en un dato
observable. **Una fila por job, no un histórico** (`PRIMARY KEY (job)`): un log crecería y
necesitaría una purga… que sería otro cron, o sea otra cosa que puede morir en silencio.
Los contadores acumulados (`run_count`, `error_count`) y las marcas
`last_ok_at`/`last_error_at` conservan lo poco del histórico que de verdad se consulta.

`cron/run.php` registra el latido de cada job **sin cambiar su contrato**: la salida JSON
es la misma que consume cron-job.org. Tres garantías escritas en el código:

- Late **también al fallar**, y también cuando no había nada que hacer (si no, un job al
  día parecería muerto justo cuando está al día).
- Hay un `register_shutdown_function` que late si el job murió sin llegar a ningún
  `_heartbeat()` (timeout de PHP, fatal): sin él, ese caso no se distinguiría de "no
  invocado".
- **Un fallo al registrar el latido NUNCA tumba el job.** Si la migración no está aplicada
  todavía —que es el orden normal, el código se despliega antes— el job hace su trabajo
  igual.

Los periodos esperados viven en `IAREPO_JOB_PERIODS` (`cron/run.php:87`) y los siembra la
propia migración: `link_check` = 21600 s (6 h), `moderation` = 120 s (2 min). Es
**configuración, no verdad**: cambiarlo aquí no cambia la planificación real, que está en
cron-job.org.

`api/health.php` publica la antigüedad (§4.5) y `quality/smoke_test.sh` la pone en rojo
cuando supera el periodo del job **por 3** (sección «Latidos de los cron», dos checks:
`check_cron_job link_check 21600` y `check_cron_job moderation 120`). El factor 3 da margen
a un retraso puntual del planificador sin tragarse un job realmente parado.

### 8.7 CI en GitHub [V 2026-08-04]

`.github/workflows/ci.yml`. El gate vivía **solo** en `.githooks/pre-push`, es decir: en la
máquina de quien empuja, y solo si esa persona corrió `make hooks`. Un clon nuevo, otro
ordenador o un `git push --no-verify` desactivaban la única barrera entre un error de
sintaxis y producción. **Esta CI es la segunda red, y `--no-verify` no la salta.**

Dos trabajos en `ubuntu-24.04`, disparados por push a **cualquier** rama, por PR y a mano
(`workflow_dispatch`):

| Trabajo | Qué corre | Equivalente local |
|---|---|---|
| `gate` | `make lint` · `make guards` · `make test` | `make check` |
| `integracion` | `make integration-ci` contra `mariadb:11.8` real | `make integration` |

Lo que **no** hace, a propósito: no usa ningún secreto (`permissions: contents: read`), no
toca producción (ni SSH, ni `curl` a iarepo.com, ni deploy), no publica artefactos ni
escribe en el repo. Solo dice sí o no; el despliegue sigue siendo `git push origin main`.

**Cero acciones de terceros salvo `actions/checkout`.** El runner ya trae PHP 8.3, que es
la misma rama que producción, así que instalar `shivammathur/setup-php` añadiría un tercero
con acceso al checkout a cambio de nada. La regla "cero dependencias" es sobre el
**producto**; la CI es andamio, pero se le aplicó el mismo criterio.

Tres trampas que la CI ya tiene resueltas y que conviene no reintroducir:

1. **`PHP_ESPERADO: '8.3'`** — si GitHub sube el runner a otra rama, el trabajo falla **a
   propósito** en vez de validar en silencio sobre un PHP que no es el de producción.
2. **`cmd | head -1` con `pipefail`** devuelve 141 (SIGPIPE) de forma intermitente y tumba
   el paso. Se usa `sed -n 1p`, que consume toda la entrada. Lo mismo con
   `php -m | grep -q`: se vuelca `php -m` a una variable y se busca con here-string.
3. **Un SKIP es un verde falso.** Sin `pdo_mysql`, sin Docker o con el checkout
   incompleto, la suite de integración se **salta** e imprime "en verde" con exit 0. Por
   eso hay comprobaciones de entorno que fallan el trabajo, y una verificación
   **independiente de cualquier cadena de texto**: que exista
   `/var/lib/mysql/$IAREPO_TEST_DB_NAME` **dentro** del contenedor.

### 8.8 Backup de la BD [V 2026-08-04, ensayado con restauración real]

`setup/tools/backup_db.sh` (755). Dump diario a `~/iarepo-backups/db/<fecha>/`,
**independiente** del backup de Campus: no lee, no escribe y no importa nada de
`~/backups/`.

- **Credenciales**: se leen de `.env.php` con `php` y se pasan a `mysqldump` por un
  fichero de opciones temporal con permisos 600. `MYSQL_PWD` sería visible en `/proc` para
  otros procesos.
- **Cinturón anti-Campus**: si `DB_NAME` no contiene `resources`, **ABORTA**. Este script
  no puede tocar otra base.
- **Escritura atómica**: el dump se escribe siempre en `.partial` y solo se renombra si
  pasa las verificaciones, para que nunca quede en su sitio un fichero a medias que
  *parezca* el backup del día.
- **Verificación, que es lo que convierte un dump en un backup**: `gzip -t`; el marcador
  `-- Dump completed` en las últimas líneas (**el tamaño no es señal fiable** — una BD
  pequeña da un dump pequeño y legítimo, se aprendió ensayando); y tantos `CREATE TABLE`
  como tablas tenga la BD **viva**, que es lo que detecta el volcado parcial por permisos.
- **Retención** 14 días; **tar semanal** de `thumbnails/` los domingos (no están en git).
- Se instala por el cron de **hPanel**: ⚠️ en este hosting **no hay comando `crontab`**.

Restauración ensayada de verdad [V 2026-08-04]: 18/18 tablas, filas idénticas, FULLTEXT
preservado. (Son **18** y no las 19 de §2.2 porque `cron_heartbeats` la crea la
`migration_010`, que aún no está aplicada en producción: el script cuenta las tablas de la
BD **viva**, no las de `setup/*.sql`. Tras aplicarla, lo correcto es que diga 19/19.)

---

## 9. Seguridad

| Capa | Estado real [V 2026-08-04] |
|---|---|
| JWT | HMAC-SHA256 propio, `shared/jwt.php`. Secreto **compartido** con Campus |
| Google OAuth | `GOOGLE_CLIENT_ID` en `.env.php` |
| CORS | `.htaccess:78-79`: `^https://([a-z0-9-]+\.)?claseprivada\.com$` + `iarepo.com`/`www.iarepo.com`, más `ALLOWED_ORIGINS` |
| Viewer | `html`/`embed`: `sandbox` sin `allow-same-origin`. `url`: **sin `sandbox`** (§6.4) |
| SQL | PDO con prepared statements, sin emulación |
| `.env.php` | `<FilesMatch>` → `Require all denied` (`.htaccess:24-26`) |
| `setup/` | `RewriteRule ^setup(/|$) - [F,L]` (`.htaccess:35`) — el `<Directory>` **ya no está**, ver abajo |
| `shared/` | `RewriteRule ^shared/ - [F,L]` (`.htaccess:43`) |
| `*.sql` | `<FilesMatch "\.sql$">` → denied (`.htaccess:62-64`) |
| `admin/` | **bloqueado EXCEPTO dos ficheros** (`.htaccess:39-40`, ver abajo) |
| `tests/` `quality/` `docs/` `Makefile` | `RewriteRule ^tests(/|$) - [F,L]` y hermanas, `.htaccess:51-54` |
| `.git*` | `RewriteRule ^\.git - [F,L]` (`.htaccess:59`) — cubre `.githooks/`, `.gitignore` y un eventual `.git/` |
| `cron/` | **NO se bloquea, a propósito**: `cron/run.php` lo invoca un scheduler externo por HTTPS y se autentica con `CRON_SECRET` |

**Corrección importante:** `/admin/` **no** está bloqueado del todo. `.htaccess:39-40`
dice literalmente `RewriteRule ^admin/(errors|create)\.php$ - [L]` **antes** de
`RewriteRule ^admin/ - [F,L]`. Es decir: `admin/errors.php` y `admin/create.php` son
accesibles desde internet y se defienden **solo** con `ADMIN_PASS`. Cualquier fichero
nuevo bajo `admin/` sí queda bloqueado. La versión anterior de este documento afirmaba
un 403 total: era falso, y podía llevar a añadir un `admin/loquesea.php` creyéndolo
protegido, o a "arreglar" el visor de errores rompiéndolo.

### ⚠️ La bomba dormida del `<Directory>`: DESACTIVADA [V 2026-08-04]

`.htaccess` tenía un bloque `<Directory "setup">`. **No es sintaxis válida dentro de un
`.htaccess`**, y no es un detalle cosmético: verificado contra un **httpd 2.4 real con
mod_rewrite**, con ese bloque Apache devuelve **500 en TODAS las rutas del sitio**
(`<Directory not allowed here`) — 24/24 rutas probadas, no solo la bloqueada.

Sobrevivía porque LiteSpeed lo ignora y quien hacía el trabajo era el `RewriteRule` de
respaldo. Ya no está: el bloqueo lo hace **solo** `RewriteRule ^setup(/|$) - [F,L]`
(`.htaccess:35`). El `(/|$)` cubre también `/setup` sin barra, antes de que `mod_dir`
redirija.

⚠️ **Aviso honesto que debe quedar escrito: la corrección se probó en Apache, NO en
LiteSpeed**, que es lo que corre producción. Lo que se sabe con certeza es que el bloque
viejo era una bomba (Apache) y que el idioma nuevo es exactamente el mismo que ya usaban
`shared/` y `admin/` bajo LiteSpeed hoy. Eso es inferencia fuerte, no una comprobación:
`.htaccess` sigue siendo lo que **ninguna capa valida** (§8.4) y el argumento decisivo a
favor de staging.

### Superficies servidas por HTTP [V 2026-08-04]

`.htaccess:51-59` bloquea `tests/`, `quality/`, `docs/`, `Makefile` y todo lo que
empiece por `.git`, con el mismo idioma `RewriteRule ^dir(/|$) - [F,L]` que usa `setup/`.
**No se usa un comodín para todos los ficheros ocultos** porque `/.well-known/` (ACME,
renovación del certificado) tiene que seguir sirviéndose.

⚠️ **Esas reglas NO están en producción todavía**, porque ni ellas ni los directorios que
protegen se han empujado. Estado real hoy [V 2026-08-04, `curl -s -o /dev/null -w
'%{http_code}'`]:

| URL | Hoy | Por qué |
|---|---|---|
| `/AGENTS.md`, `/README.md` | **200** | **A propósito** (convención `AGENTS.md`/`llms.txt`). No se bloquean |
| `/docs/RUNBOOK.md`, `/tests/run.php` | **404** | Aún sin commitear: por eso hoy no están expuestos |

**Consecuencia operativa, y es la que importa:** `.htaccess` y los directorios nuevos
van en el **mismo commit**, o primero el `.htaccess`. Si `tests/` o `quality/` se
despliegan en un push y el `.htaccess` en otro posterior, hay una ventana en la que se
sirven. Los `.php` de `tests/` llevan un guard `PHP_SAPI !== 'cli'` como mitigación, pero
la defensa correcta es la regla.

`quality/smoke_test.sh` tiene una sección «Exposición de ficheros de desarrollo» con 8
checks que lo verifican **tras** el deploy: 403 → PASS, 200 → FAIL, 404 → INDETERMINADO
(el directorio aún no está desplegado, así que el check no demuestra nada; contarlo como
PASS sería peor, porque el día que se desplegara sin bloqueo el 200 pasaría inadvertido).

**Lo que NO se hizo, a propósito:** un `<FilesMatch "\.(md|sh|…)$">` global habría tumbado
`AGENTS.md`, `CLAUDE.md` y `README.md`, que se publican deliberadamente. Y ninguna regla
usa `<Directory>`: ver arriba por qué eso da 500 en todo el sitio bajo Apache.

### Fuga conocida sin cerrar: `level`

`api/resources.php` guarda `level` con `sanitize()` (trim + substr), **sin lista
blanca**, al crear y al actualizar. `index.php` lo filtra en el cliente antes de
convertirlo en clase CSS, pero la causa raíz sigue: cualquier otra superficie que pinte
`level` sin filtrar vuelve a ser vulnerable.

### Fuga CERRADA: el mensaje de la excepción en los 500 [2026-08-06]

Cuatro endpoints devolvían al cliente el error crudo del driver:

```php
json_error('Fork failed: ' . $e->getMessage(), 500);   // api/resources.php (fork)
json_error('Update failed: ' . $e->getMessage(), 500); // api/resources.php (update)
json_error('Like failed: ' . $e->getMessage(), 500);   // api/likes.php
json_error('Failed: ' . $e->getMessage(), 500);        // api/usage.php
```

Un `$e->getMessage()` de MariaDB lleva **nombres de tabla, nombres de columna y
fragmentos de la consulta**, y en el camino de `update` puede arrastrar trozos de lo que
el propio usuario acaba de enviar. Bastaba con provocar una excepción para leerlo. Era
ruidoso hacia fuera y **mudo hacia dentro**: nada quedaba registrado.

Hoy los cuatro registran el detalle con `api_log('error', …)` y responden un mensaje
genérico con código (`FORK_FAILED`, `UPDATE_FAILED`, `LIKE_FAILED`, `USAGE_FAILED`).

⚠️ **Al sanear un `catch`, registra siempre antes de responder.** Quitar el mensaje sin
loguearlo convierte la fuga en un fallo invisible — que es exactamente lo que pasó con el
latido de cron, donde un `try/catch` mudo se tragó un `1267 Illegal mix of collations`
durante semanas.

El patrón lo vigila `tests/unit/usage_signal_test.php`, que **barre todo `api/*.php`** (no
sólo los cuatro corregidos): es una clase de fallo, y el siguiente `catch` que alguien
escriba copiará el que tenga más cerca. El test descarta comentarios antes de auditar, así
que documentar el patrón malo —como hace este párrafo— no lo pone en rojo.

---

## 10. Configuración (`.env.php`)

`.env.php` **no está en git**. La plantilla versionada es `.env.php.example`, que
declara 11 claves [V 2026-08-04]:

```
DB_HOST  DB_NAME  DB_USER  DB_PASS
JWT_SECRET          — debe coincidir EXACTAMENTE con el de Campus; si no, todos los tokens fallan
GOOGLE_CLIENT_ID
ADMIN_PASS          — protege admin/create.php y admin/errors.php
CRON_SECRET         — autentica cron/run.php?job=…&token=…
OPEN_REGISTRATION   — activa moderación, rate limits y comprobación de similitud
DEBUG               — trazas en las respuestas de error de la API. NUNCA en producción
ALLOWED_ORIGINS     — array, se suma a los orígenes por defecto del .htaccess
```

**Clave fantasma:** `MAIL_FROM` se lee en `shared/mailer.php:49` pero **no está en
`.env.php.example`**. Sin ella se usa el fallback `iarepo <noreply@iarepo.com>`.
Debería declararse en la plantilla.

---

## 11. Convenciones de código

- **PHP 8**: `match()`, tipos de retorno, `never`. Nada de Composer.
- **PDO con prepared statements siempre.** Sin emulación (`shared/db.php`).
- Helpers globales de API: `h()`, `json_ok()`, `json_error()`, `sanitize()`,
  `json_body()`, `request_method()`, `clientIp()`, `rateLimit()`.
- Middleware: `authenticate()`, `requireAuth()`, `requireRole()`, `getSessionUser()`.
- Transacciones para operaciones multi-tabla, con `rollBack()` en el `catch`
  (`cron/run.php:284-347` es el ejemplo bien hecho).
- Vanilla CSS y Vanilla JS. Nada de Tailwind. Dark mode base del viewer:
  `#0f172a` / `#1e293b` / `#e2e8f0`.
- Endpoints nuevos: `cors()` como primera llamada.
- **No hay `php-cs-fixer` en este repo** (`ls .php-cs-fixer*` → no existe). La versión
  anterior lo afirmaba apuntando a la configuración de otro repositorio. Sigue el estilo
  del fichero que estés tocando; lo único que se comprueba de verdad es
  `quality/guards.sh`.

---

## 12. Relación con Campus

Unidireccional. Campus firma un JWT con el secreto compartido; el payload lleva
`user_id`, `name`, `role`, `tenant_id`, `tenant_name`, `areas[]`. Llama a la API de
iarepo con `Authorization: Bearer <token>`. iarepo valida, procesa y **denormaliza** la
identidad en la fila. iarepo nunca llama a Campus.

En Campus el secreto vive como `resources_jwt_secret` [S: es otro repositorio, no
comprobable desde aquí; confírmalo antes de tocarlo]. **Cambiar `JWT_SECRET` exige un
cambio coordinado en los dos `.env.php`**; si no coinciden, todos los tokens fallan.

Caracterización relevante [V 2026-08-04, `tests/unit/jwt_test.php`]: `jwt_decode`
rechaza un payload `{}` aunque la firma sea válida, y **acepta indefinidamente un token
sin claim `exp`**. `jwt_encode` siempre inyecta `iat`/`exp`, así que hoy es inalcanzable
desde iarepo — pero Campus firma sus propios tokens.

---

## 13. Deuda técnica abierta

Ordenada por lo que más duele. El procedimiento de cada una está en `docs/RUNBOOK.md`.

1. 🔴 **La contraseña de la BD de producción está PUBLICADA en el historial de git**
   (commit `5b6c1e6`, `setup/seed_resources.php`). Ya no está en el working tree y G4 la
   detectaría hoy (§8.3), pero **el objeto sobrevive en el repo bare y en GitHub, que es
   público**. Rotarla es el **paso 0** de `docs/RUNBOOK.md`, es **urgente** y es
   **independiente del deploy**. **P0.**
2. ~~**No hay backup verificado de la BD**~~ — **RESUELTO el 2026-08-04** (§8.8):
   `setup/tools/backup_db.sh`, con restauración ensayada de verdad (18/18 tablas, filas
   idénticas, FULLTEXT preservado). Lo que **sigue pendiente** es instalarlo en el cron de
   hPanel: hasta entonces el script existe y no corre.
3. ~~**Deriva de esquema**~~ — **RESUELTA el 2026-08-04** (§2.4) [V: `php tests/run.php
   --integration` en verde, 0 errores SQL al reconstruir]. Quedan dos
   flecos que NO bloquean el deploy: el ENUM real de `moderation_status` en producción
   sigue sin verificar (sólo importa el día que se abra el registro) y no hay tabla de
   registro de migraciones aplicadas.
4. ~~**`php tests/run.php` en rojo**~~ — **RESUELTO el 2026-08-04** [V: `php
   tests/run.php` → exit 0, sin ningún test en rojo]. Se deja el caso escrito porque
   la clase de bug se repite: `iarepo_tokenize()` deduplica con `$out[$t] = true` y PHP
   convierte a `int` las claves numéricas, así que `iarepo_build_search('2024 examen')`
   devolvía `terms = [2024, 'examen']` y el JSON de la API salía con tipos mezclados.
   El arreglo es `return array_map('strval', array_keys($out));`
   (`shared/search.php:391`), y `tests/unit/search_test.php` lo fija.

   Recuerda que el hook pre-push es configuración **local por clon**, no del repo:
   compruébalo con `git config core.hooksPath` (si no imprime `.githooks`, **no hay
   gate local** y un rojo no detendría nada — aunque desde 2026-08 la CI de §8.7 sí lo
   vería).
5. ~~**`.gitignore` lista `AGENTS.md` mientras el fichero está trackeado**~~ —
   **RESUELTO el 2026-08-04**: la línea era **inerte** (git no ignora lo ya trackeado) y
   sólo inducía a creer que el fichero era privado. Se quitó, y en su lugar hay un
   comentario que dice explícitamente que `AGENTS.md`, `CLAUDE.md` y `docs/` **sí** viajan
   y **sí** se publican.
6. ~~**`tests/`, `quality/`, `Makefile` y `.githooks/` se sirven por HTTP**~~ —
   **bloqueo escrito en `.htaccess`, pendiente de desplegar** (§9). Los `.md` de la raíz
   (`AGENTS.md`, `CLAUDE.md`, `README.md`) siguen públicos **a propósito**. Sin verificar
   bajo LiteSpeed hasta el push: es el mismo idioma que la regla de `setup/`, que hoy
   funciona, pero eso es inferencia.
7. **El `REGEXP` no está probado contra la MariaDB de producción** (§7.4.1). Desde que
   existen los sinónimos el impacto es **mayor**: ya no son solo las consultas con token
   corto, sino **cualquier consulta cuyo término esté en el diccionario**. Probabilidad
   baja, rollback de dos líneas. Verificar con un `curl` justo tras el deploy.
8. **El cron de `link_check` lleva parado desde 2026-05-30** (66 días al descubrirlo).
   Los latidos (§8.6) hacen que se vea, pero **no lo reactivan**: hay que volver a darlo
   de alta en cron-job.org.
9. **`tenant_id = 0`**: el modelo de visibilidad no tiene semántica útil para los
   profesores externos (§5.2).
10. **`sort=popular` ordena por `use_count`, que es 0 en todo el catálogo**, así que
    "Más usados" del select devuelve un orden arbitrario y contradice a la sección
    destacada de la portada, que usa `use_count*3 + view_count + like_count*2`.
11. **`level` sin lista blanca en la API** (§9).
12. **El listado no filtra `moderation_status`, la portada sí** (§7.4).
13. **`setup/run_migration.php`** parte el SQL con `explode(';', ...)`, que rompe
    cualquier `;` dentro de un literal; no envuelve en transacción, no registra qué
    aplicó y no tiene `--dry-run`.
14. **Sin SPF ni DMARC** para `iarepo.com` (§6.6).
15. **No hay staging.** Es lo único que permitiría probar `.htaccess` bajo LiteSpeed.
16. **La imprecisión por subcadena del término del usuario de 3+ caracteres sigue
    abierta** (§7.4): `ADN` sigue casando "Chladni". El arreglo de precisión cubre los
    tokens de < 3 y los sinónimos, no el término largo del usuario.
17. **`migration_007` y `migration_008` no son idempotentes** (`ADD COLUMN` sin
    `IF NOT EXISTS`): G8 las marca en **aviso**, no bloquea. Como no hay tabla de
    migraciones aplicadas, reejecutarlas falla en vez de no hacer nada.

---

## 14. Errores comunes

| Síntoma | Causa probable | Qué mirar |
|---|---|---|
| Página HTML con un blob `{"ok":false,...}` incrustado | Regla #1: `helpers.php` en una página HTML y saltó una excepción | `quality/baseline_html_helpers.txt`, `shared/error_handler.php:186-188` |
| La página imprime su propio código fuente | Regla #2: `?>` en un comentario de línea | `php quality/lib/analyze.php close-tag <fichero>` |
| El cambio no aparece en el navegador | Caché del service worker | `sw.js`, `assets/js/pwa.js` |
| `401 Unauthorized` | `JWT_SECRET` distinto entre Campus y iarepo | los dos `.env.php` |
| `403 Forbidden` | Rol insuficiente o visibilidad | `requireRole()`, `canView()` |
| CORS bloqueado | Origen fuera de la regex | `.htaccess:78-79`, `ALLOWED_ORIGINS` |
| Health `degraded` | La BD no conecta | credenciales en `.env.php` |
| `429` en el smoke test | 120 GET/min por IP en el listado | `SEARCH_DELAY`, espera un minuto |
| Viewer en blanco | `code_content` vacío | el recurso no tiene contenido |
| Una `<option>` de filtro no devuelve nada | El catálogo no tiene ese valor (p. ej. `lang=pt`) | consulta la BD antes de añadir opciones |
| El 404 no es el de marca | LiteSpeed ignora `ErrorDocument`; falta el catch-all | `.htaccess:70-73` |
| `403` en `/tests/…`, `/quality/…`, `/docs/…` | **Correcto, es el bloqueo nuevo** | `.htaccess:51-59` (§9) |
| **500 en TODAS las rutas del sitio** | Alguien reintrodujo un `<Directory>` en `.htaccess` | `.htaccess` (§9), no es sintaxis válida ahí |
| `health.php` devuelve `commit: null` | El hook no está instalado, no es ejecutable, o no pudo escribir el doc root | `setup/hooks/post-receive` (§8.5), `ls -l hooks/post-receive` |
| `health.php` devuelve `crons: null` | La migración 010 no está aplicada (o la BD no responde) | `setup/migration_010_cron_heartbeats.sql` |
| Un job con `age_seconds: null` | **Nunca ha latido**: declarado pero no invocado | cron-job.org (§8.6) |
| Buscar en español devuelve 0 en un área que sí existe | El recurso está catalogado en inglés y falta el grupo | `shared/search_synonyms.php` (§7.3) |
| Una búsqueda devuelve medio catálogo | Un sinónimo corto y común, o el término del usuario de 3+ por subcadena | §7.4 |

---

## 15. Lecciones aprendidas

Cada una costó descubrirla y ninguna se ve leyendo el código. Están aquí para que no haya
que volver a pagarlas.

### 15.1 Un push que despliega y no avisa de que no ha desplegado

El hook `post-receive` del servidor tenía permisos **644**. Git ignora los hooks no
ejecutables **en silencio**: no hay error, no hay aviso, el push sale bien. Estuvo así
**un mes**. Durante ese mes producción sirvió código viejo, `git log` decía que todo
estaba desplegado y el smoke test daba **44 checks en verde** contra la versión antigua —
porque comprobaba que el sitio funciona, no que sirva lo que tienes delante.

El bit de ejecución se lo llevó por delante la migración de servidor del 2026-07-13, y no
había forma de notarlo desde fuera.

**Lo que se hizo:** el hook está ahora **versionado** (`setup/hooks/post-receive`),
escribe `deploy_version.txt`, `api/health.php` lo publica como `commit`, y el smoke test lo
**compara con el HEAD local**. La pregunta "¿qué commit está vivo?" se responde sin SSH.

**La lección general:** *un canal de despliegue que solo puede fallar en silencio no es un
canal de despliegue.* Todo paso automático necesita dejar un rastro que envejezca de forma
visible. Y `ls -l hooks/post-receive` (que se vea `-rwxr-xr-x`) es parte del procedimiento,
no una comprobación opcional.

### 15.2 Un guard mal calibrado es peor que no tener guard

`setup/seed_resources.php` contenía la **contraseña real de la BD de producción en claro**
y G4 llevaba meses en verde. No era un fallo de implementación: la regla exigía que la
palabra clave (`DB_PASS`) estuviera pegada al valor, y la credencial era **posicional**
(`new PDO($dsn, "usuario", "contraseña")`). El guard hacía exactamente lo que se le pidió;
lo que se le pidió no cubría el caso realista.

Y el push publica por **dos** vías a la vez (producción y GitHub público), así que el
secreto salió por las dos y **git conserva el objeto aunque se revierta**.

**La lección general, en tres partes:**

1. **Un guard verde no es evidencia de ausencia.** Antes de confiar en uno, escribe el
   caso que quieres que atrape y comprueba que lo atrapa. La forma barata: mete
   temporalmente el patrón y mira si se pone rojo.
2. **Sacar el secreto del working tree NO es rotarlo.** Son dos acciones distintas y la
   segunda es la que importa. `docs/RUNBOOK.md` la pone de paso 0, antes que el deploy y
   sin depender de él.
3. **Las listas escritas a mano caducan.** El mismo error de clase tumbó la detección de
   deriva de esquema (`iframe_blocked`, §2.4): un guard basado en una lista manual está
   verde hasta el día en que alguien añade algo, y ese día no avisa.

### 15.3 `lang` no es de fiar (y por extensión: ningún campo de catalogación lo es)

La columna `resources.lang` dice `es` en 371 filas y `en` en 192. Entre los títulos
marcados **`es`**, los términos más frecuentes son `mechanics`, `electromagnetism`,
`waves`, `law`, `motion`, `quantum`, `chemistry`. Los tags están duplicados por idioma
(`simulation` 188 / `simulación` 96, `interactive` 248 / `interactivo` 93). Y
`subject_area` está normalizado **a inglés**, así que buscar "biología" devolvía **cero**
en un catálogo con 37 recursos de biología.

**Consecuencias prácticas, las tres verificadas:**

- **No filtres por `lang` para resolver un problema de idioma.** La solución fue expandir
  la **consulta** (§7.3), no acotar el conjunto.
- El filtro `?lang=` de la API sigue existiendo y sigue siendo tan poco fiable como la
  columna. No lo uses como fuente de verdad ni lo pongas de defecto.
- **Antes de añadir una `<option>` a un filtro, consulta la BD.** Un valor que el catálogo
  no tiene (`lang=pt`) produce una opción que siempre devuelve cero, y eso parece un bug
  del buscador.

**La lección general:** los campos que rellena un humano (o un seed de hace meses) son una
**pista**, no un dato. Cualquier lógica de producto que dependa de ellos hereda su
fiabilidad. Mídela antes de construir encima.
