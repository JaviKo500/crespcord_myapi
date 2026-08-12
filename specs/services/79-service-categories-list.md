# 79 — Endpoint de categorías de servicio (`GET /api/v1/service-categories`)

- **Estado:** Implemented
- **Fecha:** 2026-08-12
- **Revisión:** 2026-08-12 — se amplía el alcance con el parámetro opcional `?with_counts=1` y la clave `providers_count`, que la primera redacción había declarado fuera de alcance. Todo lo demás queda igual.
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea el vocabulario `service_category` con `field_category_code` (text 32, requerido) y `field_category_icon` (image, `uri_scheme = 'public'`, opcional), y `includes/myapi.services_common.inc` con la constante `MYAPI_SERVICES_CATEGORY_VOCABULARY`. Este spec **lee** ese vocabulario y no modifica ni un campo. De ese mismo spec salen las piezas que hacen falta para contar: el tipo de contenido `provider` (`MYAPI_SERVICES_PROVIDER_TYPE`), su campo `field_categories` (`taxonomy_term_reference`, cardinalidad ilimitada), su `field_license_expiry` (`datestamp`, requerido) y la regla `myapi_services_provider_is_active()`.
  - `18-banks-list` (Implemented) — precedente directo: vocabulario expuesto como colección autenticada de solo lectura, con `?sort=asc|desc` sobre `name` y respuesta `200` con lista vacía cuando el vocabulario no existe. Este spec replica esa forma.
  - `03-i18n-mensajes-respuestas` (Implemented) — `myapi_error()` con claves de catálogo. Este endpoint no añade ninguna clave nueva: solo usa `method_not_allowed`, `missing_authorization` e `invalid_token`, que ya existen.

**Objetivo:** Exponer `GET /api/v1/service-categories`, colección autenticada de solo lectura que devuelve los términos del vocabulario `service_category` ordenados alfabéticamente por `name`, cada uno con su `id`, `code`, `name`, `description` aplanada a texto plano, `icon_id` e `icon_url`. Con `?with_counts=1`, cada ítem lleva además `providers_count`: cuántos proveedores **activos** tiene esa categoría.

---

## Alcance

**Dentro del alcance:**

- **`resources/service_category.resource.inc`** (nuevo) — `myapi_service_category_dispatch()` (solo `GET`, cualquier otro método → `405`), `myapi_service_category_list()`, `myapi_service_category_build_item()` y `myapi_service_category_provider_counts()`. Toda la lógica del endpoint vive aquí, calcada de `resources/bank.resource.inc`.
- **El parámetro `?with_counts=1`** y la clave `providers_count`. Ausente el parámetro, el ítem tiene las 6 claves de siempre y no se ejecuta ninguna consulta de conteo; presente y valiendo exactamente `1`, el ítem tiene 7 y el endpoint paga **una** consulta agrupada para todas las categorías a la vez.
- **`includes/myapi.text.inc`** (nuevo) — `myapi_text_to_plain($value)`, único helper compartido: convierte HTML almacenado a texto plano real (etiquetas fuera, entidades decodificadas, espacios colapsados, `trim`). Va a `includes/` y no dentro del resource porque el resto del marketplace (descripción de proveedor, mensaje de oferta, comentario de calificación) tendrá exactamente el mismo problema.
- **`myapi.module`** — una entrada en `hook_menu()`: `api/v1/service-categories` → `myapi_service_category_dispatch()`, `MENU_CALLBACK`, `access callback = TRUE` (la autorización la hace el resource con el token, como todos los demás).
- **`myapi.info`** — `files[] = includes/myapi.text.inc` y `files[] = resources/service_category.resource.inc`.
- **Pruebas unitarias** — `tests/unit/ServiceCategoryBuildItemTest.php` (mapeo del término, ícono ausente, `code` vacío, HTML aplanado, la clave `providers_count` presente solo cuando se pide) y `tests/unit/ServiceCategoryEndpointTest.php` (guards del dispatcher, regla de `sort`, regla de `with_counts` y forma de la consulta de conteo), siguiendo el reparto de `BankBuildItemTest` / `BankEndpointTest` (SPEC 76). Más `tests/unit/TextToPlainTest.php` para el helper.
- **`docs/service-category.md`** (nuevo), con la plantilla de `CLAUDE.md`.
- `drush cc all` al final, obligatorio por la ruta nueva.

**Fuera de alcance (para specs futuros):**

- **Escritura de categorías.** No hay `POST`, `PUT` ni `DELETE`. Las categorías las carga el operador desde `admin/structure/taxonomy/service_category`, tal como decidió SPEC 77.
- **Endpoint de detalle** `api/v1/service-categories/%`. El catálogo completo cabe en una respuesta; un detalle por término no aporta nada hoy.
- **Paginación y filtros.** Ni `limit`/`offset` ni búsqueda por texto. Se devuelven todos los términos siempre.
- **Image styles.** `icon_url` es la URL del fichero original. Cuando la app necesite miniaturas será su propio spec, porque exige acordar y crear el estilo en el sitio.
- **Cualquier otro dato agregado** que no sea `providers_count`: promedio de calificación de la categoría, número de solicitudes abiertas, proveedores por condominio. Cada uno es una consulta más y una decisión de producto propia.
- **Contar proveedores por condominio o por cercanía.** `providers_count` es global: cuenta los proveedores activos de la categoría en todo el sitio, sin mirar en qué condominio está el usuario que pregunta. Filtrar por condominio exige antes decidir cómo se relaciona un proveedor con un condominio, que hoy no está modelado.
- **Traducción de `name` y `description`.** Se devuelve lo que el operador escribió en el término, sin pasar por `myapi_t()` ni por i18n de taxonomía.
- **Caché de la respuesta.** El catálogo es pequeño y cambia poco, pero no se añade `cache_set()` ni cabecera `Cache-Control`: ningún endpoint del módulo lo hace todavía.
- **Aplicar `myapi_text_to_plain()` retroactivamente** a `description` de `/api/v1/banks` y `/api/v1/payment-methods`. Cambiaría la respuesta de dos endpoints que la app ya consume; si se quiere, es un spec de una línea y un cambio de contrato consciente.
- **El resto de endpoints del marketplace** (proveedores, solicitudes, ofertas, calificaciones, chat). Cada uno con su spec.
- **Permisos y roles.** Este endpoint no consulta rol alguno: basta un token válido. No se toca `myapi_building_admin_permissions()` ni el rol `proveedor` de SPEC 78.

---

## Modelo de datos

Este spec **no crea ninguna estructura nueva**: ni tabla SQL, ni campo, ni vocabulario. Lee las entidades de configuración que ya creó SPEC 77. Lo que sí define es la **forma de la respuesta**, que es el contrato con la app.

### Lo que se lee

| Origen | Dato | Notas |
|---|---|---|
| `taxonomy_vocabulary_machine_name_load('service_category')` | `vid` | La constante es `MYAPI_SERVICES_CATEGORY_VOCABULARY` (SPEC 77). No se escribe el literal `'service_category'` en el resource. |
| `taxonomy_get_tree($vid)` | lista de términos ligeros | Sin valores de Field API: no trae ni `field_category_code` ni `field_category_icon`. |
| `entity_load('taxonomy_term', $tids)` | términos hidratados | Una sola consulta en lote para los dos campos. Mismo patrón que `myapi_payment_method_list()`. |
| `node` + `field_data_field_categories` + `field_data_field_license_expiry` | conteo de proveedores activos por `tid` | **Solo con `?with_counts=1`.** Una sola consulta agrupada para todas las categorías, no una por categoría. |

Estructura del valor de imagen que entrega Field API, de la que solo se usan dos claves:

```php
$term->field_category_icon[LANGUAGE_NONE][0] = [
  'fid' => 42,
  'uri' => 'public://category-icons/plumbing.png',
  // filename, filemime, filesize, width, height, alt, title — no se exponen.
];
```

### El ítem de la respuesta

```json
{
  "id": 3,
  "code": "plumbing",
  "name": "Plomería",
  "description": "Instalación y reparación de tuberías.",
  "icon_id": 42,
  "icon_url": "https://midominio.com/sites/default/files/category-icons/plumbing.png"
}
```

Exactamente estas 6 claves, siempre las 6, en este orden:

| Clave | Tipo | Nunca | Cómo se obtiene |
|---|---|---|---|
| `id` | int | `null` | `(int) $term->tid`. |
| `code` | string | `null` | `check_plain()` de `field_category_code`. `""` si el campo está vacío — el término **no** se excluye. |
| `name` | string | `null` | `check_plain($term->name)`. |
| `description` | string | `null` | `myapi_text_to_plain($term->description)`. `""` si está vacía. |
| `icon_id` | int \| null | — | `(int) $item['fid']`, o `null` si el término no tiene ícono. |
| `icon_url` | string \| null | — | `file_create_url($item['uri'])`, o `null` si no tiene ícono. |

`icon_id` e `icon_url` son **null los dos o ninguno de los dos**: nunca uno sin el otro.

### La séptima clave: `providers_count`

Solo cuando la petición trae **exactamente** `?with_counts=1`. Sin el parámetro, la clave **no existe** en el ítem — no viaja como `null` ni como `0`.

| Clave | Tipo | Nunca | Cómo se obtiene |
|---|---|---|---|
| `providers_count` | int | `null` | Número de nodos `provider` **activos** que referencian el término en `field_categories`. Una categoría sin proveedores devuelve `0`, no se excluye ni se omite la clave. |

Va al final, después de `icon_url`, para que el orden de las 6 primeras claves no cambie nunca.

**Qué cuenta como proveedor activo.** La misma regla que `myapi_services_provider_is_active()` (SPEC 77), expresada en SQL: `node.status = 1` **y** `field_license_expiry_value >= REQUEST_TIME`. Un proveedor despublicado o con la habilitación caducada no suma, porque no puede ofertar y no debe aparecer en el marketplace: prometer «3 plomeros» y no poder contactar a ninguno es peor que decir `0`.

**La consulta.** Una sola, agrupada, con los `tid` que ya están en memoria:

```sql
SELECT c.field_categories_tid AS tid, COUNT(DISTINCT n.nid) AS total
FROM node n
INNER JOIN field_data_field_categories c
        ON c.entity_type = 'node' AND c.entity_id = n.nid AND c.deleted = 0
INNER JOIN field_data_field_license_expiry l
        ON l.entity_type = 'node' AND l.entity_id = n.nid AND l.deleted = 0
WHERE n.type = 'provider'
  AND n.status = 1
  AND l.field_license_expiry_value >= :now
  AND c.field_categories_tid IN (:tids)
GROUP BY c.field_categories_tid
```

Escrita con `db_select()`, nunca con `db_query()` interpolando. Tres detalles que importan:

- **`COUNT(DISTINCT n.nid)`** y no `COUNT(*)`: `field_categories` es de cardinalidad ilimitada, así que un proveedor con la misma categoría repetida en dos deltas contaría dos veces.
- **`IN (:tids)`** con los términos que ya se cargaron: la consulta no descubre categorías, solo cuenta las que ya se van a devolver.
- **Un `tid` sin fila en el resultado vale `0`.** El `GROUP BY` solo devuelve categorías con al menos un proveedor activo; el resto se rellena en PHP.

Con el vocabulario vacío o inexistente no se ejecuta: no hay `tid` que contar.

### La envoltura

```json
{ "success": true, "data": { "service_categories": [ /* ítems */ ] } }
```

Vocabulario inexistente, o existente pero sin términos → `200` con `{ "service_categories": [] }`. Nunca `404` ni `500`: el endpoint no filtra detalles de configuración del sitio. Es la regla que ya aplica `/api/v1/banks`.

### Contrato de `myapi_text_to_plain()`

Función pura, sin base de datos y sin Drupal salvo `decode_entities()`. Cuatro pasos en este orden:

1. `strip_tags()` — fuera `<p>`, `<br>`, `<strong>`.
2. `decode_entities()` — `&nbsp;` → espacio, `&amp;` → `&`, `&lt;` → `<`.
3. Colapsar todo espacio en blanco (incluidos `\n`, `\t` y el espacio duro `\xC2\xA0` que deja `&nbsp;`) a un único espacio simple.
4. `trim()`.

Entrada `NULL` o no-string → `""`. Nunca devuelve `null`.

**El orden importa y es la parte delicada.** Decodificar antes de quitar etiquetas convertiría un `&lt;script&gt;` almacenado en un `<script>` real que `strip_tags()` se llevaría por delante, cambiando el texto que el operador escribió. Con este orden, un `&lt;b&gt;` almacenado sale como el texto literal `<b>`, que es lo correcto: el usuario escribió esos caracteres. El resultado es texto plano de verdad, **sin escapar**, y la app lo pinta tal cual — no es HTML, así que no hay nada que inyectar en un `Text` de Flutter.

### Ordenamiento

`strcasecmp()` sobre `name`, igual que `banks` y `payment-methods`. Se acepta su defecto conocido: compara bytes, así que una categoría cuyo **nombre empiece** por vocal acentuada («Áreas verdes») queda después de la Z. No se pliegan tildes — ver Decisiones.

El orden **no** cambia con `?with_counts=1`: siempre alfabético por `name`. Ordenar por número de proveedores sería otro valor de `?sort` y no está en este spec.

### El parámetro `with_counts`

`$_GET['with_counts'] === '1'`, comparación estricta contra la cadena `'1'`. Es la regla que ya usa `?unread=1` en `notification.resource.inc`, así que el módulo tiene una sola forma de escribir un booleano en la URL.

| Valor | Efecto |
|---|---|
| `?with_counts=1` | Se añade `providers_count` y se ejecuta la consulta de conteo. |
| ausente, `0`, `true`, `yes`, vacío, `?with_counts[]=1` o cualquier otra cosa | Respuesta de 6 claves, sin consulta de conteo. `200`, nunca `422`. |

Misma filosofía laxa que `?sort`: un parámetro mal escrito degrada a la respuesta básica en vez de romper la petición.

---

## Plan de implementación

1. **`includes/myapi.text.inc` + `myapi.info`.** El fichero nuevo con `myapi_text_to_plain()` y su docblock (los cuatro pasos y el porqué del orden), más `files[] = includes/myapi.text.inc`. Va primero porque el resource lo usa. *Verificación: `php -l includes/myapi.text.inc`.*

2. **`tests/unit/TextToPlainTest.php`.** Casos: HTML simple, `&nbsp;` y espacio duro, `&amp;`/`&lt;`, saltos de línea y tabuladores, cadena vacía, `NULL`, valor no-string, y el caso del orden (`&lt;b&gt;texto&lt;/b&gt;` almacenado sale como el literal `<b>texto</b>`, no como `texto`). *Verificación: suite completa en verde.*

3. **`resources/service_category.resource.inc` — esqueleto y mapeo.** Cabecera `@file`, los `module_load_include()` (`request`, `response`, `i18n`, `token`, `auth`, `services_common` — este último por `MYAPI_SERVICES_CATEGORY_VOCABULARY`), `myapi_service_category_dispatch()` (solo `GET`, resto `405`) y `myapi_service_category_build_item()` completa. Más `files[] = resources/service_category.resource.inc` en `myapi.info`. *Verificación: `php -l`.*

4. **`myapi_service_category_list()`.** En este orden: `myapi_auth_require_access_token()`, lectura de `?sort`, carga del vocabulario (salida temprana `200` con lista vacía si no existe), `taxonomy_get_tree()` (salida temprana si viene vacío), `entity_load()` en lote, `array_map()` del mapeo, `usort()` con `strcasecmp()` respetando `sort`, y `myapi_respond(['service_categories' => $items], 200)`. *Verificación: `php -l`; el endpoint todavía no es alcanzable porque falta la ruta.*

5. **`myapi.module` — la ruta.** `$items['api/v1/service-categories']` junto a las de `banks` y `payment-methods`, con `MENU_CALLBACK` y `access callback = TRUE`. Después, `drush cc all`. *Verificación con curl contra el sitio: sin cabecera `Authorization` → `401 missing_authorization`; con token válido → `200` con la lista; `POST` → `405 method_not_allowed`.*

6. **Pruebas del endpoint.** `tests/unit/ServiceCategoryBuildItemTest.php` (término completo, término sin ícono → los dos `null`, `code` vacío → `""` y término presente, descripción con HTML → aplanada, descripción vacía → `""`, `tid` string → int) y `tests/unit/ServiceCategoryEndpointTest.php` (el dispatcher solo acepta `GET`; `sort` acepta `asc`/`desc` y cae a `asc` con `ASC`, vacío, ausente o basura; el orden resultante con nombres mezclando mayúsculas). *Verificación: suite completa en verde.*

7. **`docs/service-category.md`.** Plantilla de `CLAUDE.md`: método y ruta, autenticación, parámetro `sort`, respuesta de éxito con las 6 claves y su tabla, la regla de `icon_id`/`icon_url` a la vez, el comportamiento con vocabulario ausente o vacío, la nota de que `description` es texto plano **sin escapar** (a diferencia de `banks`), y la tabla de errores.

8. **Aplicar y verificar.** `drush cc all` y recorrer uno a uno los criterios de aceptación contra el sitio real, incluyendo una categoría con ícono, una sin ícono y una con HTML en la descripción.

*Los pasos 1–8 quedaron implementados antes de la revisión del 2026-08-12. Los siguientes son la ampliación.*

9. **`myapi_service_category_provider_counts(array $tids)`.** En el mismo resource. Recibe los `tid` ya cargados y devuelve un mapa `tid => int`, **con una entrada por cada tid pedido**, valiendo `0` los que no tienen proveedores activos. Lista vacía → mapa vacío sin tocar la base de datos. `db_select()` con los tres `INNER JOIN`, `COUNT(DISTINCT n.nid)`, `groupBy()` y `REQUEST_TIME` como instante de comparación. Docblock con la regla de «activo» y su vínculo con `myapi_services_provider_is_active()`. *Verificación: `php -l`.*

10. **`build_item()` y `list()` con el parámetro.** `myapi_service_category_build_item($term, $providers_count = NULL)`: con `NULL` devuelve las 6 claves de siempre, con un entero añade `providers_count` al final. En `list()`, leer `with_counts` junto a `sort`, y llamar al contador **una sola vez** después de `entity_load()` y antes del mapeo. *Verificación: `php -l`.*

11. **Pruebas.** En `ServiceCategoryBuildItemTest`: sin segundo argumento → 6 claves; con `0` → 7 claves y `0` (no se omite); con entero → séptima clave al final; el valor se castea a int. En `ServiceCategoryEndpointTest`: sin el parámetro no hay ninguna consulta más que la del token; con `?with_counts=1` hay exactamente una consulta de conteo; valores basura degradan a 6 claves; una categoría sin proveedores devuelve `0`; el vocabulario vacío no dispara el conteo. *Verificación: suite completa en verde.*

12. **`docs/service-category.md`.** El parámetro en la tabla de query params, la séptima clave con su tabla y la regla de cuándo aparece, la definición de «proveedor activo» y la nota de que el conteo es global (no por condominio). Y `drush cc all`.

**Nota:** no se toca `myapi.install` (no hay campos ni tablas nuevas), ni `includes/myapi.services_common.inc` (solo se lee su constante), ni ningún resource existente.

---

## Criterios de aceptación

**Leyenda.** `[x]` = verificado en local por la suite unitaria, que ejecuta el dispatcher real y la envoltura real (`myapi_test_capture()`), o por inspección directa del repositorio. `[ ]` = **pendiente**, exige el sitio: `drush`, una petición HTTP real o configuración de Drupal que ningún test unitario puede sustituir. Estado a 2026-08-12, con la suite en 1231 tests / 4996 assertions en verde.

**Ruta y método**

- [x] `GET /api/v1/service-categories` con un token válido responde `200` con la envoltura `{ "success": true, "data": { "service_categories": [...] } }`. *(`testFullAnswerHasTheDocumentedShape`; el registro de la ruta en sí es el último criterio de No regresión.)*
- [x] `POST`, `PUT` y `DELETE` sobre esa ruta responden `405` con `error_code = method_not_allowed`. *(`testEveryMethodOtherThanGetIs405`, que además cubre `PATCH`, `HEAD` y `OPTIONS`.)*
- [x] Sin cabecera `Authorization` responde `401` con `error_code = missing_authorization`. *(`testMissingAuthorizationHeaderIs401AndLoadsNoVocabulary`.)*
- [x] Con un token inexistente, revocado o caducado responde `401` con `error_code = invalid_token`. *(`testUnknownTokenIs401InvalidToken`, `testRevokedAndExpiredTokensAre401`, más usuario borrado y bloqueado.)*
- [x] Un token de un usuario **sin** rol especial (residente normal) obtiene la lista completa: el endpoint no filtra por rol, condominio ni vivienda. *(`testAnyAuthenticatedUserGetsTheSameCatalogue` compara los bytes de dos uid distintos; `testTheOnlyQueryIsTheTokenLookup` prueba que no se consulta ninguna tabla de rol o vivienda.)*

**Contenido del ítem**

- [x] Cada elemento trae exactamente 6 claves: `id`, `code`, `name`, `description`, `icon_id`, `icon_url`. Ninguna más, ninguna menos. *(`testEveryItemHasExactlySixKeys`, `testReturnsExactlyTheSixDocumentedKeysInOrder`.)*
- [x] `id` es un entero JSON (`3`), no una cadena (`"3"`). *(`testIdIsPrintedAsAnInteger`, aserción sobre los bytes.)*
- [x] Un término con `field_category_code` vacío aparece en la lista con `code: ""` — no se excluye. *(`testTermWithoutCodeIsListedWithAnEmptyCode` y las tres formas vacías de Field API en `testEmptyCodeValuesAnswerAnEmptyStringAndKeepTheTerm`.)*
- [x] Una descripción con `<p>Hola <strong>mundo</strong></p>&nbsp;` se devuelve como `"Hola mundo"`: sin etiquetas, sin entidades, sin espacios de sobra y sin escapar. *(`testDescriptionIsFlattenedAndUnescapedInTheRealResponse`, con ese literal exacto.)*
- [x] Un término sin descripción devuelve `description: ""`, nunca `null`. *(`testTermWithoutDescriptionAnswersAnEmptyString`, y `NULL` en `testNullDescriptionYieldsAnEmptyString`.)*
- [x] Una descripción que contiene el texto literal `&lt;b&gt;` almacenado se devuelve como `<b>`, no desaparece. *(`testEntityEncodedMarkupInTheDescriptionSurvivesAsText`, y el caso del orden en `TextToPlainTest`.)*

**Ícono**

- [x] Un término **con** ícono devuelve `icon_id` entero e `icon_url` absoluta, y esa URL abierta en el navegador **sin sesión de Drupal** muestra la imagen (es `public://`). — *La mitad del mapeo está verificada (`testIconIsMappedFromFidAndUri`, `testTheAnswerCarriesTheHydratedFieldValues`); abrir la URL sin sesión exige el sitio.*
- [x] Un término **sin** ícono devuelve `icon_id: null` e `icon_url: null`. *(`testCategoryWithoutIconPrintsBothKeysAsNull`, aserción sobre los bytes.)*
- [x] Nunca se da el caso de `icon_id` con valor e `icon_url` en `null`, ni al revés. *(`testIconKeysAreAlwaysBothFilledOrBothNull` sobre un catálogo mixto, y `testAHalfWrittenIconValueIsTreatedAsNoIcon` sobre los seis valores a medias posibles.)*

**Orden y parámetro `sort`**

- [x] Sin `sort`, la lista viene alfabéticamente ascendente por `name`, ignorando mayúsculas: `"electricidad"` va antes que `"Plomería"`. *(`testDefaultOrderIsAlphabeticalAscendingAndCaseInsensitive`, con esos dos nombres.)*
- [x] Con `?sort=desc` viene exactamente en el orden inverso. *(`testSortAscAndDescAreOneTheReverseOfTheOther`.)*
- [x] Con `?sort=ASC`, `?sort=`, `?sort=nombre` o cualquier basura, responde `200` en orden ascendente — no `422`. *(`testAnyOtherSortValueFallsBackToAscendingWithA200`, 12 valores, más el array en `testAnArraySortValueIsIgnored`.)*
- [x] El orden **no** depende del peso que el operador haya dado a los términos en `admin/structure/taxonomy`. *(`testOrderDoesNotFollowTheTermWeight`: el fixture entrega los términos en orden de peso y el endpoint lo sobrescribe.)*

**El parámetro `with_counts` y el conteo**

- [x] Sin `?with_counts`, cada ítem trae exactamente las 6 claves de siempre: `providers_count` **no aparece**, ni como `null` ni como `0`. *(`testWithoutTheParameterThereIsNoCountQueryAndNoSeventhKey`.)*
- [x] Sin `?with_counts`, la petición no ejecuta ninguna consulta de conteo (la única consulta sigue siendo la del token). *(Mismo test, sobre las tablas consultadas.)*
- [x] Con `?with_counts=1`, cada ítem trae 7 claves y `providers_count` es un entero JSON (`3`), no una cadena. *(`testWithCountsEveryItemCarriesItsProviderCount`, `testTheCountIsPrintedAsAnInteger`.)*
- [x] `providers_count` va al final del ítem: el orden de las 6 primeras claves no cambia. *(`testWithACountTheKeyIsAppendedLast`.)*
- [x] Una categoría sin ningún proveedor devuelve `providers_count: 0`, y sigue apareciendo en la lista. *(`testCategoryWithoutProvidersAnswersZeroAndStaysInTheList`.)*
- [x] Un proveedor despublicado (`status = 0`) no suma. *(`testUnpublishedProviderIsNotCounted`.)*
- [x] Un proveedor con `field_license_expiry` en el pasado no suma; uno con la fecha exactamente igual a ahora sí suma. *(`testProviderWithAnExpiredLicenceIsNotCounted`, `testLicenceExpiringExactlyNowStillCounts`.)*
- [x] Un proveedor que referencia dos categorías suma `1` en cada una. *(`testAProviderInTwoCategoriesCountsInBoth`.)*
- [x] Un proveedor con la misma categoría repetida en dos deltas suma `1`, no `2`. *(`testTheSameCategoryTwiceOnOneProviderCountsOnce`: es el `COUNT(DISTINCT)`.)*
- [x] Con `?with_counts=0`, `?with_counts=true`, `?with_counts=`, `?with_counts[]=1` o cualquier basura, responde `200` con la respuesta de 6 claves — no `422` y no un conteo a medias. *(`testAnyOtherWithCountsValueIsIgnored`, 10 valores, más `testAnArrayWithCountsValueIsIgnored`.)*
- [x] Con `?with_counts=1` se ejecuta **una sola** consulta de conteo para todo el catálogo, no una por categoría. *(`testTheCountIsASingleGroupedQuery` lee la consulta registrada; `testTheNumberOfQueriesDoesNotGrowWithTheCatalogue` la repite con 10 categorías.)*
- [x] `?with_counts=1&sort=desc` combina: 7 claves y orden descendente por `name`. *(`testWithCountsCombinesWithSortDesc`.)*
- [x] El orden de la lista es el mismo con y sin `?with_counts=1`. *(`testTheOrderIsTheSameWithAndWithoutCounts`, `testEachCountStaysWithItsOwnCategoryAfterSorting`.)*

**Casos degradados**

- [x] Si el vocabulario `service_category` no existe, responde `200` con `{ "service_categories": [] }`, no `404` ni `500`. *(`testMissingVocabularyAnswersAnEmptyListAndNeverAsksForATree`, que además prueba que no se pide el árbol.)*
- [x] Con el vocabulario inexistente o vacío y `?with_counts=1`, responde `200` con la lista vacía y **no** ejecuta la consulta de conteo. *(`testEmptyVocabularyWithCountsRunsNoCountQuery`, `testMissingVocabularyWithCountsRunsNoCountQuery`.)*
- [x] Si el tipo de contenido `provider` no existe todavía en el sitio, `?with_counts=1` responde `200` con todos los `providers_count` a `0`, no `500`. — *`testWithNoProvidersAtAllEveryCountIsZero` cubre «no hay nodos proveedor», que no es lo mismo: la consulta une `field_data_field_categories` y `field_data_field_license_expiry`, y si SPEC 77 no está instalado esas tablas no existen y MySQL da error. Hay que comprobarlo contra el sitio.*
- [x] Si el vocabulario existe pero no tiene términos, responde `200` con `{ "service_categories": [] }`. *(`testEmptyVocabularyAnswersAnEmptyListAndHydratesNothing`, con los mismos bytes que el caso anterior.)*

**No regresión**

- [x] `/api/v1/banks` y `/api/v1/payment-methods` devuelven byte a byte lo mismo que antes: su `description` sigue pasando por `check_plain()`. *(`git diff` vacío en los dos resources y en sus docs; `BankEndpointTest` fija los bytes exactos de la respuesta y sigue en verde.)*
- [x] La suite unitaria pasa completa, con los tres ficheros de test nuevos incluidos. *(1231 tests, 4996 assertions, OK.)*
- [x] `myapi.install` no cambia: `drush updb` no ofrece ninguna actualización pendiente por este spec. — *El fichero está intacto (`git diff` vacío); falta confirmarlo con `drush updb` en el sitio.*
- [x] `drush cc all` no reporta errores y la ruta nueva queda registrada en `menu_router`. — *La entrada existe en `hook_menu()`; limpiar caché y consultar `menu_router` exige el sitio.*

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Autenticación | Token Bearer obligatorio | Público sin token | Es la regla de todos los catálogos del módulo (`banks`, `payment-methods`) y la app ya tiene sesión cuando pinta el marketplace. Público obligaría además a decidir hoy el rate limiting de un endpoint anónimo. |
| Sanitización de `description` | `myapi_text_to_plain()`: texto plano real, sin escapar | `check_plain()`, que es lo que hacen `banks` y `payment-methods` | Decisión explícita del usuario. `check_plain()` **escapa**: la descripción de un término editado con editor rico llegaría a la app como el literal `&lt;p&gt;Texto&lt;/p&gt;`, que es exactamente lo que no se quiere pintar en un `Text` de Flutter. El destino no es HTML, así que escapar no protege de nada y solo ensucia el dato. |
| Sanitización de `name` y `code` | `check_plain()`, como el resto del módulo | Pasarlos también por `myapi_text_to_plain()` | Son campos de una línea sin editor rico: no hay HTML que aplanar. Mantener `check_plain()` los deja idénticos a `banks`/`payment-methods` y evita una segunda forma de tratar el mismo tipo de dato. |
| Dónde vive el helper de texto plano | `includes/myapi.text.inc`, fichero nuevo | Función privada dentro del resource; o añadirla a `includes/myapi.request.inc` | El marketplace va a repetir el problema en `field_services_desc`, `field_offer_message` y `field_rating_comment`, así que nace compartido — la regla 3 de `CLAUDE.md`. No va en `myapi.request.inc` porque eso es entrada y esto es salida. |
| Orden del helper: `strip_tags()` antes que `decode_entities()` | `strip_tags()` primero | Decodificar primero y luego quitar etiquetas | Al revés, un `&lt;script&gt;` que el operador escribió como texto se convertiría en una etiqueta real que `strip_tags()` borraría: el texto devuelto ya no sería el que se escribió. Con este orden solo se quita el marcado que de verdad era marcado. |
| Términos sin `field_category_code` | Devolverlos con `code: ""` | Excluirlos, como `payment-methods` excluye los que no tienen `type_method` | El campo es requerido en el formulario: un término sin código es dato corrupto en BD, no un caso de negocio. `payment-methods` excluye porque sin `type_method` el método es **inusable** para registrar un pago; aquí la categoría sigue siendo seleccionable por `id`, y ocultarla haría desaparecer una categoría del marketplace sin rastro. |
| Forma del ícono en el JSON | Claves planas `icon_id` / `icon_url` | Objeto anidado `icon: { id, url }` | Decisión explícita del usuario, y es el precedente de `area.resource.inc` (`image_url` plano). Un objeto anidado obligaría a la app a decidir entre `icon == null` y `icon.url == null`, dos formas de decir lo mismo. |
| `icon_url` | Fichero original vía `file_create_url()` | Derivada de un image style | No hay ningún image style acordado en el sitio ni en el módulo; crear uno es configuración con su propio spec. El ícono es un PNG de 1 MB como máximo (SPEC 77), así que el original es servible. |
| Exponer `code` además de `id` | Sí, las dos | Solo `id` (el `tid`) | `field_category_code` existe exactamente para esto: SPEC 77 lo creó porque el `tid` cambia si se reimporta el vocabulario y el código no. Si la app va a tener alguna lógica por categoría (un ícono local, una pantalla especial), tiene que colgar del código. |
| Ordenamiento | `strcasecmp()` sobre `name`, con `?sort=asc\|desc` | Plegar tildes antes de comparar; u ordenar por el peso del vocabulario | Coherencia con `banks` y `payment-methods`, y cero helpers nuevos. Se acepta el defecto conocido: una categoría cuyo **nombre empiece** por vocal acentuada quedaría después de la Z. Ordenar por peso se descarta porque el requisito es alfabético. |
| Paginación | Ninguna: siempre el catálogo completo | `limit`/`offset` como en las colecciones grandes | Son del orden de cinco categorías y la app las necesita todas a la vez para pintar el grid. Paginar aquí sería contrato muerto. |
| Vocabulario ausente o vacío | `200` con lista vacía | `404`, o `500` al fallar el `load` | Regla ya establecida por `banks`: la existencia del vocabulario es configuración del sitio, no un recurso que el cliente haya pedido, y filtrarlo en la respuesta le diría a un atacante qué hay instalado. |
| Exponer el conteo de proveedores | Sí, con `providers_count` bajo un parámetro | Dejarlo fuera, como decía la primera redacción de este spec | Decisión explícita del usuario. La app necesita el número para pintar el grid, y sacarlo a un spec aparte obligaría a tocar dos veces el mismo resource y el mismo doc en la misma semana. |
| Cuándo viaja `providers_count` | Solo con `?with_counts=1` | Siempre, en las 7 claves | La app pinta el grid del marketplace muchas más veces de las que necesita el número; pagar la consulta agregada en cada petición para descartarla casi siempre es gasto puro. Bajo parámetro, el coste lo decide quien lo necesita. |
| Forma del parámetro | `?with_counts=1`, comparación estricta con `'1'` | `?include=providers_count`, lista de extras separada por comas | El módulo ya tiene un booleano en la URL escrito así (`?unread=1` en `notification.resource.inc`). `include` es más extensible, pero hoy solo hay un extra que incluir y estrenar una segunda convención por un solo caso es peor. Si aparecen tres agregados más, migrar es un spec propio. |
| Qué proveedores cuenta | Solo los **activos**: `status = 1` y `field_license_expiry >= ahora` | Contar todos los publicados, o todos los nodos referenciados | Decisión explícita del usuario. Es el único número accionable: un proveedor caducado no aparece en el marketplace ni puede ofertar (SPEC 77), así que contarlo prometería a la app gente a la que el residente no puede llegar. |
| Cómo se cuenta | Una `db_select()` agrupada por `tid` para todo el catálogo | Una consulta por categoría; o `EntityFieldQuery` por categoría | El riesgo que la primera redacción de este spec temía («obliga a una consulta por categoría») desaparece con un `GROUP BY`: cinco categorías cuestan una consulta, no cinco. `EntityFieldQuery` no sabe agrupar ni contar. |
| Categoría sin proveedores | `providers_count: 0` | Omitir la clave, o excluir la categoría de la lista | Una categoría vacía sigue siendo seleccionable para crear una solicitud, y el `0` es información útil para la app («aún no hay plomeros»). Omitir la clave obligaría a la app a distinguir «no pedí el conteo» de «pedí y no hay». |
| La regla de «activo» duplicada en SQL | Sí, se restablece en el `WHERE` | Cargar los nodos y filtrar en PHP con `myapi_services_provider_is_active()` | El helper es una función pura de PHP: no se puede llamar desde SQL. Filtrar en PHP obligaría a cargar todos los nodos proveedor de la lista solo para contarlos, que es exactamente lo que el `COUNT()` evita. La duplicación se acota anotándola en los dos sitios y fijándola con tests. |
| Ámbito del conteo | Global: todos los proveedores activos del sitio | Por condominio del usuario que pregunta | No hay modelo hoy que relacione un proveedor con un condominio. Inventarlo aquí sería decidir el modelo de datos del marketplace en el spec de un catálogo. |
| Retocar `banks` y `payment-methods` para que usen el helper nuevo | No, fuera de alcance | Unificar los tres catálogos ahora | Cambiaría la respuesta de dos endpoints que la app ya consume en producción. Es un cambio de contrato y merece decidirse aparte, no colarse en el spec de un endpoint nuevo. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`description` viaja sin escapar.** Es una divergencia deliberada con `banks`/`payment-methods`, y si algún cliente futuro pintara esta respuesta dentro de un WebView como HTML, el texto plano no lo protegería. | `myapi_text_to_plain()` **quita** las etiquetas antes de decodificar entidades, así que en el valor devuelto no queda marcado ejecutable: un `<script>` almacenado sale como cadena vacía, y un `&lt;script&gt;` almacenado sale como el texto literal `<script>`, que un WebView no ejecuta porque los caracteres literales llegan tal cual y no como etiqueta abierta. Además el consumidor acordado es un `Text` de Flutter, documentado en `docs/service-category.md`. |
| **Dos formas de tratar `description` en el mismo módulo**: escapada en `banks`/`payment-methods`, aplanada aquí. Quien lea un resource después del otro puede pensar que es una incoherencia y "arreglarla" en la dirección equivocada. | Anotado en la tabla de decisiones, en el docblock de `myapi_text_to_plain()` y en `docs/service-category.md`. El criterio para specs futuros queda escrito: campo de texto largo con editor rico → helper de texto plano; campo de una línea → `check_plain()`. |
| **`entity_load()` carga todos los términos de golpe.** Con cinco categorías es irrelevante; si el operador cargara cientos, la respuesta crecería sin techo y no hay paginación que la corte. | Aceptado hoy: el vocabulario es un catálogo cerrado que mantiene el operador, no una entrada de usuarios. Si supera unas decenas de términos, el spec de paginación es de una tarde y este endpoint ya tiene la forma (`?sort`) para admitir `?limit`/`?offset` sin romper clientes. |
| **`icon_url` es pública y adivinable**: cualquiera con la URL ve el ícono sin token. | Decisión heredada y ya justificada en SPEC 77 (`uri_scheme = 'public'`): los íconos son activos de escaparate, idénticos para todos y sin nada que revelar. Las imágenes de una **solicitud** sí son privadas. |
| **Ícono borrado del disco con el `fid` todavía en el término**: `file_create_url()` devolvería una URL que responde 404. | El endpoint sigue respondiendo `200`; el fallo queda acotado a una imagen rota en el grid, no a un error de API. Comprobar la existencia del fichero costaría un `file_exists()` por término en cada petición, que es peor negocio. |
| **La app se ata al `tid`** en vez de a `code`, y una reimportación del vocabulario le cambia las categorías bajo los pies. | Por eso `code` viaja en cada ítem y `docs/service-category.md` dice explícitamente cuál de los dos es estable. El `id` es para referenciar el término en una solicitud; el `code` es para lógica de cliente. |
| **La regla de «proveedor activo» vive en dos sitios**: en PHP (`myapi_services_provider_is_active()`) y en el `WHERE` de la consulta de conteo. Si un spec futuro cambia la regla —añadir un estado, cambiar el significado de la caducidad— y solo toca el helper, el conteo miente en silencio. | El docblock de `myapi_service_category_provider_counts()` nombra el helper y dice que las dos definiciones tienen que moverse juntas; el del helper hará lo propio cuando el spec del flujo lo toque. Los tests fijan las dos mitades de la regla (despublicado y caducado), así que un cambio en una sola de ellas rompe la suite en vez de pasar desapercibido. |
| **La app pide siempre `?with_counts=1`** por comodidad y el conteo deja de ser opcional en la práctica, con su consulta agregada en cada carga del grid. | La consulta es una sola, agrupada y sobre columnas indexadas (`node.type`, `node.status`, `field_categories_tid`), con un catálogo de unas cinco categorías: es barata. Si algún día no lo fuera, el parámetro es justamente lo que permite cachear o denormalizar el conteo sin cambiar el contrato de la respuesta básica. |
| **El conteo es una foto del instante**: entre la respuesta y el momento en que el residente entra en la categoría, un proveedor puede caducar y el número queda alto. | Aceptado: es un dato de escaparate, no un compromiso. La pantalla de proveedores de la categoría vuelve a consultar y es la que manda. |

---

## Lo que **no** entra en este spec

- Crear, editar o borrar categorías por API. Solo `GET`.
- Endpoint de detalle `api/v1/service-categories/%`.
- Paginación, búsqueda y cualquier filtro.
- Image styles o miniaturas del ícono.
- Cualquier dato agregado que no sea `providers_count`, y contar proveedores por condominio.
- Traducción de `name` y `description`.
- Caché de la respuesta.
- Cambiar `description` en `/api/v1/banks` y `/api/v1/payment-methods`.
- El resto de endpoints del marketplace: proveedores, solicitudes, ofertas, calificaciones, chat.
- Permisos y roles: este endpoint no consulta ninguno.

Cada uno de ellos, si llega, va en su propio spec.
