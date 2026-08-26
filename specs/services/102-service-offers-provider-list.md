# 102 — Listado de ofertas del proveedor (`GET /api/v1/service-offers/provider`)

> **Estado:** Implemented · **Depende de:** `77-services-content-types-install` (Implemented) — dueña del bundle `service_offer`, de `field_provider`, `field_offer_status` y del catálogo `myapi_services_offer_statuses()`; `78-provider-role` (Implemented) — dueña del rol `proveedor`, de `myapi_provider_role_is()` (la compuerta del `403`) y de `myapi_provider_role_provider_ids()` (la que decide qué proveedor es de quién); `97-provider-mine-list` (Implemented) — el precedente exacto de autorización: rol → `403 provider_role_required`, rol sin vínculo → `200` vacío; `98-service-requests-provider-list` (Implemented) — dueña del bloque `pagination`, del idioma de los seis parámetros y de la regla de `?provider_id` (*estricto en el formato, laxo en la pertenencia*), que este spec copia línea por línea salvo en que aquí el parámetro es **obligatorio**; `100-service-offer-create` (Implemented) — dueña de `resources/service_offer.resource.inc`, de `myapi_service_offer_build()` y de la decisión *"no hay `GET` de colección a propósito"*, que este spec **matiza**; `101-service-offer-on-direct` (Implemented) — la que hace que una oferta pueda colgar de una solicitud `direct`, caso que este listado devuelve sin nada especial · **Fecha:** 2026-08-26
> **Objetivo:** Dar al proveedor un listado paginado y filtrable de las ofertas que ha enviado con **un** proveedor suyo, con ocho claves referenciales por ítem.

## 1 — Por qué existe este spec

`resources/service_offer.resource.inc` dice hoy, escrito y razonado: *"no hay `GET` de la colección a propósito — las ofertas viajan dentro de los dos detalles, y un tercer sitio donde leerlas sería un tercer sitio que mantener de acuerdo con `offers_count`"*.

**Esa decisión no se revierte: se delimita.** Aquella frase habla de *las ofertas de una solicitud*, que son la competencia y sí tienen que cuadrar con `offers_count`. Este endpoint responde otra pregunta, que hoy no tiene respuesta en ninguna parte: ***"¿qué he presupuestado yo?"***. Su conjunto cruza solicitudes en lugar de vivir dentro de una, no cuenta nada de nadie más y no puede desincronizarse con ningún contador, porque no publica ninguno.

Sin él, un proveedor que quiera repasar sus quince presupuestos del mes tiene que abrir quince detalles de solicitud, y solo si antes recuerda cuáles eran.

---

## 2 — Alcance

**Dentro:**

- Una ruta nueva, `GET /api/v1/service-offers/provider`, autenticada por Bearer y **con el rol `proveedor`**.
- Un despachador propio en `resources/service_offer.resource.inc`. `POST`, `PUT`, `PATCH` y `DELETE` sobre esa ruta responden `405`, **antes del token y antes de cualquier consulta**.
- `?provider_id` **obligatorio**: ausente → `422 missing_field`; malformado → `422 invalid_field`, antes de tocar la base de datos; ajeno o inexistente → `200` con lista vacía.
- El conjunto: las ofertas **publicadas** cuyo `field_provider` es ese proveedor, con `INNER JOIN` a una `service_request` **publicada**. Cualquier estado de oferta (`sent`, `selected`, `rejected`, `withdrawn`) y cualquier estado de solicitud (`cancelled` incluida).
- El ítem: **ocho claves, siempre las ocho, en orden fijo** — `id`, `status`, `amount`, `amount_type`, `created`, `valid_until`, `provider`, `request`.
- Los seis parámetros de query string: `page`, `limit`, `sort`, `status`, `request_status`, `category_id`, `date_from`/`date_to` — más el `provider_id` obligatorio.
- El bloque `pagination` `{total, page, limit, total_pages}`, byte a byte el del SPEC 98.
- La extracción de `myapi_service_request_parse_id_param()` a `includes/myapi.request.inc`: hoy vive dentro de `resources/service_request.resource.inc` y la Regla 5 de `CLAUDE.md` prohíbe que otro recurso la llame ahí.
- `docs/service-offer.md` ampliado con el endpoint nuevo.
- Tests unitarios de las funciones puras: el constructor del ítem y el parseo de los parámetros.

**Fuera de alcance (para specs futuros):**

- **El detalle de una oferta** (`GET /api/v1/service-offers/{id}`). Este listado es referencial por decisión explícita; el detalle completo ya existe dentro de `GET /api/v1/service-requests/provider/{id}`, en `my_offers`, con las quince claves.
- **Las siete claves largas** — `message`, `includes`, `excludes`, `duration`, `tax_included`, `warranty_days`, `requires_visit`, `available_from`. Se leen donde ya se leen.
- **Editar, retirar o adjudicar** una oferta. Cada una es su spec.
- **Varios proveedores en una llamada** (`?provider_id=41,42`, o el agregado de toda la cuenta). Si hace falta, es un spec y un formato nuevo, no un parche a este.
- **Filtros por condominio, por unidad, por rango sobre `valid_until`**, y cualquier orden que no sea `created` de la oferta.
- **Contadores y agregados** ("tienes 3 ofertas pendientes", importe total presupuestado del mes).
- **Notificaciones** de cualquier tipo.
- **El listado equivalente del residente** ("todas las ofertas que he recibido").
- **Cualquier backfill.** Ninguna oferta guardada se toca.

---

## 3 — Modelo de datos

**Este spec no introduce ningún campo, ninguna tabla, ningún bundle y ninguna clave de i18n.** Lee lo que el SPEC 77 instaló y responde con los códigos de error que ya existen (`method_not_allowed`, `missing_field`, `invalid_field`, `provider_role_required`). Ninguna oferta guardada se escribe.

Lo único que aparece es **la forma de la respuesta**.

### El conjunto

```
oferta ∈ lista  ⟺  offer.status = 1                                  (publicada)
                 ∧ offer.field_provider_target_id = ?provider_id
                 ∧ ?provider_id ∈ myapi_provider_role_provider_ids(uid)
                 ∧ request.status = 1                                (INNER JOIN)
```

Cuatro condiciones y ninguna más. **No hay condición sobre el estado de la oferta, ni sobre el de la solicitud, ni sobre `field_license_expiry`, ni sobre `provider.status`**: un proveedor suspendido lee su historial completo.

### El ítem — ocho claves, siempre las ocho, en este orden

```json
{
  "id": 901,
  "status": "sent",
  "amount": 95.5,
  "amount_type": "fixed",
  "created": "2026-08-25T10:14:00",
  "valid_until": "2026-09-01T23:59:59",
  "provider": { "id": 41, "name": "Plomería Torres" },
  "request": {
    "id": 128,
    "title": "Fuga en el calentador",
    "status": "assigned",
    "category": { "id": 12, "code": "plumbing", "name": "Plomería" }
  }
}
```

| Clave | Tipo | Regla |
|---|---|---|
| `id` | int | `node.nid` de la **oferta** |
| `status` | string \| null | `sent`, `selected`, `rejected`, `withdrawn`; `null` solo en fila corrupta |
| `amount` | float \| null | Nunca `"95.50"` y nunca `0.0` por ausencia. `null` cuando `amount_type = on_site_quote` |
| `amount_type` | string \| null | `null` en toda oferta anterior al SPEC 100. Nadie la rellenó y nadie la va a rellenar |
| `created` | string | `Y-m-d\TH:i:s`, vía `format_date()` |
| `valid_until` | string \| null | `Y-m-d\TH:i:s`. **No se compara con la fecha de hoy**: una oferta caducada viaja igual, con su fecha, y quien decide si está vencida es el cliente |
| `provider` | object | `{id, name}` — **nunca null** |
| `request` | object | `{id, title, status, category}` — **nunca null**, por el `INNER JOIN` |
| `request.category` | object | `{id, code, name}`, en el orden y con los tipos del ítem del SPEC 98: `code` es `""` cuando el término no tiene, nunca `null` |

Cinco precisiones que son el contrato:

- **Las seis primeras claves salen tal cual de `myapi_service_offer_build()`** — mismos nombres, mismos tipos, mismos nulos, mismo orden. Este ítem es ese serializador **recortado**, no uno nuevo: el día que cambie el formato de `amount` cambia en los dos sitios a la vez o en ninguno.
- **`provider` es `{id, name}` y no `{id, name, logo}`**, que es lo que devuelven `offers` y `my_offers`. El logo es el del propio lector, que ya lo tiene, y traerlo cuesta un `leftJoin` a `file_managed` en cada fila para pintar la misma imagen veinte veces.
- **`provider` se resuelve UNA vez, fuera del bucle**, con una consulta al `node` del proveedor, y se copia idéntico en cada ítem. Nunca un join por fila.
- **`request` no lleva `description`, ni `unit`, ni `offers_count`, ni `assigned_provider`.** Es la etiqueta que permite pintar la fila y abrir el detalle, no medio detalle.
- **No viaja ni un dato de contacto ni de la competencia.** Ni el residente, ni el condominio, ni la vivienda, ni cuántas ofertas rivales tiene la solicitud, ni por cuánto.

### El envoltorio

```json
{ "success": true, "data": { "service_offers": [ ], "pagination": { "total": 1, "page": 1, "limit": 20, "total_pages": 1 } } }
```

`total_pages` es **`0`** cuando `total` es `0`, la misma aritmética del SPEC 98.

---

## 4 — Plan de implementación

Ocho pasos. El primero no enciende nada: paga la deuda de arquitectura que la Regla 5 impone antes de que exista el endpoint.

1. **`includes/myapi.request.inc` — la extracción.**
   `myapi_parse_id_param($name)`, movida **verbatim** desde `resources/service_request.resource.inc` con su docblock íntegro. Allí, `myapi_service_request_parse_id_param()` se queda como una línea que delega, así sus tres envoltorios (`category_id`, `unit_id`, `provider_id`) no se tocan. Motivo: un recurso no llama a las internas de otro recurso (Regla 5 de `CLAUDE.md`), y "leer un id de la query string" es parseo de petición, que es justo lo que ese `include` es.
   *Verificación: `php -l`; `ServiceRequestListEndpointTest` y `ServiceRequestProviderListTest` siguen en verde **sin tocar un solo test** — la prueba de que el traslado no movió nada.*

2. **`includes/myapi.service_offer_query.inc` — la consulta base.** Fichero nuevo, con línea en `myapi.info`.
   `myapi_service_offer_provider_base_query($provider_id, array $filters)`: `node` de la oferta (`status = 1`, bundle `service_offer`), `innerJoin` a `field_data_field_offer_request` y de ahí al `node` de la solicitud (`status = 1`), `innerJoin` a la categoría y su término, `leftJoin` a `field_offer_status`, `field_offer_amount`, `field_offer_amount_type`, `field_offer_valid_until` y `field_category_code`. Los cinco filtros se aplican aquí y componen con `AND`.
   *Verificación: `php -l`; los filtros ejercitados contra el `SELECT` construido.*

3. **El mismo fichero — el conteo y la página.**
   `myapi_service_offer_provider_count($provider_id, $filters)` y `myapi_service_offer_provider_fetch($provider_id, $filters, $page, $limit, $sort)`, ambas sobre la consulta base. `sort` ordena por `n.created` de la **oferta**; `limit === -1` no pone `->range()`.
   *Verificación: `php -l`; conteo y página sobre datos fixture.*

4. **El mismo fichero — el proveedor, resuelto una vez.**
   `myapi_service_offer_provider_scope($provider_id, $uid)`: devuelve `['id' => int, 'name' => string]` cuando el nid está en `myapi_provider_role_provider_ids($uid)` **y** existe el nodo, y `FALSE` en cualquier otro caso. Sin condición `status = 1` sobre el proveedor — un suspendido lee su historial.
   *Verificación: `php -l`; los tres casos —propio, ajeno, inexistente— contra filas fixture.*

5. **El mismo fichero — el ítem.**
   `myapi_service_offer_provider_build_item($row, array $provider)`, **pura**, las ocho claves en su orden. Las seis que comparte con el serializador de quince **se toman de `myapi_service_offer_build($row)`** y no se vuelven a escribir: ni el `(float)`, ni el `format_date()`, ni la regla de que `""` es `null`. `provider` se copia del argumento; `request` se arma aquí.
   *Verificación: `php -l`; test que fija las ocho claves y su orden, y otro que comprueba que `id`, `status`, `amount`, `amount_type`, `created` y `valid_until` son idénticas a las de `myapi_service_offer_build()` sobre la misma fila.*

6. **`resources/service_offer.resource.inc` — el despachador y el listado.**
   `myapi_service_offer_provider_dispatch()` (solo `GET`; todo lo demás `405` antes del token) y `myapi_service_offer_provider_list()`, en el orden fijo:
   método → token → rol (`403 provider_role_required`) → `?provider_id` (`422 missing_field` / `422 invalid_field`, sin consulta) → los cinco filtros restantes → `myapi_service_offer_provider_scope()` (`FALSE` → `200` vacío) → `count` → página → `200`.
   *Verificación: `php -l`; `curl` con token sin rol → `403`; con `provider_id` ajeno → `200` y `total: 0`; `POST` sobre la ruta → `405`.*

7. **`myapi.module` — la ruta.**
   `api/v1/service-offers/provider` con `access callback => TRUE`, `page callback => 'myapi_service_offer_provider_dispatch'` y `file => resources/service_offer.resource.inc`.
   Sobre el enrutado: son **cuatro componentes** y el cuarto es un literal. No colisiona con `api/v1/service-requests/%/offers` (cinco componentes, otro tercer componente), y el día que llegue `api/v1/service-offers/%` para el detalle, Drupal 7 resuelve el literal antes que el comodín — el mismo razonamiento que el SPEC 99 dejó escrito para su ruta.
   *Verificación: `drush cc all`; la ruta responde y ninguna existente cambia de respuesta.*

8. **Documentación y matriz manual.**
   `docs/service-offer.md` gana la sección `GET /api/v1/service-offers/provider` con la plantilla de `CLAUDE.md`. `drush cc all` y recorrido de: cuenta sin rol; cuenta con rol y sin proveedor; cuenta con dos proveedores preguntando por cada uno; proveedor suspendido; proveedor con licencia vencida; una oferta sobre solicitud `open`, una sobre `direct`, una sobre `cancelled`; una oferta cuya solicitud está despublicada; los seis parámetros, cada uno con un valor válido y uno basura.

---

## 5 — Criterios de aceptación

**Método y autenticación**

- [x] `POST`, `PUT`, `PATCH` y `DELETE` sobre `/api/v1/service-offers/provider` responden `405 method_not_allowed`, **sin token** en la petición.
- [x] Sin cabecera `Authorization` → `401 missing_authorization`.
- [x] Con un token caducado o inexistente → `401 invalid_token`.
- [x] Token válido de una cuenta **sin** el rol `proveedor` → `403 provider_role_required`.
- [x] Token válido de una cuenta con `administrator` pero **sin** `proveedor` → `403 provider_role_required`.

**`?provider_id`**

- [x] Ausente → `422` con `error_code: missing_field` y el mensaje nombrando `provider_id`.
- [x] `abc`, `0`, `-1`, `1,2`, `" 41"` → `422 invalid_field`, y **no se ejecuta ninguna consulta de ofertas**.
- [x] Un nid que existe pero **no** es de la cuenta → `200`, `service_offers: []`, `pagination.total: 0`, `total_pages: 0`.
- [x] Un nid **inexistente** → misma respuesta que el anterior: `200` vacío, nunca `403` ni `404`.
- [x] Una cuenta con dos proveedores obtiene, con cada `provider_id`, **solo** las ofertas de ese proveedor, y la unión de las dos llamadas es el conjunto completo de sus ofertas, sin repetidos.

**El conjunto**

- [x] Una oferta `sent`, una `selected`, una `rejected` y una `withdrawn` del mismo proveedor aparecen **las cuatro** sin filtro.
- [x] Una oferta sobre una solicitud `cancelled` aparece.
- [x] Una oferta sobre una solicitud `direct` (SPEC 101) aparece, sin nada distinto en el ítem.
- [x] Una oferta **despublicada** no aparece.
- [x] Una oferta cuya solicitud está **despublicada o borrada** no aparece, y `pagination.total` no la cuenta.
- [x] Un proveedor con `status = 0` (suspendido) devuelve `200` con su historial completo.
- [x] Un proveedor con `field_license_expiry` en el pasado devuelve `200` con su historial completo.
- [x] Ninguna oferta de otro proveedor aparece jamás, ni siquiera sobre una solicitud en la que este proveedor también ofertó.

**El ítem**

- [x] Cada elemento de `service_offers` tiene **exactamente ocho claves**, en el orden `id`, `status`, `amount`, `amount_type`, `created`, `valid_until`, `provider`, `request`.
- [x] `id`, `status`, `amount`, `amount_type`, `created` y `valid_until` valen, sobre la misma fila, **exactamente lo mismo** que las claves homónimas de `myapi_service_offer_build()`.
- [x] `amount` es un `float` o `null`, nunca `"95.50"`; una oferta `on_site_quote` responde `null`.
- [x] Una oferta anterior al SPEC 100 responde `amount_type: null` y `valid_until: null`, y **aparece en la lista**.
- [x] `created` y `valid_until` tienen formato `Y-m-d\TH:i:s`.
- [x] `provider` es `{id, name}`, **sin `logo`**, y es el mismo objeto en todos los ítems de la respuesta.
- [x] `request` nunca es `null` y lleva `{id, title, status, category{id, code, name}}`.
- [x] `request.category.code` es `""` —nunca `null`— cuando el término no tiene código.
- [x] Ni el ítem ni el envoltorio contienen `message`, `includes`, `excludes`, `duration`, `tax_included`, `warranty_days`, `requires_visit`, `available_from`, `offers_count`, `requester`, `condominium` ni `unit`.

**Los seis parámetros**

- [x] `page` y `limit` por defecto son `1` y `20`; `limit=-1` devuelve todo en una página y fuerza `page: 1`; `limit=999` se recorta a `50`; basura en cualquiera de los dos cae al valor por defecto **sin `422`**.
- [x] `sort=asc` / `sort=desc` ordenan por `created` **de la oferta**; un valor desconocido cae a `desc`.
- [x] `status=sent,selected` devuelve solo esas; `status=inventado` se descarta en silencio; `status=sent,inventado` filtra por `sent`.
- [x] `request_status=closed,cancelled` filtra por el estado de la **solicitud**; una clave desconocida se descarta en silencio.
- [x] `category_id` con un tid filtra por la categoría de la solicitud; malformado → `422 invalid_field`.
- [x] `date_from` / `date_to` acotan por `created` de la oferta, **inclusivos en los dos extremos**: una oferta creada a las 23:50 de `date_to` entra.
- [x] Un parámetro desconocido (`?unit_id=3`, `?foo=bar`) se ignora en silencio y nunca produce `422`.

**Paginación**

- [x] `pagination` lleva `{total, page, limit, total_pages}` y `total` describe el conjunto **filtrado**, no la página.
- [x] Con `total: 0`, `total_pages` es `0` y no `1`.
- [x] Pedir una página más allá de la última responde `200` con `service_offers: []` y el `total` real.

**Lo que no se rompe**

- [x] `GET /api/v1/service-requests`, `GET /api/v1/service-requests/provider`, los dos detalles y `POST /api/v1/service-requests/{id}/offers` responden **byte a byte lo mismo** que antes de este spec.
- [x] La suite unitaria completa pasa en verde, con `ServiceRequestListEndpointTest` y `ServiceRequestProviderListTest` **sin modificar**.
- [x] `docs/service-offer.md` documenta el endpoint, sus errores y los siete parámetros.

> **Cómo se verificó.** Todo lo marcado está cubierto por `tests/unit/ServiceOfferProviderListTest.php`
> (65 tests) sobre el arnés de fixtures del módulo, más `php -l` en los seis
> ficheros tocados; la suite completa pasa en verde (2218 tests) con
> `ServiceRequestListEndpointTest` y `ServiceRequestProviderListTest` **sin
> modificar un carácter**. La casilla que queda abierta no se puede probar ahí:
> el arnés **registra los joins y nunca los resuelve**, así que no se puede
> sembrar una oferta "descartada por su solicitud". Lo que sí está asertado es
> la **forma** de ese join —los dos saltos son `INNER` y el del nodo lleva el
> bundle y `status = 1`—; el comportamiento es un criterio manual contra un
> sitio arrancado, la misma mitad que el SPEC 98 dejó a MySQL. Pendiente
> también, por la misma razón, `drush cc all` y que el enrutador prefiera el
> literal de la ruta.

---

## 6 — Decisiones tomadas y descartadas

**La ruta y el fichero**

- **Sí:** `GET /api/v1/service-offers/provider`. Calca `service-requests/provider`, que el app ya sabe leer, y deja libre `service-offers/{id}` para el detalle del día que llegue.
- **No:** `api/v1/providers/mine/offers` ni `api/v1/service-requests/provider/offers`. La primera hace de `providers/mine` un prefijo de cosas que no son proveedores; la segunda dice que estas ofertas cuelgan de una solicitud, y precisamente lo que las hace útiles es que la cruzan.
- **Sí:** segundo despachador dentro de `resources/service_offer.resource.inc`. Es el mismo recurso —la oferta— y la Regla 2 pide un fichero por recurso, no uno por ruta.
- **No:** un `resources/service_offer_provider.resource.inc`. Serían dos ficheros compartiendo el serializador de ofertas, que es exactamente la duplicación que la Regla 3 prohíbe.

**`?provider_id`**

- **Sí:** obligatorio. Una cuenta con dos proveedores que pregunta sin decir cuál está preguntando algo que este spec no responde, y responder "los dos mezclados" sería inventar un formato de lista agregada sin haberlo diseñado.
- **No:** derivarlo cuando la cuenta opera uno solo. Es la decisión 10 del SPEC 100, literal: elegir en silencio funciona hasta el día en que hay dos, y entonces el cliente que nunca mandó el campo empieza a leer la lista equivocada sin que nada falle.
- **No:** `?provider_id=41,42`. Formato nuevo para un caso que nadie ha pedido; si llega, es un spec.
- **Sí:** un nid ajeno o inexistente responde `200` con lista vacía. Es la regla del SPEC 98 —*estricto en el formato, laxo en la pertenencia*— y no permite enumerar proveedores ajenos.
- **No:** `403 service_offer_provider_not_owned`, que es lo que hace la compuerta del SPEC 100 al **crear**. Ahí el `403` distingue tres causas de rechazo sobre una acción que el usuario sí quería hacer; aquí solo convertiría un listado en un oráculo de *"este nid existe y no es tuyo"*.

**El conjunto**

- **Sí:** todo el historial, cualquier estado de oferta y cualquier estado de solicitud. Recortar en el cliente es reversible; recortar en el servidor no, y un proveedor repasando qué fue de un presupuesto de hace tres meses no tendría nada que pedir.
- **Sí:** ni `provider.status` ni `field_license_expiry` filtran nada. **La licencia gobierna el mercado —poder ofertar— y no el archivo de lo ya ofertado.** Un suspendido que perdiera el acceso a sus propios presupuestos perdería la prueba de un trabajo que hizo.
- **Sí:** `INNER JOIN` a la solicitud publicada. Cada ítem de esta lista tiene que poder abrirse; una oferta cuya solicitud desapareció es una fila que solo genera un `404` al tocarla.
- **No:** `LEFT JOIN` con `request: null`. Obligaría al cliente a pintar un ítem que no lleva a ninguna parte y a distinguir dos clases de fila.

**El ítem**

- **Sí:** ocho claves. Es la definición de "referencial" que este spec adopta: lo justo para pintar la fila y abrir el detalle.
- **No:** las quince de `myapi_service_offer_build()`. `message`, `includes` y `excludes` son texto largo; multiplicados por cincuenta filas hacen de un listado una descarga.
- **Sí:** `amount` viaja. Es **su** precio, no el de un rival: ocultarlo obligaría a abrir cada oferta para recordar por cuánto se ofreció, que es justo lo que este endpoint viene a evitar.
- **Sí:** `provider` en cada ítem aunque sea siempre el mismo objeto. El cliente pinta la lista sin recordar con qué id preguntó, y el día que exista un listado agregado el ítem no cambia de forma.
- **No:** `provider.logo`. Es el logo del propio lector, que ya lo tiene, y cuesta un `leftJoin` a `file_managed` por fila para repetir la misma imagen.
- **Sí:** las seis claves compartidas se **toman** de `myapi_service_offer_build()` en lugar de reescribirse. El `(float)`, el `format_date()` y la regla *"`""` es `null`"* están escritos una vez; el día que cambien, cambian en los dos sitios o en ninguno.
- **Sí:** `valid_until` viaja crudo. **No:** una clave `expired: true` calculada en el servidor. La caducidad depende del instante en que se mira, y una lista cacheada diez minutos mentiría en el borde.

**Arquitectura**

- **Sí:** `myapi_parse_id_param()` sube a `includes/myapi.request.inc`. Un recurso no llama a las internas de otro (Regla 5), y leer un id de la query string es parseo de petición.
- **No:** copiarla en el fichero nuevo. Sería la tercera definición de *"un entero positivo o `422`"*.
- **Sí:** tres funciones —`base_query`, `count`, `fetch`— como el SPEC 98. El bloque `pagination` describe el conjunto filtrado, y eso exige contar antes de paginar.
- **Sí:** el proveedor se resuelve una vez, fuera del bucle. Un join por fila para repetir el mismo nombre veinte veces.
- **Sí:** la decisión *"no hay `GET` de colección"* del SPEC 100 se **matiza y se anota en su fichero**, no se borra. Sigue siendo cierta para *las ofertas de una solicitud*, que son las que tienen que cuadrar con `offers_count`.

---

## 7 — Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **La extracción de `myapi_parse_id_param()` rompe en silencio los tres filtros que ya la usan** (`?category_id`, `?unit_id`, `?provider_id` del SPEC 98). Un cambio de nombre del parámetro dentro de la función cambiaría el `@field` del mensaje de error de tres endpoints en producción. | El traslado es **verbatim** y el nombre viejo se queda delegando en una línea. `ServiceRequestListEndpointTest` y `ServiceRequestProviderListTest` tienen que pasar **sin tocarles un carácter**: si hay que editar un test, el traslado movió algo. |
| **Las seis claves compartidas se separan de `myapi_service_offer_build()`.** Alguien añade una regla ahí (un redondeo de `amount`, otro formato de fecha) y este listado sigue respondiendo lo de antes, con dos verdades sobre el mismo dato. | El ítem **llama** a esa función y elige claves; no puede divergir sin borrar la llamada. Un test compara las seis, clave a clave, sobre la misma fila. |
| **`?provider_id` obligatorio rompe a cualquier cliente que ya llamara sin él** — hoy ninguno, porque la ruta no existe. | Se documenta como obligatorio desde el primer día. El riesgo real es el inverso: relajarlo después sería compatible, endurecerlo no. Por eso nace estricto. |
| **La consulta cruza cinco `leftJoin` de campo sobre `field_data_*` sin índice compuesto.** Con un proveedor de miles de ofertas y `limit=-1`, la página se construye entera en memoria. | `limit` se recorta a 50 salvo `-1` explícito, y el `INNER JOIN` por `field_provider` acota el conjunto a un proveedor **antes** que los `leftJoin`. Si aparece lentitud, el sitio donde mirar es `field_data_field_offer_request`, que es el join que multiplica. |
| **Dos listados del proveedor con reglas de acceso distintas.** `service-requests/provider` incluye el mercado (A ∪ B ∪ C) y este solo las ofertas propias; alguien puede leer que "el proveedor ve lo mismo en los dos sitios". | Este endpoint **no tiene regla de acceso por conjunto**: es una igualdad sobre `field_provider`. No hay nada que mantener en paso con `myapi_service_request_viewer()`, y por eso este spec no la toca. |
| **`request.status` puede quedar obsoleto en la pantalla del proveedor** — la solicitud pasa a `cancelled` y la lista cacheada sigue diciendo `assigned`. | Es un listado, no una suscripción. La verdad del estado está en el detalle, que es donde se actúa. Notificar cambios de estado es otro spec, ya declarado fuera de alcance. |

---

## Lo que **no** está en este spec

- El detalle de una oferta (`GET /api/v1/service-offers/{id}`).
- Editar, retirar o adjudicar una oferta.
- Las siete claves largas del serializador de quince.
- Varios proveedores en una llamada, o el agregado de toda la cuenta.
- Filtros por condominio, por unidad o por `valid_until`, y cualquier orden que no sea `created` de la oferta.
- Contadores, badges y agregados de importe.
- Notificaciones de cualquier tipo.
- El listado equivalente del residente.
- Cualquier `hook_update_N`, migración o backfill.

Cada uno de ellos, si llega, va en su propio spec.
