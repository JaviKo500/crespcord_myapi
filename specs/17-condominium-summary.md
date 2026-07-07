# 17 — Resumen de condominio (total de gastos y saldo de caja)

- **Estado:** Approved
- **Fecha:** 2026-07-07
- **Dependencias:**
  - `16-unit-expenses-list` (Implemented) — provee `myapi_condominium_related_nids()`, la clave i18n `condominium_access_denied`, el criterio de estado único `Activo` (`MYAPI_EXPENSE_EXPOSED_STATUS`) y el parseo de rango de fechas que aquí se **extrae a un helper compartido**.
  - `10-units-saldo-actual` (Implemented) — patrón de referencia para exponer un `decimal` de Field API como `float` con clave en inglés y regla de `NULL` (`current_balance` → aquí `cash_balance`).
  - `12-receipts-date-range-filter` (Implemented) — origen del comportamiento laxo de `date_from`/`date_to` que ahora se comparte.
- **Objetivo:** Agregar `GET /api/v1/condominiums/<condominium_id>/summary`, un endpoint autenticado que, verificando que el usuario sea propietario u ocupante de al menos una unidad del condominio, devuelve el `id` y `name` del condominio, el `total_expenses` (suma de montos de los gastos en estado `Activo`, con filtro opcional por rango de fechas), el `expenses_count` (cuántos gastos entraron en esa suma) y el `cash_balance` (`field_saldo_caja`).

---

## Alcance

### Dentro de este spec

- **`resources/condominium.resource.inc`** (nuevo) — `myapi_condominium_dispatch($condominium_id)` (solo `GET`) y las funciones `myapi_condominium_summary()`, `myapi_condominium_expense_totals()` (suma + conteo de gastos `Activo`), `myapi_condominium_cash_balance()` (lee `field_saldo_caja`) y `myapi_condominium_name()` (title del nodo). Mismo esqueleto de `module_load_include()` que `expense.resource.inc`.
- **`includes/myapi.request.inc`** (modificar) — nuevo helper genérico `myapi_parse_date_range_param()` (+ `myapi_valid_iso_date()`), extraído de las funciones que hoy viven en `expense.resource.inc`, para que ambos recursos compartan el parseo de rango de fechas sin duplicar lógica.
- **`resources/expense.resource.inc`** (modificar) — refactor sin cambio de comportamiento: `myapi_expense_parse_date_range()`/`myapi_expense_valid_date()` pasan a delegar en el nuevo helper compartido (o se reemplazan por él).
- **`myapi.module`** (modificar) — registrar `GET /api/v1/condominiums/%/summary` en `hook_menu()`.
- **`myapi.info`** (modificar) — agregar `resources/condominium.resource.inc` a `files[]`.
- **`docs/condominium.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Listado de condominios** (`GET /api/v1/condominiums` sin `%`) — este spec solo agrega el sub-recurso `summary` de un condominio concreto.
- **Detalle/edición del condominio** (crear/editar nodos `condominio`, escribir `field_saldo_caja`) — solo lectura.
- **Desglose de gastos** — este endpoint devuelve el **agregado** (suma + conteo); el listado ítem por ítem ya lo cubre `GET /api/v1/condominiums/%/expenses` (spec 16).
- **Sumar/contar gastos en un estado distinto de `Activo`** — mismo criterio de estado único que spec 16; cualquier otro estado (o sin fila de estado) queda fuera del agregado.
- **Interpretación de negocio de los valores** (signo del saldo, semántica de montos, moneda) — se exponen tal cual están almacenados, sin transformación (mismo criterio que specs 10 y 16).
- **Filtrar el agregado por otro campo que no sea el rango de fechas** (categoría, referencia, etc.) — el único filtro opcional es `date_from`/`date_to` sobre `field_fecha_de_gasto`.
- **Cambios de comportamiento en `expenses`** — el toque a `expense.resource.inc` es solo el refactor del helper de fechas; su salida no cambia.

---

## Modelo de datos

El nodo `condominio` es el mismo destino al que apunta `field_condominio` de cada `vivienda` (ver `myapi_condominium_related_nids()`, spec 16). El agregado de gastos usa las mismas tablas que `expense.resource.inc`. `field_saldo_caja` está atado al bundle `condominio` (`decimal(10,2)`, single-value), confirmado en `schema.sql` (`dr_field_data_field_saldo_caja`).

| Tabla Drupal | Columna(s) | Uso |
|---|---|---|
| `node` (condominio) | `nid`, `title` | `nid` = `condominium_id` de la ruta; `title` = `name`. Se lee el título del nodo condominio directamente. |
| `field_data_field_saldo_caja` | `entity_id`, `field_saldo_caja_value` | `cash_balance`. `entity_id = condominium_id`, `entity_type = 'node'`, `deleted = 0`. Single-value → 1 fila. |
| `node` (gastos) | `nid`, `type`, `status` | Nodos `gastos` publicados (`type = 'gastos'`, `status = 1`) que entran en el agregado. |
| `field_data_field_condominio` | `entity_id`, `field_condominio_target_id` | Filtro principal del agregado: inner join `= condominium_id`. |
| `field_data_field_estado_gasto` | `entity_id`, `field_estado_gasto_value` | Inner join `= 'Activo'` (`MYAPI_EXPENSE_EXPOSED_STATUS`): solo gastos en ese estado entran en la suma y el conteo. |
| `field_data_field_valor` | `entity_id`, `field_valor_value` | Sumando de `total_expenses` (`SUM`) y base del `expenses_count` (`COUNT`). |
| `field_data_field_fecha_de_gasto` | `entity_id`, `field_fecha_de_gasto_value` | Solo cuando hay filtro: inner join con `SUBSTR(...,1,10) >= / <=` para acotar el agregado por rango de fechas. |

### Control de acceso

Idéntico a spec 16: `myapi_auth_require_access_token()` → `$uid`; `in_array((int) $condominium_id, myapi_condominium_related_nids($uid))`; si no está → `myapi_error('condominium_access_denied', 403)`. Cubre condominio ajeno y condominio inexistente sin distinguir el motivo. Un `condominium_id` no numérico se castea a `(int) 0` y cae en `403`.

### Agregado de gastos (`total_expenses` / `expenses_count`)

- Se calcula sobre el **mismo conjunto** que listaría spec 16: gastos `type='gastos'`, `status=1`, `field_condominio = condominium_id`, `field_estado_gasto = 'Activo'`, más el rango de fechas si viene.
- `total_expenses` = `SUM(field_valor_value)` de ese conjunto, expuesto como `float`. Conjunto vacío → **`0.0`** (nunca `NULL`).
- `expenses_count` = `COUNT` de gastos de ese conjunto, `int`. Conjunto vacío → `0`.
- Un gasto en el conjunto pero **sin fila** en `field_data_field_valor` cuenta en `expenses_count` pero aporta `0` a la suma (left join a `field_valor`; `SUM` ignora `NULL`). Nota: `total_expenses` y `expenses_count` pueden no cuadrar 1:1 si algún gasto no tiene monto.

### Mapeo → claves JSON

| Fuente | Clave JSON | Tipo | Regla `NULL` |
|---|---|---|---|
| `node.nid` (ruta) | `id` | int | nunca `NULL` |
| `node.title` (condominio) | `name` | string | `NULL` solo si el nodo no existe/no resuelve (con acceso concedido no debería ocurrir) |
| `SUM(field_valor_value)` | `total_expenses` | float | nunca `NULL` (conjunto vacío → `0.0`) |
| `COUNT` gastos `Activo` | `expenses_count` | int | nunca `NULL` (conjunto vacío → `0`) |
| `field_saldo_caja_value` | `cash_balance` | float | `NULL` si el condominio no tiene fila en `field_data_field_saldo_caja` |

### Filtro por rango de fechas (opcional)

Mismo contrato laxo que spec 16, ahora vía el helper compartido `myapi_parse_date_range_param()`:

| Param | Formato | Default | Regla |
|---|---|---|---|
| `date_from` | `YYYY-MM-DD` | ausente = sin límite inferior | Válido solo si matchea el formato y `checkdate()`; filtra `SUBSTR(field_fecha_de_gasto_value,1,10) >= date_from`. |
| `date_to` | `YYYY-MM-DD` | ausente = sin límite superior | Válido solo si matchea el formato y `checkdate()`; filtra `SUBSTR(...,1,10) <= date_to`. |

- Formato inválido → se ignora ese límite. Rango invertido (`from > to`) → se descarta el filtro completo. Nunca hay `422`.
- Con filtro activo, un gasto sin fila en `field_fecha_de_gasto` queda excluido del agregado (inner join).

### Forma de respuesta

```json
{
  "success": true,
  "data": {
    "id": 12,
    "name": "Edificio El Sáuco",
    "total_expenses": 4820.50,
    "expenses_count": 15,
    "cash_balance": 12500.00
  }
}
```

---

## Plan de implementación

1. **Extraer el helper de fechas a `includes/myapi.request.inc`:**
   - `myapi_valid_iso_date($value)` — copia de la actual `myapi_expense_valid_date()`: devuelve el string si matchea `YYYY-MM-DD` y pasa `checkdate()`, `NULL` si no.
   - `myapi_parse_date_range_param()` — copia de la actual `myapi_expense_parse_date_range()`: lee `$_GET['date_from']`/`$_GET['date_to']`, valida cada uno, descarta el filtro completo si `from > to`, devuelve `['from' => ..., 'to' => ...]`.

2. **Refactor de `resources/expense.resource.inc` (sin cambio de comportamiento):** reemplazar el cuerpo de `myapi_expense_parse_date_range()`/`myapi_expense_valid_date()` para que deleguen en los helpers compartidos (o eliminarlas y llamar directo a `myapi_parse_date_range_param()`). Verificar que `expenses` sigue respondiendo igual.

3. **Crear `resources/condominium.resource.inc`** con los mismos `module_load_include()` que `expense.resource.inc` (request, response, i18n, token, auth, unit_access) y `define('MYAPI_CONDOMINIUM_EXPENSE_STATUS', 'Activo')`.
   - `myapi_condominium_dispatch($condominium_id)` — solo `GET`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
   - `myapi_condominium_summary($condominium_id)`:
     - `myapi_auth_require_access_token()` → `$uid`.
     - `in_array((int) $condominium_id, myapi_condominium_related_nids($uid))`; si no → `myapi_error('condominium_access_denied', 403)`.
     - `$range = myapi_parse_date_range_param();` → `$from`/`$to`.
     - `$totals = myapi_condominium_expense_totals($condominium_id, $from, $to);` → `['total' => float, 'count' => int]`.
     - `$name = myapi_condominium_name($condominium_id);`
     - `$cash = myapi_condominium_cash_balance($condominium_id);`
     - `myapi_respond(['id' => (int) $condominium_id, 'name' => $name, 'total_expenses' => $totals['total'], 'expenses_count' => $totals['count'], 'cash_balance' => $cash], 200);`

4. **`myapi_condominium_expense_totals($condominium_id, $from, $to)`** — `db_select('node', 'n')` con `type='gastos'`, `status=1`; inner join a `field_data_field_condominio` (`= $condominium_id`); inner join a `field_data_field_estado_gasto` (`= MYAPI_CONDOMINIUM_EXPENSE_STATUS`); si hay límite de fecha, inner join a `field_data_field_fecha_de_gasto` con las condiciones `SUBSTR(...,1,10) >= / <=`; left join a `field_data_field_valor`. Seleccionar `SUM(fval.field_valor_value)` y `COUNT(n.nid)` con `addExpression()`. Devolver `['total' => $row->total !== NULL ? (float) $row->total : 0.0, 'count' => (int) $row->count]`.

5. **`myapi_condominium_name($condominium_id)`** — `db_select('node','n')` con `nid = $condominium_id` y `type='condominio'`, `fields('n',['title'])`; devolver el title o `NULL` si no hay fila.

6. **`myapi_condominium_cash_balance($condominium_id)`** — `db_select('field_data_field_saldo_caja','fs')` con `entity_id=$condominium_id`, `entity_type='node'`, `deleted=0`; devolver `(float) $value` o `NULL` si no hay fila.

7. **Registrar la ruta en `myapi.module`:**
   ```php
   $items['api/v1/condominiums/%/summary'] = [
     'page callback'   => 'myapi_condominium_dispatch',
     'page arguments'  => [3],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/condominium.resource.inc',
   ];
   ```

8. **Agregar a `myapi.info`:** `files[] = resources/condominium.resource.inc`.

9. **Crear `docs/condominium.md`** siguiendo la plantilla: descripción, auth, query params (`date_from`/`date_to`), tabla de campos de respuesta, tabla de errores, nota de que el agregado solo cuenta gastos `Activo` y de que `condominium_access_denied` no distingue "no existe" de "no es tuyo".

10. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación (incluido re-probar `GET /api/v1/condominiums/%/expenses` para confirmar que el refactor no lo alteró).

---

## Criterios de aceptación

- [ ] `GET /api/v1/condominiums/<condominium_id>/summary` con token válido y un `condominium_id` donde el usuario tiene al menos una unidad (propietario u ocupante) devuelve `200` con `data` conteniendo exactamente 5 claves: `id`, `name`, `total_expenses`, `expenses_count`, `cash_balance`.
- [ ] `id` = `condominium_id` de la ruta (int); `name` = title del nodo `condominio`.
- [ ] `total_expenses` = suma (`float`) de `field_valor` de los gastos `type='gastos'`, `status=1`, `field_condominio = condominium_id` y `field_estado_gasto = 'Activo'`; es `0.0` cuando no hay ningún gasto en ese conjunto.
- [ ] `expenses_count` = cantidad (`int`) de gastos de ese mismo conjunto; es `0` cuando el conjunto está vacío.
- [ ] Un gasto sin fila en `field_valor` cuenta en `expenses_count` pero aporta `0` a `total_expenses`.
- [ ] Gastos en un estado distinto de `Activo` o sin fila de estado no entran ni en la suma ni en el conteo.
- [ ] `cash_balance` = `field_saldo_caja_value` del nodo condominio (`float`), tal cual (incluido signo); es `null` cuando el condominio no tiene fila en `field_data_field_saldo_caja`.
- [ ] `date_from`/`date_to` acotan el agregado sobre `expense_date` (primeros 10 caracteres) de forma inclusiva; `total_expenses` y `expenses_count` reflejan el conjunto ya filtrado.
- [ ] `date_from`/`date_to` con formato inválido, o rango invertido (`from > to`), se ignoran sin `422`; con filtro activo, gastos sin fila de fecha quedan excluidos del agregado.
- [ ] `condominium_id` inexistente o ajeno → `403 condominium_access_denied`, sin distinguir el motivo; un `condominium_id` no numérico también cae en `403`.
- [ ] Sin header `Authorization` → `401 missing_authorization`; token inválido/expirado/revocado → `401 invalid_token`.
- [ ] Cualquier método distinto de `GET` → `405 method_not_allowed`.
- [ ] `GET /api/v1/condominiums/%/expenses` (spec 16) sigue respondiendo idéntico tras el refactor del helper de fechas.
- [ ] `docs/condominium.md` documenta el endpoint completo (auth, query params, campos de respuesta, errores).
- [ ] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Ruta y recurso | `GET /api/v1/condominiums/%/summary` en un nuevo `condominium.resource.inc` | `GET /api/v1/condominiums/%` (item), o meterlo dentro de `expense.resource.inc` | El dato es de nivel condominio (nombre + saldo + agregado), no un sub-listado de gastos; `summary` deja libre `/%` para un futuro detalle. |
| Significado de "total de gastos" | `SUM(field_valor)` de gastos en estado `Activo` | Conteo de gastos, o suma de todos los estados | Pedido explícito del usuario; mismo criterio de estado único que spec 16. |
| Conteo de gastos sumados | Se incluye `expenses_count` junto al total | Devolver solo el monto | Pedido explícito del usuario. |
| Casos vacíos | `total_expenses=0.0` / `expenses_count=0`; `cash_balance=null` sin fila | `total_expenses=null` cuando no hay gastos | Pedido explícito del usuario; un total monetario siempre es numérico, mientras que "sin saldo registrado" sí es ausencia de dato (mismo criterio que `current_balance` del spec 10). |
| Filtro de fechas | `date_from`/`date_to` opcional, comportamiento laxo idéntico a spec 16 | Total absoluto sin filtro | Pedido explícito del usuario. |
| Parseo de rango de fechas | Extraer helper genérico a `includes/myapi.request.inc` y compartirlo entre `expense` y `condominium` | Duplicar las funciones en el nuevo recurso | CLAUDE.md prohíbe duplicar lógica entre recursos y que un recurso llame funciones internas de otro; el lugar correcto es `includes/`. |
| Constante de estado | `define('MYAPI_CONDOMINIUM_EXPENSE_STATUS', 'Activo')` propia del recurso | Reutilizar `MYAPI_EXPENSE_EXPOSED_STATUS` de `expense.resource.inc` | Mantiene los recursos aislados (un recurso no depende de un `define` de otro); el valor coincide pero la dependencia quedaría acoplada. |
| Fuente de `name` | `title` del nodo `condominio` | Un campo de texto aparte | El nombre del condominio es el título del nodo, consistente con cómo `unit.resource.inc` agrupa por `condominio`. |
| Semántica de acceso denegado | `403 condominium_access_denied` uniforme, exista o no el condominio | Distinguir `404` de `403` | No revela si un `condominium_id` ajeno existe; mismo criterio que spec 16. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Refactor del helper de fechas rompe `expenses` (spec 16).** Al mover `myapi_expense_parse_date_range()`/`myapi_expense_valid_date()` a `includes/`, un cambio de firma o de comportamiento alteraría silenciosamente el filtro del endpoint de gastos. | El helper es copia exacta del comportamiento actual; criterio de aceptación explícito de re-probar `GET /api/v1/condominiums/%/expenses` tras el refactor. |
| **Campos compartidos entre content types.** `field_condominio`, `field_valor` y `field_categoria` los usan varios bundles; `field_saldo_caja` es del bundle `condominio`. Sin filtro por tipo, la query traería filas ajenas. | El agregado filtra por `n.type='gastos'`; `myapi_condominium_name()` filtra por `n.type='condominio'`; `field_saldo_caja` se lee por `entity_id = condominium_id` + `entity_type='node'`. |
| **`total_expenses` y `expenses_count` no cuadran 1:1.** Un gasto sin fila en `field_valor` cuenta en el conteo pero suma `0`; el cliente podría esperar `total/count = promedio` y equivocarse. | Documentado en el modelo de datos y en `docs/condominium.md`; el `left join` + `SUM` que ignora `NULL` es intencional. |
| **Nodo `condominio` no publicado o inexistente.** El acceso se concede por las unidades del usuario, no por el estado del nodo condominio; `name` podría venir de un nodo despublicado, o `NULL` si el nodo fue borrado. | Aceptado: mismo criterio que spec 16 (no se agrega verificación extra del nodo condominio). `name=NULL` es tolerable; si aparece un caso real, se corrige en un spec aparte. |
| **Lectura directa de tablas de Field API.** Un cambio de schema en `field_valor`, `field_saldo_caja`, `field_estado_gasto` o `field_fecha_de_gasto` (rename, tipo, multi-value) rompe el agregado sin aviso de Drupal. | Documentado en la tabla de mapeo del spec y en `docs/condominium.md`; mismo criterio ya aceptado en specs 10 y 16. |
| **Precisión de `decimal(10,2)` al exponerse como `float`.** `SUM` y el saldo pueden introducir imprecisión de punto flotante en casos extremos. | Sin acción adicional; parte del contrato ya aceptado en specs 10/16. |
| **`condominium_id` como wildcard `%` sin `load function`.** Drupal no valida que el segmento sea numérico antes de invocar el dispatcher. | Un valor no numérico se castea a `(int) 0`, nunca coincide con un nid real y cae en `403 condominium_access_denied`. |
