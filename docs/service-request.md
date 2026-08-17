## GET /api/v1/service-requests

Returns the service requests **the authenticated resident created** — a
paginated list with the status, the category, the number of offers received and,
when there is one, the awarded offer and the awarded provider.

Read-only collection. There is no create, update, cancel, close or award over
the API yet: `POST`, `PUT` and `DELETE` answer `405`. Requests and offers are
loaded today by the operator from the back office.

This is the **first route** of the `service_request` bundle, whose schema was
built by SPEC 77, 86 and 87 without one. Two more routes live in this same
document, added by SPEC 89:

- [`GET /api/v1/service-requests/{id}`](#get-apiv1service-requestsid) — the
  **detail**: everything this listing answers, plus the unit, the condominium,
  the requester, the images, the attachment, `closed_at` and the offers one by
  one. It is also the first endpoint of the module whose answer **depends on who
  asks**.
- [`GET /api/v1/service-requests/{id}/files/{fid}`](#get-apiv1service-requestsidfilesfid)
  — the **bytes** of one image or of the attachment, both `private://`.

One thing is deliberately **not** here and is its own spec: **the provider's
side of the marketplace** — *the requests I may attend*. That is the other half
of the market, with another scope and another shape. SPEC 89 gives a provider
the **detail** of a request they already know about; how they come to know about
it is that other endpoint.

> **Nothing creates a service request yet.** On the day this endpoint is
> deployed it answers an empty list to everybody, and that is the real state of
> the system, not a failure of the endpoint. It is verified against requests
> loaded by hand from the back office, exactly as the fields of SPEC 86 and 87
> are.

**Authentication:** required (Bearer access token)

**The scope is `field_requester = uid`, and nothing else.** No role is read and
no condominium is resolved:

- **Not the reader's roles.** A user who also holds the `proveedor` role reads
  here what they created **as a resident**, complete, with no category of theirs
  narrowing anything. A role is not an identity: whoever attends services can
  live in a condominium and need a plumber.
- **Not the condominium**, unlike [`/api/v1/claims`](claim.md). A claim can be
  public inside a condominium and its listing needs that scope; here nothing is
  public — the request belongs to whoever created it and to nobody else. Adding
  the condominium could only ever **remove** rows of your own, from a resident
  who changed unit.
- **Not `node.uid`.** The filter is `field_requester`, the semantic field, not
  the technical author. A request an administrator loaded from the back office
  **with `field_requester` pointing at the reader** is listed all the same —
  which today is every request on the site.

A reader with no requests gets `200` with an empty list and `total: 0`, never a
`403`. There is no `404` either: the collection always exists.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Values | Default | Notes |
|-------|--------|---------|-------|
| `page` | integer ≥ 1 | `1` | 1-based page number. Any other value (`0`, `-1`, `abc`, empty) silently falls back to `1` — no `422`. A page beyond the last one answers `200` with an empty list. |
| `limit` | integer 1–50, or `-1` | `20` | Items per page. Above 50 it is **cut to 50**. **`-1` means "everything on one page"** and forces `page: 1` (SPEC 15). Any other invalid value falls back to `20`. |
| `sort` | `asc` \| `desc` | `desc` | Direction of the creation date. Only the exact lowercase values count: `DESC`, `arriba` or an empty value fall back to `desc` — no `422`. |
| `status` | one or more catalogue keys, comma-separated | *(no filter)* | `?status=open,offered` filters by both. An unknown key inside the list is **dropped in silence**: `?status=open,inventado` filters by `open` alone, and `?status=inventado` filters by nothing at all. No `422`. |
| `category_id` | positive integer (`tid`) | *(no filter)* | Narrows the listing to the requests of that category. **The only parameter of this endpoint that can answer `422`** — see below. |

The two filters compose with `AND`, and `pagination` describes the result of the
two together.

### `category_id`: the one parameter that can be a `422`

Two idioms of validation live side by side in this query string, and that is a
decision rather than an oversight: `?category_id=abc` answers `422` while
`?status=abc` answers `200` with no filter.

**The reason is not the parameter, it is its twin.**
[`GET /api/v1/providers`](provider.md) already treats the **same parameter name
in the same domain** exactly like this. Having `?category_id=abc` answer `422`
in one endpoint and the whole list in the other would force the app to remember
which is which. The coherence that matters is that of one parameter **across
sibling endpoints**, not that of different parameters inside one.

- `?category_id=abc`, `-3`, `0`, `?category_id=` (empty) and `?category_id[]=1`
  → **`422 invalid_field`** naming `category_id`, answered **before any listing
  query runs**.
- `?category_id=<a tid that does not exist>`, `<a tid of another vocabulary>`,
  `<a tid with no requests of yours>` → **`200` with `service_requests: []` and
  `total: 0`**.

Once the value *is* a positive integer there are no more judgements: the
endpoint filters, it does not validate the catalogue. A `404` would say that the
endpoint does not exist, not the category.

The `id` to send is the one `/api/v1/service-categories` returns.

### `status`: the keys are the catalogue's

The six keys of `field_request_status`, in lifecycle order:

| Key | Meaning |
|-----|---------|
| `open` | Born with no provider chosen; the bidding round is open. |
| `direct` | Born with a provider chosen by the resident, **with no bidding round** (SPEC 87). |
| `offered` | At least one offer received, none awarded yet. |
| `assigned` | An offer has been awarded. |
| `closed` | Terminal: finished, with or without an award. |
| `cancelled` | Terminal: called off by the resident or the operator. |

The filter validates against `myapi_services_request_statuses()`, never against
a list written into the resource, so the day the catalogue gains a seventh
status the filter accepts it with no code change here.

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "service_requests": [
      {
        "id": 128,
        "title": "Fuga en el calentador",
        "description": "El calentador del baño principal gotea desde el lunes.\nEmpeoró el martes.",
        "status": "assigned",
        "category": { "id": 12, "name": "Plomería" },
        "offers_count": 3,
        "assigned_offer": { "id": 45, "status": "selected" },
        "assigned_provider": { "id": 7, "name": "Plomería Rivas" },
        "created": "2026-08-14T09:12:33",
        "desired_start": "2026-08-19T08:00:00"
      },
      {
        "id": 127,
        "title": "Pintar la reja del patio",
        "description": "Ya hablé con ellos, vienen el sábado.",
        "status": "direct",
        "category": { "id": 15, "name": "Pintura" },
        "offers_count": 0,
        "assigned_offer": null,
        "assigned_provider": { "id": 7, "name": "Plomería Rivas" },
        "created": "2026-08-12T18:40:02",
        "desired_start": "2026-08-16T09:00:00"
      }
    ],
    "pagination": { "total": 7, "page": 1, "limit": 20, "total_pages": 1 }
  }
}
```

`data` carries exactly two keys, `service_requests` and `pagination`, and
nothing else. `service_requests` is always a JSON array, even with one element
or none.

Each element contains exactly these **10 keys, always all 10, in this order**:

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The request's `nid`. Never `null`. |
| `title` | string | The node title, **as stored**. The bundle has no title field of its own. |
| `description` | string | `field_description`, **as stored**: the line breaks the resident typed are preserved. See [The description travels raw](#the-description-travels-raw). |
| `status` | string | One of the six catalogue keys above, in English and **with no label beside it**. |
| `category` | object | `{ "id": int, "name": string }`, exactly two keys. Never `null` — see [The category is required](#the-category-is-required). |
| `offers_count` | int | Offers received. **`0`** when there are none, never `null` and never absent. See below. |
| `assigned_offer` | object \| **null** | `{ "id": int, "status": string }` or `null`. `status` is a key of the **offer** catalogue: `sent`, `selected`, `rejected`, `withdrawn`. |
| `assigned_provider` | object \| **null** | `{ "id": int, "name": string }` or `null`. |
| `created` | string | `Y-m-d\TH:i:s`, site timezone — identical to `created` in `/api/v1/claims`. |
| `desired_start` | string | `field_desired_start`, same format. A real timestamp, not a naive stored string like `reception_date` in claims. |

`id`, `category.id`, `offers_count`, `assigned_offer.id` and
`assigned_provider.id` all travel as **JSON integers**, never as the strings the
database answers.

### The award is two sibling keys, not one object

`assigned_offer` and `assigned_provider` go `null` **independently**, and that is
the whole reason they are two keys:

| Status | `assigned_offer` | `assigned_provider` |
|--------|------------------|---------------------|
| `open`, `offered`, `cancelled` | `null` | `null` |
| `assigned` | `{ id, status }` | `{ id, name }` |
| **`direct`** | **`null`** | **`{ id, name }`** |
| `closed` | whatever it carried when it closed | whatever it carried when it closed |

**The `direct` row is the one that forbids nesting them.** SPEC 87 created that
status precisely for the request whose resident picked a provider with **no**
bidding round — provider yes, offer no. An `awarded: { offer, provider }` would
have to answer either a whole `null`, losing the provider, or an object with
half of it empty.

To ask *"is there an award?"*, read `assigned_provider`: it is the key that is
present in both awarded states.

> ⚠️ `assigned_provider` is **denormalised** and no flow maintains it yet.
> Nothing validates that it matches the provider of `assigned_offer`, so in
> principle the listing can show an offer of one provider and the name of
> another. The endpoint serves the two fields **exactly as stored**, without
> inventing a preference between them; the coherence is the awarding flow's to
> guarantee, and that flow does not exist yet.

### A broken reference answers `null` and the request stays

Nothing validates today that `field_assigned_offer` or
`field_assigned_provider` point at a live node. When the referenced node was
**deleted or unpublished**, the key answers `null` **and the request keeps its
place in the listing**.

Losing a request of your own from the listing because of a broken reference is
the worst failure a read endpoint can have: nothing is shown and no error
explains it. `null` already makes the distinction the app needs — *"there is no
award"* and *"there is an award I cannot paint"* are the same screen.

### `offers_count`

**Every published offer counts, whatever its status** — `sent`, `selected`,
`rejected` and `withdrawn` alike. *"How many offers did I get"* is the question a
listing answers, and an offer later withdrawn was still received. An
**unpublished** offer does not count.

A `direct` request always answers `0`: it went through no bidding round.

The breakdown by status is detail information and belongs to
`GET /api/v1/service-requests/{id}`, which lists the offers one by one.

> **`field_request` is a shared field.** It hangs off both `service_offer` and
> `service_transaction` (the timeline, SPEC 77), so the count is narrowed to the
> offer bundle. Adding timeline entries to a request does **not** move its
> `offers_count`. Without that condition the failure would be silent: the number
> stays plausible and is simply too high.

### The description travels raw

`description` is served **as stored**, without `myapi_text_to_plain()`. That
helper collapses the line breaks the resident typed, and the resident typed
them. It is also exactly what [`/api/v1/claims`](claim.md) does with this **same
shared field**, so the same datum travels the same way in both endpoints.

The contract therefore **depends on the instance keeping the `plain_text`
format**, which is what SPEC 77 pinned. If someone switches it to a format that
allows HTML, this endpoint starts serving unescaped markup — the same risk
`/api/v1/claims` already runs with the same field, which is why the answer is
the same in both places rather than different in one.

Truncation is presentation and belongs to the app, which knows how much fits.

### The category is required

`category` is never `null`: `field_category` is required on the bundle, and the
listing joins it and its taxonomy term with an **`INNER JOIN`** — the one
deliberate exception to the "a broken reference answers `null`" rule above.

A service listing with no category is not actionable, so the inconsistency must
**show** (a row missing) rather than spread as a `null` every client paints its
own way.

**The consequence, written down so it is not discovered as a bug:** if an
operator deletes a term from the `service_category` vocabulary with requests
still hanging off it, those requests **disappear from the listing** and no
message says so. It is a data problem, not a code one, and it is diagnosed with:

```sql
SELECT fc.entity_id, fc.field_category_tid
FROM field_data_field_category fc
LEFT JOIN taxonomy_term_data td ON td.tid = fc.field_category_tid
WHERE fc.entity_type = 'node' AND fc.deleted = 0 AND td.tid IS NULL;
```

The fix is to reassign the category. Preventing it at the root belongs to the
spec that allows **deleting** categories.

### Ordering

Two criteria:

1. `created` — the creation date, in the direction `sort` asks for. It never
   moves when the request is edited, and it is the order the resident expects:
   *the last thing I asked for, on top*.
2. `id`, **in the same direction**, as a tie-break.

The tie-break is not decoration. Without it, two requests created in the same
second can swap places between page 1 and page 2, so the app shows one of them
twice and never shows the other.

There is no other orderable column: `desired_start` and `changed` cannot be
ordered by.

### Pagination

`pagination` carries the same four keys as every other listing of the module:

| Key | Meaning |
|-----|---------|
| `total` | Size of the **already filtered** set — with `?category_id` or `?status`, that subset, not the whole listing. |
| `page` | The page that was served, echoed back (the value asked for, even beyond the last page). |
| `limit` | Items per page actually applied, after the clamp. `-1` travels back as `-1`. |
| `total_pages` | `ceil(total / limit)`, and **`0`** when `total` is `0` — not `1`. With `limit=-1` it is `1`, or `0` when there is nothing. |

An empty listing answers
`{"service_requests": [], "pagination": {"total": 0, "page": 1, "limit": 20,
"total_pages": 0}}` with `200`, never an error.

`total` and the rows are two queries, so a request created between them can
leave `total` off by one for that single request. Same behaviour as every other
listing of the module.

**Notes**
- The queries of this endpoint deliberately carry **no `->addTag('node_access')`**.
  The tag would run `myapi_provider_role_alter_node_query()` over them — a
  whitelist by the provider's categories (SPEC 78) — and a resident who also
  holds the `proveedor` role would stop seeing their own requests of categories
  they do not attend, silently, with a shorter list and no error. It is also the
  sentence `myapi_query_node_access_alter()` has written down about every query
  of this module. **Do not add it "to do it the Drupal way".** A unit test fails
  if it appears.
- The status travels as the **key only**, with no Spanish label beside it — same
  as claims and reservations, so the app's existing mapping applies. The labels
  of `myapi_services_request_statuses()` are back-office text and never travel.
- `?category_id` accepts **one** value, not a list. `?category_id=3,7` is a
  `422`, same as in `/api/v1/providers`.
- The request costs **three listing queries** whatever the page size — the
  count, the page, and the offers of that whole page — plus the token lookup.
  None of them grows with the number of rows.
- The queries read the `field_data_*` tables directly instead of loading the
  nodes, which is the trade-off the whole module already accepts: they assume
  the current field storage of Drupal 7.
- There is no cache: no `ETag`, no `304`. Every request is answered fresh.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 405  | `method_not_allowed` | Any method other than `GET` (`POST`, `PUT`, `DELETE`, …). Answered **before** authentication: a `POST` with no token is `405`, not `401`. |
| 422  | `invalid_field` | `category_id` is present and is not a positive integer (`abc`, `-3`, `0`, empty, an array). The message names the field. It is the **only** `422` this endpoint can answer. |

---

## GET /api/v1/service-requests/{id}

Returns **one** service request in full: the ten keys of the listing plus the
requester, the unit, the condominium, the images, the attachment, `closed_at`
and the offers one by one.

Read-only. `POST`, `PUT` and `DELETE` answer `405`, before the token and before
any query.

**Authentication:** required (Bearer access token)

### Two readers, two answers

This is the **first endpoint of the module whose response depends on who asks**.

| Reader | Who they are | What they get |
|--------|--------------|---------------|
| **The requester** | `field_requester = uid` | The whole request, every offer, the unit included. |
| **The provider** | Operates a `provider` node that either already bid on this request, or is active and of its category while the request is still unawarded | The same eighteen keys, with `unit: null` and `offers` trimmed to their own. |
| Anybody else | — | `403 forbidden` |

**The keys are always the same eighteen, for both readers.** None appears and
none disappears; what changes is the content of three:

| Key | Requester | Provider |
|-----|-----------|----------|
| `viewer` | `"requester"` | `"provider"` |
| `unit` | `{id, name}` | **`null`** |
| `offers` | **all of them**, `created DESC` | **only their own** (zero, one, or one per provider node they operate) |
| `offers_count` | the total | **the total, the same** — they know how many they compete against, not who nor for how much |
| `requester` | `{id, name}` | `{id, name}`, the same |
| `condominium` | `{id, name}` | the same |
| `images` / `attachment` | downloadable | downloadable, same URLs |
| everything else | | the same |

`viewer` travels because without it `unit: null` cannot be told apart from *this
request has no unit*, and the app cannot decide whether to paint **make an
offer** or **award**. Deducing the role by comparing `requester.id` with the
token's uid would work today and would put the server's access rule inside the
client.

`unit` is `null` for the provider by explicit decision: to decide whether to bid
they need the category, the description, the photos, the desired date and the
condominium. The flat number adds nothing to that decision and does say where a
specific person lives, to anyone of the category. The day a provider is awarded
the job they will need it — and that is the award spec, not this one.

`requester` travels with `id` and `name` and **nothing else**, for both readers:
no phone, no email, no dni. The name says who you would be dealing with; the
rest is contact data and does not travel from this bundle.

### The access rule, exactly

Three rules, evaluated in order, first hit wins. Both this endpoint and the file
one below call the **same function** to decide.

| # | Rule | Condition | Result |
|---|------|-----------|--------|
| 0 | The request exists | `type = 'service_request' AND status = 1` (and its category term exists) | Otherwise **`404 not_found`**, nothing else evaluated |
| 1 | The requester | `field_requester = uid` | `viewer: "requester"` |
| 2 | Already offered | one of the reader's `provider` nodes has an offer on this request | `viewer: "provider"` — **whatever the status**, and whatever the category is today |
| 3 | Eligible provider | status ∈ (`open`, `offered`) **and** `field_assigned_offer` empty **and** `field_assigned_provider` empty **and** the request's category is one of theirs **and** at least one of their providers is active | `viewer: "provider"` |
| — | None | | **`403 forbidden`** |

Four things this table decides, each with a reason:

- **Rule 1 ignores roles entirely.** A resident who also holds `proveedor` reads
  their own request of a category they do not attend, complete. Same reasoning
  as the listing, and the same reason no query here is tagged `node_access`.
- **Rule 2 is status- and category-independent.** Whoever has a live offer needs
  to see what became of it. Losing the detail the moment it is awarded to
  somebody else would leave an offer in their app with nothing behind it and a
  `403` as the only explanation.
- **Rule 3 checks the status AND both award keys**, and reads them **raw**. A
  request left in `offered` with `field_assigned_offer` already filled in — an
  incoherent datum nothing prevents today — stops being biddable, which is the
  safe reading. Reading the *resolved* reference instead would make an award
  pointing at an unpublished offer look like no award at all and reopen the
  request to every provider of the category.
- **Being "a provider" is a `provider` node pointing at you** through
  `field_provider_users`, never the `proveedor` role on your account. A user
  holding the role with no provider node behind them is `403`.

> **`direct` requests are `403` for providers, and that is deliberate.**
> `myapi_provider_role_broadcast_statuses()` — what the back office does *not*
> hide from a provider — includes `direct`; **rule 3 does not**. They are two
> policies over the same datum and they have to be able to diverge: a `direct`
> request is born with a provider already chosen, which is exactly what
> "unawarded" excludes.
>
> **The consequence, written down so it is not discovered in production:** today
> the provider of a `direct` request **cannot read its detail**. Nothing creates
> direct requests yet, so it costs nothing now. The spec that creates them is
> the one that will know what relation it leaves behind and whether the rule
> grows a fourth clause. A unit test pins the current behaviour, so equalising
> the two lists breaks the suite.

### `404` and `403` mean different things

Unlike the listing, which simply does not show a row:

- **`404 not_found`** — no such request: it does not exist, it is unpublished,
  it is of another bundle, or its category tid is orphaned (see *The category is
  required* above). The reader is told none of the four apart.
- **`403 forbidden`** — it exists, and this reader is neither its requester nor
  a provider who may still bid.

The nid is not a secret: a provider reached it from a listing that handed it to
them, and *this request no longer takes offers* is actionable where *it does not
exist* is a lie.

`/api/v1/service-requests/abc`, `/0` and `/-3` answer `404` **without a single
query**, not even the token's: the shape of the URL is wrong whoever is asking.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Success response (200) — the requester**

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 128,
      "title": "Fuga en el calentador",
      "description": "El calentador del baño principal gotea desde el lunes.",
      "status": "offered",
      "category": { "id": 12, "name": "Plomería" },
      "offers_count": 2,
      "assigned_offer": null,
      "assigned_provider": null,
      "created": "2026-08-14T09:12:33",
      "desired_start": "2026-08-19T08:00:00",

      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "unit": { "id": 55, "name": "A-301" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [
        { "id": 91, "url": "https://.../api/v1/service-requests/128/files/91", "filename": "fuga.jpg" }
      ],
      "attachment": { "id": 92, "url": "https://.../api/v1/service-requests/128/files/92", "filename": "presupuesto.pdf" },
      "closed_at": null,
      "offers": [
        {
          "id": 46,
          "provider": { "id": 9, "name": "Servicios Díaz", "logo": "https://.../sites/default/files/logo-diaz.png" },
          "amount": 95.5,
          "message": "Puedo pasar el jueves por la mañana.",
          "status": "sent",
          "created": "2026-08-15T18:40:02"
        },
        {
          "id": 45,
          "provider": { "id": 7, "name": "Plomería Rivas", "logo": null },
          "amount": null,
          "message": "Necesito ver la instalación antes de dar precio.",
          "status": "sent",
          "created": "2026-08-15T11:03:17"
        }
      ]
    }
  }
}
```

**Success response (200) — the provider**: the same body with
`"viewer": "provider"`, `"unit": null` and `offers` holding only their own —
`offers_count` unchanged.

### The keys the detail adds

| Key | Source | Nullable | Notes |
|-----|--------|:--------:|-------|
| `viewer` | the access rule | No | `"requester"` or `"provider"`. Never `null`: a reader with no role already got a `403`. |
| `requester` | `field_requester` → `users` | No¹ | `{id, name}`. The name is `field_nombre + field_apellidos`, or `users.name` when **either** is missing — never a hybrid. The same rule `/api/v1/units` uses, shared in `includes/myapi.user.inc`. |
| `unit` | `field_unit` → `field_nombre_vivienda` | **Yes** | `{id, name}`, or `null` entirely for the provider. `name` is `field_nombre_vivienda`, **not** the `vivienda` node title. |
| `condominium` | `field_condominium` → `node.title` | No¹ | `{id, name}`. Here the name **is** the node title, unlike the unit. |
| `images` | `field_images` → `file_managed` | No | **Always an array**, empty when there are none. `{id, url, filename}` each, in the `delta` order the operator uploaded them. |
| `attachment` | `field_attachment` → `file_managed` | **Yes** | `{id, url, filename}` or `null`. Cardinality 1. |
| `closed_at` | `field_closed_at` | **Yes** | `Y-m-d\TH:i:s`, `null` while the request is not closed. |
| `offers` | `service_offer` via `field_request` | No | **Always an array**, empty when there are none — every `direct` request among them. |

¹ Required on the bundle; the `LEFT JOIN`s leave them `NULL` if somebody deleted
the row by hand, and the serialiser answers `null` instead of breaking.

**Each offer:**

| Key | Source | Nullable | Notes |
|-----|--------|:--------:|-------|
| `id` | the offer's `nid` | No | JSON integer. |
| `provider.id` / `.name` | `field_provider` → `node.title` | **Yes** (the whole object) | An unpublished or deleted provider leaves `provider: null` and **the offer stays in the list** — dropping it would make the list disagree with `offers_count`. |
| `provider.logo` | `field_logo` → `file_create_url()` | **Yes** | An absolute, **direct** URL: `field_logo` is `public://` (SPEC 85), unlike the request's own images. Never an `api/v1/...` path. |
| `amount` | `field_offer_amount` | **Yes** | **A number or `null`**, never `"95.50"`. `null` means *no price yet* — the field is optional because the price can be settled in the chat. `0` is a price somebody offered. |
| `message` | `field_offer_message` | No¹ | As stored, with its line breaks, exactly like `description`. |
| `status` | `field_offer_status` | No¹ | `sent` / `selected` / `rejected` / `withdrawn`. |
| `created` | `node.created` | No | `Y-m-d\TH:i:s`. |

**Which offers travel:** every **published** one, whatever its status —
`withdrawn` and `rejected` included — which is exactly what `offers_count`
counts, so the number and the list cannot contradict each other. An unpublished
offer is neither listed nor counted. A `service_transaction` of the request is
neither: `field_request` is shared by the two bundles, and the bundle condition
is what stops the timeline from being read as bids.

**Order:** `created DESC`, tie-broken by `id DESC`. The tie-break is not
decoration — two offers of the same second would otherwise swap places between
two reads of the same screen.

**Offers are not paginated.** They all travel. A request receives units of
offers, not hundreds.

**Notes**
- The detail costs **five content queries**, fixed — the request row, the
  requester's name, the images, `offers_count`, and the offers — plus the token
  lookup. None of them grows with the number of images or of offers. A provider
  reader adds the role questions of `includes/myapi.provider_role.inc`, all
  statically cached.
- `offers_count` comes from the **aggregate query**, never from
  `count(offers)`: the provider gets a trimmed list and the full total.
- The ten first keys are **byte for byte** what `GET /api/v1/service-requests`
  answers for the same request — the same serialiser produces them.
- No query here carries `->addTag('node_access')` either, for the reason written
  in the listing's notes. A unit test fails if it appears.
- Everything about `description`, the required category and its orphan-tid
  diagnosis, above, applies unchanged to the detail — including that an orphan
  category makes the request answer `404` here and disappear from the listing
  there. The two views agree, which is the point.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header absent or malformed. |
| 401  | `invalid_token` | Token unknown, revoked, expired, or its user is missing or blocked. |
| 403  | `forbidden` | The request exists and the reader is neither its requester nor a provider who may still bid on it. |
| 404  | `not_found` | No such request (missing, unpublished, another bundle, orphan category), **or** an `{id}` that is not a positive integer — the latter without any query. |
| 405  | `method_not_allowed` | Any method other than `GET`. Answered before the token and before any query. |

---

## GET /api/v1/service-requests/{id}/files/{fid}

Serves **the bytes** of one image of the request or of its attachment. Not JSON
on the happy path; errors do travel in the module's envelope.

It exists because `field_images` and `field_attachment` are `private://` **at
the field level** since SPEC 77: without it the detail would answer filenames
nobody could open. Same pair of routes [claims](claim.md) got in SPEC 65 and
provider galleries in SPEC 82, with **this** endpoint's access rule.

**Authentication:** required (Bearer access token)

**The access rule is the same function as the detail's**, not a copy of its
conditions: whoever cannot read the detail cannot download its files, and the
day a condition changes it changes for both routes at once. A unit test asks
both routes with the same token in six scenarios and fails if they disagree.

| Situation | Response |
|-----------|----------|
| Token absent / invalid | `401` |
| The request does not exist or is unpublished | `404 not_found` |
| The reader does not pass the access rule | `403 forbidden` |
| The fid belongs to neither `field_images` nor `field_attachment` **of that** request | `404 not_found` |
| The file is not on disk | `404 not_found` |
| Any method other than `GET` | `405 method_not_allowed`, before the token |
| Everything fine | `200` with `Content-Type`, `Content-Length`, `Content-Disposition: inline`, `Cache-Control: private, no-store` and the bytes |

> **The membership check is what makes this route safe.** Without it,
> `/service-requests/128/files/999` would serve **any private file of the site**
> — a payment receipt, another resident's claim photo, a provider's private
> gallery — to anyone with access to *one* request; and access to an `open`
> request of your category is access every active provider has. The fid's owner
> is resolved and compared with the `{id}` of the route **before** the file is
> ever loaded. A structural test fails if that comparison disappears.

The URL serves the **original** file. Image styles are not generated and not
offered: `private://styles/...` is out of scope.

### The back office

`hook_file_download()` decides for the operator, and it is a **different rule**
from the app's: the administrative roles (`administrator`, `backend`,
`administrador edificio`), with `administrador edificio` scoped to the
condominiums assigned to them — read from the request's `field_condominium`.

Without that hook nobody would see the thumbnails on `node/{id}/edit`, because
in `private://` nothing is served without an explicit decision. With it, a user
with a Drupal session and no administrative role who pastes the private URL gets
Drupal's `403`.

`myapi_file_download()` asks **three owners in a chain** — claims, provider
galleries, and service requests — and each answers `NULL` for a file that is not
theirs, which is what keeps payment receipts and every other private file of the
site behaving exactly as before.

> **MAINTENANCE RULE — read this before adding another file field to
> `service_request`.** Two things must happen or the file is born unreachable by
> both consumers, with no error to explain it:
>
> 1. create the field with `'settings' => ['uri_scheme' => 'private']`;
> 2. **add its name to the list `myapi_service_request_file_request_nid()`
>    walks** in `includes/myapi.service_request_files.inc`.
>
> The same rule `includes/myapi.claims_files.inc` carries for claims.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` / `invalid_token` | No token, or a token that is not valid. |
| 403  | `forbidden` | The reader may not read this request — the same `403` the detail answers. |
| 404  | `not_found` | No such request; or `{id}` / `{fid}` not a positive integer; or the fid does not belong to that request; or the file is missing from disk. |
| 405  | `method_not_allowed` | Any method other than `GET`, before the token. |

---

## What is still not here

Written down so it is not looked for in this document:

- **Every write.** Creating, editing, cancelling, closing, awarding, offering,
  uploading and deleting files. All `405`.
- **Offers as a resource of their own** — creating them, withdrawing them,
  `GET /api/v1/offers/{id}`. Here they are only **read**, inside one request.
- **The chat.** `field_firebase_path`, `field_chat_opened_at` and
  `field_last_message_at` do not travel: who opens the thread and when the path
  is generated is another spec, and a key served today is a contract that spec
  could no longer change.
- **The timeline.** `service_transaction` exists since SPEC 77 and is not
  served.
- **Ratings.** Neither served nor required.
- **The provider's listing** — *the requests I may attend*.
- **`?include=`, `ETag`, conditional caching.** The detail always answers whole.

> **On the day this is deployed it answers `403` to almost everybody**, because
> nothing creates requests, offers or providers through the API yet and the data
> is loaded by hand. That is the real state of the system, not a failure of the
> access rule. It is verified against requests, offers and providers loaded from
> the back office, exactly as SPEC 86, 87 and 88 were.
