# SPEC 68 — Notificaciones de reclamos y de sus transacciones

> **Estado:** Implemented · **Fecha:** 2026-08-04
>
> **Depende de:**
> - **SPEC 55** — bundles `reclamo` y `claim_transaction` y sus campos
>   (`field_claim_type`, `field_visibility`, `field_status`, `field_condominium`,
>   `field_requester`, `field_description`, `field_comment`, `field_status_date`,
>   `field_claim`). El `reclamo` **no tiene** `field_unit`.
> - **SPEC 57** — `myapi_claim_transaction_create_initial()` y las ramas
>   `reclamo` / `claim_transaction` de `myapi_node_insert()`, que este spec
>   amplía.
> - **SPEC 61** — `myapi_claim_transaction_initial_comment()`; su frase de acuse
>   se reutiliza literal en el email al solicitante, para que el correo y la
>   línea de tiempo digan exactamente lo mismo.
> - **SPEC 62** — el catálogo de cuatro estados (`received`, `in_progress`,
>   `resolved`, `closed`) y sus etiquetas, que los avisos de transacción muestran.
> - **SPEC 63** — `field_reception_date` con granularidad de minuto, la fecha que
>   muestran todos los avisos de creación.
> - **SPEC 66** — `myapi_claim_create()`; este spec le añade **una línea**: la
>   bandera de origen antes de su `node_save()` (`claim.resource.inc:1253`).
> - **SPEC 67** — `myapi_claim_update()`; su `node_save()` es una de las dos vías
>   por las que un reclamo pasa de privado a público. **No se toca.**
> - **SPEC 09** — `myapi_condominium_member_uids()`, el universo de
>   propietarios + ocupantes de un condominio.
> - **SPEC 25 / 26** — `myapi_notification_create()` (inbox + encolado del push
>   en una sola llamada) y las columnas `condominium_id` / `unit_id`.
> - **SPEC 48** — el patrón espejo del que este spec copia entero: cola
>   `myapi_mail_send` (`myapi_mail_queue_enqueue()`), emails HTML vía
>   `MyapiHtmlMailSystem`, bandera transitoria de opt-out, y los dos resolutores
>   de destinatarios de back office (`myapi_reservation_backend_uids()` y
>   `myapi_reservation_building_admin_uids()`), que se **generalizan** aquí.
> - **SPEC 49** — rol `administrador edificio`, `MYAPI_BUILDING_ADMIN_CLAIM_TYPE`
>   y `myapi_building_admin_field_value()`.
>
> **Objetivo:** Notificar por push, inbox y email los tres eventos del ciclo de
> vida de un reclamo — su creación, su paso de privado a público, y cada
> transacción posterior a la inicial — al solicitante y, cuando el reclamo es
> público, a los usuarios de su condominio; y enviar además un email de detalle
> con botón al back office a los roles `backend` y `administrador edificio`
> **solo** cuando el reclamo nace desde la app.

---

Tres hechos que fija la cabecera, porque condicionan todo el documento:

- **Los tres disparadores viven en hooks de nodo, no en los endpoints.**
  `myapi_node_insert()` cubre la creación del reclamo y la de cada transacción;
  `myapi_node_update()` cubre la transición de visibilidad. La API solo aporta
  **una bandera de origen**; ningún endpoint cambia su respuesta.
- **El `reclamo` no tiene vivienda.** Solo `field_condominium`. Por eso
  `unit_id` es `NULL` en todas las filas de `myapi_notifications` que crea este
  spec, y por eso el email a back office no lleva línea "Vivienda".
- **Los textos son fijos en español.** Los tres disparadores corren dentro de
  `node_save()`, donde no hay `Accept-Language`; traducir solo el que nace de la
  API dejaría los avisos inconsistentes entre sí. Mismo criterio que SPEC
  27/28/30/48.

### La matriz completa

| Evento | Origen | Destinatarios | Canales |
|---|---|---|---|
| Reclamo creado, **privado** | Back office | Solicitante | push + inbox + email |
| Reclamo creado, **público** | Back office | Solicitante (texto propio) + resto del condominio (texto de vecino) | push + inbox + email |
| Reclamo creado, **privado** | App (API) | Solicitante · **+ email de detalle a `backend` y `administrador edificio`** | push + inbox + email |
| Reclamo creado, **público** | App (API) | Solicitante + resto del condominio · **+ email de detalle a back office** | push + inbox + email |
| Reclamo pasa **privado → público** | App o back office | Condominio **menos** el solicitante | push + inbox + email |
| Transacción nueva (no la inicial), reclamo **privado** | Back office | Solicitante | push + inbox + email |
| Transacción nueva (no la inicial), reclamo **público** | Back office | Solicitante (texto propio) + resto del condominio | push + inbox + email |

---

## Alcance

### Dentro de este spec

- **`includes/myapi.claim_notification.inc`** (nuevo) — toda la lógica del spec:
  - **Constantes**: `MYAPI_NOTIFICATION_SOURCE_CLAIM` (`'claim'`),
    `MYAPI_NOTIFICATION_DEEP_LINK_CLAIM` (`'claim'`), y los tres `type`:
    `claim_created`, `claim_published`, `claim_transaction_created`, más
    `MYAPI_CLAIM_VISIBILITY_PUBLIC` (`'public'`).
  - **Detectores**, uno por disparador:
    `myapi_claim_is_creation_from_api($node)`,
    `myapi_claim_is_publication_transition($node)`,
    `myapi_claim_transaction_is_notifiable($node)`.
  - **Resolutores de destinatarios**:
    `myapi_claim_requester_uid($node)` y
    `myapi_claim_condominium_uids($condominium_id, $exclude_uid)`.
  - **Fila equivalente** `myapi_claim_notification_row($node)` — mismo patrón que
    `myapi_reservation_notification_row()` de SPEC 48: un objeto con todos los
    valores del reclamo ya resueltos (asunto, tipo, visibilidad, estado,
    condominio y su nombre, solicitante con nombre y correo, fecha de recepción,
    descripción, conteo de archivos), para que los constructores de texto y los
    de email no vuelvan a leer el nodo cada uno por su lado.
  - **Constructores de texto puros** (los que entran en `tests/unit/`):
    `myapi_claim_push_title()`, `myapi_claim_push_body()`,
    `myapi_claim_transaction_push_*()`, `myapi_claim_status_label()`,
    `myapi_claim_type_label()` y `myapi_claim_excerpt()` (el recorte con `…`).
  - **Orquestadores**: `myapi_claim_notify_created($node, $from_api)`,
    `myapi_claim_notify_published($node)`,
    `myapi_claim_notify_transaction($node)`.

- **`includes/myapi.notification.inc`** (modificar) — dos resolutores de
  destinatarios de back office, **generalizados** desde SPEC 48:
  `myapi_notification_role_uids($role_name)` y
  `myapi_notification_building_admin_uids($condominium_id)`. Las dos funciones de
  `myapi.reservation_notification.inc` pasan a ser envoltorios de una línea que
  delegan en ellas: SPEC 48 conserva su firma y sus llamadas intactas, y este
  spec no duplica dos queries que ya existen (regla 3 de `CLAUDE.md`).

- **`includes/myapi.mail.inc`** (modificar) — cinco formateadores nuevos y sus
  constructores de HTML, con la misma paleta CrespCord del resto:
  `myapi_mail_format_claim_created_user()`,
  `..._claim_published_neighbour()`,
  `..._claim_transaction_user()` (parametrizado para las dos variantes) y
  `..._claim_created_admin()` (el de detalle, con el botón al back office).

- **`myapi.module`** (modificar) — solo glue:
  - `myapi_mail()`: cinco keys nuevas (`claim_created_requester`,
    `claim_published_neighbour`, `claim_transaction_requester`,
    `claim_transaction_neighbour`, `claim_created_admin`).
  - `myapi_node_insert()`: la rama `'reclamo'` que ya existe añade la llamada a
    `myapi_claim_notify_created()` **después** de
    `myapi_claim_transaction_create_initial()`; la rama `'claim_transaction'`
    añade `myapi_claim_notify_transaction()` **después** del sync de estado.
  - `myapi_node_update()`: rama nueva para `'reclamo'` (hoy no existe ninguna),
    que llama a `myapi_claim_notify_published()` si detecta la transición.

- **`resources/claim.resource.inc`** (modificar) — **una línea**:
  `$node->myapi_claim_from_api = TRUE;` inmediatamente antes del `node_save()`
  de `myapi_claim_create()` (línea 1253). `myapi_claim_update()` no se toca.

- **`includes/myapi.claim_transaction_admin.inc`** (modificar) — **una línea**:
  `$transaction->myapi_skip_claim_notification = TRUE;` antes del `node_save()`
  de `myapi_claim_transaction_create_initial()`. Es lo que hace que la
  transacción inicial automática no notifique dos veces el mismo hecho que ya
  notificó la creación del reclamo.

- **`myapi.install`** (modificar) — las cinco keys nuevas en
  `myapi_html_mail_keys()`, el catálogo único que `myapi_mail_system_register()`
  / `..._unregister()` recorren, más un `hook_update_N()` que aplique el mapeo en
  las instalaciones existentes. Sin él, los correos llegarían convertidos a texto
  plano.

- **`myapi.info`** (modificar) — `files[]` del include nuevo.

- **`tests/unit/ClaimNotificationTest.php`** (nuevo) — los constructores de texto
  puros y las tres decisiones puras (a quién le toca qué texto según
  visibilidad y rol de lectura, el recorte del asunto y del comentario, las
  etiquetas de estado y tipo).

- **`tests/README.md`** (modificar) — la cobertura nueva.

- **`docs/claim-notifications.md`** (nuevo) — los tres disparadores, la matriz de
  destinatarios, los textos exactos de push, inbox y los cinco emails, las
  banderas de opt-out y de origen, y los casos degradados.

- `drush updb && drush cc all` al final.

### Fuera de este spec

- **Notificar cualquier otra edición del reclamo** — cambiar asunto, descripción,
  condominio, tipo o archivos no avisa a nadie. Solo la transición de
  visibilidad, y solo en un sentido.
- **La transición público → privado.** No se puede "des-avisar" a quien ya leyó
  el reclamo, y decirle a un vecino que algo dejó de ser visible es peor que el
  silencio.
- **Notificar la edición de una transacción existente** (`myapi_node_update()`,
  rama `claim_transaction`). Solo la creación.
- **Notificar la transacción inicial automática.** El hecho que registra —"el
  reclamo fue recibido"— ya lo notifica la creación del reclamo.
- **Email a `backend` / `administrador edificio` cuando el reclamo se crea desde
  el back office.** El operador que lo radica ya lo sabe.
- **Email a back office en las transacciones y en la publicación.** Las escribe
  el propio operador; avisarle sería ruido.
- **Notificar el borrado** de un reclamo o de una transacción.
- **Traducir los textos vía `myapi_t()`.**
- **Preferencias de notificación por usuario.** Hoy nadie puede silenciar un
  canal; un reclamo público de un condominio grande notifica a todos sus
  propietarios y ocupantes, sin excepción.
- **Adjuntar los archivos del reclamo al email.** Viven en `private://` y su
  descarga exige token (SPEC 65); el email solo lleva el conteo.
- **Deep link a una transacción concreta.** Los tres eventos apuntan al reclamo.
- **Deduplicación entre eventos** — best-effort, mismo criterio que SPEC
  27/28/30/48.
- **Recordatorios, SLA o escalado** por reclamos sin atender.
- **Endpoints nuevos o cambios en el sobre de respuesta.** `POST /api/v1/claims`,
  `POST /api/v1/claims/{id}` y los `GET` responden byte a byte igual que hoy.
- **Configuración de SMTP** — se usa `drupal_mail()` tal como esté el sitio.
- **Cambiar `field_status` del reclamo sin crear transacción.** No es una vía
  real: SPEC 57 deja ese select `#disabled` en el formulario de edición
  (`myapi.claim_transaction_admin.inc:480`), así que toda transición de estado
  nace de una transacción y queda cubierta.

---

## Modelo de datos

**No se crean tablas, columnas, campos ni bundles.** `myapi_notifications` ya
tiene todo desde SPEC 26. Lo que este spec define son constantes, dos banderas
transitorias, una forma de dato en memoria y los textos exactos.

### Constantes nuevas

```php
// includes/myapi.claim_notification.inc
define('MYAPI_NOTIFICATION_SOURCE_CLAIM', 'claim');
define('MYAPI_NOTIFICATION_DEEP_LINK_CLAIM', 'claim');
define('MYAPI_NOTIFICATION_TYPE_CLAIM_CREATED', 'claim_created');
define('MYAPI_NOTIFICATION_TYPE_CLAIM_PUBLISHED', 'claim_published');
define('MYAPI_NOTIFICATION_TYPE_CLAIM_TRANSACTION', 'claim_transaction_created');
define('MYAPI_CLAIM_VISIBILITY_PUBLIC', 'public');
define('MYAPI_CLAIM_NOTIFY_ROLE', 'backend');
define('MYAPI_CLAIM_SUBJECT_EXCERPT', 80);
define('MYAPI_CLAIM_COMMENT_EXCERPT', 120);
```

`MYAPI_BUILDING_ADMIN_ROLE` y `MYAPI_BUILDING_ADMIN_CLAIM_TYPE` se reutilizan de
SPEC 49; no se redefinen.

### Las dos banderas transitorias

Propiedades de objeto, no campos: no se persisten y solo viven durante el
`node_save()` que las lleva.

| Bandera | La pone | La lee | Efecto |
|---|---|---|---|
| `$node->myapi_claim_from_api` | `myapi_claim_create()` (SPEC 66) antes de su `node_save()` | `myapi_claim_is_creation_from_api()` | Presente → Caso 2: además del aviso al residente, email de detalle a back office. Ausente → Caso 1: solo el aviso al residente |
| `$transaction->myapi_skip_claim_notification` | `myapi_claim_transaction_create_initial()` (SPEC 57) antes de su `node_save()` | `myapi_claim_transaction_is_notifiable()` | Presente → no notifica. Es lo que distingue "la primera transacción" de las demás, sin contar filas |

El **default de cada una es el seguro**: sin bandera de origen no salen emails a
back office, y sin bandera de opt-out una transacción sí notifica. Una ruta
futura que cree reclamos o transacciones sin marcar nada hace lo conservador en
el primer caso y lo esperado en el segundo.

### `myapi_claim_notification_row($node)` — la fila equivalente

Mismo patrón que `myapi_reservation_notification_row()` (SPEC 48): un objeto con
**todo ya resuelto**, para que los constructores de texto y los cinco emails no
vuelvan a leer el nodo cada uno por su cuenta.

| Propiedad | Origen | Si falta |
|---|---|---|
| `nid`, `created` | del nodo | — |
| `subject` | `node.title` | `''` |
| `description` | `field_description` | `''` |
| `claim_type` / `type_label` | `field_claim_type` → `Reclamo` / `Requerimiento` | `Reclamo` |
| `visibility` | `field_visibility` | `NULL` (se trata como privado) |
| `status` / `status_label` | `field_status` → catálogo de SPEC 62 | `status_label = NULL` |
| `condominium_id` / `condominium_name` | `field_condominium` + `node_load()` | `NULL` / `Sin condominio` |
| `requester_uid` / `requester_name` / `requester_mail` | `field_requester` + `user_load()` | `NULL` / `Usuario eliminado (#N)` / `NULL` |
| `reception_date` | `field_reception_date` (SPEC 63) | cae a `node.created` |
| `image_count` / `has_attachment` | conteo de `field_images` / `field_attachment` | `0` / `FALSE` |

`unit_id` **siempre es `NULL`** en las filas de `myapi_notifications`: el
`reclamo` no tiene `field_unit`.

### Los tres disparadores y sus destinatarios

| # | Detector | Condición |
|---|---|---|
| 1 | `myapi_claim_is_creation_from_api($node)` | Se ejecuta siempre en `hook_node_insert()`; solo decide si además hay email a back office |
| 2 | `myapi_claim_is_publication_transition($node)` | `isset($node->original)` **y** `original.field_visibility !== 'public'` **y** `field_visibility === 'public'` |
| 3 | `myapi_claim_transaction_is_notifiable($node)` | `empty($node->myapi_skip_claim_notification)` **y** `field_claim` resuelve a un reclamo cargable |

Destinatarios, en los tres casos filtrados a **usuarios activos** y deduplicados:

| Evento | Solicitante | Condominio (propietarios + ocupantes, `myapi_condominium_member_uids($condo, 'todos')`) |
|---|---|---|
| Creación, privado | `claim_created` | — |
| Creación, público | `claim_created` | `claim_published`, **excluyendo al solicitante** |
| Publicación (privado → público) | — | `claim_published`, **excluyendo al solicitante** |
| Transacción, reclamo privado | `claim_transaction_created` | — |
| Transacción, reclamo público | `claim_transaction_created` | `claim_transaction_created` (texto de vecino), **excluyendo al solicitante** |

La exclusión del solicitante del fan-out es lo que garantiza **una sola
notificación por persona** con el texto que le corresponde. Cada combinación
"texto + destinatarios" es una llamada independiente a
`myapi_notification_create()`, porque esa función escribe un único `body` para
todos los uids que recibe.

**Si `field_requester` está vacío** (posible en el back office): no se notifica a
nadie por la vía del solicitante y se deja un `watchdog` de warning. El fan-out
público, si el reclamo es público, sí ocurre. Nunca un fan-out accidental.

### Textos de push + inbox

Fechas en `d/m/Y H:i` — el formato que ya usan el calendario y los emails de
reserva. El asunto se recorta a **80** caracteres con `…`; el comentario del
operador, a **120**.

**Creación — al solicitante** (`type: claim_created`)

```
Título:  Reclamo recibido
Cuerpo:  Fuga de agua en el pasillo
         Recibido el 04/08/2026 16:45
```

Con `field_claim_type = requirement`, el título es `Requerimiento recibido`.

**Creación pública y publicación — a los vecinos** (`type: claim_published`)

```
Título:  Nuevo reclamo en tu condominio
Cuerpo:  Fuga de agua en el pasillo
         Publicado el 04/08/2026 16:45
```

`Publicado el` es la **fecha de recepción** cuando el reclamo nace público, y la
**hora del guardado** cuando pasa de privado a público. En ambos casos es el
instante en que ese vecino pudo verlo, que es lo que la línea afirma.

**Transacción — al solicitante** (`type: claim_transaction_created`)

```
Título:  Tu reclamo pasó a "En proceso"
Cuerpo:  Fuga de agua en el pasillo
         Se asignó un técnico para revisar la tubería del tercer piso.
         05/08/2026 09:30
```

**Transacción — a los vecinos** (mismo `type`)

```
Título:  Novedad en un reclamo de tu condominio
Cuerpo:  Fuga de agua en el pasillo
         Estado: En proceso · 05/08/2026 09:30
         Se asignó un técnico para revisar la tubería del tercer piso.
```

El estado va en el **título** del solicitante (es lo único que se lee en la
pantalla bloqueada) y en el **cuerpo** del vecino, cuyo título tiene que decir
antes de nada que el reclamo no es suyo.

**Degradados de los textos**

- Comentario vacío → su línea se omite; el cuerpo queda de dos líneas.
- `field_status` sin etiqueta resoluble → el título del solicitante cae a
  `Novedad en tu reclamo` y la línea `Estado:` del vecino se omite.
- `field_claim_type` ausente o desconocido → `Reclamo`, mismo criterio que
  SPEC 61.
- El cuerpo completo sigue pasando por `myapi_onesignal_truncate_body()` (corte
  a 200). Con el asunto ya recortado a 80, el recorte solo puede alcanzar al
  final del comentario, nunca a la primera línea.

**Comunes a las tres filas de `myapi_notifications`**

| Clave | Valor |
|---|---|
| `source_type` | `"claim"` |
| `source_nid` | nid del **reclamo** (nunca el de la transacción) |
| `deep_link_target` | `"claim"` |
| `deep_link_id` | nid del reclamo |
| `condominium_id` | `field_condominium` del reclamo, o `NULL` |
| `unit_id` | siempre `NULL` |

`deep_link.target = "claim"` es un valor **nuevo** para la app: hoy solo existen
`bulletin`, `payment`, `receipt`, `extra_fee` y `reservation`. Un cliente que no
lo conozca debe degradar a abrir el inbox, no romper.

### Las cinco keys de correo

| Key | Destinatario | Asunto |
|---|---|---|
| `claim_created_requester` | Solicitante | `Reclamo recibido — {asunto}` |
| `claim_published_neighbour` | Vecinos (creación pública **y** publicación) | `Nuevo reclamo en tu condominio — {asunto}` |
| `claim_transaction_requester` | Solicitante | `Novedad en tu reclamo — {asunto}` |
| `claim_transaction_neighbour` | Vecinos | `Novedad en un reclamo de tu condominio — {asunto}` |
| `claim_created_admin` | `backend` + `administrador edificio` del condominio | `Nuevo reclamo #{nid} — {condominio}` |

El sustantivo de los asuntos sigue a `field_claim_type` (`Requerimiento
recibido — …`). El asunto del reclamo va recortado a 80 caracteres.

### Email al residente — contenido

Cabecera CrespCord, saludo, frase de contexto, tabla de datos y pie.

| Key | Frase de contexto |
|---|---|
| `claim_created_requester` | `Hemos recibido tu reclamo. Será revisado por la administración y te notificaremos cualquier novedad.` |
| `claim_published_neighbour` | `Se publicó un nuevo reclamo en tu condominio.` |
| `claim_transaction_requester` | `Tu reclamo tiene una novedad.` |
| `claim_transaction_neighbour` | `Un reclamo de tu condominio tiene una novedad.` |

La frase de `claim_created_requester` es **la misma que ya guarda SPEC 61** en la
transacción inicial (tuteada), para que el correo y la primera fila de la línea
de tiempo digan literalmente lo mismo.

Tabla de los dos emails de **creación / publicación**:

| Línea | Valor |
|---|---|
| Asunto | `Fuga de agua en el pasillo` |
| Tipo | `Reclamo` |
| Condominio | `Residencias El Parque` |
| Estado | `Recibido` |
| Recibido el | `04/08/2026 16:45` |
| Descripción | bloque de texto, `check_plain()` + `nl2br()` |

Tabla de los dos emails de **transacción** — sin `Descripción` del reclamo, con
el comentario **completo** del operador (aquí no hay límite de 200 que respetar):

| Línea | Valor |
|---|---|
| Asunto | `Fuga de agua en el pasillo` |
| Estado | `En proceso` |
| Fecha | `05/08/2026 09:30` |
| Comentario | bloque de texto |

Pie de los cuatro: `Reclamo #141`.

### Email a back office (`claim_created_admin`) — contenido

```
Se registró un nuevo reclamo desde la aplicación.

  Reclamo        #141
  Asunto         Fuga de agua en el pasillo
  Tipo           Reclamo
  Visibilidad    Privado
  Estado         Recibido
  Solicitante    Javier Correa
  Email          javiko500@gmail.com
  Condominio     Residencias El Parque
  Recibido el    04/08/2026 16:45
  Adjuntos       2 imágenes, 1 documento

  Descripción
  La mancha llega ya hasta la puerta 3-B y no para de crecer.

        [ Abrir en el back office ]   →   {base}/node/141
```

- **Sin línea "Vivienda":** el `reclamo` no tiene `field_unit`, y derivarla de
  las viviendas del solicitante es ambiguo cuando tiene dos en el mismo
  condominio.
- **`Adjuntos`** es solo el conteo: los archivos viven en `private://` y su
  descarga exige token (SPEC 65). La línea se omite cuando no hay ninguno.
- **El botón** es `url('node/' . $nid, ['absolute' => TRUE])`, pasado por
  `check_plain()` — `url()` no escapa el `&` entre argumentos de query.
- **Un email por destinatario**, nunca uno con todos en copia: un correo
  inválido no arrastra a los demás, y nadie ve la lista de los otros operadores.
- El asunto **no** va recortado aquí: lleva el nid y el condominio, no el asunto
  del reclamo.

### Ítem de la cola de correo

Idéntico en forma al de SPEC 48:

```php
['key' => 'claim_created_requester', 'to' => 'javiko500@gmail.com', 'params' => [...], 'attempts' => 0]
```

Los `params` viajan **ya resueltos y escapados** (cadenas, no nids): el correo
describe lo que era cierto en el instante del disparo. Un reclamo borrado o un
condominio renombrado entre el disparo y la corrida de cron no rompen ni alteran
el envío.

### Guardián de doble disparo

`drupal_static()` por nid dentro de cada orquestador, mismo patrón que SPEC
28/48: un re-guardado del mismo nodo dentro de la misma request (una Rule que
recalcule algo, un `node_save()` encadenado) notifica **como máximo una vez**.

---

## Plan de implementación

Los pasos 1–5 son **aditivos y ciegos**: añaden código que ninguna ruta alcanza
todavía. El primero que cambia el comportamiento del sitio es el 6.

**1. Generalizar los dos resolutores de destinatarios de back office.**
En `includes/myapi.notification.inc`, dos funciones nuevas:

```php
function myapi_notification_role_uids($role_name)              // users ⨝ users_roles ⨝ role
function myapi_notification_building_admin_uids($condominium_id) // + join field_condominio_admin
```

Son el cuerpo actual de `myapi_reservation_backend_uids()` y
`myapi_reservation_building_admin_uids()`, con el nombre del rol como parámetro
en vez de constante. Se conservan las tres reglas que documentó SPEC 48: rol
por **nombre** y nunca por rid (el rid varía por entorno), solo cuentas activas,
sin pertenencia implícita del `uid 1`. Y el guardián de
`field_info_field(MYAPI_BUILDING_ADMIN_CONDO_FIELD)`, que evita un fatal en la
ventana de deploy entre subir el código y correr `drush updb`.

Las dos funciones de `includes/myapi.reservation_notification.inc` pasan a ser
envoltorios de una línea que delegan. SPEC 48 conserva firma, llamadas y
comportamiento.

*Verificación: `php -l`; `drush php-eval` sobre las cuatro funciones (las dos
nuevas y las dos envoltorio) devuelve exactamente los mismos uids que antes del
cambio.*

**2. El include de notificación de reclamos — datos y textos puros.**
Crear `includes/myapi.claim_notification.inc` con las constantes de la sección
anterior, `myapi_claim_notification_row($node)`, los resolutores de
destinatarios (`myapi_claim_requester_uid()`,
`myapi_claim_condominium_uids($condo, $exclude_uid)`, que envuelve a
`myapi_condominium_member_uids([$condo], 'todos')` filtrando activos y excluyendo
un uid) y **todos los constructores de texto puros**:
`myapi_claim_type_label()`, `myapi_claim_status_label()`,
`myapi_claim_excerpt($text, $limit)`, y los cuatro pares título/cuerpo.

`files[] = includes/myapi.claim_notification.inc` en `myapi.info`. `drush cc all`.

*Verificación: `php -l`; `drush php-eval` sobre
`myapi_claim_notification_row(node_load(N))` de un reclamo real devuelve las 14
propiedades pobladas, y las etiquetas de ausencia en un reclamo sin condominio o
con el solicitante borrado.*

**3. Tests unitarios de los textos.**
`tests/unit/ClaimNotificationTest.php`: las etiquetas de tipo y estado con sus
degradados, el recorte a 80/120 con `…` (y el no-recorte cuando cabe), y los
cuatro pares título/cuerpo — incluidos los tres degradados: comentario vacío,
estado sin etiqueta y tipo desconocido. Actualizar el conteo de
`tests/README.md`.

*Verificación: la suite completa en verde.*

**4. Los cinco emails.**
En `includes/myapi.mail.inc`, los formateadores y sus constructores de HTML, con
la paleta CrespCord del resto. Los cuatro del residente comparten un constructor
de tabla parametrizado; el de back office tiene el suyo, con el bloque del botón.
En `myapi.module`, ampliar `myapi_mail()` con las cinco keys.
En `myapi.install`, añadir las cinco a **`myapi_html_mail_keys()`** — que es el
catálogo único que `myapi_mail_system_register()` / `..._unregister()` recorren,
así que no hay una segunda lista que mantener — y crear el `hook_update_N()`
siguiente libre, que llame a `myapi_mail_system_register()`. Sin ese update, en
un sitio ya instalado las cinco keys caerían en `DefaultMailSystem` y el HTML
llegaría convertido a texto plano. `drush updb && drush cc all`.

*Verificación: `drush php-eval` disparando
`drupal_mail('myapi', 'claim_created_admin', ...)` con params de prueba → llega
un correo con el HTML intacto y el botón enlazando a `node/{nid}`.*

**5. Los tres orquestadores.**
En el include del paso 2:

- `myapi_claim_notify_created($node, $from_api)` — arma la fila, notifica al
  solicitante, y si el reclamo es público hace el fan-out al condominio
  excluyéndolo; si `$from_api`, encola además el email de detalle a
  `myapi_notification_role_uids(MYAPI_CLAIM_NOTIFY_ROLE)` unido a
  `myapi_notification_building_admin_uids($row->condominium_id)`, deduplicado.
- `myapi_claim_notify_published($node)` — solo el fan-out al condominio,
  excluyendo al solicitante, con `Publicado el` = la hora del guardado.
- `myapi_claim_notify_transaction($node)` — carga el reclamo desde
  `field_claim`, arma su fila, y notifica al solicitante y —si el reclamo es
  público— al condominio, con los dos textos distintos.

Los tres son **best-effort**: ninguna rama lanza excepción, y los tres abren con
el guardián de `drupal_static()` por nid.

*Verificación: llamarlos a mano sobre nodos existentes → las filas esperadas en
`myapi_notifications`, los ítems en `myapi_onesignal_push` y en
`myapi_mail_send`, sin que ninguna combinación produzca dos filas para el mismo
uid.*

**6. Enganchar los tres disparadores.** El paso que enciende el spec.

En `resources/claim.resource.inc`, antes del `node_save()` de la línea 1253:

```php
$node->myapi_claim_from_api = TRUE;
```

En `includes/myapi.claim_transaction_admin.inc`, antes del `node_save()` de
`myapi_claim_transaction_create_initial()`:

```php
$transaction->myapi_skip_claim_notification = TRUE;
```

En `myapi_node_insert()` (`myapi.module`), la rama `'reclamo'` que ya llama a
`myapi_claim_transaction_create_initial($node)` añade **después**:

```php
module_load_include('inc', 'myapi', 'includes/myapi.claim_notification');
myapi_claim_notify_created($node, myapi_claim_is_creation_from_api($node));
```

Después, no antes: así el reclamo ya tiene su transacción inicial cuando se
notifica, y un fallo del orquestador no puede impedir que se cree.

La rama `'claim_transaction'` añade, después del sync de estado:

```php
module_load_include('inc', 'myapi', 'includes/myapi.claim_notification');
if (myapi_claim_transaction_is_notifiable($node)) {
  myapi_claim_notify_transaction($node);
}
```

También después: el sync escribe `field_status` del reclamo, y el texto del
aviso lee el estado de la transacción, no el del reclamo — pero mantener el
orden "primero el dato, luego el aviso" es la regla del módulo.

En `myapi_node_update()`, rama nueva para `'reclamo'` (hoy no hay ninguna):

```php
if ($node->type === MYAPI_BUILDING_ADMIN_CLAIM_TYPE) {
  module_load_include('inc', 'myapi', 'includes/myapi.claim_notification');
  if (myapi_claim_is_publication_transition($node)) {
    myapi_claim_notify_published($node);
  }
  return;
}
```

`drush cc all`.

*Verificación: `POST /api/v1/claims` sigue respondiendo `201` con el mismo
cuerpo, y ahora deja las filas y los ítems de cola esperados.*

**7. Documentación.** Crear `docs/claim-notifications.md`: los tres
disparadores, la matriz completa de destinatarios, los textos exactos de push e
inbox con sus degradados, el asunto y los campos de los cinco emails, las dos
banderas, el `deep_link.target = "claim"` nuevo (y la nota de que un cliente que
no lo conozca debe degradar a abrir el inbox), el comportamiento de la cola de
correo y los casos degradados.

**8. Aplicar y verificar de punta a punta.** `drush updb && drush cc all`, y
recorrer la matriz completa:

| Prueba | Esperado |
|---|---|
| (a) `POST /api/v1/claims` privado | Solicitante: push + inbox + email. Back office: email de detalle. Vecinos: nada |
| (b) `POST /api/v1/claims` público | Lo anterior + los vecinos, cada uno con **una** notificación |
| (c) `node/add/reclamo` privado | Solo el solicitante. **Ningún** email a back office |
| (d) `node/add/reclamo` público | Solicitante + vecinos. Ningún email a back office |
| (e) `POST /api/v1/claims/{id}` cambiando `private` → `public` | Vecinos sí, solicitante no |
| (f) `node/{nid}/edit` haciendo el mismo cambio | Idéntico a (e) |
| (g) `node/{nid}/edit` de `public` → `private` | Nada |
| (h) `node/{nid}/edit` cambiando solo el asunto | Nada |
| (i) Transacción nueva sobre un reclamo privado | Solo el solicitante |
| (j) Transacción nueva sobre un reclamo público | Solicitante + vecinos, textos distintos |
| (k) Editar una transacción existente | Nada |
| (l) `drush cron` | Drena `myapi_mail_send` y `myapi_onesignal_push` |

**Nota de orden:** el paso 6 es el único reversible de un vistazo — quitar las
dos líneas de bandera y las tres ramas de hook deja el sitio exactamente como
está hoy, con todo el código de los pasos 1–5 inerte.

---

## Criterios de aceptación

**Creación desde la app (Caso 2) — solicitante**

- [x] Un `POST /api/v1/claims` exitoso inserta una fila en `myapi_notifications` con `uid` = `field_requester`, `type = "claim_created"`, `source_type = "claim"`, `source_nid` = nid del reclamo, `deep_link_target = "claim"` y `deep_link_id` = nid del reclamo.
- [x] `condominium_id` de esa fila es el nid de `field_condominium`, y `unit_id` es `NULL`.
- [x] El `title` es exactamente `Reclamo recibido`, o `Requerimiento recibido` cuando `field_claim_type = "requirement"`.
- [x] El `body` es exactamente `{asunto}\nRecibido el {d/m/Y H:i}`, con la fecha tomada de `field_reception_date`.
- [x] Se encola el push correspondiente en `myapi_onesignal_push` con ese mismo título y cuerpo.
- [x] Se encola un email `claim_created_requester` al correo del solicitante, con asunto `Reclamo recibido — {asunto}`.

**Creación desde la app (Caso 2) — back office**

- [x] Se encola un email `claim_created_admin` **por cada** usuario activo con rol `backend` que tenga correo.
- [x] Se encola uno **por cada** usuario activo con rol `administrador edificio` cuyo `field_condominio_admin` incluya el condominio del reclamo.
- [x] Un usuario que tiene **ambos** roles recibe **un solo** email, no dos.
- [x] Un `administrador edificio` de **otro** condominio no recibe nada.
- [x] Un usuario con rol `backend` **bloqueado** no recibe email; el `uid 1` tampoco, salvo que tenga el rol asignado.
- [x] El asunto es `Nuevo reclamo #{nid} — {condominio}`.
- [x] El email muestra Reclamo, Asunto, Tipo, Visibilidad, Estado, Solicitante, Email, Condominio, Recibido el, Adjuntos y Descripción; **no** muestra ninguna línea "Vivienda".
- [x] La línea `Adjuntos` refleja el conteo real (`2 imágenes, 1 documento`) y **se omite** cuando el reclamo no tiene ninguno.
- [x] El botón `Abrir en el back office` apunta a la URL absoluta de `node/{nid}` y abre el reclamo tras el login.
- [x] Se envía **un email por destinatario**; ninguno lleva a otro operador en copia.

**Creación desde el back office (Caso 1)**

- [x] Crear un `reclamo` desde `node/add/reclamo` notifica al solicitante exactamente igual que el Caso 2 (misma fila, mismo título, mismo cuerpo, mismo email).
- [x] **Ningún** usuario con rol `backend` ni `administrador edificio` recibe email en este caso.
- [x] Lo mismo para un alta programática (`node_save()` vía drush, migración o import): notifica al residente y a nadie de back office.

**Visibilidad pública en la creación**

- [x] Un reclamo creado con `visibility = "public"` inserta, además de la del solicitante, una fila `type = "claim_published"` para **cada** propietario y ocupante activo de las viviendas del condominio.
- [x] El solicitante recibe **una sola** notificación (`claim_created`) aunque sea propietario u ocupante de ese condominio; nunca dos.
- [x] El `title` del vecino es `Nuevo reclamo en tu condominio` y el `body` es `{asunto}\nPublicado el {d/m/Y H:i}`.
- [x] Cada vecino recibe un email `claim_published_neighbour`.
- [x] Un reclamo creado con `visibility = "private"` no genera **ninguna** fila para nadie que no sea el solicitante.

**Transición privado → público**

- [x] `POST /api/v1/claims/{id}` cambiando `visibility` de `private` a `public` inserta una fila `claim_published` para cada usuario del condominio **excepto** el solicitante.
- [x] El mismo cambio hecho en `node/{nid}/edit` del back office produce exactamente el mismo resultado.
- [x] La línea `Publicado el` muestra la **hora del guardado**, no la fecha de recepción original del reclamo.
- [x] El solicitante **no** recibe nada por esta transición.
- [x] El cambio inverso (`public` → `private`) no genera fila, push ni email para nadie.
- [x] Editar un reclamo **sin** tocar `field_visibility` (cambiar asunto, descripción, condominio, archivos) no genera nada.
- [x] Editar un reclamo que ya era `public` dejándolo `public` no genera nada.
- [x] `POST /api/v1/claims/{id}` sigue respondiendo `200` con el mismo cuerpo que definió SPEC 67, con y sin transición.

**Transacciones**

- [x] La transacción inicial automática (`myapi_claim_transaction_create_initial()`) **no** genera fila, push ni email — ni al crear el reclamo desde la app ni desde el back office.
- [x] Una transacción creada después inserta una fila `type = "claim_transaction_created"` para el solicitante, con `source_nid` y `deep_link_id` = nid del **reclamo**, nunca el de la transacción.
- [x] El `title` del solicitante es `Tu reclamo pasó a "En proceso"` (o `Tu requerimiento pasó a …`), con la etiqueta del catálogo de SPEC 62.
- [x] Su `body` es `{asunto}\n{comentario}\n{d/m/Y H:i}`.
- [x] Sobre un reclamo **público**, cada vecino recibe además una fila con `title = "Novedad en un reclamo de tu condominio"` y `body = "{asunto}\nEstado: En proceso · {d/m/Y H:i}\n{comentario}"`.
- [x] Sobre un reclamo **privado**, ningún vecino recibe nada.
- [x] El solicitante recibe **una sola** notificación aunque el reclamo sea público.
- [x] Se encolan los emails `claim_transaction_requester` y `claim_transaction_neighbour` correspondientes, con el comentario **completo** (sin recorte) en el cuerpo.
- [x] **Editar** una transacción existente no genera fila, push ni email.
- [x] Una transacción creada desde `node/add/claim_transaction` (formulario nativo) notifica igual que una creada desde el formulario propio de SPEC 57.

**Recorte y formato de los textos**

- [x] Un asunto de más de 80 caracteres aparece recortado con `…` en el título/cuerpo del push y en el asunto del email; uno de 80 o menos aparece íntegro y **sin** `…`.
- [x] Un comentario de más de 120 caracteres aparece recortado con `…` en el push; el email lo muestra completo.
- [x] Todas las fechas de push, inbox y emails usan `d/m/Y H:i`; en ningún texto aparece el formato `YYYY-MM-DD`.
- [x] Con el asunto ya recortado a 80, `myapi_onesignal_truncate_body()` nunca corta la primera línea del cuerpo.

**Degradados**

- [x] Un reclamo con `field_requester` vacío no notifica a nadie por la vía del solicitante, deja un `watchdog` de warning, y —si es público— el fan-out al condominio ocurre igual.
- [x] Un solicitante sin correo recibe push e inbox; su email se salta con un `watchdog` y la creación responde igual.
- [x] Un reclamo sin condominio resuelto no produce fan-out al condominio ni emails a `administrador edificio`, y su fila lleva `condominium_id = NULL`.
- [x] Una transacción con `field_comment` vacío produce un cuerpo de dos líneas, sin línea en blanco colgando.
- [x] Un `field_status` sin etiqueta resoluble produce el título `Novedad en tu reclamo` y omite la línea `Estado:` del vecino, sin errores PHP.
- [x] Un `field_claim_type` ausente o desconocido produce el sustantivo `Reclamo`, igual que SPEC 61.
- [x] Un condominio sin propietarios ni ocupantes activos no encola nada y no produce error.
- [x] Si no hay ningún usuario activo con rol `backend` ni `administrador edificio` del condominio, la creación desde la app funciona igual y no se encola ningún email de detalle.
- [x] Un correo inválido en la lista de destinatarios no impide el envío a los demás.
- [x] Un fallo de envío reintenta hasta 3 veces y luego se descarta con un `watchdog` de error; la cola no queda atascada.

**Integridad y guardián**

- [x] Ningún uid recibe dos filas por el mismo evento, en ninguna combinación de visibilidad, rol y pertenencia al condominio.
- [x] Un re-guardado del mismo nodo dentro de la misma request notifica como máximo una vez (`drupal_static()` por nid).
- [x] Un fallo dentro de cualquiera de los tres orquestadores no impide el guardado del nodo ni cambia la respuesta del endpoint.

**Colas y transporte**

- [x] `drush cron` drena `myapi_mail_send` y `myapi_onesignal_push`, y los correos salen.
- [x] Los cinco emails llegan en **HTML** (no convertidos a texto plano) tras `drush updb`.
- [x] Los emails se arman con los datos capturados en el momento del disparo: borrar el reclamo entre el disparo y la corrida de cron no impide ni altera el envío.

**No regresión**

- [x] `POST /api/v1/claims` sigue respondiendo `201` con el mismo cuerpo; `POST /api/v1/claims/{id}`, `200`; los `GET` de claims, idénticos.
- [x] Las notificaciones de reserva (SPEC 48) siguen funcionando idénticas tras generalizar sus dos resolutores de destinatarios: mismos uids, mismos emails, mismos asuntos.
- [x] Las notificaciones de pago (27/30), alícuota (28) y boletín (25/26) no cambian.
- [x] El email de password reset (SPEC 07) sigue llegando en HTML.
- [x] La línea de tiempo del reclamo (SPEC 57) muestra las mismas transacciones que antes: este spec no crea ni borra ninguna.
- [x] El sync de estado transacción → reclamo (SPEC 57) sigue funcionando.
- [x] La suite PHPUnit queda en verde, con los tests nuevos de `ClaimNotificationTest.php` incluidos.
- [x] `drush updb && drush cc all` no reporta errores.

**Documentación**

- [x] `docs/claim-notifications.md` documenta los tres disparadores, la matriz de destinatarios, los textos exactos, las dos banderas, el `deep_link.target = "claim"` nuevo y los casos degradados.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Ubicación de los tres disparadores | Hooks de nodo (`hook_node_insert()` / `hook_node_update()`), con la API aportando solo una bandera | Disparar dentro de `myapi_claim_create()` y `myapi_claim_update()` | El reclamo se crea por **dos** vías y se publica por **dos** vías; enganchar en los endpoints obligaría a duplicar la llamada y dejaría fuera el back office, que es justo el Caso 1. Con el hook, un alta por drush o una migración también notifica al residente. |
| Distinguir Caso 1 de Caso 2 | Bandera transitoria `$node->myapi_claim_from_api`, puesta antes del `node_save()` de SPEC 66 | Detectar por rol del usuario actual; detectar por si hay una sesión de token activa | Elección del usuario. Copia exacta del mecanismo ya probado en SPEC 30/48. Detectar por rol es frágil: un administrador podría usar la API en el futuro y su reclamo dejaría de avisar a back office. El default sin bandera es el conservador — **no** mandar emails. |
| Alcance del Caso 1 | El back office **no** recibe email cuando el reclamo se crea desde el back office | Notificar siempre a `backend` | Elección del usuario. El operador que radica el reclamo ya sabe que existe. Misma decisión que tomó SPEC 48 para las reservas creadas por un operador. |
| Detectar "la primera transacción" | Bandera `$transaction->myapi_skip_claim_notification` en `myapi_claim_transaction_create_initial()` | Contar las transacciones existentes del reclamo (`COUNT > 1`) | Elección del usuario. El conteo depende del orden de guardado dentro del `node_save()` del reclamo y se rompe si alguien borra la transacción inicial. La bandera es explícita, cero consultas, y el default (notificar) es el esperado para cualquier vía futura. |
| Notificación de la transacción inicial | Ninguna | Notificarla como cualquier otra | El hecho que registra —"el reclamo fue recibido"— ya lo notifica la creación del reclamo. Notificar las dos cosas sería el mismo mensaje dos veces con dos minutos de diferencia. |
| Alcance de las notificaciones de edición | Solo la transición `private` → `public` | Notificar cualquier edición; no notificar ninguna (como dejó SPEC 67) | Elección del usuario. Cambiar el asunto o la descripción no cambia quién puede ver el reclamo; hacerlo público sí, y ahí hay gente nueva que no sabe que existe. |
| Transición `public` → `private` | No notifica nada | Avisar a los vecinos de que dejó de ser visible | No se puede "des-avisar" a quien ya leyó el reclamo, y el aviso solo serviría para señalar que hubo algo que ya no se puede consultar. |
| Fecha de la línea `Publicado el` | Fecha de recepción cuando el reclamo nace público; hora del guardado cuando pasa de privado a público | Siempre `field_reception_date`; siempre la hora actual | En ambos casos la línea afirma lo mismo: el instante en que ese vecino pudo verlo. Usar siempre la recepción haría que un reclamo publicado seis días después dijera una fecha que para el vecino no significó nada. |
| Un solo texto para el vecino en creación pública y en publicación | Sí: mismo `type` (`claim_published`) y misma key de correo | Un `type` y una key por evento | Desde el vecino los dos hechos son idénticos: un reclamo pasó a ser visible para él. Separarlos serían dos plantillas iguales que mantener y dos ramas del `switch` de `myapi_mail()`. Bajó las keys de correo de seis a cinco. |
| El solicitante dentro del fan-out público | Recibe **su** texto (`claim_created` / el de solicitante en transacciones) y queda **excluido** del fan-out de vecinos | Un texto único y neutro para todos; incluirlo en el fan-out y aceptar dos notificaciones | Elección del usuario. Un texto único no puede ser cierto para los dos: *"Se publicó un reclamo en tu condominio"* es falso para quien lo escribió. La exclusión garantiza **una** notificación por persona, que es lo que pedía "sin duplicar usuarios". |
| "Todos los usuarios del condominio" | Propietarios **y** ocupantes de las viviendas del condominio, activos, vía `myapi_condominium_member_uids($condo, 'todos')` | Todos los usuarios del sitio; solo propietarios | Elección del usuario. Coincide con la regla de visibilidad de SPEC 64: un reclamo público solo lo ven los vecinos de su condominio, así que notificar más allá avisaría de algo que el destinatario no puede abrir. |
| Reclamo sin `field_requester` | No se notifica por la vía del solicitante + `watchdog` de warning; el fan-out público sí ocurre | Notificar al autor del nodo (`node.uid`); fallar en silencio | En el back office `field_requester` lo elige el operador y puede quedar vacío; el autor del nodo sería entonces el propio operador, que recibiría un acuse de un reclamo que no es suyo. |
| Destinatarios de back office | `backend` (todos) + `administrador edificio` **del condominio del reclamo**, deduplicados | Solo `backend`; incluir `administrator`; no acotar los `administrador edificio` por condominio | Petición explícita del usuario. El acotamiento por condominio es el mismo criterio que SPEC 48 ya aplica: mandar el detalle a los operadores de otro edificio sería ruido y una fuga de datos de residentes ajenos. |
| Emails a back office en transacciones y publicación | Ninguno | Notificar también esos dos eventos | Elección del usuario. Ambos los origina el propio operador desde el back office; avisarle de lo que acaba de escribir es ruido. |
| Canales de la transición a público | Push + inbox + email, igual que la creación | Solo push + inbox | Elección del usuario. Coherencia: un reclamo que se vuelve visible llega por los mismos tres canales que uno que nace visible. |
| Estructura del push | Título = el **evento**, cuerpo = asunto + datos | Título = el asunto del reclamo (borrador original) | Elección del usuario. Con el asunto como título, los tres eventos del mismo reclamo llegan con un título idéntico y el residente no sabe en la pantalla bloqueada si le recibieron el reclamo, si un vecino publicó uno o si hay una novedad. |
| Estado en el aviso de transacción | En el **título** para el solicitante, en el **cuerpo** para el vecino | Omitirlo (borrador original); ponerlo en el cuerpo para los dos | El estado es la información del evento: sin él, el residente tiene que abrir la app para saber qué pasó. El título del vecino no puede gastarlo porque antes tiene que decir que el reclamo no es suyo. |
| Línea `Tipo: Reclamo/Requerimiento` en el push | Eliminada del cuerpo; el sustantivo va en el **título** (`Reclamo recibido` / `Requerimiento recibido`) | Dejarla como línea propia (borrador original) | Es un dato de catálogo, no un evento: ocupaba una de las tres líneas útiles sin decir nada que el título no diga ya. |
| Descripción del reclamo en el push | Fuera; solo el asunto | Incluirla (borrador original) | El solicitante ya sabe qué escribió, y una descripción larga llega mutilada a media frase por el corte de 200 de `myapi_onesignal_truncate_body()`. La descripción completa sí va en el email, donde cabe. |
| Recorte de asunto y comentario | Asunto a 80, comentario a 120, con `…` | Dejar que OneSignal corte el cuerpo entero a 200 | Sin recortar el asunto, un asunto de 255 caracteres consume el push entero y la fecha y el estado no llegan nunca. Recortando la primera línea, el corte de 200 solo puede alcanzar al final del comentario. |
| Formato de fecha | `d/m/Y H:i` en todo | `YYYY-MM-DD HH:MM` (borrador original) | `YYYY-MM-DD` es formato de máquina. Todo lo que ve el usuario final en el módulo —calendario, panel de reclamos, emails de reserva— ya usa `d/m/Y`. |
| Frase de acuse del email al solicitante | La misma que SPEC 61 guarda en la transacción inicial, tuteada | Redactar una propia | Que el correo y la primera fila de la línea de tiempo digan literalmente lo mismo evita que el residente lea dos versiones distintas del mismo acuse. |
| Línea "Vivienda" en el email a back office | Fuera | Derivarla de las viviendas del solicitante | El `reclamo` **no tiene** `field_unit` — solo condominio. Un solicitante con dos viviendas en el mismo condominio la haría ambigua, y adivinarla sería peor que omitirla. |
| Archivos del reclamo en el email | Solo el conteo (`2 imágenes, 1 documento`) | Adjuntarlos; enlazarlos | Viven en `private://` y su descarga exige token (SPEC 65): un enlace desde el correo respondería `404`. El conteo sirve para priorizar sin prometer nada. |
| Envío del email de detalle | Uno por destinatario | Uno solo con todos los operadores en copia | Un correo inválido no arrastra a los demás, y ningún operador ve la dirección de los otros. Mismo criterio que SPEC 48. |
| Idioma | Fijo en español, sin `myapi_t()` | Traducir según `Accept-Language` | Elección del usuario. Los tres disparadores corren dentro de hooks de `node_save()`, donde no hay `Accept-Language`; traducir solo el que nace de la API dejaría los avisos del mismo reclamo en idiomas distintos. Mismo criterio que SPEC 27/28/30/48. |
| Transporte | Push e inbox síncronos; emails por la cola `myapi_mail_send` con 3 reintentos | Todo síncrono; todo diferido | Elección del usuario. Un reclamo público de un condominio grande son decenas de envíos SMTP: síncronos, sumarían latencia al `201` y un SMTP caído podría tumbar la creación. El inbox y el push, que son el canal inmediato, no dependen de la cola. |
| Resolutores de destinatarios de back office | Generalizados a `includes/myapi.notification.inc`; SPEC 48 queda con dos envoltorios de una línea | Duplicar las dos queries en el include nuevo; moverlas y reescribir los llamadores de SPEC 48 | Aprobado por el usuario. Duplicar viola la regla 3 de `CLAUDE.md`. Los envoltorios dejan la firma pública de SPEC 48 intacta, así que su spec sigue describiendo con exactitud lo que hace. |
| `deep_link.target` | Valor nuevo `"claim"` | Reutilizar `"bulletin"`; enlazar a la transacción | Un reclamo no es un boletín y el inbox de la app los distingue por ese campo. El deep link apunta siempre al **reclamo**, no a la transacción: la app abre el detalle con su línea de tiempo completa, que es donde la novedad tiene contexto. |
| Ubicación del código | Un include nuevo, `includes/myapi.claim_notification.inc` | Meterlo en `myapi.claim_transaction_admin.inc` o en `myapi.notification.inc` | Mismo criterio que SPEC 48: un include por dominio de notificación. `myapi.notification.inc` guarda lo genérico y reutilizable; lo específico de reclamos, su propio archivo. |
| Guardián de doble disparo | `drupal_static()` por nid en cada orquestador | Ninguno | Mismo patrón que SPEC 28/48. Un re-guardado del mismo nodo dentro de la request (una Rule, un `node_save()` encadenado) duplicaría el push sin él. |
| Un spec o dos | Uno solo (`68`), con los tres disparadores | `68-claim-notifications` + `69-claim-transaction-notifications` | Elección del usuario. Comparten el include, la fila equivalente, la cola de correo y cuatro de las cinco plantillas; partirlos habría dejado el segundo spec siendo casi solo dos textos. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Volumen del fan-out público.** Un reclamo público en un condominio de 200 viviendas son ~400 filas en `myapi_notifications`, ~400 pushes y ~400 emails encolados, por cada evento — y una transacción más multiplica lo mismo otra vez. | El inbox y el push son inserciones y encolados baratos; el coste real es SMTP, y por eso va a cola y drena por lotes en cada cron. Si el volumen se vuelve un problema, agrupar en un solo envío multi-destinatario es un cambio localizado en el orquestador. Queda documentado en `docs/claim-notifications.md` para que la administración sepa qué cuesta marcar un reclamo como público. |
| **Privacidad: "público" significa que la descripción llega al correo de cada vecino.** Un residente puede marcar público un reclamo con datos que no querría difundir, sin dimensionar que no es "visible si alguien entra a mirar" sino "enviado a todos". | Es la consecuencia buscada de lo que el usuario pidió, no un efecto lateral. Se documenta explícitamente en `docs/claim-notifications.md` para que la app pueda advertirlo en el selector de visibilidad. El mismo texto ya viaja en el push, así que el email no amplía el conjunto de datos difundidos. |
| **Un reclamo hecho público por error difunde de forma irreversible.** Volverlo privado después no des-envía los correos ni borra los pushes ya entregados. | Es una propiedad del mundo, no del código: la misma que ya documentó SPEC 67 para la visibilidad. La transición inversa no notifica nada precisamente para no llamar la atención sobre lo que se quiso ocultar. |
| **La bandera de origen no llega al hook.** Si `$node->myapi_claim_from_api` no sobreviviera al ciclo de `node_save()`, los reclamos creados desde la app dejarían de avisar a back office. | En Drupal 7 los hooks de `node_save()` reciben el mismo objeto `$node` que el llamador modificó. Patrón estándar del core, ya en producción por SPEC 30 y 48. Cubierto por un criterio de aceptación explícito, y el fallo es del lado seguro: se deja de mandar un email, no se manda de más. |
| **La bandera de opt-out de la transacción inicial no llega al hook.** Ahí el fallo **sí** es visible: cada reclamo notificaría dos veces el mismo hecho con segundos de diferencia. | Mismo mecanismo y mismo respaldo que la anterior, más un criterio de aceptación que lo verifica en los dos caminos de creación. Es la primera comprobación a hacer si aparecen avisos duplicados. |
| **Generalizar los dos resolutores de SPEC 48 toca código `Implemented`.** Un error en los envoltorios rompería las notificaciones de reserva, que están en producción. | El cuerpo se mueve sin cambios salvo el nombre del rol como parámetro, y el paso 1 del plan verifica con `drush php-eval` que las cuatro funciones devuelven los mismos uids que antes. Hay criterios de no regresión explícitos sobre los emails de reserva. |
| **`deep_link.target = "claim"` es un valor nuevo que la app aún no conoce.** Una notificación cuyo destino la app no sabe abrir puede quedarse sin acción, o romper el manejador. | Se documenta como parte del contrato en `docs/claim-notifications.md`, con la regla explícita de degradar a "abrir el inbox" ante un target desconocido. El inbox muestra la notificación igual, así que el contenido nunca se pierde aunque el deep link no funcione todavía. |
| **Cambio del literal `'public'`** en los `allowed_values` de `field_visibility` dejaría de detectar la transición, en silencio. | La comparación usa `MYAPI_CLAIM_VISIBILITY_PUBLIC`, fuente única en el include nuevo. El valor lo fija `_myapi_claims_install()` en `myapi.install`, que es quien crea el campo. |
| **El cron no corre o corre poco.** Los emails viven en cola: el residente recibe el push al instante y el correo tarde o nunca. | Riesgo heredado y ya aceptado desde SPEC 25 (push) y 48 (correo). Se documenta que la entrega del correo depende del cron. El inbox y el push, que son el canal inmediato, no dependen de la cola de correo. |
| **Alta masiva de transacciones** (una migración, un import) dispararía una notificación por cada una. | El default es notificar, y es el correcto para el uso real. Silenciar una ruta nueva es una línea: `$node->myapi_skip_claim_notification = TRUE;`. Queda documentado junto a la bandera. |
| **Mover un reclamo de condominio** (SPEC 67 lo permite) deja notificados a vecinos del condominio anterior, y las transacciones siguientes van al nuevo. | No es un disparador de este spec, y el resultado es el correcto: quien deja de poder ver el reclamo deja de recibir sus novedades. Lo ya enviado no se puede retirar; se documenta. |
| **Coste de `myapi_condominium_member_uids()` por evento.** Cada transacción sobre un reclamo público recorre las viviendas del condominio y sus referencias de propietario y ocupante. | Es la misma query que el boletín de condominio ya ejecuta desde SPEC 25, con el mismo volumen y sin problemas reportados. Corre **después** del `node_save()`, así que su latencia no alarga ninguna transacción de base de datos. |
| **La rama nueva de `'reclamo'` en `myapi_node_update()`.** Hoy esa función no tiene ninguna, y un `return;` mal colocado podría cortocircuitar las ramas de `claim_transaction`, `reservation` o pago que ya existen. | La rama se añade con el mismo patrón de guarda por `$node->type` + `return;` que ya usan las demás, y hay criterios de no regresión sobre las notificaciones de reserva, pago, alícuota y boletín, más el sync de estado de SPEC 57. |
| **Un fallo dentro de un orquestador podría tumbar la creación de un reclamo.** Corre dentro del `node_save()` del endpoint. | Los tres son best-effort por diseño: ninguna rama lanza excepción, y toda ausencia (condominio, solicitante, correo, área) degrada a `NULL` con su etiqueta o a un `watchdog`. Hay un criterio de aceptación explícito de que ningún fallo cambia la respuesta del endpoint. |
| **Sin preferencias de notificación, nadie puede silenciar nada.** Un condominio con muchos reclamos públicos puede volverse ruidoso y empujar a los residentes a desactivar los pushes de la app entera. | Reconocido y fuera de alcance a propósito. Si el ruido aparece, el spec de preferencias por usuario tiene un punto de enganche limpio: filtrar la lista de uids dentro de `myapi_claim_condominium_uids()`, sin tocar ni un texto ni un disparador. |

---

## Lo que **NO** está en este spec

- Notificar cualquier edición del reclamo que no sea la transición `private` → `public`.
- La transición `public` → `private`.
- Notificar la edición de una transacción existente, o el borrado de un reclamo o una transacción.
- Notificar la transacción inicial automática.
- Email a back office cuando el reclamo se crea desde el back office, y en los eventos de transacción y publicación.
- Traducir los textos vía `myapi_t()`.
- Preferencias de notificación por usuario.
- Adjuntar o enlazar los archivos del reclamo en el email.
- Deep link a una transacción concreta.
- Deduplicación entre eventos distintos.
- Recordatorios, SLA o escalado por reclamos sin atender.
- Endpoints nuevos o cambios en el sobre de respuesta.
- Configuración de SMTP.

Cada uno de ellos, si llega, va en su propio spec.
