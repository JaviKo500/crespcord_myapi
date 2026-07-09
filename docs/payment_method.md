## GET /api/v1/payment-methods

Returns the full list of payment methods: the terms of the `metodos_pago`
taxonomy vocabulary, each mapped to its `id`, `name`, `type_method` (from the
`field_tipo_pago` field) and `description`. Read-only collection: no per-method
detail endpoint and no create/update/delete. The list is ordered by `id`,
ascending by default (see the `sort` query parameter).

Terms without a value in `field_tipo_pago` are **excluded** from the collection:
`type_method` is the key the app uses to register the method on a payment, so a
method without that value is not usable and is not offered.

**Authentication:** required (Bearer access token)

Any user with a valid access token may list the payment methods — there is no
per-role, per-condominium or per-unit filtering. The descriptions/types may
contain system data (e.g. account numbers), so the endpoint is not public.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Values | Default | Notes |
|-------|--------|---------|-------|
| `sort` | `asc` \| `desc` | `asc` | Sort order by `id`. `asc` = lowest id first, `desc` = highest id first. Any other value (absent, empty, uppercase `ASC`, another field name) is silently ignored and falls back to `asc` — no `422`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "payment_methods": [
      { "id": 4, "name": "Transferencia", "type_method": "Bancaria", "description": "Cuenta corriente 2100xxxxxx" },
      { "id": 7, "name": "Efectivo", "type_method": "cash", "description": "" }
    ]
  }
}
```

Each element of `payment_methods` contains exactly these 4 keys:

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The term's `tid`. Never `null`. |
| `name` | string | The term's `name`, sanitized with `check_plain()`. |
| `type_method` | string | The `field_tipo_pago` value, sanitized with `check_plain()`. Never empty — terms without this value are excluded from the list. |
| `description` | string | The term's `description`, sanitized with `check_plain()`. A term with no description returns `""` (empty string), never `null`. |

Notes:
- If the `metodos_pago` vocabulary does not exist (e.g. renamed or deleted), the
  response is `200` with `{ "payment_methods": [] }` — not a `500`. The endpoint
  never leaks configuration details.
- If the vocabulary exists but has no terms, the response is `200` with
  `{ "payment_methods": [] }`.
- A term without a `field_tipo_pago` value (absent, empty or whitespace-only) is
  excluded from the collection; it does not appear in the response. If no term
  has that value, the response is `200` with `{ "payment_methods": [] }`.
- `type_method` and `description` are escaped plain text (`check_plain()`), not
  rich HTML. Any markup stored in the term is returned escaped.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 405  | `method_not_allowed` | Any method other than `GET` (`POST`, `PUT`, `DELETE`, …). |
