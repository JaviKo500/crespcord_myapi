# 18 — Listado de bancos (términos del vocabulario `bancos`)

- **Estado:** Approved
- **Fecha:** 2026-07-09
- **Dependencias:**
  - `01-bootstrap-modulo` (Implemented) — esqueleto del módulo, `hook_menu()`, envelope de respuesta y helpers `myapi_respond()`/`myapi_error()`.
  - `resources/ping.resource.inc` (referencia) — patrón del recurso público más simple: dispatcher que solo acepta `GET` y responde sin autenticación.
- **Objetivo:** Agregar `GET /api/v1/banks`, un endpoint público de solo lectura que expone los términos del vocabulario de taxonomía `bancos` (machine name `bancos`) devolviendo por cada término su `id` (int), `name` y `description`, ambos saneados con `check_plain()`.

---

## Alcance

### Dentro de este spec

- **`resources/bank.resource.inc`** (nuevo) — mismo esqueleto de `module_load_include()` que `ping.resource.inc` (request, response, i18n). Contiene:
  - `myapi_bank_dispatch()` — solo `GET`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
  - `myapi_bank_list()` — carga el vocabulario con `taxonomy_vocabulary_machine_name_load('bancos')`, obtiene los términos con `taxonomy_get_tree()`, construye la lista y responde con `myapi_respond(['banks' => $banks], 200)`.
  - `myapi_bank_build_item($term)` — mapea un término a `['id' => (int), 'name' => check_plain(...), 'description' => check_plain(...)]`.
- **`myapi.module`** (modificar) — registrar `GET /api/v1/banks` en `hook_menu()` con `access callback => TRUE` (público) y `file => resources/bank.resource.inc`.
- **`myapi.info`** (modificar) — agregar `files[] = resources/bank.resource.inc`.
- **`docs/bank.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Detalle de un banco** (`GET /api/v1/banks/%`) — solo se expone la colección completa.
- **Crear / editar / borrar términos** del vocabulario `bancos` — endpoint de solo lectura.
- **Otros campos del término** más allá de `id`, `name` y `description` (weight, jerarquía padre/hijo, campos de Field API adjuntos al término) — no se exponen.
- **Autenticación / permisos** — el endpoint es público; no valida token ni rol.
- **Paginación, filtros u ordenamiento configurable** — se devuelve el árbol completo en el orden natural de `taxonomy_get_tree()` (peso y luego nombre).
- **Interpretar `description` como HTML enriquecido** — se sanea con `check_plain()` (texto plano escapado); no se aplica `filter_xss()` ni el text format del término.
- **Traducción de `name`/`description`** (i18n de términos, `entity_translation`, `i18n_taxonomy`) — se devuelve el valor tal cual está almacenado en el término.

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

1. **Crear `resources/bank.resource.inc`** con la cabecera `@file` y los `module_load_include()` de request, response e i18n (mismo bloque que `ping.resource.inc`).

2. **`myapi_bank_dispatch()`** — obtiene el método con `myapi_request_method()`; si es `GET` llama a `myapi_bank_list()`, en cualquier otro caso `myapi_error('method_not_allowed', 405)`.

3. **`myapi_bank_list()`:**
   - `$vocabulary = taxonomy_vocabulary_machine_name_load('bancos');`
   - Si `$vocabulary === FALSE` → `myapi_respond(['banks' => []], 200);` y termina.
   - `$tree = taxonomy_get_tree($vocabulary->vid);`
   - `$banks = array_map('myapi_bank_build_item', $tree);` (array vacío si no hay términos).
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

7. **Crear `docs/bank.md`** siguiendo la plantilla: descripción, auth (pública), método, forma de respuesta (tabla de campos `id`/`name`/`description`), tabla de errores (`405`), y nota de que el vocabulario ausente o vacío devuelve `banks: []`.

8. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación.

---

## Criterios de aceptación

- [ ] `GET /api/v1/banks` sin ningún header de autenticación devuelve `200` con `{ "success": true, "data": { "banks": [...] } }`.
- [ ] Cada elemento de `banks` tiene exactamente 3 claves: `id`, `name`, `description`.
- [ ] `id` es el `tid` del término como entero.
- [ ] `name` es el nombre del término saneado con `check_plain()`.
- [ ] `description` es la descripción del término saneada con `check_plain()`; un término sin descripción devuelve `""` (string vacío), nunca `null`.
- [ ] El orden de los bancos es el de `taxonomy_get_tree()` (peso y luego nombre).
- [ ] Si el vocabulario `bancos` no existe, la respuesta es `200` con `banks: []` (sin error `500`).
- [ ] Si el vocabulario existe pero no tiene términos, la respuesta es `200` con `banks: []`.
- [ ] Cualquier método distinto de `GET` (`POST`, `PUT`, `DELETE`) devuelve `405 method_not_allowed`.
- [ ] `docs/bank.md` documenta el endpoint completo (método, auth pública, campos de respuesta, error `405`, caso de vocabulario ausente/vacío).
- [ ] `myapi.info` incluye `files[] = resources/bank.resource.inc` y `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Clave del identificador | `id` | `tid` | Consistencia con el resto de la API, donde el identificador de recurso se expone como `id` (p. ej. `condominiums`). |
| Saneo de `description` | `check_plain()` (texto plano escapado) | `filter_xss()` (HTML seguro) / `check_markup()` (text format del término) | La app consume la descripción como texto plano; `check_plain()` es el criterio de saneo ya usado en el módulo y evita depender del text format configurado en Drupal. |
| Saneo de `name` | También con `check_plain()` | Devolver `name` crudo | Aunque el pedido solo mencionaba sanear `description`, el nombre viene de la misma fuente no confiable (Drupal EOL); sanearlo es coherente y sin costo. |
| Descripción vacía | `""` (string vacío) | `null` | `check_plain('')` ya devuelve `""` sin lógica extra; el cliente siempre recibe un string, simplificando el consumo. |
| Estructura de `data` | `{ "banks": [...] }` | `data` = array directo | Consistencia con las colecciones existentes (`properties` en units, `expenses` en gastos). |
| Vocabulario ausente | `200` con `banks: []` | `500 server_error` | Es un endpoint público de catálogo; el degradado silencioso es más amable con el cliente y evita filtrar detalles de configuración. |
| Implementación | `taxonomy_vocabulary_machine_name_load()` + `taxonomy_get_tree()` | Consulta directa a `taxonomy_term_data` / `taxonomy_index` | Pedido explícito del usuario; usa la API de taxonomía de Drupal 7 en lugar de leer tablas crudas. |
| Autenticación | Pública (`access callback => TRUE`) | Requerir access token | Pedido explícito del usuario; el catálogo de bancos no es información sensible. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Endpoint público sin autenticación.** Cualquiera puede listar los bancos y sus descripciones (que pueden incluir números de cuenta). | Aceptado por decisión explícita: el catálogo de bancos se considera no sensible. Si en el futuro las descripciones llevan datos privados, se revisa en un spec aparte con auth. |
| **`taxonomy_get_tree()` en vocabularios muy grandes.** Devuelve el árbol completo sin paginación, cargando todos los términos en memoria. | Aceptado: un catálogo de bancos es una lista corta y estable; la paginación queda explícitamente fuera de alcance. |
| **`description` como texto plano cuando la app espera HTML.** `check_plain()` escapa cualquier markup; si un término trae HTML intencional, la app lo verá escapado. | Decisión consciente (ver sección de decisiones); si se necesita HTML enriquecido, se cambia el saneo en un spec futuro sin romper el contrato de la clave. |
| **Dependencia del machine name `bancos`.** Si el vocabulario se renombra o se borra, el endpoint deja de devolver datos. | El caso ausente está cubierto: `200` con `banks: []` en vez de error; documentado en `docs/bank.md`. |
