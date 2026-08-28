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
require_once __DIR__ . '/../../includes/myapi.service_transaction.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * Unit tests for the pure pieces of
 * PUT /api/v1/service-requests/%/close (SPEC 108).
 *
 * Three functions, and between them they decide the whole shape of what the
 * close writes: whether the request is answered 200 or 422, how many stars end
 * up on the provider's reputation forever, and what lands on the timeline.
 * Everything else in that endpoint — the ownership check, the transition graph,
 * the three node_save()s and the two counters — needs Drupal and a database
 * booted, and is verified with an HTTP client against the running site (see the
 * spec's acceptance matrix and docs/service-request.md).
 *
 * THE ONE RULE THAT SHAPES EVERY TEST BELOW: the body has TWO shapes and the
 * request's status picks one. myapi_services_close_requires_rating() is what
 * picks — it is SPEC 77's and already tested there — so what is pinned here is
 * that this endpoint asks IT and validates accordingly, which is the coupling
 * that would break in silence.
 *
 * Four rules worth stating out loud, because all four are easy to "fix" into
 * bugs:
 *
 * - `stars` REFUSES BOOLEANS. (int) TRUE is 1, and 1 IS a valid star value, so
 *   a bare cast turns `"stars": true` into a one-star rating nobody wrote.
 * - `stars` ACCEPTS "5" AS 5. A JSON client that quotes its numbers is not
 *   wrong, and the two must produce the identical verdict.
 * - `close_reason` IS REQUIRED while the cancellation's `reason` is optional.
 *   Closing with nothing awarded leaves the providers who bid hanging, and that
 *   entry is the only thing left to explain it to them.
 * - THE LENGTH LIMIT COUNTS CHARACTERS, NOT BYTES, and it is 1000 and not the
 *   cancellation's 255. The residents of this product write in Spanish, so 1000
 *   accented characters must fit — hence the accented cases below.
 */
class ServiceRequestCloseTest extends TestCase {

  /* -------------------------------------------------------------------------
   * Shape A — closing a job: stars required, comment optional.
   * ---------------------------------------------------------------------- */

  /**
   * No stars at all is the one refusal the app can act on: it knows which field
   * to ask for, because @field names it.
   */
  public function testShapeAWithoutStarsIsMissingField() {
    foreach ([NULL, [], ['comment' => 'x'], 'stars'] as $body) {
      $result = myapi_service_request_validate_close_body($body, TRUE);

      $this->assertFalse($result['ok']);
      $this->assertSame('missing_field', $result['error_code']);
      $this->assertSame(['@field' => 'stars'], $result['replacements']);
    }
  }

  /**
   * 5 and "5" are the same rating. A client that quotes its numbers to keep
   * them exact is not sending a different value, and the two verdicts must be
   * identical down to the type of `stars`.
   */
  public function testIntegerAndDigitStringStarsAreIdentical() {
    $from_int = myapi_service_request_validate_close_body(['stars' => 5], TRUE);
    $from_string = myapi_service_request_validate_close_body(['stars' => '5'], TRUE);

    $this->assertTrue($from_int['ok']);
    $this->assertSame(5, $from_int['values']['stars']);
    $this->assertSame($from_int, $from_string);
  }

  /**
   * Every star value of the catalogue is accepted, and it is the catalogue that
   * is asked — not a literal 1..5 written in the validator.
   */
  public function testEveryCatalogueStarValueIsAccepted() {
    foreach (array_keys(myapi_services_star_values()) as $stars) {
      $result = myapi_service_request_validate_close_body(['stars' => $stars], TRUE);

      $this->assertTrue($result['ok'], 'Star value ' . $stars . ' must be accepted.');
      $this->assertSame($stars, $result['values']['stars']);
    }
  }

  /**
   * The refusals, and the two that matter most are TRUE and 2.5: the first
   * because (int) TRUE is a valid star value, the second because it sits inside
   * the range without being a star.
   */
  public function testInvalidStarsAreRefused() {
    $cases = [
      'below the scale'    => 0,
      'above the scale'    => 6,
      'negative'           => -1,
      'a fraction'         => 2.5,
      'a whole float'      => 5.0,
      'a word'             => 'abc',
      'a signed string'    => '-3',
      'a decimal string'   => '1.5',
      'an empty string'    => '',
      'TRUE'               => TRUE,
      'FALSE'              => FALSE,
      'an array'           => [],
      'NULL'               => NULL,
    ];

    foreach ($cases as $label => $stars) {
      $result = myapi_service_request_validate_close_body(['stars' => $stars], TRUE);

      $this->assertFalse($result['ok'], $label . ' must not be a rating.');
      $this->assertSame('invalid_field', $result['error_code'], $label);
      $this->assertSame(['@field' => 'stars'], $result['replacements'], $label);
    }
  }

  /**
   * The same matrix straight against the resolver, which is where "what counts
   * as a star value" is decided once for the whole endpoint.
   */
  public function testParseStarsResolvesOnlyIntegersAndDigitStrings() {
    $this->assertSame(5, myapi_service_request_parse_stars(5));
    $this->assertSame(5, myapi_service_request_parse_stars('5'));
    $this->assertSame(1, myapi_service_request_parse_stars(1));

    foreach ([0, 6, -1, 2.5, 5.0, 'abc', '-3', '1.5', '', TRUE, FALSE, NULL, []] as $raw) {
      $this->assertNull(myapi_service_request_parse_stars($raw));
    }
  }

  /**
   * An absent, empty or whitespace-only comment is NOT an error: the comment is
   * optional, and a resident who rates without writing owes nobody words.
   */
  public function testAbsentOrBlankCommentIsNull() {
    foreach ([NULL, '', '   ', "\n\t "] as $comment) {
      $body = ['stars' => 4];
      if ($comment !== NULL) {
        $body['comment'] = $comment;
      }

      $result = myapi_service_request_validate_close_body($body, TRUE);

      $this->assertTrue($result['ok']);
      $this->assertNull($result['values']['comment']);
    }
  }

  /**
   * A comment that is there comes back trimmed, and nothing else is done to it:
   * field_rating_comment is stored raw and escaped by whoever renders it.
   */
  public function testCommentIsTrimmedAndOtherwiseUntouched() {
    $result = myapi_service_request_validate_close_body(
      ['stars' => 5, 'comment' => "  Llegó puntual & dejó todo limpio.  "],
      TRUE
    );

    $this->assertTrue($result['ok']);
    $this->assertSame('Llegó puntual & dejó todo limpio.', $result['values']['comment']);
  }

  /**
   * Present with a type other than string IS an error: the client sent the key
   * on purpose and sent something that is not a comment.
   */
  public function testNonStringCommentIsInvalidField() {
    foreach ([5, 2.5, TRUE, [], ['a']] as $comment) {
      $result = myapi_service_request_validate_close_body(
        ['stars' => 5, 'comment' => $comment],
        TRUE
      );

      $this->assertFalse($result['ok']);
      $this->assertSame('invalid_field', $result['error_code']);
      $this->assertSame(['@field' => 'comment'], $result['replacements']);
    }
  }

  /**
   * Exactly 1000 fits and 1001 does not. The limit is 1000 and not the
   * cancellation's 255: this is an opinion about a service, not a label.
   */
  public function testCommentLengthBoundary() {
    $ok = myapi_service_request_validate_close_body(
      ['stars' => 5, 'comment' => str_repeat('a', 1000)],
      TRUE
    );
    $this->assertTrue($ok['ok']);
    $this->assertSame(1000, drupal_strlen($ok['values']['comment']));

    $too_long = myapi_service_request_validate_close_body(
      ['stars' => 5, 'comment' => str_repeat('a', 1001)],
      TRUE
    );
    $this->assertFalse($too_long['ok']);
    $this->assertSame('field_too_long', $too_long['error_code']);
    $this->assertSame(['@field' => 'comment'], $too_long['replacements']);
  }

  /**
   * CHARACTERS, NOT BYTES. 1000 accented characters are 2000 bytes in UTF-8, so
   * a strlen() here would reject a comment that is exactly at the limit.
   */
  public function testAccentedCommentAtTheLimitFits() {
    $result = myapi_service_request_validate_close_body(
      ['stars' => 5, 'comment' => str_repeat('á', 1000)],
      TRUE
    );

    $this->assertTrue($result['ok']);
    $this->assertSame(1000, drupal_strlen($result['values']['comment']));
    $this->assertSame(2000, strlen($result['values']['comment']));
  }

  /**
   * close_reason is IGNORED IN SILENCE on this shape, and it is not a 422: an
   * app that sends the whole form in both cases has to work. Here the rating's
   * comment IS the text of the close.
   */
  public function testCloseReasonIsIgnoredWhenRatingIsRequired() {
    $result = myapi_service_request_validate_close_body(
      ['stars' => 3, 'close_reason' => str_repeat('z', 5000)],
      TRUE
    );

    $this->assertTrue($result['ok']);
    $this->assertSame(3, $result['values']['stars']);
    $this->assertNull($result['values']['close_reason']);
  }

  /* -------------------------------------------------------------------------
   * Shape B — closing with nothing awarded: close_reason and nothing else.
   * ---------------------------------------------------------------------- */

  /**
   * Absent, empty and whitespace-only all mean the same thing and all answer
   * missing_field — NOT invalid_field: a reason made of spaces explains nothing
   * to the providers who bid, and the app has to be told which field to ask
   * for.
   */
  public function testShapeBWithoutACloseReasonIsMissingField() {
    $bodies = [
      NULL,
      [],
      ['stars' => 5],
      ['close_reason' => ''],
      ['close_reason' => '   '],
      ['close_reason' => "\n\t "],
    ];

    foreach ($bodies as $body) {
      $result = myapi_service_request_validate_close_body($body, FALSE);

      $this->assertFalse($result['ok']);
      $this->assertSame('missing_field', $result['error_code']);
      $this->assertSame(['@field' => 'close_reason'], $result['replacements']);
    }
  }

  /**
   * Present with the wrong type IS invalid_field and not missing_field: the key
   * arrived, the value is not a reason.
   */
  public function testNonStringCloseReasonIsInvalidField() {
    foreach ([5, 2.5, TRUE, [], NULL] as $reason) {
      $result = myapi_service_request_validate_close_body(
        ['close_reason' => $reason],
        FALSE
      );

      $this->assertFalse($result['ok']);
      $this->assertSame('invalid_field', $result['error_code']);
      $this->assertSame(['@field' => 'close_reason'], $result['replacements']);
    }
  }

  /**
   * A valid reason comes back trimmed, and the two rating keys come back NULL:
   * the three keys always travel, whichever shape ran.
   */
  public function testValidCloseReasonComesBackTrimmed() {
    $result = myapi_service_request_validate_close_body(
      ['close_reason' => '  Lo resolví con un conocido.  '],
      FALSE
    );

    $this->assertTrue($result['ok']);
    $this->assertSame('Lo resolví con un conocido.', $result['values']['close_reason']);
    $this->assertNull($result['values']['stars']);
    $this->assertNull($result['values']['comment']);
  }

  /**
   * 1000 characters fit, 1001 do not, and 1000 accented ones fit too.
   */
  public function testCloseReasonLengthBoundary() {
    $ok = myapi_service_request_validate_close_body(
      ['close_reason' => str_repeat('a', 1000)],
      FALSE
    );
    $this->assertTrue($ok['ok']);

    $accented = myapi_service_request_validate_close_body(
      ['close_reason' => str_repeat('ñ', 1000)],
      FALSE
    );
    $this->assertTrue($accented['ok']);
    $this->assertSame(1000, drupal_strlen($accented['values']['close_reason']));

    $too_long = myapi_service_request_validate_close_body(
      ['close_reason' => str_repeat('a', 1001)],
      FALSE
    );
    $this->assertFalse($too_long['ok']);
    $this->assertSame('field_too_long', $too_long['error_code']);
    $this->assertSame(['@field' => 'close_reason'], $too_long['replacements']);
  }

  /**
   * stars and comment are IGNORED IN SILENCE on this shape — including values
   * that would be refused on the other one. There is nobody to rate, so nothing
   * about them is validated.
   */
  public function testStarsAndCommentAreIgnoredWhenNoRatingIsRequired() {
    $result = myapi_service_request_validate_close_body(
      [
        'close_reason' => 'Ya no lo necesito.',
        'stars'        => 99,
        'comment'      => str_repeat('z', 5000),
      ],
      FALSE
    );

    $this->assertTrue($result['ok']);
    $this->assertSame('Ya no lo necesito.', $result['values']['close_reason']);
    $this->assertNull($result['values']['stars']);
    $this->assertNull($result['values']['comment']);
  }

  /**
   * Whichever shape ran, the values array carries the same three keys. The
   * endpoint reads all three unconditionally when it builds the timeline text,
   * so a missing key would be a notice in production.
   */
  public function testTheValuesArrayAlwaysCarriesTheThreeKeys() {
    $shape_a = myapi_service_request_validate_close_body(['stars' => 5], TRUE);
    $shape_b = myapi_service_request_validate_close_body(['close_reason' => 'x'], FALSE);

    foreach ([$shape_a, $shape_b] as $result) {
      $this->assertSame(
        ['stars', 'comment', 'close_reason'],
        array_keys($result['values'])
      );
    }
  }

  /* -------------------------------------------------------------------------
   * The status is what picks the shape.
   * ---------------------------------------------------------------------- */

  /**
   * The coupling this endpoint rests on. myapi_services_close_requires_rating()
   * is SPEC 77's and is tested there; what is pinned here is that its answer is
   * what the validator is handed, so 'assigned' and 'direct' demand stars while
   * 'offered' demands a reason. Getting this backwards would let a resident
   * close an awarded job without rating anybody — the exact state that function
   * exists to forbid.
   */
  public function testTheStatusDecidesWhichShapeIsDemanded() {
    $rating_statuses = [
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
    ];

    foreach ($rating_statuses as $status) {
      $requires_rating = myapi_services_close_requires_rating($status);
      $this->assertTrue($requires_rating, $status . ' must demand a rating.');

      // Stars alone are enough...
      $with_stars = myapi_service_request_validate_close_body(['stars' => 4], $requires_rating);
      $this->assertTrue($with_stars['ok'], $status);

      // ...and a reason alone is not.
      $with_reason = myapi_service_request_validate_close_body(['close_reason' => 'x'], $requires_rating);
      $this->assertFalse($with_reason['ok'], $status);
      $this->assertSame(['@field' => 'stars'], $with_reason['replacements'], $status);
    }

    $requires_rating = myapi_services_close_requires_rating(MYAPI_SERVICES_REQUEST_STATUS_OFFERED);
    $this->assertFalse($requires_rating, "'offered' must not demand a rating.");

    $with_reason = myapi_service_request_validate_close_body(['close_reason' => 'x'], $requires_rating);
    $this->assertTrue($with_reason['ok']);

    $with_stars = myapi_service_request_validate_close_body(['stars' => 4], $requires_rating);
    $this->assertFalse($with_stars['ok']);
    $this->assertSame(['@field' => 'close_reason'], $with_stars['replacements']);
  }

  /* -------------------------------------------------------------------------
   * The timeline entry.
   * ---------------------------------------------------------------------- */

  /**
   * Closed with nothing awarded: the resident's own words, VERBATIM. No prefix
   * and no label — the providers whose offers were left hanging are the
   * audience of that sentence, and the timeline already says who wrote the
   * entry and what status it carries.
   */
  public function testCloseCommentWithNoRatingIsTheReasonVerbatim() {
    $reason = 'Lo resolví con un conocido, ya no necesito el servicio.';

    $this->assertSame(
      $reason,
      myapi_service_transaction_close_comment($reason, NULL, NULL)
    );

    // The provider name is irrelevant on this branch: without stars there is no
    // rating to describe.
    $this->assertSame(
      $reason,
      myapi_service_transaction_close_comment($reason, NULL, 'Plomería Ruiz')
    );
  }

  /**
   * Closed with a rating and a known provider: who was rated, and with how many
   * stars.
   */
  public function testCloseCommentWithARatingNamesTheProviderAndTheStars() {
    $comment = myapi_service_transaction_close_comment(NULL, 5, 'Plomería Ruiz');

    $this->assertSame(
      'Servicio cerrado. Plomería Ruiz calificado con 5 estrellas.',
      $comment
    );
  }

  /**
   * Closed with a rating and no provider name: still a whole sentence, never
   * one that stops mid-word. Unreachable from the endpoint — the gate refuses a
   * close with no assigned provider — and written because a pure function
   * handed NULL must still answer something readable.
   */
  public function testCloseCommentWithoutAProviderNameIsStillASentence() {
    foreach ([NULL, '', '   '] as $name) {
      $this->assertSame(
        'Servicio cerrado y calificado con 3 estrellas.',
        myapi_service_transaction_close_comment(NULL, 3, $name)
      );
    }
  }

  /**
   * The stars decide the branch, not the reason: a close that carries both —
   * which the endpoint never produces, since each shape nulls the other key —
   * still records the rating, because that is the act worth keeping.
   */
  public function testStarsWinOverAReasonWhenBothArrive() {
    $comment = myapi_service_transaction_close_comment('Ya no lo necesito.', 4, 'Plomería Ruiz');

    $this->assertSame(
      'Servicio cerrado. Plomería Ruiz calificado con 4 estrellas.',
      $comment
    );
  }

  /**
   * A digit string of stars reads the same as the integer, so a value that came
   * back from the field API as a string cannot silently drop the rating branch.
   */
  public function testDigitStringStarsProduceTheRatedSentence() {
    $this->assertSame(
      myapi_service_transaction_close_comment(NULL, 5, 'Plomería Ruiz'),
      myapi_service_transaction_close_comment(NULL, '5', 'Plomería Ruiz')
    );
  }

  /**
   * NEVER EMPTY, which is the promise SPEC 92 made for every transaction of
   * this bundle. The last case — neither a reason nor stars — is unreachable
   * from the endpoint and is exactly why the fallback exists.
   */
  public function testNoCombinationEverProducesAnEmptyComment() {
    $reasons = [NULL, '', '   ', 'Ya no lo necesito.'];
    $stars = [NULL, '', 0, 3, '5', 'abc'];
    $names = [NULL, '', '   ', 'Plomería Ruiz'];

    foreach ($reasons as $reason) {
      foreach ($stars as $star) {
        foreach ($names as $name) {
          $comment = myapi_service_transaction_close_comment($reason, $star, $name);

          $this->assertIsString($comment);
          $this->assertNotSame('', trim($comment));
        }
      }
    }
  }

}
