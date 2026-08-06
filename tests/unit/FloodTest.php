<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.flood.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';

/**
 * Unit tests for includes/myapi.flood.inc (SPEC 06, covered by SPEC 73).
 *
 * The brute-force protection of the whole API. Until SPEC 73 it had exactly
 * one test in the entire project — the sixth failed login for the same
 * username, in tests/integration — which left six of the seven protected
 * counters and every configurable threshold unverified.
 *
 * What these three functions are actually responsible for is arithmetic over
 * Drupal variables: resolving a limit and a window out of two variable NAMES
 * and handing them to the Flood API in the right order. The failure mode is
 * silent by construction — a misspelled variable name falls back to the
 * catch-all 10/3600 and a swapped threshold/window still returns a boolean, so
 * a wrong rate limit produces no error anywhere and no test would notice.
 * That is what the recorded stub calls in tests/unit/bootstrap.php make
 * assertable here.
 *
 * The counting itself — the {flood} table, and whether the sixth attempt in an
 * hour is really the one that trips — stays in tests/integration, where a real
 * Drupal sandbox can advance a counter.
 */
class FloodTest extends TestCase {

  /**
   * The wiring the specs describe, as data: event name => [limit variable,
   * window variable, expected limit, expected window].
   *
   * Transcribed from the tables of SPEC 06 ("Variables de configuración") and
   * SPEC 07, NOT read back out of myapi.flood.inc. That is the whole value of
   * the table: it is a second, independent statement of the same numbers, so
   * an edit to the defaults in the code has to be made twice on purpose
   * instead of once by accident.
   */
  private static $wiring = [
    'myapi_login_ip' => [
      'myapi_flood_login_ip_limit', 'myapi_flood_login_ip_window', 20, 3600,
    ],
    'myapi_login_user' => [
      'myapi_flood_login_user_limit', 'myapi_flood_login_user_window', 5, 3600,
    ],
    'myapi_refresh_ip' => [
      'myapi_flood_refresh_ip_limit', 'myapi_flood_refresh_ip_window', 10, 900,
    ],
    'myapi_logout_ip' => [
      'myapi_flood_logout_ip_limit', 'myapi_flood_logout_ip_window', 20, 900,
    ],
    'myapi_forgot_ip' => [
      'myapi_flood_forgot_ip_limit', 'myapi_flood_forgot_ip_window', 10, 3600,
    ],
    'myapi_forgot_identifier' => [
      'myapi_flood_forgot_identifier_limit', 'myapi_flood_forgot_identifier_window', 3, 3600,
    ],
    'myapi_reset_ip' => [
      'myapi_flood_reset_ip_limit', 'myapi_flood_reset_ip_window', 10, 900,
    ],
  ];

  protected function setUp(): void {
    $GLOBALS['myapi_test_flood_calls'] = [];
    unset($GLOBALS['myapi_test_flood_allowed'], $GLOBALS['myapi_test_variables']);

    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');
  }

  protected function tearDown(): void {
    $GLOBALS['myapi_test_flood_calls'] = [];
    unset($GLOBALS['myapi_test_flood_allowed'], $GLOBALS['myapi_test_variables']);
  }

  /**
   * The single call the code under test made to the Flood API.
   *
   * @return array  The recorded call.
   */
  private function theOnlyFloodCall() {
    $this->assertCount(1, $GLOBALS['myapi_test_flood_calls'], 'exactly one Flood API call');

    return $GLOBALS['myapi_test_flood_calls'][0];
  }

  // ---------------------------------------------------------------------
  // myapi_flood_is_allowed()
  // ---------------------------------------------------------------------

  /**
   * Every one of the seven counters resolves to the threshold and window its
   * spec documents.
   *
   * This is the case that would have caught a misspelled variable name: a name
   * that is not in the defaults table silently resolves to 10/3600, which is
   * a plausible-looking rate limit and the wrong one for five of these seven.
   */
  public function testEveryCounterResolvesItsDocumentedThresholdAndWindow() {
    foreach (self::$wiring as $event => list($limit_var, $window_var, $limit, $window)) {
      $GLOBALS['myapi_test_flood_calls'] = [];

      myapi_flood_is_allowed($event, 'subject', $limit_var, $window_var);

      $call = $this->theOnlyFloodCall();
      $this->assertSame($limit, $call['threshold'], $event . ' limit');
      $this->assertSame($window, $call['window'], $event . ' window');
    }
  }

  /**
   * The four arguments reach flood_is_allowed() in ITS order, which is not the
   * order myapi_flood_is_allowed() receives them in:
   *
   *   myapi_flood_is_allowed($event, $identifier, $limit_var, $window_var)
   *   flood_is_allowed($name, $threshold, $window, $identifier)
   *
   * The identifier travels from second place to fourth and the two numbers are
   * built in between. A swap there still returns a boolean and still looks
   * like protection.
   */
  public function testArgumentsReachTheFloodApiInItsOwnOrder() {
    myapi_flood_is_allowed('myapi_login_user', 'ana', 'myapi_flood_login_user_limit', 'myapi_flood_login_user_window');

    $this->assertSame([
      'call'       => 'is_allowed',
      'event'      => 'myapi_login_user',
      'threshold'  => 5,
      'window'     => 3600,
      'identifier' => 'ana',
    ], $this->theOnlyFloodCall());
  }

  /**
   * An unknown variable name falls back to 10 attempts per hour.
   *
   * Pinned because it is the shape of the bug this class exists to catch: the
   * fallback is what makes a typo survive, so a future counter added WITHOUT
   * its entry in the defaults table gets this instead of what its spec says.
   */
  public function testUnknownVariableNamesFallBackToTenPerHour() {
    myapi_flood_is_allowed('myapi_new_thing', 'x', 'myapi_flood_typo_limit', 'myapi_flood_typo_window');

    $call = $this->theOnlyFloodCall();
    $this->assertSame(10, $call['threshold']);
    $this->assertSame(3600, $call['window']);
  }

  /**
   * A variable set on the site overrides the default, which is the runtime
   * knob SPEC 06 promises ("sin cache clear").
   */
  public function testSiteVariablesOverrideTheDefaults() {
    $GLOBALS['myapi_test_variables']['myapi_flood_login_user_limit'] = 3;
    $GLOBALS['myapi_test_variables']['myapi_flood_login_user_window'] = 60;

    myapi_flood_is_allowed('myapi_login_user', 'ana', 'myapi_flood_login_user_limit', 'myapi_flood_login_user_window');

    $call = $this->theOnlyFloodCall();
    $this->assertSame(3, $call['threshold']);
    $this->assertSame(60, $call['window']);
  }

  /**
   * An override is cast to int before it is used.
   *
   * `drush vset myapi_flood_login_user_limit 3` stores the string "3", and
   * flood_is_allowed() compares the threshold with a COUNT() — a string there
   * is a comparison that works by accident until it does not.
   */
  public function testOverridesAreCastToInt() {
    $GLOBALS['myapi_test_variables']['myapi_flood_login_user_limit'] = '3';
    $GLOBALS['myapi_test_variables']['myapi_flood_login_user_window'] = '60';

    myapi_flood_is_allowed('myapi_login_user', 'ana', 'myapi_flood_login_user_limit', 'myapi_flood_login_user_window');

    $call = $this->theOnlyFloodCall();
    $this->assertSame(3, $call['threshold'], 'int, not "3"');
    $this->assertSame(60, $call['window'], 'int, not "60"');
  }

  /**
   * The verdict is the Flood API's, passed straight through — this function
   * decides nothing on its own.
   */
  public function testTheVerdictComesFromTheFloodApi() {
    $GLOBALS['myapi_test_flood_allowed'] = TRUE;
    $this->assertTrue(myapi_flood_is_allowed('myapi_login_ip', '10.0.0.1', 'myapi_flood_login_ip_limit', 'myapi_flood_login_ip_window'));

    $GLOBALS['myapi_test_flood_allowed'] = FALSE;
    $this->assertFalse(myapi_flood_is_allowed('myapi_login_ip', '10.0.0.1', 'myapi_flood_login_ip_limit', 'myapi_flood_login_ip_window'));
  }

  // ---------------------------------------------------------------------
  // myapi_flood_check()
  // ---------------------------------------------------------------------

  /**
   * Under the limit, the check is invisible: it returns, prints nothing, and
   * the endpoint carries on.
   */
  public function testCheckReturnsSilentlyWhenAllowed() {
    $GLOBALS['myapi_test_flood_allowed'] = TRUE;

    $result = myapi_test_capture(function () {
      myapi_flood_check('myapi_login_ip', '10.0.0.1', 'myapi_flood_login_ip_limit', 'myapi_flood_login_ip_window');
    });

    $this->assertFalse($result['exited'], 'the request continues');
    $this->assertSame('', $result['output']);
  }

  /**
   * Over the limit, it answers 429 with 'too_many_attempts' and ends the
   * request — nothing after the check runs.
   *
   * 429 and not 403 is SPEC 06's explicit decision (RFC 6585), so the Flutter
   * client can tell rate limiting from a permission problem.
   */
  public function testCheckAnswers429AndStopsWhenBlocked() {
    $GLOBALS['myapi_test_flood_allowed'] = FALSE;
    $reached_the_end = FALSE;

    $result = myapi_test_capture(function () use (&$reached_the_end) {
      myapi_flood_check('myapi_login_ip', '10.0.0.1', 'myapi_flood_login_ip_limit', 'myapi_flood_login_ip_window');
      $reached_the_end = TRUE;
    });

    $this->assertTrue($result['exited']);
    $this->assertFalse($reached_the_end, 'the code after the check never runs');
    $this->assertSame(429, $result['status']);
    $this->assertSame('too_many_attempts', $result['json']['error_code']);
    $this->assertSame('Demasiados intentos. Inténtalo de nuevo más tarde.', $result['json']['error']);
  }

  /**
   * The check adds nothing to the counter — it only reads it. Registering here
   * would make every blocked request extend its own block.
   */
  public function testCheckNeverRegistersAnAttempt() {
    $GLOBALS['myapi_test_flood_allowed'] = FALSE;

    myapi_test_capture(function () {
      myapi_flood_check('myapi_login_ip', '10.0.0.1', 'myapi_flood_login_ip_limit', 'myapi_flood_login_ip_window');
    });

    $this->assertSame(['is_allowed'], array_column($GLOBALS['myapi_test_flood_calls'], 'call'));
  }

  // ---------------------------------------------------------------------
  // myapi_flood_register()
  // ---------------------------------------------------------------------

  /**
   * Registering passes the event, the resolved window and the identifier —
   * and, unlike the check, takes no limit at all: the limit only matters when
   * reading the counter.
   */
  public function testRegisterPassesEventWindowAndIdentifier() {
    myapi_flood_register('myapi_refresh_ip', '10.0.0.1', 'myapi_flood_refresh_ip_window');

    $this->assertSame([
      'call'       => 'register',
      'event'      => 'myapi_refresh_ip',
      'window'     => 900,
      'identifier' => '10.0.0.1',
    ], $this->theOnlyFloodCall());
  }

  /**
   * It resolves the window from the same defaults as the check does.
   *
   * They are two separate static arrays in the same file, so this is the case
   * that catches them drifting apart — a register with a longer window than
   * its check would keep extending a counter the check has already forgotten.
   */
  public function testRegisterResolvesTheSameWindowAsTheCheck() {
    foreach (self::$wiring as $event => list($limit_var, $window_var, $limit, $window)) {
      $GLOBALS['myapi_test_flood_calls'] = [];

      myapi_flood_register($event, 'subject', $window_var);

      $this->assertSame($window, $this->theOnlyFloodCall()['window'], $event);
    }
  }

  /**
   * Same fallback and same override rules as the check.
   */
  public function testRegisterFallsBackAndHonoursOverrides() {
    myapi_flood_register('myapi_new_thing', 'x', 'myapi_flood_typo_window');
    $this->assertSame(3600, $this->theOnlyFloodCall()['window']);

    $GLOBALS['myapi_test_flood_calls'] = [];
    $GLOBALS['myapi_test_variables']['myapi_flood_refresh_ip_window'] = '120';
    myapi_flood_register('myapi_refresh_ip', 'x', 'myapi_flood_refresh_ip_window');
    $this->assertSame(120, $this->theOnlyFloodCall()['window'], 'int, not "120"');
  }

  // ---------------------------------------------------------------------
  // The call sites
  // ---------------------------------------------------------------------

  /**
   * Every flood call in resources/auth.resource.inc uses the variable names
   * that belong to the event it names.
   *
   * The functions above can be perfectly correct and the protection still be
   * wrong, if an endpoint reads the counter for one event with another's
   * threshold — `myapi_flood_check('myapi_reset_ip', $ip,
   * 'myapi_flood_forgot_ip_limit', ...)` is one character away from what is
   * written today and would raise the reset limit from 10/15min to 10/1h with
   * no symptom at all. The regex reads the real call sites and checks each
   * triple against the table at the top of this class.
   */
  public function testEveryCallSiteUsesTheVariablesOfItsOwnEvent() {
    $source = file_get_contents(__DIR__ . '/../../resources/auth.resource.inc');

    preg_match_all(
      "/myapi_flood_(?:check|is_allowed)\(\s*'([a-z_]+)'\s*,[^,]+,\s*'([a-z_]+)'\s*,\s*'([a-z_]+)'\s*\)/",
      $source,
      $reads,
      PREG_SET_ORDER
    );
    preg_match_all(
      "/myapi_flood_register\(\s*'([a-z_]+)'\s*,[^,]+,\s*'([a-z_]+)'\s*\)/",
      $source,
      $writes,
      PREG_SET_ORDER
    );

    $this->assertNotEmpty($reads, 'parse sanity: flood checks found');
    $this->assertNotEmpty($writes, 'parse sanity: flood registrations found');

    foreach ($reads as $match) {
      list(, $event, $limit_var, $window_var) = $match;
      $this->assertArrayHasKey($event, self::$wiring, 'undocumented flood event: ' . $event);
      $this->assertSame(self::$wiring[$event][0], $limit_var, $event . ' limit variable');
      $this->assertSame(self::$wiring[$event][1], $window_var, $event . ' window variable');
    }

    foreach ($writes as $match) {
      list(, $event, $window_var) = $match;
      $this->assertArrayHasKey($event, self::$wiring, 'undocumented flood event: ' . $event);
      $this->assertSame(self::$wiring[$event][1], $window_var, $event . ' window variable');
    }
  }

  /**
   * All seven documented counters are actually wired to something.
   *
   * The mirror of the case above: that one catches a call site using the wrong
   * variables, this one catches a counter that stopped being read at all —
   * a protection deleted by an edit, which no failing test would otherwise
   * report because nothing breaks when a check disappears.
   */
  public function testEveryDocumentedCounterIsUsedByAnEndpoint() {
    $source = file_get_contents(__DIR__ . '/../../resources/auth.resource.inc');

    foreach (array_keys(self::$wiring) as $event) {
      $this->assertMatchesRegularExpression(
        "/myapi_flood_(?:check|is_allowed)\(\s*'" . preg_quote($event, '/') . "'/",
        $source,
        $event . ' is never checked by any endpoint'
      );
    }
  }

}
