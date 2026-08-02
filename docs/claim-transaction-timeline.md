# Claim transaction timeline and creation modal (back office)

Adds, to the native `node/%nid/edit` form of a `reclamo`, a read-only
timeline of its `claim_transaction` history plus a "Crear transacción" button
that opens a modal to add a new one. It is a page enhancement for the
operator, not an API endpoint: it returns HTML, never the JSON envelope, and
**no endpoint under `api/v1/` is involved or changed by it** (SPEC 57).

It depends on SPEC 56 (`via_claim` condominium resolution, the `create`/`edit
any claim_transaction content` permissions, and the claims listing that links
to `node/%nid/edit`) and SPEC 55 (the `reclamo`/`claim_transaction` bundles
and their fields).

---

## Files

| File | Role |
|------|------|
| `myapi.module` | `hook_menu()` entry for the modal route, `myapi_claim_transaction_modal_access()`, `myapi_form_reclamo_node_form_alter()` (glue only, delegates), and the `'reclamo'`/`'claim_transaction'` branches of `myapi_node_insert()`/`myapi_node_update()`. |
| `includes/myapi.claim_transaction_admin.inc` | Everything else: the timeline query and its render, the creation form and its submit handler, the modal page callback, the automatic initial transaction, and the status sync. Loaded by the `file` key of the menu entry and by `module_load_include()` from `myapi.module`. |
| `includes/myapi.claims_admin.inc` | Reused, not duplicated: `myapi_claims_status_options()` and `myapi_claims_status_label()` (SPEC 56) label `field_status` for this screen too — it is a single field shared by both the `reclamo` and `claim_transaction` bundles. |
| `includes/myapi.building_admin.inc` | Reused, not duplicated: `myapi_building_admin_field_value()` / `_field_target_id()` (SPEC 49), pure field-reading helpers, back the automatic creation and the status sync. |

After adding or modifying any of this, run:

```bash
drush cc all
```

No `drush updb`: this spec adds no field, table or permission — the
`claim_transaction` permissions were already granted by SPEC 56.

---

## Access control

The modal route, `node/%node/claim-transaction/add`, is checked **per node**,
not by role:

```php
function myapi_claim_transaction_modal_access($node) {
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
  gets a **403** on the modal route even with the `nid` in hand.
- `node_access('create', 'claim_transaction')` is the same permission SPEC 56
  granted `administrador edificio`; `administrator`/`backend` already had it.

The fieldset and the "Crear transacción" link are only ever rendered inside
`node/%nid/edit` (see below), so a user without update access to that claim
never sees the link in the first place — the modal route's own access check
is what actually stops a direct URL guess.

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

The only way to change an existing claim's status is the modal (or, for
`administrator`/`backend`, the native `claim_transaction` forms) — never this
form.

---

## Timeline

`myapi_claim_transaction_timeline_rows($claim_nid)` — one row per published
`claim_transaction` whose `field_claim` points at the claim, `LEFT JOIN`ed to
`field_status`, `field_status_date`, `field_comment` and to `users` via the
transaction node's own `uid` (its author). Ordered `field_status_date`
**descending**, `nid` descending as a deterministic tie-break. No pager: the
expected volume per claim is low (status changes, not a chat).

| Column | Source | When empty or the reference is deleted |
|---|---|---|
| Estado | `field_status`, labelled with `myapi_claims_status_label()` (SPEC 56) | `—` if empty (should not happen: required) |
| Fecha de estado | `field_status_date` | `—` if empty |
| Comentario | `field_comment` | `—` if empty |
| Autor | `node.uid` → the account's username | Account deleted: `Usuario eliminado (#uid)` |

The **"Crear transacción"** link renders **first**, above the table, with
class `use-ajax` pointing at the modal route. An empty, hidden container
(`<div id="myapi-claim-transaction-modal" style="display: none;">`) is added
right after the table — the modal fills it via AJAX (see below); it is never
visible on its own.

---

## Creation modal

Two behaviours on the same route (`node/%node/claim-transaction/add`),
`myapi_claim_transaction_modal_callback($claim_node)`:

- **With JavaScript** (a `.use-ajax` click): Drupal core's own
  `Drupal.behaviors.AJAX` (`misc/ajax.js`) intercepts the link and issues a
  POST carrying `ajax_page_state` — the same signal
  `ajax_base_page_theme()`/`ajax_render()` check internally, and what this
  callback uses (`!empty($_POST['ajax_page_state'])`) to detect the request.
  It then calls `ajax_render()` directly and exits by hand (documented in
  `ajax_render()`'s own docblock as a valid pattern for callbacks that do not
  declare `'delivery callback' => 'ajax_deliver'` — that would have made
  AJAX mandatory here, breaking the no-JS path). The response: an
  `ajax_command_html()` filling `#myapi-claim-transaction-modal` with the
  form markup, followed by an `ajax_command_invoke(..., 'dialog', ...)` that
  opens it as a jQuery UI dialog. **No custom JS file** was needed for any
  of this.
  - `ajax_command_open_modal_dialog()` is deliberately **not** used: it does
    not exist in Drupal 7 core (confirmed against the real environment,
    Drupal 7.64 — see the spec's plan step 1). `ajax_command_html()` +
    `ajax_command_invoke()` are the real, core-only substitute.
- **Without JavaScript, or a direct URL visit**: the same function returns
  the form as a normal render array — an ordinary page, same form, same
  submit.

The form itself, `myapi_claim_transaction_create_form()`, is a form of its
own (not the native `claim_transaction` node form), so it shows **exactly**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `field_status` | `select` | Yes | Options from `myapi_claims_status_options()` (SPEC 56) |
| `field_status_date` | `date_popup` | Yes | Defaults to today; date-only (`#date_format => 'Y-m-d'`), no time |
| `field_comment` | `textarea` | No | — |
| `field_images` | `managed_file` | No | Validators/upload URI read live from the field instance via core's `file_field_widget_upload_validators()`/`file_field_widget_uri()` — never hard-coded |
| `field_attachment` | `managed_file` | No | Same as above |

`field_claim` is **not** shown: it travels fixed as `$form['claim_nid']`
(`#type => 'value'`), set to the claim being edited.

**Submit** (`myapi_claim_transaction_create_form_submit()`): builds the
`claim_transaction` node by hand (`field_claim` = the claim, `uid` = the
current user), calls `node_save()`, and sets
`$form_state['redirect'] = 'node/<claim_nid>/edit'` — a **normal POST**, full
page reload, no `#ajax` replace. The status sync (below) is what actually
updates the claim; nothing in this handler touches it directly.

---

## Automatic initial transaction

`myapi_claim_transaction_create_initial($node)`, called from
`myapi_node_insert()`'s `'reclamo'` branch — every creation path of a
`reclamo`, not only the admin form:

```php
$transaction->field_claim[LANGUAGE_NONE][0]['target_id']  = $node->nid;
$transaction->field_status[LANGUAGE_NONE][0]['value']     = myapi_building_admin_field_value($node, 'field_status');
$transaction->field_status_date[LANGUAGE_NONE][0]['value'] = date('Y-m-d');
```

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
- The rule lives in the **node hook**, not in the modal's submit handler, so
  it also covers `node/add/claim_transaction` and `node/%nid/edit` of a
  transaction — the **native** forms, used by `administrator`/`backend`,
  which never go through the modal.
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

No unit test: every function here touches `db_select()`, the Field API, or
`$_POST`/`$form_state`, the same criterion the rest of `tests/unit/` already
applies to comparable back-office screens (`myapi_reservation_calendar_page()`,
`myapi_claims_list_page()`).

```bash
drush cc all
```

**Form / timeline matrix** — `node/%nid/edit` of a `reclamo`:

| Case | Expected |
|---|---|
| `node/add/reclamo` | `field_status` editable, no timeline fieldset |
| `node/%nid/edit`, existing claim | `field_status` visible but disabled; timeline fieldset below |
| Save the edit form (other fields changed) | `field_status` unchanged, even with a tampered POST value |
| Timeline row order | `field_status_date` descending, `nid` descending on a tie |
| "Crear transacción" position | First, above the table |

**Automatic initial transaction matrix**:

| Case | Expected |
|---|---|
| Create claim with `field_status = received` | Initial transaction with `field_status = received`, `field_status_date` = today |
| Create claim with `field_status = in_progress` | Initial transaction copies `in_progress`, not forced to `received` |
| Reload `node/%nid/edit` right after creation | Initial transaction already in the timeline |
| Claim creation | No extra `node_save()` of the claim itself (sync finds no difference) |

**Modal matrix**:

| Case | Expected |
|---|---|
| Click "Crear transacción" with JS | Opens a jQuery UI modal, no navigation |
| Same link, JS disabled | Navigates to `node/%nid/claim-transaction/add`, full page form |
| Fields shown | Exactly `field_status`, `field_status_date`, `field_comment`, `field_images`, `field_attachment` — never `field_claim` |
| `field_status_date` default | Today |
| Submit | Creates the transaction, redirects to `node/%nid/edit`, full reload |
| After redirect | New transaction is first in the timeline |
| No `create claim_transaction content`, or no condominium access (SPEC 56) | 403 on the modal route, even with the `nid` known |
| Image > 3 MB, or wrong extension; attachment outside `pdf/doc/docx/xls/xlsx` | Rejected with the native field validation message |

**Status sync matrix**:

| Case | Expected |
|---|---|
| New transaction, different status than the claim | Claim updates to the new status |
| New transaction, same status as the claim | Claim's `changed` timestamp does not advance |
| Created via the modal | Sync fires (via `hook_node_insert()`) |
| Created via `node/add/claim_transaction` (native, `administrator`/`backend`) | Sync fires the same way — the rule lives in the hook, not the modal |
| Edited via `node/%nid/edit` of a transaction (native) | Sync fires via `hook_node_update()` |
| Claim re-saved by the sync | Does **not** create a new transaction |

**No regression / infra**:

- `resources/*.resource.inc` does not appear in the diff of this spec.
- `hook_menu()` gained exactly one new route, `node/%node/claim-transaction/add`;
  no `api/v1/...` path changed.
- The existing `'pagos'`, `'boletin'`, `'recibo'`, `'alicuota_extra'`,
  `'reservation'` branches of `myapi_node_insert()`/`myapi_node_update()`
  are unchanged.
- `drush cc all` reports no errors.
