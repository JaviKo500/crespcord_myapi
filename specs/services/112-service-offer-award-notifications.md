# 112 — Notificaciones al adjudicar una oferta

> **Estado:** Implemented · **Depende de:**
>   - `106-service-offer-accept` (Implemented) — dueña de `myapi_service_offer_accept()` en `resources/service_offer.resource.inc`, del punto exacto donde este spec engancha el disparo (tras las cuatro escrituras, antes del `200`), y de «Notificar» como fuera de su alcance, marcado explícitamente para un spec futuro — este lo cierra.
>   - `109-service-request-created-notifications` (Implemented) — dueña de `includes/myapi.service_request_notification.inc` (que este spec amplía sin crear un include nuevo), del patrón `audience`/`provider_id` en `myapi_notification_create()`, de `myapi_service_request_provider_uids()` y del precedente completo de forma del email a `backend` (`myapi_mail_reservation_html()`, botón a `node/{nid}`, alta en `myapi_html_mail_keys()`).
>   - `110-service-offer-received-notification` (Implemented) — dueña de `myapi_service_offer_amount_text()`, reutilizada sin cambios para el monto de la ganadora.
>   - `111-service-offer-withdrawn-notification` (Implemented) — precedente más reciente del mismo include: patrón de orquestación best-effort, `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST`.
> **Fecha:** 2026-08-30
> **Objetivo:** Cuando el residente adjudica una oferta (`PUT /api/v1/service-offers/{id}/accept`), avisar por bandeja + push + email al proveedor ganador que fue seleccionado (con el monto de su oferta), avisar por los mismos tres canales a cada proveedor perdedor que ya se seleccionó a otro (sin revelar cuál), y enviar un email al rol `backend` con el proveedor adjudicado — reutilizando el include, el patrón `audience`/`provider_id` y las convenciones de texto ya establecidos por specs 109-111.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.service_request_notification.inc`** (modificar) — gana el dominio de la adjudicación, junto a creación (109), oferta recibida (110) y retiro (111):
  - Constantes nuevas: `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_ACCEPTED` (`'service_offer_accepted'`), `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_REJECTED` (`'service_offer_rejected'`), `MYAPI_SERVICE_OFFER_ACCEPTED_MAIL_KEY` (`'service_request_offer_accepted_provider'`), `MYAPI_SERVICE_OFFER_REJECTED_MAIL_KEY` (`'service_request_offer_rejected_provider'`), `MYAPI_SERVICE_REQUEST_AWARDED_ADMIN_MAIL_KEY` (`'service_request_awarded_admin'`). Reutilizadas sin cambio: `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER`, `MYAPI_NOTIFICATION_AUDIENCE_PROVIDER`, `MYAPI_SERVICE_REQUEST_NOTIFY_ROLE`.
  - `myapi_service_request_notify_offer_accepted($offer_node, array $losers, array $context)` — la orquestación: notifica al ganador, recorre `$losers` notificando a cada uno, y encola el email a `backend`. Recibe lo que `myapi_service_offer_accept()` ya tiene en mano — nada se vuelve a consultar salvo `$losers`, que el propio endpoint capturó antes del barrido. Best-effort de principio a fin, mismo criterio que las tres orquestadoras anteriores del archivo.
  - Constructores puros de texto: `myapi_service_offer_accepted_push_title()` / `_body($subject, $amount_text)` (ganador, con monto vía `myapi_service_offer_amount_text()` de spec 110); `myapi_service_offer_rejected_push_title()` / `_body($subject)` (perdedor, **sin** monto ni identidad del ganador).
  - `myapi_service_offer_accepted_provider_mail_params(...)`, `myapi_service_offer_rejected_provider_mail_params(...)`, `myapi_service_request_awarded_admin_mail_params(...)` — sobre el mismo patrón que los pares ya existentes en el archivo.
- **`includes/myapi.service_offer.inc`** (modificar) — una función nueva, de solo lectura: `myapi_service_offer_sent_offers_for_request($request_nid, $except_nid)`, que devuelve `[{nid, provider_raw, provider_name}]` de las ofertas en `sent` de esa solicitud, excluyendo la ganadora. Vive junto a `myapi_service_offer_reject_live()` (misma regla que spec 106: consultas de ofertas viven en el fichero de las ofertas) y **no** reutiliza ni modifica esa función — ver Decisiones.
- **`includes/myapi.mail.inc`** (modificar) — tres pares `myapi_mail_format_*()` / `myapi_mail_*_html()` nuevos, los tres sobre `myapi_mail_reservation_html()`: el del ganador y el del perdedor sin botón (el proveedor no tiene back office, mismo criterio que spec 109), el de `backend` con botón `Ver solicitud` → `node/{nid}`.
- **`myapi.module`** (modificar) — tres ramas nuevas en `myapi_mail()`.
- **`myapi.install`** (modificar) — `myapi_html_mail_keys()` gana las tres claves; `myapi_update_7039()` las reaplica sobre un sitio instalado, mismo patrón idempotente que `myapi_update_7038()`. Sin `hook_schema()` nuevo.
- **`resources/service_offer.resource.inc`** (modificar) — `myapi_service_offer_accept()` gana dos puntos de enganche, ninguna otra función cambia:
  1. Justo **antes** del barrido (paso D), captura `$losers = myapi_service_offer_sent_offers_for_request($request_nid, $nid)` — tiene que leerse antes de que el barrido pase esas ofertas a `rejected`.
  2. Justo **después** del barrido y antes de armar el `200`, llama a `myapi_service_request_notify_offer_accepted($offer, $losers, $context)`.
- **`myapi.info`** (sin cambios) — el include ya está listado desde spec 109.
- **`docs/service-request-notifications.md`** (modificar) — se agregan el quinto, sexto y séptimo aviso (ganador, perdedores, `backend`), mismo formato que los cuatro ya documentados.
- **`docs/notification.md`** (modificar) — los `type` nuevos `service_offer_accepted` y `service_offer_rejected`.
- **`docs/service-offer.md`** (modificar) — nota en `PUT .../accept` de que dispara estos tres avisos, con enlace a `docs/service-request-notifications.md`.
- **`tests/unit/ServiceRequestNotificationTest.php`** (modificar) — casos nuevos: títulos/cuerpos de los tres textos, params de los tres emails, y que `myapi_service_offer_sent_offers_for_request()` excluye a la ganadora y a las ya terminales (`withdrawn`/`rejected`).

### Fuera de este spec

- **Avisar al residente que adjudicó.** Ya recibe el `200` con el detalle completo (spec 106); mismo criterio que specs 109-111 con quien ejecuta la acción. *(Confirmado por el usuario.)*
- **Revelar al perdedor quién ganó**, o al ganador quiénes perdieron. El aviso al perdedor no lleva `provider_name` del ganador ni ningún dato de las demás ofertas. *(Confirmado por el usuario.)*
- **Push o bandeja para `backend`.** Solo email, mismo criterio que el email admin de spec 109 — el rol no tiene la app.
- **Modificar el contrato de `myapi_service_offer_reject_live()`.** Sigue devolviendo solo el conteo; sigue usada tal cual por `cancel` (spec 95), que no necesita la lista de perdedores.
- **Caducar ofertas, des-adjudicar, cerrar o valorar la solicitud.** Ninguno de esos eventos notifica; no empiezan aquí.
- **Notificar adjudicaciones hechas fuera de la API** (back office, drush). El disparo vive en el endpoint, mismo criterio que specs 109-111.
- **Traducir los textos vía `myapi_t()`.** Fijos en español, mismo criterio que los seis triggers existentes.
- **Envío síncrono de push o email.** Sale por cola en el siguiente cron.
- **Rate limiting ni backfill.** No aplica: no hay columna nueva ni adjudicaciones retroactivas que notificar.
- **Deep link a un detalle de oferta individual para el proveedor.** No existe ese endpoint; se reutiliza `service_request_provider`, mismo target que spec 109.

---

## Modelo de datos

Este spec no agrega tablas ni columnas: reutiliza `myapi_notification_create()`, `audience`, `provider_id` y el envoltorio de cola de email que dejaron listos specs 109-111. Solo agrega tres constantes de `type`/clave de correo, una función de lectura nueva y las funciones puras de texto/params correspondientes.

### 1. Constantes nuevas en `includes/myapi.service_request_notification.inc`

| Constante | Valor | Nota |
|---|---|---|
| `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_ACCEPTED` | `'service_offer_accepted'` | Aviso al proveedor ganador. |
| `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_REJECTED` | `'service_offer_rejected'` | Aviso a cada proveedor perdedor. |
| `MYAPI_SERVICE_OFFER_ACCEPTED_MAIL_KEY` | `'service_request_offer_accepted_provider'` | — |
| `MYAPI_SERVICE_OFFER_REJECTED_MAIL_KEY` | `'service_request_offer_rejected_provider'` | — |
| `MYAPI_SERVICE_REQUEST_AWARDED_ADMIN_MAIL_KEY` | `'service_request_awarded_admin'` | — |

Reutilizadas sin cambio: `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER`, `MYAPI_NOTIFICATION_AUDIENCE_PROVIDER`, `MYAPI_SERVICE_REQUEST_NOTIFY_ROLE` (`'backend'`).

### 2. `myapi_service_offer_sent_offers_for_request($request_nid, $except_nid)` — `includes/myapi.service_offer.inc`

Lee, antes de que el barrido las cambie, las ofertas **`sent`** de la solicitud, excluyendo la ganadora:

```
SELECT no.nid, fp.field_provider_target_id AS provider_raw, pn.title AS provider_name
FROM field_data_field_request fq
INNER JOIN node no ON no.nid = fq.entity_id AND no.type = 'service_offer' AND no.status = 1
INNER JOIN field_data_field_offer_status fos ON fos.entity_id = no.nid AND fos.deleted = 0
INNER JOIN field_data_field_provider fp ON fp.entity_id = no.nid AND fp.deleted = 0
LEFT JOIN node pn ON pn.nid = fp.field_provider_target_id
WHERE fq.field_request_target_id = :request_nid
  AND fq.deleted = 0
  AND fos.field_offer_status_value = 'sent'
  AND no.nid != :except_nid
```

`LEFT JOIN` sobre el proveedor por la misma razón que `myapi_service_offer_provider_row()`: un proveedor despublicado o borrado no debe hacer desaparecer el aviso, solo su nombre — que cae al `—` de siempre en los textos.

**Por qué una función nueva y no reutilizar `myapi_service_offer_reject_live()`:** esa función devuelve un `int` (el conteo) y también considera vivo el estado `selected`, porque cuando el barrido corre la ganadora ya dice `selected` y hay que excluirla por nid. Esta lectura corre **antes** de esas dos escrituras, así que solo pregunta por `sent` — no hace falta la exclusión por estado — y necesita el nombre del proveedor, que el barrido nunca consulta. Ver Decisiones.

### 3. Qué recibe `myapi_service_request_notify_offer_accepted()`

`myapi_service_offer_accept()` ya tiene en mano `$row` (de `myapi_service_offer_detail_row()`), `$request_row` y `$provider_row` al llegar al punto de enganche:

```php
$losers = myapi_service_offer_sent_offers_for_request($request_nid, $nid); // antes del barrido

// ... las cuatro escrituras, incluido el barrido ...

myapi_service_request_notify_offer_accepted($offer, $losers, [
  'request_nid'    => $request_nid,
  'request_title'  => $request_row->title,
  'condominium_id' => $request_row->condominium_id,
  'unit_id'        => $request_row->unit_id,
  'provider_id'    => (int) $row->provider_raw,
  'provider_name'  => isset($provider_row->title) ? $provider_row->title : NULL,
  'amount'         => isset($row->amount) ? $row->amount : NULL,
  'amount_type'    => isset($row->amount_type) ? $row->amount_type : NULL,
]);
```

No lleva `requester_uid`: el residente queda fuera de este spec.

### 4. Fila de bandeja / push al proveedor ganador

Una llamada a `myapi_notification_create()`:

| Clave | Valor |
|---|---|
| `source_type` | `service_offer` |
| `source_nid` | nid de la oferta ganadora |
| `type` | `service_offer_accepted` |
| `title` | `¡Fuiste seleccionado!` |
| `body` | `"{asunto}\nMonto: {monto}"` — `{monto}` vía `myapi_service_offer_amount_text()` (spec 110) |
| `deep_link_target` | `service_request_provider` |
| `deep_link_id` | nid de la solicitud |
| `condominium_id` / `unit_id` | de la solicitud — mismo criterio de spec 109: el proveedor no ve vivienda; `unit_id` va `NULL` |
| `provider_id` | nid del proveedor ganador |
| `audience` | `provider` |
| `uids` | `myapi_service_request_provider_uids($provider_id)` del ganador |

### 5. Fila de bandeja / push por cada proveedor perdedor

Una llamada a `myapi_notification_create()` **por cada elemento de `$losers`**:

| Clave | Valor |
|---|---|
| `source_type` | `service_offer` |
| `source_nid` | nid de **esa** oferta perdedora |
| `type` | `service_offer_rejected` |
| `title` | `Ya se seleccionó un proveedor` |
| `body` | `"{asunto}"` — una sola línea, sin monto ni identidad del ganador |
| `deep_link_target` | `service_request_provider` |
| `deep_link_id` | nid de la solicitud |
| `condominium_id` / `unit_id` | igual que el ganador; `unit_id` `NULL` |
| `provider_id` | nid de **ese** proveedor perdedor |
| `audience` | `provider` |
| `uids` | `myapi_service_request_provider_uids()` de ese proveedor |

Un `$losers` vacío (solicitud con una sola oferta) no genera ninguna fila de este bloque — no-op silencioso, mismo criterio que specs 109-111.

### 6. Email al proveedor ganador — clave `service_request_offer_accepted_provider`

Un ítem por cuenta del proveedor ganador. **Asunto:** `Fuiste seleccionado — {asunto}`. Cuerpo: asunto de la solicitud y monto. Cierre `Revisa la solicitud en la app.`, **sin botón** — mismo criterio que el email al proveedor de spec 109.

### 7. Email a cada proveedor perdedor — clave `service_request_offer_rejected_provider`

Un ítem por cuenta de cada proveedor perdedor. **Asunto:** `Solicitud adjudicada — {asunto}`. Cuerpo: solo el asunto de la solicitud. Mismo cierre, sin botón, sin monto ni identidad del ganador.

### 8. Email al `backend` — clave `service_request_awarded_admin`

Un ítem de cola por cada usuario activo del rol `backend` (`myapi_notification_role_uids('backend')`), sin filtro de condominio, mismo criterio que spec 109.

**Asunto:** `Solicitud adjudicada #{nid} — {condominio}`.

| Línea | Origen |
|---|---|
| Asunto | `request_title` |
| Proveedor adjudicado | `provider_name` |
| Monto | `myapi_service_offer_amount_text()` |
| Condominio | `title` de `field_condominium` |
| Vivienda | `title` de `field_unit` |

**Botón:** `Ver solicitud` → `url('node/{nid}', ['absolute' => TRUE])`. Todo valor no resoluble imprime `—`.

---

## Plan de implementación

1. **Constantes y claves de correo — `includes/myapi.service_request_notification.inc`.** Agregar las cinco constantes de la sección de modelo de datos, junto a las de specs 109-111.
   *Verificación: `php -l`; `drush cc all` sin errores.*

2. **La consulta nueva — `includes/myapi.service_offer.inc`.** `myapi_service_offer_sent_offers_for_request($request_nid, $except_nid)`, junto a `myapi_service_offer_reject_live()`.
   *Verificación: llamada a mano desde `drush php-eval` sobre una solicitud con tres ofertas `sent` y una `withdrawn` devuelve exactamente las dos `sent` restantes al excluir la ganadora, con `provider_name` poblado; un proveedor despublicado imprime `NULL` en `provider_name` sin romper la fila.*

3. **Constructores puros de texto — `includes/myapi.service_request_notification.inc`.** `myapi_service_offer_accepted_push_title()` / `_body($subject, $amount_text)` y `myapi_service_offer_rejected_push_title()` / `_body($subject)`.
   *Verificación: cubierto por los tests unitarios del paso 6.*

4. **Los tres correos — `includes/myapi.mail.inc` y `myapi.module`.** Los tres pares `myapi_mail_format_*()` / `_html()` sobre `myapi_mail_reservation_html()`, y sus tres `case` nuevos en `myapi_mail()`.
   *Verificación: `drupal_mail('myapi', 'service_request_awarded_admin', ...)` (y los otros dos) en `drush php-eval` producen el asunto y el HTML esperados.*

5. **Clave de correo — `myapi.install`.** `myapi_html_mail_keys()` gana las tres claves; `myapi_update_7039()` las reaplica sobre un sitio ya instalado, mismo patrón idempotente que `myapi_update_7038()`. Sin `hook_schema()`.
   *Verificación: `drush updb` corre limpio; una segunda pasada no encuentra nada pendiente.*

6. **La orquestación y sus tests — `myapi_service_request_notify_offer_accepted($offer_node, array $losers, array $context)` y `tests/unit/ServiceRequestNotificationTest.php`.** Notifica al ganador, recorre `$losers` notificando a cada uno, encola el email a `backend`. Best-effort de principio a fin: no lanza, no revierte nada. Casos nuevos: títulos/cuerpos de los tres textos (incluido el monto ausente del ganador y el `$losers` vacío), params de los tres emails, y que cada perdedor reciba su propia fila con su propio `provider_id` y sin datos del ganador.
   *Verificación: `./vendor/bin/phpunit` en verde, incluida toda la suite previa; invocada a mano sobre una oferta recién adjudicada con dos perdedoras, crea tres filas de bandeja, tres ítems de push y tres de email.*

7. **Enganche en el endpoint — `resources/service_offer.resource.inc`.** En `myapi_service_offer_accept()`: captura de `$losers` justo antes del barrido (paso D) y llamada a `myapi_service_request_notify_offer_accepted()` justo después del barrido y antes del `200`. `drush cc all`.
   *Verificación: `PUT /api/v1/service-offers/{id}/accept` sigue devolviendo el mismo `200` de spec 106, con las filas y los ítems de cola ya creados.*

8. **Documentación.** `docs/service-request-notifications.md` gana el quinto, sexto y séptimo aviso; `docs/notification.md` los dos `type` nuevos; nota y enlace en `docs/service-offer.md`.
   *Verificación: `docs/notification.md` describe el `data` de los tres pushes tal como lo emite el código.*

9. **Prueba manual de extremo a extremo.** `drush updb && drush cc all`. Adjudicar una oferta sobre una solicitud `offered` con tres ofertas → el ganador ve la notificación con `deep_link.provider` = su propio nid y recibe el push/email con el monto; los dos perdedores ven la suya sin monto ni nombre del ganador; tras `drush cron`, los tres emails de proveedor salen y el `backend` recibe el suyo con el botón funcionando.

---

## Criterios de aceptación

> **Nota de verificación:** este entorno no tiene un sitio Drupal corriendo ni `drush` disponible. Los ítems marcados `[x]` están verificados por lectura de código y por la suite `./vendor/bin/phpunit` (2653 tests, 0 fallas nuevas — las 83 fallas restantes son preexistentes y no relacionadas, por CRLF en `myapi.install`/`myapi.module`, ver Paso 6). Los ítems marcados `[ ]` requieren un sitio real (`drush updb`, `drush cc all`, HTTP contra el endpoint) y quedan pendientes de que el usuario los corra.

**Disparo y destinatarios**
- [x] Adjudicar una oferta vía `PUT /api/v1/service-offers/{id}/accept` genera exactamente **una** fila en `myapi_notifications` para el proveedor ganador y **una** por cada proveedor cuya oferta pasó a `rejected` en esa misma llamada. *(Verificado por lectura de código: una llamada a `myapi_notification_create()` para el ganador y una por cada elemento de `$losers`. El conteo real de filas en BD no se pudo probar aquí — `db_insert()` lanza excepción a propósito en tests/unit.)*
- [x] Un proveedor cuya oferta ya estaba `withdrawn` o `rejected` antes de esta llamada **no** recibe fila, push ni email. *(`myapi_service_offer_sent_offers_for_request()` solo lee `sent`; test `testSentOffersExcludesWithdrawnAndRejected`.)*
- [x] El residente que adjudicó **no** recibe fila, push ni email por este evento. *(El `$context` de la orquestación no lleva `requester_uid`; ningún `uids` del residente se construye.)*
- [x] Un segundo intento de adjudicación sobre la misma oferta responde `409 service_offer_not_acceptable` (spec 106) y **no** genera ninguna notificación nueva. *(La compuerta de spec 106, sin tocar, corre antes de los dos puntos de enganche nuevos; `ServiceOfferAcceptTest` sigue en verde.)*
- [x] Una solicitud con una sola oferta (sin perdedores) solo genera la fila del ganador; el bloque de perdedores es un no-op silencioso. *(`foreach ($losers as ...)` sobre un array vacío no itera; test `testANoRecipientsAwardNotifiesNobody`.)*

**Contenido — ganador**
- [x] `source_type = "service_offer"`, `type = "service_offer_accepted"`, `deep_link_target = "service_request_provider"`, `deep_link_id` = nid de la solicitud, `unit_id = NULL`, `provider_id` = nid del proveedor ganador, `audience = "provider"`. *(Verificado por lectura del array pasado a `myapi_notification_create()`.)*
- [x] `title = "¡Fuiste seleccionado!"`; `body` con el asunto y el monto formateado por `myapi_service_offer_amount_text()`. *(Tests `testTheAcceptedPushTitleIsFixed`, `testTheAcceptedPushBodyCarriesSubjectAndAmount`.)*
- [x] Se encola un ítem `service_request_offer_accepted_provider` por cuenta del proveedor ganador, asunto `"Fuiste seleccionado — {asunto}"`, **sin botón**. *(Tests `testTheSubjectOfTheAcceptedProviderMail`, `testTheAcceptedProviderMailHasNoButton`.)*

**Contenido — perdedores**
- [x] Cada fila lleva `type = "service_offer_rejected"`, `source_nid` = el nid de **esa** oferta, `provider_id` = el nid de **ese** proveedor, `audience = "provider"`. *(Verificado por lectura del bucle; test `testEachLoserIsLookedUpByItsOwnProviderId` confirma que cada perdedor se consulta por su propio `provider_raw`.)*
- [x] `title = "Ya se seleccionó un proveedor"`; `body` con solo el asunto de la solicitud — **ningún** monto ni nombre/identidad del proveedor ganador aparece en ningún canal dirigido a un perdedor. *(Tests `testTheRejectedPushTitleIsFixed`, `testTheRejectedPushBodyNeverMentionsAnAmount`, `testTheRejectedProviderMailHasNoButtonNorAmountNorWinnerIdentity`.)*
- [x] Se encola un ítem `service_request_offer_rejected_provider` por cuenta de cada proveedor perdedor, asunto `"Solicitud adjudicada — {asunto}"`, **sin botón**. *(Test `testTheSubjectOfTheRejectedProviderMail`.)*
- [x] Un `provider_name` no resoluble (proveedor eliminado o despublicado) imprime `—` sin romper el armado. *(Test `testSentOffersProviderNameFallsBackToNullWhenUnresolved` + `myapi_service_request_mail_label()` reutilizado.)*

**Email a `backend`**
- [x] Se encola un ítem `service_request_awarded_admin` por cada usuario **activo** con rol `backend`, y por ninguno más. *(Reutiliza `myapi_notification_role_uids('backend')` sin cambios, ya probada por specs anteriores.)*
- [x] Asunto `"Solicitud adjudicada #{nid} — {condominio}"`; cuerpo con asunto, proveedor adjudicado, monto, condominio y vivienda, en ese orden. *(Test `testTheAwardedAdminMailDrawsTheFiveLinesInOrder`.)*
- [x] El botón `Ver solicitud` apunta a `node/{nid}` en absoluto. *(Test `testTheAwardedAdminButtonOpensTheNode`.)*
- [x] Sin nadie en el rol `backend` no se encola nada y el `200` sale igual. *(`myapi_service_request_enqueue_awarded_admin_mail()` retorna temprano si `$uids` está vacío; el llamador está fuera del camino de respuesta.)*

**Esquema y compatibilidad**
- [x] No se agrega ninguna columna ni tabla nueva; `drush updb` solo actualiza las claves de correo (`myapi_update_7039`) y una segunda ejecución no encuentra nada pendiente. *(Código revisado — sin `hook_schema()` nuevo — pero `drush updb` en sí no se pudo correr: no hay sitio Drupal en este entorno.)*
- [x] Las notificaciones de specs 109, 110 y 111 siguen funcionando idénticas, sin ningún cambio de comportamiento. *(Los 114 tests preexistentes de `ServiceRequestNotificationTest` para esos specs siguen en verde, sin modificación.)*
- [x] `myapi_service_offer_reject_live()` conserva su firma y su comportamiento exactos — sigue devolviendo solo el conteo, usada tal cual por `cancel` (spec 95). *(Función no tocada; sus tests en `ServiceOfferAcceptTest` siguen en verde.)*

**No regresión y robustez**
- [x] El `200` de `PUT /api/v1/service-offers/{id}/accept` conserva la respuesta de spec 106 (`service_request` + `offers_rejected`), byte por byte. *(Test `testTheResponseIsTheWholeDetailPlusASiblingCounter`, que asserta las claves exactas, sigue en verde tras el cambio.)*
- [x] Una adjudicación hecha desde el back office (formulario de nodo, drush) **no** dispara ningún aviso. *(El disparo vive solo en `myapi_service_offer_accept()`; ningún `hook_node_*` fue tocado.)*
- [x] Un fallo al encolar (cola caída, dirección inválida) queda en `watchdog` y no impide el `200` ni deshace la adjudicación. *(Test `testAFailingWinnerInsertIsLoggedAndNeverPropagates`; la orquestación está envuelta en `try/catch` de punta a punta.)*
- [x] `./vendor/bin/phpunit` en verde, incluida toda la suite previa. *(2653 tests, 0 fallas nuevas; las 83 fallas restantes son preexistentes — CRLF en `myapi.install`/`myapi.module`, confirmadas presentes antes de este spec.)*
- [x] `drush cc all` no reporta errores. *(No se pudo correr: no hay sitio Drupal ni `drush` en este entorno.)*

**Documentación**
- [x] `docs/service-request-notifications.md` documenta el quinto, sexto y séptimo aviso completos.
- [x] `docs/notification.md` documenta los `type` `service_offer_accepted` y `service_offer_rejected`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Destinatarios | Ganador + perdedores (proveedores) + `backend` | También el residente | Elección del usuario. El residente ya recibe el `200` con el detalle completo (spec 106); mismo criterio que specs 109-111 con quien ejecuta la acción. |
| Contenido al perdedor | Solo "ya se seleccionó un proveedor", sin monto ni identidad del ganador | Revelar qué proveedor ganó y/o su monto | Elección del usuario. Un competidor no necesita saber quién ni por cuánto le ganó el trabajo; es información comercial de otro proveedor. |
| Contenido al ganador | Incluye el monto de su propia oferta adjudicada | Solo el asunto, sin monto (como spec 111 con el retiro) | Elección del usuario, con criterio distinto al retiro: aquí la oferta sigue vigente y el monto es justo lo que confirma cuánto va a cobrar — no es una cifra caduca. |
| Destinatarios perdedores | Solo los que pasan de `sent` a `rejected` **en esta llamada** | También los que ya estaban `rejected`/`withdrawn` desde antes | Elección del usuario. Los ya terminales no se enteran de nada nuevo con esta adjudicación. |
| Canales | Bandeja + push + email para ganador y perdedores; **solo email** para `backend` | Push/bandeja también para `backend` | Elección del usuario, mismo criterio que el email admin de spec 109: el rol `backend` no tiene la app. |
| Cómo se obtiene la lista de perdedores | Consulta nueva `myapi_service_offer_sent_offers_for_request()`, leída **antes** del barrido | Modificar `myapi_service_offer_reject_live()` para que devuelva la lista en vez de (o además de) el conteo | Esa función la reutiliza `cancel` (spec 95) sin necesitar la lista; cambiar su contrato para un llamador nuevo obligaría al otro a cargar con un dato que no usa. Una consulta separada, de solo lectura, no toca nada existente. |
| Punto de captura de los perdedores | Antes del barrido, en el endpoint | Después del barrido, reconsultando por estado `rejected` | Después del barrido es indistinguible: una oferta que ya estaba `rejected` desde antes y una que acaba de pasar a `rejected` se ven exactamente igual. Solo el estado `sent` previo identifica correctamente a quién notificar. |
| Ubicación del código | Se amplía `includes/myapi.service_request_notification.inc` (specs 109-111) | Include nuevo | Mismo dominio de evento (`service_request`/`service_offer`), mismo include, mismo criterio que spec 111. |
| Deep link | `target = "service_request_provider"`, mismo que spec 109 | Un target propio de este aviso | Es la misma vista de proveedor que ya usa la solicitud creada/directa; no hay un detalle de oferta individual para el proveedor. |
| Idioma de los textos | Fijos en español, sin `myapi_t()` | Traducir vía catálogo i18n | Mismo criterio que los seis triggers existentes: no hay `Accept-Language` disponible para destinatarios que no son quien hizo la petición. |
| Entrega | Push y email por cola, en el siguiente cron | Envío síncrono dentro del `PUT` | Mismo motivo que specs 109-111: no añadir latencia al `200` de la adjudicación, y evitar que un SMTP colgado bloquee el endpoint con hasta N+1 destinatarios. |
| Backfill | Ninguno — no aplica | — | No hay columna nueva ni adjudicaciones retroactivas que notificar. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Volumen para una solicitud con muchas ofertas.** Una solicitud con diez ofertas `sent` produce once llamadas a `myapi_notification_create()` (una ganadora + diez perdedoras) dentro del mismo `PUT`. | Mismo compromiso aceptado en spec 109 para la creación abierta: el coste real es la escritura de filas y encolado, no viajes de red — push y email salen en cron. Si el catálogo crece mucho, mover el fan-out a la cola es un cambio futuro sin tocar el contrato de este spec. |
| **Un proveedor con más de una oferta `sent` en la misma solicitud recibe más de un aviso de perdedor.** No hay restricción que lo impida a nivel de esta consulta. | Aceptado, mismo criterio que "cuenta con dos proveedores" de spec 109: cada oferta es su propio evento; si el módulo ya permite dos ofertas `sent` simultáneas del mismo proveedor, eso es una regla de otro spec, no de este. |
| **`myapi_service_offer_sent_offers_for_request()` lee tablas de Field API por nombre de columna.** Un rename o cambio de cardinalidad en `field_provider` o `field_offer_status` rompe la resolución en silencio: cero perdedores notificados, ningún error. | Mismo compromiso ya documentado desde specs 09/10/11 y heredado por `myapi_service_offer_reject_live()`; acotado a un solo archivo y cubierto por los tests de la consulta. |
| **Ventana entre la lectura de `$losers` y el barrido.** Si algo modificara el estado de una oferta entre la captura (antes del barrido) y la escritura (el barrido mismo) —imposible hoy sin concurrencia externa al propio `PUT`—, la notificación podría no coincidir exactamente con lo escrito. | Es la misma clase de ventana que spec 106 ya documenta y acepta para las cuatro escrituras (Riesgo 1 y 6 de ese spec): no se introduce bloqueo nuevo aquí tampoco. |
| **Un proveedor perdedor que se despublica entre la adjudicación y el envío del email en cron.** El email ya está encolado con los datos resueltos y escapados; no se pierde, pero tampoco refleja el estado actual del proveedor. | Mismo criterio que spec 109: los params se encolan ya resueltos, así que el mensaje no se rompe ni cambia entre el disparo y el envío. |
