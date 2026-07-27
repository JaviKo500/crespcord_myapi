<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_calendar.inc';

/**
 * Unit tests for the pure helpers of the back-office reservation calendar
 * (SPEC 47), which live in includes/myapi.reservation_calendar.inc.
 *
 * Covers the six functions that carry the whole risk of the page — the grid
 * arithmetic, the overnight split and the lane assignment — none of which
 * touches the database or Drupal:
 *   - myapi_calendar_range()            — visible range + the extra query day.
 *   - myapi_calendar_month_grid()       — weeks of exactly 7 days, Monday first.
 *   - myapi_calendar_week_days()        — the 7 days of a week.
 *   - myapi_calendar_day_segments()     — one or two segments per reservation.
 *   - myapi_calendar_assign_lanes()     — greedy lanes per connected cluster.
 *   - myapi_calendar_area_color_index() — deterministic palette index.
 *
 * The query helper myapi_reservation_calendar_rows() is deliberately NOT tested
 * here: it runs db_select(), so testing it would require Drupal booted, which is
 * exactly what tests/unit avoids across the whole repo (same precedent as
 * SPEC 40-46). Its verification is `php -l` plus the manual matrix in
 * docs/reservation-calendar.md.
 *
 * includes/myapi.reservation_query.inc is required because the segment split
 * reuses myapi_reservation_time_to_minutes() instead of duplicating the
 * 'HH:MM' -> minutes conversion (rule 5 of CLAUDE.md).
 *
 * Fixed dates are used throughout so the day arithmetic is deterministic, and
 * every assertion is on plain 'Y-m-d' strings: no DateTime, no timezone.
 *
 * Reference dates and why each one is here:
 *   - 2026-08-15  Saturday, in a month that starts Saturday and ends Monday.
 *   - 2026-06-01  Monday: the month starts exactly on grid_from.
 *   - 2026-05-31  Sunday: the month ends exactly on grid_to.
 *   - 2021-02-01  Monday, 28 days: the only shape that yields exactly 4 weeks.
 *   - 2028-02-01  Leap February.
 *   - 2026-11-01  Sunday-starting month of 30 days -> 6 weeks.
 */
class ReservationCalendarTest extends TestCase {

  /**
   * Builds a raw reservation row as returned by
   * myapi_reservation_calendar_rows().
   *
   * Only the four fields the segment split actually reads are mandatory; the
   * rest of the row travels untouched inside the segment, for the detail panel.
   */
  private function row($nid, $date, $start_time, $end_time, $extra = []) {
    $row = new stdClass();
    $row->nid = $nid;
    $row->date = $date;
    $row->start_time = $start_time;
    $row->end_time = $end_time;
    foreach ($extra as $key => $value) {
      $row->{$key} = $value;
    }
    return $row;
  }

  /**
   * Builds a bare segment for the lane tests.
   *
   * myapi_calendar_assign_lanes() only reads nid, start_min and end_min, so the
   * lane fixtures stay free of the segment split.
   */
  private function seg($nid, $start_min, $end_min) {
    return [
      'nid' => $nid,
      'start_min' => $start_min,
      'end_min' => $end_min,
    ];
  }

  /**
   * Reduces a lane result to 'nid:lane/lanes_total' strings, in order.
   */
  private function lanes($segments) {
    return array_map(function ($segment) {
      return $segment['nid'] . ':' . $segment['lane'] . '/' . $segment['lanes_total'];
    }, $segments);
  }

  /* ---- myapi_calendar_range() ---- */

  public function testMonthRangeCoversTheWholeMonthPlusItsPartialWeeks() {
    // August 2026 starts on Saturday and ends on Monday, so the grid runs from
    // the Monday before the 1st to the Sunday after the 31st: 6 weeks.
    $range = myapi_calendar_range('month', '2026-08-15');

    $this->assertSame([
      'grid_from' => '2026-07-27',
      'grid_to' => '2026-09-06',
      'query_from' => '2026-07-26',
    ], $range);
  }

  public function testWeekRangeIsTheMondayToSundayOfTheReferenceDay() {
    // 2026-08-15 is a Saturday.
    $range = myapi_calendar_range('week', '2026-08-15');

    $this->assertSame([
      'grid_from' => '2026-08-10',
      'grid_to' => '2026-08-16',
      'query_from' => '2026-08-09',
    ], $range);
  }

  public function testQueryFromIsAlwaysGridFromMinusOneDay() {
    // The extra day ahead is what keeps the tail of an overnight that starts
    // the day before grid_from inside the view.
    foreach (['month', 'week'] as $view) {
      $range = myapi_calendar_range($view, '2026-11-01');
      $expected = date('Y-m-d', strtotime($range['grid_from'] . ' -1 day'));
      $this->assertSame($expected, $range['query_from'], 'view ' . $view);
    }
  }

  public function testMonthStartingOnMondayGetsNoLeadingEmptyWeek() {
    // 2026-06-01 is a Monday: grid_from is the 1st itself.
    $range = myapi_calendar_range('month', '2026-06-15');

    $this->assertSame('2026-06-01', $range['grid_from']);
    $this->assertSame('2026-07-05', $range['grid_to']);
  }

  public function testMonthEndingOnSundayGetsNoTrailingEmptyWeek() {
    // 2026-05-31 is a Sunday: grid_to is the 31st itself.
    $range = myapi_calendar_range('month', '2026-05-10');

    $this->assertSame('2026-04-27', $range['grid_from']);
    $this->assertSame('2026-05-31', $range['grid_to']);
  }

  public function testAnyViewOtherThanWeekIsTreatedAsMonth() {
    $month = myapi_calendar_range('month', '2026-08-15');

    $this->assertSame($month, myapi_calendar_range('daily', '2026-08-15'));
    $this->assertSame($month, myapi_calendar_range('', '2026-08-15'));
  }

  public function testReferenceDayInsideAWeekAlwaysYieldsTheSameWeek() {
    $monday = myapi_calendar_range('week', '2026-08-10');
    $sunday = myapi_calendar_range('week', '2026-08-16');

    $this->assertSame($monday, $sunday);
  }

  /* ---- myapi_calendar_month_grid() ---- */

  public function testMonthGridWeeksAlwaysHaveSevenDaysStartingOnMonday() {
    foreach (['2026-08-15', '2028-02-01', '2026-11-01', '2021-02-01', '2026-06-01'] as $ref) {
      $weeks = myapi_calendar_month_grid($ref);

      $this->assertGreaterThanOrEqual(4, count($weeks), $ref);
      $this->assertLessThanOrEqual(6, count($weeks), $ref);

      foreach ($weeks as $index => $week) {
        $this->assertCount(7, $week, $ref . ' week ' . $index);
        $this->assertSame('Mon', date('D', strtotime($week[0])), $ref . ' week ' . $index);
      }
    }
  }

  public function testMonthGridIsAContiguousRunFromGridFromToGridTo() {
    foreach (['2026-08-15', '2028-02-01', '2026-11-01', '2021-02-01', '2026-05-10'] as $ref) {
      $range = myapi_calendar_range('month', $ref);
      $days = call_user_func_array('array_merge', myapi_calendar_month_grid($ref));

      $this->assertSame($range['grid_from'], reset($days), $ref);
      $this->assertSame($range['grid_to'], end($days), $ref);
      $this->assertSame(count($days), count(array_unique($days)), $ref . ' has repeated days');

      // No gaps: every day is the previous one plus exactly one day.
      $previous = NULL;
      foreach ($days as $day) {
        if ($previous !== NULL) {
          $this->assertSame(date('Y-m-d', strtotime($previous . ' +1 day')), $day, $ref);
        }
        $previous = $day;
      }
    }
  }

  public function testMonthGridContainsEveryDayOfTheReferenceMonth() {
    // The last day of a month that ends mid-week must be painted: it is the
    // case that separates "Sunday after the last day" from "last Sunday".
    $days = call_user_func_array('array_merge', myapi_calendar_month_grid('2026-08-15'));

    $this->assertContains('2026-08-01', $days);
    $this->assertContains('2026-08-31', $days);
    $this->assertCount(42, $days);
  }

  public function testLeapFebruaryGridIncludesTheTwentyNinth() {
    $days = call_user_func_array('array_merge', myapi_calendar_month_grid('2028-02-01'));

    $this->assertContains('2028-02-29', $days);
    $this->assertNotContains('2028-02-30', $days);
  }

  public function testFebruaryStartingOnMondayIsExactlyFourWeeks() {
    // February 2021: Monday the 1st, Sunday the 28th. The only shape with no
    // partial week at either end.
    $weeks = myapi_calendar_month_grid('2021-02-01');

    $this->assertCount(4, $weeks);
    $this->assertSame('2021-02-01', $weeks[0][0]);
    $this->assertSame('2021-02-28', $weeks[3][6]);
  }

  public function testThirtyDayMonthStartingOnSundayIsSixWeeks() {
    $weeks = myapi_calendar_month_grid('2026-11-01');

    $this->assertCount(6, $weeks);
    $this->assertSame('2026-10-26', $weeks[0][0]);
    $this->assertSame('2026-12-06', $weeks[5][6]);
  }

  /* ---- myapi_calendar_week_days() ---- */

  public function testWeekDaysRunsMondayToSunday() {
    // 2026-08-15 is a Saturday.
    $this->assertSame([
      '2026-08-10',
      '2026-08-11',
      '2026-08-12',
      '2026-08-13',
      '2026-08-14',
      '2026-08-15',
      '2026-08-16',
    ], myapi_calendar_week_days('2026-08-15'));
  }

  public function testWeekDaysIsTheSameForEveryDayOfThatWeek() {
    $expected = myapi_calendar_week_days('2026-08-15');

    $this->assertSame($expected, myapi_calendar_week_days('2026-08-10'));
    $this->assertSame($expected, myapi_calendar_week_days('2026-08-16'));
  }

  public function testWeekDaysCrossesTheMonthBoundary() {
    // 2026-09-01 is a Tuesday: its week starts in August.
    $days = myapi_calendar_week_days('2026-09-01');

    $this->assertSame('2026-08-31', $days[0]);
    $this->assertSame('2026-09-06', $days[6]);
  }

  /* ---- myapi_calendar_day_segments() ---- */

  public function testEmptyRowsProduceNoSegments() {
    $this->assertSame([], myapi_calendar_day_segments([]));
  }

  public function testSameDayReservationProducesOneSegment() {
    $days = myapi_calendar_day_segments([$this->row(11, '2026-08-15', '10:00', '12:00')]);

    $this->assertSame(['2026-08-15'], array_keys($days));
    $this->assertCount(1, $days['2026-08-15']);

    $segment = $days['2026-08-15'][0];
    $this->assertSame('10:00', $segment['start_time']);
    $this->assertSame('12:00', $segment['end_time']);
    $this->assertSame(600, $segment['start_min']);
    $this->assertSame(720, $segment['end_min']);
    $this->assertFalse($segment['ends_next_day']);
    $this->assertFalse($segment['is_continuation']);
  }

  public function testOvernightReservationProducesTwoSegments() {
    $days = myapi_calendar_day_segments([$this->row(12, '2026-08-15', '22:00', '02:00')]);

    $this->assertSame(['2026-08-15', '2026-08-16'], array_keys($days));

    $head = $days['2026-08-15'][0];
    $this->assertSame(1320, $head['start_min']);
    $this->assertSame(1440, $head['end_min']);
    $this->assertTrue($head['ends_next_day']);
    $this->assertFalse($head['is_continuation']);

    $tail = $days['2026-08-16'][0];
    $this->assertSame(0, $tail['start_min']);
    $this->assertSame(120, $tail['end_min']);
    $this->assertSame('00:00', $tail['start_time']);
    $this->assertSame('02:00', $tail['end_time']);
    $this->assertTrue($tail['is_continuation']);
    $this->assertFalse($tail['ends_next_day']);
  }

  public function testReservationEndingAtMidnightProducesOnlyTheFirstSegment() {
    // 20:00 -> 00:00 crosses by the end <= start rule, but the second segment
    // would have zero duration, so it is not emitted. This is the case that
    // separates the correct implementation from the naive one.
    $days = myapi_calendar_day_segments([$this->row(13, '2026-08-15', '20:00', '00:00')]);

    $this->assertSame(['2026-08-15'], array_keys($days));
    $this->assertCount(1, $days['2026-08-15']);
    $this->assertSame(1200, $days['2026-08-15'][0]['start_min']);
    $this->assertSame(1440, $days['2026-08-15'][0]['end_min']);
    $this->assertTrue($days['2026-08-15'][0]['ends_next_day']);
  }

  public function testFullDayReservationProducesTwoSegmentsAddingUpToTwentyFourHours() {
    $days = myapi_calendar_day_segments([$this->row(14, '2026-08-15', '10:00', '10:00')]);

    $head = $days['2026-08-15'][0];
    $tail = $days['2026-08-16'][0];

    $this->assertSame(600, $head['start_min']);
    $this->assertSame(1440, $head['end_min']);
    $this->assertSame(0, $tail['start_min']);
    $this->assertSame(600, $tail['end_min']);
    $this->assertSame(1440, ($head['end_min'] - $head['start_min']) + ($tail['end_min'] - $tail['start_min']));
  }

  public function testSegmentsAreGroupedByDayAndDaysComeInOrder() {
    $rows = [
      $this->row(21, '2026-08-16', '09:00', '10:00'),
      $this->row(22, '2026-08-14', '09:00', '10:00'),
      $this->row(23, '2026-08-15', '23:00', '01:00'),
    ];

    $days = myapi_calendar_day_segments($rows);

    $this->assertSame(['2026-08-14', '2026-08-15', '2026-08-16'], array_keys($days));
    // The continuation of nid 23 lands on the 16th, next to nid 21.
    $this->assertCount(2, $days['2026-08-16']);
  }

  public function testSegmentsOfADayAreSortedByStartThenNid() {
    $rows = [
      // Lands on the 16th at 00:00 as a continuation.
      $this->row(31, '2026-08-15', '23:00', '01:00'),
      $this->row(30, '2026-08-16', '09:00', '10:00'),
      $this->row(29, '2026-08-16', '09:00', '11:00'),
    ];

    $days = myapi_calendar_day_segments($rows);

    $nids = array_map(function ($segment) {
      return $segment['nid'];
    }, $days['2026-08-16']);

    $this->assertSame([31, 29, 30], $nids);
  }

  public function testSegmentCarriesTheExactKeysAndTheOriginalRow() {
    $row = $this->row(41, '2026-08-15', '10:00', '12:00', ['area_id' => 7, 'area_title' => 'Piscina']);

    $days = myapi_calendar_day_segments([$row]);
    $segment = $days['2026-08-15'][0];

    $this->assertSame([
      'nid',
      'date',
      'start_time',
      'end_time',
      'start_min',
      'end_min',
      'is_continuation',
      'ends_next_day',
      'row',
    ], array_keys($segment));

    $this->assertSame($row, $segment['row']);
    $this->assertSame($row, $days['2026-08-15'][0]['row']);
  }

  public function testBothSegmentsOfAnOvernightShareTheSameRow() {
    // The detail panel reads the row, never the segment, so the duration of a
    // 22:00 -> 02:00 is 4h whichever chip is clicked.
    $row = $this->row(42, '2026-08-15', '22:00', '02:00');

    $days = myapi_calendar_day_segments([$row]);

    $this->assertSame($row, $days['2026-08-15'][0]['row']);
    $this->assertSame($row, $days['2026-08-16'][0]['row']);
    $this->assertSame(42, $days['2026-08-16'][0]['nid']);
  }

  public function testSegmentDateIsTheDayItIsPaintedOn() {
    $days = myapi_calendar_day_segments([$this->row(43, '2026-08-15', '22:00', '02:00')]);

    $this->assertSame('2026-08-15', $days['2026-08-15'][0]['date']);
    $this->assertSame('2026-08-16', $days['2026-08-16'][0]['date']);
  }

  /* ---- myapi_calendar_assign_lanes() ---- */

  public function testNoSegmentsProduceNoLanes() {
    $this->assertSame([], myapi_calendar_assign_lanes([]));
  }

  public function testASingleSegmentTakesTheFullWidth() {
    $lanes = myapi_calendar_assign_lanes([$this->seg(1, 600, 720)]);

    $this->assertSame(['1:0/1'], $this->lanes($lanes));
  }

  public function testBackToBackSegmentsBothTakeTheFullWidth() {
    // 10:00-11:00 and 11:00-12:00 do NOT overlap under the half-open criterion
    // of SPEC 35/41/45, so they must not share the column width.
    $lanes = myapi_calendar_assign_lanes([
      $this->seg(1, 600, 660),
      $this->seg(2, 660, 720),
    ]);

    $this->assertSame(['1:0/1', '2:0/1'], $this->lanes($lanes));
  }

  public function testTwoOverlappingSegmentsSplitTheColumn() {
    $lanes = myapi_calendar_assign_lanes([
      $this->seg(1, 600, 720),
      $this->seg(2, 630, 750),
    ]);

    $this->assertSame(['1:0/2', '2:1/2'], $this->lanes($lanes));
  }

  public function testThreeOverlappingSegmentsSplitTheColumnInThree() {
    $lanes = myapi_calendar_assign_lanes([
      $this->seg(1, 600, 720),
      $this->seg(2, 600, 720),
      $this->seg(3, 600, 720),
    ]);

    $this->assertSame(['1:0/3', '2:1/3', '3:2/3'], $this->lanes($lanes));
  }

  public function testALaneIsReusedOnceItsPreviousSegmentEnded() {
    // A 10:00-11:00, B 10:30-11:30, C 11:00-12:00: A-B overlap and B-C overlap,
    // but A-C do not, so the three form one cluster of only two lanes and C
    // reuses lane 0.
    $lanes = myapi_calendar_assign_lanes([
      $this->seg(1, 600, 660),
      $this->seg(2, 630, 690),
      $this->seg(3, 660, 720),
    ]);

    $this->assertSame(['1:0/2', '2:1/2', '3:0/2'], $this->lanes($lanes));
  }

  public function testDisjointClustersHaveIndependentLaneCounts() {
    // Morning: a triple overlap. Afternoon: a lone segment. The afternoon one
    // must keep the full width instead of inheriting lanes_total = 3.
    $lanes = myapi_calendar_assign_lanes([
      $this->seg(1, 540, 660),
      $this->seg(2, 540, 660),
      $this->seg(3, 540, 660),
      $this->seg(4, 900, 960),
    ]);

    $this->assertSame(['1:0/3', '2:1/3', '3:2/3', '4:0/1'], $this->lanes($lanes));
  }

  public function testBackToBackClustersStayIndependent() {
    // Two pairs that touch at 12:00 but never overlap: two clusters of two
    // overlapping segments each, both with lanes_total = 2.
    $lanes = myapi_calendar_assign_lanes([
      $this->seg(1, 600, 720),
      $this->seg(2, 630, 720),
      $this->seg(3, 720, 780),
      $this->seg(4, 750, 780),
    ]);

    $this->assertSame(['1:0/2', '2:1/2', '3:0/2', '4:1/2'], $this->lanes($lanes));
  }

  public function testLaneAssignmentKeepsEveryOtherSegmentKey() {
    $lanes = myapi_calendar_assign_lanes([
      ['nid' => 9, 'start_min' => 0, 'end_min' => 120, 'is_continuation' => TRUE],
    ]);

    $this->assertSame(9, $lanes[0]['nid']);
    $this->assertTrue($lanes[0]['is_continuation']);
    $this->assertSame(0, $lanes[0]['lane']);
    $this->assertSame(1, $lanes[0]['lanes_total']);
  }

  /* ---- myapi_calendar_area_color_index() ---- */

  public function testColorIndexIsAlwaysInsideThePalette() {
    foreach ([1, 5, 11, 12, 13, 34, 120, 999] as $nid) {
      $index = myapi_calendar_area_color_index($nid);
      $this->assertGreaterThanOrEqual(0, $index, 'nid ' . $nid);
      $this->assertLessThanOrEqual(11, $index, 'nid ' . $nid);
    }
  }

  public function testColorIndexIsTheNidModuloTwelve() {
    $this->assertSame(1, myapi_calendar_area_color_index(1));
    $this->assertSame(11, myapi_calendar_area_color_index(11));
    $this->assertSame(0, myapi_calendar_area_color_index(12));
    $this->assertSame(1, myapi_calendar_area_color_index(13));
    $this->assertSame(10, myapi_calendar_area_color_index(34));
  }

  public function testColorIndexIsStableAndAcceptsNumericStrings() {
    // The nid arrives from the database as a string.
    $this->assertSame(myapi_calendar_area_color_index(34), myapi_calendar_area_color_index('34'));
    $this->assertSame(myapi_calendar_area_color_index(34), myapi_calendar_area_color_index(34));
  }

}
