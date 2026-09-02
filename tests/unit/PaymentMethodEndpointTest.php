<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../resources/payment_method.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/payment-methods (SPEC 19, covered by
 * SPEC 121).
 *
 * myapi_payment_method_dispatch() is called the way hook_menu() calls it, over
 * a fixture `metodos_pago` vocabulary, a fixture my_api_tokens row and a
 * fixture Authorization header. What gets asserted is the JSON body the module
 * prints and the status code it sets — the same bytes the Flutter app receives.
 *
 * THE TWIN OF banks, AND THE DIFFERENCE THAT MATTERS. This endpoint is the
 * second catalogue of the module built on the taxonomy API, and it shares the
 * method guard, the token guard, the degraded paths and the `sort` whitelist
 * with GET /api/v1/banks. It has one rule banks does not, and that rule is the
 * reason this class exists rather than a data provider added to
 * BankEndpointTest: payment methods are HYDRATED in a second, batched
 * entity_load() call, and a term with no field_tipo_pago value is DROPPED from
 * the collection. Both halves fail silently — a resource that forgot to
 * hydrate would answer a full list of methods with no type_method, and a
 * resource that forgot to filter would offer the app a method it cannot use to
 * register a payment.
 *
 * What it does NOT prove is Drupal's half: that `metodos_pago` exists on the
 * site, that taxonomy_get_tree() builds the hierarchy or applies term access,
 * or that entity_load() resolves the Field API. Those answers come from Drupal,
 * and the stubs answer seeded rows (see the SPEC 76/79 blocks in bootstrap.php).
 */
class PaymentMethodEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_taxonomy_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
    myapi_test_taxonomy_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A my_api_tokens row for the plaintext token above.
   */
  private function tokenRow(array $overrides = []) {
    return $overrides + [
      'id'                => '1',
      'uid'               => '3',
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * Sends the request as an authenticated, active user.
   */
  private function authenticateAs($uid = 3, array $token_overrides = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'name' => 'user' . $uid, 'status' => 1];

    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow($token_overrides + ['uid' => (string) $uid])]]);
  }

  /**
   * Seeds the `metodos_pago` vocabulary with the given methods.
   *
   * A second vocabulary is always seeded first, so `metodos_pago` never gets
   * vid 1: a resource that hard-coded the vid, or a stub that ignored it,
   * would pass against vid 1 by accident. Same guard as BankEndpointTest.
   *
   * The tree is seeded with the LIGHT rows taxonomy_get_tree() answers (tid,
   * name, description and nothing else) and the hydrated rows are registered
   * separately, carrying field_tipo_pago. A resource that skipped the
   * entity_load() would therefore see no type_method at all and drop every
   * method — which is the production failure, and what
   * testEveryMethodIsHydratedBeforeBeingMapped() reads.
   *
   * @param array $methods
   *   Each entry is ['tid' => int, 'name' => string, 'description' => string,
   *   'type' => string|NULL]. A NULL 'type' registers a hydrated term with no
   *   field_tipo_pago value at all.
   */
  private function seedMethods(array $methods) {
    $tree = [];
    $hydrated = [];

    foreach ($methods as $method) {
      $method += ['description' => '', 'type' => 'Bancaria'];

      $tree[] = [
        'tid'         => (string) $method['tid'],
        'name'        => $method['name'],
        'description' => $method['description'],
      ];

      $term = [
        'tid'         => (string) $method['tid'],
        'name'        => $method['name'],
        'description' => $method['description'],
      ];
      if ($method['type'] !== NULL) {
        $term['field_tipo_pago'] = [LANGUAGE_NONE => [['value' => $method['type']]]];
      }
      $hydrated[] = $term;
    }

    myapi_test_taxonomy_seed([
      'bancos'        => [['tid' => '99', 'name' => 'Banco Pichincha', 'description' => '']],
      'metodos_pago'  => $tree,
    ]);
    myapi_test_taxonomy_entities_seed($hydrated);
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function request() {
    return myapi_test_capture('myapi_payment_method_dispatch');
  }

  /**
   * The `name` of every method in the answered order.
   */
  private function names(array $result) {
    return array_column($result['json']['data']['payment_methods'], 'name');
  }

  /**
   * Every taxonomy call of a given function name, in order.
   */
  private function callsTo($function) {
    return array_values(array_filter(myapi_test_taxonomy_calls(), function ($call) use ($function) {
      return $call['function'] === $function;
    }));
  }

  /* -------------------------------------------------------------------------
   * Method routing.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, before any authentication: a POST with
   * a perfectly valid token is still 405, and the message is the translated
   * one from the catalogue.
   */
  public function testEveryMethodOtherThanGetIs405() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->request();

      $this->assertSame(405, $result['status'], $method);
      $this->assertFalse($result['json']['success'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame('Método no permitido.', $result['json']['error'], $method);
    }
  }

  /**
   * The 405 costs nothing: no token lookup and no vocabulary load. A read-only
   * catalogue that queried on a POST would be paying for a request it rejects.
   */
  public function testRejectedMethodTouchesNeitherDatabaseNorTaxonomy() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->request();

    $this->assertSame([], myapi_test_db_queries());
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * GET reaches the list handler — proven by the authentication error it
   * answers, which only myapi_payment_method_list() can produce.
   */
  public function testGetIsRoutedToTheListHandler() {
    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /**
   * A lowercase verb is still a GET: the comparison goes through
   * myapi_request_method(), which upper-cases it.
   */
  public function testLowercaseGetIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    $result = $this->request();

    $this->assertSame(200, $result['status'], 'not 405: the request was routed as a GET');
  }

  /* -------------------------------------------------------------------------
   * The access token guard.
   *
   * The catalogue is NOT public: the descriptions carry account numbers. Every
   * case below asserts that the vocabulary is never even loaded when the token
   * fails.
   * ---------------------------------------------------------------------- */

  /**
   * No Authorization header: 401 missing_authorization, and not one taxonomy
   * call — the catalogue never leaves the site for an anonymous caller.
   */
  public function testMissingAuthorizationHeaderIs401AndLoadsNoVocabulary() {
    $this->seedMethods([['tid' => 7, 'name' => 'Transferencia', 'description' => 'Cta. 2100', 'type' => 'Bancaria']]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame('No se proporcionó token de acceso.', $result['json']['error']);
    $this->assertSame([], myapi_test_taxonomy_calls());
    $this->assertStringNotContainsString('2100', $result['output']);
  }

  /**
   * A header that is not "Bearer <token>" is treated as no header at all,
   * including an empty Bearer.
   */
  public function testMalformedAuthorizationHeaderIs401() {
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    foreach (['Token abc', 'Bearer', 'Bearer ', 'abc', 'Bearer a b'] as $header) {
      $_SERVER['HTTP_AUTHORIZATION'] = $header;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $header);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $header);
      $this->assertSame([], myapi_test_taxonomy_calls(), $header);
    }
  }

  /**
   * A token that is not in my_api_tokens is 401 invalid_token, and the
   * catalogue is not loaded.
   */
  public function testUnknownTokenIs401InvalidToken() {
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    myapi_test_db_seed(['my_api_tokens' => []]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * A revoked token, an expired one, a deleted user and a blocked one are the
   * four ways an EXISTING token still fails. All four are 401 invalid_token
   * and none of them loads the catalogue.
   */
  public function testRevokedExpiredAndInactiveUserTokensAreAll401() {
    $cases = [
      'revoked'      => ['token' => ['revoked' => '1'], 'user' => TRUE],
      'expired'      => ['token' => ['access_expires_at' => REQUEST_TIME - 1], 'user' => TRUE],
      'deleted user' => ['token' => [], 'user' => FALSE],
      'blocked user' => ['token' => [], 'user' => 'blocked'],
    ];

    foreach ($cases as $name => $case) {
      myapi_test_taxonomy_seed();
      $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);
      $GLOBALS['myapi_test_users'] = [];
      $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;

      if ($case['user'] === TRUE) {
        $GLOBALS['myapi_test_users'][3] = ['uid' => 3, 'name' => 'user3', 'status' => 1];
      }
      elseif ($case['user'] === 'blocked') {
        $GLOBALS['myapi_test_users'][3] = ['uid' => 3, 'name' => 'user3', 'status' => 0];
      }

      myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow($case['token'])]]);

      $result = $this->request();

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_taxonomy_calls(), $name);
    }
  }

  /**
   * A token expiring exactly now is still valid: the middleware compares with
   * '>=', so the second it dies is the second it still works.
   */
  public function testTokenExpiringExactlyNowIsStillValid() {
    $this->authenticateAs(3, ['access_expires_at' => REQUEST_TIME]);
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
  }

  /**
   * The catalogue is not per-user: two different authenticated users get the
   * same bytes. The token row is read for authentication and for nothing else.
   */
  public function testAnyAuthenticatedUserGetsTheSameCatalogue() {
    $this->authenticateAs(3);
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);
    $first = $this->request();

    myapi_test_taxonomy_seed();
    $this->authenticateAs(88);
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);
    $second = $this->request();

    $this->assertSame($first['output'], $second['output']);
  }

  /**
   * The token lookup is the only query this endpoint runs: the catalogue lives
   * in the taxonomy API, not in a table this resource selects from.
   */
  public function testTheOnlyQueryIsTheTokenLookup() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    $this->request();

    $queries = myapi_test_db_queries();
    $this->assertCount(1, $queries);
    $this->assertSame('my_api_tokens', $queries[0]['table']);
  }

  /* -------------------------------------------------------------------------
   * The response shape.
   * ---------------------------------------------------------------------- */

  /**
   * The documented body, compared whole: the envelope, the single data key and
   * the four keys of each item, in the documented order.
   */
  public function testFullAnswerHasTheDocumentedShape() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 7, 'name' => 'Efectivo', 'description' => '', 'type' => 'cash'],
      ['tid' => 4, 'name' => 'Transferencia', 'description' => 'Cuenta corriente 2100', 'type' => 'Bancaria'],
    ]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'success' => TRUE,
      'data'    => [
        'payment_methods' => [
          ['id' => 7, 'name' => 'Efectivo', 'type_method' => 'cash', 'description' => ''],
          ['id' => 4, 'name' => 'Transferencia', 'type_method' => 'Bancaria', 'description' => 'Cuenta corriente 2100'],
        ],
      ],
    ], $result['json']);
  }

  /**
   * The list is printed as a JSON ARRAY and not as an object: array_values()
   * after the filter is what guarantees it, and a filtered-out term in the
   * middle is exactly what would turn it into an object without it.
   */
  public function testTheListIsPrintedAsAJsonArrayEvenAfterAMethodIsDropped() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 1, 'name' => 'Alfa', 'type' => 'cash'],
      ['tid' => 2, 'name' => 'Beta', 'type' => NULL],
      ['tid' => 3, 'name' => 'Gamma', 'type' => 'Bancaria'],
    ]);

    $result = $this->request();

    $this->assertStringContainsString('"payment_methods":[{', $result['output']);
    $this->assertSame(['Alfa', 'Gamma'], $this->names($result));
  }

  /**
   * Every item carries exactly the four documented keys, in order, and nothing
   * else: no vid, no weight, no vocabulary_machine_name.
   */
  public function testEveryItemHasExactlyTheFourDocumentedKeysInOrder() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'description' => 'x', 'type' => 'cash']]);

    $result = $this->request();

    $item = $result['json']['data']['payment_methods'][0];
    $this->assertSame(['id', 'name', 'type_method', 'description'], array_keys($item));
  }

  /**
   * A single method is still a list, not a bare object.
   */
  public function testASingleMethodIsStillAList() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    $result = $this->request();

    $this->assertCount(1, $result['json']['data']['payment_methods']);
    $this->assertStringContainsString('"payment_methods":[{', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * The degraded paths.
   * ---------------------------------------------------------------------- */

  /**
   * A missing vocabulary answers 200 with an empty list and never asks for a
   * tree — the `=== FALSE` guard, without which the next line would fatal on
   * FALSE->vid.
   */
  public function testMissingVocabularyAnswersAnEmptyListAndNeverAsksForATree() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed(['bancos' => [['tid' => '99', 'name' => 'Banco', 'description' => '']]]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['payment_methods' => []], $result['json']['data']);
    $this->assertSame([], $this->callsTo('taxonomy_get_tree'));
  }

  /**
   * An empty vocabulary answers 200 with an empty list and never hydrates:
   * entity_load() on an empty id list is a query for nothing.
   */
  public function testEmptyVocabularyAnswersAnEmptyListAndNeverHydrates() {
    $this->authenticateAs();
    $this->seedMethods([]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['payment_methods' => []], $result['json']['data']);
    $this->assertSame([], $this->callsTo('entity_load'));
  }

  /**
   * The two degraded paths answer the SAME BYTES, and both print an array.
   */
  public function testBothDegradedPathsAnswerTheSameEmptyArray() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed(['bancos' => [['tid' => '99', 'name' => 'Banco', 'description' => '']]]);
    $missing = $this->request();

    $this->authenticateAs();
    $this->seedMethods([]);
    $empty = $this->request();

    $this->assertSame($missing['output'], $empty['output']);
    $this->assertStringContainsString('"payment_methods":[]', $missing['output']);
  }

  /**
   * A vocabulary whose every term lacks field_tipo_pago answers an empty list
   * — the same 200 as an empty vocabulary, reached through the filter instead
   * of through the guard.
   */
  public function testAVocabularyWhereNoTermIsUsableAnswersAnEmptyList() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 1, 'name' => 'Alfa', 'type' => NULL],
      ['tid' => 2, 'name' => 'Beta', 'type' => ''],
    ]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['payment_methods' => []], $result['json']['data']);
    $this->assertStringContainsString('"payment_methods":[]', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * The taxonomy calls.
   * ---------------------------------------------------------------------- */

  /**
   * The vocabulary asked for is `metodos_pago` and no other.
   */
  public function testTheVocabularyAskedForIsMetodosPago() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    $this->request();

    $calls = $this->callsTo('taxonomy_vocabulary_machine_name_load');
    $this->assertCount(1, $calls);
    $this->assertSame(['metodos_pago'], $calls[0]['args']);
  }

  /**
   * The tree is asked for with the vid that was just resolved, and only once.
   * The fixture puts `metodos_pago` on vid 2 on purpose.
   */
  public function testTheTreeIsAskedForOnceWithTheLoadedVid() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash']]);

    $this->request();

    $calls = $this->callsTo('taxonomy_get_tree');
    $this->assertCount(1, $calls);
    $this->assertSame(2, $calls[0]['args'][0], 'the vid of metodos_pago, not a hard-coded 1');
  }

  /**
   * The terms are hydrated in ONE batched call carrying every tid of the tree,
   * not one call per term.
   */
  public function testEveryMethodIsHydratedBeforeBeingMappedInASingleBatch() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 7, 'name' => 'Efectivo', 'type' => 'cash'],
      ['tid' => 4, 'name' => 'Transferencia', 'type' => 'Bancaria'],
      ['tid' => 9, 'name' => 'Cheque', 'type' => 'Cheque'],
    ]);

    $result = $this->request();

    $calls = $this->callsTo('entity_load');
    $this->assertCount(1, $calls);
    $this->assertSame('taxonomy_term', $calls[0]['args'][0]);
    $this->assertSame(['7', '4', '9'], $calls[0]['args'][1]);
    $this->assertSame(
      // Ordered by name: Cheque, Efectivo, Transferencia.
      ['Cheque', 'cash', 'Bancaria'],
      array_column($result['json']['data']['payment_methods'], 'type_method'),
      'the hydrated field travelled into the response'
    );
  }

  /* -------------------------------------------------------------------------
   * type_method: the rule this catalogue has and banks does not.
   * ---------------------------------------------------------------------- */

  /**
   * A term with no field_tipo_pago value at all is excluded.
   */
  public function testATermWithoutTheFieldIsExcluded() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 1, 'name' => 'Con tipo', 'type' => 'cash'],
      ['tid' => 2, 'name' => 'Sin tipo', 'type' => NULL],
    ]);

    $result = $this->request();

    $this->assertSame(['Con tipo'], $this->names($result));
  }

  /**
   * An empty and a whitespace-only value are excluded too: the filter trims
   * before deciding, so a method whose type is a space is as unusable as one
   * with no type at all.
   */
  public function testEmptyAndWhitespaceOnlyTypesAreExcluded() {
    foreach (['', ' ', "\t", "\n", '   '] as $type) {
      myapi_test_taxonomy_seed();
      $this->authenticateAs();
      $this->seedMethods([
        ['tid' => 1, 'name' => 'Usable', 'type' => 'cash'],
        ['tid' => 2, 'name' => 'Vacío', 'type' => $type],
      ]);

      $result = $this->request();

      $this->assertSame(['Usable'], $this->names($result), json_encode($type));
    }
  }

  /**
   * The value is kept UNTRIMMED in the answer: the trim() decides whether the
   * method is usable, it does not rewrite what is stored. A type of ' cash '
   * travels with its spaces, which is what the app compares against.
   */
  public function testTheTypeIsTrimmedOnlyToDecideAndNeverRewritten() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 1, 'name' => 'Efectivo', 'type' => ' cash ']]);

    $result = $this->request();

    $this->assertSame(' cash ', $result['json']['data']['payment_methods'][0]['type_method']);
  }

  /**
   * A type of '0' is a real value and is NOT dropped: trim('0') === '0', not
   * ''. The guard is a string comparison and not an empty() check, and this is
   * the case that tells them apart.
   */
  public function testATypeOfZeroIsKept() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 1, 'name' => 'Cero', 'type' => '0']]);

    $result = $this->request();

    $this->assertSame('0', $result['json']['data']['payment_methods'][0]['type_method']);
  }

  /**
   * Only delta 0 of the field is read. A multi-value field_tipo_pago answers
   * its first value, which is the shape the instance has (cardinality 1).
   */
  public function testOnlyTheFirstDeltaOfTheFieldIsRead() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed([
      'bancos'       => [['tid' => '99', 'name' => 'Banco', 'description' => '']],
      'metodos_pago' => [['tid' => '1', 'name' => 'Efectivo', 'description' => '']],
    ]);
    myapi_test_taxonomy_entities_seed([
      [
        'tid'             => '1',
        'name'            => 'Efectivo',
        'description'     => '',
        'field_tipo_pago' => [LANGUAGE_NONE => [['value' => 'primero'], ['value' => 'segundo']]],
      ],
    ]);

    $result = $this->request();

    $this->assertSame('primero', $result['json']['data']['payment_methods'][0]['type_method']);
  }

  /**
   * A term the hydration did not answer is simply absent from the response
   * rather than a fatal: entity_load() omits an id it could not load, and
   * array_map() over the loaded map never sees it.
   */
  public function testATermThatCouldNotBeHydratedIsAbsentInsteadOfFatal() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed([
      'bancos'       => [['tid' => '99', 'name' => 'Banco', 'description' => '']],
      'metodos_pago' => [
        ['tid' => '1', 'name' => 'Efectivo', 'description' => ''],
        ['tid' => '2', 'name' => 'Fantasma', 'description' => ''],
      ],
    ]);
    // Only tid 1 is registered as hydrated; tid 2 falls back to the light tree
    // row, which carries no field_tipo_pago and is therefore dropped by the
    // usability filter rather than by a missing entity.
    myapi_test_taxonomy_entities_seed([
      [
        'tid'             => '1',
        'name'            => 'Efectivo',
        'description'     => '',
        'field_tipo_pago' => [LANGUAGE_NONE => [['value' => 'cash']]],
      ],
    ]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['Efectivo'], $this->names($result));
  }

  /* -------------------------------------------------------------------------
   * Casting and sanitizing.
   * ---------------------------------------------------------------------- */

  /**
   * The id is an int in the JSON, cast from the string the database answers.
   */
  public function testIdIsCastFromTheStringTheDatabaseAnswers() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 42, 'name' => 'Efectivo', 'type' => 'cash']]);

    $result = $this->request();

    $this->assertSame(42, $result['json']['data']['payment_methods'][0]['id']);
    $this->assertStringContainsString('"id":42', $result['output']);
  }

  /**
   * The three strings are escaped with check_plain(), so stored markup travels
   * escaped and never as live HTML.
   */
  public function testNameTypeAndDescriptionAreAllEscaped() {
    $this->authenticateAs();
    $this->seedMethods([[
      'tid'         => 1,
      'name'        => '<b>Efectivo</b>',
      'description' => "Cuenta \"2100\" & cía",
      'type'        => "<script>alert('x')</script>",
    ]]);

    $result = $this->request();

    $item = $result['json']['data']['payment_methods'][0];
    $this->assertSame('&lt;b&gt;Efectivo&lt;/b&gt;', $item['name']);
    $this->assertSame('Cuenta &quot;2100&quot; &amp; cía', $item['description']);
    $this->assertSame('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', $item['type_method']);
    $this->assertStringNotContainsString('<script>', $result['output']);
  }

  /**
   * An empty description travels as "" and never as null — the app renders the
   * string without a null check.
   */
  public function testEmptyDescriptionTravelsAsAnEmptyString() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 1, 'name' => 'Efectivo', 'description' => '', 'type' => 'cash']]);

    $result = $this->request();

    $this->assertSame('', $result['json']['data']['payment_methods'][0]['description']);
    $this->assertStringContainsString('"description":""', $result['output']);
  }

  /**
   * Accented text travels unchanged: check_plain() escapes HTML, not UTF-8.
   */
  public function testAccentedTextTravelsUnchanged() {
    $this->authenticateAs();
    $this->seedMethods([['tid' => 1, 'name' => 'Depósito en ventanilla', 'description' => 'Sucursal Quiñones', 'type' => 'Depósito']]);

    $result = $this->request();

    $item = $result['json']['data']['payment_methods'][0];
    $this->assertSame('Depósito en ventanilla', $item['name']);
    $this->assertSame('Sucursal Quiñones', $item['description']);
    $this->assertSame('Depósito', $item['type_method']);
  }

  /* -------------------------------------------------------------------------
   * Ordering and the `sort` parameter.
   * ---------------------------------------------------------------------- */

  /**
   * The default order is alphabetical ascending by name, which is NOT the
   * order the tree answered nor the tid-keyed order of entity_load().
   */
  public function testDefaultOrderIsAlphabeticalAscending() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 3, 'name' => 'Transferencia', 'type' => 'Bancaria'],
      ['tid' => 1, 'name' => 'Cheque', 'type' => 'Cheque'],
      ['tid' => 2, 'name' => 'Efectivo', 'type' => 'cash'],
    ]);

    $result = $this->request();

    $this->assertSame(['Cheque', 'Efectivo', 'Transferencia'], $this->names($result));
  }

  /**
   * ?sort=asc is the default made explicit; ?sort=desc is exactly the reverse.
   */
  public function testSortDescIsExactlyAscReversed() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 1, 'name' => 'Cheque', 'type' => 'a'],
      ['tid' => 2, 'name' => 'Efectivo', 'type' => 'b'],
      ['tid' => 3, 'name' => 'Transferencia', 'type' => 'c'],
    ]);

    $_GET['sort'] = 'asc';
    $asc = $this->names($this->request());

    $_GET['sort'] = 'desc';
    $desc = $this->names($this->request());

    $this->assertSame(['Cheque', 'Efectivo', 'Transferencia'], $asc);
    $this->assertSame(array_reverse($asc), $desc);
  }

  /**
   * Any other value of ?sort — uppercase, another field, an empty string, a
   * number, an array — falls back to ascending and never raises a 422.
   */
  public function testAnyOtherSortValueFallsBackToAscending() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 1, 'name' => 'Transferencia', 'type' => 'a'],
      ['tid' => 2, 'name' => 'Cheque', 'type' => 'b'],
    ]);

    foreach (['ASC', 'DESC', 'Desc', 'name', '', '1', 'descending', ['desc']] as $value) {
      $_GET['sort'] = $value;

      $result = $this->request();

      $this->assertSame(200, $result['status'], json_encode($value));
      $this->assertSame(['Cheque', 'Transferencia'], $this->names($result), json_encode($value));
    }
  }

  /**
   * The order is case-insensitive: strcasecmp() is what compares the names, so
   * a lowercase method does not sink to the bottom of the list.
   */
  public function testOrderIsCaseInsensitive() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 1, 'name' => 'zelle', 'type' => 'a'],
      ['tid' => 2, 'name' => 'Efectivo', 'type' => 'b'],
      ['tid' => 3, 'name' => 'cheque', 'type' => 'c'],
    ]);

    $result = $this->request();

    $this->assertSame(['cheque', 'Efectivo', 'zelle'], $this->names($result));
  }

  /**
   * The order is applied AFTER the filter, so a dropped method never takes a
   * position: the answered list is contiguous and each item keeps its own id
   * and description.
   */
  public function testOrderIsAppliedOverTheFilteredListAndKeepsEachItemWhole() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 5, 'name' => 'Transferencia', 'description' => 'cta 1', 'type' => 'Bancaria'],
      ['tid' => 6, 'name' => 'Beta', 'type' => NULL],
      ['tid' => 7, 'name' => 'Cheque', 'description' => 'cta 2', 'type' => 'Cheque'],
    ]);

    $result = $this->request();

    $this->assertSame([
      ['id' => 7, 'name' => 'Cheque', 'type_method' => 'Cheque', 'description' => 'cta 2'],
      ['id' => 5, 'name' => 'Transferencia', 'type_method' => 'Bancaria', 'description' => 'cta 1'],
    ], $result['json']['data']['payment_methods']);
  }

  /**
   * The comparison runs over the SANITIZED name, the same way the banks
   * catalogue does: the usort() comes after the check_plain(), so a name
   * starting with a quote sorts by its escaped form ('&#039;') and not by the
   * raw byte. No real payment method starts with one — the case is here so the
   * two catalogues cannot silently diverge on it.
   */
  public function testOrderIsAppliedOverTheSanitizedName() {
    $this->authenticateAs();
    $this->seedMethods([
      ['tid' => 1, 'name' => '"Beta', 'type' => 'a'],
      ['tid' => 2, 'name' => "'Alfa", 'type' => 'b'],
    ]);

    $result = $this->request();

    $this->assertSame(['&#039;Alfa', '&quot;Beta'], $this->names($result));
  }

  /**
   * A larger catalogue comes back fully ordered, and the count is preserved:
   * nothing is lost or duplicated by the sort.
   */
  public function testALargerCatalogueComesBackFullyOrdered() {
    $names = ['Zelle', 'Efectivo', 'Transferencia', 'Cheque', 'Débito', 'Crédito', 'PayPal', 'Depósito'];
    $methods = [];
    foreach ($names as $i => $name) {
      $methods[] = ['tid' => $i + 1, 'name' => $name, 'type' => 'tipo' . $i];
    }

    $this->authenticateAs();
    $this->seedMethods($methods);

    $answered = $this->names($this->request());

    $expected = $names;
    usort($expected, 'strcasecmp');
    $this->assertSame($expected, $answered);
    $this->assertCount(count($names), $answered);
  }
}
