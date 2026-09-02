<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin_user.inc';

/**
 * Unit tests for the building admin's people scope (SPECS 49 and 51, covered
 * by SPEC 121).
 *
 * BuildingAdminTest and BuildingAdminUserTest already cover the pure decision
 * functions of this pair of files — who may be seen, which errors a
 * reservation form raises. What had no test at all is the layer ABOVE them:
 * the four entry points Drupal actually calls, which resolve the current user,
 * decide whether the filter applies to them, and hand the decision helpers
 * their arguments.
 *
 * That layer is where the scope is lost, not in the decisions:
 *
 *  - THE FILTER MUST NOT APPLY TO A FULL ADMINISTRATOR. An operator holding
 *    'administer users' is doing site administration, and narrowing their
 *    people list would be a bug they cannot work around.
 *  - IT MUST APPLY TO EVERY BUILDING ADMIN WHO IS NOT ONE, including uid 1 if
 *    somebody ever gave it the role — this file grants no implicit membership.
 *  - AND THE OPERATOR ALWAYS SEES THEMSELVES, whatever their scope resolves to,
 *    or they cannot open their own profile.
 *
 * The pure comparator of the availability ranges is here too, for want of a
 * better home: it is one `usort` callback of four lines, reached only through
 * a string callable, and it is what keeps the busy ranges of an area in
 * chronological order across a midnight boundary.
 */
class BuildingAdminUserAccessTest extends TestCase {

  const ADMIN_UID = 7;
  const NEIGHBOUR_UID = 3;
  const STRANGER_UID = 900;
  const CONDOMINIUM = 12;
  const UNIT = 45;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_static_reset();
    myapi_test_field_seed_allowed_values();
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_permissions'] = [];
    $GLOBALS['myapi_test_form_errors'] = [];
    unset($GLOBALS['myapi_test_user_view_access']);
    $GLOBALS['user'] = (object) ['uid' => 0, 'roles' => []];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_permissions'] = [];
    unset($GLOBALS['myapi_test_user_view_access']);
    $GLOBALS['user'] = (object) ['uid' => 0, 'roles' => []];
  }

  /**
   * An account object holding (or not) the building-admin role.
   */
  private function account($uid, $building_admin = TRUE, array $extra_roles = [], $condominiums = NULL) {
    $roles = $extra_roles;
    if ($building_admin) {
      $roles[] = MYAPI_BUILDING_ADMIN_ROLE;
    }

    $account = (object) ['uid' => $uid, 'name' => 'u' . $uid, 'roles' => $roles];

    // The assignment lives ON THE ACCOUNT — myapi_building_admin_condominium_ids()
    // reads the loaded user's own field, not a field_data table — so a fixture
    // operator carries it the way user_load() would answer it.
    if (is_array($condominiums)) {
      $account->{MYAPI_BUILDING_ADMIN_CONDO_FIELD} = [LANGUAGE_NONE => array_map(function ($nid) {
        return ['target_id' => $nid];
      }, $condominiums)];
    }

    return $account;
  }

  /**
   * The operator of self::CONDOMINIUM.
   */
  private function scopedAdmin() {
    return $this->account(self::ADMIN_UID, TRUE, [], [self::CONDOMINIUM]);
  }

  /**
   * Makes $account the current user.
   */
  private function actAs($account) {
    $GLOBALS['user'] = $account;
  }

  /**
   * Seeds the building the admin manages and the people who live in it.
   */
  private function seedBuilding() {
    // The condominium field must exist, or the assignment lookup answers empty
    // without querying.
    myapi_test_field_seed_allowed_values([MYAPI_BUILDING_ADMIN_CONDO_FIELD => []]);

    myapi_test_db_seed([
      'field_data_' . MYAPI_BUILDING_ADMIN_CONDO_FIELD => [
        [
          'entity_id'   => (string) self::ADMIN_UID,
          'entity_type' => 'user',
          'deleted'     => '0',
          MYAPI_BUILDING_ADMIN_CONDO_FIELD . '_target_id' => (string) self::CONDOMINIUM,
        ],
      ],
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::NEIGHBOUR_UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupante'  => [],
      'field_data_field_ocupantes' => [],
    ]);
  }

  /* -------------------------------------------------------------------------
   * When the filter applies.
   * ---------------------------------------------------------------------- */

  /**
   * THE FILTER APPLIES TO A BUILDING ADMIN WHO IS NOT A FULL ADMINISTRATOR,
   * and to nobody else.
   */
  public function testTheFilterAppliesOnlyToAScopedBuildingAdmin() {
    $this->actAs($this->account(self::ADMIN_UID));
    $this->assertTrue(myapi_building_admin_user_filter_is_active());

    // The same operator, now holding 'administer users': they are doing site
    // administration and must not be narrowed.
    $GLOBALS['myapi_test_permissions']['administer users:' . self::ADMIN_UID] = TRUE;
    $this->assertFalse(myapi_building_admin_user_filter_is_active());

    // Somebody who does not hold the role at all.
    $GLOBALS['myapi_test_permissions'] = [];
    $this->actAs($this->account(self::NEIGHBOUR_UID, FALSE));
    $this->assertFalse(myapi_building_admin_user_filter_is_active());
  }

  /**
   * The anonymous user is never filtered — there is nobody to scope.
   */
  public function testAnonymousIsNeverFiltered() {
    $this->actAs((object) ['uid' => 0, 'roles' => []]);
    $this->assertFalse(myapi_building_admin_user_filter_is_active());

    $this->assertFalse(myapi_building_admin_user_filter_is_active('not an object'));
    $this->assertFalse(myapi_building_admin_user_filter_is_active((object) ['roles' => []]));
  }

  /**
   * An explicit account overrides the current user, which is what lets the
   * caller ask about somebody else.
   */
  public function testAnExplicitAccountOverridesTheCurrentUser() {
    $this->actAs($this->account(self::NEIGHBOUR_UID, FALSE));

    $this->assertTrue(myapi_building_admin_user_filter_is_active($this->account(self::ADMIN_UID)));
  }

  /* -------------------------------------------------------------------------
   * Who a scoped operator may open.
   * ---------------------------------------------------------------------- */

  /**
   * DRUPAL'S OWN GATE COMES FIRST. An account Drupal already refuses is
   * refused whatever the scope says.
   */
  public function testDrupalsOwnGateIsCheckedFirst() {
    $GLOBALS['myapi_test_user_view_access'] = FALSE;
    $this->actAs($this->scopedAdmin());
    $this->seedBuilding();

    $this->assertFalse(myapi_building_admin_user_view_access($this->account(self::ADMIN_UID, FALSE)));
  }

  /**
   * AN UNFILTERED OPERATOR IS NOT NARROWED: with the filter off, whatever
   * Drupal allows is allowed.
   */
  public function testAnUnfilteredOperatorIsNotNarrowed() {
    $this->actAs($this->account(self::NEIGHBOUR_UID, FALSE));

    $this->assertTrue(myapi_building_admin_user_view_access($this->account(self::STRANGER_UID, FALSE)));
  }

  /**
   * A SCOPED OPERATOR SEES THE PEOPLE OF THEIR BUILDING AND THEMSELVES, and
   * nobody else — a stranger's profile is refused even though Drupal would
   * allow it.
   */
  public function testAScopedOperatorSeesTheirBuildingAndThemselves() {
    $this->seedBuilding();
    $this->actAs($this->scopedAdmin());

    $this->assertTrue(myapi_building_admin_user_view_access($this->account(self::NEIGHBOUR_UID, FALSE)), 'a resident of their building');
    $this->assertTrue(myapi_building_admin_user_view_access($this->scopedAdmin()), 'and themselves');
    $this->assertFalse(myapi_building_admin_user_view_access($this->account(self::STRANGER_UID, FALSE)), 'and nobody else');
  }

  /**
   * AN OPERATOR WITH NO BUILDING ASSIGNED STILL SEES THEMSELVES. The scope is
   * empty, so the visible set is exactly one uid — theirs — and they can still
   * open their own profile.
   */
  public function testAnOperatorWithNoBuildingStillSeesThemselves() {
    myapi_test_field_seed_allowed_values([MYAPI_BUILDING_ADMIN_CONDO_FIELD => []]);
    myapi_test_db_seed(['field_data_' . MYAPI_BUILDING_ADMIN_CONDO_FIELD => []]);
    $this->actAs($this->account(self::ADMIN_UID, TRUE, [], []));

    $this->assertSame([self::ADMIN_UID], myapi_building_admin_visible_uids());
    $this->assertTrue(myapi_building_admin_user_view_access($this->account(self::ADMIN_UID, TRUE, [], [])));
    $this->assertFalse(myapi_building_admin_user_view_access($this->account(self::NEIGHBOUR_UID, FALSE)));
  }

  /**
   * The visible set is memoised per uid for the request — it costs two queries
   * and is read by every row of the people list.
   */
  public function testTheVisibleSetIsMemoisedPerRequest() {
    $this->seedBuilding();
    $this->actAs($this->scopedAdmin());

    $first = myapi_building_admin_visible_uids();
    $queries = count(myapi_test_db_queries());
    $second = myapi_building_admin_visible_uids();

    $this->assertSame($first, $second);
    $this->assertCount($queries, myapi_test_db_queries(), 'the second call queries nothing');
  }

  /* -------------------------------------------------------------------------
   * The reservation form of a scoped operator.
   * ---------------------------------------------------------------------- */

  /**
   * An UNFILTERED operator's reservation form raises nothing: the scope checks
   * are for building admins only, and this validator returns on its first line
   * for everybody else.
   */
  public function testAnUnfilteredOperatorsFormRaisesNothing() {
    $this->actAs($this->account(self::NEIGHBOUR_UID, FALSE));

    $node = (object) ['type' => 'reservation'];
    $node->field_requester[LANGUAGE_NONE][0]['target_id'] = self::STRANGER_UID;
    $node->field_unit[LANGUAGE_NONE][0]['target_id'] = 999;
    $form_state = [];

    myapi_building_admin_validate_reservation($node, [], $form_state);

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * A SCOPED OPERATOR MAY BOOK FOR THEIR OWN BUILDING and for a resident they
   * can see — that combination raises nothing.
   */
  public function testAValidReservationOfTheirOwnBuildingRaisesNothing() {
    $this->seedBuilding();
    $this->actAs($this->scopedAdmin());

    $unit = (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101'];
    $unit->field_condominio[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;
    myapi_test_node_seed([self::UNIT => $unit]);

    $node = (object) ['type' => 'reservation'];
    $node->field_requester[LANGUAGE_NONE][0]['target_id'] = self::NEIGHBOUR_UID;
    $node->field_unit[LANGUAGE_NONE][0]['target_id'] = self::UNIT;
    $form_state = [];

    myapi_building_admin_validate_reservation($node, [], $form_state);

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * A REQUESTER OUTSIDE THEIR SCOPE IS REPORTED ON THE REQUESTER FIELD, which
   * is what stops an operator from booking on behalf of another building's
   * resident.
   */
  public function testARequesterOutsideTheScopeIsReported() {
    $this->seedBuilding();
    $this->actAs($this->scopedAdmin());

    $unit = (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101'];
    $unit->field_condominio[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;
    myapi_test_node_seed([self::UNIT => $unit]);

    $node = (object) ['type' => 'reservation'];
    $node->field_requester[LANGUAGE_NONE][0]['target_id'] = self::STRANGER_UID;
    $node->field_unit[LANGUAGE_NONE][0]['target_id'] = self::UNIT;
    $form_state = [];

    myapi_building_admin_validate_reservation($node, [], $form_state);

    $errors = $GLOBALS['myapi_test_form_errors'];
    $this->assertNotSame([], $errors);
    $this->assertArrayHasKey('field_requester', $errors);
  }

  /**
   * A UNIT OF ANOTHER BUILDING IS REPORTED ON THE UNIT FIELD, and a unit that
   * does not exist is treated as a denial rather than as "no unit" — a stray
   * nid must not slip through.
   */
  public function testAForeignOrMissingUnitIsReported() {
    $this->seedBuilding();
    $this->actAs($this->scopedAdmin());

    $foreign = (object) ['nid' => 46, 'type' => 'vivienda', 'status' => 1, 'title' => 'B-201'];
    $foreign->field_condominio[LANGUAGE_NONE][0]['target_id'] = 99;
    myapi_test_node_seed([46 => $foreign]);

    $node = (object) ['type' => 'reservation'];
    $node->field_requester[LANGUAGE_NONE][0]['target_id'] = self::NEIGHBOUR_UID;
    $node->field_unit[LANGUAGE_NONE][0]['target_id'] = 46;
    $form_state = [];
    myapi_building_admin_validate_reservation($node, [], $form_state);
    $this->assertArrayHasKey('field_unit', $GLOBALS['myapi_test_form_errors']);

    $GLOBALS['myapi_test_form_errors'] = [];
    myapi_test_node_seed([]);
    $node->field_unit[LANGUAGE_NONE][0]['target_id'] = 4242;
    $form_state = [];
    myapi_building_admin_validate_reservation($node, [], $form_state);
    $this->assertArrayHasKey('field_unit', $GLOBALS['myapi_test_form_errors'], 'a missing unit is a denial');
  }

  /* -------------------------------------------------------------------------
   * The assignment field's own access.
   * ---------------------------------------------------------------------- */

  /**
   * ONLY SOMEBODY WHO MAY MANAGE ASSIGNMENTS SEES THE FIELD. A building admin
   * must not be able to widen their own scope by editing their profile.
   */
  public function testOnlyAnAssignmentManagerSeesTheField() {
    $this->assertFalse(myapi_building_admin_assignment_field_access($this->account(self::ADMIN_UID)));
    $this->assertFalse(myapi_building_admin_assignment_field_access($this->account(self::NEIGHBOUR_UID, FALSE)));

    $this->assertTrue(
      myapi_building_admin_assignment_field_access($this->account(2, FALSE, ['administrator'])),
      'an administrator may'
    );
    $this->assertTrue(
      myapi_building_admin_assignment_field_access((object) ['uid' => 1, 'roles' => []]),
      'and so may uid 1'
    );
  }

  /**
   * A non-object and an account with no roles array are refused rather than
   * fataling — the field access callback runs on every user form.
   */
  public function testADegradedAccountIsRefused() {
    $this->assertFalse(myapi_building_admin_assignment_field_access(NULL));
    $this->assertFalse(myapi_building_admin_assignment_field_access('admin'));
    $this->assertFalse(myapi_building_admin_assignment_field_access((object) ['uid' => 7]));
  }

  /* -------------------------------------------------------------------------
   * The busy-range comparator.
   * ---------------------------------------------------------------------- */

  /**
   * The comparator orders by day first and by clock time second, which is what
   * keeps a session that crosses midnight in chronological order — sorting by
   * time alone would put 00:30 of the next day before 22:00 of this one.
   */
  public function testTheBusyRangeComparatorOrdersByDayThenTime() {
    $ranges = [
      ['start_date' => '2026-06-16', 'start_time' => '00:30'],
      ['start_date' => '2026-06-15', 'start_time' => '22:00'],
      ['start_date' => '2026-06-15', 'start_time' => '10:00'],
    ];

    usort($ranges, 'myapi_reservation_busy_range_compare');

    $this->assertSame([
      ['start_date' => '2026-06-15', 'start_time' => '10:00'],
      ['start_date' => '2026-06-15', 'start_time' => '22:00'],
      ['start_date' => '2026-06-16', 'start_time' => '00:30'],
    ], $ranges);
  }

  /**
   * Two ranges starting at the same instant compare equal, which is what keeps
   * the sort from reordering them arbitrarily on the two PHP versions this
   * project spans.
   */
  public function testTwoRangesAtTheSameInstantCompareEqual() {
    $a = ['start_date' => '2026-06-15', 'start_time' => '10:00'];
    $b = ['start_date' => '2026-06-15', 'start_time' => '10:00'];

    $this->assertSame(0, myapi_reservation_busy_range_compare($a, $b));
    $this->assertLessThan(0, myapi_reservation_busy_range_compare($a, ['start_date' => '2026-06-15', 'start_time' => '10:01']));
    $this->assertGreaterThan(0, myapi_reservation_busy_range_compare($a, ['start_date' => '2026-06-14', 'start_time' => '23:59']));
  }
}
