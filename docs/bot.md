# Bot endpoints (SPEC 127)

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
