# 20 — Registrar un pago (`POST /api/v1/payments`)

- **Estado:** Approved
- **Fecha:** 2026-07-09
- **Dependencias:**
  - `01-bootstrap-modulo` (Implemented) — esqueleto del módulo, `hook_menu()`, envelope de respuesta y helpers `myapi_respond()` / `myapi_error()`.
  - `05-middleware-access-token-logout` (Implemented) — `myapi_auth_require_access_token()` que valida el Bearer access token y devuelve la fila (con `uid`), o corta con `401`.
  - `09-units-owner-occupant` (Implemented) — helper `myapi_unit_related_nids($uid)` (en `includes/myapi.unit_access.inc`) para verificar que el usuario sea propietario u ocupante de la vivienda.
  - `14-unit-payments-list` (Implemented) — recurso `payment.resource.inc` y forma del item de pago que este endpoint reutiliza/amplía; la ruta de creación vive en el mismo archivo de recurso.
  - `18-banks-list` (Implemented) — vocabulario `bancos` cuyo `tid` se valida al asignar `field_banco`.
- **Objetivo:** Agregar `POST /api/v1/payments`, un endpoint **autenticado** que crea un nodo `pagos` a partir de una petición `multipart/form-data`, validando que el usuario autenticado sea propietario u ocupante de la vivienda destino, con `field_estado_pago` **siempre forzado** a `"Pendiente de verificar"`, `field_vivienda`/`field_referencia`/`field_valor` (>0)/`field_forma_de_pago` (contra `allowed_values`)/`field_banco` (tid válido de `bancos`) obligatorios, `field_fecha_de_pago` opcional (default: hora del servidor), un archivo adjunto opcional (`pdf`/`jpg`/`jpeg`/`png`, ≤ 5 MB, guardado como managed file en `private://comprobantes_pago/`), y rechazando una `field_referencia` repetida en la misma vivienda con `409`.

---

## Alcance

### Dentro de este spec

- **`resources/payment.resource.inc`** (modificar — ya existe con el listado GET) — se agrega la lógica de creación:
  - **Nuevo page callback `myapi_payment_create_dispatch()`** para la ruta plana `api/v1/payments`: enruta por método; solo `POST` → `myapi_payment_create()`; cualquier otro → `myapi_error('method_not_allowed', 405)`. (El dispatcher existente `myapi_payment_dispatch($unit_id)` de la ruta anidada GET **no se toca**.)
  - **`myapi_payment_create()`** — exige access token (`myapi_auth_require_access_token()`), parsea el `multipart/form-data`, valida todos los campos, verifica acceso a la vivienda, comprueba referencia duplicada, guarda el archivo opcional, crea el nodo `pagos` con `node_save()` y responde `201` con el pago completo.
  - **Helpers de creación** en el mismo archivo: construcción/validación de campos, y mapeo del nodo creado a la forma de respuesta (`myapi_payment_build_created_item()`).
- **`includes/myapi.request.inc`** (modificar) — helper reutilizable para leer un campo de `multipart/form-data` (`$_POST`) de forma segura (`myapi_request_post_field($name)`), para no acceder a `$_POST` crudo desde el recurso. La subida/validación del archivo (`$_FILES` + validators) vive en el recurso.
- **`myapi.module`** (modificar) — registrar `api/v1/payments` en `hook_menu()` con `page callback => myapi_payment_create_dispatch`, `access callback => TRUE`, `type => MENU_CALLBACK`, `file => resources/payment.resource.inc`. La autenticación se resuelve dentro del recurso.
- **`includes/myapi.i18n.inc` / `docs/i18n.md`** (modificar) — nuevas claves de error/éxito del catálogo (ver plan, paso 8).
- **`docs/payment.md`** (modificar) — agregar la sección `POST /api/v1/payments` siguiendo la plantilla (auth requerida, headers multipart, tabla de campos del body, archivo, respuesta `201`, tabla de errores).
- **`myapi.info`** — **sin cambios**: `resources/payment.resource.inc` ya está listado.

### Fuera de este spec

- **Editar / borrar un pago** (`PUT` / `DELETE /api/v1/payments/%`) — solo se crea; la modificación y el borrado quedan para otro spec.
- **Cambiar el estado del pago** (verificar/rechazar el comprobante) — `field_estado_pago` se crea siempre en `"Pendiente de verificar"`; el flujo de verificación posterior es otro spec.
- **Descargar / servir el archivo adjunto** — se guarda en `private://` y se devuelve una referencia (`file_url`/`file_id`), pero el endpoint de descarga autenticada del comprobante no se define aquí.
- **`field_detalle`** — no se acepta ni se guarda, aunque el front lo mande.
- **Múltiples archivos** — se acepta como máximo **un** archivo adjunto (`field_archivo` es de valor único); no hay carga múltiple.
- **La ruta anidada `POST /api/v1/units/%/payments`** — la creación es plana (`vivienda` en el body); no se añade una ruta de creación anidada.
- **El listado de pagos** (`GET /api/v1/units/%/payments`, spec 14) — no se modifica su lógica; solo comparten archivo de recurso.
- **Deduplicación global de referencias** — la unicidad es **por vivienda**, no global.
- **Notificaciones** (correo/push al administrador cuando entra un pago por verificar) — fuera de alcance.

---

## Modelo de datos

Este spec **no introduce tablas propias** (`myapi_*`), no hay `hook_schema()` ni cambios en `myapi.install`. Escribe sobre estructuras existentes de Drupal:

- Un nodo `pagos` (tabla `node` + tablas `field_data_field_*` de cada campo) creado con `node_save()`.
- Un archivo gestionado (`file_managed` + `file_usage`) cuando se adjunta comprobante, creado con `file_save_upload()` / `file_save()`.

### Entrada — campos del `multipart/form-data`

| Campo (form-data) | Destino Drupal | Oblig. | Tipo | Validación / regla |
|---|---|---|---|---|
| `field_vivienda` | `field_vivienda` (entity ref → nodo) | **Sí** | int (nid) | Entero > 0; el nodo debe existir, ser tipo vivienda y estar publicado; el usuario autenticado debe ser propietario u ocupante (`myapi_unit_related_nids`). Si no existe/tipo erróneo → `422`; sin acceso → `403`. |
| `field_referencia` | `field_referencia` (text) | **Sí** | string | No vacío, `check_plain`, ≤ 255. No debe existir otro pago con la misma referencia **en esa vivienda** (si existe → `409 duplicate_reference`). |
| `field_valor` | `field_valor` (decimal) | **Sí** | decimal | Numérico y **> 0**; se castea a decimal. `<= 0` o no numérico → `422`. |
| `field_forma_de_pago` | `field_forma_de_pago` (list_text) | **Sí** | string | Debe ser una clave de `allowed_values` del campo (leídas con `field_info_field`). Valor fuera de la lista → `422`. |
| `field_banco` | `field_banco` (taxonomy term ref) | **Sí** | int (tid) | Entero > 0; `taxonomy_term_load($tid)` debe existir y pertenecer al vocabulario `bancos`. Si no → `422`. |
| `field_fecha_de_pago` | `field_fecha_de_pago` (date ISO) | No | string ISO | Opcional. Acepta `YYYY-MM-DD` (→ `T00:00:00`) o `YYYY-MM-DDTHH:MM:SS`. Formato/fecha inválidos → `422`. **Default (ausente): hora exacta del servidor** `date('Y-m-d\TH:i:s')`. |
| `field_archivo` (`$_FILES`) | `field_archivo` (file) | No | archivo | Opcional. Extensiones `pdf jpg jpeg png`, ≤ 5 MB, **y MIME real** en `{application/pdf, image/jpeg, image/png}`. Se guarda en `private://comprobantes_pago/`. Inválido → `422`. |
| `field_estado_pago` | `field_estado_pago` (list_text) | — | — | **Ignorado en la entrada.** Siempre se fuerza a `"Pendiente de verificar"` aunque el front mande otro valor. |
| `field_detalle` | — | — | — | **Ignorado.** No se acepta ni se guarda. |

### Valores fijados por el servidor

| Campo Drupal | Valor |
|---|---|
| `node->type` | `pagos` |
| `node->title` | `"Pago " . $referencia . " - " . <fecha YYYY-MM-DD>` (autogenerado; el front no lo manda) |
| `node->uid` | `uid` del usuario autenticado (autor del nodo) |
| `node->status` | `1` (publicado) |
| `node->language` | `LANGUAGE_NONE` |
| `field_estado_pago` | `"Pendiente de verificar"` (forzado) |
| `field_fecha_de_pago` | valor del front si es válido, si no la hora del servidor |

### Archivo adjunto (managed file)

| Aspecto | Regla |
|---|---|
| Destino | `private://comprobantes_pago/` (se crea el directorio si no existe con `file_prepare_directory(..., FILE_CREATE_DIRECTORY)`). |
| Persistencia | Managed file: `status = FILE_STATUS_PERMANENT`, `file_usage_add($file, 'myapi', 'node', $nid)` para que Drupal no lo borre como temporal. |
| Estructura en `field_archivo` | `[LANGUAGE_NONE][0] = ['fid' => $file->fid, 'display' => 1]`. |
| Validadores | `file_validate_extensions` → `'pdf jpg jpeg png'`; `file_validate_size` → `5*1024*1024`; comprobación adicional de MIME real con `finfo`. |

### Forma de respuesta (`201`)

```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 87,
      "title": "Pago 000123 - 2026-07-09",
      "unit_id": 12,
      "payment_date": "2026-07-09T14:30:00",
      "status": "Pendiente de verificar",
      "payment_method": "Transferencia",
      "reference": "000123",
      "amount": 45.90,
      "bank_id": 7,
      "file_id": 55,
      "file_url": "private://comprobantes_pago/000123.pdf"
    }
  },
  "message": "Pago registrado correctamente."
}
```

> `file_id` y `file_url` son `null` cuando no se adjunta archivo. Se exponen ambos (`fid` interno + URI `private://…`) para que un futuro endpoint de descarga autenticada del comprobante disponga del `fid`; la URI `private://…` no es descargable directamente por el cliente.

---

## Plan de implementación

1. **Registrar la ruta en `myapi.module`** (`hook_menu()`):
   ```php
   $items['api/v1/payments'] = [
     'page callback'   => 'myapi_payment_create_dispatch',
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/payment.resource.inc',
   ];
   ```
   La autenticación se resuelve dentro del recurso. (Tras esto, `drush cc all`.)

2. **Helper de lectura de campos multipart en `includes/myapi.request.inc`:**
   - `myapi_request_post_field($name)` → devuelve `$_POST[$name]` trim-eado si es string escalar, o `NULL`. Evita tocar `$_POST` crudo desde el recurso. (No parsea JSON: en `multipart/form-data` los campos llegan por `$_POST`, no por `php://input`.)

3. **`myapi_payment_create_dispatch()`** en `payment.resource.inc`:
   - `myapi_request_method()`; si `POST` → `myapi_payment_create()`; si no → `myapi_error('method_not_allowed', 405)`.

4. **`myapi_payment_create()`** — orquesta, en este orden (cada validación corta con su error antes de crear nada):
   1. `$row = myapi_auth_require_access_token(); $uid = $row->uid;` (corta `401`).
   2. Leer campos con `myapi_request_post_field()`: `field_vivienda`, `field_referencia`, `field_valor`, `field_forma_de_pago`, `field_banco`, `field_fecha_de_pago` (opcional).
   3. **Obligatorios presentes**: si falta alguno de los 5 obligatorios → `myapi_error('missing_field', 422, ['@field' => ...])`.
   4. **`field_vivienda`**: entero > 0; cargar el nodo; debe existir, ser tipo vivienda y estar publicado → si no `422 invalid_field`. Verificar acceso: `if (!in_array($vivienda_nid, myapi_unit_related_nids($uid), TRUE)) myapi_error('unit_access_denied', 403);`.
   5. **`field_valor`**: `is_numeric` y `> 0` → si no `422 invalid_amount`.
   6. **`field_referencia`**: string no vacío, ≤ 255 → `422` si no.
   7. **`field_forma_de_pago`**: leer `field_info_field('field_forma_de_pago')['settings']['allowed_values']`; si el valor no es una clave → `422 invalid_payment_method`.
   8. **`field_banco`**: entero > 0; `taxonomy_term_load($tid)`; debe existir y `$term->vid == vid('bancos')` → si no `422 invalid_bank`.
   9. **`field_fecha_de_pago`**: si viene, validar formato (`YYYY-MM-DD` o `YYYY-MM-DDTHH:MM:SS`, `checkdate`) → `422 invalid_date`; normalizar a `Y-m-d\TH:i:s`. Si no viene → `date('Y-m-d\TH:i:s')`.
   10. **Referencia duplicada (por vivienda)**: `db_select` sobre `field_data_field_referencia` join `field_data_field_vivienda` buscando otro nodo `pagos` con esa referencia y ese `field_vivienda_target_id`; si existe → `myapi_error('duplicate_reference', 409)`.
   11. **Archivo (si `$_FILES['field_archivo']` está presente y sin error de subida)**: `myapi_payment_save_file()` (paso 5). Si es inválido → `422` (extensión/tamaño/MIME).
   12. **Crear el nodo** (paso 6) → `node_save($node)`.
   13. Si hubo archivo: `file_usage_add($file, 'myapi', 'node', $node->nid)`.
   14. Responder `myapi_respond(['payment' => myapi_payment_build_created_item($node, $file)], 201, 'payment_created')`.

5. **`myapi_payment_save_file()`** — subida y validación del adjunto:
   - Preparar directorio: `file_prepare_directory('private://comprobantes_pago', FILE_CREATE_DIRECTORY)`.
   - `$validators = ['file_validate_extensions' => ['pdf jpg jpeg png'], 'file_validate_size' => [5*1024*1024]];`
   - `$file = file_save_upload('field_archivo', $validators, 'private://comprobantes_pago/');` → si `FALSE`/errores → `422 invalid_file`.
   - **MIME real**: `finfo_file()` sobre `$file->uri` debe estar en `{application/pdf, image/jpeg, image/png}`; si no, borrar y `422 invalid_file_type`.
   - Marcar permanente: `$file->status = FILE_STATUS_PERMANENT; file_save($file);` → devolver `$file`.

6. **Construcción del nodo `pagos`** (inline en `create()` o `myapi_payment_build_node()`):
   ```php
   $node = new stdClass();
   $node->type = 'pagos';
   node_object_prepare($node);
   $node->uid = $uid;
   $node->status = 1;
   $node->language = LANGUAGE_NONE;
   $node->title = 'Pago ' . $referencia . ' - ' . substr($fecha, 0, 10);
   $node->field_vivienda[LANGUAGE_NONE][0]['target_id']   = $vivienda_nid;
   $node->field_referencia[LANGUAGE_NONE][0]['value']     = $referencia;
   $node->field_valor[LANGUAGE_NONE][0]['value']          = $valor;
   $node->field_forma_de_pago[LANGUAGE_NONE][0]['value']  = $forma_de_pago;
   $node->field_banco[LANGUAGE_NONE][0]['tid']            = $banco_tid;
   $node->field_fecha_de_pago[LANGUAGE_NONE][0]['value']  = $fecha;
   $node->field_estado_pago[LANGUAGE_NONE][0]['value']    = 'Pendiente de verificar';
   if ($file) {
     $node->field_archivo[LANGUAGE_NONE][0] = ['fid' => $file->fid, 'display' => 1];
   }
   node_save($node);
   ```

7. **`myapi_payment_build_created_item($node, $file)`** — mapea a la forma de respuesta: `id`, `title`, `unit_id`, `payment_date`, `status`, `payment_method`, `reference`, `amount` (float), `bank_id` (int), `file_id` (`$file ? (int) $file->fid : NULL`), `file_url` (`$file ? $file->uri : NULL`).

8. **Catálogo i18n**: agregar las claves de error/éxito nuevas (`myapi.i18n.inc` / `docs/i18n.md`): `invalid_amount`, `invalid_payment_method`, `invalid_bank`, `invalid_date`, `duplicate_reference`, `invalid_file`, `invalid_file_type`, `payment_created` (mensaje de éxito), reutilizando `missing_field` / `invalid_field` / `unit_access_denied` / `method_not_allowed` ya existentes.

9. **Documentar en `docs/payment.md`**: nueva sección `POST /api/v1/payments` (auth requerida, `Content-Type: multipart/form-data`, tabla de campos, reglas del archivo, respuesta `201`, tabla de errores `401`/`403`/`405`/`409`/`422`).

10. **Aplicar y verificar**: `drush cc all` + `curl -F` sobre los casos de la sección de aceptación (con y sin archivo, campos inválidos, referencia duplicada, vivienda ajena).

---

## Criterios de aceptación

**Éxito**
- [ ] `POST /api/v1/payments` (multipart) con token válido, todos los obligatorios correctos y **sin** archivo devuelve `201` con `{ "success": true, "data": { "payment": {...} }, "message": ... }`.
- [ ] El mismo caso **con** un archivo `pdf`/`jpg`/`jpeg`/`png` ≤ 5 MB devuelve `201` y `payment.file_id` es un entero y `payment.file_url` apunta a `private://comprobantes_pago/…`.
- [ ] Sin archivo, `payment.file_id` y `payment.file_url` son `null`.
- [ ] El nodo creado es tipo `pagos`, publicado, con `title = "Pago {referencia} - {YYYY-MM-DD}"` y `uid` = usuario autenticado.
- [ ] `field_estado_pago` del nodo es `"Pendiente de verificar"` **aunque** la petición mande otro valor en `field_estado_pago`.
- [ ] Enviar `field_detalle` no lo persiste (el nodo no tiene detalle).
- [ ] Sin `field_fecha_de_pago`, se guarda la hora del servidor (`Y-m-d\TH:i:s`).
- [ ] Con `field_fecha_de_pago = "2026-07-09"` se guarda `2026-07-09T00:00:00`; con `"2026-07-09T14:30:00"` se guarda tal cual.
- [ ] El archivo guardado es permanente (`FILE_STATUS_PERMANENT`) y tiene una fila en `file_usage` para el nodo (no se borra como temporal).

**Autenticación y acceso**
- [ ] Sin header `Authorization` → `401 missing_authorization`; con token inválido/expirado → `401 invalid_token`.
- [ ] `field_vivienda` de una vivienda que el usuario **no** posee ni ocupa → `403 unit_access_denied` y no se crea nada.

**Validación (todas cortan con el error indicado y no crean nodo ni archivo)**
- [ ] Falta cualquiera de `field_vivienda`, `field_referencia`, `field_valor`, `field_forma_de_pago`, `field_banco` → `422 missing_field` con el `@field`.
- [ ] `field_vivienda` inexistente, no publicada o de un tipo distinto de vivienda → `422 invalid_field`.
- [ ] `field_valor` no numérico, `0` o negativo → `422 invalid_amount`.
- [ ] `field_forma_de_pago` que no está en los `allowed_values` del campo → `422 invalid_payment_method`.
- [ ] `field_banco` que no existe o no pertenece al vocabulario `bancos` → `422 invalid_bank`.
- [ ] `field_fecha_de_pago` con formato inválido o fecha imposible → `422 invalid_date`.
- [ ] Archivo con extensión fuera de `pdf/jpg/jpeg/png` → `422` (extensión); archivo > 5 MB → `422` (tamaño); archivo con MIME real fuera de `{application/pdf, image/jpeg, image/png}` (p. ej. `.php` renombrado a `.pdf`) → `422 invalid_file_type`.

**Duplicados y método**
- [ ] Una `field_referencia` que ya existe en **esa misma vivienda** → `409 duplicate_reference`; la misma referencia en **otra** vivienda **sí** se permite (`201`).
- [ ] Cualquier método distinto de `POST` sobre `/api/v1/payments` (`GET`, `PUT`, `DELETE`) → `405 method_not_allowed`.

**No regresión / infra**
- [ ] `GET /api/v1/units/%/payments` (spec 14) sigue respondiendo idéntico; solo comparten archivo de recurso.
- [ ] Todas las claves de error/éxito nuevas están en el catálogo i18n y traducen en `es`/`en`.
- [ ] `docs/payment.md` incluye la sección `POST /api/v1/payments` completa y `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Transporte de la petición | `multipart/form-data` (`$_POST` + `$_FILES`) | JSON con archivo en base64 | Estándar HTTP para subir archivos; lo que Flutter maneja nativo (`MultipartRequest`). Base64 infla ~33% y arriesga el límite de 5 MB en memoria. |
| Ruta | Plana `POST /api/v1/payments`, `field_vivienda` en el body | Anidada `POST /api/v1/units/%/payments` | Elección del usuario. La creación queda como recurso independiente; el acceso a la vivienda se valida igual. |
| Control de acceso | Solo propietario/ocupante (`myapi_unit_related_nids`) → `403` si no | Cualquier usuario autenticado | No permitir registrar pagos en viviendas ajenas; mismo criterio que el GET de payments/receipts. |
| Almacenamiento del archivo | `private://comprobantes_pago/` como managed file permanente | `public://` | Un comprobante es sensible (montos, cuentas); no debe ser accesible por URL pública directa. |
| `field_estado_pago` | **Forzado** a `"Pendiente de verificar"`, se ignora la entrada | Aceptar el estado del front / default configurable | Elección del usuario: un pago recién registrado siempre entra por verificar. |
| Validación de `field_forma_de_pago` | Contra `allowed_values` del campo (`field_info_field`) → `422` | Aceptar cualquier string no vacío | Impide guardar formas de pago inválidas aunque el front falle o se llame la API directamente. |
| Validación de `field_banco` | Verificar que el `tid` exista y sea del vocabulario `bancos` → `422` | Aceptar cualquier entero > 0 | Evita referencias a términos inexistentes o de otro vocabulario. |
| Título del nodo | Autogenerado `"Pago {referencia} - {YYYY-MM-DD}"` | Pedirlo al front / usar solo la referencia | Los nodos exigen `title`; el front no lo maneja. Autogenerado es descriptivo en el admin sin campo extra. |
| `field_detalle` | Ignorado por completo | Aceptarlo como opcional | El usuario no lo incluyó; se deja fuera para no ampliar el contrato. |
| `field_fecha_de_pago` | Opcional; el front puede sobreescribir, default = hora del servidor | Siempre la hora del servidor | Elección del usuario: flexibilidad para registrar pagos con fecha propia. |
| Formato de fecha aceptado | `YYYY-MM-DD` (→ `T00:00:00`) **y** `YYYY-MM-DDTHH:MM:SS` | Solo datetime ISO completo | Más cómodo para el front sin perder validación estricta. |
| Unicidad de `field_referencia` | **Por vivienda** → `409` si se repite | Global / sin unicidad | Elección del usuario: evita registrar dos veces el mismo comprobante en una vivienda, pero permite que viviendas distintas coincidan. |
| Código de duplicado | `409 duplicate_reference` | `422` | Semántica correcta: conflicto con un recurso existente, no un error de forma del input. |
| Validación del archivo | Extensión + tamaño (validators de Drupal) **+ MIME real** (`finfo`) | Solo extensión + tamaño | Drupal 7 es EOL; verificar el MIME real evita un `.php` renombrado a `.pdf`. |
| Respuesta de éxito | `201` con el pago completo + `file_id` y `file_url` | `201` solo con el `id` | El front puede pintar el pago sin otra llamada; se exponen ambos (`fid` + URI) para un futuro endpoint de descarga. |
| Ubicación de la creación | En el mismo `payment.resource.inc` (page callback aparte) | Nuevo archivo de recurso | Un recurso = un archivo (CLAUDE.md); crear y listar pagos son el mismo recurso. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`private://` no configurado en el sitio.** Si el filesystem privado no está definido, `file_prepare_directory('private://…')` falla y la subida se cae. | Si el directorio no se puede preparar → `422 invalid_file` (o `500` controlado) con mensaje claro, sin crear el nodo. Documentar en `docs/payment.md` que el sitio requiere el private file system habilitado. La creación **sin** archivo no depende de esto. |
| **Machine name real del tipo de contenido vivienda.** El código asume `pagos` para el pago, pero el tipo de la vivienda referenciada podría ser `vivienda`, `viviendas` u otro. Validar por un machine name equivocado rechazaría todo con `422`. | Confirmar el machine name del bundle vivienda antes de implementar; la validación de tipo se ajusta en un punto único. Señal de diagnóstico clara: si toda creación válida sale `422 invalid_field`, el bundle esperado es otro. |
| **`allowed_values` de `field_forma_de_pago` compara por clave, no por etiqueta.** El front podría mandar la etiqueta visible en vez de la clave almacenada. | La doc especifica que se envía la **clave** de `allowed_values`; el `422 invalid_payment_method` lo hace explícito. Si el front usa etiquetas, se ajusta la comparación en un fix puntual. |
| **Orden de creación nodo/archivo y huérfanos.** Si el archivo se guarda permanente pero `node_save()` falla después, el archivo queda sin usage (huérfano). | Guardar el archivo justo antes de `node_save()` y agregar `file_usage_add()` **después** del save exitoso; ante fallo de `node_save()`, el archivo sin usage lo recoge el cron de Drupal (managed sin uso). Aceptado como caso raro. |
| **Condición de carrera en la referencia duplicada.** Dos peticiones simultáneas con la misma referencia/vivienda podrían pasar ambas la comprobación previa y crear dos pagos. | Aceptado: sin índice único a nivel de BD (Field API no lo provee), la comprobación es best-effort. La probabilidad es baja (misma vivienda, misma referencia, mismo instante); si se vuelve crítico, se añade un índice/lock en otro spec. |
| **Tamaño de subida limitado por PHP.** `upload_max_filesize`/`post_max_size` de PHP pueden ser menores a 5 MB y cortar la subida antes de llegar al validador. | El validador `file_validate_size` cubre el límite lógico; si PHP corta antes, `$_FILES` trae el error de subida y se responde `422 invalid_file`. Documentar el requisito de config PHP ≥ 5 MB. |
| **Drupal 7 EOL — entrada no confiable.** Todo el input viaja por multipart y se persiste. | Validación estricta de cada campo (tipos, rangos, allowed_values, tid real, MIME real), `check_plain` en strings, `node_save()`/Field API (no SQL crudo para escribir). |
