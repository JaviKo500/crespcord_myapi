# SPEC 67 — Edición de un reclamo por su solicitante (`POST /api/v1/claims/%`)

> **Estado:** Implemented · **Depende de:** SPEC 55 (bundle `reclamo` y sus campos), SPEC 64 (`myapi_claim_fetch()`, `myapi_claim_build_item()`, `myapi_claim_dispatch()`), SPEC 65 (ficheros en `private://`, `myapi_claim_build_file()`), SPEC 66 (`myapi_claim_create()`, `myapi_claim_create_save_files()`, `myapi_claim_valid_catalogue_value()`) · **Fecha:** 2026-08-04
> **Objetivo:** Añadir `POST /api/v1/claims/%`, que permite al **solicitante** de un reclamo modificar todos sus campos y sus archivos mientras su estado siga siendo `received`, devolviendo el mismo objeto que `GET /api/v1/claims/%`.

Tres notas técnicas que fija la cabecera, porque condicionan el resto del documento:

- **`POST` sobre el item, no `PUT`.** PHP no rellena `$_POST` ni `$_FILES` en un `PUT`: el cuerpo `multipart/form-data` llegaría crudo por `php://input` y habría que escribir un parser MIME a mano. `POST /api/v1/claims/%` reutiliza sin cambios `myapi_request_post_field()` y `myapi_claim_create_save_files()`. Es la única ruta del módulo que usa `POST` sobre un item; queda documentado como decisión, no como descuido.
- **No se toca `myapi.module`.** La ruta `api/v1/claims/%` ya enruta a `myapi_claim_dispatch($id)` sin restricción de método; hoy responde `405` a `POST`. Este spec le cambia esa rama.
- **No se toca `hook_node_update()`.** No tiene rama `'reclamo'` (`myapi.module:702`), así que el `node_save()` de la edición no dispara transacciones ni notificaciones. El timeline del reclamo no cambia.

---

## Alcance

**Dentro:**

- **`resources/claim.resource.inc`** (modificar):
  - **`myapi_claim_dispatch($id = NULL)`** — la rama `POST` con `$id !== NULL` deja de caer en `405` y llama a `myapi_claim_update($id)`. `POST` sin id sigue creando (SPEC 66); `PUT` y `DELETE` siguen respondiendo `405`.
  - **`myapi_claim_update($id)`** (nuevo) — la orquestación completa: autenticación, carga del reclamo bajo la misma regla de visibilidad de SPEC 64, las dos comprobaciones de negocio (solicitante y estado `received`), validación de los cinco campos de texto en el mismo orden que la creación, resolución de los archivos (quitar los pedidos, añadir los nuevos), `node_save()` y respuesta `200` con el mismo objeto que `GET /api/v1/claims/%`.
  - Helpers nuevos y privados a este archivo para los archivos: leer los `fid` actuales de `field_images`/`field_attachment` del nodo, validar `remove_image_ids[]` contra ese conjunto, y borrar de verdad (`file_usage_delete()` + `file_delete()`) lo que se quita o se reemplaza.
  - **Reutilización sin duplicar**: `myapi_claim_valid_catalogue_value()`, `myapi_claim_create_save_files()`, `myapi_claim_create_delete_files()`, `myapi_claim_fetch()`, `myapi_claim_load_images()`, `myapi_claim_load_transactions()` y `myapi_claim_build_item()` se usan tal como están, sin tocarlas.
- **`includes/myapi.request.inc`** (modificar) — helper nuevo `myapi_request_post_field_array($name)`, para leer un campo `multipart` repetido sin que el recurso toque `$_POST` directamente.
- **`includes/myapi.i18n.inc`** (modificar) — tres claves nuevas en `es`/`en`: `claim_updated`, `claim_edit_denied`, `claim_not_editable`.
- **`docs/claim.md`** (modificar) — sección nueva `POST /api/v1/claims/{id}` con la plantilla de `CLAUDE.md`.
- No hay `drush updb`: ningún campo, tabla ni bundle cambia.
- No hay diff en `myapi.module` ni en `myapi.install`.

**Fuera de alcance (para specs futuros):**

- **Editar un reclamo en cualquier estado distinto de `received`.** Una vez la administración lo tomó, el residente ya no lo modifica.
- **Editar un reclamo siendo administrador desde la app.** El back office ya edita cualquier reclamo por `node/%/edit`; este endpoint es solo para el solicitante.
- **Eliminar o cerrar un reclamo desde la app** (`DELETE /api/v1/claims/%`).
- **Añadir transacciones o comentarios desde la app.** Sigue siendo exclusivo del back office (SPEC 57).
- **Notificar a los administradores** de que un reclamo fue editado. Ni push ni email.
- **Registrar la edición en el timeline** como una transacción nueva.
- **Reordenar las imágenes existentes.** Las nuevas se añaden al final; el orden de las que quedan no se puede cambiar.
- **Control de concurrencia optimista** (`If-Match`, `changed`). Dos ediciones simultáneas del mismo reclamo: gana la última.
- **Actualización parcial estilo `PATCH`.** Los cinco campos de texto son obligatorios en cada llamada.
- **`PUT` real con parser `multipart` propio,** ni un `_method` override.
- **Rate limiting / flood control** sobre la edición.

Cinco casos límite decididos **dentro** de este alcance:

1. **Request sin `images[]`, sin `remove_image_ids[]`, sin `attachment` y sin `remove_attachment`:** los archivos del reclamo quedan exactamente como estaban. Solo se actualiza el texto.
2. **`remove_attachment=1` junto con un `attachment` nuevo:** el flag se ignora en silencio — subir un adjunto nuevo ya borra el anterior, porque la cardinalidad es 1.
3. **Reclamo `public` de otro vecino,** visible para el usuario: `403 claim_edit_denied`. Es visible, así que ocultarlo con un `404` confundiría el diagnóstico.
4. **Reclamo que el usuario no puede ver** (otro condominio, o privado de otro): `404 claim_not_found`, exactamente el mismo que `GET /api/v1/claims/%`.
5. **El usuario es el solicitante pero el reclamo ya está `in_progress`, `resolved` o cualquier otro estado:** `409 claim_not_editable`. La petición es válida; el conflicto es con el estado actual del recurso.

---

## Modelo de datos

**No hay campos, tablas ni bundles nuevos.** Este spec escribe en los mismos campos que SPEC 55 creó y SPEC 66 rellena por primera vez desde la API. Lo único nuevo son tres claves i18n.

### Request — `multipart/form-data` sobre `POST /api/v1/claims/{id}`

Mismo transporte que `POST /api/v1/claims`: texto por `$_POST`, archivos por `$_FILES`. No hay cuerpo JSON.

| Campo | Tipo | Obligatorio | Efecto |
|---|---|---|---|
| `subject` | string, ≤255 | Sí | Reemplaza `node.title` |
| `claim_type` | string, clave de `field_claim_type` | Sí | Reemplaza `field_claim_type` |
| `condominium_id` | int > 0, del usuario | Sí | Reemplaza `field_condominium` |
| `description` | string, no vacío | Sí | Reemplaza `field_description` |
| `visibility` | string, clave de `field_visibility` | Sí | Reemplaza `field_visibility` |
| `images[]` | hasta N archivos, JPG/JPEG/PNG, ≤3 MB c/u | No | **Añade** al final de `field_images` |
| `remove_image_ids[]` | lista de `fid` del propio reclamo | No | **Quita** esas imágenes y las borra del disco |
| `attachment` | 1 archivo, PDF/DOC/DOCX/XLS/XLSX, ≤3 MB | No | Reemplaza `field_attachment`; el anterior se borra |
| `remove_attachment` | `1` o `true` | No | Deja `field_attachment` vacío y borra el anterior. Se ignora si viene `attachment` |

Los cinco primeros son obligatorios **siempre**: la actualización es total, no parcial. Enviar solo `description` responde `422 missing_field`.

`remove_image_ids[]` llega a `$_POST` como **array**, y `myapi_request_post_field()` devuelve `NULL` para arrays a propósito. De ahí el helper compartido nuevo, **`myapi_request_post_field_array($name)`** en `includes/myapi.request.inc`, que devuelve una lista de strings escalares recortados (o `[]`). Así ningún recurso toca `$_POST` directamente, que es la convención del módulo.

### Campos que el servidor nunca deja cambiar

| Campo | Comportamiento |
|---|---|
| `node.uid` | Intacto — el autor original |
| `node.created` | Intacto |
| `field_requester` | Intacto — no se lee del request, igual que en la creación |
| `field_status` | Intacto: sigue siendo `received`, que es la precondición para llegar aquí |
| `field_reception_date` | **Intacto** — la fecha original de recepción, nunca se re-sella |
| `node.changed` | Se mueve solo, por `node_save()` |

Esto sale gratis: el nodo se carga con `node_load($id)` y solo se sobrescriben los campos del request. Nada de construir un `stdClass` desde cero.

### Validación, en este orden

Cada paso aborta con su propio error antes de tocar nada:

1. **Autenticación** — Bearer válido, o `401`.
2. **`{id}`** — entero positivo, o `404 claim_not_found`.
3. **Visibilidad** — `myapi_claim_fetch($uid, $condos, ['nid' => $id], …)` sin filas → `404 claim_not_found`. Misma regla y misma query que `GET /api/v1/claims/%`, sin reescribirla.
4. **Solicitante** — `row.requester_id !== uid` → **`403 claim_edit_denied`**.
5. **Estado** — `row.status !== 'received'` → **`409 claim_not_editable`**.
6. **Campos requeridos** — falta uno → `422 missing_field` con `@field`.
7. **`subject`** ≤255 → `422 invalid_field`.
8. **`claim_type`** en `allowed_values` → `422 invalid_field`.
9. **`condominium_id`** entero positivo → `422 invalid_field`; en `myapi_condominium_related_nids($uid)` → si no, `403 condominium_access_denied`.
10. **`description`** no vacío tras `trim()` → `422 invalid_field`.
11. **`visibility`** en `allowed_values` → `422 invalid_field`.
12. **`remove_image_ids[]`** — cada valor entero positivo **y** `fid` presente hoy en el `field_images` de este reclamo → si no, `422 invalid_field` con `@field: remove_image_ids`. Un `fid` de otro reclamo, de un pago, o inexistente, falla igual.
13. **Cupo de imágenes** — el máximo de imágenes nuevas admisibles es `5 − (actuales − eliminadas)`. Ese número se pasa como `$max_count` a `myapi_claim_create_save_files()`, que ya responde `422 claim_too_many_images` cuando se supera. Cero código nuevo para el tope.
14. **`images[]`** — mismo `myapi_claim_create_save_files()` de SPEC 66, todo-o-nada: extensión, tamaño y MIME real.
15. **`attachment`** — igual, con `$max_count = 1` y `$also_delete` = las imágenes nuevas ya guardadas en esta misma request.

### Orden de las escrituras (lo que hace que nada quede a medias)

1. Todo lo anterior valida y **guarda los archivos nuevos**. Si algo falla aquí, los nuevos se borran y **el reclamo no se ha tocado**: sigue con su texto y sus archivos originales.
2. Se compone el `field_images` final: las actuales **menos** las eliminadas, en su orden de `delta` actual, **más** las nuevas al final.
3. `node_save($node)` — no dispara ningún hook (`hook_node_update()` no tiene rama `'reclamo'`).
4. `file_usage_add()` por cada archivo nuevo.
5. **Solo entonces** se borran los viejos: `file_usage_delete()` + `file_delete()` sobre las imágenes eliminadas y sobre el adjunto reemplazado o quitado. Borrar después del `node_save()` es lo que garantiza que nunca se destruye un archivo que el nodo aún referencia.

### Claves i18n nuevas

| Clave | `es` | `en` |
|---|---|---|
| `claim_updated` | Reclamo actualizado correctamente. | Claim updated successfully. |
| `claim_edit_denied` | Solo el solicitante puede modificar este reclamo. | Only the requester can modify this claim. |
| `claim_not_editable` | Este reclamo ya no se puede modificar porque su estado cambió. | This claim can no longer be modified because its status changed. |

Reutilizadas sin cambio: `missing_authorization`, `invalid_token`, `claim_not_found`, `missing_field`, `invalid_field`, `condominium_access_denied`, `claim_too_many_images`, `claim_invalid_image`, `claim_invalid_attachment`, `invalid_file_type`, `method_not_allowed`.

### Respuesta de éxito (200)

El **mismo objeto** que `GET /api/v1/claims/%`, envuelto igual (`data.claim`), transacciones siempre expandidas, más `message`:

```json
{
  "success": true,
  "data": {
    "claim": {
      "id": 141,
      "subject": "Fuga de agua en el pasillo (corregido)",
      "description": "La mancha llega ya hasta la puerta 3-B.",
      "status": "received",
      "claim_type": "claim",
      "visibility": "private",
      "reception_date": "2026-08-03T16:45:00",
      "created": "2026-08-03T16:45:01",
      "condominium_id": 7,
      "condominium_name": "Residencias El Parque",
      "requester_id": 34,
      "images": [
        { "id": 521, "url": "https://mi-sitio/api/v1/claims/141/files/521", "filename": "pasillo.jpg" },
        { "id": 530, "url": "https://mi-sitio/api/v1/claims/141/files/530", "filename": "puerta.jpg" }
      ],
      "attachment": null,
      "transactions": [
        {
          "id": 88,
          "status": "received",
          "status_date": "2026-08-03T16:45:00",
          "comment": "Hemos recibido su reclamo...",
          "created": "2026-08-03T16:45:01",
          "images": [],
          "attachment": null
        }
      ]
    }
  },
  "message": "Reclamo actualizado correctamente."
}
```

`reception_date`, `created` y `transactions` son los mismos de antes de editar: la edición no los toca.

---

## Plan de implementación

1. **`includes/myapi.i18n.inc` — las tres claves nuevas.** `claim_updated`, `claim_edit_denied` y `claim_not_editable` en los bloques `es` y `en`, junto a las demás `claim_*`. *Verificación: `myapi_t()` de las tres devuelve el texto correcto en ambos idiomas.*

2. **`includes/myapi.request.inc` — `myapi_request_post_field_array($name)`.** Hermano de `myapi_request_post_field()`: si `$_POST[$name]` no es un array devuelve `[]`; si lo es, devuelve sus valores escalares recortados, descartando los no escalares y los vacíos. No valida tipos ni contenido — eso es del recurso. Vive en `includes/` y no en el recurso porque cualquier endpoint `multipart` futuro con un campo repetido lo necesitará igual. *Verificación: `php -l`; y una llamada con `$_POST['remove_image_ids'] = ['520', ' 521 ']` devuelve `['520', '521']`.*

3. **`resources/claim.resource.inc` — `myapi_claim_node_file_fids($node, $field_name)`.** Helper puro: recibe un nodo cargado y el nombre de un campo de archivo, devuelve la lista ordenada por `delta` de sus `fid` como enteros, o `[]`. Una sola función para `field_images` (N elementos) y `field_attachment` (0 o 1), no dos copias del mismo `isset()` anidado. *Verificación: `php -l`.*

4. **`resources/claim.resource.inc` — `myapi_claim_update_parse_removals(array $current_fids)`.** Lee `myapi_request_post_field_array('remove_image_ids')` y valida cada valor: entero positivo **y** presente en `$current_fids`. Al primer fallo, `myapi_error('invalid_field', 422, ['@field' => 'remove_image_ids'])`. Devuelve la lista de enteros sin duplicados. Nada de tocar la base de datos ni borrar: solo valida y devuelve. *Verificación: `php -l`; un `fid` ajeno responde `422` y no borra nada.*

5. **`resources/claim.resource.inc` — `myapi_claim_update_delete_files(array $fids, $nid)`.** El borrado real de los archivos que salen del reclamo: por cada `fid`, `file_load()`, `file_usage_delete($file, 'myapi', 'node', $nid, 0)` para quitar **todos** los registros de uso de este módulo sobre ese archivo, y `file_delete($file)`. Un `fid` que ya no existe se salta en silencio. Se llama **siempre después** de `node_save()`, nunca antes. *Verificación: `php -l`; tras quitar una imagen, su fila de `file_managed` desaparece y el archivo ya no está en `private://`.*

6. **`resources/claim.resource.inc` — `myapi_claim_update($id)`.** La orquestación completa, en el orden exacto de la sección de validación del Modelo de datos: auth → `{id}` → visibilidad vía `myapi_claim_fetch()` → `403 claim_edit_denied` → `409 claim_not_editable` → los cinco campos de texto (mismas comprobaciones que `myapi_claim_create()`) → `node_load($id)` → `myapi_claim_node_file_fids()` (paso 3) → `myapi_claim_update_parse_removals()` (paso 4) → cupo de imágenes como `$max_count` de `myapi_claim_create_save_files()` → imágenes nuevas → adjunto nuevo → composición del `field_images` final → `node_save()` → `file_usage_add()` de lo nuevo → `myapi_claim_update_delete_files()` de lo viejo (paso 5) → recarga con `myapi_claim_fetch()` + `myapi_claim_load_images()` + `myapi_claim_load_transactions($nids, TRUE)` → `myapi_respond(['claim' => …], 200, 'claim_updated')`. *Verificación: `curl -F` con los cinco campos actualiza el texto y devuelve `200`; sin `Authorization`, `401`; sobre un reclamo ajeno visible, `403`; sobre uno `in_progress`, `409`.*

7. **`resources/claim.resource.inc` — `myapi_claim_dispatch()`.** Una rama nueva: `POST` con `$id !== NULL` → `myapi_claim_update($id)`. Todo lo demás del dispatcher queda igual, incluido el `405` de `PUT` y `DELETE`. Es el paso que enciende el endpoint: hasta aquí, los pasos 3–6 son código que ninguna ruta alcanza. *Verificación: `php -l`; `PUT /api/v1/claims/141` sigue respondiendo `405`.*

8. **`docs/claim.md`.** Sección nueva `POST /api/v1/claims/{id}` con la plantilla de `CLAUDE.md`: método, auth, tabla completa de campos del `multipart/form-data` (incluidas las tres reglas de archivos), ejemplo de respuesta `200`, y tabla de errores con `401`, `403` (dos causas distintas), `404`, `405`, `409` y `422` con sus variantes. Se documenta también, explícitamente, por qué es `POST` y no `PUT`. *Verificación: lectura contra la implementación.*

9. **`drush cc all` + matriz manual.** Sin `drush updb`. Recorrer los criterios de aceptación con: el solicitante de un reclamo `received`, el mismo usuario sobre un reclamo suyo ya `in_progress`, y un vecino distinto sobre un reclamo `public` del primero.

**Nota de orden:** los pasos 1–5 son aditivos y no cambian el comportamiento de ninguna ruta viva; el 7 es el único que altera una respuesta existente (`POST /api/v1/claims/%` deja de ser `405`). Partirlo así permite verificar cada helper con `php -l` antes de que el endpoint exista.

---

## Criterios de aceptación

**Autenticación y método**

- [x] `POST /api/v1/claims/{id}` sin `Authorization` → `401 missing_authorization`.
- [x] Con un token inválido o expirado → `401 invalid_token`.
- [x] `PUT /api/v1/claims/{id}` y `DELETE /api/v1/claims/{id}` siguen respondiendo `405 method_not_allowed`.
- [x] `POST /api/v1/claims` (sin id) sigue creando un reclamo con `201`, sin cambios respecto a SPEC 66.

**Acceso y estado**

- [x] `{id}` no numérico, `0` o negativo → `404 claim_not_found`.
- [x] Un reclamo de otro condominio, o privado de otro vecino, o inexistente → `404 claim_not_found` — los tres indistinguibles entre sí.
- [x] Un reclamo `public` de otro vecino (visible para el editor) → `403 claim_edit_denied`, nunca `404`.
- [x] El solicitante sobre un reclamo suyo con estado distinto de `received` → `409 claim_not_editable`.
- [x] En los tres casos anteriores, ni un solo campo, archivo ni fila cambia.
- [x] El solicitante sobre un reclamo suyo en `received` → la edición procede.

**Campos de texto**

- [x] Falta `subject`, `claim_type`, `condominium_id`, `description` o `visibility` → `422 missing_field` con `@field` el que falte, y nada se modifica.
- [x] `subject` de 256 caracteres o más → `422 invalid_field`.
- [x] `claim_type` o `visibility` fuera de sus `allowed_values` → `422 invalid_field`.
- [x] `description` vacío tras `trim()` → `422 invalid_field`.
- [x] `condominium_id` no numérico, `0` o negativo → `422 invalid_field`.
- [x] `condominium_id` de un condominio ajeno → `403 condominium_access_denied`.
- [x] `condominium_id` de otro condominio del propio usuario → el reclamo se mueve a ese condominio y el `GET` lo confirma.
- [x] Cambiar `visibility` de `private` a `public` hace que el reclamo aparezca en el listado de otro vecino del mismo condominio.

**Imágenes**

- [x] Request sin `images[]` ni `remove_image_ids[]` → las imágenes del reclamo quedan exactamente iguales, mismo orden y mismos `fid`.
- [x] `images[]` con 2 archivos válidos sobre un reclamo con 1 imagen → quedan 3, las nuevas **al final** del orden.
- [x] `remove_image_ids[]` con 1 de las 3 imágenes → quedan las otras 2, en su orden original.
- [x] `remove_image_ids[]` con las 3 → el reclamo queda con `"images": []`.
- [x] Quitar 1 y añadir 2 en la misma request sobre un reclamo con 4 → quedan 5, válido.
- [x] Quitar 0 y añadir 2 sobre un reclamo con 4 → `422 claim_too_many_images`, y ninguna de las 2 nuevas queda guardada.
- [x] `remove_image_ids[]` con un `fid` de otro reclamo, de un pago o inexistente → `422 invalid_field` con `@field: remove_image_ids`, y **ninguna** imagen se borra.
- [x] Un `.webp` en `images[]` → `422 claim_invalid_image`; las imágenes actuales siguen intactas.
- [x] Un `.php` renombrado a `.jpg` → `422 invalid_file_type`; nada se guarda ni se borra.
- [x] Una imagen de 4 MB junto a una válida → `422 claim_invalid_image`, ninguna de las dos queda guardada, y el reclamo conserva sus imágenes originales.
- [x] Una imagen eliminada desaparece de `file_managed` y del disco en `private://`; su URL responde `404 file_not_found`.

**Adjunto**

- [x] Request sin `attachment` ni `remove_attachment` → el adjunto queda igual.
- [x] `attachment` nuevo sobre un reclamo que ya tenía uno → queda solo el nuevo, y el anterior desaparece de `file_managed` y del disco.
- [x] `remove_attachment=1` sin `attachment` → el reclamo queda con `"attachment": null` y el archivo se borra.
- [x] `remove_attachment=1` **junto con** un `attachment` nuevo → el flag se ignora y queda el nuevo adjunto, sin error.
- [x] Un adjunto de tipo o tamaño inválido → `422 claim_invalid_attachment`; el adjunto anterior sigue ahí y las imágenes nuevas de esa misma request no quedan guardadas.

**Integridad**

- [x] Cualquier error de los anteriores deja el reclamo byte a byte como estaba: texto, imágenes, adjunto y `changed`.
- [x] Ningún archivo se borra antes del `node_save()`: en un fallo, todos los archivos originales siguen existiendo.
- [x] Tras una edición con éxito, no queda ninguna fila de `file_usage` apuntando a un archivo ya borrado, ni ningún archivo permanente sin uso.

**Campos inmutables**

- [x] `field_reception_date` es el mismo valor antes y después de editar.
- [x] `field_requester` no cambia, aunque el cliente mande un campo con ese nombre.
- [x] `field_status` sigue en `received` después de editar.
- [x] `node.uid` y `node.created` no cambian; solo `node.changed` avanza.
- [x] El reclamo sigue teniendo **exactamente las mismas transacciones** que antes de la edición, con los mismos `id`, `status` y `comment` — la edición no crea ninguna.

**Respuesta**

- [x] Éxito → `200`, con `data.claim` teniendo exactamente las mismas claves y tipos que `GET /api/v1/claims/{id}`, y `message` traducido (`claim_updated`).
- [x] `transactions` viene expandido (objetos, no ints), sin necesidad de `?include=transactions`.
- [x] Las `url` de las imágenes nuevas apuntan a `GET /api/v1/claims/{id}/files/{fid}` y descargan con el mismo token.
- [x] Un `GET /api/v1/claims/{id}` inmediatamente después devuelve un objeto idéntico al del `200`.

**No regresión**

- [x] `GET /api/v1/claims`, `GET /api/v1/claims/%` y `GET /api/v1/claims/%/files/%` no cambian ninguna clave, tipo ni código de estado.
- [x] `POST /api/v1/claims` no cambia: mismos errores, mismo `201`.
- [x] `myapi.install` y `myapi.module` no aparecen en el diff.
- [x] `node/%/edit` de un `reclamo` en el back office sigue funcionando igual.
- [x] `drush cc all` no reporta errores.

**Documentación**

- [x] `docs/claim.md` documenta `POST /api/v1/claims/{id}` con la plantilla de `CLAUDE.md`, incluidas las tres reglas de archivos y la justificación de `POST` sobre `PUT`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Método HTTP | `POST /api/v1/claims/{id}` con `multipart/form-data` | `PUT` con un parser `multipart` propio sobre `php://input`; `PUT` con JSON sin archivos; `POST` + override `_method=PUT` | PHP no rellena `$_POST` ni `$_FILES` en un `PUT`. Escribir un parser MIME a mano en un módulo D7 sin soporte de seguridad es superficie de código y de riesgo por una etiqueta semántica. `PUT` con JSON dejaría los archivos fuera y obligaría a un segundo spec con endpoints propios. El override es un `POST` disfrazado con un parámetro más que documentar. |
| Alcance de la actualización | Total: los cinco campos de texto son obligatorios en cada llamada | Parcial estilo `PATCH`: solo se toca lo que venga | Decisión explícita del usuario. Contrato idéntico al de `POST /api/v1/claims`, una sola tabla de campos que documentar, y ninguna necesidad de distinguir "campo ausente" de "campo vaciado" caso por caso. |
| Modelo de archivos | Incremental: `images[]` añade, `remove_image_ids[]` quita, lo no mencionado se conserva | Reemplazo total (lo acordado inicialmente); declarativo con `keep_image_ids[]` | El reemplazo total no permite quitar una de tres sin volver a subir las otras dos — bytes de más y riesgo de perderlas si la subida falla. El declarativo lo resuelve, pero un cliente que olvida `keep_image_ids[]` **borra todas** las imágenes; el incremental, ante un parámetro olvidado, no destruye nada. |
| `remove_images=1` | Se elimina del contrato | Dejarlo como atajo para vaciar todas las imágenes | Con `remove_image_ids[]` ya se pueden listar todas. Dos formas de hacer lo mismo son dos ramas que probar, dos filas que documentar y una pregunta más para el cliente. |
| Archivos que salen del reclamo | Se borran de verdad: `file_usage_delete()` + `file_delete()` | Solo desreferenciar y dejar que el cron los recoja | Decisión explícita del usuario. Viven en `private://` y nadie más los referencia; dejarlos acumula peso en disco por cada corrección que haga un residente. |
| Momento del borrado | Después del `node_save()`, nunca antes | Borrar primero y luego guardar el nodo | Borrar antes deja una ventana en la que el nodo aún referencia un archivo que ya no existe. Si el `node_save()` falla, la edición se cae con los archivos originales intactos. |
| Editor que no es el solicitante | `403 claim_edit_denied` (clave nueva) | `404 claim_not_found`, como hace todo lo demás del recurso | Decisión explícita del usuario. El `404` uniforme de SPEC 64 existe para no confirmar que un reclamo privado existe; aquí el reclamo **ya es visible** para quien pregunta, así que no hay nada que ocultar y un `404` solo confundiría el diagnóstico en soporte. |
| Reclamo fuera de estado `received` | `409 claim_not_editable` | `422 invalid_field` o `403` | Decisión explícita del usuario. El dato enviado es válido; lo que falla es el estado actual del recurso, que es exactamente lo que significa un `409`. **Nota:** `409` no figura en la lista de códigos de `CLAUDE.md`; este spec lo introduce a propósito y `docs/claim.md` lo documenta. |
| `fid` ajeno en `remove_image_ids[]` | `422 invalid_field` con `@field: remove_image_ids`, sin borrar nada | Ignorarlo en silencio y borrar solo los válidos | Decisión explícita del usuario. Mandar el `fid` de otro reclamo es un bug del cliente o un sondeo; en ambos casos el silencio esconde el problema, y un borrado parcial deja al cliente creyendo que quitó algo que sigue ahí. |
| `remove_attachment=1` + `attachment` nuevo | El flag se ignora en silencio | `422` por contradicción | Decisión explícita del usuario. `field_attachment` es de cardinalidad 1: subir uno nuevo **ya** borra el anterior, así que el resultado con y sin flag es idéntico. Un `422` rechazaría una petición cuyo significado es inequívoco. |
| Rastro de la edición | Ninguno: el timeline no se toca | Crear una `claim_transaction` de tipo "editado" | Decisión explícita del usuario. Las transacciones son el histórico de **estados**, y editar no cambia el estado. Además obligaría a inventar un `status` que no está en el catálogo de SPEC 55/62. |
| `field_reception_date` | Intacta: la fecha original de recepción | Re-sellarla con la hora de la edición | Decisión explícita del usuario. Es la fecha en que la administración recibió el reclamo, no la de su última corrección; moverla falsearía el orden del listado y los filtros por rango. |
| `condominium_id` | Editable, validado igual que en la creación | Inmutable | Decisión explícita del usuario. Es el campo más plausible de equivocar al radicar (un residente con vivienda en dos condominios), y la validación contra `myapi_condominium_related_nids($uid)` ya impide moverlo a uno ajeno. |
| Cómo se construye el nodo | `node_load($id)` y se sobrescriben solo los campos del request | Construir un `stdClass` nuevo como hace `myapi_claim_build_node()` | Con `node_load()`, los campos inmutables (`uid`, `created`, `field_requester`, `field_status`, `field_reception_date`) quedan intactos **por construcción**, sin una sola línea que los preserve a mano ni riesgo de olvidar uno al añadir un campo en el futuro. |
| Tope de 5 imágenes | Se pasa `5 − (actuales − eliminadas)` como `$max_count` a `myapi_claim_create_save_files()` | Escribir una comprobación de conteo propia en `myapi_claim_update()` | El helper de SPEC 66 ya cuenta y ya responde `claim_too_many_images`. Reusarlo con un tope calculado es cero código nuevo y garantiza que los dos endpoints fallen igual. |
| `remove_image_ids[]` en el request | Array `multipart` + helper nuevo `myapi_request_post_field_array()` en `includes/` | String separado por comas leído con `myapi_request_post_field()` | Aprobado explícitamente por el usuario. El array es la forma idiomática en `multipart` y ya la usa `images[]`; el helper en `includes/` mantiene la regla de que ningún recurso toca `$_POST` directamente, y sirve a cualquier endpoint futuro con un campo repetido. |
| Concurrencia | Ninguna: dos ediciones simultáneas, gana la última | `If-Match` / comparación de `node.changed` | Un reclamo lo edita su solicitante desde su propia app; el escenario de dos escritores simultáneos es marginal y ningún otro endpoint del módulo lo contempla. Queda anotado en Riesgos. |
| Ubicación del código | Todo en `resources/claim.resource.inc`, salvo el helper de `$_POST` | Un include nuevo en `includes/` | Nadie más edita reclamos; no hay un segundo consumidor. Mismo criterio que SPEC 64 y 66. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El borrado de imágenes es irreversible.** No hay papelera: `remove_image_ids[]` borra el archivo del disco, y un residente que se equivoca de `fid` pierde la foto. | Es la consecuencia buscada de la decisión "borrar de verdad". La app debe pedir confirmación antes de enviar la eliminación, y se documenta en `docs/claim.md` como parte del contrato. El `422` ante un `fid` que no es del reclamo evita al menos borrar la imagen de **otro** reclamo por un error de cliente. |
| **`file_usage_delete($file, 'myapi', 'node', $nid, 0)` borra todos los registros de uso de este módulo sobre ese `fid`.** Si el mismo archivo estuviera referenciado por dos nodos, el segundo quedaría apuntando a un archivo borrado. | Hoy no puede ocurrir: cada subida crea su propia fila en `file_managed`, y `myapi_claims_file_claim_nid()` (SPEC 65) ya asume la relación 1:1 entre un `fid` y su reclamo. Si un spec futuro introduce archivos compartidos entre nodos, esta línea es la primera que hay que revisar. |
| **El estado del reclamo puede cambiar entre que la app lo muestra y el usuario envía la edición** (un administrador lo pasa a `in_progress` mientras tanto). El residente recibe un `409` con el formulario ya rellenado. | Inevitable sin bloqueos, y preferible a permitir la edición sobre un reclamo ya en curso. El mensaje de `claim_not_editable` dice explícitamente que el estado cambió, para que la app pueda recargar el detalle y explicarlo en vez de mostrar un error genérico. |
| **Dos ediciones simultáneas del mismo reclamo:** gana la última, sin aviso. | Escenario marginal — el único editor es el propio solicitante desde su app. Ningún endpoint del módulo tiene control de concurrencia; introducirlo aquí solo sería un spec futuro, no una excepción para un endpoint. |
| **Cambiar `visibility` de `public` a `private` no deshace lo ya visto.** Los vecinos que leyeron el reclamo mientras era público siguen sabiendo su contenido. | Es una propiedad del mundo, no del código. Se documenta en `docs/claim.md` para que la app no prometa lo contrario en su UI. |
| **Cambiar `condominium_id` mueve el reclamo entero**, con sus transacciones y sus archivos: los vecinos del condominio anterior dejan de verlo y los del nuevo empiezan a verlo. | Es el comportamiento buscado (corregir un condominio mal elegido), y la validación contra `myapi_condominium_related_nids($uid)` impide moverlo fuera de los condominios del propio usuario. |
| **Archivos temporales huérfanos si el proceso PHP muere entre `file_save_upload()` y el `myapi_error()` de limpieza** (timeout, OOM). | Riesgo heredado de SPEC 66, no introducido aquí: son archivos permanentes sin ninguna referencia en `field_data_*`, que la recolección de archivos de `hook_cron` recoge igual que cualquier subida abandonada. |
| **Subir varias imágenes de 3 MB puede chocar con `upload_max_filesize`/`post_max_size`** mal configurados. | Mismo prerrequisito de entorno que ya documenta SPEC 66 en `docs/claim.md`. Un límite bajo produce un `$_FILES` vacío o truncado, que este endpoint trata como "no se envió archivo" o rechaza en la validación — nunca un `500`. |

---

## Lo que **NO** está en este spec

- Editar un reclamo en cualquier estado distinto de `received`.
- Editar un reclamo siendo administrador desde la app.
- Eliminar o cerrar un reclamo desde la app.
- Añadir transacciones o comentarios desde la app.
- Notificaciones al editar, ni registro de la edición en el timeline.
- Reordenar las imágenes existentes.
- Control de concurrencia optimista.
- Actualización parcial estilo `PATCH`.
- `PUT` con parser `multipart` propio.
- Rate limiting sobre la edición.

Cada uno de ellos, si llega, va en su propio spec.
