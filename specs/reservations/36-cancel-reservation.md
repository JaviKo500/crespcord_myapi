# SPEC 36 — Cancelar reserva propia (`PUT /api/v1/reservations/%/cancel`)

> **Estado:** Draft · **Depende de:** SPEC 23 (patrón de anulación soft-cancel de `payment.resource.inc`), SPEC 32 (content types de reservas), SPEC 35 (creación de reserva) · **Fecha:** 2026-07-22
> **Objetivo:** Agregar `PUT /api/v1/reservations/%/cancel`, un endpoint autenticado que permite al usuario que figura en `field_requester` de una reserva `confirmed` cancelarla —soft-cancel, reescribiendo `field_reservation_status` a `'cancelled'` y `field_cancelled_by` a `'user'`— siempre que falten más minutos que `field_cancel_deadline_minutes` del área entre la hora actual del servidor y el inicio de la reserva.

**Notas de la cabecera:**

- Depende de SPEC 23 solo como **patrón** (dispatcher de acción con id, soft-cancel, orden de validaciones), no como dependencia funcional — no se toca `payment.resource.inc`.
- Depende de SPEC 35 porque opera sobre nodos `reservation` creados por ese endpoint, pero no modifica su código.

---

## Alcance

### Dentro de este spec

- **`resources/reservation.resource.inc`** (modificar — recurso ya existente) — se agrega:
  - **Ruta de item nueva** `api/v1/reservations/%/cancel`, con un dispatcher propio que enruta por método: solo `PUT` → `myapi_reservation_cancel($reservation_id)`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
  - **`myapi_reservation_cancel($reservation_id)`** — exige access token, carga el nodo `reservation` (404 si no existe/no es de ese tipo), valida que el usuario autenticado sea exactamente el `field_requester` (403 si no), valida que `field_reservation_status` sea exactamente `'confirmed'` (409 si no), valida el margen de `field_cancel_deadline_minutes` del área contra la hora actual del servidor (409 si no alcanza), reescribe `field_reservation_status` a `'cancelled'` y `field_cancelled_by` a `'user'`, guarda con `node_save()` y responde `200` con la reserva actualizada.
  - **Helper reutilizado**: `myapi_reservation_build_created_item()` (de SPEC 35) para la respuesta, ya que el shape es idéntico.
- **`myapi.module`** (modificar) — registrar `api/v1/reservations/%/cancel` en `hook_menu()` (`page arguments => [3]`, `access callback => TRUE`, `file => resources/reservation.resource.inc`).
- **`includes/myapi.i18n.inc` / `docs/i18n.md`** (modificar) — nuevas claves: `reservation_not_found` (404), `reservation_forbidden` (403), `reservation_not_confirmed` (409), `reservation_cancel_window_expired` (409), `reservation_cancelled` (mensaje de éxito). Reutiliza `missing_authorization`, `invalid_token`, `method_not_allowed` ya existentes.
- **`docs/reservation.md`** (modificar) — agregar la sección `PUT /api/v1/reservations/%/cancel` siguiendo la plantilla.
- **`myapi.info`** — sin cambios: `resources/reservation.resource.inc` ya está listado.

### Fuera de este spec

- **Cancelación por un administrador** — solo el propio `field_requester` puede cancelar; ningún rol con permisos ampliados está contemplado aquí.
- **Cancelación por cualquier propietario/ocupante de la unidad** — decisión explícita: distinto del criterio de `payment.resource.inc`, aquí solo el solicitante original.
- **Reactivar una reserva cancelada** — no hay endpoint para volver `'cancelled'` a `'confirmed'`.
- **Notificaciones de cancelación** — no mencionadas en el enunciado; fuera de alcance, igual que en SPEC 35.
- **Borrar el nodo** — soft-cancel únicamente, se conserva el nodo intacto salvo los dos campos reescritos.
- **Motivo de cancelación (`reason`)** — no fue pedido para este endpoint (a diferencia de `payment.resource.inc`); el body de la request no lleva ningún campo.

---

## Modelo de datos

No se crean tablas ni campos nuevos. Este endpoint solo lee y reescribe el nodo `reservation` (SPEC 32) y lee `field_cancel_deadline_minutes` del área asociada (SPEC 32/33).

### Body

Ninguno. El `reservation_id` viaja por la ruta (`api/v1/reservations/%/cancel`); cualquier body enviado se ignora.

### Orden de validaciones (cada una corta antes de tocar el nodo)

| # | Validación | Fuente de datos | Error si falla |
|---|---|---|---|
| 1 | Auth Bearer | `myapi_auth_require_access_token()` | `401 missing_authorization` / `401 invalid_token` |
| 2 | `reservation_id` es un entero > 0, el nodo existe y es tipo `reservation` | `node_load($reservation_id)` | `404 reservation_not_found` |
| 3 | El usuario autenticado es exactamente `field_requester` de la reserva | `$node->field_requester[LANGUAGE_NONE][0]['target_id'] === $uid` | `403 reservation_forbidden` |
| 4 | `field_reservation_status` es exactamente `'confirmed'` | — | `409 reservation_not_confirmed` |
| 5 | Ventana de cancelación: minutos entre ahora y el inicio de la reserva > `field_cancel_deadline_minutes` del área | `field_date`+`field_start_time` de la reserva vs `REQUEST_TIME` (timezone del sitio); `field_cancel_deadline_minutes` del nodo `area` referenciado en `field_area` | `409 reservation_cancel_window_expired` |

**Cálculo de la ventana:** `minutes_until_start = floor((timestamp(date, start_time) - REQUEST_TIME) / 60)`. Se permite cancelar solo si `minutes_until_start > field_cancel_deadline_minutes`. Si la reserva ya pasó (`minutes_until_start` negativo), también cae en `409 reservation_cancel_window_expired` — no hace falta un caso aparte para "reserva ya pasada".

### Cambios en el nodo al cancelar

| Campo | Valor nuevo |
|---|---|
| `field_reservation_status` | `'cancelled'` |
| `field_cancelled_by` | `'user'` |
| Todos los demás campos | Sin cambios (soft-cancel, igual que `payment.resource.inc` spec 23). |

### Respuesta (`200`)

Mismo shape que SPEC 34/35, reutilizando `myapi_reservation_build_created_item($node, $area_title)`:

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
      "status": "cancelled",
      "cancelled_by": "user",
      "created": "2026-07-22T14:30:00"
    }
  },
  "message": "Reserva cancelada correctamente."
}
```

---

## Plan de implementación

1. **Registrar la ruta en `myapi.module`** (`hook_menu()`):
   ```php
   $items['api/v1/reservations/%/cancel'] = [
     'page callback'   => 'myapi_reservation_cancel_dispatch',
     'page arguments'  => [3],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/reservation.resource.inc',
   ];
   ```
   *Verificación: `drush cc all`.*

2. **`myapi_reservation_cancel_dispatch($reservation_id)`** en `reservation.resource.inc` — `myapi_request_method()`; si `PUT` → `myapi_reservation_cancel($reservation_id)`; si no → `myapi_error('method_not_allowed', 405)`.

3. **`myapi_reservation_cancel($reservation_id)`** — orquesta, en este orden (cada validación corta antes de modificar nada):
   1. `$row = myapi_auth_require_access_token(); $uid = $row->uid;` (corta `401`).
   2. `$reservation_id` de la ruta: si no es un entero > 0, o `node_load()` devuelve `NULL`, o `$node->type !== 'reservation'` → `myapi_error('reservation_not_found', 404)`.
   3. **Autoría**: `$requester_uid = $node->field_requester[LANGUAGE_NONE][0]['target_id'] ?? NULL;` si `(int) $requester_uid !== (int) $uid` → `myapi_error('reservation_forbidden', 403)`.
   4. **Estado**: `$status = $node->field_reservation_status[LANGUAGE_NONE][0]['value'] ?? NULL;` si `$status !== 'confirmed'` → `myapi_error('reservation_not_confirmed', 409)`.
   5. **Ventana de cancelación**: cargar `$area = node_load($node->field_area[LANGUAGE_NONE][0]['target_id']);`, leer `field_cancel_deadline_minutes` del área; calcular `minutes_until_start` con el helper `myapi_reservation_add_minutes()`/parseo de tiempo ya creado en SPEC 35 (reutilizado dentro del mismo archivo); si `minutes_until_start <= $deadline` → `myapi_error('reservation_cancel_window_expired', 409)`.
   6. **Aplicar la cancelación**:
      ```php
      $node->field_reservation_status[LANGUAGE_NONE][0]['value'] = 'cancelled';
      $node->field_cancelled_by[LANGUAGE_NONE][0]['value'] = 'user';
      node_save($node);
      ```
   7. Responder `myapi_respond(['reservation' => myapi_reservation_build_created_item($node, $area->title)], 200, 'reservation_cancelled');`.

4. **Catálogo i18n** (`includes/myapi.i18n.inc` + `docs/i18n.md`) — agregar en `es`/`en`: `reservation_not_found`, `reservation_forbidden`, `reservation_not_confirmed`, `reservation_cancel_window_expired`, `reservation_cancelled`.

5. **Documentar en `docs/reservation.md`** — nueva sección `PUT /api/v1/reservations/%/cancel` (auth, sin body, respuesta `200`, tabla de errores `401/403/404/405/409`).

6. **Aplicar y verificar** — `drush cc all` + pruebas manuales: crear una reserva (SPEC 35), cancelarla dentro de la ventana permitida, verificar `status`/`cancelled_by` en BD; intentar cancelar de nuevo (`409 reservation_not_confirmed`); intentar cancelar una reserva ajena (`403 reservation_forbidden`); intentar cancelar fuera de la ventana de `field_cancel_deadline_minutes` (`409 reservation_cancel_window_expired`); `reservation_id` inexistente (`404`).

---

## Criterios de aceptación

**Éxito**
- [ ] `PUT /api/v1/reservations/{id}/cancel` con token válido, reserva propia (`field_requester` = uid autenticado) en estado `'confirmed'`, y dentro de la ventana de cancelación → `200` con `data.reservation`; `status` = `'cancelled'`, `cancelled_by` = `'user'`.
- [ ] Tras cancelar, el nodo en BD tiene `field_reservation_status` = `'cancelled'` y `field_cancelled_by` = `'user'`; todos los demás campos (`field_unit`, `field_area`, `field_date`, `field_start_time`, `field_end_time`, `field_condominium`, `field_requester`) quedan intactos.
- [ ] `GET /api/v1/units/%/reservations` (SPEC 34) muestra la reserva cancelada con `status: "cancelled"` (esa lista ya devuelve ambos estados).

**Autenticación**
- [ ] Sin header `Authorization` → `401 missing_authorization`; token inválido/expirado → `401 invalid_token`.

**Existencia**
- [ ] `reservation_id` inexistente, o de un nodo que no es tipo `reservation` → `404 reservation_not_found`.

**Autoría**
- [ ] Un usuario que es propietario/ocupante de la unidad pero **no** es el `field_requester` de esa reserva → `403 reservation_forbidden`, la reserva no se modifica.

**Estado**
- [ ] Una reserva ya `'cancelled'` → `409 reservation_not_confirmed`, sin modificar el nodo (idempotencia: cancelar dos veces falla la segunda vez).

**Ventana de cancelación**
- [ ] Faltan más minutos que `field_cancel_deadline_minutes` del área hasta el inicio → cancelación permitida.
- [ ] Faltan exactamente o menos minutos que `field_cancel_deadline_minutes`, o la reserva ya empezó/pasó → `409 reservation_cancel_window_expired`, sin modificar el nodo.

**Método y no regresión**
- [ ] Cualquier método distinto de `PUT` sobre `/api/v1/reservations/{id}/cancel` → `405 method_not_allowed`.
- [ ] `POST /api/v1/reservations` (SPEC 35) y `GET /api/v1/units/%/reservations` (SPEC 34) siguen funcionando idénticos.
- [ ] Todas las claves i18n nuevas están en el catálogo en `es`/`en`.
- [ ] `docs/reservation.md` incluye la sección `PUT /api/v1/reservations/%/cancel` completa.
- [ ] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Método y ruta HTTP | `PUT /api/v1/reservations/%/cancel` | `DELETE /api/v1/reservations/%` | Mismo criterio que spec 23 (payments): ruta de acción explícita, autodescriptiva; deja `DELETE` libre para un futuro borrado real. |
| Efecto sobre el nodo | Soft-cancel: reescribir `field_reservation_status`/`field_cancelled_by`, conservando el nodo | `node_delete()` de la reserva | Consistente con el patrón de `payment.resource.inc` (spec 23); conserva historial/auditoría. |
| Quién puede cancelar | Solo el `field_requester` exacto | Cualquier propietario/ocupante de la unidad (como en payments) | Pedido explícito del usuario: distinto del criterio de payments a propósito, porque la reserva es un compromiso personal del solicitante, no de la unidad completa. |
| Código de "no soy el requester" | `403 reservation_forbidden` (código propio) | Reutilizar `403 unit_access_denied` | El motivo es distinto (no es un problema de acceso a la unidad, sino de autoría de la reserva); un código propio evita que el cliente interprete mal el error. |
| Código de reserva no confirmada | `409 reservation_not_confirmed` | `404` (tratarla como si no existiera) | Mismo criterio que `409 payment_not_pending`: la reserva existe y es del usuario, pero su estado actual no permite la operación — es un conflicto de estado, no de existencia. |
| Código de ventana vencida | `409 reservation_cancel_window_expired` | `403` / `422` | Conflicto de estado temporal del recurso (igual criterio que `area_not_active`/`reservation_overlap` en spec 35), no un problema de permisos ni de formato del request. |
| Reserva ya pasada | Cae en el mismo `409 reservation_cancel_window_expired` que "ventana vencida", sin caso aparte | Un código distinto para "la reserva ya pasó" | `minutes_until_start` negativo siempre es `<= field_cancel_deadline_minutes`; no hay necesidad de distinguir el motivo exacto para el cliente. |
| `reason` de cancelación | No se pide (a diferencia de payments) | Campo opcional igual que `payment.resource.inc` | No fue solicitado por el usuario para este endpoint; se agrega en un spec futuro si hace falta. |
| Reactivación | Fuera de alcance | Endpoint para volver `'cancelled'` a `'confirmed'` | No solicitado; mismo criterio que la falta de "undo" en payments. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Condición de carrera en doble cancelación.** Dos `PUT /cancel` casi simultáneos sobre la misma reserva podrían leer `'confirmed'` ambos antes de que el primero guarde. | Aceptado, mismo criterio que la doble-anulación de payments (spec 23); riesgo residual mínimo, ambas peticiones terminan en el mismo estado final `'cancelled'`. |
| **`field_cancel_deadline_minutes` del área cambia después de creada la reserva.** Si el admin ajusta ese valor en el área tras la creación, la ventana de cancelación de reservas ya existentes cambia retroactivamente. | Aceptado: el campo se lee en vivo del área al momento de cancelar, no se congela un valor en la reserva; es el comportamiento esperado (la política de cancelación es del área, no de la reserva puntual). |
| **Timezone del servidor vs cliente al calcular `minutes_until_start`.** | Mismo criterio que SPEC 35: se usa consistentemente la timezone del sitio Drupal en todo el módulo. |
| **Reserva con área borrada.** Si el nodo `area` referenciado en `field_area` ya no existe, `node_load()` del área devuelve `NULL` y no hay `field_cancel_deadline_minutes` que leer. | Tratado como `409 reservation_cancel_window_expired` (no se puede confirmar la ventana, se asume vencida por seguridad) — documentado en `docs/reservation.md` como caso límite. |

## Lo que **NO** está en este spec

- Cancelación por administrador o por cualquier propietario/ocupante de la unidad.
- Reactivación de una reserva cancelada.
- Motivo de cancelación (`reason`).
- Notificaciones de cancelación.
- Borrado del nodo.
