# SPEC 126 — Detalle de supervisión de una solicitud de servicio

> **Estado:** Implemented · **Depende de:** SPEC 49 (rol `administrador edificio`, `myapi_building_admin_condominium_map()`, `myapi_node_access()`), SPEC 77 (bundle `service_request`, catálogos de `includes/myapi.services_common.inc`), SPEC 87 (`field_assigned_provider` y el estado `direct`), SPEC 89 (`myapi_service_request_detail_row()`, `myapi_service_request_load_offers()`, `myapi_service_request_load_images()`, `hook_file_download()` de solicitudes), SPEC 94 (`myapi_service_transaction_timeline_rows()` y `myapi_service_transaction_author_label()`), SPEC 100 (las diez columnas de presupuesto de una oferta), SPEC 123 (gate de cobertura y tests de contrato), SPEC 125 (la página de listado, `myapi_service_requests_admin_roles()` / `_access()`, y la entrada de `service_request` en el dominio de solo lectura del building admin) · **Fecha:** 2026-09-10
>
> **Objetivo:** Añadir `admin/content/service-requests/%node` — una pantalla de **supervisión estrictamente de solo lectura**, accesible a los tres roles del listado — que muestre una solicitud completa en cuatro bloques: datos, línea de tiempo de transacciones, ofertas recibidas y adjuntos. Es lo que convierte el listado de la SPEC 125 en supervisión. **No introduce ni una sola consulta nueva sobre `service_request`**: reutiliza `myapi_service_request_detail_row()`, `myapi_service_transaction_timeline_rows()` y `myapi_service_request_load_offers()`, que ya existen, y lee los adjuntos del nodo que el router ya cargó.

---

## Alcance

**Dentro:**

- **`includes/myapi.service_request_detail_admin.inc`** (nuevo) — la pantalla entera: la página, los constructores de las cuatro tablas y el aviso de solicitud rota. Todos los helpers son puros salvo la página.

  > El nombre lleva el sufijo `_admin` y **no** es `myapi.service_requests_admin.inc` (el listado, plural). Es exactamente el criterio que ya separa `includes/myapi.service_transaction.inc` de `includes/myapi.service_transaction_admin.inc`: el sufijo significa "esto solo corre detrás de una pantalla de back-office". Emparejado así, el archivo nuevo se lee como el gemelo de back-office de `includes/myapi.service_request_detail.inc`, que es literalmente lo que es.

- **`myapi.module`** (modificar):
  - `hook_menu()`: entrada **`admin/content/service-requests/%node`**, `MENU_CALLBACK` (hija del listado, no visible en el menú), `file` apuntando al `.inc` nuevo, `page arguments` / `access arguments` = `[3]`.
  - **`myapi_service_request_admin_detail_access($node)`** — tres condiciones y ninguna más: el nodo es del bundle `service_request`, el lector tiene uno de los tres roles del listado (`myapi_service_requests_admin_access()`, **reutilizada, no reescrita**), y `node_access('view', $node)` dice que sí.
- **`includes/myapi.service_requests_admin.inc`** (modificar) — **una sola celda**: la columna 1 (ID) del listado pasa de ser un entero suelto a ser un enlace a la pantalla nueva. Ni una columna más, ni un cambio en el enlace del título.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.service_request_detail_admin.inc`.
- **`includes/myapi.service_request_files.inc`** (modificar) — **solo un comentario**. Su docblock de `myapi_service_request_file_access()` afirma hoy que *"'service_request' is deliberately absent from `myapi_building_admin_condominium_map()`"*, y la SPEC 125 lo metió en ese mapa. El código no cambia (esa función lee `field_condominium` a mano y sigue dando la misma respuesta); lo que se corrige es la afirmación, que ya es falsa y que un lector usaría para razonar sobre el acceso a los adjuntos — que es justo lo que esta spec estrena como pantalla.
- **`tests/unit/ModuleContractTest.php`** (modificar) — `admin/content/service-requests/%node` entra en `NON_VERSIONED_PATHS`. Sin eso, `testEveryEndpointIsUnderTheVersionOnePrefix()` se pone en rojo, que es exactamente lo que ese test existe para hacer.
- **`tests/unit/ServiceRequestAdminDetailTest.php`** (nuevo) — los helpers puros bajo los stubs de `tests/unit/bootstrap.php`: las filas del bloque de datos, las cuatro celdas de una transacción, las ocho de una oferta, los adjuntos leídos del nodo, el aviso de solicitud rota, y el gate de bundle del access callback.
- **`tests/unit/ServiceRequestsAdminPageTest.php`** (modificar) — la celda 1 del listado ahora es un enlace; el caso que la fija por posición se actualiza.
- **`docs/service-request-admin-detail.md`** (nuevo) y **`docs/service-requests-list.md`** (modificar: la columna ID enlaza).
- `drush cc all` al final. **`drush updb` no hace falta**: no hay schema, ni campo, ni permiso, ni `hook_update_N`.

**Fuera de alcance:**

- **Cualquier control de edición, en cualquier bloque.** Ni crear transacción, ni editar, ni borrar, ni adjudicar una oferta, ni cambiar el estado, ni un enlace a `node/%nid/edit`. La pantalla es de lectura de principio a fin, y el camino a la edición sigue siendo el que la SPEC 125 dejó: el enlace del **título** en el listado.
- **Consultas nuevas sobre `service_request`, `service_offer` o `service_transaction`.** Las tres que la pantalla necesita ya existen. Si una hiciera falta, es señal de que el bloque está mal recortado.
- **Tocar `includes/myapi.service_request_query.inc`, `includes/myapi.service_request_detail.inc` o `includes/myapi.service_transaction_admin.inc`.** Se leen desde fuera con `module_load_include()` y no cambia una línea de ninguno.
- **Cualquier endpoint `api/v1/...`.** No se toca un archivo de `resources/`.
- **Paginación de las tres tablas del detalle.** Una solicitud tiene un puñado de transacciones, ofertas e imágenes; las tres se cargan enteras, igual que ya hace la línea de tiempo de la SPEC 94.
- **Filtros, ordenación por columnas, exportación.** No hay nada que filtrar en un solo registro.
- **El bloque de chat** (SPEC 116/117) y el historial de notificaciones. Son otra pantalla y otra spec.
- **La valoración** (`service_rating`). Se muestra su existencia a través del estado `closed`, no el detalle de la valoración: es el quinto bundle y merece su propio bloque cuando alguien lo pida.
- **CSS nuevo.** Cuatro `#theme => 'table'` y encabezados; no hace falta una hoja.

---

## Modelo de datos

**No se crea ninguna tabla, ningún campo, ningún permiso y ninguna consulta.** Lo que esta spec define es una composición de lo que ya existe.

### De dónde sale cada bloque

| Bloque | Función reutilizada | Archivo | Coste |
|---|---|---|---|
| 1. Datos | `myapi_service_request_detail_row($nid)` | `includes/myapi.service_request_query.inc` (SPEC 89) | 1 consulta |
| 2. Línea de tiempo | `myapi_service_transaction_timeline_rows($nid)` | `includes/myapi.service_transaction_admin.inc` (SPEC 94) | 1 consulta |
| 3. Ofertas recibidas | `myapi_service_request_load_offers($nid, [])` | `includes/myapi.service_request_detail.inc` (SPEC 89) | 1 consulta |
| 4. Adjuntos | los campos del `%node` que el router ya cargó | — | **0 consultas** |

Tres consultas de bloque más un `user_load()` para el nombre del solicitante, y ninguna de ellas escrita aquí.

### Bloque 1 — Datos

Pares etiqueta / valor sobre `#theme => 'table'` de dos columnas, en este orden:

| # | Etiqueta | Origen (alias de `myapi_service_request_detail_row()`) | Cuando falta |
|---|---|---|---|
| 1 | ID | `nid` | — |
| 2 | Título | `title` | `—` |
| 3 | Estado | `status` → `myapi_services_request_statuses()` | valor crudo si el catálogo no lo conoce |
| 4 | Condominio | `condominium_name` | `—` |
| 5 | Vivienda | `unit_name` | `—` |
| 6 | Solicitante | `requester_uid` resuelto contra `users` | `Usuario eliminado (#uid)` |
| 7 | Categoría | `category_name` (+ `category_code` entre paréntesis si lo hay) | `—` |
| 8 | Fecha de creación | `created`, `d/m/Y H:i` | — |
| 9 | Fecha deseada | `desired_start`, `d/m/Y H:i` | `—` |
| 10 | Fecha de cierre | `closed_at`, `d/m/Y H:i` | `—` |
| 11 | Proveedor adjudicado | `assigned_provider_name` / `assigned_provider_raw` | `—` / `Proveedor eliminado (#id)` |
| 12 | Oferta adjudicada | `assigned_offer_id` / `assigned_offer_raw` + `assigned_offer_status` | `—` / `Oferta eliminada (#id)` |
| 13 | Descripción | `description`, en su propia fila a ancho completo | `—` |

Las tres reglas de etiqueta son las que **ya escribió la SPEC 125** y se reutilizan tal cual desde `includes/myapi.service_requests_admin.inc`: `myapi_service_requests_status_label()`, `myapi_service_requests_text_label()` y `myapi_service_requests_date_label()`. El par resuelto/crudo de proveedor y oferta sigue el patrón de tres ramas de `myapi_service_requests_provider_label()`, y por eso el bloque distingue "sin adjudicar" de "adjudicada a algo que ya no está".

`requester_uid` llega crudo — la consulta del detalle de la API proyecta el uid y no el nombre, porque la respuesta JSON no lo necesita. El nombre se resuelve con **un `user_load()`**, no con una consulta nueva ni con un join añadido a una función compartida: es un registro, no una página de veinte.

### El caso roto: `myapi_service_request_detail_row()` puede devolver `FALSE` para una solicitud que el listado sí muestra

Es la consecuencia menos obvia de esta spec y merece su propio párrafo.

`myapi_service_request_detail_row()` arranca de `myapi_service_request_base_query()`, que hace **INNER JOIN sobre `field_requester`, `field_category` y `taxonomy_term_data`** — a propósito, por la decisión 6 de la SPEC 125 leída al revés: la app de un residente no debe listar una solicitud rota. El listado de back-office hace lo contrario: **todos sus joins son LEFT**, precisamente para que una solicitud sin categoría, sin solicitante o con un término borrado **aparezca** en la tabla con `—`.

Las dos reglas son correctas y son opuestas, así que existe una solicitud que el operador ve en el listado y cuya consulta de detalle no devuelve fila. Sin tratarla, el enlace del listado llevaría a un 404 justo en las filas que el operador tiene que arreglar.

**La pantalla la trata como diagnóstico y no como error.** Cuando la fila es `FALSE`:

- se pinta un aviso `drupal_set_message(..., 'warning')` que dice qué pasa y por qué — la solicitud existe y está publicada, pero no tiene categoría o no tiene solicitante, y **por eso la app no la ve**;
- el bloque 1 se pinta igualmente con lo que el nodo cargado sí sabe de sí mismo: nid, título y fecha de creación;
- **los bloques 2, 3 y 4 se pintan enteros**, porque sus tres fuentes no dependen ni de la categoría ni del solicitante.

Que la pantalla de supervisión diga en voz alta "esta solicitud está rota y por eso es invisible para la app" es más valioso que cualquier fila del bloque 1.

### Bloque 2 — Línea de tiempo de transacciones

`myapi_service_transaction_timeline_rows($nid)` tal cual: orden `field_status_date DESC, nid DESC` — lo último que pasó arriba, que es la lectura de un operador.

Cuatro columnas: **Estado**, **Fecha del estado**, **Comentario**, **Autor**.

Se reutiliza `myapi_service_transaction_author_label()` para la última.

**No se reutiliza `myapi_service_transaction_timeline_table_rows()`**, y esa es la única cosa de la SPEC 94 que esta pantalla vuelve a escribir. Esa función construye **seis** celdas: las cuatro de arriba más `Editar` y `Borrar`, dos enlaces que son controles de edición. Pasarle un flag para que se calle las dos últimas convertiría una función de una pantalla en una función de dos con un modo, que es la manera de romper las dos a la vez. Lo que se copia son cuatro expresiones ternarias; lo que se evita es un parámetro de modo en código de escritura.

La fecha del estado se pinta **`substr($value, 0, 16)`**, no `format_date(strtotime(...))`. `field_status_date` se creó con `tz_handling = 'none'` (SPEC 55): lo almacenado es una hora local ingenua y convertirla desplazaría la hora que alguien escribió a mano. Es la misma regla — y el mismo comentario — de `myapi_service_transaction_timeline_table_rows()`, y es la razón de que este bloque **no** use `myapi_service_requests_date_label()` para esa columna.

### Bloque 3 — Ofertas recibidas

`myapi_service_request_load_offers($nid, [])`. **La lista vacía es la clave**: el segundo parámetro es el recorte por proveedor, y vacío significa "todas las ofertas de esta solicitud", que es la respuesta del solicitante y la que un supervisor necesita. No se le pasa nada más y no se filtra después en PHP.

Ocho columnas: **ID**, **Proveedor**, **Estado**, **Importe**, **Tipo de importe**, **Válida hasta**, **Mensaje**, **Fecha**.

- Estado contra `myapi_services_offer_statuses()`; tipo de importe contra `myapi_services_offer_amount_types()`. Ninguna etiqueta se escribe aquí.
- Una oferta cuyo proveedor fue despublicado llega con `provider_id` a `NULL` y **se queda en la lista** (así la construyó la SPEC 89, para que la lista y `offers_count` no se contradigan): la celda dice `Proveedor eliminado`.
- Una oferta anterior a la SPEC 100 no tiene ninguna de las diez columnas de presupuesto: `Importe`, `Tipo de importe` y `Válida hasta` responden `—`, que es exactamente lo que la API ya sirve para ella.
- La oferta adjudicada se marca en su fila (clase `myapi-offer-selected` sobre el `<tr>`), cotejando el `nid` contra `assigned_offer_id` del bloque 1. Es una marca visual, no un control.

### Bloque 4 — Adjuntos

Sale del **`$node` que el router ya cargó**, leyendo `field_attachment` y `field_images`. Coste: cero consultas, porque `node_load()` ya corrió `field_attach_load()`.

**Y deliberadamente NO de `myapi_service_request_load_images()`**, aunque exista y aunque sea la función que un lector esperaría reutilizar aquí. Dos razones, y la primera basta:

1. Esa función devuelve items de `myapi_service_request_build_file()`, cuya `url` es **`api/v1/service-requests/{id}/files/{fid}`** — un endpoint que exige un token Bearer. La sesión de un operador de back-office no lo lleva, así que cada enlace daría 401. Lo que esta pantalla necesita es `file_create_url($uri)`, que va por `system/files/...` y lo autoriza `hook_file_download()` → `myapi_service_request_file_download_headers()` → `myapi_service_request_file_access()`, que **ya reconoce a los tres roles** y ya acota al `administrador edificio` por condominio. El control de acceso a los adjuntos, por tanto, no se escribe: existe desde la SPEC 89.
2. Esa función tampoco devuelve el `uri`, que es justo lo que `file_create_url()` pide, así que reutilizarla costaría además un `file_load_multiple()` sobre los fids para recuperar lo que el nodo ya tenía en memoria.

Columnas: **Archivo** (el nombre, enlazado), **Tipo** (`filemime`) y **Origen** (`Adjunto` para `field_attachment`, `Imagen` para `field_images`). El orden es el adjunto primero y luego las imágenes por `delta`, que es el orden en que se subieron.

---

## Plan de implementación

1. **`tests/unit/ServiceRequestAdminDetailTest.php` (nuevo) — en rojo, antes de tocar nada.**
   Los helpers puros: las trece filas del bloque 1 con la fila completa y con una a la que le falta todo lo opcional; las tres ramas de proveedor adjudicado y las tres de oferta adjudicada; las cuatro celdas de una transacción, incluido el `substr()` de la fecha de estado y el fallback del comentario vacío; las ocho de una oferta, incluida la anterior a la SPEC 100 y la del proveedor despublicado; los adjuntos leídos de un objeto nodo con las dos, con una y con ninguna de las dos colecciones de archivos; y el aviso de solicitud rota.
   *Verificación: `vendor/bin/phpunit` en rojo por función inexistente, en esos casos y solo en esos.*

2. **`includes/myapi.service_request_detail_admin.inc` (nuevo) — `@file` y helpers puros.**
   El `@file` explica: por qué la pantalla vive en `includes/` y no en `resources/`; por qué el sufijo `_admin`; que **no contiene ni una consulta**, y de dónde sale cada bloque; y por qué el bloque 4 no llama a `myapi_service_request_load_images()`. Dentro, en este orden: `myapi_service_request_admin_summary_rows()`, `myapi_service_request_admin_offer_label()` (el par resuelto/crudo de la oferta adjudicada), `myapi_service_request_admin_timeline_table_rows()`, `myapi_service_request_admin_offer_table_rows()`, `myapi_service_request_admin_node_files()` (extractor puro sobre el objeto nodo), `myapi_service_request_admin_file_table_rows()` y `myapi_service_request_admin_broken_notice()`.
   *Verificación: `php -l`; `vendor/bin/phpunit` **en verde**.*

3. **Mismo archivo — la página.**
   `myapi_service_request_admin_detail_page($node)`: los tres `module_load_include()` (`myapi.services_common`, `myapi.service_request_query` + `myapi.service_request_detail`, `myapi.service_transaction_admin`, `myapi.service_requests_admin`), la fila del detalle, la rama del caso roto, los cuatro bloques con su `#empty` y el enlace `Volver al listado`. **Ni un `l()` hacia `/edit`, ni un botón, ni un formulario.**
   *Verificación: `php -l`; la pantalla renderiza con datos de prueba; `grep -n "edit\|delete\|form\|submit"` sobre el archivo no devuelve un solo control.*

4. **`myapi.module` — ruta y acceso.**
   La entrada de `hook_menu()` y `myapi_service_request_admin_detail_access()`. La comprobación de bundle primero (un nid de otro tipo responde 403, no una pantalla vacía), después el gate de rol reutilizado, después `node_access('view', $node)`. El docblock dice por qué el acotado por condominio es `node_access()` y no una lista de condominios escrita aquí: es la mitad por-nodo de la decisión 2 de la SPEC 125, y la escribe `myapi_node_access()` desde que el bundle entró en `myapi_building_admin_condominium_map()`.
   *Verificación: `php -l`.*

5. **`tests/unit/ModuleContractTest.php` — el allowlist.**
   `admin/content/service-requests/%node` en `NON_VERSIONED_PATHS`.
   *Verificación: `vendor/bin/phpunit --filter ModuleContract` en verde — incluidos `testEveryCallbackLivesInTheFileItsRouteDeclares()` y `testEveryRouteFileIsDeclaredInTheInfoFile()`, que son los que atrapan un `file` mal escrito.*

6. **`myapi.info` — `files[]`.**
   *Verificación: `testEveryFileDeclaredInTheInfoFileExists()` y `testEveryIncludeAndResourceIsDeclaredInTheInfoFile()` en verde.*

7. **`includes/myapi.service_requests_admin.inc` — la celda del ID, y `tests/unit/ServiceRequestsAdminPageTest.php`.**
   La columna 1 pasa a `l($nid, 'admin/content/service-requests/' . $nid)`. Nada más de ese archivo cambia: el enlace del título sigue resolviéndose con `myapi_service_requests_editable_map()` y sigue llevando a `node/%nid/edit` o a `node/%nid`. El caso que fija las nueve celdas por posición se actualiza para esperar un enlace en la primera.
   *Verificación: `vendor/bin/phpunit` en verde; las nueve columnas siguen siendo nueve.*

8. **`includes/myapi.service_request_files.inc` — el comentario obsoleto.**
   Se reescribe el párrafo que afirma que `service_request` no está en `myapi_building_admin_condominium_map()`, explicando qué cambió con la SPEC 125 y por qué la función sigue leyendo `field_condominium` a mano: quitar esa lectura ahora sería un cambio de comportamiento disfrazado de limpieza.
   *Verificación: lectura; `git diff` muestra únicamente comentario.*

9. **`docs/service-request-admin-detail.md` (nuevo) y `docs/service-requests-list.md` (modificar).**
   Ruta, control de acceso con su matriz de roles, los cuatro bloques con sus columnas, el caso de la solicitud rota, cómo se autorizan los adjuntos y la anomalía `backend` + `proveedor`. En el doc del listado, la columna ID pasa a describirse como enlace.
   *Verificación: lectura contra la implementación.*

10. **Matriz manual, en un sitio real.**
    Las mismas cinco cuentas de la SPEC 125. Se comprueba: quién abre la ruta; que un `administrador edificio` abre el detalle de una solicitud suya y recibe **403** en el de una ajena; que los cuatro bloques se pintan; que una solicitud sin ofertas, sin transacciones y sin adjuntos muestra los tres mensajes de vacío y no un error; que los enlaces de adjunto descargan para los tres roles y **no** para un residente autenticado; y que una solicitud rota a propósito — término de categoría borrado — muestra el aviso y los tres bloques restantes en lugar de un 404.
    *Verificación: la matriz, anotada en el commit.*

11. **Cierre.** `drush cc all`. `drush updb` no se ejecuta.
    *Verificación: `vendor/bin/phpunit` verde, `vendor/bin/phpstan analyse --memory-limit=1G` sin errores nuevos, y el gate de cobertura satisfecho para el archivo nuevo — que es lo que `ServiceRequestAdminDetailTest` cubre.*

---

## Criterios de aceptación

> **Estado de la verificación** (2026-09-10):
> `[x]` verificado con test unitario o comprobación estática, con la evidencia anotada ·
> `[~]` verificado a medias, con lo que falta anotado ·
> `[ ]` pendiente de la matriz manual del paso 10 del plan, que necesita un sitio levantado.

**Ruta y acceso**

- [~] `admin/content/service-requests/<nid>` responde 200 para `administrator`, `backend` y `administrador edificio` **de ese condominio**. — *`ServiceRequestAdminDetailTest::testTheRoleGateIsTheListingsOwn()` recorre los tres roles de `myapi_service_requests_admin_roles()` y afirma que el callback deja pasar. El 200 en sí necesita el sitio.*
- [~] Un `administrador edificio` recibe **403** en el detalle de una solicitud de un condominio que no tiene asignado. — *`testTheRoleAloneIsNotEnoughWhenNodeAccessSaysNo()`: con el rol puesto y `node_access('view')` en `FALSE`, el callback dice que no, y se comprueba que llegó a preguntar. Que `myapi_node_access()` responda `FALSE` para un condominio ajeno lo verificó la SPEC 125 y se reconfirma en la matriz.*
- [~] Un autenticado sin ninguno de los tres roles recibe 403; `uid 1` entra siempre. — *Primera mitad en `testTheRoleGateIsTheListingsOwn()`. `uid 1` entra por `myapi_service_requests_admin_access()`, que es la función reutilizada y ya la cubre la SPEC 125.*
- [~] Un nid de otro bundle responde 403, y un nid inexistente responde el 404 de Drupal. — *`testOnlyAServiceRequestOpensTheScreen()` fija el gate de bundle (y el `NULL`). El 404 lo da el cargador `%node` de core, no este código.*
- [x] La ruta **no** aparece en el menú de administración: solo se llega desde el listado. — *`MENU_CALLBACK` en la entrada de `hook_menu()`; estático.*

**Solo lectura — la garantía de esta spec**

- [x] La pantalla no contiene ni un `<form>`, ni un `<input>`, ni un botón, ni un enlace a la ruta de edición del nodo, ni a las de transacciones, y hay un test que lo afirma leyendo el archivo. — *`testTheScreenHasNoWriteAffordanceAtAll()`, que tokeniza el archivo, descarta los comentarios y busca once cadenas prohibidas. Verde.*
- [x] Ninguna función del archivo nuevo llama a `node_save()`, `node_delete()`, `db_insert()`, `db_update()` ni `db_delete()`. — *Las cinco están en la lista de ese mismo test.*
- [x] El listado conserva sus nueve columnas y el enlace del título sigue llevando a la edición para quien puede editar. — *`ServiceRequestsAdminPageTest` sigue verde entero, incluidos `testARowBecomesTheNineDocumentedCells()` (nueve celdas) y `testTheTitleLinksToTheNodeWhenTheReaderCannotEdit()`.*

**Reutilización — la otra garantía**

- [x] El archivo nuevo no contiene un solo `db_select()`. — *`testTheScreenOpensNoQueryOfItsOwn()`, que también descarta `db_query`.*
- [x] `git diff main -- includes/myapi.service_request_query.inc includes/myapi.service_request_detail.inc includes/myapi.service_transaction_admin.inc resources/` está vacío. — *Comprobado: diff vacío.*
- [x] Los bloques 1, 2 y 3 salen de `myapi_service_request_detail_row()`, `myapi_service_transaction_timeline_rows()` y `myapi_service_request_load_offers($nid, [])`, y ninguna de las tres cambia de firma. — *Mismo diff vacío; las tres llamadas están en `myapi_service_request_admin_detail_page()`.*
- [x] Las etiquetas de estado de solicitud, estado de oferta y tipo de importe salen de `includes/myapi.services_common.inc`; el archivo nuevo no declara ni una clave ni una etiqueta de catálogo. — *Los tres catálogos se leen con sus funciones; `testAnUnknownTransactionStatusPrintsRaw()` y `testAnUnknownOfferStatusPrintsRaw()` prueban además que un valor fuera del catálogo no desaparece.*
- [x] La pantalla entera cuesta tres consultas de bloque, un `user_load()` y el `node_load()` que hace el propio router por el `%node`. Ninguna otra. — *Lectura del archivo: tres llamadas reutilizadas, un `user_load()` guardado por `requester_uid !== NULL`, y ni un `db_select()`.*

**Contenido**

- [x] El bloque 1 muestra las trece filas documentadas; cada valor opcional que falta responde `—` y ninguna fila desaparece. — *`testTheSummaryIsTheThirteenDocumentedRowsInOrder()`, `testTheSummaryPaintsAWholeRequest()` y `testARequestMissingEverythingOptionalStillHasItsThirteenRows()`.*
- [x] Una solicitud adjudicada a un proveedor despublicado muestra `Proveedor eliminado (#id)`, y una con la oferta adjudicada despublicada, `Oferta eliminada (#id)`. — *La primera la cubre `myapi_service_requests_provider_label()` (SPEC 125, ya verde); la segunda, `testTheOfferLabelDistinguishesUnawardedFromBroken()`.*
- [x] Una solicitud `direct` muestra proveedor y `—` en oferta adjudicada, sin que ninguno de los dos se lea a través del otro. — *`testADirectRequestShowsItsProviderAndNoOffer()`.*
- [x] El bloque 2 lista las transacciones de más reciente a más antigua, con la fecha de estado sin conversión de zona horaria, y **sin** columnas Editar ni Borrar. — *`testTheTimelineHasNoEditOrDeleteCell()` (cuatro celdas) y `testTheStatusDateIsNeverConverted()`. El orden es el de la consulta reutilizada, que no cambia.*
- [x] El bloque 3 lista **todas** las ofertas publicadas de la solicitud, cualquiera que sea su estado, y marca la adjudicada. — *La lista vacía de proveedores en la llamada (estático) más `testTheAwardedOfferIsMarkedAndTheOthersAreNot()` y `testNoOfferIsMarkedWhenNothingIsAwarded()`.*
- [x] Una oferta anterior a la SPEC 100 aparece con `—` en las tres columnas de presupuesto en lugar de desaparecer. — *`testAnOfferOlderThanTheQuoteFieldsKeepsItsRow()`.*
- [~] El bloque 4 lista el adjunto y las imágenes con enlaces que un operador de los tres roles puede abrir, y que un residente autenticado no. — *`testTheAttachmentLinksNeverPointAtTheApi()` fija que el enlace es `file_create_url()` y nunca la ruta `api/v1`. Quién puede abrirlo lo decide `myapi_service_request_file_access()`, que ya existía y ya tiene sus tests; la comprobación de punta a punta está en la matriz.*
- [x] Los tres bloques vacíos muestran su mensaje de "no hay" y ninguno rompe la página. — *`testAnEmptyTimelineIsAnEmptyTable()`, `testAnEmptyOfferListIsAnEmptyTable()`, `testAnEmptyFileListIsAnEmptyTable()` y `testANodeWithNoFilesAnswersAnEmptyList()`; los tres `#empty` están en la página.*

**El caso roto**

- [~] Una solicitud publicada cuyo término de categoría fue borrado, o cuya fila de `field_requester` no existe, **abre** el detalle: aviso de advertencia, bloque 1 degradado y bloques 2, 3 y 4 completos. Nunca un 404. — *`testABrokenRequestIsDiagnosedAndNotHidden()` y `testTheDegradedSummaryFallsBackToTheNode()` cubren las dos piezas; que los otros tres bloques se pinten igual es estático (la rama `else` no los toca). El 404 que no ocurre necesita el sitio.*
- [ ] Esa misma solicitud aparece en el listado con `—`, y su enlace de ID lleva a la pantalla anterior. — *Matriz manual, paso 7.*

**Calidad**

- [~] `vendor/bin/phpunit` en verde con el gate de cobertura satisfecho para el archivo nuevo. — *Suite verde: `OK (3798 tests, 19797 assertions)`. El gate no se pudo ejecutar en local (sin `pcov` ni `xdebug`); la regla que importa —un archivo que ningún test ejecuta falla por nombre— se cumple: `ServiceRequestAdminDetailTest` requiere el `.inc` y lo ejercita con 38 casos. Falta el número de CI.*
- [x] `vendor/bin/phpstan analyse` sin errores nuevos. — *`[OK] No errors` con `--memory-limit=1G`.*
- [~] `php -l` limpio bajo PHP 7.4 en los archivos tocados; `ModuleContractTest::testNoSourceFileUsesPhp8OnlyCode()` en verde. — *`php -l` pasa en los siete archivos, pero el PHP local es **8.4.1**, así que no prueba la 7.4. El test que sí la cubre —tokeniza buscando `match`, `?->`, promoción, tipos unión y las funciones de PHP 8— está verde. Confirmación definitiva, en CI.*
- [x] Código, nombres y comentarios en inglés; los textos de pantalla en español dentro de `t()`. — *Ningún identificador en español en el archivo nuevo; las cadenas con acentos son todas argumentos de `t()`.*
- [x] `docs/service-request-admin-detail.md` existe y describe la ruta implementada. — *321 líneas: acceso con su matriz, los cuatro bloques, el caso roto, la autorización de los adjuntos, la anomalía `backend` + `proveedor`, los tests y una matriz manual de diez comprobaciones.*

---

## Decisiones tomadas y descartadas

**1. La ruta cuelga del listado y usa `%node`, no `%`.**
`admin/content/service-requests/%node` en lugar de `admin/content/service-requests/%`. El cargador de core da tres cosas gratis: el 404 de un nid inexistente, el objeto que `node_access('view', ...)` necesita, y los campos de archivo del bloque 4 sin una consulta. Con `%` habría que escribir las tres. Se descartó también colgarla de `node/%node/...` como hacen las rutas de transacciones: esta pantalla es la continuación del listado, y su breadcrumb tiene que volver ahí.

**2. El acceso es rol + `node_access('view')`, y ni una lista de condominios escrita a mano.**
Es la mitad por-nodo de la decisión 2 de la SPEC 125. El listado se acota con `->addTag('node_access')`; una pantalla de un solo registro se acota con `node_access()` sobre ese registro, que es la misma regla escrita por `myapi_building_admin_access_decision()` sobre `myapi_building_admin_node_condominium()` y no una segunda copia. Se descartó comparar `field_condominium` contra `myapi_building_admin_condominium_ids()` aquí: funcionaría hoy y derivaría el día que la regla cambie.

**3. La pantalla no tiene ni un control de edición, y eso incluye el enlace a `/edit`.**
Un enlace "Editar" no es un control de esta pantalla, pero es un camino a la escritura desde una pantalla que se declara de supervisión, y la declaración deja de ser verificable en cuanto se admite la primera excepción. El camino a la edición ya existe y está a un clic: el enlace del **título** en el listado, que la SPEC 125 hizo condicional justo para eso. Se descartó también un enlace "Editar" visible solo para quien puede editar — sería la misma excepción con una condición delante.

**4. El enlace del listado es la columna ID, no una décima columna ni el título.**
El título ya significa "abrir el nodo" y su destino depende del permiso del lector (SPEC 125, decisión 10); reapuntarlo le quitaría a `backend` el acceso de un clic al formulario donde se crean transacciones. Una décima columna "Detalle" fue la alternativa, y se descartó por dos razones: la tabla ya tiene nueve columnas y no cabe una más sin apretarla, y el ID es la identidad del registro, así que enlazarlo a la pantalla del registro es la lectura natural. Cuesta una celda y no rompe el orden documentado.

**5. La línea de tiempo reutiliza la consulta y la etiqueta de autor, pero no el constructor de filas.**
`myapi_service_transaction_timeline_table_rows()` construye seis celdas, y dos son `Editar` y `Borrar`. Darle un parámetro de modo para que las omita convertiría una función de una pantalla en una de dos, y el precio de equivocarse en ese flag es un control de escritura en una pantalla que promete no tener ninguno. Lo que se reescribe son cuatro ternarias; lo que se evita es un modo en código que borra nodos.

**6. Las ofertas se leen con el recorte vacío, y se muestran todas.**
`myapi_service_request_load_offers($nid, [])`. El segundo parámetro existe para recortar a las ofertas propias de un proveedor lector; un supervisor no es un competidor y tiene que ver la ronda entera para juzgarla. Se descartó ocultar los importes al `administrador edificio`: es uno de los tres roles de confianza del back-office, ya ve el importe adjudicado en la solicitud y ocultarle los perdedores no protege nada — la comparación es exactamente el trabajo de supervisión.

**7. Los adjuntos salen del nodo cargado y no de `myapi_service_request_load_images()`.**
Esa función construye URLs de `api/v1/...`, que exigen un Bearer que una sesión de back-office no lleva: cada enlace daría 401, y el fallo sería un enlace roto y no una excepción. Lo que la pantalla necesita es `file_create_url($uri)`, que el `hook_file_download()` de la SPEC 89 ya autoriza para los tres roles y ya acota por condominio. Se descartó añadir el `uri` a `myapi_service_request_load_images()`: cambiaría una función que sirve a dos endpoints para ahorrar un `foreach` sobre un campo que ya está en memoria.

**8. La solicitud rota es un aviso, no un 404.**
La consulta reutilizada hace INNER sobre categoría y solicitante, y el listado hace LEFT: hay solicitudes visibles en uno e ilegibles para la otra. Se descartaron las dos salidas fáciles. Escribir una consulta de detalle con todo LEFT sería la cuarta función que responde "qué es una solicitud", y la SPEC 89 ya explica por qué esa pregunta se contesta en un sitio. Dejar el 404 haría que el listado enlace al vacío precisamente en las filas rotas, que son las que existen para ser encontradas. El aviso convierte el conflicto en información: la pantalla dice que la solicitud está rota y que por eso la app no la ve.

**9. Cuatro tablas y cero CSS.**
`#theme => 'table'` cuatro veces, con encabezados `<h2>`. Se descartó una hoja nueva y se descartó ampliar `css/myapi.claims.css`: no hay fila de filtros que colocar y el tema de administración ya maquetará las tablas.

**10. Sin paginación en los tres bloques.**
Una solicitud tiene unidades de transacciones, ofertas e imágenes. Es la misma decisión que la SPEC 94 tomó para la línea de tiempo, y el día que un caso real la desmienta el paginador entra en un bloque sin tocar los otros tres.

---

## Riesgos identificados

**1. `backend` + `proveedor` en la misma cuenta: fila visible en el listado, 403 en el detalle.**
Es el riesgo 1 de la SPEC 125 con una superficie más. `myapi_node_access()` consulta `myapi_provider_role_node_decision()` sin mirar listas de exentos, así que esa cuenta ve la fila y no puede abrirla — ahora ni el nodo ni esta pantalla. *Mitigación:* documentado; la combinación es una anomalía de configuración y se resuelve quitando un rol. No se toca `myapi_node_access()`, por la decisión 5 de la SPEC 125.

**2. El aviso de solicitud rota puede leerse como un error de la pantalla.**
Un operador que llega por primera vez a una solicitud rota puede pensar que el detalle falló. *Mitigación:* el texto dice explícitamente qué falta, qué consecuencia tiene (la app no la ve) y qué hacer (reasignar la categoría o el solicitante desde el formulario del nodo). Es un mensaje de diagnóstico, no un "ha ocurrido un error".

**3. El bloque 4 depende de que el nodo cargado traiga sus campos de archivo.**
Si un día `field_attachment` o `field_images` cambian de nombre o de cardinalidad, el bloque se queda mudo sin fallar. *Mitigación:* el extractor es puro y su test lo ejercita con las dos colecciones, con una y con ninguna; un cambio de nombre lo pone en rojo. Es el mismo trato que `myapi_building_admin_field_target_id()` recibe desde la SPEC 49.

**4. `user_load()` para el nombre del solicitante es una consulta que la fila reutilizada no trae.**
Podría tentar a añadir el join a `myapi_service_request_detail_row()`, que es compartida con la API. *Mitigación:* no se hace, y el docblock lo dice: un registro no justifica cambiar una consulta que sirve a dos endpoints. Si algún día el detalle de la API necesitara el nombre, esa es su spec y no ésta.

**5. El gate de cobertura.**
La página no es ejercitable sin Drupal; los helpers sí. *Mitigación:* siete de las ocho funciones del archivo son puras, que es donde está la lógica. Si el porcentaje global cae, la respuesta correcta sigue siendo mover lógica a funciones puras y no bajar el umbral (SPEC 123).

**6. El comentario obsoleto de `myapi.service_request_files.inc` puede tener hermanos.**
La SPEC 125 metió `service_request` en `myapi_building_admin_condominium_map()` y ese archivo se quedó afirmando lo contrario. *Mitigación:* esta spec lo corrige, y el paso 8 del plan es una revisión dirigida de las demás menciones al mapa; lo que no puede hacer es garantizar que no quede ninguna en un archivo que nadie ha leído desde entonces.
