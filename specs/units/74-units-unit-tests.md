# 74 — Tests unitarios de `GET /api/v1/units` (specs 08, 09 y 10)

- **Estado:** Implemented — 89 casos nuevos, suite unitaria en verde (643 tests, 2735 assertions)
- **Fecha:** 2026-08-06
- **Dependencias:**
  - `08-units-list` (Implemented) — `myapi_unit_dispatch()`, `myapi_unit_list()`
    y los fetchers de `resources/unit.resource.inc`; `myapi_unit_related_nids()`
    en `includes/myapi.unit_access.inc`.
  - `09-units-owner-occupant` (Implemented) — `owner_uid`, `occupant_uid`,
    `occupant_name` y la regla del `delta` máximo.
  - `10-units-saldo-actual` (Implemented) — `current_balance`.
  - `21-auth-testing` (Implemented) — define las tres capas de `tests/`.
  - `73-auth-unit-tests-expansion` (Implemented) — el `bootstrap.php` que este
    spec extiende y el helper `myapi_test_capture()`.
- **Objetivo:** Que todo el código que produjeron los specs 08, 09 y 10 tenga
  cobertura unitaria real —no solo la mitad pura—, y que cada criterio de
  aceptación de esos tres specs esté ejercitado por un test con nombre propio en
  vez de por una prueba manual con `curl`.

---

## El problema

Los tres specs de `specs/units/` produjeron ocho funciones y **ninguna** tenía
un solo test en ninguna de las tres capas:

| Función | Archivo | Cobertura antes de este spec |
|---|---|---|
| `myapi_unit_dispatch()` | `resources/unit.resource.inc` | ninguna |
| `myapi_unit_list()` | `resources/unit.resource.inc` | ninguna |
| `myapi_unit_build_properties()` | `resources/unit.resource.inc` | ninguna |
| `myapi_unit_fetch_units()` | `resources/unit.resource.inc` | ninguna |
| `myapi_unit_fetch_condominiums()` | `resources/unit.resource.inc` | ninguna |
| `myapi_unit_fetch_occupant_uids()` | `resources/unit.resource.inc` | ninguna |
| `myapi_unit_fetch_user_names()` | `resources/unit.resource.inc` | ninguna |
| `myapi_unit_related_nids()` | `includes/myapi.unit_access.inc` | ninguna |

`tests/integration/` cubre solo `auth` (así lo dejó escrito SPEC 21 en su
"Scope note"), y la verificación de los tres specs de unidades es la lista de
`curl` manuales del paso 11 del spec 08 — que nadie vuelve a correr cuando se
toca el endpoint.

La razón técnica de la ausencia era una sola: **siete de las ocho funciones
empiezan en un `db_select()`**, y la regla de `tests/unit` desde SPEC 21 es que
ahí no entra nada que toque la base de datos. Pero lo que esas funciones hacen
*después* de la consulta —la precedencia por `delta`, el fallback de nombres, el
mapa de condominios, el agrupado y los casts de la respuesta— es PHP puro que
decide byte por byte lo que recibe la app, y estaba entero sin ejecutar.

El costo de dejarlo así no es teórico: el `!== NULL` de `current_balance` es lo
único que distingue "saldo cero" de "sin saldo", el `!==` cuádruple de
`myapi_unit_fetch_user_names()` es lo único que evita un nombre híbrido tipo
`"pcordero Cordero"`, y el `orderBy('delta', 'ASC')` es lo único que hace
correcto el bucle que resuelve el ocupante actual. Los tres fallan en silencio,
con HTTP 200 y un cuerpo plausible.

---

## Alcance

### Dentro de este spec

- **`tests/unit/bootstrap.php`** (modificar) — un *fixture query builder* y dos
  stubs más:
  - `MyapiTestSelectQuery` / `MyapiTestStatement` + `db_select()`, que
    responden filas sembradas por el test aplicando las condiciones y el
    `ORDER BY` sobre las columnas que la fixture trae, y proyectando los
    campos/alias que la query pidió. **Registran cada consulta** en
    `$GLOBALS['myapi_test_db_queries']`.
  - `myapi_test_db_seed()` / `myapi_test_db_queries()` — sembrar tablas y leer
    las consultas ejecutadas.
  - `user_load()` (lee `$GLOBALS['myapi_test_users']`) y la constante
    `REQUEST_TIME`, las dos piezas que le faltaban al middleware de access
    token para poder correr fuera de Drupal.
- **`tests/unit/UnitBuildPropertiesTest.php`** (nuevo, 25 casos) —
  `myapi_unit_build_properties()`.
- **`tests/unit/UnitQueriesTest.php`** (nuevo, 35 casos) —
  `myapi_unit_related_nids()`, `myapi_unit_fetch_units()`,
  `myapi_unit_fetch_condominiums()`, `myapi_unit_fetch_occupant_uids()` y
  `myapi_unit_fetch_user_names()`.
- **`tests/unit/UnitEndpointTest.php`** (nuevo, 29 casos) —
  `myapi_unit_dispatch()` y `myapi_unit_list()` de punta a punta: se llama al
  dispatcher como lo llama `hook_menu()` y se asserta el JSON impreso y el
  código de estado.
- **`docs/unit.md`** (modificar) — la nota sobre la representación numérica
  descrita abajo, y el ejemplo de respuesta corregido en consecuencia.
- **`tests/README.md`** (modificar) — la capa unitaria y los stubs nuevos.

### Fuera de este spec — y por qué

| Qué | Por qué no |
|---|---|
| Ejecutar SQL de verdad | El *fixture builder* no resuelve JOINs ni ejecuta SQL. Que un `LEFT JOIN` a un campo multi-value duplique la fila de una unidad, o que el `ORDER BY delta` se comporte igual sobre un índice real, solo lo contesta una base de datos: sigue siendo trabajo de `tests/integration`. |
| `tests/integration/` para `units` | SPEC 21 dejó esa capa acotada a `auth` y montarla para otro recurso (módulo compañero, sandbox, fixtures de nodos) es un spec propio, no un anexo de este. |
| `tests/e2e/` para `units` | Igual que arriba; además requiere una cuenta QA con unidades reales en producción. |
| `hook_menu()` en `myapi.module` | Devuelve un array que solo significa algo dentro de Drupal. Que la ruta llegue al dispatcher es de integración; que el dispatcher haga lo correcto, de aquí. |
| El resto de `includes/myapi.unit_access.inc` (`myapi_condominium_related_nids()`, `myapi_unit_member_uids()`, `myapi_condominium_member_uids()`, `myapi_user_owned_unit_nids()`, `myapi_user_occupied_unit_nids()`, `myapi_units_condominium_nids()`) | No las produjeron los specs 08/09/10 sino los de boletines, reservas y notificaciones. El harness nuevo las deja testeables; cubrirlas es alcance de un spec de esos recursos. |
| Cambiar el tipo de `area_m2`/`current_balance` en la respuesta | Ver "El hallazgo": se documenta, no se cambia. Tocar el contrato de un endpoint en producción es una decisión de producto, no de un spec de tests. |

---

## Diseño: qué prueba de verdad el harness nuevo

El `db_select()` de `bootstrap.php` **no es una base de datos**, y el spec lo
declara antes que nada porque de ahí depende cómo leer cada caso:

- **Lo que sí se ejecuta de producción:** todo el PHP posterior a la consulta.
  Los bucles que arman mapas, la precedencia `field_ocupantes` → `field_ocupante`,
  el fallback `"nombre apellidos"` → `users.name`, los early-return que evitan
  una query con `IN ()` vacío, el agrupado por condominio, los descartes y los
  casts. Corren tal cual, sin copias ni reimplementaciones.
- **Lo que se asserta estructuralmente:** las condiciones que aplicaría la base
  de datos (`type`, `status`, `deleted = 0`, `entity_type = 'node'|'user'`, el
  `IN` de nids, el `ORDER BY delta`). El builder las registra y hay casos que
  las leen. Es una assertion sobre *el SQL construido*, no sobre su resultado —
  y está dicho así en el docblock de cada uno de esos casos.
- **Lo que no se prueba aquí:** la semántica del motor. Duplicación de filas por
  JOIN, colación, tipos de columna. Eso es de integración y este spec no
  pretende sustituirlo.

Las condiciones sí se aplican sobre las columnas que la fixture trae, así que
los criterios "unidad no publicada no aparece", "condominio no publicado no
aparece", "filas de otro usuario no cuentan" y "otro bundle no cuenta" se
verifican por **comportamiento**, no por inspección de la query.

### Verificación del harness (mutantes)

Un test que no falla cuando el código se rompe no prueba nada, así que se
comprobó al revés, rompiendo producción a propósito y confirmando que la suite
lo detecta:

| Mutación en producción | Resultado |
|---|---|
| Quitar `->orderBy('delta', 'ASC')` | 2 fallos |
| Quitar `(float)` de `current_balance` | 6 fallos |
| Quitar `$occupants_nids` del merge de `myapi_unit_related_nids()` | 6 fallos |
| Quitar `->condition('n.status', 1)` en condominios | 4 fallos |
| Quitar `->condition('n.status', 1)` en unidades | 3 fallos |
| Consultar el campo legacy con `$nids` en vez de `$unresolved_nids` | 1 fallo |
| Sustituir el fallback de nombres por `if (TRUE)` | 4 fallos |

Se probó también sustituir `$unit->saldo_actual !== NULL` por
`!empty($unit->saldo_actual)`: **no falla**, y es correcto que no falle — el
valor llega de la base como la cadena `'0.0000'`, que es *truthy*, así que las
dos formas coinciden para todo dato posible del schema actual (`decimal(10,4)`).
Queda anotado para que nadie lo lea como un hueco de cobertura.

---

## El hallazgo

`GET /api/v1/units` no manda `92.0` ni `-3393.0`, como decía el ejemplo de
`docs/unit.md` y el de los specs 09/10: manda `92` y `-3393`.

```php
json_encode((float) '92.00');      // "92"
json_encode((float) '-3393.0000'); // "-3393"
json_encode((float) '15.50');      // "15.5"
```

`json_encode()` imprime un `float` sin parte fraccionaria como literal entero.
El cast `(float)` de `myapi_unit_build_properties()` es correcto y el valor no
se pierde; lo que cambia es el **literal JSON**, y con él el tipo que infiere el
cliente: el mismo campo llega como `int` en una unidad y como `double` en la
siguiente, de modo que un cliente Dart que lo lea con `as double` revienta con
la primera unidad de área o saldo redondo.

Decisión: **documentarlo, no cambiarlo.** El endpoint está en producción y
cualquier alternativa (mandarlo como string, forzar decimales) rompe a los
clientes actuales. Queda fijado por
`UnitEndpointTest::testWholeNumbersTravelWithoutADecimalPoint` y escrito en
`docs/unit.md` como nota de contrato, con la instrucción para el cliente: parsear
como `num`, nunca como `double`.

---

## Plan de implementación

1. **`bootstrap.php`** — `MyapiTestSelectQuery`, `MyapiTestStatement`,
   `db_select()`, `myapi_test_db_seed()`, `myapi_test_db_queries()`,
   `user_load()` y `REQUEST_TIME`, todos con guarda `function_exists()` /
   `defined()` y documentados en el `@file` con el mismo criterio que los stubs
   anteriores.
2. **`UnitBuildPropertiesTest.php`** — la función pura primero: forma
   documentada completa, agrupado, descartes, NULLs y casts.
3. **`UnitQueriesTest.php`** — los cuatro fetchers y `myapi_unit_related_nids()`:
   mapas, fallbacks, early-returns y forma de cada query.
4. **`UnitEndpointTest.php`** — dispatcher + `myapi_unit_list()` completo:
   routing, las cinco formas de fallar un access token, la respuesta vacía, la
   respuesta llena comparada entera, y el costo en consultas.
5. **Verificación por mutación** — romper producción en siete puntos y
   confirmar que la suite lo detecta; revertir.
6. **`docs/unit.md` y `tests/README.md`** — la nota del hallazgo y la
   descripción de la capa.

---

## Criterios de aceptación

- [x] Las 8 funciones producidas por los specs 08/09/10 tienen tests unitarios.
- [x] Cada criterio de aceptación de los specs 08, 09 y 10 que no dependa de
      ejecutar SQL real tiene al menos un caso con nombre propio.
- [x] `myapi_unit_list()` se ejerce de punta a punta, assertando el JSON impreso
      y el código de estado, no un valor de retorno.
- [x] Las cinco formas de rechazar un access token (ausente, malformado,
      desconocido, revocado, expirado) y las dos de rechazar al usuario
      (inexistente, bloqueado) dan **401** con el `error_code` documentado.
- [x] `POST`/`PUT`/`DELETE`/`PATCH`/`HEAD` dan **405** sin tocar una sola tabla.
- [x] La suite unitaria completa pasa: **643 tests, 2735 assertions**.
- [x] Cada clase nueva pasa también aislada (`vendor/bin/phpunit --filter
      UnitEndpointTest`), la regla de SPEC 73 contra el estado compartido.
- [x] Siete mutaciones en producción producen fallos; el mutante equivalente
      está anotado.
- [x] No se modificó ninguna línea de producción (`resources/`, `includes/`).
- [x] `docs/unit.md` documenta la representación numérica real de la respuesta.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Cómo testear lo que empieza en `db_select()` | *Fixture query builder* en `bootstrap.php`: se ejecuta el PHP real y se siembran las filas | Dejarlo fuera de `tests/unit` como hizo SPEC 73 con `auth` | Con la regla anterior, 7 de 8 funciones de estos specs quedaban sin ninguna cobertura en ninguna capa; el PHP posterior a la query es donde viven las reglas de los tres specs. |
| Alcance del *fake* | Aplicar condiciones y `ORDER BY` sobre las columnas de la fixture; registrar joins sin resolverlos | Implementar un motor SQL en miniatura | Resolver JOINs sería reimplementar la base de datos dentro del test: mucho código propio que puede tener bugs propios y que ya no probaría producción sino al *fake*. |
| Métodos no soportados del query builder | Lanzar `RuntimeException` | Devolver `$this` en silencio | Un `groupBy()` ignorado haría pasar un test por la razón equivocada; el fallo ruidoso obliga a extender el harness conscientemente. |
| Assertions sobre la forma de la query | Sí, para `type`/`status`/`deleted`/`entity_type`/`IN`/`ORDER BY` | Solo assertions de comportamiento | Son exactamente los alcances que un cambio de schema rompe en silencio, y desde esta capa no hay otra forma de fijarlos. Cada caso dice en su docblock que asserta el SQL construido. |
| Stub de `myapi_auth_require_access_token()` | No: corre el middleware **real** sobre `my_api_tokens` sembrada y `user_load()` stubeado | Stubear la función y devolver una fila | Un stub habría hecho imposible cubrir los 401, que son 7 de los criterios de aceptación; además `bootstrap.php` ya documenta el costo de stubear funciones de `myapi` (el fatal por "Cannot redeclare"). |
| Tres clases en vez de una | `UnitBuildPropertiesTest` / `UnitQueriesTest` / `UnitEndpointTest` | Un solo `UnitTest.php` de 89 casos | Cada una tiene un tipo de assertion distinto (pura / query / HTTP) y un `--filter` distinto; una clase única no diría a qué capa pertenece un fallo. |
| El hallazgo numérico | Documentar en `docs/unit.md` y fijar con un test | Cambiar el cast o el formato de salida | El endpoint está en producción con clientes vivos; cambiar el literal JSON es una decisión de producto y rompería lo que hoy funciona. |
| Verificación del harness | Siete mutaciones ejecutadas y anotadas | Confiar en que los tests en verde bastan | Un fake mal hecho produce una suite verde que no prueba nada; la única forma de demostrar lo contrario es romper producción a propósito. |

---

## Riesgos identificados

- **Falsa sensación de cobertura de SQL.** Un lector apurado puede leer
  "`testFetchUnitsSkipsUnpublishedUnits` pasa" como "la query filtra bien en
  MySQL". No es lo mismo: la fixture aplica la condición porque la fila trae la
  columna. *Mitigación:* el disclaimer está en el `@file` de `bootstrap.php`, en
  el docblock de las tres clases y en este spec; los casos que assertan
  estructura lo dicen uno por uno.
- **El fake puede divergir del `SelectQuery` real.** Si un cambio en producción
  empieza a usar `addExpression()`, `groupBy()` o `db_or()`, el harness no lo
  soporta. *Mitigación:* deliberada — lanza excepción en vez de devolver algo
  plausible, así que el próximo autor lo ve en la primera corrida.
- **`db_select()` ahora existe en toda la suite unitaria.** Antes, un test que
  alcanzara una consulta por error moría con "undefined function"; ahora recibe
  un resultado vacío y sigue. *Mitigación:* se corrió la suite completa antes y
  después del cambio con idéntico resultado (554 tests en verde), lo que prueba
  que ningún test existente dependía de ese fatal.
- **Fixtures que se desincronizan del schema real.** Una columna renombrada en
  la base no rompe estos tests, porque la fixture la sigue trayendo con el
  nombre viejo. *Mitigación:* es exactamente el límite que separa esta capa de
  `tests/integration`, y está declarado en "Fuera de este spec".
