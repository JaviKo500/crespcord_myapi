<?php

/**
 * @file
 * Reservation resource for the myapi module.
 *
 * Lists 'reservation' nodes belonging to the authenticated user, scoped to a
 * unit the user owns or occupies. Read-only, paginated, with an optional
 * date-range and status filter. Mirrors payment.resource.inc, except both
 * 'confirmed' and 'cancelled' reservations are exposed (status travels in the
 * payload instead of being excluded), and the result set is additionally
 * restricted to field_requester = the authenticated uid (SPEC 37): a unit
 * shared by multiple owners/occupants shows each of them only their own
 * reservations, not the whole unit's.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Dispatcher for /api/v1/units/%/reservations.
 *
 * Routes by HTTP method. Only GET is supported.
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_reservation_dispatch($unit_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_reservation_list($unit_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/reservations.
 *
 * Routes by HTTP method. Only POST is supported (create a reservation); any
 * other method returns 405. Authentication and the rest of the validations
 * are resolved inside myapi_reservation_create(), not here.
 */
function myapi_reservation_create_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_reservation_create();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/reservations/%/cancel.
 *
 * Routes by HTTP method. Only PUT is supported (cancel a reservation); any
 * other method returns 405. Authentication and the rest of the validations
 * are resolved inside myapi_reservation_cancel(), not here.
 *
 * @param int $reservation_id  The 'reservation' nid from the route.
 */
function myapi_reservation_cancel_dispatch($reservation_id) {
  $method = myapi_request_method();
  if ($method === 'PUT') {
    myapi_reservation_cancel($reservation_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/reservations/%/details.
 *
 * Routes by HTTP method. Only GET is supported (read one reservation); any
 * other method returns 405. Authentication and the rest of the validations
 * are resolved inside myapi_reservation_details(), not here.
 *
 * @param int $reservation_id  The 'reservation' nid from the route.
 */
function myapi_reservation_details_dispatch($reservation_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_reservation_details($reservation_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Reads the first 'value' of a single-value Field API field on a node.
 *
 * @param object $node   The node to read from.
 * @param string $field  The field machine name (e.g. 'field_area_status').
 *
 * @return mixed  The stored value, or NULL if the field has no value.
 */
function myapi_reservation_node_value($node, $field) {
  return isset($node->{$field}[LANGUAGE_NONE][0]['value'])
    ? $node->{$field}[LANGUAGE_NONE][0]['value']
    : NULL;
}

/**
 * Reads the first 'target_id' of a single-value entity-reference field.
 *
 * @param object $node   The node to read from.
 * @param string $field  The field machine name (e.g. 'field_condominio').
 *
 * @return int|NULL  The referenced entity id, or NULL if the field is empty.
 */
function myapi_reservation_node_target_id($node, $field) {
  return isset($node->{$field}[LANGUAGE_NONE][0]['target_id'])
    ? $node->{$field}[LANGUAGE_NONE][0]['target_id']
    : NULL;
}

/**
 * Reads field_date's plain 'Y-m-d', regardless of whether $node is the
 * in-memory node built by myapi_reservation_build_node() (value already
 * 'Y-m-d') or one freshly loaded from the DB (Date field, full
 * 'Y-m-d H:i:s' value) — same truncation as every SQL read of this field
 * (SUBSTR(field_date_value, 1, 10)) in this file.
 *
 * @param object $node  The reservation node to read from.
 *
 * @return string|NULL  'Y-m-d', or NULL if the field has no value.
 */
function myapi_reservation_node_date($node) {
  $value = myapi_reservation_node_value($node, 'field_date');
  return $value !== NULL ? substr($value, 0, 10) : NULL;
}

/**
 * Handles POST /api/v1/reservations.
 *
 * Creates a 'reservation' node for a unit's common area on behalf of the
 * authenticated user, applying the eight business validations in the exact
 * order documented in specs/reservations/35-create-reservation.md, each one
 * aborting the request before the node is ever touched. On success responds
 * 201 with the created reservation and a translated message.
 */
function myapi_reservation_create() {
  // 0a. Authentication: valid Bearer access token, or 401.
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  // 0b. Body: presence, then type/format, for every field.
  $body = (array) myapi_request_body();
  myapi_request_require_fields($body, ['unit_id', 'area_id', 'date', 'start_time', 'duration_minutes']);

  if (!ctype_digit((string) $body['unit_id']) || (int) $body['unit_id'] <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'unit_id']);
  }
  $unit_id = (int) $body['unit_id'];

  if (!ctype_digit((string) $body['area_id']) || (int) $body['area_id'] <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'area_id']);
  }
  $area_id = (int) $body['area_id'];

  $date = $body['date'];
  if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $date_parts)
    || !checkdate((int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1])) {
    myapi_error('invalid_field', 422, ['@field' => 'date']);
  }

  $start_time = $body['start_time'];
  if (!is_string($start_time) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $start_time)) {
    myapi_error('invalid_field', 422, ['@field' => 'start_time']);
  }

  if (!ctype_digit((string) $body['duration_minutes']) || (int) $body['duration_minutes'] <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'duration_minutes']);
  }
  $duration_minutes = (int) $body['duration_minutes'];

  // 0c. unit_id access: the authenticated user must own or occupy the unit.
  if (!in_array($unit_id, myapi_unit_related_nids($uid))) {
    myapi_error('unit_access_denied', 403);
  }

  // 0d. area_id must exist, be an 'area' node, and belong to the same
  // condominium as unit_id. Both "does not exist" and "wrong condominium"
  // collapse into the same 404, same non-filtering criterion as
  // unit_access_denied.
  $unit = node_load($unit_id);
  $unit_condominium_id = myapi_reservation_node_target_id($unit, 'field_condominio');

  $area = node_load($area_id);
  if (!$area || $area->type !== 'area' || $unit_condominium_id === NULL
    || (int) myapi_reservation_node_target_id($area, 'field_condominium') !== (int) $unit_condominium_id) {
    myapi_error('area_not_found', 404);
  }

  // 1. Role vs field_who_can_reserve: 'owner'/'tenant' must match the caller's
  // relation to the unit; any other value (including 'both' or empty) allows
  // either role.
  $who_can_reserve = myapi_reservation_node_value($area, 'field_who_can_reserve');
  if ($who_can_reserve === 'owner' && !in_array($unit_id, myapi_user_owned_unit_nids($uid))) {
    myapi_error('reservation_role_not_allowed', 403);
  }
  if ($who_can_reserve === 'tenant' && !in_array($unit_id, myapi_user_occupied_unit_nids($uid))) {
    myapi_error('reservation_role_not_allowed', 403);
  }

  // 2. field_area_status must be exactly 'active'.
  if (myapi_reservation_node_value($area, 'field_area_status') !== 'active') {
    myapi_error('area_not_active', 409);
  }

  // 3. date + start_time must not be in the past (site timezone, comparable
  // with REQUEST_TIME since Drupal sets the PHP default timezone at bootstrap).
  $start_timestamp = strtotime($date . ' ' . $start_time . ':00');
  if ($start_timestamp === FALSE || $start_timestamp < REQUEST_TIME) {
    myapi_error('invalid_field', 422, ['@field' => 'date']);
  }

  // end_time is computed once here, before validations 4 and 5 both need it.
  $end_time = myapi_reservation_add_minutes($start_time, $duration_minutes);
  $start_minutes = myapi_reservation_parse_time($start_time);
  $end_minutes = myapi_reservation_parse_time($end_time);

  // 4. Within field_open_time-field_close_time. An area with no open/close
  // row cannot satisfy the window, so it fails closed.
  $open_time = myapi_reservation_node_value($area, 'field_open_time');
  $close_time = myapi_reservation_node_value($area, 'field_close_time');
  if ($open_time === NULL || $close_time === NULL
    || $start_minutes < myapi_reservation_parse_time($open_time)
    || $end_minutes > myapi_reservation_parse_time($close_time)) {
    myapi_error('reservation_outside_hours', 422);
  }

  // 5. duration_minutes <= field_max_minutes. An area with no max_minutes row
  // has no limit to enforce.
  $max_minutes = myapi_reservation_node_value($area, 'field_max_minutes');
  if ($max_minutes !== NULL && $duration_minutes > (int) $max_minutes) {
    myapi_error('reservation_duration_exceeded', 422);
  }

  // 6-8. Overlap, active-reservation and balance checks, plus the insert
  // itself, run inside a DB transaction holding a row lock (SELECT ... FOR
  // UPDATE) on the area node. This closes the check-then-act race: two
  // concurrent requests for the same area serialize on that lock, so the
  // second one re-runs its checks only after the first has already
  // committed its node_save(), instead of both reading the same stale
  // "no overlap yet" snapshot and both inserting. Requires the area's
  // underlying tables to use the InnoDB engine (Drupal 7's default).
  $transaction = db_transaction();

  db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.nid', $area_id)
    ->forUpdate()
    ->execute()
    ->fetchField();

  // 6. Overlap with another 'confirmed' reservation of the same area/date.
  if (myapi_reservation_has_overlap($area_id, $date, $start_minutes, $end_minutes)) {
    $transaction->rollback();
    myapi_error('reservation_overlap', 409);
  }

  // 7. Only one active 'confirmed' reservation per unit+area (start not yet
  // passed).
  if (myapi_reservation_has_active_reservation($unit_id, $area_id)) {
    $transaction->rollback();
    myapi_error('reservation_already_active', 409);
  }

  // 8. Balance: current debt blocks new reservations unless the most recent
  // sent receipt shows no previous balance due.
  if (!myapi_reservation_check_balance($unit_id)) {
    $transaction->rollback();
    myapi_error('insufficient_balance', 403);
  }

  $node = myapi_reservation_build_node($unit_condominium_id, $unit_id, $uid, $area_id, $date, $start_time, $end_time);
  node_save($node);
  // Explicit unset commits now (Drupal 7 transactions commit on destruct)
  // and releases the area lock before the response is built below.
  unset($transaction);

  myapi_respond(['reservation' => myapi_reservation_build_item_from_node($node, $area->title, myapi_reservation_node_value($area, 'field_area_category'), myapi_reservation_node_value($area, 'field_cancel_deadline_minutes'))], 201, 'reservation_created');
}

/**
 * Handles PUT /api/v1/reservations/%/cancel.
 *
 * Cancels a 'confirmed' reservation on behalf of the authenticated user, but
 * only when that user is exactly the reservation's field_requester and the
 * cancellation window (field_cancel_deadline_minutes of the area) has not
 * closed yet. Soft-cancel only: field_reservation_status is rewritten to
 * 'cancelled' and field_cancelled_by to 'user', every other field is left
 * untouched. Every check aborts with its own error before the node is ever
 * modified.
 *
 * See the ordered plan in specs/reservations/36-cancel-reservation.md, step 3.
 *
 * @param mixed $reservation_id  The 'reservation' nid from the route.
 */
function myapi_reservation_cancel($reservation_id) {
  // 1. Authentication: valid Bearer access token, or 401.
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  // 2. reservation_id must be a positive integer and reference an existing
  // 'reservation' node; both "invalid id" and "not found" map to the same 404.
  if (!ctype_digit((string) $reservation_id) || (int) $reservation_id <= 0) {
    myapi_error('reservation_not_found', 404);
  }
  $node = node_load((int) $reservation_id);
  if (!$node || $node->type !== 'reservation') {
    myapi_error('reservation_not_found', 404);
  }

  // 3. Authorship: only the exact field_requester of the reservation may
  // cancel it, unlike payment.resource.inc's unit-wide access.
  $requester_uid = myapi_reservation_node_target_id($node, 'field_requester');
  if ((int) $requester_uid !== (int) $uid) {
    myapi_error('reservation_forbidden', 403);
  }

  // 4. Status: only a 'confirmed' reservation may be cancelled; any other
  // status (including an already-cancelled reservation) is a 409, with no
  // modification to the node.
  $status = myapi_reservation_node_value($node, 'field_reservation_status');
  if ($status !== 'confirmed') {
    myapi_error('reservation_not_confirmed', 409);
  }

  // 5. Cancellation window: minutes between now and the reservation's start
  // must exceed the area's field_cancel_deadline_minutes. A deleted area, or
  // one with no deadline row, fails closed as an expired window (documented
  // edge case in the spec's risk table) since the window cannot be confirmed.
  $area_id = myapi_reservation_node_target_id($node, 'field_area');
  $area = $area_id ? node_load($area_id) : NULL;
  $deadline_minutes = $area ? myapi_reservation_node_value($area, 'field_cancel_deadline_minutes') : NULL;
  if ($area === NULL || $deadline_minutes === NULL) {
    myapi_error('reservation_cancel_window_expired', 409);
  }

  $date = myapi_reservation_node_date($node);
  $start_time = myapi_reservation_node_value($node, 'field_start_time');
  $start_timestamp = strtotime($date . ' ' . $start_time . ':00');
  $minutes_until_start = floor(($start_timestamp - REQUEST_TIME) / 60);
  if ($minutes_until_start <= (int) $deadline_minutes) {
    myapi_error('reservation_cancel_window_expired', 409);
  }

  // 6. Apply the cancellation.
  $node->field_reservation_status[LANGUAGE_NONE][0]['value'] = 'cancelled';
  $node->field_cancelled_by[LANGUAGE_NONE][0]['value'] = 'user';
  node_save($node);

  // 7. Respond 200 with the updated reservation.
  myapi_respond(['reservation' => myapi_reservation_build_item_from_node($node, $area->title, myapi_reservation_node_value($area, 'field_area_category'), $deadline_minutes)], 200, 'reservation_cancelled');
}

/**
 * Handles GET /api/v1/reservations/%/details.
 *
 * Returns the single 'reservation' node identified by $reservation_id, in the
 * same 14-key item shape as the list (GET /api/v1/units/%/reservations),
 * wrapped as {"reservation": ...}. The reservation is only visible when it
 * would also appear in the caller's own list: it must exist, be a published
 * 'reservation' node, belong to a unit the caller owns or occupies, and have
 * field_requester = the authenticated uid (same two filters as SPEC 34 + 37).
 *
 * Every "not visible to you" case — invalid id, missing/unpublished node,
 * a unit the caller is not related to, or another resident's reservation —
 * collapses into the same 404 reservation_not_found, so the endpoint never
 * reveals whether a reservation id exists or whom it belongs to, mirroring the
 * list's non-revealing access rule (where "no access" and "does not exist"
 * are indistinguishable).
 *
 * @param mixed $reservation_id  The 'reservation' nid from the route.
 */
function myapi_reservation_details($reservation_id) {
  // 1. Authentication: valid Bearer access token, or 401.
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  // 2. reservation_id must be a positive integer referencing a published
  // 'reservation' node; anything else is a non-revealing 404.
  if (!ctype_digit((string) $reservation_id) || (int) $reservation_id <= 0) {
    myapi_error('reservation_not_found', 404);
  }
  $node = node_load((int) $reservation_id);
  if (!$node || $node->type !== 'reservation' || (int) $node->status !== 1) {
    myapi_error('reservation_not_found', 404);
  }

  // 3. Requester filter (SPEC 37): only the caller's own reservations. A
  // reservation created by another resident collapses into the same 404.
  if ((int) myapi_reservation_node_target_id($node, 'field_requester') !== (int) $uid) {
    myapi_error('reservation_not_found', 404);
  }

  // 4. Unit access (same rule as the list, SPEC 34): the caller must own or
  // occupy the reservation's unit. Also collapses into the same 404.
  $unit_id = myapi_reservation_node_target_id($node, 'field_unit');
  if ($unit_id === NULL || !in_array((int) $unit_id, myapi_unit_related_nids($uid))) {
    myapi_error('reservation_not_found', 404);
  }

  // 5. Resolve the area-derived values from the referenced 'area' node. A
  // deleted/missing area leaves them NULL, exactly like the list's left join.
  $area_id = myapi_reservation_node_target_id($node, 'field_area');
  $area = $area_id ? node_load($area_id) : NULL;
  $area_title = $area ? $area->title : NULL;
  $area_category = $area ? myapi_reservation_node_value($area, 'field_area_category') : NULL;
  $cancel_deadline_minutes = $area ? myapi_reservation_node_value($area, 'field_cancel_deadline_minutes') : NULL;

  myapi_respond(['reservation' => myapi_reservation_build_item_from_node($node, $area_title, $area_category, $cancel_deadline_minutes)], 200);
}

/**
 * Checks whether a candidate interval overlaps a 'confirmed' reservation of
 * the same area on the same date.
 *
 * Half-open interval criterion: new_start < existing_end AND
 * new_end > existing_start, so a reservation that ends exactly when another
 * begins does not count as an overlap. Rows for the day/area are fetched
 * (expected to be few per area per day) and compared in PHP.
 *
 * @param int    $area_id       The 'area' nid.
 * @param string $date          'YYYY-MM-DD' of the candidate reservation.
 * @param int    $start_minutes Candidate start, minutes since midnight.
 * @param int    $end_minutes   Candidate end, minutes since midnight.
 *
 * @return bool  TRUE when the candidate interval overlaps an existing one.
 */
function myapi_reservation_has_overlap($area_id, $date, $start_minutes, $end_minutes) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_area', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->condition('fa.field_area_target_id', $area_id);

  $query->innerJoin('field_data_field_reservation_status', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->condition('fst.field_reservation_status_value', 'confirmed');

  $query->innerJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->where('SUBSTR(fd.field_date_value, 1, 10) = :date', [':date' => $date]);

  $query->innerJoin('field_data_field_start_time', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->addField('fs', 'field_start_time_value', 'start_time');

  $query->innerJoin('field_data_field_end_time', 'fe', "fe.entity_id = n.nid AND fe.entity_type = 'node' AND fe.deleted = 0");
  $query->addField('fe', 'field_end_time_value', 'end_time');

  $rows = $query->execute()->fetchAll();
  foreach ($rows as $existing) {
    $existing_start = myapi_reservation_parse_time($existing->start_time);
    $existing_end = myapi_reservation_parse_time($existing->end_time);
    if ($start_minutes < $existing_end && $end_minutes > $existing_start) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 * Checks whether a unit already has a 'confirmed' reservation for the same
 * area whose start date/time has not passed yet.
 *
 * @param int $unit_id  The 'vivienda' nid.
 * @param int $area_id  The 'area' nid.
 *
 * @return bool  TRUE when such a reservation exists.
 */
function myapi_reservation_has_active_reservation($unit_id, $area_id) {
  $today = date('Y-m-d', REQUEST_TIME);
  $now = date('H:i', REQUEST_TIME);

  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->condition('fu.field_unit_target_id', $unit_id);

  $query->innerJoin('field_data_field_area', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->condition('fa.field_area_target_id', $area_id);

  $query->innerJoin('field_data_field_reservation_status', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->condition('fst.field_reservation_status_value', 'confirmed');

  $query->innerJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->innerJoin('field_data_field_start_time', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->where(
    '(SUBSTR(fd.field_date_value, 1, 10) > :today) OR (SUBSTR(fd.field_date_value, 1, 10) = :today AND fs.field_start_time_value > :now)',
    [':today' => $today, ':now' => $now]
  );

  return (bool) $query->countQuery()->execute()->fetchField();
}

/**
 * Checks whether a unit is allowed to reserve given its current balance.
 *
 * If field_saldo_actual is <= 0 (or the unit has no row), the unit may
 * reserve without looking at any receipt. Otherwise, the most recently
 * issued ('Enviado') receipt for the unit (ordered by field_periodo DESC,
 * same "most recent" criterion as the receipts list) decides: a positive
 * field_saldo_anterior blocks the reservation; anything else (<=0, missing
 * row, or no 'Enviado' receipt at all) allows it.
 *
 * @param int $unit_id  The 'vivienda' nid.
 *
 * @return bool  TRUE when the unit is allowed to reserve.
 */
function myapi_reservation_check_balance($unit_id) {
  $unit = node_load($unit_id);
  $current_balance = myapi_reservation_node_value($unit, 'field_saldo_actual');
  if ($current_balance === NULL || (float) $current_balance <= 0) {
    return TRUE;
  }

  $query = db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.type', 'recibo')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $unit_id);

  $query->innerJoin('field_data_field_estado', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_value', 'Enviado');

  $query->leftJoin('field_data_field_periodo', 'fper', "fper.entity_id = n.nid AND fper.entity_type = 'node' AND fper.deleted = 0");
  $query->addField('fper', 'field_periodo_value', 'period_start');

  $query->leftJoin('field_data_field_saldo_anterior', 'fsant', "fsant.entity_id = n.nid AND fsant.entity_type = 'node' AND fsant.deleted = 0");
  $query->addField('fsant', 'field_saldo_anterior_value', 'previous_balance');

  $query->orderBy('fper.field_periodo_value', 'DESC');
  $query->range(0, 1);

  $receipt = $query->execute()->fetchObject();
  if (!$receipt) {
    return TRUE;
  }

  return !($receipt->previous_balance !== NULL && (float) $receipt->previous_balance > 0);
}

/**
 * Builds the in-memory 'reservation' node object, ready for node_save().
 *
 * field_condominium comes from the unit (vivienda), not from the area or the
 * request body, per the spec's data model. field_cancelled_by is left unset.
 *
 * @param int    $condominium_id  The unit's 'condominio' nid.
 * @param int    $unit_id         The 'vivienda' nid.
 * @param int    $uid             The authenticated requester's uid.
 * @param int    $area_id         The 'area' nid.
 * @param string $date            'YYYY-MM-DD'.
 * @param string $start_time      'HH:MM'.
 * @param string $end_time        'HH:MM', server-computed.
 *
 * @return object  Unsaved node, ready for node_save().
 */
function myapi_reservation_build_node($condominium_id, $unit_id, $uid, $area_id, $date, $start_time, $end_time) {
  $node = new stdClass();
  $node->type = 'reservation';
  node_object_prepare($node);
  $node->uid = $uid;
  $node->status = 1;
  $node->language = LANGUAGE_NONE;
  $node->title = 'Reservation ' . $unit_id . ' - ' . $date . ' ' . $start_time;

  $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = $condominium_id;
  $node->field_unit[LANGUAGE_NONE][0]['target_id'] = $unit_id;
  $node->field_requester[LANGUAGE_NONE][0]['target_id'] = $uid;
  $node->field_area[LANGUAGE_NONE][0]['target_id'] = $area_id;
  $node->field_date[LANGUAGE_NONE][0]['value'] = $date;
  $node->field_start_time[LANGUAGE_NONE][0]['value'] = $start_time;
  $node->field_end_time[LANGUAGE_NONE][0]['value'] = $end_time;
  $node->field_reservation_status[LANGUAGE_NONE][0]['value'] = 'confirmed';

  return $node;
}

/**
 * Maps a loaded/in-memory 'reservation' node into the single-item response
 * shape, wrapped by the caller as {"reservation": ...}.
 *
 * Same 14-key shape as myapi_reservation_build_item()
 * (GET /api/v1/units/%/reservations, SPEC 34), but reads straight from a node
 * object instead of a DB fetch row. Used by three by-node responses: create
 * (201), cancel (200) and detail (GET /api/v1/reservations/%/details, 200).
 * The area-derived values ('area_name', 'area_category',
 * 'cancel_deadline_minutes') are passed in by the caller, since the node
 * itself only stores the area's nid; each is NULL when the caller could not
 * resolve the area (e.g. it was deleted).
 *
 * @param object      $node                     The reservation node.
 * @param string      $area_title               The reserved area's title.
 * @param string|NULL $area_category            The reserved area's category.
 * @param int|NULL    $cancel_deadline_minutes  The area's cancellation window.
 *
 * @return array  Response item with all 14 mapped keys.
 */
function myapi_reservation_build_item_from_node($node, $area_title, $area_category = NULL, $cancel_deadline_minutes = NULL) {
  return [
    'id'             => (int) $node->nid,
    'condominium_id' => (int) myapi_reservation_node_target_id($node, 'field_condominium'),
    'unit_id'        => (int) myapi_reservation_node_target_id($node, 'field_unit'),
    'requester_id'   => (int) myapi_reservation_node_target_id($node, 'field_requester'),
    'area_id'        => (int) myapi_reservation_node_target_id($node, 'field_area'),
    'area_name'      => $area_title,
    'area_category'  => $area_category,
    'cancel_deadline_minutes' => $cancel_deadline_minutes !== NULL ? (int) $cancel_deadline_minutes : NULL,
    'date'           => myapi_reservation_node_date($node),
    'start_time'     => myapi_reservation_node_value($node, 'field_start_time'),
    'end_time'       => myapi_reservation_node_value($node, 'field_end_time'),
    'status'         => myapi_reservation_node_value($node, 'field_reservation_status'),
    'cancelled_by'   => myapi_reservation_node_value($node, 'field_cancelled_by'),
    'created'        => format_date($node->created, 'custom', 'Y-m-d\TH:i:s'),
  ];
}

/**
 * Handles GET /api/v1/units/%/reservations.
 *
 * Returns a paginated list of 'reservation' nodes belonging to $unit_id AND
 * created by the authenticated user (field_requester = uid), provided the
 * user is the owner or occupant of that unit (same access rule as
 * GET /api/v1/units). Both 'confirmed' and 'cancelled' reservations are
 * returned; the status travels in the payload. A unit shared by multiple
 * owners/occupants never shows one user another's reservations (SPEC 37).
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_reservation_list($unit_id) {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  $nids = myapi_unit_related_nids($uid);
  if (!in_array((int) $unit_id, $nids)) {
    myapi_error('unit_access_denied', 403);
  }

  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && $_GET['limit'] === '-1'
    ? -1
    : (isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
      ? max(1, min(50, (int) $_GET['limit']))
      : 20);

  if ($limit === -1) {
    $page = 1;
  }

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  $range = myapi_reservation_parse_date_range();
  $from = $range['from'];
  $to = $range['to'];

  $status = isset($_GET['status']) && in_array($_GET['status'], ['confirmed', 'cancelled'], TRUE)
    ? $_GET['status']
    : NULL;

  $total = myapi_reservation_count($unit_id, $from, $to, $status, $uid);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from, $to, $status, $uid);
  $reservations = array_map('myapi_reservation_build_item', $rows);

  myapi_respond([
    'reservations' => $reservations,
    'pagination' => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * Parses the optional date-range filter from the query string.
 *
 * Reads $_GET['date_from'] and $_GET['date_to'], each expected as an ISO
 * 'YYYY-MM-DD' string. A bound is kept only when it matches that format and
 * is a real calendar date (validated with checkdate()); anything else is
 * silently ignored, exactly like page/limit/sort. If both bounds are present
 * but inverted (from > to), the whole filter is dropped so the endpoint
 * responds as if no range were given. No 422 is ever raised here.
 *
 * @return array  ['from' => string|NULL, 'to' => string|NULL].
 */
function myapi_reservation_parse_date_range() {
  $from = myapi_reservation_valid_date(isset($_GET['date_from']) ? $_GET['date_from'] : NULL);
  $to = myapi_reservation_valid_date(isset($_GET['date_to']) ? $_GET['date_to'] : NULL);

  if ($from !== NULL && $to !== NULL && $from > $to) {
    return ['from' => NULL, 'to' => NULL];
  }

  return ['from' => $from, 'to' => $to];
}

/**
 * Validates a single 'YYYY-MM-DD' date string.
 *
 * @param mixed $value  Raw query-string value, or NULL when absent.
 *
 * @return string|NULL  The value when it is a real ISO calendar date,
 *                       NULL otherwise.
 */
function myapi_reservation_valid_date($value) {
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
    return NULL;
  }
  if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
    return NULL;
  }
  return $value;
}

/**
 * Converts an 'HH:MM' time string into minutes since midnight.
 *
 * Assumes $hhmm already matches the 'HH:MM' 24h format (validated at the
 * request-body stage before this is ever called).
 *
 * @param string $hhmm  Time in 'HH:MM' format.
 *
 * @return int  Minutes since midnight (0-1439).
 */
function myapi_reservation_parse_time($hhmm) {
  list($hours, $minutes) = explode(':', $hhmm);
  return ((int) $hours) * 60 + (int) $minutes;
}

/**
 * Adds a number of minutes to an 'HH:MM' time string.
 *
 * Deliberately does not wrap around at 24:00: a result of 24:00 or later is
 * returned as-is (e.g. '25:30'), so it naturally fails the open/close-time
 * comparison later, since no area's field_close_time is ever past midnight.
 *
 * @param string $hhmm     Start time in 'HH:MM' format.
 * @param int    $minutes  Minutes to add (duration_minutes from the body).
 *
 * @return string  Resulting time in 'HH:MM' format, hours unbounded.
 */
function myapi_reservation_add_minutes($hhmm, $minutes) {
  $total = myapi_reservation_parse_time($hhmm) + (int) $minutes;
  $hours = intdiv($total, 60);
  $mins = $total % 60;
  return sprintf('%02d:%02d', $hours, $mins);
}

/**
 * Counts published 'reservation' nodes for a unit, unpaginated.
 *
 * When a date bound is given, joins field_data_field_date and restricts by
 * field_date_value so the count matches the same filtered set as the
 * fetched page; with an active bound, reservations with no date row are
 * excluded. When a status filter is given, joins
 * field_data_field_reservation_status and restricts to that value.
 * Always restricted to field_requester = $uid (SPEC 37), so the count
 * reflects only the authenticated user's own reservations, not the whole
 * unit's.
 *
 * @param int         $unit_id  The 'vivienda' nid to filter by.
 * @param string|NULL $from     Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to       Inclusive upper bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $status   'confirmed', 'cancelled', or NULL for both.
 * @param int         $uid      The authenticated user's uid.
 *
 * @return int  Total number of matching reservations.
 */
function myapi_reservation_count($unit_id, $from = NULL, $to = NULL, $status = NULL, $uid = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->condition('fu.field_unit_target_id', $unit_id);

  $query->innerJoin('field_data_field_requester', 'freq', "freq.entity_id = n.nid AND freq.entity_type = 'node' AND freq.deleted = 0");
  $query->condition('freq.field_requester_target_id', $uid);

  if ($from !== NULL || $to !== NULL) {
    $query->innerJoin('field_data_field_date', 'fdate', "fdate.entity_id = n.nid AND fdate.entity_type = 'node' AND fdate.deleted = 0");
    if ($from !== NULL) {
      $query->where('SUBSTR(fdate.field_date_value, 1, 10) >= :date_from', [':date_from' => $from]);
    }
    if ($to !== NULL) {
      $query->where('SUBSTR(fdate.field_date_value, 1, 10) <= :date_to', [':date_to' => $to]);
    }
  }

  if ($status !== NULL) {
    $query->innerJoin('field_data_field_reservation_status', 'fstat', "fstat.entity_id = n.nid AND fstat.entity_type = 'node' AND fstat.deleted = 0");
    $query->condition('fstat.field_reservation_status_value', $status);
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Fetches a page of 'reservation' nodes for a unit, with every mapped field.
 *
 * @param int         $unit_id  The 'vivienda' nid to filter by.
 * @param int         $page     1-based page number.
 * @param int         $limit    Rows per page, already clamped to [1, 50], or
 *                              -1 to return every row unpaginated.
 * @param string      $sort     'asc' or 'desc', applied to field_date_value.
 * @param string|NULL $from     Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to       Inclusive upper bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $status   'confirmed', 'cancelled', or NULL for both.
 * @param int         $uid      The authenticated user's uid. Rows are always
 *                              restricted to field_requester = $uid (SPEC 37).
 *
 * @return array  Rows keyed numerically, one per reservation.
 */
function myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from = NULL, $to = NULL, $status = NULL, $uid = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'created'])
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->condition('fu.field_unit_target_id', $unit_id);
  $query->addField('fu', 'field_unit_target_id', 'unit_id');

  $query->leftJoin('field_data_field_condominium', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->addField('fcond', 'field_condominium_target_id', 'condominium_id');

  $query->leftJoin('field_data_field_requester', 'freq', "freq.entity_id = n.nid AND freq.entity_type = 'node' AND freq.deleted = 0");
  $query->addField('freq', 'field_requester_target_id', 'requester_id');
  // Mandatory filter (SPEC 37): only the authenticated user's own
  // reservations, never another owner/occupant's of the same unit.
  $query->condition('freq.field_requester_target_id', $uid);

  $query->leftJoin('field_data_field_area', 'farea', "farea.entity_id = n.nid AND farea.entity_type = 'node' AND farea.deleted = 0");
  $query->addField('farea', 'field_area_target_id', 'area_id');
  $query->leftJoin('node', 'na', 'na.nid = farea.field_area_target_id');
  $query->addField('na', 'title', 'area_name');
  // Area category lives on the referenced 'area' node, so it joins on the
  // area nid (farea.field_area_target_id), not on the reservation node. NULL
  // when the area has no category row or the reservation has no area.
  $query->leftJoin('field_data_field_area_category', 'fcat', "fcat.entity_id = farea.field_area_target_id AND fcat.entity_type = 'node' AND fcat.deleted = 0");
  $query->addField('fcat', 'field_area_category_value', 'area_category');
  // Cancellation window, also read from the referenced 'area' node (joined on
  // the area nid). A UX hint so the client can decide whether to offer the
  // cancel action; the cancel endpoint still re-validates the window. NULL
  // when the area has no deadline row or the reservation has no area.
  $query->leftJoin('field_data_field_cancel_deadline_minutes', 'fcdl', "fcdl.entity_id = farea.field_area_target_id AND fcdl.entity_type = 'node' AND fcdl.deleted = 0");
  $query->addField('fcdl', 'field_cancel_deadline_minutes_value', 'cancel_deadline_minutes');

  $query->leftJoin('field_data_field_date', 'fdate', "fdate.entity_id = n.nid AND fdate.entity_type = 'node' AND fdate.deleted = 0");
  $query->addField('fdate', 'field_date_value', 'date');

  // Optional date-range filter on field_date. Applying a condition on the
  // left-joined column also excludes reservations with no date row (NULL),
  // which is the desired behaviour: a reservation without a date cannot fall
  // in a range.
  if ($from !== NULL) {
    $query->where('SUBSTR(fdate.field_date_value, 1, 10) >= :date_from', [':date_from' => $from]);
  }
  if ($to !== NULL) {
    $query->where('SUBSTR(fdate.field_date_value, 1, 10) <= :date_to', [':date_to' => $to]);
  }

  $query->leftJoin('field_data_field_start_time', 'fstart', "fstart.entity_id = n.nid AND fstart.entity_type = 'node' AND fstart.deleted = 0");
  $query->addField('fstart', 'field_start_time_value', 'start_time');

  $query->leftJoin('field_data_field_end_time', 'fend', "fend.entity_id = n.nid AND fend.entity_type = 'node' AND fend.deleted = 0");
  $query->addField('fend', 'field_end_time_value', 'end_time');

  $query->leftJoin('field_data_field_reservation_status', 'fstat', "fstat.entity_id = n.nid AND fstat.entity_type = 'node' AND fstat.deleted = 0");
  $query->addField('fstat', 'field_reservation_status_value', 'status');

  // Optional status filter, same idiom as the date range: the condition on
  // the left-joined column excludes reservations with no status row or a
  // different status.
  if ($status !== NULL) {
    $query->where('fstat.field_reservation_status_value = :status', [':status' => $status]);
  }

  $query->leftJoin('field_data_field_cancelled_by', 'fcancel', "fcancel.entity_id = n.nid AND fcancel.entity_type = 'node' AND fcancel.deleted = 0");
  $query->addField('fcancel', 'field_cancelled_by_value', 'cancelled_by');

  $query->orderBy('fdate.field_date_value', $sort === 'asc' ? 'ASC' : 'DESC');
  // Deterministic tie-breaker: reservations sharing the same date would
  // otherwise come back in an unstable order across requests (and could
  // shift between pages), because SQL does not guarantee row order for
  // ties. nid is unique, never NULL and immutable, so it makes the ordering
  // reproducible.
  $query->orderBy('n.nid', $sort === 'asc' ? 'ASC' : 'DESC');
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }

  return $query->execute()->fetchAll();
}

/**
 * Maps one raw reservation row into the response shape documented for
 * GET /api/v1/units/%/reservations.
 *
 * 'id' and every '*_id' key are cast to int, each only when not NULL.
 * 'date' is truncated to its 'Y-m-d' portion, NULL when the reservation has
 * no date row. 'created' is the node's native creation timestamp, formatted
 * as an ISO 8601 string in the site's timezone. Text fields (area_name,
 * start_time, end_time, status, cancelled_by) pass through as stored, with
 * no business interpretation, and stay NULL when the node has no row for
 * that field. 'area_category' is the referenced area's category, NULL when
 * the area has no category row or the reservation has no area.
 * 'cancel_deadline_minutes' is the referenced area's cancellation window, cast
 * to int, NULL under the same conditions; it is a client-side UX hint (the
 * cancel endpoint re-validates the window), not authoritative data.
 *
 * @param object $reservation  One row from myapi_reservation_fetch().
 *
 * @return array  Response item with all 14 mapped keys.
 */
function myapi_reservation_build_item($reservation) {
  return [
    'id'             => (int) $reservation->nid,
    'condominium_id' => $reservation->condominium_id !== NULL ? (int) $reservation->condominium_id : NULL,
    'unit_id'        => $reservation->unit_id !== NULL ? (int) $reservation->unit_id : NULL,
    'requester_id'   => $reservation->requester_id !== NULL ? (int) $reservation->requester_id : NULL,
    'area_id'        => $reservation->area_id !== NULL ? (int) $reservation->area_id : NULL,
    'area_name'      => $reservation->area_name,
    'area_category'  => $reservation->area_category,
    'cancel_deadline_minutes' => $reservation->cancel_deadline_minutes !== NULL ? (int) $reservation->cancel_deadline_minutes : NULL,
    'date'           => $reservation->date !== NULL ? substr($reservation->date, 0, 10) : NULL,
    'start_time'     => $reservation->start_time,
    'end_time'       => $reservation->end_time,
    'status'         => $reservation->status,
    'cancelled_by'   => $reservation->cancelled_by,
    'created'        => format_date($reservation->created, 'custom', 'Y-m-d\TH:i:s'),
  ];
}
