<?php

/**
 * @file
 * Shared payment-write helpers for the myapi module (SPEC 130).
 *
 * Everything needed to turn validated payment input into a stored 'pagos'
 * node: date normalization, the per-unit duplicate-reference lookup, the
 * receipt upload, the node builder and the mapper that shapes the created
 * payment for a response.
 *
 * These five functions and MYAPI_PAYMENT_METHOD_CASH lived in
 * resources/payment.resource.inc until a second door started creating the same
 * node: POST /api/v1/bot/payments, the WhatsApp bot's endpoint. A payment
 * written by the app and one written by the bot are THE SAME NODE, so the code
 * that builds it is shared logic, not one resource's logic — rule 3 of
 * CLAUDE.md sends it here, and rule 5 forbids the bot resource from reaching
 * into the payment resource for it.
 *
 * The move was mechanical: not a line of any body changed, and no signature
 * changed. What the app does with these helpers is exactly what it did before;
 * what the bot adds on top of the node it builds lives in its own resource, in
 * myapi_bot_payment_decorate_node(), so nothing bot-specific leaks into the
 * path every resident's payment travels.
 */

module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');

/**
 * Payment method (compared case-insensitively) for which the bank is optional.
 *
 * When the submitted payment_method matches this value ignoring case, bank_id
 * becomes optional: a cash payment has no originating bank. Centralized here so
 * the rule can be adjusted in one place.
 */
define('MYAPI_PAYMENT_METHOD_CASH', 'efectivo');

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
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
      return NULL;
    }
    return $value . 'T00:00:00';
  }
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})$/D', $value, $m)) {
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
 * field_canal is forced to 'app' for the same reason and with the same
 * strictness (SPEC 129): the channel is decided by the door the request came
 * in through and never by the caller. The endpoint accepts no 'channel' input —
 * a channel the client could pick would not be traceability, it would be a
 * statement of intent. The field is not exposed in any response.
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
  $node->field_canal[LANGUAGE_NONE][0]['value']         = MYAPI_PAYMENT_CHANNEL_APP;

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
    // GUARDED LIKE EVERY OTHER NULLABLE KEY OF THIS MAPPER (SPEC 122). It was
    // the one read here without an isset(), so a 'pagos' node with no estado
    // row — which the detail endpoint answers, unlike the listing — emitted an
    // undefined-property notice on its way to the same null.
    'status'         => isset($node->field_estado_pago[LANGUAGE_NONE][0]['value'])
      ? $node->field_estado_pago[LANGUAGE_NONE][0]['value']
      : NULL,
    'payment_method' => $node->field_forma_de_pago[LANGUAGE_NONE][0]['value'],
    'reference'      => $node->field_referencia[LANGUAGE_NONE][0]['value'],
    'amount'         => (float) $node->field_valor[LANGUAGE_NONE][0]['value'],
    'bank_id'        => $has_bank ? (int) $node->field_banco[LANGUAGE_NONE][0]['tid'] : NULL,
    'bank_name'      => $bank_term ? check_plain($bank_term->name) : NULL,
    'file_id'        => $file ? (int) $file->fid : NULL,
    'file_name'      => $file ? drupal_basename($file->uri) : NULL,
    'detail'         => isset($node->field_detalle[LANGUAGE_NONE][0]['value'])
      ? $node->field_detalle[LANGUAGE_NONE][0]['value']
      : NULL,
  ];
}
