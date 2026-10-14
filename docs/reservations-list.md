# Reservations listing (back office)

A read-only, server-rendered, paginated table of `reservation` nodes, at
**`admin/content/reservations`** (SPEC 131). It is the list companion of the
calendar at `admin/content/reservation-calendar` (SPEC 47): the calendar
answers "what does this week look like", the listing answers "find me that
booking".

It is a page for the operator, not an API endpoint: it returns HTML, never the
JSON envelope, and **no endpoint under `api/v1/` is involved or changed by
it**. It never creates, edits or cancels anything: the ID of each row links to
Drupal's own `node/<nid>/edit` (or `node/<nid>`), and there is no AJAX.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry, `myapi_reservations_admin_roles()`, `myapi_reservations_admin_access()`. Glue only. |
| `includes/myapi.reservations_admin.inc` | The whole page: GET-parameter validation, the single query, the filter form, the table and its row labels. Loaded by the `file` key of the menu entry. |
| `includes/myapi.reservation_calendar.inc` | Reused, not duplicated: `myapi_calendar_condominium_scope()`, `_positive_int()`, `_effective_condominium()`, `_condominium_options()`, `_area_options()`, `_user_name_label()` and `myapi_reservation_calendar_filter_form_after_build()`. |
| `includes/myapi.claims_admin.inc` | Reused: `myapi_claims_field_allowed_values()` reads the labels of `field_reservation_status` and `field_cancelled_by` from the fields themselves. This page declares no label of its own. |
| `css/myapi.claims.css` | The shared filter row, under `.myapi-reservations-filters`. Attached from the page callback, never from `myapi.info`. |

After deploying, run `drush cc all`. **`drush updb` is not needed**: there is
no new table, field or permission.

---

## Access control

| Role | Gets in | Rows it sees |
|---|---|---|
| `administrator` | Yes | All |
| `backend` | Yes | All |
| `administrador edificio` | Yes | Only the reservations of its assigned condominiums |
| `uid 1` | Yes, always | All |
| Anybody else | **403** | — |

Getting in is decided by **role only** (`myapi_reservations_admin_access()`,
same criterion and same `uid 1` guard as `myapi_claims_admin_access()`). An
`administrador edificio` with no condominium assigned gets in and sees an empty
table, not a 403.

**What an `administrador edificio` sees is decided by the query's
`->addTag('node_access')`**, through the `direct` / `field_condominium` entry
`reservation` has had in `myapi_building_admin_condominium_map()` since SPEC
49. No catalogue of the role changes for this page. A `?condominium=` of
somebody else's building is treated as no selection and still shows only their
own.

---

## GET parameters

All filters combine with AND and survive the pager. **Garbage in any of them
means no filter on it**, never an error or a message.

| Parameter | Accepted values | If invalid |
|---|---|---|
| `condominium` | Positive integer, a `condominio` nid. For an `administrador edificio`, only one of its assigned ones (`myapi_calendar_effective_condominium()`) | No filter |
| `area` | Positive integer that is one of the areas of the effective condominium (`myapi_calendar_area_options()`) | No filter. With no condominium selected the area select is disabled and `?area=` is ignored |
| `status` | A key of `field_reservation_status`' allowed_values: `confirmed`, `cancelled` | No filter |
| `date_from` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | Ignored |
| `date_to` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | Ignored |
| `page` | Drupal's pager | — |

**The date range filters `field_date`, the day OF THE BOOKING, never the
creation date.** Both bounds are inclusive and independent: the day of
`field_date` (a datetime stored as `Y-m-d H:i:s`) is compared with
`SUBSTR(..., 1, 10)`, which is the same whole-day reading as the
`00:00:00` / `23:59:59` bounds of the other listings. A reservation with no
date is excluded when a bound is set and listed when none is.

---

## Query

`n.type = 'reservation'`, `n.status = 1`, `->addTag('node_access')`,
`->extend('PagerDefault')->limit(20)`. **Every join is `LEFT`**: a reservation
with no date, a deleted area or a deleted requester keeps its row and shows `—`
in that cell. No join can multiply rows — every field joined has cardinality 1.

**Order:** `field_date DESC`, `field_start_time DESC`, `n.nid DESC` — the
furthest booking on top (decision 6 of SPEC 131). The `Creada` column shows the
creation date anyway.

---

## Columns

| # | Column | Source | When missing |
|---|---|---|---|
| 1 | ID | `n.nid`, linked (see below) | — |
| 2 | Fecha | `field_date`, `d/m/Y` | `—` |
| 3 | Horario | `field_start_time` – `field_end_time`, as stored (`HH:MM – HH:MM`; an overnight booking reads `22:00 – 02:00`) | `—` when either end is missing |
| 4 | Área | the area's title | `—` |
| 5 | Condominio | the condominium's title | `—` |
| 6 | Vivienda | the unit's title | `—` |
| 7 | Solicitante | first + last name, or the account name when the profile has none (same rule as `myapi_calendar_user_name_label()`) | `Sin solicitante` / `Usuario eliminado (#uid)` |
| 8 | Estado | label of `field_reservation_status` (`Confirmada` / `Cancelada`) | `—`; a value outside the catalogue prints raw |
| 9 | Cancelación | `Usuario: <motivo>` / `Admin: <motivo>`; with no reason, only who | `—` |
| 10 | Creada | `n.created`, `d/m/Y H:i` | — |

Every cell is escaped. Empty table: *No hay resultados para los filtros
seleccionados.*

### The ID link

`node/<nid>/edit` when `node_access('update', $node)` allows it, `node/<nid>`
otherwise — resolved with **one** `node_load_multiple()` over the page's nids,
and short-circuited with no load at all for `bypass node access`.

---

## Tests

`tests/unit/ReservationsAdminPageTest.php` covers the pure helpers, the filter
validation and the query against the fixture database. The three that matter
most:

| Test | Fails when |
|---|---|
| `testTheListingQueryCarriesTheNodeAccessTag()` | the tag is lost — the page does not break, it silently lists every reservation of the site to a building admin |
| `testTheDateRangeFiltersTheReservationDateNotTheCreationDate()` | the range moves to `n.created` |
| `testEveryJoinIsLeft()` | a join becomes `INNER` and a broken reservation disappears from the screen where it would be found |

---

## Navigation menu

The page is reached from the *Navegación* block of the sidebar, as
**Listado de reservas**. That link is site configuration, not part of the
module — see `docs/produccion-checklist.md`.
