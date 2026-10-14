# Service offers listing (back office)

A read-only, server-rendered, paginated table of `service_offer` nodes — the
quotes providers send for service requests — at
**`admin/content/service-offers`** (SPEC 131).

It is a page for the operator, not an API endpoint: HTML, never the JSON
envelope, and **no endpoint under `api/v1/` is involved or changed by it**. It
never edits anything, and there is no AJAX.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry; access is `myapi_marketplace_admin_access()`, shared with the provider and rating listings. |
| `includes/myapi.service_offers_admin.inc` | The whole page: GET validation, the query, the filter form, the table. Also `myapi_service_offers_admin_provider_options()`, which the rating listing reuses for its provider select. |
| `includes/myapi.services_common.inc` | Read, never redeclared: `myapi_services_offer_statuses()` and `myapi_services_offer_amount_types()`. |
| `includes/myapi.service_requests_admin.inc` | Reused: `myapi_service_requests_created_range()`, the whole-day date bounds. |
| `includes/myapi.reservation_calendar.inc` | Reused: `_positive_int()`, `_condominium_options()` and the form's after-build. |
| `css/myapi.claims.css` | The shared filter row, under `.myapi-service-offers-filters`. |

After deploying, run `drush cc all`. `drush updb` is not needed.

---

## Access control

| Role | Gets in |
|---|---|
| `administrator`, `backend`, `uid 1` | Yes — every offer |
| `administrador edificio` | **403** |
| Anybody else | **403** |

By role only (`myapi_marketplace_admin_access()`). The `administrador edificio`
is out by decision 2 of SPEC 131: an offer has no condominium of its own, and
scoping one through its request is an indirect mode that does not exist.

### Known anomaly: `backend` + `proveedor` on the same account

The query carries `->addTag('node_access')`, and the alter of the `proveedor`
role narrows `service_offer` to that account's own offers. An operator holding
**both** roles sees only their own here. Same configuration anomaly as risk 1
of SPEC 125; the fix is operational — do not give `proveedor` to a back-office
account.

---

## GET parameters

All combine with AND and survive the pager. **Garbage means no filter on it.**

| Parameter | Accepted values | If invalid |
|---|---|---|
| `condominium` | Positive integer among `myapi_calendar_condominium_options()`. Filters by the condominium **of the request** | No filter |
| `provider` | Positive integer that is a `provider` nid (published or not) | No filter |
| `status` | A key of `myapi_services_offer_statuses()`: `sent`, `selected`, `rejected`, `withdrawn` | No filter |
| `date_from` | `YYYY-MM-DD`; filters `n.created` from `00:00:00` | Ignored |
| `date_to` | `YYYY-MM-DD`; filters `n.created` up to `23:59:59` | Ignored |
| `page` | Drupal's pager | — |

---

## Query

`n.type = 'service_offer'`, `n.status = 1`, `->addTag('node_access')`,
`->extend('PagerDefault')->limit(20)`, ordered by `n.nid DESC`. **Every join is
`LEFT`** and every field joined has cardinality 1.

**The condominium is the request's**: `field_condominium` is joined on
`field_request_target_id`, never on the offer's own nid — an offer has no
condominium.

The request and the provider are projected resolved **and raw**, so a label can
tell "no reference" from "a reference to something that is gone". The provider
join does not require it to be published: a suspended provider still has a
name.

---

## Columns

| # | Column | Source | When missing |
|---|---|---|---|
| 1 | ID | `n.nid`, linked to `node/<nid>/edit` or `node/<nid>` | — |
| 2 | Solicitud | request title, linked to **`admin/content/service-requests/<nid>`** (the supervision detail of SPEC 126) for every reader | `—` / `Solicitud eliminada (#id)`; an untitled request shows its nid |
| 3 | Condominio | the request's condominium | `—` |
| 4 | Proveedor | the provider's title | `—` / `Proveedor eliminado (#id)` |
| 5 | Monto | `1.500,00 (Precio cerrado)`: two decimals, comma decimal separator, no currency, plus the label of `myapi_services_offer_amount_types()`. **`on_site_quote` shows only its label, never an amount**, even if one sits in the column | `—` |
| 6 | Estado | label of `myapi_services_offer_statuses()` | `—`; a value outside the catalogue prints raw |
| 7 | Enviada | `n.created`, `d/m/Y H:i` | — |
| 8 | Válida hasta | `field_offer_valid_until`, `d/m/Y` | `—` |
| 9 | Último mensaje | `field_last_message_at`, `d/m/Y H:i` | `Sin chat` |

Every cell is escaped. Empty table: *No hay resultados para los filtros
seleccionados.*

The ID link is resolved with one `node_load_multiple()` per page, and none at
all for `bypass node access`.

---

## Tests

`tests/unit/ServiceOffersAdminPageTest.php`. The ones that matter most:

| Test | Fails when |
|---|---|
| `testTheRequestCellOpensTheSupervisionDetail()` | the Solicitud link stops pointing at SPEC 126's detail |
| `testAnOnSiteQuoteShowsNoAmount()` | an `on_site_quote` starts printing a price |
| `testTheCondominiumFilterReadsTheRequestsCondominium()` | the condominium is joined on the offer instead of its request |
| `testTheListingQueryCarriesTheNodeAccessTagAndThePager()` | the tag or the pager is lost |

---

## Navigation menu

Reached from the *Navegación* block of the sidebar as **Ofertas de
proveedores** — site configuration, see `docs/produccion-checklist.md`.
