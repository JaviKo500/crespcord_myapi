<?php

/**
 * @file
 * The business rules of a reservation, shared by its two write paths (SPEC 135).
 *
 * A reservation is written from two places: POST /api/v1/reservations
 * (myapi_reservation_create() in resources/reservation.resource.inc) and the
 * back-office node form (myapi_reservation_validate_rules() in
 * includes/myapi.reservation_admin.inc). Until SPEC 135 every rule below lived
 * inside the resource, so the form could not reach them without breaking rule
 * 5 of CLAUDE.md — and it simply did not apply them: an operator could book an
 * area on top of another reservation, outside its opening hours or in the
 * past.
 *
 * This file is the single definition of each rule. The two callers only decide
 * what to do with the answer: the endpoint aborts on the first violation with
 * its error_code, the form flags every violated input at once.
 *
 * THE ONE RULE THAT IS NOT HERE is the balance check
 * (myapi_reservation_check_balance()). It stays in the resource because the
 * endpoint is its only caller: an operator booking from the back office is
 * allowed to do so for a unit in debt. That is a product decision, not an
 * oversight.
 *
 * The first block is what moved out of the resource unchanged: the field
 * readers, the clock arithmetic of SPEC 41 and the two queries of validations
 * 6 and 7. The second block is new and composes them.
 */

module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
module_load_include('inc', 'myapi', 'includes/myapi.reservation_query');

/**
 * Reads the first 'value' of a single-value Field API field on a node.
 *
 * @param object $node   The node to read from.
 * @param string $field  The field machine name (e.g. 'field_area_status').
 *
 * @return mixed  The stored value, or NULL if the field has no value.
 */
function myapi_reservation_node_value($node, $field) {
  return isset($node->{$field}[LANGUAGE_NONE][0]['value'])
    ? $node->{$field}[LANGUAGE_NONE][0]['value']
    : NULL;
}

/**
 * Reads the first 'target_id' of a single-value entity-reference field.
 *
 * @param object $node   The node to read from.
 * @param string $field  The field machine name (e.g. 'field_condominio').
 *
 * @return int|NULL  The referenced entity id, or NULL if the field is empty.
 */
function myapi_reservation_node_target_id($node, $field) {
  return isset($node->{$field}[LANGUAGE_NONE][0]['target_id'])
    ? $node->{$field}[LANGUAGE_NONE][0]['target_id']
    : NULL;
}

/**
 * Reads field_date's plain 'Y-m-d', regardless of whether $node is the
 * in-memory node built by myapi_reservation_build_node() (value already
 * 'Y-m-d') or one freshly loaded from the DB (Date field, full
 * 'Y-m-d H:i:s' value) — same truncation as every SQL read of this field
 * (SUBSTR(field_date_value, 1, 10)) in this file.
 *
 * @param object $node  The reservation node to read from.
 *
 * @return string|NULL  'Y-m-d', or NULL if the field has no value.
 */
function myapi_reservation_node_date($node) {
  $value = myapi_reservation_node_value($node, 'field_date');
  return $value !== NULL ? substr($value, 0, 10) : NULL;
}

/**
 * Fetches the 'confirmed' reservations of an area that could touch a candidate
 * on day D, projected onto the absolute minute axis (SPEC 41).
 *
 * Until SPEC 45 this was myapi_reservation_has_overlap() and answered a
 * yes/no question. Validation 6 now enforces a capacity of N simultaneous
 * reservations instead of rejecting on the first overlap, so it needs the
 * INTERVALS themselves to compute the concurrency peak. The query is unchanged;
 * only what is done with the rows is.
 *
 * Each reservation is projected onto an absolute axis anchored at the midnight
 * of its own field_date (myapi_reservation_abs_interval), so same-day,
 * midnight-crossing and early-morning reservations all compare uniformly. To
 * catch the tails that a single-date query would miss, reservations are fetched
 * for three days: field_date IN (D-1, D, D+1). D-1 covers a previous-day
 * reservation whose tail runs past midnight into $date; D+1 covers an
 * early-morning reservation of the next day that a crossing candidate could
 * hit. The per-area/day volume stays low, so interval math is done in PHP.
 *
 * The candidate's own range is NOT used to filter here: the caller projects it
 * with myapi_reservation_abs_interval() and hands both to
 * myapi_reservation_peak_concurrency(), which applies the half-open criterion
 * (unchanged from SPEC 35, so back-to-back reservations still do not overlap).
 * $start_minutes/$end_minutes are kept in the signature per SPEC 45 so the call
 * site reads the same as before the rename.
 *
 * @param int    $area_id       The 'area' nid.
 * @param string $date          Normalized 'YYYY-MM-DD' of the candidate (D).
 * @param int    $start_minutes Candidate start, minutes since midnight.
 * @param int    $end_minutes   Candidate end, minutes since midnight (unbounded
 *                              when the candidate crosses midnight).
 * @param int|null $exclude_nid A reservation to leave out (SPEC 135): the one
 *                              being edited from the back office. NULL on the
 *                              endpoint, which only ever creates.
 *
 * @return array  List of [start_abs, end_abs] pairs, absolute minutes, one per
 *                confirmed reservation of the three days. Empty when none.
 */
function myapi_reservation_fetch_abs_intervals($area_id, $date, $start_minutes, $end_minutes, $exclude_nid = NULL) {
  $prev = date('Y-m-d', strtotime($date . ' -1 day'));
  $next = date('Y-m-d', strtotime($date . ' +1 day'));

  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);
  if ($exclude_nid) {
    $query->condition('n.nid', (int) $exclude_nid, '<>');
  }

  $query->innerJoin('field_data_field_area', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->condition('fa.field_area_target_id', $area_id);

  $query->innerJoin('field_data_field_reservation_status', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->condition('fst.field_reservation_status_value', 'confirmed');

  $query->innerJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->where('SUBSTR(fd.field_date_value, 1, 10) IN (:dates)', [':dates' => [$prev, $date, $next]]);
  $query->addField('fd', 'field_date_value', 'date');

  $query->innerJoin('field_data_field_start_time', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->addField('fs', 'field_start_time_value', 'start_time');

  $query->innerJoin('field_data_field_end_time', 'fe', "fe.entity_id = n.nid AND fe.entity_type = 'node' AND fe.deleted = 0");
  $query->addField('fe', 'field_end_time_value', 'end_time');

  $intervals = [];
  $rows = $query->execute()->fetchAll();
  foreach ($rows as $existing) {
    $existing_date = substr($existing->date, 0, 10);
    $existing_start = myapi_reservation_parse_time($existing->start_time);
    $existing_end = myapi_reservation_parse_time($existing->end_time);
    $intervals[] = myapi_reservation_abs_interval($existing_date, $existing_start, $existing_end);
  }

  return $intervals;
}

/**
 * Checks whether a unit already has a 'confirmed' reservation for the same
 * area whose start date/time has not passed yet.
 *
 * @param int $unit_id  The 'vivienda' nid.
 * @param int $area_id  The 'area' nid.
 * @param int|null $exclude_nid  A reservation to leave out (SPEC 135): the one
 *                               being edited from the back office.
 *
 * @return bool  TRUE when such a reservation exists.
 */
function myapi_reservation_has_active_reservation($unit_id, $area_id, $exclude_nid = NULL) {
  $today = date('Y-m-d', REQUEST_TIME);
  $now = date('H:i', REQUEST_TIME);

  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);
  if ($exclude_nid) {
    $query->condition('n.nid', (int) $exclude_nid, '<>');
  }

  $query->innerJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->condition('fu.field_unit_target_id', $unit_id);

  $query->innerJoin('field_data_field_area', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->condition('fa.field_area_target_id', $area_id);

  $query->innerJoin('field_data_field_reservation_status', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->condition('fst.field_reservation_status_value', 'confirmed');

  $query->innerJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->innerJoin('field_data_field_start_time', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->where(
    '(SUBSTR(fd.field_date_value, 1, 10) > :today) OR (SUBSTR(fd.field_date_value, 1, 10) = :today AND fs.field_start_time_value > :now)',
    [':today' => $today, ':now' => $now]
  );

  return (bool) $query->countQuery()->execute()->fetchField();
}

/**
 * Converts an 'HH:MM' time string into minutes since midnight.
 *
 * Assumes $hhmm already matches the 'HH:MM' 24h format (validated at the
 * request-body stage before this is ever called).
 *
 * @param string $hhmm  Time in 'HH:MM' format.
 *
 * @return int  Minutes since midnight (0-1439).
 */
function myapi_reservation_parse_time($hhmm) {
  list($hours, $minutes) = explode(':', $hhmm);
  return ((int) $hours) * 60 + (int) $minutes;
}

/**
 * Adds a number of minutes to an 'HH:MM' time string.
 *
 * Deliberately does not wrap around at 24:00: a result of 24:00 or later is
 * returned as-is (e.g. '25:30'). The midnight crossing is derived afterwards
 * by comparison (SPEC 41): a same-day area rejects it via
 * myapi_reservation_crosses_disallowed(), while an area that closes after
 * midnight accepts it through the extended-window check.
 *
 * @param string $hhmm     Start time in 'HH:MM' format.
 * @param int    $minutes  Minutes to add (duration_minutes from the body).
 *
 * @return string  Resulting time in 'HH:MM' format, hours unbounded.
 */
function myapi_reservation_add_minutes($hhmm, $minutes) {
  $total = myapi_reservation_parse_time($hhmm) + (int) $minutes;
  $hours = (int) ($total / 60);
  $mins = $total % 60;
  return sprintf('%02d:%02d', $hours, $mins);
}

/**
 * Wraps a minutes-since-midnight value back into a 24h 'HH:MM' clock string.
 *
 * The counterpart to myapi_reservation_add_minutes(): where that returns the
 * unbounded value ('26:00') needed by the opening-hours math, this returns the
 * clock time actually persisted on the node ('02:00'), so a range crossing
 * midnight is stored as a real time-of-day and its crossing is derived
 * downstream by comparison (end <= start) — the convention the reservation
 * list and GET /api/v1/areas/%/availability (SPEC 40) both rely on.
 *
 * Pure arithmetic helper: no DB, no Drupal calls.
 *
 * @param int $minutes  Minutes since midnight, possibly >= 1440.
 *
 * @return string  Clock time in 'HH:MM', wrapped into [00:00, 24:00).
 */
function myapi_reservation_wrap_time($minutes) {
  $wrapped = (((int) $minutes % 1440) + 1440) % 1440;
  return sprintf('%02d:%02d', (int) ($wrapped / 60), $wrapped % 60);
}

/**
 * Determines whether an area's opening hours wrap past midnight.
 *
 * An area "wraps" when its close time is at or before its open time on the
 * clock (e.g. open 12:00, close 02:00), meaning it stays open into the early
 * hours of the next calendar day. A normal area (close strictly after open)
 * never wraps.
 *
 * Pure arithmetic helper: no DB, no Drupal calls, unit-testable in isolation
 * (SPEC 41).
 *
 * @param int $open_min   field_open_time as minutes since midnight.
 * @param int $close_min  field_close_time as minutes since midnight.
 *
 * @return bool  TRUE when the area closes after midnight.
 */
function myapi_reservation_area_wraps($open_min, $close_min) {
  return $close_min <= $open_min;
}

/**
 * Normalizes the clock day (field_date) a reservation is stored under.
 *
 * The client always sends date = D (the day it shows on the calendar). When
 * the area wraps past midnight AND the start falls in the early-morning tail
 * [00:00, close) — e.g. a 01:00 start in a 12:00-02:00 area — the reservation
 * actually belongs to the next calendar day, so field_date is normalized to
 * D+1 (same clock-day convention SPEC 40 already uses to show the crossing).
 * Every other case keeps D: a non-wrapping area never normalizes, and neither
 * does the dead gap between close and open (e.g. 05:00 in 12:00-02:00, where
 * start_min < close_min is false), which correctly stays out of hours.
 *
 * Pure arithmetic + date() helper: no DB, no Drupal calls (SPEC 41).
 *
 * @param string $date       'YYYY-MM-DD' as sent by the client (day D).
 * @param int    $start_min  Reservation start, minutes since midnight.
 * @param int    $open_min   field_open_time as minutes since midnight.
 * @param int    $close_min  field_close_time as minutes since midnight.
 *
 * @return string  'YYYY-MM-DD': D+1 when normalized, otherwise D.
 */
function myapi_reservation_effective_date($date, $start_min, $open_min, $close_min) {
  if (myapi_reservation_area_wraps($open_min, $close_min) && $start_min < $close_min) {
    return date('Y-m-d', strtotime($date . ' +1 day'));
  }
  return $date;
}

/**
 * Projects a reservation onto an absolute minute axis anchored at the midnight
 * of its stored field_date.
 *
 * The end is carried one day forward when it wraps below the start
 * (end_min <= start_min, the derived-cross representation of a stored
 * reservation such as 20:00 -> 02:00), so a candidate and every existing
 * reservation can be compared on a single continuous axis regardless of
 * whether either crosses midnight (SPEC 41, validation 6).
 *
 * Pure arithmetic + strtotime() helper: no DB, no Drupal calls.
 *
 * @param string $date       'YYYY-MM-DD' the reservation is stored under.
 * @param int    $start_min  Start, minutes since midnight.
 * @param int    $end_min    End, minutes since midnight (may be <= start_min
 *                           when the stored end already wrapped past midnight).
 *
 * @return array  [start_abs, end_abs] in absolute minutes.
 */
function myapi_reservation_abs_interval($date, $start_min, $end_min) {
  $base = (int) (strtotime($date . ' 00:00:00') / 60);
  $end_abs = $base + $end_min + ($end_min <= $start_min ? 1440 : 0);
  return [$base + $start_min, $end_abs];
}

/**
 * Tells whether a candidate range crosses midnight in an area that does NOT
 * wrap — the case that must be rejected with reservation_crosses_midnight
 * before the opening-hours window is even evaluated (SPEC 41, validation 4).
 *
 * A range crosses midnight when its end lands at or past 24:00 (end_min >=
 * 1440, the unbounded representation produced by
 * myapi_reservation_add_minutes()) or wraps below its start (end_min <=
 * start_min). When the area itself wraps past midnight, crossing is legal, so
 * this returns FALSE.
 *
 * Pure arithmetic helper: no DB, no Drupal calls.
 *
 * @param int $start_min  Candidate start, minutes since midnight.
 * @param int $end_min    Candidate end, minutes since midnight (unbounded).
 * @param int $open_min   field_open_time as minutes since midnight.
 * @param int $close_min  field_close_time as minutes since midnight.
 *
 * @return bool  TRUE when the range crosses midnight but the area closes the
 *               same clock day.
 */
function myapi_reservation_crosses_disallowed($start_min, $end_min, $open_min, $close_min) {
  if (myapi_reservation_area_wraps($open_min, $close_min)) {
    return FALSE;
  }
  return $end_min >= 1440 || $end_min <= $start_min;
}

/**
 * Checks whether a candidate range falls within an area's opening hours,
 * handling areas that wrap past midnight (SPEC 41, validation 4).
 *
 * A non-wrapping area keeps the original linear check (start at or after open,
 * end at or before close). A wrapping area is evaluated on an extended axis
 * [open_min, close_min + 1440]: a start in the early-morning tail
 * ([00:00, close)) is projected forward by 24h, and the end is projected onto
 * that same frame as start_eff + (end_min - start_min) — for the candidate,
 * end_min - start_min is exactly the duration, so this rejects a tail start
 * whose duration overruns the projected close (e.g. 01:00 + 120min -> 03:00 in
 * a 12:00-02:00 area) instead of wrongly accepting it.
 *
 * Pure arithmetic helper: no DB, no Drupal calls.
 *
 * @param int $start_min  Candidate start, minutes since midnight.
 * @param int $end_min    Candidate end, minutes since midnight (unbounded).
 * @param int $open_min   field_open_time as minutes since midnight.
 * @param int $close_min  field_close_time as minutes since midnight.
 *
 * @return bool  TRUE when the range fits inside the area's opening hours.
 */
function myapi_reservation_within_hours($start_min, $end_min, $open_min, $close_min) {
  if (!myapi_reservation_area_wraps($open_min, $close_min)) {
    return $start_min >= $open_min && $end_min <= $close_min;
  }

  $start_eff = ($start_min < $close_min) ? $start_min + 1440 : $start_min;
  $end_eff = $start_eff + ($end_min - $start_min);
  return $start_eff >= $open_min && $end_eff <= $close_min + 1440;
}

/**
 * Whether a person may reserve an area for a unit, given who the area is for.
 *
 * Validation 1 of SPEC 35. 'owner' and 'tenant' must match the person's
 * relation to the unit; any other value — 'both', empty, something a later
 * release adds — allows either.
 *
 * The person is the reservation's REQUESTER: the authenticated user on the
 * endpoint, the user picked in field_requester on the back-office form. The
 * role of the operator who types the reservation is never looked at.
 *
 * @param mixed $who_can_reserve  The area's field_who_can_reserve, or NULL.
 * @param int   $unit_id          The 'vivienda' nid.
 * @param int   $uid              The requester's uid.
 *
 * @return bool  TRUE when the requester may reserve the area for that unit.
 */
function myapi_reservation_requester_may_reserve($who_can_reserve, $unit_id, $uid) {
  if ($who_can_reserve === 'owner') {
    return in_array($unit_id, myapi_user_owned_unit_nids($uid));
  }
  if ($who_can_reserve === 'tenant') {
    return in_array($unit_id, myapi_user_occupied_unit_nids($uid));
  }

  return TRUE;
}

/**
 * Checks a candidate against the area's own rules: status, clock and length.
 *
 * Validations 2 to 5 of SPEC 35, in that order. Every violated rule is
 * returned, so the endpoint can abort on the first one and the form can show
 * them all — with one exception: a range that crosses midnight in an area that
 * closes the same day is reported as that and NOT also as out of hours, which
 * it always is and which would only repeat the same mistake in other words.
 *
 * An area missing its opening or its closing time fails closed, as out of
 * hours.
 *
 * Pure: no DB, no Drupal, no clock — "now" is an argument.
 *
 * @param mixed    $area_status  The area's field_area_status.
 * @param string   $date         'YYYY-MM-DD', the clock day of the start.
 * @param string   $start_time   'HH:MM'.
 * @param int      $end_min      End in minutes since the midnight of $date,
 *                               UNBOUNDED: 1500 for 01:00 of the next day.
 * @param int|null $open_min     field_open_time in minutes, or NULL.
 * @param int|null $close_min    field_close_time in minutes, or NULL.
 * @param mixed    $max_minutes  The area's field_max_minutes, or NULL for no
 *                               limit.
 * @param int      $now          Unix timestamp to compare the start against.
 *
 * @return array  Violation keys, in validation order. Empty when the candidate
 *                respects every rule. The keys are the endpoint's error codes,
 *                except 'reservation_in_past', which the endpoint answers as
 *                invalid_field on 'date'.
 */
function myapi_reservation_schedule_violations($area_status, $date, $start_time, $end_min, $open_min, $close_min, $max_minutes, $now) {
  $violations = [];
  $start_min = myapi_reservation_parse_time($start_time);

  if ($area_status !== 'active') {
    $violations[] = 'area_not_active';
  }

  $start_timestamp = strtotime($date . ' ' . $start_time . ':00');
  if ($start_timestamp === FALSE || $start_timestamp < $now) {
    $violations[] = 'reservation_in_past';
  }

  if ($open_min === NULL || $close_min === NULL) {
    $violations[] = 'reservation_outside_hours';
  }
  elseif (myapi_reservation_crosses_disallowed($start_min, $end_min, $open_min, $close_min)) {
    $violations[] = 'reservation_crosses_midnight';
  }
  elseif (!myapi_reservation_within_hours($start_min, $end_min, $open_min, $close_min)) {
    $violations[] = 'reservation_outside_hours';
  }

  if ($max_minutes !== NULL && ($end_min - $start_min) > (int) $max_minutes) {
    $violations[] = 'reservation_duration_exceeded';
  }

  return $violations;
}

/**
 * Takes the row lock that serialises every write of a reservation on an area.
 *
 * SELECT ... FOR UPDATE on the area's node row. It only means something inside
 * a transaction the caller keeps open until its node_save() is done: two
 * writers of the same area queue on this row, so the second one runs its
 * capacity and active-reservation checks only after the first has committed,
 * instead of both reading the same "there is still room" and both inserting.
 *
 * BOTH write paths take this same lock, which is what makes them exclude each
 * other and not just themselves: the endpoint around validations 6 to 8, the
 * back-office form through myapi_reservation_hold_area_lock() (SPEC 135).
 *
 * Requires the node table to be InnoDB (Drupal 7's default).
 *
 * @param int $area_id  The 'area' nid.
 */
function myapi_reservation_lock_area($area_id) {
  db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.nid', $area_id)
    ->forUpdate()
    ->execute()
    ->fetchField();
}

/**
 * Whether adding a candidate would push an area above its concurrent capacity.
 *
 * Validation 6 of SPEC 35 as SPEC 45 left it. Existing reservations are
 * compared on an absolute axis across D-1/D/D+1, so midnight-crossing tails
 * are caught (SPEC 41).
 *
 * CHECK-THEN-ACT. The answer is only as good as the moment it is read, so
 * both callers ask it while holding myapi_reservation_lock_area() and keep
 * holding it until their node is saved. Calling this without that lock turns
 * the capacity into a suggestion.
 *
 * @param int      $area_id      The 'area' nid.
 * @param int      $capacity     Effective capacity, from
 *                               myapi_reservation_effective_capacity().
 * @param string   $date         'YYYY-MM-DD', the clock day of the start.
 * @param int      $start_min    Start, minutes since midnight.
 * @param int      $end_min      End, minutes since midnight (unbounded, or
 *                               wrapped below the start: both are projected).
 * @param int|null $exclude_nid  A reservation to leave out of the count: the
 *                               one being edited, which must not collide with
 *                               its own stored copy.
 *
 * @return bool  TRUE when the slot has no room for the candidate.
 */
function myapi_reservation_slot_is_full($area_id, $capacity, $date, $start_min, $end_min, $exclude_nid = NULL) {
  $intervals = myapi_reservation_fetch_abs_intervals($area_id, $date, $start_min, $end_min, $exclude_nid);
  list($cand_start_abs, $cand_end_abs) = myapi_reservation_abs_interval($date, $start_min, $end_min);

  // The peak can never exceed the number of reservations overlapping the
  // candidate, so fewer overlaps than slots is already an accept. Exact, not an
  // approximation: it just keeps the happy path — most requests — out of the
  // O(n^2) scan. Same half-open criterion as everywhere else (SPEC 35).
  $overlapping = 0;
  foreach ($intervals as $interval) {
    if ($interval[0] < $cand_end_abs && $interval[1] > $cand_start_abs) {
      $overlapping++;
    }
  }

  if ($overlapping < $capacity) {
    return FALSE;
  }

  // The +1 is the candidate itself: rejecting means it would push some instant
  // of its own window above the area's capacity. Counting the reservations
  // that overlap would be wrong here — two bookings can both overlap the
  // candidate and never coincide with each other.
  return myapi_reservation_peak_concurrency($intervals, $cand_start_abs, $cand_end_abs) + 1 > $capacity;
}
