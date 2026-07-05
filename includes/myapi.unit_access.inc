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
