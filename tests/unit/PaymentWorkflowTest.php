<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.payment_workflow.inc';

/**
 * Unit tests for the payment verification workflow (SPECS 22, 27, 30 and 80,
 * covered by SPEC 121).
 *
 * THE ONE PLACE IN THIS MODULE WHERE A BUG COSTS MONEY. When an operator
 * verifies a payment, this file discounts the amount from the resident's
 * balance, adds it to the condominium's cash, forces the payment to
 * "Completado" and cancels the pending penalty tasks. Every one of those is a
 * write, three of them are arithmetic, and none of them is visible in an HTTP
 * response — the endpoint that triggers it is the Drupal node form.
 *
 * It replaced a legacy Rules component, which is why the transition detectors
 * are so specific: the same node save can arrive from the app, from the node
 * form, or from Rules' own auto-save, and only ONE of those shapes may move a
 * balance. A detector that fired once too often would double-discount a
 * resident; one that fired once too rarely would leave a verified payment
 * without effect.
 *
 * WHAT RUNS HERE FOR REAL: the detectors, the preconditions, the arithmetic,
 * the objects handed to node_save(), the DELETEs issued against
 * rules_scheduler, the notification fan-out and the params of the backend
 * email. WHAT DOES NOT: that node_save() persists, that Rules exists at all,
 * and that the mail is delivered — the queue is a recorder.
 */
class PaymentWorkflowTest extends TestCase {

  const PAYMENT = 501;
  const UNIT = 45;
  const CONDOMINIUM = 12;
  const UID = 3;
  const ADMIN_UID = 1;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    myapi_test_node_seed();
    myapi_test_write_reset();
    myapi_test_queue_reset();
    myapi_test_field_seed_allowed_values();
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_profile_fields'] = [];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    $GLOBALS['myapi_test_users'] = [];
    unset($GLOBALS['myapi_test_profile_fields']);
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A 'pagos' node in a given state.
   */
  private function payment($status, array $spec = []) {
    $spec += [
      'nid'       => self::PAYMENT,
      'uid'       => self::UID,
      'unit'      => self::UNIT,
      'amount'    => '120.50',
      'reference' => 'REF-001',
      'method'    => 'transferencia',
      'date'      => '2026-06-15T00:00:00',
      'detail'    => NULL,
      'created'   => 1780000000,
    ];

    $node = (object) [
      'nid'     => $spec['nid'],
      'uid'     => $spec['uid'],
      'type'    => 'pagos',
      'status'  => 1,
      'created' => $spec['created'],
      'title'   => 'Pago ' . $spec['reference'],
    ];

    if ($status !== NULL) {
      $node->field_estado_pago[LANGUAGE_NONE][0]['value'] = $status;
    }
    if ($spec['unit'] !== NULL) {
      $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] = $spec['unit'];
    }
    if ($spec['amount'] !== NULL) {
      $node->field_valor[LANGUAGE_NONE][0]['value'] = $spec['amount'];
    }
    if ($spec['reference'] !== NULL) {
      $node->field_referencia[LANGUAGE_NONE][0]['value'] = $spec['reference'];
    }
    $node->field_forma_de_pago[LANGUAGE_NONE][0]['value'] = $spec['method'];
    $node->field_fecha_de_pago[LANGUAGE_NONE][0]['value'] = $spec['date'];
    if ($spec['detail'] !== NULL) {
      $node->field_detalle[LANGUAGE_NONE][0]['value'] = $spec['detail'];
    }

    return $node;
  }

  /**
   * The same node with an ->original in a given previous state, which is what
   * an UPDATE looks like to a hook.
   */
  private function update($previous, $incoming, array $spec = []) {
    $node = $this->payment($incoming, $spec);
    $node->original = $this->payment($previous, $spec);

    return $node;
  }

  /**
   * The unit and its condominium, with their balances.
   */
  private function seedBuilding($unit_balance = '500.00', $cash = '1000.00', array $overrides = []) {
    $unit = (object) [
      'nid'    => self::UNIT,
      'type'   => 'vivienda',
      'status' => 1,
      'title'  => 'A-101',
    ];
    $unit->field_condominio[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;
    if ($unit_balance !== NULL) {
      $unit->field_saldo_actual[LANGUAGE_NONE][0]['value'] = $unit_balance;
    }

    $condominium = (object) [
      'nid'    => self::CONDOMINIUM,
      'type'   => 'condominio',
      'status' => 1,
      'title'  => 'Torre Andalucía',
    ];
    if ($cash !== NULL) {
      $condominium->field_saldo_caja[LANGUAGE_NONE][0]['value'] = $cash;
    }

    myapi_test_node_seed($overrides + [
      self::UNIT        => $unit,
      self::CONDOMINIUM => $condominium,
    ]);
    myapi_test_write_reset();
    $GLOBALS['myapi_test_db_writes'] = [];
    myapi_test_queue_reset();
  }

  /**
   * The saved node of a given type, or NULL.
   */
  private function savedNode($type) {
    foreach (myapi_test_node_saves() as $node) {
      if ($node->type === $type) {
        return $node;
      }
    }

    return NULL;
  }

  /**
   * The rows inserted into myapi_notifications by the fan-out.
   */
  private function insertedNotifications() {
    $writes = myapi_test_db_writes('myapi_notifications');

    return $writes ? $writes[0]['rows'] : [];
  }

  /* -------------------------------------------------------------------------
   * myapi_payment_field_value().
   * ---------------------------------------------------------------------- */

  /**
   * Reads delta 0 of the default language, or NULL — the accessor every other
   * function of this file is built on.
   */
  public function testFieldValueReadsDeltaZeroOrNull() {
    $node = $this->payment(MYAPI_PAYMENT_STATUS_PENDING);

    $this->assertSame(MYAPI_PAYMENT_STATUS_PENDING, myapi_payment_field_value($node, 'field_estado_pago'));
    $this->assertSame('120.50', myapi_payment_field_value($node, 'field_valor'));
    $this->assertNull(myapi_payment_field_value($node, 'field_detalle'));
    $this->assertNull(myapi_payment_field_value($node, 'field_inexistente'));
  }

  /* -------------------------------------------------------------------------
   * The three transition detectors.
   * ---------------------------------------------------------------------- */

  /**
   * The verification transition is EXACTLY "Pendiente de verificar" ->
   * "Nuevo", and nothing else.
   */
  public function testTheVerificationTransitionIsExactlyOnePair() {
    $this->assertTrue(myapi_payment_is_verification_transition(
      $this->update(MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_TRIGGER)
    ));

    $wrong = [
      'pending to completed'   => [MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_COMPLETED],
      'pending to cancelled'   => [MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_CANCELLED],
      'pending to pending'     => [MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_PENDING],
      'completed to trigger'   => [MYAPI_PAYMENT_STATUS_COMPLETED, MYAPI_PAYMENT_STATUS_TRIGGER],
      'trigger to trigger'     => [MYAPI_PAYMENT_STATUS_TRIGGER, MYAPI_PAYMENT_STATUS_TRIGGER],
      'nothing to trigger'     => [NULL, MYAPI_PAYMENT_STATUS_TRIGGER],
      'pending to nothing'     => [MYAPI_PAYMENT_STATUS_PENDING, NULL],
    ];

    foreach ($wrong as $name => $pair) {
      $this->assertFalse(
        myapi_payment_is_verification_transition($this->update($pair[0], $pair[1])),
        $name
      );
    }
  }

  /**
   * AN INSERT NEVER FIRES IT. A node with no ->original is a creation, and a
   * payment created straight in "Nuevo" must not be verified by this path —
   * that is the legacy Rule's job, detected separately.
   */
  public function testAnInsertNeverFiresTheVerification() {
    $insert = $this->payment(MYAPI_PAYMENT_STATUS_TRIGGER);

    $this->assertFalse(myapi_payment_is_verification_transition($insert));
    $this->assertFalse(myapi_payment_is_rule_completion($insert));
    $this->assertFalse(myapi_payment_is_cancellation_transition($insert));
  }

  /**
   * The Rules completion is exactly "Nuevo" -> "Completado" — the second save
   * Rules' auto-save performs, which this module only OBSERVES.
   */
  public function testTheRuleCompletionIsExactlyItsOwnPair() {
    $this->assertTrue(myapi_payment_is_rule_completion(
      $this->update(MYAPI_PAYMENT_STATUS_TRIGGER, MYAPI_PAYMENT_STATUS_COMPLETED)
    ));

    foreach ([
      'pending to completed'  => [MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_COMPLETED],
      'trigger to trigger'    => [MYAPI_PAYMENT_STATUS_TRIGGER, MYAPI_PAYMENT_STATUS_TRIGGER],
      'completed to completed' => [MYAPI_PAYMENT_STATUS_COMPLETED, MYAPI_PAYMENT_STATUS_COMPLETED],
    ] as $name => $pair) {
      $this->assertFalse(myapi_payment_is_rule_completion($this->update($pair[0], $pair[1])), $name);
    }
  }

  /**
   * The cancellation fires on ANY previous state except an already-cancelled
   * one: the guard is against re-firing, not against a particular origin.
   */
  public function testTheCancellationFiresFromEveryStateButCancelled() {
    foreach ([MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_COMPLETED, MYAPI_PAYMENT_STATUS_TRIGGER, 'Cualquiera', NULL] as $previous) {
      $this->assertTrue(
        myapi_payment_is_cancellation_transition($this->update($previous, MYAPI_PAYMENT_STATUS_CANCELLED)),
        json_encode($previous)
      );
    }

    $this->assertFalse(myapi_payment_is_cancellation_transition(
      $this->update(MYAPI_PAYMENT_STATUS_CANCELLED, MYAPI_PAYMENT_STATUS_CANCELLED)
    ), 'already cancelled');

    $this->assertFalse(myapi_payment_is_cancellation_transition(
      $this->update(MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_COMPLETED)
    ), 'not a cancellation at all');
  }

  /**
   * THE OPT-OUT WINS OVER EVERYTHING. The endpoint sets the flag before its
   * own save, so a resident cancelling their own payment is never pushed a
   * notification about their own action.
   */
  public function testTheOptOutFlagSuppressesTheCancellationTransition() {
    $node = $this->update(MYAPI_PAYMENT_STATUS_PENDING, MYAPI_PAYMENT_STATUS_CANCELLED);
    $this->assertTrue(myapi_payment_is_cancellation_transition($node));

    $node->myapi_skip_cancel_notification = TRUE;
    $this->assertFalse(myapi_payment_is_cancellation_transition($node));
  }

  /* -------------------------------------------------------------------------
   * myapi_payment_apply_verification(): the arithmetic.
   * ---------------------------------------------------------------------- */

  /**
   * The happy path: the unit balance goes down by the amount, the cash goes
   * up by it, both are saved as new revisions, and the payment is forced to
   * "Completado" on the object being saved.
   */
  public function testTheVerificationMovesBothBalancesAndForcesTheState() {
    $this->seedBuilding('500.00', '1000.00');
    $node = $this->payment(MYAPI_PAYMENT_STATUS_TRIGGER, ['amount' => '120.50']);

    myapi_payment_apply_verification($node);

    $unit = $this->savedNode('vivienda');
    $condominium = $this->savedNode('condominio');

    $this->assertSame(379.5, $unit->field_saldo_actual[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1120.5, $condominium->field_saldo_caja[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $unit->revision, 'the unit is saved as a new revision');
    $this->assertSame(1, $condominium->revision);
    $this->assertSame(MYAPI_PAYMENT_STATUS_COMPLETED, $node->field_estado_pago[LANGUAGE_NONE][0]['value']);
  }

  /**
   * THE PAYMENT ITSELF IS NEVER SAVED HERE. The state is written on the
   * in-progress object so the running node_save() persists it — a save of the
   * payment inside its own presave would recurse.
   */
  public function testThePaymentItselfIsNotSaved() {
    $this->seedBuilding();
    $node = $this->payment(MYAPI_PAYMENT_STATUS_TRIGGER);

    myapi_payment_apply_verification($node);

    $this->assertNull($this->savedNode('pagos'));
    $this->assertCount(2, myapi_test_node_saves(), 'the unit and the condominium, and nothing else');
  }

  /**
   * A NULL balance is treated as zero on both sides, so a first payment
   * against a unit with no recorded balance leaves it negative rather than
   * failing.
   */
  public function testANullBalanceIsTreatedAsZero() {
    $this->seedBuilding(NULL, NULL);
    $node = $this->payment(MYAPI_PAYMENT_STATUS_TRIGGER, ['amount' => '100.00']);

    myapi_payment_apply_verification($node);

    $this->assertSame(-100.0, $this->savedNode('vivienda')->field_saldo_actual[LANGUAGE_NONE][0]['value']);
    $this->assertSame(100.0, $this->savedNode('condominio')->field_saldo_caja[LANGUAGE_NONE][0]['value']);
  }

  /**
   * A negative balance keeps going down: the workflow does not clamp, because
   * a credit in the resident's favour is a legitimate state.
   */
  public function testANegativeBalanceKeepsGoingDown() {
    $this->seedBuilding('-50.00', '0.00');
    $node = $this->payment(MYAPI_PAYMENT_STATUS_TRIGGER, ['amount' => '25.00']);

    myapi_payment_apply_verification($node);

    $this->assertSame(-75.0, $this->savedNode('vivienda')->field_saldo_actual[LANGUAGE_NONE][0]['value']);
  }

  /**
   * EVERY PRECONDITION ABORTS WITH NO EFFECT AT ALL: no balance moves, no node
   * is saved, and the payment keeps its incoming state (it is NOT forced to
   * "Completado", which is what would otherwise mark an unapplied payment as
   * done).
   */
  public function testEveryFailedPreconditionLeavesEverythingUntouched() {
    $cases = [
      'no amount'          => ['payment' => ['amount' => NULL], 'building' => []],
      'zero amount'        => ['payment' => ['amount' => '0.00'], 'building' => []],
      'negative amount'    => ['payment' => ['amount' => '-10.00'], 'building' => []],
      'non numeric amount' => ['payment' => ['amount' => 'mucho'], 'building' => []],
      'no unit reference'  => ['payment' => ['unit' => NULL], 'building' => []],
    ];

    foreach ($cases as $name => $case) {
      $this->seedBuilding();
      $node = $this->payment(MYAPI_PAYMENT_STATUS_TRIGGER, $case['payment']);

      myapi_payment_apply_verification($node);

      $this->assertSame([], myapi_test_node_saves(), $name);
      $this->assertSame(
        MYAPI_PAYMENT_STATUS_TRIGGER,
        $node->field_estado_pago[LANGUAGE_NONE][0]['value'],
        $name . ': the state is not forced'
      );
      $this->assertSame([], myapi_test_db_writes(), $name . ': no scheduled task is cancelled');
    }
  }

  /**
   * The unit must exist, be a 'vivienda' and be published; the condominium must
   * exist and be a 'condominio'. Each broken link aborts with no effect.
   */
  public function testTheUnitAndCondominiumMustBothResolve() {
    $unit = (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101'];
    $unit->field_condominio[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;

    $broken = [
      'missing unit'        => [],
      'unit of another type' => [self::UNIT => (object) ['nid' => self::UNIT, 'type' => 'recibo', 'status' => 1]],
      'unpublished unit'    => [self::UNIT => (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 0]],
      'unit with no condominium' => [self::UNIT => (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1]],
      'missing condominium' => [self::UNIT => $unit],
      'condominium of another type' => [
        self::UNIT => $unit,
        self::CONDOMINIUM => (object) ['nid' => self::CONDOMINIUM, 'type' => 'vivienda', 'status' => 1],
      ],
    ];

    foreach ($broken as $name => $nodes) {
      myapi_test_node_seed($nodes);
      myapi_test_write_reset();
      $GLOBALS['myapi_test_db_writes'] = [];

      $node = $this->payment(MYAPI_PAYMENT_STATUS_TRIGGER);
      myapi_payment_apply_verification($node);

      $this->assertSame([], myapi_test_node_saves(), $name);
      $this->assertSame(MYAPI_PAYMENT_STATUS_TRIGGER, $node->field_estado_pago[LANGUAGE_NONE][0]['value'], $name);
    }
  }

  /* -------------------------------------------------------------------------
   * The scheduled tasks.
   * ---------------------------------------------------------------------- */

  /**
   * The four legacy Rules tasks of the unit are deleted, each by its own
   * (config, identifier) pair — the identifier carries the unit nid, so a
   * wrong one would cancel a NEIGHBOUR's penalty.
   */
  public function testTheFourScheduledTasksOfTheUnitAreCancelled() {
    myapi_test_db_seed(['rules_scheduler' => []]);
    myapi_payment_cancel_scheduled_tasks(self::UNIT);

    $writes = myapi_test_db_writes('rules_scheduler');
    $this->assertCount(4, $writes);

    $pairs = [];
    foreach ($writes as $write) {
      $values = array_column($write['conditions'], 'value', 'field');
      $pairs[$values['config']] = $values['identifier'];
      $this->assertSame('db_delete', $write['call']);
    }

    $this->assertSame([
      'rules_recordatorio_pago'              => 'recordatorio ' . self::UNIT,
      'rules_recalcular_con_penalizacion'    => 'penalizacion 10 ' . self::UNIT,
      'rules_recalcular_con_penalizacion_15' => 'penalizacion 15 ' . self::UNIT,
      'rules_recalcular_con_penalizacion_31' => 'penalizacion 31 ' . self::UNIT,
    ], $pairs);
  }

  /**
   * The delete only removes the rows of THIS unit, and is idempotent: a second
   * run removes nothing and raises nothing.
   */
  public function testTheCancellationIsScopedToTheUnitAndIdempotent() {
    myapi_test_db_seed(['rules_scheduler' => [
      ['tid' => '1', 'config' => 'rules_recordatorio_pago', 'identifier' => 'recordatorio ' . self::UNIT],
      ['tid' => '2', 'config' => 'rules_recordatorio_pago', 'identifier' => 'recordatorio 77'],
    ]]);

    myapi_payment_cancel_scheduled_tasks(self::UNIT);

    $remaining = array_column($GLOBALS['myapi_test_db']['rules_scheduler'], 'identifier');
    $this->assertSame(['recordatorio 77'], $remaining);

    $GLOBALS['myapi_test_db_writes'] = [];
    myapi_payment_cancel_scheduled_tasks(self::UNIT);
    $this->assertSame(['recordatorio 77'], array_column($GLOBALS['myapi_test_db']['rules_scheduler'], 'identifier'));
  }

  /**
   * A successful verification cancels the tasks as its last balance step.
   */
  public function testTheVerificationCancelsTheScheduledTasks() {
    $this->seedBuilding();
    myapi_test_db_seed(['rules_scheduler' => []]);

    myapi_payment_apply_verification($this->payment(MYAPI_PAYMENT_STATUS_TRIGGER));

    $this->assertCount(4, myapi_test_db_writes('rules_scheduler'));
  }

  /* -------------------------------------------------------------------------
   * The "payment approved" notification.
   * ---------------------------------------------------------------------- */

  /**
   * The approval notice reaches the payment's author with the documented type,
   * deep link and context.
   */
  public function testTheApprovalNoticeReachesTheAuthor() {
    $this->seedBuilding();
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'roles' => []];
    myapi_test_db_seed(['users' => [['uid' => (string) self::UID, 'status' => '1', 'name' => 'pcordero']]]);

    myapi_payment_notify_approved($this->payment(MYAPI_PAYMENT_STATUS_COMPLETED));

    $rows = $this->insertedNotifications();
    $this->assertCount(1, $rows);
    $this->assertSame(self::UID, $rows[0]['uid']);
    $this->assertSame(MYAPI_NOTIFICATION_SOURCE_PAYMENT, $rows[0]['source_type']);
    $this->assertSame(MYAPI_NOTIFICATION_TYPE_PAYMENT_APPROVED, $rows[0]['type']);
    $this->assertSame(MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT, $rows[0]['deep_link_target']);
    $this->assertSame(self::PAYMENT, $rows[0]['deep_link_id']);
    $this->assertSame(self::UNIT, $rows[0]['unit_id']);
    $this->assertSame(self::CONDOMINIUM, $rows[0]['condominium_id'], 'resolved through the unit');
  }

  /**
   * The title and the body carry the reference and the amount, formatted to
   * two decimals — this is the text the resident reads on the banner.
   */
  public function testTheApprovalTextCarriesTheReferenceAndTheAmount() {
    $this->seedBuilding();
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'roles' => []];
    myapi_test_db_seed(['users' => [['uid' => (string) self::UID, 'status' => '1', 'name' => 'pcordero']]]);

    myapi_payment_notify_approved($this->payment(MYAPI_PAYMENT_STATUS_COMPLETED, [
      'reference' => 'REF-777', 'amount' => '1234.5',
    ]));

    $row = $this->insertedNotifications()[0];
    $this->assertSame('Pago aprobado — Ref. REF-777', $row['title']);
    $this->assertStringContainsString('1,234.50', $row['body']);
    $this->assertStringContainsString('REF-777', $row['body']);
  }

  /**
   * A payment missing its reference, amount or unit still notifies: the notice
   * degrades to an empty reference, "0.00" and NULL context rather than being
   * lost.
   */
  public function testAnIncompletePaymentStillNotifies() {
    $this->seedBuilding();
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'roles' => []];
    myapi_test_db_seed(['users' => [['uid' => (string) self::UID, 'status' => '1', 'name' => 'pcordero']]]);

    myapi_payment_notify_approved($this->payment(MYAPI_PAYMENT_STATUS_COMPLETED, [
      'reference' => NULL, 'amount' => NULL, 'unit' => NULL,
    ]));

    $row = $this->insertedNotifications()[0];
    $this->assertSame('Pago aprobado — Ref. ', $row['title']);
    $this->assertStringContainsString('0.00', $row['body']);
    $this->assertNull($row['unit_id']);
    $this->assertNull($row['condominium_id']);
  }

  /* -------------------------------------------------------------------------
   * The "payment cancelled" notification.
   * ---------------------------------------------------------------------- */

  /**
   * The cancellation notice carries its own type and text.
   */
  public function testTheCancellationNoticeHasItsOwnTypeAndText() {
    $this->seedBuilding();
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'roles' => []];
    myapi_test_db_seed(['users' => [['uid' => (string) self::UID, 'status' => '1', 'name' => 'pcordero']]]);

    myapi_payment_notify_cancelled($this->payment(MYAPI_PAYMENT_STATUS_CANCELLED, ['amount' => '80.00']));

    $row = $this->insertedNotifications()[0];
    $this->assertSame(MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED, $row['type']);
    $this->assertSame('Pago anulado — Ref. REF-001', $row['title']);
    $this->assertStringContainsString('80.00 ha sido anulado', $row['body']);
    $this->assertStringNotContainsString('Motivo:', $row['body']);
  }

  /**
   * A stored field_detalle becomes the "Motivo:" line, trimmed; a blank one
   * does not add the line at all.
   */
  public function testTheReasonBecomesTheMotivoLineOnlyWhenItHasContent() {
    foreach ([
      'con motivo'  => ['detail' => '  Transferencia no acreditada  ', 'expected' => 'Motivo: Transferencia no acreditada'],
      'vacío'       => ['detail' => '   ', 'expected' => NULL],
      'ausente'     => ['detail' => NULL, 'expected' => NULL],
    ] as $name => $case) {
      $this->seedBuilding();
      $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'roles' => []];
      myapi_test_db_seed(['users' => [['uid' => (string) self::UID, 'status' => '1', 'name' => 'pcordero']]]);

      myapi_payment_notify_cancelled($this->payment(MYAPI_PAYMENT_STATUS_CANCELLED, ['detail' => $case['detail']]));

      $body = $this->insertedNotifications()[0]['body'];
      if ($case['expected'] === NULL) {
        $this->assertStringNotContainsString('Motivo:', $body, $name);
      }
      else {
        $this->assertStringContainsString($case['expected'], $body, $name);
      }
      $this->assertStringContainsString('Referencia: REF-001', $body, $name);
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_payment_notify_recipients(): who gets told.
   * ---------------------------------------------------------------------- */

  /**
   * Normally the author is the recipient, because that is the resident who
   * registered the payment from the app.
   */
  public function testTheAuthorIsTheRecipient() {
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'roles' => []];

    $this->assertSame(
      [self::UID],
      myapi_payment_notify_recipients($this->payment(MYAPI_PAYMENT_STATUS_COMPLETED), self::UNIT)
    );
  }

  /**
   * WHEN THE AUTHOR IS AN ADMINISTRATOR the payment was typed in on somebody
   * else's behalf, so the unit's OCCUPANTS are notified instead — telling the
   * operator that their own entry was approved is noise, and the resident
   * would never hear about it.
   */
  public function testAnAdministratorAuthorNotifiesTheOccupantsInstead() {
    $GLOBALS['myapi_test_users'][self::ADMIN_UID] = [
      'uid' => self::ADMIN_UID, 'name' => 'admin', 'status' => 1, 'roles' => [3 => 'administrator'],
    ];
    myapi_test_db_seed([
      'field_data_field_ocupante' => [
        ['entity_id' => (string) self::UNIT, 'field_ocupante_target_id' => '7', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => (string) self::UNIT, 'field_ocupantes_target_id' => '8', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $uids = myapi_payment_notify_recipients(
      $this->payment(MYAPI_PAYMENT_STATUS_COMPLETED, ['uid' => self::ADMIN_UID]),
      self::UNIT
    );

    sort($uids);
    // The uids come back as the STRINGS the driver answers —
    // myapi_unit_member_uids() does not cast, and myapi_notification_create()
    // is what intval()s them before the insert. Pinned as it is, because a
    // caller comparing them with === would be surprised.
    $this->assertSame(['7', '8'], $uids, 'both occupant fields are read');
  }

  /**
   * The fallback keeps the notice from being lost: an administrator author
   * whose unit has no resolvable occupant is notified themselves.
   */
  public function testAnAdministratorWithNoOccupantFallsBackToItself() {
    $GLOBALS['myapi_test_users'][self::ADMIN_UID] = [
      'uid' => self::ADMIN_UID, 'name' => 'admin', 'status' => 1, 'roles' => [3 => 'administrator'],
    ];
    myapi_test_db_seed([]);

    $node = $this->payment(MYAPI_PAYMENT_STATUS_COMPLETED, ['uid' => self::ADMIN_UID]);

    $this->assertSame([self::ADMIN_UID], myapi_payment_notify_recipients($node, self::UNIT));
    $this->assertSame([self::ADMIN_UID], myapi_payment_notify_recipients($node, NULL), 'and with no unit at all');
  }

  /**
   * A deleted author still resolves to its own uid rather than to nobody.
   */
  public function testADeletedAuthorStillResolvesToItsUid() {
    $GLOBALS['myapi_test_users'] = [];

    $this->assertSame(
      [self::UID],
      myapi_payment_notify_recipients($this->payment(MYAPI_PAYMENT_STATUS_COMPLETED), self::UNIT)
    );
  }

  /* -------------------------------------------------------------------------
   * The backend email (SPEC 80).
   * ---------------------------------------------------------------------- */

  /**
   * With nobody holding the role nothing is enqueued and no params are built.
   */
  public function testWithNobodyInTheRoleNothingIsEnqueued() {
    $this->seedBuilding();

    myapi_payment_notify_created($this->payment(MYAPI_PAYMENT_STATUS_PENDING));

    $this->assertSame([], myapi_test_queue_items(MYAPI_MAIL_QUEUE));
  }

  /**
   * The params carry every row of the email, already resolved and escaped —
   * because the queue runs on cron, long after the payment was saved.
   */
  public function testTheMailParamsAreResolvedAndEscaped() {
    myapi_test_field_seed_allowed_values([
      MYAPI_PAYMENT_METHOD_FIELD => ['transferencia' => 'Transferencia bancaria'],
    ]);
    $unit = (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101 <b>'];
    $unit->field_condominio[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;
    myapi_test_node_seed([
      self::UNIT => $unit,
      self::CONDOMINIUM => (object) ['nid' => self::CONDOMINIUM, 'type' => 'condominio', 'status' => 1, 'title' => 'Torre & Cía'],
    ]);
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'mail' => 'p@example.com'];
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Pablo', 'last_name' => 'Cordero'];

    $params = myapi_payment_backend_mail_params(
      $this->payment(MYAPI_PAYMENT_STATUS_PENDING, ['amount' => '1500.5']),
      NULL,
      (object) ['tid' => 9, 'name' => 'Banco <Pichincha>'],
      (object) ['fid' => 77, 'uri' => 'private://comprobantes_pago/recibo.pdf']
    );

    $this->assertSame(self::PAYMENT, $params['nid']);
    $this->assertSame('REF-001', $params['reference']);
    $this->assertSame('1,500.50', $params['amount']);
    $this->assertSame('Transferencia bancaria', $params['method']);
    $this->assertSame('Banco &lt;Pichincha&gt;', $params['bank']);
    $this->assertSame('15/06/2026', $params['date']);
    $this->assertSame('A-101 &lt;b&gt;', $params['unit']);
    $this->assertSame('Torre &amp; Cía', $params['condominium']);
    $this->assertSame('Pablo Cordero', $params['resident']);
    $this->assertSame('p@example.com', $params['email']);
    $this->assertSame('recibo.pdf', $params['file']);
    $this->assertSame(MYAPI_PAYMENT_STATUS_PENDING, $params['status']);
  }

  /**
   * Every unresolvable value falls back to the placeholder, so the email keeps
   * its shape instead of showing empty cells.
   */
  public function testEveryUnresolvableValueFallsBackToThePlaceholder() {
    myapi_test_node_seed([]);
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_profile_fields'] = [];

    $node = $this->payment(NULL, ['reference' => NULL, 'unit' => NULL]);
    $params = myapi_payment_backend_mail_params($node);

    foreach (['reference', 'bank', 'unit', 'condominium', 'resident', 'email', 'file', 'status'] as $key) {
      $this->assertSame(MYAPI_PAYMENT_MAIL_EMPTY, $params[$key], $key);
    }
  }

  /**
   * The resident label prefers the profile name and falls back to the
   * username.
   */
  public function testTheResidentLabelPrefersTheProfileName() {
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'mail' => 'p@example.com'];

    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Pablo', 'last_name' => 'Cordero'];
    $this->assertSame('Pablo Cordero', myapi_payment_resident_label(self::UID));

    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Pablo'];
    $this->assertSame('Pablo', myapi_payment_resident_label(self::UID), 'a half profile still wins');

    $GLOBALS['myapi_test_profile_fields'] = [];
    $this->assertSame('pcordero', myapi_payment_resident_label(self::UID), 'the username is the fallback');

    $GLOBALS['myapi_test_users'] = [];
    $this->assertSame(MYAPI_PAYMENT_MAIL_EMPTY, myapi_payment_resident_label(self::UID), 'and the placeholder is the last resort');
  }

  /**
   * The resident's email is exposed for the operator to reply to, escaped, and
   * falls back to the placeholder.
   */
  public function testTheResidentMailLabel() {
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'p', 'status' => 1, 'mail' => 'p@example.com'];
    $this->assertSame('p@example.com', myapi_payment_resident_mail_label(self::UID));

    $GLOBALS['myapi_test_users'][self::UID]['mail'] = '';
    $this->assertSame(MYAPI_PAYMENT_MAIL_EMPTY, myapi_payment_resident_mail_label(self::UID));

    $GLOBALS['myapi_test_users'] = [];
    $this->assertSame(MYAPI_PAYMENT_MAIL_EMPTY, myapi_payment_resident_mail_label(self::UID));
  }

  /**
   * The method label reads the field catalogue and falls back to the RAW KEY
   * for a value the catalogue no longer has — never to an empty cell.
   */
  public function testTheMethodLabelFallsBackToTheRawKey() {
    myapi_test_field_seed_allowed_values([
      MYAPI_PAYMENT_METHOD_FIELD => ['transferencia' => 'Transferencia bancaria'],
    ]);

    $this->assertSame('Transferencia bancaria', myapi_payment_method_label('transferencia'));
    $this->assertSame('deposito', myapi_payment_method_label('deposito'), 'a dropped key still prints');
    $this->assertSame(MYAPI_PAYMENT_MAIL_EMPTY, myapi_payment_method_label(NULL));
    $this->assertSame(MYAPI_PAYMENT_MAIL_EMPTY, myapi_payment_method_label(''));

    myapi_test_field_seed_allowed_values([]);
    $this->assertSame('transferencia', myapi_payment_method_label('transferencia'), 'no catalogue, raw key');
  }

  /**
   * THE DATE LABEL DOES NOT GO THROUGH A TIMEZONE. It reformats the stored
   * string directly, because the value is the calendar day the resident picked
   * and a strtotime() round trip could shift it.
   */
  public function testTheDateLabelReformatsTheStringWithoutATimezone() {
    $this->assertSame('15/06/2026', myapi_payment_date_label('2026-06-15T00:00:00'));
    $this->assertSame('01/01/2026', myapi_payment_date_label('2026-01-01T23:59:59'));
    $this->assertSame('15/06/2026', myapi_payment_date_label('2026-06-15'), 'a bare date works too');

    foreach ([NULL, '', 'ayer', '15/06/2026'] as $value) {
      $this->assertSame(MYAPI_PAYMENT_MAIL_EMPTY, myapi_payment_date_label($value), json_encode($value));
    }
  }

  /**
   * The edit URL points at the node FORM, which is the operator's next action,
   * and is absolute so it works from a mail client.
   */
  public function testTheEditUrlPointsAtTheNodeForm() {
    myapi_test_node_seed([]);
    $GLOBALS['myapi_test_users'] = [];

    $params = myapi_payment_backend_mail_params($this->payment(MYAPI_PAYMENT_STATUS_PENDING));

    $this->assertStringContainsString('node/' . self::PAYMENT . '/edit', $params['edit_url']);
  }

  /**
   * One mail item per recipient, with the resolved params, and the same params
   * for both — a per-recipient rebuild would be a query per operator.
   */
  public function testOneItemPerRecipientCarriesTheSameParams() {
    $this->seedBuilding();
    myapi_test_db_seed(['users' => [
      ['uid' => '10', 'status' => '1', 'name' => 'op1', 'mail' => 'op1@example.com', 'r.name' => MYAPI_PAYMENT_NOTIFY_ROLE],
      ['uid' => '11', 'status' => '1', 'name' => 'op2', 'mail' => 'op2@example.com', 'r.name' => MYAPI_PAYMENT_NOTIFY_ROLE],
    ]]);
    $GLOBALS['myapi_test_users'][10] = ['uid' => 10, 'name' => 'op1', 'status' => 1, 'mail' => 'op1@example.com'];
    $GLOBALS['myapi_test_users'][11] = ['uid' => 11, 'name' => 'op2', 'status' => 1, 'mail' => 'op2@example.com'];

    myapi_payment_notify_created($this->payment(MYAPI_PAYMENT_STATUS_PENDING));

    $items = myapi_test_queue_items(MYAPI_MAIL_QUEUE);
    $this->assertCount(2, $items);
    $this->assertSame(MYAPI_PAYMENT_CREATED_ADMIN_MAIL_KEY, $items[0]['data']['key']);
    $this->assertSame($items[0]['data']['params'], $items[1]['data']['params']);
  }

  /**
   * AN INVALID RECIPIENT ADDRESS DOES NOT DRAG THE BATCH DOWN: it is logged
   * and skipped, and the other operator still gets the email.
   */
  public function testAnInvalidRecipientIsSkippedAndLogged() {
    $this->seedBuilding();
    myapi_test_db_seed(['users' => [
      ['uid' => '10', 'status' => '1', 'name' => 'op1', 'mail' => 'roto', 'r.name' => MYAPI_PAYMENT_NOTIFY_ROLE],
      ['uid' => '11', 'status' => '1', 'name' => 'op2', 'mail' => 'op2@example.com', 'r.name' => MYAPI_PAYMENT_NOTIFY_ROLE],
    ]]);
    $GLOBALS['myapi_test_users'][10] = ['uid' => 10, 'name' => 'op1', 'status' => 1, 'mail' => 'roto'];
    $GLOBALS['myapi_test_users'][11] = ['uid' => 11, 'name' => 'op2', 'status' => 1, 'mail' => 'op2@example.com'];

    myapi_payment_notify_created($this->payment(MYAPI_PAYMENT_STATUS_PENDING));

    $items = myapi_test_queue_items(MYAPI_MAIL_QUEUE);
    $this->assertCount(1, $items);
    $this->assertSame('op2@example.com', $items[0]['data']['to']);
    $this->assertNotSame([], $GLOBALS['myapi_test_watchdog']);
  }
}
