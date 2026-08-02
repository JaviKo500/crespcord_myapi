<?php

/**
 * @file
 * Back-office claims listing page (SPEC 56).
 *
 * Serves admin/content/claims: a read-only, server-rendered, paginated table
 * of 'reclamo' nodes with GET filters. It is NOT an API endpoint — nothing
 * here returns the JSON envelope, and no resource under resources/ calls into
 * it. The page lives in includes/ for the same reason
 * includes/myapi.reservation_calendar.inc does: resources/ is for what the
 * Flutter app consumes, and this is back office.
 *
 * The file is loaded by the 'file' key of its hook_menu() entry in
 * myapi.module, so it only reaches PHP on that one route.
 *
 * No AJAX of any kind (SPEC 47's precedent): every filter reloads the page
 * over GET, which is what keeps the URL linkable and the pager's own links
 * (theme('pager')) working with no extra wiring — they carry the current
 * query string automatically.
 *
 * The condominium scoping of an 'administrador edificio' (SPEC 49) is reused
 * wholesale from includes/myapi.reservation_calendar.inc rather than
 * reimplemented: myapi_calendar_condominium_scope(), positive_int(),
 * effective_condominium() and condominium_options() are already generic — none
 * of the four reads or writes anything calendar-specific — and
 * includes/myapi.reservation_notification.inc already reuses
 * myapi_calendar_condominium_options() the same way, by loading that file
 * with module_load_include(). Duplicating that logic here would violate
 * Regla 3 de CLAUDE.md for no gain; the row-level restriction to the assigned
 * condominiums is, in any case, done by ->addTag('node_access') on the query
 * itself (see includes/myapi.claim_query.inc), so this reuse is only about
 * the filter form's selector and the condominium column label, never about
 * access.
 */

module_load_include('inc', 'myapi', 'includes/myapi.claim_query');

/* ---------------------------------------------------------------------------
 * Filters.
 *
 * These touch $_GET and, for the labels, the Field API — so they are not pure
 * in the sense the tests/unit files require, and this page has none. Nothing
 * here ever fails: an invented status, a malformed date or a non-numeric
 * condominium all fall back to "no filter", matching the calendar's
 * criterion.
 * ------------------------------------------------------------------------ */

/**
 * Normalises a raw GET value into one of the five allowed statuses, or NULL.
 *
 * The whitelist is hard-coded rather than read from field_info_field(): the
 * five stored values are part of the data model this spec was written
 * against, and validation must not silently change if a site administrator
 * edits the field's allowed values from the UI. Labels, which carry no such
 * risk, ARE read from the field (see myapi_claims_status_options()).
 *
 * @param mixed $value  Raw $_GET value.
 *
 * @return string|null
 */
function myapi_claims_valid_status($value) {
  $allowed = array('received', 'in_progress', 'resolved', 'closed', 'duplicated');
  return (is_scalar($value) && in_array((string) $value, $allowed, TRUE)) ? (string) $value : NULL;
}

/**
 * Normalises a raw GET value into one of the two allowed claim types, or
 * NULL. Same reasoning as myapi_claims_valid_status().
 *
 * @param mixed $value  Raw $_GET value.
 *
 * @return string|null
 */
function myapi_claims_valid_claim_type($value) {
  $allowed = array('requirement', 'claim');
  return (is_scalar($value) && in_array((string) $value, $allowed, TRUE)) ? (string) $value : NULL;
}

/**
 * Reads the allowed_values of a list_text field, or an empty array when the
 * field does not exist in this environment.
 *
 * @param string $field_name
 *
 * @return array  Stored value => human label.
 */
function myapi_claims_field_allowed_values($field_name) {
  $field = field_info_field($field_name);
  return ($field && isset($field['settings']['allowed_values'])) ? $field['settings']['allowed_values'] : array();
}

/**
 * Status select options: the five allowed keys, labelled from field_status'
 * own allowed_values (myapi.install) so the Spanish text lives in one place.
 * A key with no matching label — the field missing in this environment —
 * falls back to the raw key rather than disappearing from the select.
 *
 * @return array  Stored value => label, in the fixed spec order.
 */
function myapi_claims_status_options() {
  $labels = myapi_claims_field_allowed_values('field_status');
  $options = array();
  foreach (array('received', 'in_progress', 'resolved', 'closed', 'duplicated') as $key) {
    $options[$key] = isset($labels[$key]) ? $labels[$key] : $key;
  }
  return $options;
}

/**
 * Claim-type select options. Same criterion as myapi_claims_status_options().
 *
 * @return array  Stored value => label.
 */
function myapi_claims_claim_type_options() {
  $labels = myapi_claims_field_allowed_values('field_claim_type');
  $options = array();
  foreach (array('requirement', 'claim') as $key) {
    $options[$key] = isset($labels[$key]) ? $labels[$key] : $key;
  }
  return $options;
}

/**
 * Reads and validates the status/claim_type/date_from/date_to GET
 * parameters. The condominium parameter is deliberately NOT here: resolving
 * it needs the user's assigned-condominiums scope (SPEC 49), which
 * myapi_claims_list_page() already reuses from the calendar's helpers, and
 * folding it into this function would mean loading that file from here too
 * for a single value.
 *
 * @return array  Keys: status, claim_type, date_from, date_to. Each is the
 *   validated value or NULL for "no filter".
 */
function myapi_claims_list_filters() {
  // Reused for the exact same 'Y-m-d' + checkdate() rule the reservation
  // endpoints and the calendar already validate dates with.
  module_load_include('inc', 'myapi', 'resources/reservation.resource');

  $status = isset($_GET['status']) ? myapi_claims_valid_status($_GET['status']) : NULL;
  $claim_type = isset($_GET['claim_type']) ? myapi_claims_valid_claim_type($_GET['claim_type']) : NULL;

  $date_from = NULL;
  if (isset($_GET['date_from']) && is_scalar($_GET['date_from'])) {
    $date_from = myapi_reservation_valid_date((string) $_GET['date_from']);
  }

  $date_to = NULL;
  if (isset($_GET['date_to']) && is_scalar($_GET['date_to'])) {
    $date_to = myapi_reservation_valid_date((string) $_GET['date_to']);
  }

  return array(
    'status' => $status,
    'claim_type' => $claim_type,
    'date_from' => $date_from,
    'date_to' => $date_to,
  );
}

/* ---------------------------------------------------------------------------
 * Row labels.
 * ------------------------------------------------------------------------ */

/**
 * Human label for the status of a claims-list row.
 *
 * @param string|null $value
 *
 * @return string  Already escaped.
 */
function myapi_claims_status_label($value) {
  if ($value === NULL) {
    return '—';
  }
  $options = myapi_claims_status_options();
  return check_plain(isset($options[$value]) ? $options[$value] : $value);
}

/**
 * Human label for the claim type of a claims-list row.
 *
 * @param string|null $value
 *
 * @return string  Already escaped.
 */
function myapi_claims_claim_type_label($value) {
  if ($value === NULL) {
    return '—';
  }
  $options = myapi_claims_claim_type_options();
  return check_plain(isset($options[$value]) ? $options[$value] : $value);
}

/**
 * Human label for the requester of a claims-list row.
 *
 * Same three-way pattern as myapi_calendar_user_label() (no requester set /
 * account deleted / present), kept as its own function because the row's
 * property names (requester_uid, requester_name) are the ones documented in
 * the spec's model, not the calendar's (uid, user_name).
 *
 * @param object $row  Row from myapi_claims_list_rows().
 *
 * @return string  Already escaped.
 */
function myapi_claims_requester_label($row) {
  if ($row->requester_uid === NULL) {
    return t('Sin solicitante');
  }
  if ($row->requester_name === NULL) {
    return t('Usuario eliminado (#@uid)', array('@uid' => $row->requester_uid));
  }
  return check_plain($row->requester_name);
}

/**
 * Builds the table body from the query rows.
 *
 * The condominium label reuses myapi_calendar_condominium_label(): the row
 * carries the same ->condominium_id property that function reads, and
 * $condominium_options is the same nid => title map already loaded for the
 * filter form's select, so no second lookup is built here.
 *
 * @param array $rows                 Rows fetched from myapi_claims_list_rows().
 * @param array $condominium_options  nid => title.
 *
 * @return array  One row per claim, ready for '#theme' => 'table'.
 */
function myapi_claims_list_table_rows(array $rows, array $condominium_options) {
  $table_rows = array();

  foreach ($rows as $row) {
    $table_rows[] = array(
      (int) $row->nid,
      l($row->subject, 'node/' . $row->nid . '/edit'),
      myapi_calendar_condominium_label($row, $condominium_options),
      myapi_claims_status_label($row->status),
      myapi_claims_claim_type_label($row->claim_type),
      myapi_claims_requester_label($row),
      $row->reception_date === NULL ? '—' : check_plain(format_date(strtotime($row->reception_date), 'custom', 'd/m/Y')),
    );
  }

  return $table_rows;
}

/* ---------------------------------------------------------------------------
 * Filter form.
 * ------------------------------------------------------------------------ */

/**
 * Builds the filter form of the claims listing.
 *
 * #method = 'get', same criterion as
 * myapi_reservation_calendar_filter_form(): every filter ends up in the query
 * string, so the URL is linkable and the pager keeps them across pages.
 * Drupal's own hidden fields are dropped by the SAME after-build callback the
 * calendar uses — it is entirely generic, it only unsets three keys — so it
 * is reused rather than copied.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters               Validated filters, used as defaults.
 * @param array $condominium_options   nid => title.
 */
function myapi_claims_list_filter_form($form, &$form_state, $filters, $condominium_options) {
  $form['#method'] = 'get';
  // No CSRF token: a read-only GET filter has nothing to forge, and the token
  // would show up in the URL.
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  $form['#action'] = url('admin/content/claims');

  // With clean URLs off the path itself travels in ?q=, and a browser
  // submitting a GET form replaces the whole query string of the action — see
  // myapi_reservation_calendar_filter_form() for the same fix.
  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type' => 'hidden',
      '#value' => 'admin/content/claims',
    );
  }

  $form['condominium'] = array(
    '#type' => 'select',
    '#title' => t('Condominio'),
    '#options' => array('' => t('- Todos -')) + $condominium_options,
    '#default_value' => $filters['condominium'] === NULL ? '' : $filters['condominium'],
  );

  $form['status'] = array(
    '#type' => 'select',
    '#title' => t('Estado'),
    '#options' => array('' => t('- Todos -')) + myapi_claims_status_options(),
    '#default_value' => $filters['status'] === NULL ? '' : $filters['status'],
  );

  $form['claim_type'] = array(
    '#type' => 'select',
    '#title' => t('Tipo'),
    '#options' => array('' => t('- Todos -')) + myapi_claims_claim_type_options(),
    '#default_value' => $filters['claim_type'] === NULL ? '' : $filters['claim_type'],
  );

  // Native HTML5 date inputs, written by hand for the same reason the
  // calendar's own 'date' field is: theme_textfield() forces type="text" and
  // would undo an #attributes override. A browser without support renders a
  // plain text box, which still submits 'YYYY-MM-DD' if typed by hand.
  $form['date_from'] = array(
    '#type' => 'item',
    '#title' => t('Fecha de recepción desde'),
    '#markup' => '<input type="date" name="date_from" value="' . check_plain((string) $filters['date_from']) . '" />',
  );
  $form['date_to'] = array(
    '#type' => 'item',
    '#title' => t('Fecha de recepción hasta'),
    '#markup' => '<input type="date" name="date_to" value="' . check_plain((string) $filters['date_to']) . '" />',
  );

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/* ---------------------------------------------------------------------------
 * Page.
 * ------------------------------------------------------------------------ */

/**
 * Page callback of admin/content/claims.
 *
 * Read-only: validates the filters, runs ONE paginated query and renders a
 * table plus the 'Crear reclamo' button. Nothing here creates, edits or
 * deletes a claim — the row action and the button both point at Drupal's own
 * node/add and node/%/edit, never a page of this module's own.
 */
function myapi_claims_list_page() {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  // SPEC 49: NULL for a user who sees every condominium ('administrator',
  // 'backend'), the assigned list (possibly empty) for an 'administrador
  // edificio'. Everything below flows from it, same as the calendar page.
  $scope = myapi_calendar_condominium_scope();
  $condominium_options = myapi_calendar_condominium_options($scope);

  $requested = isset($_GET['condominium']) ? myapi_calendar_positive_int($_GET['condominium']) : NULL;
  $condominium = myapi_calendar_effective_condominium($requested, $scope);

  $filters = myapi_claims_list_filters();

  // A list (rather than a single nid) means no single condominium of this
  // user's was named — either none at all or somebody else's — so the select
  // must show nothing chosen and the query gets no condominium filter of its
  // own. The rows are still restricted to the assigned set: that is what
  // ->addTag('node_access') on the query does, not this filter.
  $filters['condominium'] = is_array($condominium) ? NULL : $condominium;

  $query = myapi_claims_list_rows(
    $filters['condominium'],
    $filters['status'],
    $filters['claim_type'],
    $filters['date_from'],
    $filters['date_to']
  );
  $rows = $query->execute()->fetchAll();

  $build = array();

  $build['create'] = array(
    '#markup' => '<p>' . l(t('Crear reclamo'), 'node/add/reclamo', array(
      'attributes' => array('class' => array('button')),
    )) . '</p>',
  );

  $build['filters'] = drupal_get_form('myapi_claims_list_filter_form', $filters, $condominium_options);

  $build['table'] = array(
    '#theme' => 'table',
    '#header' => array(
      t('ID'),
      t('Asunto'),
      t('Condominio'),
      t('Estado'),
      t('Tipo'),
      t('Solicitante'),
      t('Fecha de recepción'),
    ),
    '#rows' => myapi_claims_list_table_rows($rows, $condominium_options),
    '#empty' => t('No hay reclamos para mostrar.'),
  );

  $build['pager'] = array(
    '#markup' => theme('pager'),
  );

  return $build;
}
