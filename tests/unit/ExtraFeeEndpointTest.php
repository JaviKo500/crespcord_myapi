<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/extra_fee.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/units/%/extra-fees (SPEC 13, covered
 * by SPEC 121).
 *
 * THE TWIN OF receipts, AND WHY IT IS NOT A DATA PROVIDER ON IT. The two
 * resources are the same listing written twice — the same access rule, the
 * same page/limit/sort parse, the same estado filter, the same date-range
 * parse — and PaginationUnlimitedTest already covers the one axis where
 * keeping them identical is the whole point. What this class covers is the
 * half where they are NOT identical and are not supposed to be: a different
 * node type, a different date column (field_fecha, not field_periodo), nine
 * response keys instead of forty, and one mapping rule receipts does not have
 * — `details` is "" when absent, where every other absent field is null.
 *
 * That last rule is the reason a shared provider would have been wrong: it
 * would have had to be written as an exception, and an exception in a shared
 * test is where a real divergence hides.
 *
 * Same fixture contract as ReceiptEndpointTest: flat rows, joins recorded and
 * not resolved, 'fest.field_estado_value' written qualified so one row can
 * hold both the estado value and node's published flag.
 */
class ExtraFeeEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';
  const UNIT = 45;
  const UID = 3;
  const EXPOSED = 'Enviado';

  protected function setUp(): void {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One 'alicuota_extra' row, carrying both halves of every joined column.
   */
  private function feeRow(array $spec) {
    $spec += [
      'status'    => self::EXPOSED,
      'unit'      => self::UNIT,
      'published' => '1',
      'date'      => NULL,
      'values'    => [],
    ];

    $row = [
      'nid'                      => (string) $spec['id'],
      'title'                    => 'Alícuota extra ' . $spec['id'],
      'type'                     => 'alicuota_extra',
      'status'                   => (string) $spec['published'],
      'field_vivienda_target_id' => (string) $spec['unit'],
      'unit_id'                  => (string) $spec['unit'],
      'fest.field_estado_value'  => $spec['status'],
      'field_fecha_value'        => $spec['date'],
      'date'                     => $spec['date'],
    ];

    return $spec['values'] + $row;
  }

  private function seed(array $fees, array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $rows = [];
    foreach ($fees as $spec) {
      $rows[] = $this->feeRow($spec);
    }

    myapi_test_db_seed($tables + [
      'my_api_tokens' => [[
        'id'                => '1',
        'uid'               => (string) self::UID,
        'access_token_hash' => myapi_token_hash(self::TOKEN),
        'revoked'           => '0',
        'access_expires_at' => REQUEST_TIME + 1800,
      ]],
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'node' => $rows,
    ]);
  }

  private function request($unit_id = self::UNIT) {
    return myapi_test_capture(function () use ($unit_id) {
      myapi_extra_fee_dispatch($unit_id);
    });
  }

  private function ids(array $result) {
    return array_column($result['json']['data']['extra_fees'], 'id');
  }

  private function consecutive($count) {
    $fees = [];
    for ($i = 1; $i <= $count; $i++) {
      $fees[] = ['id' => $i, 'date' => sprintf('2026-06-%02d', $i)];
    }

    return $fees;
  }

  /* -------------------------------------------------------------------------
   * Routing and the two guards.
   * ---------------------------------------------------------------------- */

  /**
   * Every verb other than GET is 405, and the rejection runs no query.
   */
  public function testEveryMethodOtherThanGetIs405AndRunsNoQuery() {
    $this->seed($this->consecutive(1));

    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->request();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame([], myapi_test_db_queries(), $method);
    }
  }

  /**
   * A lowercase verb is still a GET.
   */
  public function testLowercaseGetIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';
    $this->seed($this->consecutive(1));

    $this->assertSame(200, $this->request()['status']);
  }

  /**
   * Every way of failing the token is a 401 that never reaches the fees.
   */
  public function testEveryFailingTokenIs401AndNeverReachesTheFees() {
    $this->seed($this->consecutive(1));
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $result = $this->request();
    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries());

    $this->seed($this->consecutive(1));
    $GLOBALS['myapi_test_db']['my_api_tokens'] = [];
    $result = $this->request();
    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries('node'));

    $this->seed($this->consecutive(1));
    $GLOBALS['myapi_test_db']['my_api_tokens'][0]['revoked'] = '1';
    $this->assertSame(401, $this->request()['status']);

    $this->seed($this->consecutive(1));
    $GLOBALS['myapi_test_db']['my_api_tokens'][0]['access_expires_at'] = REQUEST_TIME - 1;
    $this->assertSame(401, $this->request()['status']);

    $this->seed($this->consecutive(1));
    $GLOBALS['myapi_test_users'] = [];
    $this->assertSame(401, $this->request()['status']);
  }

  /**
   * A foreign unit and a non-existent one are the same 403, and neither
   * queries the fees.
   */
  public function testAForeignOrMissingUnitIsTheSame403() {
    $this->seed($this->consecutive(1), [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '77', 'field_propietario_target_id' => '900', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $foreign = $this->request(77);
    $missing = $this->request(4242);

    $this->assertSame(403, $foreign['status']);
    $this->assertSame('unit_access_denied', $foreign['json']['error_code']);
    $this->assertSame($foreign['output'], $missing['output']);
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /**
   * The occupant of a unit sees its extra fees, through either occupant field.
   */
  public function testOccupantsSeeTheFeesOfTheirUnit() {
    foreach ([
      'field_data_field_ocupante'  => 'field_ocupante_target_id',
      'field_data_field_ocupantes' => 'field_ocupantes_target_id',
    ] as $table => $column) {
      $this->seed($this->consecutive(1), [
        'field_data_field_propietario' => [],
        $table => [['entity_id' => (string) self::UNIT, $column => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node']],
      ]);

      $this->assertSame(200, $this->request()['status'], $table);
    }
  }

  /* -------------------------------------------------------------------------
   * The scope of the listing.
   * ---------------------------------------------------------------------- */

  /**
   * Only fees of THIS type, published, in the exposed state and belonging to
   * the unit in the route are listed. Each excluded row is excluded by a
   * different condition, and all four are asserted at once because a resource
   * that lost any one of them still answers a plausible 200.
   */
  public function testTheFourConditionsOfTheListingAllHold() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => '2026-06-02', 'status' => 'Borrador'],
      ['id' => 3, 'date' => '2026-06-03', 'published' => '0'],
      ['id' => 4, 'date' => '2026-06-04', 'unit' => 77],
    ]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'recibo'] + $this->feeRow(['id' => 5, 'date' => '2026-06-05']);

    $result = $this->request();

    $this->assertSame([1], $this->ids($result));
    $this->assertSame(1, $result['json']['data']['pagination']['total']);
  }

  /**
   * A fee with no estado row at all is invisible: the estado join is an INNER
   * one.
   */
  public function testAFeeWithNoEstadoRowIsInvisible() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => '2026-06-02', 'status' => NULL],
    ]);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * The answered `status` is the estado value and not node's published flag.
   */
  public function testTheAnsweredStatusIsTheExposedState() {
    $this->seed($this->consecutive(1));

    $this->assertSame(self::EXPOSED, $this->request()['json']['data']['extra_fees'][0]['status']);
  }

  /* -------------------------------------------------------------------------
   * The query shape.
   * ---------------------------------------------------------------------- */

  /**
   * One count and one fetch, the count without a range, and the fetch ordered
   * by the fecha column — NOT by the periodo column of its twin.
   */
  public function testTheQueryShapeIsTheDocumentedOne() {
    $this->seed($this->consecutive(2));

    $this->request();

    $queries = myapi_test_db_queries('node');
    $this->assertCount(2, $queries);
    $this->assertTrue($queries[0]['count']);
    $this->assertNull($queries[0]['range']);

    $order = $queries[1]['order'];
    $this->assertCount(1, $order);
    $this->assertSame('ffec.field_fecha_value', $order[0]['field']);
    $this->assertSame('DESC', $order[0]['direction']);

    $values = array_column($queries[1]['conditions'], 'value', 'field');
    $this->assertSame('alicuota_extra', $values['n.type']);
    $this->assertSame(self::EXPOSED, $values['fest.field_estado_value']);
  }

  /* -------------------------------------------------------------------------
   * Pagination, sorting and the date range.
   * ---------------------------------------------------------------------- */

  /**
   * The documented defaults.
   */
  public function testTheDocumentedDefaults() {
    $this->seed($this->consecutive(3));

    $this->assertSame(
      ['total' => 3, 'page' => 1, 'limit' => 20, 'total_pages' => 1],
      $this->request()['json']['data']['pagination']
    );
  }

  /**
   * An empty unit is a 200 with an empty array and total_pages 0.
   */
  public function testAnEmptyUnitIsAnEmptyTwoHundred() {
    $this->seed([]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['extra_fees']);
    $this->assertSame(0, $result['json']['data']['pagination']['total_pages']);
    $this->assertStringContainsString('"extra_fees":[]', $result['output']);
  }

  /**
   * total_pages is the ceiling of the division, and the page slices.
   */
  public function testPaginationSlicesAndCountsTheWholeSet() {
    $this->seed($this->consecutive(7));
    $_GET['limit'] = '3';

    $_GET['page'] = '1';
    $first = $this->request();
    $_GET['page'] = '3';
    $third = $this->request();

    $this->assertSame(3, $first['json']['data']['pagination']['total_pages']);
    $this->assertSame(7, $first['json']['data']['pagination']['total']);
    $this->assertSame([7, 6, 5], $this->ids($first));
    $this->assertSame([1], $this->ids($third));
  }

  /**
   * limit is clamped to [1, 50] and page falls back to 1, silently.
   */
  public function testLimitIsClampedAndPageFallsBack() {
    $this->seed($this->consecutive(1));

    foreach (['0' => 20, '-5' => 20, 'abc' => 20, '51' => 50, '7' => 7] as $sent => $expected) {
      $_GET = ['limit' => (string) $sent];
      $this->assertSame($expected, $this->request()['json']['data']['pagination']['limit'], 'limit=' . $sent);
    }

    foreach (['0', '-1', 'abc', ''] as $sent) {
      $_GET = ['page' => $sent];
      $this->assertSame(1, $this->request()['json']['data']['pagination']['page'], 'page=' . $sent);
    }
  }

  /**
   * The default order is newest first and ?sort=asc reverses it; any other
   * value falls back to desc.
   */
  public function testSortingFollowsTheDocumentedRule() {
    $this->seed($this->consecutive(3));

    $this->assertSame([3, 2, 1], $this->ids($this->request()));

    $_GET['sort'] = 'asc';
    $this->assertSame([1, 2, 3], $this->ids($this->request()));

    foreach (['ASC', 'Desc', 'fecha', '', ['asc']] as $value) {
      $_GET['sort'] = $value;
      $this->assertSame([3, 2, 1], $this->ids($this->request()), json_encode($value));
    }
  }

  /**
   * Both bounds are inclusive, filter on field_fecha, and narrow the count.
   */
  public function testTheDateRangeFiltersAndNarrowsTheCount() {
    $this->seed($this->consecutive(6));
    $_GET['date_from'] = '2026-06-02';
    $_GET['date_to'] = '2026-06-04';

    $result = $this->request();

    $this->assertSame([4, 3, 2], $this->ids($result));
    $this->assertSame(3, $result['json']['data']['pagination']['total']);
  }

  /**
   * A malformed bound is ignored, and an inverted range drops both bounds.
   */
  public function testMalformedAndInvertedRangesAreIgnored() {
    $this->seed($this->consecutive(3));

    foreach (['2026-13-40', '01-06-2026', 'hoy', '2026-02-30', ''] as $value) {
      $_GET = ['date_from' => $value];
      $this->assertSame([3, 2, 1], $this->ids($this->request()), $value);
    }

    $_GET = ['date_from' => '2026-06-30', 'date_to' => '2026-06-01'];
    $this->assertSame([3, 2, 1], $this->ids($this->request()));
  }

  /**
   * A fee with no date is excluded the moment a bound is given, and listed
   * when none is.
   */
  public function testAFeeWithNoDateIsExcludedOnlyWhenABoundIsGiven() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => NULL],
    ]);

    $this->assertSame([1, 2], $this->ids($this->request()));

    $_GET['date_to'] = '2026-12-31';
    $this->assertSame([1], $this->ids($this->request()));
  }

  /* -------------------------------------------------------------------------
   * The mapper: nine keys and the one rule receipts does not have.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly the nine documented keys, in order.
   */
  public function testTheItemHasExactlyTheNineDocumentedKeysInOrder() {
    $this->seed([['id' => 1, 'date' => '2026-06-01']]);

    $item = $this->request()['json']['data']['extra_fees'][0];

    $this->assertSame(
      ['id', 'title', 'unit_id', 'date', 'status', 'extra_fee', 'previous_balance', 'total', 'details'],
      array_keys($item)
    );
  }

  /**
   * id and unit_id are ints; the three decimals are floats when stored and
   * null when absent.
   */
  public function testTheCastsOfTheMapper() {
    $this->seed([['id' => 501, 'date' => '2026-06-01', 'values' => [
      'extra_fee'        => '25.50',
      'previous_balance' => '-10.00',
      'total'            => '15.50',
    ]]]);

    $item = $this->request()['json']['data']['extra_fees'][0];

    $this->assertSame(501, $item['id']);
    $this->assertSame(self::UNIT, $item['unit_id']);
    $this->assertSame(25.5, $item['extra_fee']);
    $this->assertSame(15.5, $item['total']);
    // A float with no fractional part is written as a bare `-10` by
    // drupal_json_encode() — JSON has one number type — so the round trip
    // answers an int. What is pinned is the VALUE and the sign; the float cast
    // itself is asserted on the raw item in ExtraFeeEndpointTest's mapper
    // cases and, exhaustively, in ReceiptBuildItemTest.
    $this->assertEquals(-10.0, $item['previous_balance']);
  }

  /**
   * An absent decimal is null and never zero.
   */
  public function testAnAbsentDecimalIsNull() {
    $this->seed([['id' => 1, 'date' => '2026-06-01']]);

    $result = $this->request();
    $item = $result['json']['data']['extra_fees'][0];

    $this->assertNull($item['extra_fee']);
    $this->assertNull($item['previous_balance']);
    $this->assertNull($item['total']);
    $this->assertStringContainsString('"total":null', $result['output']);
  }

  /**
   * THE RULE THIS RESOURCE HAS AND ITS TWIN DOES NOT: `details` is an empty
   * string when the node has no field_detalle row, never null. Every other
   * absent field of this item is null, which is exactly why the exception is
   * worth a case of its own — the app prints this one without a null check.
   */
  public function testAbsentDetailsIsAnEmptyStringAndNotNull() {
    $this->seed([['id' => 1, 'date' => '2026-06-01']]);

    $result = $this->request();
    $item = $result['json']['data']['extra_fees'][0];

    $this->assertSame('', $item['details']);
    $this->assertNotNull($item['details']);
    $this->assertStringContainsString('"details":""', $result['output']);
    // Its neighbours in the very same item ARE null: the rule is per field.
    $this->assertNull($item['total']);
  }

  /**
   * A stored detail travels whole, and a non-string one is cast to string
   * rather than dropped.
   */
  public function testStoredDetailsTravelWholeAndAreCastToString() {
    $this->seed([['id' => 1, 'date' => '2026-06-01', 'values' => ['details' => "Reparación ascensor\nCuota 2/3"]]]);
    $this->assertSame("Reparación ascensor\nCuota 2/3", $this->request()['json']['data']['extra_fees'][0]['details']);

    $this->seed([['id' => 1, 'date' => '2026-06-01', 'values' => ['details' => 0]]]);
    $this->assertSame('0', $this->request()['json']['data']['extra_fees'][0]['details']);
  }

  /**
   * The date travels raw: no reformatting, and null when the node has no fecha
   * row.
   */
  public function testTheDateTravelsRawOrNull() {
    $this->seed([['id' => 1, 'date' => '2026-06-15 00:00:00']]);
    $this->assertSame('2026-06-15 00:00:00', $this->request()['json']['data']['extra_fees'][0]['date']);

    $this->seed([['id' => 1, 'date' => NULL]]);
    $this->assertNull($this->request()['json']['data']['extra_fees'][0]['date']);
  }

  /* -------------------------------------------------------------------------
   * The envelope, and the twin-divergence guards.
   * ---------------------------------------------------------------------- */

  /**
   * The envelope is the documented one, under the key of THIS resource.
   */
  public function testTheEnvelopeHasTheDocumentedShape() {
    $this->seed($this->consecutive(1));

    $result = $this->request();

    $this->assertTrue($result['json']['success']);
    $this->assertSame(['extra_fees', 'pagination'], array_keys($result['json']['data']));
    $this->assertSame(['total', 'page', 'limit', 'total_pages'], array_keys($result['json']['data']['pagination']));
  }

  /**
   * The response is not cacheable, like every JSON answer of this module.
   */
  public function testTheResponseIsNotCacheable() {
    $this->seed($this->consecutive(1));

    $result = $this->request();

    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
  }

  /**
   * The pure validator of this resource, exercised directly — including the
   * trailing-newline hole it shares with its twin (see
   * ReceiptEndpointTest::testATrailingNewlineIsStillAcceptedByThisResourcesOwnValidator
   * and "Los hallazgos" in SPEC 121).
   */
  public function testTheValidatorAcceptsRealDatesAndTheTrailingNewline() {
    $this->assertSame('2026-06-01', myapi_extra_fee_valid_date('2026-06-01'));
    $this->assertSame('2024-02-29', myapi_extra_fee_valid_date('2024-02-29'));
    $this->assertNull(myapi_extra_fee_valid_date('2026-02-29'));
    $this->assertNull(myapi_extra_fee_valid_date('2026-6-1'));
    $this->assertNull(myapi_extra_fee_valid_date(NULL));
    $this->assertNull(myapi_extra_fee_valid_date(['2026-06-01']));

    $this->assertSame("2026-06-01\n", myapi_extra_fee_valid_date("2026-06-01\n"), 'the copy has no D modifier');
  }

  /**
   * The range parser answers the documented pairs, and drops both bounds when
   * they are inverted.
   */
  public function testTheRangeParserAnswersTheDocumentedPairs() {
    $_GET = [];
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_extra_fee_parse_date_range());

    $_GET = ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'];
    $this->assertSame(['from' => '2026-06-01', 'to' => '2026-06-30'], myapi_extra_fee_parse_date_range());

    $_GET = ['date_from' => '2026-06-30', 'date_to' => '2026-06-01'];
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_extra_fee_parse_date_range());

    $_GET = ['date_from' => 'x', 'date_to' => '2026-06-30'];
    $this->assertSame(['from' => NULL, 'to' => '2026-06-30'], myapi_extra_fee_parse_date_range());
  }
}
