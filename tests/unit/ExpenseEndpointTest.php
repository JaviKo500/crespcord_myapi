<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/expense.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/condominiums/%/expenses (SPEC 16,
 * covered by SPEC 121).
 *
 * THE THIRD SHAPE OF LISTING IN THIS MODULE, and the first one scoped to a
 * CONDOMINIUM instead of to a unit. That scope is the reason it gets its own
 * class rather than a row in the twins' data provider: access is granted
 * through myapi_condominium_related_nids(), which is a two-step resolution —
 * the user's units first, then the condominiums those units belong to — and
 * every step of it is an access decision. A resident of tower A must not read
 * the expense book of tower B, and the way that could break is not a missing
 * condition but a wrong JOIN direction, which answers a plausible list.
 *
 * It is also the first listing that reads the SHARED date-range parser
 * (myapi_parse_date_range_param(), includes/myapi.request.inc) instead of
 * carrying its own copy — so unlike its receipts/extra-fees cousins it does
 * NOT accept a bound with a trailing newline. Both facts are pinned below,
 * side by side, because that difference is invisible from the outside.
 *
 * Same fixture contract as the other listings: flat rows, joins recorded and
 * not resolved, the estado column written qualified so one row can also carry
 * node's published flag.
 */
class ExpenseEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

  /**
   * The condominium in the route, one of its units, and the resident.
   */
  const CONDOMINIUM = 12;
  const UNIT = 45;
  const UID = 3;

  const EXPOSED = 'Activo';

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
   * One 'gastos' row, carrying both halves of every joined column.
   */
  private function expenseRow(array $spec) {
    $spec += [
      'status'      => self::EXPOSED,
      'condominium' => self::CONDOMINIUM,
      'published'   => '1',
      'date'        => NULL,
      'values'      => [],
    ];

    $row = [
      'nid'                            => (string) $spec['id'],
      'title'                          => 'Gasto ' . $spec['id'],
      'type'                           => 'gastos',
      'status'                         => (string) $spec['published'],
      'field_condominio_target_id'     => (string) $spec['condominium'],
      'condominium_id'                 => (string) $spec['condominium'],
      'fest.field_estado_gasto_value'  => $spec['status'],
      'field_fecha_de_gasto_value'     => $spec['date'],
      'expense_date'                   => $spec['date'],
    ];

    return $spec['values'] + $row;
  }

  /**
   * Authenticates uid 3 as the owner of unit 45, which belongs to condominium
   * 12, and seeds the given expenses.
   *
   * The two relation tables are seeded separately on purpose: they are the two
   * steps of myapi_condominium_related_nids(), and a case that wants to break
   * one of them replaces just that one.
   */
  private function seed(array $expenses, array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $rows = [];
    foreach ($expenses as $spec) {
      $rows[] = $this->expenseRow($spec);
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
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'node' => $rows,
    ]);
  }

  private function request($condominium_id = self::CONDOMINIUM) {
    return myapi_test_capture(function () use ($condominium_id) {
      myapi_expense_dispatch($condominium_id);
    });
  }

  private function ids(array $result) {
    return array_column($result['json']['data']['expenses'], 'id');
  }

  private function consecutive($count) {
    $expenses = [];
    for ($i = 1; $i <= $count; $i++) {
      $expenses[] = ['id' => $i, 'date' => sprintf('2026-06-%02d', $i)];
    }

    return $expenses;
  }

  /* -------------------------------------------------------------------------
   * Routing.
   * ---------------------------------------------------------------------- */

  /**
   * Every verb other than GET is 405 and runs no query at all.
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

  /* -------------------------------------------------------------------------
   * The token guard.
   * ---------------------------------------------------------------------- */

  /**
   * No header, unknown token, revoked, expired, deleted user and blocked user:
   * all 401, and none of them reads a single expense.
   */
  public function testEveryFailingTokenIs401AndNeverReachesTheExpenses() {
    $this->seed($this->consecutive(1));
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $result = $this->request();
    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries());

    foreach ([
      'unknown' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'] = []; },
      'revoked' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['revoked'] = '1'; },
      'expired' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['access_expires_at'] = REQUEST_TIME - 1; },
      'deleted' => function () { $GLOBALS['myapi_test_users'] = []; },
      'blocked' => function () { $GLOBALS['myapi_test_users'][ExpenseEndpointTest::UID]['status'] = 0; },
    ] as $name => $break) {
      $this->seed($this->consecutive(1));
      $break();

      $result = $this->request();

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries('node'), $name);
    }
  }

  /* -------------------------------------------------------------------------
   * The condominium access rule, resolved in two steps.
   * ---------------------------------------------------------------------- */

  /**
   * A resident of the condominium reads its expenses.
   */
  public function testAResidentOfTheCondominiumReadsItsExpenses() {
    $this->seed($this->consecutive(2));

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertCount(2, $result['json']['data']['expenses']);
  }

  /**
   * A condominium the caller has no unit in is 403 condominium_access_denied,
   * and the expenses are never queried. The error_code is this endpoint's own
   * and not the unit one.
   */
  public function testAForeignCondominiumIs403AndNeverQueriesTheExpenses() {
    $this->seed($this->consecutive(1));

    $result = $this->request(99);

    $this->assertSame(403, $result['status']);
    $this->assertSame('condominium_access_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /**
   * A condominium that does not exist answers the same bytes as one that
   * exists and belongs to somebody else: the endpoint never reveals which.
   */
  public function testANonExistentCondominiumIsIndistinguishableFromAForeignOne() {
    $this->seed($this->consecutive(1), [
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
        // Unit 88 belongs to condominium 99, but the caller does not own it.
        ['entity_id' => '88', 'field_condominio_target_id' => '99', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $foreign = $this->request(99);
    $missing = $this->request(4242);

    $this->assertSame(403, $foreign['status']);
    $this->assertSame($foreign['output'], $missing['output']);
  }

  /**
   * BOTH STEPS OF THE RESOLUTION ARE REQUIRED. Owning a unit is not enough if
   * that unit is not in this condominium, and a condominium row is not enough
   * if the unit is not the caller's.
   */
  public function testBothStepsOfTheResolutionAreRequired() {
    // Step 2 broken: the caller owns unit 45, but 45 belongs to condominium 77.
    $this->seed($this->consecutive(1), [
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => '77', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);
    $this->assertSame(403, $this->request()['status'], 'owns a unit, but not here');

    // Step 1 broken: unit 45 is in this condominium, but belongs to somebody
    // else.
    $this->seed($this->consecutive(1), [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => '900', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);
    $this->assertSame(403, $this->request()['status'], 'the unit is here, but not theirs');
  }

  /**
   * The occupant of a unit has the same condominium access as its owner,
   * through either occupant field.
   */
  public function testOccupantsGetTheSameCondominiumAccess() {
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

  /**
   * A deleted relation row grants nothing, at either step.
   */
  public function testADeletedRelationRowGrantsNothing() {
    $this->seed($this->consecutive(1), [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '1', 'entity_type' => 'node'],
      ],
    ]);
    $this->assertSame(403, $this->request()['status'], 'deleted ownership');

    $this->seed($this->consecutive(1), [
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '1', 'entity_type' => 'node'],
      ],
    ]);
    $this->assertSame(403, $this->request()['status'], 'deleted condominium link');
  }

  /**
   * A caller with no unit at all short-circuits: the second step is never even
   * queried, because there is nothing to resolve from.
   */
  public function testACallerWithNoUnitNeverRunsTheSecondQuery() {
    $this->seed($this->consecutive(1), [
      'field_data_field_propietario' => [],
      'field_data_field_ocupante'    => [],
      'field_data_field_ocupantes'   => [],
    ]);

    $result = $this->request();

    $this->assertSame(403, $result['status']);
    $this->assertSame([], myapi_test_db_queries('field_data_field_condominio'));
  }

  /* -------------------------------------------------------------------------
   * The scope of the listing.
   * ---------------------------------------------------------------------- */

  /**
   * Type, published flag, condominium and estado all narrow the set, and each
   * of the four excludes a row of its own.
   */
  public function testTheFourConditionsOfTheListingAllHold() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => '2026-06-02', 'status' => 'Anulado'],
      ['id' => 3, 'date' => '2026-06-03', 'published' => '0'],
      ['id' => 4, 'date' => '2026-06-04', 'condominium' => 77],
    ]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'recibo'] + $this->expenseRow(['id' => 5, 'date' => '2026-06-05']);

    $result = $this->request();

    $this->assertSame([1], $this->ids($result));
    $this->assertSame(1, $result['json']['data']['pagination']['total']);
  }

  /**
   * An expense with no estado row is invisible: the estado join is INNER.
   */
  public function testAnExpenseWithNoEstadoRowIsInvisible() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => '2026-06-02', 'status' => NULL],
    ]);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * The answered `status` is the estado value, always the exposed one because
   * of the filter.
   */
  public function testTheAnsweredStatusIsTheExposedState() {
    $this->seed($this->consecutive(1));

    $this->assertSame(self::EXPOSED, $this->request()['json']['data']['expenses'][0]['status']);
  }

  /* -------------------------------------------------------------------------
   * The query shape.
   * ---------------------------------------------------------------------- */

  /**
   * One count without a range and one ordered, ranged fetch; both carry the
   * same four narrowing conditions.
   */
  public function testTheQueryShapeIsTheDocumentedOne() {
    $this->seed($this->consecutive(2));

    $this->request();

    $queries = myapi_test_db_queries('node');
    $this->assertCount(2, $queries);
    $this->assertTrue($queries[0]['count']);
    $this->assertNull($queries[0]['range']);

    foreach ($queries as $i => $query) {
      $values = array_column($query['conditions'], 'value', 'field');
      $this->assertSame('gastos', $values['n.type'], 'query ' . $i);
      $this->assertSame(1, $values['n.status'], 'query ' . $i);
      $this->assertSame(self::CONDOMINIUM, $values['fcond.field_condominio_target_id'], 'query ' . $i);
      $this->assertSame(self::EXPOSED, $values['fest.field_estado_gasto_value'], 'query ' . $i);
    }

    $order = $queries[1]['order'];
    $this->assertCount(1, $order);
    $this->assertSame('ffec.field_fecha_de_gasto_value', $order[0]['field']);
  }

  /**
   * The category name is read through a join to taxonomy_term_data, which is
   * recorded even though the stub does not resolve it — a resource that
   * stopped joining would answer a null name for every expense.
   */
  public function testTheCategoryNameIsJoinedFromTheTaxonomyTable() {
    $this->seed($this->consecutive(1));

    $this->request();

    $joins = array_column(myapi_test_db_queries('node')[1]['joins'], 'table');
    $this->assertContains('taxonomy_term_data', $joins);
    $this->assertContains('field_data_field_categoria', $joins);
  }

  /* -------------------------------------------------------------------------
   * Pagination, sorting and the shared date range.
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
   * An empty condominium is a 200 with an empty array and total_pages 0.
   */
  public function testAnEmptyCondominiumIsAnEmptyTwoHundred() {
    $this->seed([]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['expenses']);
    $this->assertSame(0, $result['json']['data']['pagination']['total_pages']);
    $this->assertStringContainsString('"expenses":[]', $result['output']);
  }

  /**
   * The page slices the ordered set and total_pages is the ceiling.
   */
  public function testPaginationSlicesAndCountsTheWholeSet() {
    $this->seed($this->consecutive(7));
    $_GET['limit'] = '3';

    $_GET['page'] = '1';
    $first = $this->request();
    $_GET['page'] = '3';
    $third = $this->request();

    $this->assertSame(3, $first['json']['data']['pagination']['total_pages']);
    $this->assertSame([7, 6, 5], $this->ids($first));
    $this->assertSame([1], $this->ids($third));
  }

  /**
   * limit is clamped to [1, 50]; page falls back to 1; neither ever answers a
   * 422.
   */
  public function testLimitIsClampedAndPageFallsBack() {
    $this->seed($this->consecutive(1));

    foreach (['0' => 20, '-5' => 20, 'x' => 20, '51' => 50, '50' => 50, '4' => 4] as $sent => $expected) {
      $_GET = ['limit' => (string) $sent];
      $this->assertSame($expected, $this->request()['json']['data']['pagination']['limit'], 'limit=' . $sent);
    }

    foreach (['0', '-1', 'x', ''] as $sent) {
      $_GET = ['page' => $sent];
      $result = $this->request();
      $this->assertSame(200, $result['status'], 'page=' . $sent);
      $this->assertSame(1, $result['json']['data']['pagination']['page'], 'page=' . $sent);
    }
  }

  /**
   * Newest first by default, ?sort=asc reverses, everything else falls back.
   */
  public function testSortingFollowsTheDocumentedRule() {
    $this->seed($this->consecutive(3));

    $this->assertSame([3, 2, 1], $this->ids($this->request()));

    $_GET['sort'] = 'asc';
    $this->assertSame([1, 2, 3], $this->ids($this->request()));

    foreach (['ASC', 'Desc', '', ['asc']] as $value) {
      $_GET['sort'] = $value;
      $this->assertSame([3, 2, 1], $this->ids($this->request()), json_encode($value));
    }
  }

  /**
   * Both bounds are inclusive and narrow the count as well.
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
   * A malformed bound is ignored and an inverted range drops both.
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
   * An expense with no date is excluded the moment a bound is given.
   */
  public function testAnExpenseWithNoDateIsExcludedOnlyWhenABoundIsGiven() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => NULL],
    ]);

    $this->assertSame([1, 2], $this->ids($this->request()));

    $_GET['date_from'] = '2020-01-01';
    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * THIS ENDPOINT READS THE SHARED PARSER, so a bound with a trailing newline
   * is REJECTED here — the opposite of what its receipts/extra-fees cousins
   * do with their own copies of the same validator. The divergence is the
   * finding; this case is the half of it that behaves correctly.
   */
  public function testATrailingNewlineBoundIsRejectedBecauseTheSharedParserIsUsed() {
    $this->seed($this->consecutive(3));
    $_GET['date_from'] = "2026-06-03\n";

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    // The bound was dropped, so the whole set comes back — no silent
    // off-by-one-day exclusion.
    $this->assertSame([3, 2, 1], $this->ids($result));
  }

  /* -------------------------------------------------------------------------
   * The mapper.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly the ten documented keys, in order.
   */
  public function testTheItemHasExactlyTheTenDocumentedKeysInOrder() {
    $this->seed($this->consecutive(1));

    $item = $this->request()['json']['data']['expenses'][0];

    $this->assertSame([
      'id', 'title', 'condominium_id', 'description', 'category_id',
      'category_name', 'expense_date', 'amount', 'reference', 'status',
    ], array_keys($item));
  }

  /**
   * The three casts of the mapper: two ints, one float.
   */
  public function testTheCastsOfTheMapper() {
    $this->seed([['id' => 501, 'date' => '2026-06-01', 'values' => [
      'category_id' => '17',
      'amount'      => '1250.75',
    ]]]);

    $item = $this->request()['json']['data']['expenses'][0];

    $this->assertSame(501, $item['id']);
    $this->assertSame(self::CONDOMINIUM, $item['condominium_id']);
    $this->assertSame(17, $item['category_id']);
    $this->assertSame(1250.75, $item['amount']);
  }

  /**
   * category_id is null — and NOT 0 — when the expense has no category: the
   * cast is guarded, and a 0 would be read by the app as a real term id.
   */
  public function testAnUncategorisedExpenseHasANullCategoryIdAndNotZero() {
    $this->seed([['id' => 1, 'date' => '2026-06-01']]);

    $result = $this->request();
    $item = $result['json']['data']['expenses'][0];

    $this->assertNull($item['category_id']);
    $this->assertNull($item['category_name']);
    $this->assertStringContainsString('"category_id":null', $result['output']);
  }

  /**
   * amount is null when absent and a float when stored — including a stored
   * zero, which is a real expense of no value and not an absent one.
   */
  public function testAmountIsNullWhenAbsentAndAFloatWhenStored() {
    $this->seed([['id' => 1, 'date' => '2026-06-01']]);
    $this->assertNull($this->request()['json']['data']['expenses'][0]['amount']);

    $this->seed([['id' => 1, 'date' => '2026-06-01', 'values' => ['amount' => '0.00']]]);
    $result = $this->request();
    $this->assertNotNull($result['json']['data']['expenses'][0]['amount']);
    $this->assertStringContainsString('"amount":0,', $result['output']);
  }

  /**
   * The text fields travel raw and stay null when the node has no row: no
   * empty-string fallback anywhere in this mapper.
   */
  public function testTextFieldsTravelRawAndStayNull() {
    $this->seed([['id' => 1, 'date' => '2026-06-15 00:00:00', 'values' => [
      'description'   => "Mantenimiento ascensor\nFactura 001",
      'reference'     => 'FAC-001',
      'category_name' => 'Mantenimiento',
    ]]]);

    $item = $this->request()['json']['data']['expenses'][0];
    $this->assertSame("Mantenimiento ascensor\nFactura 001", $item['description']);
    $this->assertSame('FAC-001', $item['reference']);
    $this->assertSame('Mantenimiento', $item['category_name']);
    $this->assertSame('2026-06-15 00:00:00', $item['expense_date']);

    $this->seed([['id' => 1, 'date' => NULL]]);
    $item = $this->request()['json']['data']['expenses'][0];
    $this->assertNull($item['description']);
    $this->assertNull($item['reference']);
    $this->assertNull($item['expense_date']);
  }

  /* -------------------------------------------------------------------------
   * The envelope.
   * ---------------------------------------------------------------------- */

  /**
   * The documented envelope, under this resource's key.
   */
  public function testTheEnvelopeHasTheDocumentedShape() {
    $this->seed($this->consecutive(1));

    $result = $this->request();

    $this->assertTrue($result['json']['success']);
    $this->assertSame(['expenses', 'pagination'], array_keys($result['json']['data']));
    $this->assertSame(['total', 'page', 'limit', 'total_pages'], array_keys($result['json']['data']['pagination']));
    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
  }
}
