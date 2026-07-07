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
