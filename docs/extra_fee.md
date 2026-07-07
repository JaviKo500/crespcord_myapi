## GET /api/v1/units/{unit_id}/extra-fees

Returns a paginated list of `alicuota_extra` nodes (exposed as `extra_fees`)
belonging to `unit_id`, visible only to the authenticated user who is the owner
or occupant of that unit — same access rule as `GET /api/v1/units`. Read-only:
no create/update/delete, no single-extra-fee detail endpoint.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. Special value `-1` disables pagination entirely: every matching extra fee is returned in one response, `page` is forced to `1`, and `total_pages` is `1` (or `0` when `total` is `0`). |
| `sort` | `desc` | `asc` or `desc`, applied to `date` (`field_fecha_value`). Any other value falls back to `desc`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, keeps only extra fees with `date >= date_from`. Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, keeps only extra fees with `date <= date_to`. Same silent-ignore rule as `date_from`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "extra_fees": [
      {
        "id": 812,
        "title": "Alícuota extra julio 2026",
        "unit_id": 45,
        "date": "2026-07-01",
        "status": "Enviado",
        "extra_fee": 25.0,
        "previous_balance": 10.5,
        "total": 35.5,
        "details": "Reparación de bomba de agua"
      }
    ],
    "pagination": {
      "total": 3,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

A unit with no extra fees gets `{"extra_fees": [], "pagination": {"total": 0,
"page": 1, "limit": 20, "total_pages": 0}}` with `200` (not an error).
Requesting a page beyond the last one also returns `200` with `extra_fees: []`
(not an error).

Notes:
- Access rule: the authenticated user must be the owner or occupant of
  `unit_id`, using the same `myapi_unit_related_nids()` lookup as
  `GET /api/v1/units` (owner via `field_propietario`, occupant via
  `field_ocupante` legacy single-value or `field_ocupantes` current
  multi-value, evaluated as OR).
- `unit_id` that does not belong to the authenticated user and `unit_id` that
  does not exist at all return the exact same `403 unit_access_denied` — the
  response never reveals whether a unit exists.
- Only published (`status = 1`) `alicuota_extra` nodes are returned.
- Only extra fees whose `field_estado` state is `Enviado` are exposed. Extra
  fees in any other state, and extra fees with no `field_estado` row at all, are
  silently excluded from both the `extra_fees` list and the `pagination.total`
  count — they behave as if they did not exist for this endpoint. Because of
  this filter, `status` in every returned item is always `"Enviado"`.
- Every extra fee includes exactly 9 keys: `id`, `title`, `unit_id`, `date`,
  `status`, `extra_fee`, `previous_balance`, `total`, `details` (see mapping
  table below). A decimal field (or `date`) is `null` when the node has no row
  in that field's storage table — no other transformation or business
  validation is applied (e.g. `status` is the raw stored text, not validated
  against a fixed list; `date` is a raw string, not reformatted). `details` is
  the exception: it is an **empty string (`""`)**, never `null`, when the node
  has no `field_detalle` row.
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **filtered** set (after `date_from`/`date_to` are applied, if any), not the
  unit's full extra-fee count. `total_pages` is `0` when `total` is `0`.
- Sorting is always by `date` (`field_fecha_value`); there is no other sort
  field.

**Date-range filter (`date_from` / `date_to`)**

Both bounds are optional and independent: you may send only `date_from`, only
`date_to`, both, or neither. They filter on `date` inclusively on both ends.

- Comparison is made on the first 10 characters of `field_fecha_value`
  (`SUBSTR(..., 1, 10)`), so an extra fee stored as either `2026-07-01` or
  `2026-07-01T00:00:00` is **included** by `date_to=2026-07-01` — the time
  suffix never pushes the last day out of range.
- The filter is applied **before** pagination and sorting, so `page`, `limit`
  and `sort` operate over the already-filtered set.
- Extra fees with no `field_fecha` row (`date = null`) are **excluded** whenever
  at least one bound is active — an extra fee without a date cannot belong to a
  date range.
- Invalid values (bad format or non-calendar dates) are ignored per bound, and
  an inverted range (`date_from > date_to`) drops the whole filter, so the
  endpoint responds exactly as if no range had been sent. No `422` is raised
  for either case — this mirrors the lax handling of `page`/`limit`/`sort`.

Example: `GET /api/v1/units/45/extra-fees?date_from=2026-07-01&date_to=2026-07-31`
returns only extra fees whose `date` falls within July 2026 inclusive.

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables
instead of going through the Field API, for query simplicity. A future schema
change to any of the fields below (rename, single→multi-value, bundle move)
will silently break this endpoint without a Drupal update warning. All
decimal fields are single-value and mapped 1:1. Note that `field_vivienda`,
`field_estado`, `field_saldo_anterior` and `field_total` are shared with other
content types (e.g. `recibo`); the `n.type = 'alicuota_extra'` condition and
the per-join `entity_id` binding keep the query scoped to extra-fee nodes only.

| Drupal field | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `nid` | `id` | int | never `NULL` |
| `title` | `title` | string | never `NULL` |
| `field_vivienda_target_id` | `unit_id` | int | never `NULL` (it is the query filter) |
| `field_fecha_value` | `date` | string | `NULL` if no row |
| `field_estado_value` | `status` | string | always `"Enviado"` (endpoint filter) |
| `field_valor_extra_value` | `extra_fee` | float | `NULL` if no row |
| `field_saldo_anterior_value` | `previous_balance` | float | `NULL` if no row |
| `field_total_value` | `total` | float | `NULL` if no row |
| `field_detalle_value` | `details` | string | `""` (empty string) if no row, never `NULL` |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | `alicuota_extra` nodes. |
| `field_data_field_vivienda` | `entity_id`, `field_vivienda_target_id` | Extra fee → unit relation (`unit_id`). Main filter of the endpoint. |
| `field_data_field_estado` | `entity_id`, `field_estado_value` | `status`. Inner join filtered to `Enviado`; other states are excluded from the endpoint. |
| `field_data_field_fecha` | `entity_id`, `field_fecha_value` | `date`. Default sort column and date-range filter column. |
| `field_data_field_valor_extra` | `entity_id`, `field_valor_extra_value` | `extra_fee`, `decimal`, mapped 1:1. |
| `field_data_field_saldo_anterior` | `entity_id`, `field_saldo_anterior_value` | `previous_balance`, `decimal`, mapped 1:1. |
| `field_data_field_total` | `entity_id`, `field_total_value` | `total`, `decimal`, mapped 1:1. |
| `field_data_field_detalle` | `entity_id`, `field_detalle_value` | `details`, text; empty string when the row is absent. |

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
