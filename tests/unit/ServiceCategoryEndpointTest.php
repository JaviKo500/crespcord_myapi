<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../resources/service_category.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/service-categories (SPEC 79).
 *
 * myapi_service_category_dispatch() is called the way hook_menu() calls it,
 * over a fixture `service_category` vocabulary, a fixture my_api_tokens row and
 * a fixture Authorization header. What gets asserted is the JSON body the
 * module prints and the status code it sets — the same bytes the Flutter app
 * receives.
 *
 * Four things live only here and nowhere else:
 *  - the method guard (405 before authentication),
 *  - the access-token guard as this endpoint applies it (a valid token is the
 *    whole authorization: no role, no condominium, no unit),
 *  - the hydration, i.e. that the terms are batch-loaded so the code and the
 *    icon are there at all, and
 *  - the ordering and the lax ?sort rule.
 *
 * What it does NOT prove is Drupal's half: that `service_category` exists on
 * the site, that taxonomy_get_tree() applies term access, or that entity_load()
 * really reads field_data_field_category_code. Those answers come from the
 * taxonomy and Field APIs; the stubs answer seeded rows.
 */
class ServiceCategoryEndpointTest extends TestCase {

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
   * Seeds the `service_category` vocabulary with the given terms.
   *
   * The tree gets the LIGHT rows (tid and name only, the way
   * taxonomy_get_tree() answers) and entity_load() gets the full ones: a
   * resource that skipped the hydration would therefore answer every category
   * with code "" and no icon, which is the production failure this split
   * reproduces.
   *
   * A second vocabulary is always seeded first, so `service_category` never
   * gets vid 1: a resource that hard-coded the vid would pass by accident.
   *
   * @param array $terms
   *   Term rows in the order taxonomy_get_tree() answers them (weight order),
   *   which is exactly the order the resource is supposed to override.
   */
  private function seedCategories(array $terms) {
    $light = array_map(function ($term) {
      return [
        'tid'         => $term['tid'],
        'name'        => $term['name'],
        'description' => isset($term['description']) ? $term['description'] : '',
      ];
    }, $terms);

    myapi_test_taxonomy_seed([
      'bancos'           => [['tid' => '99', 'name' => 'Banco Pichincha', 'description' => '']],
      'service_category' => $light,
    ]);
    myapi_test_taxonomy_entities_seed($terms);
  }

  /**
   * A full term row: code and icon included unless they are passed as NULL.
   */
  private function term($tid, $name, $code = 'code', $description = '', $icon = NULL) {
    $term = [
      'tid'         => (string) $tid,
      'name'        => $name,
      'description' => $description,
    ];

    if ($code !== NULL) {
      $term['field_category_code'] = [LANGUAGE_NONE => [['value' => $code]]];
    }
    if ($icon !== NULL) {
      $term['field_category_icon'] = [LANGUAGE_NONE => [$icon]];
    }

    return $term;
  }

  /**
   * The icon value of a term, as Field API stores it.
   */
  private function icon($fid, $filename) {
    return ['fid' => (string) $fid, 'uri' => 'public://category-icons/' . $filename];
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function request() {
    return myapi_test_capture('myapi_service_category_dispatch');
  }

  /**
   * The categories of the answer.
   */
  private function categories(array $result) {
    return $result['json']['data']['service_categories'];
  }

  /**
   * The `name` of every category in the answered order.
   */
  private function names(array $result) {
    return array_column($this->categories($result), 'name');
  }

  /* -------------------------------------------------------------------------
   * Method routing (SPEC 79, step 3).
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, before any authentication: a POST with
   * a perfectly valid token is still 405, with the translated catalogue
   * message. The collection is read-only — categories are loaded from
   * admin/structure/taxonomy, never over the API.
   */
  public function testEveryMethodOtherThanGetIs405() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

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
   * The 405 costs nothing: no token lookup and no taxonomy call.
   */
  public function testRejectedMethodTouchesNeitherDatabaseNorTaxonomy() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->request();

    $this->assertSame([], myapi_test_db_queries());
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * GET reaches the list handler — proven by the authentication error it
   * answers, which only myapi_service_category_list() can produce.
   */
  public function testGetIsRoutedToTheListHandler() {
    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /**
   * A lowercase verb is still a GET: myapi_request_method() upper-cases it.
   */
  public function testLowercaseGetIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

    $result = $this->request();

    $this->assertSame(200, $result['status'], 'not 405: the request was routed as a GET');
  }

  /* -------------------------------------------------------------------------
   * The access token guard (SPEC 05, as SPEC 79 applies it).
   * ---------------------------------------------------------------------- */

  /**
   * No Authorization header: 401 missing_authorization, and not one taxonomy
   * call.
   */
  public function testMissingAuthorizationHeaderIs401AndLoadsNoVocabulary() {
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame('No se proporcionó token de acceso.', $result['json']['error']);
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * A header that is not "Bearer <token>" is treated as no header at all.
   */
  public function testMalformedAuthorizationHeaderIs401() {
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

    foreach (['Token abc', 'Bearer', 'Bearer ', 'abc', 'Bearer a b'] as $header) {
      $_SERVER['HTTP_AUTHORIZATION'] = $header;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $header);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $header);
      $this->assertSame([], myapi_test_taxonomy_calls(), $header);
    }
  }

  /**
   * A token with no row in my_api_tokens: 401 invalid_token, a different code
   * from the one above — the app tells "log in" from "refresh" by it.
   */
  public function testUnknownTokenIs401InvalidToken() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-other-token';
    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame('Token inválido.', $result['json']['error']);
    $this->assertSame([], myapi_test_taxonomy_calls());
  }

  /**
   * A revoked token (logout) is rejected even though the hash matches, and an
   * expired one too. Both are the acceptance criterion "token inexistente,
   * revocado o caducado -> 401 invalid_token".
   */
  public function testRevokedAndExpiredTokensAre401() {
    foreach ([['revoked' => '1'], ['access_expires_at' => REQUEST_TIME - 1]] as $overrides) {
      $this->authenticateAs(3, $overrides);
      $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

      $result = $this->request();

      $this->assertSame(401, $result['status'], json_encode($overrides));
      $this->assertSame('invalid_token', $result['json']['error_code'], json_encode($overrides));
      $this->assertSame([], myapi_test_taxonomy_calls(), json_encode($overrides));
    }
  }

  /**
   * A deleted or blocked user is rejected as well, with no 500 on a NULL
   * account.
   */
  public function testTokenOfADeletedOrBlockedUserIs401() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $GLOBALS['myapi_test_users'] = [];

    $deleted = $this->request();

    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $GLOBALS['myapi_test_users'][3]['status'] = 0;

    $blocked = $this->request();

    $this->assertSame(401, $deleted['status']);
    $this->assertSame('invalid_token', $deleted['json']['error_code']);
    $this->assertSame(401, $blocked['status']);
    $this->assertSame('invalid_token', $blocked['json']['error_code']);
  }

  /**
   * Authentication is the whole authorization: SPEC 79 puts no role,
   * condominium or unit check on the catalogue, so any valid token — a plain
   * resident, a user with no unit at all — gets the same list. Two different
   * users, byte-identical bodies.
   */
  public function testAnyAuthenticatedUserGetsTheSameCatalogue() {
    $terms = [
      $this->term(5, 'Electricidad', 'electricity'),
      $this->term(3, 'Plomería', 'plumbing', '<p>Tuberías</p>', $this->icon(42, 'plumbing.png')),
    ];

    $this->authenticateAs(3);
    $this->seedCategories($terms);
    $first = $this->request();

    $this->authenticateAs(41);
    $this->seedCategories($terms);
    $second = $this->request();

    $this->assertSame(200, $first['status']);
    $this->assertSame(200, $second['status']);
    $this->assertSame($first['output'], $second['output']);
  }

  /**
   * The endpoint costs exactly one query, the token lookup: the catalogue
   * comes from the taxonomy and entity APIs, never from a db_select() of its
   * own. No role table, no unit table — the proof that the list is not
   * filtered per user.
   */
  public function testTheOnlyQueryIsTheTokenLookup() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);

    $this->request();

    $this->assertSame(['my_api_tokens'], array_column(myapi_test_db_queries(), 'table'));
  }

  /* -------------------------------------------------------------------------
   * The answer (SPEC 79, "El ítem de la respuesta").
   * ---------------------------------------------------------------------- */

  /**
   * The full body, compared whole and with types: the contract the app codes
   * against, with a category that has an icon and one that does not.
   */
  public function testFullAnswerHasTheDocumentedShape() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing', '<p>Instalación y reparación de tuberías.</p>', $this->icon(42, 'plumbing.png')),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'success' => TRUE,
      'data'    => [
        'service_categories' => [
          [
            'id'          => 5,
            'code'        => 'electricity',
            'name'        => 'Electricidad',
            'description' => '',
            'icon_id'     => NULL,
            'icon_url'    => NULL,
          ],
          [
            'id'          => 3,
            'code'        => 'plumbing',
            'name'        => 'Plomería',
            'description' => 'Instalación y reparación de tuberías.',
            'icon_id'     => 42,
            'icon_url'    => 'https://crespcord.example.com/sites/default/files/category-icons/plumbing.png',
          ],
        ],
      ],
    ], $result['json']);
  }

  /**
   * And the bytes of that body: `service_categories` is a JSON ARRAY and each
   * item prints its six keys in the documented order. json_decode() to an
   * assoc array cannot tell `[]` from `{}`, so this is asserted on the raw
   * output.
   *
   * The accents travel as \u escapes: drupal_json_encode() does not pass
   * JSON_UNESCAPED_UNICODE, so "Plomería" is printed as "Plomería". Same
   * as every other endpoint of the module, and transparent to any JSON parser.
   */
  public function testAnswerIsPrintedAsAJsonArrayWithTheDocumentedKeyOrder() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing', 'Tuberías', $this->icon(42, 'plumbing.png')),
    ]);

    $result = $this->request();

    $this->assertSame(
      '{"success":true,"data":{"service_categories":[{"id":3,"code":"plumbing","name":"Plomer\\u00eda",'
      . '"description":"Tuber\\u00edas","icon_id":42,'
      . '"icon_url":"https:\/\/crespcord.example.com\/sites\/default\/files\/category-icons\/plumbing.png"}]}}',
      $result['output']
    );
  }

  /**
   * Every item carries exactly the six documented keys, whatever else the term
   * object holds — the assertion that catches a field leaking into the
   * catalogue.
   */
  public function testEveryItemHasExactlySixKeys() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing', 'Tuberías', $this->icon(42, 'plumbing.png')),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);

    $result = $this->request();

    foreach ($this->categories($result) as $category) {
      $this->assertSame(
        ['id', 'code', 'name', 'description', 'icon_id', 'icon_url'],
        array_keys($category)
      );
    }
  }

  /**
   * `id` is an int in the printed JSON, not a string: the database answers
   * tids as strings and Dart compares them as ints.
   */
  public function testIdIsPrintedAsAnInteger() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

    $result = $this->request();

    $this->assertSame(3, $this->categories($result)[0]['id']);
    $this->assertStringContainsString('"id":3', $result['output']);
    $this->assertStringNotContainsString('"id":"3"', $result['output']);
  }

  /**
   * A term with no code is listed with code "" — it is NOT dropped. The
   * acceptance criterion that separates this resource from payment-methods.
   */
  public function testTermWithoutCodeIsListedWithAnEmptyCode() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', NULL),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);

    $result = $this->request();

    $this->assertCount(2, $this->categories($result));
    $this->assertSame('', $this->categories($result)[1]['code']);
    $this->assertSame(3, $this->categories($result)[1]['id']);
  }

  /**
   * The plain-text description survives the full round trip: markup, entities
   * and the trailing &nbsp; are gone in the JSON the endpoint actually prints,
   * and the text is NOT escaped. The mapper is unit-tested on its own; this
   * proves the endpoint uses it.
   */
  public function testDescriptionIsFlattenedAndUnescapedInTheRealResponse() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing', '<p>Hola <strong>mundo</strong></p>&nbsp;'),
      $this->term(5, 'Electricidad', 'electricity', 'Cortes &amp; instalaciones'),
    ]);

    $result = $this->request();

    $this->assertSame('Cortes & instalaciones', $this->categories($result)[0]['description']);
    $this->assertSame('Hola mundo', $this->categories($result)[1]['description']);
    $this->assertStringNotContainsString('<strong>', $result['output']);
    $this->assertStringNotContainsString('&amp;', $result['output']);
  }

  /**
   * A term with no description answers "" and not null, in the response
   * itself.
   */
  public function testTermWithoutDescriptionAnswersAnEmptyString() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(5, 'Electricidad', 'electricity', '')]);

    $result = $this->request();

    $this->assertSame('', $this->categories($result)[0]['description']);
    $this->assertStringContainsString('"description":""', $result['output']);
  }

  /**
   * A category with no icon prints both keys as null — the app finds them and
   * reads one to know about both.
   */
  public function testCategoryWithoutIconPrintsBothKeysAsNull() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(5, 'Electricidad', 'electricity')]);

    $result = $this->request();

    $this->assertNull($this->categories($result)[0]['icon_id']);
    $this->assertNull($this->categories($result)[0]['icon_url']);
    $this->assertStringContainsString('"icon_id":null,"icon_url":null', $result['output']);
  }

  /**
   * Across the whole answer, `icon_id` and `icon_url` are never one without
   * the other — the invariant stated as a rule in SPEC 79, checked over a
   * mixed catalogue.
   */
  public function testIconKeysAreAlwaysBothFilledOrBothNull() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing', '', $this->icon(42, 'plumbing.png')),
      $this->term(5, 'Electricidad', 'electricity'),
      $this->term(7, 'Jardinería', 'gardening', '', $this->icon(43, 'gardening.png')),
      $this->term(9, 'Cerrajería', 'locksmith'),
    ]);

    $result = $this->request();

    foreach ($this->categories($result) as $category) {
      $this->assertSame(
        $category['icon_id'] === NULL,
        $category['icon_url'] === NULL,
        $category['name']
      );
    }
  }

  /**
   * One term is still a list of one, not a bare object.
   */
  public function testASingleTermIsStillAList() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

    $result = $this->request();

    $this->assertCount(1, $this->categories($result));
    $this->assertStringContainsString('"service_categories":[{', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * The hydration: without it there is no code and no icon.
   * ---------------------------------------------------------------------- */

  /**
   * The vocabulary is asked for by its constant, `service_category`, and the
   * tree with the vid just loaded — the fixture puts it at vid 2 on purpose,
   * so a hard-coded vid would fail here.
   */
  public function testTheVocabularyAndTheVidAskedFor() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);

    $this->request();

    $calls = myapi_test_taxonomy_calls();
    $this->assertSame('taxonomy_vocabulary_machine_name_load', $calls[0]['function']);
    $this->assertSame([MYAPI_SERVICES_CATEGORY_VOCABULARY], $calls[0]['args']);
    $this->assertSame('service_category', MYAPI_SERVICES_CATEGORY_VOCABULARY);
    $this->assertSame('taxonomy_get_tree', $calls[1]['function']);
    $this->assertSame(2, $calls[1]['args'][0]);
  }

  /**
   * The terms are loaded ONCE, in a single batched entity_load() carrying
   * every tid of the tree — not one call per term. With five categories the
   * difference is invisible; the shape of the call is what keeps it that way.
   */
  public function testTermsAreHydratedInASingleBatchedCall() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
      $this->term(7, 'Jardinería', 'gardening'),
    ]);

    $this->request();

    $calls = myapi_test_taxonomy_calls();
    $this->assertSame(
      ['taxonomy_vocabulary_machine_name_load', 'taxonomy_get_tree', 'entity_load'],
      array_column($calls, 'function')
    );
    $this->assertSame('taxonomy_term', $calls[2]['args'][0]);
    $this->assertSame(['3', '5', '7'], $calls[2]['args'][1]);
  }

  /**
   * And the hydration is what puts the code and the icon in the answer: the
   * fixture's tree rows carry neither, exactly as taxonomy_get_tree() answers
   * in production. A resource that mapped the tree directly would answer
   * code "" and a null icon here.
   */
  public function testTheAnswerCarriesTheHydratedFieldValues() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing', '', $this->icon(42, 'plumbing.png')),
    ]);

    $result = $this->request();

    $this->assertSame('plumbing', $this->categories($result)[0]['code']);
    $this->assertSame(42, $this->categories($result)[0]['icon_id']);
  }

  /**
   * entity_load() answers a tid-keyed map; the response must be a JSON array.
   * A missing array_values() would print an OBJECT keyed by tid — the classic
   * PHP-to-JSON break, and the reason this case is asserted on the bytes.
   */
  public function testTheTidKeyedMapIsPrintedAsAList() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(31, 'Plomería', 'plumbing'),
      $this->term(52, 'Electricidad', 'electricity'),
    ]);

    $result = $this->request();

    $this->assertStringContainsString('"service_categories":[{', $result['output']);
    $this->assertStringNotContainsString('"service_categories":{', $result['output']);
    $this->assertSame([0, 1], array_keys($this->categories($result)));
  }

  /* -------------------------------------------------------------------------
   * The degraded cases (SPEC 79, "Casos degradados").
   * ---------------------------------------------------------------------- */

  /**
   * The `service_category` vocabulary does not exist (never created, renamed,
   * deleted): 200 with an empty list, NOT a 404 and not a 500. The tree is
   * never asked for, which is the branch that would fatal on FALSE->vid.
   */
  public function testMissingVocabularyAnswersAnEmptyListAndNeverAsksForATree() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed(['bancos' => [['tid' => '99', 'name' => 'Banco Pichincha', 'description' => '']]]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['success' => TRUE, 'data' => ['service_categories' => []]], $result['json']);
    $this->assertSame('{"success":true,"data":{"service_categories":[]}}', $result['output']);
    $this->assertSame(['taxonomy_vocabulary_machine_name_load'], array_column(myapi_test_taxonomy_calls(), 'function'));
  }

  /**
   * The vocabulary exists but has no terms: the same 200 with an empty list,
   * this time after asking for the tree and WITHOUT hydrating anything —
   * entity_load([]) would be a pointless query. Both degraded paths answer
   * identical bytes, so the app has one empty state and not two.
   */
  public function testEmptyVocabularyAnswersAnEmptyListAndHydratesNothing() {
    $this->authenticateAs();
    $this->seedCategories([]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame('{"success":true,"data":{"service_categories":[]}}', $result['output']);
    $this->assertSame(
      ['taxonomy_vocabulary_machine_name_load', 'taxonomy_get_tree'],
      array_column(myapi_test_taxonomy_calls(), 'function')
    );
  }

  /* -------------------------------------------------------------------------
   * Ordering and the `sort` parameter.
   * ---------------------------------------------------------------------- */

  /**
   * With no `sort`, categories come back A→Z by name, ignoring case — NOT in
   * the order taxonomy_get_tree() answers, which the fixture scrambles. The
   * acceptance criterion says it in these words: "electricidad" before
   * "Plomería".
   */
  public function testDefaultOrderIsAlphabeticalAscendingAndCaseInsensitive() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(9, 'electricidad', 'electricity'),
      $this->term(5, 'Cerrajería', 'locksmith'),
    ]);

    $result = $this->request();

    $this->assertSame(['Cerrajería', 'electricidad', 'Plomería'], $this->names($result));
  }

  /**
   * `?sort=asc` is the same order, explicitly; `?sort=desc` is exactly its
   * reverse.
   */
  public function testSortAscAndDescAreOneTheReverseOfTheOther() {
    $terms = [
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(9, 'electricidad', 'electricity'),
      $this->term(5, 'Cerrajería', 'locksmith'),
      $this->term(7, 'Jardinería', 'gardening'),
    ];

    $this->authenticateAs();
    $this->seedCategories($terms);
    $_GET['sort'] = 'asc';
    $asc = $this->names($this->request());

    $this->authenticateAs();
    $this->seedCategories($terms);
    $_GET['sort'] = 'desc';
    $desc = $this->names($this->request());

    $this->assertSame(['Cerrajería', 'electricidad', 'Jardinería', 'Plomería'], $asc);
    $this->assertSame(array_reverse($asc), $desc);
  }

  /**
   * Every value that is not exactly 'asc' or 'desc' falls back to ascending,
   * silently and with a 200 — never a 422. Uppercase 'ASC' is in the list on
   * purpose: it is the value a client is most likely to send by mistake, and
   * the acceptance criteria name it.
   */
  public function testAnyOtherSortValueFallsBackToAscendingWithA200() {
    $ascending = ['Cerrajería', 'electricidad', 'Plomería'];

    foreach (['ASC', 'DESC', 'Asc', ' asc', 'asc ', '', 'nombre', 'name', 'id', 'weight', '1', '0', 'null'] as $value) {
      $this->authenticateAs();
      $this->seedCategories([
        $this->term(3, 'Plomería', 'plumbing'),
        $this->term(9, 'electricidad', 'electricity'),
        $this->term(5, 'Cerrajería', 'locksmith'),
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
   * applies.
   */
  public function testAnArraySortValueIsIgnored() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(9, 'electricidad', 'electricity'),
    ]);
    $_GET['sort'] = ['desc'];

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['electricidad', 'Plomería'], $this->names($result));
  }

  /**
   * The order does NOT depend on the weight the operator gave the terms in
   * admin/structure/taxonomy: the fixture answers them in weight order and the
   * endpoint overrides it. This is an acceptance criterion of its own.
   */
  public function testOrderDoesNotFollowTheTermWeight() {
    $this->authenticateAs();
    $this->seedCategories([
      // Weight order as the operator arranged it: Plomería pinned to the top.
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Cerrajería', 'locksmith'),
      $this->term(9, 'Albañilería', 'masonry'),
    ]);

    $result = $this->request();

    $this->assertSame(['Albañilería', 'Cerrajería', 'Plomería'], $this->names($result));
  }

  /**
   * Ordering by name is not ordering by id: with ids and names deliberately in
   * opposite orders, the answer follows the names.
   */
  public function testOrderFollowsTheNameAndNotTheId() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(1, 'Plomería', 'plumbing'),
      $this->term(2, 'Jardinería', 'gardening'),
      $this->term(3, 'Cerrajería', 'locksmith'),
    ]);

    $result = $this->request();

    $this->assertSame([3, 2, 1], array_column($this->categories($result), 'id'));
  }

  /**
   * strcasecmp() compares bytes, so a name starting with an accented vowel
   * sorts AFTER every ASCII name instead of where a Spanish reader expects it:
   * "Áreas verdes" lands last. Pinned as the known defect SPEC 79 accepts on
   * purpose, for consistency with banks and payment-methods — not as a
   * recommendation.
   */
  public function testAccentedInitialsSortAfterEveryAsciiName() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Áreas verdes', 'green-areas'),
      $this->term(5, 'Zapatería', 'shoes'),
      $this->term(9, 'Cerrajería', 'locksmith'),
    ]);

    $result = $this->request();

    $this->assertSame(['Cerrajería', 'Zapatería', 'Áreas verdes'], $this->names($result));
  }

  /**
   * Ordering moves whole items: every category keeps its own id, code,
   * description and icon after the sort. The failure this catches would ship
   * one category's icon under another category's name.
   */
  public function testEachItemKeepsItsOwnValuesAfterSorting() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing', 'Tuberías', $this->icon(42, 'plumbing.png')),
      $this->term(9, 'electricidad', 'electricity', 'Cortes', $this->icon(43, 'electricity.png')),
      $this->term(5, 'Cerrajería', 'locksmith', 'Cerraduras'),
    ]);
    $_GET['sort'] = 'desc';

    $result = $this->request();

    $this->assertSame([
      [
        'id'          => 3,
        'code'        => 'plumbing',
        'name'        => 'Plomería',
        'description' => 'Tuberías',
        'icon_id'     => 42,
        'icon_url'    => 'https://crespcord.example.com/sites/default/files/category-icons/plumbing.png',
      ],
      [
        'id'          => 9,
        'code'        => 'electricity',
        'name'        => 'electricidad',
        'description' => 'Cortes',
        'icon_id'     => 43,
        'icon_url'    => 'https://crespcord.example.com/sites/default/files/category-icons/electricity.png',
      ],
      [
        'id'          => 5,
        'code'        => 'locksmith',
        'name'        => 'Cerrajería',
        'description' => 'Cerraduras',
        'icon_id'     => NULL,
        'icon_url'    => NULL,
      ],
    ], $this->categories($result));
  }

  /**
   * Two categories sharing a name both survive the sort with their own id.
   *
   * The relative order of the tie is deliberately NOT asserted: usort() is
   * stable since PHP 8.0 but not on the 7.4 that runs production.
   */
  public function testTermsSharingANameAreBothKept() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(9, 'Plomería', 'plumbing-industrial'),
      $this->term(5, 'Cerrajería', 'locksmith'),
    ]);

    $result = $this->request();

    $ids = array_column($this->categories($result), 'id');
    $this->assertSame('Cerrajería', $this->names($result)[0]);
    $this->assertEqualsCanonicalizing([5, 3, 9], $ids);
  }

  /**
   * A larger catalogue stays fully ordered — no off-by-one at the ends of the
   * comparator — and the sort reindexes the list in both directions, so
   * json_encode() keeps printing an array.
   */
  public function testALargerCatalogueComesBackFullyOrderedAndStillAsAList() {
    $names = ['Plomería', 'Cerrajería', 'Jardinería', 'Albañilería', 'electricidad', 'Pintura', 'Mudanzas', 'Limpieza'];
    $terms = [];
    foreach ($names as $i => $name) {
      $terms[] = $this->term(100 - $i, $name, 'code-' . $i);
    }

    foreach (['asc', 'desc'] as $sort) {
      $this->authenticateAs();
      $this->seedCategories($terms);
      $_GET['sort'] = $sort;

      $result = $this->request();

      $expected = $names;
      usort($expected, 'strcasecmp');
      if ($sort === 'desc') {
        $expected = array_reverse($expected);
      }

      $this->assertSame($expected, $this->names($result), $sort);
      $this->assertSame(range(0, 7), array_keys($this->categories($result)), $sort);
      $this->assertStringContainsString('"service_categories":[{', $result['output'], $sort);
    }
  }

}
