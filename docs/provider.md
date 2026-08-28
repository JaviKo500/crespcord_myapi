## GET /api/v1/providers

Returns the **active** providers of the marketplace — a paginated list with the
logo, name, categories, rating, short description and hourly rate of each one. It
is what the app paints the provider directory from, and what a category tile
opens into.

Read-only collection: there is no create, update or delete over the API. A
provider is loaded, edited and suspended by the operator from the back office.

The listing is filterable by **one** category and orderable by rating or by
hourly rate in both directions. Ordered by rating, descending, by default.

A provider that operates on the platform sees **its own** providers, active or
not, through [GET /api/v1/providers/mine](provider-mine.md) — the same eight
fields as here plus two flags, and no pagination.

The address, the long description (`field_services_desc`), the tags, the full
gallery and the ratings of a provider do not travel through this listing — see
[GET /api/v1/providers/%](provider-detail.md) for the detail. The logo does
travel, because it is public and it is what the card is for: see
[The logo](#the-logo). The gallery is a route apart: see [Images](#images).

**Authentication:** required (Bearer access token)

Any user with a valid access token gets the same marketplace — there is no
per-role, per-condominium or per-unit filtering. A provider is not related to
any condominium in today's data model, so the listing is site-wide, exactly like
`/api/v1/service-categories`. There is no `403` and there is no `404`: the
collection always exists.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Values | Default | Notes |
|-------|--------|---------|-------|
| `page` | integer ≥ 1 | `1` | 1-based page number. Any other value (`0`, `-1`, `abc`, empty) silently falls back to `1` — no `422`. A page beyond the last one answers `200` with an empty list. |
| `limit` | integer 1–50 | `20` | Items per page. Above 50 it is **cut to 50**. Any invalid value falls back to `20`, and **that includes `-1`**: unlike payments, receipts and expenses, `limit=-1` does **not** return everything here. |
| `order_by` | `rating_avg` \| `hourly_rate` | `rating_avg` | The column to order by. Any other value (`title`, `created`, uppercase, empty) silently falls back to `rating_avg` — no `422`. |
| `sort` | `asc` \| `desc` | `desc` | Direction. Only the exact lowercase values count: `DESC`, `descendente` or an empty value fall back to `desc` — no `422`. |
| `category_id` | positive integer (`tid`) | *(no filter)* | Narrows the listing to the providers carrying that category. **The only parameter that can answer `422`** — see below. |

### `category_id`: the one parameter that can be a `422`

The asymmetry is deliberate. A malformed identifier is a badly built request; an
unknown value of an enumeration is merely a value this endpoint does not know.
Same split `/api/v1/bulletins` already makes with `condominium_id`.

- `?category_id=abc`, `-3`, `0`, `?category_id=` (empty) and `?category_id[]=1`
  → **`422 invalid_field`** naming `category_id`.
- `?category_id=<a tid that does not exist>`, `<a tid of another vocabulary>`,
  `<a tid with no providers>` → **`200` with `providers: []` and `total: 0`**.

Once the value *is* a positive integer there are no more judgements: the
endpoint filters, it does not validate the catalogue. Checking that the term
exists would be an extra query to answer an error where an empty list says the
same thing — and it saves the app from telling "deleted category" apart from
"category with no providers", which for it are the same screen.

The `id` to send is the one `/api/v1/service-categories` returns, so the app
filters with what it just received and translates nothing.

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "providers": [
      {
        "id": 41,
        "logo": "https://midominio.com/sites/default/files/logo-plomeria-torres.png",
        "title": "Plomería Torres",
        "categories": [
          { "id": 7, "code": "plomeria", "name": "Plomería" },
          { "id": 9, "code": "gasfiteria", "name": "Gasfitería" }
        ],
        "rating_avg": 4.75,
        "rating_count": 12,
        "short_description": "Destapes y reparaciones, atención en el día.",
        "hourly_rate": 25.5
      },
      {
        "id": 42,
        "logo": null,
        "title": "Electricidad Ríos",
        "categories": [],
        "rating_avg": null,
        "rating_count": 0,
        "short_description": "",
        "hourly_rate": null
      }
    ],
    "pagination": { "total": 37, "page": 1, "limit": 20, "total_pages": 2 }
  }
}
```

`data` carries exactly two keys, `providers` and `pagination`, and nothing else.
`providers` is always a JSON array, even with one element or none.

Each element of `providers` contains exactly these **8 keys, always all 8, in
this order**:

> **Change of shape (SPEC 85).** The item used to carry **7** keys and now
> carries **8**: `logo` was added in **second position**, right after `id`. Every
> other key keeps its type, its value and its place. Adding a key is backwards
> compatible for any reasonable parser, but a client that pins the number of
> fields must be updated **before** the deployment.

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The provider's `nid`. Never `null`. It is the id to use on `/api/v1/providers/%/gallery`. |
| `logo` | string \| **null** | Absolute URL of the provider's logo (`field_logo`). **`null`** when the provider has none — never `""` and never a broken URL — and it is **still listed**. See [The logo](#the-logo). |
| `title` | string | The node title as **plain text** (`myapi_text_to_plain()`): markup stripped, entities decoded, **not** HTML-escaped. A provider named `Luz & Cía` travels as `Luz & Cía`. Never empty (required by Drupal). |
| `categories` | array | The categories of the provider, in the operator's own order. `[]` when it has none — the provider is **still listed**. See below. |
| `rating_avg` | float \| **null** | Average rating, 1–5. **`null`** — never `0` — while nobody has rated the provider. |
| `rating_count` | int | Number of ratings. **`0`** when there are none, never `null`. |
| `short_description` | string | `field_short_description`, plain text like `title`. `""` when empty, never `null`. |
| `hourly_rate` | float \| **null** | `field_hourly_rate`. `null` when the provider publishes no rate — and it is **still listed**. No currency symbol: the app puts it. |

Each element of `categories` contains exactly **3 keys** — and none more: no
`description`, no `icon_id`, no `icon_url`. The icon is already in the app from
the categories grid, and repeating it on every provider of every page is dead
weight.

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The term's `tid`, the same `id` `/api/v1/service-categories` answers. |
| `code` | string | `field_category_code`, plain text. A term with no code answers `""` and the category is **still listed** — same as in the categories endpoint. |
| `name` | string | The term's `name`, plain text. |

### Every string travels unescaped

Every string key of this endpoint — `title`, `short_description` and both
strings of `categories` — goes through `myapi_text_to_plain()`: tags are
stripped, HTML entities are **decoded**, whitespace is collapsed. The app can
paint the value verbatim in a `Text` widget.

What the app must **not** expect is HTML escaping. `&` arrives as `&`, not as
`&amp;`; that was the previous behaviour and it was wrong — Flutter decodes no
entity, so an escaped title was painted with the entity visible. No markup
survives the helper either, so nothing is unsafe about it.

> ⚠️ Note for `/api/v1/service-categories`: that endpoint still escapes `code`
> and `name` with `check_plain()`. For a term carrying `&` in its name, the two
> endpoints answer different bytes for the same term.

### `rating_avg: null` is not zero stars

**This is the mistake the app is most likely to make.** A provider that nobody
has rated yet answers:

```json
"rating_avg": null,
"rating_count": 0
```

Painted as `0`, that provider shows up with the worst possible rating of the
marketplace on the day it joins. `null` means *not rated yet*, and it is `null`
and not `0.0` on purpose: zero is **outside** the 1–5 scale, so it would be an
impossible value travelling as if it were real.

**The key that decides is `rating_count`.** When it is `0`, paint "no ratings
yet" and no stars. That is also why both keys always travel and not just one.

And it is `rating_count` and not `rating_avg` for a second reason: a provider
may answer **`rating_avg: 0`** rather than `null`, because `field_rating_avg` is
not empty but holds a stored `0.00`. The endpoint reports what is stored — the
`null` is for a field with no value at all. Either way the reading is the same:
**`rating_count: 0` means not rated yet**, whatever `rating_avg` says.

### The counters are written since SPEC 108

**Until then, every provider of the marketplace answered `rating_avg: null` and
`rating_count: 0`**, whatever work it had done: the two fields had existed since
the schema was built and nothing had ever written them. They are real now.

What writes them is **not** an endpoint: it is a node hook that fires whenever a
`service_rating` node is created, edited or deleted, so the numbers are right
whichever door the rating came through — a resident closing their request with
[`PUT /api/v1/service-requests/{id}/close`](service-request.md), or an operator
creating, correcting or **moderating** one from the back office. Moderating a
rating means *deleting* it, and the counters follow that too.

- **`rating_count`** is the number of ratings of that provider, and a provider
  with none has a stored `0` — the API answered `0` for an empty field already,
  and now storage and API say the same thing.
- **`rating_avg`** is the average of the stars, rounded to **two decimals**, and
  it is left **empty** — not `0.00` — while there is nothing to average. An
  unrated provider does not have a zero for a mark.
- The average is **recomputed whole** on every save, never adjusted
  incrementally, so it repairs itself: whatever state the counters were left in,
  the next rating saved puts both numbers right.

### The two decimals are JSON numbers

`rating_avg` and `hourly_rate` travel as numbers, never as strings: `25.5`, not
`"25.50"`.

A **round** value prints with no decimal part — a rate stored as `25.00` travels
as `25` — which is what any JSON encoder does with a float that has nothing
after the point, and exactly what `amount` already does in payments, receipts
and expenses. In Dart that decodes as an `int`, so read it as `num` (or with
`(value as num).toDouble()`); a bare `as double` throws on a whole number.

### Ordering

Three criteria, applied in this order:

1. **Providers with no value go last, in both directions.** A listing ordered by
   price from low to high that *opened* with the providers publishing no price
   would read as a server error, not as an order. This criterion does **not**
   depend on `sort`.
2. The requested column (`order_by`), in the requested direction (`sort`).
3. `id` descending, as a tie-break: at equal rating, the newest provider first.

The tie-break is what makes pagination stable. Without it, two providers scoring
`4.50` can swap places between page 1 and page 2, so the app shows one of them
twice and never shows the other.

Only `rating_avg` and `hourly_rate` can be ordered by. Title, join date and
number of ratings cannot.

### Pagination

`pagination` carries the same four keys as every other listing of the module:

| Key | Meaning |
|-----|---------|
| `total` | Size of the **already filtered** set — with `?category_id`, the providers of that category, not the whole site. |
| `page` | The page that was served, echoed back (the value asked for, even beyond the last page). |
| `limit` | Items per page actually applied, after the 1–50 clamp. |
| `total_pages` | `ceil(total / limit)`, and **`0`** when `total` is `0` — not `1`. |

An empty marketplace answers
`{"providers": [], "pagination": {"total": 0, "page": 1, "limit": 20,
"total_pages": 0}}` with `200`, never an error.

`total` and the rows are two queries, so a provider published or expired between
them can leave `total` off by one for that single request. Same behaviour as
every other listing of the module.

### Who is an active provider

```
node.status = 1  AND  field_license_expiry >= REQUEST_TIME
```

Both halves must hold. An unpublished provider (suspended by hand by the
operator), one whose licence expired, and one with no licence on record at all
are all absent from the listing and from `total`. A licence expiring **exactly
now** still counts — the comparison is `>=`.

It is the same rule `/api/v1/service-categories?with_counts=1` counts with, and
since SPEC 83 the SQL half of it lives in one place,
`includes/myapi.provider_query.inc`, so the two can never disagree: the number
of providers this endpoint returns for a category matches the `providers_count`
of that category, always.

### The logo

`logo` is the **absolute, direct URL of the file**, the kind
`https://midominio.com/sites/default/files/logo-plomeria-torres.png`. It is
**not** an `api/v1/...` path and not a `/system/files` one: `field_logo` is
public storage, so the web server answers it and a bare Flutter
`Image.network(provider['logo'])` paints it **with no `Authorization` header at
all**. Same shape `icon_url` already has in `/api/v1/service-categories`.

That is the whole difference with the gallery below, and it is deliberate: a
logo is commercial identity — the shop window of the provider, which goes on
every card of this listing — while a gallery image is content uploaded for one
record. Catalogue and identity go public; content of a record goes private.

Three things worth knowing before painting it:

- **A provider with no logo answers `null`.** Never `""`, never `false`, never a
  missing key: there is one empty case to handle, and the app decides its own
  placeholder. The same `null` arrives when the file was deleted from disk
  outside Drupal, and the provider is **still listed** either way.
- **The image is not necessarily square.** The aspect ratio is not validated by
  express decision, so a `1000×300` reaches the app. Paint it with
  `BoxFit.contain` over a fixed-ratio box: the logo arrives whole, with margins,
  instead of cropped or stretched.
- **It is at most 1000×1000 and 2 MB**, the original file with no image style
  and no thumbnail behind it. There is no `?style=` derivative served by the API.

The logo is **read-only over the API**, like everything else in the marketplace:
it is uploaded, replaced and removed by the operator from the back office.

### Images

The gallery of a provider does **not** travel through this listing. Its images
are private, so every one of them is an authenticated request through PHP, and a
page of twenty providers would be twenty Drupal bootstraps just for the
thumbnails — which is exactly the cost the public logo avoids.

The app asks for them when it opens a provider's card:

```
GET /api/v1/providers/{id}/gallery
```

See [provider-gallery.md](provider-gallery.md).

**Notes**
- `categories` follows the **delta order** of the field — the order the operator
  dragged the categories into in the form — never alphabetical.
- A provider whose category was deleted in the back office keeps the rest: the
  orphan category is omitted from its `categories` and the provider is answered
  the same. It never disappears from the marketplace because of a deleted term.
- A provider with **no** logo, **no** rating, **no** rate, **no** description and
  **no** category is listed all the same, with the five empty values. Nothing
  optional filters anybody out.
- The logo costs **no extra query**: it travels in the same row of the page, and
  the pagination is exactly the one it would be without it. There is no way to
  filter or order the listing by "has a logo".
- Filtering by category never duplicates a provider, not even one carrying the
  same category twice or working in three categories.
- The request costs **four queries** whatever the page size: the token, the
  count, the page and the categories of the whole page — never one query per
  provider.
- The queries read the `field_data_*` tables directly instead of loading the
  nodes, which is the trade-off the whole module already accepts: it assumes the
  current field storage of Drupal 7.
- There is no cache: no `ETag`, no `304`. Every request is answered fresh.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 422  | `invalid_field` | `category_id` is present and is not a positive integer (`abc`, `-3`, `0`, empty, an array). The message names the field. |
| 405  | `method_not_allowed` | Any method other than `GET` (`POST`, `PUT`, `DELETE`, …). Answered **before** authentication: a `POST` with no token is `405`, not `401`. |
