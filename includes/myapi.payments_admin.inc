<?php

/**
 * @file
 * Back-office payments report (SPEC 132).
 *
 * Serves admin/content/payments and admin/content/payments/csv: a read-only,
 * server-rendered, paginated table of 'pagos' nodes with GET filters, a total
 * row, and the same filtered set as a CSV download. It is NOT an API endpoint
 * — nothing here returns the JSON envelope, and no resource under resources/
 * calls into it. Same placement as includes/myapi.service_requests_admin.inc
 * and for the same reason: resources/ is for what the Flutter app consumes.
 *
 * The file is loaded by the 'file' key of its two hook_menu() entries in
 * myapi.module, so it only reaches PHP on those routes.
 *
 * ------------------------------------------------------------------------
 * ONE QUERY FOR THE TABLE, THE TOTAL AND THE CSV
 * ------------------------------------------------------------------------
 *
 * myapi_payments_admin_build_query() is the only place the filters become SQL
 * (decision 12). The table pages it, the total sums it and the CSV reads it in
 * batches; two parallel queries would end up answering different sets, and
 * nobody would trust the file. For the same reason the screen and the CSV read
 * every cell through the same raw helpers (_unit_name(), _person_name(),
 * _option_label()) and differ only in how they present the answer.
 *
 * The query carries ->addTag('node_access') and stays next to the page, as
 * SPEC 125 decided for its own listing (decision 7 there).
 *
 * ------------------------------------------------------------------------
 * WHAT IS REUSED
 * ------------------------------------------------------------------------
 *
 * - myapi_calendar_condominium_options(), _positive_int() and
 *   _filter_form_after_build() (includes/myapi.reservation_calendar.inc), as
 *   every back-office listing since SPEC 47.
 * - myapi_reservation_valid_date() for the 'Y-m-d' + checkdate() rule.
 * - myapi_claims_field_allowed_values() for the two list_text catalogues.
 * - myapi_payment_method_label(), _channel_label() and _date_label()
 *   (includes/myapi.payment_workflow.inc) for the screen cells. They answer
 *   HTML-escaped text, so the CSV reads the raw catalogue label instead.
 */

module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');
module_load_include('inc', 'myapi', 'includes/myapi.claims_admin');
module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');

/**
 * The 'pagos' bundle machine name.
 */
define('MYAPI_PAYMENTS_ADMIN_TYPE', 'pagos');

/**
 * Longest accepted value of a free-text filter, in characters.
 */
define('MYAPI_PAYMENTS_ADMIN_TEXT_MAX', 100);

/**
 * Days covered by the default date range (decision 6).
 */
define('MYAPI_PAYMENTS_ADMIN_DEFAULT_DAYS', 30);

/* ---------------------------------------------------------------------------
 * Catalogues.
 * ------------------------------------------------------------------------ */

/**
 * The status select options: field_estado_pago's own allowed_values.
 *
 * @return array
 *   Stored value => label.
 */
function myapi_payments_admin_status_options() {
  return myapi_claims_field_allowed_values('field_estado_pago');
}

/**
 * The payment method select options: field_forma_de_pago's allowed_values.
 *
 * @return array
 *   Stored value => label.
 */
function myapi_payments_admin_method_options() {
  return myapi_claims_field_allowed_values(MYAPI_PAYMENT_METHOD_FIELD);
}

/**
 * The channel select options: the three constants of SPEC 129.
 *
 * Keyed by the CONSTANTS, not by the field, because the filter validates
 * against them; the field only lends its labels. A site missing field_canal
 * still offers the three channels, under their raw keys. All three are offered
 * (decision 5): without 'backoffice' the migrated history cannot be isolated.
 *
 * @return array
 *   Channel key => label, in the order app, bot, backoffice.
 */
function myapi_payments_admin_channel_options() {
  $labels = myapi_claims_field_allowed_values(MYAPI_PAYMENT_CHANNEL_FIELD);

  $options = array();
  foreach (array(MYAPI_PAYMENT_CHANNEL_APP, MYAPI_PAYMENT_CHANNEL_BOT, MYAPI_PAYMENT_CHANNEL_BACKOFFICE) as $key) {
    $options[$key] = isset($labels[$key]) ? $labels[$key] : $key;
  }

  return $options;
}

/**
 * The raw label of a catalogue key, for the CSV.
 *
 * Unlike the screen labels, this answers UNESCAPED text: a CSV cell is data.
 * An unknown key prints raw, the same criterion as the screen.
 *
 * @param array $options
 *   Stored value => label.
 * @param string|null $key
 *
 * @return string|null
 *   The label, the raw key, or NULL when there is no key.
 */
function myapi_payments_admin_option_label(array $options, $key) {
  if ($key === NULL || $key === '') {
    return NULL;
  }

  return isset($options[$key]) ? (string) $options[$key] : (string) $key;
}

/* ---------------------------------------------------------------------------
 * Filters.
 *
 * NOTHING HERE EVER FAILS, same as every back-office listing: a malformed
 * value is "no filter", never an error page.
 * ------------------------------------------------------------------------ */

/**
 * Validates one free-text filter: trimmed, 1–100 characters.
 *
 * Counted in characters, not bytes, so an 'ñ' costs one.
 *
 * @param mixed $value
 *   Raw query-string value.
 *
 * @return string|null
 *   The trimmed text, or NULL for no filter.
 */
function myapi_payments_admin_text_filter($value) {
  if (!is_scalar($value)) {
    return NULL;
  }

  $value = trim((string) $value);
  $length = drupal_strlen($value);

  return ($length >= 1 && $length <= MYAPI_PAYMENTS_ADMIN_TEXT_MAX) ? $value : NULL;
}

/**
 * The default date range: the last 30 days, today included.
 *
 * Pure: it receives the request time instead of reading it, so the test can
 * fix it. "Today" is the site's calendar day (format_date()); the 30 days are
 * calendar days, counted on that date, so no DST or timezone shift can move
 * the lower bound.
 *
 * @param int $now
 *   REQUEST_TIME.
 *
 * @return array
 *   ['date_from' => 'Y-m-d', 'date_to' => 'Y-m-d'].
 */
function myapi_payments_admin_default_range($now) {
  $today = format_date($now, 'custom', 'Y-m-d');
  $from = new DateTime($today);
  $from->modify('-' . MYAPI_PAYMENTS_ADMIN_DEFAULT_DAYS . ' days');

  return array(
    'date_from' => $from->format('Y-m-d'),
    'date_to'   => $today,
  );
}

/**
 * Reads and validates every GET filter of the report.
 *
 * The condominium is validated as a POSITIVE INTEGER only; whether it is one
 * of the offered condominiums is decided by
 * myapi_payments_admin_effective_condominium() against the options the page
 * loads for the select.
 *
 * ABSENT IS NOT EMPTY (decision 6). When the URL names neither date_from nor
 * date_to, the default range applies; when it names them, each is validated
 * on its own and an empty one means "no limit on that side". That is how an
 * operator removes the limit: clear both boxes and apply.
 *
 * @param int $now
 *   REQUEST_TIME, for the default range.
 *
 * @return array
 *   Keys: condominium, status, unit_name, owner_last_name,
 *   occupant_last_name, payment_method, channel, date_from, date_to. Each is
 *   the validated value or NULL for no filter.
 */
function myapi_payments_admin_list_filters($now) {
  module_load_include('inc', 'myapi', 'resources/reservation.resource');

  $filters = array(
    'condominium'        => isset($_GET['condominium']) ? myapi_calendar_positive_int($_GET['condominium']) : NULL,
    'status'             => NULL,
    'unit_name'          => NULL,
    'owner_last_name'    => NULL,
    'occupant_last_name' => NULL,
    'payment_method'     => NULL,
    'channel'            => NULL,
    'date_from'          => NULL,
    'date_to'            => NULL,
  );

  if (isset($_GET['status']) && is_scalar($_GET['status'])) {
    $statuses = myapi_payments_admin_status_options();
    if (isset($statuses[(string) $_GET['status']])) {
      $filters['status'] = (string) $_GET['status'];
    }
  }

  if (isset($_GET['payment_method']) && is_scalar($_GET['payment_method'])) {
    $methods = myapi_payments_admin_method_options();
    if (isset($methods[(string) $_GET['payment_method']])) {
      $filters['payment_method'] = (string) $_GET['payment_method'];
    }
  }

  if (isset($_GET['channel']) && is_scalar($_GET['channel'])) {
    $channels = array(MYAPI_PAYMENT_CHANNEL_APP, MYAPI_PAYMENT_CHANNEL_BOT, MYAPI_PAYMENT_CHANNEL_BACKOFFICE);
    if (in_array((string) $_GET['channel'], $channels, TRUE)) {
      $filters['channel'] = (string) $_GET['channel'];
    }
  }

  foreach (array('unit_name', 'owner_last_name', 'occupant_last_name') as $key) {
    if (isset($_GET[$key])) {
      $filters[$key] = myapi_payments_admin_text_filter($_GET[$key]);
    }
  }

  if (!array_key_exists('date_from', $_GET) && !array_key_exists('date_to', $_GET)) {
    return myapi_payments_admin_default_range($now) + $filters;
  }

  foreach (array('date_from', 'date_to') as $key) {
    if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
      $filters[$key] = myapi_reservation_valid_date((string) $_GET[$key]);
    }
  }

  return $filters;
}

/**
 * Keeps the requested condominium only when it is one of the offered ones.
 *
 * A hand-typed nid of a deleted condominium filters nothing instead of
 * emptying the report with no explanation.
 *
 * @param int|null $requested
 * @param array $options
 *   nid => title, from myapi_calendar_condominium_options().
 *
 * @return int|null
 */
function myapi_payments_admin_effective_condominium($requested, array $options) {
  if ($requested === NULL) {
    return NULL;
  }

  return isset($options[$requested]) ? (int) $requested : NULL;
}

/* ---------------------------------------------------------------------------
 * The query.
 * ------------------------------------------------------------------------ */

/**
 * The filtered payments query: joins and WHERE, no projection, no order.
 *
 * THE ONE PLACE THE FILTERS BECOME SQL (decision 12). The table, the total and
 * the CSV each start here and add only what is theirs: the projection and the
 * order (myapi_payments_admin_add_fields() / _add_order()), the pager, the
 * SUM, the batch range. It projects nothing on purpose: an aggregate next to
 * plain columns, or an ORDER BY a SUM cannot use, is an error under MySQL's
 * ONLY_FULL_GROUP_BY.
 *
 * ->addTag('node_access') is kept even though only 'administrator' and
 * 'backend' reach this page: it costs them nothing (both alters return at
 * their first guard) and it is what would scope the rows if the page ever
 * opened to the 'administrador edificio' (out of scope, decision 3).
 *
 * n.status is NOT filtered and every field_estado_pago is listed, 'Nuevo'
 * included (decision 4): a payment's lifecycle lives in that field, and a
 * back-office report that hides payments hides exactly what is being searched.
 *
 * EVERY JOIN IS LEFT: a payment whose unit, owner or bank was deleted keeps
 * its row with a dash in that cell. Everything about the people and the
 * condominium is read THROUGH the unit, so a payment whose unit is gone shows
 * dashes there and does not vanish.
 *
 * No join can multiply rows: every field joined has cardinality 1 — checked
 * on the live site for owner and occupant in step 0 of SPEC 132 — and node.nid,
 * users.uid and taxonomy_term_data.tid are primary keys. Neither the unit nor
 * the condominium join filters on the published flag: an unpublished one still
 * names the payment it holds.
 *
 * @param array $filters
 *   From myapi_payments_admin_list_filters(), with the condominium already
 *   narrowed by myapi_payments_admin_effective_condominium().
 *
 * @return SelectQuery
 */
function myapi_payments_admin_build_query(array $filters) {
  $query = db_select('node', 'n')
    ->condition('n.type', MYAPI_PAYMENTS_ADMIN_TYPE);
  $query->addTag('node_access');

  // The unit, its name and, through it, the condominium and the two people.
  $query->leftJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->leftJoin('node', 'nv', "nv.nid = fv.field_vivienda_target_id AND nv.type = 'vivienda'");
  $query->leftJoin('field_data_field_nombre_vivienda', 'fnv', "fnv.entity_id = nv.nid AND fnv.entity_type = 'node' AND fnv.deleted = 0");
  $query->leftJoin('field_data_field_condominio', 'fco', "fco.entity_id = nv.nid AND fco.entity_type = 'node' AND fco.deleted = 0");
  $query->leftJoin('node', 'nc', "nc.nid = fco.field_condominio_target_id AND nc.type = 'condominio'");

  $query->leftJoin('field_data_field_propietario', 'fpo', "fpo.entity_id = nv.nid AND fpo.entity_type = 'node' AND fpo.deleted = 0");
  $query->leftJoin('users', 'uo', 'uo.uid = fpo.field_propietario_target_id');
  $query->leftJoin('field_data_field_nombre', 'uon', "uon.entity_id = uo.uid AND uon.entity_type = 'user' AND uon.deleted = 0");
  $query->leftJoin('field_data_field_apellidos', 'uoa', "uoa.entity_id = uo.uid AND uoa.entity_type = 'user' AND uoa.deleted = 0");
  $query->leftJoin('field_data_field_cedula', 'uoc', "uoc.entity_id = uo.uid AND uoc.entity_type = 'user' AND uoc.deleted = 0");

  $query->leftJoin('field_data_field_ocupante', 'foc', "foc.entity_id = nv.nid AND foc.entity_type = 'node' AND foc.deleted = 0");
  $query->leftJoin('users', 'uc', 'uc.uid = foc.field_ocupante_target_id');
  $query->leftJoin('field_data_field_nombre', 'ucn', "ucn.entity_id = uc.uid AND ucn.entity_type = 'user' AND ucn.deleted = 0");
  $query->leftJoin('field_data_field_apellidos', 'uca', "uca.entity_id = uc.uid AND uca.entity_type = 'user' AND uca.deleted = 0");
  $query->leftJoin('field_data_field_cedula', 'ucc', "ucc.entity_id = uc.uid AND ucc.entity_type = 'user' AND ucc.deleted = 0");

  // The payment's own fields.
  $query->leftJoin('field_data_field_fecha_de_pago', 'ffp', "ffp.entity_id = n.nid AND ffp.entity_type = 'node' AND ffp.deleted = 0");
  $query->leftJoin('field_data_field_forma_de_pago', 'ffo', "ffo.entity_id = n.nid AND ffo.entity_type = 'node' AND ffo.deleted = 0");
  $query->leftJoin('field_data_field_banco', 'fba', "fba.entity_id = n.nid AND fba.entity_type = 'node' AND fba.deleted = 0");
  $query->leftJoin('taxonomy_term_data', 'tb', 'tb.tid = fba.field_banco_tid');
  $query->leftJoin('field_data_field_referencia', 'fre', "fre.entity_id = n.nid AND fre.entity_type = 'node' AND fre.deleted = 0");
  $query->leftJoin('field_data_field_valor', 'fva', "fva.entity_id = n.nid AND fva.entity_type = 'node' AND fva.deleted = 0");
  $query->leftJoin('field_data_field_estado_pago', 'fes', "fes.entity_id = n.nid AND fes.entity_type = 'node' AND fes.deleted = 0");
  $query->leftJoin('field_data_field_canal', 'fca', "fca.entity_id = n.nid AND fca.entity_type = 'node' AND fca.deleted = 0");
  $query->leftJoin('field_data_field_banco_emisor', 'fbe', "fbe.entity_id = n.nid AND fbe.entity_type = 'node' AND fbe.deleted = 0");
  $query->leftJoin('field_data_field_banco_destino', 'fbd', "fbd.entity_id = n.nid AND fbd.entity_type = 'node' AND fbd.deleted = 0");

  // Optional filters, ANDed. A filter on a left-joined column also excludes
  // the payments with no value in it — the desired reading of "only these".
  if ($filters['condominium'] !== NULL) {
    $query->condition('fco.field_condominio_target_id', $filters['condominium']);
  }
  if ($filters['status'] !== NULL) {
    $query->condition('fes.field_estado_pago_value', $filters['status']);
  }
  if ($filters['payment_method'] !== NULL) {
    $query->condition('ffo.field_forma_de_pago_value', $filters['payment_method']);
  }
  if ($filters['channel'] !== NULL) {
    $query->condition('fca.field_canal_value', $filters['channel']);
  }
  // The operator does not know whether the name on screen is the title or
  // field_nombre_vivienda, so both are searched (decision 7). Case and accents
  // are the column collation's business, as in every LIKE of the module.
  if ($filters['unit_name'] !== NULL) {
    $needle = '%' . db_like($filters['unit_name']) . '%';
    $query->condition(db_or()
      ->condition('nv.title', $needle, 'LIKE')
      ->condition('fnv.field_nombre_vivienda_value', $needle, 'LIKE'));
  }
  if ($filters['owner_last_name'] !== NULL) {
    $query->condition('uoa.field_apellidos_value', '%' . db_like($filters['owner_last_name']) . '%', 'LIKE');
  }
  if ($filters['occupant_last_name'] !== NULL) {
    $query->condition('uca.field_apellidos_value', '%' . db_like($filters['occupant_last_name']) . '%', 'LIKE');
  }
  // field_fecha_de_pago is a 'date' field: an ISO string with a 'T'
  // ('2026-10-14T00:00:00'). Comparing its first ten characters against the
  // two days is what makes each bound cover its whole day (00:00:00 to
  // 23:59:59), and it is the comparison myapi_payment_count() already makes.
  if ($filters['date_from'] !== NULL) {
    $query->where('SUBSTR(ffp.field_fecha_de_pago_value, 1, 10) >= :payments_date_from', array(':payments_date_from' => $filters['date_from']));
  }
  if ($filters['date_to'] !== NULL) {
    $query->where('SUBSTR(ffp.field_fecha_de_pago_value, 1, 10) <= :payments_date_to', array(':payments_date_to' => $filters['date_to']));
  }

  return $query;
}

/**
 * Projects the columns the table and the CSV read, under the names the row
 * helpers expect.
 *
 * Every person and the unit come twice, raw and resolved: the raw target_id
 * is what tells "no unit" from "a unit that was deleted".
 *
 * @param SelectQuery $query
 *   From myapi_payments_admin_build_query().
 */
function myapi_payments_admin_add_fields($query) {
  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'created', 'created');

  $query->addField('fv', 'field_vivienda_target_id', 'unit_raw');
  $query->addField('nv', 'nid', 'unit_id');
  $query->addField('nv', 'title', 'unit_title');
  $query->addField('fnv', 'field_nombre_vivienda_value', 'unit_name');
  $query->addField('nc', 'title', 'condominium_title');

  $query->addField('fpo', 'field_propietario_target_id', 'owner_raw');
  $query->addField('uo', 'name', 'owner_name');
  $query->addField('uon', 'field_nombre_value', 'owner_first_name');
  $query->addField('uoa', 'field_apellidos_value', 'owner_last_name');
  $query->addField('uoc', 'field_cedula_value', 'owner_cedula');

  $query->addField('foc', 'field_ocupante_target_id', 'occupant_raw');
  $query->addField('uc', 'name', 'occupant_name');
  $query->addField('ucn', 'field_nombre_value', 'occupant_first_name');
  $query->addField('uca', 'field_apellidos_value', 'occupant_last_name');
  $query->addField('ucc', 'field_cedula_value', 'occupant_cedula');

  $query->addField('ffp', 'field_fecha_de_pago_value', 'payment_date');
  $query->addField('ffo', 'field_forma_de_pago_value', 'payment_method');
  $query->addField('tb', 'name', 'bank_name');
  $query->addField('fre', 'field_referencia_value', 'reference');
  $query->addField('fes', 'field_estado_pago_value', 'status');
  $query->addField('fva', 'field_valor_value', 'amount');
  $query->addField('fca', 'field_canal_value', 'channel');
  $query->addField('fbe', 'field_banco_emisor_value', 'issuer_bank');
  $query->addField('fbd', 'field_banco_destino_value', 'destination_bank');
}

/**
 * The report's order: the latest payment date first, the higher nid on a tie.
 *
 * The nid tiebreak is what makes the order TOTAL, and a total order is what
 * lets the CSV read the set in ranged batches without skipping or repeating a
 * row between two of them.
 *
 * @param SelectQuery $query
 */
function myapi_payments_admin_add_order($query) {
  $query->orderBy('ffp.field_fecha_de_pago_value', 'DESC');
  $query->orderBy('n.nid', 'DESC');
}

/**
 * One page of the report: 20 rows, through Drupal's pager.
 *
 * @param array $filters
 *
 * @return array
 *   Row objects.
 */
function myapi_payments_admin_fetch_page(array $filters) {
  $query = myapi_payments_admin_build_query($filters);
  myapi_payments_admin_add_fields($query);
  myapi_payments_admin_add_order($query);

  return $query->extend('PagerDefault')->limit(20)->execute()->fetchAll();
}

/**
 * The total row's numbers over EVERY filtered payment, not only the page.
 *
 * With no status filter the sum mixes 'Anulado' with the rest (decision 11):
 * the total reflects the filter, with no hidden rule.
 *
 * @param array $filters
 *
 * @return array
 *   ['total' => string|float|NULL, 'count' => int]. The total is NULL when no
 *   payment matched, as SQL's SUM answers.
 */
function myapi_payments_admin_total(array $filters) {
  $query = myapi_payments_admin_build_query($filters);
  $query->addExpression('SUM(fva.field_valor_value)', 'total');
  $query->addExpression('COUNT(n.nid)', 'payments');

  $row = $query->execute()->fetchObject();

  return array(
    'total' => $row ? $row->total : NULL,
    'count' => $row ? (int) $row->payments : 0,
  );
}

/**
 * One batch of the CSV: the same ordered query, ranged.
 *
 * @param array $filters
 * @param int $offset
 * @param int $length
 *
 * @return array
 *   Row objects; fewer than $length means this was the last batch.
 */
function myapi_payments_admin_fetch_batch(array $filters, $offset, $length) {
  $query = myapi_payments_admin_build_query($filters);
  myapi_payments_admin_add_fields($query);
  myapi_payments_admin_add_order($query);
  $query->range($offset, $length);

  return $query->execute()->fetchAll();
}

/* ---------------------------------------------------------------------------
 * Raw cell values, shared by the screen and the CSV.
 * ------------------------------------------------------------------------ */

/**
 * The unit's name: field_nombre_vivienda first, the title otherwise.
 *
 * A payment whose unit was deleted keeps the raw target_id, and says so
 * instead of vanishing (SPEC 132, "Columnas en pantalla").
 *
 * @param object $row
 *   Row from myapi_payments_admin_build_query().
 *
 * @return string|null
 *   UNESCAPED text, or NULL when the payment has no unit at all.
 */
function myapi_payments_admin_unit_name($row) {
  if ($row->unit_raw === NULL) {
    return NULL;
  }
  if ($row->unit_id === NULL) {
    return t('Vivienda eliminada (#@nid)', array('@nid' => $row->unit_raw));
  }
  if ($row->unit_name !== NULL && $row->unit_name !== '') {
    return $row->unit_name;
  }

  return $row->unit_title;
}

/**
 * The owner's or occupant's name.
 *
 * Three shapes: no reference (NULL), a deleted account ('Usuario eliminado
 * (#uid)'), or the person — 'nombre apellidos' when the profile has any of
 * the two, the account name otherwise.
 *
 * @param object $row
 *   Row from myapi_payments_admin_build_query().
 * @param string $prefix
 *   'owner' or 'occupant'.
 *
 * @return string|null
 *   UNESCAPED text, or NULL.
 */
function myapi_payments_admin_person_name($row, $prefix) {
  $raw = $row->{$prefix . '_raw'};
  if ($raw === NULL) {
    return NULL;
  }

  $name = $row->{$prefix . '_name'};
  if ($name === NULL) {
    return t('Usuario eliminado (#@uid)', array('@uid' => $raw));
  }

  $full_name = trim($row->{$prefix . '_first_name'} . ' ' . $row->{$prefix . '_last_name'});

  return $full_name !== '' ? $full_name : $name;
}

/* ---------------------------------------------------------------------------
 * Screen labels. Every one answers ALREADY ESCAPED text and falls back to an
 * em dash instead of an empty cell.
 * ------------------------------------------------------------------------ */

/**
 * Human label for a free-text cell.
 *
 * @param string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_payments_admin_text_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  return check_plain($value);
}

/**
 * Human label for the status. An unknown key prints raw.
 *
 * @param string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_payments_admin_status_label($value) {
  return myapi_payments_admin_text_label(myapi_payments_admin_option_label(myapi_payments_admin_status_options(), $value));
}

/**
 * The unit cell: linked to the unit, plain text when it was deleted.
 *
 * @param object $row
 *
 * @return string
 *   Already escaped; l() escapes the link text.
 */
function myapi_payments_admin_unit_label($row) {
  $name = myapi_payments_admin_unit_name($row);

  if ($name === NULL) {
    return '—';
  }
  if ($row->unit_id === NULL) {
    return check_plain($name);
  }

  return l($name, 'node/' . (int) $row->unit_id);
}

/**
 * Human label for an amount: '$1.234,56'.
 *
 * @param string|float|null $value
 *
 * @return string
 */
function myapi_payments_admin_money_label($value) {
  if ($value === NULL || $value === '' || !is_numeric($value)) {
    return '—';
  }

  return '$' . number_format((float) $value, 2, ',', '.');
}

/**
 * The total row: the sum of every filtered payment, and how many there are.
 *
 * An empty result is a total of zero — "nothing" is an answer here, not a
 * missing value.
 *
 * @param string|float|null $sum
 *   SUM(field_valor) of the filtered set; NULL when the set is empty.
 * @param int $count
 *
 * @return string
 */
function myapi_payments_admin_total_label($sum, $count) {
  $count = (int) $count;
  $payments = $count === 1 ? t('1 pago') : t('@count pagos', array('@count' => $count));

  return t('Total: @total (@payments)', array(
    '@total'    => myapi_payments_admin_money_label($sum === NULL ? 0 : $sum),
    '@payments' => $payments,
  ));
}

/**
 * The ID cell: node/%nid/edit, or node/%nid for a reader who cannot edit.
 *
 * @param object $row
 * @param bool $can_edit
 *
 * @return string
 */
function myapi_payments_admin_id_link($row, $can_edit) {
  $nid = (int) $row->nid;

  return l((string) $nid, 'node/' . $nid . ($can_edit ? '/edit' : ''));
}

/**
 * Builds the table body: the fifteen cells of SPEC 132, in header order.
 *
 * @param array $rows
 *   Rows fetched from myapi_payments_admin_build_query().
 * @param array $editable
 *   nid => bool. A nid absent from the map is NOT editable — the link that
 *   never 403s is the safe fallback.
 *
 * @return array
 *   Ready for '#theme' => 'table'.
 */
function myapi_payments_admin_table_rows(array $rows, array $editable) {
  $table_rows = array();

  foreach ($rows as $row) {
    $nid = (int) $row->nid;

    $table_rows[] = array(
      myapi_payments_admin_id_link($row, !empty($editable[$nid])),
      myapi_payments_admin_unit_label($row),
      myapi_payments_admin_text_label($row->condominium_title),
      myapi_payments_admin_text_label(myapi_payments_admin_person_name($row, 'owner')),
      myapi_payments_admin_text_label($row->owner_cedula),
      myapi_payments_admin_text_label(myapi_payments_admin_person_name($row, 'occupant')),
      myapi_payments_admin_text_label($row->occupant_cedula),
      myapi_payment_date_label($row->payment_date),
      myapi_payment_method_label($row->payment_method),
      myapi_payments_admin_text_label($row->bank_name),
      myapi_payments_admin_text_label($row->reference),
      myapi_payments_admin_status_label($row->status),
      myapi_payments_admin_money_label($row->amount),
      myapi_payment_channel_label($row->channel),
      check_plain(format_date((int) $row->created, 'custom', 'd/m/Y H:i')),
    );
  }

  return $table_rows;
}

/* ---------------------------------------------------------------------------
 * CSV.
 * ------------------------------------------------------------------------ */

/**
 * The CSV header row: the fifteen screen columns plus the two OCR banks.
 *
 * @return array
 */
function myapi_payments_admin_csv_header() {
  return array(
    t('ID'),
    t('Vivienda'),
    t('Condominio'),
    t('Propietario'),
    t('Cédula propietario'),
    t('Ocupante'),
    t('Cédula ocupante'),
    t('Fecha de pago'),
    t('Forma de pago'),
    t('Banco'),
    t('Referencia'),
    t('Estado'),
    t('Valor'),
    t('Canal'),
    t('Creado'),
    t('Banco emisor'),
    t('Banco destino'),
  );
}

/**
 * One CSV cell, neutralised against formula injection (decision 14).
 *
 * References and names come from the app and the bot, i.e. from residents. A
 * cell starting with '=', '+', '-', '@', a tab or a carriage return would be
 * read as a formula by the operator's spreadsheet; a leading apostrophe makes
 * it text. NULL is an empty cell.
 *
 * @param mixed $value
 *
 * @return string
 */
function myapi_payments_admin_csv_cell($value) {
  if ($value === NULL) {
    return '';
  }

  $value = (string) $value;
  if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== FALSE) {
    return "'" . $value;
  }

  return $value;
}

/**
 * One CSV row: seventeen RAW values, every one through the formula escape.
 *
 * Raw means: the amount as '186.40' (no '$', dot decimal) so the sheet can sum
 * it, ISO dates, catalogue labels unescaped, and an empty cell — never the
 * screen's em dash — for a missing value.
 *
 * @param object $row
 *   Row from myapi_payments_admin_build_query().
 *
 * @return array
 */
function myapi_payments_admin_csv_row($row) {
  $payment_date = NULL;
  if ($row->payment_date !== NULL && preg_match('/^\d{4}-\d{2}-\d{2}/', $row->payment_date, $m)) {
    $payment_date = $m[0];
  }

  $amount = NULL;
  if ($row->amount !== NULL && is_numeric($row->amount)) {
    $amount = number_format((float) $row->amount, 2, '.', '');
  }

  $cells = array(
    (int) $row->nid,
    myapi_payments_admin_unit_name($row),
    $row->condominium_title,
    myapi_payments_admin_person_name($row, 'owner'),
    $row->owner_cedula,
    myapi_payments_admin_person_name($row, 'occupant'),
    $row->occupant_cedula,
    $payment_date,
    myapi_payments_admin_option_label(myapi_payments_admin_method_options(), $row->payment_method),
    $row->bank_name,
    $row->reference,
    myapi_payments_admin_option_label(myapi_payments_admin_status_options(), $row->status),
    $amount,
    myapi_payments_admin_option_label(myapi_claims_field_allowed_values(MYAPI_PAYMENT_CHANNEL_FIELD), $row->channel),
    format_date((int) $row->created, 'custom', 'Y-m-d H:i'),
    $row->issuer_bank,
    $row->destination_bank,
  );

  return array_map('myapi_payments_admin_csv_cell', $cells);
}

/**
 * The download's file name: 'pagos-YYYYmmdd-HHii.csv'.
 *
 * @param int $now
 *   REQUEST_TIME.
 *
 * @return string
 */
function myapi_payments_admin_csv_filename($now) {
  return 'pagos-' . format_date($now, 'custom', 'Ymd-Hi') . '.csv';
}

/**
 * The query string of the 'Descargar CSV' button: the EFFECTIVE filters.
 *
 * The CSV callback re-reads its filters from its own URL, so this sends what
 * the table is actually using (decision 12). The two dates ALWAYS travel:
 * the default range explicitly, and "no limit" as two empty values — which
 * the callback reads as present-and-empty and not as absent, the latter would
 * re-apply the default range.
 *
 * @param array $filters
 *   From myapi_payments_admin_list_filters(), with the condominium already
 *   narrowed by myapi_payments_admin_effective_condominium().
 *
 * @return array
 *   Ready for url()'s 'query' option.
 */
function myapi_payments_admin_csv_query(array $filters) {
  $query = array();

  foreach (array('condominium', 'status', 'unit_name', 'owner_last_name', 'occupant_last_name', 'payment_method', 'channel') as $key) {
    if ($filters[$key] !== NULL) {
      $query[$key] = (string) $filters[$key];
    }
  }

  $query['date_from'] = $filters['date_from'] === NULL ? '' : $filters['date_from'];
  $query['date_to'] = $filters['date_to'] === NULL ? '' : $filters['date_to'];

  return $query;
}

/* ---------------------------------------------------------------------------
 * Who may edit what.
 * ------------------------------------------------------------------------ */

/**
 * Resolves, for the rows of ONE page, whether the reader may edit each node.
 *
 * Drupal's own node_access('update') decides, over ONE node_load_multiple()
 * of the page's nids. Two fast paths load no node at all: 'bypass node
 * access' (an 'administrator') and 'edit any pagos content' (the 'backend'
 * operator this page was built for).
 *
 * @param array $rows
 *   Rows fetched from myapi_payments_admin_fetch_page().
 *
 * @return array
 *   nid => bool. A nid absent from the map is read as "not editable".
 */
function myapi_payments_admin_editable_map(array $rows) {
  if (!$rows) {
    return array();
  }

  $nids = array();
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
  }

  if (user_access('bypass node access') || user_access('edit any ' . MYAPI_PAYMENTS_ADMIN_TYPE . ' content')) {
    return array_fill_keys($nids, TRUE);
  }

  $map = array();
  foreach (node_load_multiple($nids) as $nid => $node) {
    $map[(int) $nid] = (bool) node_access('update', $node);
  }

  return $map;
}

/* ---------------------------------------------------------------------------
 * Filter form.
 * ------------------------------------------------------------------------ */

/**
 * Builds the filter form of the payments report.
 *
 * #method = 'get' and no token, same as every back-office listing; Drupal's
 * own hidden fields are dropped by the calendar's generic after-build. The
 * dates are repainted from the EFFECTIVE filters, so a first load shows the
 * default 30-day range it is applying.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters
 *   Validated filters, used as defaults.
 * @param array $condominium_options
 *   nid => title.
 */
function myapi_payments_admin_filter_form($form, &$form_state, $filters, $condominium_options) {
  $form['#method'] = 'get';
  $form['#attributes']['class'][] = 'myapi-payments-filters';
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  $form['#action'] = url('admin/content/payments');

  // With clean URLs off the path travels in ?q=, which a GET form would
  // otherwise drop — see myapi_reservation_calendar_filter_form().
  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type'  => 'hidden',
      '#value' => 'admin/content/payments',
    );
  }

  $form['condominium'] = array(
    '#type'          => 'select',
    '#title'         => t('Condominio'),
    '#options'       => array('' => t('- Todos -')) + $condominium_options,
    '#default_value' => $filters['condominium'] === NULL ? '' : $filters['condominium'],
  );

  $form['status'] = array(
    '#type'          => 'select',
    '#title'         => t('Estado'),
    '#options'       => array('' => t('- Todos -')) + myapi_payments_admin_status_options(),
    '#default_value' => $filters['status'] === NULL ? '' : $filters['status'],
  );

  $form['unit_name'] = array(
    '#type'          => 'textfield',
    '#title'         => t('Vivienda'),
    '#size'          => 15,
    '#maxlength'     => MYAPI_PAYMENTS_ADMIN_TEXT_MAX,
    '#default_value' => (string) $filters['unit_name'],
  );

  $form['owner_last_name'] = array(
    '#type'          => 'textfield',
    '#title'         => t('Apellidos propietario'),
    '#size'          => 15,
    '#maxlength'     => MYAPI_PAYMENTS_ADMIN_TEXT_MAX,
    '#default_value' => (string) $filters['owner_last_name'],
  );

  $form['occupant_last_name'] = array(
    '#type'          => 'textfield',
    '#title'         => t('Apellidos ocupante'),
    '#size'          => 15,
    '#maxlength'     => MYAPI_PAYMENTS_ADMIN_TEXT_MAX,
    '#default_value' => (string) $filters['occupant_last_name'],
  );

  $form['payment_method'] = array(
    '#type'          => 'select',
    '#title'         => t('Forma de pago'),
    '#options'       => array('' => t('- Todas -')) + myapi_payments_admin_method_options(),
    '#default_value' => $filters['payment_method'] === NULL ? '' : $filters['payment_method'],
  );

  $form['channel'] = array(
    '#type'          => 'select',
    '#title'         => t('Canal'),
    '#options'       => array('' => t('- Todos -')) + myapi_payments_admin_channel_options(),
    '#default_value' => $filters['channel'] === NULL ? '' : $filters['channel'],
  );

  // Native HTML5 date inputs, written by hand: theme_textfield() forces
  // type="text". Both are always submitted, empty or not, which is what
  // tells "the operator removed the limit" from "first load" (decision 6).
  $form['date_from'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha de pago desde'),
    '#markup' => '<input type="date" name="date_from" value="' . check_plain((string) $filters['date_from']) . '" />',
  );
  $form['date_to'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha de pago hasta'),
    '#markup' => '<input type="date" name="date_to" value="' . check_plain((string) $filters['date_to']) . '" />',
  );

  $form['submit'] = array(
    '#type'  => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/* ---------------------------------------------------------------------------
 * Page.
 * ------------------------------------------------------------------------ */

/**
 * Resolves the effective filters of the current request.
 *
 * Shared by the page and the CSV callback so the two read the URL the same
 * way: the GET filters, with the condominium kept only when it is one of the
 * offered ones.
 *
 * @param array $condominium_options
 *   nid => title, from myapi_calendar_condominium_options().
 *
 * @return array
 *   Filters ready for myapi_payments_admin_build_query().
 */
function myapi_payments_admin_effective_filters(array $condominium_options) {
  $filters = myapi_payments_admin_list_filters(REQUEST_TIME);
  $filters['condominium'] = myapi_payments_admin_effective_condominium($filters['condominium'], $condominium_options);

  return $filters;
}

/**
 * Page callback of admin/content/payments.
 *
 * Read-only from end to end: the filter form, a 'Descargar CSV' button that
 * carries the effective filters, one paginated table, the total of every
 * filtered payment, and the pager. The ID cell links to Drupal's own node
 * pages; nothing here edits a payment.
 */
function myapi_payments_admin_page() {
  drupal_add_css(drupal_get_path('module', 'myapi') . '/css/myapi.claims.css');

  $condominium_options = myapi_calendar_condominium_options();
  $filters = myapi_payments_admin_effective_filters($condominium_options);

  $rows = myapi_payments_admin_fetch_page($filters);
  $total = myapi_payments_admin_total($filters);

  $build = array();

  $build['filters'] = drupal_get_form('myapi_payments_admin_filter_form', $filters, $condominium_options);

  $build['csv'] = array(
    '#markup' => '<div class="myapi-payments-actions">' . l(t('Descargar CSV'), 'admin/content/payments/csv', array(
      'query'      => myapi_payments_admin_csv_query($filters),
      'attributes' => array('class' => array('button', 'myapi-payments-csv')),
    )) . '</div>',
  );

  $build['table'] = array(
    '#theme'  => 'table',
    '#header' => array(
      t('ID'),
      t('Vivienda'),
      t('Condominio'),
      t('Propietario'),
      t('Cédula propietario'),
      t('Ocupante'),
      t('Cédula ocupante'),
      t('Fecha de pago'),
      t('Forma de pago'),
      t('Banco'),
      t('Referencia'),
      t('Estado'),
      t('Valor'),
      t('Canal'),
      t('Creado'),
    ),
    '#rows'   => myapi_payments_admin_table_rows($rows, myapi_payments_admin_editable_map($rows)),
    '#empty'  => t('No hay resultados para los filtros seleccionados.'),
  );

  $build['total'] = array(
    '#markup' => '<div class="myapi-payments-total">' . check_plain(myapi_payments_admin_total_label($total['total'], $total['count'])) . '</div>',
  );

  $build['pager'] = array(
    '#markup' => theme('pager'),
  );

  return $build;
}

/* ---------------------------------------------------------------------------
 * CSV download.
 * ------------------------------------------------------------------------ */

/**
 * Rows read per batch by the CSV download.
 */
define('MYAPI_PAYMENTS_ADMIN_CSV_BATCH', 500);

/**
 * Page callback of admin/content/payments/csv.
 *
 * Reads its filters from its own URL exactly as the page does — the button
 * that links here sends the page's EFFECTIVE filters, default range included
 * — and streams the result.
 */
function myapi_payments_admin_csv() {
  $filters = myapi_payments_admin_effective_filters(myapi_calendar_condominium_options());

  myapi_payments_admin_csv_send($filters, REQUEST_TIME);
}

/**
 * Streams the filtered payments as a CSV attachment and ends the request.
 *
 * Not through myapi_respond(): this is not the JSON API. UTF-8 with a BOM so
 * Excel shows accents and 'ñ' (decision 13), comma-separated, one header row,
 * then every filtered payment — no row limit — read in batches of 500 over
 * the same ordered query the page pages, so memory stays flat however large
 * the range is.
 *
 * fputcsv() is given an EMPTY escape character (PHP 7.4+): its default
 * backslash escape is not part of RFC 4180 and corrupts a field holding a
 * backslash followed by a quote.
 *
 * The file carries residents' names and ID numbers; it is never cached.
 *
 * @param array $filters
 *   Effective filters, from myapi_payments_admin_effective_filters().
 * @param int $now
 *   REQUEST_TIME, for the file name.
 */
function myapi_payments_admin_csv_send(array $filters, $now) {
  drupal_add_http_header('Content-Type', 'text/csv; charset=utf-8');
  drupal_add_http_header('Content-Disposition', 'attachment; filename="' . myapi_payments_admin_csv_filename($now) . '"');
  drupal_add_http_header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
  drupal_add_http_header('Pragma', 'no-cache');
  drupal_add_http_header('Expires', '0');
  drupal_add_http_header('X-Content-Type-Options', 'nosniff');

  $handle = fopen('php://output', 'w');
  fwrite($handle, "\xEF\xBB\xBF");
  fputcsv($handle, myapi_payments_admin_csv_header(), ',', '"', '');

  $offset = 0;
  do {
    $rows = myapi_payments_admin_fetch_batch($filters, $offset, MYAPI_PAYMENTS_ADMIN_CSV_BATCH);
    foreach ($rows as $row) {
      fputcsv($handle, myapi_payments_admin_csv_row($row), ',', '"', '');
    }
    $offset += MYAPI_PAYMENTS_ADMIN_CSV_BATCH;
  } while (count($rows) === MYAPI_PAYMENTS_ADMIN_CSV_BATCH);

  fclose($handle);
  drupal_exit();
}
