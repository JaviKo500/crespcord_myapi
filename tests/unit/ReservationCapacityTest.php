<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';

/**
 * Unit tests for the pure concurrent-capacity helpers in
 * includes/myapi.reservation_query.inc (SPEC 45).
 *
 * Covers the three functions that turn "one overlap rejects" into "at most N
 * simultaneous reservations per area":
 *   - myapi_reservation_effective_capacity() — fail-closed normalisation.
 *   - myapi_reservation_peak_concurrency()   — peak of simultaneous existing
 *     reservations inside the candidate's window.
 *   - myapi_reservation_occupancy_ranges()   — partition of the axis into
 *     constant-occupancy ranges with reserved/remaining counters.
 *
 * None of them touches the database or Drupal, so they are testable in
 * isolation. The DB paths (myapi_reservation_fetch_abs_intervals(),
 * validation 6 of POST /api/v1/reservations, GET .../availability) are covered
 * by the curl matrix in docs/area.md and docs/reservation.md, same precedent as
 * SPEC 40/41/42.
 *
 * Reference scenario (the one that separates the correct implementation from
 * the naive one): an area of capacity 2 already has 10:00-11:00 and
 * 13:00-14:00 booked, and someone asks for 10:00-14:00. That candidate OVERLAPS
 * two reservations, so a naive "count the overlapping rows" check would see 2
 * and reject at capacity 2. But those two existing bookings are never active at
 * the same instant, so the real peak is 1 and the request must be accepted.
 *
 * Intervals reach peak_concurrency() ALREADY projected onto the absolute-minute
 * axis of SPEC 41, so the tests project their own fixtures with a local helper
 * that mirrors myapi_reservation_abs_interval(). That function stays in
 * resources/reservation.resource.inc and is deliberately NOT loaded here: the
 * helpers under test must not depend on it.
 *
 * Fixed dates are used throughout so the day arithmetic is deterministic.
 */
class ReservationCapacityTest extends TestCase {

  const D = '2026-08-01';
  const D_NEXT = '2026-08-02';

  /**
   * Projects a date + 'HH:MM' range onto the absolute-minute axis.
   *
   * Mirrors myapi_reservation_abs_interval(): an end at or below the start
   * means the range crosses midnight and lands on the next day.
   */
  private function iv($date, $start_time, $end_time) {
    $base = intdiv(strtotime($date . ' 00:00:00'), 60);
    $start_min = myapi_reservation_time_to_minutes($start_time);
    $end_min = myapi_reservation_time_to_minutes($end_time);
    $end_abs = $base + $end_min + ($end_min <= $start_min ? 1440 : 0);
    return [$base + $start_min, $end_abs];
  }

  /**
   * Builds a busy-range item as returned by myapi_reservation_busy_ranges().
   */
  private function busy($start_date, $start_time, $end_date, $end_time) {
    return [
      'start_date' => $start_date,
      'start_time' => $start_time,
      'end_date' => $end_date,
      'end_time' => $end_time,
    ];
  }

  /* ---- myapi_reservation_effective_capacity() ---- */

  public function testCapacityMissingValueIsOne() {
    // No row in the field table: the area behaves exactly as it does today.
    $this->assertSame(1, myapi_reservation_effective_capacity(NULL));
  }

  public function testCapacityZeroOrNegativeIsOne() {
    // Fail-closed: an admin writing 0 does NOT close the area, and a negative
    // never means "unlimited".
    $this->assertSame(1, myapi_reservation_effective_capacity(0));
    $this->assertSame(1, myapi_reservation_effective_capacity(-5));
  }

  public function testCapacityIsAlwaysAnInt() {
    // Field API hands back strings; the API must expose ints.
    $this->assertSame(1, myapi_reservation_effective_capacity('1'));
    $this->assertSame(3, myapi_reservation_effective_capacity('3'));
    $this->assertSame(3, myapi_reservation_effective_capacity(3));
  }

  /* ---- myapi_reservation_peak_concurrency() ---- */

  public function testPeakWithNoIntervalsIsZero() {
    $cand = $this->iv(self::D, '10:00', '11:00');

    $this->assertSame(0, myapi_reservation_peak_concurrency([], $cand[0], $cand[1]));
  }

  public function testPeakIgnoresIntervalsOutsideTheCandidateWindow() {
    $intervals = [
      $this->iv(self::D, '08:00', '09:00'),
      $this->iv(self::D, '15:00', '16:00'),
    ];
    $cand = $this->iv(self::D, '10:00', '11:00');

    $this->assertSame(0, myapi_reservation_peak_concurrency($intervals, $cand[0], $cand[1]));
  }

  public function testBackToBackIsNotSimultaneous() {
    // Half-open criterion (SPEC 35, kept by SPEC 41): a reservation ending
    // exactly when another starts does not overlap, so it does not count.
    $intervals = [
      $this->iv(self::D, '09:00', '10:00'),
      $this->iv(self::D, '11:00', '12:00'),
    ];
    $cand = $this->iv(self::D, '10:00', '11:00');

    $this->assertSame(0, myapi_reservation_peak_concurrency($intervals, $cand[0], $cand[1]));
  }

  public function testPeakOfTwoDisjointOverlappingReservationsIsOne() {
    // KEY CASE. Both existing reservations overlap the candidate, but they are
    // never active at the same instant: peak is 1, not 2. A naive count of
    // overlapping rows would answer 2 and wrongly reject at capacity 2.
    $intervals = [
      $this->iv(self::D, '10:00', '11:00'),
      $this->iv(self::D, '13:00', '14:00'),
    ];
    $cand = $this->iv(self::D, '10:00', '14:00');

    $this->assertSame(1, myapi_reservation_peak_concurrency($intervals, $cand[0], $cand[1]));
  }

  public function testPeakIsDetectedInTheInteriorOfTheWindow() {
    // At cand_start (09:00) nothing is active; the two existing bookings only
    // coincide between 11:00 and 12:00, well inside the window.
    $intervals = [
      $this->iv(self::D, '10:00', '12:00'),
      $this->iv(self::D, '11:00', '13:00'),
    ];
    $cand = $this->iv(self::D, '09:00', '14:00');

    $this->assertSame(2, myapi_reservation_peak_concurrency($intervals, $cand[0], $cand[1]));
  }

  public function testPeakAtTheStartOfTheWindowIsCounted() {
    // The mirror case: the peak happens exactly at cand_start, where no
    // existing reservation starts, so cand_start must be an evaluated point.
    $intervals = [
      $this->iv(self::D, '09:00', '12:00'),
      $this->iv(self::D, '09:30', '11:00'),
    ];
    $cand = $this->iv(self::D, '10:00', '10:30');

    $this->assertSame(2, myapi_reservation_peak_concurrency($intervals, $cand[0], $cand[1]));
  }

  public function testMidnightCrossingIntervalsGiveTheSamePeakAsTheirFlatEquivalent() {
    // Projected onto the absolute axis, a wrapping session behaves exactly like
    // the same shape shifted 12 hours earlier inside a single day.
    $crossing = [
      $this->iv(self::D, '22:00', '02:00'),
      $this->iv(self::D, '23:00', '01:00'),
    ];
    $crossing_cand = $this->iv(self::D, '23:30', '00:30');

    $flat = [
      $this->iv(self::D, '10:00', '14:00'),
      $this->iv(self::D, '11:00', '13:00'),
    ];
    $flat_cand = $this->iv(self::D, '11:30', '12:30');

    $this->assertSame(
      myapi_reservation_peak_concurrency($flat, $flat_cand[0], $flat_cand[1]),
      myapi_reservation_peak_concurrency($crossing, $crossing_cand[0], $crossing_cand[1])
    );
    $this->assertSame(2, myapi_reservation_peak_concurrency($crossing, $crossing_cand[0], $crossing_cand[1]));
  }

  /**
   * With capacity 1, "peak + 1 > capacity" must be the exact equivalent of the
   * boolean overlap check the write path used before SPEC 45. This is the
   * no-regression guarantee for every area currently in production, including
   * the midnight-crossing cases of SPEC 41.
   *
   * @dataProvider capacityOneCases
   */
  public function testCapacityOneMatchesTheOldOverlapBoolean($intervals, $cand, $expected_rejection, $label) {
    $peak = myapi_reservation_peak_concurrency($intervals, $cand[0], $cand[1]);

    $this->assertSame($expected_rejection, $peak + 1 > 1, $label);
  }

  public function capacityOneCases() {
    return [
      'partial overlap rejects' => [
        [$this->iv(self::D, '10:00', '11:00')],
        $this->iv(self::D, '10:30', '11:30'),
        TRUE,
        'partial overlap',
      ],
      'back to back is allowed' => [
        [$this->iv(self::D, '10:00', '11:00')],
        $this->iv(self::D, '11:00', '12:00'),
        FALSE,
        'back to back',
      ],
      'ending exactly at start is allowed' => [
        [$this->iv(self::D, '10:00', '11:00')],
        $this->iv(self::D, '09:00', '10:00'),
        FALSE,
        'candidate ends where the existing starts',
      ],
      'candidate containing an existing rejects' => [
        [$this->iv(self::D, '10:00', '11:00')],
        $this->iv(self::D, '09:00', '12:00'),
        TRUE,
        'candidate contains the existing one',
      ],
      'two disjoint overlapping still reject at capacity 1' => [
        [$this->iv(self::D, '10:00', '11:00'), $this->iv(self::D, '13:00', '14:00')],
        $this->iv(self::D, '10:00', '14:00'),
        TRUE,
        'peak 1 is already too much when capacity is 1',
      ],
      'SPEC 41 crossing tail vs early morning candidate rejects' => [
        [$this->iv(self::D, '20:00', '02:00')],
        $this->iv(self::D_NEXT, '01:00', '03:00'),
        TRUE,
        'existing 20:00->02:00 vs candidate 01:00->03:00 next day',
      ],
      'SPEC 41 back to back across midnight is allowed' => [
        [$this->iv(self::D, '20:00', '02:00')],
        $this->iv(self::D_NEXT, '02:00', '03:00'),
        FALSE,
        'existing 20:00->02:00 vs candidate 02:00->03:00 next day',
      ],
    ];
  }

  /* ---- myapi_reservation_occupancy_ranges() ---- */

  public function testOccupancyOfAnEmptySessionIsAnEmptyList() {
    $this->assertSame([], myapi_reservation_occupancy_ranges([], 3));
  }

  public function testPartialOverlapsSplitTheAxisAtEveryBoundary() {
    $busy = [
      $this->busy(self::D, '10:00', self::D, '11:00'),
      $this->busy(self::D, '10:30', self::D, '12:00'),
    ];

    $this->assertSame([
      [
        'start_date' => '2026-08-01',
        'start_time' => '10:00',
        'end_date' => '2026-08-01',
        'end_time' => '10:30',
        'reserved' => 1,
        'remaining' => 2,
      ],
      [
        'start_date' => '2026-08-01',
        'start_time' => '10:30',
        'end_date' => '2026-08-01',
        'end_time' => '11:00',
        'reserved' => 2,
        'remaining' => 1,
      ],
      [
        'start_date' => '2026-08-01',
        'start_time' => '11:00',
        'end_date' => '2026-08-01',
        'end_time' => '12:00',
        'reserved' => 1,
        'remaining' => 2,
      ],
    ], myapi_reservation_occupancy_ranges($busy, 3));
  }

  public function testEmptyGapsAreNotEmitted() {
    // 11:00-13:00 has nobody booked; it must not appear as reserved: 0.
    $busy = [
      $this->busy(self::D, '10:00', self::D, '11:00'),
      $this->busy(self::D, '13:00', self::D, '14:00'),
    ];

    $ranges = myapi_reservation_occupancy_ranges($busy, 2);

    $this->assertCount(2, $ranges);
    $this->assertSame('10:00', $ranges[0]['start_time']);
    $this->assertSame('11:00', $ranges[0]['end_time']);
    $this->assertSame('13:00', $ranges[1]['start_time']);
    $this->assertSame('14:00', $ranges[1]['end_time']);
    foreach ($ranges as $range) {
      $this->assertSame(1, $range['reserved']);
    }
  }

  public function testRemainingIsNeverNegative() {
    // An admin lowered the capacity to 2 with three reservations already
    // created: they are respected, and remaining floors at 0.
    $busy = [
      $this->busy(self::D, '10:00', self::D, '11:00'),
      $this->busy(self::D, '10:00', self::D, '11:00'),
      $this->busy(self::D, '10:00', self::D, '11:00'),
    ];

    $ranges = myapi_reservation_occupancy_ranges($busy, 2);

    $this->assertCount(1, $ranges);
    $this->assertSame(3, $ranges[0]['reserved']);
    $this->assertSame(0, $ranges[0]['remaining']);
  }

  public function testRangesAreSortedAscendingAcrossMidnight() {
    // Wrapping session (SPEC 42): the evening booking crosses into D+1 and the
    // early-morning tail is stored under D+1. Input order is reversed on
    // purpose: the output must still come back ascending.
    $busy = [
      $this->busy(self::D_NEXT, '00:00', self::D_NEXT, '02:00'),
      $this->busy(self::D, '23:00', self::D_NEXT, '01:00'),
    ];

    $this->assertSame([
      [
        'start_date' => '2026-08-01',
        'start_time' => '23:00',
        'end_date' => '2026-08-02',
        'end_time' => '00:00',
        'reserved' => 1,
        'remaining' => 1,
      ],
      [
        'start_date' => '2026-08-02',
        'start_time' => '00:00',
        'end_date' => '2026-08-02',
        'end_time' => '01:00',
        'reserved' => 2,
        'remaining' => 0,
      ],
      [
        'start_date' => '2026-08-02',
        'start_time' => '01:00',
        'end_date' => '2026-08-02',
        'end_time' => '02:00',
        'reserved' => 1,
        'remaining' => 1,
      ],
    ], myapi_reservation_occupancy_ranges($busy, 2));
  }

  public function testCapacityOneSessionReportsEveryReservationAsFull() {
    // occupancy is present in capacity-1 areas too, where it is redundant with
    // busy: one range per reservation, always reserved 1 / remaining 0.
    $busy = [
      $this->busy(self::D, '10:00', self::D, '11:00'),
      $this->busy(self::D, '11:00', self::D, '12:00'),
    ];

    $ranges = myapi_reservation_occupancy_ranges($busy, 1);

    $this->assertCount(2, $ranges);
    foreach ($ranges as $range) {
      $this->assertSame(1, $range['reserved']);
      $this->assertSame(0, $range['remaining']);
    }
  }
}
