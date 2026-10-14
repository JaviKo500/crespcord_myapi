# Providers listing (back office)

A read-only, server-rendered, paginated directory of `provider` nodes, at
**`admin/content/providers`** (SPEC 131). It shows every provider of the
marketplace — **published or not** — with its categories, its users, its
licence and its rating, so the operator can supervise the marketplace without
opening nodes one by one.

It is a page for the operator, not an API endpoint: HTML, never the JSON
envelope, and **no endpoint under `api/v1/` is involved or changed by it**. It
never edits anything: the ID of each row links to Drupal's own
`node/<nid>/edit` (or `node/<nid>`), and there is no AJAX.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry, `myapi_marketplace_admin_roles()`, `myapi_marketplace_admin_access()` (shared with `admin/content/service-offers` and `admin/content/service-ratings`). Glue only. |
| `includes/myapi.providers_admin.inc` | The whole page: GET validation, the paged query, the two bounded queries of the multivalue fields, the situation rule, the filter form and the table. |
| `includes/myapi.service_requests_admin.inc` | Reused: `myapi_service_requests_category_options()` and `_effective_category()` — same vocabulary, same select. |
| `includes/myapi.user.inc` | Reused: `myapi_user_display_names()` names the provider's users the way every other screen does. |
| `css/myapi.claims.css` | The shared filter row (`.myapi-providers-filters`) and the situation pill (`.myapi-provider-situation-*`). |

After deploying, run `drush cc all`. `drush updb` is not needed.

---

## Access control

| Role | Gets in |
|---|---|
| `administrator`, `backend`, `uid 1` | Yes — every provider |
| `administrador edificio` | **403** |
| Anybody else | **403** |

By role only (`myapi_marketplace_admin_access()`). The `administrador edificio`
is deliberately out (decision 2 of SPEC 131): a provider serves every building
and has no condominium to be scoped to. Letting the role in would need an
indirect scoping mode that does not exist; if it is ever wanted, it is a spec of
its own.

### Known anomaly: `backend` + `proveedor` on the same account

The query carries `->addTag('node_access')`, and the alter of the `proveedor`
role narrows `provider`, `service_offer` and `service_rating` to that account's
own — those bundles are not in `myapi_building_admin_visible_types()`, the
exempt list it receives. An operator holding **both** roles therefore sees only
their own provider here. It is the same configuration anomaly as risk 1 of
SPEC 125 and the fix is operational: do not give `proveedor` to a back-office
account.

---

## GET parameters

Garbage in any of them means **no filter on it**, never an error.

| Parameter | Accepted values | If invalid |
|---|---|---|
| `category` | Positive integer that is a term of the `service_category` vocabulary | No filter |
| `situation` | `active`, `expiring`, `expired`, `suspended`, `no_license` | No filter |
| `page` | Drupal's pager | — |

The category filter is a **correlated `EXISTS`** over
`field_data_field_categories` — the same shape the marketplace endpoint uses
(`myapi_provider_category_exists()`, SPEC 83), equivalent to the
`n.nid IN (subquery)` of the spec. It never joins the multivalue table, so a
provider with three categories is still one row.

---

## Situation

Derived from SPEC 83's rule of the *active provider* — published **and**
`field_license_expiry >= REQUEST_TIME` — in this priority order:

| Situation | Label | Condition |
|---|---|---|
| `suspended` | Suspendido | `n.status = 0` |
| `no_license` | Sin habilitación | published, `field_license_expiry` empty |
| `expired` | Habilitación vencida | published, expiry `< now` |
| `expiring` | Vence pronto | published, `now <= expiry <= now + 30 days` |
| `active` | Activo | published, expiry `> now + 30 days` |

A licence expiring **exactly now** is still valid (`>=`), so it reads
*Vence pronto*, never *vencida*. Exactly 30 days out is still *Vence pronto*.

The rule is written twice on purpose: `myapi_providers_admin_situation()` labels
the cell, `myapi_providers_admin_situation_conditions()` builds the SQL of the
filter. Both take the current time as a parameter, and
`ProvidersAdminPageTest::testEachSituationFilterAgreesWithTheCell()` runs the two
over the same borders.

---

## Query

`n.type = 'provider'`, **no `n.status` condition** (decision 4: suspending a
provider is unpublishing it, and this is where the operator finds it again),
`->addTag('node_access')`, `->extend('PagerDefault')->limit(20)`. Order:
`n.title ASC`, `n.nid ASC`.

Only the cardinality-1 fields are joined, all `LEFT`: `field_phone`,
`field_license_expiry`, `field_rating_avg`, `field_rating_count`.

**`field_categories` and `field_provider_users` are never joined** (decision 5):
they would multiply rows and the pager would count them. Each is read in a
second query bounded to the nids of the page and grouped in PHP, in delta order.
`GROUP_CONCAT` was discarded: MySQL-only and capped by `group_concat_max_len`.

---

## Columns

| # | Column | Source | When missing |
|---|---|---|---|
| 1 | ID | `n.nid`, linked | — |
| 2 | Nombre comercial | `n.title` | the nid |
| 3 | Categorías | `field_categories` term names, comma separated | `—` (a deleted term is skipped) |
| 4 | Teléfono | `field_phone` | `—` |
| 5 | Usuarios asociados | `field_provider_users`, named by `myapi_user_display_names()` | `—`; a deleted account reads `Usuario eliminado (#uid)` |
| 6 | Habilitación vence | `field_license_expiry`, `d/m/Y` | `—` |
| 7 | Situación | see above, as a pill with class `myapi-provider-situation-<key>` | — |
| 8 | Calificación | `4,6 ★ (12)` from `field_rating_avg` / `field_rating_count` | `Sin calificaciones` (also for a zero count or a count with no average) |
| 9 | Alta | `n.created`, `d/m/Y` | — |

Every cell is escaped. Empty table: *No hay resultados para los filtros
seleccionados.*

The ID links to `node/<nid>/edit` when `node_access('update', $node)` allows it,
`node/<nid>` otherwise — one `node_load_multiple()` per page, none at all for
`bypass node access`.

---

## Tests

`tests/unit/ProvidersAdminPageTest.php`. The ones that matter most:

| Test | Fails when |
|---|---|
| `testALicenceExpiringExactlyNowIsNotExpired()` / `testTheThirtyDayBorder()` | a border moves |
| `testEachSituationFilterAgreesWithTheCell()` | the SQL filter and the PHP label stop agreeing |
| `testAnUnpublishedProviderIsListed()` | someone adds `n.status = 1` |
| `testTheMultivalueFieldsAreNeverJoinedToThePagedQuery()` | a multivalue field is joined and the pager starts counting duplicates |
| `testTheCategoryFilterDoesNotMultiplyRows()` | the category filter becomes a join |

---

## Navigation menu

Reached from the *Navegación* block of the sidebar as **Proveedores** — site
configuration, see `docs/produccion-checklist.md`.
