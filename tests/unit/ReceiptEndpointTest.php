<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/receipt.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/units/%/receipts (SPEC 11 and 12,
 * covered by SPEC 121).
 *
 * myapi_receipt_dispatch() is called the way hook_menu() calls it — with the
 * unit id from the route — over fixture tables, a fixture my_api_tokens row
 * and a fixture Authorization header. What gets asserted is the JSON body the
 * module prints and the status code it sets.
 *
 * THE FIXTURE ROWS ARE FLAT, one row per receipt carrying the columns the
 * joins would have produced, because the query stub records joins instead of
 * resolving them (see MyapiTestSelectQuery). So what runs here is the PHP half
 * of the endpoint: the access check, the page/limit/sort parse, the date-range
 * parse, the pagination arithmetic, the estado filter as a condition, and the
 * mapping of rows into the response. Whether the real SQL returns these rows —
 * that a LEFT JOIN to a multi-value field duplicates them, that the collation
 * compares the dates the way MySQL does — stays tests/integration's job.
 *
 * 'fest.field_estado_value' is written QUALIFIED in every fixture row on
 * purpose: its alias in the projection is 'status', which collides with node's
 * own published flag, and only the qualified key lets one flat row hold both.
 * That is what makes the estado filter and the answered `status` assertable at
 * the same time, which PaginationUnlimitedTest could not do.
 */
class ReceiptEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  /**
   * The unit every fixture request asks about, and its owner.
   */
  const UNIT = 45;
  const UID = 3;

  /**
   * The one state this endpoint exposes. Anything else is invisible.
   */
  const EXPOSED = 'Enviado';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

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
   * One 'recibo' row, carrying both halves of every joined column.
   *
   * @param array $spec
   *   'id', 'period' ('YYYY-MM-DD'), and optionally 'status' (the estado
   *   value, defaulting to the exposed one), 'unit' (defaulting to the fixture
   *   unit), 'published' (node status, defaulting to 1) and 'values' (a map of
   *   projected aliases to values, for the decimals).
   */
  private function receiptRow(array $spec) {
    $spec += [
      'status'    => self::EXPOSED,
      'unit'      => self::UNIT,
      'published' => '1',
      'period'    => NULL,
      'values'    => [],
    ];

    $row = [
      'nid'                       => (string) $spec['id'],
      'title'                     => 'Recibo ' . $spec['id'],
      'type'                      => 'recibo',
      // node's own published flag, which is what the n.status condition reads.
      'status'                    => (string) $spec['published'],
      'field_vivienda_target_id'  => (string) $spec['unit'],
      'unit_id'                   => (string) $spec['unit'],
      // Qualified: the projection aliases this to 'status'.
      'fest.field_estado_value'   => $spec['status'],
      'field_periodo_value'       => $spec['period'],
      'period_start'              => $spec['period'],
      'period_end'                => $spec['period'],
    ];

    return $spec['values'] + $row;
  }

  /**
   * Authenticates uid 3 as the OWNER of unit 45 and seeds the given receipts.
   *
   * @param array $receipts  Specs for receiptRow().
   * @param array $tables    Extra fixture tables, merged last (used by the
   *                         access cases to change who owns what).
   */
  private function seed(array $receipts, array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $rows = [];
    foreach ($receipts as $spec) {
      $rows[] = $this->receiptRow($spec);
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

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function request($unit_id = self::UNIT) {
    return myapi_test_capture(function () use ($unit_id) {
      myapi_receipt_dispatch($unit_id);
    });
  }

  /**
   * The ids of the answered receipts, in the answered order.
   */
  private function ids(array $result) {
    return array_column($result['json']['data']['receipts'], 'id');
  }

  /**
   * $count receipts, ids 1..$count, one per consecutive day of June 2026.
   */
  private function consecutive($count) {
    $receipts = [];
    for ($i = 1; $i <= $count; $i++) {
      $receipts[] = ['id' => $i, 'period' => sprintf('2026-06-%02d', $i)];
    }

    return $receipts;
  }

  /**
   * The queries run against the node table (the count first, then the fetch).
   */
  private function nodeQueries() {
    return myapi_test_db_queries('node');
  }

  /* -------------------------------------------------------------------------
   * Method routing.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, before any authentication and before
   * the access check: a POST from the legitimate owner is still 405.
   */
  public function testEveryMethodOtherThanGetIs405() {
    $this->seed($this->consecutive(1));

    foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->request();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame('Método no permitido.', $result['json']['error'], $method);
    }
  }

  /**
   * The 405 costs nothing: not one query, so a rejected verb never pays for
   * the token lookup nor for the allowlist.
   */
  public function testRejectedMethodRunsNoQuery() {
    $this->seed($this->consecutive(1));
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    $this->request();

    $this->assertSame([], myapi_test_db_queries());
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
   * The access token guard.
   * ---------------------------------------------------------------------- */

  /**
   * No Authorization header: 401, and nothing is read — not the allowlist, not
   * the receipts.
   */
  public function testMissingAuthorizationHeaderIs401AndReadsNothing() {
    $this->seed($this->consecutive(1));
    unset($_SERVER['HTTP_AUTHORIZATION']);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * An unknown, revoked or expired token, and a token whose user is gone or
   * blocked, are all 401 invalid_token — and none of them reaches the receipts.
   */
  public function testEveryFailingTokenIs401AndNeverReachesTheReceipts() {
    $cases = [
      'unknown'      => ['seed_token' => FALSE, 'user' => TRUE, 'overrides' => []],
      'revoked'      => ['seed_token' => TRUE, 'user' => TRUE, 'overrides' => ['revoked' => '1']],
      'expired'      => ['seed_token' => TRUE, 'user' => TRUE, 'overrides' => ['access_expires_at' => REQUEST_TIME - 1]],
      'deleted user' => ['seed_token' => TRUE, 'user' => FALSE, 'overrides' => []],
      'blocked user' => ['seed_token' => TRUE, 'user' => 'blocked', 'overrides' => []],
    ];

    foreach ($cases as $name => $case) {
      $this->seed($this->consecutive(1));
      $GLOBALS['myapi_test_users'] = [];

      if ($case['user'] === TRUE) {
        $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];
      }
      elseif ($case['user'] === 'blocked') {
        $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 0];
      }

      $tokens = $case['seed_token']
        ? [$case['overrides'] + [
          'id'                => '1',
          'uid'               => (string) self::UID,
          'access_token_hash' => myapi_token_hash(self::TOKEN),
          'revoked'           => '0',
          'access_expires_at' => REQUEST_TIME + 1800,
        ]]
        : [];

      $GLOBALS['myapi_test_db']['my_api_tokens'] = $tokens;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
      $this->assertSame([], $this->nodeQueries(), $name);
    }
  }

  /* -------------------------------------------------------------------------
   * The access rule: owner or occupant of THIS unit.
   * ---------------------------------------------------------------------- */

  /**
   * The owner of the unit gets its receipts.
   */
  public function testTheOwnerOfTheUnitGetsItsReceipts() {
    $this->seed($this->consecutive(2));

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertCount(2, $result['json']['data']['receipts']);
  }

  /**
   * The three relations are OR-ed: the legacy single-value occupant field and
   * the current multi-value one give exactly the same access as ownership.
   */
  public function testLegacyAndCurrentOccupantsGetTheSameAccessAsTheOwner() {
    $relations = [
      'field_data_field_ocupante'  => 'field_ocupante_target_id',
      'field_data_field_ocupantes' => 'field_ocupantes_target_id',
    ];

    foreach ($relations as $table => $column) {
      $this->seed($this->consecutive(1), [
        // No ownership row at all: the access can only come from this table.
        'field_data_field_propietario' => [],
        $table => [
          ['entity_id' => (string) self::UNIT, $column => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ],
      ]);

      $result = $this->request();

      $this->assertSame(200, $result['status'], $table);
      $this->assertCount(1, $result['json']['data']['receipts'], $table);
    }
  }

  /**
   * A unit the user is not related to is 403 unit_access_denied, and the
   * receipts are never queried.
   */
  public function testAForeignUnitIs403AndNeverQueriesTheReceipts() {
    $this->seed($this->consecutive(1));

    $result = $this->request(999);

    $this->assertSame(403, $result['status']);
    $this->assertSame('unit_access_denied', $result['json']['error_code']);
    $this->assertSame([], $this->nodeQueries());
  }

  /**
   * A unit that does not exist at all answers the SAME 403, byte for byte, as
   * a unit that exists and belongs to somebody else: the endpoint never
   * reveals whether a unit exists.
   */
  public function testANonExistentUnitIsIndistinguishableFromAForeignOne() {
    $this->seed($this->consecutive(1), [
      // Unit 77 exists and belongs to somebody else; unit 12345 does not exist.
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '77', 'field_propietario_target_id' => '900', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $foreign = $this->request(77);
    $missing = $this->request(12345);

    $this->assertSame(403, $foreign['status']);
    $this->assertSame($foreign['output'], $missing['output']);
  }

  /**
   * A relation row marked deleted does not grant access: the allowlist filters
   * on deleted = 0, and a field detached from the bundle would otherwise keep
   * handing out somebody else's bill.
   */
  public function testADeletedRelationRowDoesNotGrantAccess() {
    $this->seed($this->consecutive(1), [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '1', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame(403, $this->request()['status']);
  }

  /**
   * The access check reads the token's uid and never a uid from the request:
   * another user's relation to the unit does not let this caller in.
   */
  public function testTheAllowlistIsBuiltForTheTokenUidAndNotForAnyoneElse() {
    $this->seed($this->consecutive(1), [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => '900', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $result = $this->request();

    $this->assertSame(403, $result['status']);
    $lookups = myapi_test_db_queries('field_data_field_propietario');
    $this->assertCount(1, $lookups);
    // The uid comes off the token row, where the driver answers it as a
    // string; what matters is that it is the token's and not the request's.
    $this->assertSame(self::UID, (int) $lookups[0]['conditions'][0]['value']);
  }

  /* -------------------------------------------------------------------------
   * The estado filter: only 'Enviado' exists for this endpoint.
   * ---------------------------------------------------------------------- */

  /**
   * A receipt in any other state is invisible, and so is one with no estado
   * row at all — the join is an INNER one, so a NULL never matches.
   */
  public function testOnlyReceiptsInTheExposedStateAreReturned() {
    $this->seed([
      ['id' => 1, 'period' => '2026-06-01', 'status' => self::EXPOSED],
      ['id' => 2, 'period' => '2026-06-02', 'status' => 'Borrador'],
      ['id' => 3, 'period' => '2026-06-03', 'status' => 'Anulado'],
      ['id' => 4, 'period' => '2026-06-04', 'status' => NULL],
    ]);

    $result = $this->request();

    $this->assertSame([1], $this->ids($result));
  }

  /**
   * The hidden receipts are hidden from the COUNT as well: pagination.total
   * must describe the same set the list describes, or the client pages into
   * emptiness.
   */
  public function testHiddenReceiptsAreExcludedFromTheTotalToo() {
    $this->seed([
      ['id' => 1, 'period' => '2026-06-01', 'status' => self::EXPOSED],
      ['id' => 2, 'period' => '2026-06-02', 'status' => 'Borrador'],
      ['id' => 3, 'period' => '2026-06-03', 'status' => 'Borrador'],
    ]);

    $result = $this->request();

    $this->assertSame(1, $result['json']['data']['pagination']['total']);
    $this->assertSame(1, $result['json']['data']['pagination']['total_pages']);
  }

  /**
   * The answered `status` is the estado value and not node's published flag.
   * Because of the filter it is always the exposed state, which is what
   * docs/receipt.md promises the app.
   */
  public function testTheAnsweredStatusIsTheExposedState() {
    $this->seed($this->consecutive(1));

    $result = $this->request();

    $this->assertSame(self::EXPOSED, $result['json']['data']['receipts'][0]['status']);
  }

  /**
   * An unpublished node is excluded: the n.status condition is separate from
   * the estado one, and both have to hold.
   */
  public function testUnpublishedReceiptsAreExcluded() {
    $this->seed([
      ['id' => 1, 'period' => '2026-06-01'],
      ['id' => 2, 'period' => '2026-06-02', 'published' => '0'],
    ]);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * A node of another type is excluded even when it carries the same columns:
   * the type condition is what keeps a 'pagos' row out of the receipts list.
   */
  public function testANodeOfAnotherTypeIsExcluded() {
    $this->seed($this->consecutive(1));
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'pagos'] + $this->receiptRow(['id' => 99, 'period' => '2026-06-09']);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * A receipt of ANOTHER unit is excluded even when the caller legitimately
   * owns both: the listing is scoped to the unit in the route.
   */
  public function testAReceiptOfAnotherUnitIsExcluded() {
    $this->seed([
      ['id' => 1, 'period' => '2026-06-01', 'unit' => self::UNIT],
      ['id' => 2, 'period' => '2026-06-02', 'unit' => 77],
    ], [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '77', 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /* -------------------------------------------------------------------------
   * The query shape — what cannot be asserted through the answer.
   * ---------------------------------------------------------------------- */

  /**
   * Two queries hit the node table and no more: the count and the page. The
   * count carries no range, which is what makes pagination.total the size of
   * the set instead of the size of the page.
   */
  public function testTheEndpointRunsOneCountAndOneFetch() {
    $this->seed($this->consecutive(3));

    $this->request();

    $queries = $this->nodeQueries();
    $this->assertCount(2, $queries);
    $this->assertTrue($queries[0]['count'], 'the first node query is the count');
    $this->assertNull($queries[0]['range']);
    $this->assertFalse($queries[1]['count'], 'the second one is the page');
  }

  /**
   * The fetch orders by the period column and by nothing else, and the
   * direction is the resolved sort.
   */
  public function testTheFetchOrdersByThePeriodColumn() {
    $this->seed($this->consecutive(2));

    $this->request();

    $order = $this->nodeQueries()[1]['order'];
    $this->assertCount(1, $order);
    $this->assertSame('fper.field_periodo_value', $order[0]['field']);
    $this->assertSame('DESC', $order[0]['direction']);
  }

  /**
   * The estado condition travels on BOTH queries with the same value: the
   * count and the page describe one set.
   */
  public function testBothQueriesCarryTheSameEstadoCondition() {
    $this->seed($this->consecutive(2));

    $this->request();

    foreach ($this->nodeQueries() as $i => $query) {
      $values = array_column($query['conditions'], 'value', 'field');
      $this->assertSame('recibo', $values['n.type'], 'query ' . $i);
      $this->assertSame(1, $values['n.status'], 'query ' . $i);
      $this->assertSame(self::EXPOSED, $values['fest.field_estado_value'], 'query ' . $i);
      $this->assertSame(self::UNIT, $values['fv.field_vivienda_target_id'], 'query ' . $i);
    }
  }

  /* -------------------------------------------------------------------------
   * Pagination.
   * ---------------------------------------------------------------------- */

  /**
   * The documented defaults when nothing is asked for: page 1, limit 20,
   * newest first.
   */
  public function testTheDocumentedDefaults() {
    $this->seed($this->consecutive(3));

    $pagination = $this->request()['json']['data']['pagination'];

    $this->assertSame(['total' => 3, 'page' => 1, 'limit' => 20, 'total_pages' => 1], $pagination);
  }

  /**
   * total_pages is the ceiling of total/limit, and the last page is a partial
   * one rather than a rounded-down loss.
   */
  public function testTotalPagesIsTheCeilingOfTheDivision() {
    $this->seed($this->consecutive(21));
    $_GET['limit'] = '10';

    $result = $this->request();

    $this->assertSame(21, $result['json']['data']['pagination']['total']);
    $this->assertSame(3, $result['json']['data']['pagination']['total_pages']);
  }

  /**
   * A unit with no receipts is a 200 with an empty list and total_pages 0 —
   * not a 404 and not a 1.
   */
  public function testAUnitWithNoReceiptsIsAnEmptyTwoHundred() {
    $this->seed([]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['receipts']);
    $this->assertSame(['total' => 0, 'page' => 1, 'limit' => 20, 'total_pages' => 0], $result['json']['data']['pagination']);
    $this->assertStringContainsString('"receipts":[]', $result['output']);
  }

  /**
   * A page beyond the last one is a 200 with an empty list: the client is not
   * punished for asking, and total/total_pages still describe the whole set.
   */
  public function testAPageBeyondTheLastOneIsAnEmptyTwoHundred() {
    $this->seed($this->consecutive(3));
    $_GET['page'] = '9';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['receipts']);
    $this->assertSame(3, $result['json']['data']['pagination']['total']);
    $this->assertSame(9, $result['json']['data']['pagination']['page']);
  }

  /**
   * The page actually slices: page 2 of a limit of 2 answers the third and
   * fourth newest, with no overlap with page 1.
   */
  public function testThePageSlicesTheOrderedSet() {
    $this->seed($this->consecutive(5));
    $_GET['limit'] = '2';

    $_GET['page'] = '1';
    $first = $this->ids($this->request());

    $_GET['page'] = '2';
    $second = $this->ids($this->request());

    $this->assertSame([5, 4], $first);
    $this->assertSame([3, 2], $second);
    $this->assertSame([], array_intersect($first, $second));
  }

  /**
   * limit is clamped to [1, 50]: 0 and a negative value that is not the -1
   * sentinel fall back to the default, and anything above 50 is capped.
   */
  public function testLimitIsClampedToTheDocumentedRange() {
    $this->seed($this->consecutive(1));

    $cases = [
      '0'    => 20,
      '-5'   => 20,
      'abc'  => 20,
      ''     => 20,
      '1'    => 1,
      '50'   => 50,
      '51'   => 50,
      '9999' => 50,
    ];

    foreach ($cases as $sent => $expected) {
      $_GET['limit'] = $sent;

      $result = $this->request();

      $this->assertSame($expected, $result['json']['data']['pagination']['limit'], 'limit=' . $sent);
    }
  }

  /**
   * page falls back to 1 for every non-positive-integer value, silently and
   * never with a 422.
   */
  public function testPageFallsBackToOneForEveryMalformedValue() {
    $this->seed($this->consecutive(1));

    foreach (['0', '-1', 'abc', '', '1.5', '+2'] as $sent) {
      $_GET['page'] = $sent;

      $result = $this->request();

      $this->assertSame(200, $result['status'], 'page=' . $sent);
      $this->assertSame(1, $result['json']['data']['pagination']['page'], 'page=' . $sent);
    }
  }

  /**
   * AN ARRAY page/limit ANSWERS THE DEFAULT, SILENTLY.
   *
   * '?page[]=2' used to reach `ctype_digit((string) $_GET['page'])`, and
   * casting an array to a string raises "Array to string conversion" (a notice
   * on the PHP 7.4 production runs) before answering the literal 'Array',
   * which is not all digits and therefore fell back to the default. The ANSWER
   * was always right; the notice was the problem, because on a site with
   * display_errors on it lands INSIDE the JSON body.
   *
   * SPEC 121 recorded it as a finding and pinned it; SPEC 122 fixed it by
   * pulling the parse into myapi_parse_page_param() /
   * myapi_parse_limit_param(), which guard with is_scalar() first — the shape
   * myapi_parse_id_param() always had. This case now asserts the same answer
   * and NO notice; the error handler is what makes the absence assertable.
   */
  public function testAnArrayPageOrLimitAnswersTheDefaultSilently() {
    $this->seed($this->consecutive(1));

    $_GET['page'] = ['2'];
    $_GET['limit'] = ['5'];

    $notices = [];
    set_error_handler(function ($severity, $message) use (&$notices) {
      $notices[] = $message;

      return TRUE;
    });
    try {
      $result = $this->request();
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $notices, 'not one notice');

    $this->assertSame(200, $result['status']);
    $this->assertSame(1, $result['json']['data']['pagination']['page']);
    $this->assertSame(20, $result['json']['data']['pagination']['limit']);
  }

  /* -------------------------------------------------------------------------
   * Sorting.
   * ---------------------------------------------------------------------- */

  /**
   * The default is newest first, and ?sort=asc is exactly the reverse.
   */
  public function testSortAscIsExactlyTheReverseOfTheDefault() {
    $this->seed($this->consecutive(4));

    $default = $this->ids($this->request());

    $_GET['sort'] = 'asc';
    $asc = $this->ids($this->request());

    $this->assertSame([4, 3, 2, 1], $default);
    $this->assertSame(array_reverse($default), $asc);
  }

  /**
   * Any other value of ?sort falls back to descending, with no 422.
   */
  public function testAnyOtherSortValueFallsBackToDescending() {
    $this->seed($this->consecutive(3));

    foreach (['ASC', 'Desc', 'period', '', '1', ['asc']] as $value) {
      $_GET['sort'] = $value;

      $result = $this->request();

      $this->assertSame(200, $result['status'], json_encode($value));
      $this->assertSame([3, 2, 1], $this->ids($result), json_encode($value));
    }
  }

  /* -------------------------------------------------------------------------
   * The date range.
   * ---------------------------------------------------------------------- */

  /**
   * Both bounds are inclusive and filter on period_start only.
   */
  public function testBothBoundsAreInclusive() {
    $this->seed($this->consecutive(5));
    $_GET['date_from'] = '2026-06-02';
    $_GET['date_to'] = '2026-06-04';

    $result = $this->request();

    $this->assertSame([4, 3, 2], $this->ids($result));
    $this->assertSame(3, $result['json']['data']['pagination']['total']);
  }

  /**
   * Each bound works on its own.
   */
  public function testEachBoundWorksAlone() {
    $this->seed($this->consecutive(4));

    $_GET['date_from'] = '2026-06-03';
    $this->assertSame([4, 3], $this->ids($this->request()));

    $_GET = ['date_to' => '2026-06-02'];
    $this->assertSame([2, 1], $this->ids($this->request()));
  }

  /**
   * A malformed or non-calendar bound is ignored silently — as if absent — and
   * never answers a 422.
   */
  public function testAMalformedBoundIsIgnoredSilently() {
    $this->seed($this->consecutive(3));

    foreach (['2026-13-40', '01-06-2026', 'hoy', '2026-06', '2026-02-30', '', '20260601'] as $value) {
      $_GET = ['date_from' => $value];

      $result = $this->request();

      $this->assertSame(200, $result['status'], $value);
      $this->assertSame([3, 2, 1], $this->ids($result), $value);
    }
  }

  /**
   * checkdate() is what rejects the impossible calendar dates the regex would
   * otherwise accept, and it accepts a real leap day.
   */
  public function testTheCalendarIsCheckedAndALeapDayIsAccepted() {
    $this->seed([['id' => 1, 'period' => '2024-02-29']]);

    $_GET = ['date_from' => '2024-02-29', 'date_to' => '2024-02-29'];
    $this->assertSame([1], $this->ids($this->request()));

    // 2026 is not a leap year: the same day is not a date, so the bound is
    // dropped and the whole set comes back.
    $_GET = ['date_from' => '2026-02-29'];
    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * An inverted range drops BOTH bounds, so the answer is the unfiltered set
   * rather than an empty list.
   */
  public function testAnInvertedRangeDropsTheWholeFilter() {
    $this->seed($this->consecutive(3));
    $_GET['date_from'] = '2026-06-30';
    $_GET['date_to'] = '2026-06-01';

    $result = $this->request();

    $this->assertSame([3, 2, 1], $this->ids($result));
    $this->assertSame(3, $result['json']['data']['pagination']['total']);
  }

  /**
   * Equal bounds are NOT inverted: a single-day range answers that day.
   */
  public function testEqualBoundsSelectASingleDay() {
    $this->seed($this->consecutive(3));
    $_GET['date_from'] = '2026-06-02';
    $_GET['date_to'] = '2026-06-02';

    $this->assertSame([2], $this->ids($this->request()));
  }

  /**
   * A receipt with no period at all is excluded the moment a bound is given:
   * the condition sits on the left-joined column, and NULL compares to
   * nothing. Without a bound it is listed like any other.
   */
  public function testAReceiptWithNoPeriodIsExcludedOnlyWhenABoundIsGiven() {
    $this->seed([
      ['id' => 1, 'period' => '2026-06-01'],
      ['id' => 2, 'period' => NULL],
    ]);

    $this->assertSame([1, 2], $this->ids($this->request()));

    $_GET['date_from'] = '2026-01-01';
    $result = $this->request();
    $this->assertSame([1], $this->ids($result));
    $this->assertSame(1, $result['json']['data']['pagination']['total']);
  }

  /**
   * The bound compares the first ten characters only, so a stored datetime is
   * matched by a plain date — this is what the SUBSTR() in the fragment is for.
   */
  public function testAStoredDatetimeIsMatchedByAPlainDateBound() {
    $this->seed([['id' => 1, 'period' => '2026-06-15 00:00:00']]);
    $_GET['date_from'] = '2026-06-15';
    $_GET['date_to'] = '2026-06-15';

    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * The bounds narrow the COUNT as well, so total_pages describes the filtered
   * set and the client is never offered a page it cannot reach.
   */
  public function testTheBoundsNarrowTheCountToo() {
    $this->seed($this->consecutive(10));
    $_GET['date_from'] = '2026-06-08';
    $_GET['limit'] = '2';

    $result = $this->request();

    $this->assertSame(3, $result['json']['data']['pagination']['total']);
    $this->assertSame(2, $result['json']['data']['pagination']['total_pages']);
  }

  /**
   * THE TRAILING-NEWLINE HOLE, CLOSED BY SPEC 122.
   *
   * SPEC 73 added the 'D' modifier to the shared myapi_valid_iso_date() so
   * "2026-06-01\n" stopped passing as a date — without it PCRE lets '$' match
   * just before a trailing newline. This resource carried its own COPY of that
   * validator, one of six, and none of the copies got the modifier: a bound
   * with a trailing newline was accepted, travelled into the query with the
   * newline still in it, and silently excluded the very day it named (because
   * "2026-06-01\n" sorts after "2026-06-01").
   *
   * SPEC 121 recorded it and pinned the broken behaviour; SPEC 122 replaced
   * all six bodies with a one-line delegation to the shared helper. The case
   * now asserts both halves of the fix: the copy answers exactly what the
   * shared one answers, and the malformed bound is DROPPED rather than applied
   * — so the whole set comes back instead of a day going missing.
   */
  public function testATrailingNewlineBoundIsRejectedLikeTheSharedHelperDoes() {
    $this->assertNull(myapi_receipt_valid_date("2026-06-01\n"), 'the copy delegates now');
    $this->assertSame(myapi_valid_iso_date("2026-06-01\n"), myapi_receipt_valid_date("2026-06-01\n"));
    $this->assertSame('2026-06-01', myapi_receipt_valid_date('2026-06-01'), 'a real date still passes');

    $this->seed([['id' => 1, 'period' => '2026-06-01']]);
    $_GET['date_from'] = "2026-06-01\n";

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([1], $this->ids($result), 'the bound was dropped, not applied with the newline in it');
  }

  /* -------------------------------------------------------------------------
   * The response envelope.
   * ---------------------------------------------------------------------- */

  /**
   * The envelope is the documented one: success, one data object with exactly
   * two keys, and the pagination block with exactly four.
   */
  public function testTheEnvelopeHasTheDocumentedShape() {
    $this->seed($this->consecutive(1));

    $result = $this->request();

    $this->assertTrue($result['json']['success']);
    $this->assertSame(['receipts', 'pagination'], array_keys($result['json']['data']));
    $this->assertSame(['total', 'page', 'limit', 'total_pages'], array_keys($result['json']['data']['pagination']));
    $this->assertArrayNotHasKey('message', $result['json']);
  }

  /**
   * Every answered receipt carries the 40 documented keys — the mapper runs
   * for real inside the endpoint, not only in ReceiptBuildItemTest.
   */
  public function testEveryAnsweredReceiptCarriesTheFortyKeys() {
    $this->seed($this->consecutive(2));

    $result = $this->request();

    foreach ($result['json']['data']['receipts'] as $receipt) {
      $this->assertCount(40, $receipt);
      $this->assertSame(['id', 'title', 'unit_id', 'period_start', 'period_end', 'status'], array_slice(array_keys($receipt), 0, 6));
    }
  }

  /**
   * The decimals answered by the endpoint are floats and the absent ones are
   * null, end to end: a projected column the fixture does not carry is NULL,
   * which is exactly what a LEFT JOIN answers.
   *
   * The 0.00 is asserted on the RAW BODY and not on the decoded value: JSON
   * has one number type, so drupal_json_encode() writes a float zero as `0`
   * and json_decode() reads it back as an int. What travels to the app is
   * `"fee":0`, and that is what this pins — the distinction that matters is
   * `0` vs `null`, and it survives the round trip.
   */
  public function testTheDecimalsAreFloatsAndTheAbsentOnesAreNull() {
    $this->seed([['id' => 1, 'period' => '2026-06-01', 'values' => ['total' => '187.32', 'fee' => '0.00']]]);

    $result = $this->request();
    $receipt = $result['json']['data']['receipts'][0];

    $this->assertSame(187.32, $receipt['total']);
    $this->assertStringContainsString('"fee":0,', $result['output']);
    $this->assertNotNull($receipt['fee']);
    $this->assertNull($receipt['gym']);
    $this->assertStringContainsString('"gym":null', $result['output']);
  }

  /**
   * The response carries the no-store headers every JSON answer of this module
   * carries: a receipt is one resident's personal data, and no proxy may keep
   * it.
   */
  public function testTheResponseIsNotCacheable() {
    $this->seed($this->consecutive(1));

    $result = $this->request();

    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
    $this->assertSame('nosniff', $result['headers']['X-Content-Type-Options']);
  }

  /* -------------------------------------------------------------------------
   * The two pure parsers, exercised directly.
   * ---------------------------------------------------------------------- */

  /**
   * myapi_receipt_valid_date() answers the value for a real ISO calendar date
   * and NULL for everything else, including every non-string.
   */
  public function testValidDateAnswersTheValueOrNull() {
    $this->assertSame('2026-06-01', myapi_receipt_valid_date('2026-06-01'));
    $this->assertSame('2024-02-29', myapi_receipt_valid_date('2024-02-29'));

    foreach (['2026-6-1', '2026-13-01', '2026-00-10', '2026-06-31', 'x', '', '2026-06-01 00:00:00'] as $value) {
      $this->assertNull(myapi_receipt_valid_date($value), $value);
    }

    foreach ([NULL, 20260601, ['2026-06-01'], TRUE, 1.5] as $value) {
      $this->assertNull(myapi_receipt_valid_date($value), json_encode($value));
    }
  }

  /**
   * myapi_receipt_parse_date_range() answers both bounds, drops the malformed
   * ones independently, and drops BOTH when they are inverted.
   */
  public function testParseDateRangeAnswersTheDocumentedPairs() {
    $_GET = [];
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_receipt_parse_date_range());

    $_GET = ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'];
    $this->assertSame(['from' => '2026-06-01', 'to' => '2026-06-30'], myapi_receipt_parse_date_range());

    $_GET = ['date_from' => '2026-06-01', 'date_to' => 'nope'];
    $this->assertSame(['from' => '2026-06-01', 'to' => NULL], myapi_receipt_parse_date_range());

    $_GET = ['date_from' => 'nope', 'date_to' => '2026-06-30'];
    $this->assertSame(['from' => NULL, 'to' => '2026-06-30'], myapi_receipt_parse_date_range());

    $_GET = ['date_from' => '2026-06-30', 'date_to' => '2026-06-01'];
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_receipt_parse_date_range());

    // Equal bounds are not inverted.
    $_GET = ['date_from' => '2026-06-01', 'date_to' => '2026-06-01'];
    $this->assertSame(['from' => '2026-06-01', 'to' => '2026-06-01'], myapi_receipt_parse_date_range());
  }
}
