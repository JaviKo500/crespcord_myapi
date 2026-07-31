<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.building_admin_user.inc';

/**
 * Unit tests for the PEOPLE half of the building-admin filter (SPEC 51), whose
 * pure logic lives in includes/myapi.building_admin_user.inc.
 *
 * Covers the three decidable pieces of the spec, all of them free of Drupal:
 *   - myapi_building_admin_user_filter_applies()   — who gets filtered;
 *   - myapi_building_admin_user_decision()         — who is visible;
 *   - myapi_building_admin_reservation_errors()    — the node form rule.
 *
 * Deliberately NOT tested here: myapi_menu_alter(), the user_access query
 * alter, myapi_requirements() and the granting of 'access user profiles'. All
 * four need the Drupal container — a booted site, a menu router, a database —
 * which is exactly what tests/unit avoids across this repo. Their verification
 * is the manual acceptance criteria of the spec, and it is written down there
 * rather than skipped silently.
 *
 * Three tests here are guards against a future edit rather than checks of
 * today's behaviour, and they are the reason this file matters most:
 * testDecisionIsAlwaysAllowOrDeny() fails if the rule ever grows a third
 * verdict, testDecisionAlwaysAllowsOneself() fails if an operator loses their
 * own account, and testFilterIsDisabledByAdministerUsers() pins the one
 * exception the spec allows on the "who is looking" side.
 */
class BuildingAdminUserTest extends TestCase {

  /* -------------------------------------------------------------------------
   * Who gets filtered — myapi_building_admin_user_filter_applies().
   * ---------------------------------------------------------------------- */

  /**
   * The role alone, which is the case this whole spec exists for.
   */
  public function testFilterAppliesToTheRoleAlone() {
    $this->assertTrue(myapi_building_admin_user_filter_applies(TRUE, FALSE));
  }

  /**
   * GUARD: 'administer users' switches the filter off.
   *
   * The symmetric counterpart of 'bypass node access' for nodes. Without it an
   * account holding both 'administrator' and this role would see every node of
   * the site but only the people of its condominiums — an asymmetry that reads
   * as a bug in the module. Dropping this exception is a behaviour change and
   * must fail here first.
   */
  public function testFilterIsDisabledByAdministerUsers() {
    $this->assertFalse(myapi_building_admin_user_filter_applies(TRUE, TRUE));
  }

  /**
   * GUARD: nobody without the role is ever filtered — residents, backend
   * users, administrators, the site owner. This feature must cost the rest of
   * the site nothing.
   */
  public function testFilterNeverAppliesWithoutTheRole() {
    $this->assertFalse(myapi_building_admin_user_filter_applies(FALSE, FALSE));
    $this->assertFalse(myapi_building_admin_user_filter_applies(FALSE, TRUE));
  }

  /* -------------------------------------------------------------------------
   * Who is visible — myapi_building_admin_user_decision().
   * ---------------------------------------------------------------------- */

  /**
   * A resident of an assigned condominium is visible.
   */
  public function testDecisionAllowsAUidInTheList() {
    $this->assertSame('allow', myapi_building_admin_user_decision(7, 3, array(3, 7, 9)));
  }

  /**
   * Everybody else is not, with no exception for 'administrator', 'backend'
   * nor another building admin: whoever cannot be traced to an assigned
   * condominium is invisible.
   */
  public function testDecisionDeniesAUidOutsideTheList() {
    $this->assertSame('deny', myapi_building_admin_user_decision(42, 3, array(3, 7, 9)));
  }

  /**
   * GUARD: one's own account is always visible, even when it is not in the
   * list.
   *
   * Without this, an operator with no unit of their own would lose "Mi cuenta"
   * and their own edit form. It opens nothing: seeing one's own profile needs
   * no permission for any other role in Drupal.
   */
  public function testDecisionAlwaysAllowsOneself() {
    $this->assertSame('allow', myapi_building_admin_user_decision(3, 3, array()));
    $this->assertSame('allow', myapi_building_admin_user_decision(3, 3, array(7, 9)));
    $this->assertSame('allow', myapi_building_admin_user_decision('3', 3, array()));
  }

  /**
   * An operator with nothing assigned sees only themselves — the correct rule,
   * not a special case.
   */
  public function testDecisionDeniesEverybodyElseWithAnEmptyList() {
    $this->assertSame('deny', myapi_building_admin_user_decision(7, 3, array()));
  }

  /**
   * Uids arriving as strings from a database read still match. Without the
   * cast on both sides, a strict comparison would deny an operator the
   * residents that are genuinely theirs.
   */
  public function testDecisionMatchesUidsAcrossTypes() {
    $this->assertSame('allow', myapi_building_admin_user_decision('7', 3, array(7)));
    $this->assertSame('allow', myapi_building_admin_user_decision(7, 3, array('7')));
    $this->assertSame('allow', myapi_building_admin_user_decision('7', '3', array('7', '9')));
    $this->assertSame('deny', myapi_building_admin_user_decision('42', '3', array('7', '9')));
  }

  /**
   * GUARD: the verdict is never a third value, in any combination.
   *
   * Both callers turn anything that is not 'allow' into a 403, so a stray
   * return value would be a silent lockout — or, read the other way round, a
   * silent opening if a caller ever compared against 'deny' instead.
   */
  public function testDecisionIsAlwaysAllowOrDeny() {
    foreach (array(0, 3, 7, 42, '7', NULL) as $target) {
      foreach (array(array(), array(7), array(7, 9), array('7')) as $visible) {
        $this->assertContains(
          myapi_building_admin_user_decision($target, 3, $visible),
          array('allow', 'deny'),
          'Target ' . var_export($target, TRUE)
        );
      }
    }
  }

  /* -------------------------------------------------------------------------
   * The reservation form — myapi_building_admin_reservation_errors().
   * ---------------------------------------------------------------------- */

  /**
   * The happy path: a visible requester on a unit of an assigned condominium.
   *
   * Both verdicts of myapi_building_admin_access_decision() that mean "this
   * unit is not refused" are accepted — 'ignore', which is what the wrapper
   * actually passes for a unit of an assigned condominium, and 'allow', the
   * word the spec uses for it.
   */
  public function testReservationOfAVisibleRequesterAndOwnUnitIsAccepted() {
    foreach (array('allow', 'ignore') as $unit_decision) {
      $this->assertSame(
        array(),
        myapi_building_admin_reservation_errors(7, array(3, 7), $unit_decision),
        'Unit decision ' . $unit_decision
      );
    }
  }

  /**
   * A requester the operator cannot even see — only reachable through a
   * hand-crafted POST, since the autocomplete does not offer them.
   */
  public function testReservationOfAForeignRequesterIsRejected() {
    $errors = myapi_building_admin_reservation_errors(42, array(3, 7), 'ignore');

    $this->assertCount(1, $errors);
    $this->assertSame('field_requester', $errors[0]['field']);
  }

  /**
   * An empty requester. uid 0 is the anonymous user and is rejected like an
   * empty field, never treated as a valid pick.
   */
  public function testReservationWithoutARequesterIsRejected() {
    foreach (array(NULL, 0, '', '0') as $requester) {
      $errors = myapi_building_admin_reservation_errors($requester, array(3, 7), 'ignore');

      $this->assertCount(1, $errors, 'Requester ' . var_export($requester, TRUE));
      $this->assertSame('field_requester', $errors[0]['field']);
    }
  }

  /**
   * A unit of another condominium, refused by the SPEC 49 rule.
   */
  public function testReservationOfAForeignUnitIsRejected() {
    $errors = myapi_building_admin_reservation_errors(7, array(3, 7), 'deny');

    $this->assertCount(1, $errors);
    $this->assertSame('field_unit', $errors[0]['field']);
  }

  /**
   * An empty unit, or a submitted unit that does not resolve — the wrapper
   * reports the second as 'deny'. Anything that is not a clean verdict fails
   * closed.
   */
  public function testReservationWithoutAUnitIsRejected() {
    foreach (array(NULL, '', 'whatever') as $unit_decision) {
      $errors = myapi_building_admin_reservation_errors(7, array(3, 7), $unit_decision);

      $this->assertCount(1, $errors, 'Unit decision ' . var_export($unit_decision, TRUE));
      $this->assertSame('field_unit', $errors[0]['field']);
    }
  }

  /**
   * Both fields wrong at once produce both messages, in field order, so the
   * operator fixes the form in one pass instead of two.
   */
  public function testReservationWithBothFieldsWrongReportsBothErrors() {
    $errors = myapi_building_admin_reservation_errors(42, array(3, 7), 'deny');

    $this->assertCount(2, $errors);
    $this->assertSame('field_requester', $errors[0]['field']);
    $this->assertSame('field_unit', $errors[1]['field']);
  }

  /**
   * Every error carries a non-empty message for form_set_error(): an error
   * with no text would block the save with no explanation on screen.
   */
  public function testEveryReservationErrorCarriesAMessage() {
    foreach (myapi_building_admin_reservation_errors(NULL, array(), NULL) as $error) {
      $this->assertArrayHasKey('message', $error);
      $this->assertNotSame('', trim($error['message']));
    }
  }

  /**
   * Uids as strings, the shape a database read gives back, must not turn a
   * legitimate requester into a form error.
   */
  public function testReservationMatchesRequesterUidsAcrossTypes() {
    $this->assertSame(array(), myapi_building_admin_reservation_errors('7', array(3, 7), 'ignore'));
    $this->assertSame(array(), myapi_building_admin_reservation_errors(7, array('3', '7'), 'ignore'));
  }
}
