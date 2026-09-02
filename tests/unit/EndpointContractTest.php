<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../myapi.module';

/**
 * Contract tests for what every endpoint DOES, driven through the router.
 *
 * ModuleContractTest is this file's static half: it reads the sources and
 * asserts the wiring — that routes name callbacks that exist, in the files they
 * declare, listed in myapi.info, documented, under api/v1/. It never calls
 * anything. This file is the dynamic half: it takes the same routing table and
 * CALLS every callback in it, once per HTTP method, and asserts the two things
 * that are true of all of them at once.
 *
 * The first is authentication. Every route in hook_menu() declares
 * 'access callback' => TRUE, because Drupal's permission system knows nothing
 * about bearer tokens: the entire security boundary of this API is that each
 * callback calls myapi_auth_require_access_token() before it does anything.
 * That is 58 hand-written calls spread over 20 resource files, and nothing
 * checked them. An endpoint that forgets the line is not a failing test
 * anywhere in tests/unit — every test calls its handler with a seeded token
 * row, so the guard is never the thing under test — and it is not a 500 in
 * production either. It answers. To anyone.
 *
 * The second is the method matrix. The dispatcher pattern (CLAUDE.md: "Each
 * resource file has a dispatcher that routes by HTTP method") is copied by
 * hand into every resource, and the branch that is easy to lose in the copy is
 * the else: a dispatcher that forgets it serves its GET handler to a DELETE.
 * 36 test files already assert 405 for their own resource, which is 36 chances
 * to forget the 37th; this asserts it for every route there is, including the
 * ones added after this file was written.
 *
 * Why dynamic, when the rest of the contract layer is static: a static walk
 * can prove that the word myapi_auth_require_access_token appears somewhere
 * under a dispatcher, and that is not the question. The question is whether
 * the guard is on the path the request actually takes — and a dispatcher with
 * a GET branch that authenticates and a POST branch that does not passes any
 * reasonable grep. Calling it is the only honest answer.
 *
 * What makes calling it possible without a database: the guard bails on the
 * missing header before it queries anything, so an unauthenticated request
 * never reaches the resource's own SQL. That is not an accident of the test —
 * it is asserted below, twice, as the invariant it is.
 *
 * What this file cannot say: nothing here proves an AUTHENTICATED request is
 * authorised. That a token belonging to condominium A cannot read condominium
 * B's rows is a different contract, it needs real fixtures per resource, and
 * it stays with each resource's own test — and, for the SQL half of it, with
 * tests/integration.
 */
class EndpointContractTest extends TestCase {

  /**
   * The methods a dispatcher can be asked for.
   *
   * The seven a client can reach this API with. Anything a dispatcher does not
   * implement out of this list has to be 405, and that "anything" is the whole
   * point of listing them here rather than testing the one or two verbs a
   * given resource happens to care about.
   */
  const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

  /**
   * The endpoints that answer without a token, as "path [METHOD]" => why.
   *
   * Five, and each one is a decision rather than an omission — which is why
   * they are written down here instead of being inferred. ping is the liveness
   * probe the app calls before it has a session at all; login is where a
   * session comes from; refresh authenticates with the refresh token instead
   * of the access one, so the access-token guard would be wrong there; and the
   * two password endpoints are, by definition, for users who cannot log in.
   *
   * Note what is NOT here: logout. It carries a refresh token in its body and
   * still demands the access token in the header, and that is deliberate —
   * revoking somebody else's session is not a thing an anonymous request gets
   * to do.
   *
   * Adding a line here is the only way to make an endpoint public, and it is a
   * line a reviewer sees in the diff. Forgetting the guard on a real endpoint
   * is a failing test.
   */
  const PUBLIC_ENDPOINTS = [
    'api/v1/ping [GET]'                    => 'liveness probe, called before there is a session',
    'api/v1/auth/login [POST]'             => 'this is where a session comes from',
    'api/v1/auth/refresh [POST]'           => 'authenticates with the refresh token, not the access token',
    'api/v1/auth/password/forgot [POST]'   => 'for users who cannot log in',
    'api/v1/auth/password/reset [POST]'    => 'for users who cannot log in',
  ];

  /**
   * The table the guard reads, and the only one an unauthenticated request may
   * touch.
   */
  const TOKEN_TABLE = 'my_api_tokens';

  /**
   * Loads every resource and include, once, for the whole class.
   *
   * The router names 20 resource files and the includes they lean on, and this
   * class calls all of them, so there is no useful subset to require: it is the
   * module or nothing. myapi.mailsystem.inc is the one exception and the one
   * file in the tree that cannot be loaded outside a site — it extends
   * DefaultMailSystem, a Drupal core class, at file scope. Nothing routed
   * reaches it, so skipping it changes nothing here.
   */
  public static function setUpBeforeClass(): void {
    $root = dirname(__DIR__, 2);

    foreach (array_merge(glob($root . '/includes/*.inc'), glob($root . '/resources/*.inc')) as $path) {
      if (basename($path) === 'myapi.mailsystem.inc') {
        continue;
      }
      require_once $path;
    }
  }

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  /**
   * The api/v1 routing table, as "path [METHOD]" => [callback, args, method,
   * every method the same dispatcher implements].
   *
   * One entry per route AND method the dispatcher implements, which is the
   * unit every case below works in: 'api/v1/claims' is not one endpoint, it is
   * a GET and a POST, and they are guarded separately or not at all. The
   * fourth element is what keeps that split from lying about the other half —
   * the POST of 'api/v1/claims' is implemented, so the GET entry must not go
   * looking for a 405 on it.
   *
   * The % of a path becomes the argument 1. No case here gets far enough for
   * the value to matter — every one of them is refused at the method check or
   * at the guard, both of which run before the id is looked at.
   */
  public function routedEndpoints() {
    $endpoints = [];

    foreach (myapi_menu() as $path => $item) {
      if (strpos($path, 'api/v1/') !== 0) {
        continue;
      }
      $callback = $item['page callback'];
      $args = array_fill(0, substr_count($path, '%'), 1);
      $implemented = $this->implementedMethods($callback);

      foreach ($implemented as $method) {
        $endpoints[$path . ' [' . $method . ']'] = [$callback, $args, $method, $implemented];
      }
    }
    $this->assertGreaterThan(50, count($endpoints), 'parse sanity: endpoints found');

    return $endpoints;
  }

  /**
   * The HTTP methods a dispatcher compares itself against.
   *
   * Tokens, not a grep over the file: the dispatcher's docblock says the word
   * GET in prose in most resources, and a comment inside the else branch says
   * it in a few. Reading T_CONSTANT_ENCAPSED_STRING out of the function's own
   * body is what separates the comparison from the sentence about it.
   *
   * This only says what the source MENTIONS. That the branch is real, and
   * reachable, is what testEveryEndpointServesTheMethodsItImplements() proves
   * by calling it — the two cases are each other's other half, and a verb
   * named in a string but wired to nothing fails there.
   */
  private function implementedMethods($callback) {
    $reflection = new ReflectionFunction($callback);
    $lines = file($reflection->getFileName());
    $body = implode('', array_slice(
      $lines,
      $reflection->getStartLine() - 1,
      $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));

    $methods = [];
    foreach (token_get_all('<?php ' . $body) as $token) {
      if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        continue;
      }
      $value = trim($token[1], "'\"");
      if (in_array($value, self::HTTP_METHODS, TRUE)) {
        $methods[$value] = TRUE;
      }
    }
    $this->assertNotEmpty($methods, $callback . '(): routes by no HTTP method at all');

    return array_keys($methods);
  }

  /**
   * Calls a routed callback and returns the captured response.
   */
  private function request($callback, array $args, $method, $authorization = NULL) {
    myapi_test_db_seed();
    $_SERVER['REQUEST_METHOD'] = $method;
    if ($authorization === NULL) {
      unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    else {
      $_SERVER['HTTP_AUTHORIZATION'] = $authorization;
    }

    return myapi_test_capture(function () use ($callback, $args) {
      call_user_func_array($callback, $args);
    });
  }

  /**
   * Every method a dispatcher does not implement is 405, in the envelope.
   *
   * The else branch of the dispatcher pattern, asserted for all 53 endpoints
   * at once instead of resource by resource. A DELETE that reaches a GET
   * handler is not a 500 anyone notices — on a list endpoint it is a 200 with
   * the list in it.
   *
   * Sent with no Authorization header on purpose: see the case below.
   */
  public function testEveryEndpointRefusesTheMethodsItDoesNotImplement() {
    foreach ($this->routedEndpoints() as $label => $endpoint) {
      list($callback, $args, , $implemented) = $endpoint;

      foreach (self::HTTP_METHODS as $method) {
        if (in_array($method, $implemented, TRUE)) {
          continue;
        }
        $result = $this->request($callback, $args, $method);

        $this->assertSame(405, $result['status'], $label . ': ' . $method . ' was not refused');
        $this->assertFalse($result['json']['success'], $label . ': ' . $method);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $label . ': ' . $method);
      }
    }
  }

  /**
   * And the other direction: a method the dispatcher names is really wired.
   *
   * Without this, the case above passes on a dispatcher that refuses
   * everything — including its own verb. What it asserts is only "not 405",
   * because what a wired method answers to an anonymous request is the next
   * case's business: here it is enough that the request got past the routing.
   */
  public function testEveryEndpointServesTheMethodsItImplements() {
    foreach ($this->routedEndpoints() as $label => $endpoint) {
      list($callback, $args, $method) = $endpoint;

      $result = $this->request($callback, $args, $method);

      $this->assertNotSame(405, $result['status'], $label . ': the method it routes by is refused');
    }
  }

  /**
   * No token, no answer: 401 missing_authorization on every guarded endpoint.
   *
   * The case this file exists for. It is the only one in the suite that fails
   * when a NEW endpoint is written without myapi_auth_require_access_token(),
   * rather than when an existing one is changed — and a new endpoint without
   * the guard is not a bug that surfaces, it is a table readable by anybody
   * who can spell its URL.
   */
  public function testEveryEndpointDemandsAnAccessToken() {
    foreach ($this->routedEndpoints() as $label => $endpoint) {
      list($callback, $args, $method) = $endpoint;
      if (isset(self::PUBLIC_ENDPOINTS[$label])) {
        continue;
      }

      $result = $this->request($callback, $args, $method);

      $this->assertSame(401, $result['status'], $label . ': answers without a token');
      $this->assertSame('missing_authorization', $result['json']['error_code'], $label);
    }
  }

  /**
   * A token that is not a token is 401 invalid_token, not 500 and not 200.
   *
   * The header being present is the difference from the case above, and it is
   * the one that reaches the database: this is the path where the guard hashes
   * what it was given, finds no row, and stops.
   */
  public function testEveryEndpointRejectsAnUnknownAccessToken() {
    foreach ($this->routedEndpoints() as $label => $endpoint) {
      list($callback, $args, $method) = $endpoint;
      if (isset(self::PUBLIC_ENDPOINTS[$label])) {
        continue;
      }

      $result = $this->request($callback, $args, $method, 'Bearer not-a-real-access-token');

      $this->assertSame(401, $result['status'], $label . ': accepted an unknown token');
      $this->assertSame('invalid_token', $result['json']['error_code'], $label);
    }
  }

  /**
   * An unauthenticated request reads nothing at all.
   *
   * The guard returns on the missing header before it queries even the token
   * table, so the correct number of queries here is zero — and a dispatcher
   * that loads the unit, or counts the rows, or resolves the condominium
   * BEFORE calling the guard would show up as a query recorded against a table
   * that an anonymous caller has no business reaching.
   *
   * This is what "the guard runs first" means as an assertion. The status code
   * cases above cannot see it: a resource that reads its rows and then
   * authenticates answers exactly the same 401.
   */
  public function testAnUnauthenticatedRequestReachesNoTable() {
    foreach ($this->routedEndpoints() as $label => $endpoint) {
      list($callback, $args, $method) = $endpoint;
      if (isset(self::PUBLIC_ENDPOINTS[$label])) {
        continue;
      }

      $this->request($callback, $args, $method);

      $tables = [];
      foreach (myapi_test_db_queries() as $query) {
        $tables[$query['table']] = TRUE;
      }
      $this->assertSame([], array_keys($tables), $label . ': queried a table before authenticating');
    }
  }

  /**
   * With a token presented, the only table read before it is validated is the
   * one the token lives in.
   *
   * The same invariant one step further in: here the guard does run its query,
   * and nothing else may have run one alongside it.
   */
  public function testARejectedTokenReachesNoTableButTheTokenTable() {
    foreach ($this->routedEndpoints() as $label => $endpoint) {
      list($callback, $args, $method) = $endpoint;
      if (isset(self::PUBLIC_ENDPOINTS[$label])) {
        continue;
      }

      $this->request($callback, $args, $method, 'Bearer not-a-real-access-token');

      $tables = [];
      foreach (myapi_test_db_queries() as $query) {
        $tables[$query['table']] = TRUE;
      }
      $this->assertSame(
        [self::TOKEN_TABLE],
        array_keys($tables),
        $label . ': queried something other than the token table before authenticating'
      );
    }
  }

  /**
   * The public allowlist has no stale entries.
   *
   * The same hygiene ModuleContractTest keeps over NON_VERSIONED_PATHS, for
   * the same reason: an endpoint removed from hook_menu() and left here would
   * silently exempt the next one that happens to be routed at the same path
   * and method.
   */
  public function testThePublicAllowlistHasNoStaleEntries() {
    $routed = $this->routedEndpoints();

    foreach (array_keys(self::PUBLIC_ENDPOINTS) as $label) {
      $this->assertArrayHasKey($label, $routed, $label . ': allowlisted as public but no longer routed');
    }
  }

  /**
   * And the allowlisted ones really do answer without a token.
   *
   * Not "they answer 200": login without a body is a 422, and that is the
   * right answer. What is asserted is that they got as far as their own
   * validation, which is to say that they were never asked for a token.
   */
  public function testThePublicEndpointsAnswerWithoutAToken() {
    foreach (self::PUBLIC_ENDPOINTS as $label => $reason) {
      list($callback, $args, $method) = $this->routedEndpoints()[$label];

      $result = $this->request($callback, $args, $method);

      $this->assertNotSame(401, $result['status'], $label . ': public but demands a token — ' . $reason);
      $this->assertNotSame(405, $result['status'], $label);
    }
  }

}
