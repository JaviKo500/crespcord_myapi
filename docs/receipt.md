## GET /api/v1/units/{unit_id}/receipts

Returns a paginated list of `recibo` nodes (exposed as `receipts`) belonging
to `unit_id`, visible only to the authenticated user who is the owner or
occupant of that unit — same access rule as `GET /api/v1/units`. Read-only:
no create/update/delete, no single-receipt detail endpoint.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. Special value `-1` disables pagination entirely: every matching receipt is returned in one response, `page` is forced to `1`, and `total_pages` is `1` (or `0` when `total` is `0`). |
| `sort` | `desc` | `asc` or `desc`, applied to `period_start` (`field_periodo_value`). Any other value falls back to `desc`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, keeps only receipts with `period_start >= date_from`. Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, keeps only receipts with `period_start <= date_to`. Same silent-ignore rule as `date_from`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "receipts": [
      {
        "id": 501,
        "title": "Recibo junio 2026",
        "unit_id": 45,
        "period_start": "2026-06-01",
        "period_end": "2026-06-30",
        "status": "Enviado",
        "gas_previous_reading": 120.5,
        "gas_current_reading": 135.2,
        "gas_consumption": 14.7,
        "water_previous_reading": 210.0,
        "water_current_reading": 224.3,
        "water_consumption": 14.3,
        "hot_water_previous_reading": 80.0,
        "hot_water_current_reading": 86.5,
        "hot_water_consumption": 6.5,
        "water_heating": 5.0,
        "gym": 10.0,
        "jacuzzi_sauna": 8.0,
        "extra": 0.0,
        "extra_fee": 0.0,
        "internet": 15.0,
        "electricity": 22.0,
        "preheating": 3.0,
        "fee": 120.0,
        "storage_fee": 12.0,
        "parking_fee": 18.0,
        "terrace_fee": 0.0,
        "office_fee": 0.0,
        "commercial_unit_fee": 0.0,
        "total_fee": 150.0,
        "insurance": 5.0,
        "penalty_amount": 0.0,
        "monthly_total": 187.32,
        "previous_balance": -3393.0,
        "total": 187.32,
        "observation": null,
        "late_payment_message": null,
        "gas_fixed_rate": 2.5,
        "water_fixed_rate": 1.8,
        "hot_water_fixed_rate": 3.0
      }
    ],
    "pagination": {
      "total": 12,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

A unit with no receipts gets `{"receipts": [], "pagination": {"total": 0,
"page": 1, "limit": 20, "total_pages": 0}}` with `200` (not an error).
Requesting a page beyond the last one also returns `200` with `receipts: []`
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
- Only published (`status = 1`) `recibo` nodes are returned.
- Only receipts whose `field_estado` state is `Enviado` are exposed. Receipts
  in any other state, and receipts with no `field_estado` row at all, are
  silently excluded from both the `receipts` list and the `pagination.total`
  count — they behave as if they did not exist for this endpoint. Because of
  this filter, `status` in every returned item is always `"Enviado"`.
- Every receipt includes exactly 40 keys: `id`, `title`, `unit_id`, and 37
  data fields (see mapping table below). A field is `null` when the node has
  no row in that field's storage table — no other transformation or business
  validation is applied (e.g. `status` is the raw stored text, not validated
  against a fixed list; `period_start`/`period_end` are raw strings, not
  reformatted).
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **filtered** set (after `date_from`/`date_to` are applied, if any), not the
  unit's full receipt count. `total_pages` is `0` when `total` is `0`.
- Sorting is always by `period_start` (`field_periodo_value`); there is no
  other sort field.

**Date-range filter (`date_from` / `date_to`)**

Both bounds are optional and independent: you may send only `date_from`, only
`date_to`, both, or neither. They filter on `period_start` only (never on
`period_end`), inclusively on both ends.

- Comparison is made on the first 10 characters of `field_periodo_value`
  (`SUBSTR(..., 1, 10)`), so a receipt stored as either `2026-06-30` or
  `2026-06-30T00:00:00` is **included** by `date_to=2026-06-30` — the time
  suffix never pushes the last day out of range.
- The filter is applied **before** pagination and sorting, so `page`, `limit`
  and `sort` operate over the already-filtered set.
- Receipts with no `field_periodo` row (`period_start = null`) are **excluded**
  whenever at least one bound is active — a receipt without a period cannot
  belong to a date range.
- Invalid values (bad format or non-calendar dates) are ignored per bound, and
  an inverted range (`date_from > date_to`) drops the whole filter, so the
  endpoint responds exactly as if no range had been sent. No `422` is raised
  for either case — this mirrors the lax handling of `page`/`limit`/`sort`.

Example: `GET /api/v1/units/45/receipts?date_from=2026-06-01&date_to=2026-06-30`
returns only receipts whose `period_start` falls within June 2026 inclusive.

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables
instead of going through the Field API, for query simplicity. A future schema
change to any of the fields below (rename, single→multi-value, bundle move)
will silently break this endpoint without a Drupal update warning. All
decimal fields are single-value and mapped 1:1.

| Drupal field | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `nid` | `id` | int | never `NULL` |
| `title` | `title` | string | never `NULL` |
| `field_vivienda_target_id` | `unit_id` | int | never `NULL` (it is the query filter) |
| `field_periodo_value` | `period_start` | string | `NULL` if no row |
| `field_periodo_value2` | `period_end` | string | `NULL` if no row |
| `field_estado_value` | `status` | string | always `"Enviado"` (endpoint filter) |
| `field_gas_lectura_anterior_value` | `gas_previous_reading` | float | `NULL` if no row |
| `field_gas_lectura_actual_value` | `gas_current_reading` | float | `NULL` if no row |
| `field_consumo_gas_value` | `gas_consumption` | float | `NULL` if no row |
| `field_agua_lectura_anterior_value` | `water_previous_reading` | float | `NULL` if no row |
| `field_agua_lectura_actual_value` | `water_current_reading` | float | `NULL` if no row |
| `field_consumo_agua_value` | `water_consumption` | float | `NULL` if no row |
| `field_agua_caliente_lectura_ante_value` | `hot_water_previous_reading` | float | `NULL` if no row |
| `field_agua_caliente_lectura_actu_value` | `hot_water_current_reading` | float | `NULL` if no row |
| `field_consumo_agua_caliente_value` | `hot_water_consumption` | float | `NULL` if no row |
| `field_calentamiento_agua_value` | `water_heating` | float | `NULL` if no row |
| `field_gimnasio_value` | `gym` | float | `NULL` if no row |
| `field_jacuzzi_sauna_turco_value` | `jacuzzi_sauna` | float | `NULL` if no row |
| `field_extra_value` | `extra` | float | `NULL` if no row |
| `field_alicuota_extra_value` | `extra_fee` | float | `NULL` if no row |
| `field_internet_value` | `internet` | float | `NULL` if no row |
| `field_energia_electrica_value` | `electricity` | float | `NULL` if no row |
| `field_precalentamiento_value` | `preheating` | float | `NULL` if no row |
| `field_alicuota_value` | `fee` | float | `NULL` if no row |
| `field_alicuota_bodega_value` | `storage_fee` | float | `NULL` if no row |
| `field_alicuota_parqueadero_value` | `parking_fee` | float | `NULL` if no row |
| `field_alicuota_terraza_value` | `terrace_fee` | float | `NULL` if no row |
| `field_alicuota_oficina_value` | `office_fee` | float | `NULL` if no row |
| `field_alicuota_local_comercial_value` | `commercial_unit_fee` | float | `NULL` if no row |
| `field_alicuota_total_value` | `total_fee` | float | `NULL` if no row |
| `field_seguro_value` | `insurance` | float | `NULL` if no row |
| `field_valor_penalizacion_value` | `penalty_amount` | float | `NULL` if no row |
| `field_total_mes_value` | `monthly_total` | float | `NULL` if no row |
| `field_saldo_anterior_value` | `previous_balance` | float | `NULL` if no row |
| `field_total_value` | `total` | float | `NULL` if no row |
| `field_observacion_value` | `observation` | string | `NULL` if no row |
| `field_mensaje_demora_value` | `late_payment_message` | string | `NULL` if no row |
| `field_tarifa_fija_gas_value` | `gas_fixed_rate` | float | `NULL` if no row |
| `field_tarifa_fija_agua_value` | `water_fixed_rate` | float | `NULL` if no row |
| `field_tarifa_fija_agua_caliente_value` | `hot_water_fixed_rate` | float | `NULL` if no row |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status` | `recibo` nodes. |
| `field_data_field_vivienda` | `entity_id`, `field_vivienda_target_id` | Receipt → unit relation (`unit_id`). Main filter of the endpoint. |
| `field_data_field_periodo` | `entity_id`, `field_periodo_value`, `field_periodo_value2` | Receipt period (`period_start`/`period_end`). Default sort column. |
| `field_data_field_estado` | `entity_id`, `field_estado_value` | `status`. Inner join filtered to `Enviado`; other states are excluded from the endpoint. |
| `field_data_field_observacion` | `entity_id`, `field_observacion_value` | `observation`, free text (`_format` column ignored). |
| `field_data_field_mensaje_demora` | `entity_id`, `field_mensaje_demora_value` | `late_payment_message`, free text (`_format` column ignored). |
| Remaining `field_data_field_*` tables (30 tables) | `entity_id`, `field_*_value` | All `decimal`, mapped 1:1 per the table above. |

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
