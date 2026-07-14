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
 * Dispatcher for the flat /api/v1/payments route.
 *
 * Routes by HTTP method. Only POST is supported (create a payment); any other
 * method returns 405. Authentication is resolved inside myapi_payment_create(),
 * not here.
 */
function myapi_payment_create_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_payment_create();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * The 'field_forma_de_pago' machine field whose allowed_values gate the input.
 */
define('MYAPI_PAYMENT_METHOD_FIELD', 'field_forma_de_pago');

/**
 * Payment method (compared case-insensitively) for which the bank is optional.
 *
 * When the submitted payment_method matches this value ignoring case, bank_id
 * becomes optional: a cash payment has no originating bank. Centralized here so
 * the rule can be adjusted in one place.
 */
define('MYAPI_PAYMENT_METHOD_CASH', 'efectivo');

/**
 * Handles POST /api/v1/payments.
 *
 * Creates a 'pagos' node from a multipart/form-data request. Requires a valid
 * Bearer access token; the authenticated user must be the owner or occupant of
 * the target unit. Every field is validated in order, and each check aborts
 * with its own error before anything is created. On success responds 201 with
 * the full payment and a translated message.
 *
 * See the ordered plan in specs/20-register-payment.md, step 4.
 */
function myapi_payment_create() {
  // 1. Authentication: valid Bearer access token, or 401.
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  // 2. Read the multipart/form-data fields (English contract; never touch
  // $_POST directly). Each API field maps to a Drupal field internally.
  $vivienda_raw = myapi_request_post_field('unit_id');
  $referencia   = myapi_request_post_field('reference');
  $valor_raw    = myapi_request_post_field('amount');
  $forma        = myapi_request_post_field('payment_method');
  $banco_raw    = myapi_request_post_field('bank_id');
  $fecha_raw    = myapi_request_post_field('payment_date');

  // 3. Required fields present. unit_id, reference, amount and payment_method
  // are always required; bank_id is required only when the method is not cash.
  $required = [
    'unit_id'        => $vivienda_raw,
    'reference'      => $referencia,
    'amount'         => $valor_raw,
    'payment_method' => $forma,
  ];
  foreach ($required as $name => $value) {
    if ($value === NULL || $value === '') {
      myapi_error('missing_field', 422, ['@field' => $name]);
    }
  }
  $is_cash = strtolower($forma) === MYAPI_PAYMENT_METHOD_CASH;
  if (!$is_cash && ($banco_raw === NULL || $banco_raw === '')) {
    myapi_error('missing_field', 422, ['@field' => 'bank_id']);
  }

  // 4. unit_id: integer > 0; the node must exist, be a published 'vivienda';
  // and the user must own or occupy it.
  if (!ctype_digit((string) $vivienda_raw) || (int) $vivienda_raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'unit_id']);
  }
  $vivienda_nid = (int) $vivienda_raw;
  $unit = node_load($vivienda_nid);
  if (!$unit || $unit->type !== 'vivienda' || (int) $unit->status !== 1) {
    myapi_error('invalid_field', 422, ['@field' => 'unit_id']);
  }
  // Access: same non-strict rule as myapi_payment_list() in this file, since
  // myapi_unit_related_nids() returns nids as strings.
  if (!in_array($vivienda_nid, myapi_unit_related_nids($uid))) {
    myapi_error('unit_access_denied', 403);
  }

  // 5. amount: numeric and strictly > 0.
  if (!is_numeric($valor_raw) || (float) $valor_raw <= 0) {
    myapi_error('invalid_amount', 422);
  }
  $valor = (float) $valor_raw;

  // 6. reference: non-empty string, <= 255 chars.
  if (strlen($referencia) > 255) {
    myapi_error('invalid_field', 422, ['@field' => 'reference']);
  }
  $referencia = check_plain($referencia);

  // 7. payment_method: must be a key of the field's allowed_values.
  $field_info = field_info_field(MYAPI_PAYMENT_METHOD_FIELD);
  $allowed = ($field_info && isset($field_info['settings']['allowed_values']))
    ? $field_info['settings']['allowed_values']
    : [];
  if (!array_key_exists($forma, $allowed)) {
    myapi_error('invalid_payment_method', 422);
  }

  // 8. bank_id: optional for cash, required otherwise (enforced in step 3).
  // When present (even for cash), it must be an integer > 0 and a term of the
  // 'bancos' vocabulary; otherwise the payment is stored with no bank.
  $banco_tid = NULL;
  $term = NULL;
  if ($banco_raw !== NULL && $banco_raw !== '') {
    if (!ctype_digit((string) $banco_raw) || (int) $banco_raw <= 0) {
      myapi_error('invalid_bank', 422);
    }
    $banco_tid = (int) $banco_raw;
    $vocabulary = taxonomy_vocabulary_machine_name_load('bancos');
    $term = taxonomy_term_load($banco_tid);
    if (!$vocabulary || !$term || (int) $term->vid !== (int) $vocabulary->vid) {
      myapi_error('invalid_bank', 422);
    }
  }

  // 9. payment_date: optional; validate/normalize, else server time.
  if ($fecha_raw !== NULL && $fecha_raw !== '') {
    $fecha = myapi_payment_normalize_date($fecha_raw);
    if ($fecha === NULL) {
      myapi_error('invalid_date', 422);
    }
  }
  else {
    $fecha = date('Y-m-d\TH:i:s');
  }

  // 10. Duplicate reference within the same unit -> 409.
  if (myapi_payment_reference_exists($referencia, $vivienda_nid)) {
    myapi_error('duplicate_reference', 409);
  }

  // 11. Optional attached file (validated inside; aborts 422 on failure).
  $file = NULL;
  if (!empty($_FILES['file']['name'])) {
    $file = myapi_payment_save_file();
  }

  // 12. Build and persist the node.
  $node = myapi_payment_build_node($uid, $vivienda_nid, $referencia, $valor, $forma, $banco_tid, $fecha, $file);
  node_save($node);

  // 13. Tie the managed file to the node so cron does not reap it.
  if ($file) {
    file_usage_add($file, 'myapi', 'node', $node->nid);
  }

  // 14. Respond 201 with the full payment (including the bank name).
  myapi_respond(
    ['payment' => myapi_payment_build_created_item($node, $file, $term)],
    201,
    'payment_created'
  );
}

/**
 * Normalizes an accepted payment-date string to 'Y-m-d\TH:i:s'.
 *
 * Accepts 'YYYY-MM-DD' (normalized to T00:00:00) or 'YYYY-MM-DDTHH:MM:SS'. The
 * calendar date is validated with checkdate(); the time part (when present) is
 * range-checked. Returns NULL on any invalid format or impossible date/time.
 *
 * @param string $value  Raw ISO date/datetime from the request.
 *
 * @return string|null  Normalized 'Y-m-d\TH:i:s', or NULL when invalid.
 */
function myapi_payment_normalize_date($value) {
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
      return NULL;
    }
    return $value . 'T00:00:00';
  }
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})$/', $value, $m)) {
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
      return NULL;
    }
    if ((int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
      return NULL;
    }
    return $value;
  }
  return NULL;
}

/**
 * Checks whether a reference already exists for a given unit.
 *
 * Uniqueness is per unit: joins field_data_field_referencia and
 * field_data_field_vivienda on the same 'pagos' node and looks for any row with
 * the same reference and target unit. Best-effort (no DB-level unique index);
 * see the race-condition risk in the spec.
 *
 * @param string $referencia    The reference string to look up.
 * @param int    $vivienda_nid  The target unit nid.
 *
 * @return bool  TRUE when a payment with that reference already exists in the
 *               unit, FALSE otherwise.
 */
function myapi_payment_reference_exists($referencia, $vivienda_nid) {
  $query = db_select('field_data_field_referencia', 'fref')
    ->fields('fref', ['entity_id'])
    ->condition('fref.entity_type', 'node')
    ->condition('fref.deleted', 0)
    ->condition('fref.field_referencia_value', $referencia);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = fref.entity_id AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $vivienda_nid);

  $query->innerJoin('node', 'n', 'n.nid = fref.entity_id');
  $query->condition('n.type', 'pagos');

  return (bool) $query->range(0, 1)->execute()->fetchField();
}

/**
 * Saves and validates the optional 'field_archivo' upload.
 *
 * The caller invokes this only when $_FILES['file'] carries a file.
 * Validates extension (pdf/jpg/jpeg/png) and size (<= 5 MB) with Drupal
 * validators, then re-checks the real MIME with finfo so a renamed file (e.g. a
 * .php disguised as .pdf) is rejected. On any failure it aborts the request
 * with a 422 (and deletes the just-saved file on a MIME mismatch); on success
 * it marks the file permanent and returns it.
 *
 * @return object  The saved permanent managed file object.
 */
function myapi_payment_save_file() {
  $directory = 'private://comprobantes_pago';
  if (!file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
    // Private filesystem not configured or not writable: cannot store the
    // sensitive receipt, so abort before creating anything.
    myapi_error('invalid_file', 422);
  }

  // Drupal 7's file_save_upload() reads uploads from the nested
  // $_FILES['files']['name'][<field>] structure that its own form API produces,
  // not from $_FILES['file'] where PHP puts a plain multipart field named
  // 'file'. Remap our flat upload into that structure so file_save_upload('file')
  // can find it (Flutter's MultipartRequest and curl -F both send it flat).
  if (isset($_FILES['file']) && !isset($_FILES['files']['name']['file'])) {
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
      $_FILES['files'][$key]['file'] = $_FILES['file'][$key];
    }
  }

  $validators = [
    'file_validate_extensions' => ['pdf jpg jpeg png'],
    'file_validate_size'       => [5 * 1024 * 1024],
  ];

  $file = file_save_upload('file', $validators, $directory . '/');
  if (!is_object($file)) {
    // FALSE on validation error (extension/size) or a failed upload.
    myapi_error('invalid_file', 422);
  }

  // Real MIME check: the stored extension is not enough on an EOL platform.
  $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];
  $real_path = drupal_realpath($file->uri);
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = $finfo ? finfo_file($finfo, $real_path) : FALSE;
  if ($finfo) {
    finfo_close($finfo);
  }
  if (!in_array($mime, $allowed_mimes, TRUE)) {
    file_delete($file);
    myapi_error('invalid_file_type', 422);
  }

  $file->status = FILE_STATUS_PERMANENT;
  return file_save($file);
}

/**
 * Builds an unsaved 'pagos' node from the validated payment input.
 *
 * All values are already validated by the caller. field_estado_pago is always
 * forced to 'Pendiente de verificar' regardless of the request, and the title
 * is autogenerated as "Pago {referencia} - {YYYY-MM-DD}". The node is returned
 * unsaved so the caller controls node_save() ordering relative to
 * file_usage_add().
 *
 * @param int         $uid           Author (authenticated user) id.
 * @param int         $vivienda_nid  Target unit nid.
 * @param string      $referencia    Sanitized reference.
 * @param float       $valor         Amount (> 0).
 * @param string      $forma         Payment method (allowed_values key).
 * @param int|null    $banco_tid     Bank term id (vocabulary 'bancos'), or NULL
 *                                   when none (e.g. a cash payment).
 * @param string      $fecha         Normalized 'Y-m-d\TH:i:s' payment date.
 * @param object|null $file          Saved managed file, or NULL when none.
 *
 * @return object  The prepared, unsaved node object.
 */
function myapi_payment_build_node($uid, $vivienda_nid, $referencia, $valor, $forma, $banco_tid, $fecha, $file) {
  $node = new stdClass();
  $node->type = 'pagos';
  node_object_prepare($node);
  $node->uid = $uid;
  $node->status = 1;
  $node->language = LANGUAGE_NONE;
  $node->title = 'Pago ' . $referencia . ' - ' . substr($fecha, 0, 10);

  $node->field_vivienda[LANGUAGE_NONE][0]['target_id']  = $vivienda_nid;
  $node->field_referencia[LANGUAGE_NONE][0]['value']    = $referencia;
  $node->field_valor[LANGUAGE_NONE][0]['value']         = $valor;
  $node->field_forma_de_pago[LANGUAGE_NONE][0]['value'] = $forma;
  $node->field_fecha_de_pago[LANGUAGE_NONE][0]['value'] = $fecha;
  $node->field_estado_pago[LANGUAGE_NONE][0]['value']   = 'Pendiente de verificar';

  if ($banco_tid !== NULL) {
    $node->field_banco[LANGUAGE_NONE][0]['tid'] = $banco_tid;
  }

  if ($file) {
    $node->field_archivo[LANGUAGE_NONE][0] = ['fid' => $file->fid, 'display' => 1];
  }

  return $node;
}

/**
 * Maps the created 'pagos' node into the 201 response shape.
 *
 * Reads the values straight from the node built by myapi_payment_build_node(),
 * so it reflects exactly what was persisted (including the forced estado). Both
 * file_id and file_name are NULL when no attachment was provided. file_name is
 * the stored filename (basename of the private:// URI, including any rename
 * suffix Drupal added on collision); the fid is exposed for a future
 * authenticated download endpoint, per the spec.
 *
 * bank_id and bank_name are NULL together when the payment has no bank (e.g. a
 * cash payment); bank_name is the term name, sanitized with check_plain().
 *
 * @param object      $node       The saved payment node.
 * @param object|null $file       The saved managed file, or NULL when none.
 * @param object|null $bank_term  The 'bancos' term, or NULL when no bank.
 *
 * @return array  Response item with all documented keys.
 */
function myapi_payment_build_created_item($node, $file, $bank_term = NULL) {
  $has_bank = isset($node->field_banco[LANGUAGE_NONE][0]['tid']);

  return [
    'id'             => (int) $node->nid,
    'title'          => $node->title,
    'unit_id'        => (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id'],
    'payment_date'   => $node->field_fecha_de_pago[LANGUAGE_NONE][0]['value'],
    'status'         => $node->field_estado_pago[LANGUAGE_NONE][0]['value'],
    'payment_method' => $node->field_forma_de_pago[LANGUAGE_NONE][0]['value'],
    'reference'      => $node->field_referencia[LANGUAGE_NONE][0]['value'],
    'amount'         => (float) $node->field_valor[LANGUAGE_NONE][0]['value'],
    'bank_id'        => $has_bank ? (int) $node->field_banco[LANGUAGE_NONE][0]['tid'] : NULL,
    'bank_name'      => $bank_term ? check_plain($bank_term->name) : NULL,
    'file_id'        => $file ? (int) $file->fid : NULL,
    'file_name'      => $file ? drupal_basename($file->uri) : NULL,
  ];
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

  $query->leftJoin('field_data_field_detalle', 'fdet', "fdet.entity_id = n.nid AND fdet.entity_type = 'node' AND fdet.deleted = 0");
  $query->addField('fdet', 'field_detalle_value', 'detail');

  $query->leftJoin('field_data_field_archivo', 'farc', "farc.entity_id = n.nid AND farc.entity_type = 'node' AND farc.deleted = 0");
  $query->addField('farc', 'field_archivo_fid', 'file_id');

  $query->leftJoin('field_data_field_banco', 'fban', "fban.entity_id = n.nid AND fban.entity_type = 'node' AND fban.deleted = 0");
  $query->addField('fban', 'field_banco_tid', 'bank_id');
  $query->leftJoin('taxonomy_term_data', 'tban', 'tban.tid = fban.field_banco_tid');
  $query->addField('tban', 'name', 'bank_name');

  $query->orderBy('ffec.field_fecha_de_pago_value', $sort === 'asc' ? 'ASC' : 'DESC');
  // Deterministic tie-breaker: payments sharing the same payment_date would
  // otherwise come back in an unstable order across requests (and could shift
  // between pages), because SQL does not guarantee row order for ties. nid is
  // unique, never NULL and immutable, so it makes the ordering reproducible.
  $query->orderBy('n.nid', $sort === 'asc' ? 'ASC' : 'DESC');
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }

  return $query->execute()->fetchAll();
}

/**
 * Maps one raw payment row into the response shape documented for
 * GET /api/v1/units/%/payments.
 *
 * 'amount' (decimal) is cast to float when present, NULL otherwise. 'file_id'
 * and 'bank_id' are cast to int, each only when not NULL. Text fields
 * (payment_date, status, payment_method, reference, detail, bank_name) pass
 * through as stored, with no business interpretation, and stay NULL when the
 * node has no row for that field. 'bank_id' and 'bank_name' are NULL together
 * when the payment has no bank (e.g. a cash payment).
 *
 * @param object $payment  One row from myapi_payment_fetch().
 *
 * @return array  Response item with all 11 mapped keys.
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
    'detail'         => $payment->detail,
    'file_id'        => $payment->file_id !== NULL ? (int) $payment->file_id : NULL,
    'bank_id'        => $payment->bank_id !== NULL ? (int) $payment->bank_id : NULL,
    'bank_name'      => $payment->bank_name,
  ];
}
