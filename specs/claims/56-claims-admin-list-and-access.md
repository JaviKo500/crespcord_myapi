# SPEC 56 — Acceso `via_claim` y listado de reclamos en el panel de administración

> **Estado:** Implemented · **Depende de:** SPEC 47 (`myapi_calendar_admin_roles()`, patrón de página back-office sin AJAX), SPEC 49 (`myapi_building_admin_condominium_map()`, modos `self`/`direct`/`via_unit`, `myapi_building_admin_editable_types()`), SPEC 55 (bundles `reclamo`/`claim_transaction`, nota explícita de que `via_claim` queda pendiente) · **Fecha:** 2026-08-01
> **Objetivo:** Añadir el modo de resolución `via_claim` para que `administrador edificio` pueda administrar `claim_transaction` acotado a sus condominios, y una página de back-office en `admin/content/claims` — enlazada en el sidebar, visible para `administrator`, `backend` y `administrador edificio` — que lista los reclamos con filtros y paginación, sin tocar ningún endpoint de la API ni el formulario de edición.

---

## Alcance

**Dentro:**

- **`includes/myapi.building_admin.inc`** (modificar):
  - Nuevo modo **`via_claim`** en `myapi_building_admin_condominium_map()` para `claim_transaction`: resuelve su condominio saltando `field_claim` → nodo `reclamo` → `field_condominium`, reutilizando la propia entrada `MYAPI_BUILDING_ADMIN_CLAIM_TYPE` del mapa (sin hardcodear el campo dos veces, mismo criterio que `via_unit` reutiliza `$map['vivienda']['field']`).
  - `myapi_building_admin_resolve_condominium()` gana la rama `via_claim`, con un `$claim_loader` inyectado igual que `$unit_loader` — sigue puro y testeable.
  - `myapi_building_admin_alter_node_query()` gana el join de dos saltos para `via_claim` (mismo patrón que ya usa para `via_unit`, con `myapi_building_admin_join_field()` reutilizado).
  - `myapi_building_admin_editable_types()` agrega `MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE` (nueva constante, `'claim_transaction'`) condicionalmente, cuando el bundle existe — mismo criterio que ya aplica a `reclamo`. Esto hace que `myapi_building_admin_permissions()` conceda `create claim_transaction content` / `edit any claim_transaction content` sin tocar esa función.
  - Comentario/nota actualizados: la nota de SPEC 55 que decía "no existe un modo `via_claim`" se retira, ya no aplica.
- **`myapi.install`** (modificar) — nuevo **`myapi_update_7018()`** que re-ejecuta `_myapi_building_admin_install()` para que un sitio ya instalado reciba los dos permisos nuevos sobre `claim_transaction` (sin schema nuevo, sin campo nuevo).
- **`includes/myapi.claims_admin.inc`** (nuevo) — página de listado:
  - Page callback `myapi_claims_list_page()`: lee y valida los parámetros GET, llama al helper de consulta, arma la tabla paginada y devuelve el HTML.
  - Formulario de filtros `myapi_claims_list_filter_form()` con `#method = 'get'` (condominio, estado, tipo, fecha de recepción desde/hasta).
  - Helper de opciones de condominio para el selector, acotado a los asignados cuando el usuario es `administrador edificio` (mismo criterio que `myapi_calendar_condominium_options()` de SPEC 47/49; se evalúa reutilizar esa función si es suficientemente genérica, o extraer una versión compartida — se decide en el plan de implementación, no aquí).
  - Tabla con columnas: ID, asunto (título), condominio, estado, tipo, solicitante, fecha de recepción. Cada fila enlaza a `node/%nid/edit` (ruta nativa de Drupal, sin página propia). Botón "Crear reclamo" al inicio del listado, enlaza a `node/add/reclamo`.
  - Paginación real con `pager_default_initialize()` / `->extend('PagerDefault')->limit(20)`, orden `ORDER BY nid DESC`.
- **`includes/myapi.claim_query.inc`** (nuevo, o función añadida a un include existente si el plan de implementación lo justifica) — `myapi_claims_list_rows($condominium_id, $status, $claim_type, $date_from, $date_to)`, consulta única con `addTag('node_access')` para que `myapi_building_admin_alter_node_query()` la acote automáticamente sin duplicar lógica de condominio.
- **`myapi.module`** (modificar):
  - Entrada de `hook_menu()` para `admin/content/claims`, `MENU_NORMAL_ITEM` (aparece en el sidebar bajo Contenido, mismo patrón que `admin/content/reservation-calendar`), `file` apuntando al `.inc` nuevo.
  - `myapi_claims_admin_roles()` — única fuente de la lista de roles autorizados: `administrator`, `backend`, `administrador edificio` (vía `MYAPI_BUILDING_ADMIN_ROLE`).
  - `myapi_claims_admin_access()` — access callback por nombre de rol, `uid 1` siempre dentro (mismo patrón que `myapi_calendar_access()`). Un `administrador edificio` sin condominios asignados accede a la página pero ve el listado vacío — el filtro lo decide la query, no el access callback.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.claims_admin.inc` (y el de la query si es archivo separado).
- **`docs/claims-list.md`** (nuevo) — ruta, control de acceso, filtros, columnas, paginación, y la explicación del modo `via_claim`.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance (para SPEC B / futuros specs):**

- **Página de edición de `reclamo` con el listado de transacciones debajo, el botón "crear transacción" y su modal.** Es el spec B de este mismo pedido.
- **Creación automática de la transacción inicial al crear un reclamo**, y la sincronización de estado reclamo↔transacción. También spec B.
- **Cualquier endpoint `api/v1/...`.** No se toca ningún `resource`.
- **Búsqueda por texto libre** (asunto o nombre del solicitante).
- **Filtro por `field_visibility`.**
- **Listado o página propia para `claim_transaction`.** Solo se resuelve su acceso (`via_claim`); verlas en contexto es tarea del spec B.
- **AJAX de cualquier tipo.** Los filtros recargan por GET, igual que el calendario.
- **Views.** Página construida a mano, mismo precedente que SPEC 47.

---

## Modelo de datos

No se crean tablas SQL nuevas ni campos nuevos. Se modifican catálogos existentes en `includes/myapi.building_admin.inc` y se define una consulta nueva de solo lectura.

### Constante nueva

```php
// includes/myapi.building_admin.inc
if (!defined('MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE')) {
  define('MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE', 'claim_transaction');
}
```

### Entrada nueva en `myapi_building_admin_condominium_map()`

```php
function myapi_building_admin_condominium_map() {
  return [
    // ... entradas existentes sin cambios ...
    MYAPI_BUILDING_ADMIN_CLAIM_TYPE => ['mode' => 'direct', 'field' => 'field_condominium'],
    MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE => [
      'mode'  => 'via_claim',
      'field' => 'field_claim', // entityreference en claim_transaction -> nodo reclamo
    ],
  ];
}
```

### `myapi_building_admin_resolve_condominium()` — rama `via_claim`

Mismo patrón que `via_unit`, pero el nodo intermedio es el propio `reclamo` y su campo de condominio se lee **de la entrada del mapa**, no hardcodeado:

```php
case 'via_claim':
  $claim_nid = myapi_building_admin_field_target_id($node, $entry['field']);
  if (!$claim_nid || !is_callable($claim_loader)) {
    return NULL;
  }
  $claim = call_user_func($claim_loader, $claim_nid);
  if (!is_object($claim)) {
    return NULL;
  }
  return myapi_building_admin_field_target_id($claim, $map[MYAPI_BUILDING_ADMIN_CLAIM_TYPE]['field']);
```

La firma gana un tercer parámetro `$claim_loader = NULL` (por defecto `NULL`, no rompe las llamadas existentes de `via_unit`). `myapi_building_admin_node_condominium()` pasa `'node_load'` para los dos.

### `myapi_building_admin_alter_node_query()` — rama `via_claim`

Dos saltos de `leftJoin` vía `myapi_building_admin_join_field()` (ya reentrante por `$joined`): `claim_transaction.field_claim_target_id` → `reclamo.nid`, y de ahí `reclamo.field_condominium_target_id` → condominios asignados. Mismo criterio de "fail closed" que `via_unit`: si `field_claim` o `field_condominium` no existen en el entorno, esa rama no se añade y `claim_transaction` desaparece del listado en vez de mostrarse sin filtrar.

### Filas de `myapi_claims_list_rows()`

```
nid, subject, condominium_id, condominium_title,
status, claim_type, reception_date,
requester_uid, requester_name
```

Una sola consulta, `LEFT JOIN` a `field_data_field_condominium` + `node` (título), `field_data_field_status`, `field_data_field_claim_type`, `field_data_field_reception_date`, `field_data_field_requester` + `users` (`name`). `n.type = 'reclamo'` y `n.status = 1`. `->addTag('node_access')` para que el filtro de condominio de `administrador edificio` se aplique solo (ver Alcance). Sin ese tag, `administrator`/`backend` no se ven afectados en absoluto — el guard 1 de `myapi_building_admin_alter_node_query()` sale de inmediato si el usuario no tiene el rol.

### Parámetros GET del filtro

| Parámetro | Validación | Por defecto |
|---|---|---|
| `condominium` | entero positivo; si `administrador edificio`, se ignora si no está entre sus asignados | sin filtro |
| `status` | uno de `received`/`in_progress`/`resolved`/`closed`/`duplicated`; cualquier otra cosa → sin filtro | sin filtro |
| `claim_type` | `requirement` o `claim`; cualquier otra cosa → sin filtro | sin filtro |
| `date_from` | fecha `Y-m-d` válida; si no, se ignora | sin filtro |
| `date_to` | fecha `Y-m-d` válida; si no, se ignora | sin filtro |
| `page` | manejado por `pager_default_initialize()`, no por este código | `0` |

Basura en cualquier parámetro cae al valor por defecto sin error, mismo criterio que el calendario (SPEC 47).

### Update hook

```php
function myapi_update_7018() {
  _myapi_building_admin_install();
}
```

Reutiliza el helper existente de SPEC 49 tal cual — ya cruza los permisos deseados contra `module_invoke_all('permission')` y ya no duplica nada.

---

## Plan de implementación

1. **`tests/unit/BuildingAdminTest.php` (modificar) — casos nuevos antes de tocar la lógica.** Casos para `myapi_building_admin_resolve_condominium()` en modo `via_claim` (resuelve por el salto doble, `claim_loader` no invocable ⇒ `NULL`, `reclamo` sin `field_condominium` ⇒ `NULL` sin error PHP) y para `myapi_building_admin_editable_types()` (incluye `claim_transaction` solo cuando `$include_claim_type` fuerza `TRUE`, igual que ya hace con `reclamo`). *Verificación: `vendor/bin/phpunit` en rojo con "modo no reconocido" / aserciones fallidas.*

2. **`includes/myapi.building_admin.inc` — constante, mapa y resolución pura.** `MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE`, la entrada `via_claim` en `myapi_building_admin_condominium_map()`, la rama `via_claim` en `myapi_building_admin_resolve_condominium()` con el tercer parámetro `$claim_loader = NULL`, y `myapi_building_admin_editable_types()` extendido. *Verificación: `php -l`; `vendor/bin/phpunit` **en verde**.*

3. **`includes/myapi.building_admin.inc` — `myapi_building_admin_alter_node_query()`.** Rama `via_claim` con el doble `leftJoin` reentrante vía `myapi_building_admin_join_field()`, fail-closed si `field_claim` o `field_condominium` faltan. Sin test unitario (depende de `SelectQueryInterface`, mismo criterio ya documentado en SPEC 49 para esta función). *Verificación: `php -l`; matriz manual del paso 9.*

4. **`myapi.install` — `myapi_update_7018()`.** Una línea, llama a `_myapi_building_admin_install()` (ya existe, ya idempotente, ya cruza contra permisos disponibles). No hace falta tocar `myapi_install()`: una instalación limpia ya llama a ese helper y `myapi_building_admin_editable_types()` ya incluye `claim_transaction` desde el paso 2. *Verificación: en un sitio con el módulo ya instalado, `drush updb` concede `create claim_transaction content` / `edit any claim_transaction content` al rol; reejecutar el update no duplica filas en `role_permission`.*

5. **`includes/myapi.claim_query.inc` (nuevo) — `myapi_claims_list_rows()`.** La consulta de la sección de modelo de datos, con `addTag('node_access')`, los cinco filtros opcionales y `pager_default_initialize()` / `->extend('PagerDefault')->limit(20)`. Docblock explicando por qué el tag es suficiente y no hace falta repetir el filtro de condominio a mano. *Verificación: `php -l`.*

6. **`includes/myapi.claims_admin.inc` (nuevo) — página y formulario de filtros.** `myapi_claims_list_page()` valida los parámetros GET, llama al helper del paso 5, arma la tabla (`theme('table', ...)` con pager) y el botón "Crear reclamo". `myapi_claims_list_filter_form()` con `#method = 'get'` y los cuatro campos de filtro; el selector de condominio se acota a los asignados si el usuario es `administrador edificio` (se decide aquí si se reutiliza `myapi_calendar_condominium_options()` extrayéndola a un include compartido, o se duplica una versión mínima — la firma actual de esa función y su acoplamiento al calendario se revisan en este paso antes de decidir). *Verificación: `php -l`; página renderiza con datos de prueba.*

7. **`myapi.module` — `hook_menu()`, `myapi_claims_admin_roles()`, `myapi_claims_admin_access()`.** Entrada `admin/content/claims` (`MENU_NORMAL_ITEM`, `file` hacia el `.inc` del paso 6). **`myapi.info`**: `files[]` para los dos `.inc` nuevos. *Verificación: `php -l`; tras `drush cc all`, aparece el enlace en el sidebar bajo Contenido para `administrator`/`backend`, y un autenticado sin esos roles ni `administrador edificio` recibe 403.*

8. **`docs/claims-list.md` (nuevo).** Ruta, control de acceso (los tres roles y la nota de `via_claim`), tabla de filtros, columnas, paginación, y cómo verificar que `administrador edificio` solo ve sus condominios. *Verificación: lectura contra la implementación.*

9. **`drush updb && drush cc all`.** Matriz manual: acceso por rol, filtro de condominio con y sin `administrador edificio`, cada filtro por separado, paginación con más de 20 reclamos, click en fila y en "Crear reclamo".

---

## Criterios de aceptación

**Modo `via_claim` (unit)**

- [x] `myapi_building_admin_resolve_condominium()` en modo `via_claim` devuelve el `nid` del condominio cuando `field_claim` apunta a un `reclamo` con `field_condominium` relleno.
- [x] Devuelve `NULL` si `field_claim` está vacío, si `$claim_loader` no es invocable, o si el `reclamo` cargado no tiene `field_condominium`. Ningún caso emite warning de PHP.
- [x] `myapi_building_admin_condominium_map()` incluye `claim_transaction => ['mode' => 'via_claim', 'field' => 'field_claim']` y sigue teniendo exactamente las mismas entradas anteriores sin cambios.
- [x] `myapi_building_admin_editable_types(TRUE)` incluye `claim_transaction`; `myapi_building_admin_editable_types(FALSE)` no lo incluye; sin argumento, depende de `node_type_load('claim_transaction')`.
- [x] `vendor/bin/phpunit` pasa entero, incluidos los tests nuevos, sin tocar los existentes.

**Permisos**

- [x] En un sitio limpio (`drush en myapi`), el rol `administrador edificio` tiene concedidos `create claim_transaction content` y `edit any claim_transaction content` en `/admin/people/permissions`.
- [x] En un sitio donde el módulo ya estaba instalado, `drush updb` ejecuta `myapi_update_7018` y concede esos dos permisos sin tocar ningún otro permiso del rol.
- [x] Reejecutar `myapi_update_7018` dos veces no duplica filas en `role_permission`.
- [x] Ningún permiso `delete … claim_transaction content` queda concedido.

**Filtro de condominio (`hook_node_access()` y `hook_query_alter()`)**

- [x] Un `administrador edificio` con el condominio A asignado: abrir por URL directa `/node/N/edit` de una `claim_transaction` cuyo `reclamo` pertenece a A devuelve 200; si pertenece a B, devuelve 403.
- [x] Una `claim_transaction` cuyo `field_claim` está vacío, o cuyo `reclamo` referenciado no tiene `field_condominium`, no produce error PHP y su acceso lo decide el resto de Drupal (`NODE_ACCESS_IGNORE`).
- [x] Un usuario `administrator` o `backend` sigue viendo y editando toda `claim_transaction` sin ninguna restricción nueva.
- [x] Un residente autenticado en la app recibe exactamente las mismas respuestas en todos los endpoints `api/v1/...` (no se toca ningún `resource`).

**Página `admin/content/claims` — acceso**

- [x] `administrator`, `backend` y `uid 1` acceden sin 403.
- [x] `administrador edificio` accede sin 403, tenga o no condominios asignados.
- [x] Un autenticado sin esos roles, y un anónimo, reciben 403.
- [x] El enlace "Reclamos" (o el título elegido) aparece en el sidebar bajo Contenido para los tres roles autorizados.

**Listado — filtros y datos**

- [x] Sin parámetros, el listado muestra los reclamos de los condominios visibles para el usuario (todos para `administrator`/`backend`; solo los asignados para `administrador edificio`), ordenados por `nid` **descendente**.
- [x] Un `administrador edificio` con el condominio A asignado no ve en ningún caso un reclamo del condominio B, ni cambiando los filtros de la URL.
- [x] Un `administrador edificio` sin condominios asignados ve el listado vacío, sin error.
- [x] Filtrar por `condominium`, `status`, `claim_type`, `date_from` y `date_to` — cada uno por separado y combinados — devuelve solo las filas que cumplen todos los filtros activos.
- [x] `?condominium=B` en la URL de un `administrador edificio` sin B asignado se ignora en silencio (mismo criterio que el calendario).
- [x] Parámetros basura (`?status=inventado`, `?date_from=hola`) no producen error: caen a "sin filtro".
- [x] Cada fila muestra ID, asunto, condominio, estado, tipo, solicitante y fecha de recepción.
- [x] Click en una fila navega a `node/<nid>/edit` del reclamo.
- [x] El botón "Crear reclamo" navega a `node/add/reclamo`.

**Paginación**

- [x] Con más de 20 reclamos visibles, el listado pagina de a 20 y el pager de Drupal (`pager_default_initialize()`) funciona con los filtros activos conservados entre páginas.
- [x] Con 20 o menos, no aparece el pager.

**No regresión / infra**

- [x] `resources/*.resource.inc` no aparece en el diff.
- [x] `hook_menu()` no cambia ninguna ruta `api/v1/...`; la única entrada nueva es `admin/content/claims`.
- [x] `myapi_update_7017` y anteriores quedan intactos.
- [x] `drush cc all` no reporta errores.
- [x] Existe `docs/claims-list.md` con ruta, acceso, filtros, columnas y paginación.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Alcance del pedido original | Partido en 2 specs: este (acceso + listado) y uno futuro (edición + transacciones + modal) | Un único spec grande | Toca 5+ dominios distintos (acceso, listado, edición, AJAX/modal, lógica de negocio de sincronización). Mismo criterio de partición que SPEC 32→48 en reservas. |
| Roles con acceso al listado | `administrator`, `backend` y `administrador edificio` | Solo `administrator`/`backend` | Es justamente el rol que hoy puede crear/editar reclamos (SPEC 49/55) pero no tenía dónde verlos agrupados ni acceso a sus transacciones. Dejarlo fuera habría resuelto la mitad del pedido. |
| Resolución del condominio de `claim_transaction` | Nuevo modo `via_claim` (`field_claim` → `reclamo` → `field_condominium`) | Dejarlo fuera del mapa indefinidamente, o forzar un `field_condominium` propio y redundante en `claim_transaction` | Es exactamente el modo que SPEC 55 dejó pendiente a propósito. Duplicar el campo de condominio en la transacción violaría la relación 1:N ya modelada — el condominio de una transacción **es** el de su reclamo, no un dato independiente que pueda desincronizarse. |
| Permisos sobre `claim_transaction` | `create` + `edit any`, concedidos ya en este spec | Dejarlo en solo lectura hasta el spec de edición | El spec de edición (spec B) va a necesitar crear transacciones desde un modal; pedirle a ese spec que además gestione permisos mezclaría control de acceso con UI. Aquí queda listo y probado con la matriz de acceso de este spec. |
| Filtros del listado | Condominio, estado, tipo, fecha de recepción (rango) | Replicar literalmente los cinco filtros del calendario (incluye área y vista mes/semana) | Reclamos no tiene "área" ni una vista de rejilla temporal — son conceptos propios de reservas. Se toma el *criterio* del calendario (selector de condominio acotado por rol, GET recargable, valores basura caen a default) y se aplican los filtros que sí tienen sentido para un listado tabular. |
| Filtro de fecha | Rango `date_from`/`date_to` | Selector mes/semana como el calendario | El calendario necesita mes/semana porque pinta una rejilla visual; un listado tabular se filtra mejor con un rango abierto. |
| Ruta de la página | `admin/content/claims` | `admin/content/reclamos` | Consistencia con el resto de rutas del módulo, todas en inglés (`reservation-calendar`), aunque el machine name del bundle sea `reclamo` en español — ya es una mezcla aceptada desde SPEC 55. |
| Construcción del listado | Página propia hecha a mano, mismo patrón de SPEC 47 | Vista de Views, como ya sirve `/admin/content` en este sitio | SPEC 49 dejó documentado que la vista de `/admin/content` depende de que *"Disable SQL rewriting"* esté desmarcado para que el filtro de condominio aplique — un riesgo de configuración fuera del control del código. Una página propia con `addTag('node_access')` en su propia consulta no depende de esa configuración externa. |
| Filtro de condominio en la consulta del listado | `addTag('node_access')` sobre la query nueva, reutilizando `myapi_building_admin_alter_node_query()` sin cambios de firma | Repetir a mano la lógica de condominios asignados dentro de `myapi_claims_list_rows()` | Regla 3 de CLAUDE.md: no duplicar lógica compartida. La función de alteración ya sabe resolver `via_claim`/`direct`/`via_unit` genéricamente; añadir el tag es una línea y hereda cualquier fix futuro sin tocar este archivo. |
| Paginación | Pager real de Drupal (`pager_default_initialize()`) | Tope de filas + aviso, como el calendario (SPEC 47) | Un listado de reclamos crece indefinidamente y no tiene una "ventana natural" como la rejilla mensual del calendario; ocultar reclamos antiguos detrás de un tope sería peor experiencia que paginar. |
| Orden del listado | `nid` descendente | `nid` ascendente | Los reclamos más recientes son los que un operador necesita ver primero — mismo criterio que cualquier bandeja de soporte. |
| Filas por página | 20 | 50 | Estándar nativo de `/admin/content` en Drupal 7; no hay motivo para desviarse. |
| Click en fila | Enlaza a `node/<nid>/edit`, la ruta nativa de Drupal | Construir ya en este spec una página de edición custom | La página de edición con el listado de transacciones debajo es todo el spec B. Este spec deja el enlace apuntando al formulario nativo, que sigue siendo válido y funcional aunque no muestre transacciones — no hay estado intermedio roto. |
| Sin AJAX en este spec | Filtros por GET, recarga completa | Cualquier interacción AJAX | Mismo criterio que SPEC 47: nada de este spec necesita AJAX, y evitarlo mantiene la página cacheable y simple de depurar. El modal AJAX es un problema exclusivo del spec B. |
| Acceso a la página vs. contenido vacío | El access callback solo verifica el rol; un `administrador edificio` sin condominios asignados entra y ve la tabla vacía | Denegar el acceso a la página si no tiene condominios asignados | Mismo criterio que el calendario de reservas: la página es accesible, el filtro de datos es responsabilidad de la consulta, no del control de acceso a la ruta. Simplifica la lógica de acceso a una sola pregunta ("¿tiene el rol?"). |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **`via_claim` es un salto doble** (`claim_transaction` → `reclamo` → `field_condominium`), más costoso que `direct`. En `hook_node_access()` se paga por nodo cargado. | Mismo patrón de caché estática por `nid` que ya usa `myapi_building_admin_node_condominium()` para `via_unit`; el costo ya existe hoy para pagos/recibos y no se introduce nada nuevo en el mecanismo. |
| **Un `reclamo` borrado deja `claim_transaction` huérfanas** (`field_claim` con un `target_id` que ya no carga). | `myapi_building_admin_resolve_condominium()` devuelve `NULL` cuando `node_load()` del reclamo falla — la transacción queda **fuera** de la regla (`NODE_ACCESS_IGNORE`), no oculta ni rota. Documentado en `docs/claims-list.md`. |
| **Olvidar el `addTag('node_access')`** en una consulta futura sobre `claim_transaction` (por ejemplo, la que construya el spec de edición) expondría transacciones de otros condominios sin que nada lo avise. | Documentado como regla de mantenimiento en `docs/claims-list.md`, mismo texto que ya usa `docs/building-admin-role.md` para el resto del módulo: toda pantalla nueva de back office debe pasar por `myapi_building_admin_*` o llevar el tag. |
| **`/admin/content` (la vista de Views existente) sigue sujeta al riesgo ya documentado en SPEC 49**: si la vista tiene marcado *"Disable SQL rewriting"*, un `administrador edificio` vería ahí reclamos y transacciones sin filtrar, aunque `admin/content/claims` y las URLs directas sí queden protegidas. | No es un riesgo nuevo de este spec — ya estaba en SPEC 49 — pero se repite en `docs/claims-list.md` porque ahora `claim_transaction` también pasa a ser un tipo con datos sensibles por condominio. |
| **El pager pierde los filtros al cambiar de página** si `pager_default_initialize()` no se combina correctamente con los parámetros GET del formulario de filtros. | Criterio de aceptación explícito que lo prueba; el formulario de filtros y el pager comparten la misma URL base (`current_path()`), mismo patrón que cualquier vista con exposed filters de Drupal 7. |
| **Reutilizar (o no) `myapi_calendar_condominium_options()`** sin revisar su acoplamiento actual al calendario podría introducir una regresión ahí si se modifica su firma. | Decisión explícita en el plan (paso 6): se revisa la función antes de decidir si se extrae a un include compartido o se duplica una versión mínima — no se toca a ciegas. |
| **Un `administrador edificio` sin condominios asignados** ve la página vacía sin ninguna explicación en pantalla, lo que puede leerse como un bug en vez de una asignación pendiente. | Mismo criterio aceptado que el calendario de reservas (SPEC 47/49): no se agrega un mensaje especial en este spec: es la misma señal (tabla vacía) en toda la superficie de back office del rol. |
| **Concurrencia entre `myapi_update_7018` y ediciones manuales de permisos en producción.** | `_myapi_building_admin_install()` ya es conservador: solo agrega lo que falta, nunca revoca lo que un administrador haya quitado a mano — mismo comportamiento documentado en SPEC 49. |

---

## Lo que **NO** está en este spec

- Página de edición de `reclamo` con el listado de transacciones, el botón "crear transacción" y su modal.
- Creación automática de la transacción inicial al crear un reclamo.
- Sincronización de estado reclamo↔transacción.
- Cualquier endpoint `api/v1/...`.
- Búsqueda por texto libre, filtro por `field_visibility`, listado propio de `claim_transaction`.
- AJAX de cualquier tipo.
- Views.

Cada uno, si entra, va en su propio spec (el inmediato es la SPEC B ya acordada).
