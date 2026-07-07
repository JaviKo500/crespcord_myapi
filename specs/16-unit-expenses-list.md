# 16 — Listado de gastos de un condominio

- **Estado:** Implemented
- **Fecha:** 2026-07-07
- **Dependencias:**
  - `08-units-list` (Implemented) — `myapi_unit_related_nids()`, base sobre la que se construye el nuevo `myapi_condominium_related_nids()`.
  - `14-unit-payments-list` (Implemented) — modelo de referencia más cercano: misma forma de recurso, misma paginación, mismo filtro de fechas.
  - `13-unit-extra-fees-list` (Implemented) — se replica el patrón de "un solo estado expuesto" (`MYAPI_..._EXPOSED_STATUS`), aquí con el valor `Activo`.
  - `15-unlimited-limit-pagination` (Implemented) — el contrato `limit=-1` se implementa desde el día uno en este endpoint, sin necesidad de un spec adicional.
- **Objetivo:** Agregar `GET /api/v1/condominiums/<condominium_id>/expenses`, un endpoint autenticado y paginado que, verificando que el usuario sea propietario u ocupante de al menos una unidad de ese condominio, lista los nodos `gastos` (expuestos como `expenses`) en estado `Activo` de ese condominio, con filtro opcional por rango de fechas sobre `field_fecha_de_gasto`.

---

## Alcance

### Dentro de este spec

- **`resources/expense.resource.inc`** (nuevo) — `myapi_expense_dispatch($condominium_id)` (solo GET) y las funciones `myapi_expense_list()`, `myapi_expense_count()`, `myapi_expense_fetch()`, `myapi_expense_build_item()`, más los helpers de rango de fechas (`myapi_expense_parse_date_range()` / `myapi_expense_valid_date()`). Misma estructura que `payment.resource.inc`.
- **`includes/myapi.unit_access.inc`** (modificar) — nueva función `myapi_condominium_related_nids($uid)`, construida sobre `myapi_unit_related_nids()` existente: resuelve las unidades del usuario y luego sus condominios vía `field_data_field_condominio`.
- **`includes/myapi.i18n.inc`** (modificar) — nueva clave de catálogo `condominium_access_denied` (en/es).
- **`myapi.module`** (modificar) — registrar `GET /api/v1/condominiums/%/expenses` en `hook_menu()`.
- **`myapi.info`** (modificar) — agregar `resources/expense.resource.inc` a `files[]`.
- **`docs/expense.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Escritura de gastos** (crear/editar/eliminar nodos `gastos`) — solo lectura.
- **Endpoint de detalle individual** (`GET /api/v1/condominiums/%/expenses/%`) — solo el listado.
- **`GET /api/v1/condominiums`** (listado de condominios del usuario en sí, sin nada anidado) — este spec solo agrega el sub-recurso de gastos; el listado de condominios ya existe implícitamente agrupado dentro de `GET /api/v1/units`.
- **Interpretación de negocio de los valores** (semántica de `field_categoria`, formato de `field_fecha_de_gasto`, validación de montos) — se expone tal cual está almacenado, sin transformación, mismo criterio que `payments`/`receipts`/`extra-fees`.
- **Exponer gastos en un estado distinto de `Activo`** — cualquier gasto cuyo `field_estado_gasto` no sea exactamente `Activo`, o que no tenga fila de estado, queda oculto (inner join con `= 'Activo'`).
- **Filtrar sobre otro campo que no sea `field_fecha_de_gasto`** (por `category`, `status`, `amount`, etc.) — el único filtro opcional es el rango de fechas.
- **Cambios en `payments`, `receipts`, `extra-fees` o `units`** — este spec solo agrega un recurso nuevo y una función de acceso nueva; no toca los endpoints existentes.

---

## Modelo de datos

Content type `gastos`, confirmado por la URL de administración de campos (`admin/structure/types/manage/gastos/fields`) y por `schema.sql`. El nodo `gastos` se relaciona con el condominio vía `field_condominio` (Entity Reference) — el mismo campo, compartido, que usa el nodo `vivienda` para indicar a qué condominio pertenece cada unidad (ver `unit.resource.inc:151-152`).

| Tabla Drupal | Columna(s) | Uso |
|---|---|---|
| `node` | `nid`, `type`, `status` | Nodos `vivienda` del usuario, para resolver los condominios a los que tiene acceso. |
| `field_data_field_condominio` | `entity_id`, `field_condominio_target_id` | 1) Para cada unidad del usuario (`entity_id IN unit_nids`): resuelve los condominios accesibles (`myapi_condominium_related_nids`). 2) En cada nodo `gastos`: filtro principal (inner join `= $condominium_id` de la ruta) y valor expuesto como `condominium_id`. |
| `node` | `nid`, `title`, `type`, `status` | Nodos `gastos` publicados. |
| `field_data_field_estado_gasto` | `entity_id`, `field_estado_gasto_value` | `status`. Inner join con condición `= 'Activo'`: solo se exponen gastos en ese estado exacto (los sin fila de estado quedan fuera). |
| `field_data_field_fecha_de_gasto` | `entity_id`, `field_fecha_de_gasto_value` | `expense_date`. Columna de orden y de filtro de rango de fechas. |
| `field_data_field_descripcion` | `entity_id`, `field_descripcion_value` | `description`, texto. |
| `field_data_field_categoria` | `entity_id`, `field_categoria_tid` | `category_id`. Join a `taxonomy_term_data.tid` para `category_name`. |
| `field_data_field_valor` | `entity_id`, `field_valor_value` | `amount`, decimal. |
| `field_data_field_referencia` | `entity_id`, `field_referencia_value` | `reference`, texto. |
| `taxonomy_term_data` | `tid`, `name` | Resuelve el nombre de la categoría (mismo patrón que `unit.resource.inc` con `field_categoria`). |

### Control de acceso

`myapi_condominium_related_nids($uid)` (nuevo, en `includes/myapi.unit_access.inc`): reutiliza `myapi_unit_related_nids($uid)` para obtener las unidades del usuario, y resuelve sus condominios vía `field_data_field_condominio` (`entity_id IN unit_nids`, `entity_type = 'node'`, `deleted = 0`), devolviendo la lista única de `condominio_nid`.

El endpoint verifica `in_array((int) $condominium_id, myapi_condominium_related_nids($uid))`; si no está → `myapi_error('condominium_access_denied', 403)`. Esto cubre tanto un condominio ajeno como uno inexistente, sin distinguir el motivo (mismo criterio que `unit_access_denied` en `payments`/`receipts`/`extra-fees`).

### Mapeo de campos → claves JSON

| Campo Drupal | Clave JSON | Tipo | Regla `NULL` |
|---|---|---|---|
| `nid` | `id` | int | nunca `NULL` |
| `title` | `title` | string | nunca `NULL` |
| `field_condominio_target_id` (del gasto) | `condominium_id` | int | nunca `NULL` (viene directo de la ruta, es el filtro de la query) |
| `field_descripcion_value` | `description` | string | `NULL` si no hay fila |
| `field_categoria_tid` | `category_id` | int | `NULL` si no hay fila |
| `taxonomy_term_data.name` (join por `category_id`) | `category_name` | string | `NULL` si no hay fila o el término no existe/no resuelve |
| `field_fecha_de_gasto_value` | `expense_date` | string | `NULL` si no hay fila |
| `field_valor_value` | `amount` | float | `NULL` si no hay fila |
| `field_referencia_value` | `reference` | string | `NULL` si no hay fila |
| `field_estado_gasto_value` | `status` | string | nunca `NULL` (inner join `= 'Activo'`); siempre vale `"Activo"` |

### Contrato de paginación / orden

- Query params: `page` (default `1`), `limit` (default `20`, clamp a `[1, 50]`, o `-1` para desactivar paginación y devolver todo el conjunto filtrado — igual que spec 15, implementado desde el día uno), `sort` (`asc`\|`desc`, default `desc`).
- Valores inválidos o ausentes caen a su default silenciosamente (sin `422`).
- Orden siempre por `field_fecha_de_gasto_value` (`expense_date`).
- `total` = cantidad total de gastos del condominio en estado `Activo` (con el rango de fechas ya aplicado si viene), sin paginar. `total_pages` = `ceil(total / limit)` (o `1` si `limit = -1` y `total > 0`), o `0` si `total` es `0`.

### Filtro por rango de fechas (opcional)

| Param | Formato | Default | Regla |
|---|---|---|---|
| `date_from` | `YYYY-MM-DD` | ausente = sin límite inferior | Si es válido, filtra `expense_date >= date_from`. |
| `date_to` | `YYYY-MM-DD` | ausente = sin límite superior | Si es válido, filtra `expense_date <= date_to`. |

- Un límite se considera **válido** solo si matchea `YYYY-MM-DD` y es fecha real (`checkdate()`); cualquier otra cosa se ignora.
- Rango invertido (`from > to`) descarta el filtro completo. Nunca hay `422`.
- Comparación sobre los primeros 10 caracteres: `SUBSTR(field_fecha_de_gasto_value, 1, 10) >= :date_from` / `<= :date_to`. Un nodo sin fila en `field_fecha_de_gasto` queda excluido cuando hay filtro activo.

### Forma de respuesta

```json
{
  "expenses": [
    {
      "id": 1204,
      "title": "Mantenimiento ascensor julio",
      "condominium_id": 12,
      "description": "Mantenimiento mensual de ascensores",
      "category_id": 34,
      "category_name": "Mantenimiento",
      "expense_date": "2026-07-05",
      "amount": 320.50,
      "reference": "FAC-00981",
      "status": "Activo"
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

1. **Agregar `myapi_condominium_related_nids($uid)` a `includes/myapi.unit_access.inc`:**
   ```php
   function myapi_condominium_related_nids($uid) {
     $unit_nids = myapi_unit_related_nids($uid);
     if (empty($unit_nids)) {
       return [];
     }
     $condo_nids = db_select('field_data_field_condominio', 'fc')
       ->fields('fc', ['field_condominio_target_id'])
       ->condition('entity_id', $unit_nids, 'IN')
       ->condition('entity_type', 'node')
       ->condition('deleted', 0)
       ->execute()
       ->fetchCol();
     return array_values(array_unique($condo_nids));
   }
   ```

2. **Agregar la clave `condominium_access_denied` a `includes/myapi.i18n.inc`**, en/es (p.ej. `'You do not have access to this condominium.'` / `'No tienes acceso a este condominio.'`), junto a `unit_access_denied`.

3. **Crear `resources/expense.resource.inc`** con los mismos `module_load_include()` que `payment.resource.inc` (request, response, i18n, token, auth, unit_access) y `define('MYAPI_EXPENSE_EXPOSED_STATUS', 'Activo')`.
   - `myapi_expense_dispatch($condominium_id)` — enruta por método; solo `GET`, cualquier otro → `myapi_error('method_not_allowed', 405)`.
   - `myapi_expense_list($condominium_id)`:
     - `myapi_auth_require_access_token()` → `$uid`.
     - `myapi_condominium_related_nids($uid)` + `in_array((int) $condominium_id, ...)`; si no → `myapi_error('condominium_access_denied', 403)`.
     - Parseo de `page`/`limit`/`sort` con los defaults/clamps del modelo de datos, incluyendo `limit=-1` (idéntico al bloque de spec 15).
     - `myapi_expense_parse_date_range()` → `$from`/`$to`.
     - `myapi_expense_count($condominium_id, $from, $to)` para `total`; `total_pages` según la fórmula del modelo de datos.
     - `myapi_expense_fetch($condominium_id, $page, $limit, $sort, $from, $to)` + `array_map('myapi_expense_build_item', $rows)`.
     - `myapi_respond(['expenses' => $items, 'pagination' => [...]], 200)`.
   - `myapi_expense_parse_date_range()` / `myapi_expense_valid_date()` — idénticas a las de `payment.resource.inc`.

4. **`myapi_expense_count($condominium_id, $from, $to)`** — `db_select('node', 'n')` con `type = 'gastos'` y `status = 1`; inner join a `field_data_field_condominio` (`= $condominium_id`); inner join a `field_data_field_estado_gasto` con condición `field_estado_gasto_value = MYAPI_EXPENSE_EXPOSED_STATUS`; si hay algún límite de fecha, inner join a `field_data_field_fecha_de_gasto` con las condiciones `SUBSTR(field_fecha_de_gasto_value,1,10) >= / <= :bound`. Devuelve `countQuery()`.

5. **`myapi_expense_fetch($condominium_id, $page, $limit, $sort, $from, $to)`** — misma base; `addField` sobre el join de `field_condominio` para exponer `condominium_id`; `leftJoin` a `field_fecha_de_gasto` (`expense_date`) con las condiciones de rango cuando hay filtro; inner join a `field_estado_gasto` (`= 'Activo'`, expone `status`); left joins a `field_descripcion`, `field_valor`, `field_referencia`; left join a `field_categoria` (`category_id`) y luego left join a `taxonomy_term_data` por `field_categoria_tid = tid` (`category_name`). `orderBy('fecha.field_fecha_de_gasto_value', $sort)`; `range()` solo si `$limit !== -1` (igual que spec 15).

6. **`myapi_expense_build_item($row)`** — arma el ítem: `id`/`condominium_id`/`category_id` a `int` (`category_id` solo si no es `NULL`), `title`/`description`/`expense_date`/`category_name`/`reference`/`status` tal cual, y `amount` a `float` cuando no es `NULL`.

7. **Registrar la ruta en `myapi.module`:**
   ```php
   $items['api/v1/condominiums/%/expenses'] = [
     'page callback'   => 'myapi_expense_dispatch',
     'page arguments'  => [3],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/expense.resource.inc',
   ];
   ```

8. **Agregar a `myapi.info`:** `files[] = resources/expense.resource.inc`.

9. **Crear `docs/expense.md`** siguiendo la plantilla: descripción, auth, query params (paginación incl. `limit=-1` + `date_from`/`date_to`), tabla de campos de respuesta, tabla de errores, nota de que solo se exponen gastos en estado `Activo` y de que `condominium_access_denied` no distingue "no existe" de "no es tuyo".

10. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación.

---

## Criterios de aceptación

- [x] `GET /api/v1/condominiums/<condominium_id>/expenses` con token válido y `condominium_id` de un condominio donde el usuario tiene al menos una unidad (propietario u ocupante) devuelve `200` con `expenses` (array mapeado según el modelo de datos) y `pagination` (`total`, `page`, `limit`, `total_pages`).
- [x] Cada ítem incluye exactamente las 9 claves: `id`, `title`, `condominium_id`, `description`, `category_id`, `category_name`, `expense_date`, `amount`, `reference`, `status`, con `NULL` en `description`/`category_id`/`category_name`/`expense_date`/`amount`/`reference` cuando el nodo no tiene fila en ese campo.
- [x] Solo se listan nodos `gastos` publicados (`status = 1`) cuyo `field_condominio_target_id` coincide con `condominium_id` **y** `field_estado_gasto = 'Activo'`; un gasto en otro estado o sin fila de estado queda excluido.
- [x] `status` es siempre `"Activo"` en cada ítem devuelto.
- [x] `condominium_id` inexistente o ajeno (el usuario no tiene ninguna unidad ahí) devuelve `403 condominium_access_denied`, sin distinguir el motivo.
- [x] Un usuario con unidades en dos condominios distintos solo ve los `expenses` del `condominium_id` pedido en la ruta, no los del otro condominio donde también tiene acceso.
- [x] Sin header `Authorization` → `401 missing_authorization`; token inválido/expirado/revocado → `401 invalid_token`.
- [x] Cualquier método distinto de `GET` → `405 method_not_allowed`.
- [x] `?page` y `?limit` paginan correctamente; `limit` se clampa a `[1, 50]`; valores inválidos/ausentes caen a los defaults (`page=1`, `limit=20`) sin error.
- [x] `?limit=-1` devuelve todos los gastos del conjunto filtrado en un solo array, con `pagination.limit: -1`, `pagination.page: 1` (ignorando `?page` si vino) y `pagination.total_pages` en `1` (si `total > 0`) o `0` (si `total` es `0`).
- [x] `?sort=asc`/`?sort=desc` invierte el orden por `expense_date` (`field_fecha_de_gasto_value`); default `desc`; valor inválido cae a `desc`.
- [x] `date_from`/`date_to` filtran sobre `expense_date` (primeros 10 caracteres) de forma inclusiva; cada límite es independiente; el borde superior incluye el día indicado aunque haya sufijo de hora.
- [x] `pagination.total` y `total_pages` reflejan el conjunto **ya filtrado** (`Activo` + rango de fechas), no el total bruto del condominio.
- [x] `date_from`/`date_to` con formato inválido, o rango invertido (`from > to`), se ignoran sin `422`; nodos sin fila en `field_fecha_de_gasto` quedan excluidos cuando hay al menos un límite activo.
- [x] Un condominio sin gastos en estado `Activo` (o una página fuera de rango) devuelve `200` con `expenses: []` y `pagination.total: 0`, `total_pages: 0` (no es error).
- [x] `docs/expense.md` documenta el endpoint completo (auth, query params, campos de respuesta, errores).
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nombre del recurso | `expense` / ruta `expenses` / array `expenses` | `gasto`, `gastos` (literal) | CLAUDE.md prohíbe identificadores en español y exige rutas en inglés plural; consistente con `payments`/`extra-fees`. |
| Ruta de exposición | `GET /api/v1/condominiums/<condominium_id>/expenses`, con `condominium_id` como parámetro directo de la ruta | `GET /api/v1/units/<unit_id>/expenses`, resolviendo el condominio internamente a partir de la unidad | El cliente ya conoce y envía el `condominium_id` directamente; forzar a pasar por un `unit_id` intermedio agregaría una resolución innecesaria y sería menos RESTful (el gasto pertenece al condominio, no a la unidad). |
| Helper de acceso | `myapi_condominium_related_nids($uid)`, nuevo, extraído a `includes/myapi.unit_access.inc` | Resolverlo inline dentro de `expense.resource.inc` | Es un helper de control de acceso genuinamente reutilizable (cualquier futuro recurso scopeado por condominio lo necesitará), a diferencia de la resolución unidad→condominio de la versión anterior del spec, que era de un solo uso. |
| Código de error de acceso | `condominium_access_denied` (nueva clave en el catálogo i18n) | Reutilizar `unit_access_denied` | El recurso protegido ahora es un condominio, no una unidad; usar la clave existente sería confuso y semánticamente incorrecto para el cliente. |
| Criterio de estado | Exponer solo `Activo` (inner join `= 'Activo'`) | Exponer todos menos uno (como `payments`), o exponer todos | Pedido explícito del usuario. |
| Campo `status` en la respuesta | Incluido siempre, con valor constante `"Activo"` | Omitirlo por ser constante | Pedido explícito del usuario; consistencia con el patrón de `extra-fees` (estado único, igual se expone la clave). |
| Campo `category` | Se exponen **dos** claves: `category_id` (tid crudo) y `category_name` (nombre resuelto vía `taxonomy_term_data`) | Exponer solo una de las dos | Pedido explícito del usuario; mismo patrón de doble clave que `owner_uid`/`owner_name` en `unit.resource.inc`. |
| Paginación y `limit=-1` | Implementado desde el día uno, idéntico a spec 15 | Implementarlo simple primero y agregar `limit=-1` en un spec aparte | Pedido explícito del usuario ("todo igual" a `payments`); evita una deuda técnica conocida de antemano. |
| Filtro de fechas | `date_from`/`date_to` sobre `field_fecha_de_gasto`, comportamiento laxo (idéntico a `payments`) | Sin filtro, o filtro con `422` | Pedido explícito del usuario. |
| Semántica de acceso denegado | `403 condominium_access_denied` uniforme, exista o no el condominio | Distinguir `404` de `403` | No revela si un `condominium_id` ajeno existe; mismo criterio que `unit_access_denied` en `payments`/`receipts`/`extra-fees`. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Campos compartidos entre content types.** `field_condominio` lo usan tanto `vivienda` como `gastos`; `field_categoria`, `field_valor` y `field_referencia` los usan también otros bundles. Sin filtro por tipo de nodo, la query traería filas ajenas. | Todas las consultas filtran por `n.type` (`vivienda` dentro de `myapi_condominium_related_nids`, `gastos` para el listado); el `entity_id` de cada join amarra la fila al nodo correcto. |
| **Nodo `condominio` no publicado o eliminado.** El endpoint no verifica el estado de publicación del nodo condominio antes de listar sus gastos; solo verifica que el usuario tenga una unidad que apunte a ese `condominium_id`. | Aceptado: el criterio de acceso ya pasó por las unidades propias del usuario; no se agrega una segunda verificación para no introducir un comportamiento no pedido. Si se detecta un caso real, se ajusta en un spec de corrección. |
| **Categoría con término eliminado o no resuelto.** `category_id` puede venir con un `tid` que ya no existe en `taxonomy_term_data`, devolviendo `category_name: NULL` junto a un `category_id` no nulo. | Comportamiento documentado explícitamente en la tabla de mapeo; el cliente debe tolerar esa combinación. |
| **Lectura directa de tablas de Field API.** Un cambio de schema en cualquiera de los campos usados (rename, cambio de tipo, paso a multi-value) rompe silenciosamente la consulta sin aviso de Drupal. | Documentado en la tabla de mapeo del spec y en `docs/expense.md`; mismo criterio ya aceptado en `payments`/`receipts`/`extra-fees`. |
| **Formato real de `field_fecha_de_gasto_value` desconocido.** `schema.sql` solo trae estructura (`varchar(20)`), no datos. | La comparación por `SUBSTR(...,1,10)` y el orden por la columna cruda funcionan tanto con `2026-07-05` como con `2026-07-05T00:00:00`; verificar en el paso 10 del plan. |
| **Precisión de `decimal(10,2)` al exponerse como `float`.** La conversión a `float` de PHP puede introducir imprecisión de punto flotante en casos extremos. | Sin acción adicional; parte del contrato ya aceptado en `payments`/`receipts`/`extra-fees`/`units`. |
| **`condominium_id` como wildcard `%` sin `load function`.** Drupal no valida que el segmento sea numérico antes de invocar el dispatcher. | Un valor no numérico se castea a `(int) 0`, nunca coincide con un nid real y cae en `403 condominium_access_denied`; ya cubierto. |
