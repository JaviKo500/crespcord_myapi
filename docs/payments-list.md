# Payments report (back office)

A read-only, server-rendered, paginated table of `pagos` nodes, at
**`admin/content/payments`** (SPEC 132), with a total row and a CSV download of
the same filtered set at **`admin/content/payments/csv`**. It adds what the
*Reporte Pagos* View cannot give: a filter by **channel** (`App` / `Bot` /
`Back office`, SPEC 129/130), the real ID number in the Cédula columns, and an
export that matches the screen row for row.

The View is not touched and keeps working; retiring it is a later, operational
decision.

It is a page for the operator, not an API endpoint: it returns HTML (or a CSV
file), never the JSON envelope, and **no endpoint under `api/v1/` is involved or
changed by it**. It never creates or edits anything: the ID of each row links to
Drupal's own `node/<nid>/edit` (or `node/<nid>`), and there is no AJAX.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | Two `hook_menu()` entries, `myapi_payments_admin_roles()`, `myapi_payments_admin_access()`. Glue only. |
| `includes/myapi.payments_admin.inc` | The whole feature: GET-parameter validation, the default range, the single filtered query, the filter form, the table and its labels, the total, and the CSV. Loaded by the `file` key of both menu entries. |
| `includes/myapi.reservation_calendar.inc` | Reused: `myapi_calendar_condominium_options()`, `_positive_int()` and `myapi_reservation_calendar_filter_form_after_build()`. |
| `includes/myapi.claims_admin.inc` | Reused: `myapi_claims_field_allowed_values()` reads the catalogues of `field_estado_pago`, `field_forma_de_pago` and `field_canal` from the fields themselves. |
| `includes/myapi.payment_workflow.inc` | Reused: `myapi_payment_method_label()`, `myapi_payment_channel_label()`, `myapi_payment_date_label()` for the screen, and the `MYAPI_PAYMENT_CHANNEL_*` constants. |
| `css/myapi.claims.css` | The shared filter row, under `.myapi-payments-filters`, plus `.myapi-payments-actions` and `.myapi-payments-total`. Attached from the page callback, never from `myapi.info`. |

After deploying, run `drush cc all`. **`drush updb` is not needed**: there is
no new table, field or permission.

---

## Access control

| Role | Gets in |
|---|---|
| `administrator` | Yes |
| `backend` | Yes |
| `uid 1` | Yes, always |
| `administrador edificio` | **No — 403** |
| Anybody else | **403** |

The same rule guards both routes, the report and the CSV. Getting in is decided
by **role only** (`myapi_payments_admin_access()`, same criterion and same
`uid 1` guard as `myapi_claims_admin_access()`).

The `administrador edificio` is kept out on purpose (decision 3 of SPEC 132):
`docs/building-admin-role.md` keeps the financial back office away from that
role, and opening it would be a spec of its own. The access function is its own
and does not reuse `myapi_marketplace_admin_access()`, even though today both
answer the same: they answer different questions and must be free to diverge.

---

## GET parameters

All filters combine with AND and survive the pager. **Garbage in any of them
means no filter on it**, never an error or a message — an invented status,
`?condominium=-1`, `?date_from=2026-02-30`, `?channel[]=bot` or a 101-character
text are all silently ignored.

| Parameter | Accepted values | Applies to |
|---|---|---|
| `condominium` | Positive integer that is one of `myapi_calendar_condominium_options()` | The condominium **of the payment's unit** |
| `status` | A key of `field_estado_pago`: `Nuevo`, `Pendiente de verificar`, `Completado`, `Anulado` | `field_estado_pago` |
| `unit_name` | Text, trimmed, 1–100 characters | The unit's **title OR `field_nombre_vivienda`**, partial match |
| `owner_last_name` | Text, trimmed, 1–100 characters | The owner's `field_apellidos`, partial match |
| `occupant_last_name` | Text, trimmed, 1–100 characters | The occupant's `field_apellidos`, partial match |
| `payment_method` | A key of `field_forma_de_pago` (`Deposito`, `Efectivo`, `Cheque`, `Transferencia`, `PayPhone`, `Tarjeta`) | `field_forma_de_pago` |
| `channel` | `app`, `bot` or `backoffice` (the `MYAPI_PAYMENT_CHANNEL_*` constants), exact | `field_canal` |
| `date_from` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | `field_fecha_de_pago` |
| `date_to` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | `field_fecha_de_pago` |
| `page` | Drupal's pager | — |

Text matches go through `db_like()` and use `LIKE '%x%'`. Whether they ignore
case and accents depends on the column's collation (`utf8_general_ci` on a
standard Drupal 7 install), as in every other search of the module.

**The date range filters `field_fecha_de_pago`, the day OF THE PAYMENT, never
the creation date.** Both bounds are inclusive and independent. The field is a
`date` stored as an ISO string with a `T` (`2026-10-14T00:00:00`), so its first
ten characters are compared with `SUBSTR(..., 1, 10)` — the whole-day
`00:00:00` / `23:59:59` reading, and the same comparison
`myapi_payment_count()` makes. A payment with no date is excluded when a bound
is set and listed when none is.

### The default range: absent is not empty

| URL | Range applied |
|---|---|
| Neither `date_from` nor `date_to` present (first load, or the pager) | **Last 30 days**: today − 30 → today, in the site's calendar. The form shows both dates filled in. |
| Both present and empty (`?date_from=&date_to=`) — the operator cleared both and applied | **No limit** |
| Only one present | That bound alone; the other side is open |

The default is computed by `myapi_payments_admin_default_range(REQUEST_TIME)`, a
pure function, and it replicates what the View does: the first load and a CSV
with no filters never sweep the whole history.

---

## Query

One function, `myapi_payments_admin_build_query($filters)`, turns the filters
into SQL. The page, the total and the CSV all start from it and add only what
is theirs (projection and order, pager, `SUM`, batch range), so **the three can
never answer different sets**.

- `n.type = 'pagos'` and **no filter on `n.status`**: a payment's lifecycle
  lives in `field_estado_pago`. **All four states are listed, `Nuevo`
  included** — the API hides `Nuevo` from the resident, this report does not.
- `->addTag('node_access')`.
- **Every join is `LEFT`.** The condominium, the owner and the occupant are read
  **through the unit**: a payment whose unit was deleted keeps its row, with
  `Vivienda eliminada (#id)` and `—` in condominium, owner and occupant.
- No join can multiply rows: every field joined has cardinality 1 (checked on
  the live site for `field_propietario` and `field_ocupante`, step 0 of SPEC
  132).
- **Order:** `field_fecha_de_pago DESC`, `n.nid DESC`. The `nid` tiebreak
  makes the order total, which is what lets the CSV read in ranged batches
  without skipping or repeating a row.

---

## Columns

| # | Column | Source | When missing |
|---|---|---|---|
| 1 | ID | `n.nid`, linked (see below) | — |
| 2 | Vivienda | `field_nombre_vivienda`, else the title; linked to the unit | `—` / `Vivienda eliminada (#id)` |
| 3 | Condominio | the title of the unit's condominium | `—` |
| 4 | Propietario | `nombre apellidos`, else the account name | `—` / `Usuario eliminado (#uid)` |
| 5 | Cédula propietario | `field_cedula` of the owner — never the username or the e-mail, which is what the View shows | `—` |
| 6 | Ocupante | as Propietario | as Propietario |
| 7 | Cédula ocupante | `field_cedula` of the occupant | `—` |
| 8 | Fecha de pago | `d/m/Y` | `—` |
| 9 | Forma de pago | label of `field_forma_de_pago` | `—` |
| 10 | Banco | name of the `bancos` term | `—` |
| 11 | Referencia | escaped | `—` |
| 12 | Estado | label of `field_estado_pago` | `—`; a value outside the catalogue prints raw |
| 13 | Valor | `$1.234,56` | `—` |
| 14 | Canal | `App` / `Bot de WhatsApp` / `Back office` | `—` |
| 15 | Creado | `n.created`, `d/m/Y H:i` | — |

Every cell is escaped. Empty table: *No hay resultados para los filtros
seleccionados.*

`field_comprobante_ocr` is shown nowhere — it is long evidence text and is read
on the node. `Banco emisor` / `Banco destino` are **only in the CSV**.

### The ID link

`node/<nid>/edit` when the reader may edit the payment, `node/<nid>` otherwise.
`bypass node access` and `edit any pagos content` answer for every row with no
node load; anyone else is resolved with **one** `node_load_multiple()` over the
page's nids.

---

## Total

Under the table: **`Total: $X (N pagos)`** — the sum of `field_valor` and the
number of payments over **every filtered row, not only the visible page**.

> **⚠ With the status filter empty, the total mixes every state, `Anulado`
> included.** It reflects the filter and nothing else, with no hidden rule
> (decision 11). To total only what was collected, filter by
> `Estado = Completado`.

The total is one `SUM` / `COUNT` over the same filtered query, without the
pager. An empty result reads `Total: $0,00 (0 pagos)`.

---

## CSV — `admin/content/payments/csv`

The **Descargar CSV** button above the table links here with **the effective
filters** of the page: the default 30-day range is sent explicitly when it is
being applied, and "no limit" travels as `date_from=&date_to=`. The CSV reads
its own URL with the same code as the page, so **the file has exactly the rows
of the table, unpaged**.

| Aspect | Value |
|---|---|
| Headers | `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="pagos-YYYYmmdd-HHii.csv"`, plus `Cache-Control: private, no-store…` — the file is never cached |
| Encoding | UTF-8 **with BOM**, so Excel shows accents and `ñ` |
| Separator | `,` (the site uses a dot decimal) |
| Quoting | RFC 4180: `fputcsv()` with an empty escape character |
| First row | Column names |
| Columns | The 15 of the screen **plus Banco emisor and Banco destino** (17) |
| Values | Raw: the amount as `186.40` (no `$`, dot decimal), the payment date as `Y-m-d`, the creation date as `Y-m-d H:i`; state, method and channel as labels |
| Missing value | Empty cell, never `—` (a dash would break a `SUM` in the sheet) |
| Rows | Every filtered payment, no limit, read in **batches of 500** over the same ordered query |

**Formula injection.** References and names come from the app and the bot, i.e.
from residents. Any cell starting with `=`, `+`, `-`, `@`, a tab or a carriage
return gets a leading `'`, so the spreadsheet reads it as text:
a reference `=1+1` appears as `'=1+1`.

It does not go through `myapi_respond()`: this is not the JSON API.

---

## Risks

- **Personal data leaves the system.** The CSV carries residents' names and ID
  numbers and, once downloaded, is outside any control. Mitigation: access
  limited to `administrator` / `backend`, and the response is never cached.
  Treat the file accordingly. Auditing downloads (`watchdog`) is a possible
  improvement, not done.
- **Historical `backoffice` payments.** SPEC 129 marked every payment that
  existed before it as `backoffice`, because there was no reliable way to tell
  where it came from. `?channel=app` therefore only sees payments created after
  that spec; `?channel=backoffice` includes that whole history.
- **Query cost.** Around 25 `LEFT JOIN`s, plus an `OR LIKE` with a leading
  wildcard that uses no index. With the default 30-day range the set is small.
  A CSV with no date limit over the whole history can be slow. If it goes over
  `max_execution_time`, add `drupal_set_time_limit()` to the CSV callback.
- **`backend` + `proveedor` on one account.** `pagos` is not among the bundles
  the provider alter restricts, so the `node_access` tag should not narrow this
  report for such an account. Check it in the manual matrix.

---

## Tests

`tests/unit/PaymentsAdminPageTest.php` covers the filter validation, the
default range, every label, the screen row, the CSV row and its escaping, the
query against the fixture database, the total and the CSV stream. The ones that
matter most:

| Test | Fails when |
|---|---|
| `testAbsentDatesApplyTheDefaultRangeButEmptyOnesRemoveIt()` | "absent" and "empty" collapse: either every first load sweeps the whole history, or the operator can no longer remove the limit |
| `testTheCsvLinkCarriesTheEffectiveDates()` / `testTheCsvLinkKeepsANoLimitRangeAsEmptyDates()` | the CSV stops matching the table |
| `testTheTotalSumsEveryFilteredPayment()` | the total is computed over the page instead of the filtered set |
| `testTheTotalQueryIsAPureAggregate()` | the total query gets columns or an order and breaks under `ONLY_FULL_GROUP_BY` |
| `testFormulaTriggersAreNeutralised()` / `testTheCsvRowEscapesEveryCell()` | a resident-supplied cell runs as a formula in the operator's spreadsheet |
| `testTheCsvReadsEveryPaymentInBatchesOfFiveHundred()` | a large export is truncated or read in one unbounded query |
| `testEveryJoinIsLeft()` | a payment with a broken reference disappears from the report |

The page callback, the filter form and the CSV route callback are only assembly
around `drupal_get_form()`, `theme('pager')` and `php://output`, and are not
unit-tested.

---

## Navigation menu

The page is reached from the *Navegación* block of the sidebar, as
**Reporte de pagos**, under *Calificaciones de servicio*. Drupal hides the link
from anyone without access to the route, so an `administrador edificio` does not
see it. That link is site configuration, not part of the module — see
`docs/produccion-checklist.md`.
