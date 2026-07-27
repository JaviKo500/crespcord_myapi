# 48 — Notificaciones de reserva creada y cancelada

- **Estado:** Implemented
- **Fecha:** 2026-07-27
- **Dependencias:**
  - `35-create-reservation` (Implemented) — `myapi_reservation_create()` en `resources/reservation.resource.inc`; este spec **modifica** esa función para disparar la notificación tras el `node_save()`.
  - `36-cancel-reservation` (Implemented) — `myapi_reservation_cancel()`; este spec **modifica** esa función para marcar el nodo con una bandera de opt-out, de modo que la cancelación del propio residente no notifique a nadie.
  - `25-notifications-inbox-boletin` / `26-notification-condominium-unit-context` (Implemented) — `myapi_notification_create()` (inbox + encolado del push de OneSignal en una sola llamada) y las columnas `condominium_id`/`unit_id` de `myapi_notifications`.
  - `30-payment-cancelled-notification` (Implemented) — patrón espejo del que este spec copia: detección de transición en `hook_node_update()` y bandera transitoria de opt-out en el nodo.
  - `47-reservation-calendar-admin` (Implemented) — helpers de etiqueta reutilizados tal cual para el email de detalle (`myapi_calendar_user_label()`, `myapi_calendar_unit_label()`, `myapi_calendar_area_label()`, `myapi_calendar_condominium_label()`, `myapi_calendar_duration_label()`) y `myapi_calendar_admin_roles()` como referencia de rol de back office.
  - `07-password-reset` (Implemented) — `MyapiHtmlMailSystem`, `myapi_mail_system_register()` y el patrón de email HTML con branding CrespCord de `includes/myapi.mail.inc`.
- **Objetivo:** Cuando se crea una reserva vía `POST /api/v1/reservations`, notificar al residente (push + inbox + email) y enviar un email de detalle a todos los usuarios activos con rol `backend`; cuando un operador la cancela desde el back office, notificar al residente (push + inbox + email); ni la reserva **creada** por un operador desde el back office ni la cancelación hecha por el propio residente desde la app notifican a nadie.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.reservation_notification.inc`** (nuevo) — toda la lógica del spec:
  - Constantes: `MYAPI_NOTIFICATION_SOURCE_RESERVATION` (`'reservation'`), `MYAPI_NOTIFICATION_TYPE_RESERVATION_CREATED` (`'reservation_created'`), `MYAPI_NOTIFICATION_TYPE_RESERVATION_CANCELLED` (`'reservation_cancelled'`), `MYAPI_NOTIFICATION_DEEP_LINK_RESERVATION` (`'reservation'`), `MYAPI_RESERVATION_STATUS_CANCELLED` (`'cancelled'`).
  - `myapi_reservation_is_cancellation_transition($node)` — detecta el update de back office a `'cancelled'`, respetando la bandera de opt-out (patrón de spec 30).
  - `myapi_reservation_notification_row($node)` — arma desde el nodo un objeto con **la misma forma de fila** que devuelve `myapi_reservation_calendar_rows()` (`nid`, `created`, `date`, `start_time`, `end_time`, `status`, `area_id`, `area_title`, `unit_id`, `unit_title`, `condominium_id`, `uid`, `user_name`, `user_mail`, `cancelled_by`), para poder pasarla a los helpers de etiqueta de spec 47 sin duplicar formato ni tocar el calendario.
  - `myapi_reservation_notify_created($node)` / `myapi_reservation_notify_cancelled($node)` — arman título y cuerpo, llaman a `myapi_notification_create()` (inbox + push) y encolan los emails.
  - `myapi_reservation_backend_uids()` — uids **activos** con rol `backend`.

- **`includes/myapi.mail_queue.inc`** (nuevo) — cola de envío diferido de correo, genérica y reutilizable por cualquier resource futuro: `myapi_mail_queue_enqueue($key, $to, array $params)` y `myapi_mail_queue_worker($item)` (llama a `drupal_mail('myapi', $key, $to, $language, $params)`). Mismo patrón que la cola de OneSignal.

- **`includes/myapi.mail.inc`** (modificar) — tres formateadores nuevos y sus constructores de HTML, junto al de password reset:
  - `myapi_mail_format_reservation_created_user()` / `..._cancelled_user()` — email al residente.
  - `myapi_mail_format_reservation_created_admin()` — email de detalle a `backend`, con los mismos campos y etiquetas del panel del calendario, maquetado con estilos inline.

- **`myapi.module`** (modificar) — solo glue:
  - Constante `MYAPI_MAIL_QUEUE` (`'myapi_mail_send'`) en la cabecera, junto a `MYAPI_ONESIGNAL_QUEUE` y por el mismo motivo (`hook_cron_queue_info()` la referencia antes de que su include esté cargado).
  - `hook_mail()`: tres ramas nuevas (`reservation_created_user`, `reservation_cancelled_user`, `reservation_created_admin`).
  - `hook_cron_queue_info()`: registrar el worker de la cola de correo.
  - `hook_node_update()`: rama nueva para `$node->type === 'reservation'`.

- **`resources/reservation.resource.inc`** (modificar):
  - `myapi_reservation_create()` — tras el `node_save()` y **fuera** de la transacción, llamar a `myapi_reservation_notify_created($node)`.
  - `myapi_reservation_cancel()` — marcar `$node->myapi_skip_reservation_notification = TRUE;` antes del `node_save()`.

- **`myapi.install`** (modificar) — `myapi_mail_system_register()` / `..._unregister()` pasan a mapear también las tres keys nuevas a `MyapiHtmlMailSystem`, más un `hook_update_N()` que aplique el mapeo en las instalaciones existentes.

- **`myapi.info`** (modificar) — `files[]` para los dos includes nuevos.

- **`docs/reservation-notifications.md`** (nuevo) — disparadores, destinatarios, textos exactos, campos de cada email y el opt-out.

### Fuera de este spec

- **Notificar la creación de una reserva hecha por un operador desde el back office** — no se envía **nada a nadie**: ni push, ni fila de inbox, ni email al residente, ni email de detalle a `backend`. El disparo vive dentro de `myapi_reservation_create()`, que solo se ejecuta en el endpoint, y **no hay ninguna rama de `reservation` en `hook_node_insert()`**, así que un `node_save()` de alta —desde el formulario de nodo, drush, una migración o un import— no tiene por dónde disparar.
- **Notificar la cancelación hecha por el residente desde la app** (`PUT /api/v1/reservations/%/cancel`) — no se notifica a **nadie**: ni al residente, ni por email, ni a `backend`.
- **Email a los usuarios con rol `administrator`** — solo el rol `backend`.
- **Filtrar los destinatarios `backend` por condominio** — se notifica a todos los activos con ese rol, sin acotar por condominio.
- **Email de detalle a `backend` en la cancelación** — el detalle solo se envía al crear.
- **Traducir los textos vía `myapi_t()`** — fijos en español, mismo criterio que specs 27/28/30.
- **Notificar otros cambios de la reserva** (reprogramación, recordatorio previo, "tu reserva empieza en 1 hora") — cada uno, su propio spec.
- **Nuevos endpoints o cambios en el sobre de respuesta** — este spec no toca la API pública; los endpoints 35 y 36 siguen respondiendo exactamente igual.
- **Deduplicación de notificaciones/emails** — best-effort, mismo criterio que specs 27/28/30.
- **Configuración de SMTP** — se usa `drupal_mail()` tal como esté configurado el sitio.
- **Cambios en la página del calendario (spec 47)** — se leen sus helpers, no se modifica ninguno.

---

## Modelo de datos

Este spec **no agrega tablas ni columnas**: `myapi_notifications` ya tiene todo lo necesario desde spec 26. Lo que define son constantes, dos formas de dato en memoria (la fila de reserva y el ítem de cola de correo) y los textos exactos.

### Constantes nuevas

```php
// includes/myapi.reservation_notification.inc
define('MYAPI_NOTIFICATION_SOURCE_RESERVATION', 'reservation');
define('MYAPI_NOTIFICATION_TYPE_RESERVATION_CREATED', 'reservation_created');
define('MYAPI_NOTIFICATION_TYPE_RESERVATION_CANCELLED', 'reservation_cancelled');
define('MYAPI_NOTIFICATION_DEEP_LINK_RESERVATION', 'reservation');
define('MYAPI_RESERVATION_STATUS_CANCELLED', 'cancelled');
define('MYAPI_RESERVATION_NOTIFY_ROLE', 'backend');

// myapi.module (cabecera, junto a MYAPI_ONESIGNAL_QUEUE)
define('MYAPI_MAIL_QUEUE', 'myapi_mail_send');
```

### Bandera transitoria de opt-out

`$node->myapi_skip_reservation_notification` — propiedad transitoria (no es un campo, no se persiste) que `myapi_reservation_cancel()` pone en `TRUE` antes de su `node_save()`. Ausente en cualquier otra ruta de guardado. Mismo mecanismo que `myapi_skip_cancel_notification` de spec 30.

### `myapi_reservation_notification_row($node)` — fila equivalente

Objeto con **exactamente** los nombres de propiedad que produce `myapi_reservation_calendar_rows()`, para que los helpers de etiqueta de spec 47 lo consuman sin cambios:

| Propiedad | Origen |
|---|---|
| `nid`, `created` | del propio nodo |
| `date` | `field_date`, recortado a `YYYY-MM-DD` |
| `start_time`, `end_time` | `field_start_time`, `field_end_time` |
| `status` | `field_reservation_status` |
| `cancelled_by` | `field_cancelled_by`, o `NULL` |
| `area_id` / `area_title` | `field_area`; el título vía `node_load()`, `NULL` si el área ya no existe |
| `unit_id` / `unit_title` | `field_unit`; ídem |
| `condominium_id` | `field_condominium`, o `NULL` |
| `uid` / `user_name` / `user_mail` | `field_requester`; nombre y correo vía `user_load()`, `NULL` si la cuenta no existe |

Cada ausencia queda en `NULL`, que es justo lo que los helpers de spec 47 ya saben degradar (`Sin vivienda`, `Usuario eliminado (#789)`, etc.).

### Textos de push + inbox

Ambas notificaciones usan el mismo `$params` de `myapi_notification_create()`:

| Clave | Creada | Cancelada |
|---|---|---|
| `source_type` | `"reservation"` | `"reservation"` |
| `source_nid` | nid de la reserva | nid de la reserva |
| `type` | `"reservation_created"` | `"reservation_cancelled"` |
| `title` | `Reserva creada` | `Reserva cancelada` |
| `deep_link_target` | `"reservation"` | `"reservation"` |
| `deep_link_id` | nid de la reserva | nid de la reserva |
| `condominium_id` / `unit_id` | de la fila, o `NULL` | de la fila, o `NULL` |
| `uids` | `[field_requester]` | `[field_requester]` |

Cuerpo, tres líneas:

```
Tu reserva del área "Cancha de golf" ha sido confirmada.
Fecha: 27/07/2026
Horario: 09:00 - 10:00
```

```
Tu reserva del área "Cancha de golf" ha sido cancelada por un operador.
Fecha: 27/07/2026
Horario: 09:00 - 10:00
```

- `{área}` = `area_title`; si el área ya no existe, la primera línea cae a `Tu reserva ha sido confirmada.` / `... cancelada por un operador.` (sin comillas vacías).
- Fecha en `d/m/Y` vía `format_date()`, igual que el panel del calendario.
- Si la reserva cruza medianoche (`end_time <= start_time`), la línea de horario es `Horario: 22:00 - 02:00 (+1 día)`, el mismo indicador que usa el calendario.
- El cuerpo completo ronda los 90 caracteres, muy por debajo del corte de 200 de `myapi_onesignal_truncate_body()`.

### Emails — asuntos

| Email | Asunto |
|---|---|
| Residente, creada | `Reserva confirmada — Cancha de golf, 27/07/2026` |
| Residente, cancelada | `Reserva cancelada — Cancha de golf, 27/07/2026` |
| `backend`, creada | `Nueva reserva #501513 — Gimnasio, 27/07/2026` |

### Email al residente — contenido

Cabecera CrespCord + saludo `Hola {nombre}` + frase de contexto, y debajo el bloque de datos:

| Línea | Valor |
|---|---|
| Área | `Gimnasio` |
| Condominio | `Condominio jk` |
| Vivienda | `Casa JK 1 - Condominio jk - javiko500` |
| Fecha | `27/07/2026` |
| Horario | `09:00 - 11:00` (con `(+1 día)` si cruza medianoche) |
| Duración | `2h 0min` |

Frase de contexto: `Tu reserva ha sido confirmada.` / `Tu reserva ha sido cancelada por un operador.` Al pie, `Reserva #501513` y, solo en la cancelación, una línea de cierre: `Si crees que se trata de un error, comunícate con la administración de tu condominio.`

### Email a `backend` — contenido

Los mismos campos y **las mismas etiquetas** que el panel de detalle del calendario, en el mismo orden, encabezado por `Reserva #501513`:

`Usuario`, `Email`, `Vivienda`, `Área`, `Condominio`, `Fecha`, `Horario`, `Duración`, `Estado`, `Creada`.

Los valores se obtienen llamando a los helpers de spec 47 sobre la fila equivalente. `Cancelada por` no aparece nunca: este email solo existe en la creación, donde el estado siempre es `Confirmada`.

### Ítem de la cola de correo

```php
['key' => 'reservation_created_user', 'to' => 'javiko500@gmail.com', 'params' => [...]]
```

Los `params` viajan **ya resueltos y escapados** (cadenas, no nids): el email se arma con lo que era cierto en el momento del disparo, y una reserva borrada o un área renombrada entre el disparo y la corrida de cron no rompen ni alteran el envío. Un ítem por destinatario, así que un correo inválido no arrastra a los demás.

---

## Plan de implementación

**1. Cola de correo genérica.** Crear `includes/myapi.mail_queue.inc` con la constante de reintentos y las dos funciones:

```php
define('MYAPI_MAIL_QUEUE_MAX_ATTEMPTS', 3);

function myapi_mail_queue_enqueue($key, $to, array $params) {
  if (!valid_email_address($to)) {
    watchdog('myapi', 'Mail "@key" skipped: invalid or empty recipient.', ['@key' => $key], WATCHDOG_WARNING);
    return FALSE;
  }
  DrupalQueue::get(MYAPI_MAIL_QUEUE)->createItem([
    'key' => $key, 'to' => $to, 'params' => $params, 'attempts' => 0,
  ]);
  return TRUE;
}

function myapi_mail_queue_worker($item) {
  if (empty($item['key']) || empty($item['to'])) {
    return;
  }
  $message = drupal_mail('myapi', $item['key'], $item['to'], language_default(), $item['params']);

  if (empty($message['result'])) {
    $attempts = isset($item['attempts']) ? (int) $item['attempts'] + 1 : 1;
    if ($attempts < MYAPI_MAIL_QUEUE_MAX_ATTEMPTS) {
      $item['attempts'] = $attempts;
      DrupalQueue::get(MYAPI_MAIL_QUEUE)->createItem($item);
      watchdog('myapi', 'Mail "@key" to @to failed; retry @n queued.', ['@key' => $item['key'], '@to' => $item['to'], '@n' => $attempts], WATCHDOG_WARNING);
    }
    else {
      watchdog('myapi', 'Mail "@key" to @to dropped after @n attempts.', ['@key' => $item['key'], '@to' => $item['to'], '@n' => $attempts], WATCHDOG_ERROR);
    }
  }
}
```

Agregar `MYAPI_MAIL_QUEUE` en la cabecera de `myapi.module` (con el mismo guardián `if (!defined(...))` que `MYAPI_ONESIGNAL_QUEUE`), registrar el worker en `myapi_cron_queue_info()` junto al de OneSignal, y añadir `files[] = includes/myapi.mail_queue.inc` a `myapi.info`. `drush cc all`.
*Verificación: encolar a mano un ítem con una key existente (`password_reset`) y correr `drush cron` → el correo sale y la cola queda vacía.*

**2. Include de notificación de reservas.** Crear `includes/myapi.reservation_notification.inc` con las constantes de la sección anterior, `myapi_reservation_notification_row($node)` y `myapi_reservation_backend_uids()`. Esta última cruza `users` ⨝ `users_roles` ⨝ `role` con `role.name = MYAPI_RESERVATION_NOTIFY_ROLE` y `users.status = 1`, devolviendo uids únicos. Se compara por **nombre de rol, nunca por rid** (mismo criterio que `myapi_calendar_admin_roles()` en spec 47: el rid varía por entorno). Añadir `files[] = includes/myapi.reservation_notification.inc` a `myapi.info`. `drush cc all`.
*Verificación: `drush php-eval` sobre `myapi_reservation_backend_uids()` devuelve los uids esperados; sobre `myapi_reservation_notification_row(node_load(N))` devuelve la fila con los mismos nombres de propiedad que el calendario.*

**3. Emails.** En `includes/myapi.mail.inc`, añadir los tres formateadores y sus constructores de HTML (tabla con estilos inline, misma paleta CrespCord que el de password reset). En `myapi.module`, ampliar `myapi_mail()`:

```php
function myapi_mail($key, &$message, $params) {
  module_load_include('inc', 'myapi', 'includes/myapi.mail');
  switch ($key) {
    case 'password_reset':
      myapi_mail_format_password_reset($message, $params);
      break;

    case 'reservation_created_user':
    case 'reservation_cancelled_user':
      myapi_mail_format_reservation_user($message, $params, $key);
      break;

    case 'reservation_created_admin':
      myapi_mail_format_reservation_created_admin($message, $params);
      break;
  }
}
```

En `myapi.install`, extender `myapi_mail_system_register()` / `myapi_mail_system_unregister()` con las tres keys nuevas (`myapi_reservation_created_user`, `myapi_reservation_cancelled_user`, `myapi_reservation_created_admin`) y agregar un `hook_update_N()` que llame a `myapi_mail_system_register()` para aplicarlo donde el módulo ya está instalado. `drush updb && drush cc all`.
*Verificación: `drush php-eval` disparando `drupal_mail('myapi', 'reservation_created_user', ...)` con params de prueba → llega un correo con el HTML intacto (no convertido a texto plano).*

**4. `myapi_reservation_notify_created($node)`** en el include del paso 2: arma la fila, construye título/cuerpo, llama a `myapi_notification_create()` con el `$params` de la sección anterior, encola el email al residente (si `user_mail` no es `NULL`) y un email por cada uid de `myapi_reservation_backend_uids()` que tenga correo. Toda la función es best-effort: ninguna rama lanza excepción.
*Verificación: llamarla a mano sobre una reserva existente → una fila en `myapi_notifications`, un ítem en la cola de OneSignal y N+1 en la de correo.*

**5. Enganche en la creación.** En `myapi_reservation_create()` (`resources/reservation.resource.inc`), después del `unset($transaction)` y **antes** del `myapi_respond()`:

```php
module_load_include('inc', 'myapi', 'includes/myapi.reservation_notification');
myapi_reservation_notify_created($node);
```

Fuera de la transacción a propósito: la reserva ya está confirmada en BD, y ni un fallo al notificar ni la latencia del encolado deben poder tumbar o retrasar el `201`.
*Verificación: `POST /api/v1/reservations` válido → `201` idéntico al de antes, y la notificación en el inbox del residente.*

**6. Detección y notificación de la cancelación.** En el mismo include:

```php
function myapi_reservation_is_cancellation_transition($node) {
  if (!empty($node->myapi_skip_reservation_notification)) {
    return FALSE;
  }
  if (!isset($node->original)) {
    return FALSE;
  }
  $previous = myapi_reservation_node_value($node->original, 'field_reservation_status');
  $incoming = myapi_reservation_node_value($node, 'field_reservation_status');

  return $incoming === MYAPI_RESERVATION_STATUS_CANCELLED
    && $previous !== MYAPI_RESERVATION_STATUS_CANCELLED;
}
```

Y `myapi_reservation_notify_cancelled($node)`, gemela de la del paso 4 pero con el `type`/título/cuerpo de cancelación y **sin** los emails a `backend`.
*Verificación: llamar al detector con `original` en `'confirmed'` e incoming `'cancelled'` → `TRUE`; con la bandera puesta → `FALSE`; con ambos `'cancelled'` → `FALSE`; sin `original` → `FALSE`.*

**7. Hook y opt-out.** En `myapi_node_update()` de `myapi.module`, rama nueva:

```php
if ($node->type === 'reservation') {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_notification');
  if (myapi_reservation_is_cancellation_transition($node)) {
    myapi_reservation_notify_cancelled($node);
  }
  return;
}
```

Y en `myapi_reservation_cancel()`, antes del `node_save($node)`: `$node->myapi_skip_reservation_notification = TRUE;`. `drush cc all`.
*Verificación: cancelar desde el back office → notificación + email al residente; cancelar vía `PUT /api/v1/reservations/%/cancel` → `200` igual que antes y **cero** notificaciones, emails o ítems de cola.*

**8. Documentación.** Crear `docs/reservation-notifications.md`: disparadores de cada evento, destinatarios, textos exactos de push/inbox, asunto y campos de cada email, el opt-out, el comportamiento de la cola (los emails salen en la siguiente corrida de cron, con hasta 3 intentos) y los casos degradados (área/vivienda/cuenta eliminadas, usuario sin correo).

**9. Aplicar y verificar de punta a punta.** `drush updb && drush cc all`, luego: (a) crear reserva desde la app → push, inbox, email al residente y email de detalle a cada `backend`; (b) cancelar desde el back office → push, inbox y email al residente, sin email a `backend`; (c) cancelar desde la app → nada; (d) editar una reserva ya `'cancelled'` sin tocar el estado → nada; (e) `drush cron` drena ambas colas.

---

## Criterios de aceptación

> **Estado de la verificación (2026-07-27).** Una casilla marcada significa
> **verificada estáticamente**: revisión del código implementado, `php -l` sobre
> los seis archivos tocados y la suite PHPUnit (150 tests, 539 asserts, en
> verde). Son criterios cuyo resultado queda determinado por el código y no
> puede variar en ejecución.
>
> Las casillas **sin marcar** requieren el sitio Drupal corriendo (envío real de
> correo, `drush cron`, `drush updb`, o comparar contra la página del
> calendario) y quedan pendientes del paso 9 del plan.

**Reserva creada — notificación al residente**
- [x] Un `POST /api/v1/reservations` exitoso inserta una fila en `myapi_notifications` con `uid` = `field_requester`, `type = "reservation_created"`, `source_type = "reservation"`, `source_nid` = nid de la reserva, `deep_link_target = "reservation"` y `deep_link_id` = nid de la reserva.
- [x] `condominium_id` y `unit_id` de esa fila quedan poblados con los nids de `field_condominium` y `field_unit`.
- [x] El `title` es exactamente `Reserva creada`.
- [x] El `body` es exactamente `Tu reserva del área "{área}" ha sido confirmada.\nFecha: {d/m/Y}\nHorario: {HH:MM} - {HH:MM}`.
- [x] En una reserva que cruza medianoche, la línea de horario termina en ` (+1 día)`.
- [x] Si el área ya no existe, la primera línea es `Tu reserva ha sido confirmada.` (sin comillas vacías) y el resto no cambia.
- [x] Se encola el push de OneSignal correspondiente (un ítem en `myapi_onesignal_push` con ese título y cuerpo).

**Reserva creada — emails**
- [x] Se encola un email `reservation_created_user` al correo del residente, con asunto `Reserva confirmada — {área}, {d/m/Y}`.
- [x] Ese email llega en HTML (no convertido a texto plano) y muestra Área, Condominio, Vivienda, Fecha, Horario, Duración y `Reserva #{nid}`.
- [x] Se encola un email `reservation_created_admin` **por cada** usuario activo con rol `backend` que tenga correo, con asunto `Nueva reserva #{nid} — {área}, {d/m/Y}`.
- [x] Ese email muestra las 10 líneas del panel del calendario (`Usuario`, `Email`, `Vivienda`, `Área`, `Condominio`, `Fecha`, `Horario`, `Duración`, `Estado`, `Creada`) con los mismos valores que muestra `admin/content/reservation-calendar` para esa misma reserva.
- [x] Un usuario con rol `backend` pero **bloqueado** no recibe email.
- [x] Un usuario con rol `administrator` (y sin `backend`) no recibe email.
- [x] El `uid 1` no recibe email salvo que tenga el rol `backend` asignado.

**Reserva cancelada por un operador**
- [x] Cambiar `field_reservation_status` de `'confirmed'` a `'cancelled'` desde el back office inserta una fila en `myapi_notifications` con `type = "reservation_cancelled"` dirigida al `field_requester`.
- [x] El `title` es exactamente `Reserva cancelada` y el `body` exactamente `Tu reserva del área "{área}" ha sido cancelada por un operador.\nFecha: {d/m/Y}\nHorario: {HH:MM} - {HH:MM}`.
- [x] Se encola el push correspondiente y un email `reservation_cancelled_user` al residente, con asunto `Reserva cancelada — {área}, {d/m/Y}`.
- [x] Ese email incluye la línea de cierre `Si crees que se trata de un error, comunícate con la administración de tu condominio.`
- [x] **Ningún** usuario con rol `backend` recibe email por una cancelación.

**No dispara**
- [x] `PUT /api/v1/reservations/%/cancel` (spec 36) no genera fila en `myapi_notifications`, ni ítem en la cola de push, ni ítem en la cola de correo.
- [x] Crear una reserva desde el back office (formulario de nodo en `node/add/reservation`) no genera fila en `myapi_notifications`, ni ítem en la cola de push, ni ítem en la cola de correo — ni para el residente ni para los usuarios `backend`.
- [x] Lo mismo para un alta programática (`node_save()` vía drush, migración o import) de un nodo `reservation`.
- [x] Editar una reserva ya `'cancelled'` (cambiando otros campos, sin tocar el estado) no genera nada nuevo.
- [x] Editar una reserva `'confirmed'` sin cambiar el estado no genera nada.
- [x] Guardar un nodo de cualquier otro tipo no entra en la rama nueva de `myapi_node_update()`.

**Casos degradados**
- [x] Un residente sin correo recibe push e inbox, y su email se salta con un `watchdog`, sin afectar la respuesta ni el guardado.
- [x] Un área, vivienda o cuenta de usuario eliminada produce las mismas etiquetas de ausencia que el calendario (`Sin vivienda`, `Usuario eliminado (#789)`, etc.) en el email a `backend`, sin errores PHP.
- [x] Si no hay ningún usuario activo con rol `backend`, la creación funciona igual y no se encola ningún email de detalle.
- [x] Un fallo de envío reintenta hasta 3 veces y luego se descarta con un `watchdog` de nivel error; la cola no queda atascada.

**Colas y transporte**
- [ ] `drush cron` drena la cola `myapi_mail_send` y los correos salen.
- [x] Los emails se arman con los datos capturados en el momento del disparo: borrar la reserva entre el disparo y la corrida de cron no impide ni altera el envío.
- [x] Un correo inválido en la lista de `backend` no impide el envío a los demás destinatarios.

**No regresión / infra**
- [x] `POST /api/v1/reservations` sigue respondiendo `201` con el mismo cuerpo que antes, y `PUT /api/v1/reservations/%/cancel` sigue respondiendo `200` con el mismo cuerpo.
- [x] El email de password reset (spec 07) sigue llegando en HTML.
- [x] Las notificaciones de pago (specs 27/30), alícuota (28) y boletín (25/26) siguen funcionando idénticas.
- [x] La página `admin/content/reservation-calendar` (spec 47) no cambia: ninguno de sus helpers fue modificado.
- [x] `drush updb && drush cc all` no reporta errores.
- [x] `docs/reservation-notifications.md` documenta disparadores, destinatarios, textos y casos degradados.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Disparador de "creada" | Solo `POST /api/v1/reservations`, enganchado dentro de `myapi_reservation_create()` | `hook_node_insert()` sobre cualquier nodo `reservation` | Elección del usuario: el endpoint es la única vía real de creación desde la app. Engancharlo ahí y **no** en `hook_node_insert()` garantiza por construcción que una reserva creada por un operador desde el panel —o por drush, una migración o un import— no envíe push ni email a nadie. |
| Reserva creada por un operador | No notifica a **nadie**, ni al residente ni a `backend` | Notificar al residente de que le crearon una reserva | Elección explícita del usuario. El operador que la crea ya sabe que existe, y el residente la ve en su lista de reservas; el aviso se reserva para lo que el residente inició (creación desde la app) o para lo que le cambia sin que él intervenga (cancelación por un operador). |
| Disparador de "cancelada" | Solo update de back office a `'cancelled'`, vía `hook_node_update()` | Notificar también la cancelación del residente desde la app / discriminar por `field_cancelled_by` | Elección del usuario, y mismo criterio ya tomado en spec 30: el residente que cancela su propia reserva no se notifica a sí mismo. Detectar la transición de estado es más robusto que leer `cancelled_by`, que un operador podría dejar vacío. |
| Cancelación desde la app | No notifica a **nadie** (ni al residente ni a `backend`) | Avisar por email a `backend` de las cancelaciones de residentes | Elección explícita del usuario: los operadores ven esas cancelaciones en el calendario de spec 47; un email por cada una sería ruido. |
| Mecanismo de opt-out | Bandera transitoria `$node->myapi_skip_reservation_notification` puesta antes del `node_save()` | Detectar por rol del usuario actual o por `field_cancelled_by` | Copia exacta del mecanismo ya probado en spec 30: explícito, robusto y de una línea. Detectar por rol es frágil (un admin también podría usar la API en el futuro). |
| Destinatarios del email de detalle | Todos los usuarios **activos** con rol `backend`, resueltos por **nombre** de rol | Reutilizar `myapi_calendar_admin_roles()` (incluye `administrator`) / filtrar por condominio / usar el rid 4 | Elección del usuario. Por nombre y no por rid porque el rid varía por entorno (mismo razonamiento documentado en spec 47). No se filtra por condominio porque hoy no existe un vínculo usuario↔condominio para ese rol. |
| Email de detalle solo al crear | Solo en la creación | También en la cancelación | Elección del usuario. |
| Transporte de los emails | Todo por cola de cron (`myapi_mail_send`), un ítem por destinatario | Envío síncrono dentro de la request / mixto | Elección del usuario: N usuarios `backend` son N envíos SMTP; síncronos, cada uno sumaría latencia al `201` y un SMTP caído podría colgar la creación de reservas. El costo aceptado es que el correo sale en la siguiente corrida de cron. |
| Cola de correo genérica y no específica de reservas | `includes/myapi.mail_queue.inc`, reutilizable por cualquier resource | Encolar dentro del include de notificación de reservas | Regla 3 de `CLAUDE.md`: los helpers compartidos van a `includes/`. El próximo evento que necesite email diferido no vuelve a escribir el worker. |
| Política de reintento de correo | Hasta 3 intentos, re-encolando el ítem; después se descarta con `watchdog` de error | Lanzar excepción para que la Queue API reintente (como el worker de OneSignal) / descartar al primer fallo | Reintento indefinido sobre un rechazo permanente del SMTP repetiría el mismo envío en cada cron para siempre; descartar al primer fallo perdería correos legítimos por un corte transitorio. El contador acotado cubre ambos casos. |
| Contenido de los ítems de cola | Datos ya resueltos y escapados, no nids | Guardar el nid y recargar el nodo en el worker | El correo describe lo que era cierto cuando ocurrió el evento. Recargar en cron rompería el envío si la reserva se borró y mostraría datos alterados si el área se renombró en el intervalo. |
| Reuso de los helpers del calendario | `myapi_reservation_notification_row()` arma una fila con la misma forma que `myapi_reservation_calendar_rows()` y se la pasa a los helpers de etiqueta de spec 47 | Resolver los 10 valores desde el nodo dentro del include nuevo / refactorizar la query del calendario para aceptar un nid | Elección del usuario: cero duplicación de formato y del manejo de ausencias, sin tocar una línea de código Implemented. Refactorizar la query del calendario tenía más riesgo y ningún beneficio aquí. |
| Push e inbox | Una sola llamada a `myapi_notification_create()` | Dos mecanismos separados | La función ya inserta el inbox y encola el push; separarlos duplicaría lógica sin ganar nada. |
| Formato de fecha | `d/m/Y` en todo (push, inbox y emails) | ISO `Y-m-d` en el push, como venía en el borrador | Lo que ve el usuario final debe ser uno solo; `d/m/Y` es el que ya usa el panel del calendario. |
| Guion del horario | `-` en push, inbox y email al residente; `–` en el email a `backend` | Unificar a uno solo | El email a `backend` replica el panel de detalle del calendario campo por campo, incluido su guion largo; los textos nuevos usan el guion simple del borrador del usuario. |
| Indicador de cruce de medianoche | ` (+1 día)` en la línea de horario | Omitirlo | Sin él, `Horario: 22:00 - 02:00` se lee como una reserva de 20 horas. Es el mismo indicador que ya muestra el calendario. |
| Fecha y horario en el aviso de cancelación | Incluidos | Solo la frase de cancelación, como en el borrador | Un residente con varias reservas en la misma área no sabría cuál le cancelaron. Sigue siendo un cuerpo corto. |
| Idioma | Fijo en español, sin `myapi_t()` | Traducir según `Accept-Language` en la creación | Mismo criterio que specs 27/28/30. La cancelación ocurre dentro de un `hook_node_update()` donde no hay `Accept-Language`; traducir solo la creación dejaría los dos eventos inconsistentes. |
| Emails en HTML | HTML con branding CrespCord, keys registradas en `MyapiHtmlMailSystem` | Texto plano | Elección del usuario. Requiere un `hook_update_N()` para aplicar el mapeo donde el módulo ya está instalado. |
| Ubicación del enganche de creación | Fuera de la transacción, después del `unset($transaction)` | Dentro de la transacción, antes del commit | La reserva ya está confirmada; un fallo al notificar no debe poder revertirla, y el encolado no debe alargar el bloqueo de fila sobre el área. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El cron no corre o corre poco.** Los emails viven en una cola; si el cron del sitio está detenido o espaciado, el residente recibe el push al instante pero el correo llega tarde o nunca. | Es el mismo riesgo que ya tiene el push de OneSignal desde spec 25, aceptado entonces. Se documenta en `docs/reservation-notifications.md` que la entrega del correo depende del cron. La notificación de inbox y el push, que son el canal inmediato, no dependen de la cola de correo. |
| **La bandera de opt-out no llega al hook.** Si `$node->myapi_skip_reservation_notification` no sobreviviera al ciclo de `node_save()`, la cancelación desde la app notificaría por error. | En Drupal 7 los hooks de `node_save()` reciben el mismo objeto `$node` que el llamador modificó, así que una propiedad seteada antes es visible en `hook_node_update()`. Patrón estándar del core, ya validado en producción por spec 30. Cubierto por un criterio de aceptación explícito. |
| **Otra ruta futura que cancele reservas sin marcar la bandera** (drush, otro endpoint, una Rule) disparará la notificación por defecto. | Aceptado y explícito: el default es "notificar toda cancelación que no venga del endpoint actual". Silenciar una ruta nueva es una línea. Se documenta. |
| **Cambio del literal `'cancelled'`** en los `allowed_values` de `field_reservation_status` dejaría de disparar la detección. | La comparación usa `MYAPI_RESERVATION_STATUS_CANCELLED`, fuente única en el include nuevo. El valor está fijado por `_myapi_reservations_install()` en `myapi.install`, que es quien crea el campo. |
| **Re-guardado del nodo dentro de la misma request** (una Rule que recalcule algo y vuelva a guardar con un `$node->original` obsoleto) podría disparar dos veces. | Mismo patrón de guardián que spec 28: `drupal_static()` por nid dentro de la función de notificación, de modo que un nodo notifica como máximo una vez por request. |
| **Crecimiento de la cola de correo** si hay muchos usuarios `backend` y muchas reservas: un ítem por destinatario y por reserva. | El volumen real es bajo (unidades de usuarios `backend` por sitio) y la Queue API drena por lotes en cada cron. Si algún día el rol creciera mucho, la agrupación en un solo envío multi-destinatario es un cambio localizado en `myapi_reservation_notify_created()`. |
| **Fuga de datos personales al rol `backend`.** El email de detalle incluye nombre y correo del residente. | Es exactamente la misma información que el panel de detalle del calendario ya muestra a ese mismo rol (decisión documentada en spec 47); el email no amplía el conjunto de datos ni el de destinatarios. |
| **Registro del mail system olvidado.** Sin el `hook_update_N()`, en un sitio ya instalado las tres keys nuevas caerían en `DefaultMailSystem` y el HTML llegaría convertido a texto plano. | El paso 3 del plan lo incluye explícitamente, y hay un criterio de aceptación que verifica que el correo llega en HTML. |
| **Dependencia de los helpers de spec 47.** Si un helper del calendario cambia de firma, el email a `backend` se rompe. | Es la contrapartida aceptada de no duplicar formato. El acoplamiento es de una sola dirección —el calendario no sabe nada de este spec— y la forma de fila queda documentada en el modelo de datos de este spec como contrato compartido. |
| **Reserva sin condominio, sin vivienda o con cuenta eliminada.** | Todo cae a `NULL` y los helpers de spec 47 ya producen la etiqueta de ausencia correspondiente. `condominium_id`/`unit_id` de la notificación quedan `NULL`, igual que en specs 27/30. Cubierto por criterios de aceptación. |

---

## Lo que **no** entra en este spec

- Notificar la creación de una reserva hecha por un operador desde el back office: no se envía push, inbox ni email a nadie (solo dispara el endpoint).
- Notificar la cancelación hecha por el residente desde la app (no se avisa a nadie).
- Email a usuarios con rol `administrator`, o filtrado de `backend` por condominio.
- Email de detalle a `backend` en la cancelación.
- Traducir los textos vía `myapi_t()`.
- Recordatorios previos a la reserva u otros eventos del ciclo de vida.
- Deduplicación de notificaciones y emails.
- Configuración de SMTP.

Cada una, si aparece, va en su propio spec.
