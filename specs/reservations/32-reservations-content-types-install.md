# SPEC 32 — Content types de reservas (Área y Reserva) creados en la instalación

> **Estado:** Approved · **Depende de:** — (ninguno; los content types `condominio` y `vivienda` ya existen en el sitio) · **Fecha:** 2026-07-22
> **Objetivo:** Crear de forma idempotente, al instalar y al actualizar el módulo `myapi`, los dos content types de reservas —«Área» (`area`) y «Reserva» (`reservation`)— con todos sus campos Field API vía `node_type_save()`/`field_create_field()`/`field_create_instance()`, sin crearlos a mano en el admin y sin destruir datos al desinstalar.

**Notas de la cabecera:**

- **No depende de ningún spec previo**: es trabajo de instalación (`myapi.install` + `myapi.info`), no un endpoint REST. Los bundles destino `condominio` y `vivienda` ya existen en el sitio (requisito 6 del enunciado).

---

## Alcance

### Dentro de este spec

- **`myapi.install`** (modificar):
  1. Un helper privado idempotente **`_myapi_reservations_install()`** que crea los dos content types y todos sus campos/instancias. Es la única fuente de verdad de la creación.
  2. Sub-helpers privados reutilizables: **`_myapi_reservations_ensure_node_type()`**, **`_myapi_reservations_ensure_field()`** (a nivel de campo, una sola vez) y **`_myapi_reservations_ensure_instance()`** (por bundle).
  3. `hook_install()` (`myapi_install()`) llama a `_myapi_reservations_install()` **además** del `myapi_mail_system_register()` actual → instalaciones nuevas quedan completas.
  4. Nuevo **`myapi_update_7006()`** que llama al mismo `_myapi_reservations_install()` → los sitios donde el módulo YA está instalado obtienen los content types vía `drush updb`.
  5. `hook_uninstall()` (`myapi_uninstall()`) **conservador**: no borra content types, campos ni datos. Borrado destructivo solo si la constante **`MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL`** (definida `FALSE` por defecto) se activa manualmente; documentado con comentario.
- **`myapi.info`** (modificar): añadir `dependencies[] = entityreference` y `dependencies[] = date`. Nada más (image, list, text, number son de core).
- **`docs/reservations-install.md`** (crear): documenta los dos content types, sus campos, la idempotencia, el flujo `drush updb` para sitios existentes y la política conservadora de uninstall.

### Fuera de este spec (para specs futuros)

- **Cualquier endpoint REST de reservas** (listar/crear/cancelar áreas o reservas). Aquí solo se crean los content types; la API que los consume es otro spec.
- **Lógica de negocio de reservas**: validación de solapamientos, slots, ventana de cancelación, formato HH:MM, `field_max_minutes` múltiplo de `field_slot_minutes`. Son reglas de la API futura, no del esquema.
- **Título autogenerado de «Reserva»** (módulo Automatic Nodetitles con patrón `Reserva [node:field_area] [node:field_date]`). Se menciona como opción operativa; este spec **no** instala ni configura ese módulo. El título queda con el comportamiento nativo.
- **Displays/formatters de salida** (cómo se ven los campos en la ficha del nodo). Solo se crean storage + instancia con widget; no se configuran view modes.
- **Migrar o crear nodos de datos** de áreas o reservas. Solo estructura, cero contenido.
- **Crear o modificar los content types `condominio` y `vivienda`**. Se asumen existentes; solo se apunta a ellos.
- **Borrado de datos en uninstall por defecto.** El camino destructivo existe solo tras activar la constante manualmente.

---

## Modelo de datos

No se crean tablas SQL propias (a diferencia de `myapi_tokens`). Se crean **entidades de configuración Field API**: 2 bundles de nodo, 1 campo compartido y N instancias. Drupal genera automáticamente las tablas `field_data_*` / `field_revision_*`.

### Content type «Área» (`area`)

| Ajuste | Valor |
|---|---|
| `type` / `name` | `area` / `Área` |
| `base` | `node_content` |
| `description` | Áreas comunes reservables de un condominio. |
| `has_title` / `title_label` | `1` / `Nombre del área` (título nativo = nombre) |
| Publicación | `node_options_area = ['status']` → publicado; sin promote, sin sticky |
| Comentarios | `comment_area = COMMENT_NODE_HIDDEN` |
| `custom` / `modified` / `locked` | `1` / `1` / `0` |

### Content type «Reserva» (`reservation`)

| Ajuste | Valor |
|---|---|
| `type` / `name` | `reservation` / `Reserva` |
| `base` | `node_content` |
| `description` | Reserva de un área común hecha por un usuario. |
| `has_title` / `title_label` | `1` / `Título` (nativo; irrelevante para la API, autogenerable fuera de este spec) |
| Fecha de creación | campo nativo `created` del nodo (no se crea campo custom) |
| Publicación | `node_options_reservation = ['status']` → publicado; sin promote, sin sticky |
| Comentarios | `comment_reservation = COMMENT_NODE_HIDDEN` |

### Campo compartido (a nivel de campo, se crea UNA vez)

| Campo | `type` | Settings de campo | Cardinalidad |
|---|---|---|---|
| `field_condominium` | `entityreference` | `target_type=node`, `handler=base`, `handler_settings.target_bundles=['condominio']` | 1 |

`field_condominium` se crea una sola vez con `field_create_field()` y se añade como **instancia** a `area` y a `reservation` (ambos apuntan al mismo bundle `condominio`).

### Instancias del bundle `area`

| Instancia | Tipo de campo | Requerido | Default | Widget | Settings de instancia |
|---|---|---|---|---|---|
| `field_condominium` | `entityreference` | Sí | — | `entityreference_autocomplete` | (hereda target `condominio`) |
| `field_image` | `image` | No | — | `image_image` | `file_extensions='png jpg jpeg'`, alt no obligatorio |
| `field_open_time` | `text` (plain) | Sí | — | `text_textfield` | `max_length=5` (HH:MM) |
| `field_close_time` | `text` (plain) | Sí | — | `text_textfield` | `max_length=5` (HH:MM) |
| `field_slot_minutes` | `number_integer` | Sí | `60` | `number` | ayuda: tamaño del bloque en minutos |
| `field_max_minutes` | `number_integer` | Sí | `120` | `number` | ayuda: duración máx en minutos |
| `field_area_status` | `list_text` | Sí | `active` | `options_select` | `allowed_values`: `active\|Activo`, `closed\|Cerrado`, `maintenance\|En Mantenimiento` |
| `field_who_can_reserve` | `list_text` | Sí | `both` | `options_select` | `allowed_values`: `both\|Ambos`, `owner\|Propietario`, `tenant\|Arrendatario` |
| `field_cancel_deadline_minutes` | `number_integer` | Sí | `120` | `number` | ayuda: minutos mínimos antes del inicio para cancelar |

### Instancias del bundle `reservation`

| Instancia | Tipo de campo | Requerido | Default | Widget | Settings |
|---|---|---|---|---|---|
| `field_condominium` | `entityreference` | Sí | — | `entityreference_autocomplete` | target `condominio` (mismo campo compartido) |
| `field_unit` | `entityreference` | Sí | — | `entityreference_autocomplete` | `target_bundles=['vivienda']` |
| `field_requester` | `entityreference` | Sí | — | `entityreference_autocomplete` | `target_type=user` |
| `field_area` | `entityreference` | Sí | — | `entityreference_autocomplete` | `target_bundles=['area']` |
| `field_date` | `datetime` (módulo Date) | Sí | — | `date_select` | granularidad `year-month-day`, `tz_handling='none'`, `todate` vacío → solo fecha (Y-m-d) |
| `field_start_time` | `text` (plain) | Sí | — | `text_textfield` | `max_length=5` (HH:MM) |
| `field_end_time` | `text` (plain) | Sí | — | `text_textfield` | `max_length=5` (HH:MM) |
| `field_reservation_status` | `list_text` | Sí | `confirmed` | `options_select` | `allowed_values`: `confirmed\|Confirmada`, `cancelled\|Cancelada` |
| `field_cancelled_by` | `list_text` | No | — | `options_select` | `allowed_values`: `user\|Usuario`, `admin\|Admin` |

### Nota sobre `field_status` → campos separados por bundle

El enunciado define `field_status` en ambos bundles pero con `allowed_values` distintos (`active/closed/maintenance` en Área vs `confirmed/cancelled` en Reserva). En Field API D7, `allowed_values` de un `list_text` es setting **de campo**, no de instancia: un mismo `field_status` compartido no puede tener dos catálogos. Se resuelve creando **dos campos distintos**:

- `area` → **`field_area_status`** (`active/closed/maintenance`, default `active`).
- `reservation` → **`field_reservation_status`** (`confirmed/cancelled`, default `confirmed`).

Así cada catálogo queda limpio y ningún Área puede quedar en `confirmed`. `field_condominium` sigue siendo el único campo genuinamente compartido.

---

## Plan de implementación

1. **`myapi.info` — dependencias.** Añadir `dependencies[] = entityreference` y `dependencies[] = date` (tras las líneas `files[]`). No se agrega `files[]` nuevo: toda la lógica vive en `myapi.install`, ya listado.
   *Verificación: `drush pm-list` muestra las dependencias; el módulo no habilita si faltan (ambas ya están en el sitio).*

2. **`myapi.install` — sub-helper `_myapi_reservations_ensure_node_type($type, $name, $description, $title_label)`.** Idempotente: si `node_type_load($type)` ya existe, retorna sin tocar; si no, arma el objeto con `node_type_set_defaults()`, `node_type_save()`, `node_add_body_field()` (si aplica) y setea `node_options_$type = ['status']` y `comment_$type = COMMENT_NODE_HIDDEN` vía `variable_set()`.
   *Verificación: llamable dos veces sin duplicar ni advertir.*

3. **`myapi.install` — sub-helper `_myapi_reservations_ensure_field($field_name, $definition)`.** Idempotente: `if (!field_info_field($field_name)) field_create_field($definition);`. Cubre entityreference (target node/user), text, number_integer, list_text (con `allowed_values`), image y datetime.
   *Verificación: segunda llamada no lanza `FieldException` por campo duplicado.*

4. **`myapi.install` — sub-helper `_myapi_reservations_ensure_instance($field_name, $bundle, $instance)`.** Idempotente: `if (!field_info_instance('node', $field_name, $bundle)) field_create_instance($instance);`. El `$instance` trae `label`, `required`, `widget`, `default_value`, `description` y settings.
   *Verificación: segunda llamada no duplica la instancia.*

5. **`myapi.install` — orquestador `_myapi_reservations_install()`.** Llama en orden: (a) los dos `_ensure_node_type` (`area`, `reservation`); (b) `_ensure_field` para **campos** (`field_condominium` una sola vez, `field_image`, `field_open_time`, `field_close_time`, `field_slot_minutes`, `field_max_minutes`, `field_area_status`, `field_who_can_reserve`, `field_cancel_deadline_minutes`, `field_unit`, `field_requester`, `field_area`, `field_date`, `field_start_time`, `field_end_time`, `field_reservation_status`, `field_cancelled_by`); (c) las **instancias** por bundle según las tablas del modelo. Todo pasa por los sub-helpers idempotentes.
   *Verificación: función única, reejecutable; es la sola fuente de verdad de la creación.*

6. **`myapi.install` — enganchar en `hook_install()`.** En `myapi_install()`, añadir la llamada a `_myapi_reservations_install()` **después** de `myapi_mail_system_register()`. Instalaciones nuevas quedan completas con un `drush en myapi`.
   *Verificación: en un sitio limpio, `drush en myapi` crea `my_api_tokens` + los dos content types y todos los campos.*

7. **`myapi.install` — `myapi_update_7006()`.** Nuevo update hook que llama a `_myapi_reservations_install()`. Docblock explicando que crea los content types de reservas en sitios donde el módulo ya está instalado.
   *Verificación: en el sitio de producción (ya instalado), `drush updb` ejecuta 7006 y crea todo sin duplicar `my_api_tokens` ni tocar datos.*

8. **`myapi.install` — `hook_uninstall()` conservador.** Al inicio de `myapi.install`, `define('MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL', FALSE);` con comentario. En `myapi_uninstall()`, tras `myapi_mail_system_unregister()`, un bloque `if (MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL) { ... field_delete_field() / node_type_delete() ... }` que por defecto **no se ejecuta**. Comentario explicando que los datos son reales del cliente.
   *Verificación: `drush pm-uninstall myapi` no borra content types, campos ni nodos con la constante en FALSE.*

9. **`docs/reservations-install.md`.** Documentar (en el mismo commit): los dos content types y sus campos, la tabla de `allowed_values`, la nota sobre `field_area_status`/`field_reservation_status`, el campo compartido `field_condominium`, la idempotencia, el flujo `drush updb` para sitios existentes y la política conservadora de uninstall (con la constante).

10. **Aplicar y verificar.** `drush cc all` tras los cambios. Recorrer los criterios de aceptación: instalación limpia, `drush updb` en sitio existente, y ciclo `dis/en` (o reejecución del helper) sin duplicados ni errores.

**Nota:** no se toca `myapi.module` (no hay rutas) ni `hook_schema()` (no hay tablas SQL propias).

---

## Criterios de aceptación

- [ ] En un sitio limpio, `drush en myapi` crea la tabla `my_api_tokens` **y** los content types `area` y `reservation` (verificable en `admin/structure/types`).
- [ ] En el sitio de producción donde `myapi` **ya** estaba instalado, `drush updb` ejecuta `myapi_update_7006` y crea ambos content types y todos sus campos, sin tocar `my_api_tokens`, `myapi_password_reset_tokens` ni `myapi_notifications`.
- [ ] Reejecutar la creación (ciclo `drush pm-uninstall`/`drush en`, o reejecutar el update) **no** duplica campos ni instancias ni lanza errores/`FieldException`.
- [ ] El content type `area` tiene exactamente estos campos: `field_condominium`, `field_image`, `field_open_time`, `field_close_time`, `field_slot_minutes`, `field_max_minutes`, `field_area_status`, `field_who_can_reserve`, `field_cancel_deadline_minutes`.
- [ ] El content type `reservation` tiene exactamente estos campos: `field_condominium`, `field_unit`, `field_requester`, `field_area`, `field_date`, `field_start_time`, `field_end_time`, `field_reservation_status`, `field_cancelled_by`.
- [ ] `field_condominium` existe como **un solo campo** (`field_info_field('field_condominium')` único) con **dos instancias** (una en `area`, otra en `reservation`), ambas apuntando al bundle `condominio`.
- [ ] `field_unit` apunta al bundle `vivienda`; `field_area` apunta al bundle `area`; `field_requester` tiene `target_type = user`.
- [ ] `field_open_time`, `field_close_time`, `field_start_time`, `field_end_time` son `text` con `max_length = 5`.
- [ ] `field_slot_minutes` (default `60`), `field_max_minutes` (default `120`) y `field_cancel_deadline_minutes` (default `120`) son `number_integer` con esos valores por defecto.
- [ ] `field_area_status` es `list_text` requerido, default `active`, con valores `active|Activo`, `closed|Cerrado`, `maintenance|En Mantenimiento`.
- [ ] `field_who_can_reserve` es `list_text` requerido, default `both`, con valores `both|Ambos`, `owner|Propietario`, `tenant|Arrendatario`.
- [ ] `field_reservation_status` es `list_text` requerido, default `confirmed`, con valores `confirmed|Confirmada`, `cancelled|Cancelada`.
- [ ] `field_cancelled_by` es `list_text` **no requerido**, con valores `user|Usuario`, `admin|Admin`.
- [ ] `field_date` es de tipo `datetime` (módulo Date), requerido, granularidad solo fecha (año-mes-día), y almacena/lee en formato `Y-m-d`.
- [ ] `field_image` es `image` opcional, cardinalidad 1, con extensiones `png jpg jpeg`.
- [ ] Todos los campos marcados como requeridos en el modelo tienen `required = 1` en su instancia; `field_image` y `field_cancelled_by` tienen `required = 0`.
- [ ] Ambos content types quedan **publicados por defecto**, sin promoción a portada y con comentarios ocultos.
- [ ] `area` usa el título nativo como nombre (no hay campo aparte para el nombre); `reservation` usa el `created` nativo como fecha de creación (no hay campo custom para eso).
- [ ] `myapi.info` declara `dependencies[] = entityreference` y `dependencies[] = date`, y no añade ningún módulo de exposición de API.
- [ ] `drush pm-uninstall myapi` con `MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL = FALSE` **no** borra content types, campos ni nodos de reservas.
- [ ] Existe `docs/reservations-install.md` documentando ambos content types, la idempotencia, el flujo `drush updb` y la política de uninstall.
- [ ] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Sitios ya instalados | Helper privado `_myapi_reservations_install()` llamado por `hook_install()` **y** por `myapi_update_7006()` | Solo `hook_install()` | En producción `myapi` ya está instalado; `drush en` no reejecuta `hook_install()`. El update hook es la vía estándar para llevar el cambio a sitios existentes vía `drush updb`, sin `dis/en`. Un helper único evita duplicar lógica entre ambos. |
| Conflicto `field_status` | Dos campos separados: `field_area_status` y `field_reservation_status` | Un `field_status` compartido con la unión de los 5 valores | En Field API D7 `allowed_values` de `list_text` es setting **de campo**, no de instancia. Un campo compartido permitiría estados inválidos por bundle (un Área en `confirmed`). Campos separados dan catálogos limpios y validación estructural correcta. |
| Almacenamiento de `field_date` | `datetime` (módulo Date), granularidad año-mes-día | `date` (string ISO) / `datestamp` (unix) | `datetime` da una columna SQL `DATETIME` ordenable y filtrable por rango en SQL nativo, ideal para la API futura, y se lee/escribe como `Y-m-d` con granularidad a día. |
| Widget de entity reference | `entityreference_autocomplete` en los cuatro campos | `select` (options) | Autocomplete escala con muchos condominios/viviendas/usuarios/áreas; un `select` cargaría todas las opciones y reventaría el formulario. |
| `field_condominium` compartido | Un campo, dos instancias (una por bundle) | Dos campos distintos (`field_area_condominium`, `field_reservation_condominium`) | Ambos apuntan al mismo bundle `condominio` con idéntica semántica; requisito 3 del enunciado. Un campo compartido evita duplicación y mantiene consistencia. |
| Política de uninstall | Conservador: no borra nada salvo `MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL = TRUE` (default `FALSE`) | Borrar content types y campos en `hook_uninstall()` | Contienen datos reales del cliente (áreas y reservas). Un uninstall accidental destruiría producción. La constante deja el camino destructivo disponible pero desactivado y explícito. |
| Ubicación de la lógica | Todo en `myapi.install` (helpers privados `_myapi_reservations_*`) | Un `.inc` nuevo en `includes/` listado en `.info` | La creación solo corre en install/update; no es lógica de runtime reutilizable por resources. Mantenerla en `myapi.install` evita cargar el `.inc` en cada request y sigue el patrón del esquema existente. |
| Dependencias en `.info` | Solo `entityreference` y `date` | Añadir también módulos de exposición de API | `image`, `list`, `text`, `number` son de core (no se declaran). Los módulos de exposición están prohibidos por CLAUDE.md; `entityreference` y `date` son solo proveedores de tipos de campo. |
| Título de «Reserva» | Título nativo con comportamiento por defecto; autogeneración fuera de scope | Instalar/configurar Automatic Nodetitles en este spec | El título no es relevante para la API. Acoplar la instalación a otro módulo contrib amplía el scope y la superficie de fallo sin beneficio para el esquema. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Los bundles `condominio`/`vivienda` no existen o tienen otro machine name.** Las instancias `entityreference` apuntan a `target_bundles=['condominio']` / `['vivienda']`; si el nombre real difiere, las referencias no resolverían. | Requisito 6 del enunciado: ya existen. Antes de implementar se verifica el machine name exacto en `admin/structure/types`. Si difiere, se ajusta en el helper (un solo lugar). |
| **`entityreference` o `date` no habilitados al ejecutar el update.** `field_create_field()` de esos tipos fallaría si el proveedor no está activo. | Confirmado que ambos están habilitados. Además `dependencies[]` impide habilitar `myapi` sin ellos, y el update solo corre sobre un módulo ya habilitado (por tanto con deps satisfechas). |
| **Idempotencia incompleta.** Si un check `field_info_*` usa caché desactualizada, una segunda pasada podría intentar recrear y lanzar `FieldException`. | Los checks usan `field_info_field()`/`field_info_instance()`/`node_type_load()` que consultan la definición viva; el helper omite la creación si ya existe. Criterio de aceptación de reejecución sin duplicados. |
| **`created` nativo insuficiente para la reserva.** Se usa `node.created` como fecha de creación de la reserva; no distingue fecha del evento (`field_date`) de fecha de alta. | Son campos distintos y deliberados: `field_date` = día reservado; `created` = alta del registro. Documentado en `docs/reservations-install.md`. |
| **Uninstall destructivo activado por error.** Si alguien pone `MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL = TRUE`, un uninstall borraría content types y datos reales. | Default `FALSE`, comentario de advertencia junto a la constante y en la doc. El camino destructivo es explícito y opt-in, nunca automático. |
| **`max_length=5` demasiado rígido para horas.** `HH:MM` cabe en 5, pero no admite segundos ni formatos alternativos. | Es el formato especificado (24h `HH:MM`). La validación de formato es de la API futura (fuera de scope); el esquema solo fija la longitud. |

---

## Lo que **no** entra en este spec

- Cualquier **endpoint REST** de áreas o reservas (listar/crear/cancelar).
- **Lógica de negocio**: solapamientos, slots, `field_max_minutes` múltiplo del slot, ventana de cancelación, validación de formato `HH:MM`.
- **Título autogenerado** de «Reserva» (Automatic Nodetitles) y su configuración.
- **Displays/view modes/formatters** de salida de los campos.
- **Creación o migración de nodos** de datos (áreas/reservas reales).
- **Crear o modificar** los content types `condominio` y `vivienda`.
- **Borrado de datos en uninstall** por defecto.

Cada uno de estos, si llega, va en su propio spec.
