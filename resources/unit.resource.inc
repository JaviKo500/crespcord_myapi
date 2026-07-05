<?php

/**
 * @file
 * Unit resource for the myapi module.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Dispatcher for /api/v1/units.
 *
 * Routes by HTTP method. Only GET is supported.
 */
function myapi_unit_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_unit_list();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/units.
 *
 * Returns, for the authenticated user, every condominium where they have at
 * least one unit (as owner or occupant), with the units nested underneath.
 */
function myapi_unit_list() {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  $nids = myapi_unit_related_nids($uid);
  if (empty($nids)) {
    myapi_respond(['properties' => []], 200);
  }

  $units = myapi_unit_fetch_units($nids);

  $condominio_nids = array_values(array_unique(array_filter(array_map(function ($unit) {
    return $unit->condominio_nid;
  }, $units))));
  $condominium_titles = myapi_unit_fetch_condominium_titles($condominio_nids);

  $occupant_uids = myapi_unit_fetch_occupant_uids($nids);

  $owner_uids = array_map(function ($unit) {
    return $unit->owner_uid;
  }, $units);
  $user_uids = array_values(array_unique(array_filter(array_merge($owner_uids, array_values($occupant_uids)))));
  $user_names = myapi_unit_fetch_user_names($user_uids);

  $properties = myapi_unit_build_properties($units, $condominium_titles, $occupant_uids, $user_names);

  myapi_respond(['properties' => $properties], 200);
}

/**
 * Groups units by condominium and resolves owner/occupant names, into the
 * response shape documented for GET /api/v1/units.
 *
 * Units whose condominio_nid has no entry in $condominium_titles (parent
 * condominium missing or unpublished) are dropped from the response.
 *
 * @param array $units              Rows from myapi_unit_fetch_units().
 * @param array $condominium_titles Map of condominio_nid => title.
 * @param array $occupant_uids      Map of nid => occupant_uid, from
 *                                  myapi_unit_fetch_occupant_uids().
 * @param array $user_names         Map of uid => name, from
 *                                  myapi_unit_fetch_user_names().
 *
 * @return array  List of ['id', 'name', 'units' => [['id', 'name', 'category', 'area_m2', 'owner_uid', 'owner_name', 'occupant_uid', 'occupant_name', 'current_balance'], ...]].
 */
function myapi_unit_build_properties($units, $condominium_titles, $occupant_uids, $user_names) {
  $properties_by_condominio = [];

  foreach ($units as $unit) {
    $condominio_nid = $unit->condominio_nid;
    if ($condominio_nid === NULL || !isset($condominium_titles[$condominio_nid])) {
      continue;
    }

    if (!isset($properties_by_condominio[$condominio_nid])) {
      $properties_by_condominio[$condominio_nid] = [
        'id'    => (int) $condominio_nid,
        'name'  => $condominium_titles[$condominio_nid],
        'units' => [],
      ];
    }

    $owner_name = ($unit->owner_uid !== NULL && isset($user_names[$unit->owner_uid]))
      ? $user_names[$unit->owner_uid]
      : NULL;

    $occupant_uid = isset($occupant_uids[$unit->nid]) ? $occupant_uids[$unit->nid] : NULL;
    $occupant_name = ($occupant_uid !== NULL && isset($user_names[$occupant_uid]))
      ? $user_names[$occupant_uid]
      : NULL;

    $properties_by_condominio[$condominio_nid]['units'][] = [
      'id'            => (int) $unit->nid,
      'name'          => $unit->name,
      'category'      => $unit->category,
      'area_m2'       => $unit->area_m2 !== NULL ? (float) $unit->area_m2 : NULL,
      'owner_uid'     => $unit->owner_uid !== NULL ? (int) $unit->owner_uid : NULL,
      'owner_name'    => $owner_name,
      'occupant_uid'  => $occupant_uid !== NULL ? (int) $occupant_uid : NULL,
      'occupant_name' => $occupant_name,
      'current_balance' => $unit->saldo_actual !== NULL ? (float) $unit->saldo_actual : NULL,
    ];
  }

  return array_values($properties_by_condominio);
}

/**
 * Fetches unit ('vivienda') data for a set of nids.
 *
 * Only published units are returned. Fields not set on a given unit come back
 * as NULL via the LEFT JOINs (e.g. a unit without an owner has owner_uid NULL).
 *
 * @param array $nids  'vivienda' nids to fetch.
 *
 * @return array  Rows keyed numerically, each with nid, name, category,
 *                area_m2, condominio_nid, owner_uid, saldo_actual.
 */
function myapi_unit_fetch_units($nids) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.type', 'vivienda')
    ->condition('n.status', 1)
    ->condition('n.nid', $nids, 'IN');

  $query->leftJoin('field_data_field_nombre_vivienda', 'fnv', "fnv.entity_id = n.nid AND fnv.entity_type = 'node' AND fnv.deleted = 0");
  $query->addField('fnv', 'field_nombre_vivienda_value', 'name');

  $query->leftJoin('field_data_field_categoria', 'fc', "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0");
  $query->leftJoin('taxonomy_term_data', 'tcat', 'tcat.tid = fc.field_categoria_tid');
  $query->addField('tcat', 'name', 'category');

  $query->leftJoin('field_data_field_total_m2', 'fm2', "fm2.entity_id = n.nid AND fm2.entity_type = 'node' AND fm2.deleted = 0");
  $query->addField('fm2', 'field_total_m2_value', 'area_m2');

  $query->leftJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->addField('fcond', 'field_condominio_target_id', 'condominio_nid');

  $query->leftJoin('field_data_field_propietario', 'fown', "fown.entity_id = n.nid AND fown.entity_type = 'node' AND fown.deleted = 0");
  $query->addField('fown', 'field_propietario_target_id', 'owner_uid');

  $query->leftJoin('field_data_field_saldo_actual', 'fsa', "fsa.entity_id = n.nid AND fsa.entity_type = 'node' AND fsa.deleted = 0");
  $query->addField('fsa', 'field_saldo_actual_value', 'saldo_actual');

  return $query->execute()->fetchAll();
}

/**
 * Fetches published 'condominio' titles for a set of nids.
 *
 * Nids not present in the returned map either do not exist or are
 * unpublished; callers use this to drop units whose condominium is not
 * visible.
 *
 * @param array $nids  'condominio' nids to fetch.
 *
 * @return array  Map of nid => title, only for published condominiums.
 */
function myapi_unit_fetch_condominium_titles($nids) {
  if (empty($nids)) {
    return [];
  }

  $result = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'condominio')
    ->condition('n.status', 1)
    ->condition('n.nid', $nids, 'IN')
    ->execute();

  $titles = [];
  foreach ($result as $condominium) {
    $titles[$condominium->nid] = $condominium->title;
  }

  return $titles;
}

/**
 * Fetches the current occupant uid for a set of 'vivienda' nids.
 *
 * Resolution order per nid:
 *  - Rows in field_data_field_ocupantes (current, multi-value), keeping the
 *    one with the highest delta (the most recently assigned occupant). The
 *    query orders by delta ASC and the loop overwrites the map entry for
 *    each entity_id, so the last write per nid is the highest delta.
 *  - For nids not resolved above, fall back to field_data_field_ocupante
 *    (legacy, single-value).
 *
 * @param array $nids  'vivienda' nids to look up.
 *
 * @return array  Map of nid => occupant_uid. Nids with no occupant in either
 *                field are absent from the map.
 */
function myapi_unit_fetch_occupant_uids($nids) {
  if (empty($nids)) {
    return [];
  }

  $occupant_uids = [];

  $result = db_select('field_data_field_ocupantes', 'os')
    ->fields('os', ['entity_id', 'field_ocupantes_target_id'])
    ->condition('entity_id', $nids, 'IN')
    ->condition('deleted', 0)
    ->orderBy('delta', 'ASC')
    ->execute();

  foreach ($result as $row) {
    $occupant_uids[$row->entity_id] = $row->field_ocupantes_target_id;
  }

  $unresolved_nids = array_values(array_diff($nids, array_keys($occupant_uids)));
  if (!empty($unresolved_nids)) {
    $legacy_result = db_select('field_data_field_ocupante', 'o')
      ->fields('o', ['entity_id', 'field_ocupante_target_id'])
      ->condition('entity_id', $unresolved_nids, 'IN')
      ->condition('deleted', 0)
      ->execute();

    foreach ($legacy_result as $row) {
      $occupant_uids[$row->entity_id] = $row->field_ocupante_target_id;
    }
  }

  return $occupant_uids;
}

/**
 * Fetches display names for a set of user ids (owners and/or occupants).
 *
 * Uses "$field_nombre $field_apellidos" when both are non-empty; falls back
 * to the full users.name otherwise, to avoid hybrid names mixing a username
 * with only one of the two parts.
 *
 * @param array $uids  User ids to fetch.
 *
 * @return array  Map of uid => name.
 */
function myapi_unit_fetch_user_names($uids) {
  if (empty($uids)) {
    return [];
  }

  $query = db_select('users', 'u')
    ->fields('u', ['uid', 'name'])
    ->condition('u.uid', $uids, 'IN');

  $query->leftJoin('field_data_field_nombre', 'fn', "fn.entity_id = u.uid AND fn.entity_type = 'user' AND fn.deleted = 0");
  $query->addField('fn', 'field_nombre_value', 'first_name');

  $query->leftJoin('field_data_field_apellidos', 'fa', "fa.entity_id = u.uid AND fa.entity_type = 'user' AND fa.deleted = 0");
  $query->addField('fa', 'field_apellidos_value', 'last_name');

  $result = $query->execute();

  $names = [];
  foreach ($result as $account) {
    if ($account->first_name !== NULL && $account->first_name !== '' && $account->last_name !== NULL && $account->last_name !== '') {
      $names[$account->uid] = $account->first_name . ' ' . $account->last_name;
    }
    else {
      $names[$account->uid] = $account->name;
    }
  }

  return $names;
}
