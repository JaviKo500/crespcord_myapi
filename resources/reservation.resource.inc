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
