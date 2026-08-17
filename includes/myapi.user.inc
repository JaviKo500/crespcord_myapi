<?php

/**
 * @file
 * User profile field helpers for the myapi module.
 */

/**
 * Fetches profile fields (first name, last name, DNI, phone) for a user.
 *
 * Reads the underlying Field API tables directly (field_data_field_nombre,
 * field_apellidos, field_cedula, field_telefono) rather than user_load(),
 * since these are Field API values, not properties on the user object.
 *
 * The function_exists() guard is here for tests/unit/bootstrap.php, which
 * declares a fixture stub of this same function (SPEC 73) and is loaded before
 * any production file. Since SPEC 89 this file also holds
 * myapi_user_display_names(), which unit tests DO exercise for real, so the
 * bootstrap has to be able to require this file without a redeclare fatal.
 * The guard covers this function only: everything else here is the real thing.
 *
 * @param int $uid  User id.
 *
 * @return array  ['first_name' => string|NULL, 'last_name' => string|NULL,
 *                  'dni' => string|NULL, 'phone' => string|NULL].
 */
if (!function_exists('myapi_user_fetch_profile_fields')) {
function myapi_user_fetch_profile_fields($uid) {
  $query = db_select('users', 'u')
    ->fields('u', ['uid'])
    ->condition('u.uid', $uid);

  $query->leftJoin('field_data_field_nombre', 'fn', "fn.entity_id = u.uid AND fn.entity_type = 'user' AND fn.deleted = 0");
  $query->addField('fn', 'field_nombre_value', 'first_name');

  $query->leftJoin('field_data_field_apellidos', 'fa', "fa.entity_id = u.uid AND fa.entity_type = 'user' AND fa.deleted = 0");
  $query->addField('fa', 'field_apellidos_value', 'last_name');

  $query->leftJoin('field_data_field_cedula', 'fc', "fc.entity_id = u.uid AND fc.entity_type = 'user' AND fc.deleted = 0");
  $query->addField('fc', 'field_cedula_value', 'dni');

  $query->leftJoin('field_data_field_telefono', 'ft', "ft.entity_id = u.uid AND ft.entity_type = 'user' AND ft.deleted = 0");
  $query->addField('ft', 'field_telefono_value', 'phone');

  $row = $query->execute()->fetchObject();

  return [
    'first_name' => $row && $row->first_name !== NULL && $row->first_name !== '' ? $row->first_name : NULL,
    'last_name'  => $row && $row->last_name !== NULL && $row->last_name !== '' ? $row->last_name : NULL,
    'dni'        => $row && $row->dni !== NULL && $row->dni !== '' ? $row->dni : NULL,
    'phone'      => $row && $row->phone !== NULL && $row->phone !== '' ? $row->phone : NULL,
  ];
}
}

/**
 * Fetches display names for a set of user ids.
 *
 * Uses "$field_nombre $field_apellidos" when both are non-empty; falls back to
 * the full users.name otherwise, so a user with only one of the two parts
 * filled in never answers a hybrid name mixing a real part with a username.
 *
 * This rule used to live in myapi_unit_fetch_user_names()
 * (resources/unit.resource.inc, SPECS 08/09). SPEC 89 moved it here because
 * the service request detail became its second consumer, which is exactly when
 * Rule 3 of CLAUDE.md applies: shared logic goes to includes/, written once.
 * Copying it into the resource would have duplicated it, and calling the unit
 * resource's function from another resource would have broken Rule 5.
 * myapi_unit_fetch_user_names() stays as a one-line wrapper so neither its
 * callers nor the GET /api/v1/units contract had to change.
 *
 * @param array $uids  User ids to fetch.
 *
 * @return array  Map of uid => name. Ids with no users row are absent.
 */
function myapi_user_display_names(array $uids) {
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
