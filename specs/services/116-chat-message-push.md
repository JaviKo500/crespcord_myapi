# 116 — Aviso de mensaje nuevo del chat (`POST /api/v1/chat/threads/%/notify`)

- **Estado:** Implemented
- **Fecha:** 2026-09-01 (revisado el mismo día: ver «Revisión — la vista previa», abajo)
- **Dependencias:**
  - `115-chat-token` (Implemented) — dueña de la **regla de pertenencia**, de `includes/myapi.chat.inc`, de la convención `service_offers/{nid}` y del recurso `resources/chat.resource.inc`. Su propio «Fuera de alcance» nombra este spec: «Notificar un mensaje nuevo. Ni push ni bandeja… es el spec hermano», y su tabla de riesgos lo cierra con «Es la primera cosa que hay que hacer después de esta».
  - `109-service-request-created-notifications` (Implemented) — dueña de `myapi_service_request_provider_uids()`, de `myapi_service_request_node_title()` y del criterio de que **la audiencia de un proveedor son todas las cuentas de `field_provider_users`**, con o sin el rol.
  - `110-service-offer-received-notification` (Implemented) — precedente exacto de los constructores de texto puros (`myapi_service_offer_push_title()` / `_body()`) y de la resolución del nombre del residente (perfil de SPEC 54 primero, `$account->name` de reserva).
  - `25-notifications-inbox-boletin` (Implemented) — dueña de `includes/myapi.onesignal.inc`, de la cola `myapi_onesignal_push` y de `myapi_notification_create()`. **Este spec usa la capa de transporte y NO usa `myapi_notification_create()`** — ver Decisión 3.
  - `78-provider-role` (Implemented) — `myapi_provider_role_provider_ids()`, la única definición de «qué proveedores son de esta cuenta».
  - `79-service-categories-list` (Implemented) — dueña de `includes/myapi.text.inc` y de `myapi_text_to_plain()`, el saneador de texto plano del módulo. **La revisión lo reutiliza tal cual y no escribe uno nuevo**: ya quita marcado, ya decodifica entidades y ya colapsa los saltos de línea, que son las tres cosas que una vista previa necesita.
  - `03-i18n-mensajes-respuestas` (Implemented) — el catálogo. Este spec **no le añade ni una clave**, tampoco después de la revisión: la vista previa no produce ni un mensaje de error.
- **Objetivo:** Que un mensaje escrito en el chat llegue como **push al otro lado de la conversación**, con una **vista previa del texto**, sin que Drupal almacene el mensaje y sin montar un runtime nuevo.

Cuatro notas que la cabecera fija, y que son la continuación literal de las del 115:

- **Drupal sigue sin leer y sin guardar un solo mensaje, pero ahora lo transporta.** Este spec no lee la Realtime Database, no escribe en ella y **no escribe el texto en ninguna tabla**. Lo que sí hace desde la revisión es **recibir una vista previa del cliente y reenviarla a OneSignal**, para que el banner diga quién escribió, sobre qué solicitud y **qué dijo**. La diferencia con la versión original es deliberada y su precio está en la Decisión 2: el texto pasa por este servidor y por OneSignal, y se ve en una pantalla bloqueada. Lo que **no** cambia es que la conversación sigue viviendo solo en Firebase — aquí el texto atraviesa, no se queda.
- **El disparo lo da el cliente que escribió**, porque es el único proceso que sabe que hubo un mensaje sin que nadie tenga que vigilar Firebase. Las otras dos formas —una Cloud Function con trigger en la RTDB, o esa función llamando de vuelta a esta API— están evaluadas y descartadas **por ahora** en la Decisión 1, con el precio anotado en Riesgos.
- **Ni una fila en `myapi_notifications`.** Un mensaje de chat **no entra en la bandeja**. El motivo no es de gusto: el inbox no tiene forma de enterarse de que leíste el chat —eso ocurre en Firebase— así que la fila se quedaría no leída para siempre y el badge quedaría sucio de forma permanente. Decisión 3.
- **Ni un campo, ni una tabla, ni un `hook_update_N`, ni una fila escrita.** Los tres campos de SPEC 77 (`field_firebase_path`, `field_chat_opened_at`, `field_last_message_at`) **siguen vacíos al terminar este spec**, igual que al terminar el 115. La vista previa **tampoco se guarda**: se recibe, se sanea, se manda y se olvida. **✅ Resuelto por SPEC 117** en su primera mitad: este endpoint es hoy el escritor de `field_chat_opened_at` (el primer aviso del hilo) y de `field_last_message_at` (todos), fuera del `if ($allowed)` y del `if ($sent)`, porque la columna se llama `last_message_at` y no `last_push_at`. La segunda mitad **sigue en pie y no es un descuido**: la vista previa se sigue sin guardar — lo que se escribe es *cuándo*, nunca *qué*. Ver `specs/services/117-chat-fields-mirror.md`.

### Revisión — la vista previa

La primera versión de este spec cerró la Decisión 2 con «el banner no lleva el texto del mensaje» y dejó medido lo que costaría el día que se quisiera. **Se quiso**, el mismo día y antes de mergear la rama, así que el coste se paga aquí en vez de en un spec 117 — el endpoint no había llegado a producción, de modo que no hay contrato publicado que romper.

Lo que la revisión cambia, y nada más:

- El cuerpo del `POST` **deja de estar vacío**: acepta una clave `preview`, **opcional**.
- El banner pasa de dos líneas a **tres**, y la tercera es el texto.
- La Decisión 2 se **invierte**, conservando escrito su razonamiento original y lo que se acepta a cambio.

Lo que la revisión **no** cambia: los destinatarios, el `data`, el colapso, el TTL, el *debounce*, la compuerta, la ausencia de bandeja y la ausencia de escrituras. **Y la ruta sigue sin tener un `422`.**

---

## Alcance

### Dentro de este spec

- **`includes/myapi.text.inc`** — **sin cambios**. Se usa `myapi_text_to_plain()` tal cual está.
- **`includes/myapi.chat.inc`** (modificar) — la regla de pertenencia gana su segunda dirección: hoy contesta «¿qué hilos son de este uid?» y necesita contestar «¿quiénes son los dos lados de este hilo?».
  - `myapi_chat_thread_base_query()` (**nueva**) — **refactor sin cambio de comportamiento**: devuelve el `SelectQuery` con los seis joins que hoy están escritos **dos veces** dentro de `myapi_chat_offer_nids_for_uid()` (oferta viva → su proveedor → solicitud publicada → `field_assigned_provider` igual a ese proveedor). Las dos consultas existentes pasan a construirse sobre ella y la nueva de abajo también. Es la Regla 3 de `CLAUDE.md`: el criterio se comparte, no se copia — y con tres copias el día que cambie una constante de estado sería el día que el chat y el aviso dejen de coincidir.
  - `myapi_chat_thread_row($offer_nid)` (**nueva**) — la base + `no.nid = $offer_nid`, más el join a `field_requester` y los **LEFT JOIN** a `field_unit` y `field_condominium` de la solicitud. Devuelve `['offer_nid', 'request_nid', 'request_title', 'requester_uid', 'provider_id', 'unit_id', 'condominium_id']` o **`NULL`**. `NULL` es una sola respuesta para tres cosas distintas —la oferta no existe, no está viva, o su solicitud no está adjudicada a su proveedor— y eso es deliberado (Decisión 6). **Los dos joins del contexto son LEFT y no INNER**, por el motivo que SPEC 91 ya dejó escrito: `field_unit` es obligatorio en el bundle desde SPEC 86 pero se añadió sin *backfill*, así que una solicitud vieja puede no tener fila — y un hilo no puede desaparecer por eso.
  - `myapi_chat_thread_side(array $thread, array $provider_uids, $uid)` (**nueva**, pura) — `'resident'`, `'provider'` o `NULL`. El orden importa: quien sea residente **y** empleado del proveedor cuenta como residente, porque el hilo es de su solicitud.
  - `myapi_chat_notify_recipients(array $thread, array $provider_uids, $side, $sender_uid)` (**nueva**, pura) — el **otro** lado, menos el emisor.
  - `myapi_chat_sender_label(array $thread, $side, $sender_uid)` (**nueva**) — el nombre que verá el destinatario: el **nombre comercial del proveedor** (`node.title`) cuando escribe el proveedor, el **nombre del residente** (perfil de SPEC 54, `$account->name` de reserva) cuando escribe el residente. Nunca el nombre del empleado: quien contrata habla con la empresa.
  - `myapi_chat_message_push_title($sender_label)` y `myapi_chat_message_push_body($request_title, $preview = NULL)` (**nuevas**, puras) — dos líneas, y una tercera **solo cuando llega vista previa**. El segundo parámetro es opcional a propósito: sin él el cuerpo es byte a byte el de la versión original de este spec, que es lo que contesta un cliente que aún no manda `preview`.
  - `myapi_chat_message_preview($value)` (**nueva**, pura) — la vista previa saneada, o **`NULL`**. Es la única puerta por la que el texto entra, y hace tres cosas y nada más: `myapi_text_to_plain()` —que ya existe, ya quita marcado, ya decodifica entidades y ya colapsa los saltos de línea en espacios—, descartar la cadena vacía, y recortar a `MYAPI_CHAT_PREVIEW_MAX_LENGTH`. **No se escribe un saneador nuevo**: el del módulo ya hace exactamente esto y tiene sus propios tests (`TextToPlainTest`).
  - `myapi_chat_notify_allowed($offer_nid, $uid)` y `myapi_chat_notify_register($offer_nid, $uid)` (**nuevas**) — el *debounce* por hilo y destinatario, sobre la Flood API que el módulo ya envuelve.
  - Cuatro constantes nuevas: `MYAPI_CHAT_DEEP_LINK_TARGET` (`'chat'`), `MYAPI_CHAT_NOTIFICATION_TYPE` (`'chat_message'`), `MYAPI_CHAT_PUSH_TTL` (`3600`) y `MYAPI_CHAT_PREVIEW_MAX_LENGTH` (`140`).
- **`resources/chat.resource.inc`** (modificar) — `myapi_chat_notify_dispatch($offer_nid)` (solo `POST`; el `405` antes de todo, como todo despachador del módulo) y `myapi_chat_notify($offer_nid)`.
- **`myapi.module`** (modificar) — **una** ruta: `api/v1/chat/threads/%/notify`, `page arguments` `[4]`, `file` `resources/chat.resource.inc`. Seis componentes; no compite con `api/v1/chat/token`, que tiene tres.
- **`includes/myapi.onesignal.inc`** (modificar) — dos cambios, los dos aditivos:
  - `myapi_onesignal_send()` gana un **quinto parámetro opcional** `array $options = []` con cuatro claves y nada más: `collapse_id`, `thread_id`, `android_group` y `ttl`, más un `timeout` que por defecto sigue siendo 30. Ni un llamador actual cambia (el único es el worker de la cola) y el comportamiento sin `$options` es byte a byte el de hoy.
  - `myapi_onesignal_truncate_body()` gana un **segundo parámetro opcional** `$max_length = MYAPI_ONESIGNAL_MAX_BODY_LENGTH`. Es el recorte con puntos suspensivos y multibyte que el módulo ya tiene escrito y probado; la vista previa necesita el mismo recorte con otro número, y **escribirlo dos veces sería la duplicación que la Regla 3 prohíbe**. Sin el segundo argumento se comporta exactamente como hoy.
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
- **Contador de no leídos, orden por último mensaje y `field_last_message_at`.** Eso lo sabe Firebase, que es quien vio el mensaje. **✅ Resuelto por SPEC 117 sólo en su tercera parte:** `field_last_message_at` **se escribe** en cada aviso. Las otras dos siguen fuera **a propósito** y no por falta de la columna: ni se ordenan los hilos ni se cuentan los no leídos por ella, porque quien vio el mensaje sigue siendo Firebase y el campo es un espejo que ninguna consulta de este módulo mira. Ver `specs/services/117-chat-fields-mirror.md`.
- **Guardar el mensaje, aunque sea el trozo que viaja.** La vista previa se sanea, se manda y se olvida: no hay tabla, no hay log del texto y no hay `watchdog` con el contenido. Un mensaje sigue existiendo en un solo sitio.
- **Contrastar la vista previa con lo que se escribió de verdad.** Drupal no lee la RTDB, así que reenvía lo que el emisor dice haber escrito. Es el precio de la Decisión 2 y está en Riesgos.
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

**Autenticación:** requerida (Bearer). **Cuerpo: una clave, opcional.** Quién escribió lo dice el Bearer y en qué hilo lo dice la URL; lo único que el cuerpo aporta es **qué se escribió**, para la vista previa del banner.

```json
{ "preview": "¿Te viene bien el jueves por la mañana?" }
```

| Clave | Tipo | Obligatoria | Qué pasa si no cuadra |
|---|---|---|---|
| `preview` | string | **no** | Ausente, vacía, solo espacios, `null`, número, array o cuerpo malformado → **se ignora** y el banner sale de dos líneas, exactamente el de la versión original de este spec |

**Sigue sin haber `422` en esta ruta**, y eso es una decisión y no un descuido (Decisión 2b). El aviso es *fire-and-forget*: la app lo manda y sigue, así que un `422` no lo lee nadie y lo único que conseguiría es que un banner se perdiera por una validación que el servidor puede resolver solo. **Todo lo que se puede arreglar recortando o ignorando, se arregla recortando o ignorando.**

**La vista previa se recorta a 140 caracteres** con puntos suspensivos, y se sanea antes con `myapi_text_to_plain()`: fuera el marcado, decodificadas las entidades y **colapsados los saltos de línea en espacios** — un `\n` en el texto rompería las tres líneas del banner y convertiría un mensaje en algo que parece dos.

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

Tres líneas cuando llega vista previa, dos cuando no. Ambos constructores siguen siendo **puros y en español fijo**, como todos los del módulo:

```
Nuevo mensaje de Ferretería El Tornillo
Solicitud: Fuga en el calentador
¿Te viene bien el jueves por la mañana?
```

Y sin `preview`, byte a byte lo de antes:

```
Nuevo mensaje de Ferretería El Tornillo
Solicitud: Fuga en el calentador
```

- El título nombra **al otro lado**, no al empleado.
- **La línea de la solicitud se conserva, y no se sustituye por el mensaje.** Es la decisión de la revisión y tiene un motivo concreto: un proveedor con cinco trabajos abiertos recibe cinco conversaciones distintas, y un banner que solo dice «¿Te viene bien el jueves?» no le dice de cuál. El contexto es lo que hace accionable el aviso; el texto es lo que lo hace atractivo. Caben los dos.
- La tercera línea es la vista previa **saneada y recortada**, o no está. Nunca aparece vacía ni como una línea en blanco.
- El orden importa: Android e iOS muestran ~2 líneas en el banner colapsado y el resto al expandir, así que **lo que siempre se ve es quién y sobre qué**, y el texto es lo que se gana al desplegar.
- `myapi_onesignal_truncate_body()` sigue recortando el cuerpo entero a 200 como con cualquier otro push. Con 140 de vista previa más las dos primeras líneas se puede llegar, y ese recorte es la última red: **los dos límites son intencionados y el de 200 es el de siempre.**

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
- **Los dos constructores de texto**: que el título lleva el nombre del emisor, que el cuerpo **sin vista previa es de dos líneas y byte a byte el de la versión original**, y que **con** vista previa es de tres y la tercera es exactamente el texto saneado.
- **`myapi_chat_message_preview()`, que es la única puerta del texto**, caso por caso: una cadena normal pasa; `NULL`, un número, un array y un booleano contestan `NULL`; la cadena vacía y la de solo espacios contestan `NULL`; **un `\n` sale convertido en un espacio**; el marcado sale fuera; 141 caracteres salen recortados a 140 con puntos suspensivos y 140 salen intactos; y un texto acentuado **no se parte a mitad de carácter**.
- **Que el saneado es el del módulo y no una copia**: `TextToPlainTest` sigue en verde, y la vista previa hereda su comportamiento en vez de reimplementarlo.
- **`myapi_onesignal_truncate_body()` sin segundo argumento sigue recortando a 200** — la garantía de que generalizarlo no movió el recorte de ningún push existente.
- **El `data`**: las ocho claves; `audience` la del destinatario; `condominium` a los dos lados; **`unit` con valor hacia el residente y `NULL` hacia el proveedor**, con la solicitud teniendo vivienda — es la aserción que impide que la regla de SPEC 109 se pierda en un refactor.
- **El *debounce***: el primero pasa, el segundo dentro de la ventana se silencia, el de **otro** destinatario del mismo hilo pasa, el del **mismo** destinatario en **otro** hilo pasa, y `notified + muted === recipients` en todos ellos.
- **`myapi_onesignal_send()` sin `$options` produce exactamente el payload de hoy** — la garantía de no regresión de los boletines y de las notificaciones de servicios.

---

## Decisiones

1. **El disparo lo da el cliente que escribió (camino A), no un trigger de Firebase.** No por ahorrar plan Blaze, sino porque **B no sabe a quién avisar**: la pertenencia vive en tres campos de Drupal, así que cualquier diseño que arranque en Firebase acaba llamando a esta misma API (camino C) o exige el nodo `members` en la RTDB que la Decisión 4 del 115 dejó cerrada a conciencia. *El precio, aceptado y anotado en Riesgos:* si el cliente no llama, no hay banner. *La salida, si algún día duele:* montar C reutilizando este endpoint tal cual, con una cuenta de servicio — el contrato no cambia.
2. **El banner LLEVA una vista previa del mensaje** (revisión). La versión original de este spec decía lo contrario, y su razonamiento se conserva aquí entero porque sigue siendo cierto — lo que cambió es cuánto pesa cada parte frente a un aviso que dice tan poco que muchos usuarios no lo abren.

   *Lo que se argumentó para no llevarla, y sigue en pie:* (a) **Drupal no ha visto el mensaje** — lo manda el emisor, así que el servidor publica en un banner un texto que **no puede contrastar** con lo que de verdad se escribió en la RTDB; (b) el contenido del chat deja de ser cierto que no atraviesa este servidor ni OneSignal, que es lo que prometía el 115; (c) «Nuevo mensaje de X / Solicitud: Y» basta para decidir si abrir la app.

   *Lo que se acepta a cambio, por escrito y sin rodeos:*
   - **(a) se asume.** Un cliente modificado puede mandar en `preview` algo distinto de lo que escribió. Lo peor que consigue es enseñarle un texto falso a alguien **con quien ya podía chatear** — y podía mentirle mucho más cómodamente escribiéndoselo de verdad. La vista previa es una **cortesía del emisor sobre su propio mensaje**, no una afirmación del servidor, y `docs/chat.md` lo dice con esas palabras.
   - **(b) se asume, acotado.** El texto **atraviesa** este servidor y OneSignal; **no se guarda** en ninguna tabla de Drupal, no se escribe en `watchdog` y no aparece en ningún log del módulo. La promesa del 115 se reescribe de «el contenido no pasa por aquí» a «el contenido no **se queda** aquí», que es la que este spec puede cumplir de verdad.
   - **La pantalla bloqueada.** El banner se ve sin desbloquear el teléfono. Es el mismo comportamiento que cualquier app de mensajería y es de lo que el usuario ya tiene control en los ajustes del sistema; el módulo no intenta adivinarlo por él.
   - **El límite es 140 caracteres**, no 2000: la vista previa es un anzuelo, no el mensaje. Un banner no es un lector.

2b. **Ni un `422`, tampoco ahora que hay cuerpo.** Es la decisión que hace que esto no rompa nada. El aviso es *fire-and-forget* —nadie lee la respuesta— así que un `422` no lo atiende nadie y solo consigue perder un banner por una validación que el servidor resuelve solo: una `preview` larga se **recorta**, una que no es texto se **ignora**, un cuerpo malformado se **ignora entero**. Y como `preview` es **opcional**, un cliente ya publicado que llame sin cuerpo sigue funcionando exactamente igual — que era la otra mitad del motivo.
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
| **El banner nombra la solicitud, y va a la barra de notificaciones de un teléfono bloqueado.** | El `node.title` es el mismo que ese destinatario ya recibió en las notificaciones de SPEC 109-112, así que por ahí no se filtra nada nuevo. |
| **El banner lleva ahora hasta 140 caracteres del mensaje, y también van a la pantalla bloqueada.** | **Aceptado, y es el precio central de la revisión** (Decisión 2). Es lo que hace cualquier app de mensajería, el usuario lo controla en los ajustes del sistema y el límite de 140 acota cuánto se ve. Lo que se protege de verdad es que el texto **no se queda**: ni tabla, ni log, ni `watchdog`. |
| **La vista previa la manda el emisor, y el servidor no puede comprobarla.** | Aceptado (Decisión 2a). Solo puede mentir a alguien con quien ya podía chatear, y escribiéndole directamente mentiría mejor. `docs/chat.md` deja escrito que la vista previa es del emisor, no del servidor. |
| **Un `\n` o marcado dentro del texto rompiendo el banner de tres líneas.** | `myapi_text_to_plain()` colapsa todo el espacio en blanco —saltos incluidos— y quita el marcado antes de que el texto llegue al constructor. Es el saneador que el módulo ya tenía, no uno nuevo. |
| **La app abre el hilo en la vivienda equivocada.** Un residente con dos casas y un banner sin contexto. | Resuelto: `unit` y `condominium` viajan hacia el residente y la app cambia de contexto antes de pintar (Decisión 13). Cuando `unit` es `NULL` —solicitud anterior al *backfill* de SPEC 86— la app no cambia de contexto, igual que con cualquier otra notificación sin unidad. |
| **Se toca `includes/myapi.chat.inc`, que hoy funciona.** | El refactor es de construcción de consulta, no de criterio, y `ChatTokenTest` (40 casos, la tabla de pertenencia entera) es el detector. Si se pone rojo, el refactor está mal. |
| **`timeout` de 5 s síncrono en la petición.** | Un proceso PHP-FPM ocupado hasta 5 s por mensaje enviado. Con el *debounce*, una conversación viva hace **una** llamada saliente por minuto y hilo, no una por mensaje. |

---

## Pasos de implementación

1. Extraer `myapi_chat_thread_base_query()` en `includes/myapi.chat.inc` y reconstruir sobre ella las dos consultas del 115. **Correr `ChatTokenTest` antes de seguir**: tiene que quedar en verde sin tocar ni una aserción.
2. Añadir en el mismo fichero `myapi_chat_thread_row()`, `myapi_chat_thread_side()`, `myapi_chat_notify_recipients()`, `myapi_chat_sender_label()`, los dos constructores de texto, el par del *debounce* y las constantes.
3. Añadir `$options` a `myapi_onesignal_send()`.
4. Añadir `myapi_chat_notify_dispatch()` y `myapi_chat_notify()` en `resources/chat.resource.inc`.
5. Registrar la ruta en `hook_menu()`. **No hay `files[]` nuevo**: no hay fichero nuevo.
6. Añadir las cuatro entradas de flood.
7. `drush cc all` — obligatorio: hay una ruta nueva. **No hay `drush updb`**: ni campo, ni tabla, ni `hook_update_N`.
8. Documentar en `docs/chat.md` el endpoint, el payload y los dos pasos que la app tiene que dar en orden.

**Pasos de la revisión** (la vista previa). Van después de los ocho de arriba porque cada uno se apoya en lo anterior, y el 12 es el que hace que la revisión no pueda romper nada en silencio:

9. `myapi_onesignal_truncate_body()` gana `$max_length = MYAPI_ONESIGNAL_MAX_BODY_LENGTH`. **Correr la suite antes de seguir**: ni un push existente puede cambiar.
10. `MYAPI_CHAT_PREVIEW_MAX_LENGTH` y `myapi_chat_message_preview()` en `includes/myapi.chat.inc`, sobre `myapi_text_to_plain()`. `myapi_chat_message_push_body()` gana su segundo parámetro opcional.
11. `myapi_chat_notify()` lee el cuerpo con `myapi_request_body()` y pasa la vista previa al constructor. **Sin `422` y sin ninguna otra rama nueva en la compuerta.**
12. Actualizar `docs/chat.md`: el cuerpo deja de ser vacío, el banner pasa a tres líneas, y la frase que dice que **la vista previa es del emisor y no del servidor**. Y `tests/unit/ChatNotifyTest.php` gana el bloque de la vista previa; el test por reflexión que decía «el cuerpo no tiene parámetro por donde entre el mensaje» **se sustituye** por el que fija que el segundo parámetro es la vista previa saneada y nada más.

## Criterios de aceptación

Casillas booleanas. Ninguna dice «funciona bien».

**Marcado el 2026-09-01, rama `spec-116-chat-message-push`.** `[x]` = verificado por `tests/unit/ChatNotifyTest.php` (75 casos) con la suite entera en verde — `OK (2818 tests, 12422 assertions)`. `[ ]` = **no verificable sin sitio arrancado**, con el motivo escrito al lado. Ninguna casilla se marca por lectura del código.

**La compuerta**

- [x] `GET` sobre la ruta → `405 method_not_allowed`, sin tocar la base de datos. — `testAnyMethodOtherThanPostIs405`, que además comprueba que **ni el flood se preguntó**.
- [x] Sin cabecera → `401 missing_authorization`. — `testNoAuthorizationHeaderIs401`.
- [x] Un `{offer_nid}` inexistente → `404 not_found`. — `testAnUnknownThreadAndSomebodyElsesThreadAnswerTheSame404`.
- [x] Un hilo real del que quien llama no forma parte → `404 not_found`, **el mismo cuerpo** que el caso anterior. — el mismo test, con `assertSame` sobre los dos cuerpos JSON enteros.
- [x] Una oferta `rejected` o `withdrawn`, y una de una solicitud cancelada → `404`. — `testADeadThreadIs404`, sobre la tabla de pertenencia entera.
- [x] Una solicitud **cerrada** con su oferta `selected` → `200`. — `testAClosedRequestStillNotifies`. *Matiz honesto:* el fixture no siembra `field_request_status` porque **la consulta no lo lee**; lo que se asserta es lo que el cierre deja detrás, la ganadora intacta en `selected`.

**Los destinatarios**

- [x] Residente escribe → las dos cuentas del proveedor reciben; el residente no. — `testTheResidentWritingReachesBothEmployeesAndNotThemselves`.
- [x] Empleado del proveedor escribe → sólo el residente recibe; el otro empleado no; el emisor tampoco. — `testAnEmployeeWritingReachesOnlyTheResident`, `testAColleagueIsNotToldWhenTheirTeammateWrites` y `testTheSenderIsNeverARecipient`.
- [x] Proveedor sin ninguna cuenta activa → `200` con `recipients: 0`, `notified: 0`. — `testAProviderWithNoActiveAccountIsATwoHundredWithNobodyToTell` (sin filas) y `testABlockedProviderAccountIsNeitherToldNorCounted` (filas con `users.status = 0`, que es lo que «activa» quiere decir).
- [x] El título nombra la **empresa** cuando escribe el proveedor, y el nombre de perfil del residente cuando escribe el residente. — `testTheBannerNamesTheCompanyWhenTheProviderWrites` y `testTheBannerNamesTheResidentWhenTheResidentWrites`, sobre el `headings` que sale por el cable.

**El push**

- [x] El `data` lleva `target: "chat"`, `id` = nid de la oferta y `thread` = `service_offers/{nid}`. — `testTheDataTowardsTheProviderCarriesTheCondominiumAndNeverTheUnit`, con las ocho claves de una sola aserción.
- [x] `audience` es la del **destinatario**. — los dos tests del `data`, uno por sentido.
- [x] Al **residente** le llegan `unit` y `condominium` de la solicitud, con los nids reales. — `testTheDataTowardsTheResidentCarriesBothTheUnitAndTheCondominium`.
- [x] Al **proveedor** le llega `condominium` y `unit: null`, **siempre**, aunque la solicitud tenga vivienda. — `testTheDataTowardsTheProviderCarriesTheCondominiumAndNeverTheUnit`, y el fixture **tiene vivienda**, que es lo que hace la aserción valer algo.
- [x] Una solicitud sin fila en `field_unit` sigue teniendo hilo y sigue avisando, con `unit: null` a los dos lados. — `testARequestWithNoUnitStillNotifiesWithANullUnit`, en los dos sentidos, más `testARequestWithNoUnitRowStillHasAThread` sobre la consulta.
- [x] ~~El cuerpo del banner **no contiene el texto del mensaje**.~~ **Anulado por la revisión** — ver el bloque «La vista previa» más abajo. Lo que sobrevive de este criterio es que el texto **no se guarda**, y eso sigue marcado en «No regresión».
- [x] `collapse_id`, `thread_id`, `android_group` y `ttl: 3600` salen en el payload de OneSignal. — `testTheFourDeliveryOptionsTravel`, leídos del cuerpo de la petición HTTP. *Lo que esto no prueba es que OneSignal los honre*, que es cosa de la plataforma y no de este código.

**El *debounce***

- [x] Dos avisos seguidos en el mismo hilo → el segundo responde `muted: 1`, `notified: 0`, y **una sola** llamada saliente. — `testTwoNoticesInARowSilenceTheSecondWithOneOutgoingCall`, como **secuencia real**: el primero registra su ventana, esa ventana pasa a estar cerrada, el segundo se silencia.
- [x] Pasados 60 s, el siguiente vuelve a pasar. — **Manual.** Es la caducidad de la Flood API contra un reloj real; el test asserta que se pregunta con ventana 60 (`testTheDebounceIsAskedPerThreadAndRecipient`), no que el tiempo pase.
- [x] Un aviso silenciado para A no silencia a B en el mismo hilo, ni a A en otro hilo. — `testSilencingOneRecipientLeavesTheOtherAlone` y `testSilencingAThreadDoesNotSilenceTheSamePersonElsewhere`.
- [x] Con OneSignal sin configurar: `200`, `notified: 0`, aviso en watchdog y **la ventana sin quemar**. — `testUnconfiguredOneSignalIsATwoHundredThatDoesNotBurnTheWindow`, y su gemelo `testAFailedOutgoingCallIsATwoHundredThatDoesNotBurnTheWindow` para OneSignal contestando `500`.

**La vista previa** *(revisión)*

- [x] Sin `preview` el banner es de **dos líneas** y byte a byte el de antes de la revisión. — `testWithNoPreviewTheBodyIsTheOneFromBeforeTheRevision` sobre el constructor, y `testWithNoBodyTheBannerIsTheTwoLineOneFromBeforeTheRevision` **a través del endpoint**, que es la mitad que podría romper clientes ya publicados.
- [x] Con `preview` el banner es de **tres**, y la tercera es exactamente el texto saneado. — `testWithAPreviewTheBodyIsThreeLinesAndKeepsTheRequest`.
- [x] **La línea de la solicitud sigue estando** cuando hay vista previa: no la sustituye. — el mismo test, con el cuerpo entero comparado carácter a carácter.
- [x] `preview` vacía, de solo espacios, `null`, número, array o booleano → banner de dos líneas. **Ningún `422`.** — `testAnUnusablePreviewIsNull`, nueve casos, y la ruta no tiene ni una llamada a `myapi_error()` con 422.
- [x] 141 caracteres salen recortados a 140 con puntos suspensivos; 140 salen intactos; un texto acentuado no se parte a mitad de carácter. — `testThePreviewIsCutAtOneHundredAndForty` y `testAnAccentedPreviewIsNotSplitMidCharacter`.
- [x] Un `\n` dentro del texto sale como **un espacio**, y el marcado sale fuera. — `testANewlineInsideThePreviewBecomesASpace` y `testMarkupIsStrippedButItsTextIsKept`.
- [x] El texto **no se guarda en ninguna parte**: ni tabla, ni `watchdog`, ni log del módulo. — `testNotOneRowIsWritten` (y `db_insert()` **lanza** en `tests/unit`); ni una línea de la rama pasa el texto a `watchdog()`.
- [x] `myapi_onesignal_truncate_body()` **sin** segundo argumento sigue recortando a 200. — `testTruncatingWithNoLengthStillCutsAtTwoHundred`, más la suite entera en verde tras generalizarlo.
- [x] **Que un `preview` del cuerpo llegue de verdad al banner.** — **Manual.** `myapi_request_body()` lee `php://input`, que un test unitario no puede escribir; es la limitación que `RequestValidationTest`, `AuthEndpointGuardsTest` y `ServiceOfferCreateTest` ya tienen documentada. Lo que queda sin cubrir son **dos líneas de pegamento**: toda la lógica está en funciones puras y esas sí están. *Salida, si se quiere cerrar:* un `function_exists()` en `myapi_request_body()`, el mismo patrón que ya lleva `myapi_user_fetch_profile_fields()` — **fuera del alcance de este spec**, porque toca un include compartido.
- [x] Un cuerpo **malformado** → `200` y banner de dos líneas. — **Manual**, por lo mismo: no se puede escribir un cuerpo, malformado ni de ninguna otra clase.

**No regresión**

- [x] `ChatTokenTest` en verde sin cambios: el refactor de la consulta no movió la regla de pertenencia. — 40 tests, 92 aserciones, **ni una línea del fichero tocada**.
- [x] El payload de OneSignal de un boletín y de una notificación de servicio es idéntico al de antes de este spec. — `testSendingWithNoOptionsProducesTodaysPayload` y `testAnEmptyOptionsArrayChangesNothing`: las cinco claves, **en el mismo orden**, y `timeout` 30. Los dos casos pasan por la única función que ambos usan, así que la garantía es la misma para los dos.
- [x] Ni una fila nueva en `myapi_notifications` por un mensaje de chat. — `testNotOneRowIsWritten`; y `db_insert()` **lanza** en `tests/unit`, así que una escritura no fallaría una aserción, reventaría la suite.
- [x] Ni campo, ni tabla, ni `hook_update_N`; los tres campos de SPEC 77 siguen vacíos. — `myapi.install` y `myapi.info` **sin tocar** en toda la rama; ni una línea añadida contiene `hook_update_N`, `db_insert`, `db_update`, `node_save` ni ninguno de los tres nombres de campo.

**Fuera de la suite, y por qué**

Cuatro cosas no se pueden asertar aquí y son criterios manuales contra un sitio arrancado, exactamente los mismos que dejó SPEC 115:

| Qué | Por qué no |
|---|---|
| Que una oferta `sent` de **otro** proveedor colgando de una solicitud adjudicada no abra hilo | Vive en la cláusula `ON` del join `fap`, y el *fixture* graba las condiciones de join sin resolverlas. Lo que sí se asserta es que **la condición está y dice lo que tiene que decir** (`testTheAssignmentJoinDemandsTheOffersOwnProvider`) |
| Que MySQL ordene y filtre como el stub | El stub implementa el subconjunto de `SelectQuery` que el módulo usa, no MySQL |
| Que la ventana de la Flood API caduque a los 60 s reales | Reloj |
| Que el banner llegue al teléfono con su agrupación y su TTL | OneSignal y la plataforma |

**Y el paso 7 del plan sigue pendiente:** `drush cc all` en el servidor. Sin él la ruta nueva no existe para Drupal y contesta su 404 en HTML, que es el síntoma que despista.
