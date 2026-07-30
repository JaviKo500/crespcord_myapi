# Reservation notifications (created / cancelled)

These are **not REST endpoints**. They are three behaviors triggered around the
reservation lifecycle (SPEC 48, extended by SPEC 50):

- A reservation **created** through `POST /api/v1/reservations` notifies the
  resident (push + inbox + email) and emails the detail to every active user
  holding the `backend` role.
- A reservation **cancelled by an operator** from the back office notifies the
  resident (push + inbox + email) and nobody else.
- A reservation **cancelled by its own resident** through
  `PUT /api/v1/reservations/%/cancel` emails the detail to every active
  `backend` user and sends the resident nothing.

No endpoint changed its contract: `POST /api/v1/reservations` still answers
`201` with the same body and `PUT /api/v1/reservations/%/cancel` still answers
`200` with the same body, now with an optional `cancel_reason` in the request
and one more key in the response. See `docs/reservation.md` for the API itself.

**The cancellation reason** (SPEC 50) is optional in both paths — the body of
the endpoint and the `Motivo de cancelación` field of the node form. Without
one, every text below is byte for byte the one SPEC 48 produced.

---

## Files

| File | Role |
|------|------|
| `includes/myapi.reservation_notification.inc` | The whole behavior: constants, the equivalent row, the cancellation detector, the two notify functions and the `backend` recipient query. |
| `includes/myapi.mail_queue.inc` | Generic deferred mail queue (`myapi_mail_send`), reusable by any future resource. |
| `includes/myapi.mail.inc` | The mail formatters and their HTML builders, next to the password reset one: one formatter per audience (resident, `backend`), each serving its two keys. |
| `myapi.module` | Glue only: `MYAPI_MAIL_QUEUE`, the `hook_mail()` branches (the two resident keys share one, the two `backend` keys share another), the queue worker registration and the `reservation` branch of `hook_node_update()`. |
| `resources/reservation.resource.inc` | The creation trigger, the cancellation opt-out flag and the `backend` email trigger of the cancel endpoint, one call each. |
| `myapi.install` | Maps the four mail keys to `MyapiHtmlMailSystem` (`myapi_update_7010()`, `myapi_update_7011()`) and creates `field_cancel_reason` (`myapi_update_7011()`). |

After deploying, run:

```bash
drush updb && drush cc all
```

`drush updb` is **not optional**: without `myapi_update_7010()` /
`myapi_update_7011()` the mail keys fall back to `DefaultMailSystem` and the
HTML body arrives converted to plain text, and without `myapi_update_7011()`
there is no `field_cancel_reason` to store a reason in.

---

## Triggers

| Event | Fires from | Notifies |
|-------|-----------|----------|
| Reservation created | `myapi_reservation_create()`, after the transaction commits and before the `201` | Resident (push + inbox + email) + every active `backend` user (email) |
| Reservation cancelled **by an operator** | `myapi_node_update()` → `myapi_reservation_is_cancellation_transition()` | Resident only (push + inbox + email) |
| Reservation cancelled **by its resident** | `myapi_reservation_cancel()` → `myapi_reservation_notify_user_cancelled()`, after `node_save()` and before the `200` | Every active `backend` user only (email) |

Read the matrix column by column: the operator's cancellation never mails
`backend`, and the resident's cancellation never reaches the resident. The
operator who cancels already knows, and their colleagues see it in the
calendar; the resident who cancels just did it on screen.

### What does NOT notify anybody

| Case | Why |
|------|-----|
| A reservation created by an operator from `node/add/reservation` | The trigger lives **inside** the endpoint function. There is deliberately **no `reservation` branch in `hook_node_insert()`**, so no node save can reach it. |
| A reservation created programmatically (drush, migration, import) | Same reason. |
| `PUT /api/v1/reservations/%/cancel`, **as far as the resident is concerned** | The endpoint sets the opt-out flag before saving (see below): no push, no inbox row, no resident email. A resident is not notified of their own action. The `backend` email is a separate trigger and does go out. |
| A cancellation from the back office, **as far as `backend` is concerned** | The `backend` email fires from the endpoint only, never from `hook_node_update()`. |
| Editing the reason of an already cancelled reservation | The detector only looks at the status transition, so correcting a typo never sends a second push. |
| Saving a reason with the status still `confirmed` | Nothing fires and the value is simply stored, ready for the day the reservation is cancelled. |
| Editing an already `cancelled` reservation | The detector requires the previous status to be different from `cancelled`. |
| Editing a `confirmed` reservation without touching the status | The incoming status is not `cancelled`. |
| Saving a node of any other type | The `reservation` branch of `myapi_node_update()` is never entered. |

### The cancellation detector

`myapi_reservation_is_cancellation_transition($node)` returns `TRUE` only when
**all** of these hold:

1. `$node->myapi_skip_reservation_notification` is not set (opt-out).
2. `$node->original` exists (it is an update of a stored node, not an insert).
3. The incoming `field_reservation_status` is `cancelled`.
4. The previous `field_reservation_status` is **not** `cancelled`.

The transition is read from the status field and never from
`field_cancelled_by`, which an operator may well leave empty.

### The opt-out flag

`$node->myapi_skip_reservation_notification = TRUE;` is a **transient node
property** — not a field, never persisted — set by `myapi_reservation_cancel()`
right before its `node_save()`. In Drupal 7 the node hooks receive the same
`$node` object the caller modified, so the flag is visible in
`hook_node_update()`. Same mechanism as `myapi_skip_cancel_notification` in
SPEC 30.

The default is therefore **"notify the resident of every cancellation that does
not come from the app endpoint"**. Any future path that cancels reservations
(drush, another endpoint, a Rule) will notify unless it sets the same flag —
which is one line. The flag has never governed the `backend` email: that one is
fired explicitly by the endpoint, so a programmatic `node_save()` that happens
to set `field_cancelled_by = 'user'` sends nothing.

---

## Push + inbox

One call to `myapi_notification_create()` inserts the inbox row and enqueues the
OneSignal push, exactly as bulletins, payments and fees do. The inbox is
synchronous and immediate; the push is deferred to the `myapi_onesignal_push`
queue.

| Column | Created | Cancelled |
|--------|---------|-----------|
| `uid` | `field_requester` | `field_requester` |
| `source_type` | `reservation` | `reservation` |
| `source_nid` | reservation nid | reservation nid |
| `type` | `reservation_created` | `reservation_cancelled` |
| `title` | `Reserva creada` | `Reserva cancelada` |
| `deep_link_target` | `reservation` | `reservation` |
| `deep_link_id` | reservation nid | reservation nid |
| `condominium_id` / `unit_id` | from `field_condominium` / `field_unit`, or `NULL` | idem |

Body, three lines:

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

Four lines when the operator typed a reason:

```
Tu reserva del área "Cancha de golf" ha sido cancelada por un operador.
Fecha: 27/07/2026
Horario: 09:00 - 10:00
Motivo: Mantenimiento de la piscina
```

- The date is `d/m/Y`, the same format the calendar shows.
- A reservation crossing midnight ends its schedule line with ` (+1 día)`, e.g.
  `Horario: 22:00 - 02:00 (+1 día)`. Without it, the line would read as a
  20-hour booking.
- If the area no longer exists the first line drops the quoted name altogether
  (`Tu reserva ha sido confirmada.`) instead of printing empty quotes.
- The `Motivo` line exists **only on a cancellation** and **only when there is a
  reason**: a creation never carries it, and a cancellation without one is byte
  for byte the three-line body above. A reason of only whitespace counts as no
  reason.
- The three fixed lines are around 90 characters, well under the 200-character
  push cut of `myapi_onesignal_truncate_body()`. **A long reason can push past
  it**, and what gets trimmed (with an ellipsis) is the tail of the reason. This
  is accepted: the inbox row and the email always carry it in full, and the
  field is capped at 255 characters anyway.
- The reason travels **raw**, like the area title: this body is stored in
  `myapi_notifications` and rendered by the app as plain text, never as HTML.

The texts are **fixed in Spanish** and do not go through `myapi_t()`, the same
criterion as specs 27/28/30: the cancellation fires inside a
`hook_node_update()` where there is no `Accept-Language` to resolve.

---

## Emails

All four are HTML (CrespCord branding, inline styles) and all four leave
through the deferred mail queue.

| Mail key | Recipient | Subject |
|----------|-----------|---------|
| `reservation_created_user` | The resident | `Reserva confirmada — Cancha de golf, 27/07/2026` |
| `reservation_cancelled_user` | The resident | `Reserva cancelada — Cancha de golf, 27/07/2026` |
| `reservation_created_admin` | Every active `backend` user, one item each | `Nueva reserva #501513 — Gimnasio, 27/07/2026` |
| `reservation_cancelled_admin` | Every active `backend` user, one item each | `Reserva cancelada #501513 — Gimnasio, 27/07/2026` |

### To the resident

Greeting `Hola {nombre}`, a context sentence, and the data block:

| Line | Example |
|------|---------|
| Área | `Gimnasio` |
| Condominio | `Condominio jk` |
| Vivienda | `Casa JK 1 - Condominio jk - javiko500` |
| Fecha | `27/07/2026` |
| Horario | `09:00 - 11:00` (with ` (+1 día)` when it crosses midnight) |
| Duración | `2h 0min` |
| Motivo | `Mantenimiento de la piscina` — last line, **only on a cancellation with a reason** |

Context sentence: `Tu reserva ha sido confirmada.` on creation,
`Tu reserva ha sido cancelada por un operador.` on cancellation. The footer
carries `Reserva #501513` and, **only on the cancellation**, the closing line:

```
Si crees que se trata de un error, comunícate con la administración de tu condominio.
```

### To the `backend` role

Two keys, one builder: they share ten of their twelve lines, so duplicating the
function and the template would only guarantee they diverge at the first layout
change.

Headed by `Reserva #501513`, with the **ten lines of the calendar detail panel**,
in the same order and with the same labels:

`Usuario`, `Email`, `Vivienda`, `Área`, `Condominio`, `Fecha`, `Horario`,
`Duración`, `Estado`, `Creada`.

The cancellation variant (`reservation_cancelled_admin`) adds two more:

| Line | Value |
|------|-------|
| `Cancelada por` | `Usuario`, fixed — this email only exists for the resident's own cancellation |
| `Motivo` | The reason, **only when there is one**; without it the line is not drawn at all |

Its heading reads `Reserva cancelada`, its context sentence
`Un residente ha cancelado su reserva desde la aplicación.`, and `Estado` is
always `Cancelada`.

> The calendar detail panel prints the raw stored value (`user` / `admin`) under
> `Cancelada por`; the email prints the label `Usuario`. Aligning the calendar to
> the labels is a separate change.

The values are produced by the very label helpers the calendar uses
(`myapi_calendar_user_label()`, `myapi_calendar_unit_label()`,
`myapi_calendar_area_label()`, `myapi_calendar_condominium_label()`,
`myapi_calendar_duration_label()`), applied to a row built by
`myapi_reservation_notification_row()` with the same shape
`myapi_reservation_calendar_rows()` returns. An operator reading the email and
an operator reading `admin/content/reservation-calendar` therefore see the same
reservation described identically, absence labels included. The schedule uses
the calendar's en dash (`–`); push, inbox and the resident email use the simple
hyphen (`-`).

On the creation variant (`reservation_created_admin`) neither `Cancelada por`
nor `Motivo` appears: the reservation is always `Confirmada` there. Only the
mail key decides this — the two extra values are ignored if they somehow reach
the creation email.

Below the data block, a **`Ver reserva`** button links to the reservation's own
node page (`node_url`, built with `url('node/<nid>', ['absolute' => TRUE])`,
i.e. `/?q=node/<nid>` on a site with clean URLs off). Same destination as the
`Ver más` button of the calendar detail panel (`docs/reservation-calendar.md`).

### Who exactly is a `backend` recipient

`myapi_reservation_backend_uids()` crosses `users` ⨝ `users_roles` ⨝ `role`:

- Matched by role **name** (`backend`), never by rid — the rid varies per
  environment (same reasoning as `myapi_calendar_admin_roles()` in SPEC 47).
- Only **active** accounts (`users.status = 1`). A blocked user receives nothing.
- `uid 1` receives nothing unless the `backend` role is actually assigned to it.
- The `administrator` role receives nothing.
- **No condominium filter**: every active `backend` user is notified of every
  reservation, because there is no user↔condominium link for that role today.

The detail email is sent on **creation** and on the **resident's own
cancellation**. A cancellation performed by an operator from the back office
never mails the `backend` role — the operator who did it knows, and their
colleagues see it in the calendar.

Volume note: each `backend` user now receives one email per creation **and** one
per resident cancellation. With the handful of `backend` accounts a site has,
and a queue that drains in batches, this is not a concern; if it ever became
one, grouping into a single multi-recipient send is a change confined to
`myapi_reservation_enqueue_admin_mails()`.

---

## The mail queue

`myapi_mail_send`, a generic queue (`includes/myapi.mail_queue.inc`) with no
knowledge of reservations, so any future resource can reuse it:

```php
myapi_mail_queue_enqueue($key, $to, $params);   // one item per recipient
```

- **Delivery depends on cron.** The push and the inbox row are the immediate
  channel; the emails leave on the next cron run. If the site's cron is stopped
  or widely spaced, the mail arrives late. Same accepted risk as the OneSignal
  push queue since SPEC 25.
- **Retries:** a failed send is re-enqueued with an incremented counter up to
  `MYAPI_MAIL_QUEUE_MAX_ATTEMPTS` (3) and then dropped with a `watchdog` of
  level *error*. A permanently rejected address can never block the queue.
- **One item per recipient**, so an invalid address in the `backend` list does
  not prevent delivery to the others.
- **The params travel already resolved and escaped** (strings, never nids). The
  email describes what was true at trigger time: deleting the reservation or
  renaming the area between the trigger and the cron run neither breaks nor
  alters the send.

Drain it like the push queue:

```bash
drush cron                       # drains every queue
drush queue-run myapi_mail_send  # drains only this one
```

**Production must have a cron entry that actually reaches this queue.** The
dedicated push cron in `docs/notifications-produccion.md` (Option B) only runs
`queue-run myapi_onesignal_push` — it does **not** touch `myapi_mail_send`. If
that is the only cron configured on the server, these emails get enqueued and
never sent. See the updated crontab in `docs/notifications-produccion.md` step 6,
which adds a dedicated `queue-run myapi_mail_send` line (or relies on a general
`drush cron` if one is already scheduled).

---

## Degraded cases

| Case | Behavior |
|------|----------|
| Resident with no email address | Push and inbox go out normally; the email is skipped with a `watchdog` warning. Neither the API response nor the node save is affected. |
| Reservation with no `field_requester` | Nobody to notify: no inbox row, no push, no resident email. The `backend` email still goes out, with `Sin usuario` / `—`. |
| Deleted area | `Área eliminada (#123)` in the emails; the push/inbox body drops the quoted name. |
| Deleted unit / no unit | `Sin vivienda` or `Vivienda eliminada (#123)`, exactly as the calendar shows. |
| Deleted user account | `Usuario eliminado (#789)` in the `backend` email. |
| No condominium | `Sin condominio`; the notification's `condominium_id` stays `NULL`. |
| Nobody holds the `backend` role | The creation and the cancellation work the same and no detail email is enqueued. |
| Reservation cancelled with no reason | Every text drops its `Motivo` line and reads exactly as it did before SPEC 50. |
| Reservation cancelled before SPEC 50 existed | Same as above: `field_cancel_reason` is empty, so no line is drawn. No data migration was needed. |
| Any failure while notifying | Caught and logged with `watchdog_exception()`. The notification is best-effort by contract: the reservation is already committed, so nothing here may turn a successful `201` into a `500`, and nothing may break a node save. |
| Re-save of the same node within one request | A `drupal_static()` guard keyed by nid means a node notifies at most once per request. |

---

## Related docs

- `docs/reservation.md` — the reservation endpoints themselves.
- `docs/reservation-calendar.md` — the back-office calendar whose label helpers
  and detail panel this feature reuses.
- `docs/notification.md` — the inbox resource the app reads.
- `docs/notifications-produccion.md` — cron and queue setup in production.
