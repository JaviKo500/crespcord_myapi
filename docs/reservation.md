## GET /api/v1/units/{unit_id}/reservations

Returns a paginated list of `reservation` nodes belonging to `unit_id` AND
created by the authenticated user (`field_requester = uid`), provided the
user is the owner or occupant of that unit — same access rule as
`GET /api/v1/units`. A unit shared by multiple owners/occupants never shows
one user another's reservations: each of them only ever sees their own. Both
`confirmed` and `cancelled` reservations are returned; this is "My
Reservations", so a cancelled reservation is still history the resident
wants to see. Read-only: no create/update/delete/cancel, no
single-reservation detail endpoint, no availability/conflict check.

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
| `sort` | `desc` | `asc` or `desc`, applied to `date` (`field_date_value`) and then to `start_time`. Any other value falls back to `desc`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, keeps only reservations with `date >= date_from`. Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, keeps only reservations with `date <= date_to`. Same silent-ignore rule as `date_from`. |
| `time_from` | absent = no time refinement | `HH:MM` 24h. Refines `date_from` **on that day only**: on `date_from` keeps `start_time >= time_from`, later days unaffected. Ignored silently when malformed or when `date_from` is absent/invalid. |
| `time_to` | absent = no time refinement | `HH:MM` 24h. Refines `date_to` **on that day only**: on `date_to` keeps `start_time <= time_to`, earlier days unaffected. Same silent-ignore rule as `time_from`. |
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
        "area_category": "pool",
        "cancel_deadline_minutes": 120,
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
- The result set is always restricted to `field_requester = uid` of the
  authenticated user, in addition to `field_unit = unit_id`: a unit with
  several owners/occupants each only sees the reservations they themselves
  created, never another resident's.
- Only published (`status = 1`) `reservation` nodes are returned.
- Both `confirmed` and `cancelled` reservations are returned; `status` travels
  in each item so the client can distinguish them.
- Every reservation includes exactly 14 keys: `id`, `condominium_id`,
  `unit_id`, `requester_id`, `area_id`, `area_name`, `area_category`,
  `cancel_deadline_minutes`, `date`, `start_time`, `end_time`, `status`,
  `cancelled_by`, `created` (see mapping table below). `condominium_id`,
  `requester_id`, `area_id`, `area_name`, `area_category`,
  `cancel_deadline_minutes`, `date`, `start_time`, `end_time`, `cancelled_by`
  are `null` when the node has no row in that field's storage table — no other
  transformation or business validation is applied.
- `area_name` is the `title` of the `area` node referenced by `field_area`,
  resolved via a join; it is `null` when the reservation has no area row or
  the referenced area node is missing (e.g. deleted).
- `area_category` is the `field_area_category` value of that same referenced
  `area` node (the same field surfaced as `category` in
  `GET /api/v1/condominiums/%/areas`); it is `null` when the reservation has
  no area row or the area has no category set.
- `cancel_deadline_minutes` is the `field_cancel_deadline_minutes` value of the
  referenced `area` node, cast to `int`; `null` when the reservation has no
  area row or the area has no deadline set. It is a **client-side UX hint** so
  the app can decide whether to offer the cancel action (combine it with
  `date`/`start_time` against the current time); it is not authoritative —
  `PUT /api/v1/reservations/%/cancel` re-validates the window server-side.
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **filtered** set (`date_from`/`date_to`/`time_from`/`time_to`/`status` if
  any), not the unit's full reservation count. `total_pages` is `0` when
  `total` is `0`.
- Sorting is by `date` (`field_date_value`) and then by `start_time`
  (`field_start_time_value`), both in the direction given by `sort`; there is
  no other sort field and no way to sort by one without the other.
  Reservations sharing the same `date` therefore come back in clock order —
  with `sort=asc`, `09:00` before `15:00`. Remaining ties (same date and same
  start time) are broken by `id` (`nid`) in the same direction, so the order
  is deterministic and stable across requests and pages (no row can shift
  between pages on repeated calls). `start_time` is stored zero-padded
  (`HH:MM`), so the string ordering is chronological; reservations with no
  `start_time` row group together at one end of their day (`NULL` ordering),
  never interleaved.

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

**Time refinement (`time_from` / `time_to`)**

Each date bound accepts an optional `HH:MM` refinement. A time is **not a
filter of its own**: it narrows its own date bound on the boundary day and
leaves every other day in the range untouched. This is the "from now onwards"
semantics the app needs — asking for today from `09:00` must not also hide
tomorrow's `08:00`.

The resulting comparison is:

```
lower bound:  date > date_from  OR (date = date_from AND start_time >= time_from)
upper bound:  date < date_to    OR (date = date_to   AND start_time <= time_to)
```

- Both ends stay **inclusive**: a reservation starting exactly at `time_from`
  (or exactly at `time_to`) is returned.
- Only `start_time` is compared. A reservation already in progress —
  `08:00→10:00` queried with `time_from=09:00` — is **not** returned; the
  filter answers "which reservations start from this hour", not "which are
  active at this hour".
- On the boundary day, a reservation with no `start_time` row is excluded
  (same rule as a reservation with no `date` row inside a date range). On any
  other day of the range it is still returned.
- `time_from` is dropped when `date_from` is absent or invalid, and `time_to`
  when `date_to` is absent or invalid — there would be no boundary day to
  apply them to. A malformed time (`9:00`, `24:00`, `09:00:00`, `ahora`) is
  ignored per bound, silently, with no `422`; its date bound still applies.
- When the range collapses to a single day (`date_from == date_to`) and the
  times are inverted (`time_from > time_to`), **both times are dropped** and
  the day is kept, mirroring the inverted-date-range rule — the request never
  degrades into a guaranteed-empty result. Across different days inverted
  times are legitimate (`27th from 18:00` → `28th until 09:00`) and are kept.
- Reservations that cross midnight are stored under their own clock day with
  their real `start_time` (SPEC 41), so they are matched by the day they start
  on: a `23:00→01:00` reservation of the 27th is returned by
  `date_from=2026-07-27&time_from=22:00`, not by the 28th's bounds.

Example — everything from today 09:00 onwards, in chronological order:

`GET /api/v1/units/21/reservations?status=confirmed&date_from=2026-07-27&time_from=09:00&sort=asc`

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
| `field_unit_target_id` | `unit_id` | int | never `NULL` (it is a query filter) |
| `field_condominium_target_id` | `condominium_id` | int | `NULL` if no row |
| `field_requester_target_id` | `requester_id` | int | never `NULL` (it is also a query filter, `= uid`) |
| `field_area_target_id` | `area_id` | int | `NULL` if no row |
| `node.title` (of the referenced area) | `area_name` | string | `NULL` when `area_id` is `NULL` or the area node is missing |
| `field_area_category_value` (of the referenced area) | `area_category` | string | `NULL` when `area_id` is `NULL` or the area has no category |
| `field_cancel_deadline_minutes_value` (of the referenced area) | `cancel_deadline_minutes` | int | `NULL` when `area_id` is `NULL` or the area has no deadline |
| `field_date_value` | `date` | string (`Y-m-d`) | `NULL` if no row |
| `field_start_time_value` | `start_time` | string | `NULL` if no row |
| `field_end_time_value` | `end_time` | string | `NULL` if no row |
| `field_reservation_status_value` | `status` | string | `NULL` if no row |
| `field_cancelled_by_value` | `cancelled_by` | string | `NULL` if no row |
| `created` | `created` | string (ISO 8601) | never `NULL` |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `type`, `status`, `created` | `reservation` nodes. |
| `field_data_field_unit` | `entity_id`, `field_unit_target_id` | Reservation → unit relation (`unit_id`). Filter of the endpoint. |
| `field_data_field_condominium` | `entity_id`, `field_condominium_target_id` | `condominium_id`. Left join. |
| `field_data_field_requester` | `entity_id`, `field_requester_target_id` | `requester_id`. Also the mandatory filter restricting the result set to the authenticated user's own reservations (`= uid`). |
| `field_data_field_area` | `entity_id`, `field_area_target_id` | `area_id`. Left join. |
| `node` (aliased) | `nid`, `title` | `area_name`, resolved via a left join on `field_area_target_id`. |
| `field_data_field_area_category` | `entity_id`, `field_area_category_value` | `area_category`, joined on the referenced area's nid (`field_area_target_id`), not on the reservation node. Left join. |
| `field_data_field_cancel_deadline_minutes` | `entity_id`, `field_cancel_deadline_minutes_value` | `cancel_deadline_minutes`, joined on the referenced area's nid (`field_area_target_id`), not on the reservation node. Left join. |
| `field_data_field_date` | `entity_id`, `field_date_value` | `date`. Primary sort column and date-range filter column. Left join. |
| `field_data_field_start_time` | `entity_id`, `field_start_time_value` | `start_time`, text. Second sort column and `time_from`/`time_to` filter column. Left join. |
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

Upcoming reservations only, from today at 09:00 onwards:
```bash
curl -i -X GET 'https://host/api/v1/units/21/reservations?status=confirmed&date_from=2026-07-27&time_from=09:00&sort=asc' \
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
in minutes since midnight); the client never sends it.

**Areas that close after midnight (SPEC 41).** An area whose
`field_close_time` is at or before its `field_open_time` (e.g. open `12:00`,
close `02:00`) stays open into the early hours of the next calendar day. For
these "wrapping" areas:

- **Day normalization.** The client always sends `date = D` (the day it shows
  on its calendar). When the start falls in the early-morning tail
  `[00:00, close)` — e.g. `01:00` in a `12:00–02:00` area — the server stores
  `field_date = D+1` (the start's clock day, the same convention
  `GET /api/v1/areas/{id}/availability` already uses). A start that is **not**
  in that tail keeps `field_date = D`. The request contract does not change:
  the client keeps sending `date = D` in both cases, and the response returns
  the real stored `date`/`start_time`/`end_time`, so a normalized slot comes
  back with `date = D+1`.
- **Stored `end_time` is a wrapped clock time.** A range that crosses midnight
  is persisted as its real time-of-day (e.g. `23:00 + 180min` stores
  `end_time = 02:00`, **not** `26:00`), with `field_date` unchanged. The
  crossing is derived downstream by comparison (`end_time <= start_time`), which
  is what `GET /api/v1/areas/{id}/availability` uses to report `end_date = D+1`.
- **Extended opening-hours window (validation 4).** The range is checked
  against `[open, close + 24h]`: a `20:00` start of 6h ending at `02:00` is
  inside hours; `20:00 + 8h → 04:00` overruns the projected close and fails
  with `reservation_outside_hours`. A start in the dead gap between `close` and
  `open` (e.g. `05:00` in a `12:00–02:00` area) is **not** normalized and also
  fails as out of hours.
- **Concurrent capacity on an absolute axis (validation 6).** See the capacity
  criterion below.

For a **normal** area (`field_close_time` strictly after `field_open_time`)
nothing changes: `field_date` is never normalized, and a range that would
reach or cross midnight (`end >= 24:00`) is rejected with
`422 reservation_crosses_midnight`, a distinct error from a plain
out-of-hours request so the client can message it usefully.

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
| 3 | `date` + `start_time` is not in the past (site timezone; evaluated on the normalized `field_date`) | `422 invalid_field` (`@field = date`) |
| 4 | Range does not cross midnight in a same-day area, and is within the area's opening hours (extended `[open, close+24h]` window for areas that close after midnight) | `422 reservation_crosses_midnight` / `422 reservation_outside_hours` |
| 5 | `duration_minutes` does not exceed the area's maximum | `422 reservation_duration_exceeded` |
| 6 | The area's concurrent capacity is not exceeded: the peak of simultaneous `confirmed` reservations inside the requested window, plus this one, fits in `max_concurrent_reservations` (absolute axis across `D−1`/`D`/`D+1`) | `409 reservation_overlap` (capacity 1) / `409 area_capacity_full` (capacity > 1) |
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

**Concurrent capacity criterion (validation 6)**

An area declares in `field_max_concurrent_reservations` how many reservations
may **coincide** in the same time slot — a gym that fits three groups at once
has capacity `3`. The value is read-only through the API and exposed as
`max_concurrent_reservations` in the area item (see [area.md](area.md)).

The **effective** capacity is fail-closed: no row, `NULL`, `0` or a negative
value all mean `1`. So an area whose admin never filled the field behaves
exactly as before this field existed — one reservation per slot. `NULL` never
means "unlimited".

Each reservation — the candidate and every existing `confirmed` one — is
projected onto an absolute minute axis anchored at the midnight of its own
`field_date`, so same-day, midnight-crossing and early-morning reservations
compare uniformly. Existing reservations are fetched for three days
(`field_date IN (D−1, D, D+1)`) so a previous day's tail that runs past
midnight, or a next-day early-morning booking, is compared too.

Half-open interval: `new_start_abs < existing_end_abs AND
new_end_abs > existing_start_abs`. A reservation that ends exactly when
another begins is **not** simultaneous (back-to-back bookings are allowed).

What is compared against the capacity is the **peak** of simultaneous existing
reservations inside the candidate's window — the answer to "at some instant,
would there be more than N?" — plus the candidate itself. The request is
rejected when `peak + 1 > capacity`.

Counting how many reservations *overlap* the candidate would be a different,
wrong question. With capacity `2` and `10:00-11:00` and `13:00-14:00` already
booked, a `10:00-14:00` candidate overlaps **two** of them, yet those two are
never active at the same time: the peak is `1`, there is room, and the request
is accepted.

Which `error_code` comes back depends on the capacity:

| Effective capacity | Rejection |
|---|---|
| `1` | `409 reservation_overlap` — the exact error, code and text, that this endpoint has always returned. Nothing changed for these areas. |
| `> 1` | `409 area_capacity_full` — the slot has no seats left. A capacity-1 area **never** returns this code. |

The capacity is checked **inside** the same transaction and after the same
`SELECT ... FOR UPDATE` row lock on the area node as before: without that lock
two concurrent requests would both read "there is still room" and both insert,
making the capacity trivially exceedable.

The capacity is a property of the **area**, not of the unit. Validation 7 is
unchanged and orthogonal: a unit that already has an active reservation for the
area still gets `409 reservation_already_active`, even when seats are free.
The capacity counts **reservations**, not people — there is no attendee count.

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
      "area_category": "pool",
      "cancel_deadline_minutes": 120,
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
| 422  | `invalid_field` | Any of the five fields fails its format/type/range check, or the requested date/time is in the past. |
| 403  | `unit_access_denied` | `unit_id` does not exist, or is not owned/occupied by the authenticated user. |
| 404  | `area_not_found` | `area_id` does not exist, or belongs to a different condominium than `unit_id`. Both cases return the same error. |
| 403  | `reservation_role_not_allowed` | The area is `owner`-only and the user only occupies the unit, or `tenant`-only and the user only owns it. |
| 409  | `area_not_active` | The area's status is `maintenance`, `closed`, or anything other than `active`. |
| 422  | `reservation_outside_hours` | The requested range falls outside the area's `open_time`–`close_time` window (the extended `[open, close+24h]` window for areas that close after midnight). |
| 422  | `reservation_crosses_midnight` | The requested range would cross midnight (`end >= 24:00`) but the area closes the same clock day (its `close_time` is after `open_time`). |
| 422  | `reservation_duration_exceeded` | `duration_minutes` exceeds the area's `max_minutes`. |
| 409  | `reservation_overlap` | **Capacity-1 areas only** (no `max_concurrent_reservations` set, or set to `1`/`0`/a negative). The requested range overlaps another `confirmed` reservation of the same area, compared on an absolute axis across the previous/current/next day. |
| 409  | `area_capacity_full` | **Capacity > 1 only.** At some instant inside the requested range the area would exceed `max_concurrent_reservations` simultaneous reservations. A capacity-1 area never returns this code. |
| 409  | `reservation_already_active` | The unit already has a `confirmed` reservation for the same area whose start has not passed. Returned even when the area still has free seats — validation 7 limits the **unit**, validation 6 the **area**. |
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

**Overnight-area acceptance matrix (SPEC 41)**

Assumes `area_id=42` is a wrapping area open `12:00`–`02:00`, and `area_id=50`
a normal area open `08:00`–`22:00`. `D = 2026-07-25`. The client always sends
`date = D`; the stored `field_date` is what the server normalizes to.

| Case | `area_id` | `date` | `start_time` | `duration_minutes` | Result | Stored `field_date` |
|---|---|---|---|---|---|---|
| Early-morning slot normalized | 42 | `2026-07-25` | `01:00` | 60 | `201` | `2026-07-26` (D+1) |
| Evening slot crossing midnight | 42 | `2026-07-25` | `20:00` | 360 (→`02:00`) | `201` | `2026-07-25` (D) |
| Evening slot overruns projected close | 42 | `2026-07-25` | `20:00` | 480 (→`04:00`) | `422 reservation_outside_hours` | — |
| Dead gap (not normalized) | 42 | `2026-07-25` | `05:00` | 60 | `422 reservation_outside_hours` | — |
| Cross midnight in a same-day area | 50 | `2026-07-25` | `21:00` | 240 (→`01:00`) | `422 reservation_crosses_midnight` | — |
| Overlap: morning slot vs previous evening's tail | 42 | `2026-07-25` | `01:00` | 60 | `409 reservation_overlap` (given an existing `20:00→02:00` on D−1) | — |
| Normal area, no change | 50 | `2026-07-25` | `10:00` | 120 | `201` | `2026-07-25` (D) |
| Normal area, out of hours | 50 | `2026-07-25` | `07:00` | 60 | `422 reservation_outside_hours` | — |

```bash
# Early-morning slot in a wrapping area → stored on D+1
curl -i -X POST 'https://host/api/v1/reservations' \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"unit_id":21,"area_id":42,"date":"2026-07-25","start_time":"01:00","duration_minutes":60}'

# Same-day area, range crossing midnight → reservation_crosses_midnight
curl -i -X POST 'https://host/api/v1/reservations' \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"unit_id":21,"area_id":50,"date":"2026-07-25","start_time":"21:00","duration_minutes":240}'
```

**Concurrent-capacity acceptance matrix (SPEC 45)**

Assumes `area_id=50` is a normal area open `08:00`–`22:00` with **no**
`max_concurrent_reservations` set (effective capacity `1`), and `area_id=77` the
same hours with capacity `3`. `D = 2026-08-01`.

| Case | `area_id` | Existing `confirmed` reservations overlapping | Request | Result |
|---|---|---|---|---|
| No-regression: capacity 1, overlap | 50 | one `10:00-11:00` | `10:30`, 60 min | `409 reservation_overlap` — same code and text as before SPEC 45 |
| No-regression: capacity 1, back-to-back | 50 | one `10:00-11:00` | `11:00`, 60 min | `201` |
| Capacity 1 never returns the new code | 50 | any | any | never `area_capacity_full` |
| Seats left | 77 | two overlapping `10:00-11:00` | `10:00`, 60 min | `201` |
| Full | 77 | three overlapping `10:00-11:00` | `10:00`, 60 min | `409 area_capacity_full` |
| The gym case | 77 | one from another unit, `10:00-11:00` | `10:00`, 60 min from a **different** unit | `201` — capacity ignores which unit each reservation comes from |
| Peak vs naive count | 77 (as capacity 2) | `10:00-11:00` and `13:00-14:00` | `10:00`, 240 min | `201` — overlaps two, but they never coincide |
| Validation 7 still applies | 77 | one from the **same** unit, still active | any | `409 reservation_already_active`, even with free seats |

```bash
# Capacity 3 with two overlapping reservations → 201
curl -i -X POST 'https://host/api/v1/reservations' \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"unit_id":21,"area_id":77,"date":"2026-08-01","start_time":"10:00","duration_minutes":60}'

# Same area once full → 409 area_capacity_full, translated per Accept-Language
curl -i -X POST 'https://host/api/v1/reservations' \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Accept-Language: en' \
  -H 'Content-Type: application/json' \
  -d '{"unit_id":22,"area_id":77,"date":"2026-08-01","start_time":"10:00","duration_minutes":60}'
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
      "area_category": "pool",
      "cancel_deadline_minutes": 120,
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

---

## GET /api/v1/reservations/{id}/details

Returns a single reservation by id, in the same item shape as
`GET /api/v1/units/{unit_id}/reservations`, wrapped as `{"reservation": ...}`.
Read-only. Applies the **same access rules as the list**: the reservation is
visible only when it would also appear in the caller's own list — it must be a
published `reservation` node, belong to a unit the caller owns or occupies, and
have `field_requester = uid` (the authenticated user's own reservation). Both
`confirmed` and `cancelled` reservations are returned; `status` travels in the
payload.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Request body**

None. The reservation id travels in the path; any body sent is ignored.

**Validation order**

Each validation short-circuits before the next one runs:

| # | Validation | Error |
|---|---|---|
| 1 | Bearer token present and valid | `401 missing_authorization` / `401 invalid_token` |
| 2 | `{id}` is a positive integer referencing a published (`status = 1`) `reservation` node | `404 reservation_not_found` |
| 3 | Authenticated user is the reservation's `field_requester` | `404 reservation_not_found` |
| 4 | Authenticated user owns or occupies the reservation's `field_unit` | `404 reservation_not_found` |

Validations 2–4 all collapse into the **same** `404 reservation_not_found`: a
bad/nonexistent id, an unpublished node, another resident's reservation, and a
reservation on a unit the caller is not related to are indistinguishable. This
mirrors the list's non-revealing access rule (where "no access" and "does not
exist" both return the same error), so the endpoint never reveals whether a
reservation id exists or whom it belongs to. This differs on purpose from
`PUT .../cancel`, which distinguishes `403 reservation_forbidden` from
`404 reservation_not_found`.

**Success response (200)**

Same shape as an item from `GET /api/v1/units/{unit_id}/reservations` — the same
14 keys, with the identical `NULL` rules (see the mapping table under that
endpoint). `area_name`, `area_category` and `cancel_deadline_minutes` are read
live from the referenced `area` node and are `null` when the area is missing
(deleted).

```json
{
  "success": true,
  "data": {
    "reservation": {
      "id": 88,
      "condominium_id": 7,
      "unit_id": 21,
      "requester_id": 34,
      "area_id": 42,
      "area_name": "Piscina principal",
      "area_category": "pool",
      "cancel_deadline_minutes": 120,
      "date": "2026-07-25",
      "start_time": "10:00",
      "end_time": "12:00",
      "status": "confirmed",
      "cancelled_by": null,
      "created": "2026-07-22T14:30:00"
    }
  }
}
```

Notes:
- No `message` is returned (this is a plain read, like the list).
- Unlike the list, there are no `page`/`limit`/`sort`/`date_from`/`date_to`/
  `status` query params — a single item takes none.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or malformed. |
| 401  | `invalid_token` | Access token not found, revoked, expired, or the user no longer exists/is blocked. |
| 404  | `reservation_not_found` | `{id}` is not a positive integer, does not reference a published `reservation` node, is another resident's reservation, or is on a unit the caller does not own/occupy. All indistinguishable. |
| 405  | `method_not_allowed` | Any HTTP method other than `GET`. |

**Example:**
```bash
curl -i 'https://host/api/v1/reservations/88/details' \
  -H 'Authorization: Bearer <access_token>'
```
