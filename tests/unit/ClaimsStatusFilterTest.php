<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.claims_common.inc';

/**
 * Unit tests for the status whitelist of the claims listing (SPEC 62),
 * myapi_claims_valid_status() in includes/myapi.claims_common.inc — where
 * SPEC 64 moved it from includes/myapi.claims_admin.inc so the read-only
 * claims API could share it with the back office. Only the require_once above
 * changed: the function is the same one, and so is every assert below.
 *
 * The function is the one place that decides which '?status=' values the
 * listing accepts, and it is hard-coded on purpose (see its docblock): it does
 * NOT read field_info_field(), so dropping 'duplicated' from the field's
 * allowed_values in myapi.install would not have narrowed it. These tests pin
 * the four surviving keys and, above all, the one that must now be rejected —
 * a bookmarked '?status=duplicated' has to fall back to "no filter" instead of
 * returning an empty table for a status that no longer exists.
 *
 * Deliberately NOT tested here, and said out loud rather than skipped in
 * silence (same criterion as ClaimTransactionInitialCommentTest):
 *   - myapi_claims_status_options() / myapi_claims_status_label(), which read
 *     the labels off field_info_field() — Field API, i.e. exactly what
 *     tests/unit avoids.
 *   - myapi_update_7021(), which is database and Field API only.
 * Both are in the spec's manual acceptance matrix instead.
 *
 * SPEC 69 added a second block below, for myapi_claims_valid_status_list():
 * the multi-value form of the API filter ('?status=received,in_progress').
 * It lives in this file rather than in ClaimListFilterTest because it is the
 * same whitelist — the list function validates each item by calling
 * myapi_claims_valid_status(), so both blocks move together the next time the
 * catalogue changes.
 */
class ClaimsStatusFilterTest extends TestCase {

  public function testTheFourAllowedStatusesPassThroughUnchanged() {
    foreach (array('received', 'in_progress', 'resolved', 'closed') as $status) {
      $this->assertSame($status, myapi_claims_valid_status($status));
    }
  }

  /**
   * The regression this spec is about: 'duplicated' was the fifth allowed
   * value until SPEC 62.
   */
  public function testDuplicatedIsNoLongerAccepted() {
    $this->assertNull(myapi_claims_valid_status('duplicated'));
  }

  public function testUnknownOrMalformedValuesFallBackToNoFilter() {
    $this->assertNull(myapi_claims_valid_status('inventado'));
    $this->assertNull(myapi_claims_valid_status(''));
    $this->assertNull(myapi_claims_valid_status('Received'));
    $this->assertNull(myapi_claims_valid_status(array('received')));
    $this->assertNull(myapi_claims_valid_status(NULL));
  }

  /**
   * $_GET always hands over strings, but a numeric-looking one must not match
   * by juggling: in_array() is called with strict = TRUE.
   */
  public function testNumericValuesDoNotMatchByJuggling() {
    $this->assertNull(myapi_claims_valid_status(0));
    $this->assertNull(myapi_claims_valid_status('0'));
  }

  /* ---- myapi_claims_valid_status_list() (SPEC 69) ---- */

  /**
   * The contract the API had before SPEC 69 must keep working byte for byte:
   * a single value is now a list of one, never a NULL and never a string.
   */
  public function testSingleStatusBecomesAListOfOne() {
    foreach (array('received', 'in_progress', 'resolved', 'closed') as $status) {
      $this->assertSame(array($status), myapi_claims_valid_status_list($status));
    }
  }

  public function testSeveralStatusesAreAllKeptInOrder() {
    $this->assertSame(
      array('received', 'in_progress'),
      myapi_claims_valid_status_list('received,in_progress')
    );
    $this->assertSame(
      array('received', 'in_progress', 'resolved', 'closed'),
      myapi_claims_valid_status_list('received,in_progress,resolved,closed')
    );
  }

  /**
   * Lax parsing item by item: an unknown value inside the list does not
   * poison the valid ones, which is what makes '?status=received,duplicated'
   * from an old bookmark behave like '?status=received'.
   */
  public function testUnknownItemsAreDroppedAndTheValidOnesStillFilter() {
    $this->assertSame(array('received'), myapi_claims_valid_status_list('received,inventado'));
    $this->assertSame(array('received'), myapi_claims_valid_status_list('received,duplicated'));
    $this->assertSame(array('closed'), myapi_claims_valid_status_list('Received,closed'));
  }

  /**
   * Only when NOTHING survives does the whole filter fall back to "every
   * status" — the same answer '?status=inventado' has given since SPEC 62.
   */
  public function testAListWithNoValidItemMeansNoFilter() {
    $this->assertNull(myapi_claims_valid_status_list('inventado,duplicated'));
    $this->assertNull(myapi_claims_valid_status_list(','));
    $this->assertNull(myapi_claims_valid_status_list(''));
    $this->assertNull(myapi_claims_valid_status_list('0,1'));
  }

  /**
   * Whitespace and empty items come from real query strings — a URL-encoded
   * ', ' separator, a trailing comma appended by a client that builds the
   * list in a loop.
   */
  public function testSpacingAndEmptyItemsAreTolerated() {
    $this->assertSame(array('received', 'closed'), myapi_claims_valid_status_list('received, closed'));
    $this->assertSame(array('received', 'closed'), myapi_claims_valid_status_list('received,,closed,'));
    $this->assertSame(array('received'), myapi_claims_valid_status_list('  received  '));
  }

  /**
   * Duplicates collapse: repeating a status must not repeat the SQL condition
   * nor change the result set.
   */
  public function testDuplicatesCollapse() {
    $this->assertSame(array('received'), myapi_claims_valid_status_list('received,received'));
    $this->assertSame(
      array('received', 'closed'),
      myapi_claims_valid_status_list('received,closed,received')
    );
  }

  /**
   * '?status[]=received' reaches PHP as an array. It is not the documented
   * form, so it falls back to "no filter" like any other unparsable value,
   * rather than half-working.
   */
  public function testNonScalarValuesMeanNoFilter() {
    $this->assertNull(myapi_claims_valid_status_list(array('received')));
    $this->assertNull(myapi_claims_valid_status_list(NULL));
  }

}
