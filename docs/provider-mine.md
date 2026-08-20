## GET /api/v1/providers/mine

The providers **the account itself operates** (SPEC 97): the nodes of type
`provider` the token's user is linked to through `field_provider_users`,
**active or not**.

The other side of the marketplace listing ([provider.md](provider.md)). That
one answers "which providers may I hire?" over the active providers of the
whole site; this one answers "which providers am I?" over the account's own,
and lists the suspended and the expired ones **on purpose** — its reader is
precisely the person who needs to be told that the licence ran out.

Read-only: there is no create, update or delete over the API here either. A
provider is loaded, edited and suspended by the operator from the back office
(SPEC 77/78).

Not the full ficha: no address, no long description, no tags, no gallery and no
individual ratings. For those there is
[GET /api/v1/providers/%](provider-detail.md), which answers `200` for a
provider with an expired licence and whose `id` is exactly the one this listing
gives.

**The `id` of each provider here is what
[`GET /api/v1/service-requests/provider`](service-request-provider.md) accepts
in `?provider_id`** (SPEC 98): this endpoint is where the app gets the values
that let a reader narrow their board to one of their providers. Any other nid —
somebody else's provider, or a node that is not one — answers `200` with an
empty list there, never a `403`.

**Authentication:** required (Bearer access token) **and the `proveedor` role**

This is the **first `api/v1` endpoint of the module authorised by a role**.
Until SPEC 97 the `proveedor` role only narrowed the back office and no
resource read it — see [provider-role.md](provider-role.md).

| The account… | Answer |
|--------------|--------|
| has no valid token | `401` |
| has a token but **not** the `proveedor` role | `403 provider_role_required` |
| has the role but **no** linked provider | `200` with `providers: []` |
| has the role and one or more linked providers | `200` with the list |

**Role and link are two different things, and the status code says so.** "You
are not a provider" is a permission; "the operator has not linked you yet" is a
pending task of somebody else, and a `403` would disguise it as a problem with
the account. Nothing in this module validates that role and link go together
(the hole [provider-role.md](provider-role.md) documents), so the symptom of a
half-configured account is an empty screen and not an opaque error.

**No exception for administrators.** An `administrator` without the `proveedor`
role receives the same `403` as anybody else: the endpoint answers "your
providers", and an administrator has none of their own. To see them all there
is the back office. There is no `uid` parameter and no admin variant — nobody
can list another account's providers through this route.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters:** none, and **none is ever an error**. There is no `page`,
no `limit`, no `category_id`, no `order_by` and no `sort`: any query string is
**ignored in silence**, so `?limit=1` answers exactly the same as the bare
route and never a `422`. The rule protects the client that reuses marketplace
code and sends a stray parameter by reflex.

**Success response (200)**

`data` carries **one key only**, `providers`. **There is no `pagination`**: an
account operates one or two providers, not a pageable collection, and an
envelope with `total: 2` and `total_pages: 1` would only force the app to write
a page loop that never takes a second turn.

```json
{
  "success": true,
  "data": {
    "providers": [
      {
        "id": 77,
        "logo": "https://midominio.com/sites/default/files/logo-electricidad-sur.png",
        "title": "Electricidad Sur",
        "categories": [
          { "id": 9, "code": "electricidad", "name": "Electricidad" }
        ],
        "rating_avg": 4.5,
        "rating_count": 12,
        "short_description": "Instalaciones y emergencias 24 h",
        "hourly_rate": 25.5,
        "status": true,
        "is_active": true
      },
      {
        "id": 41,
        "logo": null,
        "title": "Plomería Torres",
        "categories": [],
        "rating_avg": null,
        "rating_count": 0,
        "short_description": "",
        "hourly_rate": null,
        "status": true,
        "is_active": false
      }
    ]
  }
}
```

`providers` is always an array: with one, several or zero items.

### The item: ten keys, always the ten, in this order

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | `node.nid`. The id to send to [GET /api/v1/providers/%](provider-detail.md). |
| `logo` | string \| null | Absolute public URL of `field_logo`. `null` when there is no logo, and also when the file is gone. |
| `title` | string | Plain text, unescaped. |
| `categories` | array | `{id, code, name}` in delta order; `[]` when it has none. |
| `rating_avg` | float \| null | `null` is "not rated yet", not zero stars. |
| `rating_count` | int | `0`, never `null`. |
| `short_description` | string | Plain text; `""` when empty, never `null`. |
| `hourly_rate` | float \| null | |
| **`status`** | **bool** | `node.status == 1`. Published, or suspended by the operator. |
| **`is_active`** | **bool** | `status` **and** `field_license_expiry >= REQUEST_TIME`. |

**The first eight keys are byte for byte the ones
[GET /api/v1/providers](provider.md) answers** for the same provider — same
types, same `null`s, same order. They are not re-implemented here: both
endpoints build them with the same function, which is what keeps them from ever
drifting apart. The details written up in [provider.md](provider.md) —
unescaped strings, `rating_avg: null` is not zero stars, the two decimals are
JSON numbers, how the logo URL is built — apply here identically.

`hourly_rate` travels even though the own listing has no particular use for it.
That is the price of sharing the builder, and it is paid on purpose.

### `status` and `is_active`: what each one decides

`is_active` is literally the marketplace rule — the same one written up in
[Who is an active provider](provider.md#who-is-an-active-provider), boundary
`>=` included — but computed as a **flag over the provider** instead of applied
as a filter. Hence the contract:

> **`is_active: true` ⟺ that provider appears today in
> `GET /api/v1/providers`.**

Together the two flags give the reason with no extra field:

| `status` | `is_active` | What happened to the provider |
|----------|-------------|-------------------------------|
| `true`   | `true`      | Operative. It shows in the marketplace. |
| `true`   | `false`     | Licence expired, **or no licence on record at all**. |
| `false`  | `false`     | Suspended by hand by the operator. |
| `false`  | `true`      | **Impossible by construction**: `is_active` includes `status`. |

**`is_active` is never `null`.** A provider with no row in
`field_license_expiry` is `is_active: false`, the same as an expired one: a
third value would mean exactly what `false` already means and would cost the
app a case.

`license_expiry` does **not** travel. The exact date changes nothing the app
paints, and the two causes are already told apart by the pair of flags.

### Order

By `id` **descending**, and nothing else. With no pagination the order only has
to be deterministic; `nid DESC` is the same tie-breaker the public listing
already uses, needs no join and depends on no data that may be missing. The
active ones are **not** floated to the top: the state travels in its own two
keys and mixing it into the order would hide it there.

### Notes

- **Four queries per request**, whether the account operates one provider or
  twenty: the token, the link, the rows and the categories of all of them at
  once. Never one query per provider.
- **A link pointing at a deleted node is skipped**: it produces no row, and the
  rest of the providers are answered just the same.
- **No ceiling on the size of the response.** `field_provider_users` has no
  cardinality limit on the provider side and this route does not paginate, so
  an account linked to hundreds of providers would receive them all. Assumed:
  today the real case is one or two per account, and adding pagination later is
  additive.
- The role helpers live in `includes/myapi.provider_role.inc`, and
  `resources/provider.resource.inc` loads that include **itself**:
  `myapi.module` only pulls it inside the back-office hooks, so an API request
  would otherwise reach the gate with the functions undefined.
- **No cache**: no `ETag`, no `304`. Every request is answered fresh, like the
  rest of the module.
- `field_rating_avg` and `field_rating_count` are still written by nothing —
  the same note [provider.md](provider.md) carries.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 403  | `provider_role_required` | The account does not carry the `proveedor` role. The **only** cause of a `403` here, administrators included. |
| 405  | `method_not_allowed` | Any method other than `GET` (`POST`, `PUT`, `DELETE`, `PATCH`, …). Answered **before** authentication: a `POST` with no token is `405`, not `401`. |
