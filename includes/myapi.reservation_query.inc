<?php

/**
 * @file
 * Shared reservation-domain query helpers for the myapi module.
 *
 * These helpers read confirmed reservations of an area without any requester
 * filter, so read-only endpoints (e.g. area availability) can report the busy
 * time ranges of an area for a given day.
 *
 * Since SPEC 45 the file also hosts the pure concurrent-capacity helpers
 * (effective_capacity, peak_concurrency, occupancy_ranges), which the write
 * path shares with availability: both must answer "how many reservations may
 * coincide here?" with the same rule, and duplicating it is exactly what rule 5
 * of CLAUDE.md forbids. They remain pure — no DB, no Drupal — so the whole file
 * stays unit-testable without bootstrapping the site.
 */

/**
 * Parses an 'HH:MM' time string into minutes since midnight.
 *
 * Self-contained on purpose: this file must be unit-testable without booting
 * Drupal or loading the reservation resource. Mirrors the existing criterion
 * ((int)HH * 60 + (int)MM), robust to formatting.
 *
 * @param string $hhmm  Time in 'HH:MM' format.
 *
 * @return int  Minutes since midnight (0-1439).
 */
function myapi_reservation_time_to_minutes($hhmm) {
  list($hours, $minutes) = explode(':', $hhmm);
  return ((int) $hours) * 60 + (int) $minutes;
}

/**
 * Normalises the raw field_concurrent_reservations value of an area into
 * its EFFECTIVE capacity: how many reservations may coincide in the same slot.
 *
 * The rule is deliberately FAIL-CLOSED:
 *   - No row in the field table, or NULL  -> 1.
 *   - 0 or a negative value               -> 1.
 *   - n >= 1                              -> n.
 *
 * NULL never means "unlimited". Every area that existed before SPEC 45 has no
 * value stored, and reading that as unlimited would turn an admin forgetting to
 * fill the field into the silent disappearance of the overlap check in
 * production. Capacity 1 is exactly the single-reservation behaviour those
 * areas already had, so an empty field is a no-op.
 *
 * A 0 does not close the area either: closing is what field_area_status is for
 * (SPEC 33/39), and the field description says so to the admin.
 *
 * This is the ONLY place the rule lives. Its three callers — validation 6 of
 * POST /api/v1/reservations, myapi_area_build_item() and
 * myapi_area_availability() — must never re-implement the ternary, and neither
 * must the Flutter client: the item exposes the value already normalised so
 * server and app can never disagree.
 *
 * @param mixed $raw  Raw column value (int, numeric string or NULL).
 *
 * @return int  Effective capacity, always >= 1.
 */
function myapi_reservation_effective_capacity($raw) {
  return ($raw === NULL || (int) $raw < 1) ? 1 : (int) $raw;
}

/**
 * Returns the MAXIMUM number of existing reservations active at the same
 * instant at some point inside the candidate's window.
 *
 * This is the question validation 6 really has to answer. Counting how many
 * existing reservations overlap the candidate is NOT the same thing and is
 * wrong: with 10:00-11:00 and 13:00-14:00 already booked, a 10:00-14:00
 * candidate overlaps two of them, yet those two are never active together, so
 * the peak is 1 and one free slot is enough to accept it.
 *
 * Algorithm:
 *   1. Keep only the intervals overlapping the candidate, with the module's
 *      HALF-OPEN criterion: s < cand_end && e > cand_start.
 *   2. None left -> 0.
 *   3. Points to evaluate: cand_start, plus the start of every overlapping
 *      interval strictly inside the window.
 *   4. For each point t, count the intervals with s <= t && e > t.
 *   5. Return the maximum.
 *
 * Half-open means a reservation ending exactly when another starts is NOT
 * simultaneous, the same rule under which back-to-back bookings have been
 * accepted since SPEC 35 and which SPEC 41 kept across midnight.
 *
 * Evaluating only the starts is exact, not an approximation: concurrency goes
 * UP only at a start and DOWN only at an end, so the maximum inside the window
 * necessarily happens either at cand_start or right at one of those starts.
 *
 * O(n^2) on purpose: n is a handful of rows per area and day, and this version
 * is far easier to reason about and to test than an event queue.
 *
 * Pure function (no DB, no Drupal). It receives intervals ALREADY projected
 * onto the absolute-minute axis of SPEC 41 — see
 * myapi_reservation_abs_interval() in resources/reservation.resource.inc — so
 * midnight crossings are already resolved and need no special case here.
 *
 * @param array $intervals   List of [start_abs, end_abs] pairs, absolute
 *                           minutes, of the existing confirmed reservations.
 * @param int   $cand_start  Candidate start, absolute minutes.
 * @param int   $cand_end    Candidate end, absolute minutes.
 *
 * @return int  Peak of simultaneous existing reservations, 0 when none
 *              overlaps. The candidate itself is NOT counted.
 */
function myapi_reservation_peak_concurrency($intervals, $cand_start, $cand_end) {
  // (1) Overlapping intervals only, half-open criterion.
  $overlapping = array();
  foreach ($intervals as $interval) {
    list($start, $end) = $interval;
    if ($start < $cand_end && $end > $cand_start) {
      $overlapping[] = $interval;
    }
  }

  // (2) Nothing overlaps the window: the slot is free.
  if (!$overlapping) {
    return 0;
  }

  // (3) The window start, plus every start strictly inside it.
  $points = array($cand_start);
  foreach ($overlapping as $interval) {
    if ($interval[0] > $cand_start && $interval[0] < $cand_end) {
      $points[] = $interval[0];
    }
  }

  // (4)-(5) Highest count over the evaluated points.
  $peak = 0;
  foreach ($points as $point) {
    $active = 0;
    foreach ($overlapping as $interval) {
      if ($interval[0] <= $point && $interval[1] > $point) {
        $active++;
      }
    }
    if ($active > $peak) {
      $peak = $active;
    }
  }

  return $peak;
}

/**
 * Fetches the raw rows of confirmed, published reservations of an area whose
 * field_date falls on any of the given days.
 *
 * Same base as myapi_reservation_fetch_abs_intervals() but WITHOUT the
 * requester filter and WITHOUT the D-1/D+1 window: it reports every confirmed
 * booking
 * of the area (all units of the condominium) across the given dates. Rows
 * missing any of field_date / field_start_time / field_end_time are excluded by
 * the inner joins, consistent with the write path.
 *
 * @param int   $area_id  The 'area' nid.
 * @param array $dates    List of 'YYYY-MM-DD' days to match against field_date.
 *
 * @return array  Raw rows (stdClass with date, start_time, end_time). Empty
 *   array when there are no matching reservations.
 */
function myapi_reservation_busy_rows($area_id, array $dates) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_area', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->condition('fa.field_area_target_id', $area_id);

  $query->innerJoin('field_data_field_reservation_status', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->condition('fst.field_reservation_status_value', 'confirmed');

  $query->innerJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->where('SUBSTR(fd.field_date_value, 1, 10) IN (:dates)', array(':dates' => $dates));
  $query->addExpression('SUBSTR(fd.field_date_value, 1, 10)', 'date');

  $query->innerJoin('field_data_field_start_time', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->addField('fs', 'field_start_time_value', 'start_time');

  $query->innerJoin('field_data_field_end_time', 'fe', "fe.entity_id = n.nid AND fe.entity_type = 'node' AND fe.deleted = 0");
  $query->addField('fe', 'field_end_time_value', 'end_time');

  return $query->execute()->fetchAll();
}

/**
 * Derives the busy date/time ranges of an area's SESSION for a requested day.
 *
 * A "session" is the area's operating window for day D. For a normal area
 * (close_min > open_min) the session is the calendar day itself: only
 * reservations stored under $date belong to it and none cross midnight.
 *
 * For an area that wraps past midnight (close_min <= open_min) the session runs
 * [D open, D+1 close] and is assembled from two stored slices, because the
 * create path stores every reservation under the CLOCK day of its start:
 *   - field_date = D   with start >= open  — the evening/late-night part; a
 *     booking passing midnight has end <= start and ends on D+1.
 *   - field_date = D+1 with start <  close — the early-morning tail, stored
 *     under its own clock day.
 * Conversely, a field_date = D row with start < close is the tail of the
 * PREVIOUS session, and a field_date = D+1 row with start >= close is the
 * evening of the NEXT session; both are excluded here so each reservation
 * belongs to exactly one session.
 *
 * Pure function (no DB, no Drupal): unit-testable in isolation. The caller
 * fetches $date and (only when the area wraps) $next_date. $open_min/$close_min
 * may be NULL when the area has no hours row, in which case it degrades to a
 * plain calendar-day (non-wrapping) session. The result is sorted ascending by
 * (start_date, start_time).
 *
 * @param array    $rows       Raw rows (stdClass: date, start_time, end_time).
 * @param string   $date       Requested session day, 'YYYY-MM-DD' (D).
 * @param string   $next_date  The day after, 'YYYY-MM-DD' (D+1).
 * @param int|NULL $open_min   Area open time in minutes since midnight, or NULL.
 * @param int|NULL $close_min  Area close time in minutes since midnight, or NULL.
 *
 * @return array  List of items with exactly 4 keys:
 *   start_date, start_time, end_date, end_time. Empty array when none applies.
 */
function myapi_reservation_busy_ranges($rows, $date, $next_date, $open_min = NULL, $close_min = NULL) {
  $wraps = $open_min !== NULL && $close_min !== NULL && $close_min <= $open_min;
  $busy = array();

  foreach ($rows as $row) {
    $start_minutes = myapi_reservation_time_to_minutes($row->start_time);
    $end_minutes = myapi_reservation_time_to_minutes($row->end_time);

    if (!$wraps) {
      // Plain calendar-day session: only rows stored under $date apply.
      if ($row->date !== $date) {
        continue;
      }
    }
    elseif ($row->date === $date) {
      // Evening/late-night slice of the session; a start before open is the
      // tail of the previous session and does not belong here.
      if ($start_minutes < $open_min) {
        continue;
      }
    }
    elseif ($row->date === $next_date) {
      // Early-morning tail of the session; a start at or after close is the
      // evening of the next session and does not belong here.
      if ($start_minutes >= $close_min) {
        continue;
      }
    }
    else {
      continue;
    }

    $crosses = $end_minutes <= $start_minutes;
    $busy[] = array(
      'start_date' => $row->date,
      'start_time' => $row->start_time,
      'end_date' => $crosses ? date('Y-m-d', strtotime($row->date . ' +1 day')) : $row->date,
      'end_time' => $row->end_time,
    );
  }

  usort($busy, 'myapi_reservation_busy_range_compare');

  return $busy;
}

/**
 * Comparator for busy ranges: ascending by (start_date, start_time).
 *
 * @param array $a  Busy range item.
 * @param array $b  Busy range item.
 *
 * @return int  Negative, zero or positive per usort() contract.
 */
function myapi_reservation_busy_range_compare($a, $b) {
  if ($a['start_date'] !== $b['start_date']) {
    return strcmp($a['start_date'], $b['start_date']);
  }
  return strcmp($a['start_time'], $b['start_time']);
}

/**
 * Splits an area's session into ranges of CONSTANT occupancy, each carrying how
 * many reservations are active in it and how many slots are left.
 *
 * Input is the output of myapi_reservation_busy_ranges(): items of exactly 4
 * keys (start_date, start_time, end_date, end_time), already filtered to the
 * session and sorted. The axis is cut at every boundary — every start and every
 * end — and each resulting range is emitted with its own counters:
 *
 *   {start_date, start_time, end_date, end_time, reserved, remaining}
 *
 *   - reserved  — number of reservations active in that range.
 *   - remaining — max(0, capacity - reserved). NEVER negative: an admin may
 *     lower the capacity of an area that already has reservations, and those
 *     are respected until they pass; the area simply accepts no new ones until
 *     occupancy drops.
 *
 * Ranges with reserved = 0 are not emitted (a gap between bookings is not
 * occupancy), so the output is not necessarily contiguous. An empty input gives
 * an empty list. Output is sorted ascending by (start_date, start_time).
 *
 * Only counters travel out: no id, no unit_id, no requester_id, no names. The
 * privacy rule of SPEC 40 stands — the requester learns THAT a slot is taken,
 * never by whom.
 *
 * Boundaries are compared on the absolute-minute axis so a session crossing
 * midnight orders correctly, but each boundary is labelled back with the exact
 * date/time strings it came from, which keeps the output free of any date
 * arithmetic (and therefore of DST edge cases).
 *
 * Pure function (no DB, no Drupal).
 *
 * @param array $busy      Busy ranges of the session, as returned by
 *                         myapi_reservation_busy_ranges().
 * @param int   $capacity  Effective capacity of the area, always >= 1 (see
 *                         myapi_reservation_effective_capacity()).
 *
 * @return array  List of occupancy ranges with 6 keys each. Empty array when
 *                the session has no reservations.
 */
function myapi_reservation_occupancy_ranges($busy, $capacity) {
  if (!$busy) {
    return array();
  }

  // Project every range onto the absolute axis, remembering which date/time
  // strings each boundary came from so the output needs no date arithmetic.
  $intervals = array();
  $labels = array();
  foreach ($busy as $range) {
    $start_abs = intdiv(strtotime($range['start_date'] . ' 00:00:00'), 60)
      + myapi_reservation_time_to_minutes($range['start_time']);
    $end_abs = intdiv(strtotime($range['end_date'] . ' 00:00:00'), 60)
      + myapi_reservation_time_to_minutes($range['end_time']);

    $intervals[] = array($start_abs, $end_abs);
    $labels[$start_abs] = array('date' => $range['start_date'], 'time' => $range['start_time']);
    $labels[$end_abs] = array('date' => $range['end_date'], 'time' => $range['end_time']);
  }

  $boundaries = array_keys($labels);
  sort($boundaries, SORT_NUMERIC);

  $ranges = array();
  for ($i = 0; $i < count($boundaries) - 1; $i++) {
    $from = $boundaries[$i];
    $to = $boundaries[$i + 1];

    // Half-open, same criterion as everywhere else: a reservation ending at
    // $from is no longer active in [$from, $to).
    $reserved = 0;
    foreach ($intervals as $interval) {
      if ($interval[0] <= $from && $interval[1] > $from) {
        $reserved++;
      }
    }

    if ($reserved < 1) {
      continue;
    }

    $ranges[] = array(
      'start_date' => $labels[$from]['date'],
      'start_time' => $labels[$from]['time'],
      'end_date' => $labels[$to]['date'],
      'end_time' => $labels[$to]['time'],
      'reserved' => $reserved,
      'remaining' => max(0, $capacity - $reserved),
    );
  }

  return $ranges;
}

/**
 * Fetches every published reservation whose field_date falls inside a range,
 * with the data the back-office calendar needs to paint and describe it
 * (SPEC 47).
 *
 * Sibling of myapi_reservation_busy_rows(): same base (node of type
 * 'reservation' with n.status = 1) and the same tz-free SUBSTR on
 * field_date_value, but over a RANGE instead of a list of days, without the
 * mandatory area filter, and returning the descriptive columns the calendar
 * chips, legend and detail panel print. It lives here and not inside
 * includes/myapi.reservation_calendar.inc because shared reservation-domain
 * queries belong in includes/ (rule 3 of CLAUDE.md), next to the helper it
 * mirrors. The cost is that API requests loading this file define ~40 lines
 * they never execute.
 *
 * One query for the whole visible range. Grouping the rows by day afterwards
 * is string arithmetic in PHP and costs nothing, whereas one query per day
 * would be 42 queries in the month view.
 *
 * Why each join is what it is:
 * - INNER on field_date, field_start_time, field_end_time and
 *   field_reservation_status. A reservation missing any of those four cannot be
 *   placed on a grid, and the write path already treats such a row as
 *   incoherent (same criterion as myapi_reservation_busy_rows()). Excluding it
 *   here keeps the calendar and the API telling the same story.
 * - LEFT on field_area, field_unit, field_condominium, field_requester and
 *   their node/users rows, plus the requester's field_nombre and
 *   field_apellidos (same profile fields as myapi_user_fetch_profile_fields()).
 *   A deleted area or unit must NOT remove the reservation from the calendar:
 *   it existed and it occupied a slot. The target_id survives in the field
 *   table while the title arrives NULL, and the render degrades to 'Área
 *   eliminada (#123)'. field_cancelled_by is left too because a confirmed
 *   reservation simply has no row there.
 *
 * $limit + 1 rows are requested on purpose: if the caller gets more than
 * $limit back it knows the view is truncated, without a second COUNT() query.
 * The trimming and the on-screen warning are the caller's job.
 *
 * @param string   $from            First day of the range, 'YYYY-MM-DD',
 *                                  inclusive. Normally grid_from minus one day,
 *                                  so an overnight starting just before the
 *                                  grid still leaves its tail inside it.
 * @param string   $to              Last day of the range, 'YYYY-MM-DD',
 *                                  inclusive.
 * @param int|NULL $condominium_id  Optional field_condominium filter.
 * @param int|NULL $area_id         Optional field_area filter.
 * @param array    $statuses        Reservation statuses to include, e.g.
 *                                  array('confirmed'). Never a raw user string.
 * @param int      $limit           Maximum rows to paint; $limit + 1 are
 *                                  fetched.
 *
 * @return array  Raw rows (stdClass) ordered by date, start_time and nid, with
 *   nid, created, date, start_time, end_time, status, cancelled_by, area_id,
 *   area_title, unit_id, unit_title, condominium_id, uid, user_name,
 *   user_mail, user_first_name and user_last_name. Empty array when nothing
 *   matches.
 */
function myapi_reservation_calendar_rows($from, $to, $condominium_id, $area_id, array $statuses, $limit) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'created'))
    ->condition('n.type', 'reservation')
    ->condition('n.status', 1);

  // Date, hours and status: mandatory for a row to be paintable.
  $query->innerJoin('field_data_field_date', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->where('SUBSTR(fd.field_date_value, 1, 10) BETWEEN :from AND :to', array(':from' => $from, ':to' => $to));
  $query->addExpression('SUBSTR(fd.field_date_value, 1, 10)', 'date');

  $query->innerJoin('field_data_field_start_time', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->addField('fs', 'field_start_time_value', 'start_time');

  $query->innerJoin('field_data_field_end_time', 'fe', "fe.entity_id = n.nid AND fe.entity_type = 'node' AND fe.deleted = 0");
  $query->addField('fe', 'field_end_time_value', 'end_time');

  $query->innerJoin('field_data_field_reservation_status', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->condition('fst.field_reservation_status_value', $statuses, 'IN');
  $query->addField('fst', 'field_reservation_status_value', 'status');

  // Descriptive data: never a reason to drop the row.
  $query->leftJoin('field_data_field_area', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->addField('fa', 'field_area_target_id', 'area_id');
  $query->leftJoin('node', 'na', 'na.nid = fa.field_area_target_id');
  $query->addField('na', 'title', 'area_title');

  $query->leftJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->addField('fu', 'field_unit_target_id', 'unit_id');
  $query->leftJoin('node', 'nu', 'nu.nid = fu.field_unit_target_id');
  $query->addField('nu', 'title', 'unit_title');

  $query->leftJoin('field_data_field_condominium', 'fc', "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0");
  $query->addField('fc', 'field_condominium_target_id', 'condominium_id');

  $query->leftJoin('field_data_field_requester', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->addField('fr', 'field_requester_target_id', 'uid');
  $query->leftJoin('users', 'u', 'u.uid = fr.field_requester_target_id');
  $query->addField('u', 'name', 'user_name');
  $query->addField('u', 'mail', 'user_mail');
  $query->leftJoin('field_data_field_nombre', 'fn', "fn.entity_id = u.uid AND fn.entity_type = 'user' AND fn.deleted = 0");
  $query->addField('fn', 'field_nombre_value', 'user_first_name');
  $query->leftJoin('field_data_field_apellidos', 'fap', "fap.entity_id = u.uid AND fap.entity_type = 'user' AND fap.deleted = 0");
  $query->addField('fap', 'field_apellidos_value', 'user_last_name');

  $query->leftJoin('field_data_field_cancelled_by', 'fcb', "fcb.entity_id = n.nid AND fcb.entity_type = 'node' AND fcb.deleted = 0");
  $query->addField('fcb', 'field_cancelled_by_value', 'cancelled_by');

  // Optional filters. Applied on the left-joined columns, so a reservation
  // with no condominium or no area row is also excluded when filtering by
  // one — which is the desired reading of "show me this condominium".
  if ($condominium_id !== NULL) {
    $query->condition('fc.field_condominium_target_id', $condominium_id);
  }
  if ($area_id !== NULL) {
    $query->condition('fa.field_area_target_id', $area_id);
  }

  // Chronological, with nid as the deterministic tie-breaker so the grid is
  // reproducible across requests. Times are zero-padded 'HH:MM', so the plain
  // string sort is already clock order.
  $query->orderBy('date');
  $query->orderBy('start_time');
  $query->orderBy('n.nid');

  // One extra row: its presence is the "there are more" flag.
  $query->range(0, $limit + 1);

  return $query->execute()->fetchAll();
}
