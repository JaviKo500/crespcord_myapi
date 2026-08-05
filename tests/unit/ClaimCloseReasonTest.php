<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/claim.resource.inc';

/**
 * Unit tests for the mandatory closing reason of
 * PUT /api/v1/claims/%/close (SPEC 70).
 *
 * Covers myapi_claim_validate_close_reason(), the only pure piece of the
 * endpoint: it never touches a node and it decides both the 200 and the three
 * 422s. The rest — the 404/403/409 ordering, the 'claim_transaction' that gets
 * created and the status sync it triggers — needs Drupal booted and is verified
 * manually (see docs/claim.md).
 *
 * Two rules are the reason this function exists rather than a call to
 * myapi_request_require_strings(): the reason is REQUIRED, so an absent key and
 * a whitespace-only string must both answer 'missing_field' and not "no
 * reason"; and the length limit counts CHARACTERS, because a Spanish reason
 * with accents is two bytes per character and strlen() would reject a
 * perfectly storable one — hence the accented cases below.
 */
class ClaimCloseReasonTest extends TestCase {

  /**
   * No body at all: the field is required, so this is a 422 and not a silent
   * "no reason" like the optional cancel_reason of a reservation.
   */
  public function testNullBodyIsMissingField() {
    $result = myapi_claim_validate_close_reason(NULL);

    $this->assertFalse($result['ok']);
    $this->assertSame('missing_field', $result['error_code']);
    $this->assertSame(['@field' => 'close_reason'], $result['replacements']);
  }

  /**
   * An empty body, or one carrying only other keys, is the same 422.
   */
  public function testBodyWithoutTheKeyIsMissingField() {
    foreach ([[], ['other' => 'x']] as $body) {
      $result = myapi_claim_validate_close_reason($body);

      $this->assertFalse($result['ok'], json_encode($body));
      $this->assertSame('missing_field', $result['error_code'], json_encode($body));
    }
  }

  /**
   * A JSON body that decodes to a scalar is not a body: no key, no reason.
   */
  public function testScalarBodyIsMissingField() {
    $result = myapi_claim_validate_close_reason('close_reason');

    $this->assertFalse($result['ok']);
    $this->assertSame('missing_field', $result['error_code']);
  }

  /**
   * The key present with the wrong type is a FORMAT error, not a missing one:
   * the client meant to send a reason and sent something that is not one.
   */
  public function testNonStringReasonIsInvalidField() {
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
      $result = myapi_claim_validate_close_reason(['close_reason' => $value]);

      $this->assertFalse($result['ok'], $label);
      $this->assertSame('invalid_field', $result['error_code'], $label);
      $this->assertSame(['@field' => 'close_reason'], $result['replacements'], $label);
    }
  }

  /**
   * Whitespace-only is as absent as no key at all — the app must not be able to
   * close a claim with a reason of three spaces.
   */
  public function testWhitespaceOnlyReasonIsMissingField() {
    foreach (['', ' ', '   ', "\t", "\n  \n"] as $raw) {
      $result = myapi_claim_validate_close_reason(['close_reason' => $raw]);

      $this->assertFalse($result['ok'], json_encode($raw));
      $this->assertSame('missing_field', $result['error_code'], json_encode($raw));
    }
  }

  /**
   * The stored value is trimmed, so neither the timeline nor the email ever
   * prints a leading blank.
   */
  public function testReasonIsTrimmed() {
    $result = myapi_claim_validate_close_reason([
      'close_reason' => "  Lo resolví por mi cuenta con el vecino \n",
    ]);

    $this->assertTrue($result['ok']);
    $this->assertSame('Lo resolví por mi cuenta con el vecino', $result['value']);
  }

  /**
   * A single character is a valid reason: the rule is "not empty", not "long
   * enough to be useful".
   */
  public function testOneCharacterReasonIsAccepted() {
    $result = myapi_claim_validate_close_reason(['close_reason' => 'x']);

    $this->assertTrue($result['ok']);
    $this->assertSame('x', $result['value']);
  }

  /**
   * Exactly MYAPI_CLAIM_CLOSE_REASON_MAX characters is the last accepted
   * length.
   */
  public function testReasonOfMaxCharactersIsAccepted() {
    $reason = str_repeat('a', MYAPI_CLAIM_CLOSE_REASON_MAX);

    $result = myapi_claim_validate_close_reason(['close_reason' => $reason]);

    $this->assertTrue($result['ok']);
    $this->assertSame($reason, $result['value']);
  }

  /**
   * One character over is one too many.
   */
  public function testReasonOverTheLimitIsRejected() {
    $result = myapi_claim_validate_close_reason([
      'close_reason' => str_repeat('a', MYAPI_CLAIM_CLOSE_REASON_MAX + 1),
    ]);

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_long', $result['error_code']);
    $this->assertSame(['@field' => 'close_reason'], $result['replacements']);
  }

  /**
   * The limit counts characters, not bytes: a full-length reason written in
   * Spanish is twice as many bytes and still fits.
   */
  public function testAccentedReasonAtTheLimitIsAccepted() {
    $reason = str_repeat('á', MYAPI_CLAIM_CLOSE_REASON_MAX);
    $this->assertSame(
      MYAPI_CLAIM_CLOSE_REASON_MAX * 2,
      strlen($reason),
      'fixture sanity: two bytes per character'
    );

    $result = myapi_claim_validate_close_reason(['close_reason' => $reason]);

    $this->assertTrue($result['ok']);
    $this->assertSame($reason, $result['value']);
  }

  /**
   * And one accented character over the limit is still rejected.
   */
  public function testAccentedReasonOverTheLimitIsRejected() {
    $result = myapi_claim_validate_close_reason([
      'close_reason' => str_repeat('ñ', MYAPI_CLAIM_CLOSE_REASON_MAX + 1),
    ]);

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_long', $result['error_code']);
  }

  /**
   * The length is measured AFTER trimming, so padding a full-length reason with
   * spaces does not push it over the limit.
   */
  public function testLengthIsMeasuredAfterTrimming() {
    $reason = str_repeat('a', MYAPI_CLAIM_CLOSE_REASON_MAX);

    $result = myapi_claim_validate_close_reason(['close_reason' => '   ' . $reason . '   ']);

    $this->assertTrue($result['ok']);
    $this->assertSame($reason, $result['value']);
  }
}
