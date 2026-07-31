## GET /api/v1/condominiums/{condominium_id}/areas

Returns a paginated list of `area` nodes belonging to `condominium_id`, visible
only to an authenticated user who is related to that condominium — same access
rule as the rest of the `condominiums/{id}/...` endpoints. Read-only: no
create/update/delete. For a single area by id, see
[`GET /api/v1/areas/{id}`](#get-apiv1areasid) below.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. Special value `-1` disables pagination entirely: every matching area is returned in one response, `page` is forced to `1`, and `total_pages` is `1` (or `0` when `total` is `0`). |
| `sort` | `desc` | `asc` or `desc`, applied to the area **title** (`node.title`). Any other value falls back to `desc`. There is no date-range filter — areas have no temporal dimension. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "areas": [
      {
        "id": 42,
        "name": "Piscina principal",
        "condominium_id": 7,
        "image_id": 15,
        "image_url": "https://host/sites/default/files/piscina.jpg",
        "open_time": "08:00",
        "close_time": "22:00",
        "slot_minutes": 60,
        "max_minutes": 120,
        "status": "active",
        "who_can_reserve": "both",
        "cancel_deadline_minutes": 120,
        "category": "pool",
        "notes": "<p>Aforo máximo 20 personas.</p><p>Prohibido el vidrio.</p>",
        "max_concurrent_reservations": 3
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

A condominium with no visible areas gets `{"areas": [], "pagination": {"total":
0, "page": 1, "limit": 20, "total_pages": 0}}` with `200` (not an error).
Requesting a page beyond the last one also returns `200` with `areas: []` (not
an error).

Notes:
- Access rule: the authenticated user must be related to `condominium_id`, using
  the same `myapi_condominium_related_nids()` lookup as the other
  `condominiums/{id}/...` endpoints.
- `condominium_id` that is not related to the authenticated user and
  `condominium_id` that does not exist at all return the exact same
  `403 condominium_access_denied` — the response never reveals whether a
  condominium exists.
- Only published (`status = 1`) `area` nodes are returned.
- The status criterion is **by inclusion**: only areas whose `field_area_status`
  is one of `active` / `maintenance` are exposed. Areas in status `closed`, and
  areas with **no** `field_area_status` row at all, are silently excluded from
  both the `areas` list and the `pagination.total` count — they behave as if
  they did not exist for this endpoint. Because the criterion is inclusion, a new
  status added by the business is hidden by default until it is added to the
  visible set, centralized in the `MYAPI_AREA_VISIBLE_STATUSES` constant so it
  can be adjusted in one place. This is the opposite of the by-exclusion
  criterion used by payments: "no status" is not a safe state to show.
- Every area includes exactly 15 keys: `id`, `name`, `condominium_id`,
  `image_id`, `image_url`, `open_time`, `close_time`, `slot_minutes`,
  `max_minutes`, `status`, `who_can_reserve`, `cancel_deadline_minutes`,
  `category`, `notes`, `max_concurrent_reservations` (see mapping table below).
  Text/list fields pass through as
  the raw stored value (e.g. `status`, `who_can_reserve`, `category` are the
  stored option keys, not their labels; `open_time`/`close_time` are raw `HH:MM`
  strings). A field is `null` when the node has no row in that field's storage
  table. `slot_minutes`, `max_minutes`, `cancel_deadline_minutes` and `image_id`
  are cast to `int` when present, `null` otherwise.
- `notes` is free text edited from the Drupal admin (field "Instrucciones o
  notas") and is **read-only** through the API. Its default text format used to
  be pinned to Full HTML; since SPEC 49 (`myapi_update_7013()`) the instance
  carries no explicit format, so each editor starts on the best format they are
  allowed to use — a hard-coded one left the textarea disabled for any role
  without `use text format full_html`. This changes nothing here: the format is
  never applied to the API response.
  It is returned **exactly as stored**: the value may contain HTML and the server
  never sanitizes or renders it (no `check_markup()`, no `filter_xss()`). Any
  client that renders it — a WebView in particular — is responsible for its own
  sanitization. `notes` is `null` only when the node has no row in
  `field_data_field_area_notes`; an empty string is returned as `""` and is not
  normalized to `null`. The text format itself is not exposed.
- `max_concurrent_reservations` is how many reservations may **coincide** in the
  same time slot in this area (the gym fits three groups at once). It counts
  reservations, not people. It is edited from the Drupal admin (field "Reservas
  simultáneas permitidas") and is **read-only** through the API.

  It is the **only** key that is not returned raw: it is always an `int` and
  always `>= 1`, already normalized. An area with no row in the storage table,
  or with `0`, or with a negative value stored by hand, returns `1` — **never**
  `null`. That is the fail-closed rule of the server's own reservation check
  (`myapi_reservation_effective_capacity()`): an empty field means "one at a
  time", exactly how every area behaved before this field existed, and it never
  means "unlimited". The key is exposed already normalized on purpose, so the
  client can draw free slots without re-implementing that rule and drifting out
  of sync with the server. A `0` does **not** close an area — that is what
  `status` is for.
- `image_id` and `image_url` are `null` **together** when the area has no image.
  When an image is present, `image_url` is the absolute URL built with
  `file_create_url()` over the joined `file_managed.uri`.
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **visible** set (published, matching condominium, status in the visible set),
  not the condominium's full area count. `total_pages` is `0` when `total` is `0`.
- Sorting is always by `name` (`node.title`); there is no other sort field.
  Areas sharing the same title are broken by `id` (`nid`) in the same direction
  as `sort`, so the order is deterministic and stable across requests and pages
  (no row can shift between pages on repeated calls).

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables instead of
going through the Field API, for query simplicity. A future schema change to any
of the fields below (rename, single→multi-value, bundle move, type change) will
silently break this endpoint without a Drupal update warning. `field_condominium`
is shared with the `reservation` bundle; the `n.type = 'area'` condition and the
per-join `entity_id` binding keep the query scoped to area nodes only.

Areas whose `field_image` lives in a **private** filesystem still resolve through
`file_create_url()`, but access control for private files is out of scope for
this endpoint — the caller receives a URL, not an authenticated stream.

| Drupal field | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `nid` | `id` | int | never `NULL` |
| `title` | `name` | string | never `NULL` |
| `field_condominium_target_id` | `condominium_id` | int | never `NULL` (it is the query filter) |
| `field_image_fid` | `image_id` | int | `NULL` if no image |
| `file_managed.uri` (via `file_create_url()`) | `image_url` | string | `NULL` when `image_id` is `NULL` |
| `field_open_time_value` | `open_time` | string | `NULL` if no row |
| `field_close_time_value` | `close_time` | string | `NULL` if no row |
| `field_slot_minutes_value` | `slot_minutes` | int | `NULL` if no row |
| `field_max_minutes_value` | `max_minutes` | int | `NULL` if no row |
| `field_area_status_value` | `status` | string | never `NULL` (inner join `IN` visible set) |
| `field_who_can_reserve_value` | `who_can_reserve` | string | `NULL` if no row |
| `field_cancel_deadline_minutes_value` | `cancel_deadline_minutes` | int | `NULL` if no row |
| `field_area_category_value` | `category` | string | `NULL` if no row |
| `field_area_notes_value` | `notes` | string (raw, may contain unsanitized HTML) | `NULL` if no row; `""` stays `""` |
| `field_concurrent_reservations_value` | `max_concurrent_reservations` | int (**normalized**, always `>= 1`) | never `NULL`: no row, `NULL`, `0` or negative all return `1` |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | `area` nodes. Also the sort column (`title`) and tie-breaker (`nid`). |
| `field_data_field_condominium` | `entity_id`, `field_condominium_target_id` | Area → condominium relation (`condominium_id`). Main filter of the endpoint. Inner join. |
| `field_data_field_area_status` | `entity_id`, `field_area_status_value` | `status`. Inner join filtered to `IN ('active','maintenance')`; `closed` and areas with no status row are excluded. |
| `field_data_field_image` | `entity_id`, `field_image_fid` | `image_id`, managed-file reference. Left join. |
| `file_managed` | `fid`, `uri` | `image_url`, built with `file_create_url()`. Left-joined on `field_image_fid`; avoids a per-row `file_load()`. |
| `field_data_field_open_time` | `entity_id`, `field_open_time_value` | `open_time`, text `HH:MM`. Left join. |
| `field_data_field_close_time` | `entity_id`, `field_close_time_value` | `close_time`, text `HH:MM`. Left join. |
| `field_data_field_slot_minutes` | `entity_id`, `field_slot_minutes_value` | `slot_minutes`, integer. Left join. |
| `field_data_field_max_minutes` | `entity_id`, `field_max_minutes_value` | `max_minutes`, integer. Left join. |
| `field_data_field_who_can_reserve` | `entity_id`, `field_who_can_reserve_value` | `who_can_reserve`, list text. Left join. |
| `field_data_field_cancel_deadline_minutes` | `entity_id`, `field_cancel_deadline_minutes_value` | `cancel_deadline_minutes`, integer. Left join. |
| `field_data_field_area_category` | `entity_id`, `field_area_category_value` | `category`, list text. Left join. |
| `field_data_field_area_notes` | `entity_id`, `field_area_notes_value` | `notes`, long text. Left join (alias `fnot`). Only `_value` is selected; `field_area_notes_format` is never exposed. |
| `field_data_field_concurrent_reservations` | `entity_id`, `field_concurrent_reservations_value` | `max_concurrent_reservations`, integer. Left join (alias `fcap`). A missing row yields `NULL`, which is normalized to `1` before it reaches the response. |

Since SPEC 46, `field_open_time` and `field_close_time` are validated as `HH:MM`
on a 24h clock (`00:00`–`23:59`, leading zero required) when an area is saved
from the Drupal admin form. The rule is format-only: `close_time <= open_time`
stays legal, because that is how an overnight area is expressed (SPEC 41).
Since SPEC 52 that rule lives in `includes/myapi.time_format.inc` and is shared
with the `reservation` start/end times; the behaviour of the area form did not
change with the move.

The API does **not** validate the format on read: `open_time` and `close_time`
are still returned exactly as stored, so an area saved with a malformed value
before that validation existed keeps being served as-is until its node is saved
again. Nothing rejects it, and the reservation arithmetic treats it the same way
it did before.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 403  | `condominium_access_denied` | `condominium_id` is not related to the authenticated user, or does not exist. Both cases return the same error — the response never distinguishes them. |
| 405  | `method_not_allowed` | Any HTTP method other than GET. |

Error envelope:
```json
{
  "success": false,
  "error_code": "condominium_access_denied",
  "error": "No tienes acceso a este condominio."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Example:**
```bash
curl -i -X GET 'https://host/api/v1/condominiums/7/areas?sort=asc&limit=50' \
  -H 'Authorization: Bearer <access_token>'
```

---

## GET /api/v1/areas/{id}

Returns a single `area` by id, in the same item shape as
`GET /api/v1/condominiums/{condominium_id}/areas`, wrapped as `{"area": ...}`.
Read-only. Applies the **same rules as the list**: the area is visible only
when it would also appear in the caller's list — it must be a published `area`
node with a visible `field_area_status` (`active`/`maintenance`) and belong to
a condominium the caller is related to. There are no query parameters.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Request body**

None. The area id travels in the path; any body sent is ignored.

**Success response (200)**

Same 15 keys as a list item, with the identical types and `NULL` rules (see the
mapping table under the list endpoint). `notes` included: raw value, may contain
unsanitized HTML, `null` when the node has no row.
`max_concurrent_reservations` included: always an `int` `>= 1`, never `null`.

```json
{
  "success": true,
  "data": {
    "area": {
      "id": 42,
      "name": "Piscina principal",
      "condominium_id": 7,
      "image_id": 15,
      "image_url": "https://host/sites/default/files/piscina.jpg",
      "open_time": "08:00",
      "close_time": "22:00",
      "slot_minutes": 60,
      "max_minutes": 120,
      "status": "active",
      "who_can_reserve": "both",
      "cancel_deadline_minutes": 120,
      "category": "pool",
      "notes": "<p>Aforo máximo 20 personas.</p><p>Prohibido el vidrio.</p>",
      "max_concurrent_reservations": 3
    }
  }
}
```

**Access & visibility (non-revealing 404)**

Every "not visible to you" case collapses into the **same** `404 area_not_found`:

- `{id}` is not a positive integer;
- no node with that id, or it is not a published `area` node;
- the area's `field_area_status` is not visible (`closed`, or no status row);
- the area belongs to a condominium the caller is not related to.

They are indistinguishable on purpose, mirroring the list — where a hidden area
or an area in another condominium simply never appears — so the endpoint never
reveals whether an area id exists or in which condominium it lives. (Note this
differs from the list's `403 condominium_access_denied`: there the condominium
is in the path, so access is reported on the condominium; here the path carries
only the area id, so a single non-revealing `404` is used instead.)

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found, revoked, expired, or the associated user does not exist/is blocked. |
| 404  | `area_not_found` | The id is invalid, references no published/visible `area`, or the area is in a condominium the caller is not related to. All indistinguishable. |
| 405  | `method_not_allowed` | Any HTTP method other than GET. |

**Example:**
```bash
curl -i -X GET 'https://host/api/v1/areas/42' \
  -H 'Authorization: Bearer <access_token>'
```

---

## GET /api/v1/areas/{id}/availability

Reports what an `area` has taken for a single day's **session**, derived from
every **confirmed**, published reservation of the area — across **all** units of
the condominium, not just the caller's — so the app can grey out the unbookable
slots before letting a resident confirm a new booking. For an area that closes
after midnight, the session for day `D` runs `[D open, D+1 close]` (see
**Availability is per SESSION** below). Read-only: no create/update/delete.

The endpoint only reports **that** a slot is taken and **how full** it is, never
**by whom**: items carry date/time keys and counters and nothing else (no `id`,
`unit_id`, `requester_id` or names).

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Required | Notes |
|-------|----------|-------|
| `date` | yes | The day to inspect, `YYYY-MM-DD`. Validated strictly: absent/empty → `422 missing_field`; wrong format or non-calendar date (e.g. `2026-02-30`) → `422 invalid_field`. There is no silent fallback to "today". |

There is no pagination and no other filter: one area, one day.

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "date": "2026-07-24",
    "capacity": 1,
    "busy": [
      {
        "start_date": "2026-07-24",
        "start_time": "23:00",
        "end_date": "2026-07-25",
        "end_time": "01:00"
      },
      {
        "start_date": "2026-07-25",
        "start_time": "01:00",
        "end_date": "2026-07-25",
        "end_time": "02:00"
      }
    ],
    "occupancy": [
      {
        "start_date": "2026-07-24",
        "start_time": "23:00",
        "end_date": "2026-07-25",
        "end_time": "01:00",
        "reserved": 1,
        "remaining": 0
      },
      {
        "start_date": "2026-07-25",
        "start_time": "01:00",
        "end_date": "2026-07-25",
        "end_time": "02:00",
        "reserved": 1,
        "remaining": 0
      }
    ]
  }
}
```

The response has **exactly 4 keys**: `date`, `capacity`, `busy`, `occupancy`.
A day with no confirmed reservations returns `200` with `{"date": "<date>",
"capacity": <n>, "busy": [], "occupancy": []}` (not an error). `data.date`
echoes the validated `date` verbatim.

`capacity` and `occupancy` were added by SPEC 45; the change is **additive**.
`date` and `busy` keep their name and shape, and in a capacity-1 area `busy`
keeps its exact previous content too.

The example above is a capacity-1 area that closes after midnight, with an
evening booking crossing into `D+1` and a back-to-back early-morning tail stored
under `D+1`. They do not overlap — in a capacity-1 area they could not, the
create path would have rejected the second one — so `occupancy` mirrors `busy`
one to one, which is exactly what capacity 1 always looks like.

**`capacity` — how many reservations may coincide**

The area's effective capacity, always an `int` `>= 1`, normalized with the same
rule as `max_concurrent_reservations` in the area item: no row, `0` or a
negative value all read as `1`. Capacity `1` is the historical behaviour — one
reservation per slot — and it is what every area returns until an admin sets the
field.

**`occupancy` — how full each range is**

The session split into ranges of **constant occupancy**: the axis is cut at
every reservation start and end, and each resulting range reports its counters.
Six keys, all always present:

| Key | Type | Meaning |
|---|---|---|
| `start_date` / `start_time` | string | Where the range begins. |
| `end_date` / `end_time` | string | Where it ends. |
| `reserved` | int `>= 1` | Reservations active during the whole range. |
| `remaining` | int `>= 0` | Free slots, `max(0, capacity - reserved)`. |

- Ranges with `reserved = 0` are **not** emitted, so the list is not necessarily
  contiguous: a gap between two ranges means nobody has that time booked.
- Sorted ascending by `(start_date, start_time)`.
- `remaining` is **never negative**. An admin may lower the capacity of an area
  that already has reservations; those are respected until they pass, and the
  area simply accepts no new ones (`remaining: 0`) until occupancy drops.
- **Always present**, capacity-1 areas included — where it is redundant with
  `busy` (one range per reservation, always `reserved: 1, remaining: 0`) — so
  the client never has to branch on capacity to read the response.
- Counters only: no `id`, no `unit_id`, no `requester_id`, no names.

**`busy` — what you cannot book**

`busy` means "this cannot be reserved", and that meaning is unchanged. How it is
derived depends on the capacity:

| Effective capacity | How `busy` is built |
|---|---|
| `== 1` | One entry **per reservation**, exactly as before SPEC 45. Two consecutive bookings (`10:00-11:00` and `11:00-12:00`) stay **two** entries; they are never merged. |
| `> 1` | Only the **saturated** ranges (`reserved >= capacity`) taken from `occupancy`, with contiguous saturated ranges merged into a single block. A range with free slots is still bookable, so it does **not** appear here. |

That split is deliberate. Applying the block calculation to every area would
merge those two consecutive capacity-1 bookings into a single `10:00-12:00`
block and change the payload of every area already in production. With the split
the regression is zero.

So an area of capacity `3` with two overlapping reservations returns
`"busy": []` — the slot still has room — and an `occupancy` range with
`"reserved": 2, "remaining": 1`.

**Availability is per SESSION, not per calendar day**

`date=D` reports the area's whole operating **session** for day `D`, i.e. the
window `[D open_time, D+1 close_time]`:

- **Normal area** (`close_time > open_time`, e.g. `08:00–22:00`): the session is
  just the calendar day `D`. All reservations are same-day
  (`start_date == end_date`); none cross midnight (the create path rejects a
  range that would, with `422 reservation_crosses_midnight`).
- **Area that closes after midnight** (`close_time <= open_time`, e.g.
  `12:00–02:00`): the session spans two calendar days. Because the create path
  stores each reservation under the **clock day** of its start (SPEC 41), a
  single session is assembled from two stored slices:
  - `field_date = D` with `start_time >= open_time` — the evening/late-night
    part; a booking passing midnight (`end_time <= start_time`, e.g.
    `23:00 → 01:00`) reports `end_date = D+1`.
  - `field_date = D+1` with `start_time < close_time` — the **early-morning
    tail** (e.g. `00:00 → 02:00`), stored under its own clock day `D+1` and
    reported with `start_date = end_date = D+1`.

  A `field_date = D` row starting **before** `open_time` is the tail of the
  **previous** session and is excluded; a `field_date = D+1` row starting **at
  or after** `close_time` is the evening of the **next** session and is also
  excluded. So every reservation belongs to exactly one session, and the
  early-morning tail of a wrapping area appears **only** under the session it
  belongs to — never doubled into the next day's session.

Each item in `busy` has **exactly four keys, always present**, all absolute
dates so the client never infers the day from the times (`occupancy` items carry
these same four plus `reserved`/`remaining`):

| Key | Type | Meaning |
|---|---|---|
| `start_date` | string `YYYY-MM-DD` | The reservation's own `field_date` (its start clock day). |
| `start_time` | string `HH:MM` | Raw stored start time. |
| `end_date` | string `YYYY-MM-DD` | Same as `start_date`, or the **next day** when the reservation crosses midnight (`end_time <= start_time`). |
| `end_time` | string `HH:MM` | Raw stored end time (a range crossing midnight is stored wrapped, e.g. `02:00`, not `26:00`). |

In a capacity-`> 1` area a `busy` item is a saturated **block**, not a single
reservation, so its bounds are the edges of the saturated stretch rather than
one booking's stored times. The four keys and their types are identical either
way.

`busy` and `occupancy` are both sorted ascending by `(start_date, start_time)`,
so the evening part of a wrapping session comes before its early-morning tail.

**Access & visibility (non-revealing 404)**

Identical to [`GET /api/v1/areas/{id}`](#get-apiv1areasid): every "not visible to
you" case collapses into the **same** `404 area_not_found` — invalid id, no such
node, not a published `area`, hidden `field_area_status`, or an area in a
condominium the caller is not related to. Indistinguishable on purpose.

**Data model assumptions**

Reads directly from the Field API storage tables (same approach as the rest of
the module), joining published `reservation` nodes on `field_area`,
`field_reservation_status = 'confirmed'`, `field_date`, `field_start_time` and
`field_end_time`. A reservation with a missing `field_date` / `field_start_time`
/ `field_end_time` row is excluded by the inner joins (consistent with the write
path). `field_date` is stored as `YYYY-MM-DD HH:MM:SS`, so the day is matched
with `SUBSTR(field_date_value, 1, 10)`. The endpoint fetches only the day(s) the
session needs: just `date` for a normal area, and `date` **plus** `date + 1` for
an area that closes after midnight (to pick up the early-morning tail stored
under the next clock day); the area's `field_open_time`/`field_close_time` decide
which stored rows belong to the session. `cancelled` reservations never produce a
row and so never appear in `busy`. A future schema change to any of those fields
(rename, single→multi-value, type change) would silently break this endpoint
without a Drupal update warning.

`capacity` comes from the same `field_concurrent_reservations` left join as
the area item, so no extra query is issued for it.

This endpoint only **reports**; it never creates, updates or rejects anything.
It does share the capacity rule with `POST /api/v1/reservations` — both resolve
the effective capacity through the same helper — so what this endpoint calls
saturated is exactly what the create path refuses with `409 area_capacity_full`
(or `409 reservation_overlap` in a capacity-1 area). See
[reservation.md](reservation.md).

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found, revoked, expired, or the associated user does not exist/is blocked. |
| 404  | `area_not_found` | The id is invalid, references no published/visible `area`, or the area is in a condominium the caller is not related to. All indistinguishable. |
| 422  | `missing_field` | `date` query param is absent or empty (`@field = date`). |
| 422  | `invalid_field` | `date` is not `YYYY-MM-DD` or is a non-calendar date (`@field = date`). |
| 405  | `method_not_allowed` | Any HTTP method other than GET. |

**Manual test matrix (curl)**

No token / malformed header → `401 missing_authorization`; unknown token →
`401 invalid_token`; foreign-condominium area → `404 area_not_found`:
```bash
# Happy path: a day with reservations from several units.
curl -i -X GET 'https://host/api/v1/areas/42/availability?date=2026-07-25' \
  -H 'Authorization: Bearer <access_token>'

# Missing date → 422 missing_field.
curl -i -X GET 'https://host/api/v1/areas/42/availability' \
  -H 'Authorization: Bearer <access_token>'

# Non-calendar date → 422 invalid_field.
curl -i -X GET 'https://host/api/v1/areas/42/availability?date=2026-02-30' \
  -H 'Authorization: Bearer <access_token>'

# Area that closes after midnight: the session for D shows the evening slots of
# D plus the early-morning tail stored under D+1 (e.g. a 00:00->02:00 booking).
# occupancy follows the same session split as busy.
curl -i -X GET 'https://host/api/v1/areas/42/availability?date=2026-07-24' \
  -H 'Authorization: Bearer <access_token>'

# Wrong method → 405 method_not_allowed.
curl -i -X POST 'https://host/api/v1/areas/42/availability?date=2026-07-25' \
  -H 'Authorization: Bearer <access_token>'

# --- Capacity (SPEC 45) ---

# No-regression, capacity 1: an area with two consecutive bookings
# (10:00-11:00 and 11:00-12:00) must return capacity: 1 and TWO busy entries,
# byte for byte what it returned before SPEC 45 — never a merged 10:00-12:00.
curl -i -X GET 'https://host/api/v1/areas/42/availability?date=2026-08-01' \
  -H 'Authorization: Bearer <access_token>'

# Room left: area of capacity 3 with 2 overlapping reservations →
# capacity: 3, busy: [], occupancy with reserved: 2, remaining: 1.
curl -i -X GET 'https://host/api/v1/areas/77/availability?date=2026-08-01' \
  -H 'Authorization: Bearer <access_token>'

# Saturated: the same area with 3 overlapping reservations → that range appears
# in busy, and occupancy reports reserved: 3, remaining: 0.
curl -i -X GET 'https://host/api/v1/areas/77/availability?date=2026-08-02' \
  -H 'Authorization: Bearer <access_token>'

# Merging: capacity 2 with 10:00-11:00 and 11:00-12:00 both saturated → ONE
# busy block 10:00 → 12:00, while occupancy still reports the two ranges.
curl -i -X GET 'https://host/api/v1/areas/78/availability?date=2026-08-01' \
  -H 'Authorization: Bearer <access_token>'

# Lowered capacity: an area whose admin dropped capacity below its existing
# bookings → reserved > capacity, remaining floors at 0 (never negative).
curl -i -X GET 'https://host/api/v1/areas/79/availability?date=2026-08-01' \
  -H 'Authorization: Bearer <access_token>'
```
