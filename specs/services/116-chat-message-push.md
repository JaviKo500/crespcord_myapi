# 116 — Aviso de mensaje nuevo del chat (`POST /api/v1/chat/threads/%/notify`)

- **Estado:** Approved
- **Fecha:** 2026-09-01
- **Dependencias:**
  - `115-chat-token` (Implemented) — dueña de la **regla de pertenencia**, de `includes/myapi.chat.inc`, de la convención `service_offers/{nid}` y del recurso `resources/chat.resource.inc`. Su propio «Fuera de alcance» nombra este spec: «Notificar un mensaje nuevo. Ni push ni bandeja… es el spec hermano», y su tabla de riesgos lo cierra con «Es la primera cosa que hay que hacer después de esta».
  - `109-service-request-created-notifications` (Implemented) — dueña de `myapi_service_request_provider_uids()`, de `myapi_service_request_node_title()` y del criterio de que **la audiencia de un proveedor son todas las cuentas de `field_provider_users`**, con o sin el rol.
  - `110-service-offer-received-notification` (Implemented) — precedente exacto de los constructores de texto puros (`myapi_service_offer_push_title()` / `_body()`) y de la resolución del nombre del residente (perfil de SPEC 54 primero, `$account->name` de reserva).
  - `25-notifications-inbox-boletin` (Implemented) — dueña de `includes/myapi.onesignal.inc`, de la cola `myapi_onesignal_push` y de `myapi_notification_create()`. **Este spec usa la capa de transporte y NO usa `myapi_notification_create()`** — ver Decisión 3.
  - `78-provider-role` (Implemented) — `myapi_provider_role_provider_ids()`, la única definición de «qué proveedores son de esta cuenta».
  - `03-i18n-mensajes-respuestas` (Implemented) — el catálogo. Este spec **no le añade ni una clave**.
- **Objetivo:** Que un mensaje escrito en el chat llegue como **push al otro lado de la conversación**, sin que Drupal vea, almacene ni transporte el texto del mensaje, y sin montar un runtime nuevo.

Cuatro notas que la cabecera fija, y que son la continuación literal de las del 115:

- **Drupal sigue sin ver un solo mensaje.** Este spec no lee la Realtime Database, no escribe en ella y no recibe el texto. Recibe **un aviso de que hubo un mensaje en un hilo**, resuelve a quién le toca enterarse y manda un banner que dice quién escribió y sobre qué solicitud. El contenido del chat no pasa por este servidor ni por OneSignal.
- **El disparo lo da el cliente que escribió**, porque es el único proceso que sabe que hubo un mensaje sin que nadie tenga que vigilar Firebase. Las otras dos formas —una Cloud Function con trigger en la RTDB, o esa función llamando de vuelta a esta API— están evaluadas y descartadas **por ahora** en la Decisión 1, con el precio anotado en Riesgos.
- **Ni una fila en `myapi_notifications`.** Un mensaje de chat **no entra en la bandeja**. El motivo no es de gusto: el inbox no tiene forma de enterarse de que leíste el chat —eso ocurre en Firebase— así que la fila se quedaría no leída para siempre y el badge quedaría sucio de forma permanente. Decisión 3.
- **Ni un campo, ni una tabla, ni un `hook_update_N`, ni una fila escrita.** Los tres campos de SPEC 77 (`field_firebase_path`, `field_chat_opened_at`, `field_last_message_at`) **siguen vacíos al terminar este spec**, igual que al terminar el 115.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.chat.inc`** (modificar) — la regla de pertenencia gana su segunda dirección: hoy contesta «¿qué hilos son de este uid?» y necesita contestar «¿quiénes son los dos lados de este hilo?».
  - `myapi_chat_thread_base_query()` (**nueva**) — **refactor sin cambio de comportamiento**: devuelve el `SelectQuery` con los seis joins que hoy están escritos **dos veces** dentro de `myapi_chat_offer_nids_for_uid()` (oferta viva → su proveedor → solicitud publicada → `field_assigned_provider` igual a ese proveedor). Las dos consultas existentes pasan a construirse sobre ella y la nueva de abajo también. Es la Regla 3 de `CLAUDE.md`: el criterio se comparte, no se copia — y con tres copias el día que cambie una constante de estado sería el día que el chat y el aviso dejen de coincidir.
  - `myapi_chat_thread_row($offer_nid)` (**nueva**) — la base + `no.nid = $offer_nid`, más el join a `field_requester` y los **LEFT JOIN** a `field_unit` y `field_condominium` de la solicitud. Devuelve `['offer_nid', 'request_nid', 'request_title', 'requester_uid', 'provider_id', 'unit_id', 'condominium_id']` o **`NULL`**. `NULL` es una sola respuesta para tres cosas distintas —la oferta no existe, no está viva, o su solicitud no está adjudicada a su proveedor— y eso es deliberado (Decisión 6). **Los dos joins del contexto son LEFT y no INNER**, por el motivo que SPEC 91 ya dejó escrito: `field_unit` es obligatorio en el bundle desde SPEC 86 pero se añadió sin *backfill*, así que una solicitud vieja puede no tener fila — y un hilo no puede desaparecer por eso.
  - `myapi_chat_thread_side(array $thread, array $provider_uids, $uid)` (**nueva**, pura) — `'resident'`, `'provider'` o `NULL`. El orden importa: quien sea residente **y** empleado del proveedor cuenta como residente, porque el hilo es de su solicitud.
  - `myapi_chat_notify_recipients(array $thread, array $provider_uids, $side, $sender_uid)` (**nueva**, pura) — el **otro** lado, menos el emisor.
  - `myapi_chat_sender_label(array $thread, $side, $sender_uid)` (**nueva**) — el nombre que verá el destinatario: el **nombre comercial del proveedor** (`node.title`) cuando escribe el proveedor, el **nombre del residente** (perfil de SPEC 54, `$account->name` de reserva) cuando escribe el residente. Nunca el nombre del empleado: quien contrata habla con la empresa.
  - `myapi_chat_message_push_title($sender_label)` y `myapi_chat_message_push_body($request_title)` (**nuevas**, puras) — dos líneas, sin una palabra del mensaje.
  - `myapi_chat_notify_allowed($offer_nid, $uid)` y `myapi_chat_notify_register($offer_nid, $uid)` (**nuevas**) — el *debounce* por hilo y destinatario, sobre la Flood API que el módulo ya envuelve.
  - Tres constantes nuevas: `MYAPI_CHAT_DEEP_LINK_TARGET` (`'chat'`), `MYAPI_CHAT_NOTIFICATION_TYPE` (`'chat_message'`) y `MYAPI_CHAT_PUSH_TTL` (`3600`).
- **`resources/chat.resource.inc`** (modificar) — `myapi_chat_notify_dispatch($offer_nid)` (solo `POST`; el `405` antes de todo, como todo despachador del módulo) y `myapi_chat_notify($offer_nid)`.
- **`myapi.module`** (modificar) — **una** ruta: `api/v1/chat/threads/%/notify`, `page arguments` `[4]`, `file` `resources/chat.resource.inc`. Seis componentes; no compite con `api/v1/chat/token`, que tiene tres.
- **`includes/myapi.onesignal.inc`** (modificar) — `myapi_onesignal_send()` gana un **quinto parámetro opcional** `array $options = []` con cuatro claves y nada más: `collapse_id`, `thread_id`, `android_group` y `ttl`, más un `timeout` que por defecto sigue siendo 30. Ni un llamador actual cambia (el único es el worker de la cola) y el comportamiento sin `$options` es byte a byte el de hoy.
- **`includes/myapi.flood.inc`** (modificar) — cuatro entradas nuevas en los tres arrays estáticos de defaults: `myapi_flood_chat_notify_ip_limit` = 600 / `_window` = 3600, y `myapi_flood_chat_notify_thread_limit` = 1 / `_window` = 60. Ni una línea de lógica cambia.
- **`myapi.info`** — **sin cambios**: no hay fichero nuevo. Aun así el `drush cc all` es obligatorio, porque **hay una ruta nueva**.
- **`docs/chat.md`** (modificar) — el endpoint con la plantilla de `CLAUDE.md`, la tabla del payload del push y **la frase que el equipo de la app tiene que leer**: escribir en Firebase y llamar a esta ruta son dos pasos, en ese orden, y el segundo es *fire-and-forget*.
- **`tests/unit/ChatNotifyTest.php`** (**nuevo**).
- **`specs/services/115-chat-token.md`** (anotar) — su viñeta «Notificar un mensaje nuevo» y su última fila de Riesgos se marcan **✅ Resuelto por SPEC 116**, con la convención de 104/105/106.

### Fuera de este spec

- **Enterarse del mensaje sin que el cliente avise.** Si el móvil que escribió se muere entre el `set()` de Firebase y este `POST`, el mensaje llega y el aviso no. Es el precio de la Decisión 1 y está en Riesgos con el camino de salida.
- **Callarse cuando el destinatario está mirando el hilo.** Drupal no sabe si tiene la pantalla abierta. Lo resuelve el cliente descartando el banner en *foreground*, y la solución de verdad —presencia en la RTDB— necesita una Cloud Function.
- **La bandeja.** Ver Decisión 3.
- **Correo.** Un mensaje de chat por email sería ruido, y encima llegaría sin el texto, que es justamente lo que no tenemos.
- **Contador de no leídos, orden por último mensaje y `field_last_message_at`.** Eso lo sabe Firebase, que es quien vio el mensaje.
- **Vista previa del texto en el banner.** Decisión 2, con la lista exacta de lo que costaría el día que se quiera.
- **Adjuntos, retención, moderación y back office.** Siguen fuera, igual que en el 115.

---

## Modelo de datos

**Ningún cambio.** Ni campo, ni instancia, ni bundle, ni tabla, ni catálogo, ni `hook_update_N`, ni `drush updb`. Este spec **no escribe una sola fila** en la base de datos de Drupal — con una excepción que conviene nombrar por honestidad: la Flood API escribe en `flood`, su tabla, la misma que ya usan el login y el `chat/token`.

### Quién dispara el aviso

Tres caminos posibles, y el spec elige el primero:

| | Quién detecta el mensaje | Quién sabe a quién avisar | Qué hace falta montar |
|---|---|---|---|
| **A — el cliente avisa** ✅ | El móvil que escribió | Drupal, con la regla de pertenencia que ya existe | **Nada nuevo** |
| B — Cloud Function | Firebase, con un trigger `onWrite` | Nadie: la función no sabe quién es el otro lado | Plan Blaze, un despliegue nuevo, y un nodo `members` en la RTDB escrito por el backend (la Decisión 4 del 115, que se dejó cerrada a propósito) |
| C — híbrido | Firebase | Drupal, llamado por la función | Todo lo de B **más** este mismo endpoint |

El argumento decisivo no es el coste: es que **B no puede resolver los destinatarios sin abrir la puerta que el 115 cerró**. La pertenencia vive en `field_requester`, `field_assigned_provider` y `field_provider_users`, y quien la sabe es Drupal. Cualquier camino que empiece en Firebase acaba preguntándole a esta API — es decir, acaba siendo C, que es A con un salto de más. Ver Decisión 1.

---

## `POST /api/v1/chat/threads/{offer_nid}/notify`

**Autenticación:** requerida (Bearer). **Cuerpo: vacío**, y por el mismo motivo que en el 115: no hay ni una clave que mandar. Quién escribió lo dice el Bearer, en qué hilo lo dice la URL y **qué escribió no se manda a propósito** (Decisión 2). Un cuerpo presente se ignora entero, malformado incluido. **No hay `422` en esta ruta.**

El componente `{offer_nid}` es el **nid de la oferta**, no la ruta del hilo: `service_offers/901` lleva una barra y no cabe en un componente de URL. La ruta del hilo se deriva con `myapi_chat_thread_id()`, que sigue siendo la única definición de la convención.

### Respuesta 200

```json
{
  "success": true,
  "data": {
    "thread": "service_offers/901",
    "recipients": 2,
    "notified": 2,
    "muted": 0
  }
}
```

| Clave | Qué es |
|---|---|
| `thread` | La ruta del hilo, derivada. Va para que el cliente pueda comprobar que habló del hilo que creía. |
| `recipients` | Cuántas cuentas hay al otro lado (el emisor nunca se cuenta). |
| `notified` | A cuántas se les mandó banner en esta llamada. |
| `muted` | A cuántas **no**, porque ya recibieron uno por este mismo hilo dentro de la ventana del *debounce*. `notified + muted === recipients`, siempre. |

**`notified: 0` es un `200`, no un error.** Ni cuando el otro lado no tiene ninguna cuenta activa, ni cuando el *debounce* silenció a todos, ni cuando OneSignal no está configurado, ni cuando la llamada a OneSignal falló. El push es *best-effort* de extremo a extremo, igual que en el resto del módulo: **el mensaje ya está en Firebase y el chat funciona sin banner**. Un `5xx` aquí sólo conseguiría que la app crea que pasó algo con el mensaje. Los fallos reales van a watchdog, que es donde se miran.

### La compuerta, en orden

| # | Condición | Respuesta |
|---|---|---|
| 1 | Método distinto de `POST` | `405 method_not_allowed` — en el despachador, antes del flood, del token y de cualquier consulta |
| 2 | Flood por IP | `429 too_many_attempts` |
| 3 | Sin cabecera / token inválido o caducado | `401 missing_authorization` / `invalid_token` |
| 4 | El `{offer_nid}` no es un hilo, **o no es un hilo de quien llama** | `404 not_found` |

**El paso 4 es un solo `404` para dos cosas distintas, a propósito** (Decisión 6). Distinguir un `403` («existe, pero no es tuyo») de un `404` («no existe») convertiría la ruta en un enumerador de hilos vivos: pidiendo `1..N` cualquiera sabría qué ofertas están adjudicadas y activas. Y el 404 usa la clave **genérica** `not_found`, ya en el catálogo, que es lo que ya hacen `service-offers/%/withdraw` y `%/accept`.

**El `429` va antes del token**, igual que en el 115, y por lo mismo: es un limitador de coste y antes del paso 3 no hay uid contra el que contar. El techo es alto —600 por hora y por IP— porque **chatear son muchas llamadas** y una casa entera comparte IP; lo que de verdad acota el tráfico saliente no es este contador, es el *debounce*. Por eso **no hay un tercer contador por uid**: sería redundante y una cosa más que ajustar.

---

## El push

### Los destinatarios

Salen de `myapi_chat_thread_row()` y de `myapi_service_request_provider_uids()` — **la misma función que usan las notificaciones de SPEC 109-112**, no una consulta nueva:

- **Escribe el residente** → destinatarios: **todas** las cuentas de `field_provider_users` del proveedor adjudicado. La pertenencia es por empresa (criterio del 115), así que el aviso también.
- **Escribe el proveedor** → destinatario: el `field_requester` de la solicitud, y sólo él.
- **El emisor nunca recibe su propio push**, ni en otro dispositivo. Y un compañero del proveedor **no** recibe banner cuando escribe su compañero: el mensaje lo mandó su empresa; el hilo ya lo ven los dos.

### El texto

Dos líneas, ambas puras y en español fijo, como todos los constructores de push del módulo:

```
Nuevo mensaje de Ferretería El Tornillo
Solicitud: Fuga en el calentador
```

- El título nombra **al otro lado**, no al empleado.
- El cuerpo es el `node.title` de la solicitud, que es contexto que ese destinatario **ya recibió** en las notificaciones de SPEC 109-112: no revela nada nuevo.
- Ni una palabra del mensaje. Ver Decisión 2.
- `myapi_onesignal_truncate_body()` sigue recortando a 200 como con cualquier otro push, aunque aquí nunca se llegue.

### El `data`

**Las mismas siete claves que `myapi_notification_create()`**, para que la app no tenga que aprender un segundo formato, más una octava:

| Clave | Valor |
|---|---|
| `target` | `'chat'` |
| `id` | El nid de la oferta — lo que el deep link necesita para abrir el hilo |
| `thread` | `'service_offers/901'`, la ruta derivada (la octava, y la única que no existe en los otros pushes) |
| `notification_type` | `'chat_message'` |
| `audience` | `'resident'` o `'provider'` — la del **destinatario**, no la del emisor |
| `provider` | El nid del proveedor del hilo |
| `condominium` | El nid del condominio de la solicitud, **para los dos lados** |
| `unit` | El nid de la vivienda **solo cuando el destinatario es el residente**. Al proveedor le va `NULL`, siempre |

#### El contexto: por qué la unidad viaja, y por qué solo hacia un lado

**Hacia el residente viaja, y es obligatorio que viaje.** Una cuenta puede tener más de una vivienda, y la app trabaja siempre «dentro de» una: si el banner abre el hilo sin decir de qué unidad es la solicitud, la app lo abre con el contexto en el que estuviera — que puede ser **otra casa**. `unit` y `condominium` son exactamente lo que la app necesita para **cambiar de contexto antes de pintar el hilo**, y es para lo que SPEC 26 añadió esas dos claves al payload de push. Un aviso de chat que obliga a adivinar la vivienda es un aviso a medias.

**Hacia el proveedor no viaja nunca.** No es una omisión de este spec: es la regla que SPEC 109 dejó escrita en el código, `'unit_id' => NULL` con el comentario «Never the unit: a provider does not learn which home asked until they open the detail endpoint». El proveedor sí recibe `condominium` —ese mismo aviso de SPEC 109 ya se lo manda— porque saber a qué conjunto va no es saber a qué puerta. Que el chat aflojara esa regla sería filtrar por la puerta de atrás un dato que el endpoint de detalle protege por la de delante.

**Si `unit` llega `NULL` al residente** (solicitud anterior al *backfill* de SPEC 86), la app abre el hilo **sin cambiar de contexto**, que es lo que hace hoy cualquier notificación sin unidad; no es un caso de error.

### Colapso y TTL

Las cuatro claves nuevas de `$options`, y las cuatro tienen un motivo:

| Opción | Valor | Por qué |
|---|---|---|
| `collapse_id` | `'chat_' . $offer_nid` | Veinte mensajes seguidos son **un** banner que se reemplaza, no veinte apilados |
| `thread_id` | La ruta del hilo | Agrupación nativa en iOS |
| `android_group` | `'chat_' . $offer_nid` | Lo mismo en Android |
| `ttl` | `3600` | Un aviso de chat de hace seis horas no sirve de nada. Sin TTL, un teléfono apagado toda la tarde recibe la avalancha entera al encenderse |

### El *debounce*, que es lo que hace esto habitable

**Como máximo un banner por hilo y por destinatario cada 60 segundos.** No hay tabla nueva: es la Flood API que el módulo ya envuelve, con el identificador compuesto `{offer_nid}:{uid}`, límite 1 y ventana 60.

- Se consulta con `myapi_flood_is_allowed()` y **no** con `myapi_flood_check()`: un destinatario silenciado no es un error del que llama, es un `muted++` y un `200`.
- **Se registra sólo cuando el banner sale de verdad.** Si OneSignal no está configurado o la llamada falló, no se quema la ventana: el siguiente mensaje vuelve a intentarlo.
- El silencio **no** sabe si el destinatario leyó, y no hace falta que lo sepa: ya le avisamos hace menos de un minuto.
- **Efecto secundario buscado:** si la app reintenta el `POST` (o lo manda dos veces por una reconexión), el segundo se silencia solo. El endpoint es idempotente dentro de la ventana sin una línea escrita para serlo.

### Síncrono, no en cola

`myapi_onesignal_send()` directo, con `timeout` **5**, y no `DrupalQueue`.

La cola `myapi_onesignal_push` se drena con `drush queue-run` **cada minuto** (`docs/notifications-produccion.md`). Un minuto de retraso está bien para un boletín y es **inaceptable para un chat**: el banner llegaría después de la respuesta del otro. Y el coste que la cola evita —bloquear al que llama— aquí no existe: **nadie está esperando esta respuesta**, la app la manda y sigue. El `timeout` baja de 30 a 5 porque lo que se protege ya no es al usuario, es al proceso PHP-FPM.

---

## Configuración

**Ninguna variable nueva.** Se usan las dos de OneSignal que ya están (`myapi_onesignal_app_id`, `myapi_onesignal_rest_api_key`) y las cuatro de flood, que como todas las del módulo tienen su valor por defecto en el código y sólo hace falta tocarlas con `variable_set()` si se quiere afinar la ventana del *debounce* en producción.

## i18n

**Ni una clave nueva.** Todo lo que responde este endpoint —`method_not_allowed`, `too_many_attempts`, `missing_authorization`, `invalid_token`, `not_found`— ya está en los dos idiomas. Los textos del push no pasan por el catálogo, igual que los de SPEC 109-113: son español fijo, porque el catálogo traduce respuestas de la API y esto es un banner.

---

## Tests — `tests/unit/ChatNotifyTest.php`

Todo sin sitio arrancado, sobre las funciones puras y el mismo *fixture* de consultas que ya usa `ChatTokenTest`:

- **`myapi_chat_thread_row()`, contra la tabla de la regla de pertenencia del 115**, fila por fila: adjudicada, directa en `sent`, directa sin oferta, perdedora `rejected`, retirada `withdrawn`, cancelada y cerrada. Es la **misma** tabla, y ese es el punto: si esta consulta y la del token se separaran, aquí se vería.
- **El refactor no cambia nada:** `ChatTokenTest` entero sigue en verde tras extraer `myapi_chat_thread_base_query()`. Es el único criterio que hace seguro tocar el fichero del 115.
- **`myapi_chat_thread_side()`**: residente, empleado del proveedor, segundo empleado, tercero ajeno (`NULL`), y el caso raro de una cuenta que es las dos cosas → `'resident'`.
- **`myapi_chat_notify_recipients()`**: el residente escribe → los dos empleados; un empleado escribe → sólo el residente; **el emisor no está nunca en la lista**; el compañero tampoco cuando escribe su compañero.
- **`myapi_chat_sender_label()`**: nombre comercial del proveedor cuando escribe el proveedor; nombre de perfil del residente cuando escribe el residente; `$account->name` cuando el perfil está vacío.
- **Los dos constructores de texto**: que el título lleva el nombre del emisor y **que el cuerpo no contiene el mensaje** — no hay parámetro por donde pudiera entrar, y el test lo deja escrito.
- **El `data`**: las ocho claves; `audience` la del destinatario; `condominium` a los dos lados; **`unit` con valor hacia el residente y `NULL` hacia el proveedor**, con la solicitud teniendo vivienda — es la aserción que impide que la regla de SPEC 109 se pierda en un refactor.
- **El *debounce***: el primero pasa, el segundo dentro de la ventana se silencia, el de **otro** destinatario del mismo hilo pasa, el del **mismo** destinatario en **otro** hilo pasa, y `notified + muted === recipients` en todos ellos.
- **`myapi_onesignal_send()` sin `$options` produce exactamente el payload de hoy** — la garantía de no regresión de los boletines y de las notificaciones de servicios.

---

## Decisiones

1. **El disparo lo da el cliente que escribió (camino A), no un trigger de Firebase.** No por ahorrar plan Blaze, sino porque **B no sabe a quién avisar**: la pertenencia vive en tres campos de Drupal, así que cualquier diseño que arranque en Firebase acaba llamando a esta misma API (camino C) o exige el nodo `members` en la RTDB que la Decisión 4 del 115 dejó cerrada a conciencia. *El precio, aceptado y anotado en Riesgos:* si el cliente no llama, no hay banner. *La salida, si algún día duele:* montar C reutilizando este endpoint tal cual, con una cuenta de servicio — el contrato no cambia.
2. **El banner no lleva el texto del mensaje.** Tres motivos, en orden de peso: (a) **Drupal no lo ha visto** — lo mandaría el emisor, así que el servidor estaría publicando en un banner un texto que no puede contrastar con lo que de verdad se escribió; (b) el contenido del chat no atraviesa ni este servidor ni OneSignal, que es exactamente lo que promete el 115; (c) «Nuevo mensaje de X / Solicitud: Y» ya basta para decidir si abrir la app. *Si algún día se quiere la vista previa*, el coste está medido: una clave `preview` en el cuerpo, su validación de longitud, su saneado, un `422` que hoy no existe en esta ruta, y aceptar (a) y (b) por escrito.
3. **Push sí, bandeja no.** `myapi_notification_create()` **no** se usa aquí, porque siempre inserta filas. El argumento decisivo: **el inbox no puede enterarse de que leíste el chat** —eso ocurre en Firebase, donde este módulo no mira—, así que la fila se quedaría `is_read = 0` para siempre y el badge de notificaciones quedaría permanentemente sucio. Un chat ya tiene su propia lista y sus propios no leídos; duplicarlos en `myapi_notifications` es una segunda fuente de verdad que nadie va a marcar. *Consecuencia asumida:* es el primer push del módulo sin fila de bandeja, y `docs/chat.md` lo dice con esas palabras.
4. **Síncrono, no en cola.** La cola se drena cada minuto y eso es la mitad de una conversación de retraso. Nadie espera esta respuesta, así que el motivo por el que existe la cola no aplica.
5. **El *debounce* es la Flood API, no una tabla nueva.** El módulo ya la envuelve, ya tiene defaults en código y ya se ajusta con `variable_set()` sin desplegar. Una tabla `myapi_chat_last_push` haría lo mismo con un `hook_update_N` de propina.
6. **Un solo `404` para «no es un hilo» y «no es tu hilo».** Distinguirlos convertiría la ruta en un enumerador de ofertas vivas. Y se usa la clave genérica `not_found`, que es lo que ya hacen las otras rutas de oferta.
7. **La ruta lleva el nid de la oferta, no la ruta del hilo.** `service_offers/901` tiene una barra. La convención se sigue derivando en un único sitio, `myapi_chat_thread_id()`.
8. **`myapi_chat_thread_base_query()` se extrae aunque el 115 escribiera las dos consultas a mano.** Con una tercera copia, las tres se separan el día que cambie una constante de estado — y se separarían en silencio, que es el fallo que este chat ya estuvo a punto de tener una vez. `ChatTokenTest` en verde es la prueba de que el refactor no cambia nada.
9. **El nombre del emisor es el de la empresa, no el del empleado.** Coherente con SPEC 110, y con lo que el residente cree: contrató a un proveedor, no a una persona.
10. **`notified: 0` responde `200`.** Nada de lo que puede ir mal aquí —OneSignal caído, sin configurar, sin cuentas al otro lado— tiene arreglo en el cliente ni afecta al mensaje, que ya está entregado. Un `5xx` sólo haría que la app dude de un mensaje que sí llegó.
11. **Flood por IP y *debounce* por hilo, y ningún contador por uid.** Los dos que hay cubren las dos cosas distintas que hay que acotar: las consultas (IP) y el tráfico saliente (hilo+destinatario). Un tercero sería un número más que nadie sabría ajustar.
12. **`$options` en `myapi_onesignal_send()`, no una función nueva.** Cuatro claves opcionales sobre el payload que ya se arma. Sin ellas, el comportamiento es idéntico al de hoy, y el único llamador actual —el worker de la cola— no se toca.
13. **El push lleva el contexto de la solicitud, y la unidad solo hacia el residente.** Las dos claves que SPEC 26 puso en el payload para esto (`unit`, `condominium`) se rellenan de verdad: sin ellas, un residente con dos viviendas abre el hilo en la casa equivocada, porque la app no tiene de dónde sacar cuál es. Hacia el proveedor va `condominium` y **nunca** `unit`, que es la regla textual de SPEC 109 —«a provider does not learn which home asked until they open the detail endpoint»—; relajarla aquí sería filtrar por el chat lo que el endpoint de detalle protege. *Precio:* dos `LEFT JOIN` más en la única consulta del endpoint.

---

## Riesgos

| Riesgo | Mitigación / precio aceptado |
|---|---|
| **Si el cliente no llama, no hay aviso.** La app se cierra, pierde la red o falla entre el `set()` de Firebase y este `POST`. | Aceptado. El **mensaje sí llegó** —lo escribió en Firebase antes— así que lo que se pierde es el banner, no la conversación, y el destinatario lo ve al abrir la app. La app debe llamar **después** del `set()` y no antes, y puede reintentar sin miedo: el *debounce* absorbe el duplicado. La salida, si duele en producción, es el camino C. |
| **Un cliente puede avisar sin haber escrito nada.** | Sólo sobre **hilos suyos**, y con un banner cada 60 segundos que dice «Nuevo mensaje de X» sin texto. Lo peor que consigue es molestar a alguien con quien ya podía chatear — y podía molestarle mucho más escribiéndole de verdad. |
| **El destinatario está mirando el hilo y le entra un banner.** | El cliente lo descarta en *foreground*. La solución de verdad es presencia en la RTDB, y esa vive del lado de Firebase. |
| **Ráfaga de mensajes = ráfaga de pushes.** | `collapse_id` + agrupación por hilo + *debounce* de 60 s. Es el mismo problema que resuelve cualquier app de mensajería y se resuelve igual. |
| **El banner nombra la solicitud, y va a la barra de notificaciones de un teléfono bloqueado.** | Es el mismo `node.title` que ese mismo destinatario ya recibió en las notificaciones de SPEC 109-112, así que no se filtra nada nuevo. El **mensaje** no viaja, que es lo que de verdad importa. |
| **La app abre el hilo en la vivienda equivocada.** Un residente con dos casas y un banner sin contexto. | Resuelto: `unit` y `condominium` viajan hacia el residente y la app cambia de contexto antes de pintar (Decisión 13). Cuando `unit` es `NULL` —solicitud anterior al *backfill* de SPEC 86— la app no cambia de contexto, igual que con cualquier otra notificación sin unidad. |
| **Se toca `includes/myapi.chat.inc`, que hoy funciona.** | El refactor es de construcción de consulta, no de criterio, y `ChatTokenTest` (40 casos, la tabla de pertenencia entera) es el detector. Si se pone rojo, el refactor está mal. |
| **`timeout` de 5 s síncrono en la petición.** | Un proceso PHP-FPM ocupado hasta 5 s por mensaje enviado. Con el *debounce*, una conversación viva hace **una** llamada saliente por minuto y hilo, no una por mensaje. |

---

## Pasos de implementación

1. Extraer `myapi_chat_thread_base_query()` en `includes/myapi.chat.inc` y reconstruir sobre ella las dos consultas del 115. **Correr `ChatTokenTest` antes de seguir**: tiene que quedar en verde sin tocar ni una aserción.
2. Añadir en el mismo fichero `myapi_chat_thread_row()`, `myapi_chat_thread_side()`, `myapi_chat_notify_recipients()`, `myapi_chat_sender_label()`, los dos constructores de texto, el par del *debounce* y las tres constantes.
3. Añadir `$options` a `myapi_onesignal_send()`.
4. Añadir `myapi_chat_notify_dispatch()` y `myapi_chat_notify()` en `resources/chat.resource.inc`.
5. Registrar la ruta en `hook_menu()`. **No hay `files[]` nuevo**: no hay fichero nuevo.
6. Añadir las cuatro entradas de flood.
7. `drush cc all` — obligatorio: hay una ruta nueva. **No hay `drush updb`**: ni campo, ni tabla, ni `hook_update_N`.
8. Documentar en `docs/chat.md` el endpoint, el payload y los dos pasos que la app tiene que dar en orden.

## Criterios de aceptación

Casillas booleanas. Ninguna dice «funciona bien».

**La compuerta**

- [ ] `GET` sobre la ruta → `405 method_not_allowed`, sin tocar la base de datos.
- [ ] Sin cabecera → `401 missing_authorization`.
- [ ] Un `{offer_nid}` inexistente → `404 not_found`.
- [ ] Un hilo real del que quien llama no forma parte → `404 not_found`, **el mismo cuerpo** que el caso anterior.
- [ ] Una oferta `rejected` o `withdrawn`, y una de una solicitud cancelada → `404`.
- [ ] Una solicitud **cerrada** con su oferta `selected` → `200`: el hilo sobrevive al cierre (SPEC 115, Decisión 9) y el aviso también.

**Los destinatarios**

- [ ] Residente escribe → las dos cuentas del proveedor reciben; el residente no.
- [ ] Empleado del proveedor escribe → sólo el residente recibe; el otro empleado no; el emisor tampoco.
- [ ] Proveedor sin ninguna cuenta activa → `200` con `recipients: 0`, `notified: 0`.
- [ ] El título nombra la **empresa** cuando escribe el proveedor, y el nombre de perfil del residente cuando escribe el residente.

**El push**

- [ ] El `data` lleva `target: "chat"`, `id` = nid de la oferta y `thread` = `service_offers/{nid}`.
- [ ] `audience` es la del **destinatario**.
- [ ] Al **residente** le llegan `unit` y `condominium` de la solicitud, con los nids reales.
- [ ] Al **proveedor** le llega `condominium` y `unit: null`, **siempre**, aunque la solicitud tenga vivienda.
- [ ] Una solicitud sin fila en `field_unit` sigue teniendo hilo y sigue avisando, con `unit: null` a los dos lados.
- [ ] El cuerpo del banner **no contiene el texto del mensaje** — ni puede, porque no viaja en la petición.
- [ ] `collapse_id`, `thread_id`, `android_group` y `ttl: 3600` salen en el payload de OneSignal.

**El *debounce***

- [ ] Dos avisos seguidos en el mismo hilo → el segundo responde `muted: 1`, `notified: 0`, y **una sola** llamada saliente.
- [ ] Pasados 60 s, el siguiente vuelve a pasar.
- [ ] Un aviso silenciado para A no silencia a B en el mismo hilo, ni a A en otro hilo.
- [ ] Con OneSignal sin configurar: `200`, `notified: 0`, aviso en watchdog y **la ventana sin quemar** (el siguiente mensaje reintenta).

**No regresión**

- [ ] `ChatTokenTest` en verde sin cambios: el refactor de la consulta no movió la regla de pertenencia.
- [ ] El payload de OneSignal de un boletín y de una notificación de servicio es idéntico al de antes de este spec.
- [ ] Ni una fila nueva en `myapi_notifications` por un mensaje de chat.
- [ ] Ni campo, ni tabla, ni `hook_update_N`; los tres campos de SPEC 77 siguen vacíos.
