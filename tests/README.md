# tests/ — capa 2 del sistema anti-regresión

Runner propio, cero dependencias: ni PHPUnit, ni Composer, ni autoload.
Solo `php`. Es la misma regla que el resto del proyecto.

```bash
php tests/run.php                   # unitarios, sin BD  (~0,4 s)
php tests/run.php --integration     # + la suite con BD real
php tests/run.php --filter=search   # solo lo que case con "search"
php tests/run.php --list            # lista los tests sin ejecutarlos
php tests/run.php --verbose         # añade el recuento de subcasos
php tests/run.php --no-color        # sin ANSI (útil en logs y CI)
make test                           # atajo de lo primero
```

Sale con **0** si todo pasa y con **1** si algo falla, si no hay ningún test
que ejecutar, o si la suite muere a medias. Lo ejecuta `.githooks/pre-push`
antes de cada `git push`, y aquí `git push` es producción en vivo.

Estado hoy: **71 tests, 82.399 aserciones, 0,35 s**, y **1 test en rojo a
propósito** (ver más abajo: es un fallo real de `shared/search.php`).
Verificado determinista en 25 ejecuciones seguidas.

---

## Escribir un test

Un fichero de test es un `.php` normal en `tests/unit/` (o
`tests/integration/`) que declara funciones globales `test_*`:

```php
require_once IAREPO_ROOT . '/shared/search.php';

function test_las_busquedas_de_una_palabra_usan_el_indice(): void
{
    assert_eq('hybrid', iarepo_build_search('ondas')['mode']);
}
```

El runner incluye el fichero, detecta las funciones `test_*` que ha
declarado (en orden de declaración) y las ejecuta. **Un test pasa si no
lanza nada.** No hay clases, ni anotaciones, ni `setUp`.

Convenciones:

| Cosa | Regla |
| --- | --- |
| Fichero de suite | `tests/unit/algo_test.php` |
| Fichero de apoyo | empieza por `_` (p. ej. `_helpers.php`) → el runner no lo trata como suite |
| Fichero sin `test_*` | se incluye igual (sus funciones quedan disponibles) pero no aporta tests |
| Nombre del test | `test_lo_que_debe_pasar()`, en español, afirmando el comportamiento |
| Rutas | siempre desde `IAREPO_ROOT`; el runner puede invocarse desde cualquier directorio |

### Aserciones

Las define `tests/run.php` **antes** de incluir nada, así que están
disponibles en cualquier fichero de test sin ningún `require`:

```
assert_true / assert_false          assert_contains / assert_not_contains
assert_eq / assert_neq   (===)      assert_matches / assert_not_matches
assert_null / assert_not_null       assert_count
assert_throws(fn, Clase)            assert_no_output(fn)
test_fail(mensaje)                  test_skip(motivo)
subtest(nombre, fn)                 iarepo_show(valor)   → formatea para el mensaje
iarepo_php_isolated(codigo)         → ejecuta PHP en un subproceso limpio
```

`subtest()` es la pieza que hace útil esta suite: si un subcaso falla **no
aborta el test**, así que un bucle sobre 75 entradas hostiles reporta
todas las que fallan, no solo la primera. El nombre del subcaso sale en el
informe entre corchetes.

```php
function test_ninguna_entrada_rompe_el_sql(): void
{
    foreach (st_corpus() as $etiqueta => $entrada) {
        subtest($etiqueta, static function () use ($entrada): void {
            st_check_invariants(iarepo_build_search($entrada));
        });
    }
}
```

### Los avisos de PHP son fallos

El runner instala su propio manejador de errores: cualquier *warning* o
*notice* durante un test lo pone en rojo, con fichero y línea. Sin eso, un
`preg_replace` que devuelve `null` por UTF-8 inválido pasaría inadvertido y
el test seguiría verde con datos basura. `E_DEPRECATED` no bloquea (depende
de la versión de PHP), pero se lista al final.

Para silenciar algo a propósito, `@` sigue funcionando.

---

## La regla que no se puede saltar: nada de `shared/helpers.php`

**El runner aborta si algún test carga `shared/helpers.php` o
`shared/error_handler.php`.**

No es purismo. `helpers.php:12` hace `require_once error_handler.php`, y
ese fichero registra en el momento de cargarse un `set_exception_handler`
que hace `echo json_encode(...)` y `exit(1)`. Dentro del runner eso
convierte el primer test fallido en un blob JSON y mata el proceso **sin
imprimir el informe**: la suite parecería verde. Es la regla crítica #1 del
proyecto (la que rompe páginas HTML en silencio) aplicada aquí.

Para probar código que vive en `helpers.php` se usa un subproceso:

```php
$r = iarepo_php_isolated("<?php require 'shared/helpers.php'; echo json_encode([sanitize('  x  ')]);");
// $r = ['code' => int, 'out' => string, 'err' => string]
```

`tests/unit/helpers_isolation_test.php` hace exactamente eso, y además
**demuestra** el fallo en vivo en lugar de describirlo.

El mismo truco resuelve el estado que no se puede rebobinar dentro de un
proceso: `lang()` de `shared/i18n.php` guarda el idioma en un `static` que
solo se resuelve una vez, así que los escenarios de idioma (cookie,
`Accept-Language`, valor inválido) van cada uno en su subproceso.

---

## Qué hay cubierto hoy

| Fichero | Cubre | Notas |
| --- | --- | --- |
| `unit/search_test.php` | `shared/search.php` | El grueso. Invariantes sobre un corpus de 72 entradas hostiles + fuzz determinista de 1.500 cadenas, más los 9 casos de la tabla de evidencia reproducida en producción. |
| `unit/jwt_test.php` | `shared/jwt.php` | El contrato con Campus: firma alterada, `alg: none`, `exp` caducado, tokens malformados. |
| `unit/i18n_test.php` | `shared/i18n.php` | Traducción, *fallback*, resolución de idioma y sanidad del diccionario: ninguna clave sin `t()` que la use ni repetida, ninguna excepción de `quality/i18n_ignore.txt` sin uso, y ninguna API que traduzca lee su filtro `?lang=` como idioma de interfaz. |
| `unit/similarity_test.php` | `shared/similarity.php` | Detector de plagio: solo las funciones de texto (las que hablan con PDO son de integración). |
| `unit/helpers_isolation_test.php` | `shared/helpers.php` | `sanitize()`, `h()` y la demostración de la regla #1. |
| `unit/page_errors_test.php` | `shared/page_errors.php` | Un fatal en una página HTML no deja media página (500 limpio con `ref`), los avisos no se enseñan pero se registran, y **las 15 páginas** lo cargan lo primero. Reproduce en un subproceso el `t(T.creating)` que rompió el panel (AGENTS.md §15.4). |
| `unit/labels_test.php` | `shared/labels.php`, `shared/ui.php`, `shared/asset.php`, `ui.js` | Cada categoría sembrada tiene etiqueta y color; niveles con edades; la fuente se deduce del dominio (y de la propia dirección en un `url` sin `source_url`); el sello ignora símbolos sueltos; un solo tema en la portada (PHP y `IA.cover`); `[hidden]` gana en `app.css`; `pwa.js` recibe sus textos traducidos; ningún tipo sale en crudo; `?v=` solo dentro del repo; `IA.esc` escapa la comilla simple (lo ejecuta con `node`). |
| `unit/landing_test.php`, `resource_page_test.php`, `account_pages_test.php`, `lists_profile_test.php` | rediseño 2026-09 | Portada, ficha y visor, cuenta, perfil/listas/Guardados: decisiones de producto que no fallan ruidosamente si se deshacen (umbrales de cifras, nada ordenado por popularidad, «Hacer mi versión» solo en html/embed, perfil de alumno no público…). Estáticos, sin BD. AGENTS.md §6.12. |
| `unit/smoke_markers_test.php` | `quality/smoke_test.sh` | Cada marcador que el smoke busca en una página existe en su fuente: si un rediseño lo quita, el FAIL llegaría con la web ya publicada. |
| `unit/comprehension_test.php`, `fork_lineage_test.php`, `tracking_test.php`, `usage_signal_test.php` | señales de 2026-08-06 | «¿Te quedó claro?», linaje de versiones, beacon de visitas y «lo usé en clase». |
| `integration/render_pages_test.php` | **cada página HTML** | Levanta el sitio con `php -S` (copia temporal de los ficheros, `.env.php` de pruebas, atajo de login que solo existe en esa copia) y la abre como anónimo, alumno y profesor: estado esperado, HTML hasta `</html>`, sin JSON de error, sin avisos de PHP. Rompe una página a propósito y comprueba el 500, la fila en `client_error_log` y `errors_24h` en `health.php`. `IAREPO_TEST_KEEP_SITE=1` conserva la copia y su `server.log` para depurar. |
| `integration/authorization_test.php` | quién ve qué en `api/` | Entra como otro profesor, como alumno y como anónimo y pide lo ajeno a `versions`, `usage`, `assignments`, `comments` y `collections` (un borrador en una lista pública). |
| `integration/account_api_test.php` | POST/PUT de `api/resources.php` | `javascript:`/`data:` como fuente o enlace → 400 con código; un PUT sin fuente no la borra; lista negra al crear y al editar. |
| `integration/api_lang_test.php` | `?lang=` en la API | Filtra, no planta la cookie de idioma, y las etiquetas siguen la cookie de interfaz (AGENTS.md §15.5). |
| `unit/review_fixes_test.php`, `integration/review_fixes_test.php` | revisión 2026-09 | Lo que encontraron tres revisores independientes y ninguna capa veía: CSRF (rol y API de escritura), perfil público de cuentas sin nada publicado, nombres de alumnos en los «Me gusta», original privado visible desde sus versiones, direcciones que PHP y el navegador leen distinto, validación al cambiar solo el tipo, cambio de idioma que tiraba la query, secuencia de una lista desde la ficha, y en la interfaz: «Salir» en escritorio, salto de teclado visible, aviso dentro del diálogo, QR sin pantalla completa, contraste del verde. El unitario ejecuta la lógica pura y mira el fuente; el de integración lo reproduce por HTTP. |
| `integration/site_server.php` | infraestructura | No define tests: la usan los de render, autorización, cuenta e idioma. |
| resto de `integration/` | API + BD real | Buscador, esquema, latidos, señales. Se ejecuta con `--integration`. |

### Las invariantes del buscador

`search_test.php` gira alrededor de `st_check_invariants()`, que se aplica
a **todas** las entradas del corpus y del fuzz. Son las que hacen imposible
repetir el HTTP 500 de `C++`:

1. `substr_count(where, '?') === count(params)` — descuadrarlo es
   `SQLSTATE[HY093]` en producción, no un test rojo.
2. Lo mismo para `score` / `score_params`.
3. **Ni un byte del usuario en el texto del SQL.** El SQL generado solo
   puede contener los caracteres del vocabulario fijo
   (`[A-Za-z0-9_ ,.()'?!*/+=]`). Una comilla doble, un `;`, un `%` o un
   emoji ahí significan interpolación, es decir, inyección.
4. Paréntesis balanceados y comillas balanceadas; los únicos literales
   entrecomillados admisibles son `' '` y `'!'`.
5. La cadena que entra en `AGAINST()` está vacía o casa `IAREPO_FT_SAFE`.
6. Ningún *stopword* sale con `+`: uno solo devuelve cero filas para toda
   la consulta, sin ningún error visible.
7. Todo parámetro de `LIKE` va envuelto en `%…%` y con los comodines del
   usuario escapados con `!`; todo `LIKE ?` lleva su `ESCAPE '!'`.
8. `terms` (que la API devuelve al navegador) solo contiene letras y
   dígitos.

---

## ~~Un test en rojo a propósito~~ — resuelto

`bug_terms_numericos_salen_como_int_en_vez_de_string` demostraba que `iarepo_tokenize()`
devolvía `[2024, 'examen']` (PHP convierte a `int` las claves numéricas). El arreglo
—`array_map('strval', array_keys($out))`— está en `shared/search.php` y el test sigue ahí,
en verde, para que no vuelva.

---

## Límites conocidos

- **Sin BD.** Todo lo de aquí es lógica pura. Que el SQL generado sea
  sintácticamente inatacable no prueba que devuelva las filas correctas:
  eso es la capa 3 (`--integration`).
- **CJK.** Una consulta en chino/japonés/coreano de 1-2 caracteres cae al
  brazo `LIKE`, y aunque sea más larga el analizador por defecto de InnoDB
  no sabe segmentar CJK (haría falta el parser `NGRAM`, que MariaDB no
  tiene). Los tests solo garantizan que **no rompe**, no que encuentre.
- **Los subprocesos cuestan ~17 ms cada uno.** Hoy son 18 y suman ~0,3 s de
  los ~0,4 s totales: el resto de la suite (82.000 aserciones) tarda menos
  que arrancar tres intérpretes. Si esto crece, es el primer sitio donde
  mirar.
- **`tests/` se despliega a producción** dentro de `public_html`. Desde
  2026-08 `.htaccess` lo bloquea (`RewriteRule ^tests(/|$) - [F,L]`), y
  además cada fichero de aquí empieza rechazando cualquier SAPI que no sea
  CLI: dos cerrojos, por si uno falla bajo LiteSpeed.
- **La suite de integración comparte UNA base de datos** (`iarepo_test` en
  el contenedor `iarepo_test_db`): no la corras dos veces a la vez.
