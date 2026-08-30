# 114 — Notificaciones al cerrar una solicitud de servicio y calificar al proveedor

> **Estado:** Approved · **Depende de:**
>   - `108-service-request-close` (Implemented) — dueña de `myapi_service_request_close()` en `resources/service_request.resource.inc`, de las dos formas del cuerpo (A: con calificación desde `assigned`/`direct`; B: sin adjudicar desde `offered`), del orden de las tres escrituras (calificación → solicitud → transacción), y de «Notificar al proveedor de que lo calificaron» como fuera de su alcance, marcado explícitamente para un spec futuro — este lo cierra.
>   - `109-service-request-created-notifications` (Implemented) — dueña de `includes/myapi.service_request_notification.inc` (que este spec amplía sin crear un include nuevo), del patrón `audience`/`provider_id` en `myapi_notification_create()`, y del precedente de forma del email a `backend`.
>   - `112-service-offer-award-notifications` (Implemented) — precedente de aviso al proveedor con datos concretos de su propia oferta (monto), reutilizado aquí para las estrellas y el comentario de su propia calificación.
>   - `113-service-request-cancel-notifications` (Implemented) — precedente más reciente del mismo include: patrón de orquestación best-effort y formato de línea condicional en el email a `backend`.
> **Fecha:** 2026-08-30
> **Objetivo:** Cuando el residente cierra su solicitud de servicio (`PUT /api/v1/service-requests/{id}/close`), enviar a los usuarios del rol `backend` un único email con el detalle del cierre —incluida la calificación cuando la hubo— y avisar por bandeja + push + email al proveedor calificado, con las estrellas y el comentario completos que recibió.

---

## Alcance

**Dentro de este spec**

- **`includes/myapi.service_request_notification.inc`** (modificar) — gana el dominio del cierre y la calificación, junto a creación (109), oferta recibida (110), retiro (111), adjudicación (112) y cancelación (113):
  - Constantes nuevas: `MYAPI_NOTIFICATION_SOURCE_SERVICE_RATING` (`'service_rating'`), `MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_RATED` (`'service_request_rated'`), `MYAPI_SERVICE_REQUEST_RATED_PROVIDER_MAIL_KEY` (`'service_request_rated_provider'`), `MYAPI_SERVICE_REQUEST_CLOSED_ADMIN_MAIL_KEY` (`'service_request_closed_admin'`). Reutilizadas sin cambio: `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER`, `MYAPI_NOTIFICATION_AUDIENCE_PROVIDER`, `MYAPI_SERVICE_REQUEST_NOTIFY_ROLE`.
  - `myapi_service_request_notify_closed($node, $rating, array $context)` — la orquestación: si `$rating` no es `NULL`, notifica al proveedor calificado (bandeja + push + email) y encola a `backend` **un único** email con el detalle del cierre y de la calificación; si `$rating` es `NULL` (cierre sin adjudicar, forma B), encola a `backend` el mismo email pero sin la sección de calificación. Recibe lo que `myapi_service_request_close()` ya tiene en mano tras las tres escrituras — nada se vuelve a consultar. Best-effort de principio a fin, mismo criterio que las cinco orquestadoras anteriores del archivo.
  - Constructores puros de texto: `myapi_service_request_rated_push_title()` / `_body($subject, $stars, $comment)` — con las estrellas y el comentario completos (decisión confirmada).
  - `myapi_service_request_rated_provider_mail_params(...)` y `myapi_service_request_closed_admin_mail_params(...)` — sobre el mismo patrón que los pares ya existentes en el archivo. La segunda arma un cuerpo condicional: motivo (forma B) o proveedor + estrellas + comentario (forma A), nunca los dos.
- **`includes/myapi.mail.inc`** (modificar) — dos pares `myapi_mail_format_*()` / `myapi_mail_*_html()` nuevos, ambos sobre `myapi_mail_reservation_html()`: el del proveedor sin botón (mismo criterio que el resto de emails a proveedor), el de `backend` con botón `Ver solicitud` → `node/{nid}`.
- **`myapi.module`** (modificar) — dos ramas nuevas en `myapi_mail()`.
- **`myapi.install`** (modificar) — `myapi_html_mail_keys()` gana las dos claves; `myapi_update_7041()` las reaplica sobre un sitio instalado, mismo patrón idempotente que `myapi_update_7040()`. Sin `hook_schema()` nuevo.
- **`resources/service_request.resource.inc`** (modificar) — `myapi_service_request_close()` gana un punto de enganche, ninguna otra función cambia: justo después de las tres escrituras (calificación → solicitud → transacción) y antes de construir la respuesta, llama a `myapi_service_request_notify_closed($node, $rating, $context)`.
- **`myapi.info`** (sin cambios) — el include ya está listado desde spec 109.
- **`docs/service-request-notifications.md`** (modificar) — se agrega la sección «Request closed / rated (SPEC 114)».
- **`docs/notification.md`** (modificar) — el `type` nuevo `service_request_rated`.
- **`docs/service-request.md`** (modificar) — nota en `PUT .../close` de que dispara este aviso, con enlace a `docs/service-request-notifications.md`.
- **`tests/unit/ServiceRequestNotificationTest.php`** (modificar) — casos nuevos: título/cuerpo del texto con estrellas y comentario, params de los dos emails (incluida la rama condicional del email a `backend`), y que un `$rating = NULL` no dispara nada hacia el proveedor.

**Fuera de este spec**

- **Avisar al residente que cerró.** Ya recibe el `200` con el detalle completo (spec 108); mismo criterio que specs 109-113 con quien ejecuta la acción. *(Confirmado por el usuario: el punto "al solicitante" del pedido original era, en realidad, solo para `backend`.)*
- **Push o bandeja para `backend`.** Solo email, mismo criterio que el resto de emails admin.
- **Dos emails separados a `backend`** (uno de cierre, otro de calificación). *(Confirmado por el usuario: un solo email combinado.)*
- **Notificar cierres o calificaciones hechas fuera de la API** (back office: `node/add/service_rating`, `node/%/edit`, `node/%/delete`). El disparo vive en el endpoint, mismo criterio que specs 109-113.
- **Traducir los textos vía `myapi_t()`.** Fijos en español, mismo criterio que los ocho triggers existentes.
- **Envío síncrono de push o email.** Sale por cola en el siguiente cron.
- **Rate limiting ni backfill.** No aplica: no hay columna nueva ni cierres retroactivos que notificar.
- **Deep link a un detalle de calificación individual.** No existe ese endpoint; se reutiliza `service_request_provider`, mismo target que specs 109-113.
- **Reabrir una solicitud cerrada, o editar/retirar una calificación desde la app.** `closed` sigue terminal (spec 108); no hay evento de "recalificar" que notificar.

---

## Modelo de datos

Este spec no agrega tablas ni columnas: reutiliza `myapi_notification_create()`, `audience`, `provider_id` y el envoltorio de cola de email que dejaron listos specs 109-113. Solo agrega tres constantes de `type`/clave de correo, una constante de `source_type` nueva y las funciones puras de texto/params correspondientes.

### 1. Constantes nuevas en `includes/myapi.service_request_notification.inc`

| Constante | Valor | Nota |
|---|---|---|
| `MYAPI_NOTIFICATION_SOURCE_SERVICE_RATING` | `'service_rating'` | El evento nace de un nodo `service_rating` recién creado, no de una `service_offer` — a diferencia de specs 110-113, aquí siempre hay una entidad propia que identificar (una `direct` no tiene oferta que referenciar, pero **siempre** tiene una calificación cuando este aviso dispara). |
| `MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_RATED` | `'service_request_rated'` | Aviso al proveedor calificado. |
| `MYAPI_SERVICE_REQUEST_RATED_PROVIDER_MAIL_KEY` | `'service_request_rated_provider'` | — |
| `MYAPI_SERVICE_REQUEST_CLOSED_ADMIN_MAIL_KEY` | `'service_request_closed_admin'` | Un único email, contenido condicional (ver punto 5). |

Reutilizadas sin cambio: `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER`, `MYAPI_NOTIFICATION_AUDIENCE_PROVIDER`, `MYAPI_SERVICE_REQUEST_NOTIFY_ROLE` (`'backend'`).

### 2. Qué recibe `myapi_service_request_notify_closed()`

`myapi_service_request_close()` ya tiene en mano, tras las tres escrituras, todo lo que hace falta — `$provider_nid` y `$provider_name` se resolvieron en la condición 7 de la compuerta (`node_load()`, cacheado por Drupal), y `$rating_id` en el paso 8a:

```php
$rating = $requires_rating ? [
  'rating_id'     => $rating_id,
  'provider_id'   => $provider_nid,
  'provider_name' => $provider_name,
  'stars'         => $body['values']['stars'],
  'comment'       => $body['values']['comment'],
] : NULL;

// ... 8b (la solicitud) y 8c (la transacción) ...

myapi_service_request_notify_closed($node, $rating, [
  'request_nid'    => $node->nid,
  'request_title'  => $node->title,
  'condominium_id' => myapi_building_admin_field_target_id($node, 'field_condominium'),
  'unit_id'        => myapi_building_admin_field_target_id($node, 'field_unit'),
  'close_reason'   => $body['values']['close_reason'],
]);
```

Igual que spec 113: el `$context` se construye desde `$node`, no desde `$row` (el detalle post-escritura), porque `$row` puede ser `FALSE` en el caso degradado (categoría borrada) que el propio endpoint ya contempla. No lleva `requester_uid`: el residente que cerró queda fuera de este spec.

### 3. Fila de bandeja / push al proveedor calificado — solo si `$rating !== NULL`

Una llamada a `myapi_notification_create()`:

| Clave | Valor |
|---|---|
| `source_type` | `service_rating` |
| `source_nid` | `$rating['rating_id']` |
| `type` | `service_request_rated` |
| `title` | `¡Te calificaron!` |
| `body` | `"{asunto}\n{estrellas} estrellas"`, con `" — {comentario}"` agregado a la segunda línea solo si hubo comentario |
| `deep_link_target` | `service_request_provider` |
| `deep_link_id` | nid de la solicitud |
| `condominium_id` / `unit_id` | de la solicitud — mismo criterio que el resto de avisos a proveedor: `unit_id` va `NULL` |
| `provider_id` | `$rating['provider_id']` |
| `audience` | `provider` |
| `uids` | `myapi_service_request_provider_uids($rating['provider_id'])` |

Un cierre sin calificación (`$rating === NULL`) no genera esta fila — no-op silencioso, mismo criterio que specs 109-113.

### 4. Email al proveedor calificado — clave `service_request_rated_provider`, solo si `$rating !== NULL`

Un ítem por cuenta del proveedor. **Asunto:** `Te calificaron — {asunto}`. Cuerpo: asunto, estrellas y comentario completos (decisión confirmada por el usuario). Cierre `Revisa la solicitud en la app.`, **sin botón** — mismo criterio que el resto de emails a proveedor.

### 5. Email al `backend` — clave `service_request_closed_admin`, **siempre**, contenido condicional

Un ítem de cola por cada usuario activo del rol `backend` (`myapi_notification_role_uids('backend')`), sin filtro de condominio, en **cualquiera** de las dos formas de cierre.

**Asunto:** `Solicitud cerrada #{nid} — {condominio}`.

| Línea | Cuando hubo calificación (`$rating !== NULL`) | Cuando no (`$rating === NULL`) |
|---|---|---|
| Asunto | `request_title` | `request_title` |
| Proveedor calificado | `provider_name`, o `—` | *(ausente)* |
| Estrellas | `stars` | *(ausente)* |
| Comentario | `comment`, o `—` | *(ausente)* |
| Motivo | *(ausente)* | `close_reason` |
| Condominio | `title` de `field_condominium` | ídem |
| Vivienda | `title` de `field_unit` | ídem |

Nunca aparecen juntas las líneas de calificación y la de `Motivo`: una y otra forma son mutuamente excluyentes, igual que en el propio spec 108.

**Botón:** `Ver solicitud` → `url('node/{nid}', ['absolute' => TRUE])`. Todo valor no resoluble imprime `—`.

---

## Plan de implementación

1. **Constantes y claves de correo — `includes/myapi.service_request_notification.inc`.** Agregar las cuatro constantes de la sección de modelo de datos, junto a las de specs 109-113.
   *Verificación: `php -l`; `drush cc all` sin errores.*

2. **Constructores puros de texto — `includes/myapi.service_request_notification.inc`.** `myapi_service_request_rated_push_title()` / `_body($subject, $stars, $comment)`.
   *Verificación: cubierto por los tests unitarios del paso 5.*

3. **Los dos correos — `includes/myapi.mail.inc` y `myapi.module`.** Los dos pares `myapi_mail_format_*()` / `_html()` sobre `myapi_mail_reservation_html()` (proveedor sin botón, `backend` con botón y cuerpo condicional), y sus dos `case` nuevos en `myapi_mail()`.
   *Verificación: `drupal_mail('myapi', 'service_request_closed_admin', ...)` (y el otro) en `drush php-eval` producen el asunto y el HTML esperados, incluida la rama con calificación y la rama con motivo.*

4. **Clave de correo — `myapi.install`.** `myapi_html_mail_keys()` gana las dos claves; `myapi_update_7041()` las reaplica sobre un sitio instalado, mismo patrón idempotente que `myapi_update_7040()`. Sin `hook_schema()`.
   *Verificación: `drush updb` corre limpio; una segunda pasada no encuentra nada pendiente.*

5. **La orquestación y sus tests — `myapi_service_request_notify_closed($node, $rating, array $context)` y `tests/unit/ServiceRequestNotificationTest.php`.** Si `$rating` no es `NULL`: notifica al proveedor (bandeja + push + email) y encola a `backend` el email con la sección de calificación. Si `$rating` es `NULL`: encola a `backend` el email con la sección de motivo. Best-effort de principio a fin: no lanza, no revierte nada. Casos nuevos: título/cuerpo del texto al proveedor (con y sin comentario), params de los dos emails en sus dos ramas, que un `$rating = NULL` no genera fila ni email de proveedor, y que el email a `backend` sale en ambos casos con las líneas correctas y nunca mezcladas.
   *Verificación: `./vendor/bin/phpunit` en verde, incluida toda la suite previa; invocada a mano sobre un cierre con calificación crea una fila de bandeja, un ítem de push, un email de proveedor y uno de `backend` con la sección de calificación; sobre un cierre sin adjudicar solo crea el email de `backend` con el motivo.*

6. **Enganche en el endpoint — `resources/service_request.resource.inc`.** En `myapi_service_request_close()`: construcción de `$rating` y llamada a `myapi_service_request_notify_closed()` justo después del paso 8c (la transacción) y antes del paso 9 (la respuesta). `drush cc all`.
   *Verificación: `PUT /api/v1/service-requests/{id}/close` sigue devolviendo el mismo `200` de spec 108, con las filas y los ítems de cola ya creados, incluido el caso degradado (categoría borrada) donde la notificación igual dispara aunque la respuesta venga con solo `id`/`status`/`rating_id`.*

7. **Documentación.** `docs/service-request-notifications.md` gana la sección «Request closed / rated (SPEC 114)»; `docs/notification.md` el `type` nuevo; nota y enlace en `docs/service-request.md`.
   *Verificación: `docs/notification.md` describe el `data` del push tal como lo emite el código.*

8. **Prueba manual de extremo a extremo.** `drush updb && drush cc all`. Cerrar una `assigned` con `{stars: 5, comment: "..."}` → el proveedor ve su notificación con estrellas y comentario, y recibe el push/email; tras `drush cron`, el email de proveedor sale y `backend` recibe el suyo con la sección de calificación y el botón funcionando. Repetir cerrando una `direct` (sin oferta) con calificación, y una `offered` con `close_reason` — esta última solo dispara el email de `backend` con el motivo.

---

## Criterios de aceptación

**Disparo y destinatarios**
- [ ] Cerrar una solicitud `assigned` o `direct` vía `PUT /api/v1/service-requests/{id}/close` con calificación válida genera exactamente **una** fila en `myapi_notifications` para el proveedor calificado.
- [ ] Cerrar una solicitud `offered` con `close_reason` (sin calificación) **no** genera ninguna fila de bandeja/push, y **no** envía email al proveedor.
- [ ] El residente que cerró **no** recibe fila, push ni email por este evento.
- [ ] Un segundo intento de cierre sobre la misma solicitud responde `409 service_request_not_closable` (spec 108) y **no** genera ninguna notificación nueva.
- [ ] En **ambas** formas de cierre (con y sin calificación) se encola exactamente **un** email `service_request_closed_admin` por cada usuario activo del rol `backend` — nunca dos.

**Contenido — proveedor calificado**
- [ ] `source_type = "service_rating"`, `source_nid` = nid de la calificación creada, `type = "service_request_rated"`, `deep_link_target = "service_request_provider"`, `deep_link_id` = nid de la solicitud, `unit_id = NULL`, `provider_id` = nid del proveedor, `audience = "provider"`.
- [ ] `title = "¡Te calificaron!"`; `body` con el asunto y las estrellas, y el comentario agregado solo cuando existe — sin separador colgando cuando no hay comentario.
- [ ] Se encola un ítem `service_request_rated_provider`, asunto `"Te calificaron — {asunto}"`, **con las estrellas y el comentario completos**, **sin botón**.
- [ ] Cerrar una `direct` (sin `field_assigned_offer`) con calificación genera el mismo aviso al proveedor, sin depender de que exista una oferta.

**Email a `backend`**
- [ ] Cuando hubo calificación: el cuerpo trae asunto, proveedor calificado, estrellas, comentario (o `—` si no hubo), condominio y vivienda, en ese orden — **sin** la línea `Motivo`.
- [ ] Cuando no hubo calificación: el cuerpo trae asunto, motivo, condominio y vivienda, en ese orden — **sin** las líneas de proveedor/estrellas/comentario.
- [ ] Asunto siempre `"Solicitud cerrada #{nid} — {condominio}"`, en ambas formas.
- [ ] El botón `Ver solicitud` apunta a `node/{nid}` en absoluto.
- [ ] Sin nadie en el rol `backend` no se encola nada y el `200` sale igual.

**Esquema y compatibilidad**
- [ ] No se agrega ninguna columna ni tabla nueva; `drush updb` solo actualiza las claves de correo (`myapi_update_7041`) y una segunda ejecución no encuentra nada pendiente.
- [ ] Las notificaciones de specs 109-113 siguen funcionando idénticas, sin ningún cambio de comportamiento.
- [ ] Ninguna función existente de `myapi_service_request_close()` cambia de firma ni de comportamiento — solo se agrega la construcción de `$rating` y la llamada al orquestador.

**No regresión y robustez**
- [ ] El `200` de `PUT /api/v1/service-requests/{id}/close` conserva la respuesta de spec 108 (`service_request` + `rating_id`), byte por byte, incluido el caso degradado (categoría borrada).
- [ ] Un cierre hecho desde el back office (formulario de nodo, drush, o creación manual de un `service_rating`) **no** dispara ningún aviso. El disparo vive solo en `myapi_service_request_close()`.
- [ ] Un fallo al encolar (cola caída, dirección inválida) queda en `watchdog` y no impide el `200` ni deshace el cierre.
- [ ] `./vendor/bin/phpunit` en verde, incluida toda la suite previa.
- [ ] `drush cc all` no reporta errores.

**Documentación**
- [ ] `docs/service-request-notifications.md` documenta la sección «Request closed / rated (SPEC 114)» completa, con las dos ramas del email a `backend`.
- [ ] `docs/notification.md` documenta el `type` `service_request_rated`.
- [ ] `docs/service-request.md` enlaza a la sección nueva desde el bloque de `PUT .../close`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Destinatario del aviso de "cierre" | Solo `backend` | También el residente que cerró | Elección del usuario, aclarada tras pregunta: la mención inicial a "el solicitante" era para `backend`, no para el residente. Mismo criterio que specs 109-113 con quien ejecuta la acción: ya recibe el `200`. |
| Alcance de la notificación de cierre | Las dos formas de cierre (con y sin calificación) | Solo la forma con proveedor | Elección del usuario. Toda solicitud que llega a `closed` es información operativa para `backend`, la haya o no adjudicada. |
| Email a `backend` por cierre + rating en la misma llamada | **Un solo email combinado**, contenido condicional | Dos emails separados (uno de cierre, otro de calificación) | Elección del usuario. Cerrar y calificar son un solo acto atómico (spec 108); un solo correo refleja ese acto sin duplicar el asunto. |
| Contenido del aviso al proveedor calificado | Estrellas y comentario completos | Solo estrellas, o un aviso genérico sin detalle | Elección del usuario. A diferencia del perdedor de una puja (spec 112), aquí no hay competencia que proteger: es la opinión del residente sobre su propio trabajo. |
| `source_type` del aviso al proveedor | Constante nueva `service_rating`, `source_nid` = nid de la calificación | Reutilizar `service_offer` con el nid de la oferta adjudicada | Una `direct` no tiene oferta (`field_assigned_offer` vacío); la calificación, en cambio, **siempre** existe cuando este aviso dispara. Apuntar a la calificación cubre los dos orígenes (`assigned` y `direct`) sin un caso especial. |
| Canales | Bandeja + push + email para el proveedor calificado; **solo email** para `backend` | Push/bandeja también para `backend` | Mismo criterio que el resto de emails admin del módulo (specs 109-113): el rol no tiene la app. |
| Cómo se obtienen el proveedor y su nombre | Reutilizar `$provider_nid` / `$provider_name` ya resueltos por la compuerta de spec 108 (condición 7) | Una consulta o `node_load()` nuevo en la orquestación | Ya están cargados — Drupal 7 cachea la entidad estáticamente — así que no hay coste adicional ni una segunda fuente de verdad para el mismo dato. |
| Fuente del `$context` de la notificación | Campos de `$node` (`field_condominium`, `field_unit`, `title`) | Los datos ya resueltos de `$row` (el detalle post-escritura) | Mismo motivo que spec 113: `$row` puede ser `FALSE` en el caso degradado que spec 108 ya contempla (categoría borrada); construir el contexto desde `$node` no depende de que la respuesta HTTP se haya podido construir. |
| Ubicación del código | Se amplía `includes/myapi.service_request_notification.inc` (specs 109-113) | Include nuevo | Mismo dominio de evento (`service_request`/`service_rating`), mismo include, mismo criterio que los cinco specs anteriores. |
| Deep link | `target = "service_request_provider"`, mismo que specs 109-113 | Un target propio de este aviso, o uno de detalle de calificación | No existe un detalle de calificación individual; se reutiliza la misma vista de proveedor. |
| Idioma de los textos | Fijos en español, sin `myapi_t()` | Traducir vía catálogo i18n | Mismo criterio que los ocho triggers existentes: no hay `Accept-Language` disponible para destinatarios que no son quien hizo la petición. |
| Entrega | Push y email por cola, en el siguiente cron | Envío síncrono dentro del `PUT` | Mismo motivo que specs 109-113: no añadir latencia al `200` del cierre, y evitar que un SMTP colgado bloquee el endpoint. |
| Backfill | Ninguno — no aplica | — | No hay columna nueva ni cierres retroactivos que notificar. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El email a `backend` mezcla dos ramas de contenido en una sola función.** Un error de lógica podría filtrar la línea `Motivo` en un cierre con calificación, o viceversa. | Cubierto explícitamente por tests unitarios que verifican que las líneas de una rama nunca aparecen en la otra (ver Plan, paso 5). |
| **`$provider_name` puede ser `NULL`** si el proveedor fue borrado entre la compuerta y el enganche de este spec (ventana mínima, dentro del mismo `PUT`). | Mismo criterio que specs 109-113: cae al `—` de siempre en los textos; no rompe el armado. |
| **Un operador que crea o edita un `service_rating` desde el back office** no dispara ningún aviso — el disparo vive solo en `myapi_service_request_close()`. | Aceptado, mismo criterio que specs 109-113: es una limitación conocida y documentada del patrón, no un caso nuevo. |
| **El comentario del residente puede contener texto largo (hasta 1000 caracteres)** en el push y el email al proveedor. | Mismo compromiso que el resto del módulo: no se trunca, se envía completo — es la decisión confirmada por el usuario. |
