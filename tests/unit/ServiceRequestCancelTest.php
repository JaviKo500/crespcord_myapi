<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_files.inc';
require_once __DIR__ . '/../../includes/myapi.service_offer.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_detail.inc';
require_once __DIR__ . '/../../includes/myapi.provider_card.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * Unit tests for the pure pieces of
 * PUT /api/v1/service-requests/%/cancel (SPEC 95).
 *
 * Two functions, and between them they decide the whole shape of what the
 * cancellation writes: whether the request is answered 200 or 422, and what
 * ends up in the field_comment of the transaction that lands on the timeline
 * forever. Everything else in that endpoint — the ownership check, the
 * transition graph, the node_save() of the request, the transaction and the
 * rejection of live offers — needs Drupal and a database booted, and is
 * verified with an HTTP client against the running site (see step 4 of the
 * spec and docs/service-request.md).
 *
 * The two rules worth stating out loud, because both are easy to "fix" into
 * bugs:
 *
 * - An absent, empty or whitespace-only reason is NOT an error. The reason is
 *   optional and this is the only exit the resident has; a 422 there would
 *   force the app to scrub the string before sending it.
 * - The length limit counts CHARACTERS, not bytes. The residents of this
 *   product write in Spanish, so 255 accented characters must fit — hence the
 *   accented cases below.
 */
class ServiceRequestCancelTest extends TestCase {

  /**
   * No body at all: cancelling without explaining is the normal case.
   */
  public function testNullBodyMeansNoReason() {
    $result = myapi_service_request_validate_cancel_reason(NULL);

    $this->assertTrue($result['ok']);
    $this->assertNull($result['value']);
  }

  /**
   * An empty body, or one carrying other keys, is still "no reason".
   */
  public function testBodyWithoutTheKeyMeansNoReason() {
    foreach ([[], ['other' => 'x']] as $body) {
      $result = myapi_service_request_validate_cancel_reason($body);

      $this->assertTrue($result['ok']);
      $this->assertNull($result['value']);
    }
  }

  /**
   * A JSON body that decodes to a scalar is not a body: no key, no reason.
   */
  public function testScalarBodyMeansNoReason() {
    $result = myapi_service_request_validate_cancel_reason('reason');

    $this->assertTrue($result['ok']);
    $this->assertNull($result['value']);
  }

  /**
   * Whitespace-only is treated as absent, never as a 422: an optional field
   * the user left blank is not a format error.
   */
  public function testWhitespaceOnlyReasonMeansNoReason() {
    foreach (['', ' ', '   ', "\t", "\n  \n"] as $raw) {
      $result = myapi_service_request_validate_cancel_reason(['reason' => $raw]);

      $this->assertTrue($result['ok'], json_encode($raw));
      $this->assertNull($result['value'], json_encode($raw));
    }
  }

  /**
   * The key present with the wrong type IS an error: the client meant to send
   * a reason and sent something that is not one.
   */
  public function testNonStringReasonIsRejected() {
    $cases = [
      'integer' => 42,
      'float'   => 1.5,
      'array'   => ['a'],
      'object'  => ['nested' => 'value'],
      'bool'    => TRUE,
      // Explicit null: distinguishable from an absent key only with
      // array_key_exists(), which is why the validator does not use isset().
      'null'    => NULL,
    ];

    foreach ($cases as $label => $value) {
      $result = myapi_service_request_validate_cancel_reason(['reason' => $value]);

      $this->assertFalse($result['ok'], $label);
      $this->assertSame('invalid_field', $result['error_code'], $label);
      $this->assertSame(['@field' => 'reason'], $result['replacements'], $label);
    }
  }

  /**
   * The stored value is trimmed, so the timeline never prints a leading blank.
   */
  public function testReasonIsTrimmed() {
    $result = myapi_service_request_validate_cancel_reason([
      'reason' => "  Ya resolví el problema por mi cuenta \n",
    ]);

    $this->assertTrue($result['ok']);
    $this->assertSame('Ya resolví el problema por mi cuenta', $result['value']);
  }

  /**
   * Exactly 255 characters is the last accepted length.
   */
  public function testReasonOf255CharactersIsAccepted() {
    $reason = str_repeat('a', 255);

    $result = myapi_service_request_validate_cancel_reason(['reason' => $reason]);

    $this->assertTrue($result['ok']);
    $this->assertSame($reason, $result['value']);
  }

  /**
   * 256 characters is one too many.
   */
  public function testReasonOf256CharactersIsRejected() {
    $result = myapi_service_request_validate_cancel_reason([
      'reason' => str_repeat('a', 256),
    ]);

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_long', $result['error_code']);
    $this->assertSame(['@field' => 'reason'], $result['replacements']);
  }

  /**
   * The limit counts characters, not bytes: 255 accented ones are 510 bytes
   * and still fit.
   */
  public function testReasonOf255AccentedCharactersIsAccepted() {
    $reason = str_repeat('á', 255);
    $this->assertSame(510, strlen($reason), 'fixture sanity: two bytes per character');

    $result = myapi_service_request_validate_cancel_reason(['reason' => $reason]);

    $this->assertTrue($result['ok']);
    $this->assertSame($reason, $result['value']);
  }

  /**
   * And 256 accented characters are still one too many.
   */
  public function testReasonOf256AccentedCharactersIsRejected() {
    $result = myapi_service_request_validate_cancel_reason([
      'reason' => str_repeat('ñ', 256),
    ]);

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_long', $result['error_code']);
  }

  /**
   * The length is measured AFTER trimming, so padding a 255-character reason
   * with spaces does not push it over the limit.
   */
  public function testLengthIsMeasuredAfterTrimming() {
    $result = myapi_service_request_validate_cancel_reason([
      'reason' => '   ' . str_repeat('a', 255) . '   ',
    ]);

    $this->assertTrue($result['ok']);
    $this->assertSame(str_repeat('a', 255), $result['value']);
  }

  /**
   * The 'cancel_reason' key of SPEC 50 is not this endpoint's key: sending it
   * here is "no reason", not a reason. The two validators are separate on
   * purpose and this is what that separation means from outside.
   */
  public function testReservationKeyIsIgnored() {
    $result = myapi_service_request_validate_cancel_reason([
      'cancel_reason' => 'Ya lo resolví.',
    ]);

    $this->assertTrue($result['ok']);
    $this->assertNull($result['value']);
  }

  /**
   * A reason with content becomes the comment verbatim: no prefix, no label,
   * no escaping.
   */
  public function testCommentIsTheReasonVerbatim() {
    $reason = 'Ya lo resolví.';

    $this->assertSame($reason, myapi_service_request_cancel_comment($reason));
  }

  /**
   * Markup in the reason is stored raw — field_comment is escaped by whoever
   * renders it (SPEC 92), not here.
   */
  public function testCommentIsNotEscaped() {
    $reason = 'Lo resolvió "Juan" & <b>Pedro</b>';

    $this->assertSame($reason, myapi_service_request_cancel_comment($reason));
  }

  /**
   * No reason: the automatic fallback takes its place.
   */
  public function testCommentFallsBackWhenThereIsNoReason() {
    $this->assertSame(
      'El residente canceló la solicitud.',
      myapi_service_request_cancel_comment(NULL)
    );
  }

  /**
   * The comment is never empty, whatever the validator hands over. SPEC 92
   * established that no transaction is born without a comment, and this is the
   * assertion that holds that line.
   */
  public function testCommentIsNeverEmpty() {
    foreach ([NULL, '', 'Ya lo resolví.'] as $reason) {
      $comment = myapi_service_request_cancel_comment($reason);

      $this->assertIsString($comment, json_encode($reason));
      $this->assertNotSame('', $comment, json_encode($reason));
    }
  }

  /**
   * The two functions compose: whatever the validator accepts as "no reason"
   * comes out of the comment builder as the fallback, and whatever it accepts
   * as a reason comes out verbatim.
   */
  public function testValidatorAndCommentCompose() {
    $blank = myapi_service_request_validate_cancel_reason(['reason' => '   ']);
    $this->assertSame(
      'El residente canceló la solicitud.',
      myapi_service_request_cancel_comment($blank['value'])
    );

    $given = myapi_service_request_validate_cancel_reason(['reason' => '  Ya lo resolví.  ']);
    $this->assertSame(
      'Ya lo resolví.',
      myapi_service_request_cancel_comment($given['value'])
    );
  }
}
