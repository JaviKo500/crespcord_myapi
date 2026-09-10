# Service request supervision detail (back office)

The whole of one service request on a single **read-only** screen, at
**`admin/content/service-requests/<nid>`**. It is a page for the operator, not
an API endpoint: it returns HTML, never the JSON envelope, and **no endpoint
under `api/v1/` is involved or changed by it** (SPEC 126).

It is what turns the listing of SPEC 125 into supervision. Four blocks — the
request's data, its transaction timeline, the offers it received and its
attachments — and **not one control that leads to a write**: no form, no
button, no link to the node's editing form, no link to the transaction routes.

**It opens no query of its own.** Every row it paints comes from a function
that already existed:

| Block | Function | Where it lives | Cost |
|---|---|---|---|
| 1. Datos | `myapi_service_request_detail_row()` | `includes/myapi.service_request_query.inc` (SPEC 89) | 1 query |
| 2. Línea de tiempo de transacciones | `myapi_service_transaction_timeline_rows()` | `includes/myapi.service_transaction_admin.inc` (SPEC 94) | 1 query |
| 3. Ofertas recibidas | `myapi_service_request_load_offers($nid, [])` | `includes/myapi.service_request_detail.inc` (SPEC 89) | 1 query |
| 4. Adjuntos | the fields of the node the router already loaded | — | **0 queries** |

Three block queries, one `user_load()` for the requester's name, and the
`node_load()` the router itself does for the `%node` wildcard. Nothing else.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry and `myapi_service_request_admin_detail_access()`. Glue only. |
| `includes/myapi.service_request_detail_admin.inc` | The whole screen: the four table builders, the awarded-offer label and the broken-request notice. Loaded by the `file` key of the menu entry, so it only reaches PHP on this route. |
| `includes/myapi.service_requests_admin.inc` | Reused, not duplicated: `myapi_service_requests_status_label()`, `_text_label()`, `_date_label()`, `_requester_label()` and `_provider_label()` are the listing's, so the same value reads the same way on both screens. Its own `myapi_service_requests_detail_link()` is the ID cell that leads here. |
| `includes/myapi.service_transaction_admin.inc` | Reused: the timeline query and `myapi_service_transaction_author_label()`. |
| `includes/myapi.service_request_detail.inc` | Reused: `myapi_service_request_load_offers()`. |
| `includes/myapi.services_common.inc` | Reused: `myapi_services_request_statuses()`, `myapi_services_offer_statuses()` and `myapi_services_offer_amount_types()`. This screen declares no catalogue key and no Spanish catalogue label of its own. |

Nothing under `resources/` is touched, and none of the three reused query
functions changes a line.

---

## Access control

Three conditions, in this order:

1. **The node is a `service_request`.** Any other bundle answers 403. Without
   it the route would render a supervision screen over any node id of the
   site.
2. **The reader holds one of the three roles of the listing** — `administrator`,
   `backend`, `administrador edificio` — decided by
   `myapi_service_requests_admin_access()` **itself**, not by a second list.
   `uid 1` is let in there explicitly.
3. **`node_access('view', $node)`.**

| Account | `admin/content/service-requests/<nid>` |
|---|---|
| `administrator` | 200 for every request (`bypass node access`) |
| `backend` with no other role | 200 for every request |
| `administrador edificio` | 200 for a request of an assigned condominium, **403** for anybody else's |
| Authenticated with none of the three roles | 403 |
| Anonymous | 403 |
| Non-existent nid | 404 — Drupal's own, from the `%node` loader |

### Why the condominium scope is `node_access()` and not a list written here

The listing scopes itself with `->addTag('node_access')` on its query; a screen
showing **one** record scopes itself with `node_access()` over that record. It
is the same rule, read two ways. Since SPEC 125 put `service_request` in
`myapi_building_admin_condominium_map()` (mode `direct`, over
`field_condominium`), `myapi_node_access()` answers it for an
`administrador edificio`. Comparing `field_condominium` against the assigned
list here would work today and drift the day that rule moves — decision 2 of
SPEC 125, read from the per-node side.

### Known anomaly: `backend` + `proveedor` on the same account

`myapi_node_access()` consults `myapi_provider_role_node_decision()` without
looking at any exempt list, so an account holding **both** `backend` and
`proveedor` sees the row in the listing and receives **403** here. It is the
same anomaly SPEC 125 documented as its risk 1, with one more surface.

It is a configuration anomaly, not a bug of this screen, and it is not fixed by
weakening the access callback. The operational answer is to give the account
`administrator` or to remove `proveedor` from it.

---

## Block 1 — Datos

Thirteen label/value rows, in this order. Every optional value degrades to an
em dash **on its own**, and no row ever disappears: this is the screen where a
half-filled request gets found.

| # | Label | Source (alias of `myapi_service_request_detail_row()`) | When missing |
|---|---|---|---|
| 1 | ID | `nid` | — always present |
| 2 | Título | `title` | `—` |
| 3 | Estado | `status`, labelled from `myapi_services_request_statuses()` | `—`. A value outside the catalogue prints **raw**: that is a request edited by hand and the operator has to see it |
| 4 | Condominio | `condominium_name` (the node title) | `—` |
| 5 | Vivienda | `unit_name` (`field_nombre_vivienda`, not the node title) | `—` |
| 6 | Solicitante | `requester_uid`, resolved with one `user_load()` | No requester: `Sin solicitante`. Account deleted: `Usuario eliminado (#uid)` |
| 7 | Categoría | `category_name`, with `category_code` in parentheses when it has one | `—` |
| 8 | Fecha de creación | `created`, `d/m/Y H:i` | — always present |
| 9 | Fecha deseada | `desired_start`, `d/m/Y H:i` | `—` |
| 10 | Fecha de cierre | `closed_at`, `d/m/Y H:i` | `—` |
| 11 | Proveedor adjudicado | `assigned_provider_name` / `assigned_provider_raw` | Not awarded: `—`. Awarded to a provider that is gone: `Proveedor eliminado (#nid)` |
| 12 | Oferta adjudicada | `assigned_offer_id` / `assigned_offer_raw` + `assigned_offer_status` | Not awarded: `—`. Awarded to an offer that is gone: `Oferta eliminada (#nid)`. Awarded with no status: `#<id>` |
| 13 | Descripción | `description`, line breaks preserved | `—` |

The requester's **name** is not in the row: the detail query projects the uid
and never the name, because the JSON response does not need it. It is resolved
here with one `user_load()`, rather than by adding a join to a query two
endpoints share.

Rows 11 and 12 read the raw `target_id` **and** the resolved node, which is why
the query projects both: "never awarded" and "awarded to something that was
unpublished or deleted" are different facts, and one em dash for both would
hide the second behind the first. A `direct` request has a provider and **no
offer at all** (SPEC 87), so the em dash in row 12 is the normal answer for it,
not a symptom.

Every value is printed through `check_plain()`; the description additionally
goes through `nl2br()`, so its line breaks survive while its content stays
escaped.

---

## The broken request: a diagnosis, never a 404

`myapi_service_request_detail_row()` starts from
`myapi_service_request_base_query()`, which **INNER JOINs** `field_requester`,
`field_category` and `taxonomy_term_data` — deliberately, so a broken request
never reaches the app. The back-office listing does the opposite and **LEFTs
everything**, so that the same broken request *is* listed, with em dashes.

Both rules are right and they are opposite. There is therefore a request the
operator sees in the listing whose detail query answers **no row**. Left alone,
the listing would link to a 404 on precisely the rows that exist to be
repaired.

What the screen does instead:

- a warning is rendered at the top, naming the request, saying what is missing
  (the category or the requester), what it costs (**the app does not see the
  request**) and where it is repaired;
- block 1 falls back to the three things the node knows about itself — ID,
  title, creation date. It is **not** a best-effort reconstruction out of
  `$node->field_*`: that would be a second, weaker copy of the detail query
  living next to the real one;
- **blocks 2, 3 and 4 render in full.** None of them depends on the category or
  on the requester.

To reproduce it on a test site: delete the `service_category` term a request
points at, then open the request from the listing.

---

## Block 2 — Línea de tiempo de transacciones

`myapi_service_transaction_timeline_rows()`, in its own order: most recent
first (`field_status_date DESC, nid DESC`).

| Column | Source | When missing |
|---|---|---|
| Estado | `field_request_status` of the transaction, labelled from the SPEC 77 catalogue | `—`. Outside the catalogue: **raw** |
| Fecha del estado | `field_status_date`, cut to 16 characters | `—` |
| Comentario | `field_comment` | `—` |
| Autor | `node.uid` → username, via `myapi_service_transaction_author_label()` | `Usuario eliminado (#uid)` |

**Four columns and not six.** The SPEC 94 builder
(`myapi_service_transaction_timeline_table_rows()`) makes the same four and
appends an `Editar` and a `Borrar` link. This screen carries no write control,
so it builds its own four cells. Giving that function a mode flag would turn
one screen's builder into two screens' builder, and the price of getting the
flag wrong is a write control on the screen that promises none.

**The status date is a substring, never `format_date(strtotime(...))`.**
`field_status_date` was created with `tz_handling = 'none'` (SPEC 55): what is
stored is a naive local time, and converting it would shift the hour somebody
typed by hand. The cut to 16 characters drops the seconds, which this module
always pins to `:00`.

There is no pager: a request has units of transactions, the same call SPEC 94
already made.

---

## Block 3 — Ofertas recibidas

`myapi_service_request_load_offers($nid, [])`. **The empty list is the point**:
that parameter exists to trim a bidding provider to their own offers, and a
supervisor is not a competitor. Every published offer of the request travels,
whatever its status.

| Column | Source | When missing |
|---|---|---|
| ID | the offer's nid | — |
| Proveedor | `field_provider` → the provider node's title | `Proveedor eliminado` |
| Estado | `field_offer_status`, labelled from `myapi_services_offer_statuses()` | `—`. Outside the catalogue: **raw** |
| Importe | `field_offer_amount` | `—` |
| Tipo de importe | `field_offer_amount_type`, labelled from `myapi_services_offer_amount_types()` | `—` |
| Válida hasta | `field_offer_valid_until`, `d/m/Y H:i` | `—` |
| Mensaje | `field_offer_message` | `—` |
| Fecha | `node.created`, `d/m/Y H:i` | — |

The **awarded** offer's row carries the class `myapi-offer-selected`, matched by
nid against `assigned_offer_id` of block 1. It is a mark, not a control:
nothing on this screen awards or rejects anything.

Two degraded shapes are expected here and **neither drops a row**:

- an offer stored before SPEC 100 has a row in none of the ten quote tables, so
  its three quote cells answer em dashes. Dropping it would make this list
  disagree with `offers_count`;
- an offer whose provider node was unpublished arrives with `provider_name`
  NULL and stays in the list, for the same reason. The query projects no raw
  `target_id` for the provider, so an offer with no provider reference at all
  reads the same way — acceptable, because both are the same red flag and both
  need the same repair.

**A building admin sees every amount of the round.** That is deliberate: the
role is one of the three back-office roles, it already sees the awarded amount
on the request, and comparing the losing quotes is exactly the supervision job.

---

## Block 4 — Adjuntos

Read off the `%node` the router already loaded — `field_attachment` first, then
`field_images` in their stored delta order. `node_load()` already ran
`field_attach_load()`, so this block costs **no query at all**.

| Column | Source | When missing |
|---|---|---|
| Archivo | `filename`, linked with `file_create_url($uri)` | Nameless file: the link text is `#<fid>` |
| Tipo | `filemime` | `—` |
| Origen | `Adjunto` for `field_attachment`, `Imagen` for `field_images` | — |

A field item with no `fid` is skipped: that is the empty widget row Drupal
leaves behind, and it would otherwise become a link to nothing.

### Why not `myapi_service_request_load_images()`

Because it answers items of `myapi_service_request_build_file()`, whose `url`
is **`api/v1/service-requests/{id}/files/{fid}`** — an endpoint that demands a
Bearer token, which a back-office session does not carry. Every link would
answer **401**, and the failure would look like a broken link rather than an
error. It also does not return the `uri`, which is what `file_create_url()`
needs, so reusing it would additionally cost a `file_load_multiple()` to
recover what the loaded node already holds in memory.

### How the file links are authorised

They go through Drupal's private-file route, so the decision is
`hook_file_download()` → `myapi_service_request_file_download_headers()` →
`myapi_service_request_file_access()` (SPEC 89), which:

- lets `uid 1` in;
- lets `administrator` and `backend` read every request's files;
- scopes `administrador edificio` to the condominiums assigned to them;
- answers `-1` (a hard deny) to everybody else, **including a resident** — the
  app reads its own files through the `api/v1` endpoint with its token, not
  through this route.

**No access rule for the attachments is written by SPEC 126.** It has existed
since SPEC 89 and this screen simply uses the route it already guards.

---

## Tests

`tests/unit/ServiceRequestAdminDetailTest.php` covers the pure half — the four
table builders, the awarded-offer label, the degraded summary, the broken
notice and the bundle and role gates of the access callback — plus two cases
that assert over the file itself:

- **`testTheScreenHasNoWriteAffordanceAtAll()`** — the promise of this spec is
  about a *file*, so it is asserted over the file's code (comments stripped):
  no `node_save`, no `db_insert`/`db_update`/`db_delete`, no
  `drupal_get_form`, no submit element, no link to the editing form or to the
  transaction routes, and no call to the SPEC 94 table builder. It is the case
  that fails the day somebody adds an "Editar" link because it was convenient.
- **`testTheScreenOpensNoQueryOfItsOwn()`** — no `db_select`, no `db_query`. A
  query here would be a fourth answer to "what is a service request".

`tests/unit/ModuleContractTest.php` carries the route in
`NON_VERSIONED_PATHS`, which is what allows a non-`api/v1` path to exist, and
its own cases check that the callback lives in the file the route declares and
that the file is registered in `myapi.info`.

Not unit-tested, and said out loud rather than skipped in silence:
`myapi_service_request_admin_detail_page()` (four render arrays and three
reused calls, every piece of which is tested below it) and the
`node_access()` half of the access callback (the fixture answers what it is
told, so asserting it would only prove the stub).

---

## Manual verification

Five accounts, the same five as SPEC 125: `administrator`, `backend`,
`administrador edificio` with two condominiums, `administrador edificio` with
none, and a `proveedor`.

| # | Check | Expected |
|---|---|---|
| 1 | Each account opens `admin/content/service-requests/<nid>` of a request of condominium A | 200 for the first three; 403 for the building admin without condominiums and for the `proveedor` |
| 2 | The building admin of A and B opens a request of condominium C | 403 |
| 3 | `backend` opens a request with transactions, offers and files | The four blocks render, the awarded offer is marked |
| 4 | Open a request with no transactions, no offers and no files | The three "no hay" messages; no error |
| 5 | Click an attachment as each of the three roles | The file downloads |
| 6 | Click the same attachment URL as a resident | 403 |
| 7 | Delete the category term of a request, then open it from the listing | The warning, the degraded summary, and blocks 2, 3 and 4 in full — never a 404 |
| 8 | Search the rendered HTML for `<form`, `<input`, `<button` and `/edit` | Nothing |
| 9 | Open the detail of a `direct` request | Provider in row 11, em dash in row 12 |
| 10 | Open a nid of another bundle under this path | 403 |

`drush cc all` is required after deploying: the route is new. **`drush updb` is
not** — SPEC 126 adds no schema, no field and no permission.
