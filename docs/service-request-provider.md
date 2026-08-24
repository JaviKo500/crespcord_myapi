## GET /api/v1/service-requests/provider

The service requests **that concern the authenticated provider** (SPEC 98): the
ones they already offered on, the unassigned ones of their categories, and the
ones awarded to their providers **in any status**. A paginated list with the
same item as the resident's listing plus the **requester** and the
**condominium**.

The other half of [service-request.md](service-request.md). That one answers
*"what did I ask for?"* over `field_requester = uid`; this one answers *"what
work is there for me?"* over the providers the account operates. The two never
overlap: a resident who also holds the `proveedor` role reads their own requests
there and the market here, and neither listing leaks into the other.

**One list and not two.** The market and the finished work share the item, the
pagination, the filters and the authorisation; what changes is only which set a
request belongs to. The app tells the two apart with `?status`:
`?status=open,offered` is *the market*, everything else is *my work*.

The item of this list is
[`GET /api/v1/service-requests/provider/{id}`](#get-apiv1service-requestsproviderid),
documented below: same gate, same access rule, same thirteen keys, plus six of
its own. **Whatever this list shows opens there, and nothing else does.**

Read-only. There is no `POST` here and there will not be one by accident:
creating, withdrawing or awarding an offer is another spec. `POST`, `PUT`,
`DELETE` and `PATCH` answer `405`.

**Authentication:** required (Bearer access token) **and the `proveedor` role**

The second `api/v1` endpoint of the module authorised by a role, after
[`GET /api/v1/providers/mine`](provider-mine.md) — and it reuses that
endpoint's `403` key exactly, because the cause and the user's remedy are
identical.

| The account… | Answer |
|--------------|--------|
| has no valid token | `401 missing_authorization` / `401 invalid_token` |
| has a token but **not** the `proveedor` role | `403 provider_role_required` |
| holds `administrator` but **not** `proveedor` | `403 provider_role_required` — no exception for administrators |
| has the role but **no** linked provider | `200` with `service_requests: []` and `total: 0` |
| has the role and a linked provider | `200` with the list |

**Role and link are two different things**, the same reading
[provider-mine.md](provider-mine.md) spells out: *"you are not a provider"* is a
permission, *"the operator has not linked you yet"* is somebody else's pending
task, and a `403` would disguise the second as the first.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | `Bearer <access_token>` |
| Accept-Language | `es` / `en` (optional, defaults to `es`) |

**Request body**

None — this is a `GET`.

---

### The scope: A ∪ B ∪ C

With **`P`** = the providers of the account, or the single one `?provider_id`
selects, a request concerns the reader when it is in **any** of these three
sets:

| | Set | Definition | Note |
|---|-----|------------|------|
| **A** | *Already offered* | a published `service_offer` with `field_provider ∈ P` points at the request | **Any status, any category** — even one no longer attended |
| **B** | *The market* | status ∈ (`open`, `offered`) **and** `field_assigned_offer` empty **and** `field_assigned_provider` empty **and** the category ∈ the categories of `P` **and** at least one provider of `P` is active | Rule 3 of the detail's access rule, condition by condition |
| **C** | *My work* | `field_assigned_provider ∈ P` | **Any status**, `closed` and `cancelled` included |

Three precisions that are the contract and not details:

- **`offered` is in B.** A request with third-party offers but no award is
  **still biddable**, or only the fastest bidder would ever see it.
- **`direct` is not in B, and that is on purpose.** A `direct` request is born
  awarded, which is exactly what "unassigned" rules out — so a `direct` of
  **somebody else's** appears through nothing, and a `direct` of **mine**
  appears through C. This is also why B does **not** use
  `myapi_provider_role_broadcast_statuses()`, which does include `direct`: that
  catalogue is the back office's policy, not the app's.
- **The whole history travels**, `cancelled` included. If the server already
  trimmed, `?status` could only trim further, and a provider reviewing what
  became of a job three months ago would have nothing to ask for. Trimming in
  the client is reversible; trimming in the server is not.
- **A request in A, B and C at once appears exactly once.** The scope is a
  filter over one row and never a union of three result sets.

**Unpublished requests appear through none of the three.**

### If it is in this list, it can be opened

**Every request this endpoint returns answers `200` on
[`GET /api/v1/service-requests/{id}`](service-request.md#get-apiv1service-requestsid)
with the same token.** Not one `403`.

That equivalence is the reason SPEC 98 added **rule 2b** to the detail's access
rule — *"the request is awarded to one of my providers"* → `viewer: "provider"`,
in any status. A listing whose items cannot be opened is worse than not having
the item: the app would have to replicate the server's access rule just to know
which link to paint, which is precisely what an endpoint exists to avoid.

The same widened rule governs
[`GET /api/v1/service-requests/{id}/files/{fid}`](service-request.md#get-apiv1service-requestsidfilesfid),
so the awarded provider downloads the photos of their own job.

> **The rule is written twice**, once as a set here (to paginate) and once as a
> per-row decision in the detail (to load one row). Neither form can be derived
> from the other without losing the pagination or the loaded row. If a future
> spec touches only one of them — a new biddable status, a change to
> "unassigned", an age rule — the symptom is an item in this list that answers
> `403` when opened, or a readable request that appears nowhere. **Neither
> fails loudly.** A test walks the whole status × award × category × offered
> matrix to hold the two in step.

---

### Query string

| Parameter | Treatment | On an invalid value |
|-----------|-----------|---------------------|
| `page` | Integer ≥ 1; defaults to `1`. | Lax → `1` |
| `limit` | 1–50; `-1` means everything on one page; defaults to `20`. | Lax → `20` |
| `sort` | `asc` / `desc` over `node.created`; defaults to `desc`. | Lax → `desc` |
| `status` | Comma-separated list, validated against the status catalogue. | Lax: an unknown key is dropped in silence |
| `category_id` | One tid. **Strict in the format.** | `422 invalid_field` |
| `provider_id` | One `provider` nid. **Strict in the format, lax in the ownership.** | `422` if malformed; empty list if it is not the account's |
| `date_from` / `date_to` | Range over `node.created`, `YYYY-MM-DD`, inclusive on both ends. | Lax: a bad bound is dropped |
| `unit_id` | **Does not exist on this route.** | Ignored in silence — never a `422` |
| anything else | Ignored in silence. | Never a `422` |

**`?unit_id` is ignored and not refused.** A parameter to filter by flat
contradicts the unit rule below, which only paints the address once the job is
already yours; refusing it with a `422` would suggest it might work somewhere
else on this route.

#### `?provider_id` changes `P`, it does not add a condition

| Value | `P` becomes | Result |
|-------|-------------|--------|
| absent | every provider of the account | the full union |
| a nid **of the account** | `[that nid]` | A ∪ B ∪ C for it alone: its offers, its categories and **its licence**, not an active sibling's |
| a **foreign** or non-existent nid | `[]` | `200` with an empty list and `total: 0`. No `403`, no `404`, and no scope query |
| malformed (`abc`, `1,2`, `-1`, `0`) | — | `422 invalid_field`, **before any query** |

The property that makes this reading the right one: **the union of the results
of `?provider_id` over every provider of the account is exactly the list with no
filter** — nothing missing, nothing repeated. Filtering only "my work", or only
by category, would leave requests outside every filtered view.

As a consequence, `?provider_id=A` also uses **A's licence**: *"put the app in
Provider A mode"* includes being suspended. With every provider suspended or
expired, **B disappears and A and C remain** — the answer is a shorter list,
never a `403`.

The values it accepts are the `id` of each provider in
[`GET /api/v1/providers/mine`](provider-mine.md).

---

**Success response (200)**

```json
{
  "success": true,
  "data": {
    "service_requests": [
      {
        "id": 128,
        "title": "Fuga en el calentador",
        "description": "El calentador gotea desde el lunes.",
        "status": "assigned",
        "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
        "unit": { "id": 55, "name": "A-301" },
        "offers_count": 3,
        "assigned_offer": { "id": 901, "status": "accepted" },
        "assigned_provider": { "id": 41, "name": "Plomería Torres" },
        "created": "2026-08-12T09:14:00",
        "desired_start": "2026-08-20T08:00:00",
        "requester": { "id": 3, "name": "Ana Pérez" },
        "condominium": { "id": 500, "name": "Residencial Los Almendros" }
      }
    ],
    "pagination": { "total": 1, "page": 1, "limit": 20, "total_pages": 0 }
  }
}
```

### The item: thirteen keys, always the thirteen, in this order

| Field | Type | Note |
|-------|------|------|
| `id` | int | `node.nid` |
| `title` | string | |
| `description` | string | `""` when empty; travels as stored, line breaks included |
| `status` | string \| null | `open`, `direct`, `offered`, `assigned`, `closed`, `cancelled` |
| `category` | object | `{id, code, name}` |
| `unit` | object \| null | **Conditional on the reader — see below** |
| `offers_count` | int | **The real total**, competition included |
| `assigned_offer` | object \| null | `{id, status}` |
| `assigned_provider` | object \| null | `{id, name}`. **Not masked**, even when the winner is a rival |
| `created` | string | `Y-m-d\TH:i:s` |
| `desired_start` | string \| null | `Y-m-d\TH:i:s` |
| `requester` | object \| null | `{id, name}` — `name` is `"field_nombre field_apellidos"` |
| `condominium` | object \| null | `{id, name}` — `name` is the **node title** of the `condominio` |

**The first eleven are produced by the very same serialiser the resident's
listing uses**, unmodified: same types, same nulls, same order. The two new keys
go at the end so the shared builder cannot drift.

`requester` and `condominium` are a **whole `null`**, never
`{id: null, name: null}` — the shape `unit`, `assigned_offer` and
`assigned_provider` already use. `condominium` is `null` when the reference is
empty or the node was deleted or unpublished, and **the request keeps its place
in the list**. `requester` is `null` only on corrupt data: its join is INNER.

**No contact detail travels.** `requester` is `{id, name}` and nothing else — no
email, no phone, no id number, no username. Same rule as the detail.

**No images and no attachment** either, and **no `my_offer`**: whether I already
bid and for how much is the detail's answer, complete, with the offer's status
and its comments.

**The competition is visible on purpose.** `offers_count` is the real total and
`assigned_provider` names the winner even when it is a rival. The detail already
gives a provider both, with the decision written down that *"`offers_count` is
the total and not the size of the trimmed list"*; masking them here would create
two truths about the same datum depending on which endpoint you asked. What is
sensitive — **the amount of each offer** — travels in neither.

`offers_count` counts **published offers** and never `service_transaction`
rows: a request with three status changes and no offers answers `0`.

### The `unit` rule

```
unit = (assigned_provider.id ∈ the account's providers) ? {id, name} : null
```

| Case | `unit` |
|------|--------|
| awarded to one of my providers | `{id, name}` |
| `open` of my category, unassigned | `null` |
| awarded to another provider — even one I bid on | `null` |
| award pointing at a deleted or unpublished node (`assigned_provider: null`) | `null` |

- **The key always travels**, with value `null` when it does not apply. It is
  never omitted: a client would otherwise have to tell "absent" from "null",
  which here mean the same thing.
- **It is measured against the account's providers, not against `P`.** With
  `?provider_id=A`, a request awarded to my other provider **B** that shows up
  because A bid on it **still carries the unit**: I am going to that house
  either way, and narrowing a view does not change what I already know.
- **It is compared against `assigned_provider` as already resolved** — against
  `node` with the bundle and `status = 1` — and never against the raw column. An
  award pointing at a deleted node answers `assigned_provider: null` and
  therefore `unit: null`: the rule fails towards the **closed** side.

This **qualifies** SPEC 89 rather than reversing it. There, `unit` is `null` for
*every* provider reader, because the flat number adds nothing to the decision to
bid and does say where a specific person lives. Here the address appears exactly
when the work is already yours, which is when it stops being a privacy question
and becomes the operational datum of the job.

**The condominium, by contrast, travels always**: it names the area without
naming the door. A provider who reads "Residencial Los Almendros" knows whether
the job is nearby before bidding; they do not know which apartment anybody
lives in.

**The detail of this family answers the same `unit`.** Since SPEC 99,
[`GET /api/v1/service-requests/provider/{id}`](#get-apiv1service-requestsproviderid)
applies this very rule, out of this very function — so a request whose flat
is painted on the board still shows it when the provider taps. The `unit`
disappearing on tap was SPEC 98's known gap and it is closed.

What is still different is the **resident's** detail,
[`GET /api/v1/service-requests/{id}`](service-request.md): that one nulls `unit`
for every provider reader and calls its trimmed list `offers`. See *Two details
of the same request* at the end of this file.

### Ordering and pagination

`node.created DESC`, ties broken by `nid DESC`, reversible with `?sort=asc` —
the same order as the resident's listing. Not `desired_start`: for *my work*
urgency would rule, but for *the market* what matters is what has just been
published, and `desired_start` is optional, so the order would depend on a field
that can be missing.

`pagination.total` describes the **whole filtered set**, never the page, and
`total_pages` is `0` — not `1` — when there are no results. A page past the last
one answers `200` with an empty list, never a `404`.

---

**Possible errors**

| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header |
| 401 | `invalid_token` | Revoked, expired or unknown token |
| 403 | `provider_role_required` | The account does not hold the `proveedor` role — administrators included. **The only `403` of this endpoint** |
| 405 | `method_not_allowed` | Any method other than `GET`. Checked **before** the token, so a `POST` with no header at all is `405` and not `401` |
| 422 | `invalid_field` | `?provider_id` or `?category_id` malformed. Answered **before any query** |

There is **no `404`**: the collection always exists. And a `403` never means
"you have nothing" — an account with the role and no linked provider, and a
`?provider_id` that is not its own, both answer `200` with an empty list.

**No new i18n key.** The `403` reuses `provider_role_required`
([provider-mine.md](provider-mine.md)) and the `422` reuses `invalid_field`.

---

### Cost

**Ten queries, fixed** — token, account, link, active, categories, A, C, count,
page, offers, names — and **none of them grows with the number of rows on the
page**. Twenty requests cost what one costs; the names of the whole page are
resolved in **one** call and never one per row.

Two shortcuts save queries rather than costing them: an empty `P` answers
without a single scope query, and a `P` with no active provider skips the
category read, because there can be no market for it.

### Known limits

- **B is not narrowed by condominium.** The `proveedor` role has two axes,
  membership and category, and neither is the condominium (SPEC 78). A
  carpenter sees the open carpentry requests of **every** building on the site.
  That is the marketplace model SPEC 78 and 83 established; this endpoint only
  makes it visible.
- **The requester's name reaches the whole market.** Any provider of a category
  learns the first and last name of whoever asked for the work, and the
  condominium, before that person has chosen anybody. It is the same datum the
  detail already gave one by one; what changed is the cost of collecting it. No
  phone, email, id number or flat travels.
- **The back office keeps its own rule.** `hook_node_access` still uses
  `myapi_provider_role_broadcast_statuses()`, which does **not** include
  "awarded to me" and **does** include `direct` by category. So a provider with
  a Drupal session cannot open by `node/N` the closed job they see in the app,
  and can see by `node/N` a foreign `direct` of their category that the app
  hides. The second is a pre-existing gap of SPEC 78, not created here;
  aligning the back office is its own spec.
- **`?limit=-1` on a busy category** can be the whole market of that category
  plus the provider's entire history, each row with a dozen joins. The real
  ceiling is `?limit=50`; the app does not need `-1`.

---

## GET /api/v1/service-requests/provider/{id}

The detail of **one** service request, for the provider (SPEC 99). The item of
the listing above, plus its images, its attachment, the reader's own offers and
the complete timeline.

The item route of this family, and the sibling of
[`GET /api/v1/service-requests/{id}`](service-request.md), which serves the
**resident**. Both read the same node; they differ in three things and only in
three, all listed in *Two details of the same request* below.

**Whatever the listing shows opens here, and nothing else does.** The two use
the same access rule, and that equivalence is a test and not an intention: a
request that appears on the board and answers `403` here would be a bug.

Read-only. `POST`, `PUT`, `PATCH` and `DELETE` answer `405`, checked **before
the token and before any query**: the method is wrong whoever is asking.

**Authentication:** required (Bearer access token) **and the `proveedor` role**

**Headers**
| Header | Value |
|--------|-------|
| Authorization | `Bearer <access_token>` |
| Accept-Language | `es` / `en` (optional, defaults to `es`) |

**Request body**

None — this is a `GET`.

---

### Who gets in

Two gates and then the access rule, in this order — and the order is the
contract, because it decides which error a reader sees.

| # | Step | Failure |
|---|------|---------|
| 1 | The `{id}` is a positive integer | `404 not_found`, **without a single query** |
| 2 | The token is valid | `401 missing_authorization` / `401 invalid_token` |
| 3 | The account holds the `proveedor` role | `403 provider_role_required` |
| 4 | The account operates at least one provider | `403 forbidden` |
| 5 | The request exists and is published | `404 not_found` |
| 6 | The reader is a **provider** of it | `403 forbidden` |

**Step 3 is the only thing this route asks for over the resident's detail.**
That one lets in any account that operates a provider, role or no role, and
that is right *there*: it serves residents and providers alike and cannot
reject by role. This one can. It is also the point where an administrator cuts
an account off the provider channel by removing a role, without unlinking a
single node.

**Steps 3 and 4 run before the request is loaded.** A `{id}` that does not
exist, asked for by an account with no role, answers `403 provider_role_required`
and **never `404`**: a reader who is not on this route may not learn which
requests exist.

**The role with no linked provider is `403 forbidden` here, and `200` with an
empty list on the listing.** Not a contradiction: a list has a legitimate way of
saying *"nothing"*, a detail does not. Over one concrete resource there is only
*"here it is"* or *"you may not see it"*, and with no provider to operate there
is no access rule that could ever be satisfied. `403` and not `404`, because the
request does exist — step 5 proved it.

**The requester of the request is `403` here**, even holding the `proveedor`
role. Their own request told through this route would carry an empty `my_offers`
and a `unit` decided by the award — the same request counted wrong. Their route
exists and it is [`GET /api/v1/service-requests/{id}`](service-request.md).

#### Step 6: the three ways in

The same rule the resident's detail uses, **unmodified**. Both routes call the
same function; neither reimplements a condition of it, because a rule copied in
two places drifts.

| Rule | Condition | What it covers |
|------|-----------|----------------|
| **2** | One of my providers already offered on the request | I keep seeing the work I bid on, in **any** status and **even after it leaves my category** |
| **2b** | `field_assigned_provider` ∈ my providers | **The `direct` awarded to me**, and every job already won — `closed` and `cancelled` included |
| **3** | `status ∈ (open, offered)` **and** no award of either kind **and** the category is mine **and** some provider of mine is active | **The open market of my category** |

A **foreign `direct`** enters through none of them: it is born awarded, so rule 3
excludes it by definition and rule 2b points at somebody else. That is a
`403 forbidden`, and it is the right answer.

---

**Success response (200)**

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 128,
      "title": "Fuga en el calentador",
      "description": "El calentador gotea.\nDesde el lunes.",
      "status": "assigned",
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 55, "name": "A-301" },
      "offers_count": 4,
      "assigned_offer": { "id": 901, "status": "accepted" },
      "assigned_provider": { "id": 41, "name": "Plomería Torres" },
      "created": "2026-08-12T09:14:00",
      "desired_start": "2026-08-20T08:00:00",
      "requester": { "id": 3, "name": "Ana Pérez" },
      "condominium": { "id": 500, "name": "Residencial Los Almendros" },
      "viewer": "provider",
      "images": [
        { "id": 91, "url": "https://…/api/v1/service-requests/128/files/91", "filename": "fuga.jpg" }
      ],
      "attachment": { "id": 92, "url": "https://…/api/v1/service-requests/128/files/92", "filename": "presupuesto.pdf" },
      "closed_at": null,
      "my_offers": [
        {
          "id": 901,
          "provider": { "id": 41, "name": "Plomería Torres", "logo": null },
          "amount": 150.5,
          "message": "Puedo pasar el jueves.",
          "status": "accepted",
          "created": "2026-08-13T11:02:00"
        }
      ],
      "transactions": [
        { "id": 701, "status": "open", "status_date": "2026-08-12T09:14:00", "comment": "Hemos recibido su solicitud.", "created": "2026-08-12T09:14:00" }
      ]
    }
  }
}
```

### The object: nineteen keys, always the nineteen, in this order

The **thirteen of the listing item**, without a single difference, followed by
**six of its own**.

| # | Field | Type | Note |
|---|-------|------|------|
| 1 | `id` | int | `node.nid` |
| 2 | `title` | string | |
| 3 | `description` | string | `""` when empty; as stored, line breaks included |
| 4 | `status` | string \| null | `open`, `direct`, `offered`, `assigned`, `closed`, `cancelled` |
| 5 | `category` | object | `{id, code, name}`; `code` is `""` and never `null` |
| 6 | `unit` | object \| null | **Only when the job is already mine** — the rule of this file |
| 7 | `offers_count` | int | **The real total**, competition included |
| 8 | `assigned_offer` | object \| null | `{id, status}` |
| 9 | `assigned_provider` | object \| null | `{id, name}`. **Not masked**, even when the winner is a rival |
| 10 | `created` | string | `Y-m-d\TH:i:s` |
| 11 | `desired_start` | string \| null | `Y-m-d\TH:i:s` |
| 12 | `requester` | object \| null | `{id, name}` — `name` is `"field_nombre field_apellidos"`, or the username |
| 13 | `condominium` | object \| null | `{id, name}` — `name` is the **node title**. Travels always |
| 14 | `viewer` | string | **Always `"provider"`** |
| 15 | `images` | array | `{id, url, filename}` in upload order. **Always an array**, `[]` when there are none |
| 16 | `attachment` | object \| null | `{id, url, filename}` |
| 17 | `closed_at` | string \| null | `Y-m-d\TH:i:s`. `null` in every request that is not `closed` |
| 18 | `my_offers` | array | **Only mine.** Always an array, `[]` when I have not bid |
| 19 | `transactions` | array | The **complete** timeline. Always an array |

**The thirteen are byte for byte the listing's item for the same `{id}`** — same
types, same nulls, same order — because they come out of the very same
serialiser. Nothing appears and nothing disappears with the data or with who is
asking: a `null` is an answer, an absent key is a question, and a nested object
that does not apply is a **whole `null`** and never `{id: null, name: null}`.

### `viewer` is constant, and travels anyway

It is always `"provider"` on this route. It stays so the client model of the two
detail routes is the **same object** with the same parser; dropping it would buy
nothing but a second almost identical class.

### `my_offers`, and not `offers`

Only the offers whose `field_provider` is one of the account's providers, and
the name says so. The trim is made by the query and never in PHP.

| | |
|---|---|
| Four offers on the request, one of them mine | `my_offers` has **1** element, `offers_count` is **4** |
| No offers of mine | `my_offers` is `[]`, and the response is still `200` |

Each element is `{id, provider: {id, name, logo}, amount, message, status,
created}` — the six keys of the resident's detail, under the honest name.

**The competition's amounts never travel.** What does travel is the count, and
the winner's name in `assigned_provider`, unmasked: masking them would create
two truths about the same datum depending on which endpoint you asked.

**There is no `can_offer` key.** The client derives *"can I still bid on this?"*
from `status`, `assigned_provider` and `my_offers`, all three of which are
already here. Adding it would tie the response to a business rule that lives in
the access function today and might not agree with it tomorrow.

### `transactions` is not trimmed

The whole timeline, comments included, exactly as the resident sees it. There is
no stable ownership criterion in a status transition, and a timeline with holes
in it is a story the client would paint as complete. If some event must be
hidden from a provider, the place to decide it is the timeline's own spec.

### Private files

`images[].url` and `attachment.url` point at
[`GET /api/v1/service-requests/{id}/files/{fid}`](service-request.md) — the
resident's file route, reused as is. **There is no
`/api/v1/service-requests/provider/{id}/files/{fid}`, and there will not be
one:** that route already authorises with the same access rule this detail
uses, so a sibling would be new code making exactly the same decision. Two
routes, one rule, no possible divergence.

Whoever can read this detail can download its bytes, and nobody else.

---

**Possible errors**

| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header |
| 401 | `invalid_token` | Revoked, expired or unknown token |
| 403 | `provider_role_required` | The account does not hold the `proveedor` role — administrators included. Answered **before** the request is loaded |
| 403 | `forbidden` | The role with no linked provider; a foreign `direct`; another category; a rival's job I never bid on; **or the requester of the request** |
| 404 | `not_found` | `{id}` is not a positive integer (**no query at all**), or no such request: it does not exist, it is unpublished, or it is of another bundle |
| 405 | `method_not_allowed` | Any method other than `GET`. Checked **before** the token, so a `POST` with no header at all is `405` and not `401` |

**No new i18n key.** All six already exist in the catalogue.

**`403` and `404` mean different things here**, unlike in the listing, which
simply does not show a row. `404` is *"no such request"*; `403` is *"it exists,
and you are not a provider of it"*. The `{id}` is not a secret — the reader
reached it from a listing that handed it over.

---

### Two details of the same request

The same node, read by the same provider, answers differently on the two detail
routes. Both are deliberate, and this is the whole list:

| | `/service-requests/{id}` (SPEC 89) | `/service-requests/provider/{id}` (SPEC 99) |
|---|---|---|
| The `proveedor` role | not required | **required** — `403 provider_role_required` |
| A `requester` reading it | `200`, their own detail, complete | `403 forbidden` |
| `unit` for a provider reader | **always `null`** | `{id, name}` **when the job is already theirs** |
| The offers key | `offers`, trimmed to the reader | **`my_offers`**, and the name says so |
| `viewer` | `requester` or `provider` | always `provider` |
| Where `viewer` sits | **12th**, before `requester` | **14th**, after `condominium` |

Everything else — the other seventeen keys, their types, their nulls, the
timeline, the file route and the access rule for a provider — is identical. The
two orders differ only in the position of `viewer`, because each route appends
its own keys after the listing item it is built from, and the two listings do
not have the same number of keys.

**Why both exist.** Renaming `offers` to `my_offers` and uncovering `unit` on
the resident's route would break an app in production, and requiring the
`proveedor` role there would lock out accounts that read it without one. So the
provider's answer got its own route, the same way the listing did.

**What comes next.** The obvious work for the spec that continues this one is to
**deprecate the provider's access to the resident's route** and leave one answer
per reader. Until then, an app that reads a request as a provider should use
this route.

### Cost

**Six content queries, fixed**, plus the token's and the role questions, which
are cached per account: the request row, the requester's name, the images,
`offers_count`, my offers and the timeline. Exactly what the resident's detail
already costs, over one single node, and none of them grows with the number of
images, offers or transactions.

### Known limits

- **The `unit` does not expire.** A job awarded to one of my providers shows the
  flat in **any** status, `closed` and `cancelled` included — a job cancelled a
  year ago still names the address it was going to. It is the same surface the
  listing already exposes and it is not widened here; capping it by status or by
  date is a retention rule no spec has taken yet, and taking it only in the
  detail would put this route out of step with the board again.
- **The requester's name reaches the whole market.** Any active provider of the
  category reads the full name of whoever asked for the work just by opening an
  open request — without the address, since `unit` is `null` there, but with the
  name and the condominium. Inherited from the listing and not widened.
- **The back office keeps its own rule**, unchanged by this endpoint. See the
  listing's limits above.
