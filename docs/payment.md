## GET /api/v1/units/{unit_id}/payments

Returns a paginated list of `pagos` nodes (exposed as `payments`) belonging to
`unit_id`, visible only to the authenticated user who is the owner or occupant
of that unit — same access rule as `GET /api/v1/units`. Read-only: no
create/update/delete, no single-payment detail endpoint.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. Special value `-1` disables pagination entirely: every matching payment is returned in one response, `page` is forced to `1`, and `total_pages` is `1` (or `0` when `total` is `0`). |
| `sort` | `desc` | `asc` or `desc`, applied to `payment_date` (`field_fecha_de_pago_value`). Any other value falls back to `desc`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, keeps only payments with `payment_date >= date_from`. Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, keeps only payments with `payment_date <= date_to`. Same silent-ignore rule as `date_from`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "payments": [
      {
        "id": 902,
        "title": "Pago julio 2026",
        "unit_id": 45,
        "payment_date": "2026-07-05",
        "status": "Aprobado",
        "payment_method": "Transferencia",
        "reference": "TRX-88213",
        "amount": 187.32
      }
    ],
    "pagination": {
      "total": 4,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

A unit with no payments in a state other than `Nuevo` gets `{"payments": [],
"pagination": {"total": 0, "page": 1, "limit": 20, "total_pages": 0}}` with
`200` (not an error). Requesting a page beyond the last one also returns `200`
with `payments: []` (not an error).

Notes:
- Access rule: the authenticated user must be the owner or occupant of
  `unit_id`, using the same `myapi_unit_related_nids()` lookup as
  `GET /api/v1/units` (owner via `field_propietario`, occupant via
  `field_ocupante` legacy single-value or `field_ocupantes` current
  multi-value, evaluated as OR).
- `unit_id` that does not belong to the authenticated user and `unit_id` that
  does not exist at all return the exact same `403 unit_access_denied` — the
  response never reveals whether a unit exists.
- Only published (`status = 1`) `pagos` nodes are returned.
- The state criterion is **by exclusion**: every payment whose
  `field_estado_pago` is **not** `Nuevo` is exposed. Payments in state `Nuevo`,
  and payments with no `field_estado_pago` row at all, are silently excluded
  from both the `payments` list and the `pagination.total` count — they behave
  as if they did not exist for this endpoint. Because the criterion is
  exclusion, if the business adds a new state (e.g. an internal draft) it will
  be exposed automatically unless it is also called `Nuevo`; the excluded value
  is centralized in the `MYAPI_PAYMENT_EXCLUDED_STATUS` constant so it can be
  adjusted in one place.
- Every payment includes exactly 8 keys: `id`, `title`, `unit_id`,
  `payment_date`, `status`, `payment_method`, `reference`, `amount` (see mapping
  table below). `amount` (or a text field) is `null` when the node has no row in
  that field's storage table — no other transformation or business validation is
  applied (e.g. `status`/`payment_method` are the raw stored text, not validated
  against a fixed list; `payment_date` is a raw string, not reformatted).
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **filtered** set (estado `<> 'Nuevo'`, plus `date_from`/`date_to` if any), not
  the unit's full payment count. `total_pages` is `0` when `total` is `0`.
- Sorting is always by `payment_date` (`field_fecha_de_pago_value`); there is no
  other sort field.

**Date-range filter (`date_from` / `date_to`)**

Both bounds are optional and independent: you may send only `date_from`, only
`date_to`, both, or neither. They filter on `payment_date` inclusively on both
ends.

- Comparison is made on the first 10 characters of `field_fecha_de_pago_value`
  (`SUBSTR(..., 1, 10)`), so a payment stored as either `2026-07-05` or
  `2026-07-05T00:00:00` is **included** by `date_to=2026-07-05` — the time
  suffix never pushes the last day out of range.
- The filter is applied **before** pagination and sorting, so `page`, `limit`
  and `sort` operate over the already-filtered set.
- Payments with no `field_fecha_de_pago` row (`payment_date = null`) are
  **excluded** whenever at least one bound is active — a payment without a date
  cannot belong to a date range.
- Invalid values (bad format or non-calendar dates) are ignored per bound, and
  an inverted range (`date_from > date_to`) drops the whole filter, so the
  endpoint responds exactly as if no range had been sent. No `422` is raised
  for either case — this mirrors the lax handling of `page`/`limit`/`sort`.

Example: `GET /api/v1/units/45/payments?date_from=2026-07-01&date_to=2026-07-31`
returns only payments whose `payment_date` falls within July 2026 inclusive.

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables instead of
going through the Field API, for query simplicity. A future schema change to any
of the fields below (rename, single→multi-value, bundle move, type change) will
silently break this endpoint without a Drupal update warning. `field_valor` is
single-value and mapped 1:1. Note that `field_vivienda`, `field_referencia` and
`field_valor` are shared with other content types; the `n.type = 'pagos'`
condition and the per-join `entity_id` binding keep the query scoped to payment
nodes only.

| Drupal field | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `nid` | `id` | int | never `NULL` |
| `title` | `title` | string | never `NULL` |
| `field_vivienda_target_id` | `unit_id` | int | never `NULL` (it is the query filter) |
| `field_fecha_de_pago_value` | `payment_date` | string | `NULL` if no row |
| `field_estado_pago_value` | `status` | string | never `NULL` (inner join `<> 'Nuevo'`) |
| `field_forma_de_pago_value` | `payment_method` | string | `NULL` if no row |
| `field_referencia_value` | `reference` | string | `NULL` if no row |
| `field_valor_value` | `amount` | float | `NULL` if no row |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | `pagos` nodes. |
| `field_data_field_vivienda` | `entity_id`, `field_vivienda_target_id` | Payment → unit relation (`unit_id`). Main filter of the endpoint. |
| `field_data_field_estado_pago` | `entity_id`, `field_estado_pago_value` | `status`. Inner join filtered to `<> 'Nuevo'`; state `Nuevo` and payments with no estado row are excluded. |
| `field_data_field_fecha_de_pago` | `entity_id`, `field_fecha_de_pago_value` | `payment_date`. Default sort column and date-range filter column. |
| `field_data_field_forma_de_pago` | `entity_id`, `field_forma_de_pago_value` | `payment_method`, text. |
| `field_data_field_referencia` | `entity_id`, `field_referencia_value` | `reference`, text. |
| `field_data_field_valor` | `entity_id`, `field_valor_value` | `amount`, `decimal`, mapped 1:1. |

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
