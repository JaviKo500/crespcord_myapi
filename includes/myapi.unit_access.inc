<?php

/**
 * @file
 * Shared unit access helpers for the myapi module.
 */

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
 * Returns the nids of 'condominio' nodes related to a user through any
 * 'vivienda' node they own or occupy.
 *
 * @param int $uid  User id to look up.
 *
 * @return array  Unique list of 'condominio' nids.
 */
function myapi_condominium_related_nids($uid) {
  $unit_nids = myapi_unit_related_nids($uid);
  if (empty($unit_nids)) {
    return [];
  }

  $condo_nids = db_select('field_data_field_condominio', 'fc')
    ->fields('fc', ['field_condominio_target_id'])
    ->condition('entity_id', $unit_nids, 'IN')
    ->condition('entity_type', 'node')
    ->condition('deleted', 0)
    ->execute()
    ->fetchCol();

  return array_values(array_unique($condo_nids));
}

/**
 * Returns the uids of the owners and/or occupants of a set of 'vivienda'
 * nodes, filtered by role.
 *
 * Reverse of myapi_unit_related_nids(): given the units, resolve the users.
 * Occupants come from both the legacy single-value field (field_ocupante) and
 * the current multi-value field (field_ocupantes), merged. Shared by the
 * reverse condominium resolver below and by the 'General' bulletin scope,
 * which resolves over every published unit.
 *
 * @param array  $unit_nids  'vivienda' nids to look up.
 * @param string $role        'propietarios', 'ocupantes' or 'todos'. Any other
 *                            value yields an empty set (fail-safe).
 *
 * @return array  Unique list of uids (not filtered by user status).
 */
function myapi_unit_member_uids(array $unit_nids, $role) {
  if (empty($unit_nids)) {
    return [];
  }

  $role = strtolower($role);
  $uids = [];

  if ($role === 'propietarios' || $role === 'todos') {
    $owner_uids = db_select('field_data_field_propietario', 'p')
      ->fields('p', ['field_propietario_target_id'])
      ->condition('entity_id', $unit_nids, 'IN')
      ->condition('entity_type', 'node')
      ->condition('deleted', 0)
      ->execute()
      ->fetchCol();
    $uids = array_merge($uids, $owner_uids);
  }

  if ($role === 'ocupantes' || $role === 'todos') {
    $occupant_uids = db_select('field_data_field_ocupante', 'o')
      ->fields('o', ['field_ocupante_target_id'])
      ->condition('entity_id', $unit_nids, 'IN')
      ->condition('entity_type', 'node')
      ->condition('deleted', 0)
      ->execute()
      ->fetchCol();

    $occupants_uids = db_select('field_data_field_ocupantes', 'os')
      ->fields('os', ['field_ocupantes_target_id'])
      ->condition('entity_id', $unit_nids, 'IN')
      ->condition('entity_type', 'node')
      ->condition('deleted', 0)
      ->execute()
      ->fetchCol();

    $uids = array_merge($uids, $occupant_uids, $occupants_uids);
  }

  return array_values(array_unique($uids));
}

/**
 * Returns the uids of the owners and/or occupants of the units belonging to a
 * set of 'condominio' nodes (reverse of myapi_condominium_related_nids()).
 *
 * Encapsulates the condominium -> units -> uids path so no caller duplicates
 * the field-table queries.
 *
 * @param array  $condo_nids  'condominio' nids.
 * @param string $role         'propietarios', 'ocupantes' or 'todos'.
 *
 * @return array  Unique list of uids (not filtered by user status).
 */
function myapi_condominium_member_uids(array $condo_nids, $role) {
  if (empty($condo_nids)) {
    return [];
  }

  $unit_nids = db_select('field_data_field_condominio', 'fc')
    ->fields('fc', ['entity_id'])
    ->condition('field_condominio_target_id', $condo_nids, 'IN')
    ->condition('entity_type', 'node')
    ->condition('deleted', 0)
    ->execute()
    ->fetchCol();

  return myapi_unit_member_uids($unit_nids, $role);
}
