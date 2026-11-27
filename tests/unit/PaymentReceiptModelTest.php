<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.payment_receipt.inc';

/**
 * Unit tests for the payment receipt data model (SPEC 138).
 *
 * What this file covers is the part of the model that is pure logic: the
 * security code that will travel in the QR and the barcode. The tables, the
 * fields and the sequence reservation are exercised against a real database
 * (tests/integration) or by the manual checks of the spec; what can be
 * asserted here is the alphabet, the length and the fact that two calls do not
 * return the same value.
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

}
