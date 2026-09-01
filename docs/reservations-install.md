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
| `field_area_category` | list_text | Yes | `other` | Stable catalogue key the app maps to an icon; see catalogue below. |

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
| `field_area_category` | `area` | 36 options — see [The category catalogue](#the-category-catalogue) below |
| `field_reservation_status` | `reservation` | `confirmed` → Confirmada · `cancelled` → Cancelada |
| `field_cancelled_by` | `reservation` | `user` → Usuario · `admin` → Admin |

### Why `field_area_status` and `field_reservation_status` are two fields

The two bundles need a status with **different** catalogues (`active/closed/
maintenance` for an area vs `confirmed/cancelled` for a reservation). In
Drupal 7 Field API, `allowed_values` of a `list_text` field is a **field-level**
setting, not per-instance — a single shared field could not carry two different
catalogues. So there are two separate fields, one per bundle, each with a clean
catalogue. An area can therefore never end up in `confirmed`.

### `field_area_category` is a stable client key, not a display label

`field_area_category` classifies each area so the Flutter app can pick an icon
**deterministically from the key** (`pool`, `gym`, …), never from the free-text
area name. The keys are English `snake_case` and **must stay stable**: the client
maps key → icon, so renaming a key would break existing icons, whereas the
Spanish labels are admin-facing only and can be reworded freely. `other` is the
default so every area — including ones created before this field existed — always
resolves to a valid catalogue key. It lives only on the `area` bundle; it does
not exist on `reservation` or `condominio`.

### `field_condominium` is the only genuinely shared field

`field_condominium` is created **once** with `field_create_field()` and attached
as an **instance** to both `area` and `reservation`, both pointing at the
`condominio` bundle. `field_info_field('field_condominium')` returns a single
field with two instances.

> **entityreference placement.** `target_type`, `handler` and
> `handler_settings.target_bundles` are **all three field-level** settings in
> Drupal 7 entityreference — the selection handler reads them off the *field*,
> never off the instance. All of them therefore come from one catalogue,
> `_myapi_entityreference_field_settings()` in `myapi.install`: `field_unit` →
> `vivienda`, `field_area` → `area`, and `field_condominium` → `condominio` for
> both of its instances. `field_requester` targets `user`, whose entity type has
> a single bundle, so it carries no `target_bundles` and that absence is correct.
>
> Because these settings are field-level, a **shared** field has one selection
> setting for all its bundles. That is harmless for `field_condominium` (both
> bundles want `condominio`), but a future field needing different bundles per
> bundle would have to be split in two — exactly like `field_area_status` and
> `field_reservation_status` already are for their `allowed_values`.
>
> This is the SPEC 53 bug: until then the module wrote `handler` and
> `handler_settings` on the **instances**, where entityreference never looks, so
> the handler ran with no bundle condition and every autocomplete of the module
> offered every node of the site — a `vivienda` or a `boletin` came up while
> typing in `field_condominium`. See
> [The SPEC 53 repair](#the-spec-53-repair-myapi_update_7016) below.

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

### `field_area_category` on already-installed sites

`field_area_category` was added after the initial reservations schema, so it has
its own update hook, `myapi_update_7007()`. Fresh sites get it through
`_myapi_reservations_install()` like every other area field; existing sites get
it with:

```bash
drush updb    # runs myapi_update_7007 → adds field_area_category to 'area'
drush cc all
```

The hook reuses the same idempotent `_ensure_field` / `_ensure_instance`
sub-helpers, so re-running it never duplicates the field/instance nor throws a
`FieldException`. It only adds the field and its `area` instance — no REST
endpoint, no business logic — and leaves `reservation` and `condominio` alone.

### The category catalogue

The allowed values of `field_area_category` live in ONE place,
`_myapi_area_category_allowed_values()` in `myapi.install`, read by the
installer and by `myapi_update_7043()`. The catalogue started as ten options
and now holds 36, in the order the select renders them — alphabetical by label,
with `other` deliberately last instead of under its O:

| Key | Label | | Key | Label |
|---|---|---|---|---|
| `guest_suite` | Apartamento de huéspedes | | `bike_parking` | Parqueadero de bicicletas |
| `playground` | Área infantil | | `guest_parking` | Parqueadero de visitantes |
| `steam_room` | Baño turco / Vapor | | `pool` | Piscina |
| `library` | Biblioteca | | `kids_pool` | Piscina infantil |
| `soccer_field` | Cancha de fútbol | | `cinema_room` | Sala de cine |
| `padel_court` | Cancha de pádel | | `study_room` | Sala de estudio |
| `squash_court` | Cancha de squash | | `game_room` | Sala de juegos |
| `tennis_court` | Cancha de tenis | | `meeting_room` | Sala de juntas |
| `sports_court` | Cancha deportiva | | `yoga_room` | Sala de yoga / Pilates |
| `chapel` | Capilla / Sala de oración | | `party_hall` | Salón de eventos |
| `ev_charger` | Cargador de vehículo eléctrico | | `multipurpose_room` | Salón multiusos |
| `coworking` | Coworking / Sala de trabajo | | `social_room` | Salón social |
| `storage` | Depósito / Bodega | | `sauna` | Sauna / Spa |
| `gym` | Gimnasio | | `rooftop` | Terraza / Rooftop |
| `jacuzzi` | Jacuzzi | | `car_wash` | Zona de lavado de vehículos |
| `laundry` | Lavandería | | `pet_area` | Zona de mascotas |
| | | | `bbq` | Zona de parrillas (BBQ) |
| | | | `picnic_area` | Zona de picnic |
| | | | `green_area` | Zona verde / Jardín |
| | | | `other` | Otra |

**The key is the contract, not the label.** The app chooses the icon of an area
from this key and `area.resource.inc` passes the stored value straight through
to JSON, so keys are added — never renamed, never removed. A rename blanks the
icon of every area already classified with it, and Drupal's `list` module
refuses the removal outright (`FieldUpdateForbiddenException`) while one node
still holds the value.

Existing sites widen the catalogue with:

```bash
drush updb    # runs myapi_update_7043 → rewrites the allowed values
drush cc all
```

`myapi_update_7043()` reads the live field and calls `field_update_field()`;
`_ensure_field` would have done nothing, since it only creates a field that is
missing. The hook removes and renames nothing, so it is safe on a site full of
areas, and re-running it writes the same array.

### The SPEC 53 repair (`myapi_update_7016`)

Fixing the installer is not enough for a site that already exists:
`_myapi_reservations_ensure_field()` **skips any field that exists**, on purpose,
so no `drush updb` would ever have reached the five entityreference fields
created with their selection settings in the wrong place. `myapi_update_7016()`
is the one thing in this module that writes over an existing field definition:

```bash
drush updb    # runs myapi_update_7016 → repairs the selection settings
drush cc all
```

What it touches, and what it does not:

| | |
|---|---|
| Fields repaired | `field_condominium`, `field_unit`, `field_area`, `field_requester`, `field_condominio_admin` |
| What is written | `handler` and `handler_settings` on the **field**, via `field_update_field()` |
| Data | **None.** Not one `field_data_*` row is read or written — this is a definition change |
| `target_type` | Never touched. A field whose `target_type` disagrees with the catalogue is **reported to the log and skipped**, because changing it would orphan every stored `target_id` |
| Values set by hand | Never overwritten. The update **fills in what is missing**; a `target_bundles` an administrator narrowed in the UI survives |
| Old instance settings | Left in place. entityreference never reads instance settings, so the leftovers cost nothing |

The decision of what counts as "missing" is a pure function,
`_myapi_entityreference_repair_settings()`, covered by
`tests/unit/EntityReferenceFieldSettingsTest.php`. Re-running the update finds
everything in place and writes nothing.

**Faster alternative for a single site.** Ticking the referenceable bundle at
`/admin/structure/types/manage/area/fields/field_condominium` writes the same
field-level setting through the UI and fixes both `field_condominium` instances
at once. The update hook exists so this does not have to be done by hand, field
by field, environment by environment.

**How to confirm it on a site:**

```bash
drush php-eval "print_r(field_info_field('field_condominium')['settings']);"
```

`handler_settings.target_bundles` must show `condominio`. If only `target_type`
comes back, the update has not run there.

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

Only when this is flipped to `TRUE` does `myapi_uninstall()` delete the 18
fields (which also deletes their instances and stored values) and both content
types. Leave it `FALSE` in production; an accidental uninstall would otherwise
wipe real areas and reservations.
