# SPEC 38 — Endpoint de detalle de una reserva por id

> **Estado:** Implemented · **Depende de:** SPEC 34 (listado de reservas por unidad), SPEC 37 (filtro por solicitante), SPEC 36 (patrón de endpoint por id de reserva) · **Fecha:** 2026-07-23
> **Objetivo:** Exponer `GET /api/v1/reservations/{id}/details` como lectura de una sola reserva por su id, devolviendo el mismo item que el listado y aplicando **las mismas validaciones de acceso que el listado** (unidad del usuario + `field_requester = uid`).

---

## Alcance

**Dentro:**

- Nuevo dispatcher `myapi_reservation_details_dispatch($reservation_id)` en `resources/reservation.resource.inc` (solo `GET`; cualquier otro → `405 method_not_allowed`).
- `GET /api/v1/reservations/%/details`: lectura de un nodo `reservation` por id, autenticado por Bearer.
- Reglas de acceso idénticas al listado (SPEC 34 + 37): la reserva es visible solo si aparecería en el listado propio del usuario — nodo `reservation` publicado (`status = 1`), en una vivienda que el usuario posee/ocupa, y con `field_requester = uid`.
- Respuesta: el **mismo item de 14 claves** que el listado (incl. `area_category` y `cancel_deadline_minutes`), envuelto como `{"reservation": ...}` — mismo envoltorio que `POST`/`PUT cancel`.
- Reutiliza el mapeador de nodo→item ya existente (renombrado a `myapi_reservation_build_item_from_node()`), compartido por create, cancel y details.
- Ruta registrada en `hook_menu()`. No hay archivo nuevo (el recurso ya está en `myapi.info`). Doc en `docs/reservation.md`.
- `drush cc all` al final (ruta nueva).

**Fuera de alcance:**

- Cualquier escritura/edición sobre la reserva (ya cubierto por SPEC 35/36).
- Un parámetro para ver reservas de otros ocupantes/dueños de la misma unidad (igual que SPEC 37, fuera de alcance salvo pedido explícito).
- Chequeo de disponibilidad / conflicto de franjas.

---

## Modelo de datos

**No hay datos persistentes nuevos.** Lee el bundle `reservation` (SPEC 32) vía `node_load()` y, para los valores derivados del área (`area_name`, `area_category`, `cancel_deadline_minutes`), un `node_load()` del nodo `area` referenciado. Sin query de listado (no hay paginación/orden/filtros que armar).

**Item de respuesta** (`data.reservation`): idéntico al item del listado (SPEC 34, ampliado con `area_category` y `cancel_deadline_minutes`), producido por `myapi_reservation_build_item_from_node()`. Los tres valores derivados del área quedan `null` si el área fue borrada.

**Envelope:**

```json
{ "success": true, "data": { "reservation": { "...": "14 claves" } } }
```

Sin `message` (es una lectura simple, como el listado).

---

## Semántica de errores (decisión clave)

Todas las causas de "no visible para ti" colapsan en un único **`404 reservation_not_found`**:

- `{id}` no es entero positivo,
- el nodo no existe / no es `reservation` / no está publicado,
- la reserva es de **otro** solicitante (`field_requester != uid`),
- la reserva está en una vivienda que el usuario **no** posee/ocupa.

Motivo: el listado **nunca revela existencia** (SPEC 34: "sin acceso" y "no existe" colapsan en el mismo error) y además filtra por solicitante (SPEC 37). Trasladado a un recurso por id, la lectura no debe revelar si un id existe ni de quién es. Se reutiliza la clave i18n `reservation_not_found` (ya usada por SPEC 36); **no hay claves i18n nuevas**.

Esto se aparta **a propósito** de `PUT .../cancel` (SPEC 36), que sí distingue `403 reservation_forbidden` de `404 reservation_not_found`: el pedido de este spec es "las mismas validaciones que el listado", y el listado es no-revelador.

---

## Plan de implementación

1. **`myapi_reservation_details_dispatch($reservation_id)`** — `GET` → `myapi_reservation_details($reservation_id)`; cualquier otro → `myapi_error('method_not_allowed', 405)`.

2. **`myapi_reservation_details($reservation_id)`** — orquestación:
   - `myapi_auth_require_access_token()` → `$uid` (401 si falla).
   - `{id}` entero positivo, si no → `404 reservation_not_found`.
   - `node_load()`; si no existe, no es `reservation`, o `status != 1` → `404 reservation_not_found`.
   - `field_requester != uid` → `404 reservation_not_found` (SPEC 37).
   - `field_unit` no relacionada vía `myapi_unit_related_nids($uid)` → `404 reservation_not_found` (SPEC 34).
   - `node_load()` del área para `title` / `field_area_category` / `field_cancel_deadline_minutes` (NULL si el área falta).
   - `myapi_respond(['reservation' => myapi_reservation_build_item_from_node($node, $area_title, $area_category, $cancel_deadline_minutes)], 200)`.

3. **Renombrar `myapi_reservation_build_created_item()` → `myapi_reservation_build_item_from_node()`** (mismo cuerpo) y generalizar su docblock: ahora la usan create (201), cancel (200) y details (200). Actualizar las dos llamadas existentes.

4. **Registrar la ruta** en `hook_menu()` (`myapi.module`): `api/v1/reservations/%/details` → `page callback myapi_reservation_details_dispatch`, `page arguments [3]`, `access callback TRUE`, `file resources/reservation.resource.inc`, `type MENU_CALLBACK`.

5. **Documentar** en `docs/reservation.md` (sección nueva: método, auth, orden de validación, respuesta 200, tabla de errores, ejemplo curl).

6. **`drush cc all`** para tomar la ruta nueva.

---

## Criterios de aceptación

- [x] `GET /api/v1/reservations/{id}/details` sin token Bearer → `401 missing_authorization`; token inválido/expirado → `401 invalid_token`.
- [x] Con token válido y una reserva **propia** (creada por el usuario, en una unidad que posee/ocupa, publicada) → `200` con el item de 14 claves envuelto como `{"reservation": ...}`.
- [x] Item idéntico al del listado (mismas claves, mismos tipos y reglas de `null`), incluyendo `area_category` y `cancel_deadline_minutes` leídos del área; `null` cuando el área fue borrada.
- [x] `{id}` inexistente, no-`reservation`, no publicado, de **otro** solicitante, o en una unidad ajena → todos el **mismo** `404 reservation_not_found`, indistinguibles.
- [x] `POST`/`PUT`/`DELETE` sobre la ruta → `405 method_not_allowed`.
- [x] No se agregan claves i18n nuevas (`reservation_not_found`, `method_not_allowed`, `missing_authorization`, `invalid_token` ya existen).
- [x] `docs/reservation.md` incluye la sección del endpoint y casa con el contrato implementado.

---

## Decisiones

- **Sí:** colapsar todo "no visible" en un único `404 reservation_not_found`, para no revelar existencia ni pertenencia — traslado directo de la regla no-reveladora del listado (SPEC 34) al recurso por id.
- **Sí:** reutilizar el mapeador nodo→item (renombrado `myapi_reservation_build_item_from_node()`) en vez de re-correr la query de listado — el shape ya está garantizado idéntico y se evita duplicar la query grande.
- **Sí:** `field_requester = uid` obligatorio (SPEC 37) y acceso por unidad (SPEC 34), ambos aplicados, como pide "las mismas validaciones que el listado".
- **No:** distinguir `403` de `404` como en `cancel` (SPEC 36) — el listado es no-revelador y este endpoint lo replica.
- **No:** query params — un solo item no admite paginación/orden/filtros.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un cliente esperaba distinguir "no existe" de "no es tuya" (como en `cancel`) | Decisión explícita y documentada: este endpoint replica la regla no-reveladora del listado, no la de `cancel`. |
| Referencia `field_area` huérfana (área borrada) | `node_load()` del área devuelve NULL; `area_name`/`area_category`/`cancel_deadline_minutes` quedan `null`, igual que el `leftJoin` del listado, sin descartar la reserva. |
| El renombre de `build_created_item` deja referencias colgadas | Las llamadas del código se actualizaron; los specs 35/36 conservan el nombre histórico a propósito (registran el estado a su fecha). |

## Lo que **NO** está en este spec

- Escritura/edición de reservas (SPEC 35/36).
- Ver reservas de otros miembros de la misma unidad.
- Chequeo de disponibilidad / conflicto de franjas.
