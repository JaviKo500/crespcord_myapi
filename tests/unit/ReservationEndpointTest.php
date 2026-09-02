<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_notification.inc';
require_once __DIR__ . '/../../resources/reservation.resource.inc';

/**
 * End-to-end unit tests for the four reservation endpoints (SPECS 34, 36, 37,
 * 38, 43 and 50, covered by SPEC 121).
 *
 * THE MOST PRIVATE LISTING OF THE MODULE. Every other resource is scoped to a
 * unit; this one is scoped to a unit AND to the caller — SPEC 37 — because a
 * flat shared by two owners must not show either of them the other's bookings.
 * The requester filter is one condition, it is invisible in the response, and
 * losing it produces a perfectly plausible 200 with somebody else's plans in
 * it. Several cases below exist only to hold that line, on the list and on the
 * detail alike.
 *
 * The cancel endpoint is the module's strictest write: only the exact
 * field_requester may cancel, only a 'confirmed' reservation may be cancelled,
 * and only before the area's deadline — and each of the three answers a
 * different code, in a fixed order, so a client can tell them apart.
 *
 * WHAT THIS LAYER CANNOT REACH, stated once: myapi_request_body() reads
 * php://input, which a unit test cannot write. POST /api/v1/reservations takes
 * ALL of its input from that body, so only its first guard — "the body is
 * empty, so unit_id is missing" — is reachable here, and the eight ordered
 * validations behind it belong to tests/integration. What this class does
 * instead is exercise the pieces those validations are built out of, directly:
 * the time arithmetic, the active-reservation lookup, the balance rule and the
 * node builder. Same for the optional 'cancel_reason' of the cancel endpoint —
 * its validator is exercised as a pure function.
 */
class ReservationEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

  const UNIT = 45;
  const UID = 3;
  const OTHER_UID = 900;
  const CONDOMINIUM = 12;
  const AREA = 700;
  const RESERVATION = 800;

  /**
   * A created timestamp every fixture shares, so 'created' is deterministic.
   */
  const CREATED = 1780000000;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    myapi_test_node_seed();
    myapi_test_write_reset();
    myapi_test_queue_reset();
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
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
    myapi_test_node_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One 'reservation' row for the listing, carrying every joined column flat.
   */
  private function reservationRow(array $spec) {
    $spec += [
      'unit'      => self::UNIT,
      'requester' => self::UID,
      'area'      => self::AREA,
      'state'     => 'confirmed',
      'published' => '1',
      'date'      => '2026-06-15',
      'start'     => '10:00',
      'end'       => '11:00',
      'cancelled_by' => NULL,
      'reason'    => NULL,
      'area_name' => 'Piscina',
      'category'  => 'pool',
      'deadline'  => 120,
      'created'   => self::CREATED,
    ];

    return [
      'nid'                        => (string) $spec['id'],
      'type'                       => 'reservation',
      'status'                     => (string) $spec['published'],
      'created'                    => (string) $spec['created'],
      'field_unit_target_id'       => (string) $spec['unit'],
      'unit_id'                    => (string) $spec['unit'],
      'field_requester_target_id'  => (string) $spec['requester'],
      'requester_id'               => (string) $spec['requester'],
      'field_area_target_id'       => (string) $spec['area'],
      'area_id'                    => (string) $spec['area'],
      'condominium_id'             => (string) self::CONDOMINIUM,
      'area_name'                  => $spec['area_name'],
      'area_category'              => $spec['category'],
      'cancel_deadline_minutes'    => $spec['deadline'] === NULL ? NULL : (string) $spec['deadline'],
      'field_date_value'           => $spec['date'],
      'date'                       => $spec['date'],
      'field_start_time_value'     => $spec['start'],
      'start_time'                 => $spec['start'],
      'end_time'                   => $spec['end'],
      'fstat.field_reservation_status_value' => $spec['state'],
      'cancelled_by'               => $spec['cancelled_by'],
      'cancel_reason'              => $spec['reason'],
    ];
  }

  /**
   * A 'reservation' NODE, as node_load() answers it.
   */
  private function reservationNode(array $spec = []) {
    $spec += [
      'nid'       => self::RESERVATION,
      'unit'      => self::UNIT,
      'requester' => self::UID,
      'area'      => self::AREA,
      'state'     => 'confirmed',
      'published' => 1,
      'date'      => '2026-06-15',
      'start'     => '10:00',
      'end'       => '11:00',
      'cancelled_by' => NULL,
      'reason'    => NULL,
      'type'      => 'reservation',
    ];

    $node = (object) [
      'nid'     => $spec['nid'],
      'uid'     => $spec['requester'],
      'type'    => $spec['type'],
      'status'  => $spec['published'],
      'created' => self::CREATED,
      'title'   => 'Reservation ' . $spec['unit'],
    ];

    $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;
    if ($spec['unit'] !== NULL) {
      $node->field_unit[LANGUAGE_NONE][0]['target_id'] = $spec['unit'];
    }
    if ($spec['requester'] !== NULL) {
      $node->field_requester[LANGUAGE_NONE][0]['target_id'] = $spec['requester'];
    }
    if ($spec['area'] !== NULL) {
      $node->field_area[LANGUAGE_NONE][0]['target_id'] = $spec['area'];
    }
    $node->field_date[LANGUAGE_NONE][0]['value'] = $spec['date'];
    $node->field_start_time[LANGUAGE_NONE][0]['value'] = $spec['start'];
    $node->field_end_time[LANGUAGE_NONE][0]['value'] = $spec['end'];
    if ($spec['state'] !== NULL) {
      $node->field_reservation_status[LANGUAGE_NONE][0]['value'] = $spec['state'];
    }
    if ($spec['cancelled_by'] !== NULL) {
      $node->field_cancelled_by[LANGUAGE_NONE][0]['value'] = $spec['cancelled_by'];
    }
    if ($spec['reason'] !== NULL) {
      $node->field_cancel_reason[LANGUAGE_NONE][0]['value'] = $spec['reason'];
    }

    return $node;
  }

  /**
   * An 'area' node with a cancellation deadline.
   */
  private function areaNode(array $spec = []) {
    $spec += ['nid' => self::AREA, 'title' => 'Piscina', 'deadline' => 120, 'category' => 'pool'];

    $node = (object) ['nid' => $spec['nid'], 'type' => 'area', 'status' => 1, 'title' => $spec['title']];
    if ($spec['deadline'] !== NULL) {
      $node->field_cancel_deadline_minutes[LANGUAGE_NONE][0]['value'] = $spec['deadline'];
    }
    if ($spec['category'] !== NULL) {
      $node->field_area_category[LANGUAGE_NONE][0]['value'] = $spec['category'];
    }

    return $node;
  }

  private function seed(array $reservations = [], array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'mail' => 'p@example.com'];

    $rows = [];
    foreach ($reservations as $spec) {
      $rows[] = $this->reservationRow($spec);
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
    myapi_test_write_reset();
    $GLOBALS['myapi_test_db_writes'] = [];
  }

  private function listRequest($unit_id = self::UNIT) {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture(function () use ($unit_id) {
      myapi_reservation_dispatch($unit_id);
    });
  }

  private function detailsRequest($reservation_id = self::RESERVATION) {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture(function () use ($reservation_id) {
      myapi_reservation_details_dispatch($reservation_id);
    });
  }

  private function cancelRequest($reservation_id = self::RESERVATION) {
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    return myapi_test_capture(function () use ($reservation_id) {
      myapi_reservation_cancel_dispatch($reservation_id);
    });
  }

  private function createRequest() {
    $_SERVER['REQUEST_METHOD'] = 'POST';

    return myapi_test_capture('myapi_reservation_create_dispatch');
  }

  private function ids(array $result) {
    return array_column($result['json']['data']['reservations'], 'id');
  }

  /* -------------------------------------------------------------------------
   * The four dispatchers.
   * ---------------------------------------------------------------------- */

  /**
   * Each route accepts exactly one verb; every rejection is a 405 that runs no
   * query.
   */
  public function testEachDispatcherAcceptsOnlyItsOwnVerb() {
    $cases = [
      'list'    => ['allowed' => 'GET', 'call' => function () { myapi_reservation_dispatch(ReservationEndpointTest::UNIT); }],
      'details' => ['allowed' => 'GET', 'call' => function () { myapi_reservation_details_dispatch(ReservationEndpointTest::RESERVATION); }],
      'create'  => ['allowed' => 'POST', 'call' => function () { myapi_reservation_create_dispatch(); }],
      'cancel'  => ['allowed' => 'PUT', 'call' => function () { myapi_reservation_cancel_dispatch(ReservationEndpointTest::RESERVATION); }],
    ];

    foreach ($cases as $name => $case) {
      foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
        if ($method === $case['allowed']) {
          continue;
        }
        $this->seed([['id' => 800]]);
        $_SERVER['REQUEST_METHOD'] = $method;

        $result = myapi_test_capture($case['call']);

        $this->assertSame(405, $result['status'], $name . ' ' . $method);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $name . ' ' . $method);
        $this->assertSame([], myapi_test_db_queries(), $name . ' ' . $method);
      }
    }
  }

  /**
   * All four require a valid token.
   */
  public function testAllFourEndpointsRequireAValidToken() {
    $requests = [
      'list'    => function () { return $this->listRequest(); },
      'details' => function () { return $this->detailsRequest(); },
      'cancel'  => function () { return $this->cancelRequest(); },
      'create'  => function () { return $this->createRequest(); },
    ];

    foreach ($requests as $name => $request) {
      $this->seed([['id' => 800]]);
      unset($_SERVER['HTTP_AUTHORIZATION']);
      $result = $request();
      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries(), $name);

      $this->seed([['id' => 800]]);
      $GLOBALS['myapi_test_db']['my_api_tokens'][0]['revoked'] = '1';
      $result = $request();
      $this->assertSame(401, $result['status'], $name . ' revoked');
      $this->assertSame('invalid_token', $result['json']['error_code'], $name . ' revoked');
    }
  }

  /* -------------------------------------------------------------------------
   * GET /units/%/reservations — access and the requester filter.
   * ---------------------------------------------------------------------- */

  /**
   * A foreign unit is 403 and reads no reservation; a missing one answers the
   * same bytes.
   */
  public function testAForeignOrMissingUnitIsTheSame403() {
    $this->seed([['id' => 800]], [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '77', 'field_propietario_target_id' => '900', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $foreign = $this->listRequest(77);
    $missing = $this->listRequest(4242);

    $this->assertSame(403, $foreign['status']);
    $this->assertSame('unit_access_denied', $foreign['json']['error_code']);
    $this->assertSame($foreign['output'], $missing['output']);
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /**
   * THE RULE OF SPEC 37: a unit shared by two residents shows each of them
   * only their OWN reservations. The other's booking is invisible even though
   * the caller legitimately owns the unit — and it is invisible in the count
   * as well, or the client would page into rows it cannot see.
   */
  public function testAResidentNeverSeesAnotherResidentsReservationOfTheSameUnit() {
    $this->seed([
      ['id' => 800, 'requester' => self::UID],
      ['id' => 801, 'requester' => self::OTHER_UID],
      ['id' => 802, 'requester' => self::UID],
    ]);

    $result = $this->listRequest();

    $this->assertSame([802, 800], $this->ids($result));
    $this->assertSame(2, $result['json']['data']['pagination']['total']);
  }

  /**
   * BOTH statuses are listed: 'cancelled' travels in the payload instead of
   * being filtered out, which is what makes this listing different from every
   * other one in the module.
   */
  public function testConfirmedAndCancelledAreBothListed() {
    $this->seed([
      ['id' => 800, 'state' => 'confirmed'],
      ['id' => 801, 'state' => 'cancelled'],
    ]);

    $result = $this->listRequest();

    $this->assertCount(2, $result['json']['data']['reservations']);
    $statuses = array_column($result['json']['data']['reservations'], 'status');
    sort($statuses);
    $this->assertSame(['cancelled', 'confirmed'], $statuses);
  }

  /**
   * ?status narrows to one of the two, and any other value is ignored.
   */
  public function testTheStatusFilterAcceptsOnlyTheTwoKnownValues() {
    $this->seed([
      ['id' => 800, 'state' => 'confirmed'],
      ['id' => 801, 'state' => 'cancelled'],
    ]);

    $_GET['status'] = 'confirmed';
    $result = $this->listRequest();
    $this->assertSame([800], $this->ids($result));
    $this->assertSame(1, $result['json']['data']['pagination']['total'], 'the count is narrowed too');

    $_GET['status'] = 'cancelled';
    $this->assertSame([801], $this->ids($this->listRequest()));

    foreach (['Confirmed', 'pending', '', ['confirmed']] as $value) {
      $_GET['status'] = $value;
      $this->assertCount(2, $this->listRequest()['json']['data']['reservations'], json_encode($value));
    }
  }

  /**
   * Unpublished reservations and nodes of another type are excluded.
   */
  public function testUnpublishedAndForeignTypesAreExcluded() {
    $this->seed([
      ['id' => 800],
      ['id' => 801, 'published' => '0'],
    ]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'area'] + $this->reservationRow(['id' => 802]);

    $this->assertSame([800], $this->ids($this->listRequest()));
  }

  /* -------------------------------------------------------------------------
   * The listing: order, pagination and the date/time range.
   * ---------------------------------------------------------------------- */

  /**
   * The order is date, then start_time, then nid — all three in the requested
   * direction. The second key is what makes an ascending list read as a real
   * timeline instead of jumping 15:00 before 09:00.
   */
  public function testTheOrderIsDateThenStartTimeThenNid() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-15', 'start' => '15:00'],
      ['id' => 801, 'date' => '2026-06-15', 'start' => '09:00'],
      ['id' => 802, 'date' => '2026-06-14', 'start' => '20:00'],
      ['id' => 803, 'date' => '2026-06-15', 'start' => '09:00'],
    ]);

    $_GET['sort'] = 'asc';
    $this->assertSame([802, 801, 803, 800], $this->ids($this->listRequest()));

    $this->seed([['id' => 800]]);
    $_GET['sort'] = 'asc';
    $this->listRequest();
    $this->assertSame([
      ['field' => 'fdate.field_date_value', 'direction' => 'ASC'],
      ['field' => 'fstart.field_start_time_value', 'direction' => 'ASC'],
      ['field' => 'n.nid', 'direction' => 'ASC'],
    ], myapi_test_db_queries('node')[1]['order']);
  }

  /**
   * The default direction is descending, and any other ?sort value falls back
   * to it.
   */
  public function testTheDefaultOrderIsDescending() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-14'],
      ['id' => 801, 'date' => '2026-06-15'],
    ]);

    $this->assertSame([801, 800], $this->ids($this->listRequest()));

    foreach (['ASC', 'Desc', '', ['asc']] as $value) {
      $_GET['sort'] = $value;
      $this->assertSame([801, 800], $this->ids($this->listRequest()), json_encode($value));
    }
  }

  /**
   * The documented pagination, including the '-1' sentinel.
   */
  public function testPaginationIncludingTheUnlimitedSentinel() {
    $reservations = [];
    for ($i = 1; $i <= 7; $i++) {
      $reservations[] = ['id' => 800 + $i, 'date' => sprintf('2026-06-%02d', $i)];
    }
    $this->seed($reservations);

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
    $this->assertCount(1, $result['json']['data']['reservations']);

    $this->seed($reservations);
    $_GET = ['limit' => '-1', 'page' => '5'];
    $result = $this->listRequest();
    $this->assertCount(7, $result['json']['data']['reservations']);
    $this->assertSame(1, $result['json']['data']['pagination']['page']);
    $this->assertNull(myapi_test_db_queries('node')[1]['range']);
  }

  /**
   * A unit with no reservation of the caller's is a 200 with an empty array.
   */
  public function testAnEmptyListIsATwoHundred() {
    $this->seed([['id' => 800, 'requester' => self::OTHER_UID]]);

    $result = $this->listRequest();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['reservations']);
    $this->assertStringContainsString('"reservations":[]', $result['output']);
  }

  /**
   * The plain date range is inclusive on both ends and narrows the count.
   */
  public function testThePlainDateRangeIsInclusive() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-14'],
      ['id' => 801, 'date' => '2026-06-15'],
      ['id' => 802, 'date' => '2026-06-16'],
    ]);
    $_GET = ['date_from' => '2026-06-15', 'date_to' => '2026-06-16'];

    $result = $this->listRequest();

    $this->assertSame([802, 801], $this->ids($result));
    $this->assertSame(2, $result['json']['data']['pagination']['total']);
  }

  /**
   * THE TIME REFINEMENT NARROWS THE BOUNDARY DAY ONLY (SPEC 43). 'from D at
   * 09:00' drops D's earlier bookings and leaves every later day whole — the
   * property a plain "date >= D AND time >= 09:00" would get wrong.
   */
  public function testTheTimeBoundNarrowsOnlyItsOwnBoundaryDay() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-15', 'start' => '08:00'],
      ['id' => 801, 'date' => '2026-06-15', 'start' => '10:00'],
      ['id' => 802, 'date' => '2026-06-16', 'start' => '07:00'],
    ]);
    $_GET = ['date_from' => '2026-06-15', 'time_from' => '09:00'];

    $result = $this->listRequest();

    $this->assertSame([802, 801], $this->ids($result), 'the next day keeps its early booking');
    $this->assertSame(2, $result['json']['data']['pagination']['total']);
  }

  /**
   * The upper time bound is the mirror image, and both ends stay inclusive.
   */
  public function testTheUpperTimeBoundIsTheMirrorImageAndInclusive() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-15', 'start' => '10:00'],
      ['id' => 801, 'date' => '2026-06-15', 'start' => '18:00'],
      ['id' => 802, 'date' => '2026-06-14', 'start' => '23:00'],
    ]);
    $_GET = ['date_to' => '2026-06-15', 'time_to' => '18:00'];

    $this->assertSame([801, 800, 802], $this->ids($this->listRequest()), '18:00 is included');

    $_GET = ['date_to' => '2026-06-15', 'time_to' => '17:59'];
    $this->assertSame([800, 802], $this->ids($this->listRequest()));
  }

  /**
   * A time bound WITHOUT its date bound is dropped: it is a refinement, never
   * a filter of its own.
   */
  public function testATimeBoundWithoutItsDateBoundIsDropped() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-15', 'start' => '08:00'],
      ['id' => 801, 'date' => '2026-06-15', 'start' => '20:00'],
    ]);

    $_GET = ['time_from' => '09:00'];
    $this->assertCount(2, $this->listRequest()['json']['data']['reservations']);

    $_GET = ['date_from' => 'no-es-fecha', 'time_from' => '09:00'];
    $this->assertCount(2, $this->listRequest()['json']['data']['reservations'], 'a dropped date drops its time');
  }

  /**
   * A single-day range with inverted times keeps the DAY and drops both times,
   * rather than answering an always-empty set.
   */
  public function testASingleDayWithInvertedTimesKeepsTheDay() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-15', 'start' => '08:00'],
      ['id' => 801, 'date' => '2026-06-15', 'start' => '20:00'],
      ['id' => 802, 'date' => '2026-06-16', 'start' => '10:00'],
    ]);
    $_GET = ['date_from' => '2026-06-15', 'date_to' => '2026-06-15', 'time_from' => '20:00', 'time_to' => '08:00'];

    $this->assertSame([801, 800], $this->ids($this->listRequest()));
  }

  /**
   * An inverted DATE range drops everything, times included.
   */
  public function testAnInvertedDateRangeDropsTheWholeFilter() {
    $this->seed([
      ['id' => 800, 'date' => '2026-06-15'],
      ['id' => 801, 'date' => '2026-06-16'],
    ]);
    $_GET = ['date_from' => '2026-06-30', 'date_to' => '2026-06-01', 'time_from' => '09:00'];

    $this->assertCount(2, $this->listRequest()['json']['data']['reservations']);
  }

  /**
   * A malformed date or time bound is ignored silently, never a 422.
   */
  public function testMalformedBoundsAreIgnored() {
    $this->seed([['id' => 800, 'date' => '2026-06-15', 'start' => '08:00']]);

    foreach (['2026-13-40', 'hoy', '2026-02-30', ''] as $value) {
      $_GET = ['date_from' => $value];
      $this->assertSame(200, $this->listRequest()['status'], $value);
      $this->assertCount(1, $this->listRequest()['json']['data']['reservations'], $value);
    }

    foreach (['9:00', '25:00', '10:60', 'mañana', ''] as $value) {
      $_GET = ['date_from' => '2026-06-15', 'time_from' => $value];
      $this->assertCount(1, $this->listRequest()['json']['data']['reservations'], $value);
    }
  }

  /* -------------------------------------------------------------------------
   * The list mapper.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly the fifteen documented keys, in order.
   */
  public function testTheItemHasExactlyTheFifteenDocumentedKeysInOrder() {
    $this->seed([['id' => 800]]);

    $item = $this->listRequest()['json']['data']['reservations'][0];

    $this->assertSame([
      'id', 'condominium_id', 'unit_id', 'requester_id', 'area_id',
      'area_name', 'area_category', 'cancel_deadline_minutes', 'date',
      'start_time', 'end_time', 'status', 'cancelled_by', 'cancel_reason', 'created',
    ], array_keys($item));
  }

  /**
   * The casts: every id is an int when present and null when absent — never 0
   * — the date is truncated to its day, and 'created' is an ISO string.
   */
  public function testTheCastsAndTheTruncations() {
    $this->seed([['id' => 800, 'date' => '2026-06-15 00:00:00', 'deadline' => 120]]);

    $item = $this->listRequest()['json']['data']['reservations'][0];

    $this->assertSame(800, $item['id']);
    $this->assertSame(self::CONDOMINIUM, $item['condominium_id']);
    $this->assertSame(self::UNIT, $item['unit_id']);
    $this->assertSame(self::UID, $item['requester_id']);
    $this->assertSame(self::AREA, $item['area_id']);
    $this->assertSame(120, $item['cancel_deadline_minutes']);
    $this->assertSame('2026-06-15', $item['date'], 'the stored datetime is truncated to its day');
    $this->assertSame(format_date(self::CREATED, 'custom', 'Y-m-d\TH:i:s'), $item['created']);
  }

  /**
   * An area that was deleted leaves its three derived values null, and the
   * reservation is still listed — the join is a left one.
   */
  public function testADeletedAreaLeavesItsDerivedValuesNull() {
    $this->seed([['id' => 800, 'area_name' => NULL, 'category' => NULL, 'deadline' => NULL]]);

    $result = $this->listRequest();
    $item = $result['json']['data']['reservations'][0];

    $this->assertSame(800, $item['id']);
    $this->assertNull($item['area_name']);
    $this->assertNull($item['area_category']);
    $this->assertNull($item['cancel_deadline_minutes']);
    $this->assertStringContainsString('"cancel_deadline_minutes":null', $result['output']);
  }

  /**
   * cancelled_by and cancel_reason are null on a confirmed reservation and
   * carry their stored values on a cancelled one.
   */
  public function testTheCancellationFieldsTravelWhenPresent() {
    $this->seed([
      ['id' => 800, 'state' => 'confirmed'],
      ['id' => 801, 'state' => 'cancelled', 'cancelled_by' => 'user', 'reason' => 'Cambio de planes'],
    ]);

    $items = $this->listRequest()['json']['data']['reservations'];
    $by_id = array_column($items, NULL, 'id');

    $this->assertNull($by_id[800]['cancelled_by']);
    $this->assertNull($by_id[800]['cancel_reason']);
    $this->assertSame('user', $by_id[801]['cancelled_by']);
    $this->assertSame('Cambio de planes', $by_id[801]['cancel_reason']);
  }

  /* -------------------------------------------------------------------------
   * GET /reservations/%/details.
   * ---------------------------------------------------------------------- */

  /**
   * The detail of the caller's own reservation answers 200 and the same
   * fifteen keys as the list, wrapped as {"reservation": ...}.
   */
  public function testTheDetailAnswersTheSameFifteenKeys() {
    $this->seed([['id' => self::RESERVATION]]);
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode(),
      self::AREA        => $this->areaNode(),
    ]);

    $listed = $this->listRequest()['json']['data']['reservations'][0];
    $detail = $this->detailsRequest();

    $this->assertSame(200, $detail['status']);
    $this->assertSame(['reservation'], array_keys($detail['json']['data']));
    $this->assertSame(array_keys($listed), array_keys($detail['json']['data']['reservation']));
    $this->assertArrayNotHasKey('message', $detail['json']);
  }

  /**
   * THE FIVE WAYS OF NOT SEEING A RESERVATION ANSWER THE SAME 404: a malformed
   * id, a missing node, another bundle, an unpublished node, ANOTHER
   * RESIDENT'S reservation, and one of a unit the caller is not related to.
   * The fifth is the one that matters most — a 403 there would confirm that
   * the reservation exists and belongs to somebody else.
   */
  public function testEveryInvisibleReservationIsTheSame404() {
    $this->seed();
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode(),
      801 => $this->reservationNode(['nid' => 801, 'published' => 0]),
      802 => $this->reservationNode(['nid' => 802, 'requester' => self::OTHER_UID]),
      803 => $this->reservationNode(['nid' => 803, 'unit' => 77]),
      804 => (object) ['nid' => 804, 'type' => 'area', 'status' => 1, 'title' => 'Piscina'],
      self::AREA => $this->areaNode(),
    ]);

    $baseline = $this->detailsRequest(4242);
    $this->assertSame(404, $baseline['status']);
    $this->assertSame('reservation_not_found', $baseline['json']['error_code']);

    foreach ([801, 802, 803, 804] as $id) {
      $this->assertSame($baseline['output'], $this->detailsRequest($id)['output'], 'reservation ' . $id);
    }

    foreach (['abc', '0', '-1', ''] as $id) {
      $this->assertSame($baseline['output'], $this->detailsRequest($id)['output'], json_encode($id));
    }
  }

  /**
   * A deleted area leaves the three derived values null and still answers 200.
   */
  public function testTheDetailOfAReservationWithADeletedAreaStillAnswers() {
    $this->seed();
    myapi_test_node_seed([self::RESERVATION => $this->reservationNode()]);

    $result = $this->detailsRequest();

    $this->assertSame(200, $result['status']);
    $reservation = $result['json']['data']['reservation'];
    $this->assertNull($reservation['area_name']);
    $this->assertNull($reservation['area_category']);
    $this->assertNull($reservation['cancel_deadline_minutes']);
  }

  /* -------------------------------------------------------------------------
   * PUT /reservations/%/cancel.
   * ---------------------------------------------------------------------- */

  /**
   * A cancellable reservation is soft-cancelled: the status and cancelled_by
   * are rewritten, the node is saved once, and the answer is a 200 with the
   * updated reservation and a translated message.
   */
  public function testCancellingRewritesTheStatusAndAnswersTheReservation() {
    $this->seed();
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME + 86400)]),
      self::AREA        => $this->areaNode(),
    ]);

    $result = $this->cancelRequest();

    $this->assertSame(200, $result['status']);
    $this->assertArrayHasKey('message', $result['json']);
    $this->assertSame('cancelled', $result['json']['data']['reservation']['status']);
    $this->assertSame('user', $result['json']['data']['reservation']['cancelled_by']);

    $saved = myapi_test_node_saves();
    $this->assertCount(1, $saved);
    $this->assertSame('cancelled', $saved[0]->field_reservation_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame('user', $saved[0]->field_cancelled_by[LANGUAGE_NONE][0]['value']);
  }

  /**
   * IT IS A SOFT CANCEL: every other field is left exactly as it was.
   */
  public function testTheCancellationTouchesNothingElse() {
    $this->seed();
    $node = $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME + 86400)]);
    myapi_test_node_seed([self::RESERVATION => $node, self::AREA => $this->areaNode()]);

    $this->cancelRequest();
    $saved = myapi_test_node_saves()[0];

    $this->assertSame(self::UNIT, $saved->field_unit[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::AREA, $saved->field_area[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::UID, $saved->field_requester[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('10:00', $saved->field_start_time[LANGUAGE_NONE][0]['value']);
    $this->assertSame('11:00', $saved->field_end_time[LANGUAGE_NONE][0]['value']);
  }

  /**
   * THE OPT-OUT FLAG travels on the saved node, so hook_node_update() does not
   * read the resident's own cancellation as a back-office one and notify them
   * about their own action.
   */
  public function testTheSavedNodeCarriesTheNotificationOptOut() {
    $this->seed();
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME + 86400)]),
      self::AREA        => $this->areaNode(),
    ]);

    $this->cancelRequest();

    $this->assertTrue(!empty(myapi_test_node_saves()[0]->myapi_skip_reservation_notification));
  }

  /**
   * THE GUARDS RUN IN A FIXED ORDER AND EACH HAS ITS OWN CODE: not found (404),
   * not yours (403), not confirmed (409), window expired (409). A client can
   * tell them apart, which is the whole point of not collapsing them.
   */
  public function testEachCancelGuardHasItsOwnCodeAndSavesNothing() {
    $tomorrow = date('Y-m-d', REQUEST_TIME + 86400);

    $cases = [
      'missing'        => ['id' => 4242, 'status' => 404, 'code' => 'reservation_not_found'],
      'another bundle' => ['id' => 804, 'status' => 404, 'code' => 'reservation_not_found'],
      'malformed'      => ['id' => 'abc', 'status' => 404, 'code' => 'reservation_not_found'],
      'not yours'      => ['id' => 802, 'status' => 403, 'code' => 'reservation_forbidden'],
      'not confirmed'  => ['id' => 803, 'status' => 409, 'code' => 'reservation_not_confirmed'],
    ];

    foreach ($cases as $name => $case) {
      $this->seed();
      myapi_test_node_seed([
        self::RESERVATION => $this->reservationNode(['date' => $tomorrow]),
        802 => $this->reservationNode(['nid' => 802, 'requester' => self::OTHER_UID, 'date' => $tomorrow]),
        803 => $this->reservationNode(['nid' => 803, 'state' => 'cancelled', 'date' => $tomorrow]),
        804 => (object) ['nid' => 804, 'type' => 'area', 'status' => 1, 'title' => 'Piscina'],
        self::AREA => $this->areaNode(),
      ]);

      $result = $this->cancelRequest($case['id']);

      $this->assertSame($case['status'], $result['status'], $name);
      $this->assertSame($case['code'], $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_node_saves(), $name);
    }
  }

  /**
   * AUTHORSHIP AND NOT UNIT ACCESS. The co-owner of the very same unit cannot
   * cancel a booking they did not make — this endpoint is stricter than every
   * other one of the module, which scope by unit.
   */
  public function testACoOwnerOfTheUnitStillCannotCancelSomebodyElsesReservation() {
    $this->seed([], [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::OTHER_UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode(['requester' => self::OTHER_UID, 'date' => date('Y-m-d', REQUEST_TIME + 86400)]),
      self::AREA        => $this->areaNode(),
    ]);

    $result = $this->cancelRequest();

    $this->assertSame(403, $result['status']);
    $this->assertSame('reservation_forbidden', $result['json']['error_code']);
  }

  /**
   * THE WINDOW FAILS CLOSED. A reservation whose start is inside the deadline,
   * one whose area was deleted, and one whose area has no deadline row all
   * answer the same 409 — the window cannot be confirmed, so it is treated as
   * closed.
   */
  public function testTheCancellationWindowFailsClosed() {
    $soon = date('Y-m-d', REQUEST_TIME + 3600);
    $soon_time = date('H:i', REQUEST_TIME + 3600);

    $cases = [
      'inside the deadline' => [
        'nodes' => [
          self::RESERVATION => $this->reservationNode(['date' => $soon, 'start' => $soon_time]),
          self::AREA        => $this->areaNode(['deadline' => 120]),
        ],
      ],
      'area deleted' => [
        'nodes' => [self::RESERVATION => $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME + 86400)])],
      ],
      'area with no deadline' => [
        'nodes' => [
          self::RESERVATION => $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME + 86400)]),
          self::AREA        => $this->areaNode(['deadline' => NULL]),
        ],
      ],
      'already started' => [
        'nodes' => [
          self::RESERVATION => $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME - 86400)]),
          self::AREA        => $this->areaNode(['deadline' => 0]),
        ],
      ],
    ];

    foreach ($cases as $name => $case) {
      $this->seed();
      myapi_test_node_seed($case['nodes']);

      $result = $this->cancelRequest();

      $this->assertSame(409, $result['status'], $name);
      $this->assertSame('reservation_cancel_window_expired', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_node_saves(), $name);
    }
  }

  /**
   * The window is EXCLUSIVE: exactly at the deadline is already too late, one
   * minute more is still in time.
   */
  public function testTheDeadlineItselfIsAlreadyTooLate() {
    $at_deadline = REQUEST_TIME + 120 * 60;

    $this->seed();
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode([
        'date'  => date('Y-m-d', $at_deadline),
        'start' => date('H:i', $at_deadline),
      ]),
      self::AREA => $this->areaNode(['deadline' => 120]),
    ]);
    $this->assertSame(409, $this->cancelRequest()['status'], 'exactly at the deadline');

    $this->seed();
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode([
        'date'  => date('Y-m-d', $at_deadline + 120),
        'start' => date('H:i', $at_deadline + 120),
      ]),
      self::AREA => $this->areaNode(['deadline' => 120]),
    ]);
    $this->assertSame(200, $this->cancelRequest()['status'], 'two minutes past it');
  }

  /**
   * WITH NO BODY NO REASON IS WRITTEN, and the answer carries a null
   * cancel_reason — the only half of the SPEC 50 rule this layer can reach.
   */
  public function testWithNoBodyNoReasonIsWritten() {
    $this->seed();
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME + 86400)]),
      self::AREA        => $this->areaNode(),
    ]);

    $result = $this->cancelRequest();

    $this->assertNull($result['json']['data']['reservation']['cancel_reason']);
    $this->assertObjectNotHasProperty('field_cancel_reason', myapi_test_node_saves()[0]);
  }

  /**
   * The cancellation is best effort about its email: with nobody holding the
   * 'backend' role the 200 still goes out and nothing is enqueued.
   */
  public function testTheCancellationEmailIsBestEffort() {
    $this->seed();
    myapi_test_node_seed([
      self::RESERVATION => $this->reservationNode(['date' => date('Y-m-d', REQUEST_TIME + 86400)]),
      self::AREA        => $this->areaNode(),
    ]);

    $result = $this->cancelRequest();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], myapi_test_queue_items(MYAPI_MAIL_QUEUE));
  }

  /* -------------------------------------------------------------------------
   * POST /reservations — the one reachable guard.
   * ---------------------------------------------------------------------- */

  /**
   * WITH NO BODY THE CREATE ENDPOINT ANSWERS 422 missing_field NAMING THE
   * FIRST REQUIRED FIELD, and creates nothing. That is the whole of what this
   * layer can prove about POST /reservations — see the class docblock — and it
   * is worth proving: it is the answer a client with a broken serializer gets,
   * and it must not be a 500.
   */
  public function testTheCreateEndpointWithNoBodyIs422AndCreatesNothing() {
    $this->seed();

    $result = $this->createRequest();

    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertStringContainsString('unit_id', $result['json']['error']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /* -------------------------------------------------------------------------
   * The pure helpers the create path is built out of.
   * ---------------------------------------------------------------------- */

  /**
   * The three node accessors read delta 0 and answer NULL for an absent field;
   * the date one truncates to its day, whichever shape the field carries.
   */
  public function testTheThreeNodeAccessors() {
    $node = $this->reservationNode(['date' => '2026-06-15 00:00:00']);

    $this->assertSame('confirmed', myapi_reservation_node_value($node, 'field_reservation_status'));
    $this->assertNull(myapi_reservation_node_value($node, 'field_inexistente'));

    $this->assertSame(self::UNIT, myapi_reservation_node_target_id($node, 'field_unit'));
    $this->assertNull(myapi_reservation_node_target_id($node, 'field_inexistente'));

    $this->assertSame('2026-06-15', myapi_reservation_node_date($node), 'a stored datetime is truncated');

    $plain = $this->reservationNode(['date' => '2026-06-15']);
    $this->assertSame('2026-06-15', myapi_reservation_node_date($plain), 'a plain date is unchanged');

    $node_without = $this->reservationNode();
    unset($node_without->field_date);
    $this->assertNull(myapi_reservation_node_date($node_without));
  }

  /**
   * myapi_reservation_parse_time() converts 'HH:MM' into minutes since
   * midnight, at both ends of the clock.
   */
  public function testParseTimeConvertsToMinutesSinceMidnight() {
    $this->assertSame(0, myapi_reservation_parse_time('00:00'));
    $this->assertSame(1, myapi_reservation_parse_time('00:01'));
    $this->assertSame(600, myapi_reservation_parse_time('10:00'));
    $this->assertSame(1439, myapi_reservation_parse_time('23:59'));
    // The unbounded shape add_minutes() produces round-trips.
    $this->assertSame(1500, myapi_reservation_parse_time('25:00'));
  }

  /**
   * myapi_reservation_add_minutes() DELIBERATELY DOES NOT WRAP: a range that
   * crosses midnight surfaces as '25:00' and not as '01:00', which is exactly
   * what the opening-hours checks read to detect the crossing.
   */
  public function testAddMinutesDoesNotWrapAtMidnight() {
    $this->assertSame('11:00', myapi_reservation_add_minutes('10:00', 60));
    $this->assertSame('10:30', myapi_reservation_add_minutes('10:00', 30));
    $this->assertSame('10:00', myapi_reservation_add_minutes('10:00', 0), 'adding nothing changes nothing');
    $this->assertSame('24:00', myapi_reservation_add_minutes('23:00', 60), 'midnight is 24:00 and not 00:00');
    $this->assertSame('25:30', myapi_reservation_add_minutes('23:30', 120));
    $this->assertSame('26:00', myapi_reservation_add_minutes('22:00', 240));
  }

  /**
   * And myapi_reservation_wrap_time() is its counterpart: the value actually
   * PERSISTED is the clock time, so the crossing is derived downstream by
   * comparison instead of by an impossible hour.
   */
  public function testWrapTimeBringsTheUnboundedValueBackToTheClock() {
    $this->assertSame('01:00', myapi_reservation_wrap_time(1500));
    $this->assertSame('00:00', myapi_reservation_wrap_time(1440));
    $this->assertSame('23:59', myapi_reservation_wrap_time(1439));
    $this->assertSame('02:00', myapi_reservation_wrap_time(myapi_reservation_parse_time('26:00')));
    // A negative value wraps forward rather than producing '-01:00'.
    $this->assertSame('23:00', myapi_reservation_wrap_time(-60));
  }

  /**
   * The two lax validators of the listing: a real ISO date and a real 24h
   * time, NULL for everything else including every non-string.
   */
  public function testTheTwoLaxValidators() {
    $this->assertSame('2026-06-15', myapi_reservation_valid_date('2026-06-15'));
    $this->assertSame('2024-02-29', myapi_reservation_valid_date('2024-02-29'));
    foreach (['2026-2-1', '2026-13-01', '2026-02-30', 'hoy', '', NULL, ['x'], 20260615] as $value) {
      $this->assertNull(myapi_reservation_valid_date($value), json_encode($value));
    }

    $this->assertSame('00:00', myapi_reservation_valid_time('00:00'));
    $this->assertSame('23:59', myapi_reservation_valid_time('23:59'));
    foreach (['9:00', '24:00', '23:60', '09:0', 'mañana', '', NULL, ['09:00'], 900] as $value) {
      $this->assertNull(myapi_reservation_valid_time($value), json_encode($value));
    }
  }

  /**
   * The range parser answers its four keys and applies the three drop rules.
   */
  public function testTheRangeParserAnswersItsFourKeys() {
    $_GET = [];
    $this->assertSame(
      ['from' => NULL, 'to' => NULL, 'time_from' => NULL, 'time_to' => NULL],
      myapi_reservation_parse_date_range()
    );

    $_GET = ['date_from' => '2026-06-15', 'date_to' => '2026-06-20', 'time_from' => '09:00', 'time_to' => '18:00'];
    $this->assertSame(
      ['from' => '2026-06-15', 'to' => '2026-06-20', 'time_from' => '09:00', 'time_to' => '18:00'],
      myapi_reservation_parse_date_range()
    );

    // An inverted date range drops everything.
    $_GET = ['date_from' => '2026-06-20', 'date_to' => '2026-06-15', 'time_from' => '09:00'];
    $this->assertSame(
      ['from' => NULL, 'to' => NULL, 'time_from' => NULL, 'time_to' => NULL],
      myapi_reservation_parse_date_range()
    );

    // A time with no date of its own is dropped.
    $_GET = ['date_to' => '2026-06-20', 'time_from' => '09:00'];
    $range = myapi_reservation_parse_date_range();
    $this->assertNull($range['time_from']);

    // A single inverted day keeps the day and drops both times.
    $_GET = ['date_from' => '2026-06-15', 'date_to' => '2026-06-15', 'time_from' => '18:00', 'time_to' => '09:00'];
    $this->assertSame(
      ['from' => '2026-06-15', 'to' => '2026-06-15', 'time_from' => NULL, 'time_to' => NULL],
      myapi_reservation_parse_date_range()
    );

    // Equal times on a single day are NOT inverted.
    $_GET = ['date_from' => '2026-06-15', 'date_to' => '2026-06-15', 'time_from' => '09:00', 'time_to' => '09:00'];
    $range = myapi_reservation_parse_date_range();
    $this->assertSame('09:00', $range['time_from']);
    $this->assertSame('09:00', $range['time_to']);
  }

  /**
   * The cancel-reason validator: absent, blank and whitespace-only are all "no
   * reason" and not an error; a non-string IS an error; and the length is
   * measured in CHARACTERS so 255 accented ones fit.
   */
  public function testTheCancelReasonValidator() {
    $this->assertSame(['ok' => TRUE, 'value' => NULL], myapi_reservation_validate_cancel_reason(NULL));
    $this->assertSame(['ok' => TRUE, 'value' => NULL], myapi_reservation_validate_cancel_reason([]));
    $this->assertSame(['ok' => TRUE, 'value' => NULL], myapi_reservation_validate_cancel_reason(['cancel_reason' => '']));
    $this->assertSame(['ok' => TRUE, 'value' => NULL], myapi_reservation_validate_cancel_reason(['cancel_reason' => "  \n "]));

    $this->assertSame(
      ['ok' => TRUE, 'value' => 'Cambio de planes'],
      myapi_reservation_validate_cancel_reason(['cancel_reason' => '  Cambio de planes  '])
    );

    foreach ([['cancel_reason' => 5], ['cancel_reason' => ['x']], ['cancel_reason' => NULL], ['cancel_reason' => TRUE]] as $body) {
      $verdict = myapi_reservation_validate_cancel_reason($body);
      $this->assertFalse($verdict['ok'], json_encode($body));
      $this->assertSame('invalid_field', $verdict['error_code']);
      $this->assertSame(['@field' => 'cancel_reason'], $verdict['replacements']);
    }

    $accented = str_repeat('á', 255);
    $this->assertTrue(myapi_reservation_validate_cancel_reason(['cancel_reason' => $accented])['ok'], '255 characters fit');

    $too_long = myapi_reservation_validate_cancel_reason(['cancel_reason' => str_repeat('a', 256)]);
    $this->assertFalse($too_long['ok']);
    $this->assertSame('field_too_long', $too_long['error_code']);
  }

  /* -------------------------------------------------------------------------
   * The node builder and the by-node mapper.
   * ---------------------------------------------------------------------- */

  /**
   * The built node carries every documented field, and the two values that are
   * NEVER taken from the request: the status is forced to 'confirmed' and the
   * condominium comes from the UNIT, not from the area or the body.
   */
  public function testTheBuiltNodeForcesTheStatusAndTakesTheCondominiumFromTheUnit() {
    $node = myapi_reservation_build_node(self::CONDOMINIUM, self::UNIT, self::UID, self::AREA, '2026-06-15', '10:00', '11:00');

    $this->assertSame('reservation', $node->type);
    $this->assertSame(self::UID, $node->uid);
    $this->assertSame(1, $node->status);
    $this->assertSame('Reservation 45 - 2026-06-15 10:00', $node->title);
    $this->assertSame(self::CONDOMINIUM, $node->field_condominium[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::UNIT, $node->field_unit[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::UID, $node->field_requester[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::AREA, $node->field_area[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('2026-06-15', $node->field_date[LANGUAGE_NONE][0]['value']);
    $this->assertSame('10:00', $node->field_start_time[LANGUAGE_NONE][0]['value']);
    $this->assertSame('11:00', $node->field_end_time[LANGUAGE_NONE][0]['value']);
    $this->assertSame('confirmed', $node->field_reservation_status[LANGUAGE_NONE][0]['value']);
    $this->assertObjectNotHasProperty('field_cancelled_by', $node);
  }

  /**
   * The by-node mapper answers the SAME fifteen keys as the row mapper, which
   * is what keeps the create/cancel/detail responses interchangeable with a
   * list item.
   */
  public function testTheByNodeMapperAnswersTheSameFifteenKeys() {
    $item = myapi_reservation_build_item_from_node($this->reservationNode(), 'Piscina', 'pool', 120);

    $this->assertSame([
      'id', 'condominium_id', 'unit_id', 'requester_id', 'area_id',
      'area_name', 'area_category', 'cancel_deadline_minutes', 'date',
      'start_time', 'end_time', 'status', 'cancelled_by', 'cancel_reason', 'created',
    ], array_keys($item));

    $this->assertSame(self::RESERVATION, $item['id']);
    $this->assertSame('Piscina', $item['area_name']);
    $this->assertSame('pool', $item['area_category']);
    $this->assertSame(120, $item['cancel_deadline_minutes']);
    $this->assertSame('2026-06-15', $item['date']);
    $this->assertSame('confirmed', $item['status']);
    $this->assertNull($item['cancelled_by']);
    $this->assertNull($item['cancel_reason']);
  }

  /**
   * The three area-derived values default to NULL, so a caller that could not
   * resolve the area still gets a well-formed item.
   */
  public function testTheAreaDerivedValuesDefaultToNull() {
    $item = myapi_reservation_build_item_from_node($this->reservationNode(), NULL);

    $this->assertNull($item['area_name']);
    $this->assertNull($item['area_category']);
    $this->assertNull($item['cancel_deadline_minutes']);
  }

  /* -------------------------------------------------------------------------
   * The two write-path lookups, exercised directly.
   * ---------------------------------------------------------------------- */

  /**
   * myapi_reservation_has_active_reservation() sees a FUTURE confirmed booking
   * of the same unit and area, and nothing else: another unit, another area, a
   * cancelled one, an unpublished one and a past one are all invisible.
   */
  public function testTheActiveReservationLookupIsNarrow() {
    $tomorrow = date('Y-m-d', REQUEST_TIME + 86400);
    $yesterday = date('Y-m-d', REQUEST_TIME - 86400);

    $row = function (array $spec) {
      $spec += [
        'unit' => ReservationEndpointTest::UNIT, 'area' => ReservationEndpointTest::AREA,
        'state' => 'confirmed', 'published' => '1', 'start' => '23:00',
      ];

      return [
        'nid'                            => (string) $spec['id'],
        'type'                           => 'reservation',
        'status'                         => $spec['published'],
        'field_unit_target_id'           => (string) $spec['unit'],
        'field_area_target_id'           => (string) $spec['area'],
        'field_reservation_status_value' => $spec['state'],
        'field_date_value'               => $spec['date'],
        'field_start_time_value'         => $spec['start'],
      ];
    };

    myapi_test_db_seed(['node' => [$row(['id' => 1, 'date' => $tomorrow])]]);
    $this->assertTrue(myapi_reservation_has_active_reservation(self::UNIT, self::AREA));

    $invisible = [
      'another unit'  => $row(['id' => 2, 'date' => $tomorrow, 'unit' => 77]),
      'another area'  => $row(['id' => 3, 'date' => $tomorrow, 'area' => 701]),
      'cancelled'     => $row(['id' => 4, 'date' => $tomorrow, 'state' => 'cancelled']),
      'unpublished'   => $row(['id' => 5, 'date' => $tomorrow, 'published' => '0']),
      'in the past'   => $row(['id' => 6, 'date' => $yesterday]),
    ];

    foreach ($invisible as $name => $node) {
      myapi_test_db_seed(['node' => [$node]]);
      $this->assertFalse(myapi_reservation_has_active_reservation(self::UNIT, self::AREA), $name);
    }
  }

  /**
   * THE BALANCE RULE. A unit with no debt may always reserve; a unit in debt
   * may only when its most recent SENT receipt shows no previous balance due.
   * Every degraded shape — no receipt, no previous_balance row — allows.
   */
  public function testTheBalanceRule() {
    $unit = function ($balance) {
      $node = (object) ['nid' => ReservationEndpointTest::UNIT, 'type' => 'vivienda', 'status' => 1];
      if ($balance !== NULL) {
        $node->field_saldo_actual[LANGUAGE_NONE][0]['value'] = $balance;
      }

      return $node;
    };

    $receipt = function (array $spec) {
      return [
        'nid'                     => (string) $spec['id'],
        'type'                    => 'recibo',
        'status'                  => '1',
        'field_vivienda_target_id' => (string) ReservationEndpointTest::UNIT,
        'field_estado_value'      => 'Enviado',
        'field_periodo_value'     => $spec['period'],
        'period_start'            => $spec['period'],
        'previous_balance'        => $spec['previous'],
      ];
    };

    // No debt: allowed without looking at a receipt at all.
    foreach ([NULL, '0.00', '-50.00'] as $balance) {
      myapi_test_node_seed([self::UNIT => $unit($balance)]);
      myapi_test_db_seed(['node' => []]);
      $this->assertTrue(myapi_reservation_check_balance(self::UNIT), json_encode($balance));
      $this->assertSame([], myapi_test_db_queries('node'), 'no receipt was read');
    }

    // In debt, and the latest sent receipt shows a previous balance due.
    myapi_test_node_seed([self::UNIT => $unit('120.00')]);
    myapi_test_db_seed(['node' => [
      $receipt(['id' => 1, 'period' => '2026-05-01', 'previous' => '0.00']),
      $receipt(['id' => 2, 'period' => '2026-06-01', 'previous' => '80.00']),
    ]]);
    $this->assertFalse(myapi_reservation_check_balance(self::UNIT), 'the LATEST receipt decides');

    // The same two receipts the other way round: the latest one is clean.
    myapi_test_node_seed([self::UNIT => $unit('120.00')]);
    myapi_test_db_seed(['node' => [
      $receipt(['id' => 1, 'period' => '2026-05-01', 'previous' => '80.00']),
      $receipt(['id' => 2, 'period' => '2026-06-01', 'previous' => '0.00']),
    ]]);
    $this->assertTrue(myapi_reservation_check_balance(self::UNIT));

    // In debt with no sent receipt at all: allowed.
    myapi_test_node_seed([self::UNIT => $unit('120.00')]);
    myapi_test_db_seed(['node' => []]);
    $this->assertTrue(myapi_reservation_check_balance(self::UNIT), 'no receipt, no block');

    // In debt with a receipt carrying no previous_balance row: allowed.
    myapi_test_node_seed([self::UNIT => $unit('120.00')]);
    myapi_test_db_seed(['node' => [$receipt(['id' => 1, 'period' => '2026-06-01', 'previous' => NULL])]]);
    $this->assertTrue(myapi_reservation_check_balance(self::UNIT));
  }

  /**
   * Every answer of the four endpoints carries the no-store headers.
   */
  public function testEveryAnswerIsUncacheable() {
    $this->seed([['id' => self::RESERVATION]]);
    myapi_test_node_seed([self::RESERVATION => $this->reservationNode(), self::AREA => $this->areaNode()]);

    foreach ([$this->listRequest(), $this->detailsRequest()] as $result) {
      $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
    }
  }
}
