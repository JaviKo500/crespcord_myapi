# Reservation form — dependent selectors and business rules

The back-office node form of a `reservation` (`node/add/reservation`,
`node/<nid>/edit`) chains its four entity-reference selectors (SPEC 134):

```
condominium ─┬─> unit ──> requester
             └─> area
```

- **Vivienda** and **Área** only offer the units and areas of the picked
  condominium.
- **Solicitante** only offers the owner and occupants of the picked **unit**
  (not every resident of the condominium).
- A selector is **disabled** until its parent holds a value, and is **emptied**
  when its parent changes.

It applies to every role, on create and on edit. `POST /api/v1/reservations`
is not involved: the endpoint saves its node programmatically.

> The selectors are about *who and where*. *When* — overlap, the area's
> capacity, its opening hours, a date in the past — is the
> [business rules](#business-rules-on-save) section below (SPEC 135).

---

## Files

| File | Role |
|---|---|
| `myapi.module` | The route, `myapi_reservation_form_access()`, `hook_form_reservation_node_form_alter()` and the `reservation` branch of `hook_node_validate()`. Glue only. |
| `includes/myapi.reservation_form.inc` | The chain, the form alter, the autocomplete callback with its queries, and the validation. |
| `includes/myapi.response.inc` | `myapi_respond_autocomplete()` — the only non-envelope response of the module. |
| `js/myapi.reservation_form.js` | Re-points, empties and enables/disables the selectors. Attached through the form's `#attached`, never from `myapi.info`. |
| `tests/unit/ReservationFormTest.php` | Both halves of the include under the test stubs, plus the route's access callback. The JavaScript is not covered. |
| `includes/myapi.reservation_rules.inc` | The business rules of a reservation, shared by this form and by `POST /api/v1/reservations` (SPEC 135). |
| `includes/myapi.reservation_admin.inc` | `myapi_reservation_validate_rules()`: applies those rules to the form. Also the HH:MM format of SPEC 52. |
| `tests/unit/ReservationAdminRulesTest.php` | The shared rules and their application to the form. |

---

## Three layers

1. **The form** — `myapi_reservation_form_alter()` tags the four inputs with
   `data-myapi-reservation-selector` and replaces the autocomplete path of the
   three dependent ones with this module's route. If any of the four is not a
   single-value `entityreference_autocomplete` widget (somebody changed it from
   the field UI), the form is left untouched; the validation still applies.
2. **The suggestions** — the route below, scoped to the parent id in the path.
3. **The save** — `myapi_reservation_form_validate_scope()`, reached from
   `hook_node_validate()`. A hand-crafted POST or a browser without JavaScript
   never sees layers 1 and 2.

| Error | Message |
|---|---|
| Unit not in the condominium | *La vivienda no pertenece al condominio seleccionado.* |
| Area not in the condominium | *El área no pertenece al condominio seleccionado.* |
| Requester neither owner nor occupant of the unit | *El solicitante debe ser propietario u ocupante de la vivienda seleccionada.* |

**On an edit the validation only runs when one of the four references
changed.** Cancelling a reservation — the everyday edit — must not be refused
because its requester has since moved out of the unit.

---

## GET admin/content/reservations/autocomplete/{selector}/{parent}/{text}

Suggestions for one dependent selector. Asked by Drupal's
`misc/autocomplete.js`, not by the app.

**Authentication:** Drupal session with `create reservation content`,
`edit any reservation content` or `edit own reservation content`.

| Segment | Value |
|---|---|
| `selector` | `unit`, `area` or `requester` |
| `parent` | Condominium nid for `unit` and `area`; unit nid for `requester` |
| `text` | What the operator typed; matched as "contains" |

**Success response (200)** — Drupal's autocomplete shape, **not** the API
envelope: value written into the input => HTML shown in the list. At most 10.

```json
{ "Motora 2 - PRUEBAS MOTORA (30057)": "<div class=\"reference-autocomplete\">Motora 2 - PRUEBAS MOTORA</div>" }
```

An unknown selector, a parent of `0`, an empty text or no match all answer
`{}`. There is no error body.

| What is offered | Rule |
|---|---|
| `unit` | Published `vivienda` nodes whose `field_condominio` is the parent; title contains the text |
| `area` | Published `area` nodes whose `field_condominium` is the parent; title contains the text |
| `requester` | Active users in `field_propietario`, `field_ocupante` or `field_ocupantes` of the parent unit; user name contains the text. The e-mail is neither matched nor returned |

**Possible errors**
| Code | When |
|------|------|
| 403  | No permission to create or edit reservations |

### Other roles

The route is open to any role holding a reservation permission, and only
building admins are narrowed. In production (checked 2026-11-24) only `backend`
and `administrador edificio` hold one; `supervisor` and `pagos` hold none, and
granting it to them is pending the client's confirmation (SPEC 135). The day
they get it, nothing narrows the queries for them. That is why `requester`
answers a user name and a uid and nothing else — what entityreference's own
autocomplete already exposed.

### Building admins

The node queries carry the `node_access` tag, so the narrowing of SPEC 49
applies on top: an `administrador edificio` asking for a condominium, or for
the people of a unit, outside their assignment gets `{}`.
`myapi_building_admin_validate_reservation()` (SPEC 51) keeps running after
this validation.

---

## Business rules on save

Since SPEC 135 a reservation saved from this form respects the same rules as
one created with `POST /api/v1/reservations` ([reservation.md](reservation.md),
validations 1–7). Both read them from `includes/myapi.reservation_rules.inc`.

**The only rule the form skips is the balance (validation 8):** an operator
may book for a unit in debt. Nothing else is skipped, for any role —
`administrator`, `backend`, `administrador edificio`, `supervisor` and `pagos`
are all validated alike. Which of them can open the form at all is decided by
Drupal's node permissions, not by this module: today `backend` and
`administrador edificio` (and `administrator`).

| # | Rule | Input flagged | Message |
|---|---|---|---|
| 1 | The **requester** matches the area's `who_can_reserve` (`owner` / `tenant`; any other value allows both) | Solicitante | *El solicitante no puede reservar esta área: el área limita quién reserva (solo propietarios o solo inquilinos).* |
| 2 | The area's status is `active` | Área | *El área no está activa y no admite reservas.* |
| 3 | Date + start time is not in the past | Fecha | *La fecha y la hora de inicio de la reserva ya pasaron.* |
| 4 | The range does not cross midnight in a same-day area | Hora de fin | *La reserva cruzaría la medianoche y el área cierra el mismo día.* |
| 4 | The range is inside the area's opening hours (an area with no hours fails closed) | Hora de inicio | *El horario de la reserva está fuera del horario de atención del área.* |
| 5 | The duration does not exceed the area's maximum | Hora de fin | *La duración de la reserva excede la duración máxima permitida para el área.* |
| 6 | The area's concurrent capacity is not exceeded | Hora de inicio | *El horario se cruza con una reserva existente del área.* (capacity 1) / *El área ya alcanzó su cupo de reservas simultáneas para ese horario.* |
| 7 | The unit has no other active `confirmed` reservation for the area | Vivienda | *La vivienda ya tiene una reserva activa para esta área.* |
| 8 | Balance of the unit | — | **Not checked.** |

**When they run**

- Only when the three earlier validations (HH:MM format, selectors, building
  admin scope) raised nothing.
- On a **new** reservation saved as *Confirmada*: always.
- On an **edit**: only when the reservation is saved as *Confirmada* **and**
  the unit, area, requester, date, start, end or status changed. Cancelling a
  reservation, or saving an old one untouched, never trips a rule.
- An edit leaves its own stored copy out of rules 6 and 7.

**Fail closed.** When the rules apply and a value cannot be read — the date is
not `YYYY-MM-DD…`, a time is not `HH:MM`, the area is not an `area` node — the
save is refused and that input is flagged with *No se pudo leer este campo para
comprobar las reglas del área…*. A reservation is never saved as *Confirmada*
with the rules silently skipped.

**The area lock.** Rules 6 and 7 count stored reservations, so they are only
true while nobody else writes. The endpoint checks and saves inside one
transaction holding a row lock on the area node
(`myapi_reservation_lock_area()`). The form takes **that same lock** during
validation and keeps it until the node is written:

| Ends in | When |
|---|---|
| `myapi_node_insert()` / `myapi_node_update()` | The reservation was saved. |
| `myapi_reservation_validate_rules()` | Rule 6 or 7 refused it. |
| A shutdown function | The request validated and did not save: another module's error, or *Vista previa*. |

Two operators, or an operator and a resident on the app, saving the same slot
of the same area are served one after the other; the second one sees the first
one's reservation and is refused. The lock is only taken when every earlier
rule passed, and lasts one request.

**How the form differs from the endpoint**

- The role of rule 1 is the **requester's** relation to the unit, never the
  operator's Drupal role.
- **Fecha** is taken as typed: it is the stored clock day of the start. The
  endpoint receives the app's calendar day and normalises an early-morning
  start to `D+1` (SPEC 41); the form does not.
- The end is a field, not a duration. An end at or before the start means the
  next day, the stored convention of SPEC 41.
- One message per input: when two rules flag the same input, the first one is
  shown and the next save names what is left.
- No notification is sent for a reservation created here (unchanged, SPEC 48).

---

## Deploy

`drush cc all` — a new route, a new include and a new hook implementation.
No schema change, no `drush updb`.
