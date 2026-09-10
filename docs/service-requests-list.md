# Service requests listing (back office)

A read-only, server-rendered, paginated table of `service_request` nodes, at
**`admin/content/service-requests`**. It is a page for the operator, not an API
endpoint: it returns HTML, never the JSON envelope, and **no endpoint under
`api/v1/` is involved or changed by it** (SPEC 125).

It shows every request visible to the current user with five GET filters and
Drupal's own pager. It never creates, edits or cancels anything: each row links
to Drupal's own `node/<nid>/edit` or `node/<nid>`, there is no page of this
module's own for either, and no AJAX of any kind.

**There is deliberately no "create" button**, unlike `admin/content/claims`. A
service request is born in the app, from a resident, and moves forward through
`myapi_services_request_transitions()` (SPEC 77) and the endpoints that honour
it. The back office has no business inventing one.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry, `myapi_service_requests_admin_roles()`, `myapi_service_requests_admin_access()`, and the one-line change to `myapi_query_node_access_alter()` described below. Glue only. |
| `includes/myapi.service_requests_admin.inc` | The whole page: GET-parameter validation, the single query, the filter form, the table and its row labels. Loaded by the `file` key of the menu entry, so it only reaches PHP on this route. |
| `includes/myapi.building_admin.inc` | `service_request` joins `myapi_building_admin_readonly_types()` and the condominium map. This is what makes the row-level scoping work, through `->addTag('node_access')` on the query. |
| `includes/myapi.services_common.inc` | Reused, not duplicated: `myapi_services_request_statuses()` is the single source of the status select's options **and** of the table's status labels. This page declares no status and no Spanish label of its own. |
| `includes/myapi.reservation_calendar.inc` | Reused, not duplicated: `myapi_calendar_condominium_scope()`, `_positive_int()`, `_effective_condominium()`, `_condominium_options()` and `_filter_form_after_build()`, exactly as `includes/myapi.claims_admin.inc` already reuses them. |
| `css/myapi.claims.css` | Shared with the claims listing: the filter row of both pages is the same row. Each page keeps its own class (`.myapi-service-requests-filters`) so a rule can be given to one alone the day they stop being identical. |

The `.css` is attached from the page callback with `drupal_add_css()` and is
**deliberately not declared in `myapi.info`**: a `stylesheets[]` entry would
ship it on every page of the site, API responses included.

After deploying this spec, run:

```bash
drush cc all
```

**`drush updb` is not needed and runs nothing for this spec**: there is no new
table, no new field and — the point of the whole design — no new permission.
`drush cc all` is what makes Drupal see the new route and the new `.inc` of
`files[]`; without it the page answers 404.

### Where the listing query lives, and why

`myapi_service_requests_list_rows()` is in
`includes/myapi.service_requests_admin.inc` and **not** in
`includes/myapi.service_request_query.inc`, which is where every other query
over this bundle lives. That file's `@file` block states, three times and in
capitals, that none of its queries carries `->addTag('node_access')` — the tag
would run `myapi_provider_role_alter_node_query()` over them, and a resident who
also holds the `proveedor` role would stop seeing their own requests. It is an
invariant a reader can check at a glance.

This query carries that tag necessarily. Putting the two in one file would turn
a checkable rule into an exception that has to be read function by function.

---

## Access control

Access is **by role name only**, the same criterion as `admin/content/claims`
and `admin/content/reservation-calendar`:

```php
function myapi_service_requests_admin_roles() {
  return ['administrator', 'backend', MYAPI_BUILDING_ADMIN_ROLE];
}
```

- `administrator` and `backend` see the listing, every request of the site.
- `administrador edificio` sees the listing too — **with or without a
  condominium assigned** — narrowed to its own buildings. The access callback
  only asks "does the role apply"; it never asks "does this user have anything
  to see". A building admin with a pending assignment gets an empty table, not
  a 403, which is what keeps a missing assignment from reading as a broken
  account.
- `uid 1` always gets in, even with none of those roles — Drupal's superuser
  bypass lives inside `user_access()`, which this callback never calls.
- Everybody else — an authenticated user without those roles, and any anonymous
  visitor — gets a **403**.

`myapi_service_requests_admin_roles()` is the single source of truth for that
list. No permission gates the page.

---

## `service_request` is READ-ONLY for `administrador edificio`

This is the one change of this spec that reaches beyond the new page, and the
one difference with what SPEC 56 did for `reclamo`.

| Catalogue | Before | After |
|---|---|---|
| `myapi_building_admin_editable_types()` | `boletin`, `reservation`, `area`, `reclamo`, `claim_transaction` | **unchanged** |
| `myapi_building_admin_readonly_types()` | `condominio`, `vivienda` | + `service_request` |
| `myapi_building_admin_visible_types()` | the union of the two | + `service_request` |
| `myapi_building_admin_permissions()` | derived from the editable types | **unchanged — no `create service_request content`, no `edit any service_request content`** |

The bundle joins the read-only catalogue only when `node_type_load()` finds it,
the same guard the claims bundles carry: a site without the services bundles is
unaffected.

The precedent is **`vivienda`**, not `reclamo`: a bundle the building operator
needs to consult and must not touch. A request advances through the state
machine of SPEC 77 and the endpoints that honour it, never through a node form.
`tests/unit/BuildingAdminTest.php::testNoWritePermissionOverServiceRequestsIsEverGranted()`
fails the day somebody moves the bundle to the editable catalogue, which is what
makes that move a deliberate act rather than a side effect.

Because no permission changed, **there is no `hook_update_N` for this spec**.

### The two exempt lists of `hook_query_node_access_alter()`

Two roles narrow `node_access`-tagged queries — `administrador edificio`
(SPEC 49) and `proveedor` (SPEC 78) — and each alter is a complete allowlist of
its own domain, so each is handed the **other** role's domain as its exempt
types. Until this spec the two domains were disjoint. They now overlap in one
bundle, and the overlap is resolved once, in `myapi.module`:

```php
myapi_building_admin_alter_node_query($query, array_values(array_diff(
  myapi_services_node_types(),
  myapi_building_admin_visible_types()
)));
myapi_provider_role_alter_node_query($query, myapi_building_admin_visible_types());
```

- The building-admin call **subtracts its own visible types** from the
  provider's domain, so `service_request` is *not* exempt for it and its
  condominium rule applies. Written as a subtraction rather than as a literal
  list of the four bundles that remain: the rule being expressed is "the other
  role's domain, minus whatever I already claim", so the next bundle that
  crosses the border needs no edit there.
- The provider call is **unchanged** and keeps receiving the building admin's
  visible types, which now include `service_request`.

Resulting visibility in any `node_access`-tagged query:

| Role held by the account | `service_request` rows it sees |
|---|---|
| `administrator` | All — `bypass node access`, it never reaches either alter |
| `backend` only | All — guard 1 of both alters returns immediately |
| `administrador edificio` | Only those of its assigned condominiums |
| `proveedor` only | All — no longer narrowed in listings (see below) |
| `backend` + `proveedor` | All in the listing; **403 on opening the node** (see below) |

### Two consequences, both accepted deliberately

**1. The change applies to every `node_access`-tagged query, not just this
page.** An `administrador edificio` now also sees the requests of its
condominiums in `/admin/content` and in entityreference autocompletes. This is
the same side effect `vivienda` already has, documented in
`docs/building-admin-role.md`, and the role holds no create permission over any
bundle whose form would open a `service_request` selector, so the autocomplete
surface is nil today.

**2. A `proveedor` is no longer narrowed in listings.** Exempting the bundle from
the provider alter is what keeps an operator holding `backend` **and**
`proveedor` from seeing this listing cut down to the categories they attend. The
price is that the provider alter stops narrowing `service_request` anywhere. It
is not an open door:

- `myapi_node_access()` consults no exempt list at all, so a provider still gets
  a **403** on the direct URL of a foreign request.
- The `api/v1/...` queries carry no `node_access` tag, so
  `GET /api/v1/service-requests` for a provider is byte for byte what it was
  (SPEC 98's A ∪ B ∪ C rule, untouched).
- The role is granted **no** content-administration permission (SPEC 78), so it
  has no back-office listing to reach.

**The day the `proveedor` role is granted a content-administration permission,
this section has to be re-read.**

### Known anomaly: `backend` + `proveedor` on the same account

`myapi_node_access()` is the per-node half of both scope filters and it does
**not** use exempt lists: it denies whatever either role would deny. So an
operator who holds `backend` *and* `proveedor` sees every row in this listing
(the fix above) and collects a **403 when opening a request** of a category they
do not attend. `administrator` is unaffected — `bypass node access` never
reaches that hook.

The combination is a configuration anomaly, and the operational fix is to grant
`administrator` or drop `proveedor` from that account. Extending the exempt
logic into `myapi_node_access()` was considered and rejected in SPEC 125: it
moves the direct-URL access rule of all five marketplace bundles to fix one
misconfigured account.

---

## GET parameters

Every filter travels in the query string, so any position of the listing is a
URL that can be copied, pasted into another tab and shared. Nothing here ever
errors: a junk parameter falls back to "no filter".

| Parameter | Accepted values | Default | If invalid |
|---|---|---|---|
| `condominium` | Positive integer, a `condominio` nid | no filter | No filter |
| `status` | `open`, `direct`, `offered`, `assigned`, `closed`, `cancelled` | no filter | No filter |
| `category` | Positive integer, a term id of the `service_category` vocabulary | no filter | No filter |
| `date_from` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | no filter | Ignored |
| `date_to` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | no filter | Ignored |
| `page` | Handled entirely by Drupal's pager (`->extend('PagerDefault')`) | `0` | — |

Examples — all of them render a page, none of them error:

```
admin/content/service-requests
admin/content/service-requests?condominium=7&status=assigned
admin/content/service-requests?category=12&date_from=2026-09-01&date_to=2026-09-30
admin/content/service-requests?status=inventado&category=abc&date_from=hola   -> every filter falls back to none
```

**The date range filters the creation date (`node.created`) and nothing else.**
`field_desired_start` is shown as a column but never filtered: it is optional,
and filtering by it would silently drop every request that has none. Each bound
covers its whole day — `00:00:00` for `date_from`, `23:59:59` for `date_to` — so
`?date_to=2026-09-04` includes a request created that day at 16:45. The two
bounds are independent: either alone is a half-open range, and an inverted range
is kept as sent and simply matches nothing.

A `?category=` pointing at a term that is not in the vocabulary is dropped and
the select comes back on `- Todas -`. Unlike the condominium, this is **not** an
access rule: every operator who reaches this page sees every category.

### `administrador edificio` and the `condominium` filter

Same criterion as the calendar and the claims listing (SPEC 47/49/56):

- The **condominium select** lists only the condominiums assigned to the user in
  `field_condominio_admin`.
- A hand-edited **`?condominium=B`** pointing at a condominium *not* assigned to
  the user is treated as *no selection* — and for this role no selection means
  **all of mine**, never all of the site's.
- With **no condominium assigned**, the select is empty and the table shows
  nothing — not an error.

The row-level restriction is **not** implemented by this filter: it comes from
`->addTag('node_access')` on the query, which
`myapi_building_admin_alter_node_query()` narrows automatically. The
`condominium` parameter only ever adds an extra, optional condition on top.

---

## Columns

| Column | Source | When empty or the reference is deleted |
|---|---|---|
| ID | `n.nid` | — always present |
| Título | `n.title`, linked (see below) | Empty title: the link text falls back to the nid, so the row stays clickable |
| Condominio | `field_condominium` → the condominium's title | `—` |
| Estado | `field_request_status`, labelled from `myapi_services_request_statuses()` | `—`. A value outside the catalogue prints **raw**, on purpose: that is a request edited by hand and the operator has to see it |
| Solicitante | `field_requester` → the account's username | No requester: `Sin solicitante`. Account deleted: `Usuario eliminado (#uid)` |
| Fecha de creación | `n.created`, shown as `d/m/Y H:i` | — always present |
| Fecha deseada | `field_desired_start`, shown as `d/m/Y H:i` | `—` |
| Categoría | `field_category` → the term name | `—` |
| Proveedor adjudicado | `field_assigned_provider` → the provider's title | Not awarded: `—`. Awarded to a provider that was unpublished or deleted: `Proveedor eliminado (#nid)` |

Every value coming from the database is printed through `check_plain()` or
Drupal's `l()` (which escapes its text argument), so a request title or a
provider name containing `<script>` or `&` is escaped.

**The awarded provider is read from `field_assigned_provider` and never through
`field_assigned_offer`**: a `direct` request has a provider and no offer at all
(SPEC 87), so reading the award through the offer would blank the column for
every direct request. The query projects the field's raw `target_id` next to the
resolved node precisely to tell "not awarded" from "awarded to something that is
gone" — two different facts about a request.

**Every join of the query is `LEFT`, without exception.** This is the deliberate
difference with `myapi_service_request_base_query()`, which INNERs the category
and the requester: the app of a resident must not list a broken request, and this
page is where a broken request gets *found*. A request with no category, no
requester or a condominium pointing at a deleted node keeps its row here and
shows an em dash in that cell.

Only **published** requests (`node.status = 1`) of type `service_request` are
listed.

### The title link is per reader

| Reader | Link target |
|---|---|
| Can edit the node (`administrator`, `backend`) | `node/<nid>/edit` — where the transaction timeline of SPEC 94 lives |
| Cannot edit it (`administrador edificio`) | `node/<nid>` |

The decision is Drupal's own `node_access('update', $node)`, not a role check of
ours, so `edit own` permissions, `hook_node_access()` and `bypass node access`
all count. Two fast paths resolve it for every row at once and load no node at
all — `bypass node access` and `edit any service_request content` — so the usual
reader never pays for it. Anything else falls to one batched
`node_load_multiple()` of the page's 20 nids.

---

## Pagination

Drupal's own pager (`->extend('PagerDefault')->limit(20)`), 20 rows per page,
ordered `nid` **descending** — the most recent requests first. With 20 rows or
fewer, no pager is shown. Every active filter is preserved across pages: the
pager's links carry the current query string.

---

## Tests

Unlike the claims listing, this page arrives with unit coverage:
`tests/unit/ServiceRequestsAdminPageTest.php` (41 cases) drives the filters, the
labels, the table body, the edit-permission map **and the query itself**, under
the fixtures of `tests/unit/bootstrap.php`. Three cases are guards rather than
checks of today's behaviour:

| Test | Fails when |
|---|---|
| `testTheListingQueryCarriesTheNodeAccessTag()` | `->addTag('node_access')` is lost — which does not break the page, it silently lists every request of the site |
| `testEveryJoinedColumnIsOptional()` | a join becomes INNER and a half-filled request disappears from the one screen where it would be fixed |
| `testNoWritePermissionOverServiceRequestsIsEverGranted()` (in `BuildingAdminTest`) | the bundle is moved to the editable catalogue and the role silently gains `create` / `edit any` |

Out of scope, and named rather than skipped: `myapi_service_requests_list_page()`
and `myapi_service_requests_list_filter_form()`, which are `drupal_get_form()`,
`theme('pager')` and `drupal_add_css()` — Drupal's render pipeline, not a
decision of ours.

---

## Manual verification

```bash
drush cc all
```

**Access matrix** — same URL, `admin/content/service-requests`:

| User | Condominiums assigned | Expected |
|---|---|---|
| `uid 1` | — | Sees the listing |
| Role `administrator` | — | Sees the listing, every request |
| Role `backend` | — | Sees the listing, every request |
| Role `administrador edificio` | One or more | Sees the listing, only its own condominiums' requests |
| Role `administrador edificio` | None | Sees the listing, **empty table**, no error |
| Authenticated, none of those roles | — | 403 |
| Anonymous | — | 403 |

**Read-only matrix** — `administrador edificio`, a request of its own building:

| Action | Expected |
|---|---|
| Click the title in the listing | Opens `node/<nid>`, not the edit form |
| Open `node/<nid>/edit` by hand | **403** |
| Check `/admin/people/permissions` | No `create service_request content`, no `edit any service_request content` |
| `drush updb` | Nothing pending for this module |

**Condominium scoping matrix** — building admin with condominium A assigned, a
request of condominium B:

| Action | Expected |
|---|---|
| Open the listing with no filter | B's request is absent |
| `?condominium=<B's nid>` | Ignored — same rows as no filter at all |
| Open `node/<B's request nid>` directly | 403 |
| Open `/admin/content` | A's requests are listed, B's are not |
| `administrator` or `backend` opens the listing | Both A's and B's requests |

**Filters matrix**:

| Case | Expected |
|---|---|
| No parameters | Every visible request, `nid` descending |
| `?status=open` (and each other value) | Only matching requests |
| `?category=<tid>` | Only requests of that category |
| `?category=<tid not in the vocabulary>` | Ignored, select on `- Todas -` |
| `?date_from=2026-09-01&date_to=2026-09-30` | Only requests created in range, inclusive both ends |
| `?date_to=<the day a request was created>` | That request is inside the range, whatever its time |
| `?status=inventado&category=abc&date_from=hola` | No error — all three fall back to "no filter" |
| More than 20 visible requests | Pager appears, filters preserved across pages |
| 20 or fewer | No pager |

**Degraded-data matrix** (seen as `backend`):

| Request | Expected |
|---|---|
| No `field_category` | Row listed, `—` in Categoría |
| `field_condominium` pointing at a deleted node | Row listed, `—` in Condominio |
| Awarded to an unpublished provider | `Proveedor eliminado (#nid)`, not `—` |
| `direct` (provider, no offer) | Its provider shown in Proveedor adjudicado |
| No `field_desired_start` | `—`, never `01/01/1970` |

**No regression / infra**:

- `resources/*.resource.inc` does not appear in the diff of this spec.
- `myapi_service_request_base_query()` gained no join and no condition;
  `GET /api/v1/service-requests` answers what it answered before, for a resident
  and for a provider.
- `hook_menu()` gained exactly one route, `admin/content/service-requests`; no
  `api/v1/...` path changed.
- No `hook_update_N` was added.
- `drush cc all` reports no errors.
