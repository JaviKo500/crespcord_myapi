# 78 — Rol `proveedor` y alcance de lo que ve

- **Estado:** **Draft (borrador — no implementado).** Ninguna línea de código escrita. Las decisiones marcadas como *pendientes* abajo hay que cerrarlas antes de empezar.
- **Fecha:** 2026-08-07
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — crea los cinco bundles, el vocabulario y `includes/myapi.services_common.inc` con `MYAPI_SERVICES_*` y `myapi_services_provider_is_active()`. Este spec es su continuación directa y **no** modifica ninguno de sus campos.
  - `49-building-admin-role` (Implemented) — patrón completo de «rol con alcance»: rol creado por nombre, catálogo único de permisos, concesión conservadora, y las dos mitades del filtro (`myapi_node_access()` + `myapi_query_node_access_alter()` en `myapi.module`). Este spec **replica** el patrón y **modifica** esas dos funciones para que consulten también al rol nuevo.
  - `51-building-admin-people-scope` (Implemented) — `myapi_building_admin_filter_available_permissions()`, que este spec reutiliza para no escribir permisos muertos en `role_permission`.
  - `72-query-base-table-guard` (Implemented) — `myapi_building_admin_query_base_table_alias()`, el guard que impide que un `hook_query_alter()` aterrice sobre una consulta que solo hace `JOIN` con `node`. Obligatorio reutilizarlo.

**Objetivo:** Crear de forma idempotente el rol **`proveedor`** y acotar lo que un usuario con ese rol puede ver de los cinco bundles del marketplace: **su propio proveedor, sus ofertas, sus calificaciones y las solicitudes de sus categorías** — nada más. Sin ningún endpoint `api/v1/...` nuevo.

---

## Nota de borrador: decisiones pendientes de confirmación

El documento está escrito **aplicando la recomendación** de cada punto, para que se lea coherente. Si alguna se cambia, hay que revisar las secciones marcadas.

| # | Pregunta | Recomendación aplicada en este borrador | Qué cambia si se decide lo contrario |
|---|---|---|---|
| 1 | ¿El proveedor entra al back office de Drupal? | **No.** Trabaja solo desde la app. El rol no recibe `access administration pages`, `access content overview`, `view the administration theme` ni `access toolbar`. | Habría que añadir esos permisos, un menú propio y una vista de administración: el spec crece del orden de 2 días. |
| 2 | ¿Puede editar su propia ficha de proveedor? | **No.** La ficha la mantiene el operador. El rol no recibe ningún `edit`. | Habría que conceder `edit own provider content` **y** blindar `field_license_expiry`, `field_rating_avg`, `field_rating_count` y `field_categories` contra edición — en `hook_form_alter()` y además en `hook_node_validate()`, porque el formulario no es el único camino. |
| 3 | ¿Un proveedor caducado qué conserva? | Conserva la lectura de **lo suyo** (su ficha, sus ofertas, sus calificaciones) y **pierde** el listado de solicitudes de sus categorías en las que no haya ofertado. | Si se decide que lo pierde todo, la caducidad pasa a ser un guard único al principio de la decisión de acceso y el modelo se simplifica. |
| 4 | ¿Un usuario puede pertenecer a más de un proveedor? | **Sí**, porque `field_provider_users` lo permite. La resolución usuario → proveedor devuelve **una lista**, no un id. | Si se decide 1:1, la lista degenera en un id y hay que añadir una validación que impida el segundo alta — validación que hoy no existe. |
| 5 | ¿Ve la línea de tiempo (`service_transaction`)? | **Sí**, pero solo la de las solicitudes que ya puede ver. | Si se decide que no, `service_transaction` se deniega siempre para este rol y desaparece un salto de dos tablas del filtro. |

---

## El hecho que decide todo el diseño

**Los endpoints `api/v1/...` de este módulo no pasan por `node_access`.**

Está verificado y escrito en el propio código: `resources/claim.resource.inc:31` («The queries deliberately do NOT carry `->addTag('node_access')`»), y `myapi_query_node_access_alter()` en `myapi.module:885` lo confirma para todo el módulo («No query of this module carries that tag (checked across resources/ and includes/), so the api/v1/... endpoints are untouched»). Además, `node_save()` no comprueba permisos.

De ahí se siguen tres consecuencias que hay que tener presentes al leer el resto:

1. **El rol `proveedor` no autoriza la API.** Cuando llegue el spec de los endpoints, la autorización se escribirá allí, explícita y testeable, igual que hizo reclamos. Este spec no la adelanta ni la sustituye.
2. **Entonces, ¿para qué sirve el rol?** Para dos cosas concretas: (a) es el **marcador** que la capa de API consultará para saber que un token pertenece a un proveedor, y (b) cierra el **back office**, que es una superficie real — un proveedor tiene usuario y contraseña de Drupal y puede entrar por `/user` y pedir `/node/N`.
3. **Y la superficie del back office existe porque `access content` no viene de este rol.** Lo hereda del rol *usuario autenticado*, como cualquier residente. Por eso no basta con «no conceder nada»: sin una denegación explícita, un proveedor podría leer por URL directa la solicitud de cualquier edificio. Esa denegación es la mitad más importante de este spec.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.provider_role.inc`** (nuevo) — catálogos y helpers, con el mismo reparto puro / Drupal que `includes/myapi.building_admin.inc`:
  - Constantes `MYAPI_PROVIDER_ROLE` (`'proveedor'`) y `MYAPI_PROVIDER_USERS_FIELD` (`'field_provider_users'`), guardadas con `if (!defined(...))`.
  - El catálogo de tipos bajo la regla y **cómo** se acota cada uno (el equivalente del mapa de condominios, pero con otros ejes).
  - El catálogo de permisos concedidos.
  - Helpers puros: coincidencia de rol, decisión de acceso por pertenencia, y decisión de visibilidad de una solicitud por categoría.
  - Helpers de Drupal: resolución usuario → nodos `provider`, proveedor → categorías, y el alter de la consulta.
- **`myapi.install`** (modificar) — `_myapi_provider_role_install()` idempotente (rol + permisos), llamada desde `myapi_install()`, y `myapi_update_7026()` (el 7025 lo ocupa el spec 77).
- **`myapi.module`** (modificar) — `myapi_node_access()` y `myapi_query_node_access_alter()` pasan a consultar **los dos** roles. Ninguna función nueva de negocio: solo pegamento, como ya son hoy.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.provider_role.inc`.
- **`tests/unit/ProviderRoleTest.php`** (nuevo) y **`docs/provider-role.md`** (nuevo).
- **`docs/services-install.md`** (modificar) — quitar de «Known gaps» la línea que dice que el rol es el siguiente spec.

**Fuera de alcance (para specs futuros):**

- **Cualquier endpoint `api/v1/...`** y toda la autorización de la API: qué solicitudes devuelve el listado del proveedor, quién puede ofertar, quién abre chat. Es el spec siguiente y es donde vive la regla que de verdad protege los datos.
- **Alta o autoregistro de proveedores.** El operador crea el nodo `provider` y el usuario, y los enlaza con `field_provider_users`.
- **Asignar el rol a usuarios existentes.** Se crea vacío; asignarlo es manual.
- **UI propia** de ningún tipo, ni menú, ni vista de administración.
- **Validación de que un usuario de `field_provider_users` tenga el rol.** Hoy nada lo comprueba; queda anotado en Riesgos.
- **Borrar el rol o sus permisos al desinstalar.** Criterio conservador, igual que el spec 49.
- **Notificaciones, chat, flujo de estados y campos denormalizados** — todo sigue en sus specs.

---

## Modelo

### El rol

| Ajuste | Valor |
|---|---|
| Nombre | `proveedor` (español, minúscula, literal en `MYAPI_PROVIDER_ROLE`) |
| Comparación | **Siempre por nombre.** El `rid` es por entorno y no entra en la lógica en ningún sitio. |
| Creación | `user_role_load_by_name()` y solo se crea si falta — misma idempotencia que `_myapi_building_admin_install()`. |

### Los permisos concedidos

`myapi_provider_role_permissions()`, cruzado contra `module_invoke_all('permission')` con `myapi_building_admin_filter_available_permissions()`.

**La lista está vacía a propósito.** No es un olvido, es el diseño: el rol no necesita ni un solo permiso de Drupal para que la app funcione, porque la API escribe con `node_save()` y lee con `db_select()` sin tag. Conceder algo solo abriría camino al back office.

| Bundle | create | edit own | edit any | delete |
|---|:---:|:---:|:---:|:---:|
| `provider` | ❌ | ❌ | ❌ | ❌ |
| `service_request` | ❌ | ❌ | ❌ | ❌ |
| `service_offer` | ❌ | ❌ | ❌ | ❌ |
| `service_rating` | ❌ | ❌ | ❌ | ❌ |
| `service_transaction` | ❌ | ❌ | ❌ | ❌ |

En particular **no** se concede `create service_offer content`: ese permiso habilita `node/add/service_offer`, y por ahí un proveedor crearía ofertas saltándose la unicidad, el chequeo de habilitación y el estado de la solicitud. La API no lo necesita.

La función existe igualmente, devolviendo una lista vacía, por tres razones: es el punto único donde se añadiría algo el día que haga falta, la instalación es idéntica a la del rol vecino, y hay un test que falla si alguien mete un `delete` ahí dentro.

### Qué ve, y por qué eje se acota

El rol `administrador edificio` se acota **por condominio**, con un mapa que resuelve el condominio de cada tipo. El rol `proveedor` se acota por **dos ejes nuevos y distintos**, y por eso no entra en `myapi_building_admin_condominium_map()`:

- **Pertenencia** — qué nodo `provider` es suyo, resuelto al revés desde `field_provider_users`.
- **Categoría** — qué solicitudes le incumben, cruzando `service_request.field_category` con `provider.field_categories`.

`myapi_provider_role_scope_map()`:

| Bundle | Modo | Cómo se resuelve |
|---|---|---|
| `provider` | `self` | El propio `nid` tiene que estar entre los suyos. |
| `service_offer` | `own` | `field_provider` tiene que estar entre los suyos. |
| `service_rating` | `own` | `field_rating_provider` tiene que estar entre los suyos. |
| `service_request` | `category` | Ver la regla de abajo. |
| `service_transaction` | `via_request` | Se hereda de la solicitud a la que apunta `field_request`. Mismo salto de dos que el modo `via_claim` del spec 49. |

Un tipo que **no** está en este mapa queda **fuera** de la regla: la decisión devuelve `ignore` y decide el resto de Drupal. Es lo que mantiene intacto todo lo demás — un proveedor que además vive en el edificio sigue viendo boletines, su vivienda y sus recibos exactamente igual que antes.

### La regla de `service_request`

Una solicitud es visible para un proveedor cuando se cumple **cualquiera** de estas dos:

1. **Ya ofertó en ella** — su proveedor tiene una `service_offer` que apunta a esa solicitud. Esta rama **no** depende ni del estado ni de la habilitación: lo que ya tocó, lo sigue viendo.
2. **Le incumbe ahora** — todas a la vez: `field_category` de la solicitud está entre las `field_categories` de su proveedor; el estado es `open` u `offered`; y el proveedor está **activo** según `myapi_services_provider_is_active()`.

Las solicitudes `assigned`, `closed` y `cancelled` de la categoría, en las que no ofertó, dejan de ser visibles. No son suyas y no puede hacer nada con ellas.

### La decisión de acceso

Igual que en el spec 49, y por el mismo motivo: **la decisión devuelve `deny` o `ignore`, nunca `allow`**. Permitir aquí cortocircuitaría todas las demás comprobaciones de Drupal —nodos despublicados, hooks de otros módulos— y convertiría un filtro en una escalada de privilegios. Un test debe fallar el día que aparezca una rama que permita.

`deny` se devuelve solo cuando se cumple **todo**:

- la cuenta tiene el rol `proveedor`;
- el tipo del nodo está en `myapi_provider_role_scope_map()`;
- y el nodo no pasa la regla de su modo.

Un usuario **sin ningún proveedor asociado** que tenga el rol no ve **nada** de los cinco bundles. Es el mismo criterio explícito que el spec 49 tomó con «ningún condominio asignado»: la ausencia de asignación deniega, no abre.

### Las dos mitades del filtro

Ambas funciones de `myapi.module` pasan a consultar los dos roles. Un usuario que tenga **los dos** recibe la denegación si **cualquiera** de los dos deniega — la lectura conservadora.

- **`myapi_node_access()`** — cierra la URL directa (`/node/N`). Es la mitad que de verdad importa aquí, por lo explicado arriba sobre `access content`.
- **`myapi_query_node_access_alter()`** — estrecha los listados y los autocompletados. Con las decisiones 1 y 2 aplicadas, el proveedor no abre ningún formulario ni ninguna pantalla de `/admin`, así que esta mitad hoy casi no tiene superficie; se implementa igualmente porque la búsqueda, los bloques y cualquier módulo futuro sí usan ese tag, y porque tener solo una mitad es exactamente el fallo que el spec 49 documentó. **Obligatorio** pasar antes por `myapi_building_admin_query_base_table_alias()` (spec 72): sin ese guard, la consulta se rompe en cuanto alguien tagea algo que no son nodos.

### Coste de la resolución

`hook_node_access()` se invoca una vez por nodo, operación y cuenta, y Drupal cachea el resultado en estático dentro de la petición. Aun así, la rama `category` de una solicitud necesita dos consultas (las categorías del proveedor, y si ofertó). Se resuelven así:

- Los ids de proveedor del usuario y sus categorías se calculan **una vez por petición** y se guardan en estático, igual que hace `myapi_building_admin_condominium_ids()`.
- «¿Ofertó?» se consulta por solicitud, también con estático por nid.

Con esto, ver una solicitud cuesta como mucho una consulta extra, y el listado del back office —que el rol no abre— no dispara ninguna por fila.

---

## Plan de implementación

1. **`includes/myapi.provider_role.inc`** — constantes, `myapi_provider_role_scope_map()`, `myapi_provider_role_permissions()` y los helpers puros: `myapi_provider_role_roles_match()`, `myapi_provider_role_access_decision()` (pertenencia) y `myapi_provider_role_request_visible()` (categoría, estado, habilitación, ya-ofertó). Todo puro va primero, para que los tests puedan escribirse contra ello sin sitio. *Verificación: `php -l` y los tests del paso 6 sobre estas funciones.*

2. **Helpers de Drupal en el mismo fichero** — `myapi_provider_role_is()`, `myapi_provider_role_provider_ids()` (consulta inversa sobre `field_data_field_provider_users` con `entity_type = 'user'`), `myapi_provider_role_category_ids()` y `myapi_provider_role_node_decision()`. Cada uno es una envoltura fina sobre un helper puro. *Verificación: manual contra el sitio, en los criterios de aceptación.*

3. **`myapi.install` — `_myapi_provider_role_install()`.** Rol por nombre, creación solo si falta, y concesión conservadora de `myapi_provider_role_permissions()` filtrada contra las que existen. Con la lista vacía de este borrador, el efecto real es «crea el rol y no concede nada», y aun así se escribe entero: es el punto donde se añadirá algo el día que cambie la decisión 1 o la 2. *Verificación: reejecutable sin duplicar el rol ni escribir filas en `role_permission`.*

4. **`myapi.install` — enganche.** Llamada en `myapi_install()` después de `_myapi_services_install()` (los bundles tienen que existir antes de que sus permisos puedan encontrarse en `module_invoke_all('permission')`), y `myapi_update_7026()` con la misma llamada. *Verificación: `drush en myapi` en sitio limpio y `drush updb` en el existente.*

5. **`myapi.module` — las dos mitades.** Reestructurar `myapi_node_access()` para consultar los dos roles y devolver `NODE_ACCESS_DENY` si cualquiera deniega, y añadir la llamada al alter del proveedor en `myapi_query_node_access_alter()`. Sin lógica nueva en `myapi.module`: sigue siendo pegamento. *Verificación: los criterios de aceptación de acceso, más la no regresión completa del rol `administrador edificio`.*

6. **`tests/unit/ProviderRoleTest.php`.** Como mínimo: el catálogo de permisos y el guard de que nunca aparece un `delete`; el guard de que la decisión nunca es `allow`; el mapa de alcance (los cinco tipos y solo esos); la decisión por pertenencia, incluido el caso «sin proveedor asociado no ve nada»; y la regla de solicitud en sus ocho combinaciones de categoría × estado × habilitación × ya-ofertó. *Verificación: `vendor/bin/phpunit` completo en verde.*

7. **Documentación.** `docs/provider-role.md` y el retoque de `docs/services-install.md`.

8. **Aplicar y verificar.** `drush cc all` y recorrer los criterios de aceptación.

**Nota:** no se toca ningún fichero de `resources/`, ni `hook_schema()`, ni ningún campo del spec 77.

---

## Criterios de aceptación

**Instalación**

- [ ] En un sitio limpio, `drush en myapi` crea el rol `proveedor` (visible en `/admin/people/roles`).
- [ ] En el sitio existente, `drush updb` ejecuta `myapi_update_7026` y lo crea sin tocar nada más.
- [ ] Reejecutar `_myapi_provider_role_install()` no crea un segundo rol con el mismo nombre.
- [ ] `drush pm-uninstall myapi` no borra el rol.
- [ ] En `/admin/people/permissions`, la columna del rol `proveedor` está **completamente vacía**.

**Alcance — lo que ve**

Con un usuario U asociado al proveedor P (categorías: Limpieza), con el rol `proveedor`, y sin `bypass node access`:

- [ ] `/node/N` del nodo `provider` P → 200. De otro proveedor Q → 403.
- [ ] Una `service_offer` de P → 200. Una de Q → 403.
- [ ] Una `service_rating` de P → 200. Una de Q → 403.
- [ ] Una `service_request` de Limpieza en estado `open` → 200.
- [ ] La misma solicitud en estado `closed`, sin oferta de P → 403.
- [ ] Una solicitud de Limpieza en estado `closed` **en la que P ofertó** → 200.
- [ ] Una `service_request` de Mantenimiento en estado `open` → 403.
- [ ] Con la habilitación de P caducada: sus propias ofertas y calificaciones siguen dando 200, y una solicitud de Limpieza `open` en la que no ofertó pasa a 403.
- [ ] Un usuario con el rol pero **sin** ningún proveedor asociado recibe 403 en los cinco bundles.
- [ ] La `service_transaction` de una solicitud visible → 200; la de una no visible → 403.

**No regresión — esto es la mitad del valor del spec**

- [ ] El mismo usuario U, que además es residente, sigue viendo con normalidad boletines, su vivienda, sus recibos, sus pagos y sus reservas. Ningún tipo fuera de los cinco cambia de comportamiento para él.
- [ ] Un usuario con el rol `administrador edificio` ve y filtra exactamente lo mismo que antes: la matriz de aceptación del spec 49 se recorre entera y pasa.
- [ ] Un usuario con **los dos** roles recibe la intersección: se le deniega lo que le denegaría cualquiera de los dos por separado.
- [ ] `administrator` y `backend` no notan ningún cambio.
- [ ] **Todos los endpoints `api/v1/...` devuelven exactamente lo mismo que antes.** No se ha tocado ningún fichero de `resources/`, y ninguna consulta del módulo lleva el tag `node_access`.
- [ ] `/admin/content` no se vacía ni lanza error de SQL para ningún rol (regresión del spec 72).
- [ ] La suite unitaria pasa completa.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nombre del rol | `proveedor`, español y minúscula | `provider`, inglés como el bundle | Coherencia con `administrador edificio`, que es el otro rol que crea este módulo. Se acepta a sabiendas la mezcla con el bundle `provider` en inglés, igual que ya conviven `reclamo` y `claim_transaction`. |
| Permisos concedidos | **Ninguno** | Conceder al menos `create service_offer content` | `create` habilita `node/add/service_offer`, y por ahí un proveedor crearía ofertas saltándose la unicidad, la habilitación y el estado de la solicitud. La API no necesita ese permiso: `node_save()` no comprueba nada. Conceder abriría un camino que no se usa. |
| Eje de alcance | Pertenencia y categoría, en un mapa propio | Meterlo en `myapi_building_admin_condominium_map()` | Ese mapa responde a «¿de qué condominio es este nodo?». Aquí las preguntas son «¿es mío?» y «¿es de mi categoría?». Forzarlas al mismo mapa obligaría a inventar modos que no significan condominio y volvería ilegibles los dos filtros. |
| La denegación | `hook_node_access()`, aunque el rol no tenga permisos | No hacer nada, confiando en que sin permisos no ve nada | `access content` lo hereda del rol *usuario autenticado*, como cualquier residente. Sin denegación explícita, un proveedor con sesión de Drupal leería por URL directa la solicitud de cualquier edificio. |
| La mitad del `query_alter` | Se implementa igualmente | Dejarla para cuando el rol tenga back office | Tener solo una mitad es el fallo exacto que el spec 49 documentó. El tag lo usan búsqueda, bloques y cualquier módulo futuro, no solo `/admin/content`. |
| Resolución usuario → proveedor | Devuelve una **lista** | Devolver un id, asumiendo 1:1 | `field_provider_users` es de cardinalidad ilimitada por el lado del proveedor, y nada impide hoy que un usuario aparezca en dos. Una lista degenera sin coste al caso de uno; un id se rompería en silencio con el segundo. |
| Sin proveedor asociado | No ve **nada** de los cinco bundles | Tratarlo como usuario normal | Mismo criterio explícito que el spec 49 con «ningún condominio asignado». La ausencia de asignación deniega; abrir sería lo peligroso. |
| Solicitudes en las que ya ofertó | Visibles siempre, sin importar estado ni habilitación | Aplicarles la misma regla de categoría | Un proveedor tiene que poder consultar el histórico de aquello en lo que participó, incluso caducado. Es también lo que necesitará la pantalla «mis ofertas» de la app. |
| Guard de tabla base | Reutilizar `myapi_building_admin_query_base_table_alias()` | Escribir uno propio en el fichero nuevo | Es exactamente la misma decisión y duplicarla violaría la regla de «una sola lógica compartida» de CLAUDE.md. El precio es un nombre con prefijo de la otra feature; se anota como deuda, no se renombra ahora. |
| Alcance del spec | Solo rol y filtro de back office | Incluir también la autorización de los endpoints | Los endpoints no existen todavía y no pasan por `node_access`. Mezclarlos daría un spec que hace dos cosas distintas con dos mecanismos distintos. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El rol da una falsa sensación de seguridad.** Alguien puede leer «el proveedor solo ve sus solicitudes» y suponer que eso protege también la API. No la protege: ninguna consulta de `resources/` lleva el tag. | Escrito arriba, en Decisiones y en `docs/provider-role.md`, tan explícito como se puede. El spec de los endpoints tendrá que escribir su propia autorización y sus propias pruebas. |
| **Nada garantiza que un usuario de `field_provider_users` tenga el rol.** El operador puede enlazar una cuenta y olvidarse de asignarlo: el usuario no vería nada del marketplace, y el síntoma —una app vacía— no apunta a la causa. | Fuera de alcance implementarlo. Se propone un `hook_requirements()` de aviso, en la línea del que ya existe para el permiso del rol vecino, como primer punto del spec siguiente. |
| **Un usuario en dos proveedores** ve la unión de ambos: las solicitudes de la unión de sus categorías, y las ofertas de los dos. Puede ser deseable o puede ser un error de datos. | El modelo lo soporta y el borrador lo declara. Si se decide 1:1 (decisión 4), hace falta además una validación que impida el segundo enlace. |
| **Coste de la rama `category`** en `hook_node_access()`: cruzar categorías y preguntar «¿ofertó?» por cada nodo. En un listado largo sería una consulta por fila. | Estático por petición para proveedor y categorías, estático por nid para «¿ofertó?». Y con las decisiones 1 y 2 aplicadas, este rol no abre ningún listado del back office. |
| **La mezcla de dos roles en `myapi_node_access()`** hace crecer una función que hoy es corta y clara, y una reestructuración descuidada podría alterar el comportamiento del rol `administrador edificio`. | La reestructuración es aditiva y la matriz completa del spec 49 se recorre entera en los criterios de aceptación de este. |
| **Deuda de nombre**: `myapi_building_admin_query_base_table_alias()` pasa a usarse desde una feature que no es el rol de edificio. | Aceptada a cambio de no duplicar. Si un tercer consumidor aparece, ese será el momento de moverla a un include compartido. |
