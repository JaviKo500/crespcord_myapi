<?php

/**
 * @file
 * Back-office claim transaction timeline and creation modal (SPEC 57).
 *
 * Everything behind node/%node/claim-transaction/add and the transactions
 * fieldset added to node/%nid/edit of a 'reclamo' lives here. It is NOT an
 * API endpoint — nothing here returns the JSON envelope, and no resource
 * under resources/ calls into it.
 *
 * The status catalogue is NOT duplicated here: myapi_claims_status_options()
 * (includes/myapi.claims_admin.inc, SPEC 56) already reads field_status'
 * allowed_values dynamically, and field_status is a single field shared by
 * both the 'reclamo' and 'claim_transaction' bundles (see myapi.install), so
 * the same function serves both screens without change. Loaded with
 * module_load_include() rather than repeating the pattern, per Regla 3 de
 * CLAUDE.md.
 */

module_load_include('inc', 'myapi', 'includes/myapi.claims_admin');

/**
 * Reads the transaction timeline of a claim, most recent first.
 *
 * One row per published 'claim_transaction' node whose field_claim points at
 * $claim_nid. LEFT JOINed to field_status, field_status_date and
 * field_comment (any of which could in principle be missing) and to users
 * via the transaction node's own uid (its author) — same LEFT JOIN criterion
 * as myapi_claims_list_rows(): a row missing one of these values still
 * appears rather than disappearing from the timeline.
 *
 * ORDER BY field_status_date DESC, nid DESC: field_status_date is a
 * day-granularity 'datetime' field (same storage shape as
 * field_reception_date on 'reclamo'), so ordering by the raw joined column is
 * equivalent to ordering by its date part alone. The nid tie-break makes the
 * order deterministic when two transactions share a status date.
 *
 * @param int $claim_nid
 *   The 'reclamo' node id whose transactions to list.
 *
 * @return array
 *   One object per transaction, each with: nid, status, status_date (
 *   'Y-m-d'), comment, uid, user_name, created. Fetched eagerly — the
 *   expected volume per claim is low (see SPEC 57 scope), so no pager.
 */
function myapi_claim_transaction_timeline_rows($claim_nid) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'uid', 'created'))
    ->condition('n.type', 'claim_transaction')
    ->condition('n.status', 1);

  $query->leftJoin('field_data_field_claim', 'fcl', "fcl.entity_id = n.nid AND fcl.entity_type = 'node' AND fcl.deleted = 0");
  $query->condition('fcl.field_claim_target_id', $claim_nid);

  $query->leftJoin('field_data_field_status', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->addField('fs', 'field_status_value', 'status');

  $query->leftJoin('field_data_field_status_date', 'fsd', "fsd.entity_id = n.nid AND fsd.entity_type = 'node' AND fsd.deleted = 0");
  $query->addExpression('SUBSTR(fsd.field_status_date_value, 1, 10)', 'status_date');

  $query->leftJoin('field_data_field_comment', 'fcm', "fcm.entity_id = n.nid AND fcm.entity_type = 'node' AND fcm.deleted = 0");
  $query->addField('fcm', 'field_comment_value', 'comment');

  $query->leftJoin('users', 'u', 'u.uid = n.uid');
  $query->addField('u', 'name', 'user_name');

  $query->orderBy('fsd.field_status_date_value', 'DESC');
  $query->orderBy('n.nid', 'DESC');

  return $query->execute()->fetchAll();
}

/* ---------------------------------------------------------------------------
 * Creation form (SPEC 57 modal).
 * ------------------------------------------------------------------------ */

/**
 * FAPI form to create one 'claim_transaction' node.
 *
 * A form of its own — not the native 'claim_transaction' node form — so it
 * shows exactly the 4 fields the timeline's "Crear transacción" action needs
 * and hides field_claim entirely (it travels as the 'claim_nid' #type=>value
 * element instead, fixed to $claim_node->nid). Every field is built by hand
 * and, in the submit handler, written straight onto the node object — the
 * same manual field-assignment style myapi_node_insert()'s 'reclamo' branch
 * uses for the automatic initial transaction — rather than going through
 * field_default_form(), which would drag in the native form's multi-step
 * machinery this form is explicitly avoiding.
 *
 * field_images/field_attachment validators and upload URI are NOT
 * hardcoded to SPEC 55's "3 MB, png/jpg/jpeg" / "3 MB,
 * pdf/doc/docx/xls/xlsx": file_field_widget_upload_validators() and
 * file_field_widget_uri() are Drupal core's own functions (modules/file/
 * file.field.inc), the exact ones the native widget calls, reading the real
 * field/instance settings — so the limits can never drift from what SPEC 55
 * actually configured. Same reasoning as reusing myapi_claims_status_options()
 * instead of a hardcoded catalogue.
 *
 * @param array $form
 * @param array $form_state
 * @param object $claim_node
 *   The 'reclamo' node this transaction belongs to.
 */
function myapi_claim_transaction_create_form($form, &$form_state, $claim_node) {
  $form['field_status'] = array(
    '#type' => 'select',
    '#title' => t('Estado'),
    '#options' => myapi_claims_status_options(),
    '#required' => TRUE,
  );

  // #date_format limited to the date part only: field_status_date's
  // granularity is day-only (myapi.install), so no time picker is shown, and
  // $form_state['values']['field_status_date'] comes back as array('date' =>
  // 'Y-m-d'), no 'time' key — see date_popup_element_value_callback().
  $form['field_status_date'] = array(
    '#type' => 'date_popup',
    '#title' => t('Fecha de estado'),
    '#date_format' => 'Y-m-d',
    '#default_value' => date('Y-m-d') . ' 00:00:00',
    '#required' => TRUE,
  );

  $form['field_comment'] = array(
    '#type' => 'textarea',
    '#title' => t('Comentario'),
  );

  $images_field = field_info_field('field_images');
  $images_instance = field_info_instance('node', 'field_images', 'claim_transaction');
  $form['field_images'] = array(
    '#type' => 'managed_file',
    '#title' => t('Imágenes'),
    '#upload_validators' => file_field_widget_upload_validators($images_field, $images_instance),
    '#upload_location' => file_field_widget_uri($images_field, $images_instance),
  );

  $attachment_field = field_info_field('field_attachment');
  $attachment_instance = field_info_instance('node', 'field_attachment', 'claim_transaction');
  $form['field_attachment'] = array(
    '#type' => 'managed_file',
    '#title' => t('Adjunto'),
    '#upload_validators' => file_field_widget_upload_validators($attachment_field, $attachment_instance),
    '#upload_location' => file_field_widget_uri($attachment_field, $attachment_instance),
  );

  $form['claim_nid'] = array(
    '#type' => 'value',
    '#value' => $claim_node->nid,
  );

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Guardar'),
  );

  return $form;
}

/**
 * Submit handler of myapi_claim_transaction_create_form().
 *
 * Builds and saves the 'claim_transaction' node by hand (field_claim = the
 * claim this form was opened for, uid = the current user), then redirects
 * back to the claim's edit page — a normal POST/redirect, no #ajax
 * replace, so a failed request never risks losing unsaved edits on the
 * claim's own form (see SPEC 57 "Decisiones tomadas y descartadas").
 *
 * hook_node_insert()'s 'claim_transaction' branch (myapi.module) is what
 * actually syncs the claim's field_status afterwards — nothing here does
 * that directly, so the same sync rule also covers the native
 * node/add/claim_transaction form used by 'administrator'/'backend'.
 */
function myapi_claim_transaction_create_form_submit($form, &$form_state) {
  global $user;

  $claim_nid = $form_state['values']['claim_nid'];

  $transaction = (object) array(
    'type' => 'claim_transaction',
    'uid' => $user->uid,
    'status' => 1,
  );
  node_object_prepare($transaction);

  $transaction->field_claim[LANGUAGE_NONE][0]['target_id'] = $claim_nid;
  $transaction->field_status[LANGUAGE_NONE][0]['value'] = $form_state['values']['field_status'];
  $transaction->field_status_date[LANGUAGE_NONE][0]['value'] = $form_state['values']['field_status_date']['date'];

  if ($form_state['values']['field_comment'] !== '') {
    $transaction->field_comment[LANGUAGE_NONE][0]['value'] = $form_state['values']['field_comment'];
  }

  if (!empty($form_state['values']['field_images']['fid'])) {
    $transaction->field_images[LANGUAGE_NONE][0]['fid'] = $form_state['values']['field_images']['fid'];
  }

  if (!empty($form_state['values']['field_attachment']['fid'])) {
    $transaction->field_attachment[LANGUAGE_NONE][0]['fid'] = $form_state['values']['field_attachment']['fid'];
  }

  node_save($transaction);

  $form_state['redirect'] = 'node/' . $claim_nid . '/edit';
}

/* ---------------------------------------------------------------------------
 * Modal page callback.
 * ------------------------------------------------------------------------ */

/**
 * Page callback of node/%node/claim-transaction/add (SPEC 57).
 *
 * Access is already resolved by myapi_claim_transaction_modal_access()
 * (myapi.module) before this runs — $claim_node is guaranteed to be a
 * 'reclamo' the current user may update.
 *
 * Two behaviours on the same route, chosen at request time:
 * - No JS / direct URL: returns the form as a normal render array, delivered
 *   as an ordinary HTML page (the menu item declares no 'delivery callback',
 *   so drupal_deliver_html_page() handles it exactly like any other page).
 * - Through the 'use-ajax' link added by myapi_form_reclamo_node_form_alter()
 *   (a later step): renders the same form and returns it as AJAX commands
 *   instead — ajax_command_html() fills the '#myapi-claim-transaction-modal'
 *   container that same later step adds (hidden, empty) to node/%nid/edit,
 *   then ajax_command_invoke() calls jQuery UI's .dialog() on that container
 *   to open it. Both are real functions of D7 core's includes/ajax.inc.
 *   ajax_command_open_modal_dialog() is deliberately NOT used: it does not
 *   exist in this Drupal 7.64 core (confirmed in the SPEC 57 plan's step 1
 *   spike, by listing every ajax_command_* function actually defined there).
 *
 * The AJAX branch is detected with the same signal Drupal core itself checks
 * in this situation — $_POST['ajax_page_state'] non-empty — see
 * ajax_base_page_theme() and the top of ajax_render(), both in
 * includes/ajax.inc. misc/ajax.js hardcodes every Drupal.ajax request
 * (including a plain '.use-ajax' link) to POST with that key always present,
 * so the check is reliable without any custom JS: core's own
 * Drupal.behaviors.AJAX already binds '.use-ajax' links automatically to any
 * element found in the DOM with that class, and drives the request/response
 * cycle end to end.
 *
 * ajax_render() is called directly, and the response is printed and the
 * request exited by hand, rather than declaring
 * 'delivery callback' => 'ajax_deliver' on the menu item: that would make
 * every request to this route an unconditional AJAX response, including the
 * no-JS one this same function also has to serve. Calling ajax_render() from
 * inside a page callback and terminating manually is the exact pattern its
 * own docblock documents: "some contributed and custom modules render Ajax
 * responses without using that delivery callback."
 *
 * drupal_add_library('system', 'ui.dialog') is called unconditionally, not
 * only on the AJAX branch: on a full page load it just adds the library to
 * the normal <head> like any other; on the AJAX branch, ajax_render()'s own
 * new-CSS/JS diffing (against $_POST['ajax_page_state']) picks it up and
 * prepends the extra commands needed to load it — nothing in this function
 * has to build that itself.
 *
 * @param object $claim_node
 *   The 'reclamo' node loaded from the %node wildcard.
 *
 * @return array|null
 *   A render array (the form) on the no-JS path. On the AJAX path, prints
 *   the JSON response itself and never returns (drupal_exit()).
 */
function myapi_claim_transaction_modal_callback($claim_node) {
  drupal_add_library('system', 'ui.dialog');

  $form = drupal_get_form('myapi_claim_transaction_create_form', $claim_node);

  if (empty($_POST['ajax_page_state'])) {
    return $form;
  }

  $commands = array();
  $commands[] = ajax_command_html('#myapi-claim-transaction-modal', drupal_render($form));
  $commands[] = ajax_command_invoke('#myapi-claim-transaction-modal', 'dialog', array(array(
    'modal' => TRUE,
    'width' => 500,
    'title' => t('Crear transacción'),
  )));

  drupal_add_http_header('Content-Type', 'application/json; charset=utf-8');
  print ajax_render($commands);
  drupal_exit();
}

/* ---------------------------------------------------------------------------
 * 'reclamo' node form alter (SPEC 57).
 * ------------------------------------------------------------------------ */

/**
 * Delegate of myapi_form_reclamo_node_form_alter() (myapi.module).
 *
 * Only touches the form in edit mode ($form['#node']->nid set). On
 * node/add/reclamo the newly-created claim's field_status is exactly what
 * myapi_node_insert()'s 'reclamo' branch copies into the automatic initial
 * transaction (see Modelo de datos), so it stays untouched and editable
 * there — deliberately, not an oversight (see SPEC 57 "Decisiones tomadas y
 * descartadas").
 *
 * #disabled, not #access = FALSE: the value stays visible and Drupal still
 * resubmits a disabled element's #default_value, so saving the rest of the
 * claim's form never blanks field_status. Same traversal pattern as
 * myapi_building_admin_restrict_bulletin_form() (includes/myapi.building_admin.inc):
 * field_status is a single-value ('options_select') field, so
 * element_children() over the langcode level is enough — no per-delta loop
 * is needed for a cardinality-1 field.
 *
 * @param array $form
 * @param array $form_state
 */
function myapi_claim_transaction_reclamo_form_alter(&$form, &$form_state) {
  if (empty($form['#node']->nid)) {
    return;
  }

  foreach (element_children($form['field_status']) as $langcode) {
    $form['field_status'][$langcode]['#disabled'] = TRUE;
  }

  $form['myapi_claim_transactions'] = array(
    '#type' => 'fieldset',
    '#title' => t('Línea de tiempo de transacciones'),
    '#weight' => 900,
  ) + myapi_claim_transaction_timeline_build($form['#node']);
}

/* ---------------------------------------------------------------------------
 * Timeline render.
 * ------------------------------------------------------------------------ */

/**
 * Human label for the author of a timeline row.
 *
 * Only two cases, unlike myapi_claims_requester_label() (SPEC 56): 'uid'
 * here is the transaction node's own native author column (node.uid),
 * never NULL, so there is no "nobody set" case to cover — only "the account
 * was deleted since" (user_name NULL through the LEFT JOIN) versus present.
 *
 * @param object $row  Row from myapi_claim_transaction_timeline_rows().
 *
 * @return string  Already escaped.
 */
function myapi_claim_transaction_author_label($row) {
  if ($row->user_name === NULL) {
    return t('Usuario eliminado (#@uid)', array('@uid' => $row->uid));
  }
  return check_plain($row->user_name);
}

/**
 * Builds the timeline table body from the query rows.
 *
 * Status is labelled with myapi_claims_status_label() (SPEC 56,
 * includes/myapi.claims_admin.inc) — already loaded at the top of this file
 * — for the same reuse reason as myapi_claims_status_options(): one field,
 * one label source, shared by both bundles.
 *
 * @param array $rows  Rows from myapi_claim_transaction_timeline_rows().
 *
 * @return array  One row per transaction, ready for '#theme' => 'table'.
 */
function myapi_claim_transaction_timeline_table_rows(array $rows) {
  $table_rows = array();

  foreach ($rows as $row) {
    $table_rows[] = array(
      myapi_claims_status_label($row->status),
      $row->status_date === NULL ? '—' : check_plain(format_date(strtotime($row->status_date), 'custom', 'd/m/Y')),
      ($row->comment === NULL || $row->comment === '') ? '—' : check_plain($row->comment),
      myapi_claim_transaction_author_label($row),
    );
  }

  return $table_rows;
}

/**
 * Builds the transactions timeline block: the 'Crear transacción' link,
 * the read-only table, and the hidden modal container.
 *
 * The link comes FIRST (SPEC 57 acceptance criteria: "al inicio de la línea
 * de tiempo, no al final"), with class 'use-ajax' so
 * Drupal.behaviors.AJAX (misc/ajax.js, D7 core) binds it automatically —
 * see myapi_claim_transaction_modal_callback() for the request/response
 * cycle this triggers.
 *
 * '#myapi-claim-transaction-modal' is added here, empty and hidden
 * (display: none until jQuery UI's .dialog() takes over its visibility),
 * because it must already exist in the DOM of node/%nid/edit for
 * ajax_command_html()/ajax_command_invoke() to have a selector to target —
 * the modal callback never creates its own container.
 *
 * @param object $claim_node
 *   The 'reclamo' node being edited.
 *
 * @return array  A render array.
 */
function myapi_claim_transaction_timeline_build($claim_node) {
  $rows = myapi_claim_transaction_timeline_rows($claim_node->nid);

  $build = array();

  $build['create_link'] = array(
    '#markup' => '<p>' . l(t('Crear transacción'), 'node/' . $claim_node->nid . '/claim-transaction/add', array(
      'attributes' => array('class' => array('use-ajax', 'button')),
    )) . '</p>',
  );

  $build['table'] = array(
    '#theme' => 'table',
    '#header' => array(t('Estado'), t('Fecha de estado'), t('Comentario'), t('Autor')),
    '#rows' => myapi_claim_transaction_timeline_table_rows($rows),
    '#empty' => t('Todavía no hay transacciones para este reclamo.'),
  );

  $build['modal_container'] = array(
    '#markup' => '<div id="myapi-claim-transaction-modal" style="display: none;"></div>',
  );

  return $build;
}

/* ---------------------------------------------------------------------------
 * Automatic initial transaction (SPEC 57).
 * ------------------------------------------------------------------------ */

/**
 * Creates the automatic initial transaction of a newly-created 'reclamo'.
 *
 * Delegate of myapi_node_insert()'s 'reclamo' branch (myapi.module).
 * hook_node_insert() covers every creation path of a 'reclamo' — the admin
 * node form, and any future programmatic one (migration, api/v1/... resource)
 * — not just the UI, which is the whole point of hanging this off the node
 * hook rather than the node/add/reclamo form itself (see SPEC 57 "Decisiones
 * tomadas y descartadas").
 *
 * field_status is copied from the claim as chosen on node/add/reclamo — where
 * it stays editable, unlike node/%nid/edit — NOT forced to 'received'. Because
 * the transaction is born with the SAME status the claim already has, the
 * sync branch (myapi_claim_transaction_sync_claim_status(), a later step)
 * never finds a difference and never re-saves the claim: no
 * insert-triggers-update cascade. An acceptance criterion of SPEC 57 checks
 * this directly.
 *
 * Only ever called from hook_node_insert(), never hook_node_update(): editing
 * an existing claim must not create a second initial transaction.
 *
 * myapi_building_admin_field_value() (includes/myapi.building_admin.inc,
 * SPEC 49) is reused as-is rather than re-reading
 * $node->field_status[LANGUAGE_NONE][0]['value'] by hand — same reuse
 * reasoning as everywhere else in this spec.
 *
 * @param object $node
 *   The just-inserted 'reclamo' node.
 */
function myapi_claim_transaction_create_initial($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $transaction = (object) array(
    'type' => 'claim_transaction',
    'uid' => $node->uid,
    'status' => 1,
  );
  node_object_prepare($transaction);

  $transaction->field_claim[LANGUAGE_NONE][0]['target_id'] = $node->nid;
  $transaction->field_status[LANGUAGE_NONE][0]['value'] = myapi_building_admin_field_value($node, 'field_status');
  $transaction->field_status_date[LANGUAGE_NONE][0]['value'] = date('Y-m-d');

  node_save($transaction);
}

/* ---------------------------------------------------------------------------
 * Status sync (SPEC 57).
 * ------------------------------------------------------------------------ */

/**
 * Syncs the parent claim's field_status to a transaction's own, when they
 * differ.
 *
 * Delegate of BOTH myapi_node_insert() and myapi_node_update()'s
 * 'claim_transaction' branches (myapi.module). The rule lives in the node
 * hook, not in myapi_claim_transaction_create_form_submit(), precisely so
 * that node/add/claim_transaction and node/%nid/edit of a transaction (the
 * native forms, for 'administrator'/'backend', which never go through the
 * modal) are covered too, without duplicating the sync in two places. See
 * SPEC 57 "Decisiones tomadas y descartadas".
 *
 * Direction is transaction -> claim only: nothing here ever creates or
 * touches another transaction, so there is no insert/update cascade beyond
 * the one already accounted for by myapi_claim_transaction_create_initial() —
 * that transaction is born with the SAME status the claim already has, so
 * this function never finds a difference for it and never re-saves the
 * claim.
 *
 * myapi_building_admin_field_target_id() / myapi_building_admin_field_value()
 * (includes/myapi.building_admin.inc, SPEC 49) are reused as-is — same
 * reasoning as everywhere else in this spec.
 *
 * @param object $node
 *   The just-saved 'claim_transaction' node.
 */
function myapi_claim_transaction_sync_claim_status($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $claim_nid = myapi_building_admin_field_target_id($node, 'field_claim');
  $claim = $claim_nid ? node_load($claim_nid) : NULL;

  if (!$claim) {
    return;
  }

  $transaction_status = myapi_building_admin_field_value($node, 'field_status');
  $claim_status = myapi_building_admin_field_value($claim, 'field_status');

  if ($transaction_status === $claim_status) {
    return;
  }

  $claim->field_status[LANGUAGE_NONE][0]['value'] = $transaction_status;
  node_save($claim);
}
