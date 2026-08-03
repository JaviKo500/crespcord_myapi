# Claim transaction timeline and creation page (back office)

Adds, to the native `node/%nid/edit` form of a `reclamo`, a read-only
timeline of its `claim_transaction` history plus a "Crear transacción" link
that goes to a page to add a new one. It is a page enhancement for the
operator, not an API endpoint: it returns HTML, never the JSON envelope, and
**no endpoint under `api/v1/` is involved or changed by it** (SPEC 57).

It depends on SPEC 56 (`via_claim` condominium resolution, the `create`/`edit
any claim_transaction content` permissions, and the claims listing that links
to `node/%nid/edit`) and SPEC 55 (the `reclamo`/`claim_transaction` bundles
and their fields).

> The creation screen was originally a modal opened over `node/%nid/edit` via
> Drupal 7's core AJAX Framework (jQuery UI Dialog). It worked, but the
> visual result did not fit the site and was dropped in favour of a plain
> page during implementation — see SPEC 57's plan step 10 for the full
> history. There is no AJAX, no jQuery UI Dialog and no custom JS anywhere in
> this feature.

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry for the creation route, `myapi_claim_transaction_add_access()`, `myapi_form_reclamo_node_form_alter()` and `myapi_form_claim_transaction_node_form_alter()` (glue only, both delegate), and the `'reclamo'`/`'claim_transaction'` branches of `myapi_node_insert()`/`myapi_node_update()`. |
| `includes/myapi.claim_transaction_admin.inc` | Everything else: the timeline query and its render, the "Editar" link and its access check, the creation form and its submit handler, the creation page callback, the edit-form alter and its redirect handler, the automatic initial transaction, and the status sync. Loaded by the `file` key of the menu entry and by `module_load_include()` from `myapi.module`. |
| `includes/myapi.claims_admin.inc` | Reused, not duplicated: `myapi_claims_status_options()` and `myapi_claims_status_label()` (SPEC 56) label `field_status` for this screen too — it is a single field shared by both the `reclamo` and `claim_transaction` bundles. |
| `includes/myapi.building_admin.inc` | Reused, not duplicated: `myapi_building_admin_field_value()` / `_field_target_id()` (SPEC 49), pure field-reading helpers, back the automatic creation and the status sync. |
| `myapi.install` | Configuration of `field_status_date` only: `_myapi_claims_install()` declares it with `hour`/`minute` granularity and a `'Y-m-d H:i'` `date_select` widget, and `myapi_update_7019()` (SPEC 58) applies that same configuration to sites installed earlier. Configuration only — no row of `field_data_field_status_date` is ever rewritten. |

After adding or modifying any of this, run:

```bash
drush cc all
```

No `drush updb`: this spec adds no field, table or permission — the
`claim_transaction` permissions were already granted by SPEC 56.

---

## Access control

The creation route, `node/%node/claim-transaction/add`, is checked **per
node**, not by role:

```php
function myapi_claim_transaction_add_access($node) {
  if (!is_object($node) || $node->type !== 'reclamo') {
    return FALSE;
  }
  return node_access('update', $node) && node_access('create', 'claim_transaction');
}
```

- `FALSE` for any node that is not a `reclamo`.
- Otherwise defers entirely to `node_access()`, which already covers the
  `bypass node access` superuser shortcut and, for `administrador edificio`,
  the condominium scoping of SPEC 49/56 (`hook_node_access()` in
  `myapi.module`). A building admin without access to a claim's condominium
  gets a **403** on this route even with the `nid` in hand.
- `node_access('create', 'claim_transaction')` is the same permission SPEC 56
  granted `administrador edificio`; `administrator`/`backend` already had it.

The fieldset and the "Crear transacción" link are only ever rendered inside
`node/%nid/edit` (see below), so a user without update access to that claim
never sees the link in the first place — this route's own access check is
what actually stops a direct URL guess.

---

## `field_status` read-only in edition

`myapi_form_reclamo_node_form_alter()` (glue) delegates to
`myapi_claim_transaction_reclamo_form_alter()`
(`includes/myapi.claim_transaction_admin.inc`):

```php
function myapi_claim_transaction_reclamo_form_alter(&$form, &$form_state) {
  if (empty($form['#node']->nid)) {
    return;
  }
  foreach (element_children($form['field_status']) as $langcode) {
    $form['field_status'][$langcode]['#disabled'] = TRUE;
  }
  // ... append the timeline fieldset, see below
}
```

- **`node/add/reclamo`** (no `nid` yet): untouched. `field_status` stays a
  normal, editable `select` — this is exactly where the initial status is
  chosen, and the automatic initial transaction (below) copies it as-is.
- **`node/%nid/edit`** (existing claim): `field_status` renders
  **disabled** — visible, but not editable from this form. `#disabled`, not
  `#access = FALSE`: Drupal still resubmits a disabled element's
  `#default_value`, so saving the rest of the claim's form never blanks the
  status, even if the HTML were hand-tampered to send a different value.

The only way to change an existing claim's status is the creation page (or,
for `administrator`/`backend`, the native `claim_transaction` forms) — never
this form.

On those native forms (`node/add/claim_transaction`, `node/%nid/edit` of a
transaction) `field_status_date` keeps its own Date module `date_select`
widget, which since SPEC 58 offers **hour and minute** selectors alongside
day/month/year. That widget is untouched by the custom form's textfield
decision: they are two independent entry paths into the same field.

---

## Timeline

`myapi_claim_transaction_timeline_rows($claim_nid)` — one row per published
`claim_transaction` whose `field_claim` points at the claim, `LEFT JOIN`ed to
`field_status`, `field_status_date`, `field_comment` and to `users` via the
transaction node's own `uid` (its author). Ordered `field_status_date`
**descending**, `nid` descending as a deterministic tie-break. No pager: the
expected volume per claim is low (status changes, not a chat).

`field_status_date` carries **date and time**, to the minute (SPEC 58). The
query selects the stored column as-is — the `SUBSTR(..., 1, 10)` that used to
truncate it to its date part is gone — and the cell renders it with
`format_date(strtotime(...), 'custom', 'd/m/Y H:i')`, the same custom format
`myapi.reservation_calendar.inc` and `myapi.reservation_notification.inc`
already use. Ordering by the raw column still works: `'Y-m-d H:i:s'` sorts
lexicographically exactly as it sorts chronologically, so the `nid` tie-break
now only applies to transactions sharing the same *minute*.

> **Transactions created before SPEC 58** were saved by a day-only field and
> therefore display `00:00` as their time (`15/07/2026 00:00`). That is the
> value actually recorded, not a formatting bug: the spec deliberately
> performed **no data migration**, since no real time was ever captured for
> those rows and inventing one would be worse than showing the truth.

| Column | Source | When empty or the reference is deleted |
|---|---|---|
| Estado | `field_status`, labelled with `myapi_claims_status_label()` (SPEC 56) | `—` if empty (should not happen: required) |
| Fecha de estado | `field_status_date`, formatted `d/m/Y H:i` (e.g. `01/08/2026 14:35`) | `—` if empty |
| Comentario | `field_comment` | `—` if empty |
| Autor | `node.uid` → the account's username | Account deleted: `Usuario eliminado (#uid)` |
| Editar | `myapi_claim_transaction_edit_link()` — a link to `node/<nid>/edit` of that transaction (SPEC 59) | **Empty cell**, with no substitute text, when the user may not update that transaction |

The **"Crear transacción"** link renders **first**, above the table — a
plain link (`l()`, `class="button"` only, no `use-ajax`) pointing at
`node/<claim_nid>/claim-transaction/add`.

---

## "Editar" link (SPEC 59)

The last cell of every timeline row links to the **native** Drupal edit form
of that transaction — `node/<nid>/edit`, not a custom form of this module:

```php
function myapi_claim_transaction_edit_link($nid) {
  $transaction_node = node_load($nid);
  if (!$transaction_node || !node_access('update', $transaction_node)) {
    return '';
  }
  return l(t('Editar'), 'node/' . $nid . '/edit');
}
```

- The link is **hidden**, not disabled, for a user without access: a link
  that leads to a 403 is worse than no link. Same criterion as
  `myapi_claim_transaction_add_access()` above, which also resolves access
  before its own link is shown.
- `node_access('update', ...)` is the single source of truth — it already
  covers `bypass node access` for `administrator`/`backend` **and** the
  per-condominium `via_claim` filter SPEC 56 gave `administrador edificio`.
  Nothing about that rule is reimplemented here.
- That is why the row's raw `nid` is not enough and `node_load()` runs **once
  per row**: `node_access()` needs the node object. The cost is the one
  SPEC 56 already accepted for `via_claim`, over the low per-claim volume this
  screen assumes (no pager). If the volume ever grew, caching the per-`uid`
  verdict within the request is the bounded fix.
- The cell is a `'#markup'` render element, not a plain string:
  `theme_table()` runs `check_plain()` over string cells, which would print
  the `<a>` tag instead of rendering the link.

---

## `field_claim` read-only in edition, and the redirect back (SPEC 59)

`myapi_form_claim_transaction_node_form_alter()` (glue) delegates to
`myapi_claim_transaction_transaction_form_alter()`
(`includes/myapi.claim_transaction_admin.inc`):

```php
function myapi_claim_transaction_transaction_form_alter(&$form, &$form_state) {
  if (empty($form['#node']->nid)) {
    return;
  }
  if (isset($form['field_claim'])) {
    foreach (element_children($form['field_claim']) as $langcode) {
      foreach (element_children($form['field_claim'][$langcode]) as $delta) {
        if (isset($form['field_claim'][$langcode][$delta]['target_id'])) {
          $form['field_claim'][$langcode][$delta]['target_id']['#disabled'] = TRUE;
        }
      }
    }
  }
  form_load_include($form_state, 'inc', 'myapi', 'includes/myapi.claim_transaction_admin');
  $form['#submit'][] = 'myapi_claim_transaction_edit_form_submit_redirect';
  if (isset($form['actions']['submit']['#submit'])) {
    $form['actions']['submit']['#submit'][] = 'myapi_claim_transaction_edit_form_submit_redirect';
  }
}
```

- **`node/add/claim_transaction`** (no `nid` yet): untouched. `field_claim`
  stays a normal, editable, required field, and no redirect is forced — a
  transaction being created belongs to no claim yet, so there is nothing to
  protect and nowhere to send the operator. Same criterion as
  `myapi_claim_transaction_reclamo_form_alter()` on `node/add/reclamo`.
- **`node/%nid/edit`** of an existing transaction: `field_claim` renders
  **disabled** — its value stays visible, so the operator sees which claim the
  transaction belongs to, but it cannot be retyped or autocompleted to another
  one. `#disabled`, not `#access = FALSE`, for the same reason as
  `field_status` above: Drupal resubmits a disabled element's value and
  discards whatever a tampered POST sends for it.
- The walk goes **one level deeper** than the `field_status` one: `field_claim`
  uses the `entityreference_autocomplete` widget, which nests the real
  textfield under `target_id` inside each delta, whereas `options_select`
  makes the langcode-level element itself the `select`.
- The `isset()` guards the **loop only**, not the `#submit`: if another module
  or a `field_access` rule removed `field_claim` from the form, there is
  nothing to disable, but redirecting to the claim is still right — the
  handler resolves it from the saved node, not from the form.
- **Both `#submit` lists, not just the form's.** `form_execute_handlers()`
  runs the **triggering element's** `#submit` when it has one and ignores the
  form-level list entirely, and `node_form()` always gives its Save button
  `array('node_form_submit')`. A handler appended only to `$form['#submit']`
  therefore never runs on a node form — saving landed on Drupal's native
  `node/<nid>` as if it did not exist. Only one of the two lists ever executes,
  so appending to both never runs it twice.
- **`form_load_include()`, not plain `module_load_include()`.** This form has
  `managed_file` fields, so Drupal **caches** it: the POST is served from the
  cached array and `hook_form_alter()` — with it, `myapi.module`'s
  `module_load_include()` — never runs again, leaving the cached `#submit`
  pointing at a function whose file was never loaded (`Call to undefined
  function myapi_claim_transaction_edit_form_submit_redirect()`).
  `form_load_include()` also records the file in
  `$form_state['build_info']['files']`, which is what Drupal reloads when it
  retrieves a cached form. Any future `#submit` handler this module adds to a
  native form from an `.inc` needs the same treatment.
- No other field of the native form is restricted: `field_status`,
  `field_status_date`, `field_comment`, `field_images` and `field_attachment`
  stay fully editable. `field_claim` is locked for one specific risk — moving
  a transaction to a different claim by accident.

The redirect handler runs **last**, after `node_form_submit()` has saved the
node:

```php
function myapi_claim_transaction_edit_form_submit_redirect($form, &$form_state) {
  if (empty($form_state['node'])) {
    return;
  }
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');
  $claim_nid = myapi_building_admin_field_target_id($form_state['node'], 'field_claim');
  if ($claim_nid) {
    $form_state['redirect'] = 'node/' . $claim_nid . '/edit';
  }
}
```

- Saving lands on **`node/<claim_nid>/edit`**, the claim's edit form with its
  timeline already reflecting the change — not on Drupal's native
  `node/<nid>`. Same destination as creating a transaction, so both flows end
  in the same place.
- Overriding `$form_state['redirect']` is the standard FAPI way to change
  where a form lands, and is why this is a submit handler rather than a
  `drupal_goto()` inside `hook_node_update()`: a `goto` there would abort the
  other `hook_node_update()` implementations queued behind it — including this
  module's own status sync.
- When `field_claim` does not resolve (the corrupt-data case), the redirect is
  left **alone** rather than set to something invalid, and Drupal falls back
  to `node/<nid>`. The `empty($form_state['node'])` guard is the counterpart
  for a submit chain another module altered.
- This applies to **any** entry point into that edit form, including
  `/admin/content` — accepted deliberately: the transaction lives inside its
  claim's context regardless of where the operator came from.

---

## Creation page

`myapi_claim_transaction_add_page_callback($claim_node)`, the page callback
of `node/%node/claim-transaction/add`:

```php
function myapi_claim_transaction_add_page_callback($claim_node) {
  return drupal_get_form('myapi_claim_transaction_create_form', $claim_node);
}
```

One behaviour, always: a normal page with the form, delivered like any other
Drupal page. Clicking "Crear transacción" navigates away from
`node/%nid/edit`; saving navigates back to it (see Submit, below).

The form itself, `myapi_claim_transaction_create_form()`, is a form of its
own (not the native `claim_transaction` node form), so it shows **exactly**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `field_status` | `select` | Yes | Options from `myapi_claims_status_options()` (SPEC 56) |
| `field_status_date` | `textfield`, plain `'AAAA-MM-DD HH:MM'` text | Yes | Defaults to the current date **and time** (e.g. `2026-08-01 14:35`); validated server-side by `myapi_claim_transaction_validate_status_date()` (`#element_validate`), which splits the value on its space and reuses `myapi_reservation_valid_date()` **and** `myapi_reservation_valid_time()` (`resources/reservation.resource.inc`) — the same `'YYYY-MM-DD'` + `checkdate()` and `'HH:MM'` 24h rules `includes/myapi.claims_admin.inc` already reuses for its own date filters, so SPEC 58 added no new regex. A value with no time part is rejected. The submit handler saves it as `'Y-m-d H:i:00'` — seconds pinned to `00`, since the form does not capture them. Two combo-style date widgets were tried first — `date_popup` (Date module) and core's own `#type => 'date'` — and both were reverted: each brought its own layout CSS (`container-inline-date`, then `container-inline`) that broke alignment with the rest of the form. A plain textfield uses the exact same wrapper as `field_status`/`field_comment`, with nothing special left to fight (see SPEC 57 "Decisiones tomadas y descartadas") |
| `field_comment` | `textarea` | **Yes** | Required at the request of the user, after seeing the form live. Only this custom form enforces it — the underlying field instance (SPEC 55) is still optional, so `node/add/claim_transaction` (native) does not require it |
| `field_images` | `managed_file` | No | Validators/upload URI read live from the field instance via core's `file_field_widget_upload_validators()`/`file_field_widget_uri()` — never hard-coded |
| `field_attachment` | `managed_file` | No | Same as above |

`field_claim` is **not** shown: it travels fixed as `$form['claim_nid']`
(`#type => 'value'`), set to the claim being edited.

**Submit** (`myapi_claim_transaction_create_form_submit()`): builds the
`claim_transaction` node by hand (`field_claim` = the claim, `uid` = the
current user), calls `node_save()`, and sets
`$form_state['redirect'] = 'node/<claim_nid>/edit'` — a normal POST, full
page reload. The status sync (below) is what actually updates the claim;
nothing in this handler touches it directly.

---

## Automatic initial transaction

`myapi_claim_transaction_create_initial($node)`, called from
`myapi_node_insert()`'s `'reclamo'` branch — every creation path of a
`reclamo`, not only the admin form:

```php
$transaction->field_claim[LANGUAGE_NONE][0]['target_id']  = $node->nid;
$transaction->field_status[LANGUAGE_NONE][0]['value']     = myapi_building_admin_field_value($node, 'field_status');
$transaction->field_status_date[LANGUAGE_NONE][0]['value'] = date('Y-m-d H:i:00');
```

The initial transaction records the real instant the claim was created, not
that day at midnight (SPEC 58) — same pinned `:00` seconds as the creation
form's submit handler.

`field_status` is **copied** from the claim as chosen on `node/add/reclamo`
— never forced to `received`. Because the transaction is born with the same
status the claim already has, the sync rule below never finds a difference
for it, so creating a claim never re-saves it a second time.

Only fires on `hook_node_insert()`, never `hook_node_update()`: editing an
existing claim does not create a second initial transaction.

---

## Status sync

`myapi_claim_transaction_sync_claim_status($node)`, called from **both**
`myapi_node_insert()` and `myapi_node_update()`'s `'claim_transaction'`
branch:

```php
$claim_nid = myapi_building_admin_field_target_id($node, 'field_claim');
$claim = $claim_nid ? node_load($claim_nid) : NULL;
if ($claim && myapi_building_admin_field_value($node, 'field_status') !== myapi_building_admin_field_value($claim, 'field_status')) {
  $claim->field_status[LANGUAGE_NONE][0]['value'] = myapi_building_admin_field_value($node, 'field_status');
  node_save($claim);
}
```

- Direction is **transaction → claim only**. There is no reverse path: the
  claim's `field_status` is read-only on `node/%nid/edit` (above), so nothing
  in the UI can trigger it the other way.
- The rule lives in the **node hook**, not in the creation form's submit
  handler, so it also covers `node/add/claim_transaction` and
  `node/%nid/edit` of a transaction — the **native** forms, used by
  `administrator`/`backend`, which never go through
  `myapi_claim_transaction_create_form()`.
- If `field_claim` is empty, or resolves to a node without `field_status`
  (a non-`reclamo`, in principle prevented by SPEC 53's `target_bundles`),
  `$claim_status` reads `NULL` and the comparison is almost always `TRUE`,
  which would attempt a `node_save()` that fails on that mismatched bundle.
  Not guarded explicitly — it depends on `target_bundles` continuing to do
  its job, same as documented in the spec's Risks table.
- No insert/update cascade: `myapi_claim_transaction_create_initial()` only
  listens to `hook_node_insert()` of `'reclamo'`, never `'claim_transaction'`,
  so a claim re-saved by this sync never creates a new transaction.

---

## Manual verification

Two functions of this file **are** unit tested, in
`tests/unit/ClaimTransactionEditTest.php` (SPEC 59):
`myapi_claim_transaction_transaction_form_alter()`, which is FAPI array
manipulation, and `myapi_claim_transaction_edit_form_submit_redirect()`, which
is a field read plus an assignment. Everything else here — including
`myapi_claim_transaction_edit_link()`, which is `node_load()` +
`node_access()` — touches `db_select()`, the Field API or `$_POST`, the same
criterion the rest of `tests/unit/` already applies to comparable back-office
screens (`myapi_reservation_calendar_page()`, `myapi_claims_list_page()`), and
is verified by the matrices below instead.

```bash
drush cc all
```

**Form / timeline matrix** — `node/%nid/edit` of a `reclamo`:

| Case | Expected |
|---|---|
| `node/add/reclamo` | `field_status` editable, no timeline fieldset |
| `node/%nid/edit`, existing claim | `field_status` visible but disabled; timeline fieldset below |
| Save the edit form (other fields changed) | `field_status` unchanged, even with a tampered POST value |
| Timeline row order | `field_status_date` descending (date **and** time), `nid` descending on a tie |
| Timeline date cell | `d/m/Y H:i`; a row created before SPEC 58 shows `00:00`, a `NULL` one shows `—` |
| "Crear transacción" position | First, above the table |
| "Editar" column | Last column of the table; one link per row the user may update |
| `administrator` / `backend` | "Editar" on **every** row, of any claim |
| `administrador edificio` with the claim's condominium | "Editar" on every row of that claim |
| `administrador edificio` without it | The claim is already outside their listing (SPEC 56); reached by any other means, the "Editar" cell is empty |

**Automatic initial transaction matrix**:

| Case | Expected |
|---|---|
| Create claim with `field_status = received` | Initial transaction with `field_status = received`, `field_status_date` = the exact date and time of creation |
| Create claim with `field_status = in_progress` | Initial transaction copies `in_progress`, not forced to `received` |
| Reload `node/%nid/edit` right after creation | Initial transaction already in the timeline |
| Claim creation | No extra `node_save()` of the claim itself (sync finds no difference) |

**Creation page matrix**:

| Case | Expected |
|---|---|
| Click "Crear transacción" | Navigates to `node/%nid/claim-transaction/add`, full page form |
| Fields shown | Exactly `field_status`, `field_status_date`, `field_comment`, `field_images`, `field_attachment` — never `field_claim` |
| `field_status_date` default | Current date and time, as plain 'AAAA-MM-DD HH:MM' text; label aligned above the field, same as `field_status`/`field_comment` |
| `field_status_date` malformed or non-existent date (e.g. `2026-02-30 10:00`) | Validation error from `myapi_claim_transaction_validate_status_date()`, transaction not created |
| `field_status_date` impossible time (e.g. `2026-08-01 25:99`), or no time part at all | Same validation error, transaction not created |
| `field_status_date` valid (e.g. `2026-08-01 14:35`) | Saved as `2026-08-01 14:35:00`, shown as `01/08/2026 14:35` in the timeline |
| Submit with no `field_comment` | Native required-field error, transaction not created |
| Submit | Creates the transaction, redirects to `node/%nid/edit`, full reload |
| After redirect | New transaction is first in the timeline |
| No `create claim_transaction content`, or no condominium access (SPEC 56) | 403 on this route, even with the `nid` known |
| Image > 3 MB, or wrong extension; attachment outside `pdf/doc/docx/xls/xlsx` | Rejected with the native field validation message |

**Edit matrix** (SPEC 59) — `node/%nid/edit` of a `claim_transaction`:

| Case | Expected |
|---|---|
| Click "Editar" in the timeline | Navigates to `node/<nid>/edit`, the native Drupal form |
| `field_claim` on that form | Visible with its current value, but **disabled** — cannot be retyped or autocompleted |
| `node/add/claim_transaction` (native creation) | `field_claim` normal, editable and required; no forced redirect |
| Save after changing status / date / comment | Redirects to `node/<claim_nid>/edit`, timeline already showing the change |
| Tampered POST sending another `field_claim` | Discarded — the transaction stays on its claim |
| `field_claim` empty or corrupt | Redirect falls back to Drupal's native `node/<nid>`, no error |
| Editing `field_status` there | Claim's `field_status` still syncs (SPEC 57, `hook_node_update()`) |
| No `edit any claim_transaction content`, or no condominium access | 403 on `node/<nid>/edit` even with the `nid` known — native Drupal, untouched by this spec |

**Status sync matrix**:

| Case | Expected |
|---|---|
| New transaction, different status than the claim | Claim updates to the new status |
| New transaction, same status as the claim | Claim's `changed` timestamp does not advance |
| Created via `myapi_claim_transaction_create_form()` | Sync fires (via `hook_node_insert()`) |
| Created via `node/add/claim_transaction` (native, `administrator`/`backend`) | Sync fires the same way — the rule lives in the hook, not the form |
| Edited via `node/%nid/edit` of a transaction (native) | Sync fires via `hook_node_update()` |
| Claim re-saved by the sync | Does **not** create a new transaction |

**No regression / infra**:

- `resources/*.resource.inc` does not appear in the diff of this spec.
- `hook_menu()` gained exactly one new route, `node/%node/claim-transaction/add`
  (SPEC 57); SPEC 59 added **none** — the "Editar" link points at Drupal's own
  `node/%/edit`. No `api/v1/...` path changed.
- No permission was granted or revoked by SPEC 59: `create` / `edit any
  claim_transaction content` are exactly the ones SPEC 56 gave.
- The existing `'pagos'`, `'boletin'`, `'recibo'`, `'alicuota_extra'`,
  `'reservation'` branches of `myapi_node_insert()`/`myapi_node_update()`
  are unchanged.
- `drush cc all` reports no errors.
