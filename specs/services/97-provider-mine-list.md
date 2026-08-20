# 97 — Listado de los proveedores propios de una cuenta con rol `proveedor`

- **Estado:** Approved
- **Fecha:** 2026-08-20
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña del bundle
    `provider` y de todos los campos que este spec lee: `field_logo`,
    `field_categories`, `field_rating_avg`, `field_rating_count`,
    `field_short_description`, `field_hourly_rate`, `field_license_expiry` y
    `field_provider_users`. **Cero cambios de esquema:** ni campo, ni instancia,
    ni bundle, ni tabla, ni `hook_update_N()`.
  - `78-provider-role` (Implemented) — dueña del rol `proveedor`, de
    `myapi_provider_role_is()` (la compuerta del 403) y de
    `myapi_provider_role_provider_ids()` (el vínculo usuario → proveedores vía
    `field_provider_users`). Este spec es el **primer endpoint `api/v1/...` que
    lee ese rol**; hasta hoy el rol solo cerraba el back office y ningún
    recurso lo consultaba. `docs/provider-role.md` avisa explícitamente de que
    el rol "no autoriza la API" y de que esa autorización se escribiría en el
    spec de los endpoints: es este.
  - `83-providers-list` (Implemented) — dueña de la mitad "listado" de
    `resources/provider.resource.inc`, de `myapi_provider_build_item()` (que
    este spec **reutiliza tal cual**, sin tocar una línea), de
    `myapi_provider_categories_by_nid()` y de
    `includes/myapi.provider_query.inc`.
  - `85-provider-logo` (Implemented) — dueña de la clave `logo` y de los dos
    LEFT JOIN encadenados (`field_data_field_logo` → `file_managed`) que este
    spec copia en su propia consulta para poder reutilizar el constructor.
  - `81-provider-rate-tags-short-description` (Implemented) — dueña de
    `field_short_description` y `field_hourly_rate`, dos de las claves del ítem.

**Objetivo:** Que una cuenta con el rol `proveedor` obtenga con
`GET /api/v1/providers/mine` la lista de los nodos `provider` a los que está
vinculada, activos o no, y que cualquier otra cuenta reciba `403`.

---

## Alcance

**Dentro:**

- **`myapi.module`** (modificar) — una entrada nueva en `hook_menu()`:
  `api/v1/providers/mine`, `MENU_CALLBACK`, `access callback` en `TRUE` (la
  autorización la hace el recurso, como en todo el módulo),
  `page callback` = `myapi_provider_mine_dispatch`. Se registra **junto a** las
  tres rutas de proveedores que ya existen. No se toca ninguna de ellas.

- **`resources/provider.resource.inc`** (modificar) — cuatro funciones nuevas al
  final de la mitad "listado", ninguna existente se modifica:
  - **`myapi_provider_mine_dispatch()`** — enruta por método: `GET` llama al
    listado, cualquier otro método responde `405` **antes** de autenticar.
  - **`myapi_provider_mine_list()`** — el endpoint completo: token → compuerta
    de rol → vínculos → consulta → categorías → respuesta.
  - **`myapi_provider_mine_fetch(array $nids)`** — la consulta. Misma base y
    mismos alias que `myapi_provider_fetch()` (para que el constructor
    compartido reciba la fila que espera), pero **sin**
    `myapi_provider_apply_active_conditions()`: filtra por `n.nid IN (:nids)` y
    `n.type = 'provider'`, y añade `n.status` y un **LEFT** JOIN a
    `field_data_field_license_expiry` para poder calcular `is_active` sin
    excluir a nadie.
  - **`myapi_provider_mine_build_item($row, array $categories)`** — llama a
    `myapi_provider_build_item()` y le **añade dos claves al final**, `status` e
    `is_active`. El constructor del SPEC 83 no se toca, así que las ocho claves
    del marketplace no pueden divergir entre los dos endpoints.

- **`includes/myapi.i18n.inc`** (modificar) — una clave nueva,
  `provider_role_required`, en los dos idiomas del catálogo (`es` y `en`).

- **`docs/provider-mine.md`** (nuevo) — la documentación del endpoint, con la
  plantilla de `CLAUDE.md`.

- **`docs/provider.md`** (modificar) — una línea de enlace cruzado en la
  cabecera, para que quien lea el listado público encuentre el propio. Nada más.

- **`docs/i18n.md`** (modificar) — la clave nueva en la tabla de mensajes.

- **`tests/unit/ProviderMineEndpointTest.php`** (nuevo) — tests unitarios de la
  compuerta de rol y de la construcción del ítem, al estilo de
  `ProviderListEndpointTest.php`.

**Fuera (explícito):**

- **`myapi.info` no se toca.** No hay `.inc` nuevo: todo el código vive en dos
  archivos que ya están en `files[]`.
- **No hay `hook_update_N()` ni cambio de esquema.** El endpoint solo lee.
- **No se crea, edita ni borra un proveedor por la API.** Sigue siendo
  responsabilidad del back office, igual que en el SPEC 83.
- **No hay paginación, ni filtros, ni ordenación configurable.** Ni `page`, ni
  `limit`, ni `category_id`, ni `order_by`, ni `sort`: cualquier parámetro de
  query string se **ignora en silencio**, nunca es un `422`.
- **No se sirve el detalle del proveedor propio.** Ni dirección, ni descripción
  larga, ni tags, ni galería, ni ratings individuales: para eso ya está
  `GET /api/v1/providers/%` (SPEC 84), que responde `200` a un proveedor con
  licencia caducada y al que este listado le da el `id`.
- **Nadie puede ver los proveedores de otra cuenta.** No hay parámetro `uid` ni
  variante para administradores: un `administrator` sin rol `proveedor` recibe
  el mismo `403` que cualquiera.
- **No se valida la coherencia rol ↔ vínculo.** Que una cuenta esté en
  `field_provider_users` sin tener el rol (o al revés) sigue sin dar aviso;
  el `hook_requirements()` que sugiere `docs/provider-role.md` queda fuera.
- **No se toca la regla de "proveedor activo" del marketplace público.**
  `myapi_provider_apply_active_conditions()` no se modifica; este endpoint
  simplemente no la usa.
- **No se escriben `field_rating_avg` ni `field_rating_count`.** Siguen sin
  tener flujo que los recalcule, como dice `docs/provider.md`.
- **Sin caché, sin `ETag`, sin `304`**, igual que el resto del módulo.

---

## Modelo de datos

**No hay estructura persistente nueva.** El endpoint solo lee. Lo que este
spec sí define es la forma de la respuesta y una clave de mensaje.

### La respuesta

`data` lleva **una sola clave**, `providers`, y ninguna más. No hay
`pagination`: la cuenta opera uno o dos proveedores, no una colección
paginable.

```json
{ "success": true, "data": { "providers": [] } }
```

`providers` es siempre un array JSON, con uno, varios o cero elementos.

### El ítem: 10 claves, siempre las 10, en este orden

Las **ocho primeras** las produce `myapi_provider_build_item()` (SPEC 83/85)
sin modificar, con exactamente los mismos tipos, los mismos `null` y el mismo
orden que en `GET /api/v1/providers`. Las **dos últimas** son de este spec.

| Campo | Tipo | Origen |
|-------|------|--------|
| `id` | int | `node.nid`. |
| `logo` | string \| null | URL pública absoluta de `field_logo`. |
| `title` | string | `node.title`, texto plano. |
| `categories` | array | `{id, code, name}` en orden de delta; `[]` si no tiene. |
| `rating_avg` | float \| null | `field_rating_avg`. |
| `rating_count` | int | `field_rating_count`; `0`, nunca `null`. |
| `short_description` | string | `field_short_description`, texto plano; `""` si vacío. |
| `hourly_rate` | float \| null | `field_hourly_rate`. |
| **`status`** | **bool** | `node.status == 1`. Publicado o suspendido por el operador. |
| **`is_active`** | **bool** | `node.status == 1` **y** `field_license_expiry >= REQUEST_TIME`. |

### Los dos bools: qué decide cada uno

`is_active` es, literalmente, la condición que
`myapi_provider_apply_active_conditions()` aplica en SQL para el marketplace
público — pero calculada en PHP sobre la fila, no como filtro. **`is_active`
true ⟺ ese proveedor aparece hoy en `GET /api/v1/providers`.** Esa
equivalencia es el contrato, y el test la fija.

Los dos juntos dan el motivo sin campo extra:

| `status` | `is_active` | Qué le ha pasado al proveedor |
|----------|-------------|-------------------------------|
| `true`   | `true`      | Operativo. Sale en el marketplace. |
| `true`   | `false`     | Licencia caducada, o **sin licencia registrada**. |
| `false`  | `false`     | Suspendido a mano por el operador. |
| `false`  | `true`      | **Imposible por construcción**: `is_active` incluye `status`. |

Por eso no viaja `license_expiry`: la fecha exacta no cambia lo que la app
pinta, y las dos causas ya se distinguen.

**`is_active` nunca es `null`.** Un proveedor sin fila en
`field_data_field_license_expiry` — el LEFT JOIN devuelve `NULL` — es
`is_active: false`, igual que uno caducado. Un `null` obligaría a la app a
tratar un tercer caso que significa lo mismo que el `false`.

### La clave i18n

Una sola, en `includes/myapi.i18n.inc`:

| Clave | `en` | `es` |
|-------|------|------|
| `provider_role_required` | Your account does not have the provider role. | Tu cuenta no tiene el rol de proveedor. |

---

## Plan de implementación

Seis pasos. Cada uno deja el módulo funcionando: los tres primeros añaden
código que todavía nadie llama, el cuarto lo enciende.

### 1. La clave del `403`

`includes/myapi.i18n.inc`: `provider_role_required` en los bloques `en` y `es`,
en la zona de claves de proveedores (junto a `provider_not_found` y
`provider_not_eligible`). Y la fila correspondiente en `docs/i18n.md`.

Nada la usa todavía. Se hace primero porque es lo único que el paso 3 no puede
inventarse.

### 2. La consulta y el constructor

En `resources/provider.resource.inc`, al final de la mitad "listado":

**`myapi_provider_mine_fetch(array $nids)`** — con `$nids` vacío devuelve `[]`
**sin lanzar consulta**. Con nids:

- Base idéntica a `myapi_provider_fetch()`: `node n`, `n.nid`, `n.title`,
  `n.type = MYAPI_SERVICES_PROVIDER_TYPE`, y los mismos LEFT JOIN con los
  **mismos alias de salida** (`rating_avg`, `rating_count`,
  `short_description`, `hourly_rate`, `logo_uri`, incluido el doble salto
  `field_data_field_logo` → `file_managed`). Los alias son el contrato con el
  constructor del SPEC 83: si cambian, el ítem sale con `null`.
- **`n.type` se filtra aunque los nids vengan de `field_provider_users`.** Ese
  campo vive en el bundle `provider`, así que hoy es redundante; se pone porque
  la consulta no debe depender de dónde vinieron los nids.
- `->condition('n.nid', $nids, 'IN')`.
- **NO llama a `myapi_provider_apply_active_conditions()`.** En su lugar:
  `$query->fields('n', ['status'])` y un **LEFT** JOIN a
  `field_data_field_license_expiry` con alias propio (no `l`, que ese helper
  reserva), exponiendo `field_license_expiry_value` como `license_expiry`.
- `ORDER BY n.nid DESC`, y nada más: sin `range()`.

**`myapi_provider_mine_build_item($row, array $categories = [])`** —
`myapi_provider_build_item($row, $categories)` y encima:

```php
$item['status']    = isset($row->status) && (int) $row->status === 1;
$item['is_active'] = $item['status']
  && isset($row->license_expiry)
  && $row->license_expiry !== ''
  && (int) $row->license_expiry >= REQUEST_TIME;
```

Los dos son bool puros: nunca `null`, nunca `0`/`1`, nunca string.

### 3. El endpoint

**`myapi_provider_mine_list()`**, en orden estricto:

1. `myapi_auth_require_access_token()` → `401` si falta o no vale. Se guarda el
   `uid` de la fila.
2. `user_load($row->uid)` — hace falta el objeto **con `->roles`**, que la fila
   del token no lleva. No es una consulta extra: `user_load()` ya lo cargó
   dentro del paso 1 y Drupal 7 lo sirve de su caché estática de entidades.
3. **La compuerta:** `if (!myapi_provider_role_is($account))` →
   `myapi_error('provider_role_required', 403)`. Es la única causa de `403` del
   endpoint.
4. `myapi_provider_role_provider_ids($account)`. **Lista vacía → `200` con
   `providers: []`**, y se sale sin más consultas. Tiene el rol pero nadie lo ha
   vinculado: son datos que faltan, no un permiso que sobra.
5. `myapi_provider_mine_fetch($nids)`.
6. `myapi_provider_categories_by_nid()` con los nids de las filas devueltas —
   **una sola consulta para todos los proveedores**, la del SPEC 83 sin tocar.
   Ojo: los nids que se le pasan son los de las **filas**, no los del paso 4,
   que pueden incluir un nodo borrado.
7. Recorrer las filas en el orden que vinieron y construir cada ítem.
8. `myapi_respond(['providers' => $items])`.

**`myapi_provider_mine_dispatch()`** — `GET` llama al listado; cualquier otro
método es `myapi_error('method_not_allowed', 405)` **antes** de mirar el token,
igual que `myapi_provider_dispatch()`.

**Coste: cuatro consultas fijas**, sea uno o veinte proveedores — el token, el
vínculo, las filas y las categorías. Nunca una consulta por proveedor.

### 4. La ruta

En `hook_menu()` de `myapi.module`, junto a las tres de proveedores:

```php
$items['api/v1/providers/mine'] = [
  'page callback'    => 'myapi_provider_mine_dispatch',
  'access callback'  => TRUE,
  'type'             => MENU_CALLBACK,
];
```

**No compite con `api/v1/providers/%`**: el router de Drupal 7 resuelve primero
la ruta literal y solo cae al comodín si no hay coincidencia exacta. Aun así, la
entrada se escribe **antes** que la del comodín en el array, por legibilidad y
por el mismo criterio que el comentario ya existente sobre
`api/v1/providers/%/gallery`.

```bash
drush cc all
```

Sin esto la ruta no existe. No hace falta `drush updb`: no hay esquema.

### 5. Los tests

`tests/unit/ProviderMineEndpointTest.php`, al estilo de
`ProviderListEndpointTest.php` (sin sitio arrancado, sobre las funciones puras
y sobre fixtures de fila):

- La compuerta: `myapi_provider_role_roles_match()` acepta `proveedor` y
  rechaza `authenticated user`, `administrator` y `administrador edificio`.
- El ítem tiene **exactamente 10 claves, en el orden documentado**.
- Las tres combinaciones posibles de `status`/`is_active` de la tabla del
  modelo de datos, más el caso **sin fila de licencia** (`license_expiry`
  ausente → `is_active: false`, nunca `null`).
- `is_active` con `license_expiry == REQUEST_TIME` es `true` (el `>=` de
  `myapi_provider_apply_active_conditions()`, replicado).
- Un proveedor sin logo, sin rating, sin tarifa, sin descripción y sin
  categorías se construye igual, con sus vacíos.
- `myapi_provider_mine_fetch([])` devuelve `[]` sin consultar.

### 6. La documentación

`docs/provider-mine.md` nuevo con la plantilla de `CLAUDE.md`, y la línea de
enlace cruzado en `docs/provider.md`. En el mismo commit que el código: un
endpoint sin doc está incompleto.

---

## Criterios de aceptación

Checklist booleano. Cada línea se comprueba con una petición HTTP o
ejecutando `vendor/bin/phpunit`.

### Autorización

- [ ] `GET /api/v1/providers/mine` sin cabecera `Authorization` responde `401`
      con `error_code: "missing_authorization"`.
- [ ] Con un token revocado, caducado o inexistente responde `401` con
      `error_code: "invalid_token"`.
- [ ] Con un token válido de una cuenta **sin** el rol `proveedor` responde
      `403` con `error_code: "provider_role_required"`.
- [ ] Una cuenta con rol `administrator` y **sin** `proveedor` recibe el mismo
      `403`. No hay excepción para administradores.
- [ ] Una cuenta con rol `proveedor` y **ningún** nodo vinculado en
      `field_provider_users` recibe `200` con
      `data: { "providers": [] }` — nunca `403` ni `404`.
- [ ] `POST`, `PUT`, `DELETE` y `PATCH` sobre la ruta responden `405` con
      `error_code: "method_not_allowed"`, **sin cabecera `Authorization`
      incluida**: el `405` llega antes que el `401`.

### Contenido de la respuesta

- [ ] `data` tiene **exactamente una** clave, `providers`. No hay `pagination`.
- [ ] Con dos proveedores vinculados, la respuesta trae los dos, ordenados por
      `id` **descendente**.
- [ ] Cada ítem tiene **exactamente 10 claves**, en el orden: `id`, `logo`,
      `title`, `categories`, `rating_avg`, `rating_count`,
      `short_description`, `hourly_rate`, `status`, `is_active`.
- [ ] Las ocho primeras claves de un proveedor **activo** son **byte a byte
      iguales** a las que ese mismo proveedor devuelve en
      `GET /api/v1/providers`.
- [ ] Un proveedor propio **despublicado** (`node.status = 0`) aparece en la
      lista con `status: false` e `is_active: false`, y **no** aparece en
      `GET /api/v1/providers`.
- [ ] Un proveedor propio publicado con `field_license_expiry` en el pasado
      aparece con `status: true` e `is_active: false`.
- [ ] Un proveedor propio publicado **sin fila** en `field_license_expiry`
      aparece con `status: true` e `is_active: false`. `is_active` no es `null`.
- [ ] Un proveedor propio publicado con licencia vigente aparece con
      `status: true` e `is_active: true`, y **sí** aparece en
      `GET /api/v1/providers`.
- [ ] `status` e `is_active` viajan como booleanos JSON (`true`/`false`), nunca
      como `0`/`1` ni como `"true"`.
- [ ] Un proveedor propio sin logo, sin categorías, sin rating, sin tarifa y sin
      descripción se devuelve igual, con `logo: null`, `categories: []`,
      `rating_avg: null`, `rating_count: 0`, `hourly_rate: null` y
      `short_description: ""`.
- [ ] Un nid en `field_provider_users` cuyo nodo ya no existe **no** rompe la
      respuesta: se omite y el resto de proveedores se devuelve igual.

### Parámetros y ruta

- [ ] `?page=2`, `?limit=1`, `?category_id=abc`, `?order_by=title` y cualquier
      otro parámetro se **ignoran**: la respuesta es idéntica a la de la ruta
      sin query string. Ninguno produce `422`.
- [ ] `GET /api/v1/providers/mine` no colisiona con
      `GET /api/v1/providers/{id}`: el detalle de un proveedor por nid sigue
      respondiendo lo mismo que antes de este spec.
- [ ] `GET /api/v1/providers` responde exactamente lo mismo que antes de este
      spec, con los mismos parámetros y la misma paginación.

### Código y despliegue

- [ ] `myapi_provider_build_item()` y `myapi_provider_fetch()` no tienen ni una
      línea modificada.
- [ ] `myapi.info` no tiene ni una línea modificada.
- [ ] No hay `hook_update_N()` nuevo: `drush updb` no tiene nada que ejecutar.
- [ ] Tras `drush cc all` la ruta responde; sin `drush cc all` responde el 404
      de Drupal.
- [ ] Una petición completa consume **cuatro consultas** y no crece con el
      número de proveedores vinculados.
- [ ] `vendor/bin/phpunit` pasa en verde, incluidos
      `ProviderMineEndpointTest`, `ProviderListEndpointTest`,
      `ProviderRoleTest` y `ProviderActiveConditionsTest`.
- [ ] `docs/provider-mine.md` existe y documenta los `401`, `403` y `405`.

---

## Decisiones

### 1. La ruta es `api/v1/providers/mine`

**Descartadas:** `api/v1/me/providers` y `api/v1/providers?mine=1`.

La primera abriría una familia `me/*` que hoy no existe en el módulo, por un
solo endpoint. La segunda mete dos autorizaciones distintas —marketplace
público y proveedores propios— en la misma función: el `?mine=1` tendría que
saltarse la paginación, los filtros, el orden y el criterio de "activo" del
listado que reutiliza, con lo que de compartido solo quedaría el nombre.

### 2. El rol autoriza; el vínculo son datos

Sin rol `proveedor` → `403`. Con rol y sin ningún nodo en
`field_provider_users` → `200` con lista vacía.

**Descartado:** `403` también en el segundo caso.

Son dos cosas distintas y el código HTTP tiene que distinguirlas. "No eres
proveedor" es un permiso; "el operador no te ha vinculado todavía" es una
tarea pendiente de otra persona, y devolver `403` la disfraza de problema de
la cuenta. `docs/provider-role.md` ya documenta este agujero de datos —nada
valida que rol y vínculo vayan juntos— y con esta decisión el síntoma es una
pantalla vacía, no un error opaco.

### 3. Este es el primer endpoint del módulo que lee el rol `proveedor`

**Descartado:** mirar solo `field_provider_users`, que es lo que hace hoy
`resources/service_request.resource.inc`.

Ese recurso lo hace a propósito y su docblock lo explica: allí la pregunta es
"¿qué solicitudes te conciernen?", y responderla por rol haría que un
residente que además es proveedor dejara de ver las suyas. Aquí la pregunta es
otra —"¿eres proveedor?"— y el cliente la pidió por rol. `docs/provider-role.md`
anticipaba exactamente esto: el rol "es la marca que la capa de API leerá para
saber que un token pertenece a un proveedor", y la autorización se escribiría
en el spec de los endpoints.

**Consecuencia que hay que aceptar:** una cuenta vinculada a un proveedor a la
que el operador olvidó marcarle el rol recibe `403` aquí y, a la vez, ve las
solicitudes de sus categorías en `/api/v1/service-requests`. Es incoherente, y
la causa es el agujero de datos del punto 2, no este endpoint.

### 4. Se listan los proveedores inactivos, con dos bools que lo explican

**Descartado:** aplicar la misma regla de "activo" que el marketplace público.

Si un proveedor con la licencia caducada desapareciera de su propia lista, el
proveedor abre la app, no ve nada y no tiene forma de saber por qué. La lista
propia es precisamente donde esa información tiene que estar.

**Descartado también:** enviar `license_expiry`. `status` e `is_active`
distinguen ya las dos causas —suspendido vs. licencia— y la fecha exacta no
cambia lo que la app pinta ni lo que el proveedor puede hacer al respecto.

### 5. `is_active` se calcula en PHP, no se filtra en SQL

`myapi_provider_apply_active_conditions()` no se toca y no se usa: hace un
`innerJoin` sobre `field_license_expiry`, que es justo lo que este endpoint no
puede hacer. La condición se replica sobre la fila, con un LEFT JOIN detrás.

Es una duplicación real de una regla de negocio, y se acepta porque las dos
versiones tienen formas incompatibles (filtro vs. bandera). Lo que la sujeta es
un criterio de aceptación explícito: `is_active: true` ⟺ el proveedor sale hoy
en `GET /api/v1/providers`. El día que la regla de "activo" cambie, hay **dos**
sitios que tocar y el test lo dice.

### 6. Se reutiliza `myapi_provider_build_item()` tal cual

**Descartado:** un constructor propio con solo las claves pedidas.

El precio de reutilizarlo es que `hourly_rate` viaja aunque no se hubiera
pedido — nueve claves querían y son diez. Se paga con gusto: es la única
manera de garantizar que las ocho claves compartidas no divergen entre el
listado público y el propio, que es un tipo de bug que no da error en ninguna
parte. Los dos bools se añaden **al final**, sin tocar el orden existente.

### 7. Sin paginación

Una cuenta opera uno o dos proveedores. Una envolvente `pagination` con
`total: 2` y `total_pages: 1` es ceremonia, y obliga a la app a escribir un
bucle de páginas que nunca dará una segunda vuelta.

**Consecuencia:** si algún día una cuenta gestionara cientos de proveedores,
este endpoint los devolvería todos en una respuesta. Se asume; el día que pase,
añadir paginación es aditivo.

### 8. `error_code: "provider_role_required"`, no `forbidden`

**Descartado:** reutilizar `forbidden`, que ya existe en el catálogo.

El `403` de este endpoint tiene una causa única y accionable ("pide el rol al
operador"). Con una clave propia la app la distingue de los otros `forbidden`
del módulo sin leer el texto del mensaje, que es lo que `error_code` existe
para evitar.

### 9. Orden por `id` descendente

**Descartados:** alfabético por título, y activos primero.

Sin paginación no hay riesgo de duplicados entre páginas, así que el orden solo
tiene que ser determinista. `n.nid DESC` es el criterio de desempate que ya usa
el listado público, no necesita ningún JOIN y no depende de datos que puedan
faltar. Poner los activos primero mezclaría el orden con el estado, que ya
viaja en dos claves propias.

### 10. Sin excepción para administradores

Un `administrator` sin rol `proveedor` recibe `403`. El endpoint responde "tus
proveedores", y un administrador no tiene proveedores propios: para verlos
todos ya está el back office.

### 11. Los parámetros de query string se ignoran en silencio

**Descartado:** `422` ante un parámetro desconocido.

Coherente con cómo el listado público trata `page`, `limit`, `order_by` y
`sort` —fallback silencioso, sin error— y protege al cliente que reutilice
código del marketplace y mande un `?limit=20` de rebote.

---

## Riesgos

### 1. La regla de "proveedor activo" queda escrita en dos sitios

`myapi_provider_apply_active_conditions()` la aplica como filtro SQL; este
spec la replica como bandera en PHP. Si un spec futuro cambia la definición
—por ejemplo, un periodo de gracia tras el vencimiento— y toca solo una,
`is_active: true` dejará de significar "sale en el marketplace" **sin que
falle nada**.

**Mitigación:** el criterio de aceptación que exige la equivalencia entre
`is_active` y la presencia en `GET /api/v1/providers`, y un comentario en
`myapi_provider_mine_build_item()` que apunte al helper por su nombre.

### 2. El contrato entre `myapi_provider_mine_fetch()` y el constructor son los alias de la fila

`myapi_provider_build_item()` lee `$row->rating_avg`, `$row->logo_uri`,
`$row->hourly_rate`… con `isset()`. Si un spec futuro renombra un alias en
`myapi_provider_fetch()` y no lo hace en la consulta de este endpoint, el ítem
propio empieza a devolver `null` en esa clave —**silenciosamente**, sin
excepción ni aviso—, mientras el listado público sigue correcto.

**Mitigación:** el test que compara las ocho claves compartidas entre los dos
endpoints para el mismo proveedor. Es el único que detecta esta deriva.

### 3. El alias `l` está reservado

`myapi_provider_apply_active_conditions()` documenta que se apropia del alias
`l` para `field_data_field_license_expiry`. La consulta de este spec hace un
LEFT JOIN a **esa misma tabla** y debe usar otro alias. Si alguien más adelante
añade la llamada al helper dentro de `myapi_provider_mine_fetch()` —creyendo
que "faltaba"—, rompe el endpoint por partida doble: el `innerJoin` excluiría a
los inactivos, que son justo los que este listado existe para mostrar.

**Mitigación:** comentario explícito en la consulta diciendo por qué **no** se
llama al helper.

### 4. Rol y vínculo pueden estar desalineados en producción

Nada valida que una cuenta de `field_provider_users` tenga el rol, ni al revés.
Con este spec eso pasa a tener consecuencias visibles y contradictorias: la
cuenta vinculada pero sin rol recibe `403` aquí y a la vez ve solicitudes de
sus categorías en `/api/v1/service-requests`.

**Mitigación hoy:** ninguna en código — queda fuera de alcance a propósito. El
`hook_requirements()` que sugiere `docs/provider-role.md` sigue siendo la
solución pendiente, y este spec es el primer motivo real para escribirlo.

### 5. La ruta literal depende del orden de resolución del router

`api/v1/providers/mine` y `api/v1/providers/%` conviven porque Drupal 7 prefiere
la parte literal sobre el comodín. Es comportamiento documentado del núcleo, no
un accidente, pero es un supuesto del que depende que el detalle de proveedor
siga funcionando.

**Mitigación:** el criterio de aceptación que verifica que
`GET /api/v1/providers/{id}` sigue respondiendo lo mismo después del despliegue,
y que la ruta nueva no se traga ningún nid.

### 6. Sin techo en el tamaño de la respuesta

`field_provider_users` no tiene cardinalidad limitada del lado del proveedor, y
este endpoint no pagina. Una cuenta vinculada a cientos de proveedores recibiría
todos en una respuesta.

**Mitigación:** ninguna, asumido en la decisión 7. Hoy el caso real es de uno o
dos proveedores por cuenta; añadir paginación más adelante es aditivo y no
rompe al cliente.
