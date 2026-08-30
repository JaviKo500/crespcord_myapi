# 113 — Notificaciones al cancelar una solicitud de servicio

> **Estado:** Approved · **Depende de:**
>   - `95-service-request-cancel` (Implemented) — dueña de `myapi_service_request_cancel()` en `resources/service_request.resource.inc`, del orden de las tres escrituras (solicitud → transacción → ofertas), de `myapi_service_offer_reject_live($nid)` como barrido final, y de «Notificaciones al proveedor» como fuera de su alcance, marcado explícitamente para un spec futuro — este lo cierra.
>   - `109-service-request-created-notifications` (Implemented) — dueña de `includes/myapi.service_request_notification.inc` (que este spec amplía sin crear un include nuevo), del patrón `audience`/`provider_id` en `myapi_notification_create()`, de `myapi_service_request_provider_uids()` y del precedente de forma del email a `backend` (`myapi_mail_reservation_html()`, botón a `node/{nid}`, alta en `myapi_html_mail_keys()`).
>   - `112-service-offer-award-notifications` (Implemented) — precedente más reciente del mismo include: patrón de orquestación best-effort, `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER`, y sobre todo el patrón de **capturar la lista de afectados antes del barrido** (`myapi_service_offer_sent_offers_for_request()`), que este spec reutiliza con su propia consulta.
> **Fecha:** 2026-08-30
> **Objetivo:** Cuando el residente cancela una solicitud de servicio (`PUT /api/v1/service-requests/{id}/cancel`), avisar por bandeja + push + email a cada proveedor cuya oferta quedó rechazada por esa cancelación (sin revelar el motivo del residente), y enviar un email al rol `backend` con el detalle de la cancelación, incluido el motivo cuando el residente lo dio.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.service_request_notification.inc`** (modificar) — gana el dominio de la cancelación, junto a creación (109), oferta recibida (110), retiro (111) y adjudicación (112):
  - Constantes nuevas: `MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_CANCELLED` (`'service_request_cancelled'`), `MYAPI_SERVICE_REQUEST_CANCELLED_PROVIDER_MAIL_KEY` (`'service_request_cancelled_provider'`), `MYAPI_SERVICE_REQUEST_CANCELLED_ADMIN_MAIL_KEY` (`'service_request_cancelled_admin'`). Reutilizadas sin cambio: `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER`, `MYAPI_NOTIFICATION_AUDIENCE_PROVIDER`, `MYAPI_SERVICE_REQUEST_NOTIFY_ROLE`.
  - `myapi_service_request_notify_cancelled($node, array $providers, array $context)` — la orquestación: recorre `$providers` notificando a cada uno (bandeja + push + email) y encola el email a `backend`. Recibe lo que `myapi_service_request_cancel()` ya tiene en mano — `$node` recién guardado y `$providers`, capturado por el propio endpoint antes del barrido. Best-effort de principio a fin, mismo criterio que las cuatro orquestadoras anteriores del archivo.
  - Constructores puros de texto: `myapi_service_request_cancelled_push_title()` / `_body($subject)` — un único texto, sin distinguir si la oferta del proveedor estaba `sent` o `selected` (decisión confirmada).
  - `myapi_service_request_cancelled_provider_mail_params(...)` y `myapi_service_request_cancelled_admin_mail_params(...)` — sobre el mismo patrón que los pares ya existentes en el archivo. Solo la versión de `backend` lleva el motivo del residente.
- **`includes/myapi.service_offer.inc`** (modificar) — una función nueva, de solo lectura: `myapi_service_offer_live_offers_for_request($request_nid)`, que devuelve `[{nid, provider_raw, provider_name}]` de las ofertas **`sent` o `selected`** de esa solicitud — el mismo par de estados que `myapi_service_offer_reject_live()` considera vivos, leído **antes** de que ese barrido las pase a `rejected`. Vive junto a `myapi_service_offer_reject_live()` y `myapi_service_offer_sent_offers_for_request()` (misma regla que spec 106/112: consultas de ofertas viven en el fichero de las ofertas) y **no** reutiliza ni modifica ninguna de las dos — ver Decisiones.
- **`includes/myapi.mail.inc`** (modificar) — dos pares `myapi_mail_format_*()` / `myapi_mail_*_html()` nuevos, ambos sobre `myapi_mail_reservation_html()`: el del proveedor sin botón (mismo criterio que el resto de emails a proveedor), el de `backend` con botón `Ver solicitud` → `node/{nid}`.
- **`myapi.module`** (modificar) — dos ramas nuevas en `myapi_mail()`.
- **`myapi.install`** (modificar) — `myapi_html_mail_keys()` gana las dos claves; `myapi_update_7040()` las reaplica sobre un sitio instalado, mismo patrón idempotente que `myapi_update_7039()`. Sin `hook_schema()` nuevo.
- **`resources/service_request.resource.inc`** (modificar) — `myapi_service_request_cancel()` gana dos puntos de enganche, ninguna otra función cambia:
  1. Justo **antes** del paso 7c (el barrido), captura `$affected_providers = myapi_service_offer_live_offers_for_request($node->nid)` — tiene que leerse antes de que el barrido pase esas ofertas a `rejected`.
  2. Justo **después** del paso 7c y antes de construir la respuesta (paso 8), llama a `myapi_service_request_notify_cancelled($node, $affected_providers, $context)`.
- **`myapi.info`** (sin cambios) — el include ya está listado desde spec 109.
- **`docs/service-request-notifications.md`** (modificar) — se agrega la sección «Request cancelled (SPEC 113)», mismo formato que las cuatro secciones ya documentadas.
- **`docs/notification.md`** (modificar) — el `type` nuevo `service_request_cancelled`.
- **`docs/service-request.md`** (modificar) — nota en `PUT .../cancel` de que dispara este aviso, con enlace a `docs/service-request-notifications.md`.
- **`tests/unit/ServiceRequestNotificationTest.php`** (modificar) — casos nuevos: título/cuerpo del texto único, params de los dos emails, y que `myapi_service_offer_live_offers_for_request()` trae `sent` y `selected` pero no `rejected`/`withdrawn`.

### Fuera de este spec

- **Avisar al residente que canceló.** Ya recibe el `200` con el detalle completo (spec 95); mismo criterio que specs 109-112 con quien ejecuta la acción.
- **Revelar el motivo del residente al proveedor.** El aviso al proveedor no lleva `reason` en ningún canal. *(Confirmado por el usuario.)*
- **Push o bandeja para `backend`.** Solo email, mismo criterio que el email admin de specs 109 y 112.
- **Modificar el contrato de `myapi_service_offer_reject_live()`** ni el de `myapi_service_offer_sent_offers_for_request()`. Ninguna de las dos cambia de firma ni de comportamiento.
- **Distinguir el mensaje entre una oferta `sent` (nunca elegida) y una `selected` (trabajo ya asignado).** Mismo texto para ambas. *(Confirmado por el usuario.)*
- **Notificar cancelaciones hechas fuera de la API** (back office, drush). El disparo vive en el endpoint, mismo criterio que specs 109-112.
- **Traducir los textos vía `myapi_t()`.** Fijos en español, mismo criterio que los siete triggers existentes.
- **Envío síncrono de push o email.** Sale por cola en el siguiente cron.
- **Rate limiting ni backfill.** No aplica: no hay columna nueva ni cancelaciones retroactivas que notificar.
- **Deep link a un detalle de oferta individual para el proveedor.** No existe ese endpoint; se reutiliza `service_request_provider`, mismo target que specs 109/112.

---

## Modelo de datos

Este spec no agrega tablas ni columnas: reutiliza `myapi_notification_create()`, `audience`, `provider_id` y el envoltorio de cola de email que dejaron listos specs 109-112. Solo agrega tres constantes de `type`/clave de correo, una función de lectura nueva y las funciones puras de texto/params correspondientes.

### 1. Constantes nuevas en `includes/myapi.service_request_notification.inc`

| Constante | Valor | Nota |
|---|---|---|
| `MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_CANCELLED` | `'service_request_cancelled'` | Aviso a cada proveedor con una oferta viva en la solicitud cancelada. |
| `MYAPI_SERVICE_REQUEST_CANCELLED_PROVIDER_MAIL_KEY` | `'service_request_cancelled_provider'` | — |
| `MYAPI_SERVICE_REQUEST_CANCELLED_ADMIN_MAIL_KEY` | `'service_request_cancelled_admin'` | — |

Reutilizadas sin cambio: `MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER`, `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER`, `MYAPI_NOTIFICATION_AUDIENCE_PROVIDER`, `MYAPI_SERVICE_REQUEST_NOTIFY_ROLE` (`'backend'`).

### 2. `myapi_service_offer_live_offers_for_request($request_nid)` — `includes/myapi.service_offer.inc`

Lee, antes de que el barrido las cambie, las ofertas **`sent` o `selected`** de la solicitud — el mismo par de estados que `myapi_service_offer_reject_live()` considera vivos, sin excepción por nid porque la cancelación no perdona a nadie:

```
SELECT no.nid, fp.field_provider_target_id AS provider_raw, pn.title AS provider_name
FROM field_data_field_request fq
INNER JOIN node no ON no.nid = fq.entity_id AND no.type = 'service_offer' AND no.status = 1
INNER JOIN field_data_field_offer_status fos ON fos.entity_id = no.nid AND fos.deleted = 0
INNER JOIN field_data_field_provider fp ON fp.entity_id = no.nid AND fp.deleted = 0
LEFT JOIN node pn ON pn.nid = fp.field_provider_target_id
WHERE fq.field_request_target_id = :request_nid
  AND fq.deleted = 0
  AND fos.field_offer_status_value IN ('sent', 'selected')
```

`LEFT JOIN` sobre el proveedor por la misma razón que `myapi_service_offer_sent_offers_for_request()`: un proveedor despublicado o borrado no debe hacer desaparecer el aviso, solo su nombre — que cae al `—` de siempre en los textos.

**Por qué una función nueva y no reutilizar una de las dos existentes:** `myapi_service_offer_reject_live()` devuelve un `int`, no una lista, y `myapi_service_offer_sent_offers_for_request()` solo mira `sent` — nunca necesita `selected`, porque en spec 112 la ganadora ya pasó a `selected` antes de que esa función corra y se excluye por nid. Aquí no hay ganadora que excluir: **toda** oferta `sent` o `selected` de la solicitud pierde por igual, así que la consulta necesita ambos estados y ninguna exclusión. Ensanchar cualquiera de las dos funciones existentes para este segundo llamador cambiaría un contrato que specs 95 y 112 ya usan tal cual.

### 3. Qué recibe `myapi_service_request_notify_cancelled()`

`myapi_service_request_cancel()` construye el `$context` a partir de `$node` y de `$reason` — **no** de `$row` (el detalle post-escritura), porque `$row` puede ser `FALSE` en el caso degradado (categoría borrada) que el propio endpoint ya contempla, y la notificación no debe depender de que la respuesta HTTP se haya podido construir:

```php
$affected_providers = myapi_service_offer_live_offers_for_request($node->nid); // antes del paso 7c

// ... los tres escrituras (7a, 7b, 7c) ...

myapi_service_request_notify_cancelled($node, $affected_providers, [
  'request_nid'    => $node->nid,
  'request_title'  => $node->title,
  'condominium_id' => myapi_building_admin_field_target_id($node, 'field_condominium'),
  'unit_id'        => myapi_building_admin_field_target_id($node, 'field_unit'),
  'reason'         => $reason['value'],
]);
```

No lleva `requester_uid`: el residente que canceló queda fuera de este spec.

### 4. Fila de bandeja / push por cada proveedor afectado

Una llamada a `myapi_notification_create()` **por cada elemento de `$affected_providers`**:

| Clave | Valor |
|---|---|
| `source_type` | `service_offer` |
| `source_nid` | nid de **esa** oferta |
| `type` | `service_request_cancelled` |
| `title` | `Solicitud cancelada` |
| `body` | `"{asunto}"` — una sola línea, sin motivo y sin distinguir si la oferta estaba `sent` o `selected` |
| `deep_link_target` | `service_request_provider` |
| `deep_link_id` | nid de la solicitud |
| `condominium_id` / `unit_id` | de la solicitud — mismo criterio que el resto de avisos a proveedor: `unit_id` va `NULL` |
| `provider_id` | nid de **ese** proveedor |
| `audience` | `provider` |
| `uids` | `myapi_service_request_provider_uids()` de ese proveedor |

Un `$affected_providers` vacío (solicitud sin ofertas vivas) no genera ninguna fila de este bloque — no-op silencioso, mismo criterio que specs 109-112.

### 5. Email a cada proveedor afectado — clave `service_request_cancelled_provider`

Un ítem por cuenta de cada proveedor afectado. **Asunto:** `Solicitud cancelada — {asunto}`. Cuerpo: solo el asunto de la solicitud. Cierre `Revisa la solicitud en la app.`, **sin botón** — mismo criterio que el resto de emails a proveedor (specs 109, 112). **Sin motivo.**

### 6. Email al `backend` — clave `service_request_cancelled_admin`

Un ítem de cola por cada usuario activo del rol `backend` (`myapi_notification_role_uids('backend')`), sin filtro de condominio, **siempre que se cancele la solicitud** — incluso sin proveedores afectados.

**Asunto:** `Solicitud cancelada #{nid} — {condominio}`.

| Línea | Origen |
|---|---|
| Asunto | `request_title` |
| Motivo | `reason` del contexto, o `—` si el residente no puso ninguno |
| Proveedores afectados | `count($affected_providers)` |
| Condominio | `title` de `field_condominium` |
| Vivienda | `title` de `field_unit` |

**Botón:** `Ver solicitud` → `url('node/{nid}', ['absolute' => TRUE])`. Todo valor no resoluble imprime `—`.

---

## Plan de implementación

1. **Constantes y claves de correo — `includes/myapi.service_request_notification.inc`.** Agregar las tres constantes de la sección de modelo de datos, junto a las de specs 109-112.
   *Verificación: `php -l`; `drush cc all` sin errores.*

2. **La consulta nueva — `includes/myapi.service_offer.inc`.** `myapi_service_offer_live_offers_for_request($request_nid)`, junto a `myapi_service_offer_reject_live()` y `myapi_service_offer_sent_offers_for_request()`.
   *Verificación: llamada a mano desde `drush php-eval` sobre una solicitud con una oferta `sent`, una `selected` y una `withdrawn` devuelve exactamente las dos primeras, con `provider_name` poblado; un proveedor despublicado imprime `NULL` en `provider_name` sin romper la fila.*

3. **Constructores puros de texto — `includes/myapi.service_request_notification.inc`.** `myapi_service_request_cancelled_push_title()` / `_body($subject)`.
   *Verificación: cubierto por los tests unitarios del paso 6.*

4. **Los dos correos — `includes/myapi.mail.inc` y `myapi.module`.** Los dos pares `myapi_mail_format_*()` / `_html()` sobre `myapi_mail_reservation_html()`, y sus dos `case` nuevos en `myapi_mail()`.
   *Verificación: `drupal_mail('myapi', 'service_request_cancelled_admin', ...)` (y el otro) en `drush php-eval` producen el asunto y el HTML esperados, incluida la línea `Motivo` con y sin texto.*

5. **Clave de correo — `myapi.install`.** `myapi_html_mail_keys()` gana las dos claves; `myapi_update_7040()` las reaplica sobre un sitio ya instalado, mismo patrón idempotente que `myapi_update_7039()`. Sin `hook_schema()`.
   *Verificación: `drush updb` corre limpio; una segunda pasada no encuentra nada pendiente.*

6. **La orquestación y sus tests — `myapi_service_request_notify_cancelled($node, array $providers, array $context)` y `tests/unit/ServiceRequestNotificationTest.php`.** Recorre `$providers` notificando a cada uno, encola el email a `backend` sin condicionarlo a que `$providers` no esté vacío. Best-effort de principio a fin: no lanza, no revierte nada. Casos nuevos: título/cuerpo del texto único, params de los dos emails (incluida la línea `Motivo` presente/ausente), que cada proveedor reciba su propia fila con su propio `provider_id`, y que un `$providers` vacío solo encola el email de `backend`.
   *Verificación: `./vendor/bin/phpunit` en verde, incluida toda la suite previa; invocada a mano sobre una solicitud recién cancelada con dos proveedores afectados, crea dos filas de bandeja, dos ítems de push, dos emails de proveedor y uno de `backend`.*

7. **Enganche en el endpoint — `resources/service_request.resource.inc`.** En `myapi_service_request_cancel()`: captura de `$affected_providers` justo antes del paso 7c y llamada a `myapi_service_request_notify_cancelled()` justo después del paso 7c y antes del paso 8. `drush cc all`.
   *Verificación: `PUT /api/v1/service-requests/{id}/cancel` sigue devolviendo el mismo `200` de spec 95, con las filas y los ítems de cola ya creados, incluido el caso degradado (categoría borrada) donde la notificación igual dispara aunque la respuesta venga con solo `id`/`status`.*

8. **Documentación.** `docs/service-request-notifications.md` gana la sección «Request cancelled (SPEC 113)»; `docs/notification.md` el `type` nuevo; nota y enlace en `docs/service-request.md`.
   *Verificación: `docs/notification.md` describe el `data` del push tal como lo emite el código.*

9. **Prueba manual de extremo a extremo.** `drush updb && drush cc all`. Cancelar una solicitud `offered` con dos ofertas `sent` → cada proveedor ve su notificación con `deep_link.provider` = su propio nid y recibe el push/email sin motivo; tras `drush cron`, los dos emails de proveedor salen y el `backend` recibe el suyo con el motivo (si lo hubo) y el botón funcionando. Repetir cancelando una solicitud `assigned` (oferta `selected`) y una `open` sin ofertas — esta última solo dispara el email de `backend`.

---

## Criterios de aceptación

**Disparo y destinatarios**
- [ ] Cancelar una solicitud vía `PUT /api/v1/service-requests/{id}/cancel` genera exactamente **una** fila en `myapi_notifications` por cada proveedor con una oferta `sent` o `selected` en esa solicitud al momento de cancelar.
- [ ] Un proveedor cuya oferta ya estaba `rejected` o `withdrawn` antes de esta llamada **no** recibe fila, push ni email.
- [ ] El residente que canceló **no** recibe fila, push ni email por este evento.
- [ ] Un segundo intento de cancelación sobre la misma solicitud responde `409 service_request_not_cancellable` (spec 95) y **no** genera ninguna notificación nueva.
- [ ] Una solicitud sin ofertas vivas (`open` recién creada, o todas las ofertas ya terminales) no genera ninguna fila de proveedor; el email de `backend` sale igual.

**Contenido — proveedor**
- [ ] `source_type = "service_offer"`, `source_nid` = nid de **esa** oferta, `type = "service_request_cancelled"`, `deep_link_target = "service_request_provider"`, `deep_link_id` = nid de la solicitud, `unit_id = NULL`, `provider_id` = nid de ese proveedor, `audience = "provider"`.
- [ ] `title = "Solicitud cancelada"`; `body` con solo el asunto de la solicitud, sin motivo.
- [ ] Una oferta `selected` (proveedor asignado) y una `sent` (nunca elegida) reciben **el mismo** título y cuerpo — sin distinción.
- [ ] Se encola un ítem `service_request_cancelled_provider` por cuenta de cada proveedor afectado, asunto `"Solicitud cancelada — {asunto}"`, **sin botón**, **sin motivo**.

**Email a `backend`**
- [ ] Se encola un ítem `service_request_cancelled_admin` por cada usuario **activo** con rol `backend`, y por ninguno más — con o sin proveedores afectados.
- [ ] Asunto `"Solicitud cancelada #{nid} — {condominio}"`; cuerpo con asunto, motivo, proveedores afectados, condominio y vivienda, en ese orden.
- [ ] Cuando el residente puso un `reason` al cancelar, la línea `Motivo` lo muestra tal cual; cuando no, muestra `—`.
- [ ] El botón `Ver solicitud` apunta a `node/{nid}` en absoluto.
- [ ] Sin nadie en el rol `backend` no se encola nada y el `200` sale igual.

**Esquema y compatibilidad**
- [ ] No se agrega ninguna columna ni tabla nueva; `drush updb` solo actualiza las claves de correo (`myapi_update_7040`) y una segunda ejecución no encuentra nada pendiente.
- [ ] Las notificaciones de specs 109, 110, 111 y 112 siguen funcionando idénticas, sin ningún cambio de comportamiento.
- [ ] `myapi_service_offer_reject_live()` y `myapi_service_offer_sent_offers_for_request()` conservan su firma y su comportamiento exactos.

**No regresión y robustez**
- [ ] El `200` de `PUT /api/v1/service-requests/{id}/cancel` conserva la respuesta de spec 95 (`service_request` + `offers_rejected`), byte por byte, incluido el caso degradado (categoría borrada).
- [ ] Una cancelación hecha desde el back office (formulario de nodo, drush) **no** dispara ningún aviso. El disparo vive solo en `myapi_service_request_cancel()`.
- [ ] Un fallo al encolar (cola caída, dirección inválida) queda en `watchdog` y no impide el `200` ni deshace la cancelación.
- [ ] `./vendor/bin/phpunit` en verde, incluida toda la suite previa.
- [ ] `drush cc all` no reporta errores.

**Documentación**
- [ ] `docs/service-request-notifications.md` documenta la sección «Request cancelled (SPEC 113)» completa.
- [ ] `docs/notification.md` documenta el `type` `service_request_cancelled`.
- [ ] `docs/service-request.md` enlaza a la sección nueva desde el bloque de `PUT .../cancel`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Quiénes cuentan como "proveedores involucrados" | Toda oferta `sent` o `selected` de la solicitud al momento de cancelar | Solo el proveedor asignado (`field_assigned_provider`) | Elección del usuario. Coincide con lo que spec 95 realmente rechaza — el mismo conjunto que produce `offers_rejected` — y cubre tanto al proveedor que nunca fue elegido como al que ya tenía el trabajo. |
| Motivo (`reason`) del residente al proveedor | Nunca se incluye | Incluirlo cuando existe | Elección del usuario. Mismo criterio que el aviso al perdedor de spec 112: el proveedor no necesita ni debe ver el texto privado del residente. |
| Motivo (`reason`) del residente a `backend` | Se incluye cuando existe, `—` si no | Nunca incluirlo | Elección del usuario. `backend` es staff interno, no un competidor: el motivo da contexto operativo real para entender la cancelación. |
| Cuándo se envía el email a `backend` | Siempre que se cancele, haya o no proveedores afectados | Solo si `offers_rejected > 0` | Elección del usuario, mismo criterio que el resto de eventos de `backend` (creación, adjudicación): no depende de un efecto lateral. |
| Canales para el proveedor | Bandeja + push + email | Solo bandeja + push | Elección del usuario, mismo trío que specs 109-112. |
| `type` de la notificación al proveedor | Constante nueva `service_request_cancelled` | Reutilizar `service_offer_rejected` (spec 112) | Elección del usuario. La causa es distinta — "perdiste la puja" vs "se canceló la solicitud" — y un `type` propio deja que la app distinga los dos casos en vez de mezclarlos bajo un mismo significado. |
| Distinguir el mensaje entre oferta `sent` y `selected` | Un solo texto para ambas | Dos textos ("tu oferta fue descartada" / "el trabajo asignado se canceló") | Elección del usuario. Simplifica el include y evita duplicar constructores de texto para una distinción que el proveedor entiende igual de bien con un mensaje único. |
| Cómo se obtiene la lista de proveedores afectados | Consulta nueva `myapi_service_offer_live_offers_for_request()`, leída **antes** del barrido (paso 7c) | Modificar `myapi_service_offer_reject_live()` para que devuelva la lista en vez de (o además de) el conteo | Esa función la reutiliza `accept` (spec 106/112) sin necesitar la lista de la cancelación. Cambiar su contrato para un llamador nuevo obligaría al otro a cargar con un dato que no usa. |
| Por qué no reutilizar `myapi_service_offer_sent_offers_for_request()` (spec 112) | Función nueva | Ensanchar esa función para aceptar también `selected` | Esa función existe para el caso de adjudicación, donde la ganadora ya es `selected` y se excluye por nid — nunca necesita incluir `selected` en el resultado. Ensancharla mezclaría dos contratos distintos (con exclusión por nid vs sin ella) en una sola función. |
| Punto de captura de los proveedores afectados | Antes del barrido (paso 7c), en el endpoint | Después del barrido, reconsultando por estado `rejected` | Después del barrido es indistinguible: una oferta que ya estaba `rejected` desde antes y una que acaba de pasar a `rejected` se ven exactamente igual. Solo `sent`/`selected`, leído en ese instante, identifica correctamente a quién notificar. |
| Fuente del `$context` de la notificación | Campos de `$node` (`field_condominium`, `field_unit`, `title`) | Los datos ya resueltos de `$row` (el detalle post-escritura) | `$row` puede ser `FALSE` en el caso degradado que spec 95 ya contempla (categoría borrada); construir el contexto desde `$node` hace que la notificación no dependa de que la respuesta HTTP se haya podido construir. |
| Ubicación del código | Se amplía `includes/myapi.service_request_notification.inc` (specs 109-112) | Include nuevo | Mismo dominio de evento (`service_request`/`service_offer`), mismo include, mismo criterio que specs 110-112. |
| Deep link | `target = "service_request_provider"`, mismo que specs 109/112 | Un target propio de este aviso | Es la misma vista de proveedor que ya usa la solicitud creada/adjudicada; no hay un detalle de oferta individual para el proveedor. |
| Idioma de los textos | Fijos en español, sin `myapi_t()` | Traducir vía catálogo i18n | Mismo criterio que los siete triggers existentes: no hay `Accept-Language` disponible para destinatarios que no son quien hizo la petición. |
| Entrega | Push y email por cola, en el siguiente cron | Envío síncrono dentro del `PUT` | Mismo motivo que specs 109-112: no añadir latencia al `200` de la cancelación, y evitar que un SMTP colgado bloquee el endpoint con hasta N+1 destinatarios. |
| Backfill | Ninguno — no aplica | — | No hay columna nueva ni cancelaciones retroactivas que notificar. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Volumen para una solicitud con muchas ofertas.** Una solicitud con diez ofertas vivas produce diez llamadas a `myapi_notification_create()` dentro del mismo `PUT`. | Mismo compromiso aceptado en specs 109 y 112: el coste real es la escritura de filas y encolado, no viajes de red — push y email salen en cron. |
| **Un proveedor con más de una oferta viva en la misma solicitud recibe más de un aviso.** No hay restricción que lo impida a nivel de esta consulta. | Aceptado, mismo criterio que specs 109 y 112: cada oferta es su propio evento. |
| **`myapi_service_offer_live_offers_for_request()` lee tablas de Field API por nombre de columna.** Un rename o cambio de cardinalidad en `field_provider` o `field_offer_status` rompe la resolución en silencio: cero proveedores notificados, ningún error. | Mismo compromiso ya documentado desde specs 109-112 y heredado por `myapi_service_offer_reject_live()`; acotado a un solo archivo y cubierto por los tests de la consulta. |
| **Ventana entre la lectura de `$affected_providers` y el barrido.** Si algo modificara el estado de una oferta entre la captura (antes del paso 7c) y la escritura (el paso 7c mismo) —imposible hoy sin concurrencia externa al propio `PUT`—, la notificación podría no coincidir exactamente con lo escrito. | Es la misma clase de ventana que spec 95 ya documenta y acepta para sus tres escrituras (riesgo de no-atomicidad del paso 7), y que spec 112 acepta para su propio barrido: no se introduce bloqueo nuevo aquí tampoco. |
| **Un proveedor afectado que se despublica entre la cancelación y el envío del email en cron.** El email ya está encolado con los datos resueltos y escapados; no se pierde, pero tampoco refleja el estado actual del proveedor. | Mismo criterio que specs 109 y 112: los params se encolan ya resueltos, así que el mensaje no se rompe ni cambia entre el disparo y el envío. |
| **El `$context` se construye desde `$node` y no desde `$row`, precisamente para sobrevivir al caso degradado de spec 95 (categoría borrada).** Si además `field_condominium` o `field_unit` apuntan a un nodo borrado, esas líneas caen al `—` de siempre — no hay riesgo nuevo, pero es la razón concreta por la que este spec no reutiliza `$row`. | Cubierto por los mismos degradadores (`myapi_service_request_mail_label()`) que el resto del archivo; no requiere mitigación adicional. |
