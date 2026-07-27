<?php

/**
 * @file
 * Reservation resource for the myapi module.
 *
 * Lists 'reservation' nodes belonging to the authenticated user, scoped to a
 * unit the user owns or occupies. Read-only, paginated, with an optional
 * date-range filter (each bound refinable to an hour, SPEC 43) and status
 * filter. Mirrors payment.resource.inc, except both
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

  // SPEC 41: normalize field_date to the start's clock day, before any
  // time-based validation (3-8) or the node build reads it. In an area that
  // closes after midnight, a start in the early-morning tail [00:00, close)
  // belongs to the next calendar day, so the effective date becomes D+1; the
  // client still sends date = D. Non-wrapping areas, the dead gap between
  // close and open, and an area missing its open/close row all keep D
  // untouched (a missing window still fails closed at validation 4). The
  // parsed open/close/start minutes computed here are reused by validations 4
  // and 6 below.
  $open_time = myapi_reservation_node_value($area, 'field_open_time');
  $close_time = myapi_reservation_node_value($area, 'field_close_time');
  $open_minutes = $open_time !== NULL ? myapi_reservation_parse_time($open_time) : NULL;
  $close_minutes = $close_time !== NULL ? myapi_reservation_parse_time($close_time) : NULL;
  $start_minutes = myapi_reservation_parse_time($start_time);
  if ($open_minutes !== NULL && $close_minutes !== NULL) {
    $date = myapi_reservation_effective_date($date, $start_minutes, $open_minutes, $close_minutes);
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
  // Operates on the SPEC 41 normalized $date, so an early-morning slot moved to
  // D+1 (e.g. 01:00 in a 12:00-02:00 area, booked the evening of D) is no
  // longer wrongly rejected as being in the past.
  $start_timestamp = strtotime($date . ' ' . $start_time . ':00');
  if ($start_timestamp === FALSE || $start_timestamp < REQUEST_TIME) {
    myapi_error('invalid_field', 422, ['@field' => 'date']);
  }

  // end_time is computed once here, before validations 4 and 5 both need it.
  // add_minutes() does not wrap at 24:00, so a range crossing midnight surfaces
  // as end_minutes >= 1440 (e.g. '25:00' -> 1500), which the checks below rely
  // on. $start_minutes was parsed at the normalization step above.
  $end_time = myapi_reservation_add_minutes($start_time, $duration_minutes);
  $end_minutes = myapi_reservation_parse_time($end_time);
  // Value persisted on the node: the wrapped clock time (mod 24h). A crossing
  // range is stored as '02:00', not '26:00', so the response, the reservation
  // list and SPEC 40 availability all read a real time-of-day and derive the
  // crossing by comparison. Validations 4-6 keep using the unbounded
  // $start_minutes/$end_minutes above.
  $end_time_stored = myapi_reservation_wrap_time($end_minutes);

  // 4. Opening hours (SPEC 41). First the midnight-crossing cut: a range that
  // crosses midnight in an area that does NOT wrap past midnight gets its own
  // error, so the client can tell it apart from a plain out-of-hours request.
  // Then the window itself — an area missing its open/close row fails closed,
  // and a wrapping area is checked against the extended window [open, close+24h]
  // inside myapi_reservation_within_hours(). $open_minutes/$close_minutes were
  // parsed at the normalization step above.
  if (myapi_reservation_crosses_disallowed($start_minutes, $end_minutes, $open_minutes, $close_minutes)) {
    myapi_error('reservation_crosses_midnight', 422);
  }
  if ($open_minutes === NULL || $close_minutes === NULL
    || !myapi_reservation_within_hours($start_minutes, $end_minutes, $open_minutes, $close_minutes)) {
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

  // 6. Overlap with another 'confirmed' reservation of the same area, compared
  // on an absolute axis across D-1/D/D+1 so midnight-crossing tails are caught
  // (SPEC 41). $date is the normalized clock day.
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

  $node = myapi_reservation_build_node($unit_condominium_id, $unit_id, $uid, $area_id, $date, $start_time, $end_time_stored);
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
 * the same area, comparing on an absolute minute axis (SPEC 41).
 *
 * Each reservation — the candidate and every existing one — is projected onto
 * an absolute axis anchored at the midnight of its own field_date
 * (myapi_reservation_abs_interval), so same-day, midnight-crossing and
 * early-morning reservations all compare uniformly. To catch the tails that a
 * single-date query would miss, existing reservations are fetched for three
 * days: field_date IN (D-1, D, D+1). D-1 covers a previous-day reservation
 * whose tail runs past midnight into $date; D+1 covers an early-morning
 * reservation of the next day that a crossing candidate could hit. The
 * per-area/day volume stays low, so interval math is done in PHP.
 *
 * Half-open interval criterion (unchanged from SPEC 35): new_start_abs <
 * existing_end_abs AND new_end_abs > existing_start_abs, so back-to-back
 * reservations (one ends exactly when another begins) do not overlap.
 *
 * @param int    $area_id       The 'area' nid.
 * @param string $date          Normalized 'YYYY-MM-DD' of the candidate (D).
 * @param int    $start_minutes Candidate start, minutes since midnight.
 * @param int    $end_minutes   Candidate end, minutes since midnight (unbounded
 *                              when the candidate crosses midnight).
 *
 * @return bool  TRUE when the candidate interval overlaps an existing one.
 */
function myapi_reservation_has_overlap($area_id, $date, $start_minutes, $end_minutes) {
  $prev = date('Y-m-d', strtotime($date . ' -1 day'));
  $next = date('Y-m-d', strtotime($date . ' +1 day'));

  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_area', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->condition('fa.field_area_target_id', $area_id);

  $query->innerJoin('field_data_field_reservation_status', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->condition('fst.field_reservation_status_value', 'confirmed');

  $query->innerJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->where('SUBSTR(fd.field_date_value, 1, 10) IN (:dates)', [':dates' => [$prev, $date, $next]]);
  $query->addField('fd', 'field_date_value', 'date');

  $query->innerJoin('field_data_field_start_time', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->addField('fs', 'field_start_time_value', 'start_time');

  $query->innerJoin('field_data_field_end_time', 'fe', "fe.entity_id = n.nid AND fe.entity_type = 'node' AND fe.deleted = 0");
  $query->addField('fe', 'field_end_time_value', 'end_time');

  list($new_start_abs, $new_end_abs) = myapi_reservation_abs_interval($date, $start_minutes, $end_minutes);

  $rows = $query->execute()->fetchAll();
  foreach ($rows as $existing) {
    $existing_date = substr($existing->date, 0, 10);
    $existing_start = myapi_reservation_parse_time($existing->start_time);
    $existing_end = myapi_reservation_parse_time($existing->end_time);
    list($existing_start_abs, $existing_end_abs) = myapi_reservation_abs_interval($existing_date, $existing_start, $existing_end);
    if ($new_start_abs < $existing_end_abs && $new_end_abs > $existing_start_abs) {
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
  $time_from = $range['time_from'];
  $time_to = $range['time_to'];

  $status = isset($_GET['status']) && in_array($_GET['status'], ['confirmed', 'cancelled'], TRUE)
    ? $_GET['status']
    : NULL;

  $total = myapi_reservation_count($unit_id, $from, $to, $time_from, $time_to, $status, $uid);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from, $to, $time_from, $time_to, $status, $uid);
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
 * Each date bound accepts an optional time refinement, $_GET['time_from'] /
 * $_GET['time_to'] in 'HH:MM' 24h format (SPEC 43). A time bound is not a
 * filter of its own: it narrows its date bound on the boundary day only, so
 * 'date_from=D&time_from=09:00' means "from D at 09:00 onwards" and leaves
 * every later day untouched. It is therefore dropped whenever its own date
 * bound is absent or invalid, and both are dropped when the range collapses
 * to a single inverted day (from == to and time_from > time_to), mirroring
 * the inverted-date-range rule.
 *
 * @return array  ['from' => string|NULL, 'to' => string|NULL,
 *                 'time_from' => string|NULL, 'time_to' => string|NULL].
 */
function myapi_reservation_parse_date_range() {
  $from = myapi_reservation_valid_date(isset($_GET['date_from']) ? $_GET['date_from'] : NULL);
  $to = myapi_reservation_valid_date(isset($_GET['date_to']) ? $_GET['date_to'] : NULL);

  if ($from !== NULL && $to !== NULL && $from > $to) {
    return ['from' => NULL, 'to' => NULL, 'time_from' => NULL, 'time_to' => NULL];
  }

  $time_from = $from !== NULL
    ? myapi_reservation_valid_time(isset($_GET['time_from']) ? $_GET['time_from'] : NULL)
    : NULL;
  $time_to = $to !== NULL
    ? myapi_reservation_valid_time(isset($_GET['time_to']) ? $_GET['time_to'] : NULL)
    : NULL;

  // Single-day range with inverted times: keep the day, drop both times, so
  // the request behaves as if only the date range had been sent instead of
  // returning an always-empty set.
  if ($from !== NULL && $from === $to
    && $time_from !== NULL && $time_to !== NULL && $time_from > $time_to) {
    $time_from = NULL;
    $time_to = NULL;
  }

  return [
    'from'      => $from,
    'to'        => $to,
    'time_from' => $time_from,
    'time_to'   => $time_to,
  ];
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
 * Validates a single 'HH:MM' 24h time string (SPEC 43).
 *
 * Same lax contract as myapi_reservation_valid_date(): anything that is not a
 * well-formed clock time is silently ignored, never a 422. The pattern is the
 * one field_start_time values are created with, so a valid bound compares
 * correctly against the stored column as a plain zero-padded string.
 *
 * @param mixed $value  Raw query-string value, or NULL when absent.
 *
 * @return string|NULL  The value when it is a valid 'HH:MM' time, NULL
 *                       otherwise.
 */
function myapi_reservation_valid_time($value) {
  if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
    return NULL;
  }
  return $value;
}

/**
 * Applies the date-range filter (and its optional time bounds) to a query.
 *
 * Shared by myapi_reservation_count() and myapi_reservation_fetch() so both
 * always filter over exactly the same set — 'total' in the pagination block
 * would otherwise drift from the rows actually returned. Expects the caller
 * to have joined field_data_field_date as 'fdate' and, whenever a time bound
 * is active, field_data_field_start_time as 'fstart'.
 *
 * A bare date bound compares on the day only. A date bound carrying a time
 * refinement becomes a boundary-day comparison instead:
 *
 *   date > from  OR (date = from AND start_time >= time_from)
 *   date < to    OR (date = to   AND start_time <= time_to)
 *
 * so the hour narrows the boundary day and every other day in range keeps all
 * of its reservations. Both ends stay inclusive. On the boundary day a
 * reservation with no field_start_time row is excluded (NULL comparison), the
 * same way a reservation with no date row is excluded from a date range.
 *
 * The placeholder for the day is repeated under two names on purpose: the
 * same named placeholder cannot appear twice in one prepared statement.
 *
 * @param SelectQuery $query      The query being built.
 * @param string|NULL $from       Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to         Inclusive upper bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $time_from  'HH:MM' refinement of $from, or NULL.
 * @param string|NULL $time_to    'HH:MM' refinement of $to, or NULL.
 */
function myapi_reservation_apply_range($query, $from, $to, $time_from = NULL, $time_to = NULL) {
  if ($from !== NULL) {
    if ($time_from !== NULL) {
      $query->where(
        '(SUBSTR(fdate.field_date_value, 1, 10) > :date_from) OR (SUBSTR(fdate.field_date_value, 1, 10) = :date_from_day AND fstart.field_start_time_value >= :time_from)',
        [':date_from' => $from, ':date_from_day' => $from, ':time_from' => $time_from]
      );
    }
    else {
      $query->where('SUBSTR(fdate.field_date_value, 1, 10) >= :date_from', [':date_from' => $from]);
    }
  }

  if ($to !== NULL) {
    if ($time_to !== NULL) {
      $query->where(
        '(SUBSTR(fdate.field_date_value, 1, 10) < :date_to) OR (SUBSTR(fdate.field_date_value, 1, 10) = :date_to_day AND fstart.field_start_time_value <= :time_to)',
        [':date_to' => $to, ':date_to_day' => $to, ':time_to' => $time_to]
      );
    }
    else {
      $query->where('SUBSTR(fdate.field_date_value, 1, 10) <= :date_to', [':date_to' => $to]);
    }
  }
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
 * returned as-is (e.g. '25:30'). The midnight crossing is derived afterwards
 * by comparison (SPEC 41): a same-day area rejects it via
 * myapi_reservation_crosses_disallowed(), while an area that closes after
 * midnight accepts it through the extended-window check.
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
 * Wraps a minutes-since-midnight value back into a 24h 'HH:MM' clock string.
 *
 * The counterpart to myapi_reservation_add_minutes(): where that returns the
 * unbounded value ('26:00') needed by the opening-hours math, this returns the
 * clock time actually persisted on the node ('02:00'), so a range crossing
 * midnight is stored as a real time-of-day and its crossing is derived
 * downstream by comparison (end <= start) — the convention the reservation
 * list and GET /api/v1/areas/%/availability (SPEC 40) both rely on.
 *
 * Pure arithmetic helper: no DB, no Drupal calls.
 *
 * @param int $minutes  Minutes since midnight, possibly >= 1440.
 *
 * @return string  Clock time in 'HH:MM', wrapped into [00:00, 24:00).
 */
function myapi_reservation_wrap_time($minutes) {
  $wrapped = (((int) $minutes % 1440) + 1440) % 1440;
  return sprintf('%02d:%02d', intdiv($wrapped, 60), $wrapped % 60);
}

/**
 * Determines whether an area's opening hours wrap past midnight.
 *
 * An area "wraps" when its close time is at or before its open time on the
 * clock (e.g. open 12:00, close 02:00), meaning it stays open into the early
 * hours of the next calendar day. A normal area (close strictly after open)
 * never wraps.
 *
 * Pure arithmetic helper: no DB, no Drupal calls, unit-testable in isolation
 * (SPEC 41).
 *
 * @param int $open_min   field_open_time as minutes since midnight.
 * @param int $close_min  field_close_time as minutes since midnight.
 *
 * @return bool  TRUE when the area closes after midnight.
 */
function myapi_reservation_area_wraps($open_min, $close_min) {
  return $close_min <= $open_min;
}

/**
 * Normalizes the clock day (field_date) a reservation is stored under.
 *
 * The client always sends date = D (the day it shows on the calendar). When
 * the area wraps past midnight AND the start falls in the early-morning tail
 * [00:00, close) — e.g. a 01:00 start in a 12:00-02:00 area — the reservation
 * actually belongs to the next calendar day, so field_date is normalized to
 * D+1 (same clock-day convention SPEC 40 already uses to show the crossing).
 * Every other case keeps D: a non-wrapping area never normalizes, and neither
 * does the dead gap between close and open (e.g. 05:00 in 12:00-02:00, where
 * start_min < close_min is false), which correctly stays out of hours.
 *
 * Pure arithmetic + date() helper: no DB, no Drupal calls (SPEC 41).
 *
 * @param string $date       'YYYY-MM-DD' as sent by the client (day D).
 * @param int    $start_min  Reservation start, minutes since midnight.
 * @param int    $open_min   field_open_time as minutes since midnight.
 * @param int    $close_min  field_close_time as minutes since midnight.
 *
 * @return string  'YYYY-MM-DD': D+1 when normalized, otherwise D.
 */
function myapi_reservation_effective_date($date, $start_min, $open_min, $close_min) {
  if (myapi_reservation_area_wraps($open_min, $close_min) && $start_min < $close_min) {
    return date('Y-m-d', strtotime($date . ' +1 day'));
  }
  return $date;
}

/**
 * Projects a reservation onto an absolute minute axis anchored at the midnight
 * of its stored field_date.
 *
 * The end is carried one day forward when it wraps below the start
 * (end_min <= start_min, the derived-cross representation of a stored
 * reservation such as 20:00 -> 02:00), so a candidate and every existing
 * reservation can be compared on a single continuous axis regardless of
 * whether either crosses midnight (SPEC 41, validation 6).
 *
 * Pure arithmetic + strtotime() helper: no DB, no Drupal calls.
 *
 * @param string $date       'YYYY-MM-DD' the reservation is stored under.
 * @param int    $start_min  Start, minutes since midnight.
 * @param int    $end_min    End, minutes since midnight (may be <= start_min
 *                           when the stored end already wrapped past midnight).
 *
 * @return array  [start_abs, end_abs] in absolute minutes.
 */
function myapi_reservation_abs_interval($date, $start_min, $end_min) {
  $base = intdiv(strtotime($date . ' 00:00:00'), 60);
  $end_abs = $base + $end_min + ($end_min <= $start_min ? 1440 : 0);
  return [$base + $start_min, $end_abs];
}

/**
 * Tells whether a candidate range crosses midnight in an area that does NOT
 * wrap — the case that must be rejected with reservation_crosses_midnight
 * before the opening-hours window is even evaluated (SPEC 41, validation 4).
 *
 * A range crosses midnight when its end lands at or past 24:00 (end_min >=
 * 1440, the unbounded representation produced by
 * myapi_reservation_add_minutes()) or wraps below its start (end_min <=
 * start_min). When the area itself wraps past midnight, crossing is legal, so
 * this returns FALSE.
 *
 * Pure arithmetic helper: no DB, no Drupal calls.
 *
 * @param int $start_min  Candidate start, minutes since midnight.
 * @param int $end_min    Candidate end, minutes since midnight (unbounded).
 * @param int $open_min   field_open_time as minutes since midnight.
 * @param int $close_min  field_close_time as minutes since midnight.
 *
 * @return bool  TRUE when the range crosses midnight but the area closes the
 *               same clock day.
 */
function myapi_reservation_crosses_disallowed($start_min, $end_min, $open_min, $close_min) {
  if (myapi_reservation_area_wraps($open_min, $close_min)) {
    return FALSE;
  }
  return $end_min >= 1440 || $end_min <= $start_min;
}

/**
 * Checks whether a candidate range falls within an area's opening hours,
 * handling areas that wrap past midnight (SPEC 41, validation 4).
 *
 * A non-wrapping area keeps the original linear check (start at or after open,
 * end at or before close). A wrapping area is evaluated on an extended axis
 * [open_min, close_min + 1440]: a start in the early-morning tail
 * ([00:00, close)) is projected forward by 24h, and the end is projected onto
 * that same frame as start_eff + (end_min - start_min) — for the candidate,
 * end_min - start_min is exactly the duration, so this rejects a tail start
 * whose duration overruns the projected close (e.g. 01:00 + 120min -> 03:00 in
 * a 12:00-02:00 area) instead of wrongly accepting it.
 *
 * Pure arithmetic helper: no DB, no Drupal calls.
 *
 * @param int $start_min  Candidate start, minutes since midnight.
 * @param int $end_min    Candidate end, minutes since midnight (unbounded).
 * @param int $open_min   field_open_time as minutes since midnight.
 * @param int $close_min  field_close_time as minutes since midnight.
 *
 * @return bool  TRUE when the range fits inside the area's opening hours.
 */
function myapi_reservation_within_hours($start_min, $end_min, $open_min, $close_min) {
  if (!myapi_reservation_area_wraps($open_min, $close_min)) {
    return $start_min >= $open_min && $end_min <= $close_min;
  }

  $start_eff = ($start_min < $close_min) ? $start_min + 1440 : $start_min;
  $end_eff = $start_eff + ($end_min - $start_min);
  return $start_eff >= $open_min && $end_eff <= $close_min + 1440;
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
 * @param int         $unit_id    The 'vivienda' nid to filter by.
 * @param string|NULL $from       Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to         Inclusive upper bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $time_from  'HH:MM' refinement of $from, or NULL.
 * @param string|NULL $time_to    'HH:MM' refinement of $to, or NULL.
 * @param string|NULL $status     'confirmed', 'cancelled', or NULL for both.
 * @param int         $uid        The authenticated user's uid.
 *
 * @return int  Total number of matching reservations.
 */
function myapi_reservation_count($unit_id, $from = NULL, $to = NULL, $time_from = NULL, $time_to = NULL, $status = NULL, $uid = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->condition('fu.field_unit_target_id', $unit_id);

  $query->innerJoin('field_data_field_requester', 'freq', "freq.entity_id = n.nid AND freq.entity_type = 'node' AND freq.deleted = 0");
  $query->condition('freq.field_requester_target_id', $uid);

  if ($from !== NULL || $to !== NULL) {
    $query->innerJoin('field_data_field_date', 'fdate', "fdate.entity_id = n.nid AND fdate.entity_type = 'node' AND fdate.deleted = 0");
    // The start time is only needed when a bound carries a time refinement;
    // left-joined so a reservation with no start_time row still reaches the
    // WHERE clause (and is dropped there, on the boundary day only).
    if ($time_from !== NULL || $time_to !== NULL) {
      $query->leftJoin('field_data_field_start_time', 'fstart', "fstart.entity_id = n.nid AND fstart.entity_type = 'node' AND fstart.deleted = 0");
    }
    myapi_reservation_apply_range($query, $from, $to, $time_from, $time_to);
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
 * @param string      $sort      'asc' or 'desc', applied to field_date_value
 *                               and then to field_start_time_value.
 * @param string|NULL $from      Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to        Inclusive upper bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $time_from 'HH:MM' refinement of $from, or NULL.
 * @param string|NULL $time_to   'HH:MM' refinement of $to, or NULL.
 * @param string|NULL $status    'confirmed', 'cancelled', or NULL for both.
 * @param int         $uid       The authenticated user's uid. Rows are always
 *                               restricted to field_requester = $uid (SPEC 37).
 *
 * @return array  Rows keyed numerically, one per reservation.
 */
function myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from = NULL, $to = NULL, $time_from = NULL, $time_to = NULL, $status = NULL, $uid = NULL) {
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

  $query->leftJoin('field_data_field_start_time', 'fstart', "fstart.entity_id = n.nid AND fstart.entity_type = 'node' AND fstart.deleted = 0");
  $query->addField('fstart', 'field_start_time_value', 'start_time');

  // Optional date-range filter on field_date, with its optional time bounds
  // on field_start_time. Applying a condition on the left-joined column also
  // excludes reservations with no date row (NULL), which is the desired
  // behaviour: a reservation without a date cannot fall in a range.
  myapi_reservation_apply_range($query, $from, $to, $time_from, $time_to);

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
  // Second key (SPEC 43): reservations of the same day come back in clock
  // order, so an ascending list reads as a real timeline instead of jumping
  // 15:00 before 09:00. Stored as zero-padded 'HH:MM', so a plain string sort
  // is already chronological. Rows with no start_time row sort together at
  // one end (NULL), never interleaved.
  $query->orderBy('fstart.field_start_time_value', $sort === 'asc' ? 'ASC' : 'DESC');
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
