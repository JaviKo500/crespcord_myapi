# SPEC 65 — Imágenes y adjuntos de reclamos en `private://` con descarga autenticada

> **Estado:** Approved · **Depende de:** SPEC 55 (`field_images` y `field_attachment`, creados sin `uri_scheme`), SPEC 56 (`myapi_claims_admin_roles()` y el modo `via_claim` que acota a `administrador edificio`), SPEC 64 (`myapi_claim_base_query()`, la regla de visibilidad y el contrato de `docs/claim.md`) · **Fecha:** 2026-08-03
> **Objetivo:** Cerrar el acceso público a las imágenes y adjuntos de reclamos migrándolos a `private://`, y devolverles una vía de lectura a cada consumidor — `hook_file_download()` para el operador del back-office y `GET /api/v1/claims/%/files/%` con Bearer para la app.

Notas técnicas que fija la cabecera, porque condicionan el resto del documento:

- **Prerrequisito de entorno, no de código:** `file_private_path` debe estar configurado en `settings.php` (o en `admin/config/media/file-system`) **antes** de ejecutar `drush updb`. Sin él, `private://` no resuelve y la migración no puede correr.
- La migración **cierra el acceso futuro, no rebobina el pasado**: una URL `public://` ya descargada, cacheada por un proxy o compartida por chat sigue siendo una copia del fichero fuera de control. Lo que deja de funcionar es la URL, no las copias que ya salieron.

---

## Alcance

**Dentro:**

- **`myapi.install`** (modificar):
  - Las dos llamadas a `_myapi_reservations_ensure_field()` de `field_images` y `field_attachment` (líneas 1349-1358) pasan a llevar `'settings' => ['uri_scheme' => 'private']`, para que una instalación limpia nazca ya privada.
  - **`myapi_update_7023()`** (nuevo) — pone `uri_scheme = 'private'` en los dos campos vía `field_update_field()` y migra con `file_move()` los ficheros ya subidos que sigan en `public://`.
- **`includes/myapi.claims_files.inc`** (nuevo) — la lógica compartida por los dos consumidores: resolver el reclamo dueño de un fid (directo por `field_images`/`field_attachment` del propio reclamo, o vía la transacción que lo lleva), y la decisión de acceso del back-office. Un include nuevo y no `myapi.claims_common.inc`, cuyo `@file` declara que es neutro sin base de datos.
- **`myapi.module`** (modificar):
  - **`myapi_file_download($uri)`** — implementación de `hook_file_download()`. Stub fino: `module_load_include()` y delega en el include nuevo. Resuelve `uri → fid → reclamo dueño`, y concede solo a los roles de `myapi_claims_admin_roles()`, acotando `administrador edificio` a sus condominios. Devuelve las cabeceras del fichero cuando concede, `-1` cuando el fichero es de un reclamo que ese usuario no puede ver, y `NULL` para cualquier URI que no reconozca.
  - Ruta nueva en `hook_menu()`: `api/v1/claims/%/files/%`, `page arguments [3, 5]`, callback `myapi_claim_file_dispatch`.
- **`resources/claim.resource.inc`** (modificar):
  - `myapi_claim_build_file()` y `myapi_claim_load_images()` dejan de llamar a `file_create_url()`: `url` pasa a ser la URL absoluta del endpoint nuevo, construida con `url('api/v1/claims/' . $claim_nid . '/files/' . $fid, ['absolute' => TRUE])`. Las dos funciones necesitan por tanto saber el **nid del reclamo**, que en el caso de las transacciones no es el nid de la fila.
  - **`myapi_claim_file_dispatch($id, $fid)`** (nuevo) — `GET` → descarga; cualquier otro método → `405`.
  - **`myapi_claim_file_download($id, $fid)`** (nuevo) — token Bearer, visibilidad del reclamo **reutilizando `myapi_claim_base_query()`**, pertenencia del fid vía el include nuevo, y streaming del fichero.
- **`includes/myapi.i18n.inc`** (modificar) — una clave nueva, `file_not_found`, en `es` y `en`.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.claims_files.inc`.
- **`docs/claim.md`** (modificar) — el endpoint nuevo con la plantilla de `CLAUDE.md`, la tabla del objeto de fichero actualizada, y la sección "⚠️ Image and attachment URLs are public" **reescrita**: de riesgo aceptado a riesgo cerrado, con la nota de lo ya descargado.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- **Cualquier endpoint de escritura.** No se sube, ni se borra, ni se reemplaza un fichero desde la app.
- **Estilos de imagen y derivados propios.** El endpoint sirve el fichero original, sin `?style=thumbnail` ni redimensionado. El back-office sigue viendo sus miniaturas porque `image_style_deliver()` pregunta por el fichero **origen**, que sí resuelve `hook_file_download()`.
- **Los comprobantes de pago** (`private://comprobantes_pago`, SPEC 20). No se toca `resources/payment.resource.inc` ni `docs/payment.md`. `hook_file_download()` **no** los reconoce y por tanto siguen sin ser descargables por nadie — exactamente el estado de hoy, sin regresión ni mejora. Darles acceso es su propio spec.
- **`field_image` del bundle `area`** (SPEC 32) y cualquier otro fichero del módulo: siguen en `public://`. Este spec migra solo los dos campos de reclamos.
- **Tests unitarios nuevos.** La pertenencia del fid es una query, no un helper puro; extraer un helper artificial solo para testearlo no compra nada. Se verifica con la matriz manual, mismo criterio que SPEC 56 con `myapi_building_admin_alter_node_query()`.
- **Token por query string** (`?access_token=`). El endpoint lo exige en la cabecera `Authorization`, como todos los demás.
- **`Range`, `ETag` y descargas parciales.** El endpoint sirve el fichero entero.
- **Recuperar las copias ya descargadas.** Fuera del alcance de cualquier spec: la migración cierra el acceso futuro.

Dos casos límite decididos dentro de este alcance:

1. **Un fid que existe pero pertenece a otro reclamo** → `404 file_not_found`, no `403`. El usuario ya demostró que ve el reclamo de la ruta, así que no se filtra nada, pero tampoco se le confirma que ese fid exista en otro sitio.
2. **Un fichero cuya fila de `file_managed` apunta a un fichero físico ausente** → `404 file_not_found`, no un error de PHP ni una respuesta de 0 bytes.

---

## Modelo de datos

**No hay tablas ni campos nuevos.** Lo que cambia es el `uri_scheme` de dos campos ya existentes, la `uri` de los ficheros ya subidos, el valor de una clave del JSON, y una clave del catálogo i18n.

### Ajuste de los dos campos

```php
// Antes (SPEC 55) — sin 'settings', y el default de Drupal 7 es 'public'.
_myapi_reservations_ensure_field('field_images', [
  'field_name'  => 'field_images',
  'type'        => 'image',
  'cardinality' => FIELD_CARDINALITY_UNLIMITED,
]);

// Después
_myapi_reservations_ensure_field('field_images', [
  'field_name'  => 'field_images',
  'type'        => 'image',
  'cardinality' => FIELD_CARDINALITY_UNLIMITED,
  'settings'    => ['uri_scheme' => 'private'],
]);
```

Idéntico para `field_attachment` (`type => 'file'`, `cardinality => 1`).

`_myapi_reservations_ensure_field()` solo crea el campo si no existe, así que en un sitio ya instalado esta línea no hace nada: ahí el cambio lo aplica el update.

### Qué toca y qué no toca la migración

| Tabla / recurso | Cambia | Por qué |
|---|---|---|
| `field_config.data` (`uri_scheme`) | **Sí**, vía `field_update_field()` | Es donde vive el esquema del campo. |
| `file_managed.uri` | **Sí**, lo reescribe `file_move()` | `public://foo.jpg` → `private://foo.jpg`. |
| El fichero físico | **Sí**, lo mueve `file_move()` | De `sites/default/files/` al directorio privado. |
| `field_data_field_images` / `field_data_field_attachment` | **No** | Guardan el `fid`, nunca la URI. Ninguna fila se reescribe. |
| `field_revision_field_*` | **No** | Mismo motivo. |

Esa es la razón por la que la migración es segura: el vínculo nodo↔fichero es el fid, y el fid no se toca.

### Ámbito de la migración

El update recorre los fids que cumplen las tres condiciones:

1. Están referenciados desde `field_data_field_images` o `field_data_field_attachment` (`entity_type = 'node'`, `deleted = 0`).
2. Su `file_managed.uri` empieza por `public://`.
3. El fichero físico existe.

Un fid que ya está en `private://` se salta en silencio: es lo que hace el update re-ejecutable.

### `hook_file_download()` — contrato de retorno

`myapi_file_download($uri)` devuelve uno de tres valores, que es el contrato que Drupal 7 espera:

| Devuelve | Cuándo |
|---|---|
| `array` de cabeceras (`Content-Type`, `Content-Length`, `Content-Disposition`) | El `uri` pertenece a un `field_images`/`field_attachment` de un reclamo **y** el usuario tiene uno de los roles de `myapi_claims_admin_roles()` (con `administrador edificio` acotado a sus condominios). |
| `-1` | El `uri` pertenece a un fichero de reclamos y el usuario **no** puede verlo. Deniega en firme. |
| `NULL` | El `uri` no lo reconoce este módulo (comprobantes de pago, derivados de estilos de imagen, ficheros de otros módulos). Decide otro. |

El `NULL` es deliberado y es lo que hace que el resto del sitio no cambie de comportamiento.

### Resolución del reclamo dueño de un fichero

Un fid puede colgar de cuatro sitios, y los cuatro terminan en un `reclamo`:

```
field_images     de un 'reclamo'            → ese nid
field_attachment de un 'reclamo'            → ese nid
field_images     de un 'claim_transaction'  → field_claim → nid del reclamo
field_attachment de un 'claim_transaction'  → field_claim → nid del reclamo
```

La función que resuelve esto vive en `includes/myapi.claims_files.inc` y devuelve el **nid del reclamo** o `NULL`. La usan los dos consumidores: el hook (para decidir el acceso del operador) y el endpoint (para comprobar que el fid pedido es de *ese* reclamo).

### Cambio de contrato: `url`

```json
// Antes (SPEC 64) — abre sin token
{ "id": 512, "url": "https://mi-sitio/sites/default/files/mancha.jpg", "filename": "mancha.jpg" }

// Después — exige Authorization: Bearer <token>
{ "id": 512, "url": "https://mi-sitio/api/v1/claims/140/files/512", "filename": "mancha.jpg" }
```

- Las tres claves y sus tipos **no cambian**: `id` int, `url` string, `filename` string.
- El `140` es siempre el nid del **reclamo**, también para los ficheros de una transacción. La app no necesita saber de qué transacción cuelga un fichero para pedirlo.
- `filename` sigue siendo el nombre original, que es lo que la app muestra.

### El endpoint

```
GET /api/v1/claims/{claim_nid}/files/{fid}
Authorization: Bearer <access_token>
```

Respuesta correcta: **los bytes del fichero**, no el envelope JSON. Es la única excepción a la Regla 4 de `CLAUDE.md` en todo el módulo, y está justificada abajo en Decisiones.

| Cabecera | Valor |
|---|---|
| `Content-Type` | `file_managed.filemime` |
| `Content-Length` | `file_managed.filesize` |
| `Content-Disposition` | `inline; filename="<filename>"` |
| `Cache-Control` | `private, no-store` |

Los errores **sí** viajan en el envelope de siempre:

| Código | `error_code` | Cuándo |
|---|---|---|
| `401` | `missing_authorization` / `invalid_token` | Sin cabecera, o token inválido o expirado. |
| `404` | `claim_not_found` | El reclamo no existe, es de otro condominio, o es privado ajeno. Misma regla y mismo mensaje que `GET /api/v1/claims/%`. |
| `404` | `file_not_found` | El reclamo es visible, pero el fid no existe, no pertenece a ese reclamo ni a sus transacciones, o su fichero físico no está. |
| `405` | `method_not_allowed` | Cualquier método distinto de `GET`. |

El orden de comprobación importa y es siempre el mismo: **token → reclamo → fichero**. Un fid ajeno bajo un reclamo que el usuario no ve devuelve `claim_not_found`, no `file_not_found`: la primera pregunta que falla es la que responde.

### Clave i18n nueva

| Clave | `es` | `en` |
|---|---|---|
| `file_not_found` | `Archivo no encontrado.` | `File not found.` |

---

## Plan de implementación

El orden está pensado para que **la migración vaya al final**, cuando las dos vías de lectura ya están vivas y verificadas. Migrar primero dejaría al operador con miniaturas rotas y a la app con imágenes en blanco durante los pasos intermedios.

1. **`includes/myapi.i18n.inc` — clave `file_not_found`.** Una línea en el bloque `es` y otra en el `en`, junto a `claim_not_found`. *Verificación: `myapi_t('file_not_found')` devuelve el texto en ambos idiomas.*

2. **`includes/myapi.claims_files.inc` (nuevo) — resolución de propiedad.** El docblock `@file` (por qué existe: dos consumidores, y por qué no va en `myapi.claims_common.inc`) y dos funciones: `myapi_claims_file_claim_nid($fid)`, que devuelve el nid del reclamo dueño de un fid recorriendo los cuatro orígenes de la sección de modelo de datos, o `NULL`; y `myapi_claims_file_fid_by_uri($uri)`, que resuelve `file_managed.uri` → `fid` para el hook. Registrarlo en `myapi.info` con `files[] = includes/myapi.claims_files.inc`. *Verificación: `php -l`; `drush cc all`; consultada a mano con un fid de reclamo, uno de transacción y uno de comprobante de pago, devuelve nid, nid y `NULL`.*

3. **`includes/myapi.claims_files.inc` — decisión de acceso del back-office.** `myapi_claims_file_access($claim_nid, $account)`: `uid 1` y los roles `administrator`/`backend` siempre dentro; `administrador edificio` solo si el condominio del reclamo está entre los suyos, reutilizando `myapi_building_admin_condominium_map()` / el helper de condominios asignados de SPEC 49 — sin reescribir la regla. Un usuario sin ninguno de los tres roles, fuera. *Verificación: `php -l`.*

4. **`myapi.module` — `hook_file_download()`.** `myapi_file_download($uri)`: `module_load_include()` del include del paso 2 y delegación. `uri` no reconocida → `NULL` sin tocar la base de datos más de una vez; reconocida y permitida → array de cabeceras desde `file_managed`; reconocida y denegada → `-1`. *Verificación: `php -l`; `drush cc all`; con los ficheros todavía en `public://` nada cambia visiblemente, que es lo esperado — el hook queda vivo y inerte.*

5. **`resources/claim.resource.inc` — el endpoint de descarga.** `myapi_claim_file_download($id, $fid)`: `myapi_auth_require_access_token()` → `$uid`; `myapi_condominium_related_nids($uid)`; `myapi_claim_base_query()` con `->condition('n.nid', $id)` y sin paginación → cero filas devuelve `myapi_error('claim_not_found', 404)`; `myapi_claims_file_claim_nid($fid)` distinto de `$id` devuelve `myapi_error('file_not_found', 404)`; fichero físico ausente, lo mismo; y en el caso feliz, `file_transfer($uri, $headers)` con las cuatro cabeceras de la tabla. Y `myapi_claim_file_dispatch($id, $fid)`: `GET` → lo anterior, cualquier otro método → `myapi_error('method_not_allowed', 405)`. *Verificación: `php -l`. La regla de visibilidad no se reescribe: es literalmente la misma llamada que usa `myapi_claim_detail()`.*

6. **`myapi.module` — la ruta.** `api/v1/claims/%/files/%` en `hook_menu()`, `page arguments [3, 5]`, `page callback myapi_claim_file_dispatch`, `access callback TRUE`, `file resources/claim.resource.inc`, `type MENU_CALLBACK`. *Verificación: tras `drush cc all`, `curl` con Bearer válido sobre un fid propio descarga el fichero — **todavía desde `public://`**, que es la prueba de que el endpoint funciona con independencia de la migración. Sin token, `401`.*

7. **`resources/claim.resource.inc` — `url` apunta al endpoint.** `myapi_claim_build_file()` gana un parámetro `$claim_nid` y cambia `file_create_url($uri)` por `url('api/v1/claims/' . $claim_nid . '/files/' . $fid, ['absolute' => TRUE])`. `myapi_claim_load_images()` gana un `array $owner_map = []` que traduce el nid de la entidad al nid del reclamo: vacío para los reclamos (cada nid es su propio dueño), relleno por `myapi_claim_load_transactions()` cuando expande, donde el dueño es el reclamo y no la transacción. Actualizar los dos docblocks que hoy dicen que las URLs son públicas. *Verificación: `php -l`; `GET /api/v1/claims/%?include=transactions` devuelve en todas las `url` el nid del **reclamo**, nunca el de la transacción; esas URLs descargan con token y siguen descargando sin él, porque los ficheros aún son públicos.*

8. **`myapi.install` — instalación limpia.** `'settings' => ['uri_scheme' => 'private']` en las dos llamadas a `_myapi_reservations_ensure_field()`. No afecta a ningún sitio existente: el helper solo crea el campo si falta. *Verificación: `php -l`; en un sitio limpio, `drush en myapi` y `field_info_field('field_images')['settings']['uri_scheme']` devuelve `private`.*

9. **`myapi.install` — `myapi_update_7023()`, la migración.** En este orden: (a) `file_prepare_directory('private://', FILE_CREATE_DIRECTORY)` y, si falla, `throw new DrupalUpdateException()` con el mensaje de que falta `file_private_path` en `settings.php` — ese es el único caso que aborta; (b) `field_update_field()` sobre los dos campos con `uri_scheme = 'private'`; (c) recorrer los fids del ámbito descrito arriba y, por cada uno, `file_move($file, 'private://' . $file->filename, FILE_EXISTS_RENAME)`, contando éxitos y fallos; (d) cada fallo individual va a `watchdog()` con su fid y su uri, se salta, y **no** aborta el update; (e) devolver el resumen (`N movidos, M fallidos, K ya privados`) como mensaje del update. Re-ejecutarlo no mueve nada dos veces: los que ya están en `private://` no entran en el ámbito. *Verificación: `drush updb` en un sitio con reclamos con imágenes; las URLs `sites/default/files/...` pasan a devolver 404; `drush updb` una segunda vez reporta `0 movidos`.*

10. **`docs/claim.md`.** El endpoint nuevo con la plantilla de `CLAUDE.md` (método, auth, cabeceras de respuesta, tabla de errores), la fila `url` de la tabla del objeto de fichero reescrita, y la sección "⚠️ Image and attachment URLs are public" sustituida por una que diga: que los ficheros están en `private://`, que la app debe mandar `Authorization` también al pedir imágenes, que el operador los ve por sesión de Drupal vía `hook_file_download()`, que `file_private_path` es un prerrequisito de entorno, y que **la migración cierra el acceso futuro pero no recupera lo ya descargado**. *Verificación: lectura contra la implementación.*

11. **`drush updb && drush cc all && drush image-flush --all` + matriz manual.** El flush de derivados es obligatorio, no opcional: sin él quedan miniaturas públicas en `sites/default/files/styles/` (ver Riesgos). Recorrer los criterios de aceptación con tres usuarios: un residente con reclamo propio, un `administrador edificio` con un solo condominio asignado, y un `administrator`. Incluir una pasada por `admin/content/claims`, por `node/%/edit` de un reclamo con imágenes y por la línea de tiempo de transacciones, para confirmar que las miniaturas se ven.

**Nota de orden:** entre los pasos 7 y 9 el sistema está en un estado deliberadamente redundante — las URLs nuevas funcionan y las viejas también, porque los ficheros todavía son públicos. Ese solape es lo que permite verificar el endpoint **antes** de que la migración lo convierta en la única vía. El paso 9 es el que cierra la puerta, y es el único irreversible.

---

## Criterios de aceptación

**Migración e instalación**

- [ ] Con `file_private_path` **sin** configurar, `drush updb` falla con un mensaje que nombra `file_private_path` y `settings.php`, y **no** cambia el `uri_scheme` de ningún campo.
- [ ] Con `file_private_path` configurado, `drush updb` deja `field_info_field('field_images')['settings']['uri_scheme']` y el de `field_attachment` en `private`.
- [ ] Tras el update, ningún fichero de `field_images` ni de `field_attachment` referenciado desde un nodo conserva una `file_managed.uri` que empiece por `public://`.
- [ ] La URL pública anterior de una imagen migrada (`sites/default/files/...`) deja de servir el fichero.
- [ ] Tras `drush updb` y `drush image-flush --all`, la URL de un derivado ya generado (`sites/default/files/styles/...`) deja de servir la imagen.
- [ ] Ninguna fila de `field_data_field_images`, `field_data_field_attachment` ni de sus tablas `field_revision_*` cambia: el `fid` de cada nodo es el mismo antes y después.
- [ ] Un reclamo con tres imágenes sigue mostrando las tres tras la migración, en el mismo orden de `delta`.
- [ ] Un fichero cuyo `file_move()` falla no aborta el update: queda registrado en `watchdog` y el resto se migra igual.
- [ ] Ejecutar `myapi_update_7023` una segunda vez reporta `0 movidos` y no altera ningún fichero.
- [ ] En un sitio limpio, `drush en myapi` crea los dos campos ya con `uri_scheme = 'private'`, sin necesidad de ejecutar el update.
- [ ] Un fichero subido **después** de la migración desde `node/add/reclamo` aterriza en `private://`, no en `public://`.

**Back-office (`hook_file_download()`)**

- [ ] Un `administrator` ve las miniaturas de `field_images` en `node/%/edit` de un reclamo y descarga su `field_attachment`.
- [ ] Las imágenes de las transacciones se ven en la línea de tiempo del reclamo (SPEC 57).
- [ ] Un `administrador edificio` ve los ficheros de un reclamo de un condominio asignado.
- [ ] Un `administrador edificio` que pega la URL de un fichero de un reclamo de **otro** condominio recibe 403.
- [ ] Un usuario autenticado sin ninguno de los tres roles recibe 403 sobre cualquier fichero de reclamos.
- [ ] Un anónimo recibe 403.
- [ ] Un comprobante de pago (`private://comprobantes_pago`) sigue devolviendo 403 para todos, igual que antes de este spec: el hook devuelve `NULL` y nadie más concede.
- [ ] Ningún otro fichero privado del sitio cambia de comportamiento.

**Endpoint — autenticación y método**

- [ ] `GET /api/v1/claims/%/files/%` sin cabecera `Authorization` → `401 missing_authorization`.
- [ ] Con token inválido o expirado → `401 invalid_token`.
- [ ] `POST`, `PUT` o `DELETE` sobre esa ruta → `405 method_not_allowed`.

**Endpoint — acceso y pertenencia**

- [ ] Un fid de un reclamo **visible** para el usuario devuelve `200` con los bytes del fichero.
- [ ] Un fid de una **transacción** de ese reclamo también devuelve `200`, pidiéndolo con el nid del **reclamo** en la ruta.
- [ ] El fid de un reclamo de otro condominio, con **su** nid en la ruta, devuelve `404 claim_not_found`.
- [ ] El fid de un reclamo privado ajeno, con su nid en la ruta, devuelve `404 claim_not_found`.
- [ ] Un fid **de otro reclamo**, pedido bajo el nid de un reclamo que el usuario **sí** ve, devuelve `404 file_not_found` — no devuelve el fichero.
- [ ] Un fid que no existe, o que existe pero no cuelga de ningún reclamo (un comprobante de pago), devuelve `404 file_not_found`.
- [ ] Un fid cuyo fichero físico no está en disco devuelve `404 file_not_found`, no un 200 de 0 bytes ni un error de PHP.
- [ ] `GET /api/v1/claims/abc/files/xyz` (ids no numéricos) devuelve `404`, no un error de PHP.
- [ ] El orden de comprobación es token → reclamo → fichero: un fid ajeno bajo un reclamo invisible devuelve `claim_not_found`, no `file_not_found`.

**Endpoint — respuesta**

- [ ] La respuesta correcta son los **bytes del fichero**, no el envelope JSON.
- [ ] `Content-Type` casa con `file_managed.filemime` y `Content-Length` con `filesize`.
- [ ] `Content-Disposition` es `inline` y lleva el nombre de fichero original.
- [ ] `Cache-Control` es `private, no-store`.
- [ ] Los errores **sí** viajan en el envelope `{ "success": false, "error_code": ..., "error": ... }`.
- [ ] `error_code: file_not_found` responde en español y en inglés según `Accept-Language`.

**Contrato del JSON de reclamos**

- [ ] Cada entrada de `images` y el objeto `attachment` siguen teniendo exactamente `id`, `url` y `filename`, con los mismos tipos que en SPEC 64.
- [ ] `url` es ahora una URL absoluta de `/api/v1/claims/{nid}/files/{fid}`; ninguna respuesta contiene ya una URL de `sites/default/files`.
- [ ] En las transacciones expandidas, el `{nid}` de la `url` es el del **reclamo**, no el de la transacción.
- [ ] Pedir esa `url` sin cabecera `Authorization` devuelve `401`, no el fichero.
- [ ] `GET /api/v1/claims` y `GET /api/v1/claims/%` no cambian ninguna otra clave, tipo ni código de estado respecto a SPEC 64.

**No regresión e infra**

- [ ] `resources/payment.resource.inc` y `docs/payment.md` no aparecen en el diff.
- [ ] Ningún otro `resources/*.resource.inc` aparece en el diff.
- [ ] `includes/myapi.claims_common.inc` no aparece en el diff: sigue sin base de datos.
- [ ] `includes/myapi.claim_query.inc` y `includes/myapi.claims_admin.inc` no aparecen en el diff.
- [ ] La regla de visibilidad de reclamos existe **una sola vez** en el repo: `grep -rn "field_visibility"` no encuentra ninguna condición nueva fuera de `myapi_claim_base_query()`.
- [ ] `myapi_update_7022` y anteriores quedan intactos; el único update nuevo es `myapi_update_7023`.
- [ ] `myapi.info` lista `includes/myapi.claims_files.inc`.
- [ ] La única clave i18n añadida es `file_not_found`, presente en `es` y en `en`.
- [ ] La suite de tests sigue en verde, sin tests nuevos ni modificados.
- [ ] `drush updb` y `drush cc all` no reportan errores.

**Documentación**

- [ ] `docs/claim.md` documenta el endpoint nuevo con la plantilla de `CLAUDE.md`.
- [ ] La sección "⚠️ Image and attachment URLs are public" ya no existe: en su lugar hay una que describe el acceso cerrado.
- [ ] `docs/claim.md` dice explícitamente que `file_private_path` es un prerrequisito de entorno.
- [ ] `docs/claim.md` dice explícitamente que la migración cierra el acceso futuro pero no recupera los ficheros ya descargados.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Qué viaja en `url` | La URL absoluta del endpoint nuevo, manteniendo la clave y su tipo | Eliminar `url` y dejar `id` + `filename`, como `payment.md` | El argumento de "así no hay que tocar la app" no se sostiene: en cuanto los ficheros pasan a `private://`, las URLs actuales dejan de abrir y la app se rompe en las dos opciones. Manteniendo `url`, el cambio del cliente es añadir la cabecera al cargador de imágenes; eliminándola, además tiene que componer la ruta a mano y la duplicamos en los dos lados. El patrón de `payment.md` no es un modelo: es el estado a medias que este spec cierra. |
| Formato de la respuesta correcta | Streaming del fichero con `file_transfer()` | (a) `302` a la URL del fichero; (b) JSON con el contenido en base64 | (a) es imposible: el destino está en `private://` y no es servible sin pasar por PHP. (b) infla un 33% y rompe cualquier cargador de imágenes estándar. |
| Envelope en el caso feliz | Se rompe la Regla 4 de `CLAUDE.md` a propósito: el `200` son bytes, no JSON | Envolver siempre, sin excepciones | Un fichero binario no tiene envelope posible. La excepción es una sola, está acotada al `200` de un único endpoint, y los errores siguen usando `myapi_error()` sin cambios. Queda escrita aquí y en `docs/claim.md` para que no se lea como un descuido. |
| Fid que no pertenece al reclamo de la ruta | `404 file_not_found`, clave i18n nueva | Colapsarlo en `claim_not_found`, o devolver `403` | El usuario ya demostró que ve ese reclamo, así que distinguirlo no filtra nada, y en soporte separa "el reclamo no es tuyo" de "ese fichero no es de ese reclamo". Un `403` sí filtraría: confirmaría que el fid existe en otro sitio. |
| Orden de comprobación | Token → reclamo → fichero, siempre | Comprobar primero la pertenencia del fid, que es una query más barata | Responder por el fichero antes que por el reclamo dejaría que alguien sondee fids con un nid de reclamo que no puede ver. La pregunta de acceso se responde antes que la de existencia. |
| Regla de visibilidad del endpoint | Llamar a `myapi_claim_base_query()`, la misma de SPEC 64 | Escribir la condición de visibilidad en el endpoint, que solo necesita saber sí/no | Regla 3 de `CLAUDE.md`. Además, dos copias de la regla se desincronizan en el primer spec que la toque, y esta decide quién ve fotos de reclamos privados. |
| Nid en la ruta para los ficheros de una transacción | El del **reclamo** | `GET /api/v1/claim-transactions/%/files/%`, o el nid de la transacción en la misma ruta | El reclamo es la unidad de acceso: la regla de visibilidad se evalúa sobre él, no sobre la transacción. Con el nid del reclamo, el endpoint hace una comprobación de acceso y una de pertenencia; con el de la transacción haría además el salto a su reclamo para acabar en el mismo sitio. La app tampoco necesita saber de qué transacción cuelga cada imagen. |
| Callback del endpoint | `myapi_claim_file_dispatch($id, $fid)`, propio | Un tercer caso dentro de `myapi_claim_dispatch()` | El dispatcher actual enruta por presencia de `$id`; con dos argumentos opcionales, "listado, detalle o fichero" se vuelve una cadena de `isset()` difícil de leer. Dos rutas, dos dispatchers, cada uno con una responsabilidad. |
| Acceso del back-office | Los tres roles de `myapi_claims_admin_roles()`, con `administrador edificio` acotado a sus condominios | Conceder por rol sin acotar el condominio | Sin el acotado se cambiaría una fuga por otra más pequeña: un `administrador edificio` vería, pegando URLs, las fotos de los reclamos de edificios que no administra. Es exactamente la regla que SPEC 56 ya aplica en `admin/content/claims` y en `node/%/edit`; aquí se reutiliza, no se reinventa. |
| Retorno del hook para URIs desconocidas | `NULL` | `-1` (denegar todo lo que no reconozcamos) | `-1` es una denegación en firme que corta a los demás módulos. Devolviendo `NULL` este módulo opina solo sobre sus ficheros y el resto del sitio se comporta igual que antes. |
| Miniaturas del back-office | Confiar en que `image_style_deliver()` pregunta por el fichero **origen**, que sí resolvemos | Reconocer también las URIs `private://styles/...` y mapearlas de vuelta al origen | El módulo `image` de Drupal 7 ya consulta el acceso del origen antes de generar o servir un derivado. Añadir el mapeo inverso sería código para un caso que el core ya cubre. Si la verificación manual demuestra lo contrario, es un bug de este spec y se arregla dentro de él. |
| Ubicación de la lógica compartida | `includes/myapi.claims_files.inc`, nuevo | Añadirla a `includes/myapi.claims_common.inc` | El `@file` de `claims_common` declara que es deliberadamente neutro — sin Field API, sin base de datos — y que eso es lo que lo hace testeable desde `tests/unit`. La resolución de propiedad de un fid es una query. Meterla ahí rompería la propiedad que justifica la existencia de ese archivo. |
| Dónde vive `hook_file_download()` | El hook en `myapi.module`, como stub que delega en el include | Toda la lógica dentro de `myapi.module` | Drupal exige que el hook esté en el `.module`, pero `CLAUDE.md` prohíbe la lógica de negocio ahí. Un stub de tres líneas cumple las dos cosas, y deja la lógica donde el endpoint también puede llamarla. |
| Mover vs copiar los ficheros | `file_move()` | `file_copy()`, dejando el original en `public://` por seguridad | Copiar dejaría intacta exactamente la URL pública que este spec existe para cerrar. Si algo va mal, el fichero está en `private://` y se recupera de ahí; el backup es responsabilidad del entorno, no de dejar una copia expuesta. |
| Colisión de nombres al mover | `FILE_EXISTS_RENAME` | `FILE_EXISTS_REPLACE` | `REPLACE` podría machacar un fichero privado ya existente con el mismo nombre — incluido un comprobante de pago si algún día comparten directorio. `RENAME` no pierde nada y `file_move()` actualiza la `uri` en `file_managed` sola. |
| Directorio de destino | La raíz de `private://` | Un subdirectorio `private://reclamos`, como `private://comprobantes_pago` | Las instancias de los dos campos no declaran `file_directory`, así que las subidas **nuevas** van a la raíz del esquema. Migrar a un subdirectorio dejaría los ficheros viejos en un sitio y los nuevos en otro. Unificarlos exigiría cambiar también las instancias: más superficie, ninguna ganancia funcional. |
| Qué aborta el update | Solo que `private://` no esté disponible | (a) Abortar al primer `file_move()` fallido; (b) no abortar nunca | Sin directorio privado no hay migración posible y seguir sería dejar los campos declarados como privados con los ficheros públicos: el peor estado. Un fichero suelto que falla, en cambio, no debería bloquear los otros doscientos; queda en `watchdog` con su fid para repararlo a mano. |
| Re-ejecutabilidad del update | El ámbito es "los que siguen en `public://`", así que la segunda pasada no mueve nada | Marcar los migrados en una tabla de control | El estado ya está en `file_managed.uri`. Una tabla de control sería un segundo sitio donde guardar lo mismo, y desincronizable. |
| Instalación limpia | Se cambia también `_myapi_reservations_ensure_field()` | Dejar el instalador como está y confiar en el update | Un sitio nuevo no ejecuta los `hook_update_N()` anteriores: nacería con los campos públicos y el mismo agujero que este spec cierra, hasta que alguien recordara correr el update a mano. |
| Tests | Ninguno nuevo | Extraer un helper puro para poder testear la pertenencia del fid | Todo lo que este spec añade toca base de datos, sesión o disco. El helper puro tendría que ser artificial para ser testeable, y el test probaría la envoltura, no la regla. Mismo criterio que SPEC 56 con `myapi_building_admin_alter_node_query()`. La verificación es la matriz manual del paso 11. |
| Token del endpoint | Solo en la cabecera `Authorization` | Aceptar también `?access_token=` para cargadores de imágenes limitados | El cargador de imágenes de Flutter admite cabeceras. Un token en la query string acaba en los logs del servidor, en el `Referer` y en el historial — justo el tipo de fuga que este spec cierra. |
| `Content-Disposition` | `inline` | `attachment` | La app renderiza las imágenes en pantalla; `attachment` forzaría una descarga en cualquier visor web. El nombre original viaja igual en la cabecera. |
| Comprobantes de pago | No se tocan; el hook devuelve `NULL` sobre ellos | Aprovechar el hook para abrirlos al back-office | Están fuera del alcance pedido. Vale la pena dejar escrito que **hoy no los descarga nadie**, tampoco el operador, y que este spec no lo cambia: es un pendiente conocido, no una regresión introducida aquí. |
| Momento de la migración | Último paso del plan, después de que las dos vías de lectura estén vivas | Migrar primero y luego construir los accesos | Migrar antes deja al operador con miniaturas rotas y a la app con imágenes en blanco durante los pasos intermedios. Con este orden, el paso irreversible es el último y llega con todo lo demás ya verificado. |
| Otros ficheros del módulo | `field_image` del bundle `area` (SPEC 32) sigue en `public://` | Migrar de paso todo lo que esté en `public://` | La foto de un área común es material de catálogo que el residente ve antes de reservar; no es información sensible de nadie. Migrarla añadiría un endpoint más sin cerrar ninguna fuga. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Los derivados de estilos de imagen ya generados siguen en `public://styles/`.** Si el back-office renderizó alguna miniatura mientras los ficheros eran públicos, esa copia redimensionada quedó escrita en `sites/default/files/styles/...` y **sigue sirviéndose sin control** después de migrar el original. La migración movería el original y dejaría la copia. | El paso 11 incluye `drush image-flush --all` (o *Flush all caches* → estilos de imagen) después del `drush updb`, y un criterio de aceptación lo comprueba: la URL de un derivado conocido deja de servir. Los derivados se regeneran solos, ya bajo `private://styles/`, la primera vez que el operador abre la pantalla. |
| **`file_private_path` configurado dentro del docroot.** Si el directorio privado queda bajo la raíz web y el servidor lo sirve, la migración no cierra nada: cambia la URL, no el control de acceso. | Documentado en `docs/claim.md` como prerrequisito, con la condición explícita de que el directorio debe estar **fuera** del docroot o protegido por el `.htaccess` que Drupal escribe. Se verifica pidiendo la ruta directa de un fichero migrado y comprobando que no la sirve el servidor web. |
| **La migración no rebobina lo ya descargado.** Una URL pública que ya salió por chat, quedó en la caché de un proxy o se guardó en un dispositivo sigue siendo una copia fuera de control. | Se dice explícitamente en `docs/claim.md` y en la cabecera de este spec. Lo que este spec garantiza es que **a partir de la migración** no se sirve ningún fichero de reclamos sin autenticar. Las copias ya distribuidas no las recupera ningún cambio de código. |
| **La versión de la app ya desplegada deja de ver imágenes en el momento del `drush updb`.** Las URLs que tiene cacheadas apuntan a `sites/default/files` y pasan a devolver 404, y las nuevas exigen una cabecera que su cargador de imágenes todavía no manda. | Es una migración coordinada, no un despliegue independiente: el update se ejecuta cuando la versión de la app con el cargador autenticado esté publicada, o asumiendo la ventana de imágenes rotas. Queda dicho aquí porque es una decisión de calendario, no de código. |
| **Cada imagen pasa ahora por un bootstrap completo de Drupal.** Antes las servía el servidor web directamente; ahora un detalle con 3 imágenes son 3 peticiones PHP con arranque de Drupal, token y dos queries cada una. | Es el costo inherente de un fichero con control de acceso, el mismo que paga cualquier sitio Drupal con `private://`. Las imágenes están limitadas a 3 MB por SPEC 55 y el cliente las cachea en disco. Si el volumen se vuelve un problema, la salida es una caché con URL firmada y caducidad — otro spec, no un ajuste de este. |
| **`hook_file_download()` se invoca para *todos* los ficheros privados del sitio**, incluidos los comprobantes de pago y los de cualquier módulo futuro. | El hook resuelve `uri → fid` con una sola query y devuelve `NULL` en cuanto el fid no cuelga de un reclamo. Ni deniega ni concede nada que no sea suyo. |
| **`FILE_EXISTS_RENAME` cambia el nombre del fichero** (`informe.pdf` → `informe_0.pdf`) cuando ya hay uno igual en `private://`, y ese nombre es el que viaja en `filename`. | Solo ocurre con colisión real de nombres en la raíz del esquema privado. El `fid` no cambia, el fichero es el correcto y `url` sigue funcionando: el efecto es cosmético en la etiqueta que ve el usuario. La alternativa (`REPLACE`) destruiría un fichero ajeno. |
| **Ficheros de `public://` no referenciados desde ninguna fila de campo no se migran** y siguen siendo públicos: restos de una subida abandonada o de un nodo borrado. | El ámbito de la migración es deliberado — mover ficheros que ningún nodo referencia no aporta nada y arriesga tocar ficheros de otro origen. Drupal marca esos ficheros como temporales y su limpieza es tarea de `hook_cron`, no de este spec. |
| **Un fid puede resolver a un reclamo por dos caminos** (imagen del reclamo e imagen de una transacción del mismo reclamo) si alguien reutiliza el mismo fichero. | La resolución devuelve el primer reclamo que encuentra y, en ese caso, los dos caminos llevan al mismo nid. Si el fichero estuviera compartido entre reclamos distintos — cosa que ningún formulario del módulo produce —, la comprobación de pertenencia fallaría para uno de ellos y devolvería `404 file_not_found`: falla cerrado, no abierto. |
| **Un reclamo despublicado (`status = 0`)** deja de servir sus ficheros por la API, porque `myapi_claim_base_query()` filtra por `status = 1`. | Es coherente con SPEC 64: un reclamo despublicado ya no aparece ni en el listado ni en el detalle. El operador sigue viéndolos por el back-office vía `hook_file_download()`, que no mira `status`. |
| **Si un spec futuro añade un campo de fichero a `reclamo` o a `claim_transaction`**, no quedará cubierto por el hook ni por el endpoint, y nacerá público si se crea sin `uri_scheme` — exactamente el fallo que este spec repara. | Anotado como regla de mantenimiento en el `@file` de `includes/myapi.claims_files.inc` y en `docs/claim.md`: todo campo de fichero nuevo en estos dos bundles se crea con `uri_scheme = 'private'` y se añade a la resolución de propiedad. |

---

## Lo que **NO** está en este spec

- Cualquier endpoint de escritura: subir, reemplazar o borrar un fichero desde la app.
- Estilos de imagen o derivados propios del endpoint (`?style=thumbnail`, redimensionado, miniaturas para la app).
- Los comprobantes de pago (`private://comprobantes_pago`): siguen sin ser descargables por nadie, y `hook_file_download()` no los reconoce.
- `field_image` del bundle `area` y cualquier otro fichero del módulo: se quedan en `public://`.
- Tests unitarios nuevos.
- Token por query string, `Range`, `ETag` o descargas parciales.
- Recuperar las copias que ya se descargaron mientras las URLs eran públicas.

Cada uno de ellos, si llega, va en su propio spec.
