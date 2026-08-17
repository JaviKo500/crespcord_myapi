# 89 — Detalle de una solicitud de servicio (`GET /api/v1/service-requests/{id}`)

- **Estado:** Implemented
- **Fecha:** 2026-08-17
- **Dependencias:**
  - `88-service-requests-list` (Implemented) — la **hermana exacta**. Es dueña de
    `resources/service_request.resource.inc`, del dispatcher, de
    `myapi_service_request_base_query()` y del serializador de diez claves que
    este spec **extiende**, no reescribe. El detalle es el listado más seis
    claves y una lista de ofertas.
  - `77-services-content-types-install` (Implemented) — dueña de los bundles
    `service_request` y `service_offer` y de **todos** los campos que aquí se
    leen por primera vez: `field_images` y `field_attachment` (ambos
    `private://` **a nivel de campo**), `field_closed_at`, `field_condominium`,
    y los del lado oferta (`field_provider`, `field_offer_message`,
    `field_offer_amount`, `field_offer_status`). Cero cambios de esquema.
  - `86-service-request-unit` (Implemented) — dueña de la instancia `field_unit`
    en `service_request`, que es la clave `unit` de la respuesta y la que **no**
    viaja al proveedor.
  - `87-service-request-direct-status` (Implemented) — la razón de que
    `assigned_offer` y `assigned_provider` sigan siendo dos claves hermanas, y
    de que una solicitud `direct` pueda responder `offers: []` con proveedor
    adjudicado.
  - `78-provider-role` (Implemented) — la **segunda mitad de este spec**. De
    ella salen `myapi_provider_role_provider_ids()`,
    `myapi_provider_role_category_ids()`,
    `myapi_provider_role_any_provider_active()` y
    `myapi_provider_role_has_offered()`, que son las cuatro preguntas de las que
    cuelga el acceso del proveedor. Ninguna consulta de este spec lleva
    `addTag('node_access')`, igual que SPEC 88.
  - `65-claim-files-private-and-download` (Implemented) — el **precedente de
    forma** para los ficheros privados: un endpoint `/{id}/files/{fid}`
    autenticado, un `includes/` que resuelve a quién pertenece un fid, y un
    `hook_file_download()` que decide para el back office. Este spec repite ese
    patrón sobre `service_request` en vez de copiar código.
  - `64-claims-list-and-detail` (Implemented) — el precedente de **un
    serializador compartido por lista y detalle**, y de la carga de imágenes de
    una página en una sola consulta (`myapi_claim_load_images()`).
  - `09-units-owner-occupant` (Implemented) — dueña de la regla del nombre de un
    usuario (`field_nombre + field_apellidos`, o `users.name`), que este spec
    **extrae** de `resources/unit.resource.inc` a `includes/` en vez de
    duplicarla.

**Objetivo:** Servir el detalle completo de una solicitud de servicio —sus
campos, su vivienda y condominio, sus imágenes y adjunto descargables, y sus
ofertas— al residente que la creó y, recortado, al proveedor de la categoría que
todavía puede ofertar por ella.

Cinco notas que la cabecera fija:

- **Dos lectores, dos respuestas.** Es el primer endpoint del módulo cuya
  respuesta **depende de quién pregunta**: el residente ve su solicitud entera
  con todas las ofertas; el proveedor ve la misma forma sin `unit` y con
  `offers` recortado a la suya. Las claves son siempre las mismas —ninguna
  aparece ni desaparece—, lo que cambia es su contenido.
- **El acceso son dos reglas independientes, y basta con una.** Ser el
  solicitante (`field_requester = uid`), o ser un proveedor activo de la
  categoría con la solicitud todavía sin adjudicar. Quien ya ofertó conserva el
  acceso aunque la solicitud avance.
- **Trae un segundo endpoint, y no es opcional.** `field_images` y
  `field_attachment` viven en `private://`: sin
  `GET /api/v1/service-requests/{id}/files/{fid}` las imágenes viajarían con
  nombre y sin nada que pintar. Es la misma decisión que SPEC 65 tomó para
  claims, con la regla de acceso de **este** spec.
- **Cero escritura y cero esquema.** Ni crea, ni edita, ni adjudica, ni oferta.
  `myapi.install` no se toca: los ocho campos que se leen por primera vez
  existen desde SPEC 77.
- **`403` y `404` significan cosas distintas**, a diferencia de lo que hace el
  listado (que simplemente no muestra la fila): si la solicitud no existe o está
  despublicada es `404`; si existe y el lector no encaja en ninguna de las dos
  reglas es `403`.

---

## Alcance

### Dentro del alcance

**`resources/service_request.resource.inc`** (modificar — es el fichero que
SPEC 88 creó y sigue siendo el único con lógica de este recurso):

- `myapi_service_request_item_dispatch($nid)` — dispatcher de
  `api/v1/service-requests/%`: `GET` al detalle, cualquier otro método a
  `405 method_not_allowed`. Hermano del que ya existe para la colección, no una
  rama dentro de él.
- `myapi_service_request_detail($nid)` — orquestación: validar el `nid`,
  autenticar, resolver **quién es el lector**, cargar la solicitud, sus
  imágenes, su adjunto y sus ofertas, y serializar.
- `myapi_service_request_viewer($row, $uid)` — **la función de acceso**, y la
  pieza central del spec. Devuelve `'requester'`, `'provider'` o `NULL` (→
  `403`). Es la única que decide, y decide en este orden: solicitante →
  proveedor que ya ofertó → proveedor activo de la categoría con la solicitud
  sin adjudicar.
- `myapi_service_request_detail_row($nid)` — **una** consulta: la fila completa
  de la solicitud, con la vivienda, el condominio, la categoría, el estado, la
  adjudicación y el adjunto. Reusa `myapi_service_request_base_query()` **sin**
  el filtro por `uid` — ver Decisiones.
- `myapi_service_request_load_images($nid)` /
  `myapi_service_request_build_file($fid, $filename, $request_nid)` — las
  imágenes en una consulta y el objeto `{id, url, filename}` con URL
  autenticada, calcados de `myapi_claim_load_images()` /
  `myapi_claim_build_file()`.
- `myapi_service_request_load_offers($nid, array $provider_ids)` — las ofertas
  de la solicitud en **una** consulta, con su proveedor y su logo.
  `$provider_ids` vacío significa «todas» (el residente); con contenido, la
  consulta filtra por `field_provider` y devuelve solo las del lector.
- `myapi_service_request_build_detail($row, $viewer, array $images, array $offers, $offers_count)`
  — pura, sin base de datos. Envuelve a `myapi_service_request_build_item()` de
  SPEC 88 y le añade las seis claves nuevas; el recorte del proveedor vive aquí
  y en ningún otro sitio.
- `myapi_service_request_file_download($nid, $fid)` —
  `GET /api/v1/service-requests/%/files/%`: token, la **misma**
  `myapi_service_request_viewer()`, comprobación de que el fid pertenece a esa
  solicitud, y `file_transfer()`. Solo lectura.

**`includes/myapi.service_request_files.inc`** (nuevo) — la propiedad de un
fichero, compartida por dos consumidores como manda SPEC 65:

- `myapi_service_request_file_request_nid($fid)` — de qué solicitud es un fid
  (`field_images` o `field_attachment` sobre un nodo `service_request`), o
  `NULL`.
- `myapi_service_request_file_fid_by_uri($uri)` — solo para
  `hook_file_download()`.
- `myapi_service_request_file_access($request_nid, $account)` — la regla del
  **back office** (roles administrativos, y `administrador edificio` acotado a
  sus condominios), no la de la app.
- `myapi_service_request_file_download_headers($uri, $account)` — el cuerpo
  entero del `hook_file_download()` para este bundle: cabeceras, `-1` o `NULL`.

**`includes/myapi.user.inc`** (modificar) —
`myapi_user_display_names(array $uids)`: la regla «`field_nombre` +
`field_apellidos`, y si falta alguno `users.name`» pasa aquí, con
`resources/unit.resource.inc` delegando en ella. Es el segundo consumidor, que
es exactamente cuando la Regla 3 de `CLAUDE.md` se activa.

**`myapi.module`** (modificar): las rutas `api/v1/service-requests/%` y
`api/v1/service-requests/%/files/%` en `myapi_menu()`, y una línea en
`myapi_file_download()` para que consulte también al include nuevo. Nada de
lógica.

**`myapi.info`** (modificar):
`files[] = includes/myapi.service_request_files.inc`.

**`docs/service-request.md`** (modificar): los dos endpoints nuevos con la
plantilla de `CLAUDE.md`, la tabla de qué ve cada lector, y la regla de
mantenimiento de los ficheros privados.

**`tests/unit/ServiceRequestDetailEndpointTest.php`** (nuevo), al estilo de
`ServiceRequestListEndpointTest`.

`drush cc all` al final (rutas nuevas e include nuevo).

### Fuera del alcance

- **Toda escritura.** Crear, editar, cancelar, cerrar, adjudicar, subir o borrar
  ficheros. `POST`, `PUT` y `DELETE` responden `405` en las dos rutas.
- **Las ofertas como recurso propio.** Crearlas, retirarlas, listarlas fuera de
  una solicitud, `GET /api/v1/offers/{id}`. Aquí solo se **leen** las de una
  solicitud concreta.
- **El chat.** `field_firebase_path`, `field_chat_opened_at` y
  `field_last_message_at` no viajan: quién abre el hilo y cuándo se genera la
  ruta es otro spec, y una clave servida hoy es un contrato que ese spec ya no
  podría cambiar.
- **La línea de tiempo.** `service_transaction` existe desde SPEC 77 y no se
  sirve: no se pidió, y el detalle ya trae dos listas.
- **Calificaciones.** Ni se sirven ni se exigen.
- **El listado del proveedor** — «las solicitudes que puedo atender». Este spec
  le da al proveedor el **detalle** de una solicitud que ya conoce; cómo llega a
  conocerla es el otro lado del mercado y tiene dueña escrita
  (`myapi_provider_role_visible_request_ids()`).
- **Datos de contacto del solicitante.** `requester` viaja con `id` y `name` y
  nada más: ni teléfono, ni email, ni cédula, para ninguno de los dos lectores.
  `myapi_user_fetch_profile_fields()` no se llama.
- **Paginación de las ofertas.** Van todas, sin `?page`. Una solicitud recibe
  unidades de ofertas, no cientos.
- **Miniaturas y estilos de imagen.** La URL sirve el fichero original;
  `private://styles/...` no se genera ni se ofrece.
- **`?include=`, ETag, caché condicional.** El detalle responde siempre entero.
- **`myapi.install`, `hook_schema()` y cualquier campo o instancia.** Cero
  cambios de esquema: los ocho campos que se leen por primera vez existen desde
  SPEC 77/86.
- **Etiquetar cualquier consulta con `node_access`.** Decisión explícita,
  heredada de SPEC 88: ver Decisiones.
- **Tocar el listado de SPEC 88.** Su respuesta no cambia ni una clave; el
  detalle lo **extiende** llamando a su serializador, no lo reescribe.
- **Notificaciones.** Endpoint de lectura: no dispara ninguna.

---

## Modelo de datos

**Este spec no introduce ninguna estructura nueva.** Ni campo, ni instancia, ni
tabla, ni `myapi_update_XXXX()`. Lo que define es **la regla de acceso**, **la
forma de la respuesta** y **el plan de consultas**.

### La regla de acceso: `myapi_service_request_viewer()`

Devuelve `'requester'`, `'provider'` o `NULL`. Se evalúa en este orden y la
primera que acierta gana:

| # | Regla | Condición exacta | Resultado |
|---|---|---|---|
| 0 | La solicitud existe | `n.type = 'service_request' AND n.status = 1` | Si no: **`404 not_found`**, sin llegar a evaluar nada más |
| 1 | Es el solicitante | `field_requester_target_id = uid` | `'requester'` |
| 2 | Ya ofertó | `myapi_provider_role_has_offered($nid, $provider_ids)` | `'provider'` — **cualquiera que sea el estado**: quien tiene una oferta viva no la pierde de vista porque la solicitud avance a `assigned`, `closed` o `cancelled` |
| 3 | Proveedor elegible | `status ∈ ('open','offered')` **y** `field_assigned_offer` vacío **y** `field_assigned_provider` vacío **y** `field_category_tid ∈ myapi_provider_role_category_ids($account)` **y** `myapi_provider_role_any_provider_active($account)` | `'provider'` |
| — | Ninguna | | **`403 forbidden`** |

Tres detalles que la tabla fija:

- **`direct` no entra por la regla 3**, aunque su proveedor esté en el campo y no
  en una oferta: nace adjudicada, y «sin proveedor adjudicado» la excluye. Su
  proveedor entra por la regla 2 solo si además ofertó — que por definición no
  hizo. **Consecuencia asumida y anotada en Riesgos:** hoy el proveedor de una
  solicitud `direct` **no** puede ver su detalle. Lo resolverá el spec que cree
  las solicitudes directas, que es quien sabrá qué relación deja escrita.
- **Las dos claves de adjudicación se comprueban las dos**, no solo el estado.
  Una solicitud que quedó en `offered` con `field_assigned_offer` ya relleno
  —dato incoherente que hoy nadie impide— deja de ser ofertable, que es la
  lectura segura.
- **`myapi_provider_role_broadcast_statuses()` NO se usa aquí.** Incluye
  `direct`, y esta regla no. Se lee, se descarta y se dice por qué en el código:
  el back office y la app responden a preguntas distintas sobre el mismo dato.

### La respuesta del **solicitante**

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 128,
      "title": "Fuga en el calentador",
      "description": "El calentador del baño principal gotea desde el lunes.",
      "status": "offered",
      "category": { "id": 12, "name": "Plomería" },
      "offers_count": 2,
      "assigned_offer": null,
      "assigned_provider": null,
      "created": "2026-08-14T09:12:33",
      "desired_start": "2026-08-19T08:00:00",

      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "unit": { "id": 55, "name": "A-301" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [
        { "id": 91, "url": "https://.../api/v1/service-requests/128/files/91", "filename": "fuga.jpg" }
      ],
      "attachment": { "id": 92, "url": "https://.../api/v1/service-requests/128/files/92", "filename": "presupuesto.pdf" },
      "closed_at": null,
      "offers": [
        {
          "id": 46,
          "provider": { "id": 9, "name": "Servicios Díaz", "logo": "https://.../sites/default/files/logo-diaz.png" },
          "amount": 95.5,
          "message": "Puedo pasar el jueves por la mañana.",
          "status": "sent",
          "created": "2026-08-15T18:40:02"
        },
        {
          "id": 45,
          "provider": { "id": 7, "name": "Plomería Rivas", "logo": null },
          "amount": null,
          "message": "Necesito ver la instalación antes de dar precio.",
          "status": "sent",
          "created": "2026-08-15T11:03:17"
        }
      ]
    }
  }
}
```

Las **diez primeras claves son, literalmente, las de
`myapi_service_request_build_item()`** (SPEC 88), en su mismo orden y producidas
por esa misma función. Detrás van las siete nuevas.

### La respuesta del **proveedor**

Mismas diecisiete claves, siempre, sin que ninguna aparezca ni desaparezca.
Cambia el contenido de tres:

| Clave | Solicitante | Proveedor |
|---|---|---|
| `viewer` | `"requester"` | `"provider"` |
| `unit` | `{ id, name }` | **`null`** |
| `offers` | **todas**, orden por `created DESC` | **solo las suyas** (cero o una, mismo formato y mismo orden) |
| `offers_count` | el total | **el total, igual** — sabe contra cuántos compite, no quiénes ni cuánto |
| `requester` | `{ id, name }` | `{ id, name }`, igual |
| `condominium` | `{ id, name }` | igual |
| `images` / `attachment` | descargables | descargables, mismas URLs |
| resto | | igual |

`viewer` va porque sin ella `unit: null` es indistinguible de «la solicitud no
tiene vivienda», y el cliente Flutter no puede decidir si pintar el botón de
ofertar o el de adjudicar.

### De dónde sale cada clave nueva

| Clave | Origen | Nulo posible | Notas |
|---|---|:---:|---|
| `viewer` | `myapi_service_request_viewer()` | No | `"requester"` o `"provider"`. Nunca viaja `null`: un `NULL` ya respondió `403`. |
| `requester` | `field_requester` → `users` + `myapi_user_display_names()` | No¹ | `{ id, name }`. El nombre es `field_nombre + field_apellidos`, o `users.name` si falta alguno — nunca un híbrido. Sin teléfono, email ni cédula. |
| `unit` | `field_unit` → `field_nombre_vivienda` | **Sí** | `{ id, name }` o `null` entero para el proveedor. `name` es `field_nombre_vivienda`, **no** el título del nodo `vivienda` — misma regla que `myapi_provider_list()`. |
| `condominium` | `field_condominium` → `node.title` | No¹ | `{ id, name }`. Campo requerido desde SPEC 77. |
| `images` | `field_images` → `file_managed` | No | **Siempre un array**, vacío cuando no hay. Cada elemento `{ id, url, filename }`, en orden de `delta`. |
| `attachment` | `field_attachment` → `file_managed` | **Sí** | `{ id, url, filename }` o `null`. Cardinalidad 1. |
| `closed_at` | `field_closed_at` | **Sí** | `Y-m-d\TH:i:s`, o `null` mientras la solicitud no esté cerrada. |
| `offers` | `service_offer` vía `field_request` | No | **Siempre un array**, vacío cuando no hay (toda `direct`, entre otras). |

¹ Campos requeridos en el bundle; los `LEFT JOIN` los dejan en `NULL` si alguien
borró la fila a mano y el serializador responde `null` sin romperse.

**Cada oferta:**

| Clave | Origen | Nulo posible | Notas |
|---|---|:---:|---|
| `id` | `node.nid` de la oferta | No | Entero JSON. |
| `provider.id` / `.name` | `field_provider` → `node.title` | **Sí** (el objeto entero) | `LEFT JOIN` con `type = 'provider' AND status = 1`: un proveedor despublicado deja `provider: null` y **la oferta sigue en la lista**. |
| `provider.logo` | `field_logo` → `file_create_url()` | **Sí** | URL absoluta **directa**: `field_logo` es `public://` (SPEC 85), a diferencia de las imágenes de la solicitud. |
| `amount` | `field_offer_amount` | **Sí** | **Float o `null`**, nunca `"95.50"` ni `0.0` — misma regla que `hourly_rate` en `GET /api/v1/providers`. El campo es opcional por decisión de SPEC 77: el precio puede cerrarse en el chat. |
| `message` | `field_offer_message` | No¹ | Valor almacenado en crudo, con sus saltos de línea, igual que `description`. |
| `status` | `field_offer_status` | No¹ | Clave de `myapi_services_offer_statuses()`: `sent` / `selected` / `rejected` / `withdrawn`. |
| `created` | `node.created` | No | `Y-m-d\TH:i:s`. |

Solo se listan las ofertas **publicadas** (`n.status = 1`), y **todas** las
publicadas sea cual sea su estado — incluidas `withdrawn` y `rejected`,
exactamente lo mismo que cuenta `offers_count`, para que el número y la lista no
puedan contradecirse.

**Orden: `n.created DESC, n.nid DESC`** — la más reciente arriba, con el
desempate por `nid` por la misma razón que en SPEC 88: dos ofertas del mismo
segundo, sin él, cambian de sitio entre dos lecturas.

### El segundo endpoint: `GET /api/v1/service-requests/{id}/files/{fid}`

No devuelve JSON: devuelve **los bytes** del fichero, o un error con la
envoltura del módulo. Es la copia exacta de `GET /api/v1/claims/%/files/%`
(SPEC 65) con la regla de acceso de este spec.

| Situación | Respuesta |
|---|---|
| Token ausente / inválido | `401` |
| La solicitud no existe o está despublicada | `404 not_found` |
| El lector no pasa `myapi_service_request_viewer()` | `403 forbidden` |
| El fid no pertenece a `field_images` ni a `field_attachment` **de esa** solicitud | `404 not_found` |
| El fichero no existe en disco | `404 not_found` |
| Todo bien | `200` con `Content-Type`, `Content-Length`, `Content-Disposition: inline` y los bytes |

**La comprobación de pertenencia es lo que hace segura la ruta**: sin ella,
`/service-requests/128/files/999` serviría cualquier fichero privado del sitio
con solo tener acceso a *una* solicitud. Y el `hook_file_download()` es lo que
impide lo contrario — que el operador del back office abra `node/128/edit` y no
vea sus propias imágenes, porque en `private://` nadie sirve nada sin una
decisión explícita.

### El plan de consultas

Fijo, y no crece con el número de imágenes ni de ofertas:

| # | Consulta | Cuándo |
|---|---|---|
| 1 | La fila de la solicitud, con vivienda, condominio, categoría, estado, adjudicación y adjunto | siempre |
| 2 | `field_requester` → nombre del solicitante (`myapi_user_display_names()`) | siempre |
| 3 | Las imágenes (`field_images` + `file_managed`), en orden de `delta` | siempre |
| 4 | `offers_count` — `myapi_service_request_offer_counts_by_nid([$nid])`, la función de SPEC 88 **reusada tal cual** | siempre |
| 5 | Las ofertas, con proveedor y logo | siempre |
| — | Los `provider_ids`, categorías y actividad del lector, y `has_offered` | **solo si la regla 1 falló**; estáticamente cacheadas por `myapi_provider_role_*` |

`offers_count` sale de la consulta 4 y **no** de `count($offers)`, aunque para el
solicitante los dos números coincidan: el proveedor recibe la lista recortada y
el total completo, así que derivarlo de la lista le daría siempre `1` o `0`. Un
test fija que para el solicitante los dos coinciden.

La consulta 1 arranca de `myapi_service_request_base_query()` **sin el filtro por
`uid`** — ver Decisiones: la función gana un parámetro opcional en vez de
duplicarse.

---

## Plan de implementación

Nueve pasos. Los siete primeros no cambian el comportamiento de ninguna ruta
existente; el 8 es el que enciende los dos endpoints.

1. **`includes/myapi.user.inc` — extraer la regla del nombre.**
   Nace `myapi_user_display_names(array $uids)`: la lógica que hoy vive en
   `myapi_unit_fetch_user_names()` (`field_nombre` + `field_apellidos`, y
   `users.name` cuando falta cualquiera de los dos, nunca un híbrido), con el
   docblock que dice por qué se movió — dos consumidores, Regla 3 de
   `CLAUDE.md`. `myapi_unit_fetch_user_names()` se queda como envoltorio de una
   línea que delega, para no tocar sus llamadores ni el contrato de
   `GET /api/v1/units`.
   *Verificación: `php -l`, y la suite de units en verde sin haber cambiado ni
   un test.*

2. **`includes/myapi.service_request_files.inc` — la propiedad de un fichero.**
   Las cuatro funciones de la sección anterior, calcadas en forma de
   `includes/myapi.claims_files.inc` y `includes/myapi.provider_files.inc`:
   `..._fid_by_uri()`, `..._request_nid()`, `..._file_access()` (back office) y
   `..._download_headers()`. Con la **regla de mantenimiento** escrita en el
   `@file`: si un spec futuro añade un tercer campo de fichero a
   `service_request`, hay que crearlo con `uri_scheme => private` **y** añadirlo
   a la lista que recorre `..._request_nid()`, o el fichero nace inalcanzable
   para los dos consumidores.
   *Verificación: `php -l`; tests unitarios de la resolución con tablas
   fixture.*

3. **`myapi.info` y `myapi_file_download()`.**
   La línea `files[] = includes/myapi.service_request_files.inc`, y en
   `myapi_file_download()` una consulta más al include nuevo: si claims responde
   `NULL` (no es suyo) y proveedores responde `NULL`, se pregunta a solicitudes;
   si también dice `NULL`, el fichero no es de este módulo y Drupal sigue
   decidiendo como hasta hoy.
   *Verificación: `drush cc all`; el operador abre `node/N/edit` de una solicitud
   con fotos y las ve; un comprobante de pago y una foto de galería siguen
   sirviéndose exactamente igual.*

4. **`resources/service_request.resource.inc` — la regla de acceso y las
   funciones puras.**
   `myapi_service_request_viewer()` con la tabla de acceso en su docblock,
   incluido el párrafo de por qué **no** llama a
   `myapi_provider_role_broadcast_statuses()`;
   `myapi_service_request_build_file()`, `myapi_service_request_build_offer()` y
   `myapi_service_request_build_detail()`, las tres sin base de datos. Nada
   enruta todavía.
   *Verificación: `php -l`; los tests de las tres reglas de acceso y de los tres
   serializadores pasan sin sitio arrancado.*

5. **El mismo fichero — las consultas.**
   `myapi_service_request_base_query()` gana un `$uid` que admite `NULL` («sin
   filtro por solicitante»), con el comentario de que ese es el **único** cambio
   que el detalle le pide al listado y por qué es preferible a una segunda
   consulta base que puede divergir. Luego
   `myapi_service_request_detail_row($nid)`,
   `myapi_service_request_load_images($nid)` y
   `myapi_service_request_load_offers($nid, array $provider_ids)`.
   *Verificación: `php -l`; los tests del listado siguen en verde — es la prueba
   de que el parámetro nuevo no le movió nada.*

6. **El mismo fichero — `myapi_service_request_detail()` y su dispatcher.**
   Validar el `nid` del argumento de ruta antes de nada (un
   `/service-requests/abc` no cuesta ninguna consulta), autenticar, cargar la
   fila, resolver el lector, y de ahí las cuatro cargas y el serializado.
   `myapi_service_request_item_dispatch()` con su `405`.
   *Verificación: `php -l`.*

7. **El mismo fichero — `myapi_service_request_file_download()`.**
   Token, `myapi_service_request_viewer()` — **la misma función, no una copia**—,
   pertenencia del fid a esa solicitud vía el include del paso 2, `file_load()` y
   `file_transfer()`. Sin JSON en el camino feliz.
   *Verificación: `php -l`.*

8. **`myapi.module` — las dos rutas.**
   `api/v1/service-requests/%` y `api/v1/service-requests/%/files/%` en
   `myapi_menu()`, con `'access callback' => TRUE`, `'file'`, `MENU_CALLBACK` y
   `'page arguments'` pasando los comodines. Colocadas justo debajo de
   `api/v1/service-requests`, con el comentario de que Drupal 7 resuelve primero
   por número de segmentos, así que las tres rutas no compiten.
   *Verificación: `drush cc all`; `curl` con token real devuelve `200` con la
   envoltura correcta, y un `curl` a `/files/{fid}` descarga la imagen.*

9. **Tests y documentación.**
   `tests/unit/ServiceRequestDetailEndpointTest.php`, al estilo de
   `ServiceRequestListEndpointTest`: se llama al dispatcher como lo llama
   `hook_menu()`, sobre filas fixture, y se asserta el JSON impreso y el código
   HTTP. Cubre las tres reglas de acceso y sus negativas, el `404` frente al
   `403`, el recorte del proveedor (las tres claves), `offers_count` contra
   `count(offers)`, el orden de las ofertas y su desempate, la oferta con
   proveedor despublicado, el `amount` float y `null`, `images: []`,
   `attachment: null`, `closed_at: null`, el `405`, y los dos guards
   estructurales: que ninguna consulta del fichero lleva `node_access`, y que el
   endpoint de ficheros comprueba la pertenencia del fid.
   Y `docs/service-request.md` con los dos endpoints, la tabla de qué ve cada
   lector, la consulta de diagnóstico de la categoría huérfana y la regla de
   mantenimiento de los ficheros privados.
   *Verificación: suite completa en verde; `drush cc all` sin errores; recorrer
   los criterios de aceptación contra el sitio.*

---

## Criterios de aceptación

**Contrato de respuesta**

- [x] `GET /api/v1/service-requests/{id}` con token válido responde `200` y
      `{"success": true, "data": {"service_request": { }}}` — un objeto bajo
      `service_request`, nunca una lista.
- [x] El objeto trae exactamente **diecisiete** claves: las diez de
      `myapi_service_request_build_item()` en su mismo orden, y después
      `viewer`, `requester`, `unit`, `condominium`, `images`, `attachment`,
      `closed_at` y `offers`. Ni una más, ni una menos, para ninguno de los dos
      lectores.
- [x] Las diez primeras claves valen **byte a byte** lo mismo que en
      `GET /api/v1/service-requests` para esa misma solicitud.
- [x] `id`, `requester.id`, `unit.id`, `condominium.id`, `category.id`,
      `offers_count`, `images[].id`, `attachment.id`, `offers[].id` y
      `offers[].provider.id` viajan como **enteros** JSON, no como strings.
- [x] `images` es siempre un array —vacío cuando no hay— y `offers` también;
      `attachment`, `closed_at` y `unit` sí pueden ser `null` enteros.
- [x] `closed_at` tiene la forma `Y-m-d\TH:i:s` en una solicitud cerrada y es
      `null` en cualquier otra.
- [x] `unit.name` es `field_nombre_vivienda`, **no** el título del nodo
      `vivienda`.
- [x] `requester.name` es «nombre apellidos» cuando ambos campos están, y
      `users.name` cuando falta cualquiera de los dos — nunca un híbrido tipo
      «Ana» a secas cuando el apellido está vacío.
- [x] `requester` no lleva teléfono, email ni cédula para ningún lector.

**Acceso — el solicitante**

- [x] El creador (`field_requester = uid`) ve el detalle en cualquier estado,
      incluidas `closed` y `cancelled`, con `viewer: "requester"`.
- [x] Lo ve aunque además tenga el rol `proveedor` y la categoría de su
      solicitud no sea una de las suyas: el rol no recorta nada, y ninguna
      consulta lleva `addTag('node_access')`.
- [x] Una solicitud creada desde el back office por un administrador **con
      `field_requester` apuntando al lector** se ve: el criterio es
      `field_requester`, no `node.uid`.

**Acceso — el proveedor**

- [x] Un proveedor activo cuya categoría coincide ve una solicitud en `open` con
      `viewer: "provider"`.
- [x] La misma solicitud en `offered` (ya tiene ofertas de otros, ninguna
      adjudicada) también se ve.
- [x] En `assigned`, `closed` o `cancelled` responde `403`, aunque la categoría
      coincida.
- [x] En `direct` responde `403`, aunque la categoría coincida y el estado sea
      uno de los que el back office difunde.
- [x] Una solicitud en `open` cuyo `field_assigned_provider` o
      `field_assigned_offer` ya está relleno —dato incoherente— responde `403`:
      se comprueban el estado **y** las dos claves.
- [x] Un proveedor de **otra** categoría responde `403`.
- [x] Un proveedor con el nodo despublicado, o con `field_license_expiry`
      vencida, responde `403` — `myapi_services_provider_is_active()` se aplica
      de verdad.
- [x] Un usuario con el rol `proveedor` pero sin ningún nodo `provider` que lo
      referencie (`field_provider_users`) responde `403`, sin reventar.
- [x] Un proveedor que **ya ofertó** ve el detalle en `assigned`, en `closed` y
      en `cancelled`, y también si la categoría de la solicitud cambió después
      de su oferta.
- [x] Un usuario autenticado sin rol `proveedor` y que no es el solicitante
      responde `403`.

**El recorte del proveedor**

- [x] El proveedor recibe `unit: null` y `viewer: "provider"`, y todas las demás
      claves con el mismo contenido que el solicitante.
- [x] El proveedor recibe `condominium` completo y `requester {id, name}`
      completo.
- [x] `offers` del proveedor contiene **solo** ofertas cuyo `field_provider` es
      uno de sus nodos `provider`: cero elementos si no ha ofertado, uno si
      ofertó.
- [x] `offers_count` del proveedor es el **total** de la solicitud, no el tamaño
      de su lista recortada: con tres ofertas de tres proveedores distintos, el
      que ofertó ve `offers_count: 3` y `offers` con un elemento.
- [x] Un proveedor que opera **dos** nodos `provider` que ofertaron los dos ve
      sus dos ofertas.

**Las ofertas**

- [x] El solicitante ve todas las ofertas publicadas, incluidas las `rejected` y
      las `withdrawn`.
- [x] Una oferta despublicada no aparece en `offers` **ni** cuenta en
      `offers_count`.
- [x] Para el solicitante, `count(offers)` es siempre igual a `offers_count`.
- [x] El orden es por fecha de creación **descendente**, y dos ofertas creadas
      en el mismo segundo salen siempre en el mismo orden entre dos lecturas
      (desempate por `nid`).
- [x] Una oferta cuyo `field_provider` apunta a un nodo despublicado o borrado
      responde `provider: null` **y sigue en la lista**.
- [x] `amount` viaja como número (`95.5`) y no como `"95.50"`; una oferta sin
      monto responde `null`, nunca `0`.
- [x] `provider.logo` es una URL absoluta directa al fichero, o `null` — no una
      ruta `api/v1/...`.
- [x] `message` conserva los saltos de línea que el proveedor escribió.
- [x] Una solicitud sin ofertas responde `offers: []` y `offers_count: 0`.
- [x] Las entradas de `service_transaction` de la solicitud no aparecen en
      `offers` ni mueven `offers_count`.

**Los ficheros**

- [x] `images[].url` y `attachment.url` apuntan a
      `GET /api/v1/service-requests/{id}/files/{fid}`, absolutas, y **no** a
      `system/files/...`.
- [x] Las imágenes salen en el orden de `delta` en que el operador las subió.
- [x] `GET /api/v1/service-requests/{id}/files/{fid}` con el token del
      solicitante devuelve `200` y los bytes, con `Content-Type` y
      `Content-Disposition: inline`.
- [x] El proveedor que puede ver el detalle puede descargar sus imágenes y su
      adjunto con el mismo `200`.
- [x] Quien no puede ver el detalle recibe **el mismo `403`** en la ruta de
      ficheros: la regla es la misma función, no una copia.
- [x] Un `fid` que existe pero pertenece a **otra** solicitud responde `404`, no
      los bytes.
- [x] Un `fid` de un comprobante de pago o de una galería de proveedor responde
      `404`.
- [x] Sin cabecera `Authorization`, la ruta de ficheros responde `401`, no los
      bytes.
- [x] En el back office, un administrador abre `node/{id}/edit` de una solicitud
      y **ve** las miniaturas; un `administrador edificio` las ve solo en las
      solicitudes de sus condominios.
- [x] Un usuario con sesión Drupal y sin rol administrativo que pega la URL
      privada de una imagen recibe `403` de Drupal.

**Errores y métodos**

- [x] Sin cabecera `Authorization`: `401 missing_authorization`. Con token
      inválido o caducado: `401 invalid_token`.
- [x] Un `{id}` que no existe, o que existe pero está despublicado, o que es de
      otro bundle (un `provider`, un `reclamo`): `404 not_found`.
- [x] `/api/v1/service-requests/abc`, `/0` y `/-3`: `404 not_found`, **sin
      ejecutar ninguna consulta**.
- [x] Una solicitud que existe y el lector no puede ver: `403 forbidden`. El
      `404` y el `403` no se confunden nunca.
- [x] `POST`, `PUT` y `DELETE` sobre las dos rutas responden
      `405 method_not_allowed`, sin token y antes de cualquier consulta.

**Rendimiento**

- [x] Un detalle leído por el solicitante ejecuta **cinco** consultas de
      contenido, con una imagen y con veinte, y con una oferta y con veinte:
      ninguna crece con el número de filas.
- [x] Ninguna función del recurso llama a `node_load()` dentro de un bucle.

**No regresión**

- [x] `GET /api/v1/service-requests` responde byte a byte igual, con y sin
      filtros — el `$uid` opcional de `base_query()` no le movió nada.
- [x] `GET /api/v1/units`, `GET /api/v1/claims`,
      `GET /api/v1/claims/{id}/files/{fid}`, `GET /api/v1/providers/{id}` y
      `GET /api/v1/providers/{id}/gallery/{fid}` responden byte a byte igual.
- [x] Los ficheros privados de claims, de galerías de proveedor y de
      comprobantes de pago se sirven exactamente como antes en el back office.
- [x] `myapi.install` no tiene ni un cambio: `drush updb` no encuentra ningún
      update pendiente.
- [x] Ningún rol gana ni pierde permisos, y `myapi_provider_role_*` queda sin
      tocar.
- [x] La suite unitaria pasa completa y `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| La ruta | **`api/v1/service-requests/%`** | `api/v1/service-request/%`, en singular, como se pidió al principio | Decisión explícita del usuario tras plantearlo. El listado ya es `api/v1/service-requests`, y dos rutas hermanas con distinta forma obligan al cliente a recordar cuál lleva la `s`. Drupal 7 resuelve primero por número de segmentos, así que la colección, el ítem y los ficheros conviven sin competir. |
| Quién puede ver el detalle | **Dos reglas independientes, y basta con una**: el solicitante, o el proveedor elegible | Solo el solicitante (y el proveedor en otro endpoint); o reutilizar `myapi_provider_role_visible_request_ids()` tal cual | Petición explícita del usuario. Un endpoint por lector duplicaría el serializador y las cinco consultas para cambiar tres claves. `visible_request_ids()` responde a la pregunta del **back office** —qué solicitudes no le oculto a este proveedor— e incluye `direct`, que aquí está excluida: reutilizarla habría atado dos políticas que deben poder divergir. |
| Qué es «abierta y sin proveedor adjudicado» | **`status ∈ ('open','offered')` y las dos claves de adjudicación vacías** | Solo `open`; o los tres estados de `myapi_provider_role_broadcast_statuses()` | Decisión explícita del usuario. Una solicitud con ofertas sigue siendo adjudicable, así que cerrarla a los proveedores nuevos en cuanto llega la primera oferta daría ventaja al que llegó antes y empobrecería la ronda. `direct` queda fuera porque nace con proveedor elegido, que es justo lo que «sin adjudicar» excluye. Comprobar además las dos claves —y no solo el estado— es la lectura segura de un dato que hoy nada mantiene coherente. |
| Proveedor activo | **Se exige** (`myapi_services_provider_is_active()`: publicado + licencia vigente) | Bastar con el rol y la categoría | Decisión explícita del usuario. El rol es una etiqueta de cuenta; la licencia es lo que dice que ese proveedor puede trabajar hoy. Sin la comprobación, suspender a un proveedor —despublicando su nodo o dejando vencer la licencia— no le quitaría el acceso a las solicitudes del mercado, que es precisamente lo que suspenderlo significa. |
| El que ya ofertó | **Conserva el acceso siempre**, sea cual sea el estado y aunque la categoría cambie | Perderlo cuando la solicitud se adjudica a otro | Decisión explícita del usuario, y la regla ya está escrita en `myapi_provider_role_request_visible()` como primera cláusula. Quien tiene una oferta viva necesita ver qué pasó con ella; quitarle el detalle en cuanto se adjudica a otro le dejaría una oferta en su app sin nada detrás, y un `403` como toda explicación. |
| `direct` y su proveedor | **No lo ve**, y queda anotado | Dejar entrar al proveedor de `field_assigned_provider` | Es la consecuencia honesta de excluir `direct` de la regla 3, y hoy no cuesta nada: ningún flujo crea solicitudes directas todavía. Añadir ahora una cuarta regla —«o eres el proveedor adjudicado»— sería legislar sobre un flujo que no existe, y el spec que cree las solicitudes directas es quien sabrá qué acceso necesita su proveedor y hasta cuándo. Anotado en Riesgos. |
| `404` frente a `403` | **`404` si no existe o está despublicada; `403` si existe y no puedes verla** | Un `404` para todo, que es lo que no filtra existencia | Decisión explícita del usuario. Es la elección coherente con el resto del módulo (`GET /api/v1/claims/{id}` responde así) y la que da un mensaje accionable: «esa solicitud ya no admite ofertas» es información útil para un proveedor, y el nid no es un secreto — el proveedor llegó a él desde un listado que se lo dio. |
| Un solo endpoint con dos respuestas | **Mismas diecisiete claves para los dos lectores**, con tres contenidos distintos | Dos endpoints; o omitir del JSON las claves que el proveedor no ve | Omitir claves obliga al cliente a comprobar la existencia de cada una antes de leerla, y a un test a describir dos formas distintas. Un `null` es una respuesta, una clave ausente es una pregunta. Las diecisiete claves siempre presentes es la misma regla que `myapi_service_request_build_item()` ya sigue en el listado. |
| La clave `viewer` | **Va en la respuesta** | Que el cliente deduzca su papel comparando `requester.id` con el uid de su token | Sin ella, `unit: null` es indistinguible de «no hay vivienda», y el cliente no puede decidir si pinta «ofertar» o «adjudicar». La deducción por `requester.id` funciona pero pone la regla de acceso del servidor dentro del cliente: el día que se añada un tercer lector —el administrador del edificio, por ejemplo— habría que tocar la app. El servidor ya sabe la respuesta y cuesta cero decirla. |
| `unit` para el proveedor | **`null`** | Servirla igual a los dos | Decisión explícita del usuario. Para decidir si oferta, el proveedor necesita categoría, descripción, fotos, fecha deseada y condominio; el número de puerta no añade nada a esa decisión y sí dice dónde vive una persona concreta a cualquiera de la categoría, haya ofertado o no. El día que oferte y se le adjudique hará falta dársela — y eso es el spec de adjudicación, no este. |
| `requester` para el proveedor | **Sí, `id` y `name`** | Ocultarlo, o servirlo con teléfono y email | Decisión explícita del usuario. El nombre no es un dato de contacto: dice con quién trataría, que es parte de decidir si oferta. El teléfono y el email sí lo son y no viajan para nadie — ni siquiera para el solicitante, que ya sabe los suyos —, así que `myapi_user_fetch_profile_fields()` no se llama desde este recurso. |
| Las ofertas para el proveedor | **Solo las suyas** | Todas, como el solicitante; o ninguna | Decisión explícita del usuario. Ver el monto y el mensaje de la competencia convierte el mercado en una subasta con información asimétrica a favor del que mira más tarde. Ocultárselas del todo tampoco sirve: necesita ver la suya para saber qué ofertó y en qué estado está. `offers_count` completo le da la señal legítima —cuánta competencia hay— sin decirle de quién ni por cuánto. |
| Orden de las ofertas | **`created DESC`, desempatado por `nid DESC`** | Ascendente, como la línea de tiempo de claims; o por monto | Decisión explícita del usuario: la más reciente arriba. El desempate por `nid` no es cosmético — dos ofertas del mismo segundo, sin él, cambian de sitio entre dos lecturas de la misma pantalla, la misma trampa que SPEC 83 y SPEC 88 documentaron. Ordenar por monto lo decide el cliente, que tiene la lista entera. |
| Paginación de las ofertas | **Ninguna: van todas** | `?page`/`?limit` sobre `offers` | Decisión explícita del usuario. Una solicitud recibe unidades de ofertas; paginarlas añadiría un bloque `pagination` anidado y la pregunta de qué significa `total` cuando el lector es un proveedor que solo ve la suya. |
| `offers_count` | **Sale de la consulta agregada de SPEC 88**, no de `count($offers)` | Contar la lista ya cargada y ahorrar una consulta | Para el proveedor la lista está recortada, así que contarla le daría siempre `0` o `1` donde la clave promete el total. Reusar `myapi_service_request_offer_counts_by_nid()` tiene además una segunda ventaja: el filtro por bundle que impide contar `service_transaction` —el error silencioso que SPEC 88 documentó— ya está escrito y probado ahí dentro, y no se reescribe. |
| Los ficheros privados | **Endpoint propio `/{id}/files/{fid}` + `hook_file_download()`**, en este mismo spec | Servir solo `id` y `filename` y dejar la descarga para otro spec; o un spec aparte solo para ficheros | Decisión explícita del usuario. `field_images` y `field_attachment` son `private://` **a nivel de campo** desde SPEC 77: sin el endpoint, el detalle sirve nombres de fichero que nadie puede abrir, y «el detalle con las imágenes» —que es lo que se pidió— no estaría entregado. El patrón ya existe dos veces (SPEC 65 en claims, SPEC 82 en galerías), así que es repetir una forma conocida, no diseñar una nueva. |
| Dónde vive la propiedad de un fichero | **`includes/myapi.service_request_files.inc`** | Dentro del recurso, junto al endpoint | Aquí sí hay dos consumidores desde el primer día —el endpoint de la app y `hook_file_download()` para el back office—, que es exactamente la condición que activa la Regla 3 de `CLAUDE.md`. Es además el sitio donde claims y proveedores ya pusieron lo mismo, así que `myapi_file_download()` queda con tres delegaciones simétricas en vez de dos y una excepción. |
| El nombre del solicitante | **Extraer `myapi_user_display_names()` a `includes/myapi.user.inc`** | Copiar la regla en el recurso; o llamar a `myapi_unit_fetch_user_names()` desde aquí | Decisión explícita del usuario. Copiarla es duplicar lógica, que la Regla 3 prohíbe. Llamar a la función de `unit.resource.inc` rompería la Regla 5 —un recurso no llama a las entrañas de otro— y ataría el detalle de servicios a un fichero que no tiene nada que ver. `includes/myapi.user.inc` ya existe y es exactamente para esto. |
| `myapi_service_request_base_query()` | **Gana un `$uid` que admite `NULL`** | Una segunda función base para el detalle; o filtrar por `nid` sobre la misma consulta con el `uid` puesto | Una segunda base es una segunda verdad sobre qué es una solicitud válida, y el día que cambie una habrá que acordarse de la otra — que es justo lo que SPEC 88 evitó al compartirla entre el conteo y la página. Filtrar con el `uid` puesto habría hecho imposible el acceso del proveedor sin escribir un `OR` que mezcla dos reglas distintas en SQL. |
| La categoría sigue con `INNER JOIN` | **Sí, heredado del listado**: un `tid` huérfano deja la solicitud fuera y responde `404` | `LEFT JOIN` solo en el detalle, con `category.name: null` | Que la misma solicitud esté en el listado y no en el detalle, o al revés, es peor que cualquiera de los dos comportamientos por separado: son dos vistas del mismo dato y tienen que coincidir. La incoherencia se sigue viendo —falta la fila, y ahora también el detalle— en vez de propagarse como un `null` que cada cliente pinta como puede, y `docs/service-request.md` ya lleva la consulta que la diagnostica. Anotado en Riesgos. |
| Etiqueta `node_access` | **Ninguna consulta la lleva** | Etiquetarlas, como haría una consulta de nodos «bien educada» de Drupal 7 | Herencia directa de SPEC 88 y de `myapi_query_node_access_alter()`, cuyo docblock dice que ninguna consulta de este módulo la lleva. El alter es una lista blanca por categoría del proveedor: un residente con rol `proveedor` dejaría de ver el detalle de su propia solicitud de una categoría que no atiende. El acceso de este endpoint lo decide `myapi_service_request_viewer()`, en un solo sitio y a la vista. |
| El chat, la línea de tiempo y las calificaciones | **Fuera** | Servir al menos `chat_opened_at`, o las `service_transaction` | Decisión explícita del usuario sobre el chat. Una clave servida hoy es un contrato que el spec del chat ya no podría cambiar sin romper la app, y ese spec tiene que decidir quién abre el hilo y cuándo se genera `field_firebase_path`. La línea de tiempo no se pidió y el detalle ya trae dos listas. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El endpoint de ficheros sirve cualquier fichero privado del sitio.** Es el riesgo más grave del spec: si `/service-requests/128/files/{fid}` no comprueba que el fid pertenece **a esa** solicitud, basta tener acceso a una cualquiera —y a `open` de tu categoría accede cualquier proveedor— para descargar comprobantes de pago, fotos de reclamos ajenos y galerías privadas por fuerza bruta sobre el `fid`. | La pertenencia se resuelve en `myapi_service_request_file_request_nid()` y se compara con el `{id}` de la ruta **antes** de tocar `file_load()`; un fid que resuelve a otra solicitud o a `NULL` responde `404`. Tres criterios de aceptación lo prueban desde fuera —fid de otra solicitud, fid de un comprobante, fid de una galería— y un test estructural falla si la comparación desaparece. Es exactamente el guard que SPEC 65 y SPEC 82 ya llevan. |
| **La regla de acceso se duplica** entre el detalle y el endpoint de ficheros. El día que cambie una condición —un estado más, la licencia— se cambia en un sitio y no en el otro, y el resultado es un `403` que igualmente deja descargar las fotos, o al revés. | `myapi_service_request_viewer()` es **una** función y los dos endpoints la llaman; ninguno reimplementa ni una condición. Un test pide el detalle y un fichero con el mismo token en los seis escenarios de acceso y exige que las dos rutas coincidan siempre. |
| **`myapi_provider_role_broadcast_statuses()` y la regla 3 divergen sin que nadie lo note.** Las dos listas de estados se parecen tanto —`open`, `offered` aquí; `open`, `direct`, `offered` allí— que quien lea una sola dará por hecho que son la misma y «arreglará» la diferencia. | Está escrito como decisión y comentado en el propio código: son dos políticas sobre el mismo dato —qué le oculto en el back office, qué le dejo ofertar en la app— y tienen que poder divergir. Un test fija que `direct` responde `403` en este endpoint, de modo que igualar las dos listas rompe la suite. |
| **El proveedor de una solicitud `direct` no puede ver su detalle.** Hoy es inocuo porque nada crea solicitudes directas; el día que exista el flujo, el proveedor elegido recibirá un `403` sobre el trabajo que le acaban de adjudicar. | Consecuencia conocida y anotada como precondición del spec que cree las solicitudes directas, que es quien decidirá si añade una cuarta regla —«o eres el proveedor adjudicado»— y hasta cuándo dura. Escrito en `docs/service-request.md` para que no se descubra en producción. |
| **`field_assigned_provider` es un dato denormalizado que nadie mantiene** (la propia instancia dice «se rellena a partir de la oferta adjudicada, no editar a mano», y ningún flujo lo rellena). La regla 3 lo lee para decidir acceso, así que un valor puesto a mano cierra el acceso de todos los proveedores a una solicitud que en realidad sigue abierta. | Es el fallo en la dirección segura: cierra de más, no de menos, y se ve enseguida —«ningún proveedor recibe esta solicitud»— en vez de filtrar datos en silencio. La coherencia entre las dos claves de adjudicación y el estado la debe garantizar el flujo de adjudicación, que aún no existe; es la misma deuda que SPEC 87 y SPEC 88 ya dejaron anotada. |
| **La solicitud desaparece por un `tid` huérfano**, y ahora con un `404` en vez de solo faltar en el listado. El residente pide un detalle que existe y recibe «no existe». | Solo ocurre si un operador borra un término del vocabulario `service_category` con solicitudes colgando de él. `docs/service-request.md` lleva la consulta de diagnóstico (filas de `field_data_field_category` cuyo `tid` no está en `taxonomy_term_data`) y la solución es de datos —reasignar la categoría—, no de código. Anotado además para el spec que permita **borrar** categorías, que es quien debe impedirlo de raíz. |
| **Tocar `myapi_service_request_base_query()` rompe el listado.** El `$uid` opcional es un cambio en la función más compartida del recurso, y un `NULL` mal tratado convertiría el listado en «todas las solicitudes de todos». | El parámetro se implementa como «`NULL` = no añadir la condición», nunca como «`NULL` = comparar con `NULL`», y el `INNER JOIN` a `field_data_field_requester` se mantiene siempre para que la fila siga necesitando un solicitante. La no regresión del listado es criterio de aceptación —byte a byte, con y sin filtros— y su suite entera se ejecuta sin cambiar ni un test. |
| **`hook_file_download()` se vuelve más caro para todo el sitio.** El hook dispara con **cada** fichero privado; ahora hay una tercera resolución encadenada, y las de claims y proveedores ya hacen consultas. | El coste de un URI ajeno sigue siendo una consulta por include: `..._fid_by_uri()` falla y se acaba. Las tres delegaciones se ordenan de más frecuente a menos y cada una devuelve `NULL` en cuanto no reconoce el fichero, que es lo que mantiene intacto el comportamiento de comprobantes de pago y derivados de estilo de imagen. Criterio de aceptación propio. |
| **Un tercer campo de fichero añadido en el futuro nace inalcanzable.** Si un spec posterior mete otro campo de fichero en `service_request` y no lo añade a la lista de `..._request_nid()`, sus ficheros no se sirven ni en la app ni en el back office, y sin ningún error que lo explique. | Regla de mantenimiento escrita en el `@file` del include y repetida en `docs/service-request.md`, calcada de la que SPEC 65 dejó en `myapi.claims_files.inc`: crear el campo con `uri_scheme => private` **y** añadirlo a la lista. |
| **La descripción y el mensaje de la oferta viajan en crudo.** Si alguien cambia el formato de texto de esas instancias de `plain_text` a uno que permita HTML, la respuesta empieza a servir marcado sin escapar. | El mismo riesgo que ya corren `GET /api/v1/claims` y `GET /api/v1/service-requests` con `field_description`, y por eso la respuesta es la misma en los tres sitios: tratar aquí el mismo dato de otra forma habría hecho que viajara de dos maneras. `docs/service-request.md` deja escrito que el contrato depende del formato `plain_text` de las instancias. |
| **El endpoint responde `403` a casi todo el mundo el día del despliegue**, porque nada crea ofertas ni solicitudes por API todavía y los datos se cargan a mano. Se leerá como un fallo de la regla de acceso cuando es el estado real del sistema. | Escrito en la cabecera, en el alcance y en `docs/service-request.md`. La verificación de aceptación se hace sobre solicitudes, ofertas y proveedores cargados desde el back office, que es exactamente cómo se probaron SPEC 86, 87 y 88. |
