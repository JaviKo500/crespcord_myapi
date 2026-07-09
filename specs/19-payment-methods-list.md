# 19 — Listado de métodos de pago (términos del vocabulario `metodos_pago`)

- **Estado:** Implemented
- **Fecha:** 2026-07-09
- **Dependencias:**
  - `01-bootstrap-modulo` (Implemented) — esqueleto del módulo, `hook_menu()`, envelope de respuesta y helpers `myapi_respond()`/`myapi_error()`.
  - `05-middleware-access-token-logout` (Implemented) — helper `myapi_auth_require_access_token()` e includes `myapi.token`/`myapi.auth` que validan el Bearer access token.
  - `18-banks-list` (Implemented) — recurso hermano de referencia: mismo patrón de catálogo de taxonomía autenticado, de solo lectura, con `sort` por `id`. Este recurso lo replica agregando un campo de Field API (`field_tipo_pago`) hidratado con `entity_load()`.
- **Objetivo:** Agregar `GET /api/v1/payment-methods`, un endpoint **autenticado** de solo lectura que expone los términos del vocabulario de taxonomía `metodos_pago` devolviendo por cada término `id` (int), `name` (string), `type_method` (string, desde `field_tipo_pago`) y `description` (string) —los tres strings saneados con `check_plain()`—, ordenados por `id` ascendente por defecto, con parámetro opcional `sort` (`asc`/`desc`). Los términos **sin valor** en `field_tipo_pago` se **excluyen** de la colección, porque `type_method` es la clave que la app usará para registrar el método en un pago y un método sin ese valor no es utilizable.

---

## Alcance

### Dentro de este spec

- **`resources/payment_method.resource.inc`** (nuevo) — mismo bloque de `module_load_include()` que `bank.resource.inc` (request, response, i18n, `myapi.token`, `myapi.auth`). Contiene:
  - `myapi_payment_method_dispatch()` — solo `GET`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
  - `myapi_payment_method_list()` — exige el access token con `myapi_auth_require_access_token()` (corta con `401`); lee el parámetro opcional `sort` (`asc`/`desc`, default `asc`); carga el vocabulario con `taxonomy_vocabulary_machine_name_load('metodos_pago')`, obtiene los `tid` con `taxonomy_get_tree()`, **hidrata los términos con `entity_load('taxonomy_term', $tids)`** para acceder a `field_tipo_pago`, construye la lista **descartando los términos sin `type_method`**, la ordena por `id` según `sort` y responde con `myapi_respond(['payment_methods' => $items], 200)`.
  - `myapi_payment_method_build_item($term)` — mapea un término a `['id' => (int), 'name' => check_plain(...), 'type_method' => check_plain(...), 'description' => check_plain(...)]`, o devuelve `NULL` si el término no tiene valor en `field_tipo_pago` (para filtrarlo).
- **`myapi.module`** (modificar) — registrar `GET /api/v1/payment-methods` en `hook_menu()` con `access callback => TRUE` y `file => resources/payment_method.resource.inc`. La autenticación se resuelve dentro del recurso.
- **`myapi.info`** (modificar) — agregar `files[] = resources/payment_method.resource.inc`.
- **`docs/payment_method.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **El endpoint de bancos** (`GET /api/v1/banks`, spec 18, vocabulario `bancos`) — no se toca; este recurso es independiente.
- **Detalle de un método de pago** (`GET /api/v1/payment-methods/%`) — solo se expone la colección completa.
- **Crear / editar / borrar términos** del vocabulario `metodos_pago` — solo lectura.
- **Otros campos del término** más allá de `id`, `name`, `type_method` y `description` (weight, jerarquía padre/hijo, otros campos de Field API) — no se exponen.
- **Permisos por rol o por unidad** — cualquier usuario con un access token válido puede listar; no se filtra por rol, condominio ni unidad. La única barrera es tener un token válido.
- **Paginación y filtros** — se devuelve el árbol completo, sin `page`/`limit` ni filtros por nombre/tipo. (El orden por `id` con `sort` **sí** está dentro de alcance.)
- **Ordenar por otros campos** (`name`, `type_method`, `description`, `weight`) — el único criterio de orden es `id`.
- **Interpretar `description`/`type_method` como HTML enriquecido** — se sanean con `check_plain()` (texto plano escapado); no se aplica `filter_xss()` ni el text format del término.
- **Traducción de los campos** (i18n de términos, `entity_translation`, `i18n_taxonomy`) — se devuelve el valor tal cual está almacenado.

---

## Modelo de datos

Este spec **no introduce estructuras nuevas**: lee un vocabulario de taxonomía ya existente en Drupal (`metodos_pago`) mediante la API de taxonomía, sin tocar tablas propias del módulo (`myapi_*`). No hay `hook_schema()` ni cambios en `myapi.install`.

### Fuente de datos

| Origen | Cómo se accede | Uso |
|---|---|---|
| Vocabulario `metodos_pago` | `taxonomy_vocabulary_machine_name_load('metodos_pago')` → objeto vocabulario (o `FALSE` si no existe) | Obtener el `vid` para pedir su árbol de términos. |
| Términos (lista + orden) | `taxonomy_get_tree($vocabulary->vid)` → array de objetos término ligeros (`tid`, `name`, `description`, `weight`…) | Fuente de los `tid` y de `name`/`description`. **No** trae `field_tipo_pago`. |
| Términos hidratados (campos) | `entity_load('taxonomy_term', $tids)` → términos completos con Field API | Acceder a `field_tipo_pago` (el campo `tipo`). Una sola query batch para todos los `tid`. |

### Mapeo → claves JSON

Cada término se transforma con `myapi_payment_method_build_item()`:

| Fuente (objeto término hidratado) | Clave JSON | Tipo | Saneo / regla |
|---|---|---|---|
| `$term->tid` | `id` | int | `(int)` — nunca `NULL`. |
| `$term->name` | `name` | string | `check_plain($term->name)`. |
| `$term->field_tipo_pago[LANGUAGE_NONE][0]['value']` | `type_method` | string | `check_plain(...)`; **si el campo está ausente/vacío el término se excluye** de la colección (nunca se devuelve un `type_method` vacío). |
| `$term->description` | `description` | string | `check_plain($term->description)`; si es `NULL`/vacía → `""`. |

Acceso seguro al campo: se comprueba `isset($term->field_tipo_pago[LANGUAGE_NONE][0]['value'])` y que su valor con `trim()` no sea `''` antes de mapear el término; si no cumple, el término **no entra** en la respuesta.

### Caso degradado

- `taxonomy_vocabulary_machine_name_load('metodos_pago')` devuelve `FALSE` (vocabulario inexistente) → `200` con `{ "payment_methods": [] }`, sin error.
- Vocabulario existe pero sin términos → `200` con `{ "payment_methods": [] }`.
- Término sin valor en `field_tipo_pago` (ausente, vacío o solo espacios) → **se excluye** de la colección; no aparece en la respuesta.
- Si ningún término tiene `field_tipo_pago` → `200` con `{ "payment_methods": [] }`.

### Forma de respuesta

```json
{
  "success": true,
  "data": {
    "payment_methods": [
      { "id": 4, "name": "Transferencia", "type_method": "Bancaria", "description": "Cuenta corriente 2100xxxxxx" },
      { "id": 7, "name": "Efectivo", "type_method": "cash", "description": "" }
    ]
  }
}
```

---

## Plan de implementación

1. **Crear `resources/payment_method.resource.inc`** con la cabecera `@file` y los `module_load_include()` de request, response, i18n, `myapi.token` y `myapi.auth` (mismo bloque que `bank.resource.inc`).

2. **`myapi_payment_method_dispatch()`** — obtiene el método con `myapi_request_method()`; si es `GET` llama a `myapi_payment_method_list()`, en cualquier otro caso `myapi_error('method_not_allowed', 405)`.

3. **`myapi_payment_method_list()`:**
   - `myapi_auth_require_access_token();` — exige Bearer access token válido; si falta o es inválido/expirado corta con `401` (`missing_authorization` / `invalid_token`). No se usa el `uid` (el catálogo no depende del usuario).
   - Resolver el orden: `$sort = (isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)) ? $_GET['sort'] : 'asc';`
   - `$vocabulary = taxonomy_vocabulary_machine_name_load('metodos_pago');`
   - Si `$vocabulary === FALSE` → `myapi_respond(['payment_methods' => []], 200);` y termina.
   - `$tree = taxonomy_get_tree($vocabulary->vid);`
   - Si `$tree` está vacío → `myapi_respond(['payment_methods' => []], 200);` y termina.
   - Extraer los `tid`: `$tids = array_map(function ($t) { return $t->tid; }, $tree);`
   - Hidratar: `$terms = entity_load('taxonomy_term', $tids);` (array keyed por `tid`, con `field_tipo_pago`).
   - `$items = array_map('myapi_payment_method_build_item', $terms);` → construir; luego descartar los términos sin `type_method`: `$items = array_values(array_filter($items));` (`build_item` devuelve `NULL` para esos casos y `array_filter` los elimina).
   - Ordenar `$items` por `id` según `$sort` con `usort()`:
     ```php
     usort($items, function ($a, $b) use ($sort) {
       return $sort === 'desc' ? ($b['id'] - $a['id']) : ($a['id'] - $b['id']);
     });
     ```
   - `myapi_respond(['payment_methods' => $items], 200);`

4. **`myapi_payment_method_build_item($term)`** — filtra y devuelve:
   ```php
   $type = isset($term->field_tipo_pago[LANGUAGE_NONE][0]['value'])
     ? $term->field_tipo_pago[LANGUAGE_NONE][0]['value']
     : '';
   // Sin type_method el método no es utilizable en un pago: se excluye.
   if (trim($type) === '') {
     return NULL;
   }
   return [
     'id'          => (int) $term->tid,
     'name'        => check_plain($term->name),
     'type_method' => check_plain($type),
     'description' => check_plain($term->description),
   ];
   ```
   `check_plain(NULL)` / `check_plain('')` devuelven `""`, cubriendo la `description` vacía sin lógica extra. `type_method` nunca es vacío porque el término se descartó antes.

5. **Registrar la ruta en `myapi.module`** dentro de `hook_menu()`:
   ```php
   $items['api/v1/payment-methods'] = [
     'page callback'   => 'myapi_payment_method_dispatch',
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/payment_method.resource.inc',
   ];
   ```

6. **Agregar a `myapi.info`:** `files[] = resources/payment_method.resource.inc`.

7. **Crear `docs/payment_method.md`** siguiendo la plantilla: descripción, auth (**requerida** — Bearer access token), método, parámetro `sort`, tabla de campos (`id`/`name`/`type_method`/`description`), tabla de errores (`401` sin token o con token inválido, `405` método no permitido), y nota de que el vocabulario ausente/vacío devuelve `payment_methods: []` y de que **los términos sin `type_method` se excluyen** de la colección.

8. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación.

---

## Criterios de aceptación

- [x] `GET /api/v1/payment-methods` con un Bearer access token válido devuelve `200` con `{ "success": true, "data": { "payment_methods": [...] } }`.
- [x] `GET /api/v1/payment-methods` sin header `Authorization` devuelve `401 missing_authorization`.
- [x] `GET /api/v1/payment-methods` con un token inválido o expirado devuelve `401 invalid_token`.
- [x] Cada elemento de `payment_methods` tiene exactamente 4 claves: `id`, `name`, `type_method`, `description`.
- [x] `id` es el `tid` del término como entero.
- [x] `name` es el nombre del término saneado con `check_plain()`.
- [x] `type_method` es el valor de `field_tipo_pago` saneado con `check_plain()`; nunca es vacío (los términos sin ese valor no se devuelven).
- [x] Un término sin valor en `field_tipo_pago` (ausente, vacío o solo espacios) **no aparece** en `payment_methods`.
- [x] Si ningún término tiene `field_tipo_pago`, la respuesta es `200` con `payment_methods: []`.
- [x] `description` es la descripción del término saneada con `check_plain()`; un término sin descripción devuelve `""` (string vacío), nunca `null`.
- [x] Sin `sort` (o con un valor inválido: ausente, vacío, `ASC`, `weight`, etc.) los métodos vienen ordenados por `id` ascendente, sin `422`.
- [x] Con `?sort=asc` vienen ordenados por `id` ascendente.
- [x] Con `?sort=desc` vienen ordenados por `id` descendente.
- [x] Si el vocabulario `metodos_pago` no existe, la respuesta es `200` con `payment_methods: []` (sin error `500`).
- [x] Si el vocabulario existe pero no tiene términos, la respuesta es `200` con `payment_methods: []`.
- [x] Cualquier método distinto de `GET` (`POST`, `PUT`, `DELETE`) devuelve `405 method_not_allowed`.
- [x] El endpoint `GET /api/v1/banks` (spec 18) sigue respondiendo idéntico; este recurso no lo modifica.
- [x] `docs/payment_method.md` documenta el endpoint completo (método, auth requerida, `sort`, campos de respuesta, errores `401`/`405`, caso de vocabulario ausente/vacío y `type_method` vacío).
- [x] `myapi.info` incluye `files[] = resources/payment_method.resource.inc` y `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Ruta y recurso | Nuevo `payment_method.resource.inc` en `GET /api/v1/payment-methods` | Reutilizar/repuntar `GET /api/v1/banks` (spec 18) | `banks` ya está implementado para el vocabulario `bancos`; `metodos_pago` son métodos de pago (recurso distinto). Ruta propia evita colisión y mantiene el contrato de banks intacto. |
| Obtención del campo `tipo` | `taxonomy_get_tree()` (lista/orden) + `entity_load('taxonomy_term', $tids)` (campos) | Solo `taxonomy_get_tree()` / `taxonomy_term_load()` por término / leer `field_data_field_tipo_pago` cruda | `taxonomy_get_tree()` no devuelve campos de Field API; `entity_load()` los hidrata en **una sola query batch** (mejor que N cargas) y usa la API de taxonomía en vez de leer tablas crudas. |
| Machine name del campo | `field_tipo_pago` → clave `type_method` | `field_tipo` / `tipo` crudo | Machine name confirmado por el usuario; se expone en inglés (`type_method`) por la convención de claves JSON del proyecto. |
| Saneo | `check_plain()` en `name`, `type_method` y `description` | Sanear solo `description` / devolver crudo | Los tres vienen de la misma fuente no confiable (Drupal EOL); sanear todo es coherente con spec 18 y sin costo. |
| Término sin `type_method` | **Excluir** el término de la colección | Devolver `type_method: ""` / `null` | `type_method` es la clave que la app usará para registrar el método en un pago; un método sin ese valor no es utilizable, así que no debe ofrecerse como opción. |
| Valor vacío de `description` | `""` (string vacío) | `null` | `check_plain('')` ya devuelve `""`; el cliente siempre recibe un string, simplificando el consumo. (`type_method` nunca es vacío por la exclusión anterior.) |
| Clave del identificador | `id` | `tid` | Consistencia con el resto de la API (`banks`, `condominiums`, etc.). |
| Estructura de `data` | `{ "payment_methods": [...] }` | `data` = array directo | Consistencia con las colecciones existentes (`banks`, `properties`, `expenses`). |
| Vocabulario ausente | `200` con `payment_methods: []` | `500 server_error` | Endpoint de catálogo; el degradado silencioso es más amable con el cliente y no filtra detalles de configuración. Mismo criterio que spec 18. |
| Ordenamiento | Por `id` con `sort` (`asc`/`desc`), default `asc`, criterio laxo | Orden natural de `taxonomy_get_tree()` (peso, luego nombre) / ordenar por otros campos | Pedido explícito del usuario; reutiliza la convención `sort` de `banks`/`payment`/`expense` (mismo nombre, valores y tolerancia a valores inválidos). |
| Autenticación | Requerir access token dentro del recurso (`myapi_auth_require_access_token()`) | Pública (`access callback => TRUE` sin validar token) | No exponer datos de sistema de forma pública; consistente con `banks` y el resto de recursos. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`field_tipo_pago` no es un campo de valor simple.** Si `tipo` fuera una lista (`list_text`), referencia a término (`entityreference`/`taxonomy_term_reference`) u otro widget, la columna no sería `_value` y **todos** los términos se leerían como vacíos → al excluir los vacíos la colección saldría siempre `[]`. | Acceso con `isset(...[LANGUAGE_NONE][0]['value'])` sin warning. Señal de diagnóstico clara: si `payment_methods` sale `[]` con términos existentes, la columna del campo es otra; se ajusta en un fix puntual sin romper el contrato JSON. |
| **`entity_load()` sobre vocabularios grandes.** Carga todos los términos con sus campos en memoria, sin paginación. | Aceptado: un catálogo de métodos de pago es una lista corta y estable; la paginación queda explícitamente fuera de alcance. |
| **`taxonomy_get_tree()` devuelve `tid` pero `entity_load()` reordena por clave.** El array hidratado viene keyed por `tid`, no en el orden del árbol. | Irrelevante: el orden final se impone siempre con `usort()` por `id` según `sort`; no se depende del orden natural de ninguna de las dos llamadas. |
| **Exposición de datos de sistema.** Las descripciones/tipos pueden incluir números de cuenta u otra información sensible. | El endpoint exige un Bearer access token válido; solo usuarios autenticados pueden listar. No se expone de forma pública. |
| **`description`/`type_method` como texto plano cuando la app espera HTML.** `check_plain()` escapa cualquier markup. | Decisión consciente (ver decisiones); si se necesita HTML enriquecido, se cambia el saneo en un spec futuro sin romper el contrato de la clave. |
| **Dependencia del machine name `metodos_pago` y del campo `field_tipo_pago`.** Si el vocabulario o el campo se renombran/borran, el endpoint deja de devolver datos (o `type_method` queda vacío). | El vocabulario ausente devuelve `200` con `payment_methods: []` (documentado); el campo ausente devuelve `type_method: ""`. Ambos casos degradan sin `500`. |
