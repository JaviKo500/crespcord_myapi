<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.payment_receipt.inc';

/**
 * Unit tests for the payment receipt data model (SPEC 138).
 *
 * Two halves of the model are covered here. The security code that will
 * travel in the QR and the barcode is pure logic: the alphabet, the length and
 * the fact that two calls do not return the same value. The number reservation
 * is SQL, but its three statements are simulated by the
 * {myapi_receipt_sequences} fixture in bootstrap.php, which is enough to pin
 * the contract callers depend on: the guard without a transaction, the start
 * number, the increment and the independence between condominiums. The tables
 * and the fields themselves need a real database and stay with the manual
 * checks of the spec.
 *
 * THE 1 000-CODE CASE IS NOT PARANOIA. The generator reads one byte per
 * character and masks it with & 31, which is uniform only because 256 is a
 * multiple of 32. A bug that read the same byte twice, or that reused a
 * buffer, would still produce plausible-looking codes and every single-call
 * assertion would pass. Generating a thousand and requiring all of them to be
 * different is the cheap way to catch that class of mistake.
 */
class PaymentReceiptModelTest extends TestCase {

  /**
   * The published alphabet, spelled out instead of read from the constant.
   *
   * Reading the constant back would assert that the code equals itself; the
   * point is that the alphabet the docs promise is the one the generator uses.
   */
  const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

  /**
   * Four condominiums, one per start-number case.
   *
   * 101 has no field at all, 102 and 103 have different start numbers and 104
   * is the one whose field is changed after its first receipt.
   */
  protected function setUp(): void {
    $GLOBALS['myapi_test_receipt_sequences'] = [];
    $GLOBALS['myapi_test_receipt_sequence_locks'] = [];
    $GLOBALS['myapi_test_transactions_open'] = 0;

    myapi_test_node_seed([
      101 => ['nid' => 101, 'type' => 'condominio'],
      102 => [
        'nid'   => 102,
        'type'  => 'condominio',
        'field_receipt_start_number' => [LANGUAGE_NONE => [['value' => 500]]],
      ],
      103 => [
        'nid'   => 103,
        'type'  => 'condominio',
        'field_receipt_start_number' => [LANGUAGE_NONE => [['value' => 700]]],
      ],
      104 => [
        'nid'   => 104,
        'type'  => 'condominio',
        'field_receipt_start_number' => [LANGUAGE_NONE => [['value' => 900]]],
      ],
    ]);
  }

  protected function tearDown(): void {
    myapi_test_node_seed();
    unset($GLOBALS['myapi_test_receipt_sequences'], $GLOBALS['myapi_test_receipt_sequence_locks']);
    $GLOBALS['myapi_test_transactions_open'] = 0;
  }

  /**
   * Reserves one number inside its own transaction, as production must.
   */
  private function reserveNumber($condominium_nid) {
    $transaction = db_transaction();
    $number = myapi_payment_receipt_next_number($condominium_nid);
    unset($transaction);

    return $number;
  }

  public function testSecurityCodeHasTwentyCharacters() {
    $this->assertSame(20, strlen(myapi_payment_receipt_security_code()));
  }

  public function testSecurityCodeUsesOnlyThePublishedAlphabet() {
    $this->assertMatchesRegularExpression(
      '/^[' . self::ALPHABET . ']{20}$/',
      myapi_payment_receipt_security_code()
    );
  }

  public function testSecurityCodeDiffersBetweenCalls() {
    $this->assertNotSame(
      myapi_payment_receipt_security_code(),
      myapi_payment_receipt_security_code()
    );
  }

  public function testAThousandCodesInARowDoNotRepeat() {
    $codes = [];
    for ($i = 0; $i < 1000; $i++) {
      $codes[] = myapi_payment_receipt_security_code();
    }

    $this->assertCount(1000, array_unique($codes));
  }

  public function testNextNumberThrowsWithoutAnOpenTransaction() {
    try {
      myapi_payment_receipt_next_number(101);
      $this->fail('Expected a LogicException when no transaction is open.');
    }
    catch (LogicException $e) {
      $this->assertStringContainsString('open transaction', $e->getMessage());
    }

    $this->assertSame(
      [],
      $GLOBALS['myapi_test_receipt_sequences'],
      'the guard must fire before touching the counter'
    );
  }

  public function testFirstNumberWithoutStartFieldIsOne() {
    $this->assertSame(1, $this->reserveNumber(101));
  }

  public function testStartNumberIsTheFirstReservedNumber() {
    $this->assertSame(500, $this->reserveNumber(102));
    $this->assertSame(501, $this->reserveNumber(102));
    $this->assertSame(502, $this->reserveNumber(102));
  }

  public function testCondominiumsKeepIndependentCounters() {
    $this->assertSame(1, $this->reserveNumber(101));
    $this->assertSame(700, $this->reserveNumber(103));
    $this->assertSame(2, $this->reserveNumber(101));
    $this->assertSame(701, $this->reserveNumber(103));
  }

  public function testChangingTheStartNumberAfterTheFirstReceiptDoesNotResetTheSequence() {
    $this->assertSame(900, $this->reserveNumber(104));

    $node = node_load(104);
    $node->field_receipt_start_number[LANGUAGE_NONE][0]['value'] = 5;

    $this->assertSame(901, $this->reserveNumber(104));
  }

  public function testReservationTakesTheRowLockInsideTheTransaction() {
    $this->reserveNumber(102);

    $this->assertSame(
      [['condominium_nid' => 102, 'in_transaction' => TRUE]],
      $GLOBALS['myapi_test_receipt_sequence_locks']
    );
  }

}
