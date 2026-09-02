<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../resources/area.resource.inc';

/**
 * End-to-end unit tests for the three area endpoints (SPECS 33, 39, 40 and 45,
 * covered by SPEC 121).
 *
 * AreaSearchTest already covers the ?search half of the listing — the two
 * mechanisms that resolve a needle to a name and to a category. What is
 * exercised here is everything else, and above all the property the three
 * endpoints share and must never lose:
 *
 *   AN AREA THAT IS NOT VISIBLE IS NOT REVEALED, ANYWHERE.
 *
 * Visibility is by INCLUSION (field_area_status in {active, maintenance}) and
 * the detail and the availability endpoints reuse the very same base select,
 * so a 'closed' area — or one with no status row — is absent from the list AND
 * answers the same 404 as an area that never existed, in another condominium
 * or with a malformed id. Four different reasons, one answer: that is the
 * contract, and the way to break it is to add a branch that answers 403 for
 * one of them.
 *
 * The fixture rows are FLAT, one per area, carrying the columns the joins
 * would have produced — 'fsta.field_area_status_value' qualified because its
 * alias in the projection is 'status', which collides with node.status.
 */
class AreaEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

  const CONDOMINIUM = 12;
  const OTHER_CONDOMINIUM = 99;
  const UNIT = 45;
  const UID = 3;
  const AREA = 700;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_field_seed_allowed_values();
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
   * One 'area' row, carrying every projected column flat.
   */
  private function areaRow(array $spec = []) {
    $spec += [
      'id'          => self::AREA,
      'name'        => 'Piscina',
      'condominium' => self::CONDOMINIUM,
      'state'       => 'active',
      'published'   => '1',
      'open'        => '08:00',
      'close'       => '22:00',
      'capacity'    => NULL,
      'image'       => NULL,
      'image_uri'   => NULL,
      'category'    => 'pool',
      'notes'       => NULL,
      'slot'        => NULL,
      'max'         => NULL,
      'who'         => NULL,
      'deadline'    => NULL,
    ];

    return [
      'nid'                          => (string) $spec['id'],
      'title'                        => $spec['name'],
      'type'                         => 'area',
      'status'                       => (string) $spec['published'],
      'field_condominium_target_id'  => (string) $spec['condominium'],
      'condominium_id'               => (string) $spec['condominium'],
      'fsta.field_area_status_value' => $spec['state'],
      'image_id'                     => $spec['image'] === NULL ? NULL : (string) $spec['image'],
      'image_uri'                    => $spec['image_uri'],
      'open_time'                    => $spec['open'],
      'close_time'                   => $spec['close'],
      'slot_minutes'                 => $spec['slot'] === NULL ? NULL : (string) $spec['slot'],
      'max_minutes'                  => $spec['max'] === NULL ? NULL : (string) $spec['max'],
      'who_can_reserve'              => $spec['who'],
      'cancel_deadline_minutes'      => $spec['deadline'] === NULL ? NULL : (string) $spec['deadline'],
      'field_area_category_value'    => $spec['category'],
      'category'                     => $spec['category'],
      'notes'                        => $spec['notes'],
      'max_concurrent_reservations'  => $spec['capacity'] === NULL ? NULL : (string) $spec['capacity'],
    ];
  }

  /**
   * One confirmed 'reservation' row, as myapi_reservation_busy_rows() reads it.
   */
  private function reservationRow(array $spec) {
    $spec += ['area' => self::AREA, 'state' => 'confirmed', 'published' => '1'];

    return [
      'nid'                                => (string) $spec['id'],
      'type'                               => 'reservation',
      'status'                             => (string) $spec['published'],
      'field_area_target_id'               => (string) $spec['area'],
      'field_reservation_status_value'     => $spec['state'],
      'field_date_value'                   => $spec['date'],
      'date'                               => $spec['date'],
      'field_start_time_value'             => $spec['start'],
      'start_time'                         => $spec['start'],
      'field_end_time_value'               => $spec['end'],
      'end_time'                           => $spec['end'],
    ];
  }

  /**
   * Authenticates uid 3 as the owner of unit 45 in condominium 12.
   */
  private function seed(array $areas = [], array $extra_nodes = [], array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $rows = [];
    foreach ($areas as $spec) {
      $rows[] = $this->areaRow($spec);
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
      'node' => array_merge($rows, $extra_nodes),
    ]);
  }

  private function listRequest($condominium_id = self::CONDOMINIUM) {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture(function () use ($condominium_id) {
      myapi_area_dispatch($condominium_id);
    });
  }

  private function detailRequest($area_id = self::AREA) {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture(function () use ($area_id) {
      myapi_area_details_dispatch($area_id);
    });
  }

  private function availabilityRequest($area_id = self::AREA) {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture(function () use ($area_id) {
      myapi_area_availability_dispatch($area_id);
    });
  }

  private function ids(array $result) {
    return array_column($result['json']['data']['areas'], 'id');
  }

  /* -------------------------------------------------------------------------
   * The three dispatchers.
   * ---------------------------------------------------------------------- */

  /**
   * All three routes are GET-only, and every rejection is a 405 that runs no
   * query.
   */
  public function testTheThreeDispatchersAreGetOnly() {
    $calls = [
      'list'         => function () { myapi_area_dispatch(AreaEndpointTest::CONDOMINIUM); },
      'detail'       => function () { myapi_area_details_dispatch(AreaEndpointTest::AREA); },
      'availability' => function () { myapi_area_availability_dispatch(AreaEndpointTest::AREA); },
    ];

    foreach ($calls as $name => $call) {
      foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
        $this->seed([[]]);
        $_SERVER['REQUEST_METHOD'] = $method;

        $result = myapi_test_capture($call);

        $this->assertSame(405, $result['status'], $name . ' ' . $method);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $name . ' ' . $method);
        $this->assertSame([], myapi_test_db_queries(), $name . ' ' . $method);
      }
    }
  }

  /**
   * All three require a valid token, and none of them reads an area when it
   * fails.
   */
  public function testAllThreeEndpointsRequireAValidToken() {
    $requests = [
      'list'         => function () { return $this->listRequest(); },
      'detail'       => function () { return $this->detailRequest(); },
      'availability' => function () { return $this->availabilityRequest(); },
    ];

    foreach ($requests as $name => $request) {
      $this->seed([[]]);
      unset($_SERVER['HTTP_AUTHORIZATION']);
      $result = $request();
      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries(), $name);

      foreach ([
        'unknown' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'] = []; },
        'revoked' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['revoked'] = '1'; },
        'expired' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['access_expires_at'] = REQUEST_TIME - 1; },
        'blocked' => function () { $GLOBALS['myapi_test_users'][AreaEndpointTest::UID]['status'] = 0; },
      ] as $break_name => $break) {
        $this->seed([[]]);
        $break();

        $result = $request();

        $this->assertSame(401, $result['status'], $name . ' ' . $break_name);
        $this->assertSame([], myapi_test_db_queries('node'), $name . ' ' . $break_name);
      }
    }
  }

  /* -------------------------------------------------------------------------
   * GET /condominiums/%/areas — access and visibility.
   * ---------------------------------------------------------------------- */

  /**
   * A resident of the condominium reads its areas.
   */
  public function testAResidentReadsTheAreasOfTheirCondominium() {
    $this->seed([['id' => 700, 'name' => 'Piscina'], ['id' => 701, 'name' => 'Gimnasio']]);

    $result = $this->listRequest();

    $this->assertSame(200, $result['status']);
    $this->assertCount(2, $result['json']['data']['areas']);
  }

  /**
   * A condominium the caller has no unit in is 403, and a non-existent one
   * answers the same bytes — the list collapses both into one answer.
   */
  public function testAForeignOrMissingCondominiumIsTheSame403() {
    $this->seed([[]], [], [
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '88', 'field_condominio_target_id' => (string) self::OTHER_CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $foreign = $this->listRequest(self::OTHER_CONDOMINIUM);
    $missing = $this->listRequest(4242);

    $this->assertSame(403, $foreign['status']);
    $this->assertSame('condominium_access_denied', $foreign['json']['error_code']);
    $this->assertSame($foreign['output'], $missing['output']);
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /**
   * VISIBILITY IS BY INCLUSION: only 'active' and 'maintenance' are listed.
   * 'closed', an unknown state and an area with no status row are all hidden —
   * "no status" is not a safe state to show.
   */
  public function testOnlyTheTwoVisibleStatusesAreListed() {
    $this->seed([
      ['id' => 700, 'name' => 'A activa', 'state' => 'active'],
      ['id' => 701, 'name' => 'B mantenimiento', 'state' => 'maintenance'],
      ['id' => 702, 'name' => 'C cerrada', 'state' => 'closed'],
      ['id' => 703, 'name' => 'D estado nuevo', 'state' => 'draft'],
      ['id' => 704, 'name' => 'E sin estado', 'state' => NULL],
    ]);

    $result = $this->listRequest();

    $this->assertSame([701, 700], $this->ids($result), 'ordered by title descending');
    $this->assertSame(2, $result['json']['data']['pagination']['total']);
  }

  /**
   * Type, published flag and condominium narrow the listing too.
   */
  public function testTheOtherThreeConditionsHold() {
    $this->seed([
      ['id' => 700, 'name' => 'A'],
      ['id' => 701, 'name' => 'B', 'published' => '0'],
      ['id' => 702, 'name' => 'C', 'condominium' => self::OTHER_CONDOMINIUM],
    ]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'reservation'] + $this->areaRow(['id' => 703, 'name' => 'D']);

    $this->assertSame([700], $this->ids($this->listRequest()));
  }

  /* -------------------------------------------------------------------------
   * The listing: order and pagination.
   * ---------------------------------------------------------------------- */

  /**
   * The default order is by title DESCENDING — which is unusual and therefore
   * worth pinning — with nid as a deterministic tie-breaker in the same
   * direction.
   */
  public function testTheDefaultOrderIsTitleDescendingWithANidTieBreaker() {
    $this->seed([
      ['id' => 700, 'name' => 'Piscina'],
      ['id' => 701, 'name' => 'Gimnasio'],
      ['id' => 702, 'name' => 'Piscina'],
    ]);

    $this->assertSame([702, 700, 701], $this->ids($this->listRequest()));

    $_GET['sort'] = 'asc';
    $this->assertSame([701, 700, 702], $this->ids($this->listRequest()));

    $this->seed([['id' => 700]]);
    $_GET['sort'] = 'asc';
    $this->listRequest();
    $this->assertSame([
      ['field' => 'n.title', 'direction' => 'ASC'],
      ['field' => 'n.nid', 'direction' => 'ASC'],
    ], myapi_test_db_queries('node')[1]['order']);
  }

  /**
   * Any other ?sort value falls back to descending.
   */
  public function testAnyOtherSortValueFallsBackToDescending() {
    $this->seed([['id' => 700, 'name' => 'A'], ['id' => 701, 'name' => 'B']]);

    foreach (['ASC', 'Desc', 'title', '', ['asc']] as $value) {
      $_GET['sort'] = $value;
      $this->assertSame([701, 700], $this->ids($this->listRequest()), json_encode($value));
    }
  }

  /**
   * The documented defaults, the clamping, the slicing, and the '-1' sentinel
   * — which this listing DOES have, unlike bulletins.
   */
  public function testPaginationIncludingTheUnlimitedSentinel() {
    $areas = [];
    for ($i = 1; $i <= 7; $i++) {
      $areas[] = ['id' => 700 + $i, 'name' => 'Area ' . $i];
    }
    $this->seed($areas);

    $this->assertSame(
      ['total' => 7, 'page' => 1, 'limit' => 20, 'total_pages' => 1],
      $this->listRequest()['json']['data']['pagination']
    );

    foreach (['0' => 20, 'x' => 20, '51' => 50, '3' => 3] as $sent => $expected) {
      $_GET = ['limit' => (string) $sent];
      $this->assertSame($expected, $this->listRequest()['json']['data']['pagination']['limit'], 'limit=' . $sent);
    }

    $_GET = ['limit' => '3', 'page' => '3'];
    $result = $this->listRequest();
    $this->assertSame(3, $result['json']['data']['pagination']['total_pages']);
    $this->assertCount(1, $result['json']['data']['areas']);

    // Re-seeded so the recorded queries belong to this request alone.
    $this->seed($areas);
    $_GET = ['limit' => '-1', 'page' => '4'];
    $result = $this->listRequest();
    $this->assertCount(7, $result['json']['data']['areas']);
    $this->assertSame(-1, $result['json']['data']['pagination']['limit']);
    $this->assertSame(1, $result['json']['data']['pagination']['page']);
    $this->assertNull(myapi_test_db_queries('node')[1]['range'], 'no range was applied');
  }

  /**
   * A condominium with no visible area is a 200 with an empty array.
   */
  public function testACondominiumWithNoVisibleAreaIsAnEmptyTwoHundred() {
    $this->seed([['id' => 700, 'state' => 'closed']]);

    $result = $this->listRequest();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['areas']);
    $this->assertSame(0, $result['json']['data']['pagination']['total_pages']);
    $this->assertStringContainsString('"areas":[]', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * GET /areas/% — the detail.
   * ---------------------------------------------------------------------- */

  /**
   * The detail of a visible area of the caller's condominium answers 200 with
   * the SAME item shape as the list, wrapped as {"area": ...}.
   */
  public function testTheDetailAnswersTheSameItemShapeAsTheList() {
    $this->seed([['id' => self::AREA, 'name' => 'Piscina']]);

    $listed = $this->listRequest()['json']['data']['areas'][0];
    $detail = $this->detailRequest();

    $this->assertSame(200, $detail['status']);
    $this->assertSame(['area'], array_keys($detail['json']['data']));
    $this->assertSame($listed, $detail['json']['data']['area']);
    $this->assertArrayNotHasKey('message', $detail['json']);
  }

  /**
   * THE FIVE WAYS OF NOT SEEING AN AREA ANSWER THE SAME 404, byte for byte: a
   * malformed id, a missing node, another bundle, a hidden status, and an area
   * of a condominium the caller is not related to. Any of them answering
   * something else would tell the caller that the area exists.
   */
  public function testEveryInvisibleAreaIsTheSame404() {
    $this->seed([
      ['id' => 700, 'name' => 'Visible'],
      ['id' => 701, 'name' => 'Cerrada', 'state' => 'closed'],
      ['id' => 702, 'name' => 'De otro condominio', 'condominium' => self::OTHER_CONDOMINIUM],
      ['id' => 703, 'name' => 'No publicada', 'published' => '0'],
    ]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'reservation'] + $this->areaRow(['id' => 704]);

    $baseline = $this->detailRequest(4242);
    $this->assertSame(404, $baseline['status']);
    $this->assertSame('area_not_found', $baseline['json']['error_code']);

    foreach ([701, 702, 703, 704] as $area_id) {
      $this->assertSame($baseline['output'], $this->detailRequest($area_id)['output'], 'area ' . $area_id);
    }

    foreach (['abc', '0', '-1', ''] as $id) {
      $this->assertSame($baseline['output'], $this->detailRequest($id)['output'], json_encode($id));
    }
  }

  /**
   * A malformed id is refused before any query: the ctype_digit() guard comes
   * first, so a junk id costs one token lookup and nothing else.
   */
  public function testAMalformedIdRunsNoAreaQuery() {
    $this->seed([['id' => self::AREA]]);

    $this->detailRequest('abc');

    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /* -------------------------------------------------------------------------
   * The item mapper.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly the fifteen documented keys, in order.
   */
  public function testTheItemHasExactlyTheFifteenDocumentedKeysInOrder() {
    $this->seed([['id' => self::AREA]]);

    $item = $this->detailRequest()['json']['data']['area'];

    $this->assertSame([
      'id', 'name', 'condominium_id', 'image_id', 'image_url', 'open_time',
      'close_time', 'slot_minutes', 'max_minutes', 'status', 'who_can_reserve',
      'cancel_deadline_minutes', 'category', 'notes', 'max_concurrent_reservations',
    ], array_keys($item));
  }

  /**
   * The guarded int casts: present becomes an int, absent stays null and never
   * becomes 0 — a slot_minutes of 0 would make the app draw no slots at all.
   */
  public function testTheGuardedIntCastsKeepNullApartFromZero() {
    $this->seed([['id' => self::AREA, 'slot' => 60, 'max' => 180, 'deadline' => 120, 'image' => 77, 'image_uri' => 'public://areas/pool.png']]);
    $item = $this->detailRequest()['json']['data']['area'];
    $this->assertSame(60, $item['slot_minutes']);
    $this->assertSame(180, $item['max_minutes']);
    $this->assertSame(120, $item['cancel_deadline_minutes']);
    $this->assertSame(77, $item['image_id']);

    $this->seed([['id' => self::AREA]]);
    $result = $this->detailRequest();
    $item = $result['json']['data']['area'];
    $this->assertNull($item['slot_minutes']);
    $this->assertNull($item['max_minutes']);
    $this->assertNull($item['cancel_deadline_minutes']);
    $this->assertNull($item['image_id']);
    $this->assertStringContainsString('"slot_minutes":null', $result['output']);
  }

  /**
   * The image URL is built from the JOINED file_managed uri — no per-row
   * file_load() — and is null together with the id when there is no image.
   */
  public function testTheImageUrlComesFromTheJoinedUri() {
    $this->seed([['id' => self::AREA, 'image' => 77, 'image_uri' => 'public://areas/pool.png']]);
    $item = $this->detailRequest()['json']['data']['area'];
    $this->assertSame(file_create_url('public://areas/pool.png'), $item['image_url']);

    $this->seed([['id' => self::AREA]]);
    $item = $this->detailRequest()['json']['data']['area'];
    $this->assertNull($item['image_url']);
    $this->assertNull($item['image_id']);
  }

  /**
   * The text fields travel RAW — notes in particular is never sanitised here,
   * which is a documented property of this mapper and not an oversight.
   */
  public function testTheTextFieldsTravelRaw() {
    $this->seed([['id' => self::AREA, 'name' => 'Piscina <b>', 'notes' => '<p>Traer gorro</p>', 'who' => 'owners', 'category' => 'pool']]);

    $item = $this->detailRequest()['json']['data']['area'];

    $this->assertSame('Piscina <b>', $item['name']);
    $this->assertSame('<p>Traer gorro</p>', $item['notes']);
    $this->assertSame('owners', $item['who_can_reserve']);
    $this->assertSame('pool', $item['category']);
    $this->assertSame('08:00', $item['open_time']);
    $this->assertSame('22:00', $item['close_time']);
  }

  /**
   * THE ONE KEY THAT DEVIATES: max_concurrent_reservations is answered already
   * NORMALISED — always an int, always >= 1 — so the client never has to
   * re-implement the fail-closed rule the server validates with.
   */
  public function testTheCapacityIsAnsweredNormalisedAndNeverNull() {
    $cases = [
      'sin fila'   => [NULL, 1],
      'cero'       => ['0', 1],
      'negativa'   => ['-3', 1],
      'basura'     => ['muchas', 1],
      'uno'        => ['1', 1],
      'cuatro'     => ['4', 4],
    ];

    foreach ($cases as $name => $case) {
      $this->seed([['id' => self::AREA, 'capacity' => $case[0]]]);

      $item = $this->detailRequest()['json']['data']['area'];

      $this->assertSame($case[1], $item['max_concurrent_reservations'], $name);
      $this->assertIsInt($item['max_concurrent_reservations'], $name);
    }
  }

  /* -------------------------------------------------------------------------
   * GET /areas/%/availability.
   * ---------------------------------------------------------------------- */

  /**
   * The availability of an invisible area is the SAME 404 as the detail's —
   * the two endpoints share the criterion, so a hidden area does not leak
   * through the busy ranges either.
   */
  public function testTheAvailabilityOfAnInvisibleAreaIsTheSame404() {
    $this->seed([
      ['id' => 700, 'name' => 'Visible'],
      ['id' => 701, 'name' => 'Cerrada', 'state' => 'closed'],
      ['id' => 702, 'name' => 'Ajena', 'condominium' => self::OTHER_CONDOMINIUM],
    ]);
    $_GET['date'] = '2026-06-15';

    $baseline = $this->availabilityRequest(4242);
    $this->assertSame(404, $baseline['status']);
    $this->assertSame('area_not_found', $baseline['json']['error_code']);

    foreach ([701, 702, 'abc', '0'] as $area_id) {
      $this->assertSame($baseline['output'], $this->availabilityRequest($area_id)['output'], json_encode($area_id));
    }
  }

  /**
   * THE 404 COMES BEFORE THE DATE VALIDATION. Asking for the availability of
   * somebody else's area with a malformed date answers the 404, not the 422 —
   * otherwise the 422 would confirm that the area exists.
   */
  public function testTheAreaGuardIsResolvedBeforeTheDate() {
    $this->seed([['id' => 701, 'state' => 'closed']]);
    $_GET = [];

    $this->assertSame(404, $this->availabilityRequest(701)['status']);

    $_GET['date'] = 'no-es-fecha';
    $this->assertSame(404, $this->availabilityRequest(701)['status']);
  }

  /**
   * The date is validated STRICTLY here — unlike every listing filter of this
   * module, where a malformed bound is ignored. Absent is missing_field,
   * malformed or impossible is invalid_field, and both name the parameter.
   */
  public function testTheDateIsValidatedStrictly() {
    $this->seed([['id' => self::AREA]]);

    $_GET = [];
    $absent = $this->availabilityRequest();
    $this->assertSame(422, $absent['status']);
    $this->assertSame('missing_field', $absent['json']['error_code']);
    $this->assertStringContainsString('date', $absent['json']['error']);

    $_GET = ['date' => ''];
    $this->assertSame('missing_field', $this->availabilityRequest()['json']['error_code'], 'empty is absent');

    $_GET = ['date' => ['2026-06-15']];
    $this->assertSame('missing_field', $this->availabilityRequest()['json']['error_code'], 'an array is absent');

    // "2026-06-15\n" is in the list since SPEC 122: without the 'D' modifier
    // PCRE let '$' match just before a trailing newline, so the availability
    // of a whole day was answered for a date that carried one.
    foreach (['15-06-2026', '2026-6-15', '2026-13-01', '2026-02-30', 'hoy', '2026-06-15T00:00:00', "2026-06-15\n"] as $value) {
      $_GET = ['date' => $value];

      $result = $this->availabilityRequest();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
      $this->assertStringContainsString('date', $result['json']['error'], $value);
    }
  }

  /**
   * The answer has exactly four keys, and the date is echoed back as received.
   */
  public function testTheAnswerHasTheFourDocumentedKeys() {
    $this->seed([['id' => self::AREA]]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['date', 'capacity', 'busy', 'occupancy'], array_keys($result['json']['data']));
    $this->assertSame('2026-06-15', $result['json']['data']['date']);
  }

  /**
   * A day with no reservation answers empty lists and the area's capacity —
   * never a 404 and never a null.
   */
  public function testAFreeDayAnswersEmptyLists() {
    $this->seed([['id' => self::AREA, 'capacity' => 3]]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();

    $this->assertSame(3, $result['json']['data']['capacity']);
    $this->assertSame([], $result['json']['data']['busy']);
    $this->assertSame([], $result['json']['data']['occupancy']);
    $this->assertStringContainsString('"busy":[]', $result['output']);
  }

  /**
   * IN A CAPACITY-1 AREA 'busy' IS ONE ENTRY PER RESERVATION, deliberately NOT
   * merged: two consecutive bookings stay two blocks, because merging them
   * would change the payload every area in production already answers.
   */
  public function testInACapacityOneAreaConsecutiveBookingsStayTwoBlocks() {
    $this->seed([['id' => self::AREA]], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '11:00']),
      $this->reservationRow(['id' => 901, 'date' => '2026-06-15', 'start' => '11:00', 'end' => '12:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();

    $this->assertSame(1, $result['json']['data']['capacity']);
    $this->assertCount(2, $result['json']['data']['busy']);
    $this->assertSame('10:00', $result['json']['data']['busy'][0]['start_time']);
    $this->assertSame('11:00', $result['json']['data']['busy'][1]['start_time']);
  }

  /**
   * IN A LARGER AREA a range with free slots is NOT busy, and saturated
   * contiguous ranges merge into one block — the opposite rule, on purpose.
   */
  public function testInALargerAreaOnlySaturatedRangesAreBusyAndTheyMerge() {
    $this->seed([['id' => self::AREA, 'capacity' => 2]], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '12:00']),
      $this->reservationRow(['id' => 901, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '11:00']),
      $this->reservationRow(['id' => 902, 'date' => '2026-06-15', 'start' => '11:00', 'end' => '12:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();
    $busy = $result['json']['data']['busy'];

    $this->assertSame(2, $result['json']['data']['capacity']);
    $this->assertCount(1, $busy, '10:00-12:00 is saturated throughout and merges into one block');
    $this->assertSame('10:00', $busy[0]['start_time']);
    $this->assertSame('12:00', $busy[0]['end_time']);
  }

  /**
   * A half-full range is absent from 'busy' but present in 'occupancy' with
   * its counters — which is the whole reason 'occupancy' exists.
   */
  public function testAHalfFullRangeIsOccupiedButNotBusy() {
    $this->seed([['id' => self::AREA, 'capacity' => 3]], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '11:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();

    $this->assertSame([], $result['json']['data']['busy']);
    $this->assertCount(1, $result['json']['data']['occupancy']);
    $this->assertSame(1, $result['json']['data']['occupancy'][0]['reserved']);
    $this->assertSame(2, $result['json']['data']['occupancy'][0]['remaining']);
  }

  /**
   * OCCUPANCY IS REPORTED EVEN IN A CAPACITY-1 AREA, where it is redundant
   * with 'busy' — so the client never has to branch on capacity to read the
   * response.
   */
  public function testOccupancyIsReportedInACapacityOneAreaToo() {
    $this->seed([['id' => self::AREA]], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '11:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $occupancy = $this->availabilityRequest()['json']['data']['occupancy'];

    $this->assertCount(1, $occupancy);
    $this->assertSame(1, $occupancy[0]['reserved']);
    $this->assertSame(0, $occupancy[0]['remaining']);
  }

  /**
   * THE ANSWER NEVER SAYS WHO. No id, no unit, no requester, no name reaches
   * the payload — the caller only learns that a slot is taken.
   */
  public function testTheAvailabilityNeverRevealsWhoBooked() {
    $this->seed([['id' => self::AREA]], [
      ['field_requester_target_id' => '900', 'requester_name' => 'Ana Vecina']
        + $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '11:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();

    $this->assertStringNotContainsString('Ana', $result['output']);
    $this->assertStringNotContainsString('900', $result['output']);
    $this->assertSame(['start_date', 'start_time', 'end_date', 'end_time'], array_keys($result['json']['data']['busy'][0]));
  }

  /**
   * Only CONFIRMED, published reservations of THIS area count.
   */
  public function testOnlyConfirmedReservationsOfThisAreaCount() {
    $this->seed([['id' => self::AREA]], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '11:00']),
      $this->reservationRow(['id' => 901, 'date' => '2026-06-15', 'start' => '12:00', 'end' => '13:00', 'state' => 'cancelled']),
      $this->reservationRow(['id' => 902, 'date' => '2026-06-15', 'start' => '14:00', 'end' => '15:00', 'published' => '0']),
      $this->reservationRow(['id' => 903, 'date' => '2026-06-15', 'start' => '16:00', 'end' => '17:00', 'area' => 701]),
    ]);
    $_GET['date'] = '2026-06-15';

    $busy = $this->availabilityRequest()['json']['data']['busy'];

    $this->assertCount(1, $busy);
    $this->assertSame('10:00', $busy[0]['start_time']);
  }

  /**
   * AN AREA THAT CLOSES PAST MIDNIGHT reports the early-morning tail of the
   * SAME session, which is stored under the next clock day — and asks the
   * database for both days.
   */
  public function testAnAreaThatWrapsPastMidnightReportsItsTail() {
    $this->seed([['id' => self::AREA, 'open' => '18:00', 'close' => '02:00']], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '22:00', 'end' => '23:00']),
      $this->reservationRow(['id' => 901, 'date' => '2026-06-16', 'start' => '00:30', 'end' => '01:30']),
      // The evening of the NEXT session, which belongs to 2026-06-16 and must
      // not appear in the answer for the 15th.
      $this->reservationRow(['id' => 902, 'date' => '2026-06-16', 'start' => '20:00', 'end' => '21:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();
    $busy = $result['json']['data']['busy'];

    $this->assertCount(2, $busy);
    $this->assertSame(['2026-06-15', '22:00'], [$busy[0]['start_date'], $busy[0]['start_time']]);
    $this->assertSame(['2026-06-16', '00:30'], [$busy[1]['start_date'], $busy[1]['start_time']]);
  }

  /**
   * A NON-WRAPPING AREA asks for one day only: the extra fetch is paid for
   * exactly when the session needs it.
   */
  public function testANonWrappingAreaAsksForOneDayOnly() {
    $this->seed([['id' => self::AREA, 'open' => '08:00', 'close' => '22:00']], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-16', 'start' => '09:00', 'end' => '10:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();

    $this->assertSame([], $result['json']['data']['busy'], 'the next day is not part of this session');
  }

  /**
   * An area with no open/close row does not wrap: the derivation needs both
   * bounds, and a half-configured area degrades to a plain calendar day rather
   * than to an error.
   */
  public function testAnAreaWithNoScheduleDoesNotWrap() {
    $this->seed([['id' => self::AREA, 'open' => NULL, 'close' => NULL]], [
      $this->reservationRow(['id' => 900, 'date' => '2026-06-15', 'start' => '10:00', 'end' => '11:00']),
      $this->reservationRow(['id' => 901, 'date' => '2026-06-16', 'start' => '01:00', 'end' => '02:00']),
    ]);
    $_GET['date'] = '2026-06-15';

    $result = $this->availabilityRequest();

    $this->assertSame(200, $result['status']);
    $this->assertCount(1, $result['json']['data']['busy']);
  }

  /**
   * Every answer of the three endpoints carries the no-store headers.
   */
  public function testEveryAnswerIsUncacheable() {
    $this->seed([['id' => self::AREA]]);
    $_GET['date'] = '2026-06-15';

    foreach ([$this->listRequest(), $this->detailRequest(), $this->availabilityRequest()] as $result) {
      $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
    }
  }
}
