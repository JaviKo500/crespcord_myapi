<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.service_offer.inc';
require_once __DIR__ . '/../../includes/myapi.service_transaction.inc';
require_once __DIR__ . '/../../includes/myapi.service_offer_query.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_detail.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../resources/service_offer.resource.inc';
require_once __DIR__ . '/../../myapi.module';

/**
 * Unit tests for PUT /api/v1/service-offers/{id}/accept (SPEC 106).
 *
 * The award is the first write of this module that touches four nodes and two
 * bundles in one pass — the winning offer, the losing ones, the request and the
 * transaction — and the order of those writes is a contract, not a style: it is
 * what makes myapi_service_transaction_sync_request_status() compare two equal
 * statuses and NOT save the request a second time.
 *
 * WHAT THIS FILE CAN PROVE AND WHAT IT CANNOT. node_save() here is a recorder
 * (see tests/unit/bootstrap.php): a green test says the code asked for the
 * right thing, never that Drupal persisted it. That the node_save() lands, that
 * hook_node_presave() titles the transaction and that hook_node_insert() runs
 * the status sync all stay with the HTTP client against the running site — see
 * step 6 of the spec and docs/service-offer.md.
 */
class ServiceOfferAcceptTest extends TestCase {

  const REQUEST_NID = 500;

  const OFFER_NID    = 901;
  const PROVIDER_NID = 41;
  const UID          = 314;
  const CREATED      = 1787000000;
  const TOKEN        = 'accept-test-token';

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_static_reset();
    myapi_test_write_reset();
    myapi_test_node_seed();
    myapi_test_db_seed();
  }

  /* -------------------------------------------------------------------------
   * The live-offer sweep, myapi_service_offer_reject_live() (SPECS 95, 106).
   * ---------------------------------------------------------------------- */

  /**
   * One row of the sweep's query, seeded flat: the stub records joins instead
   * of resolving them, so every column the query reads — whatever table it
   * really comes from — lives on the base fixture under the alias the query
   * gives it.
   *
   * @param mixed  $offer_nid    The offer's nid, which is what the query
   *                             projects and the loop then loads.
   * @param string $status       Its field_offer_status value.
   * @param mixed  $request_nid  The request it hangs on.
   */
  private function sweepRow($offer_nid, $status, $request_nid = self::REQUEST_NID) {
    return [
      'entity_type'              => 'node',
      'deleted'                  => 0,
      'field_request_target_id'  => $request_nid,
      'type'                     => MYAPI_SERVICES_OFFER_TYPE,
      'status'                   => 1,
      'field_offer_status_value' => $status,
      'nid'                      => $offer_nid,
    ];
  }

  /**
   * Seeds the query's rows and a loadable node per offer, which is what the
   * loop needs: a nid the query answers but node_load() does not is skipped,
   * and that is a case of its own below.
   */
  private function seedOffers(array $rows) {
    myapi_test_db_seed(['field_data_field_request' => $rows]);

    $nodes = [];
    foreach ($rows as $row) {
      $nodes[$row['nid']] = (object) [
        'nid'                => $row['nid'],
        'type'               => MYAPI_SERVICES_OFFER_TYPE,
        'field_offer_status' => [LANGUAGE_NONE => [0 => ['value' => $row['field_offer_status_value']]]],
      ];
    }
    myapi_test_node_seed($nodes);
  }

  /**
   * The status every saved node ended up carrying, keyed by nid — what the
   * sweep actually wrote, read off the recorder.
   */
  private function savedStatuses() {
    $saved = [];
    foreach (myapi_test_node_saves() as $node) {
      $saved[(int) $node->nid] = $node->field_offer_status[LANGUAGE_NONE][0]['value'];
    }

    return $saved;
  }

  /**
   * NO $except_nid IS THE CANCELLATION, and it behaves exactly as the function
   * it replaces did (SPEC 95): every live offer goes to 'rejected'.
   *
   * This is the case that makes the extraction safe — myapi_service_request_cancel()
   * calls it with one argument, and one argument must mean what it always meant.
   */
  public function testWithoutAnExceptionEveryLiveOfferIsRejected() {
    $this->seedOffers([
      $this->sweepRow(901, 'sent'),
      $this->sweepRow(902, 'sent'),
      $this->sweepRow(903, 'selected'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID);

    $this->assertSame(3, $rejected);
    $this->assertSame([901 => 'rejected', 902 => 'rejected', 903 => 'rejected'], $this->savedStatuses());
  }

  /**
   * THE AWARD'S CASE: the spared offer survives and every other live one does
   * not.
   *
   * Sparing the winner is obligatory and not an optimisation. The award writes
   * 'selected' onto it first, and 'selected' is one of the two statuses this
   * sweep considers live — without the exception the very next line would
   * rewrite the winner to 'rejected' and the request would end up assigned to a
   * rejected offer.
   */
  public function testTheExceptedOfferSurvivesAndTheOthersDoNot() {
    $this->seedOffers([
      $this->sweepRow(901, 'selected'),
      $this->sweepRow(902, 'sent'),
      $this->sweepRow(903, 'sent'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID, 901);

    $this->assertSame(2, $rejected);
    $this->assertSame([902 => 'rejected', 903 => 'rejected'], $this->savedStatuses());
    $this->assertArrayNotHasKey(901, $this->savedStatuses(), 'the winner is not even saved');
  }

  /**
   * AN $except_nid THAT IS NOT AN OFFER OF THIS REQUEST SPARES NOTHING, and it
   * is not an error either: it simply never appears in the list the query
   * answers, so the sweep behaves as if no exception had been passed.
   */
  public function testAnExceptionFromAnotherRequestChangesNothing() {
    $this->seedOffers([
      $this->sweepRow(901, 'sent'),
      $this->sweepRow(902, 'sent'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID, 777);

    $this->assertSame(2, $rejected);
    $this->assertSame([901 => 'rejected', 902 => 'rejected'], $this->savedStatuses());
  }

  /**
   * The exception is compared as an integer, so a nid arriving as a string —
   * which is how it comes off a query row — still spares the right offer.
   */
  public function testTheExceptionMatchesAcrossTypes() {
    $this->seedOffers([
      $this->sweepRow('901', 'selected'),
      $this->sweepRow('902', 'sent'),
    ]);

    $rejected = myapi_service_offer_reject_live((string) self::REQUEST_NID, '901');

    $this->assertSame(1, $rejected);
    $this->assertSame([902 => 'rejected'], $this->savedStatuses());
  }

  /**
   * 'withdrawn' and 'rejected' ARE NEVER TOUCHED, with or without an exception.
   * The first is the provider's own retreat and overwriting it would erase who
   * walked away by themselves; the second is already terminal. The query is
   * what enforces it, through the IN over the two live constants.
   */
  public function testTerminalStatusesAreLeftAlone() {
    $this->seedOffers([
      $this->sweepRow(901, 'sent'),
      $this->sweepRow(902, 'withdrawn'),
      $this->sweepRow(903, 'rejected'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID, 901);

    $this->assertSame(0, $rejected);
    $this->assertSame([], $this->savedStatuses());
  }

  /**
   * Nothing live is 0 and no save, not an error — the normal answer for a
   * request whose offers were all withdrawn.
   */
  public function testNothingLiveAnswersZero() {
    $this->seedOffers([]);

    $this->assertSame(0, myapi_service_offer_reject_live(self::REQUEST_NID));
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * A nid the query answers but node_load() no longer resolves is SKIPPED and
   * not counted: a row pointing at a deleted node has nothing to rewrite, and
   * failing here would abort a sweep that is already halfway done.
   */
  public function testAnUnloadableOfferIsSkippedAndNotCounted() {
    myapi_test_db_seed(['field_data_field_request' => [
      $this->sweepRow(901, 'sent'),
      $this->sweepRow(902, 'sent'),
    ]]);
    // Only one of the two loads.
    myapi_test_node_seed([901 => (object) [
      'nid'                => 901,
      'type'               => MYAPI_SERVICES_OFFER_TYPE,
      'field_offer_status' => [LANGUAGE_NONE => [0 => ['value' => 'sent']]],
    ]]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID);

    $this->assertSame(1, $rejected);
    $this->assertSame([901 => 'rejected'], $this->savedStatuses());
  }



  /* -------------------------------------------------------------------------
   * Fixtures and the harness for the endpoint.
   * ---------------------------------------------------------------------- */

  /**
   * One offer row, flat, as every join of myapi_service_offer_detail_row()
   * delivers it. The published flag of the node travels as `status` and the
   * offer's own status under its QUALIFIED source, because a flat row cannot
   * hold both and the fixture resolves the qualified name first — the same
   * shape ServiceOfferWithdrawTest uses.
   */
  private function offerRow(array $overrides = []) {
    return $overrides + [
      'nid'                                  => (string) self::OFFER_NID,
      'type'                                 => MYAPI_SERVICES_OFFER_TYPE,
      'status'                               => '1',
      'created'                              => (string) self::CREATED,
      'fq.field_request_target_id'           => (string) self::REQUEST_NID,
      'nr.nid'                               => (string) self::REQUEST_NID,
      'fp.field_provider_target_id'          => (string) self::PROVIDER_NID,
      'np.nid'                               => (string) self::PROVIDER_NID,
      'np.title'                             => 'Plomería Torres',
      'foa.field_offer_amount_value'         => '150.50',
      'fost.field_offer_status_value'        => 'sent',
      'fat.field_offer_amount_type_value'    => 'fixed',
      'fvu.field_offer_valid_until_value'    => NULL,
    ];
  }

  /** The request row, flat, as myapi_service_request_detail_row() reads it. */
  private function requestRow(array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) self::REQUEST_NID,
      'type'                           => MYAPI_SERVICES_REQUEST_TYPE,
      'status'                         => '1',
      'title'                          => 'Fuga en el calentador',
      'created'                        => (string) self::CREATED,
      'fr.field_requester_target_id'   => (string) self::UID,
      'requester_uid'                  => (string) self::UID,
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      'fcat.field_category_tid'        => '9',
      'category_id'                    => '9',
      'category_code'                  => 'plumbing',
      'category_name'                  => 'Plomería',
    ];
  }

  /** The provider node, with the licence myapi_service_offer_provider_row() reads. */
  private function providerRow(array $overrides = []) {
    return $overrides + [
      'nid'                              => (string) self::PROVIDER_NID,
      'type'                             => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'                           => '1',
      'title'                            => 'Plomería Torres',
      'fle.field_license_expiry_value'   => (string) (REQUEST_TIME + 86400),
    ];
  }

  private function tokenRow() {
    return [
      'id'                => '1',
      'uid'               => (string) self::UID,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * The loaded nodes the four writes touch: the request and the offer. Only
   * what the endpoint reads or writes is shaped — everything else is left out
   * precisely because the endpoint must not invent it.
   */
  private function seedNodes(array $extra_offers = []) {
    $nodes = [
      self::REQUEST_NID => (object) [
        'nid'    => self::REQUEST_NID,
        'type'   => MYAPI_SERVICES_REQUEST_TYPE,
        'uid'    => 7,
        'status' => 1,
        'title'  => 'Fuga en el calentador',
        'created' => self::CREATED,
        'field_request_status' => [LANGUAGE_NONE => [['value' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED]]],
        'field_requester'      => [LANGUAGE_NONE => [['target_id' => self::UID]]],
      ],
      self::OFFER_NID => (object) [
        'nid'    => self::OFFER_NID,
        'type'   => MYAPI_SERVICES_OFFER_TYPE,
        'uid'    => 33,
        'status' => 1,
        'title'  => 'Oferta de Plomería Torres — solicitud #128',
        'created' => self::CREATED,
        'field_offer_status' => [LANGUAGE_NONE => [['value' => 'sent']]],
      ],
    ];

    foreach ($extra_offers as $nid => $status) {
      $nodes[$nid] = (object) [
        'nid'    => $nid,
        'type'   => MYAPI_SERVICES_OFFER_TYPE,
        'uid'    => 33,
        'status' => 1,
        'field_offer_status' => [LANGUAGE_NONE => [['value' => $status]]],
      ];
    }

    myapi_test_node_seed($nodes);
  }

  /**
   * Seeds a whole scenario in one call: every myapi_test_db_seed() replaces the
   * entire fixture, so nothing can be added afterwards.
   *
   * $options: 'drop_offer' removes the offer row entirely (a 404 case),
   * 'provider' overrides the provider node, and 'offers' is a map of
   * nid => status for the OTHER offers of the same request, which is what the
   * sweep and offers_count read.
   */
  private function seed(array $offer = [], array $request = [], array $options = []) {
    $options += ['drop_offer' => FALSE, 'provider' => [], 'offers' => []];

    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid'    => self::UID,
      'name'   => 'residente' . self::UID,
      'status' => 1,
      'roles'  => ['authenticated user'],
    ];

    $nodes = [$this->requestRow($request), $this->providerRow($options['provider'])];
    if (!$options['drop_offer']) {
      $nodes[] = $this->offerRow($offer);
    }

    // The offers of this request, as the three queries over
    // field_data_field_request read them: the sweep, offers_count and the
    // detail's listing all hang off this one fixture.
    $offer_status = isset($offer['fost.field_offer_status_value'])
      ? $offer['fost.field_offer_status_value']
      : 'sent';
    $links = [];
    if (!$options['drop_offer']) {
      $links[] = $this->offerLink(self::OFFER_NID, $offer_status);
    }
    foreach ($options['offers'] as $nid => $status) {
      $links[] = $this->offerLink($nid, $status);
    }

    myapi_test_db_seed([
      'my_api_tokens'           => [$this->tokenRow()],
      'node'                    => $nodes,
      'field_data_field_request' => $links,
      'users'                   => [['uid' => (string) self::UID, 'name' => 'residente314']],
    ]);
    myapi_test_static_reset();
    $this->seedNodes($options['offers']);
  }

  /**
   * One row of field_data_field_request, flat, carrying every column the three
   * queries that read that table project or filter on.
   */
  private function offerLink($offer_nid, $status) {
    return [
      'entity_type'                       => 'node',
      'deleted'                           => '0',
      'field_request_target_id'           => (string) self::REQUEST_NID,
      'type'                              => MYAPI_SERVICES_OFFER_TYPE,
      'status'                            => '1',
      'field_offer_status_value'          => $status,
      'nid'                               => (string) $offer_nid,
      'created'                           => (string) self::CREATED,
      'provider_id'                       => (string) self::PROVIDER_NID,
      'provider_name'                     => 'Plomería Torres',
    ];
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  private function dispatch($nid = self::OFFER_NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_offer_accept_dispatch((string) $nid);
    });
  }

  private function assertError(array $result, $status, $error_code, $label = '') {
    $this->assertSame($status, $result['status'], $label);
    $this->assertFalse($result['json']['success'], $label);
    $this->assertSame($error_code, $result['json']['error_code'], $label);
  }

  /** The nodes the call saved, keyed by nid. */
  private function saves() {
    $saves = [];
    foreach (myapi_test_node_saves() as $node) {
      $saves[(int) $node->nid] = $node;
    }

    return $saves;
  }

  /* -------------------------------------------------------------------------
   * The route and the dispatcher.
   * ---------------------------------------------------------------------- */

  /**
   * Drupal's router is not run in tests/unit, so what is asserted is the
   * DECLARATION — the same criterion, and the same helper, as SPEC 103's route
   * tests. That Drupal 7 prefers a literal over a wildcard is core behaviour
   * and a MANUAL step of the spec, verified with `drush cc all` in between.
   */
  private function moduleSource() {
    return file_get_contents(__DIR__ . '/../../myapi.module');
  }

  /**
   * THE AWARD'S ROUTE TAKES THE FOURTH COMPONENT. 'page arguments' => [3]: the
   * wildcard is api / v1 / service-offers / % / accept and the literal is the
   * fifth — a [4] would hand the dispatcher the string 'accept'.
   */
  public function testTheRouteIsDeclaredWithTheFourthComponent() {
    $this->assertMatchesRegularExpression(
      '/\$items\[\'api\/v1\/service-offers\/%\/accept\'\]\s*=\s*\[\s*'
      . '\'page callback\'\s*=>\s*\'myapi_service_offer_accept_dispatch\',\s*'
      . '\'page arguments\'\s*=>\s*\[3\],\s*'
      . '\'access callback\'\s*=>\s*TRUE,\s*'
      . '\'type\'\s*=>\s*MENU_CALLBACK,\s*'
      . '\'file\'\s*=>\s*\'resources\/service_offer\.resource\.inc\',/',
      $this->moduleSource()
    );
  }

  /**
   * THE FOUR ROUTES OF THE PREFIX COEXIST, and each still points where it did.
   * '/service-offers/901/accept' carries the id in [3], so it can never be
   * '/service-offers/provider/%' — that one has a LITERAL there — and 'accept'
   * in [4], so it can never be the withdrawal. The symptom of an error here is
   * not a 404: it is one of the four starting to answer another one's job.
   */
  public function testTheFourRoutesOfThePrefixCoexist() {
    $module = $this->moduleSource();

    $routes = [
      "\$items['api/v1/service-offers/provider']"    => 'myapi_service_offer_provider_dispatch',
      "\$items['api/v1/service-offers/provider/%']"  => 'myapi_service_offer_provider_item_dispatch',
      "\$items['api/v1/service-offers/%']"           => 'myapi_service_offer_item_dispatch',
      "\$items['api/v1/service-offers/%/withdraw']"  => 'myapi_service_offer_withdraw_dispatch',
      "\$items['api/v1/service-offers/%/accept']"    => 'myapi_service_offer_accept_dispatch',
    ];

    foreach ($routes as $route => $callback) {
      $this->assertStringContainsString($route, $module, $route . ' is declared');
      $this->assertStringContainsString("'page callback'    => '" . $callback . "'", $module);
    }
  }

  /**
   * Every method but PUT answers 405 — BEFORE the token and before a single
   * query, the criterion every dispatcher of this module follows: the method is
   * wrong whoever is asking.
   */
  public function testEveryMethodOtherThanPutIs405BeforeTheToken() {
    foreach (['GET', 'POST', 'PATCH', 'DELETE', 'HEAD'] as $method) {
      $this->seed();
      $this->authenticate();
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->dispatch();

      $this->assertError($result, 405, 'method_not_allowed', $method);
      $this->assertSame([], myapi_test_db_queries(), $method . ' costs no query');
      $this->assertSame([], myapi_test_node_saves(), $method . ' writes nothing');
    }
  }

  /**
   * And with no token at all it is STILL 405 and never 401: the method is
   * checked first.
   */
  public function testAWrongMethodWithoutATokenIsStill405() {
    $this->seed();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->assertError($this->dispatch(), 405, 'method_not_allowed');
  }

  /* -------------------------------------------------------------------------
   * The five conditions the resource owns.
   * ---------------------------------------------------------------------- */

  /**
   * CONDITION 1 — a wildcard that is not a positive integer is 404 and COSTS NO
   * QUERY, not even the token's. The answer is about the SHAPE of the URL and
   * not about what exists.
   *
   * @dataProvider malformedIds
   */
  public function testAMalformedIdIs404BeforeAnyQuery($nid) {
    $this->seed();
    $this->authenticate();

    $result = myapi_test_capture(function () use ($nid) {
      myapi_service_offer_accept_dispatch($nid);
    });

    $this->assertError($result, 404, 'not_found');
    $this->assertSame([], myapi_test_db_queries(), 'no query was run');
    $this->assertSame([], myapi_test_node_saves());
  }

  public function malformedIds() {
    return [
      'letters'  => ['abc'],
      'zero'     => ['0'],
      'negative' => ['-3'],
      'list'     => ['1,2'],
      'decimal'  => ['1.5'],
      'empty'    => [''],
      'null'     => [NULL],
    ];
  }

  /**
   * CONDITION 2 — no Authorization header is 401 missing_authorization, and a
   * token that does not resolve is 401 invalid_token. Neither writes anything.
   */
  public function testAuthenticationIsRequired() {
    $this->seed();
    $this->assertError($this->dispatch(), 401, 'missing_authorization');
    $this->assertSame([], myapi_test_node_saves());

    $this->seed();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';
    $this->assertError($this->dispatch(), 401, 'invalid_token');
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * CONDITION 3 — an offer that is not servable is 404, and the four ways in
   * are indistinguishable on purpose: missing, unpublished, another bundle, or
   * its request unpublished.
   */
  public function testAnUnservableOfferIs404() {
    $cases = [
      'missing'            => ['offer' => NULL],
      'unpublished'        => ['offer' => ['status' => '0']],
      'another bundle'     => ['offer' => ['type' => MYAPI_SERVICES_REQUEST_TYPE]],
      'request unpublished' => ['request' => ['status' => '0']],
    ];

    foreach ($cases as $label => $case) {
      $this->seed(
        isset($case['offer']) ? $case['offer'] : [],
        isset($case['request']) ? $case['request'] : [],
        ['drop_offer' => array_key_exists('offer', $case) && $case['offer'] === NULL]
      );
      $this->authenticate();

      $this->assertError($this->dispatch(), 404, 'not_found', $label);
      $this->assertSame([], myapi_test_node_saves(), $label . ' writes nothing');
    }
  }

  /**
   * CONDITION 5 — ONLY THE field_requester AWARDS. Not node.uid, not the rest
   * of the unit, and not the provider who owns the offer. A request with no
   * requester at all is a 403 too: nobody owns it, so nobody may award it.
   *
   * AND NOTHING WAS WRITTEN: the four writes all sit after this line.
   */
  public function testOnlyTheRequesterMayAward() {
    $cases = [
      'another resident' => ['fr.field_requester_target_id' => '999', 'requester_uid' => '999'],
      'no requester'     => ['fr.field_requester_target_id' => NULL, 'requester_uid' => NULL],
      'empty requester'  => ['fr.field_requester_target_id' => '', 'requester_uid' => ''],
    ];

    foreach ($cases as $label => $overrides) {
      $this->seed([], $overrides);
      $this->authenticate();

      $this->assertError($this->dispatch(), 403, 'service_request_forbidden', $label);
      $this->assertSame([], myapi_test_node_saves(), $label . ' writes nothing');
    }
  }

  /**
   * THE GATE'S CODES REACH HTTP WITH THE RIGHT STATUS, which is the ONE thing
   * the resource adds to the pure gate: whether the offer may still be awarded
   * is a 409, whether its provider may still work is a 403.
   *
   * AND NONE OF THE FOUR WRITES HAPPENED — the gate sits before all of them.
   */
  public function testTheGateCodesMapToTheRightHttpStatus() {
    $cases = [
      'offer rejected'      => [409, 'service_offer_not_acceptable', ['fost.field_offer_status_value' => 'rejected'], [], []],
      'offer selected'      => [409, 'service_offer_not_acceptable', ['fost.field_offer_status_value' => 'selected'], [], []],
      'offer withdrawn'     => [409, 'service_offer_not_acceptable', ['fost.field_offer_status_value' => 'withdrawn'], [], []],
      'request open'        => [409, 'service_request_not_assignable', [], ['frs.field_request_status_value' => 'open'], []],
      'request assigned'    => [409, 'service_request_not_assignable', [], ['frs.field_request_status_value' => 'assigned'], []],
      'request cancelled'   => [409, 'service_request_not_assignable', [], ['frs.field_request_status_value' => 'cancelled'], []],
      'request corrupt'     => [409, 'service_request_not_assignable', [], ['frs.field_request_status_value' => 'nonsense'], []],
      'quote expired'       => [409, 'service_offer_expired', ['fvu.field_offer_valid_until_value' => (string) (REQUEST_TIME - 1)], [], []],
      'provider suspended'  => [403, 'service_offer_provider_not_active', [], [], ['status' => '0']],
      'licence lapsed'      => [403, 'service_offer_provider_not_active', [], [], ['fle.field_license_expiry_value' => (string) (REQUEST_TIME - 1)]],
      'no licence row'      => [403, 'service_offer_provider_not_active', [], [], ['fle.field_license_expiry_value' => NULL]],
    ];

    foreach ($cases as $label => $case) {
      list($status, $code, $offer, $request, $provider) = $case;

      $this->seed($offer, $request, ['provider' => $provider]);
      $this->authenticate();

      $this->assertError($this->dispatch(), $status, $code, $label);
      $this->assertSame([], myapi_test_node_saves(), $label . ' writes nothing');
    }
  }

  /**
   * THE ORDER SURVIVES THE TRIP THROUGH HTTP: a rejected offer whose provider
   * is also suspended answers 409 service_offer_not_acceptable and NOT the 403.
   * The gate asserts this over rows; this asserts the resource did not reorder
   * it on the way out.
   */
  public function testARejectedOfferFromASuspendedProviderIs409AndNot403() {
    $this->seed(
      ['fost.field_offer_status_value' => 'rejected'],
      [],
      ['provider' => ['status' => '0']]
    );
    $this->authenticate();

    $this->assertError($this->dispatch(), 409, 'service_offer_not_acceptable');
  }

  /**
   * THE BODY IS NOT READ AT ALL — asserted STRUCTURALLY and not through a
   * fixture, which is the only honest way: myapi_request_body() reads
   * php://input, so no unit test can hand it one. What CAN be proved is the
   * stronger claim — the endpoint never calls it. Nothing is parsed, so a body
   * with keys and a body that is malformed JSON are the same thing: ignored,
   * and neither can fail.
   *
   * Same shape, and same reason, as the withdrawal's guard of SPEC 105.
   */
  public function testTheEndpointNeverReadsTheBody() {
    $source = $this->acceptSource();

    foreach (['myapi_request_body', 'json_decode', '$_POST', '$_GET', 'php://input'] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $source, $forbidden);
    }
  }

  /**
   * THE FOUR WRITES ARE IN THE ORDER THE SPEC DEFENDS, and the order is the
   * contract: the REQUEST first, so that
   * myapi_service_transaction_sync_request_status() — which fires on the
   * hook_node_insert() of the transaction — compares two equal statuses and
   * does NOT save the request a second time. That property cannot be observed
   * from a unit test, because the hook is Drupal's dispatch; what can be
   * observed is the order that produces it.
   */
  public function testTheFourWritesAreInOrder() {
    $source = $this->acceptSource();

    $positions = [
      'the request'     => strpos($source, 'node_save($request_node)'),
      'the transaction' => strpos($source, 'myapi_service_transaction_record('),
      'the winner'      => strpos($source, 'node_save($offer)'),
      'the losers'      => strpos($source, 'myapi_service_offer_reject_live('),
    ];

    foreach ($positions as $label => $position) {
      $this->assertNotFalse($position, $label . ' is written');
    }

    $this->assertSame(array_keys($positions), array_keys($positions));
    $this->assertTrue($positions['the request'] < $positions['the transaction'], 'the request before the transaction');
    $this->assertTrue($positions['the transaction'] < $positions['the winner'], 'the transaction before the winner');
    $this->assertTrue($positions['the winner'] < $positions['the losers'], 'the winner before the losers');
  }

  /**
   * THE WINNER IS SPARED BY NID AND NOT BY STATUS. By the time the sweep runs
   * the winner already says 'selected', which myapi_service_offer_reject_live()
   * considers live — so without the second argument it would reject itself and
   * the request would end up assigned to a rejected offer.
   */
  public function testTheSweepIsCalledWithTheWinnersNid() {
    $this->assertStringContainsString(
      'myapi_service_offer_reject_live($request_nid, $nid)',
      $this->acceptSource()
    );
  }

  /**
   * field_assigned_provider IS WRITTEN FROM provider_raw AND NEVER FROM
   * provider_id — decision 15. The joined column carries status = 1 inside it,
   * so the day condition 9 is relaxed, the record of who a job was awarded to
   * must not depend on whether their listing is still published.
   */
  public function testTheAssignedProviderComesFromTheRawColumn() {
    $source = $this->acceptSource();

    $this->assertStringContainsString(
      "\$request_node->field_assigned_provider[LANGUAGE_NONE][0]['target_id'] = (int) \$row->provider_raw;",
      $source
    );
    $this->assertStringNotContainsString('$row->provider_id', $source, 'never the joined column');
  }

  /**
   * The body of myapi_service_offer_accept(), comments stripped and bounded at
   * the next function: this file grows, and a scan that ran to the end of it
   * would read the neighbours' code as if it were this one's.
   */
  private function acceptSource() {
    $code = file_get_contents(__DIR__ . '/../../resources/service_offer.resource.inc');
    $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $code);

    $start = strpos($code, 'function myapi_service_offer_accept($nid)');
    $this->assertNotFalse($start, 'the endpoint exists');

    $end = strpos($code, "\nfunction ", $start + 1);

    return $end === FALSE ? substr($code, $start) : substr($code, $start, $end - $start);
  }


  /* -------------------------------------------------------------------------
   * The four writes, and the shape of the 200.
   *
   * WHAT THIS LAYER CAN PROVE AND WHAT IT CANNOT. node_save() is a RECORDER
   * (see tests/unit/bootstrap.php): it does not persist, so the response —
   * which is rebuilt from the database AFTER the writes — comes back reading
   * the fixture and not what was just written. That is exactly why the
   * assertions below split in two: WHAT WAS HANDED TO node_save(), which is the
   * whole contract of this endpoint and is asserted here in full; and THAT THE
   * RESPONSE IS A REREAD, which is asserted by its shape. That the reread
   * answers 'assigned', that hook_node_presave() titles the transaction and
   * that hook_node_insert() runs the status sync are the HTTP checks of step 6
   * of the spec, against a running site.
   * ---------------------------------------------------------------------- */

  /**
   * WRITE A — THE REQUEST, and it is the FIRST node saved. Its status, its
   * assigned offer and its assigned provider, in one node_save().
   */
  public function testTheRequestIsWrittenFirstWithItsThreeFields() {
    $this->seed();
    $this->authenticate();

    $this->dispatch();

    $saves = myapi_test_node_saves();
    $this->assertNotEmpty($saves);
    $request = $saves[0];

    $this->assertSame(MYAPI_SERVICES_REQUEST_TYPE, $request->type, 'the request is saved first');
    $this->assertSame(self::REQUEST_NID, (int) $request->nid);
    $this->assertSame(
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      $request->field_request_status[LANGUAGE_NONE][0]['value']
    );
    $this->assertSame(self::OFFER_NID, $request->field_assigned_offer[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::PROVIDER_NID, $request->field_assigned_provider[LANGUAGE_NONE][0]['target_id']);
  }

  /**
   * THE REQUEST IS SAVED EXACTLY ONCE. Saving it twice is the symptom of the
   * order being broken: myapi_service_transaction_sync_request_status() rewrites
   * the request's status from the transaction's, and it only returns without
   * saving because the endpoint already wrote the same value first.
   */
  public function testTheRequestIsSavedOnlyOnce() {
    $this->seed();
    $this->authenticate();

    $this->dispatch();

    $requests = array_filter(myapi_test_node_saves(), function ($node) {
      return $node->type === MYAPI_SERVICES_REQUEST_TYPE;
    });

    $this->assertCount(1, $requests);
  }

  /**
   * THE REQUEST KEEPS EVERYTHING ELSE. node.uid, node.created, node.title and
   * the published flag are untouched: awarding neither unpublishes nor
   * rewrites history.
   */
  public function testTheRequestKeepsEverythingElse() {
    $this->seed();
    $this->authenticate();

    $this->dispatch();
    $request = $this->saves()[self::REQUEST_NID];

    $this->assertSame(7, $request->uid, 'the technical author is not rewritten');
    $this->assertSame(self::CREATED, $request->created);
    $this->assertSame('Fuga en el calentador', $request->title);
    $this->assertSame(1, $request->status, 'the request stays published');
    $this->assertSame(self::UID, $request->field_requester[LANGUAGE_NONE][0]['target_id']);
  }

  /**
   * WRITE B — THE TRANSACTION, second, with the four fields of SPEC 77 and
   * node.uid = the resident who awarded. Its comment is the award text, built
   * from the provider's name and the offer's amount, both of which came off
   * rows the gate had already paid for.
   */
  public function testTheTransactionIsWrittenSecond() {
    $this->seed();
    $this->authenticate();

    $this->dispatch();

    $saves = myapi_test_node_saves();
    $transaction = $saves[1];

    $this->assertSame(MYAPI_SERVICES_TRANSACTION_TYPE, $transaction->type);
    $this->assertSame(self::REQUEST_NID, $transaction->field_request[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      $transaction->field_request_status[LANGUAGE_NONE][0]['value']
    );
    $this->assertSame(self::UID, $transaction->uid, 'the resident who awarded');
    $this->assertSame(
      'Oferta adjudicada a Plomería Torres por 150,50.',
      $transaction->field_comment[LANGUAGE_NONE][0]['value']
    );
    $this->assertMatchesRegularExpression(
      '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/',
      $transaction->field_status_date[LANGUAGE_NONE][0]['value']
    );
    $this->assertFalse(
      property_exists($transaction, 'title'),
      'the title belongs to hook_node_presave()'
    );
  }

  /**
   * An 'on_site_quote' offer records the provider and no figure — the endpoint
   * hands the amount type through and the builder decides, which is what keeps
   * the rule in one place.
   */
  public function testAnOnSiteQuoteRecordsNoAmount() {
    $this->seed([
      'fat.field_offer_amount_type_value' => 'on_site_quote',
      'foa.field_offer_amount_value'      => NULL,
    ]);
    $this->authenticate();

    $this->dispatch();

    $this->assertSame(
      'Oferta adjudicada a Plomería Torres.',
      myapi_test_node_saves()[1]->field_comment[LANGUAGE_NONE][0]['value']
    );
  }

  /**
   * WRITE C — THE WINNER, third, and it is ONE field: 'selected'. Nothing else
   * of the offer moves.
   */
  public function testTheWinningOfferIsWrittenThird() {
    $this->seed();
    $this->authenticate();

    $this->dispatch();

    $offer = myapi_test_node_saves()[2];

    $this->assertSame(MYAPI_SERVICES_OFFER_TYPE, $offer->type);
    $this->assertSame(self::OFFER_NID, (int) $offer->nid);
    $this->assertSame('selected', $offer->field_offer_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame(33, $offer->uid, 'the provider who created it, not the resident');
    $this->assertSame(self::CREATED, $offer->created);
    $this->assertSame('Oferta de Plomería Torres — solicitud #128', $offer->title);
  }

  /**
   * WRITE D — THE LOSERS. Every other offer that was 'sent' becomes 'rejected',
   * AND THE WINNER DOES NOT, even though the sweep considers 'selected' live.
   * offers_rejected counts exactly the ones THIS call moved.
   */
  public function testTheLosingOffersAreRejectedAndTheWinnerIsNot() {
    $this->seed([], [], ['offers' => [902 => 'sent', 903 => 'sent']]);
    $this->authenticate();

    $result = $this->dispatch();
    $saves = $this->saves();

    $this->assertSame('selected', $saves[self::OFFER_NID]->field_offer_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame('rejected', $saves[902]->field_offer_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame('rejected', $saves[903]->field_offer_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame(2, $result['json']['data']['offers_rejected']);
  }

  /**
   * OFFERS THAT WERE ALREADY 'withdrawn' OR 'rejected' ARE NOT TOUCHED, and
   * they do not count either: the first is the provider's own retreat and
   * overwriting it would erase who walked away by themselves, the second is
   * already terminal.
   */
  public function testTerminalOffersAreNeitherTouchedNorCounted() {
    $this->seed([], [], ['offers' => [902 => 'withdrawn', 903 => 'rejected', 904 => 'sent']]);
    $this->authenticate();

    $result = $this->dispatch();
    $saves = $this->saves();

    $this->assertArrayNotHasKey(902, $saves, 'the withdrawn one is not saved');
    $this->assertArrayNotHasKey(903, $saves, 'the rejected one is not saved');
    $this->assertSame('rejected', $saves[904]->field_offer_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $result['json']['data']['offers_rejected']);
  }

  /**
   * THE FOUR WRITES, IN ORDER, OBSERVED AS SAVES: request, transaction, winner,
   * losers. The order is what makes the status sync of SPEC 94 find two equal
   * statuses and return without saving the request again.
   */
  public function testTheSavesHappenInTheDefendedOrder() {
    $this->seed([], [], ['offers' => [902 => 'sent']]);
    $this->authenticate();

    $this->dispatch();

    $order = array_map(function ($node) {
      return $node->type . ':' . (int) $node->nid;
    }, myapi_test_node_saves());

    $this->assertSame([
      MYAPI_SERVICES_REQUEST_TYPE . ':' . self::REQUEST_NID,
      MYAPI_SERVICES_TRANSACTION_TYPE . ':900',
      MYAPI_SERVICES_OFFER_TYPE . ':' . self::OFFER_NID,
      MYAPI_SERVICES_OFFER_TYPE . ':902',
    ], $order);
  }

  /**
   * THE 200 IS THE RESIDENT'S WHOLE DETAIL, with offers_rejected as a SIBLING
   * and not a twentieth key of it — so the object under 'service_request' stays
   * byte-identical to what GET /api/v1/service-requests/{id} answers and the
   * app can swap it in with no special case.
   *
   * The nineteen keys are asserted BY NAME AND IN ORDER: this response is the
   * detail's serialiser or it is nothing.
   */
  public function testTheResponseIsTheWholeDetailPlusASiblingCounter() {
    $this->seed();
    $this->authenticate();

    $result = $this->dispatch();

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame('Oferta adjudicada correctamente.', $result['json']['message']);

    $this->assertSame(
      ['service_request', 'offers_rejected'],
      array_keys($result['json']['data']),
      'offers_rejected is a sibling'
    );
    $this->assertIsInt($result['json']['data']['offers_rejected']);

    $this->assertSame([
      'id', 'title', 'description', 'status', 'category', 'unit', 'offers_count',
      'assigned_offer', 'assigned_provider', 'created', 'desired_start',
      'viewer', 'requester', 'condominium', 'images', 'attachment', 'closed_at',
      'offers', 'transactions',
    ], array_keys($result['json']['data']['service_request']));

    $this->assertSame('requester', $result['json']['data']['service_request']['viewer']);
  }

  /**
   * THE RESPONSE IS A REREAD AND NOT AN ECHO OF WHAT WAS JUST WRITTEN, which is
   * the whole of decision 10 — the answer cannot disagree with what a GET would
   * say because it IS what a GET answers. The proof at this layer is the
   * inverse of the obvious one: node_save() does not persist here, so the
   * status that comes back is the FIXTURE'S, not the 'assigned' the endpoint
   * wrote. An implementation that echoed its own writes would answer 'assigned'
   * and this assertion would fail.
   */
  public function testTheResponseIsRebuiltFromTheDatabase() {
    $this->seed();
    $this->authenticate();

    $result = $this->dispatch();

    $this->assertSame(
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      $result['json']['data']['service_request']['status'],
      'the fixture never moved, so a reread still says offered'
    );
    $this->assertSame(
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      $this->saves()[self::REQUEST_NID]->field_request_status[LANGUAGE_NONE][0]['value'],
      'while the write itself said assigned'
    );
  }

  /**
   * NOT IDEMPOTENT, ON PURPOSE (decision 11). A second PUT on the offer that
   * was just awarded answers 409 service_offer_not_acceptable — it says
   * 'selected' now — and writes NOTHING. A 200 would pretend the second call
   * had done something, and it would land a duplicate entry on a timeline that
   * is forever.
   */
  public function testASecondAwardOnTheSameOfferIs409AndWritesNothing() {
    $this->seed(['fost.field_offer_status_value' => 'selected'], [
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
    ]);
    $this->authenticate();

    $this->assertError($this->dispatch(), 409, 'service_offer_not_acceptable');
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * AND A PUT ON ANOTHER OFFER OF THE SAME, ALREADY AWARDED REQUEST NEVER
   * REASSIGNS. That one is 'rejected' by now, so condition 6 answers first —
   * which is why the 409 says service_offer_not_acceptable and not
   * service_request_not_assignable, even though the request would fail
   * condition 7 too.
   */
  public function testAwardingALoserOfAnAssignedRequestNeverReassigns() {
    $this->seed(['fost.field_offer_status_value' => 'rejected'], [
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
    ]);
    $this->authenticate();

    $this->assertError($this->dispatch(), 409, 'service_offer_not_acceptable');
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * offers_count IS THE REAL TOTAL AND NOT count($offers): awarding deletes no
   * offer, so the number is the same it was before the call.
   */
  public function testAwardingDoesNotChangeOffersCount() {
    $this->seed([], [], ['offers' => [902 => 'sent', 903 => 'withdrawn']]);
    $this->authenticate();

    $result = $this->dispatch();

    $this->assertSame(3, $result['json']['data']['service_request']['offers_count']);
  }

  /* -------------------------------------------------------------------------
   * The pure gate, myapi_service_offer_accept_gate() — conditions 6 to 9.
   * ---------------------------------------------------------------------- */

  const NOW = 1800000000;

  const NOT_ACCEPTABLE = 'service_offer_not_acceptable';
  const NOT_ASSIGNABLE = 'service_request_not_assignable';
  const EXPIRED        = 'service_offer_expired';
  const NOT_ACTIVE     = 'service_offer_provider_not_active';

  /**
   * A row of myapi_service_offer_detail_row(), trimmed to the two columns the
   * gate reads. Everything else on that row belongs to the response, not here.
   */
  private function offer(array $overrides = []) {
    return (object) ($overrides + [
      'status'      => MYAPI_SERVICES_OFFER_STATUS_SENT,
      'valid_until' => NULL,
    ]);
  }

  /**
   * A row of myapi_service_request_detail_row(), trimmed the same way:
   * requester_uid is condition 5 and belongs to the resource.
   */
  private function request(array $overrides = []) {
    return (object) ($overrides + ['status' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED]);
  }

  /**
   * A row of myapi_service_offer_provider_row(), trimmed to the licence pair.
   * `owned` and `category_ids` travel on the real row and are never read here.
   */
  private function provider(array $overrides = []) {
    return (object) ($overrides + [
      'status'         => 1,
      'license_expiry' => self::NOW + 86400,
    ]);
  }

  private function gate($row = NULL, $request_row = NULL, $provider_row = NULL) {
    return myapi_service_offer_accept_gate(
      $row === NULL ? $this->offer() : $row,
      $request_row === NULL ? $this->request() : $request_row,
      $provider_row === NULL ? $this->provider() : $provider_row,
      self::NOW
    );
  }

  /**
   * The happy path: a 'sent' offer, an 'offered' request, no expiry and an
   * active provider. NULL means the resource may write.
   */
  public function testAllFourConditionsPassing() {
    $this->assertNull($this->gate());
  }

  /**
   * CONDITION 6 — THE FOUR OFFER STATUSES. Only 'sent' is awardable: 'selected'
   * says it was already awarded, 'rejected' that it lost, 'withdrawn' that the
   * provider took it back.
   */
  public function testOnlyASentOfferIsAcceptable() {
    $this->assertNull($this->gate($this->offer(['status' => 'sent'])));

    foreach (['selected', 'rejected', 'withdrawn'] as $status) {
      $this->assertSame(self::NOT_ACCEPTABLE, $this->gate($this->offer(['status' => $status])), $status);
    }
  }

  /**
   * A corrupt, empty or absent offer status is NOT acceptable and never an
   * exception — the same fail-closed reading a row that did not load gets.
   */
  public function testACorruptOfferStatusFailsClosed() {
    $rows = [
      'empty'    => $this->offer(['status' => '']),
      'null'     => $this->offer(['status' => NULL]),
      'unknown'  => $this->offer(['status' => 'not_a_status']),
      'absent'   => (object) [],
      'no row'   => FALSE,
    ];

    foreach ($rows as $label => $row) {
      $this->assertSame(self::NOT_ACCEPTABLE, $this->gate($row), $label);
    }
  }

  /**
   * CONDITION 7 — THE GRAPH ANSWERS, AND ONLY 'offered' LEADS TO 'assigned'.
   * The list below is not this gate's: it is myapi_services_request_transitions()
   * read from the outside, which is the point of asking instead of transcribing.
   *
   * 'open' IS IN THE FAILING LIST ON PURPOSE. A request with offers still
   * sitting in 'open' — the hole SPEC 100 left, since nothing syncs
   * 'open' -> 'offered' from the back office — cannot be awarded from the app,
   * and SPEC 106 does not invent that edge (decision 3).
   */
  public function testOnlyAnOfferedRequestIsAssignable() {
    $this->assertNull($this->gate(NULL, $this->request(['status' => 'offered'])));

    foreach (['open', 'direct', 'assigned', 'closed', 'cancelled'] as $status) {
      $this->assertSame(
        self::NOT_ASSIGNABLE,
        $this->gate(NULL, $this->request(['status' => $status])),
        $status
      );
    }
  }

  /**
   * A field_request_status that is empty, absent or not in the catalogue is a
   * 409 AND NEVER AN EXCEPTION: myapi_services_transition_allowed() answers
   * FALSE for an unknown key by design, and this gate leans on that rather than
   * branching on it. Same for a request row that did not load.
   */
  public function testACorruptRequestStatusIsA409AndNotAnException() {
    $rows = [
      'empty'   => $this->request(['status' => '']),
      'null'    => $this->request(['status' => NULL]),
      'unknown' => $this->request(['status' => 'not_a_status']),
      'absent'  => (object) [],
      'no row'  => FALSE,
    ];

    foreach ($rows as $label => $row) {
      $this->assertSame(self::NOT_ASSIGNABLE, $this->gate(NULL, $row), $label);
    }
  }

  /**
   * CONDITION 8 — ABSENT valid_until MEANS "DOES NOT EXPIRE". It is optional
   * since SPEC 100 and most offers do not carry one; reading an empty column as
   * lapsed would block almost the whole catalogue.
   */
  public function testAnOfferWithoutAnExpiryNeverLapses() {
    foreach ([NULL, ''] as $valid_until) {
      $this->assertNull($this->gate($this->offer(['valid_until' => $valid_until])), var_export($valid_until, TRUE));
    }

    // The column absent from the row entirely, the way an offer created before
    // myapi_update_7035() arrives.
    $this->assertNull($this->gate((object) ['status' => MYAPI_SERVICES_OFFER_STATUS_SENT]));
  }

  /**
   * A quote whose deadline has passed is 409 service_offer_expired: awarding
   * work at a price the provider already declared lapsed commits them to
   * something they did not offer.
   */
  public function testAPastExpiryLapses() {
    foreach ([self::NOW - 1, self::NOW - 86400, 0, '0'] as $valid_until) {
      $this->assertSame(
        self::EXPIRED,
        $this->gate($this->offer(['valid_until' => $valid_until])),
        var_export($valid_until, TRUE)
      );
    }
  }

  /**
   * THE COMPARISON IS >=, exactly like myapi_services_provider_is_active(): the
   * quote is good THROUGHOUT its expiry instant and not one second less. The
   * boundary is the case worth pinning — off by one here silently rejects every
   * offer that expires this very second.
   */
  public function testTheExpiryBoundaryIsInclusive() {
    $this->assertNull($this->gate($this->offer(['valid_until' => self::NOW])), 'exactly now still holds');
    $this->assertNull($this->gate($this->offer(['valid_until' => self::NOW + 1])));
    $this->assertSame(self::EXPIRED, $this->gate($this->offer(['valid_until' => self::NOW - 1])));
  }

  /**
   * The timestamp arrives off a query column, so it is a string; it must be
   * read as the integer it is and not compared as text.
   */
  public function testTheExpiryReadsTheSameAsStringOrInteger() {
    $this->assertNull($this->gate($this->offer(['valid_until' => (string) (self::NOW + 86400)])));
    $this->assertSame(self::EXPIRED, $this->gate($this->offer(['valid_until' => (string) (self::NOW - 86400)])));
  }

  /**
   * A deadline nobody can parse is not a deadline that has been met: a
   * non-numeric value is read as LAPSED and not as absent, the same fail-closed
   * reading myapi_services_provider_is_active() gives a corrupt licence.
   */
  public function testANonNumericExpiryFailsClosed() {
    foreach (['abc', '2026-08-26', []] as $valid_until) {
      $this->assertSame(
        self::EXPIRED,
        $this->gate($this->offer(['valid_until' => $valid_until])),
        var_export($valid_until, TRUE)
      );
    }
  }

  /**
   * CONDITION 9 — THE PROVIDER MUST STILL BE ABLE TO WORK. Unpublished, licence
   * expired, or no licence row at all: all three are 403, and the rule is
   * myapi_services_provider_is_active()'s, not this gate's.
   */
  public function testAnInactiveProviderIsRejected() {
    $rows = [
      'unpublished'      => $this->provider(['status' => 0]),
      'licence expired'  => $this->provider(['license_expiry' => self::NOW - 1]),
      'licence empty'    => $this->provider(['license_expiry' => '']),
      'licence null'     => $this->provider(['license_expiry' => NULL]),
      'licence corrupt'  => $this->provider(['license_expiry' => 'abc']),
      'no licence row'   => (object) ['status' => 1],
      'no provider row'  => FALSE,
    ];

    foreach ($rows as $label => $row) {
      $this->assertSame(self::NOT_ACTIVE, $this->gate(NULL, NULL, $row), $label);
    }
  }

  /**
   * The licence boundary is inclusive too, and it is the same function's rule.
   */
  public function testTheLicenceBoundaryIsInclusive() {
    $this->assertNull($this->gate(NULL, NULL, $this->provider(['license_expiry' => self::NOW])));
    $this->assertSame(
      self::NOT_ACTIVE,
      $this->gate(NULL, NULL, $this->provider(['license_expiry' => self::NOW - 1]))
    );
  }

  /**
   * THE ORDER IS THE CONTRACT, AND IT IS ASSERTED HERE AND NOT ASSUMED.
   * Conditions 6, 7 and 8 ask "is this offer awardable?" and 9 asks "may this
   * provider still work?"; the second only means anything once the first has
   * passed. So a rejected offer from an unpublished provider answers 409
   * service_offer_not_acceptable and NOT 403 — the same order SPEC 105 fixed
   * for editing.
   */
  public function testTheFirstFailingConditionIsTheOneThatAnswers() {
    // Everything wrong at once: the offer status wins.
    $this->assertSame(self::NOT_ACCEPTABLE, $this->gate(
      $this->offer(['status' => 'rejected', 'valid_until' => self::NOW - 1]),
      $this->request(['status' => 'cancelled']),
      $this->provider(['status' => 0])
    ));

    // Offer fine: the request's status wins over the expiry and the licence.
    $this->assertSame(self::NOT_ASSIGNABLE, $this->gate(
      $this->offer(['valid_until' => self::NOW - 1]),
      $this->request(['status' => 'assigned']),
      $this->provider(['status' => 0])
    ));

    // Offer and request fine: the expiry wins over the licence.
    $this->assertSame(self::EXPIRED, $this->gate(
      $this->offer(['valid_until' => self::NOW - 1]),
      NULL,
      $this->provider(['status' => 0])
    ));
  }

  /**
   * PURE: the gate reads the three rows it is handed and asks nothing of the
   * database, which is what lets the whole matrix above run with no site
   * booted. A query creeping in here would make the resource pay twice for what
   * it already read.
   */
  public function testTheGateCostsNoQuery() {
    myapi_test_db_seed();

    $this->gate();
    $this->gate($this->offer(['status' => 'rejected']));

    $this->assertSame([], myapi_test_db_queries());
    $this->assertSame([], myapi_test_node_saves());
  }

  /* -------------------------------------------------------------------------
   * The timeline entry, myapi_service_transaction_record() (SPECS 95, 106).
   * ---------------------------------------------------------------------- */

  /**
   * The four fields of SPEC 77, plus node.uid and the bundle. This is the whole
   * contract of the extraction: the cancellation writes the same node it wrote
   * inline since SPEC 95, and the award writes one of the same shape.
   */
  public function testTheRecordedTransactionCarriesTheFourFields() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'Oferta adjudicada a Fontanería Ruiz.');

    $this->assertCount(1, myapi_test_node_saves());
    $this->assertSame($saved, myapi_test_node_saves()[0]);

    $this->assertSame(MYAPI_SERVICES_TRANSACTION_TYPE, $saved->type);
    $this->assertSame(77, $saved->uid);
    $this->assertSame(1, $saved->status);
    $this->assertSame(self::REQUEST_NID, $saved->field_request[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('assigned', $saved->field_request_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame('Oferta adjudicada a Fontanería Ruiz.', $saved->field_comment[LANGUAGE_NONE][0]['value']);
  }

  /**
   * field_status_date is the REAL instant with the seconds pinned to 00, and a
   * string and not a timestamp: the field is 'datetime' and not 'datestamp'
   * (SPEC 77). Midnight of that day would be the bug SPEC 58 already paid for
   * once in claims.
   */
  public function testTheStatusDateIsTheRealInstantWithSecondsPinned() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'x');
    $value = $saved->field_status_date[LANGUAGE_NONE][0]['value'];

    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $value);
    $this->assertSame(date('Y-m-d H:i:00'), $value);
  }

  /**
   * field_comment is written with 'value' ONLY and no 'format': the column is
   * stored raw and whoever renders it escapes it, exactly like claims.
   */
  public function testTheCommentCarriesNoTextFormat() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'x');

    $this->assertSame(['value' => 'x'], $saved->field_comment[LANGUAGE_NONE][0]);
  }

  /**
   * THE TITLE IS NOT SET HERE. myapi_service_transaction_set_title() puts it in
   * from hook_node_presave(), inside the node_save() above — the one place that
   * titles a transaction whatever created it. A title written here would be a
   * second one for the same node.
   */
  public function testTheRecorderDoesNotSetTheTitle() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'x');

    $this->assertFalse(property_exists($saved, 'title'), 'the recorder leaves the title to hook_node_presave()');
  }

  /**
   * The status is stored AS-IS. Recording what there was is the point, so
   * nothing here validates it against the catalogue or against the graph — a
   * request saved programmatically without one copies the empty value, exactly
   * as myapi_service_transaction_create_initial() does.
   */
  public function testTheStatusIsStoredWithoutValidation() {
    foreach (['assigned', 'cancelled', '', 'not_a_status'] as $status) {
      myapi_test_write_reset();
      $saved = myapi_service_transaction_record(self::REQUEST_NID, $status, 77, 'x');

      $this->assertSame($status, $saved->field_request_status[LANGUAGE_NONE][0]['value'], $status);
    }
  }

  /* -------------------------------------------------------------------------
   * The award's timeline text, myapi_service_transaction_accept_comment().
   * ---------------------------------------------------------------------- */

  /**
   * WITH AN AMOUNT: the provider and the price, which is the part of the
   * decision worth recording. Comma for the decimal separator and dot for the
   * thousands, two decimals always, and NO CURRENCY SYMBOL — field_offer_amount
   * stores a number and the module has no currency anywhere, so writing one
   * here would invent a datum.
   */
  public function testAPricedOfferNamesTheProviderAndTheAmount() {
    $cases = [
      '95.5'      => 'Oferta adjudicada a Fontanería Ruiz por 95,50.',
      '95.50'     => 'Oferta adjudicada a Fontanería Ruiz por 95,50.',
      '1250'      => 'Oferta adjudicada a Fontanería Ruiz por 1.250,00.',
      '1234567.8' => 'Oferta adjudicada a Fontanería Ruiz por 1.234.567,80.',
      '0.5'       => 'Oferta adjudicada a Fontanería Ruiz por 0,50.',
      '95.567'    => 'Oferta adjudicada a Fontanería Ruiz por 95,57.',
    ];

    foreach ($cases as $amount => $expected) {
      $this->assertSame(
        $expected,
        myapi_service_transaction_accept_comment('Fontanería Ruiz', $amount, 'fixed'),
        $amount
      );
    }
  }

  /**
   * The amount arrives off a query column, so it is a string; a float behaves
   * identically. Neither prints differently from the other.
   */
  public function testTheAmountReadsTheSameAsStringOrFloat() {
    $this->assertSame(
      myapi_service_transaction_accept_comment('Fontanería Ruiz', '95.50', 'fixed'),
      myapi_service_transaction_accept_comment('Fontanería Ruiz', 95.50, 'fixed')
    );
  }

  /**
   * 'on_site_quote' IS THE AMOUNTLESS TYPE (SPEC 100) AND IT WINS over whatever
   * sits in the column: an offer to be quoted on site has no price to record,
   * and printing one left over from an earlier edit would put a figure nobody
   * agreed to on a timeline that is forever.
   */
  public function testAnOnSiteQuoteNeverPrintsAnAmount() {
    $expected = 'Oferta adjudicada a Fontanería Ruiz.';

    $this->assertSame($expected, myapi_service_transaction_accept_comment('Fontanería Ruiz', NULL, 'on_site_quote'));
    $this->assertSame($expected, myapi_service_transaction_accept_comment('Fontanería Ruiz', '95.50', 'on_site_quote'));
  }

  /**
   * NO AMOUNT AT ALL is the same sentence: an offer created before the ten
   * quote columns existed has no row in field_data_field_offer_amount, and a
   * non-numeric value is treated as an absence rather than printed raw.
   */
  public function testAnOfferWithoutAnAmountNamesOnlyTheProvider() {
    $expected = 'Oferta adjudicada a Fontanería Ruiz.';

    foreach ([NULL, '', 'abc', []] as $amount) {
      $this->assertSame(
        $expected,
        myapi_service_transaction_accept_comment('Fontanería Ruiz', $amount, 'fixed'),
        var_export($amount, TRUE)
      );
    }
  }

  /**
   * WITHOUT A PROVIDER NAME the entry is still a whole sentence. This case
   * cannot happen today — condition 9 requires an active provider, which is
   * published and therefore titled — and exists because a pure function handed
   * NULL must answer a sentence and not half of one.
   */
  public function testWithoutAProviderNameTheEntryStillReads() {
    foreach ([NULL, '', '   '] as $name) {
      $this->assertSame(
        'Oferta adjudicada.',
        myapi_service_transaction_accept_comment($name, '95.50', 'fixed'),
        var_export($name, TRUE)
      );
    }
  }

  /**
   * The provider's name is trimmed, so the timeline never prints a leading
   * blank — the same rule the cancellation reason follows.
   */
  public function testTheProviderNameIsTrimmed() {
    $this->assertSame(
      'Oferta adjudicada a Fontanería Ruiz.',
      myapi_service_transaction_accept_comment('  Fontanería Ruiz  ', NULL, 'on_site_quote')
    );
  }

  /**
   * A request nid that is not a positive integer answers 0 BEFORE any query,
   * which is the guard the function was born with (SPEC 95) and the reason a
   * malformed route can never start a sweep.
   *
   * TRUE IS NOT IN THE LIST, AND THAT IS DELIBERATE. ctype_digit((string) TRUE)
   * is ctype_digit('1'), so a boolean true walks through this guard as the nid
   * 1. It is the behaviour the function has carried since SPEC 95 and the
   * extraction did not change it; no caller can produce it — both pass a nid
   * read off a node — so it is left as inherited rather than asserted here as
   * if it were a decision.
   */
  public function testANonPositiveIntegerRequestNidCostsNoQuery() {
    foreach (['abc', '0', '-3', '', 0, -3, 1.5, NULL, []] as $raw) {
      myapi_test_db_seed(['field_data_field_request' => [$this->sweepRow(901, 'sent')]]);
      myapi_test_write_reset();

      $this->assertSame(0, myapi_service_offer_reject_live($raw), var_export($raw, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($raw, TRUE));
      $this->assertSame([], myapi_test_node_saves(), var_export($raw, TRUE));
    }
  }

}
