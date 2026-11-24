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
require_once __DIR__ . '/../../includes/myapi.reservation_rules.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_notification.inc';
require_once __DIR__ . '/../../resources/reservation.resource.inc';

/**
 * Serves a fixed string as php://input for the length of one request.
 *
 * Only php://input is answered; nothing else is opened through the php://
 * scheme while the endpoint runs. Registered and restored by
 * ReservationCreateRulesTest::post(), never left in place.
 */
class MyapiTestInputStream {

  public static $body = '';

  /** @var resource|null */
  public $context;

  private $position = 0;

  public function stream_open($path, $mode, $options, &$opened_path) {
    $this->position = 0;

    return $path === 'php://input';
  }

  public function stream_read($count) {
    $chunk = substr(self::$body, $this->position, $count);
    $this->position += strlen($chunk);

    return $chunk;
  }

  public function stream_eof() {
    return $this->position >= strlen(self::$body);
  }

  public function stream_stat() {
    return [];
  }

}

/**
 * The ordered validations of POST /api/v1/reservations, through the endpoint
 * (SPEC 35, as SPEC 135 left them).
 *
 * SPEC 135 moved the rules out of myapi_reservation_create() into
 * includes/myapi.reservation_rules.inc. What stayed in the resource is the
 * part the app depends on: which error_code and which HTTP status each
 * violated rule answers, and that the FIRST violated one wins. This class pins
 * exactly that contract, plus the two things only the endpoint does — the
 * balance check and the row lock around validations 6 to 8.
 *
 * HOW THE BODY GETS IN. myapi_request_body() reads php://input and caches it
 * in a function static. Each case therefore runs in its own process (one
 * request, one body), with php://input served by MyapiTestInputStream.
 */
class ReservationCreateRulesTest extends TestCase {

  const TOKEN = 'a-valid-access-token';
  const UNIT = 45;
  const OTHER_UNIT = 46;
  const UID = 3;
  const CONDOMINIUM = 12;
  const AREA = 700;

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  private function tomorrow() {
    return date('Y-m-d', REQUEST_TIME + 86400);
  }

  /**
   * An 'area' node: active, 08:00-22:00, 120 minutes at most, one at a time,
   * open to owners and tenants alike.
   */
  private function area(array $spec) {
    $spec += [
      'status' => 'active', 'open' => '08:00', 'close' => '22:00',
      'max' => 120, 'capacity' => NULL, 'who' => 'both',
    ];

    $node = (object) ['nid' => self::AREA, 'type' => 'area', 'status' => 1, 'title' => 'Piscina'];
    $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;
    $map = [
      'field_area_status' => 'status', 'field_open_time' => 'open', 'field_close_time' => 'close',
      'field_max_minutes' => 'max', 'field_concurrent_reservations' => 'capacity',
      'field_who_can_reserve' => 'who',
    ];
    foreach ($map as $field => $key) {
      if ($spec[$key] !== NULL) {
        $node->{$field}[LANGUAGE_NONE][0]['value'] = $spec[$key];
      }
    }

    return $node;
  }

  /**
   * One stored reservation, as the lookups of validations 6 and 7 read it.
   */
  private function row(array $spec) {
    $spec += [
      'unit' => self::OTHER_UNIT, 'date' => $this->tomorrow(), 'start' => '10:00', 'end' => '11:00',
    ];

    return [
      'nid'                            => (string) $spec['id'],
      'type'                           => 'reservation',
      'status'                         => '1',
      'field_unit_target_id'           => (string) $spec['unit'],
      'field_area_target_id'           => (string) self::AREA,
      'field_reservation_status_value' => 'confirmed',
      'field_date_value'               => $spec['date'],
      'date'                           => $spec['date'],
      'field_start_time_value'         => $spec['start'],
      'start_time'                     => $spec['start'],
      'field_end_time_value'           => $spec['end'],
      'end_time'                       => $spec['end'],
    ];
  }

  /**
   * Seeds the site and sends one POST /api/v1/reservations.
   *
   * @param array $case  'body' (overrides; 'date' NULL means tomorrow), 'area'
   *                     (overrides), 'rows' (stored reservations) and 'debt'
   *                     (TRUE: the unit owes, and so did its last receipt).
   */
  private function post(array $case) {
    $case += ['body' => [], 'area' => [], 'rows' => [], 'debt' => FALSE];

    $unit = (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1];
    $unit->field_condominio[LANGUAGE_NONE][0]['target_id'] = self::CONDOMINIUM;

    $nodes = array_map([$this, 'row'], $case['rows']);
    if ($case['debt']) {
      $unit->field_saldo_actual[LANGUAGE_NONE][0]['value'] = '150.00';
      $nodes[] = [
        'nid' => '9', 'type' => 'recibo', 'status' => '1',
        'field_vivienda_target_id' => (string) self::UNIT, 'field_estado_value' => 'Enviado',
        'period_start' => '2026-10-01', 'previous_balance' => '80.00',
      ];
    }

    myapi_test_node_seed([self::UNIT => $unit, self::AREA => $this->area($case['area'])]);
    myapi_test_db_seed([
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
      'node' => $nodes,
    ]);
    myapi_test_write_reset();
    myapi_test_queue_reset();
    $GLOBALS['myapi_test_db_locks'] = [];
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'mail' => 'p@example.com'];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $body = $case['body'] + [
      'unit_id' => self::UNIT, 'area_id' => self::AREA, 'date' => NULL,
      'start_time' => '10:00', 'duration_minutes' => 60,
    ];
    if ($body['date'] === NULL) {
      $body['date'] = $this->tomorrow();
    }
    MyapiTestInputStream::$body = json_encode($body);

    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'MyapiTestInputStream');
    try {
      return myapi_test_capture('myapi_reservation_create_dispatch');
    }
    finally {
      stream_wrapper_restore('php');
    }
  }

  /* -------------------------------------------------------------------------
   * Every rule answers its own code and status.
   * ---------------------------------------------------------------------- */

  public function refusals() {
    $taken = [['id' => 1]];

    return [
      'rule 1: an owner in a tenants-only area' => [
        ['area' => ['who' => 'tenant']], 403, 'reservation_role_not_allowed',
      ],
      'rule 2: the area is not active' => [
        ['area' => ['status' => 'inactive']], 409, 'area_not_active',
      ],
      'rule 3: the start is in the past' => [
        ['body' => ['date' => '2020-01-01']], 422, 'invalid_field',
      ],
      'rule 4: crossing midnight in a same-day area' => [
        ['body' => ['start_time' => '21:00', 'duration_minutes' => 240], 'area' => ['max' => NULL]], 422, 'reservation_crosses_midnight',
      ],
      'rule 4: before the area opens' => [
        ['body' => ['start_time' => '06:00']], 422, 'reservation_outside_hours',
      ],
      'rule 4: an area with no opening time fails closed' => [
        ['area' => ['open' => NULL]], 422, 'reservation_outside_hours',
      ],
      'rule 5: longer than the area allows' => [
        ['body' => ['duration_minutes' => 121]], 422, 'reservation_duration_exceeded',
      ],
      'rule 6: a taken slot in an area of one' => [
        ['rows' => $taken], 409, 'reservation_overlap',
      ],
      'rule 6: a full slot in an area of two' => [
        ['rows' => [['id' => 1], ['id' => 2]], 'area' => ['capacity' => 2]], 409, 'area_capacity_full',
      ],
      'rule 7: the unit already holds an active reservation there' => [
        ['rows' => [['id' => 1, 'unit' => self::UNIT, 'start' => '15:00', 'end' => '16:00']]], 409, 'reservation_already_active',
      ],
      'rule 8: the unit is in debt' => [
        ['debt' => TRUE], 403, 'insufficient_balance',
      ],

      // The first violated rule wins: the order is part of the contract.
      'order: role before status' => [
        ['area' => ['who' => 'tenant', 'status' => 'inactive']], 403, 'reservation_role_not_allowed',
      ],
      'order: status before the past' => [
        ['area' => ['status' => 'inactive'], 'body' => ['date' => '2020-01-01']], 409, 'area_not_active',
      ],
      'order: the past before the opening hours' => [
        ['body' => ['date' => '2020-01-01', 'start_time' => '06:00']], 422, 'invalid_field',
      ],
      'order: the opening hours before the duration' => [
        ['body' => ['start_time' => '06:00', 'duration_minutes' => 121]], 422, 'reservation_outside_hours',
      ],
      'order: the duration before the capacity' => [
        ['body' => ['duration_minutes' => 121], 'rows' => $taken], 422, 'reservation_duration_exceeded',
      ],
      'order: the capacity before the active reservation' => [
        ['rows' => [['id' => 1, 'unit' => self::UNIT]]], 409, 'reservation_overlap',
      ],
      'order: the active reservation before the balance' => [
        ['rows' => [['id' => 1, 'unit' => self::UNIT, 'start' => '15:00', 'end' => '16:00']], 'debt' => TRUE], 409, 'reservation_already_active',
      ],
    ];
  }

  /**
   * @dataProvider refusals
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testARefusedReservationAnswersItsCodeAndSavesNothing(array $case, $status, $error_code) {
    $result = $this->post($case);

    $this->assertTrue($result['exited']);
    $this->assertSame($status, $result['status']);
    $this->assertFalse($result['json']['success']);
    $this->assertSame($error_code, $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves(), 'nothing is saved');
  }

  /**
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testAStartInThePastIsReportedOnTheDateField() {
    $result = $this->post(['body' => ['date' => '2020-01-01']]);

    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('date', $result['json']['error']);
  }

  /* -------------------------------------------------------------------------
   * The accepted reservation.
   * ---------------------------------------------------------------------- */

  /**
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testAValidReservationIsSavedUnderTheAreaLock() {
    $result = $this->post([]);

    $this->assertSame(201, $result['status']);
    $this->assertTrue($result['json']['success']);

    $saves = myapi_test_node_saves();
    $this->assertCount(1, $saves);
    $this->assertSame('reservation', $saves[0]->type);
    $this->assertSame('10:00', $saves[0]->field_start_time[LANGUAGE_NONE][0]['value']);
    $this->assertSame('11:00', $saves[0]->field_end_time[LANGUAGE_NONE][0]['value']);
    $this->assertSame('confirmed', $saves[0]->field_reservation_status[LANGUAGE_NONE][0]['value']);

    $this->assertSame([['table' => 'node', 'in_transaction' => TRUE]], $GLOBALS['myapi_test_db_locks']);
    $this->assertSame(0, $GLOBALS['myapi_test_transactions_open'], 'committed before the response');
  }

  /**
   * Exactly the maximum duration, in a slot that still has room, right next to
   * another reservation: every boundary on the accepting side at once.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testTheBoundariesAreInclusive() {
    $result = $this->post([
      'body' => ['start_time' => '11:00', 'duration_minutes' => 120],
      'rows' => [['id' => 1]],
    ]);

    $this->assertSame(201, $result['status']);
  }

  /**
   * A range across midnight is taken by an area that closes after it, and its
   * end is stored as a clock time (SPEC 41).
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testAnOvernightAreaTakesARangeAcrossMidnight() {
    $result = $this->post([
      'body' => ['start_time' => '23:00', 'duration_minutes' => 120],
      'area' => ['open' => '12:00', 'close' => '02:00'],
    ]);

    $this->assertSame(201, $result['status']);
    $this->assertSame('01:00', myapi_test_node_saves()[0]->field_end_time[LANGUAGE_NONE][0]['value']);
  }

}
