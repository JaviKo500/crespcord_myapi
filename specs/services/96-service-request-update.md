# 96 — Edición de una solicitud de servicio desde la app

- **Estado:** Approved
- **Fecha:** 2026-08-19
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña del bundle
    `service_request` y de los cuatro campos que este spec escribe
    (`field_description`, `field_desired_start`, `field_images`,
    `field_attachment`) más el `node.title`. Cero cambios de esquema: ni campo,
    ni instancia, ni bundle, ni tabla.
  - `90-service-request-create` (Implemented) — dueña de
    `resources/service_request.resource.inc` como archivo de escritura, del
    orden de validación (id → token → carga → acceso → estado → cuerpo), de la
    familia de claves i18n `service_request_*`, de
    `myapi_service_request_parse_desired_start()` y de
    `includes/myapi.node_files.inc`. Este spec reutiliza sus validaciones tal
    cual y amplía ese include con tres funciones más, por el mismo motivo y con
    el mismo procedimiento con que él lo creó.
  - `89-service-request-detail` (Implemented) — dueña de
    `myapi_service_request_item_dispatch()`, `myapi_service_request_detail_row()`,
    `myapi_service_request_load_images()` y `myapi_service_request_build_detail()`.
    Este spec le añade la rama `POST` al dispatcher y reutiliza los otros tres
    sin tocar una línea.
  - `95-service-request-cancel` (Implemented) — precedente inmediato: la
    primera escritura de este recurso sobre una solicitud que ya existe. De ahí
    salen la regla de acceso (`field_requester` exacto, nunca `node->uid`), el
    criterio 404/403/409 y la clave `service_request_forbidden`, cuyo mensaje
    este spec **reescribe** para que deje de hablar solo de cancelar.
  - `67-claim-update` (Implemented) — precedente de forma exacto: `POST` sobre
    el item porque PHP no puebla `$_FILES` en un `PUT`, reemplazo total de los
    campos de texto, `remove_image_ids[]` validado contra lo que el nodo
    referencia ahora, y borrado real de disco **después** del `node_save()`.
    Este spec **mueve** tres de sus funciones a `includes/` y deja al reclamo
    llamándolas desde ahí.
  - `92-service-request-initial-transaction` (Implemented) y
    `93-service-request-transactions-in-detail` (Implemented) — el timeline.
    Este spec **no los toca**: editar no crea transacción, y `hook_node_update()`
    de `myapi.module` no tiene rama `'service_request'` que pudiera crearla.

**Objetivo:** Que el residente pueda corregir el título, la descripción, la
fecha deseada y los ficheros de su propia solicitud de servicio con
`POST /api/v1/service-requests/{id}`, mientras siga `open` y nadie haya
ofertado todavía.

---

## Alcance

**Dentro:**

- **`resources/service_request.resource.inc`** (modificar):
  - **`myapi_service_request_item_dispatch($nid)`** — la rama `POST` deja de
    caer en `405` y llama a `myapi_service_request_update($nid)`. `GET` sigue
    sirviendo el detalle; `PUT` y `DELETE` siguen respondiendo `405`. Es el
    mismo cambio que el SPEC 67 hizo en `myapi_claim_dispatch()`.
  - **`myapi_service_request_update($nid)`** (nueva) — el endpoint completo:
    compuerta, validación, escritura y respuesta.
  - **`myapi_service_request_update_gate($row)`** (nueva, pura) — responde si
    una solicitud admite edición según su estado y su número de ofertas.
    Separada del endpoint para que el test unitario la ejecute sin HTTP.

- **`includes/myapi.node_files.inc`** (ampliar) — tres funciones **movidas**
  desde `resources/claim.resource.inc`, renombradas de `claim` a `node`, sin
  wrappers de los nombres viejos y sin un cambio de lógica:
  - `myapi_claim_node_file_fids()` → **`myapi_node_files_current_fids()`**
  - `myapi_claim_update_parse_removals()` → **`myapi_node_files_parse_removals()`**,
    que pasa a recibir el nombre del campo `$_POST` por parámetro en vez de
    llevar `'remove_image_ids'` escrito dentro.
  - `myapi_claim_update_delete_files()` → **`myapi_node_files_delete_removed()`**

  Es exactamente el procedimiento que el SPEC 90 documentó en el `@file` de ese
  include cuando movió ahí `myapi_claim_create_save_files()`.

- **`resources/claim.resource.inc`** (modificar) — borra esas tres funciones y
  llama a las nuevas. Cero cambio de comportamiento en el reclamo: mismos
  argumentos, mismos códigos de error, misma respuesta. Los tests del reclamo
  que ya existen son la red que lo prueba.

- **`includes/myapi.i18n.inc`** (modificar) — las claves nuevas en `es` y `en`, y
  la **reescritura** del texto de `service_request_forbidden`, que hoy dice «No
  puedes cancelar esta solicitud» y pasa a ser neutro para servir a los dos
  endpoints.

- **`docs/service-request.md`** (modificar) — sección nueva para
  `POST /api/v1/service-requests/{id}`, en el mismo fichero y no en uno aparte.

- **`tests/unit/ServiceRequestUpdateTest.php`** (nuevo) — con el patrón de
  `ServiceRequestCancelTest.php`.

**No se toca `myapi.module`.** La ruta `api/v1/service-requests/%` ya está
registrada en `hook_menu()` apuntando a `myapi_service_request_item_dispatch`
sin restricción de método: hoy responde `405` a un `POST` y lo que cambia es esa
rama, dentro del recurso. Tampoco se toca `myapi.info`: no aparece ningún `.inc`
nuevo.

**Fuera de alcance (para specs futuros):**

- **`category_id`.** Cambiar de categoría cambia el público de proveedores que
  ve la solicitud: eso es otra solicitud, no una edición.
- **`unit_id`,** y con él el `condominium_id` derivado. Mismo argumento: el
  condominio decide quién ve la solicitud, y moverla de sitio no es corregirla.
- **`assigned_provider_id`.** Una solicitud con proveedor asignado está en
  `direct`, y `direct` no es editable. No hay caso que atender.
- **Reordenar las imágenes.** Las que sobreviven mantienen su orden de delta y
  las nuevas van al final, igual que en el SPEC 67.
- **Editar desde el proveedor, el operador o el administrador de edificio.** El
  back office ya edita con el formulario nativo de Drupal.
- **Editar en `offered`, `direct`, `assigned`, `closed` o `cancelled`,** y editar
  una `open` que ya recibió ofertas.
- **Dejar rastro de la edición:** ni transacción en el timeline, ni notificación
  a nadie, ni historial de qué cambió.
- **Control de concurrencia** (`If-Unmodified-Since`, `updated_at` en el cuerpo,
  o cualquier otro bloqueo optimista). Dos ediciones simultáneas del mismo
  residente: gana la última.
- **`PUT`.** PHP no puebla `$_POST` ni `$_FILES` en un `PUT`, y aquí hay
  ficheros — el mismo motivo documentado en `docs/claim.md`.
- **Edición parcial.** Los tres campos de texto viajan siempre, en cada llamada.

---

## Modelo de datos

**Cero cambios de esquema.** Ni campo, ni instancia, ni bundle, ni tabla, ni
`hook_update_N()`. Este spec escribe sobre campos que el SPEC 77 ya instaló.

### Petición — `POST /api/v1/service-requests/{id}`

`multipart/form-data`, no JSON — como la creación y como la edición del
reclamo. Los textos se leen con `myapi_request_post_field()` y
`myapi_request_post_field_array()`, nunca de `$_POST` directamente; los ficheros
llegan por `$_FILES`.

| Campo                | Tipo      | Obligatorio | Regla |
|----------------------|-----------|-------------|-------|
| `title`              | texto     | **sí**      | No vacío tras `trim()`, máximo 255 caracteres (`node.title` es `varchar(255)`). |
| `description`        | texto     | **sí**      | No vacía tras `trim()`. |
| `desired_start`      | texto     | **sí**      | `myapi_service_request_parse_desired_start()`: `strtotime()` la parsea y el instante es **estrictamente futuro**. |
| `images[]`           | ficheros  | no          | JPG, JPEG o PNG hasta 3 MB cada una. Se **añaden** a las que ya hay. |
| `remove_image_ids[]` | repetido  | no          | Cada valor, entero positivo y fid que la solicitud referencia **ahora mismo**. |
| `attachment`         | fichero   | no          | PDF, DOC, DOCX, XLS o XLSX hasta 3 MB. **Sustituye** al que hubiera. |
| `remove_attachment`  | texto     | no          | `1` o `true` vacía el adjunto. Se **ignora** si en la misma petición viene un `attachment` nuevo: el campo tiene cardinalidad 1, así que subir uno ya sustituye al anterior y el resultado con y sin la bandera es idéntico. |

Los tres campos de texto son obligatorios **en cada llamada**: esto es un
reemplazo, no un parcheo. No mandar `description` es `422 missing_field`, nunca
«déjala como estaba». Es el contrato del SPEC 67, y con tres campos el coste
para el cliente es nulo.

Todo lo que no está en esa tabla se conserva intacto porque el endpoint carga
el nodo con `node_load()` y sobrescribe solo lo que la petición nombra:
`field_category`, `field_unit`, `field_condominium`, `field_requester`,
`field_request_status`, `field_assigned_provider`, `field_assigned_offer` y
`field_closed_at` no se leen de la petición jamás.

### La compuerta — `myapi_service_request_update_gate($row)`

Dos condiciones, y las dos tienen que cumplirse:

1. `$row->status === 'open'`. La comparación estricta contra el literal, no el
   grafo de `myapi_services_transition_allowed()`: aquí no hay transición de
   estado que consultar, y un estado corrupto o vacío no es `'open'`, así que
   cae del lado correcto sin una rama propia.
2. La solicitud tiene **cero** ofertas publicadas, contadas con
   `myapi_service_request_offer_counts_by_nid([$nid])` — la agregación del SPEC
   88, que ya filtra por bundle `service_offer` y `status = 1`. Cuenta
   **cualquier** oferta, sea cual sea su estado: `withdrawn` y `rejected`
   también. Un proveedor que ya leyó el enunciado y ofertó no debe encontrárselo
   cambiado.

La segunda condición **no es redundante con la primera**. El grafo dice que la
primera oferta mueve `open → offered`, pero hoy nada lo ejecuta: `myapi.module`
no tiene ninguna rama `service_offer` en `hook_node_insert()` ni en
`hook_node_update()`, así que una oferta creada desde el back office deja la
solicitud en `open` con ofertas colgando. La compuerta tapa ese hueco, y el día
que exista el endpoint de ofertas y sincronice el estado, la segunda condición
simplemente dejará de ser la que dispara.

Los dos fallos responden lo mismo: `409 service_request_not_editable`. Para el
residente el resultado es idéntico —no puede editar— y dos códigos obligarían
al cliente a mantener dos mensajes para la misma pantalla.

### El cupo de imágenes

`field_images` admite 5. Lo que decide cuántas nuevas caben es lo que queda
**después** de las eliminaciones:

```
conservadas = count(fids actuales) - count(remove_image_ids)
max_nuevas  = max(0, 5 - conservadas)
```

`max_nuevas` es lo que se le pasa a `myapi_node_files_save()`, que ya responde
`service_request_too_many_images` cuando la petición manda más de esas. Una
petición que borra tres y sube tres es válida; una que sube seis sin borrar
nada, no.

### Escrituras, en este orden

Nada se escribe hasta que todo está validado. El orden es el del SPEC 67 y el
motivo es el mismo en cada paso:

1. `node_load()` y lectura de los fids actuales de `field_images` y
   `field_attachment` con `myapi_node_files_current_fids()`.
2. `remove_image_ids[]` validado contra esos fids. Un fid de otra solicitud, de
   un reclamo o de nada responde `422` **aquí**, antes de que ningún fichero
   toque el disco, y antes de que un fid ajeno sirva para sondear ficheros de
   otros.
3. `images[]` con `myapi_node_files_save()`: todo o nada. Si falla, no se ha
   escrito nada todavía y la solicitud conserva su texto y sus ficheros.
4. `attachment` con `myapi_node_files_save()` y `$also_delete = $image_files`:
   si el adjunto falla, las imágenes que el paso 3 acababa de guardar se borran
   también, y la petición no deja huérfanos.
5. Sobrescritura sobre el nodo cargado: `title`, `field_description`,
   `field_desired_start`, la lista final de `field_images` (las que sobreviven
   en su orden de delta, más las nuevas al final) y `field_attachment`
   (sustituido, vaciado, o intacto).
6. `node_save()`.
7. `file_usage_add()` de cada fichero nuevo, para que el cron no los siegue.
8. `myapi_node_files_delete_removed()` de los que salieron de la solicitud —
   imágenes eliminadas y adjunto sustituido o vaciado. **Después** del
   `node_save()`, nunca antes: así ningún fichero que el nodo todavía referencia
   llega a destruirse.

**Sin transacción de base de datos**, igual que la cancelación y que el
reclamo: un `node_save()` con la Field API y sus hooks dentro de una transacción
explícita es una fuente conocida de bloqueos en Drupal 7. El único punto que
puede quedar a medias es el paso 8, y su consecuencia es un fichero muerto en
disco que ya nadie referencia.

**El `node_save()` del paso 6 no dispara nada.** `hook_node_update()` de
`myapi.module` no tiene rama `'service_request'`: ni transacción, ni
notificación, ni sincronización de estado. Editar no deja rastro en el timeline,
y eso es una decisión, no una omisión.

### Respuesta de éxito (200)

**Dieciséis claves**, en este orden:

```
id · title · description · status · category · unit · assigned_offer ·
assigned_provider · created · desired_start · viewer · requester ·
condominium · images · attachment · closed_at
```

Son las diecinueve del detalle **menos tres**: `offers`, `offers_count` y
`transactions`. La edición no las cambia —la compuerta acaba de probar que no
hay ofertas, y el timeline sigue donde estaba—, así que la respuesta no las
consulta ni las repite. Consecuencia que hay que aceptar con los ojos abiertos:
**este objeto no es intercambiable con el del `GET`**, y la app no puede
sustituir sin más el detalle que tenga en pantalla por lo que devuelve esta
llamada; tiene que fusionar las dieciséis claves sobre el objeto que ya tiene.
Queda anotado en `docs/service-request.md` con esas palabras.

Se construye llamando a `myapi_service_request_build_detail($row, 'requester',
$images, [], 0, [])` y quitando las tres claves de la lista resultante. Un
serializador y no dos: las dieciséis que quedan salen de la misma función que
sirve el `GET`, así que no pueden divergir de él por accidente.

`viewer` es siempre `'requester'` —el paso de acceso ya probó que quien llegó
aquí es el `field_requester`— y `status` es siempre `'open'`, porque editar no
mueve el estado. `message` es `service_request_updated`.

### Errores

| HTTP | `error_code`                          | Cuándo |
|------|---------------------------------------|--------|
| 405  | `method_not_allowed`                  | `PUT` o `DELETE` sobre la ruta. Antes del token y antes de la consulta. |
| 404  | `service_request_not_found`           | El `{id}` no es entero positivo; o la solicitud no existe, es de otro bundle, está despublicada, o su término de categoría fue borrado. |
| 401  | `missing_authorization` / `invalid_token` | Sin cabecera `Authorization`, o token inválido o caducado. |
| 403  | `service_request_forbidden`           | La solicitud existe y quien pide no es su `field_requester`. Incluye al proveedor asignado y al administrador. |
| 409  | `service_request_not_editable`        | El estado no es `open`, o es `open` y ya tiene al menos una oferta. |
| 422  | `missing_field`                       | Falta `title`, `description` o `desired_start`. `@field` dice cuál. |
| 422  | `invalid_field`                       | `title` de más de 255; `description` vacía; `desired_start` que no parsea o no es futura; `remove_image_ids[]` con un valor no entero o con un fid que la solicitud no referencia. `@field` dice cuál. |
| 422  | `service_request_invalid_image` / `service_request_too_many_images` | Imagen de tipo o tamaño no permitido; o más imágenes de las que caben en el cupo. |
| 422  | `service_request_invalid_attachment` / `service_request_too_many_attachments` | Adjunto de tipo o tamaño no permitido; o más de uno. |

El `404` se resuelve con **`myapi_service_request_detail_row($nid)`**, no con
`node_load()`. Una sola consulta trae a la vez el criterio de existencia (el
mismo exacto que el `GET`, `INNER JOIN` con la categoría incluido), el
`requester_uid` para el `403` y el `status` para el `409`. El `node_load()`
llega después, solo para escribir. Esto es lo que evita la rama fea de la
cancelación: allí el `detail_row()` va al final y una categoría huérfana obliga
a degradar la respuesta **después** de haber escrito; aquí lo que no se puede
serializar responde `404` antes de escribir nada.

### Claves i18n — `includes/myapi.i18n.inc`, en `es` y `en`

Dos nuevas:

| Clave | es | en |
|-------|----|----|
| `service_request_updated`      | Solicitud actualizada correctamente. | Service request updated successfully. |
| `service_request_not_editable` | Esta solicitud ya no se puede editar. | This service request can no longer be edited. |

Y una **reescrita**, porque pasa a servir a dos endpoints:

| Clave | es (antes → después) | en (antes → después) |
|-------|----------------------|----------------------|
| `service_request_forbidden` | «No puedes cancelar esta solicitud.» → «No tienes permiso sobre esta solicitud.» | «You cannot cancel this service request.» → «You do not have permission on this service request.» |

Todas las demás ya existen y se reutilizan sin tocarlas: `missing_field`,
`invalid_field`, `service_request_not_found`, `service_request_invalid_image`,
`service_request_too_many_images`, `service_request_invalid_attachment`,
`service_request_too_many_attachments` y `method_not_allowed`.

---

## Plan de implementación

Seis pasos. Cada uno deja el módulo funcionando y es commiteable por separado.

### Paso 1 — Las tres claves i18n

`includes/myapi.i18n.inc`: añadir `service_request_updated` y
`service_request_not_editable` a los dos idiomas, y reescribir el texto de
`service_request_forbidden` en los dos.

Nada las usa todavía; el módulo sigue exactamente igual. Se hace primero para
que el paso 4 no tenga que mezclar catálogo con lógica.

Prueba manual: `PUT /api/v1/service-requests/{id}/cancel` sobre una solicitud
ajena responde `403` con el texto nuevo.

### Paso 2 — El movimiento de los tres helpers

`includes/myapi.node_files.inc`: añadir `myapi_node_files_current_fids()`,
`myapi_node_files_parse_removals()` y `myapi_node_files_delete_removed()`,
copiadas literalmente de `resources/claim.resource.inc` con dos únicos cambios:
el nombre, y el nombre del campo `$_POST` como parámetro de la segunda en vez de
`'remove_image_ids'` escrito dentro. Los docblocks viajan con ellas, actualizados
para hablar de nodos y no de reclamos.

`resources/claim.resource.inc`: borrar las tres funciones y cambiar sus tres
llamadas en `myapi_claim_update()` por las nuevas, pasando `'remove_image_ids'`
como primer argumento en la del parseo. Sin wrappers de los nombres viejos:
nada más los llamaba, igual que cuando el SPEC 90 movió
`myapi_claim_create_save_files()` a ese mismo include.

`drush cc all`. Prueba manual: `POST /api/v1/claims/{id}` con `remove_image_ids[]`
sigue borrando la imagen y devolviendo el mismo objeto. Los tests del reclamo que
ya existen se ejecutan sin cambios y siguen pasando — son la red de este paso.

### Paso 3 — La compuerta, pura y probada

`resources/service_request.resource.inc`: añadir
`myapi_service_request_update_gate($row)`, que recibe la fila de
`myapi_service_request_detail_row()` ya cargada más el número de ofertas, y
devuelve `TRUE` o `FALSE`. No consulta, no responde, no carga: por eso el test
la ejecuta directamente.

`tests/unit/ServiceRequestUpdateTest.php` (nuevo) con los casos de esta función:
`open` sin ofertas, `open` con una, `open` con una `withdrawn`, cada uno de los
otros cinco estados, y estado vacío o desconocido.

Nadie la llama todavía. El módulo sigue respondiendo `405` a un `POST` sobre el
item.

### Paso 4 — El endpoint y su rama en el dispatcher

`resources/service_request.resource.inc`: escribir
`myapi_service_request_update($nid)` completo —el orden de la sección
«Escrituras» del modelo de datos, de la validación del `{id}` al `myapi_respond()`—
y cambiar la rama `POST` de `myapi_service_request_item_dispatch()` para que la
llame.

Es el paso más grande del plan, unas 130 líneas, y **no se parte**: cualquier
corte deja un `POST` que valida y no escribe, o que escribe y no responde. Lo
que sí está fuera de él es todo lo que los pasos 1 a 3 ya dejaron hecho.

`drush cc all`. Pruebas manuales con curl, en este orden: `405` con `PUT`, `404`
con `/abc`, `401` sin token, `403` con el token de otro residente, `409` sobre
una `cancelled`, `422` sin `description`, y por fin el `200` que cambia el
título y sube una imagen.

### Paso 5 — El resto de los tests

Ampliar `tests/unit/ServiceRequestUpdateTest.php` con lo que no es la compuerta
y sigue siendo puro: el cálculo del cupo de imágenes, la construcción de la
lista final de `field_images` (supervivientes en su orden más nuevas al final),
la regla de `remove_attachment` ignorado cuando llega un `attachment`, y la
respuesta de dieciséis claves —que las tres ausentes no estén y que las
dieciséis presentes estén en el orden documentado—.

### Paso 6 — La documentación

`docs/service-request.md`: sección nueva `POST /api/v1/service-requests/{id}`
con la plantilla de `CLAUDE.md` — cabeceras, cuerpo `multipart/form-data`,
respuesta de éxito con las dieciséis claves, y la tabla de errores completa.
Incluye, con esas palabras, los dos avisos que la app necesita: que la respuesta
**no** trae `offers`, `offers_count` ni `transactions` y por tanto no sustituye
al objeto del detalle, y que el método es `POST` y no `PUT` porque PHP no puebla
`$_FILES` en un `PUT`.

---

## Criterios de aceptación

### Ruta y método

- [ ] `POST /api/v1/service-requests/{id}` existe y no responde `405`.
- [ ] `PUT` y `DELETE` sobre esa ruta siguen respondiendo `405 method_not_allowed`,
      sin cabecera `Authorization` y sin que la solicitud tenga que existir.
- [ ] `GET /api/v1/service-requests/{id}` sigue devolviendo las diecinueve claves
      de siempre, sin un cambio.
- [ ] `POST /api/v1/service-requests` (sin id) sigue creando, y no ha cambiado.
- [ ] `hook_menu()` de `myapi.module` no tiene ninguna línea nueva.
- [ ] `myapi.info` no tiene ninguna línea nueva.

### Identificación de la solicitud

- [ ] `/api/v1/service-requests/abc`, `/0` y `/-3` responden `404
      service_request_not_found` sin consultar el token.
- [ ] Un `{id}` que no existe, que es de otro bundle, que está despublicado o
      cuyo término de categoría fue borrado responde `404`, los cuatro iguales.

### Autenticación y acceso

- [ ] Sin cabecera `Authorization`: `401 missing_authorization`.
- [ ] Con token inválido o caducado: `401 invalid_token`.
- [ ] Con el token de un residente que no es el `field_requester`: `403
      service_request_forbidden`.
- [ ] Una solicitud registrada por un operador a nombre de un residente
      (`node.uid` operador, `field_requester` residente) la edita el residente y
      no el operador.
- [ ] El proveedor de la categoría, que sí puede **leer** el detalle, recibe
      `403` al editar.
- [ ] Una solicitud sin `field_requester` responde `403`, nunca `200`.

### Compuerta

- [ ] `open` sin ninguna oferta: la edición procede.
- [ ] `open` con una oferta en `sent`: `409 service_request_not_editable`.
- [ ] `open` con una única oferta en `withdrawn`: `409` igualmente.
- [ ] `open` con una única oferta en `rejected`: `409` igualmente.
- [ ] `offered`, `direct`, `assigned`, `closed` y `cancelled`: `409`, los cinco.
- [ ] Un `field_request_status` vacío o con un valor fuera del catálogo: `409`,
      nunca `500`.
- [ ] El `409` llega **después** del `404` y del `403`: una solicitud ajena y no
      editable responde `403`.

### Campos de texto

- [ ] Falta `title`, `description` o `desired_start`: `422 missing_field` con
      `@field` señalando el que falta.
- [ ] `title` de 256 caracteres: `422 invalid_field` con `@field: title`.
- [ ] `title` de 255 caracteres: aceptado.
- [ ] `description` de solo espacios: `422 invalid_field`.
- [ ] `desired_start` que `strtotime()` no parsea: `422 invalid_field`.
- [ ] `desired_start` en el pasado: `422 invalid_field`, aunque sea el valor que
      la solicitud tiene guardado ahora mismo.
- [ ] Los tres campos se guardan tal cual llegan, ya trimados por
      `myapi_request_post_field()`; los saltos de línea de `description` se
      conservan.

### Imágenes

- [ ] `images[]` con dos ficheros sobre una solicitud que tenía una: quedan tres,
      las nuevas al final y en el orden en que llegaron.
- [ ] `remove_image_ids[]` con un fid de la propia solicitud: la imagen
      desaparece de la respuesta, del `GET` posterior, de `file_managed` y del
      disco.
- [ ] `remove_image_ids[]` con un fid de otra solicitud, de un reclamo o
      inexistente: `422 invalid_field` con `@field: remove_image_ids`, y ni un
      solo fichero tocado.
- [ ] `remove_image_ids[]` con `abc`, `0` o `-1`: el mismo `422`.
- [ ] Un fid repetido en `remove_image_ids[]` se trata una sola vez y no es un
      error.
- [ ] Solicitud con 5 imágenes: subir una más sin borrar ninguna responde
      `422 service_request_too_many_images`.
- [ ] Solicitud con 5 imágenes: borrar tres y subir tres en la misma petición
      funciona y deja 5.
- [ ] Una imagen de tipo o tamaño no permitido responde
      `422 service_request_invalid_image` y **ninguna** de las imágenes de esa
      misma petición queda guardada.
- [ ] Se pueden borrar todas las imágenes: una solicitud sin ninguna es válida.

### Adjunto

- [ ] `attachment` nuevo sobre una solicitud que ya tenía uno: el viejo
      desaparece de `file_managed` y del disco, y el nuevo queda referenciado.
- [ ] `remove_attachment=1` sin `attachment`: el adjunto se borra y la respuesta
      trae `attachment: null`.
- [ ] `remove_attachment=1` **con** `attachment` nuevo: la bandera se ignora y
      queda el fichero nuevo.
- [ ] Sin `attachment` y sin `remove_attachment`: el adjunto que hubiera se queda
      exactamente como estaba.
- [ ] Un adjunto inválido responde `422 service_request_invalid_attachment` y
      las imágenes que la misma petición acababa de guardar se borran con él.

### Efecto sobre la solicitud

- [ ] `field_request_status` sigue en `open` después de la edición.
- [ ] `field_category`, `field_unit`, `field_condominium`, `field_requester`,
      `field_assigned_provider`, `field_assigned_offer` y `field_closed_at`
      valen después lo mismo que valían antes.
- [ ] `node.uid` no cambia: editar no se apropia de la solicitud.
- [ ] El nodo sigue publicado.
- [ ] `{node}.changed` sí cambia — es el `node_save()`, y es correcto.
- [ ] El timeline no gana ninguna entrada: `transactions` del `GET` posterior
      tiene exactamente los mismos elementos que antes de editar.
- [ ] No se envía ninguna notificación ni ningún correo.
- [ ] Dos ediciones seguidas funcionan las dos: esto no es idempotente ni lo
      necesita.

### Respuesta

- [ ] `200` con `success: true` y `message` traducido de
      `service_request_updated`.
- [ ] El objeto `service_request` trae **dieciséis** claves, en el orden
      documentado.
- [ ] No trae `offers`, ni `offers_count`, ni `transactions`.
- [ ] Las dieciséis que trae valen byte a byte lo mismo que las mismas dieciséis
      del `GET /api/v1/service-requests/{id}` ejecutado justo después.
- [ ] `viewer` vale `"requester"`.
- [ ] `images` es siempre un array, vacío cuando no hay ninguna, nunca `null`.
- [ ] `attachment` es `null` cuando no hay, y no `{fid: null}`.

### El reclamo, intacto

- [ ] `POST /api/v1/claims/{id}` con `remove_image_ids[]` sigue borrando la
      imagen y devolviendo el mismo objeto que antes del paso 2.
- [ ] Los mismos códigos de error, con los mismos `@field`.
- [ ] `resources/claim.resource.inc` ya no define
      `myapi_claim_node_file_fids()`, `myapi_claim_update_parse_removals()` ni
      `myapi_claim_update_delete_files()`, y ninguna otra línea del módulo las
      nombra.
- [ ] Los tests unitarios del reclamo pasan sin haber sido modificados.

### Tests unitarios — `tests/unit/ServiceRequestUpdateTest.php`

- [ ] Cubre la compuerta en los ocho casos de estado y en los tres de ofertas.
- [ ] Cubre el cálculo del cupo, incluido el caso en que las eliminaciones
      superan a las imágenes existentes y `max_nuevas` se topa en 0.
- [ ] Cubre la lista final de `field_images`: supervivientes en su orden de
      delta, nuevas al final, y reindexado sin huecos.
- [ ] Cubre que `remove_attachment` se ignora cuando llega un `attachment`.
- [ ] Cubre que la respuesta tiene las dieciséis claves y no las tres ausentes.
- [ ] La suite entera pasa.

### Documentación

- [ ] `docs/service-request.md` tiene la sección del endpoint, con la plantilla
      de `CLAUDE.md`.
- [ ] Dice que la respuesta no trae `offers`, `offers_count` ni `transactions`,
      y que por eso no sustituye al objeto del detalle.
- [ ] Dice por qué es `POST` y no `PUT`.
- [ ] La tabla de errores lista los siete códigos HTTP que el endpoint puede
      devolver.

---

## Decisiones tomadas y descartadas

**El método y la ruta**

- **Sí:** `POST /api/v1/service-requests/{id}`. PHP no puebla `$_POST` ni
  `$_FILES` en un `PUT`: el cuerpo `multipart/form-data` llegaría crudo por
  `php://input` y habría que escribir un parser MIME a mano. Aquí hay ficheros,
  así que la decisión está tomada por el lenguaje. Es el segundo `POST` sobre un
  item del módulo, después del reclamo, y el motivo está documentado en
  `docs/claim.md` desde el SPEC 67.
- **No:** `PUT`, ni `PATCH`. El primero por lo anterior; el segundo porque no lo
  usa ninguna ruta del módulo y estrenarlo para esto sería un idioma nuevo por
  un endpoint.
- **No:** una ruta `/edit` propia, al estilo de `/cancel`. El `cancel` la tiene
  porque es una acción con nombre sobre un recurso; una edición es la escritura
  del recurso mismo, y la ruta del item ya lo nombra.

**Qué se puede editar**

- **Sí:** `title`, `description`, `desired_start`, imágenes y adjunto. Son los
  campos que describen **el trabajo pedido**, y corregir un typo o mover la
  fecha no cambia quién ve la solicitud ni qué evaluó nadie.
- **No:** `category_id`. Cambiar de categoría cambia el público de proveedores
  que la ve; eso es otra solicitud, no una edición.
- **No:** `unit_id`, y con él el condominio derivado. Mismo argumento, y más
  fuerte: el condominio es el ámbito de visibilidad entero.
- **No:** `assigned_provider_id`. Una solicitud con proveedor asignado está en
  `direct`, y `direct` no es editable, así que no hay caso que atender.
- **No:** reordenar las imágenes. Las supervivientes conservan su orden y las
  nuevas van al final. Reordenar necesita que el cliente mande el orden completo
  y que el servidor lo valide contra lo que hay: es otro contrato.

**Reemplazo total y no parcial**

- **Sí:** los tres campos de texto obligatorios en cada llamada, `422
  missing_field` si falta uno. Es el contrato del SPEC 67, y con tres campos el
  coste para el cliente es una línea.
- **No:** edición parcial —tocar solo lo que llega—. Obliga a distinguir «campo
  ausente» de «campo vacío» en `multipart/form-data`, donde esa diferencia es
  resbaladiza, y a documentar por campo qué significa no mandarlo. Más contrato
  para menos claridad.

**Cuándo se puede editar**

- **Sí:** solo `open` y con cero ofertas. Antes de que nadie haya invertido
  trabajo en leer y presupuestar el enunciado, cambiarlo no perjudica a nadie;
  después, sí.
- **Sí:** cuenta **cualquier** oferta publicada, con el estado que sea. Un
  proveedor que ofertó y se retiró leyó el enunciado igual, y el que fue
  rechazado también. El criterio caro de explicar es el que distingue, no el que
  no distingue.
- **No:** contar solo las vivas (`sent`, `selected`), que es lo que hace
  `myapi_service_request_reject_live_offers()`. Allí la pregunta es «¿a quién
  hay que rechazar?»; aquí es «¿alguien leyó esto ya?». Son dos preguntas
  distintas y compartir la respuesta las haría divergir.
- **Sí:** la condición de las ofertas se comprueba aunque el grafo diga que la
  primera oferta mueve `open → offered`. Hoy nada ejecuta esa transición
  —`myapi.module` no tiene rama `service_offer` en ningún hook— así que `open`
  con ofertas es un estado real y alcanzable desde el back office.
- **Sí:** un solo `error_code`, `service_request_not_editable`, para los dos
  motivos. Para el residente el resultado es el mismo, y dos códigos obligan al
  cliente a mantener dos mensajes para una sola pantalla.
- **No:** `service_request_has_offers` como código aparte. Se puede añadir el día
  que la app quiera decir «un proveedor ya te ofertó»; añadirlo hoy es adivinar
  una pantalla que no existe.
- **Sí:** comparación estricta contra el literal `'open'`, no
  `myapi_services_transition_allowed()`. Aquí no hay transición de estado que
  consultar —el estado no se mueve—, así que preguntarle al grafo sería usarlo
  para algo que no responde.

**El 404, y por qué no hay respuesta degradada**

- **Sí:** `myapi_service_request_detail_row()` primero, `node_load()` después y
  solo para escribir. Una consulta resuelve el `404`, el `403` y el `409`, y —lo
  importante— lo que no se puede serializar responde `404` **antes** de escribir.
- **No:** `node_load()` primero, como hace el `cancel`. Allí eso obliga a una
  rama que, cuando el término de categoría fue borrado, degrada la respuesta a
  dos claves con un `watchdog` **después** de haber escrito. Aquí esa rama no
  hace falta y no se escribe.
- **Sí:** una solicitud con la categoría huérfana responde `404`, exactamente
  como ya responde el `GET`. No es editable porque no es legible.

**Acceso**

- **Sí:** el `field_requester` exacto, nunca `node->uid`. Una solicitud que un
  operador registró a nombre de un residente es del residente. Mismo criterio
  que el SPEC 89 para leer y el SPEC 95 para cancelar.
- **No:** el resto de la vivienda. Una solicitud la firma una persona; el hogar
  no hereda el derecho a reescribirla.
- **No:** el administrador de edificio ni el operador. Ya editan desde el
  formulario nativo de Drupal, con permisos y registro propios.
- **Sí:** reutilizar `service_request_forbidden` y **reescribir** su texto a algo
  neutro. El `error_code` es lo que el cliente consume y no cambia; el texto
  hablaba solo de cancelar porque hasta hoy solo existía la cancelación.
- **No:** un `service_request_edit_denied` nuevo, que es lo que hizo el reclamo
  con `claim_edit_denied`. Dos códigos para «no es tuya» en el mismo recurso
  obligan a la app a tratar como distintos dos casos idénticos.

**La respuesta**

- **Sí:** dieciséis claves. `offers`, `offers_count` y `transactions` no cambian
  con una edición —la compuerta acaba de probar que no hay ofertas— así que
  consultarlas sería pagar por confirmar lo ya sabido.
- **No:** las diecinueve del detalle, que es lo que devuelven la creación y la
  cancelación. Se acepta a cambio que **este objeto no sustituye al del `GET`**
  y que la app tiene que fusionarlo sobre el que ya tiene. Queda escrito en la
  documentación con esas palabras, porque es el filo de esta decisión.
- **Sí:** construirlo llamando a `myapi_service_request_build_detail()` y
  quitando las tres claves. Un serializador y no dos: las dieciséis restantes
  salen de la misma función que sirve el `GET` y no pueden divergir por
  accidente.
- **No:** un serializador propio para la edición. Sería la segunda verdad sobre
  la forma de una solicitud, y divergiría el día que el detalle gane una clave.

**El timeline**

- **Sí:** editar no crea transacción y no notifica a nadie.
  `hook_node_update()` no tiene rama `'service_request'`, así que esto cuesta
  cero líneas, pero se toma como decisión y no como consecuencia.
- **No:** una transacción «editada». `field_request_status` es obligatorio en
  `service_transaction`, así que habría que escribirle `open`, lo que dispara
  `myapi_service_transaction_sync_request_status()` y mete un guardado extra de
  la solicitud en un flujo que no cambia de estado. Un timeline de estados no es
  un registro de auditoría; si hace falta auditoría, es otro spec.

**Ficheros**

- **Sí:** mover los tres helpers del SPEC 67 a `includes/myapi.node_files.inc`.
  Son genéricos —reciben nodo, nombre de campo y fids, y no saben qué es un
  reclamo— y las reglas 3 y 5 de `CLAUDE.md` mandan. El precedente exacto está
  en el `@file` de ese mismo include: el SPEC 90 movió ahí
  `myapi_claim_create_save_files()` por este mismo motivo.
- **No:** copiarlas en `service_request.resource.inc`. Sería la duplicación que
  las reglas prohíben, y la primera en divergir sería la validación del `422`.
- **No:** dejar wrappers con los nombres viejos en el reclamo. Nada más los
  llamaba, igual que en el SPEC 90.
- **Sí:** borrado real de disco (`file_usage_delete()` + `file_delete()`) y no
  esperar al cron. Cada corrección de un residente dejaría peso muerto si no.
- **Sí:** ese borrado **después** del `node_save()`. Borrar antes abre una
  ventana en la que el nodo referencia un fichero que ya no existe, y un
  `node_save()` que falle deja la solicitud apuntando a la nada.
- **Sí:** `remove_attachment` se ignora cuando llega un `attachment` nuevo. La
  cardinalidad del campo es 1, así que subir uno ya sustituye al anterior: el
  resultado con y sin la bandera es idéntico y un `422` rechazaría una petición
  cuyo significado no es ambiguo.

**`desired_start` sigue exigiéndose futura**

- **Sí:** la misma regla que la creación, con el mismo
  `myapi_service_request_parse_desired_start()`.
- **No:** dejarla pasar si es idéntica a la guardada. Tiene un filo real —una
  solicitud vieja con la fecha ya vencida obliga a fijar fecha nueva para
  corregir el título— pero es pequeño: solo alcanza a solicitudes `open`, sin
  ofertas y con la fecha pasada. La alternativa mete una comparación de cadenas
  cuya semántica («idéntica» ¿antes o después de normalizar?) es más frágil que
  el problema que resuelve.
- **No:** no exigir futuro en la edición. Permitiría dejar publicada, y visible
  para los proveedores, una solicitud que pide un servicio para el mes pasado.

**Sin transacción de base de datos**

- **Sí:** igual que el `cancel` y que el reclamo. Un `node_save()` con la Field
  API y sus hooks dentro de una transacción explícita es una fuente conocida de
  bloqueos en Drupal 7.
- **No:** `db_transaction()` alrededor de la escritura. Lo único que puede quedar
  a medias es el borrado de ficheros del último paso, y su consecuencia es peso
  muerto en disco, no datos incoherentes.

**Concurrencia**

- **No:** control optimista (`If-Unmodified-Since`, un `updated_at` en el
  cuerpo). Dos ediciones simultáneas son las dos del mismo residente, desde el
  mismo teléfono: gana la última, y es lo que esa persona espera.

---

## Riesgos identificados

| Riesgo | Mitigación |
|--------|------------|
| **Reescribir `service_request_forbidden` cambia un texto que ya está en producción** y que hoy consume el `cancel`. Una app que pinte el campo `error` tal cual verá cambiar la copia de esa pantalla. | El `error_code` no cambia, que es lo que el cliente debe consumir para decidir. El cambio queda anotado en la sección de i18n del spec y en `docs/service-request.md`, y es una sola cadena por idioma. |
| **El paso 2 toca `resources/claim.resource.inc`, que está en producción**, para un endpoint que no tiene nada que ver con reclamos. Un fallo ahí rompe la edición de reclamos, no la de solicitudes. | El movimiento es una copia literal salvo el nombre y un parámetro. Va en su propio paso y su propio commit, antes de que exista una línea del endpoint nuevo, y los tests unitarios del reclamo se ejecutan **sin modificarlos**: si el movimiento rompe algo, lo dicen antes de seguir. |
| **La respuesta de dieciséis claves no sustituye al objeto del detalle.** Un cliente que hoy hace `detalle = respuesta.service_request` después de crear o cancelar hará lo mismo aquí y se quedará sin `offers`, `offers_count` ni `transactions` en pantalla. | Documentado con esas palabras en `docs/service-request.md`, y con un criterio de aceptación propio. Es el precio explícito de la decisión, no un descuido. |
| **`open` con ofertas es alcanzable hoy** porque nada sincroniza `open → offered`: una oferta creada desde el back office no mueve el estado. Sin la segunda condición de la compuerta, el residente editaría un enunciado que un proveedor ya presupuestó. | Es exactamente lo que la segunda condición cubre. El día que exista el endpoint de ofertas y sincronice el estado, esa condición dejará de ser la que dispara, pero no sobra: seguirá siendo la red por debajo del back office. |
| **`file_usage_delete($file, 'myapi', 'node', $nid, 0)` borra TODAS las filas de uso que el módulo tiene sobre ese fid**, que es lo que permite que `file_delete()` lo elimine de verdad. Solo es correcto mientras un fid pertenezca a un único nodo. | Hoy se cumple: cada subida crea su propia fila en `file_managed`, y `myapi_service_request_file_request_nid()` ya asume esa relación 1:1 para autorizar las descargas. El aviso viaja en el docblock de `myapi_node_files_delete_removed()`: un spec futuro con ficheros compartidos entre nodos tiene que revisar esa línea antes que ninguna otra. |
| **Una solicitud `open` cuya `desired_start` ya venció no se puede editar sin fijar fecha nueva**, porque la validación exige futuro y el reemplazo es total. Un residente que solo quiere corregir el título recibe un `422`. | Aceptado y documentado como decisión. El alcance real es estrecho —`open`, sin ofertas y con la fecha pasada— y el `422` trae `@field: desired_start`, así que la app puede pedir la fecha nueva en vez de mostrar un error opaco. |

---

## Lo que **no** está en este spec

- Editar `category_id`, `unit_id` o `assigned_provider_id`.
- Reordenar las imágenes de una solicitud.
- Editar en `offered`, `direct`, `assigned`, `closed` o `cancelled`, ni en una
  `open` que ya recibió ofertas.
- Editar desde el proveedor, el operador o el administrador de edificio.
- Dejar rastro de la edición: ni transacción en el timeline, ni notificación,
  ni historial de qué cambió.
- Control de concurrencia entre dos ediciones simultáneas.
- El endpoint de ofertas y la sincronización `open → offered`.
- `PUT` sobre el item.

Cada una de ellas, si llega, va en su propio spec.
