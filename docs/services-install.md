# Services marketplace — content types installation

This module creates, on install and on update, the taxonomy vocabularies and
the five content types the services marketplace (SPEC 77) is built on —
**Categoría de servicio** (`service_category`), **Proveedor** (`provider`),
**Solicitud de servicio** (`service_request`), **Oferta** (`service_offer`),
**Calificación de servicio** (`service_rating`) and **Transacción de
solicitud** (`service_transaction`) — together with every Field API field and
instance they need. Everything is created programmatically
(`taxonomy_vocabulary_save()`, `node_type_save()`, `field_create_field()`,
`field_create_instance()`); nothing is built by hand in the admin UI.

SPEC 81 extends the `provider` bundle with three more fields — an hourly rate,
free tags and a short description — and creates a second vocabulary,
**Etiqueta de proveedor** (`provider_tag`), for the tags. All three are
**optional**, so no provider already loaded on the site becomes invalid, and
none of them is read by any endpoint yet.

There are **no custom SQL tables** for this feature. The vocabularies, the
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

## The vocabularies

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

### Etiqueta de proveedor (`provider_tag`) — SPEC 81

Flat, born empty and with **no fields of its own** — deliberately, not by
oversight. The category code exists because the app hangs logic on it (icons,
screens); a tag is painted and nothing else, and demanding a code for each one
would cancel the whole point of the autocomplete.

Its terms are **created by the operator while typing**, in the «Etiquetas»
field of a provider — see [How the tags are born](#how-the-tags-are-born).

> **Keeping it clean is operational work, not code.** With free creation the
> vocabulary drifts on its own: «urgencias», «Urgencias 24h» and «emergencias»
> can end up meaning the same thing. Merging or renaming terms at
> `admin/structure/taxonomy/provider_tag` keeps every reference intact, and
> nothing in this module normalizes them.

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
| `field_license_expiry` | datestamp | 1 | Yes | Down to the minute — see [Why the licence expiry has minutes](#why-the-licence-expiry-has-minutes). |
| `field_categories` | taxonomy_term_reference → `service_category` | ∞ | Yes | Checkboxes (`options_buttons`). |
| `field_rating_avg` | number_decimal (3,2) | 1 | No | Denormalised. **Nothing writes it in SPEC 77.** |
| `field_rating_count` | number_integer | 1 | No | Denormalised. Idem. |
| `field_hourly_rate` | number_decimal (10,2) | 1 | No | SPEC 81. «Valor hora». Informative reference price, in the module's implicit currency. `min = 0`, `prefix = '$ '` — see [The hourly rate](#the-hourly-rate). |
| `field_tags` | taxonomy_term_reference → `provider_tag` | ∞ | No | SPEC 81. «Etiquetas». Autocomplete (`taxonomy_autocomplete`) that **creates the terms it does not find** — see [How the tags are born](#how-the-tags-are-born). |
| `field_short_description` | text (255) | 1 | No | SPEC 81. «Descripción corta». One line for the marketplace card. No text format selector. |
| `field_gallery` | image | **10** | No | SPEC 82. «Galería». `png jpg jpeg`, 3 MB, **`private://`** — see [Which files are private](#which-files-are-private) and [provider-gallery.md](provider-gallery.md). Replaces the deleted `field_photo`. |
| `field_logo` | image | 1 | No | SPEC 85. «Logo». `png jpg jpeg`, 2 MB, `200x200`–`1000x1000`, **`public://`** — see [The logo](#the-logo) and [Which files are private](#which-files-are-private). |

The two counters exist so the "providers of a category" listing does not run an
`AVG()` per row. The hooks that recalculate them ship with the rating flow;
until then both are simply empty.

The three fields of SPEC 81 are all **optional** and **nothing reads them
yet** — `/api/v1/service-categories` is the only endpoint that touches this
bundle, and it only counts rows. `field_short_description` **does not replace**
`field_services_desc`: the long one stays required and stays a textarea, one for
the detail screen and one for the listing card. Existing providers are born with
the short one empty; no update copies text into it, because an automatic 255-
character cut publishes half-sentences nobody wrote.

#### The gallery, and the photo that is gone

SPEC 82 gave `provider` a carousel of up to **ten private images** and **deleted
`field_photo` outright**, field, instance and data. There is no cover image any
more: if one is needed, it is the first image of the gallery.

Three properties of `field_gallery` belong to the **field**, not to the
instance, which is what makes them expensive to change later:

- **`uri_scheme = 'private'`.** Changing it afterwards needs
  `field_update_field()` **and** a `file_move()` of every uploaded image — the
  work `myapi_update_7023()` had to do for the claim fields in SPEC 65. Born
  private, there was nothing to migrate.
- **Cardinality 10.** Raising or lowering the cap is a `hook_update_N`, and
  lowering it on providers that are over it silently discards the extra deltas.
- **The order is the order of the deltas**, i.e. what the operator drags in the
  form. There is no weight field and no date ordering.

`myapi_update_7029()` deletes `field_photo` **unconditionally**: it does not
count the rows of `field_data_field_photo` and it does not abort if it finds
any. Confirm `SELECT COUNT(*) FROM field_data_field_photo` and have a database
and files backup **before** running `drush updb` on any environment where
photos might have been loaded — they are lost with no way back.

#### The logo

SPEC 85 gave `provider` a **public** logo: one image, optional, that travels as
the `logo` key of `GET /api/v1/providers` and `GET /api/v1/providers/{id}`.

It sits next to a private gallery on the same bundle, and that is not an
oversight to be tidied up — it is the criterion of
[Which files are private](#which-files-are-private) applied twice: **catalogue
and commercial identity go public; content uploaded for one record goes
private**. The logo is the same kind of asset as `field_category_icon`.

Two settings of the instance are **not symmetrical**, and reading them as two
equivalent limits is the mistake worth avoiding:

| Setting | What it does |
|---|---|
| `min_resolution = 200x200` | **REJECTS.** An image below it cannot be saved and the form says the minimum. |
| `max_resolution = 1000x1000` | **RESIZES, silently.** A `2000×1500` is accepted and stored as `1000×750`, with no error and no warning. |

And `max_filesize = 2 MB` **is validated BEFORE the resize**, so the resize never
rescues a heavy file: a `4000×4000` PNG of 5 MB is rejected **by weight** and
never gets reduced. The operator has to export it light already — the automatic
resize saves pixels, not upload bytes. Both behaviours are written in the field's
description, which is the only text the operator reads while uploading.

Two more things that are so by express decision:

- **The aspect ratio is not validated.** Drupal 7 has no native ratio check, and
  the two resolutions are independent caps, so a `1000×300` passes both. The
  «square» recommendation lives in the description, and the app paints the logo
  with `BoxFit.contain`.
- **`uri_scheme = 'public'` and cardinality 1 belong to the FIELD.** Same as with
  the gallery, changing the scheme later needs `field_update_field()` **and** a
  `file_move()` of every logo — the work `myapi_update_7023()` had to do in
  SPEC 65.

`myapi_update_7031()` creates the field **empty for every provider already on
the site**: there is no backfill, nothing gains an image and no node is touched.
A provider with no logo answers `logo: null`, and the app decides its
placeholder.

Nothing writes the logo over the API: it is uploaded, replaced and removed by
the operator from the back office, like the gallery and like everything else in
the marketplace.

#### The hourly rate

Two things about it that are easy to get wrong:

- **`min = 0` is not an SQL constraint.** It is an `#element_validate` the
  `number` widget adds, so saving `-15` from the back-office form fails
  validation — but a programmatic `node_save()` can still write a negative. The
  spec that first exposes a write endpoint for providers has to repeat the
  check.
- **`prefix = '$ '` never leaves the back office.** It paints in the form and in
  the node view; the column `field_hourly_rate_value` and any future API
  response hold the bare number (`25.50`). Whoever consumes it adds the symbol.

The size is `10,2` — the same as `field_offer_amount`, up to `99999999.99` —
and not the `3,2` of `field_rating_avg`, which is dimensioned for a 1-5
average. Decimal and not float because this is money.

#### How the tags are born

The D7 `taxonomy_autocomplete` widget creates unknown terms **natively**, with
no setting at all: a name it does not find enters validation as
`['tid' => 'autocreate', ...]` and `taxonomy_field_presave()` saves it as a real
term before writing the field row. (`'auto_create' => TRUE` belongs to
`entityreference`, not to taxonomy — it is not written anywhere here on
purpose.)

Two consequences for whoever fills the form:

- **Matching is exact and case-insensitive.** Typing «Urgencias» when
  «urgencias» exists reuses the existing term; typing «urgencias 24h» creates a
  new one even though «urgencias» exists.
- **A comma inside a name splits it in two tags.** The comma is the widget's
  separator, so «lunes, martes y feriados» becomes three tags. A tag that really
  contains a comma has to be typed in double quotes: `"lunes, martes"`.

There is no cap on how many tags a provider can carry: a maximum in the field
becomes a `hook_update_N` the day somebody needs one more, so if one is ever
needed it goes in the form or the endpoint that decides it.

### Solicitud de servicio (`service_request`)

| Field | Type | Card. | Required | Notes |
|---|---|:---:|:---:|---|
| `field_requester` | entityreference → user | 1 | Yes | **Shared** with `reservation`/`reclamo` (SPEC 32). The resident who owns the request and the recipient of its notifications. |
| `field_condominium` | entityreference → `condominio` | 1 | Yes | **Shared** (SPEC 32). |
| `field_unit` | entityreference → `vivienda` | 1 | Yes | **Shared** with `reservation` (SPEC 32) and `service_rating` (SPEC 84). The unit the service is for. Added by SPEC 86. |
| `field_category` | taxonomy_term_reference → `service_category` | 1 | Yes | |
| `field_description` | text_long | 1 | Yes | **Shared** with `reclamo` (SPEC 55). |
| `field_desired_start` | datestamp | 1 | Yes | |
| `field_images` | image | ∞ | No | **Shared** (SPEC 55/65) → **`private://`**. |
| `field_attachment` | file | 1 | No | **Shared** (SPEC 55/65) → **`private://`**. |
| `field_request_status` | list_text | 1 | Yes | Default `open`. A request born with a provider already chosen is saved as `direct` instead (SPEC 87). |
| `field_assigned_offer` | entityreference → `service_offer` | 1 | No | |
| `field_assigned_provider` | entityreference → `provider` | 1 | No | Denormalised from the awarded offer, or the provider chosen directly when the status is `direct`. |
| `field_closed_at` | datestamp | 1 | No | |

`field_requester` does not duplicate the node's native `uid`: the `uid` is
whoever saved the node (the backend, possibly) and `field_requester` is the
resident the request belongs to. Same distinction `reservation` has made since
SPEC 32.

`field_unit` is **required here and optional on `service_rating`**, and that is
deliberate. `required` is an *instance* setting, so the three instances of this
one field diverge freely: `reservation` required, `service_rating` optional
(SPEC 84 was added to a bundle that could already hold nodes), `service_request`
required (SPEC 86 — a service goes to one specific unit, and the bundle had no
stored node to invalidate). Nothing writes it yet: the endpoint that creates a
request does not exist. Whoever writes it also has to decide something Drupal
does not validate — that the chosen `vivienda` belongs to the
`field_condominium` of the same request. `myapi_units_condominium_nids()`
(`includes/myapi.unit_access.inc`) resolves it.

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
| `field_offer_amount_type` | list_text | 1 | **No** | SPEC 100. How the amount is to be read — see [`field_offer_amount_type`](#field_offer_amount_type). `on_site_quote` is the one value that carries **no** amount. |
| `field_offer_valid_until` | datestamp | 1 | **No** | SPEC 100. **Informative: no process expires an offer by this date.** |
| `field_offer_available_from` | datestamp | 1 | **No** | SPEC 100. When the provider could start. |
| `field_offer_duration` | number_integer | 1 | **No** | SPEC 100. Meaningless without its unit; the endpoint takes the two or neither. `min` is **not** an SQL constraint — see the warning below. |
| `field_offer_duration_unit` | list_text | 1 | **No** | SPEC 100. `hours` or `days` — see [`field_offer_duration_unit`](#field_offer_duration_unit). |
| `field_offer_includes` | text_long | 1 | **No** | SPEC 100. **No `text_processing`**, unlike `field_offer_message`: no format column. |
| `field_offer_excludes` | text_long | 1 | **No** | SPEC 100. Same. |
| `field_offer_tax_included` | list_boolean | 1 | **No** | SPEC 100. `0 = No`, `1 = Sí`. **Three-valued in practice**: empty means *the provider never said*, which is a different answer from `No`. The widget is `options_select` and never a checkbox, precisely so it can be left empty. |
| `field_offer_warranty_days` | number_integer | 1 | **No** | SPEC 100. `0` is a declaration — *no warranty* — and not an absence. |
| `field_offer_requires_visit` | list_boolean | 1 | **No** | SPEC 100. `0 = No`, `1 = Sí`. The API always writes one of the two; an offer stored before SPEC 100 is empty and is read as `false`. |

The **account** that offered is the node's native `uid`; `field_provider` is
the provider node it belongs to. Two different things, because one provider can
have several accounts.

**The ten fields of SPEC 100 are all optional instances, and that is a
decision.** There were real offers stored on the site when they were created, and
a required instance would have left every one of them unsaveable from
`node/%/edit` until a human filled the new field in. **The obligation lives in
the endpoint**, where it can be reasoned about and changed without touching the
database: `POST /api/v1/service-requests/{id}/offers` demands `amount_type`, and
nothing else beyond the message and the provider. See
[service-offer.md](service-offer.md).

> ⚠️ **`min = 0` is NOT an SQL constraint** on `field_offer_duration` nor on
> `field_offer_warranty_days`. In Drupal 7 a minimum is an `#element_validate`
> the `number` widget adds, so a programmatic `node_save()` writes a `-15`
> without a word. The endpoint repeats the cut — exactly as this document
> already warns for [`field_hourly_rate`](#field_hourly_rate).

> **`myapi_update_7035()` backfills nothing.** Every offer stored before it
> answers `null` on the ten new columns for ever, and that is the answer, not a
> gap: deducing an `amount_type` from the amount would put in a provider's mouth
> a statement they never made. Same discipline as `myapi_update_7032()`.

### Calificación de servicio (`service_rating`)

| Field | Type | Card. | Required | Notes |
|---|---|:---:|:---:|---|
| `field_rating_offer` | entityreference → `service_offer` | 1 | **No** (since SPEC 87) | The awarded offer being rated. Empty when the request was a **direct** one and there was no bidding round. |
| `field_rating_provider` | entityreference → `provider` | 1 | Yes | The provider being scored. Denormalised from the offer, or taken from the direct request's `field_assigned_provider`. |
| `field_stars` | list_integer | 1 | Yes | `1` to `5`. No zero, no half stars. |
| `field_rating_comment` | text_long | 1 | No | `plain_text` pinned. |

**`field_rating_offer` was required until SPEC 87, and the status catalogue is
what freed it.** Closing a request in `direct` demands a rating
(`myapi_services_close_requires_rating()`) and a direct job has no offer to hang
it off, so a required offer would have demanded a rating that could not be
saved. The reference that always exists is `field_rating_provider`, and that one
stays required: a rating with no provider scores nobody.

What this costs, stated plainly: a rating on an **assigned** request could point
at an offer of a different request, and nothing in the schema stops it — the
check that `field_rating_offer` belongs to the request being closed, and that its
provider is `field_rating_provider`, is now business logic for the flow that
creates ratings. Before SPEC 87 the offer was at least always present; it was
never verified to be the right one (see
[provider-detail.md](provider-detail.md)).

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
direct|Proveedor directo
offered|Con ofertas
assigned|Asignada
closed|Cerrada
cancelled|Cancelada
```

`direct` is SPEC 87's; the other five are SPEC 77's. It sits second because the
list is in lifecycle order and `direct` is an **entry** status, not a step of the
round: `open` and `direct` are the two ways a request can be born.

The transition graph, `myapi_services_request_transitions()`:

```
open ──(first offer)──> offered ──(award)──> assigned
                           │                    │
                           └──────> closed <────┘
                                      ▲
direct ───────────────────────────────┘

open | direct | offered | assigned ──> cancelled
```

- `closed` and `cancelled` are **terminal**. Nothing reopens a request:
  reopening would need its own rules for the offers and the chat of the closed
  round.
- `direct` is a **root**: no status leads into it. A request is either born with
  a provider already chosen or it goes through the bidding round, and the two
  paths never cross — `open → direct` would leave the offers already received
  with nothing to resolve them, and `assigned → direct` would contradict the
  offer the resident awarded. Its only exits are `closed` and `cancelled`.
- Closing **from `assigned` or from `direct` requires a rating**
  (`myapi_services_close_requires_rating()`): both mean there is a provider who
  did the job. Closing from `offered` is the contract's "no award" path and
  there is nobody to score. This is what made `field_rating_offer` optional —
  see [Calificación de servicio](#calificación-de-servicio-service_rating).

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

`sent` and `selected` are the two **live** statuses — the ones that mean *still
in play* — and they are named as constants in
`includes/myapi.services_common.inc` (`MYAPI_SERVICES_OFFER_STATUS_SENT`,
`..._SELECTED`) because two different places read them together: the uniqueness
gate of SPEC 100 and the rejection sweep of SPEC 95. `rejected` and `withdrawn`
are dead offers and block nothing.

### `field_offer_amount_type`

```
fixed|Precio cerrado
estimate|Estimado
hourly|Por hora
on_site_quote|A presupuestar en sitio
```

SPEC 100. The catalogue that turns *a number* into a quote: the same `150` means
a closed price, a guess, an hourly rate or nothing at all, and without this the
resident is comparing figures that do not mean the same thing. It is the one new
field the API makes **mandatory** beyond the message, precisely because the
number is unreadable without it.

`on_site_quote` is the value that carries **no** amount, and the endpoint
enforces both halves: an amount is required for the other three and forbidden
for this one.

### `field_offer_duration_unit`

```
hours|Horas
days|Días
```

SPEC 100. Two values and no more: below an hour nothing in this marketplace is
worth quoting, and above a handful of days the honest answer is a date, which is
what `field_offer_available_from` already carries. Adding a third later is a
value in this list plus an update, and it changes no caller.

Meaningless on its own — a duration without its unit says nothing and a unit
without its duration says less — so the API takes the two together or neither,
and serves them as **one object**.

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

**The rule has exactly two homes, and they move together.** The pure function
above is the PHP one, for a caller holding a node; `includes/myapi.provider_query.inc`
is the SQL one, for a caller counting or listing rows it never loads. Both
consumers of the SQL half — the `providers_count` of
`/api/v1/service-categories` and the `/api/v1/providers` listing — call that
include, and neither writes the `WHERE` itself. A spec that changes what
"active" means has to change both files, and must not write a third copy: the
first symptom of a divergence is a category card promising "3 providers" over a
listing that returns 4.

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
| `field_gallery` (provider) | `private://` | SPEC 82. An express decision: the gallery of a provider is not reachable by a guessable URL for anybody without a session. The price is that every image goes through PHP and needs a token — see [provider-gallery.md](provider-gallery.md). |
| `field_category_icon` (category) | `public://` | A catalogue asset shown in the app's category grid. |
| `field_logo` (provider) | `public://` | SPEC 85. An express decision: a logo is the provider's commercial identity, it goes on every card of the listing and it reveals nothing about anybody. Private, a page of twenty providers would be twenty authenticated requests and twenty Drupal bootstraps just to paint the list. |

The category icon stays public because making it private would mean an
authenticated download endpoint per thumbnail of the grid, for an image that
reveals nothing, and the logo of SPEC 85 is public for exactly the same reason.
**The two criteria coexist on purpose** — and they now coexist on the same
bundle, `provider`, with a public logo next to a private gallery. The rule for
future specs is: a catalogue asset or the commercial identity of the site is
public; content uploaded for one ficha or one case is private.

One consequence of a public logo, accepted with the decision: its URL is
guessable and cacheable forever. Removing a logo from the field does not clear
it from a browser cache or a CDN in front, and the old URL may keep serving the
file until it expires. It is commercial information the provider publishes
themselves, not personal data. Should a logo ever need to be genuinely
retractable, the way out is to make it private with a `hook_update_N` and a
`file_move()` — the work of `myapi_update_7023()` — not a patch in the endpoint.

### Maintenance rule — `hook_file_download()` now has TWO owners

`myapi_file_download()` in `myapi.module` asks **claims first and providers
second**, and returns `NULL` only when neither owner recognises the URI:

```php
$headers = myapi_claims_file_download_headers($uri, $user);

// NULL means "not mine". Anything else — headers or -1 — is already a decision
// taken about a claim file, and asking the second owner would turn it into a
// permission granted through the back door.
if ($headers !== NULL) {
  return $headers;
}

return myapi_provider_file_download_headers($uri, $user);
```

The cut is `$headers !== NULL` and **never** a loose `if (!$headers)`: that one
happens to work today because `-1` is truthy, and stops working the day anyone
changes that value.

Any new **private** file field on `provider` must be created with
`'settings' => ['uri_scheme' => 'private']` **and** added to the ownership
resolution in `includes/myapi.provider_files.inc`. Half the job is worse than
none: a field created without both is born public, or private and unreachable by
both readers.

A **public** one — `field_logo` is the only case today — does neither. It never
goes through PHP, so it has no owner to resolve, and `hook_file_download()`
knows nothing about it: that is exactly what makes it cheap, and why SPEC 85
touched neither `myapi_file_download()` nor
`includes/myapi.provider_files.inc`. What decides which of the two a new field
is, is the criterion of [Which files are private](#which-files-are-private),
never convenience. It is the same rule
`includes/myapi.claims_files.inc` states for `reclamo` and `claim_transaction`,
and payment receipts (`private://comprobantes_pago`, SPEC 20) are still
recognised by nobody.

---

## Shared fields — read this before editing the installer

Eight fields are **borrowed**, not created. Each gets a new instance here and
the field itself is untouched:

| Field | Owner | Used on |
|---|---|---|
| `field_requester`, `field_condominium` | reservations (SPEC 32) | `service_request` |
| `field_unit` | reservations (SPEC 32) | `service_rating` (SPEC 84), `service_request` (SPEC 86) |
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
  a name, description or hierarchy adjusted on the site. SPEC 81 calls it a
  second time, for `provider_tag`, and adds nothing else to it.
- `_myapi_reservations_ensure_node_type()`, `_ensure_field()` and
  `_ensure_instance()` — reused unchanged from SPEC 32/49.

Order matters: the vocabularies first (the category and tag fields name them),
then the five bundles (an entityreference field names a target bundle, which
has to exist), then the fields, then the instances.

This is also why the update hooks call the **whole** installer instead of just
their own new pieces: a surgical update would restate the field definitions in a
second place of `myapi.install`, with the certainty that one day they diverge.
Re-running it finds everything in place and writes nothing — a field adjusted by
hand on the site survives the update untouched.

---

## How it is applied

| Site | What runs |
|---|---|
| Fresh install | `myapi_install()` → `_myapi_services_install()`, after `_myapi_claims_install()` (which creates the borrowed fields and makes them private) and before `_myapi_building_admin_install()`. |
| Already installed | `drush updb` → `myapi_update_7025()` (SPEC 77), `myapi_update_7028()` (SPEC 81), `myapi_update_7029()` (SPEC 82), `myapi_update_7030()` (SPEC 84), `myapi_update_7031()` (SPEC 85), `myapi_update_7032()` (SPEC 86), `myapi_update_7033()` (SPEC 87) and `myapi_update_7035()` (SPEC 100) → the same `_myapi_services_install()` in all eight, plus two `field_update_*()` calls in `7033` (see below). |

`drush cc all` afterwards. Update history of this feature:

| Update | Spec | What it adds |
|---|---|---|
| `myapi_update_7025` | 77 | The `service_category` vocabulary, the five bundles and their fields. |
| `myapi_update_7028` | 81 | The `provider_tag` vocabulary and the three new fields of `provider`. |
| `myapi_update_7029` | 82 | `field_gallery` on `provider`, and the **deletion** of `field_photo` with its data. |
| `myapi_update_7030` | 84 | The borrowed `field_unit` instance on `service_rating`. |
| `myapi_update_7031` | 85 | `field_logo` on `provider` — public, one image, born **empty** for every existing provider. |
| `myapi_update_7032` | 86 | The borrowed `field_unit` instance on `service_request` — the first one of this feature that is **required**. |
| `myapi_update_7033` | 87 | The `direct` status in `field_request_status` (both bundles at once), and `field_rating_offer` relaxed to optional. The first services update that does **more** than re-run the installer. |
| `myapi_update_7035` | 100 | The ten quote fields of `service_offer` — price type, validity, availability, duration and its unit, what is and is not included, tax, warranty and prior visit. All ten instances **optional**, and **no backfill**: the offers already stored are not touched and answer `null` on all ten. Back to just re-running the installer, because all twenty creations are new and the idempotent `_ensure_*` helpers only ever create. |

All of them create **structure only** — no permission, no role and no node, so
running them changes nothing any user already has. **`7029` is different in two
ways**: it deletes a field and its data irreversibly (see
[The gallery, and the photo that is gone](#the-gallery-and-the-photo-that-is-gone)),
and SPEC 82 does add two routes — the ones documented in
[provider-gallery.md](provider-gallery.md).

**`7031` adds no route** and no field to fill in, but it does change the shape of
two responses already in production: `GET /api/v1/providers` and
`GET /api/v1/providers/{id}` gain the `logo` key, in second position. Whoever
maintains the app has to know **before** the deployment — see
[provider.md](provider.md) and [provider-detail.md](provider-detail.md).

**`7032` is the one to check before running.** It adds no route and changes no
response — `service_request` still has no endpoint — but its instance is
**required**, and a required instance on an already-installed bundle stops any
node without a value from being saved again. Count them first:

```bash
drush sqlq "SELECT COUNT(*) FROM node WHERE type = 'service_request';"
```

`0` is the expected answer, because no flow creates requests yet and only
`administrator`/`backend` reach the form. If it is not `0`, those requests need a
unit picked by hand — the update runs no backfill and touches no node, on
purpose: nothing in the module can infer which unit a request meant (a resident
may occupy several, see [provider-detail.md](provider-detail.md)).

**`7033` is the first one that cannot just re-run the installer**, and the reason
is worth knowing before writing the next update: the idempotent helpers only
*create*. `_myapi_reservations_ensure_field()` skips a field that already exists
and `_myapi_reservations_ensure_instance()` skips an existing instance, so on an
installed site neither the wider `allowed_values` nor the relaxed `required`
would ever be applied — and nothing would report the omission. `7033` therefore
calls `field_update_field()` on `field_request_status` (with the values read from
`myapi_services_request_statuses()`, never retyped) and `field_update_instance()`
on `field_rating_offer`, then clears the field cache.

It migrates **no data**, and needs none. Removing an allowed value in use throws
core's `FieldUpdateForbiddenException` — that is what `myapi_update_7021()` had
to rewrite rows around when it dropped `duplicated` from the claims catalogue —
but *adding* one has nothing to forbid, and relaxing `required` cannot invalidate
a stored rating: every row that satisfied the stricter rule satisfies the looser
one. Re-running it writes the same six values again and sets the same flag.

Because `field_request_status` is shared, one `field_update_field()` gives the
status to **both** selects at once: the request's and the timeline's
(`service_transaction`).

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
`drush pm-uninstall myapi` therefore leaves both vocabularies, the five content
types, their fields and all their nodes exactly where they are — providers,
requests, offers and ratings are real client data.

Flipping it to TRUE runs `_myapi_services_uninstall_destructive()`, which
deletes this feature's own fields — the three of SPEC 81 included, since their
names are new in the whole module and no other bundle uses them — removes the
**instances** of the seven borrowed ones, deletes the five node types and both
vocabularies, and purges the field data. Independent from the reservations and claims constants: flipping
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
- Nothing consumes the hourly rate, the tags or the short description (SPEC 81).
  There is no `/api/v1/providers` yet, and the spec that writes it decides then
  how the rate is formatted in JSON, whether the tags travel as strings or as
  `{id, name}`, and whether the short description goes through
  `myapi_text_to_plain()` or `check_plain()`. Nothing relates the rate to
  `field_offer_amount` either: it is a shop-window figure, not a business rule.
