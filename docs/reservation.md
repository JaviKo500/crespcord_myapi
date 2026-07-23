## GET /api/v1/units/{unit_id}/reservations

Returns a paginated list of `reservation` nodes belonging to `unit_id`,
visible only to the authenticated user who is the owner or occupant of that
unit — same access rule as `GET /api/v1/units`. Both `confirmed` and
`cancelled` reservations are returned; this is "My Reservations", so a
cancelled reservation is still history the resident wants to see. Read-only:
no create/update/delete/cancel, no single-reservation detail endpoint, no
availability/conflict check.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. Special value `-1` disables pagination entirely: every matching reservation is returned in one response, `page` is forced to `1`, and `total_pages` is `1` (or `0` when `total` is `0`). |
| `sort` | `desc` | `asc` or `desc`, applied to `date` (`field_date_value`). Any other value falls back to `desc`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, keeps only reservations with `date >= date_from`. Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, keeps only reservations with `date <= date_to`. Same silent-ignore rule as `date_from`. |
| `status` | absent = both statuses | `confirmed` or `cancelled`. Any other value is ignored silently (no `422`), as if absent — both statuses are returned. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "reservations": [
      {
        "id": 88,
        "condominium_id": 7,
        "unit_id": 21,
        "requester_id": 34,
        "area_id": 42,
        "area_name": "Piscina principal",
        "date": "2026-07-25",
        "start_time": "10:00",
        "end_time": "12:00",
        "status": "confirmed",
        "cancelled_by": null,
        "created": "2026-07-22T14:30:00"
      }
    ],
    "pagination": {
      "total": 1,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

A unit with no reservations gets `{"reservations": [], "pagination": {"total":
0, "page": 1, "limit": 20, "total_pages": 0}}` with `200` (not an error).
Requesting a page beyond the last one also returns `200` with `reservations:
[]` (not an error).

Notes:
- Access rule: the authenticated user must be the owner or occupant of
  `unit_id`, using the same `myapi_unit_related_nids()` lookup as
  `GET /api/v1/units` (owner via `field_propietario`, occupant via
  `field_ocupante` legacy single-value or `field_ocupantes` current
  multi-value, evaluated as OR).
- `unit_id` that does not belong to the authenticated user and `unit_id` that
  does not exist at all return the exact same `403 unit_access_denied` — the
  response never reveals whether a unit exists.
- Only published (`status = 1`) `reservation` nodes are returned.
- Both `confirmed` and `cancelled` reservations are returned; `status` travels
  in each item so the client can distinguish them.
- Every reservation includes exactly 12 keys: `id`, `condominium_id`,
  `unit_id`, `requester_id`, `area_id`, `area_name`, `date`, `start_time`,
  `end_time`, `status`, `cancelled_by`, `created` (see mapping table below).
  `condominium_id`, `requester_id`, `area_id`, `area_name`, `date`,
  `start_time`, `end_time`, `cancelled_by` are `null` when the node has no row
  in that field's storage table — no other transformation or business
  validation is applied.
- `area_name` is the `title` of the `area` node referenced by `field_area`,
  resolved via a join; it is `null` when the reservation has no area row or
  the referenced area node is missing (e.g. deleted).
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **filtered** set (`date_from`/`date_to`/`status` if any), not the unit's
  full reservation count. `total_pages` is `0` when `total` is `0`.
- Sorting is always by `date` (`field_date_value`); there is no other sort
  field. Reservations sharing the same `date` are broken by `id` (`nid`) in
  the same direction as `sort`, so the order is deterministic and stable
  across requests and pages (no row can shift between pages on repeated
  calls).

**Date-range filter (`date_from` / `date_to`)**

Both bounds are optional and independent: you may send only `date_from`, only
`date_to`, both, or neither. They filter on `date` inclusively on both ends.

- Comparison is made on the first 10 characters of `field_date_value`
  (`SUBSTR(..., 1, 10)`), so a reservation stored as either `2026-07-25` or
  `2026-07-25T00:00:00` is **included** by `date_to=2026-07-25` — the time
  suffix never pushes the last day out of range.
- The filter is applied **before** pagination and sorting, so `page`, `limit`
  and `sort` operate over the already-filtered set.
- Reservations with no `field_date` row (`date = null`) are **excluded**
  whenever at least one bound is active — a reservation without a date cannot
  belong to a date range.
- Invalid values (bad format or non-calendar dates) are ignored per bound, and
  an inverted range (`date_from > date_to`) drops the whole filter, so the
  endpoint responds exactly as if no range had been sent. No `422` is raised
  for either case — this mirrors the lax handling of `page`/`limit`/`sort`.

Example: `GET /api/v1/units/21/reservations?date_from=2026-07-01&date_to=2026-07-31`
returns only reservations whose `date` falls within July 2026 inclusive.

**Status filter (`status`)**

- `status=confirmed` returns only reservations whose `field_reservation_status`
  is `confirmed`; `status=cancelled` returns only `cancelled` ones.
- Any other value (including an empty string, a typo, or an unsupported
  status) is ignored silently — the endpoint responds as if `status` were
  absent, returning both `confirmed` and `cancelled` reservations. No `422` is
  raised.

Example: `GET /api/v1/units/21/reservations?status=cancelled` returns only the
unit's cancelled reservations.

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables instead
of going through the Field API, for query simplicity. A future schema change
to any of the fields below (rename, single→multi-value, bundle move, type
change) will silently break this endpoint without a Drupal update warning.
`field_condominium` is shared with the `area` content type; the `n.type =
'reservation'` condition and the per-join `entity_id` binding keep the query
scoped to reservation nodes only. See `docs/reservations-install.md` for the
full schema definition.

| Drupal field | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `nid` | `id` | int | never `NULL` |
| `field_unit_target_id` | `unit_id` | int | never `NULL` (it is the query filter) |
| `field_condominium_target_id` | `condominium_id` | int | `NULL` if no row |
| `field_requester_target_id` | `requester_id` | int | `NULL` if no row |
| `field_area_target_id` | `area_id` | int | `NULL` if no row |
| `node.title` (of the referenced area) | `area_name` | string | `NULL` when `area_id` is `NULL` or the area node is missing |
| `field_date_value` | `date` | string (`Y-m-d`) | `NULL` if no row |
| `field_start_time_value` | `start_time` | string | `NULL` if no row |
| `field_end_time_value` | `end_time` | string | `NULL` if no row |
| `field_reservation_status_value` | `status` | string | `NULL` if no row |
| `field_cancelled_by_value` | `cancelled_by` | string | `NULL` if no row |
| `created` | `created` | string (ISO 8601) | never `NULL` |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `type`, `status`, `created` | `reservation` nodes. |
| `field_data_field_unit` | `entity_id`, `field_unit_target_id` | Reservation → unit relation (`unit_id`). Main filter of the endpoint. |
| `field_data_field_condominium` | `entity_id`, `field_condominium_target_id` | `condominium_id`. Left join. |
| `field_data_field_requester` | `entity_id`, `field_requester_target_id` | `requester_id`. Left join. |
| `field_data_field_area` | `entity_id`, `field_area_target_id` | `area_id`. Left join. |
| `node` (aliased) | `nid`, `title` | `area_name`, resolved via a left join on `field_area_target_id`. |
| `field_data_field_date` | `entity_id`, `field_date_value` | `date`. Default sort column and date-range filter column. Left join. |
| `field_data_field_start_time` | `entity_id`, `field_start_time_value` | `start_time`, text. Left join. |
| `field_data_field_end_time` | `entity_id`, `field_end_time_value` | `end_time`, text. Left join. |
| `field_data_field_reservation_status` | `entity_id`, `field_reservation_status_value` | `status`. Left join; also the `status` filter column. |
| `field_data_field_cancelled_by` | `entity_id`, `field_cancelled_by_value` | `cancelled_by`, text. Left join. |

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 403  | `unit_access_denied` | `unit_id` is not owned/occupied by the authenticated user, or does not exist. Both cases return the same error — the response never distinguishes them. |
| 405  | `method_not_allowed` | Any HTTP method other than GET. |

Error envelope:
```json
{
  "success": false,
  "error_code": "unit_access_denied",
  "error": "No tienes acceso a esta unidad."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Example:**
```bash
curl -i -X GET 'https://host/api/v1/units/21/reservations?status=confirmed&sort=asc' \
  -H 'Authorization: Bearer <access_token>'
```

---

## POST /api/v1/reservations

Creates a `reservation` node for a unit's common area, on behalf of the
authenticated user. Applies eight business validations, in a fixed order,
before writing anything — each one aborts the request with its own error and
leaves no node created. Does not include cancellation (see the separate
cancel endpoint) or a single-reservation detail endpoint.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |
| Content-Type | application/json |

**Request body**
```json
{
  "unit_id": 21,
  "area_id": 42,
  "date": "2026-07-25",
  "start_time": "10:00",
  "duration_minutes": 120
}
```

| Field | Type | Required | Validation |
|---|---|---|---|
| `unit_id` | int | Yes | Positive integer. Missing → `422 missing_field`; non-numeric → `422 invalid_field` (`@field = unit_id`). |
| `area_id` | int | Yes | Positive integer. Same rule as `unit_id` (`@field = area_id`). |
| `date` | string | Yes | `YYYY-MM-DD`, validated with `checkdate()`. Invalid → `422 invalid_field` (`@field = date`). |
| `start_time` | string | Yes | `HH:MM` 24h, `^([01]\d|2[0-3]):([0-5]\d)$`. Invalid → `422 invalid_field` (`@field = start_time`). |
| `duration_minutes` | int | Yes | Positive integer (`> 0`). Invalid → `422 invalid_field` (`@field = duration_minutes`). |

`end_time` is always computed server-side (`start_time + duration_minutes`,
in minutes since midnight); the client never sends it. If the computed
`end_time` reaches or crosses midnight (`>= 24:00`), it is not specially
handled — it naturally fails the opening-hours validation below, since no
area's `field_close_time` is ever past midnight.

**Validation order**

Each validation short-circuits the request before the next one runs and
before the node is ever touched:

| # | Validation | Error |
|---|---|---|
| 0a | Bearer token present and valid | `401 missing_authorization` / `401 invalid_token` |
| 0b | Body well-formed (table above) | `422 missing_field` / `422 invalid_field` |
| 0c | Authenticated user owns or occupies `unit_id` | `403 unit_access_denied` |
| 0d | `area_id` exists and belongs to `unit_id`'s condominium | `404 area_not_found` |
| 1 | Role vs the area's `who_can_reserve` (`owner`/`tenant` must match; any other value allows both) | `403 reservation_role_not_allowed` |
| 2 | Area's status is exactly `active` | `409 area_not_active` |
| 3 | `date` + `start_time` is not in the past (site timezone) | `422 invalid_field` (`@field = date`) |
| 4 | Requested range is within the area's opening hours | `422 reservation_outside_hours` |
| 5 | `duration_minutes` does not exceed the area's maximum | `422 reservation_duration_exceeded` |
| 6 | No overlap with another `confirmed` reservation of the same area/date | `409 reservation_overlap` |
| 7 | Unit has no other `confirmed` reservation for the same area whose start has not passed | `409 reservation_already_active` |
| 8 | Unit's balance allows reserving (see below) | `403 insufficient_balance` |

**Balance check (validation 8)**

1. If the unit's current balance (`field_saldo_actual`) is `<= 0` (or the unit
   has no balance row), the reservation is allowed without inspecting any
   receipt.
2. Otherwise, the most recently issued (`Enviado`) receipt for the unit
   (ordered by `field_periodo` descending, same "most recent" criterion as
   `GET /api/v1/units/{unit_id}/receipts`) decides: a positive
   `field_saldo_anterior` blocks the reservation (`403 insufficient_balance`);
   anything else — `<= 0`, a missing row, or no `Enviado` receipt at all —
   allows it.

**Overlap criterion (validation 6)**

Half-open interval: `new_start < existing_end AND new_end > existing_start`.
A reservation that ends exactly when another begins is **not** an overlap
(back-to-back bookings are allowed).

**Success response (201)**

Same shape as an item from `GET /api/v1/units/{unit_id}/reservations`.

```json
{
  "success": true,
  "data": {
    "reservation": {
      "id": 91,
      "condominium_id": 7,
      "unit_id": 21,
      "requester_id": 34,
      "area_id": 42,
      "area_name": "Piscina principal",
      "date": "2026-07-25",
      "start_time": "10:00",
      "end_time": "12:00",
      "status": "confirmed",
      "cancelled_by": null,
      "created": "2026-07-22T14:30:00"
    }
  },
  "message": "Reserva creada correctamente."
}
```

Notes:
- `field_reservation_status` is always written as `confirmed`; there is no way
  to create a reservation in any other status through this endpoint.
- `field_condominium` on the created node is always derived from the **unit**
  (`unit_id`'s `field_condominio`), never from the area or the request body —
  even though both are guaranteed to match by the time validation 0d passes.
  This distinguishes `field_condominio` (on `vivienda`/unit nodes) from
  `field_condominium` (on `area` and `reservation` nodes) — a legacy naming
  difference that predates this endpoint.
- `field_cancelled_by` is left unset on creation.
- The created reservation is immediately visible through
  `GET /api/v1/units/{unit_id}/reservations`.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or malformed. |
| 401  | `invalid_token` | Access token not found, revoked, expired, or the user no longer exists/is blocked. |
| 422  | `missing_field` | `unit_id`, `area_id`, `date`, `start_time` or `duration_minutes` is missing. |
| 422  | `invalid_field` | Any of the five fields fails its format/type/range check, the requested date/time is in the past, or (see above) `end_time` crosses midnight. |
| 403  | `unit_access_denied` | `unit_id` does not exist, or is not owned/occupied by the authenticated user. |
| 404  | `area_not_found` | `area_id` does not exist, or belongs to a different condominium than `unit_id`. Both cases return the same error. |
| 403  | `reservation_role_not_allowed` | The area is `owner`-only and the user only occupies the unit, or `tenant`-only and the user only owns it. |
| 409  | `area_not_active` | The area's status is `maintenance`, `closed`, or anything other than `active`. |
| 422  | `reservation_outside_hours` | The requested range falls outside the area's `open_time`–`close_time` window. |
| 422  | `reservation_duration_exceeded` | `duration_minutes` exceeds the area's `max_minutes`. |
| 409  | `reservation_overlap` | The requested range overlaps another `confirmed` reservation of the same area/date. |
| 409  | `reservation_already_active` | The unit already has a `confirmed` reservation for the same area whose start has not passed. |
| 403  | `insufficient_balance` | The unit's balance is positive and its most recent sent receipt shows a positive previous balance. |
| 405  | `method_not_allowed` | Any HTTP method other than `POST`. |

Error envelope:
```json
{
  "success": false,
  "error_code": "reservation_overlap",
  "error": "Este horario se cruza con una reserva existente."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Example:**
```bash
curl -i -X POST 'https://host/api/v1/reservations' \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"unit_id":21,"area_id":42,"date":"2026-07-25","start_time":"10:00","duration_minutes":120}'
```

---

## PUT /api/v1/reservations/{id}/cancel

Cancels a `confirmed` reservation on behalf of the authenticated user.
Soft-cancel only: `field_reservation_status` is rewritten to `cancelled` and
`field_cancelled_by` to `user`, every other field on the node is left
untouched (no `node_delete()`). Only the reservation's own `field_requester`
may cancel it — unlike `payment.resource.inc`, no other owner/occupant of the
unit is allowed. No reactivation endpoint exists.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Request body**

None. The reservation id travels in the path; any body sent is ignored.

**Validation order**

Each validation short-circuits the request before the next one runs and
before the node is ever touched:

| # | Validation | Error |
|---|---|---|
| 1 | Bearer token present and valid | `401 missing_authorization` / `401 invalid_token` |
| 2 | `{id}` is a positive integer, the node exists and is of type `reservation` | `404 reservation_not_found` |
| 3 | Authenticated user is exactly the reservation's `field_requester` | `403 reservation_forbidden` |
| 4 | `field_reservation_status` is exactly `confirmed` | `409 reservation_not_confirmed` |
| 5 | Cancellation window has not closed (see below) | `409 reservation_cancel_window_expired` |

**Cancellation window (validation 5)**

`minutes_until_start = floor((timestamp(date, start_time) - now) / 60)`
(site timezone). Cancellation is allowed only when `minutes_until_start` is
strictly greater than the reservation's area's `field_cancel_deadline_minutes`.
A reservation whose start has already passed always fails this check (no
separate error code for "already started"). If the referenced area node is
missing (deleted) or has no `field_cancel_deadline_minutes` row, the window is
treated as already expired, since it cannot be confirmed. The deadline is read
live from the area at cancellation time, not frozen at reservation creation —
if an admin changes it later, it retroactively applies to existing
reservations.

**Success response (200)**

Same shape as an item from `GET /api/v1/units/{unit_id}/reservations`.

```json
{
  "success": true,
  "data": {
    "reservation": {
      "id": 91,
      "condominium_id": 7,
      "unit_id": 21,
      "requester_id": 34,
      "area_id": 42,
      "area_name": "Piscina principal",
      "date": "2026-07-25",
      "start_time": "10:00",
      "end_time": "12:00",
      "status": "cancelled",
      "cancelled_by": "user",
      "created": "2026-07-22T14:30:00"
    }
  },
  "message": "Reserva cancelada correctamente."
}
```

Notes:
- Cancelling an already-`cancelled` reservation fails with
  `409 reservation_not_confirmed` (idempotency: the second call always fails).
- The cancelled reservation remains visible through
  `GET /api/v1/units/{unit_id}/reservations` with `status: "cancelled"`.
- Not in scope: cancellation by an administrator or by another
  owner/occupant of the unit, reactivation of a cancelled reservation, a
  cancellation `reason`, and cancellation notifications.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or malformed. |
| 401  | `invalid_token` | Access token not found, revoked, expired, or the user no longer exists/is blocked. |
| 404  | `reservation_not_found` | `{id}` is not a positive integer, or does not reference an existing `reservation` node. |
| 403  | `reservation_forbidden` | The authenticated user is not the reservation's `field_requester`. |
| 409  | `reservation_not_confirmed` | `field_reservation_status` is not `confirmed` (e.g. already `cancelled`). |
| 409  | `reservation_cancel_window_expired` | Fewer minutes than (or exactly) the area's `field_cancel_deadline_minutes` remain until the start, the reservation already started/passed, or the area is missing/has no deadline row. |
| 405  | `method_not_allowed` | Any HTTP method other than `PUT`. |

Error envelope:
```json
{
  "success": false,
  "error_code": "reservation_cancel_window_expired",
  "error": "La ventana de cancelación de esta reserva ya expiró."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Example:**
```bash
curl -i -X PUT 'https://host/api/v1/reservations/91/cancel' \
  -H 'Authorization: Bearer <access_token>'
```
