# 77 — Tipos de contenido del marketplace de servicios (`provider`, `service_request`, `service_offer`, `service_rating`, `service_transaction`)

- **Estado:** Implemented — código y unit tests en verde (1111 tests, 4593 assertions); la verificación manual en el sitio queda pendiente
- **Fecha:** 2026-08-07
- **Dependencias:**
  - `32-reservations-content-types-install` (Implemented) — patrón idempotente `_myapi_reservations_ensure_node_type()` / `_ensure_field()` / `_ensure_instance()` en `myapi.install`, y los campos compartidos `field_condominium` (→ bundle `condominio`) y `field_requester` (→ entidad `user`), que este spec reutiliza sin modificarlos.
  - `53-entityreference-selection-settings` (Implemented) — `_myapi_entityreference_field_settings()`, catálogo donde este spec registra sus siete campos `entityreference` nuevos para que nazcan con el `target_bundles` correcto.
  - `55-claims-content-types-install` (Implemented) — precedente directo de este spec (dos bundles + línea de tiempo compartiendo el campo de estado), y origen de los campos `field_description`, `field_images`, `field_attachment`, `field_status_date` y `field_comment` que aquí se reutilizan.
  - `65-claim-files-private-and-download` (Implemented) — deja `field_images` y `field_attachment` con `uri_scheme = 'private'` **a nivel de campo**, que es lo que hace que las fotos de una solicitud nazcan privadas sin que este spec tenga que decirlo.
  - `49-building-admin-role` (Implemented) — `myapi_building_admin_condominium_map()`, al que este spec **no** añade ninguna entrada todavía (ver Fuera de alcance).

**Objetivo:** Crear de forma idempotente, al instalar y al actualizar el módulo, el vocabulario **`service_category`** y los cinco content types del marketplace de servicios — **`provider`**, **`service_request`**, **`service_offer`**, **`service_rating`** y **`service_transaction`** — con todos sus campos, instancias y relaciones, **sin ningún endpoint de API, permiso de rol, interfaz de administración ni lógica de negocio**.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.services_common.inc`** (nuevo) — catálogos y reglas puras: los nombres de máquina de los cinco bundles y del vocabulario, el catálogo de estados de solicitud y de oferta, la escala de estrellas, el grafo de transiciones, `myapi_services_close_requires_rating()` y `myapi_services_provider_is_active()`. Todo puro (sin base de datos, sin Drupal), en el mismo reparto que `includes/myapi.building_admin.inc`.
- **`myapi.install`** (modificar):
  - Nueva constante **`MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL`** (`FALSE`), independiente de las de reservas y reclamos.
  - Nuevo sub-helper **`_myapi_services_ensure_vocabulary()`** — el equivalente de `_myapi_reservations_ensure_node_type()` para vocabularios, que no existía. Es el **único** sub-helper nuevo; todo lo demás reutiliza los de SPEC 32/49.
  - Nuevo **`_myapi_services_install()`** idempotente: vocabulario, cinco content types, campos e instancias.
  - Nuevo **`_myapi_services_uninstall_destructive()`**, opt-in, que **borra solo los campos propios** y se limita a quitar la **instancia** de los siete campos prestados.
  - Siete entradas nuevas en **`_myapi_entityreference_field_settings()`**.
  - `myapi_install()` llama a `_myapi_services_install()`; nuevo **`myapi_update_7025()`** que llama a lo mismo; `myapi_uninstall()` gana su bloque condicional.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.services_common.inc`. Ninguna dependencia nueva: `entityreference`, `date`, `taxonomy`, `image`, `file`, `list`, `text` y `number` ya están cubiertas por core o por SPEC 32.
- **Pruebas unitarias** — `tests/unit/ServicesInstallTest.php` (catálogos, grafo de transiciones, regla de calificación, proveedor activo, y guards que leen `myapi.install` como texto) y ampliación de `tests/unit/EntityReferenceFieldSettingsTest.php` con los siete campos nuevos.
- **`docs/services-install.md`** (nuevo).
- `drush cc all` al final.

**Fuera de alcance (para specs futuros):**

- **Cualquier endpoint `api/v1/...`**: listar categorías, listar proveedores, crear una solicitud, ofertar, abrir chat, cerrar, calificar. No se crea ningún `resources/*.resource.inc`.
- **Cualquier permiso o rol.** No se toca `myapi_building_admin_permissions()` ni se crea el rol `proveedor` — ese rol es explícitamente el siguiente spec. Hoy solo `administrator` (y `backend`, por sus permisos globales) ve estos tipos en el back office.
- **La entrada de `service_request` en `myapi_building_admin_condominium_map()`.** El campo `field_condominium` ya se crea aquí precisamente para que ese spec sea una línea, pero añadir la entrada sin conceder antes los permisos no tendría efecto, y concederlos es trabajo del spec del rol. Mismo criterio con el que SPEC 55 dejó `claim_transaction` fuera del mapa.
- **Toda la lógica de negocio:** validar transiciones (el grafo existe pero nadie lo lee), exigir la calificación al cerrar, rellenar los campos denormalizados (`field_assigned_provider`, `field_rating_provider`, `field_rating_avg`, `field_rating_count`), el `GET_LOCK` de unicidad de oferta y de calificación, y la difusión a los proveedores de la categoría.
- **Título autogenerado** de solicitudes, ofertas, calificaciones y transacciones. Precedente: en reclamos fue su propio spec (SPEC 60).
- **Ocultar en el formulario los campos denormalizados** (`hook_form_alter()` con `#access = FALSE`). Es código de módulo, no configuración de campo; y hoy ningún rol operativo llega a esos formularios.
- **Vistas de administración** de proveedores, solicitudes, ofertas y calificaciones. Cuando lleguen serán páginas propias con `hook_menu()`, como `includes/myapi.claims_admin.inc`, no Views — este repo no usa Views para nada.
- **El chat.** Los tres campos que lo soportarán (`field_firebase_path`, `field_chat_opened_at`, `field_last_message_at`) se crean vacíos; el transporte (Firebase Realtime Database u otro), el puente de autenticación y las reglas de seguridad no están decididos ni cotizados.
- **Notificaciones push/inbox** de solicitud nueva, oferta nueva, adjudicación, calificación y mensaje de chat.
- **Alta o autoregistro de proveedores desde la app.** El operador crea el nodo `provider` y su usuario a mano.
- **Términos del vocabulario.** Se crea el vocabulario vacío; las cinco categorías las carga el operador.
- **Claves del catálogo `myapi_t()` / i18n** — no hay respuesta de API que traducir todavía.

---

## Modelo de datos

No se crean tablas SQL propias (no hay `hook_schema()` nuevo). Se crean **entidades de configuración**: 1 vocabulario, 5 bundles de nodo, 29 campos nuevos y sus instancias. Drupal genera las tablas `field_data_*` / `field_revision_*`.

### Vocabulario «Categoría de servicio» (`service_category`)

| Ajuste | Valor |
|---|---|
| `machine_name` / `name` | `service_category` / Categoría de servicio |
| Jerarquía | Plana (`hierarchy = 0`) — hay categorías, no sub-categorías |

| Campo | Tipo | Card. | Req. | Widget | Notas |
|---|---|:---:|:---:|---|---|
| `field_category_code` | `text` (32) | 1 | Sí | `text_textfield` | Identificador estable que viaja en la API. El `tid` cambia si se reimporta el vocabulario; el código no. |
| `field_category_icon` | `image` | 1 | No | `image_image` | `png jpg jpeg`, 1 MB. **`uri_scheme = 'public'`** — ver Decisiones. |

### Content type «Proveedor» (`provider`)

Título nativo = «Nombre comercial». Publicado por defecto, sin promoción, comentarios ocultos.

| Campo | Tipo | Card. | Req. | Notas |
|---|---|:---:|:---:|---|
| `field_provider_users` | `entityreference` → user | ∞ | Sí | Cuentas que operan el proveedor. Sin `target_bundles` (la entidad user tiene un solo bundle). |
| `field_phone` | `text` (20) | 1 | Sí | |
| `field_address` | `text_long` | 1 | No | `format = plain_text` fijado. |
| `field_services_desc` | `text_long` | 1 | Sí | `format = plain_text` fijado. |
| `field_photo` | `image` | 1 | No | `png jpg jpeg`, 3 MB, **público**. |
| `field_license_expiry` | `datestamp` | 1 | Sí | Granularidad hasta el minuto — ver Decisiones. |
| `field_categories` | `taxonomy_term_reference` → `service_category` | ∞ | Sí | Widget `options_buttons` (checkboxes). |
| `field_rating_avg` | `number_decimal` (3,2) | 1 | No | Denormalizado. Nadie lo escribe en este spec. |
| `field_rating_count` | `number_integer` | 1 | No | Denormalizado. |

**Proveedor activo** = `node.status = 1 AND field_license_expiry >= REQUEST_TIME`. La regla vive en una sola función pura, `myapi_services_provider_is_active()`, porque la preguntarán tres sitios distintos: la difusión de una solicitud, la creación de una oferta y el listado por categoría.

### Content type «Solicitud de servicio» (`service_request`)

Título nativo «Título», sin autogeneración todavía.

| Campo | Tipo | Card. | Req. | Notas |
|---|---|:---:|:---:|---|
| `field_requester` | `entityreference` → user | 1 | Sí | **Compartido** (SPEC 32). Destinatario de las notificaciones de la solicitud. |
| `field_condominium` | `entityreference` → `condominio` | 1 | Sí | **Compartido** (SPEC 32). Alcance del rol `administrador edificio` cuando llegue su spec. |
| `field_category` | `taxonomy_term_reference` → `service_category` | 1 | Sí | Widget `options_select`. |
| `field_description` | `text_long` | 1 | Sí | **Compartido** (SPEC 55). |
| `field_desired_start` | `datestamp` | 1 | Sí | |
| `field_images` | `image` | ∞ | No | **Compartido** (SPEC 55/65) → **`private://`**. |
| `field_attachment` | `file` | 1 | No | **Compartido** (SPEC 55/65) → **`private://`**. |
| `field_request_status` | `list_text` | 1 | Sí | Default `open`. Catálogo abajo. |
| `field_assigned_offer` | `entityreference` → `service_offer` | 1 | No | |
| `field_assigned_provider` | `entityreference` → `provider` | 1 | No | Denormalizado desde la oferta adjudicada. |
| `field_closed_at` | `datestamp` | 1 | No | |

**Autor y fecha de alta**: campos nativos `uid` y `created` del nodo. `field_requester` no los duplica: el `uid` es quien guardó el nodo (que puede ser el backend), y `field_requester` es el residente al que pertenece la solicitud — la misma distinción que ya hace `reservation` desde SPEC 32.

### Catálogo de `field_request_status`

```
open|Abierta
offered|Con ofertas
assigned|Asignada
closed|Cerrada
cancelled|Cancelada
```

Grafo de transiciones (`myapi_services_request_transitions()`, sin aplicar todavía):

```
open ──(1ª oferta)──> offered ──(adjudicación)──> assigned
  │                      │                           │
  │                      └────────> closed <─────────┘
  └──────────> cancelled <──────────┴────────────────┘
```

- `closed` y `cancelled` son **terminales**: nada reabre una solicitud.
- Cerrar desde `assigned` **exige calificación**; cerrar desde `offered` es el cierre sin adjudicación del contrato (7.2.4) y no hay a quién calificar. La regla es `myapi_services_close_requires_rating()`.

### Content type «Oferta» (`service_offer`)

| Campo | Tipo | Card. | Req. | Notas |
|---|---|:---:|:---:|---|
| `field_request` | `entityreference` → `service_request` | 1 | Sí | **Compartido con `service_transaction`.** |
| `field_provider` | `entityreference` → `provider` | 1 | Sí | |
| `field_offer_message` | `text_long` | 1 | Sí | `format = plain_text`. |
| `field_offer_amount` | `number_decimal` (10,2) | 1 | No | En dólares. Una oferta sin monto es válida. |
| `field_offer_status` | `list_text` | 1 | Sí | Default `sent`: `sent\|Enviada`, `selected\|Seleccionada`, `rejected\|Rechazada`, `withdrawn\|Retirada`. |
| `field_firebase_path` | `text` (255) | 1 | No | Reservado para el chat. Vacío = chat no abierto. |
| `field_chat_opened_at` | `datestamp` | 1 | No | Reservado para el chat. |
| `field_last_message_at` | `datestamp` | 1 | No | Reservado para el chat. |

El **proveedor que oferta** es el `uid` nativo del nodo; `field_provider` es el nodo `provider` al que pertenece. Son dos cosas distintas porque un proveedor puede tener varias cuentas (`field_provider_users`).

### Content type «Calificación de servicio» (`service_rating`)

| Campo | Tipo | Card. | Req. | Notas |
|---|---|:---:|:---:|---|
| `field_rating_offer` | `entityreference` → `service_offer` | 1 | Sí | La calificación cuelga de la **oferta**, no de la solicitud. |
| `field_rating_provider` | `entityreference` → `provider` | 1 | Sí | Denormalizado desde la oferta. |
| `field_stars` | `list_integer` | 1 | Sí | `1..5`, sin cero y sin medias estrellas. |
| `field_rating_comment` | `text_long` | 1 | No | `format = plain_text`. |

Apuntar a la oferta y no a la solicitud hace **irrepresentable** —no solo prohibida— una calificación a un proveedor que nunca ofertó ahí: la oferta es lo único que une solicitud y proveedor.

### Content type «Transacción de solicitud» (`service_transaction`)

Réplica de `claim_transaction` (SPEC 55): una entrada por cambio de estado.

| Campo | Tipo | Card. | Req. | Notas |
|---|---|:---:|:---:|---|
| `field_request` | `entityreference` → `service_request` | 1 | Sí | Mismo campo que en `service_offer`. |
| `field_request_status` | `list_text` | 1 | Sí | Mismo campo que en `service_request`, **sin default** en esta instancia: una transacción siempre registra un estado elegido a propósito. |
| `field_status_date` | `datetime` | 1 | Sí | **Compartido con `claim_transaction`** (SPEC 55). La única fecha de esta feature que no es `datestamp` — ver Decisiones. |
| `field_comment` | `text_long` | 1 | No | **Compartido con `claim_transaction`.** |

### Grafo de relaciones

```
service_category (taxonomy)
        ▲                     ▲
        │ field_categories    │ field_category
        │                     │
    provider ◄──────────  service_request ◄── field_request ── service_transaction
        ▲                    │      ▲
        │ field_provider     │      │ field_request
        │                    │      │
        └────────────  service_offer ┘
                          ▲   ▲
      field_assigned_offer│   │ field_rating_offer
                          │   │
       service_request ───┘   service_rating
```

Referencias denormalizadas, que un spec futuro rellenará en `presave`:

- `service_request.field_assigned_provider` ← desde `field_assigned_offer`
- `service_rating.field_rating_provider` ← desde `field_rating_offer`
- `provider.field_rating_avg` / `field_rating_count` ← recalculados al insertar/borrar una calificación

---

## Plan de implementación

1. **`includes/myapi.services_common.inc`** — constantes de bundles y vocabulario, los tres catálogos, el grafo de transiciones y las tres reglas puras. Va primero porque `myapi.install` lo lee. *Verificación: `php -l`.*

2. **`myapi.install` — entradas en `_myapi_entityreference_field_settings()`.** Los siete campos `entityreference` nuevos, con sus tres `target_bundles` escritos una sola vez en variables locales. Antes de crear ningún campo, para que nazcan bien configurados y no dependan de ningún update de reparación. *Verificación: `EntityReferenceFieldSettingsTest` en verde con la lista ampliada a trece campos.*

3. **`myapi.install` — `_myapi_services_ensure_vocabulary()`.** Único sub-helper nuevo. *Verificación: reejecutable sin duplicar ni sobrescribir un vocabulario ya ajustado en el sitio.*

4. **`myapi.install` — `_myapi_services_install()`.** En el orden (a) vocabulario y sus campos, (b) los cinco bundles, (c) los campos, (d) las instancias. *Verificación: reejecutable sin duplicados ni `FieldException`.*

5. **`myapi.install` — enganche.** `myapi_install()` llama al helper después de `_myapi_claims_install()` (que es quien crea los campos prestados y los deja privados) y antes de `_myapi_building_admin_install()`. Nuevo `myapi_update_7025()` con la misma llamada. *Verificación: sitio limpio con `drush en myapi`; sitio existente con `drush updb`.*

6. **`myapi.install` — uninstall conservador.** `MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL = FALSE` y `_myapi_services_uninstall_destructive()`, que separa campos propios (se borran) de prestados (solo pierden la instancia). *Verificación: `drush pm-uninstall myapi` no borra nada con la constante en `FALSE`.*

7. **`myapi.info`** — registrar el include nuevo.

8. **Pruebas.** `tests/unit/ServicesInstallTest.php` y la ampliación de `EntityReferenceFieldSettingsTest`. *Verificación: suite completa en verde.*

9. **Documentación.** `docs/services-install.md`.

10. **Aplicar y verificar.** `drush cc all` y recorrer los criterios de aceptación.

**Nota:** no se toca `myapi.module` (no hay rutas nuevas) ni `hook_schema()` (no hay tablas SQL propias) ni `includes/myapi.building_admin.inc`.

---

## Criterios de aceptación

**Instalación e idempotencia**

- [x] En un sitio limpio, `drush en myapi` crea el vocabulario `service_category` y los cinco content types (verificable en `admin/structure/taxonomy` y `admin/structure/types`).
- [x] En el sitio donde `myapi` **ya** estaba instalado, `drush updb` ejecuta `myapi_update_7025` y crea todo sin tocar nada existente.
- [x] Reejecutar `_myapi_services_install()` no duplica vocabulario, campos, instancias ni bundles, y no lanza `FieldException`.
- [x] `drush pm-uninstall myapi` con `MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL = FALSE` no borra nada de esta feature.
- [x] La suite unitaria pasa completa (1111 tests, 4593 assertions).

**Campos compartidos — el riesgo real de este spec**

- [x] `field_description`, `field_images`, `field_attachment`, `field_requester`, `field_condominium`, `field_status_date` y `field_comment` siguen siendo **un solo campo cada uno** (`field_info_field()` único), con sus instancias previas intactas en `reclamo`, `claim_transaction`, `area` y `reservation`.
- [x] `field_images` y `field_attachment` conservan `uri_scheme = 'private'`: una imagen subida a una `service_request` aterriza en el directorio privado, no en `sites/default/files`.
- [x] Un test unitario falla si alguien añade uno de esos siete campos a la lista `$owned` del uninstall destructivo, o si el instalador intenta recrearlos.

**Vocabulario y proveedores**

- [x] `service_category` es plano, y sus términos aceptan código e ícono.
- [x] `field_category_code` es requerido; el `field_category_icon` es público y sirve por URL directa.
- [x] Un nodo `provider` exige nombre comercial, al menos un usuario asociado, teléfono, descripción de servicios, caducidad y al menos una categoría.
- [x] `field_categories` se muestra como checkboxes y solo ofrece términos de `service_category`.
- [x] `myapi_services_provider_is_active()` devuelve TRUE en el instante exacto de caducidad y FALSE un segundo después; FALSE si el nodo está despublicado; FALSE si la caducidad está vacía.

**Solicitudes, ofertas, calificaciones y línea de tiempo**

- [x] `field_request_status` nace en `open`; la instancia de `service_transaction` del **mismo** campo no trae default.
- [x] `field_stars` solo ofrece 1–5.
- [x] Los autocompletados de `field_request`, `field_provider`, `field_assigned_offer`, `field_assigned_provider`, `field_rating_offer` y `field_rating_provider` ofrecen **solo** nodos de su bundle destino, y ninguno ofrece viviendas, boletines ni recibos.
- [x] El grafo de transiciones es cerrado sobre el catálogo de estados, `closed`/`cancelled` no llevan a ninguna parte, y un estado desconocido devuelve FALSE en lugar de lanzar.
- [x] Cerrar exige calificación **solo** desde `assigned`.

**No regresión**

- [x] Ningún endpoint `api/v1/...` cambia de respuesta: no se ha tocado ningún fichero de `resources/`.
- [x] Ningún rol gana ni pierde permisos: `/admin/people/permissions` es idéntico antes y después salvo por las filas nuevas (vacías) de los cinco tipos.
- [x] `myapi.info` no declara ninguna dependencia nueva.
- [x] `myapi_update_7024` y anteriores quedan intactos.
- [x] `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Categorías | Vocabulario `service_category` con código e ícono | `list_text` con claves estables, como `field_area_category` (SPEC 32) | Elección explícita del usuario. El operador tiene que poder añadir categorías y subirles ícono sin un deploy, que es justo lo que un `list_text` no permite. El precio es que el `tid` no es estable, y por eso existe `field_category_code`. |
| Tipo de los campos de fecha | `datestamp` (timestamp Unix) | `datetime`, que es lo que usan reservas y reclamos | Elección explícita del usuario: la app manda y recibe instantes y hace ella la conversión a hora local. Se acepta la divergencia con el resto del módulo como decisión consciente. |
| `field_status_date` de la línea de tiempo | Reutilizar el campo `datetime` de `claim_transaction` | Crear un `field_transaction_date` propio en `datestamp`, coherente con el resto de esta feature | Es exactamente el mismo dato («la fecha de este cambio de estado») y la línea de tiempo es una réplica de la de reclamos. Duplicar el campo para ganar coherencia de tipo dentro del módulo costaría un campo casi idéntico y una segunda forma de leer lo mismo. Es la única excepción a la decisión anterior y está anotada en el código. |
| Granularidad de `field_license_expiry` | Hasta el minuto | Solo día, que es como piensa un operador | Con granularidad de día el timestamp guardado son las 00:00 de ese día, así que una habilitación «que vence el 31» moriría al acabar el 30. Un error de un día que nadie sospecharía hasta que un proveedor quedara bloqueado. |
| Alcance por condominio | `service_request` lleva `field_condominium`; `provider` no | Ambos por condominio, o ninguno | El marketplace de proveedores es el mismo para todo el sitio; lo que hay que acotar por edificio es qué solicitudes ve el `administrador edificio`. Sin `field_condominium` en la solicitud, ese tipo quedaría fuera de la regla de acceso de SPEC 49 y el rol las vería todas o ninguna. |
| Cinco bundles en vez de tres | Añadir `service_rating` y `service_transaction` | Modelar la calificación como campos de la solicitud y prescindir de la línea de tiempo | La calificación como entidad propia permite borrarla (moderación) sin tocar la solicitud y apuntar a la oferta, que es lo que hace irrepresentable calificar a quien no ofertó. La línea de tiempo es petición explícita del usuario, con el precedente de reclamos. |
| Campo del estado | `field_request_status` compartido por `service_request` y `service_transaction`; `field_offer_status` aparte | Un único campo de estado para todo | Solicitud y transacción comparten el catálogo exacto, igual que `reclamo`/`claim_transaction` con `field_status`; las ofertas no comparten ni un valor, así que un campo común ofrecería «Abierta» en el formulario de oferta. Es el mismo criterio con el que SPEC 32 separó `field_area_status` de `field_reservation_status`. |
| `field_provider` / `field_assigned_provider` / `field_rating_provider` | Tres campos distintos aunque los tres apunten a `provider` | Un solo `field_provider` con tres instancias | Son tres hechos distintos: quién ofertó, quién fue adjudicado y a quién se calificó. Compartido, los tres acabarían en una misma tabla `field_data_field_provider` donde ninguna consulta podría distinguirlos, y el ahorro sería un campo. |
| `field_request` | **Sí** compartido entre `service_offer` y `service_transaction` | Dos campos | Aquí sí es el mismo hecho —«la solicitud a la que esto pertenece»—, con el mismo bundle destino y la misma cardinalidad. |
| Ícono de categoría y foto de proveedor | `uri_scheme = 'public'` | Privados, como los ficheros de reclamos (SPEC 65) | Son activos de escaparate: idénticos para todos los usuarios y sin nada que revelar. Privarlos obligaría a un endpoint de descarga autenticado por cada miniatura del grid. Las fotos de una **solicitud** sí son privadas, porque pueden mostrar el interior de una vivienda. |
| Campos del chat | Crearlos ahora, vacíos | Dejarlos para el spec del chat | La forma del dato ya está decidida (un hilo por oferta) aunque el transporte no lo esté. Crear tres columnas cuesta cero hoy; añadirlas después cuesta un `hook_update_N` y un deploy. |
| Ocultar los campos denormalizados en el formulario | Fuera de este spec | `hook_form_alter()` con `#access = FALSE` aquí mismo | Es código de módulo, no configuración de campo, y el alcance acordado es «solo las tablas». Hoy además ningún rol operativo alcanza esos formularios: solo `administrator`. |
| Sub-helpers de instalación | Solo uno nuevo (`_myapi_services_ensure_vocabulary()`); el resto reutiliza SPEC 32/49 | `_myapi_services_ensure_node_type()` / `_ensure_field()` / `_ensure_instance()` propios | Esas funciones ya son genéricas desde SPEC 49. Duplicarlas violaría la regla de «una sola lógica compartida» de CLAUDE.md. El del vocabulario no tenía equivalente. |
| Uninstall destructivo | Lista de campos propios + borrado de **instancias** para los prestados | Copiar el patrón de reclamos, que borra campos sin distinguir | Reclamos era dueño de todos sus campos; esta feature presta siete. `field_delete_field('field_description')` se llevaría por delante la descripción de todos los reclamos desde un teardown cuyo autor solo quería quitar el marketplace. Un test unitario lo impide. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Borrado accidental de un campo prestado** en el uninstall destructivo. Es el riesgo más caro del spec: `field_delete_field('field_condominium')` vacía áreas y reservas. | La constante está en `FALSE` y el teardown separa campos propios de prestados. `ServicesInstallTest::testTheDestructiveUninstallNeverDeletesABorrowedField()` falla si un nombre prestado aparece en la lista `$owned`. |
| **`field_description`, `field_images` y `field_attachment` compartidos con reclamos.** Si algún día una solicitud necesita otras extensiones, otro tamaño o texto con formato, cambiar el **campo** afectaría también a los reclamos. | Aceptado: los *settings de instancia* (extensiones, tamaño, requerido) sí son por bundle y pueden divergir sin tocar nada más. Solo `uri_scheme`, tipo y cardinalidad son de campo, y en los tres el requisito es idéntico hoy. Si diverge, se separa entonces. |
| **Ningún control de negocio.** Nada valida transiciones, unicidad de oferta, ni que solo un proveedor activo oferte. Un `administrator` puede dejar hoy una solicitud en un estado imposible desde el back office. | Explícitamente fuera de alcance. Hoy solo `administrator`/`backend` alcanzan estos formularios, y no hay app conectada a ellos. El grafo ya está escrito y probado para que el spec del flujo lo lea en vez de reinventarlo. |
| **`field_firebase_path` editable a mano** por quien alcance el formulario: cambiarlo rompería el chat en silencio. | Hoy nadie operativo llega a ese formulario, y la descripción del campo lo advierte. Ocultarlo es del spec del chat, que es quien además le dará uso. |
| **`service_transaction` fuera del mapa de condominios**, igual que `claim_transaction` lo estuvo tras SPEC 55: si un spec futuro le concede permisos al rol `administrador edificio` sin resolver antes su condominio (vía `field_request` → `service_request` → `field_condominium`), quedaría visible entre edificios. | Documentado aquí y en `docs/services-install.md`. El modo `via_claim` de `myapi_building_admin_condominium_map()` ya demuestra cómo se resuelve un salto de dos; el spec del rol tendrá que añadir el suyo. |
| **Divergencia `datestamp` / `datetime`** dentro del mismo módulo: la capa de API tendrá que formatear los dos tipos. | Aceptada por decisión del usuario y acotada a un solo campo (`field_status_date`). Anotada en el código, en el spec y en la doc, para que quien escriba el endpoint no la descubra depurando. |
| **Crecimiento de almacenamiento** por imágenes y adjuntos de solicitudes. | Aceptado, mismo criterio que SPEC 32/55: la gestión de almacenamiento es operativa. |
