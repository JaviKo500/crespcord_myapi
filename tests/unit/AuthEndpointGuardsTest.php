<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.flood.inc';
require_once __DIR__ . '/../../resources/auth.resource.inc';

/**
 * Unit tests for the guards in front of the 5 JSON endpoints of /api/v1/auth
 * (SPECS 02/04/05/06/07, covered by SPEC 73).
 *
 * Each endpoint answers only after passing a short chain of gates — method
 * routing, then the flood counter, then field validation — and only the last
 * of those needs a database. Everything before it runs here, which is how the
 * five dispatchers and the flood wiring of every endpoint get covered without
 * a Drupal sandbox.
 *
 * The ORDER of the gates is the point of several cases below, because it is
 * not the same for every endpoint and the difference is deliberate: login
 * validates its fields BEFORE touching the counter (a malformed request must
 * not consume anyone's attempts), while refresh, logout, forgot and reset
 * check the counter FIRST (they identify the caller by IP, so an attacker
 * cannot dodge the limit by sending garbage).
 *
 * What stays out is what starts at the first db_select(): the credential
 * check, the token lookups, the rotation, the email and the actual password
 * write. Those are tests/integration/MyapiAuthTestCase.test, over real HTTP.
 *
 * A note on the request body: myapi_request_body() reads php://input, which a
 * test cannot write, so every POST below arrives with an empty body. That is
 * not a limitation here — an empty body is precisely what exercises the
 * validation gate, and it is the shape a misbehaving client actually sends.
 */
class AuthEndpointGuardsTest extends TestCase {

  protected function setUp(): void {
    $GLOBALS['myapi_test_flood_calls'] = [];
    $GLOBALS['myapi_test_ip'] = '198.51.100.4';
    unset($GLOBALS['myapi_test_flood_allowed'], $GLOBALS['myapi_test_variables']);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_GET = [];
    $_POST = [];

    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');
  }

  protected function tearDown(): void {
    $GLOBALS['myapi_test_flood_calls'] = [];
    unset($GLOBALS['myapi_test_flood_allowed'], $GLOBALS['myapi_test_variables'], $GLOBALS['myapi_test_ip']);
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  /**
   * The five dispatchers, with the flood event each one guards.
   *
   * @return array  dispatcher => flood event, or NULL when the endpoint has no
   *                flood gate before its first validation.
   */
  private function dispatchers() {
    return [
      'myapi_auth_dispatch'                 => NULL,
      'myapi_auth_refresh_dispatch'         => 'myapi_refresh_ip',
      'myapi_auth_logout_dispatch'          => 'myapi_logout_ip',
      'myapi_auth_password_forgot_dispatch' => 'myapi_forgot_ip',
      'myapi_auth_password_reset_dispatch'  => 'myapi_reset_ip',
    ];
  }

  /**
   * Runs a dispatcher with the given method.
   *
   * @param string $dispatcher  Function name.
   * @param string $method      HTTP method.
   *
   * @return array  The captured response.
   */
  private function dispatch($dispatcher, $method) {
    $_SERVER['REQUEST_METHOD'] = $method;

    return myapi_test_capture($dispatcher);
  }

  // ---------------------------------------------------------------------
  // Method routing
  // ---------------------------------------------------------------------

  /**
   * All five endpoints are POST-only, and everything else is 405.
   *
   * Documented in docs/auth.md for each of them; asserted here in one place
   * because it is the same three-line dispatcher copied five times, and a copy
   * that lost its else branch would run a POST handler on a GET.
   */
  public function testEveryEndpointIsPostOnly() {
    foreach (array_keys($this->dispatchers()) as $dispatcher) {
      foreach (['GET', 'PUT', 'DELETE', 'PATCH'] as $method) {
        $label = $dispatcher . ' ' . $method;

        $result = $this->dispatch($dispatcher, $method);

        $this->assertTrue($result['exited'], $label);
        $this->assertSame(405, $result['status'], $label);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $label);
      }
    }
  }

  /**
   * A rejected method never touches the flood counter.
   *
   * Otherwise a crawler sending GETs would burn the IP's allowance for the
   * real endpoint and lock out a legitimate user from the same network.
   */
  public function testRejectedMethodsDoNotConsumeTheFloodAllowance() {
    foreach (array_keys($this->dispatchers()) as $dispatcher) {
      $GLOBALS['myapi_test_flood_calls'] = [];

      $this->dispatch($dispatcher, 'GET');

      $this->assertSame([], $GLOBALS['myapi_test_flood_calls'], $dispatcher);
    }
  }

  /**
   * The method comparison is case-insensitive, like every other dispatcher in
   * the module.
   */
  public function testLowercaseMethodStillRoutesToThePostHandler() {
    $result = $this->dispatch('myapi_auth_refresh_dispatch', 'post');

    // It got past the routing: the answer is refresh's own validation error,
    // not a 405.
    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
  }

  // ---------------------------------------------------------------------
  // The flood gate, per endpoint
  // ---------------------------------------------------------------------

  /**
   * Over the limit, four of the five endpoints answer 429 before doing
   * anything else — and each one reads ITS OWN counter, identified by the
   * caller's IP.
   *
   * This is the case SPEC 06 never had: until now only login-by-username was
   * verified anywhere, so refresh, logout, forgot and reset were protected by
   * code no test had ever executed.
   */
  public function testEachEndpointAnswers429OnItsOwnCounter() {
    $GLOBALS['myapi_test_flood_allowed'] = FALSE;

    foreach ($this->dispatchers() as $dispatcher => $event) {
      if ($event === NULL) {
        continue;
      }

      $GLOBALS['myapi_test_flood_calls'] = [];

      $result = $this->dispatch($dispatcher, 'POST');

      $this->assertSame(429, $result['status'], $dispatcher);
      $this->assertSame('too_many_attempts', $result['json']['error_code'], $dispatcher);

      $call = $GLOBALS['myapi_test_flood_calls'][0];
      $this->assertSame('is_allowed', $call['call'], $dispatcher);
      $this->assertSame($event, $call['event'], $dispatcher);
      $this->assertSame('198.51.100.4', $call['identifier'], $dispatcher . ' is limited by IP');
    }
  }

  /**
   * A blocked request stops at the counter: nothing is registered on top of
   * it, and no second counter is consulted.
   */
  public function testABlockedRequestStopsAtTheCounter() {
    $GLOBALS['myapi_test_flood_allowed'] = FALSE;

    $this->dispatch('myapi_auth_password_forgot_dispatch', 'POST');

    $this->assertSame(['is_allowed'], array_column($GLOBALS['myapi_test_flood_calls'], 'call'));
  }

  // ---------------------------------------------------------------------
  // The validation gate, per endpoint
  // ---------------------------------------------------------------------

  /**
   * POST /auth/login with no body is 422 'invalid_field' naming 'username'.
   *
   * 'invalid_field' and not 'missing_field' because login validates with
   * myapi_request_require_strings(), which folds absent and unusable into one
   * answer so the response cannot be used to probe which fields exist.
   */
  public function testLoginRejectsAnEmptyBody() {
    $result = $this->dispatch('myapi_auth_dispatch', 'POST');

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertSame('Campo inválido o ausente: username', $result['json']['error']);
  }

  /**
   * And it does so WITHOUT touching the flood counter.
   *
   * Login is the one endpoint that validates before it counts, and the reason
   * is in the shape of its counters: it limits by username as well as by IP,
   * so a request with no username has no counter to charge — and charging the
   * IP for a malformed request would let a crawler lock out a whole network.
   */
  public function testLoginValidatesBeforeItCounts() {
    $this->dispatch('myapi_auth_dispatch', 'POST');

    $this->assertSame([], $GLOBALS['myapi_test_flood_calls'], 'no counter consulted');
  }

  /**
   * POST /auth/refresh with no body is 422 'missing_field' naming
   * 'refresh_token', after its IP counter was read.
   */
  public function testRefreshRequiresTheRefreshToken() {
    $result = $this->dispatch('myapi_auth_refresh_dispatch', 'POST');

    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertSame('Falta el campo requerido: refresh_token', $result['json']['error']);
    $this->assertSame(['is_allowed'], array_column($GLOBALS['myapi_test_flood_calls'], 'call'));
  }

  /**
   * POST /auth/logout with no Authorization header is 401
   * 'missing_authorization'.
   *
   * The order matters and is asserted: the counter is read first, then the
   * access token is demanded, and only then is the body looked at. A logout
   * without a token never reaches the body validation, so the response cannot
   * tell an attacker whether 'refresh_token' was even required.
   */
  public function testLogoutDemandsAnAccessTokenBeforeTheBody() {
    $result = $this->dispatch('myapi_auth_logout_dispatch', 'POST');

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame('No se proporcionó token de acceso.', $result['json']['error']);
    $this->assertSame(['is_allowed'], array_column($GLOBALS['myapi_test_flood_calls'], 'call'));
  }

  /**
   * A malformed Authorization header is the same 401 as no header at all:
   * myapi_auth_parse_bearer() answers NULL for both, and the endpoint cannot
   * tell them apart.
   */
  public function testLogoutTreatsAMalformedHeaderAsNoHeader() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

    $result = $this->dispatch('myapi_auth_logout_dispatch', 'POST');

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /**
   * POST /auth/password/forgot with no body is 422 naming
   * 'username_or_email' — the one field name in this API that is not a field.
   *
   * The endpoint accepts either key and requires exactly one of them, so the
   * placeholder names the CHOICE rather than a key the client could send.
   */
  public function testForgotRequiresAUsernameOrAnEmail() {
    $result = $this->dispatch('myapi_auth_password_forgot_dispatch', 'POST');

    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertSame('Falta el campo requerido: username_or_email', $result['json']['error']);
  }

  /**
   * And it stops before the identifier counter, which it cannot even name
   * without an identifier: only the IP counter was read.
   */
  public function testForgotStopsBeforeTheIdentifierCounter() {
    $this->dispatch('myapi_auth_password_forgot_dispatch', 'POST');

    $events = array_column($GLOBALS['myapi_test_flood_calls'], 'event');
    $this->assertSame(['myapi_forgot_ip'], $events);
    $this->assertNotContains('myapi_forgot_identifier', $events);
  }

  /**
   * POST /auth/password/reset with no body is 422 'missing_field' naming
   * 'token' — the first of its two required fields.
   */
  public function testResetRequiresTheToken() {
    $result = $this->dispatch('myapi_auth_password_reset_dispatch', 'POST');

    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertSame('Falta el campo requerido: token', $result['json']['error']);
  }

  /**
   * Every one of these rejections is a complete envelope: a client parsing the
   * response never has to special-case a guard's answer.
   */
  public function testEveryGuardAnswersAWellFormedEnvelope() {
    $GLOBALS['myapi_test_flood_allowed'] = TRUE;

    foreach (array_keys($this->dispatchers()) as $dispatcher) {
      foreach (['GET', 'POST'] as $method) {
        $label = $dispatcher . ' ' . $method;

        $result = $this->dispatch($dispatcher, $method);

        $this->assertSame(['success', 'error_code', 'error'], array_keys($result['json']), $label);
        $this->assertFalse($result['json']['success'], $label);
        $this->assertNotSame($result['json']['error_code'], $result['json']['error'], $label . ' is translated');
        $this->assertSame('application/json', $result['headers']['Content-Type'], $label);
      }
    }
  }

}
