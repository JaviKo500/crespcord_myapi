# 110 — Notificación al residente al recibir una oferta

- **Estado:** Approved
- **Fecha:** 2026-08-30
- **Dependencias:**
  - `100-service-offer-create` (Implemented) — dueña de `myapi_service_offer_create()` en `resources/service_offer.resource.inc`, el punto exacto donde se engancha el disparo, y del objeto oferta (15 claves) del que este spec toma proveedor, monto y tipo de precio. Su decisión 15 dejó esto explícitamente fuera; este spec cierra esa deuda.
  - `109-service-request-created-notifications` (Implemented) — dueña de `includes/myapi.service_request_notification.inc` (que este spec amplía sin crear un include nuevo), de la columna `provider_id` en `myapi_notifications`, de la clave `audience` en el `data` del push, y del patrón bandeja+push+email por evento del dominio `service_request` que este spec reutiliza sin ningún cambio de esquema.
  - `25-notifications-inbox-boletin` (Implemented) — tabla `myapi_notifications`, `myapi_notification_create()`, endpoints de `resources/notification.resource.inc`.
  - `26-notification-condominium-unit-context` (Implemented) — columnas `condominium_id`/`unit_id`, aquí pobladas con el contexto de la propia solicitud del residente.
  - `07-password-reset` (Implemented) — dueña del patrón de deep link a la app vía variable de Drupal (`myapi_password_reset_deep_link_base`, esquema `myapp://`), que este spec replica con una variable propia para el botón del email.
- **Objetivo:** Cuando un proveedor crea una oferta sobre una solicitud (`POST /api/v1/service-requests/{id}/offers`), avisar al residente `field_requester` de esa solicitud por bandeja + push + email — uno por cada oferta recibida, sin importar si es la primera o una repetida — con `audience = "resident"`, `provider_id`/`unit_id`/`condominium_id` de contexto poblados en la fila, y un botón en el email que abre la solicitud directamente en la app.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.service_request_notification.inc`** (modificar) — gana el dominio del disparo de oferta, junto al de creación de solicitud que ya tenía:
  - Constantes: `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER` (`'service_offer'`), `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_RECEIVED` (`'service_offer_received'`), `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST` (`'service_request'`), `MYAPI_SERVICE_OFFER_RECEIVED_MAIL_KEY` (`'service_request_offer_resident'`).
  - `myapi_service_request_notify_offer_received($offer_node, $context)` — la orquestación: resuelve los datos ya disponibles en `myapi_service_offer_create()` (no vuelve a consultar nada que el endpoint ya tenga en mano), resuelve el nombre del residente vía `myapi_user_fetch_profile_fields()`, llama una vez a `myapi_notification_create()` con `uids = [$context['requester_uid']]`, y encola el email al residente. Best-effort de principio a fin, igual que el resto del include.
  - Constructores puros de texto: `myapi_service_offer_amount_text($amount, $amount_type)`, `myapi_service_offer_push_title()`, `myapi_service_offer_push_body($subject, $provider_name, $amount_text)`, `myapi_service_offer_resident_mail_params(...)`.
  - `myapi_service_request_app_deep_link_url($request_nid)` — función pura nueva, reutilizable por cualquier email futuro de este dominio dirigido al residente: lee `variable_get('myapi_service_request_deep_link_base', 'myapp://service-requests')` y devuelve `check_plain($base . '/' . $request_nid)`.
- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_format_service_request_offer_resident()` / `myapi_mail_service_request_offer_resident_html()`, sobre el mismo shell `myapi_mail_reservation_html()` que ya usan los correos de spec 109, **con** botón `Ver solicitud` → `myapi_service_request_app_deep_link_url()`.
- **`myapi.module`** (modificar) — una rama nueva en `myapi_mail()`: `service_request_offer_resident`.
- **`myapi.install`** (modificar) — `myapi_html_mail_keys()` gana `myapi_service_request_offer_resident`, reaplicado desde un `myapi_update_7037()` idéntico en forma al `myapi_update_7036()` de spec 109 (sin `hook_schema()` nuevo: no hay columna que agregar). Sin este update, un sitio ya instalado no vería la clave de correo hasta un `drush updb`.
- **`resources/service_offer.resource.inc`** (modificar) — `myapi_service_offer_create()` llama a `myapi_service_request_notify_offer_received()` después de las tres escrituras (oferta, transición si aplica, transacción si aplica) y antes de construir el `201`. Ninguna otra función del archivo cambia.
- **`myapi.info`** (sin cambios) — el include ya está listado desde spec 109.
- **`docs/service-request-notifications.md`** (modificar) — se agrega el contrato del tercer aviso (los dos de spec 109 más este), mismo formato.
- **`docs/notification.md`** (modificar) — el `type` nuevo `service_offer_received`.
- **`docs/service-offer.md`** (modificar) — nota en el `POST` de que dispara este aviso, con enlace al doc anterior, y la tabla de la nueva variable `myapi_service_request_deep_link_base` (mismo estilo que la tabla de variables de spec 07).
- **`tests/unit/ServiceRequestNotificationTest.php`** (modificar) — se agregan casos para los textos y params del email nuevo, la función de monto y la URL del deep link, en la misma clase que ya cubre los de spec 109.

### Fuera de este spec

- **Avisar al proveedor de que su oferta fue enviada.** Acaba de crearla y recibe el `201` con el objeto completo — mismo criterio que spec 109 con el residente que crea la solicitud.
- **Avisar al rol `backend`.** El pedido dice literalmente "al solicitante"; el backend no recibe un segundo email por cada oferta que entra a una solicitud que ya conoce desde spec 109.
- **Cualquier otro evento del marketplace que hoy no notifica.** Retiro/edición de oferta (105), adjudicación (106), cancelación (95), cierre y valoración (108): ninguno empieza aquí. Este spec cubre solo la creación de la oferta. La notificación de "oferta actualizada/retirada" es candidata directa para el próximo spec de esta serie.
- **Notificar ofertas creadas fuera de la API** (back office, drush, importación). El disparo vive en el endpoint, mismo criterio que spec 109 y spec 80.
- **Deep link a un detalle de oferta individual.** No existe ese endpoint (spec 100 lo excluyó); el `deep_link.target` sigue siendo `service_request`, igual que el destino del botón del email.
- **Deduplicar avisos si el residente recibe varias ofertas.** Cada `POST` de cada proveedor genera su propia fila, push y email — es la decisión de "cada oferta notifica".
- **Traducir los textos vía `myapi_t()`.** Fijos en español, mismo criterio que los siete triggers anteriores y el de spec 109.
- **Envío síncrono de push o email.** Sale por cola en el siguiente cron, como el resto.
- **Rate limiting** sobre cuántos avisos genera un proveedor ofertando.
- **Backfill.** No aplica: no hay columna nueva, y no hay ofertas "retroactivas" que notificar.
- **Universal Links / App Links con dominio propio.** El botón usa un esquema custom (`myapp://`), mismo patrón que spec 07; una infraestructura de enlaces web con fallback real es un cambio de configuración de la app Flutter, no de este módulo.

---

## Modelo de datos

Este spec no agrega tablas ni columnas: reutiliza el esquema y el envoltorio (`myapi_notification_create()`, `audience`, `provider_id`, `unit_id`/`condominium_id`) que dejó listo spec 109. Solo agrega constantes, funciones de texto, una clave de correo y una variable de configuración.

### 1. Constantes nuevas en `includes/myapi.service_request_notification.inc`

| Constante | Valor | Nota |
|---|---|---|
| `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER` | `'service_offer'` | Identifica que el evento se originó en un nodo `service_offer`, no en la solicitud. |
| `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_RECEIVED` | `'service_offer_received'` | — |
| `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST` | `'service_request'` | Hasta hoy el include solo tenía `..._SERVICE_REQUEST_PROVIDER` (spec 109); esta es la primera notificación de este dominio dirigida al residente, y su deep link apunta a su propia solicitud. |
| `MYAPI_SERVICE_OFFER_RECEIVED_MAIL_KEY` | `'service_request_offer_resident'` | Clave de `drupal_mail()` / `myapi_html_mail_keys()`. |

`source_type`/`source_nid` describen la oferta (el nodo que disparó el evento); `deep_link_target`/`deep_link_id` apuntan a la solicitud (donde vive el detalle que el residente puede abrir). Son columnas independientes en `myapi_notification_create()` — no hay ninguna regla que las obligue a coincidir, y spec 109 ya las usa con valores distintos (`source_type = service_request` con `deep_link_target = service_request_provider`).

### 2. Qué recibe `myapi_service_request_notify_offer_received()`

`myapi_service_offer_create()` ya tiene en mano todo lo necesario al llegar al punto de enganche — nada se vuelve a consultar:

```php
myapi_service_request_notify_offer_received($offer_node, [
  'request_nid'     => $request_row->nid,
  'request_title'   => $request_row->title,
  'requester_uid'   => $request_row->requester_uid,
  'condominium_id'  => $request_row->condominium_id,
  'unit_id'         => $request_row->unit_id,
  'provider_id'     => $provider_id,
  'provider_name'   => $provider_row->title,
  'amount'          => $values['amount'],       // NULL si on_site_quote
  'amount_type'     => $values['amount_type'],
]);
```

`$request_row` es la misma fila que `myapi_service_offer_eligibility()` ya validó; `$provider_row` es la que resolvió `provider_id`. El nombre del residente se resuelve **dentro** de la función nueva, una sola vez, vía `myapi_user_fetch_profile_fields($requester_uid)` — mismo helper que ya usa el email de `backend` de spec 109 — con el username como respaldo si el perfil no tiene nombre.

### 3. Texto del monto — `myapi_service_offer_amount_text($amount, $amount_type)`

Función pura, reutiliza el catálogo `myapi_services_offer_amount_types()` de spec 100 (regla 3 de `CLAUDE.md`: no retecleeer la etiqueta).

| `amount_type` | Texto |
|---|---|
| `on_site_quote` | `A presupuestar en sitio` |
| `fixed` / `estimate` / `hourly` | `{number_format($amount, 2)} ({etiqueta del catálogo})` — ej. `150.00 (Precio cerrado)` |

Un `amount_type` que no está en el catálogo (dato corrupto) cae al mismo texto que `on_site_quote`, nunca a un error.

### 4. Fila de bandeja / push al residente

Una sola llamada a `myapi_notification_create()`:

| Clave | Valor |
|---|---|
| `source_type` | `service_offer` |
| `source_nid` | nid de la oferta recién creada |
| `type` | `service_offer_received` |
| `title` | `Nueva oferta recibida` |
| `body` | ver abajo |
| `deep_link_target` | `service_request` |
| `deep_link_id` | nid de la solicitud |
| `condominium_id` | `$request_row->condominium_id` |
| `unit_id` | `$request_row->unit_id` |
| `provider_id` | nid del proveedor que ofertó |
| `audience` | `resident` |
| `uids` | `[$requester_uid]` |

**`title`** = `Nueva oferta recibida`.
**`body`** = `"{asunto}\nProveedor: {proveedor}\nMonto: {monto_texto}"`, donde `{asunto}` = `$request_row->title`, `{proveedor}` = `$provider_name`, `{monto_texto}` = el texto de la sección 3. Igual que en spec 109, cualquier dato no resoluble se imprime como `—` en vez de romper el armado.

`data` del push queda así:

```json
{
  "target": "service_request",
  "id": 128,
  "unit": 44,
  "condominium": 87,
  "notification_type": "service_offer_received",
  "audience": "resident",
  "provider": 41
}
```

### 5. Email al residente — clave `service_request_offer_resident`

Un ítem de cola por `uid` (aquí, exactamente uno: el `requester_uid`), sobre el shell `myapi_mail_reservation_html()`.

**Asunto:** `Nueva oferta recibida — {asunto}`.

| Línea | Origen |
|---|---|
| Asunto | `$request_row->title` |
| Proveedor | `$provider_name` |
| Monto | mismo texto de la sección 3 |

**Saludo:** `Hola {nombre}` si `myapi_user_fetch_profile_fields()` resuelve nombre, si no `Hola` a secas — mismo criterio que los emails de reclamos (spec 68).

**Botón:** `Ver solicitud` → `myapi_service_request_app_deep_link_url($request_nid)`:

```php
function myapi_service_request_app_deep_link_url($request_nid) {
  $base = variable_get('myapi_service_request_deep_link_base', 'myapp://service-requests');
  return check_plain($base . '/' . $request_nid);
}
```

Mismo patrón que `resources/auth.resource.inc` usa para `myapi_password_reset_deep_link_base` (spec 07): esquema `myapp://` ya adoptado por la app, configurable sin deploy.

**Cierre:** `Puedes ver el detalle completo desde el botón de abajo.`

### Variable de configuración nueva

| Variable | Valor por defecto | Nota |
|---|---|---|
| `myapi_service_request_deep_link_base` | `myapp://service-requests` | Base del deep link al que apunta el botón del email. Independiente de `myapi_password_reset_deep_link_base` (spec 07), aunque comparte el mismo esquema `myapp://`. |

---

## Plan de implementación

1. **Constantes — `includes/myapi.service_request_notification.inc`.** Agregar `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_RECEIVED`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST` y `MYAPI_SERVICE_OFFER_RECEIVED_MAIL_KEY`, junto a las de spec 109.
   *Verificación: `php -l`; `drush cc all` sin errores.*

2. **Constructores puros de texto — mismo archivo.** `myapi_service_offer_amount_text($amount, $amount_type)` (reusa `myapi_services_offer_amount_types()`), `myapi_service_offer_push_title()`, `myapi_service_offer_push_body($subject, $provider_name, $amount_text)`, y `myapi_service_request_app_deep_link_url($request_nid)` (lee la variable, `check_plain()` del resultado).
   *Verificación: cubierto por los tests unitarios del paso 6, sin sitio arrancado — cada `amount_type` produce el texto exacto de la tabla, incluido el caso corrupto que cae a `on_site_quote`, y la URL con y sin variable override.*

3. **El email — `includes/myapi.mail.inc` y `myapi.module`.** `myapi_service_offer_resident_mail_params(...)` (mismo archivo que el paso 2) y `myapi_mail_format_service_request_offer_resident()` / `myapi_mail_service_request_offer_resident_html()` sobre `myapi_mail_reservation_html()`, **con** botón `Ver solicitud` → `myapi_service_request_app_deep_link_url()`. Un `case` nuevo en `myapi_mail()`: `service_request_offer_resident`.
   *Verificación: `drupal_mail('myapi', 'service_request_offer_resident', ...)` en `drush php-eval` produce el asunto y el HTML esperados, con el botón apuntando a `myapp://service-requests/{nid}` por defecto, y con y sin nombre de residente resuelto.*

4. **Clave de correo — `myapi.install`.** `myapi_html_mail_keys()` gana `myapi_service_request_offer_resident`; `myapi_update_7037()` la reaplica sobre un sitio ya instalado, mismo patrón idempotente que `myapi_update_7036()`. Sin `hook_schema()` — no hay columna nueva.
   *Verificación: `drush updb` corre limpio; una segunda pasada no encuentra nada pendiente.*

5. **La orquestación — `myapi_service_request_notify_offer_received($offer_node, $context)`.** Resuelve el nombre del residente una sola vez, arma `title`/`body`/`data` y llama a `myapi_notification_create()` con `uids = [$context['requester_uid']]`; encola el email al residente. Best-effort de principio a fin: no lanza, no revierte nada.
   *Verificación: invocada a mano sobre una oferta existente, crea una fila de bandeja, un ítem en `myapi_onesignal_push` y uno en `myapi_mail_send` con el botón poblado.*

6. **`tests/unit/ServiceRequestNotificationTest.php`.** Casos nuevos: los cuatro `amount_type` de `myapi_service_offer_amount_text()` (incluido el corrupto), el `title`/`body` del push, los params y el asunto del email, la URL del botón con y sin variable configurada, y que `uids` sea exactamente `[requester_uid]` sin importar cuántas ofertas previas tenga la solicitud.
   *Verificación: `./vendor/bin/phpunit` en verde, incluida toda la suite previa.*

7. **Enganche en el endpoint — `resources/service_offer.resource.inc`.** Llamada a `myapi_service_request_notify_offer_received()` tras las tres escrituras (oferta, transición si aplica, transacción si aplica) y antes de armar el `201`, precedida de su `module_load_include()` (el include ya está en `myapi.info` desde spec 109). `drush cc all`.
   *Verificación: `POST /api/v1/service-requests/{id}/offers` sigue devolviendo el mismo `201` de spec 100, con la fila y los ítems de cola ya creados.*

8. **Documentación.** `docs/service-request-notifications.md` gana el tercer aviso; `docs/notification.md` el `type` nuevo; nota, enlace y tabla de la variable nueva en `docs/service-offer.md`.
   *Verificación: `docs/notification.md` describe el `data` del push tal como lo emite el código.*

9. **Prueba manual de extremo a extremo.** `drush updb && drush cc all`. Crear una oferta desde un proveedor sobre una solicitud abierta → el residente ve la notificación en su bandeja con `deep_link.target = "service_request"` y `deep_link.provider` poblado, recibe el push con `"audience": "resident"` y, tras `drush cron`, el email con el botón que abre la app (o falla silenciosamente si no está instalada). Repetir con una segunda oferta de otro proveedor sobre la misma solicitud → segunda fila independiente, `uids` sigue siendo solo el residente.

---

## Criterios de aceptación

**Disparo y destinatario**
- [ ] Crear una oferta vía `POST /api/v1/service-requests/{id}/offers` genera exactamente **una** fila en `myapi_notifications` con `uid = requester_uid` de la solicitud.
- [ ] Una segunda y tercera oferta de proveedores distintos sobre la misma solicitud generan cada una su propia fila independiente, todas con el mismo `uid` (el residente).
- [ ] Ninguna otra cuenta (el proveedor que ofertó, otros ocupantes de la vivienda, el rol `backend`) recibe fila, push ni email por este evento.

**Contenido de la fila / push**
- [ ] `source_type = "service_offer"`, `source_nid` = nid de la oferta recién creada, `type = "service_offer_received"`.
- [ ] `deep_link_target = "service_request"`, `deep_link_id` = nid de la solicitud (no de la oferta).
- [ ] `condominium_id` y `unit_id` quedan poblados con los de la solicitud (a diferencia del aviso al proveedor de spec 109, que los deja en `NULL`).
- [ ] `provider_id` = nid del proveedor que ofertó.
- [ ] `title = "Nueva oferta recibida"`; `body` con las tres líneas: asunto, `Proveedor:`, `Monto:`.
- [ ] El `data` del push lleva `"audience": "resident"` y `"provider"` con el nid del proveedor.
- [ ] `amount_type = "on_site_quote"` → el texto del monto es `"A presupuestar en sitio"`, sin monto numérico.
- [ ] `amount_type ∈ (fixed, estimate, hourly)` → el texto es `"{monto con 2 decimales} ({etiqueta del catálogo})"`, ej. `"150.00 (Precio cerrado)"`.
- [ ] Un `amount_type` fuera de catálogo (dato corrupto) no rompe el armado: cae al mismo texto que `on_site_quote`.

**Email**
- [ ] Se encola un ítem en `myapi_mail_send`, clave `service_request_offer_resident`, a la dirección del residente.
- [ ] Asunto: `"Nueva oferta recibida — {asunto de la solicitud}"`.
- [ ] Cuerpo con asunto, proveedor y el mismo texto de monto que el push.
- [ ] Saludo `"Hola {nombre}"` cuando el perfil del residente resuelve nombre; `"Hola"` a secas si no.
- [ ] El email lleva un botón `"Ver solicitud"` cuya URL es `{myapi_service_request_deep_link_base}/{nid de la solicitud}`, con `myapp://service-requests` como valor por defecto de la variable.
- [ ] Cambiando la variable `myapi_service_request_deep_link_base` en el sitio, la URL del botón cambia de base sin tocar código.

**Esquema y compatibilidad**
- [ ] No se agrega ninguna columna ni tabla nueva; `drush updb` solo actualiza la clave de correo (`myapi_update_7037`) y una segunda ejecución no encuentra nada pendiente.
- [ ] Las dos notificaciones de spec 109 (creación de solicitud) siguen funcionando idénticas, sin ningún cambio de comportamiento.
- [ ] `GET /api/v1/notifications` devuelve la notificación nueva con `deep_link.target = "service_request"`, `deep_link.provider` poblado, sin alterar ninguna clave existente de la respuesta.

**No regresión y robustez**
- [ ] El `201` de `POST /api/v1/service-requests/{id}/offers` conserva las diecisiete claves de spec 100 (`service_offer` de 15 claves + `request`), byte por byte.
- [ ] Una oferta creada desde el back office (formulario de nodo, drush) **no** dispara ningún aviso — el disparo vive en el endpoint, no en un hook.
- [ ] Un fallo al encolar (cola caída, dirección inválida, `myapi_user_fetch_profile_fields()` sin resultado) queda en `watchdog` y no impide el `201` ni deshace la oferta ni la transacción.
- [ ] `./vendor/bin/phpunit` en verde, incluida toda la suite previa.
- [ ] `drush cc all` no reporta errores; el include ya listado en `myapi.info` desde spec 109 no necesita una segunda entrada.

**Documentación**
- [ ] `docs/service-request-notifications.md` documenta el tercer aviso completo.
- [ ] `docs/notification.md` documenta el `type` `service_offer_received`.
- [ ] La variable `myapi_service_request_deep_link_base` queda documentada con su valor por defecto, mismo estilo que la tabla de variables de spec 07.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Canales | Bandeja + push + email, en una sola llamada a `myapi_notification_create()` | Solo push + email, sin fila de bandeja | Elección del usuario. Mismo patrón único que los ocho triggers existentes del módulo; separar el push a mano duplicaría lógica de OneSignal que ya resuelve esa función. |
| Contenido del aviso | Asunto de la solicitud + nombre del proveedor + tipo de precio/monto | Solo asunto y proveedor, sin monto; o incluir también el mensaje libre del proveedor | Elección del usuario. Es lo mínimo para que el residente decida si vale la pena abrir el detalle; el mensaje libre puede ser largo y ensuciar el push. |
| Aviso al `backend` | Ninguno, solo al residente | Un email adicional al rol `backend`, como en spec 109 | Elección del usuario, literal en el pedido ("al solicitante"). El backend ya se enteró de la solicitud en spec 109; no necesita un segundo aviso por cada oferta que reciba. |
| Deep link (bandeja/push) | `target = "service_request"`, `id` = nid de la solicitud | Un target nuevo `service_offer` con el nid de la oferta | Elección del usuario. No existe un `GET` de detalle de oferta individual — spec 100 decidió que las ofertas viajan siempre dentro del detalle de la solicitud — así que no hay pantalla a la que apuntar un target de oferta. |
| Repetición | Cada oferta nueva genera su propio aviso, sin importar cuántas existan ya sobre la misma solicitud | Notificar solo la primera oferta (la que mueve `open → offered`) | Elección del usuario. Cada oferta es un evento propio para el residente — le llegó un presupuesto nuevo — independientemente de si la solicitud ya estaba en `offered`. |
| Ubicación del código | Se amplía `includes/myapi.service_request_notification.inc` (spec 109) | Include nuevo `myapi.service_offer_notification.inc` | Elección del usuario. Mismo dominio de evento (`service_request`), mismo include; evita duplicar el patrón de armar título/cuerpo/email por segunda vez. |
| `provider_id` en la fila | Poblado con el nid del proveedor que ofertó | `NULL`, reservado solo para avisos cuya audiencia es el proveedor | Elección del usuario. Es contexto útil para el residente aunque `audience = "resident"`: la app puede mostrar qué proveedor mandó la oferta sin una consulta más. |
| `unit_id` / `condominium_id` | Poblados con los de la propia solicitud del residente | `NULL`, igual que el aviso al proveedor de spec 109 | Elección del usuario. Es la propia solicitud del residente — a diferencia del aviso al proveedor, aquí no hay ningún motivo de privacidad que justifique ocultarlos. |
| `source_type` / `source_nid` | Describen la **oferta** (`service_offer`, nid de la oferta) mientras el `deep_link` apunta a la **solicitud** | Que ambos pares describan lo mismo, como en los triggers de un solo nodo (boletín, pago, reserva) | Son columnas independientes en `myapi_notification_create()` — spec 109 ya las usa con valores distintos entre sí. El origen real del evento es la oferta; la navegación útil es la solicitud, que es donde vive el detalle. |
| Email con botón a la app | Sí, deep link `myapp://service-requests/{nid}` vía variable configurable `myapi_service_request_deep_link_base` | Sin botón, como el email al proveedor de spec 109 | Pedido explícito del usuario durante la revisión de esta spec. Se reutiliza el patrón exacto de `myapi_password_reset_deep_link_base` (spec 07): mismo esquema `myapp://` ya usado por la app, variable de Drupal para poder ajustarlo sin deploy. |
| Formato del monto | `número (etiqueta del catálogo)`, ej. `"150.00 (Precio cerrado)"`; `"A presupuestar en sitio"` para `on_site_quote` | Solo el número, sin la etiqueta del tipo de precio | El usuario pidió mostrar "tipo de precio/monto"; la etiqueta reusa `myapi_services_offer_amount_types()` de spec 100 sin retecleearla (regla 3 de `CLAUDE.md`). |
| Nombre del residente en el email | `myapi_user_fetch_profile_fields()`, con `"Hola"` a secas de respaldo | Sin saludo personalizado | Mismo criterio y helper que ya usa el email al `backend` de spec 109 y los emails de reclamos (spec 68). |
| Idioma de los textos | Fijos en español, sin `myapi_t()` | Traducir vía catálogo i18n | Mismo criterio que los ocho triggers existentes: no hay `Accept-Language` disponible para un destinatario que no es quien hizo la petición. |
| Entrega | Push y email por cola, en el siguiente cron | Envío síncrono dentro del `POST` | Mismo motivo que spec 109: no añadir latencia ni un SMTP colgado al `201` de la creación de la oferta. |
| Backfill | Ninguno — no aplica | — | No hay columna nueva ni dato retroactivo que rellenar; solo se agrega una clave de correo al catálogo. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El deep link no abre nada si el residente no tiene la app instalada.** Un esquema custom (`myapp://...`) no tiene fallback web, a diferencia de un Universal Link/App Link real. | Es el mismo compromiso ya aceptado y documentado en spec 07 para el reset de contraseña — ese caso además tiene una página web de respaldo porque el flujo lo exige (completar el reset), y este no. Aquí no hace falta: el residente igual ve la notificación en su bandeja (`GET /api/v1/notifications`) si el botón no abre nada. |
| **Volumen para el residente.** Una solicitud popular con diez ofertas produce diez filas, diez pushes y diez emails al mismo residente en poco tiempo. | Es la decisión explícita de "cada oferta notifica" (ver Decisiones). El residente puede desactivar notificaciones a nivel OS si le resulta ruidoso; agrupar en un resumen es un spec propio y más grande, ya descartado en la ronda de preguntas. |
| **La variable `myapi_service_request_deep_link_base` se comparte por convención de nombre con `myapi_password_reset_deep_link_base`, pero son variables independientes.** Un operador podría asumir que cambiar una cambia la otra. | Los nombres son explícitos y distintos (`service_request` vs `password_reset`); se documentan por separado, mismo criterio que ya usa el módulo para variables de flood control (`myapi_flood_forgot_*` vs `myapi_flood_reset_*`, spec 07). |
| **`source_type`/`source_nid` apuntan a la oferta pero `deep_link` apunta a la solicitud — una lectura apurada de la fila puede asumir que son el mismo nodo.** | Documentado explícitamente en el modelo de datos y en `docs/notification.md`; es la primera vez que el módulo separa los dos pares a propósito, así que la nota es más larga que en triggers anteriores. |
| **Un proveedor que se elimina o despublica después de ofertar deja un `provider_id` que apunta a un nodo inexistente.** | Es una FK lógica, mismo criterio que `condominium_id`/`unit_id` desde spec 26 y que `provider_id` desde spec 109: el endpoint de detalle del proveedor ya responde 404 y la app lo maneja. |
| **`myapi_user_fetch_profile_fields()` falla o el residente no tiene perfil completo.** | Cae al respaldo `"Hola"` sin nombre, mismo patrón que los emails de reclamos (spec 68); nunca produce un error ni bloquea el resto del disparo. |
| **El correo con botón depende de que `check_plain()` escape bien un `request_nid` que siempre es un entero validado.** No hay entrada de usuario libre en la URL. | El `nid` viene de `$request_row->nid`, ya validado como entero por la compuerta de spec 100 antes de llegar a este disparador; no hay superficie de inyección real, pero se mantiene `check_plain()` por consistencia con el resto del módulo. |
