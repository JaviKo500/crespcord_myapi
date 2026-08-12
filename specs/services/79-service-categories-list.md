# 79 — Endpoint de categorías de servicio (`GET /api/v1/service-categories`)

- **Estado:** Approved
- **Fecha:** 2026-08-12
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea el vocabulario `service_category` con `field_category_code` (text 32, requerido) y `field_category_icon` (image, `uri_scheme = 'public'`, opcional), y `includes/myapi.services_common.inc` con la constante `MYAPI_SERVICES_CATEGORY_VOCABULARY`. Este spec **lee** ese vocabulario y no modifica ni un campo.
  - `18-banks-list` (Implemented) — precedente directo: vocabulario expuesto como colección autenticada de solo lectura, con `?sort=asc|desc` sobre `name` y respuesta `200` con lista vacía cuando el vocabulario no existe. Este spec replica esa forma.
  - `03-i18n-mensajes-respuestas` (Implemented) — `myapi_error()` con claves de catálogo. Este endpoint no añade ninguna clave nueva: solo usa `method_not_allowed`, `missing_authorization` e `invalid_token`, que ya existen.

**Objetivo:** Exponer `GET /api/v1/service-categories`, colección autenticada de solo lectura que devuelve los términos del vocabulario `service_category` ordenados alfabéticamente por `name`, cada uno con su `id`, `code`, `name`, `description` aplanada a texto plano, `icon_id` e `icon_url`.

---

## Alcance

**Dentro del alcance:**

- **`resources/service_category.resource.inc`** (nuevo) — `myapi_service_category_dispatch()` (solo `GET`, cualquier otro método → `405`), `myapi_service_category_list()` y `myapi_service_category_build_item()`. Toda la lógica del endpoint vive aquí, calcada de `resources/bank.resource.inc`.
- **`includes/myapi.text.inc`** (nuevo) — `myapi_text_to_plain($value)`, único helper compartido: convierte HTML almacenado a texto plano real (etiquetas fuera, entidades decodificadas, espacios colapsados, `trim`). Va a `includes/` y no dentro del resource porque el resto del marketplace (descripción de proveedor, mensaje de oferta, comentario de calificación) tendrá exactamente el mismo problema.
- **`myapi.module`** — una entrada en `hook_menu()`: `api/v1/service-categories` → `myapi_service_category_dispatch()`, `MENU_CALLBACK`, `access callback = TRUE` (la autorización la hace el resource con el token, como todos los demás).
- **`myapi.info`** — `files[] = includes/myapi.text.inc` y `files[] = resources/service_category.resource.inc`.
- **Pruebas unitarias** — `tests/unit/ServiceCategoryBuildItemTest.php` (mapeo del término, ícono ausente, `code` vacío, HTML aplanado) y `tests/unit/ServiceCategoryEndpointTest.php` (guards del dispatcher y regla de `sort`), siguiendo el reparto de `BankBuildItemTest` / `BankEndpointTest` (SPEC 76). Más `tests/unit/TextToPlainTest.php` para el helper.
- **`docs/service-category.md`** (nuevo), con la plantilla de `CLAUDE.md`.
- `drush cc all` al final, obligatorio por la ruta nueva.

**Fuera de alcance (para specs futuros):**

- **Escritura de categorías.** No hay `POST`, `PUT` ni `DELETE`. Las categorías las carga el operador desde `admin/structure/taxonomy/service_category`, tal como decidió SPEC 77.
- **Endpoint de detalle** `api/v1/service-categories/%`. El catálogo completo cabe en una respuesta; un detalle por término no aporta nada hoy.
- **Paginación y filtros.** Ni `limit`/`offset` ni búsqueda por texto. Se devuelven todos los términos siempre.
- **Image styles.** `icon_url` es la URL del fichero original. Cuando la app necesite miniaturas será su propio spec, porque exige acordar y crear el estilo en el sitio.
- **Conteo de proveedores por categoría** (`providers_count`) y cualquier otro dato agregado. Obliga a una consulta por categoría sobre `field_data_field_categories` y a decidir si cuenta proveedores activos (`myapi_services_provider_is_active()`); es una decisión de producto, no de transporte.
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

**Nota:** no se toca `myapi.install` (no hay campos ni tablas nuevas), ni `includes/myapi.services_common.inc` (solo se lee su constante), ni ningún resource existente.

---

## Criterios de aceptación

**Ruta y método**

- [ ] `GET /api/v1/service-categories` con un token válido responde `200` con la envoltura `{ "success": true, "data": { "service_categories": [...] } }`.
- [ ] `POST`, `PUT` y `DELETE` sobre esa ruta responden `405` con `error_code = method_not_allowed`.
- [ ] Sin cabecera `Authorization` responde `401` con `error_code = missing_authorization`.
- [ ] Con un token inexistente, revocado o caducado responde `401` con `error_code = invalid_token`.
- [ ] Un token de un usuario **sin** rol especial (residente normal) obtiene la lista completa: el endpoint no filtra por rol, condominio ni vivienda.

**Contenido del ítem**

- [ ] Cada elemento trae exactamente 6 claves: `id`, `code`, `name`, `description`, `icon_id`, `icon_url`. Ninguna más, ninguna menos.
- [ ] `id` es un entero JSON (`3`), no una cadena (`"3"`).
- [ ] Un término con `field_category_code` vacío aparece en la lista con `code: ""` — no se excluye.
- [ ] Una descripción con `<p>Hola <strong>mundo</strong></p>&nbsp;` se devuelve como `"Hola mundo"`: sin etiquetas, sin entidades, sin espacios de sobra y sin escapar.
- [ ] Un término sin descripción devuelve `description: ""`, nunca `null`.
- [ ] Una descripción que contiene el texto literal `&lt;b&gt;` almacenado se devuelve como `<b>`, no desaparece.

**Ícono**

- [ ] Un término **con** ícono devuelve `icon_id` entero e `icon_url` absoluta, y esa URL abierta en el navegador **sin sesión de Drupal** muestra la imagen (es `public://`).
- [ ] Un término **sin** ícono devuelve `icon_id: null` e `icon_url: null`.
- [ ] Nunca se da el caso de `icon_id` con valor e `icon_url` en `null`, ni al revés.

**Orden y parámetro `sort`**

- [ ] Sin `sort`, la lista viene alfabéticamente ascendente por `name`, ignorando mayúsculas: `"electricidad"` va antes que `"Plomería"`.
- [ ] Con `?sort=desc` viene exactamente en el orden inverso.
- [ ] Con `?sort=ASC`, `?sort=`, `?sort=nombre` o cualquier basura, responde `200` en orden ascendente — no `422`.
- [ ] El orden **no** depende del peso que el operador haya dado a los términos en `admin/structure/taxonomy`.

**Casos degradados**

- [ ] Si el vocabulario `service_category` no existe, responde `200` con `{ "service_categories": [] }`, no `404` ni `500`.
- [ ] Si el vocabulario existe pero no tiene términos, responde `200` con `{ "service_categories": [] }`.

**No regresión**

- [ ] `/api/v1/banks` y `/api/v1/payment-methods` devuelven byte a byte lo mismo que antes: su `description` sigue pasando por `check_plain()`.
- [ ] La suite unitaria pasa completa, con los tres ficheros de test nuevos incluidos.
- [ ] `myapi.install` no cambia: `drush updb` no ofrece ninguna actualización pendiente por este spec.
- [ ] `drush cc all` no reporta errores y la ruta nueva queda registrada en `menu_router`.

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

---

## Lo que **no** entra en este spec

- Crear, editar o borrar categorías por API. Solo `GET`.
- Endpoint de detalle `api/v1/service-categories/%`.
- Paginación, búsqueda y cualquier filtro.
- Image styles o miniaturas del ícono.
- Conteo de proveedores por categoría, ni ningún dato agregado.
- Traducción de `name` y `description`.
- Caché de la respuesta.
- Cambiar `description` en `/api/v1/banks` y `/api/v1/payment-methods`.
- El resto de endpoints del marketplace: proveedores, solicitudes, ofertas, calificaciones, chat.
- Permisos y roles: este endpoint no consulta ninguno.

Cada uno de ellos, si llega, va en su propio spec.
