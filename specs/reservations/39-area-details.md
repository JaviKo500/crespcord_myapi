# SPEC 39 — Endpoint de detalle de un área por id

> **Estado:** Implemented · **Depende de:** SPEC 33 (listado de áreas de un condominio), SPEC 38 (patrón de endpoint de detalle por id) · **Fecha:** 2026-07-23
> **Objetivo:** Exponer `GET /api/v1/areas/{id}` como lectura de una sola área por su id, devolviendo el mismo item que el listado y aplicando **las mismas validaciones que el `GET` de listado** (acceso al condominio + criterio de visibilidad por estado).

---

## Alcance

**Dentro:**

- Nuevo dispatcher `myapi_area_details_dispatch($area_id)` en `resources/area.resource.inc` (solo `GET`; cualquier otro → `405 method_not_allowed`).
- `GET /api/v1/areas/%`: lectura de un nodo `area` por id, autenticado por Bearer.
- Reglas idénticas al listado (SPEC 33): el área es visible solo si aparecería en el listado propio del usuario — nodo `area` publicado (`status = 1`), con `field_area_status IN MYAPI_AREA_VISIBLE_STATUSES` (`active`/`maintenance`), en un condominio al que el usuario está relacionado (`myapi_condominium_related_nids`).
- Respuesta: el **mismo item de 13 claves** que el listado (`myapi_area_build_item`), envuelto como `{"area": ...}`.
- **Refactor sin cambio de comportamiento** del listado: extraer `myapi_area_base_select()` (proyección completa + criterio de visibilidad) y reutilizarlo tanto en `myapi_area_fetch()` (listado) como en el nuevo `myapi_area_fetch_one()` (detalle), para no duplicar la query grande.
- Ruta registrada en `hook_menu()`. No hay archivo nuevo (el recurso ya está en `myapi.info`). Doc en `docs/area.md`.
- `drush cc all` al final (ruta nueva).

**Fuera de alcance:**

- Cualquier alta / edición / borrado de áreas (endpoints de escritura).
- Filtros por `category` / `who_can_reserve`.
- Exponer áreas `closed` o sin fila de estado (siguen ocultas, igual que en el listado).
- Control de acceso a archivos privados de `field_image` (igual que SPEC 33: se devuelve la URL, no un stream autenticado).

---

## Modelo de datos

**No hay datos persistentes nuevos.** Lee el bundle `area` (SPEC 32) vía la misma query base que el listado, restringida a `n.nid`. El criterio de visibilidad (`field_area_status` inner join con `IN (active, maintenance)`) queda dentro de `myapi_area_base_select()`, así que una área oculta simplemente no produce fila, tal como no aparece en el listado.

**Item de respuesta** (`data.area`): idéntico al item del listado (SPEC 33), producido por `myapi_area_build_item()`.

**Envelope:**

```json
{ "success": true, "data": { "area": { "...": "13 claves" } } }
```

Sin `message` (es una lectura simple, como el listado).

---

## Semántica de errores (decisión clave)

Todas las causas de "no visible para ti" colapsan en un único **`404 area_not_found`**:

- `{id}` no es entero positivo,
- el nodo no existe / no es `area` / no está publicado,
- el `field_area_status` no es visible (`closed` o sin fila de estado),
- el área está en un condominio al que el usuario **no** está relacionado.

Motivo: el listado ya es no-revelador — un área oculta o de otro condominio simplemente **no aparece** (SPEC 33). Trasladado a un recurso por id, la lectura no debe revelar si un id existe ni en qué condominio vive. Se reutiliza la clave i18n `area_not_found` (ya usada por SPEC 35 en la creación de reservas); **no hay claves i18n nuevas**.

A diferencia del listado, que usa `403 condominium_access_denied` (el condominio va en la ruta, así que el acceso se reporta sobre el condominio), aquí la ruta lleva solo el id del área, por lo que se usa un único `404` no-revelador. Consistente con el detalle de reservas (SPEC 38).

---

## Plan de implementación

1. **`myapi_area_base_select()`** — nuevo helper: `db_select('node')` con `type=area`, `status=1`; `innerJoin field_condominium` + `addField condominium_id` (sin condición de condominio); `innerJoin field_area_status` con `IN MYAPI_AREA_VISIBLE_STATUSES` + `addField status`; todos los `leftJoin` de campos mapeados + `file_managed` para `image_uri`. Devuelve el `SelectQuery` sin `orderBy`/`range`/condición de `nid` — el listado y el detalle agregan lo suyo.

2. **`myapi_area_fetch($condominium_id, $page, $limit, $sort)`** — pasa a usar `myapi_area_base_select()`, luego `->condition('fcon.field_condominium_target_id', $condominium_id)`, `orderBy` título + `nid`, `range`. Mismo SQL efectivo que antes.

3. **`myapi_area_fetch_one($area_id)`** — `myapi_area_base_select()` + `->condition('n.nid', $area_id)` + `fetchObject()`. Devuelve `FALSE` si no hay área visible que case.

4. **`myapi_area_details_dispatch($area_id)`** — `GET` → `myapi_area_details($area_id)`; cualquier otro → `405`.

5. **`myapi_area_details($area_id)`** — orquestación:
   - `myapi_auth_require_access_token()` → `$uid` (401 si falla).
   - `{id}` entero positivo, si no → `404 area_not_found`.
   - `myapi_area_fetch_one()`; si `FALSE` → `404 area_not_found`.
   - `field_condominium` del área no relacionada vía `myapi_condominium_related_nids($uid)` → `404 area_not_found`.
   - `myapi_respond(['area' => myapi_area_build_item($area)], 200)`.

6. **Registrar la ruta** en `hook_menu()` (`myapi.module`): `api/v1/areas/%` → `page callback myapi_area_details_dispatch`, `page arguments [3]`, `access callback TRUE`, `file resources/area.resource.inc`, `type MENU_CALLBACK`.

7. **Documentar** en `docs/area.md` (sección nueva del endpoint) y ajustar la nota del listado que decía "no single-area detail endpoint".

8. **`drush cc all`** para tomar la ruta nueva.

---

## Criterios de aceptación

- [x] `GET /api/v1/areas/{id}` sin token Bearer → `401 missing_authorization`; token inválido/expirado → `401 invalid_token`.
- [x] Con token válido y un área **visible** de un condominio del usuario → `200` con el item de 13 claves envuelto como `{"area": ...}`, idéntico al item del listado.
- [x] `{id}` inexistente, no-`area`, no publicado, con estado oculto (`closed`/sin fila), o en un condominio ajeno → todos el **mismo** `404 area_not_found`, indistinguibles.
- [x] `POST`/`PUT`/`DELETE` sobre la ruta → `405 method_not_allowed`.
- [x] El listado (`GET /api/v1/condominiums/%/areas`) sigue comportándose exactamente igual tras el refactor a `myapi_area_base_select()` (mismos items, paginación, orden y visibilidad).
- [x] No se agregan claves i18n nuevas (`area_not_found`, `method_not_allowed`, `missing_authorization`, `invalid_token` ya existen).
- [x] `docs/area.md` incluye la sección del endpoint y casa con el contrato implementado.

---

## Decisiones

- **Sí:** colapsar todo "no visible" en un único `404 area_not_found`, para no revelar existencia ni condominio — traslado directo de la regla no-reveladora del listado (SPEC 33) al recurso por id.
- **Sí:** extraer `myapi_area_base_select()` y reutilizarlo en listado y detalle, en vez de duplicar la query — cumple "sin duplicación de lógica" y garantiza shape idéntico.
- **Sí:** el criterio de visibilidad (`IN MYAPI_AREA_VISIBLE_STATUSES`) vive en la query base, así que aplica igual al detalle sin código extra.
- **No:** distinguir `403` de `404` — el listado es no-revelador y este endpoint lo replica; consistente con SPEC 38.
- **No:** query params — un solo item no admite paginación/orden/filtros.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El refactor a `myapi_area_base_select()` altera el comportamiento del listado | La query base reproduce exactamente los mismos joins/condiciones/proyección; el listado solo agrega la condición de condominio, el orden y el rango que ya tenía. `php -l` + prueba manual del listado. |
| Un cliente esperaba distinguir "no existe" de "sin acceso" (como el listado con `403`) | Decisión explícita y documentada: este endpoint replica la regla no-reveladora del listado sobre un recurso por id, con un único `404`. |
| Referencia `field_image` en filesystem privado | Igual que SPEC 33: se devuelve la URL vía `file_create_url()`, el control de acceso a archivos privados queda fuera de alcance. |

## Lo que **NO** está en este spec

- Escritura/edición/borrado de áreas.
- Filtros por `category` / `who_can_reserve`.
- Exponer áreas `closed` o sin estado.
