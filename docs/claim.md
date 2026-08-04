# Claims API (SPEC 64, SPEC 65, SPEC 66, SPEC 67)

Five endpoints over the `reclamo` nodes the back office manages (SPEC 55–63):
a paginated list, a detail by id, the authenticated download of one claim file
(SPEC 65), creating a claim (SPEC 66), and editing one's own claim while it is
still `received` (SPEC 67). The Flutter app consumes them; nothing here closes
or deletes a claim, edits a claim filed by somebody else, or adds a
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

## POST /api/v1/claims

Creates a `reclamo` node for the authenticated resident, with up to 5 images
and one optional attachment. The request is `multipart/form-data` (text
fields plus files), not JSON — same contract as `POST /api/v1/payments`. The
response is the **same object** `GET /api/v1/claims/%` returns, transactions
always expanded: the initial `"received"` transaction that
`hook_node_insert()` creates on its own is already there, so the client never
needs a second request to learn its `id`.

Out of scope of this endpoint: closing or deleting a claim, and adding a
transaction from the app (comments, status changes) — those remain back office
only. Editing a claim one filed is `POST /api/v1/claims/{id}`, documented
below.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |
| Content-Type | `multipart/form-data` |

**Request body (form-data fields)**
| Field | Required | Type | Rule |
|-------|----------|------|------|
| `subject` | **yes** | string | ≤ 255 chars (`node.title` is `varchar(255)`). Otherwise → `422 invalid_field`. |
| `claim_type` | **yes** | string | Must be a **key** of `field_claim_type`'s `allowed_values` (`requirement` \| `claim`), not the visible label. Otherwise → `422 invalid_field`. |
| `condominium_id` | **yes** | int (nid) | Integer > 0, and one of the condominiums the authenticated user belongs to (`myapi_condominium_related_nids($uid)`, the same set `GET /api/v1/claims` uses). Bad format → `422 invalid_field`; a foreign condominium, or a user with no condominium at all → `403 condominium_access_denied`. |
| `description` | **yes** | string | Non-empty after `trim()`. Otherwise → `422 invalid_field`. No maximum (`text_long`). |
| `visibility` | **yes** | string | Must be a **key** of `field_visibility`'s `allowed_values` (`private` \| `public`). Otherwise → `422 invalid_field`. |
| `images[]` | no | file[] | Up to 5 files. See file rules below. |
| `attachment` | no | file | At most 1 file. See file rules below. |

`solicitante` is **not a field of this request**: `field_requester` is always
the `uid` of the Bearer token, never read from the body — sending a different
value has no effect, because there is no such input to send.

**Images (`images[]`) and attachment (`attachment`)**
| Aspect | Images | Attachment |
|--------|--------|------------|
| Extensions | `jpg`, `jpeg`, `png` (SPEC 55, unchanged) | `pdf`, `doc`, `docx`, `xls`, `xlsx` (SPEC 55, unchanged) |
| Size | ≤ 3 MB each | ≤ 3 MB |
| Count | Up to 5. A 6th file → `422 claim_too_many_images`, none saved. | At most 1 — `attachment` is not an array field, so a second `attachment` part sent by the client is not receivable as two files. |
| Real MIME | Checked with `finfo`, derived from the field's own allowed extensions (not hardcoded). Mismatch (e.g. a `.php` renamed to `.jpg`) → `422 invalid_file_type`. | Same check → `422 invalid_file_type`. |
| Rejected extension/size | `422 claim_invalid_image` | `422 claim_invalid_attachment` |
| Storage | Saved as **permanent** managed files under the field's configured `private://` directory, each with a `file_usage` row tied to the node. | Same. |

Nothing about extensions or sizes is hardcoded in the endpoint: it reads
`field_images` / `field_attachment`'s own Field API instance
(`file_field_widget_upload_validators()` / `file_field_widget_uri()`), the
same functions the native `managed_file` widget uses — if SPEC 55's limits
change from the Drupal UI, this endpoint inherits the change automatically.

**All-or-nothing on files.** Any image or attachment that fails validation
aborts the whole request: every file already saved **in that same request**
(earlier valid images, if the attachment is what failed) is deleted before the
error response, and no node is created. There is no partial claim with only
the valid files attached.

**Fields the server always sets, never the client**
| Field | Value |
|-------|-------|
| `node.uid` | `uid` of the Bearer token |
| `node.status` | `1` (published) |
| `field_requester` | `uid` of the Bearer token |
| `field_status` | `'received'`, written explicitly — a programmatic `node_save()` does not go through `field_default_form()`, so the field's `default_value` is never applied on its own. |
| `field_reception_date` | `date('Y-m-d H:i:00')`, the server's own instant at creation time. |

**Success response (201)**

Identical shape to `GET /api/v1/claims/%` — see **Field reference** below for
every key. The only endpoint-specific detail is that `transactions` always
carries exactly one element right after creation: the automatic `"received"`
transaction.

```json
{
  "success": true,
  "data": {
    "claim": {
      "id": 141,
      "subject": "Fuga de agua en el pasillo",
      "description": "Se ve una mancha de humedad que crece desde el lunes.",
      "status": "received",
      "claim_type": "claim",
      "visibility": "private",
      "reception_date": "2026-08-03T16:45:00",
      "created": "2026-08-03T16:45:01",
      "condominium_id": 7,
      "condominium_name": "Residencias El Parque",
      "requester_id": 34,
      "images": [
        {
          "id": 520,
          "url": "https://mi-sitio/api/v1/claims/141/files/520",
          "filename": "mancha.jpg"
        }
      ],
      "attachment": null,
      "transactions": [
        {
          "id": 88,
          "status": "received",
          "status_date": "2026-08-03T16:45:00",
          "comment": "Hemos recibido su reclamo. Será revisado por la administración y se le notificará cualquier novedad.",
          "created": "2026-08-03T16:45:01",
          "images": [],
          "attachment": null
        }
      ]
    }
  },
  "message": "Reclamo creado correctamente."
}
```

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | `Authorization` header absent or not a `Bearer <token>`. |
| 401 | `invalid_token` | Access token invalid, revoked, expired, or its user is missing/blocked. |
| 403 | `condominium_access_denied` | `condominium_id` is a positive integer the user does not belong to, or the user has no condominium at all. Nothing is created. |
| 405 | `method_not_allowed` | Any method other than `GET` or `POST` on `/api/v1/claims`. Creation is always on the collection: `POST /api/v1/claims/{id}` is **not** a creation, it is the update endpoint below. |
| 422 | `missing_field` | A required field is absent or empty (`@field` names it): `subject`, `claim_type`, `condominium_id`, `description`, or `visibility`. |
| 422 | `invalid_field` | `subject` over 255 chars, `claim_type` not a key of its `allowed_values`, `condominium_id` not a positive integer, `description` empty after `trim()`, or `visibility` not a key of its `allowed_values`. |
| 422 | `claim_too_many_images` | More than 5 files in `images[]`. Nothing is saved. |
| 422 | `claim_invalid_image` | An image fails extension or size validation. All-or-nothing: any images already saved in the same request are deleted too. |
| 422 | `claim_invalid_attachment` | The attachment fails extension or size validation. All-or-nothing: any images already saved in the same request are deleted too. |
| 422 | `invalid_file_type` | An image's or the attachment's real MIME (checked with `finfo`) does not match its extension. |

Validation runs in the order listed in **Request body** above, and each check
aborts before anything is created. See [i18n.md](i18n.md) for the translated
`error`/`message` text.

**Example (with one image, no attachment):**
```bash
curl -i -X POST https://host/api/v1/claims \
  -H 'Authorization: Bearer <access_token>' \
  -F 'subject=Fuga de agua en el pasillo' \
  -F 'claim_type=claim' \
  -F 'condominium_id=7' \
  -F 'description=Se ve una mancha de humedad que crece desde el lunes.' \
  -F 'visibility=private' \
  -F 'images[]=@mancha.jpg'
```

---

## POST /api/v1/claims/{id}

Lets the **requester** of a claim modify it — every text field and its files —
while its status is still `received`. The response is the **same object**
`GET /api/v1/claims/%` returns, transactions always expanded.

Two properties of this endpoint that the client has to know before writing
against it:

- **The update is total for text, incremental for files.** The five text fields
  are required on *every* call: this is not a `PATCH`, and sending only
  `description` answers `422 missing_field`. Files work the other way round —
  `images[]` adds, `remove_image_ids[]` removes, and anything the request does
  not mention stays exactly as it was. A request with none of the four file
  parameters changes the text and leaves every file untouched.
- **Only while the claim is `received`.** Once the administration moves it to
  `in_progress`, `resolved` or `closed`, the resident no longer edits it:
  `409 claim_not_editable`.

### Why `POST` on an item and not `PUT`

`PUT` is the semantically obvious verb, and it is deliberately not used. **PHP
populates neither `$_POST` nor `$_FILES` on a `PUT`**: the `multipart/form-data`
body would arrive raw through `php://input` and the module would have to carry a
hand-written MIME parser — real code and real risk, in a Drupal 7 install with no
security support, bought with nothing but a verb. The alternatives were weighed
and dropped: `PUT` with a JSON body leaves the files out and needs a second spec
of file-only endpoints, and `POST` + `_method=PUT` is this same request with one
more parameter to document.

This is the only `POST`-on-an-item route of the module. It is a decision, not an
oversight.

**Authentication:** required (Bearer access token) — and the caller must be the
claim's `field_requester`.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |
| Content-Type | `multipart/form-data` |

**Request body (form-data fields)**
| Field | Required | Type | Effect |
|-------|----------|------|--------|
| `subject` | **yes** | string | Replaces `node.title`. ≤ 255 chars, otherwise → `422 invalid_field`. |
| `claim_type` | **yes** | string | Replaces `field_claim_type`. Must be a **key** of its `allowed_values` (`requirement` \| `claim`), not the visible label. |
| `condominium_id` | **yes** | int (nid) | Replaces `field_condominium`. Integer > 0 and one of the user's own condominiums. Bad format → `422 invalid_field`; a foreign one → `403 condominium_access_denied`. **Editable on purpose**: it is the field most easily got wrong when filing, for a resident with a unit in two condominiums. |
| `description` | **yes** | string | Replaces `field_description`. Non-empty after `trim()`. |
| `visibility` | **yes** | string | Replaces `field_visibility`. Must be a **key** of its `allowed_values` (`private` \| `public`). |
| `images[]` | no | file[] | **Adds** files at the end of `field_images`. Same extension, size and MIME rules as creation. |
| `remove_image_ids[]` | no | int[] | **Removes** those images from the claim and **deletes them from disk**. Every value must be a `fid` this claim references right now. |
| `attachment` | no | file | Replaces `field_attachment`. The previous attachment is deleted. |
| `remove_attachment` | no | `1` \| `true` | Leaves `field_attachment` empty and deletes the previous file. **Ignored when `attachment` also comes** in the request. |

`remove_image_ids[]` is sent as a repeated field, the same shape as `images[]`
(`-F 'remove_image_ids[]=520' -F 'remove_image_ids[]=521'`), not as a
comma-separated string.

**The three file rules, in full**

1. **Adding.** `images[]` appends to the end, in upload order. The existing
   images keep their `delta` order and **cannot be reordered** by this endpoint.
   The ceiling is still 5 images per claim, counted *after* the removals of the
   same request: the number of new images admitted is `5 - (current - removed)`.
   So on a claim with 4 images, removing 1 and adding 2 is valid (5 in total),
   while adding 2 without removing any answers `422 claim_too_many_images` — and
   neither of the two is saved.
2. **Removing.** Every `fid` in `remove_image_ids[]` must be an image **of this
   claim**. A `fid` of another claim, of a payment, or of nothing at all answers
   `422 invalid_field` with `@field: remove_image_ids`, and **not a single image
   is deleted** — no partial removal, no silent skip. A `fid` repeated twice
   counts once.
3. **Deletion is real and irreversible.** A removed image, a replaced attachment
   and a dropped attachment are deleted from `file_managed` and from disk
   (`file_usage_delete()` + `file_delete()`); there is no bin and no undo, and
   their `url` then answers `404 file_not_found`. **The app must confirm with the
   user before sending a removal.** The files live in `private://` and nothing
   else references them, so leaving them behind would only add dead weight to
   disk on every correction a resident makes.

**Fields the server never lets the client change**
| Field | Behaviour |
|-------|-----------|
| `node.uid` | Untouched — the original author. |
| `node.created` | Untouched. |
| `field_requester` | Untouched. It is not read from the request, exactly as in creation: sending a field with that name has no effect. |
| `field_status` | Untouched — it stays `received`, which is the precondition to get here. |
| `field_reception_date` | **Untouched.** It is the date the administration received the claim, not the date of its last correction; re-stamping it would falsify the listing order and the `date_from`/`date_to` filters. |
| `node.changed` | Moves on its own, through `node_save()`. |

The claim's **timeline does not change**: editing creates no transaction and
sends no notification. Transactions are the history of *statuses*, and editing
does not change the status — the same `transactions` array, with the same `id`s,
comes back in the response.

**Nothing is ever left half-done.** Everything validates and the new files are
saved *before* the node is written; if anything fails, those new files are
deleted and the claim keeps its text and its original files, byte for byte. Only
after `node_save()` succeeds are the old files deleted — which is what
guarantees a file the node still references is never destroyed.

**Success response (200)**

Identical shape to `GET /api/v1/claims/%` — see **Field reference** below for
every key. `reception_date`, `created` and `transactions` are the same values as
before the edit.

```json
{
  "success": true,
  "data": {
    "claim": {
      "id": 141,
      "subject": "Fuga de agua en el pasillo (corregido)",
      "description": "La mancha llega ya hasta la puerta 3-B.",
      "status": "received",
      "claim_type": "claim",
      "visibility": "private",
      "reception_date": "2026-08-03T16:45:00",
      "created": "2026-08-03T16:45:01",
      "condominium_id": 7,
      "condominium_name": "Residencias El Parque",
      "requester_id": 34,
      "images": [
        {
          "id": 521,
          "url": "https://mi-sitio/api/v1/claims/141/files/521",
          "filename": "pasillo.jpg"
        },
        {
          "id": 530,
          "url": "https://mi-sitio/api/v1/claims/141/files/530",
          "filename": "puerta.jpg"
        }
      ],
      "attachment": null,
      "transactions": [
        {
          "id": 88,
          "status": "received",
          "status_date": "2026-08-03T16:45:00",
          "comment": "Hemos recibido su reclamo. Será revisado por la administración y se le notificará cualquier novedad.",
          "created": "2026-08-03T16:45:01",
          "images": [],
          "attachment": null
        }
      ]
    }
  },
  "message": "Reclamo actualizado correctamente."
}
```

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | `Authorization` header absent or not a `Bearer <token>`. |
| 401 | `invalid_token` | Access token invalid, revoked, expired, or its user is missing/blocked. |
| 403 | `claim_edit_denied` | The claim **is** visible to the caller (a `public` one of a neighbour) but they are not its `field_requester`. Deliberately not a `404`: there is nothing left to hide once the claim is already readable, and a `404` would only confuse the diagnosis in support. |
| 403 | `condominium_access_denied` | `condominium_id` is a positive integer the user does not belong to. Nothing is modified. |
| 404 | `claim_not_found` | The claim does not exist, is unpublished, belongs to another condominium, is private and filed by somebody else, or the id is not a positive integer. The four are indistinguishable — the same rule and the same message as `GET /api/v1/claims/%`. |
| 405 | `method_not_allowed` | `PUT` or `DELETE` on `/api/v1/claims/{id}`. |
| 409 | `claim_not_editable` | The caller is the requester, but the claim's status is no longer `received`. The payload is valid; what conflicts is the current state of the resource. |
| 422 | `missing_field` | A required field is absent or empty (`@field` names it): `subject`, `claim_type`, `condominium_id`, `description`, or `visibility`. The update is total, so this fires even when only one field changed. |
| 422 | `invalid_field` | `subject` over 255 chars, `claim_type` not a key of its `allowed_values`, `condominium_id` not a positive integer, `description` empty after `trim()`, `visibility` not a key of its `allowed_values`, or (`@field: remove_image_ids`) a `fid` that is not a positive integer or is not an image of this claim. |
| 422 | `claim_too_many_images` | The new images do not fit in `5 - (current - removed)`. None of them is saved. |
| 422 | `claim_invalid_image` | An image fails extension or size validation. All-or-nothing: the images already saved in the same request are deleted too, and the claim's own files are untouched. |
| 422 | `claim_invalid_attachment` | The attachment fails extension or size validation. The images of that same request are deleted too, and the previous attachment stays in place. |
| 422 | `invalid_file_type` | An image's or the attachment's real MIME (checked with `finfo`) does not match its extension. |

`409` is not in the status-code list of `CLAUDE.md`; SPEC 67 introduces it on
purpose for this one case. Validation runs in the order of the **Request body**
table — authentication, id, visibility, requester, status, then the fields — and
each check aborts before anything is written. See [i18n.md](i18n.md) for the
translated `error`/`message` text.

**Example (change the text, drop one image, add another, delete the attachment):**
```bash
curl -i -X POST https://host/api/v1/claims/141 \
  -H 'Authorization: Bearer <access_token>' \
  -F 'subject=Fuga de agua en el pasillo (corregido)' \
  -F 'claim_type=claim' \
  -F 'condominium_id=7' \
  -F 'description=La mancha llega ya hasta la puerta 3-B.' \
  -F 'visibility=private' \
  -F 'remove_image_ids[]=520' \
  -F 'images[]=@puerta.jpg' \
  -F 'remove_attachment=1'
```

### Two things the endpoint cannot undo

- **Turning `visibility` from `public` back to `private` does not unsee it.**
  The neighbours who read the claim while it was public still know what it said.
  That is a property of the world, not of the code — the app's UI should not
  promise otherwise.
- **Changing `condominium_id` moves the whole claim**, with its transactions and
  its files: the neighbours of the previous condominium stop seeing it and those
  of the new one start to. That is the intended behaviour of fixing a wrongly
  filed claim, and the validation against the user's own condominiums keeps it
  from being moved out of their reach.

There is **no concurrency control**: two simultaneous edits of the same claim,
last one wins, with no `If-Match` and no `changed` comparison. The status can
also change between the moment the app renders the form and the moment the user
submits it — an administrator taking the claim meanwhile — which the resident
sees as a `409` on a form they had already filled in. The
`claim_not_editable` message says explicitly that the status changed, so the app
can reload the detail and explain it instead of showing a generic error.

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
