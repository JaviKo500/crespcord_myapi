<?php

/**
 * @file
 * Receipt resource for the myapi module.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Dispatcher for /api/v1/units/%/receipts.
 *
 * Routes by HTTP method. Only GET is supported.
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_receipt_dispatch($unit_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_receipt_list($unit_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/units/%/receipts.
 *
 * Returns, for the authenticated user, a paginated list of 'recibo' nodes
 * belonging to $unit_id, provided the user is the owner or occupant of that
 * unit (same access rule as GET /api/v1/units).
 *
 * @param int $unit_id  The 'vivienda' nid from the route.
 */
function myapi_receipt_list($unit_id) {
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

  $total = myapi_receipt_count($unit_id);
  $total_pages = $total > 0 ? (int) ceil($total / $limit) : 0;

  $rows = myapi_receipt_fetch($unit_id, $page, $limit, $sort);
  $receipts = array_map('myapi_receipt_build_item', $rows);

  myapi_respond([
    'receipts'   => $receipts,
    'pagination' => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * Counts published 'recibo' nodes for a unit, unpaginated.
 *
 * @param int $unit_id  The 'vivienda' nid to filter by.
 *
 * @return int  Total number of matching receipts.
 */
function myapi_receipt_count($unit_id) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'recibo')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $unit_id);

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Fetches a page of 'recibo' nodes for a unit, with every mapped field.
 *
 * @param int    $unit_id  The 'vivienda' nid to filter by.
 * @param int    $page     1-based page number.
 * @param int    $limit    Rows per page, already clamped to [1, 50].
 * @param string $sort     'asc' or 'desc', applied to field_periodo_value.
 *
 * @return array  Rows keyed numerically, one per receipt.
 */
function myapi_receipt_fetch($unit_id, $page, $limit, $sort) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'recibo')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_vivienda', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->condition('fv.field_vivienda_target_id', $unit_id);
  $query->addField('fv', 'field_vivienda_target_id', 'unit_id');

  $query->leftJoin('field_data_field_periodo', 'fper', "fper.entity_id = n.nid AND fper.entity_type = 'node' AND fper.deleted = 0");
  $query->addField('fper', 'field_periodo_value', 'period_start');
  $query->addField('fper', 'field_periodo_value2', 'period_end');

  $query->leftJoin('field_data_field_estado', 'fest', "fest.entity_id = n.nid AND fest.entity_type = 'node' AND fest.deleted = 0");
  $query->addField('fest', 'field_estado_value', 'status');

  $query->leftJoin('field_data_field_gas_lectura_anterior', 'fgla', "fgla.entity_id = n.nid AND fgla.entity_type = 'node' AND fgla.deleted = 0");
  $query->addField('fgla', 'field_gas_lectura_anterior_value', 'gas_previous_reading');

  $query->leftJoin('field_data_field_gas_lectura_actual', 'fgca', "fgca.entity_id = n.nid AND fgca.entity_type = 'node' AND fgca.deleted = 0");
  $query->addField('fgca', 'field_gas_lectura_actual_value', 'gas_current_reading');

  $query->leftJoin('field_data_field_consumo_gas', 'fcg', "fcg.entity_id = n.nid AND fcg.entity_type = 'node' AND fcg.deleted = 0");
  $query->addField('fcg', 'field_consumo_gas_value', 'gas_consumption');

  $query->leftJoin('field_data_field_agua_lectura_anterior', 'fala', "fala.entity_id = n.nid AND fala.entity_type = 'node' AND fala.deleted = 0");
  $query->addField('fala', 'field_agua_lectura_anterior_value', 'water_previous_reading');

  $query->leftJoin('field_data_field_agua_lectura_actual', 'falc', "falc.entity_id = n.nid AND falc.entity_type = 'node' AND falc.deleted = 0");
  $query->addField('falc', 'field_agua_lectura_actual_value', 'water_current_reading');

  $query->leftJoin('field_data_field_consumo_agua', 'fca', "fca.entity_id = n.nid AND fca.entity_type = 'node' AND fca.deleted = 0");
  $query->addField('fca', 'field_consumo_agua_value', 'water_consumption');

  $query->leftJoin('field_data_field_agua_caliente_lectura_ante', 'facla', "facla.entity_id = n.nid AND facla.entity_type = 'node' AND facla.deleted = 0");
  $query->addField('facla', 'field_agua_caliente_lectura_ante_value', 'hot_water_previous_reading');

  $query->leftJoin('field_data_field_agua_caliente_lectura_actu', 'faclc', "faclc.entity_id = n.nid AND faclc.entity_type = 'node' AND faclc.deleted = 0");
  $query->addField('faclc', 'field_agua_caliente_lectura_actu_value', 'hot_water_current_reading');

  $query->leftJoin('field_data_field_consumo_agua_caliente', 'fcac', "fcac.entity_id = n.nid AND fcac.entity_type = 'node' AND fcac.deleted = 0");
  $query->addField('fcac', 'field_consumo_agua_caliente_value', 'hot_water_consumption');

  $query->leftJoin('field_data_field_calentamiento_agua', 'fcaw', "fcaw.entity_id = n.nid AND fcaw.entity_type = 'node' AND fcaw.deleted = 0");
  $query->addField('fcaw', 'field_calentamiento_agua_value', 'water_heating');

  $query->leftJoin('field_data_field_gimnasio', 'fgim', "fgim.entity_id = n.nid AND fgim.entity_type = 'node' AND fgim.deleted = 0");
  $query->addField('fgim', 'field_gimnasio_value', 'gym');

  $query->leftJoin('field_data_field_jacuzzi_sauna_turco', 'fjac', "fjac.entity_id = n.nid AND fjac.entity_type = 'node' AND fjac.deleted = 0");
  $query->addField('fjac', 'field_jacuzzi_sauna_turco_value', 'jacuzzi_sauna');

  $query->leftJoin('field_data_field_extra', 'fext', "fext.entity_id = n.nid AND fext.entity_type = 'node' AND fext.deleted = 0");
  $query->addField('fext', 'field_extra_value', 'extra');

  $query->leftJoin('field_data_field_alicuota_extra', 'faliex', "faliex.entity_id = n.nid AND faliex.entity_type = 'node' AND faliex.deleted = 0");
  $query->addField('faliex', 'field_alicuota_extra_value', 'extra_fee');

  $query->leftJoin('field_data_field_internet', 'fint', "fint.entity_id = n.nid AND fint.entity_type = 'node' AND fint.deleted = 0");
  $query->addField('fint', 'field_internet_value', 'internet');

  $query->leftJoin('field_data_field_energia_electrica', 'fenerg', "fenerg.entity_id = n.nid AND fenerg.entity_type = 'node' AND fenerg.deleted = 0");
  $query->addField('fenerg', 'field_energia_electrica_value', 'electricity');

  $query->leftJoin('field_data_field_precalentamiento', 'fprec', "fprec.entity_id = n.nid AND fprec.entity_type = 'node' AND fprec.deleted = 0");
  $query->addField('fprec', 'field_precalentamiento_value', 'preheating');

  $query->leftJoin('field_data_field_alicuota', 'fali', "fali.entity_id = n.nid AND fali.entity_type = 'node' AND fali.deleted = 0");
  $query->addField('fali', 'field_alicuota_value', 'fee');

  $query->leftJoin('field_data_field_alicuota_bodega', 'falibod', "falibod.entity_id = n.nid AND falibod.entity_type = 'node' AND falibod.deleted = 0");
  $query->addField('falibod', 'field_alicuota_bodega_value', 'storage_fee');

  $query->leftJoin('field_data_field_alicuota_parqueadero', 'faliparq', "faliparq.entity_id = n.nid AND faliparq.entity_type = 'node' AND faliparq.deleted = 0");
  $query->addField('faliparq', 'field_alicuota_parqueadero_value', 'parking_fee');

  $query->leftJoin('field_data_field_alicuota_terraza', 'faliterr', "faliterr.entity_id = n.nid AND faliterr.entity_type = 'node' AND faliterr.deleted = 0");
  $query->addField('faliterr', 'field_alicuota_terraza_value', 'terrace_fee');

  $query->leftJoin('field_data_field_alicuota_oficina', 'faliofic', "faliofic.entity_id = n.nid AND faliofic.entity_type = 'node' AND faliofic.deleted = 0");
  $query->addField('faliofic', 'field_alicuota_oficina_value', 'office_fee');

  $query->leftJoin('field_data_field_alicuota_local_comercial', 'falicom', "falicom.entity_id = n.nid AND falicom.entity_type = 'node' AND falicom.deleted = 0");
  $query->addField('falicom', 'field_alicuota_local_comercial_value', 'commercial_unit_fee');

  $query->leftJoin('field_data_field_alicuota_total', 'falitot', "falitot.entity_id = n.nid AND falitot.entity_type = 'node' AND falitot.deleted = 0");
  $query->addField('falitot', 'field_alicuota_total_value', 'total_fee');

  $query->leftJoin('field_data_field_seguro', 'fseg', "fseg.entity_id = n.nid AND fseg.entity_type = 'node' AND fseg.deleted = 0");
  $query->addField('fseg', 'field_seguro_value', 'insurance');

  $query->leftJoin('field_data_field_valor_penalizacion', 'fpen', "fpen.entity_id = n.nid AND fpen.entity_type = 'node' AND fpen.deleted = 0");
  $query->addField('fpen', 'field_valor_penalizacion_value', 'penalty_amount');

  $query->leftJoin('field_data_field_total_mes', 'ftotm', "ftotm.entity_id = n.nid AND ftotm.entity_type = 'node' AND ftotm.deleted = 0");
  $query->addField('ftotm', 'field_total_mes_value', 'monthly_total');

  $query->leftJoin('field_data_field_saldo_anterior', 'fsant', "fsant.entity_id = n.nid AND fsant.entity_type = 'node' AND fsant.deleted = 0");
  $query->addField('fsant', 'field_saldo_anterior_value', 'previous_balance');

  $query->leftJoin('field_data_field_total', 'ftot', "ftot.entity_id = n.nid AND ftot.entity_type = 'node' AND ftot.deleted = 0");
  $query->addField('ftot', 'field_total_value', 'total');

  $query->leftJoin('field_data_field_observacion', 'fobs', "fobs.entity_id = n.nid AND fobs.entity_type = 'node' AND fobs.deleted = 0");
  $query->addField('fobs', 'field_observacion_value', 'observation');

  $query->leftJoin('field_data_field_mensaje_demora', 'fmsgd', "fmsgd.entity_id = n.nid AND fmsgd.entity_type = 'node' AND fmsgd.deleted = 0");
  $query->addField('fmsgd', 'field_mensaje_demora_value', 'late_payment_message');

  $query->leftJoin('field_data_field_tarifa_fija_gas', 'ftfg', "ftfg.entity_id = n.nid AND ftfg.entity_type = 'node' AND ftfg.deleted = 0");
  $query->addField('ftfg', 'field_tarifa_fija_gas_value', 'gas_fixed_rate');

  $query->leftJoin('field_data_field_tarifa_fija_agua', 'ftfa', "ftfa.entity_id = n.nid AND ftfa.entity_type = 'node' AND ftfa.deleted = 0");
  $query->addField('ftfa', 'field_tarifa_fija_agua_value', 'water_fixed_rate');

  $query->leftJoin('field_data_field_tarifa_fija_agua_caliente', 'ftfac', "ftfac.entity_id = n.nid AND ftfac.entity_type = 'node' AND ftfac.deleted = 0");
  $query->addField('ftfac', 'field_tarifa_fija_agua_caliente_value', 'hot_water_fixed_rate');

  $query->orderBy('fper.field_periodo_value', $sort === 'asc' ? 'ASC' : 'DESC');
  $query->range(($page - 1) * $limit, $limit);

  return $query->execute()->fetchAll();
}

/**
 * Maps one raw receipt row into the response shape documented for
 * GET /api/v1/units/%/receipts.
 *
 * All decimal fields are cast to float when present, NULL otherwise. Text
 * fields (period, status, observation, late_payment_message) pass through
 * as stored, with no business interpretation.
 *
 * @param object $receipt  One row from myapi_receipt_fetch().
 *
 * @return array  Response item with all 40 mapped keys.
 */
function myapi_receipt_build_item($receipt) {
  $float_fields = [
    'gas_previous_reading', 'gas_current_reading', 'gas_consumption',
    'water_previous_reading', 'water_current_reading', 'water_consumption',
    'hot_water_previous_reading', 'hot_water_current_reading', 'hot_water_consumption',
    'water_heating', 'gym', 'jacuzzi_sauna', 'extra', 'extra_fee', 'internet',
    'electricity', 'preheating', 'fee', 'storage_fee', 'parking_fee',
    'terrace_fee', 'office_fee', 'commercial_unit_fee', 'total_fee',
    'insurance', 'penalty_amount', 'monthly_total', 'previous_balance',
    'total', 'gas_fixed_rate', 'water_fixed_rate', 'hot_water_fixed_rate',
  ];

  $item = [
    'id'           => (int) $receipt->nid,
    'title'        => $receipt->title,
    'unit_id'      => (int) $receipt->unit_id,
    'period_start' => $receipt->period_start,
    'period_end'   => $receipt->period_end,
    'status'       => $receipt->status,
  ];

  foreach ($float_fields as $field) {
    $item[$field] = $receipt->$field !== NULL ? (float) $receipt->$field : NULL;
  }

  $item['observation'] = $receipt->observation;
  $item['late_payment_message'] = $receipt->late_payment_message;

  return $item;
}
