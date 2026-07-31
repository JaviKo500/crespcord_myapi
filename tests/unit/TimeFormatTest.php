<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.time_format.inc';

/**
 * Unit tests for includes/myapi.time_format.inc (SPEC 52), which replaces
 * tests/unit/AreaTimeFormatTest.php of SPEC 46.
 *
 * The file holds the single HH:MM rule of the module's node forms, now shared
 * by the 'area' opening/closing times (SPEC 46) and the 'reservation'
 * start/end times (SPEC 52). Two functions, two levels:
 *
 *   - myapi_time_format_is_valid() — pure predicate, no database, no Drupal.
 *     This is the matrix SPEC 46 wrote, moved verbatim onto the new name.
 *   - myapi_time_format_validate_fields() — walks $form_state['values'] and
 *     calls form_set_error()/t(), both stubbed in bootstrap.php. It is covered
 *     here and not left to the manual matrix, unlike in SPEC 46, because it is
 *     now shared: one bug in the walk silences the validation of four fields
 *     across two bundles at once.
 *
 * The accepted format is a 24h clock time with a leading zero: 00:00 .. 23:59.
 * Two rejections deserve spelling out, because they are the ones an admin is
 * most likely to type:
 *   - "8:00"  — no leading zero. Every stored time is a fixed-width string that
 *     the reservation code parses, compares and sorts by, so one single shape
 *     is what keeps that arithmetic predictable.
 *   - "24:00" — outside the clock. End of day is written 23:59; a range that
 *     really crosses midnight is stored wrapped (SPEC 41): "22:00" -> "02:00",
 *     never "26:00".
 *
 * The empty string is rejected by the predicate, but the walker never reaches
 * it with an empty value: it skips blanks so the field's own required flag
 * produces that error instead of a duplicate one.
 */
class TimeFormatTest extends TestCase {

  protected function setUp(): void {
    $GLOBALS['myapi_test_form_errors'] = [];
  }

  /* ---- myapi_time_format_is_valid() ---- */

  /**
   * @dataProvider validTimes
   */
  public function testAcceptsWellFormedTimes($value) {
    $this->assertTrue(myapi_time_format_is_valid($value));
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
    $this->assertFalse(myapi_time_format_is_valid($value));
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

  /* ---- myapi_time_format_validate_fields() ---- */

  /**
   * Field map as the 'reservation' entry point passes it (SPEC 52). The 'area'
   * one has the same shape with its own two fields.
   */
  private function reservationFields() {
    return [
      'field_start_time' => 'Hora de inicio',
      'field_end_time'   => 'Hora de fin',
    ];
  }

  /**
   * Builds the $form_state a node form submit produces, one delta per field.
   */
  private function formState(array $values) {
    $form_state = ['values' => []];
    foreach ($values as $field_name => $value) {
      $form_state['values'][$field_name] = ['und' => [0 => ['value' => $value]]];
    }
    return $form_state;
  }

  public function testWellFormedTimesProduceNoError() {
    $form_state = $this->formState([
      'field_start_time' => '10:00',
      'field_end_time'   => '12:00',
    ]);

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * SPEC 41: a reservation crossing midnight is stored wrapped, so end <= start
   * is legal and this validation must stay format-only.
   */
  public function testRangeCrossingMidnightIsAccepted() {
    $form_state = $this->formState([
      'field_start_time' => '22:00',
      'field_end_time'   => '02:00',
    ]);

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * The key is the full element path, which is what makes Drupal flag the
   * offending input instead of only printing the message at the top.
   */
  public function testErrorKeyIsTheFullElementPath() {
    $form_state = $this->formState(['field_start_time' => '8:00']);

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame(
      ['field_start_time][und][0][value'],
      array_keys($GLOBALS['myapi_test_form_errors'])
    );
  }

  public function testMessageNamesTheOffendingField() {
    $form_state = $this->formState(['field_end_time' => 'ocho']);

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame(
      'Hora de fin: el formato debe ser HH:MM en 24 horas (por ejemplo 08:00 o 22:30).',
      $GLOBALS['myapi_test_form_errors']['field_end_time][und][0][value']
    );
  }

  public function testEachMalformedFieldReportsItsOwnError() {
    $form_state = $this->formState([
      'field_start_time' => '8:00',
      'field_end_time'   => '99:99',
    ]);

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame(
      ['field_start_time][und][0][value', 'field_end_time][und][0][value'],
      array_keys($GLOBALS['myapi_test_form_errors'])
    );
  }

  /**
   * Blanks belong to the instance's required flag, in
   * entity_form_field_validate(). Reporting them here too would show two
   * messages for the same field on the same submit.
   *
   * @dataProvider blankValues
   */
  public function testBlankValuesAreSkipped($value) {
    $form_state = $this->formState(['field_start_time' => $value]);

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  public function blankValues() {
    return [
      'empty string' => [''],
      'spaces only'  => ['   '],
    ];
  }

  /**
   * The trim() decides emptiness only: what is matched is the raw value, so a
   * padded time is rejected instead of being stored with the space.
   */
  public function testPaddedTimeIsRejected() {
    $form_state = $this->formState(['field_start_time' => ' 08:00']);

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertCount(1, $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * A field the form did not submit is not an error: the walk skips it and
   * lets required (or its absence from the bundle) speak.
   */
  public function testMissingFieldIsSkipped() {
    $form_state = ['values' => []];

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * The widget puts housekeeping keys next to the deltas ('add_more', and a
   * scalar under the field name in some widgets). None of them is a value to
   * validate.
   */
  public function testWidgetHousekeepingKeysAreSkipped() {
    $form_state = ['values' => [
      'field_start_time' => [
        'und' => [
          0          => ['value' => '10:00'],
          'add_more' => 'Añadir otro elemento',
        ],
        'add_more' => 1,
      ],
    ]];

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * Neither the language nor the delta is assumed: reading
   * [LANGUAGE_NONE][0]['value'] straight would skip the validation silently if
   * either ever changed.
   */
  public function testEveryLanguageAndDeltaIsWalked() {
    $form_state = ['values' => [
      'field_start_time' => [
        'es' => [0 => ['value' => '8:00']],
        'en' => [0 => ['value' => '10:00'], 1 => ['value' => '25:00']],
      ],
    ]];

    myapi_time_format_validate_fields($form_state, $this->reservationFields());

    $this->assertSame(
      ['field_start_time][es][0][value', 'field_start_time][en][1][value'],
      array_keys($GLOBALS['myapi_test_form_errors'])
    );
  }

}
