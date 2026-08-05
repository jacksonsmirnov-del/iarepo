# 📚 iarepo

[![CI](https://github.com/jacksonsmirnov-del/iarepo/actions/workflows/ci.yml/badge.svg)](https://github.com/jacksonsmirnov-del/iarepo/actions/workflows/ci.yml)

**Repositorio abierto de recursos educativos interactivos.**

Descubre, comparte y ejecuta simulaciones, herramientas y recursos educativos — listos para usar en clase.

🌐 [iarepo.com](https://iarepo.com) · 📜 [Términos de uso](https://iarepo.com/legal/terms.php)

## ¿Qué es iarepo?

iarepo es una plataforma que **agrega y cataloga** recursos educativos interactivos de acceso libre. Funciona como un directorio donde profesores pueden encontrar simulaciones, herramientas y modelos listos para usar en clase.

- 🔗 **Enlaza** a recursos de fuentes como PhET, GeoGebra, oPhysics y más
- 📤 **Permite** a profesores subir y compartir sus propios recursos
- 🔍 **Cataloga** por materia, nivel educativo e idioma
- 🖥️ **Visor integrado** con iframe sandbox y modo presentación fullscreen
- 🔓 **API REST** pública para integrar con cualquier LMS o plataforma

## Stack

- PHP 8+ · MySQL/MariaDB · Vanilla JS
- **Zero dependencias** — sin Composer, sin npm, sin frameworks
- JWT HMAC-SHA256 implementado desde cero
- Google Sign-In para registro de profesores

## Integración continua

Cada `push` y cada pull request pasan por [`.github/workflows/ci.yml`](.github/workflows/ci.yml), que ejecuta dos trabajos en paralelo sobre PHP 8.3 (la misma rama que producción):

- **Gate rápido** — `php -l` de todos los `.php` y `node --check` de los `.js`, los 9 chequeos estáticos de `quality/guards.sh` (helpers.php en páginas HTML, cierres de PHP en comentarios, CDNs, credenciales…) y los tests unitarios. Reproducible en local con `make check`.
- **Integración** — la suite con base de datos real contra **MariaDB 11.8**, reconstruyendo el esquema desde `setup/*.sql` y sembrando `tests/fixtures/seed.sql`. Reproducible con `make integration-ci`, que —a diferencia de `make integration`— se pone en rojo si la suite se salta por falta de Docker en vez de cantar un verde que no prueba nada.
- **Y nada más** — la CI no usa secretos, no toca producción y no despliega: solo dice sí o no. El despliegue sigue siendo `git push origin main`.

## Estructura

```
iarepo/
├── api/               ← REST API (CRUD, fork, versionado, stats)
├── auth/              ← Google Sign-In callback + logout
├── dashboard/         ← Panel del profesor (mis recursos)
├── legal/             ← Términos de servicio y uso
├── viewer/            ← Visor de recursos (iframe sandbox + fullscreen)
├── shared/            ← Auth middleware, JWT, DB, helpers
├── setup/             ← Schema SQL + server setup
└── index.php          ← Landing page + health check
```

## API

| Endpoint | Método | Función |
|----------|--------|---------|
| `/api/resources.php` | GET/POST/PUT/DELETE | CRUD + fork + versionado |
| `/api/usage.php` | GET/POST | Tracking de uso |
| `/api/stats.php` | GET | Métricas y estadísticas |
| `/api/suggestions.php` | GET/POST/PUT | Feedback entre profesores |
| `/api/assignments.php` | GET/POST/DELETE | Asignar recursos a aulas |
| `/api/versions.php` | GET | Historial de versiones |
| `/view/{id}` | GET | Visor con iframe sandbox |

## Setup

```bash
# 1. Clonar
git clone https://github.com/jacksonsmirnov-del/iarepo.git
cd iarepo

# 2. Configurar
cp .env.php.example .env.php
# Editar .env.php con tus credenciales de DB y Google OAuth

# 3. Crear tablas
mysql -u tu_usuario -p tu_base_de_datos < setup/schema.sql
mysql -u tu_usuario -p tu_base_de_datos < setup/schema_users.sql

# 4. Verificar
curl http://localhost/
# → {"status":"ok","service":"iarepo","version":"1.0.0"}
```

## Seguridad

- 🔐 JWT HMAC-SHA256 para autenticación API
- 🔒 iframe sandbox (`allow-scripts`, NO `allow-same-origin`)
- 🌐 CORS configurable
- 🔍 Verificador automático de enlaces (cron)
- 📋 Visibilidad: `draft` → `area` → `school` → `community`

## Atribución

iarepo enlaza a recursos de terceros sin alojar copias. Cada recurso muestra la fuente original con link de atribución. Los recursos externos pertenecen a sus respectivos autores:

- [PhET Interactive Simulations](https://phet.colorado.edu) — CC-BY 4.0
- [GeoGebra](https://geogebra.org) — CC-BY-NC-SA
- [oPhysics](https://ophysics.com) — Libre educativo
- [Physics Simulations](https://physics-simulations.org) — Libre educativo

## Licencia

[MIT](LICENSE) — El código fuente es libre. Los recursos enlazados conservan sus licencias originales.

---

Hecho con ❤️ para profesores del mundo.
