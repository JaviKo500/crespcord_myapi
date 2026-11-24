<?php

/**
 * @file
 * Back-office validation for the 'reservation' content type (SPEC 52, 135).
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
 * THE BUSINESS RULES (SPEC 135). SPEC 52 stopped at the format and left a gap
 * written down in this very docblock: the node form checked none of what
 * POST /api/v1/reservations checks. myapi_reservation_validate_rules() closes
 * it. A reservation saved from the back office now respects the same rules as
 * one created from the app — who may reserve the area, its status, a start in
 * the past, its opening window, its maximum duration, its concurrent capacity
 * and one active reservation per unit and area — read from the same functions,
 * in includes/myapi.reservation_rules.inc.
 *
 * WITH ONE EXCEPTION, AND ONLY ONE: the balance. A unit in debt cannot reserve
 * from the app; an operator can reserve for it. Nothing else is skipped, for
 * any role.
 *
 * The format rule stays format-only for a reason of its own: end <= start is
 * legal. A reservation running across midnight is stored wrapped (SPEC 41):
 * start '22:00', end '02:00', never '26:00'. Validating the order would break
 * every one of them.
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

/* ---------------------------------------------------------------------------
 * Business rules (SPEC 135).
 * ------------------------------------------------------------------------ */

/**
 * The fields whose change makes an edit worth re-validating.
 *
 * Everything a rule reads. field_condominium is not here: no rule reads it,
 * and SPEC 134 already ties it to the unit and the area.
 *
 * @return array  Field machine names.
 */
function myapi_reservation_admin_rule_fields() {
  return [
    'field_unit',
    'field_area',
    'field_requester',
    'field_date',
    'field_start_time',
    'field_end_time',
    'field_reservation_status',
  ];
}

/**
 * Whether a submitted reservation has to go through the business rules.
 *
 * - A reservation that is not being saved as 'confirmed' never does: a
 *   cancelled one occupies nothing, and cancelling is the everyday edit of
 *   this bundle. It must work on a reservation of last year, on an area that
 *   has since been closed, on a slot that is now full.
 * - A new one always does.
 * - An edit does when it touches something a rule reads — moving it, changing
 *   who it is for, or bringing a cancelled one back to 'confirmed'. Saving an
 *   old reservation untouched, or fixing only its cancel reason, does not.
 *
 * Pure.
 *
 * @param bool  $is_new     TRUE for node/add, FALSE for an edit.
 * @param array $submitted  Field machine name => submitted scalar value.
 * @param array $stored     Field machine name => stored scalar value. Ignored
 *                          when $is_new.
 *
 * @return bool
 */
function myapi_reservation_admin_rules_apply($is_new, array $submitted, array $stored) {
  $status = isset($submitted['field_reservation_status']) ? $submitted['field_reservation_status'] : NULL;
  if ($status !== 'confirmed') {
    return FALSE;
  }

  if ($is_new) {
    return TRUE;
  }

  foreach (myapi_reservation_admin_rule_fields() as $field) {
    $new = isset($submitted[$field]) ? (string) $submitted[$field] : '';
    $old = isset($stored[$field]) ? (string) $stored[$field] : '';
    if ($new !== $old) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 * Turns rule violations into the errors the node form shows.
 *
 * The keys are the ones includes/myapi.reservation_rules.inc and the endpoint
 * use. The messages are admin form strings: they do NOT go through myapi_t(),
 * which is the API translation catalogue and speaks to the resident ('No
 * tienes permitido...'), not to an operator booking for somebody else.
 *
 * ONE ERROR PER FIELD. form_set_error() keeps only the first message it is
 * given for a name, so a second violation on the same input would be dropped
 * silently by Drupal; dropping it here keeps that visible and tested. The
 * operator fixes the input and the next save names what is left.
 *
 * An unknown key is skipped, not guessed at.
 *
 * Pure.
 *
 * @param array $violations  Violation keys, in validation order.
 *
 * @return array  List of ['field' => machine name, 'message' => text].
 */
function myapi_reservation_admin_rule_errors(array $violations) {
  $catalogue = [
    'reservation_role_not_allowed' => [
      'field'   => 'field_requester',
      'message' => 'El solicitante no puede reservar esta área: el área limita quién reserva (solo propietarios o solo inquilinos).',
    ],
    'area_not_active' => [
      'field'   => 'field_area',
      'message' => 'El área no está activa y no admite reservas.',
    ],
    'reservation_in_past' => [
      'field'   => 'field_date',
      'message' => 'La fecha y la hora de inicio de la reserva ya pasaron.',
    ],
    'reservation_crosses_midnight' => [
      'field'   => 'field_end_time',
      'message' => 'La reserva cruzaría la medianoche y el área cierra el mismo día.',
    ],
    'reservation_outside_hours' => [
      'field'   => 'field_start_time',
      'message' => 'El horario de la reserva está fuera del horario de atención del área.',
    ],
    'reservation_duration_exceeded' => [
      'field'   => 'field_end_time',
      'message' => 'La duración de la reserva excede la duración máxima permitida para el área.',
    ],
    'reservation_overlap' => [
      'field'   => 'field_start_time',
      'message' => 'El horario se cruza con una reserva existente del área.',
    ],
    'area_capacity_full' => [
      'field'   => 'field_start_time',
      'message' => 'El área ya alcanzó su cupo de reservas simultáneas para ese horario.',
    ],
    'reservation_already_active' => [
      'field'   => 'field_unit',
      'message' => 'La vivienda ya tiene una reserva activa para esta área.',
    ],
  ];

  $errors = [];
  $seen = [];
  foreach ($violations as $key) {
    if (!isset($catalogue[$key]) || isset($seen[$catalogue[$key]['field']])) {
      continue;
    }
    $seen[$catalogue[$key]['field']] = TRUE;
    $errors[] = $catalogue[$key];
  }

  return $errors;
}

/**
 * Applies the business rules of a reservation to the back-office node form.
 *
 * Called LAST from myapi_node_validate() for 'reservation' nodes, for EVERY
 * user who can reach the form — 'administrator', 'backend', 'administrador
 * edificio', 'supervisor', 'pagos': no role skips a rule. Reached from
 * node_form_validate() only, so POST /api/v1/reservations, which applies the
 * same rules itself, is untouched.
 *
 * It stays silent while the form already has an error. The rules read the
 * times as HH:MM and assume the unit, the area and the requester belong
 * together; SPEC 52 and SPEC 134 say so first, and a rule evaluated over
 * values those two refused would only add a second, misleading message.
 *
 * DIFFERENCES WITH THE ENDPOINT, all of them deliberate:
 * - NO BALANCE CHECK. The one rule an operator may skip.
 * - field_date is taken as typed. The app sends the day it shows on its
 *   calendar and the endpoint normalises an early-morning start to D+1
 *   (SPEC 41); here the operator fills the stored field itself, which already
 *   is the clock day of the start.
 * - The end is a field, not a duration: an end at or before the start is the
 *   next day, the stored convention of SPEC 41.
 * - The area lock is held across two steps, validation and save, instead of
 *   inside one function. See myapi_reservation_hold_area_lock().
 * - A value the rules cannot read REFUSES the save instead of skipping them.
 * - An edit leaves its own stored copy out of the capacity and
 *   active-reservation counts.
 *
 * @param object $node
 *   The pseudo-node built from the submitted values.
 * @param array $form
 *   The node form. Unused; kept to mirror the hook.
 * @param array $form_state
 *   The form state, by reference. Unused, same reason.
 */
function myapi_reservation_validate_rules($node, $form, &$form_state) {
  if (form_get_errors()) {
    return;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.time_format');
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_rules');

  $submitted = myapi_reservation_admin_rule_values($node);
  $nid = !empty($node->nid) ? (int) $node->nid : NULL;

  $stored = [];
  if ($nid) {
    $original = node_load($nid);
    $stored = $original ? myapi_reservation_admin_rule_values($original) : [];
  }

  if (!myapi_reservation_admin_rules_apply(!$nid, $submitted, $stored)) {
    return;
  }

  $unit_id = (int) $submitted['field_unit'];
  $area_id = (int) $submitted['field_area'];
  $uid = (int) $submitted['field_requester'];
  $date = (string) $submitted['field_date'];
  $start_time = (string) $submitted['field_start_time'];
  $end_time = (string) $submitted['field_end_time'];

  // FAIL CLOSED. The form reached this point without an error, so Drupal, the
  // Date module, SPEC 52 and SPEC 134 all accepted these values — and a value
  // the rules cannot read would otherwise mean a confirmed reservation saved
  // with no rule applied at all, silently. Refusing it is the only answer that
  // cannot be wrong: a widget changed from the field UI, or a Date module that
  // hands over another shape, shows up on the first save instead of as a
  // double booking weeks later.
  $area = $area_id ? node_load($area_id) : FALSE;
  $unreadable = myapi_reservation_admin_unreadable_fields([
    'field_unit'       => $unit_id > 0,
    'field_requester'  => $uid > 0,
    'field_area'       => $area && $area->type === 'area',
    'field_date'       => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date),
    'field_start_time' => myapi_time_format_is_valid($start_time),
    'field_end_time'   => myapi_time_format_is_valid($end_time),
  ]);
  if ($unreadable) {
    foreach ($unreadable as $field) {
      form_set_error($field, 'No se pudo leer este campo para comprobar las reglas del área, así que la reserva no se guarda. Revise el valor; si es correcto, avise al equipo técnico.');
    }
    return;
  }

  $start_minutes = myapi_reservation_parse_time($start_time);
  $end_minutes = myapi_reservation_parse_time($end_time);
  if ($end_minutes <= $start_minutes) {
    $end_minutes += 1440;
  }

  $open_time = myapi_reservation_node_value($area, 'field_open_time');
  $close_time = myapi_reservation_node_value($area, 'field_close_time');

  $violations = [];

  if (!myapi_reservation_requester_may_reserve(myapi_reservation_node_value($area, 'field_who_can_reserve'), $unit_id, $uid)) {
    $violations[] = 'reservation_role_not_allowed';
  }

  $violations = array_merge($violations, myapi_reservation_schedule_violations(
    myapi_reservation_node_value($area, 'field_area_status'),
    $date,
    $start_time,
    $end_minutes,
    $open_time !== NULL ? myapi_reservation_parse_time($open_time) : NULL,
    $close_time !== NULL ? myapi_reservation_parse_time($close_time) : NULL,
    myapi_reservation_node_value($area, 'field_max_minutes'),
    REQUEST_TIME
  ));

  // The two rules that count stored reservations need the area lock, and need
  // it held until the node is saved. It is only taken when everything above
  // passed: a reservation already refused will not be saved, so its counts
  // are read without a lock, just to name every problem in one go.
  $holds_lock = !$violations;
  if ($holds_lock) {
    myapi_reservation_hold_area_lock($area_id);
  }

  $capacity = myapi_reservation_effective_capacity(myapi_reservation_node_value($area, 'field_concurrent_reservations'));
  if (myapi_reservation_slot_is_full($area_id, $capacity, $date, $start_minutes, $end_minutes, $nid)) {
    $violations[] = $capacity === 1 ? 'reservation_overlap' : 'area_capacity_full';
  }

  if (myapi_reservation_has_active_reservation($unit_id, $area_id, $nid)) {
    $violations[] = 'reservation_already_active';
  }

  // The balance of the unit is deliberately NOT checked (SPEC 135).

  foreach (myapi_reservation_admin_rule_errors($violations) as $error) {
    form_set_error($error['field'], $error['message']);
  }

  // Refused: nothing will be saved, so nobody has to wait behind this request.
  if ($violations && $holds_lock) {
    myapi_reservation_release_area_lock();
  }
}

/**
 * Names the fields the rules could not read.
 *
 * Pure.
 *
 * @param array $readable  Field machine name => whether its value is usable.
 *
 * @return array  The machine names answered FALSE, in the order given.
 */
function myapi_reservation_admin_unreadable_fields(array $readable) {
  return array_keys(array_filter($readable, function ($ok) {
    return !$ok;
  }));
}

/**
 * The transaction that keeps the area lock for the rest of the request.
 *
 * A function static and not drupal_static(): a drupal_static_reset() from
 * anywhere would destroy the object, which commits, which drops the lock in
 * the middle of the save.
 *
 * @return mixed  By reference: the DatabaseTransaction, or NULL when none.
 */
function &myapi_reservation_area_lock_slot() {
  static $transaction = NULL;

  return $transaction;
}

/**
 * Locks an area from the form's validation until its reservation is saved.
 *
 * THE PROBLEM. A node form validates in one step and saves in another. With
 * the lock taken and dropped inside the validation, two operators — or an
 * operator and a resident on the app — could both read "there is room" and
 * both save. The endpoint does not have this problem because it checks and
 * saves inside one transaction; this gives the form the same shape.
 *
 * HOW. A transaction is opened here and kept in a static, and
 * myapi_reservation_lock_area() takes the same row lock the endpoint takes.
 * node_save() then runs nested inside it. It ends in one of three places:
 *  1. myapi_node_insert() / myapi_node_update(), once the reservation is
 *     written. node_save()'s own transaction is still open at that point;
 *     Drupal commits the two together when that one closes, so the lock is
 *     held exactly until the reservation is durable.
 *  2. myapi_reservation_validate_rules() itself, when one of the two counted
 *     rules refuses the reservation.
 *  3. the shutdown function registered below, for every request that validated
 *     and then did not save: another module's validation failed, or the
 *     operator pressed "Vista previa".
 *
 * WHY 3 IS NOT OPTIONAL. Everything the request writes after this point is
 * inside the transaction — the form cache a preview needs, the session that
 * carries the messages. Left to PHP's object destruction order at the end of
 * the request, it could be rolled back instead of committed. The shutdown
 * function commits it while the connection is certainly alive.
 *
 * THE COST. Until one of the three happens, another writer of the same area
 * waits. That is the point, and it lasts one request.
 *
 * @param int $area_id  The 'area' nid.
 */
function myapi_reservation_hold_area_lock($area_id) {
  static $registered = FALSE;

  $transaction = &myapi_reservation_area_lock_slot();
  if ($transaction === NULL) {
    $transaction = db_transaction();
  }

  if (!$registered) {
    drupal_register_shutdown_function('myapi_reservation_release_area_lock');
    $registered = TRUE;
  }

  myapi_reservation_lock_area($area_id);
}

/**
 * Ends the transaction opened by myapi_reservation_hold_area_lock().
 *
 * Commits (Drupal 7 transactions commit when the object is destroyed) and so
 * releases the area lock. A no-op when nothing is held, which is every request
 * that did not validate a reservation form — the reservation endpoints among
 * them, since they never load this file's lock.
 */
function myapi_reservation_release_area_lock() {
  $transaction = &myapi_reservation_area_lock_slot();
  $transaction = NULL;
}

/**
 * Reads from a reservation node the scalar value of every field a rule uses.
 *
 * Works on the pseudo-node of the form and on a node loaded from the database
 * alike: references answer their target id, field_date its 'YYYY-MM-DD' and
 * the rest their value. A field with no value answers NULL.
 *
 * @param object $node  The reservation node.
 *
 * @return array  Field machine name => scalar or NULL, one entry per field of
 *                myapi_reservation_admin_rule_fields().
 */
function myapi_reservation_admin_rule_values($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_rules');

  $references = ['field_unit', 'field_area', 'field_requester'];
  $values = [];

  foreach (myapi_reservation_admin_rule_fields() as $field) {
    if (in_array($field, $references, TRUE)) {
      $values[$field] = myapi_reservation_node_target_id($node, $field);
    }
    elseif ($field === 'field_date') {
      $date = myapi_reservation_node_value($node, $field);
      $values[$field] = is_string($date) ? substr($date, 0, 10) : NULL;
    }
    else {
      $values[$field] = myapi_reservation_node_value($node, $field);
    }

    if (!is_scalar($values[$field])) {
      $values[$field] = NULL;
    }
  }

  return $values;
}
