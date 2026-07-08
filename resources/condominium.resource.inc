<?php

/**
 * @file
 * Condominium resource for the myapi module.
 *
 * Exposes GET /api/v1/condominiums/%/summary: an authenticated,
 * condominium-level summary for a user who owns or occupies at least one unit
 * there. Returns the condominium id and name, the aggregated total and count
 * of its 'Activo' expenses (with an optional lax date-range filter) and its
 * cash balance (field_saldo_caja). Read-only. Shares the condominium access
 * check and the date-range parsing with expense.resource.inc, without calling
 * that resource's internal functions: the shared logic lives in includes/.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Only expenses in this 'field_estado_gasto' state enter the aggregate. Any
 * other state (or an expense with no estado row) is excluded from both the sum
 * and the count. The value matches expense.resource.inc's exposed state, but
 * the constant is kept local so this resource does not depend on another
 * resource's define.
 */
define('MYAPI_CONDOMINIUM_EXPENSE_STATUS', 'Activo');

/**
 * Dispatcher for /api/v1/condominiums/%/summary.
 *
 * Routes by HTTP method. Only GET is supported.
 *
 * @param int $condominium_id  The 'condominio' nid from the route.
 */
function myapi_condominium_dispatch($condominium_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_condominium_summary($condominium_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/condominiums/%/summary.
 *
 * Returns, for the authenticated user, the condominium's id and name, the
 * aggregated total and count of its 'Activo' expenses (optionally narrowed by
 * a date range) and its cash balance, provided the user owns or occupies at
 * least one unit in that condominium. A condominium the user has no unit in
 * (or a non-existent one) yields 403 condominium_access_denied without
 * distinguishing the reason.
 *
 * @param int $condominium_id  The 'condominio' nid from the route.
 */
function myapi_condominium_summary($condominium_id) {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  $nids = myapi_condominium_related_nids($uid);
  if (!in_array((int) $condominium_id, $nids)) {
    myapi_error('condominium_access_denied', 403);
  }

  $range = myapi_parse_date_range_param();
  $from = $range['from'];
  $to = $range['to'];

  $totals = myapi_condominium_expense_totals($condominium_id, $from, $to);
  $name = myapi_condominium_name($condominium_id);
  $cash = myapi_condominium_cash_balance($condominium_id);

  myapi_respond([
    'id'             => (int) $condominium_id,
    'name'           => $name,
    'total_expenses' => $totals['total'],
    'expenses_count' => $totals['count'],
    'cash_balance'   => $cash,
  ], 200);
}

/**
 * Aggregates the total amount and count of a condominium's 'Activo' expenses.
 *
 * Operates over the same set expense.resource.inc would list: published
 * 'gastos' nodes (type='gastos', status=1) whose field_condominio equals
 * $condominium_id and whose field_estado_gasto is the exposed state, narrowed
 * by the optional date range. Estado is an inner join with an equality
 * condition, so an expense in another state (or with no estado row) is
 * excluded from both the sum and the count.
 *
 * field_valor is left-joined: an expense with no valor row still counts in
 * expenses_count but contributes 0 to the sum (SUM ignores NULL), so
 * total_expenses and expenses_count need not match 1:1. When a date bound is
 * given, field_data_field_fecha_de_gasto is inner-joined and restricted by
 * field_fecha_de_gasto_value, which also excludes expenses with no fecha row.
 *
 * @param int         $condominium_id  The 'condominio' nid to filter by.
 * @param string|NULL $from            Inclusive lower bound 'YYYY-MM-DD', or
 *                                     NULL.
 * @param string|NULL $to              Inclusive upper bound 'YYYY-MM-DD', or
 *                                     NULL.
 *
 * @return array  ['total' => float, 'count' => int]. Empty set → total 0.0,
 *                count 0.
 */
function myapi_condominium_expense_totals($condominium_id, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'gastos')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->condition('fcond.field_condominio_target_id', $condominium_id);

  // Estado is an inner join with an equality condition: only expenses whose
  // state is the exposed one enter the aggregate, every other state (or a
  // missing estado row) is excluded.
  $query->innerJoin('field_data_field_estado_gasto', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_gasto_value', MYAPI_CONDOMINIUM_EXPENSE_STATUS);

  if ($from !== NULL || $to !== NULL) {
    $query->innerJoin('field_data_field_fecha_de_gasto', 'ffec', "ffec.entity_id = n.nid AND ffec.entity_type = 'node' AND ffec.deleted = 0");
    if ($from !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_de_gasto_value, 1, 10) >= :date_from', [':date_from' => $from]);
    }
    if ($to !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_de_gasto_value, 1, 10) <= :date_to', [':date_to' => $to]);
    }
  }

  // Left join to valor: an expense with no amount still counts but adds 0.
  $query->leftJoin('field_data_field_valor', 'fval', "fval.entity_id = n.nid AND fval.entity_type = 'node' AND fval.deleted = 0");

  $query->addExpression('SUM(fval.field_valor_value)', 'total');
  $query->addExpression('COUNT(n.nid)', 'count');

  $row = $query->execute()->fetchObject();

  return [
    'total' => ($row && $row->total !== NULL) ? (float) $row->total : 0.0,
    'count' => $row ? (int) $row->count : 0,
  ];
}

/**
 * Reads the title of a 'condominio' node.
 *
 * Filtered by n.type='condominio' so a nid that happens to be another bundle
 * never resolves here. Access has already been granted by the caller through
 * the user's units, not by this node's status, so a NULL is possible only if
 * the node was deleted or is not a condominio.
 *
 * @param int $condominium_id  The 'condominio' nid.
 *
 * @return string|NULL  The node title, or NULL when no matching node exists.
 */
function myapi_condominium_name($condominium_id) {
  $title = db_select('node', 'n')
    ->fields('n', ['title'])
    ->condition('n.nid', $condominium_id)
    ->condition('n.type', 'condominio')
    ->execute()
    ->fetchField();

  return $title !== FALSE ? $title : NULL;
}

/**
 * Reads a condominium's cash balance from field_saldo_caja.
 *
 * field_saldo_caja is a single-value decimal(10,2) bound to the 'condominio'
 * bundle, so at most one row matches. The value is exposed as-is (including
 * sign), with no business interpretation, mirroring how spec 10 exposes
 * current_balance. A condominium with no row yields NULL (balance not
 * recorded), which is a genuine absence of data rather than 0.0.
 *
 * @param int $condominium_id  The 'condominio' nid.
 *
 * @return float|NULL  The stored balance as float, or NULL when no row exists.
 */
function myapi_condominium_cash_balance($condominium_id) {
  $value = db_select('field_data_field_saldo_caja', 'fs')
    ->fields('fs', ['field_saldo_caja_value'])
    ->condition('fs.entity_id', $condominium_id)
    ->condition('fs.entity_type', 'node')
    ->condition('fs.deleted', 0)
    ->execute()
    ->fetchField();

  return $value !== FALSE ? (float) $value : NULL;
}
