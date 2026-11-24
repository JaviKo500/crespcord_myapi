<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../myapi.module';
require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.time_format.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_rules.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_admin.inc';

/**
 * Unit tests for the business rules of a reservation on the back-office node
 * form (SPEC 135).
 *
 * Until that spec the form applied none of what POST /api/v1/reservations
 * applies. The rules moved to includes/myapi.reservation_rules.inc, where both
 * write paths read them, and myapi_reservation_validate_rules() runs them from
 * hook_node_validate().
 *
 * THE CONTRACT THIS CLASS PINS:
 *  - every rule of the endpoint is applied to the form, for every role;
 *  - EXCEPT THE BALANCE, the one rule an operator may skip — asserted both by
 *    behaviour (a unit in debt is booked) and by source (the admin file never
 *    names the balance check);
 *  - cancelling, and saving an old reservation untouched, never trip a rule;
 *  - an edit does not collide with its own stored copy;
 *  - a value the rules cannot read REFUSES the save instead of skipping them;
 *  - a reservation that passes holds the area's row lock — the endpoint's own —
 *    from the validation until the node is written.
 *
 * WHAT THIS LAYER CANNOT REACH: that the Date widget hands the validation a
 * 'YYYY-MM-DD...' string in a real browser submit (if it does not, the form
 * now says so instead of saving), how the errors look in the back-office
 * theme, and that the lock really blocks a second writer: the fixture database
 * has no rows to lock and no second connection. Those are the manual matrix of
 * the spec.
 */
class ReservationAdminRulesTest extends TestCase {

  const UNIT = 45;
  const OTHER_UNIT = 46;
  const UID = 3;
  const AREA = 700;
  const RESERVATION = 800;

  protected function setUp(): void {
    myapi_reservation_release_area_lock();
    myapi_test_db_seed();
    myapi_test_node_seed();
    $GLOBALS['myapi_test_form_errors'] = [];
    $GLOBALS['myapi_test_db_locks'] = [];
    $GLOBALS['myapi_test_shutdown_functions'] = [];
  }

  protected function tearDown(): void {
    myapi_reservation_release_area_lock();
    myapi_test_db_seed();
    myapi_test_node_seed();
    $GLOBALS['myapi_test_form_errors'] = [];
    $GLOBALS['myapi_test_db_locks'] = [];
    $GLOBALS['myapi_test_shutdown_functions'] = [];
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  private function tomorrow() {
    return date('Y-m-d', REQUEST_TIME + 86400);
  }

  /**
   * An 'area' node: active, 08:00-22:00, 120 minutes at most, one at a time,
   * open to owners and tenants alike.
   */
  private function area(array $spec = []) {
    $spec += [
      'status' => 'active', 'open' => '08:00', 'close' => '22:00',
      'max' => 120, 'capacity' => NULL, 'who' => 'both', 'type' => 'area',
    ];

    $node = (object) ['nid' => self::AREA, 'type' => $spec['type'], 'status' => 1, 'title' => 'Piscina'];
    $map = [
      'field_area_status' => 'status', 'field_open_time' => 'open', 'field_close_time' => 'close',
      'field_max_minutes' => 'max', 'field_concurrent_reservations' => 'capacity',
      'field_who_can_reserve' => 'who',
    ];
    foreach ($map as $field => $key) {
      if ($spec[$key] !== NULL) {
        $node->{$field}[LANGUAGE_NONE][0]['value'] = $spec[$key];
      }
    }

    return $node;
  }

  /**
   * The pseudo-node the form submits (or a stored one, with a nid).
   */
  private function reservation(array $spec = [], $nid = NULL) {
    $spec += [
      'unit' => self::UNIT, 'area' => self::AREA, 'requester' => self::UID,
      'date' => $this->tomorrow() . ' 00:00:00', 'start' => '10:00', 'end' => '11:00',
      'state' => 'confirmed',
    ];

    $node = (object) ['type' => 'reservation', 'status' => 1];
    if ($nid) {
      $node->nid = $nid;
    }
    $node->field_unit[LANGUAGE_NONE][0]['target_id'] = $spec['unit'];
    $node->field_area[LANGUAGE_NONE][0]['target_id'] = $spec['area'];
    $node->field_requester[LANGUAGE_NONE][0]['target_id'] = $spec['requester'];
    $node->field_date[LANGUAGE_NONE][0]['value'] = $spec['date'];
    $node->field_start_time[LANGUAGE_NONE][0]['value'] = $spec['start'];
    $node->field_end_time[LANGUAGE_NONE][0]['value'] = $spec['end'];
    $node->field_reservation_status[LANGUAGE_NONE][0]['value'] = $spec['state'];

    return $node;
  }

  /**
   * One stored reservation, as the two lookups of the rules read it.
   */
  private function row(array $spec) {
    $spec += [
      'unit' => self::OTHER_UNIT, 'area' => self::AREA, 'state' => 'confirmed',
      'date' => $this->tomorrow(), 'start' => '10:00', 'end' => '11:00',
    ];

    return [
      'nid'                            => (string) $spec['id'],
      'type'                           => 'reservation',
      'status'                         => '1',
      'field_unit_target_id'           => (string) $spec['unit'],
      'field_area_target_id'           => (string) $spec['area'],
      'field_reservation_status_value' => $spec['state'],
      'field_date_value'               => $spec['date'],
      'date'                           => $spec['date'],
      'field_start_time_value'         => $spec['start'],
      'start_time'                     => $spec['start'],
      'field_end_time_value'           => $spec['end'],
      'end_time'                       => $spec['end'],
    ];
  }

  /**
   * Seeds the site: the area, the stored reservations and who owns the unit.
   */
  private function site(array $rows = [], ?array $area = NULL, array $nodes = [], array $tables = []) {
    myapi_reservation_release_area_lock();
    $GLOBALS['myapi_test_db_locks'] = [];
    myapi_test_node_seed([self::AREA => $this->area($area ?: [])] + $nodes);
    myapi_test_db_seed($tables + [
      'node' => array_map([$this, 'row'], $rows),
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);
  }

  private function validate($node) {
    $form = [];
    $form_state = [];
    myapi_reservation_validate_rules($node, $form, $form_state);

    return $GLOBALS['myapi_test_form_errors'];
  }

  private function schedule(array $spec = []) {
    $spec += [
      'status' => 'active', 'date' => $this->tomorrow(), 'start' => '10:00', 'end' => 660,
      'open' => 480, 'close' => 1320, 'max' => 120,
    ];

    return myapi_reservation_schedule_violations(
      $spec['status'], $spec['date'], $spec['start'], $spec['end'],
      $spec['open'], $spec['close'], $spec['max'], REQUEST_TIME
    );
  }

  /* -------------------------------------------------------------------------
   * The shared rules — includes/myapi.reservation_rules.inc.
   * ---------------------------------------------------------------------- */

  public function testACandidateInsideEveryRuleHasNoViolation() {
    $this->assertSame([], $this->schedule());
  }

  public function testEachScheduleRuleAnswersItsOwnKey() {
    $this->assertSame(['area_not_active'], $this->schedule(['status' => 'inactive']));
    $this->assertSame(['area_not_active'], $this->schedule(['status' => NULL]), 'no status is not active');
    $this->assertSame(['reservation_in_past'], $this->schedule(['date' => date('Y-m-d', REQUEST_TIME - 2 * 86400)]));
    $this->assertSame(['reservation_outside_hours'], $this->schedule(['start' => '07:00', 'end' => 480]));
    $this->assertSame(['reservation_outside_hours'], $this->schedule(['start' => '21:30', 'end' => 1350]));
    $this->assertSame(['reservation_duration_exceeded'], $this->schedule(['end' => 780]));
    $this->assertSame([], $this->schedule(['end' => 780, 'max' => NULL]), 'no maximum, no limit');
    $this->assertSame([], $this->schedule(['end' => 720]), 'exactly the maximum is allowed');
  }

  /**
   * An area with no opening or no closing time fails closed.
   */
  public function testAnAreaWithoutHoursIsOutOfHours() {
    $this->assertSame(['reservation_outside_hours'], $this->schedule(['open' => NULL]));
    $this->assertSame(['reservation_outside_hours'], $this->schedule(['close' => NULL]));
  }

  /**
   * Crossing midnight in a same-day area is reported as that, and not also as
   * out of hours.
   */
  public function testCrossingMidnightIsNotAlsoOutOfHours() {
    $this->assertSame(
      ['reservation_crosses_midnight'],
      $this->schedule(['start' => '23:00', 'end' => 1500, 'close' => 1439])
    );
  }

  /**
   * An area that closes after midnight takes a range across it (SPEC 41).
   */
  public function testAnOvernightAreaTakesARangeAcrossMidnight() {
    $this->assertSame([], $this->schedule(['start' => '23:00', 'end' => 1500, 'open' => 720, 'close' => 120]));
    $this->assertSame(
      ['reservation_outside_hours'],
      $this->schedule(['start' => '01:30', 'end' => 180, 'open' => 720, 'close' => 120]),
      'a tail start that overruns the close'
    );
  }

  /**
   * A start at this very second has not passed; one second ago has.
   */
  public function testAStartRightNowIsNotInThePast() {
    $start = strtotime('2026-12-01 10:00:00');
    $at = function ($now) {
      return myapi_reservation_schedule_violations('active', '2026-12-01', '10:00', 660, 480, 1320, 120, $now);
    };

    $this->assertSame([], $at($start));
    $this->assertSame(['reservation_in_past'], $at($start + 1));
  }

  /**
   * The order is the endpoint's contract: it aborts on the first one.
   */
  public function testViolationsComeInValidationOrder() {
    $this->assertSame(
      ['area_not_active', 'reservation_in_past', 'reservation_outside_hours', 'reservation_duration_exceeded'],
      $this->schedule(['status' => 'inactive', 'date' => '2020-01-01', 'start' => '06:00', 'end' => 600])
    );
  }

  public function testWhoMayReserveFollowsTheArea() {
    myapi_test_db_seed([
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '9', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertTrue(myapi_reservation_requester_may_reserve('owner', 45, 3));
    $this->assertFalse(myapi_reservation_requester_may_reserve('owner', 45, 9));
    $this->assertTrue(myapi_reservation_requester_may_reserve('tenant', 45, 9));
    $this->assertFalse(myapi_reservation_requester_may_reserve('tenant', 45, 3));

    myapi_test_db_seed();
    foreach (['both', '', NULL, 'anything'] as $open) {
      $this->assertTrue(myapi_reservation_requester_may_reserve($open, 45, 77), json_encode($open));
    }
    $this->assertSame([], myapi_test_db_queries(), 'an open area looks nobody up');
  }

  public function testASlotIsFullOnlyWhenTheCapacityIsReached() {
    $day = $this->tomorrow();
    myapi_test_db_seed(['node' => [$this->row(['id' => 1]), $this->row(['id' => 2, 'start' => '10:30', 'end' => '11:30'])]]);

    $this->assertTrue(myapi_reservation_slot_is_full(self::AREA, 1, $day, 600, 660));
    $this->assertTrue(myapi_reservation_slot_is_full(self::AREA, 2, $day, 630, 660), 'both coincide at 10:30');
    $this->assertFalse(myapi_reservation_slot_is_full(self::AREA, 3, $day, 630, 660));
    $this->assertFalse(myapi_reservation_slot_is_full(self::AREA, 1, $day, 690, 750), 'back to back does not overlap');
    $this->assertFalse(myapi_reservation_slot_is_full(self::AREA, 1, $day, 480, 600), 'ending where another starts');
  }

  /**
   * The reservation being edited does not collide with its own stored copy.
   */
  public function testAnExcludedReservationDoesNotCount() {
    $day = $this->tomorrow();
    myapi_test_db_seed(['node' => [$this->row(['id' => self::RESERVATION, 'unit' => self::UNIT])]]);

    $this->assertTrue(myapi_reservation_slot_is_full(self::AREA, 1, $day, 600, 660));
    $this->assertFalse(myapi_reservation_slot_is_full(self::AREA, 1, $day, 600, 660, self::RESERVATION));

    $this->assertTrue(myapi_reservation_has_active_reservation(self::UNIT, self::AREA));
    $this->assertFalse(myapi_reservation_has_active_reservation(self::UNIT, self::AREA, self::RESERVATION));
  }

  /* -------------------------------------------------------------------------
   * When the form applies them — myapi_reservation_admin_rules_apply().
   * ---------------------------------------------------------------------- */

  private function values(array $override = []) {
    return $override + [
      'field_unit' => 45, 'field_area' => 700, 'field_requester' => 3,
      'field_date' => '2026-12-01', 'field_start_time' => '10:00', 'field_end_time' => '11:00',
      'field_reservation_status' => 'confirmed',
    ];
  }

  public function testANewConfirmedReservationIsAlwaysValidated() {
    $this->assertTrue(myapi_reservation_admin_rules_apply(TRUE, $this->values(), []));
  }

  /**
   * Cancelling is the everyday edit: it must never trip a rule.
   */
  public function testAReservationNotSavedAsConfirmedIsNeverValidated() {
    $cancelled = $this->values(['field_reservation_status' => 'cancelled']);

    $this->assertFalse(myapi_reservation_admin_rules_apply(TRUE, $cancelled, []));
    $this->assertFalse(myapi_reservation_admin_rules_apply(FALSE, $cancelled, $this->values()));
    $this->assertFalse(myapi_reservation_admin_rules_apply(TRUE, $this->values(['field_reservation_status' => NULL]), []));
  }

  public function testAnEditIsValidatedOnlyWhenItTouchesWhatARuleReads() {
    $stored = $this->values();

    $this->assertFalse(myapi_reservation_admin_rules_apply(FALSE, $this->values(), $stored), 'untouched');
    $this->assertFalse(
      myapi_reservation_admin_rules_apply(FALSE, $this->values(['field_unit' => '45']), $stored),
      'a string id is the same id'
    );

    foreach (myapi_reservation_admin_rule_fields() as $field) {
      if ($field === 'field_reservation_status') {
        continue;
      }
      $this->assertTrue(myapi_reservation_admin_rules_apply(FALSE, $this->values([$field => 'x']), $stored), $field);
    }

    $this->assertTrue(
      myapi_reservation_admin_rules_apply(FALSE, $this->values(), $this->values(['field_reservation_status' => 'cancelled'])),
      'bringing a cancelled reservation back takes a slot again'
    );
  }

  /* -------------------------------------------------------------------------
   * What the operator reads — myapi_reservation_admin_rule_errors().
   * ---------------------------------------------------------------------- */

  public function testEveryRuleHasAFieldAndAMessage() {
    $keys = [
      'reservation_role_not_allowed' => 'field_requester',
      'area_not_active'               => 'field_area',
      'reservation_in_past'           => 'field_date',
      'reservation_crosses_midnight'  => 'field_end_time',
      'reservation_outside_hours'     => 'field_start_time',
      'reservation_duration_exceeded' => 'field_end_time',
      'reservation_overlap'           => 'field_start_time',
      'area_capacity_full'            => 'field_start_time',
      'reservation_already_active'    => 'field_unit',
    ];

    foreach ($keys as $key => $field) {
      $errors = myapi_reservation_admin_rule_errors([$key]);
      $this->assertCount(1, $errors, $key);
      $this->assertSame($field, $errors[0]['field'], $key);
      $this->assertNotSame('', trim($errors[0]['message']), $key);
    }
  }

  /**
   * form_set_error() keeps one message per name: the first violation of a
   * field wins, and an unknown key is skipped.
   */
  public function testOneErrorPerFieldAndUnknownKeysAreSkipped() {
    $errors = myapi_reservation_admin_rule_errors([
      'reservation_outside_hours', 'insufficient_balance', 'reservation_overlap', 'area_not_active',
    ]);

    $this->assertSame(['field_start_time', 'field_area'], array_column($errors, 'field'));
    $this->assertStringContainsString('horario de atención', $errors[0]['message']);
  }

  /* -------------------------------------------------------------------------
   * The save — myapi_reservation_validate_rules().
   * ---------------------------------------------------------------------- */

  public function testAValidReservationIsSaved() {
    $this->site();

    $this->assertSame([], $this->validate($this->reservation()));
  }

  public function testEachRuleFlagsItsInput() {
    $cases = [
      'field_area'       => [[], ['status' => 'inactive'], []],
      'field_date'       => [[], [], ['date' => '2020-01-01 00:00:00']],
      'field_start_time' => [[], [], ['start' => '06:00', 'end' => '07:00']],
      'field_end_time'   => [[], [], ['end' => '13:00']],
      'field_requester'  => [[], ['who' => 'tenant'], []],
      'field_unit'       => [[['id' => 1, 'unit' => self::UNIT, 'start' => '15:00', 'end' => '16:00']], [], []],
    ];

    foreach ($cases as $field => $case) {
      list($rows, $area, $reservation) = $case;
      $GLOBALS['myapi_test_form_errors'] = [];
      $this->site($rows, $area);

      $this->assertSame([$field], array_keys($this->validate($this->reservation($reservation))), $field);
    }
  }

  public function testAnOverlapIsRefusedAndNamedByCapacity() {
    $this->site([['id' => 1]]);
    $errors = $this->validate($this->reservation());
    $this->assertSame(['field_start_time'], array_keys($errors));
    $this->assertStringContainsString('reserva existente', $errors['field_start_time']);

    $GLOBALS['myapi_test_form_errors'] = [];
    $this->site([['id' => 1]], ['capacity' => 2]);
    $this->assertSame([], $this->validate($this->reservation()), 'one of two slots is still free');

    $GLOBALS['myapi_test_form_errors'] = [];
    $this->site([['id' => 1], ['id' => 2]], ['capacity' => 2]);
    $errors = $this->validate($this->reservation());
    $this->assertStringContainsString('cupo', $errors['field_start_time']);
  }

  /**
   * An end at or before the start is the next day (SPEC 41): refused by a
   * same-day area, taken by an overnight one.
   */
  public function testAnEndBeforeTheStartCrossesMidnight() {
    $crossing = ['start' => '21:00', 'end' => '01:00', 'date' => $this->tomorrow() . ' 00:00:00'];

    $this->site([], ['max' => NULL]);
    $this->assertSame(['field_end_time'], array_keys($this->validate($this->reservation($crossing))));

    $GLOBALS['myapi_test_form_errors'] = [];
    $this->site([], ['max' => NULL, 'open' => '12:00', 'close' => '02:00']);
    $this->assertSame([], $this->validate($this->reservation($crossing)));
  }

  /**
   * An end equal to the start is a full day, not an empty range. A same-day
   * area refuses it as crossing midnight; an overnight one, where crossing is
   * legal, as longer than its opening window — never as a zero-length booking
   * that fits anywhere.
   */
  public function testAnEndEqualToTheStartIsTheNextDay() {
    $this->site([], ['max' => NULL]);
    $this->assertSame(['field_end_time'], array_keys($this->validate($this->reservation(['start' => '10:00', 'end' => '10:00']))));

    $GLOBALS['myapi_test_form_errors'] = [];
    $this->site([], ['max' => NULL, 'open' => '12:00', 'close' => '02:00']);
    $this->assertSame(['field_start_time'], array_keys($this->validate($this->reservation(['start' => '23:00', 'end' => '23:00']))));
  }

  /**
   * An area with no opening or no closing time fails closed on the form too.
   */
  public function testAnAreaWithoutHoursRefusesTheSave() {
    foreach (['open', 'close'] as $missing) {
      $GLOBALS['myapi_test_form_errors'] = [];
      $this->site([], [$missing => NULL]);

      $this->assertSame(['field_start_time'], array_keys($this->validate($this->reservation())), $missing);
    }
  }

  /**
   * An edit whose stored copy cannot be loaded has nothing to compare against,
   * so it is validated like a new reservation instead of waved through.
   */
  public function testAnEditWithNoStoredCopyIsValidated() {
    $this->site([], ['status' => 'inactive']);

    $this->assertSame(['field_area'], array_keys($this->validate($this->reservation([], self::RESERVATION))));
  }

  /**
   * THE ONE RULE THE BACK OFFICE SKIPS. A unit in debt — the exact fixture the
   * endpoint answers insufficient_balance for — is booked, and neither the
   * unit's balance nor its receipts are even read.
   */
  public function testAUnitInDebtCanBeBookedFromTheBackOffice() {
    $unit = (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1];
    $unit->field_saldo_actual[LANGUAGE_NONE][0]['value'] = '150.00';

    $this->site([], NULL, [self::UNIT => $unit], [
      'node' => [[
        'nid' => '9', 'type' => 'recibo', 'status' => '1',
        'field_vivienda_target_id' => (string) self::UNIT, 'field_estado_value' => 'Enviado',
        'period_start' => '2026-10-01', 'previous_balance' => '80.00',
      ]],
    ]);

    $this->assertSame([], $this->validate($this->reservation()));

    foreach (myapi_test_db_queries() as $query) {
      $this->assertStringNotContainsString('recibo', json_encode($query), 'no receipt is looked up');
    }
  }

  /**
   * The same promise, read from the source: the admin file never names the
   * balance check, and the shared rules file does not host it.
   */
  public function testTheBackOfficeNeverCallsTheBalanceCheck() {
    $root = dirname(__DIR__, 2);
    $pattern = '/myapi_reservation_check_balance\s*\(/';

    foreach (['includes/myapi.reservation_admin.inc', 'includes/myapi.reservation_form.inc'] as $file) {
      $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', file_get_contents($root . '/' . $file));
      $this->assertSame(0, preg_match($pattern, $code), $file);
    }

    $this->assertStringNotContainsString(
      'function myapi_reservation_check_balance',
      file_get_contents($root . '/includes/myapi.reservation_rules.inc')
    );
    $this->assertSame(1, preg_match($pattern, file_get_contents($root . '/resources/reservation.resource.inc')), 'the endpoint still checks it');
  }

  /**
   * Cancelling an old reservation on a closed area and a full slot goes
   * through without a single lookup.
   */
  public function testCancellingNeverTripsARule() {
    $stored = $this->reservation(['date' => '2020-01-01 00:00:00'], self::RESERVATION);
    $this->site([['id' => 1, 'date' => '2020-01-01']], ['status' => 'inactive'], [self::RESERVATION => $stored]);

    $submitted = $this->reservation(['date' => '2020-01-01 00:00:00', 'state' => 'cancelled'], self::RESERVATION);

    $this->assertSame([], $this->validate($submitted));
    $this->assertSame([], myapi_test_db_queries());
  }

  public function testAnUntouchedOldReservationIsNotRevalidated() {
    $stored = $this->reservation(['date' => '2020-01-01 00:00:00'], self::RESERVATION);
    $this->site([], ['status' => 'inactive'], [self::RESERVATION => $stored]);

    $this->assertSame([], $this->validate($this->reservation(['date' => '2020-01-01'], self::RESERVATION)), 'a stored datetime and a typed date are the same day');
  }

  /**
   * Moving a reservation re-validates it, without counting its own stored
   * copy against the capacity or the one-active-per-unit rule.
   */
  public function testAnEditDoesNotCollideWithItself() {
    $stored = $this->reservation([], self::RESERVATION);
    $own = ['id' => self::RESERVATION, 'unit' => self::UNIT];

    $this->site([$own], NULL, [self::RESERVATION => $stored]);
    $this->assertSame([], $this->validate($this->reservation(['start' => '10:30', 'end' => '11:30'], self::RESERVATION)));

    $GLOBALS['myapi_test_form_errors'] = [];
    $this->site([$own, ['id' => 2, 'start' => '11:00', 'end' => '12:00']], NULL, [self::RESERVATION => $stored]);
    $this->assertSame(
      ['field_start_time'],
      array_keys($this->validate($this->reservation(['start' => '10:30', 'end' => '11:30'], self::RESERVATION))),
      'somebody else is still in the way'
    );
  }

  /**
   * The rules wait for SPEC 52 and SPEC 134: a form that already has an error
   * gets no second opinion, and nothing is looked up.
   */
  public function testAFormThatAlreadyHasAnErrorIsLeftAlone() {
    $this->site([['id' => 1]]);
    $GLOBALS['myapi_test_form_errors'] = ['field_start_time' => 'formato'];

    $this->assertSame(['field_start_time' => 'formato'], $this->validate($this->reservation()));
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * FAIL CLOSED. The form got here with no error, so a value the rules cannot
   * read means a confirmed reservation about to be saved with no rule applied.
   * It is refused, on the input that could not be read, and never fatals.
   */
  public function testUnreadableValuesRefuseTheSave() {
    $unreadable = [
      'field_date'       => [['date' => ['year' => 2026]], ['date' => 'mañana'], ['date' => 1795000000]],
      'field_start_time' => [['start' => '8:00']],
      'field_end_time'   => [['end' => 'ocho']],
      'field_area'       => [['area' => 0], ['area' => 999]],
      'field_unit'       => [['unit' => NULL]],
      'field_requester'  => [['requester' => '']],
    ];

    foreach ($unreadable as $field => $overrides) {
      foreach ($overrides as $override) {
        $GLOBALS['myapi_test_form_errors'] = [];
        $this->site([['id' => 1]]);

        $errors = $this->validate($this->reservation($override));

        $this->assertSame([$field], array_keys($errors), json_encode($override));
        $this->assertStringContainsString('no se guarda', $errors[$field]);
        $this->assertSame([], myapi_test_db_queries(), 'no rule is evaluated over it');
        $this->assertSame([], $GLOBALS['myapi_test_db_locks'], 'and no lock is taken');
      }
    }

    $GLOBALS['myapi_test_form_errors'] = [];
    $this->site([], ['type' => 'vivienda']);
    $this->assertSame(['field_area'], array_keys($this->validate($this->reservation())), 'a node that is not an area');
  }

  public function testEveryUnreadableFieldIsNamed() {
    $this->assertSame([], myapi_reservation_admin_unreadable_fields(['a' => TRUE, 'b' => TRUE]));
    $this->assertSame(['a', 'c'], myapi_reservation_admin_unreadable_fields(['a' => FALSE, 'b' => TRUE, 'c' => FALSE]));

    $this->site();
    $errors = $this->validate($this->reservation(['date' => 'x', 'start' => 'y']));
    $this->assertSame(['field_date', 'field_start_time'], array_keys($errors));
  }

  /**
   * Cancelling stays exempt even from that: nothing about a cancelled
   * reservation is read.
   */
  public function testACancelledReservationWithUnreadableValuesIsStillSaved() {
    $this->site();

    $this->assertSame([], $this->validate($this->reservation(['date' => 'mañana', 'state' => 'cancelled'])));
  }

  /* -------------------------------------------------------------------------
   * The area lock — myapi_reservation_hold_area_lock().
   * ---------------------------------------------------------------------- */

  private function openTransactions() {
    return $GLOBALS['myapi_test_transactions_open'] ?? 0;
  }

  /**
   * A reservation that passes is still holding the area's row lock, inside an
   * open transaction, when the validation returns: the save runs under it.
   * And the lock is taken BEFORE the two counts are read.
   */
  public function testAValidReservationHoldsTheAreaLockUntilItIsSaved() {
    $this->site();

    $this->assertSame([], $this->validate($this->reservation()));

    $this->assertSame([['table' => 'node', 'in_transaction' => TRUE]], $GLOBALS['myapi_test_db_locks']);
    $this->assertSame(1, $this->openTransactions(), 'still open after the validation');

    myapi_node_insert($this->reservation());
    $this->assertSame(0, $this->openTransactions(), 'released once the node is written');
  }

  public function testAnEditReleasesTheLockOnUpdate() {
    $stored = $this->reservation([], self::RESERVATION);
    $this->site([], NULL, [self::RESERVATION => $stored]);
    $moved = $this->reservation(['start' => '12:00', 'end' => '13:00'], self::RESERVATION);

    $this->assertSame([], $this->validate($moved));
    $this->assertSame(1, $this->openTransactions());

    $moved->original = $stored;
    myapi_node_update($moved);
    $this->assertSame(0, $this->openTransactions());
  }

  /**
   * The lock comes before the counts: reading "there is room" first and
   * locking afterwards would be no lock at all.
   */
  public function testTheLockIsTakenBeforeTheCountsAreRead() {
    $rules = file_get_contents(dirname(__DIR__, 2) . '/includes/myapi.reservation_admin.inc');
    $body = substr($rules, strpos($rules, 'function myapi_reservation_validate_rules('));

    $lock = strpos($body, 'myapi_reservation_hold_area_lock(');
    $this->assertNotFalse($lock);
    $this->assertGreaterThan($lock, strpos($body, 'myapi_reservation_slot_is_full('));
    $this->assertGreaterThan($lock, strpos($body, 'myapi_reservation_has_active_reservation('));
  }

  /**
   * Refused by a counted rule: the lock was taken and is given back at once,
   * because nothing is going to be saved.
   */
  public function testARefusedReservationGivesTheLockBack() {
    $this->site([['id' => 1]]);

    $this->assertNotSame([], $this->validate($this->reservation()));
    $this->assertCount(1, $GLOBALS['myapi_test_db_locks']);
    $this->assertSame(0, $this->openTransactions());
  }

  /**
   * Refused before the counts: the lock is never taken, and the counts are
   * still read so every problem is named at once.
   */
  public function testAReservationAlreadyRefusedNeverLocks() {
    $this->site([['id' => 1, 'start' => '06:00', 'end' => '07:00']], ['open' => '08:00']);

    $errors = $this->validate($this->reservation(['start' => '06:00', 'end' => '07:00']));

    $this->assertSame(['field_start_time'], array_keys($errors));
    $this->assertSame([], $GLOBALS['myapi_test_db_locks']);
    $this->assertSame(0, $this->openTransactions());
    $this->assertNotSame([], myapi_test_db_queries('node'), 'the counts ran anyway');
  }

  /**
   * A request that validated and never saved — a preview, another module's
   * error — ends through the shutdown function, registered once per request.
   */
  public function testTheShutdownFunctionEndsWhatWasNeverSaved() {
    $this->site();
    $this->validate($this->reservation());
    $this->validate($this->reservation());

    $this->assertSame(1, $this->openTransactions(), 'one transaction, however many validations');

    myapi_reservation_release_area_lock();
    $this->assertSame(0, $this->openTransactions());

    myapi_reservation_release_area_lock();
    $this->assertSame(0, $this->openTransactions(), 'releasing nothing is a no-op');

    // Registered once per request behind a static, so which test of this
    // process triggered it is not observable: the registration is read from
    // the source instead.
    $admin = file_get_contents(dirname(__DIR__, 2) . '/includes/myapi.reservation_admin.inc');
    $this->assertSame(1, substr_count($admin, "drupal_register_shutdown_function('myapi_reservation_release_area_lock');"));
  }

  /**
   * ONE LOCK FOR BOTH WRITE PATHS. The form and the endpoint only exclude each
   * other because they lock the same row through the same function.
   */
  public function testTheEndpointAndTheFormTakeTheSameLock() {
    $root = dirname(__DIR__, 2);
    $resource = file_get_contents($root . '/resources/reservation.resource.inc');

    $this->assertSame(1, substr_count($resource, 'myapi_reservation_lock_area($area_id);'));
    $this->assertStringNotContainsString('forUpdate', $resource, 'the endpoint has no lock of its own');
    $this->assertGreaterThan(
      strpos($resource, 'myapi_reservation_lock_area($area_id);'),
      strpos($resource, 'myapi_reservation_slot_is_full('),
      'and still takes it before reading the capacity'
    );

    $admin = file_get_contents($root . '/includes/myapi.reservation_admin.inc');
    $this->assertStringNotContainsString('forUpdate', $admin);

    myapi_reservation_lock_area(self::AREA);
    $this->assertSame('node', $GLOBALS['myapi_test_db_locks'][0]['table']);
    $this->assertCount(1, myapi_test_db_queries('node'), 'one query: the area row');
  }

  /**
   * A reservation saved outside the form — the endpoints, drush — goes through
   * the same hooks with nothing held, and nothing happens.
   */
  public function testSavingWithNothingHeldIsHarmless() {
    myapi_node_insert($this->reservation());

    $this->assertSame(0, $this->openTransactions());
    $this->assertSame([], myapi_test_db_queries());
  }

  /* -------------------------------------------------------------------------
   * The glue — myapi_node_validate().
   * ---------------------------------------------------------------------- */

  /**
   * The hook runs the rules, and runs them last: for whoever saves, with no
   * role exempt.
   */
  public function testTheNodeValidateHookRunsTheRulesLast() {
    $source = file_get_contents(dirname(__DIR__, 2) . '/myapi.module');
    $start = strpos($source, "    case 'reservation':\n      module_load_include('inc', 'myapi', 'includes/myapi.reservation_admin');");
    $this->assertNotFalse($start);
    $branch = substr($source, $start, strpos($source, 'return;', $start) - $start);

    $order = [
      'myapi_reservation_validate_times(',
      'myapi_reservation_form_validate_scope(',
      'myapi_building_admin_validate_reservation(',
      'myapi_reservation_validate_rules(',
    ];
    $last = -1;
    foreach ($order as $call) {
      $position = strpos($branch, $call);
      $this->assertNotFalse($position, $call);
      $this->assertGreaterThan($last, $position, $call);
      $last = $position;
    }

    $rules = file_get_contents(dirname(__DIR__, 2) . '/includes/myapi.reservation_admin.inc');
    $body = substr($rules, strpos($rules, 'function myapi_reservation_validate_rules('));
    $this->assertStringNotContainsString('$user', $body, 'no rule depends on who is logged in');
    $this->assertStringNotContainsString('user_access(', $body);
  }

}
