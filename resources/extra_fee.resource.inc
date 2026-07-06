<?php

/**
 * @file
 * Extra fee resource for the myapi module.
 *
 * Lists 'alicuota_extra' nodes (exposed as extra_fees) of a unit for its
 * owner or occupant. Read-only, paginated, with an optional date-range filter
 * on field_fecha. Mirrors receipt.resource.inc.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Only extra fees in this 'field_estado' state are exposed by the endpoint.
 * Any other state (or an extra fee with no estado row) is silently ignored.
 */
define('MYAPI_EXTRA_FEE_EXPOSED_STATUS', 'Enviado');

/**
 * Dispatcher for /api/v1/units/%/extra-fees.
 *
 * Routes by HTTP method. Only GET is supported.
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_extra_fee_dispatch($unit_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_extra_fee_list($unit_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/units/%/extra-fees.
 *
 * Returns, for the authenticated user, a paginated list of 'alicuota_extra'
 * nodes belonging to $unit_id, provided the user is the owner or occupant of
 * that unit (same access rule as GET /api/v1/units).
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_extra_fee_list($unit_id) {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  $nids = myapi_unit_related_nids($uid);
  if (!in_array((int) $unit_id, $nids)) {
    myapi_error('unit_access_denied', 403);
  }

  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
    ? (int) $_GET['limit']
    : 20;
  $limit = max(1, min(50, $limit));

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  $range = myapi_extra_fee_parse_date_range();
  $from = $range['from'];
  $to = $range['to'];

  $total = myapi_extra_fee_count($unit_id, $from, $to);
  $total_pages = $total > 0 ? (int) ceil($total / $limit) : 0;

  $rows = myapi_extra_fee_fetch($unit_id, $page, $limit, $sort, $from, $to);
  $extra_fees = array_map('myapi_extra_fee_build_item', $rows);

  myapi_respond([
    'extra_fees' => $extra_fees,
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
function myapi_extra_fee_parse_date_range() {
  $from = myapi_extra_fee_valid_date(isset($_GET['date_from']) ? $_GET['date_from'] : NULL);
  $to = myapi_extra_fee_valid_date(isset($_GET['date_to']) ? $_GET['date_to'] : NULL);

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
function myapi_extra_fee_valid_date($value) {
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
    return NULL;
  }
  if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
    return NULL;
  }
  return $value;
}

/**
 * Counts published 'alicuota_extra' nodes for a unit, unpaginated.
 *
 * When a date bound is given, joins field_data_field_fecha and restricts by
 * field_fecha_value so the count matches the same filtered set as the fetched
 * page.
 *
 * @param int         $unit_id  The 'vivienda' nid to filter by.
 * @param string|NULL $from     Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to       Inclusive upper bound 'YYYY-MM-DD', or NULL.
 *
 * @return int  Total number of matching extra fees.
 */
function myapi_extra_fee_count($unit_id, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'alicuota_extra')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $unit_id);

  // Only extra fees in the exposed state are counted; any other state (or none)
  // is excluded so the count matches the fetched, filtered set.
  $query->innerJoin('field_data_field_estado', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_value', MYAPI_EXTRA_FEE_EXPOSED_STATUS);

  if ($from !== NULL || $to !== NULL) {
    $query->innerJoin('field_data_field_fecha', 'ffec', "ffec.entity_id = n.nid AND ffec.entity_type = 'node' AND ffec.deleted = 0");
    if ($from !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_value, 1, 10) >= :date_from', [':date_from' => $from]);
    }
    if ($to !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_value, 1, 10) <= :date_to', [':date_to' => $to]);
    }
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Fetches a page of 'alicuota_extra' nodes for a unit, with every mapped field.
 *
 * @param int         $unit_id  The 'vivienda' nid to filter by.
 * @param int         $page     1-based page number.
 * @param int         $limit    Rows per page, already clamped to [1, 50].
 * @param string      $sort     'asc' or 'desc', applied to field_fecha_value.
 * @param string|NULL $from     Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to       Inclusive upper bound 'YYYY-MM-DD', or NULL.
 *
 * @return array  Rows keyed numerically, one per extra fee.
 */
function myapi_extra_fee_fetch($unit_id, $page, $limit, $sort, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'alicuota_extra')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $unit_id);
  $query->addField('fv', 'field_vivienda_target_id', 'unit_id');

  $query->leftJoin('field_data_field_fecha', 'ffec', "ffec.entity_id = n.nid AND ffec.entity_type = 'node' AND ffec.deleted = 0");
  $query->addField('ffec', 'field_fecha_value', 'date');

  // Optional date-range filter on field_fecha. Applying a condition on the
  // left-joined column also excludes extra fees with no fecha row (NULL), which
  // is the desired behaviour: an extra fee without a date cannot fall in a
  // range.
  if ($from !== NULL) {
    $query->where('SUBSTR(ffec.field_fecha_value, 1, 10) >= :date_from', [':date_from' => $from]);
  }
  if ($to !== NULL) {
    $query->where('SUBSTR(ffec.field_fecha_value, 1, 10) <= :date_to', [':date_to' => $to]);
  }

  // Estado is an inner join with an equality condition: only extra fees whose
  // state is the exposed one are returned, every other state (or a missing
  // estado row) is excluded.
  $query->innerJoin('field_data_field_estado', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_value', MYAPI_EXTRA_FEE_EXPOSED_STATUS);
  $query->addField('fest', 'field_estado_value', 'status');

  $query->leftJoin('field_data_field_valor_extra', 'fval', "fval.entity_id = n.nid AND fval.entity_type = 'node' AND fval.deleted = 0");
  $query->addField('fval', 'field_valor_extra_value', 'extra_fee');

  $query->leftJoin('field_data_field_saldo_anterior', 'fsant', "fsant.entity_id = n.nid AND fsant.entity_type = 'node' AND fsant.deleted = 0");
  $query->addField('fsant', 'field_saldo_anterior_value', 'previous_balance');

  $query->leftJoin('field_data_field_total', 'ftot', "ftot.entity_id = n.nid AND ftot.entity_type = 'node' AND ftot.deleted = 0");
  $query->addField('ftot', 'field_total_value', 'total');

  $query->orderBy('ffec.field_fecha_value', $sort === 'asc' ? 'ASC' : 'DESC');
  $query->range(($page - 1) * $limit, $limit);

  return $query->execute()->fetchAll();
}

/**
 * Maps one raw extra-fee row into the response shape documented for
 * GET /api/v1/units/%/extra-fees.
 *
 * The decimal fields are cast to float when present, NULL otherwise. Text
 * fields (date, status) pass through as stored, with no business
 * interpretation.
 *
 * @param object $extra_fee  One row from myapi_extra_fee_fetch().
 *
 * @return array  Response item with all 8 mapped keys.
 */
function myapi_extra_fee_build_item($extra_fee) {
  $float_fields = ['extra_fee', 'previous_balance', 'total'];

  $item = [
    'id'      => (int) $extra_fee->nid,
    'title'   => $extra_fee->title,
    'unit_id' => (int) $extra_fee->unit_id,
    'date'    => $extra_fee->date,
    'status'  => $extra_fee->status,
  ];

  foreach ($float_fields as $field) {
    $item[$field] = $extra_fee->$field !== NULL ? (float) $extra_fee->$field : NULL;
  }

  return $item;
}
