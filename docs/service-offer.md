# Service offers

The provider's side of the marketplace: a provider bids on an open request of
their category, with the fields that turn *a text and a number* into a quote the
resident can compare.

One route, and it hangs off the request because **an offer does not exist
outside its request**:

- [`POST /api/v1/service-requests/{id}/offers`](#post-apiv1service-requestsidoffers) — create an offer.

Offers are **read** elsewhere: whole, inside
[the resident's detail](service-request.md) (`offers`) and inside
[the provider's detail](service-request-provider.md) (`my_offers`). There is no
`GET` of the collection here, and that is deliberate — a third place to read
them would be a third thing to keep in agreement with `offers_count`.

---

## POST /api/v1/service-requests/{id}/offers

Creates the offer of one of the authenticated account's providers on an open
request of that provider's category, and — **when the offer is the first one** —
moves the request from `open` to `offered` and writes one entry on its timeline.

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
| `message` | string, 1–2000 chars | **Yes** | What you are offering to do. Stored as typed, line breaks and all. |
| `amount_type` | `fixed` \| `estimate` \| `hourly` \| `on_site_quote` | **Yes** | How the amount is to be read. Mandatory because the number is unreadable without it: the same `150` means a closed price, a guess, an hourly rate or nothing at all. |
| `amount` | decimal ≥ 0, ≤ 99999999.99 | **conditional** | **Required** for `fixed`, `estimate` and `hourly`; **forbidden** for `on_site_quote`. `0` is a price somebody offered. May be sent as a number or as a string, so the decimals survive the wire. |
| `tax_included` | bool | No | Only alongside an `amount`. Omitting it is *I did not say*, which is a different answer from `false`. |
| `valid_until` | string `Y-m-d H:i` | No | Until when the offer stands. Must be **strictly in the future**. |
| `available_from` | string `Y-m-d H:i` | No | When you could start. Strictly in the future, and not later than `valid_until`. |
| `duration` | int 1–9999 | No | Estimated duration. **Coupled with `duration_unit`: send both or neither.** |
| `duration_unit` | `hours` \| `days` | No | Same. |
| `includes` | string, ≤ 2000 chars | No | What the quote covers. Empty after trimming is stored as absent. |
| `excludes` | string, ≤ 2000 chars | No | What it does not. |
| `warranty_days` | int 0–3650 | No | `0` is a declaration — *no warranty* — and not an absence. |
| `requires_visit` | bool | No | Defaults to `false`. |

**Booleans are read as JSON booleans and nothing else.** `"true"`, `"1"` and `1`
all answer `422 invalid_field`. The body is JSON, the client can send a real
boolean, and accepting the string `"false"` would open the door to reading it as
true.

**Lengths count characters, not bytes.** 2000 accented characters fit; the cut
is the one the provider can count.

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
| 5 | It is in `open` or `offered`, and nothing is awarded on it | `409 service_request_not_offerable` |
| 6 | Its category is one your provider serves | `403 service_offer_category_mismatch` |
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
resolved value would quietly reopen a request that is already somebody's job. A
`direct` request falls out here with no rule of its own — it is neither of the
two biddable statuses **and** it is born with a provider assigned. A `direct`
awarded **to you** falls the same way: a request that is already yours is not
bid on, it is worked on.

**An empty or corrupt `field_request_status` answers `409`, never `500`.**

**Condition 6 needs no special case for a provider with no categories:** an
empty set matches nothing, and *you do not serve this category* is the true
statement then too.

**Condition 7 — "live" means `sent` or `selected`.** An offer in `rejected` or
`withdrawn` does not compete, so it blocks nothing and you may bid again. **Two
different providers of the same account may both bid on the same request**: they
are two companies in this data model, with separate licences, categories and
ratings, and the resident contracts the provider, not the account.

> ⚠️ **You cannot correct an offer you already sent.** There is no `PUT` and no
> `DELETE` here, and condition 7 blocks a second one while the first is `sent`.
> A zero too many in the amount stays written until the resident awards somebody
> else or cancels. Confirm the send in the app with a summary of what is going
> out. Editing and withdrawing are the next spec.

### What the server decides, and the client never sends

| Field | Value |
|-------|-------|
| `node.type` | `service_offer` |
| `node.uid` | The uid of the Bearer token — the **account** that bid |
| `node.status` | `1` |
| `node.title` | *"Oferta de &lt;proveedor&gt; — solicitud #&lt;nid&gt;"*, truncated to 255 |
| `field_request` | The `{id}` of the route |
| `field_offer_status` | Always `sent` |
| `field_firebase_path`, `field_chat_opened_at`, `field_last_message_at` | Always empty — the chat is another spec |

`node.uid` and `field_provider` are **two different things and both are
written**: a provider may be operated by several accounts, and the offer has to
know which of them sent it as well as which company it commits.

**An optional value you did not declare is not written at all**, rather than
written as an empty row. That is what makes a new offer that declared nothing
indistinguishable from one stored before SPEC 100 — which is exactly right.

### The three writes

| # | Write | When |
|---|-------|------|
| 1 | **The offer** | Always. It is what you asked to create. |
| 2 | **The request**, to `offered` | **Only if it moves.** `open` → `offered`, and the transition is *asked* of the status graph, never transcribed. A request already in `offered` is not saved at all. |
| 3 | **A `service_transaction`** | **Only if the status actually moved.** |

**The transaction is written only when the status changes, and that is a
decision.** `service_transaction` has been *one entry per status change* since
SPEC 77, and the third offer on an already-`offered` request changes none.
Recording it would write a timeline row whose status repeats the one before it.
The resident learns about the second and third offers from `offers_count` and
from `offers`, not from the history.

**Neither `field_assigned_offer` nor `field_assigned_provider` is ever touched:
bidding is not awarding.**

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
moved — and `status` is the one **after** the write.

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
| 409 | `service_request_not_offerable` | The request is not in `open`/`offered`, or something is already awarded on it. |
| 403 | `service_offer_category_mismatch` | Your provider does not serve the request's category. |
| 409 | `service_offer_already_sent` | That provider already has a `sent` or `selected` offer on this request. |
| 422 | `service_offer_amount_required` | `amount_type` is `fixed`, `estimate` or `hourly` and no `amount` came. |
| 422 | `service_offer_amount_not_allowed` | `amount_type` is `on_site_quote` and an `amount` came. |
| 422 | `service_offer_tax_without_amount` | `tax_included` without an `amount`. |
| 422 | `service_offer_dates_inconsistent` | `available_from` is later than `valid_until`. |
| 422 | `service_offer_duration_incomplete` | One of `duration` / `duration_unit` without the other. |

**A `403` or a `409` from the gate always beats a `422` from the body.**

**The order of the body rules is the contract too**, and the first broken one
answers: `message`, `amount_type`, `amount`, `tax_included`, `valid_until`,
`available_from`, their coherence, `duration`/`duration_unit`,
`includes`/`excludes`, `warranty_days`, `requires_visit`.

---

## What is still not here

Written down so it is not looked for in this document:

- **Editing or withdrawing an offer.** No `PUT`, no `DELETE`, and no way to
  reach the `withdrawn` status, which exists in the catalogue and nothing writes.
  This is the real limitation of this endpoint — see the warning above.
- **Awarding one.** `selected`, `field_assigned_offer` and the transition
  `offered → assigned` are the resident's side and another spec. Nothing in this
  module writes `selected` yet.
- **Files on an offer.** Photos of previous jobs and a PDF quote. Hanging a file
  off an offer breaks the ownership chain the private-file route resolves by
  `n.type = service_request`, and it forces a decision about whether the
  competition sees your quote. Its own spec, and it must add an **upload route**
  rather than change this body's format.
- **A `GET` of the offers of a request.** They already travel inside the two
  details. Any method but `POST` here is `405`.
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
