# 14 — Listado de pagos de una unidad

- **Estado:** Implemented
- **Fecha:** 2026-07-06
- **Dependencias:**
  - `08-units-list` (Implemented) — `myapi_unit_related_nids()`, ya extraído a `includes/myapi.unit_access.inc`.
  - `11-unit-receipts-list` (Implemented) — este endpoint es un calco de `receipts`/`extra-fees`: mismo control de acceso propietario/ocupante, misma paginación (`page`/`limit`/`sort`) y misma estructura de recurso.
  - `12-receipts-date-range-filter` (Implemented) — se replica el filtro opcional `date_from`/`date_to`, aquí sobre `field_fecha_de_pago`.
  - `13-unit-extra-fees-list` (Implemented) — modelo de referencia más cercano (mismo tamaño y forma); se difiere en el criterio de estado.
- **Objetivo:** Agregar `GET /api/v1/units/<unit_id>/payments`, un endpoint autenticado y paginado que lista los nodos `pagos` (expuestos como `payments`) en cualquier estado distinto de `Nuevo` de una unidad, visible solo para el propietario u ocupante de esa unidad, con filtro opcional por rango de fechas sobre `field_fecha_de_pago`.

---

## Alcance

### Dentro de este spec

- **`resources/payment.resource.inc`** (nuevo) — `myapi_payment_dispatch()` (solo GET) y las funciones `myapi_payment_list()`, `myapi_payment_count()`, `myapi_payment_fetch()`, `myapi_payment_build_item()`, más los helpers de rango de fechas (`myapi_payment_parse_date_range()` / `myapi_payment_valid_date()`). Misma estructura que `extra_fee.resource.inc`.
- **`myapi.module`** (modificar) — registrar `GET /api/v1/units/%/payments` en `hook_menu()`.
- **`myapi.info`** (modificar) — agregar `resources/payment.resource.inc` a `files[]`.
- **`docs/payment.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Escritura de pagos** (crear/editar/eliminar nodos `pagos`) — solo lectura.
- **Endpoint de detalle individual** (`GET /api/v1/units/%/payments/%`) — solo el listado.
- **Interpretación de negocio de los valores** (semántica de `field_estado_pago`, catálogo de `field_forma_de_pago`, validación de montos, formato de `field_fecha_de_pago`) — se expone tal cual está almacenado, sin transformación, mismo criterio que `receipts`, `extra-fees` y `current_balance`.
- **Exponer pagos en estado `Nuevo`** — cualquier pago cuyo `field_estado_pago` sea `Nuevo`, o que no tenga fila de estado, queda oculto (inner join con `<> 'Nuevo'`).
- **Filtrar sobre otro campo que no sea `field_fecha_de_pago`** (por `payment_method`, `status`, `amount`, etc.) — el único filtro opcional es el rango de fechas.
- **Nuevo helper de acceso** — se reutiliza `myapi_unit_related_nids()` de `includes/myapi.unit_access.inc` tal cual; no se modifica.
- **Cambios en `receipts`, `extra-fees` o `units`** — este spec solo agrega un recurso nuevo; no toca los existentes.

---

## Modelo de datos

Content type `pagos`, verificado en `schema.sql`. `field_valor` es `decimal(10,2)` single-value; `field_fecha_de_pago` es `varchar(20)` single-value (solo `_value`, no es rango); `field_forma_de_pago`, `field_referencia` y `field_estado_pago` son `varchar(255)` single-value; `field_vivienda` es la Entity Reference usada para filtrar por unidad.

| Tabla Drupal | Columna(s) | Uso |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | Nodos `pagos` publicados. |
| `field_data_field_vivienda` | `entity_id`, `field_vivienda_target_id` | Pago → unidad (`unit_id`). Filtro principal (inner join). |
| `field_data_field_estado_pago` | `entity_id`, `field_estado_pago_value` | `status`. Inner join con condición `<> 'Nuevo'`: se exponen todos los estados menos `Nuevo` (y los sin fila de estado quedan fuera). |
| `field_data_field_fecha_de_pago` | `entity_id`, `field_fecha_de_pago_value` | `payment_date`. Columna de orden y de filtro de rango de fechas. |
| `field_data_field_forma_de_pago` | `entity_id`, `field_forma_de_pago_value` | `payment_method`, texto. |
| `field_data_field_referencia` | `entity_id`, `field_referencia_value` | `reference`, texto. |
| `field_data_field_valor` | `entity_id`, `field_valor_value` | `amount`, decimal. |

### Mapeo de campos → claves JSON

| Campo Drupal | Clave JSON | Tipo | Regla `NULL` |
|---|---|---|---|
| `nid` | `id` | int | nunca `NULL` |
| `title` | `title` | string | nunca `NULL` |
| `field_vivienda_target_id` | `unit_id` | int | nunca `NULL` (es el filtro de la query) |
| `field_fecha_de_pago_value` | `payment_date` | string | `NULL` si no hay fila |
| `field_estado_pago_value` | `status` | string | nunca `NULL` (inner join `<> 'Nuevo'`) |
| `field_forma_de_pago_value` | `payment_method` | string | `NULL` si no hay fila |
| `field_referencia_value` | `reference` | string | `NULL` si no hay fila |
| `field_valor_value` | `amount` | float | `NULL` si no hay fila |

### Contrato de paginación / orden

- Query params: `page` (default `1`), `limit` (default `20`, clamp a `[1, 50]`), `sort` (`asc`\|`desc`, default `desc`).
- Valores inválidos o ausentes caen a su default silenciosamente (sin `422`), igual que `receipts`/`extra-fees`.
- Orden siempre por `field_fecha_de_pago_value` (`payment_date`).
- `total` = cantidad total de pagos de esa unidad en estado distinto de `Nuevo` (con el rango de fechas ya aplicado si viene), sin paginar. `total_pages` = `ceil(total / limit)`, o `0` si `total` es `0`.

### Filtro por rango de fechas (opcional)

| Param | Formato | Default | Regla |
|---|---|---|---|
| `date_from` | `YYYY-MM-DD` | ausente = sin límite inferior | Si es válido, filtra `payment_date >= date_from`. |
| `date_to` | `YYYY-MM-DD` | ausente = sin límite superior | Si es válido, filtra `payment_date <= date_to`. |

- Un límite se considera **válido** solo si matchea `YYYY-MM-DD` y es fecha real (`checkdate()`); cualquier otra cosa se ignora, como si no viniera.
- Cada límite es independiente; rango invertido (`from > to`) descarta el filtro completo. Nunca hay `422`.
- Comparación sobre los primeros 10 caracteres: `SUBSTR(field_fecha_de_pago_value, 1, 10) >= :date_from` / `<= :date_to`, para incluir el borde sin importar un posible sufijo de hora. Un nodo sin fila en `field_fecha_de_pago` (`payment_date = NULL`) queda excluido cuando hay filtro activo.

### Forma de respuesta

```json
{
  "payments": [
    {
      "id": 902,
      "title": "Pago julio 2026",
      "unit_id": 45,
      "payment_date": "2026-07-05",
      "status": "Aprobado",
      "payment_method": "Transferencia",
      "reference": "TRX-88213",
      "amount": 187.32
    }
  ],
  "pagination": {
    "total": 4,
    "page": 1,
    "limit": 20,
    "total_pages": 1
  }
}
```

---

## Plan de implementación

1. **Crear `resources/payment.resource.inc`** con los mismos `module_load_include()` que `extra_fee.resource.inc` (request, response, i18n, token, auth, unit_access) y `define('MYAPI_PAYMENT_EXCLUDED_STATUS', 'Nuevo')`.
   - `myapi_payment_dispatch($unit_id)` — enruta por método; solo `GET`, cualquier otro → `myapi_error('method_not_allowed', 405)`.
   - `myapi_payment_list($unit_id)`:
     - `myapi_auth_require_access_token()` → `$uid`.
     - `myapi_unit_related_nids($uid)` + `in_array((int) $unit_id, $nids)`; si no → `myapi_error('unit_access_denied', 403)`.
     - Parseo de `page`/`limit`/`sort` con los defaults/clamps del modelo de datos.
     - `myapi_payment_parse_date_range()` → `$from`/`$to`.
     - `myapi_payment_count($unit_id, $from, $to)` para `total`; `total_pages = ceil(total/limit)` o `0`.
     - `myapi_payment_fetch($unit_id, $page, $limit, $sort, $from, $to)` + `array_map('myapi_payment_build_item', $rows)`.
     - `myapi_respond(['payments' => $items, 'pagination' => [...]], 200)`.
   - `myapi_payment_parse_date_range()` / `myapi_payment_valid_date()` — idénticas a las de `extra_fee.resource.inc`.

2. **`myapi_payment_count($unit_id, $from, $to)`** — `db_select('node')` con `type = 'pagos'` y `status = 1`; inner join a `field_data_field_vivienda` (`= $unit_id`); inner join a `field_data_field_estado_pago` con condición `field_estado_pago_value <> 'Nuevo'`; si hay algún límite, inner join a `field_data_field_fecha_de_pago` con las condiciones `SUBSTR(field_fecha_de_pago_value,1,10) >= / <= :bound`. Devuelve `countQuery()`.

3. **`myapi_payment_fetch($unit_id, $page, $limit, $sort, $from, $to)`** — misma base; `leftJoin` a `field_fecha_de_pago` (`payment_date`) con las condiciones de rango cuando hay filtro; inner join a `field_estado_pago` (`<> 'Nuevo'`, expone `status`); left joins a `field_forma_de_pago`, `field_referencia`, `field_valor`. `orderBy('fecha.field_fecha_de_pago_value', $sort)`, `range()` según `page`/`limit`.

4. **`myapi_payment_build_item($row)`** — arma el ítem: `id`/`unit_id` a `int`, `title`/`payment_date`/`status`/`payment_method`/`reference` tal cual, y `amount` a `float` cuando no es `NULL`.

5. **Registrar la ruta en `myapi.module`:**
   ```php
   $items['api/v1/units/%/payments'] = [
     'page callback'   => 'myapi_payment_dispatch',
     'page arguments'  => [3],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/payment.resource.inc',
   ];
   ```

6. **Agregar a `myapi.info`:** `files[] = resources/payment.resource.inc`.

7. **Crear `docs/payment.md`** siguiendo la plantilla: descripción, auth, query params (paginación + `date_from`/`date_to`), tabla de campos de respuesta, tabla de errores, nota de que se exponen todos los estados menos `Nuevo` (y que un pago sin estado queda oculto) y que `unit_access_denied` no distingue "no existe" de "no es tuya".

8. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación.

---

## Criterios de aceptación

- [x] `GET /api/v1/units/<unit_id>/payments` con token válido y `unit_id` de una unidad donde el usuario es propietario u ocupante devuelve `200` con `payments` (array mapeado según el modelo de datos) y `pagination` (`total`, `page`, `limit`, `total_pages`).
- [x] Cada ítem incluye exactamente las 8 claves: `id`, `title`, `unit_id`, `payment_date`, `status`, `payment_method`, `reference`, `amount`, con `NULL` en `payment_date`/`payment_method`/`reference`/`amount` cuando el nodo no tiene fila en ese campo.
- [x] Solo se listan nodos `pagos` publicados (`status = 1`) con `field_vivienda_target_id = unit_id` **y** `field_estado_pago <> 'Nuevo'`; un pago en estado `Nuevo` o sin fila de estado queda excluido.
- [x] `unit_id` de una unidad ajena (ni propietario ni ocupante) devuelve `403 unit_access_denied`.
- [x] `unit_id` inexistente devuelve el mismo `403 unit_access_denied` (no se distingue el motivo).
- [x] Sin header `Authorization` → `401 missing_authorization`; token inválido/expirado/revocado → `401 invalid_token`.
- [x] Cualquier método distinto de `GET` → `405 method_not_allowed`.
- [x] `?page` y `?limit` paginan correctamente; `limit` se clampa a `[1, 50]`; valores inválidos/ausentes caen a los defaults (`page=1`, `limit=20`) sin error.
- [x] `?sort=asc`/`?sort=desc` invierte el orden por `payment_date` (`field_fecha_de_pago_value`); default `desc`; valor inválido cae a `desc`.
- [x] `date_from`/`date_to` filtran sobre `payment_date` (primeros 10 caracteres) de forma inclusiva; cada límite es independiente; el borde superior incluye el día indicado aunque haya sufijo de hora.
- [x] `pagination.total` y `total_pages` reflejan el conjunto **ya filtrado** (estado `<> 'Nuevo'` + rango de fechas), no el total bruto de la unidad.
- [x] `date_from`/`date_to` con formato inválido, o rango invertido (`from > to`), se ignoran sin `422`; nodos sin fila en `field_fecha_de_pago` quedan excluidos cuando hay al menos un límite activo.
- [x] Una unidad sin pagos en estado distinto de `Nuevo` (o una página fuera de rango) devuelve `200` con `payments: []` y `pagination.total: 0`, `total_pages: 0` (no es error).
- [x] `docs/payment.md` documenta el endpoint completo (auth, query params, campos de respuesta, errores).
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nombre del recurso | `payment` / ruta `payments` / array `payments` | `pago`, `pagos` (literal) | CLAUDE.md prohíbe identificadores en español y exige rutas en inglés plural; consistente con `receipts`/`extra-fees`. |
| Archivo de la lógica | Nuevo `resources/payment.resource.inc` | Agregar funciones a `receipt.resource.inc`/`extra_fee.resource.inc` | `pagos` es un content type propio; regla "un recurso = un archivo" y "los recursos no se llaman entre sí". |
| Criterio de estado | Exponer todos los estados **menos** `Nuevo` (inner join `<> 'Nuevo'`) | Exponer solo un estado (como `receipts`/`extra-fees`), o exponer todos | Pedido explícito del usuario: la app ve todos los pagos que ya no son "Nuevo". |
| Pago sin fila de estado | Excluido (inner join) | Incluirlo con `status: null` (left join) | Elegido por el usuario; cambio mínimo sobre el patrón de `receipts`/`extra-fees` (solo se invierte el operador del inner join). |
| Constante de estado | `MYAPI_PAYMENT_EXCLUDED_STATUS = 'Nuevo'` (estado a excluir) | `..._EXPOSED_STATUS` (estado a incluir), como en `receipts`/`extra-fees` | La semántica aquí es de exclusión, no de inclusión; el nombre refleja el criterio real y centraliza el valor en un solo lugar. |
| Clave JSON de `field_valor` | `amount` | `value`, `paid_amount` | Elegido por el usuario; nombre estándar para el monto de un pago. |
| Clave JSON de `field_forma_de_pago` | `payment_method` | `payment_type`, `method` | Elegido por el usuario. |
| Filtro de fechas | `date_from`/`date_to` sobre `field_fecha_de_pago`, comportamiento laxo | Sin filtro, o filtro con `422` | Pedido explícito de replicar `receipts`/`extra-fees` (spec 12/13); `field_fecha_de_pago` es fecha única, así que el filtro aplica directo sobre esa columna. |
| Forma de comparación de fechas | `SUBSTR(field_fecha_de_pago_value, 1, 10)` | Comparar la columna cruda | `field_fecha_de_pago` es `varchar(20)` y puede o no traer sufijo `T00:00:00`; comparar los 10 primeros chars incluye correctamente el borde superior. |
| Semántica de acceso denegado | `403 unit_access_denied` uniforme, exista o no la unidad | Distinguir `404` de `403` | No revela si un `unit_id` ajeno existe; mismo criterio que `receipts`/`extra-fees`. |
| Reutilización del acceso | `myapi_unit_related_nids()` de `includes/myapi.unit_access.inc` tal cual | Duplicar la consulta o filtrar por bundle propio | El helper ya existe y resuelve propietario/ocupante; no hace falta lógica nueva. |
| Campo `unit_id` en cada ítem | Incluido, aunque es redundante con la ruta | Omitido | Consistencia con `receipts`/`extra-fees`; evita que el cliente deba recordar el parámetro de ruta al procesar cada ítem. |
| Página fuera de rango / unidad sin datos | `200` con `payments: []` | `422` o `404` | Consistente con `receipts`/`extra-fees`/`units`: lista vacía, no error. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Lectura directa de tablas de Field API.** Un cambio de schema en `field_valor`, `field_forma_de_pago`, `field_referencia`, `field_fecha_de_pago` o `field_estado_pago` (rename, cambio de tipo, paso a multi-value) rompe silenciosamente la consulta sin aviso de Drupal. | Documentado en la tabla de mapeo del spec y en `docs/payment.md`; mismo criterio ya aceptado en `receipts`/`extra-fees`. |
| **Campos compartidos entre content types.** `field_vivienda`, `field_referencia` y `field_valor` los usan también otros bundles. Sin filtro por tipo de nodo, la query traería filas ajenas. | Todas las consultas filtran por `n.type = 'pagos'`; el `entity_id` de cada join amarra la fila al nodo correcto. Criterio de aceptación explícito de que solo se listan nodos `pagos`. |
| **Criterio por exclusión (`<> 'Nuevo'`).** A diferencia de `receipts`/`extra-fees` (que incluyen un estado conocido), aquí se expone cualquier estado que no sea `Nuevo`. Si el negocio agrega un estado nuevo (p.ej. un borrador interno), se expondrá automáticamente sin que el endpoint lo sepa. | Aceptado y explícito en el spec: el criterio es "todo menos `Nuevo`". El valor está centralizado en `MYAPI_PAYMENT_EXCLUDED_STATUS` para ajustarlo en un solo lugar si aparece otro estado a ocultar. |
| **Formato real de `field_fecha_de_pago_value` desconocido.** `schema.sql` solo trae estructura (`varchar(20)`), no datos; no se confirmó si guarda `2026-07-05` o `2026-07-05T00:00:00`. | La comparación por `SUBSTR(...,1,10)` y el orden por la columna cruda funcionan en ambos casos. Verificar en el paso 8 que el borde `date_to = último día` incluye ese nodo. |
| **Precisión de `decimal(10,2)` al exponerse como `float`.** La conversión a `float` de PHP puede introducir imprecisión de punto flotante en casos extremos. | Sin acción adicional; parte del contrato ya aceptado en `receipts`/`extra-fees`/`units`. |
| **`unit_id` como wildcard `%` sin `load function`.** Drupal no valida que el segmento sea numérico antes de invocar el dispatcher. | Un valor no numérico se castea a `(int) 0`, nunca coincide con un nid real y cae en `403 unit_access_denied`; ya cubierto. |
