<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_calendar.inc';
require_once __DIR__ . '/../../resources/reservation.resource.inc';

/**
 * Unit tests for the reservation calendar's filters and renderers (SPEC 47,
 * covered by SPEC 121).
 *
 * THE BACK OFFICE'S ONLY CUSTOM SCREEN, and until SPEC 121 the largest
 * untested file of the module: 31 functions of which
 * ReservationCalendarTest covered only the six that do the grid arithmetic.
 * What was left is everything the operator actually sees — the filters that
 * decide WHICH reservations are painted, and the HTML that paints them.
 *
 * Two properties are worth naming, because both fail silently:
 *
 *  - THE FILTERS ARE LAX BUT NOT BLIND. Every query-string value falls back to
 *    a default instead of erroring, which is right for a screen — but an
 *    ?area that does not belong to the chosen ?condominium must be DROPPED,
 *    not honoured, or the operator reads a filtered calendar that silently
 *    shows nothing.
 *  - THE RENDERERS ESCAPE. Every area name, unit title and user name on this
 *    screen is operator-entered text interpolated into HTML by hand, with no
 *    theme layer in between. A missing check_plain() here is stored XSS in the
 *    back office, and it looks exactly like the line next to it.
 *
 * OUT OF SCOPE, deliberately: myapi_reservation_calendar_filter_form(), its
 * after_build and myapi_reservation_calendar_page(). The first two return
 * Drupal form arrays that only mean something inside the Form API, and the
 * third is the page callback that glues everything together with
 * drupal_add_css()/drupal_add_js() and a render array. Their PARTS are covered
 * here; wiring them together is tests/integration's job.
 */
class CalendarRenderTest extends TestCase {

  const AREA = 700;
  const UNIT = 45;
  const CONDOMINIUM = 12;
  const UID = 3;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_GET = [];
  }

  protected function tearDown(): void {
    $_GET = [];
    myapi_test_db_seed();
    myapi_test_static_reset();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One calendar row, the shape myapi_reservation_calendar_rows() answers.
   */
  private function row(array $spec = []) {
    $spec += [
      'nid'         => 800,
      'created'     => 1780000000,
      'date'        => '2026-06-15',
      'start_time'  => '10:00',
      'end_time'    => '11:30',
      'status'      => 'confirmed',
      'cancelled_by' => NULL,
      'cancel_reason' => NULL,
      'area_id'     => self::AREA,
      'area_title'  => 'Piscina',
      'unit_id'     => self::UNIT,
      'unit_title'  => 'A-101',
      'condominium_id' => self::CONDOMINIUM,
      'uid'         => self::UID,
      'user_name'   => 'pcordero',
      'user_mail'   => 'p@example.com',
      'user_first_name' => NULL,
      'user_last_name'  => NULL,
    ];

    return (object) $spec;
  }

  /**
   * A segment of the shape myapi_calendar_day_segments() produces.
   */
  private function segment(array $spec = []) {
    $spec += [
      'row'             => $this->row(),
      'nid'             => 800,
      'start_time'      => '10:00',
      'end_time'        => '11:30',
      'is_continuation' => FALSE,
      'ends_next_day'   => FALSE,
      // The week grid positions a chip from these two, which the day splitter
      // computes alongside the printable times.
      'start_min'       => 600,
      'end_min'         => 690,
    ];

    return $spec;
  }

  /* -------------------------------------------------------------------------
   * myapi_calendar_positive_int(): the parser every filter is built on.
   * ---------------------------------------------------------------------- */

  /**
   * A positive integer answers itself; everything else answers NULL, which the
   * filters read as "no filter".
   */
  public function testPositiveIntAcceptsOnlyPositiveIntegers() {
    $this->assertSame(1, myapi_calendar_positive_int('1'));
    $this->assertSame(12, myapi_calendar_positive_int(12));
    $this->assertSame(700, myapi_calendar_positive_int('700'));

    foreach (['0', '-1', '1.5', '01a', 'abc', '', ' 1', '1 ', '+1', ['1'], NULL, FALSE] as $value) {
      $this->assertNull(myapi_calendar_positive_int($value), json_encode($value));
    }

    // TRUE casts to '1' and is therefore accepted as node 1. It cannot arrive
    // from a query string — $_GET only ever carries strings and arrays — so it
    // is pinned as a property of the helper rather than as a hole.
    $this->assertSame(1, myapi_calendar_positive_int(TRUE));
  }

  /**
   * A leading-zero id is accepted and normalised — '012' is node 12, which is
   * what a hand-edited URL may carry.
   */
  public function testLeadingZerosAreNormalised() {
    $this->assertSame(12, myapi_calendar_positive_int('012'));
  }

  /* -------------------------------------------------------------------------
   * The building-admin scope.
   * ---------------------------------------------------------------------- */

  /**
   * WITH NO SCOPE the requested condominium is honoured as it is — that is the
   * unrestricted operator, who may look at any building.
   */
  public function testWithNoScopeTheRequestIsHonoured() {
    $this->assertSame(12, myapi_calendar_effective_condominium(12, NULL));
    $this->assertNull(myapi_calendar_effective_condominium(NULL, NULL));
  }

  /**
   * WITH A SCOPE the request is honoured only when it is INSIDE it; anything
   * else falls back to the whole scope rather than to "no filter". A building
   * admin asking for another tower gets their own buildings, never everyone's.
   */
  public function testAScopedOperatorNeverEscapesTheirBuildings() {
    $scope = [12, 13];

    $this->assertSame(12, myapi_calendar_effective_condominium(12, $scope));
    $this->assertSame(13, myapi_calendar_effective_condominium('13', $scope), 'a string request resolves');

    $this->assertSame($scope, myapi_calendar_effective_condominium(99, $scope), 'a foreign building falls back');
    $this->assertSame($scope, myapi_calendar_effective_condominium(NULL, $scope), 'no request means the whole scope');
  }

  /**
   * An EMPTY scope is still a scope: an operator who administers nothing sees
   * nothing, rather than everything.
   */
  public function testAnEmptyScopeShowsNothing() {
    $this->assertSame([], myapi_calendar_effective_condominium(12, []));
    $this->assertSame([], myapi_calendar_effective_condominium(NULL, []));
  }

  /* -------------------------------------------------------------------------
   * The option lists.
   * ---------------------------------------------------------------------- */

  /**
   * The condominium options are the published 'condominio' nodes, keyed by
   * nid.
   */
  public function testTheCondominiumOptionsArePublishedCondominios() {
    myapi_test_db_seed(['node' => [
      ['nid' => '12', 'title' => 'Torre Andalucía', 'type' => 'condominio', 'status' => '1'],
      ['nid' => '13', 'title' => 'Torre Bolívar', 'type' => 'condominio', 'status' => '1'],
      ['nid' => '14', 'title' => 'Torre Oculta', 'type' => 'condominio', 'status' => '0'],
      ['nid' => '15', 'title' => 'A-101', 'type' => 'vivienda', 'status' => '1'],
    ]]);

    $options = myapi_calendar_condominium_options();

    $this->assertSame(['12' => 'Torre Andalucía', '13' => 'Torre Bolívar'], $options);
  }

  /**
   * A scope narrows the options, and an EMPTY scope answers an empty list
   * WITHOUT querying — an "IN ()" is invalid SQL in Drupal 7.
   */
  public function testAScopeNarrowsTheOptionsAndAnEmptyOneSkipsTheQuery() {
    myapi_test_db_seed(['node' => [
      ['nid' => '12', 'title' => 'Torre Andalucía', 'type' => 'condominio', 'status' => '1'],
      ['nid' => '13', 'title' => 'Torre Bolívar', 'type' => 'condominio', 'status' => '1'],
    ]]);

    $this->assertSame(['12' => 'Torre Andalucía'], myapi_calendar_condominium_options([12]));

    myapi_test_db_seed(['node' => []]);
    $this->assertSame([], myapi_calendar_condominium_options([]));
    $this->assertSame([], myapi_test_db_queries('node'), 'no query at all');
  }

  /**
   * The area options are the published areas of the chosen condominium, and a
   * NULL or empty condominium answers an empty list without querying.
   */
  public function testTheAreaOptionsAreScopedToTheCondominium() {
    myapi_test_db_seed(['node' => [
      ['nid' => '700', 'title' => 'Piscina', 'type' => 'area', 'status' => '1', 'field_condominium_target_id' => '12'],
      ['nid' => '701', 'title' => 'Gimnasio', 'type' => 'area', 'status' => '1', 'field_condominium_target_id' => '12'],
      ['nid' => '702', 'title' => 'Salón', 'type' => 'area', 'status' => '1', 'field_condominium_target_id' => '13'],
      ['nid' => '703', 'title' => 'Cerrada', 'type' => 'area', 'status' => '0', 'field_condominium_target_id' => '12'],
    ]]);

    // Ordered by title, so Gimnasio comes first.
    $this->assertSame(['701' => 'Gimnasio', '700' => 'Piscina'], myapi_calendar_area_options(12));

    myapi_test_db_seed(['node' => []]);
    $this->assertSame([], myapi_calendar_area_options(NULL));
    $this->assertSame([], myapi_calendar_area_options([]));
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /* -------------------------------------------------------------------------
   * myapi_calendar_filters(): what the screen actually paints.
   * ---------------------------------------------------------------------- */

  /**
   * The defaults with an empty query string: the month view, today, no
   * condominium, no area, and the confirmed reservations only.
   */
  public function testTheDefaultFilters() {
    $filters = myapi_calendar_filters([]);

    $this->assertSame('month', $filters['view']);
    $this->assertSame(date('Y-m-d'), $filters['date']);
    $this->assertNull($filters['condominium']);
    $this->assertNull($filters['area']);
    $this->assertSame('confirmed', $filters['status']);
    $this->assertSame(['confirmed'], $filters['statuses']);
  }

  /**
   * Only 'week' switches the view; every other value is the month.
   */
  public function testOnlyWeekSwitchesTheView() {
    $_GET['view'] = 'week';
    $this->assertSame('week', myapi_calendar_filters([])['view']);

    foreach (['Week', 'day', 'month', '', '1', ['week']] as $value) {
      $_GET['view'] = $value;
      $this->assertSame('month', myapi_calendar_filters([])['view'], json_encode($value));
    }
  }

  /**
   * The date accepts what the API accepts and falls back to TODAY for anything
   * else — a broken bookmark opens the calendar, it does not break it.
   */
  public function testTheDateFallsBackToToday() {
    $_GET['date'] = '2026-06-15';
    $this->assertSame('2026-06-15', myapi_calendar_filters([])['date']);

    foreach (['2026-13-40', '2026-02-30', '15/06/2026', 'hoy', '', ['2026-06-15']] as $value) {
      $_GET['date'] = $value;
      $this->assertSame(date('Y-m-d'), myapi_calendar_filters([])['date'], json_encode($value));
    }
  }

  /**
   * 'all' is the third status and expands into BOTH, which is what makes the
   * cancelled chips appear next to the confirmed ones.
   */
  public function testTheStatusFilterAndItsAllValue() {
    $_GET['status'] = 'cancelled';
    $filters = myapi_calendar_filters([]);
    $this->assertSame('cancelled', $filters['status']);
    $this->assertSame(['cancelled'], $filters['statuses']);

    $_GET['status'] = 'all';
    $filters = myapi_calendar_filters([]);
    $this->assertSame('all', $filters['status']);
    $this->assertSame(['confirmed', 'cancelled'], $filters['statuses']);

    foreach (['Confirmed', 'todos', '', ['all']] as $value) {
      $_GET['status'] = $value;
      $this->assertSame('confirmed', myapi_calendar_filters([])['status'], json_encode($value));
    }
  }

  /**
   * AN AREA THAT IS NOT IN THE CHOSEN CONDOMINIUM IS DROPPED. Honouring it
   * would paint an empty calendar and leave the operator staring at a filter
   * that silently matches nothing.
   */
  public function testAnAreaOutsideTheChosenCondominiumIsDropped() {
    $options = [700 => 'Piscina', 701 => 'Gimnasio'];

    $_GET = ['condominium' => '12', 'area' => '700'];
    $this->assertSame(700, myapi_calendar_filters($options)['area']);

    $_GET = ['condominium' => '12', 'area' => '702'];
    $this->assertNull(myapi_calendar_filters($options)['area'], 'an area of another building is dropped');
  }

  /**
   * WITHOUT A CONDOMINIUM the area survives whatever the option list says —
   * the cross-check only applies once a building has been chosen, which is
   * pinned here so the guard is not "simplified" into always checking.
   */
  public function testWithoutACondominiumTheAreaIsNotCrossChecked() {
    $_GET = ['area' => '702'];

    $this->assertSame(702, myapi_calendar_filters([700 => 'Piscina'])['area']);
  }

  /**
   * A malformed condominium or area is simply absent.
   */
  public function testMalformedIdsAreAbsent() {
    foreach (['0', '-1', 'abc', '', ['12']] as $value) {
      $_GET = ['condominium' => $value, 'area' => $value];
      $filters = myapi_calendar_filters([]);
      $this->assertNull($filters['condominium'], json_encode($value));
      $this->assertNull($filters['area'], json_encode($value));
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_calendar_url(): every position of the calendar is a plain URL.
   * ---------------------------------------------------------------------- */

  /**
   * The URL carries the view, the date and the status always, and the two
   * optional filters only when they are set — so a default calendar has a
   * clean address.
   */
  public function testTheUrlCarriesTheFiltersItHas() {
    $filters = ['view' => 'month', 'date' => '2026-06-15', 'status' => 'confirmed', 'condominium' => NULL, 'area' => NULL];

    $url = myapi_calendar_url($filters, '2026-07-01');

    $this->assertStringContainsString('view=month', $url);
    $this->assertStringContainsString('date=2026-07-01', $url);
    $this->assertStringContainsString('status=confirmed', $url);
    $this->assertStringNotContainsString('condominium', $url);
    $this->assertStringNotContainsString('area', $url);

    $filters['condominium'] = 12;
    $filters['area'] = 700;
    $url = myapi_calendar_url($filters, '2026-07-01');
    $this->assertStringContainsString('condominium=12', $url);
    $this->assertStringContainsString('area=700', $url);
  }

  /**
   * The date is the one passed in and not the one in the filters — that is
   * what makes the previous/next links move — and the view can be overridden,
   * which is what the month/week switch does.
   */
  public function testTheDateAndTheViewCanBeOverridden() {
    $filters = ['view' => 'month', 'date' => '2026-06-15', 'status' => 'all', 'condominium' => NULL, 'area' => NULL];

    $url = myapi_calendar_url($filters, '2026-05-01', 'week');

    $this->assertStringContainsString('view=week', $url);
    $this->assertStringContainsString('date=2026-05-01', $url);
    $this->assertStringNotContainsString('date=2026-06-15', $url);
  }

  /* -------------------------------------------------------------------------
   * The chips.
   * ---------------------------------------------------------------------- */

  /**
   * A CANCELLED RESERVATION IS GREY WHATEVER ITS AREA. The status wins over
   * the colour, which is what lets an operator read a mixed calendar at a
   * glance.
   */
  public function testACancelledChipIsAlwaysTheCancelledColour() {
    $this->assertSame('myapi-cal-cancelled', myapi_calendar_chip_color_class($this->row(['status' => 'cancelled'])));
    $this->assertSame(
      'myapi-cal-cancelled',
      myapi_calendar_chip_color_class($this->row(['status' => 'cancelled', 'area_id' => NULL])),
      'even with no area'
    );
  }

  /**
   * A reservation with no area has its own class, and every other one gets a
   * colour derived from its area — so the same area is always the same colour.
   */
  public function testTheColourIsDerivedFromTheAreaAndIsStable() {
    $this->assertSame('myapi-cal-noarea', myapi_calendar_chip_color_class($this->row(['area_id' => NULL])));

    $first = myapi_calendar_chip_color_class($this->row(['area_id' => 700]));
    $again = myapi_calendar_chip_color_class($this->row(['area_id' => 700, 'nid' => 999]));
    $other = myapi_calendar_chip_color_class($this->row(['area_id' => 701]));

    $this->assertSame($first, $again, 'the same area is always the same colour');
    $this->assertStringStartsWith('myapi-cal-c', $first);
    $this->assertNotSame($first, $other);
  }

  /**
   * A plain chip carries its schedule, its area and the nid the JS opens the
   * detail panel with.
   */
  public function testAPlainChipCarriesItsScheduleAreaAndNid() {
    $html = myapi_calendar_render_chip($this->segment());

    $this->assertStringContainsString('data-nid="800"', $html);
    $this->assertStringContainsString('10:00–11:30', $html);
    $this->assertStringContainsString('Piscina', $html);
    $this->assertStringNotContainsString('myapi-cal-continuation', $html);
    $this->assertStringNotContainsString('+1', $html);
  }

  /**
   * AN OVERNIGHT BOOKING IS TWO CHIPS THAT OPEN THE SAME PANEL: the first
   * carries a '+1' marker and the second an arrow, and both carry the same
   * nid.
   */
  public function testAnOvernightBookingIsTwoChipsWithTheSameNid() {
    $row = $this->row(['start_time' => '22:00', 'end_time' => '02:00']);

    $first = myapi_calendar_render_chip($this->segment([
      'row' => $row, 'start_time' => '22:00', 'end_time' => '23:59', 'ends_next_day' => TRUE,
    ]));
    $second = myapi_calendar_render_chip($this->segment([
      'row' => $row, 'start_time' => '00:00', 'end_time' => '02:00', 'is_continuation' => TRUE,
    ]));

    $this->assertStringContainsString('+1', $first);
    $this->assertStringContainsString('22:00–02:00', $first, 'the first chip shows the real end');

    $this->assertStringContainsString('myapi-cal-continuation', $second);
    $this->assertStringContainsString('↳', $second);
    $this->assertStringContainsString('00:00–02:00', $second);

    $this->assertStringContainsString('data-nid="800"', $first);
    $this->assertStringContainsString('data-nid="800"', $second);
  }

  /**
   * The inline style is optional and escaped — it is what positions a chip in
   * the week grid.
   */
  public function testTheInlineStyleIsOptionalAndEscaped() {
    $plain = myapi_calendar_render_chip($this->segment());
    $this->assertStringNotContainsString('style=', $plain);

    $styled = myapi_calendar_render_chip($this->segment(), 'top:10%;height:20%');
    $this->assertStringContainsString('style="top:10%;height:20%"', $styled);

    $hostile = myapi_calendar_render_chip($this->segment(), 'x" onload="alert(1)');
    $this->assertStringNotContainsString('onload="alert', $hostile);
  }

  /**
   * THE AREA NAME IS ESCAPED. It is operator-entered text interpolated into
   * HTML by hand, and this screen has no theme layer to catch it.
   */
  public function testTheAreaNameIsEscapedInTheChip() {
    $html = myapi_calendar_render_chip($this->segment([
      'row' => $this->row(['area_title' => '<script>alert(1)</script>']),
    ]));

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
  }

  /* -------------------------------------------------------------------------
   * The legend.
   * ---------------------------------------------------------------------- */

  /**
   * The legend lists one entry per area actually painted, deduplicated, with
   * the swatch of that area's colour.
   */
  public function testTheLegendListsEachPaintedAreaOnce() {
    $segments = [
      $this->segment(['row' => $this->row(['area_id' => 700, 'area_title' => 'Piscina'])]),
      $this->segment(['row' => $this->row(['area_id' => 700, 'area_title' => 'Piscina'])]),
      $this->segment(['row' => $this->row(['area_id' => 701, 'area_title' => 'Gimnasio'])]),
    ];

    $html = myapi_calendar_render_legend($segments);

    $this->assertSame(2, substr_count($html, 'myapi-cal-legend-item'));
    $this->assertStringContainsString('Piscina', $html);
    $this->assertStringContainsString('Gimnasio', $html);
    $this->assertStringContainsString('myapi-cal-c' . myapi_calendar_area_color_index(700), $html);
  }

  /**
   * Cancelled reservations and reservations with no area are NOT in the
   * legend: it explains the colours, and neither of those has one.
   */
  public function testCancelledAndAreaLessSegmentsAreNotInTheLegend() {
    $segments = [
      $this->segment(['row' => $this->row(['status' => 'cancelled'])]),
      $this->segment(['row' => $this->row(['area_id' => NULL, 'area_title' => NULL])]),
    ];

    $this->assertSame('', myapi_calendar_render_legend($segments));
    $this->assertSame('', myapi_calendar_render_legend([]), 'and an empty day has no legend at all');
  }

  /**
   * THE LEGEND IS SORTED DE-ACCENTED. Comparing UTF-8 bytes would push 'Área'
   * past 'Zona', because the accented letters sit above the ASCII range.
   */
  public function testTheLegendIsSortedDeAccented() {
    $segments = [
      $this->segment(['row' => $this->row(['area_id' => 702, 'area_title' => 'Zona verde'])]),
      $this->segment(['row' => $this->row(['area_id' => 700, 'area_title' => 'Área social'])]),
      $this->segment(['row' => $this->row(['area_id' => 701, 'area_title' => 'Gimnasio'])]),
    ];

    $html = myapi_calendar_render_legend($segments);

    $area = strpos($html, 'Área social');
    $gym = strpos($html, 'Gimnasio');
    $green = strpos($html, 'Zona verde');

    $this->assertLessThan($gym, $area, 'the accented name sorts first, not last');
    $this->assertLessThan($green, $gym);
  }

  /* -------------------------------------------------------------------------
   * The grids.
   * ---------------------------------------------------------------------- */

  /**
   * myapi_calendar_grid_segments() collects the segments of the days actually
   * painted, in day order, and ignores the ones outside the grid.
   */
  public function testTheGridCollectsOnlyThePaintedDays() {
    $by_day = [
      '2026-06-14' => [$this->segment(['nid' => 1])],
      '2026-06-15' => [$this->segment(['nid' => 2]), $this->segment(['nid' => 3])],
      '2026-07-01' => [$this->segment(['nid' => 4])],
    ];

    $segments = myapi_calendar_grid_segments($by_day, ['2026-06-15', '2026-06-14']);

    $this->assertSame([2, 3, 1], array_column($segments, 'nid'), 'in the order of the days given');
    $this->assertSame([], myapi_calendar_grid_segments($by_day, ['2026-08-01']));
    $this->assertSame([], myapi_calendar_grid_segments([], ['2026-06-15']));
  }

  /**
   * The month grid paints one cell per day, marks the days of other months and
   * puts each day's chips inside its own cell.
   */
  public function testTheMonthGridMarksTheDaysOfOtherMonths() {
    $weeks = [
      ['2026-05-31', '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06'],
    ];
    $by_day = ['2026-06-02' => [$this->segment()]];

    $html = myapi_calendar_render_month($weeks, $by_day, '2026-06-15');

    $this->assertStringContainsString('myapi-cal-month', $html);
    $this->assertSame(7, substr_count($html, 'myapi-cal-day"') + substr_count($html, 'myapi-cal-day '));
    $this->assertSame(1, substr_count($html, 'myapi-cal-out'), 'only 31 May is outside the month');
    $this->assertSame(1, substr_count($html, 'myapi-cal-chip"') + substr_count($html, 'myapi-cal-chip '));
  }

  /**
   * TODAY IS MARKED, which is the one cell the operator looks for first.
   */
  public function testTodayIsMarkedInTheMonthGrid() {
    $today = date('Y-m-d');
    $weeks = [[$today]];

    $this->assertStringContainsString('myapi-cal-today', myapi_calendar_render_month($weeks, [], $today));
    $this->assertStringNotContainsString(
      'myapi-cal-today',
      myapi_calendar_render_month([['2020-01-01']], [], '2020-01-01')
    );
  }

  /**
   * The week grid paints one column per day and positions each chip with an
   * inline style — that is what makes a 10:00 booking sit at ten o'clock.
   */
  public function testTheWeekGridPositionsItsChips() {
    $days = ['2026-06-15', '2026-06-16'];
    $by_day = ['2026-06-15' => [$this->segment()]];

    $html = myapi_calendar_render_week($days, $by_day);

    $this->assertStringContainsString('myapi-cal-week-view', $html);
    $this->assertSame(2, substr_count($html, 'myapi-cal-daycol"'), 'one column per day');
    $this->assertStringContainsString('style="top:', $html, 'the chip is positioned');
    $this->assertStringContainsString('Piscina', $html);
  }

  /**
   * An empty week still renders its columns rather than nothing: the operator
   * sees a calendar, not a blank page.
   */
  public function testAnEmptyWeekStillRendersItsColumns() {
    $html = myapi_calendar_render_week(['2026-06-15', '2026-06-16'], []);

    $this->assertStringContainsString('myapi-cal-week-view', $html);
    $this->assertSame(2, substr_count($html, 'myapi-cal-daycol"'));
    $this->assertStringContainsString('myapi-cal-hour', $html, 'the hour ruler is still drawn');
    $this->assertStringNotContainsString('myapi-cal-chip', $html);
  }

  /* -------------------------------------------------------------------------
   * The navigation.
   * ---------------------------------------------------------------------- */

  /**
   * The month navigation steps a MONTH at a time, from the first of the month
   * — stepping from the 31st would skip February.
   */
  public function testTheMonthNavigationStepsAMonthFromTheFirst() {
    $filters = ['view' => 'month', 'date' => '2026-03-31', 'status' => 'confirmed', 'condominium' => NULL, 'area' => NULL];

    $html = myapi_calendar_render_nav($filters, ['grid_from' => '2026-03-01', 'grid_to' => '2026-03-31']);

    $this->assertStringContainsString('date=2026-02-01', $html, 'the previous month');
    $this->assertStringContainsString('date=2026-04-01', $html, 'the next month');
  }

  /**
   * The week navigation steps seven days.
   */
  public function testTheWeekNavigationStepsSevenDays() {
    $filters = ['view' => 'week', 'date' => '2026-06-15', 'status' => 'confirmed', 'condominium' => NULL, 'area' => NULL];

    $html = myapi_calendar_render_nav($filters, ['grid_from' => '2026-06-15', 'grid_to' => '2026-06-21']);

    $this->assertStringContainsString('date=2026-06-08', $html);
    $this->assertStringContainsString('date=2026-06-22', $html);
  }

  /**
   * The view switch offers both views and marks the current one.
   */
  public function testTheViewSwitchOffersBothViews() {
    $filters = ['view' => 'month', 'date' => '2026-06-15', 'status' => 'confirmed', 'condominium' => NULL, 'area' => NULL];

    $html = myapi_calendar_render_nav($filters, ['grid_from' => '2026-06-01', 'grid_to' => '2026-06-30']);

    $this->assertStringContainsString('view=week', $html);
    $this->assertStringContainsString('view=month', $html);
    $this->assertStringContainsString('myapi-cal-view-link', $html);
  }

  /* -------------------------------------------------------------------------
   * The detail panels.
   * ---------------------------------------------------------------------- */

  /**
   * One panel per reservation, keyed by nid so the chip's data-nid opens it,
   * carrying the ten values of the calendar's own labels.
   */
  public function testOnePanelPerReservationCarriesTheLabels() {
    $html = myapi_calendar_render_details([$this->row()], [self::CONDOMINIUM => 'Torre Andalucía']);

    // The panel is addressed by id, which is what the chip's data-nid resolves
    // to on the client side.
    $this->assertStringContainsString('id="myapi-cal-detail-800"', $html);
    $this->assertStringContainsString('hidden', $html, 'panels start closed');
    $this->assertStringContainsString('Piscina', $html);
    $this->assertStringContainsString('A-101', $html);
    $this->assertStringContainsString('Torre Andalucía', $html);
    $this->assertStringContainsString('pcordero', $html);
    $this->assertStringContainsString('15/06/2026', $html);
    $this->assertStringContainsString('1h 30min', $html);
  }

  /**
   * A cancelled reservation shows who cancelled it and why.
   */
  public function testACancelledPanelShowsItsReason() {
    $row = $this->row([
      'status' => 'cancelled', 'cancelled_by' => 'user', 'cancel_reason' => 'Cambio de planes',
    ]);

    $html = myapi_calendar_render_details([$row], []);

    $this->assertStringContainsString('Cambio de planes', $html);
  }

  /**
   * THE PANEL ESCAPES EVERY OPERATOR-ENTERED VALUE. A unit named with markup
   * must not become live HTML in the back office.
   */
  public function testThePanelEscapesEveryValue() {
    $row = $this->row([
      'unit_title' => '<img src=x onerror=alert(1)>',
      'area_title' => '<b>Piscina</b>',
      'user_name'  => '<i>pcordero</i>',
      'cancel_reason' => '<script>alert(1)</script>',
      'status' => 'cancelled',
    ]);

    $html = myapi_calendar_render_details([$row], []);

    $this->assertStringNotContainsString('<img src=x', $html);
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringNotContainsString('<b>Piscina</b>', $html);
    $this->assertStringContainsString('&lt;', $html);
  }

  /**
   * An empty set of rows renders no panel at all.
   */
  public function testNoRowsRenderNoPanels() {
    $html = myapi_calendar_render_details([], []);

    $this->assertStringNotContainsString('myapi-cal-detail-', $html);
  }

  /* -------------------------------------------------------------------------
   * The labels, on their degraded shapes.
   * ---------------------------------------------------------------------- */

  /**
   * Each label has three states — present, deleted, absent — and the deleted
   * one keeps the id so an operator can look it up.
   */
  public function testEachLabelHasItsThreeStates() {
    $this->assertSame('Piscina', myapi_calendar_area_label($this->row()));
    $this->assertSame('Área eliminada (#700)', myapi_calendar_area_label($this->row(['area_title' => NULL])));
    $this->assertSame('Sin área', myapi_calendar_area_label($this->row(['area_id' => NULL, 'area_title' => NULL])));

    $this->assertSame('A-101', myapi_calendar_unit_label($this->row()));
    $this->assertSame('Vivienda eliminada (#45)', myapi_calendar_unit_label($this->row(['unit_title' => NULL])));
    $this->assertSame('Sin vivienda', myapi_calendar_unit_label($this->row(['unit_id' => NULL, 'unit_title' => NULL])));

    $options = [self::CONDOMINIUM => 'Torre Andalucía'];
    $this->assertSame('Torre Andalucía', myapi_calendar_condominium_label($this->row(), $options));
    $this->assertSame('Condominio no disponible (#12)', myapi_calendar_condominium_label($this->row(), []));
    $this->assertSame('Sin condominio', myapi_calendar_condominium_label($this->row(['condominium_id' => NULL]), $options));
  }

  /**
   * The two user labels differ on purpose: the ADMIN one keeps the username
   * next to the full name, because an operator searching by account needs it;
   * the RESIDENT-facing one does not.
   */
  public function testTheTwoUserLabelsDifferOnPurpose() {
    $row = $this->row(['user_first_name' => 'Pablo', 'user_last_name' => 'Cordero']);

    $admin = myapi_calendar_user_label($row);
    $name_only = myapi_calendar_user_name_label($row);

    $this->assertStringContainsString('Pablo Cordero', $admin);
    $this->assertStringContainsString('pcordero', $admin, 'the admin label keeps the username');
    $this->assertSame('Pablo Cordero', $name_only);
    $this->assertStringNotContainsString('pcordero', $name_only);
  }

  /**
   * The full-name helper trims, escapes, and answers NULL when there is
   * nothing to show.
   */
  public function testTheFullNameHelper() {
    // The trim() is applied to the JOINED string and not to each half, so the
    // inner padding survives. Pinned as it is: no real profile carries it, and
    // the alternative would change the text the back office already prints.
    $this->assertSame('Pablo   Cordero', _myapi_reservation_full_name($this->row([
      'user_first_name' => ' Pablo ', 'user_last_name' => ' Cordero ',
    ])));
    $this->assertSame('Pablo Cordero', _myapi_reservation_full_name($this->row([
      'user_first_name' => 'Pablo', 'user_last_name' => 'Cordero',
    ])));
    $this->assertSame('Pablo', _myapi_reservation_full_name($this->row(['user_first_name' => 'Pablo'])));
    $this->assertSame('Cordero', _myapi_reservation_full_name($this->row(['user_last_name' => 'Cordero'])));
    $this->assertNull(_myapi_reservation_full_name($this->row()));
    $this->assertNull(_myapi_reservation_full_name($this->row(['user_first_name' => '   '])));

    $this->assertSame(
      '&lt;b&gt;Pablo&lt;/b&gt;',
      _myapi_reservation_full_name($this->row(['user_first_name' => '<b>Pablo</b>']))
    );
  }
}
