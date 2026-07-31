# 49 — Rol «administrador edificio» con alcance por condominio

- **Estado:** Approved
- **Fecha:** 2026-07-28
- **Dependencias:**
  - `32-reservations-content-types-install` (Implemented) — patrón idempotente `_myapi_reservations_ensure_node_type()` / `_ensure_field()` / `_ensure_instance()` en `myapi.install`, que este spec **replica** para el rol, sus permisos y el nuevo campo de usuario; también define los bundles `area` y `reservation` y su `field_condominium`.
  - `47-reservation-calendar-admin` (Implemented) — `myapi_calendar_admin_roles()` y `myapi_calendar_access()` en `myapi.module`, y `myapi_calendar_condominium_options()` en `includes/myapi.reservation_calendar.inc`; este spec **modifica** las tres para admitir el nuevo rol y acotar su selector de condominios.
  - `48-reservation-notifications` (Implemented) — `myapi_reservation_backend_uids()` en `includes/myapi.reservation_notification.inc`; este spec **amplía** el destinatario del email de detalle de reserva creada.
  - `29-bulletins-list` / `31-bulletins-condominium-filter` (Implemented) — semántica de `field_tipo_de_boletin` (`General` / `Condominio` / `Personalizado`) y `field_condominio` del bundle `boletin`, que este spec usa para restringir qué boletines puede publicar el rol.
  - `25-notifications-inbox-boletin` (Implemented) — `myapi_notification_create_from_boletin()`, disparado desde `hook_node_insert()`: un boletín guardado por este rol dispara el mismo fan-out de push/inbox que cualquier otro.
- **Objetivo:** Crear de forma idempotente, al instalar o actualizar el módulo, el rol `administrador edificio` con permisos de creación y edición sobre boletines, reservas, áreas y —si el bundle existe— reclamos y sugerencias, restringidos en el back office de Drupal a los condominios asignados al usuario mediante un nuevo campo de usuario.

Dos notas transversales:

- **Nombre en minúscula, comparado siempre por nombre.** El `rid` se asigna por entorno, así que el código nunca lo referencia (mismo criterio que `myapi_calendar_admin_roles()` con `administrator` / `backend`). La constante queda `define('MYAPI_BUILDING_ADMIN_ROLE', 'administrador edificio');`.
- **Ningún endpoint `api/v1/...` cambia.** El rol es exclusivamente de back office.

---

## Alcance

### Entra en este spec

1. **Creación del rol `administrador edificio`** en `myapi_install()` y en un nuevo `myapi_update_7012()`, mediante un helper idempotente que lo busca por nombre y solo lo crea si falta. (`myapi_update_7011()` ya está ocupado por el spec 50; el siguiente número libre es el 7012.)
2. **Nuevo campo de usuario `field_condominio_admin`** — `entityreference` a nodos `condominio`, cardinalidad ilimitada, instancia sobre el bundle `user`, editable desde `/user/N/edit`. Mismo patrón `_ensure_field()` / `_ensure_instance()` ya existente.
3. **Catálogo de permisos concedidos al rol**, en un único punto del código:
   - Contenido: `create` y `edit any` sobre `boletin`, `reservation`, `area`; los mismos sobre el bundle de reclamos y sugerencias **solo si ese bundle existe**. Nunca `delete`.
   - Back office: `access content`, `access content overview`, `access administration pages`, `view the administration theme`, `access toolbar`.
4. **Filtro por condominio en el back office**, en dos piezas:
   - `hook_node_access()` — deniega `view` / `update` / `delete` sobre cualquier nodo **de los tipos con condominio resoluble** que no pertenezca a sus condominios asignados (403 por URL directa).
   - `hook_query_alter()` sobre el tag `node_access` — estrecha `/admin/content` y demás listados a **los tipos visibles (`boletin`, `reservation`, `area`, reclamos, `condominio` y —añadida tras la aprobación— `vivienda`) Y a sus condominios asignados**. `condominio` entra en la lista porque los autocompletados de `entityreference` consultan con ese mismo tag: sin él, el selector de `field_condominio` del boletín y el de `field_condominium` de área/reserva quedarían vacíos y el rol no podría crear nada. Como efecto lateral buscado, esos autocompletados solo ofrecen los condominios asignados.
5. **Alcance de la denegación de vista**, con el condominio resuelto así:

   | Tipo | Cómo se resuelve el condominio |
   |------|-------------------------------|
   | `condominio` | El propio `nid` |
   | `boletin`, `gastos` | `field_condominio` |
   | `area`, `reservation` | `field_condominium` |
   | `vivienda` | `field_condominio` |
   | `pagos`, `recibo`, `alicuota_extra` | `field_vivienda` → `field_condominio` de la vivienda |
   | reclamos y sugerencias (futuro) | `field_condominio`, si el bundle lo trae |

   Un nodo de un tipo no listado (o de un tipo listado al que le falte el campo) queda **fuera** de la regla: `hook_node_access()` devuelve `NODE_ACCESS_IGNORE` y decide el resto del sistema. La denegación aplica **solo a este rol**; el resto de usuarios y la API no cambian.
6. **Restricción del boletín en el formulario de nodo** (`hook_node_validate()`): este rol solo puede guardar boletines con `field_tipo_de_boletin = Condominio` y `field_condominio` entre sus asignados. `General` y `Personalizado` se rechazan con error de formulario.
7. **Calendario de reservas (spec 47)**: el rol entra en `myapi_calendar_admin_roles()` y su selector de condominios se acota a los asignados; `?condominium=N` con un condominio ajeno no devuelve nada.
8. **Email de reserva creada (spec 48)**: además del rol `backend`, lo reciben los administradores de edificio cuyos condominios asignados incluyan el de la reserva.
9. **Pruebas unitarias** (solo PHPUnit, `tests/unit/BuildingAdminTest.php`) sobre los catálogos, la resolución del condominio, la decisión de acceso y la validación del boletín.
10. **Documentación** en `docs/building-admin-role.md`.

### NO entra en este spec

- **Crear el tipo de contenido «reclamos y sugerencias».** Los permisos se conceden condicionalmente si el bundle existe; su propio spec lo creará y bastará re-ejecutar el helper.
- **Cualquier cambio en los endpoints `api/v1/...`** ni en la app Flutter.
- **Filtro por condominio para `administrator` y `backend`**: siguen viendo todo.
- **Asignar el rol a usuarios existentes.** Se crea vacío; asignarlo es manual.
- **UI propia de asignación de condominios.** Se usa el formulario nativo de usuario.
- **Node access grants** (`hook_node_grants()` / `hook_node_access_records()`) y su reconstrucción de permisos.
- **Pruebas de integración (`tests/integration/`) y e2e (`tests/e2e/`)**: la verificación de `hook_node_access()`, del `hook_query_alter()` y de la instalación es manual, contra los criterios de aceptación.
- **Borrar el rol, permisos o campo al desinstalar.** Criterio conservador, igual que los tipos de contenido de reservas.
- **Permisos de administración de usuarios, taxonomía, vistas o configuración del sitio.**

---

## Modelo de datos

Este spec **no crea ninguna tabla propia**: no hay `hook_schema()` nuevo. Todo se apoya en tablas de core y en la Field API.

### 1. Rol y permisos (tablas de core)

| Tabla | Qué se escribe |
|-------|----------------|
| `role` | Una fila con `name = 'administrador edificio'`. El `rid` lo asigna Drupal por entorno y **el código nunca lo referencia**. |
| `role_permission` | Una fila por permiso concedido (ver catálogo abajo). |
| `users_roles` | Vacía al instalar: asignar el rol a una persona es tarea manual del administrador. |

### 2. Campo de usuario `field_condominio_admin`

Definición a nivel de campo (storage), creada con `_myapi_reservations_ensure_field()`:

```php
[
  'field_name'  => 'field_condominio_admin',
  'type'        => 'entityreference',
  'cardinality' => FIELD_CARDINALITY_UNLIMITED,
  'settings'    => ['target_type' => 'node'],
]
```

Instancia sobre la entidad usuario, creada con `_myapi_reservations_ensure_instance()` (que hoy asume `entity_type = 'node'`; el spec **generaliza el helper** para aceptar el tipo de entidad):

```php
[
  'field_name'  => 'field_condominio_admin',
  'entity_type' => 'user',
  'bundle'      => 'user',
  'label'       => 'Condominios administrados',
  'required'    => 0,
  'description' => 'Condominios que este usuario puede administrar. Solo aplica al rol "administrador edificio"; sin ningún condominio, no verá contenido alguno.',
  'settings'    => [
    'handler'          => 'base',
    'handler_settings' => ['target_bundles' => ['condominio' => 'condominio']],
  ],
  'widget'      => ['type' => 'entityreference_autocomplete_tags'],
]
```

La Field API crea `field_data_field_condominio_admin` y `field_revision_field_condominio_admin` con `entity_type = 'user'`.

### 3. Catálogos en código (fuente única de verdad)

Todo vive en un archivo nuevo `includes/myapi.building_admin.inc`, registrado en `myapi.info`:

```php
define('MYAPI_BUILDING_ADMIN_ROLE', 'administrador edificio');
define('MYAPI_BUILDING_ADMIN_CONDO_FIELD', 'field_condominio_admin');

/** Bundle de reclamos y sugerencias; aún no existe (ver alcance). */
define('MYAPI_BUILDING_ADMIN_CLAIM_TYPE', 'reclamo');

/** Tipos que el rol puede crear/editar. */
function myapi_building_admin_editable_types();   // ['boletin', 'reservation', 'area'] + 'reclamo' si el bundle existe

/** Tipos que el rol puede ver en listados y autocompletados: los editables + 'condominio'. */
function myapi_building_admin_visible_types();    // myapi_building_admin_editable_types() + ['condominio']

/** Permisos concedidos al rol, ya expandidos por tipo. Nunca incluye 'delete any'. */
function myapi_building_admin_permissions();

/** Cómo se resuelve el condominio de cada tipo sujeto a la regla de acceso. */
function myapi_building_admin_condominium_map();
```

El mapa del punto 5 del alcance, en su forma concreta:

```php
[
  'condominio'     => ['mode' => 'self'],
  'boletin'        => ['mode' => 'direct',   'field' => 'field_condominio'],
  'gastos'         => ['mode' => 'direct',   'field' => 'field_condominio'],
  'vivienda'       => ['mode' => 'direct',   'field' => 'field_condominio'],
  'area'           => ['mode' => 'direct',   'field' => 'field_condominium'],
  'reservation'    => ['mode' => 'direct',   'field' => 'field_condominium'],
  'pagos'          => ['mode' => 'via_unit', 'field' => 'field_vivienda'],
  'recibo'         => ['mode' => 'via_unit', 'field' => 'field_vivienda'],
  'alicuota_extra' => ['mode' => 'via_unit', 'field' => 'field_vivienda'],
]
```

### 4. Catálogo de permisos concedidos

| Permiso | Módulo | Por qué |
|---------|--------|---------|
| `create boletin content`, `edit any boletin content` | node | Crear y editar boletines |
| `create reservation content`, `edit any reservation content` | node | Crear y editar reservas |
| `create area content`, `edit any area content` | node | Crear y editar áreas |
| `create reclamo content`, `edit any reclamo content` | node | **Solo si el bundle existe** |
| `access content` | node | Ver nodos publicados |
| `access content overview` | node | Entrar a `/admin/content` |
| `access administration pages` | system | Navegar el back office |
| `view the administration theme` | system | Formularios de nodo con el tema admin |
| ~~`access toolbar`~~ | ~~toolbar~~ | **Retirado durante la implementación** (ver nota abajo) |

> **Cambio posterior a la aprobación — `access toolbar` retirado.** Al probar el
> rol se vio que la barra negra de Drupal le ofrece entradas de *Estructura* y
> *Configuración* de las que solo puede obtener un 403. Decisión del cliente:
> el rol navega por un **menú propio en el sidebar**, acotado a lo que gestiona
> (boletines, áreas, reservas y el calendario), que es configuración del sitio y
> no de este módulo. `access administration pages` y `access content overview`
> se mantienen, así que `/admin/content` sigue siendo accesible por URL.
>
> Como el instalador nunca revoca, en los entornos que ya lo tienen concedido
> hay que ejecutar además:
> `drush role-remove-perm "administrador edificio" "access toolbar"`

> **Cambio posterior a la aprobación — `vivienda` pasa a ser visible en solo
> lectura.** El operador del edificio necesita consultar las viviendas de su
> condominio y quién las ocupa. Se añade `vivienda` a un catálogo nuevo,
> `myapi_building_admin_readonly_types()`, separado del de tipos editables:
> nada de ese catálogo llega a `myapi_building_admin_permissions()`, así que el
> rol **no** recibe `create` ni `edit any` sobre viviendas y `/node/N/edit`
> sigue devolviendo 403. El filtro por condominio se le aplica igual que al
> resto (modo `direct` sobre `field_condominio`), así que solo ve las de sus
> condominios asignados. `pago`, `recibo` y `gasto` siguen fuera.
>
> **Las «personas» de las viviendas quedan pendientes**: los propietarios y
> ocupantes son **usuarios**, no nodos, y ni `hook_node_access()` ni el tag
> `node_access` alcanzan a la entidad usuario. Conceder `access user profiles`
> abriría los perfiles de **todo** el sitio y rompería el aislamiento. La
> salida correcta es una vista sobre nodos `vivienda` que muestre los datos de
> propietario y ocupante como campos mediante una relación — la vista viaja por
> el tag `node_access` y hereda el filtro. Requiere su propio spec.

**Ningún permiso `delete any … content` ni `delete own … content`.** El rol no borra contenido: la baja de una reserva se hace cancelándola (spec 36 / 47) y la de un boletín o un área, despublicándola desde el formulario de edición. `hook_node_access()` sigue denegando también la operación `delete` sobre nodos ajenos, como defensa redundante por si alguien concede el permiso a mano más adelante.

---

## Plan de implementación

Cada paso deja el sitio funcionando; los pasos 1–2 no cambian ningún comportamiento hasta que el 3 entra en juego.

**1. Nuevo `includes/myapi.building_admin.inc` — catálogos y helpers, sin efectos.**
Constantes (`MYAPI_BUILDING_ADMIN_ROLE`, `MYAPI_BUILDING_ADMIN_CONDO_FIELD`, `MYAPI_BUILDING_ADMIN_CLAIM_TYPE`), los tres catálogos del modelo de datos y los helpers de lectura:

- `myapi_building_admin_is($account)` — TRUE si la cuenta tiene el rol (por nombre, nunca por `rid`; `uid 1` **no** lo hereda).
- `myapi_building_admin_condominium_ids($account)` — nids de `field_condominio_admin`, con caché estática por `uid`.
- `myapi_building_admin_node_condominium($node)` — aplica el mapa (`self` / `direct` / `via_unit`) y devuelve el nid del condominio o `NULL` si no se puede resolver.

Añadir `files[] = includes/myapi.building_admin.inc` a `myapi.info`.

**2. Instalación idempotente en `myapi.install`.**

- Generalizar `_myapi_reservations_ensure_instance()` con un parámetro opcional `$entity_type = 'node'` (compatible hacia atrás; el nombre se queda como está para no tocar los updates 7006–7009 ya ejecutados).
- Nuevo `_myapi_building_admin_install()`, idempotente en tres tramos: (a) `user_role_load_by_name()` y, si falta, `user_role_save()`; (b) `_ensure_field()` + `_ensure_instance()` del campo de usuario; (c) `user_role_grant_permissions()` **solo con los permisos que existen realmente** en el sitio — se cruzan contra `module_invoke_all('permission')` para que un `create reclamo content` de un bundle inexistente, o `access toolbar` sin el módulo toolbar activo, se ignore en silencio en vez de escribir una fila muerta en `role_permission`.
- Llamarlo desde `myapi_install()` y desde un nuevo `myapi_update_7012()`. **No `7011`: ese número lo consumió el spec 50** (`_myapi_reservations_install()` + `myapi_mail_system_register()`) y ya está ejecutado en producción, así que reutilizarlo dejaría el update nuevo sin correr en los sitios existentes.

**3. `hook_node_access()` en `myapi.module`.**
Delega en `includes/myapi.building_admin.inc`. Devuelve `NODE_ACCESS_IGNORE` salvo que se cumplan todas: el usuario tiene el rol, el tipo está en el mapa, y el condominio del nodo se resolvió. En ese caso, `NODE_ACCESS_DENY` si el condominio no está entre los asignados (incluido el caso "sin ningún condominio asignado" ⇒ deniega siempre) y `NODE_ACCESS_IGNORE` si sí lo está — nunca `NODE_ACCESS_ALLOW`, para no saltarse el resto de comprobaciones de Drupal. Cubre `view`, `update` y `delete`.

*Nota:* la creación (`op = 'create'`) no pasa por aquí en D7; el condominio del nodo nuevo se controla en el paso 5 para boletines, y para `area`/`reservation` se acepta que el operador elija el condominio en el formulario (queda como riesgo asumido).

**4. `hook_query_TAG_alter()` sobre el tag `node_access`.**
Implementado como `myapi_query_node_access_alter(QueryAlterableInterface $query)`. Tres guardas antes de tocar nada, en este orden:

1. **El usuario actual tiene el rol** (si no, `return` inmediato: cero coste para todos los demás).
2. **La consulta tiene `node` como tabla base.** El tag `node_access` no implica que se consulte la tabla `node`: otros módulos lo aplican sobre consultas de taxonomía, búsqueda o entidades distintas. Se recorre `$query->getTables()` buscando una entrada con `table === 'node'` y se guarda su alias; si no aparece ninguna, `return` sin alterar. Sin esta guarda, el `JOIN` y las condiciones se añadirían sobre consultas que no tienen esa columna y romperían con un error SQL en páginas ajenas a este spec.
3. **Sin condominios asignados**, se añade `1 = 0` y se termina: el rol no ve nada.

Con las guardas superadas, estrecha la consulta a `<alias>.type IN (tipos visibles)` **y** a los condominios asignados, mediante `LEFT JOIN` a la tabla de campo correspondiente por tipo (`field_data_field_condominio`, `field_data_field_condominium`, `field_data_field_vivienda` para el modo `via_unit`), más el modo `self` de `condominio` como `<alias>.nid IN (asignados)`. Las ramas se combinan con un grupo `OR` (`db_or()`), una por tipo.

Verificado que ninguna consulta de este módulo lleva el tag `node_access` (cero `addTag('node_access')` en `resources/` e `includes/`), así que la API no se ve afectada.

*Nota sobre `/admin/content`:* en este sitio ese listado lo sirve una vista de Views. Las consultas de Views llevan el tag `node_access` salvo que la vista tenga marcado *«Disable SQL rewriting»*; hay que **comprobarlo en el entorno antes de dar por buenos los criterios de aceptación del listado**. Si estuviera desactivado, el filtro no se aplicaría a esa pantalla (sí al resto) y la solución sería desmarcar esa opción en la vista, no añadir código.

**5. Ampliar `hook_node_validate()` (ya existe en `myapi.module`) para `boletin`.**
Hoy la implementación es `if ($node->type !== 'area') { return; }` seguida de la validación de horarios del área, así que **hay que reestructurarla, no añadir código detrás**: un `switch`/`if` por tipo que delegue en `myapi_area_validate_times()` para `area` y en el nuevo validador de boletín para `boletin`, dejando el resto de tipos sin tocar. El comportamiento actual de `area` no cambia.

Si el usuario tiene el rol: `field_tipo_de_boletin` distinto de `Condominio` ⇒ `form_set_error()`; `field_condominio` vacío o fuera de sus asignados ⇒ `form_set_error()`. Los mensajes son de formulario admin (no pasan por `myapi_t()`, que es de la API).

**6. Calendario de reservas (spec 47).**

- Añadir el rol a `myapi_calendar_admin_roles()`.
- `myapi_calendar_condominium_options()` acepta filtrar por una lista de nids; el calendario le pasa los condominios asignados cuando el usuario tiene el rol.
- Validar `?condominium=N`: si no está entre los asignados, se trata como "sin selección" (y la consulta de reservas no devuelve nada de ese condominio).

**7. Email de reserva creada (spec 48).**
En `includes/myapi.reservation_notification.inc`, los destinatarios del email de detalle pasan a ser la unión de `myapi_reservation_backend_uids()` y los uids con rol `administrador edificio` cuyo `field_condominio_admin` contenga el condominio de la reserva. Sin duplicados (`array_unique`), y sin cambiar el cuerpo del email ni la notificación al residente.

**8. Pruebas unitarias (PHPUnit), en `tests/unit/`.**
Mismo patrón que las existentes (`ReservationCancelReasonTest.php`, `AreaTimeFormatTest.php`): PHPUnit puro sobre `tests/unit/bootstrap.php`, sin Drupal arrancado, probando **funciones puras**. Nada de tests de integración ni e2e en este spec.

Para que sean testeables, la lógica decidible se extrae a funciones sin dependencias de Drupal dentro de `includes/myapi.building_admin.inc`, y los helpers que sí tocan Drupal (`user_role_load_by_name()`, `field_get_items()`, `node_load()`) quedan como envoltorios finos alrededor de ellas. Un único archivo nuevo, `tests/unit/BuildingAdminTest.php`, que cubre:

- **Catálogos.** `myapi_building_admin_editable_types()` incluye `boletin`, `reservation` y `area` y **no** incluye `condominio`; `myapi_building_admin_visible_types()` es el anterior más `condominio`; `myapi_building_admin_permissions()` no contiene **ningún** permiso que empiece por `delete `; el mapa de `myapi_building_admin_condominium_map()` cubre exactamente los nueve tipos del modelo de datos con los modos y campos declarados.
- **Resolución del condominio de un nodo** (`myapi_building_admin_node_condominium()` sobre su función pura): modo `self` devuelve el propio nid; modo `direct` devuelve el target del campo; modo `via_unit` devuelve el condominio de la vivienda; tipo fuera del mapa ⇒ `NULL`; tipo del mapa con el campo vacío o ausente ⇒ `NULL` y **sin error PHP**.
- **Decisión de acceso** (función pura que recibe condominio del nodo y lista de asignados, y devuelve `deny` / `ignore`): condominio asignado ⇒ `ignore`; condominio ajeno ⇒ `deny`; lista de asignados vacía ⇒ `deny`; condominio `NULL` ⇒ `ignore`. Se comprueba explícitamente que **nunca** devuelve `allow`.
- **Validación del boletín** (función pura que recibe tipo de boletín, condominio y asignados, y devuelve la lista de errores): `General` y `Personalizado` ⇒ error; `Condominio` con condominio asignado ⇒ sin errores; `Condominio` con condominio ajeno o vacío ⇒ error; `field_tipo_de_boletin` ausente ⇒ sin errores (la rama que se salta la validación y registra el `watchdog`, según el primer riesgo de la tabla).
- **Filtro de permisos contra los existentes en el sitio**: dada una lista de permisos deseados y un catálogo simulado de permisos disponibles, se conceden solo los que existen — `create reclamo content` fuera cuando el bundle no está, `access toolbar` fuera cuando el módulo no está.

`hook_node_access()`, `hook_query_alter()` y la instalación idempotente **no** se cubren con pruebas automáticas: dependen del contenedor de Drupal y se verifican a mano con los criterios de aceptación de abajo.

**9. Documentación y despliegue.**
`docs/building-admin-role.md`: qué hace el rol, cómo se asigna el condominio a un usuario, la tabla de resolución de condominio por tipo, la advertencia de que quitar permisos a mano no sobrevive a re-ejecutar el update, y la nota de que el filtro alcanza a **toda** consulta con el tag `node_access` (incluidos autocompletados y bloques), no solo a `/admin/content`. Cerrar con `drush updb` + `drush cc all`.

---

## Criterios de aceptación

**Instalación e idempotencia**

- [ ] Tras `drush en myapi` en un sitio limpio, existe un rol con `name = 'administrador edificio'` en `/admin/people/roles`.
- [ ] Tras `drush updb` en un sitio con el módulo ya instalado, existe ese mismo rol y no hay un segundo rol duplicado.
- [ ] Re-ejecutar el update (`myapi_update_7012`) dos veces seguidas no crea un segundo rol, ni un segundo campo, ni filas duplicadas en `role_permission`.
- [ ] `myapi_update_7011` sigue existiendo intacto (spec 50) y el nuevo update es `7012`.
- [ ] `field_condominio_admin` aparece en `/admin/config/people/accounts/fields` y es editable en `/user/N/edit` como autocompletado que solo ofrece nodos `condominio`.
- [ ] Ningún permiso `delete any … content` ni `delete own … content` queda concedido al rol en `/admin/people/permissions`.
- [ ] Con el bundle de reclamos ausente, no hay ninguna fila en `role_permission` con `create reclamo content` ni `edit any reclamo content`; al crear el bundle y re-ejecutar el update, ambas aparecen.
- [ ] Desinstalar el módulo (`drush dis myapi && drush pm-uninstall myapi`) deja el rol, sus permisos y el campo intactos.

**Filtro por condominio en back office**

- [ ] Un usuario con el rol y el condominio A asignado ve en `/admin/content` únicamente nodos `boletin`, `reservation`, `area` (y reclamos si existe) del condominio A, más el propio nodo `condominio` A.
- [ ] ~~Ese mismo usuario **no ve** en `/admin/content` ningún `pago`, `recibo`, `gasto` ni `vivienda`, ni siquiera de A~~, ni ningún `condominio` distinto de A. **Modificado tras la aprobación:** `vivienda` pasa a ser visible, en solo lectura y acotada al condominio A (ver nota abajo). `pago`, `recibo` y `gasto` siguen ocultos.
- [ ] Ese mismo usuario ve en `/admin/content` las `vivienda` de A y **ninguna** de B, y `/node/<vivienda>/edit` devuelve 403 también para las de A.
- [ ] Ese mismo usuario **no puede editar** el nodo `condominio` A: `/node/<A>/edit` devuelve 403 (ve el condominio, no lo administra).
- [ ] Antes de dar por buenos los tres criterios anteriores, se ha comprobado que la vista que sirve `/admin/content` **no** tiene marcado *«Disable SQL rewriting»*.
- [ ] Abrir por URL directa `/node/N` de una `reservation` del condominio B devuelve 403.
- [ ] Abrir por URL directa `/node/N` de un `pago` cuya vivienda pertenece a B devuelve 403.
- [ ] Abrir por URL directa `/node/N` de un `pago` cuya vivienda pertenece a A devuelve 200.
- [ ] `/node/N/edit` de un `boletin` del condominio B devuelve 403.
- [ ] Un usuario con el rol y **sin ningún condominio asignado** ve `/admin/content` vacío y recibe 403 en cualquier nodo de los tipos del mapa.
- [ ] Un nodo `reservation` sin `field_condominium` relleno no provoca error PHP: el acceso lo decide el resto del sistema (`NODE_ACCESS_IGNORE`).
- [ ] Con el rol activo, el autocompletado de `field_condominio` en el formulario de boletín ofrece los condominios asignados y **solo** esos — no devuelve una lista vacía.
- [ ] Lo mismo para `field_condominium` en los formularios de `area` y `reservation`, y para `field_area` en el de `reservation` (áreas del condominio asignado).
- [ ] Navegando el back office con el rol activo (portada, `/admin/content`, formularios de nodo, bloques del tema) no aparece ningún error SQL ni entrada nueva en `/admin/reports/dblog`: el `hook_query_alter()` ignora las consultas etiquetadas `node_access` cuya tabla base no es `node`.
- [ ] Un usuario con rol `administrator` o `backend` sigue viendo todo el contenido de todos los condominios, sin cambios.
- [ ] Un residente autenticado en la app recibe exactamente las mismas respuestas que antes en todos los endpoints `api/v1/...` (verificado al menos en reservas, pagos, recibos y boletines).

**Boletines**

- [ ] **Añadido tras la aprobación:** con el rol activo, el selector `field_tipo_de_boletin` del formulario ofrece **solo** `Condominio` — `General` y `Personalizado` no aparecen (`hook_form_boletin_node_form_alter()`). La validación del paso 5 se mantiene como red de seguridad y los dos criterios siguientes se comprueban enviando el formulario a mano.
- [ ] Con el rol activo, guardar un boletín con `field_tipo_de_boletin = General` muestra error de formulario y no crea el nodo.
- [ ] Lo mismo con `field_tipo_de_boletin = Personalizado`.
- [ ] Guardar un boletín `Condominio` con `field_condominio = B` (no asignado) muestra error de formulario y no crea el nodo.
- [ ] Guardar un boletín `Condominio` con `field_condominio = A` crea el nodo y dispara el fan-out habitual de push + inbox (spec 25) hacia los destinatarios de A.
- [ ] Un usuario `administrator` sigue pudiendo guardar boletines `General` y `Personalizado` sin ninguna traba nueva.

**Calendario de reservas (spec 47)**

- [ ] Un usuario con el rol accede a `/admin/content/reservation-calendar` sin 403.
- [ ] Su selector de condominios lista solo los asignados.
- [ ] Forzar `?condominium=B` en la URL no muestra ninguna reserva de B.

**Email de reserva creada (spec 48)**

- [ ] Al crear una reserva vía `POST /api/v1/reservations` en el condominio A, el email de detalle llega a los usuarios con rol `backend` **y** a los administradores de edificio con A asignado.
- [ ] Un administrador de edificio con solo B asignado **no** recibe ese email.
- [ ] Un usuario que tenga a la vez `backend` y `administrador edificio` con A asignado recibe **un solo** email.
- [ ] El email al residente y su contenido no cambian respecto al spec 48.

**Pruebas unitarias**

- [ ] `vendor/bin/phpunit` pasa en verde, incluido el nuevo `tests/unit/BuildingAdminTest.php`, y las pruebas existentes siguen pasando.
- [ ] Existe un caso que falla si alguien añade un permiso `delete …` al catálogo.
- [ ] Existe un caso que falla si la decisión de acceso devuelve `allow` en cualquier escenario.
- [ ] Existe un caso para cada modo del mapa (`self`, `direct`, `via_unit`) y para el campo ausente ⇒ `NULL`.
- [ ] No se añade ningún test a `tests/integration/` ni a `tests/e2e/`.

**Documentación**

- [ ] Existe `docs/building-admin-role.md` con el procedimiento de asignación y la tabla de resolución de condominio por tipo.
- [ ] `docs/building-admin-role.md` advierte de que el filtro alcanza a toda consulta con el tag `node_access`, incluidos autocompletados y bloques.

---

## Decisiones tomadas y descartadas

| Decisión | Alternativa descartada | Por qué |
|----------|------------------------|---------|
| Relación usuario→condominio con un campo `entityreference` en la entidad usuario (`field_condominio_admin`) | Tabla propia `myapi_user_condominium` con UI de gestión | El campo se edita con el formulario nativo de usuario: cero interfaz que construir y mantener. La tabla propia solo compensaría si hiciera falta guardar metadatos por asignación (fecha, alcance), y no hace falta. |
| Nombre del rol literal en minúscula: `administrador edificio` | `Administrador edificio` capitalizado, o un nombre de máquina en inglés | Decisión explícita del cliente. El código lo compara siempre por nombre y nunca por `rid`, igual que `myapi_calendar_admin_roles()` con `administrator` / `backend`, porque el `rid` cambia entre entornos. |
| Filtro con `hook_node_access()` + `hook_query_alter()` | Node access grants (`hook_node_grants()` / `hook_node_access_records()`) | Los grants obligan a reconstruir la tabla `node_access` de todo el sitio en cada instalación, en cada update y cada vez que se cambia la asignación de un usuario — sobre un sitio en producción con miles de nodos de pagos y recibos. `hook_node_access()` decide en caliente, sin estado que reconstruir ni riesgo de dejar el sitio con permisos a medio reconstruir. |
| El rol nunca borra contenido | `delete any … content` sobre los cuatro tipos | Las reservas y los boletines son registros reales con notificaciones ya enviadas; la baja correcta es cancelar (spec 36) o despublicar, ambas reversibles. Un borrado desde el back office no lo es. |
| La denegación de vista se extiende a `pago`, `recibo`, `gasto`, `vivienda` y `condominio` | Filtrar solo los listados y dejar la URL directa abierta | Sin esto, el listado filtrado daría una falsa sensación de aislamiento: con la URL exacta, un operador de un edificio podría leer los pagos de otro. La restricción aplica **solo a este rol**, así que ni la app ni los demás usuarios cambian de comportamiento. |
| Solo boletines de tipo `Condominio`, y solo de sus condominios | Permitir también `General` y `Personalizado` | Un boletín `General` notifica por push y correo a **todos** los condominios del sistema: es la única acción del rol con alcance fuera de su edificio, y es irreversible una vez enviada. |
| `/admin/content` limitado a los tipos editables **más `condominio`** | Dejar solo los cuatro tipos editables | Con `access content`, el listado incluiría pagos, recibos y viviendas; acotarlos por condominio abre casos raros (nodos sin campo de condominio) para información que este rol no gestiona. Pero `condominio` **no puede excluirse**: los autocompletados de `entityreference` consultan con el tag `node_access`, así que excluir el tipo dejaría vacíos los selectores de `field_condominio` y `field_condominium` y el rol no podría crear ni un boletín ni un área ni una reserva. Se acepta que vea sus condominios asignados en el listado; sin `edit any condominio content` no puede tocarlos. |
| El nuevo update es `myapi_update_7012` | `myapi_update_7011`, como decía el borrador de este spec | El 7011 ya lo consumió el spec 50 y está ejecutado en producción: reutilizarlo dejaría este spec sin correr en los sitios existentes, porque `system_update_7000` solo ejecuta números por encima del `schema_version` almacenado. |
| El `hook_query_alter()` comprueba que la tabla base sea `node` antes de alterar | Alterar toda consulta con el tag `node_access` | El tag no garantiza que se consulte la tabla `node` — otros módulos lo aplican sobre taxonomía, búsqueda u otras entidades. Sin la comprobación, el `JOIN` y las condiciones se añadirían sobre consultas sin esa columna y romperían con error SQL páginas que este spec no toca. |
| Solo pruebas unitarias PHPUnit sobre funciones puras | Añadir también pruebas de integración con Drupal arrancado | Es la convención del repositorio (`tests/unit/` con `bootstrap.php` propio, sin Drupal); montar integración para `hook_node_access()` y `hook_query_alter()` exigiría un entorno de test con base de datos que hoy no existe. La contrapartida —esos dos hooks se verifican a mano— queda escrita en los criterios de aceptación. |
| Instalación conservadora: crear si falta, nunca revocar ni borrar | Reponer el set completo de permisos en cada ejecución; borrar el rol al desinstalar | Un administrador puede haber ajustado permisos a mano en producción; el instalador no debe pisarlos en cada `drush updb`. Y desinstalar el módulo no puede llevarse por delante asignaciones de usuarios reales. Contrapartida aceptada: re-ejecutar explícitamente el update **sí** vuelve a conceder lo que falte. |
| Permisos concedidos solo si existen en el sitio (cruce contra `module_invoke_all('permission')`) | Conceder la lista completa a ciegas | Evita filas muertas en `role_permission` para el bundle de reclamos (que aún no existe) o para `access toolbar` si el módulo toolbar está desactivado, y hace que el mismo helper sirva sin cambios cuando esos elementos aparezcan. |
| El email de reserva creada llega también a los administradores del condominio de la reserva | Dejarlo solo en `backend`, o mandarlo a todos los administradores de edificio | El operador que gestiona el edificio es justamente quien necesita el aviso; mandarlo a los de otros edificios sería ruido y fuga de datos de residentes ajenos. |
| Este spec no toca ningún endpoint `api/v1/...` | Filtrar también las respuestas de la API por condominio asignado | El rol es de back office. Meter la API dentro obligaría a revisar los diez recursos existentes y su lógica de propietario/ocupante, y no hay hoy un caso de uso: los administradores de edificio no usan la app. |
| `reclamo` como nombre de máquina provisional del bundle de reclamos | Esperar a que exista el tipo para escribir este spec | Aislado en una constante y protegido por `node_type_load()`: si el bundle nunca llega o se llama de otro modo, el código no rompe y el ajuste es una línea. |

---

## Riesgos identificados

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| **`field_tipo_de_boletin` es un campo preexistente del sitio**, no gestionado por este módulo: puede faltar en algún entorno o cambiar sus valores permitidos. | La validación del paso 5 no encontraría el valor `Condominio` y bloquearía **todos** los boletines del rol. | Si el campo no existe en el bundle `boletin`, la validación se salta y se registra un `watchdog` de nivel WARNING. La comparación es contra el valor literal `'Condominio'`, documentado en `docs/`. |
| **`hook_node_access()` no se invoca para la operación `create`** en Drupal 7. | Un administrador de edificio podría crear un `area` o una `reservation` eligiendo un condominio ajeno en el formulario. | El nodo queda inaccesible para él nada más guardarlo (la regla de `view` sí aplica), así que el error es visible de inmediato y reversible por un administrador. Si aparece en uso real, se extiende `hook_node_validate()` con el mismo patrón del boletín. |
| **Coste por nodo del filtro**: `hook_node_access()` se ejecuta en cada nodo cargado, y el modo `via_unit` (`pagos`, `recibo`, `alicuota_extra`) necesita resolver la vivienda. | Latencia perceptible en listados largos para este rol. | Caché estática por `uid` (condominios asignados) y por `nid` (condominio resuelto), dentro de la misma petición. Solo se paga cuando el usuario tiene el rol. |
| **El `hook_query_alter()` afecta a toda consulta con el tag `node_access`**, no solo a `/admin/content`. | Bloques, buscadores, vistas de otros módulos y **autocompletados de `entityreference`** aparecerían vacíos o recortados para este rol. | Es el comportamiento buscado (aislamiento por condominio), pero queda escrito en `docs/` para que no se diagnostique como fallo. Verificado que ninguna consulta de este módulo lleva ese tag, así que la API queda intacta. |
| **La restricción vive en la capa de acceso a nodos, no en la base de datos.** Cualquier consulta SQL directa la ignora — como hacen hoy todos los recursos de este módulo. | Un futuro endpoint o página de back office que consulte por SQL sin comprobar acceso volvería a exponer contenido de otros condominios. | Documentado como regla de mantenimiento: toda pantalla nueva de back office debe pasar por `myapi_building_admin_*` o por `node_access()`. |
| **Un usuario con el rol que además tenga `administrator`** conserva `bypass node access`. | El filtro no aplica y parece que el spec no funciona. | Documentado en `docs/`, y los criterios de aceptación se prueban con un usuario que tenga **solo** el rol `administrador edificio`. |
| **Borrar un nodo `condominio`** deja referencias huérfanas en `field_condominio_admin`. | El administrador de edificio pierde acceso en silencio, sin mensaje que lo explique. | Caso raro (los condominios no se borran en producción); la asignación se revisa en `/user/N/edit`, donde una referencia rota es visible. |
| **`/admin/content` lo sirve una vista de Views**, no el listado de core, y una vista puede tener marcado *«Disable SQL rewriting»*. | El `hook_query_alter()` no se aplicaría a esa pantalla y el rol vería todo el contenido del sitio en el listado, aunque las URL directas sigan devolviendo 403. | Criterio de aceptación explícito que obliga a comprobar la opción en la vista antes de validar el filtro. Si está marcada, se desmarca en la vista: no se añade código para compensarlo. |
| **Generalizar `_myapi_reservations_ensure_instance()`** toca un helper usado por los updates 7006–7009 ya ejecutados. | Una regresión ahí rompería la instalación limpia de los tipos de reservas. | El nuevo parámetro `$entity_type` va al final y por defecto vale `'node'`: las llamadas existentes no cambian ni de firma ni de comportamiento. |
