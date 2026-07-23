<?php

/**
 * @file
 * Reservation resource for the myapi module.
 *
 * Lists 'reservation' nodes of a unit for its owner or occupant. Read-only,
 * paginated, with an optional date-range and status filter. Mirrors
 * payment.resource.inc, except both 'confirmed' and 'cancelled' reservations
 * are exposed (status travels in the payload instead of being excluded).
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

  // 6. Overlap with another 'confirmed' reservation of the same area/date.
  if (myapi_reservation_has_overlap($area_id, $date, $start_minutes, $end_minutes)) {
    myapi_error('reservation_overlap', 409);
  }

  // 7. Only one active 'confirmed' reservation per unit+area (start not yet
  // passed).
  if (myapi_reservation_has_active_reservation($unit_id, $area_id)) {
    myapi_error('reservation_already_active', 409);
  }

  // 8. Balance: current debt blocks new reservations unless the most recent
  // sent receipt shows no previous balance due.
  if (!myapi_reservation_check_balance($unit_id)) {
    myapi_error('insufficient_balance', 403);
  }

  $node = myapi_reservation_build_node($unit_condominium_id, $unit_id, $uid, $area_id, $date, $start_time, $end_time);
  node_save($node);

  myapi_respond(['reservation' => myapi_reservation_build_created_item($node, $area->title)], 201, 'reservation_created');
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
 * Maps the just-created 'reservation' node into the 201 response shape.
 *
 * Same shape as myapi_reservation_build_item() (GET /api/v1/units/%/reservations,
 * SPEC 34), but reads straight from the in-memory node built by
 * myapi_reservation_build_node() instead of a DB row, since the fetch query
 * used for the list is not re-run here. 'cancelled_by' is always NULL (never
 * set on creation); 'area_name' comes from the caller since the node itself
 * only stores the area's nid.
 *
 * @param object $node        The saved reservation node.
 * @param string $area_title  The reserved area's title.
 *
 * @return array  Response item with all 12 mapped keys.
 */
function myapi_reservation_build_created_item($node, $area_title) {
  return [
    'id'             => (int) $node->nid,
    'condominium_id' => (int) myapi_reservation_node_target_id($node, 'field_condominium'),
    'unit_id'        => (int) myapi_reservation_node_target_id($node, 'field_unit'),
    'requester_id'   => (int) myapi_reservation_node_target_id($node, 'field_requester'),
    'area_id'        => (int) myapi_reservation_node_target_id($node, 'field_area'),
    'area_name'      => $area_title,
    'date'           => myapi_reservation_node_value($node, 'field_date'),
    'start_time'     => myapi_reservation_node_value($node, 'field_start_time'),
    'end_time'       => myapi_reservation_node_value($node, 'field_end_time'),
    'status'         => myapi_reservation_node_value($node, 'field_reservation_status'),
    'cancelled_by'   => NULL,
    'created'        => format_date($node->created, 'custom', 'Y-m-d\TH:i:s'),
  ];
}

/**
 * Handles GET /api/v1/units/%/reservations.
 *
 * Returns, for the authenticated user, a paginated list of 'reservation'
 * nodes belonging to $unit_id, provided the user is the owner or occupant of
 * that unit (same access rule as GET /api/v1/units). Both 'confirmed' and
 * 'cancelled' reservations are returned; the status travels in the payload.
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

  $total = myapi_reservation_count($unit_id, $from, $to, $status);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from, $to, $status);
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
 *
 * @param int         $unit_id  The 'vivienda' nid to filter by.
 * @param string|NULL $from     Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to       Inclusive upper bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $status   'confirmed', 'cancelled', or NULL for both.
 *
 * @return int  Total number of matching reservations.
 */
function myapi_reservation_count($unit_id, $from = NULL, $to = NULL, $status = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->condition('fu.field_unit_target_id', $unit_id);

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
 *
 * @return array  Rows keyed numerically, one per reservation.
 */
function myapi_reservation_fetch($unit_id, $page, $limit, $sort, $from = NULL, $to = NULL, $status = NULL) {
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

  $query->leftJoin('field_data_field_area', 'farea', "farea.entity_id = n.nid AND farea.entity_type = 'node' AND farea.deleted = 0");
  $query->addField('farea', 'field_area_target_id', 'area_id');
  $query->leftJoin('node', 'na', 'na.nid = farea.field_area_target_id');
  $query->addField('na', 'title', 'area_name');

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
 * that field.
 *
 * @param object $reservation  One row from myapi_reservation_fetch().
 *
 * @return array  Response item with all 12 mapped keys.
 */
function myapi_reservation_build_item($reservation) {
  return [
    'id'             => (int) $reservation->nid,
    'condominium_id' => $reservation->condominium_id !== NULL ? (int) $reservation->condominium_id : NULL,
    'unit_id'        => $reservation->unit_id !== NULL ? (int) $reservation->unit_id : NULL,
    'requester_id'   => $reservation->requester_id !== NULL ? (int) $reservation->requester_id : NULL,
    'area_id'        => $reservation->area_id !== NULL ? (int) $reservation->area_id : NULL,
    'area_name'      => $reservation->area_name,
    'date'           => $reservation->date !== NULL ? substr($reservation->date, 0, 10) : NULL,
    'start_time'     => $reservation->start_time,
    'end_time'       => $reservation->end_time,
    'status'         => $reservation->status,
    'cancelled_by'   => $reservation->cancelled_by,
    'created'        => format_date($reservation->created, 'custom', 'Y-m-d\TH:i:s'),
  ];
}
