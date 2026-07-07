## GET /api/v1/condominiums/{condominium_id}/expenses

Returns a paginated list of `gastos` nodes (exposed as `expenses`) belonging to
`condominium_id`, visible only to the authenticated user who owns or occupies
at least one unit in that condominium. Read-only: no create/update/delete, no
single-expense detail endpoint.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. Special value `-1` disables pagination entirely: every matching expense is returned in one response, `page` is forced to `1`, and `total_pages` is `1` (or `0` when `total` is `0`). |
| `sort` | `desc` | `asc` or `desc`, applied to `expense_date` (`field_fecha_de_gasto_value`). Any other value falls back to `desc`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, keeps only expenses with `expense_date >= date_from`. Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, keeps only expenses with `expense_date <= date_to`. Same silent-ignore rule as `date_from`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "expenses": [
      {
        "id": 1204,
        "title": "Mantenimiento ascensor julio",
        "condominium_id": 12,
        "description": "Mantenimiento mensual de ascensores",
        "category_id": 34,
        "category_name": "Mantenimiento",
        "expense_date": "2026-07-05",
        "amount": 320.50,
        "reference": "FAC-00981",
        "status": "Activo"
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

A condominium with no expenses in state `Activo` gets `{"expenses": [],
"pagination": {"total": 0, "page": 1, "limit": 20, "total_pages": 0}}` with
`200` (not an error). Requesting a page beyond the last one also returns `200`
with `expenses: []` (not an error).

Notes:
- Access rule: the authenticated user must own or occupy at least one
  `vivienda` node whose `field_condominio` points to `condominium_id`, via the
  new `myapi_condominium_related_nids()` helper (built on top of
  `myapi_unit_related_nids()`).
- `condominium_id` that does not belong to the authenticated user (no unit of
  theirs points to it) and `condominium_id` that does not exist at all return
  the exact same `403 condominium_access_denied` — the response never reveals
  whether a condominium exists.
- Only published (`status = 1`) `gastos` nodes are returned.
- The state criterion is a **single exposed value**: only expenses whose
  `field_estado_gasto` is exactly `Activo` are exposed. Any other state, and
  expenses with no `field_estado_gasto` row at all, are silently excluded from
  both the `expenses` list and the `pagination.total` count — they behave as if
  they did not exist for this endpoint. The exposed value is centralized in the
  `MYAPI_EXPENSE_EXPOSED_STATUS` constant so it can be adjusted in one place.
  Because of this, `status` is always `"Activo"` in every returned item.
- Every expense includes exactly 10 keys: `id`, `title`, `condominium_id`,
  `description`, `category_id`, `category_name`, `expense_date`, `amount`,
  `reference`, `status` (see mapping table below). A field is `null` when the
  node has no row in that field's storage table — no other transformation or
  business validation is applied (e.g. `category_name`/`expense_date` are the
  raw stored values, not reformatted or validated against a fixed list).
- `category_id` (raw taxonomy `tid`) and `category_name` (resolved term name)
  are exposed as two separate keys. A `category_id` with no matching row in
  `taxonomy_term_data` (deleted term) comes back with `category_name: null`
  while `category_id` stays non-null — the client must tolerate that
  combination.
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **filtered** set (estado `= 'Activo'`, plus `date_from`/`date_to` if any), not
  the condominium's full expense count. `total_pages` is `0` when `total` is
  `0`.
- Sorting is always by `expense_date` (`field_fecha_de_gasto_value`); there is
  no other sort field.

**Date-range filter (`date_from` / `date_to`)**

Both bounds are optional and independent: you may send only `date_from`, only
`date_to`, both, or neither. They filter on `expense_date` inclusively on both
ends.

- Comparison is made on the first 10 characters of
  `field_fecha_de_gasto_value` (`SUBSTR(..., 1, 10)`), so an expense stored as
  either `2026-07-05` or `2026-07-05T00:00:00` is **included** by
  `date_to=2026-07-05` — the time suffix never pushes the last day out of
  range.
- The filter is applied **before** pagination and sorting, so `page`, `limit`
  and `sort` operate over the already-filtered set.
- Expenses with no `field_fecha_de_gasto` row (`expense_date = null`) are
  **excluded** whenever at least one bound is active — an expense without a
  date cannot belong to a date range.
- Invalid values (bad format or non-calendar dates) are ignored per bound, and
  an inverted range (`date_from > date_to`) drops the whole filter, so the
  endpoint responds exactly as if no range had been sent. No `422` is raised
  for either case — this mirrors the lax handling of `page`/`limit`/`sort`.

Example: `GET /api/v1/condominiums/12/expenses?date_from=2026-07-01&date_to=2026-07-31`
returns only expenses whose `expense_date` falls within July 2026 inclusive.

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables instead of
going through the Field API, for query simplicity. A future schema change to any
of the fields below (rename, single→multi-value, bundle move, type change) will
silently break this endpoint without a Drupal update warning. `field_valor` is
single-value and mapped 1:1. Note that `field_condominio`, `field_categoria`,
`field_valor` and `field_referencia` are shared with other content types (e.g.
`field_condominio` is also used by `vivienda`); the `n.type = 'gastos'`
condition and the per-join `entity_id` binding keep the query scoped to expense
nodes only.

| Drupal field | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `nid` | `id` | int | never `NULL` |
| `title` | `title` | string | never `NULL` |
| `field_condominio_target_id` | `condominium_id` | int | never `NULL` (it is the query filter) |
| `field_descripcion_value` | `description` | string | `NULL` if no row |
| `field_categoria_tid` | `category_id` | int | `NULL` if no row |
| `taxonomy_term_data.name` (joined by `category_id`) | `category_name` | string | `NULL` if no row or the term does not resolve |
| `field_fecha_de_gasto_value` | `expense_date` | string | `NULL` if no row |
| `field_valor_value` | `amount` | float | `NULL` if no row |
| `field_referencia_value` | `reference` | string | `NULL` if no row |
| `field_estado_gasto_value` | `status` | string | never `NULL` (inner join `= 'Activo'`); always `"Activo"` |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | `gastos` nodes. |
| `field_data_field_condominio` | `entity_id`, `field_condominio_target_id` | Expense → condominium relation (`condominium_id`). Main filter of the endpoint. Also used, for `vivienda` nodes, by `myapi_condominium_related_nids()` to resolve which condominiums the user has access to. |
| `field_data_field_estado_gasto` | `entity_id`, `field_estado_gasto_value` | `status`. Inner join filtered to `= 'Activo'`; any other state and expenses with no estado row are excluded. |
| `field_data_field_fecha_de_gasto` | `entity_id`, `field_fecha_de_gasto_value` | `expense_date`. Default sort column and date-range filter column. |
| `field_data_field_descripcion` | `entity_id`, `field_descripcion_value` | `description`, text. |
| `field_data_field_categoria` | `entity_id`, `field_categoria_tid` | `category_id`, joined to `taxonomy_term_data.tid` for `category_name`. |
| `field_data_field_valor` | `entity_id`, `field_valor_value` | `amount`, `decimal`, mapped 1:1. |
| `field_data_field_referencia` | `entity_id`, `field_referencia_value` | `reference`, text. |
| `taxonomy_term_data` | `tid`, `name` | Resolves `category_name` from `category_id`. |

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 403  | `condominium_access_denied` | `condominium_id` is not accessible to the authenticated user (no unit of theirs points to it), or does not exist. Both cases return the same error — the response never distinguishes them. |
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
