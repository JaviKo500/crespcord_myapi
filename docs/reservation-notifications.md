# Reservation notifications (created / cancelled)

These are **not REST endpoints**. They are two behaviors triggered around the
reservation lifecycle (SPEC 48):

- A reservation **created** through `POST /api/v1/reservations` notifies the
  resident (push + inbox + email) and emails the detail to every active user
  holding the `backend` role.
- A reservation **cancelled by an operator** from the back office notifies the
  resident (push + inbox + email).

Neither endpoint changed: `POST /api/v1/reservations` still answers `201` with
the same body and `PUT /api/v1/reservations/%/cancel` still answers `200` with
the same body. See `docs/reservation.md` for the API itself.

---

## Files

| File | Role |
|------|------|
| `includes/myapi.reservation_notification.inc` | The whole behavior: constants, the equivalent row, the cancellation detector, the two notify functions and the `backend` recipient query. |
| `includes/myapi.mail_queue.inc` | Generic deferred mail queue (`myapi_mail_send`), reusable by any future resource. |
| `includes/myapi.mail.inc` | The three mail formatters and their HTML builders, next to the password reset one. |
| `myapi.module` | Glue only: `MYAPI_MAIL_QUEUE`, the three `hook_mail()` branches, the queue worker registration and the `reservation` branch of `hook_node_update()`. |
| `resources/reservation.resource.inc` | The creation trigger and the cancellation opt-out flag, one call each. |
| `myapi.install` | Maps the three new mail keys to `MyapiHtmlMailSystem` (`myapi_update_7010()`). |

After deploying, run:

```bash
drush updb && drush cc all
```

`drush updb` is **not optional**: without `myapi_update_7010()` the new mail
keys fall back to `DefaultMailSystem` and the HTML body arrives converted to
plain text.

---

## Triggers

| Event | Fires from | Notifies |
|-------|-----------|----------|
| Reservation created | `myapi_reservation_create()`, after the transaction commits and before the `201` | Resident + every active `backend` user |
| Reservation cancelled | `myapi_node_update()` → `myapi_reservation_is_cancellation_transition()` | Resident only |

### What does NOT notify anybody

| Case | Why |
|------|-----|
| A reservation created by an operator from `node/add/reservation` | The trigger lives **inside** the endpoint function. There is deliberately **no `reservation` branch in `hook_node_insert()`**, so no node save can reach it. |
| A reservation created programmatically (drush, migration, import) | Same reason. |
| `PUT /api/v1/reservations/%/cancel` (the resident cancelling from the app) | The endpoint sets the opt-out flag before saving (see below). A resident is not notified of their own action. |
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

The default is therefore **"notify every cancellation that does not come from
the app endpoint"**. Any future path that cancels reservations (drush, another
endpoint, a Rule) will notify unless it sets the same flag — which is one line.

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

- The date is `d/m/Y`, the same format the calendar shows.
- A reservation crossing midnight ends its schedule line with ` (+1 día)`, e.g.
  `Horario: 22:00 - 02:00 (+1 día)`. Without it, the line would read as a
  20-hour booking.
- If the area no longer exists the first line drops the quoted name altogether
  (`Tu reserva ha sido confirmada.`) instead of printing empty quotes.
- The whole body is around 90 characters, well under the 200-character push cut
  of `myapi_onesignal_truncate_body()`.

The texts are **fixed in Spanish** and do not go through `myapi_t()`, the same
criterion as specs 27/28/30: the cancellation fires inside a
`hook_node_update()` where there is no `Accept-Language` to resolve.

---

## Emails

All three are HTML (CrespCord branding, inline styles) and all three leave
through the deferred mail queue.

| Mail key | Recipient | Subject |
|----------|-----------|---------|
| `reservation_created_user` | The resident | `Reserva confirmada — Cancha de golf, 27/07/2026` |
| `reservation_cancelled_user` | The resident | `Reserva cancelada — Cancha de golf, 27/07/2026` |
| `reservation_created_admin` | Every active `backend` user, one item each | `Nueva reserva #501513 — Gimnasio, 27/07/2026` |

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

Context sentence: `Tu reserva ha sido confirmada.` on creation,
`Tu reserva ha sido cancelada por un operador.` on cancellation. The footer
carries `Reserva #501513` and, **only on the cancellation**, the closing line:

```
Si crees que se trata de un error, comunícate con la administración de tu condominio.
```

### To the `backend` role

Headed by `Reserva #501513`, with the **ten lines of the calendar detail panel**,
in the same order and with the same labels:

`Usuario`, `Email`, `Vivienda`, `Área`, `Condominio`, `Fecha`, `Horario`,
`Duración`, `Estado`, `Creada`.

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

`Cancelada por` never appears: this email only exists on creation, where the
status is always `Confirmada`.

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

The detail email is sent **on creation only**. A cancellation never mails the
`backend` role — operators already see it in the calendar.

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
| Nobody holds the `backend` role | The creation works the same and no detail email is enqueued. |
| Any failure while notifying | Caught and logged with `watchdog_exception()`. The notification is best-effort by contract: the reservation is already committed, so nothing here may turn a successful `201` into a `500`, and nothing may break a node save. |
| Re-save of the same node within one request | A `drupal_static()` guard keyed by nid means a node notifies at most once per request. |

---

## Related docs

- `docs/reservation.md` — the reservation endpoints themselves.
- `docs/reservation-calendar.md` — the back-office calendar whose label helpers
  and detail panel this feature reuses.
- `docs/notification.md` — the inbox resource the app reads.
- `docs/notifications-produccion.md` — cron and queue setup in production.
