# SPEC 66 — Creación de reclamos desde la app (`POST /api/v1/claims`)

> **Estado:** Approved · **Depende de:** SPEC 55 (bundles `reclamo`/`claim_transaction` y sus campos), SPEC 57 (`myapi_claim_transaction_create_initial()` vía `hook_node_insert()`), SPEC 64 (`resources/claim.resource.inc`, `myapi_claim_build_item()`, `myapi_claim_dispatch()`), SPEC 65 (`myapi_claim_build_file()`, ficheros en `private://`) · **Fecha:** 2026-08-03
> **Objetivo:** Añadir `POST /api/v1/claims`, que crea un reclamo del residente autenticado con sus imágenes y adjunto opcionales, validando que el condominio le pertenezca, y devuelve el mismo objeto que `GET /api/v1/claims/%` — con la transacción inicial "Recibido" ya incluida, porque `hook_node_insert()` la crea sola.

Notas técnicas que fija la cabecera, porque condicionan el resto del documento:

- **No se toca `myapi.install`.** `field_images` (png/jpg/jpeg, 3 MB) y `field_attachment` (pdf/doc/docx/xls/xlsx, 3 MB) ya tienen exactamente los límites pedidos desde SPEC 55. El único límite que no existe a nivel de campo es "máximo 5 imágenes" (la cardinalidad es ilimitada), así que se aplica en el endpoint.
- **No se toca `myapi.module`.** La ruta `api/v1/claims` ya apunta a `myapi_claim_dispatch()` sin restricción de método; hoy responde `405` a todo lo que no sea `GET`. Este spec le añade la rama `POST`.
- **`solicitante` no viaja en el request.** `field_requester` siempre es el `uid` del token Bearer — no es un campo que el cliente envíe ni pueda sobrescribir. La validación de condominio pedida ("que el condominio sea del solicitante") se reduce, por tanto, a `myapi_condominium_related_nids($uid)`, la misma función que ya usa `GET /api/v1/claims`.

---

## Alcance

**Dentro:**

- **`resources/claim.resource.inc`** (modificar):
  - **`myapi_claim_dispatch($id = NULL)`** gana una rama: `POST` con `$id === NULL` → `myapi_claim_create()`. `POST` sobre `api/v1/claims/%` (con id) sigue devolviendo `405`, igual que hoy — crear siempre es sobre la colección.
  - **`myapi_claim_create()`** (nuevo) — la orquestación completa: autenticación, lectura de los campos `multipart/form-data`, validación de negocio (asunto, tipo, condominio, descripción, visibilidad, en ese orden), validación y guardado de imágenes/adjunto (todo-o-nada), construcción y guardado del nodo `reclamo`, y respuesta `201` con el mismo objeto que `GET /api/v1/claims/%`.
  - Helpers de apoyo, todos nuevos y privados a este archivo: validación de `claim_type`/`visibility` contra las `allowed_values` de sus campos (mismo patrón que `payment_method` en `payment.resource.inc`), y el guardado de imágenes/adjunto reutilizando `file_field_widget_upload_validators()` / `file_field_widget_uri()` sobre `field_info_field()`/`field_info_instance()` — **no** se hardcodean extensiones ni tamaños: si `myapi.install` cambia el límite mañana, este endpoint lo hereda solo, igual que ya hace `myapi_claim_transaction_create_form()` (SPEC 57).
  - **`myapi_claim_dispatch()`** es el único punto de entrada: no se toca `hook_menu()` en `myapi.module`, porque `api/v1/claims` ya enruta ahí sin restricción de método.
- **`includes/myapi.i18n.inc`** (modificar) — claves nuevas para `es`/`en`: el mensaje de éxito y los errores propios de creación que no tienen ya una clave reutilizable (ver Modelo de datos).
- **`docs/claim.md`** (modificar) — sección nueva `POST /api/v1/claims` con la plantilla de `CLAUDE.md`, y la tabla de "Field reference" ampliada si hace falta.
- No hay `drush updb` en el plan: no se crea ni modifica ningún campo, tabla o bundle.

**Fuera de alcance (para specs futuros):**

- **Editar, cerrar o eliminar un reclamo desde la app.** Este spec es solo creación.
- **Añadir una transacción desde la app** (comentarios de seguimiento, cambios de estado por el residente). Sigue siendo exclusivo del back office (SPEC 57).
- **Notificar a los administradores** cuando se crea un reclamo nuevo (patrón de SPEC 48). Ni push ni email.
- **Control de flood / rate limiting** sobre la creación. Ningún endpoint de escritura del módulo lo tiene salvo el login; no se introduce aquí sin que se pida explícitamente.
- **Ampliar `field_images` a `webp`** o cualquier otro cambio de `myapi.install`: decidido explícitamente que las imágenes se quedan en `png jpg jpeg`, sin migración.
- **Enviar `field_requester` distinto del usuario autenticado.** Decidido: el solicitante es siempre quien posee el token; no es un campo de la API.

Dos casos límite decididos dentro de este alcance:

1. **Usuario sin ninguna vivienda/condominio asociado** (`myapi_condominium_related_nids($uid)` vacío): cualquier `condominium_id` que envíe cae fuera de un conjunto vacío → `403 condominium_access_denied`. Es la consecuencia natural de la misma regla, sin código especial.
2. **Reclamo sin ninguna imagen ni adjunto**: válido. Los dos son opcionales, igual que a nivel de campo (`required = 0`).

---

## Modelo de datos

**No hay campos, tablas ni bundles nuevos.** Todo lo que se persiste ya existe desde SPEC 55; este spec solo escribe en esos campos por primera vez desde la API. Lo único nuevo son las claves i18n de la tabla de errores.

### Request — `multipart/form-data`

Igual que `POST /api/v1/payments` (SPEC 20): campos de texto por `$_POST` vía `myapi_request_post_field()`, archivos por `$_FILES`. No hay JSON body.

| Campo | Tipo | Obligatorio | Destino |
|---|---|---|---|
| `subject` | string, ≤255 | Sí | `node.title` |
| `claim_type` | string, `requirement` \| `claim` | Sí | `field_claim_type` |
| `condominium_id` | int > 0 | Sí | `field_condominium` |
| `description` | string, no vacío | Sí | `field_description` |
| `visibility` | string, `private` \| `public` | Sí | `field_visibility` |
| `images[]` | hasta 5 archivos, JPG/JPEG/PNG, ≤3 MB c/u | No | `field_images` |
| `attachment` | 1 archivo, PDF/DOC/DOCX/XLS/XLSX, ≤3 MB | No | `field_attachment` |

`solicitante` **no está en esta tabla**: `field_requester` se fija siempre al `uid` del token, sin leer nada del request (ver Header).

### Campos que el servidor fija, nunca el cliente

| Campo | Valor |
|---|---|
| `node.uid` | `uid` del token Bearer |
| `node.status` | `1` (publicado) |
| `field_requester` | `uid` del token Bearer |
| `field_status` | `'received'` — el estado inicial pedido, escrito explícitamente porque `default_value` de Field API no se aplica en un `node_save()` programático (no pasa por un formulario) |
| `field_reception_date` | `date('Y-m-d H:i:00')` — hora del servidor en el momento de la creación, mismo formato con segundos fijados a `:00` que usa `myapi_claim_transaction_create_initial()` |

### Validación, en este orden (cada paso aborta con su propio error antes de tocar la base de datos)

1. **Autenticación** — Bearer válido, o `401`.
2. **Campos requeridos presentes** — `subject`, `claim_type`, `condominium_id`, `description`, `visibility` → `422 missing_field` con `@field` el que falte.
3. **`subject`** — ≤255 caracteres (`node.title` es `varchar(255)`) → `422 invalid_field`.
4. **`claim_type`** — debe ser una clave de `field_info_field('field_claim_type')['settings']['allowed_values']` → `422 invalid_field`. Sin whitelist propia: lee el campo, igual que `payment_method` en `payment.resource.inc`.
5. **`condominium_id`** — entero positivo → `422 invalid_field`; y debe estar en `myapi_condominium_related_nids($uid)` → **`403 condominium_access_denied`** (mismo código que usa `GET /api/v1/claims?condominium_id=`).
6. **`description`** — no vacío tras `trim()` → `422 invalid_field`. Sin máximo propio (`text_long`).
7. **`visibility`** — debe ser una clave de `field_info_field('field_visibility')['settings']['allowed_values']` → `422 invalid_field`.
8. **`images[]`** (si viene alguna) — máximo 5 → `422 claim_too_many_images`; cada una, extensión y tamaño vía `file_field_widget_upload_validators('field_images', field_info_instance('node', 'field_images', 'reclamo'))` → `422 claim_invalid_image`; MIME real vía `finfo` (mismo doble chequeo que `myapi_payment_save_file()`) → `422 invalid_file_type`.
9. **`attachment`** (si viene) — mismo patrón sobre `field_attachment` → `422 claim_invalid_attachment` / `422 invalid_file_type`.
10. Cualquier fallo en 8 o 9 **borra los ficheros ya guardados en esta misma request** (imágenes previas, o el adjunto) antes de responder — todo-o-nada, ningún nodo se crea.

### Claves i18n nuevas

| Clave | `es` | `en` |
|---|---|---|
| `claim_created` | Reclamo creado correctamente. | Claim created successfully. |
| `claim_too_many_images` | Máximo 5 imágenes por reclamo. | Maximum 5 images per claim. |
| `claim_invalid_image` | Imagen inválida. Permitidos: JPG, JPEG o PNG hasta 3 MB. | Invalid image. Allowed: JPG, JPEG or PNG up to 3 MB. |
| `claim_invalid_attachment` | Adjunto inválido. Permitidos: PDF, DOC, DOCX, XLS o XLSX hasta 3 MB. | Invalid attachment. Allowed: PDF, DOC, DOCX, XLS or XLSX up to 3 MB. |

Reutilizadas sin cambio: `missing_authorization`, `invalid_token`, `missing_field`, `invalid_field`, `condominium_access_denied`, `invalid_file_type`, `method_not_allowed`.

### Respuesta de éxito (201)

**El mismo objeto que `GET /api/v1/claims/%`**, envuelto igual (`data.claim`), transacciones siempre expandidas — así que la transacción inicial "Recibido" (SPEC 61) ya aparece, con su comentario automático, sin que el cliente tenga que pedirla aparte:

```json
{
  "success": true,
  "data": {
    "claim": {
      "id": 141,
      "subject": "Fuga de agua en el pasillo",
      "description": "Se ve una mancha de humedad que crece desde el lunes.",
      "status": "received",
      "claim_type": "claim",
      "visibility": "private",
      "reception_date": "2026-08-03T16:45:00",
      "created": "2026-08-03T16:45:01",
      "condominium_id": 7,
      "condominium_name": "Residencias El Parque",
      "requester_id": 34,
      "images": [
        { "id": 520, "url": "https://mi-sitio/api/v1/claims/141/files/520", "filename": "mancha.jpg" }
      ],
      "attachment": null,
      "transactions": [
        {
          "id": 88,
          "status": "received",
          "status_date": "2026-08-03T16:45:00",
          "comment": "Hemos recibido su reclamo. Será revisado por la administración y se le notificará cualquier novedad.",
          "created": "2026-08-03T16:45:01",
          "images": [],
          "attachment": null
        }
      ]
    }
  },
  "message": "Reclamo creado correctamente."
}
```

---

## Plan de implementación

1. **`includes/myapi.i18n.inc` — las cuatro claves nuevas.** `claim_created`, `claim_too_many_images`, `claim_invalid_image`, `claim_invalid_attachment`, en los bloques `es` y `en`, junto a las demás de `claim_*`. *Verificación: `myapi_t()` de las cuatro devuelve el texto correcto en ambos idiomas.*

2. **`resources/claim.resource.inc` — `myapi_claim_valid_catalogue_value($field_name, $value)`.** Helper puro: lee `field_info_field($field_name)['settings']['allowed_values']` y devuelve `TRUE`/`FALSE` según `array_key_exists()`. Un solo helper para `claim_type` y `visibility` — no dos copias de la misma lógica. *Verificación: `php -l`.*

3. **`resources/claim.resource.inc` — `myapi_claim_create_save_files($field_name, $bundle, $files, $max_count, $error_key)`.** El núcleo de la parte todo-o-nada: recibe el array de `$_FILES` ya remapeado (uno por índice, igual que el remapeo de `myapi_payment_save_file()` pero generalizado a N), valida el conteo contra `$max_count`, y por cada archivo llama `file_field_widget_upload_validators($field_name, field_info_instance('node', $field_name, $bundle))` + `file_save_upload()` sobre el directorio de `file_field_widget_uri()`, más el chequeo de MIME real con `finfo` (mismo patrón que `myapi_payment_save_file()`). En el primer fallo, borra con `file_delete()` todo lo guardado en esa misma llamada y responde `myapi_error($error_key, 422)` (o `invalid_file_type` si fue el MIME). Devuelve el array de objetos `file` guardados y permanentes. Sirve tanto para `images[]` (`$max_count = 5`) como para `attachment` (`$max_count = 1`), una sola función para los dos campos. *Verificación: `php -l`; probado a mano con 6 imágenes, con una de 4 MB, con un `.php` renombrado a `.jpg`, y con un `.jpg` real — los tres primeros casos no dejan ningún `file_managed` huérfano.*

4. **`resources/claim.resource.inc` — `myapi_claim_build_node($uid, $subject, $claim_type, $condominium_id, $description, $visibility, array $image_files, $attachment_file)`.** Construye el nodo `reclamo` sin guardar: `title`, `field_claim_type`, `field_condominium`, `field_requester = $uid`, `field_description`, `field_visibility`, `field_status = 'received'`, `field_reception_date = date('Y-m-d H:i:00')`, y los `fid` de `$image_files`/`$attachment_file` cuando existan. Sin guardar, para que el orquestador controle el orden frente a `file_usage_add()` — mismo patrón que `myapi_payment_build_node()`. *Verificación: `php -l`.*

5. **`resources/claim.resource.inc` — `myapi_claim_create()`.** Orquestación completa, en el orden de la sección de validación del Modelo de datos: auth → campos requeridos → `subject` → `claim_type` → `condominium_id` (incluye el `403`) → `description` → `visibility` → imágenes (paso 3, si `$_FILES['images']` trae algo) → adjunto (paso 3, si `$_FILES['attachment']` trae algo; si falla, borra también las imágenes ya guardadas) → `myapi_claim_build_node()` (paso 4) → `node_save()` (dispara `hook_node_insert()`, que crea sola la transacción inicial) → `file_usage_add()` por cada archivo → recarga con `myapi_claim_fetch($uid, $condos, ['nid' => $node->nid], 1, -1, 'desc')` + `myapi_claim_load_images()` + `myapi_claim_load_transactions($nids, TRUE)`, exactamente como `myapi_claim_detail()` → `myapi_respond(['claim' => myapi_claim_build_item(...)], 201, 'claim_created')`. *Verificación: `curl -F` con los campos mínimos crea el reclamo y la respuesta trae `transactions` con un elemento (`status: received`, el comentario automático); sin `Authorization`, `401`; con un `condominium_id` ajeno, `403`.*

6. **`resources/claim.resource.inc` — `myapi_claim_dispatch()`.** Una rama nueva: `POST` con `$id === NULL` → `myapi_claim_create()`. Todo lo demás del dispatcher queda igual, incluido el `405` para `POST /api/v1/claims/%`. *Verificación: `php -l`; `POST /api/v1/claims/141` responde `405`.*

7. **`docs/claim.md`.** Sección nueva `POST /api/v1/claims` con la plantilla de `CLAUDE.md`: método, auth, tabla de campos del `multipart/form-data`, ejemplo de respuesta `201`, tabla de errores completa (`401`, `403`, `405`, `422` con sus seis variantes). *Verificación: lectura contra la implementación.*

8. **`drush cc all` + matriz manual.** No hay `drush updb` — ningún esquema cambia. Recorrer los criterios de aceptación con un residente con una sola vivienda, uno con vivienda en dos condominios, y uno sin ninguna.

**Nota de orden:** los pasos 2 a 4 son helpers que todavía no responde ninguna ruta — el paso 6 es el que conecta `POST /api/v1/claims` a `myapi_claim_create()`. Partirlo así permite verificar cada helper con `php -l` antes de que exista el endpoint vivo.

---

## Criterios de aceptación

**Autenticación y método**

- [ ] `POST /api/v1/claims` sin `Authorization` → `401 missing_authorization`.
- [ ] Con un token inválido o expirado → `401 invalid_token`.
- [ ] `POST /api/v1/claims/{id}` (con id) → `405 method_not_allowed`, nunca crea nada.
- [ ] `GET`, `PUT`, `DELETE` sobre `api/v1/claims` siguen respondiendo como hoy (`GET` lista, el resto `405`) — este spec no les cambia el comportamiento.

**Campos requeridos**

- [ ] Falta `subject`, `claim_type`, `condominium_id`, `description` o `visibility` → `422 missing_field` con `@field` el que falte, y no se crea ningún nodo.
- [ ] `subject` de 256 caracteres o más → `422 invalid_field`.
- [ ] `subject` vacío o solo espacios → `422 missing_field` (o `invalid_field` tras `trim()`, según el helper), nunca crea el nodo con un título vacío.
- [ ] `claim_type` distinto de `requirement`/`claim` → `422 invalid_field`.
- [ ] `visibility` distinto de `private`/`public` → `422 invalid_field`.
- [ ] `description` vacío tras `trim()` → `422 invalid_field`.

**Condominio**

- [ ] `condominium_id` no numérico, `0` o negativo → `422 invalid_field`.
- [ ] `condominium_id` de un condominio ajeno (el usuario no tiene vivienda ahí) → `403 condominium_access_denied`.
- [ ] `condominium_id` de un condominio del usuario → el reclamo se crea con ese `field_condominium`.
- [ ] Un usuario sin ninguna vivienda asociada → cualquier `condominium_id` responde `403 condominium_access_denied`, nunca `422` ni `200`.

**Imágenes**

- [ ] Sin `images[]` → el reclamo se crea con `"images": []`.
- [ ] De 1 a 5 imágenes válidas (jpg/jpeg/png, ≤3 MB) → todas se guardan y aparecen en `images`, en el mismo orden en que se enviaron.
- [ ] 6 imágenes → `422 claim_too_many_images`, ninguna se guarda, no se crea el nodo.
- [ ] Una imagen de 4 MB entre varias válidas → `422 claim_invalid_image`, **ninguna** de las imágenes de esa request queda guardada (todo-o-nada), no se crea el nodo.
- [ ] Un archivo `.webp` → `422 claim_invalid_image` (extensión no permitida; SPEC 55 sigue sin ampliarse).
- [ ] Un `.php` renombrado a `.jpg` → `422 invalid_file_type` (el `finfo` real lo detecta), no se guarda.

**Adjunto**

- [ ] Sin `attachment` → el reclamo se crea con `"attachment": null`.
- [ ] Un adjunto válido (pdf/doc/docx/xls/xlsx, ≤3 MB) → se guarda y aparece en `attachment`.
- [ ] Un adjunto de tipo o tamaño inválido → `422 claim_invalid_attachment`, y si ya se habían guardado imágenes válidas en la misma request, también se borran — no queda ningún archivo huérfano y no se crea el nodo.
- [ ] Un segundo archivo enviado en el campo `attachment` (más de uno) se ignora o rechaza sin crear dos adjuntos — cardinalidad 1 respetada.

**Creación y transacción inicial**

- [ ] El nodo se crea con `status = 1` (publicado), `field_requester` = el `uid` del token, sin importar qué haya mandado el cliente (el campo no se lee del request).
- [ ] `field_status` queda en `received` inmediatamente después de crear, sin depender de `default_value`.
- [ ] `field_reception_date` queda con la fecha y hora del servidor en el momento de la creación, no una enviada por el cliente (no hay tal campo en el request).
- [ ] Tras el `POST`, existe exactamente **una** transacción (`claim_transaction`) para ese reclamo, con `status: received` y el comentario automático de SPEC 61.
- [ ] Esa transacción aparece en `GET /api/v1/claims/{id}` inmediatamente después, con el mismo `id` que trajo la respuesta del `POST`.

**Respuesta**

- [ ] Éxito → `201`, con `data.claim` teniendo exactamente las mismas claves y tipos que `GET /api/v1/claims/%`, y `message` traducido (`claim_created`).
- [ ] Las `url` de `images`/`attachment` en la respuesta del `201` ya son las autenticadas de `GET /api/v1/claims/%/files/%` (SPEC 65) y descargan con el mismo token.
- [ ] `transactions` viene expandido (objetos, no ints) en la respuesta del `201`, igual que en el detalle — sin necesidad de `?include=transactions`.

**No regresión**

- [ ] `GET /api/v1/claims` y `GET /api/v1/claims/%` no cambian ninguna clave, tipo ni código de estado respecto a SPEC 64/65.
- [ ] `GET /api/v1/claims/%/files/%` no cambia.
- [ ] `myapi.install` no aparece en el diff: ningún campo, tabla ni bundle nuevo.
- [ ] `myapi.module` no aparece en el diff: no hay rutas nuevas ni cambios en `hook_menu()`.
- [ ] `node/add/reclamo` (back office) sigue funcionando igual: mismos campos, mismos validadores, mismo comportamiento.
- [ ] Crear un reclamo desde `node/add/reclamo` (back office) y desde `POST /api/v1/claims` (app) produce el mismo tipo de transacción inicial, por la misma función (`myapi_claim_transaction_create_initial()`), sin lógica duplicada.
- [ ] `drush cc all` no reporta errores.

**Documentación**

- [ ] `docs/claim.md` documenta `POST /api/v1/claims` con la plantilla de `CLAUDE.md`: método, auth, campos, respuesta, tabla de errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Quién es `solicitante` | Siempre el `uid` del token; no es un campo del request | El cliente envía `requester_id` y se valida contra el condominio de ese usuario | Un campo que el cliente puede mandar pero que en la práctica debe coincidir con quien está autenticado es una superficie de ataque sin beneficio: alguien podría intentar radicar reclamos "a nombre de" otro residente. Quitarlo del contrato es más simple de documentar y cierra esa puerta de raíz. |
| Soporte de `webp` | No se añade; las imágenes se quedan en `png jpg jpeg` (SPEC 55, sin cambios) | Ampliar `field_images` con una migración (`myapi_update_70XX`) | Decisión explícita del usuario: solo permitir lo que ya está. Evita una migración y una superficie de código adicional para un tipo de archivo no soportado hoy por ningún consumidor del módulo. |
| Límite de conteo de imágenes | Se aplica en el endpoint (`myapi_claim_create_save_files()`), no en el campo | Poner `cardinality = 5` en `field_images` | `field_images` es compartido por `reclamo` y `claim_transaction` (SPEC 55), y el formulario nativo del back office (`myapi_claim_transaction_create_form()`, SPEC 57) no tiene ese límite pedido. Cambiar la cardinalidad del campo lo limitaría también ahí, sin que se haya pedido. |
| Validadores de archivo | Reutilizar `file_field_widget_upload_validators()` / `file_field_widget_uri()` leyendo `field_info_field()`/`field_info_instance()` | Hardcodear extensiones y tamaños en el recurso, como hace `myapi_payment_save_file()` | `field_images`/`field_attachment` de `reclamo` **son** campos de Field API con instancia propia — a diferencia de `field_archivo` en `pagos`, que en `payment.resource.inc` se valida a mano. Leer la instancia es lo que ya hace `myapi_claim_transaction_create_form()` (SPEC 57) para el mismo campo, y evita que este endpoint se desincronice si alguien ajusta el límite desde la UI. |
| Fallo parcial en `images[]`/`attachment` | Todo o nada: cualquier archivo inválido aborta la request completa y borra lo ya guardado | Crear el reclamo con las imágenes válidas e informar las rechazadas | Decisión explícita del usuario. Es además más simple de razonar para el cliente: o el reclamo se creó tal cual se envió, o no se creó nada — sin estados intermedios que reconciliar. |
| Error del condominio ajeno | `403 condominium_access_denied`, reutilizada | `422 invalid_field` | Decisión explícita del usuario. Es semánticamente el mismo caso que ya resuelve `myapi_claim_parse_condominium_id()` en el listado: un condominio que existe pero no es del usuario, no un dato mal formado. |
| Error de `claim_type`/`visibility` inválidos | `422 invalid_field` genérico | Claves dedicadas (`invalid_claim_type`, `invalid_visibility`) | Decisión explícita del usuario. Menos claves i18n que mantener; mismo patrón que `unit_id`/`amount` en `payment.resource.inc`. |
| `field_status` en la creación | Se escribe explícitamente `'received'` | Confiar en el `default_value` del campo | Un `node_save()` programático no pasa por `field_default_form()`, así que `default_value` nunca se aplica solo. Sin esta línea, el nodo se crearía con `field_status` vacío pese a que el campo es requerido, y `myapi_claim_transaction_create_initial()` copiaría ese vacío a la transacción inicial. |
| `field_reception_date` en la creación | `date('Y-m-d H:i:00')` del servidor, siempre | Aceptar una fecha del cliente | Pedido explícitamente en el enunciado ("fecha y hora del servidor"). Mismo formato con segundos fijados a `:00` que ya usa `myapi_claim_transaction_create_initial()` para el mismo tipo de campo. |
| Transacción inicial | No se escribe código nuevo: `hook_node_insert()` (SPEC 57) ya la crea para todo `reclamo` insertado | Llamar explícitamente a `myapi_claim_transaction_create_initial()` desde `myapi_claim_create()` | Llamarla a mano duplicaría la creación (el hook la dispararía de todos modos) o exigiría una bandera para que el hook no la duplique — complejidad sin beneficio. El hook ya cubre "cualquier camino de creación programático", que es exactamente este caso. |
| Forma de la respuesta `201` | El mismo objeto que `GET /api/v1/claims/%`, reutilizando `myapi_claim_build_item()` | Un objeto reducido solo con los campos enviados | Decisión explícita del usuario. Le ahorra al cliente un segundo `GET` para conocer el `id` de la transacción inicial y las URLs autenticadas de los archivos recién subidos. |
| Ubicación del código nuevo | Todo dentro de `resources/claim.resource.inc` | Un include nuevo en `includes/` | Nadie más que este recurso crea reclamos; no hay un segundo consumidor todavía. Mismo criterio que SPEC 64 aplicó a los helpers propios del listado. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Archivos temporales huérfanos si el proceso PHP muere entre `file_save_upload()` y el `myapi_error()` de limpieza** (timeout, OOM). | Riesgo aceptado y de bajo impacto: son archivos `FILE_STATUS_PERMANENT` sin ninguna referencia en `field_data_*`, así que `hook_cron`'s file garbage collection de Drupal los recoge igual que cualquier subida abandonada. No es un caso nuevo introducido por este spec — ya existe hoy con `myapi_payment_save_file()`. |
| **Un `condominium_id` válido pero de un condominio despublicado.** `myapi_condominium_related_nids()` no filtra por `status` del condominio. | Mismo comportamiento que ya tiene el listado (`myapi_claim_parse_condominium_id()`): no es una regresión de este spec, y corregirlo es un cambio a una función compartida, no de este endpoint. |
| **Carga de 5 imágenes de 3 MB cada una en un solo request** (hasta ~15 MB más el adjunto) puede chocar con `upload_max_filesize`/`post_max_size` de PHP, mal configurados. | Documentado en `docs/claim.md` como prerrequisito de entorno, igual que SPEC 65 documentó `file_private_path`. Un límite de PHP demasiado bajo produce un `$_FILES` vacío o truncado, que este endpoint ya trata como "no se envió archivo" o falla la validación — no como un 500. |
| **`file_field_widget_upload_validators()` depende de la instancia vigente del campo.** Si alguien cambia `max_filesize`/`file_extensions` desde la UI de Drupal, este endpoint lo hereda sin aviso — para bien (se sincroniza solo) y para mal (un cambio no coordinado con la app cambia silenciosamente lo que la API acepta). | Es el trade-off ya aceptado en la fila de Decisiones. Si se necesita fijar los límites independientemente del campo, es un spec futuro. |

---

## Lo que **NO** está en este spec

- Editar, cerrar, eliminar un reclamo, o añadir una transacción desde la app.
- Notificaciones al crear un reclamo.
- Rate limiting / flood control sobre la creación.
- Soporte de `webp`, o cualquier cambio de `myapi.install`.
- Aceptar `solicitante` como campo del request.

Cada uno de ellos, si llega, va en su propio spec.
