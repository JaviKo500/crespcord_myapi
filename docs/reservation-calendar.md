# Reservation calendar (back office)

A read-only, server-rendered calendar of the existing reservations, at
**`admin/content/reservation-calendar`**. It is a page for the operator of the
condominium, not an API endpoint: it returns HTML, never the JSON envelope, and
**no endpoint under `api/v1/` is involved or changed by it** (SPEC 47).

It shows a month or a week grid, chips coloured by area, a legend, and a detail
panel per reservation. It never creates, edits, cancels or moves anything —
there is no drag & drop, no write path and no AJAX.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry, `hook_permission()`, `myapi_calendar_admin_roles()`, `myapi_calendar_access()`. Glue only. |
| `includes/myapi.reservation_calendar.inc` | The whole page: pure grid helpers, filters, rendering. Loaded by the `file` key of the menu entry, so it only reaches PHP on this route. |
| `includes/myapi.reservation_query.inc` | `myapi_reservation_calendar_rows()`, the single query of the visible range, next to the other shared reservation queries. |
| `css/myapi.calendar.css` | Grid, palette and detail panel. |
| `js/myapi.calendar.js` | Opens and closes the detail panel. Vanilla, no jQuery, no external library. |

The `.css` and the `.js` are attached from the page callback with
`drupal_add_css()` / `drupal_add_js()` and are **deliberately not declared in
`myapi.info`**: a `stylesheets[]` / `scripts[]` entry would ship them on every
page of the site, API responses included.

After adding or modifying any of this, run:

```bash
drush cc all
```

Without it Drupal does not see the new `.inc` of `files[]` nor the route, and
the page answers 404. There is no `drush updb` to run: this feature adds no
field, no table and no `hook_update_N`.

---

## Access control

Access is **by role name only**:

```php
function myapi_calendar_admin_roles() {
  return ['administrator', 'backend'];
}
```

- A user with the `administrator` role sees the calendar.
- A user with the `backend` role sees the calendar.
- `uid 1` always sees it, even with neither role. The guard is explicit because
  Drupal's superuser bypass lives inside `user_access()`, which this callback
  never calls.
- Everybody else — an authenticated user without those roles, and any anonymous
  visitor — gets a **403**. Not a blank page, not a redirect to the login form.

That function is the single source of truth for the list. Adding a third role
is editing that one line. Role **names** are compared, never rids: a rid is
assigned per environment, so the `4` of production may well be a different role
in local or on the client's site. `$user->roles` is `rid => name` in Drupal 7,
so `array_intersect()` already compares names.

### The permission does not grant access

`myapi_permission()` declares **`view reservation calendar`** ("Ver el
calendario de reservas") so the capability is visible at
`admin/people/permissions` under *My API*, which is where an administrator
looks for it.

> **Granting that permission to a role does NOT give that role access to the
> calendar.** The access callback does not read it. This is counter-intuitive
> and it is intentional: with an "either the permission or the role" rule,
> granting the permission to *authenticated user* by mistake would open the
> names of every resident to the whole site.

The permission is declared and never checked. If a role must see the calendar,
give it the role, not the permission.

### How to grant access to someone

1. Go to `admin/people/permissions/roles` and make sure a role named exactly
   `backend` exists. Create it if it does not.
2. Go to `admin/people`, edit the user, tick `backend`, save.
3. The user now sees `admin/content/reservation-calendar` under *Content* in
   the administration menu.

There is no `hook_update_N` granting anything: the client's site has its own
permission matrix and touching it from an update would be an invisible side
effect.

---

## GET parameters

Every filter travels in the query string, so any position of the calendar is a
URL that can be copied, pasted into another tab and shared. Nothing here ever
errors: a junk parameter falls back to its default.

| Parameter | Accepted values | Default | If invalid |
|---|---|---|---|
| `view` | `month`, `week` | `month` | Falls back to `month` |
| `date` | `YYYY-MM-DD`, validated with `myapi_reservation_valid_date()` | today (server date) | Falls back to today |
| `condominium` | Positive integer, a `condominio` nid | no filter | No filter |
| `area` | Positive integer, an `area` nid | no filter | No filter |
| `status` | `confirmed`, `cancelled`, `all` | `confirmed` | Falls back to `confirmed` |

Examples — all of them render a page, none of them error:

```
admin/content/reservation-calendar
admin/content/reservation-calendar?view=week&date=2026-08-15
admin/content/reservation-calendar?condominium=7&area=34&status=all
admin/content/reservation-calendar?view=diaria&date=hola&condominium=abc   -> current month, confirmed
```

The filter form holds four of them on a single row — condominium, area, status
and date — plus its button. **`view` is not a field of that form**: it is the
`Mes` / `Semana` switch at the right of the navigation bar, rendered as two
links, because a `<select>` outside its `<form>` would not be submitted with
it. The GET parameter is exactly the same either way.

The reference date is a native HTML5
`<input type="date">`, so the browser supplies its own calendar picker with no
extra module and no jQuery UI; a browser without support falls back to a plain
text box whose placeholder still shows the expected `AAAA-MM-DD` format. Either
way the value submitted is `YYYY-MM-DD`.

Notes on the two node parameters:

- The **area select is disabled while no condominium is selected**, and it only
  lists the areas of the selected one. The dependency is resolved by reloading
  over GET, not with AJAX, at the cost of one extra click when changing
  condominium.
- An `area` that does **not belong** to the `condominium` in the URL is
  **silently ignored** and the whole condominium is shown. That is either a
  hand-edited URL or a stale link, and showing more is the least surprising
  degradation. The check costs no extra query: the area options are already
  loaded scoped to the condominium.
- An `area` **without** a `condominium` in the URL is honoured, and the
  calendar shows that area across every condominium. Only a hand-made URL can
  reach that state, since the select is disabled.

`status` never reaches the database as a raw string: it is mapped to the list
of stored values the query receives (`all` → `['confirmed', 'cancelled']`).

The `‹`, `hoy` and `›` links are plain `<a href>`, with no JS and no form. They
move ±1 month in the month view and ±1 week in the week view, and they carry
condominium, area, status and view along. The month step is computed from the
1st of the month, so stepping back from a 31st cannot skip February. The
`Mes` / `Semana` switch sits at the right end of the same bar and keeps the
reference date and every filter, changing only `view`.

---

## What is painted

Only **published** reservations (`node.status = 1`) whose `field_date`,
`field_start_time`, `field_end_time` and `field_reservation_status` all exist.
A row missing any of those four cannot be placed on a grid, and the write path
already treats it as incoherent — hiding it here is what keeps the calendar and
the API telling the same story.

Cancelled reservations are **hidden by default**. With `status=cancelled` or
`status=all` they appear in grey with struck-through text, **never** in the
colour of their area (a cancelled booking painted in the area colour would read
as real occupancy), and they do not appear in the legend.

### Reservations crossing midnight

A reservation whose `end_time` is at or below its `start_time` ends the next
day (the rule of SPEC 41, unchanged). It is painted as **two chips**:

| Stored | Chip on D | Chip on D+1 |
|---|---|---|
| `10:00 → 12:00` | `10:00–12:00` | — |
| `22:00 → 02:00` | `22:00–02:00 +1` | `↳ 00:00–02:00`, dimmed |
| `20:00 → 00:00` | `20:00–00:00 +1` | **none** — the tail would have zero duration |
| `10:00 → 10:00` | `10:00–10:00 +1` | `↳ 00:00–10:00`, dimmed |

The dimmed chip with the `↳` prefix is the continuation: it says "this one
comes from yesterday". The `+1` mark on the first chip says it finishes the
next day.

The query runs from **one day before the grid** to the last day of the grid.
That extra day ahead is what keeps the tail of a reservation starting just
before the visible range inside it. The range is **not** widened at the other
end: the tail of a reservation starting on the last day of the grid falls
outside it, and there is nowhere to paint it.

### Month view

Weeks of exactly seven days, Monday first, from the Monday on or before the 1st
to the Sunday on or after the last day of the month — 4 to 6 weeks, always
containing every day of the month. Days outside the reference month are greyed
out.

A day shows **all** its reservations and the cell grows to fit them. There is no
`+N more`: a non-clickable one is a dead end, and a clickable one needs either
AJAX or a day view, both out of scope.

### Week view and lanes

A fixed `00:00`–`24:00` axis, 24 hourly rows, the same whatever the data. Chips
are positioned proportionally to the minute (`top` and `height` as a percentage
of 1440), not stacked inside the row of their starting hour — stacked, a 10:00
and a 10:45 booking would look identical.

Overlapping reservations share the width of the day column in **lanes**:

- Overlap uses the **half-open** criterion of SPEC 35/41/45:
  `start < other_end && end > other_start`. Two consecutive reservations,
  `10:00-11:00` and `11:00-12:00`, do **not** overlap and each takes the full
  width. If they can both be booked in an area of capacity 1, the view must not
  suggest they compete.
- Segments are grouped into connected clusters, and inside each cluster placed
  greedily in the lowest free lane. A lane is reused as soon as its previous
  segment ended.
- The lane count is **per cluster**, not per day: a triple overlap in the
  morning does not squeeze the afternoon chips to a third of the width.

The continuation chip of an overnight takes its lane in the D+1 column like any
other segment.

### Area colours

A fixed palette of 12 colours, index = `area nid % 12`, classes
`myapi-cal-c0` … `myapi-cal-c11` in `css/myapi.calendar.css`. Deterministic and
stable across environments, with no field, no migration and no configuration
UI. Two areas can share a colour when their nids are congruent modulo 12; the
legend is what tells them apart.

The index depends on the nid and not on the node, so a **deleted** area keeps
the colour it always had.

The legend lists one entry per distinct area with at least one **non-cancelled**
reservation visible in the grid, ordered by name (accents are folded for the
sort, so `Área` does not land after `Zona`).

---

## Detail panel

Clicking any chip opens a panel with: requester full name and username in
parentheses (e.g. `Juan Pérez (jperez)`), unit, area, condominium, date,
`HH:MM – HH:MM`, duration, status and creation date. A cancelled reservation
also shows `Cancelada por` and, right below it, `Motivo`; a confirmed one shows
neither line at all. The requester's email is never shown in the panel.

- The panel closes with the X, with a click on the backdrop and with `Escape`.
- Opening another detail closes the previous one; two panels are never open at
  once.
- The HTML of every panel is **already in the server response**. Opening one
  makes **no network request** of any kind — the Network tab stays empty. There
  is no new endpoint to secure and no state to keep in sync; the cost is a
  bigger HTML document, bounded by the row ceiling below.
- A reservation has exactly **one** panel even when it has two chips, and both
  chips open it.
- The duration and the `(+1 día)` mark are computed on the reservation, not on
  the chip that was clicked: opening the detail from the continuation chip of a
  `22:00 → 02:00` still reports `4h 0min`.
- **`Motivo`** (SPEC 50) is the optional cancellation reason, written either by
  the resident when cancelling from the app or by an operator in the
  `Motivo de cancelación` field of the node form. The line is drawn only when
  the reservation is cancelled **and** has a reason — same criterion as
  `Cancelada por` on a confirmed reservation: a line that does not apply is not
  drawn. It is free text written by a resident, so it is escaped with
  `check_plain()` at the point of output.

The panel shows personal data (the requester's full name and username). That
is deliberate and it is what the access control above is for: the page is
limited to the operator of the condominium. The email address is deliberately
left out, even from this restricted audience.

A **`Ver más`** button at the bottom of the panel links to the reservation's
own node page, `/?q=node/<nid>` (absolute URL, opens in a new tab). It is the
same node link the `reservation_created_admin` and
`reservation_cancelled_admin` emails carry — see
`docs/reservation-notifications.md`.

---

## Row ceiling

```php
define('MYAPI_CALENDAR_MAX_ROWS', 2000);
```

The query asks for `MYAPI_CALENDAR_MAX_ROWS + 1` rows; getting more than the
ceiling back is the signal that the range holds even more, with no extra
`COUNT()` query. The surplus is dropped and a **warning is shown on the page**
saying the view is incomplete and asking to narrow the filter — not a
`watchdog()` entry, which nobody would read before taking decisions on an
incomplete calendar.

Exactly `MYAPI_CALENDAR_MAX_ROWS` reservations produce **no** warning: the view
is complete.

It is a constant and not a `variable_get()`: there is no configuration form for
this page, so a variable would be an invisible lever nobody would find.
Changing the ceiling means editing that line and deploying.

---

## Deleted (orphan) data

The joins to area, unit, condominium and user are **left** joins, so a
reservation whose area or unit node was deleted does not disappear from the
calendar: it existed and it occupied a slot, and hiding it would falsify the
view. The label degrades instead, with the nid to investigate with:

| Data | Missing | Shown as |
|---|---|---|
| Area | `area_title` is `NULL` | `Área eliminada (#123)` |
| Area | no `field_area` at all | `Sin área` (neutral colour, outside the palette) |
| Unit | `unit_title` is `NULL` | `Vivienda eliminada (#456)` |
| Unit | no `field_unit` at all | `Sin vivienda` |
| User | `uid` present, account deleted | `Usuario eliminado (#789)` |
| User | no `field_requester` at all | `Sin usuario` |
| Condominium | nid not among the published ones | `Condominio no disponible (#99)` |

Every value coming from the database is printed through `check_plain()`, so an
area or unit title containing `<script>` or `&` is escaped. The JS never
injects HTML: it only toggles the `hidden` attribute of a block already
rendered on the server.

---

## Manual verification

There are unit tests for the six pure helpers (the grid arithmetic, the
overnight split and the lane assignment) in
`tests/unit/ReservationCalendarTest.php`. The query helper runs `db_select()`
and has none, same criterion as the rest of `tests/unit/`; its verification is
the matrix below.

```bash
drush cc all
```

**Access matrix** — same URL, `admin/content/reservation-calendar`:

| User | `view reservation calendar` | Expected |
|---|---|---|
| `uid 1` | not granted | Sees the calendar |
| Role `administrator` | not granted | Sees the calendar |
| Role `backend` | not granted | Sees the calendar |
| Authenticated, neither role | **granted** | **403** — the callback ignores the permission |
| Authenticated, neither role | not granted | 403 |
| Anonymous | — | 403 |

**Painting matrix** — with a test condominium and an area of capacity ≥ 2:

| Case | Data | Expected |
|---|---|---|
| Normal | `10:00 → 12:00` on D | One chip on D, area colour |
| Overnight | `22:00 → 02:00` on D | Chip on D with the `+1` mark, plus a dimmed chip on D+1 |
| Ends at midnight | `20:00 → 00:00` | **One** chip on D, no continuation |
| 24 h | `10:00 → 10:00` | Two chips; the detail says `24h 0min` |
| Extra day ahead | Overnight starting the day before the grid | Only the continuation chip, on the first day of the grid |
| Last day of the grid | Overnight starting on the last day | Normal chip with the `+1` mark; no continuation |
| Overlap | Two `10:00-12:00` in the same area | Week view: two lanes at half width |
| Back to back | `10:00-11:00` and `11:00-12:00` | Week view: two chips at **full width** |
| Cancelled, hidden | A `cancelled` in range, default filter | Does not appear |
| Cancelled, visible | Same with `status=all` | Grey struck-through chip, outside the legend |
| Cancelled with a reason | Same, with `Motivo de cancelación` filled | The detail shows `Motivo` right below `Cancelada por` |
| Cancelled without a reason | Same, field left empty | No `Motivo` line; the rest of the panel unchanged |
| Confirmed with a reason typed | Reason filled, status `confirmed` | Neither `Cancelada por` nor `Motivo`; the value stays stored |
| Unpublished | `node.status = 0` | Never appears, with any `status` |
| Deleted area | Delete the `area` node of a reservation | `Área eliminada (#nid)` in chip, legend and detail |
| Deleted user | Delete the requester account | `Usuario eliminado (#uid)` in the detail |
| Ceiling | Lower `MYAPI_CALENDAR_MAX_ROWS` to 5 in local | Visible "incomplete view" warning |
| Junk URL | `?view=diaria&date=hola&condominium=abc` | Current month, no error |
| Pasteable link | Copy the filtered URL into another tab | Same view, same filters |

**The API must not have moved.** These two responses have to be identical, key
by key, to what they returned before the calendar existed:

```bash
curl -s -H "Authorization: Bearer $TOKEN" "https://<host>/api/v1/areas/34/availability?date=2026-08-01"
curl -s -H "Authorization: Bearer $TOKEN" "https://<host>/api/v1/units/12/reservations"
```
