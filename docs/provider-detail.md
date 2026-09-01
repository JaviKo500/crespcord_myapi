## GET /api/v1/providers/%

One provider's full ficha (SPEC 84): the eight fields of the marketplace
listing ([provider.md](provider.md)) plus the address, the long description,
the tags, the full gallery and the last three ratings with the whole
history's summary grouped by star.

Read-only: there is no create, update or delete over the API. A provider is
loaded, edited and suspended by the operator from the back office (SPEC
77/78), and a rating is written from the back office too — nothing in the app
creates one yet.

**Authentication:** required (Bearer access token)

Same rule as the listing and the gallery: any user with a valid token gets
the same ficha — no per-role, per-condominium or per-unit filtering. A
provider is not related to any condominium in today's data model.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters:** none. The route accepts no filter, no `?include=` and
no pagination of its own.

**Success response (200)**

`data` is the provider **directly** — unlike the listing, there is no
`provider` wrapper and no `pagination`: this is one resource, not a
collection, and there is nothing else to wrap it with.

```json
{
  "success": true,
  "data": {
    "id": 41,
    "logo": "https://midominio.com/sites/default/files/logo-plomeria-torres.png",
    "title": "Plomería Torres",
    "categories": [
      { "id": 7, "code": "plomeria", "name": "Plomería" }
    ],
    "rating_avg": 4.9,
    "rating_count": 88,
    "short_description": "Destapes y reparaciones, atención en el día.",
    "hourly_rate": 25.5,
    "address": "Av. Siempre Viva 123, local 4",
    "description": "Instalaciones eléctricas residenciales, tableros, iluminación y diagnóstico de cortocircuitos.",
    "tags": ["urgencias", "24h", "certificado"],
    "gallery": [
      { "id": 42, "url": "https://midominio.com/api/v1/providers/41/gallery/42", "filename": "taller-01.jpg" }
    ],
    "ratings": [
      {
        "stars": 5,
        "comment": "Cambió el tablero de mi depto sin dejar rastro. Excelente.",
        "author_name": "Andrés M.",
        "unit": "4B",
        "created": "2026-06-12T18:04:00"
      }
    ],
    "rating_summary": { "1": 1, "2": 2, "3": 3, "4": 12, "5": 70 }
  }
}
```

`data` carries exactly **14 keys, always all 14, in this order**:

> **Change of shape (SPEC 85).** `data` used to carry **13** keys and now
> carries **14**: `logo` was added in **second position**, right after `id`, the
> same place it occupies in the listing. The other thirteen keep their type,
> their value and their order. A client that pins the number of fields must be
> updated **before** the deployment.

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The provider's `nid`. Never `null`. |
| `logo` | string \| **null** | Absolute URL of the provider's logo (`field_logo`), **identical** to the one the listing answers for this provider. `null` when it has none. It is a **direct, public** URL and needs **no `Authorization` header** — unlike every `url` of `gallery` below. See [The logo](provider.md#the-logo) in provider.md. |
| `title` | string | Plain text, unescaped. Never empty. |
| `categories` | array | Same shape and rule as the listing. `[]` when it has none. |
| `rating_avg` | float \| **null** | See [rating_avg: null is not zero stars](provider.md#rating_avg-null-is-not-zero-stars) in provider.md — the same value, the same rule, not repeated here. |
| `rating_count` | int | `0` when there are none, never `null`. |
| `short_description` | string | Plain text, unescaped. `""` when empty, never `null`. |
| `hourly_rate` | float \| **null** | `null` when the provider publishes no rate. |
| `address` | string | `field_address`, flattened with `myapi_text_to_plain()` — markup stripped, entities decoded, no escaping. `""` when empty, never `null`. |
| `description` | string | `field_services_desc`, flattened with `myapi_text_to_multiline()` and **not** with the helper `address` uses: markup stripped, entities decoded, **line breaks kept**, because this one is written in paragraphs. `""` when empty, never `null` — even though the field is required in the back-office form, the endpoint does not assume it is filled. |
| `tags` | array of string | `field_tags`, plain text, in delta order. `[]` when the provider has none. |
| `gallery` | array | Exactly the same items, same order, as [GET /api/v1/providers/%/gallery](provider-gallery.md) for this provider — the two routes share the query. |
| `ratings` | array | The last **3** ratings, most recent `created` first. `[]` when the provider has none. |
| `rating_summary` | object | Count of the **whole** rating history grouped by star, five keys always present. See below. |

The first eight are the same keys the listing answers, with the same types
and the same empty-value rules — not repeated here in detail to avoid two
sources of the same truth. See [provider.md](provider.md).

They are not merely documented as equal: the two routes build them with the
**same function**, so for a given provider the `logo` of this ficha is the very
same string the listing answers, and the two can never diverge.

Two URLs of very different natures travel in this response, and mixing them up
is the mistake worth avoiding: `logo` is served by the **web server** and needs
no header, while every `url` of `gallery` is served by **this module** and needs
the same `Authorization` header as the request that brought them. The logo is
public because it is commercial identity; the gallery is private because it is
content uploaded for one record.

**Every string of this response is plain text and travels unescaped** —
`title`, `short_description`, `address`, `description`, the `tags`, both
strings of each `categories` item and `comment` / `author_name` / `unit` of
each rating. All of them are flattened: markup stripped, entities decoded, `&`
arriving as `&` and never as `&amp;`. Two helpers do it, and the difference is
only about **line breaks**: `description` and the `comment` of a rating go
through `myapi_text_to_multiline()`, which keeps them because they are written
in paragraphs; everything else goes through `myapi_text_to_plain()`, which
collapses all whitespace into single spaces because those values are one line
by nature.
See [Every string travels unescaped](provider.md#every-string-travels-unescaped)
in provider.md for the why and for the one endpoint that still differs.

### Each item of `ratings`

| Field | Type | Notes |
|-------|------|-------|
| `stars` | int | `1`–`5`. |
| `comment` | string | `myapi_text_to_multiline()`: markup stripped, entities decoded, **line breaks kept** — a resident who wrote three paragraphs gets three paragraphs. `""` when the rating carries none — the field is optional. |
| `author_name` | string | **Abbreviated** — `"Andrés M."`, first name plus the initial of the last name — resolved from the same profile pair `myapi_claim_notification.inc` uses for `requester_name`, with three fallback levels: the profile → the account's username → `"Usuario eliminado"` for a deleted account. |
| `unit` | string \| **null** | The **name** (`field_nombre_vivienda`) of the `vivienda` node of `field_unit` — the same value `GET /api/v1/units` answers as `name`, **not** the node title. `null` when the rating carries no `field_unit`, and also `null` when that unit has no name recorded or no longer exists: the title is never used as a fallback. Since SPEC 108 **every rating written from the app carries it**: closing a request records the unit the service went to. Ratings created by hand from the back office may still leave it empty. |
| `created` | string | `Y-m-d\TH:i:s`, same format as `claim.resource.inc` and `reservation.resource.inc`. |

`author_name` is deliberately **abbreviated** and not the full name reclamos
travel: the marketplace is site-wide, so a rating is readable by any user
with a valid token, from any condominium — not just the author's own, the
way a claim is. The full name would leave the marketplace's scope in a way
this spec's decision table treats as a real, accepted trade-off.

### `rating_summary`

A fixed object of five keys, `"1"` to `"5"`, **always all five present**,
`0` where the provider has no ratings of that star — never a subset. It is
the `COUNT(*) GROUP BY field_stars` of the provider's **entire** rating
history, not just the three that travel in `ratings`: the sum of the five
values always equals `rating_count`.

A provider with no ratings answers:

```json
"ratings": [],
"rating_summary": { "1": 0, "2": 0, "3": 0, "4": 0, "5": 0 }
```

**That used to be every provider of the marketplace.** These two keys have been
served since SPEC 84 and answered `[]` and five zeros ever since, for one
reason: no rating existed anywhere on the site. Since SPEC 108 they carry real
data, and **not one line of this endpoint changed** — what arrived was something
for it to read. The flow that creates a rating is
[`PUT /api/v1/service-requests/{id}/close`](service-request.md): the resident
closes their own request and rates the provider that did the job.

The equality above — *the sum of the five values always equals `rating_count`* —
holds because the counter is computed by the **same conditions** as this
summary, deliberately. Both count ratings whatever the state of their node,
because moderating a rating means **deleting** it, not unpublishing it.

### The "published" rule, not the "active" one

The detail only requires the node to be **published** (`status = 1`) — it
does **not** require the licence to be current, unlike the listing
([Who is an active provider](provider.md#who-is-an-active-provider)). A
provider whose `field_license_expiry` is in the past still answers `200`
here, with the same data — its `logo` included — the same asymmetry
[provider-gallery.md](provider-gallery.md) already documents for its images:
a lapsed provider disappears from the marketplace listing, but a ficha
already open, and its gallery, keep serving. A provider with **no**
`field_license_expiry` at all answers `200` the same way.

**Notes**
- Nothing in this endpoint writes: the provider is edited from the back office,
  and a rating is written either by a resident closing a request
  ([`PUT /api/v1/service-requests/{id}/close`](service-request.md), SPEC 108) or
  by an operator from the back office. On a freshly installed site, with no
  request ever closed, `ratings` and `rating_summary` still answer empty.
- `tags` follows the same delta order as `categories`; a tag whose term was
  deleted in the back office is omitted in silence, same criterion as an
  orphan category.
- `gallery` is built by the exact function the gallery listing uses
  (`myapi_provider_gallery_images()`, extracted for this spec), so the two
  routes can never answer a different set of images for the same provider.
- The request costs **six queries**: the provider row, the categories, the
  tags, the gallery, the last three ratings and the rating summary — plus, at
  most, one `user_load()` and one `node_load()` per rating shown (capped at
  3, so this never grows with the size of the marketplace).
- There is no cache: no `ETag`, no `304`. Every request is answered fresh.
- No filter by condominium, role or unit: every valid token sees the same
  ficha, same criterion as the listing and the gallery.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or its user blocked or deleted. |
| 404 | `provider_not_found` | The `id` does not exist, is not of type `provider`, is unpublished, or is not a positive integer. |
| 405 | `method_not_allowed` | Any method other than `GET`. Answered **before** authentication. |

There is no `422`: the route accepts no query parameters.
