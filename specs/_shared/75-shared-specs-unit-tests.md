# 75 — Tests unitarios de los specs compartidos (03, 15 y 53)

- **Estado:** Implemented — 100 casos nuevos (77 + 23), suite unitaria en verde
  (743 tests, 3236 assertions); sin un solo cambio en código de producción
- **Fecha:** 2026-08-07
- **Dependencias:**
  - `03-i18n-mensajes-respuestas` (Implemented) — `myapi_get_lang()`,
    `myapi_t()`, `myapi_respond()`, `myapi_error()`.
  - `15-unlimited-limit-pagination` (Implemented) — el valor centinela `-1` de
    `limit` en `receipt`, `extra_fee` y `payment`.
  - `53-entityreference-selection-settings` (Implemented) — el catálogo, la
    función de reparación y `myapi_update_7016()`.
  - `21-auth-testing` (Implemented) — define las tres capas de `tests/`.
  - `73-auth-unit-tests-expansion` (Implemented) — el `bootstrap.php` que este
    spec extiende y el helper `myapi_test_capture()`.
  - `74-units-unit-tests` (Implemented) — el `db_select()` de fixtures que este
    spec completa con `where()` y `countQuery()`.
- **Objetivo:** Cerrar los dos huecos de cobertura que quedan entre los tres
  specs de `specs/_shared/`, con tests **funcionales** que ejecutan el código de
  producción tal cual lo ejecuta Drupal, no reimplementaciones de su lógica.

---

## El problema

Una auditoría de los tres specs de `specs/_shared/` contra `tests/unit/` deja
este cuadro:

| Spec | Código que produjo | Cobertura antes de este spec |
|---|---|---|
| 03 — i18n | `myapi_get_lang()`, `myapi_t()` | `I18nTest` — 17 casos |
| 03 — i18n | `myapi_respond()`, `myapi_error()` | `ResponseEnvelopeTest` — 14 casos |
| 03 — i18n | helpers de `myapi.request.inc` | `RequestValidationTest` — 31 casos |
| 53 — entityreference | catálogo + `_myapi_entityreference_repair_settings()` | `EntityReferenceFieldSettingsTest` — 15 casos |
| **53 — entityreference** | **`myapi_update_7016()`** | **ninguna** |
| **15 — `limit=-1`** | **`_list()` y `_fetch()` de los tres recursos** | **ninguna** |

El spec 03 está cubierto entero y no necesita nada. Los otros dos tienen un
hueco cada uno, y los dos son del mismo tipo: **lo que no se probó es
precisamente lo que el spec prometió y nadie volvió a verificar.**

### El hueco de SPEC 15

Cero tests. El spec replicó el mismo cambio mecánico en tres recursos gemelos
—`receipt`, `extra_fee`, `payment`— y su verificación es la lista de `curl`
manuales del paso 5 de su plan.

Lo que está sin ejercitar no es cosmético. El parseo de `limit` es un ternario
anidado de tres ramas cuya rama nueva se distingue de las otras dos por una
**comparación de string exacta** (`$_GET['limit'] === '-1'`), y a su alrededor
hay cuatro decisiones que fallan en silencio con HTTP 200:

- Si `-1` deja de detectarse, `ctype_digit()` lo rechaza y el endpoint cae al
  default `20` — la app recibe una primera página perfectamente válida en lugar
  del conjunto completo, y nada avisa.
- Si el `if ($limit === -1) { $page = 1; }` desaparece, un `?page=3` que el
  cliente arrastre de la petición anterior se cuela en `pagination.page` sin
  tener significado.
- Si el `if ($limit !== -1)` que envuelve el `range()` se invierte, se devuelven
  20 ítems y `pagination.total` sigue diciendo 137: la respuesta se contradice a
  sí misma.
- Si `total_pages` no distingue el caso `-1`, un `ceil($total / -1)` devuelve un
  número **negativo** de páginas.

Y el riesgo real es la triplicación: son tres archivos con el mismo bloque
copiado, y un arreglo aplicado a uno solo deja los otros dos rotos sin que nada
lo note.

### El hueco de SPEC 53

`myapi_update_7016()` quedó explícitamente fuera de `EntityReferenceFieldSettingsTest`,
que lo dice en su propio docblock: *"Deliberately NOT tested here … it needs
field_info_field()/field_update_field(), i.e. a booted site with a Field API"*.

La decisión era razonable, pero deja **tres criterios de aceptación del spec 53
sin verificar** (el 4, el 5 y el 6): que el update es idempotente, que no toca
ninguna tabla `field_data_*`, y que un sitio al que le falte un campo lo corre
sin error. Los tres son afirmaciones sobre un `drush updb` que se corre una vez
en producción y que, si se equivoca, se equivoca sobre la definición de un campo
compartido por dos bundles.

La parte del update que decide *qué* escribir sí está cubierta —es la función
pura de reparación—, pero la que decide *cuándo* escribir, *qué hace si el campo
no existe* y *qué hace con un `target_type` que no coincide* no está ejecutada
por nada.

---

## Alcance

**Dentro de este spec:**

- **`tests/unit/bootstrap.php`** (modificar) — tres extensiones, todas al
  servicio de correr código de producción sin reescribirlo:
  - `MyapiTestSelectQuery::where()` — parsea y **aplica** el único fragmento SQL
    que escribe el módulo, `SUBSTR(alias.columna, 1, 10) OP :param`. Sin esto,
    los tres `_fetch()` fatalan en el `__call()` y el filtro de fechas no se
    puede ejercitar.
  - `MyapiTestSelectQuery::countQuery()` — clona la consulta sin `range`, sin
    `order` y sin lista de campos, y responde una fila de una columna, como
    `COUNT(*)`. Sin esto, `myapi_<recurso>_count()` fatala y no hay `total`.
  - Stubs de Field API (`field_read_field()`, `field_update_field()`,
    `field_info_cache_clear()`, `watchdog()`) y de las cinco funciones de
    escritura (`db_insert/update/delete/merge`, `db_query`), estas últimas para
    ser afirmadas **vacías**.
- **`tests/unit/PaginationUnlimitedTest.php`** (nuevo) — SPEC 15 sobre los tres
  endpoints, de punta a punta por el dispatcher.
- **`tests/unit/EntityReferenceUpdateTest.php`** (nuevo) — `myapi_update_7016()`.

**Fuera de este spec:**

- **Tests nuevos para el spec 03.** Ya está cubierto por tres clases; añadir
  casos sería duplicar.
- **Que el SQL que describen los specs devuelva estas filas contra un esquema
  real.** Las fixtures responden, la base de datos no. Esa mitad sigue siendo
  trabajo de `tests/integration/`, y las consultas que estos tests fijan (su
  número, su orden, sus tablas y su `range`) son la costura donde se encuentran
  las dos capas.
- **Los `JOIN` resueltos.** El fixture los registra, nunca los ejecuta: una
  columna venida de un join se siembra plana bajo el alias que le da la consulta.
  La regla es la de SPEC 74 y no cambia aquí.
- **La verificación manual de SPEC 53** (los cinco autocompletados en el
  formulario). Sigue pendiente y sigue siendo manual.

---

## Modelo de datos

No hay tablas nuevas. Lo que este spec introduce son dos piezas de fixture.

### `where()` — el único fragmento SQL del módulo

Los seis `where()` de los tres recursos tienen la misma forma:

```php
$query->where('SUBSTR(fper.field_periodo_value, 1, 10) >= :date_from', [':date_from' => $from]);
```

El fixture lo parsea con una expresión regular, corta el valor de la fila a los
mismos 10 caracteres y compara con la misma tabla de operadores que usan las
condiciones. Un fragmento con **cualquier otra forma lanza una excepción** en
vez de ignorarse: un recurso que empiece a escribir otro SQL tiene que enseñarle
a este parser, no descubrir que su test pasaba porque el filtro se evaporaba.

Dos consecuencias documentadas:

- Un `NULL` en la columna **excluye** la fila, que es lo que hace SQL y lo que
  los tres recursos describen en su comentario ("un pago sin fecha no puede caer
  en un rango").
- La columna se busca por su nombre crudo (`field_periodo_value`), no por el
  alias de la proyección (`period_start`). Una fila de fixture que quiera que el
  filtro la muerda tiene que llevar **las dos**, y eso es deliberado: es lo que
  permite sembrar solo las columnas que el caso necesita.

### `countQuery()`

Clona la consulta y le quita el `range`, el `order` y los campos. Quitarle el
`range` es lo que importa: un count que lo heredara respondería el tamaño de la
página en vez del tamaño del conjunto, y **todas** las afirmaciones sobre
`total_pages` estarían de acuerdo consigo mismas y equivocadas.

---

## Plan de implementación

1. **`bootstrap.php`: `where()` y `countQuery()`.** Con esto los tres `_list()`
   corren enteros por primera vez fuera de Drupal. *Verificación: la suite
   existente sigue en verde — 643 tests.*
2. **`bootstrap.php`: Field API y guardián de escrituras.**
3. **`PaginationUnlimitedTest.php`.** Un `@dataProvider` de tres endpoints
   recorre cada caso, de modo que **ningún caso prueba un solo recurso**: los
   tres se verifican con la misma afirmación, que es la defensa contra el
   arreglo aplicado a uno de los tres.
4. **`EntityReferenceUpdateTest.php`.** Los criterios 4, 5 y 6 del spec 53, más
   el `target_type` que no coincide y el `field_info_cache_clear()` final.
5. **Actualizar el docblock de `EntityReferenceFieldSettingsTest`**, que dice
   que el update no está probado y a partir de aquí ya no es cierto.

---

## Criterios de aceptación

**SPEC 15, los tres endpoints:**

- [x] `?limit=-1` devuelve todos los ítems del conjunto filtrado, y
      `pagination.total` coincide con la cantidad devuelta.
- [x] `pagination.limit` es `-1` y `pagination.page` es `1` aunque se mande
      `?page=3`.
- [x] `total_pages` es `1` con `total > 0` y `0` con `total = 0`.
- [x] Una unidad sin ítems responde `200` con array vacío, no un error.
- [x] La consulta de fetch **no lleva `range`** con `limit=-1`, y sí lo lleva en
      todos los demás casos.
- [x] `sort=asc`/`desc` y `date_from`/`date_to` se siguen aplicando con `-1`.
- [x] `limit` ausente, `0`, `-2`, `abc`, `999`, `' -1'`, `'-01'` y `'-1 '` se
      comportan exactamente como antes del spec 15.
- [x] `403`, `401` y `405` no cambian con `limit=-1`.

**SPEC 53, el update hook:**

- [x] Repara un campo sin settings y lo nombra en el resumen.
- [x] La segunda pasada no escribe nada y lo dice.
- [x] Un campo ausente se nombra en el resumen y no produce error.
- [x] Un `target_type` que no coincide se salta, se registra en el log y no se
      escribe.
- [x] Un `target_bundles` puesto a mano sobrevive.
- [x] No se llama a ninguna función de escritura de base de datos.
- [x] `field_info_cache_clear()` se llama incluso cuando no se reparó nada.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nivel de los tests de SPEC 15 | Funcionales, por el dispatcher, afirmando el JSON impreso | Unitarios sobre el parseo de `limit` aislado | El spec promete cosas sobre la **respuesta**; un test del ternario no vería el `range()` ni el `total_pages` |
| Los tres recursos | Un `@dataProvider` que corre cada caso sobre los tres | Una clase por recurso, o probar solo `payment` | El riesgo del spec 15 es exactamente la divergencia entre los tres gemelos |
| `where()` en el fixture | Parsear y aplicar el patrón `SUBSTR`, lanzar en cualquier otro | Registrar y no aplicar | Un filtro registrado pero no aplicado haría pasar un `limit=-1` que ignora el rango de fechas |
| `myapi_update_7016()` | Probarlo con stubs de Field API | Dejarlo a la verificación manual, como estaba | Tres criterios de aceptación sin verificar sobre un `updb` que se corre una vez |
| El guardián de no-escritura | Stubs que registran y **lanzan** | No stubear nada | "No toca `field_data_*`" es infalsificable si la llamada prohibida no es observable |
| `field_read_field()` y no `field_info_field()` | Stubear el nombre que el update llama de verdad | Stubear el otro | El update elige el primero a propósito; un stub del otro nombre dejaría podrir esa distinción |

---

## Verificación por mutación

Una suite que pasa a la primera no demuestra nada. Cada grupo de tests se
verificó rompiendo el código de producción a propósito y comprobando que
falla — y restaurándolo después (el árbol de producción quedó intacto):

| Mutación | Resultado |
|---|---|
| `=== '-1'` → `=== '-999'` en `payment` | 6 fallos, **solo** en los casos de `payments` |
| `if ($limit !== -1)` → `if (TRUE)` en `receipt` | 9 fallos |
| Quitar el forzado de `$page = 1` en `extra_fee` | 1 fallo |
| `total_pages` sin la rama `-1` en `payment` | 1 fallo |
| Clamp `50` → `10` en `receipt` | 1 fallo |
| Default `20` → `25` en `extra_fee` | 13 fallos |
| Quitar la guarda `$settings === NULL` del update | 4 fallos |
| Reparar también el `target_type` que no coincide | 3 fallos |
| Quitar `field_info_cache_clear()` | 2 fallos |
| `$settings = $wanted` (imponer en vez de rellenar) | 6 fallos + 1 error |
| No reportar el campo ausente | 2 fallos |

Que la primera mutación falle **solo** en `payments` es la comprobación de que
el `@dataProvider` hace lo que se pidió de él: distingue un recurso de sus dos
gemelos.

## Hallazgo lateral

El parseo de `limit` de los tres recursos emite un
`Warning: Array to string conversion` cuando llega `?limit[]=-1`: la detección
del centinela es una comparación de string que el array no cumple, y la rama
siguiente lo pasa por `(string)` para `ctype_digit()`. **El resultado es
correcto** (cae al default `20`), así que no es un bug de contrato y no se
arregla aquí, pero ensucia el log de PHP en cada petición así.

Queda pinado por `testAnArrayLimitIsNotTheSentinelAndWarns()`, que afirma el
warning en lugar de tolerarlo: si alguien endurece el parseo con un
`is_string()` —como ya hace `myapi_get_lang()` con `?lang[]=`—, ese test falla y
se actualiza a conciencia en vez de que el warning desaparezca sin que nadie lo
note. Si se quiere arreglar, es un spec propio de una línea por recurso.

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El fixture no es una base de datos.** Un `LEFT JOIN` que duplica filas, o un `ORDER BY` real, siguen sin ejercitarse: estos tests prueban la mitad PHP de cada endpoint | Declarado en el docblock de cada clase y en el bloque de `bootstrap.php`; las consultas quedan registradas y afirmadas en su forma (tablas, condiciones, `range`) donde no se puede afirmar su resultado |
| **`where()` entiende una sola forma de SQL.** Un recurso futuro con otro fragmento no fallaría silenciosamente, pero sí fatalaría | Es el comportamiento buscado: lanza con el fragmento en el mensaje |
| **Los stubs de escritura son globales.** Cualquier test que llame a `db_insert()` ahora recibe una excepción en vez de un error fatal | Ningún test actual las llama (fatalarían hoy); el mensaje nombra la función y la tabla |
