# Bot endpoints (SPECS 127, 128, 130)

Machine endpoints consumed by the WhatsApp bot that runs in n8n. They are not
part of the Flutter app's surface and they do **not** use the Bearer access
token: the bot is not a user, it has no session, and it authenticates with a
static API key. Everything else — the response envelope, the `error_code`
catalogue, the `api/v1` prefix — is identical to the rest of the API.

---

## GET /api/v1/bot/person

Resolves a phone number to the person registered in Drupal and to every unit
that person is visible in, whether as owner or as occupant, so the bot knows
which unit a payment receipt should be charged to.

Read-only. It does **not** register the payment — that is the existing payment
endpoint's job. This one answers *who* and *where*.

**Authentication:** required (`X-Api-Key` machine key — **not** a Bearer token)

**Headers**
| Header | Value |
|--------|-------|
| X-Api-Key | `<the key set with drush vset myapi_bot_api_key>` |

**Query parameters**
| Param | Required | Notes |
|-------|----------|-------|
| `phone` | yes | The number as the person wrote it. Separators, parentheses, a leading `0` and a `+593` prefix are all irrelevant: only the last 9 digits are compared, on both the value received and the value stored. `0987535645`, `+593 98 753 5645`, `(098) 753-5645` and `098-753-5645` are the same number. |

**Request body**

None — `GET` only.

**Success response (200), person found**
```json
{
  "success": true,
  "data": {
    "found": true,
    "person": { "uid": 123, "name": "Juan Pérez" },
    "units": [
      {
        "unit_id": 456,
        "unit": "Dpto 3B",
        "condominium_id": 12,
        "condominium": "Torre Azul",
        "condominium_payment_info": "Banco Pichincha\nCta. Corriente 2100XXXXXX\nRUC 179XXXXXX001",
        "relation": "owner"
      },
      {
        "unit_id": 789,
        "unit": "Local 2",
        "condominium_id": 12,
        "condominium": "Torre Azul",
        "condominium_payment_info": "Banco Pichincha\nCta. Corriente 2100XXXXXX\nRUC 179XXXXXX001",
        "relation": "occupant"
      }
    ]
  }
}
```

`relation` is `"owner"` or `"occupant"`, and it belongs to each unit rather than
to the person: a tenant pays the maintenance fee as often as an owner does, and
the same person can own one unit and rent another. When somebody is both owner
and occupant of the *same* unit, `"owner"` wins.

`condominium_payment_info` is the condominium's `field_informacion_pago_value`
exposed exactly as stored (raw, unfiltered text — the field's text format is
ignored), `null` when the building has no row in
`field_data_field_informacion_pago`. It is the text the bot reads back over
WhatsApp so the resident knows where to transfer. It is a property of the
**condominium**, not of the unit: two units of the same building repeat the
same value, which is what keeps every element of `units` self-contained and n8n
free of a second lookup. It is the same field the app receives as
`payment_information` in [`GET /api/v1/units`](unit.md).

Only published units whose condominium is also published are listed. The
balance (`current_balance`) is **not** returned, and neither is the person's
phone, national id or email.

**Success response (200), person not found**
```json
{
  "success": true,
  "data": { "found": false, "person": null, "units": [], "reason": "not_found" }
}
```

The shape never changes, so n8n has a single branch. `reason` is a stable
English key, like `error_code`:

| `reason` | When |
|----------|------|
| `not_found` | No **active** user has that number. A blocked account (`users.status = 0`) lands here too and is not told apart — a reason of its own would confirm to whoever is asking that the person exists. |
| `ambiguous` | More than one active user matches on the last 9 digits. Answering nothing is safer than charging the payment to the wrong person. |
| `no_units` | Exactly one person matches, but has no visible unit — none owned or occupied, or all of them unpublished, or their condominium unpublished. |

`person` is `null` in all three, `no_units` included: if the bot cannot charge
the payment anywhere, the uid and the name are personal data travelling for
nothing.

There is **no `404`**. A 404 reads like a mistyped URL, and n8n would not tell
"this number is not registered" apart from "the route moved".

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `unauthorized` | `X-Api-Key` absent, empty, or different from the configured key. Also when `myapi_bot_api_key` is not configured at all — then **every** request is 401. |
| 405  | `method_not_allowed` | Any HTTP method other than `GET`. |
| 422  | `missing_phone` | `phone` is absent or empty. |
| 422  | `invalid_phone` | `phone` is present but holds fewer than 9 digits once normalised. |

A malformed `phone` is a `422` and not a `found: false` on purpose: an empty
parameter is a bug in the n8n flow, and answering "unknown number" would hide
it behind a WhatsApp conversation with somebody who was registered all along.

Error envelope:
```json
{
  "success": false,
  "error_code": "unauthorized",
  "error": "No autorizado."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Tables read**
| Table | Columns | Use |
|-------|---------|-----|
| `field_data_field_telefono` | `entity_id`, `field_telefono_value`, `entity_type`, `deleted` | The phone number. Multi-value: any of a person's rows matching is a match. |
| `users` | `uid`, `status`, `name` | Drops blocked accounts; display-name fallback. |
| `field_data_field_nombre`, `field_data_field_apellidos` | — | The display name, via `myapi_user_display_names()`. |
| `field_data_field_propietario` | `entity_id`, `field_propietario_target_id` | Units owned, via `myapi_user_owned_unit_nids()`. |
| `field_data_field_ocupante` / `field_data_field_ocupantes` | `entity_id`, `field_ocupante(s)_target_id` | Units occupied, via `myapi_user_occupied_unit_nids()`. |
| `node` + `field_data_field_nombre_vivienda` + `field_data_field_condominio` | — | Unit name and its condominium, via `myapi_unit_fetch_units()`. |
| `node` (condominio) + `field_data_field_informacion_pago` | `nid`, `title`, `status`, `field_informacion_pago_value` | Condominium title and payment information, via `myapi_unit_fetch_condominiums()`. |

No table is written, and no table is read at all until the API key has been
accepted.

---

## GET /api/v1/bot/units

Resolves the name of a condominium and the name of a unit — both written as a
person types them over WhatsApp: without accents, in lower case, with different
separators or with one letter wrong — to at most five visible units and their
owner, so the bot knows which unit a payment receipt should be charged to when
the phone number identified nobody.

The second road to the same `unit_id`. When `GET /api/v1/bot/person` answers
`found: false`, the bot asks over WhatsApp for the building and the unit, and
this is what it calls with those two answers in hand.

Read-only, like its sibling. It does **not** register the payment.

**Authentication:** required (`X-Api-Key` machine key — the **same** key as
`/bot/person`; SPEC 128 introduced no second credential)

**Headers**
| Header | Value |
|--------|-------|
| X-Api-Key | `<the key set with drush vset myapi_bot_api_key>` |

**Query parameters**
| Param | Required | Notes |
|-------|----------|-------|
| `condominium` | **yes** | The building name as the person wrote it. At least 2 characters once normalised. |
| `unit` | **yes** | The unit name as the person wrote it. At least 2 characters once normalised. |

Both are required, and that is not an oversight. `?unit=3b` on its own walks a
table of 35.000 units and `3B` exists in nearly every one of the 150 buildings:
five results out of a hundred-odd real matches is noise, not an answer.
`?condominium=torre azul` on its own is the whole building listed and cut to
five, which no branch of the conversation needs. The conversational flow always
asks for both, so the endpoint demands both.

**How the names are compared**

Both sides of every comparison — what arrives in the query string and what is
stored in Drupal — go through one definition: accents and case are folded away,
and the separators ` `, `-`, `.`, `,`, `#` and `/` are removed.

| Written / stored | Compared as |
|------------------|-------------|
| `torre azul`, `TORRE AZUL`, `Torre-Azul` | `torreazul` |
| `Edificio Torre Azul` | `edificiotorreazul` |
| `3B`, `3-B`, `3 B`, `# 3B` | `3b` |
| `Dpto 3-B` | `dpto3b` |
| `Climatización` | `climatizacion` |

The term is also split into words, and they match **in any order**: `azul torre`
finds `Edificio Torre Azul`. All of them have to appear — `torre verde` does
not find it.

If a term matches **nothing** literally, a second, approximate pass runs and
forgives a wrong letter: `torre asul` and `torrre azul` both find
`Edificio Torre Azul`. That pass only runs when the literal one found zero, so
a search that already works never starts answering with similar-looking
neighbours: if `torre` matches three buildings by name, no fourth one appears
by similarity.

**The approximate pass never touches a unit term shorter than 4 characters.**
`?unit=3b` does not return `Dpto 3C`: those two are one letter apart, and
correcting one into the other hands back the neighbour's unit with a payment
receipt behind it. `?unit=dpto 3b` (6 characters) can return `Dpto 3-C`, and it
is flagged — see `match` below.

**Request body**

None — `GET` only.

**Success response (200), with results**
```json
{
  "success": true,
  "data": {
    "found": true,
    "total": 2,
    "units": [
      {
        "unit_id": 456,
        "unit": "Dpto 3B",
        "condominium_id": 12,
        "condominium": "Edificio Torre Azul",
        "condominium_payment_info": "Banco Pichincha\nCta. Corriente 2100XXXXXX\nRUC 179XXXXXX001",
        "owner": { "uid": 123, "name": "Juan Pérez" },
        "match": "exact"
      },
      {
        "unit_id": 789,
        "unit": "Dpto 3B",
        "condominium_id": 31,
        "condominium": "Torre Azul II",
        "condominium_payment_info": null,
        "owner": null,
        "match": "fuzzy"
      }
    ]
  }
}
```

> **Two warnings the bot has to act on, not just read.**
>
> **`match: "fuzzy"` means at least one of the two names needed a letter
> corrected. Confirm over WhatsApp before charging anything to that unit.**
> Drupal marks the guess; deciding for the resident is not its job. A flow that
> treats the five results alike turns the approximate pass into a silent
> generator of misposted payments.
>
> **When `total > 1`, confirm too, whatever `match` says.** `Torre Azul`,
> `Torre Azul II` and `Torres Azules` can coexist in a base of 150 buildings,
> and `torre azul` matches all three literally. The endpoint hands over the data
> to decide with; it does not decide.

| Field | What it is |
|-------|------------|
| `found` | `true` when `units` is not empty. It exists for symmetry with `/bot/person`, not because it adds anything. |
| `total` | The real number of matches **before** the cut to five. `total > 5` means "narrow the term down", and the bot can say so instead of showing five as if they were all of them. See the caveat below. |
| `condominium_payment_info` | The condominium's `field_informacion_pago_value`, raw as stored, `null` when the building has no row in `field_data_field_informacion_pago`. The same value and the same meaning as in `/bot/person`: where to transfer, read back over WhatsApp. A property of the building, so two units of the same one repeat it. |
| `owner` | `{ uid, name }`, or `null` when the unit has no owner assigned or the uid no longer resolves. The unit still travels either way. Never the phone, the national id or the email. |
| `match` | `"exact"` when unit **and** condominium were resolved without approximating — that includes a prefix or a partial match, since both are literal. `"fuzzy"` when either of the two needed the second pass. |

**`total` counts inside the 20 condominiums examined, not inside the 150.** A
term like `ed` matches the hundred buildings whose name begins with "Edificio",
and scanning their 23.000 units to produce a number is not what this endpoint is
for: the search keeps the best 20 buildings and counts within them. With a very
short term the bot may say "there are 12" when there really are 60. Do not build
a count on this number — it is a search made to narrow things down, not a
report.

Only published units whose condominium is also published are returned. The
balance (`current_balance`) is **not** included, exactly as in `/bot/person`.

**Success response (200), no results**
```json
{
  "success": true,
  "data": { "found": false, "total": 0, "units": [] }
}
```

Same shape always, so n8n has a single branch. There is **no `404`**: a 404
reads like a mistyped URL, and n8n could not tell "that building does not exist"
from "the route moved".

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `unauthorized` | `X-Api-Key` absent, empty, or different from the configured key. Also when `myapi_bot_api_key` is not configured at all. |
| 405  | `method_not_allowed` | Any HTTP method other than `GET`. |
| 422  | `missing_condominium` | `condominium` is absent or empty. |
| 422  | `missing_unit` | `unit` is absent or empty. |
| 422  | `invalid_condominium` | `condominium` is shorter than 2 characters once normalised (e.g. `a`, or `-`). |
| 422  | `invalid_unit` | `unit` is shorter than 2 characters once normalised (e.g. `3`). |

`condominium` is validated first, so a request missing both parameters answers
one `422`, not two. A malformed parameter is a `422` and not an empty result for
the same reason as in `/bot/person`: it is a bug in the n8n flow, and answering
"I found nothing" would hide it.

**Examples**

```bash
# 200 — the building written without accents, the unit without its separator
curl -i -H 'X-Api-Key: <the secret>' \
  'https://<host>/api/v1/bot/units?condominium=torre%20azul&unit=3B'

# 200 — the words in any order, and one letter wrong
curl -i -H 'X-Api-Key: <the secret>' \
  'https://<host>/api/v1/bot/units?condominium=asul%20torre&unit=dpto%203b'

# 422 missing_unit
curl -i -H 'X-Api-Key: <the secret>' \
  'https://<host>/api/v1/bot/units?condominium=torre%20azul'
```

**Tables read**
| Table | Columns | Use |
|-------|---------|-----|
| `node` (condominio) | `nid`, `title`, `type`, `status` | The 150 published titles, loaded whole and compared in PHP. First phase of the cascade. |
| `node` (vivienda) + `field_data_field_condominio` | `nid`, `status`, `field_condominio_target_id` | The nids of the published units of the resolved condominiums. Narrows the second phase to a few hundred rows instead of 35.000. |
| `field_data_field_nombre_vivienda` | `entity_id`, `field_nombre_vivienda_value` | The unit name, via `myapi_unit_fetch_units()`. |
| `field_data_field_propietario` | `entity_id`, `field_propietario_target_id` | The owner's uid. |
| `users`, `field_data_field_nombre`, `field_data_field_apellidos` | — | The owner's display name, via `myapi_user_display_names()`, for the five that travel only. |
| `node` (condominio) + `field_data_field_informacion_pago` | `nid`, `field_informacion_pago_value` | The payment information, via `myapi_unit_fetch_condominiums()`, for the buildings behind the five that travel only. The first phase loads the titles alone. |

No table is written, no table is read until the API key has been accepted, and
the unit table is not touched at all when the condominium term matches nothing.

---

## POST /api/v1/bot/payments

Registers a payment from the reading the bot made of a WhatsApp receipt, plus
the receipt image itself. Creates the **same** `pagos` node the Flutter app
creates — same fields, same forced state, same email to the `backend` role —
distinguishable only by its channel and by the raw reading kept beside it.

The third road, and the one that writes. The two endpoints above end at a
`uid` and a `unit_id`; this is what the bot calls holding both, and it turns a
WhatsApp conversation into a payment pending verification.

**It is idempotent per message attachment.** A retried n8n call answers `200`
with the payment it already created, never a second payment. See
[Idempotency](#idempotency) below — and note the requirement it places on the
n8n flow.

**Authentication:** required (`X-Api-Key` machine key — the **same** key as
`/bot/person` and `/bot/units`; SPEC 130 introduced no second credential)

**Headers**
| Header | Value |
|--------|-------|
| X-Api-Key | `<the key set with drush vset myapi_bot_api_key>` |
| Content-Type | `multipart/form-data` |

**Request parts**
| Part | Type | Required | Content |
|------|------|----------|---------|
| `payload` | text field | **yes** | The reading, as a JSON string. Maximum **64 KB**. |
| `file` | file | **yes** | The receipt image. `pdf jpg jpeg png`, ≤ 5 MB, real MIME verified with `finfo`, stored in `private://comprobantes_pago/`. |

The JSON travels in a text field and not as the request body because the image
forces `multipart`. It is the same decision the app's `POST /api/v1/payments`
made, and it is what lets both endpoints share one upload implementation.

**The payload**

Keys are English, which is the module's public contract; n8n renames its own
JSON before calling. Errors report the **full dotted path** (`receipt.amount`),
so a failure reads directly in the n8n log.

| Key | Type | Required | Rule |
|-----|------|----------|------|
| `message.message_key` | string | **yes** | The `wamid`. Non-empty, ≤ 255. First half of the idempotency key. |
| `message.channel`, `.provider`, `.type` | string | no | Kept as evidence, not validated. |
| `message.sender.id`, `.local_phone`, `.name` | string | no | Kept as evidence. **The phone is not checked against the uid** — see below. |
| `message.button.id`, `.title` | string | no | Kept as evidence. |
| `message.received_at` | string | no | Kept as evidence. |
| `identity.person.uid` | int | no | If sent: > 0, and the user must exist and be **active** (`users.status = 1`). If **omitted**, the server resolves it from the unit — see [Who signs the payment](#who-signs-the-payment). Send no key at all; do not send `null`. |
| `identity.person.name` | string | no | Informative; the real name comes from Drupal. |
| `identity.unit.unit_id` | int | **yes** | > 0. Must be an existing, published `vivienda`, and the uid must own or occupy it. |
| `identity.unit.condominium_id` | int | **yes** | > 0, and must be **exactly** that unit's condominium. |
| `identity.unit.unit`, `.condominium`, `.relation` | string | no | Informative. The **names are compared with nothing**: they are written a dozen ways, the ids one. |
| `identity.source` | string | no | Kept as evidence. |
| `media.ref` | string ≤ 64 | no | Which attachment of the message this receipt is. Second half of the idempotency key; absent → `-`. |
| `media.mime_type`, `.file_name` | string | no | Informative. They do **not** validate the real file, which is the `file` part. |
| `receipt.reference` | string | **yes** | Non-empty, ≤ 255. Duplicated in that unit → `409`. |
| `receipt.amount` | number | **yes** | Numeric and **> 0**. A numeric string is accepted. |
| `receipt.date` | string | **yes** | `YYYY-MM-DD` or `YYYY-MM-DDTHH:MM:SS`. **No fall back to server time** — see below. |
| `receipt.issuing_bank` | string | no | ≤ 255, free text. Not resolved against the `bancos` vocabulary. |
| `receipt.destination_bank` | string | no | Same. |
| `receipt.confidence` | object | no | Stored and **never read** by the backend. |
| `receipt.corrected` | array | no | Same. |
| `receipt.model` | string | no | Same. |

**An unknown key is not an error.** It is ignored for the node and travels
whole into the evidence, so the bot can grow a field tomorrow without anything
here having to change first.

**Three rules worth reading twice**

- **`receipt.date` is required here and optional in the app.** In the app there
  is a person who knows what day they paid. Here, an absent date means the OCR
  did not read one, and dating the payment at the instant of the conversation
  instead of the day of the transfer would be a false value nobody would ever
  catch afterwards.
- **The sender's phone is not verified against the uid.** It is kept as
  evidence and compared with nothing, because `/bot/units` exists precisely to
  reach a unit when the phone identified nobody; demanding a match would close
  that road.
- **The confidences are not read.** No threshold, no different state, no
  automatic rejection. Deciding whether a `0.3` deserves asking again belongs
  to the bot, which is the one holding the conversation open.

### Who signs the payment

`identity.person.uid` is **optional**, and which user ends up owning the node
depends on whether you send it:

| You send `uid` | What the server does |
|----------------|----------------------|
| yes | Revalidates it: the user exists, is active, and owns or occupies the unit. Anything wrong → `422` / `403`. |
| no  | **Resolves it from the unit**: the **occupant**; if the unit has no occupant, the **owner**. |

Why the occupant first: whoever lives in the unit is who pays the maintenance
fee month after month. The history of a rented flat ends up split between
successive tenants, and that is the intended outcome — each payment is signed
by whoever made it, not by a landlord who did not.

**If the unit has several occupants**, the one with the **lowest uid** signs.
The criterion is arbitrary and declared as such; what matters is that it is
stable, because a retry has to produce the same payment as the first attempt.

**If the unit has neither occupant nor owner, or the resolved user is blocked,
the answer is `500 server_error`** and a `watchdog` entry — not a `422`. A
published unit with nobody attached is incomplete data on the site, not an
error in your payload. Note there is no fall through to the owner when the
occupant is blocked: a blocked occupant is still an occupant.

> **For the operator.** When the bot sends no `uid` — which happens when the
> sender's phone number matched nobody in Drupal and the person identified
> their unit by dictating the building and unit names — the payment is
> attributed to the unit's **occupant**, or to its **owner** when there is no
> occupant on file. So a payment can appear under the owner's name even though
> a tenant sent it. Who actually wrote over WhatsApp is kept in the evidence
> (`field_comprobante_ocr`, at `payload.message.sender.local_phone`), and the
> ledger row in `myapi_bot_payments` carries `uid_resolved = 1` for every
> payment that came in this way — `SELECT * FROM myapi_bot_payments WHERE
> uid_resolved = 1` lists them all.

**What the server decides, whatever the payload says**

| Field | Value |
|-------|-------|
| `field_estado_pago` | `"Pendiente de verificar"`, always. A human verifies it in the back office. |
| `field_forma_de_pago` | `"Transferencia"`, always. A bank receipt is never cash. |
| `field_canal` | `bot`. Decided by the door the request came in through, never by the caller. |
| `field_banco` | **`NULL` always.** The `bancos` term reference is the app's mechanism; the bot uses the two free-text fields. |

**Success response (201)**
```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 87,
      "title": "Pago 018273645 - 2026-09-12",
      "unit_id": 45821,
      "payment_date": "2026-09-12T00:00:00",
      "status": "Pendiente de verificar",
      "payment_method": "Transferencia",
      "reference": "018273645",
      "amount": 45.3,
      "bank_id": null,
      "bank_name": null,
      "file_id": 55,
      "file_name": "comprobante.jpg",
      "detail": null
    }
  },
  "message": "Pago registrado correctamente."
}
```

Exactly the body the app's `POST /api/v1/payments` answers — it is built by the
same mapper, so the two cannot drift.

`bank_id` and `bank_name` are **always `null`** here. The two banks the OCR
read are stored on the node but do not come out in any response, like
everything else SPEC 129 added.

### Idempotency

**The key is `message.message_key` + `media.ref`**, stored as a `sha256` of
`"<message_key>#<media_ref>"` in the primary key of `myapi_bot_payments`.

Why both halves: the `wamid` identifies the **message**, which is what n8n
retries, but one message can carry several attachments. With the `wamid` alone,
a person sending two receipts in a single WhatsApp message would have the
second answered as a retry of the first — `200`, the wrong payment back, and
one payment silently lost.

| Case | Answer |
|------|--------|
| First call | `201` with the new payment. |
| Same `message_key` **and** `media.ref` again | `200` with the **same** `payment.id`. No second node, no second file, no second email. |
| Same `message_key`, different `media.ref` | `201` — a different attachment is a different payment. |
| Retry with the same key but a **different** payload | `200` with the original payment, unmodified. The first message wins. |
| Same key, but its node was **deleted** in the back office | `201` — the orphan ledger row is swept and a new payment is created. |
| Two **simultaneous** retries | One `201` and one `200`, one node. The loser's transaction rolls back against the primary key. |

**The body of the `200` is identical to the body of the `201`.** The only
difference is the HTTP status, which is there for whoever reads the logs; n8n
never has to branch — it always reads `data.payment`.

> **Requirement on the n8n flow: `media.ref` must be stable for a given
> attachment.** If the flow regenerated it on every attempt, each retry would
> build a different key and create a new payment. Diagnosis is one query: if
> duplicate payments appear carrying the same `wamid` with different
> `media_ref`, the problem is in n8n. Both halves are stored in the clear in
> `myapi_bot_payments` precisely so that query is possible.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `unauthorized` | `X-Api-Key` absent, empty, or different. Also when `myapi_bot_api_key` is not configured at all. |
| 405  | `method_not_allowed` | Any HTTP method other than `POST`. |
| 422  | `invalid_payload` | `payload` absent, empty, larger than 64 KB, malformed JSON, or JSON that is not an object. |
| 422  | `missing_field` | A required key is absent. `@field` carries the dotted path. |
| 422  | `invalid_field` | Present but impossible: a uid that is not an integer, an inactive user, a missing or unpublished unit, a condominium that does not match, an oversized reference or bank name. |
| 422  | `invalid_amount` | `receipt.amount` not numeric, or ≤ 0. |
| 422  | `invalid_date` | `receipt.date` with an impossible format or calendar date (`2026-02-30`). |
| 422  | `missing_file` | No `file` part. |
| 422  | `invalid_file` | Extension or size outside what is allowed, or `private://` not configured on the site. |
| 422  | `invalid_file_type` | The real MIME does not match an allowed type (a `.php` renamed to `.jpg`). |
| 403  | `unit_access_denied` | The uid is neither owner nor occupant of that unit. |
| 409  | `duplicate_reference` | That reference already exists in that unit under **another** idempotency key. |
| 500  | `server_error` | Site misconfiguration: SPEC 129 not applied, or `"Transferencia"` missing from the `allowed_values` of `field_forma_de_pago`. Logged to `watchdog`. |
| 500  | `server_error` | `uid` omitted and the unit has **no active occupant or owner** to attribute the payment to. Incomplete site data, not a payload error. Logged to `watchdog`. |

**`403` and `409` say two different things to the bot.** The `403` means "you
got the person or the unit wrong" and sends the flow back to ask again. The
`409` means "this receipt is already registered" and is a finished
conversation.

**A `500` is never n8n's fault.** It is the site's, and answering `422` would
send the flow hunting for an error in a payload that has none.

**Site requirements**

- **SPEC 129 must be applied** (`drush updb`) before this endpoint is
  deployed. Without `field_comprobante_ocr`, `node_save()` would drop the
  channel and the evidence *silently*. The endpoint refuses to run instead:
  `500` plus a `watchdog` entry.
- **The private filesystem must be configured.** The receipt is mandatory, so
  without `private://` no bot payment can be registered at all.
- **HTTPS.** The key is a machine credential that now creates payments.

**Examples**

```bash
# 201 — the happy path
curl -i -X POST 'https://<host>/api/v1/bot/payments' \
  -H 'X-Api-Key: <the secret>' \
  -F 'payload=@caso.json' \
  -F 'file=@comprobante.jpg'

# 200 — the same call again: same payment.id, nothing created
curl -i -X POST 'https://<host>/api/v1/bot/payments' \
  -H 'X-Api-Key: <the secret>' \
  -F 'payload=@caso.json' \
  -F 'file=@comprobante.jpg'

# 422 missing_file
curl -i -X POST 'https://<host>/api/v1/bot/payments' \
  -H 'X-Api-Key: <the secret>' \
  -F 'payload=@caso.json'
```

A minimal `caso.json`:

```json
{
  "message":  { "message_key": "wamid.HBgMNTkzOTg3NTM1NjQ1FQIAEhgU" },
  "identity": { "person": { "uid": 3 },
                "unit": { "unit_id": 45821, "condominium_id": 12 } },
  "media":    { "ref": "m-1" },
  "receipt":  { "reference": "018273645", "amount": 45.3, "date": "2026-09-12" }
}
```

**Tables written**
| Table | Use |
|-------|-----|
| `node` + the `pagos` field tables | The payment itself, through `node_save()`. |
| `file_managed`, `file_usage` | The receipt, as a permanent managed file tied to the node. |
| `myapi_bot_payments` | The idempotency ledger: one row per message attachment, with `message_key` and `media_ref` in the clear for auditing, and `uid_resolved` flagging the payments whose owner the server resolved. |

`node_save()` and the ledger `INSERT` share one transaction: either the payment
and its row both exist, or neither does. The `backend` email is sent **after**
that transaction commits, so a losing retry never enqueues a mail for a node
that does not exist.

Nothing is read or written until the API key has been accepted.

---

## Operation — the API key

The key lives in a Drupal variable and has **no default**. Until it is set,
`variable_get()` answers the empty string, the empty string never
authenticates, and every request to every bot endpoint is a `401`. An endpoint
left open because nobody configured it is worse than one that does not work
until it is configured.

Set it:

```bash
drush vset myapi_bot_api_key '<a long random secret>'
drush cc all
```

Generate the secret with something that is not guessable, e.g.:

```bash
openssl rand -hex 32
```

Check it answers:

```bash
# 401 — no credential
curl -i 'https://<host>/api/v1/bot/person?phone=0987535645'

# 200
curl -i -H 'X-Api-Key: <the secret>' \
  'https://<host>/api/v1/bot/person?phone=0987535645'
```

**Rotating the key** is the same `drush vset` plus the matching change in the
n8n credential. There is no admin form and no second key: there is exactly one
consumer. Rotate immediately if the key is ever pasted anywhere it should not
be — anyone holding it can look up any phone number and get back a name, a uid,
and the units and condominiums that person belongs to.

Rules that are not negotiable:

- HTTPS only. The key travels in a header on every request.
- The key never goes in the repository, in a URL, or in an n8n workflow export.
- It is handed to n8n out of band, never over the same channel as anything else.

Every rejected request writes a `WATCHDOG_WARNING` entry naming the path and
the caller's IP, so an attempt to guess the key — or a leaked key being used
from somewhere unexpected — leaves a trace in `admin/reports/dblog`.

**If the header does not arrive:** some Apache/FastCGI setups and some reverse
proxies drop non-standard headers, which would make every request a `401` even
with the right key. Verify with the `curl` above before declaring the endpoint
live. The fallback, if it happens, is `Authorization: ApiKey <key>`, and it
only changes `myapi_bot_require_api_key()`.

---

## Baseline data (fill in from production)

Two numbers worth measuring once and writing down here, because they decide
whether the bot looks broken when it is merely short of data:

- How many active users have a row in `field_data_field_telefono`. If half the
  base has no phone, half the lookups are `not_found` and nothing is wrong with
  the endpoint.
- How many groups of last-9-digits have more than one active uid. That is how
  often `ambiguous` will fire.

```sql
-- Format census: anything outside 9, 10 or 12 digits is a format the
-- normalisation does not contemplate; more than 13 suggests two numbers in
-- one field.
SELECT LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
         field_telefono_value,' ',''),'-',''),'(',''),')',''),'+','')) AS digits,
       COUNT(*) AS rows_found
FROM dr_field_data_field_telefono
WHERE entity_type = 'user' AND deleted = 0
GROUP BY digits ORDER BY rows_found DESC;
```
