## GET /api/v1/service-requests

Returns the service requests **the authenticated resident created** — a
paginated list with the status, the category, the unit the service goes to, the
number of offers received and, when there is one, the awarded offer and the
awarded provider.

Reads the requester's own requests. `PUT` and `DELETE` answer `405` on this
route; `POST` **creates** one — see
[`POST /api/v1/service-requests`](#post-apiv1service-requests) below. Editing a
request that already exists is a `POST` on the **item** route
([`POST /api/v1/service-requests/{id}`](#post-apiv1service-requestsid)) and
cancelling has a route of its own. **Offering** has a route and a document of
its own since SPEC 100 — see
[service-offer.md](service-offer.md). **Awarding** lives there too, since
SPEC 106: `PUT /api/v1/service-offers/{id}/accept` is the write outside this
document that moves a request to `assigned` and writes `field_assigned_offer`
and `field_assigned_provider` — the resident acts on the offer, and the request
changes as a consequence. **Closing** has a route of its own since SPEC 108 —
[`PUT /api/v1/service-requests/{id}/close`](#put-apiv1service-requestsidclose),
the write that ends a request and rates the provider that did the job.

This is the **first route** of the `service_request` bundle, whose schema was
built by SPEC 77, 86 and 87 without one. Three more routes live in this same
document:

- [`GET /api/v1/service-requests/{id}`](#get-apiv1service-requestsid) — the
  **detail**: everything this listing answers, plus the condominium, the
  requester, the images, the attachment, `closed_at` and the offers one by
  one. It is also the first endpoint of the module whose answer **depends on who
  asks** (SPEC 89).
- [`GET /api/v1/service-requests/{id}/files/{fid}`](#get-apiv1service-requestsidfilesfid)
  — the **bytes** of one image or of the attachment, both `private://` (SPEC 89).
- [`POST /api/v1/service-requests`](#post-apiv1service-requests) — **creates**
  a request for the authenticated resident, with the condominium derived
  server-side and an optional direct award to an eligible provider (SPEC 90).

One thing is deliberately **not** here and has its own document: **the
provider's side of the marketplace** — *the requests that concern me*. That is
the other half of the market, with another scope and another shape, and it now
exists:
[`GET /api/v1/service-requests/provider`](service-request-provider.md)
(SPEC 98). SPEC 89 gives a provider the **detail** of a request they already
know about; that endpoint is how they come to know about it.

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
| `category_id` | positive integer (`tid`) | *(no filter)* | Narrows the listing to the requests of that category. One of the **two** parameters of this endpoint that can answer `422` — see below. |
| `unit_id` | positive integer (`nid`) | *(no filter)* | Narrows the listing to the requests of that unit. The other parameter that can answer `422` — see below. A unit that is not yours is **not** an error: it answers an empty list. |
| `date_from` | ISO date `YYYY-MM-DD` | *(no filter)* | Lower bound on the **creation date**, inclusive of the whole day. Anything that is not a real calendar date is **dropped in silence** — no `422`. |
| `date_to` | ISO date `YYYY-MM-DD` | *(no filter)* | Upper bound on the **creation date**, inclusive of the whole day. Same lax validation. |

The filters compose with `AND`, and `pagination` describes the result of all of
them together.

### `category_id` and `unit_id`: the two parameters that can be a `422`

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

`?unit_id` is strict for the same **kind** of reason, and again it is its twin
rather than itself: [`POST /api/v1/service-requests`](#post-apiv1service-requests)
already answers `422 invalid_field` for a malformed `unit_id`, and the app sends
here the very value it just created a request with. A listing that swallowed a
broken id and answered the **whole** list would tell the client its unit
selector works while it does not — and the resident would read somebody else's
request under the heading of their own flat.

Both go through the same parser, so they refuse the same values:

- `?unit_id=abc`, `-3`, `0`, `?unit_id=` (empty) and `?unit_id[]=55` →
  **`422 invalid_field`** naming `unit_id`, answered **before any listing query
  runs**.
- `?unit_id=<a nid that is not yours>`, `<one you moved out of>`, `<one that
  does not exist>` → **`200` with `service_requests: []` and `total: 0`**.

### `unit_id`: a foreign unit is an empty list, never a `403`

**This is the one asymmetry with the creation, and it is deliberate.**
`POST /api/v1/service-requests` answers `403 unit_access_denied` for a unit the
resident does not own or occupy, because it would **write** something on it. The
filter writes nothing: the scope of this endpoint is already
`field_requester = uid`, so a unit that is not yours can only intersect your own
requests in the empty set.

A `403` here would do the opposite of protecting anything — it would confirm to
whoever probed that the unit exists — and it would cost a query to say what an
empty list already says. Same criterion as a `category_id` no request carries.

**The filter compares the raw reference and never the joined `vivienda` node.**
A request whose unit was unpublished or deleted is still a request **of** that
unit, so `?unit_id=<it>` keeps it and the item answers `unit: null`. Filtering
on the resolved node instead would empty the resident's screen because of a node
they have never heard of.

The `id` to send is the one `/api/v1/units` returns, and the same one the app
sent as `unit_id` when it created the request.

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

### `date_from` / `date_to`: the range is over `created`

The date filtered is **`created`, the one the listing already orders by** — when
the resident asked for the service — and **never `desired_start`**, when they
want the work done. Those are two different questions, and filtering by the
column the client sorts by is the only combination that reads consistently. A
range over `desired_start` would be another pair of parameters and another spec.

Both bounds are **inclusive of the whole day** in the site's timezone:
`?date_from=2026-08-18&date_to=2026-08-18` returns everything created that day,
from `00:00:00` to `23:59:59` — a request asked for at half past eight in the
evening is **not** dropped. `created` is stored as an instant, `date_from` names
a day, and the endpoint resolves the difference rather than making the client do
it.

The bounds are independent — `?date_from` alone is open-ended forward,
`?date_to` alone open-ended backward — and each is validated on its own:
`?date_from=2026-13-05&date_to=2026-08-18` still filters by the valid twin.

**The validation is lax, like everything here except `category_id`:**

- `?date_from=abc`, `2026-13-05`, `2026-02-30`, `18-08-2026`, `2026-8-6`,
  `2026-08-06 10:00:00`, `?date_from=` (empty) or `?date_from[]=…` → the bound
  is **ignored**, `200`, no `422`.
- `?date_from=2026-08-20&date_to=2026-08-10` (**inverted**) → the **whole
  range** is dropped and the listing answers as if no dates had been sent. A
  client that swaps two date pickers gets its listing back, not a blank screen.
- A valid range that matches nothing → `200` with `service_requests: []` and
  `total: 0`.

Same parameter names and the same lax rules as
[`/api/v1/bulletins`](bulletin.md), [`/api/v1/claims`](claim.md),
[`/api/v1/payments`](payment.md) and [`/api/v1/expenses`](expense.md); the
validation itself is the shared `myapi_parse_date_range_param()`, so this
endpoint has no date parsing of its own. What changes from one listing to the
next is only **which** date is filtered: the reception date in claims, the
payment date in payments, and here the creation date — like bulletins.

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
        "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
        "unit": { "id": 55, "name": "A-301" },
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
        "category": { "id": 15, "code": "painting", "name": "Pintura" },
        "unit": { "id": 56, "name": "B-102" },
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

Each element contains exactly these **11 keys, always all 11, in this order**:

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | The request's `nid`. Never `null`. |
| `title` | string | The node title, **as stored**. The bundle has no title field of its own. |
| `description` | string | `field_description`, **as stored**: the line breaks the resident typed are preserved. See [The description travels raw](#the-description-travels-raw). |
| `status` | string | One of the six catalogue keys above, in English and **with no label beside it**. |
| `category` | object | `{ "id": int, "code": string, "name": string }`, exactly three keys. Never `null` — see [The category is required](#the-category-is-required). `code` is `field_category_code`, the stable identifier `/api/v1/service-categories` answers for the same term, and `""` when the term has none — see [The category code](#the-category-code). |
| `unit` | object \| **null** | `{ "id": int, "name": string }` or **a whole `null`**, never `{id: null, name: null}`. The unit the service goes to. `name` is `field_nombre_vivienda`, **not** the `vivienda` node title — see [The unit](#the-unit). |
| `offers_count` | int | Offers received. **`0`** when there are none, never `null` and never absent. See below. |
| `assigned_offer` | object \| **null** | `{ "id": int, "status": string }` or `null`. `status` is a key of the **offer** catalogue: `sent`, `selected`, `rejected`, `withdrawn`. **In the detail this key travels whole** — see [The award, widened](#the-award-widened-assigned_provider-and-assigned_offer). |
| `assigned_provider` | object \| **null** | `{ "id": int, "name": string }` or `null`. **In the detail this key travels as the whole provider card** — see [The award, widened](#the-award-widened-assigned_provider-and-assigned_offer). |
| `created` | string | `Y-m-d\TH:i:s`, site timezone — identical to `created` in `/api/v1/claims`. |
| `desired_start` | string | `field_desired_start`, same format. A real timestamp, not a naive stored string like `reception_date` in claims. |

`id`, `category.id`, `unit.id`, `offers_count`, `assigned_offer.id` and
`assigned_provider.id` all travel as **JSON integers**, never as the strings the
database answers.

`unit` sits **beside `category`** and not at the end: the two answer what the
request is and where it is, while `offers_count` and the two `assigned_*` keys
are the market's state and stay contiguous. The same order reaches the detail,
which merges this very item.

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

**The two keys keep this table in the detail, and only grow inside.** A listing
answers twenty requests and can afford to *name* the award; the detail answers
one and is the screen where the resident looks at what they hired, so there both
objects travel whole — the provider as the eight-key card of
[`GET /api/v1/providers`](provider.md) and the offer as the same fifteen-key
object an item of `offers` is. Which of them is `null` never changes, nor does
the order or the position of the keys: what grows is the content of an object.
See [The award, widened](#the-award-widened-assigned_provider-and-assigned_offer).

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

**A `direct` request can answer `1` since SPEC 101.** It went through no bidding
round, but the provider it was awarded to may
[send their quote](service-offer.md#quoting-a-direct-request) — and that quote
is an offer like any other, counted here like any other. Do **not** assume
`direct ⇒ 0`: the assumption was already unsafe (nothing ever stopped a
back-office offer on a `direct`) and now there is a route that does it on
purpose. `2` is not reachable: only the awarded provider may quote, and only
once while their offer is live.

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

### The unit

`unit` is the flat the service goes to — `field_unit`, required on the bundle
since SPEC 86 and written by the creation endpoint from the resident's own units.

`name` is **`field_nombre_vivienda`**, not the `vivienda` node title: the title
is an internal label and the field is the name the resident knows their flat by
— the same value `/api/v1/units` and the detail answer as `name`.

**It is a whole `null` in two cases, and the request stays listed in both:**

- **A request older than the requirement.** `field_unit` was made required on a
  bundle that already had rows, with no backfill, so a request loaded from the
  back office before that day can carry no unit at all. The join is `LEFT`
  precisely for this: an `INNER` one would make that request vanish from its own
  owner's listing with no message.
- **A reference that no longer resolves** — the `vivienda` was deleted or
  unpublished. Same rule the awarded offer and provider follow.

The second case is the one where `?unit_id` and `unit` disagree on purpose: the
filter keeps the request (the raw reference still points at that unit) and the
item answers `unit: null` (there is no node to name). That is the honest pair of
answers, and it is why the two read different columns.

### The category code

`category.code` is `field_category_code`, **the same value
`GET /api/v1/service-categories` and `GET /api/v1/providers` answer for that
same term**. It exists because the `tid` is not stable — reimporting the
vocabulary changes it and the code does not — so any per-category logic in the
app (a local icon, a special screen) hangs off `code` and never off `id`.

Two rules, and they are the ones the sibling endpoints already follow:

- **`""`, never `null`, and the request is never hidden.** `field_category_code`
  is required on the vocabulary, so a term without one is corrupt data rather
  than a business case; the client still gets a string to compare, and a
  category that would otherwise vanish from the resident's own listing stays
  visible. This is the one join of the category that is **`LEFT`** — its two
  term joins are `INNER`, see above.
- **It travels as stored**, unescaped, exactly like `title` and `category.name`
  in this endpoint. The consumer is a Flutter `Text` widget, not an HTML page.

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
| `total` | Size of the **already filtered** set — with `?category_id`, `?unit_id` or `?status`, that subset, not the whole listing. |
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
  `422`, same as in `/api/v1/providers`. `?unit_id` is the same: one value, and
  `?unit_id=55,56` is a `422`.
- **`?unit_id` costs no extra query.** The reference table is joined by the
  shared base query — the count and the page both use it — and the filter is one
  more condition on it. The three listing queries of this endpoint are still
  three, with or without the parameter.
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
| 405  | `method_not_allowed` | Any method other than `GET` or `POST` (`PUT`, `DELETE`, …). Answered **before** authentication: a `PUT` with no token is `405`, not `401`. `POST` no longer answers `405` here since SPEC 90 — see [`POST /api/v1/service-requests`](#post-apiv1service-requests). |
| 422  | `invalid_field` | `category_id` or `unit_id` is present and is not a positive integer (`abc`, `-3`, `0`, empty, an array). The message names the field. They are the **only** `422`s a `GET` on this endpoint can answer, and both are checked before any listing query runs. |

---

## GET /api/v1/service-requests/{id}

Returns **one** service request in full: the eleven keys of the listing plus the
requester, the condominium, the images, the attachment, `closed_at`, the offers
one by one and the **timeline** — its `service_transaction` entries in
chronological order.

`unit` is one of the listing's eleven since SPEC 91 — the detail no longer
resolves it on its own, it **overwrites** it for the provider (see below), and
it therefore travels in the listing's position, right after `category`.

`GET` is read-only. `PUT` and `DELETE` answer `405`, before the token and before
any query; `POST` on this same route **edits** the request since SPEC 96 — see
[`POST /api/v1/service-requests/{id}`](#post-apiv1service-requestsid).

**Authentication:** required (Bearer access token)

### Two readers, two answers

This is the **first endpoint of the module whose response depends on who asks**.

| Reader | Who they are | What they get |
|--------|--------------|---------------|
| **The requester** | `field_requester = uid` | The whole request, every offer, the unit included. |
| **The provider** | Operates a `provider` node that either already bid on this request, or is active and of its category while the request is still unawarded | The same nineteen keys, with `unit: null` and `offers` trimmed to their own. |
| Anybody else | — | `403 forbidden` |

**The keys are always the same nineteen, for both readers.** None appears and
none disappears; what changes is the content of three:

| Key | Requester | Provider |
|-----|-----------|----------|
| `viewer` | `"requester"` | `"provider"` |
| `unit` | `{id, name}` | **`null`** |
| `offers` | **all of them**, `created DESC` | **only their own** (zero, one, or one per provider node they operate) |
| `offers_count` | the total | **the total, the same** — they know how many they compete against, not who nor for how much |
| `requester` | `{id, name}` | `{id, name}`, the same |
| `condominium` | `{id, name}` | the same |
| `transactions` | **the whole timeline** | **the same whole timeline**, comments included — see below |
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
| 2b | Awarded to me | `field_assigned_provider` points at one of the reader's `provider` nodes | `viewer: "provider"` — **whatever the status**: `direct`, `assigned`, `closed` and `cancelled` included (SPEC 98) |
| 3 | Eligible provider | status ∈ (`open`, `offered`) **and** `field_assigned_offer` empty **and** `field_assigned_provider` empty **and** the request's category is one of theirs **and** at least one of their providers is active | `viewer: "provider"` |
| — | None | | **`403 forbidden`** |

Five things this table decides, each with a reason:

- **Rule 1 ignores roles entirely.** A resident who also holds `proveedor` reads
  their own request of a category they do not attend, complete. Same reasoning
  as the listing, and the same reason no query here is tagged `node_access`.
- **Rule 2 is status- and category-independent.** Whoever has a live offer needs
  to see what became of it. Losing the detail the moment it is awarded to
  somebody else would leave an offer in their app with nothing behind it and a
  `403` as the only explanation.
- **Rule 2b goes before rule 3 and never inside it.** "Awarded to me" is the
  exact opposite of rule 3's "unawarded", so it cannot be one more clause of the
  same rule. It reads the raw `field_assigned_provider` column the detail
  already projects, so it costs **no extra query**, and it is **strictly
  additive**: it can only turn a `403` into a `200`, never the reverse.
- **Rule 3 checks the status AND both award keys**, and reads them **raw**. A
  request left in `offered` with `field_assigned_offer` already filled in — an
  incoherent datum nothing prevents today — stops being biddable, which is the
  safe reading. Reading the *resolved* reference instead would make an award
  pointing at an unpublished offer look like no award at all and reopen the
  request to every provider of the category.
- **Being "a provider" is a `provider` node pointing at you** through
  `field_provider_users`, never the `proveedor` role on your account. A user
  holding the role with no provider node behind them is `403`.

> **A `direct` request is `403` for every provider EXCEPT the one it was
> awarded to.** `myapi_provider_role_broadcast_statuses()` — what the back
> office does *not* hide from a provider — includes `direct`; **rule 3 does
> not**. They are two policies over the same datum and they have to be able to
> diverge: a `direct` request is born with a provider already chosen, which is
> exactly what "unawarded" excludes. So a provider of the category who was
> **not** chosen still gets a `403`, and that is the point.
>
> **The chosen provider, on the other hand, reads it.** Until SPEC 98 they did
> not: not being the requester, not having offered (there was no bidding round)
> and not eligible under rule 3 (the request is already awarded), the provider
> of a direct award could not open the detail of their own job. SPEC 89
> documented that as a known gap waiting for *"the spec that knows what
> relation the provider of a `direct` has with the request"*; **rule 2b is that
> answer**, and it closes the gap for `assigned`, `closed` and `cancelled`
> alike. Equalising rule 3 with the broadcast catalogue still breaks the suite —
> that is a different question, and still the wrong fix.
>
> The rule the listing uses and the rule the detail uses are now **the same
> rule written twice**, once as a set (to paginate) and once as a per-row
> decision (to load one). If it appears in
> [`GET /api/v1/service-requests/provider`](service-request-provider.md), it can
> be opened; a test walks the whole status × award × category × offered matrix
> to hold the two in step.

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
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 55, "name": "A-301" },
      "offers_count": 2,
      "assigned_offer": null,
      "assigned_provider": null,
      "created": "2026-08-14T09:12:33",
      "desired_start": "2026-08-19T08:00:00",

      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
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
          "created": "2026-08-15T18:40:02",
          "amount_type": "fixed",
          "valid_until": "2026-08-22T23:59:00",
          "available_from": "2026-08-17T08:00:00",
          "duration": { "value": 3, "unit": "hours" },
          "includes": "Mano de obra, desplazamiento y sellado.",
          "excludes": "El calentador de repuesto, si hiciera falta.",
          "tax_included": true,
          "warranty_days": 90,
          "requires_visit": false
        },
        {
          "id": 45,
          "provider": { "id": 7, "name": "Plomería Rivas", "logo": null },
          "amount": null,
          "message": "Necesito ver la instalación antes de dar precio.",
          "status": "sent",
          "created": "2026-08-15T11:03:17",
          "amount_type": "on_site_quote",
          "valid_until": null,
          "available_from": null,
          "duration": null,
          "includes": null,
          "excludes": null,
          "tax_included": null,
          "warranty_days": null,
          "requires_visit": true
        }
      ],
      "transactions": [
        {
          "id": 512,
          "status": "open",
          "status_date": "2026-08-14T09:12:00",
          "comment": "Hemos recibido su solicitud. Los proveedores de la categoría podrán enviarle ofertas y se le notificará cualquier novedad.",
          "created": "2026-08-14T09:12:33"
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

Eight, on top of the listing's eleven. `unit` is **not** among them any more: it
is the listing's, and one of the **three** of the eleven the detail changes — the
other two are `assigned_offer` and `assigned_provider`, which travel whole here
(see [The award, widened](#the-award-widened-assigned_provider-and-assigned_offer)).
No key is added, dropped or moved by any of the three: what changes is content.

| Key | Source | Nullable | Notes |
|-----|--------|:--------:|-------|
| `viewer` | the access rule | No | `"requester"` or `"provider"`. Never `null`: a reader with no role already got a `403`. |
| `requester` | `field_requester` → `users` | No¹ | `{id, name}`. The name is `field_nombre + field_apellidos`, or `users.name` when **either** is missing — never a hybrid. The same rule `/api/v1/units` uses, shared in `includes/myapi.user.inc`. |
| `condominium` | `field_condominium` → `node.title` | No¹ | `{id, name}`. Here the name **is** the node title, unlike the unit. |
| `images` | `field_images` → `file_managed` | No | **Always an array**, empty when there are none. `{id, url, filename}` each, in the `delta` order the operator uploaded them. |
| `attachment` | `field_attachment` → `file_managed` | **Yes** | `{id, url, filename}` or `null`. Cardinality 1. |
| `closed_at` | `field_closed_at` | **Yes** | `Y-m-d\TH:i:s`, `null` while the request is not closed. |
| `offers` | `service_offer` via `field_request` | No | **Always an array**, empty when there are none — every `direct` request among them. |
| `transactions` | `service_transaction` via `field_request` | No | **Always an array**, empty when there are none. The timeline, oldest first — see below. |

¹ Required on the bundle; the `LEFT JOIN`s leave them `NULL` if somebody deleted
the row by hand, and the serialiser answers `null` instead of breaking.

### The award, widened: `assigned_provider` and `assigned_offer`

In the **detail** — and in every response that answers the detail's object: the
`201` of the creation, the `200` of the edition, of the cancellation, of the
closing and of `PUT /api/v1/service-offers/{id}/accept` — the two award keys are
not a name and a status. They are the two objects the app already knows how to
paint:

| Key | What it is | Identical to |
|-----|-----------|--------------|
| `assigned_provider` | The **provider card**: the same **eight keys, in the same order**, that [`GET /api/v1/providers`](provider.md) answers per provider — `id`, `logo`, `title`, `categories`, `rating_avg`, `rating_count`, `short_description`, `hourly_rate`. | An item of the marketplace listing |
| `assigned_offer` | The **whole offer**: the same **fifteen keys** described in [Each offer](#each-offer-fifteen-keys-always-in-this-order). | An item of `offers` in this very response |

Both are still `null` when there is no award, and still go `null`
**independently** — a `direct` request has a provider and no offer.

> ⚠️ **`assigned_provider.name` no longer exists: the card calls it `title`.**
> The card is the listing's card, byte for byte, and adding a `name` beside
> `title` would make it a *different* card — exactly the divergence serving one
> object from one builder prevents. The listings
> (`GET /api/v1/service-requests` and `GET /api/v1/service-requests/provider`)
> are **unchanged** and still answer `{id, name}` there.

**`assigned_offer` costs no extra query, and it cannot disagree with the list.**
The awarded offer of a request *is* one of that request's offers, so the object
is taken from `offers` (or from `my_offers` on the provider's route) and not read
again.

**The provider's card is read even when their licence has expired.** The
marketplace only *lists* active providers; an awarded provider whose licence
lapsed last week is still the one doing the job, and answering
`assigned_provider: null` there would tell the resident nobody was awarded. The
card is `null` in exactly one case, the same as before: the referenced provider
node was **deleted or unpublished**.

```json
{
  "assigned_offer": {
    "id": 45,
    "provider": { "id": 7, "name": "Plomería Rivas", "logo": "https://.../sites/default/files/logo-rivas.png" },
    "amount": 150.5,
    "message": "Puedo pasar el jueves por la mañana.",
    "status": "selected",
    "created": "2026-08-15T11:03:17",
    "amount_type": "fixed",
    "valid_until": "2026-08-22T23:59:00",
    "available_from": "2026-08-17T08:00:00",
    "duration": { "value": 3, "unit": "hours" },
    "includes": "Mano de obra, desplazamiento y sellado.",
    "excludes": "El calentador de repuesto, si hiciera falta.",
    "tax_included": true,
    "warranty_days": 90,
    "requires_visit": false
  },
  "assigned_provider": {
    "id": 7,
    "logo": "https://.../sites/default/files/logo-rivas.png",
    "title": "Plomería Rivas",
    "categories": [ { "id": 12, "code": "plumbing", "name": "Plomería" } ],
    "rating_avg": 4.8,
    "rating_count": 31,
    "short_description": "Plomería y gas, 24 h.",
    "hourly_rate": 25.5
  }
}
```

#### The one reader whose `assigned_offer` is redacted

A provider who **bid and lost** keeps reading the request — rule 2 above, and an
offer with a `403` behind it is an offer with no explanation — but their `offers`
is trimmed to their own, and the winning quote is not in it. Widening the key out
of a list they are not in would hand a competitor's price to the bidder who lost
against it, which is precisely what that trim exists to prevent.

So for that reader, and **only** for that reader, `assigned_offer` carries the
same fifteen keys with the thirteen that describe the quote empty:

```json
{
  "assigned_offer": {
    "id": 45,
    "provider": null,
    "amount": null,
    "message": "",
    "status": "selected",
    "created": null,
    "amount_type": null,
    "valid_until": null,
    "available_from": null,
    "duration": null,
    "includes": null,
    "excludes": null,
    "tax_included": null,
    "warranty_days": null,
    "requires_visit": false
  }
}
```

`id` and `status` are the two that always carry — they were already public in the
two-key shape this replaces — and the nulls are exactly the ones an offer stored
before SPEC 100 already answers, so no client meets a shape it has not met
before. **A redaction is a content, never a shape.**

`assigned_provider` is **not** redacted for that reader: those eight keys are
what `GET /api/v1/providers` already shows to everybody holding a token. The
loser learns *who* won, never *for how much*.

#### Each offer: fifteen keys, always, in this order

The **first six are SPEC 89's, unchanged** — same names, same types, same order.
SPEC 100 added nine after them and moved none, which is why a client written
against the six-key shape keeps working untouched. `amount_type` would read
better next to `amount`; order is contract here, and this table is where the
legibility is paid for instead.

| # | Key | Source | Nullable | Notes |
|--:|-----|--------|:--------:|-------|
| 1 | `id` | the offer's `nid` | No | JSON integer. |
| 2 | `provider.id` / `.name` | `field_provider` → `node.title` | **Yes** (the whole object) | An unpublished or deleted provider leaves `provider: null` and **the offer stays in the list** — dropping it would make the list disagree with `offers_count`. |
| 2 | `provider.logo` | `field_logo` → `file_create_url()` | **Yes** | An absolute, **direct** URL: `field_logo` is `public://` (SPEC 85), unlike the request's own images. Never an `api/v1/...` path. |
| 3 | `amount` | `field_offer_amount` | **Yes** | **A number or `null`**, never `"95.50"`. `null` means *no price yet* — an `on_site_quote` carries none, and the price of any offer can still be settled in the chat. `0` is a price somebody offered. |
| 4 | `message` | `field_offer_message` | No¹ | As stored, with its line breaks, exactly like `description`. |
| 5 | `status` | `field_offer_status` | No¹ | `sent` / `selected` / `rejected` / `withdrawn`. |
| 6 | `created` | `node.created` | No | `Y-m-d\TH:i:s`. |
| 7 | `amount_type` | `field_offer_amount_type` | **Yes** | `fixed` / `estimate` / `hourly` / `on_site_quote` — how the number is to be read. **`null` on every offer stored before SPEC 100**, and nothing will ever backfill it: deducing a type from the amount would put in a provider's mouth a statement they never made. |
| 8 | `valid_until` | `field_offer_valid_until` | **Yes** | `Y-m-d\TH:i:s`. **Informative only: no process expires an offer by this date.** A lapsed offer stays `sent` until somebody awards or the request is cancelled. |
| 9 | `available_from` | `field_offer_available_from` | **Yes** | `Y-m-d\TH:i:s`. When the provider could start. |
| 10 | `duration` | `field_offer_duration` + `field_offer_duration_unit` | **Yes** | `{"value": 3, "unit": "hours"}` — **one object or one whole `null`**, never `{"value": null, "unit": null}`. The two columns are coupled: one without the other means nothing. `unit` is `hours` or `days`. |
| 11 | `includes` | `field_offer_includes` | **Yes** | What the quote covers, as stored, with its line breaks. |
| 12 | `excludes` | `field_offer_excludes` | **Yes** | What it does not. |
| 13 | `tax_included` | `field_offer_tax_included` | **Yes** | **Three-valued**: `true`, `false`, or `null` for *the provider never said*. A `null` is not a `false`. |
| 14 | `warranty_days` | `field_offer_warranty_days` | **Yes** | JSON integer. `0` is a declaration — *no warranty* — and not an absence. |
| 15 | `requires_visit` | `field_offer_requires_visit` | **No** | **Never `null`**: the absence of the claim *"I need to visit first"* reads as `false`. |

An optional text that is empty is served as `null` and never as `""`. `message`
is the exception and **is** `""` when empty, because it is required: an empty
one there is a corrupt row, not an absence.

**Nine of the fifteen are `null` on every offer stored before SPEC 100.**
`myapi_update_7035()` creates the ten columns and backfills nothing, so a
historic offer answers the six keys it always answered plus eight nulls and one
`false`. See [services-install.md](services-install.md).

**Where these values come from:** `POST /api/v1/service-requests/{id}/offers` —
see [service-offer.md](service-offer.md). The object this endpoint serves and
the one that `201` answers come out of the same serialiser, so they are byte for
byte the same.

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

### The timeline (`transactions`)

The request's `service_transaction` entries: what happened to it and when. Every
request created since SPEC 92 is born with one — the acknowledgement, carrying
the status it was created with. **Offering** adds its own since SPEC 100, when
an offer is the first one and moves the request `open → offered`; **quoting a
`direct`** adds one since SPEC 101, *without* the status moving; cancelling adds
its own since SPEC 95. Awarding and closing still add none. See
[service-transaction.md](service-transaction.md) for who writes them.

**Each transaction:**

| Key | Source | Nullable | Notes |
|-----|--------|:--------:|-------|
| `id` | the transaction's `nid` | No | JSON integer. |
| `status` | `field_request_status` | **Yes**¹ | The **raw catalogue key** — `open`, `direct`, `offered`, `assigned`, `closed`, `cancelled` — exactly like the request's own `status`. No translated label: the catalogue is the client's. |
| `status_date` | `field_status_date` | **Yes**¹ | `Y-m-d\TH:i:s`. **The stored value with a `T` in it, with no timezone conversion** — see the note below. |
| `comment` | `field_comment` | **Yes** | Plain text, with its line breaks, no `format`. `null` — never `""` — when the entry carries no comment. |
| `created` | `node.created` | No | `Y-m-d\TH:i:s`, in the site's timezone. |

¹ Required on the bundle; the `LEFT JOIN`s answer `null` if somebody deleted the
field row by hand, and the entry stays in the timeline instead of vanishing from
it.

**Five keys, not the seven a claim's transaction has.** There is no `images` and
no `attachment`: `service_transaction` has no instance of either field and never
had one. Serving them as a fixed `[]` and `null` would be two keys that always
lie, and a key that can never hold content teaches the client to trust a hole.
The day the fields exist, the keys can appear; taking them away afterwards would
not be possible.

**`status_date` is not converted, and that is deliberate.** `field_status_date`
was created with `tz_handling = 'none'`: what is stored is a **naive local
time**, not a UTC instant. Running it through a timezone conversion would shift
it by the server's zone and answer an hour nobody wrote. `created` **is** a real
timestamp and **is** converted — two date columns, two rules, on purpose. It is
the same distinction `reception_date` and `created` make in
[claim.md](claim.md).

> ⚠️ **An entry does not always mean the status changed.** Since SPEC 101 there
> is exactly one that does not: when the awarded provider of a `direct` request
> [sends their quote](service-offer.md#quoting-a-direct-request), an entry is
> written whose `status` **repeats** the `direct` the request already had. The
> status column is telling the truth — nothing moved — and the **`comment`** is
> what distinguishes the entry: *"&lt;company&gt; envió su presupuesto."* next to
> the acknowledgement's own text. A client that renders the status key as the
> headline will show *Directa* twice; **render the comment**, and treat the
> status as data.

**Order:** `status_date` ascending, tie-broken by `id` ascending. It is a
timeline, read oldest first. The tie-break is not decoration — two entries of
the same minute, which happen when an operator registers two changes in a row,
would otherwise come out in whatever order the database chose.

**Both readers get the whole timeline**, comments included. The provider of the
category is not trimmed here: the comments that exist today are addressed to the
resident, and there is no field marking an entry as internal. The day there are
internal notes, what that will need is a field that distinguishes them.

**Unpublished entries do not appear.** Unpublishing a `service_transaction` from
the back office removes it from the app — that is what unpublishing means — and
the resident is left with a timeline that has a gap nobody explains. Worth
knowing before pressing the button.

**Requests created before SPEC 92 answer `"transactions": []`,** and no row is
invented for them: there is **no backfill**. A made-up entry would carry an
acknowledgement nobody issued and the request's *current* status rather than the
one it was born with. The app must treat the empty array as a normal state,
exactly as it treats `offers: []`.

**Transactions are not paginated and cannot be filtered.** They all travel,
always — no `?page`, no `?limit`. A request receives units of transactions.

**Notes**
- The detail costs **six content queries**, fixed — the request row, the
  requester's name, the images, `offers_count`, the offers, and the timeline —
  plus the token lookup. None of them grows with the number of images, of offers
  or of transactions: a request with twenty entries costs exactly what one with a
  single entry costs. A provider reader adds the role questions of
  `includes/myapi.provider_role.inc`, all statically cached.
- `offers_count` comes from the **aggregate query**, never from
  `count(offers)`: the provider gets a trimmed list and the full total.
- The ten first keys are **byte for byte** what `GET /api/v1/service-requests`
  answers for the same request — the same serialiser produces them.
- No query here carries `->addTag('node_access')` either, for the reason written
  in the listing's notes. A unit test fails if it appears.
- Everything about `description`, the required category, its `code` and its
  orphan-tid diagnosis, above, applies unchanged to the detail — including that
  an orphan category makes the request answer `404` here and disappear from the
  listing there. The two views agree, which is the point. `category` is one of
  the keys the provider receives **whole**: deciding whether to bid is exactly
  what they need it for.

**Possible errors**
| Code | error_code | When |
|------|------------|------|
| 401  | `missing_authorization` | `Authorization` header absent or malformed. |
| 401  | `invalid_token` | Token unknown, revoked, expired, or its user is missing or blocked. |
| 403  | `forbidden` | The request exists and the reader is neither its requester nor a provider who may still bid on it. |
| 404  | `not_found` | No such request (missing, unpublished, another bundle, orphan category), **or** an `{id}` that is not a positive integer — the latter without any query. |
| 405  | `method_not_allowed` | `PUT`, `DELETE` or `PATCH`. Answered before the token and before any query. `POST` no longer answers `405` here since SPEC 96 — it edits the request. |

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

### The `private:///` regression and its repair (SPEC 91)

Files uploaded **from the app** between SPEC 77 and SPEC 91 were recorded in
`file_managed.uri` as `private:///<name>` — three slashes, because
`myapi_node_files_save()` appended a `/` to what `file_field_widget_uri()`
already returns ending in one (neither `field_images` nor `field_attachment`
declares a `file_directory`, so that function answers exactly `private://`).

The bytes were never misplaced — every stream wrapper trims the extra slash
before touching the disk. What broke was the string, and only for the reader
that starts from a URI: `GET /api/v1/service-requests/%/files/%` looks the file
up by **fid** and kept working, while `hook_file_download()` matches
`file_managed.uri` as a **string**, missed the row, answered `NULL`, and left the
back office with a **`403` on every thumbnail** — for every role, `uid 1`
included.

`myapi_node_files_save()` now passes the destination untouched, and
`myapi_update_7034()` normalizes the rows already written. Only
`file_managed.uri` is rewritten; the fid does not change, so the field tables and
`file_usage` are already correct and nothing moves on disk. Same fix, same
update, for claims — see [claim.md](claim.md).

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

## POST /api/v1/service-requests

Creates a `service_request` node for the authenticated resident, in their own
unit. The request is `multipart/form-data` (text fields plus files), not JSON —
same contract as `POST /api/v1/claims`. The condominium is **derived from the
unit**, never sent by the client. An `assigned_provider_id` may be sent to
award the request **directly**, with no bidding round, to a provider eligible
for the request's category — the request is then born in `direct` instead of
`open`.

The response is the **same nineteen-key object**
[`GET /api/v1/service-requests/{id}`](#get-apiv1service-requestsid) returns,
`viewer` fixed to `"requester"` (the creator is always the requester),
`offers: []` and `offers_count: 0` (nothing can have offered on a node that did
not exist a moment ago), `closed_at: null` — and `transactions` holding the
initial entry, which **does** already exist.

**Creating a request notifies the providers** (SPEC 109): every **active**
provider of the request's category when it is born `open`, only the awarded one
when it is born `direct`, through inbox, push and email — plus a detail email to
the `backend` role. The `201` below is unchanged by it, and so is everything
else on this page: the trigger is best-effort and its failures never reach the
response. The full contract (audiences, texts, what a provider is never told) is
in [service-request-notifications.md](service-request-notifications.md).

Out of scope of this endpoint: editing, cancelling, closing or awarding a
request already created — the first two have routes of their own, the other two
do not exist yet; offering on a request, which has its own route
([service-offer.md](service-offer.md)); notifying anybody of anything other than
the creation, and notifying a request created outside this endpoint (the back
office, drush, an import) at all;
restricting a `direct` request's visibility to the provider it names — a
provider not of the chosen category still cannot see it, but one of the same
category the resident did **not** pick still can, exactly like every other
`open` or `offered` request of that category (SPEC 87). Each of these, if it
arrives, is its own spec.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |
| Content-Type | `multipart/form-data` |

**Request body (form-data fields)**
| Field | Required | Type | Rule |
|-------|----------|------|------|
| `title` | **yes** | string | ≤ 255 chars (`node.title` is `varchar(255)`). Otherwise → `422 invalid_field`. |
| `unit_id` | **yes** | int (nid) | Integer > 0, and one of the units the authenticated resident owns or occupies (`myapi_unit_related_nids($uid)`). Bad format → `422 invalid_field`; a foreign unit, or a resident with no unit at all → `403 unit_access_denied`. **The condominium is derived from this unit** — see below, `condominium_id` is not a field of this request. |
| `category_id` | **yes** | int (tid) | Integer > 0, and a real term of the `service_category` vocabulary. Otherwise → `422 invalid_field`. The `id` to send is the one `/api/v1/service-categories` returns. |
| `description` | **yes** | string | Non-empty after `trim()`. Otherwise → `422 invalid_field`. No maximum (`text_long`). |
| `desired_start` | **yes** | string `Y-m-d H:i` | Must parse with `strtotime()` into an instant **strictly later** than the server's clock at the moment of the request — the exact same second is rejected too, not only the past. Either failure → `422 invalid_field`. |
| `assigned_provider_id` | no | int (nid) | See **Direct award** below. |
| `images[]` | no | file[] | Up to 5 files. See file rules below. |
| `attachment` | no | file | At most 1 file. See file rules below. |

`condominium_id`, `field_requester`, `field_assigned_offer` and `field_closed_at`
are **not fields of this request**: none is ever read from the body, sending
them has no effect because there is no such input to send.

### The condominium is derived, never sent

`field_condominium` is resolved from `unit_id`'s own `field_condominio` — the
same table `myapi_condominium_related_nids()` reads, but for **one** unit
instead of every unit of the resident. This is why `condominium_id` is absent
from the request body above: sending one would be accepted silently and ignored,
so the field does not exist at all rather than existing and lying.

> **A unit with no `field_condominio` row** (a data inconsistency nothing today
> prevents) has no condominium to derive. There is no client-facing fix for it
> — it is not a value the resident typed wrong — so the endpoint answers
> `500 server_error` and logs the case with `watchdog()`, instead of creating a
> request with no condominium. Diagnosed the same way the orphan category tid is
> in the listing's notes above:
> ```sql
> SELECT v.nid FROM node v
> LEFT JOIN field_data_field_condominio fc ON fc.entity_id = v.nid AND fc.entity_type = 'node' AND fc.deleted = 0
> WHERE v.type = 'vivienda' AND fc.entity_id IS NULL;
> ```

### Direct award: `assigned_provider_id`

Sending a provider is a **second, complete validation**, not a simple optional
field. All four conditions must hold, or the request answers a single
`403 provider_not_eligible` — the four say the same thing to the client: *this
provider cannot receive this request right now*.

| # | Condition |
|---|-----------|
| 1 | `assigned_provider_id` is the `nid` of a **real** node of bundle `provider`. |
| 2 | That node is **published** (`node.status = 1`). |
| 3 | Its licence has **not expired** — the same rule `GET /api/v1/providers` and the marketplace use. |
| 4 | `category_id` (the one already validated above) is among the provider's own `field_categories`. |

`assigned_provider_id` not even a positive integer is `422 invalid_field`, like
any other malformed field — that is the **one** exception to the single `403`.

The provider is validated **before** any file touches the filesystem: a request
rejected for its provider never leaves an orphaned upload behind.

**What a direct award changes**
| Field | `open` (no provider, or none sent) | `direct` (eligible provider) |
|-------|---|---|
| `status` | `open` | `direct` |
| `assigned_provider` | `null` | `{ id, name }` |
| `assigned_offer` | `null` | `null` **at birth** — a `direct` is awarded to a company, not to an offer, and it has none yet. It stays `null` while the provider quotes, and is filled only if the resident later accepts that quote ([`PUT /api/v1/service-offers/{id}/accept`](service-offer.md), SPEC 107), which also moves the request to `assigned`. |
| `closed_at` | `null` | `null` |

**Images (`images[]`) and attachment (`attachment`)**
| Aspect | Images | Attachment |
|--------|--------|------------|
| Extensions | `jpg`, `jpeg`, `png` | `pdf`, `doc`, `docx`, `xls`, `xlsx` |
| Size | ≤ 3 MB each (inherited from the field instance, same limit `field_images`/`field_attachment` have carried since SPEC 77) | ≤ 3 MB |
| Count | Up to 5. A 6th file → `422 service_request_too_many_images`, none saved. | At most 1. Sending the input as `attachment[]` with two files → `422 service_request_too_many_attachments`, none saved. |
| Real MIME | Checked with `finfo`, derived from the field's own allowed extensions. Mismatch (e.g. a `.php` renamed to `.jpg`) → `422 invalid_file_type`. | Same check → `422 invalid_file_type`. |
| Rejected extension/size | `422 service_request_invalid_image` | `422 service_request_invalid_attachment` |
| Storage | Saved as **permanent** managed files under the field's configured `private://` directory, each with a `file_usage` row tied to the node. | Same. |

Nothing about extensions or sizes is hardcoded in the endpoint: it reads
`field_images` / `field_attachment`'s own Field API instance, the same
`includes/myapi.node_files.inc` helper `POST /api/v1/claims` uses — extracted by
this spec from what used to be a claims-only function, so both endpoints share
one implementation instead of two copies that could drift.

**All-or-nothing on files.** Any image or attachment that fails validation
aborts the whole request: every file already saved **in that same request**
(earlier valid images, if the attachment is what failed) is deleted before the
error response, and no node is created. There is no partial request with only
the valid files attached.

**Fields the server always sets, never the client**
| Field | Value |
|-------|-------|
| `node.uid` | `uid` of the Bearer token |
| `node.status` | `1` (published) |
| `field_requester` | `uid` of the Bearer token |
| `field_condominium` | Derived from `unit_id` — see above. |
| `field_request_status` | `'direct'` when `assigned_provider_id` was sent and passed all four conditions; `'open'` otherwise — including when the field was not sent at all. |
| `field_assigned_provider` | The validated provider's `nid`, or left empty. |
| `field_assigned_offer` | Always empty. |
| `field_closed_at` | Always empty. |

**An initial `service_transaction` IS created** — since SPEC 92, and not by this
endpoint: the `node_save()` fires `hook_node_insert()`, whose `service_request`
branch writes the first entry of the timeline copying the status the request was
just born with. It covers this endpoint, `node/add/service_request` and any
future path with one implementation, which is why not a single line of this
endpoint mentions transactions. See
[service-transaction.md](service-transaction.md).

**Success response (201)**

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 145,
      "title": "Fuga en el calentador",
      "description": "El calentador del baño principal gotea desde el lunes.",
      "status": "open",
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 55, "name": "A-301" },
      "offers_count": 0,
      "assigned_offer": null,
      "assigned_provider": null,
      "created": "2026-08-17T10:05:00",
      "desired_start": "2026-08-19T08:00:00",

      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [
        { "id": 210, "url": "https://.../api/v1/service-requests/145/files/210", "filename": "fuga.jpg" }
      ],
      "attachment": null,
      "closed_at": null,
      "offers": [],
      "transactions": [
        {
          "id": 512,
          "status": "open",
          "status_date": "2026-08-17T10:05:00",
          "comment": "Hemos recibido su solicitud. Los proveedores de la categoría podrán enviarle ofertas y se le notificará cualquier novedad.",
          "created": "2026-08-17T10:05:00"
        }
      ]
    }
  },
  "message": "Solicitud de servicio creada correctamente."
}
```

A request with a valid `assigned_provider_id` answers the same shape with
`"status": "direct"` and `assigned_provider` already filled in — and its initial
transaction carries `"status": "direct"` with the acknowledgement that matches.

**The `201` carries the timeline populated**, with the entry that was written
inside the very same `node_save()`. It is **byte for byte** what an immediate
`GET /api/v1/service-requests/{id}` answers for that request: the two responses
share the serialiser *and* the loader. `offers` and `offers_count` are the
opposite case — they are known to be empty at that instant, so they are set in
code and never queried.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | `Authorization` header absent or not a `Bearer <token>`. |
| 401 | `invalid_token` | Access token invalid, revoked, expired, or its user is missing/blocked. |
| 403 | `unit_access_denied` | `unit_id` is a positive integer the resident does not own or occupy, or the resident has no unit at all. Nothing is created. |
| 403 | `provider_not_eligible` | `assigned_provider_id` was sent and fails any of the four conditions above. Nothing is created, and nothing already uploaded in the request is saved — the provider is validated before any file. |
| 405 | `method_not_allowed` | Any method other than `GET` or `POST` on `/api/v1/service-requests`. **Creation is always on the collection**: a `POST` on `/api/v1/service-requests/{id}` does not create, it **edits** that request (SPEC 96), and `PUT`/`DELETE` on the item answer `405`. |
| 422 | `missing_field` | A required field is absent or empty (`@field` names it): `title`, `unit_id`, `category_id`, `description`, or `desired_start`. |
| 422 | `invalid_field` | `title` over 255 chars; `unit_id` or `category_id` or `assigned_provider_id` not a positive integer; `category_id` not a real term of the vocabulary; `description` empty after `trim()`; `desired_start` unparseable or not strictly in the future. |
| 422 | `service_request_too_many_images` | More than 5 files in `images[]`. Nothing is saved. |
| 422 | `service_request_invalid_image` | An image fails extension or size validation. All-or-nothing: any images already saved in the same request are deleted too. |
| 422 | `service_request_invalid_attachment` | The attachment fails extension or size validation. All-or-nothing: any images already saved in the same request are deleted too. |
| 422 | `service_request_too_many_attachments` | More than one file sent under `attachment`. Nothing is saved, and any images already saved in the same request are deleted too. |
| 422 | `invalid_file_type` | An image's or the attachment's real MIME (checked with `finfo`) does not match its extension. |
| 500 | `server_error` | `unit_id`'s own unit has no `field_condominio` row to derive a condominium from — a data inconsistency, not a client mistake. Logged with `watchdog()`. |

Validation runs in the order listed in **Request body** above (`title` →
`unit_id` → `category_id` → `description` → `desired_start` →
`assigned_provider_id` → `images[]` → `attachment`), and each check aborts
before anything is created or saved to disk. See [i18n.md](i18n.md) for the
translated `error`/`message` text.

**Example (direct award, with one image, no attachment):**
```bash
curl -i -X POST https://host/api/v1/service-requests \
  -H 'Authorization: Bearer <access_token>' \
  -F 'title=Fuga en el calentador' \
  -F 'unit_id=55' \
  -F 'category_id=12' \
  -F 'description=El calentador del baño principal gotea desde el lunes.' \
  -F 'desired_start=2026-08-19 08:00' \
  -F 'assigned_provider_id=7' \
  -F 'images[]=@fuga.jpg'
```

---

## POST /api/v1/service-requests/{id}

Lets the resident **correct their own service request**: the title, the
description, the desired date, the images and the attachment. Nothing else, and
only while the request is still `open` or `direct` and **nobody has offered on
it yet**.

The request is `multipart/form-data` (text fields plus files), not JSON — same
contract as the creation and as
[`POST /api/v1/claims/{id}`](claim.md#post-apiv1claimsid). This is a
**replacement and not a patch**: the three text fields travel on **every** call,
and a missing one is `422 missing_field` and never "leave it as it was".
Everything the request does not name is preserved untouched, because the endpoint
loads the node and overwrites only the fields the body carries.

> **The response does NOT carry `offers`, `offers_count` nor `transactions`.**
> It answers **sixteen** keys — the detail's nineteen minus those three — so
> **it does not replace the object of
> [`GET /api/v1/service-requests/{id}`](#get-apiv1service-requestsid)**. The app
> cannot swap the detail it has on screen for what this call returns: it has to
> **merge** the sixteen keys onto the object it already holds. See
> [The sixteen keys](#the-sixteen-keys-and-the-three-that-are-missing) below.

Out of scope of this endpoint, each of them its own spec if it ever arrives:
changing the `category_id` (it changes which providers see the request — that is
another request, not a correction) or the `unit_id` and with it the condominium
(same argument, and stronger: the condominium is the whole visibility scope);
`assigned_provider_id` — a `direct` request **is** editable, but only in what
the job *is*, never in **who** it was given to: moving the award to another
provider is a different action, with a different meaning for both providers;
**reordering** the images; editing from the provider, the operator or the
building admin (the back office already edits with Drupal's native form);
editing in `offered`, `assigned`, `closed` or `cancelled`, and editing an
`open` or `direct` request that has already received an offer; leaving any **trace** of the edit — no timeline entry, no
notification, no history of what changed; and any **concurrency control**
(`If-Unmodified-Since`, an `updated_at` in the body, or any other optimistic
lock): two simultaneous edits by the same resident, and the last one wins.

### Why `POST` on an item and not `PUT`

`PUT` is the semantically obvious verb and it is deliberately not used. **PHP
populates neither `$_POST` nor `$_FILES` on a `PUT`**: the `multipart/form-data`
body would arrive raw through `php://input` and the module would have to carry a
hand-written MIME parser — real code and real risk in a Drupal 7 install with no
security support, bought with nothing but a verb. There are files here, so the
decision is made by the language and not by taste. It is the same decision, for
the same reason, that `POST /api/v1/claims/{id}` took first; the reasoning is
written out in [claim.md](claim.md#why-post-on-an-item-and-not-put).

`PATCH` was not used either: no route of this module uses it, and introducing a
new idiom for one endpoint is a cost with no return. A route of its own
(`/{id}/edit`, in the style of `/{id}/cancel`) was also dropped: `cancel` has one
because it is a **named action** on a resource, while an edit is the writing of
the resource itself, and the item route already names it.

`PUT` and `DELETE` on this route still answer `405`. `DELETE` in particular is
**not** a synonym of the cancellation: a request is cancelled, never deleted, and
hiding that behind the verb would lose the timeline entry the cancellation
writes.

**Authentication:** required (Bearer access token) — and the caller must be the
request's `field_requester`.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |
| Content-Type | `multipart/form-data` |

**Request body (form-data fields)**
| Field | Required | Type | Rule |
|-------|----------|------|------|
| `title` | **yes** | string | Non-empty after `trim()`, ≤ 255 chars (`node.title` is `varchar(255)`). Over the limit → `422 invalid_field`. |
| `description` | **yes** | string | Non-empty after `trim()`. No maximum (`text_long`). |
| `desired_start` | **yes** | string `Y-m-d H:i` | Must parse with `strtotime()` into an instant **strictly later** than the server's clock at the moment of the request — the same second is rejected too. This applies **even to the value the request already has stored**: a date that has since passed cannot be saved again, which is correct, since the resident is being asked when they now want the job done. |
| `images[]` | no | file[] | New images, **added** to the ones already there. See the quota below. |
| `remove_image_ids[]` | no | int[] (repeated) | Each value a positive integer **and** a `fid` the request references **right now**. Anything else → `422 invalid_field` with `@field: remove_image_ids`. A repeated `fid` is treated once and is not an error. |
| `attachment` | no | file | **Replaces** whatever attachment there was. At most 1. |
| `remove_attachment` | no | string | `1` or `true` (case-insensitive) empties the attachment. **Ignored** when a new `attachment` comes in the same request — see below. |

`category_id`, `unit_id`, `condominium_id`, `assigned_provider_id`,
`assigned_offer_id`, `status` and `closed_at` are **not fields of this request**:
none is ever read from the body, and there is no such input to send. The
corresponding node fields — `field_category`, `field_unit`,
`field_condominium`, `field_requester`, `field_request_status`,
`field_assigned_provider`, `field_assigned_offer` and `field_closed_at` — come
out of an edit valuing exactly what they valued before, and so do `node.uid` and
`node.created`: **editing does not take ownership of the request**.

### Who may edit, and when

**Who: the `field_requester`, exactly.** Not `node.uid` — the same column the
detail and the cancellation already make authoritative, for the same reason: a
request an operator filed on a resident's behalf belongs to the **resident**, and
the operator is only the technical author. Not the rest of the unit either: a
service request is signed by one person. The **assigned provider**, who may
*read* the detail, gets `403` here; the **building admin** edits from the back
office with the native form.

**When: `open` or `direct`, and with zero offers.** Both conditions, and both
have to hold:

| # | Condition | Why |
|---|-----------|-----|
| 1 | `field_request_status` is exactly `open` **or** `direct` | The two **pre-commitment** statuses: `open` waits for whoever bids, `direct` waits for the one provider the resident named, and **neither carries an accepted offer** — a direct award adjudicates no offer at all (SPEC 87). A comparison against the two literals, not a question to the transition graph: editing moves no status, so there is no transition to consult — and a corrupt or empty status is neither of the two, which is what makes it a `409` and never a `500`. |
| 2 | The request has **zero** published offers | Before anybody has priced the statement of the job, changing it harms nobody; afterwards it does. It applies to `direct` too, and there it now has a door of its own: since SPEC 101 the awarded provider can [send their quote through the API](service-offer.md#quoting-a-direct-request), and back-office offers on a `direct` were always possible. The rule reads the same in both. |

> **Editing a `direct` request does not touch its award.** `status` stays
> `direct` and `assigned_provider` still names the provider the resident chose,
> because `assigned_provider_id` is **not a field of this request body**. The
> resident corrects *what* the job is; *who* it was given to is not something
> this endpoint can move. That is precisely what makes editing a `direct`
> request safe.

**Any** published offer counts, whatever its status — `withdrawn` and `rejected`
included. A provider who read the statement and bid on it must not find it
changed, and having later withdrawn or been rejected does not un-read it.

> **The second condition is not redundant with the first**, however much the
> graph suggests it is. The graph says the first offer moves `open → offered`,
> and since SPEC 100 one path does execute it —
> [`POST /api/v1/service-requests/{id}/offers`](service-offer.md) moves the
> request itself — but **that is the endpoint's doing, not the node's**:
> `hook_node_insert()` and `hook_node_update()` still have no `service_offer`
> branch, so an offer created from the back office leaves the request in `open`
> with offers hanging off it. This condition covers that hole, which is why it
> counts offers instead of trusting the status.
>
> **And since SPEC 101 it covers a second one, by API and not by back office.**
> The awarded provider of a `direct` can now
> [quote it](service-offer.md#quoting-a-direct-request), and that quote **does
> not move the status** — a `direct` stays `direct` on purpose. So a request in
> `direct` with one offer on it is now an ordinary, reachable state, and the
> only thing that closes editing on it is this count. A condition that trusted
> the status would leave the resident rewriting the job **after** their provider
> had priced it.

Both failures answer the **same** `409 service_request_not_editable`. For the
resident the outcome is identical — they cannot edit — and two codes would force
the app to keep two messages for one screen.

### Images: the quota is what survives the removals

`field_images` holds 5. What decides how many new ones fit is what is left
**after** the removals:

```
kept     = count(current fids) − count(remove_image_ids)
max_new  = max(0, 5 − kept)
```

So a request that deletes three and uploads three is valid; one that uploads six
without deleting anything is not. Over the quota → `422
service_request_too_many_images`, and **nothing** is saved.

The surviving images keep their current **delta order** and the new ones go at
the **end**; the list is reindexed without holes. Reordering is not possible —
it needs the client to send the whole order and the server to validate it
against what is there, which is another contract. Every image may be removed: a
request with no photos is valid.

`remove_image_ids[]` is validated **against what the request references right
now**, before a single file touches the disk. A `fid` of another request, of a
claim, or of nothing at all fails the same way — `422 invalid_field` — which is
what stops a stray id from becoming a way to probe other people's files.

### The attachment: replaced, emptied, or left alone

`field_attachment` has cardinality 1, so there are exactly three outcomes:

| The request carries | Result |
|---------------------|--------|
| A new `attachment` | It **replaces** the previous one, which is deleted from `file_managed` and from disk. |
| `remove_attachment=1` (or `true`) and no `attachment` | The field is emptied and the file deleted. |
| Neither | The attachment stays **exactly** as it was. |

**`remove_attachment` is ignored when a new `attachment` arrives in the same
request.** Uploading one already replaces the previous file, so the outcome with
and without the flag is identical; a `422` would reject a request whose meaning
is unambiguous. Any value other than `1`/`true` leaves the attachment alone —
`yes` is not a synonym.

### All-or-nothing on files, and the deletions come last

Any image or attachment that fails validation aborts the whole request: every
file already saved **in that same request** is deleted before the error
response, and the node is **not** saved — the request keeps its text and its
original files. There is no partial edit with only the valid files attached.

The files that **left** the request — the removed images and the attachment that
was replaced or dropped — are deleted for real (`file_usage_delete()` +
`file_delete()`, since they live in `private://` and nothing else points at
them) **after** the `node_save()`, never before. That order is what guarantees
no file the node still references is ever destroyed. A `fid` that no longer
loads is skipped in silence: the goal state is already true.

**No database transaction**, the same decision the cancellation and the claim
took: a `node_save()` with the Field API and its hooks inside an explicit
transaction is a known source of deadlocks in Drupal 7. The only step that can
be left half done is that final deletion, and its consequence is a dead file on
disk that nothing references any more.

### The edit leaves no trace

**No timeline entry.** `hook_node_update()` has no `service_request` branch, so
the `node_save()` of an edit creates no `service_transaction`: `transactions` in
a later `GET` holds exactly the same elements as before. That is a decision, not
an omission — the timeline records **status changes**, and an edit is not one.

**No notification and no email** to anybody — not even to the provider of a
`direct` request whose statement just changed. The marketplace does have a
notifier since SPEC 109, but it fires on **creation only**
([service-request-notifications.md](service-request-notifications.md)); every
other event of the lifecycle, this one included, is still silent — and
`field_request_status` does not move. What does change is
`{node}.changed`, which is the `node_save()` doing its job.

The endpoint is **not idempotent and does not need to be**: two edits in a row
both succeed, and the second simply writes what it was given.

### The sixteen keys, and the three that are missing

The `200` answers the detail's object **minus `offers`, `offers_count` and
`transactions`**. The gate has just proved there is no offer and the timeline is
where it was, so the response neither queries nor repeats them:

```
id · title · description · status · category · unit · assigned_offer ·
assigned_provider · created · desired_start · viewer · requester ·
condominium · images · attachment · closed_at
```

The sixteen come out of the **same serialiser** that serves the `GET`, so they
cannot drift from it: they are byte for byte what an immediate
`GET /api/v1/service-requests/{id}` answers for those same keys. `viewer` is
always `"requester"` — the access check proved whoever got here is the
`field_requester` — and `status` is whatever the request already had, `"open"`
or `"direct"`, because editing does not move the status. A `direct` request
answers the same sixteen keys with `assigned_provider` filled in — and filled in
as the **whole provider card**, exactly as the detail answers it (see
[The award, widened](#the-award-widened-assigned_provider-and-assigned_offer)):
an edit does not narrow what the response says about the award. `images` is
always an array, empty when there are none and never `null`; `attachment` is
`null` when there is none, never `{fid: null}`.

**Success response (200)**

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 145,
      "title": "Fuga en el calentador del baño principal",
      "description": "Sigue goteando, y ahora también por la tubería de abajo.",
      "status": "open",
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 55, "name": "A-301" },
      "assigned_offer": null,
      "assigned_provider": null,
      "created": "2026-08-17T10:05:00",
      "desired_start": "2026-08-21T08:00:00",
      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [
        { "id": 211, "url": "https://.../api/v1/service-requests/145/files/211", "filename": "tuberia.jpg" }
      ],
      "attachment": null,
      "closed_at": null
    }
  },
  "message": "Solicitud actualizada correctamente."
}
```

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | `PUT`, `DELETE` or `PATCH` on this route. Answered **before** the token and before any query, so it needs neither an `Authorization` header nor the request to exist. |
| 404 | `service_request_not_found` | `{id}` is not a positive integer (answered with **no query at all**, not even the token's); or the request does not exist, is of another bundle, is unpublished, or its category term was deleted. The four are indistinguishable on purpose. |
| 401 | `missing_authorization` | `Authorization` header absent or not a `Bearer <token>`. |
| 401 | `invalid_token` | Access token invalid, revoked, expired, or its user is missing/blocked. |
| 403 | `service_request_forbidden` | The request exists and the caller is not its `field_requester`. Includes the assigned provider — who may *read* it — and the building admin. A request whose `field_requester` is empty answers `403` too: nobody owns it, so nobody edits it. |
| 409 | `service_request_not_editable` | The status is neither `open` nor `direct`, or it is one of the two and the request already has at least one offer. |
| 422 | `missing_field` | `title`, `description` or `desired_start` absent or empty after `trim()`. `@field` names which. |
| 422 | `invalid_field` | `title` over 255 chars; `desired_start` unparseable or not strictly in the future; `remove_image_ids[]` carrying a value that is not a positive integer or a `fid` the request does not reference. `@field` names which. |
| 422 | `service_request_invalid_image` | An image fails extension or size validation. All-or-nothing: nothing is saved. |
| 422 | `service_request_too_many_images` | More images sent than the quota above allows. Nothing is saved. |
| 422 | `service_request_invalid_attachment` | The attachment fails extension or size validation. Any images already saved in the same request are deleted with it. |
| 422 | `service_request_too_many_attachments` | More than one file sent under `attachment`. Same cleanup. |
| 422 | `invalid_file_type` | An image's or the attachment's real MIME (checked with `finfo`) does not match its extension. |

**Six error codes and the `200`, and no other status is reachable.** There is no
`500` on this route: nothing here derives a value that could be missing, and the
`404` is resolved **before** anything is written, so an unserialisable request is
turned away instead of being edited and then answered with a degraded body.

The checks run in this order, and every one of them aborts before anything is
written or saved to disk: **`{id}` → token → the request → who is asking → the
gate → the text fields → `remove_image_ids[]` → the files**. So a request that
is somebody else's **and** not editable answers `403` and not `409`, and a
request with garbage in its body answers the access or status error first. See
[i18n.md](i18n.md) for the translated `error`/`message` text.

**Example (new title and description, one image added, one removed, attachment
dropped):**
```bash
curl -i -X POST https://host/api/v1/service-requests/145 \
  -H 'Authorization: Bearer <access_token>' \
  -F 'title=Fuga en el calentador del baño principal' \
  -F 'description=Sigue goteando, y ahora también por la tubería de abajo.' \
  -F 'desired_start=2026-08-21 08:00' \
  -F 'images[]=@tuberia.jpg' \
  -F 'remove_image_ids[]=210' \
  -F 'remove_attachment=1'
```

---

## PUT /api/v1/service-requests/{id}/cancel

Cancels a service request. The resident who filed it leaves it in `cancelled`,
one timeline entry is written, and **every live offer on it is rejected**.

This is the first write this module does on a request that already exists, and
the first status transition of the marketplace with an endpoint of its own.
`cancelled` is **terminal**: there is no way back from it, here or anywhere
else.

**Only the requester cancels.** Not the rest of the unit, unlike a payment: a
service request is signed by one person and the household does not inherit the
right to pull it. Not the assigned provider — *"I quit this job"* is another
action, with another actor and probably another target status. Not a building
administrator through this API either: they already cancel from the back office,
with a form and a mandatory comment.

**Not idempotent, on purpose.** A second call on an already-cancelled request
answers `409`, not `200`: it tells the resident their action did nothing, which
is the truth, and it is what stops a duplicate entry from landing on the
timeline.

Out of scope of this endpoint: **awarding** an offer, **creating** offers,
**reopening** a cancelled request, and cancellation by the provider. Each of
these, if it arrives, is its own spec. **Closing** is out of scope here too,
but it is no longer missing: it is the sibling verb, with its own route
([`PUT /api/v1/service-requests/{id}/close`](#put-apiv1service-requestsidclose))
and a different meaning — cancelling walks away from a job, closing finishes it.

**This call also notifies.** Every provider whose offer was still `sent` or
`selected` at the instant of cancellation, plus the `backend` role, are told —
push, inbox and email for the providers, email only for `backend` (SPEC 113).
Best-effort, after the three writes below and never blocking the `200`. Full
contract in `docs/service-request-notifications.md`.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |
| Content-Type | `application/json` — only if a body is sent |

**Request body — optional**
```json
{ "reason": "Ya resolví el problema por mi cuenta." }
```

| Field | Required | Type | Rule |
|-------|----------|------|------|
| `reason` | no | string | Up to **255 characters**, measured with `drupal_strlen()` and not `strlen()`, so 255 accented ones fit. Trimmed before storing. |

**No body at all, no `reason` key, `""` and whitespace-only all mean "no
reason", and none of them is an error.** The reason is optional by design:
demanding it would put friction on the only exit the resident has. When it is
absent the transaction's comment falls back to `El residente canceló la
solicitud.` — no transaction of this module is ever born without one.

`reason` **present but not a string** (a number, an array, `null`) *is* an
error: the client meant to send a reason and sent something that is not one.

### Which statuses admit a cancellation

| From | Result |
|------|--------|
| `open` | `200` |
| `direct` | `200` |
| `offered` | `200` |
| `assigned` | `200` |
| `closed` | `409 service_request_not_cancellable` |
| `cancelled` | `409 service_request_not_cancellable` |
| empty, or a value outside the catalogue | `409 service_request_not_cancellable` |

That table is **not written in the endpoint**: it is the transition graph of
the module (`myapi_services_request_transitions()`), asked one question —
*"does this status lead to `cancelled`?"*. A status the endpoint does not
recognise answers *no*, which is why a corrupt `field_request_status` is a `409`
and never a `500`: *"I do not know what state this is in"* reads as *"I do not
cancel it"*.

### What it writes, in this order

1. **The request.** `field_request_status` becomes `cancelled`, and **nothing
   else changes**: `field_assigned_offer` and `field_assigned_provider` keep the
   values they had — a cancellation with no trace of who the job had been
   awarded to is one nobody can audit — and so do the title, the unit, the
   category, the description, the images and the attachment. The node **stays
   published**: cancelling neither unpublishes nor deletes.
2. **The transaction.** One `service_transaction` pointing at the request, with
   `field_request_status = cancelled`, `field_status_date` set to the instant of
   the call with the seconds pinned to `00`, `uid` of the resident who
   cancelled, and `field_comment` holding **the resident's own words verbatim**
   — no prefix, no label — or the automatic fallback. Its title is generated,
   like every other one: `Solicitud #412 · Cancelada · 19/08/2026 14:30 · …`.
3. **The offers.** Every **published** offer of that request in `sent` or
   `selected` becomes `rejected`. Offers in `withdrawn` are left alone — that is
   the provider's own retreat, and overwriting it would erase who walked away by
   themselves — and so are those already in `rejected`, which is terminal.
   Offers of *other* requests are never touched, not even from the same
   provider.

The order matters: the request is saved **first**, so the back office's
status sync sees the two statuses already equal and does not save it a second
time. A cancellation writes the request exactly once.

> **The three writes are not one atomic transaction.** If saving an offer fails
> halfway through step 3, the request is already `cancelled` with its entry
> written and some offers are still live. That is accepted: the first thing
> written is the status, which is what closes the door, and a live offer on a
> `cancelled` request is inconsistent but harmless — nothing can award it,
> because `cancelled` is terminal.

**Success response (200)**

`data.service_request` is **the same nineteen-key object**
[`GET /api/v1/service-requests/{id}`](#get-apiv1service-requestsid) returns —
same loaders, same serialiser, nothing shaped by hand here — plus
`offers_rejected` as a **sibling** key. There is deliberately **no
`data.transaction`**: the cancellation entry is already the last of
`transactions`, and serving it twice would make the app choose which copy is
the real one.

Everything is read **after** the three writes, so `status` comes back
`cancelled`, the live offers come back already `rejected`, and the cancellation
is the last entry of the timeline. `viewer` is always `"requester"` — nobody
else can reach a `200` here — and `offers` therefore travels **untrimmed**.

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 412,
      "title": "Fuga en el calentador",
      "description": "El calentador del baño principal gotea desde el lunes.",
      "status": "cancelled",
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 55, "name": "A-301" },
      "offers_count": 4,
      "assigned_offer": { "id": 52, "status": "rejected", "amount": 150.5, "...": "the fifteen keys of an offer" },
      "assigned_provider": { "id": 9, "title": "Plomería Ruiz", "...": "the eight keys of a provider card" },
      "created": "2026-08-17T10:05:00",
      "desired_start": "2026-08-20T08:00:00",
      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [],
      "attachment": null,
      "closed_at": null,
      "offers": [
        { "id": 51, "status": "rejected", "...": "..." },
        { "id": 52, "status": "rejected", "...": "..." },
        { "id": 53, "status": "rejected", "...": "..." },
        { "id": 54, "status": "withdrawn", "...": "..." }
      ],
      "transactions": [
        { "id": 512, "status": "open", "...": "..." },
        {
          "id": 987,
          "status": "cancelled",
          "status_date": "2026-08-19T14:30:00",
          "comment": "Ya resolví el problema por mi cuenta.",
          "created": "2026-08-19T14:30:00"
        }
      ]
    },
    "offers_rejected": 2
  },
  "message": "Solicitud cancelada correctamente."
}
```

See [the detail](#get-apiv1service-requestsid) for what every one of the
nineteen keys means and how it is typed — this endpoint adds no key and changes
no format.

`offers_rejected` is the number of offers that **actually changed status** in
this call — `0` when the request had none live. It sits outside
`service_request` so that object stays byte-identical to the detail's and the
app can swap it into its state with no special case. It is also the one thing
the app cannot deduce from what it just received: `offers` says which offers are
rejected **now**, not which ones **this call** rejected — one already rejected
beforehand looks exactly the same.

**Cost:** six queries after the writes. They are what buys the app a full
repaint — status, offers and timeline at once — with no second round trip, and
they are why this response can never disagree with what a `GET` would say.

> **Degraded body (rare).** The detail is built with three `INNER JOIN`s, one of
> them to the category's taxonomy term. A request whose category term was
> deleted cannot be built — such a request is **already** invisible through
> `GET /api/v1/service-requests/{id}` and through the listing, for the same
> joins, so it cannot have been opened; a stale id in the app can still reach
> this endpoint. In that case **the cancellation is still applied and still
> answers `200`**, but `service_request` carries only `id` and `status`, and the
> inconsistency is logged with `watchdog()`. The app tells the two shapes apart
> by `viewer`, which only the full object has. Answering `500` there would lie
> about an operation that succeeded and would push the client into a retry that
> gets `409`.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method other than `PUT`. Answered **before** the token is read and without the request having to exist. |
| 401 | `missing_authorization` / `invalid_token` | `Authorization` header absent or not a `Bearer <token>`; or the token is invalid, revoked, expired, or its user is missing/blocked. |
| 404 | `service_request_not_found` | `{id}` is not a positive integer, or names a request that does not exist, is unpublished, or is not of bundle `service_request` (an offer's or a transaction's nid). The four cases are told apart by nothing. |
| 403 | `service_request_forbidden` | The request exists, but whoever is asking is not its `field_requester` — including a provider with a live offer on it, and a user who is only the node's `uid`. Nothing is written. |
| 409 | `service_request_not_cancellable` | The current status does not lead to `cancelled` — see the table above. Nothing is written. |
| 422 | `invalid_field` / `field_too_long` | `reason` is present with a type other than string, or longer than 255 characters. `@field` is `reason`. Nothing is written. |

`404` and `403` mean different things here, the same criterion the detail uses:
the request exists and its nid is not a secret, so the `403` tells the client
something actionable instead of pretending it is not there.

**The checks run in this order:** `{id}` → token → the request exists → **it is
yours** → **its status admits it** → the body. A malformed `reason` on someone
else's request answers `403`, never `422`: access is resolved first, and a body
with garbage in it never masks an access or a status error.

See [i18n.md](i18n.md) for the translated `error`/`message` text.

**Example (with a reason):**
```bash
curl -i -X PUT https://host/api/v1/service-requests/412/cancel \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"reason":"Ya resolví el problema por mi cuenta."}'
```

**Example (no reason, no body):**
```bash
curl -i -X PUT https://host/api/v1/service-requests/412/cancel \
  -H 'Authorization: Bearer <access_token>'
```

---

## PUT /api/v1/service-requests/{id}/close

Closes a service request and, when a provider did the job, **rates it**. The
request is left in `closed` with its closing instant recorded, one timeline
entry is written, and the rated provider's two reputation counters are
recalculated.

`closed` was **the only status of the catalogue without a door**. `cancelled`
has had one since SPEC 95, `assigned` since SPEC 106, `offered` since SPEC 100,
and `open` and `direct` are birth statuses. An awarded request simply could not
**end**: it stayed `assigned` forever, and the provider who did the work earned
no reputation for it. This is the exit that was missing.

**Closing is rating, which is why they are one call.** Splitting the verb in
two would open a window in which the request is closed and unrated — the exact
state the module was built to forbid, and one whose second call might never
arrive. `closed` is **terminal**: there is no way back from it, here or
anywhere else.

**Only the requester closes.** Not the assigned provider — the rating is the
resident's opinion *of* the provider, and a close executed by the provider
would arrive without the one thing it is required to carry, or would ask the
provider to score itself. Not a building administrator through this API either:
they close from the back office. It is the same rule the sibling verb
([cancel](#put-apiv1service-requestsidcancel)) fixed for this resource and the
one [`PUT /api/v1/claims/{id}/close`](claim.md) fixed for the same verb on
another: **whoever opens is whoever closes**.

> **The cost of that rule.** A resident who never opens the app again leaves the
> request in `assigned` forever, and that job earns its provider nothing. It is
> accepted and written down: the fix — a "work finished" signal from the
> provider, or an automatic close by cron — needs a new edge in the status graph
> or a new field, and has no spec yet. An operator can close from the back
> office meanwhile.

**Not idempotent, on purpose.** A second call on an already-closed request
answers `409`, not `200`: it tells the resident their action did nothing, which
is the truth, and it is what stops a second rating and a duplicate timeline
entry.

**This call also notifies.** When there was a rating, the rated provider is
told — push, inbox and email, with the full stars and comment they
received. The `backend` role is told **always**, in either form, email only
(SPEC 114). Best-effort, after the three writes above and never blocking the
`200`. Full contract in `docs/service-request-notifications.md`.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |
| Content-Type | `application/json` |

### The body has two shapes, and the status picks one

This is the only endpoint of the module whose body depends on the state of the
thing it writes. The rule is not arbitrary: **a rating is demanded when, and
only when, there was a provider who did the job.**

**A. Closing a job — from `assigned` or `direct`**

```json
{ "stars": 5, "comment": "Llegó puntual y dejó todo limpio." }
```

| Field | Required | Type | Rule |
|-------|----------|------|------|
| `stars` | **yes** | integer | 1, 2, 3, 4 or 5. A string of digits is accepted, so `5` and `"5"` are the same value. |
| `comment` | no | string | Up to **1000 characters**, measured with `drupal_strlen()` and not `strlen()`, so 1000 accented ones fit. Trimmed before storing. |
| `close_reason` | — | — | **Ignored in silence** if sent. Here the rating's comment *is* the text of the close. |

**B. Closing with nothing awarded — from `offered`**

```json
{ "close_reason": "Lo resolví con un conocido, ya no necesito el servicio." }
```

| Field | Required | Type | Rule |
|-------|----------|------|------|
| `close_reason` | **yes** | string | 1 to **1000 characters**, `drupal_strlen()`, trimmed. Whitespace-only counts as absent. |
| `stars`, `comment` | — | — | **Ignored in silence** if sent. There is nobody to rate. |

`close_reason` is **required** while the cancellation's `reason` is optional,
and the difference is deliberate. Cancelling is walking away and the reason
explains itself; **closing with nothing awarded, having received offers**,
leaves the providers who sent them hanging, and that timeline entry is the only
thing left to explain it to them. It is also the treatment
[`PUT /api/v1/claims/{id}/close`](claim.md) already gives the same word.

**1000 characters and not 255.** The cancellation's 255 is for a one-line
reason; both texts here are a resident's written opinion about a service.

**Keys that are left over are not a `422`.** What is named is validated and
nothing else, the same criterion as the rest of the module: an app that sends
the whole form in both cases works.

**`stars` refuses booleans and floats explicitly.** `true` would cast to `1`,
which *is* a valid star value, and `2.5` sits inside the range without being a
star. Only an integer or a string of digits is a rating.

### Which statuses admit a close

| From | Rating | Result |
|------|--------|--------|
| `assigned` | **required** | `200` |
| `direct` | **required** | `200` |
| `offered` | not asked for | `200` |
| `open` | — | `409 service_request_not_closable` |
| `closed` | — | `409 service_request_not_closable` |
| `cancelled` | — | `409 service_request_not_closable` |
| empty, or a value outside the catalogue | — | `409 service_request_not_closable` |

That table is **not written in the endpoint**: it is the module's transition
graph, asked one question — *"does this status lead to `closed`?"*. Out of that
comes free that **`open` answers `409`**: a request nobody has bid on is
**cancelled**, which is what it means, and
[cancel](#put-apiv1service-requestsidcancel) is the route for it. And because
the graph answers *no* to a status it does not recognise, a corrupt
`field_request_status` is a `409` and never a `500`.

### What it writes, in this order

1. **The rating** — *only when the status demanded one*. One `service_rating`
   node, published, owned by the resident, pointing at the awarded provider
   (`field_rating_provider`) and at the awarded offer
   (`field_rating_offer`) — **the offer is left empty on a `direct` request**,
   which never had one — with the stars, the comment, and the unit of the
   request. Its title is generated:
   `Calificación · Plomería Ruiz · 5★ · 28/08/2026`.
2. **The request.** `field_request_status` becomes `closed` and
   `field_closed_at` records the instant. **Nothing else changes**: the
   category, the unit, the description, the files and **both award fields** keep
   the values they had — a closed request is audited by who the job was given
   to — and the node **stays published**. `field_closed_at` had never been
   written by anything before this endpoint.
3. **The transaction.** One `service_transaction` pointing at the request, with
   `field_request_status = closed`, the instant with the seconds pinned to `00`,
   the `uid` of the resident who closed, and `field_comment` holding one of
   three texts:

   | Situation | Comment |
   |---|---|
   | Closed with nothing awarded | the `close_reason` **verbatim**, no prefix and no label |
   | Closed with a rating, provider known | `Servicio cerrado. Plomería Ruiz calificado con 5 estrellas.` |
   | Closed with a rating, no provider name | `Servicio cerrado y calificado con 5 estrellas.` |

4. **The provider's two counters** — *not this endpoint's write*, and that is
   the point. `field_rating_count` and `field_rating_avg` on the `provider` node
   are recalculated by a node hook that fires inside step 1, so a rating an
   operator creates **by hand** from the back office counts exactly the same.
   See [provider.md](provider.md).

**The rating is written first, and that is the most important ordering decision
of this endpoint.** It is the only irrecoverable step: if it fails, the request
is still `assigned`, the resident retries and nothing is lost. The other way
round, with the request already `closed` — terminal, second call answers `409` —
a rating that failed could **never** be retried, and that provider would lose
the reputation of a job it did.

The request is saved **before** the transaction, so the back office's status
sync sees the two statuses already equal and does not save it a second time. A
close writes the request exactly once.

> **The three writes are not one atomic transaction.** A failure between 1 and 2
> leaves a rating on a request that is still `assigned`; between 2 and 3, a
> closed request with no timeline entry. That is accepted, at the same price the
> cancellation and the award accepted. The order is chosen so the likeliest
> fragment is the **recoverable** one: retrying the close after a failure in
> step 1 works again. The other case leaves a valid rating, which counts towards
> the reputation and breaks nothing.

**Success response (200)**

`data.service_request` is **the same nineteen-key object**
[`GET /api/v1/service-requests/{id}`](#get-apiv1service-requestsid) returns —
same loaders, same serialiser, nothing shaped by hand here — plus `rating_id` as
a **sibling** key. There is deliberately **no `data.transaction`** and **no
`data.rating`**: the close entry is already the last of `transactions`, and the
rating is readable through the provider.

Everything is read **after** the writes, so `status` comes back `closed` and
`closed_at` already carries the instant. `viewer` is always `"requester"` —
nobody else can reach a `200` here — and `offers` therefore travels
**untrimmed**, with whatever statuses the award left them at: closing moves no
offer.

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 412,
      "title": "Fuga en el calentador",
      "description": "El calentador del baño principal gotea desde el lunes.",
      "status": "closed",
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 55, "name": "A-301" },
      "offers_count": 4,
      "assigned_offer": { "id": 52, "status": "selected", "amount": 150.5, "...": "the fifteen keys of an offer" },
      "assigned_provider": { "id": 9, "title": "Plomería Ruiz", "...": "the eight keys of a provider card" },
      "created": "2026-08-17T10:05:00",
      "desired_start": "2026-08-20T08:00:00",
      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [],
      "attachment": null,
      "closed_at": "2026-08-28T09:14:00",
      "offers": [
        { "id": 52, "status": "accepted", "...": "..." },
        { "id": 53, "status": "rejected", "...": "..." }
      ],
      "transactions": [
        { "id": 512, "status": "open", "...": "..." },
        { "id": 988, "status": "assigned", "...": "..." },
        {
          "id": 1041,
          "status": "closed",
          "status_date": "2026-08-28T09:14:00",
          "comment": "Servicio cerrado. Plomería Ruiz calificado con 5 estrellas.",
          "created": "2026-08-28T09:14:00"
        }
      ]
    },
    "rating_id": 4021
  },
  "message": "Solicitud cerrada correctamente."
}
```

See [the detail](#get-apiv1service-requestsid) for what every one of the
nineteen keys means and how it is typed — this endpoint adds no key and changes
no format.

`rating_id` is the nid of the rating that was created, or **`null`** when the
close needed none. It is **always present**: no key of this module appears and
disappears with the case. It sits outside `service_request` so that object stays
byte-identical to the detail's and the app can swap it into its state with no
special case.

**The rating's comment is not copied into the timeline.** It lives in the
rating, which is where [`GET /api/v1/providers/{id}`](provider-detail.md) reads
it from. Two copies of one text drift apart the day the back office edits one;
the timeline says *that* the provider was rated and with how many stars.

**Cost:** six queries after the writes. They are what buys the app a full
repaint — status, offers and timeline at once — with no second round trip, and
they are why this response can never disagree with what a `GET` would say.

> **Degraded body (rare).** Exactly as in
> [cancel](#put-apiv1service-requestsidcancel): a request whose category term
> was deleted cannot be serialised, so **the close is still applied and still
> answers `200`**, but `service_request` carries only `id` and `status`,
> `rating_id` still travels, and the inconsistency is logged with `watchdog()`.
> Answering `500` there would lie about an operation that succeeded and would
> push the client into a retry that gets `409`.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method other than `PUT`. Answered **before** the token is read and without the request having to exist. |
| 401 | `missing_authorization` / `invalid_token` | `Authorization` header absent or not a `Bearer <token>`; or the token is invalid, revoked, expired, or its user is missing/blocked. |
| 404 | `service_request_not_found` | `{id}` is not a positive integer, or names a request that does not exist, is unpublished, or is not of bundle `service_request`. The four cases are told apart by nothing. |
| 403 | `service_request_forbidden` | The request exists, but whoever is asking is not its `field_requester` — **including the assigned provider**, and a user who is only the node's `uid`. Nothing is written. |
| 409 | `service_request_not_closable` | The current status does not lead to `closed` — see the table above. An already-closed request lands here. Nothing is written. |
| 409 | `service_request_provider_missing` | The status demanded a rating and `field_assigned_provider` is empty — a request edited by hand, or a broken reference. Nothing is written: the close stops **before** the first write rather than closing the request and then failing to rate. |
| 422 | `missing_field` | `stars` absent (shape A), or `close_reason` absent or whitespace-only (shape B). `@field` names the one that is missing. Nothing is written. |
| 422 | `invalid_field` | `stars` outside 1–5, or not an integer; or `comment` / `close_reason` present with a type other than string. Nothing is written. |
| 422 | `field_too_long` | `comment` or `close_reason` longer than 1000 characters. Nothing is written. |

**The checks run in this order:** `{id}` → token → the request exists → **it is
yours** → **its status admits it** → the body, **in the shape that status asks
for** → the assigned provider exists. A malformed body on someone else's
request answers `403`, never `422`.

See [i18n.md](i18n.md) for the translated `error`/`message` text.

**Example (closing an awarded job):**
```bash
curl -i -X PUT https://host/api/v1/service-requests/412/close \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"stars":5,"comment":"Llegó puntual y dejó todo limpio."}'
```

**Example (closing without awarding):**
```bash
curl -i -X PUT https://host/api/v1/service-requests/412/close \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"close_reason":"Lo resolví con un conocido."}'
```

---

## What is still not here

Written down so it is not looked for in this document:

- **Awarding through a route of this prefix.** It does exist since SPEC 106, but
  as a `PUT` on the *offer* ([service-offer.md](service-offer.md)), because the
  object the resident acts on is the offer. Every other verb on this prefix is
  `405` — see
  [`POST /api/v1/service-requests`](#post-apiv1service-requests),
  [`POST /api/v1/service-requests/{id}`](#post-apiv1service-requestsid),
  [`PUT /api/v1/service-requests/{id}/cancel`](#put-apiv1service-requestsidcancel)
  and
  [`PUT /api/v1/service-requests/{id}/close`](#put-apiv1service-requestsidclose)
  for the four writes this document covers. **The two terminal statuses now both
  have a door**: `cancelled` since SPEC 95, `closed` since SPEC 108.
- **A provider's way of saying "the job is done".** The provider still has no
  verb at all over a request. Closing is the resident's, and only the
  resident's, so a request whose resident never comes back stays `assigned`
  forever and that job earns its provider no reputation. The fix — a "work
  finished" signal, or an automatic close by cron — needs a new edge in the
  status graph or a new field, and has no spec yet. Meanwhile an operator can
  close from the back office.
- **Editing or withdrawing a rating from the app.** A rating is written once,
  with the close, and the resident does not touch it again: there is no `PUT`
  and no `DELETE` on one. The back office can edit it and delete it, and the
  provider's two counters are recalculated in both cases.
- **Reopening.** `closed` is terminal, exactly like `cancelled`. The graph gains
  no edge.
- **Editing anything but the five fields the edit names**, and editing at all
  once the request has left `open`/`direct` or has received its first offer. The
  category, the unit, **the assigned provider** and the order of the images are
  not editable through the API: a `direct` request can be corrected, but the
  award cannot be moved to another provider — see
  [`POST /api/v1/service-requests/{id}`](#post-apiv1service-requestsid).
- **Any trace of an edit.** No timeline entry, no notification, no history of
  what changed, and no concurrency control: two simultaneous edits, and the last
  one wins.
- **Everything about an offer except creating one.** Withdrawing it, editing
  it, awarding it, and `GET /api/v1/offers/{id}`. Creating one has its own route
  and its own document since SPEC 100 —
  [`POST /api/v1/service-requests/{id}/offers`](service-offer.md) — and it is
  what moves a request from `open` to `offered`. Since SPEC 101 that same route
  also carries the **quote of a `direct`**, which moves no status at all. Here
  offers are still only **read**, inside one request.
- **The chat.** `field_firebase_path`, `field_chat_opened_at` and
  `field_last_message_at` do not travel: who opens the thread and when the path
  is generated is another spec, and a key served today is a contract that spec
  could no longer change.
- **Writing to the timeline directly.** It is **read** since SPEC 93, in the
  detail and in the `201` — see [the timeline](#the-timeline-transactions) — and
  the cancellation now **writes** one, as the side effect of a transition; since
  SPEC 101 the quote of a `direct` writes one **without** any transition behind
  it, the single exception. What does not exist is any way to write an entry *as
  such*: no `POST /api/v1/service-requests/{id}/transactions`, no commenting, no
  changing status by hand. Each remaining transition will create its own entry in its own
  spec, next to the rest of its effects.
- **Files hanging from a transaction.** `service_transaction` has no
  `field_images` and no `field_attachment`, so its entries carry five keys and
  not the seven a claim's do. Adding them is an installer, a file-ownership rule
  and a download endpoint — another spec.
- **`?include=transactions` in the listing.** The timeline is served in the
  detail and in the `201`, nowhere else. `GET /api/v1/service-requests` answers
  exactly the same eleven keys as before.
- **Ratings.** Neither served nor required.
- **The provider's listing** — *the requests I may attend*.
- **Validation of the transition graph everywhere else.** The cancellation
  checks it on **its own route** and nowhere else: the back office still writes
  any status onto any request without asking the graph, and so will any future
  endpoint until the spec that closes that door arrives.
- **Notifications on cancellation** — no longer a gap. Every provider whose
  offer was `sent` or `selected` (the assigned one included) is told, plus
  the `backend` role, since SPEC 113 —
  [see the note on `PUT .../cancel`](#put-apiv1service-requestsidcancel) and
  `docs/service-request-notifications.md`.
- **`?include=`, `ETag`, conditional caching.** The detail always answers whole.
- **Rate limiting or deduplication on creation.** A double tap or a retried
  request creates two requests; no endpoint of this module is idempotent except
  login, and this spec does not add it here either.

> **Providers are still loaded by hand from the back office.** Creating a
> `service_request` no longer needs data planted outside the API, but a `direct`
> award still needs a real `provider` node to point at, and offering on a
> request still needs a real one to bid with — and a `provider` node is still
> created only from the back office. That is the real state of the system, not a
> failure of this endpoint.
