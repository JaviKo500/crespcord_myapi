# Notifications

Per-user notification inbox (requirement 4.2.7 "Mis Notificaciones") plus the
initial OneSignal push integration.

Notifications are stored as a fan-out: **one row per recipient per source** in
`myapi_notifications`, so read/unread is a column of the row and the inbox is a
plain `SELECT ... WHERE uid = ?`. In this release the only source is a published
`boletin` node: when one is created, `hook_node_insert` resolves the recipients,
inserts a row for each, and enqueues a push. Every endpoint is authenticated and
scoped to the caller — a user never sees or touches another user's rows.

**Body is plain text.** `field_mensaje` may come from a WYSIWYG, so it is
flattened to plain text at fan-out time (`myapi_notification_plain_text()`:
block breaks → newlines, `strip_tags`, entity decode) before it is stored. The
same value feeds both the inbox `body` and the push `contents`, and a push
banner cannot render HTML. Rich formatting (a separate sanitized `body_html`) is
left for a future spec.

**Push is best-effort, the inbox is the source of truth.** The rows are written
synchronously; the push is deferred to a cron queue (`myapi_onesignal_push`). If
OneSignal is unconfigured or fails, the notification is still in the inbox — only
the push is missed. Targeting uses **External User ID**: the app calls
`OneSignal.login(<uid>)`, so the backend sends `include_external_user_ids` with
the Drupal `uid` as a string. Drupal stores no device tokens.

> **Field API coupling.** Recipient resolution reads `field_data_field_*` tables
> directly (`field_tipo_de_boletin`, `field_enviar_a`, `field_condominio`,
> `field_propietario`, `field_ocupante`, `field_ocupantes`, `field_personalizar`,
> `field_mensaje`). Renaming any of these fields or changing their cardinality
> breaks resolution silently.

---

## GET /api/v1/notifications

Paginated list of the authenticated user's notifications, ordered `created DESC`.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. Special value `-1` disables pagination: every notification is returned in one response, `page` is forced to `1`, and `total_pages` is `1` (or `0` when `total` is `0`). |
| `unread` | absent = all | `1` returns only unread notifications. Any other value (or absent) returns all. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "notifications": [
      {
        "id": 4021,
        "type": "bulletin",
        "title": "Corte de agua programado",
        "body": "El sábado de 8:00 a 12:00 se suspende el servicio...",
        "deep_link": { "target": "bulletin", "id": 812 },
        "is_read": false,
        "created_at": 1752566400,
        "read_at": null
      }
    ],
    "unread_count": 3,
    "pagination": { "total": 12, "page": 1, "limit": 20, "total_pages": 1 }
  }
}
```

- `unread_count` is the user's **total** unread count, independent of the
  `unread` filter and of pagination — it is meant for the app badge.
- `deep_link.target` + `deep_link.id` are the same pair the push carries in its
  `data` payload, so opening from the list or from the push lands on the same
  screen.

**Possible errors**
| Code | When |
|------|------|
| 401  | No `Authorization` header (`missing_authorization`), or invalid/expired/revoked token (`invalid_token`). |
| 405  | Any method other than `GET` (`method_not_allowed`). |

---

## PUT /api/v1/notifications/{id}/read

Marks one of the user's own notifications as read. Idempotent: if it was already
read, responds `200` without changing `read_at`.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

`{id}` is the `myapi_notifications` row id. A non-numeric wildcard casts to `0`,
matches nothing, and returns `404`.

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "id": 4021,
    "type": "bulletin",
    "title": "Corte de agua programado",
    "body": "El sábado de 8:00 a 12:00 se suspende el servicio...",
    "deep_link": { "target": "bulletin", "id": 812 },
    "is_read": true,
    "created_at": 1752566400,
    "read_at": 1752570000
  },
  "message": "Notificación marcada como leída."
}
```

**Possible errors**
| Code | When |
|------|------|
| 401  | Missing/invalid token. |
| 404  | The id does not exist **or** belongs to another user (`notification_not_found`); the two cases are not distinguished. |
| 405  | Any method other than `PUT` (`method_not_allowed`). |

---

## PUT /api/v1/notifications/read-all

Marks every unread notification of the user as read.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Success response (200)**
```json
{
  "success": true,
  "data": { "marked": 3 },
  "message": "Notificaciones marcadas como leídas."
}
```

- `marked` is how many rows were flipped to read (`0` if there were none).

**Possible errors**
| Code | When |
|------|------|
| 401  | Missing/invalid token. |
| 405  | Any method other than `PUT` (`method_not_allowed`). |

---

## Recipient resolution (boletin trigger)

When a **published** `boletin` node (`status = 1`) is inserted, recipients are
resolved by crossing its audience scope (`field_tipo_de_boletin`) with its role
(`field_enviar_a`). A draft (`status = 0`) notifies nobody; editing or
publishing it later does not fire either.

| `field_tipo_de_boletin` | Universe | Role filter (`field_enviar_a`) |
|---|---|---|
| `General` | Owners/occupants of every published `vivienda`. | Propietarios / Ocupantes / Todos. |
| `Condominio` | Owners/occupants of the `vivienda` nodes whose `field_condominio` is the bulletin's condominium. | Same. |
| `Personalizado` | Users referenced on the bulletin itself. `field_condominio` is ignored. | Propietarios → `field_personalizar`; Ocupantes → `field_ocupantes`; Todos → both. |

Role rules for `General` / `Condominio`: `Propietarios` = uids of
`field_propietario`; `Ocupantes` = union of `field_ocupante` (legacy) and
`field_ocupantes` (multi-value); `Todos` = both. In every scope the result is
filtered to **active** users (`users.status = 1`) and deduplicated (a user who is
both owner and occupant, or in several matching units, gets a single row).

Fail-safe: an unknown scope or role, a `Condominio` bulletin with no condominium,
or a `Personalizado` bulletin with no referenced users all resolve to an empty
recipient set — no rows inserted, no push enqueued, a `watchdog` warning logged.
Never an accidental fan-out to everyone.

## OneSignal configuration

Set as Drupal variables (in `settings.php` via `$conf[...]` or with
`drush vset`), never hardcoded:

| Variable | Meaning |
|---|---|
| `myapi_onesignal_app_id` | OneSignal project App ID. |
| `myapi_onesignal_rest_api_key` | REST API Key (secret). |

If either is missing, the fan-out to the inbox still happens; only the push is
skipped, with a `watchdog(WATCHDOG_WARNING)`. The push `data` payload is
`{ "target": "bulletin", "id": <nid>, "notification_type": "bulletin" }`.
External ids are chunked to OneSignal's 2000-per-request limit. A transport
failure re-queues the batch for the next cron (standard Queue API behaviour),
which may deliver a push twice — the inbox is never duplicated.
