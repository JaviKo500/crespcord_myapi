# SPEC 125 — Listado de solicitudes de servicio en el panel de administración

> **Estado:** Approved · **Depende de:** SPEC 47 (patrón de página back-office sin AJAX, `myapi_calendar_condominium_scope()` / `_options()` / `_positive_int()` / `_effective_condominium()` / `_filter_form_after_build()`), SPEC 49 (rol `administrador edificio`, `myapi_building_admin_condominium_map()`, catálogos `editable`/`readonly`/`visible_types`, alters de `node_access`), SPEC 56 (listado de reclamos: molde exacto de página, filtros GET y consulta paginada), SPEC 77 (bundle `service_request`, `myapi_services_request_statuses()`, `myapi_services_node_types()`), SPEC 78 (rol `proveedor` y el juego de dominios exentos entre los dos alters), SPEC 87 (`field_assigned_provider` en solicitudes `direct`), SPEC 94 (línea de tiempo de transacciones en `node/%nid/edit`) · **Fecha:** 2026-09-09
>
> **Objetivo:** Añadir una página de back-office en `admin/content/service-requests` — visible para `administrator`, `backend` y `administrador edificio` — que lista las solicitudes de servicio con filtros por condominio, estado, categoría y rango de fecha de creación, paginación y enlace al nodo, incorporando `service_request` al dominio de **solo lectura** del rol `administrador edificio` para que el acotado por condominio lo resuelva el `node_access` existente.

---

## Alcance

**Dentro:**

- **`includes/myapi.building_admin.inc`** (modificar):
  - Constante nueva **`MYAPI_BUILDING_ADMIN_REQUEST_TYPE`** = `'service_request'`, guardada con `if (!defined(...))`, mismo criterio que `MYAPI_BUILDING_ADMIN_CLAIM_TYPE` y `..._TRANSACTION_TYPE`.
  - **`myapi_building_admin_readonly_types()`** gana el bundle, condicionado a `node_type_load()` igual que `editable_types()` hace con `reclamo` — un sitio sin el módulo de servicios instalado no se entera. La función gana el parámetro `$include_request_type = NULL` para que el test unitario pueda forzarlo sin base de datos, calcado de `$include_claim_type`.
  - **`myapi_building_admin_visible_types()`** pasa el parámetro nuevo hacia abajo. Sigue siendo `editable + readonly`; **`myapi_building_admin_permissions()` no se toca y no recibe nada nuevo** — es lo que hace que esto sea "solo ver" y que **no haga falta ningún `myapi_update_70XX()`**.
  - **`myapi_building_admin_condominium_map()`** gana `MYAPI_BUILDING_ADMIN_REQUEST_TYPE => ['mode' => 'direct', 'field' => 'field_condominium']`. Modo `direct`, como `area` y `reservation`: la solicitud lleva su condominio encima.
- **`myapi.module`** (modificar):
  - **`myapi_query_node_access_alter()`** — la lista de exentos del alter del building admin pasa de `myapi_services_node_types()` a **`array_values(array_diff(myapi_services_node_types(), myapi_building_admin_visible_types()))`**. Escrito como resta y no con el bundle a mano: la regla real es "el dominio del otro rol, menos lo que yo ya reclamo", y el día que otro bundle de servicios entre en el catálogo del building admin esta línea ya está bien. La llamada al alter del proveedor **no cambia** — recibe `myapi_building_admin_visible_types()`, que ahora incluye `service_request`, y eso es exactamente lo que evita que un `backend` que además sea `proveedor` vea el listado recortado.
  - `hook_menu()`: entrada **`admin/content/service-requests`**, `MENU_NORMAL_ITEM` bajo Contenido, `file` apuntando al `.inc` nuevo, mismo patrón que `admin/content/claims`.
  - **`myapi_service_requests_admin_roles()`** — única fuente de los roles autorizados: `administrator`, `backend`, `administrador edificio` (vía `MYAPI_BUILDING_ADMIN_ROLE`).
  - **`myapi_service_requests_admin_access()`** — access callback por nombre de rol, `uid 1` siempre dentro. Un `administrador edificio` sin condominios asignados entra y ve el listado vacío; eso lo decide la query, no el callback.
- **`includes/myapi.service_requests_admin.inc`** (nuevo) — la página entera: `myapi_service_requests_list_page()`, `myapi_service_requests_list_filter_form()`, los helpers de etiqueta de fila, el de opciones de categoría y **la consulta `myapi_service_requests_list_rows()`**.

  > La consulta va **aquí y no en `includes/myapi.service_request_query.inc`** a propósito: el `@file` de ese archivo declara, en mayúsculas y tres veces, que **ninguna** de sus consultas lleva `->addTag('node_access')`, porque el tag rompería el listado de un residente que además sea `proveedor`. Ésta lleva el tag obligatoriamente. Meterlas en el mismo archivo convierte una regla verificable en una excepción que hay que leer función por función.

- **`css/myapi.claims.css`** (modificar) — el selector de la fila de filtros pasa a listar también `.myapi-service-requests-filters`. Una línea; no se crea CSS nuevo.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.service_requests_admin.inc`.
- **`tests/unit/BuildingAdminTest.php`** (modificar) — casos para `readonly_types()` / `visible_types()` con el bundle forzado dentro y fuera, para que `permissions()` **siga sin** conceder `create`/`edit` sobre `service_request` (aserción negativa explícita), y para la entrada nueva del mapa.
- **`tests/unit/ServiceRequestsAdminPageTest.php`** (nuevo) — helpers puros: validación de los parámetros GET, etiquetas de estado / solicitante / proveedor adjudicado / fechas, y armado de filas. Calcado de `ClaimsAdminPageTest.php` + `ClaimListFilterTest.php`.
- **`docs/service-requests-list.md`** (nuevo) — ruta, control de acceso, tabla de filtros, columnas, paginación, el movimiento de catálogos de roles y cómo verificar el acotado por condominio.
- `drush cc all` al final. **`drush updb` no hace falta**: no hay schema nuevo, ni campo nuevo, ni permiso nuevo.

**Fuera de alcance:**

- **Búsqueda por texto libre** (título o nombre del solicitante).
- **Filtro por proveedor adjudicado** — descartado en la definición; la columna se muestra, no se filtra.
- **Filtro por fecha deseada** — se muestra la columna, el rango filtra solo `n.created`.
- **Ordenación por columnas, exportación CSV y acciones masivas.**
- **Página de detalle propia.** El enlace va al nodo (`node/%nid/edit` o `node/%nid` según permiso), nunca a una pantalla de este módulo.
- **Cualquier endpoint `api/v1/...`.** No se toca ni un archivo de `resources/`.
- **Permisos de creación o edición de `service_request` para `administrador edificio`.** Explícitamente fuera: es la diferencia entre esta spec y lo que hizo la SPEC 56 con `reclamo`.
- **Los otros cuatro bundles de servicios** (`provider`, `service_offer`, `service_rating`, `service_transaction`) siguen íntegros en el dominio del rol `proveedor`; ninguno entra en los catálogos del building admin.
- **AJAX y Views.** Filtros por GET y tabla a mano, precedente SPEC 47/56.

---

## Modelo de datos

**No se crea ninguna tabla SQL, ningún campo y ningún permiso.** Todos los campos que la página lee existen desde las SPEC 77/86/87. Lo que se define aquí es una consulta nueva de solo lectura y dos entradas en catálogos existentes.

### Constante nueva

```php
// includes/myapi.building_admin.inc
// Machine name of the service-request bundle (SPEC 77). Same guard criterion
// as MYAPI_BUILDING_ADMIN_CLAIM_TYPE: isolated here and always crossed against
// node_type_load() before being listed, so a site without the services bundles
// is unaffected.
if (!defined('MYAPI_BUILDING_ADMIN_REQUEST_TYPE')) {
  define('MYAPI_BUILDING_ADMIN_REQUEST_TYPE', 'service_request');
}
```

### Entrada nueva en `myapi_building_admin_condominium_map()`

```php
MYAPI_BUILDING_ADMIN_REQUEST_TYPE => ['mode' => 'direct', 'field' => 'field_condominium'],
```

Modo `direct`, como `area` y `reservation`: la solicitud lleva su condominio encima y no hay salto intermedio que resolver. El campo es **`required => 1` en el bundle desde que se creó** (`myapi.install:2731`, SPEC 77), y el comentario de ese bloque anticipa literalmente esta spec — *"the field is created now so that spec is one line and not a schema change"*. No hay solicitudes antiguas sin condominio que pudieran volverse invisibles.

### Catálogos del rol — antes y después

| Función | Antes | Después |
|---|---|---|
| `myapi_building_admin_editable_types()` | `boletin`, `reservation`, `area`, `reclamo`*, `claim_transaction`* | **sin cambios** |
| `myapi_building_admin_readonly_types()` | `condominio`, `vivienda` | + `service_request`* |
| `myapi_building_admin_visible_types()` | unión de las dos | + `service_request`* |
| `myapi_building_admin_permissions()` | derivada de `editable_types()` | **sin cambios — ni `create` ni `edit any` sobre `service_request`** |

\* condicionado a `node_type_load()`.

### Las dos listas de exentos, después del cambio

```php
// myapi.module — myapi_query_node_access_alter()
myapi_building_admin_alter_node_query($query, array_values(array_diff(myapi_services_node_types(), myapi_building_admin_visible_types())));
myapi_provider_role_alter_node_query($query, myapi_building_admin_visible_types());  // sin tocar
```

| Rol de la cuenta | `service_request` en un listado con tag `node_access` |
|---|---|
| `administrator` | todo (tiene `bypass node access`, ni llega a los alters) |
| `backend` sin otros roles | todo (guarda 1 de ambos alters: no tiene ninguno de los dos roles) |
| `administrador edificio` | solo las de sus condominios asignados |
| `proveedor` | **todas** — deja de estar acotado en listados; ver Riesgos |
| `backend` + `proveedor` | todas en el listado; **403 al abrir el nodo** — ver Riesgos |

### Filas de `myapi_service_requests_list_rows()`

```
nid, title, created,
condominium_id, condominium_title,
status,
category_id, category_name,
requester_uid, requester_name,
desired_start,
assigned_provider_id, assigned_provider_name, assigned_provider_raw
```

Una sola consulta sobre `node` (`n.type = 'service_request'`, `n.status = 1`), con `->addTag('node_access')`, `->orderBy('n.nid', 'DESC')` y `->extend('PagerDefault')->limit(20)` — mismos números que `myapi_claims_list_rows()`.

**Todos los joins son `LEFT`, sin excepción**, y ahí se separa de `myapi_service_request_base_query()`, que hace `INNER` sobre categoría y solicitante. El criterio es distinto porque el lector es distinto: la app de un residente no debe listar una solicitud rota, pero el operador de back-office **es exactamente quien tiene que verla para arreglarla**. Una solicitud sin categoría, sin solicitante o con el condominio apuntando a un nodo borrado aparece en la tabla con `—` en esa columna, nunca desaparece.

| Alias | Tabla | Aporta |
|---|---|---|
| `fco` / `nc` | `field_data_field_condominium` → `node` (`condominio`, `status = 1`) | `condominium_id`, `condominium_title` |
| `frs` | `field_data_field_request_status` | `status` |
| `fcat` / `td` | `field_data_field_category` → `taxonomy_term_data` | `category_id`, `category_name` |
| `fr` / `u` | `field_data_field_requester` → `users` | `requester_uid`, `requester_name` |
| `fds` | `field_data_field_desired_start` | `desired_start` |
| `fap` / `np` | `field_data_field_assigned_provider` → `node` (`provider`, `status = 1`) | `assigned_provider_id`, `assigned_provider_name` |

`assigned_provider_raw` es el `target_id` crudo de `fap`, sin resolver. No es redundante: distingue "sin adjudicar" (`—`) de "adjudicada a un proveedor que fue despublicado o borrado" (`Proveedor eliminado (#id)`), el mismo patrón de tres ramas que `myapi_claims_requester_label()`. `field_assigned_offer` **no se lee**: una solicitud `direct` tiene proveedor y no tiene oferta (SPEC 87), así que el proveedor adjudicado se lee siempre de su propio campo y nunca a través de la oferta.

Ningún join puede multiplicar filas: los seis campos tienen cardinalidad 1, y `node.nid`, `users.uid` y `taxonomy_term_data.tid` son claves primarias.

### Columnas de la tabla

| # | Columna | Origen | Cuando falta |
|---|---|---|---|
| 1 | ID | `nid` | — |
| 2 | Título | `title`, enlazado (ver abajo) | título vacío → el `nid` como texto del enlace |
| 3 | Condominio | `condominium_title` | `—` |
| 4 | Estado | `status` → etiqueta de `myapi_services_request_statuses()` | `—` |
| 5 | Solicitante | `requester_name` | `Sin solicitante` / `Usuario eliminado (#uid)` |
| 6 | Fecha de creación | `created`, `format_date(..., 'd/m/Y H:i')` | — |
| 7 | Fecha deseada | `desired_start`, `d/m/Y H:i` | `—` |
| 8 | Categoría | `category_name` | `—` |
| 9 | Proveedor adjudicado | `assigned_provider_name` | `—` / `Proveedor eliminado (#id)` |

El enlace de la columna 2 es **condicional**: `node_access('update', $node)` decide entre `node/%nid/edit` y `node/%nid`. Se evalúa con el nodo ya cargado una sola vez por fila; el detalle de si conviene resolverlo con `node_load_multiple()` sobre las 20 filas de la página se cierra en el plan de implementación.

### Parámetros GET del filtro

| Parámetro | Validación | Por defecto |
|---|---|---|
| `condominium` | entero positivo; si es `administrador edificio`, se ignora cuando no está entre sus asignados | sin filtro |
| `status` | una de las seis claves de `myapi_services_request_statuses()` (`open`, `direct`, `offered`, `assigned`, `closed`, `cancelled`); cualquier otra cosa → sin filtro | sin filtro |
| `category` | entero positivo que exista como término del vocabulario `service_category`; si no, se ignora | sin filtro |
| `date_from` | fecha `Y-m-d` válida → `strtotime($d . ' 00:00:00')`, comparada `n.created >=` | sin filtro |
| `date_to` | fecha `Y-m-d` válida → `strtotime($d . ' 23:59:59')`, comparada `n.created <=` | sin filtro |
| `page` | lo maneja `pager_default_initialize()` | `0` |

Basura en cualquier parámetro cae al valor por defecto **sin error ni mensaje**, mismo criterio que el calendario (SPEC 47) y el listado de reclamos (SPEC 56). Los dos extremos son independientes: `date_from` solo es un rango medio abierto, y `date_to` solo también.

Las dos fechas se validan con `myapi_reservation_valid_date()` y la conversión a timestamp se hace en este archivo con el criterio `00:00:00` / `23:59:59` de `myapi_service_request_parse_created_range()`, **sin llamarla**: vive en `resources/service_request.resource.inc` y la Regla 5 de CLAUDE.md deja esa puerta cerrada. `myapi_reservation_valid_date()` sí se carga con `module_load_include()`, exactamente como ya hace `myapi_claims_list_filters()`.

---

## Plan de implementación

1. **`tests/unit/BuildingAdminTest.php` (modificar) — casos nuevos, en rojo, antes de tocar nada.**
   `myapi_building_admin_readonly_types()` incluye `service_request` cuando `$include_request_type` fuerza `TRUE` y no cuando fuerza `FALSE`; `myapi_building_admin_visible_types()` lo propaga; **`myapi_building_admin_permissions()` NO contiene `create service_request content` ni `edit any service_request content` en ninguna combinación de parámetros** (aserción negativa explícita — es la garantía de "solo ver", y es la que se rompería sola el día que alguien mueva el bundle de catálogo); `myapi_building_admin_condominium_map()` trae la entrada `direct`/`field_condominium`; y el test que ya pinta `editable` y `readonly` como listas disjuntas sigue verde.
   *Verificación: `vendor/bin/phpunit` en rojo, en esos casos y solo en esos.*

2. **`includes/myapi.building_admin.inc` — constante, catálogos y mapa.**
   `MYAPI_BUILDING_ADMIN_REQUEST_TYPE` guardada; `readonly_types($include_request_type = NULL)` con el `node_type_load()` cuando llega `NULL`; `visible_types()` propaga el parámetro nuevo manteniendo el orden de los existentes; la entrada del mapa. `permissions()` no se toca.
   *Verificación: `php -l`; `vendor/bin/phpunit` **en verde**.*

3. **`myapi.module` — la lista de exentos del alter del building admin.**
   `array_values(array_diff(myapi_services_node_types(), myapi_building_admin_visible_types()))`, con el comentario que explica por qué se escribe como resta y no con el bundle a mano. La llamada al alter del proveedor no se toca. Se actualiza el docblock de `myapi_query_node_access_alter()`, que hoy afirma que cada alter recibe "el dominio completo del otro rol" — ya no es cierto, los dominios se solapan en un bundle.
   *Verificación: `php -l`. Sin test unitario: `myapi.module` está fuera del scope de cobertura por decisión de la SPEC 123, y la función depende de `QueryAlterableInterface`. Se comprueba en la matriz manual del paso 10.*

4. **`tests/unit/ServiceRequestsAdminPageTest.php` (nuevo) — en rojo.**
   Solo helpers puros, sin base de datos, bajo los stubs de `tests/unit/bootstrap.php`: validación de los cinco parámetros GET (válido, basura, vacío, fecha imposible tipo `2026-02-30`, entero negativo), las tres ramas de la etiqueta de solicitante y las tres de la de proveedor adjudicado, el fallback `—` de cada columna opcional, y el armado de una fila completa. Calcado de `ClaimsAdminPageTest.php` + `ClaimListFilterTest.php`.
   *Verificación: `vendor/bin/phpunit` en rojo por función inexistente.*

5. **`includes/myapi.service_requests_admin.inc` (nuevo) — helpers puros primero.**
   `@file` explicando por qué la página vive en `includes/` y no en `resources/`, y por qué la consulta no va en `includes/myapi.service_request_query.inc`. Dentro: lectura y validación de filtros, opciones de estado (`myapi_services_request_statuses()`, sin duplicar el catálogo), y las funciones de etiqueta y de armado de filas.
   *Verificación: `php -l`; `vendor/bin/phpunit` **en verde**.*

6. **Mismo archivo — `myapi_service_requests_list_rows()`.**
   La consulta de la sección de modelo de datos: seis `leftJoin`, `->addTag('node_access')`, los cinco filtros opcionales, `orderBy('n.nid', 'DESC')` y `->extend('PagerDefault')->limit(20)`. Docblock explicando por qué el tag basta y no se repite a mano el filtro de condominio, y por qué aquí todo es `LEFT` cuando la consulta de la API hace `INNER`.
   *Verificación: `php -l`; `vendor/bin/phpstan analyse` sin errores nuevos.*

7. **Mismo archivo — página, formulario de filtros y opciones de categoría.**
   `myapi_service_requests_list_page()` reutiliza `myapi_calendar_condominium_scope()` / `_options()` / `_positive_int()` / `_effective_condominium()` vía `module_load_include()`, igual que `myapi_claims_list_page()` — sin copiar una línea de esa lógica. `myapi_service_requests_list_filter_form()` con `#method = 'get'`, `#token = FALSE`, el `#after_build` del calendario, el `hidden q` para `clean_url = 0`, los dos `<input type="date">` a mano y los tres selectores. Las opciones de categoría salen de `taxonomy_get_tree()` sobre el vocabulario `service_category` — **helper propio de este archivo**, porque `myapi_service_category_provider_counts()` vive en `resources/` y la Regla 5 de CLAUDE.md la deja fuera de alcance. Aquí se cierra también cómo se resuelve el enlace condicional de la columna 2: un `node_load_multiple()` de los 20 nids de la página y `node_access('update', $node)` por fila, contra una consulta por fila.
   *Verificación: `php -l`; la página renderiza con datos de prueba, los filtros recargan por GET y el paginador conserva la query string.*

8. **`myapi.module` — ruta y acceso; `myapi.info`; `css/myapi.claims.css`.**
   Entrada `admin/content/service-requests` (`MENU_NORMAL_ITEM`, `file` hacia el `.inc` del paso 5), `myapi_service_requests_admin_roles()` y `myapi_service_requests_admin_access()`. `files[] = includes/myapi.service_requests_admin.inc` en el `.info`. El selector de la fila de filtros del CSS pasa a listar también `.myapi-service-requests-filters`.
   *Verificación: `php -l`; tras `drush cc all` aparece el enlace en el sidebar bajo Contenido; un autenticado sin ninguno de los tres roles recibe 403.*

9. **`docs/service-requests-list.md` (nuevo).**
   Ruta, control de acceso, tabla de filtros, columnas, paginación, el movimiento de catálogos de roles con su tabla de "quién ve qué", el enlace condicional y la anomalía `backend` + `proveedor`.
   *Verificación: lectura contra la implementación.*

10. **Matriz manual de verificación, en un sitio real.**
    Cinco cuentas: `administrator`, `backend`, `administrador edificio` con dos condominios asignados, `administrador edificio` sin ninguno, y un `proveedor`. Se comprueba: quién entra a la ruta, cuántas filas ve cada uno, que el selector de condominio del building admin solo ofrece los suyos, que `?condominium=` con un condominio ajeno no amplía nada, que el enlace lleva a `/edit` o al nodo según el permiso, y — el que importa por el cambio del paso 3 — que **`/admin/content` del building admin ahora también lista las solicitudes de sus condominios** y ninguna otra.
    *Verificación: la matriz, anotada en el commit.*

11. **Cierre.** `drush cc all`. `drush updb` no se ejecuta: esta spec no añade schema, campo ni permiso.
    *Verificación: `vendor/bin/phpunit` verde con el gate de cobertura satisfecho — el archivo nuevo lo ejercita `ServiceRequestsAdminPageTest`, que es justo el fallo que ese gate existe para atrapar.*

---

## Criterios de aceptación

**Ruta y acceso**

- [ ] `admin/content/service-requests` responde 200 para `administrator`, para `backend` y para `administrador edificio`, y 403 para un autenticado sin ninguno de los tres roles.
- [ ] El enlace aparece en el sidebar bajo Contenido para los tres roles.
- [ ] Un `administrador edificio` sin condominios asignados entra (200) y ve la tabla vacía con el mensaje de "sin resultados", no un 403 ni un error de PHP.
- [ ] `uid 1` entra siempre.

**Acotado por condominio**

- [ ] Un `administrador edificio` con dos condominios asignados ve exactamente las solicitudes de esos dos, y ninguna otra, sin filtro aplicado.
- [ ] `?condominium=<nid de un condominio ajeno>` no amplía el listado: el selector queda en "- Todos -" y las filas siguen siendo las de sus condominios.
- [ ] `backend` y `administrator` ven las solicitudes de todos los condominios.
- [ ] Una solicitud cuyo `field_condominium` apunta a un nodo despublicado o borrado aparece en el listado de `backend` con `—` en la columna Condominio, y **no** aparece en el de ningún `administrador edificio`.

**Catálogos de rol — la garantía de "solo ver"**

- [ ] Tras el cambio, `myapi_building_admin_permissions()` sigue sin devolver `create service_request content` ni `edit any service_request content`, y hay un test unitario que lo afirma.
- [ ] No se ejecuta ningún `hook_update_N` nuevo: `drush updb` no reporta actualizaciones pendientes de este módulo.
- [ ] Un `administrador edificio` que abra `node/<nid de una solicitud suya>/edit` recibe **403**.
- [ ] Ese mismo usuario abre `node/<nid>` y ve el nodo.
- [ ] `/admin/content` de un `administrador edificio` lista las solicitudes de sus condominios y ninguna ajena.

**Filtros**

- [ ] Los cinco filtros (condominio, estado, categoría, fecha desde, fecha hasta) se combinan con AND y sobreviven al paginador: pasar a la página 2 conserva la query string completa.
- [ ] `?status=<clave inválida>`, `?category=abc`, `?category=<tid inexistente>`, `?condominium=-1` y `?date_from=2026-02-30` se ignoran silenciosamente y devuelven el listado sin ese filtro — ni error, ni mensaje, ni lista vacía.
- [ ] `?date_from=2026-09-01` sin `date_to` devuelve todo lo creado desde el 1 de septiembre a las 00:00:00 inclusive; `?date_to=2026-09-01` sin `date_from` devuelve todo lo creado hasta el 1 de septiembre a las 23:59:59 inclusive.
- [ ] El selector de condominio de un `administrador edificio` ofrece solo sus condominios asignados; el de `backend` los ofrece todos.
- [ ] El selector de estado ofrece las seis claves de `myapi_services_request_statuses()` con sus etiquetas en español, sin que este archivo declare ninguna.

**Tabla**

- [ ] Las nueve columnas aparecen en este orden: ID, Título, Condominio, Estado, Solicitante, Fecha de creación, Fecha deseada, Categoría, Proveedor adjudicado.
- [ ] El título enlaza a `node/%nid/edit` para quien puede editar el nodo, y a `node/%nid` para quien no.
- [ ] Una solicitud sin categoría, sin solicitante, sin fecha deseada o sin proveedor adjudicado **aparece igual** en la tabla, con `—` en esa columna.
- [ ] Una solicitud adjudicada a un proveedor despublicado muestra `Proveedor eliminado (#id)`, no `—`.
- [ ] Una solicitud `direct` (con proveedor y sin oferta) muestra su proveedor en la columna 9.
- [ ] Las dos fechas se pintan con `d/m/Y H:i`.
- [ ] El orden es `nid DESC` y la paginación es de 20 filas, con paginador real de Drupal.

**Aislamiento**

- [ ] Ningún archivo de `resources/` cambia, y la respuesta de `GET /api/v1/service-requests` es byte por byte la que era antes de esta spec, para un residente y para un proveedor.
- [ ] `myapi_service_request_base_query()` no gana ni pierde un solo join ni una sola condición.
- [ ] Ninguna consulta nueva se añade a `includes/myapi.service_request_query.inc`.
- [ ] No hay JSON en ninguna parte del código nuevo: la página devuelve un render array.

**Calidad**

- [ ] `vendor/bin/phpunit` en verde con el gate de cobertura satisfecho para el archivo nuevo.
- [ ] `vendor/bin/phpstan analyse` sin errores nuevos.
- [ ] `php -l` limpio bajo PHP 7.4 en los cuatro archivos tocados, sin sintaxis de PHP 8.
- [ ] Todo el código, comentarios y nombres en inglés; los textos de pantalla en español dentro de `t()`.
- [ ] `docs/service-requests-list.md` existe y describe la ruta implementada.

---

## Decisiones tomadas y descartadas

**1. `service_request` entra en `readonly_types()`, no en `editable_types()`.**
Se descartó replicar lo que la SPEC 56 hizo con `reclamo` (meterlo en los editables). El precedente correcto no es `reclamo` sino **`vivienda`**: un bundle que el operador de edificio necesita consultar y no debe tocar. Consecuencia deseada: `myapi_building_admin_permissions()` no cambia, no hay `hook_update_N`, y no hay que revisar qué pasa si un building admin edita el estado de una solicitud por detrás de la máquina de estados de la SPEC 77.

**2. El acotado por condominio lo hace `->addTag('node_access')`, no un filtro escrito a mano.**
Se descartó pasarle a la consulta la lista de condominios asignados. Duplicaría en un archivo de listado una regla de acceso que ya vive en `myapi_building_admin_alter_node_query()`, y las dos derivarían el día que la primera cambie. Además el tag cierra de paso la URL directa a través de `myapi_node_access()`, que un filtro en el `WHERE` no toca.

**3. La lista de exentos se escribe como resta, no con el bundle a mano.**
`array_diff(myapi_services_node_types(), myapi_building_admin_visible_types())` en lugar de enumerar los cuatro bundles que quedan. La regla que se quiere expresar es "el dominio del otro rol, menos lo que este rol ya reclama", y escrita así sobrevive al siguiente bundle que cruce la frontera. Se descartó una constante con la lista literal por lo mismo.

**4. Exentar `service_request` del alter del rol `proveedor` sale gratis, y se acepta lo que cuesta.**
Al entrar el bundle en `visible_types()`, que es justo la lista de exentos que recibe `myapi_provider_role_alter_node_query()`, un `backend` que además sea `proveedor` deja de ver el listado recortado — el objetivo. El precio es que un `proveedor` puro tampoco queda acotado **en listados con tag `node_access`**. Se acepta porque el rol no tiene hoy ninguna puerta al back-office (SPEC 78 lo dice explícitamente), porque las consultas de `api/v1/...` no llevan el tag, y porque `myapi_node_access()` sigue cerrando la URL directa. Se descartó escribir una excepción a medida en la llamada al alter: sería una segunda regla diciendo lo mismo que la primera.

**5. `myapi_node_access()` no se toca.**
Se descartó extender la lógica de exentos a la mitad por-nodo. Habría hecho coherente el caso `backend` + `proveedor` (fila visible, nodo abrible), pero mueve la regla de acceso directo de los cinco bundles del marketplace para arreglar una anomalía de configuración. Queda documentada como riesgo.

**6. Todos los joins del listado son `LEFT`, al contrario que la consulta de la API.**
`myapi_service_request_base_query()` hace `INNER` sobre categoría y solicitante porque una solicitud rota no debe llegar a la app. Aquí el lector es quien tiene que arreglarla: la fila rota aparece con `—`. Se descartó reutilizar la consulta de la API con un parámetro que cambiara los joins — dos lectores con criterios opuestos dentro de una función es cómo se rompe la de los dos.

**7. La consulta vive en el archivo nuevo, no en `includes/myapi.service_request_query.inc`.**
El `@file` de ese archivo declara que ninguna de sus consultas lleva `node_access`, y esa afirmación es verificable de un vistazo. Meter ahí la única que sí lo lleva la convierte en una excepción que hay que ir a buscar función por función. Se descartó también un tercer archivo solo para la consulta (lo que la SPEC 56 hizo con `myapi.claim_query.inc`): allí se separó porque `resources/claim.resource.inc` iba a reutilizarla, y aquí ningún resource va a entrar.

**8. Filtro por categoría, no por proveedor adjudicado.**
El pedido original mencionaba proveedores. Se optó por categoría: es la pregunta que un operador hace sobre un listado general ("¿qué hay pendiente de plomería?"), mientras que la de proveedor se hace desde el proveedor. La columna se muestra igual, y el filtro puede añadirse después sin tocar nada de lo que esta spec construye.

**9. El rango de fechas filtra `n.created` y no `field_desired_start`.**
`created` existe siempre; `desired_start` es opcional, y filtrar por él haría desaparecer del listado, sin avisar, todas las solicitudes que no la tengan. La columna se muestra igual. Se descartó ofrecer los dos rangos (cuatro inputs) por no cargar la fila de filtros con algo que nadie pidió.

**10. El enlace de la fila es condicional.**
`node/%nid/edit` para quien puede editar, `node/%nid` para quien no. Se descartó mandar a todos al `/edit` (el building admin se comería un 403 en cada fila) y mandar a todos al nodo (el `backend` perdería el acceso de un clic a la línea de tiempo de transacciones de la SPEC 94, que vive en el formulario de edición).

**11. Se reutiliza `css/myapi.claims.css`.**
Es la misma fila de filtros. Se descartó un archivo nuevo: dos hojas idénticas con nombres distintos se desincronizan a la primera.

**12. Los helpers de condominio se reutilizan del calendario, sin extraer nada.**
`myapi_calendar_condominium_scope()`, `_options()`, `_positive_int()`, `_effective_condominium()` y `_filter_form_after_build()` ya son genéricos y ya los reutiliza `includes/myapi.claims_admin.inc` por `module_load_include()`. Se descartó moverlos a un include compartido en esta spec: sería un refactor de tres archivos que no necesita nada de lo que aquí se pide, y el prefijo `_calendar_` es deuda de nombre, no de acoplamiento.

**13. La spec se numera 125 y no 124.**
`phpunit.xml` y `.github/workflows/tests.yml` ya citan "SPEC 124" para el gate de cobertura y PHPStan (commit `1bcbb1b`), sin archivo en `specs/`. El número está ocupado por código y renumerar el código sería peor.

---

## Riesgos identificados

**1. `backend` + `proveedor` en la misma cuenta: fila visible, nodo cerrado.**
`myapi_node_access()` consulta `myapi_provider_role_node_decision()` sin mirar listas de exentos, así que ese operador vería la solicitud en el listado y recibiría 403 al abrirla. *Mitigación:* documentado en `docs/service-requests-list.md`; la combinación es una anomalía de configuración y la solución operativa es dar `administrator` o quitar `proveedor`. Se decidió no tocar esa función (decisión 5); si el caso aparece en producción, la spec que lo arregle ya tiene aquí el diagnóstico escrito.

**2. Un `proveedor` puro deja de estar acotado en listados con tag `node_access`.**
Es el precio de la decisión 4. *Mitigación:* hoy el rol no tiene ninguna puerta al back-office, las consultas de `api/v1/...` no llevan el tag, y `myapi_node_access()` sigue cerrando la URL directa. Es un debilitamiento de defensa en profundidad, no un agujero abierto — pero deja de ser cierto el día que alguien conceda al rol `proveedor` un permiso de administración de contenido, y ese día hay que releer esta línea.

**3. El cambio del alter corre en TODA query con tag `node_access`, no solo en la página nueva.**
Aceptado explícitamente: un `administrador edificio` empezará a ver solicitudes en `/admin/content` y en los autocompletes de entityreference. *Mitigación:* es el mismo comportamiento que ya tiene `vivienda`, y el rol no tiene permiso de creación sobre ningún bundle que abra un selector de `service_request`, así que la superficie real de los autocompletes es hoy nula. El paso 10 del plan lo verifica a mano en `/admin/content` antes de dar la spec por cerrada.

**4. `array_diff()` conserva las claves del array original.**
`myapi_building_admin_alter_node_query()` pasa `$exempt_types` a `$gate->condition(..., 'IN')`. Un array con índices no consecutivos funciona en el `IN` de Drupal 7, pero es la clase de detalle que rompe un `json_encode()` o un test de igualdad estricta más adelante. *Mitigación:* `array_values()` en la misma línea, y un caso del test unitario que compare la lista esperada con `assertSame()`.

**5. El `node_type_load()` de `readonly_types()` se ejecuta en cada query con tag `node_access`.**
La función pasa de ser una lista literal a hacer una llamada. *Mitigación:* `node_type_load()` lee del caché estático de tipos de nodo, que Drupal 7 carga una vez por request; es el mismo coste que `editable_types()` ya paga dos veces por la misma vía desde la SPEC 56. Si aun así molesta, el parámetro `$include_request_type` permite al llamador saltárselo.

**6. Coste del enlace condicional: hasta 20 `node_load()` por página.**
`node_access('update', $node)` necesita el nodo cargado. *Mitigación:* un solo `node_load_multiple()` con los 20 nids, y cortocircuito con `user_access('bypass node access')` para `administrator`, que se salta la comprobación entera. Si el perfil lo desaconseja, la alternativa es resolver el permiso una vez por request (el permiso es por rol y bundle, no por nodo) y usar `node/%nid/edit` para todas las filas del que puede editar — se decide con la medición en el paso 7 del plan.

**7. El gate de cobertura puede bajar de umbral.**
El archivo nuevo tiene una parte no ejercitable sin Drupal: la página, el formulario y la consulta. *Mitigación:* `ServiceRequestsAdminPageTest` cubre los helpers puros, que es donde está la lógica; si el porcentaje global cae por debajo del umbral de la SPEC 123, la respuesta correcta es mover más lógica a funciones puras (armado de filas, resolución del enlace), no bajar el umbral.

**8. La consulta se salta el catálogo de estados si alguien edita `field_request_status` a mano.**
Un valor fuera de las seis claves se pinta crudo en la columna Estado en lugar de desaparecer. Es intencionado — el operador tiene que ver el dato roto — pero conviene no confundirlo con un bug al revisarlo.
