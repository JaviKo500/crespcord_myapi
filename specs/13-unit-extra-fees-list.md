# 13 — Listado de alícuotas extras de una unidad

- **Estado:** Implemented
- **Fecha:** 2026-07-06
- **Dependencias:**
  - `08-units-list` (Implemented) — `myapi_unit_related_nids()`, ya extraído a `includes/myapi.unit_access.inc`.
  - `11-unit-receipts-list` (Implemented) — este endpoint es un calco de `receipts`: mismo control de acceso propietario/ocupante, misma paginación (`page`/`limit`/`sort`) y mismo criterio de exponer solo un estado.
  - `12-receipts-date-range-filter` (Implemented) — se replica el filtro opcional `date_from`/`date_to`, aquí sobre `field_fecha`.
- **Objetivo:** Agregar `GET /api/v1/units/<unit_id>/extra-fees`, un endpoint autenticado y paginado que lista los nodos `alicuota_extra` (expuestos como `extra_fees`) en estado `Enviado` de una unidad, visible solo para el propietario u ocupante de esa unidad, con filtro opcional por rango de fechas sobre `field_fecha`.

---

## Alcance

### Dentro de este spec

- **`resources/extra_fee.resource.inc`** (nuevo) — `myapi_extra_fee_dispatch()` (solo GET) y las funciones `myapi_extra_fee_list()`, `myapi_extra_fee_count()`, `myapi_extra_fee_fetch()`, `myapi_extra_fee_build_item()`, más los helpers de rango de fechas (`myapi_extra_fee_parse_date_range()` / `myapi_extra_fee_valid_date()`). Misma estructura que `receipt.resource.inc`.
- **`myapi.module`** (modificar) — registrar `GET /api/v1/units/%/extra-fees` en `hook_menu()`.
- **`myapi.info`** (modificar) — agregar `resources/extra_fee.resource.inc` a `files[]`.
- **`docs/extra_fee.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Escritura de alícuotas extras** (crear/editar/eliminar nodos `alicuota_extra`) — solo lectura.
- **Endpoint de detalle individual** (`GET /api/v1/units/%/extra-fees/%`) — solo el listado.
- **Interpretación de negocio de los valores** (semántica de `field_estado`, validación de montos, formato de `field_fecha`) — se expone tal cual está almacenado, sin transformación, mismo criterio que `receipts` y `current_balance`.
- **Exponer estados distintos de `Enviado`** — cualquier otro estado (o un nodo sin fila en `field_estado`) queda oculto, igual que en `receipts`.
- **Filtrar sobre otro campo que no sea `field_fecha`** (por `status`, `total`, etc.) — el único filtro opcional es el rango de fechas.
- **Nuevo helper de acceso** — se reutiliza `myapi_unit_related_nids()` de `includes/myapi.unit_access.inc` tal cual; no se modifica.
- **Cambios en `receipts` o `units`** — este spec solo agrega un recurso nuevo; no toca los existentes.

---

## Modelo de datos

Content type `alicuota_extra`, verificado en `schema.sql`. `field_valor_extra`, `field_saldo_anterior` y `field_total` son `decimal` single-value; `field_fecha` es `varchar(20)` single-value (solo `_value`, no es rango); `field_estado` es texto; `field_vivienda` es la Entity Reference usada para filtrar por unidad.

| Tabla Drupal | Columna(s) | Uso |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | Nodos `alicuota_extra` publicados. |
| `field_data_field_vivienda` | `entity_id`, `field_vivienda_target_id` | Alícuota → unidad (`unit_id`). Filtro principal (inner join). |
| `field_data_field_estado` | `entity_id`, `field_estado_value` | `status`. Inner join con condición `= 'Enviado'`: solo ese estado se expone. |
| `field_data_field_fecha` | `entity_id`, `field_fecha_value` | `date`. Columna de orden y de filtro de rango de fechas. |
| `field_data_field_valor_extra` | `entity_id`, `field_valor_extra_value` | `extra_fee`, decimal. |
| `field_data_field_saldo_anterior` | `entity_id`, `field_saldo_anterior_value` | `previous_balance`, decimal. |
| `field_data_field_total` | `entity_id`, `field_total_value` | `total`, decimal. |

### Mapeo de campos → claves JSON

| Campo Drupal | Clave JSON | Tipo | Regla `NULL` |
|---|---|---|---|
| `nid` | `id` | int | nunca `NULL` |
| `title` | `title` | string | nunca `NULL` |
| `field_vivienda_target_id` | `unit_id` | int | nunca `NULL` (es el filtro de la query) |
| `field_fecha_value` | `date` | string | `NULL` si no hay fila |
| `field_estado_value` | `status` | string | nunca `NULL` (inner join `= 'Enviado'`) |
| `field_valor_extra_value` | `extra_fee` | float | `NULL` si no hay fila |
| `field_saldo_anterior_value` | `previous_balance` | float | `NULL` si no hay fila |
| `field_total_value` | `total` | float | `NULL` si no hay fila |

### Contrato de paginación / orden

- Query params: `page` (default `1`), `limit` (default `20`, clamp a `[1, 50]`), `sort` (`asc`\|`desc`, default `desc`).
- Valores inválidos o ausentes caen a su default silenciosamente (sin `422`), igual que `receipts`.
- Orden siempre por `field_fecha_value` (`date`).
- `total` = cantidad total de alícuotas extras `Enviado` de esa unidad (con el rango de fechas ya aplicado si viene), sin paginar. `total_pages` = `ceil(total / limit)`, o `0` si `total` es `0`.

### Filtro por rango de fechas (opcional)

| Param | Formato | Default | Regla |
|---|---|---|---|
| `date_from` | `YYYY-MM-DD` | ausente = sin límite inferior | Si es válido, filtra `date >= date_from`. |
| `date_to` | `YYYY-MM-DD` | ausente = sin límite superior | Si es válido, filtra `date <= date_to`. |

- Un límite se considera **válido** solo si matchea `YYYY-MM-DD` y es fecha real (`checkdate()`); cualquier otra cosa se ignora, como si no viniera.
- Cada límite es independiente; rango invertido (`from > to`) descarta el filtro completo. Nunca hay `422`.
- Comparación sobre los primeros 10 caracteres: `SUBSTR(field_fecha_value, 1, 10) >= :date_from` / `<= :date_to`, para incluir el borde sin importar un posible sufijo de hora. Un nodo sin fila en `field_fecha` (`date = NULL`) queda excluido cuando hay filtro activo.

### Forma de respuesta

```json
{
  "extra_fees": [
    {
      "id": 812,
      "title": "Alícuota extra julio 2026",
      "unit_id": 45,
      "date": "2026-07-01",
      "status": "Enviado",
      "extra_fee": 25.00,
      "previous_balance": 10.50,
      "total": 35.50
    }
  ],
  "pagination": {
    "total": 3,
    "page": 1,
    "limit": 20,
    "total_pages": 1
  }
}
```

---

## Plan de implementación

1. **Crear `resources/extra_fee.resource.inc`** con los mismos `module_load_include()` que `receipt.resource.inc` (request, response, i18n, token, auth, unit_access) y `define('MYAPI_EXTRA_FEE_EXPOSED_STATUS', 'Enviado')`.
   - `myapi_extra_fee_dispatch($unit_id)` — enruta por método; solo `GET`, cualquier otro → `myapi_error('method_not_allowed', 405)`.
   - `myapi_extra_fee_list($unit_id)`:
     - `myapi_auth_require_access_token()` → `$uid`.
     - `myapi_unit_related_nids($uid)` + `in_array((int) $unit_id, $nids)`; si no → `myapi_error('unit_access_denied', 403)`.
     - Parseo de `page`/`limit`/`sort` con los defaults/clamps del modelo de datos.
     - `myapi_extra_fee_parse_date_range()` → `$from`/`$to`.
     - `myapi_extra_fee_count($unit_id, $from, $to)` para `total`; `total_pages = ceil(total/limit)` o `0`.
     - `myapi_extra_fee_fetch($unit_id, $page, $limit, $sort, $from, $to)` + `array_map('myapi_extra_fee_build_item', $rows)`.
     - `myapi_respond(['extra_fees' => $items, 'pagination' => [...]], 200)`.
   - `myapi_extra_fee_parse_date_range()` / `myapi_extra_fee_valid_date()` — idénticas a las de `receipt.resource.inc`.

2. **`myapi_extra_fee_count($unit_id, $from, $to)`** — `db_select('node')` con `type = 'alicuota_extra'` y `status = 1`; inner join a `field_data_field_vivienda` (`= $unit_id`); inner join a `field_data_field_estado` (`= 'Enviado'`); si hay algún límite, inner join a `field_data_field_fecha` con las condiciones `SUBSTR(field_fecha_value,1,10) >= / <= :bound`. Devuelve `countQuery()`.

3. **`myapi_extra_fee_fetch($unit_id, $page, $limit, $sort, $from, $to)`** — misma base; `leftJoin` a `field_fecha` (`date`) con las condiciones de rango cuando hay filtro; inner join a `field_estado` (`= 'Enviado'`, expone `status`); left joins a `field_valor_extra`, `field_saldo_anterior`, `field_total`. `orderBy('fecha.field_fecha_value', $sort)`, `range()` según `page`/`limit`.

4. **`myapi_extra_fee_build_item($row)`** — arma el ítem: `id`/`unit_id` a `int`, `title`/`date`/`status` tal cual, y `extra_fee`/`previous_balance`/`total` a `float` cuando no son `NULL`.

5. **Registrar la ruta en `myapi.module`:**
   ```php
   $items['api/v1/units/%/extra-fees'] = [
     'page callback'   => 'myapi_extra_fee_dispatch',
     'page arguments'  => [3],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/extra_fee.resource.inc',
   ];
   ```

6. **Agregar a `myapi.info`:** `files[] = resources/extra_fee.resource.inc`.

7. **Crear `docs/extra_fee.md`** siguiendo la plantilla: descripción, auth, query params (paginación + `date_from`/`date_to`), tabla de campos de respuesta, tabla de errores, nota de que solo se exponen los `Enviado` y que `unit_access_denied` no distingue "no existe" de "no es tuya".

8. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación.

---

## Criterios de aceptación

- [x] `GET /api/v1/units/<unit_id>/extra-fees` con token válido y `unit_id` de una unidad donde el usuario es propietario u ocupante devuelve `200` con `extra_fees` (array mapeado según el modelo de datos) y `pagination` (`total`, `page`, `limit`, `total_pages`).
- [x] Cada ítem incluye exactamente las 8 claves: `id`, `title`, `unit_id`, `date`, `status`, `extra_fee`, `previous_balance`, `total`, con `NULL` en los decimales/`date` cuando el nodo no tiene fila en ese campo.
- [x] Solo se listan nodos `alicuota_extra` publicados (`status = 1`) con `field_vivienda_target_id = unit_id` **y** `field_estado = 'Enviado'`; cualquier otro estado (o sin fila de estado) queda excluido.
- [x] `unit_id` de una unidad ajena (ni propietario ni ocupante) devuelve `403 unit_access_denied`.
- [x] `unit_id` inexistente devuelve el mismo `403 unit_access_denied` (no se distingue el motivo).
- [x] Sin header `Authorization` → `401 missing_authorization`; token inválido/expirado/revocado → `401 invalid_token`.
- [x] Cualquier método distinto de `GET` → `405 method_not_allowed`.
- [x] `?page` y `?limit` paginan correctamente; `limit` se clampa a `[1, 50]`; valores inválidos/ausentes caen a los defaults (`page=1`, `limit=20`) sin error.
- [x] `?sort=asc`/`?sort=desc` invierte el orden por `date` (`field_fecha_value`); default `desc`; valor inválido cae a `desc`.
- [x] `date_from`/`date_to` filtran sobre `date` (primeros 10 caracteres) de forma inclusiva; cada límite es independiente; el borde superior incluye el día indicado aunque haya sufijo de hora.
- [x] `pagination.total` y `total_pages` reflejan el conjunto **ya filtrado** (estado `Enviado` + rango de fechas), no el total bruto de la unidad.
- [x] `date_from`/`date_to` con formato inválido, o rango invertido (`from > to`), se ignoran sin `422`; nodos sin fila en `field_fecha` quedan excluidos cuando hay al menos un límite activo.
- [x] Una unidad sin alícuotas extras `Enviado` (o una página fuera de rango) devuelve `200` con `extra_fees: []` y `pagination.total: 0`, `total_pages: 0` (no es error).
- [x] `docs/extra_fee.md` documenta el endpoint completo (auth, query params, campos de respuesta, errores).
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nombre del recurso | `extra_fee` / ruta `extra-fees` / array `extra_fees` | `surcharge`, `alicuota_extra` (literal) | CLAUDE.md prohíbe identificadores en español y exige rutas en inglés plural; consistente con la traducción `field_alicuota_extra → extra_fee` ya usada en `receipts`. |
| Archivo de la lógica | Nuevo `resources/extra_fee.resource.inc` | Agregar funciones a `receipt.resource.inc` | `alicuota_extra` es un content type propio, distinto de `recibo`; regla "un recurso = un archivo" y "los recursos no se llaman entre sí". |
| Clave JSON de `field_valor_extra` | `extra_fee` | `extra_value`, `amount` | Elegido por el usuario; alinea con el nombre del recurso. |
| Filtro de estado | Exponer solo `field_estado = 'Enviado'` (inner join) | Exponer todos los estados | Mismo criterio que `receipts`; solo las alícuotas enviadas son visibles para la app. |
| Filtro de fechas | `date_from`/`date_to` sobre `field_fecha`, comportamiento laxo | Sin filtro, o filtro con `422` | Pedido explícito de replicar `receipts` (spec 12); `field_fecha` es fecha única, así que el filtro aplica directo sobre esa columna. |
| Forma de comparación de fechas | `SUBSTR(field_fecha_value, 1, 10)` | Comparar la columna cruda | `field_fecha` es `varchar(20)` y puede o no traer sufijo `T00:00:00`; comparar los 10 primeros chars incluye correctamente el borde superior. |
| Semántica de acceso denegado | `403 unit_access_denied` uniforme, exista o no la unidad | Distinguir `404` de `403` | No revela si un `unit_id` ajeno existe; mismo criterio que `receipts`. |
| Reutilización del acceso | `myapi_unit_related_nids()` de `includes/myapi.unit_access.inc` tal cual | Duplicar la consulta o filtrar por bundle propio | El helper ya existe y resuelve propietario/ocupante; no hace falta lógica nueva. |
| Campo `unit_id` en cada ítem | Incluido, aunque es redundante con la ruta | Omitido | Consistencia con `receipts`; evita que el cliente deba recordar el parámetro de ruta al procesar cada ítem. |
| Página fuera de rango / unidad sin datos | `200` con `extra_fees: []` | `422` o `404` | Consistente con `receipts` y `units`: lista vacía, no error. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Lectura directa de tablas de Field API.** Un cambio de schema en `field_valor_extra`, `field_saldo_anterior`, `field_total`, `field_fecha` o `field_estado` (rename, cambio de tipo, paso a multi-value) rompe silenciosamente la consulta sin aviso de Drupal. | Documentado en la tabla de mapeo del spec y en `docs/extra_fee.md`; mismo criterio ya aceptado en `receipts`. |
| **Campos compartidos entre content types.** `field_vivienda`, `field_estado`, `field_saldo_anterior` y `field_total` los usan también `recibo` u otros bundles. Sin filtro por tipo de nodo, la query traería filas ajenas. | Todas las consultas filtran por `n.type = 'alicuota_extra'`; el `entity_id` de cada join amarra la fila al nodo correcto. Criterio de aceptación explícito de que solo se listan nodos `alicuota_extra`. |
| **Formato real de `field_fecha_value` desconocido.** `schema.sql` solo trae estructura (`varchar(20)`), no datos; no se confirmó si guarda `2026-07-01` o `2026-07-01T00:00:00`. | La comparación por `SUBSTR(...,1,10)` y el orden por la columna cruda funcionan en ambos casos. Verificar en el paso 8 que el borde `date_to = último día` incluye ese nodo. |
| **`status` sin lista de valores validada.** Si el negocio renombra el estado `Enviado` en Drupal, el endpoint dejaría de exponer nodos silenciosamente (inner join sin match). | Aceptado, mismo criterio que `receipts`; el valor está centralizado en la constante `MYAPI_EXTRA_FEE_EXPOSED_STATUS` para cambiarlo en un solo lugar. |
| **Precisión de `decimal(10,2)` al exponerse como `float`.** La conversión a `float` de PHP puede introducir imprecisión de punto flotante en casos extremos. | Sin acción adicional; parte del contrato ya aceptado en `receipts`/`units`. |
| **`unit_id` como wildcard `%` sin `load function`.** Drupal no valida que el segmento sea numérico antes de invocar el dispatcher. | Un valor no numérico se castea a `(int) 0`, nunca coincide con un nid real y cae en `403 unit_access_denied`; ya cubierto. |
