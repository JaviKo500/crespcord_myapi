<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/receipt.resource.inc';
require_once __DIR__ . '/../../resources/extra_fee.resource.inc';
require_once __DIR__ . '/../../resources/payment.resource.inc';

/**
 * End-to-end unit tests for the 'limit=-1' pagination sentinel (SPEC 15,
 * covered by SPEC 75).
 *
 * SPEC 15 replicated one mechanical change across three twin resources —
 * receipts, extra-fees and payments — and shipped with its verification being
 * a list of manual curl calls. That is the shape of change that rots: a later
 * fix applied to one of the three leaves the other two behind, and every
 * failure mode is a silent HTTP 200 with a plausible body.
 *
 * So NO case here tests a single resource. Every test method takes the
 * endpoint from a @dataProvider and runs the same assertion against all three,
 * which is the only structure that catches the divergence the spec invites.
 *
 * The endpoints are called the way hook_menu() calls them — through the
 * dispatcher, over fixture tables and a fixture Authorization header — and
 * what gets asserted is the JSON body the module prints and the status code it
 * sets. That is what makes these functional rather than a re-implementation of
 * the ternary: a test of the limit parse in isolation would see neither the
 * missing range() nor the negative total_pages, which are the two things that
 * actually break.
 *
 * Two things this layer does NOT prove, both inherited from SPEC 74's fixture
 * query builder and restated here because they bound every claim below:
 *
 *  - Joins are recorded, never resolved. A joined column is seeded flat on the
 *    row, so what these tests exercise is the PHP half of each endpoint: the
 *    limit parse, the page forcing, the total_pages arithmetic, the presence
 *    or absence of the range, and the mapping of rows into the response.
 *    Whether the real SQL returns these rows stays tests/integration's job.
 *  - The 'status' key of each returned item is a fixture artifact and is never
 *    asserted. All three resources alias their estado column to 'status',
 *    which is also the name of node's own published flag; the fixture carries
 *    one column per name, and the published flag is the one the n.status
 *    condition needs. The estado FILTER is still genuinely exercised — it
 *    reads the raw 'field_estado*_value' column, which the fixtures do carry
 *    and which testUnlimitedStillAppliesTheStatusFilter() depends on.
 */
class PaginationUnlimitedTest extends TestCase {

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
   * What differs between the three twins, and nothing else.
   *
   * 'exposed'/'hidden' are values of the estado column on either side of each
   * resource's filter: receipts and extra fees expose 'Enviado' and hide
   * everything else, payments hide 'Nuevo' and expose everything else. The
   * filters are opposite in shape ('=' against '<>'), which is exactly why
   * both sides are named here per endpoint instead of being assumed.
   *
   * 'date_column' is the raw column the ORDER BY and the date bounds read;
   * 'date_alias' is the name the same value is projected under. A fixture row
   * carries both, because the fixture resolves no joins.
   */
  private const ENDPOINTS = [
    'payments' => [
      'dispatch'      => 'myapi_payment_dispatch',
      'key'           => 'payments',
      'node_type'     => 'pagos',
      'status_column' => 'field_estado_pago_value',
      'exposed'       => 'Verificado',
      'hidden'        => 'Nuevo',
      'date_column'   => 'field_fecha_de_pago_value',
      'date_alias'    => 'payment_date',
    ],
    'receipts' => [
      'dispatch'      => 'myapi_receipt_dispatch',
      'key'           => 'receipts',
      'node_type'     => 'recibo',
      'status_column' => 'field_estado_value',
      'exposed'       => 'Enviado',
      'hidden'        => 'Borrador',
      'date_column'   => 'field_periodo_value',
      'date_alias'    => 'period_start',
    ],
    'extra-fees' => [
      'dispatch'      => 'myapi_extra_fee_dispatch',
      'key'           => 'extra_fees',
      'node_type'     => 'alicuota_extra',
      'status_column' => 'field_estado_value',
      'exposed'       => 'Enviado',
      'hidden'        => 'Borrador',
      'date_column'   => 'field_fecha_value',
      'date_alias'    => 'date',
    ],
  ];

  /**
   * The three endpoints SPEC 15 changed. Every test runs against all three.
   */
  public function endpoints() {
    return [
      'payments'   => ['payments'],
      'receipts'   => ['receipts'],
      'extra-fees' => ['extra-fees'],
    ];
  }

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
   * One node row, carrying both halves of every joined column.
   *
   * @param array $spec  'id', 'date' and optionally 'status' ('exposed' or
   *                     'hidden'; defaults to exposed).
   */
  private function itemRow($endpoint, array $spec) {
    $config = self::ENDPOINTS[$endpoint];
    $state = (isset($spec['status']) && $spec['status'] === 'hidden')
      ? $config['hidden']
      : $config['exposed'];

    return [
      'nid'                      => (string) $spec['id'],
      'title'                    => 'Item ' . $spec['id'],
      'type'                     => $config['node_type'],
      // node's own published flag, which is what the n.status condition reads.
      'status'                   => '1',
      'field_vivienda_target_id' => (string) self::UNIT,
      'unit_id'                  => (string) self::UNIT,
      $config['status_column']   => $state,
      $config['date_column']     => $spec['date'],
      $config['date_alias']      => $spec['date'],
    ];
  }

  /**
   * Authenticates uid 3 as the owner of unit 45 and seeds the given items.
   *
   * @param array $items  Item specs for itemRow().
   */
  private function seed($endpoint, array $items) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $rows = [];
    foreach ($items as $spec) {
      $rows[] = $this->itemRow($endpoint, $spec);
    }

    myapi_test_db_seed([
      'my_api_tokens' => [[
        'id'                => '1',
        'uid'               => (string) self::UID,
        'access_token_hash' => myapi_token_hash(self::TOKEN),
        'revoked'           => '0',
        'access_expires_at' => REQUEST_TIME + 1800,
      ]],
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0'],
      ],
      'node' => $rows,
    ]);
  }

  /**
   * $count items, ids 1..$count, one per consecutive day, all exposed.
   *
   * Distinct dates on purpose: the ORDER BY is what these tests assert about
   * sorting, and ties would make the expected order depend on the fixture's
   * insertion order instead of on the sort direction.
   */
  private function seedConsecutive($endpoint, $count) {
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
      $items[] = ['id' => $i, 'date' => sprintf('2026-%02d-%02dT00:00:00', 1 + intdiv($i - 1, 28), 1 + ($i - 1) % 28)];
    }
    $this->seed($endpoint, $items);

    return $items;
  }

  /**
   * Calls the dispatcher the way hook_menu() does.
   */
  private function request($endpoint, array $query = [], $unit_id = self::UNIT) {
    $_GET = $query;
    $dispatch = self::ENDPOINTS[$endpoint]['dispatch'];

    return myapi_test_capture(function () use ($dispatch, $unit_id) {
      $dispatch($unit_id);
    });
  }

  /**
   * The items of a successful response, as a list of ids in response order.
   */
  private function idsOf($endpoint, array $response) {
    $this->assertSame(200, $response['status']);
    $this->assertTrue($response['json']['success']);

    return array_column($response['json']['data'][self::ENDPOINTS[$endpoint]['key']], 'id');
  }

  private function paginationOf(array $response) {
    return $response['json']['data']['pagination'];
  }

  /**
   * The recorded fetch query — the one over 'node' that is not the count.
   *
   * Both run against the same table in the same request, and they differ in
   * exactly the property these tests care about, so telling them apart by the
   * count flag rather than by position is what keeps the assertion honest if
   * the two calls are ever reordered.
   */
  private function fetchQuery() {
    foreach (myapi_test_db_queries('node') as $query) {
      if (!$query['count']) {
        return $query;
      }
    }

    return $this->fail('no fetch query was recorded');
  }

  private function countQueryRecord() {
    foreach (myapi_test_db_queries('node') as $query) {
      if ($query['count']) {
        return $query;
      }
    }

    return $this->fail('no count query was recorded');
  }

  /* -------------------------------------------------------------------------
   * The sentinel returns everything.
   * ---------------------------------------------------------------------- */

  /**
   * 63 items — more than the 50 ceiling and more than the 20 default — come
   * back in one response.
   *
   * The count is chosen above BOTH bounds on purpose: a regression that
   * reinstated either clamp would still return a full-looking array, and only
   * a fixture larger than both tells the three cases apart.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedReturnsEveryItem($endpoint) {
    $this->seedConsecutive($endpoint, 63);

    $ids = $this->idsOf($endpoint, $this->request($endpoint, ['limit' => '-1']));

    $this->assertCount(63, $ids);
  }

  /**
   * pagination.total is the number of items actually in the array.
   *
   * The total comes from a separate count query and the items from the fetch;
   * with the range dropped from only one of the two, the response would
   * contradict itself while both halves looked right on their own.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedTotalMatchesTheItemsReturned($endpoint) {
    $this->seedConsecutive($endpoint, 63);

    $response = $this->request($endpoint, ['limit' => '-1']);

    $this->assertSame(63, count($this->idsOf($endpoint, $response)));
    $this->assertSame(63, $this->paginationOf($response)['total']);
  }

  /**
   * The pagination block of an unlimited response: the sentinel echoed back,
   * page 1, and a single page.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedReportsTheSentinelAndASinglePage($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $pagination = $this->paginationOf($this->request($endpoint, ['limit' => '-1']));

    $this->assertSame(-1, $pagination['limit']);
    $this->assertSame(1, $pagination['page']);
    $this->assertSame(1, $pagination['total_pages']);
    $this->assertSame(7, $pagination['total']);
  }

  /**
   * A '?page' sent alongside the sentinel is ignored, not honoured.
   *
   * This is the case a client hits by accident: it keeps '?page=3' from the
   * previous request and adds 'limit=-1'. Without the forcing, page 3 would be
   * echoed back for a response that has no pages, and — worse — a lingering
   * range() would have skipped the first two pages' worth of items.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedIgnoresThePageParameter($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $response = $this->request($endpoint, ['limit' => '-1', 'page' => '3']);

    $this->assertSame(1, $this->paginationOf($response)['page']);
    $this->assertCount(7, $this->idsOf($endpoint, $response));
  }

  /**
   * An empty set is a 200 with an empty array, and total_pages is 0 — not 1,
   * which is what a naive "one page holds everything" would answer, and not a
   * negative number, which is what ceil($total / -1) would.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedOnAnEmptySetIsAnEmptyListNotAnError($endpoint) {
    $this->seed($endpoint, []);

    $response = $this->request($endpoint, ['limit' => '-1']);
    $pagination = $this->paginationOf($response);

    $this->assertSame([], $this->idsOf($endpoint, $response));
    $this->assertSame(0, $pagination['total']);
    $this->assertSame(0, $pagination['total_pages']);
    $this->assertSame(-1, $pagination['limit']);
    $this->assertSame(1, $pagination['page']);
  }

  /* -------------------------------------------------------------------------
   * The mechanism: the range.
   * ---------------------------------------------------------------------- */

  /**
   * The fetch query carries NO range when the sentinel is used.
   *
   * The one structural assertion of this class, and the reason it exists:
   * every other test here would still pass on a fixture small enough to fit in
   * one page. This one fails the moment the 'if ($limit !== -1)' guard around
   * range() is inverted or dropped, regardless of how many rows are seeded.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedSkipsTheRangeOnTheFetchQuery($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $this->request($endpoint, ['limit' => '-1']);

    $this->assertNull($this->fetchQuery()['range']);
  }

  /**
   * A paged request still ranges, with the offset the page implies. The
   * counterpart of the case above: it proves the guard is a condition and not
   * a range that was simply deleted.
   *
   * @dataProvider endpoints
   */
  public function testAPagedRequestStillCarriesItsRange($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $this->request($endpoint, ['limit' => '2', 'page' => '3']);

    $this->assertSame(['start' => 4, 'length' => 2], $this->fetchQuery()['range']);
  }

  /**
   * The count query never carries a range, paged or not.
   *
   * What makes pagination.total the size of the SET rather than the size of
   * the page. A count that inherited the range would agree with itself and be
   * wrong, and total_pages would be built on it.
   *
   * @dataProvider endpoints
   */
  public function testTheCountQueryNeverCarriesARange($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $this->request($endpoint, ['limit' => '2', 'page' => '2']);

    $this->assertNull($this->countQueryRecord()['range']);
    $this->assertSame(7, $this->paginationOf($this->request($endpoint, ['limit' => '2', 'page' => '2']))['total']);
  }

  /* -------------------------------------------------------------------------
   * The sentinel changes pagination and nothing else.
   * ---------------------------------------------------------------------- */

  /**
   * The estado filter still excludes what it excluded.
   *
   * "Returns everything" means every item of the FILTERED set, not every row
   * of the table. Both sides are seeded, and the hidden one must not appear —
   * for receipts and extra fees that is a '=' filter, for payments a '<>' one.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedStillAppliesTheStatusFilter($endpoint) {
    $this->seed($endpoint, [
      ['id' => 1, 'date' => '2026-01-01T00:00:00'],
      ['id' => 2, 'date' => '2026-01-02T00:00:00', 'status' => 'hidden'],
      ['id' => 3, 'date' => '2026-01-03T00:00:00'],
    ]);

    $response = $this->request($endpoint, ['limit' => '-1']);

    $this->assertSame([3, 1], $this->idsOf($endpoint, $response));
    $this->assertSame(2, $this->paginationOf($response)['total']);
  }

  /**
   * The date range still bounds the set, and the total still agrees with it.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedStillAppliesTheDateRange($endpoint) {
    $this->seed($endpoint, [
      ['id' => 1, 'date' => '2026-01-01T00:00:00'],
      ['id' => 2, 'date' => '2026-02-15T00:00:00'],
      ['id' => 3, 'date' => '2026-03-20T00:00:00'],
      ['id' => 4, 'date' => '2026-04-30T00:00:00'],
    ]);

    $response = $this->request($endpoint, [
      'limit'     => '-1',
      'date_from' => '2026-02-01',
      'date_to'   => '2026-03-31',
    ]);

    $this->assertSame([3, 2], $this->idsOf($endpoint, $response));
    $this->assertSame(2, $this->paginationOf($response)['total']);
  }

  /**
   * Both bounds are inclusive on their own day, with the stored datetime cut
   * to its date half — the SUBSTR(..., 1, 10) the three resources write.
   *
   * Without the cut, '2026-03-20T00:00:00' <= '2026-03-20' compares a longer
   * string against a shorter one and the last day of the range drops out.
   *
   * @dataProvider endpoints
   */
  public function testTheDateBoundsAreInclusiveOnTheirOwnDay($endpoint) {
    $this->seed($endpoint, [
      ['id' => 1, 'date' => '2026-02-15T00:00:00'],
      ['id' => 2, 'date' => '2026-03-20T23:59:00'],
    ]);

    $response = $this->request($endpoint, [
      'limit'     => '-1',
      'date_from' => '2026-02-15',
      'date_to'   => '2026-03-20',
    ]);

    $this->assertSame([2, 1], $this->idsOf($endpoint, $response));
  }

  /**
   * Sort direction still applies with the sentinel, in both directions.
   *
   * Asserted as the full ordered list rather than as a count: "returns
   * everything" is trivially true of a reversed list too.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedStillHonoursTheSortDirection($endpoint) {
    $this->seedConsecutive($endpoint, 4);

    $this->assertSame(
      [4, 3, 2, 1],
      $this->idsOf($endpoint, $this->request($endpoint, ['limit' => '-1'])),
      'desc is the default'
    );
    $this->assertSame(
      [4, 3, 2, 1],
      $this->idsOf($endpoint, $this->request($endpoint, ['limit' => '-1', 'sort' => 'desc']))
    );
    $this->assertSame(
      [1, 2, 3, 4],
      $this->idsOf($endpoint, $this->request($endpoint, ['limit' => '-1', 'sort' => 'asc']))
    );
  }

  /**
   * The unlimited response contains exactly what walking every page contains,
   * in the same order.
   *
   * The strongest functional statement of the spec, and the one that needs no
   * knowledge of the implementation: whatever the sentinel does, it must not
   * change WHICH items the endpoint exposes or in what order — only how many
   * requests it takes to see them.
   *
   * @dataProvider endpoints
   */
  public function testUnlimitedMatchesWalkingEveryPage($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $walked = [];
    for ($page = 1; $page <= 4; $page++) {
      $walked = array_merge($walked, $this->idsOf($endpoint, $this->request($endpoint, [
        'limit' => '2',
        'page'  => (string) $page,
      ])));
    }

    $this->assertSame($walked, $this->idsOf($endpoint, $this->request($endpoint, ['limit' => '-1'])));
    $this->assertCount(7, $walked, 'the walk itself covered the whole set');
  }

  /* -------------------------------------------------------------------------
   * Every other value of limit behaves exactly as it did before SPEC 15.
   * ---------------------------------------------------------------------- */

  /**
   * No '?limit' at all: the documented default of 20, with its range.
   *
   * @dataProvider endpoints
   */
  public function testLimitDefaultsToTwentyWhenAbsent($endpoint) {
    $this->seedConsecutive($endpoint, 25);

    $response = $this->request($endpoint, []);

    $this->assertCount(20, $this->idsOf($endpoint, $response));
    $this->assertSame(20, $this->paginationOf($response)['limit']);
    $this->assertSame(2, $this->paginationOf($response)['total_pages']);
    $this->assertSame(['start' => 0, 'length' => 20], $this->fetchQuery()['range']);
  }

  /**
   * The values that are NOT the sentinel and NOT a usable positive integer,
   * each falling back to 20.
   *
   * The near-misses are the point: '-1 ', ' -1' and '-01' all MEAN minus one
   * to a human, and the detection is a strict string comparison that must
   * reject all three. '0' and '-2' are the other side of the same boundary —
   * numeric, non-positive, and not the sentinel.
   *
   * @dataProvider rejectedLimits
   */
  public function testRejectedLimitValuesFallBackToTheDefault($limit) {
    foreach (array_keys(self::ENDPOINTS) as $endpoint) {
      $this->seedConsecutive($endpoint, 25);

      $response = $this->request($endpoint, ['limit' => $limit]);

      $this->assertSame(20, $this->paginationOf($response)['limit'], $endpoint . ' / ' . var_export($limit, TRUE));
      $this->assertCount(20, $this->idsOf($endpoint, $response), $endpoint . ' / ' . var_export($limit, TRUE));
    }
  }

  public function rejectedLimits() {
    return [
      'zero'                => ['0'],
      'minus two'           => ['-2'],
      'minus one hundred'   => ['-100'],
      'not numeric'         => ['abc'],
      'empty string'        => [''],
      'sentinel padded left'  => [' -1'],
      'sentinel padded right' => ['-1 '],
      'sentinel zero padded'  => ['-01'],
      'signed one'          => ['+1'],
      'decimal'             => ['1.0'],
      'hexadecimal'         => ['0x1'],
    ];
  }

  /**
   * A limit above the ceiling is clamped to 50, which is what keeps -1 the
   * ONLY way to ask for more than a page.
   *
   * @dataProvider endpoints
   */
  public function testLimitAboveTheCeilingIsClampedToFifty($endpoint) {
    $this->seedConsecutive($endpoint, 63);

    $response = $this->request($endpoint, ['limit' => '999']);

    $this->assertSame(50, $this->paginationOf($response)['limit']);
    $this->assertCount(50, $this->idsOf($endpoint, $response));
    $this->assertSame(['start' => 0, 'length' => 50], $this->fetchQuery()['range']);
  }

  /**
   * An ordinary positive limit still paginates, with the page honoured and
   * total_pages computed from the division — the branch the sentinel must not
   * have disturbed.
   *
   * @dataProvider endpoints
   */
  public function testAPositiveLimitStillPaginates($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $response = $this->request($endpoint, ['limit' => '2', 'page' => '2']);
    $pagination = $this->paginationOf($response);

    $this->assertSame([5, 4], $this->idsOf($endpoint, $response));
    $this->assertSame(2, $pagination['limit']);
    $this->assertSame(2, $pagination['page']);
    $this->assertSame(4, $pagination['total_pages'], '7 items in pages of 2');
    $this->assertSame(7, $pagination['total']);
  }

  /**
   * A page past the end is an empty array and not an error, with the totals
   * still describing the whole set.
   *
   * @dataProvider endpoints
   */
  public function testAPageBeyondTheEndIsEmptyAndNotAnError($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $response = $this->request($endpoint, ['limit' => '2', 'page' => '9']);

    $this->assertSame([], $this->idsOf($endpoint, $response));
    $this->assertSame(7, $this->paginationOf($response)['total']);
    $this->assertSame(4, $this->paginationOf($response)['total_pages']);
  }

  /**
   * An array '?limit[]=-1' is not the sentinel and falls back to the default,
   * SILENTLY since SPEC 122.
   *
   * This case used to assert the opposite: the fallback was correct, but
   * getting there cost a PHP warning, because the sentinel check is a string
   * comparison the array fails and the next branch cast it with (string) for
   * ctype_digit(). SPEC 75 asserted that warning rather than tolerating it,
   * and said out loud that hardening the parse should make this case fail and
   * be updated deliberately. SPEC 122 hardened it — myapi_parse_limit_param()
   * guards with is_scalar() first — so this is that deliberate update: same
   * answer, no warning.
   *
   * @dataProvider endpoints
   */
  public function testAnArrayLimitIsNotTheSentinelAndIsSilent($endpoint) {
    $this->seedConsecutive($endpoint, 25);

    $warnings = [];
    set_error_handler(function ($severity, $message) use (&$warnings) {
      $warnings[] = $message;

      return TRUE;
    });

    try {
      $response = $this->request($endpoint, ['limit' => ['-1']]);
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame(20, $this->paginationOf($response)['limit']);
    $this->assertCount(20, $this->idsOf($endpoint, $response));
    $this->assertSame([], $warnings, 'the array-to-string cast is guarded now');
  }

  /* -------------------------------------------------------------------------
   * The guards the sentinel must not have weakened.
   * ---------------------------------------------------------------------- */

  /**
   * A unit the caller is not related to is still 403, sentinel or not.
   *
   * The access check runs before the limit is even parsed, and 'limit=-1' is
   * the request that would leak the most if it did not.
   *
   * @dataProvider endpoints
   */
  public function testAccessIsStillDeniedWithTheSentinel($endpoint) {
    $this->seedConsecutive($endpoint, 7);

    $response = $this->request($endpoint, ['limit' => '-1'], 999);

    $this->assertSame(403, $response['status']);
    $this->assertFalse($response['json']['success']);
    $this->assertSame('unit_access_denied', $response['json']['error_code']);
  }

  /**
   * No Authorization header is still 401, sentinel or not.
   *
   * @dataProvider endpoints
   */
  public function testAuthenticationIsStillRequiredWithTheSentinel($endpoint) {
    $this->seedConsecutive($endpoint, 7);
    unset($_SERVER['HTTP_AUTHORIZATION']);

    $response = $this->request($endpoint, ['limit' => '-1']);

    $this->assertSame(401, $response['status']);
    $this->assertSame('missing_authorization', $response['json']['error_code']);
  }

  /**
   * The method guard is unchanged: these endpoints are GET-only, and a
   * sentinel in the query string does not open a second door.
   *
   * @dataProvider endpoints
   */
  public function testTheMethodGuardIsUnchangedWithTheSentinel($endpoint) {
    $this->seedConsecutive($endpoint, 7);
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $response = $this->request($endpoint, ['limit' => '-1']);

    $this->assertSame(405, $response['status']);
    $this->assertSame('method_not_allowed', $response['json']['error_code']);
  }

  /**
   * The response envelope of an unlimited request is the ordinary success
   * envelope: 'success' and 'data', no 'message' — SPEC 03's contract, which
   * SPEC 15 had no business changing.
   *
   * @dataProvider endpoints
   */
  public function testTheEnvelopeIsUnchangedByTheSentinel($endpoint) {
    $this->seedConsecutive($endpoint, 3);

    $response = $this->request($endpoint, ['limit' => '-1']);

    $this->assertSame(['success', 'data'], array_keys($response['json']));
    $this->assertSame(
      [self::ENDPOINTS[$endpoint]['key'], 'pagination'],
      array_keys($response['json']['data'])
    );
    $this->assertSame('application/json', $response['headers']['Content-Type']);
  }

}
