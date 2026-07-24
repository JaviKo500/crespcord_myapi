<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';

/**
 * Unit tests for the pure derivation helper in
 * includes/myapi.reservation_query.inc.
 *
 * Covers myapi_reservation_busy_ranges() (and, transitively,
 * myapi_reservation_time_to_minutes()) — the SESSION-aware availability
 * derivation. The query helper myapi_reservation_busy_rows() touches the
 * database and is out of scope for unit tests (see the curl matrix in
 * docs/area.md for that path).
 *
 * A "session" for day D is the area's operating window [D open, D+1 close]. For
 * a normal area (close > open) that is just the calendar day D. For an area
 * that closes after midnight (close <= open) the session is assembled from
 * field_date = D rows with start >= open plus field_date = D+1 rows with
 * start < close (the early-morning tail stored under its own clock day).
 *
 * Fixed dates are used throughout so the day arithmetic is deterministic.
 *
 * Reference areas:
 *   - Normal   08:00-22:00 -> open 480,  close 1320.
 *   - Wrapping 12:00-02:00 -> open 720,  close 120.
 */
class ReservationBusyRangesTest extends TestCase {

  const D = '2026-07-24';
  const D_NEXT = '2026-07-25';
  const D_NEXT2 = '2026-07-26';

  // Normal area 08:00-22:00.
  const OPEN_N = 480;
  const CLOSE_N = 1320;

  // Wrapping area 12:00-02:00.
  const OPEN_W = 720;
  const CLOSE_W = 120;

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

  /* ---- Empty ---- */

  public function testNoRowsReturnsEmptyArray() {
    $this->assertSame([], myapi_reservation_busy_ranges([], self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W));
  }

  /* ---- Normal area (non-wrapping) ---- */

  public function testNormalSameDayKeepsBothDatesEqual() {
    $rows = [$this->row(self::D, '10:00', '12:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_N, self::CLOSE_N);

    $this->assertSame([
      [
        'start_date' => '2026-07-24',
        'start_time' => '10:00',
        'end_date' => '2026-07-24',
        'end_time' => '12:00',
      ],
    ], $busy);
  }

  public function testNormalNextDayRowIsExcluded() {
    // A reservation stored on D+1 is not part of the calendar-day session D.
    $rows = [$this->row(self::D_NEXT, '10:00', '12:00')];

    $this->assertSame([], myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_N, self::CLOSE_N));
  }

  public function testNullHoursDegradeToCalendarDaySession() {
    // Missing open/close -> treated as a plain calendar-day session.
    $rows = [
      $this->row(self::D, '10:00', '12:00'),
      $this->row(self::D_NEXT, '10:00', '12:00'),
    ];

    $busy = myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, NULL, NULL);

    $this->assertCount(1, $busy);
    $this->assertSame('2026-07-24', $busy[0]['start_date']);
    $this->assertSame('2026-07-24', $busy[0]['end_date']);
  }

  /* ---- Wrapping area: evening / late-night slice (field_date = D) ---- */

  public function testWrappingEveningNonCrossingStaysSameDay() {
    $rows = [$this->row(self::D, '20:00', '22:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W);

    $this->assertSame([
      [
        'start_date' => '2026-07-24',
        'start_time' => '20:00',
        'end_date' => '2026-07-24',
        'end_time' => '22:00',
      ],
    ], $busy);
  }

  public function testWrappingEveningCrossingEndsNextDay() {
    // 23:00 -> 01:00 on D belongs to session D and ends on D+1.
    $rows = [$this->row(self::D, '23:00', '01:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W);

    $this->assertSame([
      [
        'start_date' => '2026-07-24',
        'start_time' => '23:00',
        'end_date' => '2026-07-25',
        'end_time' => '01:00',
      ],
    ], $busy);
  }

  public function testWrappingSameDayTailBelongsToPreviousSession() {
    // A field_date = D row starting before open (00:00) is the tail of the
    // PREVIOUS session, not session D -> excluded.
    $rows = [$this->row(self::D, '00:00', '02:00')];

    $this->assertSame([], myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W));
  }

  /* ---- Wrapping area: early-morning tail (field_date = D+1) ---- */

  public function testWrappingNextDayTailIsIncludedInSession() {
    // The user's case: a 00:00 -> 02:00 booking stored under its clock day
    // D+1 is the tail of session D, so it shows when querying session D.
    $rows = [$this->row(self::D_NEXT, '00:00', '02:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W);

    $this->assertSame([
      [
        'start_date' => '2026-07-25',
        'start_time' => '00:00',
        'end_date' => '2026-07-25',
        'end_time' => '02:00',
      ],
    ], $busy);
  }

  public function testWrappingNextDayEveningIsExcludedFromSession() {
    // A field_date = D+1 row starting at/after close (20:00 >= 02:00) is the
    // evening of the NEXT session -> excluded from session D.
    $rows = [$this->row(self::D_NEXT, '20:00', '22:00')];

    $this->assertSame([], myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W));
  }

  public function testWrappingTailExcludedFromItsOwnClockDaySession() {
    // The same 00:00 -> 02:00 tail (field_date = 2026-07-25) must NOT show when
    // querying session 2026-07-25 (that session opens at 12:00). This is the
    // bug the follow-up fixes: it no longer leaks into the next session.
    $rows = [$this->row(self::D_NEXT, '00:00', '02:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::D_NEXT, self::D_NEXT2, self::OPEN_W, self::CLOSE_W);

    $this->assertSame([], $busy);
  }

  public function testWrappingPreviousSessionRowIsNeverAttributedToThisOne() {
    // A crossing reservation of session D-1 (field_date one day before D) is
    // neither D nor D+1 -> excluded, even if it were passed in.
    $rows = [$this->row('2026-07-23', '23:00', '01:00')];

    $this->assertSame([], myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W));
  }

  /* ---- Shape / ordering ---- */

  public function testFullSessionIsAssembledAndSortedByStartDateThenTime() {
    // A complete wrapping session D: evening non-crossing, evening crossing,
    // and the early-morning tail on D+1, plus noise that must be dropped
    // (previous session's tail on D, next session's evening on D+1).
    $rows = [
      $this->row(self::D_NEXT, '00:00', '02:00'),  // tail of session D (kept)
      $this->row(self::D, '23:00', '01:00'),       // crossing evening (kept)
      $this->row(self::D, '20:00', '22:00'),       // evening (kept)
      $this->row(self::D, '01:00', '02:00'),       // prev session tail (drop)
      $this->row(self::D_NEXT, '20:00', '21:00'),  // next session evening (drop)
    ];

    $busy = myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W);

    $order = array_map(function ($item) {
      return $item['start_date'] . ' ' . $item['start_time'] . '->' . $item['end_date'] . ' ' . $item['end_time'];
    }, $busy);

    $this->assertSame([
      '2026-07-24 20:00->2026-07-24 22:00',
      '2026-07-24 23:00->2026-07-25 01:00',
      '2026-07-25 00:00->2026-07-25 02:00',
    ], $order);
  }

  public function testItemsHaveExactlyTheFourKeys() {
    $rows = [$this->row(self::D, '20:00', '22:00')];

    $busy = myapi_reservation_busy_ranges($rows, self::D, self::D_NEXT, self::OPEN_W, self::CLOSE_W);

    $this->assertSame(
      ['start_date', 'start_time', 'end_date', 'end_time'],
      array_keys($busy[0])
    );
  }
}
