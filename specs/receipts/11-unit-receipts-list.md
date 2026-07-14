# 11 — Listado de alícuotas (recibos) de una unidad

- **Estado:** Implemented
- **Fecha:** 2026-07-04
- **Dependencias:**
  - `08-units-list` (Implemented) — `GET /api/v1/units`, en particular `myapi_unit_related_nids()` en `resources/unit.resource.inc`, que este spec extrae a un helper compartido.
  - `09-units-owner-occupant` (Approved) — misma lógica de "propietario u ocupante" que se reutiliza aquí para el control de acceso.
- **Objetivo:** Agregar `GET /api/v1/units/<unit_id>/receipts`, un endpoint autenticado y paginado que lista los nodos `recibo` (expuestos como `receipts`) de una unidad, visible solo para el usuario que es propietario u ocupante de esa unidad.

---

## Alcance

### Dentro de este spec

- **`resources/receipt.resource.inc`** (nuevo) — `myapi_receipt_dispatch()` (solo GET) y `myapi_receipt_list()`: valida acceso a la unidad, aplica paginación/orden, consulta los nodos `recibo` y arma la respuesta.
- **`includes/myapi.unit_access.inc`** (nuevo) — se extrae `myapi_unit_related_nids($uid)` desde `resources/unit.resource.inc` a este helper compartido, para que tanto `unit.resource.inc` como `receipt.resource.inc` lo usen sin llamarse entre sí (regla de aislamiento de recursos).
- **`resources/unit.resource.inc`** (modificar) — se quita la definición local de `myapi_unit_related_nids()` y se reemplaza por `module_load_include()` + llamada al helper movido. Sin cambio de comportamiento.
- **`myapi.module`** (modificar) — registrar `GET /api/v1/units/%/receipts` en `hook_menu()`.
- **`myapi.info`** (modificar) — agregar `resources/receipt.resource.inc` e `includes/myapi.unit_access.inc` a `files[]`.
- **`docs/receipt.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Escritura de recibos** (crear/editar/eliminar nodos `recibo`) — solo lectura.
- **Endpoint de detalle de un recibo individual** (`GET /api/v1/units/%/receipts/%`) — solo se pide el listado.
- **Interpretación de negocio de los valores** (signo de `field_estado`, validación de valores permitidos, formato de `period_start`/`period_end`) — se expone tal cual está almacenado, sin transformación, igual criterio que `current_balance` en spec 10.
- **Cambios de comportamiento en `GET /api/v1/units`** — la única modificación a `unit.resource.inc` es mover `myapi_unit_related_nids()` a `includes/`; el endpoint existente se comporta exactamente igual.
- **Filtrado por estado de publicación de la unidad (`vivienda`)** — el control de acceso reutiliza `myapi_unit_related_nids()` tal cual existe hoy, que no filtra por `status` de la unidad; este spec no cambia ese criterio.

---

## Modelo de datos

Content type `recibo`, verificado en `schema.sql`. Todos los campos de lectura/consumo/alícuota son `decimal` single-value (misma forma que `field_saldo_actual` en spec 10); `field_estado`, `field_observacion` y `field_mensaje_demora` son texto; `field_periodo` es el único campo con dos columnas (`_value`/`_value2`); `field_vivienda` es la Entity Reference usada para filtrar por unidad.

| Tabla Drupal | Columna(s) | Uso |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | Nodos `recibo`. |
| `field_data_field_vivienda` | `entity_id`, `field_vivienda_target_id` | Recibo → unidad (`unit_id`). Filtro principal del endpoint. |
| `field_data_field_periodo` | `entity_id`, `field_periodo_value`, `field_periodo_value2` | Periodo del recibo (`period_start`/`period_end`). Columna de orden por defecto. |
| `field_data_field_estado` | `entity_id`, `field_estado_value` | `status`, valor de texto libre tal cual está almacenado. |
| `field_data_field_observacion` | `entity_id`, `field_observacion_value` | `observation`, texto libre (se ignora la columna `_format`). |
| `field_data_field_mensaje_demora` | `entity_id`, `field_mensaje_demora_value` | `late_payment_message`, texto libre (se ignora `_format`). |
| Resto de campos (30 tablas `field_data_field_*`) | `entity_id`, `field_*_value` | Todos `decimal`, mapeados 1:1 según la tabla de abajo. |

### Mapeo de campos → claves JSON

| Campo Drupal | Clave JSON | Tipo | Regla `NULL` |
|---|---|---|---|
| `nid` | `id` | int | nunca `NULL` |
| `title` | `title` | string | nunca `NULL` |
| `field_vivienda_target_id` | `unit_id` | int | nunca `NULL` (es el filtro de la query) |
| `field_periodo_value` | `period_start` | string | `NULL` si no hay fila |
| `field_periodo_value2` | `period_end` | string | `NULL` si no hay fila |
| `field_estado_value` | `status` | string | `NULL` si no hay fila |
| `field_gas_lectura_anterior_value` | `gas_previous_reading` | float | `NULL` si no hay fila |
| `field_gas_lectura_actual_value` | `gas_current_reading` | float | `NULL` si no hay fila |
| `field_consumo_gas_value` | `gas_consumption` | float | `NULL` si no hay fila |
| `field_agua_lectura_anterior_value` | `water_previous_reading` | float | `NULL` si no hay fila |
| `field_agua_lectura_actual_value` | `water_current_reading` | float | `NULL` si no hay fila |
| `field_consumo_agua_value` | `water_consumption` | float | `NULL` si no hay fila |
| `field_agua_caliente_lectura_ante_value` | `hot_water_previous_reading` | float | `NULL` si no hay fila |
| `field_agua_caliente_lectura_actu_value` | `hot_water_current_reading` | float | `NULL` si no hay fila |
| `field_consumo_agua_caliente_value` | `hot_water_consumption` | float | `NULL` si no hay fila |
| `field_calentamiento_agua_value` | `water_heating` | float | `NULL` si no hay fila |
| `field_gimnasio_value` | `gym` | float | `NULL` si no hay fila |
| `field_jacuzzi_sauna_turco_value` | `jacuzzi_sauna` | float | `NULL` si no hay fila |
| `field_extra_value` | `extra` | float | `NULL` si no hay fila |
| `field_alicuota_extra_value` | `extra_fee` | float | `NULL` si no hay fila |
| `field_internet_value` | `internet` | float | `NULL` si no hay fila |
| `field_energia_electrica_value` | `electricity` | float | `NULL` si no hay fila |
| `field_precalentamiento_value` | `preheating` | float | `NULL` si no hay fila |
| `field_alicuota_value` | `fee` | float | `NULL` si no hay fila |
| `field_alicuota_bodega_value` | `storage_fee` | float | `NULL` si no hay fila |
| `field_alicuota_parqueadero_value` | `parking_fee` | float | `NULL` si no hay fila |
| `field_alicuota_terraza_value` | `terrace_fee` | float | `NULL` si no hay fila |
| `field_alicuota_oficina_value` | `office_fee` | float | `NULL` si no hay fila |
| `field_alicuota_local_comercial_value` | `commercial_unit_fee` | float | `NULL` si no hay fila |
| `field_alicuota_total_value` | `total_fee` | float | `NULL` si no hay fila |
| `field_seguro_value` | `insurance` | float | `NULL` si no hay fila |
| `field_valor_penalizacion_value` | `penalty_amount` | float | `NULL` si no hay fila |
| `field_total_mes_value` | `monthly_total` | float | `NULL` si no hay fila |
| `field_saldo_anterior_value` | `previous_balance` | float | `NULL` si no hay fila |
| `field_total_value` | `total` | float | `NULL` si no hay fila |
| `field_observacion_value` | `observation` | string | `NULL` si no hay fila |
| `field_mensaje_demora_value` | `late_payment_message` | string | `NULL` si no hay fila |
| `field_tarifa_fija_gas_value` | `gas_fixed_rate` | float | `NULL` si no hay fila |
| `field_tarifa_fija_agua_value` | `water_fixed_rate` | float | `NULL` si no hay fila |
| `field_tarifa_fija_agua_caliente_value` | `hot_water_fixed_rate` | float | `NULL` si no hay fila |

### Contrato de paginación/orden

- Query params: `page` (default `1`), `limit` (default `20`, clamp a `[1, 50]`), `sort` (`asc`\|`desc`, default `desc`).
- Valores inválidos o ausentes de `page`/`limit`/`sort` caen a su default silenciosamente (sin error 422), consistente con el resto del endpoint no rechazando request por parámetros de query.
- Orden siempre por `field_periodo_value` (`period_start`).
- `total` = cantidad total de recibos de esa unidad, sin paginar. `total_pages` = `ceil(total / limit)`, o `0` si `total` es `0`.

### Forma de respuesta

```json
{
  "receipts": [
    {
      "id": 501,
      "title": "Recibo junio 2026",
      "unit_id": 45,
      "period_start": "2026-06-01",
      "period_end": "2026-06-30",
      "status": "pendiente",
      "gas_previous_reading": 120.5,
      "gas_current_reading": 135.2,
      "gas_consumption": 14.7,
      "...": "...(resto de campos decimales)...",
      "total": 187.32,
      "observation": null,
      "late_payment_message": null
    }
  ],
  "pagination": {
    "total": 12,
    "page": 1,
    "limit": 20,
    "total_pages": 1
  }
}
```

---

## Plan de implementación

1. **Extraer `myapi_unit_related_nids($uid)`** desde `resources/unit.resource.inc` a un archivo nuevo `includes/myapi.unit_access.inc`, sin cambiar firma ni lógica interna. En `unit.resource.inc`, agregar `module_load_include('inc', 'myapi', 'includes/myapi.unit_access')` y quitar la definición local. Agregar el archivo nuevo a `myapi.info`. *Punto de verificación: `GET /api/v1/units` sigue respondiendo exactamente igual que antes (regresión).*

2. **Crear `resources/receipt.resource.inc`** con:
   - `myapi_receipt_dispatch($unit_id)` — enruta por método; solo `GET` soportado, cualquier otro método → `myapi_error('method_not_allowed', 405)`.
   - `myapi_receipt_list($unit_id)`:
     - `myapi_auth_require_access_token()` para obtener `$uid`.
     - `myapi_unit_related_nids($uid)` (helper compartido) y verificación `in_array((int) $unit_id, $nids)`; si no está → `myapi_error('unit_access_denied', 403)`. Un `unit_id` no numérico o inexistente cae naturalmente en este mismo camino (no hace falta validación aparte).
     - Parseo de `page`/`limit`/`sort` desde `$_GET` con los defaults/clamps definidos en el modelo de datos (sin errores 422 por valores inválidos).
     - Query de conteo (`COUNT(*)`) sobre nodos `recibo` publicados con `field_vivienda_target_id = $unit_id`, para `total`.
     - Query paginada con los LEFT JOINs de todos los campos de la tabla de mapeo, `ORDER BY field_periodo_value` según `sort`, `range()` según `page`/`limit`.
     - Construcción del array `receipts` aplicando el mapeo de claves y tipos.
     - `myapi_respond(['receipts' => $receipts, 'pagination' => [...]], 200)`.

3. **Registrar la ruta en `myapi.module`:**
   ```php
   $items['api/v1/units/%/receipts'] = [
     'page callback'    => 'myapi_receipt_dispatch',
     'page arguments'   => [3],
     'access callback'  => TRUE,
     'type'             => MENU_CALLBACK,
     'file'             => 'resources/receipt.resource.inc',
   ];
   ```

4. **Agregar a `myapi.info`:** `files[] = resources/receipt.resource.inc` y `files[] = includes/myapi.unit_access.inc`.

5. **Crear `docs/receipt.md`** siguiendo la plantilla del proyecto: descripción, autenticación, query params de paginación/orden, tabla completa de campos de respuesta, tabla de errores, nota sobre `unit_access_denied` no distinguiendo "no existe" de "no es tuya".

6. **Aplicar y verificar.** `drush cc all` y probar con `curl`:
   - Unidad accesible con recibos → `200`, `receipts` con todos los campos mapeados, `pagination` correcta.
   - Unidad accesible sin recibos → `200`, `receipts: []`, `pagination.total: 0`, `total_pages: 0`.
   - `unit_id` de una unidad ajena (no propietario/ocupante) → `403 unit_access_denied`.
   - `unit_id` inexistente → `403 unit_access_denied` (mismo código que el caso anterior).
   - Sin header `Authorization` → `401 missing_authorization`.
   - Token inválido/expirado → `401 invalid_token`.
   - `?page=2&limit=5` → página siguiente correcta.
   - `?sort=asc` → orden ascendente por `period_start`.
   - `?limit=999` → se aplica el clamp a `50`.
   - Método `POST`/`PUT`/`DELETE` → `405 method_not_allowed`.
   - `GET /api/v1/units` sigue funcionando igual tras el refactor del paso 1 (regresión).

---

## Criterios de aceptación

- [x] `GET /api/v1/units/<unit_id>/receipts` con token válido y `unit_id` de una unidad donde el usuario es propietario u ocupante devuelve `200` con `receipts` (array de recibos mapeados según la tabla del modelo de datos) y `pagination` (`total`, `page`, `limit`, `total_pages`).
- [x] Cada recibo devuelto incluye exactamente las claves de la tabla de mapeo (40 en total: `id`, `title`, `unit_id` + 37 campos traducidos), con `NULL` cuando el nodo no tiene fila en ese campo.
- [x] Solo se listan recibos publicados (`status = 1`) cuyo `field_vivienda_target_id` sea igual al `unit_id` de la ruta.
- [x] `unit_id` de una unidad que no es del usuario autenticado (ni propietario ni ocupante) devuelve `403` con `error_code: unit_access_denied`.
- [x] `unit_id` inexistente devuelve el mismo `403 unit_access_denied` que el caso anterior (no se distingue el motivo).
- [x] Sin header `Authorization` devuelve `401 missing_authorization`; token inválido/expirado/revocado devuelve `401 invalid_token` — mismo comportamiento que el resto de endpoints autenticados.
- [x] Cualquier método distinto de `GET` devuelve `405 method_not_allowed`.
- [x] `?page` y `?limit` paginan correctamente; `limit` se clampa a `[1, 50]`; valores inválidos o ausentes caen a los defaults (`page=1`, `limit=20`) sin error.
- [c] `?sort=asc`/`?sort=desc` invierte el orden por `period_start` (`field_periodo_value`); default `desc`; valor inválido cae a `desc`.
- [c] Una unidad sin recibos devuelve `200` con `receipts: []` y `pagination.total: 0`, `total_pages: 0` (no es un error).
- [c] Pedir una página fuera de rango devuelve `200` con `receipts: []`, no un error.
- [x] `GET /api/v1/units` no cambia su comportamiento tras extraer `myapi_unit_related_nids()` a `includes/myapi.unit_access.inc`.
- [x] `docs/receipt.md` documenta el endpoint completo (auth, query params, campos de respuesta, errores).
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nombre del recurso | `receipt`/`receipts` (inglés) | `alicuota`/`alicuotas` (literal) | CLAUDE.md prohíbe identificadores en español y exige rutas API en inglés; consistente con la traducción ya usada `vivienda→unit`, `condominio→property`. |
| Archivo de la lógica | Archivo nuevo `resources/receipt.resource.inc` | Agregar funciones a `unit.resource.inc` | `recibo` es un content type propio, distinto de `vivienda`; sigue la regla "un recurso = un archivo". |
| Lógica de acceso compartida | Extraer `myapi_unit_related_nids()` a `includes/myapi.unit_access.inc` | Duplicar la consulta dentro de `receipt.resource.inc` | Regla del proyecto: recursos no se llaman entre sí ni duplican lógica; ambos archivos necesitan la misma verificación. |
| Semántica de acceso denegado | `403 unit_access_denied` uniforme, exista o no la unidad | Distinguir `404` (no existe) de `403` (no es tuya) | No revela a un usuario si un `unit_id` ajeno existe o no. |
| Metadata de paginación | Objeto `pagination` separado (`total`, `page`, `limit`, `total_pages`), default `limit=20`, máximo `50` | Default `limit=50` (el máximo) | Pedido explícito de paginación con límite máximo razonable; un default menor evita respuestas pesadas por defecto cuando el cliente no especifica `limit`. |
| Query param de orden | `?sort=asc\|desc` | `?order=asc\|desc` | Elegido por el usuario; no hay precedente previo en el proyecto que favorezca uno sobre otro. |
| Exposición de `field_estado` | Clave `status`, valor crudo tal cual almacenado | Traducir/validar contra una lista de valores permitidos | Mismo criterio que `category` en spec 08/09: no hay spec de negocio que defina la semántica de los valores. |
| Nombres para `field_periodo` | `period_start` / `period_end` | `period` / `period_end` | Refleja mejor que el campo es un rango (inicio/fin), no un valor único. |
| Filtro de publicación de recibos | Solo `status = 1` | Incluir recibos no publicados | Consistente con el filtro `status = 1` ya aplicado a `vivienda`/`condominio` en `GET /api/v1/units`. |
| Campo `unit_id` en cada recibo | Incluido, aunque es redundante con la ruta | Omitido por redundante | Pedido explícito (el usuario listó `field_vivienda` entre los campos a devolver); evita que el cliente deba recordar el parámetro de ruta al procesar cada ítem. |
| Página fuera de rango | `200` con `receipts: []` | `422` por parámetro inválido | Consistente con el criterio ya usado en `GET /api/v1/units` para "usuario sin unidades": lista vacía, no error. |
| Validación de `page`/`limit`/`sort` | Valores inválidos caen a su default silenciosamente, sin `422` | Rechazar la request con `422` si el valor no es válido | Los `422` del proyecto se reservan para validación del body (`myapi_request_require_fields`/`_strings`); los query params de este endpoint siguen un criterio más laxo, igual de simple de razonar y sin agregar código de validación extra. |

---

## Riesgos identificados

- **Refactor de `myapi_unit_related_nids()` a `includes/`.** Si el `module_load_include()` nuevo en `unit.resource.inc` está mal referenciado, se rompe `GET /api/v1/units` además del endpoint nuevo. *Mitigación:* paso de verificación explícito en el plan (probar `GET /api/v1/units` tras el refactor, antes de seguir).
- **Lectura directa de ~34 tablas de Field API.** Igual que specs 09/10, un cambio de schema en cualquiera de estos campos (rename, cambio de tipo, paso a multi-value) rompe silenciosamente esta consulta sin aviso de Drupal. *Mitigación:* documentado en la tabla de mapeo de este spec y en `docs/receipt.md`.
- **`unit_id` como wildcard `%` sin `load function`.** Drupal no valida que el segmento de ruta sea numérico antes de invocar `myapi_receipt_dispatch()`. *Mitigación:* no se necesita validación aparte — un valor no numérico se castea a `(int) 0`, nunca coincide con un nid real y cae naturalmente en `403 unit_access_denied`, ya cubierto por el plan.
- **Precisión de `decimal(10,4)` al exponerse como `float` en JSON.** Igual riesgo que `current_balance` en spec 10: la conversión a `float` de PHP puede introducir imprecisión de punto flotante en casos extremos. *Mitigación:* ninguna acción adicional, aceptado como parte del contrato ya usado en el resto del endpoint de units.
- **Payload grande por recibo.** Cada ítem de `receipts` tiene 37 claves; con `limit` en su máximo (`50`) la respuesta puede ser pesada. *Mitigación:* ninguna, es el campo completo pedido explícitamente; el límite de `50` ya acota el peor caso.
- **`status` sin lista de valores validada.** Si el negocio agrega/cambia valores permitidos en `field_estado` dentro de Drupal, el endpoint los refleja tal cual sin romperse, pero el cliente Flutter podría no reconocer un valor nuevo. *Mitigación:* ninguna, aceptado — no hay spec de negocio que defina la lista cerrada de estados.
