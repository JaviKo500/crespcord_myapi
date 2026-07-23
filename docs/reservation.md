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
