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
 * The rule is format-only. Whether the closing time comes before the opening
 * one is NOT checked: an area that closes past midnight (close <= open) is a
 * legitimate, supported case since SPEC 41.
 */

// HH:MM on a 24h clock: 00:00 .. 23:59, leading zero required. Guarded so a
// PHPUnit require_once of this file next to one that already defined it does
// not emit a redefinition notice (same pattern as MYAPI_ONESIGNAL_QUEUE in
// myapi.module).
if (!defined('MYAPI_AREA_TIME_PATTERN')) {
  define('MYAPI_AREA_TIME_PATTERN', '/^([01][0-9]|2[0-3]):[0-5][0-9]$/');
}

/**
 * Tells whether a string is a well-formed 'HH:MM' time on a 24h clock.
 *
 * Single place where the format rule lives: myapi_area_validate_times() calls
 * it, and a future area-write endpoint must reuse it instead of repeating the
 * pattern. Pure function: no DB, no Drupal calls.
 *
 * Two rejections are deliberate and worth spelling out:
 * - '8:00' — no leading zero. Every stored time is a fixed-width string that
 *   the reservation code parses and compares (myapi_reservation_time_to_minutes()
 *   and the string comparisons around it), so a single shape is what keeps that
 *   arithmetic predictable.
 * - '24:00' — outside the clock. End of day is written '23:59'; an area that
 *   really stays open past midnight is an overnight area (SPEC 41), expressed
 *   as close_time <= open_time, never as an hour beyond 23.
 *
 * The empty string returns FALSE, but callers are expected to skip blanks
 * before asking: the field instance is required, and Drupal already reports
 * that on its own.
 *
 * @param string $value
 *   The raw value as typed in the node form, untrimmed.
 *
 * @return bool
 *   TRUE when the value matches HH:MM in [00:00, 23:59].
 */
function myapi_area_time_is_valid($value) {
  return (bool) preg_match(MYAPI_AREA_TIME_PATTERN, $value);
}

/**
 * Validates the HH:MM format of the area opening/closing time fields.
 *
 * Called from myapi_node_validate() for 'area' nodes only, so it runs on
 * node/add/area and node/%/edit — the node form flow. A programmatic
 * node_save() never reaches it.
 *
 * Three details that are easy to get wrong:
 * - Empty values are skipped, not reported. The 'required' flag on both
 *   instances already produces the "field is required" error in
 *   entity_form_field_validate(); reporting it here too would show two messages
 *   for the same field on the same submit.
 * - The trim() is used ONLY to decide whether the value is blank. What gets
 *   matched against the pattern is the raw value, so ' 08:00' is rejected
 *   instead of being silently accepted and stored with the leading space.
 * - The key passed to form_set_error() is the full element path
 *   ('field][langcode][delta][value'), which is what makes Drupal highlight the
 *   offending input; passing just the field name would only print the message
 *   at the top of the page.
 *
 * The values are walked by langcode and delta rather than read straight out of
 * [LANGUAGE_NONE][0]['value'] so nothing breaks silently — with a direct read,
 * a change in language handling or cardinality would skip the validation
 * altogether instead of failing loudly.
 *
 * @param object $node
 *   The node being validated.
 * @param array $form
 *   The node form.
 * @param array $form_state
 *   The form state, by reference. Only $form_state['values'] is read.
 */
function myapi_area_validate_times($node, $form, &$form_state) {
  $fields = [
    'field_open_time'  => 'Hora de apertura',
    'field_close_time' => 'Hora de cierre',
  ];

  foreach ($fields as $field_name => $label) {
    if (empty($form_state['values'][$field_name]) || !is_array($form_state['values'][$field_name])) {
      continue;
    }

    foreach ($form_state['values'][$field_name] as $langcode => $deltas) {
      if (!is_array($deltas)) {
        continue;
      }

      foreach ($deltas as $delta => $item) {
        // Skips the widget's housekeeping keys, such as 'add_more'.
        if (!is_array($item) || !isset($item['value'])) {
          continue;
        }
        if (trim($item['value']) === '') {
          continue;
        }
        if (!myapi_area_time_is_valid($item['value'])) {
          form_set_error(
            $field_name . '][' . $langcode . '][' . $delta . '][value',
            t('@label: el formato debe ser HH:MM en 24 horas (por ejemplo 08:00 o 22:30).', ['@label' => $label])
          );
        }
      }
    }
  }
}
