# SPEC 34 — Endpoint de listado de reservas ("Mis Reservas", solo lectura)

> **Estado:** Implemented · **Depende de:** SPEC 14 (patrón de listado de payments), SPEC 32 (tipos de contenido de reservas), SPEC 33 (listado de áreas) · **Fecha:** 2026-07-22
> **Objetivo:** Exponer `GET /api/v1/units/{unit_id}/reservations` como un listado paginado y de solo lectura de las reservas de una vivienda (tanto `confirmed` como `cancelled`), visible solo para el dueño/ocupante autenticado de esa vivienda, replicando el patrón de payments.

---

## Alcance

**Dentro:**

- Nuevo archivo de recurso `resources/reservation.resource.inc` con un dispatcher por método (solo `GET`; cualquier otro → 405).
- `GET /api/v1/units/%/reservations`: listado paginado de nodos `reservation`, autenticado por Bearer.
- Control de acceso: `unit_id` validado contra `myapi_unit_related_nids($uid)`; sin acceso **o** vivienda inexistente → el mismo `403 unit_access_denied` (indistinguibles), exactamente como payments.
- Conjunto devuelto: nodos `reservation` publicados (`status = 1`) con `field_unit = unit_id`. Se devuelven **ambos** estados; el `status` viaja en el JSON.
- Query params idénticos a payments: `page`, `limit` (con `-1`), `sort` (`asc`/`desc`, default `desc`) sobre `field_date`, desempate por `nid`; más `date_from`/`date_to` (sobre `field_date`) y filtro `status`.
- `area_name` resuelto vía join al `node.title` del nodo área (mismo idiom que `bank_name` en payments).
- Ruta registrada en `hook_menu()`, archivo listado en `myapi.info` (`files[]`), doc `docs/reservation.md`.
- `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- Cualquier alta / edición / cancelación de reservas (endpoints de escritura).
- Un endpoint de detalle de reserva (`GET /api/v1/reservations/%`).
- Chequeo de disponibilidad / conflicto de franjas.
- Filtrado por área o por solicitante.

---

## Modelo de datos

**No hay datos persistentes nuevos.** Lee el bundle `reservation` y sus tablas `field_data_*` (SPEC 32), más un join al título del nodo `area`.

**Query params** (parseo laxo, fallback en silencio — nunca un 422):

| Param | Default | Regla |
|---|---|---|
| `page` | `1` | 1-based; valor no-entero-positivo → default en silencio. |
| `limit` | `20` | clamp `[1,50]`; `-1` = sin paginar (fuerza `page=1`). |
| `sort` | `desc` | `asc`/`desc` sobre `field_date_value`; desempate `n.nid` en la misma dirección. |
| `date_from` | — | ISO `YYYY-MM-DD`, opcional; validado con `checkdate()`, si no se ignora. Cota inferior inclusiva sobre `SUBSTR(field_date_value,1,10)`. |
| `date_to` | — | Mismas reglas, cota superior inclusiva. Rango invertido (`from>to`) descarta el filtro entero. |
| `status` | — | `confirmed` / `cancelled`; cualquier otro valor se ignora (devuelve ambos). |

- El filtro de fechas se aplica **antes** de paginar/ordenar. Cuando hay al menos una cota activa, las reservas sin fila de fecha se excluyen (la condición sobre la columna del left join descarta los NULL).
- Los helpers de parseo de fechas reutilizan el idiom de payments: `myapi_reservation_parse_date_range()` + `myapi_reservation_valid_date()` (misma lógica que `myapi_payment_parse_date_range()` / `myapi_payment_valid_date()`).

**Item de respuesta** (`data.reservations[]`):

```json
{
  "id": 88,
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
```

- `id` = `nid` (int); `condominium_id`, `unit_id`, `requester_id`, `area_id` = sus respectivos `*_target_id` casteados a `int` cuando hay fila, si no `null`.
- `area_name` = el `node.title` del nodo área (vía join), `null` cuando falta la fila/nodo del área.
- `date` = `SUBSTR(field_date_value,1,10)` → `Y-m-d`, `null` cuando no hay fila de fecha.
- `start_time`, `end_time`, `status`, `cancelled_by` pasan tal cual se almacenan (`cancelled_by` `null` cuando no hay fila).
- `created` = el `created` nativo del nodo, formateado con `format_date($created, 'custom', 'Y-m-d\TH:i:s')` (ISO 8601, zona horaria del sitio).

**Envelope** (idéntico a payments):

```json
{ "success": true, "data": { "reservations": [ ... ], "pagination": { "total": 0, "page": 1, "limit": 20, "total_pages": 0 } } }
```

- `total_pages` = `0` cuando `total` es `0`; `1` cuando `limit=-1` y `total>0`. Página fuera de rango → `200` con `reservations: []`.

---

## Plan de implementación

1. **Crear `resources/reservation.resource.inc`** con el docblock `@file` y el bloque de `module_load_include()` (request, response, i18n, token, auth, unit_access). Registrarlo en `myapi.info` (`files[] = resources/reservation.resource.inc`). Committeable: el archivo carga, aún sin ruta.

2. **`myapi_reservation_dispatch($unit_id)`** — enruta por método HTTP: `GET` → `myapi_reservation_list($unit_id)`; cualquier otro → `myapi_error('method_not_allowed', 405)`.

3. **`myapi_reservation_list($unit_id)`** — orquestación espejando `myapi_payment_list()`:
   - `myapi_auth_require_access_token()` → `$uid` (401 si falla).
   - `in_array((int) $unit_id, myapi_unit_related_nids($uid))` → si no, `myapi_error('unit_access_denied', 403)`.
   - Parsear `page` / `limit` / `sort` (idiom de payments), `myapi_reservation_parse_date_range()`, y el filtro `status` (`in_array($_GET['status'], ['confirmed','cancelled'], TRUE) ? ... : NULL`).
   - `$total = myapi_reservation_count(...)`, calcular `total_pages`, `$rows = myapi_reservation_fetch(...)`, `array_map('myapi_reservation_build_item', $rows)`, `myapi_respond([...], 200)`.

4. **`myapi_reservation_parse_date_range()` + `myapi_reservation_valid_date()`** — copias de los helpers de payments (`checkdate()`, descarte de rango invertido, ignorar en silencio).

5. **`myapi_reservation_count($unit_id, $from, $to, $status)`** — `db_select('node')` con `type='reservation'`, `status=1`; `innerJoin field_data_field_unit` sobre `unit_id`; `innerJoin field_data_field_date` opcional con cotas `SUBSTR(...,1,10)` cuando hay una cota de fecha activa; `innerJoin field_data_field_reservation_status` opcional con `= $status` cuando el filtro de estado está puesto; `countQuery()`.

6. **`myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from, $to, $status)`** — la misma query base más un `leftJoin` por cada campo mapeado (`field_condominium`, `field_requester`, `field_area`, `field_date`, `field_start_time`, `field_end_time`, `field_reservation_status`, `field_cancelled_by`) y un `leftJoin node` (con alias) sobre `field_area_target_id` para `area_name` (título). Aplica las mismas condiciones de fecha/estado que `_count`; `orderBy('fdate.field_date_value', DIR)` y luego `orderBy('n.nid', DIR)`; también selecciona `n.created`; `->range()` salvo cuando `limit=-1`.

7. **`myapi_reservation_build_item($row)`** — mapea la fila cruda: casts a int de `id` y de cada `*_id` (cuando no es `null`), `date` = `substr($row->date, 0, 10)` (null cuando no hay fila), `created` = `format_date($row->created, 'custom', 'Y-m-d\TH:i:s')`, passthrough para el resto.

8. **Registrar la ruta** en `hook_menu()` (`myapi.module`): `api/v1/units/%/reservations` → `page callback myapi_reservation_dispatch`, `page arguments [3]`, `access callback TRUE`, `file resources/reservation.resource.inc`, `type MENU_CALLBACK`, con el mismo shape que `api/v1/units/%/payments`.

9. **Escribir `docs/reservation.md`** siguiendo la plantilla de doc de CLAUDE.md (método, auth, query params incl. `date_from`/`date_to`/`status`, respuestas de éxito/error, y la nota del supuesto de esquema "lee `field_data_*` directamente" como en `payment.md`).

10. **`drush cc all`** para tomar la ruta nueva.

---

## Criterios de aceptación

- [x] `GET /api/v1/units/{id}/reservations` sin token Bearer → `401 missing_authorization`.
- [x] Con un token inválido/expirado → `401 invalid_token`.
- [x] Con un token válido para una vivienda que el usuario **no** posee/ocupa (o un id inexistente) → `403 unit_access_denied`, indistinguible.
- [x] `POST`/`PUT`/`DELETE` sobre la ruta → `405 method_not_allowed`.
- [x] El listado devuelve solo nodos `reservation` con `status=1` que casan con `field_unit`; aparecen **ambos** `confirmed` y `cancelled`, con `status` en cada item.
- [x] Cada item expone las 12 claves documentadas con los tipos correctos (ints casteados, `null` cuando no hay fila).
- [x] `area_name` es igual al título del nodo área referenciado; `null` cuando el área falta.
- [x] `date` es `Y-m-d`; `created` es ISO `Y-m-d\TH:i:s`.
- [x] `sort=asc`/`desc` ordenan por `field_date`; los empates se resuelven por `nid` en la misma dirección, estable entre páginas.
- [x] `date_from`/`date_to` filtran inclusivamente sobre la fecha (granularidad de día); un valor malformado/no-calendario se ignora en silencio (sin 422); un rango invertido descarta el filtro entero; con una cota activa, las reservas sin fecha se excluyen.
- [x] `status=confirmed` / `status=cancelled` filtran acordemente; cualquier otro valor devuelve ambos, sin 422.
- [x] `limit=-1` devuelve todas las reservas que casan en una sola página; `total=0` → `total_pages=0`; página más allá de la última → `200` con `reservations: []`.
- [x] `docs/reservation.md` existe y casa con el contrato implementado.

---

## Decisiones

- **Sí:** devolver **ambos** estados; `status` en el payload. Esto es "Mis Reservas" — una reserva cancelada sigue siendo historial que el residente quiere ver.
- **Sí:** el filtro `status` acepta solo `confirmed`/`cancelled`; cualquier otro valor se ignora en silencio (devuelve ambos), consistente con el manejo laxo de `page`/`limit`/`sort`/fechas — sin 422.
- **Sí:** reutilizar los helpers de rango de fechas de payments verbatim (`checkdate()`, descarte de rango invertido, ignorar en silencio, filtrar antes de paginar). Un idiom probado, sin comportamiento de validación nuevo.
- **Sí:** `area_name` vía un self-join de `node` sobre `field_area_target_id`, mismo idiom que el join a `taxonomy_term_data` de `bank_name` en payments.
- **Sí:** `created` como string ISO 8601 vía `format_date()` (zona del sitio), para que el cliente reciba un datetime listo para parsear en vez de un epoch crudo.
- **Sí:** acceso vía `myapi_unit_related_nids($uid)` + `in_array` laxo, sin un `node_load` aparte — idéntico a `myapi_payment_list()`, por eso "sin acceso" y "no existe" colapsan en un único 403.
- **No:** endpoints de escritura / detalle / disponibilidad. Fuera de alcance; specs propios si llegan.
- **No:** claves i18n nuevas. `unit_access_denied`, `method_not_allowed`, `missing_authorization`, `invalid_token` ya existen.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El formato de almacenamiento de `field_date` difiere de `Y-m-d\TH:i:s` (almacenamiento ISO del módulo date) | Comparar y devolver vía `SUBSTR(...,1,10)`, mismo enfoque de granularidad-de-día que payments; sin depender de la parte horaria. |
| Desfase de zona horaria de `created` entre servidor y cliente | `format_date()` renderiza en la zona del sitio; el string ISO es inequívoco para que el cliente lo reinterprete. |
| Leer `field_data_*` directamente se rompe si se reconstruye el esquema | El supuesto de esquema queda documentado en `docs/reservation.md`, mismo caveat que `payment.md`. |
| Referencia `field_area` huérfana (nodo área borrado) | El `leftJoin` mantiene la reserva; `area_name` queda `null` en vez de descartar la fila. |

## Lo que **NO** está en este spec

- Alta / edición / cancelación de reservas.
- Endpoint de detalle de reserva.
- Chequeo de disponibilidad / conflicto de franjas.
- Filtros por área o por solicitante.
