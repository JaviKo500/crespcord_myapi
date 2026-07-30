<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/reservation.resource.inc';

/**
 * Unit tests for the optional cancellation reason of
 * PUT /api/v1/reservations/%/cancel (SPEC 50).
 *
 * Covers myapi_reservation_validate_cancel_reason(), the whole of the
 * endpoint's new logic: it is pure, it never touches the node and it decides
 * both the 200 and the two 422s. The write itself
 * ($node->field_cancel_reason[...]) and the ordering against the five checks of
 * SPEC 36 need Drupal booted, so they are verified manually (see step 10 of the
 * spec and docs/reservation.md).
 *
 * The length rule is the reason this function exists at all: the column is
 * varchar(255) in utf8, so the limit is 255 CHARACTERS. Measuring bytes would
 * reject a perfectly storable reason written in Spanish, which is exactly the
 * language the residents of this product write in — hence the accented cases
 * below.
 */
class ReservationCancelReasonTest extends TestCase {

  /**
   * No body at all: the request behaves exactly as it did before SPEC 50.
   */
  public function testNullBodyMeansNoReason() {
    $result = myapi_reservation_validate_cancel_reason(NULL);

    $this->assertTrue($result['ok']);
    $this->assertNull($result['value']);
  }

  /**
   * An empty body, or one carrying other keys, is still "no reason".
   */
  public function testBodyWithoutTheKeyMeansNoReason() {
    foreach ([[], ['other' => 'x']] as $body) {
      $result = myapi_reservation_validate_cancel_reason($body);

      $this->assertTrue($result['ok']);
      $this->assertNull($result['value']);
    }
  }

  /**
   * A JSON body that decodes to a scalar is not a body: no key, no reason.
   */
  public function testScalarBodyMeansNoReason() {
    $result = myapi_reservation_validate_cancel_reason('cancel_reason');

    $this->assertTrue($result['ok']);
    $this->assertNull($result['value']);
  }

  /**
   * The key present with the wrong type IS an error: the client meant to send
   * a reason and sent something that is not one.
   */
  public function testNonStringReasonIsRejected() {
    $cases = [
      'integer' => 123,
      'float'   => 1.5,
      'array'   => ['a', 'b'],
      'object'  => ['nested' => 'value'],
      'bool'    => TRUE,
      // Explicit null: distinguishable from an absent key only with
      // array_key_exists(), which is why the validator does not use isset().
      'null'    => NULL,
    ];

    foreach ($cases as $label => $value) {
      $result = myapi_reservation_validate_cancel_reason(['cancel_reason' => $value]);

      $this->assertFalse($result['ok'], $label);
      $this->assertSame('invalid_field', $result['error_code'], $label);
      $this->assertSame(['@field' => 'cancel_reason'], $result['replacements'], $label);
    }
  }

  /**
   * Whitespace-only is treated as absent, never as a 422: an optional field the
   * user left blank is not a format error.
   */
  public function testWhitespaceOnlyReasonMeansNoReason() {
    foreach (['', ' ', '   ', "\t", "\n  \n"] as $raw) {
      $result = myapi_reservation_validate_cancel_reason(['cancel_reason' => $raw]);

      $this->assertTrue($result['ok'], json_encode($raw));
      $this->assertNull($result['value'], json_encode($raw));
    }
  }

  /**
   * The stored value is trimmed, so the calendar panel and the email never
   * print a leading blank.
   */
  public function testReasonIsTrimmed() {
    $result = myapi_reservation_validate_cancel_reason([
      'cancel_reason' => "  El evento se pospuso para el mes que viene \n",
    ]);

    $this->assertTrue($result['ok']);
    $this->assertSame('El evento se pospuso para el mes que viene', $result['value']);
  }

  /**
   * Exactly 255 characters is the last accepted length.
   */
  public function testReasonOf255CharactersIsAccepted() {
    $reason = str_repeat('a', 255);

    $result = myapi_reservation_validate_cancel_reason(['cancel_reason' => $reason]);

    $this->assertTrue($result['ok']);
    $this->assertSame($reason, $result['value']);
  }

  /**
   * 256 characters is one too many.
   */
  public function testReasonOf256CharactersIsRejected() {
    $result = myapi_reservation_validate_cancel_reason([
      'cancel_reason' => str_repeat('a', 256),
    ]);

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_long', $result['error_code']);
    $this->assertSame(['@field' => 'cancel_reason'], $result['replacements']);
  }

  /**
   * The limit counts characters, not bytes: 255 accented ones are 510 bytes and
   * still fit in a utf8 varchar(255).
   */
  public function testReasonOf255AccentedCharactersIsAccepted() {
    $reason = str_repeat('á', 255);
    $this->assertSame(510, strlen($reason), 'fixture sanity: two bytes per character');

    $result = myapi_reservation_validate_cancel_reason(['cancel_reason' => $reason]);

    $this->assertTrue($result['ok']);
    $this->assertSame($reason, $result['value']);
  }

  /**
   * And 256 accented characters are still one too many.
   */
  public function testReasonOf256AccentedCharactersIsRejected() {
    $result = myapi_reservation_validate_cancel_reason([
      'cancel_reason' => str_repeat('ñ', 256),
    ]);

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_long', $result['error_code']);
  }

  /**
   * The length is measured AFTER trimming, so padding a 255-character reason
   * with spaces does not push it over the limit.
   */
  public function testLengthIsMeasuredAfterTrimming() {
    $result = myapi_reservation_validate_cancel_reason([
      'cancel_reason' => '   ' . str_repeat('a', 255) . '   ',
    ]);

    $this->assertTrue($result['ok']);
    $this->assertSame(str_repeat('a', 255), $result['value']);
  }
}
