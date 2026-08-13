# 81 — Tarifa por hora, etiquetas y descripción corta del proveedor

- **Estado:** Draft
- **Fecha:** 2026-08-13
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea el bundle `provider` y `includes/myapi.services_common.inc` con `MYAPI_SERVICES_PROVIDER_TYPE`. Este spec **añade** tres campos a ese bundle y **no modifica** ninguno de los nueve que ya existen. Reutiliza sus sub-helpers idempotentes `_myapi_reservations_ensure_field()` / `_myapi_reservations_ensure_instance()` / `_myapi_services_ensure_vocabulary()` y su patrón de uninstall conservador (campos propios vs. prestados).
  - `79-service-categories-list` (Implemented) — precedente de vocabulario expuesto por API y origen de `myapi_text_to_plain()`, el helper que el futuro endpoint de proveedores usará sobre la descripción corta. Este spec **no** toca ese endpoint ni ningún `resources/`.

**Objetivo:** Añadir de forma idempotente al content type `provider`, al instalar y al actualizar el módulo, tres campos nuevos — **`field_hourly_rate`** (tarifa por hora, decimal informativo), **`field_tags`** (etiquetas libres sobre un vocabulario nuevo `provider_tag` con autocompletado) y **`field_short_description`** (descripción corta de 255 caracteres) — todos opcionales, **sin ningún endpoint de API, permiso, validación de negocio ni cambio en las respuestas actuales**.

Tres notas sobre por qué la cabecera dice lo que dice:

- **Los tres son opcionales.** Es lo que hace que este spec no rompa los proveedores ya cargados en el sitio: un campo requerido nuevo dejaría inválido cualquier nodo `provider` existente en cuanto alguien lo abriera para editarlo.
- **No hay dependencia con SPEC 78** (rol `proveedor`): este spec no concede ni retira un solo permiso, y el proveedor no edita su propia ficha (decisión 2 de aquel spec).
- **No cambia ninguna respuesta de la API.** Hoy no existe `/api/v1/providers`; `/api/v1/service-categories` lee el bundle `provider` solo para contar (`?with_counts=1`) y ese `COUNT` no toca ninguno de los tres campos nuevos.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.services_common.inc`** (modificar) — una constante nueva, `MYAPI_SERVICES_TAG_VOCABULARY` (`'provider_tag'`), guardada con `if (!defined(...))` como las seis que ya hay. Ninguna función nueva: los tres campos no traen regla de negocio que compartir.
- **`myapi.install`** (modificar):
  - `_myapi_services_install()` gana el vocabulario `provider_tag` (vía `_myapi_services_ensure_vocabulary()`, sin campos propios), los tres campos nuevos y sus tres instancias en `provider`.
  - `_myapi_services_uninstall_destructive()` gana los tres campos en la lista `$owned` y el borrado del vocabulario nuevo, junto al de `service_category`.
  - Nuevo **`myapi_update_7028()`** que llama a `_myapi_services_install()` — la misma llamada que `myapi_update_7025()`, apoyada en que el instalador entero es reejecutable.
- **Pruebas unitarias** — ampliación de `tests/unit/ServicesInstallTest.php`: la constante nueva, la presencia de los tres campos y sus instancias en el instalador, sus ajustes exactos (precisión/escala, `min`, `max_length`, cardinalidad, widget), que las tres instancias son opcionales, que los tres campos entran en `$owned`, y que el vocabulario nuevo se borra en el teardown destructivo.
- **`docs/services-install.md`** (modificar) — los tres campos en la tabla del bundle `provider`, el vocabulario nuevo, y la nota de que `field_tags` autocrea términos al guardar.
- `drush updb` y `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- **Cualquier endpoint.** No se crea `resources/provider.resource.inc` ni se toca ninguno existente. Los tres campos nacen invisibles para la app: quien los exponga decidirá entonces el formato de la tarifa en JSON, si los tags viajan como cadenas o como `{id, name}`, y si la descripción corta pasa por `myapi_text_to_plain()` o por `check_plain()`.
- **Modificar `/api/v1/service-categories`.** Ni un filtro por tag, ni un rango de tarifa, ni el conteo por tag.
- **Campo de moneda.** La tarifa es un decimal informativo en la moneda implícita del módulo, la misma que `field_offer_amount` (SPEC 77). Introducir `field_currency` afectaría también a ofertas y pagos y es una decisión de producto propia.
- **Cualquier relación entre la tarifa y las ofertas.** Nada calcula `field_offer_amount` a partir de `field_hourly_rate`, ni valida que una oferta sea coherente con la tarifa publicada. La tarifa es escaparate: «desde cuánto cobra este proveedor».
- **Normalización, sinónimos, jerarquía o moderación de tags.** El vocabulario es plano y de creación libre: «urgencias» y «Urgencias» quedan como dos términos distintos si el operador los teclea así. Limpiarlo es tarea del operador desde `admin/structure/taxonomy/provider_tag`.
- **Tope de tags por proveedor.** La cardinalidad es ilimitada; si hiciera falta un máximo, se pone en el formulario del spec que lo necesite, no en el campo.
- **Tags en otros bundles.** El vocabulario se crea para `provider`. Etiquetar solicitudes u ofertas es otro spec y otra instancia.
- **Términos iniciales.** El vocabulario nace vacío; los términos aparecen conforme el operador los escribe.
- **Ocultar o reordenar campos en el formulario del back office.** El peso de los campos nuevos queda en el que Drupal asigne; no se toca `hook_form_alter()`.
- **Migrar texto de `field_services_desc` a `field_short_description`.** El campo nuevo nace vacío en todos los proveedores existentes; recortar a mano las descripciones ya escritas es trabajo del operador si lo quiere.
- **Índices SQL propios** sobre `field_data_field_hourly_rate` o `field_data_field_tags`. Drupal crea los suyos; afinarlos exige antes conocer las consultas del endpoint, que no existe.
- **Claves de catálogo `myapi_t()` / i18n.** No hay respuesta de API que traducir todavía.

Dos decisiones enterradas en el alcance que conviene ver ya, porque son las que más pueden discutirse después:

- **El `hook_update_N` llama al instalador entero, no solo a la parte nueva.** Es lo que ya hace `myapi_update_7025()`, y funciona porque cada `_ensure_*` comprueba antes de escribir. La alternativa —un update quirúrgico con solo los tres campos— duplicaría las definiciones en dos sitios y abriría la puerta a que diverjan.
- **El vocabulario `provider_tag` no lleva `field_tag_code`**, a diferencia de `service_category`. Queda escrito aquí para que un spec futuro no lo lea como un olvido.

---

## Modelo de datos

No se crea ninguna tabla SQL propia (sin `hook_schema()`). Se crean **entidades de configuración**: 1 vocabulario, 3 campos y 3 instancias. Drupal genera por su cuenta `field_data_field_hourly_rate`, `field_data_field_tags`, `field_data_field_short_description` y sus gemelas `field_revision_*`.

### Vocabulario «Etiqueta de proveedor» (`provider_tag`)

| Ajuste | Valor |
|---|---|
| `machine_name` / `name` | `provider_tag` / Etiqueta de proveedor |
| Descripción | Etiquetas libres de proveedores: urgencias, 24h, soldadura, garantía... Se crean solas al escribirlas en la ficha del proveedor. |
| Jerarquía | Plana (`hierarchy = 0`) |
| Campos propios | **Ninguno.** A diferencia de `service_category`, no lleva código ni ícono. |

Nace vacío. La constante es `MYAPI_SERVICES_TAG_VOCABULARY`, en `includes/myapi.services_common.inc`, junto a `MYAPI_SERVICES_CATEGORY_VOCABULARY`.

### Los tres campos nuevos de `provider`

| Campo | Tipo | Card. | Req. | Widget | Ajustes de campo |
|---|---|:---:|:---:|---|---|
| `field_hourly_rate` | `number_decimal` | 1 | No | `number` | `precision = 10`, `scale = 2` |
| `field_tags` | `taxonomy_term_reference` → `provider_tag` | ∞ | No | `taxonomy_autocomplete` | `allowed_values = [['vocabulary' => 'provider_tag', 'parent' => 0]]` |
| `field_short_description` | `text` | 1 | No | `text_textfield` | `max_length = 255` |

Ajustes de **instancia**, que son los que un spec futuro puede cambiar por bundle sin tocar a nadie más:

| Campo | Etiqueta | Ajustes de instancia | Descripción en el formulario |
|---|---|---|---|
| `field_hourly_rate` | Valor hora | `min = 0`, `prefix = '$ '` | «Tarifa por hora que cobra el proveedor. Es informativa: se muestra al residente como precio de referencia y no condiciona el monto de ninguna oferta.» |
| `field_tags` | Etiquetas | — | «Separadas por coma. Si la etiqueta no existe, se crea al guardar.» |
| `field_short_description` | Descripción corta | — | «Una línea para el listado del marketplace. Máximo 255 caracteres. La descripción completa va en Descripción de servicios.» |

Tres precisiones sobre los ajustes:

- **`precision = 10, scale = 2`** es el mismo tamaño que `field_offer_amount` (SPEC 77): hasta `99999999.99`. No se copia el `3,2` de `field_rating_avg`, que está dimensionado para un promedio de 1 a 5.
- **`min = 0`** lo aplica `number_field_widget_form()` como `#element_validate` del propio widget: guardar `-15` desde el formulario del back office falla con un error de validación. No es una restricción de la columna SQL — un `node_save()` programático puede seguir escribiendo un negativo, y por eso la validación de negocio, cuando exista, tendrá que repetirla.
- **`prefix = '$ '`** solo pinta en el formulario y en la vista del nodo en el back office. **No viaja a la API** ni se guarda en la base de datos: el valor almacenado es el número pelado.

### Cómo nacen los términos de `field_tags`

El widget `taxonomy_autocomplete` de Drupal 7 crea los términos que no existen **de forma nativa**, sin ningún ajuste: al validar el formulario, un nombre desconocido entra como `['tid' => 'autocreate', 'name' => 'urgencias', 'vid' => N]`, y `taxonomy_field_presave()` lo guarda como término real antes de escribir la fila del campo. No hace falta `'auto_create' => TRUE` — ese ajuste pertenece a `entityreference`, no a taxonomía.

Dos consecuencias que hay que tener presentes:

- **La coincidencia es exacta e insensible a mayúsculas.** Teclear «Urgencias» cuando ya existe «urgencias» reutiliza el término existente. Teclear «urgencias 24h» crea uno nuevo aunque exista «urgencias».
- **Una coma dentro del nombre lo parte en dos tags.** Es la sintaxis del widget: la coma es el separador. Un tag con coma exige comillas dobles (`"lunes, martes"`), tal como documenta Drupal.

### Estado de `provider` después de este spec

Doce campos en total: los nueve de SPEC 77 más estos tres. Ninguno de los nueve cambia de tipo, cardinalidad, requerimiento ni ajuste.

| Ya existían (SPEC 77) | Nuevos (este spec) |
|---|---|
| `field_provider_users`, `field_phone`, `field_address`, `field_services_desc`, `field_photo`, `field_license_expiry`, `field_categories`, `field_rating_avg`, `field_rating_count` | `field_hourly_rate`, `field_tags`, `field_short_description` |

Y la regla de proveedor activo (`myapi_services_provider_is_active()`) **no cambia**: sigue siendo `status = 1 AND field_license_expiry >= ahora`. Ni la tarifa, ni los tags, ni la descripción corta entran en ella, así que el `providers_count` de `/api/v1/service-categories` devuelve exactamente los mismos números antes y después.

### Ningún campo es prestado

Los tres nombres son nuevos en todo el módulo: `field_info_field('field_hourly_rate')`, `field_tags` y `field_short_description` no existen hoy. Esto importa para el uninstall — los tres son **propios** y entran en la lista `$owned`, sin la cautela que exigen los siete prestados de SPEC 77.

**Sobre el nombre `field_tags`**, que es genérico para un campo que hoy solo vive en `provider`: se elige frente a `field_provider_tags` porque el vocabulario ya lleva el ámbito en su nombre y porque, si mañana se etiquetan solicitudes, el mismo campo sirve con otra instancia.

---

## Plan de implementación

1. **`includes/myapi.services_common.inc` — la constante.** `MYAPI_SERVICES_TAG_VOCABULARY` (`'provider_tag'`), guardada con `if (!defined(...))`, justo debajo de `MYAPI_SERVICES_CATEGORY_VOCABULARY` y con un docblock que diga en una línea qué la distingue de aquella (libre y sin código, frente a cerrada y con código estable). Va primero porque `myapi.install` la lee. *Verificación: `php -l includes/myapi.services_common.inc`.*

2. **`myapi.install` — el vocabulario.** Dentro de `_myapi_services_install()`, una segunda llamada a `_myapi_services_ensure_vocabulary()` inmediatamente después de la de `service_category`, con su nombre y descripción. Sin campos ni instancias detrás: este vocabulario no tiene ninguno. *Verificación: reejecutable sin duplicar ni sobrescribir un vocabulario ya ajustado a mano en el sitio — es lo que garantiza el `return` temprano del sub-helper.*

3. **`myapi.install` — los tres campos.** En el bloque «Own fields of 'provider'», después de `field_rating_count`, tres `_myapi_reservations_ensure_field()`. El de `field_tags` necesita su propio `$tag_settings` con el `allowed_values` del vocabulario nuevo, escrito junto a `$category_settings` para que los dos se lean de un vistazo y nadie los confunda. *Verificación: `php -l`; los campos existen en `admin/reports/fields` tras el paso 6.*

4. **`myapi.install` — las tres instancias.** En el bloque de instancias de `$provider_type`, después de `field_rating_count`, con las etiquetas, descripciones y ajustes de la sección anterior. Las tres con `'required' => 0` explícito, aunque sea el valor por defecto, porque es la decisión que impide romper los proveedores ya cargados y tiene que verse al leer el código. *Verificación: `php -l`.*

5. **`myapi.install` — el uninstall.** Los tres nombres al final de la lista `$owned` de `_myapi_services_uninstall_destructive()`, y un segundo bloque de borrado de vocabulario para `provider_tag` junto al de `service_category`. *Verificación: el test del paso 7 falla si un nombre prestado se cuela en `$owned`; con la constante en `FALSE`, `drush pm-uninstall myapi` sigue sin borrar nada.*

6. **`myapi.install` — `myapi_update_7028()`.** Una llamada a `_myapi_services_install()` y un mensaje `t()` que nombre los tres campos y el vocabulario. El docblock explica por qué reejecuta el instalador entero en vez de solo lo nuevo, y que por eso es seguro llamarlo dos veces. *Verificación: `drush updb` en el sitio ofrece la actualización 7028, la aplica sin error, y una segunda pasada ya no la ofrece.*

7. **Pruebas.** Ampliación de `tests/unit/ServicesInstallTest.php`, siguiendo su reparto actual entre asserts sobre catálogos puros y guards que leen `myapi.install` como texto:
   - La constante `MYAPI_SERVICES_TAG_VOCABULARY` existe, vale `provider_tag` y **no** coincide con `MYAPI_SERVICES_CATEGORY_VOCABULARY`.
   - El instalador crea el vocabulario nuevo y los tres campos, con sus tipos, cardinalidades y ajustes exactos (`precision`/`scale`, `max_length`, `allowed_values` apuntando a `provider_tag` y no a `service_category`).
   - Las tres instancias cuelgan de `provider`, son opcionales y llevan el widget documentado; la de la tarifa lleva `min = 0`.
   - Los tres campos están en `$owned` y el teardown destructivo borra los **dos** vocabularios.
   - Ningún campo prestado ha entrado en `$owned` (el guard que ya existe, que debe seguir en verde con la lista ampliada).
   - Los nueve campos de SPEC 77 siguen declarados igual: un test de no regresión sobre el bundle completo, que falla si este spec toca uno por accidente.

   *Verificación: suite completa en verde.*

8. **`docs/services-install.md`.** Los tres campos en la tabla del bundle `provider`, el vocabulario nuevo en su sección, la nota del autocreado de términos y su sintaxis de comas, la aclaración de que `prefix` no viaja a la API, y `myapi_update_7028` en el historial de actualizaciones del documento.

9. **Aplicar y verificar.** `drush updb`, `drush cc all`, y recorrer los criterios de aceptación contra el sitio: crear un proveedor con los tres campos vacíos, editar uno existente sin tocarlos, y crear otro con tarifa, dos tags nuevos y descripción corta.

**Nota:** no se toca `myapi.module` (no hay rutas nuevas), ni `myapi.info` (no hay ficheros nuevos), ni `hook_schema()`, ni ningún fichero de `resources/`, ni `includes/myapi.provider_role.inc`, ni `_myapi_entityreference_field_settings()` — ninguno de los tres campos es `entityreference`.

Dos cosas que este plan hace a propósito y podrían parecer de más:

- **El paso 7 incluye un test de no regresión sobre los nueve campos viejos.** Cuesta poco y es la única red que hay: como el instalador es un fichero largo de definiciones seguidas, un `ensure_field` mal pegado a tres líneas del sitio correcto pasaría desapercibido en revisión.
- **El paso 4 escribe `'required' => 0` aunque sea el valor por defecto.** Es redundante para PHP y no lo es para quien lea el código dentro de seis meses.

---

## Criterios de aceptación

**Leyenda.** `[ ]` es el estado inicial de todo criterio en un spec en `Draft`. Al implementar, se marcan `[x]` los verificados por la suite unitaria o por inspección del repositorio, y se deja constancia expresa de los que exigen el sitio (`drush`, formulario real del back office).

**Instalación e idempotencia**

- [ ] En un sitio limpio, `drush en myapi` crea el vocabulario `provider_tag` (visible en `admin/structure/taxonomy`) y los tres campos en `provider` (visibles en `admin/structure/types/manage/provider/fields`).
- [ ] En el sitio donde `myapi` **ya** está instalado, `drush updb` ofrece `myapi_update_7028`, la aplica sin error y devuelve el mensaje que nombra los tres campos.
- [ ] Una segunda pasada de `drush updb` ya no ofrece la actualización, y ejecutar `_myapi_services_install()` a mano por segunda vez no duplica vocabulario, campos ni instancias, y no lanza `FieldException`.
- [ ] `drush pm-uninstall myapi` con `MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL = FALSE` no borra ninguno de los tres campos ni el vocabulario nuevo.
- [ ] La suite unitaria pasa completa, con los casos nuevos incluidos.

**Valor hora**

- [ ] El formulario de `provider` muestra «Valor hora» con el prefijo `$` y acepta guardarse **vacío**.
- [ ] Guardar `25.50` almacena exactamente `25.50`; guardar `25` almacena `25.00`.
- [ ] Guardar `-5` es rechazado por el formulario con un error de validación, y el nodo no se guarda.
- [ ] El prefijo `$ ` **no** aparece en la columna `field_hourly_rate_value` de la base de datos: lo almacenado es el número pelado.
- [ ] El campo admite `99999999.99` sin truncar y sin advertencia.

**Etiquetas**

- [ ] El campo «Etiquetas» es un autocompletado, no una lista de casillas ni un `select`.
- [ ] Escribir `urgencias, 24h` en un vocabulario vacío guarda el nodo y **crea los dos términos** en `provider_tag`, visibles en `admin/structure/taxonomy/provider_tag`.
- [ ] Escribir `Urgencias` en otro proveedor cuando ya existe `urgencias` **reutiliza** el término existente: el vocabulario sigue teniendo un solo término, no dos.
- [ ] El autocompletado ofrece **solo** términos de `provider_tag`: no sugiere categorías de servicio, ni bancos, ni ningún otro vocabulario del sitio.
- [ ] Un proveedor admite más de diez etiquetas sin que el formulario lo impida.
- [ ] El campo acepta guardarse **vacío**.

**Descripción corta**

- [ ] El campo es un `input` de una línea, no un `textarea`, y no ofrece selector de formato de texto.
- [ ] Un texto de 255 caracteres se guarda entero; el formulario impide teclear el 256.
- [ ] El campo acepta guardarse **vacío**.
- [ ] `field_services_desc` sigue siendo requerida y sigue siendo un `textarea`: la descripción corta **no** la sustituye ni la afecta.

**No regresión — la parte que de verdad importa de este spec**

- [ ] Un nodo `provider` creado **antes** de la actualización se abre en el formulario de edición, se guarda sin tocar nada y **no** pide ninguno de los tres campos nuevos.
- [ ] Los nueve campos de SPEC 77 conservan tipo, cardinalidad, requerimiento y ajustes: `field_services_desc` sigue requerida, `field_categories` sigue siendo checkboxes de `service_category`, `field_license_expiry` sigue requerida.
- [ ] El vocabulario `service_category` y sus dos campos (`field_category_code`, `field_category_icon`) quedan intactos, con todos sus términos.
- [ ] Ningún campo prestado (`field_description`, `field_images`, `field_attachment`, `field_requester`, `field_condominium`, `field_status_date`, `field_comment`) aparece en la lista `$owned`, y un test unitario falla si alguien lo añade.
- [ ] `GET /api/v1/service-categories` devuelve **byte a byte** lo mismo que antes, con y sin `?with_counts=1`: los tres campos nuevos no entran en la regla de proveedor activo y no cambian ningún conteo.
- [ ] Ningún otro endpoint `api/v1/...` cambia de respuesta: `git diff` vacío en todo `resources/`.
- [ ] Ningún rol gana ni pierde permisos: `/admin/people/permissions` es idéntico antes y después.
- [ ] `myapi.info` no cambia y no declara ninguna dependencia nueva — `number`, `taxonomy` y `text` ya están cubiertos por core o por SPEC 77.
- [ ] `myapi_update_7027` y anteriores quedan intactos.
- [ ] `drush cc all` no reporta errores.

Un apunte sobre dos criterios que parecen triviales y no lo son:

- **«El prefijo `$ ` no aparece en la base de datos»** está escrito porque es el error clásico de los campos `number` con prefijo: se ve `$ 25.50` en pantalla y se asume que eso es el dato. El día que el endpoint de proveedores devuelva la tarifa, quien lo escriba tiene que saber que le llega `25.50` y que el `$` lo pone la app.
- **«Un proveedor creado antes se guarda sin tocar nada»** es el criterio que valida la decisión de que los tres campos sean opcionales. Si alguna vez alguien los hace requeridos, este criterio es el primero que se rompe.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Los tres campos requeridos u opcionales | **Los tres opcionales** | Requerir el valor hora, o la descripción corta | Elección explícita del usuario. Hay oficios que no cobran por hora sino por presupuesto cerrado, así que exigir la tarifa obligaría a inventar un número. Y además: un campo requerido nuevo deja inválido cualquier `provider` ya cargado en cuanto alguien lo abra para editarlo, sin que nadie lo haya pedido. |
| Tipo del valor hora | `number_decimal` con `precision = 10`, `scale = 2` | `number_integer`; o `number_float` | Elección explícita del usuario. Entero perdería los centavos, que en una tarifa por hora son dinero real. `number_float` guarda en coma flotante binaria, donde `0.1 + 0.2` no es `0.3`: para dinero se usa decimal exacto. El tamaño es el mismo que `field_offer_amount` (SPEC 77), que es el otro campo monetario de la feature. |
| Moneda | **Ninguna.** Moneda implícita del módulo, como `field_offer_amount` | Un `field_currency` junto a la tarifa | Elección explícita del usuario: el valor es informativo, para que el residente vea desde cuánto cobra el proveedor. Un campo de moneda no sería solo de proveedores — arrastraría ofertas, pagos y recibos, y obliga a decidir catálogo, conversión y qué pasa con los datos ya escritos. Es un spec propio si algún día el sitio es multi-moneda. |
| Semántica de la tarifa | **Informativa**, sin efecto sobre las ofertas | Que valide o precargue `field_offer_amount` | Elección explícita del usuario. Atar la oferta a la tarifa publicada convierte un dato de escaparate en una regla de negocio, y la primera excepción («esta obra la cobro a tanto alzado») rompería la regla. |
| `min = 0` en la tarifa | **Sí**, ajuste de instancia | Sin restricción; o validarlo en `hook_node_validate()` | Una tarifa negativa solo puede ser un error de tecleo. El ajuste de instancia lo da el widget gratis; escribir un `hook_node_validate()` sería código de módulo para lo que la configuración ya resuelve. Queda anotado que **no** es una restricción SQL: un `node_save()` programático puede escribir un negativo, y la validación de negocio tendrá que repetirla cuando exista. |
| `prefix = '$ '` | **Sí**, solo en el back office | Sin prefijo; o incluir el símbolo en el dato | Ayuda al operador a no dudar de qué unidad está escribiendo, y no toca el dato: lo almacenado es el número pelado. Incluir el símbolo en el valor sería guardar una cadena donde hay un número. |
| Forma de los tags | **Vocabulario `provider_tag` con widget `taxonomy_autocomplete`** | (a) Vocabulario cerrado con casillas; (b) campo `text` de cardinalidad ilimitada | Elección explícita del usuario. El cerrado obliga al operador a cargar los términos antes de poder usarlos, que es fricción para un dato que es descriptivo por naturaleza. El `text` libre es más barato hoy y más caro siempre después: «Urgencias» y «urgencias» serían dos cadenas distintas para siempre, no hay forma de listar los tags existentes, ni de renombrar uno en un solo sitio, ni de filtrar por él sin un `LIKE`. |
| Autocreado de términos | El **nativo** del widget `taxonomy_autocomplete` de D7 | Un ajuste `'auto_create' => TRUE` | No existe tal ajuste en taxonomía: es de `entityreference`. En D7, `taxonomy_field_presave()` guarda los términos marcados `autocreate` por sí solo. Escribirlo habría sido configuración muerta que alguien acabaría "arreglando". |
| Tags y categorías, ¿el mismo eje? | **Dos campos distintos**: `field_categories` (cerrado, con código) y `field_tags` (libre, sin código) | Ampliar `service_category` con más términos y usar uno solo | Son dos ejes con reglas opuestas. La categoría estructura el marketplace: la app tiene lógica por categoría, por eso SPEC 79 le dio `field_category_code` estable, y por eso es requerida y cerrada. El tag es descriptivo, libre y opcional. Mezclarlos llenaría el grid de categorías de la app con «24h» y «garantía». |
| Código estable para los tags | **No**, el vocabulario nace sin campos | Un `field_tag_code` como el de `service_category` | El código de categoría existe porque la app cuelga lógica de él (íconos, pantallas). Un tag se pinta y ya. Exigir un código en cada tag anularía la ventaja del autocompletado: el operador tendría que abrir el vocabulario, crear el término e inventarle un código antes de poder usarlo. |
| Cardinalidad de los tags | **Ilimitada**, sin tope | Un máximo (10 o similar) en el campo | Un tope en el campo se convierte en un `hook_update_N` el día que alguien necesite once. Si hay que limitarlo, se limita donde se decide la experiencia — el formulario o el endpoint —, no en el almacenamiento. |
| Nombre del campo de tags | `field_tags` | `field_provider_tags` | El vocabulario ya lleva el ámbito en su nombre (`provider_tag`), y si mañana se etiquetan solicitudes u ofertas, el mismo campo sirve con otra instancia. Es el mismo criterio con el que SPEC 77 compartió `field_request` entre dos bundles. |
| Tipo de la descripción corta | `text` de 255, una línea | `text_long`; o `text_with_summary` | Un `text_long` invita a escribir párrafos, que es exactamente lo que ya hace `field_services_desc`: tendríamos dos campos largos y nadie sabría cuál va en la tarjeta. El límite de 255 es la restricción que hace que el campo cumpla su función. `text_with_summary` resolvería lo mismo con un campo compuesto, pero cambiaría `field_services_desc` — un campo prestado por ningún sitio hoy, pero ya cargado con datos. |
| Descripción corta con formato de texto | **Sin** `text_processing`: texto plano, sin selector de formato | Con formato y `plain_text` fijado, como `field_services_desc` | Es una línea sin editor rico: no hay marcado que filtrar, y la columna `format` sería siempre el mismo valor. Es el patrón que ya siguen `field_phone` y `field_category_code`. |
| Relación con `field_services_desc` | **Convivir**: corta para el listado, larga para el detalle | Sustituir la larga por la corta | Sustituirla perdería el texto ya escrito en los proveedores existentes: un cambio destructivo por un requisito que es aditivo. |
| Rellenar la descripción corta de los proveedores existentes | **No**, nacen vacías | Copiar los primeros 255 caracteres de `field_services_desc` en el update | Un recorte automático parte frases por la mitad y produce texto que nadie escribió, publicado en la tarjeta del marketplace. Vacío es honesto: la app pinta lo que haya, y el operador rellena lo que quiera. |
| Forma del `hook_update_N` | `myapi_update_7028()` llama a `_myapi_services_install()` **entero** | Un update quirúrgico con solo el vocabulario, los tres campos y las tres instancias | Es el patrón de `myapi_update_7025()` y funciona porque cada `_ensure_*` comprueba antes de escribir. La versión quirúrgica duplicaría las definiciones en dos sitios del mismo fichero, con la certeza de que un día divergen. |
| Uninstall destructivo | Los tres campos a `$owned`; el vocabulario `provider_tag` borrado junto a `service_category` | Tratarlos con la cautela de los campos prestados | Los tres nombres son nuevos en todo el módulo: ningún otro bundle los usa, así que borrarlos no puede llevarse por delante datos ajenos. La cautela de SPEC 77 existe por los siete campos que sí comparte con reclamos y reservas, y esos siguen protegidos por su test. |
| Alcance | **Solo instalación**: ningún endpoint | Estrenar aquí `GET /api/v1/providers` con los campos nuevos | Elección explícita del usuario. Un endpoint de proveedores exige decidir filtros, paginación, orden por calificación, alcance por condominio y formato de cada campo — cinco decisiones de producto que no caben en un spec cuyo objetivo cabe en una frase. Los campos nacen invisibles y esperan. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El vocabulario de tags se ensucia solo.** Es el riesgo real de este spec: con autocreado libre, en un mes puede haber «urgencias», «Urgencias 24h», «urgente» y «emergencias» significando lo mismo, y ningún filtro futuro por tag funcionará bien. | Aceptado a cambio de la agilidad, que es lo que se pidió. Se acota con tres cosas: el autocompletado sugiere lo existente antes de crear (la coincidencia es insensible a mayúsculas, así que el caso más frecuente ya se reutiliza solo), el vocabulario es fusionable a mano desde `admin/structure/taxonomy/provider_tag` sin perder las referencias, y `docs/services-install.md` deja escrito que mantenerlo limpio es tarea operativa, no del código. Si el filtro por tag llega a la API, ese spec decidirá si necesita normalización. |
| **Una coma dentro de un tag lo parte en dos.** Es la sintaxis del widget, y el operador que escriba «lunes, martes y feriados» acabará con tres tags sin darse cuenta. | Documentado en la descripción del campo en el propio formulario y en `docs/services-install.md`, con la salida (comillas dobles). No se intenta corregir en código: cambiar el separador del widget nativo sería reescribir taxonomía para un caso de borde. |
| **`min = 0` no protege el `node_save()` programático.** El día que exista un endpoint de alta o edición de proveedores, escribir `-15` en `field_hourly_rate` funcionará: el ajuste de instancia solo lo aplica el widget del formulario. | Anotado en el docblock de la instancia, en la tabla de decisiones y en la doc, precisamente para que el spec del endpoint no lo descubra en producción. Es el mismo tipo de duplicación que SPEC 79 ya aceptó y documentó con la regla de «proveedor activo» en PHP y en SQL. |
| **La tarifa se lee como un compromiso de precio.** El residente ve «$25/h», el proveedor oferta $40 y hay una discusión que el módulo no puede arbitrar. | Es una decisión de producto ya tomada: el campo es informativo y así lo dice su descripción en el formulario. El día que la app lo pinte, la etiqueta que elija («desde», «precio de referencia») es lo que cierra el asunto, y ese es el spec del endpoint. |
| **255 caracteres pueden quedarse cortos** para lo que el operador quiere poner en la tarjeta, y ampliar el `max_length` de un campo `text` con datos ya cargados no es trivial en Drupal 7. | Es el único de los tres campos cuyo cambio futuro tiene coste. Se asume: 255 es el tamaño estándar de una línea y el que usan `field_firebase_path` y `field_category_code` en su escala. Si se quedara corto, la salida no es ampliar la columna sino que la tarjeta use `field_services_desc` recortada, que es lo que haría hoy si el campo estuviera vacío. |
| **Tres campos que nadie consume.** Nacen invisibles para la app, igual que los tres campos de chat de SPEC 77, y hasta que llegue el endpoint de proveedores son columnas vacías que el operador rellena sin ver resultado. | Aceptado y explícito en el objetivo. Es más barato que la alternativa: crear las columnas después cuesta otro `hook_update_N`, otro deploy y otra ventana de datos a medias. El coste hoy es cero para la app y cero para las respuestas existentes. |
| **Un `hook_update_N` que reejecuta el instalador entero** toca vocabulario, cinco bundles y veintinueve campos para añadir tres. Si alguna definición de SPEC 77 hubiera divergido a mano en el sitio, este update la vería. | En realidad **no** la sobrescribe: los `_ensure_*` hacen `return` temprano si la cosa ya existe, así que un campo ajustado a mano se queda como está. El riesgo real es el inverso —que un ajuste manual sobreviva y nadie lo sepa—, y es el mismo que ya asumió `myapi_update_7025()`. El criterio de no regresión sobre los nueve campos viejos es lo que lo vigila. |
| **El bundle `provider` crece a doce campos** en un formulario de back office sin agrupar, cada vez más largo de rellenar. | Fuera de alcance por acuerdo, y sin coste técnico: es ergonomía del operador. Cuando estorbe, `field_group` o un `hook_form_alter()` lo resuelven sin tocar ni un dato. |
