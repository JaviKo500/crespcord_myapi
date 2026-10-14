<?php

/**
 * @file
 * Back-office reservation listing page (SPEC 131).
 *
 * Serves admin/content/reservations: a read-only, server-rendered, paginated
 * table of 'reservation' nodes with GET filters — the list companion of the
 * calendar of SPEC 47. It is NOT an API endpoint: nothing here returns the
 * JSON envelope, and no resource under resources/ calls into it.
 *
 * The file is loaded by the 'file' key of its hook_menu() entry in
 * myapi.module, so it only reaches PHP on that one route. Its CSS — the filter
 * row of css/myapi.claims.css, shared with the other back-office listings — is
 * added from the page callback with drupal_add_css().
 *
 * No AJAX of any kind (SPEC 47/56/125 precedent): every filter reloads the
 * page over GET, which keeps the URL linkable and the pager's links working.
 *
 * ------------------------------------------------------------------------
 * WHY THE LISTING QUERY LIVES HERE AND NOT IN
 * includes/myapi.reservation_query.inc
 * ------------------------------------------------------------------------
 *
 * Same criterion as decision 7 of SPEC 125: the query carries
 * ->addTag('node_access'), which is the whole access rule of this page, and it
 * stays next to the only page that runs it rather than among the API's
 * queries.
 *
 * ------------------------------------------------------------------------
 * WHAT IS REUSED
 * ------------------------------------------------------------------------
 *
 *  - The condominium scope of an 'administrador edificio' and the filter
 *    form plumbing: myapi_calendar_condominium_scope(), _positive_int(),
 *    _effective_condominium(), _condominium_options(), _area_options() and
 *    myapi_reservation_calendar_filter_form_after_build().
 *  - The requester's name: myapi_calendar_user_name_label(), so the listing
 *    and the calendar's emails print the same person the same way.
 *  - The two catalogues — field_reservation_status and field_cancelled_by —
 *    are the fields' own allowed_values, read with
 *    myapi_claims_field_allowed_values(). This page declares no label of its
 *    own.
 */

module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');
module_load_include('inc', 'myapi', 'includes/myapi.claims_admin');

/* ---------------------------------------------------------------------------
 * Catalogues.
 * ------------------------------------------------------------------------ */

/**
 * The status select options: field_reservation_status' allowed_values.
 *
 * @return array
 *   Stored value => human label. Empty when the field does not exist.
 */
function myapi_reservations_admin_status_options() {
  return myapi_claims_field_allowed_values('field_reservation_status');
}

/* ---------------------------------------------------------------------------
 * Filters.
 *
 * NOTHING HERE EVER FAILS. A malformed value of any parameter falls back to
 * "no filter", the criterion of every back-office listing of the module.
 * ------------------------------------------------------------------------ */

/**
 * Reads and validates the area/status/date_from/date_to GET parameters.
 *
 * The condominium is deliberately NOT here, for the reason
 * myapi_claims_list_filters() gives: it needs the reader's scope (SPEC 49),
 * which the page resolves with the calendar's helpers.
 *
 * The area is validated as a POSITIVE INTEGER ONLY; whether it belongs to the
 * effective condominium is decided by myapi_reservations_admin_effective_area()
 * against the options the page already loaded for the select.
 *
 * @return array
 *   Keys: area (int), status, date_from, date_to ('Y-m-d'). NULL means no
 *   filter.
 */
function myapi_reservations_admin_list_filters() {
  // The same 'Y-m-d' + checkdate() rule every other listing validates with.
  module_load_include('inc', 'myapi', 'resources/reservation.resource');

  $area = NULL;
  if (isset($_GET['area'])) {
    $area = myapi_calendar_positive_int($_GET['area']);
  }

  $status = NULL;
  if (isset($_GET['status']) && is_scalar($_GET['status'])) {
    $statuses = myapi_reservations_admin_status_options();
    if (isset($statuses[(string) $_GET['status']])) {
      $status = (string) $_GET['status'];
    }
  }

  $date_from = NULL;
  if (isset($_GET['date_from']) && is_scalar($_GET['date_from'])) {
    $date_from = myapi_reservation_valid_date((string) $_GET['date_from']);
  }

  $date_to = NULL;
  if (isset($_GET['date_to']) && is_scalar($_GET['date_to'])) {
    $date_to = myapi_reservation_valid_date((string) $_GET['date_to']);
  }

  return array(
    'area'      => $area,
    'status'    => $status,
    'date_from' => $date_from,
    'date_to'   => $date_to,
  );
}

/**
 * Keeps the requested area only when it is one of the offered ones.
 *
 * The options are myapi_calendar_area_options() of the EFFECTIVE condominium,
 * so a hand-typed area of another building filters nothing — and with no
 * condominium selected there is nothing to pick from, so ?area= is dropped.
 *
 * @param int|null $requested
 *   From myapi_reservations_admin_list_filters().
 * @param array $options
 *   nid => title.
 *
 * @return int|null
 *   The area nid to filter by, or NULL for no filter.
 */
function myapi_reservations_admin_effective_area($requested, array $options) {
  if ($requested === NULL) {
    return NULL;
  }

  return isset($options[$requested]) ? (int) $requested : NULL;
}

/* ---------------------------------------------------------------------------
 * The query.
 * ------------------------------------------------------------------------ */

/**
 * The listing query: one row per published reservation (SPEC 131).
 *
 * ->addTag('node_access') IS THE ACCESS RULE OF THIS PAGE. It is what makes
 * myapi_building_admin_alter_node_query() narrow an 'administrador edificio'
 * to their own condominiums, through the 'direct' / field_condominium entry
 * 'reservation' has had in myapi_building_admin_condominium_map() since SPEC
 * 49. No condominium list is passed in here and no second filter repeats that
 * rule.
 *
 * EVERY JOIN IS LEFT, unlike myapi_reservation_calendar_rows(), which INNERs
 * the date, the times and the status because it has to paint the row on a
 * grid. This page is where a broken reservation gets found: a row with no
 * date or an area that was deleted keeps its place and shows an em dash.
 *
 * The date range compares the DAY of field_date (a datetime stored as
 * 'Y-m-d H:i:s') with SUBSTR, both bounds inclusive — the same whole-day
 * reading as the '00:00:00' / '23:59:59' of the other listings, written on the
 * column's own shape. It filters the day OF THE BOOKING, never n.created.
 *
 * No join can multiply rows: every field joined has cardinality 1 and every
 * joined base table is keyed by its primary key.
 *
 * @param int|null $condominium_id
 *   'condominio' nid, compared against the raw target_id.
 * @param int|null $area_id
 *   'area' nid, compared against the raw target_id.
 * @param string|null $status
 *   One key of myapi_reservations_admin_status_options().
 * @param string|null $date_from
 *   'Y-m-d', inclusive.
 * @param string|null $date_to
 *   'Y-m-d', inclusive.
 *
 * @return SelectQuery
 *   Extended with PagerDefault and limited to 20 rows; the caller executes it.
 */
function myapi_reservations_admin_list_rows($condominium_id = NULL, $area_id = NULL, $status = NULL, $date_from = NULL, $date_to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'created'))
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);
  $query->addTag('node_access');

  // The bundle and the published flag travel in each node join, so a
  // reference to something else can never paint its title here.
  $query->leftJoin('field_data_field_condominium', 'fco', "fco.entity_id = n.nid AND fco.entity_type = 'node' AND fco.deleted = 0");
  $query->leftJoin('node', 'nc', "nc.nid = fco.field_condominium_target_id AND nc.type = 'condominio' AND nc.status = 1");
  $query->addField('nc', 'title', 'condominium_title');

  $query->leftJoin('field_data_field_area', 'far', "far.entity_id = n.nid AND far.entity_type = 'node' AND far.deleted = 0");
  $query->leftJoin('node', 'na', "na.nid = far.field_area_target_id AND na.type = 'area'");
  $query->addField('na', 'title', 'area_title');

  $query->leftJoin('field_data_field_unit', 'fun', "fun.entity_id = n.nid AND fun.entity_type = 'node' AND fun.deleted = 0");
  $query->leftJoin('node', 'nu', "nu.nid = fun.field_unit_target_id AND nu.type = 'vivienda'");
  $query->addField('nu', 'title', 'unit_title');

  // The requester, with the profile name myapi_calendar_user_name_label()
  // reads — the same four columns, under the same names, as the calendar.
  $query->leftJoin('field_data_field_requester', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->addField('fr', 'field_requester_target_id', 'uid');
  $query->leftJoin('users', 'u', 'u.uid = fr.field_requester_target_id');
  $query->addField('u', 'name', 'user_name');
  $query->leftJoin('field_data_field_nombre', 'fnm', "fnm.entity_id = u.uid AND fnm.entity_type = 'user' AND fnm.deleted = 0");
  $query->addField('fnm', 'field_nombre_value', 'user_first_name');
  $query->leftJoin('field_data_field_apellidos', 'fln', "fln.entity_id = u.uid AND fln.entity_type = 'user' AND fln.deleted = 0");
  $query->addField('fln', 'field_apellidos_value', 'user_last_name');

  $query->leftJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->addField('fd', 'field_date_value', 'reservation_date');
  $query->leftJoin('field_data_field_start_time', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->addField('fst', 'field_start_time_value', 'start_time');
  $query->leftJoin('field_data_field_end_time', 'fet', "fet.entity_id = n.nid AND fet.entity_type = 'node' AND fet.deleted = 0");
  $query->addField('fet', 'field_end_time_value', 'end_time');

  $query->leftJoin('field_data_field_reservation_status', 'frs', "frs.entity_id = n.nid AND frs.entity_type = 'node' AND frs.deleted = 0");
  $query->addField('frs', 'field_reservation_status_value', 'status');
  $query->leftJoin('field_data_field_cancelled_by', 'fcb', "fcb.entity_id = n.nid AND fcb.entity_type = 'node' AND fcb.deleted = 0");
  $query->addField('fcb', 'field_cancelled_by_value', 'cancelled_by');
  $query->leftJoin('field_data_field_cancel_reason', 'fcr', "fcr.entity_id = n.nid AND fcr.entity_type = 'node' AND fcr.deleted = 0");
  $query->addField('fcr', 'field_cancel_reason_value', 'cancel_reason');

  // Optional filters, on the left-joined columns: a reservation with no value
  // in one of them is also excluded when filtering by it.
  if ($condominium_id !== NULL) {
    $query->condition('fco.field_condominium_target_id', $condominium_id);
  }
  if ($area_id !== NULL) {
    $query->condition('far.field_area_target_id', $area_id);
  }
  if ($status !== NULL) {
    $query->condition('frs.field_reservation_status_value', $status);
  }
  if ($date_from !== NULL) {
    $query->where('SUBSTR(fd.field_date_value, 1, 10) >= :date_from', array(':date_from' => $date_from));
  }
  if ($date_to !== NULL) {
    $query->where('SUBSTR(fd.field_date_value, 1, 10) <= :date_to', array(':date_to' => $date_to));
  }

  // Decision 6 of SPEC 131: the furthest booking on top. Times are
  // zero-padded 'HH:MM', so the string sort is clock order; the nid makes the
  // order deterministic across pages.
  $query->orderBy('fd.field_date_value', 'DESC');
  $query->orderBy('fst.field_start_time_value', 'DESC');
  $query->orderBy('n.nid', 'DESC');

  return $query->extend('PagerDefault')->limit(20);
}

/* ---------------------------------------------------------------------------
 * Row labels.
 *
 * Every one of them answers ALREADY ESCAPED text and falls back to an em dash
 * instead of an empty cell.
 * ------------------------------------------------------------------------ */

/**
 * Human label for a free-text cell: area, condominium, unit.
 *
 * @param string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_reservations_admin_text_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  return check_plain($value);
}

/**
 * Human label for the reservation date: the day only, 'd/m/Y'.
 *
 * field_date is a datetime with no time granularity, stored as a string; a
 * value that cannot be read answers the em dash rather than 01/01/1970.
 *
 * @param string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_reservations_admin_date_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  $timestamp = strtotime($value);
  if ($timestamp === FALSE) {
    return '—';
  }

  return check_plain(format_date($timestamp, 'custom', 'd/m/Y'));
}

/**
 * Human label for the creation date, 'd/m/Y H:i'.
 *
 * @param int|string $value
 *   n.created, a Unix timestamp.
 *
 * @return string
 *   Already escaped.
 */
function myapi_reservations_admin_created_label($value) {
  return check_plain(format_date((int) $value, 'custom', 'd/m/Y H:i'));
}

/**
 * Human label for the schedule: 'HH:MM – HH:MM'.
 *
 * The values are printed as stored: they are already 'HH:MM' (the rule of
 * includes/myapi.time_format.inc), and an overnight booking keeps its wrapped
 * end ('22:00 – 02:00', SPEC 41). Either end missing answers the em dash — a
 * half schedule reads as a whole one.
 *
 * @param string|null $start
 * @param string|null $end
 *
 * @return string
 *   Already escaped.
 */
function myapi_reservations_admin_schedule_label($start, $end) {
  if ($start === NULL || $start === '' || $end === NULL || $end === '') {
    return '—';
  }

  return check_plain($start . ' – ' . $end);
}

/**
 * Human label for the status. An unknown key prints RAW rather than vanishing.
 *
 * @param string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_reservations_admin_status_label($value) {
  if ($value === NULL) {
    return '—';
  }

  $options = myapi_reservations_admin_status_options();

  return check_plain(isset($options[$value]) ? $options[$value] : $value);
}

/**
 * Human label for the requester.
 *
 * Three shapes: nobody set ('Sin solicitante'), a deleted account
 * ('Usuario eliminado (#uid)'), or the person — and for the last two the
 * calendar's own myapi_calendar_user_name_label() decides, so the full name
 * leads and the account name is the fallback, exactly as in the reservation
 * emails. Only the first shape is worded differently from the calendar's,
 * because the column is "Solicitante".
 *
 * @param object $row
 *   Row from myapi_reservations_admin_list_rows().
 *
 * @return string
 *   Already escaped.
 */
function myapi_reservations_admin_requester_label($row) {
  if ($row->uid === NULL) {
    return t('Sin solicitante');
  }

  return myapi_calendar_user_name_label($row);
}

/**
 * Human label for the cancellation: who, and why.
 *
 * 'Usuario: <reason>' / 'Admin: <reason>', the "who" being the label of
 * field_cancelled_by's own allowed_values. With no reason, only who. With
 * nobody, the em dash. A blank reason counts as none.
 *
 * @param string|null $cancelled_by
 * @param string|null $reason
 *
 * @return string
 *   Already escaped.
 */
function myapi_reservations_admin_cancellation_label($cancelled_by, $reason) {
  if ($cancelled_by === NULL || $cancelled_by === '') {
    return '—';
  }

  $options = myapi_claims_field_allowed_values('field_cancelled_by');
  $who = isset($options[$cancelled_by]) ? $options[$cancelled_by] : $cancelled_by;

  $reason = $reason === NULL ? '' : trim($reason);
  if ($reason === '') {
    return check_plain($who);
  }

  return check_plain($who . ': ' . $reason);
}

/**
 * The link of the ID cell: node/%nid/edit, or node/%nid for a reader who
 * cannot edit. The decision is passed in already resolved.
 *
 * @param object $row
 * @param bool $can_edit
 *
 * @return string
 *   The rendered link.
 */
function myapi_reservations_admin_id_link($row, $can_edit) {
  $nid = (int) $row->nid;

  return l((string) $nid, 'node/' . $nid . ($can_edit ? '/edit' : ''));
}

/**
 * Builds the table body: the ten cells of SPEC 131, in header order.
 *
 * @param array $rows
 *   Rows fetched from myapi_reservations_admin_list_rows().
 * @param array $editable
 *   nid => bool. A nid absent from the map is NOT editable — the link that
 *   never 403s is the safe fallback.
 *
 * @return array
 *   Ready for '#theme' => 'table'.
 */
function myapi_reservations_admin_table_rows(array $rows, array $editable) {
  $table_rows = array();

  foreach ($rows as $row) {
    $nid = (int) $row->nid;

    $table_rows[] = array(
      myapi_reservations_admin_id_link($row, !empty($editable[$nid])),
      myapi_reservations_admin_date_label($row->reservation_date),
      myapi_reservations_admin_schedule_label($row->start_time, $row->end_time),
      myapi_reservations_admin_text_label($row->area_title),
      myapi_reservations_admin_text_label($row->condominium_title),
      myapi_reservations_admin_text_label($row->unit_title),
      myapi_reservations_admin_requester_label($row),
      myapi_reservations_admin_status_label($row->status),
      myapi_reservations_admin_cancellation_label($row->cancelled_by, $row->cancel_reason),
      myapi_reservations_admin_created_label($row->created),
    );
  }

  return $table_rows;
}

/* ---------------------------------------------------------------------------
 * Who may edit what.
 * ------------------------------------------------------------------------ */

/**
 * Resolves, for the rows of ONE page, whether the reader may edit each node.
 *
 * Drupal's own node_access('update') decides, over ONE node_load_multiple()
 * of the page's nids. 'bypass node access' short-circuits it: an
 * 'administrator' edits everything, and pays for no node load.
 *
 * @param array $rows
 *   Rows fetched from myapi_reservations_admin_list_rows().
 *
 * @return array
 *   nid => bool.
 */
function myapi_reservations_admin_editable_map(array $rows) {
  if (!$rows) {
    return array();
  }

  $nids = array();
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
  }

  if (user_access('bypass node access')) {
    return array_fill_keys($nids, TRUE);
  }

  $map = array();
  foreach (node_load_multiple($nids) as $nid => $node) {
    $map[(int) $nid] = (bool) node_access('update', $node);
  }

  return $map;
}

/* ---------------------------------------------------------------------------
 * Filter form.
 * ------------------------------------------------------------------------ */

/**
 * Builds the filter form of the reservation listing.
 *
 * #method = 'get' and no token, same as every back-office listing; Drupal's
 * own hidden fields are dropped by the calendar's generic after-build.
 *
 * The area select is filled from the effective condominium and is disabled
 * when there is nothing in it — no condominium chosen — exactly as the
 * calendar's. Picking a condominium and filtering again fills it.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters
 *   Validated filters, used as defaults.
 * @param array $condominium_options
 *   nid => title, already narrowed to the reader's scope.
 * @param array $area_options
 *   nid => title, of the effective condominium.
 */
function myapi_reservations_admin_filter_form($form, &$form_state, $filters, $condominium_options, $area_options) {
  $form['#method'] = 'get';
  $form['#attributes']['class'][] = 'myapi-reservations-filters';
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  $form['#action'] = url('admin/content/reservations');

  // With clean URLs off the path travels in ?q=, which a GET form would
  // otherwise drop — see myapi_reservation_calendar_filter_form().
  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type'  => 'hidden',
      '#value' => 'admin/content/reservations',
    );
  }

  $form['condominium'] = array(
    '#type'          => 'select',
    '#title'         => t('Condominio'),
    '#options'       => array('' => t('- Todos -')) + $condominium_options,
    '#default_value' => $filters['condominium'] === NULL ? '' : $filters['condominium'],
  );

  $form['area'] = array(
    '#type'          => 'select',
    '#title'         => t('Área'),
    '#options'       => array('' => t('- Todas -')) + $area_options,
    '#default_value' => $filters['area'] === NULL ? '' : $filters['area'],
    '#disabled'      => !$area_options,
  );

  $form['status'] = array(
    '#type'          => 'select',
    '#title'         => t('Estado'),
    '#options'       => array('' => t('- Todos -')) + myapi_reservations_admin_status_options(),
    '#default_value' => $filters['status'] === NULL ? '' : $filters['status'],
  );

  // Native HTML5 date inputs, written by hand: theme_textfield() forces
  // type="text".
  $form['date_from'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha de reserva desde'),
    '#markup' => '<input type="date" name="date_from" value="' . check_plain((string) $filters['date_from']) . '" />',
  );
  $form['date_to'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha de reserva hasta'),
    '#markup' => '<input type="date" name="date_to" value="' . check_plain((string) $filters['date_to']) . '" />',
  );

  $form['submit'] = array(
    '#type'  => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/* ---------------------------------------------------------------------------
 * Page.
 * ------------------------------------------------------------------------ */

/**
 * Page callback of admin/content/reservations.
 *
 * Read-only from end to end: it validates the filters, runs ONE paginated
 * query and renders a table whose ID cell links to Drupal's own node pages.
 */
function myapi_reservations_admin_page() {
  drupal_add_css(drupal_get_path('module', 'myapi') . '/css/myapi.claims.css');

  // SPEC 49: NULL for a reader who sees every condominium, the assigned list
  // (possibly empty) for an 'administrador edificio'.
  $scope = myapi_calendar_condominium_scope();
  $condominium_options = myapi_calendar_condominium_options($scope);

  $requested = isset($_GET['condominium']) ? myapi_calendar_positive_int($_GET['condominium']) : NULL;
  $condominium = myapi_calendar_effective_condominium($requested, $scope);

  // The areas of the effective condominium: one, or every one of the
  // building admin's when none of theirs was named. The rows themselves are
  // narrowed by ->addTag('node_access'), never by this list.
  $area_options = myapi_calendar_area_options($condominium);

  $filters = myapi_reservations_admin_list_filters();
  $filters['condominium'] = is_array($condominium) ? NULL : $condominium;
  $filters['area'] = myapi_reservations_admin_effective_area($filters['area'], $area_options);

  $query = myapi_reservations_admin_list_rows(
    $filters['condominium'],
    $filters['area'],
    $filters['status'],
    $filters['date_from'],
    $filters['date_to']
  );
  $rows = $query->execute()->fetchAll();

  $build = array();

  $build['filters'] = drupal_get_form(
    'myapi_reservations_admin_filter_form',
    $filters,
    $condominium_options,
    $area_options
  );

  $build['table'] = array(
    '#theme'  => 'table',
    '#header' => array(
      t('ID'),
      t('Fecha'),
      t('Horario'),
      t('Área'),
      t('Condominio'),
      t('Vivienda'),
      t('Solicitante'),
      t('Estado'),
      t('Cancelación'),
      t('Creada'),
    ),
    '#rows'   => myapi_reservations_admin_table_rows($rows, myapi_reservations_admin_editable_map($rows)),
    '#empty'  => t('No hay resultados para los filtros seleccionados.'),
  );

  $build['pager'] = array(
    '#markup' => theme('pager'),
  );

  return $build;
}
