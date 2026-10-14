# Service ratings listing (back office)

A read-only, server-rendered, paginated table of `service_rating` nodes — the
stars and comments residents leave providers — at
**`admin/content/service-ratings`** (SPEC 131).

It is a page for the operator, not an API endpoint: HTML, never the JSON
envelope, and **no endpoint under `api/v1/` is involved or changed by it**. It
never edits anything, and there is no AJAX.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry; access is `myapi_marketplace_admin_access()`, shared with the provider and offer listings. |
| `includes/myapi.service_ratings_admin.inc` | The whole page: GET validation, the query, the filter form, the table. |
| `includes/myapi.services_common.inc` | Read, never redeclared: `myapi_services_star_values()`. |
| `includes/myapi.service_offers_admin.inc` | Reused: `myapi_service_offers_admin_provider_options()` and `_effective_option()` for the provider select. |
| `includes/myapi.service_requests_admin.inc` | Reused: `myapi_service_requests_created_range()`, the whole-day date bounds. |
| `css/myapi.claims.css` | The shared filter row, under `.myapi-service-ratings-filters`. |

After deploying, run `drush cc all`. `drush updb` is not needed.

---

## Access control

| Role | Gets in |
|---|---|
| `administrator`, `backend`, `uid 1` | Yes — every rating |
| `administrador edificio` | **403** |
| Anybody else | **403** |

By role only (`myapi_marketplace_admin_access()`). The `administrador edificio`
is out by decision 2 of SPEC 131: a rating has no condominium, and one of a
`direct` request cannot even be tied to a request.

### Known anomaly: `backend` + `proveedor` on the same account

The query carries `->addTag('node_access')`, and the alter of the `proveedor`
role narrows `service_rating` to that account's own provider. An operator
holding **both** roles sees only their own ratings here. Same configuration
anomaly as risk 1 of SPEC 125; the fix is operational — do not give
`proveedor` to a back-office account.

---

## GET parameters

All combine with AND and survive the pager. **Garbage means no filter on it.**

| Parameter | Accepted values | If invalid |
|---|---|---|
| `provider` | Positive integer that is a `provider` nid (published or not) | No filter |
| `stars` | A key of `myapi_services_star_values()`: `1` … `5` | No filter |
| `date_from` | `YYYY-MM-DD`; filters `n.created` from `00:00:00` | Ignored |
| `date_to` | `YYYY-MM-DD`; filters `n.created` up to `23:59:59` | Ignored |
| `page` | Drupal's pager | — |

---

## Query

`n.type = 'service_rating'`, `n.status = 1`, `->addTag('node_access')`,
`->extend('PagerDefault')->limit(20)`, ordered by `n.nid DESC`. **Every join is
`LEFT`** and every field joined has cardinality 1.

**The request is reached through the offer**: rating →
`field_rating_offer` → the offer's `field_request` → the request node. There is
no rating → request reference, so a rating of a `direct` request — which has no
offer (SPEC 87) — keeps its row with no request at all.

---

## Columns

| # | Column | Source | When missing |
|---|---|---|---|
| 1 | ID | `n.nid`, linked to `node/<nid>/edit` or `node/<nid>` | — |
| 2 | Fecha | `n.created`, `d/m/Y H:i` | — |
| 3 | Proveedor | `field_rating_provider` → the provider's title | `—` / `Proveedor eliminado (#id)` |
| 4 | Estrellas | `★★★★☆ (4)` | `—`; a value outside the catalogue prints raw |
| 5 | Comentario | `field_rating_comment`, escaped, cut to **140 characters** (ellipsis included) with `truncate_utf8()`; the full text in the `title` attribute | `—` |
| 6 | Solicitud | through the offer, linked to `admin/content/service-requests/<nid>` (SPEC 126) | **`Proveedor directo`** when there is no offer; `—` when the offer has no request; `Solicitud eliminada (#id)` when the request is gone |
| 7 | Vivienda | `field_unit` → the unit's title | `—` (see below) |

The comment is cut **before** escaping and by characters, not bytes: an entity
or a multibyte character is never split, and a `<script>` reads as text.

Empty table: *No hay resultados para los filtros seleccionados.* The ID link is
resolved with one `node_load_multiple()` per page, and none at all for
`bypass node access`.

---

## Known gaps

- **`Vivienda` is empty on almost every rating** (risk 2 of SPEC 131). SPEC 77
  created the `field_unit` instance and no flow writes it yet. The column is
  there because it is the right datum the day it is filled.
- **`direct` ratings have no path to their request** (risk 3). Adding a
  rating → request reference is a schema change, out of SPEC 131.
- **The provider select grows with the marketplace** (risk 4). If it passes a
  few hundred providers it should become an autocomplete.

---

## Tests

`tests/unit/ServiceRatingsAdminPageTest.php`. The ones that matter most:

| Test | Fails when |
|---|---|
| `testARatingWithNoOfferReadsDirectProvider()` | a `direct` rating stops saying `Proveedor directo` |
| `testEveryJoinIsLeftAndTheRequestIsReachedThroughTheOffer()` | the offer hop becomes `INNER` and `direct` ratings disappear |
| `testTheCommentIsEscapedAndCutWithoutBreakingAMultibyteCharacter()` | the comment stops being escaped, or is cut by bytes |

---

## Navigation menu

Reached from the *Navegación* block of the sidebar as **Calificaciones de
servicio** — site configuration, see `docs/produccion-checklist.md`.
