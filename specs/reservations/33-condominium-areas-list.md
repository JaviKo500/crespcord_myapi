# SPEC 33 — Endpoint de listado de áreas (áreas de un condominio, solo lectura)

> **Estado:** Implemented · **Depende de:** SPEC 14 (patrón de listado de payments), SPEC 32 (tipos de contenido de reservas) · **Fecha:** 2026-07-22
> **Objetivo:** Exponer `GET /api/v1/condominiums/{condominium_id}/areas` como un listado paginado y de solo lectura de los nodos `area` visibles (`active`/`maintenance`) de un condominio al que el usuario autenticado tiene acceso, replicando el patrón de payments.

---

## Alcance

**Dentro:**

- Nuevo archivo de recurso `resources/area.resource.inc` con un dispatcher por método (solo `GET`; cualquier otro → 405).
- `GET /api/v1/condominiums/%/areas`: listado paginado de nodos `area`, autenticado por Bearer.
- Control de acceso: `condominium_id` validado contra `myapi_condominium_related_nids($uid)`; sin acceso **o** condominio inexistente → el mismo `403 condominium_access_denied` (indistinguibles).
- Control de visibilidad: solo nodos `area` publicados (`status = 1`) con `field_condominium = condominium_id` **y** `field_area_status IN (active, maintenance)`, centralizado en una constante `MYAPI_AREA_VISIBLE_STATUSES`.
- Query params espejando payments **salvo fechas**: `page`, `limit` (con `-1`), `sort` (`asc`/`desc`, default `desc`) sobre el **título**, con desempate por `nid` en la misma dirección.
- Ruta registrada en `hook_menu()`, archivo listado en `myapi.info` (`files[]`), doc `docs/area.md`.
- `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- El endpoint de listado de reservas — ese es el **SPEC 34**.
- Cualquier alta / edición / borrado de áreas (endpoints de escritura).
- Un endpoint de detalle de área (`GET /api/v1/areas/%`).
- Filtro por rango de fechas (las áreas no tienen dimensión temporal) y filtros por `category` / `who_can_reserve`.
- Exponer áreas `closed` o áreas sin fila de estado.

---

## Modelo de datos

**No hay datos persistentes nuevos.** Este endpoint solo lee el bundle `area` y sus tablas `field_data_*` creadas en el SPEC 32. Define un contrato de respuesta y un contrato de query params.

**Constante**

```php
// Only these area statuses are visible to the app (inclusion criterion).
// 'closed' and any area with no status row are excluded.
define('MYAPI_AREA_VISIBLE_STATUSES', ['active', 'maintenance']);
```

**Query params** (parseo laxo, fallback en silencio — nunca un 422):

| Param | Default | Regla |
|---|---|---|
| `page` | `1` | 1-based; valor no-entero-positivo → default en silencio. |
| `limit` | `20` | clamp `[1,50]`; `-1` = sin paginar (fuerza `page=1`). |
| `sort` | `desc` | `asc`/`desc` sobre `node.title`; desempate `n.nid` en la misma dirección. |

**Item de respuesta** (`data.areas[]`); los campos de texto/número van `null` cuando el nodo no tiene fila:

```json
{
  "id": 42,
  "name": "Piscina principal",
  "condominium_id": 7,
  "image_id": 15,
  "image_url": "https://host/sites/default/files/piscina.jpg",
  "open_time": "08:00",
  "close_time": "22:00",
  "slot_minutes": 60,
  "max_minutes": 120,
  "status": "active",
  "who_can_reserve": "both",
  "cancel_deadline_minutes": 120,
  "category": "pool"
}
```

- `id` = `nid` (int), `name` = `node.title`, `condominium_id` = `field_condominium_target_id` (int).
- `image_id` = `field_image_fid` (int) o `null`; `image_url` = `file_create_url(file_managed.uri)` o `null` (ambos `null` juntos cuando no hay imagen).
- `slot_minutes`, `max_minutes`, `cancel_deadline_minutes` a `int` cuando hay fila, si no `null`.
- `open_time`, `close_time`, `status`, `who_can_reserve`, `category` pasan tal cual se almacenan.

**Envelope** (idéntico a payments):

```json
{ "success": true, "data": { "areas": [ ... ], "pagination": { "total": 0, "page": 1, "limit": 20, "total_pages": 0 } } }
```

- `total_pages` = `0` cuando `total` es `0`; `1` cuando `limit=-1` y `total>0`. Página fuera de rango → `200` con `areas: []`.

---

## Plan de implementación

1. **Crear `resources/area.resource.inc`** con el docblock `@file`, el bloque de `module_load_include()` (request, response, i18n, token, auth, unit_access) y la constante `MYAPI_AREA_VISIBLE_STATUSES`. Registrarlo en `myapi.info` (`files[] = resources/area.resource.inc`). Committeable: el archivo carga, aún sin ruta.

2. **`myapi_area_dispatch($condominium_id)`** — enruta por método HTTP: `GET` → `myapi_area_list($condominium_id)`; cualquier otro → `myapi_error('method_not_allowed', 405)`.

3. **`myapi_area_list($condominium_id)`** — la orquestación, espejando `myapi_payment_list()`:
   - `myapi_auth_require_access_token()` → `$uid` (401 si falla).
   - `in_array((int) $condominium_id, myapi_condominium_related_nids($uid))` → si no, `myapi_error('condominium_access_denied', 403)`.
   - Parsear `page` / `limit` / `sort` con el idiom exacto de payments (sin rango de fechas).
   - `$total = myapi_area_count(...)`, calcular `total_pages`, `$rows = myapi_area_fetch(...)`, `array_map('myapi_area_build_item', $rows)`, `myapi_respond([...], 200)`.

4. **`myapi_area_count($condominium_id)`** — `db_select('node')` con `type='area'`, `status=1`; `innerJoin field_data_field_condominium` sobre `condominium_id`; `innerJoin field_data_field_area_status` con `field_area_status_value IN (MYAPI_AREA_VISIBLE_STATUSES)`; `countQuery()`. El inner join sobre el estado impone el criterio por inclusión (sin fila → excluido).

5. **`myapi_area_fetch($condominium_id, $page, $limit, $sort)`** — la misma query base más un `leftJoin` por cada campo mapeado (`field_image`, `field_open_time`, `field_close_time`, `field_slot_minutes`, `field_max_minutes`, `field_who_can_reserve`, `field_cancel_deadline_minutes`, `field_area_category`) y un `leftJoin file_managed` sobre `field_image_fid` para la uri; `orderBy('n.title', DIR)` y luego `orderBy('n.nid', DIR)`; `->range()` salvo cuando `limit=-1`.

6. **`myapi_area_build_item($row)`** — mapea la fila cruda al shape de respuesta: casts a int de `id`/`condominium_id`/`image_id`/`slot_minutes`/`max_minutes`/`cancel_deadline_minutes` (cada uno solo cuando no es `null`), `file_create_url($row->image_uri)` para `image_url` (null cuando no hay fid), passthrough para el resto.

7. **Registrar la ruta** en `hook_menu()` (`myapi.module`): `api/v1/condominiums/%/areas` → `page callback myapi_area_dispatch`, `page arguments [3]`, `access callback TRUE`, `file resources/area.resource.inc`, `type MENU_CALLBACK`, con el mismo shape que `api/v1/units/%/payments`.

8. **Escribir `docs/area.md`** siguiendo la plantilla de doc de CLAUDE.md (método, auth, query params, respuestas de éxito/error, y la nota del supuesto de esquema "lee `field_data_*` directamente" como en `payment.md`).

9. **`drush cc all`** para tomar la ruta nueva.

---

## Criterios de aceptación

- [x] `GET /api/v1/condominiums/{id}/areas` sin token Bearer → `401 missing_authorization`.
- [x] Con un token inválido/expirado → `401 invalid_token`.
- [x] Con un token válido para un condominio con el que el usuario **no** se relaciona (o un id inexistente) → `403 condominium_access_denied`, indistinguible.
- [x] `POST`/`PUT`/`DELETE` sobre la ruta → `405 method_not_allowed`.
- [x] El listado devuelve solo nodos `area` con `status=1`, que casan con `field_condominium`, y con estado `active` o `maintenance`; las `closed` y las áreas sin estado nunca aparecen.
- [x] Cada item expone las 13 claves documentadas con los tipos correctos (ints casteados, `null` cuando no hay fila).
- [x] Un área con imagen devuelve `image_id` no nulo y una `image_url` absoluta; un área sin imagen devuelve ambos `null`.
- [x] `sort=asc` ordena por título ascendente, `sort=desc` (default) descendente; los empates se resuelven por `nid` en la misma dirección, estable entre páginas.
- [x] `limit=-1` devuelve todas las áreas visibles en una sola página (`page=1`, `total_pages=1`, o `0` cuando `total=0`).
- [x] `total=0` → `total_pages=0`; una página más allá de la última → `200` con `areas: []`.
- [x] `page`/`limit`/`sort` con valores basura caen a los defaults sin 422.
- [x] `docs/area.md` existe y casa con el contrato implementado.

---

## Decisiones

- **Sí:** dos specs separados (33 areas, 34 reservations). Son recursos distintos con archivos distintos; mantiene cada spec enfocado, casa con el precedente un-spec-por-endpoint de payments.
- **Sí:** visibilidad por **inclusión** (`field_area_status IN visible`) vía `MYAPI_AREA_VISIBLE_STATUSES`, un inner join. Un área sin fila de estado queda excluida — lo opuesto al criterio por exclusión de payments, pero correcto aquí porque "sin estado" no es un estado seguro para mostrar.
- **Sí:** acceso vía `myapi_condominium_related_nids($uid)` + `in_array` laxo, sin un `node_load` de existencia aparte — exactamente como `myapi_payment_list()`, por eso "sin acceso" y "no existe" colapsan en un único 403.
- **Sí:** `image_url` construida con `file_create_url()` sobre un join a `file_managed` (URL absoluta, una sola query, sin `file_load` por fila).
- **No:** filtro por rango de fechas. Las áreas no tienen dimensión temporal; añadir params de fecha vacíos sería contrato muerto.
- **No:** endpoints de detalle / escritura de áreas. Fuera de alcance; specs propios si llegan.
- **No:** claves i18n nuevas. `condominium_access_denied`, `method_not_allowed`, `missing_authorization`, `invalid_token` ya existen en `myapi.i18n.inc`.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El catálogo de `field_area_status` cambia (nuevo estado visible) | El conjunto visible está centralizado en `MYAPI_AREA_VISIBLE_STATUSES`; se edita una sola constante. |
| `field_image` almacenado en un filesystem privado | `file_create_url()` igual resuelve; el control de acceso para archivos privados está fuera de alcance (documentado en `docs/area.md`). |
| Leer `field_data_*` directamente se rompe si se reconstruye el esquema | El supuesto de esquema queda documentado en `docs/area.md`, mismo caveat que `payment.md`. |

## Lo que **NO** está en este spec

- El endpoint de listado de reservas (SPEC 34).
- Alta / edición / borrado de áreas.
- Endpoint de detalle de área.
- Filtros por categoría o por who-can-reserve, y cualquier filtro de fecha.
