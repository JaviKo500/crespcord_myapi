# 103 — Detalle de una oferta (`GET /api/v1/service-offers/provider/{id}` y `GET /api/v1/service-offers/{id}`)

> **Estado:** Approved · **Fecha:** 2026-08-26
> **Objetivo:** Servir el detalle de una oferta en dos rutas propias —una para el
> proveedor que la envió y otra para el residente que la recibió— con las quince
> claves del serializador compartido más el contexto referencial de su solicitud:
> condominio, vivienda y solicitante, cada uno visible según quién pregunta.

**Depende de:**

- `100-service-offer-create` (Implemented) — dueña de
  `resources/service_offer.resource.inc`, de `includes/myapi.service_offer.inc`
  y de **`myapi_service_offer_build()`, las quince claves que este spec sirve
  íntegras y sin tocar**. También de la frase *"no hay `GET` de la colección a
  propósito"*, que este spec no revierte: sigue sin haber colección; hay dos
  detalles de un ítem.
- `102-service-offers-provider-list` (Implemented) — la que dejó **este spec
  escrito en su sección "Fuera de alcance"** (*"El detalle de una oferta
  (`GET /api/v1/service-offers/{id}`)"*), la dueña de
  `includes/myapi.service_offer_query.inc`, de
  `myapi_service_offer_provider_scope()` —la compuerta de pertenencia que este
  spec reutiliza sin modificar— y del razonamiento de enrutado *"Drupal 7
  resuelve el literal antes que el comodín"*, que aquí se cobra.
- `99-service-request-provider-detail` (Implemented) — el **precedente exacto de
  forma**: dos detalles hermanos del mismo dato, cada uno con su ruta, su
  despachador y su compuerta, construidos por delegación sobre un serializador
  compartido y nunca por bifurcación dentro de él. De ella salen también las dos
  reglas de visibilidad que este spec traslada de la solicitud a la oferta.
- `98-service-requests-provider-list` (Implemented) — dueña de la **regla de la
  `unit`** (*el dato operativo aparece cuando el trabajo ya es mío*) que este
  spec aplica a `unit` y extiende a `requester`.
- `89-service-request-detail` (Implemented) — dueña de
  `myapi_service_request_viewer()`, **la única función de acceso a una
  solicitud**, que este spec usa sin modificar para decidir quién es el
  residente. También de la clave `offers`, dentro de la cual el residente ya lee
  estas quince claves hoy — lo que obliga a la sección 1 a justificar por qué
  existe la ruta nueva.
- `78-provider-role` (Implemented) — dueña del rol `proveedor`, de
  `myapi_provider_role_is()` (la compuerta del `403`) y de
  `myapi_provider_role_provider_ids()` (la que decide qué proveedor es de quién).
- `77-services-content-types-install` (Implemented) — dueña de los bundles
  `service_offer` y `service_request` y de **`field_request`, el campo de
  referencia que el SPEC 102 nombró mal en su plan** (`field_offer_request` no
  existe) y que es además **compartido con otro bundle**: el `innerJoin` al
  `node` de la solicitud es lo único que mantiene fuera al bundle ajeno.
  **Cero cambios de esquema.**

Cuatro notas que la cabecera fija:

- **Dos rutas, ni una función de acceso nueva.** El proveedor pasa por
  `myapi_service_offer_provider_scope()` (SPEC 102); el residente, por
  `myapi_service_request_viewer()` (SPEC 89). Si alguna vez este endpoint y el
  detalle de la solicitud discreparan sobre quién entra, sería un bug, no un
  matiz.
- **Las quince claves salen de `myapi_service_offer_build()` y no se
  reescriben.** Lo nuevo se anexa detrás, nunca se intercala.
- **La visibilidad es lo único que separa las dos respuestas.** `requester` y
  `unit` en la del proveedor están sujetos a *"el trabajo ya es mío"*; en la del
  residente, `unit` viaja siempre y `requester` no viaja.
- **Cero escritura, cero esquema, cero backfill.**

---

## 1 — Por qué existen estos dos endpoints

**El del proveedor** cierra lo que el SPEC 102 dejó abierto por escrito. Aquel
listado es **referencial por decisión** —ocho claves por ítem— y remite el
detalle completo a `GET /api/v1/service-requests/provider/{id}`, dentro de
`my_offers`. Eso obliga hoy a que, para leer las quince claves de **una** oferta,
el proveedor cargue el detalle entero de la solicitud: sus imágenes, su timeline,
el contador de la competencia y el resto de sus propias ofertas. Una fila del
listado no se puede abrir.

**El del residente** responde a lo mismo desde el otro lado. Las ofertas ya le
llegan dentro de `GET /api/v1/service-requests/{id}`, en `offers`, con las quince
claves, y eso no cambia. Lo que hoy no tiene respuesta es **abrir una oferta
desde una notificación o un enlace directo**: el cliente conoce el nid de la
oferta y no el de la solicitud, y para pintar una pantalla de una oferta tiene
que descargar la solicitud completa —con las ofertas rivales, sus importes y sus
mensajes— para quedarse con una de ellas.

Ninguno de los dos publica un contador ni un agregado, así que ninguno puede
desincronizarse con `offers_count`, que es lo que el SPEC 100 protegía cuando
escribió *"no hay `GET` de la colección a propósito"*. **Esa decisión no se
revierte: se delimita.** Hablaba de servir *el conjunto* de ofertas de una
solicitud, que es la competencia; esto sirve *una* oferta a quien ya tiene
derecho a leerla.

---

## 2 — Alcance

**Dentro:**

**Dos rutas nuevas**, ambas autenticadas por Bearer, ambas `GET` y nada más:

- `GET /api/v1/service-offers/provider/{id}` — el proveedor que la envió. Exige
  el rol `proveedor` (`403 provider_role_required`) antes de cualquier consulta.
- `GET /api/v1/service-offers/{id}` — el residente que la recibió. No exige
  ningún rol: no existe un rol `residente` en el módulo, y lo que da acceso es
  ser el solicitante de la solicitud sobre la que cuelga la oferta.

`POST`, `PUT`, `PATCH` y `DELETE` sobre cualquiera de las dos responden
`405 method_not_allowed` **antes del token y antes de cualquier consulta**, como
todo despachador del módulo.

**`includes/myapi.service_offer_query.inc`** (modificar — ya es la casa de las
consultas de este recurso):

- `myapi_service_offer_detail_row($nid)` (nueva) — la fila de la oferta con
  todos los alias que `myapi_service_offer_build()` consume, más `request_id`.
  `innerJoin` a `field_request` y de ahí al `node` de la solicitud publicada, que
  es lo que mantiene fuera al otro bundle que comparte ese campo. `FALSE` si la
  oferta no existe, no está publicada, no es del bundle `service_offer` o su
  solicitud no está publicada.
- `myapi_service_offer_build_context($request_row, $show_requester, $show_unit)`
  (nueva, **pura**) — arma el bloque `request` con sus siete claves y aplica las
  dos reglas de visibilidad. No decide nada: recibe los dos booleanos ya
  resueltos.
- `myapi_service_offer_detail_is_mine($request_row, array $owned_provider_ids)`
  (nueva, **pura**) — traduce *"el trabajo ya es mío"* a un booleano leyendo
  `assigned_provider_id` de la fila. Es la misma comparación que
  `myapi_service_request_provider_build_item()` hace sobre la clave construida,
  escrita una vez y no dos.
- `myapi_service_offer_build_detail($offer_row, $request_row, $show_requester, $show_unit)`
  (nueva, **pura**) — las quince claves más `request`.

**`resources/service_offer.resource.inc`** (modificar):

- `myapi_service_offer_provider_item_dispatch($nid)` y
  `myapi_service_offer_item_dispatch($nid)` (nuevas) — hermanas de
  `myapi_service_offer_provider_dispatch()`, no ramas dentro de ella.
- `myapi_service_offer_provider_detail($nid)` (nueva) — método → id → token →
  rol → `myapi_service_offer_detail_row()` → pertenencia vía
  `myapi_service_offer_provider_scope()` → serializar.
- `myapi_service_offer_requester_detail($nid)` (nueva) — método → id → token →
  `myapi_service_offer_detail_row()` → `myapi_service_request_viewer()` **sin
  modificarla**; si no devuelve `'requester'`, `403 forbidden` → serializar.

**`includes/myapi.service_request_query.inc`** (modificar) — recibe, trasladadas
verbatim desde `resources/service_request.resource.inc`,
`myapi_service_request_detail_row()`, `myapi_service_request_viewer()` y
`myapi_service_request_build_item()`. Ver el paso 1 del plan.

**`myapi.module`** (modificar) — dos entradas en `hook_menu()`:
`api/v1/service-offers/provider/%` (`page arguments = [4]`) y
`api/v1/service-offers/%` (`page arguments = [3]`), ambas con
`access callback = TRUE` y `type = MENU_CALLBACK`. La primera gana sobre la
segunda por el literal en la posición 3, y ninguna colisiona con
`api/v1/service-offers/provider` (SPEC 102).

**`docs/service-offer.md`** (modificar) — las dos secciones nuevas, con la
plantilla de `CLAUDE.md`, y una tabla que enfrente las dos respuestas clave a
clave: es lo único que hace revisable la regla de visibilidad.

**`tests/unit/ServiceOfferDetailTest.php`** (nuevo) — hermano de
`ServiceOfferProviderListTest.php`. Cubre las funciones puras: las dos reglas de
visibilidad en sus cuatro combinaciones, la identidad de las quince claves con
`myapi_service_offer_build()` sobre la misma fila, y el orden exacto de las
claves de la respuesta.

**Fuera de alcance (para specs futuros):**

- **Editar, retirar o adjudicar** una oferta. Cada una es su spec, y las tres
  escriben; este spec no escribe nada.
- **El listado de ofertas del residente** ("todas las que he recibido"), hermano
  del SPEC 102. Este spec sirve una oferta, no un conjunto.
- **El acceso del administrador de edificio** a cualquiera de las dos rutas. Hoy
  `myapi_service_request_viewer()` no le da entrada al detalle de la solicitud;
  dársela a la oferta sería la primera excepción y merece su spec.
- **Cualquier dato de contacto** del solicitante o del proveedor —teléfono,
  email, dirección—. Ninguna respuesta del módulo los publica y esta no inaugura
  la práctica.
- **`is_expired` o cualquier campo calculado sobre `valid_until`.** Viaja la
  fecha, como en el SPEC 102, y quien decide si venció es el cliente.
- **Las transacciones, el timeline y las imágenes de la solicitud.** Se leen
  donde ya se leen: `GET /api/v1/service-requests/{id}` y su hermana del
  proveedor.
- **`offers_count` y cualquier dato de la competencia.** Ni cuántas ofertas
  rivales tiene la solicitud, ni por cuánto.
- **La descripción de la solicitud**, sus fechas (`desired_start`, `closed_at`) y
  su `assigned_offer`. `request` es referencial por decisión, no medio detalle.
- **Notificaciones** de cualquier tipo.
- **Cualquier backfill.** Ninguna oferta guardada se toca.

---

## 3 — Modelo de datos

**Ningún campo, ninguna tabla, ningún bundle y ninguna clave de i18n nuevos.** Se
lee lo que el SPEC 77 instaló y se responde con códigos de error que ya existen:
`method_not_allowed`, `not_found`, `forbidden`, `provider_role_required`. Nada se
escribe.

Lo único que aparece es la forma de la respuesta.

### El conjunto servible — idéntico para las dos rutas

```
oferta servible  ⟺  offer.status = 1
                  ∧ offer.type   = 'service_offer'
                  ∧ request.status = 1        (INNER JOIN vía field_request)
```

Tres condiciones y ninguna más. **No hay condición sobre el estado de la oferta,
ni sobre el de la solicitud, ni sobre `provider.status`, ni sobre
`field_license_expiry`**: una oferta `withdrawn` sobre una solicitud `cancelled`
de un proveedor suspendido se sirve entera. Quien no cumple las tres da
`404 not_found`, sin distinguir cuál falló.

El `innerJoin` al `node` de la solicitud no es una comodidad: `field_request`
está **compartido con otro bundle**, y ese join es lo único que mantiene fuera al
bundle ajeno.

### La autorización, una por ruta

| Ruta | Compuerta | Dueña |
|---|---|---|
| `/provider/{id}` | rol `proveedor`, y `offer.field_provider` ∈ `myapi_provider_role_provider_ids(uid)`, resuelto con `myapi_service_offer_provider_scope()` | SPECS 78 y 102 |
| `/{id}` | `myapi_service_request_viewer($request_row, $uid) === 'requester'` | SPEC 89 |

### La regla de visibilidad — una sola, con dos consumidores

```
mine  ⟺  request.field_assigned_provider ∈ myapi_provider_role_provider_ids(uid)
```

`mine` se calcula sobre la **adjudicación de la solicitud** y **no** sobre el
estado de la oferta. Normalmente coinciden; cuando divergen manda la
adjudicación, que es la que dice a qué casa voy. Es la decisión 5 del SPEC 98,
trasladada de la solicitud a la oferta.

### La respuesta — quince claves más `request`, en este orden

`request` es **la clave dieciséis del objeto `service_offer`**, no una hermana
suya en `data`. En el SPEC 100 `request` viaja fuera porque allí informa del
*efecto* de la escritura sobre la solicitud; aquí es el *contexto* de la oferta
que se está leyendo, y una oferta sin su solicitud no se entiende.

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": "https://…/logo.png" },
      "amount": 95.5,
      "message": "Cambio de resistencia y purgado del circuito.",
      "status": "selected",
      "created": "2026-08-25T10:14:00",
      "amount_type": "fixed",
      "valid_until": "2026-09-01T23:59:59",
      "available_from": "2026-08-27T09:00:00",
      "duration": { "value": 2, "unit": "hours" },
      "includes": "Material y desplazamiento.",
      "excludes": null,
      "tax_included": true,
      "warranty_days": 30,
      "requires_visit": false,

      "request": {
        "id": 128,
        "title": "Fuga en el calentador",
        "status": "assigned",
        "category":    { "id": 12, "code": "plumbing", "name": "Plomería" },
        "condominium": { "id": 7,  "name": "Residencial Los Álamos" },
        "unit":        { "id": 55, "name": "Apto 302" },
        "requester":   { "id": 314, "name": "María Crespo" }
      }
    }
  }
}
```

**Las quince primeras claves son `myapi_service_offer_build($row)` íntegro** —
mismos nombres, mismos tipos, mismos nulos, mismo orden. No se reescribe ni una:
ni el `(float)` de `amount`, ni el `format_date()` de las tres fechas, ni la
regla de que `""` es `null`, ni que `message` se queda en `""` y
`requires_visit` nunca es `null`. El día que cambie el formato de `amount` cambia
en los cuatro sitios a la vez o en ninguno.

### `request` — siete claves, siempre las siete, en las dos rutas

| Clave | Tipo | Regla |
|---|---|---|
| `id` | int | `nr.nid` del **nodo unido**, nunca `fq.field_request_target_id` |
| `title` | string | `""` si falta, nunca `null` |
| `status` | string \| null | `open`, `offered`, `direct`, `assigned`, `closed`, `cancelled` |
| `category` | object | `{id, code, name}` — `code` es `""` cuando el término no tiene, nunca `null` |
| `condominium` | object \| null | `{id, name}`, `name` es el **título del nodo**: el bundle `condominio` no tiene campo nombre. Un `null` entero, nunca `{id: null, name: null}` |
| `unit` | object \| null | `{id, name}`, `name` es `field_nombre_vivienda` y **no** el título del nodo |
| `requester` | object \| null | `{id, name}` — **sin teléfono, sin email** |

**Las siete viajan siempre; dos cambian de contenido según quién pregunta.** Es
la doctrina del SPEC 89 (*"los keys son siempre los mismos; tres cambian
contenido"*): un cliente que puede leer las siete claves en las dos rutas no
tiene nada sobre lo que ramificar.

| Clave | `/provider/{id}` | `/{id}` |
|---|---|---|
| las quince de la oferta | idénticas | idénticas |
| `request.condominium` | siempre | siempre |
| `request.unit` | **solo si `mine`**, `null` si no | **siempre** — es su vivienda |
| `request.requester` | **solo si `mine`**, `null` si no | **siempre `null`** — es él mismo |

Tres precisiones que son el contrato:

- **`condominium` viaja siempre y para todos**, sin condición de `mine`. Nombra
  el conjunto residencial, no a una persona ni una puerta, y es la decisión 6 del
  SPEC 89 sobre el mismo dato.
- **`unit` y `requester` son un `null` entero**, nunca `{id: null, name: null}`.
  Un proveedor no puede distinguir *"no adjudicada a mí"* de *"la vivienda fue
  borrada"*, y no tiene por qué: en los dos casos no hay dirección que pintar.
- **`requester: null` en la ruta del residente es deliberado y no un olvido.** Se
  documenta como tal en `docs/service-offer.md`.

---

## 4 — Plan de implementación

Nueve pasos. Los dos primeros no encienden nada: el primero paga la deuda de
arquitectura que la Regla 5 impone, el segundo prepara la consulta. El endpoint
no existe hasta el paso 6.

1. **`includes/myapi.service_request_query.inc` — la extracción.**
   Se trasladan **verbatim, con su docblock íntegro**, tres funciones que hoy
   viven en `resources/service_request.resource.inc`:
   `myapi_service_request_detail_row()`, `myapi_service_request_viewer()` y
   `myapi_service_request_build_item()`. En el recurso cada una se queda como
   **una línea que delega**, así ninguno de sus llamadores actuales se toca.
   Motivo: un recurso no llama a las internas de otro recurso (Regla 5 de
   `CLAUDE.md`), y este fichero ya es la casa de las consultas de
   `service_request`. Es el mismo movimiento, y por la misma razón, que el paso 1
   del SPEC 102 con `myapi_parse_id_param()`.
   *Verificación: `php -l`; `ServiceRequestProviderListTest`,
   `ServiceRequestProviderDetailTest` y el resto de la familia siguen en verde
   **sin tocar un solo test** — la prueba de que el traslado no movió nada.*

2. **`includes/myapi.service_offer_query.inc` — la fila de la oferta.**
   `myapi_service_offer_detail_row($nid)`: `node` de la oferta (`status = 1`,
   bundle `service_offer`), los mismos `leftJoin` que
   `myapi_service_request_load_offers()` usa para los quince alias —
   `field_provider` y su logo, `field_offer_amount`, `field_offer_message`,
   `field_offer_status`, `field_offer_amount_type`, `field_offer_valid_until`,
   `field_offer_available_from`, `field_offer_duration`,
   `field_offer_duration_unit`, `field_offer_includes`, `field_offer_excludes`,
   `field_offer_tax_included`, `field_offer_warranty_days`,
   `field_offer_requires_visit` — más un `innerJoin` a `field_request` y de ahí
   al `node` de la solicitud publicada, del que **solo se proyecta `nr.nid` como
   `request_id`**. `FALSE` cuando no hay fila.
   **No se proyecta ni un dato más de la solicitud aquí**: el contexto lo da
   `myapi_service_request_detail_row()`, que es su dueña. Dos consultas en un
   detalle de un ítem, a cambio de cero definiciones duplicadas de qué es un
   condominio, una vivienda o una categoría.
   *Verificación: `php -l`; los cuatro casos de `FALSE` —oferta inexistente,
   despublicada, de otro bundle, solicitud despublicada— contra filas fixture.*

3. **El mismo fichero — la regla de visibilidad.**
   `myapi_service_offer_detail_is_mine($request_row, array $owned_provider_ids)`,
   **pura**: `TRUE` cuando `assigned_provider_id` de la fila está en la lista.
   Compara contra la columna proyectada del **nodo unido**, no contra el
   `target_id` crudo, así una adjudicación rota cierra la vivienda en lugar de
   abrirla.
   *Verificación: `php -l`; los cuatro casos —sin adjudicar, adjudicada a otro,
   adjudicada a mí, adjudicación rota—.*

4. **El mismo fichero — el bloque `request`.**
   `myapi_service_offer_build_context($request_row, $show_requester, $show_unit)`,
   **pura**, siete claves en su orden. `id`, `title`, `status`, `category` y
   `unit` **se toman de `myapi_service_request_build_item($request_row, [])`** y
   no se vuelven a escribir; `condominium` y `requester` se arman aquí con la
   misma forma que `myapi_service_request_build_detail()` (ver decisión 4). Los
   dos booleanos llegan ya resueltos: esta función no decide quién ve qué, solo
   lo aplica.
   *Verificación: `php -l`; test que fija las siete claves y su orden; test que
   comprueba que `id`, `title`, `status`, `category` y `unit` son idénticas a las
   homónimas de `myapi_service_request_build_item()` sobre la misma fila; test de
   las cuatro combinaciones de los dos booleanos.*

5. **El mismo fichero — la respuesta.**
   `myapi_service_offer_build_detail($offer_row, $request_row, $show_requester, $show_unit)`,
   **pura**: `myapi_service_offer_build($offer_row)` íntegro, y `request` anexado
   detrás como clave dieciséis. Ni una de las quince se reescribe.
   *Verificación: `php -l`; test que fija las dieciséis claves y su orden, y otro
   que comprueba que las quince son **idénticas** a las de
   `myapi_service_offer_build()` sobre la misma fila.*

6. **`resources/service_offer.resource.inc` — la ruta del proveedor.**
   `myapi_service_offer_provider_item_dispatch($nid)` (solo `GET`; todo lo demás
   `405` antes del token) y `myapi_service_offer_provider_detail($nid)`, en este
   orden fijo:
   método → `myapi_parse_id_param()` sobre el `nid` de la ruta (`404 not_found`
   si no es entero positivo) → token → rol (`403 provider_role_required`) →
   `myapi_service_offer_detail_row()` (`FALSE` → `404 not_found`) →
   `myapi_service_offer_provider_scope($row->provider_id, $uid)` (`FALSE` →
   `403 forbidden`) → `myapi_service_request_detail_row($row->request_id)` →
   `myapi_provider_role_provider_ids()` → `is_mine` → serializar → `200`.
   *Verificación: `php -l`; `curl` con token sin rol → `403`; con una oferta
   ajena → `403`; con una oferta despublicada → `404`; `POST` → `405`.*

7. **El mismo fichero — la ruta del residente.**
   `myapi_service_offer_item_dispatch($nid)` y
   `myapi_service_offer_requester_detail($nid)`:
   método → id → token → `myapi_service_offer_detail_row()` (`404`) →
   `myapi_service_request_detail_row($row->request_id)` (`404` si `FALSE`) →
   `myapi_service_request_viewer($request_row, $uid)` **sin modificarla**; si no
   devuelve **exactamente `'requester'`**, `403 forbidden` → serializar con
   `$show_requester = FALSE` y `$show_unit = TRUE` → `200`.
   Un `'provider'` recibe `403` aquí: para eso está la otra ruta.
   *Verificación: `php -l`; `curl` como solicitante → `200` con `unit` y
   `requester: null`; como proveedor que ofertó → `403`; como tercero → `403`.*

8. **`myapi.module` — las dos rutas.**
   `api/v1/service-offers/provider/%` → `myapi_service_offer_provider_item_dispatch`,
   `page arguments = [4]`; `api/v1/service-offers/%` →
   `myapi_service_offer_item_dispatch`, `page arguments = [3]`. Las dos con
   `access callback = TRUE`, `type = MENU_CALLBACK` y
   `file = resources/service_offer.resource.inc`.
   Sobre el enrutado: `api/v1/service-offers/provider` (SPEC 102) sigue ganando
   porque tiene cuatro componentes y estas dos tienen cinco y cuatro
   respectivamente; entre las dos nuevas, Drupal 7 resuelve el literal `provider`
   antes que el comodín — el razonamiento que el SPEC 102 dejó escrito, ahora
   cobrado.
   *Verificación: `drush cc all`; las tres rutas de `/service-offers` responden lo
   suyo y ninguna existente cambia de respuesta.*

9. **Documentación y matriz manual.**
   `docs/service-offer.md` gana las dos secciones con la plantilla de
   `CLAUDE.md` y **la tabla que enfrenta las dos respuestas clave a clave**,
   incluida la nota de que `requester: null` en la ruta del residente es
   deliberado. `drush cc all` y recorrido de: cuenta sin rol; proveedor con la
   oferta adjudicada y sin adjudicar; proveedor suspendido; proveedor con
   licencia vencida; oferta `withdrawn` sobre solicitud `cancelled`; oferta sobre
   solicitud `direct`; oferta anterior al SPEC 100 (`amount_type` y `valid_until`
   a `null`); solicitud sin condominio; solicitud sin vivienda; residente sobre
   su oferta, sobre la oferta de otra solicitud suya y sobre una ajena.

---

## 5 — Criterios de aceptación

**Método, ruta y autenticación**

- [ ] `POST`, `PUT`, `PATCH` y `DELETE` sobre `/api/v1/service-offers/provider/{id}`
      y sobre `/api/v1/service-offers/{id}` responden `405 method_not_allowed`,
      **sin token** en la petición.
- [ ] Sin cabecera `Authorization` → `401 missing_authorization` en las dos rutas.
- [ ] Con un token caducado o inexistente → `401 invalid_token` en las dos.
- [ ] `{id}` no entero positivo (`abc`, `0`, `-1`, `1,2`, `" 41"`) →
      `404 not_found`, y **no se ejecuta ninguna consulta de ofertas**.
- [ ] `GET /api/v1/service-offers/provider` (SPEC 102) sigue respondiendo su
      listado y **no** cae en el despachador del detalle.
- [ ] `GET /api/v1/service-offers/provider/41` cae en la ruta del proveedor y
      **nunca** en la del comodín.

**El conjunto servible — las dos rutas responden `404` en los mismos cuatro casos**

- [ ] Oferta inexistente → `404 not_found`.
- [ ] Oferta **despublicada** → `404 not_found`.
- [ ] Un nid de otro bundle (una solicitud, un proveedor) → `404 not_found`.
- [ ] Oferta cuya **solicitud** está despublicada o borrada → `404 not_found`,
      nunca `200` con `request: null`.
- [ ] Los cuatro `404` son **indistinguibles entre sí**: mismo `error_code`, mismo
      mensaje.

**Autorización — `/provider/{id}`**

- [ ] Token válido de una cuenta **sin** el rol `proveedor` →
      `403 provider_role_required`, **antes de cualquier consulta de ofertas**.
- [ ] Cuenta con `administrator` pero **sin** `proveedor` →
      `403 provider_role_required`.
- [ ] Cuenta con el rol pero **sin ningún proveedor vinculado** → `403 forbidden`.
- [ ] Oferta de **otro** proveedor → `403 forbidden`, nunca `404`.
- [ ] Oferta de un proveedor **suspendido** (`status = 0`) propio → `200` con la
      respuesta completa.
- [ ] Oferta de un proveedor con `field_license_expiry` en el pasado → `200`.
- [ ] Una cuenta con dos proveedores lee las ofertas de los dos.
- [ ] El **solicitante** de la solicitud, si además tiene el rol `proveedor`, no
      entra por aquí a una oferta que no es de sus proveedores → `403 forbidden`.

**Autorización — `/{id}`**

- [ ] El **solicitante** de la solicitud (`field_requester = uid`) → `200`.
- [ ] Un proveedor que ofertó en esa solicitud → `403 forbidden`
      (`myapi_service_request_viewer()` devuelve `'provider'`, no `'requester'`).
- [ ] El proveedor **adjudicado** → `403 forbidden`. Para eso está la otra ruta.
- [ ] Una cuenta sin relación con la solicitud → `403 forbidden`.
- [ ] La ruta **no exige ningún rol**: un solicitante sin roles especiales entra.

**Las quince claves de la oferta**

- [ ] Las quince valen, sobre la misma fila, **exactamente lo mismo** que las
      homónimas de `myapi_service_offer_build()`, en las dos rutas.
- [ ] `amount` es `float` o `null`, nunca `"95.50"`; una oferta `on_site_quote`
      responde `null`.
- [ ] `message` es `""` cuando está vacío, **nunca `null`**.
- [ ] `requires_visit` es `bool`, **nunca `null`**.
- [ ] `tax_included` distingue `true`, `false` y `null`.
- [ ] `duration` es un objeto entero o un `null` entero, nunca
      `{value: null, unit: null}`.
- [ ] Una oferta anterior al SPEC 100 responde `amount_type: null`,
      `valid_until: null`, `available_from: null`, `duration: null`,
      `includes: null`, `excludes: null`, `tax_included: null`,
      `warranty_days: null`, `requires_visit: false`, y **se sirve con `200`**.
- [ ] `created`, `valid_until` y `available_from` tienen formato `Y-m-d\TH:i:s`.
- [ ] `provider` lleva `{id, name, logo}` en las dos rutas; `logo` es `null`
      —nunca `""` ni una URL rota— cuando el proveedor no tiene logo o su fichero
      ya no existe.
- [ ] Una oferta `withdrawn` sobre una solicitud `cancelled` se sirve completa.
- [ ] Una oferta sobre una solicitud `direct` (SPEC 101) se sirve sin nada
      distinto.

**El bloque `request` y la visibilidad**

- [ ] `service_offer` tiene **exactamente dieciséis claves**, y `request` es la
      última.
- [ ] `request` tiene **exactamente siete claves**, en el orden `id`, `title`,
      `status`, `category`, `condominium`, `unit`, `requester`, **en las dos rutas
      y en todos los casos**.
- [ ] `request.id` es el nid del **nodo unido**; una oferta cuyo
      `field_request_target_id` apunta a un nodo borrado ya dio `404` antes.
- [ ] `request.category.code` es `""` —nunca `null`— cuando el término no tiene
      código.
- [ ] `request.condominium` viaja **siempre y en las dos rutas**, sin condición de
      adjudicación; es `null` entero cuando la solicitud no tiene condominio o el
      nodo está despublicado.
- [ ] `request.unit.name` es `field_nombre_vivienda`, **no** el título del nodo.
- [ ] `/provider/{id}`, solicitud **no adjudicada**: `unit: null` y
      `requester: null`.
- [ ] `/provider/{id}`, solicitud adjudicada a **otro** proveedor: `unit: null` y
      `requester: null`, aunque mi oferta esté en `selected`.
- [ ] `/provider/{id}`, solicitud adjudicada a **uno de mis** proveedores: `unit`
      y `requester` con sus objetos, **aunque mi oferta esté en `rejected` o
      `withdrawn`**.
- [ ] `/provider/{id}` con la adjudicación apuntando a un proveedor borrado o
      despublicado: `unit: null` y `requester: null`.
- [ ] `/{id}`: `unit` viaja **siempre** (o `null` entero si la solicitud no tiene
      vivienda) y `requester` es **siempre `null`**.
- [ ] Ni la respuesta ni `request` contienen `description`, `desired_start`,
      `closed_at`, `offers_count`, `assigned_offer`, `assigned_provider`,
      `images`, `attachment`, `transactions`, `viewer`, ni **ningún dato de
      contacto** (teléfono, email, dirección) de nadie.
- [ ] `unit` y `requester`, cuando no se ven, son un `null` entero y **nunca**
      `{id: null, name: null}`.

**Regresión del paso 1 — el traslado no movió nada**

- [ ] `ServiceRequestProviderListTest`, `ServiceRequestProviderDetailTest` y el
      resto de la familia `service_request` pasan **sin que se toque un solo
      test**.
- [ ] `GET /api/v1/service-requests/{id}`, `GET /api/v1/service-requests/provider`
      y `GET /api/v1/service-requests/provider/{id}` responden **byte a byte** lo
      mismo que antes del traslado.
- [ ] `myapi.info` lista todo fichero `.inc` nuevo o movido.

---

## 6 — Decisiones tomadas y descartadas

**1 — Dos rutas, una por audiencia. Descartada: una sola ruta con `viewer`.**
El SPEC 89 sirve dos lectores en una ruta y lo paga con dieciocho claves que
cambian de contenido según quién mira; el SPEC 99 dio marcha atrás y abrió ruta
propia para el proveedor. Este spec parte del final de esa historia, no del
principio. Además la compuerta del rol `proveedor` no cabe en una ruta
compartida: o la exige a un residente, o deja de exigirla al proveedor.

**2 — `request` es la clave dieciséis de `service_offer`. Descartada: hermana
suya en `data`.**
En el SPEC 100 `request` viaja fuera del objeto porque informa del **efecto** de
la escritura sobre la solicitud. Aquí es el **contexto** de la oferta que se lee:
una oferta sin su solicitud no se entiende, y sacarla obligaría al cliente a
recomponer a mano lo que la API ya sabe junto.

**3 — `request` referencial de siete claves. Descartado: el detalle completo de
la solicitud anidado.**
El detalle completo ya existe en `GET /api/v1/service-requests/{id}` y en su
hermana del proveedor. Servirlo también aquí crea un segundo sitio que mantener
de acuerdo, y el primer día que uno gane una clave que el otro no tenga, el
cliente tiene dos verdades sobre la misma solicitud.

**4 — `condominium` y `requester` se construyen por tercera vez. Descartado:
extraer una pura compartida y reescribir los dos llamadores.**
Hoy esas cinco líneas están duplicadas en
`myapi_service_request_provider_build_item()` y
`myapi_service_request_build_detail()`; este spec las deja en tres sitios. La
alternativa es más limpia, pero mete mano en código de los SPECS 89 y 98 que
nadie está tocando y amplía el radio de un spec que ya extrae tres funciones.
**Es deuda consciente, no descuido**: el día que un cuarto sitio la necesite, la
extracción deja de ser opcional. Los tests de la sección 5 fijan que las tres
copias responden lo mismo, que es lo que la hace detectable.

**5 — `mine` se decide por `field_assigned_provider` de la solicitud. Descartado:
por el estado `selected` de la oferta.**
Es el criterio del SPEC 87 y la decisión 5 del SPEC 98. Normalmente coinciden;
cuando divergen manda la adjudicación, porque es la que dice a qué casa voy. Una
oferta `selected` sobre una solicitud que acabó adjudicada a otro no da derecho a
la dirección de nadie.

**6 — Oferta ajena → `403 forbidden`. Descartado: `404 not_found`.**
El SPEC 99 responde `403` a una solicitud ajena y el módulo es consistente en
eso. El `404` escondería la existencia de la oferta, pero a cambio haría
indistinguibles "no es tuya" y "no existe", que es justo lo que un proveedor
depurando su integración necesita distinguir. La información que el `403` filtra
—que existe una oferta con ese nid— no dice de quién es, ni por cuánto, ni sobre
qué.

**7 — Solicitud despublicada → `404`. Descartado: `200` con `request: null`.**
Lo impone el `innerJoin`, no una comprobación aparte. Una oferta cuyo contexto
desapareció no es media respuesta: es una respuesta que el cliente no puede
pintar.

**8 — Las siete claves de `request` viajan siempre; dos cambian de contenido.
Descartado: omitir `requester` en la ruta del residente.**
Doctrina del SPEC 89. Un cliente que lee las mismas siete claves en las dos rutas
no tiene nada sobre lo que ramificar; una clave que a veces existe obliga a
comprobar su presencia además de su valor.

**9 — Dos consultas: la oferta por su lado, la solicitud por
`myapi_service_request_detail_row()`. Descartada: una sola con todos los joins.**
Una consulta sería más rápida y traería consigo una segunda definición de qué es
un condominio, una vivienda y una categoría dentro de un fichero que no es su
dueño. En un detalle de un ítem, la consulta de más no se nota; la definición de
más se nota el día que el SPEC 89 cambie la suya.

**10 — Se extraen las tres funciones a `includes/`. Descartado: que el endpoint
del residente compare `requester_uid === uid` por su cuenta.**
Esa comparación es la regla 1 de `myapi_service_request_viewer()`. Reescribirla
aquí es una segunda definición de "quién es el solicitante", y el síntoma de que
divergen sería que la oferta se lee y la solicitud no, sin que nada falle
ruidosamente.

**11 — `condominium` viaja siempre, sin condición de adjudicación. Descartado:
someterlo a `mine` como la vivienda.**
Nombra el conjunto residencial, no a una persona ni una puerta. Es la decisión 6
del SPEC 89 sobre el mismo dato, y sin él un proveedor no sabe siquiera a qué
ciudad va antes de decidir si presupuesta.

**12 — Sin `is_expired` ni ningún calculado sobre `valid_until`. Descartado:
enviarlo resuelto.**
Viaja la fecha, como en el SPEC 102. El servidor y el cliente no comparten reloj
ni zona horaria, y un booleano calculado en el servidor caduca en el trayecto.

**13 — La ruta del residente no exige ningún rol. Descartado: inventar un rol
`residente`.**
No existe en el módulo, y lo que da acceso no es una etiqueta en la cuenta sino
un hecho sobre el dato: ser el solicitante de esa solicitud.

**14 — El administrador de edificio no entra por ninguna de las dos. Descartado:
darle acceso.**
Hoy `myapi_service_request_viewer()` tampoco se lo da al detalle de la solicitud.
Dárselo a la oferta lo convertiría en la primera excepción, y una excepción que
empieza por la hoja y no por la raíz. Si hace falta, es un spec.

---

## 7 — Riesgos identificados

**1 — El traslado del paso 1 rompe algo sin hacer ruido.**
Tres funciones cambian de fichero, y una de ellas —`myapi_service_request_viewer()`—
es la que decide quién lee qué en todo el recurso de solicitudes. Un traslado mal
hecho no se manifiesta como un error: se manifiesta como un lector que entra
donde no debía.
*Mitigación:* el traslado es **verbatim** y deja delegaciones de una línea, así
que ningún llamador cambia; y la batería de `service_request` debe pasar **sin
que se toque un solo test**. Si un test hay que retocarlo, el traslado dejó de
ser un traslado y hay que revisarlo, no adaptar el test.

**2 — Las tres rutas de `/service-offers` se pisan.**
Conviven `api/v1/service-offers/provider` (SPEC 102, listado),
`api/v1/service-offers/provider/%` y `api/v1/service-offers/%`. El síntoma de un
error aquí no es un `404`: es que el listado empiece a responder un detalle, o que
`/provider/41` intente resolver un proveedor como si fuera una oferta.
*Mitigación:* los tres criterios de aceptación de enrutado se verifican **con
`drush cc all` de por medio**, que es cuando `hook_menu()` se reconstruye y cuando
el problema aparecería.

**3 — La triple copia de `condominium` y `requester` deriva.**
Es la deuda que la decisión 4 acepta a sabiendas. El síntoma sería que la misma
solicitud responda un condominio en su detalle y otro en la oferta que cuelga de
ella.
*Mitigación:* los tests fijan que las tres construcciones responden lo mismo sobre
la misma fila. No impide la divergencia; la hace fallar en rojo el mismo día.

**4 — `field_request` está compartido con otro bundle.**
Si alguien "simplifica" el `innerJoin` al `node` de la solicitud dejándolo en un
join a la tabla del campo, este endpoint empieza a servir filas de otro bundle
como si fueran solicitudes. El SPEC 102 ya dejó esa advertencia escrita en
`includes/myapi.service_offer_query.inc:87`.
*Mitigación:* el criterio "un nid de otro bundle → `404`" está en la sección 5, y
el docblock de `myapi_service_offer_detail_row()` repite la advertencia en el
sitio donde se tocaría.

**5 — El listado del SPEC 102 y este detalle discrepan sobre qué oferta es mía.**
El listado devuelve un conjunto paginado; el detalle decide fila a fila. Si alguna
vez uno gana una condición que el otro no tiene, el síntoma es un ítem que aparece
en la lista y responde `403` al abrirlo — y nada falla ruidosamente.
*Mitigación:* los dos usan **la misma** `myapi_service_offer_provider_scope()`, sin
copia ni variante. La equivalencia se mantiene mientras esa función siga siendo una
sola.

**6 — El cliente lee `requester: null` como un bug del servidor.**
En la ruta del residente esa clave es `null` siempre, por diseño, y un cliente que
no lo sepa abrirá una incidencia.
*Mitigación:* la tabla comparativa de `docs/service-offer.md` lo dice
explícitamente, y el paso 9 la hace parte del entregable, no de un comentario en el
código.

**7 — Una adjudicación mal escrita abre la vivienda y el solicitante a quien no
debe.**
`mine` se decide sobre `field_assigned_provider`. Un dato mal escrito ahí no es un
error de formato: es la dirección de una persona concreta viajando a un proveedor
que no va a ir a esa casa.
*Mitigación:* la comparación se hace contra la **columna del nodo unido**, no contra
el `target_id` crudo, así una referencia rota o despublicada cierra la vivienda en
lugar de abrirla; y el criterio "adjudicación apuntando a un proveedor borrado →
`unit: null`" está en la sección 5.
