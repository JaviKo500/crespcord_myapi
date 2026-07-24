<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';

/**
 * Unit tests for the pure derivation helper in
 * includes/myapi.reservation_query.inc.
 *
 * Only covers myapi_reservation_busy_ranges() (and, transitively,
 * myapi_reservation_time_to_minutes()) — the query helper
 * myapi_reservation_busy_rows() touches the database and is out of scope for
 * unit tests (see the documented curl matrix in docs/area.md for that path).
 *
 * Fixed dates are used throughout so the day-crossing arithmetic is
 * deterministic and independent of the current date.
 */
class ReservationBusyRangesTest extends TestCase {

  const DATE = '2026-07-25';
  const PREV = '2026-07-24';

  /**
   * Builds a raw reservation row as returned by myapi_reservation_busy_rows().
   */
  private function row($date, $start_time, $end_time) {
    $row = new stdClass();
    $row->date = $date;
    $row->start_time = $start_time;
    $row->end_time = $end_time;
    return $row;
  }

  public function testNoRowsReturnsEmptyArray() {
    $this->assertSame([], myapi_reservation_busy_ranges([], self::DATE, self::PREV));
  }

  public function testSameDayReservationKeepsBothDatesEqual() {
    $rows = [$this->row(self::DATE, '10:00', '12:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::DATE, self::PREV);

    $this->assertSame([
      [
        'start_date' => '2026-07-25',
        'start_time' => '10:00',
        'end_date' => '2026-07-25',
        'end_time' => '12:00',
      ],
    ], $busy);
  }

  public function testCrossingReservationSeenFromStartDaySetsEndDateNextDay() {
    // 23:00 -> 01:00 on the requested day crosses midnight.
    $rows = [$this->row(self::DATE, '23:00', '01:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::DATE, self::PREV);

    $this->assertSame([
      [
        'start_date' => '2026-07-25',
        'start_time' => '23:00',
        'end_date' => '2026-07-26',
        'end_time' => '01:00',
      ],
    ], $busy);
  }

  public function testCrossingReservationSeenFromNextDayKeepsPrevAsStartDate() {
    // Same reservation, now queried from its END day: its field_date is PREV,
    // it crosses, so it is kept with start_date = PREV and end_date = DATE.
    $rows = [$this->row(self::PREV, '23:00', '01:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::DATE, self::PREV);

    $this->assertSame([
      [
        'start_date' => '2026-07-24',
        'start_time' => '23:00',
        'end_date' => '2026-07-25',
        'end_time' => '01:00',
      ],
    ], $busy);
  }

  public function testPrevDayNonCrossingReservationIsDropped() {
    // A previous-day booking that ends before midnight does not touch DATE.
    $rows = [$this->row(self::PREV, '10:00', '12:00')];

    $this->assertSame([], myapi_reservation_busy_ranges($rows, self::DATE, self::PREV));
  }

  public function testEqualStartAndEndTimeCountsAsCrossing() {
    // end <= start is the crossing criterion, so 15:00 -> 15:00 crosses.
    $rows = [$this->row(self::DATE, '15:00', '15:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::DATE, self::PREV);

    $this->assertSame('2026-07-25', $busy[0]['start_date']);
    $this->assertSame('2026-07-26', $busy[0]['end_date']);
  }

  public function testResultIsSortedByStartDateThenStartTime() {
    // Deliberately unsorted input mixing a crossing prev-day row (earliest by
    // start_date) with several same-day rows.
    $rows = [
      $this->row(self::DATE, '18:00', '19:00'),
      $this->row(self::DATE, '08:00', '09:00'),
      // Crossing prev-day row: start_date = PREV, must come first.
      $this->row(self::PREV, '22:00', '02:00'),
      $this->row(self::DATE, '08:00', '07:00'),
    ];

    $busy = myapi_reservation_busy_ranges($rows, self::DATE, self::PREV);

    $order = array_map(function ($item) {
      return $item['start_date'] . ' ' . $item['start_time'];
    }, $busy);

    $this->assertSame([
      '2026-07-24 22:00',
      '2026-07-25 08:00',
      '2026-07-25 08:00',
      '2026-07-25 18:00',
    ], $order);
  }

  public function testItemsHaveExactlyTheFourKeys() {
    $rows = [$this->row(self::DATE, '10:00', '12:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::DATE, self::PREV);

    $this->assertSame(
      ['start_date', 'start_time', 'end_date', 'end_time'],
      array_keys($busy[0])
    );
  }
}
