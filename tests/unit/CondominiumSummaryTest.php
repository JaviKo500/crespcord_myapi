<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/condominium.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/condominiums/%/summary (SPEC 17,
 * covered by SPEC 121).
 *
 * THE ONLY AGGREGATING ENDPOINT OF THE MODULE. Every other listing answers
 * rows; this one answers a SUM and a COUNT over the same set the expenses
 * listing would have returned, plus two single-value reads. That makes it the
 * one endpoint where a wrong answer is a plausible number rather than a
 * missing item — nobody notices that a total is too high, which is exactly why
 * the arithmetic needs cases with names.
 *
 * Three properties are pinned here and nowhere else in the project:
 *
 *  - total and count DO NOT have to match 1:1. field_valor is LEFT joined, so
 *    an expense with no amount counts as one expense and adds nothing to the
 *    total. A resource that inner-joined it would answer a smaller, plausible
 *    count.
 *  - An empty set is total 0.0 and count 0, NOT null. SQL's SUM over no rows
 *    is NULL, and the `!== NULL` branch of myapi_condominium_expense_totals()
 *    is what turns it into the zero the app renders. (The fixture query
 *    builder answers that NULL faithfully — see the SPEC 121 note on
 *    aggregate() in bootstrap.php.)
 *  - cash_balance IS null when unrecorded, and 0.0 only when a row actually
 *    says zero. The two mean different things on a balance sheet, and the
 *    docblock of myapi_condominium_cash_balance() says so.
 */
class CondominiumSummaryTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

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
   * One 'gastos' row. Expense nids start at 101 so they can never collide with
   * the condominium node itself, which is read out of the same table.
   */
  private function expenseRow(array $spec) {
    $spec += [
      'status'      => self::EXPOSED,
      'condominium' => self::CONDOMINIUM,
      'published'   => '1',
      'date'        => NULL,
      'amount'      => NULL,
    ];

    return [
      'nid'                           => (string) (100 + $spec['id']),
      'title'                         => 'Gasto ' . $spec['id'],
      'type'                          => 'gastos',
      'status'                        => (string) $spec['published'],
      'field_condominio_target_id'    => (string) $spec['condominium'],
      'fest.field_estado_gasto_value' => $spec['status'],
      'field_fecha_de_gasto_value'    => $spec['date'],
      'field_valor_value'             => $spec['amount'],
    ];
  }

  /**
   * The 'condominio' node itself, which myapi_condominium_name() reads.
   */
  private function condominiumNode($name = 'Torre Andalucía', $nid = self::CONDOMINIUM) {
    return [
      'nid'    => (string) $nid,
      'title'  => $name,
      'type'   => 'condominio',
      'status' => '1',
    ];
  }

  /**
   * Authenticates uid 3 as the owner of unit 45 in condominium 12.
   *
   * @param array $expenses  Specs for expenseRow().
   * @param array $options   'name' (the condominium title, or FALSE for no
   *                         node at all), 'cash' (the saldo value, or NULL for
   *                         no row) and 'tables' (extra fixture tables).
   */
  private function seed(array $expenses, array $options = []) {
    $options += ['name' => 'Torre Andalucía', 'cash' => NULL, 'tables' => []];

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $nodes = [];
    if ($options['name'] !== FALSE) {
      $nodes[] = $this->condominiumNode($options['name']);
    }
    foreach ($expenses as $spec) {
      $nodes[] = $this->expenseRow($spec);
    }

    $saldo = $options['cash'] === NULL
      ? []
      : [['entity_id' => (string) self::CONDOMINIUM, 'field_saldo_caja_value' => $options['cash'], 'deleted' => '0', 'entity_type' => 'node']];

    myapi_test_db_seed($options['tables'] + [
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
      'field_data_field_saldo_caja' => $saldo,
      'node' => $nodes,
    ]);
  }

  private function request($condominium_id = self::CONDOMINIUM) {
    return myapi_test_capture(function () use ($condominium_id) {
      myapi_condominium_dispatch($condominium_id);
    });
  }

  /**
   * Asserts a monetary value that came back through the JSON round trip.
   *
   * JSON HAS ONE NUMBER TYPE. drupal_json_encode() writes a float with no
   * fractional part as a bare `36`, and json_decode() reads that back as an
   * int — so a strict assertSame(36.0, ...) would fail on a response that is
   * byte-for-byte correct. What travels to the app is the VALUE, and that is
   * what this asserts; the float CAST itself is pinned separately, on the raw
   * body, by the cases that read $result['output'].
   */
  private function assertMoney($expected, $actual, $message = '') {
    $this->assertEqualsWithDelta($expected, $actual, 0.00001, $message);
    $this->assertIsNumeric($actual, $message);
  }

  /* -------------------------------------------------------------------------
   * Routing and the guards.
   * ---------------------------------------------------------------------- */

  /**
   * Every verb other than GET is 405 and runs no query.
   */
  public function testEveryMethodOtherThanGetIs405AndRunsNoQuery() {
    $this->seed([['id' => 1, 'amount' => '10.00']]);

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
    $this->seed([]);

    $this->assertSame(200, $this->request()['status']);
  }

  /**
   * Every way of failing the token is a 401 that aggregates nothing.
   */
  public function testEveryFailingTokenIs401AndAggregatesNothing() {
    $this->seed([['id' => 1, 'amount' => '10.00']]);
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
      'blocked' => function () { $GLOBALS['myapi_test_users'][CondominiumSummaryTest::UID]['status'] = 0; },
    ] as $name => $break) {
      $this->seed([['id' => 1, 'amount' => '10.00']]);
      $break();

      $result = $this->request();

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries('node'), $name);
    }
  }

  /**
   * A condominium the caller has no unit in is 403, and nothing is aggregated
   * or read — not even the name, which would otherwise leak that the
   * condominium exists.
   */
  public function testAForeignCondominiumIs403AndLeaksNothing() {
    $this->seed([['id' => 1, 'amount' => '10.00']], ['name' => 'Torre Secreta']);

    $result = $this->request(99);

    $this->assertSame(403, $result['status']);
    $this->assertSame('condominium_access_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries('node'));
    $this->assertStringNotContainsString('Secreta', $result['output']);
  }

  /**
   * A non-existent condominium answers the same bytes as a foreign one.
   */
  public function testANonExistentCondominiumIsIndistinguishableFromAForeignOne() {
    $this->seed([], [
      'tables' => [
        'field_data_field_condominio' => [
          ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
          ['entity_id' => '88', 'field_condominio_target_id' => '99', 'deleted' => '0', 'entity_type' => 'node'],
        ],
      ],
    ]);

    $foreign = $this->request(99);
    $missing = $this->request(4242);

    $this->assertSame(403, $foreign['status']);
    $this->assertSame($foreign['output'], $missing['output']);
  }

  /**
   * The occupant of a unit gets the summary of its condominium.
   */
  public function testOccupantsGetTheSummary() {
    foreach ([
      'field_data_field_ocupante'  => 'field_ocupante_target_id',
      'field_data_field_ocupantes' => 'field_ocupantes_target_id',
    ] as $table => $column) {
      $this->seed([], [
        'tables' => [
          'field_data_field_propietario' => [],
          $table => [['entity_id' => (string) self::UNIT, $column => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node']],
        ],
      ]);

      $this->assertSame(200, $this->request()['status'], $table);
    }
  }

  /* -------------------------------------------------------------------------
   * The response shape.
   * ---------------------------------------------------------------------- */

  /**
   * The documented body, compared whole: five keys, in order, at the top level
   * of `data` — this endpoint has no wrapper list and no pagination block.
   */
  public function testTheFullAnswerHasTheDocumentedShape() {
    $this->seed([
      ['id' => 1, 'amount' => '100.50'],
      ['id' => 2, 'amount' => '49.50'],
    ], ['name' => 'Torre Andalucía', 'cash' => '820.25']);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'success' => TRUE,
      'data'    => [
        'id'             => self::CONDOMINIUM,
        'name'           => 'Torre Andalucía',
        'total_expenses' => 150,
        'expenses_count' => 2,
        'cash_balance'   => 820.25,
      ],
    ], $result['json']);
    $this->assertSame(
      ['id', 'name', 'total_expenses', 'expenses_count', 'cash_balance'],
      array_keys($result['json']['data'])
    );
  }

  /**
   * The answered id is the one from the route, cast to int.
   */
  public function testTheAnsweredIdIsTheRouteIdAsAnInt() {
    $this->seed([]);

    $result = $this->request('12');

    $this->assertSame(12, $result['json']['data']['id']);
  }

  /**
   * The response is not cacheable, like every JSON answer of this module.
   */
  public function testTheResponseIsNotCacheable() {
    $this->seed([]);

    $result = $this->request();

    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
  }

  /* -------------------------------------------------------------------------
   * The aggregate.
   * ---------------------------------------------------------------------- */

  /**
   * The total is the sum of the amounts and the count is the number of
   * expenses.
   */
  public function testTheTotalIsTheSumAndTheCountIsTheNumberOfExpenses() {
    $this->seed([
      ['id' => 1, 'amount' => '10.25'],
      ['id' => 2, 'amount' => '20.50'],
      ['id' => 3, 'amount' => '5.25'],
    ]);

    $data = $this->request()['json']['data'];

    $this->assertMoney(36.0, $data['total_expenses']);
    $this->assertSame(3, $data['expenses_count']);
  }

  /**
   * AN EXPENSE WITH NO AMOUNT STILL COUNTS. field_valor is LEFT joined, so the
   * count and the total describe the same set from two angles and need not
   * agree: three expenses, one of them without a valor row, is a count of 3
   * and a total of only what the other two carry.
   */
  public function testAnExpenseWithNoAmountCountsButAddsNothing() {
    $this->seed([
      ['id' => 1, 'amount' => '10.00'],
      ['id' => 2, 'amount' => NULL],
      ['id' => 3, 'amount' => '5.00'],
    ]);

    $data = $this->request()['json']['data'];

    $this->assertMoney(15.0, $data['total_expenses']);
    $this->assertSame(3, $data['expenses_count'], 'the amountless expense is still an expense');
  }

  /**
   * AN EMPTY SET IS 0.0 AND 0, NEVER NULL. SQL answers SUM(NULL) over no rows;
   * the endpoint is what turns that into the zero the app renders without a
   * null check.
   */
  public function testAnEmptyCondominiumTotalsZeroAndNotNull() {
    $this->seed([], ['cash' => '100.00']);

    $result = $this->request();
    $data = $result['json']['data'];

    $this->assertSame(0, $data['total_expenses']);
    $this->assertNotNull($data['total_expenses']);
    $this->assertSame(0, $data['expenses_count']);
    $this->assertStringContainsString('"total_expenses":0', $result['output']);
    $this->assertStringNotContainsString('"total_expenses":null', $result['output']);
  }

  /**
   * A condominium whose every expense lacks an amount is the same zero, and a
   * non-zero count: this is the case where SUM is NULL although rows matched.
   */
  public function testAllExpensesWithoutAmountTotalZeroWithANonZeroCount() {
    $this->seed([
      ['id' => 1, 'amount' => NULL],
      ['id' => 2, 'amount' => NULL],
    ]);

    $data = $this->request()['json']['data'];

    $this->assertSame(0, $data['total_expenses']);
    $this->assertSame(2, $data['expenses_count']);
  }

  /**
   * Negative amounts are summed as stored: a refund lowers the total instead
   * of being ignored.
   */
  public function testNegativeAmountsLowerTheTotal() {
    $this->seed([
      ['id' => 1, 'amount' => '100.00'],
      ['id' => 2, 'amount' => '-40.00'],
    ]);

    $this->assertMoney(60.0, $this->request()['json']['data']['total_expenses']);
  }

  /**
   * The aggregate obeys the SAME four conditions as the expenses listing:
   * type, published flag, condominium and estado. An expense excluded from the
   * list must be excluded from the total, or the two screens of the app
   * disagree.
   */
  public function testTheAggregateObeysTheSameFourConditionsAsTheListing() {
    $this->seed([
      ['id' => 1, 'amount' => '10.00'],
      ['id' => 2, 'amount' => '1000.00', 'status' => 'Anulado'],
      ['id' => 3, 'amount' => '1000.00', 'published' => '0'],
      ['id' => 4, 'amount' => '1000.00', 'condominium' => 77],
    ]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'recibo', 'field_valor_value' => '1000.00']
      + $this->expenseRow(['id' => 5]);

    $data = $this->request()['json']['data'];

    $this->assertMoney(10.0, $data['total_expenses']);
    $this->assertSame(1, $data['expenses_count']);
  }

  /**
   * An expense with no estado row is excluded from the aggregate: the estado
   * join is INNER here too.
   */
  public function testAnExpenseWithNoEstadoRowIsExcludedFromTheAggregate() {
    $this->seed([
      ['id' => 1, 'amount' => '10.00'],
      ['id' => 2, 'amount' => '999.00', 'status' => NULL],
    ]);

    $data = $this->request()['json']['data'];

    $this->assertMoney(10.0, $data['total_expenses']);
    $this->assertSame(1, $data['expenses_count']);
  }

  /**
   * The aggregate is ONE query: a SUM and a COUNT over the same scan, not two
   * round trips.
   */
  public function testTheAggregateIsASingleQuery() {
    $this->seed([['id' => 1, 'amount' => '10.00']]);

    $this->request();

    $aggregates = array_values(array_filter(myapi_test_db_queries('node'), function ($query) {
      return !empty($query['expressions']);
    }));

    $this->assertCount(1, $aggregates);
    $this->assertSame(['total', 'count'], array_keys($aggregates[0]['expressions']));
    $this->assertSame('SUM(fval.field_valor_value)', $aggregates[0]['expressions']['total']);
    $this->assertSame('COUNT(n.nid)', $aggregates[0]['expressions']['count']);
  }

  /* -------------------------------------------------------------------------
   * The date range.
   * ---------------------------------------------------------------------- */

  /**
   * Both bounds narrow the aggregate, inclusively.
   */
  public function testTheDateRangeNarrowsTheAggregate() {
    $this->seed([
      ['id' => 1, 'amount' => '10.00', 'date' => '2026-06-01'],
      ['id' => 2, 'amount' => '20.00', 'date' => '2026-06-15'],
      ['id' => 3, 'amount' => '40.00', 'date' => '2026-07-01'],
    ]);

    $_GET = ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'];
    $data = $this->request()['json']['data'];

    $this->assertMoney(30.0, $data['total_expenses']);
    $this->assertSame(2, $data['expenses_count']);
  }

  /**
   * A narrowed range that matches nothing is the same zero as an empty
   * condominium — not a null, and not an error.
   */
  public function testARangeThatMatchesNothingIsZero() {
    $this->seed([['id' => 1, 'amount' => '10.00', 'date' => '2026-06-01']]);

    $_GET = ['date_from' => '2030-01-01'];
    $data = $this->request()['json']['data'];

    $this->assertSame(0, $data['total_expenses']);
    $this->assertSame(0, $data['expenses_count']);
  }

  /**
   * An expense with no date is excluded the moment a bound is given, and
   * included when none is — the same rule as the listing.
   */
  public function testAnExpenseWithNoDateIsExcludedOnlyWhenABoundIsGiven() {
    $this->seed([
      ['id' => 1, 'amount' => '10.00', 'date' => '2026-06-01'],
      ['id' => 2, 'amount' => '5.00', 'date' => NULL],
    ]);

    $this->assertSame(2, $this->request()['json']['data']['expenses_count']);

    $_GET = ['date_from' => '2020-01-01'];
    $data = $this->request()['json']['data'];
    $this->assertSame(1, $data['expenses_count']);
    $this->assertMoney(10.0, $data['total_expenses']);
  }

  /**
   * A malformed bound is ignored and an inverted range drops both — the shared
   * parser, same as the expenses listing.
   */
  public function testMalformedAndInvertedRangesAreIgnored() {
    $this->seed([
      ['id' => 1, 'amount' => '10.00', 'date' => '2026-06-01'],
      ['id' => 2, 'amount' => '20.00', 'date' => '2026-07-01'],
    ]);

    foreach (['2026-13-40', 'hoy', '2026-02-30', ''] as $value) {
      $_GET = ['date_from' => $value];
      $this->assertMoney(30.0, $this->request()['json']['data']['total_expenses'], $value);
    }

    $_GET = ['date_from' => '2026-12-31', 'date_to' => '2026-01-01'];
    $this->assertMoney(30.0, $this->request()['json']['data']['total_expenses']);
  }

  /* -------------------------------------------------------------------------
   * The name.
   * ---------------------------------------------------------------------- */

  /**
   * The name is the node title, read with a type condition so a nid of another
   * bundle can never resolve here.
   */
  public function testTheNameIsTheTitleOfACondominioNode() {
    $this->seed([], ['name' => 'Torre Andalucía']);

    $result = $this->request();

    $this->assertSame('Torre Andalucía', $result['json']['data']['name']);

    $name_queries = array_values(array_filter(myapi_test_db_queries('node'), function ($query) {
      return in_array('title', $query['fields'], TRUE);
    }));
    $this->assertNotEmpty($name_queries);
    $values = array_column($name_queries[0]['conditions'], 'value', 'field');
    $this->assertSame('condominio', $values['n.type']);
  }

  /**
   * A nid that is NOT a 'condominio' answers a null name rather than another
   * bundle's title. Access was granted by the user's units, so this is reached
   * by a data inconsistency, not by an attacker.
   */
  public function testANidOfAnotherBundleAnswersANullName() {
    $this->seed([], ['name' => FALSE]);
    $GLOBALS['myapi_test_db']['node'][] = [
      'nid' => (string) self::CONDOMINIUM, 'title' => 'Un recibo', 'type' => 'recibo', 'status' => '1',
    ];

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertNull($result['json']['data']['name']);
    $this->assertStringNotContainsString('Un recibo', $result['output']);
  }

  /**
   * A deleted condominium node answers a null name and still answers 200 with
   * its aggregate: the summary degrades instead of failing.
   */
  public function testADeletedCondominiumNodeStillAnswersItsAggregate() {
    $this->seed([['id' => 1, 'amount' => '10.00']], ['name' => FALSE]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertNull($result['json']['data']['name']);
    $this->assertMoney(10.0, $result['json']['data']['total_expenses']);
  }

  /**
   * An unpublished condominium still resolves its name: the name query filters
   * by type and nid, not by status, because access came from the user's units.
   */
  public function testAnUnpublishedCondominiumStillResolvesItsName() {
    $this->seed([], ['name' => FALSE]);
    $GLOBALS['myapi_test_db']['node'][] = [
      'nid' => (string) self::CONDOMINIUM, 'title' => 'Torre Oculta', 'type' => 'condominio', 'status' => '0',
    ];

    $this->assertSame('Torre Oculta', $this->request()['json']['data']['name']);
  }

  /* -------------------------------------------------------------------------
   * The cash balance.
   * ---------------------------------------------------------------------- */

  /**
   * A stored balance is a float, sign included.
   */
  public function testAStoredBalanceIsAFloatWithItsSign() {
    $this->seed([], ['cash' => '1520.75']);
    $this->assertSame(1520.75, $this->request()['json']['data']['cash_balance']);

    $this->seed([], ['cash' => '-320.10']);
    $this->assertSame(-320.1, $this->request()['json']['data']['cash_balance']);
  }

  /**
   * NO ROW IS null AND NOT 0.0: "the balance was never recorded" and "the
   * balance is zero" are different facts about a condominium's cash, and the
   * endpoint keeps them apart.
   */
  public function testAnUnrecordedBalanceIsNullAndAZeroRowIsZero() {
    $this->seed([], ['cash' => NULL]);
    $unrecorded = $this->request();
    $this->assertNull($unrecorded['json']['data']['cash_balance']);
    $this->assertStringContainsString('"cash_balance":null', $unrecorded['output']);

    $this->seed([], ['cash' => '0.00']);
    $zero = $this->request();
    $this->assertNotNull($zero['json']['data']['cash_balance']);
    $this->assertStringContainsString('"cash_balance":0', $zero['output']);
  }

  /**
   * The balance is read for THIS condominium only, scoped by entity_type and
   * deleted — a row of another entity with the same id must not answer.
   */
  public function testTheBalanceIsScopedToThisCondominiumsRow() {
    $this->seed([], [
      'tables' => [
        'field_data_field_saldo_caja' => [
          ['entity_id' => '99', 'field_saldo_caja_value' => '999.00', 'deleted' => '0', 'entity_type' => 'node'],
          ['entity_id' => (string) self::CONDOMINIUM, 'field_saldo_caja_value' => '10.00', 'deleted' => '0', 'entity_type' => 'node'],
        ],
      ],
    ]);

    $this->assertMoney(10.0, $this->request()['json']['data']['cash_balance']);

    $queries = myapi_test_db_queries('field_data_field_saldo_caja');
    $this->assertCount(1, $queries);
    $values = array_column($queries[0]['conditions'], 'value', 'field');
    $this->assertSame('node', $values['fs.entity_type']);
    $this->assertSame(0, $values['fs.deleted']);
  }

  /**
   * A deleted saldo row does not answer: the balance goes back to null.
   */
  public function testADeletedBalanceRowAnswersNull() {
    $this->seed([], [
      'tables' => [
        'field_data_field_saldo_caja' => [
          ['entity_id' => (string) self::CONDOMINIUM, 'field_saldo_caja_value' => '10.00', 'deleted' => '1', 'entity_type' => 'node'],
        ],
      ],
    ]);

    $this->assertNull($this->request()['json']['data']['cash_balance']);
  }

  /* -------------------------------------------------------------------------
   * The three reads, together.
   * ---------------------------------------------------------------------- */

  /**
   * The endpoint runs exactly the reads it needs and no more: the token, the
   * two allowlist steps, the aggregate, the name and the balance. A summary
   * that queried per expense would be invisible in the response and expensive
   * in production.
   */
  public function testTheEndpointRunsOneQueryPerFactItAnswers() {
    $this->seed([
      ['id' => 1, 'amount' => '10.00'],
      ['id' => 2, 'amount' => '20.00'],
    ], ['cash' => '5.00']);

    $this->request();

    $tables = array_column(myapi_test_db_queries(), 'table');

    $this->assertSame(2, count(array_filter($tables, function ($table) {
      return $table === 'node';
    })), 'one aggregate and one name lookup');
    $this->assertSame(1, count(array_filter($tables, function ($table) {
      return $table === 'field_data_field_saldo_caja';
    })));
    $this->assertSame(1, count(array_filter($tables, function ($table) {
      return $table === 'my_api_tokens';
    })));
  }
}
