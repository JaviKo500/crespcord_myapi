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
require_once __DIR__ . '/../../includes/myapi.service_request_detail.inc';
require_once __DIR__ . '/../../resources/service_offer.resource.inc';
// SPEC 103, decision 4: `condominium` and `requester` are built in THREE places
// on purpose. This suite is what keeps the three in agreement, so it has to be
// able to call all three — hence the request resource, for the two copies that
// live there.
require_once __DIR__ . '/../../resources/service_request.resource.inc';

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
  const TOKEN = 'a-valid-access-token';
  const UID = 7;
  const REQUESTER_UID = 314;
  const FOREIGN_PROVIDER = 77;

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
   * The visibility rule: "is this job already mine?" (step 3).
   * ---------------------------------------------------------------------- */

  /**
   * A request row as myapi_service_request_detail_row() delivers it, of which
   * this rule reads exactly one column.
   */
  private function requestRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'                   => self::REQUEST_NID,
      'assigned_provider_id'  => NULL,
      'assigned_provider_raw' => NULL,
    ]);
  }

  /**
   * NOT AWARDED: nobody's job yet, so nobody's address.
   */
  public function testAnUnawardedRequestIsNobodysJob() {
    $this->assertFalse(myapi_service_offer_detail_is_mine(
      $this->requestRow(),
      [self::PROVIDER_NID]
    ));
  }

  /**
   * AWARDED TO SOMEBODY ELSE: false, and it stays false whatever my own offer's
   * status says. The award decides, not the offer (decision 5).
   */
  public function testARequestAwardedToAnotherProviderIsNotMine() {
    $this->assertFalse(myapi_service_offer_detail_is_mine(
      $this->requestRow([
        'assigned_provider_id'  => '77',
        'assigned_provider_raw' => '77',
      ]),
      [self::PROVIDER_NID]
    ));
  }

  /**
   * AWARDED TO ME: true. The columns come back as strings from the database and
   * the list as ints, so the comparison has to survive that — a strict
   * in_array() without the intval() would answer false on real data.
   */
  public function testARequestAwardedToMyProviderIsMine() {
    $this->assertTrue(myapi_service_offer_detail_is_mine(
      $this->requestRow([
        'assigned_provider_id'  => (string) self::PROVIDER_NID,
        'assigned_provider_raw' => (string) self::PROVIDER_NID,
      ]),
      [self::PROVIDER_NID]
    ));
  }

  /**
   * AN ACCOUNT WITH TWO PROVIDERS owns the job of either one.
   */
  public function testAnAccountWithTwoProvidersOwnsBothJobs() {
    $row = $this->requestRow([
      'assigned_provider_id'  => '42',
      'assigned_provider_raw' => '42',
    ]);

    $this->assertTrue(myapi_service_offer_detail_is_mine($row, [self::PROVIDER_NID, 42]));
  }

  /**
   * A BROKEN AWARD CLOSES THE ADDRESS, and this is riesgo 7 of the spec. The
   * award points at a provider node that was deleted or unpublished: the raw
   * column still names it, the joined column is NULL, and the rule reads the
   * JOINED one. A datum written wrong must not send a resident's street address
   * to a provider who is not going to that house.
   */
  public function testABrokenAwardIsNotMineEvenWhenTheRawColumnNamesMe() {
    $this->assertFalse(myapi_service_offer_detail_is_mine(
      $this->requestRow([
        'assigned_provider_id'  => NULL,
        'assigned_provider_raw' => (string) self::PROVIDER_NID,
      ]),
      [self::PROVIDER_NID]
    ));
  }

  /**
   * NO REQUEST AND NO PROVIDERS ARE BOTH FALSE, never NULL: two keys of the
   * response hang on this boolean, and a null would make them a third thing.
   */
  public function testTheDegenerateCasesAreFalseAndNotNull() {
    $this->assertFalse(myapi_service_offer_detail_is_mine(FALSE, [self::PROVIDER_NID]));
    $this->assertFalse(myapi_service_offer_detail_is_mine(NULL, [self::PROVIDER_NID]));
    $this->assertFalse(myapi_service_offer_detail_is_mine(
      $this->requestRow(['assigned_provider_id' => (string) self::PROVIDER_NID]),
      []
    ));
  }

  /* -------------------------------------------------------------------------
   * The `request` block: seven keys, and who sees which (step 4).
   * ---------------------------------------------------------------------- */

  /**
   * A request row as myapi_service_request_detail_row() delivers it, carrying
   * every alias the shared serialiser reads plus the four the context block
   * adds.
   */
  private function contextRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'                   => (string) self::REQUEST_NID,
      'title'                 => 'Fuga en el calentador',
      'description'           => 'El calentador gotea por la base.',
      'status'                => 'assigned',
      'category_id'           => '12',
      'category_code'         => 'plumbing',
      'category_name'         => 'Plomería',
      'unit_id'               => '55',
      'unit_name'             => 'Apto 302',
      'condominium_id'        => '7',
      'condominium_name'      => 'Residencial Los Álamos',
      'requester_uid'         => '314',
      'requester_name'        => 'María Crespo',
      'assigned_offer_id'     => NULL,
      'assigned_offer_status' => NULL,
      'assigned_provider_id'  => (string) self::PROVIDER_NID,
      'assigned_provider_name' => 'Plomería Torres',
      'created'               => (string) self::CREATED,
      'desired_start'         => NULL,
    ]);
  }

  /**
   * SEVEN KEYS, IN THIS ORDER, WHATEVER THE TWO BOOLEANS SAY. Written out as a
   * literal on purpose: reading them off the function under test would make the
   * assertion agree with itself.
   */
  const CONTEXT_KEYS = [
    'id',
    'title',
    'status',
    'category',
    'condominium',
    'unit',
    'requester',
  ];

  public function testTheContextIsAlwaysTheSameSevenKeysInOrder() {
    foreach ([[TRUE, TRUE], [TRUE, FALSE], [FALSE, TRUE], [FALSE, FALSE]] as $case) {
      list($show_requester, $show_unit) = $case;

      $context = myapi_service_offer_build_context(
        $this->contextRow(),
        $show_requester,
        $show_unit
      );

      $this->assertSame(
        self::CONTEXT_KEYS,
        array_keys($context),
        'requester=' . var_export($show_requester, TRUE) . ' unit=' . var_export($show_unit, TRUE)
      );
    }
  }

  /**
   * THE FIVE SHARED KEYS ARE NOT WRITTEN TWICE. They are compared, over the
   * SAME row, against myapi_service_request_build_item() — the serialiser the
   * request's own endpoints answer with. This is the test that fails the day
   * somebody deletes the call and reimplements the five, which is the only way
   * that duplication ever gets in.
   */
  public function testFiveKeysAreIdenticalToTheSharedRequestSerialiser() {
    $row = $this->contextRow();

    $shared = myapi_service_request_build_item($row, []);
    $context = myapi_service_offer_build_context($row, TRUE, TRUE);

    foreach (['id', 'title', 'status', 'category', 'unit'] as $key) {
      $this->assertSame($shared[$key], $context[$key], $key . ' is taken, not rewritten');
    }
  }

  /**
   * `category.code` is "" and NEVER null when the term carries none — the field
   * is required on the vocabulary, so an empty one is corrupt data the client
   * can still compare as a string. Inherited from the shared serialiser, and
   * asserted here because it is a criterion of this spec too.
   */
  public function testTheCategoryCodeIsAnEmptyStringAndNeverNull() {
    $context = myapi_service_offer_build_context(
      $this->contextRow(['category_code' => NULL]),
      TRUE,
      TRUE
    );

    $this->assertSame('', $context['category']['code']);
    $this->assertSame(['id', 'code', 'name'], array_keys($context['category']));
  }

  /**
   * `unit.name` IS field_nombre_vivienda AND NOT THE NODE TITLE — the title is
   * an internal label, the field is the name the resident knows their flat by.
   * The row seeds a different title precisely so a serialiser reading the wrong
   * column would fail here.
   */
  public function testTheUnitNameIsTheFieldAndNotTheNodeTitle() {
    $context = myapi_service_offer_build_context(
      $this->contextRow(['title' => 'Vivienda interna 55', 'unit_name' => 'Apto 302']),
      TRUE,
      TRUE
    );

    $this->assertSame('Apto 302', $context['unit']['name']);
  }

  /**
   * THE FOUR COMBINATIONS OF THE TWO BOOLEANS, and `condominium` unmoved by
   * either of them (decision 11): it names the residential complex, not a
   * person and not a door.
   */
  public function testTheTwoBooleansGovernExactlyTwoKeys() {
    $row = $this->contextRow();

    $both = myapi_service_offer_build_context($row, TRUE, TRUE);
    $this->assertSame(['id' => 55, 'name' => 'Apto 302'], $both['unit']);
    $this->assertSame(['id' => 314, 'name' => 'María Crespo'], $both['requester']);

    $unit_only = myapi_service_offer_build_context($row, FALSE, TRUE);
    $this->assertSame(['id' => 55, 'name' => 'Apto 302'], $unit_only['unit']);
    $this->assertNull($unit_only['requester']);

    $requester_only = myapi_service_offer_build_context($row, TRUE, FALSE);
    $this->assertNull($requester_only['unit']);
    $this->assertSame(['id' => 314, 'name' => 'María Crespo'], $requester_only['requester']);

    $neither = myapi_service_offer_build_context($row, FALSE, FALSE);
    $this->assertNull($neither['unit']);
    $this->assertNull($neither['requester']);

    // The condominium is the same object in all four.
    foreach ([$both, $unit_only, $requester_only, $neither] as $context) {
      $this->assertSame(
        ['id' => 7, 'name' => 'Residencial Los Álamos'],
        $context['condominium'],
        'the condominium travels always and for everyone'
      );
    }
  }

  /**
   * A HIDDEN KEY IS A WHOLE null AND NEVER {id: null, name: null}. A provider
   * cannot tell "not awarded to me" from "the unit was deleted", and has no
   * reason to: in both cases there is no address to paint.
   */
  public function testAHiddenKeyIsAWholeNullAndNeverAHalfObject() {
    $hidden = myapi_service_offer_build_context($this->contextRow(), FALSE, FALSE);

    $this->assertNull($hidden['unit']);
    $this->assertNull($hidden['requester']);
    $this->assertNotSame(['id' => NULL, 'name' => NULL], $hidden['unit']);
    $this->assertNotSame(['id' => NULL, 'name' => NULL], $hidden['requester']);
  }

  /**
   * A request with NO CONDOMINIUM and NO UNIT answers a whole null for each,
   * with the seven keys still in place — and the same for a shown block whose
   * columns are empty.
   */
  public function testMissingColumnsAnswerWholeNullsAndKeepTheSevenKeys() {
    $context = myapi_service_offer_build_context(
      $this->contextRow([
        'condominium_id'   => NULL,
        'condominium_name' => NULL,
        'unit_id'          => NULL,
        'unit_name'        => NULL,
        'requester_uid'    => NULL,
        'requester_name'   => NULL,
      ]),
      TRUE,
      TRUE
    );

    $this->assertSame(self::CONTEXT_KEYS, array_keys($context));
    $this->assertNull($context['condominium']);
    $this->assertNull($context['unit']);
    $this->assertNull($context['requester']);
  }

  /**
   * THE THREE COPIES OF `condominium` AND `requester` ANSWER THE SAME THING.
   * Decision 4 leaves those five lines in three places on purpose; this is what
   * makes the debt DETECTABLE — a divergence between the context block, the
   * provider listing item and the request's own detail fails in red the day it
   * is written, instead of showing up as one request naming two condominiums.
   */
  public function testTheThreeCopiesOfCondominiumAndRequesterAgree() {
    $row = $this->contextRow();

    $context = myapi_service_offer_build_context($row, TRUE, TRUE);
    $listing = myapi_service_request_provider_build_item($row, [], [self::PROVIDER_NID]);
    $detail = myapi_service_request_build_detail($row, 'requester', [], [], 0, []);

    foreach (['condominium', 'requester'] as $key) {
      $this->assertSame($listing[$key], $context[$key], $key . ': the provider listing and the context agree');
      $this->assertSame($detail[$key], $context[$key], $key . ": the request's own detail and the context agree");
    }
  }

  /**
   * NOT ONE CONTACT DATUM, and not one key of the request's own detail that
   * `request` is not: it is REFERENTIAL by decision (decision 3), never half a
   * detail.
   */
  public function testTheContextCarriesNothingItWasNotAskedFor() {
    $context = myapi_service_offer_build_context($this->contextRow(), TRUE, TRUE);

    $forbidden = [
      'description', 'desired_start', 'closed_at', 'offers_count',
      'assigned_offer', 'assigned_provider', 'images', 'attachment',
      'transactions', 'viewer', 'offers', 'phone', 'email', 'address',
    ];

    foreach ($forbidden as $key) {
      $this->assertArrayNotHasKey($key, $context, $key . ' is out of scope');
    }

    foreach (['unit', 'requester', 'condominium'] as $key) {
      if ($context[$key] !== NULL) {
        $this->assertSame(['id', 'name'], array_keys($context[$key]), $key . ' is {id, name} and nothing else');
      }
    }
  }

  /* -------------------------------------------------------------------------
   * The response: fifteen keys plus `request` (step 5).
   * ---------------------------------------------------------------------- */

  /**
   * THE SIXTEEN KEYS, IN THE ORDER THE SPEC FIXES THEM. Written out as a
   * literal: reading them off the function under test would make the assertion
   * agree with itself.
   */
  const DETAIL_KEYS = [
    'id',
    'provider',
    'amount',
    'message',
    'status',
    'created',
    'amount_type',
    'valid_until',
    'available_from',
    'duration',
    'includes',
    'excludes',
    'tax_included',
    'warranty_days',
    'requires_visit',
    'request',
  ];

  /**
   * An offer row as an OBJECT, the shape myapi_service_offer_build() reads —
   * the fixture rows above are arrays keyed by qualified column, which is what
   * the query answers with, so this one names the aliases directly.
   */
  private function offerObject(array $overrides = []) {
    return (object) ($overrides + [
      'nid'               => (string) self::OFFER_NID,
      'provider_id'       => (string) self::PROVIDER_NID,
      'provider_name'     => 'Plomería Torres',
      'provider_logo_uri' => 'public://logo.png',
      'amount'            => '95.50',
      'message'           => 'Cambio de resistencia y purgado del circuito.',
      'status'            => 'selected',
      'created'           => (string) self::CREATED,
      'amount_type'       => 'fixed',
      'valid_until'       => '1756771199',
      'available_from'    => '1756285200',
      'duration'          => '2',
      'duration_unit'     => 'hours',
      'includes'          => 'Material y desplazamiento.',
      'excludes'          => '',
      'tax_included'      => '1',
      'warranty_days'     => '30',
      'requires_visit'    => '0',
    ]);
  }

  public function testTheResponseIsSixteenKeysAndRequestIsTheLast() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject(),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    $this->assertSame(self::DETAIL_KEYS, array_keys($detail));
    $this->assertSame('request', array_keys($detail)[15], 'request is the sixteenth');
  }

  /**
   * THE FIFTEEN ARE NOT WRITTEN TWICE. Compared, over the SAME row, against
   * myapi_service_offer_build() — the serialiser `offers` and `my_offers`
   * already answer with. This is the test that fails the day somebody deletes
   * the call and reimplements the fifteen.
   */
  public function testTheFifteenKeysAreIdenticalToTheSharedOfferSerialiser() {
    $row = $this->offerObject();

    $shared = myapi_service_offer_build($row);
    $detail = myapi_service_offer_build_detail($row, $this->contextRow(), TRUE, TRUE);

    foreach ($shared as $key => $value) {
      $this->assertSame($value, $detail[$key], $key . ' is taken, not rewritten');
    }

    // And nothing of the fifteen was dropped on the way in.
    $this->assertSame(array_keys($shared), array_slice(array_keys($detail), 0, 15));
  }

  /**
   * The typing rules of the fifteen, asserted through THIS response because
   * they are criteria of this spec too: `amount` is a float or null and never
   * "95.50"; `message` is "" and never null; `requires_visit` is a bool and
   * never null; `tax_included` tells true, false and null apart; `duration` is
   * a whole object or a whole null.
   */
  public function testTheTypingRulesOfTheFifteenSurviveTheDelegation() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject(),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    $this->assertSame(95.5, $detail['amount']);
    $this->assertIsFloat($detail['amount']);
    $this->assertSame('Cambio de resistencia y purgado del circuito.', $detail['message']);
    $this->assertFalse($detail['requires_visit']);
    $this->assertTrue($detail['tax_included']);
    $this->assertSame(['value' => 2, 'unit' => 'hours'], $detail['duration']);
    // "" is a null for every optional text; `message` is the exception above.
    $this->assertNull($detail['excludes']);
    $this->assertSame(['id', 'name', 'logo'], array_keys($detail['provider']));
  }

  /**
   * An 'on_site_quote' offer with no amount answers a WHOLE null, and
   * `tax_included` undeclared is null and not false — the only three-valued key
   * of the fifteen.
   */
  public function testAnUnpricedOfferAnswersNullAndNotZero() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject([
        'amount'       => NULL,
        'amount_type'  => 'on_site_quote',
        'tax_included' => NULL,
      ]),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    $this->assertNull($detail['amount']);
    $this->assertSame('on_site_quote', $detail['amount_type']);
    $this->assertNull($detail['tax_included']);
  }

  /**
   * AN OFFER OLDER THAN SPEC 100 IS SERVED WHOLE: the ten quote keys read null
   * — except `requires_visit`, which is false and never null — and the sixteen
   * keys are all still there.
   */
  public function testAnOfferOlderThanSpec100AnswersTheSixteenKeys() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject([
        'amount_type'    => NULL,
        'valid_until'    => NULL,
        'available_from' => NULL,
        'duration'       => NULL,
        'duration_unit'  => NULL,
        'includes'       => NULL,
        'excludes'       => NULL,
        'tax_included'   => NULL,
        'warranty_days'  => NULL,
        'requires_visit' => NULL,
      ]),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    $this->assertSame(self::DETAIL_KEYS, array_keys($detail));

    foreach (['amount_type', 'valid_until', 'available_from', 'duration',
      'includes', 'excludes', 'tax_included', 'warranty_days'] as $key) {
      $this->assertNull($detail[$key], $key . ' predates the field');
    }

    $this->assertFalse($detail['requires_visit'], 'never null: an undeclared visit is no visit');
  }

  /**
   * A SUSPENDED PROVIDER answers `provider: null` — a whole null, never a half
   * object — and the offer is served all the same. It is what `offers` already
   * answers for that same row: one datum, one answer, in every route.
   */
  public function testASuspendedProviderAnswersAWholeNullProvider() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject([
        'provider_id'       => NULL,
        'provider_name'     => NULL,
        'provider_logo_uri' => NULL,
      ]),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    $this->assertSame(self::DETAIL_KEYS, array_keys($detail));
    $this->assertNull($detail['provider']);
  }

  /**
   * `logo` IS null AND NEVER "" when the provider has none — a client that
   * paints an empty string gets a broken image, and a null it can test.
   */
  public function testAProviderWithNoLogoAnswersANullLogo() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject(['provider_logo_uri' => NULL]),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    $this->assertNull($detail['provider']['logo']);
    $this->assertSame(self::PROVIDER_NID, $detail['provider']['id']);
  }

  /**
   * THE TWO ROUTES DIFFER IN TWO KEYS AND IN NOTHING ELSE. The fifteen are
   * identical and so are five of the seven of `request`; what changes is `unit`
   * and `requester`, which is the whole of the visibility contract.
   */
  public function testTheTwoRoutesDifferInExactlyTwoKeys() {
    $offer = $this->offerObject();
    $request = $this->contextRow();

    // The provider, on a request awarded to one of its providers.
    $provider_view = myapi_service_offer_build_detail($offer, $request, TRUE, TRUE);
    // The resident: the unit always, the requester never.
    $resident_view = myapi_service_offer_build_detail($offer, $request, FALSE, TRUE);

    $this->assertSame(array_keys($provider_view), array_keys($resident_view));

    $differ = [];
    foreach ($provider_view as $key => $value) {
      if ($key !== 'request' && $value !== $resident_view[$key]) {
        $differ[] = $key;
      }
    }
    $this->assertSame([], $differ, 'not one of the fifteen changes with the reader');

    foreach ($provider_view['request'] as $key => $value) {
      if ($key === 'requester') {
        continue;
      }
      $this->assertSame($value, $resident_view['request'][$key], $key . ' is the same for both');
    }

    $this->assertNotNull($provider_view['request']['requester']);
    $this->assertNull($resident_view['request']['requester'], 'the resident is the requester');
  }


  /* =========================================================================
   * THE PROVIDER'S ROUTE: GET /api/v1/service-offers/provider/% (step 6).
   *
   * WHAT THIS HALF CANNOT PROVE, and is a manual criterion instead: that
   * hook_menu() resolves the literal 'provider' before the wildcard (that is
   * step 8, and it needs `drush cc all`), and that MySQL evaluates the INNER
   * JOIN to the request the way the fixture evaluator does. What it DOES prove
   * is everything the module decides: the ORDER of the gate, which refusal
   * comes before which query, and the whole visibility contract.
   * ====================================================================== */

  /**
   * A request node row, flat, as myapi_service_request_detail_row()'s joins
   * deliver it.
   */
  private function requestNode(array $overrides = []) {
    return $overrides + [
      'nid'                                    => (string) self::REQUEST_NID,
      'type'                                   => MYAPI_SERVICES_REQUEST_TYPE,
      'status'                                 => '1',
      'title'                                  => 'Fuga en el calentador',
      'created'                                => (string) self::CREATED,
      'fcat.field_category_tid'                => '12',
      'td.name'                                => 'Plomería',
      'cc.field_category_code_value'           => 'plumbing',
      'frs.field_request_status_value'         => 'assigned',
      'fr.field_requester_target_id'           => (string) self::REQUESTER_UID,
      'fd.field_description_value'             => 'El calentador gotea por la base.',
      'fds.field_desired_start_value'          => NULL,
      'fca.field_closed_at_value'              => NULL,
      'nu.nid'                                 => '55',
      'fnv.field_nombre_vivienda_value'        => 'Apto 302',
      'nc.nid'                                 => '7',
      'nc.title'                               => 'Residencial Los Álamos',
      'fma.fid'                                => NULL,
      'fma.filename'                           => NULL,
      'no.nid'                                 => NULL,
      'fos.field_offer_status_value'           => NULL,
      'np.nid'                                 => (string) self::PROVIDER_NID,
      'np.title'                               => 'Plomería Torres',
      'fao.field_assigned_offer_target_id'     => NULL,
      'fap.field_assigned_provider_target_id'  => (string) self::PROVIDER_NID,
    ];
  }

  /**
   * A provider node: published, of the provider bundle.
   */
  private function providerNode($nid = self::PROVIDER_NID, $status = '1', $title = 'Plomería Torres') {
    return [
      'nid'    => (string) $nid,
      'type'   => MYAPI_SERVICES_PROVIDER_TYPE,
      'status' => $status,
      'title'  => $title,
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

  private function tokenRow($uid = self::UID) {
    return [
      'id'                => '1',
      'uid'               => (string) $uid,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * Seeds a whole scenario in one call: every myapi_test_db_seed() replaces the
   * entire fixture, so nothing can be added afterwards.
   */
  private function seed(array $nodes, array $tables = [], $roles = NULL, $uid = self::UID) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;

    $GLOBALS['myapi_test_users'][$uid] = [
      'uid'    => $uid,
      'name'   => 'usuario' . $uid,
      'status' => 1,
      'roles'  => $roles,
    ];

    $tables += [
      'my_api_tokens' => [$this->tokenRow($uid)],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [$this->link(self::PROVIDER_NID)],
      // The requester's row, flat, with the two profile joins already resolved
      // under their qualified sources — the shared "nombre apellidos" rule of
      // includes/myapi.user.inc.
      'users' => [[
        'uid'                     => (string) self::REQUESTER_UID,
        'name'                    => 'mcrespo',
        'fn.field_nombre_value'   => 'María',
        'fa.field_apellidos_value' => 'Crespo',
      ]],
    ];

    $tables['node'] = $nodes;

    myapi_test_db_seed($tables);
    myapi_test_static_reset();
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * The three nodes a happy scenario needs: the offer, its request, the
   * provider.
   */
  private function scenario(array $offer = [], array $request = []) {
    return [
      $this->offerRow($offer),
      $this->requestNode($request),
      $this->providerNode(),
    ];
  }

  private function providerDetail($nid = self::OFFER_NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_offer_provider_item_dispatch((string) $nid);
    });
  }

  private function offer(array $result) {
    return $result['json']['data']['service_offer'];
  }

  /* -------------------------------------------------------------------------
   * Method, nid and authentication.
   * ---------------------------------------------------------------------- */

  /**
   * EVERY METHOD BUT GET IS 405, WITHOUT A TOKEN IN THE REQUEST. The method is
   * wrong whoever is asking, and answering 401 first would tell a client with a
   * broken verb to go fix its credentials.
   */
  public function testEveryMethodButGetIs405BeforeTheToken() {
    $this->seed($this->scenario());

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;
      $result = $this->providerDetail();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
  }

  /**
   * A nid THAT IS NOT A POSITIVE INTEGER IS 404 AND RUNS NO QUERY AT ALL — not
   * even the token's. The answer is about the shape of the URL, not about what
   * exists.
   */
  public function testAMalformedNidIs404WithoutAnyQuery() {
    $this->authenticate();

    foreach (['abc', '0', '-1', '1,2', ' 41', '1.5', ''] as $nid) {
      // Re-seeded per case: myapi_test_db_seed() also clears the query log, and
      // "no query ran" is the assertion this test exists for.
      $this->seed($this->scenario());
      $result = $this->providerDetail($nid);

      $this->assertSame(404, $result['status'], var_export($nid, TRUE));
      $this->assertSame('not_found', $result['json']['error_code'], var_export($nid, TRUE));
      $this->assertSame([], myapi_test_db_queries(), 'no query ran for ' . var_export($nid, TRUE));
    }
  }

  /**
   * No Authorization header is 401 missing_authorization; a token that is not
   * in the table is 401 invalid_token.
   */
  public function testTheTwo401s() {
    $this->seed($this->scenario());

    $result = $this->providerDetail();
    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-token';
    $result = $this->providerDetail();
    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * Authorisation.
   * ---------------------------------------------------------------------- */

  /**
   * AN ACCOUNT WITHOUT THE 'proveedor' ROLE IS 403 provider_role_required,
   * BEFORE ANY QUERY ABOUT OFFERS — and an 'administrator' without the role is
   * refused like anybody else: this is a marketplace actor, not a permission.
   */
  public function testNoProviderRoleIs403BeforeAnyOfferQuery() {
    foreach ([['authenticated user'], ['authenticated user', 'administrator']] as $roles) {
      $this->authenticate();
      $this->seed($this->scenario(), [], $roles);
      $result = $this->providerDetail();

      $this->assertSame(403, $result['status'], implode(',', $roles));
      $this->assertSame('provider_role_required', $result['json']['error_code']);
      $this->assertSame([], myapi_test_db_queries('node'), 'no node was queried');
    }
  }

  /**
   * THE ROLE WITH NO LINKED PROVIDER IS 403 forbidden — not
   * provider_role_required: the role is there, and there is nothing to operate
   * with it, which the scope resolves as "not yours".
   */
  public function testTheRoleWithNoLinkedProviderIs403Forbidden() {
    $this->authenticate();
    $this->seed($this->scenario(), ['field_data_' . MYAPI_PROVIDER_USERS_FIELD => []]);

    $result = $this->providerDetail();

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * ANOTHER PROVIDER'S OFFER IS 403 AND NEVER 404 (decision 6). The module
   * answers 403 to a foreign request already, and a provider debugging its
   * integration has to be able to tell "not yours" from "does not exist".
   */
  public function testAForeignOfferIs403AndNot404() {
    $this->authenticate();
    $this->seed($this->scenario([
      'fp.field_provider_target_id' => (string) self::FOREIGN_PROVIDER,
      'np.nid'                      => (string) self::FOREIGN_PROVIDER,
    ]));

    $result = $this->providerDetail();

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * A SUSPENDED PROVIDER READS ITS OWN OFFER: 200, whole. The licence governs
   * the market — being able to quote — and not the record of what was already
   * quoted, which is why the gate reads the RAW column. `provider` is null
   * because the joined node is out, exactly as `offers` already answers it.
   */
  public function testASuspendedProvidersOwnOfferIs200() {
    $this->authenticate();
    $this->seed([
      $this->offerRow(['np.nid' => NULL, 'np.title' => NULL, 'fml.uri' => NULL]),
      $this->requestNode(),
      $this->providerNode(self::PROVIDER_NID, '0'),
    ]);

    $result = $this->providerDetail();

    $this->assertSame(200, $result['status']);
    $this->assertNull($this->offer($result)['provider']);
    $this->assertSame(self::DETAIL_KEYS, array_keys($this->offer($result)));
  }

  /**
   * AN ACCOUNT WITH TWO PROVIDERS reads the offers of both.
   */
  public function testAnAccountWithTwoProvidersReadsBothArchives() {
    $this->authenticate();
    $this->seed(
      [
        $this->offerRow([
          'fp.field_provider_target_id' => '42',
          'np.nid'                      => '42',
          'np.title'                    => 'Electricidad Rivas',
        ]),
        $this->requestNode(),
        $this->providerNode(42, '1', 'Electricidad Rivas'),
      ],
      ['field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
        $this->link(self::PROVIDER_NID),
        $this->link(42),
      ]]
    );

    $result = $this->providerDetail();

    $this->assertSame(200, $result['status']);
    $this->assertSame(42, $this->offer($result)['provider']['id']);
  }

  /* -------------------------------------------------------------------------
   * The servable set, through the endpoint.
   * ---------------------------------------------------------------------- */

  /**
   * THE FOUR 404s ARE INDISTINGUISHABLE: same code, same message. What the
   * fixture can reach here is three of them — the fourth, an unpublished
   * request, rides in the INNER JOIN and is asserted as a query shape above.
   */
  public function testTheFour404sAreIndistinguishable() {
    $cases = [
      'does not exist' => [$this->requestNode(), $this->providerNode()],
      'unpublished'    => $this->scenario(['status' => '0']),
      'another bundle' => $this->scenario(['type' => MYAPI_SERVICES_PROVIDER_TYPE]),
    ];

    $bodies = [];
    foreach ($cases as $label => $nodes) {
      $this->authenticate();
      $this->seed($nodes);
      $result = $this->providerDetail();

      $this->assertSame(404, $result['status'], $label);
      $bodies[] = $result['json'];
    }

    $this->assertSame([$bodies[0]], array_unique($bodies, SORT_REGULAR), 'the three answer the same body');
  }

  /* -------------------------------------------------------------------------
   * The response, and the visibility rule.
   * ---------------------------------------------------------------------- */

  /**
   * THE JOB IS MINE: `unit` and `requester` travel with their objects, and
   * `requester.name` is the shared "nombre apellidos" rule of SPEC 89.
   */
  public function testAnAwardedRequestShowsTheUnitAndTheRequester() {
    $this->authenticate();
    $this->seed($this->scenario());

    $result = $this->providerDetail();
    $offer = $this->offer($result);

    $this->assertSame(200, $result['status']);
    $this->assertSame(self::DETAIL_KEYS, array_keys($offer));
    $this->assertSame(self::CONTEXT_KEYS, array_keys($offer['request']));
    $this->assertSame(['id' => 55, 'name' => 'Apto 302'], $offer['request']['unit']);
    $this->assertSame(['id' => self::REQUESTER_UID, 'name' => 'María Crespo'], $offer['request']['requester']);
    $this->assertSame(['id' => 7, 'name' => 'Residencial Los Álamos'], $offer['request']['condominium']);
  }

  /**
   * NOT AWARDED, OR AWARDED TO SOMEBODY ELSE: `unit` and `requester` are whole
   * nulls — and they stay null even when MY offer sits at 'selected', because
   * the award decides and not the offer (decision 5). `condominium` travels all
   * the same (decision 11).
   */
  public function testAnUnawardedOrForeignAwardHidesTheUnitAndTheRequester() {
    $cases = [
      'unawarded' => ['np.nid' => NULL, 'fap.field_assigned_provider_target_id' => NULL],
      'awarded to another' => [
        'np.nid' => (string) self::FOREIGN_PROVIDER,
        'fap.field_assigned_provider_target_id' => (string) self::FOREIGN_PROVIDER,
      ],
      'broken award' => [
        'np.nid' => NULL,
        'fap.field_assigned_provider_target_id' => (string) self::PROVIDER_NID,
      ],
    ];

    foreach ($cases as $label => $request) {
      $this->authenticate();
      $this->seed($this->scenario(['fost.field_offer_status_value' => 'selected'], $request));

      $offer = $this->offer($this->providerDetail());

      $this->assertSame(self::CONTEXT_KEYS, array_keys($offer['request']), $label);
      $this->assertNull($offer['request']['unit'], $label);
      $this->assertNull($offer['request']['requester'], $label);
      $this->assertSame(
        ['id' => 7, 'name' => 'Residencial Los Álamos'],
        $offer['request']['condominium'],
        $label . ': the condominium travels always'
      );
    }
  }

  /**
   * THE JOB IS MINE EVEN WHEN MY OFFER LOST. An offer at 'rejected' or
   * 'withdrawn' on a request awarded to one of my providers still opens the
   * address: the work is mine either way.
   */
  public function testARejectedOfferOnAJobOfMineStillShowsTheAddress() {
    foreach (['rejected', 'withdrawn'] as $status) {
      $this->authenticate();
      $this->seed($this->scenario(['fost.field_offer_status_value' => $status]));

      $offer = $this->offer($this->providerDetail());

      $this->assertSame($status, $offer['status']);
      $this->assertSame(['id' => 55, 'name' => 'Apto 302'], $offer['request']['unit'], $status);
      $this->assertNotNull($offer['request']['requester'], $status);
    }
  }

  /**
   * NOT ONE DATUM OF THE REQUEST'S DETAIL, OF THE COMPETITION OR OF ANYBODY'S
   * CONTACT travels — in the response or inside `request`.
   */
  public function testTheResponseCarriesNothingItWasNotAskedFor() {
    $this->authenticate();
    $this->seed($this->scenario());

    $offer = $this->offer($this->providerDetail());
    $flat = json_encode($offer);

    foreach (['description', 'desired_start', 'closed_at', 'offers_count',
      'assigned_offer', 'assigned_provider', 'images', 'attachment',
      'transactions', 'viewer', 'phone', 'email', 'address'] as $key) {
      $this->assertStringNotContainsString('"' . $key . '"', $flat, $key . ' is out of scope');
    }
  }


  /* =========================================================================
   * THE RESIDENT'S ROUTE: GET /api/v1/service-offers/% (step 7).
   * ====================================================================== */

  private function requesterDetail($nid = self::OFFER_NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_offer_item_dispatch((string) $nid);
    });
  }

  /**
   * Seeds the scenario as the REQUESTER: the token belongs to the resident, who
   * holds no special role at all.
   */
  private function seedAsRequester(array $offer = [], array $request = [], $roles = NULL) {
    $roles = $roles === NULL ? ['authenticated user'] : $roles;

    $this->seed($this->scenario($offer, $request), [], $roles, self::REQUESTER_UID);
  }

  /**
   * EVERY METHOD BUT GET AND PUT IS 405, WITHOUT A TOKEN IN THE REQUEST.
   *
   * PUT LEFT THIS LIST IN SPEC 105, and it is the one line of this suite that
   * spec changed: the route now carries TWO ACTORS — the GET is the resident's,
   * unchanged and still asserted whole below, and the PUT is the provider's
   * edit. What the PUT answers is ServiceOfferUpdateTest's, gate included;
   * what stays here is that the other three are still refused, and still
   * before the token.
   */
  public function testTheResidentsRouteRefusesEveryMethodButGetAndPutBeforeTheToken() {
    $this->seedAsRequester();

    foreach (['POST', 'PATCH', 'DELETE'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;
      $result = $this->requesterDetail();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
  }

  /**
   * A malformed nid is 404 and runs no query, the token's included.
   */
  public function testTheResidentsRouteRefusesAMalformedNidWithoutQuerying() {
    $this->authenticate();

    foreach (['abc', '0', '-1', '1,2', ' 41'] as $nid) {
      $this->seedAsRequester();
      $result = $this->requesterDetail($nid);

      $this->assertSame(404, $result['status'], var_export($nid, TRUE));
      $this->assertSame('not_found', $result['json']['error_code']);
      $this->assertSame([], myapi_test_db_queries());
    }
  }

  public function testTheResidentsRouteAnswersTheTwo401s() {
    $this->seedAsRequester();

    $result = $this->requesterDetail();
    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-token';
    $result = $this->requesterDetail();
    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * THE REQUESTER READS IT, WITH NO SPECIAL ROLE AT ALL (decision 13): what
   * grants access is not a label on the account but a fact about the data.
   * `unit` travels — it is their home — and `requester` is null, which is
   * deliberate and not an oversight.
   */
  public function testTheRequesterReadsTheOfferWithNoRoleAtAll() {
    $this->authenticate();
    $this->seedAsRequester();

    $result = $this->requesterDetail();
    $offer = $this->offer($result);

    $this->assertSame(200, $result['status']);
    $this->assertSame(self::DETAIL_KEYS, array_keys($offer));
    $this->assertSame(self::CONTEXT_KEYS, array_keys($offer['request']));
    $this->assertSame(['id' => 55, 'name' => 'Apto 302'], $offer['request']['unit']);
    $this->assertNull($offer['request']['requester'], 'the reader IS the requester');
    $this->assertSame(['id' => 7, 'name' => 'Residencial Los Álamos'], $offer['request']['condominium']);
  }

  /**
   * `unit` TRAVELS WHATEVER THE AWARD SAYS: the `mine` rule exists to keep a
   * resident's address away from a provider who is not going to that house, and
   * the resident lives there.
   */
  public function testTheResidentSeesTheUnitOnAnUnawardedRequest() {
    $this->authenticate();
    $this->seedAsRequester([], [
      'np.nid' => NULL,
      'fap.field_assigned_provider_target_id' => NULL,
      'frs.field_request_status_value' => 'offered',
    ]);

    $offer = $this->offer($this->requesterDetail());

    $this->assertSame(['id' => 55, 'name' => 'Apto 302'], $offer['request']['unit']);
    $this->assertNull($offer['request']['requester']);
  }

  /**
   * A request with NO UNIT answers a whole null, and the seven keys stay.
   */
  public function testAResidentsRequestWithNoUnitAnswersAWholeNull() {
    $this->authenticate();
    $this->seedAsRequester([], ['nu.nid' => NULL, 'fnv.field_nombre_vivienda_value' => NULL]);

    $offer = $this->offer($this->requesterDetail());

    $this->assertSame(self::CONTEXT_KEYS, array_keys($offer['request']));
    $this->assertNull($offer['request']['unit']);
  }

  /**
   * A PROVIDER WHO BID IS 403 HERE, AND SO IS THE AWARDED ONE.
   * myapi_service_request_viewer() answers 'provider' for both, and 'provider'
   * is not 'requester': for them there is the other route.
   */
  public function testAProviderIs403OnTheResidentsRoute() {
    $this->authenticate();
    // The account of the provider: it holds the role AND the award, which is
    // rule 2b of the viewer — the strongest 'provider' verdict there is.
    $this->seed($this->scenario(), [], NULL, self::UID);

    // Precondition: the verdict really is 'provider' and not NULL, so the 403
    // below is the awarded provider's and not a stranger's.
    $this->assertSame(
      'provider',
      myapi_service_request_viewer(myapi_service_request_detail_row(self::REQUEST_NID), self::UID)
    );

    $result = $this->requesterDetail();

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * AN ACCOUNT WITH NO RELATION TO THE REQUEST IS 403: the viewer answers NULL
   * and NULL is not 'requester'.
   */
  public function testAStrangerIs403OnTheResidentsRoute() {
    $this->authenticate();
    $this->seed($this->scenario(), [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [],
    ], ['authenticated user'], 999);

    // Precondition: no rule of the viewer fits this reader at all.
    $this->assertNull(
      myapi_service_request_viewer(myapi_service_request_detail_row(self::REQUEST_NID), 999)
    );

    $result = $this->requesterDetail();

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * THE SERVABLE SET IS THE SAME ON BOTH ROUTES — "which offers exist" cannot
   * depend on who is asking — and the 404 comes BEFORE the 403: a stranger
   * asking for an offer that does not exist is told it does not exist, exactly
   * as the requester is.
   */
  public function testTheResidentsRouteAnswers404OnTheSameCases() {
    $cases = [
      'does not exist' => [$this->requestNode(), $this->providerNode()],
      'unpublished'    => $this->scenario(['status' => '0']),
      'another bundle' => $this->scenario(['type' => MYAPI_SERVICES_PROVIDER_TYPE]),
    ];

    foreach ($cases as $label => $nodes) {
      $this->authenticate();
      $this->seed($nodes, [], ['authenticated user'], self::REQUESTER_UID);
      $result = $this->requesterDetail();

      $this->assertSame(404, $result['status'], $label);
      $this->assertSame('not_found', $result['json']['error_code'], $label);
    }
  }

  /**
   * THE TWO ROUTES ANSWER THE SAME FIFTEEN KEYS FOR THE SAME OFFER, and differ
   * only in `request.unit` and `request.requester`. It is the whole contract of
   * this spec, asserted end to end and not over a pure function.
   */
  public function testBothRoutesAnswerTheSameOfferAndDifferInTwoKeysOnly() {
    $this->authenticate();
    $this->seed($this->scenario());
    $provider_view = $this->offer($this->providerDetail());

    $this->authenticate();
    $this->seedAsRequester();
    $resident_view = $this->offer($this->requesterDetail());

    $this->assertSame(array_keys($provider_view), array_keys($resident_view));

    foreach ($provider_view as $key => $value) {
      if ($key === 'request') {
        continue;
      }
      $this->assertSame($value, $resident_view[$key], $key . ' is the same in both routes');
    }

    foreach (['id', 'title', 'status', 'category', 'condominium', 'unit'] as $key) {
      $this->assertSame(
        $provider_view['request'][$key],
        $resident_view['request'][$key],
        'request.' . $key . ' is the same in both routes'
      );
    }

    $this->assertNotNull($provider_view['request']['requester']);
    $this->assertNull($resident_view['request']['requester']);
  }


  /* =========================================================================
   * THE ROUTES, as hook_menu() declares them (step 8).
   *
   * Drupal's router is not run in tests/unit, so what is asserted is the
   * DECLARATION. That the literal 'provider' wins over the wildcard is core
   * behaviour and a MANUAL criterion of the spec, verified with `drush cc all` in
   * between; what a test can hold is that the declaration says what it must.
   * ====================================================================== */

  private function moduleSource() {
    return file_get_contents(__DIR__ . '/../../myapi.module');
  }

  /**
   * THE PROVIDER'S ROUTE TAKES THE FIFTH COMPONENT. 'page arguments' => [4] and
   * never [3]: the wildcard is api / v1 / service-offers / provider / %, and a
   * [3] would hand the dispatcher the literal 'provider' — which would try to
   * resolve a provider nid as if it were an offer.
   */
  public function testTheProvidersRouteIsDeclaredWithTheFifthComponent() {
    $this->assertMatchesRegularExpression(
      '/\$items\[\'api\/v1\/service-offers\/provider\/%\'\]\s*=\s*\[\s*'
      . '\'page callback\'\s*=>\s*\'myapi_service_offer_provider_item_dispatch\',\s*'
      . '\'page arguments\'\s*=>\s*\[4\],/',
      $this->moduleSource()
    );
  }

  /**
   * THE RESIDENT'S ROUTE TAKES THE FOURTH. 'page arguments' => [3]: the
   * wildcard is api / v1 / service-offers / %.
   */
  public function testTheResidentsRouteIsDeclaredWithTheFourthComponent() {
    $this->assertMatchesRegularExpression(
      '/\$items\[\'api\/v1\/service-offers\/%\'\]\s*=\s*\[\s*'
      . '\'page callback\'\s*=>\s*\'myapi_service_offer_item_dispatch\',\s*'
      . '\'page arguments\'\s*=>\s*\[3\],/',
      $this->moduleSource()
    );
  }

  /**
   * THE THREE ROUTES OF THE PREFIX COEXIST, AND THE LISTING KEEPS ITS OWN
   * CALLBACK. The symptom of an error here is not a 404 — it is the listing
   * starting to answer a detail, which is riesgo 2 of the spec.
   */
  public function testTheThreeRoutesOfThePrefixCoexist() {
    $module = $this->moduleSource();

    $routes = [
      "\$items['api/v1/service-offers/provider']"   => 'myapi_service_offer_provider_dispatch',
      "\$items['api/v1/service-offers/provider/%']" => 'myapi_service_offer_provider_item_dispatch',
      "\$items['api/v1/service-offers/%']"          => 'myapi_service_offer_item_dispatch',
    ];

    foreach ($routes as $route => $callback) {
      $this->assertStringContainsString($route, $module, $route . ' is declared');
      $this->assertStringContainsString("'page callback'    => '" . $callback . "'", $module);
    }

    // The listing has NO 'page arguments': it takes no component.
    $this->assertMatchesRegularExpression(
      '/\$items\[\'api\/v1\/service-offers\/provider\'\]\s*=\s*\[\s*'
      . '\'page callback\'\s*=>\s*\'myapi_service_offer_provider_dispatch\',\s*'
      . '\'access callback\'/',
      $module,
      'the listing takes no page argument'
    );
  }

  /**
   * BOTH ROUTES ARE MENU_CALLBACK WITH 'access callback' => TRUE and point at
   * the offer resource: Drupal's access layer is not the one deciding here —
   * the token is checked inside the dispatcher, and who may read this offer is
   * decided by the two gates of the spec.
   */
  public function testBothRoutesDeferAccessToTheDispatcher() {
    $module = $this->moduleSource();

    foreach (['api/v1/service-offers/provider/%', 'api/v1/service-offers/%'] as $route) {
      $start = strpos($module, "\$items['" . $route . "']");
      $this->assertNotFalse($start, $route);
      $block = substr($module, $start, strpos($module, '];', $start) - $start);

      $this->assertStringContainsString("'access callback'  => TRUE", $block, $route);
      $this->assertStringContainsString("'type'             => MENU_CALLBACK", $block, $route);
      $this->assertStringContainsString("'file'             => 'resources/service_offer.resource.inc'", $block, $route);
    }
  }


  /* -------------------------------------------------------------------------
   * The remaining acceptance criteria of section 5.
   * ---------------------------------------------------------------------- */

  /**
   * A PROVIDER WHOSE `field_license_expiry` IS IN THE PAST READS ITS OWN OFFER:
   * 200. The licence governs the market — being able to quote — and not the
   * record of what was already quoted. Neither the query nor the gate reads
   * that field, and this is what holds that true.
   */
  public function testAnExpiredLicenceStillReadsItsOwnOffer() {
    $this->authenticate();
    $this->seed(
      $this->scenario(),
      ['field_data_field_license_expiry' => [[
        'entity_id'                  => (string) self::PROVIDER_NID,
        'entity_type'                => 'node',
        'deleted'                    => '0',
        'field_license_expiry_value' => (string) (REQUEST_TIME - 86400),
      ]]]
    );

    $result = $this->providerDetail();

    $this->assertSame(200, $result['status']);
    $this->assertSame(self::DETAIL_KEYS, array_keys($this->offer($result)));
  }

  /**
   * THE REQUESTER OF THE REQUEST, EVEN HOLDING THE 'proveedor' ROLE, DOES NOT
   * GET IN THROUGH THE PROVIDER'S ROUTE to an offer that is not one of their
   * providers'. Being the customer is the other route; this one asks whose
   * offer it is and nothing else.
   */
  public function testTheRequesterWithTheRoleIsStill403OnAForeignOffer() {
    $this->authenticate();
    $this->seed(
      $this->scenario([
        'fp.field_provider_target_id' => (string) self::FOREIGN_PROVIDER,
        'np.nid'                      => (string) self::FOREIGN_PROVIDER,
      ]),
      ['field_data_' . MYAPI_PROVIDER_USERS_FIELD => []],
      ['authenticated user', MYAPI_PROVIDER_ROLE],
      self::REQUESTER_UID
    );

    $result = $this->providerDetail();

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * A PROVIDER WHO MERELY BID — no award anywhere — IS 403 ON THE RESIDENT'S
   * ROUTE. myapi_service_request_viewer() answers 'provider' by its rule 2, and
   * 'provider' is not 'requester'.
   */
  public function testAProviderWhoOnlyBidIs403OnTheResidentsRoute() {
    $this->authenticate();
    $this->seed(
      $this->scenario([], [
        'np.nid' => NULL,
        'fap.field_assigned_provider_target_id' => NULL,
        'frs.field_request_status_value' => 'offered',
      ]),
      // The offer of one of my providers on that request: rule 2 of the viewer.
      ['field_data_field_request' => [[
        'entity_id'                   => (string) self::OFFER_NID,
        'entity_type'                 => 'node',
        'deleted'                     => '0',
        'field_request_target_id'     => (string) self::REQUEST_NID,
        'fp.field_provider_target_id' => (string) self::PROVIDER_NID,
      ]]]
    );

    // Precondition: this reader really is a 'provider' by rule 2 — having bid,
    // with no award anywhere. Without it the 403 below could be the 403 of a
    // stranger, which is a different criterion.
    $this->assertSame(
      'provider',
      myapi_service_request_viewer(myapi_service_request_detail_row(self::REQUEST_NID), self::UID)
    );

    $result = $this->requesterDetail();

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
  }

  /**
   * `message` IS "" WHEN EMPTY AND NEVER null. It is the one REQUIRED text of
   * the fifteen: an empty message is a corrupt row, not an absence, and the two
   * must not read alike — every other optional text answers null for "".
   */
  public function testAnEmptyMessageIsAnEmptyStringAndNeverNull() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject(['message' => '', 'includes' => '']),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    $this->assertSame('', $detail['message'], 'required: "" is not an absence');
    $this->assertNull($detail['includes'], 'optional: "" IS an absence');
  }

  /**
   * `tax_included` TELLS THE THREE APART: true, false and "never declared". It
   * is the only three-valued key of the fifteen.
   */
  public function testTaxIncludedTellsTrueFalseAndUndeclaredApart() {
    $cases = ['1' => TRUE, '0' => FALSE, NULL => NULL];

    foreach (['1', '0', NULL] as $stored) {
      $detail = myapi_service_offer_build_detail(
        $this->offerObject(['tax_included' => $stored]),
        $this->contextRow(),
        TRUE,
        TRUE
      );

      $this->assertSame($cases[$stored], $detail['tax_included'], var_export($stored, TRUE));
    }
  }

  /**
   * `duration` IS A WHOLE OBJECT OR A WHOLE null, NEVER {value: null,
   * unit: null}. The two columns are coupled — one without the other means
   * nothing — so a row carrying only one of them answers null.
   */
  public function testHalfADurationIsAWholeNull() {
    foreach ([['2', NULL], [NULL, 'hours'], [NULL, NULL]] as $case) {
      $detail = myapi_service_offer_build_detail(
        $this->offerObject(['duration' => $case[0], 'duration_unit' => $case[1]]),
        $this->contextRow(),
        TRUE,
        TRUE
      );

      $this->assertNull($detail['duration'], var_export($case, TRUE));
    }

    $whole = myapi_service_offer_build_detail(
      $this->offerObject(),
      $this->contextRow(),
      TRUE,
      TRUE
    );
    $this->assertSame(['value' => 2, 'unit' => 'hours'], $whole['duration']);
  }

  /**
   * THE THREE DATES ARE 'Y-m-d\TH:i:s', with no timezone suffix and no
   * milliseconds — the same shape every other date of this module answers.
   */
  public function testTheThreeDatesShareTheOneFormat() {
    $detail = myapi_service_offer_build_detail(
      $this->offerObject(),
      $this->contextRow(),
      TRUE,
      TRUE
    );

    foreach (['created', 'valid_until', 'available_from'] as $key) {
      $this->assertMatchesRegularExpression(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
        $detail[$key],
        $key
      );
    }
  }

  /**
   * AN OFFER 'withdrawn' ON A REQUEST 'cancelled' IS SERVED WHOLE, through the
   * endpoint: no condition of the servable set looks at either status key.
   */
  public function testAWithdrawnOfferOnACancelledRequestIsServedThroughTheEndpoint() {
    $this->authenticate();
    $this->seed($this->scenario(
      ['fost.field_offer_status_value' => 'withdrawn'],
      ['frs.field_request_status_value' => 'cancelled']
    ));

    $result = $this->providerDetail();
    $offer = $this->offer($result);

    $this->assertSame(200, $result['status']);
    $this->assertSame(self::DETAIL_KEYS, array_keys($offer));
    $this->assertSame('withdrawn', $offer['status']);
    $this->assertSame('cancelled', $offer['request']['status']);
  }

  /**
   * AN OFFER ON A 'direct' REQUEST IS SERVED WITH NOTHING DIFFERENT ABOUT IT.
   * A direct request has a provider and no bidding round; that changes what the
   * request says about itself, and nothing about how this endpoint answers.
   */
  public function testAnOfferOnADirectRequestIsServedLikeAnyOther() {
    $this->authenticate();
    $this->seed($this->scenario([], ['frs.field_request_status_value' => 'direct']));

    $result = $this->providerDetail();
    $offer = $this->offer($result);

    $this->assertSame(200, $result['status']);
    $this->assertSame(self::DETAIL_KEYS, array_keys($offer));
    $this->assertSame(self::CONTEXT_KEYS, array_keys($offer['request']));
    $this->assertSame('direct', $offer['request']['status']);
    $this->assertSame(['id' => 55, 'name' => 'Apto 302'], $offer['request']['unit']);
  }

  /**
   * `request.id` IS THE NID OF THE JOINED NODE and never the raw target_id of
   * field_request. An offer whose reference points at a deleted node answered
   * 404 long before this, in the INNER JOIN.
   */
  public function testTheRequestIdIsTheJoinedNodesNid() {
    $this->seedNodes([$this->offerRow([
      // The raw reference says one thing, the joined node says another. Only
      // MySQL could produce this, and the point is which column is read.
      'fq.field_request_target_id' => '999',
      'nr.nid'                     => (string) self::REQUEST_NID,
    ])]);

    $row = myapi_service_offer_detail_row(self::OFFER_NID);

    $this->assertSame((string) self::REQUEST_NID, (string) $row->request_id);
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
