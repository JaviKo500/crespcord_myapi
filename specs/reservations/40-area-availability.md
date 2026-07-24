# SPEC 40 — Endpoint de disponibilidad de un área (horarios ocupados)

> **Estado:** Approved · **Depende de:** SPEC 32 (content types de reservas/áreas), SPEC 33 (listado de áreas + `myapi_condominium_related_nids`), SPEC 35 (query base de solape + clave `area_not_found`), SPEC 39 (`404` no-revelador sobre recurso de área por id) · **Fecha:** 2026-07-24
> **Objetivo:** Exponer `GET /api/v1/areas/{id}/availability?date=YYYY-MM-DD` como lectura de solo lectura que devuelve los rangos horarios ocupados (reservas confirmadas de **todas** las unidades del condominio) de un área para una fecha, incluidas las que cruzan la medianoche, para que la app marque los horarios tomados antes de confirmar.

---

## Alcance

**Dentro:**

- Ruta `GET /api/v1/areas/%/availability` registrada en `hook_menu()` (`myapi.module`), `page arguments [3]`, apuntando al dispatcher del recurso de área.
- Dispatcher `myapi_area_availability_dispatch($area_id)` en `resources/area.resource.inc` (solo `GET`; cualquier otro método → `405 method_not_allowed`), más el orquestador `myapi_area_availability($area_id)` en el mismo archivo.
- **Archivo nuevo** `includes/myapi.reservation_query.inc` (registrado en `myapi.info` con `files[]`) con dos funciones reutilizables de dominio-reserva:
  - `myapi_reservation_busy_rows($area_id, array $dates)` — query a las tablas `field_data_field_*` que devuelve las filas crudas (`date`, `start_time`, `end_time`) de reservas **confirmadas** y **publicadas** del área cuyas `field_date` caen en las fechas dadas. Sin filtro de requester.
  - `myapi_reservation_busy_ranges($rows, $date, $prev_date)` — **función pura** (sin BD): deriva `start_date`/`end_date`, descarta las filas de `prev_date` que no cruzan medianoche, ordena y devuelve los items de 4 claves.
- Respuesta `{"success": true, "data": {"date": "...", "busy": [ {start_date,start_time,end_date,end_time}, ... ]}}`; `busy: []` con `200` cuando no hay reservas.
- Validación estricta de `date` (query param, `YYYY-MM-DD` + `checkdate()`): ausente → `422 missing_field`, mal formada/no-calendario → `422 invalid_field` (`@field = date`).
- Acceso idéntico al detalle de área (SPEC 39): área no visible o de condominio ajeno → único `404 area_not_found` no-revelador, reutilizando `myapi_area_fetch_one()` + `myapi_condominium_related_nids()`.
- Doc del endpoint en `docs/area.md` y **unit tests** (PHPUnit) sobre `myapi_reservation_busy_ranges()` (derivación de fechas / medianoche / orden / filtro de `prev_date`).
- `drush cc all` al final (ruta nueva).

**Fuera de alcance (para futuros specs):**

- **No se toca `POST /api/v1/reservations` ni `myapi_reservation_has_overlap()`.** Este endpoint solo **reporta**; la corrección de la validación lineal de minutos que hoy rechaza áreas que cruzan medianoche va en otro spec.
- No se tocan cancel, listado ni detalle de reservas.
- Nada de crear/modificar/borrar: solo lectura.
- Sin filtros extra (`status`, rangos de fechas) ni paginación: un área, un día.
- Sin nuevas claves i18n (las 6 usadas ya existen en es/en).
- La matriz HTTP completa (sin token, token inválido, área ajena, día con varias reservas, medianoche en ambas direcciones, cancelada ausente) queda como **plan de test manual con `curl`** documentado en el spec, no como tests de integración automatizados en esta entrega.

---

## Modelo de datos

**No hay datos persistentes nuevos.** El endpoint lee los bundles `reservation` y `area` (SPEC 32) directamente desde las tablas `field_data_field_*`, igual que el resto del módulo. No hay tablas ni campos nuevos.

**Fuente (reservas confirmadas del área):** misma base que `myapi_reservation_has_overlap()`, **sin** el filtro de requester (que ya no lo tiene) y **sin** el rango a comparar, sobre **dos** fechas:

| Tabla | Columnas | Uso |
|---|---|---|
| `node` | `nid`, `type`, `status` | Nodos `reservation` publicados (`status = 1`). Inner join base. |
| `field_data_field_area` | `entity_id`, `field_area_target_id` | Reserva → área. `= {id}`. Inner join. |
| `field_data_field_reservation_status` | `entity_id`, `field_reservation_status_value` | `= 'confirmed'`. Inner join (las `cancelled` no producen fila → no aparecen). |
| `field_data_field_date` | `entity_id`, `field_date_value` | `SUBSTR(field_date_value, 1, 10) IN (:date, :prev_date)`. Proyecta `date`. |
| `field_data_field_start_time` | `entity_id`, `field_start_time_value` | Proyecta `start_time` (`text(5)`, `HH:MM`). Inner join. |
| `field_data_field_end_time` | `entity_id`, `field_end_time_value` | Proyecta `end_time` (`text(5)`, `HH:MM`). Inner join. |

`field_date` es `datetime` (se guarda `YYYY-MM-DD HH:MM:SS`), por eso se usa `SUBSTR(...,1,10)` para el `Y-m-d` — mismo patrón que ya usa el archivo. `field_start_time`/`field_end_time` son `text(5)`, siempre `HH:MM` con cero a la izquierda.

**Fila cruda devuelta por `myapi_reservation_busy_rows()`** (una por reserva):

```php
// stdClass
{ date: "2026-07-25", start_time: "23:00", end_time: "01:00" }
```

**Derivación pura (`myapi_reservation_busy_ranges($rows, $date, $prev_date)`):** para cada fila,

- `crosses = parse(end_time) <= parse(start_time)` — reutiliza el criterio de minutos (`(int)HH*60+(int)MM`), robusto ante formato.
- `start_date = row.date` (siempre el `field_date` de la reserva).
- `end_date = crosses ? row.date + 1 día : row.date`.
- **Filtro:** si `row.date == prev_date` y `!crosses` → se descarta (una reserva del día anterior que no cruza no toca el día pedido). Las de `row.date == date` se conservan crucen o no.
- **Orden:** ascendente por `(start_date, start_time)`. Las cruzadas de `prev_date` (madrugada) quedan primero por tener `start_date` menor.

**Item de `busy[]` (exactamente 4 claves, siempre presentes):**

```json
{ "start_date": "2026-07-24", "start_time": "23:00",
  "end_date":   "2026-07-25", "end_time":   "01:00" }
```

Nunca lleva `id`, `unit_id`, `requester_id` ni nombres. `start_date == end_date` en las reservas que empiezan y terminan el mismo día.

**Envelope de respuesta:**

```json
{ "success": true, "data": { "date": "2026-07-25", "busy": [ ] } }
```

Sin `message` (lectura simple). `data.date` es el `date` pedido y validado, tal cual.

---

## Plan de implementación

1. **Crear `includes/myapi.reservation_query.inc`** con solo la función pura `myapi_reservation_busy_ranges($rows, $date, $prev_date)`: deriva `start_date`/`end_date` con `crosses = parse(end) <= parse(start)`, descarta filas de `prev_date` que no cruzan, ordena por `(start_date, start_time)` asc y devuelve items de 4 claves. Sin BD, sin llamadas a Drupal a nivel de archivo (solo `define`/`function`). *Verificación:* `php -l`.

2. **Añadir la query `myapi_reservation_busy_rows($area_id, array $dates)`** en el mismo archivo: `db_select('node')` con `type=reservation`, `status=1`; inner joins a `field_area` (`= $area_id`), `field_reservation_status` (`= 'confirmed'`), `field_date` (`SUBSTR(...,1,10) IN (:dates)`), `field_start_time`, `field_end_time`; proyecta `date` (via `SUBSTR`), `start_time`, `end_time`; `fetchAll()`. *Verificación:* `php -l`.

3. **Registrar el archivo** en `myapi.info`: `files[] = includes/myapi.reservation_query.inc`.

4. **`myapi_area_availability_dispatch($area_id)`** en `resources/area.resource.inc`: `GET` → `myapi_area_availability($area_id)`; cualquier otro método → `myapi_error('method_not_allowed', 405)`. Añadir `module_load_include('inc', 'myapi', 'includes/myapi.reservation_query')` a los `module_load_include` de cabecera del archivo.

5. **`myapi_area_availability($area_id)`** en el mismo archivo — orquestación en el orden del spec:
   - `myapi_auth_require_access_token()` → `$uid` (401 `missing_authorization` / `invalid_token` si falla).
   - `{id}` entero positivo y `myapi_area_fetch_one($area_id)`; si `FALSE` → `404 area_not_found`.
   - `field_condominium` del área no relacionada vía `myapi_condominium_related_nids($uid)` → `404 area_not_found`.
   - Leer `date` del query string; ausente/vacío → `422 missing_field` (`@field = date`); no matchea `^\d{4}-\d{2}-\d{2}$` o falla `checkdate()` → `422 invalid_field` (`@field = date`).
   - `$prev_date = date('Y-m-d', strtotime($date . ' -1 day'))`.
   - `$rows = myapi_reservation_busy_rows($area_id, [$date, $prev_date])`.
   - `$busy = myapi_reservation_busy_ranges($rows, $date, $prev_date)`.
   - `myapi_respond(['date' => $date, 'busy' => $busy], 200)`.

6. **Registrar la ruta** en `hook_menu()` (`myapi.module`): `api/v1/areas/%/availability` → `page callback myapi_area_availability_dispatch`, `page arguments [3]`, `access callback TRUE`, `file resources/area.resource.inc`, `type MENU_CALLBACK`.

7. **Unit tests** (PHPUnit) en `tests/unit/` sobre `myapi_reservation_busy_ranges()`: `require` de `includes/myapi.reservation_query.inc` (el `bootstrap.php` actual ya basta — la función pura no llama a Drupal). Casos: día sin filas → `[]`; mismo día (`start_date == end_date`, orden); reserva que cruza vista desde su día de inicio (`end_date = date+1`); la misma vista desde el día siguiente (fila de `prev_date` que cruza → `start_date = prev_date`); reserva de `prev_date` que **no** cruza → descartada; orden mixto por `(start_date, start_time)`.

8. **Documentar** en `docs/area.md` la sección `GET /api/v1/areas/{id}/availability` (query params, respuesta, notas de medianoche, tabla de errores, ejemplo `curl`), al nivel de las otras dos.

9. **`drush cc all`** para tomar la ruta nueva.

---

## Criterios de aceptación

- [ ] `GET /api/v1/areas/{id}/availability?date=...` sin header `Authorization` o malformado → `401 missing_authorization`; token inexistente/revocado/expirado o usuario inexistente/bloqueado → `401 invalid_token`.
- [ ] `{id}` inexistente, no-`area`, no publicado, con `field_area_status` no visible, o de un condominio no relacionado con el usuario → todos el **mismo** `404 area_not_found`, indistinguibles (igual que `GET /api/v1/areas/{id}`).
- [ ] Sin `date` en el query string → `422 missing_field` con `@field = date`.
- [ ] `date` con formato distinto de `YYYY-MM-DD` o fecha no-calendario (p. ej. `2026-02-30`) → `422 invalid_field` con `@field = date`.
- [ ] Con token válido, área visible del condominio del usuario y `date` válida sin reservas confirmadas → `200` con `{"date": "<date>", "busy": []}`.
- [ ] Con varias reservas confirmadas de **distintas unidades** del condominio → todas aparecen en `busy`; ningún item lleva `id`, `unit_id`, `requester_id` ni nombres, solo las 4 claves de fecha/hora.
- [ ] Una reserva `cancelled` **no** aparece en `busy` (solo `confirmed` publicadas).
- [ ] Reserva del mismo día (no cruza medianoche): `start_date == end_date == date`, con las 4 claves presentes.
- [ ] Reserva que cruza medianoche consultada **desde su día de inicio** (`date`): `start_date = date`, `end_date = date + 1 día`.
- [ ] La misma reserva consultada **desde el día siguiente** (`date` = su día de fin): aparece con `start_date = date - 1`, `start_time` original, `end_date = date`, `end_time` original.
- [ ] Una reserva del día anterior que **no** cruza medianoche **no** aparece al consultar `date`.
- [ ] `busy` viene ordenado ascendente por `(start_date, start_time)`.
- [ ] Cualquier método distinto de `GET` sobre la ruta → `405 method_not_allowed`.
- [ ] No se agregan claves i18n nuevas (`missing_authorization`, `invalid_token`, `area_not_found`, `missing_field`, `invalid_field`, `method_not_allowed` ya existen en es/en).
- [ ] `myapi_reservation_has_overlap()` y `POST /api/v1/reservations` quedan sin cambios (mismo diff = 0 sobre esas funciones).
- [ ] Los unit tests de `myapi_reservation_busy_ranges()` pasan (`scripts/run-unit-tests.sh`).
- [ ] `docs/area.md` incluye la sección del endpoint y casa con el contrato implementado.

---

## Decisiones

- **Sí:** dispatcher y orquestación en `resources/area.resource.inc` (la ruta es de área) y la query de reservas en `includes/myapi.reservation_query.inc`. Respeta "un recurso no llama funciones internas de otro; lo compartido va a `includes/`". *(Descartado: meterlo en `reservation.resource.inc` — la ruta lee como de área; descartado un archivo `area_availability.resource.inc` dedicado — el endpoint es un sub-recurso del área, no justifica archivo propio.)*
- **Sí:** función de fetch **nueva e independiente** (`myapi_reservation_busy_rows`), sin tocar `myapi_reservation_has_overlap()`. Cero riesgo sobre el camino del `POST`. *(Descartado: extraer un `base select` compartido y hacer que `has_overlap` lo use — cumpliría mejor "sin duplicación" pero modifica una función crítica del `POST`, que está explícitamente fuera de alcance.)*
- **Sí:** separar la query (`busy_rows`) de la derivación pura (`busy_ranges`), para que la lógica de medianoche/orden sea unit-testable sin BD ni servidor. *(Descartado: derivar `start_date`/`end_date` y filtrar en SQL — mete la regla de medianoche en la query y la hace inverificable en unit.)*
- **Sí:** traer las dos fechas (`date` y `date - 1`) en una sola query con `SUBSTR(...) IN (:date, :prev_date)` y filtrar en PHP las de `prev_date` que no cruzan. Una query, un recorrido. *(Descartado: dos queries separadas — innecesario.)*
- **Sí:** `crosses = parse(end) <= parse(start)` con minutos, reutilizando el criterio existente. Robusto ante formato aunque `text(5)` ya garantice `HH:MM` comparable lexicográficamente.
- **Sí:** `date` inválida corta con `422` duro (no el manejo laxo de `page`/`limit`/`sort`). Sin fecha válida la respuesta no significa nada. *(Descartado: fallback silencioso a "hoy" u omitir el filtro.)*
- **Sí:** todo "no visible para vos" colapsa en un único `404 area_not_found`, reutilizando `myapi_area_fetch_one()` — mismo criterio no-revelador que `GET /api/v1/areas/{id}` (SPEC 39).
- **Sí:** las 4 claves de fecha/hora **siempre** presentes, en fechas absolutas. El cliente nunca infiere el día a partir de las horas.
- **No:** exponer `unit_id`/`requester_id`/`id`/nombres — el residente solo necesita saber que el horario está tomado, no quién lo tomó.
- **No:** paginación ni filtros extra — un área, un día.
- **No:** tests de integración/HTTP automatizados en esta entrega (decisión 3b) — unit sobre la derivación pura + matriz `curl` documentada; los fixtures de reservas/áreas en SimpleTest quedan para el spec de testing de reservas.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Tocar sin querer `myapi_reservation_has_overlap()` / el camino del `POST` al reutilizar su query. | La query nueva vive en un archivo aparte (`includes/myapi.reservation_query.inc`); `has_overlap` no se modifica. Criterio de aceptación explícito: diff = 0 sobre esa función. |
| `field_date` guarda `YYYY-MM-DD HH:MM:SS`, no `Y-m-d` plano. | `busy_rows` proyecta `date` con `SUBSTR(field_date_value, 1, 10)` (mismo patrón que el resto del archivo); `prev_date` se calcula con `strtotime($date.' -1 day')`, no restando strings. |
| DST / zona horaria en `date - 1 día`. | `field_date` se guarda con `tz_handling = none` (SPEC 32) y las fechas son `Y-m-d` sin hora; `strtotime('... -1 day')` sobre medianoche local no cruza saltos DST relevantes. Los tests unit fijan fechas concretas. |
| Un cambio de esquema en `field_date`/`field_start_time`/`field_end_time`/`field_area`/`field_reservation_status` (rename, multi-valor, tipo) rompe la lectura directa sin aviso de Drupal. | Mismo riesgo asumido por todo el módulo (lectura directa de `field_data_field_*`). El `n.type = 'reservation'` y el binding por `entity_id`/`entity_type` acotan los joins. Documentado en `docs/area.md`. |
| Reserva "confirmada" sin fila de `field_start_time`/`field_end_time`/`field_date` (dato incompleto). | Los inner joins la excluyen: sin las tres filas no aparece en `busy`. Consistente con `has_overlap`, que también hace inner join sobre esos campos. |
| Ventana ambigua si una reserva del día pedido a su vez cruza al día siguiente y el cliente vuelve a consultar ese día siguiente. | Es el comportamiento buscado: la misma reserva aparece en ambos días con fechas absolutas coherentes (`end_date`/`start_date` compartido). Cubierto por dos criterios de aceptación (vista desde inicio y desde fin). |

---

## Lo que **NO** está en este spec

- Corrección de `myapi_reservation_has_overlap()` / la validación lineal de minutos del `POST` para áreas que cruzan medianoche — va en otro spec.
- Cancel, listado y detalle de reservas.
- Cualquier escritura (crear/modificar/borrar) desde este endpoint.
- Filtros (`status`, rangos de fechas) y paginación.
- Tests de integración/HTTP automatizados con fixtures de reservas (solo unit sobre la derivación pura + plan `curl` en la doc).

Cada uno, si aterriza, va en su propio spec.
