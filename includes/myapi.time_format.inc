<?php

/**
 * @file
 * The HH:MM format rule of the module's node forms (SPEC 52).
 *
 * Single place where the rule lives. SPEC 46 wrote it for the 'area' opening
 * and closing times; SPEC 52 found the 'reservation' start and end times
 * unvalidated in the same admin forms and moved the rule here instead of
 * copying it, so the two bundles cannot drift apart on the next edit.
 *
 * Bundle-agnostic on purpose: this file knows about time strings and about the
 * shape of $form_state['values'], never about which content type is being
 * saved. Each bundle has its own thin entry point that supplies its field map:
 * - 'area' — includes/myapi.area_admin.inc (field_open_time, field_close_time)
 * - 'reservation' — includes/myapi.reservation_admin.inc (field_start_time,
 *   field_end_time)
 *
 * No endpoint uses this file. Areas are read-only over the API and the
 * reservation endpoints format their own values before saving, so a malformed
 * time can only enter through a Drupal node form, which is exactly what this
 * validation closes. resources/reservation.resource.inc still carries its own
 * copy of the pattern for the API path; unifying it is declared out of scope
 * in SPEC 52 (it is not broken, and touching it would drag the endpoint and
 * its tests into a form-only change).
 *
 * The rule is format-only. Whether the second time comes before the first is
 * NOT checked: an area that closes past midnight, and a reservation that runs
 * across it, are legitimate supported cases since SPEC 41 — both are stored
 * wrapped ('22:00' -> '02:00', never '26:00').
 */

// HH:MM on a 24h clock: 00:00 .. 23:59, leading zero required. Guarded so a
// PHPUnit require_once of this file next to one that already defined it does
// not emit a redefinition notice (same pattern as MYAPI_ONESIGNAL_QUEUE in
// myapi.module). Replaces MYAPI_AREA_TIME_PATTERN of SPEC 46, unchanged.
if (!defined('MYAPI_TIME_FORMAT_PATTERN')) {
  define('MYAPI_TIME_FORMAT_PATTERN', '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D');
}

/**
 * Tells whether a string is a well-formed 'HH:MM' time on a 24h clock.
 *
 * The only function that knows the pattern: every caller asks here instead of
 * repeating the regex. Pure — no DB, no Drupal calls — which is what makes
 * tests/unit/TimeFormatTest.php possible with no site booted.
 *
 * Two rejections are deliberate and worth spelling out:
 * - '8:00' — no leading zero. Every stored time is a fixed-width string that
 *   the reservation code parses, compares and sorts by
 *   (myapi_reservation_time_to_minutes(), the string comparisons of the
 *   time_from/time_to filter and the ORDER BY on field_start_time_value), so a
 *   single shape is what keeps that arithmetic predictable. '9:00' would sort
 *   after '10:00'.
 * - '24:00' — outside the clock. End of day is written '23:59'; a range that
 *   really passes midnight is expressed by wrapping the second value below the
 *   first (SPEC 41), never by an hour beyond 23.
 *
 * The empty string returns FALSE, but callers are expected to skip blanks
 * before asking: the field instances are required, and Drupal already reports
 * that on its own.
 *
 * @param string $value
 *   The raw value as typed in the node form, untrimmed.
 *
 * @return bool
 *   TRUE when the value matches HH:MM in [00:00, 23:59].
 */
function myapi_time_format_is_valid($value) {
  return (bool) preg_match(MYAPI_TIME_FORMAT_PATTERN, $value);
}

/**
 * Validates the HH:MM format of a set of submitted time fields.
 *
 * Called from the per-bundle entry points, which are in turn called from
 * myapi_node_validate() — so this runs on node/add/... and node/%/edit, the
 * node form flow. A programmatic node_save() never reaches it.
 *
 * Three details that are easy to get wrong:
 * - Empty values are skipped, not reported. The 'required' flag on the
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
 * form_set_error() accumulates, so every malformed field of the set reports its
 * own message on the same submit, and the caller may keep adding errors of its
 * own afterwards (that is what the 'reservation' bundle does with the SPEC 51
 * scope checks).
 *
 * $form_state is taken by value: only $form_state['values'] is read, and the
 * errors travel through form_set_error()'s own static, not through the array.
 *
 * @param array $form_state
 *   The form state of the node form being validated.
 * @param array $fields
 *   Map of field name => human label, e.g.
 *   ['field_start_time' => 'Hora de inicio']. The label is what the error
 *   message names, so it should match the instance label the admin sees.
 */
function myapi_time_format_validate_fields(array $form_state, array $fields) {
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
        if (!myapi_time_format_is_valid($item['value'])) {
          form_set_error(
            $field_name . '][' . $langcode . '][' . $delta . '][value',
            t('@label: el formato debe ser HH:MM en 24 horas (por ejemplo 08:00 o 22:30).', ['@label' => $label])
          );
        }
      }
    }
  }
}
