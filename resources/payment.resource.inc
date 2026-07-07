<?php

/**
 * @file
 * Payment resource for the myapi module.
 *
 * Lists 'pagos' nodes (exposed as payments) of a unit for its owner or
 * occupant. Read-only, paginated, with an optional date-range filter on
 * field_fecha_de_pago. Mirrors extra_fee.resource.inc, except the estado
 * criterion is by exclusion: every state but 'Nuevo' is exposed.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Payments in this 'field_estado_pago' state are hidden by the endpoint.
 *
 * The criterion is by exclusion: every other state is exposed, and a payment
 * with no estado row is also hidden (inner join with <> this value).
 */
define('MYAPI_PAYMENT_EXCLUDED_STATUS', 'Nuevo');

/**
 * Dispatcher for /api/v1/units/%/payments.
 *
 * Routes by HTTP method. Only GET is supported.
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_payment_dispatch($unit_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_payment_list($unit_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/units/%/payments.
 *
 * Returns, for the authenticated user, a paginated list of 'pagos' nodes
 * belonging to $unit_id whose estado is not 'Nuevo', provided the user is the
 * owner or occupant of that unit (same access rule as GET /api/v1/units).
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_payment_list($unit_id) {
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

  $range = myapi_payment_parse_date_range();
  $from = $range['from'];
  $to = $range['to'];

  $total = myapi_payment_count($unit_id, $from, $to);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_payment_fetch($unit_id, $page, $limit, $sort, $from, $to);
  $payments = array_map('myapi_payment_build_item', $rows);

  myapi_respond([
    'payments' => $payments,
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
function myapi_payment_parse_date_range() {
  $from = myapi_payment_valid_date(isset($_GET['date_from']) ? $_GET['date_from'] : NULL);
  $to = myapi_payment_valid_date(isset($_GET['date_to']) ? $_GET['date_to'] : NULL);

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
function myapi_payment_valid_date($value) {
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
    return NULL;
  }
  if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
    return NULL;
  }
  return $value;
}

/**
 * Counts published 'pagos' nodes for a unit, unpaginated.
 *
 * Only payments whose estado is not 'Nuevo' are counted (inner join with a
 * <> condition), so a payment in state 'Nuevo' or with no estado row is
 * excluded. When a date bound is given, joins field_data_field_fecha_de_pago
 * and restricts by field_fecha_de_pago_value so the count matches the same
 * filtered set as the fetched page.
 *
 * @param int         $unit_id  The 'vivienda' nid to filter by.
 * @param string|NULL $from     Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to       Inclusive upper bound 'YYYY-MM-DD', or NULL.
 *
 * @return int  Total number of matching payments.
 */
function myapi_payment_count($unit_id, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'pagos')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $unit_id);

  // Estado is an inner join with a <> condition: every state but the excluded
  // one is counted, and a payment with no estado row is excluded, so the count
  // matches the fetched, filtered set.
  $query->innerJoin('field_data_field_estado_pago', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_pago_value', MYAPI_PAYMENT_EXCLUDED_STATUS, '<>');

  if ($from !== NULL || $to !== NULL) {
    $query->innerJoin('field_data_field_fecha_de_pago', 'ffec', "ffec.entity_id = n.nid AND ffec.entity_type = 'node' AND ffec.deleted = 0");
    if ($from !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_de_pago_value, 1, 10) >= :date_from', [':date_from' => $from]);
    }
    if ($to !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_de_pago_value, 1, 10) <= :date_to', [':date_to' => $to]);
    }
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Fetches a page of 'pagos' nodes for a unit, with every mapped field.
 *
 * @param int         $unit_id  The 'vivienda' nid to filter by.
 * @param int         $page     1-based page number.
 * @param int         $limit    Rows per page, already clamped to [1, 50], or
 *                              -1 to return every row unpaginated.
 * @param string      $sort     'asc' or 'desc', applied to
 *                              field_fecha_de_pago_value.
 * @param string|NULL $from     Inclusive lower bound 'YYYY-MM-DD', or NULL.
 * @param string|NULL $to       Inclusive upper bound 'YYYY-MM-DD', or NULL.
 *
 * @return array  Rows keyed numerically, one per payment.
 */
function myapi_payment_fetch($unit_id, $page, $limit, $sort, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'pagos')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $unit_id);
  $query->addField('fv', 'field_vivienda_target_id', 'unit_id');

  $query->leftJoin('field_data_field_fecha_de_pago', 'ffec', "ffec.entity_id = n.nid AND ffec.entity_type = 'node' AND ffec.deleted = 0");
  $query->addField('ffec', 'field_fecha_de_pago_value', 'payment_date');

  // Optional date-range filter on field_fecha_de_pago. Applying a condition on
  // the left-joined column also excludes payments with no fecha row (NULL),
  // which is the desired behaviour: a payment without a date cannot fall in a
  // range.
  if ($from !== NULL) {
    $query->where('SUBSTR(ffec.field_fecha_de_pago_value, 1, 10) >= :date_from', [':date_from' => $from]);
  }
  if ($to !== NULL) {
    $query->where('SUBSTR(ffec.field_fecha_de_pago_value, 1, 10) <= :date_to', [':date_to' => $to]);
  }

  // Estado is an inner join with a <> condition: every state but the excluded
  // one is returned, and a payment with no estado row is excluded.
  $query->innerJoin('field_data_field_estado_pago', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_pago_value', MYAPI_PAYMENT_EXCLUDED_STATUS, '<>');
  $query->addField('fest', 'field_estado_pago_value', 'status');

  $query->leftJoin('field_data_field_forma_de_pago', 'ffor', "ffor.entity_id = n.nid AND ffor.entity_type = 'node' AND ffor.deleted = 0");
  $query->addField('ffor', 'field_forma_de_pago_value', 'payment_method');

  $query->leftJoin('field_data_field_referencia', 'fref', "fref.entity_id = n.nid AND fref.entity_type = 'node' AND fref.deleted = 0");
  $query->addField('fref', 'field_referencia_value', 'reference');

  $query->leftJoin('field_data_field_valor', 'fval', "fval.entity_id = n.nid AND fval.entity_type = 'node' AND fval.deleted = 0");
  $query->addField('fval', 'field_valor_value', 'amount');

  $query->orderBy('ffec.field_fecha_de_pago_value', $sort === 'asc' ? 'ASC' : 'DESC');
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }

  return $query->execute()->fetchAll();
}

/**
 * Maps one raw payment row into the response shape documented for
 * GET /api/v1/units/%/payments.
 *
 * 'amount' (decimal) is cast to float when present, NULL otherwise. Text
 * fields (payment_date, status, payment_method, reference) pass through as
 * stored, with no business interpretation, and stay NULL when the node has no
 * row for that field.
 *
 * @param object $payment  One row from myapi_payment_fetch().
 *
 * @return array  Response item with all 8 mapped keys.
 */
function myapi_payment_build_item($payment) {
  return [
    'id'             => (int) $payment->nid,
    'title'          => $payment->title,
    'unit_id'        => (int) $payment->unit_id,
    'payment_date'   => $payment->payment_date,
    'status'         => $payment->status,
    'payment_method' => $payment->payment_method,
    'reference'      => $payment->reference,
    'amount'         => $payment->amount !== NULL ? (float) $payment->amount : NULL,
  ];
}
