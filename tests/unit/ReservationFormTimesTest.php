<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../myapi.module';
require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.time_format.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_rules.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_form.inc';
require_once __DIR__ . '/../../includes/myapi.area_admin.inc';

/**
 * Unit tests for the time selects of the back-office forms (SPEC 136).
 *
 *   - myapi_reservation_form_time_grid(), the pure half: the times an area can
 *     be booked at, from its opening to its closing time in steps of its block.
 *   - myapi_reservation_form_area_schedule(), the route that hands that grid
 *     to the 'reservation' form, through myapi_test_capture().
 *   - The two form alters, on hand-built form arrays: the 'reservation' one
 *     tags the start and end inputs, the 'area' one the opening and closing
 *     inputs.
 *
 * Not tested here, because no unit test can: js/myapi.reservation_form.js and
 * js/myapi.time_select.js. That is the manual matrix of the spec.
 */
class ReservationFormTimesTest extends TestCase {

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
  }

  /* -------------------------------------------------------------------------
   * The grid — myapi_reservation_form_time_grid().
   * ---------------------------------------------------------------------- */

  private function times($open, $close, $slot) {
    return array_column(myapi_reservation_form_time_grid($open, $close, $slot), 'time');
  }

  public function testTheGridGoesFromOpeningToClosingInBlocks() {
    $this->assertSame(
      [
        ['time' => '08:00', 'offset' => 0],
        ['time' => '09:00', 'offset' => 60],
        ['time' => '10:00', 'offset' => 120],
        ['time' => '11:00', 'offset' => 180],
      ],
      myapi_reservation_form_time_grid('08:00', '11:00', 60)
    );
  }

  public function testTheBlockSizeSetsTheStep() {
    $this->assertSame(['16:00', '16:30', '17:00', '17:30', '18:00'], $this->times('16:00', '18:00', 30));
    $this->assertSame(['16:00', '16:45', '17:30'], $this->times('16:00', '17:30', 45));
  }

  /**
   * The closing time is always the last entry, also when the window is not a
   * multiple of the block: it is a valid end, and the only one for the last
   * start.
   */
  public function testAWindowThatIsNotAMultipleOfTheBlockStillEndsAtClosing() {
    $grid = myapi_reservation_form_time_grid('08:00', '10:30', 60);

    $this->assertSame(['08:00', '09:00', '10:00', '10:30'], array_column($grid, 'time'));
    $this->assertSame([0, 60, 120, 150], array_column($grid, 'offset'));
  }

  /**
   * Same reading as myapi_reservation_area_wraps(): closing at or before
   * opening means the area stays open past midnight. The offsets keep growing,
   * which is what lets the form measure a duration across it.
   */
  public function testAnAreaOpenPastMidnightWrapsTheClock() {
    $grid = myapi_reservation_form_time_grid('22:00', '02:00', 60);

    $this->assertSame(['22:00', '23:00', '00:00', '01:00', '02:00'], array_column($grid, 'time'));
    $this->assertSame([0, 60, 120, 180, 240], array_column($grid, 'offset'));
  }

  public function testClosingAtTheOpeningTimeIsAFullDay() {
    $grid = myapi_reservation_form_time_grid('08:00', '08:00', 120);

    $this->assertCount(13, $grid);
    $this->assertSame('08:00', $grid[0]['time']);
    $this->assertSame(['time' => '08:00', 'offset' => 1440], $grid[12]);
  }

  /**
   * A block longer than the window still leaves one bookable range: opening
   * to closing.
   */
  public function testABlockLongerThanTheWindowLeavesOpeningAndClosing() {
    $this->assertSame(['08:00', '09:00'], $this->times('08:00', '09:00', 120));
  }

  /**
   * The block is cast to an integer, as the endpoint casts slot_minutes: a
   * fraction below one minute is no block at all.
   */
  public function testADecimalBlockIsTruncated() {
    $this->assertSame([], $this->times('08:00', '10:00', '0.5'));
    $this->assertSame(['08:00', '09:00', '10:00'], $this->times('08:00', '10:00', '60.5'));
  }

  public function testTheBlockArrivesAsTheStringTheFieldStores() {
    $this->assertSame(['08:00', '09:00'], $this->times('08:00', '09:00', '60'));
  }

  /**
   * @dataProvider unusableSchedules
   */
  public function testAScheduleThatCannotBeReadAnswersNoGrid($open, $close, $slot) {
    $this->assertSame([], myapi_reservation_form_time_grid($open, $close, $slot));
  }

  public static function unusableSchedules() {
    return [
      'no opening time'        => [NULL, '22:00', 60],
      'no closing time'        => ['08:00', NULL, 60],
      'opening not HH:MM'      => ['8:00', '22:00', 60],
      'closing out of clock'   => ['08:00', '24:00', 60],
      'no block'               => ['08:00', '22:00', NULL],
      'block of zero'          => ['08:00', '22:00', 0],
      'negative block'         => ['08:00', '22:00', -30],
      'block not a number'     => ['08:00', '22:00', 'abc'],
      'times that are arrays'  => [['08:00'], '22:00', 60],
    ];
  }

  /* -------------------------------------------------------------------------
   * The route — myapi_reservation_form_area_schedule().
   * ---------------------------------------------------------------------- */

  const EMPTY_SCHEDULE = [
    'open_time'    => NULL,
    'close_time'   => NULL,
    'slot_minutes' => NULL,
    'max_minutes'  => NULL,
    'grid'         => [],
  ];

  private function ask(...$arguments) {
    return myapi_test_capture(function () use ($arguments) {
      myapi_reservation_form_area_schedule(...$arguments);
    });
  }

  private function seedArea(array $fields = [], ?array $tables = NULL) {
    $fields += [
      'field_open_time'    => '08:00',
      'field_close_time'   => '10:00',
      'field_slot_minutes' => '60',
      'field_max_minutes'  => '120',
    ];

    $node = ['nid' => 40, 'type' => 'area'];
    foreach ($fields as $field => $value) {
      if ($value !== NULL) {
        $node[$field] = [LANGUAGE_NONE => [['value' => $value]]];
      }
    }

    myapi_test_db_seed($tables !== NULL ? $tables : ['node' => [['nid' => 40, 'type' => 'area', 'status' => 1]]]);
    myapi_test_node_seed([40 => $node]);
  }

  public function testTheScheduleOfAnAreaTravelsInTheEnvelope() {
    $this->seedArea();

    $result = $this->ask('40');

    $this->assertTrue($result['exited']);
    $this->assertSame(200, $result['status']);
    $this->assertSame(
      [
        'success' => TRUE,
        'data'    => [
          'open_time'    => '08:00',
          'close_time'   => '10:00',
          'slot_minutes' => 60,
          'max_minutes'  => 120,
          'grid'         => [
            ['time' => '08:00', 'offset' => 0],
            ['time' => '09:00', 'offset' => 60],
            ['time' => '10:00', 'offset' => 120],
          ],
        ],
      ],
      $result['json']
    );
    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
  }

  /**
   * @dataProvider idsThatAreNotAnArea
   */
  public function testAnIdThatIsNotANumberAnswersAnEmptyGridWithoutAQuery(array $arguments) {
    $this->seedArea();

    $result = $this->ask(...$arguments);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['success' => TRUE, 'data' => self::EMPTY_SCHEDULE], $result['json']);
    $this->assertSame([], myapi_test_db_queries(), 'no query is run for it');
  }

  public static function idsThatAreNotAnArea() {
    return [
      'no argument'  => [[]],
      'zero'         => [['0']],
      'not a number' => [['abc']],
      'negative'     => [['-40']],
    ];
  }

  /**
   * GUARD: the area is resolved through a 'node_access' query first, and when
   * it does not come back — what a building admin gets for an area of another
   * condominium — its schedule is not read at all.
   */
  public function testAnAreaTheOperatorCannotSeeAnswersAnEmptyGrid() {
    $this->seedArea([], ['node' => []]);

    $result = $this->ask('40');

    $this->assertSame(self::EMPTY_SCHEDULE, $result['json']['data']);

    $query = myapi_test_db_queries('node')[0];
    $this->assertContains('node_access', $query['tags']);
    $this->assertSame(['n.nid' => 40, 'n.type' => 'area'], array_column($query['conditions'], 'value', 'field'));
  }

  public function testAnAreaThatNoLongerLoadsAnswersAnEmptyGrid() {
    $this->seedArea();
    myapi_test_node_seed();

    $this->assertSame(self::EMPTY_SCHEDULE, $this->ask('40')['json']['data']);
  }

  /**
   * An area with no block size keeps its times in the answer and gets no
   * grid: the form then leaves the text inputs as they are.
   */
  public function testAnAreaWithoutABlockSizeAnswersNoGrid() {
    $this->seedArea(['field_slot_minutes' => NULL]);

    $data = $this->ask('40')['json']['data'];

    $this->assertSame('08:00', $data['open_time']);
    $this->assertNull($data['slot_minutes']);
    $this->assertSame([], $data['grid']);
  }

  /**
   * NULL is the only "no limit", same reading as
   * myapi_reservation_schedule_violations(): a stored 0 refuses every
   * duration on save, so it must reach the form as 0 and not as freedom.
   *
   * @dataProvider storedNumbers
   */
  public function testTheTwoNumbersTravelAsStoredAndAsNullWhenNotNumeric($stored, $expected) {
    $this->seedArea(['field_max_minutes' => $stored, 'field_slot_minutes' => $stored]);

    $data = $this->ask('40')['json']['data'];

    $this->assertSame($expected, $data['max_minutes']);
    $this->assertSame($expected, $data['slot_minutes']);
  }

  public static function storedNumbers() {
    return [
      'nothing stored' => [NULL, NULL],
      'not a number'   => ['abc', NULL],
      'zero'           => ['0', 0],
      'negative'       => ['-5', -5],
      'a number'       => ['90', 90],
    ];
  }

  /**
   * GUARD: the form and the save must read the maximum the same way. Whatever
   * the route hands over for a stored value, the rule applied on save agrees
   * on whether a one-block reservation fits.
   *
   * @dataProvider storedMaxima
   */
  public function testTheMaximumReadsTheSameOnTheFormAndOnSave($stored, $one_hour_fits) {
    $this->seedArea(['field_max_minutes' => $stored]);

    $maximum = $this->ask('40')['json']['data']['max_minutes'];
    $offered = $maximum === NULL || 60 <= $maximum;
    $violations = myapi_reservation_schedule_violations('active', '2999-01-01', '08:00', 540, 480, 600, $stored, 0);

    $this->assertSame($one_hour_fits, $offered);
    $this->assertSame($one_hour_fits, !in_array('reservation_duration_exceeded', $violations, TRUE));
  }

  public static function storedMaxima() {
    return [
      'no maximum' => [NULL, TRUE],
      'zero'       => ['0', FALSE],
      'too short'  => ['30', FALSE],
      'enough'     => ['60', TRUE],
    ];
  }

  /* -------------------------------------------------------------------------
   * The 'reservation' form — myapi_reservation_form_alter().
   * ---------------------------------------------------------------------- */

  private function textField() {
    return ['#language' => 'und', 'und' => [0 => ['value' => ['#type' => 'textfield']]]];
  }

  private function reservationForm() {
    $form = [];
    foreach (array_column(myapi_reservation_form_selectors(), 'field') as $field) {
      $form[$field] = [
        '#language' => 'und',
        'und'       => [0 => ['target_id' => ['#type' => 'textfield', '#autocomplete_path' => 'entityreference/x']]],
      ];
    }
    $form['field_start_time'] = $this->textField();
    $form['field_end_time'] = $this->textField();

    return $form;
  }

  private function settings(array $form) {
    $settings = array_values(array_filter($form['#attached']['js'], 'is_array'));

    return $settings[0]['data']['myapiReservationForm'];
  }

  public function testTheTwoTimeInputsAreTaggedForTheScript() {
    $form = $this->reservationForm();
    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertSame('start', $form['field_start_time']['und'][0]['value']['#attributes']['data-myapi-reservation-time']);
    $this->assertSame('end', $form['field_end_time']['und'][0]['value']['#attributes']['data-myapi-reservation-time']);
  }

  public function testAttributesTheInputAlreadyHadAreKept() {
    $form = $this->reservationForm();
    $form['field_start_time']['und'][0]['value']['#attributes'] = ['class' => ['x']];
    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertSame(
      ['class' => ['x'], 'data-myapi-reservation-time' => 'start'],
      $form['field_start_time']['und'][0]['value']['#attributes']
    );
  }

  /**
   * The time selects follow the area selector. When the four selectors are
   * not the expected widgets the alter stops before the times, and the whole
   * form — times included — is left exactly as it arrived.
   */
  public function testWhenTheSelectorsAreNotWiredTheTimesAreNotEither() {
    $form = $this->reservationForm();
    unset($form['field_area']['und'][0]['target_id']['#autocomplete_path']);
    $before = $form;

    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertSame($before, $form);
  }

  /**
   * GUARD: the schedule route is asked by the reservation form only, so it
   * opens to exactly who may open that form. Any other access callback either
   * locks operators out or hands area schedules to somebody else.
   */
  public function testTheScheduleRouteIsGuardedByTheFormAccess() {
    $item = myapi_menu()['admin/content/reservations/area-schedule/%'];

    $this->assertSame('myapi_reservation_form_access', $item['access callback']);
    $this->assertSame('myapi_reservation_form_area_schedule', $item['page callback']);
    $this->assertSame([4], $item['page arguments']);
    $this->assertSame('includes/myapi.reservation_form.inc', $item['file']);
  }

  /**
   * GUARD: the inputs stay text fields on the server. The select is built in
   * the browser and writes into them, so the SPEC 52 validation and a browser
   * without JavaScript see what they always saw.
   */
  public function testTheTimeInputsStayTextFields() {
    $form = $this->reservationForm();
    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    foreach (['field_start_time', 'field_end_time'] as $field) {
      $this->assertSame('textfield', $form[$field]['und'][0]['value']['#type']);
      $this->assertArrayNotHasKey('#disabled', $form[$field]['und'][0]['value']);
    }
  }

  public function testTheScriptIsToldWhereToAskForTheSchedule() {
    $form = $this->reservationForm();
    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertSame(
      [
        'schedulePath' => url('admin/content/reservations/area-schedule', ['absolute' => TRUE]),
        'noArea'       => '- Elige un área -',
        'pick'         => '- Elige una hora -',
      ],
      $this->settings($form)['times']
    );
  }

  /**
   * ALL OR NOTHING for the two times, and on their own: a time widget changed
   * from the field UI leaves both inputs alone, and the four selectors keep
   * working.
   *
   * @dataProvider brokenTimeWidgets
   */
  public function testATimeWidgetThatIsNotATextFieldLeavesBothTimesAlone($field, $breakage) {
    $form = $this->reservationForm();
    if ($breakage === 'missing') {
      unset($form[$field]);
    }
    elseif ($breakage === 'language') {
      unset($form[$field]['#language']);
    }
    else {
      $form[$field]['und'][0]['value']['#type'] = 'select';
    }
    $times_before = array_intersect_key($form, ['field_start_time' => 1, 'field_end_time' => 1]);

    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertSame($times_before, array_intersect_key($form, ['field_start_time' => 1, 'field_end_time' => 1]));
    $this->assertArrayNotHasKey('times', $this->settings($form));
    $this->assertSame('area', $form['field_area']['und'][0]['target_id']['#attributes']['data-myapi-reservation-selector']);
  }

  public static function brokenTimeWidgets() {
    $cases = [];
    foreach (['field_start_time', 'field_end_time'] as $field) {
      foreach (['missing', 'language', 'type'] as $breakage) {
        $cases[$field . ' ' . $breakage] = [$field, $breakage];
      }
    }

    return $cases;
  }

  /* -------------------------------------------------------------------------
   * The 'area' form — myapi_area_form_alter().
   * ---------------------------------------------------------------------- */

  private function areaForm() {
    return [
      'title'            => ['#type' => 'textfield'],
      'field_open_time'  => $this->textField(),
      'field_close_time' => $this->textField(),
    ];
  }

  public function testTheOpeningAndClosingInputsAreTaggedAndTheScriptAttached() {
    $form = $this->areaForm();
    myapi_area_form_alter($form);

    foreach (['field_open_time', 'field_close_time'] as $field) {
      $this->assertSame('1', $form[$field]['und'][0]['value']['#attributes']['data-myapi-time-select']);
      $this->assertSame('textfield', $form[$field]['und'][0]['value']['#type']);
    }
    $this->assertSame([drupal_get_path('module', 'myapi') . '/js/myapi.time_select.js'], $form['#attached']['js']);
  }

  /**
   * Field by field, unlike the reservation times: the two inputs do not depend
   * on each other, so one changed widget does not cost the other its selects.
   */
  public function testAnAreaTimeThatIsNotATextFieldIsSkippedOnItsOwn() {
    $form = $this->areaForm();
    $form['field_close_time']['und'][0]['value']['#type'] = 'select';
    myapi_area_form_alter($form);

    $this->assertSame('1', $form['field_open_time']['und'][0]['value']['#attributes']['data-myapi-time-select']);
    $this->assertArrayNotHasKey('#attributes', $form['field_close_time']['und'][0]['value']);
    $this->assertCount(1, $form['#attached']['js']);
  }

  public function testAnAreaFormWithoutTheTimeFieldsIsLeftUntouched() {
    $form = ['title' => ['#type' => 'textfield']];
    myapi_area_form_alter($form);

    $this->assertSame(['title' => ['#type' => 'textfield']], $form);
  }

  /**
   * The hook in myapi.module is glue: it reaches the alter and nothing else.
   */
  public function testTheAreaFormHookDelegatesToTheAlter() {
    $form = $this->areaForm();
    $form_state = [];
    myapi_form_area_node_form_alter($form, $form_state, 'area_node_form');

    $this->assertSame('1', $form['field_open_time']['und'][0]['value']['#attributes']['data-myapi-time-select']);
  }

}
