# Claims listing (back office)

A read-only, server-rendered, paginated table of `reclamo` nodes, at
**`admin/content/claims`**. It is a page for the operator, not an API
endpoint: it returns HTML, never the JSON envelope, and **no endpoint under
`api/v1/` is involved or changed by it** (SPEC 56).

It shows every claim visible to the current user with five GET filters and
Drupal's own pager. It never creates, edits or deletes anything: the "Crear
reclamo" button and every row link point at Drupal's own `node/add/reclamo`
and `node/<nid>/edit` — there is no page of this module's own for either, and
no AJAX of any kind.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry, `myapi_claims_admin_roles()`, `myapi_claims_admin_access()`. Glue only. |
| `includes/myapi.claims_admin.inc` | The whole page: GET-parameter validation, the filter form, the table and its row labels. Loaded by the `file` key of the menu entry, so it only reaches PHP on this route. |
| `includes/myapi.claim_query.inc` | `myapi_claims_list_rows()`, the single query. |
| `includes/myapi.building_admin.inc` | Unmodified by this file: the `via_claim` condominium-resolution mode and the `hook_query_node_access_alter()` narrowing live here, reused through `->addTag('node_access')` on the query above. |
| `includes/myapi.reservation_calendar.inc` | Reused, not duplicated: `myapi_calendar_condominium_scope()`, `myapi_calendar_positive_int()`, `myapi_calendar_effective_condominium()` and `myapi_calendar_condominium_options()` are already generic — none of the four reads or writes anything calendar-specific — and `includes/myapi.reservation_notification.inc` already reused the last one the same way before this spec. |

After adding or modifying any of this, run:

```bash
drush updb && drush cc all
```

`drush updb` runs `myapi_update_7018()`, which grants the two new
`claim_transaction` permissions to `administrador edificio` on a site that
already had the module installed. `drush cc all` is what makes Drupal see the
new route and the new `.inc` files of `files[]` — without it the page answers
404.

---

## Access control

Access is **by role name only**, the same criterion as
`admin/content/reservation-calendar`:

```php
function myapi_claims_admin_roles() {
  return ['administrator', 'backend', MYAPI_BUILDING_ADMIN_ROLE];
}
```

- `administrator` and `backend` see the listing.
- `administrador edificio` sees the listing too — **with or without a
  condominium assigned**. The access callback only asks "does the role
  apply"; it never asks "does this user have anything to see". A building
  admin with a pending assignment gets an empty table, not a 403, which is
  what keeps a missing assignment from reading as a broken account.
- `uid 1` always gets in, even with none of those roles — the same explicit
  guard `myapi_calendar_access()` carries, because Drupal's superuser bypass
  lives inside `user_access()`, which this callback never calls.
- Everybody else — an authenticated user without those roles, and any
  anonymous visitor — gets a **403**.

`myapi_claims_admin_roles()` is the single source of truth for that list, the
same pattern as `myapi_calendar_admin_roles()`. No permission gates this page
the way `view reservation calendar` documents (but does not grant) access to
the calendar: SPEC 56 introduced no equivalent permission, since the two
`claim_transaction` permissions granted by this spec (below) are about
*editing transactions*, a different question from *seeing the listing*.

---

## The `via_claim` condominium-resolution mode

Before this spec, `myapi_building_admin_condominium_map()` had no entry for
`claim_transaction`: its condominium is not on the node itself, and resolving
it needs two hops — `field_claim` → the `reclamo` node → `field_condominium`.
SPEC 56 adds the fourth mode this hop needed:

| Node type | Mode | How the condominium is resolved |
|---|---|---|
| `reclamo` | `direct` | `field_condominium` |
| `claim_transaction` | `via_claim` | `field_claim` → the claim's `field_condominium` |

The claim's own field name is read from the map's `reclamo` entry, not
hard-coded a second time — the same principle `via_unit` already applies by
reading `vivienda`'s field from the map instead of repeating it.

This one change has two effects, both scoped to `administrador edificio` and
neither touching any `api/v1/...` endpoint:

1. **Permissions.** `myapi_building_admin_editable_types()` now includes
   `claim_transaction` once the bundle exists, which makes
   `myapi_building_admin_permissions()` grant `create claim_transaction
   content` and `edit any claim_transaction content`. On a clean install
   these are granted by `_myapi_building_admin_install()` directly; on a site
   where the module was already installed, `myapi_update_7018()` (a single
   call to that same idempotent installer) backfills them — re-running it
   never duplicates a row in `role_permission`.
2. **Node access.** `hook_node_access()` and the `node_access`-tagged query
   alter now cover `claim_transaction` the same way they cover every other
   type in the map: a direct URL to a transaction of a foreign condominium
   answers 403, and it disappears from any node-access-tagged listing —
   including the entityreference autocompletes, the same side effect already
   documented for every other type in `docs/building-admin-role.md`.

**Fail-closed, same criterion as every other mode in the map:**

- A `claim_transaction` whose `field_claim` is empty, or whose referenced
  `reclamo` has no `field_condominium`, resolves to `NULL` — the rule stays
  silent about it (`NODE_ACCESS_IGNORE`), it is not hidden and it raises no
  PHP notice. This is what happens to an orphan transaction left behind by a
  deleted `reclamo`.
- If `field_claim` or `field_condominium` do not exist in a given
  environment, the query-alter branch for `via_claim` is not added at all,
  and `claim_transaction` disappears from the affected listings rather than
  showing up unfiltered.

**Maintenance rule.** Any future query over `claim_transaction` — the
transaction listing of the SPEC B edit page being the immediate candidate —
must carry `->addTag('node_access')`, the same rule already written down in
`docs/building-admin-role.md` for the rest of the module. Forgetting it would
expose transactions of every condominium to a building admin who should only
see their own; nothing else in the code would catch that.

`administrator` and `backend` are unaffected by any of the above: `via_claim`
only ever narrows a node-access-tagged query for `administrador edificio`,
same guard 1 as every other mode.

---

## GET parameters

Every filter travels in the query string, so any position of the listing is a
URL that can be copied, pasted into another tab and shared. Nothing here ever
errors: a junk parameter falls back to "no filter".

| Parameter | Accepted values | Default | If invalid |
|---|---|---|---|
| `condominium` | Positive integer, a `condominio` nid | no filter | No filter |
| `status` | `received`, `in_progress`, `resolved`, `closed`, `duplicated` | no filter | No filter |
| `claim_type` | `requirement`, `claim` | no filter | No filter |
| `date_from` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | no filter | Ignored |
| `date_to` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | no filter | Ignored |
| `page` | Handled entirely by Drupal's pager (`->extend('PagerDefault')`) | `0` | — |

Examples — all of them render a page, none of them error:

```
admin/content/claims
admin/content/claims?condominium=7&status=received
admin/content/claims?claim_type=claim&date_from=2026-01-01&date_to=2026-06-30
admin/content/claims?status=inventado&date_from=hola&condominium=abc   -> every filter falls back to none
```

The reception-date range uses two native HTML5 `<input type="date">` fields,
same reasoning as the calendar's own date field: the browser supplies its own
picker with no extra module, and a browser without support falls back to a
plain text box that still submits `YYYY-MM-DD` when typed by hand.

### `administrador edificio` and the `condominium` filter

Same criterion as the calendar (SPEC 47/49):

- The **condominium select** in the filter form lists only the condominiums
  assigned to the user in `field_condominio_admin`.
- A hand-edited **`?condominium=B`** pointing at a condominium **not**
  assigned to the user is treated as *no selection* — the select comes back
  empty, and for this role no selection means **all of mine**, never all of
  the site's. Nothing of B is shown, in the select or in the table, no matter
  what the URL asks for.
- With **no condominium assigned**, the select is empty and the table shows
  nothing — not an error, the same empty-listing outcome described under
  *Access control* above.

The row-level restriction itself is **not** implemented by this filter: it
comes from `->addTag('node_access')` on the query (see
`includes/myapi.claim_query.inc`), which
`myapi_building_admin_alter_node_query()` narrows automatically. The
`condominium` GET parameter only ever adds an extra, optional condition on
top of that — passing nothing, or a foreign nid, still leaves the query
scoped to the assigned condominiums.

`administrator` and `backend` are unaffected: `condominium` filters the whole
site's claims for them, with no scoping at all.

---

## Columns

| Column | Source | When empty or the reference is deleted |
|---|---|---|
| ID | `n.nid` | — always present |
| Asunto | `n.title` | — always present |
| Condominio | `field_condominium` → the condominium's title | No `field_condominium`: `Sin condominio`. Nid present but not among the published condominiums: `Condominio no disponible (#99)`. |
| Estado | `field_status`, labelled from the field's own `allowed_values` | `—` when the field is empty (should not happen: the field is required) |
| Tipo | `field_claim_type`, labelled from the field's own `allowed_values` | `—` when empty |
| Solicitante | `field_requester` → the account's username | No `field_requester`: `Sin solicitante`. Account deleted: `Usuario eliminado (#uid)`. |
| Fecha de recepción | `field_reception_date` | `—` when empty |

Every value coming from the database is printed through `check_plain()` or
Drupal's `l()` (which escapes its text argument by default), so a claim
subject or a condominium title containing `<script>` or `&` is escaped.

Clicking the **Asunto** cell of a row navigates to `node/<nid>/edit` — the
native Drupal node-edit form, not a page of this module's own. The **Crear
reclamo** button at the top of the listing navigates to `node/add/reclamo`,
also native.

Only **published** claims (`node.status = 1`) of type `reclamo` are listed.

---

## Pagination

Drupal's own pager (`->extend('PagerDefault')->limit(20)`), 20 rows per page,
ordered `nid` **descending** — the most recent claims first. With 20 rows or
fewer, no pager is shown. Every active filter is preserved across pages: the
pager's links carry the current query string, the same as any Drupal listing
with exposed filters.

---

## Manual verification

There is no unit test for this page: `myapi_claims_list_rows()` runs
`db_select()` and the page callback touches `$_GET` and the Field API, the
same criterion the rest of `tests/unit/` already applies to
`myapi_reservation_calendar_rows()` and `myapi_reservation_calendar_page()`.
The `via_claim` resolution logic itself **is** covered, in
`tests/unit/BuildingAdminTest.php`.

```bash
drush updb && drush cc all
```

**Access matrix** — same URL, `admin/content/claims`:

| User | Condominiums assigned | Expected |
|---|---|---|
| `uid 1` | — | Sees the listing |
| Role `administrator` | — | Sees the listing, every claim |
| Role `backend` | — | Sees the listing, every claim |
| Role `administrador edificio` | One or more | Sees the listing, only its own claims |
| Role `administrador edificio` | None | Sees the listing, **empty table**, no error |
| Authenticated, none of those roles | — | 403 |
| Anonymous | — | 403 |

**Permissions matrix** (SPEC 56 grants, not access to this page):

| Site | Action | Expected |
|---|---|---|
| Clean install, `drush en myapi` | Check `/admin/people/permissions` | `administrador edificio` has `create claim_transaction content` and `edit any claim_transaction content` |
| Module already installed | `drush updb` | `myapi_update_7018` runs and grants the same two permissions, nothing else touched |
| Either | Re-run the update twice | No duplicate row in `role_permission` |
| Either | Check the permission list | No `delete ... claim_transaction content` permission exists, ever |

**Condominium scoping matrix** — `administrador edificio` with condominium A
assigned, a `claim_transaction` whose `reclamo` belongs to condominium B:

| Action | Expected |
|---|---|
| Open `/node/<transaction nid>/edit` directly | 403 |
| Same transaction, `reclamo` belongs to A instead | 200 |
| `field_claim` on the transaction is empty | No PHP error; access falls through to the rest of Drupal |
| The `reclamo` referenced by `field_claim` has no `field_condominium` | Same — no PHP error, `NODE_ACCESS_IGNORE` |
| `administrator` or `backend` opens either transaction | 200, no restriction |
| A resident calls any `api/v1/...` endpoint | Response identical to before this spec — no resource file changed |

**Listing filters matrix**:

| Case | Expected |
|---|---|
| No parameters | Every visible claim, `nid` descending |
| `?condominium=<assigned nid>` | Only that condominium's claims |
| `?condominium=<foreign nid>` (building admin) | Ignored — same as no `condominium` at all |
| `?status=received` (and each other value, alone and combined with the rest) | Only matching claims |
| `?claim_type=claim` | Only matching claims |
| `?date_from=2026-01-01&date_to=2026-06-30` | Only claims received in range, inclusive both ends |
| `?status=inventado&date_from=hola` | No error — both fall back to "no filter" |
| More than 20 visible claims | Pager appears, filters preserved across pages |
| 20 or fewer visible claims | No pager |

**No regression / infra**:

- `resources/*.resource.inc` does not appear in the diff of this spec.
- `hook_menu()` gained exactly one new route, `admin/content/claims`; no
  `api/v1/...` path changed.
- `myapi_update_7017` and every earlier update hook are unchanged.
- `drush cc all` reports no errors.
