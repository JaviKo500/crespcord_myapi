<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.area_admin.inc';

/**
 * Unit tests for myapi_area_time_is_valid() in includes/myapi.area_admin.inc
 * (SPEC 46).
 *
 * That predicate is the single place where the HH:MM format rule of the area
 * opening/closing times lives. It is pure — no database, no Drupal — so it is
 * testable in isolation. The other function of the file,
 * myapi_area_validate_times(), walks $form_state['values'] and calls Drupal's
 * form_set_error()/t(), so it is out of scope here and is covered by the manual
 * admin-form matrix in SPEC 46, same precedent as SPEC 40/41/42/45.
 *
 * The accepted format is a 24h clock time with a leading zero: 00:00 .. 23:59.
 * Two rejections deserve spelling out, because they are the ones an admin is
 * most likely to type:
 *   - "8:00"  — no leading zero. Every stored time is a fixed-width string that
 *     the reservation code parses and compares, so one single shape is what
 *     keeps that arithmetic predictable.
 *   - "24:00" — outside the clock. End of day is written 23:59; an area that
 *     really stays open past midnight is an overnight area (SPEC 41), which is
 *     expressed as close_time <= open_time, not as an hour beyond 23.
 *
 * The empty string is rejected by the predicate, but callers never reach it
 * with an empty value: myapi_area_validate_times() skips blanks so the field's
 * own required flag produces that error instead of a duplicate one.
 */
class AreaTimeFormatTest extends TestCase {

  /**
   * @dataProvider validTimes
   */
  public function testAcceptsWellFormedTimes($value) {
    $this->assertTrue(myapi_area_time_is_valid($value));
  }

  public function validTimes() {
    return [
      'midnight'      => ['00:00'],
      'morning'       => ['08:00'],
      'half past ten' => ['22:30'],
      'last minute'   => ['23:59'],
      'noon'          => ['12:00'],
      'single digit minute with leading zero' => ['09:05'],
    ];
  }

  /**
   * @dataProvider invalidTimes
   */
  public function testRejectsMalformedTimes($value) {
    $this->assertFalse(myapi_area_time_is_valid($value));
  }

  public function invalidTimes() {
    return [
      'no leading zero'      => ['8:00'],
      'hour 24'              => ['24:00'],
      'hour out of range'    => ['25:00'],
      'minute out of range'  => ['12:60'],
      'with seconds'         => ['08:00:00'],
      'dot separator'        => ['08.00'],
      'no separator'         => ['0800'],
      'words'                => ['ocho'],
      'leading space'        => [' 08:00'],
      'trailing space'       => ['08:00 '],
      'empty string'         => [''],
      'hour only'            => ['08'],
      'trailing separator'   => ['08:'],
      'negative'             => ['-8:00'],
    ];
  }

}
