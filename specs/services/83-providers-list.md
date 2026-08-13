# 83 — Listado de proveedores del marketplace (`GET /api/v1/providers`)

- **Estado:** Implemented
- **Fecha:** 2026-08-13
- **Implementado:** 2026-08-13, rama `spec-83-providers-list`, desplegado y verificado contra el sitio.
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea el bundle `provider` y sus campos `field_categories`, `field_rating_avg`, `field_rating_count` y `field_license_expiry`, y escribe en `includes/myapi.services_common.inc` la regla `myapi_services_provider_is_active()` que este spec **traduce a SQL**. No se modifica ni un campo.
  - `79-service-categories-list` (Implemented) — el precedente directo: es el otro endpoint de solo lectura del marketplace, de él sale el `id` de categoría que este spec acepta como filtro, y su `myapi_service_category_provider_counts()` es la **misma consulta de proveedor activo** que este listado tiene que devolver coherente. También aporta `myapi_text_to_plain()`, que aquí **no** se usa (ver Modelo de datos).
  - `81-provider-rate-tags-short-description` (Implemented) — crea `field_hourly_rate` y `field_short_description`, los dos campos que este spec estrena en una respuesta de API. `field_tags` existe y **no** viaja.
  - `82-provider-private-gallery` (Implemented) — crea `resources/provider.resource.inc` y reserva el namespace `api/v1/providers/...`. Este spec **añade** funciones a ese fichero y una ruta hermana de las dos suyas; no toca la galería.

**Objetivo:** Exponer `GET /api/v1/providers`, autenticado y de solo lectura, que devuelve los proveedores **activos** con su nombre, categorías, calificación, descripción corta y tarifa por hora, paginado con el patrón del módulo, filtrable por una categoría y ordenable por calificación o por tarifa en ambos sentidos.

Cuatro notas que la cabecera fija:

- **Es el listado que SPEC 81 y SPEC 82 dejaron pendiente por escrito.** Los dos declaran «el endpoint del proveedor es su propio spec» y este lo es. Con él, `field_hourly_rate` y `field_short_description` dejan de ser columnas invisibles.
- **La regla de proveedor activo pasa a vivir en tres sitios.** Hoy son dos: `myapi_services_provider_is_active()` en PHP y el `WHERE` de `myapi_service_category_provider_counts()`. Este spec añade el tercero, y de ahí sale la decisión de **extraerla a un helper compartido** en vez de escribirla una tercera vez a mano — es el punto más discutible del spec y está en Alcance.
- **No hay endpoint de detalle.** `GET /api/v1/providers/%` no se crea aquí. El teléfono, la dirección, la descripción larga y los tags siguen sin viajar.
- **Ningún dato nuevo.** No se crea tabla, ni campo, ni clave de i18n: `invalid_field` ya existe y es la única que este endpoint necesita.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.provider_query.inc`** (nuevo) — un único helper, `myapi_provider_apply_active_conditions($query, $node_alias)`, que añade a una `SelectQuery` el `INNER JOIN` con `field_data_field_license_expiry` y las dos condiciones de proveedor activo (`status = 1`, `field_license_expiry_value >= REQUEST_TIME`). Es la traducción a SQL de `myapi_services_provider_is_active()`, con **un solo sitio** donde vive.
- **`resources/service_category.resource.inc`** (modificar) — `myapi_service_category_provider_counts()` deja de escribir el `INNER JOIN` y las dos condiciones a mano y llama al helper. **Su respuesta no cambia en un byte**: mismo SQL, mismos conteos, misma suite en verde.
- **`resources/provider.resource.inc`** (modificar) — tres funciones nuevas junto a las tres de galería, sin tocar ninguna de ellas:
  - `myapi_provider_dispatch()` — solo `GET`, resto `405`.
  - `myapi_provider_list()` — token, parseo de filtro/orden/paginación, conteo, consulta de la página y respuesta.
  - `myapi_provider_build_item()` — el mapeo de una fila al ítem de la respuesta.

  Más dos privadas de consulta, `myapi_provider_count()` y `myapi_provider_fetch()`, con el mismo reparto que `myapi_bulletin_count()` / `myapi_bulletin_fetch()`.
- **`myapi.module`** (modificar) — una entrada en `hook_menu()`: `api/v1/providers`, `MENU_CALLBACK`, `access callback = TRUE`, junto a las dos de galería de SPEC 82.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.provider_query.inc`.
- **Pruebas unitarias**:
  - `tests/unit/ProviderListEndpointTest.php` (nuevo) — el dispatcher, el `401`, el filtro, las cuatro combinaciones de orden, los nulos al final, la paginación, la forma exacta del ítem y el bloque `pagination`.
  - Ampliación de la suite de `service_category` — que los conteos siguen siendo idénticos con el helper de por medio, y que sigue excluyendo despublicados y caducados.
- **`docs/provider.md`** (nuevo) — la ruta con la plantilla de `CLAUDE.md`, la tabla de parámetros, el ejemplo de respuesta, la tabla de errores y la nota de que las imágenes se piden por la ruta de galería de SPEC 82.
- **`docs/services-install.md`** (modificar) — una línea: la regla de proveedor activo pasa de «duplicada en PHP y en SQL» a «una vez en PHP, una vez en SQL, y la de SQL vive en `includes/myapi.provider_query.inc`».
- `drush cc all` al final. **No hay `hook_update_N`**: este spec no toca la instalación.

**Fuera de alcance (para specs futuros):**

- **`GET /api/v1/providers/%`**, el detalle. Sin él, el teléfono, la dirección, la descripción larga (`field_services_desc`), los tags (`field_tags`) y la relación con usuarios (`field_provider_users`) siguen sin viajar a la app.
- **Cualquier escritura.** No se crea, edita ni borra un proveedor desde la app. Sigue siendo el operador desde el back office (SPEC 77/78).
- **La imagen de portada en el ítem.** La tarjeta del listado no trae miniatura: la galería es privada (SPEC 82) y cada imagen cuesta una petición autenticada. La app pide `/api/v1/providers/%/gallery` cuando la necesite.
- **Filtro por más de una categoría a la vez**, por tag, por rango de tarifa, por calificación mínima, o búsqueda de texto sobre el título.
- **Ordenar por cualquier otra cosa** — título, fecha de alta, número de calificaciones. Solo `rating_avg` y `hourly_rate`.
- **`limit=-1`** para traerlo todo, que sí admiten pagos, recibos y gastos. Un marketplace no se pinta entero.
- **Acotar por condominio.** Un proveedor no está relacionado con ningún condominio en el modelo de datos de hoy, y decidir esa relación es un spec propio. Todo token válido ve el mismo listado, que es la regla que ya sigue `/api/v1/service-categories`.
- **Extraer el parseo de `?page` / `?limit` a un helper compartido.** Hoy está copiado en seis resources; este spec lo copia una séptima vez a propósito y no abre esa refactorización, que tocaría seis endpoints en producción para no cambiar ninguna respuesta.
- **Índices SQL propios** sobre `field_data_field_rating_avg` o `field_data_field_hourly_rate`. Está en Riesgos: es la primera consulta del módulo que ordena por un campo sin índice útil.
- **Caché de la respuesta.** Ni `ETag`, ni `304`, ni caché de página.
- **Claves de i18n nuevas.** `invalid_field` (SPEC 26) es la única que este endpoint usa.
- **Recalcular `field_rating_avg` / `field_rating_count`.** Este spec los **lee**; quién los escribe es el spec del flujo de calificaciones, que no existe.

Dos decisiones dentro del alcance que conviene ver ya, porque son las que se pueden discutir:

- **Se modifica un endpoint implementado (`service-categories`) para no escribir la regla de activo por tercera vez.** Es la regla 3 de `CLAUDE.md`, y el propio SPEC 79 dejó escrito en un comentario que las dos mitades «tienen que moverse juntas». La alternativa —copiar el `WHERE` una vez más— deja tres verdades que sincronizar y el primer síntoma de divergencia sería una categoría que dice «3 proveedores» y un listado que devuelve 4. El precio es real: un fichero en producción se toca para no cambiar su respuesta.
- **Las funciones de consulta del listado se quedan en el resource, no en el include.** Solo las llama este endpoint. Al include va únicamente lo que tiene dos consumidores, que es exactamente el criterio con el que SPEC 82 creó `includes/myapi.provider_files.inc`.

---

## Modelo de datos

**Este spec no crea ni modifica ninguna estructura de datos.** No hay `hook_schema()`, ni campo nuevo, ni instancia, ni vocabulario, ni `hook_update_N`. Todo lo que sigue es el **contrato de una respuesta** y la forma de la consulta que la produce.

### Parámetros de la petición

| Parámetro | Valores | Por defecto | Valor inválido |
|---|---|---|---|
| `page` | entero ≥ 1 | `1` | Silencio: cae al defecto |
| `limit` | entero 1–50 | `20` | Silencio: cae al defecto, y por encima de 50 se recorta a 50 |
| `order_by` | `rating_avg` \| `hourly_rate` | `rating_avg` | Silencio: cae al defecto |
| `sort` | `asc` \| `desc` | `desc` | Silencio: cae al defecto |
| `category_id` | entero positivo (`tid`) | ausente = sin filtro | **`422 invalid_field`** con `@field = category_id` |

El `category_id` es el **único** parámetro que puede producir un `422`, y la asimetría es deliberada: `?category_id=abc` es una petición malformada, mientras que `?order_by=precio` es un valor que el endpoint simplemente no conoce. Es el mismo reparto que ya hace `resources/bulletin.resource.inc`, que devuelve `422` por un `condominium_id` malformado y ninguna queja por un `?sort` desconocido.

Y una vez que el `category_id` **es** un entero positivo, ya no hay más juicios: un `tid` que no existe, o que existe en otro vocabulario, o que no tiene ningún proveedor, responden los tres lo mismo — `200` con `providers: []`. El endpoint filtra, no valida el catálogo; comprobar que el término existe sería una consulta extra para responder `422` donde `200` con lista vacía dice lo mismo y no rompe la app.

### Forma de la respuesta

```json
{
  "success": true,
  "data": {
    "providers": [
      {
        "id": 41,
        "title": "Plomería Torres",
        "categories": [
          { "id": 7, "code": "plomeria", "name": "Plomería" },
          { "id": 9, "code": "gasfiteria", "name": "Gasfitería" }
        ],
        "rating_avg": 4.75,
        "rating_count": 12,
        "short_description": "Destapes y reparaciones, atención en el día.",
        "hourly_rate": 25.5
      }
    ],
    "pagination": { "total": 37, "page": 1, "limit": 20, "total_pages": 2 }
  }
}
```

Las siete claves del ítem, siempre las siete y siempre en ese orden:

| Clave | Tipo | Origen | Vacío |
|---|---|---|---|
| `id` | int | `node.nid` | Nunca |
| `title` | string | `node.title`, por `check_plain()` | Nunca (requerido por Drupal) |
| `categories` | array | `field_categories` → término | `[]` |
| `rating_avg` | float \| **null** | `field_rating_avg` | `null` |
| `rating_count` | int | `field_rating_count` | **`0`**, nunca `null` |
| `short_description` | string | `field_short_description`, por `check_plain()` | `""`, nunca `null` |
| `hourly_rate` | float \| **null** | `field_hourly_rate` | `null` |

Cuatro precisiones sobre esa tabla, que son las que evitan discusiones después:

- **`rating_avg` es `null` y `rating_count` es `0`.** No es una incoherencia: son dos preguntas distintas. «Cuántas calificaciones tiene» siempre tiene respuesta numérica, y cero es esa respuesta. «Cuánto puntúa» no la tiene mientras nadie lo haya calificado, y un `0.0` sería además un valor **fuera de la escala** 1–5 de `myapi_services_star_values()`.
- **`short_description` va por `check_plain()`, no por `myapi_text_to_plain()`.** El campo es `text` sin `text_processing` (SPEC 81): no hay editor rico detrás ni marcado que aplanar, así que escapar es lo correcto. `myapi_text_to_plain()` existe para las descripciones de taxonomía, que sí llevan formato — la distinción está escrita en `includes/myapi.text.inc`.
- **La categoría viaja con las tres claves que la identifican**, no con las seis de `/api/v1/service-categories`: sin `description`, sin `icon_id` y sin `icon_url`. El ícono lo tiene ya la app del grid de categorías, y repetirlo en cada proveedor de cada página es peso muerto.
- **El orden de las categorías dentro de un ítem es el de los deltas** de Field API, el mismo criterio que la galería de SPEC 82. No se reordena alfabéticamente.

El bloque `pagination` es el del módulo, con las mismas cuatro claves y el mismo significado: `total` es el total del conjunto **ya filtrado**, y `total_pages` es `0` cuando `total` es `0`. Una `page` más allá de la última responde `200` con `providers: []`, nunca `404`.

### La consulta

Tres decisiones de forma, y las tres tienen consecuencias visibles:

**1. El filtro por categoría es un `EXISTS` correlacionado, no un `JOIN`.** `field_categories` es de cardinalidad ilimitada: unirla produciría una fila por categoría del proveedor, y un proveedor con tres categorías aparecería tres veces en la página y contaría tres veces en el `total`. La salida habitual —`DISTINCT`— arrastra el `ORDER BY` a la lista de selección y complica la paginación. Un `EXISTS` no multiplica filas:

```php
$sub = db_select('field_data_field_categories', 'fc');
$sub->addExpression('1');
$sub->where('fc.entity_id = n.nid')
  ->condition('fc.entity_type', 'node')
  ->condition('fc.deleted', 0)
  ->condition('fc.field_categories_tid', $category_id);
$query->exists($sub);
```

Es el mismo patrón que la rama `Personalizado` de `myapi_bulletin_visibility_condition()`.

**2. Los cuatro campos del ítem entran por `LEFT JOIN`, nunca por `INNER JOIN`.** Los cuatro son opcionales, y un `INNER JOIN` haría **desaparecer del listado** a todo proveedor sin tarifa o sin calificaciones. Es el error que este apartado existe para prevenir: sería un filtro invisible que nadie pidió.

**3. Los nulos van al final en los dos sentidos**, con una expresión de ordenación previa:

```sql
ORDER BY (r.field_rating_avg_value IS NULL) ASC,   -- los que tienen valor, primero
         r.field_rating_avg_value DESC,             -- el sentido pedido
         n.nid DESC                                 -- desempate estable
```

El primer criterio **no depende de `sort`**: en `asc` y en `desc`, quien no publica el dato queda al final. El desempate por `n.nid DESC` es lo que hace que dos peticiones seguidas de la misma página devuelvan lo mismo — sin él, dos proveedores con `4.50` pueden intercambiarse entre página y página y la app enseñaría uno repetido y perdería otro.

La regla de activo la aplica `myapi_provider_apply_active_conditions()`, y la aplican **por igual la consulta de conteo y la de la página**. Si solo la llevara una, el `total` diría una cosa y las filas otra.

### Las categorías, en una segunda consulta

El listado no pide las categorías dentro de la consulta principal: sería el mismo problema de multiplicación de filas que el filtro esquiva. Se resuelve en **dos consultas por petición, no en una por proveedor** — con los `nid` de la página ya en memoria, una sola consulta sobre `field_data_field_categories` unida a `taxonomy_term_data` y a `field_data_field_category_code` trae todas las categorías de los veinte proveedores, y el agrupado por `nid` se hace en PHP.

Un `tid` referenciado que ya no existe como término (un borrado en el back office que dejó la fila del campo atrás) **no produce un ítem a medias**: la categoría se omite de la lista de ese proveedor, y el proveedor se devuelve igual. Falla cerrado y en silencio, que es lo mismo que hace `myapi_service_category_build_item()` con un ícono a medias.

### Errores

| Código | `error_code` | Cuándo |
|---|---|---|
| 401 | `missing_authorization` | Sin cabecera `Authorization` |
| 401 | `invalid_token` | Token inexistente, revocado, caducado, o de un usuario borrado o bloqueado |
| 422 | `invalid_field` (`@field = category_id`) | `category_id` presente y no es un entero positivo |
| 405 | `method_not_allowed` | Cualquier método que no sea `GET` |

No hay `404`: la colección siempre existe. No hay `403`: cualquier token válido ve el mismo marketplace.

---

## Plan de implementación

1. **`includes/myapi.provider_query.inc` — el helper compartido.** Un solo fichero con una sola función, `myapi_provider_apply_active_conditions(SelectQuery $query, $node_alias = 'n')`, que añade el `INNER JOIN` con `field_data_field_license_expiry` y las dos condiciones (`status = 1`, `field_license_expiry_value >= REQUEST_TIME`). El `@file` dice lo que ahora es cierto: **la regla de proveedor activo tiene exactamente dos hogares** — `myapi_services_provider_is_active()` para el PHP y este fichero para el SQL — y quien cambie el significado de «activo» tiene que tocar los dos. El alias del nodo es un parámetro y no un literal, para que la función no imponga cómo se llama la tabla en la consulta que la llama. Con su `files[]` en `myapi.info`. *Verificación: `php -l`.*

2. **`resources/service_category.resource.inc` — pasar por el helper.** `myapi_service_category_provider_counts()` borra su `innerJoin` de `field_data_field_license_expiry` y sus dos `condition()`, y llama a la función del paso 1; se añade el `module_load_include()` correspondiente y se reescribe el comentario largo que hoy explica la duplicación, que deja de ser cierto. **Nada más de ese fichero se toca.** *Verificación, y es la que importa de este paso: la suite de `service_category` completa en verde sin cambiar una sola expectativa, y `git diff` acotado a esa función y a la línea del `module_load_include()`.*

   Va **antes** que todo lo nuevo, por la misma razón que SPEC 82 puso el `hook_file_download()` antes del endpoint: es el único paso que puede romper algo que ya está en producción, y conviene verificarlo aislado, sin código nuevo encima que enturbie el diagnóstico.

3. **`resources/provider.resource.inc` — `myapi_provider_build_item()`.** El mapeo de una fila a las siete claves, en su orden, con `check_plain()` sobre `title` y `short_description`, los dos `float|null`, el `int` de `rating_count` y el array de categorías que recibe ya construido. Recibe la lista de categorías como argumento en vez de consultarla: es lo que mantiene la promesa de dos consultas por petición y no una por proveedor. *Verificación: `php -l`; la función aún no la llama nadie.*

4. **`resources/provider.resource.inc` — las dos consultas.** `myapi_provider_count($category_id)` y `myapi_provider_fetch($category_id, $page, $limit, $order_by, $sort)`, las dos apoyadas en el helper del paso 1 y en el mismo `EXISTS` para el filtro. La de `fetch` lleva los cuatro `LEFT JOIN` y el `ORDER BY` de tres criterios. Un comentario junto a los `LEFT JOIN` que diga por qué **no** son `INNER`, y otro junto al primer criterio del `ORDER BY` que diga que no depende de `sort` a propósito. Más `myapi_provider_categories_by_nid(array $nids)`, la segunda consulta, que devuelve el mapa `nid => [categorías]` con los `nid` sin categorías presentes y vacíos. *Verificación: `php -l`.*

5. **`resources/provider.resource.inc` — el dispatcher y el listado.** `myapi_provider_dispatch()` (solo `GET`, resto `405`, con la comprobación del método **antes** de la autenticación, como los otros dos dispatchers del fichero) y `myapi_provider_list()`: `myapi_auth_require_access_token()`, parseo de los cinco parámetros con la tabla del modelo de datos, el `422` del `category_id` malformado, conteo, `fetch`, la consulta de categorías con los `nid` de la página, el mapeo y `myapi_respond(['providers' => ..., 'pagination' => ...], 200)`. *Verificación: `php -l`; el endpoint aún no es alcanzable.*

6. **`myapi.module` — la ruta.** `api/v1/providers` con `page callback = 'myapi_provider_dispatch'`, `MENU_CALLBACK` y `access callback = TRUE`, junto a las dos de galería. Después, `drush cc all`. *Verificación con curl: sin cabecera → `401`; con token → `200` con la primera página; `?category_id=abc` → `422`; `?category_id=<tid válido>` → solo proveedores de esa categoría; `?order_by=hourly_rate&sort=asc` → tarifas de menor a mayor con los `null` al final; `POST` → `405`.*

7. **Pruebas.**
   - `tests/unit/ProviderListEndpointTest.php` (nuevo): el dispatcher solo acepta `GET`; `401` sin cabecera y con token inválido; `422 invalid_field` con `category_id` no numérico, negativo, cero, vacío y array, y `200` con lista vacía con un `tid` inexistente; las cuatro combinaciones de `order_by` × `sort` producen el `ORDER BY` esperado; el primer criterio de nulos **no** cambia con `sort`; el desempate `n.nid DESC` está siempre; `page` y `limit` fuera de rango caen a sus defectos sin `422` y `limit=51` se recorta a 50; `limit=-1` **no** trae todo, cae a 20; el bloque `pagination` con `total_pages = 0` cuando no hay resultados; la forma exacta del ítem (siete claves, en orden, con `rating_avg` `null` y `rating_count` `0` en un proveedor sin calificar, y `short_description` `""`); un proveedor sin categorías responde `[]` y no desaparece; un `tid` huérfano se omite sin tirar el ítem; el filtro por categoría **no** duplica un proveedor que lleva la misma categoría en dos deltas; y los dos guards de la regla de activo — un proveedor despublicado y uno caducado no aparecen, uno que caduca hoy sí.
   - Ampliación de la suite de `service_category`: los conteos siguen excluyendo despublicados y caducados **a través del helper**, y el `>=` sigue siendo `>=`.
   - Un test sobre el helper del paso 1 en aislamiento: aplica las dos condiciones y el join, y respeta el alias que se le pase.

   *Verificación: suite completa en verde.*

8. **Documentación.** `docs/provider.md` nuevo, con la plantilla de `CLAUDE.md`: la ruta, la tabla de los cinco parámetros, el ejemplo de respuesta, la tabla de errores, la nota de que las imágenes se piden por `/api/v1/providers/%/gallery` (SPEC 82) y la de que `rating_avg` puede ser `null` mientras `rating_count` es `0`. Y una línea en `docs/services-install.md`: dónde vive ahora la mitad SQL de la regla de activo.

9. **Aplicar y verificar.** `drush cc all` y recorrer los criterios de aceptación contra el sitio, con un juego de datos que tenga al menos: un proveedor con dos categorías, uno sin ninguna, uno sin tarifa, uno sin calificaciones, uno despublicado y uno con la habilitación caducada.

**Nota:** no se toca `myapi.install`, ni `hook_schema()`, ni `includes/myapi.services_common.inc`, ni `includes/myapi.i18n.inc`, ni `includes/myapi.provider_files.inc`, ni las tres funciones de galería de `resources/provider.resource.inc`, ni ningún otro fichero de `resources/` fuera del paso 2.

Dos cosas del orden que no son cosméticas:

- **El paso 2 es el único con riesgo de regresión y va el segundo**, verificado y confirmado antes de que exista una sola línea del listado.
- **El paso 3 va antes que las consultas** porque fija la forma de la fila que las consultas tienen que producir. Escribir primero el SQL y después el mapeo lleva a un `SELECT *` y a un ítem que arrastra columnas que nadie pidió.

---

## Criterios de aceptación

**Leyenda.** `[ ]` es el estado inicial de todo criterio en un spec en `Draft`. Al implementar se marcan `[x]` los que cierra la suite unitaria o la inspección del repositorio, dejando constancia expresa de los que exigen un Drupal arrancado.

Los que quedan en `[ ]` llevan **(sitio)** y son exactamente los que la suite no puede cerrar: se verifican en el paso 9 contra el servidor, después de `scripts/deploy.sh` y `drush cc all`.

La suite que los cierra son 75 tests nuevos: `tests/unit/ProviderListEndpointTest.php` (61), `tests/unit/ProviderActiveConditionsTest.php` (13) y uno añadido a la suite de `service_category`. Total del módulo: 1400 tests, 5891 aserciones, en verde.

**Autenticación y método**

- [x] Sin cabecera `Authorization` → `401 missing_authorization`.
- [x] Con token inexistente, revocado, caducado, o de un usuario borrado o bloqueado → `401 invalid_token`.
- [x] Con cualquier token válido → `200`, sin mirar rol, condominio ni vivienda.
- [x] `POST`, `PUT` y `DELETE` sobre `/api/v1/providers` → `405 method_not_allowed`, **sin token** y con token: el método se comprueba antes que la autenticación.

**Filtro por categoría**

- [x] `?category_id=<tid con proveedores activos>` devuelve **solo** proveedores que llevan ese término en `field_categories`.
- [x] Un proveedor que lleva la misma categoría en **dos deltas** aparece **una vez** en la lista y cuenta **una vez** en `total`.
- [x] `?category_id=<tid inexistente>` → `200` con `providers: []` y `total: 0`.
- [x] `?category_id=<tid de otro vocabulario>` → `200` con `providers: []`, no `422`. *(Mismo camino de código que el anterior: para el `EXISTS`, un `tid` de otro vocabulario es un `tid` que ningún proveedor lleva.)*
- [x] `?category_id=abc`, `?category_id=-3`, `?category_id=0`, `?category_id=` y `?category_id[]=1` → `422 invalid_field` con `@field = category_id`.
- [x] Sin `category_id` se devuelven todos los proveedores activos.
- [x] El `total` del bloque `pagination` refleja el conjunto **ya filtrado**, no el total de proveedores del sitio.

**Orden**

*Nota: la suite ejerce el `ORDER BY` sobre el emulador de consultas de `tests/unit/bootstrap.php`, que responde `(x IS NULL)` como 1/0 igual que SQL. **Confirmado contra el MySQL del sitio**: `ORDER BY (v IS NULL) ASC, v DESC|ASC` sobre un conjunto con nulos los deja al final en los dos sentidos.*

- [x] Por defecto, sin parámetros, el listado sale ordenado por `rating_avg` **descendente**.
- [x] Las cuatro combinaciones de `order_by` (`rating_avg`, `hourly_rate`) × `sort` (`asc`, `desc`) ordenan por el campo y en el sentido pedidos.
- [x] Con `?order_by=hourly_rate&sort=asc`, los proveedores **sin tarifa** salen al final, no al principio.
- [x] Con `?order_by=hourly_rate&sort=desc`, los proveedores sin tarifa salen **también** al final.
- [x] Lo mismo para `rating_avg` en los dos sentidos: sin calificaciones, siempre al final.
- [x] Dos proveedores con el **mismo** valor salen siempre en el mismo orden entre dos peticiones seguidas, y el de `nid` mayor va primero.
- [x] `?order_by=title`, `?order_by=`, `?sort=DESC` en mayúsculas y `?sort=descendente` caen a los defectos con `200`, nunca `422`.

**Paginación**

- [x] `?limit=5` devuelve como mucho cinco ítems y `pagination.limit` vale `5`.
- [x] `?limit=51` devuelve como mucho **50** ítems y `pagination.limit` vale `50`.
- [x] `?limit=0`, `?limit=-1`, `?limit=abc` y `limit` ausente devuelven 20 ítems como mucho: **`-1` no trae todo**.
- [x] `?page=2` devuelve la segunda página, sin repetir ni saltarse ningún ítem de la primera.
- [x] `?page=0`, `?page=-1` y `?page=abc` devuelven la primera página con `200`.
- [x] Una `page` más allá de la última → `200` con `providers: []`, nunca `404`.
- [x] Con `total = 0`, `total_pages` vale `0`, no `1`.
- [x] `total_pages` es `ceil(total / limit)` para un conjunto que no cabe exacto en una página.

**Forma del ítem**

- [x] Cada ítem trae exactamente **siete** claves, en el orden `id`, `title`, `categories`, `rating_avg`, `rating_count`, `short_description`, `hourly_rate`.
- [x] `id` es entero JSON (`41`), no cadena.
- [x] Un proveedor **sin calificaciones** responde `rating_avg: null` y `rating_count: 0`.
- [x] Un proveedor **sin tarifa** responde `hourly_rate: null`, y **aparece en el listado**: no lo excluye ningún join.
- [x] Un proveedor **sin descripción corta** responde `short_description: ""`, nunca `null`.
- [x] Un proveedor **sin categorías** responde `categories: []`, y aparece en el listado.
- [x] Cada categoría trae exactamente tres claves — `id`, `code`, `name` — y ninguna más: sin `description`, sin `icon_id`, sin `icon_url`.
- [x] El `id` y el `code` de una categoría coinciden con los que devuelve `GET /api/v1/service-categories` para el mismo término. *(Inspección: los dos mapeos hacen `(int) $tid` y `check_plain()` sobre el mismo `field_category_code_value`.)*
- [x] Las categorías de un proveedor salen en el orden de los deltas del campo, no alfabético.
- [x] **(sitio, no ejercido)** Un `tid` referenciado cuyo término ya no existe se **omite** de `categories`, y el proveedor se devuelve igual. *La suite fija la forma —el join con `taxonomy_term_data` es `INNER`— pero el emulador registra los joins sin resolverlos, así que no puede hacer que uno no case: el descarte lo hace la base de datos. En el servidor no hay ni un `tid` huérfano (consulta comprobada: 0 filas), y crear uno sería corromper datos de producción a propósito. Queda sin ejercer, con la forma fijada.*
- [x] `title` y `short_description` con `<b>` o `&` viajan escapados por `check_plain()`.
- [x] Ningún ítem trae teléfono, dirección, `field_services_desc`, tags ni imágenes.
- [x] La respuesta completa lleva `providers` y `pagination`, y nada más, dentro de `data`.

**La regla de proveedor activo**

- [x] Un proveedor **despublicado** no aparece en el listado, con filtro y sin él.
- [x] Un proveedor con `field_license_expiry` en el **pasado** no aparece.
- [x] Un proveedor cuya habilitación vence **hoy** (`field_license_expiry == REQUEST_TIME`) **sí** aparece: la comparación es `>=`.
- [x] Un proveedor **sin** `field_license_expiry` no aparece: el `INNER JOIN` lo deja fuera, igual que la regla en PHP responde `FALSE`. *(La suite lo modela como el `NULL` que devolvería un `LEFT JOIN`, que ninguna comparación acepta tampoco.)*
- [x] El `total` cuenta exactamente los mismos proveedores que devuelven las páginas: conteo y consulta aplican la regla por igual.
- [x] **(sitio, verificado)** Para una categoría dada, el número de proveedores que devuelve este endpoint sin paginar coincide con el `providers_count` que devuelve `GET /api/v1/service-categories?with_counts=1` para esa misma categoría. *Cruce sobre el servidor: `electricidad` (31) → 2 y 2, `carpinteria` (35) → 1 y 1, `cerrajeria` (37) → 1 y 1, y `climatizacion` (36) → 0 en los dos, que es el proveedor con la habilitación caducada. Los dos endpoints coinciden también en excluirlo.*

**No regresión**

- [x] **(sitio, parcial)** `GET /api/v1/service-categories` devuelve lo mismo que antes, con y sin `?with_counts=1`, en un sitio con un proveedor caducado. *Verificado contra el contrato documentado —6 claves sin el parámetro, 7 con él, los ocho conteos correctos y coherentes con el listado— y no contra una captura previa: el snapshot anterior al despliegue no se tomó. La prueba fuerte de que el SQL no cambió es la suite de 91 tests sin una expectativa tocada.*
- [x] La suite de `service_category` pasa completa **sin cambiar una sola expectativa**. *(91 tests; el único cambio del fichero es un `require_once`, porque el `module_load_include()` del bootstrap es un no-op.)*
- [x] `GET /api/v1/providers/%/gallery` y `GET /api/v1/providers/%/gallery/%` siguen respondiendo igual: las tres funciones de galería quedan sin tocar y su suite sigue en verde. *(`git diff` sobre `resources/provider.resource.inc`: lo único borrado son líneas del `@file`.)*
- [x] **(sitio, parcial)** Las imágenes de reclamos y la galería siguen viéndose en el back office: este spec no toca `myapi_file_download()`. *(`git diff` vacío sobre `includes/myapi.provider_files.inc` y sobre el hook. En el servidor, `GET /api/v1/providers/%/gallery` responde `200` y `.../gallery/999999` responde `404 file_not_found`. La página del back office no se abrió en un navegador.)*
- [x] Ningún otro endpoint `api/v1/...` cambia: `git diff` vacío en `resources/` salvo `provider.resource.inc` y la función de conteo de `service_category.resource.inc`. *(Tres hunks en ese fichero: el `module_load_include()`, el docblock y el cuerpo de la función.)*
- [x] `myapi.install` queda sin tocar: `git diff` vacío. No hay `hook_update_N` nuevo y `myapi_update_7029` sigue siendo el último.
- [x] `includes/myapi.i18n.inc` queda sin tocar: ninguna clave nueva, y los dos catálogos siguen teniendo las mismas.
- [x] Ningún rol gana ni pierde permisos. *(Inspección: ni `hook_permission()` ni `myapi.install` se tocan; la ruta lleva `access callback = TRUE` como todas las de `api/v1`. La comparación de `/admin/people/permissions` es del sitio.)*
- [x] La suite unitaria pasa completa, con el fichero de test nuevo incluido.
- [x] **(sitio, verificado)** `drush cc all` no reporta errores y `api/v1/providers` queda en `menu_router` **sin** desplazar a las dos rutas de galería. *Las tres filas conviven, cada una con su `page_callback` y el mismo `include_file`. `drush updb` confirma «No database updates required».*

Tres criterios que parecen de relleno y son los que de verdad vigilan este spec:

- **«Un proveedor sin tarifa aparece en el listado.»** Es el fallo que un `INNER JOIN` mal puesto produce: no da error, no rompe ningún test de forma, simplemente hace desaparecer proveedores del marketplace y nadie lo nota hasta que uno llama preguntando por qué no sale.
- **«El conteo de la categoría coincide con el listado de la categoría.»** Es el criterio que justifica el paso 2 del plan. Si alguna vez falla, es que las dos mitades de la regla volvieron a divergir.
- **«Un proveedor con la misma categoría en dos deltas aparece una vez.»** Es el bug clásico del `JOIN` sobre un campo multivaluado, y es el que el `EXISTS` esquiva.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Dónde vive el listado | **`resources/provider.resource.inc`**, junto a la galería | Un `resources/provider_list.resource.inc` aparte | Regla 2 de `CLAUDE.md`: un recurso, un fichero. Es además lo que SPEC 82 dejó anunciado por escrito en su `@file` cuando creó el fichero solo con la galería. |
| La regla de proveedor activo | **Extraída a `includes/myapi.provider_query.inc`** y compartida con `service-categories` | Escribir el `WHERE` una tercera vez en el resource de proveedores | Elección explícita del usuario. La regla ya estaba en dos sitios y SPEC 79 dejó escrito en un comentario que «las dos mitades tienen que moverse juntas»; una tercera copia convierte esa advertencia en una promesa que nadie puede cumplir. El precio —tocar un endpoint implementado para no cambiar su respuesta— se paga una vez, con la suite existente sin modificar como red. |
| Qué hace el helper | Añade el **join y las dos condiciones** a una `SelectQuery` que le pasan | Devolver un array de condiciones; o devolver la consulta entera ya construida | Devolver condiciones sueltas dejaría el join fuera, que es justo la mitad que se olvida. Devolver la consulta entera obligaría a los dos consumidores a la misma forma, y uno cuenta agrupando por categoría mientras el otro pagina. |
| Alias del nodo en el helper | **Parámetro** con defecto `'n'` | Literal `'n'` dentro de la función | Una función compartida que impone cómo se llaman las tablas de quien la llama es una función que se rompe la segunda vez que alguien la usa. Cuesta un argumento. |
| Incluir `status = 1` además de la caducidad | **Sí**, las dos condiciones | Solo `field_license_expiry`, que es lo único que se pidió literalmente | Elección explícita del usuario tras planteárselo. Es la regla ya escrita en `myapi_services_provider_is_active()` y la que aplica el conteo de categorías: sin `status`, la tarjeta de una categoría diría «3 proveedores» y el listado devolvería 4. Un proveedor despublicado está suspendido a mano por el operador, y el listado tiene que respetarlo. |
| Comparación de la caducidad | **`>=`** | `>` estricto | Elección explícita del usuario. Es lo que ya hacen la función pura y el conteo; con `>`, un proveedor cuya habilitación vence hoy desaparecería del marketplace un día antes en un endpoint y no en el otro. |
| Parámetros de orden | **`?order_by=` + `?sort=`** | Un solo `?sort=rating_desc\|rate_asc\|...` con cuatro valores | Elección explícita del usuario. Separa qué de cómo, admite un tercer campo sin inventar sintaxis, y mantiene el `?sort=asc\|desc` que ya usan bancos, formas de pago, categorías y boletines con el mismo significado. |
| Qué se puede ordenar | **Solo `rating_avg` y `hourly_rate`** | Admitir también `title` o `created` | Es lo que se pidió. Un `order_by` abierto obliga a una lista blanca igual de larga y a decidir el `LEFT JOIN` de cada campo; añadir uno cuando haga falta es una línea. |
| Los nulos al ordenar | **Siempre al final**, en los dos sentidos | Dejarlos donde los ponga MySQL (primeros en `ASC`, últimos en `DESC`) | Elección explícita del usuario. Un listado ordenado por precio de menor a mayor que **abre** con los que no publican precio se lee como un error del servidor, no como un orden. Cuesta un criterio más en el `ORDER BY` y hace la respuesta explicable. |
| Desempate | **`n.nid DESC`** | Ninguno; o `title ASC` | Sin desempate, dos proveedores con el mismo `4.50` pueden intercambiarse entre la página 1 y la 2, y la app enseña uno repetido y pierde otro — el fallo de paginación más difícil de reproducir que existe. `nid DESC` es estable, no necesita join y significa algo: a igual calificación, primero el más nuevo. |
| Forma del filtro por categoría | **`?category_id=` con el `tid`** | `?category_code=` con `field_category_code`; o las dos | Elección explícita del usuario. Es el `id` que ya devuelve `/api/v1/service-categories`, así que la app filtra con lo que acaba de recibir y no traduce nada. El `code` sigue viajando en cada ítem, por si algún día se prefiere. |
| Una categoría o varias | **Una sola** | Varias separadas por coma | Recomendación aceptada. Varias obliga a decidir si el filtro es `AND` u `OR`, a validar cada elemento y a definir qué pasa con una lista mixta de válidos e inválidos. La app pinta un grid donde se toca **una** categoría. |
| Cómo se aplica el filtro | **`EXISTS` correlacionado** | `INNER JOIN` con `DISTINCT` | `field_categories` es de cardinalidad ilimitada: el join multiplica filas y un proveedor con tres categorías aparecería tres veces y contaría tres veces. El `DISTINCT` lo tapa a cambio de arrastrar el `ORDER BY` a la selección. El `EXISTS` no multiplica nada, y es el patrón que ya usa la rama `Personalizado` de boletines. |
| `category_id` inexistente | **`200` con lista vacía** | `422`; o `404` | Recomendación aceptada. El endpoint filtra, no valida el catálogo: comprobar que el término existe es una consulta extra para responder un error donde una lista vacía dice exactamente lo mismo. Y evita que la app tenga que distinguir «categoría borrada» de «categoría sin proveedores», que para ella son la misma pantalla. |
| `category_id` malformado | **`422 invalid_field`** | Ignorarlo en silencio, como `order_by` y `sort` | La asimetría es deliberada y tiene precedente: `resources/bulletin.resource.inc` devuelve `422` por un `condominium_id` malformado y no se queja de un `?sort` desconocido. Un identificador que no es un identificador es una petición mal construida; un valor de enumeración desconocido es solo un valor que no conocemos. |
| Paginación | **`?page` + `?limit` (1–50, defecto 20)** y bloque `pagination` de cuatro claves | Cursor; o `offset`/`limit` | Recomendación aceptada. Es el patrón de los seis listados que ya tiene el módulo, y la app ya sabe leerlo. Un cursor sería mejor para un conjunto que cambia mientras se pagina, y este no lo hace. |
| `limit=-1` | **No admitido**: cae a 20 | Admitirlo, como pagos, recibos y gastos | Recomendación aceptada. Aquellos tres devuelven los datos de **una** vivienda, un conjunto acotado por naturaleza. El marketplace es del sitio entero y no tiene techo. |
| Valores inválidos de paginación y orden | **Silencio**: caen al defecto | `422` | Es la regla ya escrita en boletines y pagos: «invalid or absent values fall back to their defaults silently». Cambiarla aquí obligaría a la app a manejar dos criterios según el endpoint. |
| Forma de `categories` en el ítem | **`{id, code, name}`** | Solo los `tid`; o las seis claves de `/api/v1/service-categories` | Elección explícita del usuario. Solo los `id` obliga a la app a tener el catálogo cargado y cruzado para pintar una tarjeta. Las seis claves repiten `description`, `icon_id` e `icon_url` en cada proveedor de cada página, que es peso muerto: el ícono ya lo tiene la app del grid. |
| Las categorías, cómo se cargan | **Segunda consulta** para toda la página | Dentro de la consulta principal; o una consulta por proveedor | Dentro de la principal es el mismo problema de multiplicación de filas que el filtro esquiva. Una por proveedor son veintiuna consultas por petición. Dos consultas fijas, sin importar el tamaño de la página, es el patrón que `myapi_bulletin_list()` ya usa con `file_load_multiple()`. |
| Un `tid` huérfano | **Se omite** la categoría; el proveedor se devuelve | Devolver la categoría con `name` vacío; o excluir el proveedor | Falla cerrado y en silencio, igual que `myapi_service_category_build_item()` trata un ícono a medias. Excluir el proveedor haría que un borrado de término en el back office lo hiciera desaparecer del marketplace sin que nadie relacione una cosa con la otra. |
| `rating_avg` sin calificaciones | **`null`** | `0.0` | Recomendación aceptada. `0` está **fuera** de la escala 1–5 de `myapi_services_star_values()`, así que sería un valor imposible viajando como si fuera real, y la app lo pintaría como cinco estrellas vacías en vez de como «sin calificar». |
| `rating_count` sin calificaciones | **`0`** | `null`, por simetría con `rating_avg` | No son la misma pregunta. «Cuántas calificaciones tiene» siempre tiene respuesta y es cero; «cuánto puntúa» no la tiene. La simetría aparente costaría que la app tuviera que tratar `null` como cero en cada tarjeta. |
| `short_description` | **`check_plain()`** | `myapi_text_to_plain()`, como la descripción de categoría | El campo es `text` sin `text_processing` (SPEC 81): no hay editor rico detrás ni marcado que aplanar. `myapi_text_to_plain()` existe para la descripción de taxonomía, que sí lo lleva, y su propio `@file` explica cuándo aplica. |
| `hourly_rate` en JSON | **`float`, `null` cuando está vacío** | Cadena `"25.50"` | Elección explícita del usuario. Es lo que ya hacen `amount` en gastos, recibos y pagos, y `area_m2` en viviendas. El símbolo de moneda lo pone la app: el `prefix = '$ '` de SPEC 81 nunca viaja. |
| Portada en la tarjeta | **Ninguna imagen** en el ítem | La primera imagen de `field_gallery` | La galería es privada (SPEC 82): cada imagen es una petición autenticada por PHP. Una página de veinte proveedores serían veinte arranques de Drupal solo para las miniaturas del listado. La app pide la galería cuando abre una ficha. |
| Endpoint de detalle | **Fuera de alcance** | Estrenar aquí `GET /api/v1/providers/%` | El listado y el detalle tienen contratos distintos: el detalle trae teléfono, dirección, descripción larga y tags, y con ellos llega la pregunta de qué ve quién. Cabe en su propio spec y este objetivo ya cabe en una frase. |
| Alcance por condominio | **Ninguno**: todo token válido ve lo mismo | Filtrar por el condominio del usuario | Un proveedor no está relacionado con ningún condominio en el modelo de hoy. Es la misma decisión que tomó SPEC 79 para las categorías y SPEC 82 para la galería, y por el mismo motivo. |
| Parseo de `?page` / `?limit` | **Copiado** una séptima vez en el resource | Extraerlo a un helper compartido de una vez | Tentador y fuera de alcance: extraerlo tocaría seis endpoints en producción para no cambiar ninguna respuesta. Es una refactorización con su propio spec, no un efecto secundario de añadir un listado. La extracción que **sí** se hace aquí es la de la regla de activo, porque ese cambio tiene un consumidor nuevo y un fallo con síntoma visible. |
| Claves de i18n | **Ninguna nueva** | Una `invalid_category` propia | `invalid_field` con `@field = category_id` ya dice exactamente eso y existe desde SPEC 26. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Romper los conteos de `/api/v1/service-categories` al extraer la regla de activo.** Es el único riesgo del spec que afecta a un endpoint con datos en producción: un helper que olvide el `deleted = 0` del join, o que cambie el `>=` por `>`, infla o desinfla silenciosamente el número que la app pinta en cada tarjeta del grid. | El paso 2 del plan va aislado y **antes** de todo lo nuevo, y su verificación es que la suite de `service_category` pase **sin cambiar una sola expectativa** — si el helper altera el SQL, esos tests son los que caen. Se añaden dos casos que fijan las dos mitades por separado (despublicado, caducado) y uno que fija el `>=` en el límite exacto. Y el criterio cruzado de aceptación —el conteo de una categoría coincide con lo que devuelve el listado de esa categoría— vigila para siempre que las dos no vuelvan a divergir. |
| **Es la primera consulta del módulo que ordena por un campo sin índice útil.** `field_data_field_rating_avg` y `field_data_field_hourly_rate` solo tienen los índices que Drupal crea por su cuenta (`entity_id`, `bundle`), ninguno sobre el valor. Con cuatro `LEFT JOIN` y un `ORDER BY` de tres criterios, MySQL resuelve con `filesort` sobre el conjunto entero de proveedores activos antes de aplicar el `LIMIT`. | Aceptado hoy, y anotado para que nadie lo descubra en producción. Con decenas o pocos cientos de proveedores es irrelevante; el `filesort` duele en el orden de las decenas de miles, que es un tamaño que este marketplace no tiene ni va a tener pronto. Crear índices está **fuera de alcance** por decisión, porque afinarlos sin conocer el plan real de ejecución es adivinar: si algún día duele, la salida es un `EXPLAIN` sobre el sitio y un `hook_update_N` con el índice que ese `EXPLAIN` pida. |
| **La paginación profunda paga el mismo precio, y creciendo.** `?page=50&limit=50` obliga a MySQL a ordenar y descartar 2.450 filas para devolver 50. Es inherente al `OFFSET`, no a esta implementación. | Acotado por el propio producto: nadie navega a la página 50 de un marketplace, se filtra por categoría. Si llegara a hacer falta, la salida es paginación por cursor, que es otro contrato y otro spec. |
| **`total` y las filas pueden discrepar entre dos consultas.** Si un proveedor se publica o caduca entre el `COUNT` y el `SELECT` de la página, el `total` dice una cosa y las filas otra. | Aceptado, y es el comportamiento que ya tienen los seis listados del módulo. La ventana es de milisegundos y el peor efecto es un `total` desviado en uno durante una petición. Envolverlo en una transacción para un endpoint de lectura sería pagar aislamiento para no ganar nada. |
| **La app pinta `rating_avg: null` como cero estrellas.** Es el error que la app va a cometer, y el síntoma es un proveedor nuevo que aparece con la peor calificación posible del marketplace en vez de como «sin calificar». | `docs/provider.md` lo dice con el caso concreto y la salida: la clave que decide es `rating_count`, que vale `0` justo en ese caso. Es también el motivo por el que las dos claves viajan siempre, y no una sola. |
| **La regla de activo se olvida en el tercer consumidor.** El helper resuelve que hoy haya un solo sitio con el SQL, pero no impide que el spec del flujo de solicitudes escriba su propio `WHERE` para «proveedores que pueden ofertar». | La regla queda escrita en el `@file` de `includes/myapi.provider_query.inc`, en `docs/services-install.md` y en el docblock de `myapi_services_provider_is_active()`, que es donde mira quien busca «qué es un proveedor activo». Es el mismo mecanismo de regla de mantenimiento que SPEC 65 dejó para los ficheros y SPEC 82 copió, que es el precedente de que funciona. |
| **La consulta lee `field_data_*` directamente y asume el almacenamiento actual.** Si algún día un campo pasa a otro backend de almacenamiento o se traduce, las tablas dejan de existir con ese nombre y el listado se rompe entero. | Es la compensación que todo el módulo ya acepta y que el `@file` de `resources/provider.resource.inc` documenta desde SPEC 82: `node_load()` de veinte proveedores dispararía los hooks de entidad y arrastraría doce campos para responder siete claves. Queda anotado en `docs/provider.md` como la suposición que es. |
| **Un término de categoría sin `field_category_code`** viaja como `code: ""` dentro de cada proveedor. | Es exactamente lo que ya hace `/api/v1/service-categories` con el mismo dato, y por la misma razón: el campo es requerido en el formulario, así que un término sin código es dato corrupto, no un caso de negocio. Ocultar la categoría la haría desaparecer de la ficha del proveedor sin dejar rastro. |

---

## Lo que **no** entra en este spec

- `GET /api/v1/providers/%`, el detalle del proveedor: teléfono, dirección, descripción larga, tags y usuarios asociados.
- Cualquier escritura: alta, edición o baja de un proveedor desde la app.
- Imagen de portada o miniatura en el ítem del listado.
- Filtro por tag, por rango de tarifa, por calificación mínima, por texto, o por más de una categoría a la vez.
- Ordenar por título, fecha de alta o número de calificaciones.
- `limit=-1` para traer el listado entero.
- Acotar el marketplace por condominio.
- Índices SQL sobre los campos de orden, y cualquier caché de la respuesta.
- Extraer el parseo de `?page` / `?limit` a un helper compartido por los siete listados.
- Recalcular `field_rating_avg` y `field_rating_count`: este spec los lee, no los escribe.

Cada una de esas, si llega, va en su propio spec.
