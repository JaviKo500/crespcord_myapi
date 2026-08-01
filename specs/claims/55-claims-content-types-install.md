# 55 — Tipos de contenido de reclamos y sugerencias (`reclamo` y `claim_transaction`)

- **Estado:** Implemented
- **Fecha:** 2026-07-31
- **Dependencias:**
  - `32-reservations-content-types-install` (Implemented) — patrón idempotente `_myapi_reservations_ensure_node_type()` / `_ensure_field()` / `_ensure_instance()` en `myapi.install`, y los campos compartidos `field_condominium` (→ bundle `condominio`) y `field_requester` (→ entidad `user`), que este spec reutiliza sin modificarlos.
  - `49-building-admin-role` (Implemented) — define `MYAPI_BUILDING_ADMIN_CLAIM_TYPE = 'reclamo'` (nombre de máquina que este spec respeta) y `myapi_building_admin_editable_types()`, que ya incluye `'reclamo'` condicionalmente en cuanto el bundle exista.
  - `51-building-admin-people-scope` / `docs/building-admin-role.md` (Implemented) — documentan que agregar la entrada de `reclamo` a `myapi_building_admin_condominium_map()` es "el último paso del spec de ese bundle"; este spec cumple esa promesa.
  - `53-entityreference-selection-settings` (Implemented) — `_myapi_entityreference_field_settings()`, catálogo donde deben registrarse los campos `entityreference` nuevos para salir bien configurados desde el día uno.

**Objetivo:** Crear de forma idempotente, al instalar y al actualizar el módulo, los content types **`reclamo`** (reclamos y requerimientos) y **`claim_transaction`** (línea de tiempo de cambios de estado), con todos sus campos y la relación 1:N entre ambos, enganchando `reclamo` al rol `administrador edificio` — sin crear ningún endpoint de API, interfaz de administración ni lógica de notificación.

---

## Alcance

**Dentro del alcance:**

- **`myapi.install`** (modificar):
  - Nuevo helper idempotente **`_myapi_claims_install()`**, en el mismo estilo que `_myapi_reservations_install()` (SPEC 32): orquesta la creación de los dos content types, los campos y las instancias, todo idempotente. **No se crean sub-helpers nuevos** — reutiliza tal cual `_myapi_reservations_ensure_node_type()`, `_myapi_reservations_ensure_field()` y `_myapi_reservations_ensure_instance()`, que SPEC 32/49 ya dejaron genéricos (no atados a reservas).
  - `hook_install()` (`myapi_install()`) llama a `_myapi_claims_install()` además de lo que ya llama → instalaciones nuevas quedan completas.
  - Nuevo **`myapi_update_7017()`** que llama al mismo `_myapi_claims_install()` → sitios donde el módulo ya está instalado obtienen los content types vía `drush updb`. *(Siguiente número libre: existen hasta `myapi_update_7016`; `7014` aparece referenciado en dos comentarios pero su función nunca llegó a existir en el repo — un gap preexistente que este spec no toca ni corrige.)*
  - `hook_uninstall()` conservador: nueva constante **`MYAPI_CLAIMS_DESTRUCTIVE_UNINSTALL`** (`FALSE` por defecto, igual patrón que `MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL` pero propia de esta feature), que guarda un bloque de borrado que no se ejecuta por defecto.
- **Content type `reclamo`** — machine name ya reservado por SPEC 49/51 (`MYAPI_BUILDING_ADMIN_CLAIM_TYPE`). Con sus campos (detalle en la sección Modelo de datos) y título nativo = "Asunto".
- **Content type `claim_transaction`** — machine name nuevo, la línea de tiempo de cambios de estado. Título nativo, sin uso funcional (mismo criterio que "Reserva" en SPEC 32).
- **Campos nuevos, compartidos entre ambos bundles** (un campo, dos instancias, mismo patrón que `field_condominium`): `field_images` (imagen, cardinalidad ilimitada) y `field_attachment` (archivo, cardinalidad 1).
- **Reutilización de campos ya existentes** creados por SPEC 32, nueva instancia sobre `reclamo`: `field_condominium` (→ bundle `condominio`) y `field_requester` (→ entidad `user`).
- **`includes/myapi.building_admin.inc`** (modificar) — una sola entrada nueva en `myapi_building_admin_condominium_map()`: `'reclamo' => ['mode' => 'direct', 'field' => 'field_condominium']`. Es el "último paso" que `docs/building-admin-role.md` ya deja pendiente para este bundle. `myapi_building_admin_editable_types()` no se toca: ya incluye `'reclamo'` condicionalmente desde SPEC 49.
- **`docs/building-admin-role.md`** (modificar) — corregir las notas que hoy dicen "`reclamo` no existe todavía" y la que anticipaba `field_condominio` (en español) como su campo de condominio: pasa a `field_condominium`.
- **`docs/claims-install.md`** (nuevo) — ambos content types, sus campos, la idempotencia, el flujo `drush updb`, la política de uninstall y la entrada al mapa de condominios.
- `drush cc all` al final. No se declara ninguna dependencia nueva en `myapi.info` (`entityreference`, `date`, `image`, `file`, `list`, `text`, `number` ya están cubiertas por core o por SPEC 32).

**Fuera de alcance (para specs futuros):**

- **Cualquier endpoint `api/v1/...`** — crear un reclamo desde la app, listarlos, ver el detalle o el tracking. Hoy no existe ningún `resources/claim.resource.inc`; este spec no lo crea.
- **Cualquier interfaz de administración a medida.** Listar, filtrar, ver detalle y adjuntos se resuelve con las pantallas nativas de Drupal (`node/add/reclamo`, `/node/N`, `/admin/content`), que ya heredan el filtro por condominio del rol en cuanto se agrega la entrada al mapa.
- **Lógica de negocio del flujo de estados**: qué transiciones son válidas, quién puede cambiarlas, si puede haber más de una transacción "abierta", etc. Este spec no valida nada de eso — solo crea el campo `field_status` y su catálogo de valores.
- **Notificación push/inbox al residente** cuando cambia el estado. Mismo patrón que `48-reservation-notifications`, en un spec propio.
- **Enganchar `claim_transaction` al rol `administrador edificio`.** Su condominio no es resoluble con los modos que hoy soporta `myapi_building_admin_condominium_map()` (`self` / `direct` / `via_unit`): cuelga de `field_claim` → `reclamo` → `field_condominium`, y ese modo de resolución (`via_claim` o similar) no existe todavía. Por eso `claim_transaction` **no** entra en `myapi_building_admin_editable_types()` en este spec: concederle permisos de creación/edición sin poder acotarlos por condominio abriría el registro de cambios de estado de todos los edificios a cualquier administrador de edificio. Queda para el spec que construya el flujo real de cambio de estado.
- **Migrar o crear nodos de datos reales** (reclamos existentes). Solo estructura, cero contenido.
- **Modificar los bundles `condominio` o `vivienda`.**
- **Título autogenerado** de `claim_transaction`, ni displays/view modes de ningún campo.
- **Claves del catálogo `myapi_t()` / i18n** — no hay respuesta de API que traducir.

---

## Modelo de datos

No se crean tablas SQL propias (no hay `hook_schema()` nuevo). Se crean **entidades de configuración Field API**: 2 bundles de nodo, 4 campos nuevos (2 propios de `reclamo`, 1 propio de `claim_transaction`, y 2 compartidos entre ambos) y N instancias. Drupal genera automáticamente las tablas `field_data_*` / `field_revision_*`.

### Content type «Reclamo» (`reclamo`)

| Ajuste | Valor |
|---|---|
| `type` / `name` | `reclamo` / `Reclamo` |
| `base` | `node_content` |
| `description` | Reclamo o requerimiento presentado por un residente. |
| `has_title` / `title_label` | `1` / `Asunto` (título nativo = el campo "Asunto" del enunciado) |
| Publicación | `node_options_reclamo = ['status']` → publicado; sin promote, sin sticky |
| Comentarios | `comment_reclamo = COMMENT_NODE_HIDDEN` |
| Fecha de creación | campo nativo `created` del nodo (no se crea campo custom) |
| ID | `nid` nativo |

### Content type «Transacción de reclamo» (`claim_transaction`)

| Ajuste | Valor |
|---|---|
| `type` / `name` | `claim_transaction` / `Transacción de reclamo` |
| `base` | `node_content` |
| `description` | Entrada de la línea de tiempo de un reclamo: cambio de estado con comentario opcional del administrador. |
| `has_title` / `title_label` | `1` / `Título` (nativo; irrelevante para este spec, sin autogeneración — mismo criterio que «Reserva» en SPEC 32) |
| Publicación | `node_options_claim_transaction = ['status']` → publicado; sin promote, sin sticky |
| Comentarios | `comment_claim_transaction = COMMENT_NODE_HIDDEN` |
| Fecha de creación | campo nativo `created` del nodo |
| Usuario (creación) | `uid` nativo del nodo (autor) — sin campo custom |
| ID | `nid` nativo |

### Campos compartidos entre `reclamo` y `claim_transaction` (a nivel de campo, se crean UNA vez)

| Campo | `type` | Cardinalidad | Settings de campo |
|---|---|---|---|
| `field_images` | `image` | Ilimitada (`-1`) | — |
| `field_attachment` | `file` | 1 | — |

Cada uno se crea una sola vez con `field_create_field()` y se añade como **instancia** a ambos bundles, con settings de instancia iguales en los dos:

| Instancia | Requerido | Widget | Settings de instancia |
|---|---|---|---|
| `field_images` | No | `image_image` | `file_extensions='png jpg jpeg'`, `max_filesize='3 MB'`, alt no obligatorio |
| `field_attachment` | No | `file_generic` | `file_extensions='pdf doc docx xls xlsx'`, `max_filesize='3 MB'` |

### Campos reutilizados de SPEC 32 (nueva instancia sobre `reclamo`, campo sin cambios)

| Instancia | Tipo de campo | Requerido | Widget | Target |
|---|---|---|---|---|
| `field_condominium` | `entityreference` | Sí | `entityreference_autocomplete` | bundle `condominio` (hereda del campo, ya corregido por SPEC 53) |
| `field_requester` | `entityreference` | Sí | `entityreference_autocomplete` | entidad `user`, sin `target_bundles` |

### Campos nuevos propios de `reclamo`

| Instancia | Tipo de campo | Requerido | Default | Widget | Settings |
|---|---|---|---|---|---|
| `field_description` | `text_long` | Sí | — | `text_textarea` | `default_value[0]['format'] = 'plain_text'` (evita a propósito el problema de formato de SPEC 49/`field_area_notes`) |
| `field_status` | `list_text` | Sí | `received` | `options_select` | `allowed_values`: `received\|Recibido`, `in_progress\|En proceso`, `resolved\|Resuelto`, `closed\|Cerrado`, `duplicated\|Duplicado` |
| `field_claim_type` | `list_text` | Sí | — | `options_select` | `allowed_values`: `requirement\|Requerimiento`, `claim\|Reclamo` |
| `field_reception_date` | `datetime` (módulo Date) | Sí | — | `date_select` | granularidad `year-month-day`, `tz_handling='none'`, solo fecha (`Y-m-d`) |
| `field_visibility` | `list_text` | Sí | `private` | `options_select` | `allowed_values`: `private\|Privado`, `public\|Público` |

### Campos nuevos propios de `claim_transaction`

| Instancia | Tipo de campo | Requerido | Default | Widget | Settings |
|---|---|---|---|---|---|
| `field_status` | `list_text` (mismo campo que en `reclamo`, nueva instancia) | Sí | — (sin default: se fuerza a elegir el nuevo estado) | `options_select` | mismo `allowed_values` que arriba — un solo catálogo, sin necesidad de separarlo como pasó con `field_area_status`/`field_reservation_status` en SPEC 32, porque aquí los cinco valores son idénticos en ambos bundles |
| `field_status_date` | `datetime` (módulo Date) | Sí | — | `date_select` | igual granularidad que `field_reception_date` |
| `field_comment` | `text_long` | No | — | `text_textarea` | `default_value[0]['format'] = 'plain_text'`, mismo criterio que `field_description` |
| `field_claim` | `entityreference` | Sí | — | `entityreference_autocomplete` | `target_type=node`, `target_bundles=['reclamo']`; registrado en `_myapi_entityreference_field_settings()` (SPEC 53) para que el settings viva en el campo, no en la instancia |

### Entrada nueva en `myapi_building_admin_condominium_map()`

```php
// includes/myapi.building_admin.inc
[
  // ... entradas existentes ...
  'reclamo' => ['mode' => 'direct', 'field' => 'field_condominium'],
]
```

Corrige además la nota de `docs/building-admin-role.md` que anticipaba `field_condominio` (español) para este bundle: el campo real, por decisión de este spec, es `field_condominium` (el compartido de SPEC 32).

`claim_transaction` **no** entra en este mapa ni en `myapi_building_admin_editable_types()` — motivo ya explicado en Alcance.

---

## Plan de implementación

1. **`includes/myapi.building_admin.inc` — registrar `field_claim` en `_myapi_entityreference_field_settings()`** (SPEC 53) con `target_bundles = ['reclamo']`. Se hace primero para que, en cuanto el campo exista (paso 2), ya nazca con el settings correcto y no dependa de ningún update de reparación futuro. *Verificación: `php -l`; el catálogo de SPEC 53 lista la nueva entrada.*

2. **`myapi.install` — helper `_myapi_claims_install()`.** Nueva función privada idempotente que orquesta: (a) los dos `_myapi_reservations_ensure_node_type()` (`reclamo`, `claim_transaction`); (b) `_myapi_reservations_ensure_field()` para los nueve campos nuevos (`field_images`, `field_attachment`, `field_description`, `field_status`, `field_claim_type`, `field_reception_date`, `field_visibility`, `field_status_date`, `field_comment`, `field_claim`); (c) las instancias por bundle, según las tablas del modelo de datos, incluidas las dos nuevas instancias de `field_condominium` y `field_requester` sobre `reclamo`. Reutiliza exclusivamente los sub-helpers ya genéricos de SPEC 32/49 — ningún sub-helper nuevo. *Verificación: función reejecutable sin duplicar campos ni instancias, sin lanzar `FieldException`.*

3. **`myapi.install` — enganchar en `hook_install()`.** En `myapi_install()`, añadir la llamada a `_myapi_claims_install()` junto a las que ya existen. *Verificación: en un sitio limpio, `drush en myapi` crea ambos content types y todos sus campos.*

4. **`myapi.install` — `myapi_update_7017()`.** Nuevo update hook que llama a `_myapi_claims_install()`, para sitios donde el módulo ya está instalado. Docblock explicando por qué es `7017` y no `7014`/`7015` (ya consumidos, con la nota del hueco del 7014 documentada como preexistente). *Verificación: en el sitio de producción, `drush updb` ejecuta 7017 y crea todo sin tocar el esquema existente.*

5. **`myapi.install` — `hook_uninstall()` conservador.** Definir `MYAPI_CLAIMS_DESTRUCTIVE_UNINSTALL = FALSE` junto a la constante de reservas, mismo comentario de advertencia. En `myapi_uninstall()`, bloque `if (MYAPI_CLAIMS_DESTRUCTIVE_UNINSTALL) { ... field_delete_field() / node_type_delete() de los dos bundles ... }`, que no se ejecuta por defecto. *Verificación: `drush pm-uninstall myapi` no borra nada de esto con la constante en `FALSE`.*

6. **`includes/myapi.building_admin.inc` — entrada en `myapi_building_admin_condominium_map()`.** La línea `'reclamo' => ['mode' => 'direct', 'field' => 'field_condominium']`. Este paso activa de verdad las dos mitades del filtro para `reclamo`: los permisos `create`/`edit any` ya se conceden solos desde SPEC 49 en cuanto el bundle exista (`myapi_building_admin_editable_types()` no se toca). Va **después** de que el bundle y `field_condominium` existan (pasos 2–4); si fuera antes, `hook_node_access()` intentaría resolver un campo que todavía no está en el tipo. *Verificación: con el rol `administrador edificio` y un condominio asignado, `/admin/content` filtra los nodos `reclamo` por condominio y `/node/N` de un reclamo ajeno da 403 — mismo criterio ya probado en SPEC 49 para `boletin`/`reservation`/`area`.*

7. **Documentación.** Crear `docs/claims-install.md` (mismo formato que `docs/reservations-install.md`): ambos content types, catálogo completo de campos, idempotencia, flujo `drush updb`, política de uninstall y la entrada nueva al mapa de condominios. Corregir en `docs/building-admin-role.md` las menciones que hoy dicen "`reclamo` no existe todavía" y la que anticipaba `field_condominio` en español.

8. **Aplicar y verificar.** `drush cc all` tras los cambios. Recorrer los criterios de aceptación: instalación limpia, `drush updb` en sitio existente, ciclo de reejecución sin duplicados, y la matriz de filtro por condominio para el rol `administrador edificio`.

**Nota:** no se toca `myapi.module` (no hay rutas nuevas) ni `hook_schema()` (no hay tablas SQL propias).

---

## Criterios de aceptación

**Instalación e idempotencia**

- [x] En un sitio limpio, `drush en myapi` crea los content types `reclamo` y `claim_transaction` (verificable en `admin/structure/types`).
- [x] En el sitio donde `myapi` **ya** estaba instalado, `drush updb` ejecuta `myapi_update_7017` y crea ambos content types y todos sus campos, sin tocar ninguna tabla ni campo existente (`myapi_tokens`, `myapi_notifications`, los bundles `area`/`reservation`, etc.).
- [x] Reejecutar `_myapi_claims_install()` (ciclo `drush pm-uninstall`/`drush en`, o reejecutar el update) **no** duplica campos, instancias ni node types, y no lanza `FieldException`.
- [x] `drush pm-uninstall myapi` con `MYAPI_CLAIMS_DESTRUCTIVE_UNINSTALL = FALSE` **no** borra los content types `reclamo`/`claim_transaction`, sus campos ni sus nodos.

**Content type `reclamo`**

- [x] Tiene exactamente estos campos propios: `field_description`, `field_status`, `field_claim_type`, `field_reception_date`, `field_visibility`, más las instancias reutilizadas `field_condominium`, `field_requester`, `field_images`, `field_attachment`.
- [x] Usa el título nativo como "Asunto" (`title_label = 'Asunto'`), sin campo `field_subject` separado.
- [x] `field_status` es `list_text`, requerido, default `received`, con valores `received|Recibido`, `in_progress|En proceso`, `resolved|Resuelto`, `closed|Cerrado`, `duplicated|Duplicado`.
- [x] `field_claim_type` es `list_text`, requerido, sin default, con valores `requirement|Requerimiento`, `claim|Reclamo`.
- [x] `field_visibility` es `list_text`, requerido, default `private`, con valores `private|Privado`, `public|Público`.
- [x] `field_reception_date` es `datetime` (módulo Date), requerido, granularidad solo fecha (año-mes-día).
- [x] `field_description` es `text_long`, requerido, con `default_value[0]['format'] = 'plain_text'` (no `filtered_html` ni `full_html`).
- [x] `field_condominium` está instanciado en `reclamo`, requerido, y es **el mismo campo** (`field_info_field('field_condominium')` único) que usan `area` y `reservation` — no uno nuevo.
- [x] `field_requester` está instanciado en `reclamo`, requerido, y es el mismo campo que usa `reservation`.
- [x] Publicado por defecto, sin promoción a portada, comentarios ocultos.

**Content type `claim_transaction`**

- [x] Tiene exactamente estos campos propios: `field_status_date`, `field_comment`, `field_claim`, más las instancias reutilizadas `field_status`, `field_images`, `field_attachment`.
- [x] `field_status` está instanciado en `claim_transaction` compartiendo el **mismo campo** (`field_info_field('field_status')` único) que `reclamo`, con **sin** default en esta instancia (a diferencia de la de `reclamo`, que sí trae `received`).
- [x] `field_claim` es `entityreference`, requerido, cardinalidad 1, `target_bundles = ['reclamo']`, y ese `target_bundles` está en el **campo** (vía `_myapi_entityreference_field_settings()`), no en la instancia.
- [x] `field_status_date` es `datetime` (módulo Date), requerido, misma granularidad que `field_reception_date`.
- [x] `field_comment` es `text_long`, **no requerido**, con `default_value[0]['format'] = 'plain_text'`.
- [x] No tiene ningún campo de "usuario de creación": lo resuelve el `uid` nativo del nodo.
- [x] Publicado por defecto, sin promoción a portada, comentarios ocultos.

**Campos compartidos `field_images` / `field_attachment`**

- [x] `field_images` existe como **un solo campo** `image`, cardinalidad ilimitada, con **dos instancias** (una en `reclamo`, otra en `claim_transaction`), ambas con `file_extensions = 'png jpg jpeg'` y `max_filesize = '3 MB'`, ninguna requerida.
- [x] `field_attachment` existe como **un solo campo** `file`, cardinalidad 1, con dos instancias, ambas con `file_extensions = 'pdf doc docx xls xlsx'` y `max_filesize = '3 MB'`, ninguna requerida.
- [x] Subir una imagen de más de 3MB, o con una extensión distinta de `png/jpg/jpeg`, es rechazado por Drupal con su mensaje nativo de validación de campo.
- [x] Subir un archivo de más de 3MB, o con una extensión fuera de `pdf/doc/docx/xls/xlsx`, es rechazado igual.

**Enganche al rol `administrador edificio`**

- [x] `myapi_building_admin_condominium_map()` incluye la entrada `'reclamo' => ['mode' => 'direct', 'field' => 'field_condominium']`.
- [x] Tras `drush updb`, un usuario con el rol y un condominio A asignado ve en `/admin/content` únicamente los nodos `reclamo` de A (además de lo que ya veía de `boletin`/`reservation`/`area`/`vivienda`/`condominio`), y `/node/N` de un `reclamo` del condominio B devuelve 403.
- [x] El mismo usuario puede crear un `reclamo` con `field_condominium = A` desde `node/add/reclamo` (el autocompletado de ese campo solo ofrece A), y no puede editar uno de B por URL directa.
- [x] `create reclamo content` y `edit any reclamo content` aparecen concedidos al rol en `/admin/people/permissions` sin ninguna acción manual adicional — ya los concede `myapi_building_admin_permissions()` desde SPEC 49 en cuanto el bundle existe.
- [x] `claim_transaction` **no** aparece en `myapi_building_admin_editable_types()` ni en `myapi_building_admin_condominium_map()`: el rol **no** recibe `create`/`edit any` sobre ese tipo, y por tanto no hay ningún nodo de ese tipo visible ni editable para él a través de este spec.
- [x] Un usuario `administrator` o `backend` sigue viendo y pudiendo crear/editar ambos content types sin ninguna traba nueva.
- [x] Un residente autenticado en la app recibe exactamente las mismas respuestas que antes en todos los endpoints `api/v1/...` (no se toca ningún `resource`).

**No regresión / infra**

- [x] `myapi.info` no declara ninguna dependencia nueva.
- [x] `myapi_update_7016` y anteriores quedan intactos: el diff de este spec no toca ninguna línea de ninguno de ellos.
- [x] `drush cc all` no reporta errores tras el cambio.
- [x] Existe `docs/claims-install.md` documentando ambos content types, la idempotencia, el flujo `drush updb`, la política de uninstall y la entrada al mapa de condominios.
- [x] `docs/building-admin-role.md` ya no dice que `reclamo` "no existe todavía" ni anticipa `field_condominio` en español; refleja `field_condominium`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Machine name de `reclamo` | Reutilizar el ya reservado en código (`MYAPI_BUILDING_ADMIN_CLAIM_TYPE`) | Definir uno nuevo | SPEC 49/51 ya escribieron ese literal en `includes/myapi.building_admin.inc` y en `docs/`. Usar otro dejaría esa constante apuntando a un bundle que nunca existe. |
| Machine name de la segunda tabla | `claim_transaction` (inglés) | `reclamo_transaccion` (español, coherente con el resto de bundles del sitio) | Elección explícita del usuario. Se acepta la mezcla de idiomas entre los dos bundles (`reclamo` en español ya fijado por specs previos, `claim_transaction` en inglés) como una decisión consciente, no un descuido. |
| Idioma de los campos nuevos | Inglés (`field_subject` vía título nativo, `field_status`, `field_claim_type`, etc.) | Español (`field_asunto`, `field_estado`, `field_tipo`) | CLAUDE.md prohíbe identificadores en español; es además el criterio que ya usan los bundles `area`/`reservation` de SPEC 32. Obliga a corregir la nota de SPEC 49 que anticipaba `field_condominio` para este bundle. |
| Campo condominio y solicitante de `reclamo` | Reutilizar `field_condominium` y `field_requester` (compartidos, creados por SPEC 32) | Crear `field_claim_condominium` / `field_claim_requester` propios | Mismo patrón ya validado que `area`/`reservation`: un campo compartido para el mismo dato y la misma semántica, sin duplicar settings de `entityreference` que SPEC 53 ya dejó correctos. |
| `field_images` / `field_attachment` | Compartidos entre `reclamo` y `claim_transaction` (un campo, dos instancias) | Un par de campos por bundle | Ambos bundles piden exactamente la misma regla (varias imágenes + un archivo, 3MB, mismas extensiones); duplicar el campo sería la misma información dos veces sin ningún beneficio. |
| `field_status` | Un solo campo compartido entre `reclamo` y `claim_transaction`, con `allowed_values` idéntico | Separarlo en `field_claim_status` / `field_transaction_status`, como SPEC 32 hizo con área/reserva | SPEC 32 separó porque los catálogos **diferían**. Aquí los cinco valores son exactamente los mismos en las dos tablas, así que compartir el campo evita mantener dos catálogos idénticos por separado. Los `default_value` sí difieren por instancia (`received` en `reclamo`, ninguno en `claim_transaction`), lo cual Field API permite sin problema. |
| "Fecha de creación" (ambas tablas) y "Usuario (creación)" (`claim_transaction`) | Campos nativos `created` y `uid` del nodo | Campos custom `field_creation_date` / `field_user` | Mismo criterio que SPEC 32 con `created` en `reservation`: si Drupal ya lo resuelve gratis, no se duplica. |
| Fechas semánticamente distintas | Campos nuevos `field_reception_date` (reclamo) y `field_status_date` (transacción) | Reutilizar/forzar la fecha nativa para ambos casos | Representan un momento distinto al de alta del nodo (un reclamo puede recibirse por teléfono y cargarse después; una transacción puede registrar un estado con fecha propia). Requieren campo propio. |
| Validación de tamaño y tipo de archivo | Settings de instancia nativos de Field API (`file_extensions`, `max_filesize`) | Validación de negocio a mano en un hook | Es exactamente el mismo mecanismo que SPEC 32 ya usó en `field_image` del bundle `area`. Encaja en "crear las tablas de contenido": es configuración de campo, no lógica de negocio nueva. |
| Enganche de `reclamo` al rol `administrador edificio` | Sí, como último paso de este spec (entrada en el mapa de condominios) | Dejarlo para un spec futuro | Es exactamente lo que `docs/building-admin-role.md` ya prometía como pendiente ("es el último paso del spec de ese bundle"). Sin este paso, los permisos ya concedidos automáticamente por SPEC 49 quedarían sin el filtro que los hace seguros. |
| Enganche de `claim_transaction` al mismo rol | **No**, queda explícitamente fuera de este spec | Añadirlo también al catálogo, sin filtro por condominio (`NODE_ACCESS_IGNORE` de facto) | Su condominio solo es resoluble atravesando `field_claim` → `reclamo` → `field_condominium`, y ese modo de resolución no existe hoy en `myapi_building_admin_condominium_map()` (`self`/`direct`/`via_unit`). Concederle permisos sin poder acotarlos abriría el registro de cambios de estado de todos los edificios a cualquier administrador de edificio — un riesgo real, no cosmético. |
| Alcance del spec | Solo creación de tipos de contenido, campos y el enganche puntual de `reclamo` al rol | Incluir también API, panel de revisión y notificaciones (como se planteó al inicio) | Decisión explícita del usuario tras revisar el tamaño del pedido original. Cada pieza restante (creación desde la app, vista de tracking, panel de revisión, notificación push) queda para specs propios, siguiendo el precedente de partición de `32`→`48` en reservas. |
| Número de update hook | `myapi_update_7017()` | `myapi_update_7014()` (el que en teoría seguía libre) | `7014` está referenciado en dos comentarios del código pero su función nunca fue escrita — un hueco preexistente. Usarlo sería tapar un bug ajeno a este spec sin resolverlo de verdad (no se sabe qué se suponía que hacía). `7015` y `7016` ya están tomados, así que el siguiente número realmente libre es `7017`. El hueco del 7014 se deja documentado, no corregido. |
| Constante de uninstall destructivo | Nueva `MYAPI_CLAIMS_DESTRUCTIVE_UNINSTALL`, independiente de `MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL` | Reutilizar la misma constante para ambas features | Cada feature controla su propio borrado destructivo por separado; activar una no debe arrastrar a la otra. |
| Sub-helpers de instalación | Ninguno nuevo — reutiliza `_myapi_reservations_ensure_node_type()` / `_ensure_field()` / `_ensure_instance()` tal cual | Escribir `_myapi_claims_ensure_*()` propios | Esas funciones ya son genéricas desde SPEC 49 (que las usó para un campo de usuario, no de nodo). Duplicarlas violaría la regla de "un solo lugar por lógica compartida" de CLAUDE.md. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`claim_transaction` queda hoy sin ningún filtro de acceso propio.** No está en `myapi_building_admin_condominium_map()` ni en `myapi_building_admin_editable_types()`, así que si un spec futuro le concede permisos al rol `administrador edificio` sin resolver antes cómo obtener su condominio (vía `field_claim` → `reclamo`), quedaría visible/editable entre condominios para ese rol. | Documentado explícitamente en Alcance, en Decisiones y en `docs/building-admin-role.md`: cualquier spec que dé permisos sobre `claim_transaction` debe extender antes el modo de resolución del mapa (`self`/`direct`/`via_unit` no alcanzan). Hoy nadie del rol tiene permiso sobre ese tipo, así que el riesgo es solo para el futuro, no para este spec. |
| **El hueco de `myapi_update_7014`** (referenciado en comentarios pero nunca implementado) puede llevar a alguien a asumir que existe al leer el histórico, o a reutilizar el número por error en un spec futuro. | Documentado en el plan de implementación como preexistente y fuera de alcance. Este spec usa `7017`, dejando `7014` como está. |
| **`field_status` compartido entre `reclamo` y `claim_transaction`.** Si en el futuro uno de los dos bundles necesita un valor de estado que el otro no debe ofrecer, habría que separar el campo retroactivamente (dos campos nuevos, migración de valores, actualizar todo lo que lo consuma). | Aceptado conscientemente: hoy el catálogo es idéntico en ambos, y separar sin necesidad duplicaría mantenimiento. Si diverge, se separa entonces — el costo de esperar es menor que el de anticiparlo sin un caso real. |
| **Campos requeridos sin `default_value`** (`field_claim_type`, `field_reception_date`, `field_status_date`) — el widget nativo de Drupal puede preseleccionar la primera opción de un `list_text` o la fecha actual en un `date_select`, dando la impresión de una elección explícita cuando en realidad nadie la hizo. | Es comportamiento nativo de Drupal, el mismo que ya asumen los campos `list_text` requeridos de SPEC 32 (`field_area_status`, `field_who_can_reserve`). No se introduce lógica de validación adicional en este spec porque no hay flujo ni formulario propio que la necesite todavía. |
| **Crecimiento de almacenamiento** por los adjuntos de imágenes y archivos (hasta 3MB cada uno, cardinalidad ilimitada en imágenes) en `sites/default/files` a medida que se acumulan reclamos y transacciones. | Aceptado, mismo criterio que `field_image` de `area` en SPEC 32: la gestión de almacenamiento es operativa, no de este spec. |
| **Ningún control de negocio sobre quién puede crear una `claim_transaction`** ni sobre qué transiciones de estado son válidas — hoy solo `administrator`/`backend` pueden tocarlas (por `bypass node access` o por no estar filtrados), y sin ninguna regla de máquina de estados. | Explícitamente fuera de alcance (ver sección Alcance): este spec solo crea la estructura. El riesgo de un estado inconsistente (p. ej. "Cerrado" seguido de "Recibido") queda para el spec que construya el flujo real de cambio de estado. |
