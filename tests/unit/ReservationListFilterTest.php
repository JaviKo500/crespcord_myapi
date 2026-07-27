<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/reservation.resource.inc';

/**
 * Unit tests for the list-filter parsing helpers of SPEC 43 in
 * resources/reservation.resource.inc:
 *
 *   - myapi_reservation_valid_time()
 *   - myapi_reservation_parse_date_range()
 *
 * Both are DB-free: parse_date_range() reads $_GET and returns the four
 * resolved bounds, so it is exercised here by writing $_GET directly. The
 * SQL side (myapi_reservation_apply_range() and the ordering) touches the
 * database and is covered by the curl matrix in docs/reservation.md.
 */
class ReservationListFilterTest extends TestCase {

  protected function setUp(): void {
    $_GET = [];
  }

  protected function tearDown(): void {
    $_GET = [];
  }

  /* ---- myapi_reservation_valid_time() ---- */

  public function testValidTimeAcceptsWellFormedClockTimes() {
    $this->assertSame('00:00', myapi_reservation_valid_time('00:00'));
    $this->assertSame('09:00', myapi_reservation_valid_time('09:00'));
    $this->assertSame('23:59', myapi_reservation_valid_time('23:59'));
  }

  public function testValidTimeRejectsOutOfRangeValues() {
    // 24:00 is not a clock time; minutes stop at 59.
    $this->assertNull(myapi_reservation_valid_time('24:00'));
    $this->assertNull(myapi_reservation_valid_time('23:60'));
  }

  public function testValidTimeRejectsMalformedValues() {
    // Missing zero padding, seconds, free text and non-strings are ignored.
    $this->assertNull(myapi_reservation_valid_time('9:00'));
    $this->assertNull(myapi_reservation_valid_time('09:00:00'));
    $this->assertNull(myapi_reservation_valid_time('ahora'));
    $this->assertNull(myapi_reservation_valid_time(''));
    $this->assertNull(myapi_reservation_valid_time(NULL));
    $this->assertNull(myapi_reservation_valid_time(900));
  }

  /* ---- myapi_reservation_parse_date_range() ---- */

  public function testParseReturnsAllNullsWhenNothingIsSent() {
    $this->assertSame(
      ['from' => NULL, 'to' => NULL, 'time_from' => NULL, 'time_to' => NULL],
      myapi_reservation_parse_date_range()
    );
  }

  public function testParseKeepsTimeBoundAttachedToItsDateBound() {
    $_GET = ['date_from' => '2026-07-27', 'time_from' => '09:00'];
    $range = myapi_reservation_parse_date_range();

    $this->assertSame('2026-07-27', $range['from']);
    $this->assertSame('09:00', $range['time_from']);
    $this->assertNull($range['to']);
    $this->assertNull($range['time_to']);
  }

  public function testParseDropsTimeBoundWithoutItsDateBound() {
    // time_from refines date_from; with no lower bound there is no boundary
    // day to apply it to, so it is ignored instead of filtering every day.
    $_GET = ['time_from' => '09:00', 'time_to' => '18:00'];
    $range = myapi_reservation_parse_date_range();

    $this->assertNull($range['time_from']);
    $this->assertNull($range['time_to']);
  }

  public function testParseDropsTimeBoundWhenItsDateBoundIsInvalid() {
    $_GET = ['date_from' => '2026-13-40', 'time_from' => '09:00'];
    $range = myapi_reservation_parse_date_range();

    $this->assertNull($range['from']);
    $this->assertNull($range['time_from']);
  }

  public function testParseDropsMalformedTimeAndKeepsItsDate() {
    $_GET = ['date_from' => '2026-07-27', 'time_from' => '9am'];
    $range = myapi_reservation_parse_date_range();

    $this->assertSame('2026-07-27', $range['from']);
    $this->assertNull($range['time_from']);
  }

  public function testParseDropsEverythingOnInvertedDateRange() {
    $_GET = [
      'date_from' => '2026-07-31',
      'date_to'   => '2026-07-01',
      'time_from' => '09:00',
      'time_to'   => '18:00',
    ];

    $this->assertSame(
      ['from' => NULL, 'to' => NULL, 'time_from' => NULL, 'time_to' => NULL],
      myapi_reservation_parse_date_range()
    );
  }

  public function testParseDropsOnlyTimesOnInvertedSingleDayRange() {
    // Same day, times inverted: an empty result is never what the caller
    // meant, so the day survives and both times are dropped.
    $_GET = [
      'date_from' => '2026-07-27',
      'date_to'   => '2026-07-27',
      'time_from' => '18:00',
      'time_to'   => '09:00',
    ];
    $range = myapi_reservation_parse_date_range();

    $this->assertSame('2026-07-27', $range['from']);
    $this->assertSame('2026-07-27', $range['to']);
    $this->assertNull($range['time_from']);
    $this->assertNull($range['time_to']);
  }

  public function testParseKeepsInvertedTimesAcrossDifferentDays() {
    // 27th from 18:00 to the 28th at 09:00 is a perfectly valid window: the
    // inverted-time rule only applies when both bounds are the same day.
    $_GET = [
      'date_from' => '2026-07-27',
      'date_to'   => '2026-07-28',
      'time_from' => '18:00',
      'time_to'   => '09:00',
    ];
    $range = myapi_reservation_parse_date_range();

    $this->assertSame('18:00', $range['time_from']);
    $this->assertSame('09:00', $range['time_to']);
  }

  public function testParseKeepsEqualTimesOnSingleDayRange() {
    // from == to and time_from == time_to is not inverted: it selects the
    // reservations starting exactly at that hour (both ends inclusive).
    $_GET = [
      'date_from' => '2026-07-27',
      'date_to'   => '2026-07-27',
      'time_from' => '09:00',
      'time_to'   => '09:00',
    ];
    $range = myapi_reservation_parse_date_range();

    $this->assertSame('09:00', $range['time_from']);
    $this->assertSame('09:00', $range['time_to']);
  }

}
