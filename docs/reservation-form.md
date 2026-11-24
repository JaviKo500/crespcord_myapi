# Reservation form — dependent selectors

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

> This is about *who and where*, not *when*. The form still does not check
> overlap, the area's capacity, its opening hours or a date in the past — see
> `includes/myapi.reservation_admin.inc`.

---

## Files

| File | Role |
|---|---|
| `myapi.module` | The route, `myapi_reservation_form_access()`, `hook_form_reservation_node_form_alter()` and the `reservation` branch of `hook_node_validate()`. Glue only. |
| `includes/myapi.reservation_form.inc` | The chain, the form alter, the autocomplete callback with its queries, and the validation. |
| `includes/myapi.response.inc` | `myapi_respond_autocomplete()` — the only non-envelope response of the module. |
| `js/myapi.reservation_form.js` | Re-points, empties and enables/disables the selectors. Attached through the form's `#attached`, never from `myapi.info`. |
| `tests/unit/ReservationFormTest.php` | Both halves of the include under the test stubs, plus the route's access callback. The JavaScript is not covered. |

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

On this site `supervisor` and `pagos` also hold a reservation permission, and
nothing narrows the queries for them. That is why `requester` answers a user
name and a uid and nothing else — what entityreference's own autocomplete
already exposed.

### Building admins

The node queries carry the `node_access` tag, so the narrowing of SPEC 49
applies on top: an `administrador edificio` asking for a condominium, or for
the people of a unit, outside their assignment gets `{}`.
`myapi_building_admin_validate_reservation()` (SPEC 51) keeps running after
this validation.

---

## Deploy

`drush cc all` — a new route, a new include and a new hook implementation.
No schema change, no `drush updb`.
