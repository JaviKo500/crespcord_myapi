<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/claim.resource.inc';

/**
 * Unit tests for the query-string helpers GET /api/v1/claims owns (SPEC 64), in
 * resources/claim.resource.inc:
 *
 *   - myapi_claim_valid_date()
 *   - myapi_claim_parse_date_range()
 *   - myapi_claim_include_transactions()
 *
 * All three are DB-free: the first is pure, and the other two only read $_GET,
 * so they are exercised here by writing $_GET directly (same style as
 * ReservationListFilterTest). Everything they decide is the lax-parsing
 * contract of the endpoint — an invalid value falls back to its default in
 * silence, and no 422 is ever raised — which is precisely the kind of rule
 * that is cheap to break by accident and invisible until a client notices.
 *
 * Deliberately NOT tested here, and said out loud rather than skipped in
 * silence (same criterion as ClaimsStatusFilterTest):
 *
 *   - myapi_claims_valid_status() and myapi_claims_valid_claim_type(), which
 *     validate '?status' and '?claim_type'. This endpoint does not own them:
 *     they live in includes/myapi.claims_common.inc, shared verbatim with the
 *     back-office listing, and ClaimsStatusFilterTest has covered them since
 *     SPEC 62. Repeating the asserts here would mean two tests to update the
 *     next time the catalogue changes — the same synchronisation problem
 *     SPEC 64 just removed from the production code.
 *   - myapi_claim_parse_condominium_id(), which calls myapi_error() (and thus
 *     drupal_exit()) for a foreign condominium: the 403 is in the spec's manual
 *     acceptance matrix, not here.
 *   - Everything from myapi_claim_base_query() down, which is SQL.
 */
class ClaimListFilterTest extends TestCase {

  protected function setUp(): void {
    $_GET = [];
  }

  protected function tearDown(): void {
    $_GET = [];
  }

  /* ---- myapi_claim_valid_date() ---- */

  public function testValidDateAcceptsRealIsoDates() {
    $this->assertSame('2026-08-01', myapi_claim_valid_date('2026-08-01'));
    $this->assertSame('2026-12-31', myapi_claim_valid_date('2026-12-31'));
    // A leap day that exists: 2028 is a leap year.
    $this->assertSame('2028-02-29', myapi_claim_valid_date('2028-02-29'));
  }

  /**
   * The reason checkdate() has the last word and the regex alone is not
   * enough: these all match 'YYYY-MM-DD' and are not dates.
   */
  public function testValidDateRejectsNonExistentCalendarDates() {
    $this->assertNull(myapi_claim_valid_date('2026-02-30'));
    $this->assertNull(myapi_claim_valid_date('2026-02-29'));
    $this->assertNull(myapi_claim_valid_date('2026-13-01'));
    $this->assertNull(myapi_claim_valid_date('2026-00-10'));
    $this->assertNull(myapi_claim_valid_date('2026-04-31'));
  }

  public function testValidDateRejectsMalformedValues() {
    // Zero padding is required, and no other separator or format is accepted.
    $this->assertNull(myapi_claim_valid_date('2026-8-1'));
    $this->assertNull(myapi_claim_valid_date('01/08/2026'));
    $this->assertNull(myapi_claim_valid_date('2026-08-01 14:30:00'));
    $this->assertNull(myapi_claim_valid_date('ayer'));
    $this->assertNull(myapi_claim_valid_date(''));
    $this->assertNull(myapi_claim_valid_date(NULL));
    // $_GET can hand over an array ('?date_from[]=x'); it must not fatal.
    $this->assertNull(myapi_claim_valid_date(['2026-08-01']));
    $this->assertNull(myapi_claim_valid_date(20260801));
  }

  /* ---- myapi_claim_parse_date_range() ---- */

  public function testParseReturnsBothNullsWhenNothingIsSent() {
    $this->assertSame(
      ['from' => NULL, 'to' => NULL],
      myapi_claim_parse_date_range()
    );
  }

  public function testParseKeepsAWellFormedRange() {
    $_GET['date_from'] = '2026-08-01';
    $_GET['date_to'] = '2026-08-31';
    $this->assertSame(
      ['from' => '2026-08-01', 'to' => '2026-08-31'],
      myapi_claim_parse_date_range()
    );
  }

  /**
   * Both bounds are inclusive, so a single-day range is a legitimate range and
   * not an inverted one.
   */
  public function testParseKeepsASingleDayRange() {
    $_GET['date_from'] = '2026-08-01';
    $_GET['date_to'] = '2026-08-01';
    $this->assertSame(
      ['from' => '2026-08-01', 'to' => '2026-08-01'],
      myapi_claim_parse_date_range()
    );
  }

  public function testParseKeepsASingleOpenBound() {
    $_GET['date_from'] = '2026-08-01';
    $this->assertSame(
      ['from' => '2026-08-01', 'to' => NULL],
      myapi_claim_parse_date_range()
    );

    $_GET = ['date_to' => '2026-08-31'];
    $this->assertSame(
      ['from' => NULL, 'to' => '2026-08-31'],
      myapi_claim_parse_date_range()
    );
  }

  /**
   * An inverted range discards the WHOLE filter, both bounds: keeping the
   * valid-looking half would answer a range the client never asked for.
   */
  public function testParseDropsTheWholeFilterWhenTheRangeIsInverted() {
    $_GET['date_from'] = '2026-08-31';
    $_GET['date_to'] = '2026-08-01';
    $this->assertSame(
      ['from' => NULL, 'to' => NULL],
      myapi_claim_parse_date_range()
    );
  }

  /**
   * A bad bound is ignored on its own; the other one survives. No 422 anywhere
   * — that is the endpoint's contract for every parameter but condominium_id.
   */
  public function testParseIgnoresABadBoundAndKeepsTheGoodOne() {
    $_GET['date_from'] = '2026-02-30';
    $_GET['date_to'] = '2026-08-31';
    $this->assertSame(
      ['from' => NULL, 'to' => '2026-08-31'],
      myapi_claim_parse_date_range()
    );

    $_GET = ['date_from' => '2026-08-01', 'date_to' => 'manana'];
    $this->assertSame(
      ['from' => '2026-08-01', 'to' => NULL],
      myapi_claim_parse_date_range()
    );
  }

  public function testParseIgnoresBothBoundsWhenBothAreMalformed() {
    $_GET['date_from'] = '01/08/2026';
    $_GET['date_to'] = '';
    $this->assertSame(
      ['from' => NULL, 'to' => NULL],
      myapi_claim_parse_date_range()
    );
  }

  /* ---- myapi_claim_include_transactions() ---- */

  public function testIncludeExpandsOnTheExactValue() {
    $_GET['include'] = 'transactions';
    $this->assertTrue(myapi_claim_include_transactions());
  }

  public function testIncludeCollapsesWhenAbsent() {
    $this->assertFalse(myapi_claim_include_transactions());
  }

  /**
   * The comparison is strict and exact: no case folding, no trimming, and no
   * comma-separated list until a spec says what the other members mean. Every
   * one of these collapses the transactions to ids instead of raising a 422.
   */
  public function testIncludeCollapsesOnAnyOtherValue() {
    foreach (['Transactions', 'transactions,images', 'transaction', ' transactions', 'transactions ', 'images', '1', ''] as $value) {
      $_GET['include'] = $value;
      $this->assertFalse(
        myapi_claim_include_transactions(),
        sprintf('?include=%s must not expand the transactions.', var_export($value, TRUE))
      );
    }
  }

  /**
   * '?include[]=transactions' hands over an array. It must collapse, not fatal
   * on a string comparison against an array.
   */
  public function testIncludeCollapsesOnANonStringValue() {
    $_GET['include'] = ['transactions'];
    $this->assertFalse(myapi_claim_include_transactions());
  }

}
