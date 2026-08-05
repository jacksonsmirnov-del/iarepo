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
| `unit/i18n_test.php` | `shared/i18n.php` | Traducción, *fallback*, resolución de idioma y sanidad del diccionario. |
| `unit/similarity_test.php` | `shared/similarity.php` | Detector de plagio: solo las funciones de texto (las que hablan con PDO son de integración). |
| `unit/helpers_isolation_test.php` | `shared/helpers.php` | `sanitize()`, `h()` y la demostración de la regla #1. |
| `integration/` | API + BD real | Lo mantiene otro agente. Se ejecuta con `--integration`. |

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

## Un test en rojo a propósito

```
✗ bug_terms_numericos_salen_como_int_en_vez_de_string
```

**Es un fallo real de `shared/search.php`, no del test.** `iarepo_tokenize()`
deduplica con `$out[$t] = true` y PHP convierte a `int` las claves de array
que parecen enteros canónicos, así que `array_keys()` las devuelve ya como
enteros:

```php
iarepo_build_search('2024 examen')['terms']   //  [2024, 'examen']
```

`api/resources.php` devuelve eso tal cual en `search.terms`, de modo que el
navegador recibe `{"terms":[2024,"examen"]}` y cualquier resaltado que haga
`t.toLowerCase()` revienta en cuanto alguien busca un año o un número de
ejercicio. Además es inconsistente: `'007'` sí sale como cadena.

Arreglo, una línea en `shared/search.php:148`:

```php
return array_map('strval', array_keys($out));
```

Ese fichero es de otro agente, así que aquí queda el test que lo demuestra.
**Mientras siga en rojo, `php tests/run.php` sale con 1 y el hook `pre-push`
bloquea el push.** Es intencionado: el arreglo cuesta menos que discutirlo.

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
- **`tests/` se despliega a producción** dentro de `public_html` y
  `.htaccess` no lo bloquea. Por eso cada fichero de aquí empieza
  rechazando cualquier SAPI que no sea CLI. La defensa buena es una regla
  `RewriteRule ^tests/ - [F,L]` en `.htaccess`, que es de otro agente.
