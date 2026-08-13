# 82 — Galería privada del proveedor (`field_gallery`) y descarga autenticada

- **Estado:** Draft
- **Fecha:** 2026-08-13
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea el bundle `provider`, su `field_photo` (que **este spec elimina**) y `includes/myapi.services_common.inc` con `MYAPI_SERVICES_PROVIDER_TYPE`. Reutiliza sus sub-helpers idempotentes y su patrón de uninstall.
  - `65-claim-files-private-and-download` (Implemented) — el precedente completo y el que hace posible este spec: `uri_scheme = 'private'` a nivel de campo, `myapi_file_download()` en `myapi.module` como `hook_file_download()`, `includes/myapi.claims_files.inc` como lugar donde vive la resolución «qué nodo posee este fid», y `GET /api/v1/claims/%/files/%` como forma del endpoint de descarga. Este spec **replica** esa arquitectura para proveedores y **modifica** `myapi_file_download()` para que atienda a dos dueños en vez de uno.
  - `78-provider-role` (Implemented) — `myapi_node_access()`, que este spec **no modifica** pero del que depende: la decisión de acceso del back office delega en `node_access('view', $provider)` en lugar de estrenar un catálogo de roles propio.
  - `81-provider-rate-tags-short-description` (Draft) — no es dependencia funcional, pero **ocupa `myapi_update_7028()`**. Este spec estrena el `7029` y ambos modifican `_myapi_services_install()`, así que se implementan en orden.

**Objetivo:** Dar al content type `provider` una galería de hasta diez imágenes en `private://` (**`field_gallery`**), eliminar el `field_photo` público que queda sin uso, y devolverle a cada consumidor su vía de lectura — `hook_file_download()` para el operador del back office y las rutas autenticadas `GET /api/v1/providers/%/gallery` y `GET /api/v1/providers/%/gallery/%` para la app.

Cuatro notas que la cabecera fija y condicionan todo lo demás:

- **`file_private_path` ya está configurado** en el sitio: es prerrequisito de SPEC 65, que está implementado. Este spec no lo vuelve a pedir ni migra ningún fichero — `field_gallery` nace vacío y nace privado, así que no hay `file_move()` que hacer.
- **Un fichero privado no lo sirve el servidor web.** Cada imagen del carrusel pasa por PHP y por una decisión de acceso. Es el precio de la decisión, y está aceptado.
- **Es el primer spec del marketplace que estrena rutas `api/v1/providers/...`** Reserva ese namespace para el spec del listado de proveedores, que colgará de la misma raíz.
- **`myapi_file_download()` deja de ser de reclamos.** Hoy delega en un solo include; pasa a preguntar a dos y a devolver `NULL` solo cuando ninguno reconoce el URI. Es el cambio más delicado del spec, porque una regresión ahí rompe las imágenes de reclamos, que sí tienen datos en producción.

---

## Alcance

**Dentro del alcance:**

- **`myapi.install`** (modificar):
  - `_myapi_services_install()` gana `field_gallery` (`image`, cardinalidad **10**, `uri_scheme = 'private'`) y su instancia en `provider`; y **pierde** el campo y la instancia de `field_photo`.
  - `_myapi_services_uninstall_destructive()` cambia `field_photo` por `field_gallery` en la lista `$owned`.
  - Nuevo **`myapi_update_7029()`**: llama a `_myapi_services_install()` (que crea la galería) y después borra `field_photo` con `field_delete_field()` más `field_purge_batch()`.
- **`includes/myapi.provider_files.inc`** (nuevo) — el gemelo de `includes/myapi.claims_files.inc`: resolver el nodo `provider` dueño de un `fid` a través de `field_gallery`, y la decisión de acceso del back office delegando en `node_access('view', $provider)`. Es lo único que comparten los dos consumidores (regla 3 de `CLAUDE.md`).
- **`myapi.module`** (modificar):
  - **`myapi_file_download($uri)`** pasa de un dueño a dos: pregunta a reclamos, y si no reconoce el URI pregunta a proveedores. Devuelve `NULL` solo cuando ninguno lo reclama.
  - Dos entradas nuevas en `hook_menu()`: `api/v1/providers/%/gallery` y `api/v1/providers/%/gallery/%`, `MENU_CALLBACK`, `access callback = TRUE`.
- **`resources/provider.resource.inc`** (nuevo) — `myapi_provider_gallery_dispatch()` y `myapi_provider_gallery_file_dispatch()` (solo `GET`, resto `405`), `myapi_provider_gallery_list()`, `myapi_provider_gallery_download()` y `myapi_provider_build_image()`. Nace con **solo** la galería: ningún otro dato del proveedor.
- **`includes/myapi.i18n.inc`** (modificar) — una clave nueva, `provider_not_found`, en `es` y `en`. `file_not_found` ya existe desde SPEC 65 y se reutiliza.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.provider_files.inc` y `files[] = resources/provider.resource.inc`.
- **Pruebas unitarias** — ampliación de `tests/unit/ServicesInstallTest.php` (el campo nuevo con su `uri_scheme` y cardinalidad, la desaparición de `field_photo` del instalador y de `$owned`, el borrado en el update) y `tests/unit/ProviderGalleryEndpointTest.php` (nuevo: guards de los dos dispatchers, `401` sin token, `404` con proveedor inexistente o despublicado, `404` con un `fid` de otro proveedor, forma exacta del ítem y de la envoltura, lista vacía con `200`).
- **`docs/provider-gallery.md`** (nuevo) — las dos rutas con la plantilla de `CLAUDE.md`.
- **`docs/services-install.md`** (modificar) — `field_gallery` en la tabla de `provider`, `field_photo` fuera, la tabla de esquemas de fichero actualizada, y la regla de mantenimiento del `hook_file_download()` con dos dueños.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- **El endpoint del proveedor en sí.** No hay `GET /api/v1/providers` ni `GET /api/v1/providers/%`: ni nombre, ni teléfono, ni categorías, ni calificación, ni la tarifa y los tags de SPEC 81. Este spec solo abre la galería, y el fichero `resources/provider.resource.inc` nace con esas funciones y ninguna más.
- **Cualquier escritura.** No se sube, ni se borra, ni se reordena una imagen desde la app. Lo hace el operador desde el back office, como en SPEC 77/78.
- **Estilos de imagen y miniaturas.** El endpoint sirve el fichero original. El back office sigue viendo sus miniaturas porque `image_style_deliver()` pregunta por el fichero **origen**, que es justo lo que `hook_file_download()` resuelve — el mismo mecanismo que ya funciona en reclamos desde SPEC 65.
- **`Range`, `ETag`, descargas parciales y caché HTTP.** Se sirve el fichero entero, como en SPEC 65.
- **Token por query string** (`?access_token=`). La cabecera `Authorization` es obligatoria, como en todo el módulo. Esto tiene una consecuencia real para la app y está en Riesgos.
- **Migrar nada.** `field_gallery` nace vacío; `field_photo` se borra sin trasvasar sus datos, porque no los tiene.
- **Los comprobantes de pago** (`private://comprobantes_pago`, SPEC 20) siguen sin ser reconocidos por `hook_file_download()`, exactamente igual que hoy.
- **Galerías en otros bundles** (`service_request`, `service_offer`). `field_images` sigue siendo el campo de las solicitudes y no se toca.
- **Tope de tamaño total por proveedor o cuota de almacenamiento.** Hay tope por imagen (3 MB) y por cantidad (10); el producto de ambos no se vigila.
- **Reordenar el carrusel desde otro sitio que no sea el formulario.** El orden es el de los deltas de Field API, que el operador arrastra en el back office.
- **Retirar `field_images`/`field_attachment` de `service_request`**, ni cambiar en nada la resolución de ficheros de reclamos más allá de convivir con un segundo dueño.

Dos aclaraciones sobre decisiones que están dentro del alcance y podrían leerse como excesos:

- **`resources/provider.resource.inc` nace con solo la galería.** Es contraintuitivo crear el fichero del recurso «proveedor» sin exponer un proveedor, pero la alternativa —un `resources/provider_gallery.resource.inc` aparte— crearía un recurso que no es un recurso y obligaría a fusionarlo después. La regla 2 de `CLAUDE.md` es «un recurso, un fichero», y el recurso es el proveedor.
- **La clave `provider_not_found` es nueva aunque ya exista `claim_not_found`.** Son mensajes distintos para el usuario final y el catálogo i18n no comparte claves entre recursos.

---

## Modelo de datos

No se crea ninguna tabla SQL propia. Cambia **una** entidad de configuración (un campo que nace, otro que muere), y se define la **forma de dos respuestas**, que es el contrato con la app.

### El campo nuevo

| Ajuste | Valor |
|---|---|
| `field_name` | `field_gallery` |
| `type` | `image` |
| `cardinality` | **10** |
| `settings` | `['uri_scheme' => 'private']` |

Instancia en `provider`:

| Ajuste | Valor |
|---|---|
| `label` | Galería |
| `required` | 0 |
| `settings` | `file_extensions = 'png jpg jpeg'`, `max_filesize = '3 MB'`, `alt_field = 1`, `alt_field_required = 0` |
| `widget` | `image_image` |
| `description` | «Hasta 10 imágenes para el carrusel de la ficha del proveedor. Se arrastran para ordenarlas: el orden del formulario es el que ve la app. Son privadas: solo se sirven a usuarios autenticados.» |

Tres precisiones:

- **`uri_scheme` es un ajuste de campo, no de instancia.** Se decide una vez y para siempre en el momento de crearlo: cambiarlo después exige `field_update_field()` **y** mover los ficheros con `file_move()`, que es exactamente el trabajo que costó `myapi_update_7023()` en SPEC 65. Naciendo privado, aquí no hay migración.
- **La cardinalidad `10` es del campo**, así que subirla o bajarla más adelante es un `hook_update_N`. Bajarla con datos por encima del tope es además destructivo. Es el precio del tope que se pidió.
- **El orden del carrusel es el orden de los deltas** de Field API (`delta 0, 1, 2...`), que es el que el operador arrastra en el formulario. No hay campo de peso ni ordenación por fecha.

### El campo que desaparece

`field_photo` (`image`, cardinalidad 1, `public://`) se elimina por completo: instancia, campo y columnas. Hoy no lo usa nadie fuera de `myapi.install` y dos líneas de `docs/services-install.md` — ningún resource lo lee, ninguna respuesta lo devuelve.

```php
// myapi_update_7029(), después de _myapi_services_install().
if (field_info_field('field_photo')) {
  field_delete_field('field_photo');
  field_purge_batch(1000);
}
```

**Es un borrado incondicional y por decisión expresa**: no cuenta antes las filas de `field_data_field_photo`. Si en algún entorno hubiera imágenes cargadas, se pierden sin aviso y sin vuelta atrás. La guarda `field_info_field()` solo evita el error de borrar algo que no existe — no protege datos.

### Estado de `provider` después de este spec

Doce campos, contando los tres de SPEC 81:

| De SPEC 77 (ocho, ya sin `field_photo`) | De SPEC 81 | De este spec |
|---|---|---|
| `field_provider_users`, `field_phone`, `field_address`, `field_services_desc`, `field_license_expiry`, `field_categories`, `field_rating_avg`, `field_rating_count` | `field_hourly_rate`, `field_tags`, `field_short_description` | `field_gallery` |

La regla de proveedor activo **no cambia** y `/api/v1/service-categories` sigue devolviendo los mismos conteos.

### `hook_file_download()` con dos dueños

Hoy `myapi_file_download()` delega en un solo include. Pasa a encadenar, y el orden de las comprobaciones **es la parte delicada del spec**:

```php
$headers = myapi_claims_file_download_headers($uri, $user);

// NULL significa "no es mío". Cualquier otra cosa —cabeceras o -1— ya es una
// decisión tomada sobre un fichero de reclamos, y preguntar al segundo dueño
// la convertiría en un permiso concedido por la puerta de atrás.
if ($headers !== NULL) {
  return $headers;
}

return myapi_provider_file_download_headers($uri, $user);
```

El contrato de los tres valores que Drupal espera queda igual que en SPEC 65:

| Valor | Cuándo |
|---|---|
| Array de cabeceras | El fichero es de una galería y `node_access('view', $provider)` concede |
| `-1` | El fichero es de una galería y el acceso se deniega |
| `NULL` | Ningún dueño reconoce el URI — sigue siendo la respuesta para comprobantes de pago, fotos de áreas y ficheros de otros módulos |

`myapi_provider_file_download_headers()` vive en `includes/myapi.provider_files.inc` y hace tres pasos: `uri → fid`, `fid → nid del provider dueño` (vía `field_data_field_gallery`), y la decisión `node_access('view', $node)`. Las cabeceras son `inline`, por la misma razón que en reclamos: el back office pinta estas imágenes en pantalla.

**Por qué `node_access()` y no un catálogo de roles.** `myapi_node_access()` (SPEC 78) ya decide quién ve un nodo `provider`: `administrator` y `backend` por permisos globales, y el rol `proveedor` acotado a su propia ficha. Escribir aquí un segundo catálogo obligaría a mantener dos verdades sincronizadas para siempre.

### `GET /api/v1/providers/%/gallery`

**Autenticación:** token Bearer obligatorio.

```json
{
  "success": true,
  "data": {
    "images": [
      { "id": 42, "url": "https://midominio.com/api/v1/providers/7/gallery/42", "filename": "taller-01.jpg" }
    ]
  }
}
```

Las tres claves son las mismas que devuelve un fichero de reclamo (`myapi_claim_build_file()`), por lo que la app puede reutilizar el mismo modelo:

| Clave | Tipo | Cómo se obtiene |
|---|---|---|
| `id` | int | `(int) $fid` |
| `url` | string | `url('api/v1/providers/' . $nid . '/gallery/' . $fid, ['absolute' => TRUE])` |
| `filename` | string | `file_managed.filename` |

**Nunca se devuelve una URL de fichero.** `file_create_url()` sobre un `private://` produciría `/system/files/...`, que responde `403` a la app porque no lleva sesión de Drupal. La `url` que viaja es siempre la del endpoint de descarga.

Proveedor publicado y sin imágenes → `200` con `{"images": []}`, nunca `404`. El orden es el de los deltas, sin ordenación adicional.

### `GET /api/v1/providers/%/gallery/%`

**Autenticación:** token Bearer obligatorio. Devuelve los **bytes** del fichero con sus cabeceras, no JSON. Cuatro comprobaciones en este orden:

1. Token válido → si no, `401`.
2. El `%` de la ruta es un nodo `provider` **publicado** → si no, `404 provider_not_found`.
3. El `fid` pertenece a `field_gallery` **de ese** proveedor → si no, `404 file_not_found`.
4. El fichero existe físicamente → si no, `404 file_not_found`.

Dos casos límite decididos, calcados de SPEC 65:

- **Un `fid` que existe pero es de otro proveedor** → `404 file_not_found`, no `403`. El usuario ya demostró que ve el proveedor de la ruta; no se le confirma que ese `fid` exista en otro sitio.
- **Una fila de `file_managed` cuyo fichero físico falta** → `404 file_not_found`, no un error de PHP ni una respuesta de 0 bytes.

**La caducidad de la habilitación no entra en ninguna de las cuatro.** Un proveedor caducado desaparece del marketplace, pero sus imágenes se siguen sirviendo: la alternativa es que un carrusel abierto se rompa a media sesión.

### Errores de las dos rutas

| Código | `error_code` | Cuándo |
|---|---|---|
| 401 | `missing_authorization` | Sin cabecera `Authorization` |
| 401 | `invalid_token` | Token inexistente, revocado, caducado, o de un usuario borrado o bloqueado |
| 404 | `provider_not_found` | El `nid` no existe, no es del tipo `provider`, o está despublicado. **Clave nueva** |
| 404 | `file_not_found` | El `fid` no está en la galería de ese proveedor, o el fichero físico falta. Clave ya existente (SPEC 65) |
| 405 | `method_not_allowed` | Cualquier método que no sea `GET` |

---

## Plan de implementación

1. **`myapi.install` — el campo y la instancia.** En el bloque «Own fields of 'provider'», `_myapi_reservations_ensure_field('field_gallery', ...)` con `'settings' => ['uri_scheme' => 'private']` y `'cardinality' => 10`, y su instancia con las extensiones, el tamaño y la descripción de la sección anterior. En la misma pasada, **borrar** las llamadas de `field_photo` (campo e instancia) y cambiarlo por `field_gallery` en la lista `$owned` del uninstall destructivo. Un comentario junto al `uri_scheme` que diga por qué es de campo y no de instancia, con el puntero a `myapi_update_7023()` (SPEC 65) como prueba de lo que cuesta cambiarlo después. *Verificación: `php -l myapi.install`.*

2. **`myapi.install` — `myapi_update_7029()`.** Llama a `_myapi_services_install()` y después borra `field_photo` con la guarda `field_info_field()`, `field_delete_field()` y `field_purge_batch(1000)`. El docblock dice explícitamente que el borrado es **incondicional y por decisión del usuario**, que no comprueba si hay datos y que es irreversible. El mensaje `t()` de retorno nombra el campo creado y el borrado. *Verificación: `drush updb` ofrece el 7029, lo aplica sin error, y una segunda pasada no lo ofrece; `field_photo` desaparece de `admin/reports/fields` y `field_data_field_photo` deja de existir.*

3. **`includes/myapi.provider_files.inc`** (nuevo) — tres funciones, con el reparto exacto de `includes/myapi.claims_files.inc`:
   - `myapi_provider_file_fid_by_uri($uri)` — `file_managed` por URI.
   - `myapi_provider_file_provider_nid($fid)` — el `nid` dueño vía `field_data_field_gallery`, o `NULL`. **`NULL` es lo que mantiene a este módulo con las manos fuera de los ficheros ajenos**, igual que en reclamos.
   - `myapi_provider_file_download_headers($uri, $account)` — encadena las dos anteriores y `node_access('view', $node)`, y devuelve cabeceras / `-1` / `NULL`.

   El `@file` copia la **regla de mantenimiento** de reclamos: si un spec futuro añade otro campo de fichero a `provider`, hay que crearlo `private://` **y** añadirlo a la lista que recorre la resolución del dueño, o el fichero nace inalcanzable para los dos consumidores. *Verificación: `php -l`.*

4. **`myapi.module` — `hook_file_download()` con dos dueños.** `myapi_file_download()` encadena reclamos y proveedores con la regla `$headers !== NULL` corta. El docblock explica por qué un `-1` de reclamos no puede caer al segundo dueño. *Verificación manual y prioritaria: abrir en el back office un reclamo con imágenes y confirmar que **siguen viéndose** — es la única regresión cara de este spec.*

5. **`includes/myapi.i18n.inc`** — la clave `provider_not_found` en `es` («Proveedor no encontrado.») y `en` («Provider not found.»), junto a `claim_not_found`. *Verificación: la suite de i18n, que ya comprueba que los dos catálogos tienen las mismas claves.*

6. **`resources/provider.resource.inc`** (nuevo) — cabecera `@file` que diga que el recurso nace **solo** con la galería y que el resto del proveedor es un spec futuro, los `module_load_include()` habituales (`request`, `response`, `i18n`, `token`, `auth`, `services_common`, `provider_files`), los dos dispatchers (`GET`, resto `405`), `myapi_provider_build_image()` y las dos funciones de negocio:
   - `myapi_provider_gallery_list($id)` — token, proveedor publicado, carga de `field_gallery` con los `filename` en una sola consulta, mapeo, y `myapi_respond(['images' => $items], 200)`.
   - `myapi_provider_gallery_download($id, $fid)` — las cuatro comprobaciones en orden y el streaming con `file_transfer()`.

   Más las dos entradas de `files[]` en `myapi.info`. *Verificación: `php -l`; el endpoint aún no es alcanzable.*

7. **`myapi.module` — las dos rutas.** `api/v1/providers/%/gallery` (`page arguments [3]`) y `api/v1/providers/%/gallery/%` (`page arguments [3, 5]`), `MENU_CALLBACK` y `access callback = TRUE`, junto a las de reclamos. Después, `drush cc all`. *Verificación con curl: sin cabecera → `401`; con token y proveedor con imágenes → `200` con la lista; pedir una `url` de esa lista con el mismo token → los bytes de la imagen; `POST` → `405`.*

8. **Pruebas.**
   - Ampliación de `tests/unit/ServicesInstallTest.php`: `field_gallery` existe con `uri_scheme = 'private'` y cardinalidad 10; `field_photo` **no** aparece en el instalador ni en `$owned`; el update 7029 lo borra; los siete campos prestados siguen fuera de `$owned`; los ocho campos restantes de SPEC 77 siguen intactos.
   - `tests/unit/ProviderGalleryEndpointTest.php` (nuevo): los dos dispatchers solo aceptan `GET`; `401` sin cabecera y con token inválido, revocado o caducado; `404 provider_not_found` con `nid` inexistente, de otro tipo, despublicado y no numérico; `404 file_not_found` con un `fid` de otro proveedor y con un fichero físico ausente; `200` con `{"images": []}` para un proveedor sin galería; la `url` de cada ítem apunta al endpoint y **nunca** a `/system/files`; el orden de la lista es el de los deltas; un proveedor con la habilitación caducada **sí** sirve su galería.

   *Verificación: suite completa en verde.*

9. **Documentación.** `docs/provider-gallery.md` con las dos rutas, y `docs/services-install.md` actualizado: `field_gallery` dentro, `field_photo` fuera, la tabla de esquemas de fichero con la galería en `private://`, la regla de mantenimiento del `hook_file_download()` de dos dueños, y `myapi_update_7029` en el historial.

10. **Aplicar y verificar.** `drush updb`, `drush cc all`, y recorrer los criterios de aceptación contra el sitio: un proveedor con tres imágenes, uno sin ninguna, uno despublicado, y la comprobación de que las imágenes de reclamos siguen intactas.

**Nota:** no se toca `hook_schema()`, ni `_myapi_entityreference_field_settings()`, ni `includes/myapi.claims_files.inc`, ni `resources/claim.resource.inc`, ni `includes/myapi.provider_role.inc`, ni ningún otro resource.

Dos cosas del orden que no son cosméticas:

- **El paso 4 va antes del endpoint** a propósito: es el que puede romper reclamos en producción, y conviene verificarlo en el sitio antes de que haya nada nuevo encima que enturbie el diagnóstico.
- **El borrado de `field_photo` va en el update (paso 2) y no en el instalador**, porque el instalador solo crea. Un sitio limpio nunca llega a tener el campo; uno existente lo pierde en el update.

---

## Criterios de aceptación

**Leyenda.** `[ ]` es el estado inicial de todo criterio en un spec en `Draft`. Al implementar se marcan `[x]` los verificados por la suite unitaria o por inspección del repositorio, dejando constancia expresa de los que exigen el sitio.

**Instalación, campo y update**

- [ ] En un sitio limpio, `drush en myapi` crea `field_gallery` en `provider` y **no** crea `field_photo`.
- [ ] En el sitio ya instalado, `drush updb` ofrece `myapi_update_7029`, lo aplica sin error y devuelve el mensaje que nombra el campo creado y el borrado.
- [ ] Tras el update, `field_photo` no aparece en `admin/reports/fields` y la tabla `field_data_field_photo` ya no existe.
- [ ] Una segunda pasada de `drush updb` no ofrece nada, y reejecutar `_myapi_services_install()` no duplica ni lanza `FieldException`.
- [ ] El widget de «Galería» acepta **10** imágenes y rechaza la undécima.
- [ ] Una imagen subida a la galería aterriza en el directorio **privado**, no en `sites/default/files`: comprobado en la columna `uri` de `file_managed`, que empieza por `private://`.
- [ ] `drush pm-uninstall myapi` con `MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL = FALSE` no borra `field_gallery`.

**Back office — `hook_file_download()`**

- [ ] El operador abre `node/N/edit` de un proveedor con galería y **ve las miniaturas**, no iconos rotos.
- [ ] Un usuario **anónimo** que pide directamente la URL `/system/files/...` de una imagen de galería recibe `403`, no la imagen.
- [ ] Un usuario con el rol `proveedor` ve las imágenes de **su** ficha y no las de otro proveedor — lo que decida `myapi_node_access()` es lo que se aplica, sin reglas nuevas.
- [ ] Los estilos de imagen del back office siguen generándose: la miniatura aparece aunque el derivado no existiera antes de la primera visita.

**No regresión de reclamos — la parte cara de este spec**

- [ ] Las imágenes y adjuntos de un **reclamo** siguen viéndose en el back office exactamente igual que antes del cambio en `myapi_file_download()`.
- [ ] `GET /api/v1/claims/%/files/%` sigue devolviendo los bytes con un token válido, y sigue devolviendo `404`/`403` en los mismos casos que antes.
- [ ] Un fichero de reclamo que el usuario **no** puede ver sigue denegado: el `-1` de reclamos no cae al segundo dueño y se convierte en un permiso.
- [ ] Un comprobante de pago en `private://comprobantes_pago` sigue **sin** ser servido por `hook_file_download()`: ningún dueño lo reclama y la respuesta sigue siendo `NULL`.
- [ ] Una foto de área (`field_image`, `public://`) sigue sirviéndose por URL directa sin cambios.
- [ ] `includes/myapi.claims_files.inc` y `resources/claim.resource.inc` quedan **sin tocar**: `git diff` vacío en los dos.

**`GET /api/v1/providers/%/gallery`**

- [ ] Con token válido y proveedor con imágenes, responde `200` con `{"success": true, "data": {"images": [...]}}`.
- [ ] Cada ítem trae exactamente tres claves — `id`, `url`, `filename` — y `id` es entero JSON (`42`), no cadena.
- [ ] La `url` de cada ítem apunta a `api/v1/providers/%/gallery/%` y es absoluta; **nunca** contiene `/system/files` ni `sites/default/files`.
- [ ] Un proveedor publicado y sin imágenes responde `200` con `{"images": []}`, no `404`.
- [ ] El orden de la lista es el que el operador dejó arrastrando en el formulario, y no cambia entre dos peticiones seguidas.
- [ ] Un proveedor con `field_license_expiry` en el pasado **sí** devuelve su galería: la caducidad no bloquea la lectura.
- [ ] Sin cabecera `Authorization` → `401 missing_authorization`; con token inválido, revocado o caducado → `401 invalid_token`.
- [ ] `nid` inexistente, de otro tipo de contenido, despublicado o no numérico → `404 provider_not_found`.
- [ ] `POST`, `PUT` y `DELETE` → `405 method_not_allowed`.

**`GET /api/v1/providers/%/gallery/%`**

- [ ] Pedir con token válido una `url` devuelta por el listado entrega **los bytes de la imagen**, con `Content-Type` correcto, y abre bien en la app.
- [ ] La misma URL **sin** cabecera `Authorization` responde `401`, no la imagen.
- [ ] Un `fid` que existe pero pertenece a **otro** proveedor → `404 file_not_found`, no `403` y no la imagen.
- [ ] Un `fid` cuya fila de `file_managed` apunta a un fichero físico ausente → `404 file_not_found`, no un error de PHP ni una respuesta de 0 bytes.
- [ ] Un `fid` que es de un **reclamo**, pedido por esta ruta → `404 file_not_found`.
- [ ] `POST` sobre esta ruta → `405 method_not_allowed`.

**No regresión general**

- [ ] `GET /api/v1/service-categories` devuelve byte a byte lo mismo, con y sin `?with_counts=1`.
- [ ] Ningún otro endpoint `api/v1/...` cambia: `git diff` vacío en `resources/` salvo el fichero nuevo.
- [ ] Ningún rol gana ni pierde permisos: `/admin/people/permissions` idéntico antes y después.
- [ ] Los ocho campos restantes de SPEC 77 y los tres de SPEC 81 conservan tipo, cardinalidad, requerimiento y ajustes.
- [ ] Los siete campos prestados siguen fuera de `$owned`, y el test que lo vigila sigue en verde.
- [ ] `myapi_update_7028` y anteriores quedan intactos.
- [ ] La suite unitaria pasa completa, con los ficheros de test nuevos incluidos.
- [ ] `drush cc all` no reporta errores y las dos rutas nuevas quedan en `menu_router`.

Dos criterios que parecen redundantes y son los que de verdad vigilan este spec:

- **«Un fichero de reclamo denegado sigue denegado.»** Es el bug que la implementación en cadena puede introducir: si alguien escribe `if (!$headers)` en vez de `if ($headers !== NULL)`, la comparación laxa funciona por casualidad con el `-1` de hoy y deja de funcionar el día que alguien cambie ese valor.
- **«Un `fid` de reclamo pedido por la ruta de galería da 404.»** Verifica que la pertenencia se comprueba de verdad y que las dos familias de ficheros no se cruzan por el endpoint.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Privacidad de la galería | **`private://`** desde su creación | `public://`, como era `field_photo` y como son el ícono de categoría y la imagen de área | Elección explícita del usuario. Es una divergencia consciente con el criterio de SPEC 77/79 («los activos de escaparate son públicos»), y el precio está aceptado: cada imagen pasa por PHP y exige token. A cambio, la galería de un proveedor no es accesible por URL adivinable para nadie sin sesión. |
| Alcance | **Campo + `hook_file_download()` + las dos rutas**, todo aquí | Solo el campo, con el endpoint cuando llegue `/api/v1/providers` | Elección explícita del usuario, y es la unidad mínima utilizable: un campo privado sin las dos mitades de lectura produce imágenes que ni el operador ve en su formulario. Es lo mismo que hizo SPEC 65 en un solo spec. |
| Listado además de descarga | **Las dos rutas** | Solo la descarga | Sin listado la app no puede conocer los `fid`, así que el endpoint de bytes no tendría quién lo llamara. Una galería es una lista de imágenes antes que un fichero suelto. |
| Campo propio o prestado | **`field_gallery` nuevo** | Reutilizar `field_images`, que ya es `private://` y ya lo comparten reclamos y solicitudes | `field_images` está atado a `myapi_claims_file_claim_nid()`, que resuelve «qué reclamo posee este fid»: un fichero de proveedor colgando de ahí resolvería a `NULL` y quedaría denegado para todos, operador incluido, hasta que alguien tocara la lógica de reclamos. La regla de mantenimiento escrita en `includes/myapi.claims_files.inc` prohíbe justo eso. Y compartir el campo ataría el `uri_scheme`, el tamaño y las extensiones de proveedores a los de reclamos para siempre. |
| Cardinalidad | **10**, tope duro | Ilimitada, como los tags de SPEC 81 | Elección explícita del usuario. A diferencia de un tag, cada imagen cuesta 3 MB de disco y una petición autenticada al pintarse: un carrusel no es una lista infinita. El precio es que subir o bajar el tope es un `hook_update_N`, y bajarlo con datos por encima es destructivo. |
| Eliminar `field_photo` | **Sí**, campo e instancia | Conservarlo como portada pública junto a la galería privada | Elección explícita del usuario, y no tiene datos. Conservarlo dejaría al mismo proveedor con una portada pública y una galería privada — dos reglas de acceso para el mismo tipo de activo, que es exactamente la incoherencia que este spec cierra. Si hace falta una portada, es la primera imagen de la galería. |
| Forma del borrado de `field_photo` | **Incondicional**: guarda de existencia, no de datos | Contar filas en `field_data_field_photo` y abortar si hay alguna | **Decisión expresa del usuario tras plantearle el riesgo.** `field_delete_field()` es irreversible y no pregunta: si un entorno tuviera imágenes cargadas, se pierden. Queda en Riesgos. |
| Acceso del back office | **`node_access('view', $provider)`** | Un catálogo de roles propio, como `myapi_claims_admin_roles()` en SPEC 65 | `myapi_node_access()` (SPEC 78) ya decide quién ve un nodo `provider`, incluido el rol `proveedor` acotado a su propia ficha. Un segundo catálogo serían dos verdades que mantener sincronizadas, y la primera vez que divergieran el síntoma sería un operador que ve la ficha pero no sus imágenes. |
| Acceso por API | **Cualquier token válido**; el nodo debe estar publicado | Restringir por rol, condominio o vivienda | Es la regla de `/api/v1/service-categories`: el marketplace es el mismo para todo el sitio y la app ya tiene sesión cuando lo pinta. Filtrar por condominio exigiría antes decidir cómo se relaciona un proveedor con un condominio, que sigue sin estar modelado. |
| La caducidad de la habilitación | **No bloquea** la galería | Exigir proveedor activo también para las imágenes | La caducidad decide si el proveedor **aparece** en el marketplace, no si sus bytes son legibles. Bloquearla rompería un carrusel ya abierto a media sesión, y el residente vería imágenes rotas sin explicación. La regla de «activo» sigue viviendo donde tiene sentido: en los listados. |
| Proveedor despublicado | `404 provider_not_found` | `403`; o servir igual | Un nodo despublicado no es visible para la app en ningún otro endpoint del módulo, y `404` no confirma que ese `nid` exista. |
| `fid` de otro proveedor | `404 file_not_found` | `403` | Calcado de SPEC 65: el usuario ya demostró que ve el proveedor de la ruta, así que no se filtra nada; pero tampoco se le confirma que ese `fid` exista en otro sitio. Falla cerrado. |
| Encadenado de `hook_file_download()` | **Reclamos primero, con corte en `$headers !== NULL`** | Un `switch` sobre el directorio del URI; o preguntar a los dos y combinar | El corte estricto es lo que impide que un `-1` de reclamos —una denegación ya decidida— caiga al segundo dueño y se convierta en un permiso. Un `switch` por directorio ataría la lógica a la ruta física de los ficheros, que Drupal puede cambiar. |
| Dónde vive la resolución del dueño | **`includes/myapi.provider_files.inc`**, fichero nuevo | Dentro de `resources/provider.resource.inc`; o en `includes/myapi.services_common.inc` | Tiene dos consumidores —`hook_file_download()` y el endpoint— y la regla 3 de `CLAUDE.md` manda compartir. No va en `services_common.inc` por la misma razón que reclamos no la puso en `claims_common.inc`: ese fichero declara en su `@file` que es puro y sin base de datos, y resolver un dueño es una consulta. |
| Un solo resource o dos | **`resources/provider.resource.inc`** con solo la galería | `resources/provider_gallery.resource.inc` aparte | La regla 2 de `CLAUDE.md` es «un recurso, un fichero», y el recurso es el proveedor. Un fichero de galería habría que fusionarlo el día que llegue el listado de proveedores. |
| Forma del ítem | `id`, `url`, `filename` | Añadir `alt`, `width`, `height`, `filesize` | Son las tres claves exactas que ya devuelve un fichero de reclamo (`myapi_claim_build_file()`), así que la app reutiliza modelo y widget. Los extras son fáciles de añadir después y hoy nadie los ha pedido. |
| La `url` del ítem | La del **endpoint** de descarga | `file_create_url()` sobre el `private://` | `file_create_url()` produciría `/system/files/...`, que responde `403` a la app porque no lleva sesión de Drupal. Sería una URL que solo funciona en el navegador del operador. |
| Estilos de imagen | Fuera de alcance: se sirve el original | `?style=thumbnail` en el endpoint | Exige acordar y crear el estilo en el sitio, que es configuración con su propio spec. El original tiene 3 MB de tope. El back office sigue viendo miniaturas porque `image_style_deliver()` pide el fichero **origen**, que es lo que `hook_file_download()` resuelve. |
| Token por cabecera | Solo `Authorization` | Admitir `?access_token=` en la URL de la imagen | Es la regla de todo el módulo y SPEC 65 ya la fijó para el mismo caso. Tiene una consecuencia real para la app —un `Image.network` pelado no sirve— y está en Riesgos. |
| Subida desde la app | Fuera de alcance | Un `POST` de imágenes en este spec | Elección explícita del usuario: sube el operador. Un endpoint de escritura de ficheros exige validación de tipo real (no solo extensión), cuota, borrado y reordenación: es más spec que todo este junto. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Romper las imágenes de reclamos al tocar `myapi_file_download()`.** Es el riesgo más caro del spec, y el único que afecta a datos que ya existen en producción: un encadenado mal escrito deja al operador sin ver las fotos de ningún reclamo, o peor, convierte una denegación en un permiso. | El paso 4 del plan va **antes** que el endpoint precisamente para verificarlo aislado. Cuatro criterios de aceptación lo vigilan (miniaturas del back office, endpoint de reclamos, fichero denegado que sigue denegado, comprobante que sigue sin servirse), `includes/myapi.claims_files.inc` y `resources/claim.resource.inc` quedan con `git diff` vacío, y la regla del corte es `$headers !== NULL` y no una comparación laxa. |
| **`field_delete_field('field_photo')` borra imágenes si algún entorno no está vacío.** El borrado es incondicional por decisión expresa: no cuenta filas y no aborta. Si staging o producción tuvieran fotos cargadas, se pierden con el update, sin aviso y sin vuelta atrás. | **Riesgo asumido conscientemente.** Lo único que queda es operativo: confirmar `SELECT COUNT(*) FROM field_data_field_photo` en cada entorno **antes** de ejecutar `drush updb`, y tener copia de seguridad de la base de datos y del directorio de ficheros, que es procedimiento normal de despliegue. El docblock del update lo dice en la primera línea para que nadie lo ejecute creyendo que es inocuo. |
| **Un `Image.network` pelado de Flutter no puede pintar el carrusel**: la URL exige cabecera `Authorization` y ese widget no la manda. Es el error que la app va a cometer, y el síntoma es una imagen rota sin mensaje. | Documentado en `docs/provider-gallery.md` con la salida concreta, que es la misma que ya usa la app para las imágenes de reclamos desde SPEC 65: un widget que acepte `headers`, o un cargador con caché propio. Es la consecuencia directa de la decisión de privacidad, no un defecto del endpoint. |
| **Cada imagen del carrusel es una petición autenticada por PHP.** Diez imágenes, diez arranques de Drupal: no hay caché de servidor web, ni CDN, ni `304` por `ETag`. Un carrusel que se recorra entero cuesta bastante más que uno público. | Aceptado como precio de la privacidad. Se acota con el tope de 10 imágenes y los 3 MB por fichero. Si llegara a doler, la salida es un image style servido por el mismo endpoint —que es fuera de alcance hoy, no imposible— y no volver a hacer públicas las imágenes. |
| **Crecimiento del directorio privado.** Diez imágenes de 3 MB por proveedor son 30 MB por ficha en el peor caso, en un directorio que ninguna tarea limpia. | Aceptado, mismo criterio que SPEC 32/55/77: la gestión del almacenamiento es operativa. `field_purge_batch()` en el update se ocupa de lo borrado, no de lo vivo. |
| **La regla de mantenimiento se olvida.** Si un spec futuro añade otro campo de fichero a `provider` y no lo crea `private://` ni lo suma a la resolución del dueño, el fichero nace público o inalcanzable — y nadie lo nota hasta que un operador ve un icono roto. | La regla queda escrita en el `@file` de `includes/myapi.provider_files.inc` y en `docs/services-install.md`, copiando literalmente el mecanismo que SPEC 65 dejó para reclamos, que es el precedente de que funciona. |
| **Bajar la cardinalidad de 10 es destructivo.** Si alguien la reduce a 5 con proveedores que tienen ocho imágenes, Drupal descarta las sobrantes. | Anotado junto a la definición del campo. Subirla es inocuo; bajarla exige comprobar antes los deltas existentes. Es el coste conocido del tope duro que se pidió. |
| **`resources/provider.resource.inc` nace con solo dos funciones de galería**, y quien llegue después puede leerlo como un recurso incompleto y "arreglarlo" añadiendo un listado de proveedores improvisado. | El `@file` dice explícitamente que el recurso nace acotado a la galería por decisión de alcance y que el listado del proveedor es su propio spec. Es el mismo aviso que SPEC 77 dejó en los campos del chat. |
| **Dos criterios de privacidad conviviendo en el marketplace**: íconos de categoría públicos, galería de proveedor privada. Quien lea un spec después del otro puede pensar que es una incoherencia. | Anotado en la tabla de decisiones y en `docs/services-install.md`, en la misma tabla de esquemas de fichero donde ya conviven `public://` y `private://` desde SPEC 65. El criterio escrito para specs futuros es: catálogo del sitio → público; contenido subido para una ficha o un caso → privado. |
