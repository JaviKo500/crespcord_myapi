# Building admin role (`administrador edificio`)

A back-office role for the person who operates **one or more buildings** but
not the whole site. It can create and edit bulletins, areas and reservations,
and it only ever sees the content of the condominiums assigned to it (SPEC 49)
and the **people** of those condominiums — their owners and occupants, plus
themselves (SPEC 51).

Two filters, same shape, one pair of halves each: a direct-URL check that
answers 403 and a query alter that narrows every listing and autocomplete. The
content one is `hook_node_access()` + the `node_access` tag; the people one is
`hook_menu_alter()` + the `user_access` tag.

**No `api/v1/...` endpoint is involved or changed by this role.** It exists for
the Drupal admin UI only; the Flutter app never sees it, and a resident's
responses are byte for byte what they were before.

---

## Files

| File | Role |
|------|------|
| `includes/myapi.building_admin.inc` | The NODE axis: the four catalogues, the pure decision logic, the bulletin validation and the node query alter. Single source of truth for what content the role reaches — and for `myapi_building_admin_query_base_table_alias()`, the guard both alters share (SPEC 72). |
| `includes/myapi.building_admin_user.inc` | The USER axis (SPEC 51): who the role may see. The visible-uid set, the pure visibility rule, the `user/%user` access callback, the `user_access` query alter and the reservation-form validation. |
| `myapi.module` | Glue only: `hook_node_access()`, `hook_query_node_access_alter()`, `hook_menu_alter()`, `hook_query_user_access_alter()`, the `boletin` and `reservation` branches of `hook_node_validate()`, and the role added to `myapi_calendar_admin_roles()`. |
| `myapi.install` | `_myapi_building_admin_install()` — the role, the user field and the permissions — called from `hook_install()` and from `myapi_update_7012()` / `7013` / `7014`. Plus `hook_requirements('runtime')`, which warns when the role has lost `access user profiles`. |
| `includes/myapi.reservation_calendar.inc` | Narrows the calendar page (condominium select, area select and reservation query) to the assigned condominiums. |
| `includes/myapi.reservation_notification.inc` | `myapi_reservation_building_admin_uids()` — adds the building admins of a condominium to the "reservation created" email. |
| `tests/unit/BuildingAdminTest.php` | Unit tests of the catalogues and of every pure decision on the node side. |
| `tests/unit/BuildingAdminUserTest.php` | Unit tests of the three pure functions of the people filter (SPEC 51). |

---

## Deployment

```bash
drush updb    # 7012: role, field and permissions
              # 7013: the text-format permission + the area-notes default format
              # 7015: revokes 'view the administration theme'
              # 7016: repairs the field_condominio_admin autocomplete (SPEC 53)
drush cc all  # picks up the new includes/myapi.building_admin.inc of files[]
```

Both are needed. Without `updb` there is no role and no `field_condominio_admin`;
without `cc all` Drupal does not see the new `.inc` declared in `myapi.info` and
the hooks fatal on the first node listing.

> **`cc all` stopped being optional in SPEC 51.** `hook_menu_alter()` only runs
> on a menu rebuild and its result is cached in the `menu_router` table. Skip it
> and `access user profiles` is granted while the 403 on `/user/N` is not yet in
> force — the role would read the profile of **every resident of every
> condominium**. After every deployment, check it in one move: open
> `/user/<a resident of another condominium>` as the operator and confirm a 403.
> That is the verification that is never skipped.

Every update is **idempotent**: running them twice creates no second role, no
second field and no duplicate row in `role_permission`.

### One manual step per environment: the login rule

> **Without this, a building admin cannot log in at all.** They authenticate and
> are thrown straight back out — the log shows `Sesión abierta` immediately
> followed by `Sesión cerrada`.

The site has a Rules reaction rule, **`rules_validar_condominio_activo`**
("validar condominio activo"), on the *User has logged in* event. It counts the
condominiums where the account is `field_propietario` or `field_ocupante` and,
finding none, redirects to `user/logout`. A building admin is **not a
resident** — they manage a building, they do not live in one — so they have
neither, and the rule expels them.

The rule already carries an exemption list: its condition is
`NOT user_has_role(...)` over a set of staff role ids. The fix is to add this
role to that list:

`/admin/config/workflow/rules` → **validar condominio activo** → edit the
condition *NOT User has role(s)* → tick **`administrador edificio`** → save.

> **This does not travel with the module.** That Rules condition stores role
> **ids**, not names — the opposite of the criterion used throughout this
> feature, where the `rid` is never referenced because it differs per
> environment. So `drush updb` does not carry it, and the exemption must be
> redone by hand in **every** environment where the role is installed (local,
> staging, production). Get the id with:
>
> ```bash
> drush sqlq "SELECT rid, name FROM dr_role ORDER BY rid"
> ```
>
> Giving the operator a fake `vivienda` instead would also unblock the login,
> but it puts a non-resident into the residents' data and is not the fix.

---

## What the installer creates

### The role

One row in `role` with `name = 'administrador edificio'`, in lower case, exactly
as agreed with the client. The `rid` is assigned by Drupal per environment and
**the code never references it** — every check compares role *names*, the same
criterion as `myapi_calendar_admin_roles()`.

The role is created **empty**. Assigning it to a person is manual.

### The user field

`field_condominio_admin` — an `entityreference` to `condominio` nodes, unlimited
cardinality, with an instance on the `user` entity. It shows at
`/admin/config/people/accounts/fields` and is edited at `/user/N/edit` as a tags
autocomplete that only offers `condominio` nodes.

> **"Only `condominio` nodes" needs `myapi_update_7016()` on sites installed
> before SPEC 53.** The `handler_settings.target_bundles` that restricts the
> autocomplete is a **field-level** setting in Drupal 7 entityreference, and this
> installer wrote it on the *instance*, where the selection handler never looks —
> so the tags autocomplete of `/user/N/edit` offered every node of the site, not
> just condominiums. Assigning the wrong node there is not cosmetic: the
> condominium map of this role is read straight off this field. The settings now
> come from `_myapi_entityreference_field_settings()` in `myapi.install`, shared
> with the four reservation fields that had the same bug; the repair for existing
> sites is `myapi_update_7016()`. See `docs/reservations-install.md` and
> `specs/_shared/53-entityreference-selection-settings.md`.

### The permissions

| Permission | Why |
|---|---|
| `create boletin content`, `edit any boletin content` | Create and edit bulletins |
| `create reservation content`, `edit any reservation content` | Create and edit reservations |
| `create area content`, `edit any area content` | Create and edit areas |
| `create reclamo content`, `edit any reclamo content` | Create and edit reclamos — granted automatically since the bundle was created by SPEC 55 |
| `access content` | See published nodes |
| `access content overview` | Enter `/admin/content` |
| `access administration pages` | Navigate the back office |
| `use text format filtered_html` | Write formatted text — see below |
| `access user profiles` | Read people — scoped to their condominiums by the people filter, see below |

**`access user profiles` is site-wide and the filter is the only thing that
scopes it.** Drupal has no per-condominium variant of it: the permission opens
every profile, and the two halves of the people filter (SPEC 51) are what narrow
what the role actually reaches. Without the permission the role keeps everything
else but loses two things at once — every profile page answers 403 and the
**`field_requester` autocomplete of the reservation form comes back empty**, with
nothing on screen pointing at the cause. That is why `hook_requirements()` raises
a *warning* at `/admin/reports/status` when the role exists and the permission is
missing. It reports; it does not grant it back.

**`administer users` is NOT granted, and it must never be.** Two reasons, and
the second is the one that surprises: it is account administration this role has
no business with, **and holding it switches the people filter off entirely** for
whoever has it (see *The two halves of the people filter* below).

**The text format permission is not optional.** A role with no
`use text format ...` permission only reaches Drupal's fallback format
(`plain_text`), and every formatted field it may edit — the bulletin body,
`field_area_notes` on an area — renders **disabled**, with *"no tiene permisos
suficientes para editarlo"*. `filtered_html` and not `full_html`: the latter
allows arbitrary HTML and the permission is site-wide, not per field. The
format is named in `MYAPI_BUILDING_ADMIN_TEXT_FORMAT`, and the installer drops
the permission silently on a site where that format does not exist.

**`view the administration theme` is deliberately NOT granted.** With it, the
node forms, `/admin/content` and the reservation calendar came up in **Seven**,
while `backend` and every other operator of this site sees them in the site
theme. Two things were wrong with that: the role worked on a layout nobody else
here uses, and Seven does **not** show the role's own sidebar menu, because
blocks are placed per theme — so the operator lost their navigation on exactly
the pages where they need it. It was granted when SPEC 49 was approved and
dropped afterwards; `myapi_update_7015()` revokes it on sites that already had
it, so there is no manual step per environment.

**`access toolbar` is deliberately NOT granted.** Drupal's black toolbar offers
*Estructura* and *Configuración* entries this role can only get a 403 from, so
it navigates through its own sidebar menu instead — see *Navigation* below. It
was in the catalogue when SPEC 49 was approved and was dropped during
implementation; a site that already had it granted needs
`drush role-remove-perm "administrador edificio" "access toolbar"`, because the
installer never revokes.

**No `delete any … content` and no `delete own … content`, ever.** The role does
not delete: a reservation is taken down by cancelling it, a bulletin or an area
by unpublishing it from the edit form. Both are reversible; a delete is not. A
unit test fails the day somebody adds a delete permission to the catalogue.

Permissions are granted only if they **exist on the site**: the list is crossed
against `module_invoke_all('permission')`, so a permission whose content type
does not yet exist — `create reclamo content` before SPEC 55 created that
bundle, `create claim_transaction content` if it were ever added to the
catalogue — and `access toolbar` (module disabled) are dropped silently
instead of writing a dead row in `role_permission`.

> **Warning — hand-revoked permissions do not survive.** The installer is
> conservative: it creates what is missing and never revokes. But re-running
> `myapi_update_7012()` **does grant again** anything that was removed by hand
> at `/admin/people/permissions`. If a permission must be gone for good, take it
> out of `myapi_building_admin_permissions()`, not out of the UI.

Uninstalling the module (`drush pm-uninstall myapi`) leaves the role, its
permissions and the field **intact** — the same conservative criterion as the
reservations content types. Real user assignments are not something an uninstall
may take down.

---

## Assigning the role to a person

1. `/admin/people` → edit the user.
2. Tick **`administrador edificio`** under *Roles*.
3. In **Condominios administrados** (`field_condominio_admin`), type the
   condominiums they administer. The autocomplete only offers `condominio`
   nodes; add as many as needed.
4. Save.

> **Only `administrator` and `backend` can see or set that field.**
> `hook_field_access()` hides `field_condominio_admin` from everybody else, on
> the user form and on the rendered profile alike — the building admin's own
> account included. This is not tidiness: editing one's own account needs no
> permission in Drupal, so without it an operator could open `/user/N/edit` and
> assign themselves every condominium on the site. The whole filter is only as
> strong as who can write this field. The list lives in
> `myapi_building_admin_assigner_roles()`; `uid 1` is let in explicitly, the
> same guard `myapi_calendar_access()` carries.
>
> Denying the field does **not** wipe it: Drupal omits a field the account
> cannot edit from the form and skips it on save, so an operator editing their
> own profile keeps their condominiums. Nor does it break the filter —
> `myapi_building_admin_condominium_ids()` reads the raw field off the loaded
> user object and never calls `field_access()`.

> **A user with the role and no condominium assigned sees nothing**: an empty
> `/admin/content`, and a 403 on every node of the types listed below. That is
> the designed behaviour, not a bug — the assignment *is* the permission.

> **A user who also holds `administrator` sees everything.** That role carries
> `bypass node access`, which returns before `hook_node_access()` is ever
> invoked and makes the filter inapplicable. When testing this feature, use an
> account holding **only** `administrador edificio`.

---

## How the condominium of a node is resolved

`myapi_building_admin_condominium_map()` is the single source of truth. Four
modes: `self` (the node is the condominium), `direct` (a reference field on the
node), `via_unit` (two hops, through the `vivienda`) and `via_claim` (two hops,
through the `reclamo`; SPEC 56).

| Node type | Mode | How the condominium is resolved |
|---|---|---|
| `condominio` | `self` | Its own `nid` |
| `boletin` | `direct` | `field_condominio` |
| `gastos` | `direct` | `field_condominio` |
| `vivienda` | `direct` | `field_condominio` |
| `area` | `direct` | `field_condominium` |
| `reservation` | `direct` | `field_condominium` |
| `pagos` | `via_unit` | `field_vivienda` → the unit's `field_condominio` |
| `recibo` | `via_unit` | `field_vivienda` → the unit's `field_condominio` |
| `alicuota_extra` | `via_unit` | `field_vivienda` → the unit's `field_condominio` |
| `reclamo` | `direct` | `field_condominium` — the same shared field as `area`/`reservation` (SPEC 55) |
| `claim_transaction` | `via_claim` | `field_claim` → the claim's `field_condominium` (SPEC 56) |

**A type that is not in this table is out of the rule.** So is a node of a
listed type whose field is empty or missing: the condominium resolves to `NULL`,
`hook_node_access()` returns `NODE_ACCESS_IGNORE` and the rest of Drupal decides,
exactly as for any other user. No PHP notice is raised on a half-filled node.

`claim_transaction`'s own field name (`field_condominium`) is not hard-coded a
second time in the `via_claim` branch: it is read off the map's `reclamo`
entry, the same principle `via_unit` already applies by reading `vivienda`'s
field from the map instead of repeating it. See `docs/claims-list.md` for the
full detail — the permissions this mode unlocks, its fail-closed behaviour and
the manual verification matrix.

The rule **only ever denies**. It never returns `NODE_ACCESS_ALLOW`: granting
there would short-circuit every other check Drupal makes — unpublished nodes,
other modules' hooks — turning a condominium filter into a permission
escalation.

---

## The two halves of the content filter

### 1. `hook_node_access()` — the direct URL

Runs per node loaded, for `view`, `update` and `delete`. A node of somebody
else's condominium answers **403** at `/node/N` and at `/node/N/edit`.

Note that `create` is **not** covered: Drupal 7 does not invoke this hook for it.
Bulletins are covered instead by the form validation below. For `area` and
`reservation` the operator can still pick a foreign condominium in the form —
an accepted risk, and a visible one, because the node becomes unreachable to
them the moment it is saved.

### 2. `hook_query_node_access_alter()` — the listings

> **This reaches every query tagged `node_access`, not just `/admin/content`.**
> Blocks, search, other modules' views and — most visibly — the
> **`entityreference` autocompletes** of the node forms are all narrowed for
> this role. If a selector or a block looks empty or short for a building
> admin, this is why. It is the intended behaviour, not a fault to diagnose.

That last one is the reason `condominio` is in the *visible* types even though
the role cannot edit it: the autocompletes of `field_condominio` (bulletin) and
`field_condominium` (area, reservation) query with that tag, so excluding the
type would leave those selectors empty and the role unable to create anything.
The wanted side effect is that they offer the assigned condominiums and only
those.

The query alter narrows to the **visible types** **and** to the assigned
condominiums. Visible means two different things:

| Types | What the role can do |
|---|---|
| `boletin`, `reservation`, `area`, `reclamo` (SPEC 55) | Create and edit |
| `condominio`, `vivienda` | **Read only** — no `create`, no `edit any`, `/node/N/edit` answers 403 |

The read-only ones live in their own catalogue,
`myapi_building_admin_readonly_types()`, which never feeds
`myapi_building_admin_permissions()`. That separation is what makes "consult"
and "administer" two different edits to the code rather than one flag, and a
unit test fails if a read-only type ever picks up a write permission.

Everything else disappears from the listings: a building admin sees no `pagos`,
no `recibo` and no `gastos` at `/admin/content`, not even of their own
condominium, because they do not manage those.

Three guards run before anything is altered: the user holds the role; the query
is really **about** nodes, i.e. `node` is its **base table** (the tag does
**not** imply it — other modules apply it to taxonomy, search or other
entities, and altering those would break unrelated pages with an SQL error);
and the user has at least one condominium assigned.

**Base table, not merely present** — see *Why the tag is not enough* below. Both
alters ask the same question through the same helper,
`myapi_building_admin_query_base_table_alias()`.

No query of this module carries the `node_access` tag, so the `api/v1/...`
endpoints are untouched.

> **`/admin/content` is served by a Views view on this site.** Views queries
> carry the `node_access` tag **unless** the view has *«Disable SQL rewriting»*
> ticked. If a building admin sees the whole site's content in that listing
> while direct URLs still answer 403, that checkbox is the cause: untick it in
> the view. Do not add code to compensate.

---

## The two halves of the people filter

The owner and the occupants of a unit (`field_propietario`, `field_ocupante`,
`field_ocupantes`) are **users**, not nodes, and neither `hook_node_access()`
nor the `node_access` tag reaches the user entity. SPEC 51 gives that axis its
own pair of halves, built exactly like the node one and living in
`includes/myapi.building_admin_user.inc`.

The rule in one sentence: **an operator sees the owners and occupants of the
units of their assigned condominiums, plus themselves.**

Two things that sentence deliberately does not say:

- There is **no exception** for `administrator`, for `backend` nor for another
  building admin. Whoever cannot be traced to an assigned condominium is
  invisible, whatever role they hold. A rule with no exceptions fits in one line
  and does not erode.
- **One's own account is always visible**, listed or not. Without that, an
  operator with no unit of their own would lose *Mi cuenta* and their own edit
  form — and it opens nothing, since seeing one's own profile needs no
  permission for any role in Drupal.

The visible set is resolved once per request and cached: assigned condominiums
(`myapi_building_admin_condominium_ids()`) → their units → owners and occupants
(`myapi_condominium_member_uids()`, the same function the bulletin notifications
of SPEC 25 use), plus the operator's own uid, which is added **always**. That
last addition is why there is no "empty list" branch anywhere: an operator with
no condominium assigned gets a list of exactly one uid — their own — and sees
only themselves.

### 1. `hook_menu_alter()` — the direct URL

`myapi_menu_alter()` swaps the access callback of `user/%user` and
`user/%user/view` for `myapi_building_admin_user_view_access()`. The profile of
a resident of another condominium answers **403** at `/user/N`.

That callback delegates to core's `user_view_access()` **first** and only
narrows afterwards, so it can take access away and never hand it out: blocked
accounts, anonymous visitors and the `access user profiles` permission itself
are still decided by Drupal.

`user/%user/edit` is **not** touched. Without `administer users`,
`user_edit_access()` already refuses somebody else's account, and the operator
keeps their own.

> This hook only runs on a **menu rebuild**. `drush cc all` after deploying is
> what puts it in force — see *Deployment*.

### 2. `hook_query_user_access_alter()` — the listings and the autocompletes

Mirror of the node one, on the `user_access` tag: past its two guards it adds a
single `users.uid IN (visible uids)`, with no `JOIN` — the uids are already
resolved in PHP.

> **This reaches every query tagged `user_access`.** Two matter today:
> - the generic `entityreference` handler when `target_type` is `user`, which is
>   what makes the **`field_requester` autocomplete** of the reservation form
>   offer only the residents of the assigned condominiums;
> - the **`users` base table of Views**, which declares it as an *access query
>   tag*, so a back-office listing of people is scoped for free.

The two guards, in order: the filter is active for this operator (everybody else
leaves on the first line, at the cost of one role lookup), and the query is
really **about** users — `users` is its **base table**. See the next section.

There is deliberately no third "empty list" guard, because the list is never
empty.

No query of this module carries the `user_access` tag, so the `api/v1/...`
endpoints are untouched — the same as on the node side.

> **A Views view on the `users` base table with *«Disable SQL rewriting»*
> ticked** lists every user of the site, exactly like the `/admin/content`
> case above and with the same fix: untick the checkbox in the view. Do not add
> code to compensate.

### Why the tag is not enough: base table, not merely present (SPEC 72)

> **This section exists because of a bug that emptied `/admin/content` for the
> role, in every content type at once, while every other part of it worked.**

An access query tag says **what the query is about**, not which tables it
contains — and the two only coincide for the **base** table.

Views adds the access tag of **every relationship's** base table, not just the
view's own:

```php
// views_handler_relationship::query()
// Add access tags if the base table provide it.
if (empty($this->query->options['disable_sql_rewrite']) && isset($table_data['table']['base']['access query tag'])) {
  $this->query->add_tag($table_data['table']['base']['access query tag']);
}
```

The `/admin/content` view has a `Contenido: Autor` relationship so it can show
and filter by the author. `users` declares `'access query tag' => 'user_access'`,
so that **content** listing — base table `node` — also carries the `user_access`
tag. The people filter then found the joined `users` alias and constrained
`node.uid`, i.e. the **author** of each row, turning the listing into *"content
written by a resident of my condominiums"*. Everything on this site is authored
by an operator who lives in no unit, so the answer was zero rows, for every type,
with no error on screen — `views_plugin_query_default::execute()` catches the
query in a `try/catch` and an empty view is indistinguishable from "no content".

Both alters now resolve their table with the shared pure helper
`myapi_building_admin_query_base_table_alias()`, which returns the alias of the
**base** table — the only entry of `$query->getTables()` with no `join type`,
because `db_select()` registers it with `addJoin(NULL, ...)` before any join can
exist — and `NULL` for anything else, including a subquery base, where `NULL`
means *leave the query alone*.

Nothing legitimate lost scope: `users` **is** the base table of the
`field_requester` autocomplete (`EntityFieldQuery` over the user entity) and of
any Views listing of people, and `node` is the base table of the content view,
of the `entityreference` condominium selectors and of `myapi_claims_list_rows()`.

**The accepted consequence, stated out loud:** a query about something else that
merely *joins* the table is no longer narrowed — a Views listing of people with
a relationship to the content they authored would show node titles unscoped by
condominium. No view on this site has that shape, both direct-URL halves are
untouched, and the rule lives in one function, so tightening it later is a
one-function edit. The alternative was measured in the field: landing a filter on
a column that means something else took the whole back office down.

> **Symptom to recognise:** a listing that comes back **completely** empty for
> this role — not short, empty, and for every content type — while
> `/node/N` answers 200 and the `entityreference` selectors offer the right
> condominiums, is a filter landing on the wrong column, not a scoping rule
> doing its job. A scoping rule removes *some* rows.

### The one exception: `administer users`

**An operator who also holds `administer users` is not filtered at all** — not
their profiles, not their autocompletes, not their listings.

That is not an oversight, it is the symmetric counterpart of `bypass node
access` on the node side: a user holding `administrator` *and* this role already
sees all the content of the site, because `bypass node access` cuts in before
`myapi_node_access()` ever runs. Without this exception they would see all the
content but only the people of their condominiums, and that asymmetry reads as a
bug in the module.

The price is explicit: **granting `administer users` to a building admin opens
the whole address book of the site to them.** So it is not in the catalogue, it
is never granted by the installer, and the exception exists only for whoever
holds it through another role.

Careful not to confuse the two sides — the rule with no exceptions says **who is
visible**; `administer users` says **who is looking**.

Note that `administer users` is not `bypass node access`: an operator holding it
still gets `/admin/content` filtered by condominium and still gets a 403 on a
`/node/N` of another condominium.

### What the filter does not reach: `user/autocomplete`

Core's own `user/autocomplete` path — the one behind the *Escrito por* /
*Authored by* field of the node form — does **not** carry the `user_access` tag,
so it is outside this filter and would offer the whole site.

It opens nothing today: that field requires `administer nodes`, which this role
does not have and must not get. It is written down here as a **known limitation
and a maintenance rule**, not as an open hole: any new field that uses that path
has to be filtered separately.

### The reservation form

`hook_node_validate()` guards the two scoped fields of a `reservation` on
submit, the same two-layer approach as the bulletin: the form already offers
nothing foreign, and the validation refuses a foreign value that arrives anyway
through a hand-crafted POST.

- `field_requester` must be one of the visible uids.
- `field_unit` must be a unit of an assigned condominium — reusing the SPEC 49
  decision, with no new query. The autocomplete of that field was already
  scoped, with no code of SPEC 51, because `vivienda` is a read-only visible
  type.

The failure mode this closes is the worst one available: a reservation booked
for a resident of another building becomes **invisible to the very operator who
saved it**, the moment it is saved.

`POST /api/v1/reservations` is untouched — `hook_node_validate()` is only
reached from `node_form_validate()`, and the endpoint saves its node
programmatically.

---

## Bulletins: only `Condominio`, only yours

Two layers, and both stay:

1. **The form** — `hook_form_boletin_node_form_alter()` removes `General` and
   `Personalizado` from the `field_tipo_de_boletin` widget, so a building admin
   is only ever offered `Condominio`. An option that cannot be used should not
   be shown; rejecting a choice after the fact reads as a bug to whoever made
   it.
2. **The submission** — `hook_node_validate()` blocks saving a `boletin` that
   is not of scope `Condominio`, or whose `field_condominio` falls outside the
   assignment. Both produce a form error and the node is not created.

The second is not redundant: shaping a form is not enforcing a rule, and a
hand-crafted POST never goes through the first. If the widget ever changes to a
shape the form alter does not recognise, the worst case is the old behaviour —
the option shows and the validation rejects it. Nothing opens up.

`General` is the one action of this role that reaches outside its own building:
it pushes and mails **every condominium of the site**, and it is irreversible
once sent. `Personalizado` picks recipients across condominiums. Neither is
available here.

A valid `Condominio` bulletin saved by this role fires the usual push + inbox
fan-out (SPEC 25) exactly like any other. Nothing about that path changes.

Users without the role — `administrator` included — are unaffected and keep
saving `General` and `Personalizado` bulletins as before.

> `field_tipo_de_boletin` is a **pre-existing site field this module does not
> own**. The comparison is against the literal value `'Condominio'`. If the
> field has no instance on the `boletin` bundle in some environment, the
> validation **skips itself** and logs a `watchdog()` WARNING rather than
> blocking every bulletin of the role.

---

## Reservation calendar

The role is in `myapi_calendar_admin_roles()`, so
`/admin/content/reservation-calendar` answers instead of 403. Once inside:

- the condominium select lists only the assigned condominiums;
- the area select lists only the areas of those condominiums;
- forcing `?condominium=B` in the URL shows **nothing** of B. A foreign or
  absent condominium is treated as "no selection", and for this role "no
  selection" means *all of mine* — never *all of the site's*.

`administrator` and `backend` see the page exactly as before.

---

## Reservation created email

The detail email of `POST /api/v1/reservations` now goes to the union of:

- every active user with the `backend` role (unchanged), and
- every active user with `administrador edificio` whose
  `field_condominio_admin` contains **the reservation's condominium**.

Deduplicated, so somebody holding both roles gets **one** email. Building admins
of other condominiums get nothing — that would be noise and a leak of other
residents' data. The resident's own email and the body of both messages are
unchanged.

The **cancellation** email (`reservation_cancelled_admin`) still goes to
`backend` only. Widening it was not part of SPEC 49.

---

## Navigation (site configuration, not this module)

The role has no toolbar, so it needs a menu. This is **Drupal site
configuration** — menus, blocks and view permissions — and none of it lives in
`myapi`; it is written here because without it the role logs in and finds no
way to reach anything.

1. `/admin/structure/menu/add` → a new menu, e.g. **Administración de edificio**.
2. Add one link per thing the role actually manages:

   | Link | Path | Note |
   |---|---|---|
   | Contenido | `admin/content` | |
   | Viviendas | `admin/content?type=vivienda` | Read only — the filter already scopes it to their condominiums |
   | Calendario de reservas | `admin/content/reservation-calendar` | |
   | Nuevo boletín | `node/add/boletin` | |
   | Nueva área | `node/add/area` | |
   | Nueva reserva | `node/add/reservation` | |

3. `/admin/structure/block` → place that menu's block in the sidebar region of
   the front-end theme, and under **Roles** tick **only**
   `administrador edificio`.

Drupal hides a menu link when the user cannot access its path, so the menu
filters itself: nothing here can show an entry the role would get a 403 from.

### The same thing in one command

Confirm the theme and its regions first — the values below are the ones of this
site, not a default:

```bash
drush vget theme_default
drush php-eval "print_r(system_region_list(variable_get('theme_default')));"
```

Today that answers `bootstrap`, with **`sidebar_first`** (labelled *Primary*,
where the site's own "Navegación" block already lives) and **`navigation`**
(the Bootstrap navbar). Then:

```bash
drush php-eval "
\$menu = array('menu_name' => 'menu-building-admin', 'title' => 'Administración de edificio', 'description' => 'Navegación del rol administrador edificio (SPEC 49).');
menu_save(\$menu);
\$links = array(
  array('link_path' => 'admin/content', 'link_title' => 'Contenido'),
  array('link_path' => 'admin/content', 'link_title' => 'Viviendas', 'options' => array('query' => array('type' => 'vivienda'))),
  array('link_path' => 'admin/content/reservation-calendar', 'link_title' => 'Calendario de reservas'),
  array('link_path' => 'node/add/boletin', 'link_title' => 'Nuevo boletín'),
  array('link_path' => 'node/add/area', 'link_title' => 'Nueva área'),
  array('link_path' => 'node/add/reservation', 'link_title' => 'Nueva reserva'),
);
\$w = 0;
foreach (\$links as \$l) { \$l['menu_name'] = 'menu-building-admin'; \$l['module'] = 'menu'; \$l['weight'] = \$w++; menu_link_save(\$l); }

db_merge('block')
  ->key(array('theme' => 'bootstrap', 'module' => 'menu', 'delta' => 'menu-building-admin'))
  ->fields(array('status' => 1, 'region' => 'sidebar_first', 'weight' => 0, 'title' => 'Navegación', 'visibility' => 0, 'pages' => '', 'custom' => 0, 'cache' => -1))
  ->execute();

\$rid = db_query('SELECT rid FROM {role} WHERE name = :n', array(':n' => 'administrador edificio'))->fetchField();
db_delete('block_role')->condition('module', 'menu')->condition('delta', 'menu-building-admin')->execute();
db_insert('block_role')->fields(array('module' => 'menu', 'delta' => 'menu-building-admin', 'rid' => \$rid))->execute();

menu_cache_clear_all();
print 'OK, rid=' . \$rid . PHP_EOL;
" && drush cc all
```

To undo it:

```bash
drush php-eval "
db_delete('block')->condition('module','menu')->condition('delta','menu-building-admin')->execute();
db_delete('block_role')->condition('module','menu')->condition('delta','menu-building-admin')->execute();
menu_delete(array('menu_name' => 'menu-building-admin'));
menu_cache_clear_all();
" && drush cc all
```

### Sidebar or navbar, not both

A menu block can sit in **one region per theme**. Swapping `sidebar_first` for
`navigation` in the script above moves the whole menu into the Bootstrap
navbar; there is no way to have the same block in both without duplicating the
menu or installing `menu_block` (not present on this site). The sidebar is the
recommended one — it is the layout the site's other administrative users
already have.

Do **not** solve it by adding the role's links to the **main menu** instead.
That block has no useful per-role visibility: Drupal only hides a link from
whoever cannot access its path, so `node/add/boletin` in the navbar would show
up for every other role that can create bulletins too.

> **None of this travels with the module.** Menus, blocks and their role
> visibility live in the site's database, so `drush updb` does not carry them
> and the whole section has to be redone in every environment — the same
> caveat as the Rules exemption above.

> **Do not reuse the site's existing administration sidebar.** That one holds
> Gestor Bancos, Personas, Reporte Pagos, Reporte Gastos and the rest of the
> financial back office. Those screens are **Views of this site**, and a view
> only goes through the condominium filter if its query carries the
> `node_access` tag — several of them aggregate across condominiums by design.
> Handing that menu to a building admin would undo the isolation this whole
> feature exists to provide.

---

## Maintenance rules

- **The restriction lives in the node access layer, not in the database.** Any
  direct SQL query ignores it — as every resource of this module already does.
  Any new back-office screen must go through `myapi_building_admin_*` or
  `node_access()`, or it will expose other condominiums' content again.
- **Adding a content type to the role** means editing
  `myapi_building_admin_editable_types()` and, if it has a condominium, adding
  its entry to `myapi_building_admin_condominium_map()`. Nothing else.
- **The claims-and-suggestions bundle (`reclamo`) exists since SPEC 55**, which
  also added its `myapi_building_admin_condominium_map()` entry
  (`'field' => 'field_condominium'`) — the last step that spec had promised
  here. Its permissions were already granted conditionally since SPEC 49, the
  moment the bundle exists on the site. Its timeline bundle,
  `claim_transaction`, was a deliberate exception until SPEC 56: its
  condominium is only resolvable by hopping `field_claim` → `reclamo` →
  `field_condominium`, which none of the three modes of the time supported.
  **SPEC 56 added the fourth mode, `via_claim`**, exactly for that hop, and
  `claim_transaction` is now in both the map and
  `myapi_building_admin_editable_types()`. See `docs/claims-install.md` and
  `docs/claims-list.md`.
- **Any new back-office screen that lists people** must go through the
  `user_access` tag or through `myapi_building_admin_user_decision()`. A hand-made
  `db_select('users', …)` with no tag is unfiltered and shows the whole site.
  Core's `user/autocomplete` carries no such tag either: a new field that uses it
  needs its own filtering.
- **Never grant `administer users` to this role.** Besides being account
  administration it has no business with, it switches the people filter off for
  whoever holds it — see *The one exception* above.
- **`access user profiles` must stay granted** for the role to see anybody at
  all. If it is removed, `/admin/reports/status` says so with a warning naming
  the role and the permission; nothing repairs it behind your back.
- **Deleting a `condominio` node** leaves a dangling reference in
  `field_condominio_admin` and the building admin silently loses access to it.
  Rare in production; the broken reference is visible at `/user/N/edit`.
