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

> **Known difference between this listing and the detail.** The detail still
> nulls `unit` for **every** provider reader, without the exception above. So in
> "my work" the provider sees the flat here, taps, and does not see it there.
> It is deliberate and not a bug: touching the detail too would reopen a privacy
> decision SPEC 89 took with its own reasoning. It is the obvious work for the
> spec that continues this one.

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
