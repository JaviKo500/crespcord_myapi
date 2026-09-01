<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../includes/myapi.provider_query.inc';
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
    unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['sort'], $_GET['with_counts'], $_GET['page'], $_GET['limit'], $_GET['search']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['sort'], $_GET['with_counts'], $_GET['page'], $_GET['limit'], $_GET['search']);
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

  /* -------------------------------------------------------------------------
   * ?with_counts=1 and providers_count (SPEC 79, revision of 2026-08-12).
   *
   * The fixture rows of `node` are the rows the two INNER JOINs would produce:
   * one row per (provider, category) pair, carrying the provider's own columns
   * flat. Grouping them is the same arithmetic the database does over the same
   * set.
   * ---------------------------------------------------------------------- */

  /**
   * Re-seeds the database with the token row AND the provider rows.
   *
   * Needed because authenticateAs() seeds `my_api_tokens` alone and each seed
   * replaces the whole fixture; call this AFTER authenticating.
   */
  private function seedProviders(array $rows, $uid = 3) {
    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow(['uid' => (string) $uid])],
      'node'          => $rows,
    ]);
  }

  /**
   * One joined row: a provider node referencing one category.
   *
   * Active by default — published and with a licence expiring tomorrow — so
   * every case that is not about the active rule reads without noise.
   */
  private function provider($nid, $tid, array $overrides = []) {
    return $overrides + [
      'nid'                        => (string) $nid,
      'type'                       => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'                     => '1',
      'field_categories_tid'       => (string) $tid,
      'field_license_expiry_value' => (string) (REQUEST_TIME + 86400),
    ];
  }

  /**
   * The `providers_count` of every category, keyed by name.
   */
  private function countsByName(array $result) {
    $counts = [];
    foreach ($this->categories($result) as $category) {
      $counts[$category['name']] = isset($category['providers_count'])
        ? $category['providers_count']
        : NULL;
    }

    return $counts;
  }

  /**
   * The tables of every query the request ran, in order.
   */
  private function queriedTables() {
    return array_column(myapi_test_db_queries(), 'table');
  }

  /**
   * Without the parameter nothing changes: six keys, and the token lookup is
   * still the only query. The count is opt-in precisely so the grid does not
   * pay for it.
   */
  public function testWithoutTheParameterThereIsNoCountQueryAndNoSeventhKey() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);
    $this->seedProviders([$this->provider(10, 3), $this->provider(11, 3)]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['my_api_tokens'], $this->queriedTables());
    foreach ($this->categories($result) as $category) {
      $this->assertArrayNotHasKey('providers_count', $category);
    }
    $this->assertStringNotContainsString('providers_count', $result['output']);
  }

  /**
   * With ?with_counts=1 every item grows a seventh key with the number of
   * active providers of that category.
   */
  public function testWithCountsEveryItemCarriesItsProviderCount() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(11, 3),
      $this->provider(12, 5),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['Electricidad' => 1, 'Plomería' => 2], $this->countsByName($result));
    $this->assertSame(
      ['id', 'code', 'name', 'description', 'icon_id', 'icon_url', 'providers_count'],
      array_keys($this->categories($result)[0])
    );
  }

  /**
   * The count is an int in the printed JSON, not a string: COUNT() answers a
   * string on most drivers.
   */
  public function testTheCountIsPrintedAsAnInteger() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(1, $this->categories($result)[0]['providers_count']);
    $this->assertStringContainsString('"providers_count":1', $result['output']);
    $this->assertStringNotContainsString('"providers_count":"1"', $result['output']);
  }

  /**
   * A category with no provider answers 0 and stays in the list — the GROUP BY
   * does not return it, and the resource fills it in.
   */
  public function testCategoryWithoutProvidersAnswersZeroAndStaysInTheList() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertCount(2, $this->categories($result));
    $this->assertSame(['Electricidad' => 0, 'Plomería' => 1], $this->countsByName($result));
    $this->assertStringContainsString('"providers_count":0', $result['output']);
  }

  /**
   * With no provider at all — a site where the marketplace has not been
   * populated yet, or where the `provider` content type does not even exist —
   * every category answers 0 and the request is still a 200.
   */
  public function testWithNoProvidersAtAllEveryCountIsZero() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['Electricidad' => 0, 'Plomería' => 0], $this->countsByName($result));
  }

  /* -------------------------------------------------------------------------
   * What counts as an ACTIVE provider — the rule restated in SQL.
   * ---------------------------------------------------------------------- */

  /**
   * An unpublished provider does not count: it is not in the marketplace, so
   * promising it would send the resident to nobody.
   */
  public function testUnpublishedProviderIsNotCounted() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(11, 3, ['status' => '0']),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(1, $this->categories($result)[0]['providers_count']);
  }

  /**
   * A provider whose licence expired does not count either — the second half
   * of myapi_services_provider_is_active(), and the half a query written
   * without the licence join would silently lose.
   */
  public function testProviderWithAnExpiredLicenceIsNotCounted() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(11, 3, ['field_license_expiry_value' => (string) (REQUEST_TIME - 1)]),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(1, $this->categories($result)[0]['providers_count']);
  }

  /**
   * The boundary of that comparison: a licence expiring exactly now still
   * counts, because the condition is >= and not >. Same boundary the PHP
   * helper draws (`$license_expiry >= $now`).
   */
  public function testLicenceExpiringExactlyNowStillCounts() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([
      $this->provider(10, 3, ['field_license_expiry_value' => (string) REQUEST_TIME]),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(1, $this->categories($result)[0]['providers_count']);
  }

  /**
   * Only nodes of the `provider` bundle are counted: a service_request that
   * happens to reference a category does not inflate the number.
   */
  public function testOnlyProviderNodesAreCounted() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(11, 3, ['type' => MYAPI_SERVICES_REQUEST_TYPE]),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(1, $this->categories($result)[0]['providers_count']);
  }

  /**
   * A provider working in two categories counts once in each: the join
   * produces one row per pair and both groups see it.
   */
  public function testAProviderInTwoCategoriesCountsInBoth() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
    ]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(10, 5),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(['Electricidad' => 1, 'Plomería' => 1], $this->countsByName($result));
  }

  /**
   * The same provider carrying the same category in two deltas counts ONCE:
   * this is what COUNT(DISTINCT n.nid) is for, and a COUNT(*) would answer 2.
   */
  public function testTheSameCategoryTwiceOnOneProviderCountsOnce() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(10, 3),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(1, $this->categories($result)[0]['providers_count']);
  }

  /**
   * Providers of a category that is NOT in the answered catalogue — a term of
   * another vocabulary, a deleted category still referenced — do not leak into
   * anybody's count.
   */
  public function testProvidersOfOtherCategoriesDoNotLeak() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(11, 777),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertCount(1, $this->categories($result));
    $this->assertSame(1, $this->categories($result)[0]['providers_count']);
  }

  /* -------------------------------------------------------------------------
   * The shape of the count query.
   * ---------------------------------------------------------------------- */

  /**
   * ONE grouped query for the whole catalogue, not one per category. The
   * assertion reads the recorded query: base table, both inner joins, the
   * four conditions, the COUNT(DISTINCT) expression and the GROUP BY.
   */
  public function testTheCountIsASingleGroupedQuery() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Electricidad', 'electricity'),
      $this->term(7, 'Jardinería', 'gardening'),
    ]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = '1';

    $this->request();

    $this->assertSame(['my_api_tokens', 'node'], $this->queriedTables());

    $queries = myapi_test_db_queries();
    $count = $queries[1];
    $this->assertSame(['field_data_field_categories', 'field_data_field_license_expiry'], array_column($count['joins'], 'table'));
    $this->assertSame(['INNER', 'INNER'], array_column($count['joins'], 'type'));
    $this->assertSame(['COUNT(DISTINCT n.nid)'], array_values($count['expressions']));
    $this->assertSame(['c.field_categories_tid'], $count['group_by']);

    $conditions = [];
    foreach ($count['conditions'] as $condition) {
      $conditions[$condition['field']] = [$condition['value'], $condition['operator']];
    }
    $this->assertSame([MYAPI_SERVICES_PROVIDER_TYPE, '='], $conditions['n.type']);
    $this->assertSame([1, '='], $conditions['n.status']);
    $this->assertSame([REQUEST_TIME, '>='], $conditions['l.field_license_expiry_value']);
    // Every ANSWERED category, and only those: the query counts, it does not
    // discover. Since SPEC 118 the tids reach it in the answered (alphabetical)
    // order rather than the tree order, because the count is now paid for after
    // the slice — so the SET is asserted and not its order, which is not a
    // contract of an IN list.
    $this->assertSame('IN', $conditions['c.field_categories_tid'][1]);
    $tids = $conditions['c.field_categories_tid'][0];
    sort($tids);
    $this->assertSame([3, 5, 7], $tids);
  }

  /**
   * THE ACTIVE HALF OF THE COUNT COMES FROM THE SHARED HELPER (SPEC 83).
   *
   * Since SPEC 83 this query no longer writes the licence join and the two
   * conditions itself: it calls myapi_provider_apply_active_conditions(), the
   * same function GET /api/v1/providers calls, so the card of a category and
   * the listing of that category can never disagree about who is active.
   *
   * What is asserted is that the two are the SAME SQL, and not merely that both
   * work: the join and the two conditions the count carries are compared,
   * one by one, against what the helper adds to a bare query on its own. A
   * future edit that changed one without the other — a LEFT join, a > instead
   * of a >= — breaks here.
   */
  public function testTheActiveHalfOfTheCountIsTheSharedHelper() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = '1';

    $this->request();

    $queries = myapi_test_db_queries();
    $count = $queries[1];

    // A bare query put through the helper alone. The seed also clears the
    // recorded queries, so what comes back is this one and nothing else.
    myapi_test_db_seed();
    $probe = db_select('node', 'n');
    myapi_provider_apply_active_conditions($probe, 'n');
    $probe->execute();
    $recorded = myapi_test_db_queries();
    $helper = end($recorded);

    // The licence join: same table, same type, same alias, same condition.
    $this->assertSame($helper['joins'], array_slice($count['joins'], 1));
    $this->assertSame('field_data_field_license_expiry', $helper['joins'][0]['table']);
    $this->assertSame('INNER', $helper['joins'][0]['type']);

    // And the two conditions, taken out of the count by the field they name.
    $from_count = [];
    foreach ($count['conditions'] as $condition) {
      $from_count[$condition['field']] = $condition;
    }
    foreach ($helper['conditions'] as $condition) {
      $this->assertArrayHasKey($condition['field'], $from_count);
      $this->assertSame($condition, $from_count[$condition['field']], $condition['field']);
    }
    $this->assertSame(
      ['n.status', 'l.field_license_expiry_value'],
      array_column($helper['conditions'], 'field')
    );
  }

  /**
   * Ten categories still cost one count query: the number of queries does not
   * grow with the catalogue.
   */
  public function testTheNumberOfQueriesDoesNotGrowWithTheCatalogue() {
    $terms = [];
    foreach (range(1, 10) as $i) {
      $terms[] = $this->term($i, 'Categoría ' . $i, 'code-' . $i);
    }

    $this->authenticateAs();
    $this->seedCategories($terms);
    $this->seedProviders([$this->provider(10, 1)]);
    $_GET['with_counts'] = '1';

    $this->request();

    $this->assertSame(['my_api_tokens', 'node'], $this->queriedTables());
  }

  /* -------------------------------------------------------------------------
   * The lax rule of the parameter, and how it combines.
   * ---------------------------------------------------------------------- */

  /**
   * Only the exact string '1' turns the count on. Everything else — including
   * the values a client is most likely to try — answers the six-key response
   * with a 200 and no count query. Never a 422, same laxness as ?sort.
   */
  public function testAnyOtherWithCountsValueIsIgnored() {
    $values = ['0', 'true', 'TRUE', 'yes', '', ' 1', '1 ', '01', 'si', 'null'];

    foreach ($values as $value) {
      $this->authenticateAs();
      $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
      $this->seedProviders([$this->provider(10, 3)]);
      $_GET['with_counts'] = $value;

      $result = $this->request();

      $this->assertSame(200, $result['status'], var_export($value, TRUE));
      $this->assertArrayNotHasKey('providers_count', $this->categories($result)[0], var_export($value, TRUE));
      $this->assertSame(['my_api_tokens'], $this->queriedTables(), var_export($value, TRUE));
    }
  }

  /**
   * `?with_counts[]=1` — an array where a string is expected — does not fatal
   * and does not turn the count on: the comparison is `=== '1'`.
   */
  public function testAnArrayWithCountsValueIsIgnored() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = ['1'];

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertArrayNotHasKey('providers_count', $this->categories($result)[0]);
  }

  /**
   * The two parameters combine: counts AND descending order, each doing its
   * own job.
   */
  public function testWithCountsCombinesWithSortDesc() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Cerrajería', 'locksmith'),
    ]);
    $this->seedProviders([$this->provider(10, 3), $this->provider(11, 3), $this->provider(12, 5)]);
    $_GET['with_counts'] = '1';
    $_GET['sort'] = 'desc';

    $result = $this->request();

    $this->assertSame(['Plomería', 'Cerrajería'], $this->names($result));
    $this->assertSame([2, 1], array_column($this->categories($result), 'providers_count'));
  }

  /**
   * The order does not depend on the counts: asking for them does not reorder
   * anything, and the categories with more providers do not float to the top.
   */
  public function testTheOrderIsTheSameWithAndWithoutCounts() {
    $terms = [
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Cerrajería', 'locksmith'),
      $this->term(7, 'Jardinería', 'gardening'),
    ];
    $providers = [$this->provider(10, 7), $this->provider(11, 7), $this->provider(12, 5)];

    $this->authenticateAs();
    $this->seedCategories($terms);
    $this->seedProviders($providers);
    $without = $this->names($this->request());

    $this->authenticateAs();
    $this->seedCategories($terms);
    $this->seedProviders($providers);
    $_GET['with_counts'] = '1';
    $with = $this->names($this->request());

    $this->assertSame(['Cerrajería', 'Jardinería', 'Plomería'], $without);
    $this->assertSame($without, $with);
  }

  /**
   * Each count travels with its own category after the sort: the failure this
   * catches would show one category's number under another's name.
   */
  public function testEachCountStaysWithItsOwnCategoryAfterSorting() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(3, 'Plomería', 'plumbing'),
      $this->term(5, 'Cerrajería', 'locksmith'),
      $this->term(7, 'Jardinería', 'gardening'),
    ]);
    $this->seedProviders([
      $this->provider(10, 3),
      $this->provider(11, 3),
      $this->provider(12, 3),
      $this->provider(13, 7),
    ]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(['Cerrajería' => 0, 'Jardinería' => 1, 'Plomería' => 3], $this->countsByName($result));
  }

  /* -------------------------------------------------------------------------
   * The degraded cases, with the parameter on.
   * ---------------------------------------------------------------------- */

  /**
   * An empty vocabulary answers the empty list WITHOUT running the count
   * query: there is no category to count for, and an unbounded aggregate over
   * the whole node table is exactly what the early return avoids.
   */
  public function testEmptyVocabularyWithCountsRunsNoCountQuery() {
    $this->authenticateAs();
    $this->seedCategories([]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame('{"success":true,"data":{"service_categories":[]}}', $result['output']);
    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /**
   * Same for a vocabulary that does not exist at all.
   */
  public function testMissingVocabularyWithCountsRunsNoCountQuery() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed(['bancos' => [['tid' => '99', 'name' => 'Banco Pichincha', 'description' => '']]]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame('{"success":true,"data":{"service_categories":[]}}', $result['output']);
    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /**
   * A rejected method with ?with_counts=1 is still a 405 that costs nothing:
   * the count query lives behind both guards.
   */
  public function testRejectedMethodWithCountsRunsNoQueryAtAll() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $this->seedProviders([$this->provider(10, 3)]);
    $_GET['with_counts'] = '1';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $result = $this->request();

    $this->assertSame(405, $result['status']);
    $this->assertSame([], $this->queriedTables());
  }

  /**
   * And an unauthenticated request with ?with_counts=1 never reaches the
   * count either.
   */
  public function testUnauthenticatedRequestWithCountsRunsNoCountQuery() {
    $this->seedCategories([$this->term(3, 'Plomería', 'plumbing')]);
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertNotContains('node', $this->queriedTables());
  }

  /* -------------------------------------------------------------------------
   * ?page and ?limit — opt-in pagination (SPEC 118).
   *
   * The whole point of the parameter pair is what happens when it is ABSENT,
   * so half of this block asserts a response that did not change.
   * ---------------------------------------------------------------------- */

  /**
   * A catalogue of $count terms named so that the alphabetical order is also
   * the numeric one: "Categoria 01" … "Categoria 26", tid = position.
   */
  private function seedNumberedCategories($count) {
    $terms = [];
    for ($i = 1; $i <= $count; $i++) {
      $terms[] = $this->term($i, sprintf('Categoria %02d', $i), sprintf('cat_%02d', $i));
    }
    // Seeded in reverse so nothing can pass by keeping the tree order.
    $this->seedCategories(array_reverse($terms));

    return $terms;
  }

  /**
   * The `pagination` block of the answer, or NULL when there is none.
   */
  private function pagination(array $result) {
    return isset($result['json']['data']['pagination'])
      ? $result['json']['data']['pagination']
      : NULL;
  }

  /**
   * NEITHER PARAMETER, NOTHING CHANGES. This is the compatibility promise: an
   * app built against SPEC 79 keeps receiving the whole catalogue and no
   * `pagination` key at all — not an empty one, not a null one.
   */
  public function testWithoutPageAndLimitTheWholeCatalogueTravelsWithNoPaginationKey() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertCount(26, $this->categories($result));
    $this->assertSame(['service_categories'], array_keys($result['json']['data']));
    $this->assertStringNotContainsString('pagination', $result['output']);
  }

  /**
   * ?page + ?limit slice the list and add the block, with `total` describing
   * the WHOLE catalogue and not the page.
   */
  public function testPageAndLimitSliceTheListAndAddTheBlock() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);
    $_GET['page'] = '2';
    $_GET['limit'] = '10';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(
      ['Categoria 11', 'Categoria 12', 'Categoria 13', 'Categoria 14', 'Categoria 15',
       'Categoria 16', 'Categoria 17', 'Categoria 18', 'Categoria 19', 'Categoria 20'],
      $this->names($result)
    );
    $this->assertSame(
      ['total' => 26, 'page' => 2, 'limit' => 10, 'total_pages' => 3],
      $this->pagination($result)
    );
  }

  /**
   * The two keys of the body, in this order — the app parses a list and then
   * a block, never the other way round.
   */
  public function testTheBodyCarriesTheListFirstAndThePaginationSecond() {
    $this->authenticateAs();
    $this->seedNumberedCategories(3);
    $_GET['limit'] = '2';

    $result = $this->request();

    $this->assertSame(['service_categories', 'pagination'], array_keys($result['json']['data']));
    $this->assertSame(
      ['total', 'page', 'limit', 'total_pages'],
      array_keys($this->pagination($result))
    );
  }

  /**
   * EITHER parameter alone turns pagination on; the other one takes its
   * default (page 1, 20 per page).
   */
  public function testEitherParameterAloneTurnsPaginationOn() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);

    $_GET['limit'] = '5';
    $result = $this->request();
    $this->assertCount(5, $this->categories($result));
    $this->assertSame(['total' => 26, 'page' => 1, 'limit' => 5, 'total_pages' => 6], $this->pagination($result));

    unset($_GET['limit']);
    $_GET['page'] = '2';
    $result = $this->request();
    $this->assertCount(6, $this->categories($result));
    $this->assertSame('Categoria 21', $this->names($result)[0]);
    $this->assertSame(['total' => 26, 'page' => 2, 'limit' => 20, 'total_pages' => 2], $this->pagination($result));
  }

  /**
   * AN INVALID VALUE PAGES, IT DOES NOT DISABLE PAGINATION. ?page=abc is page
   * 1 of 20, never the whole catalogue: what turns the block on is that the
   * client asked, not that it asked correctly. Same lax rule as the
   * marketplace listing — nothing here is a 422.
   */
  public function testInvalidValuesFallBackToTheDefaultsAndStillPaginate() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);

    foreach (['abc', '0', '-1', '', '1.5', ['1']] as $value) {
      $_GET['page'] = $value;
      $_GET['limit'] = $value;

      $result = $this->request();

      $label = is_array($value) ? 'array' : var_export($value, TRUE);
      $this->assertSame(200, $result['status'], $label);
      $this->assertCount(20, $this->categories($result), $label);
      $this->assertSame('Categoria 01', $this->names($result)[0], $label);
      $this->assertSame(
        ['total' => 26, 'page' => 1, 'limit' => 20, 'total_pages' => 2],
        $this->pagination($result),
        $label
      );
    }
  }

  /**
   * The limit is cut to 50, and the cut value is the one echoed back — the
   * block describes what was applied, not what was asked for.
   */
  public function testLimitAboveFiftyIsCutToFifty() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);
    $_GET['limit'] = '500';

    $result = $this->request();

    $this->assertCount(26, $this->categories($result));
    $this->assertSame(['total' => 26, 'page' => 1, 'limit' => 50, 'total_pages' => 1], $this->pagination($result));
  }

  /**
   * A page beyond the last one is an empty list with a 200 — never a 404 — and
   * `page` is echoed AS ASKED FOR, not rewritten to the last page: a silent
   * rewrite would hide the client's bug.
   */
  public function testAPageBeyondTheLastOneIsAnEmptyListWithA200() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);
    $_GET['page'] = '9';
    $_GET['limit'] = '10';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame([], $this->categories($result));
    $this->assertSame(['total' => 26, 'page' => 9, 'limit' => 10, 'total_pages' => 3], $this->pagination($result));
    $this->assertStringContainsString('"service_categories":[]', $result['output']);
  }

  /**
   * THE SLICE IS TAKEN AFTER THE SORT, both ways round: page 2 descending is
   * the second page of the reversed catalogue, not the reverse of page 2.
   */
  public function testTheSliceFollowsTheOrderingAndNotTheTreeOrder() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);
    $_GET['page'] = '2';
    $_GET['limit'] = '3';

    $_GET['sort'] = 'asc';
    $this->assertSame(['Categoria 04', 'Categoria 05', 'Categoria 06'], $this->names($this->request()));

    $_GET['sort'] = 'desc';
    $this->assertSame(['Categoria 23', 'Categoria 22', 'Categoria 21'], $this->names($this->request()));
  }

  /**
   * The union of every page is the whole catalogue, in order, with no
   * duplicate and no hole — the property that actually matters to a client
   * looping until `total_pages`.
   */
  public function testEveryPageTogetherRebuildsTheOrderedCatalogue() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);
    $_GET['limit'] = '7';

    $seen = [];
    for ($page = 1; $page <= 4; $page++) {
      $_GET['page'] = (string) $page;
      $result = $this->request();
      $this->assertSame(4, $this->pagination($result)['total_pages']);
      $seen = array_merge($seen, $this->names($result));
    }

    $expected = [];
    for ($i = 1; $i <= 26; $i++) {
      $expected[] = sprintf('Categoria %02d', $i);
    }
    $this->assertSame($expected, $seen);
  }

  /**
   * An empty vocabulary paginating answers the block with everything at zero —
   * `total_pages` 0 and not 1 — and still runs no extra query.
   */
  public function testEmptyVocabularyPaginatingAnswersATotalOfZero() {
    $this->authenticateAs();
    $this->seedCategories([]);
    $_GET['page'] = '3';
    $_GET['limit'] = '10';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->categories($result));
    $this->assertSame(['total' => 0, 'page' => 3, 'limit' => 10, 'total_pages' => 0], $this->pagination($result));
  }

  /**
   * AND SO DOES A MISSING VOCABULARY: the degraded exits keep the shape a
   * client that opted in was promised, so it never parses an optional key.
   * Both degraded cases still answer identical bytes to each other.
   */
  public function testMissingVocabularyPaginatingAnswersTheSameShape() {
    $this->authenticateAs();
    myapi_test_taxonomy_seed(['bancos' => [['tid' => '99', 'name' => 'Banco Pichincha', 'description' => '']]]);
    $_GET['limit'] = '10';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->categories($result));
    $this->assertSame(['total' => 0, 'page' => 1, 'limit' => 10, 'total_pages' => 0], $this->pagination($result));
  }

  /**
   * WITH COUNTS, THE PAGE IS WHAT GETS COUNTED: still exactly one grouped
   * query, but its IN list is the page and not the whole vocabulary. This is
   * the only thing pagination actually makes cheaper on the server.
   */
  public function testWithCountsTheCountQueryCoversThePageAlone() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);
    $this->seedProviders([$this->provider(100, 2), $this->provider(101, 2), $this->provider(102, 5)]);
    $_GET['page'] = '1';
    $_GET['limit'] = '3';
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(['my_api_tokens', 'node'], $this->queriedTables());

    $queries = myapi_test_db_queries();
    $conditions = [];
    foreach ($queries[1]['conditions'] as $condition) {
      $conditions[$condition['field']] = [$condition['value'], $condition['operator']];
    }
    $tids = $conditions['c.field_categories_tid'][0];
    sort($tids);
    $this->assertSame([1, 2, 3], $tids);
    $this->assertSame(
      ['Categoria 01' => 0, 'Categoria 02' => 2, 'Categoria 03' => 0],
      $this->countsByName($result)
    );
  }

  /**
   * A count belongs to its own category after being sliced, and it is still
   * the SEVENTH and last key of the item.
   */
  public function testThePaginatedItemKeepsItsKeyOrderAndItsOwnCount() {
    $this->authenticateAs();
    $this->seedNumberedCategories(26);
    $this->seedProviders([$this->provider(100, 12)]);
    $_GET['page'] = '4';
    $_GET['limit'] = '4';
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(
      ['Categoria 13', 'Categoria 14', 'Categoria 15', 'Categoria 16'],
      $this->names($result)
    );
    $this->assertSame(
      ['id', 'code', 'name', 'description', 'icon_id', 'icon_url', 'providers_count'],
      array_keys($this->categories($result)[0])
    );
    $this->assertSame(0, $this->categories($result)[0]['providers_count']);

    $_GET['page'] = '3';
    $result = $this->request();
    $this->assertSame(1, $this->countsByName($result)['Categoria 12']);
  }

  /**
   * A page beyond the last one with ?with_counts=1 runs NO count query: there
   * is no tid to count for, and an empty IN list would count the whole site.
   */
  public function testAPageBeyondTheLastOneRunsNoCountQuery() {
    $this->authenticateAs();
    $this->seedNumberedCategories(5);
    $this->seedProviders([$this->provider(100, 1)]);
    $_GET['page'] = '7';
    $_GET['limit'] = '5';
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->categories($result));
    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /**
   * Pagination does not touch the guards: a page asked for by an
   * unauthenticated client is still a 401, and by the wrong method still a 405.
   */
  public function testPaginationDoesNotWeakenTheGuards() {
    $this->seedNumberedCategories(26);
    $_GET['page'] = '2';
    $_GET['limit'] = '10';

    $result = $this->request();
    $this->assertSame(401, $result['status']);
    $this->assertArrayNotHasKey('data', $result['json']);

    $this->authenticateAs();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $result = $this->request();
    $this->assertSame(405, $result['status']);
    $this->assertSame([], $this->queriedTables());
  }

  /* -------------------------------------------------------------------------
   * ?search — filter by name or description (SPEC 119).
   *
   * The rule that matters most here is the one that is NOT about matching: a
   * search answers every match whole, and any ?page/?limit alongside it is
   * ignored, block included.
   * ---------------------------------------------------------------------- */

  /**
   * The catalogue every search case reads, with the accents and the markup a
   * real term carries.
   */
  private function seedSearchableCategories() {
    $this->seedCategories([
      $this->term(30, 'Plomería', 'plomeria', 'Fugas, grifería, desagües'),
      $this->term(31, 'Electricidad', 'electricidad', 'Instalaciones y reparaciones'),
      $this->term(32, 'Jardinería', 'jardineria', '<p class="intro">Poda, césped, mantenimiento</p>'),
      $this->term(33, 'Cerrajería', 'cerrajeria', 'Cerraduras y llaves'),
      $this->term(34, 'Climatización', 'climatizacion', 'A/C, ventilación'),
    ]);
  }

  /**
   * A NEEDLE WITHOUT ACCENTS FINDS AN ACCENTED NAME, which is the whole reason
   * the folding exists: every code is unaccented and so is the keyboard of a
   * resident in a hurry.
   */
  public function testSearchIsAccentAndCaseInsensitiveOverTheName() {
    $this->authenticateAs();
    $this->seedSearchableCategories();

    foreach (['plomeria', 'PLOMERIA', 'Plomería', 'plomería', 'oMeRi'] as $needle) {
      $_GET['search'] = $needle;

      $result = $this->request();

      $this->assertSame(200, $result['status'], $needle);
      $this->assertSame(['Plomería'], $this->names($result), $needle);
    }
  }

  /**
   * The description is searched too, and it is searched AS ANSWERED: the
   * flattened text matches, the markup around it does not.
   */
  public function testSearchAlsoReadsTheDescriptionButNotItsMarkup() {
    $this->authenticateAs();
    $this->seedSearchableCategories();

    $_GET['search'] = 'cesped';
    $this->assertSame(['Jardinería'], $this->names($this->request()));

    $_GET['search'] = 'grifería';
    $this->assertSame(['Plomería'], $this->names($this->request()));

    // 'intro' and 'class' live only in the stored markup.
    foreach (['intro', 'class'] as $needle) {
      $_GET['search'] = $needle;
      $this->assertSame([], $this->names($this->request()), $needle);
    }
  }

  /**
   * A needle in the middle of a word matches, and every match comes back —
   * ordered, like any other answer.
   */
  public function testSearchMatchesASubstringAndAnswersEveryMatchOrdered() {
    $this->authenticateAs();
    $this->seedSearchableCategories();
    $_GET['search'] = 'eria';

    $this->assertSame(['Cerrajería', 'Jardinería', 'Plomería'], $this->names($this->request()));

    $_GET['sort'] = 'desc';
    $this->assertSame(['Plomería', 'Jardinería', 'Cerrajería'], $this->names($this->request()));
  }

  /**
   * `code` is NOT searched: it is the app's icon key, not a label. Here
   * 'climatizacion' matches through the NAME (folded), while a needle that
   * only exists as a code matches nothing.
   */
  public function testTheCodeIsNotSearched() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(30, 'Plomería', 'plomeria', 'Fugas'),
      $this->term(40, 'Portones automáticos', 'portones_automaticos', 'Motores y controles'),
    ]);

    $_GET['search'] = 'portones_automaticos';
    $this->assertSame([], $this->names($this->request()));

    $_GET['search'] = 'portones automaticos';
    $this->assertSame(['Portones automáticos'], $this->names($this->request()));
  }

  /**
   * No match is an empty list with a 200 — never a 404 and never the whole
   * catalogue.
   */
  public function testASearchThatMatchesNothingIsAnEmptyListWithA200() {
    $this->authenticateAs();
    $this->seedSearchableCategories();
    $_GET['search'] = 'buceo';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame([], $this->categories($result));
    $this->assertStringContainsString('"service_categories":[]', $result['output']);
  }

  /**
   * A CLEARED SEARCH BOX IS NOT A SEARCH: an empty or blank value answers the
   * whole catalogue, and so does an array. Nothing here is a 422.
   */
  public function testAnEmptyBlankOrArraySearchIsIgnored() {
    $this->authenticateAs();
    $this->seedSearchableCategories();

    foreach (['', '   ', "\t", ['plomeria']] as $value) {
      $_GET['search'] = $value;

      $result = $this->request();

      $label = is_array($value) ? 'array' : var_export($value, TRUE);
      $this->assertSame(200, $result['status'], $label);
      $this->assertCount(5, $this->categories($result), $label);
      $this->assertArrayNotHasKey('pagination', $result['json']['data'], $label);
    }
  }

  /**
   * The needle is trimmed before it is used, so a trailing space pasted from
   * the keyboard still matches.
   */
  public function testTheNeedleIsTrimmed() {
    $this->authenticateAs();
    $this->seedSearchableCategories();
    $_GET['search'] = '  plomeria  ';

    $this->assertSame(['Plomería'], $this->names($this->request()));
  }

  /**
   * SEARCHING OVERRULES PAGINATING: every match travels, with no slice and NO
   * `pagination` key, so the app can never read a complete result as the first
   * page of one.
   */
  public function testASearchIgnoresPageAndLimitEntirely() {
    $this->authenticateAs();
    $this->seedSearchableCategories();
    $_GET['search'] = 'eria';
    $_GET['page'] = '2';
    $_GET['limit'] = '1';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['Cerrajería', 'Jardinería', 'Plomería'], $this->names($result));
    $this->assertSame(['service_categories'], array_keys($result['json']['data']));
    $this->assertStringNotContainsString('pagination', $result['output']);
  }

  /**
   * And a blank search does NOT take pagination down with it: the parameter
   * was never a search, so the page is served.
   */
  public function testABlankSearchStillPaginates() {
    $this->authenticateAs();
    $this->seedSearchableCategories();
    $_GET['search'] = '   ';
    $_GET['limit'] = '2';

    $result = $this->request();

    $this->assertCount(2, $this->categories($result));
    $this->assertSame(['total' => 5, 'page' => 1, 'limit' => 2, 'total_pages' => 3], $this->pagination($result));
  }

  /**
   * ?search + ?with_counts=1: still ONE grouped query, and its IN list is the
   * matches — the search makes the count cheaper for the same reason the slice
   * does.
   */
  public function testSearchWithCountsCountsTheMatchesAlone() {
    $this->authenticateAs();
    $this->seedSearchableCategories();
    $this->seedProviders([$this->provider(100, 30), $this->provider(101, 30), $this->provider(102, 31)]);
    $_GET['search'] = 'plomeria';
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame(['my_api_tokens', 'node'], $this->queriedTables());

    $conditions = [];
    foreach (myapi_test_db_queries()[1]['conditions'] as $condition) {
      $conditions[$condition['field']] = [$condition['value'], $condition['operator']];
    }
    $this->assertSame([[30], 'IN'], $conditions['c.field_categories_tid']);
    $this->assertSame(['Plomería' => 2], $this->countsByName($result));
  }

  /**
   * A search that matches nothing runs no count query: there is no tid to
   * count for.
   */
  public function testASearchWithNoMatchRunsNoCountQuery() {
    $this->authenticateAs();
    $this->seedSearchableCategories();
    $this->seedProviders([$this->provider(100, 30)]);
    $_GET['search'] = 'buceo';
    $_GET['with_counts'] = '1';

    $result = $this->request();

    $this->assertSame([], $this->categories($result));
    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /**
   * The items of a search are the items of the catalogue: same six keys, same
   * values, same order.
   */
  public function testASearchedItemIsTheSameItemAsAlways() {
    $this->authenticateAs();
    $this->seedCategories([
      $this->term(30, 'Plomería', 'plomeria', 'Fugas', $this->icon(42, 'plumbing.png')),
    ]);
    $_GET['search'] = 'plomeria';

    $category = $this->categories($this->request())[0];

    $this->assertSame(['id', 'code', 'name', 'description', 'icon_id', 'icon_url'], array_keys($category));
    $this->assertSame(30, $category['id']);
    $this->assertSame('plomeria', $category['code']);
    $this->assertSame('Fugas', $category['description']);
    $this->assertSame(42, $category['icon_id']);
  }

  /**
   * The search reads the name the operator WROTE, not its escaping: a category
   * with an ampersand is findable by the ampersand, which it would not be
   * against the `&amp;` that travels in the response.
   */
  public function testTheSearchReadsTheRawNameAndNotItsEscaping() {
    $this->authenticateAs();
    $this->seedCategories([$this->term(30, 'Cortes & instalaciones', 'cortes')]);

    $_GET['search'] = '& inst';
    $result = $this->request();

    $this->assertSame(['Cortes &amp; instalaciones'], $this->names($result));
  }

  /**
   * A search over a missing or empty vocabulary is the same empty list as
   * always, with no extra query and no pagination block.
   */
  public function testASearchOverAnEmptyVocabularyIsStillAnEmptyList() {
    $this->authenticateAs();
    $this->seedCategories([]);
    $_GET['search'] = 'plomeria';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->categories($result));
    $this->assertSame(['service_categories'], array_keys($result['json']['data']));
  }

  /**
   * And the guards still come first: an unauthenticated search is a 401 that
   * touches no taxonomy at all.
   */
  public function testAnUnauthenticatedSearchIsStillA401() {
    $this->seedSearchableCategories();
    $_GET['search'] = 'plomeria';

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertArrayNotHasKey('data', $result['json']);
  }

}
