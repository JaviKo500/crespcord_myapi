<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.flood.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../resources/auth.resource.inc';

/**
 * Unit tests for logging in with an email address (SPEC 120).
 *
 * POST /api/v1/auth/login used to resolve the account with a single
 * user_load_by_name(), so the only string that opened a session was the
 * username. It now takes either form in that same 'username' field, and the
 * change splits into two pieces that a test can reach without a database —
 * which is the whole reason they are functions and not four lines inlined in
 * the endpoint:
 *
 *  - myapi_user_load_by_identifier(), the resolution itself: which column is
 *    asked, in which order, and when the second one is not asked at all.
 *  - myapi_auth_login_flood_subjects(), the brute-force accounting: WHICH
 *    counters one attempt is charged against. This is the half that is easy to
 *    get wrong and impossible to notice, because a rate limit that silently
 *    doubles still answers 200 to every legitimate login.
 *
 * What stays out is the credential check itself (user_check_password() over a
 * real hash) and the token row: those live in the integration suite, over real
 * HTTP against a real database — tests/integration/MyapiAuthTestCase.test.
 */
class AuthLoginIdentifierTest extends TestCase {

  protected function setUp(): void {
    $GLOBALS['myapi_test_user_lookups'] = [];
    $GLOBALS['myapi_test_users'] = [
      7 => ['uid' => 7, 'name' => 'Javier', 'mail' => 'Javier@Lamotora.com', 'status' => 1],
      8 => ['uid' => 8, 'name' => 'ana', 'mail' => 'ana@lamotora.com', 'status' => 1],
      9 => ['uid' => 9, 'name' => 'blocked', 'mail' => 'blocked@lamotora.com', 'status' => 0],
    ];
  }

  protected function tearDown(): void {
    unset($GLOBALS['myapi_test_users'], $GLOBALS['myapi_test_user_lookups']);
  }

  /**
   * The columns that were queried, in order.
   *
   * @return array  e.g. ['name', 'mail'].
   */
  private function lookups() {
    return array_column($GLOBALS['myapi_test_user_lookups'], 'column');
  }

  // ---------------------------------------------------------------------
  // myapi_user_load_by_identifier() — resolution
  // ---------------------------------------------------------------------

  /**
   * A username resolves, and costs exactly one lookup.
   *
   * The second half matters as much as the first: this is the path every
   * existing client takes on every login, and it must not have grown a query.
   */
  public function testAUsernameResolvesWithASingleLookup() {
    $account = myapi_user_load_by_identifier('Javier');

    $this->assertSame(7, (int) $account->uid);
    $this->assertSame(['name'], $this->lookups());
  }

  /**
   * An email address resolves, through the mail column, after the name column
   * came back empty.
   */
  public function testAnEmailResolvesThroughTheMailColumn() {
    $account = myapi_user_load_by_identifier('ana@lamotora.com');

    $this->assertSame(8, (int) $account->uid);
    $this->assertSame(['name', 'mail'], $this->lookups());
  }

  /**
   * A string with no '@' never asks the mail column: it cannot be an address,
   * so the query could only come back empty.
   */
  public function testAnIdentifierWithoutAnAtSignNeverAsksForTheMail() {
    $this->assertFalse(myapi_user_load_by_identifier('no-such-user'));

    $this->assertSame(['name'], $this->lookups());
  }

  /**
   * An unknown address does ask both columns, and still answers FALSE.
   */
  public function testAnUnknownAddressAsksBothColumnsAndResolvesToNothing() {
    $this->assertFalse(myapi_user_load_by_identifier('nobody@lamotora.com'));

    $this->assertSame(['name', 'mail'], $this->lookups());
  }

  /**
   * THE USERNAME WINS. An account whose NAME is an email string keeps logging
   * in with it, even when that same string is a DIFFERENT account's address.
   *
   * Drupal 7 allows '@' in a username, so this collision is possible on a real
   * site, and the order is the only thing that decides it. Resolving it the
   * other way round would silently move an existing user's login to somebody
   * else's account — the one regression this feature could have introduced
   * that nobody would report as a bug, because the victim just sees a wrong
   * password.
   */
  public function testAUsernameThatLooksLikeAnEmailBeatsTheAddressOfAnotherAccount() {
    $GLOBALS['myapi_test_users'][10] = ['uid' => 10, 'name' => 'ana@lamotora.com', 'mail' => 'other@lamotora.com', 'status' => 1];

    $account = myapi_user_load_by_identifier('ana@lamotora.com');

    $this->assertSame(10, (int) $account->uid, 'resolved by name, not by the mail of uid 8');
    $this->assertSame(['name'], $this->lookups(), 'the mail column was never reached');
  }

  /**
   * Case does not matter, on either column: the real lookups run under a
   * case-insensitive collation and the address a user types is whatever their
   * keyboard did.
   */
  public function testResolutionIsCaseInsensitiveOnBothColumns() {
    $this->assertSame(7, (int) myapi_user_load_by_identifier('JAVIER')->uid);
    $this->assertSame(7, (int) myapi_user_load_by_identifier('javier@lamotora.com')->uid);
  }

  /**
   * Surrounding whitespace is trimmed — phone keyboards append a space after
   * an autocompleted address, and that space is not part of anyone's login.
   */
  public function testSurroundingWhitespaceIsTrimmed() {
    $this->assertSame(8, (int) myapi_user_load_by_identifier('  ana@lamotora.com  ')->uid);
  }

  /**
   * Nothing usable resolves to FALSE without touching a column: an empty
   * string, whitespace, or a value that is not a string at all.
   *
   * The endpoint rejects those at validation long before this point, but the
   * function is shared and must not answer the anonymous row (whose name and
   * mail are empty) to a caller that asked for nothing.
   */
  public function testUnusableIdentifiersResolveToNothingWithoutAQuery() {
    foreach (['', '   ', NULL, 42, ['a'], TRUE] as $value) {
      $label = var_export($value, TRUE);

      $this->assertFalse(myapi_user_load_by_identifier($value), $label);
      $this->assertSame([], $this->lookups(), $label);
    }
  }

  // ---------------------------------------------------------------------
  // myapi_auth_login_flood_subject() — the folded counter subject
  // ---------------------------------------------------------------------

  /**
   * The subject is the identifier trimmed and lowercased.
   *
   * Before SPEC 120 the raw string was used, so 'Javier' and 'javier' — both
   * of which log in — spent two separate allowances of five. Folding them
   * closes that: five attempts are five, however they are capitalised.
   */
  public function testTheFloodSubjectIsFolded() {
    $this->assertSame('javier', myapi_auth_login_flood_subject('Javier'));
    $this->assertSame('javier', myapi_auth_login_flood_subject('  JAVIER '));
    $this->assertSame('ana@lamotora.com', myapi_auth_login_flood_subject('Ana@Lamotora.com'));
  }

  // ---------------------------------------------------------------------
  // myapi_auth_login_flood_subjects() — what one attempt is charged against
  // ---------------------------------------------------------------------

  /**
   * Logging in by username charges ONE subject.
   *
   * The username is already the account's name, so there is no second counter
   * to charge and the behaviour is exactly what it was before SPEC 120.
   */
  public function testAnAttemptByUsernameChargesOneSubject() {
    $account = myapi_user_load_by_identifier('Javier');

    $this->assertSame(['javier'], myapi_auth_login_flood_subjects('Javier', $account));
  }

  /**
   * Logging in by EMAIL charges TWO: the address AND the username behind it.
   *
   * This is the point of the whole helper. Charging only the address would
   * hand an attacker a second allowance of five against the same password —
   * five by username, five more by email — and doubling a brute-force limit
   * without changing any visible behaviour is exactly the kind of regression
   * that ships unnoticed.
   */
  public function testAnAttemptByEmailChargesTheAddressAndTheUsernameBehindIt() {
    $account = myapi_user_load_by_identifier('ana@lamotora.com');

    $this->assertSame(['ana@lamotora.com', 'ana'], myapi_auth_login_flood_subjects('ana@lamotora.com', $account));
  }

  /**
   * The typed identifier is always FIRST.
   *
   * The endpoint checks that one before loading the account, which is what
   * keeps a request that is already over the limit from costing a query.
   */
  public function testTheTypedIdentifierComesFirst() {
    $account = myapi_user_load_by_identifier('ana@lamotora.com');

    $subjects = myapi_auth_login_flood_subjects('ana@lamotora.com', $account);

    $this->assertSame(myapi_auth_login_flood_subject('ana@lamotora.com'), reset($subjects));
  }

  /**
   * A BLOCKED account still charges both subjects.
   *
   * A failed attempt is a failed attempt: if a disabled account's address were
   * cheaper to hammer than its username, the block would be the way to find
   * the unlimited door.
   */
  public function testABlockedAccountStillChargesBothSubjects() {
    $account = myapi_user_load_by_identifier('blocked@lamotora.com');

    $this->assertSame(0, (int) $account->status, 'fixture precondition');
    $this->assertSame(['blocked@lamotora.com', 'blocked'], myapi_auth_login_flood_subjects('blocked@lamotora.com', $account));
  }

  /**
   * An identifier that resolves to nothing charges the one subject it has.
   *
   * There is no username to add, and inventing one would leak: two identifiers
   * charged to the same counter can be told apart by watching when the limit
   * hits.
   */
  public function testAnUnknownIdentifierChargesOnlyItself() {
    $account = myapi_user_load_by_identifier('nobody@lamotora.com');

    $this->assertFalse($account, 'fixture precondition');
    $this->assertSame(['nobody@lamotora.com'], myapi_auth_login_flood_subjects('nobody@lamotora.com', $account));
  }

  /**
   * The subjects are deduplicated, whatever the capitalisation.
   *
   * Typing the username with different case must not charge the same counter
   * twice — that would spend an attempt at double speed and lock a legitimate
   * user out in three tries instead of five.
   */
  public function testTheSameSubjectIsNeverChargedTwice() {
    $account = myapi_user_load_by_identifier('JAVIER');

    $this->assertSame(['javier'], myapi_auth_login_flood_subjects('JAVIER', $account));
  }

  /**
   * uid 0 is not an account. The anonymous row's name is the empty string, and
   * folding it would give every unresolved attempt one shared counter — the
   * first attacker to exhaust it would lock out every failed login on the site.
   */
  public function testTheAnonymousRowIsNeverASubject() {
    $anonymous = (object) ['uid' => 0, 'name' => '', 'status' => 1];

    $this->assertSame(['ghost'], myapi_auth_login_flood_subjects('ghost', $anonymous));
  }

  // ---------------------------------------------------------------------
  // The endpoint wiring
  // ---------------------------------------------------------------------

  /**
   * The endpoint resolves through the shared helper and no longer calls
   * user_load_by_name() itself.
   *
   * Asserted on the source because the body of myapi_auth_login() cannot be
   * reached from a unit test — myapi_request_body() reads php://input, which a
   * CLI process cannot write. A direct user_load_by_name() left behind would
   * quietly restore username-only login for whichever path still used it,
   * while every test above kept passing.
   */
  public function testTheEndpointResolvesThroughTheSharedHelper() {
    $source = file_get_contents(__DIR__ . '/../../resources/auth.resource.inc');
    $login = substr($source, strpos($source, 'function myapi_auth_login()'));
    $login = substr($login, 0, strpos($login, "\n}\n") + 3);

    $this->assertStringContainsString('myapi_user_load_by_identifier(', $login);
    $this->assertStringNotContainsString('user_load_by_name(', $login);
  }

  /**
   * And it charges every subject the helper lists — the registration and the
   * clearing both run over the list, not over the typed string alone.
   */
  public function testTheEndpointChargesEverySubject() {
    $source = file_get_contents(__DIR__ . '/../../resources/auth.resource.inc');
    $login = substr($source, strpos($source, 'function myapi_auth_login()'));
    $login = substr($login, 0, strpos($login, "\n}\n") + 3);

    $this->assertStringContainsString('myapi_auth_login_flood_subjects(', $login);
    $this->assertSame(3, substr_count($login, 'foreach ($subjects as $subject)'), 'gated before the password, registered on failure, cleared on success');
  }

}
