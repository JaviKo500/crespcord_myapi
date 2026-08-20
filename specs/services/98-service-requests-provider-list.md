# 98 — Listado de solicitudes de servicio del proveedor (`GET /api/v1/service-requests/provider`)

- **Estado:** Approved
- **Fecha:** 2026-08-20
- **Dependencias:**
  - `88-service-requests-list` (Implemented) — dueña de
    `myapi_service_request_base_query()`, `myapi_service_request_fetch()`,
    `myapi_service_request_count()`, `myapi_service_request_build_item()`,
    `myapi_service_request_offer_counts_by_nid()`, de
    `myapi_service_request_parse_id_param()` (que el `?provider_id` de este spec
    reutiliza tal cual) y de los siete parámetros de query string. Este spec
    **reutiliza esas funciones** y **modifica dos** (`base_query` y `fetch`) de
    forma estrictamente aditiva: el listado del residente responde byte a byte
    lo mismo.
  - `89-service-request-detail` (Implemented) — dueña de
    `myapi_service_request_viewer()`, la función que este spec **amplía** con una
    tercera regla (decisión 7), y del precedente de forma de
    `requester {id, name}` y `condominium {id, name}`. También dueña de la
    decisión de anular `unit` para un lector proveedor, que este spec **matiza**
    (decisión 5).
  - `91-service-request-list-unit` (Implemented) — dueña de la clave `unit` del
    ítem y de sus dos saltos de join, que este spec condiciona al lector, y del
    **precedente de `?unit_id`**: estricto en el formato, laxo en la pertenencia.
    `?provider_id` copia esa regla línea por línea (decisiones 8 y 9).
  - `78-provider-role` (Implemented) — dueña del rol `proveedor`, de
    `myapi_provider_role_is()` (la compuerta del `403`), de
    `myapi_provider_role_provider_ids()`,
    `myapi_provider_role_category_ids()`,
    `myapi_provider_role_any_provider_active()`,
    `myapi_provider_role_offered_request_ids()` y del catálogo
    `myapi_provider_role_broadcast_statuses()`.
  - `97-provider-mine-list` (Implemented) — el **precedente exacto de
    autorización**: rol → `403 provider_role_required`; rol sin vínculo → `200`
    con lista vacía. Dueña de la clave i18n `provider_role_required`, que este
    spec **reutiliza sin añadir ninguna nueva**. Es también el endpoint del que
    la app saca los `id` que puede mandar en `?provider_id`.
  - `87-service-request-direct-status` (Implemented) — dueña del estado
    `direct`, la razón de que "adjudicada a mí" no se pueda resolver por la
    oferta y necesite `field_assigned_provider` por su cuenta.
  - `77-services-content-types-install` (Implemented) — dueña de los bundles y
    campos. **Cero cambios de esquema:** ni campo, ni instancia, ni bundle, ni
    tabla, ni `hook_update_N()`.

**Objetivo:** Que una cuenta con el rol `proveedor` obtenga con
`GET /api/v1/service-requests/provider` una lista paginada de las solicitudes
que le conciernen —las que ya ofertó, las abiertas de sus categorías y las
adjudicadas a sus proveedores en cualquier estado—, acotable a uno de sus
proveedores con `?provider_id`, con la misma forma de ítem que el listado del
residente más el solicitante y el condominio.

---

## Alcance

**Dentro:**

- **`myapi.module`** (modificar) — una entrada nueva en `hook_menu()`:
  `api/v1/service-requests/provider`, `MENU_CALLBACK`, `access callback` en
  `TRUE` (la autorización la hace el recurso, como en todo el módulo),
  `page callback` = `myapi_service_request_provider_dispatch`. Se escribe
  **antes** de `api/v1/service-requests/%`, igual que `providers/mine` frente a
  `providers/%` (SPEC 97). Las cuatro rutas existentes del bundle no se tocan.

- **`includes/myapi.provider_role.inc`** (modificar) — tres cambios, dos de
  ellos refactor sin cambio de comportamiento:
  - **`myapi_provider_role_category_ids_for_providers(array $provider_ids)`** y
    **`myapi_provider_role_any_provider_active_for_providers(array $provider_ids)`**
    (nuevas) — el cuerpo actual de `myapi_provider_role_category_ids()` y
    `myapi_provider_role_any_provider_active()`, extraído. Las dos funciones
    existentes pasan a ser envoltorios de una línea sobre ellas. Hace falta
    porque `?provider_id` necesita preguntar *"las categorías de **este**
    proveedor"* y *"¿**este** proveedor está activo?"*, no las de la cuenta
    entera. La caché estática ya estaba indexada por el conjunto de ids, así que
    el refactor no cambia ni una lectura.
  - **`myapi_provider_role_assigned_request_ids(array $provider_ids)`** (nueva)
    — gemela de `myapi_provider_role_offered_request_ids()`: los nids de
    `service_request` cuyo `field_assigned_provider` apunta a uno de esos
    proveedores, **en cualquier estado**. Es el conjunto C de la regla de acceso,
    y lo que hace que "mis trabajos" exista.

- **`resources/service_request.resource.inc`** (modificar) — el grueso:
  - **`myapi_service_request_provider_dispatch()`** (nueva) — `GET` llama al
    listado; cualquier otro método es `405 method_not_allowed` **antes** de
    mirar el token.
  - **`myapi_service_request_provider_list()`** (nueva) — el endpoint: token →
    compuerta de rol → `?provider_id` → alcance → conteo → página → nombres →
    respuesta.
  - **`myapi_service_request_provider_scope(array $provider_ids)`** (nueva) — la
    regla de acceso del proveedor, escrita **una sola vez** y en forma de
    conjunto: A ∪ B ∪ C. Es la pieza que el listado y
    `myapi_service_request_viewer()` comparten.
  - **`myapi_service_request_parse_provider_id()`** (nueva) — una línea sobre
    `myapi_service_request_parse_id_param('provider_id')`, el mismo parser de
    `?category_id` y `?unit_id`: `422 invalid_field` si está malformado, `NULL`
    si no viene.
  - **`myapi_service_request_provider_build_item($row, array $offer_counts, array $owned_provider_ids)`**
    (nueva) — llama a `myapi_service_request_build_item()` **sin tocarlo**, añade
    `requester` y `condominium`, y **sobrescribe `unit` a `null`** salvo que
    `assigned_provider.id` sea uno de los proveedores de la cuenta (decisión 5).
  - **`myapi_service_request_viewer()`** (modificar) — una **regla 2b** nueva
    entre la 2 y la 3: *"la solicitud está adjudicada a uno de mis proveedores"*
    → `'provider'`, en cualquier estado. Es estrictamente **aditiva**: ningún
    lector pierde acceso, y cierra el `403` del `direct` que el SPEC 89 dejó
    escrito en sus Riesgos.
  - **`myapi_service_request_base_query()`** (modificar) — un filtro nuevo,
    `provider_scope`. Ausente o `NULL` → la consulta es **byte a byte la de
    hoy**. Es el único punto por el que el alcance del proveedor entra en la
    consulta compartida.
  - **`myapi_service_request_fetch()`** (modificar) — tres proyecciones nuevas:
    `requester_uid` (de `fr`, join que la base ya hace: **coste cero**) y las dos
    columnas del condominio, que llegan tras dos LEFT JOIN **idénticos a los del
    detalle**. Ninguna de las tres la lee `myapi_service_request_build_item()`,
    así que **la respuesta del residente no cambia**.

- **`docs/service-request-provider.md`** (nuevo) — la documentación del
  endpoint, con la plantilla de `CLAUDE.md`.

- **`docs/service-request.md`** (modificar) — el enlace cruzado en la cabecera
  (donde hoy dice que "la mitad del proveedor es su propia spec", que es esta), y
  la sección de `403` del detalle actualizada con la regla 2b.

- **`docs/provider-mine.md`** (modificar) — una línea: los `id` que devuelve son
  los que este endpoint acepta en `?provider_id`.

- **`tests/unit/ServiceRequestProviderListTest.php`** (nuevo) — al estilo de
  `ProviderMineEndpointTest.php`: funciones puras y fixtures de fila, sin sitio
  arrancado.

**Fuera (explícito):**

- **`myapi.info` no se toca.** No hay `.inc` nuevo: todo vive en tres archivos ya
  listados en `files[]`.
- **`includes/myapi.i18n.inc` y `docs/i18n.md` no se tocan.** Ni una clave nueva:
  el `403` reutiliza `provider_role_required` (SPEC 97) y el `422` reutiliza
  `invalid_field`.
- **No hay `hook_update_N()` ni cambio de esquema.** El endpoint solo lee.
- **No se oferta desde aquí.** Ni `POST`, ni `PUT`, ni ningún cambio de estado:
  crear, retirar o adjudicar ofertas es otra spec. Este endpoint **solo lee**.
- **`?unit_id` no existe en esta ruta.** Un parámetro para filtrar por vivienda
  contradice la decisión 5; se **ignora en silencio**, no da `422`.
- **`?provider_id` acepta un solo id, nunca una lista.** `?provider_id=1,2` es un
  valor malformado → `422`.
- **No se toca `myapi_provider_role_visible_request_ids()` ni
  `myapi_provider_role_alter_node_query()`.** El back office de Drupal conserva
  su regla; este spec solo escribe la de la API. Queda como riesgo documentado.
- **No se toca `myapi_service_request_build_item()`.** Las once claves del ítem
  del residente no pueden divergir.
- **La respuesta del residente no cambia.** `GET /api/v1/service-requests`
  devuelve byte a byte lo mismo, con los mismos parámetros y la misma
  paginación.
- **No viajan datos de contacto.** `requester` es `{id, name}` y nada más, la
  misma regla del SPEC 89: `myapi_user_fetch_profile_fields()` no se llama desde
  este recurso.
- **No viajan imágenes ni adjunto.** Ni `images`, ni `attachment`: eso es el
  detalle.
- **No hay clave `my_offer`.** Saber si ya oferté y por cuánto es el detalle; el
  listado responde la forma que el cliente pidió, más solicitante y condominio.
- **No se notifica nada.** Ni OneSignal, ni correo: es un `GET`.
- **Sin caché, sin `ETag`, sin `304`**, igual que el resto del módulo.
- **No se valida la coherencia rol ↔ vínculo**, igual que el SPEC 97.

---

## Modelo de datos

**No hay estructura persistente nueva.** El endpoint solo lee, y no añade ni un
campo ni una clave de i18n. Lo que este spec sí define es **la regla de acceso
como conjunto**, la forma de la respuesta y el efecto de `?provider_id`.

### La regla de acceso: A ∪ B ∪ C

Con `P` = los proveedores de la cuenta (o el único que `?provider_id`
seleccione), una solicitud le concierne al lector si está en cualquiera de estos
tres conjuntos:

| | Conjunto | Definición | De dónde sale |
|---|---|---|---|
| **A** | *Ya oferté* | `service_offer` publicada con `field_provider ∈ P` apuntando a la solicitud. **Cualquier estado, cualquier categoría** — incluso una que ya no atiendo. | `myapi_provider_role_offered_request_ids(P)`, SPEC 78, sin tocar. |
| **B** | *El mercado* | `status ∈ ('open','offered')` **∧** `field_assigned_offer` vacío **∧** `field_assigned_provider` vacío **∧** `field_category_tid ∈` categorías de `P` **∧** algún proveedor de `P` activo. | La **regla 3 de `myapi_service_request_viewer()`** (SPEC 89), condición por condición. |
| **C** | *Mis trabajos* | `field_assigned_provider ∈ P`. **Cualquier estado**, `closed` y `cancelled` incluidos. | `myapi_provider_role_assigned_request_ids(P)`, nueva. |

Tres precisiones que son el contrato, no detalles:

- **B usa la lista del SPEC 89, no `myapi_provider_role_broadcast_statuses()`.**
  Esa incluye `direct`, y B tiene que excluirlo: una `direct` nace adjudicada,
  que es justo lo que "sin adjudicar" descarta. Un `direct` **mío** entra
  igualmente — por C. Un `direct` **ajeno** no entra por ninguna vía, que es lo
  correcto.
- **`offered` sí está en B.** Una solicitud con ofertas de terceros pero sin
  adjudicar **sigue siendo pujable**, o solo el primero que llega la vería nunca.
- **C es lo que arregla el `403` del SPEC 89.** Con la regla 2b, listado y
  detalle usan la misma definición: **si sale en esta lista, se puede abrir**.
  Esa equivalencia es un criterio de aceptación.

### Qué hace `?provider_id`

`?provider_id` **no añade una condición**: cambia `P`.

| Valor | `P` | Resultado |
|-------|-----|-----------|
| Ausente | Todos los proveedores de la cuenta | La unión completa. |
| Un id **de la cuenta** | `[ese id]` | A ∪ B ∪ C calculados solo para él: sus ofertas, sus categorías (y **su** licencia, no la de un hermano activo), sus adjudicaciones. |
| Un id **ajeno o inexistente** | `[]` | `200` con lista vacía y `total: 0`. Sin `403`, sin consulta extra. |
| Malformado (`abc`, `1,2`, `-1`) | — | `422 invalid_field`, **antes de cualquier consulta**. |

La propiedad que hace correcta la decisión 8: **la unión de los resultados de
`?provider_id` de cada proveedor de la cuenta es exactamente la lista sin
filtro.** Ninguna solicitud se pierde y ninguna aparece dos veces.

### La respuesta

Misma envolvente que el listado del residente, con la misma clave y el mismo
bloque de paginación:

```json
{
  "success": true,
  "data": {
    "service_requests": [],
    "pagination": { "total": 0, "page": 1, "limit": 20, "total_pages": 0 }
  }
}
```

### El ítem: 13 claves, siempre las 13, en este orden

Las **once primeras** las produce `myapi_service_request_build_item()`
(SPEC 88/91) **sin modificar**: mismos tipos, mismos `null`, mismo orden que en
`GET /api/v1/service-requests`. Las **dos últimas** son de este spec, y van al
final por la misma razón que en el SPEC 97 — así el constructor compartido no
puede divergir.

| Campo | Tipo | Nota |
|-------|------|------|
| `id` | int | `node.nid`. |
| `title` | string | `node.title`. |
| `description` | string | `field_description`; `""` si vacío. |
| `status` | string \| null | Clave del catálogo: `open`, `direct`, `offered`, `assigned`, `closed`, `cancelled`. |
| `category` | object | `{id, code, name}`. |
| `unit` | object \| null | **Condicionado al lector — ver abajo.** |
| `offers_count` | int | **El total real**, competencia incluida (decisión 10). |
| `assigned_offer` | object \| null | `{id, status}`. |
| `assigned_provider` | object \| null | `{id, name}`. **Sin enmascarar**, aunque el ganador sea un rival (decisión 10). |
| `created` | string | `Y-m-d\TH:i:s`. |
| `desired_start` | string \| null | `Y-m-d\TH:i:s`. |
| **`requester`** | **object \| null** | `{id, name}`. `name` = `"$field_nombre $field_apellidos"`, por `myapi_user_display_names()`. |
| **`condominium`** | **object \| null** | `{id, name}`. `name` es el **título del nodo** `condominio`, no un campo. |

`requester` y `condominium` son **un `null` entero, nunca
`{id: null, name: null}`**, la misma forma que `unit`, `assigned_offer` y
`assigned_provider` ya usan. `requester` es `null` solo por dato corrupto (el
join es INNER, así que en la práctica no ocurre); `condominium` es `null` si la
referencia está vacía o el nodo fue borrado o despublicado.

### La regla de la `unit` (decisión 5)

```
unit = (assigned_provider.id ∈ proveedores de la cuenta) ? {id, name} : null
```

- **Se calcula contra los proveedores de la cuenta, no contra `P`.** Con
  `?provider_id=A`, una solicitud adjudicada a mi proveedor B que aparece por
  haber ofertado A sigue mostrando la unidad: ya voy a ir a esa casa, y filtrar
  la vista no cambia lo que sé.
- **`assigned_provider` es la clave ya construida**, resuelta contra `node` con
  bundle y `status = 1`. Una adjudicación a un nodo borrado da
  `assigned_provider: null` y por tanto `unit: null`: se falla hacia el lado
  cerrado.
- **La clave viaja siempre**, con valor `null` cuando no toca. Un cliente que la
  recibe unas veces sí y otras no tendría que distinguir "ausente" de "null", que
  aquí significan lo mismo.

Esto **matiza** la decisión del SPEC 89, no la revierte: allí `unit` es `null`
para *todo* lector proveedor porque el detalle se lee sobre todo antes de pujar.
Aquí la dirección aparece exactamente cuando el trabajo ya es tuyo. El
`condominium`, en cambio, **viaja siempre**: dice la zona sin decir la puerta.

### Parámetros de query string

| Parámetro | Tratamiento | Ante un valor inválido |
|-----------|-------------|------------------------|
| `page` | Entero ≥ 1; por defecto `1`. | Laxo → `1`. |
| `limit` | 1–50; `-1` = todo en una página (SPEC 15); por defecto `20`. | Laxo → `20`. |
| `sort` | `asc` \| `desc` sobre `node.created`; por defecto `desc`. | Laxo → `desc`. |
| `status` | Lista separada por comas, validada contra `myapi_services_request_statuses()`. | Laxo: la clave desconocida se descarta. |
| `category_id` | Un tid. **Estricto en formato.** | `422 invalid_field`. |
| `provider_id` | Un nid de proveedor. **Estricto en formato, laxo en pertenencia.** | `422` si malformado; lista vacía si ajeno. |
| `date_from` / `date_to` | Rango sobre `node.created`, por el parser compartido. | Según `myapi_service_request_parse_created_range()`. |
| `unit_id` | **No existe en esta ruta.** | Se ignora en silencio. |
| Cualquier otro | Se ignora en silencio. | Nunca `422`. |

---

## Plan de implementación

Nueve pasos. Cada uno deja el módulo funcionando: los cinco primeros añaden
código que todavía nadie llama, el sexto lo enciende.

### 1. Los helpers del rol

En `includes/myapi.provider_role.inc`:

**Refactor sin cambio de comportamiento.** El cuerpo de
`myapi_provider_role_category_ids()` y el de
`myapi_provider_role_any_provider_active()` se extraen a
`myapi_provider_role_category_ids_for_providers(array $provider_ids)` y
`myapi_provider_role_any_provider_active_for_providers(array $provider_ids)`.
Las dos funciones originales quedan así:

```php
function myapi_provider_role_category_ids($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }
  return myapi_provider_role_category_ids_for_providers(
    myapi_provider_role_provider_ids($account)
  );
}
```

La caché estática se mueve **con el cuerpo** y sigue indexada por
`implode(',', $provider_ids)`, que es como ya estaba: cero lecturas nuevas, y
`ProviderRoleTest` pasa sin tocar una línea.

**Función nueva, `myapi_provider_role_assigned_request_ids(array $provider_ids)`**
— gemela exacta de `myapi_provider_role_offered_request_ids()`:

- Lista vacía → `[]` **sin lanzar consulta** (y eso es también lo que impide
  construir un `IN ()`, que en D7 es SQL inválido).
- `db_select('field_data_field_assigned_provider', 'fap')`,
  `entity_type = 'node'`, `deleted = 0`,
  `field_assigned_provider_target_id IN (:provider_ids)`, proyectando
  `fap.entity_id`.
- **INNER JOIN a `node`** con `n.type = MYAPI_SERVICES_REQUEST_TYPE AND
  n.status = 1`. No es decorativo: `field_assigned_provider` está instanciado
  solo en `service_request` hoy, pero la función no debe depender de eso — es la
  misma disciplina que el docblock de
  `myapi_service_request_offer_counts_by_nid()` explica para `field_request`.
- **Sin filtro de estado**: ese es el punto de C.
- Caché estática por conjunto de ids, igual que su gemela.

### 2. El alcance y el parser

En `resources/service_request.resource.inc`:

**`myapi_service_request_parse_provider_id()`** — una línea sobre
`myapi_service_request_parse_id_param('provider_id')`, con el docblock
explicando por qué es estricto en formato y laxo en pertenencia (SPEC 91).

**`myapi_service_request_provider_scope(array $provider_ids)`** — devuelve los
tres ingredientes de A ∪ B ∪ C, sin ejecutar todavía la consulta del listado:

```php
return [
  'nids'         => array_values(array_unique(array_merge(
    myapi_provider_role_offered_request_ids($provider_ids),   // A
    myapi_provider_role_assigned_request_ids($provider_ids)   // C
  ))),
  'category_ids' => $biddable ? myapi_provider_role_category_ids_for_providers($provider_ids) : [],
  'biddable'     => $biddable,   // myapi_provider_role_any_provider_active_for_providers()
];
```

`$provider_ids` vacío → los tres vacíos, sin una sola consulta.

**`biddable` se resuelve antes que `category_ids`** y lo apaga: si ningún
proveedor de `P` está activo, B no existe y las categorías no hacen falta. Una
consulta menos en el caso del proveedor suspendido.

### 3. El filtro en la consulta compartida

**`myapi_service_request_base_query()`** acepta un filtro nuevo,
`provider_scope`, con la forma del paso 2. **Ausente o `NULL` → la consulta es
exactamente la de hoy**, sin una condición ni un join de más: el listado del
residente no paga nada por esto.

Presente, añade **un solo grupo `OR`**:

```php
$or = db_or();

if (!empty($scope['nids'])) {
  $or->condition('n.nid', $scope['nids'], 'IN');          // A ∪ C
}

if (!empty($scope['biddable']) && !empty($scope['category_ids'])) {
  $b = db_and();
  $b->condition('frs.field_request_status_value', [
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
    ], 'IN');
  $b->isNull('sfao.field_assigned_offer_target_id');
  $b->isNull('sfap.field_assigned_provider_target_id');
  $b->condition('fcat.field_category_tid', $scope['category_ids'], 'IN');
  $or->condition($b);
}

$query->condition($or);
```

**Por qué A ∪ C van como lista y B como SQL.** A y C están acotados por la
actividad de la propia cuenta —las ofertas que mandé y los trabajos que me
dieron—, así que el `IN` tiene decenas o cientos de elementos y no crece con el
tamaño del sistema. B sí crecería: todas las abiertas de una categoría popular
pueden ser miles, y resolverlas a nids en PHP para devolverlas como `IN` haría la
consulta más lenta cuanto mejor funcione el marketplace, además de romper la
paginación de golpe.

**Alias propios, `sfao` y `sfap`.** Los dos LEFT JOIN que B necesita se hacen
**en `base_query` y con alias distintos** de los `fao`/`fap` que `fetch()` usa.
Es una segunda unión a las mismas dos tablas en la consulta de página, y se paga
a propósito: mover los joins de `fetch()` a la base cambiaría la consulta del
residente (y su `countQuery`), que es justo lo que este spec promete no hacer.
Reusar los alias sería una colisión.

**Ojo con el grupo vacío.** Si `nids` está vacío **y** B está apagado, el `OR` no
tiene condiciones y Drupal 7 lo compila a nada, es decir, **devolvería todas las
solicitudes del sistema**. Esa combinación no puede llegar aquí: el paso 6 corta
antes con `200` y lista vacía. Aun así la función lo comprueba y, si el alcance
está vacío, añade `n.nid IN (0)` — una condición imposible. Un fallo de este
código tiene que responder *nada*, nunca *todo*.

**`myapi_service_request_count()` no se toca**: ya delega en `base_query()`, así
que hereda el filtro. Y como B vive en SQL, `total` describe el conjunto real, no
una página.

**`myapi_service_request_fetch()`** añade tres proyecciones y dos joins:

```php
$query->addField('fr', 'field_requester_target_id', 'requester_uid');   // join ya hecho: coste cero

$query->leftJoin('field_data_field_condominium', 'fco', "fco.entity_id = n.nid AND fco.entity_type = 'node' AND fco.deleted = 0");
$query->leftJoin('node', 'nc', "nc.nid = fco.field_condominium_target_id AND nc.type = 'condominio' AND nc.status = 1");
$query->addField('nc', 'nid', 'condominium_id');
$query->addField('nc', 'title', 'condominium_name');
```

Byte a byte los del detalle (`myapi_service_request_detail_row()`), con los
mismos alias y el mismo bundle en el join. **`myapi_service_request_build_item()`
no lee ninguna de las tres**, así que la respuesta del residente no cambia: lo
único que cambia es que su consulta trae dos columnas que nadie mira.

### 4. El constructor del ítem

**`myapi_service_request_provider_build_item($row, array $offer_counts, array $owned_provider_ids)`**:

```php
$item = myapi_service_request_build_item($row, $offer_counts);

// La unidad, solo si el trabajo ya es mío (decisión 5). Se compara contra
// assigned_provider YA CONSTRUIDA — resuelta contra node con bundle y
// status = 1 — y no contra la columna cruda: una adjudicación a un nodo
// borrado tiene que fallar hacia el lado cerrado.
$assigned = $item['assigned_provider'];
$mine = $assigned !== NULL
  && in_array((int) $assigned['id'], array_map('intval', $owned_provider_ids), TRUE);

if (!$mine) {
  $item['unit'] = NULL;
}

$item['requester'] = ...;      // {id, name} o NULL entero
$item['condominium'] = ...;    // {id, name} o NULL entero

return $item;
```

`$owned_provider_ids` son **los de la cuenta**, no los de `?provider_id`. Los dos
objetos nuevos se construyen con la misma forma que
`myapi_service_request_build_detail()` ya usa, para que las dos respuestas no
puedan divergir. `requester['name']` se lee de `$row->requester_name`, que el
paso 6 escribe sobre la fila.

### 5. La regla 2b del detalle

En `myapi_service_request_viewer()`, entre la regla 2 y la 3:

```php
// Regla 2b (SPEC 98). La solicitud está adjudicada a uno de mis proveedores:
// es mi trabajo, y lo es en cualquier estado — 'direct', 'assigned' y
// 'closed' incluidos. Cierra el 403 que el SPEC 89 dejó escrito en sus
// Riesgos: hasta hoy el proveedor de una 'direct' no podía leer su detalle.
if (!empty($row->assigned_provider_raw)
  && in_array((int) $row->assigned_provider_raw, array_map('intval', $provider_ids), TRUE)) {
  return 'provider';
}
```

Va **antes** de la regla 3 porque la 3 exige "sin adjudicar", que es lo contrario
de esta. Se apoya en `assigned_provider_raw`, la columna cruda que
`myapi_service_request_detail_row()` ya proyecta y que la regla 3 ya lee: **cero
consultas nuevas** y cero columnas nuevas.

Es **estrictamente aditiva**: solo puede convertir un `NULL` (403) en
`'provider'`. Ningún lector pierde acceso, y para el requester no se llega a
evaluar.

Con esto, `myapi_service_request_provider_scope()` y
`myapi_service_request_viewer()` responden lo mismo sobre la misma solicitud.
**Es la misma regla escrita dos veces en dos formas** (conjunto vs. decisión por
fila), lo mismo que el SPEC 97 aceptó con `is_active`, y con la misma
mitigación: un criterio de aceptación que exige la equivalencia.

### 6. El endpoint

**`myapi_service_request_provider_list()`**, en orden estricto:

1. `myapi_auth_require_access_token()` → `401`. Se guarda el `uid`.
2. `user_load($uid)` y **la compuerta**:
   `if (!myapi_provider_role_is($account))` →
   `myapi_error('provider_role_required', 403)`. Única causa de `403`, misma
   clave que el SPEC 97.
3. `$owned = myapi_provider_role_provider_ids($account)`.
4. `$provider_id = myapi_service_request_parse_provider_id()` → `422` si está
   malformado. **Va después de la compuerta pero antes de todo lo demás**: un
   `422` no debe costar una consulta de alcance.
5. `$scope_ids = $provider_id === NULL ? $owned : (in_array($provider_id, $owned) ? [$provider_id] : []);`
6. **`$scope_ids` vacío → `myapi_respond()` con `service_requests: []` y
   `pagination: {total: 0, page: 1, limit: $limit, total_pages: 0}`, y se sale.**
   Cubre los dos casos de golpe: rol sin vínculo (SPEC 97) y `?provider_id`
   ajeno (decisión 9).
7. Paginación, `sort` y filtros, idénticos a `myapi_service_request_list()`
   **salvo `unit_id`**, que no se lee.
8. `$scope = myapi_service_request_provider_scope($scope_ids)`. **Alcance vacío →
   misma respuesta vacía del paso 6**, sin contar ni paginar.
9. `myapi_service_request_count(NULL, $filters)` y
   `myapi_service_request_fetch(NULL, $filters, ...)` — **`$uid` es `NULL`**: el
   lector no es el solicitante de nada, y el alcance ya viaja dentro de
   `$filters['provider_scope']`. Es exactamente para lo que el SPEC 88 dejó ese
   parámetro anulable.
10. `myapi_service_request_offer_counts_by_nid($nids)` — una consulta para la
    página entera, la del SPEC 88 sin tocar.
11. **Los nombres, en UNA consulta**: `myapi_user_display_names()` con los
    `requester_uid` únicos de la página, y el resultado se escribe en
    `$row->requester_name`. Nunca uno por fila.
12. Construir los ítems y `myapi_respond([...], 200)`.

**`myapi_service_request_provider_dispatch()`** — `GET` llama al listado;
cualquier otro método es `myapi_error('method_not_allowed', 405)` **antes** de
mirar el token.

**Coste: diez consultas fijas** —token, cuenta, vínculo, activo, categorías, A,
C, conteo, página, ofertas, nombres— y **ninguna crece con el número de filas de
la página**.

### 7. La ruta

En `hook_menu()` de `myapi.module`, **antes** de `api/v1/service-requests/%`:

```php
$items['api/v1/service-requests/provider'] = [
  'page callback'   => 'myapi_service_request_provider_dispatch',
  'access callback' => TRUE,
  'type'            => MENU_CALLBACK,
];
```

```bash
drush cc all
```

Sin esto la ruta no existe. `drush updb` no tiene nada que ejecutar: no hay
esquema.

### 8. Los tests

`tests/unit/ServiceRequestProviderListTest.php`, sobre funciones puras y
fixtures de fila:

- **La equivalencia listado ↔ detalle**: para cada combinación de estado ×
  adjudicación × categoría × ofertado, la pertenencia a A ∪ B ∪ C y el veredicto
  de `myapi_service_request_viewer()` coinciden. Es el test que sujeta la
  decisión 7.
- La regla 2b: una `direct` adjudicada a mi proveedor da `'provider'`;
  adjudicada a otro, `NULL`.
- `unit` es `null` salvo que `assigned_provider.id` esté en los proveedores de
  la cuenta; con `assigned_provider: null` es `null`.
- `unit` **sí viaja** con `?provider_id=A` en una solicitud adjudicada a mi
  proveedor B.
- El ítem tiene **exactamente 13 claves, en el orden documentado**, y las once
  primeras son idénticas a las de `myapi_service_request_build_item()` sobre la
  misma fila.
- `requester` y `condominium` son `null` **enteros**, nunca
  `{id: null, name: null}`.
- `myapi_provider_role_assigned_request_ids([])` devuelve `[]` sin consultar.
- El alcance vacío produce una condición imposible y **nunca** un `OR` sin
  condiciones.
- `?provider_id` malformado (`abc`, `1,2`, `-1`, `0`) es `422`.
- `ServiceRequestListTest`, `ServiceRequestDetailTest` y `ProviderRoleTest`
  siguen en verde **sin una línea modificada** — el refactor del paso 1 y las
  proyecciones del paso 3 no cambian nada de lo suyo.

### 9. La documentación

`docs/service-request-provider.md` nuevo con la plantilla de `CLAUDE.md`,
incluida la tabla A ∪ B ∪ C y la regla de la `unit`. Los enlaces cruzados en
`docs/service-request.md` (cabecera y sección de `403` del detalle, que ahora
tiene una regla más) y en `docs/provider-mine.md`. En el mismo commit que el
código: un endpoint sin doc está incompleto.

---

## Criterios de aceptación

Checklist booleano. Cada línea se comprueba con una petición HTTP o ejecutando
`vendor/bin/phpunit`.

### Autorización y método

- [ ] `GET /api/v1/service-requests/provider` sin cabecera `Authorization`
      responde `401` con `error_code: "missing_authorization"`.
- [ ] Con un token revocado, caducado o inexistente responde `401` con
      `error_code: "invalid_token"`.
- [ ] Con un token válido de una cuenta **sin** el rol `proveedor` responde `403`
      con `error_code: "provider_role_required"` — la misma clave que
      `GET /api/v1/providers/mine`, sin ninguna clave i18n nueva.
- [ ] Una cuenta con rol `administrator` y **sin** `proveedor` recibe el mismo
      `403`. No hay excepción para administradores.
- [ ] Una cuenta con rol `proveedor` y **ningún** nodo vinculado en
      `field_provider_users` recibe `200` con `service_requests: []` y
      `pagination.total: 0` — nunca `403` ni `404`.
- [ ] Un residente sin rol `proveedor` que además es solicitante recibe `403`:
      sus solicitudes propias no aparecen por esta ruta.
- [ ] `POST`, `PUT`, `DELETE` y `PATCH` sobre la ruta responden `405` con
      `error_code: "method_not_allowed"`, **sin cabecera `Authorization`
      incluida**: el `405` llega antes que el `401`.

### El alcance A ∪ B ∪ C

- [ ] Una solicitud `open` de una categoría del proveedor, sin adjudicar,
      **aparece** (B).
- [ ] Una solicitud `offered` sin adjudicar, de una categoría del proveedor,
      **aparece** (B): tener ofertas de terceros no la retira del mercado.
- [ ] Una solicitud `open` de una categoría que el proveedor **no** atiende **no
      aparece**.
- [ ] Una solicitud `direct` **ajena** (adjudicada a otro proveedor) **no
      aparece**, aunque sea de mi categoría.
- [ ] Una solicitud `direct` **adjudicada a mi proveedor aparece** (C).
- [ ] Una solicitud `assigned`, `closed` o `cancelled` **adjudicada a mi
      proveedor aparece** (C), en los tres estados.
- [ ] Una solicitud en la que **oferté y perdí** (adjudicada a un tercero, estado
      `assigned` o `closed`) **aparece** (A).
- [ ] Una solicitud en la que oferté y cuya **categoría ya no atiendo**
      **aparece** (A): A es independiente de la categoría y del estado.
- [ ] Con **todos** los proveedores de la cuenta suspendidos o con licencia
      caducada, B desaparece: solo quedan las de A y C. La lista **no** es un
      `403`.
- [ ] Con dos proveedores, uno activo y otro no, B usa las categorías de
      **ambos** cuando no hay `?provider_id` — la misma lectura de unión que
      `myapi_provider_role_any_provider_active()` ya hace.
- [ ] Una solicitud **despublicada** (`node.status = 0`) no aparece por ninguna
      de las tres vías.
- [ ] Ninguna solicitud aparece **dos veces**, aunque cumpla A, B y C a la vez.

### La equivalencia con el detalle (decisión 7)

- [ ] **Para toda solicitud de la lista, `GET /api/v1/service-requests/{id}`
      responde `200` con ese mismo token.** Ni un `403`.
- [ ] Recíprocamente, una solicitud que da `200` en el detalle a un token
      proveedor está en la lista de ese token (salvo que ese token sea además su
      solicitante, caso que esta ruta no cubre).
- [ ] El proveedor de una solicitud `direct` puede leer su detalle: el `403`
      documentado en los Riesgos del SPEC 89 ya no ocurre.
- [ ] `GET /api/v1/service-requests/{id}/files/{fid}` sigue la misma regla
      ampliada: el proveedor adjudicado descarga las fotos de su trabajo.
- [ ] Ningún lector que antes recibía `200` en el detalle recibe ahora `403`. La
      regla 2b solo amplía.
- [ ] El residente solicitante sigue leyendo su detalle exactamente igual que
      antes.

### `?provider_id`

- [ ] `?provider_id` con el nid de un proveedor de la cuenta acota los tres
      conjuntos a ese proveedor: sus ofertas, sus categorías y sus
      adjudicaciones.
- [ ] Con un proveedor **suspendido** en `?provider_id`, B desaparece aunque un
      hermano de la cuenta esté activo: la licencia que cuenta es la del
      proveedor seleccionado.
- [ ] **La unión de los resultados de `?provider_id` de cada proveedor de la
      cuenta es exactamente la lista sin filtro**, sin faltar ni repetir ninguna.
- [ ] `?provider_id` con un nid que **no es de la cuenta** responde `200` con
      lista vacía y `total: 0`. No `403`, no `404`, y **sin consulta de
      alcance**.
- [ ] `?provider_id` con el nid de un nodo que no es un proveedor, o que no
      existe, responde igual: `200` con lista vacía.
- [ ] `?provider_id=abc`, `?provider_id=1,2`, `?provider_id=-1` y
      `?provider_id=0` responden `422` con `error_code: "invalid_field"`,
      **antes de cualquier consulta**.
- [ ] `?provider_id` ausente devuelve la unión de todos los proveedores de la
      cuenta.

### Contenido del ítem

- [ ] Cada ítem tiene **exactamente 13 claves**, en el orden: `id`, `title`,
      `description`, `status`, `category`, `unit`, `offers_count`,
      `assigned_offer`, `assigned_provider`, `created`, `desired_start`,
      `requester`, `condominium`.
- [ ] Las **once primeras** son byte a byte iguales a las que
      `GET /api/v1/service-requests` devuelve para esa misma solicitud a su
      solicitante, salvo `unit` cuando la regla de la decisión 5 la anula.
- [ ] `requester` es `{id, name}` con `name = "$field_nombre $field_apellidos"`,
      el mismo valor que `myapi_user_display_names()` devuelve y que el detalle
      ya pinta.
- [ ] **No viaja ningún dato de contacto**: `requester` no lleva `email`,
      `phone`, `cedula` ni `username`.
- [ ] Un solicitante sin `field_nombre` o sin `field_apellidos` cae al mismo
      respaldo que `myapi_user_display_names()` ya aplica, y el ítem no se rompe.
- [ ] `condominium` es `{id, name}` con `name` = el **título del nodo**
      `condominio`, y viaja en **todos** los ítems, adjudicados o no.
- [ ] `condominium` es un `null` **entero** —nunca `{id: null, name: null}`—
      cuando la referencia está vacía o el nodo fue borrado o despublicado, y la
      solicitud **conserva su sitio en la lista**.
- [ ] `requester` es un `null` entero en el mismo caso.
- [ ] `offers_count` es el **total real de ofertas publicadas**, incluidas las de
      la competencia, y no `0` ni `null` en una solicitud abierta ajena.
- [ ] `offers_count` **no** cuenta filas de `service_transaction`: una solicitud
      con tres cambios de estado y cero ofertas responde `0`.
- [ ] `assigned_provider` nombra al ganador **aunque no sea uno de mis
      proveedores**.

### La regla de la `unit` (decisión 5)

- [ ] Una solicitud **adjudicada a uno de mis proveedores** trae
      `unit: {id, name}`.
- [ ] Una solicitud `open` de mi categoría, sin adjudicar, trae **`unit: null`**.
- [ ] Una solicitud adjudicada a **otro** proveedor —incluso una en la que
      oferté— trae `unit: null`.
- [ ] Una solicitud cuya adjudicación apunta a un nodo borrado o despublicado
      (`assigned_provider: null`) trae `unit: null`.
- [ ] Con `?provider_id=A`, una solicitud adjudicada a **mi otro proveedor B**
      que aparece por A **sí** trae la unidad: la regla se evalúa contra los
      proveedores de la cuenta, no contra `?provider_id`.
- [ ] La clave `unit` **está siempre presente**, con valor `null` cuando no toca.
      Nunca se omite.

### Paginación, filtros y orden

- [ ] Sin parámetros, la respuesta trae `limit: 20`, `page: 1` y las solicitudes
      más recientes primero (`node.created DESC`, desempate por `nid DESC`).
- [ ] `pagination.total` describe el **conjunto filtrado completo**, no la
      página, y `total_pages` es `0` —no `1`— cuando no hay resultados.
- [ ] `?limit=-1` devuelve todo en una página, con `page: 1` forzado.
- [ ] `?limit=999` cae a `50`; `?limit=abc` y `?page=0` caen a los valores por
      defecto sin `422`.
- [ ] `?sort=asc` invierte el orden; un valor cualquiera cae a `desc`.
- [ ] `?status=closed` sobre la lista de un proveedor con trabajos cerrados
      devuelve solo esos; `?status=open,offered` devuelve el mercado.
- [ ] `?status=inventado` se descarta en silencio y la respuesta es la de sin
      filtro; nunca `422`.
- [ ] `?category_id` malformado responde `422 invalid_field`; un tid válido que
      no atiendo intersecta en la lista vacía sin error.
- [ ] `?date_from` y `?date_to` acotan por `node.created` con el mismo parser que
      el listado del residente.
- [ ] **`?unit_id=30057` se ignora en silencio**: la respuesta es idéntica a la
      de la ruta sin ese parámetro, y no es `422`.
- [ ] Una página más allá de la última responde `200` con lista vacía, no `404`.

### No regresión

- [ ] `GET /api/v1/service-requests` responde **byte a byte lo mismo** que antes
      de este spec, con los mismos parámetros, la misma paginación y las mismas
      once claves por ítem.
- [ ] La consulta del residente **no gana ni una condición ni un join**: sin
      `provider_scope`, `myapi_service_request_base_query()` produce el mismo SQL
      que hoy.
- [ ] `myapi_service_request_build_item()` no tiene ni una línea modificada.
- [ ] `myapi_provider_role_category_ids()` y
      `myapi_provider_role_any_provider_active()` devuelven exactamente lo mismo
      que antes del refactor, y con el mismo número de consultas.
- [ ] `GET /api/v1/providers/mine`, `GET /api/v1/providers` y
      `GET /api/v1/service-categories` responden igual que antes.
- [ ] `GET /api/v1/service-requests/provider` no colisiona con
      `GET /api/v1/service-requests/{id}`: el detalle por nid sigue respondiendo
      lo mismo.

### Seguridad

- [ ] **Un alcance vacío nunca produce un `OR` sin condiciones.** Un test
      comprueba que la consulta lleva una condición imposible y no devuelve el
      sistema entero.
- [ ] Ningún parámetro entra en el SQL sin pasar por el placeholder de
      `db_select()`.
- [ ] Un proveedor **no** ve solicitudes de un condominio en el que ninguna de
      sus categorías tiene trabajo abierto ni adjudicado.

### Código y despliegue

- [ ] `myapi.info` no tiene ni una línea modificada.
- [ ] `includes/myapi.i18n.inc` y `docs/i18n.md` no tienen ni una línea
      modificada.
- [ ] No hay `hook_update_N()` nuevo: `drush updb` no tiene nada que ejecutar.
- [ ] Tras `drush cc all` la ruta responde; sin `drush cc all` responde el 404 de
      Drupal.
- [ ] Una petición completa consume **diez consultas** y **no crece con el número
      de filas de la página**: veinte solicitudes cuestan lo mismo que una.
- [ ] `vendor/bin/phpunit` pasa en verde, incluidos
      `ServiceRequestProviderListTest`, `ServiceRequestListTest`,
      `ServiceRequestDetailTest`, `ProviderRoleTest` y `ProviderMineEndpointTest`.
- [ ] `docs/service-request-provider.md` existe y documenta los `401`, `403`,
      `405` y `422`.

---

## Decisiones

### 1. El alcance es "mercado ∪ mis trabajos", no solo el mercado

**Descartadas:** (a) usar la regla del detalle (SPEC 89) tal cual, y (b) usar
`myapi_provider_role_visible_request_ids()`.

La (b) se cayó sola: esa función incluye `direct` por categoría pero no
"adjudicada a mí", así que produciría una lista con solicitudes ajenas que al
pulsar dan `403` y sin los trabajos propios ya cerrados. La (a) era coherente
pero incompleta: un proveedor necesita ver el trabajo que ya le adjudicaron, no
solo aquello a lo que aún puede pujar, y con la regla del detalle una `direct`
suya no aparecía en ninguna parte.

El conjunto C —`field_assigned_provider ∈ P`, cualquier estado— es lo que
convierte el endpoint en "mi trabajo" además de "mi mercado".

### 2. Una sola lista, no dos endpoints

**Descartado:** `/market` y `/my-jobs` por separado.

Las dos mitades comparten el ítem, la paginación, los filtros y la autorización;
lo único que cambia es qué conjunto entra. Partirlas duplicaría todo eso para que
la app pinte dos pestañas que `?status` ya distingue, y obligaría a mantener dos
definiciones de acceso en vez de una — que es exactamente el problema que la
decisión 7 existe para cerrar.

**Consecuencia asumida:** la app tiene que saber que `?status=open,offered` es
"el mercado" y el resto "mis trabajos". Va documentado.

### 3. La ruta es `api/v1/service-requests/provider`

**Descartadas:** `api/v1/service-requests/available` y
`api/v1/service-requests?scope=provider`.

`available` describía el conjunto B y dejó de ser cierto en cuanto entró C: una
solicitud cerrada no está "disponible". El `?scope=` mete dos autorizaciones
distintas —residente y proveedor— en la misma función, que es literalmente lo que
el SPEC 97 descartó al elegir `providers/mine` sobre `providers?mine=1`.

La ruta literal convive con el comodín `%` porque el router de Drupal 7 resuelve
primero la parte literal, el mismo supuesto —documentado del núcleo— del que ya
depende `api/v1/providers/mine`.

### 4. Todos los estados, `cancelled` incluido

**Descartados:** excluir `cancelled`, y quedarse solo con los estados vivos.

El histórico es lo que hace útil `?status`: si el servidor ya recorta, el filtro
solo puede recortar más, y un proveedor que quiere revisar qué pasó con un
trabajo de hace tres meses se queda sin respuesta. Recortar en el cliente es
reversible; recortar en el servidor no.

### 5. `unit` solo cuando el trabajo ya es mío

**Descartadas:** mandarla siempre (y revertir la decisión del SPEC 89), y no
mandarla nunca.

El SPEC 89 anula `unit` para todo lector proveedor con una razón que sigue siendo
válida: *"el número de piso no aporta nada a la decisión de pujar y sí dice dónde
vive una persona concreta, a cualquiera de la categoría"*. Mandarla siempre desde
el listado anularía esa decisión por la puerta de atrás y sin discutirla.

Pero cuando la solicitud está adjudicada a uno de mis proveedores, el argumento
se invierte: **voy a ir a esa casa**, la dirección es el dato operativo del
trabajo, y ocultarla obligaría a un segundo canal para algo que la API ya tiene.
La regla es entonces una sola línea —`assigned_provider.id ∈ mis proveedores`— y
no un permiso nuevo.

Esto **matiza** el SPEC 89 en vez de revertirlo, y el detalle debería seguirlo en
su día. Hoy no lo hace: queda como riesgo.

### 6. El condominio viaja siempre

Es la petición explícita del cliente y no tiene el problema de la unidad: **dice
la zona sin decir la puerta**. Un proveedor que ve "Residencial Los Almendros"
sabe si el trabajo le queda a mano antes de pujar; no sabe en qué apartamento
vive nadie.

Su forma es la del detalle, `{id, name}`, con `name` = título del nodo — y no un
campo, porque el bundle `condominio` no tiene uno de nombre.

### 7. El listado y el detalle comparten la definición de acceso

Este spec **amplía `myapi_service_request_viewer()`** con la regla 2b en vez de
dejar el `403` documentado como limitación.

**Descartados:** dejar el `403` y avisar en la doc, y sacarlo a una spec 99.

Un listado cuyos ítems no se pueden abrir es peor que no tener el ítem: la app
tendría que replicar la regla de acceso del servidor para saber qué enlace
pintar, que es exactamente lo que un endpoint existe para evitar. Y el `403` del
`direct` ya estaba escrito en los Riesgos del SPEC 89 como pendiente de *"la spec
que sepa qué relación tiene el proveedor de una `direct` con la solicitud"*: es
esta.

La ampliación es **solo aditiva** —convierte `NULL` en `'provider'`, nunca al
revés—, así que ningún cliente pierde nada.

### 8. `?provider_id` cambia `P`, no añade una condición

**Descartados:** filtrar solo "mis trabajos", y filtrar solo por categorías.

Es la única lectura con una propiedad comprobable: **la unión de los resultados
por proveedor es la lista sin filtro**. Las otras dos dejan solicitudes fuera de
toda vista filtrada —una que oferté con el proveedor A no saldría ni en
`?provider_id=A` ni en `?provider_id=B`— y hacen imposible razonar sobre lo que
la app enseña.

Como efecto, `?provider_id=A` también usa **la licencia de A**, no la de un
hermano activo: "ponme la app en modo Proveedor A" incluye estar suspendido.

### 9. Un `provider_id` ajeno es una lista vacía, no un `403`

**Descartados:** `403` con clave nueva, y `422`.

Es el precedente literal de `?unit_id` (SPEC 91), y la razón que allí está
escrita se aplica igual: *"un 403 haría lo contrario de proteger algo: le
confirmaría a quien sondea que existe, y costaría una consulta para decir lo que
una lista vacía dice gratis"*. Además evita una clave i18n nueva.

Lo estricto es el **formato**, no la pertenencia: un `?provider_id=abc` es un
cliente roto y merece `422`; un nid ajeno es una intersección vacía.

### 10. El proveedor ve la competencia entera

`offers_count` es el total real y `assigned_provider` nombra al ganador aunque
sea un rival.

**Descartado:** enmascarar ambas fuera de mis solicitudes.

El detalle del SPEC 89 ya se las da al proveedor, con la decisión escrita de que
*"`offers_count` es el total y no el tamaño de la lista recortada"*. Enmascararlas
en el listado crearía dos verdades sobre el mismo dato según por dónde se
pregunte, y no protegería nada: el importe de cada oferta —lo que sí es
sensible— sigue sin viajar, ni aquí ni allí.

Saber cuántos rivales hay es además la información que hace útil un mercado.

### 11. Sin clave `my_offer`

**Descartado:** añadir "si ya oferté y por cuánto" al ítem.

El cliente pidió una forma concreta y esto no está en ella. Costaría una consulta
más por página y respondería una pregunta que el detalle ya responde entera, con
el estado de la oferta y sus comentarios. Añadirla más adelante es aditivo.

### 12. A ∪ C como lista de nids, B como condición SQL

**Descartado:** resolver los tres conjuntos a nids en PHP y filtrar con un solo
`IN`.

A y C están acotados por la actividad de la propia cuenta y no crecen con el
sistema; B sí. Un `IN` con todas las solicitudes abiertas de "fontanería" sería
una consulta que se degrada **cuanto mejor funcione el marketplace**, y el
`total` de la paginación pasaría a contarse en PHP.

El precio es un `OR` en la consulta compartida y dos LEFT JOIN con alias propios
(`sfao`, `sfap`) duplicando tablas que el `fetch()` ya une. Se paga porque la
alternativa —mover esos joins a la base— cambiaría la consulta del residente, y
este spec promete no tocarla.

### 13. El refactor de los helpers en vez de una segunda consulta

`myapi_provider_role_category_ids()` y
`myapi_provider_role_any_provider_active()` derivan los proveedores de la cuenta
por dentro, y `?provider_id` necesita preguntarlo de **uno**.

**Descartado:** escribir en el recurso dos consultas equivalentes.

Sería duplicar lógica de negocio en un `resources/`, que `CLAUDE.md` prohíbe. La
extracción es mecánica —la caché ya estaba indexada por el conjunto de ids— y
deja una sola definición de "las categorías de estos proveedores".

### 14. Sin claves i18n nuevas

El `403` reutiliza `provider_role_required` (SPEC 97) y el `422` reutiliza
`invalid_field`.

**Descartado:** una clave propia por endpoint.

La causa del `403` es idéntica a la de `/providers/mine` —"tu cuenta no tiene el
rol de proveedor"— y es exactamente la misma acción para el usuario. Una clave
por ruta obligaría a la app a tratar como distintos dos errores que no lo son.

### 15. El orden es el del residente

`node.created DESC`, desempate por `nid DESC`, invertible con `?sort`.

**Descartado:** ordenar por `desired_start` (urgencia).

Mezclaría dos criterios en un endpoint que ya sirve dos conjuntos: para "mis
trabajos" la urgencia manda, pero para el mercado lo que importa es lo que acaba
de publicarse. Y `desired_start` es opcional, así que el orden dependería de un
campo que puede faltar. Un `?order_by=desired_start` es aditivo el día que la app
lo pida.

---

## Riesgos

### 1. La regla de acceso queda escrita dos veces

`myapi_service_request_provider_scope()` la expresa como **conjunto** (para
paginar) y `myapi_service_request_viewer()` como **decisión por fila** (para el
detalle). Las dos formas son incompatibles —una no puede derivarse de la otra sin
perder la paginación o sin cargar la fila— y por eso conviven, igual que el
SPEC 97 aceptó con `is_active`.

Si una spec futura toca una sola —añadir un estado pujable, cambiar "sin
adjudicar", meter una regla de antigüedad—, el síntoma será un ítem en la lista
que responde `403` al abrirlo, o una solicitud legible que no aparece en ninguna
parte. **Ninguna de las dos falla de forma ruidosa.**

**Mitigación:** el test de equivalencia sobre la matriz estado × adjudicación ×
categoría × ofertado, y un comentario en cada una de las dos funciones apuntando
a la otra por su nombre. Es el único test que detecta esta deriva.

### 2. El back office conserva su propia regla, y ya no coincide con la API

`myapi_provider_role_visible_request_ids()` y
`myapi_provider_role_alter_node_query()` siguen usando
`myapi_provider_role_broadcast_statuses()`, que **no** incluye "adjudicada a mí"
y **sí** incluye `direct` por categoría. Tras este spec hay dos definiciones
vivas y divergen en los dos sentidos:

- Un proveedor con sesión de Drupal **no** puede abrir por `node/N` el trabajo
  cerrado que sí ve en la app.
- Y **sí** puede ver por `node/N` una `direct` ajena de su categoría, que la app
  le oculta.

El segundo caso es una fuga preexistente del SPEC 78, no creada aquí, pero este
spec la deja a la vista al escribir al lado la regla correcta.

**Mitigación hoy:** ninguna en código — queda fuera de alcance a propósito,
porque tocar `hook_node_access` es cambiar el back office y merece su propia
spec. Queda escrito aquí para que esa spec exista.

### 3. Un `OR` vacío devuelve el sistema entero

Es el fallo más grave que este código puede tener y el más fácil de introducir.
Drupal 7 compila un `db_or()` sin condiciones a **nada**, no a `FALSE`: un
alcance vacío que llegara a `myapi_service_request_base_query()` produciría una
consulta sin filtro de proveedor y el endpoint devolvería **todas las solicitudes
de todos los condominios**, con el nombre del solicitante y el condominio
incluidos.

El paso 6 corta antes y el paso 3 añade además una condición imposible, pero son
dos defensas sobre la misma función y cualquiera de las dos puede caerse en un
refactor.

**Mitigación:** el criterio de aceptación y el test unitario que comprueban que un
alcance vacío produce una condición imposible, no un grupo vacío; y un comentario
en mayúsculas sobre el `db_or()` diciendo exactamente esto.

### 4. La `unit` aparece en el listado y desaparece en el detalle

La decisión 5 matiza el SPEC 89 **solo en el listado**. El detalle sigue anulando
`unit` para todo lector proveedor, sin excepción. El resultado visible: en la
lista de "mis trabajos" el proveedor ve el apartamento, pulsa, y en el detalle no
está.

Es una incoherencia real y conocida, aceptada porque la alternativa —tocar
también el detalle— amplía este spec a un tercer endpoint y a una decisión de
privacidad que el SPEC 89 tomó con su propio razonamiento.

**Mitigación:** queda documentado en `docs/service-request-provider.md` como
diferencia deliberada entre las dos respuestas, y es el trabajo evidente para la
spec que continúe esta.

### 5. Los alias duplicados `sfao` / `sfap` parecen un descuido

En la consulta de página, `field_data_field_assigned_offer` y
`field_data_field_assigned_provider` quedan unidas **dos veces**: una en la base
(para la condición B) y otra en el `fetch()` (para resolver los nodos).
Cualquiera que lo lea pensará que sobra una y las fusionará.

Fusionarlas hacia `fao`/`fap` mueve los joins del residente; fusionarlas hacia la
base cambia su `countQuery`. En los dos casos se rompe la promesa de que
`GET /api/v1/service-requests` no cambia — y se rompe **en silencio**, porque la
respuesta sigue siendo correcta.

**Mitigación:** comentario explícito en los dos sitios explicando por qué la
duplicación es deliberada, y el criterio de aceptación que exige que la consulta
del residente no gane ni un join.

### 6. El nombre del solicitante viaja a todo el mercado

A partir de este spec, **cualquier proveedor de una categoría conoce el nombre y
apellido de quien pidió el trabajo, y el condominio**, sin que esa persona haya
elegido a nadie todavía. Antes hacía falta abrir el detalle uno por uno; ahora
llega en bloques de veinte.

Es exactamente lo pedido y el detalle ya lo daba, así que no es un dato nuevo — es
el mismo dato con otro coste de recolección. No viaja teléfono, correo, cédula ni
vivienda.

**Mitigación:** ninguna en código. Se deja escrito para que la decisión sea
consciente y para que la spec que añada notificaciones al mercado sepa qué está
difundiendo.

### 7. B no está acotado por condominio

El rol proveedor tiene dos ejes —pertenencia y categoría— y **ninguno es el
condominio** (SPEC 78, decisión explícita). Un proveedor de "carpintería" ve las
solicitudes abiertas de carpintería de **todos** los edificios del sistema.

Es el modelo de marketplace que las specs 78 y 83 establecieron y este endpoint
solo lo hace visible. Pero si el negocio real es "proveedores contratados por un
condominio", el conjunto B es demasiado ancho y se notará aquí antes que en
ningún otro sitio.

**Mitigación:** ninguna. Si aparece el requisito, el eje "condominios que este
proveedor atiende" es un campo nuevo en el bundle `provider` y una condición más
en B — aditivo, pero es una spec entera.

### 8. `?limit=-1` sobre una categoría poblada

`-1` significa "todo en una página" (SPEC 15) y aquí puede ser el mercado
completo de una categoría más el histórico entero del proveedor, cada fila con
doce joins, el nombre del solicitante y el condominio.

**Mitigación:** ninguna específica; es el mismo riesgo que el `-1` ya tiene en
claims, pagos y en el listado del residente, y la app no necesita usarlo. El
techo real lo pone `?limit=50`.

### 9. B se apoya en índices que nadie ha comprobado

La condición B cruza `field_data_field_request_status`,
`field_data_field_category` y dos `IS NULL` sobre tablas de campo. En un sitio
con decenas de miles de solicitudes, sin índice sobre
`field_request_status_value` el `OR` puede degradar la consulta de página **y la
de conteo**, que la ejecuta entera.

**Mitigación:** ninguna en este spec —crear índices es cambio de esquema y está
fuera de alcance—, pero queda anotado como lo primero que mirar si el endpoint va
lento, y como la razón por la que la decisión 12 mantiene B en SQL en vez de en
un `IN`: solo así el plan de ejecución es visible al `EXPLAIN`.
