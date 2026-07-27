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
        "notes": "<p>Aforo máximo 20 personas.</p><p>Prohibido el vidrio.</p>"
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
- Every area includes exactly 14 keys: `id`, `name`, `condominium_id`,
  `image_id`, `image_url`, `open_time`, `close_time`, `slot_minutes`,
  `max_minutes`, `status`, `who_can_reserve`, `cancel_deadline_minutes`,
  `category`, `notes` (see mapping table below). Text/list fields pass through as
  the raw stored value (e.g. `status`, `who_can_reserve`, `category` are the
  stored option keys, not their labels; `open_time`/`close_time` are raw `HH:MM`
  strings). A field is `null` when the node has no row in that field's storage
  table. `slot_minutes`, `max_minutes`, `cancel_deadline_minutes` and `image_id`
  are cast to `int` when present, `null` otherwise.
- `notes` is free text edited from the Drupal admin (field "Instrucciones o
  notas", default text format Full HTML) and is **read-only** through the API.
  It is returned **exactly as stored**: the value may contain HTML and the server
  never sanitizes or renders it (no `check_markup()`, no `filter_xss()`). Any
  client that renders it — a WebView in particular — is responsible for its own
  sanitization. `notes` is `null` only when the node has no row in
  `field_data_field_area_notes`; an empty string is returned as `""` and is not
  normalized to `null`. The text format itself is not exposed.
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

Same 14 keys as a list item, with the identical types and `NULL` rules (see the
mapping table under the list endpoint). `notes` included: raw value, may contain
unsanitized HTML, `null` when the node has no row.

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
      "notes": "<p>Aforo máximo 20 personas.</p><p>Prohibido el vidrio.</p>"
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

Returns the **busy** time ranges of an `area` for a single day's **session**:
every **confirmed**, published reservation of the area — across **all** units of
the condominium, not just the caller's — so the app can grey out the taken slots
before letting a resident confirm a new booking. For an area that closes after
midnight, the session for day `D` runs `[D open, D+1 close]` (see **Availability
is per SESSION** below). Read-only: no create/update/delete.

The endpoint only reports **that** a slot is taken, never **by whom**: an item
carries the four date/time keys and nothing else (no `id`, `unit_id`,
`requester_id` or names).

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
    "busy": [
      {
        "start_date": "2026-07-24",
        "start_time": "23:00",
        "end_date": "2026-07-25",
        "end_time": "01:00"
      },
      {
        "start_date": "2026-07-25",
        "start_time": "00:00",
        "end_date": "2026-07-25",
        "end_time": "02:00"
      }
    ]
  }
}
```

A day with no confirmed reservations returns `200` with `{"date": "<date>",
"busy": []}` (not an error). `data.date` echoes the validated `date` verbatim.

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
dates so the client never infers the day from the times:

| Key | Type | Meaning |
|---|---|---|
| `start_date` | string `YYYY-MM-DD` | The reservation's own `field_date` (its start clock day). |
| `start_time` | string `HH:MM` | Raw stored start time. |
| `end_date` | string `YYYY-MM-DD` | Same as `start_date`, or the **next day** when the reservation crosses midnight (`end_time <= start_time`). |
| `end_time` | string `HH:MM` | Raw stored end time (a range crossing midnight is stored wrapped, e.g. `02:00`, not `26:00`). |

`busy` is sorted ascending by `(start_date, start_time)`, so the evening part of
a wrapping session comes before its early-morning tail.

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

This endpoint only **reports** overlaps; it does not touch
`myapi_reservation_has_overlap()` nor `POST /api/v1/reservations`.

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
curl -i -X GET 'https://host/api/v1/areas/42/availability?date=2026-07-24' \
  -H 'Authorization: Bearer <access_token>'

# Wrong method → 405 method_not_allowed.
curl -i -X POST 'https://host/api/v1/areas/42/availability?date=2026-07-25' \
  -H 'Authorization: Bearer <access_token>'
```
