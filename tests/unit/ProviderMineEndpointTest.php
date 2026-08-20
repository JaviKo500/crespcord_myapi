<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.provider_files.inc';
require_once __DIR__ . '/../../includes/myapi.provider_query.inc';
require_once __DIR__ . '/../../resources/provider.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/providers/mine (SPEC 97).
 *
 * Same harness as ProviderListEndpointTest: myapi_provider_mine_dispatch() is
 * called the way hook_menu() calls it, over a fixture `node` table, a fixture
 * my_api_tokens row, a fixture account carrying its roles and a fixture
 * Authorization header. What is asserted is the JSON body the module prints
 * and the status it sets.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: joins are
 * recorded and never resolved, so a provider is seeded flat — its own node
 * columns plus the value each LEFT JOIN would have brought, under the alias
 * the query gives it. The licence is seeded TWICE, under `license_expiry` (the
 * alias of this endpoint) and under `field_license_expiry_value` (the column
 * the public listing's INNER join conditions on), so the very same fixture row
 * can be read by both endpoints. That is what makes
 * testTheEightSharedKeysAreIdenticalToThePublicListing() meaningful rather
 * than a comparison of two different providers.
 *
 * The three things this suite therefore does NOT prove, all of them the
 * database's half:
 *
 *  - that Drupal's router really prefers the literal 'api/v1/providers/mine'
 *    over 'api/v1/providers/%'. hook_menu() is not run here; that is a
 *    manual acceptance criterion against a booted site.
 *  - that the LEFT join on field_data_field_license_expiry really answers NULL
 *    for a provider with no licence row. The fixture states that NULL
 *    directly; what is asserted is the SHAPE of the join — LEFT, on that
 *    table, under an alias that is not the reserved 'l'.
 *  - that MySQL orders by n.nid DESC as the stub does.
 */
class ProviderMineEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  /**
   * The uid of the account that owns that token.
   */
  const UID = 3;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $this->clearQueryString();
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $this->clearQueryString();
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_static_reset();
    myapi_test_db_seed();
  }

  private function clearQueryString() {
    unset($_GET['page'], $_GET['limit'], $_GET['order_by'], $_GET['sort'], $_GET['category_id']);
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
      'uid'               => (string) self::UID,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * One provider row, as every LEFT JOIN of this endpoint delivers it.
   *
   * Published and licensed until tomorrow by default — that is, ACTIVE — so
   * every case that is not about the two flags reads without noise. The
   * optional values are NULL, the honest default: nothing writes
   * field_rating_avg or field_rating_count yet (SPEC 77) and field_logo is
   * empty for every provider already on the site (SPEC 85).
   */
  private function provider($nid, $title, array $overrides = []) {
    $row = $overrides + [
      'nid'               => (string) $nid,
      'type'              => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'            => '1',
      'title'             => $title,
      'license_expiry'    => (string) (REQUEST_TIME + 86400),
      'rating_avg'        => NULL,
      'rating_count'      => NULL,
      'short_description' => NULL,
      'hourly_rate'       => NULL,
      'logo_uri'          => NULL,
    ];

    // The same licence under the name the PUBLIC listing reads it by, so one
    // fixture row serves both endpoints. NULL means "no row in the field
    // table", and the public listing's INNER join drops it either way.
    $row['field_license_expiry_value'] = $row['license_expiry'];

    return $row;
  }

  /**
   * One row of field_data_field_provider_users: the link account -> provider.
   */
  private function link($nid, $uid = self::UID) {
    return [
      'entity_id'                     => (string) $nid,
      'entity_type'                   => 'node',
      'deleted'                       => '0',
      'delta'                         => '0',
      'field_provider_users_target_id' => (string) $uid,
    ];
  }

  /**
   * One row of field_data_field_categories, joined to its term.
   *
   * Same shape as ProviderListEndpointTest: 'fc.entity_id' is written
   * QUALIFIED because the categories query projects that column under the
   * alias 'nid', and a flat row cannot carry the same column twice.
   */
  private function categoryRow($nid, $tid, $name, $code = 'code', $delta = 0) {
    return [
      'fc.entity_id'         => (string) $nid,
      'entity_type'          => 'node',
      'deleted'              => '0',
      'delta'                => (string) $delta,
      'field_categories_tid' => (string) $tid,
      'tid'                  => (string) $tid,
      'name'                 => $name,
      'code'                 => $code,
    ];
  }

  /**
   * Seeds the token, the account with its roles, the providers, the links and
   * the categories, in one call — every myapi_test_db_seed() replaces the
   * whole fixture.
   *
   * @param array $roles
   *   The roles of the account. The provider role by default, because that is
   *   the precondition of every case that is not about the gate itself.
   */
  private function seed(array $providers = [], array $links = [], array $categories = [], $roles = NULL) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;

    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid'    => self::UID,
      'name'   => 'proveedor' . self::UID,
      'status' => 1,
      'roles'  => $roles,
    ];

    myapi_test_db_seed([
      'my_api_tokens'                     => [$this->tokenRow()],
      'node'                              => $providers,
      'field_data_field_provider_users'   => $links,
      'field_data_field_categories'       => $categories,
    ]);
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function request() {
    return myapi_test_capture('myapi_provider_mine_dispatch');
  }

  /**
   * Authenticates, seeds and runs, which is what almost every case needs.
   */
  private function mine(array $providers, array $links = [], array $categories = [], $roles = NULL) {
    $this->authenticate();
    $this->seed($providers, $links, $categories, $roles);

    return $this->request();
  }

  private function providers(array $result) {
    return $result['json']['data']['providers'];
  }

  private function ids(array $result) {
    return array_column($this->providers($result), 'id');
  }

  private function queriedTables() {
    return array_column(myapi_test_db_queries(), 'table');
  }

  /* -------------------------------------------------------------------------
   * The gate: the 'proveedor' role.
   * ---------------------------------------------------------------------- */

  /**
   * The role matcher this endpoint authorises with accepts the provider role
   * and NOTHING else — administrator and administrador edificio included.
   * Restated here and not only in ProviderRoleTest because SPEC 97 is the
   * first api/v1 endpoint whose 403 depends on it.
   */
  public function testOnlyTheProviderRoleMatchesTheGate() {
    $this->assertTrue(myapi_provider_role_roles_match(['authenticated user', MYAPI_PROVIDER_ROLE]));
    $this->assertTrue(myapi_provider_role_roles_match([MYAPI_PROVIDER_ROLE]));
    $this->assertFalse(myapi_provider_role_roles_match(['authenticated user']));
    $this->assertFalse(myapi_provider_role_roles_match(['authenticated user', 'administrator']));
    $this->assertFalse(myapi_provider_role_roles_match(['authenticated user', 'administrador edificio']));
    $this->assertFalse(myapi_provider_role_roles_match([]));
  }

  /* -------------------------------------------------------------------------
   * Method routing and authentication.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, and the method is checked BEFORE the
   * token: a POST with no Authorization header at all is still 405, never 401.
   */
  public function testEveryMethodOtherThanGetIs405BeforeAuthentication() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $method) {
      $this->authenticate();
      $this->seed([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);
      $_SERVER['REQUEST_METHOD'] = $method;

      $authenticated = $this->request();

      unset($_SERVER['HTTP_AUTHORIZATION']);
      $this->seed([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

      $anonymous = $this->request();

      $this->assertSame(405, $authenticated['status'], $method);
      $this->assertSame('method_not_allowed', $authenticated['json']['error_code'], $method);
      $this->assertSame(405, $anonymous['status'], $method . ' (anonymous)');
      $this->assertSame('method_not_allowed', $anonymous['json']['error_code'], $method . ' (anonymous)');
      $this->assertSame([], $this->queriedTables(), $method . ': the 405 costs no query');
    }
  }

  /**
   * No Authorization header: 401 missing_authorization, and not one query.
   */
  public function testNoTokenIs401() {
    $this->seed([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], $this->queriedTables());
  }

  /**
   * A revoked token is invalid_token, and the endpoint stops at the token
   * table: no link query, no provider query.
   */
  public function testARevokedTokenIs401() {
    $this->authenticate();
    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid'    => self::UID,
      'status' => 1,
      'roles'  => ['authenticated user', MYAPI_PROVIDER_ROLE],
    ];
    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow(['revoked' => '1'])],
      'node'          => [$this->provider(41, 'Plomería Torres')],
    ]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /**
   * An expired token is the same 401, with the same code: the app has one
   * "log in again" branch, not two.
   */
  public function testAnExpiredTokenIs401() {
    $this->authenticate();
    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid'    => self::UID,
      'status' => 1,
      'roles'  => ['authenticated user', MYAPI_PROVIDER_ROLE],
    ];
    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow(['access_expires_at' => REQUEST_TIME - 1])],
      'node'          => [$this->provider(41, 'Plomería Torres')],
    ]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * A token that is in no row at all — never issued, or issued against another
   * install — is the same 401 invalid_token as a revoked one. The account is
   * never loaded and the role is never asked: there is no account yet.
   */
  public function testAnUnknownTokenIs401() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer a-token-nobody-ever-issued';
    $this->seed([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /* -------------------------------------------------------------------------
   * Authorisation: the 403 and its one cause.
   * ---------------------------------------------------------------------- */

  /**
   * A valid token whose account does NOT carry the provider role is 403
   * provider_role_required — a key of its own, not the generic 'forbidden'.
   * The link table is never even read: the role is asked first.
   */
  public function testAnAccountWithoutTheProviderRoleIs403() {
    $result = $this->mine(
      [$this->provider(41, 'Plomería Torres')],
      [$this->link(41)],
      [],
      ['authenticated user']
    );

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
    $this->assertSame('Tu cuenta no tiene el rol de proveedor.', $result['json']['error']);
    $this->assertFalse($result['json']['success']);
    $this->assertSame(['my_api_tokens'], $this->queriedTables(), 'the 403 costs one query, the token');
  }

  /**
   * AND THERE IS NO EXCEPTION FOR ADMINISTRATORS. An administrator has no
   * providers of their own; the back office already lists them all.
   */
  public function testAnAdministratorWithoutTheProviderRoleGetsTheSame403() {
    foreach ([['authenticated user', 'administrator'], ['authenticated user', 'administrador edificio']] as $roles) {
      $result = $this->mine(
        [$this->provider(41, 'Plomería Torres')],
        [$this->link(41)],
        [],
        $roles
      );

      $this->assertSame(403, $result['status'], implode(',', $roles));
      $this->assertSame('provider_role_required', $result['json']['error_code'], implode(',', $roles));
    }
  }

  /**
   * The role but NO link at all is a 200 with an empty list, never a 403 and
   * never a 404: "the operator has not linked you yet" is missing data, not a
   * missing permission. And it costs no provider query — the empty nid list
   * short-circuits.
   */
  public function testTheRoleWithNoLinkedProviderIsAnEmpty200() {
    $result = $this->mine([$this->provider(41, 'Plomería Torres')], []);

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame(['providers'], array_keys($result['json']['data']));
    $this->assertSame([], $this->providers($result));
    $this->assertSame(
      ['my_api_tokens', 'field_data_field_provider_users'],
      $this->queriedTables(),
      'no node query and no category query when there is nothing to fetch'
    );
  }

  /* -------------------------------------------------------------------------
   * The shape of the response.
   * ---------------------------------------------------------------------- */

  /**
   * `data` carries EXACTLY ONE key, and there is no pagination envelope: an
   * account operates one or two providers, not a pageable collection.
   */
  public function testDataCarriesOnlyTheProvidersKey() {
    $result = $this->mine([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['providers'], array_keys($result['json']['data']));
    $this->assertArrayNotHasKey('pagination', $result['json']['data']);
  }

  /**
   * The ten keys, in the documented order. The eight of the marketplace item
   * first, then the two flags of this spec — never interleaved, so the shared
   * half stays byte-comparable with the public listing.
   */
  public function testTheItemHasExactlyTenKeysInTheDocumentedOrder() {
    $result = $this->mine([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $item = $this->providers($result)[0];

    $this->assertSame([
      'id',
      'logo',
      'title',
      'categories',
      'rating_avg',
      'rating_count',
      'short_description',
      'hourly_rate',
      'status',
      'is_active',
    ], array_keys($item));
    $this->assertCount(10, $item);
  }

  /**
   * Two linked providers come back both, ordered by id DESCENDING — the same
   * deterministic tie-breaker the public listing uses.
   */
  public function testTwoLinkedProvidersComeBackNewestFirst() {
    $result = $this->mine(
      [
        $this->provider(41, 'Plomería Torres'),
        $this->provider(77, 'Electricidad Sur'),
      ],
      [$this->link(41), $this->link(77)]
    );

    $this->assertSame([77, 41], $this->ids($result));
  }

  /**
   * A link pointing at a node that no longer exists does NOT break the
   * response: it matches no row and the rest is answered just the same.
   */
  public function testALinkToADeletedNodeIsSkipped() {
    $result = $this->mine(
      [$this->provider(41, 'Plomería Torres')],
      [$this->link(41), $this->link(999)]
    );

    $this->assertSame(200, $result['status']);
    $this->assertSame([41], $this->ids($result));
  }

  /**
   * The empty values of a provider with nothing filled in: null logo, no
   * categories, no rating, no rate, and an EMPTY STRING description — not a
   * null. rating_count is 0 and never null.
   */
  public function testAProviderWithNothingFilledInIsBuiltWithItsEmptyValues() {
    $result = $this->mine([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $item = $this->providers($result)[0];

    $this->assertSame(41, $item['id']);
    $this->assertNull($item['logo']);
    $this->assertSame('Plomería Torres', $item['title']);
    $this->assertSame([], $item['categories']);
    $this->assertNull($item['rating_avg']);
    $this->assertSame(0, $item['rating_count']);
    $this->assertSame('', $item['short_description']);
    $this->assertNull($item['hourly_rate']);
  }

  /**
   * And a provider with everything filled in travels with it — the proof that
   * the shared builder is really being reused and not re-implemented.
   */
  public function testAProviderWithEveryOptionalValueTravelsWithThemAll() {
    $result = $this->mine(
      [
        $this->provider(41, 'Plomería Torres', [
          'rating_avg'        => '4.50',
          'rating_count'      => '12',
          'short_description' => 'Destapes y fugas',
          'hourly_rate'       => '25.00',
          'logo_uri'          => 'public://logos/torres.png',
        ]),
      ],
      [$this->link(41)],
      [$this->categoryRow(41, 7, 'Plomería', 'plomeria')]
    );

    $item = $this->providers($result)[0];

    $this->assertSame(4.5, $item['rating_avg']);
    $this->assertSame(12, $item['rating_count']);
    $this->assertSame('Destapes y fugas', $item['short_description']);
    // 25 and not 25.0: drupal_json_encode() writes a whole float as "25" and
    // json_decode() reads it back as an int. Same round-trip the public
    // listing's own assertion documents — the PHP value is a float either way.
    $this->assertSame(25, $item['hourly_rate']);
    $this->assertStringContainsString('"hourly_rate":25', $result['output']);
    $this->assertSame($GLOBALS['base_url'] . '/sites/default/files/logos/torres.png', $item['logo']);
    $this->assertSame([['id' => 7, 'code' => 'plomeria', 'name' => 'Plomería']], $item['categories']);
  }

  /* -------------------------------------------------------------------------
   * status and is_active — the two flags of this spec.
   * ---------------------------------------------------------------------- */

  /**
   * The three combinations of the spec's table, in one pass. The fourth —
   * status FALSE with is_active TRUE — is impossible by construction, because
   * is_active includes status, and testSuspendedIsNeverActive() below pins it.
   */
  public function testTheThreeCombinationsOfStatusAndIsActive() {
    $cases = [
      // Published and licensed: operative.
      'active'    => [['status' => '1', 'license_expiry' => (string) (REQUEST_TIME + 86400)], TRUE, TRUE],
      // Published, licence expired.
      'expired'   => [['status' => '1', 'license_expiry' => (string) (REQUEST_TIME - 1)], TRUE, FALSE],
      // Suspended by hand from the back office.
      'suspended' => [['status' => '0', 'license_expiry' => (string) (REQUEST_TIME + 86400)], FALSE, FALSE],
    ];

    foreach ($cases as $name => $case) {
      list($overrides, $status, $is_active) = $case;

      $result = $this->mine([$this->provider(41, 'Plomería Torres', $overrides)], [$this->link(41)]);
      $item = $this->providers($result)[0];

      $this->assertSame(200, $result['status'], $name);
      $this->assertSame($status, $item['status'], $name . ': status');
      $this->assertSame($is_active, $item['is_active'], $name . ': is_active');
    }
  }

  /**
   * A provider with NO licence row at all — the LEFT join answers NULL — is
   * is_active FALSE, exactly like an expired one. NEVER null: a third value
   * would mean what false already means, and would cost the app a case.
   */
  public function testAProviderWithNoLicenceRowIsInactiveAndNeverNull() {
    $result = $this->mine(
      [$this->provider(41, 'Plomería Torres', ['license_expiry' => NULL])],
      [$this->link(41)]
    );

    $item = $this->providers($result)[0];

    $this->assertTrue($item['status']);
    $this->assertFalse($item['is_active']);
    $this->assertNotNull($item['is_active']);
  }

  /**
   * An empty string licence value is the same inactive, and not a cast of ''
   * to 0 compared against REQUEST_TIME.
   */
  public function testAnEmptyLicenceValueIsInactive() {
    $result = $this->mine(
      [$this->provider(41, 'Plomería Torres', ['license_expiry' => ''])],
      [$this->link(41)]
    );

    $this->assertFalse($this->providers($result)[0]['is_active']);
  }

  /**
   * THE BOUNDARY, and it is the contract with the SQL half: a licence expiring
   * exactly at REQUEST_TIME is still valid, because
   * myapi_provider_apply_active_conditions() compares with >= and not >. The
   * one assertion that catches the two rules drifting apart at the edge.
   */
  public function testALicenceExpiringExactlyNowIsStillActive() {
    $result = $this->mine(
      [$this->provider(41, 'Plomería Torres', ['license_expiry' => (string) REQUEST_TIME])],
      [$this->link(41)]
    );

    $this->assertTrue($this->providers($result)[0]['is_active']);

    $one_second_later = $this->mine(
      [$this->provider(41, 'Plomería Torres', ['license_expiry' => (string) (REQUEST_TIME - 1)])],
      [$this->link(41)]
    );

    $this->assertFalse($this->providers($one_second_later)[0]['is_active']);
  }

  /**
   * A suspended provider is never active, whatever its licence says — the
   * impossible fourth row of the spec's table, asserted rather than assumed.
   */
  public function testSuspendedIsNeverActive() {
    foreach ([REQUEST_TIME + 86400, REQUEST_TIME, REQUEST_TIME - 1, NULL] as $expiry) {
      $result = $this->mine(
        [$this->provider(41, 'X', ['status' => '0', 'license_expiry' => $expiry === NULL ? NULL : (string) $expiry])],
        [$this->link(41)]
      );

      $item = $this->providers($result)[0];

      $this->assertFalse($item['status'], var_export($expiry, TRUE));
      $this->assertFalse($item['is_active'], var_export($expiry, TRUE));
    }
  }

  /**
   * BOOLEANS, not 0/1 and not "true": the flags are read by the app as bools
   * and travel as JSON literals. Asserted over the raw body, because
   * json_decode() would hide an integer 1 behind a truthy value.
   */
  public function testTheTwoFlagsTravelAsJsonBooleans() {
    $result = $this->mine(
      [$this->provider(41, 'Plomería Torres', ['status' => '1', 'license_expiry' => (string) (REQUEST_TIME - 1)])],
      [$this->link(41)]
    );

    $this->assertStringContainsString('"status":true', $result['output']);
    $this->assertStringContainsString('"is_active":false', $result['output']);
    $this->assertTrue($this->providers($result)[0]['status']);
    $this->assertFalse($this->providers($result)[0]['is_active']);
  }

  /* -------------------------------------------------------------------------
   * The contract with the public listing.
   * ---------------------------------------------------------------------- */

  /**
   * THE TEST THAT GUARDS AGAINST SILENT DRIFT (SPEC 97, risk 2). The eight
   * shared keys of an ACTIVE provider are identical — same values, same order
   * — in GET /api/v1/providers/mine and in GET /api/v1/providers, for the very
   * same fixture row.
   *
   * If somebody renames an alias in myapi_provider_fetch() and forgets
   * myapi_provider_mine_fetch(), the own listing starts answering NULL in that
   * key with no error anywhere. This is the only assertion that notices.
   */
  public function testTheEightSharedKeysAreIdenticalToThePublicListing() {
    $row = $this->provider(41, 'Plomería Torres', [
      'rating_avg'        => '4.50',
      'rating_count'      => '12',
      'short_description' => 'Destapes y fugas',
      'hourly_rate'       => '25.00',
      'logo_uri'          => 'public://logos/torres.png',
    ]);

    $mine = $this->mine([$row], [$this->link(41)], [$this->categoryRow(41, 7, 'Plomería', 'plomeria')]);
    $public = myapi_test_capture('myapi_provider_dispatch');

    $mine_item = $this->providers($mine)[0];
    $public_item = $public['json']['data']['providers'][0];

    $shared = array_slice($mine_item, 0, 8, TRUE);

    $this->assertSame($public_item, $shared);
    $this->assertSame(array_keys($public_item), array_keys($shared));
    $this->assertTrue($mine_item['is_active'], 'precondition: the fixture provider is active');
  }

  /**
   * The equivalence that holds the duplicated rule together: is_active TRUE
   * means "this provider appears today in GET /api/v1/providers", and
   * is_active FALSE means it does not. Checked over the three states at once,
   * against the real public listing rather than against a restatement of it.
   */
  public function testIsActiveMeansExactlyPresentInThePublicListing() {
    $cases = [
      'active'    => ['status' => '1', 'license_expiry' => (string) (REQUEST_TIME + 86400)],
      'expired'   => ['status' => '1', 'license_expiry' => (string) (REQUEST_TIME - 1)],
      'suspended' => ['status' => '0', 'license_expiry' => (string) (REQUEST_TIME + 86400)],
      'unlicensed' => ['status' => '1', 'license_expiry' => NULL],
    ];

    foreach ($cases as $name => $overrides) {
      $mine = $this->mine([$this->provider(41, 'Plomería Torres', $overrides)], [$this->link(41)]);
      $public = myapi_test_capture('myapi_provider_dispatch');

      $is_active = $this->providers($mine)[0]['is_active'];
      $in_public = in_array(41, array_column($public['json']['data']['providers'], 'id'), TRUE);

      $this->assertSame($is_active, $in_public, $name . ': is_active must mean "listed publicly"');
    }
  }

  /* -------------------------------------------------------------------------
   * The query.
   * ---------------------------------------------------------------------- */

  /**
   * An empty nid list answers an empty array WITHOUT QUERYING: `IN ()` is not
   * valid SQL, and the account with the role and no link is a normal 200.
   */
  public function testFetchWithNoNidsRunsNoQuery() {
    myapi_test_db_seed(['node' => [$this->provider(41, 'Plomería Torres')]]);

    $this->assertSame([], myapi_provider_mine_fetch([]));
    $this->assertSame([], $this->queriedTables());
  }

  /**
   * The query does NOT carry the active rule: no condition on n.status, no
   * condition on the licence value, and the licence join is LEFT under an
   * alias that is not the reserved 'l'. This is the shape assertion behind
   * "an expired provider is listed rather than hidden" — and the guard against
   * somebody adding myapi_provider_apply_active_conditions() back in.
   */
  public function testTheQueryDoesNotApplyTheActiveRule() {
    $this->mine([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $queries = myapi_test_db_queries('node');
    $this->assertCount(1, $queries, 'one node query, never one per provider');
    $node = $queries[0];

    $joins = [];
    foreach ($node['joins'] as $join) {
      $joins[$join['table']] = ['type' => $join['type'], 'alias' => $join['alias']];
    }

    $this->assertSame('LEFT', $joins['field_data_field_license_expiry']['type']);
    $this->assertNotSame('l', $joins['field_data_field_license_expiry']['alias'], "the alias 'l' is reserved by myapi_provider_apply_active_conditions()");

    $fields = array_column($node['conditions'], 'field');
    $this->assertNotContains('n.status', $fields);
    $this->assertNotContains('l.field_license_expiry_value', $fields);
    $this->assertContains('n.type', $fields, 'the bundle is filtered even though the nids come from the provider field');
    $this->assertContains('n.nid', $fields);
  }

  /**
   * Every optional field is LEFT joined — the four of SPEC 83 plus the two of
   * the logo — so a provider with no rate, no rating or no logo is still
   * listed to its own operator.
   */
  public function testEveryOptionalFieldIsLeftJoined() {
    $this->mine([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $node = myapi_test_db_queries('node')[0];

    $joins = [];
    foreach ($node['joins'] as $join) {
      $joins[$join['table']] = $join['type'];
    }

    $this->assertSame([
      'field_data_field_rating_avg'       => 'LEFT',
      'field_data_field_rating_count'     => 'LEFT',
      'field_data_field_short_description' => 'LEFT',
      'field_data_field_hourly_rate'      => 'LEFT',
      'field_data_field_logo'             => 'LEFT',
      'file_managed'                      => 'LEFT',
      'field_data_field_license_expiry'   => 'LEFT',
    ], $joins);
  }

  /**
   * No range(): there is no pagination to slice, and slicing silently would be
   * the one way an own provider could go missing.
   */
  public function testTheQueryIsNotRanged() {
    $this->mine([$this->provider(41, 'Plomería Torres')], [$this->link(41)]);

    $node = myapi_test_db_queries('node')[0];

    $this->assertNull($node['range']);
    $this->assertSame([['field' => 'n.nid', 'direction' => 'DESC']], $node['order']);
  }

  /**
   * FOUR QUERIES, and the count does not grow with the number of providers:
   * the token, the link, the rows and the categories of all of them at once.
   */
  public function testTheWholeRequestCostsFourQueriesWhateverTheNumberOfProviders() {
    $providers = [];
    $links = [];
    $categories = [];
    for ($i = 0; $i < 20; $i++) {
      $providers[] = $this->provider(100 + $i, 'Proveedor ' . $i);
      $links[] = $this->link(100 + $i);
      $categories[] = $this->categoryRow(100 + $i, 7, 'Plomería');
      $categories[] = $this->categoryRow(100 + $i, 9, 'Gasfitería', 'gasfiteria', 1);
    }

    $result = $this->mine($providers, $links, $categories);

    $this->assertCount(20, $this->providers($result));
    $this->assertSame(
      ['my_api_tokens', 'field_data_field_provider_users', 'node', 'field_data_field_categories'],
      $this->queriedTables()
    );
  }

  /* -------------------------------------------------------------------------
   * The includes.
   * ---------------------------------------------------------------------- */

  /**
   * THE RESOURCE MUST PULL includes/myapi.provider_role.inc ITSELF, and this is
   * a regression test for a bug that reached a running site: myapi.module only
   * module_load_include()s that file inside the back-office hooks that need it,
   * so an api/v1 request reached myapi_provider_mine_list() with
   * myapi_provider_role_is() undefined and answered a PHP fatal instead of a
   * 403.
   *
   * ASSERTED OVER THE SOURCE, and it has to be: the module_load_include() of
   * tests/unit/bootstrap.php is a no-op, because every suite requires the
   * includes it needs by hand. That is exactly what hides a missing declaration
   * from every other test in this class — they all pass with the line deleted.
   */
  public function testTheResourceDeclaresTheProviderRoleInclude() {
    $source = file_get_contents(__DIR__ . '/../../resources/provider.resource.inc');

    $this->assertStringContainsString(
      "module_load_include('inc', 'myapi', 'includes/myapi.provider_role');",
      $source,
      'the role gate of GET /api/v1/providers/mine lives in an include the resource must load'
    );

    // And the two functions it is loaded for are really the ones this endpoint
    // calls, so renaming either one does not leave the include behind as
    // decoration.
    $this->assertTrue(function_exists('myapi_provider_role_is'));
    $this->assertTrue(function_exists('myapi_provider_role_provider_ids'));
  }

  /* -------------------------------------------------------------------------
   * Query string.
   * ---------------------------------------------------------------------- */

  /**
   * Every query string parameter is IGNORED IN SILENCE — never a 422, and
   * never a different response. The client that reuses marketplace code and
   * sends a stray ?limit=1 gets the same two providers.
   */
  public function testEveryQueryStringParameterIsIgnored() {
    $providers = [$this->provider(41, 'Plomería Torres'), $this->provider(77, 'Electricidad Sur')];
    $links = [$this->link(41), $this->link(77)];

    $clean = $this->mine($providers, $links);

    $_GET['page'] = '2';
    $_GET['limit'] = '1';
    $_GET['category_id'] = 'abc';
    $_GET['order_by'] = 'title';
    $_GET['sort'] = 'asc';

    $noisy = $this->mine($providers, $links);

    $this->assertSame(200, $noisy['status']);
    $this->assertSame($clean['json'], $noisy['json']);
    $this->assertSame([77, 41], $this->ids($noisy));
  }

}
