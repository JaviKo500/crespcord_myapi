# Service offers

The marketplace's quote, from both sides: a provider bids on an open request of
their category, with the fields that turn *a text and a number* into a quote the
resident can compare — **or prices a `direct` request that was awarded to them
from the start**, which is the same body on the same route and the only place in
this module where the price of that job can live. And each side can then open
**one** of those quotes on a route of its own.

Seven routes:

- [`POST /api/v1/service-requests/{id}/offers`](#post-apiv1service-requestsidoffers) — create an offer. It hangs off the request because **an offer does not exist outside its request**.
- [`GET /api/v1/service-offers/provider`](#get-apiv1service-offersprovider) — **the provider's own archive**: *what have I quoted?*, across every request, paginated and filtered.
- [`GET /api/v1/service-offers/provider/{id}`](#get-apiv1service-offersproviderid) — **one of my offers, whole**: the fifteen keys plus the context of its request.
- [`GET /api/v1/service-offers/{id}`](#get-apiv1service-offersid) — **one of the offers I received**, for the resident who received it.
- [`PUT /api/v1/service-offers/{id}`](#put-apiv1service-offersid) — **correct my own offer**, by total replacement, while it is still `sent`.
- [`PUT /api/v1/service-offers/{id}/withdraw`](#put-apiv1service-offersidwithdraw) — **take my own offer back**, which is what finally writes `withdrawn`.
- [`PUT /api/v1/service-offers/{id}/accept`](#put-apiv1service-offersidaccept) — **the resident awards one of the offers on their request**, which is what finally writes `selected` and `assigned`.

> ⚠️ **`/api/v1/service-offers/{id}` carries two different actors.** The `GET`
> is the **resident's** — it answers `403` to a provider, even to the one who
> bid — and the `PUT` is the **provider's**, and answers `403` to a resident.
> Each side reads its own copy on its own route: the provider's is
> [`GET /api/v1/service-offers/provider/{id}`](#get-apiv1service-offersproviderid).
> A client that discovers this with the wrong token will conclude the endpoint
> is broken; it is not, it is one URL with a gate per method.

**The offers *of a request* are read elsewhere**, whole: inside
[the resident's detail](service-request.md) (`offers`) and inside
[the provider's detail](service-request-provider.md) (`my_offers`). There is no
`GET` of **that** collection, and that is deliberate — a third place to read
them would be a third thing to keep in agreement with `offers_count`.

**Neither the listing nor the two details below contradict that.** The listing
answers a different question: its set **crosses** requests instead of living
inside one, and each of its items carries **eight referential keys** — enough to
paint the row and open the detail, never half a detail. The two details serve
**one** offer to somebody who already had the right to read it. None of the
three publishes a counter of anybody, so there is nothing any of them can fall
out of step with.

---

## POST /api/v1/service-requests/{id}/offers

Creates the offer of one of the authenticated account's providers on an open
request of that provider's category, and — **when the offer is the first one** —
moves the request from `open` to `offered` and writes one entry on its timeline.

**It also carries the quote of a `direct` request**, sent by the provider it was
awarded to. That case moves **no status at all** and is documented on its own
below: [Quoting a `direct` request](#quoting-a-direct-request).

**Authentication:** required. And the `proveedor` role, and a provider node the
account operates.

**This is the first write in this module that moves the status of somebody
else's node.** The provider is not the request's `field_requester` and still
pushes it to `offered`. It is bounded to **one** transition, in **one**
direction, from **one** status, and only after the gate below has let them
through.

**Headers**

| Header | Value |
|--------|-------|
| Authorization | `Bearer <access_token>` |
| Content-Type | `application/json` |
| Accept-Language | `es` (default) or `en` |

**JSON and not `multipart/form-data`**, unlike
[`POST /api/v1/service-requests`](service-request.md#post-apiv1service-requests):
this endpoint uploads no file, and the multipart form exists only for that. The
day photos of previous jobs and a PDF quote arrive, they must come through an
**upload route of their own** over an already-created offer — never by changing
the format of this one, which would break every client already publishing
offers.

**The request travels in the route and never in the body.** With the nid in the
path there can be no `request_id` in the body contradicting the URL.

### Request body

Three fields are required. **Everything else is optional**, and an offer with
nothing but the three is accepted — a provider bidding from a phone, on site,
for a small job must be able to finish the form.

| Field | Type | Required | Notes |
|-------|------|:--------:|-------|
| `provider_id` | int > 0 | **Yes** | Which of *your* providers is bidding. Always explicit, even when the account operates only one — deriving it would choose in silence, and the day that account operated two, a client that never sent the field would start bidding with the wrong company without anything failing. |
| `message` | string, 1–2000 chars | **Yes** | What you are offering to do. **Flattened before it is measured and before it is stored** (`myapi_text_to_multiline()`): any markup is removed, the line breaks are kept. So the 2000 counts your words and not the tags, and a message of nothing but `<p></p>` is a `422`. |
| `amount_type` | `fixed` \| `estimate` \| `hourly` \| `on_site_quote` | **Yes** | How the amount is to be read. Mandatory because the number is unreadable without it: the same `150` means a closed price, a guess, an hourly rate or nothing at all. |
| `amount` | decimal ≥ 0, ≤ 99999999.99 | **conditional** | **Required** for `fixed`, `estimate` and `hourly`; **forbidden** for `on_site_quote`. `0` is a price somebody offered. May be sent as a number or as a string, so the decimals survive the wire. |
| `tax_included` | bool | No | Only alongside an `amount`. Omitting it is *I did not say*, which is a different answer from `false`. |
| `valid_until` | string `Y-m-d H:i` | No | Until when the offer stands — the deadline the resident has to accept it. Must be **strictly in the future**. |
| `available_from` | string `Y-m-d H:i` | No | When you could start the work. Strictly in the future. **Not compared against `valid_until`** — see below. |
| `duration` | int 1–9999 | No | Estimated duration. **Coupled with `duration_unit`: send both or neither.** |
| `duration_unit` | `hours` \| `days` | No | Same. |
| `includes` | string, ≤ 2000 chars | No | What the quote covers. Flattened like `message`. Empty after that is stored as absent. |
| `excludes` | string, ≤ 2000 chars | No | What it does not. |
| `warranty_days` | int 0–3650 | No | `0` is a declaration — *no warranty* — and not an absence. |
| `requires_visit` | bool | No | Defaults to `false`. |

**Booleans are read as JSON booleans and nothing else.** `"true"`, `"1"` and `1`
all answer `422 invalid_field`. The body is JSON, the client can send a real
boolean, and accepting the string `"false"` would open the door to reading it as
true.

**Lengths count characters, not bytes.** 2000 accented characters fit; the cut
is the one the provider can count.

**The two dates are not a range, and neither is compared against the other.**
`valid_until` is a deadline on the *resident's decision*; `available_from` is a
date on the *provider's execution*. There is no required order between them, and
the frequent case is the one a range would forbid: *"I need an answer before 8,
and I can start at 11."* Both dates are validated on their own — parseable and
strictly in the future — and nothing else. Until SPEC 104 this endpoint refused
`available_from` later than `valid_until` with a `422`; it no longer does.

**Unknown keys are ignored, not refused.** A `status` in the body changes
nothing: the offer is born `sent` whatever it says, because the status is not a
field of this request.

**Example (the minimum):**
```bash
curl -i -X POST https://host/api/v1/service-requests/128/offers \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{"provider_id": 41, "amount_type": "on_site_quote", "message": "Tengo que verlo antes de dar precio."}'
```

**Example (a full quote):**
```bash
curl -i -X POST https://host/api/v1/service-requests/128/offers \
  -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' \
  -d '{
        "provider_id": 41,
        "message": "Puedo pasar el jueves por la mañana.",
        "amount_type": "fixed",
        "amount": 150.50,
        "tax_included": true,
        "valid_until": "2026-09-01 23:59",
        "available_from": "2026-08-27 08:00",
        "duration": 3,
        "duration_unit": "hours",
        "includes": "Mano de obra, desplazamiento y sellado.",
        "excludes": "El calentador de repuesto, si hiciera falta.",
        "warranty_days": 90,
        "requires_visit": false
      }'
```

### Who may bid: seven conditions, in this order

They are evaluated in order and **the first one that fails answers**, which is
what makes the error actionable instead of arbitrary.

| # | Condition | If it fails |
|---|-----------|-------------|
| 0 | The account holds the `proveedor` role | `403 provider_role_required` |
| 1 | `provider_id` is one of *your* providers | `403 service_offer_provider_not_owned` |
| 2 | That provider is **active**: published, with a licence still in date | `403 service_offer_provider_not_active` |
| 3 | The request exists, is a `service_request` and is published | `404 service_request_not_found` |
| 4 | You are **not** its requester | `403 service_offer_own_request` |
| 5 | It is in `open` or `offered` with nothing awarded on it, **or it is a `direct` awarded to the very provider that is bidding** | `409 service_request_not_offerable` |
| 6 | Its category is one your provider serves — **skipped on a `direct` of your own** | `403 service_offer_category_mismatch` |
| 7 | That provider has no **live** offer on it already | `409 service_offer_already_sent` |

**All seven run before a single field of the body is validated.** Who you are
does not depend on what you wrote, so an empty body over a cancelled request
answers `409` and never `422`.

**Condition 0 has no exception, administrators included.** Operating a provider
is a marketplace fact, not a permission, so no role short-circuits it.

**Condition 3 comes after the provider on purpose:** an account with no standing
to bid learns nothing about which request nids exist.

**Condition 5 reads the raw award columns**, not the resolved ones. An award
pointing at an *unpublished* provider is still an award, and reading the
resolved value would quietly reopen a request that is already somebody's job.

**Condition 5 has two branches, and only two:**

| The request is… | Offerable when |
|---|---|
| `open` or `offered` | Nothing is awarded on it — **neither** `field_assigned_offer` **nor** `field_assigned_provider`. |
| `direct` | `field_assigned_provider` is **exactly the `provider_id` that is bidding**, and `field_assigned_offer` is still empty. |
| anything else | Never. |

**A `direct` awarded to somebody else answers the same `409`, not a code of its
own.** A provider who is not the awarded one cannot even *see* that request —
neither the detail nor the marketplace listing shows it to them — so the shared
code hides nothing they did not already not know, while a specific one would
**confirm** to them that the job is taken.

**`field_assigned_offer` must be empty even on a `direct` of your own.** A
request that already carries an awarded offer went through a round of bidding,
and that is not the case this branch opens.

**An empty or corrupt `field_request_status` answers `409`, never `500`.**

**Condition 6 needs no special case for a provider with no categories:** an
empty set matches nothing, and *you do not serve this category* is the true
statement then too.

**Condition 6 is skipped on a `direct` of your own, and on nothing else.** The
provider was already checked against the category
[when the request was created](service-request.md#post-apiv1service-requests);
asking again here would lock a company out of a job **the resident personally
handed them**, only because they stopped serving that category afterwards. The
resident's choice outranks the catalogue.

**Condition 2 is *not* skipped with it.** The category says *what* you do; the
licence says *whether you may operate at all*. A company whose licence expired
does not invoice, even for a job that was already theirs — and unlike the
category, the way out is immediate and in their own hands. So a `direct` of your
own, sent with an unpublished or expired provider, answers
`403 service_offer_provider_not_active` and **not** the `409`.

**Condition 7 — "live" means `sent` or `selected`.** An offer in `rejected` or
`withdrawn` does not compete, so it blocks nothing and you may bid again. **Two
different providers of the same account may both bid on the same request**: they
are two companies in this data model, with separate licences, categories and
ratings, and the resident contracts the provider, not the account.

> ⚠️ **A zero too many is fixable, but not from this route.** Condition 7 blocks
> a second offer while the first is `sent`, so the way back is one of the two
> routes that write on an offer that already exists:
> [`PUT /api/v1/service-offers/{id}`](#put-apiv1service-offersid) corrects it in
> place, and [`PUT /api/v1/service-offers/{id}/withdraw`](#put-apiv1service-offersidwithdraw)
> takes it back and frees the request for a new one.
>
> **Correcting is what the app should offer**, not withdrawing and re-sending:
> the second leaves **two nodes** in the resident's listing, loses the original
> `created` and makes `offers_count` count two offers from one company.
>
> **On a `direct` this used to be fatal**, because there is no competing offer
> to absorb the mistake and the resident's only exit was cancelling the job.
> That is exactly the case the two routes above opened. Confirm the send in the
> app with a summary of what is going out, and show the amount **in large type**
> on the confirmation screen anyway.

### What the server decides, and the client never sends

| Field | Value |
|-------|-------|
| `node.type` | `service_offer` |
| `node.uid` | The uid of the Bearer token — the **account** that bid |
| `node.status` | `1` |
| `node.title` | *"Oferta de &lt;proveedor&gt; — solicitud #&lt;nid&gt;"*, truncated to 255 |
| `field_request` | The `{id}` of the route |
| `field_offer_status` | Always `sent` |
| `field_firebase_path`, `field_chat_opened_at`, `field_last_message_at` | Empty at creation — the chat fills them in later, see the note below |

**A new offer is born with the three chat fields empty, and the chat works
before any of them is written.** SPEC 115 ([chat.md](chat.md)) added
`POST /api/v1/chat/token` without writing one of them: the path of a thread is a
**convention over the offer's `nid`** — `service_offers/{nid}` — derived on
every signature and never stored. It said that "the day the back office has to
see a thread, `field_firebase_path` gets written **with the same value**, and
nothing in the app changes", and that is exactly what SPEC 117 did.

**They are a read-only mirror for the back office.** The credential writes
`field_firebase_path` the first time it covers the thread; the new-message
notice writes `field_chat_opened_at` once and moves `field_last_message_at`
every time. **Nothing reads them**: no endpoint returns them, no query orders by
them, no condition looks at them, and `myapi_chat_thread_id()` is still the
single source of truth for the path. Editing them by hand dirties this ficha and
breaks nothing — the old warning under `field_firebase_path` on the node form,
which said the opposite, was corrected by `myapi_update_7042()`.

**There was no backfill**, so on a thread that already existed before that
deploy, empty means *"neither side has launched the app since"* — not *"there
was no conversation"*. The full table of who writes what, and when, is in
[chat.md](chat.md#the-three-mirror-fields).

`node.uid` and `field_provider` are **two different things and both are
written**: a provider may be operated by several accounts, and the offer has to
know which of them sent it as well as which company it commits.

**An optional value you did not declare is not written at all**, rather than
written as an empty row. That is what makes a new offer that declared nothing
indistinguishable from one stored before SPEC 100 — which is exactly right.

### The three writes

| # | Write | `open` | `offered` | `direct` of your own |
|---|-------|:---:|:---:|:---:|
| 1 | **The offer**, always `sent` | ✅ | ✅ | ✅ |
| 2 | **The request**, to `offered` | ✅ | — | **—** |
| 3 | **A `service_transaction`** | ✅ | — | **✅** |

**Write 2 happens only if the request moves.** `open` → `offered`, and the
transition is *asked* of the status graph, never transcribed. A request already
in `offered` is not saved at all — no no-op save — which is what keeps its
`changed` timestamp honest. **A `direct` is never saved either**: it comes out
of this endpoint with the same status, the same awarded provider and the same
`changed` it went in with.

**Write 3 follows the status change — with one deliberate exception.**
`service_transaction` has been *one entry per status change* since SPEC 77, and
the third offer on an already-`offered` request changes none. Recording it would
write a timeline row whose status repeats the one before it; the resident learns
about the second and third offers from `offers_count` and from `offers`, not
from the history.

**The quote of a `direct` is that exception, and it writes an entry anyway.** It
is the one thing the `open` → `offered` move gives that would otherwise be lost:
the resident seeing *"your provider sent you a quote"* on their timeline instead
of having to notice a new element inside an array their screen may not even be
painting. Its `field_request_status` **repeats** the `direct` the request already
had, because that is the truth — the status did not change — and the **comment**
is what tells the two entries apart.

**Neither `field_assigned_offer` nor `field_assigned_provider` is ever touched:
bidding is not awarding.** On a `direct`, quoting is not awarding either — the
award already happened, when the request was created.

> **The three writes are not one atomic transaction.** If write 2 or 3 failed,
> an offer would be left on an `open` request. That is the state the module
> **already admits today** — an offer created from the back office moves nothing
> — and the one
> [`POST /api/v1/service-requests/{id}`](service-request.md#post-apiv1service-requestsid)
> already defends against by counting offers instead of trusting the status. The
> failure is logged to `watchdog`, never swallowed.

### Success response (201)

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": null },
      "amount": 150.5,
      "message": "Puedo pasar el jueves por la mañana.",
      "status": "sent",
      "created": "2026-08-25T11:02:00",
      "amount_type": "fixed",
      "valid_until": "2026-09-01T23:59:00",
      "available_from": "2026-08-27T08:00:00",
      "duration": { "value": 3, "unit": "hours" },
      "includes": "Mano de obra, desplazamiento y sellado.",
      "excludes": "El calentador de repuesto, si hiciera falta.",
      "tax_included": true,
      "warranty_days": 90,
      "requires_visit": false
    },
    "request": { "id": 128, "status": "offered" }
  },
  "message": "Oferta enviada correctamente."
}
```

**The object under `service_offer` is byte for byte the element that will appear
in `my_offers`** the next time this provider reads
[`GET /api/v1/service-requests/provider/{id}`](service-request-provider.md),
because it comes out of the same serialiser. Fifteen keys, always, in this
order — see
[the offer object](service-request.md#each-offer-fifteen-keys-always-in-this-order)
for what each one means and how it is typed.

**`provider.logo` is `null` here even when the provider has one.** It costs a
query and a join for a value the bidding app already has; it travels in
`my_offers` and in `offers`, where the screen that paints it needs it.

**`request` is a sibling of `service_offer`, not a sixteenth key of it**, and it
carries only `id` and `status`. It is the one thing you cannot deduce from what
you just sent — whether your offer was the first, and so whether the request
moved — and `status` is the one **after** the write. On a `direct` it answers
`"direct"`, which is exactly why the key exists: the client does not have to
guess whether anything moved.

**This write notifies the resident (SPEC 110).** After the offer is saved (and
the request/transaction writes above, when they apply), the resident who
requested it — never the provider that just bid, and never the `backend`
role, which already knows about the request since SPEC 109 — gets a push, an
inbox row and an email with a button into the app, once per offer. It is
best-effort: a failure to notify never changes this `201`. Full contract in
[`docs/service-request-notifications.md`](service-request-notifications.md#offer-received-spec-110).

### Possible errors

| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method but `POST`, **including `GET`**. Answered before the token and before any query. |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Invented, revoked or expired token. |
| 404 | `service_request_not_found` | `{id}` is not a positive integer (answered with no query at all), or names no published `service_request`. |
| 403 | `provider_role_required` | The account does not hold the `proveedor` role. |
| 422 | `missing_field` | `provider_id`, `message` or `amount_type` absent — `@field` names which. A body that is missing or unparseable is reported as a missing `message`. |
| 422 | `invalid_field` | Any field present with the wrong type, format or range — `@field` names which. |
| 403 | `service_offer_provider_not_owned` | `provider_id` is not one of the account's providers. |
| 403 | `service_offer_provider_not_active` | It is, but the node is unpublished or its licence has expired or is empty. |
| 403 | `service_offer_own_request` | You are the request's `field_requester`. |
| 409 | `service_request_not_offerable` | The request is not in `open`/`offered`, or something is already awarded on it — **or** it is a `direct` that is not awarded to the provider you are bidding with, or one that already carries an awarded offer. |
| 403 | `service_offer_category_mismatch` | Your provider does not serve the request's category. **Not answered on a `direct` of your own**, where the category is not asked. |
| 409 | `service_offer_already_sent` | That provider already has a `sent` or `selected` offer on this request. |
| 422 | `service_offer_amount_required` | `amount_type` is `fixed`, `estimate` or `hourly` and no `amount` came. |
| 422 | `service_offer_amount_not_allowed` | `amount_type` is `on_site_quote` and an `amount` came. |
| 422 | `service_offer_tax_without_amount` | `tax_included` without an `amount`. |
| 422 | `service_offer_duration_incomplete` | One of `duration` / `duration_unit` without the other. |

**A `403` or a `409` from the gate always beats a `422` from the body.**

**The order of the body rules is the contract too**, and the first broken one
answers: `message`, `amount_type`, `amount`, `tax_included`, `valid_until`,
`available_from`, `duration`/`duration_unit`, `includes`/`excludes`,
`warranty_days`, `requires_visit`.

---

## Quoting a `direct` request

A [`direct` request](service-request.md) is born **already awarded**: the
resident picked the company themselves, so there was never a round of bidding.
And that left a hole — **`service_request` has no monetary field of its own**.
The only place in this module where the price of a job lives is
`field_offer_amount`, and that field is on the offer. A `direct` with no offer
has no price, and by design it never will have one.

This route is how that price gets written. Same URL, same body, same `201`.

**What is different, and it is only three things:**

| | `open` / `offered` | `direct` of your own |
|---|---|---|
| Who may send it | Any active provider of the category | **Only the awarded provider** |
| The category | Checked | **Not checked** |
| The request's status afterwards | `offered` | **`direct`, unchanged** |

### The status does not move, and that is the point

A `direct` moved to `offered` could be **closed without rating the provider**:
the rule that makes a rating compulsory answers *yes* for `assigned` and
`direct` and *no* for `offered`. It would fail silently, on a real job, with a
real company left unrated. And `offered` means *not awarded*, which would
contradict the `field_assigned_provider` the request carries.

A `direct` moved to `assigned` **by the mere arrival of your quote** would
record as *agreed* a price the resident never accepted — and it would take away
your own way back, because editing and withdrawing both require the offer to be
`sent`. A wrong zero would be frozen in place.

Standing still is the only option that breaks nothing. So after your quote:

- the request is still `direct`, with its `changed` untouched;
- closing it **still requires rating you**;
- `field_assigned_offer` is still empty — quoting is not awarding;
- your offer is `sent`, and **you can still correct it or take it back**.

> **What moves it is the resident, and only the resident.** Since SPEC 107 they
> can accept your quote with
> [`PUT /api/v1/service-offers/{id}/accept`](#put-apiv1service-offersidaccept),
> which takes the request to `assigned` and your offer to `selected`. That is
> the moment the price is agreed — an act, not a side effect. Until they do it,
> everything above holds.

### What the resident sees

- A new entry on the timeline: *"&lt;your company&gt; envió su presupuesto."*,
  carrying `field_request_status: "direct"` — the status it already had.
- The offer inside `offers`, with `offers_count` now `1` on a `direct` request.
- **They cannot edit the request any more.** The edit gate allows `open` or
  `direct` **with zero offers**, and it counts offers instead of trusting the
  status, so your quote closes it. That is the intended rule: the statement of
  work stops changing the moment there is a price on it.

> ⚠️ **A timeline entry whose status repeats the previous one is new here.** A
> client that assumed *every entry is a status change* will paint "Directa"
> twice. The **comment** is the headline; the status is data.

> ⚠️ **`offers_count` on a `direct` can now be `1`.** A client that assumed
> `direct ⇒ 0 offers` breaks — but that assumption was **already false**:
> nothing has ever stopped an offer being created on a `direct` from the back
> office, which is precisely why the edit gate counts offers. This endpoint does
> not create the case; it puts a door on it.

### What is still missing on a `direct`

- ~~**The resident cannot accept or reject the quote.**~~ **✅ Resuelto por
  SPEC 107** for accepting: the resident awards the quote with
  [`PUT /api/v1/service-offers/{id}/accept`](#put-apiv1service-offersidaccept),
  which is what finally moves a `direct` to `assigned` and fills
  `field_assigned_offer`. **Rejecting one specific quote is still not here** —
  the resident's exits are accepting it or cancelling the request.
- **The chat is still closed.** Its three fields have been empty since SPEC 77.
  What changed is that the row they would hang off **now exists** — before this,
  a `direct` had no offer and therefore no possible thread.

---

## GET /api/v1/service-offers/provider

The provider's own archive: **every offer one of your providers has sent**,
whatever request it hangs off and whatever became of it. Paginated, filtered and
**referential** — eight keys per item, enough to paint the row and open the
detail.

It answers the one question no other endpoint answers: ***what have I
quoted?***. Without it, a provider reviewing the fifteen quotes they sent this
month has to open fifteen request details, and only if they remember which ones
they were.

**Authentication:** required. And the `proveedor` role.

**Headers**

| Header | Value |
|--------|-------|
| Authorization | `Bearer <access_token>` |
| Accept-Language | `es` (default) or `en` |

**Example**

```bash
curl -i -G https://host/api/v1/service-offers/provider \
  -H 'Authorization: Bearer <access_token>' \
  -d provider_id=41 \
  -d status=sent,selected \
  -d limit=20
```

### Query parameters

`provider_id` is **mandatory**. Everything else is optional, and only
`category_id` can be *wrong* rather than merely unknown.

| Parameter | Type | Required | Default | Notes |
|-----------|------|:--------:|---------|-------|
| `provider_id` | int > 0 | **Yes** | — | Which of *your* providers you are asking about. |
| `page` | int > 0 | No | `1` | Garbage falls back to `1`, never a `422`. |
| `limit` | int > 0, or `-1` | No | `20` | Trimmed to **50**. `-1` returns everything on one page and forces `page: 1`. Garbage falls back to `20`. |
| `sort` | `asc` \| `desc` | No | `desc` | Over the **offer's** `created` — when *you* quoted, never when the request was asked for. An unknown value falls back to `desc`. |
| `status` | comma-separated | No | — | The **offer's** status: `sent`, `selected`, `rejected`, `withdrawn`. Unknown keys are dropped in silence; `status=sent,inventado` filters by `sent`. |
| `request_status` | comma-separated | No | — | The **request's** status: `open`, `direct`, `offered`, `assigned`, `closed`, `cancelled`. Same laxity. |
| `category_id` | int > 0 | No | — | The tid of the **request's** category. **The one filter that answers `422 invalid_field`** when malformed, because the same parameter name already does so in [`GET /api/v1/providers`](provider.md) and in both request listings. |
| `date_from` / `date_to` | `YYYY-MM-DD` | No | — | Over the **offer's** `created`, **inclusive at both ends**: an offer sent at 23:50 of `date_to` is inside the range. A malformed bound is dropped in silence, and an inverted pair drops the whole range. |

**`status` and `request_status` are two parameters and not one**, because they
are two catalogues with **no value in common**: `sent` is not a state a request
can be in, and `cancelled` is not a state an offer can be in. Sending one to the
other's filter simply matches nothing.

**Any parameter this endpoint does not read is ignored in silence.**
`?unit_id=3` or `?foo=bar` never produce a `422`.

### Why `provider_id` is mandatory

An account may operate several providers. Asking without saying which is asking
something this endpoint does not answer, and *"both mixed together"* would be
inventing an aggregate format nobody designed.

**It is not derived even for an account that operates a single provider.**
Choosing in silence works until the day there are two, and then a client that
never sent the field starts reading the wrong list without anything failing —
the same decision the creation endpoint made about the `provider_id` in its
body. It is born strict because relaxing it later is compatible and tightening
it is not.

**Strict in the format, lax in the ownership.** `abc`, `0`, `-1`, `1,2` or
`" 41"` is a broken client and earns its `422` **before a single query runs**;
a nid that belongs to somebody else, or to no node at all, is **not an error**
and answers `200` with an empty list. A `403` there would only turn a listing
into an oracle for *"this nid exists and is not yours"*.

### What is in the list, and what is not

An offer appears when **all four** hold:

1. The offer is **published**.
2. Its `field_provider` is the `provider_id` asked for.
3. That `provider_id` is one of the account's providers.
4. Its request **exists and is published**.

**And nothing else filters.**

| | |
|---|---|
| Every **offer status** | `sent`, `selected`, `rejected` and `withdrawn` all appear. |
| Every **request status** | `cancelled` included. A `direct` (quoted with the same route above) appears with nothing special in the item. |
| A **suspended** provider | Reads its whole archive. |
| A provider with an **expired licence** | Reads its whole archive. |
| An **unpublished** offer | Never appears. |
| An offer whose **request was unpublished or deleted** | Never appears, and `pagination.total` does not count it. |
| An offer of **another provider** | Never appears, not even on a request this provider also bid on. |

**The licence governs the market — being able to quote — and not the archive of
what was already quoted.** A company that lost access to its own quotes would
lose the proof of work it did.

**The request is `INNER JOIN`ed on purpose.** Every item of this list has to be
openable; an offer whose request disappeared is a row that answers `404` the
moment it is touched. The alternative — listing it with `request: null` — would
make the client paint a row that leads nowhere and tell two classes of row apart.

**`valid_until` is never compared with today.** An expired offer travels just the
same, with its date, and **the client decides** whether it is lapsed: expiry
depends on the instant it is read, and a list cached ten minutes would lie at
the edge.

### Success response (200)

```json
{
  "success": true,
  "data": {
    "service_offers": [
      {
        "id": 901,
        "status": "sent",
        "amount": 95.5,
        "amount_type": "fixed",
        "created": "2026-08-25T10:14:00",
        "valid_until": "2026-09-01T23:59:59",
        "provider": { "id": 41, "name": "Plomería Torres" },
        "request": {
          "id": 128,
          "title": "Fuga en el calentador",
          "status": "assigned",
          "category": { "id": 12, "code": "plumbing", "name": "Plomería" }
        }
      }
    ],
    "pagination": { "total": 1, "page": 1, "limit": 20, "total_pages": 1 }
  }
}
```

### Each item: eight keys, always, in this order

| Key | Type | Notes |
|-----|------|-------|
| `id` | int | The **offer's** nid. |
| `status` | string \| null | `sent`, `selected`, `rejected`, `withdrawn`. `null` only on a corrupt row. |
| `amount` | float \| null | Never `"95.50"` and never `0.0` for an absence — `0` is a price somebody offered. `null` when `amount_type` is `on_site_quote`. |
| `amount_type` | string \| null | `null` on **every offer stored before the quote fields existed**. Nothing backfilled them and nothing will. |
| `created` | string | `Y-m-d\TH:i:s`. |
| `valid_until` | string \| null | `Y-m-d\TH:i:s`. Not compared with today — see above. |
| `provider` | object | `{id, name}`. **Never `null`.** |
| `request` | object | `{id, title, status, category}`. **Never `null`**, by the `INNER JOIN`. |
| `request.category` | object | `{id, code, name}`. `code` is `""` — never `null` — when the term carries none. |

**The first six keys are byte for byte the ones the fifteen-key offer object
answers**, because they come out of **the same serialiser**. This item is that
object *trimmed*, never a second one: the day the format of `amount` changes, it
changes in both places or in neither.

**`provider` is `{id, name}` and not `{id, name, logo}`**, unlike `offers` and
`my_offers`. The logo is the reader's **own**, which the app already has, and
fetching it would cost a join to `file_managed` on every row to paint the same
image twenty times. It is resolved **once** for the whole page and copied into
each item, so the response never joins per row.

**`request` carries no `description`, no `unit`, no `offers_count` and no
`assigned_provider`.** It is the label that lets the client paint the row and
open the detail, not half a detail.

**Not one datum of the competition or of the customer travels.** No resident, no
condominium, no flat, no count of rival offers and no amount of theirs. What a
provider reads here is its own archive.

**The seven long keys are not here either** — `message`, `includes`, `excludes`,
`duration`, `tax_included`, `warranty_days`, `requires_visit`,
`available_from`. They are read where they are already read: inside
[`GET /api/v1/service-requests/provider/{id}`](service-request-provider.md), in
`my_offers`, with all fifteen. Multiplied by fifty rows they would turn a
listing into a download.

### Pagination

`{total, page, limit, total_pages}`, byte for byte the block both request
listings answer.

- `total` describes the **filtered set**, not the page.
- `total_pages` is **`0`** when `total` is `0` — never `1`.
- Asking for a page past the last one answers `200` with `service_offers: []`
  and the real `total`.
- `limit: -1` answers `total_pages: 1` (or `0`) and forces `page: 1`.
- An empty answer **echoes the `limit` the caller asked for**, so a client that
  sent `?limit=50` is not told it got `20`.

### Possible errors

| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method but `GET`. Answered **before the token** and before any query. |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Invented, revoked or expired token. |
| 403 | `provider_role_required` | The account does not hold the `proveedor` role. **No exception for administrators.** |
| 422 | `missing_field` | `provider_id` absent — `@field` names it. |
| 422 | `invalid_field` | `provider_id` or `category_id` present and malformed — `@field` names which. Answered **before any offer query**. |

**The order is the contract:** method → token → role → `provider_id` → the rest.
The role is checked **before** `provider_id`, so a reader who may not be here
does not learn whether their parameter was well formed; `provider_id` is checked
**before** any query, so a `422` costs nothing.

**An account that holds the role and is linked to no provider is not a `403`.**
It reaches the parameter, and whatever nid it sends is not its own, so it reads
`200` with an empty list: having the role and being linked to nothing is missing
data, not a missing permission.

**A foreign or non-existent `provider_id` is `200` with an empty list** — never
`403`, never `404`.

---

## GET /api/v1/service-offers/provider/{id}

**One of my offers, whole**: the fifteen keys of the offer plus the referential
context of the request it hangs off. It is what makes a row of the archive
*openable*.

Until it existed, reading the fifteen keys of **one** offer meant downloading
the entire detail of its request —
[`GET /api/v1/service-requests/provider/{id}`](service-request-provider.md) —
with its images, its timeline, the count of the competition and the rest of your
own offers, in order to keep one of them.

**Authentication:** required. And the `proveedor` role.

**Headers**

| Header | Value |
|--------|-------|
| Authorization | `Bearer <access_token>` |
| Accept-Language | `es` (default) or `en` |

**Example**

```bash
curl -i https://host/api/v1/service-offers/provider/901 \
  -H 'Authorization: Bearer <access_token>'
```

**Success response (200)**

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": "https://host/sites/default/files/logo.png" },
      "amount": 95.5,
      "message": "Cambio de resistencia y purgado del circuito.",
      "status": "selected",
      "created": "2026-08-25T10:14:00",
      "amount_type": "fixed",
      "valid_until": "2026-09-01T23:59:59",
      "available_from": "2026-08-27T09:00:00",
      "duration": { "value": 2, "unit": "hours" },
      "includes": "Material y desplazamiento.",
      "excludes": null,
      "tax_included": true,
      "warranty_days": 30,
      "requires_visit": false,

      "request": {
        "id": 128,
        "title": "Fuga en el calentador",
        "status": "assigned",
        "category":    { "id": 12, "code": "plumbing", "name": "Plomería" },
        "condominium": { "id": 7,  "name": "Residencial Los Álamos" },
        "unit":        { "id": 55, "name": "Apto 302" },
        "requester":   { "id": 314, "name": "María Crespo" }
      }
    }
  }
}
```

### Sixteen keys, and `request` is the sixteenth

**The first fifteen are the offer object, whole and untouched** — the same
fifteen `offers` and `my_offers` answer, from the same serialiser. The `(float)`
of `amount`, the `Y-m-d\TH:i:s` of the three dates, the rule that an empty
optional text is `null`, the rule that `message` stays `""` because it is
*required*, and the rule that `requires_visit` is **never** `null` are written
once and read from four places. The day the format of `amount` changes, it
changes in all four or in none.

See the [item table of the archive](#each-item-eight-keys-always-in-this-order)
for the six shared keys, and the
[creation response](#post-apiv1service-requestsidoffers) for all fifteen.

**`request` is a key *of the offer*, not a sibling of it in `data`.** In the
creation response it travels outside the object because there it reports the
**effect** of the write on the request; here it is the **context** of the offer
being read, and an offer without its request cannot be painted.

### `request`: seven keys, always the seven

| Key | Type | Notes |
|-----|------|-------|
| `id` | int | The **request's** nid. |
| `title` | string | `""` if missing, never `null`. |
| `status` | string \| null | `open`, `offered`, `direct`, `assigned`, `closed`, `cancelled`. |
| `category` | object | `{id, code, name}`. `code` is `""` — never `null` — when the term carries none. |
| `condominium` | object \| null | `{id, name}`. `name` is the **node title**: the `condominio` bundle has no name field. A **whole** `null`, never `{id: null, name: null}`. |
| `unit` | object \| null | `{id, name}`. `name` is `field_nombre_vivienda` and **not** the node title. |
| `requester` | object \| null | `{id, name}`. **No phone, no email, no address.** |

**It carries no `description`, no `desired_start`, no `closed_at`, no
`offers_count` and no `assigned_offer`.** It is *referential by decision*, not
half a detail: the whole request is read where it is already read.

### Who sees the unit and the requester

**The seven keys always travel; two change what they carry.** A client that
reads the same seven keys in both routes has nothing to branch on.

| Key | `/provider/{id}` | `/{id}` |
|-----|------------------|---------|
| the fifteen of the offer | identical | identical |
| `request.condominium` | **always** | **always** |
| `request.unit` | **only if the job is yours**, `null` otherwise | **always** — it is their home |
| `request.requester` | **only if the job is yours**, `null` otherwise | **always `null`** — it is them |

**"The job is yours"** means the *request* is awarded to one of your providers —
`field_assigned_provider` — and **not** that your offer is `selected`. Normally
the two agree; when they diverge the **award** wins, because it is the one that
says which house you are going to:

- Your offer is `selected` but the request ended up awarded to somebody else →
  `unit: null`, `requester: null`.
- Your offer is `rejected` or `withdrawn` and the request is awarded to you →
  **both travel**. The work is yours either way.
- The award points at a provider node that was deleted or unpublished →
  `unit: null`, `requester: null`. A broken reference **closes** the address, it
  does not open it.

**`condominium` travels always and for everyone**, with no condition. It names
the residential complex, not a person and not a door, and without it a provider
does not know which city it is quoting for.

**`unit` and `requester` are a whole `null`**, never `{id: null, name: null}`.
You cannot tell *"not awarded to me"* from *"the unit was deleted"*, and there is
no reason to: in both cases there is no address to paint.

### What is served, and what is not

**Three conditions and no more.** An offer is servable when it is published, is
of the `service_offer` bundle, and **its request is published**. There is no
condition on the offer's status, on the request's status, on the provider's
published flag, or on `field_license_expiry`:

- An offer `withdrawn` on a request `cancelled` from a **suspended** provider is
  served **whole**. The licence governs the *market* — being able to quote — and
  not the record of what was already quoted.
- A suspended provider's offer answers `provider: null`, exactly as `offers`
  already answers it for the same row. The offer itself is served.
- An offer stored **before the quote fields existed** answers `amount_type`,
  `valid_until`, `available_from`, `duration`, `includes`, `excludes`,
  `tax_included` and `warranty_days` as `null`, `requires_visit` as `false`, and
  is served with `200`.
- An offer on a `direct` request is served with nothing different about it.

**`is_expired` is not here, nor any field computed over `valid_until`.** The date
travels; the server and the client share neither a clock nor a timezone, and a
boolean computed on the server expires in transit.

### Possible errors

| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method but `GET`. Answered **before the token** and before any query. |
| 404 | `not_found` | `{id}` is not a positive integer (`abc`, `0`, `-1`, `1,2`, `" 41"`). **No query runs.** |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Invented, revoked or expired token. |
| 403 | `provider_role_required` | The account does not hold the `proveedor` role. **No exception for administrators**, and answered **before any offer query**. |
| 404 | `not_found` | The offer does not exist, is unpublished, is of another bundle, or **its request is not published**. |
| 403 | `forbidden` | The offer is not one of the account's providers', or the account is linked to no provider at all. |

**The order is the contract:** id → token → role → the offer → ownership.

**The four `404`s are indistinguishable**: same `error_code`, same message. You
are not told which of the four it was.

**A foreign offer is `403` and not `404`.** The module already answers `403` to a
foreign request, and a provider debugging an integration has to be able to tell
*"not yours"* from *"does not exist"*. What the `403` leaks — that an offer with
that nid exists — says nothing about whose it is, for how much, or on what.

**The requester of the request does not get in here** even if they also hold the
`proveedor` role, unless the offer belongs to one of *their* providers. Being the
customer is [the other route](#get-apiv1service-offersid).

---

## GET /api/v1/service-offers/{id}

**One of the offers I received**, for the resident who received it.

The offers of a request already reach the resident inside
[`GET /api/v1/service-requests/{id}`](service-request.md), in `offers`, with all
fifteen keys, and **that does not change**. What had no answer was **opening one
offer from a notification or a deep link**: the client knows the nid of the offer
and not the nid of the request, and to paint a screen about *one* offer it had to
download the whole request — the rival offers, their amounts and their messages —
in order to keep one of them.

**Authentication:** required. **No role.**

**Headers**

| Header | Value |
|--------|-------|
| Authorization | `Bearer <access_token>` |
| Accept-Language | `es` (default) or `en` |

**Example**

```bash
curl -i https://host/api/v1/service-offers/901 \
  -H 'Authorization: Bearer <access_token>'
```

**Success response (200)**

The same sixteen keys as the route above. Two of them carry something else:

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": "https://host/sites/default/files/logo.png" },
      "amount": 95.5,
      "message": "Cambio de resistencia y purgado del circuito.",
      "status": "selected",
      "created": "2026-08-25T10:14:00",
      "amount_type": "fixed",
      "valid_until": "2026-09-01T23:59:59",
      "available_from": "2026-08-27T09:00:00",
      "duration": { "value": 2, "unit": "hours" },
      "includes": "Material y desplazamiento.",
      "excludes": null,
      "tax_included": true,
      "warranty_days": 30,
      "requires_visit": false,

      "request": {
        "id": 128,
        "title": "Fuga en el calentador",
        "status": "assigned",
        "category":    { "id": 12, "code": "plumbing", "name": "Plomería" },
        "condominium": { "id": 7,  "name": "Residencial Los Álamos" },
        "unit":        { "id": 55, "name": "Apto 302" },
        "requester":   null
      }
    }
  }
}
```

> ### `requester` is **always** `null` here, and that is deliberate
>
> **It is not a bug and not a missing datum.** The reader *is* the requester, so
> the key would be telling them their own name. It still travels, as one of the
> seven, because a client that reads the same seven keys in both routes has
> nothing to branch on — a key that sometimes exists forces a check of its
> presence *on top of* a check of its value.
>
> If you need the requester's name on this screen, it is the authenticated
> account's own.

**`unit` travels always** — or a whole `null` when the request has no unit. The
*"only if the job is yours"* rule exists to keep a resident's address away from a
provider who is not going to that house; the resident lives there.

Everything else — the fifteen keys, the seven keys of `request`, the servable
set, the typing rules — is
[exactly as described above](#get-apiv1service-offersproviderid).

### No role is demanded

There is **no `residente` role** in this module, and what grants access is not a
label on the account but a **fact about the data**: being the requester of the
request the offer hangs off. It is resolved by the same function the request's
own detail uses, so the two can never disagree about who the resident is.

### Possible errors

| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method but `GET`. Answered **before the token**. |
| 404 | `not_found` | `{id}` is not a positive integer. **No query runs.** |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Invented, revoked or expired token. |
| 404 | `not_found` | The offer does not exist, is unpublished, is of another bundle, or **its request is not published**. The **same** four cases as the provider's route: which offers exist cannot depend on who is asking. |
| 403 | `forbidden` | The account is not the requester of the request this offer hangs off. |

**The order is the contract:** id → token → the offer → the request → who is
asking.

**A provider who bid gets `403` here, and so does the awarded one.** For them
there is [the other route](#get-apiv1service-offersproviderid), which is where
the role gate and the ownership gate live.

**A building administrator gets `403` too.** The request's own detail does not
let them in either; making the offer the first exception would be an exception
that starts at the leaf and not at the root. If it is ever needed, it is a spec.

---

## PUT /api/v1/service-offers/{id}

Corrects one of the authenticated account's offers, **by total replacement**,
while it is still `sent`. It is the answer to *"I sent a zero too many"* that
did not exist until now.

**Authentication:** required — Bearer token, and the account must hold the
`proveedor` role.

**This is the provider's verb on a URL whose `GET` belongs to the resident.**
See the warning at the top of this document.

**Headers**

| Header | Value |
|--------|-------|
| Content-Type | application/json |
| Authorization | Bearer &lt;access_token&gt; |
| Accept-Language | `es` (default) or `en` |

### Request body

**The twelve fields of the create route, no more and no fewer**, with the same
ten rules, in the same order, answering the same `error_code`s — see
[the create body](#request-body) for each one. What changes here is not the
validation, it is what an absence means.

> ⚠️ **THIS IS A TOTAL REPLACEMENT. AN OPTIONAL FIELD YOU LEAVE OUT IS DELETED.**
> A `PUT` without `warranty_days` leaves the offer **with no warranty**, even if
> it had 90 days yesterday. The same goes for `includes`, `excludes`,
> `valid_until`, `available_from`, `duration`/`duration_unit` and
> `tax_included`.
>
> **The app must send the whole form**, pre-filled with what the offer holds
> today — which is byte for byte what this endpoint answers and what
> [the detail](#get-apiv1service-offersproviderid) serves.

**`provider_id` is not a field of this body.** The offer already knows whose it
is, and a `provider_id` here can only contradict the URL. If it arrives — **with
any value, the correct one included** — the answer is `422 invalid_field` with
`@field = provider_id`. It is refused out loud rather than dropped in silence,
because a client that sends it believes it changed something.

```json
{
  "message": "Puedo pasar el jueves por la mañana.",
  "amount_type": "fixed",
  "amount": 150.5,
  "tax_included": true,
  "valid_until": "2026-09-01 23:59",
  "available_from": "2026-08-27 08:00",
  "duration": 3,
  "duration_unit": "hours",
  "includes": "Mano de obra, desplazamiento y sellado.",
  "excludes": "El calentador de repuesto, si hiciera falta.",
  "warranty_days": 90,
  "requires_visit": false
}
```

### What the `PUT` never touches

| Field | Still worth |
|-------|-------------|
| `node.uid` | The account that **created** it — not the one editing. History is not rewritten. |
| `node.created` | The instant it was quoted. `node.changed` **does** move. |
| `node.title` | The one it was born with: it names the provider and the request, and neither changes. |
| `field_request` | Editing does not move an offer to another request. |
| `field_provider` | Changing it would be another offer, not this one. |
| `field_offer_status` | Stays `sent`. |
| The three chat fields | Whatever the chat has mirrored on them. The `PUT` neither writes nor clears them — only `chat/token` and the chat notice do. |

**Nothing outside the offer moves either:** the request keeps its status, no
timeline entry is written, and `offers_count` is untouched.

### Who may edit: eight conditions, in this order

The first one that fails answers.

| # | Condition | If it fails |
|---|-----------|-------------|
| 1 | `{id}` is a positive integer | `404 not_found` — **before the token, with no query** |
| 2 | The token is valid | `401 missing_authorization` / `401 invalid_token` |
| 3 | The account holds the `proveedor` role | `403 provider_role_required` — **before the offer is read** |
| 4 | The offer exists and is servable | `404 not_found` |
| 5 | The offer belongs to one of the account's providers | `403 service_offer_provider_not_owned` |
| 6 | The offer is still `sent` | `409 service_offer_not_editable` |
| 7 | Its request still takes offers (`open`, `offered`, `direct`) | `409 service_request_not_offerable` |
| 8 | The provider may operate today | `403 service_offer_provider_not_active` |

**Ownership is `field_provider` and never `node.uid`.** Any account of the same
provider may correct an offer a colleague sent: a company with two employees
cannot be left with a frozen offer because the one who sent it is on holiday.

**Condition 8 comes after condition 7, and that is deliberate.** Conditions 5 to
7 ask *"may anything be written on this offer at all?"*, and 8 asks *"may you
send a new quote?"*. So a `409` over a closed request wins over the `403` of a
lapsed licence.

**The category is not checked.** It was checked the day the offer was born.
Asking again would leave a company unable to correct a price only because it
stopped serving that category afterwards.

**Every `422` of the body arrives after the whole gate.** A stranger's offer
with an invalid body answers `403`, never `422`: who you are does not depend on
what you wrote.

### Success response (200)

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": "https://…/logo.png" },
      "amount": 150.5,
      "message": "Puedo pasar el jueves por la mañana.",
      "status": "sent",
      "created": "2026-08-25T11:02:00",
      "amount_type": "fixed",
      "valid_until": "2026-09-01T23:59:00",
      "available_from": "2026-08-27T08:00:00",
      "duration": { "value": 3, "unit": "hours" },
      "includes": "Mano de obra, desplazamiento y sellado.",
      "excludes": "El calentador de repuesto, si hiciera falta.",
      "tax_included": true,
      "warranty_days": 90,
      "requires_visit": false
    },
    "request": { "id": 128, "status": "offered" }
  },
  "message": "Oferta actualizada correctamente."
}
```

**`200` and not `201`:** nothing is born. **`status` is still `sent`** — editing
does not change what state an offer is in.

**The fifteen keys are the same serialiser's**, so the object under
`service_offer` is byte for byte what
[the detail](#get-apiv1service-offersproviderid) answers for this nid a second
later. **`provider.logo` does travel here**, unlike in the `201` of the create
route: there it would have cost a query, here it comes free in the row the gate
needed anyway.

**`request` is a sibling and not a sixteenth key**, and it carries the request's
status **without moving it**.

### Possible errors

| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | `POST`, `PATCH` or `DELETE` on this route. Answered **before the token**. |
| 404 | `not_found` | `{id}` is not a positive integer. **No query runs.** |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Invented, revoked or expired token. |
| 403 | `provider_role_required` | The account has no `proveedor` role. **A resident who `PUT`s the URL of an offer they received lands here.** |
| 404 | `not_found` | The offer does not exist, is unpublished, is of another bundle, or **its request is not published**. The four are indistinguishable on purpose. |
| 403 | `service_offer_provider_not_owned` | The offer belongs to a provider that is not the account's. |
| 409 | `service_offer_not_editable` | The offer is `selected`, `rejected` or `withdrawn`. |
| 409 | `service_request_not_offerable` | The request is `assigned`, `closed` or `cancelled`. |
| 403 | `service_offer_provider_not_active` | The provider is unpublished or its licence has expired. |
| 422 | `invalid_field` | `provider_id` in the body (`@field = provider_id`), or any of the ten body rules. |
| 422 | `missing_field` | `message` or `amount_type` absent — including when the body is missing or unparseable. |
| 422 | `service_offer_amount_required`, `service_offer_amount_not_allowed`, `service_offer_tax_without_amount`, `service_offer_duration_incomplete` | The four conditional rules of the body. |

---

## PUT /api/v1/service-offers/{id}/withdraw

Takes one of the authenticated account's offers back while it is still `sent`.
**This is the only thing in the module that writes the `withdrawn` status**,
which has been in the catalogue since the bundle was installed.

**Authentication:** required — Bearer token, and the account must hold the
`proveedor` role.

**Headers**

| Header | Value |
|--------|-------|
| Authorization | Bearer &lt;access_token&gt; |
| Accept-Language | `es` (default) or `en` |

### Request body

**None.** Any body is ignored entirely — with keys, empty, or malformed JSON.
Nothing is parsed, so nothing can fail. There is **no `reason`**: it would be a
new column that no response serves.

> **Why a `PUT` and not a `DELETE`.** Nothing disappears. The offer stays
> published, travels whole in both details, shows up in the provider's archive
> and **still counts in `offers_count`** — *"how many offers did I receive"*
> includes the ones that were taken back. A `DELETE` would promise a
> disappearance that does not happen.

### Who may withdraw: seven conditions, in this order

The gate is the edit's **without condition 8**.

| # | Condition | If it fails |
|---|-----------|-------------|
| 1 | `{id}` is a positive integer | `404 not_found` — **before the token, with no query** |
| 2 | The token is valid | `401 missing_authorization` / `401 invalid_token` |
| 3 | The account holds the `proveedor` role | `403 provider_role_required` |
| 4 | The offer exists and is servable | `404 not_found` |
| 5 | The offer belongs to one of the account's providers | `403 service_offer_provider_not_owned` |
| 6 | The offer is still `sent` | `409 service_offer_not_withdrawable` |
| 7 | Its request still takes offers (`open`, `offered`, `direct`) | `409 service_request_not_offerable` |

> **A lapsed licence does NOT block withdrawing**, and that is the one asymmetry
> between the two verbs. Editing is sending a new quote, and whoever may not
> operate does not quote. Withdrawing commits to nothing, and forcing a provider
> to leave a wrong offer alive because their licence expired is exactly the
> damage these two routes exist to undo. **A suspended provider withdraws with
> `200`.**

**NOT IDEMPOTENT, ON PURPOSE.** A second `PUT` on an offer that is already
`withdrawn` answers `409 service_offer_not_withdrawable`, which is the truth. A
`200` would pretend it had done something. The app can show it without alarm.

**A `cancelled` request needs no rule of its own.** Cancelling already left the
offer `rejected`, so condition 6 answers before condition 7 has anything to say.

### What it writes, and what it does not

**One field, `field_offer_status = "withdrawn"`, and nothing else on the offer.**

**Nothing outside the offer moves:**

- **The request keeps its status.** A request in `offered` whose only live offer
  is withdrawn **stays in `offered`**, with zero live offers. That is a known
  inconsistency, not an oversight: the resident does not recover the right to
  edit their request by this route either, because that gate counts **any**
  published offer.
- **No timeline entry is written**, not even on a `direct`.
- **`offers_count` does not change.** An offer that was taken back was received.

**After withdrawing, the same provider may bid again on the same request**: the
uniqueness rule of the create route counts only `sent` and `selected`, so a
`withdrawn` offer blocks nothing. That is the way out of a wrong price on a
`direct` — although **correcting in place is the better one**, since re-bidding
leaves two nodes in the resident's listing.

### Success response (200)

Identical in shape to the edit's, with `status` answering `withdrawn`:

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": "https://…/logo.png" },
      "amount": 150.5,
      "message": "Puedo pasar el jueves por la mañana.",
      "status": "withdrawn",
      "created": "2026-08-25T11:02:00",
      "amount_type": "fixed",
      "valid_until": "2026-09-01T23:59:00",
      "available_from": "2026-08-27T08:00:00",
      "duration": { "value": 3, "unit": "hours" },
      "includes": "Mano de obra, desplazamiento y sellado.",
      "excludes": "El calentador de repuesto, si hiciera falta.",
      "tax_included": true,
      "warranty_days": 90,
      "requires_visit": false
    },
    "request": { "id": 128, "status": "offered" }
  },
  "message": "Oferta retirada correctamente."
}
```

**Notifies the resident (SPEC 111).** Right after the write above, the
resident who requested it — never the provider that just withdrew, and never
the `backend` role, which already knows about the request since SPEC 109 —
gets a push, an inbox row and an email with a button into the app, once per
withdrawal. It is best-effort: a failure to notify never changes this `200`.
Full contract in
[`docs/service-request-notifications.md`](service-request-notifications.md#offer-withdrawn-spec-111).

### Possible errors

| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method but `PUT`. Answered **before the token and with no query**. |
| 404 | `not_found` | `{id}` is not a positive integer. **No query runs.** |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Invented, revoked or expired token. |
| 403 | `provider_role_required` | The account has no `proveedor` role. |
| 404 | `not_found` | The offer does not exist, is unpublished, is of another bundle, or **its request is not published**. |
| 403 | `service_offer_provider_not_owned` | The offer belongs to a provider that is not the account's. |
| 409 | `service_offer_not_withdrawable` | The offer is `selected`, `rejected` or already `withdrawn`. |
| 409 | `service_request_not_offerable` | The request is `assigned`, `closed` or `cancelled`. |

---

## PUT /api/v1/service-offers/{id}/accept

The resident awards one of the offers on their own request. **This is the only
thing in the module that writes the `selected` status, the `assigned` status,
`field_assigned_offer` and `field_assigned_provider`** — four things that have
been in the catalogues and on the bundle since it was installed, waiting for
this route.

**Authentication:** required — Bearer token. **No role is needed:** this is the
resident's endpoint, and a resident holds no `proveedor` role. Who may award is
a fact about the data, not about roles.

**Headers**

| Header | Value |
|--------|-------|
| Authorization | Bearer &lt;access_token&gt; |
| Accept-Language | `es` (default) or `en` |

### Request body

**None.** Any body is ignored entirely — with keys, empty, or malformed JSON.
Nothing is parsed, so nothing can fail, exactly like the withdrawal above.

> **Why a `PUT` on the OFFER and not a `POST` on the request.** Nothing is born,
> so there is no `201`. And the object whose state changes by the resident's
> decision **is the offer**; the request turning `assigned` is the consequence,
> not the action. The URL identifies the offer on its own, which is also why the
> error *"the offer you sent is not of this request"* cannot exist.

### Who may award: nine conditions, in this order

The first one that fails answers.

| # | Condition | If it fails |
|---|-----------|-------------|
| 1 | `{id}` is a positive integer | `404 not_found` — **before the token, with no query** |
| 2 | The token is valid | `401 missing_authorization` / `401 invalid_token` |
| 3 | The offer exists and is servable | `404 not_found` |
| 4 | Its request exists and is servable | `404 not_found` |
| 5 | The reader **is** the request's `field_requester` | `403 service_request_forbidden` |
| 6 | The offer is still `sent` | `409 service_offer_not_acceptable` |
| 7 | Its request can go to `assigned` (from `offered`, or from `direct` since SPEC 107) | `409 service_request_not_assignable` |
| 8 | `valid_until` is absent, or has not passed | `409 service_offer_expired` |
| 9 | The offer's provider may still operate | `403 service_offer_provider_not_active` |

> **Condition 5 is `field_requester` exactly** — never `node.uid`. A request an
> operator registered on a resident's behalf belongs to the resident. And not
> the rest of the unit either, unlike a payment: a service request is signed by
> one person, and the household does not inherit the right to award it. **A
> provider never awards, not even their own offer.**

> **A `direct` request IS awardable, since SPEC 107.** It is born with a
> provider but **without a price** — `service_request` has no monetary field, so
> the amount can only live on an offer — and this endpoint is the verb that
> closes that gap: the resident accepts the quote of the company they already
> chose. It writes exactly the same four things as an award off the bidding
> round. **Receiving the quote assigns nothing by itself**; only the resident's
> call does.

> **A request sitting in `open` cannot be awarded.** The graph has no
> `open → assigned` edge, and an offer created **from the back office** does not
> go through the endpoint that syncs `open → offered`. So a request left in
> `open` **with offers hanging off it** answers
> `409 service_request_not_assignable`. Known, and the spec that closes it is
> another one.

> **`valid_until` absent means the quote does not expire.** It is optional and
> most offers do not carry one. The comparison is `>=`: an offer is good
> **throughout** its expiry instant and not one second less. **Nothing sweeps
> expired offers** — they keep saying `sent` in every listing and in both
> details, so the app must compare `valid_until` with the clock and disable the
> button itself. The `409` is the last line of defence, not the first.

> **The licence is checked last, and that is deliberate.** Conditions 6, 7 and 8
> ask *"is this offer awardable?"*; condition 9 asks *"may this provider still
> work?"*. The second only means anything once the first has passed — so a
> `rejected` offer from a suspended provider answers `409` and not `403`.

**NOT IDEMPOTENT, ON PURPOSE.** A second `PUT` answers
`409 service_offer_not_acceptable` — the offer says `selected` now — and
**reassigns nothing**. A `PUT` on **another** offer of the same, already awarded
request answers the same `409`, because that one is `rejected` by now. Awarding
twice, or changing the winner, is a different operation and another spec.

### What it writes: four nodes, in this order

| # | What | Detail |
|---|------|--------|
| 1 | **The request** | `field_request_status = "assigned"`, `field_assigned_offer = {id}`, `field_assigned_provider` = the offer's provider. One `node_save()`. |
| 2 | **The transaction** | One timeline entry: `assigned`, the resident as `node.uid`, and a comment naming the provider and the amount. |
| 3 | **The winning offer** | `field_offer_status = "selected"`. |
| 4 | **The losing offers** | Every offer of that request that was `sent` becomes `rejected`. |

**The order is a contract and not a style.** The request is written **first** so
that the status sync — which runs on the transaction's `hook_node_insert()` —
compares two equal statuses and does **not** save the request a second time.

**The winner is excluded from the sweep by its nid, not by its status.** By the
time step 4 runs it already says `selected`, which the sweep considers *live*;
without the exception it would reject itself.

**What is NOT touched:** offers already `withdrawn` or `rejected` (the first is
the provider's own retreat, the second is already terminal), the request's unit,
category, description, files, `node.uid`, `node.created` and `node.title`, and
the winning offer's twelve quote fields. The request **stays published**, and
`offers_count` does not change: awarding deletes no offer.

**The four writes are NOT atomic.** No database transaction is opened, for the
same reason cancelling does not open one: `node_save()` with the Field API and
its hooks inside one is a known source of lock-ups. A failure halfway through
leaves a state that is inconsistent but harmless — nothing can be awarded or
charged from it — and is repaired by hand from the back office.

**The timeline text**, with no currency symbol, because the module stores none:

| Case | Text |
|------|------|
| With an amount | `Oferta adjudicada a Plomería Torres por 150,50.` |
| `on_site_quote`, or no amount | `Oferta adjudicada a Plomería Torres.` |
| No provider name | `Oferta adjudicada.` |

### Success response (200)

**The resident's whole request detail** — the very object
`GET /api/v1/service-requests/{id}` answers, rebuilt from the database *after*
the four writes — plus `offers_rejected` as a **sibling key** and not a twentieth
key of it, so the app can swap the object in with no special case.

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 128,
      "title": "Fuga en el calentador",
      "description": "El calentador gotea desde el martes.",
      "status": "assigned",
      "category": { "id": 9, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 57, "name": "Casa 12" },
      "offers_count": 3,
      "assigned_offer": { "id": 901, "status": "selected", "amount": 150.5, "…": "the fifteen keys of an offer" },
      "assigned_provider": {
        "id": 41,
        "logo": null,
        "title": "Plomería Torres",
        "categories": [ { "id": 9, "code": "plumbing", "name": "Plomería" } ],
        "rating_avg": 4.8,
        "rating_count": 31,
        "short_description": "Fontanería y gas, 24 h.",
        "hourly_rate": 25.5
      },
      "created": "2026-08-24T09:14:00",
      "desired_start": "2026-08-28T00:00:00",
      "viewer": "requester",
      "requester": { "id": 314, "name": "Ana Pérez" },
      "condominium": { "id": 3, "name": "Altos del Bosque" },
      "images": [],
      "attachment": null,
      "closed_at": null,
      "offers": [
        { "id": 901, "status": "selected", "…": "the fifteen keys" },
        { "id": 902, "status": "rejected", "…": "the fifteen keys" },
        { "id": 903, "status": "rejected", "…": "the fifteen keys" }
      ],
      "transactions": [
        { "id": 700, "status": "open", "…": "the five keys" },
        { "id": 742, "status": "assigned", "comment": "Oferta adjudicada a Plomería Torres por 150,50.", "…": "" }
      ]
    },
    "offers_rejected": 2
  },
  "message": "Oferta adjudicada correctamente."
}
```

- **`200` and not `201`:** no resource is born that the client would address.
  The transaction is an effect, not the answer.
- **Eight queries, paid on purpose.** The app repaints the screen it is already
  on — the new status, the awarded offer, every offer with its freshly written
  status and the timeline with the entry already in it — with no second round
  trip. And the response **cannot** disagree with what a `GET` would say,
  because it is what a `GET` answers. Six of the eight were always there; the
  other two are the awarded provider's **card**, and this route always pays for
  them because awarding is precisely what fills `field_assigned_provider`.
- **`assigned_provider` and `assigned_offer` travel WHOLE** — the eight keys of
  a provider card and the fifteen of an offer, exactly as
  `GET /api/v1/service-requests/{id}` answers them. `assigned_provider.name` does
  not exist: the card calls it `title`. See
  [The award, widened](service-request.md#the-award-widened-assigned_provider-and-assigned_offer).
  The awarded offer costs no query of its own — it is the very item of `offers`
  above, the one this call has just moved to `selected`.
- **`viewer` is always `requester`:** condition 5 already proved who got here.
- **`offers_rejected` is the count of offers *this call* moved to `rejected`** —
  not counting the winner, and not counting the ones that were already rejected.
  It is the one thing the client cannot deduce from what it just received:
  `offers` shows which offers are rejected **now**, not which ones this call
  rejected.

> **A degraded body, in one case only.** If the request's category term was
> deleted between the gate and the response, the detail cannot be built. The
> award **is** written and it is correct, so the answer is still `200`, and the
> body falls back to `{"service_request": {"id": …, "status": "assigned"},
> "offers_rejected": N}`. The client tells the two shapes apart by `viewer`,
> which only the full object carries. This is nearly unreachable — the same join
> already ran in condition 4.

**Notifies the winning provider, every losing provider and `backend` (SPEC
112).** Right after the losers are swept and before the `200` above, the
winner is told they were selected (with the amount of their own offer), each
provider whose offer just moved to `rejected` is told another was selected
(without revealing who or for how much), and the `backend` role gets the full
award detail by email. It is best-effort: a failure to notify never changes
this `200`. Full contract in
[`docs/service-request-notifications.md`](service-request-notifications.md#offer-awarded-spec-112).

### Possible errors

| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method but `PUT`. Answered **before the token and with no query**. |
| 404 | `not_found` | `{id}` is not a positive integer. **No query runs.** |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Invented, revoked or expired token. |
| 404 | `not_found` | The offer does not exist, is unpublished, is of another bundle, or its request is not published. |
| 404 | `not_found` | The request cannot be resolved — its category term was deleted. **Nothing is written.** |
| 403 | `service_request_forbidden` | The reader is not the request's `field_requester`. The provider who owns the offer lands here too. |
| 409 | `service_offer_not_acceptable` | The offer is `selected`, `rejected` or `withdrawn`. |
| 409 | `service_request_not_assignable` | The request is `open`, `assigned`, `closed`, `cancelled`, or its status is empty or unknown. |
| 409 | `service_offer_expired` | The offer's `valid_until` has passed. |
| 403 | `service_offer_provider_not_active` | The offer's provider is unpublished or its licence has expired. |

---

## Configuration (SPEC 110)

The offer-received email's button (see the note on
[`POST /api/v1/service-requests/{id}/offers`](#post-apiv1service-requestsidoffers)
above) opens the request in the app through a custom-scheme deep link, the
same pattern [`myapi_password_reset_deep_link_base`](auth.md) uses for the
password-reset email:

| Variable | Default | Note |
|----------|---------|------|
| `myapi_service_request_deep_link_base` | `myapp://service-requests` | Base of the button's URL — the app opens `{base}/{request_nid}`. Set with `drush vset myapi_service_request_deep_link_base <value>`. **Independent** of `myapi_password_reset_deep_link_base`: changing one never changes the other. |

## What is still not here

Written down so it is not looked for in this document:

- **A history of an offer's changes.** A `PUT` overwrites and does not version:
  what the resident saw yesterday and what they see today cannot be compared,
  and the fifteen keys **do not include `changed`**. Making a change visible and
  notifying somebody of it are the same problem, so the sixteenth key belongs
  with the notifications spec.
- **A `PATCH`.** The edit is a **total replacement** and nothing else. A partial
  body would have to tell *"absent"* from *"delete it"*, and evaluate the three
  conditional rules against a mixture of what is stored and what was sent.
- **A reason for a withdrawal**, and therefore no `field_offer_withdraw_reason`.
- **The request going back to `open`** when its last live offer is withdrawn.
  The transition graph gains no `offered → open` edge.
- **A resident rejecting ONE offer, or withdrawing one.** `rejected` is written
  only by the sweep, which runs on two events and neither is a resident picking
  a single offer to refuse: cancelling a request, and awarding one of its offers
  — the latter rejects every other live one in the same pass. Withdrawing is the
  provider's, by definition.
- **Un-awarding, or changing the winner.** A second `accept` answers `409` and
  reassigns nothing. Undoing an award has to decide what happens to the offers
  it rejected and to the timeline entry it already wrote, and that is a spec of
  its own. There is also no record of *which* offers a given award rejected —
  `offers_rejected` is a counter and it leaves with the response.
- **A resident rejecting ONE quote on a `direct`** without cancelling the whole
  request. Accepting is here since SPEC 107; refusing a single quote and asking
  for another is not, and their only exit is still cancelling.
- **Anything expiring an offer.** `valid_until` is *read* by the award, but no
  sweep changes the status of a lapsed offer — it keeps saying `sent` in all
  three places it travels.
- **Files on an offer.** Photos of previous jobs and a PDF quote. Hanging a file
  off an offer breaks the ownership chain the private-file route resolves by
  `n.type = service_request`, and it forces a decision about whether the
  competition sees your quote. Its own spec, and it must add an **upload route**
  rather than change this body's format.
- **A `GET` of the offers of a *request*.** They already travel inside the two
  details. Any method but `POST` on the bidding route is `405`. (The provider's
  own archive, which crosses requests, **is** here — see
  [`GET /api/v1/service-offers/provider`](#get-apiv1service-offersprovider) —
  and so is the detail of **one** offer, on each side.)
- **Several providers in one call** (`?provider_id=41,42`, or the aggregate of a
  whole account). If it is ever needed it is a spec and a format of its own, not
  a patch on the parameter.
- **A listing of the offers a *resident* has received** — *"everything I have
  been quoted"*, across their requests, the sibling of the provider's archive.
  The two detail routes serve **one** offer, not a set.
- **The building administrator on either detail route.** The request's own
  detail does not let them in either, and giving them the offer would be the
  first exception — one that starts at the leaf and not at the root. If it is
  needed, it is a spec.
- **Counters and aggregates** over the archive — *"you have 3 quotes pending"*,
  the total amount quoted this month.
- **The chat.** The three fields stay empty, as they have since SPEC 77.
- **Notifying the resident.** There is no notification infrastructure for
  services at all yet.
- **Expiring an offer by `valid_until`.** The field is stored and served and
  **no process looks at it**. A lapsed offer stays `sent` until somebody awards
  or the request is cancelled.
- **Rate limiting.** The uniqueness rule already caps it at one live offer per
  provider and request, which is the bulk of the imaginable abuse.
- **Backfilling `amount_type` on the offers already stored.** They answer `null`
  for ever, which says exactly what happened: they predate the field.
