# Provider role (`proveedor`)

A role for the person who operates **one or more providers** of the services
marketplace (SPEC 77). It has **no back office**: it authenticates against the
same Drupal site as every other role, but everything it does happens through
the Flutter app — this role's whole job is to make sure that, if it is ever
logged in against `/node/N` directly, it can only reach **its own provider,
its own offers and ratings, and the service requests of its own categories**.

**No `api/v1/...` endpoint is created or authorized by this role.** See
*What this role does NOT protect* below — it is the single most important
thing to understand about SPEC 78.

---

## Files

| File | Role |
|------|------|
| `includes/myapi.provider_role.inc` | Everything: the role name and the `field_provider_users` constants, the scope map, the (empty) permission catalogue, the pure decision logic (`myapi_provider_role_access_decision()`, `myapi_provider_role_request_visible()`, `myapi_provider_role_broadcast_statuses()`), the Drupal-facing resolvers (`provider_ids()`, `category_ids()`, `any_provider_active()`, `offered_request_ids()`), `myapi_provider_role_node_decision()` and `myapi_provider_role_alter_node_query()`. |
| `includes/myapi.building_admin.inc` | **Touched by this spec**, not just reused: `myapi_building_admin_alter_node_query()` gained an `$exempt_types` parameter so it composes safely with the provider alter on the same query. See *Why building-admin's own file changed* below. |
| `myapi.module` | Glue only: `myapi_node_access()` now consults both roles (deny if either denies), and `myapi_query_node_access_alter()` runs both alters, each given the other's own domain as `$exempt_types`. |
| `myapi.install` | `_myapi_provider_role_install()` — the role and its (empty) permissions — called from `hook_install()` and from `myapi_update_7026()`. |
| `tests/unit/ProviderRoleTest.php` | Unit tests of the catalogues and of every pure decision. |

---

## Deployment

```bash
drush updb    # 7026: creates the role
drush cc all  # picks up includes/myapi.provider_role.inc of files[]
```

Both are needed, for the same reason as every role this module ships: without
`updb` there is no role at all; without `cc all` Drupal does not see the new
`.inc` and the hooks fatal on the first node access check.

Every update is **idempotent**: running it twice never creates a second role.

Uninstalling the module (`drush pm-uninstall myapi`) leaves the role intact —
the same conservative criterion as `administrador edificio`.

---

## What the installer creates

### The role

One row in `role` with `name = 'proveedor'`, in lower case. The `rid` is
assigned by Drupal per environment and **the code never references it** —
every check compares role *names*.

The role is created **empty**. Assigning it to a person, and linking a
`provider` node to that person via `field_provider_users`, are both manual.

### The permissions

**None, on purpose — this is not a placeholder.** The app writes with
`node_save()` and reads with `db_select()` queries that carry no
`node_access` tag, so the role does not need a single Drupal permission for
the app to work. In particular, `create service_offer content` is
**deliberately withheld**: granting it would open `node/add/service_offer`,
and a provider creating an offer through that form would skip the API's
uniqueness check, its enablement check and its request-status check — all of
which live in the (future) endpoint, not in Drupal's form validation.

`myapi_provider_role_permissions()` exists anyway, returning an empty list,
for the day this decision changes: it is the one place something would be
added, the installer is already wired to grant whatever it returns, and a
unit test fails if a `delete ...` permission is ever added to it.

At `/admin/people/permissions`, the `proveedor` column is **completely
empty**.

---

## Assigning the role to a person

1. `/admin/people` → edit the user.
2. Tick **`proveedor`** under *Roles*.
3. On the `provider` node the person operates, add their account to
   **`field_provider_users`** (`/node/N/edit`). This is the field that makes
   `myapi_provider_role_provider_ids()` resolve user → provider; nothing
   validates that a user in this field actually holds the role, or vice
   versa — see *Known gaps* below.
4. Save both.

A user can be linked to **more than one** `provider` node: `field_provider_users`
has unlimited cardinality on the provider side, and nothing in this module
enforces 1:1. `myapi_provider_role_provider_ids()` always returns a list, and
the filter below treats it as a **union** — see *Two or more providers*
below.

> **A user with the role and no provider linked sees nothing**: a 403 on
> every node of the five bundles. That is the designed behaviour, not a bug
> — the linkage *is* the permission, the same criterion
> `docs/building-admin-role.md` documents for "no condominium assigned".

> **A user who also holds `administrator` sees everything.** `bypass node
> access` returns before `myapi_node_access()` is ever invoked. Test with an
> account holding **only** `proveedor`.

---

## What each of the five bundles requires to be visible

`myapi_provider_role_scope_map()` is the single source of truth. Four modes:

| Bundle | Mode | Visible when |
|---|---|---|
| `provider` | `self` | Its own `nid` is one of the account's providers. |
| `service_offer` | `own` | `field_provider` is one of the account's providers. |
| `service_rating` | `own` | `field_rating_provider` is one of the account's providers. |
| `service_request` | `category` | See the rule below. |
| `service_transaction` | `via_request` | Inherited from the `service_request` that `field_request` points at — same two-hop shape as `claim_transaction`'s `via_claim` mode in `myapi_building_admin_condominium_map()`. |

**A type not in this map is out of the rule entirely.** A provider who also
lives in the building sees bulletins, their unit and their receipts exactly
as before — nothing here touches those.

The decision **only ever denies**. It never returns `NODE_ACCESS_ALLOW`:
granting there would short-circuit every other check Drupal makes and turn a
scope filter into a permission escalation. A unit test fails the day a
branch returns `allow`.

### The `service_request` rule

A request is visible when **either** holds:

1. **The account already offered on it** — one of its providers has a
   `service_offer` pointing at that request. Does **not** depend on status
   or enablement: what was already touched stays visible, including the
   history of a closed or cancelled request. This is also what a future
   "my offers" screen in the app will need.
2. **It concerns them now** — all three at once: the request's category is
   one of the account's providers' categories; the status is one of
   `myapi_provider_role_broadcast_statuses()` — `open`, `direct` or
   `offered`; and the account has at least one **active** provider
   (`myapi_services_provider_is_active()` — published, licence not expired).

A request in `assigned`, `closed` or `cancelled`, of a matching category, in
which the account did not offer, is **not** visible — it is not theirs and
there is nothing left to do with it.

**`direct` is on the visible side** (SPEC 87): a request born with a provider
already chosen is still broadcast to every provider of its category, exactly
like an open one. It is **not** narrowed to the chosen provider — the rule reads
the status and the categories, and `field_assigned_provider` is not one of its
inputs. Narrowing it belongs to the spec of the flow that writes that field, and
would be a second condition on top of this one.

The three statuses live in **one** function, `myapi_provider_role_broadcast_statuses()`,
read by both halves of the filter: the pure decision
(`myapi_provider_role_request_visible()`, for the direct-URL check) and the SQL
condition of `myapi_provider_role_visible_request_ids()` (for the listings). They
used to be two copies of the same list, and a status added to one and not the
other makes a request reachable by URL and absent from every listing — or the
reverse — with no error anywhere. A unit test pins that both read the function.

### Two or more providers

If an account operates two providers, `myapi_provider_role_category_ids()`
returns the **union** of both providers' categories, and
`myapi_provider_role_any_provider_active()` is a single account-level flag:
**true if at least one of the two is active**. So with provider A (Limpieza,
active) and provider B (Mantenimiento, expired), the account keeps browsing
open Mantenimiento requests too, as long as A is active — the account-level
flag does not distinguish per category.

This is a deliberate simplification, not an oversight: the spec's own
"Decisiones" table leaves the exact multi-provider interaction open
("puede ser deseable o puede ser un error de datos"), and every acceptance
criterion of SPEC 78 is single-provider. If the client later decides
1-account-1-provider is the rule, `field_provider_users` needs a uniqueness
validation it does not have today — see *Known gaps*.

---

## The two halves of the filter

### 1. `myapi_node_access()` — the direct URL

Runs per node, for `view`, `update` and `delete`, and now consults **both**
role decisions: it returns `NODE_ACCESS_DENY` the moment **either**
`administrador edificio`'s or `proveedor`'s own decision denies. An account
holding both roles gets the intersection — whatever either role alone would
deny it, it is denied.

`create` is not covered — Drupal does not invoke this hook for it — but it
does not need to be: the role is granted no `create ...` permission on any
of the five bundles, so `node/add/service_offer` and the rest already answer
403 through Drupal's own permission check, before this hook would matter.

### 2. `myapi_query_node_access_alter()` — the listings

Reaches every query tagged `node_access` — `/admin/content`, blocks, search,
`entityreference` autocompletes — not only `/admin/content`. With no
`create`/`edit`/`access content overview` permission granted to this role,
it opens **none** of those screens today, so this half has almost no surface
to exercise. It is implemented anyway, for the same reason
`docs/building-admin-role.md` gives: a filter with only one of its two
halves is exactly the bug SPEC 49 documented, and the tag is not only
`/admin/content`'s.

### Why building-admin's own file changed

**This is the part of SPEC 78 most worth reading carefully before touching
either alter again.**

`myapi_building_admin_alter_node_query()` and
`myapi_provider_role_alter_node_query()` are each a **complete allowlist** of
their own domain of node types — building-admin recognizes 7 types,
provider recognizes 5, and the two sets never overlap. Running both,
unqualified, on the *same* query would `AND` two disjoint allowlists
together — which collapses to nothing, for every row, regardless of type.
An account holding both roles would see an **empty** `/admin/content`, not a
narrowed one.

The fix is the `$exempt_types` parameter both functions now take:
`myapi_query_node_access_alter()` calls each one with the *other* role's own
recognized types as the exemption list. Internally, each alter's restriction
is wrapped in `db_or($node_alias.type IN $exempt_types, <its own rule>)` —
a pure veto within its own domain, a no-op for the other role's domain, and
still a full exclusion for a type **neither** role recognizes (so a solo
account never gains visibility into unrelated site content it did not have
before).

One more trap this uncovered, worth knowing if either alter is touched
again: **a `db_or()`/`db_and()` group with zero sub-conditions compiles to
nothing in Drupal 7 — it restricts nothing, not "always false".** The
"nothing assigned" guard of both alters used to close the query with a hard
`WHERE 1 = 0`; naively rewriting that as `$query->condition($gate)` breaks
the moment `$gate` has no exempted type and no branch added, because an
empty group is silently a no-op. Both functions keep the hard `1 = 0` for
exactly that case (`$exempt_types` empty) and only use the `$gate` group
when there is another role's domain to defer to.

No query of this module carries the `node_access` tag, so the `api/v1/...`
endpoints are untouched by either alter.

---

## What this role does NOT protect

**The `api/v1/...` endpoints of this module do not go through `node_access`.**
Verified in the code itself: `resources/claim.resource.inc` states its
queries deliberately carry no `node_access` tag, and
`myapi_query_node_access_alter()`'s own docblock confirms it for the whole
module. `node_save()` does not check permissions either.

Three consequences:

1. **This role does not authorize the API.** When the endpoints spec
   arrives, its authorization will be written there, explicit and testable
   — this role does not anticipate it or replace it.
2. **What the role is actually for**: (a) it is the marker the API layer
   will read to know a token belongs to a provider, and (b) it closes the
   back office, which is a real surface — a provider has a Drupal username
   and password and can reach `/node/N` directly.
3. **The back-office surface exists because `access content` is not granted
   by this role** — it is inherited from *authenticated user*, like any
   resident. That is why "grant nothing" is not the same as "deny nothing":
   without the explicit denial this role adds, a provider with a Drupal
   session could read any building's service request by direct URL.

---

## Maintenance rules

- **The restriction lives in the node access layer, not in the database.**
  Any direct SQL query ignores it. Any new back-office screen must go
  through `myapi_provider_role_node_decision()` or `node_access()`.
- **Adding a bundle to the role's scope** means editing
  `myapi_provider_role_scope_map()`, and, if its listing alter needs a real
  join, `myapi_provider_role_alter_node_query()`. Nothing else.
- **Never grant `create service_offer content`, or any `create`/`edit any`
  permission on the five bundles, without also writing the validation the
  API would otherwise skip.** See *The permissions* above.
- **Nothing validates that a `field_provider_users` account holds the
  `proveedor` role, or vice versa.** An operator can link an account and
  forget to assign the role — the symptom is an app that shows nothing, not
  an error pointing at the cause. Left for the endpoints spec, which should
  consider a `hook_requirements()` warning in the line of the one
  `docs/building-admin-role.md` already has for its own role's permission.
- **If `field_provider_users` is ever made 1:1**, add a uniqueness
  validation on save — nothing enforces it today, and
  `myapi_provider_role_category_ids()` / `myapi_provider_role_any_provider_active()`
  would need to stop treating it as a union. See *Two or more providers*
  above.
- **`service_transaction` is deliberately absent from
  `myapi_building_admin_condominium_map()`** — same warning
  `docs/services-install.md` already carries for the building-admin role: if
  a future spec grants that role permissions on the bundle without first
  adding a two-hop mode, the timeline of every building becomes visible to
  every building operator.
