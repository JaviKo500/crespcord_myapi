<?php

/**
 * @file
 * Unit resource for the myapi module.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.auth');

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

  $owner_uids = array_values(array_unique(array_filter(array_map(function ($unit) {
    return $unit->owner_uid;
  }, $units))));
  $owner_names = myapi_unit_fetch_owner_names($owner_uids);
}

/**
 * Returns the nids of 'vivienda' nodes related to a user as owner or
 * occupant (legacy single-value field or current multi-value field).
 *
 * Three independent queries merged in PHP instead of a single UNION, to keep
 * each query simple and easy to debug on its own.
 *
 * @param int $uid  User id to look up.
 *
 * @return array  Unique list of 'vivienda' nids.
 */
function myapi_unit_related_nids($uid) {
  $owner_nids = db_select('field_data_field_propietario', 'p')
    ->fields('p', ['entity_id'])
    ->condition('field_propietario_target_id', $uid)
    ->condition('deleted', 0)
    ->execute()
    ->fetchCol();

  $occupant_nids = db_select('field_data_field_ocupante', 'o')
    ->fields('o', ['entity_id'])
    ->condition('field_ocupante_target_id', $uid)
    ->condition('deleted', 0)
    ->execute()
    ->fetchCol();

  $occupants_nids = db_select('field_data_field_ocupantes', 'os')
    ->fields('os', ['entity_id'])
    ->condition('field_ocupantes_target_id', $uid)
    ->condition('deleted', 0)
    ->execute()
    ->fetchCol();

  return array_values(array_unique(array_merge($owner_nids, $occupant_nids, $occupants_nids)));
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
 *                area_m2, condominio_nid, owner_uid.
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
 * Fetches display names for a set of user ids.
 *
 * Uses "$field_nombre $field_apellidos" when both are non-empty; falls back
 * to the full users.name otherwise, to avoid hybrid names mixing a username
 * with only one of the two parts.
 *
 * @param array $uids  User ids to fetch.
 *
 * @return array  Map of uid => owner_name.
 */
function myapi_unit_fetch_owner_names($uids) {
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
