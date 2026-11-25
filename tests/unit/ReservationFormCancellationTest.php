<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../myapi.module';
require_once __DIR__ . '/../../includes/myapi.time_format.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_rules.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_form.inc';

/**
 * Unit tests for the cancellation fields of the 'reservation' node form
 * (SPEC 137).
 *
 *   - The two pure decisions: when a reason is missing, and who cancelled.
 *   - The form alter, on hand-built form arrays: "Cancelada por" hidden or
 *     locked, the reason flagged as required through #states.
 *   - The validation and the hook_node_submit() write, against nodes seeded
 *     with myapi_test_node_seed().
 *
 * Not tested here, because no unit test can: that Drupal drops the submitted
 * value of an element with '#access' FALSE or '#disabled', and the asterisk
 * misc/states.js draws. That is the manual matrix of the spec.
 */
class ReservationFormCancellationTest extends TestCase {

  protected function setUp(): void {
    myapi_test_node_seed();
    $GLOBALS['myapi_test_form_errors'] = [];
  }

  protected function tearDown(): void {
    myapi_test_node_seed();
    $GLOBALS['myapi_test_form_errors'] = [];
  }

  private function reservation($status, $reason = NULL, $cancelled_by = NULL, $nid = NULL) {
    $node = (object) ['type' => 'reservation'];
    if ($nid !== NULL) {
      $node->nid = $nid;
    }
    $node->field_reservation_status['und'][0]['value'] = $status;
    if ($reason !== NULL) {
      $node->field_cancel_reason['und'][0]['value'] = $reason;
    }
    if ($cancelled_by !== NULL) {
      $node->field_cancelled_by['und'][0]['value'] = $cancelled_by;
    }

    return $node;
  }

  private function form($nid = NULL) {
    $form = [
      'field_reservation_status' => ['#language' => 'und', 'und' => ['#type' => 'select']],
      'field_cancelled_by'       => ['#language' => 'und', 'und' => ['#type' => 'select']],
      'field_cancel_reason'      => [
        '#language' => 'und',
        'und'       => [0 => ['value' => ['#type' => 'textfield', '#description' => 'Opcional.']]],
      ],
    ];
    $form['#node'] = (object) ['type' => 'reservation'];
    if ($nid !== NULL) {
      $form['#node']->nid = $nid;
    }

    return $form;
  }

  /* -------------------------------------------------------------------------
   * The reason — myapi_reservation_form_cancel_reason_missing().
   * ---------------------------------------------------------------------- */

  public static function reasonCases() {
    return [
      'confirmed, no reason'                          => ['confirmed', NULL, NULL, NULL, FALSE],
      'new and cancelled, no reason'                  => ['cancelled', NULL, NULL, NULL, TRUE],
      'cancelling, no reason'                         => ['cancelled', NULL, 'confirmed', NULL, TRUE],
      'cancelling, empty reason'                      => ['cancelled', '', 'confirmed', NULL, TRUE],
      'cancelling, blank reason'                      => ['cancelled', "  \t", 'confirmed', NULL, TRUE],
      'cancelling with a reason'                      => ['cancelled', 'Mantenimiento', 'confirmed', NULL, FALSE],
      'already cancelled without a reason, untouched' => ['cancelled', NULL, 'cancelled', NULL, FALSE],
      'already cancelled, blank stored reason'        => ['cancelled', '', 'cancelled', ' ', FALSE],
      'already cancelled, reason erased'              => ['cancelled', '', 'cancelled', 'Mantenimiento', TRUE],
      'already cancelled, reason edited'              => ['cancelled', 'Otro', 'cancelled', 'Mantenimiento', FALSE],
      'back to confirmed, reason erased'              => ['confirmed', '', 'cancelled', 'Mantenimiento', FALSE],
    ];
  }

  /**
   * @dataProvider reasonCases
   */
  public function testTheReasonIsRequiredToCancel($status, $reason, $stored_status, $stored_reason, $missing) {
    $this->assertSame($missing, myapi_reservation_form_cancel_reason_missing($status, $reason, $stored_status, $stored_reason));
  }

  public function testACancellationWithoutReasonFlagsTheInput() {
    myapi_test_node_seed([7 => $this->reservation('confirmed', NULL, NULL, 7)]);
    $form_state = [];

    myapi_reservation_form_validate_cancel_reason($this->reservation('cancelled', '', NULL, 7), $this->form(7), $form_state);

    $this->assertSame(['field_cancel_reason][und][0][value'], array_keys($GLOBALS['myapi_test_form_errors']));
  }

  public function testACancellationWithItsReasonIsSaved() {
    myapi_test_node_seed([7 => $this->reservation('confirmed', NULL, NULL, 7)]);
    $form_state = [];

    myapi_reservation_form_validate_cancel_reason($this->reservation('cancelled', 'Mantenimiento', NULL, 7), $this->form(7), $form_state);

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  public function testANewReservationSavedAsCancelledNeedsAReason() {
    $form_state = [];

    myapi_reservation_form_validate_cancel_reason($this->reservation('cancelled'), $this->form(), $form_state);

    $this->assertCount(1, $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * A resident may cancel from the app without a reason: an operator editing
   * that reservation afterwards is not asked for one.
   */
  public function testAReservationCancelledWithoutReasonCanStillBeEdited() {
    myapi_test_node_seed([7 => $this->reservation('cancelled', NULL, 'user', 7)]);
    $form_state = [];

    myapi_reservation_form_validate_cancel_reason($this->reservation('cancelled', '', 'user', 7), $this->form(7), $form_state);

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * Through the Drupal-facing function, not only the pure one: a stored
   * reason cannot be erased from a cancelled reservation.
   */
  public function testErasingTheReasonOfACancelledReservationIsRefused() {
    myapi_test_node_seed([7 => $this->reservation('cancelled', 'Lluvia', 'admin', 7)]);
    $form_state = [];

    myapi_reservation_form_validate_cancel_reason($this->reservation('cancelled', '', 'admin', 7), $this->form(7), $form_state);

    $this->assertSame(['field_cancel_reason][und][0][value'], array_keys($GLOBALS['myapi_test_form_errors']));
  }

  public function testAFormWithoutTheReasonLanguageStillFlagsTheInput() {
    $form_state = [];

    myapi_reservation_form_validate_cancel_reason($this->reservation('cancelled'), [], $form_state);

    $this->assertSame(['field_cancel_reason][und][0][value'], array_keys($GLOBALS['myapi_test_form_errors']));
  }

  /**
   * A reservation deleted while its form was open reads as new: nothing is
   * stored, so cancelling it needs a reason and is the operator's.
   */
  public function testAReservationThatNoLongerLoadsReadsAsNew() {
    $gone = $this->reservation('cancelled', NULL, NULL, 99);

    $this->assertSame(
      ['status' => NULL, 'reason' => NULL, 'cancelled_by' => NULL],
      myapi_reservation_form_stored_cancellation($gone)
    );
    $this->assertSame(
      ['status' => NULL, 'reason' => NULL, 'cancelled_by' => NULL],
      myapi_reservation_form_stored_cancellation(NULL)
    );

    myapi_reservation_form_apply_cancelled_by($gone);
    $this->assertSame([0 => ['value' => 'admin']], $gone->field_cancelled_by['und']);
  }

  /**
   * The hook is exercised for real: the whole 'reservation' branch of
   * myapi_node_validate() runs over a cancellation with no reason. The other
   * validations have nothing to say about a pseudo-node this bare — a
   * cancelled reservation skips the business rules.
   */
  public function testTheValidationHookRunsTheReasonRule() {
    myapi_test_node_seed([7 => $this->reservation('confirmed', NULL, NULL, 7)]);
    $form_state = ['values' => []];

    myapi_node_validate($this->reservation('cancelled', '', NULL, 7), $this->form(7), $form_state);

    $this->assertArrayHasKey('field_cancel_reason][und][0][value', $GLOBALS['myapi_test_form_errors']);
  }

  /* -------------------------------------------------------------------------
   * Who cancelled — myapi_reservation_form_cancelled_by().
   * ---------------------------------------------------------------------- */

  public static function cancelledByCases() {
    return [
      'new and confirmed'                  => ['confirmed', NULL, NULL, NULL],
      'new and cancelled'                  => ['cancelled', NULL, NULL, 'admin'],
      'confirmed to cancelled'             => ['cancelled', 'confirmed', NULL, 'admin'],
      'stale mark on a confirmed one'      => ['cancelled', 'confirmed', 'user', 'admin'],
      'cancelled by its resident, edited'  => ['cancelled', 'cancelled', 'user', 'user'],
      'cancelled by an operator, edited'   => ['cancelled', 'cancelled', 'admin', 'admin'],
      'cancelled with no mark, edited'     => ['cancelled', 'cancelled', NULL, NULL],
      'cancelled with empty mark, edited'  => ['cancelled', 'cancelled', '', NULL],
      'back to confirmed'                  => ['confirmed', 'cancelled', 'user', NULL],
      'confirmed, untouched'               => ['confirmed', 'confirmed', NULL, NULL],
    ];
  }

  /**
   * @dataProvider cancelledByCases
   */
  public function testWhoCancelledFollowsTheStatus($status, $stored_status, $stored_by, $expected) {
    $this->assertSame($expected, myapi_reservation_form_cancelled_by($status, $stored_status, $stored_by));
  }

  public function testCancellingFromTheFormWritesAdmin() {
    myapi_test_node_seed([7 => $this->reservation('confirmed', NULL, NULL, 7)]);
    $node = $this->reservation('cancelled', 'Mantenimiento', NULL, 7);

    myapi_reservation_form_apply_cancelled_by($node);

    $this->assertSame([0 => ['value' => 'admin']], $node->field_cancelled_by['und']);
  }

  /**
   * THE REGRESSION. On node/%/edit the node Drupal hands to hook_node_submit()
   * is the very object node_load() keeps in its static cache, already holding
   * the submitted values. Read through node_load(), the stored status would
   * be the submitted one and the cancellation would get no canceller.
   */
  public function testTheSubmittedNodeBeingTheCachedOneStillWritesAdmin() {
    $cached = $this->reservation('confirmed', NULL, NULL, 7);
    myapi_test_node_seed([7 => $cached]);

    $cached->field_reservation_status['und'][0]['value'] = 'cancelled';
    $cached->field_cancel_reason['und'][0]['value'] = 'Mantenimiento';
    $cached->field_cancelled_by['und'] = [];
    $this->assertSame($cached, node_load(7));

    $form_state = [];
    myapi_node_submit($cached, [], $form_state);

    $this->assertSame([0 => ['value' => 'admin']], $cached->field_cancelled_by['und']);
  }

  /**
   * Same sharing, the other way: back to 'confirmed' on the cached object.
   */
  public function testTheCachedNodeBroughtBackLosesItsCanceller() {
    $cached = $this->reservation('cancelled', 'Lluvia', 'user', 7);
    myapi_test_node_seed([7 => $cached]);
    $cached->field_reservation_status['und'][0]['value'] = 'confirmed';

    myapi_reservation_form_apply_cancelled_by($cached);

    $this->assertSame([], $cached->field_cancelled_by['und']);
  }

  /**
   * After "Vista previa" the form is rebuilt around the node that already
   * holds the submitted values: what is shown still follows the database.
   */
  public function testTheFormFollowsTheDatabaseAndNotTheNodeItCarries() {
    $cached = $this->reservation('confirmed', NULL, NULL, 7);
    myapi_test_node_seed([7 => $cached]);
    $cached->field_reservation_status['und'][0]['value'] = 'cancelled';
    $cached->field_cancelled_by['und'][0]['value'] = 'admin';

    $form = $this->form(7);
    $form['#node'] = $cached;
    myapi_reservation_form_alter_cancellation($form);

    $this->assertFalse($form['field_cancelled_by']['#access']);
  }

  /**
   * Whatever arrives in the submitted node is overwritten: the stored value
   * decides, not the request.
   */
  public function testASubmittedCancellerIsIgnored() {
    myapi_test_node_seed([7 => $this->reservation('cancelled', 'Lluvia', 'user', 7)]);
    $node = $this->reservation('cancelled', 'Lluvia', 'admin', 7);

    myapi_reservation_form_apply_cancelled_by($node);

    $this->assertSame([0 => ['value' => 'user']], $node->field_cancelled_by['und']);
  }

  public function testBringingAReservationBackEmptiesWhoCancelledIt() {
    myapi_test_node_seed([7 => $this->reservation('cancelled', 'Lluvia', 'user', 7)]);
    $node = $this->reservation('confirmed', 'Lluvia', 'user', 7);

    myapi_reservation_form_apply_cancelled_by($node);

    $this->assertSame([], $node->field_cancelled_by['und']);
  }

  public function testTheSubmitHookOnlyTouchesReservations() {
    $form_state = [];

    $reservation = $this->reservation('cancelled', 'Mantenimiento');
    myapi_node_submit($reservation, [], $form_state);
    $this->assertSame([0 => ['value' => 'admin']], $reservation->field_cancelled_by['und']);

    $other = (object) ['type' => 'area'];
    myapi_node_submit($other, [], $form_state);
    $this->assertFalse(isset($other->field_cancelled_by));
  }

  /* -------------------------------------------------------------------------
   * The form — myapi_reservation_form_alter_cancellation().
   * ---------------------------------------------------------------------- */

  public function testANewReservationHidesWhoCancelled() {
    $form = $this->form();
    myapi_reservation_form_alter_cancellation($form);

    $this->assertFalse($form['field_cancelled_by']['#access']);
    $this->assertArrayNotHasKey('#disabled', $form['field_cancelled_by']);
  }

  public function testAConfirmedReservationHidesWhoCancelled() {
    // A stale mark on a confirmed reservation is not shown either.
    myapi_test_node_seed([7 => $this->reservation('confirmed', NULL, 'user', 7)]);
    $form = $this->form(7);
    myapi_reservation_form_alter_cancellation($form);

    $this->assertFalse($form['field_cancelled_by']['#access']);
  }

  public function testAStoredCancellationShowsWhoCancelledLocked() {
    myapi_test_node_seed([7 => $this->reservation('cancelled', 'Lluvia', 'admin', 7)]);
    $form = $this->form(7);
    myapi_reservation_form_alter_cancellation($form);

    $this->assertTrue($form['field_cancelled_by']['#disabled']);
    $this->assertArrayNotHasKey('#access', $form['field_cancelled_by']);
  }

  public function testACancellationWithNoStoredCancellerStaysHidden() {
    myapi_test_node_seed([7 => $this->reservation('cancelled', NULL, NULL, 7)]);
    $form = $this->form(7);
    myapi_reservation_form_alter_cancellation($form);

    $this->assertFalse($form['field_cancelled_by']['#access']);
  }

  public function testTheReasonIsMarkedRequiredWhileTheStatusIsCancelled() {
    $form = $this->form();
    myapi_reservation_form_alter_cancellation($form);

    $reason = $form['field_cancel_reason']['und'][0]['value'];
    $this->assertSame(
      [':input[name="field_reservation_status[und]"]' => ['value' => 'cancelled']],
      $reason['#states']['required']
    );
    $this->assertStringNotContainsString('Opcional', $reason['#description']);
    // Never '#required': a confirmed reservation is saved without a reason.
    $this->assertArrayNotHasKey('#required', $reason);
  }

  public function testAFormWithoutWhoCancelledStillShapesTheReason() {
    $form = $this->form();
    unset($form['field_cancelled_by']);
    myapi_reservation_form_alter_cancellation($form);

    $this->assertArrayNotHasKey('field_cancelled_by', $form);
    $this->assertArrayHasKey('#states', $form['field_cancel_reason']['und'][0]['value']);
  }

  /**
   * Without the status select there is nothing for #states to follow: the
   * help text is still corrected, and the rule on save does not depend on it.
   */
  public function testWithoutTheStatusSelectTheReasonGetsNoAsterisk() {
    $form = $this->form();
    unset($form['field_reservation_status']['#language']);
    myapi_reservation_form_alter_cancellation($form);

    $reason = $form['field_cancel_reason']['und'][0]['value'];
    $this->assertArrayNotHasKey('#states', $reason);
    $this->assertStringNotContainsString('Opcional', $reason['#description']);
  }

  public function testAReasonWidgetThatIsNotATextFieldIsLeftAlone() {
    $form = $this->form();
    $form['field_cancel_reason']['und'][0]['value']['#type'] = 'textarea';
    $before = $form['field_cancel_reason'];
    myapi_reservation_form_alter_cancellation($form);

    $this->assertSame($before, $form['field_cancel_reason']);
    $this->assertFalse($form['field_cancelled_by']['#access']);
  }

  /**
   * The selectors are all or nothing; the cancellation fields do not depend
   * on them.
   */
  public function testTheFormAlterShapesTheCancellationWithoutTheSelectors() {
    $form = $this->form();
    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertFalse($form['field_cancelled_by']['#access']);
    $this->assertArrayNotHasKey('#attached', $form);
  }

}
