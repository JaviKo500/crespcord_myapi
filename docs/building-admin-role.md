# Building admin role (`administrador edificio`)

A back-office role for the person who operates **one or more buildings** but
not the whole site. It can create and edit bulletins, areas and reservations,
and it only ever sees the content of the condominiums assigned to it (SPEC 49).

**No `api/v1/...` endpoint is involved or changed by this role.** It exists for
the Drupal admin UI only; the Flutter app never sees it, and a resident's
responses are byte for byte what they were before.

---

## Files

| File | Role |
|------|------|
| `includes/myapi.building_admin.inc` | Everything: the four catalogues, the pure decision logic, the bulletin validation and the query alter. Single source of truth. |
| `myapi.module` | Glue only: `hook_node_access()`, `hook_query_node_access_alter()`, the `boletin` branch of `hook_node_validate()`, and the role added to `myapi_calendar_admin_roles()`. |
| `myapi.install` | `_myapi_building_admin_install()` — the role, the user field and the permissions — called from `hook_install()` and from `myapi_update_7012()`. |
| `includes/myapi.reservation_calendar.inc` | Narrows the calendar page (condominium select, area select and reservation query) to the assigned condominiums. |
| `includes/myapi.reservation_notification.inc` | `myapi_reservation_building_admin_uids()` — adds the building admins of a condominium to the "reservation created" email. |
| `tests/unit/BuildingAdminTest.php` | Unit tests of the catalogues and of every pure decision. |

---

## Deployment

```bash
drush updb    # runs myapi_update_7012(): role, field and permissions
drush cc all  # picks up the new includes/myapi.building_admin.inc of files[]
```

Both are needed. Without `updb` there is no role and no `field_condominio_admin`;
without `cc all` Drupal does not see the new `.inc` declared in `myapi.info` and
the hooks fatal on the first node listing.

`myapi_update_7012()` is **idempotent**: running it twice creates no second
role, no second field and no duplicate row in `role_permission`.

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

### The permissions

| Permission | Why |
|---|---|
| `create boletin content`, `edit any boletin content` | Create and edit bulletins |
| `create reservation content`, `edit any reservation content` | Create and edit reservations |
| `create area content`, `edit any area content` | Create and edit areas |
| `create reclamo content`, `edit any reclamo content` | **Only if that bundle exists** — it does not yet |
| `access content` | See published nodes |
| `access content overview` | Enter `/admin/content` |
| `access administration pages` | Navigate the back office |
| `view the administration theme` | Node forms in the admin theme |

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
against `module_invoke_all('permission')`, so `create reclamo content` (no such
bundle) and `access toolbar` (module disabled) are dropped silently instead of
writing a dead row in `role_permission`.

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

> **A user with the role and no condominium assigned sees nothing**: an empty
> `/admin/content`, and a 403 on every node of the types listed below. That is
> the designed behaviour, not a bug — the assignment *is* the permission.

> **A user who also holds `administrator` sees everything.** That role carries
> `bypass node access`, which returns before `hook_node_access()` is ever
> invoked and makes the filter inapplicable. When testing this feature, use an
> account holding **only** `administrador edificio`.

---

## How the condominium of a node is resolved

`myapi_building_admin_condominium_map()` is the single source of truth. Three
modes: `self` (the node is the condominium), `direct` (a reference field on the
node) and `via_unit` (two hops, through the `vivienda`).

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

**A type that is not in this table is out of the rule.** So is a node of a
listed type whose field is empty or missing: the condominium resolves to `NULL`,
`hook_node_access()` returns `NODE_ACCESS_IGNORE` and the rest of Drupal decides,
exactly as for any other user. No PHP notice is raised on a half-filled node.

The rule **only ever denies**. It never returns `NODE_ACCESS_ALLOW`: granting
there would short-circuit every other check Drupal makes — unpublished nodes,
other modules' hooks — turning a condominium filter into a permission
escalation.

---

## The two halves of the filter

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
| `boletin`, `reservation`, `area` (+ `reclamo` once it exists) | Create and edit |
| `condominio`, `vivienda` | **Read only** — no `create`, no `edit any`, `/node/N/edit` answers 403 |

The read-only ones live in their own catalogue,
`myapi_building_admin_readonly_types()`, which never feeds
`myapi_building_admin_permissions()`. That separation is what makes "consult"
and "administer" two different edits to the code rather than one flag, and a
unit test fails if a read-only type ever picks up a write permission.

Everything else disappears from the listings: a building admin sees no `pagos`,
no `recibo` and no `gastos` at `/admin/content`, not even of their own
condominium, because they do not manage those.

### The people of a unit — not solved by this layer

Owners and occupants (`field_propietario`, `field_ocupante`) are **users**, not
nodes, and neither `hook_node_access()` nor the `node_access` query tag reaches
the user entity. There is no per-condominium filter for users anywhere in this
module.

So the obvious move is the wrong one:

> **Do not grant `access user profiles`.** It is site-wide: it would open the
> profile — name, phone, id number, email — of **every resident of every
> condominium**, which is precisely what this feature exists to prevent.

What works instead is to keep the query on nodes: a **View over `vivienda`
nodes**, with a relationship to the owner and occupant users, printing their
fields as columns. Because the base table is `node`, that view carries the
`node_access` tag and inherits the condominium filter for free — with
*«Disable SQL rewriting»* left unticked, as always.

Until that view exists, what the role gets is the unit node itself: opening a
`vivienda` shows its owner and occupant as rendered reference fields (the
names), which needs no extra permission. Following those links 403s.

Three guards run before anything is altered: the user holds the role; the query
really selects from the `node` table (the tag does **not** imply it — other
modules apply it to taxonomy, search or other entities, and altering those
would break unrelated pages with an SQL error); and the user has at least one
condominium assigned.

No query of this module carries the `node_access` tag, so the `api/v1/...`
endpoints are untouched.

> **`/admin/content` is served by a Views view on this site.** Views queries
> carry the `node_access` tag **unless** the view has *«Disable SQL rewriting»*
> ticked. If a building admin sees the whole site's content in that listing
> while direct URLs still answer 403, that checkbox is the cause: untick it in
> the view. Do not add code to compensate.

---

## Bulletins: only `Condominio`, only yours

`hook_node_validate()` blocks a building admin from saving a `boletin` that is
not of scope **`Condominio`**, and from picking a `field_condominio` outside
their assignment. Both produce a form error and the node is not created.

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
- **The claims-and-suggestions bundle (`reclamo`) does not exist yet.** Its
  permissions are granted the moment it does and `myapi_update_7012()` is run
  again. But it is **not** in the condominium map, so until whoever creates that
  bundle adds its entry there, its nodes are outside the rule in both halves of
  the filter — visible in the listings and not 403 on a direct URL. Adding the
  map entry is the last step of that bundle's own spec.
- **Deleting a `condominio` node** leaves a dangling reference in
  `field_condominio_admin` and the building admin silently loses access to it.
  Rare in production; the broken reference is visible at `/user/N/edit`.
