# 88 — Listado de solicitudes de servicio del residente (`GET /api/v1/service-requests`)

- **Estado:** Approved
- **Fecha:** 2026-08-17
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña de los bundles
    `service_request` y `service_offer`, de sus campos (`field_requester`,
    `field_category`, `field_description`, `field_request_status`,
    `field_assigned_offer`, `field_assigned_provider`, `field_request`,
    `field_offer_status`) y del catálogo `myapi_services_request_statuses()`, que
    este spec **lee** para validar `?status`. No crea ni modifica nada del esquema.
  - `87-service-request-direct-status` (Implemented) — la razón de que la
    adjudicación se sirva en **dos claves independientes**: una solicitud en
    `direct` tiene proveedor y **no** tiene oferta, y `field_rating_offer` se
    soltó a opcional precisamente por eso.
  - `78-provider-role` (Implemented) — dueña de
    `myapi_provider_role_alter_node_query()`, que filtra por categoría **toda**
    consulta etiquetada `node_access`. Este spec **no** etiqueta la suya, y
    mantiene así la promesa escrita en `myapi_query_node_access_alter()`:
    *«ninguna consulta de este módulo lleva esa etiqueta»*.
  - `69-...` / `GET /api/v1/claims` (Implemented) — el **precedente exacto de
    forma**: listado propio de un residente, filtrado por `field_requester = uid`,
    con `?page`/`?limit`/`?sort`/`?status` laxos y bloque `pagination`. Este spec
    copia ese idioma línea por línea.
  - `83-providers-list` (Implemented) — el precedente de **conteo agregado por
    página**: `myapi_provider_categories_by_nid()` resuelve un dato multivaluado
    de toda la página en **una** consulta, que es la forma que aquí toma
    `offers_count`.

**Objetivo:** Dar al residente autenticado el listado paginado de las solicitudes
de servicio que él mismo creó, con su estado, categoría, número de ofertas
recibidas y —cuando existan— la oferta y el proveedor adjudicados.

Cuatro notas que la cabecera fija:

- **Es el primer endpoint de `service_request`.** El bundle lleva tres specs de
  esquema (77, 86, 87) sin una sola ruta. Aquí nacen
  `resources/service_request.resource.inc`, su entrada en `myapi_menu()` y su
  línea en `myapi.info`.
- **Solo lectura, y sobre datos que hoy carga un operador.** Nada crea
  solicitudes ni ofertas por API todavía, y `field_assigned_offer` /
  `field_assigned_provider` no los escribe ningún flujo. El endpoint es correcto
  igualmente: lista lo que haya, y lo que hay hoy se carga desde el back office.
- **La adjudicación son dos claves hermanas, no un objeto.** `assigned_offer` y
  `assigned_provider` se anulan por separado porque el modelo lo permite: un
  `direct` responde la segunda con la primera en `null`.
- **Sin comprobación de rol.** El alcance lo da `field_requester = uid`, no el
  rol del lector. Un usuario que además sea `proveedor` ve aquí lo que creó como
  residente, y nada más.

---

## Alcance

**Dentro del alcance:**

- **`resources/service_request.resource.inc`** (nuevo, único fichero de lógica):
  - `myapi_service_request_dispatch()` — enruta por método; `GET` al listado,
    cualquier otro a `405 method_not_allowed`.
  - `myapi_service_request_list()` — orquestación: autenticar, parsear query
    string, contar, traer la página, resolver las ofertas de la página,
    serializar.
  - `myapi_service_request_base_query($uid, array $filters)` — la consulta
    compartida por el conteo y la página, para que no puedan divergir. **Sin
    `->addTag('node_access')`.**
  - `myapi_service_request_count()` / `myapi_service_request_fetch()`.
  - `myapi_service_request_offer_counts_by_nid(array $nids)` — **una** consulta
    agregada para toda la página.
  - `myapi_service_request_build_item($row, array $offer_counts)` — pura, sin
    base de datos, testeable sola.
  - `myapi_service_request_parse_status()` — valida contra
    `myapi_services_request_statuses()`, nunca contra una lista escrita a mano.
  - `myapi_service_request_parse_category_id()` — el **único** parámetro
    estricto: `422 invalid_field` si no es un entero positivo, igual que en
    `myapi_provider_list()`.
- **`myapi.module`** (modificar): la ruta `api/v1/service-requests` en
  `myapi_menu()`, con `'access callback' => TRUE` y `'file'`, igual que las
  demás. Nada de lógica.
- **`myapi.info`** (modificar): `files[] = resources/service_request.resource.inc`.
- **`docs/service-request.md`** (nuevo), con la plantilla de `CLAUDE.md`.
- **`tests/unit/ServiceRequestListEndpointTest.php`** (nuevo), al estilo de
  `ProviderListEndpointTest`: se llama al dispatcher como lo llama `hook_menu()`,
  sobre tablas fixture, y se asserta el JSON impreso y el código HTTP.
- `drush cc all` al final (ruta nueva y fichero nuevo).

**Fuera del alcance:**

- **El detalle, `GET /api/v1/service-requests/{id}`.** Otro spec. Es quien traerá
  vivienda, condominio, imágenes, adjunto, `closed_at` y la lista de ofertas una
  por una.
- **Toda escritura**: crear, editar, cancelar, cerrar, adjudicar.
  `POST`/`PUT`/`DELETE` responden `405`.
- **Las ofertas como recurso propio** (`service_offer`): listarlas, crearlas,
  retirarlas, su chat. Aquí solo se **cuentan**, y de la adjudicada se leen `id`
  y `status`.
- **Calificaciones.** Ni se sirven ni se exigen.
- **El listado del proveedor** — «las solicitudes que puedo atender». Es el otro
  lado del mercado, con `myapi_provider_role_visible_request_ids()` de dueña, y
  no comparte ni el alcance ni la forma con este.
- **Filtros que no se pidieron**: por vivienda, por rango de fechas,
  `?include=`. Solo `?status` y `?category_id`.
- **`?category_id` multivalor** (`?category_id=3,7`). Es un valor único, como en
  `GET /api/v1/providers`. Aceptar una lista es trivial en SQL y nada trivial en
  el contrato: obliga a decidir qué pasa cuando un elemento de la lista es
  inválido y el resto no, y ese es exactamente el caso que la decisión de abajo
  resuelve con un `422` limpio.
- **Etiquetas traducidas de los estados.** La respuesta lleva la clave
  (`"offered"`); el catálogo `myapi_t()` no gana ninguna clave, igual que en
  SPEC 87.
- **Notificaciones.** Endpoint de lectura: no dispara ninguna.
- **`myapi.install`, `hook_schema()` y cualquier campo o instancia.** Cero
  cambios de esquema: todos los campos que se leen existen desde 77/86/87.
- **`includes/*`.** Nada nuevo y nada modificado: la lógica cabe entera en el
  fichero del recurso, y no hay un segundo consumidor que justifique extraerla
  (Regla 3 de `CLAUDE.md` se activa con el segundo lector, no con el primero).
- **Etiquetar la consulta con `node_access`.** Decisión explícita, no omisión:
  ver Decisiones y Riesgos.

---

## Modelo de datos

**Este spec no introduce ninguna estructura de datos nueva.** No hay campo,
instancia, tabla, vocabulario ni `myapi_update_XXXX()`. Todo lo que se lee existe
desde SPEC 77/86/87. Lo que sí define, y es su contrato real, es **la forma de la
respuesta** y **el plan de consultas** que la produce.

### La respuesta

```json
{
  "success": true,
  "data": {
    "service_requests": [
      {
        "id": 128,
        "title": "Fuga en el calentador",
        "description": "El calentador del baño principal gotea desde el lunes.",
        "status": "assigned",
        "category": { "id": 12, "name": "Plomería" },
        "offers_count": 3,
        "assigned_offer": { "id": 45, "status": "selected" },
        "assigned_provider": { "id": 7, "name": "Plomería Rivas" },
        "created": "2026-08-14T09:12:33",
        "desired_start": "2026-08-19T08:00:00"
      }
    ],
    "pagination": { "total": 7, "page": 1, "limit": 20, "total_pages": 1 }
  }
}
```

### De dónde sale cada clave

| Clave | Origen | Nulo posible | Notas |
|---|---|:---:|---|
| `id` | `node.nid` | No | Entero JSON, nunca string (regla de `myapi_claim_build_item()`). |
| `title` | `node.title` | No | El bundle no tiene campo de título propio. |
| `description` | `field_description` | No¹ | **Valor almacenado tal cual**, sin `myapi_text_to_plain()`: conserva los saltos de línea. Es lo que ya hace `myapi_claim_build_item()` con este mismo campo compartido, y el `default_value` de la instancia es formato `plain_text`, así que no viaja marcado. |
| `status` | `field_request_status` | No | Clave del catálogo, sin etiqueta. Uno de los seis de SPEC 87. |
| `category.id` / `.name` | `field_category` → `taxonomy_term_data` | No¹ | `INNER JOIN`, **en la consulta base**: decide filas, no solo columnas, y de él cuelga el filtro `?category_id`. Un `tid` huérfano deja la solicitud fuera del listado. Ver Riesgos. |
| `offers_count` | Agregado sobre `service_offer` | No | Entero, `0` cuando no hay ninguna. Siempre `0` en `direct`. |
| `assigned_offer` | `field_assigned_offer` → `node` + `field_offer_status` | **Sí** | `{ "id", "status" }` o `null` entero. `status` del catálogo de ofertas (`sent`/`selected`/`rejected`/`withdrawn`). |
| `assigned_provider` | `field_assigned_provider` → `node.title` | **Sí** | `{ "id", "name" }` o `null` entero. |
| `created` | `node.created` | No | `format_date($ts, 'custom', 'Y-m-d\TH:i:s')`, idéntico a claims. |
| `desired_start` | `field_desired_start` | No¹ | `datestamp` real (no `tz_handling = none`), así que se formatea igual que `created`. |

¹ Son campos **requeridos** en el bundle, así que en la práctica no faltan; el
`LEFT JOIN` los deja en `NULL` si alguien borró la fila a mano, y el serializador
responde `""` / `null` sin romperse.

### Las dos claves de la adjudicación, y por qué son dos

| Estado | `assigned_offer` | `assigned_provider` |
|---|---|---|
| `open`, `offered`, `cancelled` | `null` | `null` |
| `assigned` | `{ id, status }` | `{ id, name }` |
| **`direct`** | **`null`** | **`{ id, name }`** |
| `closed` | lo que tuviera al cerrar | lo que tuviera al cerrar |

La fila `direct` es la que prohíbe anidarlas en un solo objeto: SPEC 87 creó ese
estado precisamente para la solicitud con proveedor elegido y **sin** ronda de
ofertas, y por él `field_rating_offer` pasó a opcional. Un
`awarded: { offer, provider }` obligaría a inventar un objeto con la mitad vacía,
o a devolver `null` entero y perder el proveedor.

### El plan de consultas: **tres**, fijas, no crecen con la página

1. **El conteo** — `myapi_service_request_count()` sobre
   `myapi_service_request_base_query()`.
2. **La página** — la misma base más los `LEFT JOIN` de presentación,
   `ORDER BY n.created DESC, n.nid DESC` y `range()`.
   La frontera entre las dos mitades es exacta: la **base** lleva todo lo que
   decide **qué filas existen** —el bundle, `n.status`, `field_requester`, el
   `INNER JOIN` de la categoría y las condiciones de `?status` y
   `?category_id`—, y la página añade solo lo que aporta **columnas y nunca
   filas** (`td.name`, la descripción, la fecha deseada, la oferta y el
   proveedor adjudicados). Es la regla que `myapi_provider_count()` deja escrita
   y la que garantiza que `total` cuente exactamente las solicitudes que las
   páginas devuelven.
3. **Las ofertas de toda la página** —
   `myapi_service_request_offer_counts_by_nid($nids)`, un `GROUP BY` sobre los
   nids de la página, devuelto como mapa `nid => count`.

La consulta 3 es la de SPEC 83 (`myapi_provider_categories_by_nid()`) con otro
agregado: **una** consulta para la página entera, nunca una por solicitud.

**El detalle que hace correcta la consulta 3:** `field_request` es un campo
**compartido** por `service_offer` y `service_transaction` (SPEC 77). Contar
filas de `field_data_field_request` sin más contaría también las entradas de la
línea de tiempo, y `offers_count` crecería con cada cambio de estado. El
`INNER JOIN` a `node` con `type = 'service_offer' AND status = 1` es obligatorio,
no decorativo, y un test lo fija.

### La consulta base

```
node n
  WHERE n.type = 'service_request'
    AND n.status = 1
    AND field_requester.target_id = :uid
    [AND field_request_status.value IN (:status_list)]
    [AND field_category.tid = :category_id]
```

Los dos filtros son opcionales y componen en `AND`. El de categoría **no añade
ninguna tabla**: el `INNER JOIN` a `field_data_field_category` ya vive en la base
—porque decide qué filas existen, no solo qué columnas se pintan—, así que
filtrar es una condición más sobre un `JOIN` que ya estaba. El conteo y la página
lo aplican los dos o ninguno, por salir de la misma `base_query()`.

Sin `->addTag('node_access')`, **a propósito**: `myapi_query_node_access_alter()`
documenta que ninguna consulta de este módulo la lleva, y añadirla haría que un
residente con rol `proveedor` dejara de ver sus propias solicitudes en cuanto no
fueran de su categoría.

El `ORDER BY` lleva `n.nid DESC` de desempate porque dos solicitudes creadas en
el mismo segundo, sin él, pueden intercambiar posición entre la página 1 y la 2 y
hacer que una se repita y otra desaparezca — la misma trampa que SPEC 83
documentó con `rating_avg`.

---

## Plan de implementación

Cada paso deja el módulo funcionando: los pasos 1–3 pueden aplicarse sin que
ninguna ruta cambie de comportamiento, y el 4 es el que enciende el endpoint.

1. **`resources/service_request.resource.inc` — el esqueleto y las funciones
   puras.** El `@file` que explica el alcance (`field_requester = uid`, sin
   comprobación de rol, sin `node_access`), el dispatcher con su `405`,
   `myapi_service_request_parse_status()` leyendo
   `myapi_services_request_statuses()`,
   `myapi_service_request_parse_category_id()` con su `422` —con el docblock que
   dice por qué es el único parámetro estricto y por qué un tid inexistente
   **no** lo es—, y `myapi_service_request_build_item()` completo. Nada toca
   todavía la base de datos.
   *Verificación: `php -l`; los tests de `build_item` y del parseo de `?status`
   ya pasan en verde sin sitio arrancado.*

2. **El mismo fichero — las tres consultas.**
   `myapi_service_request_base_query()` —con el comentario de por qué **no**
   lleva `addTag('node_access')` y por qué el `INNER JOIN` de la categoría vive
   aquí y no en el `fetch()`—, `count()`, `fetch()` con sus `LEFT JOIN` de
   presentación y el `ORDER BY n.created, n.nid`, y `offer_counts_by_nid()` con
   el `INNER JOIN` a
   `node type = 'service_offer'` y el comentario de que `field_request` es
   compartido con `service_transaction`.
   *Verificación: `php -l`.*

3. **El mismo fichero — `myapi_service_request_list()`.**
   Orquestación en siete pasos, calcada de `myapi_claim_list()`:
   `myapi_auth_require_access_token()`, el `category_id` estricto **antes** que
   nada más (un `422` no debe costar ni una consulta), parseo laxo de
   `page`/`limit`/`sort`/`status`, conteo, página, mapa de ofertas, `array_map()` y
   `myapi_respond(..., 200)` sin `message_key` (endpoint de lectura).
   *Verificación: `php -l`.*

4. **`myapi.info` y `myapi.module`.** La línea
   `files[] = resources/service_request.resource.inc` y la entrada
   `api/v1/service-requests` en `myapi_menu()`, con
   `'page callback' => 'myapi_service_request_dispatch'`,
   `'access callback' => TRUE`, `'file'` y `'type' => MENU_CALLBACK`. Colocada
   junto a `api/v1/service-categories` y `api/v1/providers`, que son sus vecinas
   de dominio.
   *Verificación: `drush cc all` y `curl` con token real devuelve 200 con la
   envoltura correcta.*

5. **`tests/unit/ServiceRequestListEndpointTest.php`.** Al estilo de
   `ProviderListEndpointTest`: filas fixture planas (las que devolvería cada
   `LEFT JOIN`, bajo el alias de la consulta), token fixture y cabecera
   `Authorization`. Cubre: el `401` sin token, el `405` de `POST`, la solicitud
   ajena que no aparece, el `direct` con proveedor y sin oferta, la referencia
   rota que sale `null`, `offers_count` en `0` y en `3`, el `?status` válido e
   inválido, las cuatro formas de `?category_id` (válido que filtra, válido
   inexistente que devuelve lista vacía, mal formado que da `422`, y
   `?category_id[]=1` que también da `422` sin pasar por un cast a string), la
   paginación (incluido `limit=-1` y la página más allá de la última), y los dos
   guards estructurales — que la consulta **no** lleva
   `node_access` y que el conteo de ofertas filtra por `type = 'service_offer'`.
   *Verificación: suite completa en verde.*

6. **`docs/service-request.md`.** Plantilla de `CLAUDE.md`: método, autenticación
   requerida, tabla de parámetros de query string —con `category_id` marcado
   como el único estricto—, cuerpo de ejemplo con un `assigned` y un `direct`, y
   la tabla de errores (401, 405, 422). Más una nota de que el detalle y la
   escritura son otro spec.

7. **Aplicar y verificar.** `drush cc all` y recorrer los criterios de
   aceptación contra el sitio.

---

## Criterios de aceptación

**Contrato de respuesta**

- [ ] `GET /api/v1/service-requests` con token válido responde `200` y
      `{"success": true, "data": {"service_requests": [...], "pagination": {...}}}`.
- [ ] Cada elemento trae exactamente las diez claves: `id`, `title`,
      `description`, `status`, `category`, `offers_count`, `assigned_offer`,
      `assigned_provider`, `created`, `desired_start`. Ni una más.
- [ ] `id`, `category.id`, `offers_count`, `assigned_offer.id` y
      `assigned_provider.id` viajan como **enteros** JSON, no como strings.
- [ ] `status` es una de las seis claves del catálogo, en inglés, sin etiqueta
      acompañante.
- [ ] `description` conserva los saltos de línea que el operador escribió.
- [ ] `created` y `desired_start` tienen la forma `Y-m-d\TH:i:s`, igual que
      `created` en `GET /api/v1/claims`.

**La adjudicación**

- [ ] Una solicitud en `open` u `offered` responde `assigned_offer: null` **y**
      `assigned_provider: null`.
- [ ] Una solicitud en `direct` con proveedor responde `assigned_offer: null` y
      `assigned_provider: {id, name}` — la fila que justifica que sean dos claves.
- [ ] Una solicitud en `assigned` responde las dos, y `assigned_offer.status` es
      una clave de `myapi_services_offer_statuses()`.
- [ ] Una solicitud cuyo `field_assigned_provider` apunta a un nodo despublicado
      o borrado responde `assigned_provider: null` **y sigue apareciendo en el
      listado**.

**`offers_count`**

- [ ] Una solicitud sin ofertas responde `0`, no `null` ni la clave ausente.
- [ ] Con tres ofertas —una `sent`, una `rejected` y una `withdrawn`— responde
      `3`: se cuentan todas las recibidas.
- [ ] Una oferta despublicada **no** cuenta.
- [ ] Añadir entradas de línea de tiempo (`service_transaction`) a una solicitud
      **no** mueve su `offers_count`. Es el criterio que prueba el filtro por
      tipo.

**Alcance y seguridad**

- [ ] Sin cabecera `Authorization`: `401 missing_authorization`. Con un token
      inválido o caducado: `401 invalid_token`.
- [ ] Una solicitud cuyo `field_requester` es **otro** uid no aparece, aunque sea
      del mismo condominio y la misma vivienda.
- [ ] Una solicitud creada desde el back office por un administrador **con
      `field_requester` apuntando al lector** sí aparece: el filtro es
      `field_requester`, no `node.uid`.
- [ ] Un usuario con rol `proveedor` que además sea residente ve **sus**
      solicitudes, completas, sin que la categoría del proveedor recorte nada. La
      consulta no lleva `addTag('node_access')`.
- [ ] Un lector sin ninguna solicitud recibe `200` con lista vacía y `total: 0` —
      nunca un `403`.
- [ ] `POST`, `PUT` y `DELETE` sobre la ruta responden `405 method_not_allowed`.

**Query string**

- [ ] `?page=2&limit=5` pagina; `?limit=-1` devuelve todo en una página con
      `page: 1`; `?limit=999` se recorta a 50.
- [ ] `?page=abc`, `?limit=0`, `?sort=arriba` y `?status=inventado` caen a su
      valor por defecto **en silencio**, sin `422`.
- [ ] `?status=open,offered` filtra por los dos; `?status=open,inventado` filtra
      solo por `open`.
- [ ] `?category_id=12` devuelve solo las solicitudes de esa categoría, y
      `pagination.total` cuenta **ese** subconjunto, no el listado completo.
- [ ] `?category_id=999999` (tid bien formado que no existe) responde `200` con
      lista vacía y `total: 0`, **no** un `422` ni un `404`.
- [ ] `?category_id=abc`, `?category_id=0`, `?category_id=-3`, `?category_id=`
      y `?category_id[]=1` responden `422 invalid_field` con `@field` =
      `category_id`. Es el **único** parámetro que puede dar `422` en este
      endpoint.
- [ ] `?category_id=12&status=open,offered` compone los dos filtros en `AND`, y
      la paginación describe el resultado de los dos juntos.
- [ ] Un `422` por `category_id` se responde **sin haber ejecutado ninguna
      consulta** de listado.
- [ ] `?sort=asc` invierte el orden y `pagination.total` no cambia.
- [ ] Una página más allá de la última responde `200` con lista vacía, no `404`.
- [ ] `pagination.total_pages` es `0` cuando `total` es `0`.

**Rendimiento**

- [ ] Una petición ejecuta **tres** consultas de listado, con 1 solicitud y con
      50: el conteo, la página y las ofertas de la página. Ninguna crece con el
      número de filas.

**No regresión**

- [ ] `GET /api/v1/providers`, `GET /api/v1/providers/{id}`,
      `GET /api/v1/service-categories` y `GET /api/v1/claims` responden byte a
      byte igual.
- [ ] `myapi.install` no tiene ni un cambio: `drush updb` no encuentra ningún
      update pendiente y `myapi_update_7033` sigue siendo el último.
- [ ] Ningún rol gana ni pierde permisos, y `myapi_provider_role_*` queda sin
      tocar.
- [ ] La suite unitaria pasa completa y `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| La ruta | **`api/v1/service-requests`** | `api/v1/services-request`, tal como se pidió literalmente | La convención de `CLAUDE.md` es inglés y plural, y las quince rutas existentes la cumplen sin excepción. Una ruta singular obligaría a recordarla como caso aparte cada vez que el cliente Flutter la escriba, y la primera ruta de un dominio nuevo es la que fija el nombre de todas sus hermanas (`/{id}`, `/{id}/offers`). |
| Quién puede llamarlo | **Ningún filtro por rol**: el alcance lo da `field_requester = uid` | `403` si el lector tiene el rol `proveedor` | Elección explícita del usuario. Un rol no es una identidad: quien atiende servicios puede vivir en un condominio y pedir un plomero. El `403` habría inventado un `error_code` nuevo para negar a alguien sus propios datos, y el listado del proveedor —que sí existe como necesidad— es otro endpoint, con otro alcance. |
| El alcance de los datos | **Solo `field_requester = uid`** | Además acotado a los condominios del lector, como `myapi_claim_list()` | Elección explícita del usuario. Un reclamo puede ser público dentro de un condominio, y por eso su listado necesita el alcance por condominio; aquí no hay nada público: la solicitud es del que la creó y de nadie más. Añadir el condominio solo podría **quitar** filas propias —a un residente que cambió de vivienda— sin añadir ni una. |
| De quién es la solicitud | **`field_requester`** | `node.uid`, o los dos con un `OR` | Elección explícita del usuario. `field_requester` es el campo semántico y es el que rellena `myapi_claim_create()`; `node.uid` es el autor técnico, que hoy —sin endpoint de creación— es el administrador que cargó la solicitud desde el back office. Filtrar por `node.uid` habría devuelto una lista vacía a todo el mundo. |
| Etiqueta `node_access` | **La consulta NO la lleva** | Etiquetarla, como haría cualquier consulta de nodos «bien educada» de Drupal 7 | `myapi_query_node_access_alter()` corre `myapi_provider_role_alter_node_query()` sobre toda consulta etiquetada, y ese alter es una lista blanca por categoría del proveedor. Un residente con rol `proveedor` habría dejado de ver sus propias solicitudes de una categoría que no atiende. Además, el módulo tiene escrito en ese mismo docblock que ninguna de sus consultas la lleva; este spec mantiene la frase verdadera en vez de convertirla en una excepción. |
| Forma de la adjudicación | **Dos claves hermanas**, `assigned_offer` y `assigned_provider`, nulas por separado | Un objeto `awarded: {offer, provider}`, o cuatro claves planas | Elección explícita del usuario, y es la única forma que representa el estado `direct` de SPEC 87 sin mentir: proveedor sí, oferta no. El objeto anidado obligaría a devolver `null` entero —perdiendo el proveedor— o un objeto con la mitad vacía, que es lo mismo con más ceremonia. Las cuatro claves planas repiten el prefijo y no permiten preguntar «¿hay adjudicación?» con una sola comprobación. |
| Qué cuenta `offers_count` | **Todas las ofertas publicadas**, sea cual sea su estado | Excluir `withdrawn` y `rejected`; o un desglose por estado | Elección explícita del usuario. «Cuántas ofertas recibí» es el dato que el residente lee en un listado, y una retirada se recibió. El desglose por estado es información de detalle: pertenece a `GET /api/v1/service-requests/{id}`, que sí lista las ofertas una por una. |
| Referencias rotas | **`LEFT JOIN`; si no resuelve, la clave sale `null`** y la fila sobrevive | `INNER JOIN`, o devolver el `id` sin poder resolver el resto | Nada valida hoy que `field_assigned_offer` apunte a un nodo vivo, y perder una solicitud propia del listado por una referencia rota es el peor fallo posible en un endpoint de lectura: el usuario no ve nada y no hay error que se lo explique. Devolver el `id` suelto obligaría a cada cliente a distinguir «hay adjudicación pero no la puedo pintar» de «no hay», que es exactamente la distinción que `null` ya hace. |
| La categoría con `INNER JOIN` | **`INNER JOIN`** a `taxonomy_term_data` | `LEFT JOIN`, con `category: null` cuando el término no existe | Es la excepción a la decisión anterior, y es deliberada: `field_category` es **requerido**, así que un `tid` huérfano solo aparece si alguien borró un término del vocabulario a mano. Un listado de servicios sin categoría no es accionable, y la incoherencia debe verse (falta una fila) en vez de propagarse como un `null` que cada cliente pinta como puede. Queda anotado en Riesgos con su consulta de diagnóstico. |
| El estado | **Solo la clave** (`"offered"`) | Clave + etiqueta española; o clave + etiqueta vía `myapi_t()` | Es lo que ya hacen claims y reservations, así que el cliente Flutter tiene el mapeo escrito. Las etiquetas de `myapi_services_request_statuses()` son de back office y en español, y meterlas en la respuesta introduce texto no traducible; darles claves `myapi_t()` es trabajo que SPEC 87 dejó explícitamente fuera y que corresponde al spec que decida el vocabulario completo de la feature, no a este listado. |
| `description` | **Valor almacenado en crudo** | `myapi_text_to_plain()`; o truncado a N caracteres | Elección explícita del usuario tras ver que `myapi_text_to_plain()` colapsa los saltos de línea. Es además lo que ya hace `myapi_claim_build_item()` con este **mismo campo compartido**, así que las dos respuestas tratan igual el mismo dato. El `default_value` de la instancia es formato `plain_text`, de modo que no viaja marcado. Truncar es decisión de presentación y le toca al cliente, que sabe cuánto cabe. |
| Dónde vive la lógica | **Todo en `resources/service_request.resource.inc`** | Extraer las consultas a `includes/myapi.service_request_query.inc`, como hizo `provider` | La Regla 3 de `CLAUDE.md` prohíbe **duplicar**, y aquí no hay segundo lector: `includes/myapi.provider_query.inc` nació cuando el listado y el detalle compartían la consulta base. Extraer antes de tener el segundo consumidor produce un include con un solo cliente y una frontera que hay que rediseñar en cuanto llegue el detalle de verdad. |
| Orden por defecto | **`n.created DESC`, desempatado por `n.nid DESC`** | `field_desired_start`; o `n.changed` | Elección explícita del usuario. `created` no falta nunca, no lo mueve ninguna edición y es el orden que el residente espera («lo último que pedí, arriba»). El desempate por `nid` no es cosmético: sin él, dos solicitudes creadas en el mismo segundo pueden intercambiar posición entre páginas y hacer que una se repita y otra desaparezca — la trampa que SPEC 83 documentó con `rating_avg`. |
| Parseo del query string | **Laxo: lo inválido cae al defecto en silencio**, salvo `category_id` | `422` con `error_code` por cada parámetro mal formado | Es el idioma de todo el módulo (claims, payments, bulletins, providers). Un listado que responde `422` porque llegó `?page=abc` rompe la pantalla entera por un parámetro decorativo; el `422` se reserva para los cuerpos de escritura, donde un dato malo sí corrompe algo. |
| Filtro por categoría | **`?category_id`, valor único, estricto: `422 invalid_field` si está mal formado; lista vacía si el tid no existe** | Laxo como el resto del query string; o `404` cuando el término no existe | Petición explícita del usuario, resuelta copiando `myapi_provider_list()` (SPEC 83) **al pie de la letra**, porque es el mismo nombre de parámetro en el mismo dominio: que `?category_id=abc` diera `422` en `/providers` y lista completa en `/service-requests` obligaría al cliente a recordar cuál es cuál. La distinción entre «mal formado» y «no existe» es la de SPEC 83 y es la correcta: un `abc` es un error de programación del cliente y debe verse; un tid que no existe es una pregunta legítima cuya respuesta honesta es «ninguna», y un `404` diría que el endpoint no existe, no la categoría. |
| Dónde se aplica el filtro de categoría | **En `base_query()`**, sobre el `INNER JOIN` que ya está ahí | En `fetch()`, junto a los `LEFT JOIN` de presentación | El filtro decide **qué filas existen**, así que tiene que verlo también el conteo: aplicado solo en la página, `pagination.total` describiría el listado completo y las filas una categoría, que es la incoherencia que `myapi_provider_count()` documenta como criterio de aceptación propio. Mover el `INNER JOIN` a la base no cuesta una consulta más: la categoría ya se unía para poder pintarla. |
| Alcance del spec | **Solo el listado** | Añadir de paso el detalle, o la creación | La creación necesita decidir permisos, validación vivienda ↔ condominio (deuda anotada por SPEC 86), estado inicial `open` vs `direct` y quién puede elegir proveedor: son cuatro decisiones en cuatro dominios, y `CLAUDE.md` pide partir antes que amontonar. El listado se puede entregar y probar entero por su cuenta. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`offers_count` cuenta entradas de la línea de tiempo.** `field_request` es un campo **compartido** por `service_offer` y `service_transaction` (SPEC 77). Un `COUNT(*)` sobre `field_data_field_request` sin filtrar por bundle crece con cada cambio de estado, y el fallo es silencioso: el número es plausible, solo que más alto. | El `INNER JOIN` a `node` con `type = 'service_offer' AND status = 1` es obligatorio y va comentado con esta razón. Dos criterios de aceptación lo prueban desde fuera —añadir transacciones no mueve el conteo— y un test unitario falla si la condición de tipo desaparece de la consulta. |
| **Alguien etiqueta la consulta con `node_access`** más adelante, «para hacerlo como Drupal manda». Un residente con rol `proveedor` deja de ver sus propias solicitudes de categorías que no atiende, y no hay error: la lista simplemente sale más corta. | Comentado en la propia consulta, escrito en `docs/service-request.md`, y con un test estructural que falla si la cadena `node_access` aparece en el fichero del recurso. Es el mismo tipo de guard con el que SPEC 87 fijó la lista única de estados difundidos. |
| **La solicitud desaparece del listado por un `tid` huérfano.** El `INNER JOIN` a `taxonomy_term_data` es una decisión tomada, pero su fallo es invisible desde la API: el residente ve una solicitud menos y ningún mensaje. | Solo ocurre si un operador borra un término del vocabulario `service_category` teniendo solicitudes colgadas de él. Se diagnostica con una consulta escrita en `docs/service-request.md`: las filas de `field_data_field_category` cuyo `tid` no existe en `taxonomy_term_data`. La solución es de datos —reasignar la categoría—, no de código. Anotado además para el spec que permita **borrar** categorías, que es quien debe impedirlo de raíz. |
| **El endpoint responde una lista vacía a todo el mundo el día que se despliegue**, porque nada crea solicitudes todavía. Se leerá como un fallo del endpoint cuando es el estado real del sistema. | Escrito en la cabecera, en el alcance y en `docs/service-request.md`. La verificación de aceptación se hace sobre solicitudes cargadas a mano desde el back office, que es exactamente cómo se prueban hoy los campos de SPEC 86 y 87. |
| **`assigned_provider` denormalizado que nadie mantiene.** La instancia dice *«Se rellena a partir de la oferta adjudicada. No editar a mano»*, pero ningún flujo lo rellena y nada valida que coincida con el proveedor de `field_assigned_offer`. El listado puede mostrar una oferta de un proveedor y el nombre de otro. | Fuera del alcance de un endpoint de lectura: la coherencia la debe garantizar el flujo de adjudicación, que aún no existe. Este spec sirve los dos campos **tal como están almacenados**, sin inventar una preferencia entre ellos, y lo deja anotado como precondición del spec de adjudicación. Es la misma deuda que SPEC 87 dejó escrita sobre `field_rating_offer`. |
| **`?limit=-1` sobre un residente con cientos de solicitudes.** Devuelve todo en una respuesta, y el conteo de ofertas hace un `IN` con todos los nids. | Aceptado: es el contrato de paginación del módulo desde SPEC 15, ya vigente en claims y payments, y el alcance está acotado a **un** solicitante. Un residente con cientos de solicitudes de servicio no es un caso realista; si lo fuera, el remedio es quitar `-1` de este endpoint, no reescribir la paginación. |
| **La descripción viaja en crudo.** Si alguien cambia el formato de texto de la instancia de `plain_text` a uno que permita HTML, el listado empieza a servir marcado sin escapar. | El mismo riesgo que ya corre `GET /api/v1/claims` con este mismo campo compartido, y por eso la respuesta es la misma en los dos sitios: cambiarla aquí sola habría hecho que el mismo dato viajara de dos formas. `docs/service-request.md` deja escrito que el contrato depende del formato `plain_text` de la instancia. |
| **Dos idiomas de validación en el mismo query string.** `?category_id=abc` responde `422` y `?status=abc` no; quien lea solo este endpoint puede tomarlo por una incoherencia y «arreglarla» igualando los dos. | Está escrito como decisión, no como accidente, y `docs/service-request.md` marca `category_id` como el único estricto en su tabla de parámetros. La razón no es el parámetro sino su gemelo: `GET /api/v1/providers` ya lo trata así, y la coherencia que importa es la del **mismo parámetro entre endpoints hermanos**, no la de parámetros distintos dentro de uno. Un test fija los dos comportamientos, de modo que igualarlos rompe la suite. |
| **La `?status` inválida se traga en silencio.** Un cliente que escriba mal la clave verá la lista completa y creerá que el filtro funcionó. | Es el idioma del módulo y una decisión tomada, no un descuido. Lo que sí se fija es que la validación lee `myapi_services_request_statuses()` y no una lista escrita a mano: cuando el catálogo gane un séptimo estado, el filtro lo acepta sin tocar el recurso. Un test recorre las claves del catálogo y falla si alguna aparece tecleada en el fichero. |
