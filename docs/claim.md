# Claims API (SPEC 64, SPEC 65)

Three read-only endpoints over the `reclamo` nodes the back office manages
(SPEC 55–63): a paginated list, a detail by id, and the authenticated download
of one claim file (SPEC 65). The Flutter app consumes them; nothing here
creates, edits or closes a claim, uploads or deletes a file, or adds a
transaction — those paths are back office only
(see [claim-transaction-timeline.md](claim-transaction-timeline.md)).

Two documents cover the other half of the same data:
[claims-install.md](claims-install.md) for the fields and bundles, and
[claims-list.md](claims-list.md) for the back-office listing at
`admin/content/claims`.

---

## Visibility rule

One condition, applied identically by both endpoints. A claim is visible to the
authenticated user when:

```
field_condominium ∈ (the user's condominiums)
AND ( field_visibility = 'public' OR field_requester = the user )
```

- **The user's condominiums** are `myapi_condominium_related_nids($uid)`: the
  condominiums of the units they own or occupy.
- **The condominium filter comes first and applies to everything**, including
  the user's own claims. A resident who no longer has a unit in a condominium
  stops seeing the private claims they filed there. This is deliberate, not a
  side effect — support will be asked about it.
- **`node.uid` plays no part.** The node's author is whoever typed the form; the
  requester is `field_requester`. An administrator filing a private claim on
  behalf of a resident is its author, and does **not** see it in their own app.
- A claim with **no `field_visibility` row** (imported by hand, or created
  before the field became required) is not visible: the join is INNER, so an
  undeclared visibility is never assumed to be `public`.
- Unpublished claims (`status = 0`) never appear, in either endpoint.
- A user with **no unit at all** gets `200` with an empty list — not a `403`.
  "You have nothing" is not "you may not", the same criterion as
  [bulletin.md](bulletin.md).

The queries deliberately carry **no `->addTag('node_access')`**: access is
decided by the condition above, written once in `myapi_claim_base_query()` and
shared by the list, the count and the detail. If a future spec adds
`hook_node_access()` rules over `reclamo`, these endpoints will not inherit
them.

---

## GET /api/v1/claims

Paginated list of the claims visible to the authenticated user, across all their
condominiums unless `condominium_id` narrows it.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**

Parsing is **lax**: an invalid value falls back to its default in silence and
never returns `422`. The single exception is `condominium_id`, marked below.

| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to `1`. A page past the last one returns `200` with an empty `claims` array. |
| `limit` | `20` | Clamped to `[1, 50]`. `-1` means "everything on one page" and forces `page=1` (SPEC 15). Any other invalid value falls back to `20`. |
| `sort` | `desc` | `asc` or `desc` over `reception_date`, tie-broken by `id` in the same direction so paging is stable. Any other value falls back to `desc`. |
| `condominium_id` | absent = all the user's condominiums | Positive integer (condominium `nid`). **A valid nid the user does not belong to returns `403 condominium_access_denied`** — asking for one specific condominium is a statement of intent, and silently answering with a different set would be misleading. A non-numeric, zero or negative value (`?condominium_id=abc`) is a client bug, not an access attempt: it is **ignored silently**, no `422` and no `403`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`, **inclusive**, compared **by day** against `reception_date`. Malformed or non-existent dates (`2026-02-30`, `01/08/2026`, `hoy`) are ignored silently. |
| `date_to` | absent = no upper bound | Same rules, inclusive upper bound. A claim received on the `date_to` day at 14:30 **does** appear. |
| `status` | absent = every status | One of `received`, `in_progress`, `resolved`, `closed`. Any other value (including `duplicated`, dropped by SPEC 62) means "no filter". |
| `claim_type` | absent = both types | `requirement` or `claim`. Any other value means "no filter". |
| `include` | absent = collapsed | The exact value `transactions` expands each claim's transactions into full objects. Any other value leaves them as an array of ids. |

Notes on the date bounds:

- An **inverted range** (`date_from > date_to`) discards the whole filter, both
  bounds, and answers as if none had been sent.
- With **either bound active**, claims with no `reception_date` fall out of the
  result. That is the intended reading of "show me what came in during this
  range".

**Success response (200)** — transactions collapsed (no `include`)

```json
{
  "success": true,
  "data": {
    "claims": [
      {
        "id": 140,
        "subject": "Filtración en el techo del pasillo",
        "description": "Se ve una mancha de humedad que crece desde el lunes.",
        "status": "in_progress",
        "claim_type": "claim",
        "visibility": "public",
        "reception_date": "2026-08-01T14:30:00",
        "created": "2026-08-01T14:31:12",
        "condominium_id": 7,
        "condominium_name": "Residencias El Parque",
        "requester_id": 34,
        "images": [
          {
            "id": 512,
            "url": "https://mi-sitio/api/v1/claims/140/files/512",
            "filename": "mancha.jpg"
          }
        ],
        "attachment": {
          "id": 513,
          "url": "https://mi-sitio/api/v1/claims/140/files/513",
          "filename": "informe.pdf"
        },
        "transactions": [12, 15, 18]
      }
    ],
    "pagination": {
      "total": 1,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

**Success response (200)** — `?include=transactions`

Same item, with `transactions` carrying objects instead of ids. The key name
does not change: the request the client made decides the type of its contents.

```json
{
  "success": true,
  "data": {
    "claims": [
      {
        "id": 140,
        "subject": "Filtración en el techo del pasillo",
        "...": "same keys as above",
        "transactions": [
          {
            "id": 15,
            "status": "in_progress",
            "status_date": "2026-08-02T09:00:00",
            "comment": "Se envió al plomero a revisar.",
            "created": "2026-08-02T09:02:44",
            "images": [],
            "attachment": null
          }
        ]
      }
    ],
    "pagination": {
      "total": 1,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

**Empty result (200)** — a user with no unit, or filters that match nothing:

```json
{
  "success": true,
  "data": {
    "claims": [],
    "pagination": { "total": 0, "page": 1, "limit": 20, "total_pages": 0 }
  }
}
```

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or its user blocked. |
| 403 | `condominium_access_denied` | `?condominium_id=` is a valid positive integer the user does not belong to (foreign or non-existent). |
| 405 | `method_not_allowed` | Any method other than `GET`. |

---

## GET /api/v1/claims/%

One claim by `nid`, under exactly the same visibility rule, with its
transactions **always expanded**.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters:** none. `include` is not read here — the detail exists to
read the timeline, so requiring `?include=transactions` would force every client
to always send it. The asymmetry with the list is deliberate.

**Success response (200)**

The item has exactly the same keys and types as a list item — it goes through
the same serialiser — and there is **no `pagination` block**.

```json
{
  "success": true,
  "data": {
    "claim": {
      "id": 140,
      "subject": "Filtración en el techo del pasillo",
      "description": "Se ve una mancha de humedad que crece desde el lunes.",
      "status": "in_progress",
      "claim_type": "claim",
      "visibility": "public",
      "reception_date": "2026-08-01T14:30:00",
      "created": "2026-08-01T14:31:12",
      "condominium_id": 7,
      "condominium_name": "Residencias El Parque",
      "requester_id": 34,
      "images": [],
      "attachment": null,
      "transactions": [
        {
          "id": 12,
          "status": "received",
          "status_date": "2026-08-01T14:30:00",
          "comment": "Hemos recibido su reclamo. Será revisado por la administración y se le notificará cualquier novedad.",
          "created": "2026-08-01T14:31:12",
          "images": [],
          "attachment": null
        },
        {
          "id": 15,
          "status": "in_progress",
          "status_date": "2026-08-02T09:00:00",
          "comment": "Se envió al plomero a revisar.",
          "created": "2026-08-02T09:02:44",
          "images": [],
          "attachment": null
        }
      ]
    }
  }
}
```

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or its user blocked. |
| 404 | `claim_not_found` | The claim does not exist, is unpublished, belongs to another condominium, is private and filed by somebody else, or the id is not a positive integer (`/api/v1/claims/abc`). |
| 405 | `method_not_allowed` | Any method other than `GET`. |

**All five cases answer the same `404`, on purpose.** Distinguishing them with a
`403` would confirm to anyone probing ids that a private claim exists behind
that number. The uniform `404` leaks neither the existence nor the reason. This
differs from the `403 unit_access_denied` of payments and reservations, where
the id in the URL is a unit the caller already knows about.

---

## GET /api/v1/claims/%/files/%

The bytes of one image or attachment of a claim (SPEC 65). This is the URL that
travels in every `url` key of the two endpoints above — the client does not
compose it.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

The token is read from the `Authorization` header **only**. `?access_token=` is
deliberately not supported: a token in a query string ends up in the server
logs, in the `Referer` and in the browser history — the exact kind of leak this
endpoint exists to close. Flutter's image loader accepts headers.

**Path parameters**

| Param | Notes |
|-------|-------|
| `{claim_nid}` (arg 3) | The nid of the **claim**, always — also for the files of one of its transactions. The claim is the unit of access, and the app never needs to know which transaction a picture hangs from. |
| `{fid}` (arg 5) | `file_managed.fid`, the `id` of the file object. |

**Success response (200) — the bytes of the file, not the JSON envelope**

This is the one endpoint of the module that does not answer the response
envelope on success, and the exception is deliberate: a binary file has no
envelope to travel in, and base64 inside a JSON body would inflate it by a third
and break every standard image loader. **Errors still use the envelope**, below.

| Header | Value |
|--------|-------|
| `Content-Type` | `file_managed.filemime` |
| `Content-Length` | `file_managed.filesize` |
| `Content-Disposition` | `inline; filename="<original name>"` |
| `Cache-Control` | `private, no-store` |

`inline` and not `attachment`: the app renders these images on screen, and
`attachment` would force a download in any web viewer. The original filename
travels in the header either way.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or its user blocked. |
| 404 | `claim_not_found` | The claim does not exist, is unpublished, belongs to another condominium, is private and filed by somebody else, or the id is not a positive integer. Same rule and same message as `GET /api/v1/claims/%`. |
| 404 | `file_not_found` | The claim **is** visible, but the fid does not exist, is not a positive integer, does not belong to that claim nor to any of its transactions, or its physical file is not on disk. |
| 405 | `method_not_allowed` | Any method other than `GET`. |

**The order of the checks is token → claim → file, always.** The first question
that fails is the one that answers. A foreign fid under a claim the caller
cannot see returns `claim_not_found`, never `file_not_found`: answering about
the file first would let anyone probe fids under a claim they have no access to.

**A fid that exists but belongs to another claim returns `404`, not `403`.** The
caller has already proved they can see the claim in the path, so distinguishing
"that file is not of this claim" leaks nothing and separates two different
problems in support. A `403` *would* leak: it would confirm that the fid exists
somewhere else.

---

## Field reference

**Claim item**

| Key | Type | Source | Notes |
|-----|------|--------|-------|
| `id` | int | `node.nid` | |
| `subject` | string | `node.title` | |
| `description` | string \| null | `field_description` | |
| `status` | string \| null | `field_status` | `received` / `in_progress` / `resolved` / `closed`. |
| `claim_type` | string \| null | `field_claim_type` | `requirement` / `claim`. |
| `visibility` | string | `field_visibility` | `public` / `private`. Never null — the join is INNER. |
| `reception_date` | string \| null | `field_reception_date` | `Y-m-dTH:i:s`, see **Dates** below. |
| `created` | string | `node.created` | `Y-m-dTH:i:s` in the site's timezone. |
| `condominium_id` | int \| null | `field_condominium` | |
| `condominium_name` | string \| null | the condominium's `node.title` | |
| `requester_id` | int \| null | `field_requester` | The requester's **uid**. Their name is not exposed; see below. |
| `images` | array | `field_images` | Always an array, empty when there are none, in `delta` order. |
| `attachment` | object \| null | `field_attachment` | Cardinality 1. |
| `transactions` | array | `field_claim` (reverse) | Ints, or objects with `?include=transactions` and always in the detail. |

**File object** (each entry of `images`, and `attachment`)

| Key | Type | Notes |
|-----|------|-------|
| `id` | int | `file_managed.fid`. |
| `url` | string | Absolute URL of `GET /api/v1/claims/{claim_nid}/files/{id}`. **Requires the `Authorization` header — see below.** The `{claim_nid}` is always the **claim's**, also for the files of a transaction. |
| `filename` | string | The original file name. |

**Transaction object** (expanded)

| Key | Type | Notes |
|-----|------|-------|
| `id` | int | The transaction's `nid`. |
| `status` | string \| null | The status this transaction recorded. |
| `status_date` | string \| null | `Y-m-dTH:i:s`, see **Dates**. |
| `comment` | string \| null | |
| `created` | string | `Y-m-dTH:i:s` in the site's timezone. |
| `images` | array | Same rules as the claim's. |
| `attachment` | object \| null | Same rules as the claim's. |

Transactions come in **ascending** order by `status_date`, tie-broken by `id`
ascending: it is a timeline, read oldest first. Only published transactions are
listed. A claim with none answers `"transactions": []` in both modes.

**The author of a transaction is not exposed.** Which administrator recorded a
status change is back-office information; the resident needs to know what
happened and when, not who typed it. Same reasoning for the requester's name:
no `api/v1/...` endpoint exposes usernames today, and on a public claim it would
publish the name of the neighbour who complained to the whole condominium.

---

## Dates

`reception_date` and `status_date` come out as the **stored value with a `T` in
it, with no timezone conversion at all**. Both fields were created with
`tz_handling = 'none'` (SPEC 55, widened to minute granularity by SPEC 58 and
SPEC 63): what is stored is a naive local time typed by an operator, not a UTC
instant. Running it through `strtotime()` + `format_date()` would shift it by
the server's timezone and return an hour nobody ever wrote.

A claim created before SPEC 63 stores `Y-m-d 00:00:00` and comes out as
`...T00:00:00`. That is correct, not an error.

`created`, in contrast, **is** a real Unix timestamp, so it is formatted with
`format_date()` in the site's timezone.

The `date_from` / `date_to` filters compare **by day** (`SUBSTR(value, 1, 10)`),
which is what makes `date_to` inclusive now that the field carries a time.
Filtering by hour is not supported.

---

## Image and attachment URLs are authenticated (SPEC 65)

**No file of a claim is served without an access decision.** `field_images` and
`field_attachment` live in `private://` since SPEC 65, so the web server never
delivers them directly; every read goes through PHP and through one of two
doors:

| Reader | Door | Rule |
|--------|------|------|
| The app | `GET /api/v1/claims/%/files/%`, documented above | Bearer token + the visibility rule of this document, evaluated with the same `myapi_claim_base_query()` the list and the detail use. |
| The back office | `hook_file_download()` (`myapi_file_download()`) | Drupal session + role: `administrator`, `backend`, and `administrador edificio` **scoped to its assigned condominiums** (SPEC 49/56). Anybody else, and any anonymous, gets `403`. |

Both doors share `includes/myapi.claims_files.inc`, which resolves which claim
owns a given fid — directly, or through the transaction that carries it.

**What the app has to do:** send `Authorization: Bearer <token>` when loading an
image too, not only when calling the JSON endpoints. A `url` requested without
the header answers `401`, not the file.

### `file_private_path` is an environment prerequisite

The private file system must be configured in `settings.php` (or at
`admin/config/media/file-system`) **before** running `drush updb`. Without it
the `private://` scheme does not resolve, `myapi_update_7023()` aborts with a
message naming `file_private_path`, and no field is modified.

The directory must live **outside the docroot**, or be protected by the
`.htaccess` Drupal writes into it. A private directory that the web server still
serves changes the URL of the files and nothing else: it does not close a single
thing.

### The migration closes future access, it does not rewind the past

`myapi_update_7023()` moves the already-uploaded files from `public://` to
`private://` and rewrites `file_managed.uri`. From that moment on, no claim file
is served unauthenticated.

**What it cannot do is recover what already left.** A `sites/default/files` URL
that was shared in a chat, cached by a proxy, or saved to a device is a copy
outside anyone's control, and no code change brings it back. What stops working
is the URL, not the copies already made.

Two operational consequences of the same migration:

- Run `drush image-flush --all` after `drush updb`. Image-style derivatives
  generated while the originals were public stay written under
  `sites/default/files/styles/` and keep being served; flushing them removes
  those copies, and they regenerate under `private://styles/` on the next view.
- Deployed app versions with cached `sites/default/files` URLs stop seeing
  images the moment the update runs. This is a coordinated migration: run it
  once the app version that sends the header is published, or accept the window
  of broken images.

### Maintenance rule

Any new file field on `reclamo` or `claim_transaction` must be created with
`'settings' => ['uri_scheme' => 'private']` **and** added to the ownership
resolution in `includes/myapi.claims_files.inc`. A field created without both is
born public and unreachable through either door — exactly the hole SPEC 65
closed.

---

## Implementation notes

**The `field_data_*` tables are read directly.** These endpoints do not call
`node_load_multiple()`: loading full node objects would fire the entity load
hooks once per claim and drag every field along for a response that needs a
dozen columns. It is the same approach as [payment.md](payment.md) and
[reservation.md](reservation.md), and it carries the same caveat — the queries
assume the current field storage, and rebuilding one of these fields from the UI
(renaming it, or deleting and recreating it) would break them.

**Query count is constant.** A page of 20 claims costs the same number of
queries as a page of 1: the count, the page itself, one batch query for all the
images of the page, and one for all the transactions — plus, when they are
expanded, one more for the transactions' own images. Nothing runs per row.

`?limit=-1` combined with `?include=transactions` keeps that constant query
count, but the response itself grows with the whole matching set: in a
condominium with hundreds of claims it can weigh several MB. `limit=-1` is a
conscious client decision (SPEC 15) and every listing in this module assumes the
same.

**`status` and `claim_type` are validated by the same functions the back office
uses** — `myapi_claims_valid_status()` and `myapi_claims_valid_claim_type()` in
`includes/myapi.claims_common.inc`. There is no second whitelist written inside
the resource, so `admin/content/claims` and this API can never disagree about
which values exist, and the next change to the catalogue has one file to touch.
