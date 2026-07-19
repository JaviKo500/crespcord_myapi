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
 * @param int $uid  User id.
 *
 * @return array  ['first_name' => string|NULL, 'last_name' => string|NULL,
 *                  'dni' => string|NULL, 'phone' => string|NULL].
 */
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
