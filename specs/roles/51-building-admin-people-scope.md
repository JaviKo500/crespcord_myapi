# 51 — Alcance por condominio para las personas del rol «administrador edificio»

- **Estado:** Implemented
- **Fecha:** 2026-07-31
- **Dependencias:**
  - `49-building-admin-role` (Implemented) — crea el rol, el campo de usuario
    `field_condominio_admin` y `includes/myapi.building_admin.inc` con
    `myapi_building_admin_is()`, `myapi_building_admin_condominium_ids()` y los
    catálogos de tipos y permisos. Este spec **replica** su patrón de dos mitades
    —acceso directo por URL más `hook_query_TAG_alter()`— sobre la entidad
    usuario, **amplía** `myapi_building_admin_permissions()` con
    `access user profiles`, y **reescribe** la sección «The people of a unit —
    not solved by this layer» de `docs/building-admin-role.md`, que hoy indica
    justo lo contrario.
  - `25-notifications-inbox-boletin` (Implemented) —
    `myapi_condominium_member_uids()` y `myapi_unit_member_uids()` en
    `includes/myapi.unit_access.inc`, que resuelven condominios → viviendas →
    uids de propietarios y ocupantes contemplando las tres variantes del campo
    (`field_propietario`, `field_ocupante` heredado y `field_ocupantes`). Este
    spec los **reutiliza sin modificarlos**: son exactamente la consulta que
    necesita el filtro.
  - `32-reservations-content-types-install` (Implemented) — define
    `field_requester` (`entityreference` a `user`, handler `base`, sin
    `target_bundles`) y `field_unit` del bundle `reservation`, los dos campos del
    formulario de reserva que este spec acota; y el patrón idempotente
    `_myapi_reservations_ensure_field()` / `_ensure_instance()` de `myapi.install`.
- **Objetivo:** Extender el filtro por condominio del rol `administrador edificio`
  a la entidad usuario, de modo que en perfiles, listados y autocompletados
  —`field_requester` incluido— solo alcance a los propietarios y ocupantes de las
  viviendas de sus condominios asignados, más a sí mismo.

Tres notas transversales, heredadas del spec 49:

- **Ningún endpoint `api/v1/...` cambia.** El rol es exclusivamente de back
  office, y ninguna consulta de `resources/` ni de `includes/` lleva el tag
  `user_access` (verificado: cero coincidencias), igual que ninguna lleva
  `node_access`.
- **El rol se compara siempre por nombre**, nunca por `rid`. Este spec no añade
  ninguna constante de rol: reutiliza `myapi_building_admin_is()`.
- **La regla aplica solo a este rol.** `administrator`, `backend`, `uid 1` y
  cualquier residente siguen viendo exactamente lo que veían.

---

## Alcance

### Entra en este spec

1. **`access user profiles` en el catálogo del rol.** Se añade a
   `myapi_building_admin_permissions()` y lo concede un nuevo
   `myapi_update_7014()` —el 7013 lo consumió el cambio del formato de texto del
   spec 49— reutilizando `_myapi_building_admin_install()` tal cual. Sigue
   pasando por `myapi_building_admin_filter_available_permissions()`, así que si
   el permiso no existiera en el sitio se descarta en silencio.

2. **Archivo nuevo `includes/myapi.building_admin_user.inc`**, registrado en
   `myapi.info`. `includes/myapi.building_admin.inc` ya va por 1.093 líneas y
   este es un eje distinto —la entidad usuario, no el nodo—, así que el catálogo
   de tipos y el de personas no comparten archivo.

3. **Conjunto de uids visibles.** `myapi_building_admin_visible_uids($account)`:
   los condominios asignados (`myapi_building_admin_condominium_ids()`) pasan por
   `myapi_condominium_member_uids($ids, 'todos')`, y al resultado se le suma
   **siempre** el uid del propio operador. Caché estática por uid, viva durante
   la petición.

4. **Regla de visibilidad, pura.**
   `myapi_building_admin_user_decision($target_uid, $current_uid, array $visible_uids)`
   devuelve `'allow'` o `'deny'`. Uno mismo es `'allow'` siempre, con vivienda o
   sin ella. Todo lo demás que no esté en la lista es `'deny'`, **sin excepción
   para `administrator`, `backend` ni otros administradores de edificio**: quien
   no se pueda adscribir a un condominio asignado, no se ve.

   El filtro **no se aplica** al operador que además tenga el permiso
   `administer users`. Es la excepción simétrica al `bypass node access` de los
   nodos: sin ella, un `administrator` que además tuviera el rol vería todo el
   contenido del sitio pero solo las personas de sus condominios. Ese permiso
   **no** entra en `myapi_building_admin_permissions()` — el rol no lo recibe
   nunca; la excepción existe para quien lo tenga por otro rol.

   Conviene no confundir los dos lados: la regla sin excepciones dice **quién es
   visible**; la excepción de `administer users` dice **quién mira**.

5. **Primera mitad del filtro — la URL directa.** `myapi_menu_alter()`, el primer
   `hook_menu_alter()` del módulo, sustituye el callback de acceso de
   `user/%user` y `user/%user/view` por `myapi_building_admin_user_view_access()`,
   que delega primero en el `user_view_access()` original y solo después aplica
   la regla. `/user/N` de un residente de otro condominio devuelve 403.

6. **Segunda mitad — listados y autocompletados.**
   `myapi_query_user_access_alter()` sobre el tag `user_access`, espejo de
   `myapi_query_node_access_alter()`, con dos guardas en este orden:

   1. `myapi_building_admin_user_filter_is_active()`; si no, `return` inmediato.
   2. La consulta tiene realmente `users` entre sus tablas, con su alias. El tag
      no lo garantiza.

   Superadas las guardas, añade `<alias>.uid IN (visibles)`. **No hay tercera
   guarda de «lista vacía»**: la lista siempre lleva al menos el uid del propio
   operador, así que un operador sin ningún condominio asignado se ve solo a sí
   mismo, que es la regla correcta y no un caso especial.

   Ese tag lo llevan tanto el handler genérico de `entityreference` con
   `target_type = user` —de ahí que `field_requester` pase a ofrecer solo a sus
   residentes— como la tabla base `users` de Views, que lo declara como *access
   query tag*.

7. **Validación del formulario de reserva.** Nueva rama `reservation` en
   `myapi_node_validate()`, paralela a la de `boletin`: para este rol,
   `field_requester` debe estar entre los uids visibles y el condominio de la
   `vivienda` de `field_unit` entre los asignados. Cierra el hueco que el propio
   spec 49 dejó anotado —`hook_node_access()` no cubre `create`— para los dos
   campos que este spec toca. Reutiliza
   `myapi_building_admin_node_condominium()` y
   `myapi_building_admin_access_decision()` para la vivienda: ninguna consulta
   nueva.

8. **Aviso en el informe de estado.** `myapi_requirements('runtime')` en
   `myapi.install`, el primero del módulo: si el rol existe y **no** tiene
   concedido `access user profiles`, emite un `REQUIREMENT_WARNING` en
   `/admin/reports/status` explicando que el rol se ha quedado sin autocompletado
   ni perfiles. Convierte un fallo mudo en uno visible, sin reponer el permiso a
   espaldas de quien lo quitó.

9. **Pruebas unitarias** en un `tests/unit/BuildingAdminUserTest.php` nuevo, solo
   PHPUnit sobre funciones puras, siguiendo el patrón de
   `tests/unit/BuildingAdminTest.php`.

10. **Documentación.** Reescritura de la sección «The people of a unit — not
    solved by this layer» de `docs/building-admin-role.md`, que hoy dice
    literalmente *«Do not grant `access user profiles`»*, más el apartado de las
    dos mitades del filtro de usuarios y la nota de mantenimiento.

### NO entra en este spec

- **Editar usuarios.** Ni `administer users`, ni `/admin/people`, ni ningún
  permiso de administración de cuentas. El rol lee personas; no las crea, ni las
  bloquea, ni les cambia el correo.
- **Restringir campos concretos del perfil.** El operador ve el perfil completo
  de sus residentes: `field_nombre`, `field_apellidos`, `field_cedula`,
  `field_telefono` y el correo. Ocultar alguno es una decisión de negocio
  distinta y tendría su propio spec.
- **Filtrar por condominio las respuestas de `api/v1/...`.** El rol es de back
  office, y ninguna consulta del módulo lleva el tag `user_access`.
- **Código nuevo para el autocompletado de `field_unit`.** Ya está filtrado por
  el `hook_query_node_access_alter()` del spec 49, porque `vivienda` está en
  `myapi_building_admin_readonly_types()` y acotada por condominio. Lo que sí
  entra —punto 7— es la validación del valor **enviado**, que es otra cosa.
- **Reglas propias para usuarios bloqueados (`status = 0`).** `entityreference`
  ya los excluye de sus autocompletados para quien no tiene `administer users`, y
  `user_view_access()` ya deniega su perfil por lo mismo. Este filtro solo
  estrecha, nunca amplía.
- **El autocompletado de core `user/autocomplete`** (el del campo «Escrito por»
  del formulario de nodo). No lleva el tag `user_access` y queda fuera del
  filtro; el rol no tiene `administer nodes`, así que no ve ese campo. Queda
  escrito como limitación conocida, no como agujero abierto.
- **La configuración de Views y del menú del rol.** Es configuración del sitio,
  ya documentada por el spec 49. Este spec entrega el filtro; la vista de
  personas la monta quien administra el sitio.
- **Node access grants** (`hook_node_grants()` / `hook_node_access_records()`) y
  cualquier equivalente para usuarios.
- **Revocar permisos o borrar nada al desinstalar.** Mismo criterio conservador
  del spec 49.
- **Pruebas de integración (`tests/integration/`) y e2e (`tests/e2e/`).** El
  `hook_menu_alter()`, el `hook_query_alter()` y el `hook_requirements()` se
  verifican a mano contra los criterios de aceptación.

---

## Modelo de datos

Este spec **no crea ninguna estructura de datos nueva**: no hay `hook_schema()`,
ni tabla `myapi_*`, ni campo de Field API. Reutiliza íntegro el modelo del spec
49 y las tablas de campo que ya existen.

Lo único que se escribe en base de datos es **una fila en `role_permission`**:
`access user profiles` para el rol `administrador edificio`.

### Cómo se resuelve el conjunto de personas visibles

Cuatro consultas encadenadas, todas ya escritas en
`includes/myapi.unit_access.inc`:

| Paso | Origen | Función |
|------|--------|---------|
| 1. Condominios asignados al operador | `field_data_field_condominio_admin` (`entity_type = 'user'`) | `myapi_building_admin_condominium_ids()` (spec 49, con caché por uid) |
| 2. Nodos de esos condominios | `field_data_field_condominio` (`entity_type = 'node'`) | dentro de `myapi_condominium_member_uids()` |
| 3. Propietarios | `field_data_field_propietario` | dentro de `myapi_unit_member_uids()` |
| 4. Ocupantes | `field_data_field_ocupante` (heredado) **y** `field_data_field_ocupantes` (actual), fusionados | dentro de `myapi_unit_member_uids()` |

El paso 2 no filtra por tipo de nodo, así que su resultado intermedio incluye
también los `boletin` y `gastos` del condominio. No es un error ni hay que
corregirlo: esos nodos no tienen `field_propietario` ni `field_ocupante`, así que
no aportan ningún uid al paso 3. Se anota porque explica por qué el coste real es
algo mayor que «las viviendas del condominio».

Al resultado se le suma **siempre** el uid del propio operador, y ahí termina el
conjunto.

### Estructuras en código

Todo en `includes/myapi.building_admin_user.inc`, con la misma separación
puro / Drupal-facing que `includes/myapi.building_admin.inc`:

```php
// Puro. La guarda de las dos mitades del filtro.
myapi_building_admin_user_filter_applies($has_role, $may_administer_users);

// Puro. La regla entera del spec, con la excepción de uno mismo.
// Devuelve 'allow' o 'deny'. Nunca otra cosa.
myapi_building_admin_user_decision($target_uid, $current_uid, array $visible_uids);

// Drupal-facing. Envoltorio de la guarda: myapi_building_admin_is() + user_access().
myapi_building_admin_user_filter_is_active($account = NULL);

// Drupal-facing. Lista plana de uids enteros, sin duplicados, nunca vacía
// para un operador con condominios (siempre lleva al menos el suyo).
// drupal_static por uid del operador.
myapi_building_admin_visible_uids($account = NULL);

// Drupal-facing. Callback de acceso que instala myapi_menu_alter().
myapi_building_admin_user_view_access($account);

// Drupal-facing. El alter, con las dos guardas.
myapi_building_admin_alter_user_query($query);
```

Y una función pura más para la validación de la reserva:

```php
// Puro. Recibe el uid enviado en field_requester y los visibles;
// devuelve la lista de errores, misma forma que
// myapi_building_admin_bulletin_errors(): [['field' => ..., 'message' => ...]].
myapi_building_admin_reservation_errors($requester_uid, array $visible_uids, $unit_decision);
```

`$unit_decision` es el `'allow'` / `'deny'` que el envoltorio ya obtuvo de
`myapi_building_admin_access_decision()` sobre la vivienda de `field_unit`, o
`NULL` cuando `field_unit` viene vacío. Se pasa resuelto para que la función siga
siendo pura y no necesite cargar el nodo.

### Forma de la consulta alterada

Una sola condición sobre el alias de `users`, sin ningún `JOIN`:

```sql
-- añadido por myapi_building_admin_alter_user_query()
AND users.uid IN (:uids[])
```

Es deliberadamente más simple que el alter de nodos, que necesita un `LEFT JOIN`
por tipo: aquí los uids ya vienen resueltos en PHP, así que la consulta alterada
no crece ni en tablas ni en ramas `OR`.

> **Corregido por SPEC 72.** Ese alias tiene que ser el de la tabla **base** de
> la consulta, no el de cualquier tabla `users` presente en ella. Views añade la
> *access query tag* de la tabla base de **cada relación**, así que el listado de
> contenido de `/admin/content` —tabla base `node`— arrastra la etiqueta
> `user_access` en cuanto tiene una relación al autor; con la guarda original
> esta condición aterrizaba sobre `node.uid` y dejaba el listado vacío para todo
> tipo de contenido. Ver `specs/roles/72-query-base-table-guard.md`.

---

## Plan de implementación

**El orden de los pasos es la parte importante de esta sección.** El paso que
concede `access user profiles` va **después** de las dos mitades del filtro, no
antes: ese permiso es de todo el sitio y, suelto, abre el perfil de todos los
residentes de todos los condominios durante el rato que tarde en llegar el
siguiente commit. Cada paso deja el sitio funcionando, y ninguno deja una ventana
abierta.

**1. Nuevo `includes/myapi.building_admin_user.inc` — lógica sin efectos.**
Las tres funciones puras (`myapi_building_admin_user_filter_applies()`,
`myapi_building_admin_user_decision()`,
`myapi_building_admin_reservation_errors()`) y los cuatro envoltorios
Drupal-facing (`myapi_building_admin_user_filter_is_active()`,
`myapi_building_admin_visible_uids()`,
`myapi_building_admin_user_view_access()`,
`myapi_building_admin_alter_user_query()`). Nadie los llama todavía. Añadir
`files[] = includes/myapi.building_admin_user.inc` a `myapi.info`.

`myapi_building_admin_visible_uids()` llama a
`myapi_building_admin_condominium_ids()` y a `myapi_condominium_member_uids()`,
que viven en otros dos `.inc`; como `files[]` en Drupal 7 solo alimenta el
registro de clases y no autocarga funciones, el `myapi.module` que haga de
pegamento carga los tres includes con `module_load_include()` antes de llamar,
igual que hace hoy `myapi_node_access()`.

**2. `hook_menu_alter()` — la URL directa.**
`myapi_menu_alter(&$items)` en `myapi.module`, el primero del módulo: sustituye
el `access callback` de `user/%user` y de `user/%user/view` por
`myapi_building_admin_user_view_access`, dejando intactos los `access arguments`.

El callback nuevo llama **primero** al `user_view_access()` original y solo
devuelve `TRUE` si aquel lo hacía; después aplica la regla del condominio, con
`myapi_building_admin_user_filter_is_active()` como primera guarda. Así no relaja
nada: solo puede quitar acceso, nunca darlo.

`user/%user/edit` **no se toca**: sin `administer users`, `user_edit_access()` ya
deniega editar la cuenta de otro, y el operador conserva la suya.

Verificación manual del paso: `drush cc all` y comprobar que `/user/N` sigue
comportándose exactamente igual para todos los roles — el rol aún no tiene
`access user profiles`, así que este paso no cambia nada visible todavía. Queda
armado.

**3. `hook_query_TAG_alter()` sobre `user_access` — listados y autocompletados.**
`myapi_query_user_access_alter(QueryAlterableInterface $query)` en
`myapi.module`, pegamento puro que delega en
`myapi_building_admin_alter_user_query()`. Dos guardas antes de tocar nada:

1. `myapi_building_admin_user_filter_is_active()`; si no, `return` inmediato y
   coste cero para todo el resto del sitio.
2. La consulta tiene realmente `users` entre sus tablas. Se recorre
   `$query->getTables()` buscando `table === 'users'`, y se guarda el alias. El
   tag no lo garantiza, igual que `node_access` no garantiza `node`. Sin esta
   guarda, la condición aterrizaría sobre consultas sin esa columna y rompería
   páginas ajenas a este spec con un error SQL.

Superadas, una sola línea: `<alias>.uid IN (myapi_building_admin_visible_uids())`.

**4. `access user profiles` y `myapi_update_7014()`.**
Se añade el permiso a `myapi_building_admin_permissions()` en
`includes/myapi.building_admin.inc` —una línea, junto a `access content`— y se
crea `myapi_update_7014()` que vuelve a llamar a
`_myapi_building_admin_install()` tal cual, sin argumentos nuevos: el helper ya
es idempotente y ya cruza la lista contra los permisos existentes.

Este es el paso que abre la puerta, y llega con el 403 y el filtro de consultas ya
en su sitio.

**5. Validación del formulario de reserva.**
Nueva rama `case 'reservation':` en `myapi_node_validate()`, junto a las de
`area` y `boletin`, delegando en `myapi_building_admin_validate_reservation()`.
El envoltorio: sale en la primera línea si el filtro no está activo para el
usuario; lee `field_requester` y `field_unit` del pseudo-nodo con
`myapi_building_admin_field_target_id()`; resuelve el condominio de la vivienda
con `myapi_building_admin_node_condominium()` y
`myapi_building_admin_access_decision()`; pasa los tres valores a la función pura
y convierte su resultado en `form_set_error()`.

Sigue sin tocar `POST /api/v1/reservations`: `hook_node_validate()` solo se
invoca desde `node_form_validate()`.

**6. `myapi_requirements('runtime')`.**
Nuevo, en `myapi.install`. Carga el rol por nombre con
`user_role_load_by_name()`; si no existe, no dice nada. Si existe y no tiene
`access user profiles` concedido, devuelve un `REQUIREMENT_WARNING` que explica
que el rol se ha quedado sin autocompletado ni perfiles y cómo reponerlo
(`drush role-add-perm`).

Es el único sitio del módulo donde entra un `rid`, y entra **leído del rol que se
cargó por nombre**, nunca escrito a mano: la regla del spec 49 sigue en pie.

**7. Pruebas unitarias — `tests/unit/BuildingAdminUserTest.php`.**
Archivo nuevo, PHPUnit puro sobre `tests/unit/bootstrap.php`, sin Drupal
arrancado. Cubre las tres funciones puras:

- **`myapi_building_admin_user_filter_applies()`**: con el rol y sin
  `administer users` ⇒ el filtro aplica; con el rol y con `administer users` ⇒ no
  aplica; sin el rol ⇒ no aplica, tenga o no el permiso.
- **`myapi_building_admin_user_decision()`**: uid en la lista ⇒ `'allow'`; uid
  fuera ⇒ `'deny'`; **uno mismo ⇒ `'allow'` aunque no esté en la lista**; lista
  vacía y uid distinto ⇒ `'deny'`; uids que llegan como cadena (lectura de base
  de datos) frente a enteros ⇒ misma respuesta, sin falsos `'deny'`; y un caso
  que comprueba que **nunca** devuelve un tercer valor.
- **`myapi_building_admin_reservation_errors()`**: solicitante visible y vivienda
  `'allow'` ⇒ sin errores; solicitante ajeno ⇒ un error sobre `field_requester`;
  vivienda `'deny'` ⇒ un error sobre `field_unit`; ambos mal ⇒ los dos errores;
  `field_requester` vacío (`NULL`) ⇒ error; `field_unit` vacío
  (`$unit_decision === NULL`) ⇒ error.

`hook_menu_alter()`, el `hook_query_alter()`, `hook_requirements()` y la
concesión del permiso **no** se cubren con pruebas automáticas: dependen del
contenedor de Drupal y se verifican a mano contra los criterios de aceptación.

**8. Documentación y despliegue.**
En `docs/building-admin-role.md`:

- Reescribir la sección «The people of a unit — not solved by this layer»
  (línea 261). Hoy dice *«Do not grant `access user profiles`»*; pasa a describir
  las dos mitades del filtro de usuarios, con el mismo formato que la sección
  «The two halves of the filter» que ya existe para nodos.
- Añadir a «The permissions» la fila de `access user profiles`, con la nota de
  que sin él el rol se queda sin autocompletado y el informe de estado avisa.
- Dejar escrito que `administer users` **desactiva** el filtro de personas y por
  eso no se concede nunca a este rol.
- Añadir a «Maintenance rules» que toda pantalla nueva de back office que liste
  personas debe apoyarse en el tag `user_access` o en
  `myapi_building_admin_user_decision()`, y que `user/autocomplete` de core queda
  fuera del filtro.

Cerrar con `drush updb` + `drush cc all` — el `cc all` no es opcional aquí: sin
reconstruir el menú, el `hook_menu_alter()` no entra en vigor.

---

## Criterios de aceptación

> **Escenario de prueba.** Un operador `O` con **solo** el rol
> `administrador edificio` y el condominio `A` asignado. `R1`, propietario de una
> vivienda de `A`. `R2`, ocupante de una vivienda de `B`. `O2`, otro
> administrador de edificio, con `B` asignado y sin vivienda. `U`, un usuario con
> rol `backend`. Todos los criterios se comprueban con `O`, salvo donde se diga
> otra cosa.

**Instalación y permiso**

- [x] Tras `drush updb`, el rol `administrador edificio` tiene concedido
      `access user profiles` en `/admin/people/permissions`.
- [x] Re-ejecutar `myapi_update_7014()` dos veces seguidas no duplica la fila en
      `role_permission` ni altera ningún otro permiso del rol.
- [x] `myapi_update_7013` y anteriores siguen intactos: el `git diff` de este
      spec no toca ni una línea de ninguno de ellos.
- [x] `includes/myapi.building_admin_user.inc` está listado en `myapi.info` con
      `files[]`.
- [x] `administer users` **no** aparece en `myapi_building_admin_permissions()`
      ni concedido al rol en `/admin/people/permissions`.

**Perfiles por URL directa**

- [x] `O` abre `/user/<R1>` y ve el perfil completo: nombre, apellidos, cédula,
      teléfono y correo.
- [x] `O` abre `/user/<R2>` y recibe 403.
- [x] `O` abre `/user/<O2>` y recibe 403.
- [x] `O` abre `/user/<U>` y recibe 403.
- [x] `O` abre su propio `/user/<O>` y lo ve, y el enlace «Mi cuenta» funciona.
- [x] Un operador con el rol y **sin ningún condominio asignado** sigue viendo su
      propio `/user/N` y recibe 403 en el de cualquier otro usuario.
- [x] `O` abre `/user/<R1>/edit` y recibe 403 (leer no es editar), y
      `/user/<O>/edit` sigue funcionando.
- [x] `O` no ve `field_condominio_admin` en su propio formulario de edición, y al
      guardarlo sus condominios asignados siguen intactos (criterio del spec 49,
      revalidado porque este spec toca el mismo formulario).
- [x] Un usuario `administrator`, uno `backend` y un residente de la app abren
      `/user/<R2>` exactamente como antes de este spec.
- [x] Un usuario con el rol `administrador edificio` **y** el permiso
      `administer users` (por cualquier otro rol) abre `/user/<R2>` y lo ve, y su
      autocompletado de `field_requester` ofrece a todo el sitio.
- [x] Ese mismo usuario sigue viendo `/admin/content` filtrado por condominio y
      sigue recibiendo 403 en `/node/N` de otro condominio: `administer users` no
      es `bypass node access` y no toca el filtro de nodos.

**Listados y autocompletados**

- [x] En `node/add/reservation`, el autocompletado de `field_requester` ofrece a
      `R1` y a `O`, y **no** ofrece a `R2`, `O2` ni `U`.
- [x] Ese mismo autocompletado, escrito con el nombre exacto de `R2`, devuelve
      cero resultados.
- [x] El autocompletado de `field_unit` en ese formulario sigue ofreciendo solo
      las viviendas de `A` — sin código nuevo, por el filtro de nodos del spec 49.
- [x] Una vista de Views sobre la tabla base `users`, sin *«Disable SQL
      rewriting»* marcado, lista solo a `R1` y `O` cuando la abre `O`.
- [x] Esa misma vista, abierta por un `administrator`, lista a todos los usuarios
      del sitio.
- [x] Navegando el back office con el rol activo (portada, `/admin/content`,
      formularios de nodo, calendario de reservas) no aparece ningún error SQL ni
      entrada nueva en `/admin/reports/dblog`: el alter ignora las consultas
      etiquetadas `user_access` cuya tabla base no es `users`.

**Formulario de reserva**

- [x] `O` guarda una reserva con `field_requester = R1` y una vivienda de `A`: se
      crea sin error.
- [x] `O` envía el formulario con `field_requester = R2` forzando el valor a mano
      (el autocompletado no lo ofrece): sale error de formulario y **no** se crea
      el nodo.
- [x] `O` envía el formulario con una `field_unit` de `B` forzada a mano: sale
      error de formulario y **no** se crea el nodo.
- [x] Un `administrator` guarda una reserva con cualquier solicitante y cualquier
      vivienda sin ninguna traba nueva.
- [x] `POST /api/v1/reservations` de un residente sigue creando la reserva
      exactamente igual que antes: la validación no se invoca fuera del
      formulario de administración.

**Informe de estado**

- [x] Con el permiso concedido, `/admin/reports/status` no muestra ningún aviso
      nuevo del módulo.
- [x] Tras `drush role-remove-perm "administrador edificio" "access user profiles"`,
      `/admin/reports/status` muestra un aviso de nivel *warning* que nombra el
      rol y el permiso.
- [x] En un sitio donde el rol no existe, `/admin/reports/status` no muestra ese
      aviso.

**No regresión**

- [x] Un residente autenticado en la app recibe exactamente las mismas respuestas
      que antes en todos los endpoints `api/v1/...` — verificado al menos en
      reservas, pagos, recibos y boletines.
- [x] `grep -rn "addTag('user_access')" resources/ includes/` sigue devolviendo
      cero coincidencias.
- [x] Todos los criterios de aceptación del spec 49 marcados como verificados
      siguen cumpliéndose: en particular, `/admin/content` filtrado por
      condominio y el 403 de `/node/N` de otro condominio.

**Pruebas unitarias**

- [x] `vendor/bin/phpunit` pasa en verde, incluido el nuevo
      `tests/unit/BuildingAdminUserTest.php`, y las 217 pruebas existentes siguen
      pasando.
- [x] Existe un caso que falla si `myapi_building_admin_user_decision()` deja de
      devolver `'allow'` para el propio operador.
- [x] Existe un caso que falla si esa función devuelve `'allow'` para un uid que
      no está en la lista y no es el propio operador.
- [x] Existe un caso con uids en cadena frente a enteros que falla si la
      comparación deja de normalizar los tipos.
- [x] Existe un caso que falla si `myapi_building_admin_user_filter_applies()`
      deja de desactivar el filtro ante `administer users`, y otro que falla si lo
      desactiva para quien solo tiene el rol.
- [x] No se añade ningún test a `tests/integration/` ni a `tests/e2e/`.

**Documentación**

- [x] La sección «The people of a unit — not solved by this layer» de
      `docs/building-admin-role.md` ya no existe con ese título ni con la
      instrucción *«Do not grant `access user profiles`»*.
- [x] `docs/building-admin-role.md` describe las dos mitades del filtro de
      usuarios con el mismo formato que las dos de nodos, y nombra el tag
      `user_access`.
- [x] `docs/building-admin-role.md` dice explícitamente que `user/autocomplete`
      de core queda fuera del filtro, y por qué eso no abre nada hoy.
- [x] `docs/building-admin-role.md` dice explícitamente que `administer users`
      desactiva el filtro de personas.
- [x] La tabla de permisos de `docs/building-admin-role.md` incluye
      `access user profiles`.

---

## Decisiones tomadas y descartadas

| Decisión | Alternativa descartada | Por qué |
|----------|------------------------|---------|
| Filtrar la **entidad usuario** | La vista sobre nodos `vivienda` con relación a propietario y ocupante, que es lo que el spec 49 dejó apuntado | La vista resuelve el listado y nada más. `field_requester` es un `entityreference` a **usuario**: consulta la entidad usuario, no nodos, así que ninguna vista lo arregla. Y deja `/user/N` abierto en cuanto alguien conceda el permiso. Filtrar la entidad cubre perfil, listado y autocompletado con una sola regla. |
| Quien no se adscribe a un condominio asignado es invisible, **sin excepción** para `backend` ni `administrator` | Exceptuar los roles de `myapi_building_admin_assigner_roles()`, que siempre serían visibles | La excepción solo aportaría si algún formulario del rol necesitara seleccionar a un `backend`, y hoy `field_requester` significa «el residente que reserva». Una regla sin excepciones se explica en una frase y no se erosiona con el tiempo. Ojo: esta fila es sobre **quién es visible**; la de `administer users`, más abajo, es sobre **quién mira**. No se contradicen. |
| El operador **siempre** se ve a sí mismo | Aplicar la regla también a su propia cuenta | Sin la excepción, un operador sin vivienda pierde «Mi cuenta» y su propio formulario de edición. Y no abre nada: ver el perfil propio no requiere permiso para ningún otro rol de Drupal. |
| El filtro de personas no se aplica al operador que tenga `administer users` | Aplicarlo a cualquiera que tenga el rol, mirando solo eso | Sin la excepción, un usuario con `administrator` y el rol a la vez ve todo el contenido del sitio —`bypass node access` corta antes de `myapi_node_access()`— pero solo las personas de sus condominios. La asimetría se lee como un fallo del módulo y cuesta tiempo diagnosticar. La contrapartida aceptada: conceder `administer users` a un administrador de edificio le abre el padrón entero, y por eso ese permiso está fuera del catálogo del rol y la regla queda escrita en `docs/`. |
| 403 en la URL directa con `hook_menu_alter()` envolviendo `user_view_access()` | Dejar `/user/N` abierta y filtrar solo los listados | Es el error que el spec 49 evitó con `hook_node_access()`: listado filtrado pero `/user/347` legible a mano. El envoltorio llama **primero** al callback original y solo después aplica la regla, así que únicamente puede quitar acceso, nunca darlo. |
| Un solo alter, sobre el tag `user_access` | Alterar además el tag `entityreference`, que el mismo handler añade | Los dos tags viajan en la misma consulta: alterar ambos duplicaría condiciones sobre el mismo `SELECT` y complicaría el depurado sin cubrir ningún caso nuevo. Que `user_access` llegue de verdad al autocompletado se verifica con un criterio de aceptación sobre el formulario real, no con una segunda capa de código. |
| La lista de uids se resuelve en PHP y entra como `uid IN (...)` | Subconsulta SQL con los tres `LEFT JOIN` de propietario y ocupantes | Reutiliza `myapi_condominium_member_uids()` sin tocarlo, deja la consulta alterada en una sola línea sin `JOIN` y cuesta cuatro consultas por petición, cacheadas. La subconsulta sería constante en tamaño pero ilegible, y el tamaño real de un condominio no lo justifica. |
| Archivo nuevo `includes/myapi.building_admin_user.inc` | Añadirlo al `includes/myapi.building_admin.inc` existente | Ese archivo ya va por 1.093 líneas y su eje es el nodo; el de este spec es la entidad usuario. Separarlos mantiene los dos legibles y le da al test nuevo un `require_once` obvio. |
| `hook_requirements('runtime')` que avisa si falta el permiso | (a) No hacer nada; (b) reponer el permiso en cada `drush updb` | (b) rompe el criterio conservador del spec 49: el instalador no pisa lo que un administrador ajustó a mano. (a) deja un fallo mudo — el rol pierde el autocompletado sin ningún síntoma que apunte a la causa, y el primer diagnóstico sería «el filtro está mal». El aviso cuesta veinte líneas y no toca ningún comportamiento. |
| Validar `field_requester` y `field_unit` en `hook_node_validate()` | Dejarlo como riesgo asumido, igual que el spec 49 hizo con el condominio de `area` y `reservation` | El formulario ya no ofrece a nadie ajeno, así que un valor ajeno solo puede llegar por un POST fabricado. Cerrarlo cuesta una función pura y quince líneas, y el modo de fallo contrario es el peor posible: una reserva a nombre de un residente de otro edificio se vuelve **invisible para el propio operador** en cuanto la guarda. |
| El perfil se ve completo, sin filtrar campos | Ocultar `field_cedula` o `field_telefono` con `hook_field_access()` | Son los datos con los que trabaja quien administra un edificio. Decidir que la cédula no se ve es una decisión de negocio distinta, y meterla aquí mezclaría dos reglas en el mismo hook. Si llega, llega en su propio spec. |
| Ninguna regla propia para usuarios bloqueados | Añadir `status = 0` como exclusión explícita | `entityreference` ya los excluye de sus autocompletados para quien no tiene `administer users`, y `user_view_access()` ya deniega su perfil por lo mismo. Duplicarlo crearía una segunda fuente de verdad para una regla que este spec no necesita tocar: el filtro solo estrecha, nunca amplía. |
| `access user profiles` se concede en el **último** paso del plan, después de las dos mitades del filtro | Concederlo primero, que es el orden natural de leer el spec | El permiso es de todo el sitio. Concedido antes del filtro, abre el perfil —nombre, teléfono, cédula, correo— de todos los residentes de todos los condominios durante el rato que tarde en llegar el commit siguiente. |

---

## Riesgos identificados

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| **`access user profiles` es de todo el sitio y el filtro es lo único que lo acota.** Si una de las dos mitades deja de aplicarse —un `drush cc all` que no se ejecuta y el menú no se reconstruye, un módulo nuevo que reordene la caché de menú—, el rol pasa de ver a sus residentes a ver a todos, sin ningún síntoma. | Fuga de datos personales de residentes de otros condominios: nombre, teléfono, cédula y correo. | El `hook_requirements()` **no** cubre este caso: solo mira el permiso, no el filtro. El paso 8 del plan hace obligatorio el `drush cc all`, y hay un criterio de aceptación —`/user/<R2>` devuelve 403— que se comprueba después de cada despliegue. Queda escrito en `docs/` como la verificación que no se salta. |
| **Otro módulo altera `user/%user` con su propio `hook_menu_alter()`.** El orden lo decide el peso del módulo; si el otro corre después y repone `user_view_access`, el 403 desaparece en silencio. | El mismo que el anterior, y con el mismo diagnóstico: `/user/<residente ajeno>` devuelve 200. | Hoy ningún módulo del sitio altera ese elemento (verificado). Se documenta el síntoma exacto para que el diagnóstico sea inmediato, y el criterio de aceptación lo detecta. |
| **`administer users` desactiva el filtro de personas para quien lo tenga.** Es la excepción que restaura la simetría con los nodos —donde `bypass node access` ya hace lo mismo—, pero significa que conceder ese permiso «para una cosa» a un administrador de edificio le abre el padrón entero del sitio. | Un operador con `administer users` ve a todos los residentes de todos los condominios. Los nodos siguen filtrados, porque ese permiso no es `bypass node access`. | La excepción está escrita en `docs/building-admin-role.md`, en la regla de mantenimiento y en la propia tabla de permisos: `administer users` no se concede nunca a este rol, y el catálogo de `myapi_building_admin_permissions()` no lo contiene. Un criterio de aceptación comprueba las dos caras: con el permiso ve a todos, sin él solo a los suyos. |
| **Coste del paso intermedio de `myapi_condominium_member_uids()`.** Esa función busca en `field_data_field_condominio` sin filtrar por tipo de nodo, así que su resultado intermedio incluye todos los `boletin` y `gastos` del condominio, no solo las viviendas. | En un condominio con años de boletines, ese intermedio crece aunque el resultado final no cambie. | La consulta sigue indexada por `field_condominio_target_id` y se paga **una vez por petición**, cacheada y solo para este rol. Si algún día molesta, la corrección es un `INNER JOIN node ON type = 'vivienda'` dentro de esa función, que beneficiaría también a las notificaciones del spec 25. No se hace en este spec: es una función compartida por tres specs implementados y tocarla aquí mezclaría dos cambios. |
| **El `uid IN (...)` crece con el número de residentes.** Un condominio de 800 personas mete 800 enteros en cada consulta etiquetada `user_access` de la petición. | Consultas largas en los autocompletados. | La lista se calcula una vez y se reutiliza en todas las consultas de la petición. En ese orden de magnitud MySQL no se inmuta. La salida escrita, si un sitio llegara a decenas de miles, es la subconsulta SQL descartada en la tabla de decisiones. |
| **`user/autocomplete` de core no lleva el tag `user_access`.** | Cualquier campo que lo use ofrecería todo el padrón del sitio a este rol. | Hoy no hay ninguno a su alcance: el campo «Escrito por» del formulario de nodo exige `administer nodes`, que el rol no tiene. Queda escrito en `docs/` como limitación conocida y como regla de mantenimiento — un campo nuevo que lo use hay que filtrarlo aparte. |
| **Una vista de Views sobre la tabla base `users` con *«Disable SQL rewriting»* marcado.** | Esa pantalla concreta listaría a todos los usuarios del sitio, aunque `/user/N` siga devolviendo 403. | Mismo riesgo, mismo síntoma y misma salida que el spec 49 documentó para `/admin/content`: se desmarca la casilla en la vista. No se añade código para compensarlo. |

---

## Lo que **no** entra en este spec

- **Editar usuarios.** Ni `administer users`, ni `/admin/people`, ni ningún
  permiso de administración de cuentas. Y `administer users` no solo no se
  concede: concederlo **desactiva** el filtro de personas para quien lo tenga.
- **Ocultar campos concretos del perfil.** El operador ve completo el de sus
  residentes, cédula y teléfono incluidos.
- **Filtrar por condominio las respuestas de `api/v1/...`.** Ningún endpoint
  cambia.
- **Código nuevo para el autocompletado de `field_unit`.** Ya lo filtra el
  `hook_query_node_access_alter()` del spec 49.
- **Reglas propias para usuarios bloqueados.**
- **`user/autocomplete` de core**, que no lleva el tag `user_access`.
- **La vista de personas y el menú del rol.** Configuración del sitio, no de este
  módulo.
- **Pruebas de integración y e2e.**

Cada una de ellas, si algún día llega, llega en su propio spec.
