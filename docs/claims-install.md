# Claims — content types installation

This module creates, on install and on update, the two content types the
claims-and-suggestions feature (SPEC 55) is built on — **Reclamo** (`reclamo`)
and **Transacción de reclamo** (`claim_transaction`) — together with every
Field API field and instance they need. Everything is created
programmatically (`node_type_save()`, `field_create_field()`,
`field_create_instance()`); nothing is built by hand in the admin UI.

There are **no custom SQL tables** for this feature (unlike `my_api_tokens`).
The bundles, the shared fields and the per-bundle instances are Field API
configuration entities; Drupal generates the `field_data_*` / `field_revision_*`
tables automatically.

**No `api/v1/...` endpoint is created by this spec.** Creating a reclamo from
the app, listing them, viewing the detail or the status tracking are all out of
scope — this spec only creates the structure. Listing, filtering, viewing
detail and attachments is done with Drupal's native screens
(`node/add/reclamo`, `/node/N`, `/admin/content`), which already inherit the
building-admin role's condominium filter once its map entry exists (see
[Enganche al rol `administrador edificio`](#enganche-al-rol-administrador-edificio)
below).

> **Dependencies.** No new dependency is declared in `myapi.info`.
> `entityreference`, `date`, `image`, `file`, `list`, `text` and `number` are
> already covered by core or by SPEC 32 (reservations).

---

## Content types

### Reclamo (`reclamo`)

A claim or requirement filed by a resident. Machine name reserved by SPEC
49/51 in `MYAPI_BUILDING_ADMIN_CLAIM_TYPE` — this spec respects it rather than
defining a new one.

- `base` = `node_content`, native title used as the subject
  (`title_label` = «Asunto»).
- Published by default, not promoted, not sticky (`node_options_reclamo = ['status']`).
- Comments hidden (`comment_reclamo = COMMENT_NODE_HIDDEN`).
- Creation date is the node's native `created` field — no custom field.

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `field_condominium` | entityreference → node | Yes | — | Bundle `condominio`. **Same shared field** as `area`/`reservation` (SPEC 32) — a new instance, not a new field. |
| `field_requester` | entityreference → user | Yes | — | Same shared field as `reservation` (SPEC 32). |
| `field_description` | text_long | Yes | — | `default_value[0]['format'] = 'plain_text'`, pinned on purpose — see [Why `plain_text`](#why-plain_text-and-not-full_html) below. |
| `field_status` | list_text | Yes | `received` | Shared with `claim_transaction`; see [the status catalogue](#field_status-catalogue) below. |
| `field_claim_type` | list_text | Yes | — (no default) | `requirement\|Requerimiento`, `claim\|Reclamo`. |
| `field_reception_date` | datetime (Date) | Yes | — | Day granularity only (`Y-m-d`), `tz_handling = none`. |
| `field_visibility` | list_text | Yes | `private` | `private\|Privado`, `public\|Público`. |
| `field_images` | image | No | — | Shared with `claim_transaction` (see below). Cardinality `-1`. |
| `field_attachment` | file | No | — | Shared with `claim_transaction` (see below). Cardinality 1. |

### Transacción de reclamo (`claim_transaction`)

A timeline entry of a reclamo: a status change with an optional admin comment.
Its machine name is new — no prior spec reserved it.

- `base` = `node_content`. Native title kept with default behaviour
  (`title_label` = «Título»); irrelevant to the API, no auto-generation, same
  criterion as **Reserva** in SPEC 32.
- Published by default, not promoted, not sticky
  (`node_options_claim_transaction = ['status']`).
- Comments hidden (`comment_claim_transaction = COMMENT_NODE_HIDDEN`).
- Creation date is the node's native `created` field; the author is the node's
  native `uid` — no custom "created by" field.

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `field_status` | list_text | Yes | — (no default) | **Same field** as the `reclamo` instance, different default — see below. |
| `field_status_date` | datetime (Date) | Yes | — | Same granularity as `field_reception_date`. |
| `field_comment` | text_long | No | — | `default_value[0]['format'] = 'plain_text'`. |
| `field_claim` | entityreference → node | Yes | — | Bundle `reclamo`. Cardinality 1. |
| `field_images` | image | No | — | Shared with `reclamo` (see below). |
| `field_attachment` | file | No | — | Shared with `reclamo` (see below). |

---

## `field_status` catalogue

One `list_text` field, shared by both bundles, because their allowed values
are **identical** — unlike `field_area_status` / `field_reservation_status` in
SPEC 32, which needed two separate fields because their catalogues differed.

| Key | Label |
|---|---|
| `received` | Recibido |
| `in_progress` | En proceso |
| `resolved` | Resuelto |
| `closed` | Cerrado |
| `duplicated` | Duplicado |

The two **instances** of this field differ only in `default_value`:

| Bundle | Default |
|---|---|
| `reclamo` | `received` — a new claim starts in this state. |
| `claim_transaction` | None — a transaction always records a status **deliberately chosen**, never an implicit default. |

Field API allows two instances of the same field to carry different
`default_value`s without any special handling; only `allowed_values` is the
setting that must be shared.

If the two bundles ever need to diverge (one needing a status value the other
must not offer), the field must be split retroactively into two, with a data
migration — accepted as a risk in the spec, not mitigated preemptively.

## `field_claim_type` and `field_visibility` catalogues

| Field | Bundle | Values |
|---|---|---|
| `field_claim_type` | `reclamo` | `requirement` → Requerimiento · `claim` → Reclamo |
| `field_visibility` | `reclamo` | `private` → Privado · `public` → Público |

## Why `plain_text` and not `full_html`

`field_description` and `field_comment` pin
`default_value[0]['format'] = 'plain_text'`. This is the opposite fix of
`field_area_notes` (SPEC 32/`myapi_update_7013()`), which had to **remove** its
pinned `full_html` because that format disabled the textarea for any user
without the `full_html` text-format permission — the building-admin role
among them. `plain_text` carries no such restriction: it is available to every
user, so pinning it causes none of that problem and needs no repair update.

## `field_images` / `field_attachment` — shared between both bundles

Both bundles need exactly the same upload rule (several images plus one
document, same size limit, same extensions), so each is **one field, two
instances** rather than a pair of near-identical fields per bundle — the same
reasoning SPEC 32 used for `field_condominium`.

| Field | Type | Cardinality | Instance settings (identical on both bundles) |
|---|---|---|---|
| `field_images` | image | Unlimited (`-1`) | `file_extensions = 'png jpg jpeg'`, `max_filesize = '3 MB'`, alt text enabled but not required (`alt_field = 1`, `alt_field_required = 0`) — distinct from `field_image` on `area` (SPEC 32), which disables alt entirely. |
| `field_attachment` | file | 1 | `file_extensions = 'pdf doc docx xls xlsx'`, `max_filesize = '3 MB'`. |

Neither instance is required. A file outside the allowed extensions or over
3 MB is rejected by Drupal's native Field API validation on the node form —
no custom validation code is added by this spec.

## `field_claim` — the 1:N link to the timeline

`claim_transaction.field_claim` is an `entityreference` to `node`, cardinality
1, restricted to the `reclamo` bundle. As with every other entityreference
field in this module, `target_type`, `handler` and
`handler_settings.target_bundles` are **field-level** settings in Drupal 7 —
the selection handler reads them off the field, never the instance — so they
come from the shared catalogue,
`_myapi_entityreference_field_settings()` in `myapi.install`:

```php
'field_claim' => [
  'target_type'      => 'node',
  'handler'          => 'base',
  'handler_settings' => [
    'target_bundles' => [MYAPI_BUILDING_ADMIN_CLAIM_TYPE => MYAPI_BUILDING_ADMIN_CLAIM_TYPE],
    'sort'           => ['type' => 'none'],
  ],
],
```

See `docs/reservations-install.md` for the full history of this rule (SPEC
53's bug and repair, `myapi_update_7016()`); this field was registered in the
catalogue from the day it was created, so it never needed that repair.

---

## Idempotency

Creation is driven by a single private helper, `_myapi_claims_install()`,
reusing the exact same idempotent sub-helpers SPEC 32 built — **no new
sub-helper is added**:

- `_myapi_reservations_ensure_node_type()` — skips if `node_type_load()` already
  returns the bundle.
- `_myapi_reservations_ensure_field()` — skips if `field_info_field()` already
  returns the field.
- `_myapi_reservations_ensure_instance()` — skips if `field_info_instance()`
  already returns the instance.

Each check reads the **live** definition, so re-running the helper (a
disable/enable cycle, or re-running the update) never duplicates a content
type, field or instance and never throws a `FieldException`.

---

## How it is applied

- **Fresh sites.** `hook_install()` (`myapi_install()`) calls
  `_myapi_claims_install()` right after `_myapi_reservations_install()` and
  before `_myapi_building_admin_install()` — the bundles must exist before
  their `create`/`edit any` permissions can be found in
  `module_invoke_all('permission')` and granted to the building-admin role. So
  `drush en myapi` creates both claims content types with all their fields in
  the same step as everything else.
- **Already-installed sites (production).** `drush en` does not re-run
  `hook_install()`, so the same helper is exposed through the update hook
  `myapi_update_7017()`:

  ```bash
  drush updb    # runs myapi_update_7017 → creates reclamo + claim_transaction
  drush cc all
  ```

  The update touches no existing table, bundle or field
  (`my_api_tokens`, `myapi_notifications`, `area`/`reservation`, etc.).

Both paths call the exact same helper — it is the single source of truth for
the claims schema.

### Why `myapi_update_7017` and not `myapi_update_7014`

`myapi_update_7014` is the number two existing comments in `myapi.install`
(above `myapi_requirements()` and above `myapi_update_7015()`) point at as
"reserved by SPEC 51" — but that function was never written. It is a
preexisting gap this spec documents and does not fill: reusing 7014 would
paper over a bug this spec did not create and cannot fully diagnose. `7015`
and `7016` are already taken, so `7017` is the next number genuinely free.

---

## Enganche al rol `administrador edificio`

The last step of `_myapi_claims_install()`'s companion change adds one entry
to `myapi_building_admin_condominium_map()` in
`includes/myapi.building_admin.inc`:

```php
MYAPI_BUILDING_ADMIN_CLAIM_TYPE => ['mode' => 'direct', 'field' => 'field_condominium'],
```

`create reclamo content` and `edit any reclamo content` were already granted
to the role conditionally since SPEC 49 — `myapi_building_admin_editable_types()`
includes `reclamo` the moment `node_type_load()` finds the bundle. Adding this
map entry is what turns that permission into a **safe, condominium-scoped**
one: without it, a building admin with the permission but no map entry could
see and edit every reclamo of every condominium (the permission with no filter
attached). This entry must be applied **after** the bundle and
`field_condominium` exist on it (i.e. after `_myapi_claims_install()` has run),
otherwise `hook_node_access()` would try to resolve a field the type does not
carry yet.

**`claim_transaction` was excluded here on purpose** — from this map and from
`myapi_building_admin_editable_types()`, at the time this install code was
written. Its condominium is not resolvable on the node itself: it needs
hopping `field_claim` → `reclamo` → `field_condominium`, a two-field
indirection none of the map's three modes of the time (`self` / `direct` /
`via_unit`) supported. Granting the role permissions over it without that
resolution would have exposed the status-change history of every
condominium's claims to any building admin — a real risk, not a cosmetic one.

**SPEC 56 closed that gap**: a fourth mode, `via_claim`, does exactly that
hop, and `claim_transaction` now has a map entry and is part of
`myapi_building_admin_editable_types()`. See `docs/building-admin-role.md` and
`docs/claims-list.md` for the resolution itself and its permissions.

---

## Uninstall policy (conservative)

Uninstalling the module is **non-destructive by default**: `drush pm-uninstall
myapi` does **not** remove the claims content types, their fields or any
reclamo/claim_transaction node — that data belongs to the client.

The destructive path exists but is opt-in, guarded by its own constant,
independent from the reservations one:

```php
define('MYAPI_CLAIMS_DESTRUCTIVE_UNINSTALL', FALSE);
```

Only when this is flipped to `TRUE` does `myapi_uninstall()` call
`_myapi_claims_uninstall_destructive()`, which deletes the 10 fields this spec
created (which also deletes their instances and stored values) and both
content types. Leave it `FALSE` in production; an accidental uninstall would
otherwise wipe real reclamos and their transaction history.

`field_condominium` and `field_requester` are **deliberately not** in that
deletion list, even though `reclamo` has instances of them: those fields
belong to SPEC 32 and are still used by `area`/`reservation` regardless of
this flag. Deleting them here would wipe reservations data on a site that only
opted into the claims teardown. `node_type_delete()` already removes the
`reclamo` instances of those fields via `field_attach_delete_bundle()` without
touching the fields themselves — the reservations flag is what controls
whether the fields disappear entirely.
