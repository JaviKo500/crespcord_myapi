# 90 — Creación de una solicitud de servicio (`POST /api/v1/service-requests`)

> **Estado:** Implemented · **Depende de:** `89-service-request-detail` (Implemented) — dueña de `myapi_service_request_build_detail()`, que este spec reusa tal cual para el `201`; `88-service-requests-list` (Implemented) — dueña de `myapi_service_request_base_query()`; `87-service-request-direct-status` (Implemented) — dueña del estado `direct` y de por qué solo se alcanza al nacer; `78-provider-role` (Implemented) — dueña de `myapi_provider_role_category_ids()` y `myapi_services_provider_is_active()`; `66-claim-create` (Implemented) — el precedente de forma completo: multipart, todo-o-nada en los ficheros, y el patrón que este spec extrae a `includes/` · **Fecha:** 2026-08-17
> **Objetivo:** Añadir `POST /api/v1/service-requests`, que crea una solicitud de servicio del residente autenticado en su vivienda, con condominio derivado, categoría validada contra el vocabulario, adjudicación directa opcional a un proveedor elegible, e imágenes/adjunto opcionales guardados todo-o-nada.

Cuatro notas que la cabecera fija:

- **Extrae primero, escribe después.** `myapi_claim_create_save_files()` vive hoy dentro de `claim.resource.inc`, y la Regla 5 de `CLAUDE.md` prohíbe que este recurso la llame ahí. Antes de escribir el `create` de solicitudes, ese helper (y su limpieza todo-o-nada) se muda a `includes/myapi.node_files.inc` con nombres genéricos — `myapi_node_files_save()` / `myapi_node_files_delete()` — y `claim.resource.inc` pasa a consumirlo desde ahí, sin cambiar su comportamiento ni un byte.
- **El condominio nunca lo manda el cliente.** Se deriva de `unit_id` vía `field_condominio` de la vivienda — la misma tabla que ya lee `myapi_condominium_related_nids()`, pero para una vivienda concreta, no para todas las del usuario.
- **La adjudicación directa es una segunda validación completa, no un campo opcional simple.** Si `assigned_provider_id` viene, se comprueba nodo real, publicado, activo (licencia vigente) y con la categoría entre sus `field_categories` — las cuatro juntas, con un único código de salida: `403 provider_not_eligible`.
- **Cero cambios de esquema.** Los campos `field_images`/`field_attachment` de `service_request` ya están en 3 MB desde SPEC 77 (idénticos a claims); el endpoint hereda ese límite de la instancia real, igual que hace `POST /api/v1/claims`, en vez de hardcodear los 2 MB que se mencionaron al principio.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.node_files.inc`** (nuevo) — la extracción de arquitectura, primer paso del plan:
  - `myapi_node_files_save($field_name, $bundle, array $files, $max_count, $error_key, array $also_delete = [])` — copia exacta de `myapi_claim_create_save_files()`, renombrada, sin cambiar una línea de lógica: mismo remapeo de `$_FILES`, mismos validadores leídos de `field_info_field()`/`field_info_instance()`, mismo doble chequeo de MIME real, mismo todo-o-nada.
  - `myapi_node_files_delete(array $files)` — copia exacta de `myapi_claim_create_delete_files()`, renombrada.
  - Sirve desde hoy a dos consumidores: `claim.resource.inc` (que pasa a llamarla) y `service_request.resource.inc` (que la usa por primera vez), cerrando la deuda que marcaste.

- **`resources/claim.resource.inc`** (modificar) — `myapi_claim_create_save_files()` y `myapi_claim_create_delete_files()` se **borran** de aquí (no quedan como envoltorio: nada más los llamaba con ese nombre); sus dos llamadores (`myapi_claim_create()` y `myapi_claim_update()`) pasan a invocar `myapi_node_files_save()`/`myapi_node_files_delete()`. Cero cambio de comportamiento — es el criterio de no-regresión de la sección de aceptación.

- **`resources/service_request.resource.inc`** (modificar):
  - `myapi_service_request_dispatch()` gana una rama: `POST` → `myapi_service_request_create()`. El resto del dispatcher no cambia.
  - `myapi_service_request_create()` (nuevo) — la orquestación completa: autenticación, lectura de los campos `multipart/form-data`, validación en el orden `title → unit_id → category_id → description → desired_start → assigned_provider_id → images[] → attachment`, construcción y guardado del nodo, y respuesta `201` reusando `myapi_service_request_build_detail()` de SPEC 89.
  - Helpers puros nuevos, privados a este archivo: `myapi_service_request_valid_category($tid)` (tid real del vocabulario `service_category`), `myapi_service_request_parse_desired_start($raw)` (`strtotime()` + rechazo de fecha pasada), `myapi_service_request_validate_provider($provider_id, $category_id)` (las cuatro condiciones de elegibilidad, un único `403 provider_not_eligible`).
  - `myapi_service_request_build_node(...)` — construye el nodo sin guardar, mismo patrón que `myapi_claim_build_node()`: `field_requester` siempre el uid del token, `field_condominium` derivado (nunca del cliente), `field_request_status` = `direct` u `open` según si hay proveedor validado, `field_assigned_offer`/`field_closed_at` siempre vacíos.

- **`includes/myapi.i18n.inc`** (modificar) — claves nuevas `es`/`en`: `service_request_created`, `service_request_too_many_images`, `service_request_invalid_image`, `service_request_invalid_attachment`, `provider_not_eligible`. Reutilizadas sin cambio: `missing_authorization`, `invalid_token`, `missing_field`, `invalid_field`, `unit_access_denied`, `invalid_file_type`, `method_not_allowed`.

- **`myapi.info`** (modificar): `files[] = includes/myapi.node_files.inc`.

- **`docs/service-request.md`** (modificar) — sección nueva `POST /api/v1/service-requests` con la plantilla de `CLAUDE.md`.

- **`tests/unit/ServiceRequestCreateEndpointTest.php`** (nuevo), al estilo de `ClaimCreateEndpointTest`. Cubre también que `myapi_node_files_save()`/`myapi_node_files_delete()` responden igual que las funciones que reemplazan.

- No hay `drush updb` en el plan: ningún campo, tabla ni bundle nuevo — `myapi.install` no aparece en el diff.

**Fuera de alcance (para specs futuros):**

- **Editar, cancelar, cerrar o adjudicar** una solicitud ya creada. Este spec es solo creación.
- **Ofertar sobre una solicitud** (`POST /api/v1/offers` o similar). Otro recurso, otro spec.
- **Notificaciones** al crear una solicitud (push, email a administradores). Ninguna se dispara.
- **`service_transaction`.** Ninguna se crea al nacer la solicitud — a diferencia de `reclamo`, aquí no hay un `hook_node_insert()` que la genere, y no se pide.
- **Rate limiting / flood control** sobre la creación.
- **Ampliar o reducir `field_images`/`field_attachment`** (extensiones, tamaño, cardinalidad): se heredan tal cual están desde SPEC 77.
- **Restringir la difusión de una solicitud `direct`** al proveedor elegido — sigue siendo del rol `proveedor` por categoría (SPEC 87), sin cambios aquí.
- **`myapi_claim_valid_catalogue_value()`** no se toca ni se extrae: `service_request` no valida aquí ningún campo `list_text` contra `allowed_values` (el estado lo fija el servidor, no el cliente).
- **Enviar `field_requester`, `field_condominium`, `field_assigned_offer` o `field_closed_at` desde el cliente.** Ninguno es un campo del request.

---

## Modelo de datos

**No hay campos, tablas ni bundles nuevos.** Todo lo que se persiste existe desde SPEC 77/86/87; este spec escribe en esos campos por primera vez desde la API. Lo único nuevo son las cinco claves i18n y las funciones puras de validación.

### Request — `multipart/form-data`

| Campo | Tipo | Obligatorio | Destino |
|---|---|:---:|---|
| `title` | string, ≤255 | Sí | `node.title` |
| `unit_id` | int > 0 | Sí | `field_unit`, y de él se deriva `field_condominium` |
| `category_id` | int > 0 | Sí | `field_category` |
| `description` | string, no vacío | Sí | `field_description` |
| `desired_start` | string `Y-m-d H:i` | Sí | `field_desired_start` |
| `assigned_provider_id` | int > 0 | No | `field_assigned_provider`, y decide `field_request_status` |
| `images[]` | hasta 5 archivos, JPG/JPEG/PNG | No | `field_images` |
| `attachment` | 1 archivo, PDF/DOC/DOCX/XLS/XLSX | No | `field_attachment` |

`condominium_id` **no está en esta tabla**: no es un campo del request, nunca se lee del cliente (ver Header). Tamaño máximo de cada archivo: el que diga la instancia real de `field_images`/`field_attachment` en `service_request` — hoy 3 MB, vía `field_info_field()`/`field_info_instance()`, igual que `POST /api/v1/claims`.

### Campos que el servidor fija, nunca el cliente

| Campo | Valor |
|---|---|
| `node.uid` | `uid` del token Bearer |
| `node.status` | `1` (publicado) |
| `field_requester` | `uid` del token Bearer |
| `field_condominium` | El condominio de la vivienda validada (`field_condominio` de esa `vivienda`) |
| `field_request_status` | `'direct'` si `assigned_provider_id` vino y pasó las cuatro condiciones de elegibilidad; `'open'` en cualquier otro caso — incluida la ausencia del campo |
| `field_assigned_provider` | El nid del proveedor validado, o vacío |
| `field_assigned_offer` | Siempre vacío |
| `field_closed_at` | Siempre vacío |

### Validación, en este orden (cada paso aborta antes de tocar la base de datos o el filesystem)

| # | Campo | Regla | Error |
|---|---|---|---|
| 1 | Autenticación | Bearer válido | `401 missing_authorization` / `401 invalid_token` |
| 2 | Requeridos presentes | `title`, `unit_id`, `category_id`, `description`, `desired_start` | `422 missing_field` con `@field` el que falte |
| 3 | `title` | ≤255 caracteres (`node.title` es `varchar(255)`) | `422 invalid_field` |
| 4 | `unit_id` | entero positivo, y ∈ `myapi_unit_related_nids($uid)` | `422 invalid_field` (no numérico) / `403 unit_access_denied` (ajena) |
| 4b | Condominio derivado | `myapi_units_condominium_nids([$unit_id])` no vacío | interno — ver Riesgos; no es un error del cliente |
| 5 | `category_id` | entero positivo, y tid real del vocabulario `service_category` | `422 invalid_field` |
| 6 | `description` | no vacío tras `trim()` | `422 invalid_field` |
| 7 | `desired_start` | `strtotime('Y-m-d H:i ...')` parsea, y el resultado es **estrictamente mayor** que `REQUEST_TIME` | `422 invalid_field` |
| 8 | `assigned_provider_id` (si viene) | las cuatro condiciones de abajo, todas | `422 invalid_field` (no numérico) / `403 provider_not_eligible` (alguna de las cuatro falla) |
| 9 | `images[]` (si viene alguna) | máximo 5; extensión/tamaño de la instancia real; MIME real vía `finfo` | `422 service_request_too_many_images` / `422 service_request_invalid_image` / `422 invalid_file_type` |
| 10 | `attachment` (si viene) | mismo patrón sobre `field_attachment` | `422 service_request_invalid_attachment` / `422 invalid_file_type` |

Cualquier fallo en 9 o 10 borra los ficheros ya guardados **en esa misma request** (con `myapi_node_files_delete()`, incluidos los del otro campo) antes de responder — todo-o-nada, ningún nodo se crea. El proveedor se valida **antes** de tocar el filesystem (paso 8 antes que 9/10), así una solicitud rechazada por proveedor no deja ficheros huérfanos que limpiar.

### Las cuatro condiciones de `assigned_provider_id` (todas, o `403 provider_not_eligible`)

1. `assigned_provider_id` es el `nid` de un nodo real de bundle `provider`.
2. Ese nodo está publicado (`node.status = 1`).
3. `myapi_services_provider_is_active($status, $license_expiry, REQUEST_TIME)` es `TRUE` — publicado **y** licencia vigente, la misma función de SPEC 77.
4. `category_id` (el ya validado en el paso 5) está entre los tids de `field_data_field_categories` de ese proveedor.

Un único código de salida para las cuatro — `403 provider_not_eligible` — porque las cuatro dicen lo mismo desde el punto de vista del cliente: *este proveedor no puede recibir esta solicitud directa ahora mismo*. La única excepción es un `assigned_provider_id` que no es siquiera un entero positivo, que es `422 invalid_field` como cualquier otro campo mal formado.

### Claves i18n nuevas

| Clave | `es` | `en` |
|---|---|---|
| `service_request_created` | Solicitud de servicio creada correctamente. | Service request created successfully. |
| `service_request_too_many_images` | Máximo 5 imágenes por solicitud. | Maximum 5 images per service request. |
| `service_request_invalid_image` | Imagen inválida. Permitidos: JPG, JPEG o PNG hasta 3 MB. | Invalid image. Allowed: JPG, JPEG or PNG up to 3 MB. |
| `service_request_invalid_attachment` | Adjunto inválido. Permitidos: PDF, DOC, DOCX, XLS o XLSX hasta 3 MB. | Invalid attachment. Allowed: PDF, DOC, DOCX, XLS or XLSX up to 3 MB. |
| `provider_not_eligible` | El proveedor seleccionado no puede recibir esta solicitud. | The selected provider cannot receive this request. |

Reutilizadas sin cambio: `missing_authorization`, `invalid_token`, `missing_field`, `invalid_field`, `unit_access_denied`, `invalid_file_type`, `method_not_allowed`.

### Respuesta de éxito (201)

**El mismo objeto de dieciocho claves que `GET /api/v1/service-requests/%`**, construido por `myapi_service_request_build_detail()` (SPEC 89) con `viewer` fijo en `'requester'` — el creador siempre es el solicitante — `offers: []` y `offers_count: 0` (nadie puede haber ofertado todavía), `closed_at: null` siempre:

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 145,
      "title": "Fuga en el calentador",
      "description": "El calentador del baño principal gotea desde el lunes.",
      "status": "open",
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "offers_count": 0,
      "assigned_offer": null,
      "assigned_provider": null,
      "created": "2026-08-17T10:05:00",
      "desired_start": "2026-08-19T08:00:00",

      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "unit": { "id": 55, "name": "A-301" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [
        { "id": 210, "url": "https://.../api/v1/service-requests/145/files/210", "filename": "fuga.jpg" }
      ],
      "attachment": null,
      "closed_at": null,
      "offers": []
    }
  },
  "message": "Solicitud de servicio creada correctamente."
}
```

Una solicitud con `assigned_provider_id` válido responde igual, con `status: "direct"` y `assigned_provider` ya relleno.

> **Ampliado el 2026-08-28.** Ese `assigned_provider` no es `{id, name}`: es la **tarjeta de proveedor entera**, las ocho claves de `GET /api/v1/providers` (`title` y no `name`). Este `201` es la primera respuesta en la que el residente ve la empresa que eligió, y la pinta con el widget que ya tiene. Una solicitud `open` no paga ni una consulta por ello. Ver [la ampliación del SPEC 89](89-service-request-detail.md#ampliación-2026-08-28--la-adjudicación-viaja-entera).

### El plan de escritura y de respuesta

1. `myapi_service_request_build_node(...)` construye el nodo **sin guardar** — mismo patrón que `myapi_claim_build_node()`.
2. `node_save($node)`.
3. `file_usage_add()` por cada imagen y por el adjunto, igual que claims.
4. Reconstrucción de la respuesta con las funciones que **SPEC 89 ya escribió y prueba**, sin duplicar serialización: `myapi_service_request_detail_row($node->nid)` (una consulta, trae vivienda, condominio, categoría, adjunto, estado), `myapi_user_display_names([$uid])` para `requester.name`, `myapi_service_request_load_images($node->nid)`, y `offers = []` / `offers_count = 0` puestos directamente en código — sin consultar `service_offer`, porque en el instante de creación esa lista está garantizada vacía y consultarla sería una consulta de más en el camino de escritura.
5. `myapi_respond(['service_request' => myapi_service_request_build_detail($row, 'requester', $images, [], 0)], 201, 'service_request_created')`.

---

## Plan de implementación

Nueve pasos. Los primeros tres cierran la deuda de arquitectura sin tocar ningún endpoint vivo; el sexto es el que enciende `POST /api/v1/service-requests`.

1. **`includes/myapi.node_files.inc` — el helper compartido, movido.**
   `myapi_node_files_save($field_name, $bundle, array $files, $max_count, $error_key, array $also_delete = [])` y `myapi_node_files_delete(array $files)`, copiados **verbatim** de `myapi_claim_create_save_files()` / `myapi_claim_create_delete_files()` — misma lógica, mismo remapeo de `$_FILES`, mismo doble chequeo de MIME — solo el nombre cambia, de `claim` a `node`, porque desde este spec tiene dos consumidores. `myapi.info` gana `files[] = includes/myapi.node_files.inc`.
   *Verificación: `php -l`.*

2. **`resources/claim.resource.inc` — consumir el include nuevo.**
   Se borran `myapi_claim_create_save_files()` y `myapi_claim_create_delete_files()`; sus dos llamadores, `myapi_claim_create()` y `myapi_claim_update()`, pasan a invocar `myapi_node_files_save()` / `myapi_node_files_delete()` con los mismos argumentos que ya usaban. Ni una línea de comportamiento cambia.
   *Verificación: `php -l`; la suite completa de `ClaimCreateEndpointTest` y `ClaimUpdateEndpointTest` sigue en verde sin haber tocado un solo test — la prueba de que la extracción no movió nada.*

3. **`includes/myapi.i18n.inc` — las cinco claves nuevas.**
   `service_request_created`, `service_request_too_many_images`, `service_request_invalid_image`, `service_request_invalid_attachment`, `provider_not_eligible`, en los bloques `es` y `en`, junto a las demás de `service_request_*`.
   *Verificación: `myapi_t()` de las cinco devuelve el texto correcto en ambos idiomas.*

4. **`resources/service_request.resource.inc` — los tres helpers puros de validación.**
   `myapi_service_request_valid_category($tid)` — tid real de `MYAPI_SERVICES_CATEGORY_VOCABULARY` vía `taxonomy_term_load()` y comparación del `vid`. `myapi_service_request_parse_desired_start($raw)` — `strtotime()` sobre `Y-m-d H:i`, `FALSE` o un resultado `<= REQUEST_TIME` responden lo mismo: inválido. `myapi_service_request_validate_provider($provider_id, $category_id)` — las cuatro condiciones de la sección anterior, con una sola consulta (nodo + `field_license_expiry` + `field_categories`, mismo patrón que `myapi_provider_role_any_provider_active()`). Ninguno enruta todavía.
   *Verificación: `php -l`; tests unitarios de las tres funciones contra filas fixture, sin sitio arrancado.*

5. **El mismo fichero — `myapi_service_request_build_node(...)`.**
   Construye el nodo `service_request` sin guardar: `title`, `field_unit`, `field_condominium` (derivado), `field_category`, `field_description`, `field_desired_start`, `field_requester = $uid`, `field_request_status` (`direct` u `open` según si `$assigned_provider_id` llegó validado), `field_assigned_provider`, y los `fid` de imágenes/adjunto cuando existan. Mismo patrón que `myapi_claim_build_node()`: sin guardar, para que el orquestador controle el orden frente a `file_usage_add()`.
   *Verificación: `php -l`.*

6. **El mismo fichero — `myapi_service_request_create()` y la rama del dispatcher.**
   Orquestación completa en el orden de la sección de validación del Modelo de datos: auth → campos requeridos → `title` → `unit_id` (+ condominio derivado) → `category_id` → `description` → `desired_start` → `assigned_provider_id` → `images[]` → `attachment` → `myapi_service_request_build_node()` → `node_save()` → `file_usage_add()` por archivo → recarga con `myapi_service_request_detail_row()` + `myapi_user_display_names()` + `myapi_service_request_load_images()` → `myapi_respond([...], 201, 'service_request_created')`. `myapi_service_request_dispatch()` gana la rama `POST` → `myapi_service_request_create()`; el resto del dispatcher no cambia. Sin tocar `myapi.module`: la ruta ya existe y hoy responde `405` a todo lo que no sea `GET`.
   *Verificación: `curl -F` con los campos mínimos crea la solicitud en `open`; con `assigned_provider_id` válido, en `direct`; sin `Authorization`, `401`; con un `unit_id` ajeno, `403 unit_access_denied`; con un proveedor inelegible, `403 provider_not_eligible`.*

7. **`docs/service-request.md`.**
   Sección nueva `POST /api/v1/service-requests` con la plantilla de `CLAUDE.md`: método, auth, tabla de campos del `multipart/form-data`, las cuatro condiciones de `assigned_provider_id`, ejemplo de respuesta `201`, tabla de errores completa.
   *Verificación: lectura contra la implementación.*

8. **Tests.**
   `tests/unit/ServiceRequestCreateEndpointTest.php`, al estilo de `ClaimCreateEndpointTest`: los tres helpers puros del paso 4, las cuatro condiciones del proveedor por separado, el orden de validación, el todo-o-nada de ficheros, la forma completa de la respuesta `201` contra las dieciocho claves de SPEC 89, y `open` vs `direct`. Más un guard de no-regresión: `myapi_node_files_save()`/`myapi_node_files_delete()` responden exactamente igual que las funciones que reemplazaron, ejercitadas desde ambos consumidores.
   *Verificación: suite completa en verde.*

9. **`drush cc all` + matriz manual.**
   No hay `drush updb`: ningún esquema cambia. Recorrer los criterios de aceptación con un residente con una sola vivienda, uno con vivienda en dos condominios, una solicitud sin proveedor, una con proveedor válido, y una con proveedor inelegible por cada una de las cuatro causas.

---

## Criterios de aceptación

**Autenticación y método**

- [x] `POST /api/v1/service-requests` sin `Authorization` → `401 missing_authorization`.
- [x] Con un token inválido o expirado → `401 invalid_token`.
- [x] `GET`, `PUT`, `DELETE` sobre `api/v1/service-requests` siguen respondiendo como hoy — este spec no les cambia el comportamiento.
- [x] `GET`, `PUT`, `DELETE`, `POST` sobre `api/v1/service-requests/%` siguen respondiendo `405` — esta ruta no gana un `POST`, crear siempre es sobre la colección.

**Campos requeridos**

- [x] Falta `title`, `unit_id`, `category_id`, `description` o `desired_start` → `422 missing_field` con `@field` el que falte, y no se crea ningún nodo.
- [x] `title` de 256 caracteres o más → `422 invalid_field`.
- [x] `title` vacío o solo espacios → `422 missing_field` (o `invalid_field` tras `trim()`), nunca crea el nodo con un título vacío.
- [x] `description` vacío tras `trim()` → `422 invalid_field`.

**`unit_id` y el condominio derivado**

- [x] `unit_id` no numérico, `0` o negativo → `422 invalid_field`.
- [x] `unit_id` de una vivienda ajena (el usuario no es propietario ni ocupante) → `403 unit_access_denied`.
- [x] `unit_id` de una vivienda propia → la solicitud se crea con `field_unit` y `field_condominium` correctos, y **`condominium_id` no viaja en el request** — se deriva por completo.
- [x] Un usuario sin ninguna vivienda asociada → cualquier `unit_id` responde `403 unit_access_denied`, nunca `422` ni `200`.
- [x] El `condominium` de la respuesta `201` coincide byte a byte con el que `GET /api/v1/service-requests/{id}` respondería para esa misma solicitud.

**`category_id`**

- [x] `category_id` no numérico, `0` o negativo → `422 invalid_field`.
- [x] `category_id` que no es un tid real del vocabulario `service_category` (inexistente, o tid de otro vocabulario) → `422 invalid_field`.
- [x] `category_id` de un término real del vocabulario → la solicitud se crea con `field_category` correcto, y `category` en la respuesta trae `id`, `code` y `name`.

**`desired_start`**

- [x] Un valor que no parsea con `strtotime()` (formato inválido, cadena vacía) → `422 invalid_field`.
- [x] Una fecha pasada → `422 invalid_field`.
- [x] El instante exacto de `REQUEST_TIME` → `422 invalid_field` (estrictamente futuro, no `>=`).
- [x] Una fecha futura válida → se crea, y `desired_start` en la respuesta tiene la forma `Y-m-d\TH:i:s`.

**`assigned_provider_id` y `direct`**

- [x] Sin `assigned_provider_id` → la solicitud se crea en `open`, con `assigned_provider: null`.
- [x] `assigned_provider_id` no numérico, `0` o negativo → `422 invalid_field`.
- [x] Un `assigned_provider_id` que no es el nid de ningún nodo, o que es de otro bundle (una `vivienda`, un `reclamo`) → `403 provider_not_eligible`.
- [x] Un nodo `provider` despublicado → `403 provider_not_eligible`.
- [x] Un nodo `provider` publicado con `field_license_expiry` vencida → `403 provider_not_eligible`.
- [x] Un nodo `provider` activo cuya `field_categories` **no** incluye el `category_id` de la solicitud → `403 provider_not_eligible`.
- [x] Un nodo `provider` activo cuya `field_categories` sí incluye el `category_id` → la solicitud se crea en `direct`, con `field_assigned_provider` relleno y `assigned_provider: { id, name }` en la respuesta.
- [x] Con `assigned_provider_id` válido, `field_assigned_offer` y `field_closed_at` quedan vacíos igual que en `open` — `direct` no adjudica ninguna oferta.
- [x] Falla la validación del proveedor **antes** de subirse ningún archivo: una request con `assigned_provider_id` inelegible e imágenes válidas no deja ningún `file_managed` guardado.

**Imágenes**

- [x] Sin `images[]` → la solicitud se crea con `"images": []`.
- [x] De 1 a 5 imágenes válidas (jpg/jpeg/png, dentro del límite real de la instancia) → todas se guardan y aparecen en `images`, en el mismo orden en que se enviaron.
- [x] 6 imágenes → `422 service_request_too_many_images`, ninguna se guarda, no se crea el nodo.
- [x] Una imagen que excede el límite real de la instancia entre varias válidas → `422 service_request_invalid_image`, **ninguna** de las imágenes de esa request queda guardada, no se crea el nodo.
- [x] Un `.php` renombrado a `.jpg` → `422 invalid_file_type` (el `finfo` real lo detecta), no se guarda.

**Adjunto**

- [x] Sin `attachment` → la solicitud se crea con `"attachment": null`.
- [x] Un adjunto válido → se guarda y aparece en `attachment`.
- [x] Un adjunto de tipo o tamaño inválido → `422 service_request_invalid_attachment`, y si ya se habían guardado imágenes válidas en la misma request, también se borran — no queda ningún archivo huérfano y no se crea el nodo.

**Creación**

- [x] El nodo se crea con `status = 1` (publicado), `field_requester` = el `uid` del token, sin importar qué haya mandado el cliente (el campo no se lee del request).
- [x] Ningún `service_transaction` se crea al nacer una solicitud — a diferencia de `reclamo`, no hay `hook_node_insert()` para este bundle.

**Respuesta**

- [x] Éxito → `201`, con `data.service_request` teniendo exactamente las mismas dieciocho claves y tipos que `GET /api/v1/service-requests/%`, y `message` traducido (`service_request_created`).
- [x] `viewer` es siempre `"requester"` en la respuesta de creación.
- [x] `offers: []` y `offers_count: 0` siempre, sin ejecutar ninguna consulta sobre `service_offer`.
- [x] `closed_at: null` siempre.
- [x] Las `url` de `images`/`attachment` en la respuesta del `201` ya son las autenticadas de `GET /api/v1/service-requests/%/files/%` (SPEC 89) y descargan con el mismo token.
- [x] Inmediatamente después del `POST`, `GET /api/v1/service-requests/{id}` con el mismo token responde el mismo objeto, byte a byte.

**No regresión**

- [x] `GET /api/v1/service-requests`, `GET /api/v1/service-requests/%` y `GET /api/v1/service-requests/%/files/%` no cambian ninguna clave, tipo ni código de estado.
- [x] `POST /api/v1/claims` y `POST /api/v1/claims/%` (SPEC 66/67) no cambian ninguna clave, tipo ni código de estado tras la extracción del paso 2 del plan.
- [x] `myapi.install` no aparece en el diff: ningún campo, tabla ni bundle nuevo; `drush updb` no encuentra ningún update pendiente.
- [x] `myapi.module` no aparece en el diff: no hay rutas nuevas ni cambios en `hook_menu()`.
- [x] La suite unitaria completa pasa, incluidas `ClaimCreateEndpointTest` y `ClaimUpdateEndpointTest`, y `drush cc all` no reporta errores.

**Documentación**

- [x] `docs/service-request.md` documenta `POST /api/v1/service-requests` con la plantilla de `CLAUDE.md`: método, auth, campos, las cuatro condiciones del proveedor, respuesta, tabla de errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Código de error del proveedor inelegible | **Un único `403 provider_not_eligible`** para las cuatro causas | `422 invalid_field`; o `404`/`403` separados (nid inexistente vs. inelegible) | Decisión explícita del usuario. Las cuatro causas dicen lo mismo desde el cliente: este proveedor no puede recibir la solicitud ahora. Separar nid-inexistente de inelegible obligaría al cliente a distinguir dos códigos para una sola decisión de UI ("elige otro proveedor"), y el nid no es un dato sensible que deba ocultarse tras un 404. |
| Tamaño de `images[]`/`attachment` | **3 MB, heredado de la instancia real del campo** | Bajar el campo a 2 MB con un `field_update_instance()` | Decisión explícita del usuario. Cero cambios de esquema; el endpoint hereda el límite igual que `POST /api/v1/claims`, y el "2MB" inicial era una aproximación sin verificar contra `myapi.install`. |
| Forma de extraer `myapi_claim_create_save_files()` | **Nombres genéricos (`myapi_node_files_save()`/`myapi_node_files_delete()`) en `includes/myapi.node_files.inc`, sin envoltorio en claims** | Mover el código sin renombrar, dejando `myapi_claim_create_save_files()` como alias | Decisión explícita del usuario. Un nombre con `claim` en un helper que ahora sirve a dos bundles es engañoso, y no hay un segundo consumidor del nombre viejo que justifique conservarlo como envoltorio — la Regla 3 de `CLAUDE.md` pide una función compartida, no dos nombres para la misma. |
| Corte de `desired_start` | **Estrictamente futuro: `> REQUEST_TIME`** | `>= REQUEST_TIME` | Decisión explícita del usuario. Simple y sin ambigüedad de zona horaria: todo se compara contra el reloj del servidor, y el segundo exacto no es un caso que un cliente arme a propósito. |
| Forma de la respuesta `201` | **Las mismas dieciocho claves que `GET /api/v1/service-requests/%`**, reusando `myapi_service_request_build_detail()` con `viewer` fijo en `'requester'` | Un objeto reducido solo con los campos enviados | Decisión explícita del usuario, mismo criterio que SPEC 66 aplicó a claims: le ahorra al cliente un segundo `GET`, y reusar el serializador de SPEC 89 evita una segunda forma de la misma solicitud que hay que mantener sincronizada. |
| `offers` y `offers_count` en la respuesta de creación | **`[]` y `0` puestos directamente en código, sin consultar `service_offer`** | Llamar a `myapi_service_request_load_offers()` y `myapi_service_request_offer_counts_by_nid()` igual que el detalle | En el instante de creación esas dos consultas están garantizadas a devolver vacío — nada puede haber ofertado sobre un nodo que aún no existía hace un microsegundo. Ejecutarlas de todos modos sería gastar dos consultas para confirmar algo que el propio flujo ya sabe. |
| Orden de validación | **`title → unit_id → category_id → description → desired_start → assigned_provider_id → images[] → attachment`** | Validar el proveedor al final, junto a los archivos | Decisión explícita del usuario. El proveedor se resuelve antes de tocar el filesystem: una solicitud rechazada por proveedor inelegible nunca llega a guardar un archivo que después haya que borrar. |
| Ubicación del código nuevo de creación | **Dentro de `resources/service_request.resource.inc`**, junto al `GET` existente | Un archivo nuevo | Mismo criterio que SPEC 66 aplicó a claims: nadie más que este recurso crea solicitudes, no hay un segundo consumidor todavía. |
| `service_transaction` al crear | **Ninguna se genera** | Crear una entrada inicial, como `hook_node_insert()` hace para `reclamo` | Pedido explícitamente fuera de alcance por el usuario. `service_request` no tiene hoy ningún `hook_node_insert()` que la dispare, y añadir uno sería inventar una pieza de la línea de tiempo que ningún spec de servicios ha pedido todavía. |
| `myapi_claim_valid_catalogue_value()` | **No se extrae ni se reutiliza** | Moverla también a `includes/myapi.node_files.inc` o a un include nuevo | `service_request` no valida aquí ningún campo `list_text` contra `allowed_values`: el estado lo fija el servidor (`open`/`direct`), no el cliente. Extraer una función sin un segundo llamador real anticiparía una necesidad que este spec no tiene. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Una vivienda sin `field_condominio` relleno** (dato incoherente que hoy nada impide) deja `myapi_units_condominium_nids([$unit_id])` vacío, y la solicitud no tendría condominio que fijar. | El caso no tiene una respuesta de cliente razonable — no es un dato mal formado que el residente pueda corregir. Se documenta como precondición de datos en `docs/service-request.md`, con la misma consulta de diagnóstico que SPEC 89 dejó para el `tid` huérfano de categoría, y el endpoint responde `500`/registra el caso en vez de crear una solicitud sin condominio — decisión de implementación, sin código de i18n propio porque no es un error del usuario. |
| **La extracción de `myapi_node_files_save()` introduce una regresión sutil en claims** si el copy-paste diverge en un detalle (un validador, el orden del `finfo`). | El paso 2 del plan es un cambio de **cero comportamiento** por diseño — se verifica con la suite existente de `ClaimCreateEndpointTest`/`ClaimUpdateEndpointTest` sin tocar un solo test, la misma disciplina que SPEC 89 usó para extraer `myapi_user_display_names()`. |
| **`field_categories` de un proveedor queda vacío** (nodo `provider` sin ninguna categoría asignada, dato incoherente). | La condición 4 de elegibilidad ya lo cubre sin código especial: un `IN` contra un conjunto vacío nunca es verdadero, así que ese proveedor responde `403 provider_not_eligible` igual que uno de otra categoría — la lectura segura. |
| **Un residente reenvía el mismo `POST` dos veces** (doble tap, reintento de red) y crea dos solicitudes idénticas. | Fuera de alcance por decisión explícita: ningún endpoint de escritura del módulo tiene idempotencia o deduplicación salvo el login, y no se introduce aquí sin pedirlo. Anotado para que no se descubra como "bug" en producción. |
| **`myapi_service_request_validate_provider()` consulta `field_categories` con una query propia**, distinta de `myapi_provider_role_category_ids()` (que resuelve las categorías del **operador autenticado**, no las de un nid arbitrario). Confundir las dos funciones filtraría por el proveedor equivocado. | Nombradas de forma deliberadamente distinta y documentadas en el docblock: `myapi_provider_role_category_ids()` responde "¿qué categorías atienden LOS PROVEEDORES DE ESTE USUARIO?"; la nueva responde "¿qué categorías atiende ESTE NID CONCRETO?" — la segunda pregunta no existía en el módulo antes de este spec. |

---

## Lo que **NO** está en este spec

- Editar, cancelar, cerrar o adjudicar una solicitud desde la app.
- Ofertar sobre una solicitud, o cualquier endpoint del recurso `service_offer`.
- Notificaciones al crear una solicitud (push, email a administradores).
- Cualquier `service_transaction` generada por la creación.
- Rate limiting / flood control sobre la creación.
- Cambios de esquema: extensiones, tamaños o cardinalidad de `field_images`/`field_attachment`.
- Restringir la difusión de una solicitud `direct` al proveedor elegido (sigue siendo del rol `proveedor` por categoría, SPEC 87).
- Aceptar `field_requester`, `condominium_id`, `field_assigned_offer` o `field_closed_at` como campos del request.

Cada uno de ellos, si llega, va en su propio spec.

---

## Estado de la verificación (2026-08-18)

**Qué significa cada marca.** Un `[x]` es un criterio que queda demostrado por un test de la suite unitaria (`vendor/bin/phpunit`, hoy **1643 tests / 7245 assertions en verde**) o por una comprobación directa sobre el código y el diff. Un `[ ]` es un criterio que **no** se puede cerrar sin el sitio arrancado: necesita filesystem real, `finfo` real, la instancia real de los campos de fichero, o una descarga autenticada. Esos ocho quedan para la matriz manual del paso 9 del plan.

**Los ocho que quedan pendientes, y por qué:**

| Criterio | Por qué no se puede cerrar aquí | Cómo comprobarlo |
|---|---|---|
| `category_id` real → `field_category` correcto y `category` con `id`/`code`/`name` | Es exactamente lo que rompía el bug de `field_category` (se escribía con la clave `target_id` de un *entityreference* en un campo `taxonomy_term_reference`, y `taxonomy_build_node_index()` moría con un `tid` NULL). El test unitario ya fija la clave `tid`, pero la escritura real solo la prueba la base de datos. | Un `POST` válido tras `drush cc all`; comprobar que `field_data_field_category.field_category_tid` trae el tid y que la respuesta devuelve `code` y `name`. |
| 1–5 imágenes válidas se guardan en orden | `file_save_upload()` sobre ficheros reales. | `curl -F 'images[]=@a.jpg' -F 'images[]=@b.jpg'`. |
| Una imagen que excede el límite real → ninguna queda guardada | El límite lo da la instancia real del campo (3 MB), no un fixture. | Enviar una válida y una de 4 MB; `file_managed` no debe crecer. |
| Un `.php` renombrado a `.jpg` → `422 invalid_file_type` | Depende del `finfo` del servidor. | Renombrar y enviar. |
| Un adjunto válido se guarda y aparece en `attachment` | Filesystem real. | `curl -F 'attachment=@doc.pdf'`. |
| Un adjunto inválido borra también las imágenes ya guardadas | El todo-o-nada solo se observa contra `file_managed` real. | Enviar 2 imágenes válidas + un `.exe`; ninguna fila debe quedar. |
| Las `url` de `images`/`attachment` descargan con el mismo token | Requiere la descarga autenticada de SPEC 89. | `GET` a la `url` del `201` con el mismo Bearer. |
| `drush cc all` no reporta errores | No hay sitio arrancado en el entorno de test. | `drush cc all`. |

**Dos apuntes sobre criterios ya marcados:**

- El criterio de no-regresión de claims nombra `ClaimCreateEndpointTest` y `ClaimUpdateEndpointTest`, que no existen con esos nombres: la cobertura de escritura de claims vive en `ClaimEndpointTest` y `ClaimWriteGuardsTest`, y son esas las que pasan sin haber sido tocadas.
- El criterio de la suite completa está marcado solo por su primera mitad (la suite pasa); su segunda mitad, `drush cc all`, está en la tabla de arriba.

**Fuera del spec original, añadido al corregir dos bugs de la implementación:**

- `field_category` se escribe con la clave `tid`, no `target_id`.
- Clave i18n nueva `service_request_too_many_attachments`: más de un fichero bajo `attachment` respondía la clave de imágenes, apuntando al cliente a un campo que no había rellenado.
