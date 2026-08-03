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

}
