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

  $form['area'] = array(
    '#type' => 'select',
    '#title' => t('Área'),
    '#options' => array('' => t('- Todas -')) + $area_options,
    '#default_value' => $filters['area'] === NULL ? '' : $filters['area'],
    '#disabled' => $filters['condominium'] === NULL,
    '#description' => $filters['condominium'] === NULL ? t('Elige un condominio para filtrar por área.') : NULL,
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

  $form['view'] = array(
    '#type' => 'select',
    '#title' => t('Vista'),
    '#options' => array(
      'month' => t('Mes'),
      'week' => t('Semana'),
    ),
    '#default_value' => $filters['view'],
  );

  $form['date'] = array(
    '#type' => 'textfield',
    '#title' => t('Fecha de referencia'),
    '#size' => 12,
    '#maxlength' => 10,
    '#default_value' => $filters['date'],
    '#description' => t('Formato AAAA-MM-DD.'),
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
 * At this step of SPEC 47 it prints the filter form and how many reservations
 * were found; the grid, the legend, the detail panel and the ceiling warning
 * arrive in steps 6 to 9.
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

  $build = array();

  $build['filters'] = drupal_get_form('myapi_reservation_calendar_filter_form', $filters, $condominium_options, $area_options);

  $build['count'] = array(
    '#markup' => '<p class="myapi-cal-count">' . t('@count reservas entre el @from y el @to.', array(
      '@count' => count($rows),
      '@from' => $range['grid_from'],
      '@to' => $range['grid_to'],
    )) . '</p>',
  );

  return $build;
}
