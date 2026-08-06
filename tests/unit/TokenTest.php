<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.token.inc';

/**
 * Unit tests for the pure token helpers in includes/myapi.token.inc.
 *
 * Covers myapi_token_hash(), the myapi_token_generate_*() functions and, since
 * SPEC 73, the three TTL resolvers. What is left out is the persistence half
 * — myapi_token_persist(), myapi_password_reset_token_persist() and
 * myapi_password_reset_token_invalidate_previous() — which is db_insert() and
 * db_update() and belongs to tests/integration.
 */
class TokenTest extends TestCase {

  protected function tearDown(): void {
    unset($GLOBALS['myapi_test_variables']);
  }

  public function testHashIsDeterministic() {
    $this->assertSame(myapi_token_hash('same-input'), myapi_token_hash('same-input'));
  }

  public function testHashIs64HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', myapi_token_hash('anything'));
  }

  public function testGenerateAccessIs64HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', myapi_token_generate_access());
  }

  public function testGenerateAccessDiffersBetweenCalls() {
    $this->assertNotSame(myapi_token_generate_access(), myapi_token_generate_access());
  }

  public function testGenerateRefreshIs128HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', myapi_token_generate_refresh());
  }

  public function testGenerateRefreshDiffersBetweenCalls() {
    $this->assertNotSame(myapi_token_generate_refresh(), myapi_token_generate_refresh());
  }

  public function testGenerateResetIs64HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', myapi_token_generate_reset());
  }

  public function testGenerateResetDiffersBetweenCalls() {
    $this->assertNotSame(myapi_token_generate_reset(), myapi_token_generate_reset());
  }

  // ---------------------------------------------------------------------
  // TTLs (SPEC 73)
  //
  // These three decide how long a session lasts and how long a reset link
  // stays usable, and until SPEC 73 nothing asserted them at any layer: the
  // integration suite exercises them (every login calls the first one) but no
  // assertion reads their value, and 'expires_in' is not checked anywhere. A
  // typo in a variable name would therefore silently pin the TTL to its
  // default for ever, and a wrong default would ship unnoticed.
  // ---------------------------------------------------------------------

  /**
   * The shipped defaults, in seconds: 30 minutes, 30 days and 1 hour.
   *
   * The literals are deliberate — reading the constants back would assert that
   * the code equals itself. These are the numbers docs/auth.md publishes to the
   * app, so they are the numbers the test states.
   */
  public function testDefaultTtls() {
    $this->assertSame(1800, myapi_token_access_ttl(), '30 minutes');
    $this->assertSame(2592000, myapi_token_refresh_ttl(), '30 days');
    $this->assertSame(3600, myapi_password_reset_ttl(), '1 hour');
  }

  /**
   * The constants and the resolvers agree, so the schema defaults written at
   * install time and the values served at runtime cannot drift apart.
   */
  public function testResolversMatchTheirConstants() {
    $this->assertSame(MYAPI_TOKEN_ACCESS_TTL_DEFAULT, myapi_token_access_ttl());
    $this->assertSame(MYAPI_TOKEN_REFRESH_TTL_DEFAULT, myapi_token_refresh_ttl());
    $this->assertSame(MYAPI_PASSWORD_RESET_TTL_DEFAULT, myapi_password_reset_ttl());
  }

  /**
   * Each resolver reads its own variable — the knob `drush vset` is documented
   * to turn.
   *
   * Setting all three at once is what makes this a wiring test and not three
   * separate ones: a copy-paste that left two resolvers reading the same
   * variable name would pass one assertion and fail the others.
   */
  public function testEachResolverReadsItsOwnVariable() {
    $GLOBALS['myapi_test_variables'] = [
      'myapi_token_access_ttl'   => 60,
      'myapi_token_refresh_ttl'  => 120,
      'myapi_password_reset_ttl' => 180,
    ];

    $this->assertSame(60, myapi_token_access_ttl());
    $this->assertSame(120, myapi_token_refresh_ttl());
    $this->assertSame(180, myapi_password_reset_ttl());
  }

  /**
   * An override is returned as the variable stored it.
   *
   * Unlike the flood helpers, these resolvers do NOT cast: `drush vset
   * myapi_token_access_ttl 60` stores the string "60" and that string is what
   * reaches 'expires_in' in the login response, where drupal_json_encode()
   * prints it as "60" and not 60.
   *
   * Pinned rather than fixed because the values that matter — the defaults —
   * are real ints, and the JSON type of an overridden TTL is a runtime-config
   * question, not something an edit should change silently. A future spec that
   * wants 'expires_in' to be an int in every case has to change this test on
   * purpose.
   */
  public function testAnOverriddenTtlIsNotCast() {
    $GLOBALS['myapi_test_variables']['myapi_token_access_ttl'] = '60';

    $this->assertSame('60', myapi_token_access_ttl(), 'string in, string out');
  }

}
