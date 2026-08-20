<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/service-requests/provider (SPEC 98).
 *
 * Same harness as ServiceRequestListEndpointTest and
 * ProviderMineEndpointTest: myapi_service_request_provider_dispatch() is
 * called the way hook_menu() calls it, over a fixture `node` table, a fixture
 * my_api_tokens row, a fixture account carrying its roles and a fixture
 * Authorization header. What is asserted is the JSON body the module prints
 * and the status it sets.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: joins are
 * recorded and never resolved, so a request is seeded flat — its own node
 * columns plus the value each JOIN would have brought, under the alias the
 * query gives it.
 *
 * THE AWARD IS SEEDED THREE TIMES, and that is not redundancy. The same datum
 * is read by three different consumers under three different names:
 * `field_assigned_provider_target_id` is what B's two isNull() conditions
 * compare (through the 'sfap' alias, which falls back to the bare column);
 * `assigned_provider_raw` is what rule 2b of myapi_service_request_viewer()
 * reads on the detail; and `assigned_provider_id` / `assigned_provider_name`
 * are what the chain of LEFT JOINs projects for the item. They can
 * legitimately DISAGREE — an award pointing at a deleted node keeps the raw
 * column and nulls the joined pair — and the case that unpublishes the
 * provider is exactly that.
 *
 * What this suite does NOT prove, all of it the database's half:
 *
 *  - that Drupal's router really prefers the literal
 *    'api/v1/service-requests/provider' over 'api/v1/service-requests/%'.
 *    hook_menu() is not run here; that is a manual acceptance criterion
 *    against a booted site, the same one api/v1/providers/mine carries.
 *  - that the INNER JOIN of myapi_provider_role_assigned_request_ids() really
 *    drops a node of the wrong bundle. The fixture cannot make a recorded join
 *    fail to match; what is asserted is the SHAPE of that join.
 *  - that MySQL evaluates the nested OR the way the fixture evaluator does.
 *
 * What it DOES prove is everything the module decides: the gate, the three
 * sets, the equivalence with the detail, the unit rule, the thirteen keys and
 * the two defences against the empty OR.
 */
class ServiceRequestProviderListTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  /**
   * The provider account: the reader of this endpoint.
   */
  const UID = 7;

  /**
   * The resident who asked for the work. Never the reader here.
   */
  const REQUESTER_UID = 3;

  /**
   * The two providers the account operates, and one it does not.
   */
  const PROVIDER_A = 41;
  const PROVIDER_B = 42;
  const FOREIGN_PROVIDER = 99;

  /**
   * The category the account attends, and one it does not.
   */
  const CATEGORY = 12;
  const OTHER_CATEGORY = 77;

  const NID = 128;
  const CREATED = 1755000000;
  const CONDOMINIUM = 500;

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
    unset(
      $_GET['page'], $_GET['limit'], $_GET['sort'], $_GET['status'],
      $_GET['category_id'], $_GET['unit_id'], $_GET['provider_id'],
      $_GET['date_from'], $_GET['date_to']
    );
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

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
   * One service_request row, flat, as every join of BOTH the listing and the
   * detail delivers it. 'open', of the account's category, unassigned — that
   * is, in set B — so every case that is not about the scope reads clean.
   */
  private function request($nid = self::NID, array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) $nid,
      'type'                           => MYAPI_SERVICES_REQUEST_TYPE,
      // The node's published flag; the request's own status is qualified.
      'status'                         => '1',
      'title'                          => 'Fuga en el calentador',
      'created'                        => (string) self::CREATED,
      'uid'                            => '1',
      'fr.field_requester_target_id'   => (string) self::REQUESTER_UID,
      'requester_uid'                  => (string) self::REQUESTER_UID,
      'fcat.field_category_tid'        => (string) self::CATEGORY,
      'category_id'                    => (string) self::CATEGORY,
      'category_code'                  => 'plumbing',
      'category_name'                  => 'Plomería',
      'fu.field_unit_target_id'        => '55',
      'unit_id'                        => '55',
      'unit_name'                      => 'A-301',
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'description'                    => 'El calentador gotea.',
      'desired_start'                  => (string) (self::CREATED + 86400),
      // Unassigned: the three names of "no award", one per consumer — see the
      // class docblock.
      'field_assigned_offer_target_id'    => NULL,
      'assigned_offer_raw'                => NULL,
      'assigned_offer_id'                 => NULL,
      'assigned_offer_status'             => NULL,
      'field_assigned_provider_target_id' => NULL,
      'assigned_provider_raw'             => NULL,
      'assigned_provider_id'              => NULL,
      'assigned_provider_name'            => NULL,
      'condominium_id'                    => (string) self::CONDOMINIUM,
      'condominium_name'                  => 'Residencial Los Almendros',
      'closed_at'                         => NULL,
      'attachment_fid'                    => NULL,
      'attachment_filename'               => NULL,
    ];
  }

  /**
   * The same request, awarded to $provider_nid in $status.
   *
   * Writes the award under its three names at once, which is what a coherent
   * award looks like. A case that wants them to disagree overrides one.
   */
  private function awarded($nid, $provider_nid, $status = MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED, array $overrides = []) {
    return $this->request($nid, $overrides + [
      'frs.field_request_status_value'    => $status,
      'field_assigned_provider_target_id' => (string) $provider_nid,
      'assigned_provider_raw'             => (string) $provider_nid,
      'assigned_provider_id'              => (string) $provider_nid,
      'assigned_provider_name'            => 'Proveedor ' . $provider_nid,
    ]);
  }

  /**
   * A provider node as myapi_provider_role_any_provider_active() reads it:
   * published, licensed until tomorrow — that is, ACTIVE.
   */
  private function providerNode($nid, $status = '1', $expiry = NULL) {
    return [
      'nid'            => (string) $nid,
      'type'           => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'         => $status,
      'license_expiry' => $expiry === NULL ? (string) (REQUEST_TIME + 86400) : $expiry,
    ];
  }

  /**
   * One row of field_data_field_provider_users: the account -> provider link.
   */
  private function link($provider_nid, $uid = self::UID) {
    return [
      'entity_id'   => (string) $provider_nid,
      'entity_type' => 'node',
      'deleted'     => '0',
      MYAPI_PROVIDER_USERS_FIELD . '_target_id' => (string) $uid,
    ];
  }

  /**
   * One row of field_data_field_categories: the provider attends a category.
   */
  private function category($provider_nid, $tid = self::CATEGORY) {
    return [
      'entity_id'            => (string) $provider_nid,
      'entity_type'          => 'node',
      'deleted'              => '0',
      'field_categories_tid' => (string) $tid,
    ];
  }

  /**
   * One row of field_data_field_request: an offer of $provider_nid on $nid.
   *
   * This is set A, and the same table myapi_service_request_offer_counts_by_nid()
   * counts — so a seeded offer both grants access AND raises offers_count,
   * exactly as it does in production.
   */
  private function offer($nid, $provider_nid, $offer_nid = 900) {
    return [
      'entity_id'                => (string) $offer_nid,
      'entity_type'              => 'node',
      'deleted'                  => '0',
      'field_request_target_id'  => (string) $nid,
      'fq.field_request_target_id' => (string) $nid,
      'field_provider_target_id' => (string) $provider_nid,
      'nid'                      => (string) $offer_nid,
      'no.type'                  => MYAPI_SERVICES_OFFER_TYPE,
      'no.status'                => '1',
    ];
  }

  /**
   * One row of field_data_field_assigned_provider: set C.
   *
   * The table myapi_provider_role_assigned_request_ids() reads. It is seeded
   * ALONGSIDE the award columns of the node row, because the two are two
   * readings of the same fact and production keeps them in step.
   */
  private function assignment($nid, $provider_nid) {
    return [
      'entity_id'                         => (string) $nid,
      'entity_type'                       => 'node',
      'deleted'                           => '0',
      'field_assigned_provider_target_id' => (string) $provider_nid,
      // What the INNER JOIN to node brings.
      'n.type'                            => MYAPI_SERVICES_REQUEST_TYPE,
      'n.status'                          => '1',
    ];
  }

  /**
   * The users row myapi_user_display_names() reads.
   */
  private function userRow($uid = self::REQUESTER_UID, $name = 'aperez', $first = 'Ana', $last = 'Pérez') {
    return [
      'uid'        => (string) $uid,
      'name'       => $name,
      'first_name' => $first,
      'last_name'  => $last,
    ];
  }

  /**
   * Seeds a whole scenario in one call: every myapi_test_db_seed() replaces the
   * entire fixture, so nothing can be added afterwards.
   *
   * @param array $requests
   *   service_request rows, and any provider node the case needs.
   * @param array $tables
   *   Extra fixture tables, merged over the defaults.
   * @param array|NULL $roles
   *   The reader's roles; the provider role by default, because that is the
   *   precondition of every case that is not about the gate.
   */
  private function seed(array $requests, array $tables = [], $roles = NULL) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;

    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid'    => self::UID,
      'name'   => 'proveedor' . self::UID,
      'status' => 1,
      'roles'  => $roles,
    ];

    $tables += [
      'my_api_tokens' => [$this->tokenRow()],
      'users'         => [$this->userRow()],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
        $this->link(self::PROVIDER_A),
      ],
      'field_data_field_categories' => [
        $this->category(self::PROVIDER_A),
      ],
      'field_data_field_request'            => [],
      'field_data_field_assigned_provider'  => [],
    ];

    $tables['node'] = array_merge(
      $requests,
      isset($tables['node']) ? $tables['node'] : [$this->providerNode(self::PROVIDER_A)]
    );

    myapi_test_db_seed($tables);
    myapi_test_static_reset();
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function dispatch() {
    return myapi_test_capture('myapi_service_request_provider_dispatch');
  }

  /**
   * Authenticates, seeds and runs, which is what almost every case needs.
   */
  private function board(array $requests, array $tables = [], $roles = NULL) {
    $this->authenticate();
    $this->seed($requests, $tables, $roles);

    return $this->dispatch();
  }

  private function items(array $result) {
    return $result['json']['data']['service_requests'];
  }

  private function ids(array $result) {
    return array_column($this->items($result), 'id');
  }

  private function pagination(array $result) {
    return $result['json']['data']['pagination'];
  }

  /* -------------------------------------------------------------------------
   * The gate, the method and the token.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, and the method is checked BEFORE the
   * token: a POST with no Authorization header at all is still 405, never 401.
   */
  public function testEveryMethodOtherThanGetIs405BeforeAuthentication() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
      $this->seed([$this->request()]);
      $_SERVER['REQUEST_METHOD'] = $method;
      unset($_SERVER['HTTP_AUTHORIZATION']);

      $result = $this->dispatch();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
    }
  }

  /**
   * No Authorization header is 401 missing_authorization.
   */
  public function testNoTokenIs401() {
    $this->seed([$this->request()]);

    $result = $this->dispatch();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /**
   * A revoked token is 401 invalid_token, and the gate is never reached.
   */
  public function testARevokedTokenIs401() {
    $this->authenticate();
    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid' => self::UID, 'name' => 'p', 'status' => 1,
      'roles' => ['authenticated user', MYAPI_PROVIDER_ROLE],
    ];
    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow(['revoked' => '1'])]]);
    myapi_test_static_reset();

    $result = $this->dispatch();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * An account WITHOUT the provider role is 403 provider_role_required — the
   * very key GET /api/v1/providers/mine answers, and no new one.
   */
  public function testAnAccountWithoutTheProviderRoleIs403() {
    $result = $this->board([$this->request()], [], ['authenticated user']);

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
  }

  /**
   * An administrator WITHOUT the provider role gets the same 403. There is no
   * exception for administrators on this route.
   */
  public function testAnAdministratorWithoutTheProviderRoleIs403() {
    foreach ([['authenticated user', 'administrator'], ['authenticated user', 'administrador edificio']] as $roles) {
      $result = $this->board([$this->request()], [], $roles);

      $this->assertSame(403, $result['status'], implode(',', $roles));
      $this->assertSame('provider_role_required', $result['json']['error_code']);
    }
  }

  /**
   * The 403 reuses the key SPEC 97 created: not a new one per route, because
   * the cause and the user's remedy are identical.
   */
  public function testTheForbiddenKeyIsTheOneProvidersMineAlreadyUses() {
    $result = $this->board([$this->request()], [], ['authenticated user']);

    $this->assertSame(
      myapi_t('provider_role_required'),
      $result['json']['error'],
      'the message comes from the shared catalogue and not from this resource'
    );
  }

  /**
   * The role WITH no linked provider node is 200 with an empty list — never a
   * 403 and never a 404. Having the role and being linked to nothing is
   * missing data, not a missing permission (SPEC 97).
   */
  public function testTheRoleWithNoLinkedProviderIsAnEmptyList() {
    $result = $this->board([$this->request()], [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));
    $this->assertSame(0, $this->pagination($result)['total']);
    $this->assertSame(0, $this->pagination($result)['total_pages']);
    $this->assertSame(1, $this->pagination($result)['page']);
  }

  /* -------------------------------------------------------------------------
   * The scope: A ∪ B ∪ C.
   * ---------------------------------------------------------------------- */

  /**
   * B: an 'open' request of the account's category, unassigned, is listed.
   */
  public function testAnOpenRequestOfMyCategoryIsListed() {
    $result = $this->board([$this->request()]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([self::NID], $this->ids($result));
  }

  /**
   * B: 'offered' too. Third-party offers do not withdraw a request from the
   * market, or only the fastest bidder would ever see it.
   */
  public function testAnOfferedRequestIsStillInTheMarket() {
    $result = $this->board([$this->request(self::NID, [
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
    ])]);

    $this->assertSame([self::NID], $this->ids($result));
  }

  /**
   * B: a category the provider does not attend is NOT listed.
   */
  public function testAnOpenRequestOfAnotherCategoryIsNotListed() {
    $result = $this->board([$this->request(self::NID, [
      'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
      'category_id'             => (string) self::OTHER_CATEGORY,
    ])]);

    $this->assertSame([], $this->ids($result));
  }

  /**
   * B: a FOREIGN 'direct' request of my category is not listed. A direct is
   * born assigned, which is exactly what "unassigned" rules out — and this is
   * the case that keeps B away from
   * myapi_provider_role_broadcast_statuses(), which does include 'direct'.
   */
  public function testAForeignDirectRequestIsNotListed() {
    $this->assertContains(
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      myapi_provider_role_broadcast_statuses(),
      'precondition: the back office does broadcast direct requests'
    );

    $result = $this->board(
      [$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_DIRECT)],
      ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::FOREIGN_PROVIDER)]]
    );

    $this->assertSame([], $this->ids($result));
  }

  /**
   * C: a 'direct' awarded to MY provider IS listed. This is what makes the
   * endpoint "my work" and not only "my market".
   */
  public function testADirectRequestAwardedToMyProviderIsListed() {
    $result = $this->board(
      [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_DIRECT)],
      ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]]
    );

    $this->assertSame([self::NID], $this->ids($result));
  }

  /**
   * C: 'assigned', 'closed' and 'cancelled' awarded to my provider are all
   * listed. C carries NO status filter, and that is the point of it — the
   * history is what makes '?status' useful.
   */
  public function testEveryTerminalStatusAwardedToMyProviderIsListed() {
    foreach ([
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ] as $status) {
      $result = $this->board(
        [$this->awarded(self::NID, self::PROVIDER_A, $status)],
        ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]]
      );

      $this->assertSame([self::NID], $this->ids($result), $status);
    }
  }

  /**
   * A: a request I offered on and LOST — awarded to a third party, closed — is
   * still listed. Whoever has an offer needs to see what became of it.
   */
  public function testARequestIOfferedOnAndLostIsStillListed() {
    $result = $this->board(
      [$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_CLOSED)],
      [
        'field_data_field_request'           => [$this->offer(self::NID, self::PROVIDER_A)],
        'field_data_field_assigned_provider' => [$this->assignment(self::NID, self::FOREIGN_PROVIDER)],
      ]
    );

    $this->assertSame([self::NID], $this->ids($result));
  }

  /**
   * A is independent of the CATEGORY too: a request I offered on, in a
   * category I no longer attend, is still listed.
   */
  public function testARequestIOfferedOnInACategoryINoLongerAttendIsListed() {
    $result = $this->board(
      [$this->request(self::NID, [
        'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
        'category_id'             => (string) self::OTHER_CATEGORY,
      ])],
      ['field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)]]
    );

    $this->assertSame([self::NID], $this->ids($result));
  }

  /**
   * With every provider SUSPENDED, B disappears and only A and C remain — and
   * the answer is a list, never a 403. Being suspended costs the market, not
   * the account.
   */
  public function testASuspendedProviderLosesTheMarketButKeepsItsOwnWork() {
    $market = $this->request(300);
    $mine = $this->awarded(301, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED);

    $result = $this->board([$market, $mine], [
      'node' => [$this->providerNode(self::PROVIDER_A, '0')],
      'field_data_field_assigned_provider' => [$this->assignment(301, self::PROVIDER_A)],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([301], $this->ids($result));
  }

  /**
   * An expired licence suspends the same way an unpublished node does.
   */
  public function testAnExpiredLicenceAlsoRemovesTheMarket() {
    $result = $this->board([$this->request()], [
      'node' => [$this->providerNode(self::PROVIDER_A, '1', (string) (REQUEST_TIME - 86400))],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->ids($result));
  }

  /**
   * With two providers, one active and one not, B uses the categories of BOTH
   * when there is no '?provider_id' — the same union reading
   * myapi_provider_role_any_provider_active() already makes.
   */
  public function testTheMarketUsesTheUnionOfBothProvidersCategories() {
    $result = $this->board(
      [$this->request(self::NID, [
        'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
        'category_id'             => (string) self::OTHER_CATEGORY,
      ])],
      [
        'node' => [
          $this->providerNode(self::PROVIDER_A),
          $this->providerNode(self::PROVIDER_B, '0'),
        ],
        'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
          $this->link(self::PROVIDER_A),
          $this->link(self::PROVIDER_B),
        ],
        'field_data_field_categories' => [
          $this->category(self::PROVIDER_A, self::CATEGORY),
          $this->category(self::PROVIDER_B, self::OTHER_CATEGORY),
        ],
      ]
    );

    $this->assertSame([self::NID], $this->ids($result));
  }

  /**
   * An unpublished request appears through NONE of the three sets: the scope
   * narrows what is visible, it never widens what a request IS.
   */
  public function testAnUnpublishedRequestIsNeverListed() {
    $result = $this->board(
      [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED, ['status' => '0'])],
      ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]]
    );

    $this->assertSame([], $this->ids($result));
  }

  /**
   * A request that satisfies A, B and C at once appears ONCE. The OR is a
   * filter on one row and never a union of three result sets.
   */
  public function testARequestInAllThreeSetsIsListedOnlyOnce() {
    $result = $this->board(
      [$this->request()],
      [
        'field_data_field_request'           => [$this->offer(self::NID, self::PROVIDER_A)],
        'field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)],
      ]
    );

    $this->assertSame([self::NID], $this->ids($result));
    $this->assertCount(1, $this->items($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /* -------------------------------------------------------------------------
   * The equivalence with the detail (decision 7 / risk 1).
   * ---------------------------------------------------------------------- */

  /**
   * THE TEST THAT HOLDS DECISION 7, and the only one that catches the drift
   * risk 1 describes.
   *
   * Over the whole matrix of status × award × category × offered, membership
   * in A ∪ B ∪ C and the verdict of myapi_service_request_viewer() must agree:
   * if it is in the list, it can be opened; if it can be opened as a provider,
   * it is in the list. A future spec that adds a biddable status to one form
   * and not the other fails HERE, and nowhere else.
   */
  public function testTheListingAndTheDetailAgreeOverTheWholeMatrix() {
    $statuses = [
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ];

    $checked = 0;

    foreach ($statuses as $status) {
      foreach ([NULL, self::PROVIDER_A, self::FOREIGN_PROVIDER] as $award) {
        foreach ([self::CATEGORY, self::OTHER_CATEGORY] as $tid) {
          foreach ([FALSE, TRUE] as $offered) {
            $row = $award === NULL
              ? $this->request(self::NID, ['frs.field_request_status_value' => $status])
              : $this->awarded(self::NID, $award, $status);

            $row['fcat.field_category_tid'] = (string) $tid;
            $row['category_id'] = (string) $tid;

            $tables = [
              'field_data_field_request' => $offered
                ? [$this->offer(self::NID, self::PROVIDER_A)]
                : [],
              'field_data_field_assigned_provider' => $award === NULL
                ? []
                : [$this->assignment(self::NID, $award)],
            ];

            $listed = in_array(self::NID, $this->ids($this->board([$row], $tables)), TRUE);

            // The verdict of the detail over the SAME fixture and the SAME
            // token. The row comes from myapi_service_request_detail_row() and
            // NOT from the fixture array: the access rule reads `status`, which
            // the detail projects out of field_request_status while the flat
            // row carries the NODE's published flag under that very name.
            // Comparing the two rules means running both of them for real.
            myapi_test_static_reset();
            $detail_row = myapi_service_request_detail_row(self::NID);
            $verdict = $detail_row
              ? myapi_service_request_viewer($detail_row, self::UID)
              : NULL;

            $label = sprintf(
              'status=%s award=%s category=%d offered=%s',
              $status,
              $award === NULL ? 'none' : $award,
              $tid,
              $offered ? 'yes' : 'no'
            );

            $this->assertSame(
              $listed,
              $verdict === 'provider',
              'listing and detail disagree — ' . $label
            );

            $checked++;
          }
        }
      }
    }

    $this->assertSame(72, $checked, 'the whole matrix was walked');
  }

  /**
   * Rule 2b, isolated: a 'direct' awarded to MY provider is 'provider'; the
   * same request awarded to somebody else is NULL — the 403 SPEC 89 left
   * written in its Riesgos, still refused for a stranger.
   */
  public function testRule2bGrantsTheAwardedProviderAndNobodyElse() {
    $this->seed([$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_DIRECT)]);
    $this->assertSame(
      'provider',
      myapi_service_request_viewer(myapi_service_request_detail_row(self::NID), self::UID)
    );

    $this->seed([$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_DIRECT)]);
    $this->assertNull(
      myapi_service_request_viewer(myapi_service_request_detail_row(self::NID), self::UID)
    );
  }

  /**
   * Rule 2b is STRICTLY ADDITIVE: the requester still reads their own request,
   * whatever the award. It is decided by rule 1, which runs first.
   */
  public function testTheRequesterStillReadsTheirOwnRequestUnderRule2b() {
    $this->seed([$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_CLOSED)]);

    $this->assertSame(
      'requester',
      myapi_service_request_viewer(myapi_service_request_detail_row(self::NID), self::REQUESTER_UID)
    );
  }

  /* -------------------------------------------------------------------------
   * '?provider_id'.
   * ---------------------------------------------------------------------- */

  /**
   * A malformed '?provider_id' is 422 invalid_field, and it costs NO query
   * beyond the token, the account and the link: the parser runs before the
   * scope.
   */
  public function testAMalformedProviderIdIs422() {
    foreach (['abc', '1,2', '-1', '0', ''] as $raw) {
      $this->authenticate();
      $this->seed([$this->request()]);
      $_GET['provider_id'] = $raw;

      $result = $this->dispatch();

      $this->assertSame(422, $result['status'], var_export($raw, TRUE));
      $this->assertSame('invalid_field', $result['json']['error_code'], var_export($raw, TRUE));
    }
  }

  /**
   * The 422 comes AFTER the gate: an account without the role never learns
   * whether its parameter was well formed.
   */
  public function testTheGateIsCheckedBeforeTheParameter() {
    $this->authenticate();
    $this->seed([$this->request()], [], ['authenticated user']);
    $_GET['provider_id'] = 'abc';

    $result = $this->dispatch();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
  }

  /**
   * A '?provider_id' of the account narrows the three sets to that provider.
   */
  public function testProviderIdNarrowsTheScopeToThatProvider() {
    $this->authenticate();
    $this->seed(
      [$this->request(300), $this->request(301, [
        'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
        'category_id'             => (string) self::OTHER_CATEGORY,
      ])],
      [
        'node' => [$this->providerNode(self::PROVIDER_A), $this->providerNode(self::PROVIDER_B)],
        'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
          $this->link(self::PROVIDER_A),
          $this->link(self::PROVIDER_B),
        ],
        'field_data_field_categories' => [
          $this->category(self::PROVIDER_A, self::CATEGORY),
          $this->category(self::PROVIDER_B, self::OTHER_CATEGORY),
        ],
      ]
    );
    $_GET['provider_id'] = (string) self::PROVIDER_A;

    $result = $this->dispatch();

    $this->assertSame([300], $this->ids($result));
  }

  /**
   * '?provider_id' also carries the LICENCE of the selected provider: with a
   * suspended A, B disappears even though the sibling B is active. "Put the
   * app in Provider A mode" includes being suspended (decision 8).
   */
  public function testProviderIdUsesTheLicenceOfTheSelectedProvider() {
    $this->authenticate();
    $this->seed(
      [$this->request()],
      [
        'node' => [
          $this->providerNode(self::PROVIDER_A, '0'),
          $this->providerNode(self::PROVIDER_B),
        ],
        'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
          $this->link(self::PROVIDER_A),
          $this->link(self::PROVIDER_B),
        ],
        'field_data_field_categories' => [
          $this->category(self::PROVIDER_A, self::CATEGORY),
          $this->category(self::PROVIDER_B, self::CATEGORY),
        ],
      ]
    );
    $_GET['provider_id'] = (string) self::PROVIDER_A;

    $result = $this->dispatch();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->ids($result));
  }

  /**
   * A FOREIGN '?provider_id' is 200 with an empty list — not a 403, which
   * would confirm the node exists, and not a 404 (decision 9).
   */
  public function testAForeignProviderIdIsAnEmptyList() {
    foreach ([self::FOREIGN_PROVIDER, 987654] as $nid) {
      $this->authenticate();
      $this->seed([$this->request()]);
      $_GET['provider_id'] = (string) $nid;

      $result = $this->dispatch();

      $this->assertSame(200, $result['status'], (string) $nid);
      $this->assertSame([], $this->items($result), (string) $nid);
      $this->assertSame(0, $this->pagination($result)['total'], (string) $nid);
    }
  }

  /**
   * THE PROPERTY THAT MAKES DECISION 8 CORRECT: the union of the per-provider
   * results is exactly the unfiltered list, with nothing missing and nothing
   * repeated.
   */
  public function testTheUnionOfEveryProviderIdIsTheUnfilteredList() {
    $tables = [
      'node' => [$this->providerNode(self::PROVIDER_A), $this->providerNode(self::PROVIDER_B)],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
        $this->link(self::PROVIDER_A),
        $this->link(self::PROVIDER_B),
      ],
      'field_data_field_categories' => [
        $this->category(self::PROVIDER_A, self::CATEGORY),
        $this->category(self::PROVIDER_B, self::OTHER_CATEGORY),
      ],
      'field_data_field_request' => [$this->offer(302, self::PROVIDER_A, 901)],
      'field_data_field_assigned_provider' => [$this->assignment(303, self::PROVIDER_B)],
    ];

    $requests = [
      // B, through A's category.
      $this->request(300),
      // B, through B's category.
      $this->request(301, [
        'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
        'category_id'             => (string) self::OTHER_CATEGORY,
      ]),
      // A: offered by provider A, in a category nobody attends.
      $this->request(302, [
        'fcat.field_category_tid' => '999',
        'category_id'             => '999',
      ]),
      // C: awarded to provider B, closed.
      $this->awarded(303, self::PROVIDER_B, MYAPI_SERVICES_REQUEST_STATUS_CLOSED),
    ];

    $unfiltered = $this->ids($this->board($requests, $tables));

    $union = [];
    foreach ([self::PROVIDER_A, self::PROVIDER_B] as $provider) {
      $this->authenticate();
      $this->seed($requests, $tables);
      $_GET['provider_id'] = (string) $provider;

      $ids = $this->ids($this->dispatch());
      $this->assertSame(array_values(array_unique($ids)), $ids, 'no repeats within one provider');
      $union = array_merge($union, $ids);
    }

    sort($unfiltered);
    $deduped = array_values(array_unique($union));
    sort($deduped);

    $this->assertSame([300, 301, 302, 303], $unfiltered);
    $this->assertSame($unfiltered, $deduped, 'the union of the parts is the whole');
    $this->assertCount(count($union), $deduped, 'and nothing appears under two providers');
  }

  /**
   * '?provider_id' absent is the union of every provider of the account.
   */
  public function testNoProviderIdIsTheUnionOfTheAccount() {
    $result = $this->board(
      [$this->request(300), $this->request(301, [
        'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
        'category_id'             => (string) self::OTHER_CATEGORY,
      ])],
      [
        'node' => [$this->providerNode(self::PROVIDER_A), $this->providerNode(self::PROVIDER_B)],
        'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
          $this->link(self::PROVIDER_A),
          $this->link(self::PROVIDER_B),
        ],
        'field_data_field_categories' => [
          $this->category(self::PROVIDER_A, self::CATEGORY),
          $this->category(self::PROVIDER_B, self::OTHER_CATEGORY),
        ],
      ]
    );

    $this->assertSame([301, 300], $this->ids($result));
  }

  /* -------------------------------------------------------------------------
   * The item: thirteen keys.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly thirteen keys, in the documented order.
   */
  public function testTheItemHasThirteenKeysInOrder() {
    $result = $this->board([$this->request()]);
    $item = $this->items($result)[0];

    $this->assertSame([
      'id',
      'title',
      'description',
      'status',
      'category',
      'unit',
      'offers_count',
      'assigned_offer',
      'assigned_provider',
      'created',
      'desired_start',
      'requester',
      'condominium',
    ], array_keys($item));
  }

  /**
   * The ELEVEN first keys are byte for byte what
   * myapi_service_request_build_item() answers over the same row — the
   * guarantee that the two listings cannot diverge. `unit` is compared apart
   * because decision 5 is allowed to null it, and here it does.
   */
  public function testTheElevenFirstKeysAreTheSharedBuildersOwn() {
    $row = (object) $this->request();
    $row->requester_name = 'Ana Pérez';

    $shared = myapi_service_request_build_item($row, [self::NID => 0]);
    $provider = myapi_service_request_provider_build_item($row, [self::NID => 0], [self::PROVIDER_A]);

    foreach ($shared as $key => $value) {
      if ($key === 'unit') {
        continue;
      }
      $this->assertSame($value, $provider[$key], $key);
    }

    $this->assertSame(11, count($shared));
    $this->assertSame(13, count($provider));
  }

  /**
   * `requester` is {id, name} with the name myapi_user_display_names() builds,
   * and carries NO contact detail whatsoever.
   */
  public function testTheRequesterIsAnIdAndANameAndNothingElse() {
    $result = $this->board([$this->request()]);
    $requester = $this->items($result)[0]['requester'];

    $this->assertSame(['id', 'name'], array_keys($requester));
    $this->assertSame(self::REQUESTER_UID, $requester['id']);
    $this->assertSame('Ana Pérez', $requester['name']);
  }

  /**
   * A requester with no field_nombre falls back to the same value
   * myapi_user_display_names() already answers, and the item does not break.
   */
  public function testARequesterWithNoProfileNameFallsBack() {
    $result = $this->board([$this->request()], [
      'users' => [$this->userRow(self::REQUESTER_UID, 'aperez', NULL, NULL)],
    ]);

    $this->assertSame('aperez', $this->items($result)[0]['requester']['name']);
  }

  /**
   * `condominium` is {id, name} with the NODE TITLE as the name, and it
   * travels in every item — awarded or not (decision 6).
   */
  public function testTheCondominiumTravelsInEveryItem() {
    $result = $this->board([
      $this->request(300),
      $this->awarded(301, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED),
    ], ['field_data_field_assigned_provider' => [$this->assignment(301, self::PROVIDER_A)]]);

    foreach ($this->items($result) as $item) {
      $this->assertSame(['id', 'name'], array_keys($item['condominium']));
      $this->assertSame(self::CONDOMINIUM, $item['condominium']['id']);
      $this->assertSame('Residencial Los Almendros', $item['condominium']['name']);
    }
  }

  /**
   * `condominium` is a WHOLE null — never {id: null, name: null} — when the
   * reference is empty or the node was deleted, and the request KEEPS ITS
   * PLACE in the list.
   */
  public function testAMissingCondominiumIsAWholeNull() {
    $result = $this->board([$this->request(self::NID, [
      'condominium_id'   => NULL,
      'condominium_name' => NULL,
    ])]);

    $this->assertSame([self::NID], $this->ids($result));
    $this->assertNull($this->items($result)[0]['condominium']);
  }

  /**
   * `requester` is a whole null in the same case.
   */
  public function testAMissingRequesterIsAWholeNull() {
    $row = (object) $this->request();
    $row->requester_uid = NULL;

    $item = myapi_service_request_provider_build_item($row, [], []);

    $this->assertNull($item['requester']);
    $this->assertArrayHasKey('requester', $item, 'the key travels even so');
  }

  /**
   * `offers_count` is the REAL total, competition included, and it counts
   * OFFERS and not service_transaction rows.
   */
  public function testOffersCountIsTheRealTotalOfPublishedOffers() {
    $result = $this->board([$this->request()], [
      'field_data_field_request' => [
        $this->offer(self::NID, self::FOREIGN_PROVIDER, 901),
        $this->offer(self::NID, 98, 902),
      ],
    ]);

    $this->assertSame(2, $this->items($result)[0]['offers_count']);
  }

  /**
   * `assigned_provider` names the winner even when it is a rival
   * (decision 10).
   */
  public function testTheWinnerIsNamedEvenWhenItIsARival() {
    $result = $this->board(
      [$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_CLOSED)],
      [
        'field_data_field_request'           => [$this->offer(self::NID, self::PROVIDER_A)],
        'field_data_field_assigned_provider' => [$this->assignment(self::NID, self::FOREIGN_PROVIDER)],
      ]
    );

    $item = $this->items($result)[0];
    $this->assertSame(self::FOREIGN_PROVIDER, $item['assigned_provider']['id']);
    $this->assertSame('Proveedor ' . self::FOREIGN_PROVIDER, $item['assigned_provider']['name']);
  }

  /* -------------------------------------------------------------------------
   * The unit rule (decision 5).
   * ---------------------------------------------------------------------- */

  /**
   * Awarded to one of my providers: the unit travels.
   */
  public function testTheUnitTravelsWhenTheJobIsMine() {
    $result = $this->board(
      [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED)],
      ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]]
    );

    $this->assertSame(['id' => 55, 'name' => 'A-301'], $this->items($result)[0]['unit']);
  }

  /**
   * An unassigned 'open' request of my category: unit is null. The flat number
   * adds nothing to the decision to bid (SPEC 89).
   */
  public function testTheUnitIsNullOnAnUnassignedMarketRequest() {
    $result = $this->board([$this->request()]);

    $this->assertNull($this->items($result)[0]['unit']);
    $this->assertArrayHasKey('unit', $this->items($result)[0], 'the key is never omitted');
  }

  /**
   * Awarded to SOMEBODY ELSE — even one I offered on: unit is null.
   */
  public function testTheUnitIsNullWhenTheJobWentToARival() {
    $result = $this->board(
      [$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_CLOSED)],
      [
        'field_data_field_request'           => [$this->offer(self::NID, self::PROVIDER_A)],
        'field_data_field_assigned_provider' => [$this->assignment(self::NID, self::FOREIGN_PROVIDER)],
      ]
    );

    $this->assertNull($this->items($result)[0]['unit']);
  }

  /**
   * An award pointing at a DELETED or unpublished provider node answers
   * assigned_provider: null, and therefore unit: null. The rule fails towards
   * the closed side, which is the only direction a privacy rule may fail in.
   */
  public function testABrokenAwardClosesTheUnit() {
    $row = (object) $this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED, [
      // The raw column survives; the joined pair does not.
      'assigned_provider_id'   => NULL,
      'assigned_provider_name' => NULL,
    ]);

    $item = myapi_service_request_provider_build_item($row, [], [self::PROVIDER_A]);

    $this->assertNull($item['assigned_provider']);
    $this->assertNull($item['unit']);
  }

  /**
   * THE CASE THAT PINS THE RULE TO THE ACCOUNT AND NOT TO THE FILTER: with
   * '?provider_id=A', a request awarded to my OTHER provider B that shows up
   * through A's offer still carries the unit.
   */
  public function testTheUnitFollowsTheAccountAndNotTheProviderIdFilter() {
    $this->authenticate();
    $this->seed(
      [$this->awarded(self::NID, self::PROVIDER_B, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED)],
      [
        'node' => [$this->providerNode(self::PROVIDER_A), $this->providerNode(self::PROVIDER_B)],
        'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
          $this->link(self::PROVIDER_A),
          $this->link(self::PROVIDER_B),
        ],
        'field_data_field_categories' => [
          $this->category(self::PROVIDER_A, self::CATEGORY),
          $this->category(self::PROVIDER_B, self::CATEGORY),
        ],
        'field_data_field_request'           => [$this->offer(self::NID, self::PROVIDER_A)],
        'field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_B)],
      ]
    );
    $_GET['provider_id'] = (string) self::PROVIDER_A;

    $result = $this->dispatch();

    $this->assertSame([self::NID], $this->ids($result), 'it is listed through A');
    $this->assertSame(
      ['id' => 55, 'name' => 'A-301'],
      $this->items($result)[0]['unit'],
      'and the unit travels, because B is mine too'
    );
  }

  /* -------------------------------------------------------------------------
   * The empty OR (risk 3) and the role helpers.
   * ---------------------------------------------------------------------- */

  /**
   * AN EMPTY SCOPE PRODUCES AN IMPOSSIBLE CONDITION AND NEVER AN EMPTY OR.
   *
   * Drupal 7 compiles a condition group with no conditions to NOTHING, so a
   * base query built from an empty scope without the guard would answer EVERY
   * request in the system. This asserts the second defence directly, bypassing
   * the endpoint's short circuit.
   */
  public function testAnEmptyScopeAddsAnImpossibleConditionAndNotAnEmptyGroup() {
    // A request that would match EVERY other condition of the base query, so
    // that if the scope compiled to nothing it would come back.
    myapi_test_db_seed(['node' => [$this->request()]]);
    myapi_test_static_reset();

    $query = myapi_service_request_base_query(NULL, [
      'provider_scope' => ['nids' => [], 'category_ids' => [], 'biddable' => FALSE],
    ]);
    $query->addField('n', 'nid', 'nid');

    $this->assertSame([], $query->execute()->fetchCol(), 'an empty scope answers NOTHING');

    $recorded = myapi_test_db_queries();
    $conditions = [];
    foreach (end($recorded)['conditions'] as $condition) {
      if ($condition['operator'] === 'GROUP') {
        $this->assertNotEmpty(
          $condition['group']->conditions(),
          'a condition group with no conditions matches EVERYTHING — see risk 3'
        );
        continue;
      }
      $conditions[$condition['field']] = $condition;
    }

    $this->assertArrayHasKey('n.nid', $conditions, 'the impossible condition is there');
    $this->assertSame([0], (array) $conditions['n.nid']['value']);
    $this->assertSame('IN', $conditions['n.nid']['operator']);
  }

  /**
   * And the endpoint never lets an empty scope get that far anyway: the FIRST
   * of the two defences answers before counting or paging. The scope itself
   * still costs its own reads — the link and the licence — but no count query
   * and no page query are ever issued.
   */
  public function testAnEmptyScopeNeverReachesTheCountQuery() {
    $result = $this->board([$this->request()], [
      'field_data_field_categories' => [],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));

    foreach (myapi_test_db_queries() as $query) {
      $this->assertNotTrue($query['count'], 'no count query ran');
      $this->assertNotSame(
        MYAPI_SERVICES_REQUEST_TYPE,
        isset($query['conditions'][0]['value']) ? $query['conditions'][0]['value'] : NULL,
        'and no listing query ran either'
      );
    }
  }

  /**
   * A scope with NO provider ids costs no query, which is what makes the
   * role-with-no-link case free.
   */
  public function testAnEmptyProviderListCostsNoQuery() {
    myapi_test_db_seed([]);

    $this->assertSame([], myapi_provider_role_assigned_request_ids([]));
    $this->assertSame([], myapi_provider_role_offered_request_ids([]));
    $this->assertSame([], myapi_provider_role_category_ids_for_providers([]));
    $this->assertFalse(myapi_provider_role_any_provider_active_for_providers([]));
    $this->assertSame(
      ['nids' => [], 'category_ids' => [], 'biddable' => FALSE],
      myapi_service_request_provider_scope([])
    );

    $this->assertSame([], myapi_test_db_queries(), 'not one query was issued');
  }

  /**
   * The refactor of SPEC 98 left the two account-level helpers answering
   * exactly what they answered before: they are now one-line wrappers, and
   * this is the assertion that says so.
   */
  public function testTheAccountHelpersStillAgreeWithTheirExtractedBodies() {
    $this->seed([], [
      'node' => [$this->providerNode(self::PROVIDER_A)],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [$this->link(self::PROVIDER_A)],
      'field_data_field_categories' => [$this->category(self::PROVIDER_A)],
    ]);

    $account = (object) ['uid' => self::UID];
    $ids = myapi_provider_role_provider_ids($account);

    $this->assertSame(
      myapi_provider_role_category_ids_for_providers($ids),
      myapi_provider_role_category_ids($account)
    );
    $this->assertSame(
      myapi_provider_role_any_provider_active_for_providers($ids),
      myapi_provider_role_any_provider_active($account)
    );
  }

  /**
   * The scope resolves 'biddable' BEFORE the categories and switches them off:
   * a suspended provider costs one query less, and B cannot exist for it.
   */
  public function testASuspendedProviderResolvesNoCategories() {
    $this->seed([], [
      'node' => [$this->providerNode(self::PROVIDER_A, '0')],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [$this->link(self::PROVIDER_A)],
      'field_data_field_categories' => [$this->category(self::PROVIDER_A)],
    ]);

    $scope = myapi_service_request_provider_scope([self::PROVIDER_A]);

    $this->assertFalse($scope['biddable']);
    $this->assertSame([], $scope['category_ids']);
  }

  /* -------------------------------------------------------------------------
   * Pagination, filters and order.
   * ---------------------------------------------------------------------- */

  /**
   * The defaults: limit 20, page 1, newest first.
   */
  public function testTheDefaultsAreTwentyPerPageNewestFirst() {
    $result = $this->board([
      $this->request(300, ['created' => (string) (self::CREATED - 100)]),
      $this->request(301),
    ]);

    $this->assertSame([301, 300], $this->ids($result));
    $this->assertSame(20, $this->pagination($result)['limit']);
    $this->assertSame(1, $this->pagination($result)['page']);
    $this->assertSame(2, $this->pagination($result)['total']);
    $this->assertSame(1, $this->pagination($result)['total_pages']);
  }

  /**
   * '?sort=asc' reverses it, and anything else falls back to 'desc'.
   */
  public function testSortAscReversesTheOrderAndGarbageFallsBack() {
    $requests = [
      $this->request(300, ['created' => (string) (self::CREATED - 100)]),
      $this->request(301),
    ];

    $this->authenticate();
    $this->seed($requests);
    $_GET['sort'] = 'asc';
    $this->assertSame([300, 301], $this->ids($this->dispatch()));

    $this->authenticate();
    $this->seed($requests);
    $_GET['sort'] = 'sideways';
    $this->assertSame([301, 300], $this->ids($this->dispatch()));
  }

  /**
   * '?limit' is clamped to 50, garbage falls back to 20, and none of it is a
   * 422.
   */
  public function testLimitIsClampedAndGarbageFallsBack() {
    foreach (['999' => 50, 'abc' => 20, '5' => 5] as $raw => $expected) {
      $this->authenticate();
      $this->seed([$this->request()]);
      $_GET['limit'] = (string) $raw;

      $result = $this->dispatch();

      $this->assertSame(200, $result['status'], (string) $raw);
      $this->assertSame($expected, $this->pagination($result)['limit'], (string) $raw);
    }
  }

  /**
   * '?limit=-1' is everything on one page, with page forced to 1 (SPEC 15).
   */
  public function testLimitMinusOneIsEverythingOnOnePage() {
    $this->authenticate();
    $this->seed([$this->request(300), $this->request(301)]);
    $_GET['limit'] = '-1';
    $_GET['page'] = '4';

    $result = $this->dispatch();

    $this->assertSame(-1, $this->pagination($result)['limit']);
    $this->assertSame(1, $this->pagination($result)['page']);
    $this->assertSame(1, $this->pagination($result)['total_pages']);
    $this->assertCount(2, $this->items($result));
  }

  /**
   * A page past the last one is 200 with an empty list, never a 404.
   */
  public function testAPagePastTheLastOneIsAnEmptyList() {
    $this->authenticate();
    $this->seed([$this->request()]);
    $_GET['page'] = '9';

    $result = $this->dispatch();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));
    $this->assertSame(1, $this->pagination($result)['total'], 'the total still describes the whole set');
  }

  /**
   * '?status' narrows, and an invented key is dropped in silence — never 422.
   */
  public function testStatusFiltersAndAnInventedKeyIsIgnored() {
    $requests = [
      $this->request(300),
      $this->awarded(301, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_CLOSED),
    ];
    $tables = ['field_data_field_assigned_provider' => [$this->assignment(301, self::PROVIDER_A)]];

    $this->authenticate();
    $this->seed($requests, $tables);
    $_GET['status'] = MYAPI_SERVICES_REQUEST_STATUS_CLOSED;
    $this->assertSame([301], $this->ids($this->dispatch()));

    $this->authenticate();
    $this->seed($requests, $tables);
    $_GET['status'] = 'inventado';
    $result = $this->dispatch();
    $this->assertSame(200, $result['status']);
    $this->assertSame([301, 300], $this->ids($result));
  }

  /**
   * '?category_id' is strict in the format — 422 — and a tid I do not attend
   * simply intersects in the empty list.
   */
  public function testCategoryIdIsStrictInFormatAndLaxInContent() {
    $this->authenticate();
    $this->seed([$this->request()]);
    $_GET['category_id'] = 'abc';
    $result = $this->dispatch();
    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);

    $this->authenticate();
    $this->seed([$this->request()]);
    $_GET['category_id'] = (string) self::OTHER_CATEGORY;
    $result = $this->dispatch();
    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));
  }

  /**
   * '?unit_id' DOES NOT EXIST ON THIS ROUTE: it is ignored in silence, and the
   * answer is identical to the one without it. Never a 422, because filtering
   * by flat contradicts decision 5.
   */
  public function testUnitIdIsIgnoredInSilence() {
    $this->authenticate();
    $this->seed([$this->request()]);
    $without = $this->dispatch();

    $this->authenticate();
    $this->seed([$this->request()]);
    $_GET['unit_id'] = '30057';
    $with = $this->dispatch();

    $this->assertSame(200, $with['status']);
    $this->assertSame($without['json'], $with['json'], 'the parameter changed nothing at all');
  }

  /**
   * An unknown parameter is ignored too.
   */
  public function testAnUnknownParameterIsIgnored() {
    $this->authenticate();
    $this->seed([$this->request()]);
    $_GET['whatever'] = 'x';

    $result = $this->dispatch();
    unset($_GET['whatever']);

    $this->assertSame(200, $result['status']);
    $this->assertSame([self::NID], $this->ids($result));
  }

  /* -------------------------------------------------------------------------
   * Cost.
   * ---------------------------------------------------------------------- */

  /**
   * NOT ONE QUERY GROWS WITH THE PAGE: twenty requests cost exactly what one
   * costs. The names of the whole page are resolved in ONE call, never one per
   * row — the regression this asserts is the classic N+1.
   */
  public function testTheQueryCountDoesNotGrowWithThePage() {
    $one = $this->board([$this->request(300)]);
    $this->assertSame(200, $one['status']);
    $single = count(myapi_test_db_queries());

    $many = [];
    for ($i = 0; $i < 20; $i++) {
      $many[] = $this->request(300 + $i, ['created' => (string) (self::CREATED - $i)]);
    }

    $result = $this->board($many);
    $this->assertCount(20, $this->items($result));

    $this->assertSame(
      $single,
      count(myapi_test_db_queries()),
      'twenty requests cost what one costs'
    );
  }

  /**
   * No contact detail is fetched for anybody: the query that resolves the
   * names reads `users` and the two name fields, and this resource never calls
   * myapi_user_fetch_profile_fields().
   */
  public function testNoContactDetailIsEverFetched() {
    $result = $this->board([$this->request()]);

    $this->assertSame(200, $result['status']);
    $this->assertStringNotContainsString('email', $result['output']);
    $this->assertStringNotContainsString('phone', $result['output']);
    $this->assertStringNotContainsString('cedula', $result['output']);
  }

}
