# 50 — Motivo de cancelación de reserva y aviso a `backend`

- **Estado:** Approved
- **Fecha:** 2026-07-30
- **Dependencias:**
  - `32-reservations-content-types-install` (Implemented) — helpers idempotentes
    `_myapi_reservations_ensure_field()` / `_ensure_instance()` en `myapi.install`,
    que este spec replica para el campo nuevo; también define el bundle
    `reservation` y su `field_cancelled_by`.
  - `36-cancel-reservation` (Implemented) — `myapi_reservation_cancel()`; este
    spec le agrega la lectura del body, la validación del motivo y el disparo del
    email a `backend`.
  - `47-reservation-calendar-admin` (Implemented) — `myapi_reservation_calendar_rows()`
    y `myapi_calendar_render_details()`; este spec les agrega la línea `Motivo`.
  - `48-reservation-notifications` (Implemented) — `includes/myapi.reservation_notification.inc`
    y `includes/myapi.mail.inc`; este spec agrega el motivo a los textos y una
    cuarta key de correo.
  - `35-create-reservation` / `34-unit-reservations-list` / `38-reservation-details`
    (Implemented) — `myapi_reservation_build_item_from_node()` y la query de
    `includes/myapi.reservation_query.inc`, donde entra el campo nuevo del shape.
- **Objetivo:** Agregar a la reserva un motivo de cancelación opcional —escrito por
  el residente en el body del `PUT /api/v1/reservations/%/cancel` o por el operador
  en el formulario del nodo—, exponerlo en todos los endpoints y en el panel del
  calendario, incluirlo en el push/inbox y en el email al residente cuando exista,
  y enviar un email de detalle a los usuarios `backend` cada vez que un residente
  cancela desde la app.

Dos notas transversales:

- **El motivo es siempre opcional**, en los dos caminos. Sin motivo, todos los
  textos actuales quedan **byte a byte** como están hoy.
- **La matriz de disparos queda así:** operador cancela → push + inbox + email
  **solo al residente** (con motivo si lo hay), nada a `backend`; residente cancela
  desde la app → **solo** email a `backend`, el residente no recibe nada.

---

## Alcance

### Entra en este spec

- **`myapi.install`** (modificar):
  - `field_cancel_reason` — tipo `text` (varchar 255), cardinalidad 1, creado con
    `_myapi_reservations_ensure_field()` dentro de `_myapi_reservations_install()`.
  - Instancia sobre el bundle `reservation`: etiqueta `Motivo de cancelación`,
    `required = 0`, widget `text_textfield`, descripción
    `Opcional. Se muestra al residente cuando la reserva se cancela.`
  - `myapi_update_7011()` que llama a `_myapi_reservations_install()` para crear
    el campo en instalaciones existentes, más el registro de la key de correo
    nueva (`myapi_reservation_cancelled_admin`) vía `myapi_mail_system_register()`.
  - `myapi_html_mail_keys()` — agregar `myapi_reservation_cancelled_admin`.
  - `myapi_uninstall()` — agregar `field_cancel_reason` a la lista de campos que
    se borran.

- **`resources/reservation.resource.inc`** (modificar):
  - `myapi_reservation_cancel()` — leer el body opcional, validar
    `cancel_reason`, escribirlo en el nodo antes del `node_save()` y, tras el
    guardado, encolar el email de detalle a `backend`.
  - `myapi_reservation_build_item_from_node()` — agregar `cancel_reason` al shape.

- **`includes/myapi.reservation_query.inc`** (modificar) — `leftJoin` sobre
  `field_data_field_cancel_reason` en la query de listados y `cancel_reason` en
  el ítem construido, para que el shape de la lista sea idéntico al del detalle.

- **`includes/myapi.reservation_notification.inc`** (modificar):
  - `myapi_reservation_notification_row()` — agregar `cancel_reason` a la fila.
  - `myapi_reservation_notification_body()` — cuarta línea `Motivo: …` cuando hay
    motivo y el evento es una cancelación.
  - `myapi_reservation_enqueue_user_mail()` — pasar `cancel_reason` en los params.
  - `myapi_reservation_enqueue_admin_mails($row, $key)` — generalizar la función
    para servir a las dos keys de email a `backend` (creada y cancelada).
  - `myapi_reservation_notify_user_cancelled($node)` — nueva; encola solo el
    email a `backend`, sin tocar al residente. La llama el endpoint.

- **`includes/myapi.reservation_calendar.inc`** (modificar) —
  `myapi_reservation_calendar_rows()` suma el `leftJoin` del campo, y
  `myapi_calendar_render_details()` imprime la línea `Motivo` debajo de
  `Cancelada por`, solo cuando hay motivo.

- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_reservation_user_html()`
  imprime la línea `Motivo` cuando viene en los params;
  `myapi_mail_reservation_admin_html()` pasa a servir las dos keys, agregando
  `Cancelada por` y `Motivo` en la variante de cancelación.

- **`myapi.module`** (modificar) — `myapi_mail()`: la key
  `reservation_cancelled_admin` entra en la misma rama que
  `reservation_created_admin`.

- **`tests/`** (nuevo) — tests unitarios para la validación del motivo, el cuerpo
  del push con y sin motivo, y el encolado del email a `backend`.

- **Documentación** — `docs/reservation.md` (body nuevo del cancel y `cancel_reason`
  en las respuestas), `docs/reservation-notifications.md` (email nuevo, matriz de
  disparos actualizada), `docs/reservation-calendar.md` (línea `Motivo`).

> **Sin claves i18n nuevas.** La validación del motivo reutiliza `invalid_field` y
> `field_too_long`, que ya existen en el catálogo con el placeholder `@field`.

### NO entra en este spec

- **Hacer obligatorio el motivo**, ni para el residente ni para el operador: no hay
  `hook_node_validate()` ni validación condicional en ninguna parte.
- **Notificar al residente cuando él mismo cancela** — sigue sin recibir push,
  inbox ni email; la bandera `myapi_skip_reservation_notification` se mantiene.
- **Email a `backend` cuando cancela el operador** — se mantiene el criterio de
  spec 48.
- **Re-notificar si el motivo se edita después** de la cancelación: el detector de
  transición mira solo `field_reservation_status`.
- **Validar que el motivo solo exista en reservas canceladas**: un operador puede
  guardar un motivo con la reserva en `confirmed` y el campo queda ahí, sin efecto.
- **Motivo en la creación o en cualquier otro evento** de la reserva.
- **Endpoint de cancelación para operadores** — el operador sigue usando el
  formulario de nodo.
- **Ampliar los destinatarios al rol `administrador edificio`** (spec 49, `Draft`).
- **Traducir los textos de notificación vía `myapi_t()`** — fijos en español,
  criterio de specs 27/28/30/48.
- **Filtrar los `backend` por condominio**, ni deduplicar emails.

---

## Modelo de datos

Un campo nuevo, un ítem de cola nuevo y los textos exactos. Nada de tablas propias.

### Campo nuevo

| Propiedad | Valor |
|---|---|
| `field_name` | `field_cancel_reason` |
| `type` | `text` (varchar 255) |
| `cardinality` | 1 |
| Bundle | `reservation` |
| `label` | `Motivo de cancelación` |
| `required` | `0` |
| `widget` | `text_textfield` |
| `description` | `Opcional. Se muestra al residente cuando la reserva se cancela.` |

### Body del `PUT /api/v1/reservations/%/cancel`

Sigue siendo **opcional**: una request sin body funciona exactamente igual que hoy.

```json
{ "cancel_reason": "El evento se pospuso para el mes que viene" }
```

Validación, tras las cinco validaciones ya existentes de spec 36 y **antes** de
tocar el nodo:

| Caso | Resultado |
|---|---|
| Body ausente, vacío o sin la clave | Motivo ausente. Sin error. |
| `cancel_reason` no es string (array, número, `null`) | `422 invalid_field` con `@field = cancel_reason` |
| `trim()` deja la cadena vacía | Motivo ausente. Sin error. |
| `drupal_strlen(trim())` > 255 | `422 field_too_long` con `@field = cancel_reason` |
| Resto | Se guarda el valor ya `trim()`eado en `field_cancel_reason` |

Se mide con `drupal_strlen()` (caracteres) y no con `strlen()` (bytes): la columna
es `varchar(255)` en utf8, así que 255 caracteres acentuados pasan y `strlen()`
los rechazaría de más.

### Shape de la reserva (todos los endpoints)

Una clave más, siempre presente, `null` cuando no hay motivo:

```json
{
  "id": 91,
  "status": "cancelled",
  "cancelled_by": "user",
  "cancel_reason": "El evento se pospuso para el mes que viene"
}
```

Entra en `myapi_reservation_build_item_from_node()` (create, cancel, details) y en
el ítem que arma `includes/myapi.reservation_query.inc` (listados), para que el
shape sea uno solo.

### Fila de notificación

`myapi_reservation_notification_row()` suma la propiedad `cancel_reason` (string o
`NULL`), leída de `field_cancel_reason`, junto a `cancelled_by`.

### Cuerpo del push + inbox — cancelación por operador

Con motivo, cuarta línea:

```
Tu reserva del área "Cancha de golf" ha sido cancelada por un operador.
Fecha: 27/07/2026
Horario: 09:00 - 10:00
Motivo: Mantenimiento de la piscina
```

Sin motivo, el cuerpo es **idéntico** al actual (tres líneas). La línea `Motivo`
solo existe en la cancelación: la creación nunca la lleva.

El push corta a 200 caracteres en `myapi_onesignal_truncate_body()`. Con las tres
líneas actuales (~90 caracteres) y un motivo largo, lo que se recorta es la cola
del motivo; el inbox y el email lo llevan completo. Aceptado.

### Email al residente (`reservation_cancelled_user`)

Línea `Motivo` al final del bloque de datos, después de `Duración`, solo cuando hay
motivo. El resto del email no cambia, incluida la línea de cierre
`Si crees que se trata de un error, comunícate con la administración de tu condominio.`

### Email nuevo a `backend` (`reservation_cancelled_admin`)

- **Disparador:** `PUT /api/v1/reservations/%/cancel` exitoso. Nunca desde el back
  office.
- **Destinatarios:** todos los usuarios activos con rol `backend` que tengan correo,
  un ítem de cola por destinatario (`myapi_reservation_backend_uids()`).
- **Asunto:** `Reserva cancelada #501513 — Gimnasio, 27/07/2026`
- **Cuerpo:** las mismas 10 líneas del panel del calendario que ya usa
  `reservation_created_admin` (`Usuario`, `Email`, `Vivienda`, `Área`,
  `Condominio`, `Fecha`, `Horario`, `Duración`, `Estado`, `Creada`), más:

  | Línea | Valor |
  |---|---|
  | `Cancelada por` | `Usuario` (fijo: este email solo existe en la cancelación del residente) |
  | `Motivo` | El texto, **solo si existe**; sin motivo la línea no aparece |

- **Botón:** `Ver reserva` con `node_url` absoluto, igual que el de creación.
- `Estado` vale siempre `Cancelada` en este email.

> Nota: el panel del calendario imprime hoy el valor crudo del campo
> (`user` / `admin`) en `Cancelada por`. El email usa la etiqueta `Usuario`.
> Alinear el calendario a las etiquetas queda fuera de este spec.

### Línea `Motivo` en el panel del calendario

Debajo de `Cancelada por` y solo cuando la reserva está cancelada **y** tiene
motivo. Sin motivo la línea no aparece (mismo criterio que `Cancelada por` en una
reserva confirmada). Requiere el `leftJoin` del campo en
`myapi_reservation_calendar_rows()`.

### Ítem de cola de correo

Sin cambios estructurales:
`['key' => 'reservation_cancelled_admin', 'to' => ..., 'params' => [...]]`, con los
params **ya resueltos y escapados** en el momento del disparo, criterio de spec 48.

---

## Plan de implementación

**1. El campo.** En `myapi.install`, dentro de `_myapi_reservations_install()`,
agregar `_myapi_reservations_ensure_field('field_cancel_reason', [...])` (tipo
`text`, cardinalidad 1) y
`_myapi_reservations_ensure_instance('field_cancel_reason', 'reservation', [...])`
con la etiqueta, la descripción y `required = 0` de la sección anterior. Sumar
`field_cancel_reason` a la lista de campos de `myapi_uninstall()` y
`myapi_reservation_cancelled_admin` a `myapi_html_mail_keys()`. Crear
`myapi_update_7011()` que llame a `_myapi_reservations_install()` y a
`myapi_mail_system_register()`.
*Verificación: `drush updb && drush cc all`; el campo aparece en
`node/add/reservation` y en la edición de una reserva existente; `drush updb` una
segunda vez no hace nada (idempotencia).*

**2. El motivo en el endpoint.** En `myapi_reservation_cancel()`
(`resources/reservation.resource.inc`), tras la validación 5 (ventana de
cancelación) y antes de escribir el nodo: leer `myapi_request_body()`, validar
`cancel_reason` según la tabla del modelo de datos y, si queda un valor, escribir
`$node->field_cancel_reason[LANGUAGE_NONE][0]['value']`. Agregar `cancel_reason` al
array de `myapi_reservation_build_item_from_node()`.
*Verificación: `PUT` sin body → `200` con `cancel_reason: null`, igual que antes;
con motivo → `200` con el texto y el valor en BD; con `cancel_reason: 123` →
`422 invalid_field`; con 300 caracteres → `422 field_too_long`.*

**3. El motivo en los listados.** En `includes/myapi.reservation_query.inc`,
`leftJoin` sobre `field_data_field_cancel_reason` (mismo patrón que el join de
`field_data_field_cancelled_by`) y `cancel_reason` en el ítem construido.
*Verificación: `GET /api/v1/units/%/reservations` y
`GET /api/v1/reservations/%/details` devuelven `cancel_reason` con el mismo valor
que la respuesta del cancel.*

**4. El motivo en el calendario.** En `includes/myapi.reservation_calendar.inc`: el
mismo `leftJoin` en `myapi_reservation_calendar_rows()`, y en
`myapi_calendar_render_details()` la línea `Motivo` justo después de
`Cancelada por`, dentro del mismo `if ($row->status === 'cancelled')` y con un
guardián extra de motivo no vacío.
*Verificación: en `admin/content/reservation-calendar`, una reserva cancelada con
motivo muestra la línea; una cancelada sin motivo y una confirmada, no.*

**5. El motivo en push, inbox y email al residente.** En
`includes/myapi.reservation_notification.inc`: `cancel_reason` en
`myapi_reservation_notification_row()`; cuarta línea en
`myapi_reservation_notification_body()` cuando `$cancelled` y hay motivo; el motivo
en los params de `myapi_reservation_enqueue_user_mail()`. En
`includes/myapi.mail.inc`, `myapi_mail_reservation_user_html()` imprime la línea
`Motivo` cuando el param viene no vacío.
*Verificación: cancelar desde el back office con motivo → el cuerpo del inbox tiene
las cuatro líneas y el email muestra `Motivo`; sin motivo, ambos quedan idénticos a
los de hoy.*

**6. El email a `backend`.** Generalizar
`myapi_reservation_enqueue_admin_mails($row, $key)` para servir las dos keys: en la
variante `reservation_cancelled_admin` agrega `cancelled_by` (fijo `Usuario`) y
`cancel_reason` a los params. En `myapi.mail.inc`,
`myapi_mail_reservation_admin_html()` pasa a recibir la key y imprime las dos líneas
extra solo en la variante de cancelación; el asunto lo arma
`myapi_mail_format_reservation_created_admin()`, renombrada a
`myapi_mail_format_reservation_admin($message, $params, $key)`. En `myapi.module`,
`reservation_cancelled_admin` entra en la misma rama de `myapi_mail()`.
*Verificación: `drush php-eval` disparando
`drupal_mail('myapi', 'reservation_cancelled_admin', ...)` → llega en HTML con las
12 líneas y el botón.*

**7. El disparo desde el endpoint.** Nueva
`myapi_reservation_notify_user_cancelled($node)` en el include de notificación:
guardián `drupal_static` por nid, `try/catch` que traga toda excepción, arma la fila
y llama **solo** a
`myapi_reservation_enqueue_admin_mails($row, 'reservation_cancelled_admin')` — ni
inbox, ni push, ni email al residente. En `myapi_reservation_cancel()`, después del
`node_save($node)` y antes del `myapi_respond()`:

```php
module_load_include('inc', 'myapi', 'includes/myapi.reservation_notification');
myapi_reservation_notify_user_cancelled($node);
```

La bandera `myapi_skip_reservation_notification` se mantiene tal cual: sigue
silenciando el `hook_node_update()`, y el email a `backend` lo dispara el endpoint
directamente, no el hook.
*Verificación: `PUT /cancel` → `200` con el mismo cuerpo, **cero** filas en
`myapi_notifications`, **cero** ítems en la cola de OneSignal y N ítems en
`myapi_mail_send` (uno por `backend` con correo).*

**8. Tests.** En `tests/`, siguiendo el patrón de la suite existente: validación del
`cancel_reason` (ausente, no-string, whitespace, 255, 256),
`myapi_reservation_notification_body()` con y sin motivo en ambos eventos, y la
elección de params/key en `myapi_reservation_enqueue_admin_mails()`.
*Verificación: `vendor/bin/phpunit` en verde, con los tests de spec 48 intactos.*

**9. Documentación.** `docs/reservation.md` (body opcional del cancel, tabla de
errores con `422 invalid_field` / `422 field_too_long`, `cancel_reason` en todas las
respuestas de reserva), `docs/reservation-notifications.md` (matriz de disparos
actualizada, el email nuevo con su asunto y sus 12 líneas, la línea `Motivo` en
push/inbox/email al residente, el corte de 200 caracteres del push) y
`docs/reservation-calendar.md` (línea `Motivo` en el panel).

**10. Aplicar y verificar de punta a punta.** `drush updb && drush cc all`, luego:
(a) cancelar desde la app con motivo → email a cada `backend` con el motivo, nada
para el residente; (b) cancelar desde la app sin motivo → mismo email sin la línea
`Motivo`; (c) cancelar desde el back office con motivo → push, inbox y email al
residente con la cuarta línea, y ningún email a `backend`; (d) `drush cron` drena
`myapi_mail_send`; (e) crear una reserva → todo idéntico a spec 48.

---

## Criterios de aceptación

**Campo**
- [ ] Tras `drush updb`, `field_cancel_reason` existe con instancia en el bundle
      `reservation`, etiqueta `Motivo de cancelación`, opcional y con widget de
      texto simple.
- [ ] El campo aparece en el formulario de `node/add/reservation` y en la edición de
      una reserva existente.
- [ ] Ejecutar `drush updb` dos veces no duplica ni altera el campo.

**Endpoint — motivo**
- [ ] `PUT /api/v1/reservations/{id}/cancel` **sin body** → `200` con exactamente el
      mismo cuerpo que antes del spec, más `cancel_reason: null`.
- [ ] Con `{"cancel_reason": "texto"}` → `200`, `data.reservation.cancel_reason` es
      el texto y `field_cancel_reason` en BD lo tiene.
- [ ] El valor se guarda con `trim()` aplicado.
- [ ] `{"cancel_reason": "   "}` → `200` con `cancel_reason: null` y el campo vacío
      en BD.
- [ ] `{"cancel_reason": 123}` o un array → `422 invalid_field`, la reserva **no** se
      cancela.
- [ ] Un motivo de 256 caracteres → `422 field_too_long`, la reserva **no** se
      cancela; uno de 255 pasa.
- [ ] Un motivo de 255 caracteres acentuados (`á`, `ñ`) pasa la validación y se
      guarda completo.
- [ ] Las cinco validaciones de spec 36 (auth, existencia, autoría, estado, ventana)
      siguen cortando **antes** que la del motivo: una reserva ajena con un motivo
      inválido devuelve `403 reservation_forbidden`, no `422`.

**Shape en todos los endpoints**
- [ ] `POST /api/v1/reservations` devuelve `cancel_reason: null` en la reserva
      creada.
- [ ] `GET /api/v1/units/%/reservations` y `GET /api/v1/reservations/%/details`
      devuelven `cancel_reason` con el mismo valor que la respuesta del cancel.
- [ ] Una reserva cancelada desde el back office con motivo muestra ese motivo en
      los tres endpoints de lectura.

**Email a `backend` en la cancelación del residente**
- [ ] Un `PUT /cancel` exitoso encola un ítem `reservation_cancelled_admin` por
      **cada** usuario activo con rol `backend` que tenga correo.
- [ ] El asunto es `Reserva cancelada #{nid} — {área}, {d/m/Y}`.
- [ ] El email llega en HTML (no convertido a texto plano) y muestra `Usuario`,
      `Email`, `Vivienda`, `Área`, `Condominio`, `Fecha`, `Horario`, `Duración`,
      `Estado`, `Creada`, `Cancelada por` y `Motivo`.
- [ ] `Estado` vale `Cancelada` y `Cancelada por` vale `Usuario`.
- [ ] Sin motivo, la línea `Motivo` **no aparece** y las otras 11 quedan igual.
- [ ] El email incluye el botón `Ver reserva` con la URL absoluta del nodo.
- [ ] Un `backend` bloqueado no recibe email; un `administrator` sin `backend`
      tampoco.
- [ ] Sin ningún `backend` activo, el `PUT /cancel` responde `200` igual y no se
      encola nada.
- [ ] Un correo inválido en la lista no impide el envío a los demás.

**El residente que cancela sigue sin recibir nada**
- [ ] Un `PUT /cancel` no inserta fila en `myapi_notifications` ni encola push, con
      o sin motivo.
- [ ] No se encola ningún `reservation_cancelled_user`.

**Cancelación por el operador**
- [ ] Cambiar el estado a `'cancelled'` desde el back office **con** motivo → el
      cuerpo del inbox y del push es
      `…cancelada por un operador.\nFecha: …\nHorario: …\nMotivo: {texto}`.
- [ ] **Sin** motivo, el cuerpo es byte a byte el de spec 48 (tres líneas).
- [ ] El email `reservation_cancelled_user` muestra la línea `Motivo` cuando hay
      motivo y la omite cuando no.
- [ ] **Ningún** usuario `backend` recibe email por una cancelación hecha desde el
      back office.
- [ ] Editar solo el motivo de una reserva ya cancelada no genera notificación, push
      ni email nuevos.
- [ ] Guardar un motivo dejando el estado en `'confirmed'` no dispara nada y el
      valor queda almacenado.

**Calendario**
- [ ] El panel de detalle de una reserva cancelada con motivo muestra `Motivo` justo
      debajo de `Cancelada por`.
- [ ] Una reserva cancelada sin motivo no muestra la línea; una confirmada tampoco.
- [ ] El resto del calendario (rejilla, filtros, chips, leyenda) no cambia.

**Creación — no regresión**
- [ ] `POST /api/v1/reservations` sigue disparando push, inbox, email al residente y
      `reservation_created_admin` a cada `backend`, con el mismo asunto y las mismas
      10 líneas de antes.
- [ ] El email de creación **no** muestra `Motivo` ni `Cancelada por`.
- [ ] El email de password reset (spec 07) sigue llegando en HTML.

**Infra y tests**
- [ ] `vendor/bin/phpunit` pasa, incluidos los tests nuevos y los 150 previos.
- [ ] `php -l` limpio en todos los archivos tocados.
- [ ] `drush updb && drush cc all` sin errores.
- [ ] `drush cron` drena `myapi_mail_send` y los correos salen.
- [ ] `docs/reservation.md`, `docs/reservation-notifications.md` y
      `docs/reservation-calendar.md` reflejan lo implementado.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Obligatoriedad del motivo | Opcional en los dos caminos (endpoint y formulario de nodo) | Obligatorio al cancelar, vía `hook_node_validate()` y body requerido | Elección del usuario tras plantearle el análisis: volverlo obligatorio en el endpoint rompe a la app Flutter en producción, que ya llama al `PUT` sin body; y marcar la instancia como `required` lo exigiría también al editar reservas confirmadas, no solo al cancelarlas. |
| Tipo de campo | `text` (varchar 255), widget de una línea | `text_long` (textarea) | Cabe en un push, en un email y en el panel del calendario sin maquetación especial. Un textarea invita a motivos de párrafo que el push cortaría igual. |
| Exposición del motivo | `cancel_reason` en **todos** los endpoints de reserva | Solo en la respuesta del cancel | Elección del usuario. Es una clave más en un shape ya compartido y le permite a la app mostrar el motivo en el historial de reservas. |
| Claves de error | Reutilizar `invalid_field` y `field_too_long` con `@field = cancel_reason` | Clave nueva `cancel_reason_too_long` | Ya existen, ya llevan el placeholder y las usa el resto del módulo; una clave por campo multiplicaría el catálogo sin dar información nueva al cliente. |
| Medida de la longitud | `drupal_strlen()` (caracteres) | `strlen()` (bytes) | La columna es `varchar(255)` en utf8: un motivo de 255 caracteres acentuados es válido en BD y `strlen()` lo rechazaría de más. |
| Motivo vacío o solo espacios | Se trata como ausente, sin error | `422` | Un campo opcional que el usuario dejó en blanco no es un error de formato; devolver `422` obligaría a la app a limpiar el string antes de enviarlo. |
| Disparo del email a `backend` | Desde `myapi_reservation_cancel()`, tras el `node_save()` | Desde `hook_node_update()`, discriminando por `field_cancelled_by = 'user'` | El endpoint es la única vía de cancelación del residente; engancharlo ahí mantiene el criterio de spec 48 (el hook solo ve cancelaciones de back office) y evita que un `node_save()` programático que ponga `cancelled_by = 'user'` mande correos. |
| Bandera de opt-out | Se mantiene tal cual | Quitarla ahora que la cancelación del residente sí notifica algo | Sigue haciendo falta: silencia el push, el inbox y el email **al residente**; lo único nuevo va por otro camino. |
| Notificar al residente cuando él mismo cancela | No se le envía nada | Confirmarle su propia cancelación por push o email | Elección del usuario, mismo criterio de spec 48: acaba de hacerlo en pantalla. |
| Email a `backend` cuando cancela el operador | No se envía | Enviarlo también, por simetría | Elección explícita del usuario: el operador que cancela ya lo sabe y sus compañeros lo ven en el calendario de spec 47. El email existe para enterarlos de lo que hace el residente. |
| Contenido del email nuevo | Las 10 líneas de `reservation_created_admin` + `Cancelada por` + `Motivo` | Un email corto con solo nid, área y motivo | El operador necesita los mismos datos que al crear para decidir si reasigna el espacio; reusar el mismo constructor HTML evita un tercer formato de email. |
| `Motivo` ausente en el email | La línea no aparece | Imprimirla con `—` | Elección del usuario. `Cancelada por` ya sigue ese criterio en el panel del calendario: la línea que no aplica no se dibuja. |
| Generalizar los helpers de email a `backend` | Una sola `myapi_reservation_enqueue_admin_mails($row, $key)` y un solo constructor HTML parametrizado por key | Duplicar función y plantilla para la variante de cancelación | Regla 3 de `CLAUDE.md`: los dos emails comparten 10 de 12 líneas; duplicarlos garantiza que diverjan al primer cambio de maquetación. |
| Etiqueta de `Cancelada por` en el email | `Usuario`, fija | El valor crudo `user`, como imprime hoy el calendario | En el email el valor es siempre `user` y `Cancelada por: user` se lee como un error. Alinear el calendario a las etiquetas es un cambio aparte, no de este spec. |
| Motivo en el push | Viaja en el push, sujeto al corte de 200 caracteres | Motivo solo en inbox y email | Elección del usuario: el push con motivo se entiende sin abrir la app en la mayoría de los casos, y el texto completo queda en el inbox. |
| Línea `Motivo` en el calendario | Se agrega al panel de detalle | Dejar el calendario intacto | Elección del usuario: sin ella el operador no puede leer el motivo que escribió el residente, que es la mitad del valor del campo. |
| Motivo editado después de cancelar | No re-notifica | Detectar el cambio de `field_cancel_reason` y volver a avisar | Elección del usuario. El detector mira solo la transición de estado, igual que en spec 48; corregir una errata no debería generar un segundo push. |
| Motivo con la reserva en `confirmed` | Se permite y no dispara nada | `hook_node_validate()` que lo rechace | Elección del usuario: es un campo de back office sin efecto hasta que la reserva se cancela; validar el estado del formulario añade código para un caso que nadie provoca. |
| Numeración del update | `myapi_update_7011()` | Reservarle el `7011` a la spec 49 (`Draft`) | La numeración la fija el orden real de ejecución, no el de redacción. La spec 49 tomará el siguiente libre cuando se implemente. |
| Tests | Suite PHPUnit ampliada con los casos nuevos | Solo verificación manual, como spec 48 | Elección del usuario. La validación del motivo y la elección de key/params son lógica pura, verificable sin Drupal corriendo. |
| Destinatarios | Solo usuarios activos con rol `backend`, sin filtro por condominio | Incluir `administrador edificio` (spec 49) | La spec 49 está en `Draft` y sin implementar; ampliar destinatarios es cambiar una función y le corresponde a ella. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Corte del push a 200 caracteres.** Un motivo largo llega recortado en la notificación push, con el texto cortado a media palabra. | Aceptado y elegido por el usuario. El inbox y el email llevan el motivo completo, y el corte lo aplica `myapi_onesignal_truncate_body()`, que ya añade la elipsis. Se documenta en `docs/reservation-notifications.md`. |
| **El campo es `varchar(255)` y el widget no lo limita.** Un operador puede pegar un motivo más largo en el formulario de nodo; Drupal lo rechaza con su propia validación de longitud, en inglés y con el mensaje del core. | Aceptado: es el comportamiento estándar de cualquier campo `text` del sitio. La validación propia solo cubre el endpoint, que es el que consume la app. |
| **El email a `backend` se dispara dentro de la request del `PUT`.** Si el encolado falla, la cancelación ya está guardada. | Va después del `node_save()`, envuelto en `try/catch` con `watchdog_exception()`, mismo contrato best-effort que `myapi_reservation_notify_created()`: nada de esto puede convertir un `200` en un `500`. El encolado es una escritura en cola, no un envío SMTP. |
| **Volumen de correo.** Ahora `backend` recibe email por cada creación **y** por cada cancelación de residente: el doble de ítems de cola en el peor caso. | El volumen real es bajo (unidades de usuarios `backend` por sitio) y la Queue API drena por lotes. Si creciera, agrupar en un envío multi-destinatario es un cambio localizado en `myapi_reservation_enqueue_admin_mails()`. |
| **Generalizar `myapi_reservation_enqueue_admin_mails()` y el constructor HTML toca código `Implemented` de spec 48.** Un error ahí rompe también el email de creación, que hoy funciona. | El cambio es aditivo: el segundo parámetro `$key` tiene por defecto `'reservation_created_admin'` y la rama de líneas extra solo se activa en la variante de cancelación. Hay criterios de aceptación explícitos de no regresión sobre el email de creación, y tests unitarios sobre la elección de key y params. |
| **`leftJoin` adicional en la query del calendario.** Una tabla más en una query que ya une siete. | Es un `leftJoin` por `entity_id` sobre una tabla de campo indexada, idéntico al de `field_data_field_cancelled_by` que ya está ahí. El calendario consulta un rango acotado de fechas, no la tabla entera. |
| **El motivo lo escribe el residente y lo leen los operadores.** Es texto libre de usuario final que termina en un email HTML y en una página de administración. | Se guarda crudo y se escapa **en el punto de salida**: `check_plain()` en el panel del calendario y en los params de correo (criterio de spec 48, que ya viaja escapado), y `decode_entities()` en el cuerpo del push/inbox, que la app renderiza como texto plano. Nunca se interpola sin escapar en HTML. |
| **Reservas canceladas antes de este spec** no tienen motivo y su `field_cancel_reason` queda vacío. | El campo es opcional y todo el código trata `NULL` como "sin motivo": las líneas simplemente no aparecen. No hace falta migración de datos. |
| **Un motivo escrito por el operador antes de cancelar** viaja en la notificación del mismo guardado en que cambia el estado. | Es el comportamiento deseado: el operador escribe el motivo y cambia el estado en el mismo `node_save()`, y `myapi_reservation_notification_row()` lee el nodo entrante, no el `original`. |

---

## Lo que **no** entra en este spec

- Hacer obligatorio el motivo, en cualquiera de los dos caminos.
- Notificar al residente cuando él mismo cancela desde la app.
- Email a `backend` cuando la cancelación la hace un operador.
- Re-notificar cuando el motivo se edita después de la cancelación.
- Validar que el motivo solo exista en reservas canceladas.
- Motivo en la creación o en cualquier otro evento del ciclo de vida de la reserva.
- Endpoint de cancelación para operadores.
- Ampliar los destinatarios al rol `administrador edificio` (spec 49).
- Alinear el `Cancelada por` del calendario a las etiquetas `Usuario` / `Admin`.
- Traducir los textos de push, inbox y email vía `myapi_t()`.
- Filtrar los destinatarios `backend` por condominio, o deduplicar emails.

Cada uno, si aparece, va en su propio spec.
