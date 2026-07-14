# 18 — Listado de bancos (términos del vocabulario `bancos`)

- **Estado:** Implemented
- **Fecha:** 2026-07-09
- **Dependencias:**
  - `01-bootstrap-modulo` (Implemented) — esqueleto del módulo, `hook_menu()`, envelope de respuesta y helpers `myapi_respond()`/`myapi_error()`.
  - `05-middleware-access-token-logout` (Implemented) — helper `myapi_auth_require_access_token()` e includes `myapi.token`/`myapi.auth` que validan el Bearer access token y devuelven la fila del token (o cortan con `401`).
  - `resources/condominium.resource.inc` (referencia) — patrón del recurso autenticado de solo lectura: dispatcher que solo acepta `GET` y handler que exige el access token con `myapi_auth_require_access_token()` antes de responder.
- **Objetivo:** Agregar `GET /api/v1/banks`, un endpoint **autenticado** de solo lectura que expone los términos del vocabulario de taxonomía `bancos` (machine name `bancos`) devolviendo por cada término su `id` (int), `name` y `description`, ambos saneados con `check_plain()`. El resultado viene **ordenado por `id`**, ascendente por defecto, con un parámetro opcional `sort` (`asc`/`desc`) para invertir el orden. Requiere un Bearer access token válido de cualquier usuario autenticado (no expone datos de sistema de forma pública).

---

## Alcance

### Dentro de este spec

- **`resources/bank.resource.inc`** (nuevo) — esqueleto de `module_load_include()` de request, response e i18n **más** `myapi.token` y `myapi.auth` (como `condominium.resource.inc`). Contiene:
  - `myapi_bank_dispatch()` — solo `GET`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
  - `myapi_bank_list()` — exige el access token con `myapi_auth_require_access_token()` (corta con `401` si falta o es inválido); lee el parámetro opcional `sort` (`asc`/`desc`, default `asc`, mismo patrón que `payment`/`expense`); luego carga el vocabulario con `taxonomy_vocabulary_machine_name_load('bancos')`, obtiene los términos con `taxonomy_get_tree()`, construye la lista, **la ordena por `id`** según `sort` y responde con `myapi_respond(['banks' => $banks], 200)`.
  - `myapi_bank_build_item($term)` — mapea un término a `['id' => (int), 'name' => check_plain(...), 'description' => check_plain(...)]`.
- **`myapi.module`** (modificar) — registrar `GET /api/v1/banks` en `hook_menu()` con `access callback => TRUE` y `file => resources/bank.resource.inc`. La autenticación se resuelve dentro del recurso con `myapi_auth_require_access_token()` (mismo patrón que el resto de recursos autenticados), no en el `access callback`.
- **`myapi.info`** (modificar) — agregar `files[] = resources/bank.resource.inc`.
- **`docs/bank.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Detalle de un banco** (`GET /api/v1/banks/%`) — solo se expone la colección completa.
- **Crear / editar / borrar términos** del vocabulario `bancos` — endpoint de solo lectura.
- **Otros campos del término** más allá de `id`, `name` y `description` (weight, jerarquía padre/hijo, campos de Field API adjuntos al término) — no se exponen.
- **Permisos por rol o por unidad** — cualquier usuario con un access token válido puede listar los bancos; no se filtra por rol, condominio ni unidad. La única barrera es tener un token válido (autenticación), no autorización granular.
- **Paginación y filtros** — se devuelve el árbol completo, sin `page`/`limit` ni filtros por nombre u otros campos. (El ordenamiento por `id` con `sort` **sí** está dentro de alcance; ver sección "Parámetros de consulta".)
- **Ordenar por otros campos** (`name`, `description`, `weight`) — el único criterio de orden es `id`; no se expone ordenamiento por otras claves.
- **Interpretar `description` como HTML enriquecido** — se sanea con `check_plain()` (texto plano escapado); no se aplica `filter_xss()` ni el text format del término.
- **Traducción de `name`/`description`** (i18n de términos, `entity_translation`, `i18n_taxonomy`) — se devuelve el valor tal cual está almacenado en el término.

---

## Parámetros de consulta

| Parámetro | Valores | Default | Regla |
|---|---|---|---|
| `sort` | `asc` \| `desc` | `asc` | Orden por `id` (el `tid` del término). `asc` = menor a mayor; `desc` = mayor a menor. Cualquier otro valor (ausente, vacío, `ASC` en mayúsculas, `weight`, etc.) se ignora silenciosamente y se usa `asc` — sin `422`, mismo criterio laxo que `page`/`limit`/`sort` en `payment`/`expense`. |

El orden se aplica **siempre sobre `id`**, no sobre el orden natural de `taxonomy_get_tree()` (peso y luego nombre): tras construir la lista se reordena por `id` según `sort`.

---

## Modelo de datos

Este spec **no introduce estructuras nuevas**: lee un vocabulario de taxonomía ya existente en Drupal (`bancos`) mediante la API de taxonomía, sin tocar tablas propias del módulo (`myapi_*`). No hay `hook_schema()` ni cambios en `myapi.install`.

### Fuente de datos

| Origen | Cómo se accede | Uso |
|---|---|---|
| Vocabulario `bancos` | `taxonomy_vocabulary_machine_name_load('bancos')` → objeto vocabulario (o `FALSE` si no existe) | Obtener el `vid` para pedir su árbol de términos. |
| Términos del vocabulario | `taxonomy_get_tree($vocabulary->vid)` → array de objetos término | Cada objeto expone `tid`, `name`, `description` (entre otros). |

### Mapeo → claves JSON

Cada término del árbol se transforma con `myapi_bank_build_item()`:

| Fuente (objeto término) | Clave JSON | Tipo | Saneo / regla |
|---|---|---|---|
| `$term->tid` | `id` | int | `(int)` — nunca `NULL`. |
| `$term->name` | `name` | string | `check_plain($term->name)`. |
| `$term->description` | `description` | string | `check_plain($term->description)`; si es `NULL`/vacía → `""` (string vacío). |

### Caso degradado

- `taxonomy_vocabulary_machine_name_load('bancos')` devuelve `FALSE` (vocabulario inexistente) → se responde `200` con `{ "banks": [] }`, sin error.
- Vocabulario existe pero sin términos → `200` con `{ "banks": [] }`.

### Forma de respuesta

```json
{
  "success": true,
  "data": {
    "banks": [
      { "id": 3, "name": "Banco Pichincha", "description": "Cuenta corriente 2100xxxxxx" },
      { "id": 5, "name": "Produbanco", "description": "" }
    ]
  }
}
```

---

## Plan de implementación

1. **Crear `resources/bank.resource.inc`** con la cabecera `@file` y los `module_load_include()` de request, response, i18n, `myapi.token` y `myapi.auth` (mismo bloque que `condominium.resource.inc`).

2. **`myapi_bank_dispatch()`** — obtiene el método con `myapi_request_method()`; si es `GET` llama a `myapi_bank_list()`, en cualquier otro caso `myapi_error('method_not_allowed', 405)`.

3. **`myapi_bank_list()`:**
   - `myapi_auth_require_access_token();` — exige un Bearer access token válido; si falta o es inválido/expirado, el helper corta con `401` (`missing_authorization` / `invalid_token`) y no se ejecuta nada más. El recurso no usa el `uid` devuelto (el catálogo no depende del usuario).
   - Resolver el orden: `$sort = (isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)) ? $_GET['sort'] : 'asc';` (mismo patrón laxo que `payment`/`expense`).
   - `$vocabulary = taxonomy_vocabulary_machine_name_load('bancos');`
   - Si `$vocabulary === FALSE` → `myapi_respond(['banks' => []], 200);` y termina.
   - `$tree = taxonomy_get_tree($vocabulary->vid);`
   - `$banks = array_map('myapi_bank_build_item', $tree);` (array vacío si no hay términos).
   - Ordenar `$banks` por `id` según `$sort` con `usort()`:
     ```php
     usort($banks, function ($a, $b) use ($sort) {
       return $sort === 'desc' ? ($b['id'] - $a['id']) : ($a['id'] - $b['id']);
     });
     ```
   - `myapi_respond(['banks' => $banks], 200);`

4. **`myapi_bank_build_item($term)`** — devuelve:
   ```php
   [
     'id'          => (int) $term->tid,
     'name'        => check_plain($term->name),
     'description' => check_plain($term->description),
   ]
   ```
   `check_plain(NULL)` / `check_plain('')` devuelven `""`, cubriendo la descripción vacía sin lógica extra.

5. **Registrar la ruta en `myapi.module`** dentro de `hook_menu()`:
   ```php
   $items['api/v1/banks'] = [
     'page callback'   => 'myapi_bank_dispatch',
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/bank.resource.inc',
   ];
   ```

6. **Agregar a `myapi.info`:** `files[] = resources/bank.resource.inc`.

7. **Crear `docs/bank.md`** siguiendo la plantilla: descripción, auth (**requerida** — Bearer access token), método, forma de respuesta (tabla de campos `id`/`name`/`description`), tabla de errores (`401` sin token o con token inválido, `405` método no permitido), y nota de que el vocabulario ausente o vacío devuelve `banks: []`.

8. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación.

---

## Criterios de aceptación

- [x] `GET /api/v1/banks` con un Bearer access token válido devuelve `200` con `{ "success": true, "data": { "banks": [...] } }`.
- [x] `GET /api/v1/banks` sin header `Authorization` devuelve `401 missing_authorization`.
- [x] `GET /api/v1/banks` con un token inválido o expirado devuelve `401 invalid_token`.
- [x] Cada elemento de `banks` tiene exactamente 3 claves: `id`, `name`, `description`.
- [x] `id` es el `tid` del término como entero.
- [x] `name` es el nombre del término saneado con `check_plain()`.
- [x] `description` es la descripción del término saneada con `check_plain()`; un término sin descripción devuelve `""` (string vacío), nunca `null`.
- [x] Sin `sort` (o con un valor inválido), los bancos vienen ordenados por `id` ascendente (menor a mayor).
- [x] Con `?sort=asc` los bancos vienen ordenados por `id` ascendente.
- [x] Con `?sort=desc` los bancos vienen ordenados por `id` descendente (mayor a menor).
- [x] Si el vocabulario `bancos` no existe, la respuesta es `200` con `banks: []` (sin error `500`).
- [x] Si el vocabulario existe pero no tiene términos, la respuesta es `200` con `banks: []`.
- [x] Cualquier método distinto de `GET` (`POST`, `PUT`, `DELETE`) devuelve `405 method_not_allowed`.
- [x] `docs/bank.md` documenta el endpoint completo (método, auth requerida, campos de respuesta, errores `401`/`405`, caso de vocabulario ausente/vacío).
- [x] `myapi.info` incluye `files[] = resources/bank.resource.inc` y `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Clave del identificador | `id` | `tid` | Consistencia con el resto de la API, donde el identificador de recurso se expone como `id` (p. ej. `condominiums`). |
| Saneo de `description` | `check_plain()` (texto plano escapado) | `filter_xss()` (HTML seguro) / `check_markup()` (text format del término) | La app consume la descripción como texto plano; `check_plain()` es el criterio de saneo ya usado en el módulo y evita depender del text format configurado en Drupal. |
| Saneo de `name` | También con `check_plain()` | Devolver `name` crudo | Aunque el pedido solo mencionaba sanear `description`, el nombre viene de la misma fuente no confiable (Drupal EOL); sanearlo es coherente y sin costo. |
| Descripción vacía | `""` (string vacío) | `null` | `check_plain('')` ya devuelve `""` sin lógica extra; el cliente siempre recibe un string, simplificando el consumo. |
| Estructura de `data` | `{ "banks": [...] }` | `data` = array directo | Consistencia con las colecciones existentes (`properties` en units, `expenses` en gastos). |
| Vocabulario ausente | `200` con `banks: []` | `500 server_error` | Es un endpoint de catálogo; el degradado silencioso es más amable con el cliente y evita filtrar detalles de configuración. |
| Ordenamiento | Por `id` con `sort` (`asc`/`desc`), default `asc` | Orden natural de `taxonomy_get_tree()` (peso, luego nombre) / ordenar por `name` | Pedido explícito del usuario: orden estable por `id` y control asc/desc. Reutiliza la convención `sort` ya usada en `payment`/`expense` (mismo nombre de parámetro, valores y criterio laxo ante valores inválidos). |
| Nombre/valores del parámetro de orden | `sort` = `asc`\|`desc` | `order`/`direction`, o `+id`/`-id` | Consistencia con los recursos existentes que ya exponen `sort` con esos mismos valores. |
| Implementación | `taxonomy_vocabulary_machine_name_load()` + `taxonomy_get_tree()` | Consulta directa a `taxonomy_term_data` / `taxonomy_index` | Pedido explícito del usuario; usa la API de taxonomía de Drupal 7 en lugar de leer tablas crudas. |
| Autenticación | Requerir access token (`myapi_auth_require_access_token()` dentro del recurso) | Pública (`access callback => TRUE` sin validar token) | Pedido explícito del usuario: no exponer datos de sistema de forma pública. El catálogo se sirve solo a usuarios autenticados; consistente con el resto de recursos del módulo. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Exposición de datos de sistema.** Las descripciones de los bancos pueden incluir números de cuenta u otra información sensible. | Mitigado: el endpoint exige un Bearer access token válido; solo usuarios autenticados pueden listar los bancos. No se expone de forma pública. |
| **`taxonomy_get_tree()` en vocabularios muy grandes.** Devuelve el árbol completo sin paginación, cargando todos los términos en memoria. | Aceptado: un catálogo de bancos es una lista corta y estable; la paginación queda explícitamente fuera de alcance. |
| **`description` como texto plano cuando la app espera HTML.** `check_plain()` escapa cualquier markup; si un término trae HTML intencional, la app lo verá escapado. | Decisión consciente (ver sección de decisiones); si se necesita HTML enriquecido, se cambia el saneo en un spec futuro sin romper el contrato de la clave. |
| **Dependencia del machine name `bancos`.** Si el vocabulario se renombra o se borra, el endpoint deja de devolver datos. | El caso ausente está cubierto: `200` con `banks: []` en vez de error; documentado en `docs/bank.md`. |
