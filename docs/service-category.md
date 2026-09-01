## GET /api/v1/service-categories

Returns the full list of service categories: the terms of the
`service_category` taxonomy vocabulary, each mapped to its `id`, `code`,
`name`, `description`, icon and — with `?with_counts=1` — the number of active
providers. It is the catalogue the marketplace grid is
painted from. Read-only collection: no per-category detail endpoint and no
create/update/delete — categories are loaded by the operator from
`admin/structure/taxonomy/service_category`. The list is ordered alphabetically
by `name`, ascending by default (see the `sort` query parameter).

Pagination is **opt-in** (SPEC 118): without `?page` and without `?limit` the
whole catalogue is returned and the body carries no `pagination` key at all,
exactly as the endpoint shipped. `?search=` filters by name or description
and, when it carries text, **turns pagination off** (SPEC 119).

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
| `page` | integer >= 1 | `1` | 1-based page number. Any other value (`0`, `-1`, `abc`, empty, an array) silently falls back to `1` — no `422`. A page beyond the last one answers `200` with an empty list. |
| `limit` | integer 1-50 | `20` | Items per page. Above 50 it is **cut to 50**. Any invalid value falls back to `20`, and `-1` is **not** "everything" — not asking for a page already is. |
| `search` | text | *(off)* | Filters by `name` **or** `description`, case- and accent-insensitively. An empty value, spaces only, or an array means **no search**. A search answers every match with no `pagination` block, ignoring `page` and `limit`. No `422`. |
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

### `page`, `limit` and the `pagination` block

**What turns pagination on is the presence of either parameter, not its
validity.** `?page=abc` answers page 1 of 20 with its block; it does not fall
back to the whole catalogue. A client that asked for a page always gets a page,
so its parser never has to branch.

```
GET /api/v1/service-categories?page=2&limit=20
```

```json
{
  "success": true,
  "data": {
    "service_categories": [ "... 20 items ..." ],
    "pagination": { "total": 64, "page": 2, "limit": 20, "total_pages": 4 }
  }
}
```

| Field | Notes |
|-------|-------|
| `total` | Size of the **whole** catalogue, never of the page. |
| `page` | The page that was served, echoed back — the value asked for, even beyond the last page. It is never rewritten to the last page. |
| `limit` | Items per page actually applied, after the 1-50 cut. |
| `total_pages` | `ceil(total / limit)`, and **`0`** when `total` is `0` — not `1`. |

- The slice is taken **after** the ordering, so page 2 is the second
  alphabetical page and not the second chunk of the term order. With
  `?sort=desc` it is the second page of the reversed catalogue.
- The two degraded cases (vocabulary missing, vocabulary empty) also carry the
  block when the request opted in: `{"service_categories": [], "pagination":
  {"total": 0, "page": 1, "limit": 20, "total_pages": 0}}`.
- **`?search` overrules both**: a request carrying a non-blank `search` is
  answered whole, with no slice and no `pagination` block, whatever `page` and
  `limit` say (SPEC 119).
- Pagination saves **payload, not queries**: the endpoint loads and sorts the
  whole vocabulary in PHP either way. The one thing it does make cheaper is
  `?with_counts=1` — see below.

### `search`

```
GET /api/v1/service-categories?search=plomeria
```

```json
{ "success": true, "data": { "service_categories": [ { "id": 30, "code": "plomeria", "name": "Plomería", "…": "…" } ] } }
```

- **Accent- and case-insensitive both ways**: `plomeria`, `PLOMERIA` and
  `Plomería` all find "Plomería", which matters because every `code` is
  unaccented and so is the keyboard of a resident in a hurry.
- Matches a **substring** anywhere, not a prefix or a whole word: `eria` finds
  Plomería, Jardinería and Cerrajería. Matches come back in the usual
  alphabetical order — there is no relevance ranking.
- Searches `name` and `description` only. **`code` is not searched**: it is the
  app's icon key, not a label, and the folding already covers the accent case
  that made it tempting.
- The description is matched **as it is answered** (flattened to plain text),
  so a word that only exists in the stored markup never produces a hit.
- **A search ignores `page` and `limit` entirely**, and the response carries no
  `pagination` key — its absence is how the app knows it is holding every
  match, not the first page of them.
- An empty or blank `search` is **not** a search: the whole catalogue comes
  back and `?page`/`?limit` apply as usual, so the app can clear its search box
  without rewriting the URL.
- No match is `200` with `{"service_categories": []}`, never a `404`.
- With `?with_counts=1`, the count query covers the matches alone (still one
  grouped query); a search with no match runs no count query at all.

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
- The counts cost **one** grouped query, never one per category. Without
  pagination it covers the whole catalogue; with `?page`/`?limit` it covers the
  page being answered, and a page beyond the last one runs no count query at
  all.

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
