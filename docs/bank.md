## GET /api/v1/banks

Returns the full list of banks: the terms of the `bancos` taxonomy vocabulary,
each mapped to its `id`, `name` and `description`. Read-only collection: no
per-bank detail endpoint and no create/update/delete. The list is returned in
`taxonomy_get_tree()` order (weight, then name).

**Authentication:** required (Bearer access token)

Any user with a valid access token may list the banks — there is no per-role,
per-condominium or per-unit filtering. The bank descriptions may contain system
data (e.g. account numbers), so the endpoint is not public.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "banks": [
      { "id": 3, "name": "Banco Pichincha", "description": "Cuenta corriente 2100xxxxxx" },
      { "id": 5, "name": "Produbanco", "description": "" }
    ]
  }
}
```

Each element of `banks` contains exactly these 3 keys:

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The term's `tid`. Never `null`. |
| `name` | string | The term's `name`, sanitized with `check_plain()`. |
| `description` | string | The term's `description`, sanitized with `check_plain()`. A term with no description returns `""` (empty string), never `null`. |

Notes:
- If the `bancos` vocabulary does not exist (e.g. renamed or deleted), the
  response is `200` with `{ "banks": [] }` — not a `500`. The endpoint never
  leaks configuration details.
- If the vocabulary exists but has no terms, the response is `200` with
  `{ "banks": [] }`.
- `description` is escaped plain text (`check_plain()`), not rich HTML. Any
  markup stored in the term is returned escaped.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 405  | `method_not_allowed` | Any method other than `GET` (`POST`, `PUT`, `DELETE`, …). |
