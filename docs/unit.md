## GET /api/v1/units

Returns, for the authenticated user, every condominium where they have at
least one unit — as owner or occupant — with that user's units nested
underneath. Read-only: no alícuota, no filters, no pagination (the expected
volume per user is a handful of units).

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "properties": [
      {
        "id": 12,
        "name": "Edificio El Sáuco",
        "units": [
          {
            "id": 45,
            "name": "Depto. 4B",
            "category": "departamento",
            "area_m2": 92.0,
            "owner_name": "Priscila Cordero"
          }
        ]
      }
    ]
  }
}
```

A user with no related units gets `{"properties": []}` with `200` (not an
error).

Notes:
- A unit is "related" to the authenticated user if they are its owner
  (`field_propietario`) or occupant, via either `field_ocupante` (legacy,
  single-value) or `field_ocupantes` (current, multi-value) — evaluated as OR,
  so data in either field is picked up.
- Only published units (`status = 1`) whose parent condominium node is also
  published (`status = 1`) are returned. A unit whose condominium is
  unpublished, or whose condominium node no longer exists, silently
  disappears from the response — there is no error or indicator for this
  case.
- `area_m2` always comes from `field_total_m2_value`, regardless of the
  unit's `category` — there is no per-category field mapping.
- `owner_name` is `null` when the unit has no `field_propietario` assigned.
  Otherwise it is `"<field_nombre> <field_apellidos>"` when both are
  non-empty, or the full `users.name` as a fallback when either is missing —
  never a partial/hybrid combination.
- `category` is `taxonomy_term_data.name` exactly as stored, with no
  slug/lowercase transformation.
- Units are grouped by condominium; each condominium appears once in
  `properties` with all of the authenticated user's units in that
  condominium nested under `units`.
- No alícuota field is included in this response — it is out of scope for
  this endpoint (see a future billing/quotas spec).

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables
instead of going through the Field API, for query simplicity. A future schema
change to any of the fields below (rename, single→multi-value, bundle move)
will silently break this endpoint without a Drupal update warning:

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `type`, `title`, `status` | `vivienda` (unit) and `condominio` (property) nodes. |
| `field_data_field_condominio` | `entity_id`, `field_condominio_target_id` | Unit → condominium relation. |
| `field_data_field_nombre_vivienda` | `entity_id`, `field_nombre_vivienda_value` | Unit name. |
| `field_data_field_categoria` | `entity_id`, `field_categoria_tid` | Unit category (taxonomy reference). |
| `taxonomy_term_data` | `tid`, `name` | Category name. |
| `field_data_field_total_m2` | `entity_id`, `field_total_m2_value` | Unit area (`area_m2`). |
| `field_data_field_propietario` | `entity_id`, `field_propietario_target_id` | Unit owner (single-value, `uid`). |
| `field_data_field_ocupante` | `entity_id`, `field_ocupante_target_id` | Legacy occupant (single-value, `uid`). |
| `field_data_field_ocupantes` | `entity_id`, `field_ocupantes_target_id` | Current occupant (multi-value, `uid`). |
| `field_data_field_nombre` | `entity_id` (uid), `field_nombre_value` | Owner's first name (`entity_type = 'user'`). |
| `field_data_field_apellidos` | `entity_id` (uid), `field_apellidos_value` | Owner's last name (`entity_type = 'user'`). |
| `users` | `uid`, `name`, `status` | `owner_name` fallback; authenticated user validation. |

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 405  | `method_not_allowed` | Any HTTP method other than GET. |

Error envelope:
```json
{
  "success": false,
  "error_code": "missing_authorization",
  "error": "No se proporcionó token de acceso."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).
