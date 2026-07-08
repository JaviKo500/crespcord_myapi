## GET /api/v1/condominiums/{condominium_id}/summary

Returns a condominium-level summary for the authenticated user: the
condominium's `id` and `name`, the aggregated `total_expenses` and
`expenses_count` of its `Activo` expenses (optionally narrowed by a date
range), and its `cash_balance` (`field_saldo_caja`). Visible only to the user
who owns or occupies at least one unit in that condominium. Read-only: no
create/update/delete, and no per-expense breakdown (that is
`GET /api/v1/condominiums/{condominium_id}/expenses`, see
[expense.md](expense.md)).

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, narrows the aggregate to expenses with `expense_date >= date_from`. Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, narrows the aggregate to expenses with `expense_date <= date_to`. Same silent-ignore rule as `date_from`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "id": 12,
    "name": "Edificio El Sáuco",
    "total_expenses": 4820.50,
    "expenses_count": 15,
    "cash_balance": 12500.00
  }
}
```

`data` always contains exactly these 5 keys: `id`, `name`, `total_expenses`,
`expenses_count`, `cash_balance`.

A condominium with no expenses in state `Activo` (for the applied filter, if
any) returns `total_expenses: 0.0` and `expenses_count: 0` with `200` — a
monetary total is always numeric, never `null`. `cash_balance`, by contrast, is
`null` when the condominium has no `field_saldo_caja` row (balance not
recorded), which is a genuine absence of data.

Notes:
- Access rule: the authenticated user must own or occupy at least one
  `vivienda` node whose `field_condominio` points to `condominium_id`, via the
  `myapi_condominium_related_nids()` helper (shared with the expenses
  endpoint).
- `condominium_id` that does not belong to the authenticated user (no unit of
  theirs points to it) and `condominium_id` that does not exist at all return
  the exact same `403 condominium_access_denied` — the response never reveals
  whether a condominium exists. A non-numeric `condominium_id` is cast to
  `(int) 0`, never matches a real node, and also falls into `403`.
- `id` is the `condominium_id` from the route (int); `name` is the `title` of
  the `condominio` node (filtered by `n.type = 'condominio'`). Access is
  granted by the user's units, not by the condominium node's status, so `name`
  could come from an unpublished node, or be `null` if the node was deleted —
  with access granted this should not normally happen.

**Expense aggregate (`total_expenses` / `expenses_count`)**

The aggregate is computed over the **same set** the expenses endpoint would
list: published `gastos` nodes (`type = 'gastos'`, `status = 1`) whose
`field_condominio` equals `condominium_id` and whose `field_estado_gasto` is
exactly `Activo`, plus the date range if given.

- `total_expenses` = `SUM(field_valor_value)` of that set, exposed as `float`.
  Empty set → `0.0` (never `null`).
- `expenses_count` = `COUNT` of expenses in that set, `int`. Empty set → `0`.
- The state criterion is a **single exposed value**: only expenses whose
  `field_estado_gasto` is exactly `Activo` enter the aggregate. Any other
  state, and expenses with no `field_estado_gasto` row, are excluded from both
  the sum and the count. The value is centralized in the
  `MYAPI_CONDOMINIUM_EXPENSE_STATUS` constant (its value matches the expenses
  endpoint, but the constant is kept local so the resources stay isolated).
- **`total_expenses` and `expenses_count` need not match 1:1.** An expense that
  is in the set but has **no** `field_valor` row counts in `expenses_count` yet
  contributes `0` to `total_expenses` (`field_valor` is left-joined and `SUM`
  ignores `NULL`). A client must not assume `total_expenses / expenses_count`
  is a meaningful average.

**Date-range filter (`date_from` / `date_to`)**

Both bounds are optional and independent: you may send only `date_from`, only
`date_to`, both, or neither. They narrow the aggregate on `expense_date`
inclusively on both ends.

- Comparison is made on the first 10 characters of
  `field_fecha_de_gasto_value` (`SUBSTR(..., 1, 10)`), so an expense stored as
  either `2026-07-05` or `2026-07-05T00:00:00` is **included** by
  `date_to=2026-07-05` — the time suffix never pushes the last day out of
  range.
- With at least one bound active, expenses with no `field_fecha_de_gasto` row
  are **excluded** from the aggregate — an expense without a date cannot belong
  to a date range.
- Invalid values (bad format or non-calendar dates) are ignored per bound, and
  an inverted range (`date_from > date_to`) drops the whole filter, so the
  endpoint responds exactly as if no range had been sent. No `422` is raised
  for either case. Parsing is shared with the expenses endpoint via
  `myapi_parse_date_range_param()`.

Example:
`GET /api/v1/condominiums/12/summary?date_from=2026-07-01&date_to=2026-07-31`
aggregates only expenses whose `expense_date` falls within July 2026 inclusive.

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables instead
of going through the Field API, for query simplicity. A future schema change to
any of the fields below (rename, single→multi-value, bundle move, type change)
will silently break this endpoint without a Drupal update warning.
`field_valor` and `field_saldo_caja` are single-value decimals mapped 1:1. Note
that `field_condominio` and `field_valor` are shared with other content types;
the `n.type = 'gastos'` condition on the aggregate, the `n.type = 'condominio'`
condition on `name`, and the per-join `entity_id` binding keep each query
scoped to the right nodes. `field_saldo_caja` is bound to the `condominio`
bundle and read by `entity_id = condominium_id` + `entity_type = 'node'`.

| Source | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `node.nid` (route) | `id` | int | never `NULL` |
| `node.title` (condominio) | `name` | string | `NULL` only if the node does not exist / does not resolve (should not happen with access granted) |
| `SUM(field_valor_value)` | `total_expenses` | float | never `NULL` (empty set → `0.0`) |
| `COUNT` of `Activo` expenses | `expenses_count` | int | never `NULL` (empty set → `0`) |
| `field_saldo_caja_value` | `cash_balance` | float | `NULL` if the condominium has no `field_data_field_saldo_caja` row |

| Table | Relevant columns | Use |
|---|---|---|
| `node` (condominio) | `nid`, `title`, `type` | `nid` = `condominium_id`; `title` = `name`, read with `type = 'condominio'`. |
| `node` (gastos) | `nid`, `type`, `status` | Published `gastos` nodes (`type = 'gastos'`, `status = 1`) that enter the aggregate. |
| `field_data_field_condominio` | `entity_id`, `field_condominio_target_id` | Main aggregate filter: inner join `= condominium_id`. Also used, for `vivienda` nodes, by `myapi_condominium_related_nids()` for access. |
| `field_data_field_estado_gasto` | `entity_id`, `field_estado_gasto_value` | Inner join `= 'Activo'`: only that state enters the sum and count. |
| `field_data_field_valor` | `entity_id`, `field_valor_value` | Left-joined summand of `total_expenses` and basis of `expenses_count`. |
| `field_data_field_fecha_de_gasto` | `entity_id`, `field_fecha_de_gasto_value` | Only when a bound is set: inner join with `SUBSTR(..., 1, 10) >= / <=` to narrow the aggregate. |
| `field_data_field_saldo_caja` | `entity_id`, `field_saldo_caja_value` | `cash_balance`. Single-value → at most one row. |

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 403  | `condominium_access_denied` | `condominium_id` is not accessible to the authenticated user (no unit of theirs points to it), or does not exist, or is non-numeric. All cases return the same error — the response never distinguishes them. |
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
