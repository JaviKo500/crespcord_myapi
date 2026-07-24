<?php

/**
 * @file
 * Shared reservation-domain query helpers for the myapi module.
 *
 * These helpers read confirmed reservations of an area without any requester
 * filter, so read-only endpoints (e.g. area availability) can report the busy
 * time ranges of an area for a given day. They never touch the write path:
 * myapi_reservation_has_overlap() and POST /api/v1/reservations are untouched.
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
 * Fetches the raw rows of confirmed, published reservations of an area whose
 * field_date falls on any of the given days.
 *
 * Same base as myapi_reservation_has_overlap() but WITHOUT the requester filter
 * and WITHOUT a candidate range to compare: it reports every confirmed booking
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
