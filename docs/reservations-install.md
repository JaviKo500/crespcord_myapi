# Reservations — content types installation

This module creates, on install and on update, the two content types the
reservations feature is built on — **Área** (`area`) and **Reserva**
(`reservation`) — together with every Field API field and instance they need.
Everything is created programmatically (`node_type_save()`,
`field_create_field()`, `field_create_instance()`); nothing is built by hand in
the admin UI.

There are **no custom SQL tables** for this feature (unlike `my_api_tokens`).
The bundles, the shared field and the per-bundle instances are Field API
configuration entities; Drupal generates the `field_data_*` / `field_revision_*`
tables automatically.

> **Dependencies.** `myapi.info` declares `dependencies[] = entityreference` and
> `dependencies[] = date`. `image`, `list`, `text` and `number` are core and are
> not declared. No API-exposure module is added. The module will not enable if
> `entityreference` or `date` are missing.

---

## Content types

### Área (`area`)

Common reservable areas of a condominium.

- `base` = `node_content`, native title used as the area name
  (`title_label` = «Nombre del área»).
- Published by default, not promoted, not sticky (`node_options_area = ['status']`).
- Comments hidden (`comment_area = COMMENT_NODE_HIDDEN`).

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `field_condominium` | entityreference → node | Yes | — | Bundle `condominio`. Shared field (see below). |
| `field_image` | image | No | — | Extensions `png jpg jpeg`, cardinality 1. |
| `field_open_time` | text | Yes | — | `max_length = 5` (HH:MM). |
| `field_close_time` | text | Yes | — | `max_length = 5` (HH:MM). |
| `field_slot_minutes` | number_integer | Yes | `60` | Block size in minutes. |
| `field_max_minutes` | number_integer | Yes | `120` | Max reservation length in minutes. |
| `field_area_status` | list_text | Yes | `active` | `active\|Activo`, `closed\|Cerrado`, `maintenance\|En Mantenimiento`. |
| `field_who_can_reserve` | list_text | Yes | `both` | `both\|Ambos`, `owner\|Propietario`, `tenant\|Arrendatario`. |
| `field_cancel_deadline_minutes` | number_integer | Yes | `120` | Minimum minutes before the start to cancel. |

### Reserva (`reservation`)

A reservation of a common area made by a user.

- `base` = `node_content`. Native title kept with default behaviour (irrelevant
  to the API; auto-generation is out of scope for this spec).
- Creation date is the node's native `created` field — no custom field.
- Published by default, not promoted, not sticky (`node_options_reservation = ['status']`).
- Comments hidden (`comment_reservation = COMMENT_NODE_HIDDEN`).

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `field_condominium` | entityreference → node | Yes | — | Bundle `condominio`. Same shared field as `area`. |
| `field_unit` | entityreference → node | Yes | — | Bundle `vivienda`. |
| `field_requester` | entityreference → user | Yes | — | `target_type = user`. |
| `field_area` | entityreference → node | Yes | — | Bundle `area`. |
| `field_date` | datetime (Date) | Yes | — | Day granularity only (`Y-m-d`), `tz_handling = none`, no end date. |
| `field_start_time` | text | Yes | — | `max_length = 5` (HH:MM). |
| `field_end_time` | text | Yes | — | `max_length = 5` (HH:MM). |
| `field_reservation_status` | list_text | Yes | `confirmed` | `confirmed\|Confirmada`, `cancelled\|Cancelada`. |
| `field_cancelled_by` | list_text | No | — | `user\|Usuario`, `admin\|Admin`. |

> **`field_date` vs `created`.** They are deliberately different: `field_date`
> is the reserved day, `created` is when the reservation record was filed.

---

## `allowed_values` catalogues

| Field | Bundle | Values |
|---|---|---|
| `field_area_status` | `area` | `active` → Activo · `closed` → Cerrado · `maintenance` → En Mantenimiento |
| `field_who_can_reserve` | `area` | `both` → Ambos · `owner` → Propietario · `tenant` → Arrendatario |
| `field_reservation_status` | `reservation` | `confirmed` → Confirmada · `cancelled` → Cancelada |
| `field_cancelled_by` | `reservation` | `user` → Usuario · `admin` → Admin |

### Why `field_area_status` and `field_reservation_status` are two fields

The two bundles need a status with **different** catalogues (`active/closed/
maintenance` for an area vs `confirmed/cancelled` for a reservation). In
Drupal 7 Field API, `allowed_values` of a `list_text` field is a **field-level**
setting, not per-instance — a single shared field could not carry two different
catalogues. So there are two separate fields, one per bundle, each with a clean
catalogue. An area can therefore never end up in `confirmed`.

### `field_condominium` is the only genuinely shared field

`field_condominium` is created **once** with `field_create_field()` and attached
as an **instance** to both `area` and `reservation`, both pointing at the
`condominio` bundle. `field_info_field('field_condominium')` returns a single
field with two instances.

> **entityreference placement.** Only `target_type` is a field-level setting in
> Drupal 7 entityreference. The `handler` and `handler_settings.target_bundles`
> that restrict the referenceable bundle live on the **instance** — that is where
> entityreference reads them — so `field_unit` → `vivienda`, `field_area` →
> `area`, and both `field_condominium` instances → `condominio` are configured
> at the instance level. `field_requester` targets `user` and is not restricted
> by bundle.

---

## Idempotency

Creation is driven by a single private helper, `_myapi_reservations_install()`,
built from three idempotent sub-helpers:

- `_myapi_reservations_ensure_node_type()` — skips if `node_type_load()` already
  returns the bundle.
- `_myapi_reservations_ensure_field()` — skips if `field_info_field()` already
  returns the field.
- `_myapi_reservations_ensure_instance()` — skips if `field_info_instance()`
  already returns the instance.

Each check reads the **live** definition, so re-running the helper (a
disable/enable cycle, or re-running the update) never duplicates a content type,
field or instance and never throws a `FieldException`.

---

## How it is applied

- **Fresh sites.** `hook_install()` (`myapi_install()`) calls
  `_myapi_reservations_install()` right after `myapi_mail_system_register()`, so
  `drush en myapi` creates `my_api_tokens` **and** both content types with all
  their fields in one step.
- **Already-installed sites (production).** `drush en` does not re-run
  `hook_install()`, so the same helper is exposed through the update hook
  `myapi_update_7006()`. On an existing site:

  ```bash
  drush updb    # runs myapi_update_7006 → creates area + reservation
  drush cc all
  ```

  The update touches no existing data or tables (`my_api_tokens`,
  `myapi_password_reset_tokens`, `myapi_notifications` are untouched).

Both paths call the exact same helper — it is the single source of truth for the
reservations schema.

---

## Uninstall policy (conservative)

Uninstalling the module is **non-destructive by default**: `drush pm-uninstall
myapi` does **not** remove the reservations content types, their fields or any
area/reservation node — that data belongs to the client.

The destructive path exists but is opt-in, guarded by a constant at the top of
`myapi.install`:

```php
define('MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL', FALSE);
```

Only when this is flipped to `TRUE` does `myapi_uninstall()` delete the 17
fields (which also deletes their instances and stored values) and both content
types. Leave it `FALSE` in production; an accidental uninstall would otherwise
wipe real areas and reservations.
