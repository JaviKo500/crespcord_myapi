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
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/service-requests/provider/% (SPEC 99).
 *
 * The sibling of ServiceRequestProviderListTest, and the same harness:
 * myapi_service_request_provider_item_dispatch() is called the way hook_menu()
 * calls it, over a fixture `node` table, a fixture my_api_tokens row, a
 * fixture account carrying its roles and a fixture Authorization header. What
 * is asserted is the JSON body the module prints and the status it sets.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: joins are
 * recorded and never resolved, so a request is seeded flat — its own node
 * columns plus the value each JOIN would have brought, under the alias the
 * query gives it. One row therefore feeds BOTH endpoints at once, which is
 * what makes the equivalence test below possible at all.
 *
 * THE AWARD IS SEEDED FOUR TIMES, and that is not redundancy — it is the same
 * datum read by four consumers under four names.
 * `field_assigned_provider_target_id` is what the listing's set B compares;
 * `assigned_provider_raw` is what rule 2b of myapi_service_request_viewer()
 * reads; `assigned_provider_id` / `assigned_provider_name` are what the chain
 * of LEFT JOINs projects for the item, and they are what the `unit` rule
 * measures. They can legitimately DISAGREE — an award pointing at a deleted
 * node keeps the raw column and nulls the joined pair — and that is exactly
 * the case the unit rule has to fail closed on.
 *
 * What this suite does NOT prove, all of it the router's or the database's
 * half:
 *
 *  - that Drupal really routes 'api/v1/service-requests/provider/7' here and
 *    'api/v1/service-requests/7/cancel' elsewhere. hook_menu() is not run in
 *    tests/unit; that is a manual acceptance criterion against a booted site,
 *    the same one the listing carries since SPEC 98.
 *  - that MySQL evaluates the listing's nested OR the way the fixture
 *    evaluator does.
 *
 * What it DOES prove is everything the module decides: the role gate, the
 * three ways in, the 403s, the nineteen keys, the unit rule, `my_offers`
 * against `offers_count`, and the equivalence with the listing that risk 1
 * exists for.
 */
class ServiceRequestProviderDetailTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  /**
   * The provider account: the reader of this endpoint.
   */
  const UID = 7;

  /**
   * The resident who asked for the work. The reader only in the case that
   * proves a requester is refused HERE.
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
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_static_reset();
    myapi_test_db_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  private function tokenRow($uid = self::UID, array $overrides = []) {
    return $overrides + [
      'id'                => '1',
      'uid'               => (string) $uid,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * One service_request row, flat, as every join of BOTH the listing and the
   * detail delivers it. 'open', of the account's category, unassigned — that
   * is, readable through rule 3 — so every case that is not about the access
   * rule reads clean.
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
      'description'                    => "El calentador gotea.\nDesde el lunes.",
      'desired_start'                  => (string) (self::CREATED + 86400),
      // Unassigned: the four names of "no award", one per consumer — see the
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
   * Writes the award under its four names at once, which is what a coherent
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
      // The bundle, which no query of the provider_role include reads — it
      // filters by nid — but which the timeline query DOES: the fixture engine
      // skips a condition over a column the row does not carry, so a provider
      // node with no `type` would walk into `transactions` (SPEC 93).
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
   * One offer of $provider_nid on $nid, as BOTH readers of
   * field_data_field_request deliver it: the 'fq' alias of the offers query
   * and of the count, and the bare column that
   * myapi_provider_role_offered_request_ids() reads through its own alias.
   *
   * One fixture row therefore both GRANTS ACCESS through rule 2 and RAISES
   * offers_count, exactly as it does in production.
   */
  private function offer($nid, $provider_nid, $offer_nid = 900, array $overrides = []) {
    return $overrides + [
      'entity_id'                  => (string) $offer_nid,
      'entity_type'                => 'node',
      'deleted'                    => '0',
      'field_request_target_id'    => (string) $nid,
      'fq.field_request_target_id' => (string) $nid,
      'field_provider_target_id'   => (string) $provider_nid,
      // What the INNER JOIN to the offer node brings.
      'nid'                        => (string) $offer_nid,
      'created'                    => (string) (self::CREATED + $offer_nid),
      'no.type'                    => MYAPI_SERVICES_OFFER_TYPE,
      'no.status'                  => '1',
      // The joined provider of the offer, and its serialised payload.
      'provider_id'                => (string) $provider_nid,
      'provider_name'              => 'Proveedor ' . $provider_nid,
      'provider_logo_uri'          => NULL,
      'amount'                     => NULL,
      'message'                    => 'Puedo pasar el jueves.',
      // Qualified: 'status' alone is the offer node's published flag.
      'fost.field_offer_status_value' => 'sent',
    ];
  }

  /**
   * One row of field_data_field_assigned_provider: the listing's set C.
   *
   * Seeded ALONGSIDE the award columns of the node row, because the two are
   * two readings of the same fact and production keeps them in step.
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
   * One row of field_data_field_images, joined to its file_managed row.
   */
  private function image($fid, $filename, $delta, $nid = self::NID) {
    return [
      'entity_id'   => (string) $nid,
      'entity_type' => 'node',
      'deleted'     => '0',
      'delta'       => (string) $delta,
      'fid'         => (string) $fid,
      'filename'    => $filename,
    ];
  }

  /**
   * One 'service_transaction' of the request (SPEC 93), seeded into `node`
   * because that is the timeline query's BASE table — the bundle condition is
   * the first thing it says, and the request itself is told apart by exactly
   * what tells them apart in SQL: `type`.
   */
  private function transaction($nid, $status_date, array $overrides = []) {
    return $overrides + [
      'n.nid'                          => (string) $nid,
      'nid'                            => (string) $nid,
      'type'                           => MYAPI_SERVICES_TRANSACTION_TYPE,
      // The node's published flag, not the transaction's status.
      'status'                         => '1',
      'created'                        => (string) self::CREATED,
      'fr.field_request_target_id'     => (string) self::NID,
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'status_date'                    => $status_date,
      'comment'                        => 'Hemos recibido su solicitud.',
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
   * Seeds a whole scenario in one call: every myapi_test_db_seed() replaces
   * the entire fixture, so nothing can be added afterwards.
   *
   * @param array $requests
   *   service_request rows.
   * @param array $tables
   *   Extra fixture tables, merged over the defaults. A 'node' key REPLACES
   *   the default provider node, so a case that seeds transactions passes the
   *   provider node too.
   * @param array|NULL $roles
   *   The reader's roles; the provider role by default, because that is the
   *   precondition of every case that is not about the gate.
   * @param int $reader_uid
   *   Whose token travels. UID everywhere except the case that proves a
   *   requester is refused here.
   */
  private function seed(array $requests, array $tables = [], $roles = NULL, $reader_uid = self::UID) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;

    $GLOBALS['myapi_test_users'][$reader_uid] = [
      'uid'    => $reader_uid,
      'name'   => 'usuario' . $reader_uid,
      'status' => 1,
      'roles'  => $roles,
    ];

    $tables += [
      'my_api_tokens' => [$this->tokenRow($reader_uid)],
      'users'         => [$this->userRow()],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
        $this->link(self::PROVIDER_A),
      ],
      'field_data_field_categories' => [
        $this->category(self::PROVIDER_A),
      ],
      'field_data_field_request'           => [],
      'field_data_field_assigned_provider' => [],
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
   * Runs the endpoint the way hook_menu() does — the wildcard raw, as Drupal
   * hands it over.
   */
  private function dispatch($nid = self::NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_request_provider_item_dispatch($nid);
    });
  }

  /**
   * Runs the LISTING the way hook_menu() does, for the equivalence test and
   * for the non-regression ones.
   */
  private function dispatchListing() {
    return myapi_test_capture('myapi_service_request_provider_dispatch');
  }

  /**
   * Runs the GENERAL detail of SPEC 89, for the non-regression tests.
   */
  private function dispatchGeneralDetail($nid = self::NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_request_item_dispatch($nid);
    });
  }

  /**
   * Authenticates, seeds and runs, which is what almost every case needs.
   */
  private function detail(array $requests, array $tables = [], $roles = NULL, $reader_uid = self::UID, $nid = self::NID) {
    $this->authenticate();
    $this->seed($requests, $tables, $roles, $reader_uid);

    return $this->dispatch($nid);
  }

  private function item(array $result) {
    return $result['json']['data']['service_request'];
  }

  /* -------------------------------------------------------------------------
   * The method, the nid and the token.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, and the method is checked BEFORE the
   * token and before any query: a POST with no Authorization header at all is
   * still 405, never 401.
   */
  public function testEveryMethodOtherThanGetIs405BeforeAuthentication() {
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
      $this->seed([$this->request()]);
      $_SERVER['REQUEST_METHOD'] = $method;
      unset($_SERVER['HTTP_AUTHORIZATION']);
      myapi_test_db_queries();

      $result = $this->dispatch();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame([], myapi_test_db_queries(), $method . ' cost no query');
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
   * A revoked, an expired and an invented token are all 401 invalid_token, and
   * the role gate is never reached.
   */
  public function testABrokenTokenIs401() {
    $cases = [
      'revoked' => ['token' => self::TOKEN, 'row' => ['revoked' => '1']],
      'expired' => ['token' => self::TOKEN, 'row' => ['access_expires_at' => REQUEST_TIME - 1]],
      'invented' => ['token' => 'not-a-token', 'row' => []],
    ];

    foreach ($cases as $label => $case) {
      $this->seed([$this->request()], [
        'my_api_tokens' => [$this->tokenRow(self::UID, $case['row'])],
      ]);
      $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $case['token'];

      $result = $this->dispatch();

      $this->assertSame(401, $result['status'], $label);
      $this->assertSame('invalid_token', $result['json']['error_code'], $label);
    }
  }

  /**
   * A wildcard that is not a positive integer is 404 not_found, and it costs
   * NOT ONE QUERY — not even the token's. The answer is about the SHAPE of the
   * URL and not about what exists.
   */
  public function testAMalformedNidIs404WithoutAQuery() {
    foreach (['abc', '0', '-1', '1.5', '', '12abc'] as $raw) {
      $this->authenticate();
      $this->seed([$this->request()]);
      myapi_test_db_queries();

      $result = $this->dispatch($raw);

      $this->assertSame(404, $result['status'], var_export($raw, TRUE));
      $this->assertSame('not_found', $result['json']['error_code'], var_export($raw, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($raw, TRUE) . ' cost no query');
    }
  }

  /**
   * An array wildcard cannot reach a string cast: is_scalar() comes first, so
   * '/provider/%' with an array answers 404 and never a PHP notice in the
   * middle of a JSON body.
   */
  public function testAnArrayNidIs404() {
    $this->authenticate();
    $this->seed([$this->request()]);

    $result = $this->dispatch(['1']);

    $this->assertSame(404, $result['status']);
    $this->assertSame('not_found', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * The role gate — the one step this endpoint adds (decision 3).
   * ---------------------------------------------------------------------- */

  /**
   * An authenticated account WITHOUT the 'proveedor' role is 403
   * provider_role_required — EVEN THOUGH a provider node references it and the
   * request is one the general detail would hand it. That difference between
   * the two routes is the whole point of decision 3.
   */
  public function testAnAccountWithoutTheRoleIs403ProviderRoleRequired() {
    $result = $this->detail([$this->request()], [], ['authenticated user']);

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);

    // And the same fixture, on the general detail, is a 200: the account does
    // operate a provider of the category. The gate is the only difference.
    myapi_test_static_reset();
    $general = $this->dispatchGeneralDetail();
    $this->assertSame(200, $general['status'], 'precondition: the general detail lets this reader in');
  }

  /**
   * An account WITH the role but with no provider node behind it is 403
   * forbidden and NOT provider_role_required: the role is there, there is just
   * nothing to operate with it (decision 5). It is also not a 200 with an
   * empty payload — a detail has no way of saying "nothing".
   */
  public function testTheRoleWithNoProviderIs403Forbidden() {
    $result = $this->detail([$this->request()], [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [],
      'field_data_field_categories'              => [],
    ]);

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * THE GATE RUNS BEFORE THE ROW IS LOADED: a nid that does not exist, asked
   * for by an account with no role, answers 403 provider_role_required and
   * never 404. A reader who is not on this route may not learn which requests
   * exist.
   */
  public function testTheGateIsEvaluatedBeforeTheRequestIsLoaded() {
    $result = $this->detail([$this->request()], [], ['authenticated user'], self::UID, 999999);

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
  }

  /**
   * And the second step of the gate runs before it too: the role with no
   * provider asking for a nid that does not exist is 403 forbidden, not 404.
   */
  public function testTheEmptyProviderGateAlsoRunsBeforeTheRequestIsLoaded() {
    $result = $this->detail([$this->request()], [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [],
      'field_data_field_categories'              => [],
    ], NULL, self::UID, 999999);

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * The three ways in — rules 3, 2 and 2b of myapi_service_request_viewer().
   * ---------------------------------------------------------------------- */

  /**
   * RULE 3, the open market: an 'open' or 'offered' request of my category,
   * unawarded, with an active provider of mine, is 200. A request with offers
   * on it is still awardable, so closing it to newcomers the moment the first
   * bid lands would reward whoever was fastest.
   */
  public function testTheOpenMarketOfMyCategoryIs200() {
    foreach ([
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
    ] as $status) {
      $result = $this->detail([
        $this->request(self::NID, ['frs.field_request_status_value' => $status]),
      ]);

      $this->assertSame(200, $result['status'], $status);
      $this->assertSame('provider', $this->item($result)['viewer'], $status);
    }
  }

  /**
   * RULE 2, already offered: a request one of my providers bid on is 200 in
   * ANY status — 'assigned', 'closed' and 'cancelled' included — because
   * whoever has an offer needs to see what became of it.
   */
  public function testARequestIAlreadyOfferedOnIs200InEveryStatus() {
    foreach ([
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ] as $status) {
      $result = $this->detail([
        $this->awarded(self::NID, self::FOREIGN_PROVIDER, $status),
      ], [
        'field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)],
        'field_data_field_assigned_provider' => [
          $this->assignment(self::NID, self::FOREIGN_PROVIDER),
        ],
      ]);

      $this->assertSame(200, $result['status'], $status);
    }
  }

  /**
   * RULE 2, and the part of it that is easy to lose: the request has left my
   * category since I bid on it, and I still read it.
   */
  public function testARequestIOfferedOnIs200EvenOutOfMyCategory() {
    $result = $this->detail([
      $this->request(self::NID, [
        'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
        'category_id'             => (string) self::OTHER_CATEGORY,
      ]),
    ], [
      'field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)],
    ]);

    $this->assertSame(200, $result['status']);
  }

  /**
   * RULE 2b, awarded to me: a 'direct' request awarded to one of my providers
   * is 200 — the very 403 SPEC 89 left written in its Riesgos, closed by
   * SPEC 98 and consumed here without touching the rule.
   */
  public function testADirectRequestAwardedToMeIs200() {
    $result = $this->detail([
      $this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_DIRECT),
    ], [
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::PROVIDER_A),
      ],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame('provider', $this->item($result)['viewer']);
  }

  /**
   * RULE 2b in the terminal statuses: a job of mine stays readable after it is
   * assigned, closed or cancelled.
   */
  public function testAJobAwardedToMeIs200InTheTerminalStatuses() {
    foreach ([
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ] as $status) {
      $result = $this->detail([
        $this->awarded(self::NID, self::PROVIDER_A, $status),
      ], [
        'field_data_field_assigned_provider' => [
          $this->assignment(self::NID, self::PROVIDER_A),
        ],
      ]);

      $this->assertSame(200, $result['status'], $status);
    }
  }

  /* -------------------------------------------------------------------------
   * The 403s and the 404s.
   * ---------------------------------------------------------------------- */

  /**
   * A 'direct' request awarded to SOMEBODY ELSE is 403: it was born awarded,
   * so rule 3 excludes it by definition and rule 2b points elsewhere.
   */
  public function testAForeignDirectRequestIs403() {
    $result = $this->detail([
      $this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_DIRECT),
    ], [
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::FOREIGN_PROVIDER),
      ],
    ]);

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * An 'open' request of a category I do not attend is 403.
   */
  public function testAnOpenRequestOfAnotherCategoryIs403() {
    $result = $this->detail([
      $this->request(self::NID, [
        'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
        'category_id'             => (string) self::OTHER_CATEGORY,
      ]),
    ]);

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * A request already awarded to a rival that I never bid on is 403 — the
   * competitor's job is not mine to read.
   */
  public function testARivalsJobINeverOfferedOnIs403() {
    $result = $this->detail([
      $this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED),
    ], [
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::FOREIGN_PROVIDER),
      ],
    ]);

    $this->assertSame(403, $result['status']);
  }

  /**
   * A request of my category with NO ACTIVE provider of mine is 403: rule 3
   * requires one, and an expired licence is not one.
   */
  public function testMyCategoryWithNoActiveProviderIs403() {
    $result = $this->detail([$this->request()], [
      'node' => [$this->providerNode(self::PROVIDER_A, '1', (string) (REQUEST_TIME - 86400))],
    ]);

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * THE REQUESTER OF THE REQUEST IS 403 HERE (decision 4), even holding the
   * 'proveedor' role. myapi_service_request_viewer() answers 'requester' and
   * this endpoint accepts only 'provider': their own request told through this
   * route would carry an empty `my_offers` and a `unit` decided by the award,
   * which is the same request counted wrong. Their route is
   * GET /api/v1/service-requests/%.
   */
  public function testTheRequesterIs403OnTheProvidersRoute() {
    $this->authenticate();
    $this->seed([$this->request()], [
      // The requester operates a provider of their own, so the role gate and
      // the empty-provider gate both pass and the 403 can only come from the
      // viewer verdict.
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
        $this->link(self::PROVIDER_A, self::REQUESTER_UID),
      ],
    ], ['authenticated user', MYAPI_PROVIDER_ROLE], self::REQUESTER_UID);

    $result = $this->dispatch();

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);

    // And the general detail still hands them their own request, whole.
    myapi_test_static_reset();
    $general = $this->dispatchGeneralDetail();
    $this->assertSame(200, $general['status']);
    $this->assertSame('requester', $general['json']['data']['service_request']['viewer']);
  }

  /**
   * A nid that does not exist, an unpublished one and one of another bundle
   * are all 404 not_found, and the reader is told none of them apart.
   */
  public function testTheThreeShapesOfNoSuchRequestAre404() {
    $cases = [
      'missing'     => [[$this->request(500)], self::NID],
      'unpublished' => [[$this->request(self::NID, ['status' => '0'])], self::NID],
      'other bundle' => [[$this->request(self::NID, ['type' => 'reclamo'])], self::NID],
    ];

    foreach ($cases as $label => $case) {
      list($requests, $nid) = $case;
      $result = $this->detail($requests, [], NULL, self::UID, $nid);

      $this->assertSame(404, $result['status'], $label);
      $this->assertSame('not_found', $result['json']['error_code'], $label);
    }
  }

  /* -------------------------------------------------------------------------
   * The equivalence with the listing (risk 1).
   * ---------------------------------------------------------------------- */

  /**
   * THE TEST RISK 1 EXISTS FOR, and the one that catches the drift it
   * describes: over the whole matrix of status × award × category × offered,
   * membership in the LISTING and a 200 on THIS endpoint must agree. If it is
   * on the board, it opens; if it opens, it is on the board.
   *
   * Both endpoints are run for real over the same fixture and the same token —
   * not the scope function against the viewer function, which would only prove
   * the two helpers agree and not that the two ROUTES do.
   *
   * A future spec that adds a biddable status to one form and not to the other
   * fails HERE, and nowhere else.
   */
  public function testTheListingAndThisDetailAgreeOverTheWholeMatrix() {
    $statuses = [
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ];

    $checked = 0;
    $opened = 0;

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

            $this->authenticate();
            $this->seed([$row], $tables);
            $listing = $this->dispatchListing();
            $listed = in_array(
              self::NID,
              array_column($listing['json']['data']['service_requests'], 'id'),
              TRUE
            );

            // The SAME fixture and the SAME token, through the other route.
            myapi_test_static_reset();
            $detail = $this->dispatch();

            $label = sprintf(
              'status=%s award=%s category=%d offered=%s',
              $status,
              $award === NULL ? 'none' : $award,
              $tid,
              $offered ? 'yes' : 'no'
            );

            $this->assertSame(
              $listed,
              $detail['status'] === 200,
              'the listing and the provider detail disagree — ' . $label
            );

            if ($listed) {
              $opened++;
            }
            $checked++;
          }
        }
      }
    }

    $this->assertSame(72, $checked, 'the whole matrix was walked');
    $this->assertGreaterThan(0, $opened, 'and it was not vacuously true');
  }

  /**
   * The same nid, the same account: the THIRTEEN first keys of this detail are
   * byte for byte the item the listing prints. Compared over a real response
   * from each route, not over two calls to the serialiser.
   */
  public function testTheThirteenFirstKeysAreTheListingsItemByteForByte() {
    $scenarios = [
      'open market' => [[$this->request()], []],
      'awarded to me' => [
        [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED)],
        ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]],
      ],
    ];

    foreach ($scenarios as $label => $scenario) {
      list($requests, $tables) = $scenario;

      $this->authenticate();
      $this->seed($requests, $tables);
      $listing = $this->dispatchListing();
      $listed = $listing['json']['data']['service_requests'][0];

      myapi_test_static_reset();
      $detail = $this->item($this->dispatch());

      $this->assertSame(
        $listed,
        array_slice($detail, 0, 13, TRUE),
        'the first thirteen keys differ — ' . $label
      );
    }
  }

  /* -------------------------------------------------------------------------
   * The shape of the response.
   * ---------------------------------------------------------------------- */

  /**
   * The envelope: success, and one object under 'service_request' — never a
   * list.
   */
  public function testTheEnvelopeIsOneObjectUnderServiceRequest() {
    $result = $this->detail([$this->request()]);

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame(['service_request'], array_keys($result['json']['data']));
    $this->assertArrayNotHasKey('message', $result['json']);
  }

  /**
   * Exactly nineteen keys, always the same ones, in the documented order.
   */
  public function testTheItemHasNineteenKeysInOrder() {
    $result = $this->detail([$this->request()]);

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
      'viewer',
      'images',
      'attachment',
      'closed_at',
      'my_offers',
      'transactions',
    ], array_keys($this->item($result)));
  }

  /**
   * The nineteen do not move when the data is at its emptiest: no unit, no
   * condominium, no award, no images, no offers, no timeline. A null is an
   * answer; an absent key is a question.
   */
  public function testTheNineteenKeysTravelOverAnEmptyRequest() {
    $result = $this->detail([
      $this->request(self::NID, [
        'unit_id'          => NULL,
        'unit_name'        => NULL,
        'condominium_id'   => NULL,
        'condominium_name' => NULL,
        'desired_start'    => NULL,
        'description'      => NULL,
        'category_code'    => NULL,
        'category_name'    => NULL,
      ]),
    ]);

    $item = $this->item($result);

    $this->assertSame(19, count($item));
    $this->assertNull($item['unit']);
    $this->assertNull($item['condominium']);
    $this->assertNull($item['assigned_offer']);
    $this->assertNull($item['assigned_provider']);
    $this->assertNull($item['attachment']);
    $this->assertNull($item['closed_at']);
    $this->assertSame('', $item['description']);
    $this->assertSame('', $item['category']['code'], 'never null: the client still has a string to compare');
    $this->assertSame([], $item['images']);
    $this->assertSame([], $item['my_offers']);
    $this->assertSame([], $item['transactions']);
  }

  /**
   * `viewer` is the constant 'provider' in every 200 (decision 8): the key
   * carries no information and travels anyway, so the Flutter model of the two
   * detail routes is the same object with the same parser.
   */
  public function testTheViewerIsAlwaysProvider() {
    $scenarios = [
      'rule 3' => [[$this->request()], []],
      'rule 2' => [
        [$this->request()],
        ['field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)]],
      ],
      'rule 2b' => [
        [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_DIRECT)],
        ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]],
      ],
    ];

    foreach ($scenarios as $label => $scenario) {
      $result = $this->detail($scenario[0], $scenario[1]);

      $this->assertSame(200, $result['status'], $label);
      $this->assertSame('provider', $this->item($result)['viewer'], $label);
    }
  }

  /**
   * `category` is {id, code, name}, in that order, and `code` is "" and not
   * null when the term has none.
   */
  public function testTheCategoryIsIdCodeAndName() {
    $result = $this->detail([$this->request()]);
    $category = $this->item($result)['category'];

    $this->assertSame(['id', 'code', 'name'], array_keys($category));
    $this->assertSame(self::CATEGORY, $category['id']);
    $this->assertSame('plumbing', $category['code']);
    $this->assertSame('Plomería', $category['name']);

    $result = $this->detail([$this->request(self::NID, ['category_code' => NULL])]);
    $this->assertSame('', $this->item($result)['category']['code']);
  }

  /**
   * `requester` is {id, name} with the name of the SPEC 09 rule, and carries
   * NO contact detail whatsoever.
   */
  public function testTheRequesterIsAnIdAndANameAndNothingElse() {
    $result = $this->detail([$this->request()]);
    $requester = $this->item($result)['requester'];

    $this->assertSame(['id', 'name'], array_keys($requester));
    $this->assertSame(self::REQUESTER_UID, $requester['id']);
    $this->assertSame('Ana Pérez', $requester['name']);

    // And the fallback of that same rule when there is no profile name.
    $result = $this->detail([$this->request()], [
      'users' => [$this->userRow(self::REQUESTER_UID, 'aperez', NULL, NULL)],
    ]);
    $this->assertSame('aperez', $this->item($result)['requester']['name']);
  }

  /**
   * `condominium` travels ALWAYS (decision 6) — in the open market and in a
   * job of mine alike: it names the area without naming the door.
   */
  public function testTheCondominiumTravelsInBothTheOpenMarketAndMyOwnJob() {
    $scenarios = [
      'open market' => [[$this->request()], []],
      'awarded to me' => [
        [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED)],
        ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]],
      ],
    ];

    foreach ($scenarios as $label => $scenario) {
      $condominium = $this->item($this->detail($scenario[0], $scenario[1]))['condominium'];

      $this->assertSame(['id', 'name'], array_keys($condominium), $label);
      $this->assertSame(self::CONDOMINIUM, $condominium['id'], $label);
      $this->assertSame('Residencial Los Almendros', $condominium['name'], $label);
    }
  }

  /**
   * `images` is the list of {id, url, filename} in delta order, and the urls
   * point at the FILE ROUTE OF SPEC 89 — never at system/files/..., which the
   * app could not open with a token, and never at a sibling
   * '/provider/{id}/files/{fid}', which decision 10 refused to create.
   */
  public function testTheImagesPointAtTheSpec89FileRoute() {
    $result = $this->detail([$this->request()], [
      'field_data_field_images' => [
        $this->image(91, 'fuga.jpg', 0),
        $this->image(93, 'valvula.jpg', 1),
      ],
    ]);

    $this->assertSame([
      [
        'id'       => 91,
        'url'      => url('api/v1/service-requests/128/files/91', ['absolute' => TRUE]),
        'filename' => 'fuga.jpg',
      ],
      [
        'id'       => 93,
        'url'      => url('api/v1/service-requests/128/files/93', ['absolute' => TRUE]),
        'filename' => 'valvula.jpg',
      ],
    ], $this->item($result)['images']);

    foreach ($this->item($result)['images'] as $image) {
      $this->assertStringNotContainsString('/provider/', $image['url']);
    }
  }

  /**
   * `attachment` is null when there is none and {id, url, filename} when there
   * is, on the same SPEC 89 route.
   */
  public function testTheAttachmentIsNullOrTheWholeObject() {
    $this->assertNull($this->item($this->detail([$this->request()]))['attachment']);

    $result = $this->detail([
      $this->request(self::NID, [
        'attachment_fid'      => '92',
        'attachment_filename' => 'presupuesto.pdf',
      ]),
    ]);

    $this->assertSame([
      'id'       => 92,
      'url'      => url('api/v1/service-requests/128/files/92', ['absolute' => TRUE]),
      'filename' => 'presupuesto.pdf',
    ], $this->item($result)['attachment']);
  }

  /**
   * `closed_at` is null in every request that is not closed, and the formatted
   * datestamp when it is.
   */
  public function testTheClosedAtIsNullUntilTheRequestIsClosed() {
    $this->assertNull($this->item($this->detail([$this->request()]))['closed_at']);

    $result = $this->detail([
      $this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_CLOSED, [
        'closed_at' => (string) (self::CREATED + 172800),
      ]),
    ], [
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::PROVIDER_A),
      ],
    ]);

    $this->assertSame(
      format_date(self::CREATED + 172800, 'custom', 'Y-m-d\TH:i:s'),
      $this->item($result)['closed_at']
    );
  }

  /**
   * `transactions` is the WHOLE timeline (decision 9), not trimmed to "my"
   * events, and always an array.
   */
  public function testTheTimelineTravelsWhole() {
    $result = $this->detail([$this->request()], [
      'node' => [
        $this->providerNode(self::PROVIDER_A),
        $this->transaction(701, '2026-08-01 09:00:00'),
        $this->transaction(702, '2026-08-02 10:30:00', [
          'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
          'comment' => 'Ha recibido una oferta.',
        ]),
      ],
    ]);

    $transactions = $this->item($result)['transactions'];

    $this->assertSame([701, 702], array_column($transactions, 'id'));
    $this->assertSame(
      [MYAPI_SERVICES_REQUEST_STATUS_OPEN, MYAPI_SERVICES_REQUEST_STATUS_OFFERED],
      array_column($transactions, 'status')
    );
    // The five keys of SPEC 93, in order, untouched by this spec.
    $this->assertSame(
      ['id', 'status', 'status_date', 'comment', 'created'],
      array_keys($transactions[0])
    );
    $this->assertSame('2026-08-01T09:00:00', $transactions[0]['status_date']);
  }

  /* -------------------------------------------------------------------------
   * The unit rule (decision 6 — SPEC 98's decision 5, moved to the detail).
   * ---------------------------------------------------------------------- */

  /**
   * The open market of my category, unawarded: `unit` is null. The flat number
   * adds nothing to the decision to bid and does say where a person lives.
   */
  public function testTheUnitIsNullInTheOpenMarket() {
    $item = $this->item($this->detail([$this->request()]));

    $this->assertNull($item['unit']);
    $this->assertArrayHasKey('unit', $item, 'the key is never omitted');
  }

  /**
   * Awarded to one of MY providers: the unit travels, with
   * field_nombre_vivienda as the name. I am going to that house.
   */
  public function testTheUnitTravelsWhenTheJobIsMine() {
    $result = $this->detail([
      $this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED),
    ], [
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::PROVIDER_A),
      ],
    ]);

    $this->assertSame(['id' => 55, 'name' => 'A-301'], $this->item($result)['unit']);
  }

  /**
   * Awarded to a RIVAL — even one I bid against: `unit` is null.
   */
  public function testTheUnitIsNullWhenTheJobWentToARival() {
    $result = $this->detail([
      $this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED),
    ], [
      'field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)],
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::FOREIGN_PROVIDER),
      ],
    ]);

    $this->assertSame(200, $result['status'], 'precondition: rule 2 lets me in');
    $this->assertNull($this->item($result)['unit']);
  }

  /**
   * THE RULE FAILS TOWARDS THE CLOSED SIDE. An award whose provider node was
   * deleted or unpublished answers assigned_provider: null — the comparison is
   * against the BUILT key and never against the raw column — and therefore
   * unit: null, even though the raw column still names one of my providers and
   * is what let me in through rule 2b.
   */
  public function testABrokenAwardClosesTheUnit() {
    $result = $this->detail([
      $this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED, [
        // The joined pair is NULL: the provider node is gone.
        'assigned_provider_id'   => NULL,
        'assigned_provider_name' => NULL,
      ]),
    ], [
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::PROVIDER_A),
      ],
    ]);

    $item = $this->item($result);

    $this->assertSame(200, $result['status'], 'precondition: rule 2b reads the RAW column and lets me in');
    $this->assertNull($item['assigned_provider']);
    $this->assertNull($item['unit']);
  }

  /**
   * The account operates A and B; a job awarded to B paints the unit, and it
   * does not matter which of the two got me in.
   */
  public function testTheUnitTravelsForEitherOfMyProviders() {
    $result = $this->detail([
      $this->awarded(self::NID, self::PROVIDER_B, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED),
    ], [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
        $this->link(self::PROVIDER_A),
        $this->link(self::PROVIDER_B),
      ],
      'field_data_field_categories' => [
        $this->category(self::PROVIDER_A),
        $this->category(self::PROVIDER_B),
      ],
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::PROVIDER_B),
      ],
      'node' => [
        $this->providerNode(self::PROVIDER_A),
        $this->providerNode(self::PROVIDER_B),
      ],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(
      ['id' => 55, 'name' => 'A-301'],
      $this->item($result)['unit'],
      'and the unit travels, because B is mine too'
    );
  }

  /**
   * THE TEST THAT CLOSES RISK 4 OF SPEC 98: the same nid gives the SAME `unit`
   * on the board and on this detail. The provider no longer sees the flat in
   * "my jobs" and loses it when they tap.
   */
  public function testTheUnitIsTheSameOnTheBoardAndOnThisDetail() {
    $scenarios = [
      'open market' => [[$this->request()], []],
      'awarded to me' => [
        [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED)],
        ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]],
      ],
      'awarded to a rival, mine by rule 2' => [
        [$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED)],
        [
          'field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)],
          'field_data_field_assigned_provider' => [$this->assignment(self::NID, self::FOREIGN_PROVIDER)],
        ],
      ],
    ];

    foreach ($scenarios as $label => $scenario) {
      $this->authenticate();
      $this->seed($scenario[0], $scenario[1]);
      $listed = $this->dispatchListing()['json']['data']['service_requests'][0];

      myapi_test_static_reset();
      $detail = $this->item($this->dispatch());

      $this->assertSame($listed['unit'], $detail['unit'], $label);
    }
  }

  /**
   * And the difference risk 2 documents is real and deliberate: the GENERAL
   * detail still answers unit: null to the very same provider over the very
   * same request. This test is what keeps that divergence explicit instead of
   * accidental.
   */
  public function testTheGeneralDetailStillNullsTheUnitForTheSameReader() {
    $this->authenticate();
    $this->seed([
      $this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED),
    ], [
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::PROVIDER_A),
      ],
    ]);

    $mine = $this->item($this->dispatch());

    myapi_test_static_reset();
    $general = $this->dispatchGeneralDetail()['json']['data']['service_request'];

    $this->assertSame(['id' => 55, 'name' => 'A-301'], $mine['unit']);
    $this->assertNull($general['unit'], 'the resident route is untouched by SPEC 99');
  }

  /* -------------------------------------------------------------------------
   * my_offers and offers_count (decision 7).
   * ---------------------------------------------------------------------- */

  /**
   * `my_offers` carries ONLY the offers whose field_provider is one of mine,
   * and `offers_count` carries the REAL total, competition included.
   */
  public function testMyOffersIsMineAndOffersCountIsTheRealTotal() {
    $result = $this->detail([$this->request()], [
      'field_data_field_request' => [
        $this->offer(self::NID, self::PROVIDER_A, 900),
        $this->offer(self::NID, self::FOREIGN_PROVIDER, 901),
        $this->offer(self::NID, self::FOREIGN_PROVIDER, 902),
        $this->offer(self::NID, self::FOREIGN_PROVIDER, 903),
      ],
    ]);

    $item = $this->item($result);

    $this->assertCount(1, $item['my_offers']);
    $this->assertSame(900, $item['my_offers'][0]['id']);
    $this->assertSame(self::PROVIDER_A, $item['my_offers'][0]['provider']['id']);
    $this->assertSame(4, $item['offers_count'], 'the total, and never count($my_offers)');
  }

  /**
   * With no offers of mine the response is still a 200 and `my_offers` is [] —
   * never null, and never the competition's.
   */
  public function testMyOffersIsAnEmptyArrayWhenIHaveNotBidYet() {
    $result = $this->detail([$this->request()], [
      'field_data_field_request' => [
        $this->offer(self::NID, self::FOREIGN_PROVIDER, 901),
      ],
    ]);

    $item = $this->item($result);

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $item['my_offers']);
    $this->assertSame(1, $item['offers_count']);
  }

  /**
   * `my_offers` IS TRIMMED BY TWO THINGS, NOT ONE: the request AND the reader's
   * providers. An offer of mine on a DIFFERENT request never leaks into this
   * one, and it does not raise this request's `offers_count` either.
   */
  public function testMyOffersCarriesOnlyTheOffersOfThisRequest() {
    $result = $this->detail([$this->request(), $this->request(300)], [
      'field_data_field_request' => [
        $this->offer(self::NID, self::PROVIDER_A, 900),
        // Mine, and of the same account — but on another request.
        $this->offer(300, self::PROVIDER_A, 905),
      ],
    ]);

    $item = $this->item($result);

    $this->assertSame([900], array_column($item['my_offers'], 'id'));
    $this->assertSame(1, $item['offers_count'], 'the count is this request\'s too');
  }

  /**
   * Each offer is {id, provider: {id, name, logo}, amount, message, status,
   * created} — the six keys of SPEC 89, in order, under the new name.
   */
  public function testEachOfMyOffersHasTheSixKeysOfSpec89() {
    $result = $this->detail([$this->request()], [
      'field_data_field_request' => [
        $this->offer(self::NID, self::PROVIDER_A, 900, ['amount' => '150.50']),
      ],
    ]);

    $offer = $this->item($result)['my_offers'][0];

    $this->assertSame(
      ['id', 'provider', 'amount', 'message', 'status', 'created'],
      array_keys($offer)
    );
    $this->assertSame(['id', 'name', 'logo'], array_keys($offer['provider']));
    $this->assertSame('sent', $offer['status']);
    // A NUMBER and not the stored string — the decimals are what make the
    // difference visible once the body has been through json_encode().
    $this->assertSame(150.5, $offer['amount']);
    $this->assertSame(
      format_date(self::CREATED + 900, 'custom', 'Y-m-d\TH:i:s'),
      $offer['created']
    );
  }

  /**
   * `assigned_provider` names the winner EVEN WHEN IT IS A RIVAL, unmasked
   * (decision 10 of SPEC 98, inherited here without change).
   */
  public function testTheWinningRivalIsNamed() {
    $result = $this->detail([
      $this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED),
    ], [
      'field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)],
      'field_data_field_assigned_provider' => [
        $this->assignment(self::NID, self::FOREIGN_PROVIDER),
      ],
    ]);

    $this->assertSame(
      ['id' => self::FOREIGN_PROVIDER, 'name' => 'Proveedor ' . self::FOREIGN_PROVIDER],
      $this->item($result)['assigned_provider']
    );
  }

  /* -------------------------------------------------------------------------
   * Non-regression and structure.
   * ---------------------------------------------------------------------- */

  /**
   * The GENERAL detail is untouched: for a provider reader it still answers
   * the key `offers` — not `my_offers` — and its own nineteen keys in its own
   * order.
   */
  public function testTheGeneralDetailKeepsItsOffersKeyAndItsOwnOrder() {
    $this->authenticate();
    $this->seed([$this->request()], [
      'field_data_field_request' => [$this->offer(self::NID, self::PROVIDER_A)],
    ]);

    $general = $this->dispatchGeneralDetail()['json']['data']['service_request'];

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
      'viewer',
      'requester',
      'condominium',
      'images',
      'attachment',
      'closed_at',
      'offers',
      'transactions',
    ], array_keys($general));

    $this->assertArrayNotHasKey('my_offers', $general);
    $this->assertNull($general['unit']);
  }

  /**
   * The provider LISTING is untouched: the same thirteen keys, in the same
   * order, over the same fixture.
   */
  public function testTheProviderListingKeepsItsThirteenKeys() {
    $this->authenticate();
    $this->seed([$this->request()]);

    $item = $this->dispatchListing()['json']['data']['service_requests'][0];

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
   * NO QUERY OF THIS SPEC IS TAGGED 'node_access', same as SPECS 88, 89 and
   * 98: the access decision is myapi_service_request_viewer(), in one place
   * and in plain sight, and a tag would add a second, invisible one that could
   * contradict it for a resident holding the 'proveedor' role.
   */
  public function testTheNewFunctionsCarryNoNodeAccessTag() {
    $bodies = $this->functionBodies();

    foreach ([
      'myapi_service_request_provider_item_dispatch',
      'myapi_service_request_provider_detail',
      'myapi_service_request_provider_build_detail',
    ] as $name) {
      $this->assertArrayHasKey($name, $bodies, $name . ' exists');
      $this->assertStringNotContainsString('addTag', $bodies[$name], $name);
    }
  }

  /**
   * The serialiser is PURE: no database, no node_load(), no $_GET. Every input
   * arrives resolved, which is what lets the orchestrator own the order.
   */
  public function testTheSerialiserIsPure() {
    $body = $this->functionBodies()['myapi_service_request_provider_build_detail'];

    foreach (['db_select', 'db_query', 'node_load', 'user_load', '$_GET'] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $body, $forbidden);
    }
  }

  /**
   * The thirteen are NOT restated here: the serialiser delegates to
   * myapi_service_request_provider_build_item(), which is the structural
   * reason the detail cannot drift from the listing about the same nid.
   */
  public function testTheSerialiserDelegatesTheThirteen() {
    $body = $this->functionBodies()['myapi_service_request_provider_build_detail'];

    $this->assertStringContainsString('myapi_service_request_provider_build_item', $body);
    $this->assertStringNotContainsString("'assigned_provider'", $body, 'the unit rule is not rewritten here');
  }

  /**
   * WHOEVER READS THIS DETAIL DOWNLOADS ITS BYTES, AND NOBODY ELSE. The file
   * route of SPEC 89 authorises with the very same
   * myapi_service_request_viewer() this endpoint does, so the two verdicts
   * cannot diverge — which is decision 10's whole argument for not creating a
   * sibling route.
   *
   * Both routes are run over the same fixture, and the status they answer must
   * MATCH, whichever it is.
   */
  public function testTheFileRouteAnswersWhateverThisDetailAnswers() {
    $scenarios = [
      'rule 3, the open market' => [[$this->request()], [], 200],
      'rule 2b, awarded to me'  => [
        [$this->awarded(self::NID, self::PROVIDER_A, MYAPI_SERVICES_REQUEST_STATUS_DIRECT)],
        ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::PROVIDER_A)]],
        200,
      ],
      'another category'        => [
        [$this->request(self::NID, [
          'fcat.field_category_tid' => (string) self::OTHER_CATEGORY,
          'category_id'             => (string) self::OTHER_CATEGORY,
        ])],
        [],
        403,
      ],
      'a foreign direct'        => [
        [$this->awarded(self::NID, self::FOREIGN_PROVIDER, MYAPI_SERVICES_REQUEST_STATUS_DIRECT)],
        ['field_data_field_assigned_provider' => [$this->assignment(self::NID, self::FOREIGN_PROVIDER)]],
        403,
      ],
    ];

    foreach ($scenarios as $label => $scenario) {
      list($requests, $tables, $expected) = $scenario;
      $tables['field_data_field_images'] = [[
        'entity_id'        => (string) self::NID,
        'entity_type'      => 'node',
        'deleted'          => '0',
        'delta'            => '0',
        'field_images_fid' => '91',
        'fid'              => '91',
        'filename'         => 'fuga.jpg',
        'nid'              => (string) self::NID,
        'type'             => MYAPI_SERVICES_REQUEST_TYPE,
      ]];

      $this->authenticate();
      $this->seed($requests, $tables);
      $detail = $this->dispatch();

      $this->authenticate();
      $this->seed($requests, $tables);
      myapi_test_file_seed([91 => (object) [
        'fid'      => 91,
        'uri'      => __FILE__,
        'filename' => 'fuga.jpg',
        'filemime' => 'image/jpeg',
        'filesize' => 1,
      ]]);
      $GLOBALS['myapi_test_file_transfers'] = [];
      $file = myapi_test_capture(function () {
        myapi_service_request_file_dispatch(self::NID, 91);
      });

      $file_status = myapi_test_file_transfers() ? 200 : $file['status'];

      $this->assertSame($expected, $detail['status'], $label . ' — detail');
      $this->assertSame($expected, $file_status, $label . ' — file');
    }
  }

  /**
   * THE ROUTE, as hook_menu() declares it. Drupal's router is not run in
   * tests/unit, so what is asserted is the DECLARATION: the callback, and
   * 'page arguments' => [4] — the wildcard is the FIFTH component, and a [3]
   * would hand the dispatcher the literal 'provider'.
   */
  public function testTheRouteIsDeclaredWithTheFifthComponentAsTheWildcard() {
    $module = file_get_contents(__DIR__ . '/../../myapi.module');

    $this->assertMatchesRegularExpression(
      '/\\$items\\[\'api\\/v1\\/service-requests\\/provider\\/%\'\\]\\s*=\\s*\\[\\s*'
      . '\'page callback\'\\s*=>\\s*\'myapi_service_request_provider_item_dispatch\',\\s*'
      . '\'page arguments\'\\s*=>\\s*\\[4\\],/',
      $module
    );
  }

  /**
   * AND NO SIBLING FILE ROUTE (decision 10). The images and the attachment
   * keep pointing at the SPEC 89 route, which authorises with the very same
   * access rule — two routes, one rule, no possible divergence.
   */
  public function testThereIsNoProviderFileRoute() {
    $module = file_get_contents(__DIR__ . '/../../myapi.module');

    $this->assertStringNotContainsString(
      'api/v1/service-requests/provider/%/files',
      $module
    );
    $this->assertStringContainsString(
      "\$items['api/v1/service-requests/%/files/%']",
      $module,
      'precondition: the SPEC 89 file route is the one that stays'
    );
  }

  /**
   * The resource file with every comment stripped, so a structural guard can
   * assert what the CODE says without tripping over the docblocks that explain
   * it. Same helper, same reason, as ServiceRequestDetailEndpointTest.
   */
  private function codeWithoutComments($path = '/../../resources/service_request.resource.inc') {
    $source = file_get_contents(__DIR__ . $path);
    $code = '';

    foreach (token_get_all($source) as $token) {
      if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
          continue;
        }
        $code .= $token[1];
      }
      else {
        $code .= $token;
      }
    }

    return $code;
  }

  /**
   * The resource file split into one entry per top-level function, comments
   * already stripped.
   *
   * @return array  Function name => its source, from its signature to the one
   *                before it.
   */
  private function functionBodies() {
    $code = $this->codeWithoutComments();
    $parts = preg_split(
      '/^function\s+([a-z0-9_]+)\s*\(/mi',
      $code,
      -1,
      PREG_SPLIT_DELIM_CAPTURE
    );

    $bodies = [];
    for ($i = 1; $i < count($parts); $i += 2) {
      $bodies[$parts[$i]] = $parts[$i + 1];
    }

    return $bodies;
  }

}
