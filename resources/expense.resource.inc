<?php

/**
 * @file
 * Expense resource for the myapi module.
 *
 * Lists 'gastos' nodes (exposed as expenses) of a condominium for a user who
 * owns or occupies at least one unit there. Read-only, paginated, with an
 * optional date-range filter on field_fecha_de_gasto. Mirrors
 * payment.resource.inc, except the estado criterion is a single exposed
 * state ('Activo'), like extra_fee.resource.inc, and access is scoped to a
 * condominium instead of a unit.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Only expenses in this 'field_estado_gasto' state are exposed by the
 * endpoint. Any other state (or an expense with no estado row) is silently
 * ignored.
 */
define('MYAPI_EXPENSE_EXPOSED_STATUS', 'Activo');

/**
 * Dispatcher for /api/v1/condominiums/%/expenses.
 *
 * Routes by HTTP method. Only GET is supported.
 *
 * @param int $condominium_id  The 'condominio' nid from the route.
 */
function myapi_expense_dispatch($condominium_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_expense_list($condominium_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/condominiums/%/expenses.
 *
 * Returns, for the authenticated user, a paginated list of 'gastos' nodes
 * belonging to $condominium_id whose estado is 'Activo', provided the user
 * owns or occupies at least one unit in that condominium.
 *
 * @param int $condominium_id  The 'condominio' nid from the route.
 */
function myapi_expense_list($condominium_id) {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  $nids = myapi_condominium_related_nids($uid);
  if (!in_array((int) $condominium_id, $nids)) {
    myapi_error('condominium_access_denied', 403);
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

  $range = myapi_parse_date_range_param();
  $from = $range['from'];
  $to = $range['to'];

  $total = myapi_expense_count($condominium_id, $from, $to);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_expense_fetch($condominium_id, $page, $limit, $sort, $from, $to);
  $expenses = array_map('myapi_expense_build_item', $rows);

  myapi_respond([
    'expenses' => $expenses,
    'pagination' => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * Counts published 'gastos' nodes for a condominium, unpaginated.
 *
 * Only expenses in the exposed state are counted (inner join with an
 * equality condition), so an expense in another state or with no estado row
 * is excluded. When a date bound is given, joins
 * field_data_field_fecha_de_gasto and restricts by
 * field_fecha_de_gasto_value so the count matches the same filtered set as
 * the fetched page.
 *
 * @param int         $condominium_id  The 'condominio' nid to filter by.
 * @param string|NULL $from            Inclusive lower bound 'YYYY-MM-DD', or
 *                                     NULL.
 * @param string|NULL $to              Inclusive upper bound 'YYYY-MM-DD', or
 *                                     NULL.
 *
 * @return int  Total number of matching expenses.
 */
function myapi_expense_count($condominium_id, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'gastos')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->condition('fcond.field_condominio_target_id', $condominium_id);

  // Estado is an inner join with an equality condition: only expenses whose
  // state is the exposed one are counted, every other state (or a missing
  // estado row) is excluded.
  $query->innerJoin('field_data_field_estado_gasto', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_gasto_value', MYAPI_EXPENSE_EXPOSED_STATUS);

  if ($from !== NULL || $to !== NULL) {
    $query->innerJoin('field_data_field_fecha_de_gasto', 'ffec', "ffec.entity_id = n.nid AND ffec.entity_type = 'node' AND ffec.deleted = 0");
    if ($from !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_de_gasto_value, 1, 10) >= :date_from', [':date_from' => $from]);
    }
    if ($to !== NULL) {
      $query->where('SUBSTR(ffec.field_fecha_de_gasto_value, 1, 10) <= :date_to', [':date_to' => $to]);
    }
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Fetches a page of 'gastos' nodes for a condominium, with every mapped
 * field.
 *
 * @param int         $condominium_id  The 'condominio' nid to filter by.
 * @param int         $page            1-based page number.
 * @param int         $limit           Rows per page, already clamped to
 *                                      [1, 50], or -1 to return every row
 *                                      unpaginated.
 * @param string      $sort            'asc' or 'desc', applied to
 *                                      field_fecha_de_gasto_value.
 * @param string|NULL $from            Inclusive lower bound 'YYYY-MM-DD', or
 *                                      NULL.
 * @param string|NULL $to              Inclusive upper bound 'YYYY-MM-DD', or
 *                                      NULL.
 *
 * @return array  Rows keyed numerically, one per expense.
 */
function myapi_expense_fetch($condominium_id, $page, $limit, $sort, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'gastos')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->condition('fcond.field_condominio_target_id', $condominium_id);
  $query->addField('fcond', 'field_condominio_target_id', 'condominium_id');

  $query->leftJoin('field_data_field_fecha_de_gasto', 'ffec', "ffec.entity_id = n.nid AND ffec.entity_type = 'node' AND ffec.deleted = 0");
  $query->addField('ffec', 'field_fecha_de_gasto_value', 'expense_date');

  // Optional date-range filter on field_fecha_de_gasto. Applying a condition
  // on the left-joined column also excludes expenses with no fecha row
  // (NULL), which is the desired behaviour: an expense without a date cannot
  // fall in a range.
  if ($from !== NULL) {
    $query->where('SUBSTR(ffec.field_fecha_de_gasto_value, 1, 10) >= :date_from', [':date_from' => $from]);
  }
  if ($to !== NULL) {
    $query->where('SUBSTR(ffec.field_fecha_de_gasto_value, 1, 10) <= :date_to', [':date_to' => $to]);
  }

  // Estado is an inner join with an equality condition: only expenses whose
  // state is the exposed one are returned, every other state (or a missing
  // estado row) is excluded.
  $query->innerJoin('field_data_field_estado_gasto', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->condition('fest.field_estado_gasto_value', MYAPI_EXPENSE_EXPOSED_STATUS);
  $query->addField('fest', 'field_estado_gasto_value', 'status');

  $query->leftJoin('field_data_field_descripcion', 'fdesc', "fdesc.entity_id = n.nid AND fdesc.entity_type = 'node' AND fdesc.deleted = 0");
  $query->addField('fdesc', 'field_descripcion_value', 'description');

  $query->leftJoin('field_data_field_categoria', 'fcat', "fcat.entity_id = n.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0");
  $query->addField('fcat', 'field_categoria_tid', 'category_id');
  $query->leftJoin('taxonomy_term_data', 'tcat', 'tcat.tid = fcat.field_categoria_tid');
  $query->addField('tcat', 'name', 'category_name');

  $query->leftJoin('field_data_field_valor', 'fval', "fval.entity_id = n.nid AND fval.entity_type = 'node' AND fval.deleted = 0");
  $query->addField('fval', 'field_valor_value', 'amount');

  $query->leftJoin('field_data_field_referencia', 'fref', "fref.entity_id = n.nid AND fref.entity_type = 'node' AND fref.deleted = 0");
  $query->addField('fref', 'field_referencia_value', 'reference');

  $query->orderBy('ffec.field_fecha_de_gasto_value', $sort === 'asc' ? 'ASC' : 'DESC');
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }

  return $query->execute()->fetchAll();
}

/**
 * Maps one raw expense row into the response shape documented for
 * GET /api/v1/condominiums/%/expenses.
 *
 * 'amount' (decimal) is cast to float when present, NULL otherwise. 'id',
 * 'condominium_id' and 'category_id' are cast to int, the last one only when
 * not NULL. Text fields (title, description, category_name, expense_date,
 * reference, status) pass through as stored, with no business
 * interpretation, and stay NULL when the node has no row for that field.
 *
 * @param object $expense  One row from myapi_expense_fetch().
 *
 * @return array  Response item with all 10 mapped keys.
 */
function myapi_expense_build_item($expense) {
  return [
    'id'              => (int) $expense->nid,
    'title'           => $expense->title,
    'condominium_id'  => (int) $expense->condominium_id,
    'description'     => $expense->description,
    'category_id'     => $expense->category_id !== NULL ? (int) $expense->category_id : NULL,
    'category_name'   => $expense->category_name,
    'expense_date'    => $expense->expense_date,
    'amount'          => $expense->amount !== NULL ? (float) $expense->amount : NULL,
    'reference'       => $expense->reference,
    'status'          => $expense->status,
  ];
}
