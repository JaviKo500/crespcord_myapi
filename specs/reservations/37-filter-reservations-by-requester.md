# SPEC 37 — Filtrar el listado de reservas por solicitante ("Mis Reservas" real)

> **Estado:** Implemented · **Depende de:** SPEC 34 (listado de reservas por unidad) · **Fecha:** 2026-07-22
> **Objetivo:** Cambiar `GET /api/v1/units/{unit_id}/reservations` para que devuelva únicamente las reservas creadas por el usuario autenticado (`field_requester = uid`), en vez de todas las reservas de la vivienda sin importar quién las creó.

---

## Alcance

**Dentro:**

- Modificar `myapi_reservation_count()` y `myapi_reservation_fetch()` en `resources/reservation.resource.inc` para añadir una condición **obligatoria** sobre `field_data_field_requester.field_requester_target_id = uid` (el uid del token autenticado, no un parámetro de query).
- `myapi_reservation_list()` pasa `$uid` (ya disponible desde `myapi_auth_require_access_token()`) a ambos helpers.
- El control de acceso a `unit_id` (`myapi_unit_related_nids($uid)`) **no cambia**: sigue siendo obligatorio ser dueño/ocupante de la vivienda para consultar el endpoint. Este spec agrega un segundo filtro (por solicitante) además del control de acceso existente, no lo reemplaza.
- Actualizar `docs/reservation.md`: la sección de acceso y la nota "el listado devuelve solo nodos `reservation` ... con `field_unit`" pasan a documentar el filtro adicional por `requester_id = uid`.
- Actualizar los docblocks de `resources/reservation.resource.inc` que describen el criterio de filtrado actual (comentario `@file`, docblocks de `myapi_reservation_list()`, `myapi_reservation_count()`, `myapi_reservation_fetch()`).

**Fuera de alcance:**

- Un parámetro opcional para volver al comportamiento anterior (ver todas las reservas de la vivienda, de cualquier solicitante) — no se pide en este spec.
- Cambios a `POST /api/v1/reservations` o `PUT /api/v1/reservations/%/cancel`.
- Cambios a `status` / `date_from` / `date_to` / `sort` / `page` / `limit`, que siguen funcionando igual, ahora sobre el subconjunto del usuario autenticado.

---

## Modelo de datos

No hay cambios de esquema. Se añade una condición más sobre `field_data_field_requester`, tabla que el endpoint ya lee para poblar `requester_id` en el item de respuesta (SPEC 34).

---

## Plan de implementación

1. **`myapi_reservation_list($unit_id)`** — pasar `$uid` a `myapi_reservation_count()` y `myapi_reservation_fetch()`.
2. **`myapi_reservation_count($unit_id, $from, $to, $status, $uid)`** — agregar `innerJoin('field_data_field_requester', 'freq', ...)` + `condition('freq.field_requester_target_id', $uid)`.
3. **`myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from, $to, $status, $uid)`** — sobre el `leftJoin` a `freq` que ya existe (usado para el campo `requester_id` de salida), agregar `->condition('freq.field_requester_target_id', $uid)`. Equivalente en efecto a un inner join, ya que `field_requester` siempre tiene fila desde la creación (`myapi_reservation_build_node()` lo fija siempre).
4. Actualizar los docblocks afectados y `docs/reservation.md`.
5. `drush cc all` (no hay cambio de rutas, solo por consistencia del checklist).

---

## Criterios de aceptación

- [x] Con dos usuarios (A y B) con acceso a la misma unidad, cada uno ve — vía este endpoint — solo las reservas que él mismo creó; las del otro no aparecen.
- [x] `page`/`limit`/`sort`/`date_from`/`date_to`/`status` siguen funcionando igual, ahora sobre el subconjunto de reservas del usuario autenticado.
- [x] `total`/`total_pages` reflejan solo las reservas del usuario autenticado, no las de toda la unidad.
- [x] `403 unit_access_denied` sigue aplicando exactamente igual que antes (pertenencia del usuario a la unidad, no a la reserva individual).
- [x] `docs/reservation.md` actualizado para reflejar el nuevo criterio de filtrado.

---

## Decisiones

- **Sí:** el filtro por `requester_id = uid` es obligatorio y no configurable — coincide con el pedido explícito de que cada usuario vea solo las reservas que él mismo creó.
- **No:** no se agrega un parámetro para ver las reservas de otros ocupantes/dueños de la misma unidad; queda fuera de alcance salvo que se solicite explícitamente en un spec futuro.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un cliente (app Flutter) que dependía de ver todas las reservas de la unidad (de cualquier solicitante) deja de verlas | Cambio pedido explícitamente; documentado en este spec y en `docs/reservation.md`. |

## Lo que **NO** está en este spec

- Endpoint o parámetro para que un usuario vea las reservas de otros miembros de su misma unidad.
