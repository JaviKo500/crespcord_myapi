# 84 — Detalle del proveedor (`GET /api/v1/providers/{id}`)

- **Estado:** Approved
- **Fecha:** 2026-08-13
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea el bundle `provider` con `field_address`, `field_services_desc`, `field_tags` (SPEC 81) y el bundle `service_rating` con `field_stars`, `field_rating_comment`, `field_rating_provider`. Este spec **lee** esos campos y no modifica ninguno.
  - `82-provider-private-gallery` (Implemented) — crea `resources/provider.resource.inc`, `field_gallery` y `myapi_provider_build_image()`, que este spec reutiliza dentro del mismo fichero para construir la galería del detalle.
  - `83-providers-list` (Implemented) — el listado que dejó pendiente por escrito el detalle («SPEC 83, Fuera de alcance»). Este spec es exactamente esa pieza que faltaba, y su ítem trae los mismos siete campos del listado como base.
  - Reservas (fecha no localizada en `specs/`, campo confirmado en `myapi.install`) — `field_unit`, `entityreference` → `vivienda`, cardinalidad 1. Este spec **reutiliza** ese campo con una instancia nueva en `service_rating`, siguiendo el mismo criterio que SPEC 55/77 reutilizaron `field_condominium` y `field_requester`.

**Objetivo:** Exponer `GET /api/v1/providers/{id}`, autenticado y de solo lectura, que devuelve un proveedor con los siete campos del listado (SPEC 83) más dirección, descripción larga, tags, galería completa y las últimas tres calificaciones con su resumen agrupado por estrellas.

Cinco notas que la cabecera fija:

- **No hay flujo de escritura de calificaciones todavía.** `service_rating` existe desde SPEC 77 pero nada lo escribe (`myapi_services_provider_is_active()` y el `@file` de SPEC 83 ya lo dejaron anotado). Este spec solo **lee** las que existan; en un sitio recién instalado, `ratings` y `rating_summary` responden vacíos.
- **`field_unit` se le añade una instancia a `service_rating`, campo nuevo del bundle.** Es un cambio de esquema sobre un content type ya implementado (SPEC 77), y va con su propio `hook_update_N`. Nada lo escribe todavía tampoco: nace opcional, igual que `field_rating_avg` nació en SPEC 77 sin nadie que lo recalculara.
- **El nombre del autor sale abreviado** (`"Andrés M."`), no completo. Es una divergencia deliberada del patrón de `myapi_claim_notification.inc` (`requester_name` completo): el marketplace es del sitio entero, así que una reseña de un residente de un condominio la puede leer cualquier usuario con token válido de cualquier otro condominio.
- **El detalle solo exige que el proveedor esté publicado**, no que esté "activo" (SPEC 83). Un proveedor con la licencia vencida sigue abriendo su ficha — coherente con que su galería (SPEC 82) también sigue sirviéndose vencida.
- **Ningún dato se escribe.** Es una lectura más; el proveedor se sigue creando, editando y suspendiendo desde el back office (SPEC 77/78), y una calificación se seguirá creando desde el spec del flujo de calificaciones, que no existe.

---

## Alcance

**Dentro del alcance:**

- **`myapi.install`** (modificar):
  - Una instancia nueva del campo **ya existente** `field_unit` (creado por la feature de reservas, `entityreference` → `vivienda`, cardinalidad 1) sobre el bundle `service_rating`, opcional (`required = 0`). Es una reutilización, no un campo nuevo — mismo criterio con el que SPEC 55/77 reutilizaron `field_condominium` y `field_requester` en `service_request`.
  - `_myapi_services_uninstall_destructive()`: **no cambia**. `field_unit` es prestado (lo creó y lo posee la feature de reservas), así que sigue exactamente el mismo criterio que excluye `field_condominium`/`field_requester` del `$owned` de reclamos — la instancia nueva se pierde en un uninstall destructivo, el campo no.
  - Nuevo **`myapi_update_7030()`**: llama a `_myapi_services_install()` (que crea la instancia) — mismo patrón que `myapi_update_7028()` y `myapi_update_7029()`.
- **`resources/provider.resource.inc`** (modificar) — el fichero ya anunciado por SPEC 82/83 como el lugar del detalle. Funciones nuevas, sin tocar ninguna de las nueve que ya existen:
  - `myapi_provider_detail_dispatch()` — solo `GET`, resto `405`, comprobado antes que la autenticación.
  - `myapi_provider_detail_show($id)` — token, provider publicado (`404 provider_not_found` si no), orquesta el resto y responde.
  - `myapi_provider_detail_fetch($id)` — una fila: los mismos alias que `myapi_provider_fetch()` (`nid`, `title`, `rating_avg`, `rating_count`, `short_description`, `hourly_rate`) más `address` y `description`, con la MISMA regla de publicado que la galería (`status = 1`, sin mirar `field_license_expiry`) — no la de "activo" del listado.
  - `myapi_provider_tags_by_id($id)` — los tags del proveedor, como array de strings.
  - `myapi_provider_gallery_images($id)` — **extraída** de la consulta que hoy vive dentro de `myapi_provider_gallery_list()`, para que el listado de galería y el detalle no puedan divergir en qué imágenes traen. Es el mismo criterio con el que SPEC 83 extrajo la regla de "activo" al ganar un segundo consumidor.
  - `myapi_provider_ratings_recent($id, $limit = 3)` — las últimas `$limit` filas de `service_rating` de este proveedor, con `stars`, `comment`, `uid`, `created` y el `nid` de `field_unit` si lo tiene.
  - `myapi_provider_rating_summary($id)` — el conteo agrupado por `field_stars` sobre **todo** el histórico de calificaciones del proveedor, con las cinco claves (`1`..`5`) siempre presentes y en `0` cuando no hay ninguna.
  - `myapi_provider_build_rating_item($row)` — mapea una fila de calificación a `{stars, comment, author_name, unit, created}`, resolviendo el nombre abreviado del autor (perfil vía `myapi_user_fetch_profile_fields()`, con los mismos tres niveles de fallback que `myapi_claim_notification.inc`: perfil → `account->name` → "Usuario eliminado") y el título de la vivienda de `field_unit` vía `node_load()`, `NULL` si la calificación no la tiene.
  - `myapi_provider_detail_build_item(...)` — junta `myapi_provider_build_item()` (los siete campos del listado) con `address`, `description`, `tags`, `gallery`, `ratings` y `rating_summary`.
- **`myapi.module`** (modificar) — una entrada en `hook_menu()`: `api/v1/providers/%`, `MENU_CALLBACK`, `page arguments = [2]`, `access callback = TRUE`, junto a las tres rutas ya existentes de `provider`.
- **Pruebas unitarias**:
  - `tests/unit/ProviderDetailEndpointTest.php` (nuevo) — el dispatcher, `401`, `404 provider_not_found` (inexistente / despublicado / no numérico / de otro tipo), un proveedor con licencia vencida que **sí** responde, la forma exacta del ítem, `ratings` vacío y `rating_summary` en ceros sin calificaciones, las últimas 3 en orden, el resumen agrupado con el histórico completo, el nombre abreviado del autor y sus tres niveles de fallback, `unit: null` cuando la calificación no tiene `field_unit`.
  - Ampliación de `tests/unit/ServicesInstallTest.php`: la instancia nueva de `field_unit` en `service_rating` (opcional, mismo campo/target que en `reservation`), y que `field_unit` sigue **fuera** de `$owned`.
- **`docs/provider-detail.md`** (nuevo) — la ruta con la plantilla de `CLAUDE.md`.
- **`docs/provider.md`** (modificar) — la línea que hoy dice "there is no `GET /api/v1/providers/%`" deja de ser cierta; se reemplaza por un enlace a `docs/provider-detail.md`.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- **Cualquier escritura.** No se crea, edita, borra ni modera una calificación desde la app. `service_rating` lo sigue escribiendo solo el operador desde el back office, y `field_rating_avg`/`field_rating_count` siguen sin nadie que los recalcule.
- **El flujo que rellena `field_unit` en una calificación nueva.** Este spec solo abre el campo; decidir de dónde sale automáticamente (¿del `field_requester` de la solicitud vía `field_rating_offer`? ¿lo elige el residente al calificar?) es del spec que cree calificaciones.
- **Paginación de calificaciones.** Solo las últimas 3; no hay `GET /api/v1/providers/{id}/ratings` para ver todas. Si hace falta, es su propio endpoint.
- **Moderar, ocultar o denunciar una calificación** desde la app.
- **Cualquier filtro por condominio, rol o unidad** sobre el detalle: mismo criterio que el listado (SPEC 83) y la galería (SPEC 82) — todo token válido ve la misma ficha.
- **Caché de la respuesta.** Ni `ETag`, ni `304`.
- **Índices SQL propios** sobre `field_data_field_rating_provider` o `field_data_field_stars`. Mismo riesgo aceptado que SPEC 83 con `field_rating_avg`/`field_hourly_rate`.
- **Backfill de `field_unit`** sobre calificaciones ya existentes en el sitio (si las hay): nace vacío para todas, igual que `field_short_description` nació vacío para los proveedores existentes en SPEC 81.

Dos decisiones dentro del alcance que conviene ver ya:

- **`field_unit` se reutiliza en vez de crear `field_rating_unit`.** Es la Regla 3 de `CLAUDE.md` aplicada al pie de la letra, con el mismo precedente que ya sentaron `field_condominium` y `field_requester`. El precio es que cualquier cambio futuro al campo `field_unit` (su `target_bundles`, su cardinalidad) afecta simultáneamente a `reservation` y a `service_rating`, y hay que recordarlo.
- **`myapi_provider_gallery_images()` se extrae de `myapi_provider_gallery_list()`**, tocando un endpoint ya implementado (SPEC 82) para que el listado de galería y el detalle no puedan devolver conjuntos distintos de imágenes para el mismo proveedor. Es el mismo tipo de refactor, con la misma motivación, que SPEC 83 hizo con la regla de "activo" — y va aislado en el plan de implementación por la misma razón: verificarlo antes de que exista código nuevo encima.

---

## Modelo de datos

### El campo reutilizado: instancia de `field_unit` en `service_rating`

| Ajuste | Valor |
|---|---|
| `field_name` | `field_unit` (ya existe; no se crea) |
| `entity_type` / `bundle` | `node` / `service_rating` |
| `label` | Vivienda |
| `required` | `0` |
| `widget` | `entityreference_autocomplete` |
| `description` | «Vivienda del residente que calificó, si el flujo que crea la calificación la registra. Vacío en toda calificación existente hasta entonces.» |

Sin `settings` en la instancia: el `target_bundles` (`vivienda`) es de campo y ya lo fija `_myapi_entityreference_field_settings()['field_unit']`, reutilizado tal cual.

### Forma de la respuesta

```json
{
  "success": true,
  "data": {
    "id": 41,
    "title": "Plomería Torres",
    "categories": [
      { "id": 7, "code": "plomeria", "name": "Plomería" }
    ],
    "rating_avg": 4.9,
    "rating_count": 88,
    "short_description": "Destapes y reparaciones, atención en el día.",
    "hourly_rate": 25.5,
    "address": "Av. Siempre Viva 123, local 4",
    "description": "Instalaciones eléctricas residenciales, tableros, iluminación y diagnóstico de cortocircuitos. Personal con certificación profesional y herramienta de medición.",
    "tags": ["urgencias", "24h", "certificado"],
    "gallery": [
      { "id": 42, "url": "https://midominio.com/api/v1/providers/41/gallery/42", "filename": "taller-01.jpg" }
    ],
    "ratings": [
      {
        "stars": 5,
        "comment": "Cambió el tablero de mi depto sin dejar rastro. Excelente.",
        "author_name": "Andrés M.",
        "unit": "4B",
        "created": "2026-06-12T18:04:00"
      }
    ],
    "rating_summary": { "1": 1, "2": 2, "3": 3, "4": 12, "5": 70 }
  }
}
```

`data` es directamente el objeto del proveedor — a diferencia del listado, no hay envoltura `provider` ni `pagination`: es un solo recurso, no una colección.

### Las trece claves, siempre las trece, en este orden

| Clave | Tipo | Origen | Vacío |
|---|---|---|---|
| `id` | int | `node.nid` | Nunca |
| `title` | string | `node.title`, `check_plain()` | Nunca |
| `categories` | array | `field_categories` → término, `{id, code, name}` | `[]` |
| `rating_avg` | float \| **null** | `field_rating_avg` | `null` |
| `rating_count` | int | `field_rating_count` | `0`, nunca `null` |
| `short_description` | string | `field_short_description`, `check_plain()` | `""`, nunca `null` |
| `hourly_rate` | float \| **null** | `field_hourly_rate` | `null` |
| `address` | string | `field_address`, `myapi_text_to_plain()` | `""`, nunca `null` |
| `description` | string | `field_services_desc`, `myapi_text_to_plain()` | `""` (aunque el campo es requerido en el formulario, la API no lo asume) |
| `tags` | array de string | `field_tags` → término, `name` por `check_plain()` | `[]` |
| `gallery` | array | `field_gallery`, `{id, url, filename}` — exactamente `myapi_provider_build_image()` | `[]` |
| `ratings` | array | `service_rating` de este proveedor, últimas 3 por `created` desc | `[]` |
| `rating_summary` | object | conteo agrupado por `field_stars` sobre **todo** el histórico | `{"1":0,"2":0,"3":0,"4":0,"5":0}` |

Las once primeras son las mismas que el listado (SPEC 83) con dos añadidas (`address`, `description`); las dos últimas son las nuevas de este spec. `categories`, `rating_avg`, `rating_count`, `short_description` y `hourly_rate` conservan **exactamente** el mismo tipo, la misma regla de vacío y el mismo comentario sobre `rating_avg: null` ≠ `0` que ya documenta `docs/provider.md` — no se repiten aquí en detalle para no tener dos fuentes de la misma verdad.

`address` y `description` van por `myapi_text_to_plain()` y no por `check_plain()`, a diferencia de `short_description`: los dos son `text_long` con `text_processing = 1` (editor con formato), así que hay marcado que aplanar — el mismo criterio que separa `short_description` (SPEC 81, `check_plain()`) de la descripción de categoría (SPEC 79, este helper).

### Cada calificación de `ratings`

| Clave | Tipo | Origen |
|---|---|---|
| `stars` | int | `field_stars`, `1..5` |
| `comment` | string | `field_rating_comment`, `myapi_text_to_plain()`, `""` si está vacío (el campo es opcional) |
| `author_name` | string | Nombre + inicial del apellido, resuelto vía `myapi_user_fetch_profile_fields(node.uid)`. Fallback a `account->name` si el perfil está vacío, y a `"Usuario eliminado"` si la cuenta ya no existe — los mismos tres niveles que `myapi_claim_notification.inc` usa para `requester_name`, con el formato abreviado en vez del completo |
| `unit` | string \| **null** | Título del nodo `vivienda` de `field_unit`, o `null` si la calificación no lo tiene (todas las de hoy, hasta que exista el flujo que lo rellena) |
| `created` | string | `format_date(node.created, 'custom', 'Y-m-d\TH:i:s')` — mismo formato que `claim.resource.inc` y `reservation.resource.inc` |

`author_name` abreviado (`"Andrés M."`) y no completo es una divergencia deliberada del patrón de reclamos: el marketplace es del sitio entero, así que el nombre de un residente viaja fuera de su propio condominio a cualquier usuario con token válido.

### `rating_summary`

Un objeto de cinco claves fijas, `"1"` a `"5"`, siempre las cinco presentes — nunca un subconjunto, aunque el proveedor solo tenga calificaciones de 5 estrellas. Es el `COUNT(*) GROUP BY field_stars` de **todo** el histórico de `service_rating` de este proveedor, no solo de las tres que viajan en `ratings`: la suma de sus cinco valores es siempre `rating_count`.

### Las consultas

Seis consultas fijas por petición, más como mucho tres cada una para el nombre y la unidad de las calificaciones (acotadas por el `LIMIT 3`, nunca crecen con el tamaño del marketplace):

1. **La fila del proveedor** — `node` + los mismos cuatro `LEFT JOIN` de `myapi_provider_fetch()` (rating_avg, rating_count, short_description, hourly_rate) más dos nuevos (`field_data_field_address`, `field_data_field_services_desc`), filtrada por `n.nid = $id` y `n.status = 1` — **sin** `myapi_provider_apply_active_conditions()`: el detalle no exige licencia vigente.
2. **Categorías** — `myapi_provider_categories_by_nid([$id])`, reutilizada tal cual de SPEC 83.
3. **Tags** — `field_data_field_tags` → `taxonomy_term_data`, un único `INNER JOIN`: un `tid` de un término borrado se omite en silencio, mismo criterio que una categoría huérfana.
4. **Galería** — `myapi_provider_gallery_images($id)`, extraída de SPEC 82, mismo `INNER JOIN` con `file_managed` y mismo orden por delta.
5. **Últimas 3 calificaciones** — `node` tipo `service_rating`, `INNER JOIN field_data_field_rating_provider` filtrado por este proveedor, `LEFT JOIN` a `field_data_field_stars`, `field_data_field_rating_comment` y `field_data_field_unit` (opcional, puede faltar), `ORDER BY node.created DESC`, `LIMIT 3`. Sin filtro de `status`: SPEC 77 dice que moderar una calificación es borrar el nodo, no despublicarlo.
6. **Resumen agrupado** — mismo `INNER JOIN` de `field_rating_provider` con `field_data_field_stars`, sin el `LIMIT`, `GROUP BY field_stars_value`.

Y por cada una de las (como mucho 3) calificaciones devueltas: un `user_load()` para el nombre y, si tiene `field_unit`, un `node_load()` para el título de la vivienda.

### Errores

| Código | `error_code` | Cuándo |
|---|---|---|
| 401 | `missing_authorization` | Sin cabecera `Authorization` |
| 401 | `invalid_token` | Token inexistente, revocado, caducado, o de un usuario borrado o bloqueado |
| 404 | `provider_not_found` | El `id` no existe, no es del tipo `provider`, está despublicado, o no es un entero positivo. Clave ya existente (SPEC 82) |
| 405 | `method_not_allowed` | Cualquier método que no sea `GET` |

No hay `422`: la ruta no acepta parámetros de consulta.

---

## Plan de implementación

1. **`myapi.install` — la instancia de `field_unit` en `service_rating`.** En el bloque de instancias del bundle, junto a las de `field_rating_offer`, `field_rating_provider`, `field_stars` y `field_rating_comment`: `_myapi_reservations_ensure_instance('field_unit', 'service_rating', [...])` con `'required' => 0` explícito y el `label`/`description` del modelo de datos. **No se toca el campo** (`_myapi_reservations_ensure_field()` no se llama de nuevo: `field_unit` ya existe desde reservas) y **no se toca** `_myapi_services_uninstall_destructive()`: `field_unit` sigue sin entrar en `$owned`, igual que `field_condominium`/`field_requester`. Un comentario junto a la instancia que diga que el campo es prestado y que el spec que lo escribe todavía no existe. *Verificación: `php -l myapi.install`.*

2. **`myapi.install` — `myapi_update_7030()`.** Una llamada a `_myapi_services_install()`, mismo patrón que `myapi_update_7028()`/`7029()`, con un mensaje `t()` que nombre la instancia nueva. *Verificación: `drush updb` ofrece el `7030`, lo aplica sin error, y una segunda pasada no lo ofrece.*

3. **`resources/provider.resource.inc` — extraer `myapi_provider_gallery_images($id)`.** Se saca la consulta que hoy vive dentro de `myapi_provider_gallery_list()` (el `db_select('field_data_field_gallery', ...)` con su `innerJoin` a `file_managed` y su `orderBy('fg.delta', 'ASC')`) a una función propia que devuelve las filas; `myapi_provider_gallery_list()` pasa a llamarla y a mapear con `myapi_provider_build_image()` igual que antes. **Es el único paso con riesgo de regresión sobre un endpoint en producción**, y por eso va aislado y antes de cualquier código nuevo. *Verificación, la que importa de este paso: la suite de `ProviderGalleryEndpointTest` completa en verde sin cambiar una sola expectativa, y `git diff` acotado a esa extracción — misma forma de la fila, mismo orden.*

4. **`resources/provider.resource.inc` — las cuatro consultas nuevas.** `myapi_provider_detail_fetch($id)` (la fila del proveedor, `status = 1` sin regla de activo, con los seis `LEFT JOIN`), `myapi_provider_tags_by_id($id)`, `myapi_provider_ratings_recent($id, $limit = 3)` y `myapi_provider_rating_summary($id)`, las cuatro descritas en el modelo de datos. Un comentario junto al `LEFT JOIN` de `field_unit` en `ratings_recent` que diga por qué es `LEFT` y no `INNER`: una calificación sin vivienda registrada se sigue mostrando, solo que con `unit: null`. *Verificación: `php -l`; ninguna función la llama nadie todavía.*

5. **`resources/provider.resource.inc` — `myapi_provider_build_rating_item($row)`.** El mapeo de una fila de calificación a las cinco claves, con la resolución del nombre abreviado (perfil → `account->name` → "Usuario eliminado") y del título de la vivienda vía `node_load()` cuando `field_unit` está presente. *Verificación: `php -l`; función aún no alcanzable.*

6. **`resources/provider.resource.inc` — `myapi_provider_detail_build_item(...)`.** Llama a `myapi_provider_build_item()` para las siete claves del listado y las mezcla, en el orden de la tabla del modelo de datos, con `address`, `description` (las dos por `myapi_text_to_plain()`), `tags`, `gallery` (mapeada con `myapi_provider_build_image()`, el mismo de SPEC 82), `ratings` (mapeada con la función del paso 5) y `rating_summary`. *Verificación: `php -l`.*

7. **`resources/provider.resource.inc` — el dispatcher y `myapi_provider_detail_show($id)`.** `myapi_provider_detail_dispatch($id)` (solo `GET`, resto `405`, comprobado antes que la autenticación, como los otros tres dispatchers del fichero) y `myapi_provider_detail_show($id)`: token, validación del `id` (no numérico → `404 provider_not_found`, mismo criterio que la galería), `myapi_provider_detail_fetch()` (fila vacía → `404 provider_not_found`), las cinco consultas restantes con el `nid` ya resuelto, y `myapi_respond($item, 200)` — sin envoltura adicional, `data` **es** el proveedor. *Verificación: `php -l`; el endpoint aún no es alcanzable.*

8. **`myapi.module` — la ruta.** `api/v1/providers/%`, `page arguments = [2]`, `MENU_CALLBACK`, `access callback = TRUE`, junto a las otras tres de `provider`. Después, `drush cc all`. *Verificación con curl: sin cabecera → `401`; con token y un proveedor con calificaciones → `200` con las trece claves; un `nid` despublicado o inexistente → `404`; uno con la licencia vencida → `200` igual; `POST` → `405`.*

9. **Pruebas.**
   - `tests/unit/ProviderDetailEndpointTest.php` (nuevo): el dispatcher solo acepta `GET`; `401` sin cabecera y con token inválido; `404 provider_not_found` con `nid` inexistente, de otro tipo, despublicado y no numérico; un proveedor con la licencia vencida **sí** responde `200` (a diferencia del listado); la forma exacta de las trece claves, en orden; `categories`, `rating_avg`, `rating_count`, `short_description` y `hourly_rate` calculados igual que en el listado para el mismo proveedor (cruce de valores); `address` y `description` vía `myapi_text_to_plain()` con una entrada que trae `<p>` y `&nbsp;`; `tags` como array de strings y `[]` sin ninguno; `gallery` con las mismas tres claves e igual orden que `GET /api/v1/providers/%/gallery` para el mismo proveedor; `ratings` vacío y `rating_summary` en ceros para un proveedor sin calificaciones; las últimas 3 en orden de `created` descendente cuando hay más de tres; `rating_summary` sumando el histórico completo y no solo las tres mostradas; `unit: null` cuando la calificación no tiene `field_unit`; el nombre abreviado con sus tres niveles de fallback (perfil, username, cuenta borrada).
   - Ampliación de `ProviderGalleryEndpointTest`: la extracción del paso 3 no cambia una sola expectativa.
   - Ampliación de `ServicesInstallTest`: la instancia de `field_unit` en `service_rating` (opcional, mismo campo que en `reservation`), y que sigue fuera de `$owned`.

   *Verificación: suite completa en verde.*

10. **Documentación.** `docs/provider-detail.md` nuevo, con la plantilla de `CLAUDE.md`: la ruta, el ejemplo de respuesta completo, la tabla de las trece claves, la de cada calificación, la nota de `rating_avg: null` ≠ `0` (remitiendo a `docs/provider.md`), la del nombre abreviado, la de `unit: null` mientras no exista el flujo de calificar, y la tabla de errores. Una línea en `docs/provider.md`: la frase "there is no `GET /api/v1/providers/%`" se reemplaza por un enlace a este documento.

11. **Aplicar y verificar.** `drush updb`, `drush cc all`, y recorrer los criterios de aceptación contra el sitio, con un proveedor que tenga: galería, tags, más de tres calificaciones (alguna sin `field_unit`), uno sin ninguna calificación, y uno con la licencia vencida.

**Nota:** no se toca `hook_schema()`, ni `includes/myapi.provider_query.inc`, ni `includes/myapi.provider_files.inc`, ni `myapi_provider_list()`/`myapi_provider_count()`/`myapi_provider_fetch()`/`myapi_provider_categories_by_nid()`/`myapi_provider_build_item()`/`myapi_provider_build_image()` (se **llaman**, no se editan), ni ningún otro fichero de `resources/`.

Dos cosas del orden que no son cosméticas:

- **El paso 3 va antes que todo lo nuevo** — es el único que toca un endpoint en producción, y conviene verificarlo aislado, igual que SPEC 82 hizo con `hook_file_download()` y SPEC 83 con la regla de activo.
- **El paso 6 (el mapeo) va antes que el paso 7 (el dispatcher)** por la misma razón que SPEC 83 lo hizo: fija la forma de la respuesta antes de escribir el código que la arma, y evita que el dispatcher arrastre una consulta con columnas que nadie pidió.

---

## Criterios de aceptación

**Leyenda.** `[ ]` es el estado inicial de todo criterio en un spec en `Draft`. Al implementar se marcan `[x]` los que cierra la suite unitaria o la inspección del repositorio, dejando constancia expresa de los que exigen un Drupal arrancado.

**Autenticación y método**

- [ ] Sin cabecera `Authorization` → `401 missing_authorization`.
- [ ] Con token inexistente, revocado, caducado, o de un usuario borrado o bloqueado → `401 invalid_token`.
- [ ] Con cualquier token válido → `200`, sin mirar rol, condominio ni vivienda.
- [ ] `POST`, `PUT` y `DELETE` sobre `/api/v1/providers/%` → `405 method_not_allowed`, **sin token** y con token: el método se comprueba antes que la autenticación.

**Existencia y regla de publicado**

- [ ] Un `id` inexistente → `404 provider_not_found`.
- [ ] Un `id` de un nodo que no es `provider` → `404 provider_not_found`.
- [ ] Un proveedor **despublicado** → `404 provider_not_found`.
- [ ] Un `id` no numérico (`abc`, vacío, negativo, cero) → `404 provider_not_found`, nunca un error de PHP.
- [ ] Un proveedor con `field_license_expiry` en el **pasado** → `200`: el detalle no exige "activo", solo publicado. *(Diferencia deliberada con el listado, SPEC 83.)*
- [ ] Un proveedor **sin** `field_license_expiry` → `200` igual, por la misma razón.

**Forma del ítem**

- [ ] La respuesta trae exactamente **trece** claves dentro de `data`, en el orden `id`, `title`, `categories`, `rating_avg`, `rating_count`, `short_description`, `hourly_rate`, `address`, `description`, `tags`, `gallery`, `ratings`, `rating_summary`.
- [ ] `data` no lleva ninguna envoltura adicional (no hay `provider`, no hay `pagination`): es directamente el objeto.

**Coherencia con el listado (SPEC 83)**

- [ ] Para el mismo proveedor, `id`, `title`, `categories`, `rating_avg`, `rating_count`, `short_description` y `hourly_rate` son **idénticos** entre `GET /api/v1/providers` y `GET /api/v1/providers/{id}`.
- [ ] Un proveedor sin categoría, sin tarifa o sin calificaciones responde los mismos vacíos que en el listado (`[]`, `null`, `0`, `""`, según la clave).

**`address` y `description`**

- [ ] Un `field_address`/`field_services_desc` con `<p>`, `<b>` o `&nbsp;` llega **sin** marcado y **sin** escapar (texto real, no `&lt;p&gt;`).
- [ ] `field_address` vacío → `address: ""`, nunca `null`.
- [ ] `field_services_desc` vacío → `description: ""`, nunca `null` (aunque el campo sea requerido en el formulario, el detalle no lo asume).

**`tags`**

- [ ] Un proveedor con tags responde `tags` como array de strings, con el nombre exacto del término.
- [ ] Un proveedor sin tags responde `tags: []`.
- [ ] Un `tid` de `field_tags` cuyo término fue borrado se omite en silencio, sin romper la respuesta.

**`gallery`**

- [ ] `gallery` trae exactamente las mismas imágenes, en el mismo orden, que `GET /api/v1/providers/{id}/gallery` para el mismo proveedor.
- [ ] Cada ítem trae `id`, `url`, `filename` — la `url` apunta a `/gallery/%` y nunca a `/system/files`.
- [ ] Un proveedor sin imágenes responde `gallery: []`.

**`ratings`**

- [ ] Un proveedor sin calificaciones responde `ratings: []`.
- [ ] Con más de tres calificaciones, `ratings` trae exactamente las **tres** más recientes por `created`, en orden descendente.
- [ ] Cada ítem trae `stars`, `comment`, `author_name`, `unit`, `created` — y ninguna clave más.
- [ ] `stars` es un entero `1..5`.
- [ ] Una calificación sin `field_rating_comment` responde `comment: ""`, nunca `null`.
- [ ] `author_name` sale abreviado ("Andrés M."), nunca el nombre completo.
- [ ] `author_name` cae a `account->name` cuando el perfil no tiene nombre/apellido, y a "Usuario eliminado" cuando la cuenta ya no existe.
- [ ] Una calificación **sin** `field_unit` responde `unit: null`.
- [ ] Una calificación **con** `field_unit` responde el título del nodo `vivienda` referenciado.
- [ ] `created` sale en el mismo formato (`Y-m-d\TH:i:s`) que `claim.resource.inc` y `reservation.resource.inc`.

**`rating_summary`**

- [ ] Sin calificaciones, responde `{"1":0,"2":0,"3":0,"4":0,"5":0}`, las cinco claves presentes.
- [ ] Con calificaciones, la suma de los cinco valores es igual a `rating_count`.
- [ ] `rating_summary` cuenta el **histórico completo**, no solo las tres de `ratings`: con más de tres calificaciones, sus totales no coinciden con un conteo de solo las tres mostradas.

**Instalación de `field_unit` en `service_rating`**

- [ ] `drush updb` ofrece `myapi_update_7030`, lo aplica sin error, y una segunda pasada no lo ofrece.
- [ ] La instancia de `field_unit` en `service_rating` es opcional y del mismo campo (mismo `target_bundles`) que la instancia de `reservation`.
- [ ] `field_unit` sigue **fuera** de `$owned`: `drush pm-uninstall myapi` con la constante destructiva en `FALSE` no lo borra, y tampoco lo borraría con la constante en `TRUE` — es prestado.
- [ ] `field_unit` en `reservation` conserva su instancia sin cambios: `git diff` vacío sobre esa parte de `myapi.install`.

**No regresión**

- [ ] `GET /api/v1/providers` y `GET /api/v1/providers/{id}/gallery` responden byte a byte lo mismo que antes de este spec.
- [ ] `git diff` vacío sobre `myapi_provider_list()`, `myapi_provider_count()`, `myapi_provider_fetch()`, `myapi_provider_categories_by_nid()`, `myapi_provider_build_item()`, `myapi_provider_build_image()` y `myapi_provider_gallery_download()`.
- [ ] `myapi_provider_gallery_list()` sigue devolviendo la misma forma y el mismo orden tras la extracción del paso 3; su suite pasa sin cambiar una sola expectativa.
- [ ] Ningún otro endpoint `api/v1/...` cambia: `git diff` vacío en `resources/` salvo `provider.resource.inc`.
- [ ] Ningún rol gana ni pierde permisos.
- [ ] `myapi_update_7029` y anteriores quedan intactos.
- [ ] `includes/myapi.i18n.inc` queda sin tocar: `provider_not_found` ya existe desde SPEC 82.
- [ ] La suite unitaria completa pasa en verde, con los ficheros de test nuevos incluidos.
- [ ] `drush cc all` no reporta errores y `api/v1/providers/%` queda en `menu_router` sin desplazar a las otras tres rutas de `provider`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Modelo de datos de las calificaciones | **Reutilizar `service_rating`**, el content type que SPEC 77 ya creó con `field_stars` y `field_rating_comment` | Crear una tabla SQL propia; o separar el modelo de datos en un spec previo del que este dependiera | Elección explícita del usuario, y evita duplicar un modelo que ya existe: `service_rating` ya tiene `field_rating_provider`, `field_stars` (`list_integer` 1–5) y `field_rating_comment` (`text_long`), solo que nada lo escribe ni lo lee todavía. Crear una tabla paralela habría producido dos representaciones de "una calificación" en el mismo módulo. |
| Autor, unidad y fecha en cada calificación | **Los tres viajan** | Solo `stars` y `comment`, como se pidió literalmente | Elección explícita del usuario tras vérselo planteado: sin ellos la UI de la imagen de referencia (autor, depto, mes) no se puede construir, y es justo lo que la imagen mostraba. |
| Cómo se obtiene la unidad de la calificación | **Instancia nueva del campo ya existente `field_unit`** sobre `service_rating` | (a) Sin unidad en absoluto; (b) resolverla en caliente como la primera vivienda que ocupa el `field_requester` de la solicitud | Elección explícita del usuario. `service_request` no tiene `field_unit` y un residente puede ocupar más de una vivienda (`myapi_user_occupied_unit_nids()` devuelve una lista), así que no hay una única vivienda "correcta" que inferir en caliente sin arriesgarse a mostrar el depto equivocado. Guardar la unidad **en el momento de calificar** es la única fuente que no adivina; ese guardado es del spec futuro que escribe calificaciones, y este solo abre el campo. |
| Reutilizar `field_unit` o crear `field_rating_unit` | **Reutilizar `field_unit`** (el de reservas) | Un campo nuevo, propio de `service_rating` | Regla 3 de `CLAUDE.md`, con el mismo precedente que SPEC 55/77 sentaron con `field_condominium` y `field_requester`: es el mismo dato (una referencia a `vivienda`), y un segundo campo con el mismo significado sería la duplicación que la regla prohíbe. El precio es que el campo queda compartido entre dos features y hay que recordarlo si cambia. |
| Alcance de `rating_summary` | **Histórico completo** del proveedor | Una ventana reciente (ej. últimas 100) | Elección explícita del usuario. Es lo que la barra de calificaciones de la imagen de referencia necesita mostrar (88 reseñas, todas contadas), y coincide con `rating_count`: una ventana recortada dejaría `rating_summary` y `rating_count` sin cuadrar entre sí. El precio, aceptado, es el mismo que SPEC 83 ya aceptó con `field_rating_avg`: sin índice dedicado hoy, aceptable mientras el volumen por proveedor sea el de decenas o cientos, no decenas de miles. |
| Filtro de estado sobre las calificaciones | **Sin filtro de `status`** | Exigir `status = 1` (publicado) | Elección explícita del usuario. SPEC 77 decidió que moderar una calificación es **borrar el nodo**, no despublicarlo (para poder hacerlo sin tocar la solicitud); exigir `status = 1` sería una condición que nunca excluye nada hoy y que además contradice esa decisión si algún día alguien despublica en vez de borrar. |
| Nombre del autor | **Abreviado** ("Andrés M.") | Nombre completo, como `requester_name` en reclamos | Elección explícita del usuario, con una razón de fondo real: el marketplace es del sitio entero, así que el nombre de un residente viaja fuera de su propio condominio a cualquier usuario con token válido — una exposición que reclamos no tiene, porque un reclamo se lee dentro del mismo condominio. |
| Regla de "existe" para el detalle | **Solo publicado**, sin exigir licencia vigente | La regla de "activo" completa del listado (SPEC 83): publicado **y** con licencia no vencida | Elección explícita del usuario. Es la misma asimetría que ya tiene la galería (SPEC 82): un proveedor vencido desaparece del **listado**, pero su ficha ya abierta —y sus imágenes— siguen sirviéndose. Exigir "activo" aquí rompería una ficha abierta a media sesión apenas venza la licencia, el mismo síntoma que SPEC 82 evitó para las imágenes. |
| Consulta de imágenes de la galería | **Extraída** a `myapi_provider_gallery_images($id)`, compartida por el listado de galería y el detalle | Duplicar la consulta una segunda vez en el detalle | Mismo criterio que SPEC 83 usó para la regla de "activo": con dos consumidores, una consulta copiada es una consulta que puede divergir en silencio — un `INNER` que se vuelve `LEFT` en una sola copia haría que el listado de galería y el detalle mostraran imágenes distintas para el mismo proveedor sin que ningún test de forma lo note. |
| Envoltura de la respuesta | **`data` es directamente el proveedor** | `{ "provider": { ... } }`, como el listado envuelve en `providers` | El listado envuelve porque `data` tiene dos cosas (`providers` y `pagination`); el detalle es un solo recurso y no tiene nada más que envolver — el mismo criterio que ya usa `GET /api/v1/claims/%` con la clave `claim`. *(Nota: se evaluó seguir ese mismo patrón con una clave `provider`, pero se descartó por ser una capa sin ninguna otra clave al lado; queda anotado por si un spec futuro prefiere unificar.)* |
| Paginación de `ratings` | **Fija en 3**, sin parámetro `?limit` | Un `?ratings_limit=` configurable, o un endpoint propio `GET /api/v1/providers/{id}/ratings` paginado | Es lo que se pidió ("las 3 últimas"). Un endpoint de reseñas paginado es una funcionalidad completa de "ver todas las reseñas" que no se ha pedido y que tiene sus propias preguntas (orden, filtro por estrellas, moderación) — cabe en su propio spec. |
| Forma de `rating_summary` | **Objeto** `{"1":n, ..., "5":n}` | Array de `{stars, count}` | Es la forma más directa de pintar la barra de la imagen de referencia: la clave **es** el valor de la estrella, sin que la app tenga que buscar dentro de un array. Las cinco claves siempre presentes evitan que la app tenga que rellenar los huecos. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Romper `GET /api/v1/providers/%/gallery` al extraer `myapi_provider_gallery_images()`.** Es el único riesgo del spec que toca un endpoint con datos reales en producción: una extracción descuidada podría cambiar el orden, perder el `INNER JOIN` con `file_managed`, o alterar qué columnas viajan. | El paso 3 del plan va aislado y antes de todo lo nuevo, con su propia verificación: la suite de `ProviderGalleryEndpointTest` en verde **sin cambiar una sola expectativa**. Es el mismo criterio que SPEC 82 usó con `hook_file_download()` y SPEC 83 con la regla de activo. |
| **`ratings` y `rating_summary` responden vacíos indefinidamente**, porque nada escribe `service_rating` todavía. Es el mismo riesgo que SPEC 81 ya asumió con sus tres campos: código en producción que nadie puede ejercer hasta que llegue el spec que falta. | Aceptado y explícito en el objetivo. Es más barato que la alternativa — crear el modelo de lectura después de que exista el de escritura duplica el trabajo de definir la forma de la respuesta. El costo hoy es cero para la app: un proveedor sin calificaciones ya es un caso contemplado (`rating_count: 0`). |
| **`field_unit` queda compartido entre `reservation` y `service_rating`.** Un cambio futuro a ese campo (su `target_bundles`, su cardinalidad) afecta a las dos features a la vez, y quien lo edite pensando solo en reservas puede romper calificaciones sin darse cuenta. | Documentado en la tabla de decisiones y en el comentario junto a la instancia nueva en `myapi.install`. Es el mismo riesgo, ya aceptado, que corren `field_condominium` y `field_requester` desde que reclamos y solicitudes de servicio los reutilizaron. |
| **Nombre abreviado + unidad + fecha puede seguir identificando a alguien** dentro de un edificio pequeño, aunque el nombre completo no viaje. "Andrés M., 4B, junio 2026" puede bastar para saber quién escribió la reseña si el edificio tiene pocas unidades. | Riesgo asumido conscientemente: es el mismo compromiso que hacen Google, Uber y toda app de reseñas con nombre abreviado, y ocultar también la unidad o el mes dejaría la reseña sin ningún contexto verificable ("¿de verdad calificó un residente de este edificio?"). Si algún día se pide más anonimato, la salida es ocultar `unit` primero — es la clave más identificable de las tres. |
| **Sin índice sobre `field_data_field_rating_provider` ni `field_data_field_stars`.** `rating_summary` escanea todo el histórico del proveedor en cada petición del detalle, y `ratings_recent` ordena por `created` sin índice sobre esa combinación. | Aceptado hoy, mismo criterio que SPEC 83 aceptó para `field_rating_avg`/`field_hourly_rate`: con decenas o cientos de calificaciones por proveedor es irrelevante. Si un proveedor concentrado dominara el marketplace, la salida es un `EXPLAIN` real y un `hook_update_N` con el índice que pida — no adivinar hoy. |
| **Hasta 3 `user_load()` y hasta 3 `node_load()` adicionales por petición**, uno por calificación mostrada, sin batching. | Acotado por el propio límite de 3: a diferencia del listado (hasta 20 filas por página), este costo nunca crece con el tamaño del marketplace. Batchear con `user_load_multiple()`/`node_load_multiple()` ahorraría como mucho 4 consultas por petición a cambio de más código — no se justifica para un máximo de 3. |
| **La asimetría "publicado" (detalle) vs "activo" (listado)** puede leerse como una inconsistencia: un proveedor puede desaparecer de `GET /api/v1/providers` y seguir respondiendo `200` en `GET /api/v1/providers/{id}`. | Es la misma asimetría, ya aceptada y documentada, entre el listado y la galería de SPEC 82 — no es nueva de este spec, solo se extiende al resto de la ficha. `docs/provider-detail.md` lo anota explícitamente con el caso concreto (licencia vencida) para que quien integre la app no lo lea como un bug. |
| **Ninguna validación de que `field_rating_provider` coincide con el proveedor de `field_rating_offer`.** Si una calificación futura se guardara con las dos referencias inconsistentes (una oferta de un proveedor, pero `field_rating_provider` apuntando a otro), este endpoint la mostraría igual: lee `field_rating_provider` directamente y no cruza con la oferta. | Fuera del control de un spec de solo lectura: la integridad de esa relación es responsabilidad del flujo que crea la calificación, que no existe todavía. Anotado aquí para que ese spec futuro sepa que este endpoint confía ciegamente en `field_rating_provider`. |
