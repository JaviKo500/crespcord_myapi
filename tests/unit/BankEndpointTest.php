<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../resources/bank.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/banks (SPEC 18, covered by SPEC 76).
 *
 * myapi_bank_dispatch() is called the way hook_menu() calls it, over a fixture
 * `bancos` vocabulary (see the SPEC 76 block in bootstrap.php), a fixture
 * my_api_tokens row and a fixture Authorization header. What gets asserted is
 * the JSON body the module prints and the status code it sets — the same bytes
 * the Flutter app receives.
 *
 * Three things live only here and nowhere else in the project:
 *  - the method guard (405 before authentication),
 *  - the access-token guard as this endpoint applies it (SPEC 18 made the
 *    catalogue non-public on purpose, because the descriptions carry account
 *    numbers), and
 *  - the ordering, which is the whole reason the resource does not simply
 *    return taxonomy_get_tree()'s natural order.
 *
 * What it does NOT prove is Drupal's half: that `bancos` exists on the site,
 * that taxonomy_get_tree() builds the hierarchy, applies term access or orders
 * by weight. Those answers come from the taxonomy API, and no unit test can
 * stand in for it — the stubs answer seeded rows.
 */
class BankEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_taxonomy_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['sort']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['sort']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
    myapi_test_taxonomy_seed();
  }

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
   * Seeds the `bancos` vocabulary with the given terms.
   *
   * A second vocabulary is always seeded first, so `bancos` never gets vid 1:
   * a resource that hard-coded the vid, or a stub that ignored it, would pass
   * against vid 1 by accident.
   *
   * @param array $terms
   *   Term rows in the order taxonomy_get_tree() answers them (weight order),
   *   which is exactly the order the resource is supposed to override.
   */
  private function seedBanks(array $terms) {
    myapi_test_taxonomy_seed([
      'tipos_de_gasto' => [['tid' => '99', 'name' => 'Alícuota', 'description' => '']],
      'bancos'         => $terms,
    ]);
  }

  /**
   * A term row.
   */
  private function term($tid, $name, $description = '') {
    return ['tid' => (string) $tid, 'name' => $name, 'description' => $description];
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function request() {
    return myapi_test_capture('myapi_bank_dispatch');
  }

  /**
   * The `name` of every bank in the answered order — the assertion most
   * ordering cases make.
   */
  private function names(array $result) {
    return array_column($result['json']['data']['banks'], 'name');
  }

  /* -------------------------------------------------------------------------
   * Method routing (SPEC 18, step 2).
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, before any authentication: a POST with
   * a perfectly valid token is still 405, and the message is the translated
   * one from the catalogue.
   */
  public function testEveryMethodOtherThanGetIs405() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

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
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->request();

    $this->assertSame([], myapi_test_db_queries());
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * GET reaches the list handler — proven by the authentication error it
   * answers, which only myapi_bank_list() can produce.
   */
  public function testGetIsRoutedToTheListHandler() {
    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /**
   * A lowercase verb is still a GET: the comparison goes through
   * myapi_request_method(), which upper-cases it. A client sending `get` gets
   * the catalogue, not a 405.
   */
  public function testLowercaseGetIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $result = $this->request();

    $this->assertSame(200, $result['status'], 'not 405: the request was routed as a GET');
  }

  /* -------------------------------------------------------------------------
   * The access token guard (SPEC 05, as SPEC 18 applies it).
   *
   * SPEC 18's central decision: the catalogue is NOT public, because the term
   * descriptions carry account numbers. Every case below asserts that the
   * vocabulary is never even loaded when the token fails.
   * ---------------------------------------------------------------------- */

  /**
   * No Authorization header: 401 missing_authorization, and not one taxonomy
   * call — the bank list never leaves the site for an anonymous caller.
   */
  public function testMissingAuthorizationHeaderIs401AndLoadsNoVocabulary() {
    $this->seedBanks([$this->term(3, 'Banco Pichincha', 'Cta. 2100')]);

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
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    foreach (['Token abc', 'Bearer', 'Bearer ', 'abc', 'Bearer a b'] as $header) {
      $_SERVER['HTTP_AUTHORIZATION'] = $header;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $header);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $header);
      $this->assertSame([], myapi_test_taxonomy_calls(), $header);
    }
  }

  /**
   * The scheme is matched case-insensitively (`/^Bearer\s+(\S+)$/i`), and the
   * separator is any whitespace: `bearer abc` and `Bearer\tabc` are read as
   * real bearer headers, so they fail on the TOKEN (invalid_token) and not on
   * the header shape (missing_authorization). The two codes send the app down
   * different paths, so which one a header produces is worth pinning.
   */
  public function testLowercaseSchemeAndTabSeparatorAreAcceptedAsBearerHeaders() {
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    foreach (['bearer some-other-token', "Bearer\tsome-other-token", 'BEARER some-other-token'] as $header) {
      $this->authenticateAs();
      $_SERVER['HTTP_AUTHORIZATION'] = $header;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $header);
      $this->assertSame('invalid_token', $result['json']['error_code'], $header);
    }
  }

  /**
   * And the same lowercase scheme carrying the RIGHT token is accepted whole:
   * the case-insensitive match is not just a different error code, it is a
   * working request.
   */
  public function testLowercaseSchemeWithAValidTokenIsAccepted() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'bearer ' . self::TOKEN;

    $result = $this->request();

    $this->assertSame(200, $result['status']);
  }

  /**
   * A token with no row in my_api_tokens: 401 invalid_token, a different code
   * from the one above — the app tells "log in" from "refresh" by it.
   */
  public function testUnknownTokenIs401InvalidToken() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-other-token';
    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame('Token inválido.', $result['json']['error']);
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * A revoked token (logout) is rejected even though the hash matches.
   */
  public function testRevokedTokenIs401() {
    $this->authenticateAs(3, ['revoked' => '1']);
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * An expired token is rejected: access_expires_at one second in the past.
   */
  public function testExpiredTokenIs401() {
    $this->authenticateAs(3, ['access_expires_at' => REQUEST_TIME - 1]);
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * The boundary of that comparison: a token expiring exactly at REQUEST_TIME
   * is still valid, because the guard rejects on `<` and not on `<=`.
   */
  public function testTokenExpiringExactlyNowIsStillValid() {
    $this->authenticateAs(3, ['access_expires_at' => REQUEST_TIME]);
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
  }

  /**
   * The token's user no longer exists: 401, not a 500 on a NULL account.
   */
  public function testTokenOfADeletedUserIs401() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);
    $GLOBALS['myapi_test_users'] = [];

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * A blocked user (status = 0) is rejected even with a live token.
   */
  public function testTokenOfABlockedUserIs401() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);
    $GLOBALS['myapi_test_users'][3]['status'] = 0;

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * Authorization is NOT authorization here: SPEC 18 put the catalogue behind
   * authentication only, so any valid token — any uid, any role, a user with
   * no unit at all — gets the same list. Two different users, byte-identical
   * bodies.
   */
  public function testAnyAuthenticatedUserGetsTheSameCatalogue() {
    $terms = [$this->term(5, 'Produbanco'), $this->term(3, 'Banco Pichincha', 'Cta. 2100')];

    $this->authenticateAs(3);
    $this->seedBanks($terms);
    $first = $this->request();

    $this->authenticateAs(41);
    $this->seedBanks($terms);
    $second = $this->request();

    $this->assertSame(200, $first['status']);
    $this->assertSame(200, $second['status']);
    $this->assertSame($first['output'], $second['output']);
  }

  /**
   * The endpoint costs exactly one query, the token lookup: the catalogue
   * itself comes from the taxonomy API, never from a db_select() of its own.
   */
  public function testTheOnlyQueryIsTheTokenLookup() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha'), $this->term(5, 'Produbanco')]);

    $this->request();

    $this->assertSame(['my_api_tokens'], array_column(myapi_test_db_queries(), 'table'));
  }

  /* -------------------------------------------------------------------------
   * The answer (SPEC 18, "Forma de respuesta").
   * ---------------------------------------------------------------------- */

  /**
   * The full body, compared whole and with types: the contract the app codes
   * against. `id` int, `name`/`description` strings, `banks` a list.
   */
  public function testFullAnswerHasTheDocumentedShape() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Banco Pichincha', 'Cuenta corriente 2100xxxxxx'),
      $this->term(5, 'Produbanco'),
    ]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'success' => TRUE,
      'data'    => [
        'banks' => [
          ['id' => 3, 'name' => 'Banco Pichincha', 'description' => 'Cuenta corriente 2100xxxxxx'],
          ['id' => 5, 'name' => 'Produbanco', 'description' => ''],
        ],
      ],
    ], $result['json']);
  }

  /**
   * And the bytes of that body: `banks` is a JSON ARRAY and each item prints
   * its three keys in the documented order. json_decode() to an assoc array
   * cannot tell `[]` from `{}`, so this is asserted on the raw output.
   */
  public function testAnswerIsPrintedAsAJsonArrayWithTheDocumentedKeyOrder() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha', 'Cta. 2100')]);

    $result = $this->request();

    $this->assertSame(
      '{"success":true,"data":{"banks":[{"id":3,"name":"Banco Pichincha","description":"Cta. 2100"}]}}',
      $result['output']
    );
  }

  /**
   * Every item carries exactly the three documented keys, whatever else the
   * term object holds — the assertion that catches a field leaking into the
   * catalogue.
   */
  public function testEveryItemHasExactlyThreeKeys() {
    $this->authenticateAs();
    $this->seedBanks([
      ['tid' => '3', 'name' => 'Banco Pichincha', 'description' => 'Cta. 2100', 'vid' => 2, 'weight' => -4, 'depth' => 0, 'parents' => [0]],
      ['tid' => '5', 'name' => 'Produbanco', 'description' => '', 'vid' => 2, 'weight' => 3, 'depth' => 0, 'parents' => [0]],
    ]);

    $result = $this->request();

    foreach ($result['json']['data']['banks'] as $bank) {
      $this->assertSame(['id', 'name', 'description'], array_keys($bank));
    }
  }

  /**
   * The sanitizing of SPEC 18 survives the full round trip: markup stored in a
   * term reaches the app escaped, in the JSON the endpoint actually prints.
   * The mapper is unit-tested on its own; this proves the endpoint uses it.
   */
  public function testTermMarkupIsEscapedInTheRealResponse() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, '<b>Pichincha</b>', "Cta. \"2100\" & 'ahorros'")]);

    $result = $this->request();

    $this->assertSame('&lt;b&gt;Pichincha&lt;/b&gt;', $result['json']['data']['banks'][0]['name']);
    $this->assertSame('Cta. &quot;2100&quot; &amp; &#039;ahorros&#039;', $result['json']['data']['banks'][0]['description']);
    $this->assertStringNotContainsString('<b>', $result['output']);
  }

  /**
   * A term saved with no description answers "" and not null, in the response
   * itself — the key is always present and always a string.
   */
  public function testTermWithoutDescriptionAnswersAnEmptyString() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(5, 'Produbanco', '')]);

    $result = $this->request();

    $this->assertSame('', $result['json']['data']['banks'][0]['description']);
    $this->assertStringContainsString('"description":""', $result['output']);
    $this->assertStringNotContainsString('null', $result['output']);
  }

  /**
   * One term is still a list of one, not a bare object: the app iterates the
   * same code path for a vocabulary of one bank.
   */
  public function testASingleTermIsStillAList() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $result = $this->request();

    $this->assertCount(1, $result['json']['data']['banks']);
    $this->assertStringContainsString('"banks":[{', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * The degraded cases (SPEC 18, "Caso degradado").
   * ---------------------------------------------------------------------- */

  /**
   * The `bancos` vocabulary does not exist (renamed, deleted, never created):
   * 200 with an empty list, NOT a 500 and not a leak of the machine name.
   * The tree is never asked for, which is the branch that would fatal on
   * FALSE->vid.
   */
  public function testMissingVocabularyAnswersAnEmptyListAndNeverAsksForATree() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed(['tipos_de_gasto' => [['tid' => '99', 'name' => 'Alícuota', 'description' => '']]]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['success' => TRUE, 'data' => ['banks' => []]], $result['json']);
    $this->assertSame('{"success":true,"data":{"banks":[]}}', $result['output']);
    $this->assertSame(['taxonomy_vocabulary_machine_name_load'], array_column(myapi_test_taxonomy_calls(), 'function'));
  }

  /**
   * The vocabulary exists but has no terms: same 200 with an empty list, this
   * time after asking for the tree. Both degraded paths answer identical
   * bytes, so the app has one empty state and not two.
   */
  public function testEmptyVocabularyAnswersAnEmptyList() {
    $this->authenticateAs();
    $this->seedBanks([]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame('{"success":true,"data":{"banks":[]}}', $result['output']);
    $this->assertSame(
      ['taxonomy_vocabulary_machine_name_load', 'taxonomy_get_tree'],
      array_column(myapi_test_taxonomy_calls(), 'function')
    );
  }

  /**
   * The empty list is printed as `[]` and never as `{}` — the difference
   * between `List<Bank>` and a runtime cast error in Dart.
   */
  public function testEmptyListIsAJsonArray() {
    $this->authenticateAs();
    $this->seedBanks([]);

    $result = $this->request();

    $this->assertStringContainsString('"banks":[]', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * The taxonomy calls: which vocabulary, which vid.
   * ---------------------------------------------------------------------- */

  /**
   * The machine name is `bancos`, exactly — the string the whole endpoint
   * hangs from, and the one thing a site rename breaks silently (into the
   * empty list above).
   */
  public function testTheVocabularyAskedForIsBancos() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $this->request();

    $calls = myapi_test_taxonomy_calls();
    $this->assertSame('taxonomy_vocabulary_machine_name_load', $calls[0]['function']);
    $this->assertSame(['bancos'], $calls[0]['args']);
  }

  /**
   * The tree is asked for with the vid of the vocabulary just loaded, not a
   * hard-coded one: the fixture puts `bancos` at vid 2 on purpose.
   */
  public function testTheTreeIsAskedForWithTheLoadedVid() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'Banco Pichincha')]);

    $this->request();

    $calls = myapi_test_taxonomy_calls();
    $this->assertSame('taxonomy_get_tree', $calls[1]['function']);
    $this->assertSame(2, $calls[1]['args'][0]);
  }

  /**
   * The tree is asked for once per request, whatever the sort — the ordering
   * is done in PHP over the single tree, not by re-querying.
   */
  public function testTheTreeIsAskedForOnlyOnce() {
    $this->authenticateAs();
    $this->seedBanks([$this->term(3, 'A'), $this->term(5, 'B')]);
    $_GET['sort'] = 'desc';

    $this->request();

    $this->assertCount(2, myapi_test_taxonomy_calls());
  }

  /* -------------------------------------------------------------------------
   * Ordering (SPEC 18's `sort`, as implemented over `name`).
   *
   * NOTE ON THE SPEC: specs/banks/18-banks-list.md describes `sort` as an
   * ordering over `id`. The shipped resource and docs/bank.md order
   * alphabetically over `name` (commit 342b9a5), and these cases pin the
   * SHIPPED behaviour, which is the one the app consumes. The drift is
   * recorded in specs/banks/76-banks-unit-tests.md.
   * ---------------------------------------------------------------------- */

  /**
   * With no `sort`, banks come back A→Z by name — NOT in the order
   * taxonomy_get_tree() answers (weight, then name), which the fixture
   * deliberately scrambles. Without the usort() the app would show the
   * editorial weight order.
   */
  public function testDefaultOrderIsAlphabeticalAscending() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Produbanco'),
      $this->term(9, 'Banco Pichincha'),
      $this->term(5, 'Austro'),
    ]);

    $result = $this->request();

    $this->assertSame(['Austro', 'Banco Pichincha', 'Produbanco'], $this->names($result));
  }

  /**
   * `?sort=asc` is the same order, explicitly.
   */
  public function testSortAscIsAlphabeticalAscending() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Produbanco'),
      $this->term(9, 'Banco Pichincha'),
      $this->term(5, 'Austro'),
    ]);
    $_GET['sort'] = 'asc';

    $result = $this->request();

    $this->assertSame(['Austro', 'Banco Pichincha', 'Produbanco'], $this->names($result));
  }

  /**
   * `?sort=desc` is Z→A.
   */
  public function testSortDescIsAlphabeticalDescending() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Produbanco'),
      $this->term(9, 'Banco Pichincha'),
      $this->term(5, 'Austro'),
    ]);
    $_GET['sort'] = 'desc';

    $result = $this->request();

    $this->assertSame(['Produbanco', 'Banco Pichincha', 'Austro'], $this->names($result));
  }

  /**
   * desc is exactly asc reversed for distinct names — the comparator negates
   * one result, it does not sort by a different criterion.
   */
  public function testDescIsExactlyAscReversed() {
    $terms = [
      $this->term(3, 'Produbanco'),
      $this->term(9, 'Banco Pichincha'),
      $this->term(5, 'Austro'),
      $this->term(7, 'Guayaquil'),
    ];

    $this->authenticateAs();
    $this->seedBanks($terms);
    $asc = $this->names($this->request());

    $this->authenticateAs();
    $this->seedBanks($terms);
    $_GET['sort'] = 'desc';
    $desc = $this->names($this->request());

    $this->assertSame(array_reverse($asc), $desc);
  }

  /**
   * Every value that is not exactly 'asc' or 'desc' falls back to ascending,
   * silently and with no 422 — the lax rule SPEC 18 borrowed from
   * payment/expense. Uppercase 'ASC' is in the list on purpose: it is the
   * value a client is most likely to send by mistake.
   */
  public function testAnyOtherSortValueFallsBackToAscending() {
    $ascending = ['Austro', 'Banco Pichincha', 'Produbanco'];

    foreach (['ASC', 'DESC', 'Asc', ' asc', 'asc ', '', 'name', 'id', 'weight', '1', '0', 'null'] as $value) {
      $this->authenticateAs();
      $this->seedBanks([
        $this->term(3, 'Produbanco'),
        $this->term(9, 'Banco Pichincha'),
        $this->term(5, 'Austro'),
      ]);
      $_GET['sort'] = $value;

      $result = $this->request();

      $this->assertSame(200, $result['status'], var_export($value, TRUE));
      $this->assertSame($ascending, $this->names($result), var_export($value, TRUE));
    }
  }

  /**
   * `?sort[]=desc` — an array where a string is expected — does not fatal and
   * does not order descending: in_array(..., TRUE) rejects it and the default
   * applies. The one input shape that turns a lax filter into a 500 if it is
   * ever compared loosely or passed to a string function.
   */
  public function testAnArraySortValueIsIgnored() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Produbanco'),
      $this->term(9, 'Banco Pichincha'),
    ]);
    $_GET['sort'] = ['desc'];

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['Banco Pichincha', 'Produbanco'], $this->names($result));
  }

  /**
   * The comparison is case-insensitive (strcasecmp), and the fixture is built
   * so that a case-SENSITIVE comparison would answer a different order: every
   * uppercase initial (0x41-0x5A) sorts before every lowercase one (0x61-0x7A),
   * so strcmp() would put 'Produbanco' ahead of 'banco del austro' and pile the
   * lowercase names at the end regardless of their letters.
   */
  public function testOrderIsCaseInsensitive() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'banco general'),
      $this->term(9, 'BANCO amazonas'),
      $this->term(5, 'Banco Bolivariano'),
      $this->term(7, 'Produbanco'),
      $this->term(11, 'banco del austro'),
    ]);

    $result = $this->request();

    $this->assertSame([
      'BANCO amazonas',
      'Banco Bolivariano',
      'banco del austro',
      'banco general',
      'Produbanco',
    ], $this->names($result));
  }

  /**
   * Ordering happens AFTER check_plain(), over the escaped string: a name
   * starting with a single quote (escaped to `&#039;`) sorts before one
   * starting with a double quote (escaped to `&quot;`), which is the opposite
   * of what the raw characters would give ( `"` = 0x22 < `'` = 0x27 ).
   *
   * Pinned as behaviour, not as a recommendation: it is what the shipped
   * comparator does, and it only shows up for names that begin with an HTML
   * special character.
   */
  public function testOrderIsAppliedOverTheSanitizedName() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, '"Beta'),
      $this->term(9, "'Alfa"),
    ]);

    $result = $this->request();

    $this->assertSame(['&#039;Alfa', '&quot;Beta'], $this->names($result));
  }

  /**
   * strcasecmp() compares bytes, so a name starting with an accented capital
   * sorts AFTER every ASCII name instead of where a Spanish reader expects
   * it: "Ábaco" lands last, not first. Documented in SPEC 76 as a finding, not
   * fixed here — changing the comparator changes the order the app already
   * renders.
   */
  public function testAccentedInitialsSortAfterEveryAsciiName() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Ábaco'),
      $this->term(9, 'Banco Zeta'),
      $this->term(5, 'Banco Amazonas'),
    ]);

    $result = $this->request();

    $this->assertSame(['Banco Amazonas', 'Banco Zeta', 'Ábaco'], $this->names($result));
  }

  /**
   * A leading space sorts a bank to the top, because the space is compared and
   * nothing trims the term name. The kind of thing an editor does by accident;
   * the app shows it first.
   */
  public function testALeadingSpaceSortsFirst() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Austro'),
      $this->term(9, ' Produbanco'),
    ]);

    $result = $this->request();

    $this->assertSame([' Produbanco', 'Austro'], $this->names($result));
  }

  /**
   * A shorter name that is a prefix of a longer one sorts first, both ways.
   */
  public function testAPrefixSortsBeforeTheLongerName() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Banco Pichincha Sucursal Norte'),
      $this->term(9, 'Banco Pichincha'),
    ]);

    $result = $this->request();

    $this->assertSame(['Banco Pichincha', 'Banco Pichincha Sucursal Norte'], $this->names($result));
  }

  /**
   * Two terms sharing a name both survive the sort with their own id: the
   * comparator never collapses them, whichever order they end up in.
   *
   * The relative order of the tie is deliberately NOT asserted: usort() is
   * stable since PHP 8.0 but not on the 7.4 that runs production, so pinning
   * it here would pin a behaviour the server does not guarantee.
   */
  public function testTermsSharingANameAreBothKept() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Produbanco', 'Cta. corriente'),
      $this->term(9, 'Produbanco', 'Cta. de ahorros'),
      $this->term(5, 'Austro'),
    ]);

    $result = $this->request();

    $ids = array_column($result['json']['data']['banks'], 'id');
    $this->assertSame('Austro', $this->names($result)[0]);
    $this->assertCount(3, $ids);
    $this->assertEqualsCanonicalizing([5, 3, 9], $ids);
  }

  /**
   * Sorting reindexes the list: after usort() the keys are 0..n-1, so
   * json_encode() prints an array. A sort that preserved keys would print an
   * OBJECT keyed by position — the classic PHP-to-JSON break.
   */
  public function testSortedListIsStillPrintedAsAnArray() {
    // Seeded in an order that is neither ascending nor descending, so both
    // sorts have to MOVE items: a key-preserving sort (uasort) would leave the
    // keys as 1,2,0 and json_encode() would print {"1":{…}} instead of [{…}].
    $terms = [
      $this->term(3, 'Produbanco'),
      $this->term(5, 'Austro'),
      $this->term(9, 'Banco Pichincha'),
    ];

    foreach (['asc', 'desc'] as $sort) {
      $this->authenticateAs();
      $this->seedBanks($terms);
      $_GET['sort'] = $sort;

      $result = $this->request();

      $this->assertStringContainsString('"banks":[{', $result['output'], $sort);
      $this->assertStringNotContainsString('"banks":{', $result['output'], $sort);
      $this->assertSame([0, 1, 2], array_keys($result['json']['data']['banks']), $sort);
    }
  }

  /**
   * Ordering moves whole items: the description travels with its own bank and
   * is not left behind on the row it used to occupy. The failure this catches
   * would ship one bank's account number under another bank's name.
   */
  public function testEachItemKeepsItsOwnIdAndDescriptionAfterSorting() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(3, 'Produbanco', 'Cta. 3333'),
      $this->term(9, 'Banco Pichincha', 'Cta. 9999'),
      $this->term(5, 'Austro', 'Cta. 5555'),
    ]);
    $_GET['sort'] = 'desc';

    $result = $this->request();

    $this->assertSame([
      ['id' => 3, 'name' => 'Produbanco', 'description' => 'Cta. 3333'],
      ['id' => 9, 'name' => 'Banco Pichincha', 'description' => 'Cta. 9999'],
      ['id' => 5, 'name' => 'Austro', 'description' => 'Cta. 5555'],
    ], $result['json']['data']['banks']);
  }

  /**
   * Ordering by name is not ordering by id: with ids and names deliberately in
   * opposite orders, the answer follows the names. This is the case that would
   * fail if the resource went back to SPEC 18's original `id` ordering, and
   * the reason the drift is documented instead of left to be discovered.
   */
  public function testOrderFollowsTheNameAndNotTheId() {
    $this->authenticateAs();
    $this->seedBanks([
      $this->term(1, 'Zeta'),
      $this->term(2, 'Yaguar'),
      $this->term(3, 'Austro'),
    ]);

    $result = $this->request();

    $this->assertSame([3, 2, 1], array_column($result['json']['data']['banks'], 'id'));
  }

  /**
   * A larger catalogue stays fully ordered — no off-by-one at the ends of the
   * comparator.
   */
  public function testALargerCatalogueComesBackFullyOrdered() {
    $names = ['Solidario', 'Machala', 'Bolivariano', 'Guayaquil', 'Internacional', 'Austro', 'Produbanco', 'Pichincha', 'Loja', 'Amazonas'];
    $terms = [];
    foreach ($names as $i => $name) {
      $terms[] = $this->term(100 - $i, $name);
    }

    $this->authenticateAs();
    $this->seedBanks($terms);

    $result = $this->request();

    $expected = $names;
    usort($expected, 'strcasecmp');
    $this->assertSame($expected, $this->names($result));
    $this->assertCount(10, $result['json']['data']['banks']);
  }

}
