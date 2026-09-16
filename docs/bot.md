# Bot endpoints (SPECS 127, 128)

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
      { "unit_id": 456, "unit": "Dpto 3B", "condominium_id": 12, "condominium": "Torre Azul", "relation": "owner" },
      { "unit_id": 789, "unit": "Local 2",  "condominium_id": 12, "condominium": "Torre Azul", "relation": "occupant" }
    ]
  }
}
```

`relation` is `"owner"` or `"occupant"`, and it belongs to each unit rather than
to the person: a tenant pays the maintenance fee as often as an owner does, and
the same person can own one unit and rent another. When somebody is both owner
and occupant of the *same* unit, `"owner"` wins.

Only published units whose condominium is also published are listed. Neither
the balance (`current_balance`) nor the condominium's payment information is
returned, and neither is the person's phone, national id or email.

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
| `node` (condominio) | `nid`, `title`, `status` | Condominium title, via `myapi_unit_fetch_condominiums()`. |

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
        "owner": { "uid": 123, "name": "Juan Pérez" },
        "match": "exact"
      },
      {
        "unit_id": 789,
        "unit": "Dpto 3B",
        "condominium_id": 31,
        "condominium": "Torre Azul II",
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
| `owner` | `{ uid, name }`, or `null` when the unit has no owner assigned or the uid no longer resolves. The unit still travels either way. Never the phone, the national id or the email. |
| `match` | `"exact"` when unit **and** condominium were resolved without approximating — that includes a prefix or a partial match, since both are literal. `"fuzzy"` when either of the two needed the second pass. |

**`total` counts inside the 20 condominiums examined, not inside the 150.** A
term like `ed` matches the hundred buildings whose name begins with "Edificio",
and scanning their 23.000 units to produce a number is not what this endpoint is
for: the search keeps the best 20 buildings and counts within them. With a very
short term the bot may say "there are 12" when there really are 60. Do not build
a count on this number — it is a search made to narrow things down, not a
report.

Only published units whose condominium is also published are returned. Neither
the balance (`current_balance`) nor the condominium's payment information is
included, exactly as in `/bot/person`.

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

No table is written, no table is read until the API key has been accepted, and
the unit table is not touched at all when the condominium term matches nothing.

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
