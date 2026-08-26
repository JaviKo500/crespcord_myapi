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
require_once __DIR__ . '/../../includes/myapi.service_offer_query.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../resources/service_offer.resource.inc';

/**
 * Unit tests for the detail of one offer: GET /api/v1/service-offers/provider/%
 * and GET /api/v1/service-offers/% (SPEC 103).
 *
 * Sibling of ServiceOfferProviderListTest, and the same split: what is asserted
 * here is the half of the endpoint that is PURE — the servable set, the
 * visibility rule and the two serialisers — with no site booted.
 *
 * THE ONE THING THIS SUITE EXISTS TO GUARD is that neither route rewrites what
 * somebody else already answers. The fifteen keys of the offer are
 * myapi_service_offer_build()'s, whole and untouched; five of the seven keys of
 * `request` are myapi_service_request_build_item()'s. Both are asserted by
 * COMPARING the two over the SAME row, key by key — a test that fails the day
 * somebody deletes the call and reimplements it, which is the only way that
 * duplication ever gets in.
 *
 * WHAT THE FIXTURE CANNOT PROVE, and is a manual criterion of the spec instead:
 * that MySQL evaluates the INNER JOIN to the request the way the fixture
 * evaluator does (it records joins, it does not resolve them), and that
 * hook_menu() resolves 'api/v1/service-offers/provider/%' before
 * 'api/v1/service-offers/%'. Where a join condition IS the contract — the
 * request's bundle and published flag — what is asserted is the SHAPE of the
 * query the code built, which is the most a fixture can honestly say.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: an offer
 * is seeded flat — its own node columns plus the value each JOIN would have
 * brought. The published flag of the node travels as `status` and the offer's
 * own status under its QUALIFIED source, because a flat row cannot hold both
 * and the fixture resolves the qualified name first.
 */
class ServiceOfferDetailTest extends TestCase {

  const OFFER_NID = 901;
  const REQUEST_NID = 128;
  const PROVIDER_NID = 41;
  const CREATED = 1756116840;

  protected function setUp(): void {
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

  /**
   * One offer row, flat, as every join of myapi_service_offer_detail_row()
   * delivers it. Carries the fifteen aliases the serialiser reads, plus the two
   * this query adds: `provider_raw` for the gate and `request_id` for the
   * context.
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
      'fml.uri'                              => 'public://logo.png',
      'foa.field_offer_amount_value'         => '95.50',
      'fom.field_offer_message_value'        => 'Cambio de resistencia y purgado del circuito.',
      'fost.field_offer_status_value'        => 'selected',
      'fat.field_offer_amount_type_value'    => 'fixed',
      'fvu.field_offer_valid_until_value'    => '1756771199',
      'faf.field_offer_available_from_value' => '1756285200',
      'fdu.field_offer_duration_value'       => '2',
      'fdn.field_offer_duration_unit_value'  => 'hours',
      'fin.field_offer_includes_value'       => 'Material y desplazamiento.',
      'fex.field_offer_excludes_value'       => '',
      'fti.field_offer_tax_included_value'   => '1',
      'fwd.field_offer_warranty_days_value'  => '30',
      'frv.field_offer_requires_visit_value' => '0',
    ];
  }

  /**
   * Seeds the node table with the given rows and nothing else. Every
   * myapi_test_db_seed() replaces the WHOLE fixture, so nothing can be added
   * afterwards.
   */
  private function seedNodes(array $rows) {
    myapi_test_db_seed(['node' => $rows]);
    myapi_test_static_reset();
  }

  /**
   * The join the query recorded against a given alias, or NULL.
   */
  private function joinOn($alias, $query_index = 0) {
    $queries = myapi_test_db_queries();
    $this->assertArrayHasKey($query_index, $queries, 'the query ran');

    foreach ($queries[$query_index]['joins'] as $join) {
      if ($join['alias'] === $alias) {
        return $join;
      }
    }

    return NULL;
  }

  /* -------------------------------------------------------------------------
   * The servable set: three conditions, four ways to miss them (step 2).
   * ---------------------------------------------------------------------- */

  /**
   * The happy row: a published offer of the right bundle, on a published
   * request, comes back whole.
   */
  public function testAPublishedOfferOnAPublishedRequestIsServed() {
    $this->seedNodes([$this->offerRow()]);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $this->assertIsObject($row, 'the offer is servable');
    $this->assertSame((string) self::OFFER_NID, (string) $row->nid);
    $this->assertSame((string) self::REQUEST_NID, (string) $row->request_id);
  }

  /**
   * AN OFFER THAT DOES NOT EXIST IS FALSE, not an empty object: the caller
   * answers 404 off this one value, and it must be the same value the other
   * three misses produce.
   */
  public function testAnOfferThatDoesNotExistIsFalse() {
    $this->seedNodes([$this->offerRow()]);

    $this->assertFalse(myapi_service_offer_detail_row(self::OFFER_NID + 1));
  }

  /**
   * An UNPUBLISHED offer is FALSE: n.status = 1 is one of the three conditions
   * of the servable set, and it is not negotiable by the offer's own status
   * key.
   */
  public function testAnUnpublishedOfferIsFalse() {
    $this->seedNodes([$this->offerRow(['status' => '0'])]);

    $this->assertFalse(myapi_service_offer_detail_row(self::OFFER_NID));
  }

  /**
   * A nid of ANOTHER BUNDLE is FALSE — a request, a provider, a transaction.
   * Without n.type this endpoint would serve whatever node carried that nid.
   */
  public function testANidOfAnotherBundleIsFalse() {
    $this->seedNodes([$this->offerRow(['type' => MYAPI_SERVICES_REQUEST_TYPE])]);

    $this->assertFalse(myapi_service_offer_detail_row(self::OFFER_NID));
  }

  /**
   * A nid that is not a positive integer answers FALSE AND RUNS NO QUERY. The
   * route's parser refuses those before this function is reached; the guard is
   * here so a future caller cannot turn a stray 0 into a full table scan.
   */
  public function testANonPositiveNidAnswersFalseWithoutQuerying() {
    $this->seedNodes([$this->offerRow()]);

    foreach ([0, -1, 'abc', NULL] as $nid) {
      $this->assertFalse(myapi_service_offer_detail_row($nid), var_export($nid, TRUE));
    }

    $this->assertSame([], myapi_test_db_queries(), 'no query was run');
  }

  /**
   * THE REQUEST'S BUNDLE AND PUBLISHED FLAG RIDE IN THE INNER JOIN, which is
   * the fourth way to miss the set — and the one the fixture cannot evaluate,
   * because it records joins and does not resolve them. So what is asserted is
   * the shape of the query the code built.
   *
   * field_request IS SHARED with 'service_transaction' (SPEC 77): a join that
   * stopped at the field table would serve a timeline entry as a request. That
   * is riesgo 4 of the spec, and this is the assertion that fails if somebody
   * "simplifies" the join away.
   */
  public function testTheRequestIsReachedByAnInnerJoinCarryingBundleAndStatus() {
    $this->seedNodes([$this->offerRow()]);
    myapi_service_offer_detail_row(self::OFFER_NID);

    $field = $this->joinOn('fq');
    $this->assertNotNull($field, 'the field_request row is joined');
    $this->assertSame('INNER', $field['type']);
    $this->assertSame('field_data_field_request', $field['table']);

    $node = $this->joinOn('nr');
    $this->assertNotNull($node, 'the request NODE is joined, not just its field row');
    $this->assertSame('INNER', $node['type'], 'INNER: an offer with no request is not servable');
    $this->assertSame('node', $node['table']);
    $this->assertStringContainsString('nr.type = :request_type', $node['condition']);
    $this->assertStringContainsString('nr.status = 1', $node['condition']);
  }

  /**
   * NO CONDITION ON EITHER STATUS KEY, on np.status or on the licence: an offer
   * 'withdrawn' on a request 'cancelled' from a suspended provider is served
   * whole. The set is three conditions and the test names all three.
   */
  public function testTheServableSetIsExactlyThreeConditions() {
    $this->seedNodes([$this->offerRow()]);
    myapi_service_offer_detail_row(self::OFFER_NID);

    $queries = myapi_test_db_queries();
    $fields = [];
    foreach ($queries[0]['conditions'] as $condition) {
      $fields[] = $condition['field'];
    }

    $this->assertSame(['n.nid', 'n.type', 'n.status'], $fields);
  }

  /**
   * A 'withdrawn' offer on a 'cancelled' request is served, and the row carries
   * both status keys untouched: it is the client who decides what to paint.
   */
  public function testAWithdrawnOfferOnACancelledRequestIsServed() {
    $this->seedNodes([$this->offerRow([
      'fost.field_offer_status_value' => 'withdrawn',
    ])]);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $this->assertIsObject($row);
    $this->assertSame('withdrawn', $row->status);
  }

  /* -------------------------------------------------------------------------
   * The two provider columns: the joined one is painted, the raw one gates.
   * ---------------------------------------------------------------------- */

  /**
   * TWO COLUMNS FOR THE PROVIDER, AND THAT IS THE POINT. `provider_id` comes
   * from the joined node, which carries status = 1, and is what the serialiser
   * paints; `provider_raw` is the raw target_id and is what the gate reads.
   */
  public function testTheProviderTravelsTwice_joinedForDisplayAndRawForTheGate() {
    $this->seedNodes([$this->offerRow()]);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $this->assertSame((string) self::PROVIDER_NID, (string) $row->provider_id);
    $this->assertSame((string) self::PROVIDER_NID, (string) $row->provider_raw);

    $node = $this->joinOn('np');
    $this->assertSame('LEFT', $node['type'], 'LEFT: an offer with no provider is still servable');
    $this->assertStringContainsString('np.status = 1', $node['condition']);
  }

  /**
   * A SUSPENDED PROVIDER'S OFFER IS STILL ITS OWNER'S. The node join answers
   * NULL for provider_id — so `provider` is null, exactly as `offers` already
   * answers for that same row — but `provider_raw` still names the provider, so
   * the gate lets the account into its own archive. The licence governs the
   * market, not the record of what was already quoted (SPEC 102).
   */
  public function testASuspendedProvidersOfferKeepsItsRawOwner() {
    $this->seedNodes([$this->offerRow([
      'np.nid'    => NULL,
      'np.title'  => NULL,
      'fml.uri'   => NULL,
    ])]);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $this->assertIsObject($row, 'the offer is served');
    $this->assertNull($row->provider_id, 'the joined node is out: provider will be null');
    $this->assertSame((string) self::PROVIDER_NID, (string) $row->provider_raw, 'the gate still knows whose it is');
  }

  /* -------------------------------------------------------------------------
   * The projection: the fifteen aliases, and only nr.nid off the request.
   * ---------------------------------------------------------------------- */

  /**
   * THE ROW CARRIES EVERY ALIAS myapi_service_offer_build() READS. It is copied
   * from myapi_service_request_load_offers() join for join, because the same
   * offer read through `offers`, through `my_offers` and through this detail
   * has to answer the same fifteen keys.
   */
  public function testTheRowCarriesEveryAliasTheSerialiserReads() {
    $this->seedNodes([$this->offerRow()]);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $aliases = [
      'nid', 'provider_id', 'provider_name', 'provider_logo_uri', 'amount',
      'message', 'status', 'created', 'amount_type', 'valid_until',
      'available_from', 'duration', 'duration_unit', 'includes', 'excludes',
      'tax_included', 'warranty_days', 'requires_visit',
    ];

    foreach ($aliases as $alias) {
      $this->assertTrue(property_exists($row, $alias), $alias . ' is projected');
    }
  }

  /**
   * ONLY nr.nid IS PROJECTED OFF THE REQUEST (decision 9). The context block is
   * built from myapi_service_request_detail_row(), which owns what a
   * condominium, a unit and a category are; projecting any of them here would
   * be a second definition of all three inside a file that is not their home.
   */
  public function testNothingButTheRequestsNidIsProjectedOffTheRequest() {
    $this->seedNodes([$this->offerRow()]);
    myapi_service_offer_detail_row(self::OFFER_NID);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $this->assertTrue(property_exists($row, 'request_id'), 'the request nid travels');

    // Every datum of the request the context block needs, and which this query
    // deliberately does NOT bring: they come from the function that owns them.
    $not_here = [
      'request_title', 'request_status', 'category_id', 'category_code',
      'category_name', 'condominium_id', 'condominium_name', 'unit_id',
      'unit_name', 'requester_uid', 'assigned_provider_id',
    ];

    foreach ($not_here as $alias) {
      $this->assertFalse(property_exists($row, $alias), $alias . ' is not this query\'s to project');
    }
  }

  /**
   * The ten quote columns of SPEC 100 are ALL LEFT-joined. One INNER among them
   * would 404 every offer created before myapi_update_7035() — the whole
   * historic archive — instead of answering null for the ten.
   */
  public function testTheTenQuoteColumnsAreAllLeftJoined() {
    $this->seedNodes([$this->offerRow()]);
    myapi_service_offer_detail_row(self::OFFER_NID);

    $aliases = ['fat', 'fvu', 'faf', 'fdu', 'fdn', 'fin', 'fex', 'fti', 'fwd', 'frv'];

    foreach ($aliases as $alias) {
      $join = $this->joinOn($alias);
      $this->assertNotNull($join, $alias . ' is joined');
      $this->assertSame('LEFT', $join['type'], $alias . ' must be LEFT');
    }
  }

  /**
   * An offer older than SPEC 100 — no row in any of the ten quote tables — is
   * SERVED, with the ten reading null. It is the historic archive, and a single
   * INNER above would have erased it.
   */
  public function testAnOfferOlderThanSpec100IsServed() {
    $this->seedNodes([$this->offerRow([
      'fat.field_offer_amount_type_value'    => NULL,
      'fvu.field_offer_valid_until_value'    => NULL,
      'faf.field_offer_available_from_value' => NULL,
      'fdu.field_offer_duration_value'       => NULL,
      'fdn.field_offer_duration_unit_value'  => NULL,
      'fin.field_offer_includes_value'       => NULL,
      'fex.field_offer_excludes_value'       => NULL,
      'fti.field_offer_tax_included_value'   => NULL,
      'fwd.field_offer_warranty_days_value'  => NULL,
      'frv.field_offer_requires_visit_value' => NULL,
    ])]);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $this->assertIsObject($row, 'the historic offer is servable');
    $this->assertNull($row->amount_type);
    $this->assertNull($row->valid_until);
  }

}
