# 106 — Adjudicar una oferta (`PUT /api/v1/service-offers/{id}/accept`)

> **Estado:** approved · **Depende de:** `77-services-content-types-install` (Implemented) — dueña del bundle `service_offer`, del catálogo `myapi_services_offer_statuses()` donde `selected` lleva esperando desde entonces, de los campos `field_assigned_offer` y `field_assigned_provider` instalados y jamás escritos, y del grafo `myapi_services_request_transitions()` donde la arista `offered → assigned` está dibujada y nadie recorre; `89-service-request-detail` (Implemented) — dueña de `myapi_service_request_detail_row()`, de `myapi_service_request_build_detail()` y del criterio «manda `field_requester` y no `node.uid`», que es quien decide aquí; `92-service-request-initial-transaction` (Implemented) — dueña de la forma de un nodo `service_transaction` y de sus cuatro campos; `94-service-transaction-backoffice` (Implemented) — dueña de `myapi_service_transaction_sync_request_status()`, el `hook_node_insert()` que **obliga** al orden de escritura de este spec; `95-service-request-cancel` (Implemented) — dueña de `myapi_service_request_reject_live_offers()` y de la escritura en línea de la transacción, **las dos cosas que este spec extrae**, y precedente de las tres decisiones de forma que aquí se repiten: el detalle entero como `200`, la no idempotencia y la respuesta degradada; `100-service-offer-create` (Implemented) — dueña de `myapi_service_offer_build()`, de `myapi_service_offer_provider_row()` y de `valid_until` como timestamp; `103-service-offer-detail` (Implemented) — dueña de `myapi_service_offer_detail_row()` y del split `provider_id` / `provider_raw` sobre el que gatea este spec; `105-service-offer-update-withdraw` (Implemented) — dueña de la ruta hermana `api/v1/service-offers/%/withdraw`, precedente de la quinta componente en este prefijo, del criterio «la licencia es la última condición» y del procedimiento de extracción con el test viejo como red; `78-provider-role` (Implemented) — dueña de `myapi_provider_role_provider_ids()`, que `myapi_service_offer_provider_row()` llama por dentro · **Fecha:** 2026-08-26
> **Objetivo:** Añadir `PUT /api/v1/service-offers/{id}/accept`, para que el residente adjudique una de las ofertas de su solicitud: esa oferta pasa a `selected`, las demás vivas a `rejected`, la solicitud pasa a `assigned` con `field_assigned_offer` y `field_assigned_provider` por fin escritos, y la adjudicación queda anotada en la línea de tiempo.

Cuatro notas que la cabecera fija:

- **Este spec ejecuta un arco que el módulo lleva declarado desde SPEC 77 y que nadie recorre.** `selected` está en el catálogo de estados de oferta, `assigned` está en el de solicitudes, la arista `offered → assigned` está en el grafo y `field_assigned_offer` y `field_assigned_provider` están instalados en el bundle `service_request` con sus `selection_settings`. Las cinco cosas llevan esperando a este endpoint. Hoy una oferta se crea (SPEC 100), se lee (SPEC 102, 103), se edita y se retira (SPEC 105); lo único que no se puede hacer con ella es **aceptarla**, que es para lo que nació.

- **Ni un campo nuevo, ni una tabla, ni un `hook_update_N`, ni una arista nueva en el grafo.** Este spec es una ruta, un despachador, un endpoint, una compuerta pura y **tres extracciones** de código que hoy vive en `resources/service_request.resource.inc` y que la regla 5 de `CLAUDE.md` prohíbe llamar desde otro recurso.

- **Es la primera escritura del módulo que toca cuatro nodos y dos bundles en una pasada:** la oferta ganadora, las perdedoras, la solicitud y la transacción. El orden de esas escrituras no es estilo — es la condición que hace que `myapi_service_transaction_sync_request_status()` compare dos estados iguales y **no** vuelva a guardar la solicitud, exactamente la propiedad que `myapi_service_request_cancel()` ya avisa de que hay que conservar.

- **Adjudicar es un `PUT` sobre la oferta y no un `POST` sobre la solicitud.** No nace nada, así que no hay `201`. Y el objeto cuyo estado cambia por decisión del residente es la **oferta**; que la solicitud pase a `assigned` es la consecuencia, no la acción. La URL identifica sola a la oferta, el cuerpo va vacío, y por tanto no existe el caso «la oferta que mandas no es de esta solicitud».

---

## Alcance

**Dentro del alcance:**

- **`myapi.module`** (modificar) — **una** ruta nueva: `api/v1/service-offers/%/accept`, con `page callback` `myapi_service_offer_accept_dispatch`, `page arguments` `[3]`, acceso `TRUE` y `file` `resources/service_offer.resource.inc`. Cinco componentes, como `api/v1/service-offers/%/withdraw` (SPEC 105) y como `api/v1/service-offers/provider/%` (SPEC 102), y no compite con ninguna de las dos: `/service-offers/901/accept` tiene `901` en `[3]`, así que no puede ser la de `provider`, y `accept` en `[4]`, así que no puede ser la de `withdraw`. Drupal 7 resuelve los tres literales sin ambigüedad y el análisis ya está escrito en el comentario que SPEC 105 dejó sobre esa ruta; este spec **lo amplía en una línea**, no lo reescribe.

- **`resources/service_offer.resource.inc`** (modificar) — dos funciones nuevas, dos `module_load_include()` nuevos (`includes/myapi.service_transaction` y `includes/myapi.service_request_detail`) y ni una línea tocada de las existentes:
  - `myapi_service_offer_accept_dispatch($nid)` — `PUT` y nada más; el `405` **antes** del token y antes de cualquier consulta, como todo despachador del módulo.
  - `myapi_service_offer_accept($nid)` — el endpoint entero, en el orden fijo de la sección «La compuerta» y con las cuatro escrituras en el orden que la cabecera defiende.

- **`includes/myapi.service_request_detail.inc`** (**nuevo**) — **la primera extracción**, y la más grande. Salen de `resources/service_request.resource.inc`, con sus docblocks íntegros y **sin una línea de cambio de comportamiento**: `myapi_service_request_offer_counts_by_nid()`, `myapi_service_request_load_images()`, `myapi_service_request_build_file()`, `myapi_service_request_load_offers()`, `myapi_service_request_load_transactions()` y `myapi_service_request_build_detail()`. Es la mitad *cargador y serializador* del detalle del residente; la mitad *consulta* ya vive extraída en `includes/myapi.service_request_query.inc` desde SPEC 89, y esta viñeta termina un reparto que aquel spec dejó a medias. Las seis se llamaban **solo** desde ese recurso —verificado llamante a llamante—, así que ningún otro fichero se entera.

- **`includes/myapi.service_offer.inc`** (modificar) — una compuerta y **la segunda extracción**:
  - `myapi_service_offer_accept_gate($row, $request_row, $provider_row, $now)` (pura, nueva) — las condiciones 6 a 9. Devuelve el primer `error_code` que falla o `NULL`. Ni consulta, ni `node_load()`, ni salida HTTP: el que llama ya pagó las tres filas y decide qué responder, que es lo que permite probarla entera sin sitio arrancado.
  - `myapi_service_offer_reject_live($request_nid, $except_nid = NULL)` (**extraída**) — es `myapi_service_request_reject_live_offers()` de SPEC 95, movida desde `resources/service_request.resource.inc` y con un segundo parámetro opcional. La regla 5 de `CLAUDE.md` prohíbe que un recurso llame a las funciones internas de otro, y esta hace falta en los dos; su sitio natural es el `includes/` de las ofertas, porque de ofertas es de lo que habla. **Cambia de nombre al mudarse**, para no dejar un `myapi_service_request_*` viviendo en el fichero de las ofertas. `myapi_service_request_cancel()` pasa a llamarla **sin** el segundo argumento y su comportamiento es idéntico.

- **`includes/myapi.service_transaction.inc`** (modificar) — **la tercera extracción** y su texto:
  - `myapi_service_transaction_record($request_nid, $status, $uid, $comment)` (**extraída**) — el bloque que `myapi_service_request_cancel()` escribe hoy en línea para construir y guardar el nodo `service_transaction`: `type`, `uid`, `status`, `node_object_prepare()`, `field_request`, `field_request_status`, `field_status_date` con `date('Y-m-d H:i:00')`, `field_comment` sin `format`, y `node_save()`. El título **sigue sin ponerse aquí** — lo pone `myapi_service_transaction_set_title()` desde `hook_node_presave()`, dentro de ese mismo `node_save()`.
  - `myapi_service_transaction_accept_comment($provider_name, $amount, $amount_type)` (pura, nueva) — el texto de la línea de tiempo. Vive aquí y no en el recurso porque `myapi_service_transaction_initial_comment()` ya vive aquí: los comentarios de una transacción son del fichero de las transacciones.

- **`resources/service_request.resource.inc`** (modificar) — pierde las seis funciones del detalle, pierde el barrido de ofertas, pierde la construcción en línea de la transacción, y gana un `module_load_include()` para el fichero nuevo. `myapi_service_request_provider_build_detail()` **se queda**: no la usa nadie de fuera, y moverla sería mover código sin motivo. Pasará a llamar a `myapi_service_request_build_file()` desde el `includes/` nuevo, que es exactamente para lo que están los `includes/`.

- **`myapi.info`** (modificar) — gana el `files[] = includes/myapi.service_request_detail.inc`. Es el único fichero nuevo del spec, y por tanto lo único que hace obligatorio el `drush cc all` del primer paso.

- **`includes/myapi.i18n.inc`** (modificar) — las cuatro claves nuevas en `es` y `en`.

- **`docs/service-offer.md`** (modificar) — una sección nueva siguiendo la plantilla de `CLAUDE.md`, y la lista «What is still not here» **pierde el punto de la adjudicación**, que es justo el que este spec cumple.

- **`specs/services/105-service-offer-update-withdraw.md`** (anotar) — su «Fuera del alcance: **Adjudicar una oferta**» se marca **✅ Resuelto por SPEC 106**, con la convención de SPEC 42/104/105: el spec viejo conserva lo que decidió y apunta a quién lo cambió.

- **Tests**: `tests/unit/ServiceOfferAcceptTest.php` (nuevo). `ServiceRequestCancelTest` se toca **solo** en el nombre que invoca.

**Fuera del alcance (para specs futuras):**

- **Des-adjudicar, o cambiar de oferta ganadora.** Un segundo `accept` responde `409` y **no** reasigna. Deshacer una adjudicación tiene que decidir qué pasa con las ofertas que este endpoint rechazó y con la línea de tiempo que ya escribió, y eso es un spec, no una rama.
- **Notificar.** Ni al proveedor que ganó ni a los que perdieron. El marketplace sigue sin notificador, exactamente como lo dejaron SPEC 95 y SPEC 100.
- **Cerrar la solicitud y valorarla.** `assigned → closed` y `service_rating` siguen sin endpoint. Este spec escribe `assigned` y ahí para.
- **Sincronizar `open → offered` al crear una oferta.** El agujero que el docblock de `myapi_service_request_update_gate()` documenta —una oferta creada desde el back office deja la solicitud en `open`— **sigue abierto**. Una solicitud `open` con ofertas colgando responde `409 service_request_not_assignable` y el residente no puede adjudicar desde la app. Decisión 3, tomada y explicada: este spec no inventa la arista `open → assigned` ni se mete a arreglar el `hook_node_insert()` que le falta a SPEC 100.
- **Adjudicar sobre una solicitud `direct`.** Adjudica en el nacimiento (SPEC 87) y no tiene ofertas que aceptar; el grafo no lleva `direct → assigned` y este spec no se la añade.
- **Caducar ofertas por `valid_until`.** La compuerta lo **lee** para no adjudicar una oferta vencida, pero nada barre las caducadas ni les cambia el estado. Una oferta vencida sigue diciendo `sent` en los tres sitios donde viaja.
- **Atomicidad.** Las cuatro escrituras no van en una transacción de base de datos, por la misma razón y con el mismo precio que SPEC 95 aceptó — ver Riesgos.
- **El back office.** Un operador ya adjudica a mano desde `node/%/edit`, porque `selected` y `assigned` están en los `allowed_values` desde SPEC 77 y los dos campos de asignación están en el formulario. No se toca nada de eso, ni se le añade validación del grafo — sigue siendo el spec pendiente que `includes/myapi.service_transaction.inc` lleva anotado.

Un detalle del alcance que conviene ver antes de seguir:

> **`/accept` y `/withdraw` son rutas espejo con actores opuestos.** El `withdraw` de SPEC 105 es del **proveedor** y responde `403 service_offer_provider_not_owned` a un residente; el `accept` que añade este spec es del **residente** y responde `403 service_request_forbidden` a un proveedor, incluso al dueño de la oferta. Las dos operan sobre la misma oferta, por la misma URL base, con compuertas que no comparten una sola condición más allá del `404` y del token. Es el mismo reparto que ya conviven el `GET` y el `PUT` de `api/v1/service-offers/{id}`.

---

## Modelo de datos

**Ningún cambio de esquema.** Ni campo, ni instancia, ni bundle, ni tabla, ni catálogo, ni arista del grafo, ni `hook_update_N`. `selected` está en `myapi_services_offer_statuses()`, `assigned` está en `myapi_services_request_statuses()`, la arista `offered → assigned` está en `myapi_services_request_transitions()`, y `field_assigned_offer` y `field_assigned_provider` están instalados en el bundle `service_request` con sus `selection_settings` desde SPEC 77. Lo único que faltaba era quien lo escribiera.

### Lo que se lee, y de dónde

| Dato | Función | De quién es |
|---|---|---|
| La oferta, sus quince claves, `provider_raw` y `request_id` | `myapi_service_offer_detail_row($nid)` | SPEC 103 |
| La solicitud: `requester_uid` y `status` | `myapi_service_request_detail_row($row->request_id)` | SPEC 89 |
| La licencia del proveedor: `status` y `license_expiry` | `myapi_service_offer_provider_row($row->provider_raw, $uid)` | SPEC 100 |

**Ninguna consulta nueva, y ni una línea de SQL en este spec.** Las tres funciones existen, devuelven ya todo lo que la compuerta necesita, y `valid_until` viaja **como timestamp** dentro de la primera —así que compararlo cuesta un entero y no un `strtotime()`.

Tres notas sobre esas tres lecturas:

- **La solicitud se lee con `myapi_service_request_detail_row()` y no con `node_load()`, al revés que `myapi_service_request_cancel()`.** Una consulta trae `requester_uid` y `status` juntos, que es todo lo que la compuerta pregunta de la solicitud, y el `node_load()` se paga **después**, solo cuando ya se sabe que va a haber escritura. Tiene un efecto lateral que es una mejora sobre SPEC 95 y no una casualidad: esa función hace `INNER JOIN` con el término de taxonomía de la categoría, así que una solicitud cuya categoría fue borrada **falla en la compuerta con un `404`, antes de escribir nada**, en lugar de fallar al construir la respuesta con las cuatro escrituras ya hechas. La rama degradada de SPEC 95 sigue existiendo aquí, pero pasa a ser casi inalcanzable — Decisión 9.
- **La licencia se lee sobre `provider_raw`, la columna cruda, y nunca sobre `provider_id`.** Es el split que el `@return` de `myapi_service_offer_detail_row()` documenta: la columna unida lleva `status = 1` dentro, así que un proveedor **despublicado** deja `provider_id` a `NULL` y se colaría por debajo de la comprobación. Sobre la cruda, dispara el `403` que le toca.
- **`myapi_service_offer_provider_row()` se reutiliza tal cual aunque calcule dos cosas que aquí no se miran** — `owned` y `category_ids`, que solo tienen sentido para un proveedor. Decisión 12: una definición de «qué es un proveedor para una compuerta», dos consultas de más en un endpoint que ya hace seis para responder, y cero SQL nuevo que mantener.

### `PUT /api/v1/service-offers/{id}/accept` — sin cuerpo

**Ni una clave.** Un cuerpo presente se ignora entero, incluido un JSON malformado: no hay nada que parsear, así que no hay nada que pueda fallar. Idéntico al `withdraw` de SPEC 105, y por la misma razón — la URL identifica sola a la oferta, y una oferta sabe de qué solicitud es.

### La compuerta

La primera condición que falla responde.

| # | Condición | Si falla |
|---|---|---|
| 1 | `{id}` es entero positivo | `404 not_found` — **antes del token y sin consulta** |
| 2 | Token válido | `401 missing_authorization` / `401 invalid_token` |
| 3 | `myapi_service_offer_detail_row()` devuelve fila | `404 not_found` |
| 4 | `myapi_service_request_detail_row($row->request_id)` devuelve fila | `404 not_found` |
| 5 | `requester_uid === $uid` | `403 service_request_forbidden` |
| 6 | `status` de la oferta `=== 'sent'` | `409 service_offer_not_acceptable` |
| 7 | `myapi_services_transition_allowed($request_status, 'assigned')` | `409 service_request_not_assignable` |
| 8 | `valid_until` vacío **o** `>= REQUEST_TIME` | `409 service_offer_expired` |
| 9 | `myapi_services_provider_is_active($p->status, $p->license_expiry, REQUEST_TIME)` | `403 service_offer_provider_not_active` |

Las condiciones **6 a 9** son `myapi_service_offer_accept_gate()`, pura y probable entera contra filas fixture. Las cinco primeras son del recurso: cuestan consultas y salida HTTP, y la compuerta no toca ninguna de las dos cosas.

Cinco notas sobre este orden:

- **`404` y `403` significan cosas distintas**, mismo criterio que SPEC 89, 95 y 103: la oferta existe y su nid no es un secreto, así que el `403` le dice al cliente algo accionable en vez de fingir que no está. Un proveedor —incluso el dueño de la oferta— cae en la 5.
- **El grafo se pregunta, no se transcribe.** La condición 7 no lleva escrito «`offered`» en ningún sitio: se lo pregunta a `myapi_services_transition_allowed()`, que es dueña del grafo desde SPEC 77. Copiar aquí la lista de estados adjudicables crearía una segunda verdad que se desincroniza el día que alguien añada un estado. Y como esa función responde `FALSE` a un valor desconocido o vacío por diseño, un `field_request_status` corrupto es un `409` y jamás un `500`.
- **Por esa misma condición, una solicitud `open` con ofertas colgando responde `409`.** Es el agujero conocido de SPEC 100 —nada sincroniza `open → offered`— y este spec **no** lo tapa; ver Alcance y Decisión 3.
- **`valid_until` ausente significa que no caduca.** Es opcional desde SPEC 100 y la mayoría de las ofertas no lo traen; leerlo como «caducada» bloquearía casi todo el catálogo. La comparación va en `>=`, igual que `myapi_services_provider_is_active()`: la oferta vale **durante todo** su instante de vencimiento y no un segundo menos.
- **La licencia va la última, y es deliberado.** Las condiciones 6, 7 y 8 preguntan *«¿es adjudicable esta oferta?»* y la 9 pregunta *«¿este proveedor sigue habilitado para trabajar?»*. La segunda solo tiene sentido si la primera ya pasó — el mismo orden que SPEC 105 fijó para editar.

### Las cuatro escrituras, en este orden

| # | Qué | Detalle |
|---|---|---|
| 1 | **La solicitud** | `field_request_status = 'assigned'`, `field_assigned_offer = {id}`, `field_assigned_provider = provider_raw`. Un solo `node_save()`. |
| 2 | **La transacción** | `myapi_service_transaction_record($request_id, 'assigned', $uid, $comment)`. |
| 3 | **La oferta ganadora** | `field_offer_status = 'selected'`. |
| 4 | **Las perdedoras** | `myapi_service_offer_reject_live($request_id, $offer_nid)` → `rejected`. |

El orden es **el mismo de SPEC 95**, y por las mismas tres razones, que aquí solo se heredan:

- **La solicitud primero**, para que `myapi_service_transaction_sync_request_status()` (SPEC 94), que dispara en el `hook_node_insert()` de la transacción, compare los dos estados, los encuentre iguales y vuelva **sin** guardar la solicitud por segunda vez. Quien toque cualquiera de las dos cosas tiene que conservar esa propiedad.
- **El efecto principal se escribe a la vista, en el endpoint**, y no se delega en el hook. Crear solo la transacción y dejar que el sync escriba el estado ahorraría una línea y haría que la adjudicación dejara de adjudicar, en silencio, el día que alguien edite ese sync.
- **Las ofertas al final, y no atómicamente.** No se abre transacción de base de datos: `node_save()` con la Field API y sus hooks dentro de una es una fuente conocida de bloqueos en Drupal 7, y es literalmente la razón que el docblock de la función extraída ya lleva escrita.

Y dos que son propias de este spec:

- **`field_assigned_provider` se escribe desde `provider_raw`**, el `target_id` crudo de la oferta, y no desde `provider_id`. La condición 9 ya garantiza que el proveedor está activo, así que las dos columnas valen lo mismo hoy; se usa la cruda porque es **el valor del campo**, y porque el día que la condición 9 se relaje el registro de a quién se le adjudicó no debe depender de si su ficha sigue publicada.
- **La ganadora se excluye del barrido por nid**, con el segundo parámetro de `myapi_service_offer_reject_live()`. No por su estado: cuando el barrido corre, la ganadora ya dice `selected`, que es uno de los dos estados que esa función considera vivos, así que sin el `$except_nid` se rechazaría a sí misma.

### El comentario de la línea de tiempo

`myapi_service_transaction_accept_comment($provider_name, $amount, $amount_type)`, pura, con `t()` y `@placeholder` como las dos que ya viven en ese fichero:

| Caso | Texto |
|---|---|
| Con importe | `Oferta adjudicada a @proveedor por @importe.` |
| `amount_type === 'on_site_quote'`, o sin importe | `Oferta adjudicada a @proveedor.` |
| Sin nombre de proveedor | `Oferta adjudicada.` |

El importe va con `number_format($amount, 2, ',', '.')` y **sin símbolo de moneda**: el módulo no guarda ninguna en `field_offer_amount`, y poner uno a mano sería inventarse un dato. La tercera fila no puede darse hoy —la condición 9 exige un proveedor activo, que por tanto está publicado y tiene título— y existe porque una función pura que recibe `NULL` debe devolver una frase y no una a medias.

### Respuesta de éxito (200)

**El detalle completo del residente**, exactamente como el `cancel` de SPEC 95: el objeto de diecinueve claves que sirve `GET /api/v1/service-requests/{id}`, reconstruido **después** de las cuatro escrituras con los mismos cargadores y el mismo serializador, más `offers_rejected` como **clave hermana** y no como vigésima clave del objeto.

```json
{
  "success": true,
  "data": {
    "service_request": { "id": 128, "status": "assigned", "viewer": "requester", "…": "las diecinueve claves de SPEC 89" },
    "offers_rejected": 3
  },
  "message": "Oferta adjudicada correctamente."
}
```

- **`200` y no `201`:** no nace ningún recurso que el cliente vaya a direccionar. La transacción es un efecto, no la respuesta.
- **Seis consultas, y se pagan a propósito.** La app repinta de una sola vez la pantalla en la que ya está —estado nuevo, `assigned_offer`, las ofertas con sus estados recién escritos y la línea de tiempo con la entrada ya dentro— sin un segundo viaje. Y la respuesta **no puede** discrepar de lo que diría un `GET`, porque es lo que responde un `GET`.
- **`viewer` vale siempre `requester`:** la condición 5 demostró que quien llega aquí es el `field_requester`, y nadie más alcanza esa línea.
- **`offers_rejected` es hermana** para que el objeto bajo `service_request` sea idéntico byte a byte al del detalle y la app pueda sustituirlo sin un caso especial. Y es lo único que el cliente no puede deducir de lo que acaba de recibir: `offers` muestra qué ofertas están rechazadas **ahora**, no cuáles rechazó **esta llamada**.

### Claves i18n nuevas — cuatro

| Clave | `es` | `en` |
|---|---|---|
| `service_offer_accepted` | Oferta adjudicada correctamente. | Offer awarded successfully. |
| `service_offer_not_acceptable` | Esta oferta ya no se puede aceptar. | This offer can no longer be accepted. |
| `service_offer_expired` | Esta oferta ha caducado. | This offer has expired. |
| `service_request_not_assignable` | Esta solicitud ya no admite adjudicar una oferta. | This request can no longer be awarded. |

Reutilizadas sin cambio: `not_found`, `missing_authorization`, `invalid_token`, `method_not_allowed`, `service_request_forbidden` y `service_offer_provider_not_active`.

---

## Plan de implementación

**Siete pasos.** Los cinco primeros no encienden nada: mueven código, añaden funciones que nadie llama todavía y traducen. Ninguno cambia una sola respuesta de la API, y esa es la propiedad que hace que sus verificaciones sean tests viejos en verde y no tests nuevos.

Las **tres extracciones van antes que todo** porque el endpoint necesita las tres, y van **en orden de tamaño decreciente**: la grande primero, mientras el contexto está fresco y no hay nada nuevo con lo que confundirla.

1. **La extracción grande: `includes/myapi.service_request_detail.inc`.**
   Nace el fichero con las seis funciones movidas de `resources/service_request.resource.inc`, con sus docblocks íntegros y **sin una línea de cambio**: `myapi_service_request_offer_counts_by_nid()`, `myapi_service_request_load_images()`, `myapi_service_request_build_file()`, `myapi_service_request_load_offers()`, `myapi_service_request_load_transactions()` y `myapi_service_request_build_detail()`. `myapi.info` gana su `files[]`, y `resources/service_request.resource.inc` gana su `module_load_include()` en el bloque de cabecera que ya tiene.
   Las seis se llamaban **solo** desde ese recurso —verificado llamante a llamante— así que ningún otro fichero se entera. `myapi_service_request_provider_build_detail()` **se queda** donde está: no la usa nadie de fuera, y moverla sería mover código sin motivo.
   *Verificación: `php -l` sobre los tres ficheros; `drush cc all` (hay fichero nuevo en `files[]`); **`ServiceRequestDetailEndpointTest`, `ServiceRequestListEndpointTest`, `ServiceRequestCreateEndpointTest`, `ServiceRequestCancelTest`, `ServiceRequestUpdateTest`, `ServiceRequestProviderDetailTest` y `ServiceOfferDetailTest` en verde sin tocar un solo test**. Siete suites intactas es la prueba de que la mudanza no movió nada.*

2. **La segunda extracción: el barrido de ofertas.**
   `myapi_service_request_reject_live_offers($nid)` sale de `resources/service_request.resource.inc`, entra en `includes/myapi.service_offer.inc` como `myapi_service_offer_reject_live($request_nid, $except_nid = NULL)`, y gana la condición del `$except_nid` —un `if` de dos líneas sobre la lista de nids, después de la consulta, para no meterle un parámetro condicional al `db_select`—. `myapi_service_request_cancel()` pasa a llamarla **sin** segundo argumento.
   *Verificación: `php -l`; `ServiceRequestCancelTest` en verde cambiando **solo el nombre que invoca**, ni una aserción; un test nuevo que le pasa un `$except_nid` y comprueba que esa oferta sobrevive y las demás no, y otro que le pasa un `$except_nid` que no es de esa solicitud y comprueba que no cambia nada.*

3. **La tercera extracción y su texto: la transacción.**
   `myapi_service_transaction_record($request_nid, $status, $uid, $comment)` entra en `includes/myapi.service_transaction.inc` con las líneas que `myapi_service_request_cancel()` escribe hoy en línea —`type`, `uid`, `status`, `node_object_prepare()`, los cuatro campos, `node_save()`— y **sin** poner el título, que sigue siendo cosa de `hook_node_presave()`. `cancel` pasa a llamarla. En el mismo fichero, `myapi_service_transaction_accept_comment($provider_name, $amount, $amount_type)` con sus tres casos.
   *Verificación: `php -l`; `ServiceRequestCancelTest` otra vez en verde, ahora sin cambiar ni el nombre —la llamada es nueva pero lo que se guarda es idéntico—; la matriz de los tres casos del comentario, incluido el importe con coma decimal y el `on_site_quote` sin importe.*

4. **La compuerta pura.**
   `myapi_service_offer_accept_gate($row, $request_row, $provider_row, $now)` en `includes/myapi.service_offer.inc`, con las condiciones 6 a 9 en ese orden, devolviendo el primer `error_code` o `NULL`. Nada la llama todavía.
   *Verificación: `php -l`; la matriz completa contra filas fixture, sin sitio arrancado — el mismo patrón con el que se prueban `myapi_service_offer_eligibility()` y las compuertas de SPEC 105. Casos obligatorios: los cuatro estados de oferta, un `field_request_status` corrupto y uno vacío (los dos `409` y no excepción), `valid_until` ausente / pasado / exactamente igual a `$now`, y proveedor despublicado / con licencia vencida / sin fila de licencia.*

5. **`includes/myapi.i18n.inc` — las cuatro claves.**
   `service_offer_accepted`, `service_offer_not_acceptable`, `service_offer_expired`, `service_request_not_assignable`, en `es` y en `en`.
   *Verificación: `php -l`; `I18nTest` (paridad de los dos catálogos) en verde.*

6. **El endpoint entero: ruta, despachador y adjudicación.**
   `api/v1/service-offers/%/accept` en `hook_menu()`, ampliando en una línea el comentario de desambiguación que SPEC 105 dejó sobre la ruta hermana. `myapi_service_offer_accept_dispatch()` con el `405` antes del token. `myapi_service_offer_accept()`: las cinco condiciones del recurso, la compuerta pura, las cuatro escrituras en su orden, y la respuesta con el detalle completo más `offers_rejected`. `resources/service_offer.resource.inc` gana dos `module_load_include()`: `includes/myapi.service_transaction` y `includes/myapi.service_request_detail`.
   *Verificación: `ServiceOfferAcceptTest`; `drush cc all` y un `PUT` real sobre una solicitud `offered` con tres ofertas — la respuesta trae `status: "assigned"`, `assigned_offer` y `assigned_provider` poblados, `offers_rejected: 2`, la ganadora en `selected` y la línea de tiempo con la entrada nueva la última. Y las tres comprobaciones cruzadas: el mismo `GET /api/v1/service-requests/{id}` responde lo mismo que acaba de responder el `PUT`; el archivo del proveedor (SPEC 102) enseña la ganadora en `selected` y las otras en `rejected`; y un segundo `PUT` sobre la ganadora responde `409 service_offer_not_acceptable`.*

7. **Documentación.**
   `docs/service-offer.md` gana su sección con la plantilla de `CLAUDE.md` —los nueve escalones de la compuerta en la tabla de errores, el aviso de que el cuerpo se ignora, el del reemplazo por el detalle completo y el de la no idempotencia— y pierde el punto de la adjudicación de «What is still not here». `specs/services/105-service-offer-update-withdraw.md` marca su «Fuera del alcance: Adjudicar una oferta» como **✅ Resuelto por SPEC 106**.
   *Verificación: leída de arriba abajo; el `curl` de la sección se ejecuta y responde lo que el documento dice que responde.*

**Ni `drush updb` ni `hook_update_N` en ningún paso.** No hay esquema que actualizar. `drush cc all` hace falta dos veces y por dos motivos distintos: en el paso 1 porque hay un fichero nuevo en `files[]`, y en el paso 6 porque cambia `hook_menu()`.

---

## Criterios de aceptación

Casillas booleanas. Ninguna dice «funciona bien».

**Ruta y despacho**

- [ ] `PUT /api/v1/service-offers/901/accept` llega a `myapi_service_offer_accept_dispatch(901)`.
- [ ] `GET`, `POST`, `PATCH` y `DELETE` sobre esa ruta responden `405 method_not_allowed`, **sin** validar token y **sin** una sola consulta.
- [ ] `PUT /api/v1/service-offers/provider/41` sigue respondiendo el detalle del proveedor y `PUT /api/v1/service-offers/901/withdraw` sigue retirando. Ninguna de las dos cae en la ruta nueva.
- [ ] `PUT /api/v1/service-offers/901` sigue editando (SPEC 105) y `GET /api/v1/service-offers/901` sigue sirviendo el detalle del residente (SPEC 103).

**La compuerta, escalón a escalón**

- [ ] `/api/v1/service-offers/abc/accept`, `/0/accept` y `/-3/accept` responden `404 not_found` sin token y sin consulta.
- [ ] Sin cabecera `Authorization`: `401 missing_authorization`. Con token inválido o caducado: `401 invalid_token`.
- [ ] Una oferta inexistente, despublicada, de otro bundle, o cuya solicitud está despublicada o no es `service_request`: `404 not_found`.
- [ ] Una oferta cuya solicitud tiene el término de categoría borrado: `404 not_found`, **y ninguna de las cuatro escrituras ocurrió**.
- [ ] Un residente que no es el `field_requester` de la solicitud: `403 service_request_forbidden`. El proveedor dueño de la oferta, también.
- [ ] Una oferta en `selected`, `rejected` o `withdrawn`: `409 service_offer_not_acceptable`.
- [ ] Una solicitud en `open`, `direct`, `assigned`, `closed` o `cancelled`: `409 service_request_not_assignable`. Con `field_request_status` vacío o con un valor que no está en el catálogo: **también `409`**, nunca una excepción ni un `500`.
- [ ] Una oferta con `valid_until` en el pasado: `409 service_offer_expired`. Con `valid_until` vacío: **pasa**. Con `valid_until` exactamente igual a `REQUEST_TIME`: **pasa**.
- [ ] Una oferta cuyo proveedor está despublicado, tiene la licencia vencida o no tiene fila de licencia: `403 service_offer_provider_not_active`.
- [ ] El orden se respeta: una oferta `rejected` de un proveedor despublicado responde `409 service_offer_not_acceptable` y no `403`.
- [ ] Un cuerpo cualquiera —vacío, con claves, o JSON malformado— no cambia ninguna de las respuestas anteriores ni la del éxito.

**Las cuatro escrituras**

- [ ] La solicitud queda en `assigned`, con `field_assigned_offer` = el nid de la oferta y `field_assigned_provider` = el `field_provider_target_id` de esa oferta.
- [ ] La oferta adjudicada queda en `selected`.
- [ ] Toda oferta de esa solicitud que estaba en `sent` queda en `rejected`. **La adjudicada no**, aunque el barrido considere `selected` un estado vivo.
- [ ] Las que ya estaban en `withdrawn` o `rejected` no se tocan.
- [ ] Existe un nodo `service_transaction` nuevo con `field_request` = la solicitud, `field_request_status` = `assigned`, `field_status_date` con la hora real y los segundos a `00`, `field_comment` = el texto del catálogo, y `node.uid` = el residente.
- [ ] Ese nodo tiene título, puesto por `myapi_service_transaction_set_title()`.
- [ ] La solicitud se guarda **una sola vez**: `myapi_service_transaction_sync_request_status()` encuentra los dos estados iguales y no la vuelve a guardar.
- [ ] `node.uid`, `node.created`, `node.title`, la unidad, la categoría, la descripción y los ficheros de la solicitud siguen valiendo lo mismo. La solicitud sigue publicada.
- [ ] La oferta conserva `node.uid`, `node.created`, `node.title`, `field_request`, `field_provider` y sus doce campos de presupuesto.

**La respuesta**

- [ ] `200`, `success: true`, `message` = `service_offer_accepted` traducido según `Accept-Language`.
- [ ] `data.service_request` es **byte a byte** el mismo objeto de diecinueve claves que responde `GET /api/v1/service-requests/{id}` inmediatamente después, con `viewer: "requester"`.
- [ ] `data.offers_rejected` es un entero, hermano de `service_request`, y vale el número de ofertas que **esta llamada** pasó a `rejected` — sin contar la adjudicada ni las que ya estaban rechazadas.
- [ ] Dentro de `service_request`: `status` es `assigned`, `assigned_offer` apunta a la ganadora, `assigned_provider` al proveedor, `offers` trae todas con sus estados nuevos y `transactions` termina con la entrada de la adjudicación.
- [ ] `offers_count` no cambia: adjudicar no borra ofertas.

**No idempotencia**

- [ ] Un segundo `PUT` sobre la misma oferta responde `409 service_offer_not_acceptable` y **no** escribe nada.
- [ ] Un `PUT` sobre **otra** oferta de la misma solicitud, ya adjudicada, responde `409 service_offer_not_acceptable` —la otra ya está en `rejected`— y jamás reasigna.

**Las tres extracciones**

- [ ] `includes/myapi.service_request_detail.inc` existe, está en `myapi.info`, y contiene las seis funciones con sus docblocks íntegros.
- [ ] `ServiceRequestDetailEndpointTest`, `ServiceRequestListEndpointTest`, `ServiceRequestCreateEndpointTest`, `ServiceRequestUpdateTest`, `ServiceRequestProviderDetailTest` y `ServiceOfferDetailTest` pasan **sin un solo cambio**.
- [ ] `ServiceRequestCancelTest` pasa cambiando **únicamente** el nombre `myapi_service_request_reject_live_offers` por `myapi_service_offer_reject_live`.
- [ ] `myapi_service_offer_reject_live($nid)` sin segundo argumento se comporta exactamente como la función que sustituye.
- [ ] `PUT /api/v1/service-requests/{id}/cancel` responde lo mismo que antes del spec, con el mismo número de consultas y el mismo nodo de transacción.
- [ ] `myapi_service_request_provider_build_detail()` sigue en el recurso y sigue funcionando llamando a `myapi_service_request_build_file()` desde el `includes/` nuevo.

**Catálogos y documentación**

- [ ] `I18nTest` en verde: las cuatro claves nuevas existen en `es` y en `en`.
- [ ] `myapi_services_offer_statuses()`, `myapi_services_request_statuses()` y `myapi_services_request_transitions()` **no cambian**.
- [ ] `myapi.install` no cambia y no hay `hook_update_N` nuevo.
- [ ] `docs/service-offer.md` documenta el endpoint con la plantilla de `CLAUDE.md` y su tabla de errores lista los nueve escalones.
- [ ] `specs/services/105-service-offer-update-withdraw.md` marca la adjudicación como ✅ Resuelto por SPEC 106.
- [ ] `ServiceOfferAcceptTest` existe y cubre la matriz entera de la compuerta más las cuatro escrituras.

---

## Decisiones tomadas y descartadas

**1. Un `PUT` sobre la oferta, no un `POST` sobre la solicitud.**
Descartado `POST /api/v1/service-requests/{id}/assign` con `{"offer_id": …}`. No nace ningún recurso, así que un `POST` y un `201` mentirían sobre lo que pasa; y el objeto cuyo estado cambia por la decisión del residente es la oferta —que la solicitud pase a `assigned` es la consecuencia—. Además, la URL identifica sola a la oferta, y con ella desaparece un caso de error entero: «la oferta que mandas no es de esta solicitud» no puede ocurrir.

**2. Cuerpo vacío, y se ignora entero si llega.**
No hay nada que parsear, así que no hay nada que pueda fallar: ni un `400` por JSON malformado, ni un `422` por una clave sobrante. Idéntico al `withdraw` de SPEC 105.

**3. Solo se adjudica desde `offered`, preguntándole al grafo.**
Descartado añadir la arista `open → assigned`, y descartado también arreglar aquí el agujero de SPEC 100 —nada sincroniza `open → offered`, así que una oferta creada desde el back office deja la solicitud en `open` con ofertas colgando—. Ese agujero es real, está documentado en `myapi_service_request_update_gate()` y es de otro spec: taparlo obliga a decidir qué hace `hook_node_insert()` sobre `service_offer`, que es tanto trabajo como este spec entero. Consecuencia asumida: una solicitud `open` con ofertas responde `409` y el residente no puede adjudicar desde la app hasta que ese spec exista. `direct` queda fuera por otra razón, y es definitiva: adjudica en el nacimiento (SPEC 87), no tiene ofertas que aceptar, y el grafo no lleva `direct → assigned`.

**4. Adjudica el `field_requester`, exactamente.**
No `node.uid` —una solicitud que un operador registró en nombre de un residente es del residente, criterio de SPEC 89 y SPEC 95— y no el resto de la unidad, al revés que un pago (SPEC 23): una solicitud de servicio la firma una persona y el hogar no hereda el derecho a adjudicarla. El proveedor no adjudica nunca, ni siquiera el suyo.

**5. Las demás ofertas vivas pasan a `rejected` en bloque.**
Descartado dejarlas en `sent`. Una oferta `sent` colgando de una solicitud `assigned` es una mentira para el proveedor que la mira en su archivo (SPEC 102): le dice que sigue compitiendo por un trabajo que ya se dio. El barrido reutiliza el criterio que SPEC 95 ya fijó —`withdrawn` y `rejected` no se tocan, porque el primero es la retirada del propio proveedor y sobrescribirla borraría quién se fue por su pie—.

**6. La adjudicación escribe línea de tiempo.**
El criterio que separa a SPEC 95 de SPEC 105 es limpio: **cambia el estado de la solicitud → hay transacción**. Cancelar lo cambia y escribe; editar y retirar una oferta no lo cambian y no escriben. Adjudicar lo cambia.

**7. Se valida `valid_until`.**
Se aparta de SPEC 100 y 105, que lo dejaron fuera. La razón es que aquí la fecha significa algo que en los otros dos verbos no significaba: es el proveedor diciendo «este precio vale hasta tal día», y adjudicarle un trabajo a un precio que él ya declaró vencido es comprometerle a algo que no ofreció. **Ausente sigue significando «no caduca»** —es opcional y la mayoría de las ofertas no lo traen—, y la comparación va en `>=`, igual que la licencia. Lo que **no** se hace es barrer las caducadas: siguen diciendo `sent` en todas partes, y eso es Riesgo.

**8. Se valida la licencia del proveedor, y es la última condición.**
Descartado no validarla con el argumento «el residente elige entre lo que ya se le enseñó». Pesó más el otro lado: adjudicar es poner a trabajar a un proveedor, y el módulo tiene una definición de quién puede trabajar —`myapi_services_provider_is_active()`, publicado **y** con licencia vigente— que no debe tener una excepción justo en el momento en que el trabajo se da. Va la última porque las condiciones 6, 7 y 8 preguntan «¿es adjudicable esta oferta?» y esta pregunta «¿este proveedor sigue habilitado?»: la segunda solo tiene sentido si la primera pasó. Es el orden que SPEC 105 fijó para editar.

**9. La solicitud se lee con `myapi_service_request_detail_row()` y no con `node_load()`.**
Se aparta de `myapi_service_request_cancel()`, que hace `node_load()` y luego `myapi_building_admin_field_target_id()`. Una consulta trae `requester_uid` y `status` juntos, que es todo lo que la compuerta pregunta, y el `node_load()` se paga después, solo cuando ya hay escritura segura. El efecto lateral es la mejora de verdad: esa función hace `INNER JOIN` con el término de la categoría, así que una solicitud con la categoría borrada falla **en la compuerta, con un `404`, antes de escribir**, en vez de fallar al construir la respuesta con todo ya escrito. La rama degradada de SPEC 95 se hereda igualmente, pero pasa a ser casi inalcanzable.

**10. El `200` es el detalle completo del residente, no la oferta.**
Descartado responder el objeto de quince claves más `request` hermano, que es lo que hace el `withdraw` de SPEC 105 y sería más barato. Adjudicar cambia **cuatro** cosas a la vez y tres de ellas no están en la oferta: el estado de la solicitud, sus dos campos de asignación, los estados de las otras ofertas y la línea de tiempo. Un cliente que recibiera solo la oferta tendría que pedir el detalle inmediatamente después, así que las seis consultas se pagan aquí o se pagan allí — y aquí, además, la respuesta **no puede** discrepar de lo que diría un `GET`, porque es lo que responde un `GET`. Este es el precedente de SPEC 95, no una invención.

**11. No es idempotente, a propósito.**
Un segundo `accept` responde `409` y no reasigna. Es el precedente literal de SPEC 95 —«NOT IDEMPOTENT, on purpose»—: le dice al residente que su acción no hizo nada, que es la verdad, y es lo que impide que una segunda transacción duplicada aterrice en la línea de tiempo. Des-adjudicar y cambiar de ganadora son otra operación y otro spec.

**12. `myapi_service_offer_provider_row()` se reutiliza aunque calcule de más.**
Devuelve `owned` y `category_ids`, dos consultas que aquí no se miran porque el residente no es dueño de ningún proveedor. Descartada una consulta escuálida nueva de `status` + `license_expiry`: sería una segunda definición de «qué es un proveedor para una compuerta» dentro del mismo fichero que ya tiene la primera, y dos consultas de más en un endpoint que hace seis para responder no mueven la aguja.

**13. Tres extracciones a `includes/`, en vez de meter el endpoint en el recurso de las solicitudes.**
La alternativa barata era poner `myapi_service_offer_accept()` en `resources/service_request.resource.inc` —`hook_menu()` permite que el `file` de una ruta sea el que sea— y ahorrarse las tres extracciones de golpe, con el argumento nada malo de que adjudicar es una operación del lado de la solicitud. Se descartó por dos cosas: las rutas hermanas `/withdraw` y `/accept` quedarían en ficheros distintos, y sobre todo porque **la asimetría que las extracciones arreglan ya existía antes de este spec**. La mitad *consulta* del detalle del residente lleva extraída en `includes/myapi.service_request_query.inc` desde SPEC 89 y la mitad *cargador y serializador* se quedó atrás. Este spec la termina en vez de aprovecharse de ella.

**14. El barrido de ofertas se renombra al mudarse.**
`myapi_service_request_reject_live_offers()` → `myapi_service_offer_reject_live()`. Dejar un `myapi_service_request_*` viviendo dentro de `includes/myapi.service_offer.inc` sería un nombre que miente sobre dónde está y sobre de qué habla. El coste es exactamente dos líneas en `ServiceRequestCancelTest`, y ninguna aserción.

**15. `field_assigned_provider` se escribe desde `provider_raw`.**
La columna cruda del campo, no la unida. Hoy las dos valen lo mismo, porque la condición 9 ya garantiza que el proveedor está publicado. Se usa la cruda porque es **el valor del campo**, y porque el registro de a quién se le adjudicó un trabajo no debe depender de si su ficha sigue publicada el día que alguien relaje esa condición.

**16. El importe del comentario va sin símbolo de moneda.**
`field_offer_amount` no guarda ninguna, y poner un símbolo a mano sería inventarse un dato que el módulo no tiene. `number_format($amount, 2, ',', '.')` y nada más.

**17. Las cuatro escrituras no van en una transacción de base de datos.**
Heredado de SPEC 95, con su razón intacta: `node_save()` con la Field API y sus hooks dentro de una transacción es una fuente conocida de bloqueos en Drupal 7. El precio está en Riesgos.

---

## Riesgos identificados

**1. Las cuatro escrituras no son atómicas, y un fallo a mitad deja la solicitud atascada.**
No es un riesgo teórico: si el proceso muere entre la escritura 1 y la 3, la solicitud queda en `assigned` con `field_assigned_offer` apuntando a una oferta que todavía dice `sent`, y **el propio endpoint no sabe salir de ahí** — un reintento falla en la condición 7 (`assigned → assigned` no está en el grafo) y responde `409`. Lo mismo entre la 3 y la 4, con la ganadora en `selected` y alguna perdedora todavía en `sent`.
Ninguno de esos estados corrompe nada irreversiblemente y todos se arreglan a mano desde el back office, que es donde SPEC 95 dejó la misma clase de ventana. Lo que se compra a cambio es no meter `node_save()` con la Field API dentro de una transacción de Drupal 7, que es la receta conocida de los bloqueos. El orden elegido está pensado para que la ventana más probable —la del barrido de perdedoras, que es la más larga— sea la más inofensiva: una oferta `sent` colgando de una solicitud `assigned` es fea de leer, pero no adjudica nada ni cobra nada.

**2. La extracción del paso 1 es lo más peligroso del spec.**
Seiscientas líneas movidas tocan el detalle del residente, el del proveedor, los dos listados, la creación, la cancelación y la edición — es decir, casi toda la superficie del recurso más grande del módulo. Un `use` olvidado o una función que se quede sin su `module_load_include()` no se ve en `php -l`: se ve en un `500` en producción.
La red son siete suites que deben pasar **sin un solo cambio**, y el hecho de que el paso vaya el primero y solo, en su propio commit, sin nada nuevo con lo que confundirlo. Si alguna de las siete obliga a tocar una aserción, la mudanza movió algo y hay que averiguar qué antes de seguir.

**3. Si hay solicitudes `open` con ofertas en producción, este spec no les sirve de nada.**
Es la consecuencia directa de la Decisión 3, y conviene medirla antes de desplegar y no después: una consulta que cuente cuántas solicitudes publicadas están en `open` y tienen al menos una oferta publicada. Si el número es alto, el spec que sincroniza `open → offered` deja de ser «el siguiente» y pasa a ser «antes que este», porque a esos residentes el botón de adjudicar les responderá `409` y su única salida seguirá siendo cancelar el trabajo.

**4. Nada caduca las ofertas, así que la app puede ofrecer un botón que responde `409`.**
El endpoint valida `valid_until` (Decisión 7) pero no existe ningún barrido que ponga en otro estado las ofertas vencidas: siguen diciendo `sent` en el detalle del residente, en el archivo del proveedor y en los listados. La app tiene que comparar `valid_until` con la hora y desactivar el botón por su cuenta; el `409` es la última línea de defensa, no la primera. Un cliente que no lo haga le enseñará al residente una oferta aceptable que no lo es.

**5. La adjudicación no deja registro de a qué ofertas rechazó.**
`offers_rejected` es un contador y se va con la respuesta; después, una oferta rechazada por esta llamada y una rechazada por una cancelación anterior son indistinguibles. El día que exista el spec de des-adjudicar, no habrá forma de saber a cuáles devolver a `sent`. Se anota aquí para que ese spec lo decida a sabiendas: o guarda la lista, o asume que deshacer no restaura las perdedoras.

**6. Dos peticiones concurrentes del mismo residente sobre dos ofertas distintas pueden adjudicar las dos.**
No hay bloqueo de ningún tipo. Si dos `PUT` entran a la vez, las dos compuertas leen la solicitud todavía en `offered`, las dos pasan, y el resultado es dos ofertas en `selected`, dos transacciones en la línea de tiempo y un `field_assigned_offer` con la que escribiera la última — mientras la primera queda `selected` pero no asignada, y encima rechazada por el barrido de la segunda.
La ventana son milisegundos y hace falta un doble toque real para provocarla, así que no justifica meter `lock_acquire()` en este spec. Lo que sí justifica es que la app deshabilite el botón en cuanto se pulsa, y que quede escrito aquí para quien mire este endpoint cuando aparezca el primer caso raro en producción.
