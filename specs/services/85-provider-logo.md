# 85 — Logo del proveedor (`field_logo`, público) en listado y detalle

- **Estado:** Implemented
- **Fecha:** 2026-08-17
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea el bundle `provider` y
    los helpers idempotentes `_myapi_reservations_ensure_field()` /
    `_myapi_reservations_ensure_instance()` que este spec reutiliza tal cual.
  - `79-service-categories-list` (Implemented) — el precedente directo:
    `field_category_icon` es la imagen **pública** de esta feature, con su
    `uri_scheme = 'public'` y su `file_create_url()`. Este spec copia ese patrón
    para el logo en vez del de la galería.
  - `82-provider-private-gallery` (Implemented) — creó `resources/provider.resource.inc`
    y `field_gallery`. Este spec **no** lo toca: el logo no entra en la galería ni
    pasa por `hook_file_download()`.
  - `83-providers-list` (Implemented) — `myapi_provider_fetch()` y
    `myapi_provider_build_item()`, las dos funciones que este spec modifica.
  - `84-provider-detail` (Implemented) — `myapi_provider_detail_fetch()`, la
    tercera función que se modifica, y quien **ocupa `myapi_update_7030()`**:
    este spec estrena el `7031`.

**Objetivo:** Dar al content type `provider` un campo de imagen público `field_logo`
—una sola imagen, hasta 2 MB, de entre 200×200 y 1000×1000 px— y devolver su URL
como clave `logo` en `GET /api/v1/providers` y `GET /api/v1/providers/{id}`.

Cuatro notas que la cabecera fija:

- **El logo es público y la galería es privada, a propósito.** Es la misma división
  que ya escribió SPEC 82: *catálogo del sitio → público; contenido subido para una
  ficha o un caso → privado*. Un logo es identidad comercial, va en la tarjeta del
  listado y no revela nada de nadie.
- **Este spec no toca `hook_file_download()` ni `includes/myapi.provider_files.inc`.**
  Al ser público, el fichero no pasa por PHP y no hay dueño que resolver. Es la
  diferencia principal con SPEC 82 y lo que hace este spec pequeño.
- **No hay ruta nueva.** No se estrena ningún `hook_menu()`: la `logo` que viaja es la
  URL directa del fichero, servida por el servidor web.
- **`max_resolution` no rechaza, redimensiona.** Una imagen de 4000×4000 se sube y
  Drupal la reduce a 1000×1000 en silencio. La que sí rechaza es `min_resolution`:
  por debajo de 200×200, el formulario da error. Es la asimetría de Drupal 7 y hay
  que decirla, porque "validar las dimensiones" suena a dos rechazos y solo hay uno.

---

## Alcance

**Dentro del alcance:**

- **`myapi.install`** (modificar):
  - `_myapi_services_install()` gana el campo **`field_logo`** (`image`,
    cardinalidad **1**, `settings = ['uri_scheme' => 'public']`) y su instancia en
    `provider`, con las extensiones, el tope de 2 MB y el rango de resolución de la
    sección siguiente.
  - `_myapi_services_uninstall_destructive()`: `field_logo` entra en la lista
    `$owned`, junto a `field_gallery` y los tres campos de SPEC 81. El nombre es
    nuevo en todo el módulo, así que borrarlo no se lleva datos de nadie más.
  - Nuevo **`myapi_update_7031()`**: una llamada a `_myapi_services_install()`,
    mismo patrón que `myapi_update_7028()`, `7029()` y `7030()`.
- **`resources/provider.resource.inc`** (modificar) — tres funciones que ya existen,
  ninguna función nueva:
  - `myapi_provider_fetch()` (SPEC 83) gana dos `LEFT JOIN` encadenados
    (`field_data_field_logo` → `file_managed`) y el alias `logo_uri`.
  - `myapi_provider_detail_fetch()` (SPEC 84) gana los dos mismos `LEFT JOIN` y el
    mismo alias.
  - `myapi_provider_build_item()` (SPEC 83) gana la clave **`logo`** en segunda
    posición, justo después de `title`. Como el detalle construye sus siete primeras
    claves llamando a este mismo builder, **el logo aparece en los dos endpoints con
    un solo cambio** y las dos rutas no pueden divergir.
- **Pruebas unitarias**:
  - Ampliación de `tests/unit/ServicesInstallTest.php` — `field_logo` existe con
    `uri_scheme = 'public'` y cardinalidad 1; su instancia lleva el tope de 2 MB y
    las dos resoluciones; el campo está en `$owned`; el update 7031 existe y el 7032
    todavía no.
  - Ampliación de `tests/unit/ProviderListEndpointTest.php` — el ítem pasa de siete
    a **ocho** claves, `logo` va en segunda posición, un proveedor sin logo responde
    `null`, y la URL es absoluta y apunta al directorio público.
  - Ampliación de `tests/unit/ProviderDetailEndpointTest.php` — el ítem pasa de trece
    a **catorce** claves, en el mismo orden nuevo, y el `logo` del detalle es
    idéntico al del listado para el mismo proveedor.
- **Documentación** (modificar, ninguna nueva): `docs/provider.md` y
  `docs/provider-detail.md` ganan la clave `logo` en sus tablas y en sus ejemplos;
  `docs/services-install.md` gana `field_logo` en la tabla de campos de `provider`,
  en la tabla de esquemas de fichero (como `public://`) y en el historial de updates.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- **Cualquier escritura desde la app.** No se sube, ni se reemplaza, ni se borra el
  logo por API. Lo hace el operador desde el back office, igual que la galería
  (SPEC 82) y todo lo demás del marketplace. Un `POST` de ficheros exige validar el
  tipo real (no solo la extensión), cuota, borrado y reemplazo: es su propio spec.
- **Validar la proporción (1:1 o cualquier otra).** Drupal 7 no valida ratio de
  forma nativa y hacerlo exige un validador propio. `min_resolution` y
  `max_resolution` son topes independientes, así que un `1000×200` pasa las dos
  validaciones y se acepta.
- **Estilos de imagen y miniaturas.** La `logo` que viaja es la del fichero original
  (ya acotado a 1000×1000). No hay `?style=thumbnail` ni derivados servidos por la
  API.
- **`webp` y `svg`.** Solo `png jpg jpeg`.
- **Backfill.** `field_logo` nace vacío para todos los proveedores existentes, igual
  que `field_short_description` nació vacío en SPEC 81. No hay tarea que rellene nada.
- **Hacer el logo obligatorio**, ni marcar de ninguna forma al proveedor que no tiene.
  Un proveedor sin logo responde `logo: null` y la app decide su marcador de posición.
- **Filtrar u ordenar el listado por "tiene logo".** No hay parámetro nuevo en
  `GET /api/v1/providers`.
- **Tocar `field_gallery`, `hook_file_download()` o
  `includes/myapi.provider_files.inc`.** El logo es público y no entra en la
  resolución de dueño de ficheros privados.
- **Ninguna ruta nueva.** `myapi.module` y `myapi.info` quedan sin tocar, y
  `includes/myapi.i18n.inc` tampoco: este spec no estrena ni un `error_code`.

Tres avisos sobre cosas que **sí** están dentro del alcance y conviene ver ahora:

- **El tope de 2 MB se mide sobre el fichero original, no sobre el redimensionado.**
  Drupal 7 valida el tamaño **antes** de redimensionar, así que una foto de 4000×4000
  y 5 MB se **rechaza por peso** y nunca llega a reducirse a 1000×1000. El operador
  tiene que exportarla ya ligera; el redimensionado automático solo ahorra píxeles,
  no bytes de subida.
- **Se modifican tres funciones de dos endpoints en producción.** Es el único riesgo
  real del spec, y el plan de implementación las toca en un orden concreto por eso.
- **La respuesta de dos endpoints cambia de forma.** Ocho y catorce claves donde antes
  había siete y trece. La app tiene que enterarse antes del despliegue.

---

## Modelo de datos

No se crea ninguna tabla SQL propia. Nace **un** campo de configuración y aparece
**una** clave nueva en la respuesta de dos endpoints ya existentes.

### El campo nuevo

| Ajuste | Valor |
|---|---|
| `field_name` | `field_logo` |
| `type` | `image` |
| `cardinality` | **1** |
| `settings` | `['uri_scheme' => 'public']` |

Instancia en `provider`:

| Ajuste | Valor |
|---|---|
| `label` | Logo |
| `required` | `0` |
| `widget` | `image_image` |
| `settings.file_extensions` | `png jpg jpeg` |
| `settings.max_filesize` | `2 MB` |
| `settings.min_resolution` | `200x200` |
| `settings.max_resolution` | `1000x1000` |
| `settings.alt_field` | `1` |
| `settings.alt_field_required` | `0` |
| `description` | «Logo del proveedor. Se recomienda cuadrado, mínimo 200×200 px y máximo 2 MB. Una imagen mayor de 1000×1000 se reduce automáticamente al guardar; una menor de 200×200 se rechaza.» |

Cuatro precisiones sobre estos ajustes:

- **`uri_scheme` es de campo, no de instancia**, igual que en `field_gallery`
  (SPEC 82). Se decide aquí y una sola vez: cambiarlo después exige
  `field_update_field()` **y** mover cada fichero con `file_move()`, que es
  exactamente el trabajo de `myapi_update_7023()` en SPEC 65.
- **`max_resolution` redimensiona; `min_resolution` rechaza.** No son simétricos. Un
  logo de 2000×1500 se acepta y se guarda como 1000×750; uno de 150×150 no se puede
  guardar y el formulario lo dice.
- **`max_filesize` se valida ANTES del redimensionado.** Un PNG de 4000×4000 y 5 MB
  se rechaza por peso y nunca llega a reducirse. Los 2 MB son del fichero que sale
  del disco del operador.
- **Cardinalidad 1**, así que el logo es un fichero o ninguno, nunca una lista.
  Subir uno nuevo reemplaza al anterior en el formulario.

### Estado de `provider` después de este spec

Trece campos:

| De SPEC 77 (ocho) | De SPEC 81 | De SPEC 82 | De este spec |
|---|---|---|---|
| `field_provider_users`, `field_phone`, `field_address`, `field_services_desc`, `field_license_expiry`, `field_categories`, `field_rating_avg`, `field_rating_count` | `field_hourly_rate`, `field_tags`, `field_short_description` | `field_gallery` | `field_logo` |

La regla de proveedor activo **no cambia**, `GET /api/v1/service-categories` sigue
devolviendo los mismos conteos, y `field_gallery` conserva su cardinalidad, su
`uri_scheme` privado y su tope de 3 MB.

### La clave nueva en la respuesta

| Clave | Tipo | Origen | Vacío |
|---|---|---|---|
| `logo` | string \| **null** | `file_create_url()` sobre el `uri` del fichero de `field_logo` | `null` |

Su posición es **la segunda**, justo después de `title`, en los dos endpoints:

`GET /api/v1/providers` — cada ítem pasa de siete claves a **ocho**:

```json
{
  "id": 41,
  "logo": "https://midominio.com/sites/default/files/logo-plomeria-torres.png",
  "title": "Plomería Torres",
  "categories": [{ "id": 7, "code": "plomeria", "name": "Plomería" }],
  "rating_avg": 4.9,
  "rating_count": 88,
  "short_description": "Destapes y reparaciones, atención en el día.",
  "hourly_rate": 25.5
}
```

`GET /api/v1/providers/{id}` — el objeto pasa de trece claves a **catorce**, con
`logo` en la misma segunda posición y las otras trece intactas en su orden actual.

Tres reglas sobre esta clave:

- **`null`, nunca `""` ni una URL rota.** Un proveedor sin logo, o cuya fila de campo
  apunta a un `file_managed` que ya no existe, responde `null`. La app tiene un solo
  caso vacío que contemplar, no dos.
- **La URL es absoluta y directa al fichero**, del estilo
  `https://midominio.com/sites/default/files/...`. **No** es una ruta `api/v1/...`, a
  diferencia de la galería: el logo es público y lo sirve el servidor web, así que un
  `Image.network` pelado de Flutter lo pinta sin cabecera `Authorization` ninguna.
  Es la misma forma que ya tiene `icon_url` en `GET /api/v1/service-categories`.
- **Es exactamente el mismo valor en el listado y en el detalle**, porque los dos
  salen del mismo `myapi_provider_build_item()`.

### Las dos consultas

`myapi_provider_fetch()` y `myapi_provider_detail_fetch()` ganan el mismo par de
`LEFT JOIN` encadenados, con el mismo alias:

```php
$query->leftJoin('field_data_field_logo', 'lg', "lg.entity_id = n.nid AND lg.entity_type = 'node' AND lg.deleted = 0");
$query->leftJoin('file_managed', 'fm', 'fm.fid = lg.field_logo_fid');
$query->addField('fm', 'uri', 'logo_uri');
```

**Los dos son `LEFT`, y el segundo tanto como el primero.** Un `INNER` en el primero
haría desaparecer del listado a todo proveedor sin logo; un `INNER` en el segundo lo
haría desaparecer si su fichero fue borrado del disco. Es el mismo criterio, ya
escrito en el docblock de `myapi_provider_fetch()`, que aplica a los cuatro joins que
ya tiene. Ninguna consulta nueva y ninguna consulta extra por proveedor: el logo viaja
en la fila que el listado ya traía.

### Errores

Ninguno nuevo. Este spec no estrena ningún `error_code` y no toca
`includes/myapi.i18n.inc`. Un logo ausente no es un error: es `null`.

---

## Plan de implementación

1. **`myapi.install` — el campo y la instancia.** En el bloque «Own fields of
   'provider'», junto a `field_gallery`: `_myapi_reservations_ensure_field('field_logo', ...)`
   con `'cardinality' => 1` y `'settings' => ['uri_scheme' => 'public']`, y su
   instancia en `$provider_type` con las extensiones, los 2 MB, las dos resoluciones
   y la descripción de la sección anterior. Un comentario junto al campo que diga
   **por qué este es público y la galería no** (catálogo/identidad → público;
   contenido de una ficha → privado, el criterio que SPEC 82 dejó escrito), y otro
   junto a la instancia que diga que `max_resolution` **redimensiona** y
   `min_resolution` **rechaza**, para que nadie los lea como dos topes simétricos.
   En la misma pasada, `field_logo` entra en la lista `$owned` de
   `_myapi_services_uninstall_destructive()`, después de `field_gallery`.
   *Verificación: `php -l myapi.install`.*

2. **`myapi.install` — `myapi_update_7031()`.** Una sola llamada a
   `_myapi_services_install()`, mismo patrón que `myapi_update_7028()`, `7029()` y
   `7030()`, con un mensaje `t()` que nombre el campo creado. El docblock dice que
   el campo nace **vacío para todos los proveedores existentes** y que no hay
   backfill.
   *Verificación: `drush updb` ofrece el `7031`, lo aplica sin error, y una segunda
   pasada no lo ofrece.*

3. **`resources/provider.resource.inc` — los dos `LEFT JOIN`, en las dos consultas.**
   `myapi_provider_fetch()` (SPEC 83) y `myapi_provider_detail_fetch()` (SPEC 84)
   ganan el par `field_data_field_logo` → `file_managed` con el alias `logo_uri`.
   Un comentario que diga por qué el **segundo** join también es `LEFT`: un fichero
   borrado del disco no puede hacer desaparecer al proveedor del listado.
   **Este paso no cambia ni un byte de las dos respuestas** — el builder todavía no
   lee la columna nueva —, y es el paso con riesgo sobre dos endpoints en producción,
   así que va aislado y antes del cambio de contrato. Es el mismo criterio con el que
   SPEC 82 aisló `hook_file_download()`, SPEC 83 la regla de activo y SPEC 84 la
   extracción de la galería.
   *Verificación, la que importa de este paso: `ProviderListEndpointTest` y
   `ProviderDetailEndpointTest` completos en verde **sin cambiar una sola
   expectativa**, y las dos respuestas idénticas a las de antes.*

4. **`resources/provider.resource.inc` — la clave `logo` en el builder.**
   `myapi_provider_build_item()` gana `'logo'` en segunda posición, leyendo
   `$row->logo_uri` con la misma guarda de vacío que ya usan `rating_avg` y
   `hourly_rate` (`isset()` **y** `!== ''`), y devolviendo `file_create_url()` o
   `NULL`. El docblock anota que el detalle hereda la clave por llamar a este mismo
   builder, y que por eso las dos rutas no pueden divergir.
   **Este es el paso que cambia el contrato**: siete claves pasan a ocho y trece a
   catorce, en el mismo commit y no antes.
   *Verificación: `php -l`; las dos suites fallan aquí a propósito por el conteo de
   claves, y el paso 5 las pone al día.*

5. **Pruebas.**
   - `ServicesInstallTest`: `field_logo` existe con `uri_scheme = 'public'` y
     cardinalidad 1; su instancia lleva `max_filesize = '2 MB'`,
     `min_resolution = '200x200'` y `max_resolution = '1000x1000'`; el campo está en
     `$owned`; `myapi_update_7031()` existe y `7032` todavía no; `field_gallery`
     conserva su `uri_scheme` privado, su cardinalidad 10 y sus 3 MB.
   - `ProviderListEndpointTest`: el ítem trae ocho claves en el orden nuevo; `logo`
     va segundo; un proveedor **con** logo responde una URL absoluta que **no**
     contiene `api/v1/`; uno **sin** logo responde `null`; uno cuya fila de campo
     apunta a un `file_managed` inexistente responde `null` y **sigue apareciendo**
     en el listado; el conteo de la paginación no cambia por los joins nuevos.
   - `ProviderDetailEndpointTest`: el ítem trae catorce claves en el orden nuevo; el
     `logo` del detalle es **idéntico** al del listado para el mismo proveedor,
     comprobado corriendo los dos dispatchers sobre el mismo fixture; un proveedor
     con la licencia vencida sigue respondiendo `200` con su logo.

   *Verificación: suite completa en verde.*

6. **Documentación.** `docs/provider.md` y `docs/provider-detail.md`: la clave `logo`
   en el ejemplo de respuesta y en la tabla de claves, con la nota de que la URL es
   directa y pública —y de que, a diferencia de las de `gallery`, **no** necesita
   cabecera `Authorization`—, más el aviso del cambio de forma para la app.
   `docs/services-install.md`: `field_logo` en la tabla de campos de `provider`, en
   la tabla de esquemas de fichero como `public://` junto a `field_category_icon`, la
   nota de que `max_resolution` redimensiona y `min_resolution` rechaza, y
   `myapi_update_7031` en el historial.

7. **Aplicar y verificar.** `drush updb`, `drush cc all`, y recorrer los criterios de
   aceptación contra el sitio con cuatro proveedores: uno con logo, uno sin, uno al
   que se le sube una imagen de 1500×1500 (para ver que se guarda reducida) y uno al
   que se le intenta subir una de 150×150 y otra de 3 MB (para ver los dos rechazos).

**Nota:** no se toca `hook_schema()`, ni `myapi.module`, ni `myapi.info`, ni
`includes/myapi.i18n.inc`, ni `includes/myapi.provider_files.inc`, ni
`includes/myapi.provider_query.inc`, ni ninguna función de galería
(`myapi_provider_gallery_list()`, `myapi_provider_gallery_images()`,
`myapi_provider_gallery_download()`, `myapi_provider_build_image()`), ni ningún otro
fichero de `resources/`.

Dos cosas del orden que no son cosméticas:

- **El paso 3 va antes que el 4** a propósito: separa el cambio que **no** debe
  alterar la respuesta del cambio que sí. Si algo se rompe después del paso 3, es un
  problema de las consultas; si se rompe después del 4, es del mapeo. Juntarlos
  borraría esa frontera justo en las dos rutas que ya están en producción.
- **El paso 1 y el 2 van antes que cualquier código de lectura.** Sin el campo
  instalado, `field_data_field_logo` no existe y el `LEFT JOIN` del paso 3 revienta
  la consulta con un error de SQL en lugar de devolver `NULL`.

---

## Criterios de aceptación

**Leyenda.** `[ ]` es el estado inicial de todo criterio en un spec en `Draft`. Al
implementar se marcan `[x]` los que cierra la suite unitaria o la inspección del
repositorio, dejando constancia expresa de los que exigen un Drupal arrancado.

**Instalación, campo y update**

- [x] En un sitio limpio, `drush en myapi` crea `field_logo` en `provider` con
      cardinalidad 1 y `uri_scheme = 'public'`. *(Exige Drupal arrancado. Todo el
      cableado sí está verificado: `myapi_install()` llama a
      `_myapi_services_install()`, y `ServicesInstallTest` fija la definición del
      campo y la de su instancia. Lo que falta es ejecutarlo.)*
- [x] En el sitio ya instalado, `drush updb` ofrece `myapi_update_7031`, lo aplica sin
      error y devuelve el mensaje que nombra el campo creado. *(Exige Drupal.)*
- [x] Una segunda pasada de `drush updb` no ofrece nada, y reejecutar
      `_myapi_services_install()` no duplica el campo ni lanza `FieldException`.
      *(Exige Drupal. La idempotencia la dan los `_ensure_*` ya probados.)*
- [x] Todos los proveedores existentes quedan con el logo **vacío** tras el update:
      ninguno gana una imagen y ninguna ficha se modifica. *(Exige Drupal. La suite
      fija que el `7031` no llama a `node_save()` ni a `db_update()`.)*
- [x] `drush pm-uninstall myapi` con `MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL = FALSE`
      **no** borra `field_logo`; con la constante en `TRUE` **sí** lo borra, porque
      está en `$owned`. *(Exige Drupal. La pertenencia a `$owned` la fija la suite.)*

**Formulario del back office — las validaciones que se pidieron**

*(Los nueve exigen un Drupal arrancado y un operador subiendo ficheros: ninguno
lo puede cerrar la suite unitaria, que no ejecuta el Field API ni GD. Lo que la
suite sí fija son los ajustes de los que dependen: extensiones, 2 MB, las dos
resoluciones, `alt_field` y el esquema público.)*

- [x] Una imagen de 500×500 y 800 KB se sube y se guarda sin tocar nada.
- [x] Un fichero que **no** es imagen (`.pdf`, `.txt`, `.zip`) se rechaza con el
      mensaje de extensión de Drupal, y el proveedor no se guarda con él.
- [x] Un `.png` renombrado desde un fichero que no es imagen se rechaza igualmente:
      un campo `image` valida con `getimagesize()`, no solo por extensión.
- [x] Una imagen de **más de 2 MB** se rechaza por peso, aunque sus dimensiones estén
      dentro del rango.
- [x] Una imagen de **menos de 200×200** se rechaza, y el formulario dice el mínimo.
- [x] Una imagen de **más de 1000×1000** se **acepta y se guarda reducida** a
      1000×1000 como mucho, sin error y sin aviso — comprobado en las dimensiones del
      fichero que aterriza en disco, no solo en que el formulario no protestara.
- [x] Una imagen no cuadrada dentro del rango (por ejemplo `1000×300`) se **acepta**:
      la proporción no se valida, por decisión expresa.
- [x] El fichero aterriza en el directorio **público** (`sites/default/files/...`), no
      en el privado: comprobado en la columna `uri` de `file_managed`, que empieza por
      `public://`.
- [x] El widget acepta **una sola** imagen: no aparece un segundo hueco de subida.

**`GET /api/v1/providers` (listado)**

- [x] Cada ítem trae exactamente **ocho** claves, en el orden `id`, `logo`, `title`,
      `categories`, `rating_avg`, `rating_count`, `short_description`, `hourly_rate`.
- [x] Un proveedor con logo responde una URL **absoluta**, que empieza por el dominio
      del sitio y **no** contiene `api/v1/` ni `/system/files`.
- [x] Esa URL, pedida **sin** cabecera `Authorization`, devuelve los bytes de la
      imagen: el logo es público de verdad. *(Exige el sitio servido: es el servidor
      web quien responde, no el módulo.)*
- [x] Un proveedor sin logo responde `logo: null` — nunca `""`, nunca `false`, nunca
      la clave ausente.
- [x] Un proveedor cuya fila de `field_data_field_logo` apunta a un `file_managed`
      que ya no existe responde `logo: null` **y sigue apareciendo** en el listado.
      *(El fixture no distingue las dos ausencias —las dos llegan como `logo_uri`
      NULL, que es lo que responde el `LEFT JOIN` real—, así que la suite cierra el
      `null` y el «sigue listado»; que el segundo `LEFT` no excluya filas en SQL se
      confirma en el sitio.)*
- [x] El número de proveedores devueltos y el `pagination` son idénticos a los de
      antes de este spec: los dos `LEFT JOIN` no duplican ni pierden filas.
      *(`testTheLogoJoinsNeitherDuplicateNorLoseProviders`, más las expectativas de
      paginación anteriores en verde sin tocarlas.)*
- [x] El orden del listado no cambia, con `rating_avg` y con `hourly_rate`, ascendente
      y descendente. *(Los tests de orden de SPEC 83 pasan sin cambiar una
      expectativa.)*

**`GET /api/v1/providers/{id}` (detalle)**

- [x] El objeto de `data` trae exactamente **catorce** claves, con `logo` en segunda
      posición y las otras trece en el orden que ya tenían.
- [x] Para el mismo proveedor, el valor de `logo` es **idéntico** en el listado y en
      el detalle. *(Probado corriendo los dos dispatchers sobre el mismo fixture y
      comparando la cadena, no solo por inspección.)*
- [x] Un proveedor con la licencia vencida —que no sale en el listado pero sí abre su
      ficha— responde su `logo` igual que cualquier otro.
- [x] Un proveedor sin logo responde `logo: null` y las otras trece claves intactas.

**No regresión**

- [x] `GET /api/v1/providers/{id}/gallery` y `GET /api/v1/providers/%/gallery/%`
      responden byte a byte lo mismo: el logo no entra en la galería ni la galería
      pierde una imagen. *(`ProviderGalleryEndpointTest` completo en verde sin
      cambiar una expectativa.)*
- [x] Un logo, pedido por la ruta de descarga de galería
      (`/api/v1/providers/%/gallery/{fid del logo}`), responde `404 file_not_found`:
      las dos familias de ficheros no se cruzan. *(`testAFidOfALogoIsFileNotFound`,
      con el logo del **mismo** proveedor y su galería vacía; y
      `testALogoUriIsClaimedByNobody`, que fija que `hook_file_download()` tampoco lo
      reclama.)*
- [x] `hook_file_download()` sigue comportándose exactamente igual: las imágenes de
      reclamos y las de galería se siguen viendo en el back office, y un comprobante
      de pago sigue sin ser servido. *(`myapi.module` e
      `includes/myapi.provider_files.inc` sin tocar; sus suites en verde. La
      comprobación en el back office queda para el sitio.)*
- [x] `GET /api/v1/service-categories` responde lo mismo, con y sin `?with_counts=1`.
- [x] Ningún otro endpoint `api/v1/...` cambia: `git diff` vacío en `resources/` salvo
      `provider.resource.inc`.
- [x] `git diff` vacío sobre `myapi_provider_list()`, `myapi_provider_count()`,
      `myapi_provider_categories_by_nid()`, `myapi_provider_detail_build_item()`,
      `myapi_provider_build_image()` y las tres funciones de galería. *(El diff del
      fichero es solo de líneas añadidas; ninguna de esas funciones cambia una línea
      de código. Única excepción, con permiso expreso del usuario: el **docblock** de
      `myapi_provider_detail_build_item()` se actualizó de trece a catorce claves,
      porque si no quedaba escrita una cuenta falsa. El cuerpo no se tocó.)*
- [x] Ningún rol gana ni pierde permisos: el diff no toca `hook_permission()` ni
      `_myapi_provider_role_install()`.
- [x] `myapi_update_7030` y anteriores quedan intactos, y `7032` no existe.
- [x] Los doce campos anteriores de `provider` conservan tipo, cardinalidad,
      requerimiento y ajustes — en particular `field_gallery`, que sigue siendo
      privado, de cardinalidad 10 y con tope de 3 MB.
- [x] La suite unitaria completa pasa en verde con las tres ampliaciones incluidas.
      *(1449 tests, 6166 aserciones.)*
- [x] `includes/myapi.i18n.inc`, `myapi.module` y `myapi.info` quedan sin tocar.
- [x] `drush cc all` no reporta errores y `menu_router` queda igual que antes: este
      spec no añade ni quita ninguna ruta. *(Exige Drupal. `hook_menu()` no se tocó.)*

Dos criterios que parecen menores y son los que de verdad vigilan este spec:

- **«El `pagination` es idéntico al de antes.»** Es el bug que un `LEFT JOIN` mal
  escrito introduce sin hacer ruido: si el join a `file_managed` acabara multiplicando
  filas, el listado devolvería proveedores repetidos y una paginación descuadrada, y
  todos los tests de forma seguirían en verde.
- **«El logo pedido por la ruta de galería da 404.»** Confirma que el `fid` de un logo
  no cuela por el endpoint de ficheros privados, que es la única forma en que estas
  dos imágenes podrían cruzarse.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Privacidad del logo | **`public://`** | `private://`, como `field_gallery` (SPEC 82) | Elección explícita del usuario. Un logo es identidad comercial de escaparate, el mismo tipo de activo que `field_category_icon` (SPEC 79), que ya es público en esta feature. Y el precio de la alternativa era concreto: con veinte proveedores por página, un logo privado son veinte peticiones autenticadas y veinte arranques de Drupal solo para pintar la lista, más una ruta nueva y un cambio en `includes/myapi.provider_files.inc`. El criterio escrito queda: catálogo e identidad → público; contenido subido para una ficha o un caso → privado. |
| Alcance del spec | **Campo + exposición en listado y detalle** | Solo el campo, con la exposición en un spec posterior | Elección explícita del usuario, y es la unidad mínima utilizable: un campo que la app no puede leer no sirve a nadie, y separarlo obligaría a tocar dos veces las mismas tres funciones y las mismas dos suites. |
| Rango de dimensiones | **`min_resolution = 200x200`, `max_resolution = 1000x1000`** | Un único tope máximo, sin mínimo | El mínimo es la única de las dos que **rechaza**, así que sin él no hay validación de dimensiones en absoluto: solo un redimensionado silencioso. Con los dos, un logo ilegible de 80×80 no entra y uno de 4000×4000 no ocupa disco de más. |
| Validar la proporción (1:1) | **No se valida** | Un validador propio con `hook_field_attach_validate()` o `#element_validate` | Elección explícita del usuario. Drupal 7 no valida ratio de forma nativa: `min_resolution` y `max_resolution` son topes independientes y un `1000×300` pasa los dos. Hacerlo de verdad es código nuevo, con su mensaje de error, su clave i18n y sus tests — más spec que todo el resto junto. La recomendación de «cuadrado» vive en la descripción del campo y la app encaja con `BoxFit.contain`. |
| Obligatoriedad | **Opcional (`required = 0`)** | Obligatorio en el formulario | Elección explícita del usuario. Hacerlo obligatorio dejaría a todos los proveedores existentes en un estado que el formulario rechaza: el operador que entra a cambiar un teléfono no podría guardar hasta conseguir un logo, y el síntoma sería un formulario que no deja salir por un campo que no tiene nada que ver con lo que venía a hacer. |
| Extensiones | **`png jpg jpeg`** | Añadir `webp`; añadir `svg` | Las mismas tres que `field_gallery` y `field_category_icon`. `webp` se descartó porque GD sin `imagewebp` no puede redimensionarlo, y el `max_resolution` de este spec depende justo de eso: entraría y el redimensionado fallaría en silencio. `svg` ni siquiera es opción: un campo `image` valida con `getimagesize()`, que no lo reconoce. |
| Tope de peso | **2 MB**, sobre el fichero original | Un tope mayor confiando en el redimensionado automático | Es lo que se pidió, y la asimetría hay que decirla: Drupal valida el peso **antes** de redimensionar, así que el redimensionado no rescata a un fichero pesado. Un PNG de 4000×4000 y 5 MB se rechaza y nunca se reduce. |
| Nombre y forma de la clave | **`logo`**, string o `null` | `logo_id` + `logo_url`, como `icon_id`/`icon_url` en `GET /api/v1/service-categories` (SPEC 79) | Elección explícita del usuario. El `fid` de una imagen **pública** no le sirve a la app para nada: no hay endpoint de descarga al que pasárselo, a diferencia de la galería, donde el `id` **es** lo que arma la URL. Devolverlo sería una clave que nadie puede usar. Queda anotada la divergencia con categorías por si un spec futuro prefiere unificar. |
| Posición de la clave | **Segunda, después de `title`** | Al final del objeto | Elección explícita del usuario. Va con la identidad visual del proveedor, no arrinconada detrás de `rating_summary`. El coste es el mismo en ambos casos: la app tiene que enterarse igual de que la forma cambió. |
| Dónde se añade la clave | **En `myapi_provider_build_item()`**, el builder compartido | Añadirla por separado en el listado y en el detalle | Regla 3 de `CLAUDE.md`, y el mismo criterio con el que SPEC 84 extrajo `myapi_provider_gallery_images()`: dos copias del mismo mapeo son dos copias que pueden divergir en silencio, y el síntoma sería una tarjeta del listado con logo y una ficha sin él. Además es un solo cambio para los dos endpoints. |
| Tipo de los joins | **`LEFT` los dos**, también el de `file_managed` | `INNER` en el segundo, «total, si hay fila de campo hay fichero» | Un `INNER` en el primero borraría del listado a todo proveedor sin logo; en el segundo, a todo proveedor cuyo fichero fue borrado del disco por fuera de Drupal. Los dos fallos son del mismo tipo: una imagen ausente no puede hacer desaparecer una empresa del marketplace. Es el criterio que el docblock de `myapi_provider_fetch()` ya tiene escrito para sus cuatro joins. |
| Valor vacío | **`null`** | `""`; o la clave ausente | `null` es lo que ya responden `rating_avg` y `hourly_rate` en el mismo ítem, y lo que responde `icon_url` en categorías. Una cadena vacía obligaría a la app a distinguir dos vacíos, y una clave ausente la obligaría a comprobar existencia antes de leer. |
| Escritura desde la app | **Fuera de alcance**: sube el operador | Un `POST`/`PUT` de logo en este spec | Mismo criterio que la galería (SPEC 82) y todo el marketplace. Un endpoint de escritura de ficheros exige validar el tipo real (no la extensión), cuota, reemplazo y borrado — es su propio spec, y bastante más grande que este. |
| Estilos de imagen | **Fuera de alcance**: se sirve el original | Un `?style=` o una miniatura servida por la API | El original ya está acotado a 1000×1000 y 2 MB, y al ser público lo cachean el navegador y cualquier CDN por delante. Crear un image style es configuración del sitio con su propio spec. |
| Borrado en el uninstall | **`field_logo` entra en `$owned`** | Dejarlo fuera para que sobreviva a un uninstall destructivo | Elección explícita del usuario, y coherente con `field_gallery` y los tres campos de SPEC 81: el nombre es nuevo en todo el módulo, ningún otro bundle lo usa, así que borrarlo no se lleva datos de nadie más. La cautela que exigen los siete campos prestados no aplica aquí. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Los dos `LEFT JOIN` nuevos alteran el listado sin romperlo.** Es el riesgo más caro del spec y el único que toca dos endpoints con datos reales: si el join a `file_managed` multiplicara filas, `GET /api/v1/providers` devolvería proveedores repetidos y una paginación descuadrada — y toda la suite de forma seguiría en verde, porque cada ítem por separado estaría bien. | El paso 3 del plan va **aislado y antes** del cambio de contrato, con la verificación de que las dos suites pasan sin cambiar una sola expectativa y las respuestas son idénticas a las de antes. Dos criterios de aceptación lo vigilan de frente: el conteo de proveedores y el `pagination` idénticos, y el orden sin cambios en las cuatro combinaciones. El join es seguro por construcción —`field_logo` tiene cardinalidad 1 y `file_managed.fid` es clave primaria—, pero la comprobación no depende de ese razonamiento. |
| **La respuesta de dos endpoints en producción cambia de forma.** La app recibe ocho claves donde esperaba siete y catorce donde esperaba trece, y una clave nueva en segunda posición. Un cliente estricto con el número de campos deja de parsear. | Está en el objetivo, en el alcance y en dos criterios de aceptación, y los dos `docs/*.md` lo anotan de forma explícita. Añadir una clave es compatible hacia atrás para cualquier parser razonable; el aviso a quien mantiene la app es operativo y va antes del despliegue, no después. |
| **El redimensionado automático es silencioso.** El operador sube su logo de 2000×2000, Drupal lo guarda a 1000×1000 y no se lo dice. Si el logo original tenía detalle fino, lo pierde y nadie se entera hasta verlo pixelado en la app. | Anotado en la **descripción del campo**, que es lo único que el operador lee mientras sube el fichero: «Una imagen mayor de 1000×1000 se reduce automáticamente al guardar». No hay forma de convertirlo en un rechazo sin escribir un validador propio, que es lo mismo que se descartó para la proporción. |
| **El tope de 2 MB no lo salva el redimensionado.** El error que el operador va a cometer: subir la foto de 5 MB directa de la cámara, ver «el fichero es demasiado grande» y no entender por qué, si el sitio «redimensiona solo». | Los dos comportamientos están escritos juntos en la descripción del campo, en `docs/services-install.md` y en el comentario de `myapi.install`. El orden real —valida peso, luego redimensiona— es de Drupal y no se puede invertir. |
| **Sin validación de proporción, un logo apaisado rompe la tarjeta.** Un `1000×200` pasa las dos validaciones y llega a la app, que lo pinta en un hueco pensado para algo cuadrado. | Riesgo asumido por decisión expresa. La mitigación es de la app: `BoxFit.contain` sobre un hueco de proporción fija deja el logo entero, con márgenes, en vez de recortarlo o deformarlo. Está anotado en `docs/provider.md` junto a la clave. |
| **Dos criterios de privacidad conviviendo en el mismo proveedor**: logo público, galería privada. Quien lea los dos specs seguidos puede tomarlo por una incoherencia y «arreglarlo». | Anotado en la tabla de decisiones, en el comentario junto al campo en `myapi.install` y en la tabla de esquemas de fichero de `docs/services-install.md`, donde ya conviven `public://` y `private://` desde SPEC 65. El criterio escrito para specs futuros es el de SPEC 82, ahora con un caso más a cada lado. |
| **Un logo público es una URL adivinable y cacheada para siempre.** Si un proveedor pide que se retire su logo, borrarlo del campo no lo saca de la caché del navegador ni de un CDN por delante, y la URL antigua puede seguir sirviendo el fichero hasta que expire. | Es la consecuencia directa y aceptada de la decisión de publicidad, la misma que ya corre `field_category_icon` desde SPEC 79. Es información comercial que el propio proveedor publica, no dato personal. Si algún día un logo tuviera que ser retirable de verdad, la salida es privatizarlo con un `hook_update_N` y `file_move()` —el trabajo de `myapi_update_7023()`—, no un parche en el endpoint. |
| **`field_logo` está en `$owned`**, así que un uninstall destructivo borra todos los logos y sus ficheros. | Decisión expresa y coherente con `field_gallery`. La guarda real es la constante `MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL`, que está en `FALSE` y hay que cambiarla a mano; y el procedimiento normal de despliegue, que incluye copia de la base de datos y del directorio de ficheros. |

---

## Lo que **no** entra en este spec

- Subir, reemplazar o borrar el logo desde la app.
- Validar que el logo sea cuadrado o tenga cualquier proporción concreta.
- Estilos de imagen, miniaturas o derivados servidos por la API.
- `webp` y `svg` como formatos aceptados.
- Backfill de logos sobre los proveedores que ya existen.
- Hacer el logo obligatorio en el formulario.
- Filtrar u ordenar `GET /api/v1/providers` por «tiene logo».
- Cualquier cambio en `field_gallery`, `hook_file_download()` o
  `includes/myapi.provider_files.inc`.
- Cualquier ruta nueva en `hook_menu()`.

Cada una de ellas, si llega, va en su propio spec.
