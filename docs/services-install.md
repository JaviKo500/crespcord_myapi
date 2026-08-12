# Services marketplace — content types installation

This module creates, on install and on update, the taxonomy vocabulary and the
five content types the services marketplace (SPEC 77) is built on —
**Categoría de servicio** (`service_category`), **Proveedor** (`provider`),
**Solicitud de servicio** (`service_request`), **Oferta** (`service_offer`),
**Calificación de servicio** (`service_rating`) and **Transacción de
solicitud** (`service_transaction`) — together with every Field API field and
instance they need. Everything is created programmatically
(`taxonomy_vocabulary_save()`, `node_type_save()`, `field_create_field()`,
`field_create_instance()`); nothing is built by hand in the admin UI.

There are **no custom SQL tables** for this feature. The vocabulary, the
bundles, the fields and the instances are configuration entities; Drupal
generates the `field_data_*` / `field_revision_*` tables automatically.

**No `api/v1/...` endpoint, no permission and no business logic is created by
this spec.** Listing categories, creating a request from the app, offering,
opening a chat, closing and rating are all out of scope — SPEC 77 only creates
the structure. SPEC 78 (`docs/provider-role.md`) added the `proveedor` role
and closed the Drupal back office to it, but that role still authorizes
nothing on `api/v1/...` — today only `administrator` (and `backend`, through
its site-wide permissions) reaches these content types, through Drupal's
native screens.

> **Dependencies.** No new dependency is declared in `myapi.info`.
> `entityreference`, `date`, `taxonomy`, `image`, `file`, `list`, `text` and
> `number` are already covered by core or by SPEC 32.

---

## The vocabulary

### Categoría de servicio (`service_category`)

Flat (`hierarchy = 0`): the marketplace has categories, not sub-categories.
Created empty — the five terms (Limpieza, Mantenimiento, Arreglos, Compras,
Servicios Generales) are loaded by the operator, not by the installer.

| Field | Type | Required | Notes |
|---|---|:---:|---|
| `field_category_code` | text (32) | Yes | The stable identifier that travels in the API. A `tid` changes if the vocabulary is re-imported; this code does not. Uppercase, no spaces — `CLEANING`, `MAINTENANCE`. |
| `field_category_icon` | image | No | `png jpg jpeg`, 1 MB. **`uri_scheme = 'public'`** — see [Which files are private](#which-files-are-private). |

Uniqueness of `field_category_code` is **not** enforced yet: it needs a
`hook_form_alter()` validation, which is module code and belongs to the spec
that first reads the code from an endpoint.

---

## The content types

All five are `node_content`, published by default, not promoted, not sticky,
comments hidden. None of them auto-generates its title yet — the operator types
it, exactly as `reservation` and `claim_transaction` did before their own specs
arrived.

### Proveedor (`provider`)

Native title = «Nombre comercial».

| Field | Type | Card. | Required | Notes |
|---|---|:---:|:---:|---|
| `field_provider_users` | entityreference → user | ∞ | Yes | The accounts that operate this provider from the app. No `target_bundles`: the user entity has a single bundle. |
| `field_phone` | text (20) | 1 | Yes | |
| `field_address` | text_long | 1 | No | `plain_text` pinned. |
| `field_services_desc` | text_long | 1 | Yes | `plain_text` pinned. |
| `field_photo` | image | 1 | No | `png jpg jpeg`, 3 MB, **public**. |
| `field_license_expiry` | datestamp | 1 | Yes | Down to the minute — see [Why the licence expiry has minutes](#why-the-licence-expiry-has-minutes). |
| `field_categories` | taxonomy_term_reference → `service_category` | ∞ | Yes | Checkboxes (`options_buttons`). |
| `field_rating_avg` | number_decimal (3,2) | 1 | No | Denormalised. **Nothing writes it in SPEC 77.** |
| `field_rating_count` | number_integer | 1 | No | Denormalised. Idem. |

The two counters exist so the "providers of a category" listing does not run an
`AVG()` per row. The hooks that recalculate them ship with the rating flow;
until then both are simply empty.

### Solicitud de servicio (`service_request`)

| Field | Type | Card. | Required | Notes |
|---|---|:---:|:---:|---|
| `field_requester` | entityreference → user | 1 | Yes | **Shared** with `reservation`/`reclamo` (SPEC 32). The resident who owns the request and the recipient of its notifications. |
| `field_condominium` | entityreference → `condominio` | 1 | Yes | **Shared** (SPEC 32). |
| `field_category` | taxonomy_term_reference → `service_category` | 1 | Yes | |
| `field_description` | text_long | 1 | Yes | **Shared** with `reclamo` (SPEC 55). |
| `field_desired_start` | datestamp | 1 | Yes | |
| `field_images` | image | ∞ | No | **Shared** (SPEC 55/65) → **`private://`**. |
| `field_attachment` | file | 1 | No | **Shared** (SPEC 55/65) → **`private://`**. |
| `field_request_status` | list_text | 1 | Yes | Default `open`. |
| `field_assigned_offer` | entityreference → `service_offer` | 1 | No | |
| `field_assigned_provider` | entityreference → `provider` | 1 | No | Denormalised from the awarded offer. |
| `field_closed_at` | datestamp | 1 | No | |

`field_requester` does not duplicate the node's native `uid`: the `uid` is
whoever saved the node (the backend, possibly) and `field_requester` is the
resident the request belongs to. Same distinction `reservation` has made since
SPEC 32.

### Oferta (`service_offer`)

| Field | Type | Card. | Required | Notes |
|---|---|:---:|:---:|---|
| `field_request` | entityreference → `service_request` | 1 | Yes | **Shared with `service_transaction`.** |
| `field_provider` | entityreference → `provider` | 1 | Yes | |
| `field_offer_message` | text_long | 1 | Yes | `plain_text` pinned. |
| `field_offer_amount` | number_decimal (10,2) | 1 | No | Dollars. An offer with no amount is valid — the price can be settled in the chat. |
| `field_offer_status` | list_text | 1 | Yes | Default `sent`. |
| `field_firebase_path` | text (255) | 1 | No | Reserved for the chat. Empty = no thread yet. |
| `field_chat_opened_at` | datestamp | 1 | No | Reserved for the chat. |
| `field_last_message_at` | datestamp | 1 | No | Reserved for the chat. |

The **account** that offered is the node's native `uid`; `field_provider` is
the provider node it belongs to. Two different things, because one provider can
have several accounts.

### Calificación de servicio (`service_rating`)

| Field | Type | Card. | Required | Notes |
|---|---|:---:|:---:|---|
| `field_rating_offer` | entityreference → `service_offer` | 1 | Yes | The rating hangs off the **offer**, never the request. |
| `field_rating_provider` | entityreference → `provider` | 1 | Yes | Denormalised from the offer. |
| `field_stars` | list_integer | 1 | Yes | `1` to `5`. No zero, no half stars. |
| `field_rating_comment` | text_long | 1 | No | `plain_text` pinned. |

Pointing at the offer makes a rating of a provider who never offered on that
request **unrepresentable** rather than merely forbidden: the offer is the only
thing that joins a request to a provider.

### Transacción de solicitud (`service_transaction`)

A replica of `claim_transaction` (SPEC 55): one entry per status change.

| Field | Type | Card. | Required | Notes |
|---|---|:---:|:---:|---|
| `field_request` | entityreference → `service_request` | 1 | Yes | Same field as on `service_offer`. |
| `field_request_status` | list_text | 1 | Yes | Same field as on `service_request`, **with no default** here: a transaction always records a deliberately chosen status. |
| `field_status_date` | datetime | 1 | Yes | **Shared with `claim_transaction`.** The one date of this feature that is not a `datestamp` — see below. |
| `field_comment` | text_long | 1 | No | **Shared with `claim_transaction`.** |

---

## Status catalogues

Both live in `includes/myapi.services_common.inc` and the installer reads them
from there, so the `allowed_values` of the fields and the rules of the flow
cannot drift apart. A unit test fails if the installer ever retypes the list.

### `field_request_status`

```
open|Abierta
offered|Con ofertas
assigned|Asignada
closed|Cerrada
cancelled|Cancelada
```

The transition graph, `myapi_services_request_transitions()`:

```
open ──(first offer)──> offered ──(award)──> assigned
  │                        │                    │
  │                        └──────> closed <────┘
  └──────────> cancelled <─────────┴────────────┘
```

- `closed` and `cancelled` are **terminal**. Nothing reopens a request:
  reopening would need its own rules for the offers and the chat of the closed
  round.
- Closing **from `assigned` requires a rating**
  (`myapi_services_close_requires_rating()`); closing from `offered` is the
  contract's "no award" path and there is nobody to score.

**Nothing enforces any of this yet.** The graph is written and unit-tested so
the flow spec reads it instead of restating it in prose.

### `field_offer_status`

```
sent|Enviada
selected|Seleccionada
rejected|Rechazada
withdrawn|Retirada
```

A separate field from the request's status, not a shared one with a wider
catalogue: the two lists have no value in common, and sharing would offer
«Abierta» in the offer form. Same criterion SPEC 32 used to keep
`field_area_status` and `field_reservation_status` apart.

---

## Who is an active provider

```
node.status = 1  AND  field_license_expiry >= REQUEST_TIME
```

The rule lives in one pure function, `myapi_services_provider_is_active()`,
because three different places will ask it: broadcasting a request, creating an
offer, and listing a category. The two halves cover different cases on purpose
— unpublishing suspends a provider by hand, the expiry suspends it by itself —
and both must hold. An empty expiry reads as "no licence on record" and answers
inactive.

### Why the licence expiry has minutes

With day-only granularity the stored timestamp would be 00:00 of the expiry
day, so a licence "expiring on the 31st" would in fact die as the 30th ended.
An off-by-one day nobody would suspect until a provider was locked out. The
field is therefore down to the minute, and the operator sets `23:59` to mean
the whole day.

---

## Which files are private

| Field | Scheme | Why |
|---|---|---|
| `field_images`, `field_attachment` (request) | `private://` | They may show the inside of a home. Inherited from the field, which SPEC 65 made private for claims — this feature adds an instance and changes nothing. |
| `field_photo` (provider) | `public://` | The provider's shop front, identical for every user of the marketplace. |
| `field_category_icon` (category) | `public://` | A catalogue asset shown in the app's category grid. |

Making the two public ones private would mean an authenticated download
endpoint per thumbnail of the grid, for images that reveal nothing.

---

## Shared fields — read this before editing the installer

Seven fields are **borrowed**, not created. Each gets a new instance here and
the field itself is untouched:

| Field | Owner | Used on |
|---|---|---|
| `field_requester`, `field_condominium` | reservations (SPEC 32) | `service_request` |
| `field_description`, `field_images`, `field_attachment` | claims (SPEC 55/65) | `service_request` |
| `field_status_date`, `field_comment` | claims (SPEC 55) | `service_transaction` |

Two consequences worth knowing:

1. **The field-level settings are shared.** `uri_scheme`, field type and
   cardinality are one per field, for every bundle that uses it. The
   *instance* settings — required, label, file extensions, max filesize — are
   per bundle and may diverge freely.
2. **Deleting one of them destroys another feature's data.**
   `field_delete_field('field_description')` takes the description of every
   reclamo with it. This is why `_myapi_services_uninstall_destructive()`
   splits its work in two — owned fields are deleted, borrowed ones only lose
   their instance — and why a unit test fails if a borrowed name ever appears
   in the `$owned` list.

---

## Dates: `datestamp`, with one exception

Every date field this feature creates is `datestamp` (a Unix timestamp), by
explicit decision: the app sends and receives instants and converts to local
time itself. That diverges from the `datetime` fields of SPEC 32 and 55, and
the divergence is deliberate.

The one exception is `field_status_date` on `service_transaction`, which stays
`datetime` because it is the claims field, shared and already in production.
Whoever writes the timeline endpoint will therefore format two different date
types in the same feature — stated here so it is not discovered while
debugging.

---

## Idempotency

`_myapi_services_install()` is the single source of truth for this schema and
can be re-run any number of times. Every creation goes through an idempotent
helper:

- `_myapi_services_ensure_vocabulary()` — the only new sub-helper of SPEC 77
  (vocabularies had no equivalent). It creates the vocabulary only when
  `taxonomy_vocabulary_machine_name_load()` answers FALSE, and never overwrites
  a name, description or hierarchy adjusted on the site.
- `_myapi_reservations_ensure_node_type()`, `_ensure_field()` and
  `_ensure_instance()` — reused unchanged from SPEC 32/49.

Order matters: the vocabulary first (the category fields name it), then the
five bundles (an entityreference field names a target bundle, which has to
exist), then the fields, then the instances.

---

## How it is applied

| Site | What runs |
|---|---|
| Fresh install | `myapi_install()` → `_myapi_services_install()`, after `_myapi_claims_install()` (which creates the borrowed fields and makes them private) and before `_myapi_building_admin_install()`. |
| Already installed | `drush updb` → `myapi_update_7025()` → the same `_myapi_services_install()`. |

`drush cc all` afterwards. The update creates **structure only**: no
permission, no role, no route and no node, so running it changes nothing any
user or the app can see.

---

## entityreference settings

The seven new reference fields are registered in
`_myapi_entityreference_field_settings()` (SPEC 53), which is where
`target_type`, `handler` and `handler_settings.target_bundles` belong — they
are **field**-level settings in D7 and the selection handler reads them off the
field, never off the instance.

Four of them point at only two bundles between them, and that is deliberate:
`field_provider` (who offered), `field_assigned_provider` (who was awarded) and
`field_rating_provider` (who was rated) are three different facts on three
different bundles. One shared field would put them in a single
`field_data_field_provider` table where no query could tell them apart.
`field_request` **is** shared between `service_offer` and `service_transaction`,
because there it really is the same fact.

---

## Uninstall policy (conservative)

`MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL` is `FALSE` and must stay `FALSE`.
`drush pm-uninstall myapi` therefore leaves the vocabulary, the five content
types, their fields and all their nodes exactly where they are — providers,
requests, offers and ratings are real client data.

Flipping it to TRUE runs `_myapi_services_uninstall_destructive()`, which
deletes this feature's own fields, removes the **instances** of the seven
borrowed ones, deletes the five node types and the vocabulary, and purges the
field data. Independent from the reservations and claims constants: flipping
one never drags another feature's data down with it.

---

## Known gaps, on purpose

Written down so nobody spends time looking for them:

- No endpoint, no permission. SPEC 78 created the `proveedor` role and closed
  the back office to it (`docs/provider-role.md`), but the role authorizes no
  `api/v1/...` endpoint by itself — that authorization is still a future spec.
- No entry in `myapi_building_admin_condominium_map()` for `service_request`.
  `field_condominium` exists so that spec is one line — but adding the entry
  without granting the permissions would do nothing.
- `service_transaction` resolves its condominium only through `field_request` →
  `service_request` → `field_condominium`, a two-hop mode the map does not have
  yet (it has `via_claim`, which shows the shape). Any spec granting the
  building-admin role permissions on that bundle must add the mode **first**,
  or the timeline of every building becomes visible to every building operator.
  Same warning SPEC 55 left about `claim_transaction`.
- Nothing validates transitions, offer uniqueness, or that only an active
  provider offers.
- Nothing fills the denormalised fields
  (`field_assigned_provider`, `field_rating_provider`, `field_rating_avg`,
  `field_rating_count`), and nothing hides them in the node form. Editing
  `field_firebase_path` by hand would break a chat silently — today no
  operational role reaches that form.
- No auto-generated titles.
- No chat: the three fields are reserved and empty; the transport is not
  decided.
