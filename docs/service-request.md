## GET /api/v1/service-requests

Returns the service requests **the authenticated resident created** — a
paginated list with the status, the category, the number of offers received and,
when there is one, the awarded offer and the awarded provider.

Read-only collection. There is no create, update, cancel, close or award over
the API yet: `POST`, `PUT` and `DELETE` answer `405`. Requests and offers are
loaded today by the operator from the back office.

This is the **first route** of the `service_request` bundle, whose schema was
built by SPEC 77, 86 and 87 without one. Two things are deliberately **not**
here and are their own spec:

- **The detail**, `GET /api/v1/service-requests/{id}` — the unit, the
  condominium, the images, the attachment, `closed_at` and the offers one by
  one. This listing only **counts** the offers, and of the awarded one it reads
  `id` and `status`.
- **The provider's side of the marketplace** — *the requests I may attend*. That
  is the other half of the market, with another scope and another shape, and it
  does not go through this route.

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
