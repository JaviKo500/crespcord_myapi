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
require_once __DIR__ . '/../../includes/myapi.service_transaction.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * Unit tests for the pure pieces of
 * PUT /api/v1/service-requests/%/reject (SPEC 121).
 *
 * Three functions, and between them they decide everything this endpoint
 * refuses and everything it writes that a person will read: who may hand a job
 * back, from which status, whether the body is accepted, and what lands in the
 * field_comment of a transaction that stays on the timeline forever. The rest
 * of the endpoint — the token, the node_save() of the request, the transaction,
 * the sweep and the notice — needs Drupal and a database booted and is verified
 * with an HTTP client against the running site (see
 * docs/service-request-provider.md).
 *
 * THE THREE RULES WORTH STATING OUT LOUD, because each one is easy to "fix"
 * into a bug:
 *
 * - "Not yours" is decided BEFORE "not from this status". A provider poking at
 *   somebody else's assigned request must learn 403 and never the 409 that
 *   would confirm what state it is in.
 * - The reason is REQUIRED here and optional on the resident's cancellation.
 *   The asymmetry is the point of the spec, not an oversight to be harmonised.
 * - The comment must NAME THE PROVIDER and say they cancelled. Both acts land
 *   the request on 'cancelled', so this sentence is the only thing that keeps
 *   the resident from reading a handed-back job as a decision of their own.
 */
class ServiceRequestRejectTest extends TestCase {

  /* -------------------------------------------------------------------------
   * The gate.
   * ---------------------------------------------------------------------- */

  /**
   * The happy path: my own provider holds it, and it is still 'direct'.
   */
  public function testADirectAwardedToOneOfMyProvidersPasses() {
    $this->assertNull(myapi_service_request_reject_gate(
      7,
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      [3, 7, 11]
    ));
  }

  /**
   * The target_id as a node hands it over is a STRING, and it must pass just
   * the same. field_assigned_provider comes out of the Field API as '7', never
   * as 7, so a gate comparing strictly against integers would refuse every
   * legitimate rejection on the running site while going green on a test that
   * seeded integers.
   */
  public function testTheProviderIdIsComparedAsANumberAndNotAsAString() {
    $this->assertNull(myapi_service_request_reject_gate(
      '7',
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      ['3', '7']
    ));
  }

  /**
   * Somebody else's job: 403, and the status is never consulted.
   */
  public function testADirectAwardedToSomebodyElseIsForbidden() {
    $this->assertSame(
      'service_request_forbidden',
      myapi_service_request_reject_gate(9, MYAPI_SERVICES_REQUEST_STATUS_DIRECT, [3, 7])
    );
  }

  /**
   * A request with no assigned provider is nobody's and fails closed. NULL, 0
   * and a negative id are the same answer: there is nothing else to say about
   * a job that was never given to anyone.
   */
  public function testARequestWithNoAssignedProviderIsForbidden() {
    foreach ([NULL, 0, '0', -3, ''] as $assigned) {
      $this->assertSame(
        'service_request_forbidden',
        myapi_service_request_reject_gate($assigned, MYAPI_SERVICES_REQUEST_STATUS_DIRECT, [3, 7]),
        var_export($assigned, TRUE)
      );
    }
  }

  /**
   * "NOT YOURS" BEATS "NOT FROM HERE", and that ordering is the contract. A
   * closed request belonging to another provider answers 403 and not 409: the
   * first true thing about it is that the caller does not hold it, and the 409
   * would confirm a status they may not ask about.
   */
  public function testOwnershipIsDecidedBeforeTheStatus() {
    $this->assertSame(
      'service_request_forbidden',
      myapi_service_request_reject_gate(9, MYAPI_SERVICES_REQUEST_STATUS_CLOSED, [3, 7])
    );
  }

  /**
   * ONCE THE PROVIDER HAS QUOTED, THE DOOR IS SHUT. SPEC 120 moves the request
   * to 'assigned' the moment a quote arrives, so "I cannot take this job" stops
   * being sayable exactly when a price exists — which is the whole of when this
   * verb means anything.
   */
  public function testAnAssignedRequestIsNoLongerRejectable() {
    $this->assertSame(
      'service_request_not_rejectable',
      myapi_service_request_reject_gate(7, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED, [7])
    );
  }

  /**
   * Every status but 'direct' is refused, the two terminals included — which is
   * also what makes a second call on a request already rejected answer 409
   * rather than pretending it did something.
   */
  public function testNoStatusButDirectIsRejectable() {
    $statuses = [
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ];

    foreach ($statuses as $status) {
      $this->assertSame(
        'service_request_not_rejectable',
        myapi_service_request_reject_gate(7, $status, [7]),
        $status
      );
    }
  }

  /**
   * A corrupt or empty field_request_status is a 409 and never a crash: it is
   * not 'direct', which needs no branch of its own.
   */
  public function testAnUnknownStatusIsRefusedAndNeverThrows() {
    foreach ([NULL, '', 'garbage', 0, FALSE] as $status) {
      $this->assertSame(
        'service_request_not_rejectable',
        myapi_service_request_reject_gate(7, $status, [7]),
        var_export($status, TRUE)
      );
    }
  }

  /**
   * THE GRAPH IS ASKED AND NOT TRANSCRIBED, and this is the assertion that
   * holds the two in step: the status the gate lets through must be one the
   * graph agrees can reach 'cancelled'. The day somebody narrows the edges of
   * 'direct', this test goes red instead of the endpoint silently writing a
   * transition the graph forbids.
   */
  public function testTheStatusItAcceptsIsOneTheGraphAllows() {
    $this->assertTrue(myapi_services_transition_allowed(
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED
    ));
  }

  /* -------------------------------------------------------------------------
   * The reason. REQUIRED, unlike the cancellation's.
   * ---------------------------------------------------------------------- */

  /**
   * No body, no key, or a body that is not a body at all: missing_field. This
   * is the one line where this validator and its sibling disagree, and the
   * disagreement is the spec.
   */
  public function testAnAbsentReasonIsMissingField() {
    foreach ([NULL, [], ['other' => 'x'], 'reason', 42] as $body) {
      $result = myapi_service_request_validate_reject_reason($body);

      $this->assertFalse($result['ok'], json_encode($body));
      $this->assertSame('missing_field', $result['error_code']);
      $this->assertSame(['@field' => 'reason'], $result['replacements']);
    }
  }

  /**
   * WHITESPACE-ONLY IS missing_field AND NOT invalid_field. '   ' is not a
   * malformed value, it is the absence of one dressed up, and the client that
   * sent it has the same bug as the one that sent no key at all.
   */
  public function testAWhitespaceOnlyReasonIsMissingAndNotInvalid() {
    foreach (['', ' ', '   ', "\t", "\n  \n"] as $raw) {
      $result = myapi_service_request_validate_reject_reason(['reason' => $raw]);

      $this->assertFalse($result['ok'], json_encode($raw));
      $this->assertSame('missing_field', $result['error_code'], json_encode($raw));
    }
  }

  /**
   * Markup that flattens to nothing is the same as nothing: the automatic
   * fallback the cancellation has does not exist here, so this has to be
   * refused rather than stored as an empty comment.
   */
  public function testMarkupThatFlattensToNothingIsMissing() {
    $result = myapi_service_request_validate_reject_reason(['reason' => '<p></p>']);

    $this->assertFalse($result['ok']);
    $this->assertSame('missing_field', $result['error_code']);
  }

  /**
   * The key present with the wrong type IS invalid_field: the client meant to
   * send a reason and sent something that is not one.
   */
  public function testANonStringReasonIsInvalidField() {
    foreach ([42, 4.2, TRUE, NULL, ['a'], new stdClass()] as $raw) {
      $result = myapi_service_request_validate_reject_reason(['reason' => $raw]);

      $this->assertFalse($result['ok'], gettype($raw));
      $this->assertSame('invalid_field', $result['error_code'], gettype($raw));
      $this->assertSame(['@field' => 'reason'], $result['replacements']);
    }
  }

  /**
   * A normal reason comes back flattened and trimmed.
   */
  public function testAReasonIsAcceptedAndFlattened() {
    $result = myapi_service_request_validate_reject_reason([
      'reason' => '  No tengo disponibilidad esta semana.  ',
    ]);

    $this->assertTrue($result['ok']);
    $this->assertSame('No tengo disponibilidad esta semana.', $result['value']);
  }

  /**
   * THE LIMIT COUNTS CHARACTERS AND NOT BYTES. Providers write in Spanish, so
   * 255 accented characters must fit — they are 510 bytes, and a strlen() here
   * would refuse a reason that is exactly as long as the rule allows.
   */
  public function testTheLimitCountsCharactersAndNotBytes() {
    $accented = str_repeat('á', 255);

    $result = myapi_service_request_validate_reject_reason(['reason' => $accented]);

    $this->assertTrue($result['ok']);
    $this->assertSame($accented, $result['value']);
  }

  /**
   * One character over is field_too_long, accented or not.
   */
  public function testAReasonOver255IsTooLong() {
    foreach ([str_repeat('a', 256), str_repeat('á', 256)] as $raw) {
      $result = myapi_service_request_validate_reject_reason(['reason' => $raw]);

      $this->assertFalse($result['ok']);
      $this->assertSame('field_too_long', $result['error_code']);
      $this->assertSame(['@field' => 'reason'], $result['replacements']);
    }
  }

  /* -------------------------------------------------------------------------
   * The timeline sentence.
   * ---------------------------------------------------------------------- */

  /**
   * THE ONE THING THIS SENTENCE HAS TO DO: name the provider and say THEY
   * cancelled. Both acts land on 'cancelled', so this text is the only thing
   * that stops the resident reading a handed-back job as a decision of their
   * own.
   */
  public function testTheCommentNamesTheProviderAndTheReason() {
    $comment = myapi_service_request_reject_comment(
      'Plomería Ríos',
      'No tengo disponibilidad esta semana.'
    );

    $this->assertStringContainsString('proveedor', $comment);
    $this->assertStringContainsString('Plomería Ríos', $comment);
    $this->assertStringContainsString('No tengo disponibilidad esta semana.', $comment);
  }

  /**
   * IT MUST NOT READ LIKE THE RESIDENT'S OWN CANCELLATION, which is the exact
   * confusion SPEC 121 exists to avoid. The two sentences are compared here
   * rather than asserted apart by eye, so a future edit that harmonises them
   * into one text goes red.
   */
  public function testTheCommentIsNotTheResidentsCancellationText() {
    $mine = myapi_service_request_reject_comment('Plomería Ríos', 'No puedo.');
    $theirs = myapi_service_request_cancel_comment(NULL);

    $this->assertNotSame($theirs, $mine);
    $this->assertStringNotContainsString('residente', $mine);
  }

  /**
   * An unpublished or deleted provider node resolves to NULL, and the sentence
   * must still be a whole sentence — never "El proveedor  canceló", with the
   * hole where the name should be.
   */
  public function testANamelessProviderStillProducesAWholeSentence() {
    foreach ([NULL, '', '   '] as $name) {
      $comment = myapi_service_request_reject_comment($name, 'No puedo.');

      $this->assertNotSame('', $comment);
      $this->assertStringNotContainsString('  ', $comment, var_export($name, TRUE));
      $this->assertStringContainsString('No puedo.', $comment);
    }
  }

  /**
   * The reasonless branch is unreachable from the endpoint — the validator
   * answers 422 first — and exists so a programmatic caller cannot create the
   * commentless transaction SPEC 92 ruled out. All four combinations answer a
   * non-empty string.
   */
  public function testEveryCombinationAnswersANonEmptyString() {
    foreach ([NULL, '', 'Plomería Ríos'] as $name) {
      foreach ([NULL, '', '   ', 'No puedo.'] as $reason) {
        $comment = myapi_service_request_reject_comment($name, $reason);

        $this->assertIsString($comment);
        $this->assertNotSame('', trim($comment));
      }
    }
  }
}
