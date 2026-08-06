<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/unit.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/units (SPECS 08/09/10, covered by
 * SPEC 74).
 *
 * The other two classes take the endpoint apart; this one runs it whole.
 * myapi_unit_dispatch() is called the way hook_menu() calls it, over fixture
 * tables (see the SPEC 74 block in bootstrap.php) and a fixture Authorization
 * header, and what gets asserted is the JSON body the module prints and the
 * status code it sets — the same bytes the Flutter app receives.
 *
 * That makes this the layer where the acceptance criteria of the three specs
 * are actually checkable one by one: a user who owns in one condominium and
 * occupies in another, a unit whose parent condominium is unpublished, an
 * owner with no profile fields, a balance of NULL, and the five ways an access
 * token can fail.
 *
 * What it still does NOT prove is that the SQL those specs describe returns
 * these rows against a real schema — the fixtures answer, the database does
 * not. That half stays with tests/integration, and the queries this class
 * pins (their number, their order and their tables) are the seam where the two
 * layers meet.
 */
class UnitEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
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
  private function authenticateAs($uid = 3, array $token_overrides = [], array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'name' => 'user' . $uid, 'status' => 1];

    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow($token_overrides + ['uid' => (string) $uid])]] + $tables);
  }

  /**
   * The full scenario of SPEC 08's acceptance criteria: uid 3 owns unit 45 in
   * condominium 12 (where uid 7 is the occupant) and occupies unit 80 in
   * condominium 30 (which has no owner assigned and no balance).
   */
  private function seedTwoCondominiumScenario(array $extra = []) {
    $tables = [
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '0', 'deleted' => '0'],
        ['entity_id' => '80', 'field_ocupantes_target_id' => '3', 'delta' => '0', 'deleted' => '0'],
      ],
      'node' => [
        [
          'nid' => '45', 'type' => 'vivienda', 'status' => '1',
          'name' => 'Depto. 4B', 'category' => 'departamento', 'area_m2' => '92.00',
          'condominio_nid' => '12', 'owner_uid' => '3', 'saldo_actual' => '-3393.0000',
        ],
        [
          'nid' => '80', 'type' => 'vivienda', 'status' => '1',
          'name' => 'Bodega 2', 'category' => 'bodega', 'area_m2' => '15.50',
          'condominio_nid' => '30', 'owner_uid' => NULL, 'saldo_actual' => NULL,
        ],
        ['nid' => '12', 'type' => 'condominio', 'status' => '1', 'title' => 'Edificio El Sáuco', 'payment_information' => 'Banco Pichincha 2100'],
        ['nid' => '30', 'type' => 'condominio', 'status' => '1', 'title' => 'Conjunto La Pradera', 'payment_information' => NULL],
      ],
      'users' => [
        ['uid' => '3', 'name' => 'pcordero', 'first_name' => 'Priscila', 'last_name' => 'Cordero'],
        ['uid' => '7', 'name' => 'jperez', 'first_name' => NULL, 'last_name' => NULL],
      ],
    ];

    foreach ($extra as $table => $rows) {
      $tables[$table] = $rows;
    }

    $this->authenticateAs(3, [], $tables);
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function request() {
    return myapi_test_capture('myapi_unit_dispatch');
  }

  /* -------------------------------------------------------------------------
   * Method routing (SPEC 08).
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, before any authentication: a POST with
   * a perfectly valid token is still 405.
   */
  public function testEveryMethodOtherThanGetIs405() {
    $this->authenticateAs();

    foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->request();

      $this->assertSame(405, $result['status'], $method);
      $this->assertFalse($result['json']['success'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame('Método no permitido.', $result['json']['error'], $method);
    }
  }

  /**
   * The 405 costs nothing: the dispatcher answers it without touching the
   * database.
   */
  public function testRejectedMethodTouchesNoTable() {
    $this->authenticateAs();
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->request();

    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * GET reaches the list handler — proven by the authentication error it
   * answers, which only myapi_unit_list() can produce.
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

    $result = $this->request();

    $this->assertSame(401, $result['status'], 'not 405: the request was routed as a GET');
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * The access token guard (SPEC 05, exercised through this endpoint).
   * ---------------------------------------------------------------------- */

  /**
   * No Authorization header: 401 missing_authorization, and — the part worth
   * asserting — not one query. SPEC 08's decision was that without a valid
   * token the units tables are never reached.
   */
  public function testMissingAuthorizationHeaderIs401AndTouchesNoTable() {
    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame('No se proporcionó token de acceso.', $result['json']['error']);
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * A header that is not "Bearer <token>" is treated as no header at all,
   * including an empty Bearer.
   */
  public function testMalformedAuthorizationHeaderIs401() {
    foreach (['Token abc', 'Bearer', 'Bearer ', 'abc', 'Bearer a b'] as $header) {
      $_SERVER['HTTP_AUTHORIZATION'] = $header;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $header);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $header);
    }
  }

  /**
   * A token with no row in my_api_tokens: 401 invalid_token, a different code
   * from the one above — the app tells "log in" from "refresh" by it.
   */
  public function testUnknownTokenIs401InvalidToken() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-other-token';
    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame('Token inválido.', $result['json']['error']);
  }

  /**
   * A revoked token (logout) is rejected even though the hash matches.
   */
  public function testRevokedTokenIs401() {
    $this->authenticateAs(3, ['revoked' => '1']);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * An expired token is rejected: access_expires_at one second in the past.
   */
  public function testExpiredTokenIs401() {
    $this->authenticateAs(3, ['access_expires_at' => REQUEST_TIME - 1]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * The boundary of that comparison: a token expiring exactly at REQUEST_TIME
   * is still valid, because the guard rejects on `<` and not on `<=`. A
   * one-second-wide behaviour that no other layer asserts.
   */
  public function testTokenExpiringExactlyNowIsStillValid() {
    $this->authenticateAs(3, ['access_expires_at' => REQUEST_TIME]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
  }

  /**
   * The token's user no longer exists: 401, not a 500 on a NULL account.
   */
  public function testTokenOfADeletedUserIs401() {
    $this->authenticateAs();
    $GLOBALS['myapi_test_users'] = [];

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * A blocked user (status = 0) is rejected even with a live token (SPEC 08
   * acceptance criterion).
   */
  public function testTokenOfABlockedUserIs401() {
    $this->authenticateAs();
    $GLOBALS['myapi_test_users'][3]['status'] = 0;

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * The empty answer (SPEC 08, step 3).
   * ---------------------------------------------------------------------- */

  /**
   * A user with no unit gets 200 with an empty LIST — not 404, not an empty
   * object. The app renders an empty "change unit" screen off this.
   */
  public function testUserWithoutUnitsGetsAnEmptyPropertiesList() {
    $this->authenticateAs();

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['success' => TRUE, 'data' => ['properties' => []]], $result['json']);
    $this->assertStringContainsString('"properties":[]', $result['output']);
  }

  /**
   * And it stops there: after the token and the three relation queries,
   * nothing else runs (SPEC 08, step 3 — "sin más queries").
   */
  public function testEmptyAnswerRunsNoFurtherQueries() {
    $this->authenticateAs();

    $this->request();

    $this->assertSame([
      'my_api_tokens',
      'field_data_field_propietario',
      'field_data_field_ocupante',
      'field_data_field_ocupantes',
    ], array_column(myapi_test_db_queries(), 'table'));
  }

  /* -------------------------------------------------------------------------
   * The full answer (SPECS 08/09/10).
   * ---------------------------------------------------------------------- */

  /**
   * Owner in one condominium, occupant in another: both properties come back,
   * each with only its own units, and every field of the three specs is in
   * place. The body is compared whole, so this single case is the contract the
   * app codes against.
   *
   * assertEquals and not assertSame on purpose, for one reason only, which the
   * case below pins on its own: json_encode() prints a float with no fraction
   * as an integer literal, so area_m2 92.0 comes back from json_decode() as
   * int(92). Every other value here is compared for type too, because a loose
   * comparison of the whole body would also accept "45" for 45.
   */
  public function testOwnerInOneCondominiumAndOccupantInAnother() {
    $this->seedTwoCondominiumScenario();

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertEquals([
      'success' => TRUE,
      'data'    => [
        'properties' => [
          [
            'id'                  => 12,
            'name'                => 'Edificio El Sáuco',
            'payment_information' => 'Banco Pichincha 2100',
            'units'               => [
              [
                'id'              => 45,
                'name'            => 'Depto. 4B',
                'category'        => 'departamento',
                'area_m2'         => 92.0,
                'owner_uid'       => 3,
                'owner_name'      => 'Priscila Cordero',
                'occupant_uid'    => 7,
                'occupant_name'   => 'jperez',
                'current_balance' => -3393.0,
              ],
            ],
          ],
          [
            'id'                  => 30,
            'name'                => 'Conjunto La Pradera',
            'payment_information' => NULL,
            'units'               => [
              [
                'id'              => 80,
                'name'            => 'Bodega 2',
                'category'        => 'bodega',
                'area_m2'         => 15.5,
                'owner_uid'       => NULL,
                'owner_name'      => NULL,
                'occupant_uid'    => 3,
                'occupant_name'   => 'Priscila Cordero',
                'current_balance' => NULL,
              ],
            ],
          ],
        ],
      ],
    ], $result['json']);

    $unit = $result['json']['data']['properties'][0]['units'][0];
    $this->assertIsInt($unit['id']);
    $this->assertIsInt($unit['owner_uid']);
    $this->assertIsInt($unit['occupant_uid']);
    $this->assertIsInt($result['json']['data']['properties'][0]['id']);
  }

  /**
   * Pinned because it bites the client, not the server: PHP prints a float
   * with no fractional part as an INTEGER literal, so area_m2 92.00 travels as
   * `"area_m2":92` and a balance of -3393.0000 as `"current_balance":-3393`,
   * while 15.50 travels as `15.5`. The same field is therefore an int in one
   * unit and a double in the next, and a Dart client reading it with
   * `as double` crashes on the first whole number.
   *
   * The float cast in myapi_unit_build_properties() is still the right one —
   * this is json_encode()'s representation, not a lost value — so what this
   * case does is state the fact where it can be found, rather than change it.
   */
  public function testWholeNumbersTravelWithoutADecimalPoint() {
    $this->seedTwoCondominiumScenario();

    $result = $this->request();

    $this->assertStringContainsString('"area_m2":92,', $result['output']);
    $this->assertStringContainsString('"area_m2":15.5,', $result['output']);
    $this->assertStringContainsString('"current_balance":-3393}', $result['output']);
  }

  /**
   * The occupant relation alone is enough to see a unit — via the legacy
   * single-value field, which is the half of SPEC 08's OR that is easiest to
   * lose in a refactor.
   */
  public function testUnitSeenThroughTheLegacyOccupantField() {
    $this->authenticateAs(3, [], [
      'field_data_field_ocupante' => [
        ['entity_id' => '45', 'field_ocupante_target_id' => '3', 'deleted' => '0'],
      ],
      'node' => [
        ['nid' => '45', 'type' => 'vivienda', 'status' => '1', 'name' => 'Depto. 4B', 'condominio_nid' => '12'],
        ['nid' => '12', 'type' => 'condominio', 'status' => '1', 'title' => 'Edificio El Sáuco'],
      ],
      'users' => [['uid' => '3', 'name' => 'pcordero', 'first_name' => 'Priscila', 'last_name' => 'Cordero']],
    ]);

    $result = $this->request();
    $unit = $result['json']['data']['properties'][0]['units'][0];

    $this->assertSame(45, $unit['id']);
    $this->assertSame(3, $unit['occupant_uid']);
    $this->assertSame('Priscila Cordero', $unit['occupant_name']);
  }

  /**
   * The current occupant of a unit with several assigned is the one with the
   * highest delta, all the way through the endpoint (SPEC 09).
   */
  public function testCurrentOccupantIsTheHighestDeltaThroughTheEndpoint() {
    $this->seedTwoCondominiumScenario([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '0', 'deleted' => '0'],
        ['entity_id' => '45', 'field_ocupantes_target_id' => '9', 'delta' => '1', 'deleted' => '0'],
        ['entity_id' => '80', 'field_ocupantes_target_id' => '3', 'delta' => '0', 'deleted' => '0'],
      ],
      'users' => [
        ['uid' => '3', 'name' => 'pcordero', 'first_name' => 'Priscila', 'last_name' => 'Cordero'],
        ['uid' => '9', 'name' => 'aruiz', 'first_name' => 'Ana', 'last_name' => 'Ruiz'],
      ],
    ]);

    $result = $this->request();
    $unit = $result['json']['data']['properties'][0]['units'][0];

    $this->assertSame(9, $unit['occupant_uid']);
    $this->assertSame('Ana Ruiz', $unit['occupant_name']);
  }

  /**
   * A unit whose parent condominium is unpublished disappears from the
   * response, with no error and no empty property left behind (SPEC 08
   * acceptance criterion, and the risk the spec accepted knowingly).
   */
  public function testUnitOfAnUnpublishedCondominiumDisappears() {
    $this->seedTwoCondominiumScenario();
    $nodes = $GLOBALS['myapi_test_db']['node'];
    $nodes[3]['status'] = '0';
    $GLOBALS['myapi_test_db']['node'] = $nodes;

    $result = $this->request();
    $properties = $result['json']['data']['properties'];

    $this->assertCount(1, $properties);
    $this->assertSame(12, $properties[0]['id']);
  }

  /**
   * An unpublished unit disappears too, even though its owner is the caller.
   */
  public function testUnpublishedUnitDisappears() {
    $this->seedTwoCondominiumScenario();
    $nodes = $GLOBALS['myapi_test_db']['node'];
    $nodes[0]['status'] = '0';
    $GLOBALS['myapi_test_db']['node'] = $nodes;

    $result = $this->request();
    $properties = $result['json']['data']['properties'];

    $this->assertCount(1, $properties);
    $this->assertSame(30, $properties[0]['id']);
  }

  /**
   * Every unit hidden gives the same empty list as having none — the user sees
   * no properties rather than an error.
   */
  public function testEveryUnitHiddenGivesAnEmptyList() {
    $this->seedTwoCondominiumScenario();
    $nodes = $GLOBALS['myapi_test_db']['node'];
    $nodes[2]['status'] = '0';
    $nodes[3]['status'] = '0';
    $GLOBALS['myapi_test_db']['node'] = $nodes;

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['properties']);
  }

  /**
   * An owner whose profile fields are empty is shown by username (SPEC 08
   * acceptance criterion), end to end.
   */
  public function testOwnerNameFallsBackToTheUsername() {
    $this->seedTwoCondominiumScenario([
      'users' => [
        ['uid' => '3', 'name' => 'pcordero', 'first_name' => '', 'last_name' => ''],
        ['uid' => '7', 'name' => 'jperez', 'first_name' => 'Juan', 'last_name' => 'Pérez'],
      ],
    ]);

    $result = $this->request();
    $unit = $result['json']['data']['properties'][0]['units'][0];

    $this->assertSame('pcordero', $unit['owner_name']);
    $this->assertSame('Juan Pérez', $unit['occupant_name']);
  }

  /**
   * A unit with no occupant in either field answers NULL for both occupant
   * fields (SPEC 09 acceptance criterion).
   */
  public function testUnitWithoutOccupantAnswersNulls() {
    $this->authenticateAs(3, [], [
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0'],
      ],
      'node' => [
        ['nid' => '45', 'type' => 'vivienda', 'status' => '1', 'name' => 'Depto. 4B', 'condominio_nid' => '12', 'owner_uid' => '3'],
        ['nid' => '12', 'type' => 'condominio', 'status' => '1', 'title' => 'Edificio El Sáuco'],
      ],
      'users' => [['uid' => '3', 'name' => 'pcordero', 'first_name' => 'Priscila', 'last_name' => 'Cordero']],
    ]);

    $result = $this->request();
    $unit = $result['json']['data']['properties'][0]['units'][0];

    $this->assertNull($unit['occupant_uid']);
    $this->assertNull($unit['occupant_name']);
    $this->assertSame(3, $unit['owner_uid']);
  }

  /**
   * The balance travels as a JSON number with its sign, and a unit with no row
   * in field_saldo_actual answers null (SPEC 10 acceptance criteria) — checked
   * on the raw body, because that is where a number turned into a string would
   * show.
   */
  public function testBalanceIsANumberInTheRawBody() {
    $this->seedTwoCondominiumScenario();

    $result = $this->request();

    $this->assertStringContainsString('"current_balance":-3393', $result['output']);
    $this->assertStringContainsString('"current_balance":null', $result['output']);
    $this->assertStringNotContainsString('"current_balance":"', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * The queries the answer costs.
   * ---------------------------------------------------------------------- */

  /**
   * The full path is eight queries, in this order, whatever the number of
   * units: SPEC 08 accepted 4-5 chained queries instead of one big join, and
   * SPEC 09 added the occupant one while explicitly refusing a second query
   * over users. A regression to a per-unit lookup would show up here as a
   * ninth table.
   */
  public function testTheFullAnswerCostsAFixedNumberOfQueries() {
    $this->seedTwoCondominiumScenario();

    $this->request();

    $this->assertSame([
      'my_api_tokens',
      'field_data_field_propietario',
      'field_data_field_ocupante',
      'field_data_field_ocupantes',
      'node',
      'node',
      'field_data_field_ocupantes',
      'users',
    ], array_column(myapi_test_db_queries(), 'table'));
  }

  /**
   * Owners and occupants are asked for in ONE users query, over the union of
   * both sets of uids and with no repeats (SPEC 09's stated decision).
   */
  public function testOwnersAndOccupantsAreResolvedInASingleUsersQuery() {
    $this->seedTwoCondominiumScenario();

    $this->request();

    $users_queries = myapi_test_db_queries('users');
    $this->assertCount(1, $users_queries);

    $uids = NULL;
    foreach ($users_queries[0]['conditions'] as $condition) {
      if ($condition['field'] === 'u.uid') {
        $uids = $condition['value'];
      }
    }
    sort($uids);
    $this->assertSame(['3', '7'], $uids);
  }

  /**
   * The condominium query asks only for the condominiums the units point at,
   * deduplicated — not once per unit.
   */
  public function testCondominiumsAreAskedForOncePerDistinctCondominium() {
    $this->seedTwoCondominiumScenario([
      'node' => [
        ['nid' => '45', 'type' => 'vivienda', 'status' => '1', 'name' => 'Depto. 4B', 'condominio_nid' => '12', 'owner_uid' => '3'],
        ['nid' => '46', 'type' => 'vivienda', 'status' => '1', 'name' => 'Depto. 5B', 'condominio_nid' => '12', 'owner_uid' => '3'],
        ['nid' => '12', 'type' => 'condominio', 'status' => '1', 'title' => 'Edificio El Sáuco'],
      ],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0'],
        ['entity_id' => '46', 'field_propietario_target_id' => '3', 'deleted' => '0'],
      ],
      'field_data_field_ocupantes' => [],
    ]);

    $result = $this->request();

    $condominium_query = myapi_test_db_queries('node')[1];
    $uids = NULL;
    foreach ($condominium_query['conditions'] as $condition) {
      if ($condition['field'] === 'n.nid') {
        $uids = $condition['value'];
      }
    }
    $this->assertSame(['12'], $uids);
    $this->assertCount(2, $result['json']['data']['properties'][0]['units']);
  }

  /* -------------------------------------------------------------------------
   * The envelope.
   * ---------------------------------------------------------------------- */

  /**
   * A success answer carries no 'message': CLAUDE.md makes it optional and
   * this endpoint passes no message key, so adding one would be a contract
   * change.
   */
  public function testSuccessEnvelopeHasNoMessage() {
    $this->seedTwoCondominiumScenario();

    $result = $this->request();

    $this->assertSame(['success', 'data'], array_keys($result['json']));
    $this->assertSame('application/json', $result['headers']['Content-Type']);
  }

  /**
   * Every answer of this endpoint ends the request, success or error — the
   * dispatcher never falls through to Drupal's page rendering.
   */
  public function testEveryAnswerEndsTheRequest() {
    $this->seedTwoCondominiumScenario();
    $this->assertTrue($this->request()['exited'], 'success');

    unset($_SERVER['HTTP_AUTHORIZATION']);
    $this->assertTrue($this->request()['exited'], '401');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $this->assertTrue($this->request()['exited'], '405');
  }

}
