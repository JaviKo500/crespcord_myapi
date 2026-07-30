<?php

/**
 * @file
 * Back-office reservation calendar page (SPEC 47).
 *
 * Serves admin/content/reservation-calendar: a read-only, server-rendered
 * month/week grid of existing reservations, coloured by area, with a detail
 * panel. It is NOT an API endpoint — nothing here returns the JSON envelope,
 * and no resource under resources/ calls into this file. The page lives in
 * includes/ for the same reason includes/myapi.area_admin.inc does: resources/
 * is for what the Flutter app consumes, and this is back office.
 *
 * The file is loaded by the 'file' key of its hook_menu() entry in
 * myapi.module, so it only reaches PHP on that one route. Its CSS and JS are
 * added from the page callback with drupal_add_css()/drupal_add_js() rather
 * than declared in myapi.info, which would load them on every API response
 * too.
 *
 * Everything in the "pure helpers" block below runs without a database and
 * without a single Drupal call, which is what makes it unit-testable in
 * tests/unit/ReservationCalendarTest.php with no site booted — the whole risk
 * of this page is in the grid arithmetic, the overnight split and the lane
 * assignment, and that is exactly what those tests pin down.
 *
 * Date arithmetic is done on 'Y-m-d' strings with strtotime($d . ' +1 day') at
 * local midnight, never with DateTime or timezone conversions: field_date is
 * stored with tz_handling = none (SPEC 32) and the whole module has operated
 * that way since SPEC 40. Converting halfway would shift days twice a year on
 * the DST switches.
 */

// Pulls in myapi_reservation_time_to_minutes() and, from SPEC 47 on,
// myapi_reservation_calendar_rows(). Functions of a files[] .inc are not
// autoloaded by the D7 registry, so the include is explicit. In PHPUnit this
// call is a no-op stub (tests/unit/bootstrap.php) and the test requires the
// same file itself.
module_load_include('inc', 'myapi', 'includes/myapi.reservation_query');

// Number of colours in the area palette. The classes themselves
// (myapi-cal-c0 .. myapi-cal-c11) live in css/myapi.calendar.css; keeping the
// count here means the index can never point outside the stylesheet.
//
// Guarded so a PHPUnit require_once of this file next to one that already
// defined it does not emit a redefinition notice (same pattern as
// MYAPI_AREA_TIME_PATTERN in includes/myapi.area_admin.inc).
if (!defined('MYAPI_CALENDAR_PALETTE_SIZE')) {
  define('MYAPI_CALENDAR_PALETTE_SIZE', 12);
}

// Hard ceiling on the reservations painted in one view. A constant and not a
// variable_get(): there is no configuration form for this page, so a variable
// would be an invisible lever nobody would find. Changing the ceiling means
// editing this line and deploying, which is exactly how often it will happen.
if (!defined('MYAPI_CALENDAR_MAX_ROWS')) {
  define('MYAPI_CALENDAR_MAX_ROWS', 2000);
}

/* ---------------------------------------------------------------------------
 * Pure helpers.
 *
 * No database, no Drupal, no globals. Everything below takes plain values and
 * returns plain values, so tests/unit/ReservationCalendarTest.php can require
 * this file and exercise them directly.
 * ------------------------------------------------------------------------ */

/**
 * Computes the visible range of the calendar and the range to query.
 *
 * Three dates come out:
 * - grid_from / grid_to — the first and last day the grid paints. In month
 *   view, the Monday on or before the 1st and the Sunday on or after the last
 *   day, so the grid is a whole number of weeks (4 to 6) that contains every
 *   day of the month. In week view, the Monday and Sunday of the reference
 *   day's week.
 * - query_from — grid_from minus one day. That extra day AHEAD is what keeps
 *   the tail of an overnight reservation that starts the day before the grid
 *   inside the view: without it the tail would silently vanish.
 *
 * The range is deliberately NOT widened at the other end. The tail of a
 * reservation starting on grid_to falls outside the grid and there is nowhere
 * to paint it, so fetching that row would only widen the query for nothing.
 *
 * Any view other than 'week' is treated as 'month', which is what makes a
 * ?view=daily in the URL degrade to the default instead of erroring.
 *
 * @param string $view
 *   'week', or anything else for the month view.
 * @param string $ref_date
 *   Reference day, 'YYYY-MM-DD'. Already validated by the caller.
 *
 * @return array
 *   Keys 'grid_from', 'grid_to' and 'query_from', all 'YYYY-MM-DD'.
 */
function myapi_calendar_range($view, $ref_date) {
  if ($view === 'week') {
    $first = $ref_date;
    $last = $ref_date;
  }
  else {
    $first = date('Y-m-01', strtotime($ref_date));
    $last = date('Y-m-t', strtotime($ref_date));
  }

  // date('N') is 1 for Monday and 7 for Sunday, so the Monday on or before a
  // day is that day minus (N - 1), and the Sunday on or after it is plus
  // (7 - N). Both are zero when the day already is that Monday or Sunday,
  // which is what keeps a month starting on Monday (or ending on Sunday) from
  // growing an empty week.
  $grid_from = date('Y-m-d', strtotime($first . ' -' . (((int) date('N', strtotime($first))) - 1) . ' days'));
  $grid_to = date('Y-m-d', strtotime($last . ' +' . (7 - ((int) date('N', strtotime($last)))) . ' days'));

  return array(
    'grid_from' => $grid_from,
    'grid_to' => $grid_to,
    'query_from' => date('Y-m-d', strtotime($grid_from . ' -1 day')),
  );
}

/**
 * Builds the month grid as a list of weeks of exactly seven days.
 *
 * The range comes from myapi_calendar_range('month', ...) so the Monday/Sunday
 * arithmetic lives in one place only. Because grid_from is always a Monday and
 * grid_to always the following Sunday, walking day by day in chunks of seven
 * consumes the range exactly: no partial week can ever come out.
 *
 * Days outside the reference month are part of the grid — the render greys
 * them out by comparing the month prefix, it does not need them flagged here.
 *
 * @param string $ref_date
 *   Any day of the month to paint, 'YYYY-MM-DD'.
 *
 * @return array
 *   List of weeks; each week a list of 7 'YYYY-MM-DD' strings, Monday first.
 */
function myapi_calendar_month_grid($ref_date) {
  $range = myapi_calendar_range('month', $ref_date);

  $weeks = array();
  $day = $range['grid_from'];

  // 'Y-m-d' strings compare lexicographically in chronological order, so no
  // date parsing is needed to know when the grid is finished.
  while ($day <= $range['grid_to']) {
    $week = array();
    for ($i = 0; $i < 7; $i++) {
      $week[] = $day;
      $day = date('Y-m-d', strtotime($day . ' +1 day'));
    }
    $weeks[] = $week;
  }

  return $weeks;
}

/**
 * Returns the seven days of the week containing a reference day.
 *
 * Same range helper as the month grid, so "the week of X" is defined once.
 *
 * @param string $ref_date
 *   Any day of the week to paint, 'YYYY-MM-DD'.
 *
 * @return array
 *   List of 7 'YYYY-MM-DD' strings, Monday first.
 */
function myapi_calendar_week_days($ref_date) {
  $range = myapi_calendar_range('week', $ref_date);

  $days = array();
  $day = $range['grid_from'];
  for ($i = 0; $i < 7; $i++) {
    $days[] = $day;
    $day = date('Y-m-d', strtotime($day . ' +1 day'));
  }

  return $days;
}

/**
 * Splits reservation rows into the one or two day segments each one paints.
 *
 * The crossing rule is the one of SPEC 41, unchanged: end_time <= start_time
 * means the reservation ends the next day. A crossing row yields a head
 * segment on its own date, clipped at 24:00 and flagged ends_next_day, plus a
 * continuation segment on D+1 starting at 00:00.
 *
 * The one case that separates the correct implementation from the naive one is
 * 20:00 -> 00:00: it crosses by the rule, but its second segment would have
 * zero duration, so it is NOT emitted. A zero-height chip would either be
 * invisible or a 1px artefact on the wrong day.
 *
 * Both segments of a crossing reservation carry the SAME row object. The
 * detail panel reads that row and never the segment, so a 22:00 -> 02:00 shows
 * a 4h duration whichever of its two chips was clicked.
 *
 * Segments are returned grouped by the day they are painted on, days in
 * chronological order, and within each day sorted by (start_min, nid) — which
 * is exactly the input contract of myapi_calendar_assign_lanes(). The sort
 * matters because a continuation lands on a day whose own reservations were
 * already collected.
 *
 * Segments falling outside the grid are NOT dropped here: the caller keeps the
 * days it paints. That is what makes the function total and testable.
 *
 * @param array $rows
 *   Rows as returned by myapi_reservation_calendar_rows(): objects with at
 *   least nid, date, start_time and end_time.
 *
 * @return array
 *   'YYYY-MM-DD' => list of segments, each with keys nid, date, start_time,
 *   end_time, start_min, end_min, is_continuation, ends_next_day and row.
 */
function myapi_calendar_day_segments($rows) {
  $days = array();

  foreach ($rows as $row) {
    $start_min = myapi_reservation_time_to_minutes($row->start_time);
    $end_min = myapi_reservation_time_to_minutes($row->end_time);
    $crosses = $end_min <= $start_min;

    $days[$row->date][] = array(
      'nid' => (int) $row->nid,
      'date' => $row->date,
      'start_time' => $row->start_time,
      'end_time' => $crosses ? '24:00' : $row->end_time,
      'start_min' => $start_min,
      'end_min' => $crosses ? 1440 : $end_min,
      'is_continuation' => FALSE,
      'ends_next_day' => $crosses,
      'row' => $row,
    );

    // $end_min > 0 is the 20:00 -> 00:00 guard: a tail of zero minutes is not
    // a chip.
    if ($crosses && $end_min > 0) {
      $next_day = date('Y-m-d', strtotime($row->date . ' +1 day'));

      $days[$next_day][] = array(
        'nid' => (int) $row->nid,
        'date' => $next_day,
        'start_time' => '00:00',
        'end_time' => $row->end_time,
        'start_min' => 0,
        'end_min' => $end_min,
        'is_continuation' => TRUE,
        'ends_next_day' => FALSE,
        'row' => $row,
      );
    }
  }

  ksort($days);

  foreach ($days as $date => $segments) {
    usort($segments, function ($a, $b) {
      if ($a['start_min'] === $b['start_min']) {
        return $a['nid'] - $b['nid'];
      }
      return $a['start_min'] - $b['start_min'];
    });
    $days[$date] = $segments;
  }

  return $days;
}

/**
 * Assigns each segment of one day column a lane, so overlapping chips sit side
 * by side instead of on top of each other.
 *
 * Overlap uses the HALF-OPEN criterion of SPEC 35/41/45 (s < other_end &&
 * e > other_start), reused and not rewritten: if 10:00-11:00 and 11:00-12:00
 * can both be booked in an area of capacity 1, the view must not suggest they
 * compete for the slot. Both take the full width.
 *
 * myapi_reservation_peak_concurrency() is deliberately not called here: it
 * answers "how many at the busiest instant?" with a number, which says nothing
 * about WHICH lane each chip goes to. What is shared with it is the overlap
 * criterion, which is the part that must not be duplicated.
 *
 * Two steps:
 * 1. Group into connected clusters. Since segments arrive sorted by start, a
 *    segment opens a new cluster exactly when it starts at or after the
 *    highest end seen so far in the current one. Transitivity comes for free:
 *    A-B and B-C overlapping puts A, B and C in the same cluster even if A and
 *    C do not touch.
 * 2. Inside a cluster, greedy placement in the lowest-indexed lane whose last
 *    segment ended at or before this one starts, and lanes_total is the
 *    cluster's lane count.
 *
 * lanes_total is per cluster and not per day on purpose: with a single maximum
 * for the whole day, a triple overlap in the morning would squeeze every
 * afternoon chip to a third of the width for no reason.
 *
 * @param array $segments
 *   Segments of ONE day column, already sorted by (start_min, nid) — which is
 *   how myapi_calendar_day_segments() returns them. The greedy placement
 *   depends on that order.
 *
 * @return array
 *   The same segments, in the same order, each with 'lane' (0-based) and
 *   'lanes_total' added.
 */
function myapi_calendar_assign_lanes($segments) {
  // Step 1: connected clusters.
  $clusters = array();
  $current = array();
  $cluster_end = NULL;

  foreach ($segments as $segment) {
    // Half-open: starting exactly when the cluster ends is not an overlap.
    if ($current && $segment['start_min'] >= $cluster_end) {
      $clusters[] = $current;
      $current = array();
      $cluster_end = NULL;
    }

    $current[] = $segment;
    $cluster_end = ($cluster_end === NULL) ? $segment['end_min'] : max($cluster_end, $segment['end_min']);
  }

  if ($current) {
    $clusters[] = $current;
  }

  // Step 2: greedy lanes inside each cluster.
  $result = array();

  foreach ($clusters as $cluster) {
    $lane_ends = array();
    $placed = array();

    foreach ($cluster as $segment) {
      $lane = NULL;
      foreach ($lane_ends as $index => $end) {
        if ($end <= $segment['start_min']) {
          $lane = $index;
          break;
        }
      }
      if ($lane === NULL) {
        $lane = count($lane_ends);
      }

      $lane_ends[$lane] = $segment['end_min'];
      $segment['lane'] = $lane;
      $placed[] = $segment;
    }

    // Known only once the whole cluster is placed, hence the second pass.
    $lanes_total = count($lane_ends);
    foreach ($placed as $segment) {
      $segment['lanes_total'] = $lanes_total;
      $result[] = $segment;
    }
  }

  return $result;
}

/**
 * Maps an area nid to its index in the fixed 12-colour palette.
 *
 * Deterministic and stable across environments, with no field, no migration
 * and no configuration UI. Two areas can share a colour when their nids are
 * congruent modulo 12; the legend is what tells them apart.
 *
 * The index depends on the nid and not on the node, so a deleted area whose
 * reservations survive keeps the colour it always had.
 *
 * @param int|string $nid
 *   Area nid. Arrives as a string from the database.
 *
 * @return int
 *   Index in [0, MYAPI_CALENDAR_PALETTE_SIZE - 1].
 */
function myapi_calendar_area_color_index($nid) {
  return ((int) $nid) % MYAPI_CALENDAR_PALETTE_SIZE;
}

/* ---------------------------------------------------------------------------
 * Filters.
 *
 * These do touch the database (two small queries to fill the selects) and read
 * $_GET, so they are not part of the pure block above.
 * ------------------------------------------------------------------------ */

/**
 * Normalises a raw GET value into a positive integer, or NULL.
 *
 * Anything that is not a positive integer — 'abc', '0', '-3', an array — comes
 * back as NULL, i.e. "no filter". Same lax contract as
 * myapi_reservation_valid_date(): a junk parameter degrades to the default
 * instead of erroring, and there is no JSON envelope here in which to put an
 * error_code anyway.
 *
 * @param mixed $value  Raw $_GET value.
 *
 * @return int|NULL  The positive integer, or NULL.
 */
function myapi_calendar_positive_int($value) {
  if (!is_scalar($value)) {
    return NULL;
  }
  $value = (string) $value;
  if (!preg_match('/^\d+$/', $value) || (int) $value < 1) {
    return NULL;
  }
  return (int) $value;
}

/**
 * Lists the published 'condominio' nodes for the condominium select.
 *
 * @return array  nid => title, ordered by title.
 */
function myapi_calendar_condominium_options() {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'title'))
    ->condition('n.type', 'condominio')
    ->condition('n.status', 1);
  $query->orderBy('n.title');

  return $query->execute()->fetchAllKeyed();
}

/**
 * Lists the published 'area' nodes of one condominium for the area select.
 *
 * Always scoped to a condominium: with none selected the select is disabled,
 * so there is nothing to fill and the query is skipped altogether. This is the
 * whole of the "dependent select" behaviour — it is resolved by reloading the
 * page over GET, with no AJAX (an explicit requirement of SPEC 47), at the
 * cost of one extra click when changing condominium.
 *
 * @param int|NULL $condominium_id  The 'condominio' nid, or NULL.
 *
 * @return array  nid => title, ordered by title. Empty when no condominium.
 */
function myapi_calendar_area_options($condominium_id) {
  if ($condominium_id === NULL) {
    return array();
  }

  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'title'))
    ->condition('n.type', 'area')
    ->condition('n.status', 1);
  $query->innerJoin('field_data_field_condominium', 'fc', "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0");
  $query->condition('fc.field_condominium_target_id', $condominium_id);
  $query->orderBy('n.title');

  return $query->execute()->fetchAllKeyed();
}

/**
 * Reads and validates the five GET parameters of the calendar.
 *
 * Nothing here ever fails: an unknown view, a malformed date, a non-numeric
 * condominium or an invented status all fall back to the default, so a
 * hand-edited or stale URL renders the current month instead of an error page.
 *
 * The area is dropped when a condominium is selected and the area does not
 * belong to it — silently, because that is either a hand-edited URL or an old
 * link, and showing the whole condominium is the least surprising degradation.
 * The check costs no extra query: the area options are already loaded scoped
 * to the condominium, so membership is a key lookup on that list. When NO
 * condominium is selected there is nothing to be coherent with, so a valid
 * ?area=N is honoured and the calendar shows that area across the site — the
 * select is disabled in that case, so only a hand-made URL can reach it.
 *
 * The status string never reaches the database: it is mapped here to the list
 * of stored values the query receives.
 *
 * @param array $area_options  nid => title of the areas of the selected
 *   condominium, as returned by myapi_calendar_area_options().
 *
 * @return array  Keys: view, date, condominium, area, status, statuses.
 */
function myapi_calendar_filters($area_options) {
  // myapi_reservation_valid_date() lives in the reservation resource; it is
  // reused instead of repeating the checkdate() rule so the calendar accepts
  // exactly the dates the API does.
  module_load_include('inc', 'myapi', 'resources/reservation.resource');

  $view = isset($_GET['view']) && $_GET['view'] === 'week' ? 'week' : 'month';

  $date = NULL;
  if (isset($_GET['date']) && is_scalar($_GET['date'])) {
    $date = myapi_reservation_valid_date((string) $_GET['date']);
  }
  if ($date === NULL) {
    $date = date('Y-m-d');
  }

  $condominium = isset($_GET['condominium']) ? myapi_calendar_positive_int($_GET['condominium']) : NULL;

  $area = isset($_GET['area']) ? myapi_calendar_positive_int($_GET['area']) : NULL;
  if ($area !== NULL && $condominium !== NULL && !isset($area_options[$area])) {
    $area = NULL;
  }

  $status = 'confirmed';
  if (isset($_GET['status']) && in_array($_GET['status'], array('confirmed', 'cancelled', 'all'), TRUE)) {
    $status = $_GET['status'];
  }
  $statuses = $status === 'all' ? array('confirmed', 'cancelled') : array($status);

  return array(
    'view' => $view,
    'date' => $date,
    'condominium' => $condominium,
    'area' => $area,
    'status' => $status,
    'statuses' => $statuses,
  );
}

/**
 * Builds the filter form of the calendar.
 *
 * #method = 'get' on purpose: every filter ends up in the query string, so the
 * URL is linkable, pasteable and reproducible in another tab, and the ‹ hoy ›
 * navigation can be plain <a href> links instead of forms. With POST none of
 * that would hold.
 *
 * Drupal's own hidden fields (form_id, form_build_id, form_token) would be
 * dragged into that query string, so they are removed in the after-build; the
 * page reads $_GET directly and never $form_state, which is why nothing is
 * lost by dropping them.
 *
 * The area select is disabled while no condominium is selected: a disabled
 * input is not submitted, so it cannot contribute a stale area to the URL.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters  Validated filters, used as default values.
 * @param array $condominium_options  nid => title.
 * @param array $area_options  nid => title of the selected condominium.
 */
function myapi_reservation_calendar_filter_form($form, &$form_state, $filters, $condominium_options, $area_options) {
  $form['#method'] = 'get';
  // Hook for the stylesheet, which lays the five filters out on one row.
  $form['#attributes']['class'][] = 'myapi-cal-filters';
  // No CSRF token: a read-only GET filter has nothing to forge, and the token
  // would show up in the URL.
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  // Plain path: the current query string is rebuilt from the fields, not
  // appended to.
  $form['#action'] = url('admin/content/reservation-calendar');

  // With clean URLs off the path itself travels in ?q=, and a browser
  // submitting a GET form replaces the whole query string of the action — the
  // path would be lost and the form would land on the front page. Resubmitting
  // it as a field is the standard fix (Views does the same with its exposed
  // forms).
  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type' => 'hidden',
      '#value' => 'admin/content/reservation-calendar',
    );
  }

  $form['condominium'] = array(
    '#type' => 'select',
    '#title' => t('Condominio'),
    '#options' => array('' => t('- Todos -')) + $condominium_options,
    '#default_value' => $filters['condominium'] === NULL ? '' : $filters['condominium'],
  );

  // The empty option carries the instruction while the select is disabled, so
  // the row needs no description line under the field.
  $form['area'] = array(
    '#type' => 'select',
    '#title' => t('Área'),
    '#options' => array(
      '' => $filters['condominium'] === NULL ? t('- Elige un condominio -') : t('- Todas -'),
    ) + $area_options,
    '#default_value' => $filters['area'] === NULL ? '' : $filters['area'],
    '#disabled' => $filters['condominium'] === NULL,
  );

  $form['status'] = array(
    '#type' => 'select',
    '#title' => t('Estado'),
    '#options' => array(
      'confirmed' => t('Confirmadas'),
      'cancelled' => t('Canceladas'),
      'all' => t('Todas'),
    ),
    '#default_value' => $filters['status'],
  );

  // 'view' is NOT a field of this form: it is the pair of Mes/Semana links at
  // the right of the navigation bar (myapi_calendar_render_nav()). A select
  // sitting outside its <form> would not be submitted with it, and the switch
  // belongs visually next to ‹ hoy › rather than among the filters. The GET
  // parameter is unchanged — those links carry it, and every other link of the
  // page carries the current value along.

  // Native HTML5 date input, written by hand because theme_textfield() forces
  // type="text" on the element and would undo an #attributes override. It is
  // markup inside an 'item', so it keeps the Drupal label and wrapper while
  // the browser supplies its own calendar picker — no date_popup submodule to
  // enable, no jQuery UI. A browser without support renders a plain text box,
  // exactly what this field was before, and the placeholder keeps the format
  // hint there.
  //
  // The input is named 'date' and lives inside the form, so the browser
  // submits it in the query string like any other field; type="date" always
  // submits 'YYYY-MM-DD', which is what myapi_calendar_filters() expects.
  $form['date'] = array(
    '#type' => 'item',
    '#title' => t('Fecha de referencia'),
    '#markup' => '<input type="date" name="date" class="myapi-cal-date-input"'
      . ' value="' . check_plain($filters['date']) . '"'
      . ' placeholder="' . t('AAAA-MM-DD') . '" />',
  );

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/**
 * After-build of the filter form: drops Drupal's hidden form fields.
 *
 * With #method = 'get' those fields would be serialised into the query string
 * on every submit, making the URL unreadable and unstable between requests
 * (form_build_id changes each time), which defeats the pasteable-link
 * requirement. Nothing depends on them: the page reads $_GET.
 */
function myapi_reservation_calendar_filter_form_after_build($form, &$form_state) {
  unset($form['form_id'], $form['form_build_id'], $form['form_token']);
  return $form;
}

/* ---------------------------------------------------------------------------
 * Rendering.
 *
 * Every value coming from the database is printed through check_plain(). A
 * unit or area title is written by an administrator, but Drupal 7 is EOL and
 * receives no security patches, so the cost of one escaping call per field is
 * not worth arguing about.
 * ------------------------------------------------------------------------ */

/**
 * Human label for the area of a reservation row.
 *
 * The area join is a LEFT join, so a reservation whose area node was deleted
 * still comes back with its target_id and a NULL title. Hiding that row would
 * falsify the calendar — the booking existed and it occupied a slot — and
 * leaving the label blank would not say what happened, so it degrades to
 * 'Área eliminada (#123)': the nid is something to investigate with.
 *
 * @param object $row  Reservation row.
 *
 * @return string  Already escaped, ready to print.
 */
function myapi_calendar_area_label($row) {
  if ($row->area_id === NULL) {
    return t('Sin área');
  }
  if ($row->area_title === NULL) {
    return t('Área eliminada (#@nid)', array('@nid' => $row->area_id));
  }
  return check_plain($row->area_title);
}

/**
 * CSS colour class of a reservation chip.
 *
 * Cancelled reservations never wear the colour of their area: an operator
 * reads this page to know what is booked, and a cancelled booking painted in
 * the area colour would read as real occupancy. They get their own class,
 * which the stylesheet makes win.
 *
 * A reservation with no area at all falls back to a neutral class rather than
 * to an arbitrary palette slot.
 *
 * @param object $row  Reservation row.
 *
 * @return string  A single CSS class name.
 */
function myapi_calendar_chip_color_class($row) {
  if ($row->status === 'cancelled') {
    return 'myapi-cal-cancelled';
  }
  if ($row->area_id === NULL) {
    return 'myapi-cal-noarea';
  }
  return 'myapi-cal-c' . myapi_calendar_area_color_index($row->area_id);
}

/**
 * Renders one chip: a single day segment of a reservation.
 *
 * Shared by the month and week views so a reservation looks the same in both.
 * Three shapes:
 * - Plain: 'HH:MM–HH:MM'.
 * - Head of an overnight: the REAL end time plus a '+1' mark, so the operator
 *   sees where it actually finishes instead of a 24:00 that never appears on a
 *   clock.
 * - Continuation on D+1: prefixed with an arrow and dimmed by CSS, which is
 *   what says "this one comes from yesterday".
 *
 * @param array  $segment  A segment from myapi_calendar_day_segments().
 * @param string $style    Optional inline style. The week view uses it to
 *   place the chip on the time axis; the month view passes nothing. It is the
 *   only inline style of the page and it is unavoidable: top, height, left and
 *   width depend on the data, so they cannot live in the stylesheet.
 *
 * @return string  HTML.
 */
function myapi_calendar_render_chip($segment, $style = '') {
  $row = $segment['row'];

  $classes = array('myapi-cal-chip', myapi_calendar_chip_color_class($row));
  if ($segment['is_continuation']) {
    $classes[] = 'myapi-cal-continuation';
  }

  if ($segment['is_continuation']) {
    $time = '↳ ' . check_plain($segment['start_time']) . '–' . check_plain($segment['end_time']);
  }
  elseif ($segment['ends_next_day']) {
    $time = check_plain($segment['start_time']) . '–' . check_plain($row->end_time) . ' <span class="myapi-cal-nextday">+1</span>';
  }
  else {
    $time = check_plain($segment['start_time']) . '–' . check_plain($segment['end_time']);
  }

  // data-nid is what the JS reads to open the right detail block. Both chips
  // of an overnight carry the same nid, so both open the same panel.
  return '<div class="' . implode(' ', $classes) . '" data-nid="' . (int) $segment['nid'] . '"'
    . ($style === '' ? '' : ' style="' . check_plain($style) . '"') . '>'
    . '<span class="myapi-cal-time">' . $time . '</span> '
    . '<span class="myapi-cal-area">' . myapi_calendar_area_label($row) . '</span>'
    . '</div>';
}

/**
 * Collects the segments of the days actually painted, in day order.
 *
 * myapi_calendar_day_segments() returns every segment it can derive, including
 * those of the extra query day and the continuation of a reservation starting
 * on the last day of the grid. Dropping them is a render decision, taken here:
 * a segment is painted only if its day is one of the grid's.
 *
 * @param array $segments_by_day  'YYYY-MM-DD' => segments.
 * @param array $days             Flat list of the grid days.
 *
 * @return array  Flat list of segments.
 */
function myapi_calendar_grid_segments($segments_by_day, $days) {
  $segments = array();
  foreach ($days as $day) {
    if (isset($segments_by_day[$day])) {
      $segments = array_merge($segments, $segments_by_day[$day]);
    }
  }
  return $segments;
}

/**
 * Renders the area colour legend.
 *
 * One entry per distinct area with at least one NON-cancelled reservation
 * visible in the grid, ordered by label. Cancelled ones are out: they do not
 * wear the area colour, so they have nothing to explain here.
 *
 * @param array $segments  Segments already restricted to the grid.
 *
 * @return string  HTML, empty string when there is nothing to explain.
 */
function myapi_calendar_render_legend($segments) {
  $labels = array();
  foreach ($segments as $segment) {
    $row = $segment['row'];
    if ($row->status === 'cancelled' || $row->area_id === NULL) {
      continue;
    }
    $labels[(int) $row->area_id] = myapi_calendar_area_label($row);
  }

  if (!$labels) {
    return '';
  }

  // Sorted by a de-accented key: comparing the UTF-8 bytes directly would push
  // 'Área' past 'Zona', because the accented letters sit above the ASCII range.
  // A fixed map instead of setlocale()/strcoll() keeps the order identical in
  // every environment.
  $keys = array();
  foreach ($labels as $area_id => $label) {
    $keys[$area_id] = strtr($label, array(
      'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
      'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ));
  }
  asort($keys, SORT_NATURAL | SORT_FLAG_CASE);
  $labels = array_replace($keys, $labels);

  $items = '';
  foreach ($labels as $area_id => $label) {
    $items .= '<li class="myapi-cal-legend-item">'
      . '<span class="myapi-cal-swatch myapi-cal-c' . myapi_calendar_area_color_index($area_id) . '"></span>'
      . '<span class="myapi-cal-legend-label">' . $label . '</span>'
      . '</li>';
  }

  return '<ul class="myapi-cal-legend">' . $items . '</ul>';
}

/**
 * Builds a calendar URL keeping every filter but the reference date.
 *
 * @param array  $filters  Validated filters.
 * @param string $date     The reference date the link points to.
 * @param string $view     Optional view override, for the Mes/Semana switch.
 *   Defaults to the current one, which is what every other link needs.
 *
 * @return string  Absolute-path URL.
 */
function myapi_calendar_url($filters, $date, $view = NULL) {
  $query = array(
    'view' => $view === NULL ? $filters['view'] : $view,
    'date' => $date,
    'status' => $filters['status'],
  );
  if ($filters['condominium'] !== NULL) {
    $query['condominium'] = $filters['condominium'];
  }
  if ($filters['area'] !== NULL) {
    $query['area'] = $filters['area'];
  }

  return url('admin/content/reservation-calendar', array('query' => $query));
}

/**
 * Renders the ‹ hoy › navigation and the title of the current range.
 *
 * Plain <a href> links, no forms and no JS, which is what makes every position
 * of the calendar a shareable URL. All the other filters travel along.
 *
 * The month step is computed from the FIRST of the month and not from the
 * reference day: strtotime('2026-03-31 -1 month') lands on 2026-03-03, so
 * stepping back from a 31st would skip February altogether.
 *
 * @param array $filters  Validated filters.
 * @param array $range    Output of myapi_calendar_range().
 *
 * @return string  HTML.
 */
function myapi_calendar_render_nav($filters, $range) {
  if ($filters['view'] === 'week') {
    $previous = date('Y-m-d', strtotime($filters['date'] . ' -7 days'));
    $next = date('Y-m-d', strtotime($filters['date'] . ' +7 days'));
    $title = t('Semana del @from al @to', array(
      '@from' => format_date(strtotime($range['grid_from']), 'custom', 'j M Y'),
      '@to' => format_date(strtotime($range['grid_to']), 'custom', 'j M Y'),
    ));
  }
  else {
    $first = date('Y-m-01', strtotime($filters['date']));
    $previous = date('Y-m-d', strtotime($first . ' -1 month'));
    $next = date('Y-m-d', strtotime($first . ' +1 month'));
    $title = check_plain(format_date(strtotime($first), 'custom', 'F Y'));
  }

  // Month/week switch, pushed to the right edge of the bar. Two links and not
  // a select, so it needs no form around it and stays consistent with the rest
  // of the navigation: every position of the calendar is a plain URL.
  $switch = '';
  foreach (array('month' => t('Mes'), 'week' => t('Semana')) as $option => $label) {
    $classes = 'myapi-cal-nav-link myapi-cal-view-link';
    if ($filters['view'] === $option) {
      $classes .= ' is-active';
    }
    $switch .= '<a class="' . $classes . '" href="' . myapi_calendar_url($filters, $filters['date'], $option) . '">' . $label . '</a>';
  }

  return '<div class="myapi-cal-nav">'
    . '<a class="myapi-cal-nav-link" href="' . myapi_calendar_url($filters, $previous) . '">‹</a> '
    . '<a class="myapi-cal-nav-link" href="' . myapi_calendar_url($filters, date('Y-m-d')) . '">' . t('hoy') . '</a> '
    . '<a class="myapi-cal-nav-link" href="' . myapi_calendar_url($filters, $next) . '">›</a>'
    . '<span class="myapi-cal-nav-title">' . $title . '</span>'
    . '<span class="myapi-cal-viewswitch">' . $switch . '</span>'
    . '</div>';
}

/**
 * Renders the month grid.
 *
 * Weeks of exactly seven days, Monday first. Days outside the reference month
 * are painted greyed out instead of blank, so the week they belong to still
 * reads as a week.
 *
 * A day shows ALL its reservations and the cell grows to fit them: no '+N
 * more'. A non-clickable '+N more' is a dead end, and a clickable one needs
 * either AJAX or a day view, both out of scope.
 *
 * @param array  $weeks            Output of myapi_calendar_month_grid().
 * @param array  $segments_by_day  'YYYY-MM-DD' => segments.
 * @param string $ref_date         Reference day, for the "same month" test.
 *
 * @return string  HTML.
 */
function myapi_calendar_render_month($weeks, $segments_by_day, $ref_date) {
  $ref_month = substr($ref_date, 0, 7);

  // Weekday names taken from the first week, so they follow the site language
  // and always line up with the columns below them.
  $header = '';
  foreach ($weeks[0] as $day) {
    $header .= '<div class="myapi-cal-weekday">' . check_plain(format_date(strtotime($day), 'custom', 'D')) . '</div>';
  }

  $body = '';
  foreach ($weeks as $week) {
    $body .= '<div class="myapi-cal-week">';
    foreach ($week as $day) {
      $classes = array('myapi-cal-day');
      if (substr($day, 0, 7) !== $ref_month) {
        $classes[] = 'myapi-cal-out';
      }
      if ($day === date('Y-m-d')) {
        $classes[] = 'myapi-cal-today';
      }

      $chips = '';
      if (isset($segments_by_day[$day])) {
        foreach ($segments_by_day[$day] as $segment) {
          $chips .= myapi_calendar_render_chip($segment);
        }
      }

      $body .= '<div class="' . implode(' ', $classes) . '">'
        . '<div class="myapi-cal-daynum">' . check_plain(format_date(strtotime($day), 'custom', 'j')) . '</div>'
        . '<div class="myapi-cal-chips">' . $chips . '</div>'
        . '</div>';
    }
    $body .= '</div>';
  }

  return '<div class="myapi-cal myapi-cal-month">'
    . '<div class="myapi-cal-weekdays">' . $header . '</div>'
    . $body
    . '</div>';
}

/**
 * Renders the week grid: seven day columns over a fixed 00:00-24:00 axis.
 *
 * The axis is always the same 24 hourly rows, never a window cropped to the
 * data. Deterministic: the grid does not change shape depending on what has
 * been booked, and an overnight reservation falls inside it with no special
 * case.
 *
 * Chips are positioned proportionally to the MINUTE (top and height as a
 * percentage of the 1440 minutes of the column) instead of being stacked
 * inside the row of their starting hour: stacked, a 10:00 and a 10:45 booking
 * would look identical and the lanes would communicate nothing.
 *
 * Width and horizontal offset come from the lane assignment, computed per day
 * column: overlapping reservations share the column, consecutive ones do not.
 *
 * Percentages are printed with %.4F — the uppercase, locale-independent
 * conversion — so a site running under a locale with comma as decimal
 * separator cannot emit 'top: 41,6667%' and break every chip.
 *
 * @param array $days             The 7 days, output of
 *   myapi_calendar_week_days().
 * @param array $segments_by_day  'YYYY-MM-DD' => segments.
 *
 * @return string  HTML.
 */
function myapi_calendar_render_week($days, $segments_by_day) {
  $today = date('Y-m-d');

  $head = '<div class="myapi-cal-hours-head"></div>';
  foreach ($days as $day) {
    $classes = 'myapi-cal-daycol-head' . ($day === $today ? ' myapi-cal-today' : '');
    $head .= '<div class="' . $classes . '">'
      . check_plain(format_date(strtotime($day), 'custom', 'D j'))
      . '</div>';
  }

  // 24 hourly labels: the axis runs to 24:00, so the last one printed is
  // 23:00 and the bottom edge of the column is midnight.
  $hours = '';
  for ($hour = 0; $hour < 24; $hour++) {
    $hours .= '<div class="myapi-cal-hour">' . sprintf('%02d:00', $hour) . '</div>';
  }

  $columns = '';
  foreach ($days as $day) {
    $segments = isset($segments_by_day[$day]) ? myapi_calendar_assign_lanes($segments_by_day[$day]) : array();

    $chips = '';
    foreach ($segments as $segment) {
      $style = sprintf(
        'top:%.4F%%;height:%.4F%%;left:%.4F%%;width:%.4F%%;',
        $segment['start_min'] / 1440 * 100,
        max(0, $segment['end_min'] - $segment['start_min']) / 1440 * 100,
        $segment['lane'] / $segment['lanes_total'] * 100,
        100 / $segment['lanes_total']
      );
      $chips .= myapi_calendar_render_chip($segment, $style);
    }

    $classes = 'myapi-cal-daycol' . ($day === $today ? ' myapi-cal-today' : '');
    $columns .= '<div class="' . $classes . '">' . $chips . '</div>';
  }

  return '<div class="myapi-cal myapi-cal-week-view">'
    . '<div class="myapi-cal-week-head">' . $head . '</div>'
    . '<div class="myapi-cal-week-body">'
    . '<div class="myapi-cal-hours">' . $hours . '</div>'
    . $columns
    . '</div>'
    . '</div>';
}

/**
 * Human label for the unit of a reservation row.
 *
 * Same criterion as myapi_calendar_area_label(): a deleted unit degrades to
 * its nid instead of removing the reservation or leaving a blank.
 *
 * @param object $row  Reservation row.
 *
 * @return string  Already escaped.
 */
function myapi_calendar_unit_label($row) {
  if ($row->unit_id === NULL) {
    return t('Sin vivienda');
  }
  if ($row->unit_title === NULL) {
    return t('Vivienda eliminada (#@nid)', array('@nid' => $row->unit_id));
  }
  return check_plain($row->unit_title);
}

/**
 * Human label for the requester of a reservation row.
 *
 * Two different absences: a reservation with no field_requester at all ('Sin
 * usuario') and one whose user account was deleted, which still has the uid
 * ('Usuario eliminado (#789)'). When the account is present, the label leads
 * with the profile's first and last name ('field_nombre' / 'field_apellidos')
 * and shows the account username in parentheses; a user with no profile name
 * set falls back to the username alone.
 *
 * @param object $row  Reservation row.
 *
 * @return string  Already escaped.
 */
function myapi_calendar_user_label($row) {
  if ($row->uid === NULL) {
    return t('Sin usuario');
  }
  if ($row->user_name === NULL) {
    return t('Usuario eliminado (#@uid)', array('@uid' => $row->uid));
  }
  $full_name = trim(
    ($row->user_first_name !== NULL ? $row->user_first_name : '')
    . ' '
    . ($row->user_last_name !== NULL ? $row->user_last_name : '')
  );
  if ($full_name === '') {
    return check_plain($row->user_name);
  }
  return check_plain($full_name) . ' (' . check_plain($row->user_name) . ')';
}

/**
 * Human label for the condominium of a reservation row.
 *
 * The query returns the condominium nid but no title — the select at the top
 * of the page already loaded every published condominium, so resolving the
 * title here costs no extra query.
 *
 * @param object $row      Reservation row.
 * @param array  $options  nid => title, from
 *   myapi_calendar_condominium_options().
 *
 * @return string  Already escaped.
 */
function myapi_calendar_condominium_label($row, $options) {
  if ($row->condominium_id === NULL) {
    return t('Sin condominio');
  }
  if (!isset($options[$row->condominium_id])) {
    return t('Condominio no disponible (#@nid)', array('@nid' => $row->condominium_id));
  }
  return check_plain($options[$row->condominium_id]);
}

/**
 * Duration of a reservation, as 'Xh Ymin'.
 *
 * Computed on the ROW and never on a segment. On the segment, opening the
 * detail from the continuation chip of a 22:00 -> 02:00 would report '2h
 * 0min': the reservation is one thing and its duration does not depend on
 * which of its two chips was clicked.
 *
 * @param object $row  Reservation row.
 *
 * @return string  E.g. '4h 0min'.
 */
function myapi_calendar_duration_label($row) {
  $start_min = myapi_reservation_time_to_minutes($row->start_time);
  $end_min = myapi_reservation_time_to_minutes($row->end_time);
  // Same crossing rule as the segment split: an end at or below the start
  // lands on the next day, so a full day is added.
  $minutes = $end_min - $start_min + ($end_min <= $start_min ? 1440 : 0);

  // Plain numeric formatting, nothing to translate.
  return sprintf('%dh %dmin', intdiv($minutes, 60), $minutes % 60);
}

/**
 * Renders the hidden detail panels: one block per reservation.
 *
 * All of them are printed in the server response, so opening a detail needs no
 * request of any kind — no new endpoint to secure, no state to keep in sync.
 * The cost is a bigger HTML document, bounded by MYAPI_CALENDAR_MAX_ROWS.
 *
 * Exactly ONE block per reservation even when it has two chips: the blocks are
 * keyed by nid and both chips of an overnight point at the same id.
 *
 * The panel prints the requester's full name with their username in
 * parentheses (see myapi_calendar_user_label()), never the email. That is
 * deliberate: this is a back-office page whose access callback limits it to
 * the operator of the condominium, and it answers the question the operator
 * actually has without exposing the account's email address.
 *
 * @param array $rows                 Reservation rows to emit, keyed by nid.
 * @param array $condominium_options  nid => title.
 *
 * @return string  HTML.
 */
function myapi_calendar_render_details($rows, $condominium_options) {
  $blocks = '';

  foreach ($rows as $row) {
    $end = check_plain($row->end_time);
    if (myapi_reservation_time_to_minutes($row->end_time) <= myapi_reservation_time_to_minutes($row->start_time)) {
      $end .= ' <span class="myapi-cal-nextday-label">' . t('(+1 día)') . '</span>';
    }

    $lines = array(
      t('Usuario') => myapi_calendar_user_label($row),
      t('Vivienda') => myapi_calendar_unit_label($row),
      t('Área') => myapi_calendar_area_label($row),
      t('Condominio') => myapi_calendar_condominium_label($row, $condominium_options),
      t('Fecha') => check_plain(format_date(strtotime($row->date), 'custom', 'd/m/Y')),
      t('Horario') => check_plain($row->start_time) . ' – ' . $end,
      t('Duración') => myapi_calendar_duration_label($row),
      t('Estado') => $row->status === 'cancelled' ? t('Cancelada') : t('Confirmada'),
    );

    // Only a cancelled reservation has a cancelled_by to show; on a confirmed
    // one the line is absent, not empty.
    if ($row->status === 'cancelled') {
      $lines[t('Cancelada por')] = $row->cancelled_by === NULL ? '—' : check_plain($row->cancelled_by);

      // SPEC 50: the reason is optional even on a cancelled reservation, so a
      // missing one drops the line instead of printing a placeholder — same
      // criterion as 'Cancelada por' on a confirmed reservation. It is free
      // text written by the resident, hence check_plain() at the output point.
      if (isset($row->cancel_reason) && trim($row->cancel_reason) !== '') {
        $lines[t('Motivo')] = check_plain($row->cancel_reason);
      }
    }

    $lines[t('Creada')] = check_plain(format_date($row->created, 'custom', 'd/m/Y H:i'));

    $definitions = '';
    foreach ($lines as $label => $value) {
      $definitions .= '<dt>' . $label . '</dt><dd>' . $value . '</dd>';
    }

    // Absolute so the link works the same whether the panel is read on this
    // page or copied elsewhere; check_plain() because url() does not encode
    // the '&' between query arguments for HTML output.
    $node_url = check_plain(url('node/' . $row->nid, array('absolute' => TRUE)));

    $blocks .= '<div class="myapi-cal-detail" id="myapi-cal-detail-' . (int) $row->nid . '" hidden>'
      . '<div class="myapi-cal-detail-box">'
      . '<button type="button" class="myapi-cal-detail-close" title="' . t('Cerrar') . '">×</button>'
      . '<h3 class="myapi-cal-detail-title">' . t('Reserva #@nid', array('@nid' => $row->nid)) . '</h3>'
      . '<dl class="myapi-cal-detail-list">' . $definitions . '</dl>'
      . '<a class="myapi-cal-detail-link" href="' . $node_url . '" target="_blank" rel="noopener">' . t('Ver más') . '</a>'
      . '</div>'
      . '</div>';
  }

  return '<div class="myapi-cal-details">' . $blocks . '</div>';
}

/* ---------------------------------------------------------------------------
 * Page.
 * ------------------------------------------------------------------------ */

/**
 * Page callback of admin/content/reservation-calendar.
 *
 * Read-only: it validates the filters, resolves the range, runs ONE query for
 * every reservation in it and renders. Nothing here creates, edits, cancels or
 * moves a reservation, and no API endpoint is involved at any point.
 *
 * The CSS and the JS are attached here and not declared in myapi.info, which
 * would load them on every page of the site — API responses included, and
 * those are many while this page is one.
 *
 * The query runs from range['query_from'] (one day BEFORE the grid, so the
 * tail of an overnight starting just before it is not lost) to
 * range['grid_to'].
 *
 * At this step of SPEC 47 it renders the month view; the week view, the detail
 * panel and the ceiling warning arrive in steps 7 to 9.
 */
function myapi_reservation_calendar_page() {
  $path = drupal_get_path('module', 'myapi');
  drupal_add_css($path . '/css/myapi.calendar.css');
  drupal_add_js($path . '/js/myapi.calendar.js');

  // Order matters: the areas of the selected condominium are what the area
  // parameter is validated against, so they are loaded first.
  $condominium_options = myapi_calendar_condominium_options();
  $condominium = isset($_GET['condominium']) ? myapi_calendar_positive_int($_GET['condominium']) : NULL;
  $area_options = myapi_calendar_area_options($condominium);

  $filters = myapi_calendar_filters($area_options);
  $range = myapi_calendar_range($filters['view'], $filters['date']);

  $rows = myapi_reservation_calendar_rows(
    $range['query_from'],
    $range['grid_to'],
    $filters['condominium'],
    $filters['area'],
    $filters['statuses'],
    MYAPI_CALENDAR_MAX_ROWS
  );

  // The helper asked for MYAPI_CALENDAR_MAX_ROWS + 1 rows, so getting more
  // than the ceiling back is the signal that the range holds even more. The
  // extra rows are dropped and the operator is told on screen — a watchdog()
  // entry would not do: someone would take decisions on an incomplete
  // calendar without ever knowing it was incomplete.
  if (count($rows) > MYAPI_CALENDAR_MAX_ROWS) {
    $rows = array_slice($rows, 0, MYAPI_CALENDAR_MAX_ROWS);
    drupal_set_message(
      t('Esta vista está incompleta: se muestran solo las primeras @limit reservas del rango. Acota el filtro por condominio, área o fecha para verlas todas.', array(
        '@limit' => MYAPI_CALENDAR_MAX_ROWS,
      )),
      'warning'
    );
  }

  $segments_by_day = myapi_calendar_day_segments($rows);

  if ($filters['view'] === 'week') {
    $days = myapi_calendar_week_days($filters['date']);
    $grid = myapi_calendar_render_week($days, $segments_by_day);
  }
  else {
    $weeks = myapi_calendar_month_grid($filters['date']);
    $days = call_user_func_array('array_merge', $weeks);
    $grid = myapi_calendar_render_month($weeks, $segments_by_day, $filters['date']);
  }

  $build = array();

  $build['filters'] = drupal_get_form('myapi_reservation_calendar_filter_form', $filters, $condominium_options, $area_options);

  $build['nav'] = array(
    '#markup' => myapi_calendar_render_nav($filters, $range),
  );

  $build['grid'] = array(
    '#markup' => $grid,
  );

  $grid_segments = myapi_calendar_grid_segments($segments_by_day, $days);

  $build['legend'] = array(
    '#markup' => myapi_calendar_render_legend($grid_segments),
  );

  // Only the reservations with a chip on screen get a detail block, keyed by
  // nid so an overnight with two chips still produces exactly one.
  $painted = array();
  foreach ($grid_segments as $segment) {
    $painted[$segment['nid']] = $segment['row'];
  }

  $build['details'] = array(
    '#markup' => myapi_calendar_render_details($painted, $condominium_options),
  );

  return $build;
}
