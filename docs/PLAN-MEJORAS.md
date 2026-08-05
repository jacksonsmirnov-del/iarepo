# Plan de mejoras — iarepo

> Escrito el 2026-08-05, al cierre de la sesión que arregló el buscador y montó el
> sistema anti-regresión. Todo lo que hay aquí está **sin empezar**: es la hoja de
> ruta, no un registro de lo hecho. Lo ya hecho vive en `AGENTS.md` y `docs/RUNBOOK.md`.
>
> Orden de la lista = orden recomendado, por valor entregado sobre esfuerzo.

---

## 0. Operación pendiente (bloquea o degrada lo ya construido)

Esto no son mejoras, son cabos sueltos del trabajo ya desplegado. Comandos exactos
en `docs/RUNBOOK.md`.

| # | Qué | Por qué importa |
|---|---|---|
| 0.1 | **Reactivar `link_check` en cron-job.org** | Lleva parado desde 2026-05-30. 312 recursos sin comprobar jamás y 12 con enlace muerto visibles en la ficha. Al reactivarlo se recuperan solos los falsos positivos (BioDigital #120 responde 200 y lleva 3 meses oculto) |
| 0.2 | **Aplicar `setup/migration_010_cron_heartbeats.sql`** | Sin ella los latidos no se guardan y el smoke seguirá en rojo. El código ya degrada sin ella (verificado), así que no corre prisa el mismo día del deploy |
| 0.3 | **Instalar `setup/hooks/post-receive`** | Hasta entonces `api/health.php` no publica el commit vivo y nadie sabe qué versión corre |
| 0.4 | **Alta del cron de backup** en hPanel | La BD sigue sin copia. El script está probado con restauración real |
| 0.5 | **Regenerar 5 miniaturas** (600-604) | Generadas y listas; faltaba subirlas. Las otras 12 no: son de enlaces muertos, primero 0.1 |
| 0.6 | **SPF + DMARC de `iarepo.com`** | No existen. Con la IP emisora nueva, el correo puede ir a spam |
| 0.7 | **Rotar `CRON_SECRET`** | Estuvo en claro en un fichero de configuración local |

---

## 1. Kit de simulación alojado ⭐ la de más impacto

**Problema.** Quien hace un simulador escribe 300 líneas de andamiaje (bucle, sliders,
canvas, ejes) y le queda uno mediocre. El editor de tres paneles da sitio para escribir;
no mejora lo escrito.

**Verificado el 2026-08-05:** un iframe `srcdoc` con `sandbox="allow-scripts"` (origen
opaco, sin `allow-same-origin`) **sí puede cargar y ejecutar** un script alojado en el
propio dominio. Probado con Chrome headless contra un servidor local: llegaron tanto la
petición del script como la que el script hace al ejecutarse. **No hay que tocar el
sandbox.**

**Qué incluye la v1** — las cinco cosas que todos reimplementan, y mal:

1. **Bucle de paso fijo.** Casi todo simulador casero hace `x += v * 0.016` dentro de
   `requestAnimationFrame`: la física depende del monitor del alumno. Es un error de
   corrección, no de estilo.
2. **Panel de parámetros declarativo** — sliders con unidades, play/pausa/reset.
3. **Gráficas** en canvas.
4. **Exportar mediciones a CSV.** La que convierte un juguete en una práctica de
   laboratorio; para IB, con incertidumbres, es la más valiosa.
5. **Estado compartible por URL** — el profesor manda el simulador ya configurado.

**Restricción innegociable.** Los recursos son HTML congelado para siempre: si el kit
cambia, se rompen todos los simuladores publicados. Va **versionado en el nombre**
(`assets/js/sim-kit-1.js`) y no se toca nunca; solo se añade. Un cambio incompatible es
un fichero nuevo. Misma lección que el incidente de lucide en CDN.

**Efecto secundario deseable:** la IA generando contra una API pequeña y documentada
acierta mucho más que generando canvas en crudo — y ese es el diferenciador del producto.

---

## 2. Comprobación al publicar

Reutiliza el pipeline de Chrome headless que ya existe para miniaturas. Al publicar:
carga el recurso, **captura los errores de consola**, hace la captura y avisa al autor si
revienta o sale en blanco.

Un solo mecanismo cubre tres problemas actuales: miniatura que falta, enlace muerto y
simulador roto. Los 12 recursos con captura en blanco detectados hoy se habrían cazado el
día que se subieron.

---

## 3. Historial de versiones (revisiones del mismo autor)

**Ya existe media función y no la usa nadie:** `api/versions.php` está completo y
`resource_versions` guarda contenido, autor y descripción del cambio en cada edición.
Cero consumidores en el frontend.

Falta solo la UI: ver qué cambió y volver a una versión anterior. Barato, y es la mitad
de lo que se pide en el punto 4.

---

## 4. Variantes (los forks, agrupados en la ficha)

**Problema real.** Un fork crea una ficha propia que compite con el original en el
catálogo: hoy "Fork: B.1 Thermal Energy Transfers" aparece como recurso independiente
junto al que copió.

**No fusionar, agrupar.** Conviene no mezclar dos cosas distintas:

- **Revisión** = el mismo autor edita. Historia lineal, hay una versión buena: la última.
  → es el punto 3.
- **Variante** = otro profesor lo adapta. No es "más nueva", es *distinta*, y su autor es
  otro. Meterla en la misma línea temporal dice que la de Ana es posterior a la de Luis,
  cuando son alternativas.

**Diseño propuesto.** El fork sigue siendo su propia fila (hace falta para permisos,
moderación y borrado) y se le añade una raíz común (`root_id` denormalizado, para que
agrupar sea un índice y no una recursión). Entonces:

- Buscador y portada muestran **una tarjeta por concepto**: *"3 versiones · 2 autores"*.
- La ficha lleva selector de variantes con autor y fecha; el simulador carga la elegida.
- El historial del punto 3 vive dentro de cada variante.

**Cuidado:** agrupar cambia los totales del buscador, y los tests de integración los
fijan. Hay que actualizarlos en el mismo cambio o el gate se pone en rojo — que es
justo lo que debe hacer.

---

## 5. Editor avanzado (HTML + CSS + JS)

Hoy ya se puede meter `<style>` y `<script>` dentro del HTML: técnicamente funciona. Lo
que falta es ergonomía — tres paneles en vez de un bloque único.

**Clave para no romper nada:** guardar las tres partes por separado **y** seguir guardando
el documento compuesto en `code_content`. Así el visor, el buscador, las miniaturas, la
API y Campus no se enteran. El editor sencillo sigue igual; el avanzado es una pestaña más.

Con el kit del punto 1 ya hecho, esto se vuelve casi trivial.

---

## 6. URLs con nombre (no aleatorias)

`/resource/604` no tiene problema de seguridad: el catálogo es público y el sitemap ya los
lista. Aleatorizar daría URLs feas y sin señal.

Lo que sí falta es SEO y capacidad de compartir:
`/resource/604-trabajo-mecanico-myp-ib`, aceptando `/resource/604` con redirección
permanente. Ni un enlace existente se rompe.

---

## 7. Calidad de datos

| Qué | Estado medido (2026-08-05) |
|---|---|
| `subject_area` sin normalizar | 22 recursos fuera de las 12 áreas: `Ciencias`(9), `Fisica`(5, sin tilde), `Historia`, `IB`, `IB - Fisica`, `General`(3), 2 vacías. No salen en el filtro de áreas |
| `lang` no fiable | Entre los títulos marcados 'es', los términos más frecuentes son `mechanics`, `electromagnetism`, `quantum`. No usar `lang` para decidir nada |
| Tags duplicados por idioma | `simulation`(188)/`simulación`(96), `interactive`(248)/`interactivo`(93) |
| `like_count` | Columna desnormalizada + `COUNT(*)` en vivo: dos fuentes de verdad. Hoy 0 divergencias, pero es cuestión de tiempo |

---

## 8. Deuda técnica

- **7 páginas heredadas cargan `shared/helpers.php`** (congeladas en
  `quality/baseline_html_helpers.txt`). Sacarlas una a una con el guard como red.
- **`moderation_status` incoherente**: el listado de la API no filtra por él, la portada
  sí. Hoy es latente (moderación apagada); resolverlo **antes** de encenderla, no después.
- **Sin staging.** Todo lo que se despliega se prueba en producción.
- **G4 (secretos)** no caza una contraseña corta con nombre de variable no previsto.
- **`ADN` sigue casando "Chladni"**: el término del usuario de 3+ caracteres se busca por
  subcadena. Es una decisión (da prefijos y acentos), pero tiene coste.
