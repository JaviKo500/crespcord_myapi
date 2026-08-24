# 99 — Detalle de una solicitud para el proveedor (`GET /api/v1/service-requests/provider/{id}`)

- **Estado:** Implemented
- **Fecha:** 2026-08-24
- **Dependencias:**
  - `89-service-request-detail` (Implemented) — la **hermana exacta**. Es dueña
    de `myapi_service_request_item_dispatch()`,
    `myapi_service_request_detail()`, `myapi_service_request_viewer()`,
    `myapi_service_request_detail_row()`,
    `myapi_service_request_load_images()`,
    `myapi_service_request_load_offers()`,
    `myapi_service_request_build_detail()`,
    `myapi_service_request_build_offer()`,
    `myapi_service_request_build_file()` y de la ruta de ficheros
    `api/v1/service-requests/%/files/%`. Este spec **reutiliza las nueve sin
    tocarlas** y añade un segundo orquestador al lado del suyo. El detalle del
    residente responde byte a byte lo mismo.
  - `98-service-requests-provider-list` (Implemented) — el **precedente de forma
    y de ruta**. Dueña de `api/v1/service-requests/provider`, de
    `myapi_service_request_provider_dispatch()`, de la compuerta
    `403 provider_role_required`, de la regla de la `unit` (su decisión 5) que
    este spec **traslada al detalle**, y de la regla 2b de
    `myapi_service_request_viewer()`. Su **Riesgo 4** —«la `unit` aparece en el
    listado y desaparece en el detalle»— es lo que este spec cierra.
  - `78-provider-role` (Implemented) — dueña del rol `proveedor`, de
    `myapi_provider_role_is()` (la compuerta del `403`), de
    `myapi_provider_role_provider_ids()` y de
    `myapi_provider_role_has_offered()`.
  - `97-provider-mine-list` (Implemented) — el precedente de autorización de dos
    escalones: sin rol → `403 provider_role_required`; con rol pero sin vínculo
    → no es un error, es ausencia de datos.
  - `87-service-request-direct-status` (Implemented) — dueña del estado `direct`
    y la razón de que «adjudicada a mí» se resuelva por
    `field_assigned_provider` y no por la oferta.
  - `93-service-request-transactions-in-detail` (Implemented) — dueña de
    `myapi_service_request_load_transactions()` y de la clave `transactions`,
    que este spec sirve **sin recortar**.
  - `77-services-content-types-install` (Implemented) — dueña de los bundles y de
    todos los campos que se leen. **Cero cambios de esquema.**

**Objetivo:** Servir al proveedor el detalle de una solicitud de servicio en una
ruta propia, con compuerta de rol explícita, la misma regla de acceso que ya usa
el detalle general, la vivienda visible solo cuando el trabajo ya es suyo, y sus
propias ofertas bajo una clave que no admite dudas.

Cuatro notas que la cabecera fija:

- **Una ruta nueva, ni una función de acceso nueva.** El acceso lo sigue
  decidiendo `myapi_service_request_viewer()`, sin modificarla. Si este endpoint
  y el general discreparan alguna vez sobre quién entra, sería un bug, no un
  matiz de diseño.
- **La compuerta de rol es lo único que este endpoint exige de más.** El detalle
  general deja pasar a cualquier cuenta que opere un proveedor, tenga o no el rol
  `proveedor`. Aquí no: sin rol, `403 provider_role_required`, antes de cualquier
  consulta.
- **`unit` deja de contradecir al listado.** Visible cuando `assigned_provider`
  es uno de mis proveedores, `null` en el resto — la decisión 5 del SPEC 98,
  ahora también en el detalle.
- **Cero escritura, cero esquema, cero ruta de ficheros nueva.** Las imágenes y
  el adjunto siguen viajando por `GET /api/v1/service-requests/{id}/files/{fid}`,
  que ya usa esta misma regla de acceso.

---

## Alcance

### Dentro del alcance

**`resources/service_request.resource.inc`** (modificar — sigue siendo el único
fichero con lógica de este recurso):

- `myapi_service_request_provider_item_dispatch($nid)` (nueva) — dispatcher de
  `api/v1/service-requests/provider/%`: `GET` al detalle del proveedor,
  cualquier otro método a `405 method_not_allowed` **antes del token y antes de
  cualquier consulta**. Hermano de `myapi_service_request_provider_dispatch()`
  (SPEC 98) y de `myapi_service_request_item_dispatch()` (SPEC 89), no una rama
  dentro de ninguno de los dos.
- `myapi_service_request_provider_detail($nid)` (nueva) — la orquestación
  completa, en este orden fijo:
  1. `myapi_service_request_parse_id_param()` sobre el `nid` de la ruta →
     `404 not_found` si no es un entero positivo.
  2. `myapi_auth_require_access_token()` → `401 missing_authorization` /
     `401 invalid_token`.
  3. `myapi_provider_role_is($account)` → **`403 provider_role_required`** si
     falta el rol. Es el único escalón que este endpoint añade sobre el general.
  4. `myapi_provider_role_provider_ids($account)` → si está vacío,
     `403 forbidden`: hay rol pero no hay proveedor que operar, así que no hay
     acceso posible a ninguna solicitud.
  5. `myapi_service_request_detail_row($nid)` → `404 not_found` si no existe o
     está despublicada.
  6. `myapi_service_request_viewer($row, $uid)` **sin modificarla** → si no
     devuelve `'provider'`, `403 forbidden`. Un `'requester'` también es `403`
     aquí: este endpoint es del proveedor (decisión 4).
  7. Cargar imágenes, mis ofertas, el total de ofertas y las transacciones.
  8. Serializar.
- `myapi_service_request_provider_build_detail($row, array $images, array $my_offers, $offers_count, array $transactions, array $owned_provider_ids)`
  (nueva) — **pura, sin base de datos**. Envuelve a
  `myapi_service_request_build_item()` (SPEC 88), le aplica la regla de la `unit`
  del SPEC 98 y le añade las claves del detalle. Es la hermana de
  `myapi_service_request_build_detail()`, no una bifurcación dentro de ella.

**`myapi.module`** (modificar) — una entrada en `hook_menu()`:

- `api/v1/service-requests/provider/%` →
  `myapi_service_request_provider_item_dispatch`, `page arguments = [4]`,
  `access callback = TRUE` (la autorización es del endpoint, como en todo el
  módulo), `type = MENU_CALLBACK`. Convive sin ambigüedad con
  `api/v1/service-requests/%`: en Drupal 7 gana la ruta con el literal en la
  posición 3.

**`docs/service-request-provider.md`** (modificar) — el endpoint se documenta en
el **mismo fichero** que el listado del proveedor, que ya es la casa de la
familia `/service-requests/provider`. Se añade la sección del detalle y se
**borra** la nota de «diferencia deliberada» sobre la `unit` que el SPEC 98 dejó
ahí: deja de ser cierta.

**`tests/unit/ServiceRequestProviderDetailTest.php`** (nuevo) — hermano de
`ServiceRequestProviderListTest.php`.

### Fuera del alcance (para futuras specs)

- **Modificar `myapi_service_request_viewer()`.** Ni una línea. Este spec la
  consume; SPEC 98 fue la última que la amplió.
- **Modificar el detalle general `GET /api/v1/service-requests/{id}`.** Sigue con
  su clave `offers` filtrada y con `unit: null` para todo lector proveedor. La
  incoherencia del Riesgo 4 del SPEC 98 se cierra **añadiendo la respuesta
  correcta en la ruta del proveedor**, no cambiando la del residente. Unificar
  ambas o deprecar el acceso de proveedor a la ruta general es otra spec.
- **Una ruta de ficheros hermana**
  `/service-requests/provider/{id}/files/{fid}`. Se reutiliza la del SPEC 89 tal
  cual.
- **Cualquier escritura**: ofertar, editar la oferta, aceptar, rechazar,
  adjudicar, cerrar. Este endpoint solo lee.
- **Una clave `can_offer`** o cualquier señal calculada de «todavía puedo pujar».
  El cliente la deriva de `status`, `assigned_provider` y `my_offers`.
- **Enmascarar a la competencia.** `assigned_provider` sigue nombrando al ganador
  aunque sea un rival, exactamente como decidió el SPEC 98 (su decisión 10).
- **`offers` completas para el proveedor.** Sigue viendo solo las suyas; el total
  ajeno viaja como número en `offers_count` y nada más.
- **Cambios de esquema** (`myapi.install` no se toca) y **claves i18n nuevas**
  (`provider_role_required`, `forbidden`, `not_found`, `method_not_allowed`,
  `missing_authorization` e `invalid_token` ya existen).
- **El back office.** `hook_file_download()` y las reglas de nodo de Drupal no
  cambian.

---

## Modelo de datos

**Este spec no introduce ninguna estructura de datos nueva.** Cero cambios en
`myapi.install`, cero campos, cero tablas, cero términos. Todo lo que lee existe
desde el SPEC 77 y se sirve con los serializadores del SPEC 88, 89, 93 y 98.

Lo único que este spec define es **la forma de una respuesta**.

### La regla de acceso: la del detalle, sin un solo matiz

`myapi_service_request_viewer($row, $uid)` decide, y este spec no la toca. Para
un lector proveedor devuelve `'provider'` por cualquiera de estas tres vías, en
este orden:

| Regla | Condición | Cubre |
|---|---|---|
| **2** | Alguno de mis proveedores ya ofertó por la solicitud (`myapi_provider_role_has_offered()`). | Sigo viendo el trabajo por el que pujé, avance como avance, y aunque salga de mi categoría. |
| **2b** | `field_assigned_provider ∈` mis proveedores. **Cualquier estado**, `closed` y `cancelled` incluidos. | **El `direct` adjudicado a mí**, y todo trabajo ya ganado. |
| **3** | `status ∈ ('open','offered')` **∧** `field_assigned_offer` vacío **∧** `field_assigned_provider` vacío **∧** `field_category_tid ∈` mis categorías **∧** algún proveedor mío activo. | **El mercado abierto de mi categoría.** |

Un `direct` **ajeno** no entra por ninguna: nace adjudicado, así que la regla 3
lo descarta por definición, y la 2b apunta a otro. Eso es un `403 forbidden`, y
es la respuesta correcta.

**La equivalencia que hay que sujetar:** si
`GET /api/v1/service-requests/provider` lista una solicitud,
`GET /api/v1/service-requests/provider/{id}` sobre esa misma solicitud **debe**
responder `200`. Los conjuntos A ∪ B ∪ C del listado y las reglas 2 / 3 / 2b del
detalle son la misma frase escrita dos veces (Riesgo 1 del SPEC 98), y aquí se
hereda esa deuda tal cual.

### La respuesta

```json
{ "success": true, "data": { "service_request": { } } }
```

Un único objeto bajo `service_request`, la misma envoltura que usa el detalle
del SPEC 89.

### El objeto: 19 claves, siempre las 19, en este orden

Las **13 del ítem del listado del proveedor** (SPEC 98), sin una sola
diferencia, seguidas de **6 propias del detalle**.

| # | Clave | Tipo | Notas |
|---|---|---|---|
| 1 | `id` | int | |
| 2 | `title` | string | |
| 3 | `description` | string | Tal cual se guardó, con sus saltos de línea. |
| 4 | `status` | string \| null | `open`, `direct`, `offered`, `assigned`, `closed`, `cancelled`. |
| 5 | `category` | object | `{id, code, name}`. |
| 6 | `unit` | object \| null | `{id, name}`. **Solo si el trabajo ya es mío** — ver abajo. |
| 7 | `offers_count` | int | El total **real**, competencia incluida. |
| 8 | `assigned_offer` | object \| null | `{id, status}`. |
| 9 | `assigned_provider` | object \| null | `{id, name}`. Sin enmascarar, aunque gane un rival. |
| 10 | `created` | string \| null | `Y-m-d\TH:i:s`. |
| 11 | `desired_start` | string \| null | `Y-m-d\TH:i:s`. |
| 12 | `requester` | object \| null | `{id, name}`. |
| 13 | `condominium` | object \| null | `{id, name}`. Viaja siempre: nombra la zona sin nombrar la puerta. |
| 14 | `viewer` | string | **Siempre `"provider"`.** Constante, y a propósito — ver decisiones. |
| 15 | `images` | array | Lista de `{id, url, filename}`. Vacía, nunca `null`. |
| 16 | `attachment` | object \| null | `{id, url, filename}`. |
| 17 | `closed_at` | string \| null | `Y-m-d\TH:i:s`. `null` mientras no esté `closed`. |
| 18 | `my_offers` | array | **Solo mis ofertas.** Vacía, nunca `null`. |
| 19 | `transactions` | array | La línea de tiempo **completa**, sin recortar. |

Ninguna clave aparece ni desaparece. Un objeto anidado ausente es **un `null`
entero**, nunca `{id: null, name: null}`.

### La regla de la `unit`

```
unit = (assigned_provider.id ∈ proveedores de mi cuenta) ? {id, name} : null
```

Idéntica a la decisión 5 del SPEC 98, misma implementación y mismo
razonamiento: el número de piso no aporta nada a la decisión de pujar y sí dice
dónde vive una persona concreta, pero en cuanto el trabajo es mío voy a ir a esa
casa y la dirección es el dato operativo del encargo.

La comparación es contra **`assigned_provider` ya construida**, jamás contra la
columna cruda. Esa clave se resolvió contra `node` con bundle y `status = 1`,
así que una adjudicación que apunta a un nodo borrado da
`assigned_provider: null` y por tanto `unit: null`: la regla falla hacia el lado
cerrado, que es la única dirección en la que una regla de privacidad puede
fallar.

**Esto es lo que cierra el Riesgo 4 del SPEC 98:** el proveedor ya no ve el
apartamento en la lista de «mis trabajos» y lo pierde al pulsar.

### `my_offers`: mis ofertas y solo las mías

```
my_offers = myapi_service_request_load_offers($nid, $provider_ids)
```

La misma llamada que ya hace el detalle del SPEC 89 para un lector proveedor,
con `$provider_ids` = **los proveedores de mi cuenta** (nunca vacío, porque un
`$provider_ids` vacío significaría «todas» y aquí ya se cortó con un `403` en el
paso 4). Cada elemento:

```json
{ "id": 0, "provider": { "id": 0, "name": "", "logo": null },
  "amount": null, "message": "", "status": null, "created": null }
```

El nombre de la clave es lo único que cambia respecto al SPEC 89: allí se llama
`offers` y viaja filtrada, lo que obliga al cliente a saber que está viendo un
subconjunto. Aquí el nombre lo dice.

### Ficheros privados

`images[].url` y `attachment.url` apuntan a
`GET /api/v1/service-requests/{id}/files/{fid}` — la ruta del SPEC 89, sin
cambios. Esa ruta ya autoriza con `myapi_service_request_viewer()`, así que
**cualquiera que pueda leer este detalle puede descargar sus ficheros, y nadie
más**. `myapi_service_request_build_file()` las construye igual que hoy.

---

## Plan de implementación

Cinco pasos. Cada uno deja el módulo funcionando y es commiteable por separado.
**Ningún paso modifica una función existente**: los tres primeros solo añaden.

### 1. El serializador

`myapi_service_request_provider_build_detail($row, array $images, array $my_offers, $offers_count, array $transactions, array $owned_provider_ids)`
en `resources/service_request.resource.inc`, junto a
`myapi_service_request_build_detail()`.

Pura, sin base de datos, construida por delegación como manda el precedente del
SPEC 97 y 98:

```php
// Las 13 del listado del proveedor, incluida la regla de la unit, de la
// función que ya la implementa. No se reescribe ninguna de las trece.
$item = myapi_service_request_provider_build_item(
  $row, [(int) $row->nid => (int) $offers_count], $owned_provider_ids
);

return array_merge($item, [
  'viewer'       => 'provider',
  'images'       => array_values($images),
  'attachment'   => myapi_service_request_build_file(/* ... */),
  'closed_at'    => /* format_date o NULL */,
  'my_offers'    => array_values($my_offers),
  'transactions' => array_values($transactions),
]);
```

`myapi_service_request_provider_build_item()` espera en `$row` los alias de
`myapi_service_request_fetch()` más `requester_uid`, `condominium_id`,
`condominium_name` y `requester_name`. **`myapi_service_request_detail_row()`
proyecta exactamente esos alias** — hay que verificarlo campo por campo en este
paso y, si falta alguno, añadirlo a la proyección del `detail_row` (aditivo: el
detalle general ignora una columna de más).

Sin ruta todavía. Nada la llama. El módulo responde igual que antes.

### 2. El orquestador

`myapi_service_request_provider_detail($nid)`, hermano de
`myapi_service_request_detail()`. Los ocho pasos del Alcance, en ese orden
exacto:

```php
$nid = myapi_service_request_parse_id_param(...);      // 404 not_found
$token = myapi_auth_require_access_token();            // 401
$account = (object) ['uid' => (int) $token->uid];

if (!myapi_provider_role_is($account)) {
  myapi_error('provider_role_required', 403);          // la compuerta propia
}
$provider_ids = myapi_provider_role_provider_ids($account);
if (!$provider_ids) {
  myapi_error('forbidden', 403);                       // rol sin proveedor
}

$row = myapi_service_request_detail_row($nid);
if (!$row) { myapi_error('not_found', 404); }

if (myapi_service_request_viewer($row, $account->uid) !== 'provider') {
  myapi_error('forbidden', 403);                       // requester incluido
}
```

Y después las cuatro cargas, cada una con la función que ya existe:
`myapi_service_request_load_images($nid)`,
`myapi_service_request_load_offers($nid, $provider_ids)`, el total de ofertas
con `myapi_service_request_offer_counts_by_nid([$nid])`, y
`myapi_service_request_load_transactions($nid)`. Respuesta con
`myapi_respond(['service_request' => ...])`.

Sigue sin ruta. Sigue sin cambiar nada.

### 3. El dispatcher

`myapi_service_request_provider_item_dispatch($nid = NULL)`, calcado de
`myapi_service_request_item_dispatch()`:

```php
if (myapi_request_method() === 'GET') {
  myapi_service_request_provider_detail($nid);
}
else {
  myapi_error('method_not_allowed', 405);
}
```

El `405` sale **antes del token y antes de cualquier consulta**: el método está
mal pregunte quien pregunte.

### 4. La ruta

Una entrada en `hook_menu()` de `myapi.module`, pegada a la del listado del
proveedor:

```php
$items['api/v1/service-requests/provider/%'] = [
  'page callback'   => 'myapi_service_request_provider_item_dispatch',
  'page arguments'  => [4],
  'access callback' => TRUE,
  'type'            => MENU_CALLBACK,
  'file'            => 'resources/service_request.resource.inc',
];
```

`page arguments => [4]`, no `[3]`: el comodín está en la quinta posición
(`api`/`v1`/`service-requests`/`provider`/`%`).

No compite con `api/v1/service-requests/%/files/%` ni con
`api/v1/service-requests/%/cancel` — cinco componentes frente a seis. Y
`api/v1/service-requests/provider` (cuatro) sigue resolviendo el listado.
`myapi.info` **no se toca**: no hay ficheros nuevos.

`drush cc all`. A partir de aquí el endpoint responde.

### 5. Los tests y la documentación

`tests/unit/ServiceRequestProviderDetailTest.php`, hermano de
`ServiceRequestProviderListTest.php`. Lo que cubre, como mínimo:

- **La compuerta de rol**: sin rol `proveedor` → `403 provider_role_required`,
  aunque la cuenta esté referenciada por un nodo `proveedor`.
- **Rol sin proveedor operable** → `403 forbidden`.
- **Las tres vías de acceso**, una por test: ya oferté (regla 2), `direct`
  adjudicada a mí (2b), mercado abierto de mi categoría (3).
- **Los `403` que importan**: `direct` ajena, solicitud de otra categoría,
  solicitud ya adjudicada a un rival por la que nunca oferté, **y el solicitante
  de la propia solicitud**.
- **`404`**: nid inexistente, nid despublicado, nid no numérico.
- **`405`** en `POST`, `PUT` y `DELETE`, sin token.
- **La regla de la `unit`**: `null` en el mercado abierto; `{id, name}` en un
  trabajo adjudicado a mi proveedor; `null` cuando `assigned_provider` es `null`
  por nodo borrado.
- **`my_offers`** trae solo las mías y `offers_count` trae el total real cuando
  hay competencia.
- **La equivalencia con el listado** (el test que sujeta el Riesgo 1 del
  SPEC 98): sobre el mismo conjunto de solicitudes, toda fila que
  `myapi_service_request_provider_scope()` devuelve responde `200` aquí, y
  ninguna que excluya responde `200`.
- **No regresión**: `GET /api/v1/service-requests/{id}` y
  `GET /api/v1/service-requests/provider` responden exactamente igual que antes.

Documentación en `docs/service-request-provider.md`, en el **mismo commit**: la
sección del nuevo endpoint siguiendo la plantilla de `CLAUDE.md`, y **borrar** la
nota que el SPEC 98 dejó ahí describiendo la desaparición de la `unit` en el
detalle como diferencia deliberada — deja de ser cierta en esta ruta.

---

## Criterios de aceptación

### Método y autenticación

- [x] `POST`, `PUT`, `PATCH` y `DELETE` sobre
      `/api/v1/service-requests/provider/{id}` responden
      `405 method_not_allowed`, **sin token** y sin tocar la base de datos.
- [x] Sin cabecera `Authorization` → `401 missing_authorization`.
- [x] Con token caducado, revocado o inventado → `401 invalid_token`.

### La compuerta de rol

- [x] Una cuenta autenticada **sin** el rol `proveedor` →
      `403 provider_role_required`, aunque exista un nodo `proveedor` que la
      referencie.
- [x] Una cuenta **con** el rol `proveedor` pero sin ningún nodo `proveedor` que
      la referencie → `403 forbidden`.
- [x] La compuerta de rol se evalúa **antes** de cargar la solicitud: un nid
      inexistente pedido por una cuenta sin rol responde
      `403 provider_role_required`, no `404`.

### Las tres vías de acceso

- [x] Una solicitud `open` de una de mis categorías, sin adjudicar, con algún
      proveedor mío activo → `200` (regla 3).
- [x] Una solicitud `offered` de mi categoría, sin adjudicar → `200` (regla 3).
- [x] Una solicitud por la que uno de mis proveedores **ya ofertó** → `200` en
      **cualquier estado**, incluidas `assigned`, `closed` y `cancelled`, y
      **aunque haya salido de mi categoría** (regla 2).
- [x] Una solicitud `direct` **adjudicada a uno de mis proveedores** → `200`
      (regla 2b).
- [x] Una solicitud `assigned`, `closed` o `cancelled` adjudicada a uno de mis
      proveedores → `200` (regla 2b).

### Los `403` y los `404`

- [x] Una solicitud `direct` **adjudicada a otro proveedor** → `403 forbidden`.
- [x] Una solicitud `open` de una categoría que no es mía → `403 forbidden`.
- [x] Una solicitud ya adjudicada a un rival por la que nunca oferté →
      `403 forbidden`.
- [x] Una solicitud de mi categoría cuando **ningún** proveedor mío está activo →
      `403 forbidden`.
- [x] **El solicitante de la propia solicitud**, si además tiene el rol
      `proveedor` y no encaja en ninguna regla de proveedor → `403 forbidden`.
      Este endpoint no es suyo.
- [x] Un nid que no existe → `404 not_found`.
- [x] Un nid de un nodo despublicado → `404 not_found`.
- [x] Un nid de un nodo que **no** es del bundle `service_request` →
      `404 not_found`.
- [x] `abc`, `0`, `-1` y `1.5` como nid → `404 not_found`, sin consulta.

### La equivalencia con el listado

- [x] Sobre el mismo conjunto de solicitudes y la misma cuenta: **toda**
      solicitud que `GET /api/v1/service-requests/provider` devuelve responde
      `200` en este endpoint.
- [x] **Ninguna** solicitud que ese listado excluye responde `200` en este
      endpoint.

### Contenido de la respuesta

- [x] La respuesta es
      `{ "success": true, "data": { "service_request": { ... } } }`.
- [x] El objeto trae **exactamente 19 claves**, siempre las mismas y en el orden
      documentado: `id`, `title`, `description`, `status`, `category`, `unit`,
      `offers_count`, `assigned_offer`, `assigned_provider`, `created`,
      `desired_start`, `requester`, `condominium`, `viewer`, `images`,
      `attachment`, `closed_at`, `my_offers`, `transactions`.
- [x] `viewer` vale `"provider"` en toda respuesta `200`.
- [x] Las 13 primeras claves son **byte a byte** las del ítem del mismo nid en
      `GET /api/v1/service-requests/provider`.
- [x] `category` trae `{id, code, name}`; `code` es `""` y no `null` cuando el
      término no tiene código.
- [x] `requester` trae `{id, name}` con el nombre resuelto por la regla del
      SPEC 09 (`field_nombre + field_apellidos`, o `users.name`).
- [x] `condominium` viaja **siempre** que exista, tanto en el mercado abierto
      como en un trabajo adjudicado.
- [x] `images` y `my_offers` y `transactions` son **siempre arrays**, nunca
      `null`, y vacíos cuando no hay nada.
- [x] `attachment` es `null` cuando no hay adjunto, y `{id, url, filename}`
      cuando lo hay.
- [x] `closed_at` es `null` en toda solicitud que no esté `closed`.
- [x] Un objeto anidado ausente es un `null` entero, nunca
      `{id: null, name: null}`.

### La regla de la `unit`

- [x] Solicitud del mercado abierto de mi categoría, sin adjudicar →
      `unit: null`.
- [x] Solicitud adjudicada a **uno de mis proveedores** → `unit: {id, name}`, con
      `name` = `field_nombre_vivienda`.
- [x] Solicitud adjudicada a **un rival** → `unit: null`.
- [x] Solicitud con `assigned_provider: null` (nodo proveedor borrado o
      despublicado) → `unit: null`.
- [x] La cuenta opera los proveedores A y B; una solicitud adjudicada a B →
      `unit` visible, sin depender de por cuál de los dos se obtuvo el acceso.
- [x] El mismo nid da la **misma** `unit` en el listado del proveedor y en este
      detalle. *(Cierra el Riesgo 4 del SPEC 98.)*

### `my_offers` y `offers_count`

- [x] `my_offers` contiene **solo** las ofertas cuyo `field_provider` es uno de
      mis proveedores.
- [x] Con cuatro ofertas en la solicitud, una mía: `my_offers` tiene 1 elemento y
      `offers_count` vale `4`.
- [x] Sin ofertas mías: `my_offers` es `[]` y la respuesta sigue siendo `200`.
- [x] Cada oferta trae
      `{id, provider: {id, name, logo}, amount, message, status, created}`.
- [x] `assigned_provider` nombra al ganador **aunque sea un rival**, y su nombre
      no se enmascara.

### Ficheros privados

- [x] `images[].url` y `attachment.url` apuntan a
      `/api/v1/service-requests/{id}/files/{fid}`, la ruta del SPEC 89.
- [x] Un proveedor con acceso `200` a este detalle descarga los bytes de esas
      URLs con `200`.
- [x] Un proveedor con `403` en este detalle recibe `403` también en esas URLs.
- [x] **No** existe ninguna ruta
      `/api/v1/service-requests/provider/{id}/files/{fid}`.

### No regresión

- [x] `GET /api/v1/service-requests/{id}` responde **exactamente igual que
      antes** para residente y para proveedor: mismas claves, mismo orden,
      `unit: null` para todo lector proveedor y clave `offers` (no `my_offers`).
- [x] `GET /api/v1/service-requests/provider` responde exactamente igual que
      antes.
- [x] `GET /api/v1/service-requests` (listado del residente) responde exactamente
      igual que antes.
- [x] `myapi_service_request_viewer()` no tiene ni una línea distinta.
- [x] La suite completa (`scripts/run-unit-tests.sh`) pasa en verde.

### Código y despliegue

- [x] `myapi.info` no cambia: no hay ficheros nuevos de código.
- [x] `myapi.install` no cambia: cero esquema.
- [x] Ninguna clave i18n nueva.
- [x] Ninguna consulta de este spec lleva `addTag('node_access')`, igual que el
      SPEC 88, 89 y 98.
- [x] Todo el código y los comentarios en inglés; los mensajes al usuario salen
      del catálogo.
- [x] `docs/service-request-provider.md` documenta el endpoint y **ya no**
      contiene la nota sobre la `unit` que desaparece en el detalle.
- [x] `drush cc all` y la ruta responde.

---

## Decisiones

### 1. Una ruta hermana, no una ampliación del detalle general

**Elegido:** `GET /api/v1/service-requests/provider/{id}`, con su propio
dispatcher y su propio orquestador.

**Descartado (a):** meter la compuerta de rol y la nueva regla de `unit` dentro
de `myapi_service_request_detail()`.

**Descartado (b):** una ruta nueva que delegue en el detalle general con un
parámetro de lector.

La (a) cambiaría la respuesta que el cliente ya consume hoy: pasar `offers` a
`my_offers` y destapar la `unit` son cambios rompientes para una app en
producción, y exigir el rol `proveedor` dejaría fuera a cuentas que hoy leen sin
él. La (b) convierte una función de orquestación en un `if` de dos respuestas,
que es justo lo que el SPEC 89 evitó al separar los dispatchers.

El precio es real y está aceptado: **dos endpoints devuelven casi lo mismo sobre
el mismo nodo**. Se paga porque el listado ya está partido en dos rutas por la
misma razón (decisión 3 del SPEC 98) y la simetría `/provider` para lista y
detalle es lo que el cliente espera.

### 2. La regla de acceso no se toca

`myapi_service_request_viewer()` se consume tal cual. Ni una regla nueva, ni un
parámetro, ni un `if`.

El SPEC 98 ya la amplió con la regla 2b, que es exactamente el caso `direct` que
pedía este spec. Escribir aquí una segunda definición de «quién puede leer esto»
crearía una **tercera** copia de la regla de acceso —ya hay dos, ver Riesgos— y
la primera que se olvidara de actualizar sería la que abriera el agujero.

### 3. La compuerta de rol se añade, y solo aquí

**Elegido:** `403 provider_role_required` cuando falta el rol `proveedor`.

El detalle general no lo exige: le basta con que
`myapi_provider_role_provider_ids()` devuelva algo. Eso es correcto **para esa
ruta**, que sirve a residentes y proveedores por igual y no puede rechazar por
rol. Esta ruta sí puede: es del proveedor, y el precedente exacto es el listado
del SPEC 97 y 98.

No es solo simetría. Es el punto donde un administrador puede cortar el acceso
de una cuenta al canal del proveedor quitándole un rol, sin desvincular nodos.

### 4. Un `requester` que además sea proveedor recibe `403`

**Descartado:** dejar entrar al solicitante a su propia solicitud por esta ruta.

Sería la respuesta amable, y es la equivocada. Un residente que además tenga el
rol `proveedor` recibiría por `/provider/{id}` una respuesta con `my_offers`
vacío y `unit` según la adjudicación, en vez de su detalle completo — la misma
solicitud contada mal. La ruta correcta para él existe y es
`/api/v1/service-requests/{id}`.

`myapi_service_request_viewer()` devuelve `'requester'` y este endpoint solo
acepta `'provider'`. Una línea, sin ambigüedad.

### 5. Rol sin proveedor operable es `403`, no `200` vacío

El SPEC 97 estableció que «rol sin vínculo» no es un error: el listado devuelve
`200` con cero elementos, porque una lista vacía es una respuesta legítima.

Un detalle no tiene esa salida. Sobre un recurso concreto solo caben «aquí lo
tienes» o «no puedes verlo», y sin ningún proveedor que operar no hay ninguna
regla de acceso que pueda cumplirse. `403 forbidden` y no `404`: el recurso
existe, el lector no llega.

### 6. `unit` visible cuando el trabajo ya es mío

Se traslada al detalle la decisión 5 del SPEC 98, sin cambiarle una coma.

**Descartado (a):** `unit: null` siempre, como hace hoy el detalle general. Es lo
que produjo el Riesgo 4 del SPEC 98: el proveedor ve el apartamento en «mis
trabajos», pulsa, y desaparece.

**Descartado (b):** `unit` siempre visible. Regalaría el número de piso de un
vecino a todo proveedor de la categoría por el mero hecho de abrir el detalle de
una solicitud abierta.

La regla se compara contra `assigned_provider` **ya construida** para que falle
hacia el lado cerrado.

### 7. `my_offers` y no `offers`

**Elegido:** clave propia, con el nombre diciendo lo que trae.

**Descartado:** mantener `offers` filtrada, como hace el detalle general.

Una clave llamada `offers` que a veces trae todas y a veces solo las tuyas obliga
al cliente a saber por qué ruta llegó para interpretarla. Con `my_offers` no hay
nada que saber. Es además la razón por la que la decisión 1 tuvo que ser una ruta
nueva: renombrar `offers` in situ habría roto la app.

El total ajeno sigue viajando, como número, en `offers_count`.

### 8. `viewer` viaja aunque sea constante

**Descartado:** quitarla, ya que siempre vale `"provider"`.

Se queda para que el modelo del cliente Flutter sea **el mismo objeto** en las
dos rutas de detalle: mismo parser, mismo tipo, y una clave constante que no
cuesta nada. Quitarla obligaría a mantener dos clases casi idénticas.

### 9. `transactions` completa, sin recortar

El proveedor con acceso al detalle ve la línea de tiempo entera del SPEC 93.

**Descartado:** filtrarla a los eventos «suyos». No hay criterio de propiedad
estable en una transición de estado, y recortarla dejaría huecos que el cliente
pintaría como una historia incompleta. Si algún evento no debe verlo un
proveedor, el sitio para decidirlo es el SPEC 93, no aquí.

### 10. Se reutiliza la ruta de ficheros del SPEC 89

**Descartado:** `/api/v1/service-requests/provider/{id}/files/{fid}`.

Sería código nuevo para tomar **exactamente la misma decisión**: esa ruta ya
autoriza con `myapi_service_request_viewer()`, la misma función que autoriza este
detalle. Dos rutas, una regla, cero divergencia posible.

### 11. Sin clave `can_offer`

**Descartado:** una señal calculada de «todavía puedo pujar por esto».

El cliente la deriva de `status`, `assigned_provider` y `my_offers`, que ya
viajan. Añadirla ataría la respuesta a una regla de negocio que hoy vive en la
función de acceso y mañana podría no coincidir. Añadirla más adelante es aditivo.

### 12. La documentación va en `docs/service-request-provider.md`

**Descartado:** un fichero nuevo `service-request-provider-detail.md`.

`/service-requests/provider` y `/service-requests/provider/{id}` son la misma
familia y comparten la regla de acceso, la regla de la `unit` y trece claves.
Separarlos garantizaría que una de las dos descripciones se quedara vieja.

### 13. Cero cambios de esquema y cero i18n nuevo

Todo lo que se lee existe desde el SPEC 77 y todos los códigos de error ya están
en el catálogo. Un spec de lectura que toca `myapi.install` es un spec que hace
dos cosas.

---

## Riesgos

### 1. La regla de acceso queda escrita **tres** veces

El SPEC 98 dejó dos formas de la misma frase:
`myapi_service_request_provider_scope()` (conjuntos A ∪ B ∪ C, como SQL
paginable) y `myapi_service_request_viewer()` (reglas 2 / 2b / 3, como decisión
por fila). Este spec no añade una tercera **implementación**, pero sí un **tercer
consumidor** que puede discrepar de la primera.

El síntoma no falla en voz alta: un ítem que el listado del proveedor pinta y que
al pulsarlo responde `403`, o al revés, un detalle accesible que nunca aparece en
ninguna lista.

**Mitigación:** el bloque «La equivalencia con el listado» de los criterios de
aceptación es un test, no una intención — `ServiceRequestProviderDetailTest`
recorre el mismo conjunto de solicitudes por las dos vías y exige que coincidan.
Es el mismo contrato que `ServiceRequestProviderListTest` sujeta para el detalle
general.

### 2. Dos detalles del mismo nodo que responden distinto

Sobre la misma solicitud, un mismo proveedor obtiene `unit: null` por
`/service-requests/{id}` y `unit: {id, name}` por
`/service-requests/provider/{id}`. Ambas son deliberadas y ambas son correctas
según su spec, pero visto desde fuera parece un bug.

**Mitigación:** queda documentado en `docs/service-request-provider.md` como
diferencia explícita entre las dos rutas, con la razón. El trabajo evidente para
la spec que continúe esta es **deprecar el acceso de proveedor a la ruta
general** y dejar una sola respuesta por lector; este spec no lo hace porque
rompería la app en producción.

### 3. `myapi_service_request_detail_row()` puede no proyectar todo lo que el ítem del proveedor necesita

`myapi_service_request_provider_build_item()` fue escrita contra las filas de
`myapi_service_request_fetch()`, no contra las de `detail_row()`. Si falta un
alias, el fallo no es una excepción: es una clave que sale `null` o `""` sin que
nada se queje.

**Mitigación:** el paso 1 del plan exige verificar la proyección **alias por
alias** antes de escribir nada, y los criterios de contenido incluyen la
comparación byte a byte de las 13 primeras claves contra el ítem del listado para
el mismo nid. Si falta algo, ese test lo pinta.

### 4. La `unit` viaja a quien ya no debería verla

La regla abre la vivienda al proveedor adjudicado **en cualquier estado**,
`closed` y `cancelled` incluidos. Un trabajo cancelado hace un año sigue
mostrando el piso al que iba a ir.

Es la misma superficie que el listado del SPEC 98 ya expone y no se amplía aquí.
Se acepta porque la alternativa —caducar la visibilidad por estado o por fecha—
es una regla de retención que ninguna spec ha tomado todavía, y tomarla solo en
el detalle volvería a descuadrarlo del listado.

### 5. El nombre del solicitante viaja a todo el mercado

`requester` está entre las 13 claves heredadas, así que cualquier proveedor
activo de la categoría lee el nombre completo de quien pidió el servicio con solo
abrir el detalle de una solicitud abierta. Sin dirección —la `unit` es `null`
ahí— pero con nombre y condominio.

Heredado del Riesgo 6 del SPEC 98 y no ampliado: el listado ya lo servía. Si
alguna vez se decide enmascararlo, el sitio es
`myapi_service_request_provider_build_item()`, y el cambio cae solo en las dos
rutas del proveedor.

### 6. Una ruta más colgando de `api/v1/service-requests`

Son ya cinco: la colección, `/provider`, `/%`, `/%/files/%`, `/%/cancel`, y ahora
`/provider/%`. Drupal 7 resuelve por número de componentes y literal en la
posición, así que no hay ambigüedad, pero la superficie de `hook_menu()` crece y
un futuro `/{id}/algo` mal colocado sí podría chocar.

**Mitigación:** el criterio de aceptación exige comprobar que las cinco rutas
anteriores siguen respondiendo lo mismo tras el `drush cc all`.

### 7. Cuatro consultas por detalle, sin caché

El endpoint hace una consulta para la fila, una para las imágenes, una para mis
ofertas, una para el conteo y una para las transacciones. Es exactamente lo que
ya hace el detalle general, y ninguna se apoya en un índice que este spec haya
comprobado.

Se acepta: es un detalle de un único nodo, no un listado paginable, y no crece
con el marketplace. Si algún día pesa, el sitio es el detalle general primero.

---

## Lo que **no** está en este spec

- Modificar `myapi_service_request_viewer()`.
- Modificar `GET /api/v1/service-requests/{id}`, ni su clave `offers`, ni su
  `unit: null`, ni deprecar el acceso de proveedor a esa ruta.
- Una ruta de ficheros `/api/v1/service-requests/provider/{id}/files/{fid}`.
- Cualquier escritura: ofertar, editar, aceptar, rechazar, adjudicar o cerrar.
- Una clave `can_offer` o cualquier señal calculada de si todavía puedo pujar.
- Enmascarar a la competencia en `assigned_provider`.
- Servir al proveedor las ofertas ajenas.
- Caducar la visibilidad de la `unit` por estado o por fecha.
- Enmascarar el nombre del solicitante en el mercado abierto.
- Cambios de esquema, claves i18n nuevas y cualquier cambio en el back office.

Cada una de esas cosas, si llega, va en su propia spec.
