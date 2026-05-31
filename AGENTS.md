# 🤖 AGENTS.md — iarepo.com (Resources Platform): Reglas y Contexto para Agentes

> **Lee este archivo COMPLETO antes de tocar cualquier código.**
> Este documento es la fuente de verdad para cualquier agente IA o desarrollador
> que trabaje en este proyecto, desde cualquier máquina.

---

## 1. ¿Qué es este proyecto?

**iarepo.com** (Resources Platform) es un repositorio público de recursos educativos
interactivos — un "GitHub para profesores". Cualquier profesor del mundo puede
registrarse, subir y compartir recursos (HTML interactivo, embeds, URLs, Python, etc.).

- **Producto:** iarepo — Repositorio abierto de recursos educativos interactivos
- **Marca:** iarepo (dominio: `iarepo.com`, activo en producción)
- **Dominio alias:** `resources.claseprivada.com` (también activo)
- **Stack:** PHP 8+, MySQL/MariaDB, Vanilla JS — Zero dependencias (sin Composer, sin npm)
- **Hosting:** Hostinger VPS (mismo servidor que Campus)
- **Catálogo:** 543+ recursos activos aprobados (PhET, NASA, Walter Fendt, GeoGebra, Desmos, ToyTheater, Didax, etc.)
- **Auth:** Google Sign-In (registro directo) + JWT (Campus)
- **Relación con Campus:** Independiente. Campus se conecta vía JWT API para consumir recursos.

---

## 2. Visión y Estrategia

### Principio fundamental:
```
Plataforma PÚBLICA  →  Cualquier profesor del mundo  →  Colegios la aprovechan vía API
```

### ¿Por qué público?
- Si dependiera solo de los tenants de Campus, el crecimiento sería lento
- Abierto al mundo → efecto de red → catálogo crece rápido
- Profesores de Campus TAMBIÉN pueden postear aquí sus recursos
- Un profesor independiente de Argentina sube un simulador → un colegio en Lima lo usa en clase
- Es una apuesta de este año — puede funcionar o no, pero la apertura maximiza las chances

### Flujo de uso:
```
┌──────────────────────────┐     ┌─────────────────────────────────┐
│  iarepo.com              │     │  Campus (claseprivada.com/edu)  │
│  ────────────────────    │     │  ──────────────────────────     │
│  • Registro abierto      │     │  • Genera JWT con shared secret │
│  • Cualquier profesor    │◄────│  • Consume API de recursos      │
│  • Sube/busca/forkea     │────►│  • Asigna recursos a aulas      │
│  • Viewer con sandbox    │     │  • Tracking de uso              │
│  • API REST pública      │     │                                 │
└──────────────────────────┘     └─────────────────────────────────┘
         ▲
         │  Registro directo (futuro)
         │
    Profesores del mundo
```

### Dos tipos de usuarios:
| Origen | Cómo accede | Auth |
|--------|-------------|------|
| **Profesor de Campus** | Campus genera JWT → llama a la API | JWT (shared secret) |
| **Profesor externo** | Se registra directo en iarepo.com | Google Sign-In (✅ implementado) |

---

## 3. Arquitectura

### Principio de independencia:
```
⚠️  iarepo NO tiene foreign keys a Campus.
⚠️  Toda la identidad del usuario está DENORMALIZADA en cada registro (copiada del JWT).
⚠️  Si Campus desaparece, iarepo sigue funcionando con su propia base de datos.
```

### Base de datos:
- **DB:** `u403412230_resources`
- **13 tablas**, identidad denormalizada (no hay FKs a Campus):

| Tabla | Función |
|-------|---------|
| `resources` | Contenido principal (título, código, tipo, visibilidad, contadores) |
| `resource_versions` | Historial de cambios (cada update crea un snapshot) |
| `resource_usage` | Tracking de uso (presented, sent, forked, endorsed) |
| `resource_suggestions` | Feedback privado entre profesores |
| `resource_assignments` | Recursos asignados a aulas de un colegio |
| `categories` | Categorías dinámicas (Physics, Math, Chemistry, Biology, etc.) |
| `resource_tags` | Tags libres tipo GitHub topics |
| `users` | Usuarios registrados vía Google Sign-In |
| `resource_likes` | Likes de usuarios a recursos |
| `resource_comments` | Comentarios en recursos |
| `resource_reports` | Reportes de contenido inapropiado |
| `collections` | Colecciones temáticas de recursos |
| `collection_items` | Items dentro de cada colección |

### Campos de identidad denormalizados (en cada tabla):
```sql
author_tenant_id INT       -- 0 si es un profesor externo (sin colegio)
author_user_id INT         -- ID del usuario
author_display_name VARCHAR(150)  -- Nombre visible
author_tenant_name VARCHAR(150)   -- Nombre del colegio (o vacío)
```

### Campos adicionales del recurso:
```sql
lang ENUM('es','en','pt')       -- Idioma del recurso
level VARCHAR(50)               -- Nivel educativo (primary, secondary, ib, university, general)
category_id INT                 -- FK a categories (dinámico)
source_prompt TEXT               -- Prompt original de IA que generó el código (opcional)
view_count INT                  -- Contador de vistas
code_type ENUM('html','url','embed','python','prompt','other')
source_name VARCHAR(150)        -- Nombre de la fuente (PhET, NASA, etc.)
source_url VARCHAR(500)         -- URL de la fuente original
link_status ENUM('ok','broken','timeout','unknown')  -- Estado del enlace (cron)
link_checked_at DATETIME        -- Última verificación de enlace
iframe_blocked TINYINT(1)       -- 1 si el sitio bloquea iframe
```

### subject_area (normalizado a inglés):
```
Physics (327) | Mathematics (138) | Biology (42) | Chemistry (38)
Space & Astronomy (20) | Social Studies (9) | Computer Science (8)
Art & Music (7) | Economics (3) | Languages (3) | Health & PE (3) | General (3)
```

---

## 4. Estructura del Código

```
resources/
├── index.php                ← Landing page + Health check (JSON si Accept: application/json)
├── sitemap.php              ← Sitemap XML dinámico (rewrite: /sitemap.xml)
├── .htaccess                ← CORS, URL rewriting, seguridad (bloquea /setup/, /admin/)
├── robots.txt               ← Robots + AI crawlers
├── llms.txt                 ← Contexto para LLMs
├── .env.php                 ← Credenciales (NO en git)
├── .env.php.example         ← Template de configuración
│
├── api/                     ← 12 endpoints REST
│   ├── resources.php        ← CRUD + fork + versionado (endpoint principal)
│   ├── assignments.php      ← Asignar recursos a aulas
│   ├── suggestions.php      ← Feedback entre profesores
│   ├── usage.php            ← Tracking de uso
│   ├── stats.php            ← Métricas y estadísticas
│   ├── versions.php         ← Historial de versiones
│   ├── likes.php            ← Like/unlike de recursos
│   ├── comments.php         ← Comentarios en recursos
│   ├── collections.php      ← Colecciones de recursos
│   ├── check_similarity.php ← Detección de duplicados
│   ├── og-image.php         ← Generador dinámico de OG images (screenshot → fallback GD)
│   └── health.php           ← Health check JSON
│
├── auth/                    ← Autenticación
│   ├── google.php           ← Google Sign-In callback
│   └── logout.php           ← Cerrar sesión
│
├── shared/                  ← Módulos compartidos
│   ├── auth.php             ← Middleware JWT + session (authenticate, getSessionUser)
│   ├── jwt.php              ← HMAC-SHA256 desde cero (encode + decode)
│   ├── cors.php             ← CORS multi-tenant (cualquier *.claseprivada.com)
│   ├── db.php               ← PDO singleton (getResourcesDB)
│   ├── helpers.php          ← Utilidades (json_ok, json_error, sanitize, h)
│   ├── error_handler.php    ← Error handler global
│   ├── moderation.php       ← Moderación de contenido
│   └── similarity.php       ← Detección de similitud entre recursos
│
├── thumbnails/              ← Screenshots de recursos (NO en git, generado por headless Chrome)
│   └── og-{id}.png          ← 1200×630 PNG — screenshot real del recurso en /view/{id}
│
├── viewer/                  ← Viewer de recursos
│   └── index.php            ← iframe sandbox + fallback para URLs bloqueados + presentación
│
├── resource/                ← Detalle de recurso
│   └── index.php            ← /resource/{id} — preview, likes, comments, similares, share
│
├── dashboard/               ← Panel del profesor (requiere login)
│   ├── index.php            ← Mis recursos
│   └── editor.php           ← Editor de recursos HTML
│
├── profile/                 ← Perfil público
│   └── index.php            ← /profile/{id} — recursos del profesor
│
├── admin/                   ← Admin (bloqueado por .htaccess, requiere auth)
│   └── create.php           ← Formulario de creación rápida
│
├── legal/
│   └── terms.php            ← Términos de uso
│
└── setup/                   ← Setup (bloqueado por .htaccess en producción)
    ├── schema.sql           ← Schema principal
    ├── schema_users.sql     ← Schema de usuarios + social
    ├── migration_*.sql      ← Migraciones incrementales
    ├── seed_*.php/sql       ← Seeds de catálogo (578+ recursos)
    ├── cron_link_checker.php ← Verificador de URLs rotos
    ├── cron_moderation.php  ← Moderación automática
    └── tools/
        └── generate-thumbnails.sh ← Generador de thumbnails con headless Chrome
```

---

## 5. APIs

### Endpoints:

| Endpoint | Método | Auth | Función |
|----------|--------|------|---------|
| `/` | GET | No | Health check (JSON: status, DB, version) |
| `/api/resources.php` | GET | Opcional | Listar recursos (filtros, paginación, fulltext search) |
| `/api/resources.php?id=X` | GET | Opcional | Obtener un recurso específico |
| `/api/resources.php` | POST | JWT | Crear recurso |
| `/api/resources.php?id=X` | PUT | JWT | Actualizar recurso (crea versión automáticamente) |
| `/api/resources.php?action=fork&id=X` | POST | JWT | Forkear un recurso |
| `/api/resources.php?id=X` | DELETE | JWT | Soft-delete (is_active=0) |
| `/api/assignments.php` | GET/POST/DELETE | JWT | Asignar recursos a aulas |
| `/api/usage.php` | GET/POST | JWT | Registrar/consultar uso |
| `/api/stats.php` | GET | JWT | Métricas y estadísticas |
| `/api/suggestions.php` | GET/POST/PUT | JWT | Feedback entre profesores |
| `/api/versions.php` | GET | JWT | Historial de versiones de un recurso |
| `/view/{id}` | GET | Opcional | Viewer visual (iframe sandbox + modo presentación) |
| `/resource/{id}` | GET | Opcional | Detalle del recurso (preview, likes, comments) |
| `/profile/{id}` | GET | No | Perfil público del profesor |
| `/api/likes.php` | GET/POST/DELETE | Session | Like/unlike recursos |
| `/api/comments.php` | GET/POST | Session | Comentarios en recursos |
| `/api/collections.php` | GET/POST/DELETE | Session | Colecciones de recursos |
| `/api/health.php` | GET | No | Health check JSON |
| `/api/og-image.php?id=X` | GET | No | OG image (screenshot real → fallback GD card) |
| `/sitemap.xml` | GET | No | Sitemap XML dinámico (1155 URLs) |
| `/auth/google.php` | POST | No | Google Sign-In callback |

### Auth (dos métodos):
- **JWT** (Campus): `Authorization: Bearer {token}` — para integración programática
- **Session** (Google Sign-In): Cookie de sesión PHP — para usuarios del frontend
- **Lectura** (GET): Opcional. Sin auth solo ve recursos `community`.
- **Escritura** (POST/PUT/DELETE): Auth requerido. Solo roles `teacher`, `admin`, `superadmin`.

### Filtros disponibles en GET `/api/resources.php`:

| Param | Función |
|-------|---------|
| `area` | Filtrar por subject_area |
| `search` | Búsqueda fulltext (título, descripción, tags) |
| `category` | Filtrar por category_id |
| `lang` | Filtrar por idioma (`es`, `en`, `pt`) |
| `level` | Filtrar por nivel (`primary`, `secondary`, `ib`, `university`, `general`) |
| `author_tenant_id` | Filtrar por colegio de origen |
| `visibility` | Filtrar por nivel de visibilidad |
| `sort` | `recent` (default), `popular`, `views`, `title` |
| `page` | Página (default: 1) |
| `limit` | Items por página (10-100, default: 20) |

---

## 6. Modelo de Visibilidad

### Niveles actuales:

| Nivel | Quién ve | Uso típico |
|-------|----------|------------|
| `draft` | Solo el autor | Recurso en desarrollo, borrador privado |
| `area` | Todos los profesores del mismo tenant | Compartir dentro del colegio por área |
| `school` | Todos los profesores del mismo tenant | Compartir con todo el colegio |
| `community` | Todos (público) | Compartir con el mundo |

### Filosofía:
La plataforma fomenta la **colaboración abierta**. El nivel `area` no filtra por
materia — cualquier profesor del mismo colegio puede ver recursos de tipo `area`,
independientemente de su materia. Esto es intencional: promueve la colaboración
interdisciplinaria.

### Regla de visibilidad en `canView()`:
```php
'community' => true,                    // Público para todos
'school'    => same tenant,             // Mismo colegio
'area'      => same tenant,             // Mismo colegio (área es organizativo, no restrictivo)
'draft'     => same tenant + same user, // Solo el autor
```

### ⚠️ Evolución pendiente:
El modelo actual fue diseñado asumiendo usuarios vía JWT de Campus (con `tenant_id`).
Cuando se implemente **registro directo** (profesores externos sin tenant), será
necesario repensar la visibilidad para usuarios con `tenant_id = 0`.

---

## 7. Seguridad

| Capa | Implementación |
|------|----------------|
| **Auth** | JWT HMAC-SHA256 (Campus) + Google Sign-In (frontend) |
| **JWT Secret** | Compartido entre Campus y Resources (en `.env.php` de ambos) |
| **Google OAuth** | `GOOGLE_CLIENT_ID` en `.env.php` |
| **CORS** | Restringido a `*.claseprivada.com` + `iarepo.com` + configurables |
| **Viewer** | iframe con `sandbox="allow-scripts allow-modals allow-popups"` (NO `allow-same-origin`) |
| **URL fallback** | Auto-detección de iframe blocked → botón "Abrir externo" |
| **Embed** | Renderizado dentro de iframe sandbox (no raw HTML) — XSS mitigado |
| **Env** | `.env.php` bloqueado por `.htaccess` (Require all denied) |
| **Setup** | `/setup/` bloqueado por `.htaccess` (RewriteRule → 403) |
| **Admin** | `/admin/` bloqueado por `.htaccess` (RewriteRule → 403) |
| **SQL files** | `*.sql` bloqueados por `.htaccess` (FilesMatch → denied) |
| **SQL** | PDO con prepared statements en TODAS las queries |
| **Uploads** | Límite 10MB (upload_max_filesize) |

### Bugs de seguridad resueltos:
1. ~~XSS en embed~~ → **CORREGIDO** (iframe sandbox)
2. ~~Credenciales en repo~~ → **CORREGIDO** (placeholder)
3. ~~Setup/Admin expuestos~~ → **CORREGIDO** (.htaccess 403)

---

## 8. Deployment

### Estructura del servidor:
```
/home/u403412230/
├── repos/resources.git/           ← Bare git repo
│   └── hooks/post-receive         ← Auto-deploy hook
│
├── domains/claseprivada.com/public_html/resources/  ← ⚠️ DEPLOY TARGET
│   ├── .env.php                   ← Credenciales (NO en git)
│   ├── api/
│   ├── shared/
│   ├── viewer/
│   ├── thumbnails/                ← Screenshots OG (NO en git, persiste entre deploys)
│   └── setup/
│
├── local/                         ← Herramientas instaladas a nivel de usuario
│   ├── node/                      ← Node.js v18 (para Puppeteer en futuro)
│   └── screenshot-service/        ← Puppeteer (pendiente: faltan libs en el servidor)
│
└── .cache/puppeteer/              ← Chrome headless (descargado, no funcional por falta de libatk)
```

### ⚠️ Ruta de deploy:
```
TARGET = /home/u403412230/domains/claseprivada.com/public_html/resources
```
> **CUIDADO:** NO es `domains/resources.claseprivada.com/public_html/`.
> El post-receive hook hace checkout a la ruta de arriba.

### Flujo de deploy:
```
git push origin main  →  post-receive hook  →  checkout -f a public_html/
```

### Git:
```
Repo: ssh://u403412230@145.223.106.219:65002/home/u403412230/repos/resources.git
Branch: main (única rama activa)
```

### URLs:
| Entorno | URL |
|---------|-----|
| Producción | `iarepo.com` (dominio principal) |
| Alias | `resources.claseprivada.com` (también activo) |

### Base de datos:
| DB | Usuario |
|----|---------|
| `u403412230_resources` | `u403412230_ib_ebr` |

---

## 9. Configuración (.env.php)

```php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'u403412230_resources',
    'DB_USER' => 'u403412230_ib_ebr',
    'DB_PASS' => '...',

    // JWT — DEBE coincidir con el valor en Campus .env.php
    'JWT_SECRET' => '...',

    // CORS
    'ALLOWED_ORIGINS' => [
        'https://claseprivada.com',
        'https://staging.claseprivada.com',
    ],
];
```

### Regla:
```
⚠️  El JWT_SECRET DEBE ser idéntico en ambos .env.php (Campus y Resources).
⚠️  Si cambias uno, cambia el otro. Si no coinciden, TODOS los tokens fallan.
```

---

## 10. Convenciones de Código

### PHP:
- **PHP 8+** — usa match(), named args, typed returns, `never` return type
- **PDO** con prepared statements SIEMPRE
- **Sin frameworks** — PHP puro, sin Composer, sin dependencias
- **Funciones helper globales:** `h()`, `json_ok()`, `json_error()`, `sanitize()`, `json_body()`
- **Auth middleware:** `authenticate()` (optional), `requireAuth()` (mandatory), `requireRole()`

### Respuestas JSON:
```php
// Éxito:
json_ok(['resource' => $data]);  // {"ok": true, "resource": {...}}

// Error:
json_error('Not found', 404);   // {"ok": false, "error": "Not found"}
```

### Transacciones:
```php
$db->beginTransaction();
try {
    // ... operaciones ...
    $db->commit();
    json_ok(['message' => 'Done']);
} catch (Throwable $e) {
    $db->rollBack();
    json_error('Failed: ' . $e->getMessage(), 500);
}
```

### CSS / JavaScript:
- Vanilla CSS, Vanilla JS — sin frameworks
- Dark mode (`#0f172a`, `#1e293b`, `#e2e8f0`) como estilo base del viewer
- NO usar Tailwind

### Code style:
- El proyecto usa `php-cs-fixer` (config en el campus principal)
- Casts con espacio: `(int) $value` (no `(int)$value`)
- If de una sola línea: usar bloque de dos líneas:
  ```php
  if (!$resource)
      json_error('Not found', 404);
  ```

---

## 11. Viewer (Renderizado de Recursos)

### Tipos de recurso soportados:

| `code_type` | Renderizado |
|-------------|-------------|
| `html` | iframe con `srcdoc` + sandbox (más seguro) |
| `url` | iframe con `src` + auto-detección de bloqueo + fallback "Abrir externo" |
| `embed` | iframe con `srcdoc` + sandbox (misma seguridad que html) |
| `prompt` | Viewer especial para prompts de IA |
| `python` / `other` | `<pre>` con syntax highlighting básico |

### Fallback para URLs bloqueados:
Si un sitio externo bloquea iframe (X-Frame-Options/CSP), el viewer:
1. Intenta cargar el iframe normalmente
2. Si falla → muestra card con botón "🚀 Abrir [fuente]"
3. Siempre muestra botón flotante "↗ Abrir externo" (esquina inferior derecha)

### Modo presentación:
- `/view/{id}?mode=present` → fullscreen, sin barra superior
- ESC para salir del modo presentación (sincronizado con `fullscreenchange` event)

---

## 11.5. Social Sharing (OG Images + Share Panel)

### Sistema de thumbnails (OG images):
Cada recurso tiene una imagen de preview para redes sociales (og:image) de 1200×630px.

**Estrategia en dos niveles** (`api/og-image.php`):
1. **Screenshot real** → Busca `/thumbnails/og-{id}.png` (captura del recurso con headless Chrome)
2. **Fallback GD** → Si no existe el screenshot, genera un text card con PHP GD (título, descripción, categoría, branding)

### Generación de thumbnails:
```bash
# Desde tu máquina local (requiere google-chrome instalado):
cd resources/
./setup/tools/generate-thumbnails.sh 3 5 100       # IDs específicos
./setup/tools/generate-thumbnails.sh               # Todos los populares
```

El script:
1. Usa `google-chrome --headless` para capturar `/view/{id}?mode=present` a 1200×630
2. Genera un PNG por recurso
3. Lo sube vía SCP al servidor en `/thumbnails/og-{id}.png`

### ⚠️ Headless Chrome en el servidor:
- Node.js v18 instalado en `~/local/node/`
- Puppeteer instalado en `~/local/screenshot-service/`
- **NO funcional en el servidor** — falta `libatk-bridge-2.0.so.0` (no hay sudo)
- Por ahora, los thumbnails se generan desde **la máquina local** y se suben vía SCP

### Fallback GD (fonts):
El servidor tiene DroidSans (no DejaVuSans). El fallback chain es:
```
DejaVuSans-Bold → DroidSans-Bold → DejaVuSansMono-Bold
DejaVuSans      → DroidSans      → DejaVuSansMono
```

### Meta tags en `/resource/{id}`:
```html
<meta property="og:image" content="https://iarepo.com/api/og-image.php?id={id}">
<meta property="og:type" content="article">
<meta property="og:site_name" content="iarepo">
<meta name="twitter:card" content="summary_large_image">
```

### Share panel (frontend):
- **Share FAB** (botón flotante púrpura) → visible en mobile, esquina inferior derecha
- **Botón "Compartir"** inline → visible en desktop, barra de acciones
- **Panel deslizante** con opciones: WhatsApp, Telegram, X (Twitter), Facebook, LinkedIn, Copiar enlace
- **Web Share API** → En mobile, usa el menú nativo del OS si está disponible
- **Toast notification** → Confirma visualmente cuando se copia el enlace

---

## 12. Relación con Campus

### Campus → Resources:
1. Campus genera un JWT con el shared secret
2. El JWT contiene: `user_id`, `name`, `role`, `tenant_id`, `tenant_name`, `areas[]`
3. Campus llama a la API de Resources con `Authorization: Bearer {token}`
4. Resources valida el token y procesa la petición

### Resources → Campus:
- Resources NO llama a Campus. Es unidireccional.
- La identidad del usuario queda denormalizada en Resources (copiada del JWT).

### Integración en Campus:
```
Campus .env.php:
  'resources_jwt_secret' => '...'  ← Mismo valor que Resources .env.php JWT_SECRET
```

---

## 13. Roadmap — Estado Actual y Pendientes

### ✅ Completado:
- [x] API REST completa (CRUD + fork + versionado + likes + comments + collections)
- [x] JWT auth desde cero (HMAC-SHA256)
- [x] Google Sign-In (registro directo de profesores)
- [x] Dominio iarepo.com conectado y activo
- [x] Landing page + catálogo con filtros (idioma, nivel, categoría, búsqueda)
- [x] Resource cards con favicons y badges de idioma
- [x] Viewer con iframe sandbox + fallback para URLs bloqueados
- [x] Modo presentación (fullscreen sincronizado)
- [x] Resource detail page (preview, likes, comments, similares)
- [x] Perfil de profesor (página pública)
- [x] Dashboard (mis recursos + editor)
- [x] Likes y comentarios
- [x] Sistema de visibilidad (draft/area/school/community)
- [x] Tracking de uso (presented, sent, forked, endorsed)
- [x] Feedback entre profesores (suggestions)
- [x] Asignación de recursos a aulas
- [x] Historial de versiones automático
- [x] Búsqueda fulltext (MySQL FULLTEXT)
- [x] Catálogo de 578+ recursos (15+ fuentes educativas)
- [x] subject_area normalizado a inglés
- [x] CORS multi-tenant (*.claseprivada.com + iarepo.com)
- [x] Deploy automático (git push → post-receive)
- [x] SEO: sitemap.xml dinámico, Open Graph, JSON-LD, robots.txt, og:image
- [x] Seguridad: .htaccess bloquea /setup/, /admin/, .sql, .env.php
- [x] Link checker (cron_link_checker.php)
- [x] Detección de duplicados (similarity.php)
- [x] Social sharing: OG images dinámicas, Share FAB, panel WhatsApp/Telegram/X/FB/LinkedIn
- [x] Thumbnails: screenshots reales con headless Chrome (motor propio, 108+ generados)
- [x] Meta tags: og:image, og:type, twitter:card summary_large_image

### ✅ Completado (2026-05-31):
- [x] **Rate limiting por IP** — `api_rate_limits` table + `rateLimit()` + `clientIp()` en `shared/helpers.php`. Aplicado en todos los endpoints API. Migration: `setup/migration_005_rate_limits.sql`
- [x] **Cron automático** — `cron/run.php` con CRON_SECRET. Jobs en cron-job.org: `link_check` c/6h, `moderation` c/2min. CRON_SECRET en `.env.php` del servidor (no en git)
- [x] **iframe_blocked detection** — link checker detecta `X-Frame-Options: DENY/SAMEORIGIN` y CSP `frame-ancestors`. Resource detail muestra fallback inmediato si `iframe_blocked=1`
- [x] **Colecciones UI** — Modal crear/editar, botones editar/eliminar en dashboard, página `/collection/?id=X`, botón "Guardar en colección" en resource detail
- [x] **Tags UI** — Chip input en editor, tags en cards (máx. 3), tags clickables en resource detail, API lista incluye tags via `GROUP_CONCAT`, PUT actualiza tags
- [x] **Logo SVG oficial** — `assets/img/logo.svg`, `assets/img/logo-icon.svg`, `favicon.svg`. Diseño: ícono "i" con nodos de red, gradiente morado→cyan
- [x] **SEO completo** — JSON-LD `LearningResource` en resource detail, `noindex` en viewer, sitemap limpio (sin `/view/`), `<image:image>` tags, Google Search Console conectado y sitemap enviado
- [x] **Editor empuja hacia IA** — Banner informativo con link a Gemini, opciones reordenadas (HTML con IA primero), placeholders contextuales por tipo
- [x] **Embed code** — Botón "Insertar" en resource detail, modal con 3 tamaños (responsivo/mediano/grande), código iframe listo para Moodle/Google Sites/Notion
- [x] **Landing mejorada** — Sección "Más usados" (server-side, 8 cards PHP, SEO-friendly), sección "Cómo funciona" con Lucide icons para visitantes no logueados
- [x] **Discovery en resource detail** — "Más de [autor]" + "Más en [categoría]" en sidebar, nombre de autor clickeable al perfil
- [x] **Actividad reciente en dashboard** — Feed de likes, forks y comentarios recientes en los recursos del profesor
- [x] **Profile page** — Bug fix (colecciones apuntaban a API), og:tags, JSON-LD Person schema, logo en topbar
- [x] **Smoke tests** — `quality/smoke_test.sh`: 27 checks automáticos (páginas, assets, SEO, APIs, seguridad). Corre con `./quality/smoke_test.sh`, exit 1 si falla algo
- [x] **Error tracking JS** — `shared/error_tracker.php` incluido en todas las páginas. Endpoint `api/log-error.php`, tabla `client_error_log`. Visor: `/admin/errors.php?pass=ADMIN_PASS`
- [x] **Seguridad .htaccess** — `/shared/` ahora bloqueado (retornaba 200 antes)

### ⚠️ Regla crítica — helpers.php en páginas HTML
`shared/helpers.php` carga `shared/error_handler.php` que registra manejadores de excepción que **outputan JSON** y hacen `exit`. Esto **rompe páginas HTML** silenciosamente si ocurre cualquier error.

**Regla:** NUNCA hacer `require_once 'shared/helpers.php'` en páginas que generan HTML (`index.php`, `resource/index.php`, etc.).

En su lugar:
- Definir `h()` localmente: `function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }`
- Incluir `shared/error_tracker.php` directamente (no carga el error handler)
- `helpers.php` solo en endpoints API (`api/*.php`)

### 🔲 Pendiente:
1. **Thumbnails restantes** — 108/543 generados. Ver comando batch abajo.
2. **i18n** — Posponer hasta tracción de usuarios en inglés.
3. **Headless Chrome en servidor** — Falta `libatk-bridge-2.0.so.0`. Workaround: generar local + subir vía SCP.

### Thumbnails — Generación en batch (desde Mac/Linux):
```bash
cd resources/
./setup/tools/generate-thumbnails.sh 3 5 100   # IDs específicos
# El script sube vía SCP a /thumbnails/og-{id}.png en el servidor
```

### Error tracker — Cómo ver errores en producción:
```
https://iarepo.com/admin/errors.php?pass=TU_ADMIN_PASS
```
Muestra errores JS agrupados de los últimos 7 días.

### Smoke test — Cómo correr:
```bash
cd /path/to/resources
./quality/smoke_test.sh              # Testea https://iarepo.com
./quality/smoke_test.sh https://staging.iarepo.com  # Otro entorno
```
**Correr siempre después de un deploy importante.**

---

## 14. Checklist para Cambios

Antes de hacer cualquier cambio, verifica:

- [ ] ¿Usé prepared statements para todas las queries?
- [ ] ¿Validé/saniticé todos los inputs del usuario?
- [ ] ¿Verifiqué roles con `requireRole()` en endpoints de escritura?
- [ ] ¿Usé transacciones para operaciones multi-tabla?
- [ ] ¿No edité `.env.php` en git?
- [ ] ¿Verifiqué sintaxis con `php -l archivo.php`?
- [ ] ¿Las respuestas JSON usan `json_ok()` / `json_error()`?
- [ ] ¿Los nuevos endpoints llaman a `cors()` al inicio?
- [ ] ¿Las páginas HTML NO cargan `shared/helpers.php` (ver regla crítica arriba)?
- [ ] ¿Corrí `./quality/smoke_test.sh` después del deploy?

---

## 15. Errores Comunes

| Error | Causa | Solución |
|-------|-------|----------|
| `401 Unauthorized` | JWT inválido o expirado | Verificar que JWT_SECRET coincide en ambos .env.php |
| `403 Forbidden` | Rol insuficiente o visibilidad | Verificar `requireRole()` y `canView()` |
| CORS blocked | Origen no permitido | Agregar dominio a `ALLOWED_ORIGINS` en .env.php |
| Health check: `degraded` | DB no conecta | Verificar credenciales en .env.php |
| Viewer en blanco | `code_content` vacío | El recurso no tiene contenido |

---

*Última actualización: 2026-05-20*
*Mantenido por: Jackson Smirnov (@claseprivada)*
