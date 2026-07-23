# SPEC 35 — Crear reserva de área comunal (`POST /api/v1/reservations`)

> **Estado:** Approved · **Depende de:** SPEC 09 (roles owner/occupant de unidad), SPEC 32 (content types de reservas), SPEC 33 (áreas), SPEC 34 (listado de reservas) · **Fecha:** 2026-07-22
> **Objetivo:** Exponer `POST /api/v1/reservations` como el endpoint autenticado que crea una reserva de área comunal para una unidad, aplicando en orden las ocho validaciones de negocio (rol, estado del área, no-pasado, horario, duración máxima, solapamiento, una-sola-reserva-activa y saldo) antes de escribir el nodo `reservation`.

**Notas de la cabecera:**

- No depende de SPEC 23 (`payment.resource.inc`) — ese es solo un referente de *patrón* de dispatcher/estado, no una dependencia funcional. Lo mismo aplica al spec hermano 36 (cancelación), que sí lo referenciará como patrón directo.
- Este spec **no** incluye el endpoint de cancelación — ese es SPEC 36, spec separado.

---

## Alcance

### Dentro de este spec

- **`resources/reservation.resource.inc`** (modificar — recurso ya existente de SPEC 34) — se agrega:
  - **Ruta de colección nueva** `api/v1/reservations`, con un dispatcher propio que enruta por método: solo `POST` → `myapi_reservation_create()`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
  - **`myapi_reservation_create()`** — exige access token, valida el body (`unit_id`, `area_id`, `date`, `start_time`, `duration_minutes`), aplica las 8 validaciones de negocio en el orden especificado, calcula `end_time`, crea el nodo `reservation` con `field_reservation_status = 'confirmed'` y responde `201`.
  - **Helpers nuevos** en el mismo archivo: cálculo de solapamiento, chequeo de reserva activa por unidad+área, y la query de saldo (recibo más reciente con `field_estado = 'Enviado'`), todos como funciones privadas del recurso (`myapi_reservation_*`), sin tocar `receipt.resource.inc` ni `payment.resource.inc`.
- **`myapi.module`** (modificar) — registrar `api/v1/reservations` en `hook_menu()` (`page arguments => []`, sin wildcard; `access callback => TRUE`, `file => resources/reservation.resource.inc`).
- **`includes/myapi.i18n.inc` / `docs/i18n.md`** (modificar) — nuevas claves: `reservation_role_not_allowed` (403), `area_not_active` (409), `area_not_found` (404), `reservation_outside_hours` (422), `reservation_duration_exceeded` (422), `reservation_overlap` (409), `reservation_already_active` (409), `insufficient_balance` (403), `reservation_created` (mensaje de éxito). Reutiliza `unit_access_denied`, `missing_authorization`, `invalid_token`, `method_not_allowed`, `invalid_field`, `missing_field` ya existentes.
- **`docs/reservation.md`** (modificar — ya existe desde SPEC 34) — agregar la sección `POST /api/v1/reservations` siguiendo la plantilla (auth, body, respuesta `201`, tabla de errores).
- **`myapi.info`** — sin cambios: `resources/reservation.resource.inc` ya está listado.

### Fuera de este spec

- **Cancelación de reserva** (`PUT /api/v1/reservations/%/cancel`) — SPEC 36, spec separado.
- **Endpoint de detalle de una reserva** (`GET /api/v1/reservations/%`) — no se pide.
- **Múltiplo de `field_slot_minutes`** — el enunciado solo valida contra `field_max_minutes`; no se valida que `duration_minutes` sea múltiplo del slot ni una duración mínima. Si hace falta, va en un spec futuro.
- **Notificaciones** (push/email) al crear una reserva — no mencionadas en el enunciado; los specs 27/30 de notificaciones son de pagos y no se extienden aquí.
- **Reversión de saldo o cualquier movimiento contable** — este endpoint solo **lee** `field_saldo_actual`/`field_saldo_anterior` para decidir si permite reservar; no escribe ningún saldo.
- **Reservas para viviendas o áreas de otro condominio** — cubierto como validación (404 `area_not_found` si el área no pertenece al condominio de la unidad), pero no hay soporte para reservas multi-condominio.

---

## Modelo de datos

No se crean tablas ni content types nuevos — el nodo `reservation` y sus campos ya existen (SPEC 32). Este spec define el contrato de request/response y la secuencia exacta de validaciones antes de escribir el nodo.

### Body (`POST /api/v1/reservations`)

| Campo | Tipo | Oblig. | Validación |
|---|---|---|---|
| `unit_id` | int | Sí | Entero positivo. Ausente/no numérico → `422 missing_field`/`invalid_field` (`@field = unit_id`). |
| `area_id` | int | Sí | Entero positivo. Ausente/no numérico → `422` igual criterio. |
| `date` | string | Sí | `YYYY-MM-DD`, validado con `checkdate()`. Formato inválido → `422 invalid_field` (`@field = date`). |
| `start_time` | string | Sí | `HH:MM` 24h, regex `^([01]\d|2[0-3]):([0-5]\d)$`. Inválido → `422 invalid_field` (`@field = start_time`). |
| `duration_minutes` | int | Sí | Entero positivo (`> 0`). Ausente/no numérico/`<= 0` → `422 invalid_field` (`@field = duration_minutes`). |

`end_time` se calcula en el servidor: `start_time + duration_minutes` (aritmética en minutos desde medianoche, formateado de vuelta a `HH:MM`). **No** se valida que el resultado quede dentro del mismo día — si `end_time` cruza medianoche (`>= 24:00`), es un caso de horario fuera de rango y cae naturalmente en la validación 4 (`reservation_outside_hours`), ya que ningún `field_close_time` real permite ese cruce.

### Orden de validaciones (cada una corta antes de tocar el nodo)

| # | Validación | Fuente de datos | Error si falla |
|---|---|---|---|
| 0a | Auth Bearer | `myapi_auth_require_access_token()` | `401 missing_authorization` / `401 invalid_token` |
| 0b | Body bien formado | tabla de arriba | `422 missing_field` / `422 invalid_field` |
| 0c | Acceso a `unit_id` | `myapi_unit_related_nids($uid)` | `403 unit_access_denied` |
| 0d | `area_id` existe y pertenece al condominio de `unit_id` | `node_load($area_id)`, `type='area'`, `field_condominium_target_id === field_condominio_target_id` de la unidad | `404 area_not_found` |
| 1 | Rol vs `field_who_can_reserve` del área | `myapi_user_owned_unit_nids($uid)` / `myapi_user_occupied_unit_nids($uid)` contra `unit_id`; si el área es `owner` o `tenant`, el rol debe coincidir; `both` → cualquiera | `403 reservation_role_not_allowed` |
| 2 | `field_area_status` del área | debe ser exactamente `active` | `409 area_not_active` |
| 3 | Fecha/hora no en el pasado | `date`+`start_time` vs `REQUEST_TIME` en timezone del sitio | `422 invalid_field` (`@field = date`) |
| 4 | Dentro de `field_open_time`–`field_close_time` | comparación en minutos desde medianoche; `start_time >= open` y `end_time <= close` | `422 reservation_outside_hours` |
| 5 | `duration_minutes <= field_max_minutes` | — | `422 reservation_duration_exceeded` |
| 6 | Solapamiento con otra reserva `confirmed` de la misma área/fecha | `new_start < existing_end AND new_end > existing_start` | `409 reservation_overlap` |
| 7 | Una sola reserva `confirmed` activa por unidad+área (fecha/hora de inicio aún no pasada) | mismo `unit_id`+`area_id`, `status='confirmed'`, `(date > hoy) OR (date = hoy AND start_time > ahora)` | `409 reservation_already_active` |
| 8 | Saldo | ver detalle abajo | `403 insufficient_balance` |

### Validación 8 — saldo (detalle)

1. Leer `field_saldo_actual_value` de la vivienda (`unit_id`). Si es `<= 0` (o no tiene fila) → **puede reservar**, no se sigue evaluando.
2. Si es `> 0`: buscar el nodo `recibo` con `field_vivienda_target_id = unit_id` **y** `field_estado_value = 'Enviado'`, ordenado por `field_periodo_value DESC` (mismo criterio de "más reciente" que el listado de recibos), `LIMIT 1`.
   - Sin resultado (la unidad no tiene ningún recibo enviado) → **puede reservar**.
   - Con resultado: si `field_saldo_anterior_value > 0` → **rechazar** (`403 insufficient_balance`). Si es `<= 0` o la fila no tiene ese campo → **puede reservar**.

Query propia con `db_select` sobre `field_data_field_estado`/`field_data_field_periodo`/`field_data_field_saldo_anterior`/`field_data_field_vivienda`, inline en `reservation.resource.inc` (no se reutiliza `receipt.resource.inc`, por la regla de aislamiento de recursos).

### Creación del nodo `reservation`

| Campo | Valor |
|---|---|
| `field_condominium` | `field_condominio_target_id` de la vivienda (`unit_id`) — **no** del área ni del body. |
| `field_unit` | `unit_id` |
| `field_requester` | `$uid` autenticado |
| `field_area` | `area_id` |
| `field_date` | `date` del body |
| `field_start_time` | `start_time` del body |
| `field_end_time` | calculado por el servidor |
| `field_reservation_status` | `'confirmed'` |
| `field_cancelled_by` | sin setear |

### Respuesta (`201`)

Mismo shape que los items de SPEC 34 (`myapi_reservation_build_item`), vía un helper nuevo `myapi_reservation_build_created_item($node, $area_title)`:

```json
{
  "success": true,
  "data": {
    "reservation": {
      "id": 91,
      "condominium_id": 7,
      "unit_id": 21,
      "requester_id": 34,
      "area_id": 42,
      "area_name": "Piscina principal",
      "date": "2026-07-25",
      "start_time": "10:00",
      "end_time": "12:00",
      "status": "confirmed",
      "cancelled_by": null,
      "created": "2026-07-22T14:30:00"
    }
  },
  "message": "Reserva creada correctamente."
}
```

---

## Plan de implementación

1. **`myapi.module` — registrar la ruta.** Agregar en `hook_menu()`:
   ```php
   $items['api/v1/reservations'] = [
     'page callback'   => 'myapi_reservation_create_dispatch',
     'page arguments'  => [],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/reservation.resource.inc',
   ];
   ```
   *Verificación: `drush cc all`, la ruta aparece en `admin/reports/menu` o `drush ev`.*

2. **`myapi_reservation_create_dispatch()`** en `reservation.resource.inc` — `myapi_request_method()`; si `POST` → `myapi_reservation_create()`; si no → `myapi_error('method_not_allowed', 405)`. Actualizar el bloque de `module_load_include()` al inicio del archivo si falta alguno (`myapi.request`, `myapi.response`, `myapi.i18n`, `myapi.token`, `myapi.auth`, `myapi.unit_access`).

3. **Helper `myapi_reservation_parse_time($hhmm)` / `myapi_reservation_add_minutes($hhmm, $minutes)`** — parseo y aritmética de horas en minutos desde medianoche, reutilizados por las validaciones 3-7 y el cálculo de `end_time`.

4. **`myapi_reservation_create()`** — orquestación, en el orden exacto de la tabla del modelo de datos:
   1. `myapi_auth_require_access_token()` → `$uid`.
   2. Leer y validar el body (`myapi_request_json_body()` + `myapi_request_require_fields()`/validaciones propias de tipo/formato para `unit_id`, `area_id`, `date`, `start_time`, `duration_minutes`).
   3. `in_array((int) $unit_id, myapi_unit_related_nids($uid))` → si no, `403 unit_access_denied`.
   4. `node_load($area_id)`; si `!$area || $area->type !== 'area'` → `404 area_not_found`. Cargar `field_condominio_target_id` de la unidad (`node_load($unit_id)`) y comparar con `field_condominium_target_id` del área → si difieren, `404 area_not_found` (mismo código, no distingue el motivo).
   5. Validación 1 (rol): `myapi_user_owned_unit_nids($uid)` / `myapi_user_occupied_unit_nids($uid)` vs `field_who_can_reserve` del área.
   6. Validación 2 (`field_area_status === 'active'`).
   7. Validación 3 (no-pasado): construir timestamp `date + start_time` en la timezone del sitio, comparar contra `REQUEST_TIME`.
   8. Calcular `end_time` con el helper del paso 3; validación 4 (horario) y validación 5 (`duration_minutes <= field_max_minutes`).
   9. Validación 6 (solapamiento): query sobre `reservation` `status=1`, `field_area_target_id = area_id`, `field_reservation_status_value = 'confirmed'`, `field_date_value` (día) `= date`, con el chequeo de intervalo en PHP tras traer las filas del día (pocas filas por día/área).
   10. Validación 7 (una activa por unidad+área): query sobre `reservation` con `field_unit_target_id = unit_id`, `field_area_target_id = area_id`, `field_reservation_status_value = 'confirmed'`, y `(date > hoy) OR (date = hoy AND start_time > ahora)`.
   11. Validación 8 (saldo): función nueva `myapi_reservation_check_balance($unit_id)` con la query descrita en el modelo de datos.
   12. Crear el nodo (`node_object_prepare()`/`entity_metadata_wrapper` o asignación directa `$node->field_*[LANGUAGE_NONE][0]['value'/'target_id']`, patrón ya usado en `payment.resource.inc`), `node_save($node)`.
   13. Recargar el título del área (`$area->title`) para `area_name`, `myapi_respond(['reservation' => myapi_reservation_build_created_item($node, $area->title)], 201, 'reservation_created')`.

5. **`myapi_reservation_build_created_item($node, $area_title)`** — mapea el nodo recién creado al mismo shape que `myapi_reservation_build_item()` (SPEC 34): castea ids a `int`, `date`/`start_time`/`end_time` passthrough, `status` = `field_reservation_status` value, `cancelled_by` = `null`, `created` = `format_date($node->created, 'custom', 'Y-m-d\TH:i:s')`.

6. **Catálogo i18n** (`includes/myapi.i18n.inc` + `docs/i18n.md`) — agregar en `es`/`en`: `reservation_role_not_allowed`, `area_not_active`, `area_not_found`, `reservation_outside_hours`, `reservation_duration_exceeded`, `reservation_overlap`, `reservation_already_active`, `insufficient_balance`, `reservation_created`.

7. **Documentar en `docs/reservation.md`** — nueva sección `POST /api/v1/reservations` (auth, body, respuesta `201`, tabla completa de errores `401/403/404/405/422/409`, nota sobre el orden de validaciones y sobre `field_condominium` derivado de la unidad).

8. **Aplicar y verificar** — `drush cc all` + pruebas manuales cubriendo cada validación 0a-8 en orden, más el caso feliz con y sin saldo pendiente.

**Nota:** no se toca `myapi.install` (los content types ya existen desde SPEC 32) ni `hook_schema()`.

---

## Criterios de aceptación

**Auth y body**
- [ ] Sin header `Authorization` → `401 missing_authorization`; token inválido/expirado → `401 invalid_token`.
- [ ] Falta `unit_id`, `area_id`, `date`, `start_time` o `duration_minutes` → `422 missing_field` (`@field` correspondiente).
- [ ] `date` no calendario, `start_time` fuera de `HH:MM` 24h, o `duration_minutes <= 0`/no numérico → `422 invalid_field` (`@field` correspondiente).
- [ ] `GET`/`PUT`/`DELETE` sobre `/api/v1/reservations` → `405 method_not_allowed`.

**Acceso**
- [ ] `unit_id` de una unidad que el usuario autenticado no posee ni ocupa (o inexistente) → `403 unit_access_denied`.
- [ ] `area_id` inexistente, o de un condominio distinto al de `unit_id` → `404 area_not_found`, indistinguibles.

**Validaciones de negocio (en orden, cada una sin modificar el nodo si falla)**
- [ ] Área `owner`-only reservada por un ocupante (o `tenant`-only por un propietario) → `403 reservation_role_not_allowed`. Área `both` acepta cualquiera de los dos roles.
- [ ] Área en `maintenance` o `closed` → `409 area_not_active`.
- [ ] `date`+`start_time` en el pasado respecto a la hora del servidor (timezone del sitio) → `422 invalid_field` (`@field = date`).
- [ ] Rango solicitado fuera de `field_open_time`–`field_close_time` del área → `422 reservation_outside_hours`.
- [ ] `duration_minutes` mayor a `field_max_minutes` del área → `422 reservation_duration_exceeded`.
- [ ] Rango solicitado se cruza con otra reserva `confirmed` de la misma área en la misma fecha (según el criterio de intervalo semiabierto) → `409 reservation_overlap`; una reserva que termina exactamente cuando otra empieza **no** se considera solapamiento.
- [ ] La unidad ya tiene una reserva `confirmed` para la misma área cuya fecha/hora de inicio aún no pasó → `409 reservation_already_active`, sin importar si es hoy o un día futuro.
- [ ] `field_saldo_actual <= 0` de la unidad → puede reservar sin evaluar recibos.
- [ ] `field_saldo_actual > 0` y el recibo `Enviado` más reciente (por `field_periodo`) tiene `field_saldo_anterior > 0` → `403 insufficient_balance`.
- [ ] `field_saldo_actual > 0` pero sin recibo `Enviado`, o el más reciente tiene `field_saldo_anterior <= 0`/sin fila → puede reservar.

**Éxito**
- [ ] Con todas las validaciones satisfechas, `201` con `data.reservation` en el shape documentado; `field_reservation_status` queda `'confirmed'`, `field_condominium` igual al de la unidad, `field_requester` igual al `uid` autenticado.
- [ ] `end_time` calculado coincide con `start_time + duration_minutes`.
- [ ] La reserva creada aparece luego en `GET /api/v1/units/{unit_id}/reservations` (SPEC 34) con los mismos valores.

**No regresión**
- [ ] `GET /api/v1/units/%/reservations` (SPEC 34) sigue funcionando idéntico.
- [ ] `docs/reservation.md` incluye la sección `POST /api/v1/reservations` completa.
- [ ] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Un spec vs dos | Dos specs separados (35 crear, 36 cancelar) | Un único spec para ambos endpoints | Mismo precedente que payments (20 crear / 23 anular); cada uno se revisa/implementa por separado aunque compartan archivo. |
| Formato de duración en el body | Solo `duration_minutes`; el servidor calcula `end_time` | Aceptar `end_time` explícito, o ambos | Un solo formato de entrada evita ambigüedad si el cliente manda los dos; el enunciado ya sugería minutos como opción primaria. |
| Ubicación de la query de saldo | Inline en `reservation.resource.inc`, sin helper compartido en `includes/` | Helper nuevo `includes/myapi.receipt_balance.inc` | Hoy solo la usa este endpoint; se extrae a `includes/` si un segundo consumidor aparece, siguiendo la regla de "shared logic" solo cuando hay reutilización real. |
| Origen de `field_condominium` en el nodo `reservation` | `field_condominio_target_id` de la vivienda (`unit_id`) | `field_condominium_target_id` del área | La unidad es la fuente de verdad de pertenencia real del solicitante; el área ya se valida contra ese mismo condominio, así que ambos coinciden cuando la request es válida. |
| Área de otro condominio | `404 area_not_found`, mismo código que área inexistente | Un código distinto (p. ej. `403`) | No se distingue "no existe" de "no pertenece a tu condominio"; mismo criterio de no-filtrado que `unit_access_denied`. |
| Código de rol no permitido | `403 reservation_role_not_allowed` | `422` | Es una cuestión de permiso del solicitante sobre el área, no un error de formato del body. |
| Código de área no activa | `409 area_not_active` | `422` | Conflicto de estado del recurso (el área), mismo criterio que `409 payment_not_pending` en spec 23. |
| Código de solapamiento y de reserva ya activa | `409` para ambos (`reservation_overlap`, `reservation_already_active`) | `422` | Son conflictos con el estado de otros recursos (otras reservas), no errores de formato del request. |
| Código de saldo insuficiente | `403 insufficient_balance` | `402 Payment Required` / `409` | `402` no está en la lista de códigos del proyecto (CLAUDE.md); `403` refleja que el solicitante no tiene permiso para reservar en su situación de saldo actual. |
| Criterio de solapamiento | Intervalo semiabierto (`new_start < existing_end AND new_end > existing_start`) | Incluir bordes como solapamiento | Permite reservas back-to-back (una termina exactamente cuando otra empieza), uso común en reserva de espacios por franjas. |
| Multiplicidad de `field_slot_minutes` | No se valida | Exigir que `duration_minutes` sea múltiplo del slot | El enunciado del usuario solo pide validar contra `field_max_minutes`; SPEC 32 ya marcó esa regla como "futura", y no fue pedida explícitamente aquí. |
| Multi-condominio / notificaciones | Fuera de alcance | Incluir en este spec | No mencionados en el enunciado original; se mantienen como specs futuros si se necesitan. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Condición de carrera en solapamiento/reserva-activa.** Dos `POST` casi simultáneos para la misma área/franja podrían pasar ambos la validación antes de que el primero se guarde (sin lock a nivel BD). | Aceptado como riesgo residual mínimo, mismo criterio que la doble-anulación de payments (spec 23); en el peor caso quedan dos reservas superpuestas, corregible manualmente. Si se vuelve un problema real, un spec futuro puede agregar un lock optimista o una constraint única. |
| **`field_condominio` (vivienda) vs `field_condominium` (area/reservation) — nombres distintos por legado.** Confundir estos dos campos al implementar rompería la asignación de condominio o el chequeo cruzado de área. | Documentado explícitamente en este spec y en `docs/reservation.md`; los nombres exactos de columna quedan fijados en la tabla de creación del nodo. |
| **Cálculo de `end_time` cruzando medianoche.** Una reserva `23:30` + `120` minutos da `01:30`, fuera de cualquier horario real de área. | Cae naturalmente en la validación 4 (`reservation_outside_hours`), ya que ningún área tiene `field_close_time` tras medianoche; no hace falta un chequeo aparte. |
| **Timezone del servidor vs cliente.** La validación de "no en el pasado" y el horario dependen de la timezone configurada en Drupal. | Mismo criterio que `created` en spec 34: se usa consistentemente la timezone del sitio en todo el módulo; documentado en `docs/reservation.md`. |
| **Precisión de `field_saldo_actual`/`field_saldo_anterior` (`decimal`) al compararse en PHP.** Conversión a `float` podría introducir imprecisión en casos extremos cerca de `0`. | Mismo riesgo aceptado que en specs 10/11 (`current_balance`, `previous_balance`); la comparación es `> 0` / `<= 0`, con margen suficiente para que la imprecisión de punto flotante no cambie el resultado en la práctica. |
| **Query de solapamiento trae todas las reservas del día/área a PHP para comparar intervalos.** Si un área tuviera un volumen inusualmente alto de reservas por día, el chequeo en PHP escalaría linealmente. | Aceptado: el volumen esperado por área/día es bajo (reservas de áreas comunes de un condominio); si se vuelve un problema, se puede mover el chequeo a SQL en un ajuste puntual. |

## Lo que **NO** está en este spec

- Cancelación de reserva (SPEC 36).
- Endpoint de detalle de reserva.
- Validación de múltiplo de `field_slot_minutes`.
- Notificaciones de creación de reserva.
- Reversión o escritura de saldo.
