<?php

/**
 * @file
 * Back-office validation for the 'area' content type (SPEC 46).
 *
 * Loaded only from myapi_node_validate() in myapi.module, and only for 'area'
 * nodes. No endpoint uses this file: areas are read-only over the API, so a
 * malformed time can only enter through the Drupal node form, which is exactly
 * what this validation closes.
 *
 * Since SPEC 52 the file is only the bundle's entry point: it names the two
 * fields and their labels, and the rule itself — the HH:MM pattern, the
 * predicate and the walk over $form_state['values'] — lives in
 * includes/myapi.time_format.inc, shared with the 'reservation' bundle.
 * MYAPI_AREA_TIME_PATTERN and myapi_area_time_is_valid() were moved there
 * unchanged, as MYAPI_TIME_FORMAT_PATTERN and myapi_time_format_is_valid();
 * the behaviour of this form did not change with the move.
 *
 * The rule is format-only. Whether the closing time comes before the opening
 * one is NOT checked: an area that closes past midnight (close <= open) is a
 * legitimate, supported case since SPEC 41.
 */

/**
 * Validates the HH:MM format of the area opening/closing time fields.
 *
 * Called from myapi_node_validate() for 'area' nodes only, so it runs on
 * node/add/area and node/%/edit — the node form flow. A programmatic
 * node_save() never reaches it.
 *
 * The labels are the ones SPEC 32 gave the instances, because they are what the
 * error message names and what the admin reads next to the offending input.
 *
 * @param object $node
 *   The node being validated. Unused; kept to mirror the hook's signature.
 * @param array $form
 *   The node form. Unused, same reason.
 * @param array $form_state
 *   The form state, by reference. Only $form_state['values'] is read.
 */
function myapi_area_validate_times($node, $form, &$form_state) {
  module_load_include('inc', 'myapi', 'includes/myapi.time_format');

  myapi_time_format_validate_fields($form_state, [
    'field_open_time'  => 'Hora de apertura',
    'field_close_time' => 'Hora de cierre',
  ]);
}
