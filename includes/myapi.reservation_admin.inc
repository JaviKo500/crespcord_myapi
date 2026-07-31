<?php

/**
 * @file
 * Back-office validation for the 'reservation' content type (SPEC 52).
 *
 * Loaded only from myapi_node_validate() in myapi.module, and only for
 * 'reservation' nodes. Symmetric to includes/myapi.area_admin.inc: it names the
 * two time fields and their labels, and the rule itself lives in
 * includes/myapi.time_format.inc, shared by both bundles.
 *
 * WHY IT EXISTS. SPEC 46 validated the HH:MM format of the area times and left
 * these two fields out, on the premise that reservations are only written by
 * myapi_reservation_create(), which formats them itself. That premise stopped
 * being true: reservations are created and edited from the back office too, and
 * until this file the two fields accepted anything up to five characters —
 * '8:00', '99:99', 'ocho' — because the only validation the bundle had was
 * myapi_building_admin_validate_reservation() (SPEC 51), which checks
 * field_requester and field_unit and returns on its first line for anyone who
 * is not a building admin.
 *
 * A malformed time is not cosmetic: field_start_time_value is compared as a
 * fixed-width string against :now and against the time_from/time_to bounds, is
 * the second ORDER BY column of the listing, and is parsed by
 * myapi_reservation_time_to_minutes().
 *
 * WHAT IT DOES NOT DO. Format, and nothing else. Saving a reservation from the
 * node form still does NOT check overlap with another reservation, the area's
 * concurrent capacity (SPEC 45), the area's opening window, a date in the past,
 * or the cancellation deadline: all of that lives in myapi_reservation_create()
 * and runs on POST /api/v1/reservations only. That gap is real, declared out of
 * scope in SPEC 52, and needs its own spec — do not read this file as "the
 * admin form is validated now".
 *
 * The rule is format-only for a second reason as well: end <= start is legal.
 * A reservation running across midnight is stored wrapped (SPEC 41): start
 * '22:00', end '02:00', never '26:00'. Validating the order would break every
 * one of them.
 */

/**
 * Validates the HH:MM format of the reservation start/end time fields.
 *
 * Called from myapi_node_validate() for 'reservation' nodes only, so it runs on
 * node/add/reservation and node/%/edit — the node form flow. A programmatic
 * node_save() never reaches it, which is what keeps POST /api/v1/reservations
 * untouched: the endpoint saves its node programmatically and never goes
 * through form validation.
 *
 * It applies to EVERY user, administrators included. That is the difference
 * with myapi_building_admin_validate_reservation(), which runs right after it
 * and only for the 'administrador edificio' role: the format of a stored time
 * is not a matter of who typed it.
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
function myapi_reservation_validate_times($node, $form, &$form_state) {
  module_load_include('inc', 'myapi', 'includes/myapi.time_format');

  myapi_time_format_validate_fields($form_state, [
    'field_start_time' => 'Hora de inicio',
    'field_end_time'   => 'Hora de fin',
  ]);
}
