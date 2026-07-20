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
block breaks → newlines, `strip_tags`, entity decode) before it is stored. Each
line is then trimmed, internal space runs are squeezed to one, and blank lines
are dropped, so WYSIWYG indentation and empty paragraphs do not leak through as
extra whitespace — the banner reads as tight, single-spaced lines. The
same value feeds both the inbox `body` and the push `contents`, and a push
banner cannot render HTML. Rich formatting (a separate sanitized `body_html`) is
left for a future spec.

**Push body is capped at 150 characters.** The inbox row keeps the full text,
but the push `contents` is truncated to `MYAPI_ONESIGNAL_MAX_BODY_LENGTH` (150)
in the transport layer (`myapi_onesignal_truncate_body()`): a longer body is cut
and `...` is appended so the banner stays compact and the total, ellipsis
included, never exceeds 150. Truncation is multibyte-safe, so accented text is
never split mid-character.

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
| `condominium` | absent = full inbox | Positive-integer condominium nid to scope the inbox to. Any non-positive-integer value falls back to "no scope" silently (no `422`). See the scope rules below. |
| `unit` | absent | Positive-integer unit (vivienda) nid. Only honoured when `condominium` is present and valid; ignored otherwise. |

**Condominium/unit scope**

When `condominium` is present, the inbox is filtered to what the user should see
from that condominium/unit context, following these rules:

- A notification with **no condominium** (`condominium IS NULL`, e.g. a `General`
  or `Personalizado` bulletin fan-out) is **always visible**, regardless of the
  requested scope.
- Otherwise the notification must belong to the requested `condominium`, and
  either have **no unit** (`unit IS NULL`, condominium-wide) **or** match the
  requested `unit`.
- When `unit` is absent, the unit constraint is dropped: every notification of
  the requested condominium (plus the always-visible no-condominium ones) is
  returned.

`GET /api/v1/notifications?condominium=333&unit=123` therefore returns: all
no-condominium notifications, plus condominium `333`'s notifications that are
either condominium-wide or for unit `123`. Omitting both parameters returns the
full inbox unchanged (back-compatible).

`unread_count` and `pagination.total` both reflect the same scope, so the badge
matches the filtered list the user is looking at.

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
        "deep_link": { "target": "bulletin", "id": 812, "unit": null, "condominium": null },
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

- `unread_count` is the user's unread count for the **current scope**
  (`condominium`/`unit`), independent of the `unread` filter and of pagination —
  it is meant for the app badge. With no scope requested it is the total unread.
- `deep_link.target` + `deep_link.id` are the same pair the push carries in its
  `data` payload, so opening from the list or from the push lands on the same
  screen.
- `deep_link.unit` / `deep_link.condominium` tie a notification to a specific
  unit and condominium (used by triggers such as approved payment or new
  alicuota). For `bulletin` notifications `unit` is always `NULL`, and
  `condominium` is set only for `Condominio`-scope bulletins (every recipient
  belongs to that one condominium); it stays `NULL` for `General` and
  `Personalizado` bulletins, which span several or unrelated condominiums.

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
    "deep_link": { "target": "bulletin", "id": 812, "unit": null, "condominium": null },
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

## Fee-issued trigger (recibo / alicuota_extra)

When a `recibo` or `alicuota_extra` node **transitions to `field_estado =
"Enviado"` while being edited** (`hook_node_update`), the occupants of its unit
are notified. Only the transition on edit fires: a node created directly in
`"Enviado"` does not, and re-saving a node that was already `"Enviado"` does not
re-notify (the trigger compares the previous stored status against the incoming
one — see `myapi_fee_is_sent_transition()`).

**Recipient:** the occupant(s) of the unit referenced by `field_vivienda`,
resolved via `myapi_unit_member_uids([$unit_id], 'ocupantes')` — never the
owners nor the node author. If the unit has no resoluble occupant, or the node
has no `field_vivienda`, the recipient set is empty and nothing is created
(no-op, no error).

| `$node->type` | `source_type` | `type` | `title` | `body` |
|---|---|---|---|---|
| `recibo` | `receipt` | `receipt_sent` | `Nueva alícuota generada` | `Nueva alícuota registrada para {unit}\nValor total: {total}` |
| `alicuota_extra` | `extra_fee` | `extra_fee_sent` | `Nueva alícuota extra generada` | `Nueva alícuota extra registrada para {unit}\nValor total: {value}` |

Where `{unit}` is the referenced unit node's `title` (empty string if it cannot
be resolved), and `{total}`/`{value}` is `field_total_mes` (recibo) or
`field_valor_extra` (alicuota_extra) formatted with `number_format(…, 2)` — a
missing value yields `"0.00"`. Text is fixed Spanish, not translated via
`myapi_t()` (no `Accept-Language` inside a `node_save` hook).

**Deep link:** `deep_link.target` = the `source_type` (`receipt` / `extra_fee`)
and `deep_link.id` = the fee node's nid (same shape as the payment trigger).
The context columns are also populated: `deep_link.unit` = the unit nid
(`field_vivienda`) and `deep_link.condominium` = the condominium nid
(`field_condominio` of the unit, or `NULL` if it cannot be resolved).

**Idempotency:** the notification is emitted at most once per node per request.
A fee node can be re-saved within the same request (e.g. a Rule recalculating
balances reuses the same `$node` object, so `$node->original` keeps its stale
pre-transition status); a `drupal_static` guard in `myapi_fee_notify_issued()`
prevents the duplicate row.

## OneSignal configuration

Set as Drupal variables (in `settings.php` via `$conf[...]` or with
`drush vset`), never hardcoded:

| Variable | Meaning |
|---|---|
| `myapi_onesignal_app_id` | OneSignal project App ID. |
| `myapi_onesignal_rest_api_key` | REST API Key (secret). |

If either is missing, the fan-out to the inbox still happens; only the push is
skipped, with a `watchdog(WATCHDOG_WARNING)`. The push `data` payload is
`{ "target": "bulletin", "id": <nid>, "unit": null, "condominium": <nid|null>, "notification_type": "bulletin" }`.
`unit`/`condominium` mirror `deep_link.unit`/`deep_link.condominium`: `unit` is
always `NULL` for `bulletin` notifications and `condominium` carries the
condominium nid only for `Condominio`-scope bulletins (`NULL` otherwise).
External ids are chunked to OneSignal's 2000-per-request limit. A transport
failure re-queues the batch for the next cron (standard Queue API behaviour),
which may deliver a push twice — the inbox is never duplicated.
