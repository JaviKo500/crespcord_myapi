# 111 — Notificación al residente al retirar una oferta

> **Estado:** Approved · **Depende de:**
>   - `105-service-offer-update-withdraw` (Implemented) — dueña de `PUT /api/v1/service-offers/{id}/withdraw`, de `myapi_service_offer_withdraw()` en `resources/service_offer.resource.inc` y del punto exacto donde este spec engancha el disparo, justo tras el `node_save()` y antes del `200`.
>   - `110-service-offer-received-notification` (Implemented) — dueña de `includes/myapi.service_request_notification.inc` (que este spec amplía sin crear un include nuevo), de las constantes `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER` / `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST`, de `myapi_service_offer_amount_text()` y de `myapi_service_request_app_deep_link_url()`, todas reutilizadas sin cambios. Su "Fuera de este spec" marcó la notificación de "oferta actualizada/retirada" como la candidata directa siguiente; este spec cierra esa deuda para el retiro.
>   - `109-service-request-created-notifications` (Implemented) — dueña del patrón bandeja+push+email por evento y de la columna `provider_id` en `myapi_notifications`.
> **Fecha:** 2026-08-30
> **Objetivo:** Cuando un proveedor retira su oferta (`PUT /api/v1/service-offers/{id}/withdraw`), avisar al residente `field_requester` de la solicitud por bandeja + push + email — con `audience = "resident"`, título `"Oferta retirada"` y contexto (`provider_id`/`unit_id`/`condominium_id`) poblado — reutilizando el mismo include, patrón y variable de deep link que spec 110.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.service_request_notification.inc`** (modificar) — gana el dominio del retiro de oferta, junto a los de creación de solicitud (spec 109) y oferta recibida (spec 110):
  - Constante nueva: `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_WITHDRAWN` (`'service_offer_withdrawn'`) y `MYAPI_SERVICE_OFFER_WITHDRAWN_MAIL_KEY` (`'service_request_offer_withdrawn_resident'`). El resto de constantes que necesita (`MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST`) ya existen desde spec 110.
  - `myapi_service_request_notify_offer_withdrawn($offer_node, $context)` — la orquestación: recibe lo que `myapi_service_offer_withdraw()` ya tiene en mano (no vuelve a consultar nada), resuelve el nombre del residente vía `myapi_user_fetch_profile_fields()`, llama una vez a `myapi_notification_create()` con `uids = [$context['requester_uid']]` y encola el email. Best-effort de principio a fin, mismo criterio que las dos orquestadoras anteriores del archivo.
  - `myapi_service_offer_withdrawn_push_title()` / `myapi_service_offer_withdrawn_push_body($subject, $provider_name)` — constructores puros de texto. `myapi_service_offer_amount_text()` de spec 110 **no aplica aquí**: el monto no forma parte del contenido de este aviso (ver Decisiones).
  - `myapi_service_offer_withdrawn_resident_mail_params(...)` — params del email, sobre el mismo patrón que `myapi_service_offer_resident_mail_params()` de spec 110, reutilizando `myapi_service_request_app_deep_link_url()` sin cambios.
- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_format_service_request_offer_withdrawn_resident()` / `myapi_mail_service_request_offer_withdrawn_resident_html()`, sobre el mismo shell `myapi_mail_reservation_html()`, con botón `Ver solicitud` → `myapi_service_request_app_deep_link_url()`.
- **`myapi.module`** (modificar) — una rama nueva en `myapi_mail()`: `service_request_offer_withdrawn_resident`.
- **`myapi.install`** (modificar) — `myapi_html_mail_keys()` gana `myapi_service_request_offer_withdrawn_resident`, reaplicado desde `myapi_update_7038()`, idéntico en forma a `myapi_update_7037()` de spec 110. Sin `hook_schema()` nuevo.
- **`resources/service_offer.resource.inc`** (modificar) — `myapi_service_offer_withdraw()` llama a `myapi_service_request_notify_offer_withdrawn()` justo después del `node_save()` (paso 6) y antes de armar el `200` (paso 7). Ninguna otra función del archivo cambia.
- **`myapi.info`** (sin cambios) — el include ya está listado desde spec 109.
- **`docs/service-request-notifications.md`** (modificar) — se agrega el contrato del cuarto aviso (los dos de spec 109, el de spec 110, más este), mismo formato.
- **`docs/notification.md`** (modificar) — el `type` nuevo `service_offer_withdrawn`.
- **`docs/service-offer.md`** (modificar) — nota en el `PUT .../withdraw` de que dispara este aviso, con enlace a `docs/service-request-notifications.md`.
- **`tests/unit/ServiceRequestNotificationTest.php`** (modificar) — casos nuevos para el título, cuerpo y params del email, en la misma clase que ya cubre spec 109 y spec 110.

### Fuera de este spec

- **Avisar cuando se edita una oferta (`PUT /api/v1/service-offers/{id}` sin retirar).** Es un evento distinto, ya identificado como candidato para un spec propio; este spec cubre únicamente el retiro.
- **Avisar al proveedor que retiró.** Recibe el `200` con el objeto completo en el momento — mismo criterio que spec 109/110 con quien ejecuta la acción.
- **Avisar al rol `backend`.** El pedido dice "al solicitante"; mismo criterio que spec 110.
- **Cualquier otro evento del marketplace que hoy no notifica** (adjudicación, cancelación, cierre y valoración): ninguno empieza aquí.
- **Notificar retiros hechos fuera de la API** (back office, drush). El disparo vive en el endpoint, mismo criterio que specs 109 y 110.
- **Deep link a un detalle de oferta individual.** No existe ese endpoint (spec 100); el `deep_link.target` sigue siendo `service_request`.
- **Traducir los textos vía `myapi_t()`.** Fijos en español, mismo criterio que los triggers anteriores.
- **Envío síncrono de push o email.** Sale por cola en el siguiente cron.
- **Rate limiting** sobre retiros repetidos.
- **Backfill.** No aplica: no hay columna nueva ni retiros retroactivos que notificar.
- **Variable de deep link nueva.** Se reutiliza `myapi_service_request_deep_link_base`, ya creada en spec 110.

---

## Modelo de datos

Este spec no agrega tablas ni columnas: reutiliza el esquema y el envoltorio (`myapi_notification_create()`, `audience`, `provider_id`, `unit_id`/`condominium_id`, `myapi_service_request_app_deep_link_url()`) que dejaron listos spec 109 y spec 110. Solo agrega una constante de `type`, una clave de correo y las funciones puras de texto/params correspondientes.

### 1. Constantes nuevas en `includes/myapi.service_request_notification.inc`

| Constante | Valor | Nota |
|---|---|---|
| `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_WITHDRAWN` | `'service_offer_withdrawn'` | — |
| `MYAPI_SERVICE_OFFER_WITHDRAWN_MAIL_KEY` | `'service_request_offer_withdrawn_resident'` | Clave de `drupal_mail()` / `myapi_html_mail_keys()`. |

Reutilizadas sin cambio: `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST` (spec 110).

### 2. Qué recibe `myapi_service_request_notify_offer_withdrawn()`

`myapi_service_offer_withdraw()` ya tiene en mano `$row` (de `myapi_service_offer_detail_row()`) y `$request_row` (de `myapi_service_request_detail_row()`) al llegar al punto de enganche — nada se vuelve a consultar:

```php
myapi_service_request_notify_offer_withdrawn($offer, [
  'request_nid'    => $row->request_id,
  'request_title'  => $request_row->title,
  'requester_uid'  => $request_row->requester_uid,
  'condominium_id' => $request_row->condominium_id,
  'unit_id'        => $request_row->unit_id,
  'provider_id'    => $row->provider_id,
  'provider_name'  => $row->provider_name,
]);
```

El nombre del residente se resuelve **dentro** de la función nueva, vía `myapi_user_fetch_profile_fields($requester_uid)`, con el username como respaldo — mismo criterio que spec 110.

### 3. Fila de bandeja / push al residente

Una sola llamada a `myapi_notification_create()`:

| Clave | Valor |
|---|---|
| `source_type` | `service_offer` |
| `source_nid` | nid de la oferta retirada |
| `type` | `service_offer_withdrawn` |
| `title` | `Oferta retirada` |
| `body` | ver abajo |
| `deep_link_target` | `service_request` |
| `deep_link_id` | nid de la solicitud |
| `condominium_id` | `$request_row->condominium_id` |
| `unit_id` | `$request_row->unit_id` |
| `provider_id` | nid del proveedor que retiró |
| `audience` | `resident` |
| `uids` | `[$requester_uid]` |

**`title`** = `Oferta retirada`.
**`body`** = `"{asunto}\nProveedor: {proveedor}"`, donde `{asunto}` = `$request_row->title` y `{proveedor}` = `$provider_name`. Sin monto: la oferta ya no está vigente, mostrar la cifra retirada no aporta al residente lo que debe decidir ahora. Igual que en specs 109/110, un dato no resoluble se imprime como `—`.

`data` del push:

```json
{
  "target": "service_request",
  "id": 128,
  "unit": 44,
  "condominium": 87,
  "notification_type": "service_offer_withdrawn",
  "audience": "resident",
  "provider": 41
}
```

### 4. Email al residente — clave `service_request_offer_withdrawn_resident`

Un ítem de cola por `uid` (el `requester_uid`), sobre el shell `myapi_mail_reservation_html()`.

**Asunto:** `Oferta retirada — {asunto}`.

| Línea | Origen |
|---|---|
| Asunto | `$request_row->title` |
| Proveedor | `$provider_name` |

**Saludo:** `Hola {nombre}` si se resuelve nombre, si no `Hola` a secas — mismo criterio que spec 110.

**Botón:** `Ver solicitud` → `myapi_service_request_app_deep_link_url($request_nid)`, la misma función de spec 110, sin cambios.

**Cierre:** `Puedes ver el detalle completo desde el botón de abajo.`

No se agrega ninguna variable de configuración: `myapi_service_request_deep_link_base` ya existe desde spec 110 y este email la reutiliza tal cual.

---

## Plan de implementación

1. **Constante y clave de correo — `includes/myapi.service_request_notification.inc`.** Agregar `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_WITHDRAWN` y `MYAPI_SERVICE_OFFER_WITHDRAWN_MAIL_KEY`, junto a las de spec 109/110.
   *Verificación: `php -l`; `drush cc all` sin errores.*

2. **Constructores puros de texto — mismo archivo.** `myapi_service_offer_withdrawn_push_title()` y `myapi_service_offer_withdrawn_push_body($subject, $provider_name)`.
   *Verificación: cubierto por los tests unitarios del paso 5 — el `title` fijo y el `body` de dos líneas, incluido el caso `—` cuando `provider_name` es NULL.*

3. **El email — `includes/myapi.mail.inc` y `myapi.module`.** `myapi_service_offer_withdrawn_resident_mail_params(...)` (mismo archivo que el paso 2, reutilizando `myapi_service_request_app_deep_link_url()` sin cambios) y `myapi_mail_format_service_request_offer_withdrawn_resident()` / `myapi_mail_service_request_offer_withdrawn_resident_html()` sobre `myapi_mail_reservation_html()`, con botón `Ver solicitud`. Un `case` nuevo en `myapi_mail()`: `service_request_offer_withdrawn_resident`.
   *Verificación: `drupal_mail('myapi', 'service_request_offer_withdrawn_resident', ...)` en `drush php-eval` produce el asunto y el HTML esperados, con y sin nombre de residente resuelto.*

4. **Clave de correo — `myapi.install`.** `myapi_html_mail_keys()` gana `myapi_service_request_offer_withdrawn_resident`; `myapi_update_7038()` la reaplica sobre un sitio ya instalado, mismo patrón idempotente que `myapi_update_7037()`. Sin `hook_schema()`.
   *Verificación: `drush updb` corre limpio; una segunda pasada no encuentra nada pendiente.*

5. **La orquestación y sus tests — `myapi_service_request_notify_offer_withdrawn($offer_node, $context)` y `tests/unit/ServiceRequestNotificationTest.php`.** Resuelve el nombre del residente una sola vez, arma `title`/`body`/`data` y llama a `myapi_notification_create()` con `uids = [$context['requester_uid']]`; encola el email. Best-effort de principio a fin: no lanza, no revierte nada. Casos nuevos: título y cuerpo del push (incluido `provider_name` NULL), params y asunto del email, y que `uids` sea exactamente `[requester_uid]`.
   *Verificación: `./vendor/bin/phpunit` en verde, incluida toda la suite previa; invocada a mano sobre una oferta ya retirada, crea una fila de bandeja, un ítem en `myapi_onesignal_push` y uno en `myapi_mail_send` con el botón poblado.*

6. **Enganche en el endpoint — `resources/service_offer.resource.inc`.** Llamada a `myapi_service_request_notify_offer_withdrawn()` justo después del `node_save()` (paso 6 de `myapi_service_offer_withdraw()`) y antes de armar el `200` (paso 7), precedida de su `module_load_include()` (el include ya está en `myapi.info` desde spec 109). `drush cc all`.
   *Verificación: `PUT /api/v1/service-offers/{id}/withdraw` sigue devolviendo el mismo `200` de spec 105, con la fila y los ítems de cola ya creados.*

7. **Documentación.** `docs/service-request-notifications.md` gana el cuarto aviso; `docs/notification.md` el `type` nuevo; nota y enlace en `docs/service-offer.md`.
   *Verificación: `docs/notification.md` describe el `data` del push tal como lo emite el código.*

8. **Prueba manual de extremo a extremo.** `drush updb && drush cc all`. Retirar una oferta desde un proveedor sobre una solicitud `offered` → el residente ve la notificación en su bandeja con `deep_link.target = "service_request"` y `deep_link.provider` poblado, recibe el push con `"audience": "resident"` y, tras `drush cron`, el email con el botón que abre la app.

---

## Criterios de aceptación

**Disparo y destinatario**
- [ ] Retirar una oferta vía `PUT /api/v1/service-offers/{id}/withdraw` genera exactamente **una** fila en `myapi_notifications` con `uid = requester_uid` de la solicitud.
- [ ] Un segundo intento de retiro sobre la misma oferta responde `409 service_offer_not_withdrawable` (comportamiento ya fijado por spec 105) y **no** genera una segunda notificación.
- [ ] Ninguna otra cuenta (el proveedor que retiró, otros ocupantes de la vivienda, el rol `backend`) recibe fila, push ni email por este evento.

**Contenido de la fila / push**
- [ ] `source_type = "service_offer"`, `source_nid` = nid de la oferta retirada, `type = "service_offer_withdrawn"`.
- [ ] `deep_link_target = "service_request"`, `deep_link_id` = nid de la solicitud (no de la oferta).
- [ ] `condominium_id` y `unit_id` quedan poblados con los de la solicitud.
- [ ] `provider_id` = nid del proveedor que retiró.
- [ ] `title = "Oferta retirada"`; `body` con dos líneas: asunto y `Proveedor:`. Ningún monto aparece en el texto.
- [ ] El `data` del push lleva `"audience": "resident"` y `"provider"` con el nid del proveedor.
- [ ] Un `provider_name` no resoluble (proveedor eliminado o despublicado) imprime `—` en el `body` en vez de romper el armado.

**Email**
- [ ] Se encola un ítem en `myapi_mail_send`, clave `service_request_offer_withdrawn_resident`, a la dirección del residente.
- [ ] Asunto: `"Oferta retirada — {asunto de la solicitud}"`.
- [ ] Cuerpo con asunto y proveedor, sin monto.
- [ ] Saludo `"Hola {nombre}"` cuando el perfil del residente resuelve nombre; `"Hola"` a secas si no.
- [ ] El email lleva un botón `"Ver solicitud"` cuya URL es `{myapi_service_request_deep_link_base}/{nid de la solicitud}`.

**Esquema y compatibilidad**
- [ ] No se agrega ninguna columna ni tabla nueva; `drush updb` solo actualiza la clave de correo (`myapi_update_7038`) y una segunda ejecución no encuentra nada pendiente.
- [ ] Las notificaciones de spec 109 (creación de solicitud) y spec 110 (oferta recibida) siguen funcionando idénticas, sin ningún cambio de comportamiento.
- [ ] `GET /api/v1/notifications` devuelve la notificación nueva con `deep_link.target = "service_request"`, sin alterar ninguna clave existente de la respuesta.

**No regresión y robustez**
- [ ] El `200` de `PUT /api/v1/service-offers/{id}/withdraw` conserva la respuesta de spec 105 (`service_offer` + `request`), byte por byte.
- [ ] Un retiro hecho desde el back office (formulario de nodo, drush) **no** dispara ningún aviso — el disparo vive en el endpoint, no en un hook.
- [ ] Un fallo al encolar (cola caída, dirección inválida, `myapi_user_fetch_profile_fields()` sin resultado) queda en `watchdog` y no impide el `200` ni deshace el retiro.
- [ ] `./vendor/bin/phpunit` en verde, incluida toda la suite previa.
- [ ] `drush cc all` no reporta errores.

**Documentación**
- [ ] `docs/service-request-notifications.md` documenta el cuarto aviso completo.
- [ ] `docs/notification.md` documenta el `type` `service_offer_withdrawn`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Disparador | Solo `PUT .../withdraw` | También `PUT /api/v1/service-offers/{id}` (edición) | Elección del usuario. El título del pedido dice literalmente "Oferta retirada"; la edición sin retiro es un evento distinto y queda como candidato para un spec propio. |
| Título del aviso | `"Oferta retirada"` | `"Oferta actualizada"` (texto genérico) | Elección del usuario. Es más preciso: describe exactamente lo que pasó, mismo criterio directo que `"Nueva oferta recibida"` de spec 110. |
| Destinatario | Solo el residente (`field_requester`) | También el rol `backend` | Elección del usuario, mismo criterio que spec 110: el backend no recibe un segundo email por cada cambio de estado de una oferta sobre una solicitud que ya conoce desde spec 109. |
| Canales | Bandeja + push + email, en una sola llamada a `myapi_notification_create()` | Solo bandeja + push, sin email | Elección del usuario. Consistencia total con los nueve triggers existentes del módulo, incluido spec 110. |
| Contenido del aviso | Asunto de la solicitud + nombre del proveedor, sin monto | Asunto + proveedor + monto/tipo de precio | Elección del usuario. El monto de una oferta retirada ya no es información accionable para el residente; incluirlo podría confundir mostrando una cifra que ya no aplica. |
| Ubicación del código | Se amplía `includes/myapi.service_request_notification.inc` (specs 109/110) | Include nuevo `myapi.service_offer_withdrawn_notification.inc` | Mismo dominio de evento (`service_request`/`service_offer`), mismo include; evita duplicar el patrón de armar título/cuerpo/email por tercera vez. |
| Deep link | `target = "service_request"`, misma variable `myapi_service_request_deep_link_base` de spec 110 | Una variable o target propios de este aviso | No existe un `GET` de detalle de oferta individual (spec 100/103); reutilizar la variable de spec 110 evita una tercera variable de configuración para el mismo destino de navegación. |
| Punto de enganche | Dentro de `myapi_service_offer_withdraw()`, tras el `node_save()` | Un `hook_node_update()` genérico sobre `service_offer` | Mismo criterio que specs 109/110: el disparo vive en el endpoint, así que un retiro hecho desde el back office no genera ruido. |
| Idioma de los textos | Fijos en español, sin `myapi_t()` | Traducir vía catálogo i18n | Mismo criterio que los nueve triggers existentes: no hay `Accept-Language` disponible para un destinatario que no es quien hizo la petición. |
| Entrega | Push y email por cola, en el siguiente cron | Envío síncrono dentro del `PUT` | Mismo motivo que specs 109/110: no añadir latencia al `200` del retiro. |
| Backfill | Ninguno — no aplica | — | No hay columna nueva ni retiros retroactivos que notificar; solo se agrega una clave de correo al catálogo. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Volumen para el residente.** Una solicitud con varias ofertas retiradas y vueltas a enviar (permitido desde spec 105) produce un aviso por cada retiro, además de uno por cada oferta recibida (spec 110). | Es la misma decisión de "cada evento notifica" ya aceptada en spec 110. El residente puede desactivar notificaciones a nivel OS; agrupar en un resumen es un spec propio, ya descartado en specs anteriores de esta serie. |
| **El residente no distingue si la oferta retirada fue reemplazada por una nueva del mismo proveedor.** Este aviso no enlaza con la oferta nueva (si la hay); son dos filas independientes en la bandeja. | Aceptado: no hay un identificador que relacione ambos eventos hoy, y agregarlo requeriría una columna nueva fuera del alcance de este spec. El residente ve el estado real abriendo el `deep_link` a la solicitud. |
| **`myapi_user_fetch_profile_fields()` falla o el residente no tiene perfil completo.** | Cae al respaldo `"Hola"` sin nombre, mismo patrón que specs 109/110; nunca produce un error ni bloquea el resto del disparo. |
| **Un proveedor que se elimina o despublica después de retirar deja un `provider_id` que apunta a un nodo inexistente.** | Es una FK lógica, mismo criterio que `condominium_id`/`unit_id` desde spec 26 y `provider_id` desde spec 109/110. |
