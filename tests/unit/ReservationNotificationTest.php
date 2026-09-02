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
require_once __DIR__ . '/../../includes/myapi.reservation_calendar.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_notification.inc';
require_once __DIR__ . '/../../resources/reservation.resource.inc';

/**
 * Unit tests for the reservation notifications (SPECS 48, 49, 50 and 54,
 * covered by SPEC 121).
 *
 * THREE EVENTS AND THREE DIFFERENT AUDIENCES, and the audiences are the whole
 * design:
 *
 *   created by the resident   -> resident (push + inbox + mail) AND the
 *                                'backend' role AND the building admins
 *   cancelled by an operator  -> resident only
 *   cancelled by the resident -> 'backend' only, and the resident NOTHING
 *
 * The third one is the counter-intuitive one and the easiest to "fix" by
 * mistake: somebody who just cancelled a booking on screen does not need a push
 * telling them so, and the operators — who did not — do. Every one of the six
 * "who does NOT get this" assertions below exists because the failure mode is
 * silent: a notification sent to the wrong side of that table looks perfectly
 * normal in the code and is noise (or a leak) in production.
 *
 * The other property under test is that the emails REUSE THE CALENDAR'S LABEL
 * HELPERS instead of re-deriving ten values. That is what makes the back-office
 * screen and the email agree about a deleted area, a deleted unit and a deleted
 * account — so those helpers are exercised here too, directly, on the exact
 * degraded shapes the notification row produces.
 *
 * Everything here is BEST EFFORT by contract: a reservation is already
 * committed when its notification runs, so no branch may throw. The three
 * entry points swallow and log, and the cases at the end prove it with a
 * deliberately broken write.
 */
class ReservationNotificationTest extends TestCase {

  const RESERVATION = 800;
  const AREA = 700;
  const UNIT = 45;
  const CONDOMINIUM = 12;
  const UID = 3;

  const CREATED = 1780000000;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    myapi_test_node_seed();
    myapi_test_write_reset();
    myapi_test_queue_reset();
    myapi_test_static_reset();
    myapi_test_field_seed_allowed_values();
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_profile_fields'] = [];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    unset($GLOBALS['myapi_test_profile_fields']);
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A 'reservation' node.
   */
  private function reservation(array $spec = []) {
    $spec += [
      'nid'       => self::RESERVATION,
      'unit'      => self::UNIT,
      'area'      => self::AREA,
      'requester' => self::UID,
      'condominium' => self::CONDOMINIUM,
      'date'      => '2026-06-15',
      'start'     => '10:00',
      'end'       => '11:30',
      'state'     => 'confirmed',
      'cancelled_by' => NULL,
      'reason'    => NULL,
    ];

    $node = (object) [
      'nid'     => $spec['nid'],
      'type'    => 'reservation',
      'status'  => 1,
      'created' => self::CREATED,
      'title'   => 'Reservation',
    ];

    if ($spec['condominium'] !== NULL) {
      $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = $spec['condominium'];
    }
    if ($spec['unit'] !== NULL) {
      $node->field_unit[LANGUAGE_NONE][0]['target_id'] = $spec['unit'];
    }
    if ($spec['area'] !== NULL) {
      $node->field_area[LANGUAGE_NONE][0]['target_id'] = $spec['area'];
    }
    if ($spec['requester'] !== NULL) {
      $node->field_requester[LANGUAGE_NONE][0]['target_id'] = $spec['requester'];
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
   * The referenced area, unit and condominium nodes, plus the requester.
   */
  private function seedWorld(array $options = []) {
    $options += [
      'area'        => 'Piscina',
      'unit'        => 'A-101',
      'condominium' => 'Torre Andalucía',
      'user'        => ['name' => 'pcordero', 'mail' => 'p@example.com'],
      'profile'     => [],
      'backend'     => [],
      'building_admins' => [],
    ];

    $nodes = [];
    if ($options['area'] !== NULL) {
      $nodes[self::AREA] = (object) ['nid' => self::AREA, 'type' => 'area', 'status' => 1, 'title' => $options['area']];
    }
    if ($options['unit'] !== NULL) {
      $nodes[self::UNIT] = (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1, 'title' => $options['unit']];
    }
    myapi_test_node_seed($nodes);

    $GLOBALS['myapi_test_users'] = [];
    if ($options['user'] !== NULL) {
      $GLOBALS['myapi_test_users'][self::UID] = [
        'uid' => self::UID, 'status' => 1,
      ] + $options['user'];
    }
    $GLOBALS['myapi_test_profile_fields'] = $options['profile'];

    $condominium_rows = $options['condominium'] === NULL
      ? []
      : [['nid' => (string) self::CONDOMINIUM, 'title' => $options['condominium'], 'type' => 'condominio', 'status' => '1']];

    $user_rows = [];
    foreach ($options['backend'] as $uid => $mail) {
      $user_rows[] = ['uid' => (string) $uid, 'status' => '1', 'name' => 'op' . $uid, 'mail' => $mail, 'r.name' => MYAPI_RESERVATION_NOTIFY_ROLE];
      $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'status' => 1, 'name' => 'op' . $uid, 'mail' => $mail];
    }
    foreach ($options['building_admins'] as $uid => $mail) {
      $user_rows[] = [
        'uid' => (string) $uid, 'status' => '1', 'name' => 'adm' . $uid, 'mail' => $mail,
        'r.name' => MYAPI_BUILDING_ADMIN_ROLE,
        MYAPI_BUILDING_ADMIN_CONDO_FIELD . '_target_id' => (string) self::CONDOMINIUM,
      ];
      $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'status' => 1, 'name' => 'adm' . $uid, 'mail' => $mail];
    }

    // The building-admin lookup short-circuits unless the field exists.
    myapi_test_field_seed_allowed_values([MYAPI_BUILDING_ADMIN_CONDO_FIELD => []]);

    myapi_test_db_seed([
      'node'  => $condominium_rows,
      'users' => $user_rows,
    ]);
    myapi_test_queue_reset();
    $GLOBALS['myapi_test_db_writes'] = [];
  }

  /**
   * The rows inserted into myapi_notifications.
   */
  private function inbox() {
    $writes = myapi_test_db_writes('myapi_notifications');

    return $writes ? $writes[0]['rows'] : [];
  }

  /**
   * The mail items enqueued, by key.
   */
  private function mails($key = NULL) {
    $items = myapi_test_queue_items(MYAPI_MAIL_QUEUE);
    if ($key === NULL) {
      return $items;
    }

    return array_values(array_filter($items, function ($item) use ($key) {
      return $item['data']['key'] === $key;
    }));
  }

  /* -------------------------------------------------------------------------
   * myapi_reservation_notification_row(): the shared row shape.
   * ---------------------------------------------------------------------- */

  /**
   * The row carries every property the calendar's own rows carry, resolved
   * from a single node.
   */
  public function testTheRowCarriesTheCalendarShape() {
    $this->seedWorld(['profile' => ['first_name' => 'Pablo', 'last_name' => 'Cordero']]);

    $row = myapi_reservation_notification_row($this->reservation());

    $this->assertSame(self::RESERVATION, $row->nid);
    $this->assertSame(self::CREATED, $row->created);
    $this->assertSame('2026-06-15', $row->date);
    $this->assertSame('10:00', $row->start_time);
    $this->assertSame('11:30', $row->end_time);
    $this->assertSame('confirmed', $row->status);
    $this->assertSame(self::AREA, $row->area_id);
    $this->assertSame('Piscina', $row->area_title);
    $this->assertSame(self::UNIT, $row->unit_id);
    $this->assertSame('A-101', $row->unit_title);
    $this->assertSame(self::CONDOMINIUM, $row->condominium_id);
    $this->assertSame(self::UID, $row->uid);
    $this->assertSame('pcordero', $row->user_name);
    $this->assertSame('p@example.com', $row->user_mail);
    $this->assertSame('Pablo', $row->user_first_name);
    $this->assertSame('Cordero', $row->user_last_name);
  }

  /**
   * EVERY ABSENCE LANDS ON NULL, keeping the id: a deleted area or unit loses
   * its title and nothing else, which is exactly what the calendar's LEFT
   * JOINs produce and what its labels know how to degrade.
   */
  public function testEveryAbsenceLandsOnNullAndKeepsTheId() {
    $this->seedWorld(['area' => NULL, 'unit' => NULL, 'user' => NULL]);

    $row = myapi_reservation_notification_row($this->reservation());

    $this->assertSame(self::AREA, $row->area_id);
    $this->assertNull($row->area_title);
    $this->assertSame(self::UNIT, $row->unit_id);
    $this->assertNull($row->unit_title);
    $this->assertSame(self::UID, $row->uid);
    $this->assertNull($row->user_name);
    $this->assertNull($row->user_mail);
    $this->assertNull($row->user_first_name);
  }

  /**
   * A reservation with no references at all answers a row of nulls rather than
   * failing, and a node with no created falls back to the request time.
   */
  public function testAReservationWithNoReferencesStillBuildsARow() {
    $this->seedWorld();
    $node = $this->reservation(['area' => NULL, 'unit' => NULL, 'requester' => NULL, 'condominium' => NULL]);
    unset($node->created);

    $row = myapi_reservation_notification_row($node);

    $this->assertNull($row->area_id);
    $this->assertNull($row->unit_id);
    $this->assertNull($row->uid);
    $this->assertNull($row->condominium_id);
    $this->assertSame(REQUEST_TIME, $row->created);
  }

  /**
   * An account with an EMPTY mail answers NULL and not '': the mail queue
   * would otherwise try to send to nowhere.
   */
  public function testAnEmptyMailBecomesNull() {
    $this->seedWorld(['user' => ['name' => 'pcordero', 'mail' => '']]);

    $row = myapi_reservation_notification_row($this->reservation());

    $this->assertSame('pcordero', $row->user_name);
    $this->assertNull($row->user_mail);
  }

  /* -------------------------------------------------------------------------
   * The two label helpers this file owns.
   * ---------------------------------------------------------------------- */

  /**
   * The date label is the 'd/m/Y' the calendar prints, so push, inbox and both
   * emails show one single format.
   */
  public function testTheDateLabelIsTheCalendarFormat() {
    $row = (object) ['date' => '2026-06-15'];

    $this->assertSame('15/06/2026', myapi_reservation_date_label($row));
  }

  /**
   * THE OVERNIGHT MARKER. Without it '22:00 - 02:00' reads as a twenty-hour
   * booking; with it the reader sees which day the end belongs to.
   */
  public function testTheScheduleLabelMarksAnOvernightBooking() {
    $same_day = (object) ['start_time' => '10:00', 'end_time' => '11:30'];
    $this->assertSame('10:00 - 11:30', myapi_reservation_schedule_label($same_day));

    $overnight = (object) ['start_time' => '22:00', 'end_time' => '02:00'];
    $this->assertSame('22:00 - 02:00 (+1 día)', myapi_reservation_schedule_label($overnight));

    // An end equal to the start is a full 24h booking and is marked too.
    $full_day = (object) ['start_time' => '10:00', 'end_time' => '10:00'];
    $this->assertStringContainsString('(+1 día)', myapi_reservation_schedule_label($full_day));
  }

  /**
   * The separator is a parameter because the 'backend' email replicates the
   * calendar panel, which uses an en dash.
   */
  public function testTheScheduleSeparatorIsAParameter() {
    $row = (object) ['start_time' => '10:00', 'end_time' => '11:30'];

    $this->assertSame('10:00 – 11:30', myapi_reservation_schedule_label($row, '–'));
  }

  /* -------------------------------------------------------------------------
   * The notification body.
   * ---------------------------------------------------------------------- */

  /**
   * Three plain-text lines, with the RAW area title — this text is rendered by
   * the app, which is not HTML.
   */
  public function testTheBodyIsThreePlainTextLines() {
    $row = (object) [
      'area_title' => 'Piscina & Jardín',
      'date' => '2026-06-15',
      'start_time' => '10:00',
      'end_time' => '11:30',
      'cancel_reason' => NULL,
    ];

    $body = myapi_reservation_notification_body($row, FALSE);

    $this->assertSame(
      "Tu reserva del área \"Piscina & Jardín\" ha sido confirmada.\nFecha: 15/06/2026\nHorario: 10:00 - 11:30",
      $body
    );
    $this->assertStringNotContainsString('&amp;', $body, 'the app renders plain text, not HTML');
  }

  /**
   * The cancellation wording differs, and names the operator as the actor.
   */
  public function testTheCancellationWordingNamesTheOperator() {
    $row = (object) [
      'area_title' => 'Piscina',
      'date' => '2026-06-15',
      'start_time' => '10:00',
      'end_time' => '11:30',
      'cancel_reason' => NULL,
    ];

    $this->assertStringContainsString('ha sido cancelada por un operador.', myapi_reservation_notification_body($row, TRUE));
  }

  /**
   * A DELETED AREA DROPS THE QUOTED NAME rather than printing empty quotes.
   */
  public function testADeletedAreaDropsTheQuotedName() {
    $row = (object) [
      'area_title' => NULL,
      'date' => '2026-06-15',
      'start_time' => '10:00',
      'end_time' => '11:30',
      'cancel_reason' => NULL,
    ];

    $body = myapi_reservation_notification_body($row, FALSE);

    $this->assertStringStartsWith('Tu reserva ha sido confirmada.', $body);
    $this->assertStringNotContainsString('""', $body);
  }

  /**
   * The fourth line is the cancellation reason, on cancellations only and only
   * when somebody typed one — so a creation body is byte for byte the
   * three-line text of SPEC 48.
   */
  public function testTheReasonIsAFourthLineOnCancellationsOnly() {
    $row = (object) [
      'area_title' => 'Piscina',
      'date' => '2026-06-15',
      'start_time' => '10:00',
      'end_time' => '11:30',
      'cancel_reason' => 'Mantenimiento imprevisto',
    ];

    $this->assertStringContainsString("\nMotivo: Mantenimiento imprevisto", myapi_reservation_notification_body($row, TRUE));
    $this->assertStringNotContainsString('Motivo:', myapi_reservation_notification_body($row, FALSE), 'never on a creation');

    $row->cancel_reason = '   ';
    $this->assertStringNotContainsString('Motivo:', myapi_reservation_notification_body($row, TRUE), 'a blank reason adds no line');
  }

  /* -------------------------------------------------------------------------
   * Event 1: created by the resident.
   * ---------------------------------------------------------------------- */

  /**
   * The creation notifies the resident on all three channels and emails the
   * 'backend' role.
   */
  public function testTheCreationNotifiesTheResidentAndTheBackendRole() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_created($this->reservation());

    $inbox = $this->inbox();
    $this->assertCount(1, $inbox);
    $this->assertSame(self::UID, $inbox[0]['uid']);
    $this->assertSame(MYAPI_NOTIFICATION_TYPE_RESERVATION_CREATED, $inbox[0]['type']);
    $this->assertSame(MYAPI_NOTIFICATION_DEEP_LINK_RESERVATION, $inbox[0]['deep_link_target']);
    $this->assertSame(self::RESERVATION, $inbox[0]['deep_link_id']);
    $this->assertSame(self::UNIT, $inbox[0]['unit_id']);
    $this->assertSame(self::CONDOMINIUM, $inbox[0]['condominium_id']);

    $this->assertCount(1, myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE), 'the push was enqueued');
    $this->assertCount(1, $this->mails('reservation_created_user'));
    $this->assertCount(1, $this->mails('reservation_created_admin'));
  }

  /**
   * THE BUILDING ADMINS OF THE CONDOMINIUM GET THE CREATION EMAIL TOO (SPEC
   * 49), deduplicated with the 'backend' role, and the admins of ANOTHER
   * building do not.
   */
  public function testTheCreationEmailReachesTheBuildingAdminsOfThatCondominium() {
    $this->seedWorld([
      'backend'         => [10 => 'op@example.com'],
      'building_admins' => [11 => 'adm@example.com'],
    ]);
    // An admin of another building, who must not be mailed.
    $GLOBALS['myapi_test_db']['users'][] = [
      'uid' => '12', 'status' => '1', 'name' => 'adm12', 'mail' => 'otro@example.com',
      'r.name' => MYAPI_BUILDING_ADMIN_ROLE,
      MYAPI_BUILDING_ADMIN_CONDO_FIELD . '_target_id' => '99',
    ];
    $GLOBALS['myapi_test_users'][12] = ['uid' => 12, 'status' => 1, 'name' => 'adm12', 'mail' => 'otro@example.com'];

    myapi_reservation_notify_created($this->reservation());

    $recipients = array_column(array_column($this->mails('reservation_created_admin'), 'data'), 'to');
    sort($recipients);
    $this->assertSame(['adm@example.com', 'op@example.com'], $recipients);
  }

  /**
   * The 'backend' email carries the twelve documented values, resolved through
   * the calendar's own labels.
   */
  public function testTheAdminEmailCarriesTheCalendarLabels() {
    $this->seedWorld([
      'backend' => [10 => 'op@example.com'],
      'profile' => ['first_name' => 'Pablo', 'last_name' => 'Cordero'],
    ]);

    myapi_reservation_notify_created($this->reservation());

    $params = $this->mails('reservation_created_admin')[0]['data']['params'];

    $this->assertSame(self::RESERVATION, $params['nid']);
    $this->assertSame('Pablo Cordero', $params['user']);
    $this->assertSame('p@example.com', $params['email']);
    $this->assertSame('A-101', $params['unit']);
    $this->assertSame('Piscina', $params['area']);
    $this->assertSame('Torre Andalucía', $params['condominium']);
    $this->assertSame('15/06/2026', $params['date']);
    $this->assertSame('10:00 – 11:30', $params['schedule'], 'the en dash of the calendar panel');
    $this->assertSame('1h 30min', $params['duration']);
    $this->assertSame('Confirmada', $params['status']);
    $this->assertStringContainsString('node/' . self::RESERVATION, $params['node_url']);
  }

  /**
   * THE RESIDENT'S EMAIL USES THE FULL NAME AND NEVER THE USERNAME SUFFIX
   * (SPEC 54): the admin label is reserved for the back office.
   */
  public function testTheResidentEmailUsesTheFullName() {
    $this->seedWorld(['profile' => ['first_name' => 'Pablo', 'last_name' => 'Cordero']]);

    myapi_reservation_notify_created($this->reservation());

    $params = $this->mails('reservation_created_user')[0]['data']['params'];

    $this->assertSame('Pablo Cordero', $params['name']);
    $this->assertStringNotContainsString('pcordero', $params['name']);
    $this->assertSame('', $params['cancel_reason'], 'a creation carries no reason');
  }

  /**
   * A resident with no email is skipped and logged, and the push and the inbox
   * row still went out — they are the immediate channel.
   */
  public function testAResidentWithNoEmailStillGetsThePushAndTheInboxRow() {
    $this->seedWorld(['user' => ['name' => 'pcordero', 'mail' => '']]);

    myapi_reservation_notify_created($this->reservation());

    $this->assertCount(1, $this->inbox());
    $this->assertCount(1, myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE));
    $this->assertSame([], $this->mails('reservation_created_user'));
    $this->assertNotSame([], $GLOBALS['myapi_test_watchdog']);
  }

  /**
   * A reservation with no requester notifies nobody on the resident side, and
   * still mails the operators — the event happened.
   */
  public function testAReservationWithNoRequesterStillMailsTheOperators() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_created($this->reservation(['requester' => NULL]));

    $this->assertSame([], $this->inbox());
    $this->assertSame([], myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE));
    $this->assertCount(1, $this->mails('reservation_created_admin'));
  }

  /**
   * WITH NOBODY IN EITHER ROLE nothing is enqueued for the operators and the
   * resident is still notified — the email is never a precondition.
   */
  public function testWithNoOperatorsTheResidentIsStillNotified() {
    $this->seedWorld();

    myapi_reservation_notify_created($this->reservation());

    $this->assertCount(1, $this->inbox());
    $this->assertSame([], $this->mails('reservation_created_admin'));
  }

  /**
   * ONCE PER NODE PER REQUEST. A re-save inside the same request cannot
   * duplicate the inbox row.
   */
  public function testTheCreationNotifiesOncePerRequest() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_created($this->reservation());
    myapi_reservation_notify_created($this->reservation());

    $this->assertCount(1, myapi_test_db_writes('myapi_notifications'));
    $this->assertCount(1, $this->mails('reservation_created_admin'));
  }

  /* -------------------------------------------------------------------------
   * Event 2: cancelled by an operator.
   * ---------------------------------------------------------------------- */

  /**
   * The transition detector: exactly a back-office save that moves the status
   * INTO 'cancelled'.
   */
  public function testTheCancellationTransitionDetector() {
    $confirmed = $this->reservation(['state' => 'confirmed']);
    $cancelled = $this->reservation(['state' => 'cancelled']);

    $update = $this->reservation(['state' => 'cancelled']);
    $update->original = $confirmed;
    $this->assertTrue(myapi_reservation_is_cancellation_transition($update));

    // Already cancelled: editing another field is not a new cancellation.
    $again = $this->reservation(['state' => 'cancelled']);
    $again->original = $cancelled;
    $this->assertFalse(myapi_reservation_is_cancellation_transition($again));

    // Not a cancellation at all.
    $other = $this->reservation(['state' => 'confirmed']);
    $other->original = $confirmed;
    $this->assertFalse(myapi_reservation_is_cancellation_transition($other));

    // An insert has no ->original.
    $this->assertFalse(myapi_reservation_is_cancellation_transition($cancelled));

    // And the opt-out flag wins over everything.
    $opted_out = $this->reservation(['state' => 'cancelled']);
    $opted_out->original = $confirmed;
    $opted_out->myapi_skip_reservation_notification = TRUE;
    $this->assertFalse(myapi_reservation_is_cancellation_transition($opted_out));
  }

  /**
   * An operator's cancellation notifies THE RESIDENT ONLY: push, inbox and
   * email — and never the 'backend' role, who are looking at the calendar they
   * just cancelled it in.
   */
  public function testAnOperatorCancellationNotifiesTheResidentOnly() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_cancelled($this->reservation(['state' => 'cancelled', 'cancelled_by' => 'admin']));

    $inbox = $this->inbox();
    $this->assertCount(1, $inbox);
    $this->assertSame(self::UID, $inbox[0]['uid']);
    $this->assertSame(MYAPI_NOTIFICATION_TYPE_RESERVATION_CANCELLED, $inbox[0]['type']);
    $this->assertSame('Reserva cancelada', $inbox[0]['title']);

    $this->assertCount(1, $this->mails('reservation_cancelled_user'));
    $this->assertSame([], $this->mails('reservation_cancelled_admin'), 'the operators are not mailed');
    $this->assertSame([], $this->mails('reservation_created_admin'));
  }

  /**
   * The reason travels into the inbox body and into the resident's email.
   */
  public function testTheReasonTravelsIntoTheBodyAndTheEmail() {
    $this->seedWorld();

    myapi_reservation_notify_cancelled($this->reservation([
      'state' => 'cancelled', 'reason' => 'Mantenimiento imprevisto',
    ]));

    $this->assertStringContainsString('Motivo: Mantenimiento imprevisto', $this->inbox()[0]['body']);
    $this->assertSame('Mantenimiento imprevisto', $this->mails('reservation_cancelled_user')[0]['data']['params']['cancel_reason']);
  }

  /**
   * Once per node per request, like the creation.
   */
  public function testTheOperatorCancellationNotifiesOncePerRequest() {
    $this->seedWorld();

    myapi_reservation_notify_cancelled($this->reservation(['state' => 'cancelled']));
    myapi_reservation_notify_cancelled($this->reservation(['state' => 'cancelled']));

    $this->assertCount(1, myapi_test_db_writes('myapi_notifications'));
  }

  /* -------------------------------------------------------------------------
   * Event 3: cancelled by the resident (SPEC 50).
   * ---------------------------------------------------------------------- */

  /**
   * THE MIRROR IMAGE. The resident's own cancellation mails the 'backend' role
   * and sends the resident NOTHING — no inbox row, no push, no email. They
   * just did it on screen.
   */
  public function testTheResidentsOwnCancellationMailsOnlyTheOperators() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_user_cancelled($this->reservation([
      'state' => 'cancelled', 'cancelled_by' => 'user',
    ]));

    $this->assertSame([], $this->inbox(), 'no inbox row for the resident');
    $this->assertSame([], myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE), 'no push');
    $this->assertSame([], $this->mails('reservation_cancelled_user'), 'no email to the resident');
    $this->assertCount(1, $this->mails('reservation_cancelled_admin'));
  }

  /**
   * 'Cancelada por' is the WORD 'Usuario' and never the stored 'user' — the
   * email is read by a person, and 'Cancelada por: user' reads as a bug.
   */
  public function testTheAdminCancellationEmailSaysUsuarioAndNotUser() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_user_cancelled($this->reservation([
      'state' => 'cancelled', 'cancelled_by' => 'user', 'reason' => 'Cambio de planes',
    ]));

    $params = $this->mails('reservation_cancelled_admin')[0]['data']['params'];

    $this->assertSame('Usuario', $params['cancelled_by']);
    $this->assertSame('Cambio de planes', $params['cancel_reason']);
    $this->assertSame('Cancelada', $params['status']);
  }

  /**
   * With no reason the extra line is an EMPTY STRING, which is what keeps it
   * out of the rendered message.
   */
  public function testWithNoReasonTheLineIsAnEmptyString() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_user_cancelled($this->reservation(['state' => 'cancelled', 'cancelled_by' => 'user']));

    $this->assertSame('', $this->mails('reservation_cancelled_admin')[0]['data']['params']['cancel_reason']);
  }

  /**
   * THE CANCELLATION EMAIL IS NOT WIDENED TO THE BUILDING ADMINS. SPEC 49
   * widened the CREATION key only, and this case is what keeps that decision
   * from drifting.
   */
  public function testTheCancellationEmailIsNotWidenedToBuildingAdmins() {
    $this->seedWorld([
      'backend'         => [10 => 'op@example.com'],
      'building_admins' => [11 => 'adm@example.com'],
    ]);

    myapi_reservation_notify_user_cancelled($this->reservation(['state' => 'cancelled', 'cancelled_by' => 'user']));

    $recipients = array_column(array_column($this->mails('reservation_cancelled_admin'), 'data'), 'to');
    $this->assertSame(['op@example.com'], $recipients);
  }

  /**
   * Once per node per request, like the other two.
   */
  public function testTheResidentCancellationMailsOncePerRequest() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_user_cancelled($this->reservation(['state' => 'cancelled']));
    myapi_reservation_notify_user_cancelled($this->reservation(['state' => 'cancelled']));

    $this->assertCount(1, $this->mails('reservation_cancelled_admin'));
  }

  /* -------------------------------------------------------------------------
   * The degraded labels, end to end.
   * ---------------------------------------------------------------------- */

  /**
   * A DELETED AREA, UNIT, CONDOMINIUM AND ACCOUNT all degrade into the very
   * text the back office shows — which is the whole reason this file reuses
   * the calendar's labels instead of re-deriving them.
   */
  public function testEveryDeletedEntityDegradesIntoTheBackOfficeText() {
    $this->seedWorld([
      'area' => NULL, 'unit' => NULL, 'condominium' => NULL, 'user' => NULL,
      'backend' => [10 => 'op@example.com'],
    ]);

    myapi_reservation_notify_created($this->reservation());

    $params = $this->mails('reservation_created_admin')[0]['data']['params'];

    $this->assertSame('Área eliminada (#' . self::AREA . ')', $params['area']);
    $this->assertSame('Vivienda eliminada (#' . self::UNIT . ')', $params['unit']);
    $this->assertSame('Condominio no disponible (#' . self::CONDOMINIUM . ')', $params['condominium']);
    $this->assertSame('Usuario eliminado (#' . self::UID . ')', $params['user']);
    $this->assertSame('—', $params['email']);
  }

  /**
   * A reservation with no references at all reads as the four "Sin ..."
   * labels.
   */
  public function testNoReferencesAtAllReadsAsTheSinLabels() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);

    myapi_reservation_notify_created($this->reservation([
      'area' => NULL, 'unit' => NULL, 'condominium' => NULL, 'requester' => NULL,
    ]));

    $params = $this->mails('reservation_created_admin')[0]['data']['params'];

    $this->assertSame('Sin área', $params['area']);
    $this->assertSame('Sin vivienda', $params['unit']);
    $this->assertSame('Sin condominio', $params['condominium']);
    $this->assertSame('Sin usuario', $params['user']);
  }

  /**
   * The user label falls back to the USERNAME when the profile has no name,
   * and the full name wins when it has one — even a half one.
   */
  public function testTheUserLabelFallsBackToTheUsername() {
    $this->seedWorld(['profile' => []]);
    $row = myapi_reservation_notification_row($this->reservation());
    $this->assertSame('pcordero', myapi_calendar_user_name_label($row));

    $this->seedWorld(['profile' => ['first_name' => 'Pablo']]);
    $row = myapi_reservation_notification_row($this->reservation());
    $this->assertSame('Pablo', myapi_calendar_user_name_label($row), 'a half profile still wins');

    $this->seedWorld(['profile' => ['first_name' => '  ', 'last_name' => '  ']]);
    $row = myapi_reservation_notification_row($this->reservation());
    $this->assertSame('pcordero', myapi_calendar_user_name_label($row), 'a whitespace profile does not');
  }

  /**
   * Every label ESCAPES what it prints: an area named with markup travels
   * escaped into the HTML email.
   */
  public function testTheLabelsEscapeWhatTheyPrint() {
    $this->seedWorld([
      'area' => 'Piscina <b>', 'unit' => 'A&B', 'condominium' => 'Torre "A"',
      'backend' => [10 => 'op@example.com'],
    ]);

    myapi_reservation_notify_created($this->reservation());

    $params = $this->mails('reservation_created_admin')[0]['data']['params'];

    $this->assertSame('Piscina &lt;b&gt;', $params['area']);
    $this->assertSame('A&amp;B', $params['unit']);
    $this->assertSame('Torre &quot;A&quot;', $params['condominium']);
  }

  /**
   * The duration label is derived, not stored, and handles the overnight case.
   */
  public function testTheDurationLabelIsDerived() {
    $this->assertSame('1h 30min', myapi_calendar_duration_label((object) ['start_time' => '10:00', 'end_time' => '11:30']));
    $this->assertSame('4h 0min', myapi_calendar_duration_label((object) ['start_time' => '22:00', 'end_time' => '02:00']));
    $this->assertSame('24h 0min', myapi_calendar_duration_label((object) ['start_time' => '10:00', 'end_time' => '10:00']));
    $this->assertSame('0h 45min', myapi_calendar_duration_label((object) ['start_time' => '10:00', 'end_time' => '10:45']));
  }

  /* -------------------------------------------------------------------------
   * The best-effort contract.
   * ---------------------------------------------------------------------- */

  /**
   * A FAILING INSERT IS LOGGED AND NEVER PROPAGATES. The reservation is
   * already committed when this runs, so an exception here would turn a
   * successful 201 into a 500.
   */
  public function testAFailingInsertIsLoggedAndNeverPropagates() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);
    myapi_test_db_fail_writes('myapi_notifications');

    myapi_reservation_notify_created($this->reservation());

    $this->assertNotSame([], $GLOBALS['myapi_test_watchdog'], 'the failure was logged');
  }

  /**
   * The same contract on the two cancellation entry points.
   */
  public function testBothCancellationsAlsoSwallowAndLog() {
    $this->seedWorld(['backend' => [10 => 'op@example.com']]);
    myapi_test_db_fail_writes('myapi_notifications');

    myapi_reservation_notify_cancelled($this->reservation(['state' => 'cancelled']));
    $this->assertNotSame([], $GLOBALS['myapi_test_watchdog']);

    $GLOBALS['myapi_test_watchdog'] = [];
    myapi_test_static_reset();
    myapi_reservation_notify_user_cancelled($this->reservation(['state' => 'cancelled']));
    // This one writes no notification row, so it does not fail — the point is
    // that it returns normally either way.
    $this->assertTrue(TRUE);
  }
}
