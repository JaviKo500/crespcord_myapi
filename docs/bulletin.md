## GET /api/v1/bulletins

Returns a paginated list of published `boletin` nodes (exposed as `bulletins`)
that the authenticated user is allowed to see, according to the bulletin's
audience — the cross of `field_tipo_de_boletin` (`type`) with `field_enviar_a`
(`send_to`). The visibility rule is the **reverse of the notification fan-out**
(`myapi_boletin_recipient_uids()`, see [notification.md](notification.md)): a
user sees exactly the set of bulletins whose fan-out included them as a
recipient. Read-only: no create/update/delete, no single-bulletin detail
endpoint, and the attachment is exposed only as a `file_id` (the app resolves
its URL separately).

The base list has **no `403`**: the audience is applied inside the query, so the
authenticated user simply receives their own visible set (an empty list when
nothing matches). The **only** case that returns `403` is the optional
`condominium_id` filter (see **Condominium filter** below): asking to scope the
list to a condominium the user does not belong to is an explicit access denial,
not a silent partial result.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Query parameters**
| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based. Any non-positive-integer value falls back to the default silently (no `422`). |
| `limit` | `20` | Clamped to `[1, 50]`. Any non-positive-integer or out-of-range value falls back to the default/clamp silently. |
| `sort` | `desc` | `asc` or `desc`, applied to `created_at` (`node.created`). Any other value falls back to `desc`. |
| `date_from` | absent = no lower bound | ISO `YYYY-MM-DD`. When valid, keeps only bulletins with `created_at >= date_from` at `00:00:00` (site-local). Any malformed or non-calendar value (e.g. `2026-13-40`, `01-06-2026`, `hoy`) is ignored silently (no `422`), as if absent. |
| `date_to` | absent = no upper bound | ISO `YYYY-MM-DD`. When valid, keeps only bulletins with `created_at <= date_to` at `23:59:59` (site-local), so the whole day is included. Same silent-ignore rule as `date_from`. |
| `condominium_id` | absent = no filter (spec-29 behaviour) | Positive integer (condominium `nid`). When present, narrows the result to this condominium (see **Condominium filter** below). Unlike `page`/`date_*`, a malformed value is **not** ignored: it returns `422`, and a condominium the user does not belong to returns `403`. |

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "bulletins": [
      {
        "id": 812,
        "title": "Corte de agua programado",
        "message": "<p>El sábado de 8:00 a 12:00 se suspende el servicio.</p>",
        "type": "Condominio",
        "send_to": "Todos",
        "condominium_id": 34,
        "file_id": 91,
        "file_url": "https://mi-sitio/sites/default/files/adjuntos/corte-agua.pdf",
        "file_mime": "application/pdf",
        "created_at": 1752566400
      }
    ],
    "pagination": {
      "total": 5,
      "page": 1,
      "limit": 20,
      "total_pages": 1
    }
  }
}
```

A user with no visible bulletins gets `{"bulletins": [], "pagination": {"total":
0, "page": 1, "limit": 20, "total_pages": 0}}` with `200` (not an error).
Requesting a page beyond the last one also returns `200` with `bulletins: []`
(not an error).

Notes:
- Only published (`status = 1`) `boletin` nodes are returned. A draft never
  appears — the same criterion as the fan-out, which does not notify drafts.
- Every bulletin includes exactly 10 keys: `id`, `title`, `message`, `type`,
  `send_to`, `condominium_id`, `file_id`, `file_url`, `file_mime`, `created_at`
  (see mapping table below). `message`, `send_to`, `condominium_id`, `file_id`,
  `file_url` and `file_mime` are `null` when the node has no row in that field's
  storage table; `type` never is (inner join). No other transformation is
  applied.
- `message` is the **raw HTML** of `field_mensaje`, exposed verbatim. It is
  **not** sanitized or converted to plain text — safe rendering is the client's
  responsibility (the app renders rich format with `flutter_html`). Do not
  render it in an unsafe context.
- `file_id` is the managed-file id (`fid`) of `field_adjunto`. The attachment
  lives in the **public** filesystem, so the endpoint also resolves `file_url`
  (a directly fetchable link, `file_create_url()`) and `file_mime` (the stored
  `filemime`, e.g. `image/jpeg` or `application/pdf`). The app renders it
  natively from `file_url` — `Image.network` for images, a PDF viewer for
  documents — choosing by `file_mime`; no WebView or binary-download endpoint is
  needed. `file_url`/`file_mime` are `null` when there is no attachment, and
  also when `file_id` is set but its managed file no longer resolves (in that
  edge case `file_id` still carries the stored fid).
  > Because the file is public, anyone holding the URL can fetch it regardless
  > of the bulletin's audience. This is acceptable for non-sensitive notices; an
  > attachment carrying private data would instead need `private://` storage and
  > an authenticated download endpoint.
- `condominium_id` is the target nid of `field_condominio`; it is normally
  `null` except on bulletins of `type = "Condominio"`.
- `total`/`total_pages` in `pagination` reflect the unpaginated count of the
  **already filtered** set (audience + `date_from`/`date_to` if any), not a raw
  total. `total_pages` is `0` when `total` is `0`.
- Sorting is always by `created_at` (`node.created`); there is no other sort
  field. Bulletins sharing the same `created_at` are broken by `id` (`nid`) in
  the same direction as `sort`, so the order is deterministic and stable across
  requests and pages.

**Visibility model (audience)**

A bulletin is visible to the authenticated user when it matches **any** of the
three audience branches. This mirrors, in reverse, the roles the fan-out
resolves when the bulletin is created; the reader's roles are computed once per
request from their owned/occupied units and the condominiums those units belong
to.

- **`type = "General"`** — visible when the user holds the role `send_to`
  demands: `Propietarios` → owns at least one unit; `Ocupantes` → occupies at
  least one; `Todos` → owns **or** occupies any unit.
- **`type = "Condominio"`** — visible when `field_condominio` is a condominium
  where the user holds the role `send_to` demands (owner / occupant / either).
- **`type = "Personalizado"`** — visible when the user is referenced on the node
  itself: in `field_personalizar` when `send_to` is `Propietarios` or `Todos`,
  or in `field_ocupantes` when `send_to` is `Ocupantes` or `Todos`.
  `field_condominio` is ignored for this type.

Fail-safe: a bulletin whose `field_tipo_de_boletin` or `field_enviar_a` holds an
unknown value (or has no `send_to` row) matches no branch and is hidden from
everyone — never shown to all. A user with no units at all sees no `General` or
`Condominio` bulletins; only `Personalizado` ones where they are referenced by
hand.

> **Parity with the fan-out.** This endpoint and `myapi_boletin_recipient_uids()`
> (spec 25) encode the same audience rule in two places. They must stay in sync:
> the set a user sees here is meant to equal the set of bulletins whose fan-out
> notified them. A future change to the audience rule must touch both.

> **Full history vs. inbox.** This endpoint reads the `boletin` nodes directly,
> so it can show bulletins published **before** the user existed, which the
> `/notifications` inbox does not have. This divergence is intentional and is the
> reason the endpoint exists (it also exposes `field_adjunto`, which the inbox
> does not). Read/unread state lives in `/notifications`, not here.

**Date-range filter (`date_from` / `date_to`)**

Both bounds are optional and independent: you may send only `date_from`, only
`date_to`, both, or neither. They filter on `created_at` inclusively on both
ends.

- Because `node.created` is an integer Unix timestamp, the lower bound is
  `strtotime(date_from 00:00:00)` and the upper bound `strtotime(date_to
  23:59:59)` in the site process timezone, so a bulletin created at any time on
  `date_to` is **included**.
- The filter is applied **before** pagination and sorting, so `page`, `limit`
  and `sort` operate over the already-filtered set, and so do `total` /
  `total_pages`.
- Invalid values (bad format or non-calendar dates) are ignored per bound, and
  an inverted range (`date_from > date_to`) drops the whole filter, so the
  endpoint responds exactly as if no range had been sent. No `422` is raised for
  either case — this mirrors the lax handling of `page`/`limit`/`sort`.
- The filter uses the PHP process timezone via `strtotime()`; a mismatch with
  the site timezone could shift the bound by a few hours. It is a convenience
  filter, not an accounting boundary.

Example: `GET /api/v1/bulletins?date_from=2026-07-01&date_to=2026-07-31` returns
only bulletins created within July 2026 inclusive.

**Condominium filter (`condominium_id`)**

Optional query param that scopes the list to a single condominium `C` (its
`nid`). It does **not** change the route, the audience rule, or the pagination
contract — it narrows the reader's condominium sets before the query runs.

- **Absent** (unset or empty string) — no filter; the endpoint responds exactly
  as documented above (spec-29 behaviour, no regression).
- **Membership gate.** The user must belong to `C` (own or occupy at least one
  unit in it). A value the user does not belong to — whether a **foreign**
  condominium or a **non-existent** one — returns `403 condominium_access_denied`
  (both are treated the same, so the endpoint never reveals whether a
  condominium exists). No extra existence query is run.
- **What the filtered list contains** when `C` is valid and the user belongs:
  - **`General`** — **all** the General bulletins the user would see without the
    filter. General bulletins have no condominium, so they are never trimmed.
  - **`Condominio`** — only those with `field_condominio = C`, and only when the
    user's role in `C` matches `send_to` (`Propietarios` → owner of `C`,
    `Ocupantes` → occupant of `C`, `Todos` → either). No `Condominio` bulletin of
    any other condominium appears.
  - **`Personalizado`** — **all** the user's Personalizado bulletins, unchanged;
    they target the person, not the condominium, and are not trimmed by `C`.
- **Malformed value** (`abc`, `0`, `-3`, `1.5`, etc. — not a positive integer) →
  `422 invalid_field` with `@field = condominium_id`. Unlike `page`/`date_*`,
  this is not ignored silently: the param is a gated filter, so a bad value is a
  client error.
- **Combines** with `date_from`/`date_to`, `page`, `limit` and `sort` with no
  special casing; `total` / `total_pages` reflect the set already narrowed to
  `C`.
- The filter is applied by narrowing the reader's condominium sets (`owner` /
  `occupant` / `member`) to `{C}`; the audience condition itself
  (`myapi_bulletin_visibility_condition()`) is untouched, preserving parity with
  the fan-out (spec 25).

Example: `GET /api/v1/bulletins?condominium_id=1234` returns the General and
Personalizado bulletins visible to the user, plus the `Condominio` bulletins of
condominium `1234` matching the user's role there.

**Data model assumptions**

This endpoint reads directly from Drupal 7's Field API storage tables instead of
going through the Field API, for query simplicity and to paginate/count in SQL. A
future schema change to any of the fields below (rename, single→multi-value,
bundle move, type change) will silently break this endpoint without a Drupal
update warning. The `n.type = 'boletin'` condition and the per-join `entity_id`
binding keep the query scoped to bulletin nodes only.

| Drupal field | JSON key | Type | `NULL` rule |
|---|---|---|---|
| `nid` | `id` | int | never `NULL` |
| `title` | `title` | string | never `NULL` |
| `field_mensaje_value` | `message` | string (raw HTML) | `NULL` if no row |
| `field_tipo_de_boletin_value` | `type` | string | never `NULL` (inner join) |
| `field_enviar_a_value` | `send_to` | string | `NULL` if no row |
| `field_condominio_target_id` | `condominium_id` | int | `NULL` except on `Condominio` bulletins |
| `field_adjunto_fid` | `file_id` | int | `NULL` if no attachment |
| `file_managed.uri` (via `file_create_url()`) | `file_url` | string | `NULL` if no attachment or file unresolved |
| `file_managed.filemime` | `file_mime` | string | `NULL` if no attachment or file unresolved |
| `node.created` | `created_at` | int (unix ts) | never `NULL` |

| Table | Relevant columns | Use |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status`, `created` | Published `boletin` nodes. `created` is the sort and date-filter column. |
| `field_data_field_tipo_de_boletin` | `entity_id`, `field_tipo_de_boletin_value` | `type`. Inner join; audience axis. |
| `field_data_field_enviar_a` | `entity_id`, `field_enviar_a_value` | `send_to`. Left join; part of the audience condition. |
| `field_data_field_condominio` | `entity_id`, `field_condominio_target_id` | `condominium_id`. Left join; used by the `Condominio` branch. |
| `field_data_field_personalizar` | `entity_id`, `field_personalizar_target_id` | Reader membership in the `Personalizado` branch (owner role). `EXISTS` only, not exposed. |
| `field_data_field_ocupantes` | `entity_id`, `field_ocupantes_target_id` | Reader membership in the `Personalizado` branch (occupant role). `EXISTS` only, not exposed. |
| `field_data_field_mensaje` | `entity_id`, `field_mensaje_value` | `message`, raw HTML. Left join. |
| `field_data_field_adjunto` | `entity_id`, `field_adjunto_fid` | `file_id`, managed-file reference. Left join; the `fid` is exposed and used to bulk-load the managed file. |
| `file_managed` | `fid`, `uri`, `filemime` | Bulk-loaded once per page via `file_load_multiple()` to resolve `file_url` (public `file_create_url()`) and `file_mime`. |

The reader's role sets are resolved with the helpers in
`includes/myapi.unit_access.inc`: `myapi_user_owned_unit_nids()`,
`myapi_user_occupied_unit_nids()` and `myapi_units_condominium_nids()`. Each
audience sub-condition is added to the query only when its set/flag is non-empty,
so a reader with no units of a given role never produces an invalid `IN ()`.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, or the associated user does not exist or is blocked (`status = 0`). |
| 405  | `method_not_allowed` | Any HTTP method other than `GET`. |
| 422  | `invalid_field` | `condominium_id` is present but not a positive integer (`@field = condominium_id`). |
| 403  | `condominium_access_denied` | `condominium_id` is a valid positive integer but the user does not belong to that condominium (foreign or non-existent; the two are not distinguished). |

`401` (missing/invalid token) and `405` (wrong method) are evaluated **before**
the `condominium_id` gate — authentication first, then the filter's `422`/`403`.

Error envelope:
```json
{
  "success": false,
  "error_code": "invalid_token",
  "error": "El token de acceso no es válido."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Example:**
```bash
curl -i -X GET 'https://host/api/v1/bulletins?page=1&limit=20&sort=desc' \
  -H 'Authorization: Bearer <access_token>'
```

Scoped to a single condominium:
```bash
curl -i -X GET 'https://host/api/v1/bulletins?condominium_id=1234' \
  -H 'Authorization: Bearer <access_token>'
```
