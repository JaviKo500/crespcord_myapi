<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/reservation.resource.inc';

/**
 * Unit tests for the pure overnight-reservation helpers added by SPEC 41 in
 * resources/reservation.resource.inc:
 *
 *   - myapi_reservation_area_wraps()
 *   - myapi_reservation_effective_date()
 *   - myapi_reservation_abs_interval()
 *   - myapi_reservation_within_hours()
 *   - myapi_reservation_crosses_disallowed()
 *
 * These are the only DB-free helpers of the file; the query paths
 * (myapi_reservation_has_overlap() etc.) touch the database and are covered by
 * the documented curl matrix in docs/reservation.md, not here. The overlap
 * criterion itself is exercised through myapi_reservation_abs_interval() plus
 * the same half-open comparison has_overlap() applies.
 *
 * Fixed dates are used throughout so the day-crossing arithmetic is
 * deterministic regardless of the current date.
 *
 * Reference areas:
 *   - Wrapping area open 12:00-02:00  -> open 720,  close 120.
 *   - Normal area   open 08:00-22:00  -> open 480,  close 1320.
 */
class ReservationMidnightTest extends TestCase {

  const D = '2026-07-25';
  const D_NEXT = '2026-07-26';

  // Wrapping area 12:00-02:00.
  const OPEN_W = 720;
  const CLOSE_W = 120;

  // Normal area 08:00-22:00.
  const OPEN_N = 480;
  const CLOSE_N = 1320;

  /**
   * Mirrors the half-open comparison myapi_reservation_has_overlap() applies,
   * on the absolute axis produced by myapi_reservation_abs_interval().
   */
  private function overlaps($date_a, $start_a, $end_a, $date_b, $start_b, $end_b) {
    list($as, $ae) = myapi_reservation_abs_interval($date_a, $start_a, $end_a);
    list($bs, $be) = myapi_reservation_abs_interval($date_b, $start_b, $end_b);
    return $as < $be && $ae > $bs;
  }

  /* ---- myapi_reservation_area_wraps() ---- */

  public function testAreaWrapsWhenCloseAtOrBeforeOpen() {
    $this->assertTrue(myapi_reservation_area_wraps(self::OPEN_W, self::CLOSE_W));
    // close == open is still a wrap (a 24h span, close not strictly after open).
    $this->assertTrue(myapi_reservation_area_wraps(720, 720));
  }

  public function testNormalAreaDoesNotWrap() {
    $this->assertFalse(myapi_reservation_area_wraps(self::OPEN_N, self::CLOSE_N));
  }

  /* ---- myapi_reservation_effective_date() ---- */

  public function testEarlyMorningStartInWrappingAreaNormalizesToNextDay() {
    // 01:00 (60) falls in the tail [00:00, 02:00) -> D+1.
    $this->assertSame(
      self::D_NEXT,
      myapi_reservation_effective_date(self::D, 60, self::OPEN_W, self::CLOSE_W)
    );
  }

  public function testEveningStartInWrappingAreaKeepsSameDay() {
    // 20:00 (1200) is not in the tail -> D.
    $this->assertSame(
      self::D,
      myapi_reservation_effective_date(self::D, 1200, self::OPEN_W, self::CLOSE_W)
    );
  }

  public function testDeadGapStartInWrappingAreaIsNotNormalized() {
    // 05:00 (300) sits in the dead gap between close (02:00) and open (12:00):
    // 300 < 120 is false, so it stays on D and later fails as out of hours.
    $this->assertSame(
      self::D,
      myapi_reservation_effective_date(self::D, 300, self::OPEN_W, self::CLOSE_W)
    );
  }

  public function testNormalAreaNeverNormalizes() {
    // Neither a daytime start nor an early-morning one moves the day.
    $this->assertSame(self::D, myapi_reservation_effective_date(self::D, 600, self::OPEN_N, self::CLOSE_N));
    $this->assertSame(self::D, myapi_reservation_effective_date(self::D, 60, self::OPEN_N, self::CLOSE_N));
  }

  /* ---- myapi_reservation_abs_interval() ---- */

  public function testAbsIntervalNonCrossingKeepsSameDay() {
    list($start_abs, $end_abs) = myapi_reservation_abs_interval(self::D, 600, 720);
    $this->assertSame(120, $end_abs - $start_abs);
  }

  public function testAbsIntervalCrossingCarriesEndForwardOneDay() {
    // A stored 20:00 -> 02:00 reservation (end 120 <= start 1200) spans 6h.
    list($start_abs, $end_abs) = myapi_reservation_abs_interval(self::D, 1200, 120);
    $this->assertSame(360, $end_abs - $start_abs);
  }

  public function testAbsIntervalDaysAreOffsetBy1440Minutes() {
    list($start_d) = myapi_reservation_abs_interval(self::D, 0, 60);
    list($start_next) = myapi_reservation_abs_interval(self::D_NEXT, 0, 60);
    $this->assertSame(1440, $start_next - $start_d);
  }

  /* ---- myapi_reservation_within_hours(): wrapping area ---- */

  public function testWrappingAreaEveningWithinExtendedWindow() {
    // 20:00 + 6h -> 02:00 (end raw 1560). Inside [720, 1560].
    $this->assertTrue(myapi_reservation_within_hours(1200, 1560, self::OPEN_W, self::CLOSE_W));
  }

  public function testWrappingAreaEveningOverrunsProjectedClose() {
    // 20:00 + 8h -> 04:00 (end raw 1680). Past the projected close 1560.
    $this->assertFalse(myapi_reservation_within_hours(1200, 1680, self::OPEN_W, self::CLOSE_W));
  }

  public function testWrappingAreaEarlyMorningWithinHours() {
    // 01:00 + 1h -> 02:00: start 60, end 120. Projected inside hours.
    $this->assertTrue(myapi_reservation_within_hours(60, 120, self::OPEN_W, self::CLOSE_W));
  }

  public function testWrappingAreaEarlyMorningDurationOverrunsClose() {
    // 01:00 + 2h -> 03:00: start 60, end 180. Must be rejected (the corrected
    // end projection: end_eff = start_eff + duration = 1500 + 120 = 1620 > 1560).
    $this->assertFalse(myapi_reservation_within_hours(60, 180, self::OPEN_W, self::CLOSE_W));
  }

  public function testWrappingAreaDeadGapStartIsOutOfHours() {
    // 05:00 + 1h: start 300 is below open 720 and not in the tail -> out.
    $this->assertFalse(myapi_reservation_within_hours(300, 360, self::OPEN_W, self::CLOSE_W));
  }

  /* ---- myapi_reservation_within_hours(): normal area (non-regression) ---- */

  public function testNormalAreaWithinHours() {
    // 10:00 + 2h -> 12:00.
    $this->assertTrue(myapi_reservation_within_hours(600, 720, self::OPEN_N, self::CLOSE_N));
  }

  public function testNormalAreaBeforeOpenIsOutOfHours() {
    // 07:00 + 1h -> 08:00, but starts before open 08:00.
    $this->assertFalse(myapi_reservation_within_hours(420, 480, self::OPEN_N, self::CLOSE_N));
  }

  public function testNormalAreaPastCloseIsOutOfHours() {
    // 21:30 + 1h -> 22:30, past close 22:00 (no midnight crossing).
    $this->assertFalse(myapi_reservation_within_hours(1290, 1350, self::OPEN_N, self::CLOSE_N));
  }

  /* ---- myapi_reservation_crosses_disallowed() ---- */

  public function testNormalAreaCrossingMidnightIsDisallowed() {
    // 21:00 + 4h -> 01:00 (end raw 1500 >= 1440) in a same-day area.
    $this->assertTrue(myapi_reservation_crosses_disallowed(1260, 1500, self::OPEN_N, self::CLOSE_N));
  }

  public function testNormalAreaEndingExactlyAtMidnightIsDisallowed() {
    // 20:00 + 4h -> 24:00 (end raw 1440) reaches midnight.
    $this->assertTrue(myapi_reservation_crosses_disallowed(1200, 1440, self::OPEN_N, self::CLOSE_N));
  }

  public function testNormalAreaNotCrossingIsAllowed() {
    // 21:30 + 1h -> 22:30 (end raw 1350) stays within the same day.
    $this->assertFalse(myapi_reservation_crosses_disallowed(1290, 1350, self::OPEN_N, self::CLOSE_N));
  }

  public function testWrappingAreaCrossingIsNeverDisallowed() {
    // A wrapping area is allowed to cross midnight, whatever the range.
    $this->assertFalse(myapi_reservation_crosses_disallowed(1200, 1560, self::OPEN_W, self::CLOSE_W));
  }

  /* ---- Overlap on the absolute axis (validation 6) ---- */

  public function testEarlyMorningOverlapsPreviousEveningTail() {
    // Existing 20:00 -> 02:00 on D; new 01:00 -> 02:00 normalized to D+1.
    $this->assertTrue(
      $this->overlaps(self::D_NEXT, 60, 120, self::D, 1200, 120)
    );
  }

  public function testEveningOverlapsNextMorningReservation() {
    // Symmetric: existing 01:00 -> 02:00 on D+1; new 20:00 -> 02:00 on D. The
    // new candidate's end is the unbounded 1560 produced by add_minutes.
    $this->assertTrue(
      $this->overlaps(self::D, 1200, 1560, self::D_NEXT, 60, 120)
    );
  }

  public function testBackToBackDoesNotOverlap() {
    // Existing 20:00 -> 02:00 on D (ends at D+1 02:00); new 02:00 -> 03:00
    // stored on D+1 starts exactly when the other ends -> not an overlap.
    $this->assertFalse(
      $this->overlaps(self::D_NEXT, 120, 180, self::D, 1200, 120)
    );
  }

  public function testSameDayDisjointReservationsDoNotOverlap() {
    // Non-regression: two plain same-day ranges that do not touch.
    $this->assertFalse(
      $this->overlaps(self::D, 600, 720, self::D, 780, 900)
    );
  }
}
