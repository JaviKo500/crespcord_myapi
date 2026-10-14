# SPEC 131 — Listados de back-office: reservas, proveedores, ofertas y calificaciones

> **Estado:** Approved · **Depende de:** SPEC 32 (bundle `reservation` y sus catálogos), SPEC 47 (patrón de página back-office sin AJAX y los helpers `myapi_calendar_condominium_scope()` / `_options()` / `_positive_int()` / `_effective_condominium()` / `_filter_form_after_build()`), SPEC 49 (rol `administrador edificio`, `reservation` en `editable_types()` con modo `direct`), SPEC 56 y SPEC 125 (molde exacto de listado: filtros GET, consulta paginada con tag `node_access`, joins `LEFT`, enlace condicional), SPEC 77 (bundles `provider`, `service_offer`, `service_rating` y sus catálogos), SPEC 83 (regla de "proveedor activo"), SPEC 87 (`field_rating_offer` opcional), SPEC 100 (campos de la oferta), SPEC 126 (detalle admin de solicitud, destino del enlace de la columna Solicitud) · **Fecha:** 2026-10-14
>
> **Objetivo:** Añadir cuatro páginas de back-office de solo lectura, bajo `admin/content/...`, para que los operadores consulten lo que llega desde la app y hoy solo se ve abriendo nodos sueltos: **Reservas** (listado, complemento del calendario), **Proveedores**, **Ofertas de proveedores** y **Calificaciones de servicio**. Se enlazan desde el bloque *Navegación* del sidebar, debajo de *Solicitudes a proveedores*.

---

## Alcance

**Dentro:**

- **Cuatro archivos nuevos en `includes/`**, uno por página, cada uno con su página, su formulario de filtros, sus helpers puros y **su propia consulta** (mismo criterio que la decisión 7 de la SPEC 125: la consulta con tag `node_access` vive con su página, no en los `*_query.inc` de la API):

  | Archivo | Página | Ruta |
  |---|---|---|
  | `includes/myapi.reservations_admin.inc` | Reservas | `admin/content/reservations` |
  | `includes/myapi.providers_admin.inc` | Proveedores | `admin/content/providers` |
  | `includes/myapi.service_offers_admin.inc` | Ofertas de proveedores | `admin/content/service-offers` |
  | `includes/myapi.service_ratings_admin.inc` | Calificaciones de servicio | `admin/content/service-ratings` |

- **`myapi.module`** (modificar):
  - Cuatro entradas `MENU_NORMAL_ITEM` en `hook_menu()`, mismo patrón que `admin/content/service-requests`.
  - **`myapi_reservations_admin_roles()`** → `administrator`, `backend`, `administrador edificio`; **`myapi_reservations_admin_access()`**, calcado de `myapi_claims_admin_access()`.
  - **`myapi_marketplace_admin_roles()`** → `administrator`, `backend`; **`myapi_marketplace_admin_access()`**, que comparten las tres páginas del marketplace. Una sola función para las tres porque la pregunta es la misma ("¿puede supervisar el marketplace?") y tres copias derivarían.
- **`css/myapi.claims.css`** (modificar) — el selector de la fila de filtros lista también las cuatro clases nuevas (`.myapi-reservations-filters`, `.myapi-providers-filters`, `.myapi-service-offers-filters`, `.myapi-service-ratings-filters`), más una regla para la marca de habilitación del listado de proveedores.
- **`myapi.info`** (modificar) — cuatro `files[]`.
- **Tests unitarios nuevos**: `ReservationsAdminPageTest.php`, `ProvidersAdminPageTest.php`, `ServiceOffersAdminPageTest.php`, `ServiceRatingsAdminPageTest.php` — helpers puros, calcados de `ServiceRequestsAdminPageTest.php`.
- **Docs nuevos**: `docs/reservations-list.md`, `docs/providers-list.md`, `docs/service-offers-list.md`, `docs/service-ratings-list.md`.
- **`docs/produccion-checklist.md`** (modificar) — los cuatro enlaces del menú *Navegación* como tarea de configuración.

**Fuera de alcance:**

- **Edición desde los listados.** Son de solo lectura; el enlace de cada fila lleva al nodo (`/edit` o vista, según permiso), como en la SPEC 125.
- **Cambios en los catálogos del rol `administrador edificio`.** `reservation` ya está en `editable_types()` con modo `direct` desde la SPEC 49; `provider`, `service_offer` y `service_rating` **no entran** (ver decisión 2).
- **Búsqueda por texto libre, ordenación por columnas, exportación CSV y acciones masivas.**
- **Páginas de detalle propias.**
- **Pagos (`pagos`).** El sitio ya tiene *Reporte Pagos* como View propia; no se duplica.
- **Cualquier endpoint `api/v1/...`.** Ningún archivo de `resources/` cambia.
- **AJAX y Views.** Filtros por GET y tabla a mano, precedente SPEC 47/56/125.
- **Crear los enlaces del menú *Navegación*.** Es configuración del sitio (vive en la base de datos, no viaja con el módulo); se documenta el procedimiento.

---

## Reglas comunes a las cuatro páginas

Idénticas a la SPEC 125, y escritas aquí una sola vez:

- Consulta sobre `node` con **`->addTag('node_access')`**, `->extend('PagerDefault')->limit(20)`.
- **Todos los joins `LEFT`.** Una fila con una referencia rota aparece con `—` en esa columna; nunca desaparece.
- Filtros por **GET**, `#method = 'get'`, `#token = FALSE`, el `#after_build` del calendario y el `hidden q` para `clean_url = 0`. **Basura en cualquier parámetro → sin ese filtro, sin error ni mensaje.** Los filtros se combinan con AND y sobreviven al paginador.
- Las fechas de los filtros se validan con `myapi_reservation_valid_date()` (cargada con `module_load_include()`, como ya hace `myapi_claims_list_filters()`); `date_from` → `00:00:00`, `date_to` → `23:59:59`, extremos independientes.
- Enlace de la fila: `node/%nid/edit` si `node_access('update', $node)`, si no `node/%nid`; resuelto con **un** `node_load_multiple()` sobre los nids de la página y cortocircuito por `bypass node access`.
- Fechas en `d/m/Y H:i` (o `d/m/Y` cuando el dato es solo fecha). Textos de pantalla en español dentro de `t()`; código y comentarios en inglés.
- Tabla vacía → `#empty` con "No hay resultados para los filtros seleccionados."
- Catálogos (estados, tipos de precio, estrellas) **se leen de sus funciones existentes**; ningún archivo nuevo declara etiquetas propias de un catálogo.

---

## 1. Reservas — `admin/content/reservations`

**Acceso:** `administrator`, `backend`, `administrador edificio`. El building admin ve solo las reservas de sus condominios: lo resuelve el tag `node_access` con la entrada `direct`/`field_condominium` que `reservation` ya tiene en `myapi_building_admin_condominium_map()`. Sin cambios de catálogo.

**Consulta** — `n.type = 'reservation'`, `n.status = 1`. Orden: `field_date DESC`, `field_start_time DESC`, `n.nid DESC` (la reserva más futura arriba; es lo que el operador busca).

| Alias | Tabla | Aporta |
|---|---|---|
| `fco` / `nc` | `field_data_field_condominium` → `node` (`condominio`) | condominio |
| `far` / `na` | `field_data_field_area` → `node` (`area`) | área |
| `fun` / `nu` | `field_data_field_unit` → `node` (`vivienda`) | vivienda |
| `fr` / `u` | `field_data_field_requester` → `users` | solicitante |
| `fd`, `fst`, `fet` | `field_date`, `field_start_time`, `field_end_time` | fecha y horario |
| `frs`, `fcb`, `fcr` | `field_reservation_status`, `field_cancelled_by`, `field_cancel_reason` | estado y cancelación |

**Columnas**

| # | Columna | Origen | Cuando falta |
|---|---|---|---|
| 1 | ID | `nid`, enlazado | — |
| 2 | Fecha | `field_date`, `d/m/Y` | `—` |
| 3 | Horario | `HH:MM – HH:MM` (formato de `myapi.time_format.inc`) | `—` |
| 4 | Área | título del área | `—` |
| 5 | Condominio | título del condominio | `—` |
| 6 | Vivienda | título de la vivienda | `—` |
| 7 | Solicitante | nombre completo o `name` (mismo criterio que `myapi_calendar_user_name_label()`) | `Sin solicitante` / `Usuario eliminado (#uid)` |
| 8 | Estado | etiqueta de `field_reservation_status` (`Confirmada` / `Cancelada`) | `—` |
| 9 | Cancelación | `Usuario: <motivo>` / `Admin: <motivo>`; sin motivo, solo quién | `—` |
| 10 | Creada | `n.created`, `d/m/Y H:i` | — |

**Filtros GET**

| Parámetro | Validación |
|---|---|
| `condominium` | entero positivo; para el building admin, solo entre sus asignados (`myapi_calendar_effective_condominium()`) |
| `area` | entero positivo que esté entre las opciones de `myapi_calendar_area_options()` del condominio efectivo |
| `status` | `confirmed` o `cancelled` (claves de `allowed_values` del campo) |
| `date_from` / `date_to` | `Y-m-d`; filtran **`field_date`** (la fecha de la reserva, no la de creación) |

---

## 2. Proveedores — `admin/content/providers`

**Acceso:** `administrator`, `backend`.

**Consulta** — `n.type = 'provider'`, **sin filtrar `n.status`**: un proveedor suspendido (despublicado a mano) es justo lo que el operador tiene que poder ver. Orden: `n.title ASC`, `n.nid ASC` (es un directorio).

Campos de cardinalidad 1 en la consulta principal (`field_phone`, `field_license_expiry`, `field_rating_avg`, `field_rating_count`). Los multivalor — **`field_categories`** y **`field_provider_users`** — **no se unen** a la consulta principal (multiplicarían filas y romperían el paginador): se leen en una segunda consulta acotada a los nids de la página y se agrupan en PHP.

**Situación** — derivada, con la misma regla de "proveedor activo" de la SPEC 83 (`node.status = 1 AND field_license_expiry >= REQUEST_TIME`), en este orden de prioridad:

| Situación | Condición |
|---|---|
| `Suspendido` | `n.status = 0` |
| `Sin habilitación` | `field_license_expiry` vacío |
| `Habilitación vencida` | `field_license_expiry < REQUEST_TIME` |
| `Vence pronto` | vence en 30 días o menos |
| `Activo` | el resto |

La función que la calcula es pura y recibe `REQUEST_TIME` como parámetro, para que el test fije los bordes (exactamente ahora, exactamente a 30 días).

**Columnas**

| # | Columna | Origen | Cuando falta |
|---|---|---|---|
| 1 | ID | `nid`, enlazado | — |
| 2 | Nombre comercial | `title` | el `nid` |
| 3 | Categorías | términos de `field_categories`, separados por coma | `—` |
| 4 | Teléfono | `field_phone` | `—` |
| 5 | Usuarios asociados | nombres de `field_provider_users` | `—` |
| 6 | Habilitación vence | `field_license_expiry`, `d/m/Y` | `—` |
| 7 | Situación | ver tabla de arriba, con clase CSS por situación | — |
| 8 | Calificación | `4,6 ★ (12)` desde `field_rating_avg` / `field_rating_count` | `Sin calificaciones` |
| 9 | Alta | `n.created`, `d/m/Y` | — |

**Filtros GET**

| Parámetro | Validación |
|---|---|
| `category` | entero positivo que exista en `service_category`; filtra por `n.nid IN (subconsulta sobre field_data_field_categories)` |
| `situation` | `active`, `expiring`, `expired`, `suspended`, `no_license`; traducido a condiciones SQL equivalentes a la tabla de situación |

---

## 3. Ofertas de proveedores — `admin/content/service-offers`

**Acceso:** `administrator`, `backend`.

**Consulta** — `n.type = 'service_offer'`, `n.status = 1`. Orden `n.nid DESC`.

| Alias | Tabla | Aporta |
|---|---|---|
| `freq` / `nr` | `field_data_field_request` → `node` (`service_request`) | solicitud |
| `fco` / `nc` | `field_data_field_condominium` **de la solicitud** → `node` (`condominio`) | condominio |
| `fpr` / `np` | `field_data_field_provider` → `node` (`provider`) | proveedor |
| `fam`, `fat` | `field_offer_amount`, `field_offer_amount_type` | monto |
| `fos` | `field_offer_status` | estado |
| `fvu`, `flm` | `field_offer_valid_until`, `field_last_message_at` | vigencia y chat |

**Columnas**

| # | Columna | Origen | Cuando falta |
|---|---|---|---|
| 1 | ID | `nid`, enlazado a la oferta | — |
| 2 | Solicitud | título de la solicitud, enlazado a **`admin/content/service-requests/%nid`** (detalle de la SPEC 126) | `—` / `Solicitud eliminada (#id)` |
| 3 | Condominio | condominio de la solicitud | `—` |
| 4 | Proveedor | título del proveedor | `—` / `Proveedor eliminado (#id)` |
| 5 | Monto | importe + etiqueta de `myapi_services_offer_amount_types()`; `on_site_quote` muestra solo la etiqueta | `—` |
| 6 | Estado | etiqueta de `myapi_services_offer_statuses()` | `—` (valor fuera de catálogo: crudo) |
| 7 | Enviada | `n.created`, `d/m/Y H:i` | — |
| 8 | Válida hasta | `field_offer_valid_until`, `d/m/Y` | `—` |
| 9 | Último mensaje | `field_last_message_at`, `d/m/Y H:i` | `Sin chat` |

**Filtros GET**

| Parámetro | Validación |
|---|---|
| `condominium` | entero positivo entre las opciones de `myapi_calendar_condominium_options()`; filtra por el condominio de la solicitud |
| `provider` | entero positivo que sea un nid de `provider` |
| `status` | una clave de `myapi_services_offer_statuses()` |
| `date_from` / `date_to` | `Y-m-d`; filtran `n.created` |

---

## 4. Calificaciones de servicio — `admin/content/service-ratings`

**Acceso:** `administrator`, `backend`.

**Consulta** — `n.type = 'service_rating'`, `n.status = 1`. Orden `n.nid DESC`.

| Alias | Tabla | Aporta |
|---|---|---|
| `frp` / `np` | `field_data_field_rating_provider` → `node` (`provider`) | proveedor |
| `fst` | `field_stars` | estrellas |
| `frc` | `field_rating_comment` | comentario |
| `fro` → `freq` / `nr` | `field_rating_offer` → `field_request` de la oferta → `node` | solicitud |
| `fun` / `nu` | `field_unit` → `node` (`vivienda`) | vivienda |

**Columnas**

| # | Columna | Origen | Cuando falta |
|---|---|---|---|
| 1 | ID | `nid`, enlazado | — |
| 2 | Fecha | `n.created`, `d/m/Y H:i` | — |
| 3 | Proveedor | título del proveedor | `—` / `Proveedor eliminado (#id)` |
| 4 | Estrellas | `★★★★☆ (4)` | `—` |
| 5 | Comentario | `check_plain()`, recortado a 140 caracteres con `truncate_utf8()`; el texto completo en el atributo `title` | `—` |
| 6 | Solicitud | a través de la oferta, enlazada al detalle admin | `Proveedor directo` cuando no hay oferta (SPEC 87) |
| 7 | Vivienda | título de la vivienda | `—` (hoy vacío en casi todas, ver Riesgos) |

**Filtros GET**

| Parámetro | Validación |
|---|---|
| `provider` | entero positivo que sea un nid de `provider` |
| `stars` | una clave de `myapi_services_star_values()` |
| `date_from` / `date_to` | `Y-m-d`; filtran `n.created` |

---

## Menú *Navegación* (configuración del sitio)

Los cuatro enlaces se añaden a mano en `admin/structure/menu/manage/navigation`, **debajo de *Solicitudes a proveedores***, con estos títulos:

| Título | Ruta |
|---|---|
| Listado de reservas | `admin/content/reservations` |
| Proveedores | `admin/content/providers` |
| Ofertas de proveedores | `admin/content/service-offers` |
| Calificaciones de servicio | `admin/content/service-ratings` |

Drupal oculta el enlace a quien no tiene acceso a la ruta, así que un `administrador edificio` solo verá *Listado de reservas* aunque los cuatro estén en el mismo menú. Se documenta además el equivalente con `drush php-eval` + `menu_link_save()`, como en `docs/building-admin-role.md`.

---

## Plan de implementación

Una página por commit, en este orden (de la más simple a la que más joins tiene), y en cada una: **test en rojo → helpers puros → consulta → página y formulario → ruta, `.info` y CSS → doc**.

1. **Acceso común** — `myapi_reservations_admin_roles()` / `_access()` y `myapi_marketplace_admin_roles()` / `_access()` en `myapi.module`.
   *Verificación: `php -l`.*
2. **Reservas** — `ReservationsAdminPageTest` en rojo; `includes/myapi.reservations_admin.inc`; ruta; `files[]`; CSS; `docs/reservations-list.md`.
   *Verificación: `vendor/bin/phpunit` verde; `phpstan` sin errores nuevos.*
3. **Proveedores** — ídem, con la función pura de situación y la segunda consulta de multivalor.
4. **Ofertas** — ídem.
5. **Calificaciones** — ídem.
6. **`docs/produccion-checklist.md`** — tarea del menú *Navegación*.
7. **Matriz manual en un sitio real** — `administrator`, `backend`, `administrador edificio` con dos condominios, `administrador edificio` sin condominios, y un autenticado sin roles: quién entra a cada ruta, cuántas filas ve, y que el building admin solo ve sus reservas.
8. **Cierre** — `drush cc all`. **`drush updb` no hace falta**: no hay schema, campo ni permiso nuevo.

---

## Criterios de aceptación

**Rutas y acceso**

- [ ] `admin/content/reservations` responde 200 para `administrator`, `backend` y `administrador edificio`; 403 para un autenticado sin esos roles.
- [ ] `admin/content/providers`, `admin/content/service-offers` y `admin/content/service-ratings` responden 200 para `administrator` y `backend`; **403 para `administrador edificio`** y para un autenticado sin roles.
- [ ] `uid 1` entra a las cuatro.
- [ ] Tras configurar el menú, un `administrador edificio` ve en el sidebar solo *Listado de reservas* de los cuatro enlaces nuevos.

**Reservas**

- [ ] Un `administrador edificio` con dos condominios ve exactamente las reservas de esos dos; `?condominium=<ajeno>` no amplía nada.
- [ ] `?area=` de un área que no pertenece al condominio efectivo se ignora.
- [ ] El rango de fechas filtra la fecha **de la reserva**, no la de creación.
- [ ] Una reserva cancelada muestra quién la canceló y el motivo en la columna Cancelación.
- [ ] Orden: la fecha de reserva más reciente arriba.

**Proveedores**

- [ ] Un proveedor despublicado aparece con situación `Suspendido`.
- [ ] Las cinco situaciones se calculan con los bordes correctos (vence exactamente ahora → `Activo`/`Vence pronto`, no `vencida`; a 30 días exactos → `Vence pronto`), con test unitario.
- [ ] Un proveedor con tres categorías aparece **una sola vez**, con las tres separadas por coma; la página sigue mostrando 20 proveedores distintos.
- [ ] `?category=` y `?situation=` filtran; basura se ignora.

**Ofertas**

- [ ] La columna Solicitud enlaza al detalle admin de la SPEC 126.
- [ ] Una oferta `on_site_quote` muestra la etiqueta del tipo de precio y ningún importe.
- [ ] El filtro de condominio usa el condominio de la solicitud.

**Calificaciones**

- [ ] Una calificación de solicitud `direct` (sin oferta) aparece, con `Proveedor directo` en la columna Solicitud.
- [ ] El comentario se escapa (un `<script>` en el comentario se ve como texto) y se recorta a 140 caracteres sin cortar un carácter multibyte.

**Comunes**

- [ ] Toda referencia rota se ve como `—` o `… eliminado (#id)`; ninguna fila desaparece por un join.
- [ ] Parámetros basura (`?status=xyz`, `?provider=-1`, `?date_from=2026-02-30`, arrays) se ignoran sin error.
- [ ] Ningún archivo de `resources/` ni de `*_query.inc` cambia (`git diff main -- resources/ includes/*_query.inc` vacío).
- [ ] `git diff main -- myapi.install` vacío.
- [ ] `vendor/bin/phpunit` verde con el gate de cobertura; `phpstan` sin errores nuevos; sin sintaxis PHP 8 (`ModuleContractTest`).
- [ ] Los cuatro docs existen y describen lo implementado.

---

## Decisiones tomadas y descartadas

**1. Páginas del módulo, no Views.** Decidido con el usuario: mismo patrón que Reclamos y Solicitudes, versionado en git, y el acotado por condominio ya resuelto por el tag `node_access`. Views habría exigido revisar el tag en cada vista y exportarlas a código para que viajen.

**2. Proveedores, ofertas y calificaciones solo para `administrator` y `backend`.** El `administrador edificio` administra condominios, y estos tres bundles no tienen condominio propio: un proveedor sirve a todos, una calificación ni siquiera se puede atar a una solicitud cuando fue directa. Meterlos en los catálogos del rol obligaría a inventar un modo de acotado indirecto (oferta → solicitud → condominio) y a tocar las listas de exentos entre los alters del building admin y del proveedor. Se descartó; si se pide, es una spec propia.

**3. Una spec para las cuatro páginas, cuatro commits.** Comparten todas las reglas; escribirlas cuatro veces es la forma de que diverjan. Cada página sigue siendo un archivo aislado, implementable y revisable por separado.

**4. Proveedores sin filtrar `n.status`.** Al contrario que los otros tres listados: el operador suspende proveedores despublicándolos (`docs/provider.md`), y necesita encontrarlos para reactivarlos.

**5. Multivalor del proveedor en una segunda consulta.** Unir `field_categories` o `field_provider_users` a la consulta paginada multiplicaría filas y el paginador contaría mal. `GROUP_CONCAT` se descartó por depender de MySQL y por el límite `group_concat_max_len`.

**6. Reservas ordenadas por fecha de reserva, no por `nid`.** Lo que el operador pregunta es "¿qué hay reservado?", no "¿qué se creó último?". La columna Creada se muestra igual.

**7. Sin listado de pagos.** *Reporte Pagos* ya existe como View del sitio.

---

## Riesgos identificados

**1. `backend` + `proveedor` en la misma cuenta.** El alter del rol `proveedor` recorta a ese operador los listados de `provider`, `service_offer` y `service_rating` a lo suyo (esos bundles no están en `myapi_building_admin_visible_types()`, que es la lista de exentos que recibe). Misma anomalía de configuración que el Riesgo 1 de la SPEC 125; se documenta en los docs de las tres páginas y la solución operativa es la misma.

**2. `field_unit` de las calificaciones está vacío casi siempre.** La SPEC 77 creó la instancia sin que ningún flujo la escriba. La columna se muestra porque es el dato correcto el día que se rellene; hoy saldrá `—`.

**3. Las calificaciones de solicitudes `direct` no tienen camino hasta la solicitud.** No hay referencia rating → solicitud, solo rating → oferta. Se muestra `Proveedor directo`. Añadir esa referencia es un cambio de schema, fuera de esta spec.

**4. El selector de proveedores de Ofertas y Calificaciones crece con el marketplace.** Hoy son pocos; si pasan de unos cientos, el selector debería pasar a autocomplete. Se revisa cuando ocurra.

**5. Gate de cobertura.** Cuatro archivos con partes no ejercitables sin Drupal (página, formulario, consulta). La mitigación es la de la SPEC 125: la lógica va a funciones puras (situación, etiquetas, armado de filas, validación de filtros), que es donde están los tests.
