# SPEC 64 — Endpoints de listado y detalle de reclamos (`GET /api/v1/claims`, `GET /api/v1/claims/%`)

> **Estado:** Approved · **Depende de:** SPEC 55 (bundles `reclamo` y `claim_transaction` y todos sus campos), SPEC 62 (catálogo de cuatro estados), SPEC 63 (`field_reception_date` con hora), SPEC 15 (`limit=-1`), SPEC 34 (patrón de listado paginado con filtros de fecha), SPEC 31 (filtro `condominium_id` validado contra el set del usuario) · **Fecha:** 2026-08-03
> **Objetivo:** Exponer los reclamos del condominio del residente autenticado en dos endpoints de solo lectura — un listado paginado y filtrable, y un detalle por id —, devolviendo los públicos de su condominio más los privados donde él es el solicitante, con las transacciones colapsadas a ids salvo que se pidan expandidas.

Notas técnicas que fija la cabecera, porque condicionan el resto del documento:

- Es el **primer endpoint `api/v1/...` de reclamos**: hoy no existe `resources/claim.resource.inc` y `includes/myapi.claim_query.inc` sirve solo al back-office HTML (no devuelve envelope, y no se toca en este spec).
- `field_images` y `field_attachment` están en **`public://`** (verificado en `myapi.install:1349-1358`): las URLs viajan en el JSON tal cual, sin control de acceso propio.

---

## Alcance

**Dentro:**

- **`resources/claim.resource.inc`** (nuevo) — todo el CRUD-de-lectura de reclamos en un solo archivo, con `myapi_claim_dispatch($id = NULL)` enrutando por método HTTP: `GET` → listado o detalle según venga `$id`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
- **`GET /api/v1/claims`** — listado paginado de nodos `reclamo` publicados (`status = 1`), autenticado por Bearer.
- **`GET /api/v1/claims/%`** — detalle de un reclamo por `nid`, con sus transacciones **siempre expandidas**.
- **Regla de visibilidad** (una sola condición, aplicada igual en listado y detalle): el reclamo pertenece a un condominio de `myapi_condominium_related_nids($uid)` **Y** (`field_visibility = 'public'` **O** `field_requester = uid`).
- **Query params del listado**: `page`, `limit` (con `-1`), `sort`, `condominium_id`, `date_from`, `date_to`, `status`, `claim_type`, `include`. Parseo laxo (basura → se ignora en silencio, nunca 422), con **una excepción deliberada**: un `condominium_id` que sea un nid válido pero ajeno al usuario devuelve `403 condominium_access_denied`.
- **Transacciones**: `"transactions": [12, 15, 18]` (array de ints) por defecto en el listado; objetos completos cuando llega `?include=transactions` y siempre en el detalle. Orden cronológico ascendente por `field_status_date`, desempate por `nid`.
- **Imágenes y adjunto**: URLs absolutas vía `file_create_url()` sobre la `uri` de `file_managed`, tanto del reclamo como de cada transacción expandida.
- **`includes/myapi.claims_common.inc`** (nuevo) — destino de `myapi_claims_valid_status()` y `myapi_claims_valid_claim_type()`, que hoy viven en `includes/myapi.claims_admin.inc` (líneas 69 y 82). Se **mueven tal cual**, sin cambiar una línea de su cuerpo ni de su docblock: son las dos whitelists (`received`/`in_progress`/`resolved`/`closed` y `requirement`/`claim`) que el back-office ya usa y que esta API necesita idénticas. Un include neutro, sin form builders ni page callbacks, cargable desde un recurso sin arrastrar 415 líneas de UI.
- **`includes/myapi.claims_admin.inc`** (modificar) — se le quitan las dos funciones movidas y se le añade el `module_load_include()` de `myapi.claims_common`. **Cero cambio de comportamiento**: el listado `admin/content/claims` sigue validando exactamente lo mismo.
- **`tests/unit/ClaimsStatusFilterTest.php`** (modificar) — solo su `require_once`, que pasa a apuntar al include nuevo. Ningún assert cambia: el test que SPEC 62 escribió sigue cubriendo la misma función, ahora compartida con la API.
- **`includes/myapi.i18n.inc`** (modificar) — una clave nueva, `claim_not_found`, en `es` y `en`. `condominium_access_denied`, `method_not_allowed`, `missing_authorization` e `invalid_token` ya existen y se reutilizan.
- **`myapi.module`** (modificar) — dos rutas nuevas en `hook_menu()`: `api/v1/claims` y `api/v1/claims/%`. Sin lógica de negocio, solo registro.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.claims_common.inc` y `files[] = resources/claim.resource.inc`. Todos los `includes/*.inc` del módulo están listados ahí; el nuevo no es la excepción.
- **`tests/unit/ClaimListFilterTest.php`** (nuevo) — cobertura de los helpers puros **propios** del recurso: rango de fechas e `include`. `status` y `claim_type` **no** se re-testean aquí: los cubre `ClaimsStatusFilterTest.php` desde SPEC 62, sobre las mismas funciones.
- **`docs/claim.md`** (nuevo) — ambos endpoints según la plantilla de `CLAUDE.md`, incluida la nota de que las URLs de archivos son públicas.
- `drush cc all` al final, para tomar las rutas nuevas.

**Fuera de alcance (para specs futuros):**

- **Cualquier endpoint de escritura**: crear un reclamo desde la app, editarlo, cerrarlo o añadirle una transacción. Este spec es solo lectura.
- **Mover `field_images` / `field_attachment` a `private://`** y el endpoint autenticado de descarga que eso exigiría (`GET /api/v1/claims/%/files/%`), más la migración de los archivos ya subidos. Es el spec siguiente si se decide cerrar ese acceso; hoy las URLs son públicas y este spec lo documenta como riesgo aceptado, no lo corrige.
- **Notificación push/inbox al residente** cuando cambia el estado de su reclamo (patrón de SPEC 48).
- **Filtrar el listado por hora** — `date_from`/`date_to` comparan por día, igual que el back-office (SPEC 63).
- **Filtros multivalor** (`?status=received,in_progress`) y filtro por solicitante o por texto libre.
- **Tocar `includes/myapi.claim_query.inc`** ni el comportamiento del listado HTML `admin/content/claims` (SPEC 56). De `includes/myapi.claims_admin.inc` solo salen dos funciones a un include compartido; ni su query, ni sus filtros, ni su tabla, ni su formulario cambian.
- **Endpoint de detalle de una transacción suelta** (`GET /api/v1/claim-transactions/%`): las transacciones solo viajan dentro de su reclamo.
- **Exponer el autor de la transacción** (el admin que registró el cambio): información interna del back-office.

Dos casos límite decididos dentro de este alcance:

1. **Usuario sin ningún condominio** (sin vivienda asociada): recibe `200` con `claims: []` y `total: 0`, no un 403. Es coherente con `GET /api/v1/bulletins`, que tampoco convierte "no tienes nada" en un error.
2. **`condominium_id` no numérico o basura** (`?condominium_id=abc`) → se ignora en silencio, sin filtro. El `403` es solo para un nid válido y ajeno.

---

## Modelo de datos

**No hay datos persistentes nuevos.** No se crean campos, tablas ni bundles: se leen los que SPEC 55 ya creó (`reclamo` y `claim_transaction`) más `file_managed` y el `node.title` del condominio. Lo único que se añade al estado del módulo es una clave del catálogo i18n.

### Columnas leídas

| Dato | Origen |
|---|---|
| `id`, `subject`, `created` | `node.nid`, `node.title`, `node.created` |
| `description` | `field_data_field_description.field_description_value` |
| `status` | `field_data_field_status.field_status_value` |
| `claim_type` | `field_data_field_claim_type.field_claim_type_value` |
| `reception_date` | `field_data_field_reception_date.field_reception_date_value` |
| `visibility` | `field_data_field_visibility.field_visibility_value` |
| `condominium_id` / `condominium_name` | `field_condominium_target_id` + `node.title` del condominio (LEFT JOIN con alias) |
| `requester_id` | `field_data_field_requester.field_requester_target_id` |
| `images[]` | `field_data_field_images.field_images_fid` (ordenado por `delta`) → `file_managed` |
| `attachment` | `field_data_field_attachment.field_attachment_fid` → `file_managed` |

Todos los joins son **LEFT**: un reclamo al que le falte un campo opcional-en-la-práctica sigue apareciendo con `null` en esa clave, en vez de desaparecer del listado (mismo criterio que `myapi_claims_list_rows()` y que el calendario de reservas). Las dos excepciones son los `innerJoin` de `field_condominium` y `field_visibility`, que forman parte de la condición de acceso.

### Condición de visibilidad (idéntica en listado y detalle)

```sql
field_condominium_target_id IN (:condominios_del_usuario)
AND ( field_visibility_value = 'public' OR field_requester_target_id = :uid )
```

- Si `myapi_condominium_related_nids($uid)` devuelve vacío, la consulta no se ejecuta: se responde `200` con `claims: []` y `total: 0`.
- El `uid` nativo del nodo (`node.uid`) **no** aparece en la condición: el acceso lo decide solo `field_requester`.
- El filtro de condominio se aplica **siempre primero**, incluso a los reclamos propios.

### Query params del listado

Parseo laxo — valor inválido cae al default en silencio, sin `422` — con la única excepción marcada:

| Param | Default | Regla |
|---|---|---|
| `page` | `1` | 1-based; valor no-entero-positivo → `1`. |
| `limit` | `20` | clamp `[1,50]`; `-1` = sin paginar y fuerza `page=1` (SPEC 15). |
| `sort` | `desc` | `asc`/`desc` sobre `field_reception_date_value`; desempate por `n.nid` en la misma dirección. |
| `condominium_id` | — | Entero positivo. Si no lo es → se ignora. Si lo es pero **no** está en el set del usuario → **`403 condominium_access_denied`**. |
| `date_from` / `date_to` | — | `YYYY-MM-DD`, validado con `checkdate()`; cotas **inclusivas** sobre `SUBSTR(field_reception_date_value, 1, 10)`. Rango invertido (`from > to`) descarta el filtro entero. Con una cota activa, los reclamos sin fecha quedan fuera. |
| `status` | — | Uno de `received`/`in_progress`/`resolved`/`closed`. Otro valor → sin filtro. |
| `claim_type` | — | `requirement` o `claim`. Otro valor → sin filtro. |
| `include` | — | El valor exacto `transactions` expande las transacciones. Cualquier otro valor → colapsadas. |

El `SUBSTR(...,1,10)` de las fechas es deliberado: SPEC 63 le puso hora a `field_reception_date`, y comparar contra el valor completo dejaría fuera todo lo recibido en la fecha de `date_to` después de medianoche.

`status` y `claim_type` **no** se validan con código nuevo: los normalizan `myapi_claims_valid_status()` y `myapi_claims_valid_claim_type()`, las mismas funciones que ya usa el back-office, movidas a `includes/myapi.claims_common.inc`. Un `?status=duplicated` cae en "sin filtro" por la misma línea de código en los dos consumidores, y la próxima vez que cambie el catálogo hay un solo sitio que tocar.

### Item de reclamo — `data.claims[]` y `data.claim`

```json
{
  "id": 140,
  "subject": "Filtración en el techo del pasillo",
  "description": "Se ve una mancha de humedad que crece desde el lunes.",
  "status": "in_progress",
  "claim_type": "claim",
  "visibility": "public",
  "reception_date": "2026-08-01T14:30:00",
  "created": "2026-08-01T14:31:12",
  "condominium_id": 7,
  "condominium_name": "Residencias El Parque",
  "requester_id": 34,
  "images": [
    { "id": 512, "url": "https://.../sites/default/files/mancha.jpg", "filename": "mancha.jpg" }
  ],
  "attachment": { "id": 513, "url": "https://.../sites/default/files/informe.pdf", "filename": "informe.pdf" },
  "transactions": [12, 15, 18]
}
```

- `id`, `condominium_id`, `requester_id` y los `id` de archivo son **int** cuando hay fila, `null` cuando no la hay.
- `reception_date` es el valor almacenado con la `T` puesta (`str_replace(' ', 'T', ...)`), **sin conversión de zona horaria**: el campo se creó con `tz_handling = 'none'` y convertirlo desplazaría la hora que el operador escribió. `null` cuando no hay fila.
- `created` se formatea con `format_date($created, 'custom', 'Y-m-d\TH:i:s')` (zona del sitio), idéntico a SPEC 34.
- `images` es siempre un array (vacío si no hay ninguna), ordenado por `delta`. `attachment` es un objeto o `null` (cardinalidad 1).
- `url` se construye con `file_create_url($file->uri)` sobre la `uri` ya traída del join a `file_managed`, sin un `file_load()` por fila.
- `transactions` es un **array de ints** por defecto; el mismo nombre de clave pasa a contener objetos cuando se expande.

### Item de transacción expandida — `transactions[]`

```json
{
  "id": 15,
  "status": "in_progress",
  "status_date": "2026-08-02T09:00:00",
  "comment": "Se envió al plomero a revisar.",
  "created": "2026-08-02T09:02:44",
  "images": [],
  "attachment": null
}
```

- Mismas reglas de formato que el reclamo. **No** viaja el autor de la transacción.
- Orden **ascendente** por `field_status_date_value`, desempate por `nid` ascendente: es una línea de tiempo.

### Envelope y paginación

```json
{ "success": true, "data": { "claims": [ ... ], "pagination": { "total": 0, "page": 1, "limit": 20, "total_pages": 0 } } }
```

- El detalle devuelve `{ "success": true, "data": { "claim": { ... } } }`, sin bloque `pagination`.
- `total_pages` = `0` cuando `total` es `0`; `1` cuando `limit=-1` y `total > 0`. Página fuera de rango → `200` con `claims: []`.

### Clave i18n nueva

| Clave | `es` | `en` |
|---|---|---|
| `claim_not_found` | `Reclamo no encontrado.` | `Claim not found.` |

### Estrategia de consulta (evita el N+1)

Tres consultas por request, no una por fila:

1. La query principal (con paginación) devuelve una fila por reclamo, con todos los campos de cardinalidad 1 ya joineados.
2. Una segunda query trae **todas** las imágenes de los nids de la página en un solo `IN`, agrupadas en PHP por `entity_id`.
3. Una tercera trae las transacciones de esos nids en un solo `IN` (`field_claim_target_id IN (...)`): solo `nid` cuando van colapsadas, la fila completa cuando van expandidas — más su propia pasada de imágenes/adjunto cuando se expanden.

---

## Plan de implementación

1. **`includes/myapi.claims_common.inc` — extraer los validadores compartidos.** Crear el include con su docblock `@file` (por qué existe: dos consumidores, back-office y API, sobre la misma whitelist) y mover ahí `myapi_claims_valid_status()` y `myapi_claims_valid_claim_type()` **sin tocar su cuerpo**. Registrarlo en `myapi.info` con `files[] = includes/myapi.claims_common.inc`. En `includes/myapi.claims_admin.inc`, borrarlas y añadir el `module_load_include()` del include nuevo. En `tests/unit/ClaimsStatusFilterTest.php`, cambiar el `require_once` a la ruta nueva. Va **primero** porque es el único paso que toca código existente: si algo se rompe, se rompe con la suite verde de SPEC 62 como red, antes de que exista una línea del recurso. *Verificación: `php -l` de los tres archivos; `ClaimsStatusFilterTest` pasa sin que cambie ningún assert; `admin/content/claims` sigue filtrando igual.*

2. **`resources/claim.resource.inc` — esqueleto.** Crear el archivo con el docblock `@file` (qué expone, la nota de que lee `field_data_*` directamente, y la de que no lleva `->addTag('node_access')`) y el bloque de `module_load_include()`: `request`, `response`, `i18n`, `token`, `auth`, `unit_access`, `claims_common`. Registrarlo en `myapi.info` con `files[] = resources/claim.resource.inc`. *Verificación: `php -l`; `drush cc all` no rompe nada. El archivo carga sin ruta que lo llame todavía.*

3. **`includes/myapi.i18n.inc` — clave `claim_not_found`.** Una línea en el bloque `en` y otra en el `es`, junto a las de reservas. *Verificación: `myapi_t('claim_not_found')` devuelve el texto en ambos idiomas.*

4. **Helpers de parseo propios del recurso.** `myapi_claim_parse_date_range()` y `myapi_claim_valid_date()` (copias del idiom de SPEC 34: `checkdate()`, descarte del rango invertido, ignorar en silencio) y `myapi_claim_include_transactions()`. Funciones puras, sin acceso a base de datos. **No** se escriben validadores de `status` ni de `claim_type`: se llama a los del paso 1. *Verificación: `php -l`; se cubren en el paso 15.*

5. **`myapi_claim_parse_condominium_id($allowed)`** — el único parseo que puede cortar la request: no-entero-positivo → `NULL` (sin filtro); entero positivo fuera de `$allowed` → `myapi_error('condominium_access_denied', 403)`. Separado del paso 4 justamente porque no es puro. *Verificación: `php -l`.*

6. **`myapi_claim_base_query($uid, $condos, $filters)`** — construye el `db_select('node')` con `type = 'reclamo'`, `status = 1`, el `innerJoin` a `field_data_field_condominium` con el `IN` de condominios, el `innerJoin` a `field_data_field_visibility` y el `leftJoin` a `field_data_field_requester`, más el grupo `db_or()` de la condición de visibilidad y los filtros opcionales de fecha / `status` / `claim_type`. Una sola función, consumida por el count, el fetch y el detalle — sin duplicar la regla de acceso en tres sitios (Regla 3 de `CLAUDE.md`). *Verificación: `php -l`.*

7. **`myapi_claim_count()` y `myapi_claim_fetch()`.** El count es `myapi_claim_base_query(...)->countQuery()`. El fetch añade los `leftJoin` de los campos de cardinalidad 1 (`field_description`, `field_status`, `field_claim_type`, `field_reception_date`, `field_attachment` + `file_managed`), el `leftJoin` con alias a `node` para `condominium_name`, el `orderBy` doble y el `->range()` salvo con `limit = -1`. *Verificación: `php -l`; consultada a mano contra el sitio, devuelve las filas esperadas.*

8. **`myapi_claim_load_images(array $nids)`** — una sola query a `field_data_field_images` (`entity_type = 'node'`, `deleted = 0`, `entity_id IN (:nids)`) joineada a `file_managed`, ordenada por `entity_id, delta`, agrupada en PHP en `[nid => [ {id, url, filename}, ... ]]`. Sirve tanto para los reclamos como para las transacciones: recibe nids, no le importa el bundle. *Verificación: `php -l`; con dos reclamos de tres imágenes cada uno se ejecuta **una** query, no seis.*

9. **`myapi_claim_load_transactions(array $nids, $expand)`** — una query a `field_data_field_claim` para el mapa `claim_nid => [transaction_nid, ...]`, ordenada por `field_status_date` ascendente con desempate por `nid`. Con `$expand = FALSE` devuelve ahí mismo los arrays de ints. Con `$expand = TRUE` añade los `leftJoin` de `field_status`, `field_status_date`, `field_comment` y `field_attachment` + `file_managed`, y llama a `myapi_claim_load_images()` con los nids de transacción. *Verificación: `php -l`; el orden es cronológico y estable.*

10. **`myapi_claim_build_item($row, $images, $transactions)`** — el serializador único: casts a `int`, `reception_date` vía `str_replace(' ', 'T', ...)` sin conversión de zona, `created` vía `format_date()`, `images` siempre array, `attachment` objeto o `null`. Lo usan el listado y el detalle, así que las dos respuestas no pueden divergir. *Verificación: `php -l`.*

11. **`myapi_claim_list()`.** Orquestación: `myapi_auth_require_access_token()` → `$uid`; `myapi_condominium_related_nids($uid)` → si viene vacío, `myapi_respond()` con `claims: []` / `total: 0` y termina; parseo de todos los params (pasos 1, 4 y 5); `myapi_claim_count()`; `myapi_claim_fetch()`; las dos cargas por lote (pasos 8 y 9); `array_map()` del serializador; `myapi_respond()` con el envelope y su `pagination`. *Verificación: `curl` con Bearer válido devuelve el listado; sin token, `401`.*

12. **`myapi_claim_detail($id)`.** Misma base: token, condominios, y `myapi_claim_base_query()` con `->condition('n.nid', $id)` y sin paginación. Cero filas → `myapi_error('claim_not_found', 404)`, indistinguible entre "no existe", "de otro condominio" y "privado ajeno". Con fila, las mismas cargas por lote sobre un solo nid, con las transacciones **siempre expandidas**, y `myapi_respond(['claim' => $item])`. *Verificación: un reclamo propio devuelve `200` con sus transacciones; el de otro condominio, `404`.*

13. **`myapi_claim_dispatch($id = NULL)`** — `GET` con `$id === NULL` → `myapi_claim_list()`; `GET` con `$id` → `myapi_claim_detail($id)`; cualquier otro método → `myapi_error('method_not_allowed', 405)`. *Verificación: `php -l`.*

14. **`myapi.module` — las dos rutas.** En `hook_menu()`: `api/v1/claims` (`page arguments` vacío) y `api/v1/claims/%` (`page arguments [3]`), ambas con `page callback myapi_claim_dispatch`, `access callback TRUE`, `file resources/claim.resource.inc`, `type MENU_CALLBACK`. Mismo shape que `api/v1/payments` / `api/v1/payments/%`. *Verificación: tras `drush cc all`, las dos rutas responden.*

15. **`tests/unit/ClaimListFilterTest.php`.** Cobertura de los helpers puros del paso 4: rango de fechas válido, malformado, inexistente (`2026-02-30`) e invertido; e `include` con `transactions` / otro valor / ausente. Mismo estilo que `ReservationListFilterTest.php`. **No** re-testea `status` ni `claim_type`: son las funciones del paso 1, ya cubiertas por `ClaimsStatusFilterTest.php` desde SPEC 62 — duplicar los asserts crearía dos tests que hay que actualizar a la vez, el mismo problema que este spec acaba de quitar del código. *Verificación: la suite completa sigue en verde, `ClaimsStatusFilterTest` incluido.*

16. **`docs/claim.md`.** Los dos endpoints con la plantilla de `CLAUDE.md`: método, auth, tabla completa de query params, ejemplos de respuesta colapsada y expandida, tabla de errores (`401`, `403`, `404`, `405`), y dos notas — que se leen las tablas `field_data_*` directamente (mismo caveat que `payment.md`) y que **las URLs de imágenes y adjuntos son públicas y no requieren token**. *Verificación: lectura contra la implementación.*

17. **`drush cc all` + matriz manual.** Recorrer los criterios de aceptación con dos usuarios (uno con vivienda en el condominio, otro sin ninguna) y al menos un reclamo público, uno privado propio y uno privado ajeno. Incluir una pasada por `admin/content/claims` para confirmar que el paso 1 no lo alteró.

**Nota de orden:** los pasos 6 a 10 son código que todavía no responde a ninguna ruta — el archivo carga y la suite pasa, pero no hay endpoint vivo hasta el paso 14. Partirlo de otra forma exigiría registrar la ruta antes de que el listado exista.

---

## Criterios de aceptación

**Autenticación y método**

- [ ] `GET /api/v1/claims` sin cabecera `Authorization` → `401 missing_authorization`.
- [ ] Con un token inválido o expirado → `401 invalid_token`.
- [ ] `POST`, `PUT` o `DELETE` sobre `api/v1/claims` o `api/v1/claims/%` → `405 method_not_allowed`.

**Visibilidad y acceso**

- [ ] Un reclamo **público** de un condominio del usuario aparece en su listado, sea quien sea el solicitante.
- [ ] Un reclamo **privado donde el usuario es `field_requester`** aparece en su listado.
- [ ] Un reclamo **privado de otro solicitante**, mismo condominio, **no** aparece — ni siquiera con su `id` en el detalle (`404`).
- [ ] Un reclamo de un condominio donde el usuario no tiene vivienda **no** aparece, sea público o privado.
- [ ] Un reclamo privado donde el usuario es el solicitante pero cuyo condominio ya no está en su set **no** aparece (el filtro de condominio manda siempre).
- [ ] Un reclamo cargado por un admin donde el usuario es autor del nodo (`node.uid`) pero **no** es `field_requester`, y es privado, **no** aparece.
- [ ] Un usuario sin ninguna vivienda recibe `200` con `claims: []`, `total: 0`, `total_pages: 0` — **no** un `403`.
- [ ] Un reclamo no publicado (`status = 0`) no aparece nunca, ni en el listado ni en el detalle.

**Filtros y paginación del listado**

- [ ] `?condominium_id=` con un nid del set del usuario acota el listado a ese condominio.
- [ ] `?condominium_id=` con un nid válido **ajeno** al usuario → `403 condominium_access_denied`.
- [ ] `?condominium_id=abc` (o `0`, o negativo) se ignora en silencio: devuelve todos los condominios del usuario, sin `422` ni `403`.
- [ ] `?status=in_progress` filtra; `?status=duplicated` o `?status=inventado` devuelve todos los estados, sin `422`.
- [ ] `?claim_type=claim` filtra; cualquier otro valor devuelve ambos tipos.
- [ ] `?date_from` / `?date_to` filtran inclusivamente **por día**: un reclamo recibido el día de `date_to` a las 14:30 **sí** aparece.
- [ ] Un rango invertido (`date_from > date_to`) descarta el filtro entero; una fecha malformada o inexistente (`2026-02-30`) se ignora en silencio.
- [ ] Con al menos una cota de fecha activa, los reclamos sin `field_reception_date` quedan fuera.
- [ ] `?sort=asc` y `?sort=desc` ordenan por `field_reception_date`; los empates se resuelven por `nid` en la misma dirección y el orden es estable entre páginas.
- [ ] `?limit=-1` devuelve todos los reclamos que casan en una sola página con `total_pages: 1`; `total = 0` → `total_pages: 0`; una página más allá de la última → `200` con `claims: []`.

**Transacciones**

- [ ] Sin `?include`, cada item trae `"transactions"` como array de **ints**.
- [ ] Con `?include=transactions`, cada item trae `"transactions"` como array de **objetos** con las siete claves documentadas.
- [ ] `?include=cualquier-otra-cosa` devuelve las transacciones colapsadas, sin `422`.
- [ ] Las transacciones vienen en orden **ascendente** por `field_status_date`, con desempate por `nid` ascendente.
- [ ] Un reclamo sin ninguna transacción devuelve `"transactions": []` en ambos modos.
- [ ] Ningún objeto de transacción incluye el autor (`uid` o username) del cambio de estado.
- [ ] Un listado de 20 reclamos expandidos ejecuta un número **constante** de queries (no una por reclamo ni una por transacción).

**Archivos**

- [ ] `images` es siempre un array (vacío cuando no hay ninguna), ordenado por `delta`, con `id` (int), `url` absoluta y `filename`.
- [ ] `attachment` es un objeto con esas mismas tres claves, o `null` cuando el reclamo no tiene adjunto.
- [ ] Las `url` devueltas abren el archivo **sin** cabecera `Authorization` — es el comportamiento esperado hoy, documentado en `docs/claim.md`, no un fallo.
- [ ] Las transacciones expandidas exponen sus propias `images` / `attachment` con las mismas reglas.

**Detalle**

- [ ] `GET /api/v1/claims/{id}` de un reclamo visible devuelve `200` con `data.claim` y **sin** bloque `pagination`.
- [ ] Sus transacciones vienen **siempre expandidas**, sin necesidad de `?include`.
- [ ] Un id inexistente, uno de otro condominio y un privado ajeno devuelven los tres exactamente `404 claim_not_found`, indistinguibles entre sí.
- [ ] `GET /api/v1/claims/abc` (id no numérico) devuelve `404 claim_not_found`, no un error de PHP.
- [ ] El item del detalle tiene exactamente las mismas claves y tipos que el del listado (mismo serializador).

**Formatos**

- [ ] `reception_date` y `status_date` salen como `Y-m-dTH:i:s` con la hora **tal cual está almacenada**, sin desplazamiento de zona horaria.
- [ ] Un reclamo anterior a SPEC 63 sale con `T00:00:00`, sin error.
- [ ] `created` sale como `Y-m-dTH:i:s` en la zona horaria del sitio.
- [ ] `id`, `condominium_id`, `requester_id` y los `id` de archivo son enteros JSON, no strings; y `null` cuando no hay fila.

**No regresión e infra**

- [ ] `includes/myapi.claim_query.inc` no aparece en el diff.
- [ ] En `includes/myapi.claims_admin.inc` el diff es **solo** la eliminación de las dos funciones movidas más el `module_load_include()` nuevo: su query, sus filtros, su tabla y su formulario quedan intactos.
- [ ] `admin/content/claims` sigue filtrando igual: `?status=in_progress` acota, `?status=duplicated` devuelve todo, `?claim_type=claim` acota.
- [ ] `myapi_claims_valid_status()` y `myapi_claims_valid_claim_type()` existen **una sola vez** en el repo, en `includes/myapi.claims_common.inc` (`grep -rn` devuelve una definición de cada una).
- [ ] No existe ninguna whitelist de estados ni de tipos de reclamo escrita dentro de `resources/claim.resource.inc`.
- [ ] `ClaimsStatusFilterTest.php` pasa con el `require_once` nuevo y **sin ningún assert modificado**.
- [ ] `myapi.install` no aparece en el diff: no hay campos, updates ni bundles nuevos.
- [ ] Ningún endpoint `api/v1/...` existente cambia su respuesta.
- [ ] `myapi.info` lista **los dos** archivos nuevos: `includes/myapi.claims_common.inc` y `resources/claim.resource.inc`.
- [ ] La única clave i18n añadida es `claim_not_found`, presente en `es` y en `en`.
- [ ] `docs/claim.md` existe y casa con el contrato implementado, incluida la nota de URLs públicas.
- [ ] La suite de tests sigue en verde, con `ClaimListFilterTest` incluido.
- [ ] `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Partición del trabajo | Un solo spec con listado y detalle | Dos specs, como SPEC 34 / SPEC 38 hicieron con reservas | Comparten archivo de recurso, regla de acceso y serializador. Partirlos obligaría a escribir dos veces la condición de visibilidad, o a que el segundo spec refactorice al primero. En reservas se separaron porque el detalle llegó meses después, no por diseño. |
| Ruta del detalle | `GET /api/v1/claims/%` (plural) | `GET /api/v1/claim/:id` (singular, como venía en el enunciado) | `CLAUDE.md` fija plural para colección e item, y todo el módulo lo cumple (`api/v1/payments/%`, `api/v1/areas/%`). Un singular suelto rompería el patrón para el cliente Flutter. |
| Quién decide el acceso a un reclamo privado | Solo `field_requester = uid` | Añadir `OR node.uid = uid` (el autor del nodo) | `field_requester` es el campo semántico de solicitante; `node.uid` es quien tecleó el formulario. Con el `OR`, un administrador que carga un reclamo privado de un residente lo vería después en su propia app. Una sola condición, además, es auditable de un vistazo. |
| Reclamos propios de un condominio que el usuario ya dejó | No se muestran: el filtro de condominio se aplica primero a todo | `(condominio del usuario Y público) OR (field_requester = uid, sin importar el condominio)` | La regla se lee de una vez y no deja al residente ver actividad de un edificio del que ya no forma parte. El costo es real y aceptado: al mudarse pierde el historial de sus reclamos anteriores. |
| Selección de condominio en el listado | Todos los condominios del usuario, con `?condominium_id` como filtro opcional | `condominium_id` obligatorio, o ruta anidada `api/v1/condominiums/%/claims` | Un usuario puede tener viviendas en más de un condominio y la app no siempre sabe cuál mostrar primero. Es el patrón que SPEC 31 ya validó en bulletins. |
| `condominium_id` ajeno | `403 condominium_access_denied` | Ignorarlo en silencio, como el resto de los params | Decisión explícita del usuario. Pedir un condominio concreto es una afirmación de intención, no un parámetro de conveniencia: devolver silenciosamente los reclamos de *otro* condominio sería una respuesta engañosa. La clave i18n ya existe. |
| `condominium_id` no numérico | Se ignora en silencio | También `403` | Un `?condominium_id=abc` es un bug del cliente, no un intento de acceso: no hay ningún condominio al que se le esté negando nada. Convertir un typo en un error de permisos confundiría el diagnóstico. |
| Usuario sin ninguna vivienda | `200` con `claims: []` | `403 unit_access_denied` | "No tienes nada" no es "no puedes". Mismo criterio que `GET /api/v1/bulletins`. Evita además que la app tenga que tratar un caso vacío legítimo como un error de sesión. |
| Expansión de transacciones | `?include=transactions`, colapsadas por defecto | `?with_transactions=1` o `?expand=transactions` | `include` es el nombre convencional y deja lugar a `include=transactions,images` si algún día hace falta, sin inventar un parámetro nuevo por cada cosa expandible. |
| Transacciones en el detalle | Siempre expandidas, sin necesidad del parámetro | Exigir `?include=transactions` también ahí, por simetría | El detalle existe precisamente para ver la línea de tiempo; devolver ids ahí obligaría a toda app a mandar siempre el parámetro. La asimetría es deliberada y está documentada. |
| Nombre de la clave | `transactions` en los dos modos | `transaction_ids` colapsado y `transactions` expandido | Dos nombres para el mismo dato obligarían al cliente a mirar cuál llegó. Con un solo nombre, el tipo del contenido lo determina el request que él mismo hizo. |
| Autor de la transacción | No viaja al residente | Incluir `uid` + nombre del administrador que registró el cambio | Es información interna del back-office. El residente necesita saber qué pasó y cuándo, no quién lo tecleó. |
| `requester_name` | No se expone | Devolver también el nombre o username del solicitante | Ningún recurso `api/v1/...` expone nombres de usuario hoy (SPEC 34 devuelve solo `requester_id`). En un reclamo público significaría publicar el nombre del vecino que reclamó a todo el condominio: una decisión de privacidad que merece pedirse explícitamente, no colarse en un endpoint de listado. |
| Errores del detalle | Los tres casos colapsan en `404 claim_not_found` | `403 claim_access_denied`, o distinguir `404` de `403` | Distinguirlos le confirmaría a cualquiera, probando ids, que existe un reclamo privado que no puede ver. El `404` uniforme no filtra ni la existencia. Se aparta a propósito del `403 unit_access_denied` de payments/reservas: ahí el id es el de una vivienda, un dato que el usuario ya conoce. |
| Acceso a imágenes y adjuntos | Devolver las URLs públicas de `file_create_url()` y documentar el riesgo | Migrar `field_images`/`field_attachment` a `private://` con endpoint autenticado de descarga | Los campos se crearon sin `uri_scheme` (SPEC 55), así que hoy ya están en `public://`: el archivo es descargable con o sin este endpoint. Cerrarlo exige migrar los archivos existentes, un endpoint de streaming y control de acceso por fid — un spec propio, no un añadido a este. |
| Filtro de fechas | Comparar por día con `SUBSTR(...,1,10)` | Comparar el valor completo, ahora que SPEC 63 le puso hora al campo | Con `date_to` inclusivo, comparar el valor completo dejaría fuera todo lo recibido ese día después de medianoche. Mismo razonamiento y mismo `SUBSTR` que el back-office. |
| Formato de `reception_date` / `status_date` | El valor almacenado con una `T`, sin conversión de zona | `format_date(strtotime(...), ...)`, como se hace con `created` | El campo se creó con `tz_handling = 'none'`: lo almacenado es una hora local naive, no un instante UTC. Pasarlo por `strtotime()` + `format_date()` la desplazaría según la zona del servidor y devolvería una hora que nadie escribió. `created`, en cambio, sí es un timestamp real y por eso sí se formatea. |
| Reglas de parseo del resto de params | Laxas: valor inválido → default en silencio, sin `422` | Validar estrictamente y devolver `422` | Es el idiom ya establecido en payments, reservas y bulletins. Un cliente con un marcador viejo (`?status=duplicated`, retirado por SPEC 62) recibe el listado completo en vez de un error. |
| Estrategia de consulta | Tres queries por request, con carga por lote de imágenes y transacciones | Un `node_load_multiple()` de los reclamos y sus transacciones | `node_load_multiple()` traería el objeto entero con todos sus campos y dispararía los hooks de carga por cada nodo. Leer `field_data_*` directamente es lo que ya hacen payments, reservas y bulletins, con el mismo caveat de esquema documentado. |
| Validación de `status` y `claim_type` | Extraer `myapi_claims_valid_status()` y `myapi_claims_valid_claim_type()` a `includes/myapi.claims_common.inc` y llamarlas desde los dos consumidores | (a) Reescribirlas en el recurso; (b) `module_load_include()` de `includes/myapi.claims_admin.inc` desde el recurso; (c) leer las whitelists de `field_info_field()` | (a) es exactamente lo que la Regla 3 de `CLAUDE.md` prohíbe, y ya se pagó una vez: SPEC 62 tuvo que corregir la whitelist a mano porque no se leía del campo. (b) haría que cada request de la API cargue 415 líneas de form builders y `page callback`, e invertiría la dependencia (recurso → back-office). (c) la descartó SPEC 56 a propósito: los valores almacenados son parte del modelo de datos y la validación no debe cambiar en silencio si alguien edita los `allowed_values` desde la UI. |
| Ubicación del resto de los helpers | Todos dentro de `resources/claim.resource.inc` | Un `includes/myapi.claim_api_query.inc` compartido | Nadie más los usa: `includes/` es para lógica compartida entre recursos, y hoy este es el único que lee reclamos por API. Si llega un segundo consumidor, se extraen entonces. El include nuevo del paso 1 es distinto: nace ya con dos consumidores reales. |
| Cobertura de `status` / `claim_type` en los tests | Se dejan en `ClaimsStatusFilterTest.php`, sin duplicarlos en `ClaimListFilterTest.php` | Repetir los asserts en el test nuevo, para que cada endpoint tenga los suyos | Son la misma función: dos tests sobre ella obligarían a actualizar los dos cuando cambie el catálogo — el mismo problema de sincronización manual que este spec acaba de quitar del código de producción. |
| Reutilizar `includes/myapi.claim_query.inc` | No — se deja intacto | Generalizarlo para que sirva al back-office y a la API | Devuelve una query con `PagerDefault` y `->addTag('node_access')`, pensada para un operador con rol, no para un residente con token. Adaptarlo a los dos consumidores lo volvería más frágil que tener una query propia por caso de uso. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Las imágenes y adjuntos son descargables sin token.** `field_images` y `field_attachment` están en `public://` (SPEC 55 los creó sin `uri_scheme`), así que cualquiera con la URL abre el archivo, y un reclamo **privado** puede llevar adjunta una foto sensible. Este endpoint no crea el problema, pero sí reparte las URLs a más gente que antes. | Documentado explícitamente en `docs/claim.md` y en las decisiones. Las URLs incluyen el nombre de archivo original y no son enumerables, pero eso es ofuscación, no control de acceso. Cerrarlo de verdad (migración a `private://` + endpoint de descarga autenticado) queda registrado como el spec siguiente, no como un pendiente difuso. |
| **La query no lleva `->addTag('node_access')`.** Es deliberado — el control lo hace la condición de visibilidad propia, y el residente no tiene rol de back-office —, pero significa que si un spec futuro añade reglas de `hook_node_access` sobre `reclamo`, este endpoint **no** las heredará. | Anotado en el docblock `@file` del recurso. Es el mismo criterio que ya siguen payments, reservas y bulletins: la API tiene su propia regla de acceso, explícita y testeada, en vez de depender del sistema de permisos de nodos de Drupal. |
| **Un reclamo sin fila en `field_visibility`** (importado a mano, o creado antes de que el campo fuera requerido) desaparece del listado por el `innerJoin`. | Es el comportamiento *fail-safe* correcto: sin visibilidad declarada, no se asume `public`. El campo es requerido desde SPEC 55, así que solo puede ocurrir con datos cargados por fuera del formulario. |
| **`?limit=-1` con `?include=transactions`** en un condominio con cientos de reclamos: el `IN` de transacciones y el de imágenes crecen con el resultado completo y la respuesta puede pesar varios MB. | El número de queries sigue siendo constante (el riesgo es el tamaño de la respuesta, no un N+1). `limit=-1` es una decisión consciente del cliente, heredada de SPEC 15, y el resto de listados del módulo asume lo mismo. Si se vuelve un problema real, se acota entonces con un tope propio. |
| **Leer `field_data_*` directamente** se rompe si alguien reconstruye el esquema de campos desde la UI (renombrar un campo, borrarlo y recrearlo). | Mismo supuesto y mismo caveat que `payment.md` y `reservation.md`, documentado en `docs/claim.md`. |
| **Un residente que pierde su vivienda pierde el acceso a sus propios reclamos anteriores**, incluidos los privados que él mismo presentó. | Decisión consciente (ver Decisiones), no un efecto secundario. Documentado en `docs/claim.md` para que soporte sepa responderlo. |
| **`?status=duplicated`** guardado en un marcador de la app tras SPEC 62 devuelve el listado completo en vez de un error. | Es el idiom laxo del módulo, idéntico al que SPEC 62 ya dejó documentado para el filtro del back-office — y ahora, literalmente, la misma función. |
| **El paso 1 toca código de producción que hoy funciona** (`includes/myapi.claims_admin.inc`, el listado del back-office) por un motivo que no es una funcionalidad pedida. | Es un movimiento de dos funciones sin cambiar una línea de su cuerpo, va **primero** en el plan (antes de que exista el recurso) y está respaldado por `ClaimsStatusFilterTest.php`, que pasa sin modificar ningún assert. Si el test se rompe, se rompió el movimiento, y se ve en el commit más pequeño del spec. |

---

## Lo que **NO** está en este spec

- Crear, editar o cerrar un reclamo desde la app, y añadirle transacciones: este spec es **solo lectura**.
- Mover `field_images` / `field_attachment` a `private://` y el endpoint autenticado de descarga que eso exigiría, con la migración de los archivos ya subidos.
- Notificar al residente cuando cambia el estado de su reclamo.
- Exponer el nombre del solicitante o el autor de cada transacción.
- Filtros multivalor, por solicitante, por texto libre, o por hora.
- Cualquier cambio de **comportamiento** en `admin/content/claims`, y cualquier cambio en `includes/myapi.claim_query.inc` o en `myapi.install`. De `includes/myapi.claims_admin.inc` solo salen dos funciones a un include compartido.
- Un endpoint de detalle de transacción suelta.

Cada uno de ellos, si llega, va en su propio spec.
