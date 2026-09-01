## GET /api/v1/service-categories

Returns the full list of service categories: the terms of the
`service_category` taxonomy vocabulary, each mapped to its `id`, `code`,
`name`, `description`, icon and — with `?with_counts=1` — the number of active
providers. It is the catalogue the marketplace grid is
painted from. Read-only collection: no per-category detail endpoint and no
create/update/delete — categories are loaded by the operator from
`admin/structure/taxonomy/service_category`. The list is ordered alphabetically
by `name`, ascending by default (see the `sort` query parameter).

The whole catalogue is returned on every call: there is no pagination and no
filtering.

**Authentication:** required (Bearer access token)

Any user with a valid access token may list the categories — there is no
per-role, per-condominium or per-unit filtering. A plain resident gets exactly
the same list as a building admin or a provider.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Values | Default | Notes |
|-------|--------|---------|-------|
| `sort` | `asc` \| `desc` | `asc` | Sort order by `name` (case-insensitive). `asc` = A→Z, `desc` = Z→A. Any other value (absent, empty, uppercase `ASC`, another field name) is silently ignored and falls back to `asc` — no `422`. |
| `with_counts` | `1` | *(off)* | Adds `providers_count` to every item. Only the exact value `1` turns it on: `0`, `true`, `yes`, an empty value or anything else answers the 6-key response with a `200` — no `422`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "service_categories": [
      {
        "id": 3,
        "code": "plumbing",
        "name": "Plomería",
        "description": "Instalación y reparación de tuberías.",
        "icon_id": 42,
        "icon_url": "https://midominio.com/sites/default/files/category-icons/plumbing.png"
      },
      {
        "id": 5,
        "code": "electricity",
        "name": "Electricidad",
        "description": "",
        "icon_id": null,
        "icon_url": null
      }
    ]
  }
}
```

Each element of `service_categories` contains exactly these 6 keys, always all
6, in this order:

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The term's `tid`. Never `null`. Use it to reference the category when creating a service request. |
| `code` | string | `field_category_code`, sanitized with `check_plain()`. A term whose code is empty returns `""` and is **still listed** — it is not dropped from the catalogue. |
| `name` | string | The term's `name`, sanitized with `check_plain()`. |
| `description` | string | The term's `description`, flattened to **plain text** (see the note below). A term with no description returns `""` (empty string), never `null`. |
| `icon_id` | int \| null | The `fid` of the image in `field_category_icon`, or `null` when the category has no icon. |
| `icon_url` | string \| null | Absolute URL of the original image file, or `null` when the category has no icon. |

Plus a seventh key, **only** when the request carries `?with_counts=1`:

| Field | Type | Notes |
|-------|------|-------|
| `providers_count` | int | Number of **active** providers in that category. Never `null`. A category with no provider answers `0` and is still listed. |

### `providers_count` and `?with_counts=1`

```
GET /api/v1/service-categories?with_counts=1
```

```json
{
  "success": true,
  "data": {
    "service_categories": [
      {
        "id": 3,
        "code": "plumbing",
        "name": "Plomería",
        "description": "Instalación y reparación de tuberías.",
        "icon_id": 42,
        "icon_url": "https://midominio.com/sites/default/files/category-icons/plumbing.png",
        "providers_count": 4
      },
      {
        "id": 5,
        "code": "electricity",
        "name": "Electricidad",
        "description": "",
        "icon_id": null,
        "icon_url": null,
        "providers_count": 0
      }
    ]
  }
}
```

Rules:

- **Without the parameter the key does not exist.** It is not `null` and it is
  not `0` — it is absent, and the item has 6 keys. The count is opt-in because
  the grid is painted far more often than the number is needed.
- `providers_count` is always the **last** key: the 6 above never change
  position.
- The count is **global to the site**, not per condominium: it does not depend
  on which building the caller lives in.
- Asking for counts does **not** change the ordering. The list is still
  alphabetical by `name`; it is not sorted by number of providers.
- The value is a snapshot of the instant: a provider may expire between this
  response and the moment the user opens the category.

**What counts as an active provider:** a `provider` node that is **published**
and whose `field_license_expiry` is **not in the past** (a licence expiring
exactly now still counts). An unpublished provider, or one with an expired
licence, is not counted — it cannot appear in the marketplace nor make an
offer, so counting it would promise somebody the resident cannot reach.

A provider working in several categories counts once in each of them.

### `id` vs `code`: which one is stable

Both identify the category, and they are not interchangeable:

- **`id`** is the Drupal term id. Use it to reference the category in a
  request to the API. It is **not** stable across a reimport of the
  vocabulary — a term deleted and recreated gets a new `tid`.
- **`code`** is a stable, human-readable key the operator sets by hand
  (`plumbing`, `electricity`, …). Any client-side logic per category — a local
  icon, a special screen, a colour — must hang from `code`, never from `id`.

### `icon_id` and `icon_url`

They are **both `null` or both filled**, never one without the other: check one
and you know about the other. A term whose image value is incomplete in the
database (a `fid` with no file, or the other way round) is answered as having
no icon at all.

`icon_url` points to the **original** file, with no image style applied — there
are no thumbnails yet. The file lives in the public filesystem, so the URL
opens with no Drupal session: it can be handed straight to an image widget.

If the file has been deleted from disk while the term still carries its `fid`,
this endpoint still answers `200` and the URL simply returns a 404 when
fetched. The failure is a broken image in the grid, never an API error.

### `description` is plain text, and it is NOT escaped

This is a **deliberate difference** with `/api/v1/banks` and
`/api/v1/payment-methods`, whose `description` is escaped with `check_plain()`.
Here the value is flattened by `myapi_text_to_plain()`:

1. HTML tags are removed (`<p>`, `<br>`, `<strong>`, …).
2. HTML entities are decoded (`&nbsp;` → space, `&amp;` → `&`, `&lt;` → `<`).
3. Every run of whitespace — newlines, tabs and the no-break space `&nbsp;`
   leaves behind — is collapsed to a single space.
4. The result is trimmed.

So a term description stored as `<p>Hola <strong>mundo</strong></p>&nbsp;` is
answered as `"Hola mundo"`: no tags, no entities, no leftover whitespace, and
**no escaping** — an `&` travels as `&`, not as `&amp;`.

The order of steps 1 and 2 matters: a stored `&lt;b&gt;` is text the operator
actually typed, so it comes back as the literal `<b>` instead of being deleted
as if it were markup.

**Consumer contract:** the value is plain text meant for a Flutter `Text`
widget, which renders it verbatim. It is **not** HTML and must not be rendered
as HTML in a WebView.

The rule for future endpoints of the module: a long text field edited with a
rich editor goes through `myapi_text_to_plain()`; a single-line field (a name,
a code) goes through `check_plain()`.

### Ordering

Ordering is alphabetical by `name` and case-insensitive (`strcasecmp()`), so
`"electricidad"` comes before `"Plomería"`. It does **not** follow the weight
the operator gave the terms in `admin/structure/taxonomy`.

Known limitation, shared with `/api/v1/banks`: the comparison is byte-based, so
a name **starting** with an accented vowel ("Áreas verdes") sorts after Z.

**Notes**
- If the `service_category` vocabulary does not exist (e.g. never created,
  renamed or deleted), the response is `200` with
  `{ "service_categories": [] }` — not a `404` and not a `500`. The endpoint
  never leaks configuration details of the site.
- If the vocabulary exists but has no terms, the response is `200` with
  `{ "service_categories": [] }`. Both degraded cases answer identical bytes,
  so the app has a single empty state.
- `service_categories` is always a JSON array, even with one element or none.
- With `?with_counts=1` and an empty (or missing) vocabulary, the response is
  the same empty list and no count query is run.
- The counts cost **one** grouped query for the whole catalogue, not one per
  category.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 405  | `method_not_allowed` | Any method other than `GET` (`POST`, `PUT`, `DELETE`, …). |

---

## Loading the catalogue (`scripts/seed-service-categories.php`)

The vocabulary is created empty by the installer (SPEC 77 keeps the terms out
of scope), so the catalogue is loaded with a one-off maintenance script rather
than by hand, term by term:

```bash
# Dry run — prints what it would create, writes nothing.
MYAPI_SEED_DRY_RUN=1 drush php-script scripts/seed-service-categories.php

# Real run.
drush php-script scripts/seed-service-categories.php
```

The script is **idempotent by `field_category_code`**: a code already present
in the vocabulary is skipped and its term is never touched — name, description
and icon may have been adjusted on the site. Adding a row to the `$catalogue`
array and re-running creates only that row.

Terms are created **without an icon**, so they answer `icon_id: null` and
`icon_url: null` until the operator uploads the image from
`admin/structure/taxonomy/service_category`. The app is expected to key its own
local icon map on `code` and use `icon_url` only when it is present.

`code` is the contract with the app and is immutable once published: renaming
the category changes `name`, never `code`. The `tid` is not a substitute — it
changes if the vocabulary is re-imported.

The script is not part of the module (`scripts/` is not in `myapi.info` and is
not copied by `scripts/deploy.sh`); copy it to the server to run it:

```bash
scp -i ~/.ssh/crespcord.pem scripts/seed-service-categories.php \
  ubuntu@crespcord.lamotora.com:~/
ssh -i ~/.ssh/crespcord.pem ubuntu@crespcord.lamotora.com \
  "cd /var/www/html && sudo -u www-data drush php-script ~/seed-service-categories.php"
```
