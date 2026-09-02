<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.time_format.inc';
require_once __DIR__ . '/../../includes/myapi.area_admin.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_admin.inc';
require_once __DIR__ . '/../../includes/myapi.file_download.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.node_files.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.fee_notification.inc';

/**
 * Unit tests for the shared helpers that had no test of their own (SPEC 121).
 *
 * THE FILES NOBODY OWNS. Every one of these is a small function called from
 * several places, which is exactly the shape that ends up untested: too small
 * to deserve a spec, too shared for any one caller's test to be about it.
 * Between them they decide whether a malformed time reaches the database,
 * whether a header can be forged out of a filename, whether a resident's
 * receipt notification reaches them, and whether a failed email is retried or
 * dropped.
 *
 * Three of them are the kind of guard that is invisible until it is gone:
 *
 *  - THE TWO BUNDLE VALIDATORS are four lines each and are the ONLY thing
 *    standing between the node form and a stored '99:99'. The rule itself is
 *    shared (TimeFormatTest covers it); what these two own is the field map,
 *    and a wrong field name there silently validates nothing at all.
 *  - THE FILENAME SANITISER is what keeps a stored filename from closing a
 *    quoted header parameter early.
 *  - THE REMOVAL PARSER refuses a fid the node does not reference, which is
 *    what stops a stray id from becoming a way to probe other people's files.
 */
class SharedHelpersTest extends TestCase {

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    myapi_test_node_seed();
    myapi_test_file_seed();
    myapi_test_write_reset();
    myapi_test_queue_reset();
    myapi_test_static_reset();
    myapi_test_mail_reset();
    myapi_test_field_seed_allowed_values();
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_form_errors'] = [];
    $GLOBALS['myapi_test_variables'] = [];
    $_GET = [];
    $_POST = [];
  }

  protected function tearDown(): void {
    $_GET = [];
    $_POST = [];
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_file_seed();
    myapi_test_static_reset();
    myapi_test_mail_reset();
  }

  /**
   * The form errors raised by a validator, keyed by element path.
   */
  private function formErrors() {
    return isset($GLOBALS['myapi_test_form_errors']) ? $GLOBALS['myapi_test_form_errors'] : [];
  }

  /**
   * A $form_state carrying the given field values, in the Field API shape.
   */
  private function formState(array $fields) {
    $values = [];
    foreach ($fields as $field => $value) {
      $values[$field] = [LANGUAGE_NONE => [0 => ['value' => $value]]];
    }

    return ['values' => $values];
  }

  /* -------------------------------------------------------------------------
   * The two bundle time validators.
   * ---------------------------------------------------------------------- */

  /**
   * The 'area' entry point validates ITS OWN two fields and names them with
   * the labels the operator sees next to the offending input.
   */
  public function testTheAreaValidatorNamesItsTwoFields() {
    $form_state = $this->formState([
      'field_open_time'  => '8:00',
      'field_close_time' => '99:99',
    ]);

    myapi_area_validate_times(NULL, [], $form_state);

    $errors = $this->formErrors();
    $this->assertCount(2, $errors);
    $this->assertArrayHasKey('field_open_time][und][0][value', $errors);
    $this->assertArrayHasKey('field_close_time][und][0][value', $errors);
    $this->assertStringContainsString('Hora de apertura', $errors['field_open_time][und][0][value']);
    $this->assertStringContainsString('Hora de cierre', $errors['field_close_time][und][0][value']);
    $this->assertStringContainsString('HH:MM', $errors['field_open_time][und][0][value']);
  }

  /**
   * The 'reservation' entry point is its symmetric twin, over the OTHER two
   * fields — a copy-paste that kept the area's field names would validate
   * nothing on this bundle and raise nothing either.
   */
  public function testTheReservationValidatorNamesItsOwnTwoFields() {
    $form_state = $this->formState([
      'field_start_time' => 'ocho',
      'field_end_time'   => '24:00',
    ]);

    myapi_reservation_validate_times(NULL, [], $form_state);

    $errors = $this->formErrors();
    $this->assertCount(2, $errors);
    $this->assertArrayHasKey('field_start_time][und][0][value', $errors);
    $this->assertArrayHasKey('field_end_time][und][0][value', $errors);
    $this->assertStringContainsString('Hora de inicio', $errors['field_start_time][und][0][value']);
    $this->assertStringContainsString('Hora de fin', $errors['field_end_time][und][0][value']);
  }

  /**
   * NEITHER VALIDATOR TOUCHES THE OTHER BUNDLE'S FIELDS. An 'area' form
   * carrying a malformed reservation time — which cannot happen, but is what a
   * merged field map would look like — raises nothing.
   */
  public function testEachValidatorIgnoresTheOtherBundlesFields() {
    $form_state = $this->formState(['field_start_time' => '99:99', 'field_end_time' => '99:99']);
    myapi_area_validate_times(NULL, [], $form_state);
    $this->assertSame([], $this->formErrors());

    $form_state = $this->formState(['field_open_time' => '99:99', 'field_close_time' => '99:99']);
    myapi_reservation_validate_times(NULL, [], $form_state);
    $this->assertSame([], $this->formErrors());
  }

  /**
   * A WELL-FORMED FORM RAISES NOTHING, including the two shapes the rule
   * deliberately accepts: a close before the open (an area that shuts past
   * midnight) and an end before the start (a reservation that runs across it).
   */
  public function testAWellFormedFormRaisesNothing() {
    // The validators take $form_state by reference, so the array has to be a
    // variable.
    $area = $this->formState(['field_open_time' => '12:00', 'field_close_time' => '02:00']);
    myapi_area_validate_times(NULL, [], $area);
    $this->assertSame([], $this->formErrors(), 'an area that closes past midnight is legal');

    $reservation = $this->formState(['field_start_time' => '22:00', 'field_end_time' => '02:00']);
    myapi_reservation_validate_times(NULL, [], $reservation);
    $this->assertSame([], $this->formErrors(), 'a reservation across midnight is legal');
  }

  /**
   * AN EMPTY VALUE IS SKIPPED AND NOT REPORTED: the 'required' flag on the
   * instance already produces its own message, and two messages for one field
   * on one submit is a bug the operator reads as a broken form.
   */
  public function testAnEmptyValueIsSkippedRatherThanReported() {
    foreach (['', '   ', "\t"] as $value) {
      $GLOBALS['myapi_test_form_errors'] = [];

      $form_state = $this->formState(['field_open_time' => $value, 'field_close_time' => '22:00']);
      myapi_area_validate_times(NULL, [], $form_state);

      $this->assertSame([], $this->formErrors(), json_encode($value));
    }
  }

  /**
   * THE TRIM DECIDES BLANKNESS AND NOTHING ELSE: ' 08:00' is not blank, so it
   * is matched RAW and rejected instead of being stored with its space.
   */
  public function testALeadingSpaceIsRejectedRatherThanTrimmedAway() {
    $form_state = $this->formState(['field_open_time' => ' 08:00', 'field_close_time' => '22:00']);
    myapi_area_validate_times(NULL, [], $form_state);

    $this->assertArrayHasKey('field_open_time][und][0][value', $this->formErrors());
  }

  /**
   * The walk survives the shapes a widget produces around the values: a
   * housekeeping key with no 'value', a non-array delta, and a field that is
   * not an array at all.
   */
  public function testTheWalkSurvivesTheWidgetsHousekeepingKeys() {
    $form_state = ['values' => [
      'field_open_time' => [LANGUAGE_NONE => [
        0 => ['value' => '99:99'],
        'add_more' => 'Add another item',
        1 => 'not an array',
      ]],
      'field_close_time' => 'not an array either',
    ]];

    myapi_area_validate_times(NULL, [], $form_state);

    $errors = $this->formErrors();
    $this->assertCount(1, $errors, 'only the real malformed value is reported');
    $this->assertArrayHasKey('field_open_time][und][0][value', $errors);
  }

  /* -------------------------------------------------------------------------
   * myapi_file_download_safe_filename().
   * ---------------------------------------------------------------------- */

  /**
   * An ordinary name travels unchanged, accents included.
   */
  public function testAnOrdinaryFilenameIsUnchanged() {
    $this->assertSame('recibo.pdf', myapi_file_download_safe_filename('recibo.pdf'));
    $this->assertSame('Comprobante de pago.pdf', myapi_file_download_safe_filename('Comprobante de pago.pdf'));
    $this->assertSame('recibo-junio-2026 (1).pdf', myapi_file_download_safe_filename('recibo-junio-2026 (1).pdf'));
  }

  /**
   * THE THREE CHARACTERS THAT COULD ESCAPE THE HEADER ARE REMOVED: a newline
   * (a second header), a double quote (which ends the quoted parameter) and a
   * backslash (which escapes inside it).
   */
  public function testTheCharactersThatEscapeTheHeaderAreRemoved() {
    // The control characters are STRIPPED, not cut at: what survives is the
    // rest of the name joined together, which is inert inside the quoted
    // parameter — there is no newline left to start a second header with.
    $this->assertSame('recibo.pdfX-Evil: 1', myapi_file_download_safe_filename("recibo.pdf\r\nX-Evil: 1"));
    $this->assertStringNotContainsString("\n", myapi_file_download_safe_filename("recibo.pdf\r\nX-Evil: 1"));
    $this->assertSame('X-Evil: 1', myapi_file_download_safe_filename("\r\nX-Evil: 1"));
    $this->assertSame('a.jpg', myapi_file_download_safe_filename('a".jpg'));
    $this->assertSame('a.jpg', myapi_file_download_safe_filename('a\\.jpg'));
    $this->assertSame('ab', myapi_file_download_safe_filename("a\x00b"));
    $this->assertSame('ab', myapi_file_download_safe_filename("a\x7Fb"));
  }

  /**
   * Surrounding whitespace goes, because it means nothing once the value is
   * quoted.
   */
  public function testSurroundingWhitespaceIsTrimmed() {
    $this->assertSame('recibo.pdf', myapi_file_download_safe_filename('   recibo.pdf   '));
    $this->assertSame('recibo.pdf', myapi_file_download_safe_filename("\trecibo.pdf\t"));
  }

  /**
   * AN EMPTY RESULT FALLS BACK TO A GENERIC NAME, because a filename="" is a
   * header a client may honour by saving nothing.
   */
  public function testAnEmptyResultFallsBackToAGenericName() {
    foreach (['', '   ', '"""', "\r\n", NULL, 42, ['a.pdf'], TRUE] as $value) {
      $this->assertSame('download', myapi_file_download_safe_filename($value), json_encode($value));
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_parse_id_param().
   * ---------------------------------------------------------------------- */

  /**
   * An absent parameter is NULL — "no filter" — and a positive integer is
   * itself.
   */
  public function testTheIdParamAnswersNullOrTheInteger() {
    $_GET = [];
    $this->assertNull(myapi_parse_id_param('category_id'));

    $_GET = ['category_id' => '17'];
    $this->assertSame(17, myapi_parse_id_param('category_id'));

    $_GET = ['category_id' => '007'];
    $this->assertSame(7, myapi_parse_id_param('category_id'), 'leading zeros are normalised');
  }

  /**
   * EVERY MALFORMATION IS A 422 NAMING THE PARAMETER — including the empty
   * string, which is a present-and-broken value and not an absence.
   */
  public function testEveryMalformationIsA422NamingTheParameter() {
    foreach (['0', '-1', 'abc', '1.5', '', '+2', '1a'] as $value) {
      $_GET = ['unit_id' => $value];

      $result = myapi_test_capture(function () {
        myapi_parse_id_param('unit_id');
      });

      $this->assertSame(422, $result['status'], json_encode($value));
      $this->assertSame('invalid_field', $result['json']['error_code'], json_encode($value));
      $this->assertStringContainsString('unit_id', $result['json']['error'], json_encode($value));
    }
  }

  /**
   * THE is_scalar() GUARD COMES FIRST, so an array parameter answers a clean
   * 422 instead of a PHP notice in the middle of a JSON body. This is the
   * guard the three twin listings do not have — see SPEC 121, "Los hallazgos".
   */
  public function testAnArrayParameterIsRejectedWithoutANotice() {
    $_GET = ['unit_id' => ['1']];

    $notices = [];
    set_error_handler(function ($severity, $message) use (&$notices) {
      $notices[] = $message;

      return TRUE;
    });
    try {
      $result = myapi_test_capture(function () {
        myapi_parse_id_param('unit_id');
      });
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $notices, 'not one notice');
    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * The reverse unit-access resolvers.
   * ---------------------------------------------------------------------- */

  /**
   * The owner and occupant resolvers each read their own fields, and the
   * occupant one merges the legacy single-value field with the current
   * multi-value one.
   */
  public function testTheOwnerAndOccupantResolvers() {
    myapi_test_db_seed([
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '46', 'field_propietario_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '47', 'field_propietario_target_id' => '9', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '48', 'field_propietario_target_id' => '3', 'deleted' => '1', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupante' => [
        ['entity_id' => '50', 'field_ocupante_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => '51', 'field_ocupantes_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
        // The same unit through both fields: it must come back once.
        ['entity_id' => '50', 'field_ocupantes_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame(['45', '46'], myapi_user_owned_unit_nids(3), 'deleted rows do not count');
    $this->assertSame(['50', '51'], myapi_user_occupied_unit_nids(3), 'both fields, deduplicated');
    $this->assertSame([], myapi_user_owned_unit_nids(999));
  }

  /**
   * myapi_units_condominium_nids() maps units to their buildings, deduplicated,
   * and skips the query entirely for an empty input — an "IN ()" is invalid
   * SQL in Drupal 7.
   */
  public function testTheUnitToCondominiumResolver() {
    myapi_test_db_seed([
      'field_data_field_condominio' => [
        ['entity_id' => '45', 'field_condominio_target_id' => '12', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '46', 'field_condominio_target_id' => '12', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '50', 'field_condominio_target_id' => '13', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame(['12'], myapi_units_condominium_nids([45, 46]));
    $this->assertSame(['12', '13'], myapi_units_condominium_nids([45, 50]));

    myapi_test_db_seed([]);
    $this->assertSame([], myapi_units_condominium_nids([]));
    $this->assertSame([], myapi_test_db_queries('field_data_field_condominio'), 'no query at all');
  }

  /**
   * myapi_condominium_member_uids() is the reverse direction: from buildings to
   * the people in them, filtered by role — and 'todos' is the union of both.
   */
  public function testTheCondominiumMemberResolver() {
    myapi_test_db_seed([
      'field_data_field_condominio' => [
        ['entity_id' => '45', 'field_condominio_target_id' => '12', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '50', 'field_condominio_target_id' => '13', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '50', 'field_propietario_target_id' => '9', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupante' => [],
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '4', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame(['3'], myapi_condominium_member_uids([12], 'propietarios'));
    $this->assertSame(['4'], myapi_condominium_member_uids([12], 'ocupantes'));

    $all = myapi_condominium_member_uids([12], 'todos');
    sort($all);
    $this->assertSame(['3', '4'], $all);

    $this->assertSame([], myapi_condominium_member_uids([], 'todos'), 'no building, nobody');
    $this->assertSame([], myapi_condominium_member_uids([12], 'vecinos'), 'an unknown role is fail-safe');
  }

  /* -------------------------------------------------------------------------
   * The node-file helpers.
   * ---------------------------------------------------------------------- */

  /**
   * The current fids of a node are the positive ones of its file field, in
   * order; a node with no field, an empty field or a zero fid answers an empty
   * list.
   */
  public function testTheCurrentFidsOfANode() {
    $node = (object) [];
    $node->field_images[LANGUAGE_NONE] = [
      ['fid' => '7'], ['fid' => 8], ['fid' => '0'], ['display' => 1], ['fid' => '-3'],
    ];

    $this->assertSame([7, 8], myapi_node_files_current_fids($node, 'field_images'));
    $this->assertSame([], myapi_node_files_current_fids($node, 'field_ausente'));

    $empty = (object) [];
    $empty->field_images[LANGUAGE_NONE] = [];
    $this->assertSame([], myapi_node_files_current_fids($empty, 'field_images'));
  }

  /**
   * With no removal field the parser answers an empty list and never errors —
   * an update that removes nothing is the common case.
   */
  public function testNoRemovalFieldIsAnEmptyList() {
    $_POST = [];

    $this->assertSame([], myapi_node_files_parse_removals('remove_file_ids', [7, 8]));
  }

  /**
   * Valid fids come back deduplicated, in the order they were sent.
   */
  public function testValidRemovalsAreDeduplicated() {
    $_POST = ['remove_file_ids' => ['8', '7', '8']];

    $this->assertSame([8, 7], myapi_node_files_parse_removals('remove_file_ids', [7, 8]));
  }

  /**
   * A MALFORMED FID AND A FID THE NODE DOES NOT REFERENCE FAIL THE SAME WAY.
   * That is the whole point: silence plus a partial delete would let a stray
   * id become a way to probe other people's files.
   */
  public function testAMalformedOrForeignFidIsTheSame422() {
    foreach ([['0'], ['-3'], ['abc'], ['1.5'], ['999']] as $sent) {
      $_POST = ['remove_file_ids' => $sent];

      $result = myapi_test_capture(function () {
        myapi_node_files_parse_removals('remove_file_ids', [7, 8]);
      });

      $this->assertSame(422, $result['status'], json_encode($sent));
      $this->assertSame('invalid_field', $result['json']['error_code'], json_encode($sent));
      $this->assertStringContainsString('remove_file_ids', $result['json']['error'], json_encode($sent));
    }
  }

  /**
   * The deletion releases the usage record BEFORE deleting the file, and skips
   * a fid whose managed file is already gone rather than failing.
   */
  public function testTheDeletionReleasesTheUsageAndSkipsWhatIsGone() {
    myapi_test_file_seed([
      7 => ['fid' => 7, 'uri' => 'private://a.pdf', 'filename' => 'a.pdf'],
    ]);
    myapi_test_write_reset();

    myapi_node_files_delete_removed([7, 999], 500);

    $usage = myapi_test_file_usage();
    $this->assertCount(1, $usage);
    $this->assertSame('delete', $usage[0]['op']);
    $this->assertSame(7, $usage[0]['fid']);
    $this->assertSame(500, $usage[0]['id']);
    $this->assertSame([7], myapi_test_file_deletes(), 'the missing fid is skipped');
  }

  /* -------------------------------------------------------------------------
   * The mail queue.
   * ---------------------------------------------------------------------- */

  /**
   * A valid recipient is enqueued with its key, its params and a zero attempt
   * counter.
   */
  public function testAValidRecipientIsEnqueued() {
    $this->assertTrue(myapi_mail_queue_enqueue('reservation_created_user', 'p@example.com', ['area' => 'Piscina']));

    $items = myapi_test_queue_items(MYAPI_MAIL_QUEUE);
    $this->assertCount(1, $items);
    $this->assertSame('reservation_created_user', $items[0]['data']['key']);
    $this->assertSame('p@example.com', $items[0]['data']['to']);
    $this->assertSame(['area' => 'Piscina'], $items[0]['data']['params']);
    $this->assertSame(0, $items[0]['data']['attempts']);
  }

  /**
   * AN INVALID RECIPIENT IS LOGGED AND SKIPPED, never thrown: one bad address
   * must not drag a batch down.
   */
  public function testAnInvalidRecipientIsLoggedAndSkipped() {
    foreach (['', 'roto', 'a@', '@b.c', NULL] as $to) {
      myapi_test_queue_reset();
      $GLOBALS['myapi_test_watchdog'] = [];

      $this->assertFalse(myapi_mail_queue_enqueue('x', (string) $to, []), json_encode($to));
      $this->assertSame([], myapi_test_queue_items(MYAPI_MAIL_QUEUE), json_encode($to));
      $this->assertNotSame([], $GLOBALS['myapi_test_watchdog'], json_encode($to));
    }
  }

  /**
   * The worker sends the item through drupal_mail() with the module's own name
   * and the item's key.
   */
  public function testTheWorkerSendsTheItem() {
    myapi_mail_queue_worker(['key' => 'reservation_created_user', 'to' => 'p@example.com', 'params' => ['a' => 1], 'attempts' => 0]);

    $mails = myapi_test_mails();
    $this->assertCount(1, $mails);
    $this->assertSame('myapi', $mails[0]['module']);
    $this->assertSame('reservation_created_user', $mails[0]['key']);
    $this->assertSame('p@example.com', $mails[0]['to']);
    $this->assertSame(['a' => 1], $mails[0]['params']);
  }

  /**
   * AN ITEM WITH NO KEY OR NO RECIPIENT IS DROPPED WITHOUT SENDING: a
   * corrupted queue row must not become a mail to nowhere.
   */
  public function testAnIncompleteItemIsDroppedWithoutSending() {
    myapi_mail_queue_worker([]);
    myapi_mail_queue_worker(['key' => 'x']);
    myapi_mail_queue_worker(['to' => 'p@example.com']);

    $this->assertSame([], myapi_test_mails());
  }

  /**
   * A FAILED SEND IS RETRIED, with the counter incremented, until the bounded
   * limit — and then dropped with an error rather than retried forever.
   */
  public function testAFailedSendIsRetriedUntilTheLimitAndThenDropped() {
    myapi_test_mail_reset(FALSE);

    myapi_mail_queue_worker(['key' => 'x', 'to' => 'p@example.com', 'params' => [], 'attempts' => 0]);
    $requeued = myapi_test_queue_items(MYAPI_MAIL_QUEUE);
    $this->assertCount(1, $requeued, 'the item was requeued');
    $this->assertSame(1, $requeued[0]['data']['attempts']);
    $this->assertSame(WATCHDOG_WARNING, $GLOBALS['myapi_test_watchdog'][0]['severity']);

    myapi_test_queue_reset();
    $GLOBALS['myapi_test_watchdog'] = [];
    myapi_mail_queue_worker(['key' => 'x', 'to' => 'p@example.com', 'params' => [], 'attempts' => MYAPI_MAIL_QUEUE_MAX_ATTEMPTS - 1]);

    $this->assertSame([], myapi_test_queue_items(MYAPI_MAIL_QUEUE), 'dropped, not retried forever');
    $this->assertSame(WATCHDOG_ERROR, $GLOBALS['myapi_test_watchdog'][0]['severity']);
    $this->assertStringContainsString('dropped', $GLOBALS['myapi_test_watchdog'][0]['text']);
  }

  /**
   * A SUCCESSFUL SEND REQUEUES NOTHING AND LOGS NOTHING.
   */
  public function testASuccessfulSendIsSilent() {
    myapi_mail_queue_worker(['key' => 'x', 'to' => 'p@example.com', 'params' => [], 'attempts' => 0]);

    $this->assertSame([], myapi_test_queue_items(MYAPI_MAIL_QUEUE));
    $this->assertSame([], $GLOBALS['myapi_test_watchdog']);
  }

  /* -------------------------------------------------------------------------
   * The OneSignal worker.
   * ---------------------------------------------------------------------- */

  /**
   * Both credentials are required: either one missing means "not configured".
   */
  public function testBothCredentialsAreRequired() {
    $GLOBALS['myapi_test_variables'] = [];
    $this->assertFalse(myapi_onesignal_is_configured());

    $GLOBALS['myapi_test_variables'] = ['myapi_onesignal_app_id' => 'app'];
    $this->assertFalse(myapi_onesignal_is_configured());

    $GLOBALS['myapi_test_variables'] = ['myapi_onesignal_rest_api_key' => 'key'];
    $this->assertFalse(myapi_onesignal_is_configured());

    $GLOBALS['myapi_test_variables'] = ['myapi_onesignal_app_id' => 'app', 'myapi_onesignal_rest_api_key' => 'key'];
    $this->assertTrue(myapi_onesignal_is_configured());
  }

  /**
   * AN UNCONFIGURED SITE DROPS THE ITEM AND LOGS, rather than throwing — a
   * staging environment with no credentials must not accumulate a queue it can
   * never drain.
   */
  public function testAnUnconfiguredSiteDropsThePushAndLogs() {
    myapi_test_http_reset();
    $GLOBALS['myapi_test_variables'] = [];

    myapi_onesignal_queue_worker(['external_ids' => ['3'], 'title' => 'T', 'body' => 'B', 'data' => []]);

    $this->assertSame([], myapi_test_http_requests(), 'nothing left the server');
    $this->assertNotSame([], $GLOBALS['myapi_test_watchdog']);
  }

  /**
   * An item with no recipients is dropped before the credentials are even
   * checked.
   */
  public function testAnItemWithNoRecipientsIsDropped() {
    myapi_test_http_reset();
    $GLOBALS['myapi_test_variables'] = [];

    myapi_onesignal_queue_worker(['external_ids' => []]);
    myapi_onesignal_queue_worker([]);

    $this->assertSame([], myapi_test_http_requests());
    $this->assertSame([], $GLOBALS['myapi_test_watchdog'], 'and it is not even worth a log line');
  }

  /**
   * A configured site sends, and A FAILED SEND THROWS so the Queue API retries
   * it on the next cron — the opposite of the mail queue, which counts its own
   * attempts.
   */
  public function testAFailedPushThrowsSoTheQueueRetriesIt() {
    $GLOBALS['myapi_test_variables'] = ['myapi_onesignal_app_id' => 'app', 'myapi_onesignal_rest_api_key' => 'key'];
    myapi_test_http_reset();

    myapi_onesignal_queue_worker(['external_ids' => ['3'], 'title' => 'T', 'body' => 'B', 'data' => ['target' => 'bulletin']]);
    $this->assertCount(1, myapi_test_http_requests());

    myapi_test_http_reset();
    $GLOBALS['myapi_test_http_code'] = 500;

    $this->expectException(Exception::class);
    myapi_onesignal_queue_worker(['external_ids' => ['3'], 'title' => 'T', 'body' => 'B', 'data' => []]);
  }

  /* -------------------------------------------------------------------------
   * The fee-issued notification (SPEC 28).
   * ---------------------------------------------------------------------- */

  /**
   * A fee node in a given state.
   */
  private function fee($type, $status, array $spec = []) {
    $spec += ['nid' => 600, 'unit' => 45, 'amount' => '187.32'];

    $node = (object) ['nid' => $spec['nid'], 'type' => $type, 'status' => 1, 'title' => 'Recibo'];
    if ($status !== NULL) {
      $node->field_estado[LANGUAGE_NONE][0]['value'] = $status;
    }
    if ($spec['unit'] !== NULL) {
      $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] = $spec['unit'];
    }
    $field = $type === 'recibo' ? 'field_total_mes' : 'field_valor_extra';
    $node->{$field}[LANGUAGE_NONE][0]['value'] = $spec['amount'];

    return $node;
  }

  /**
   * The field reader is the same LANGUAGE_NONE[0] accessor as everywhere else.
   */
  public function testTheFeeFieldReader() {
    $node = $this->fee('recibo', 'Enviado');

    $this->assertSame('Enviado', myapi_fee_field_value($node, 'field_estado'));
    $this->assertNull(myapi_fee_field_value($node, 'field_ausente'));
  }

  /**
   * THE TRANSITION IS "ANYTHING BUT SENT" -> "SENT", ON AN UPDATE ONLY. An
   * insert has no ->original, which is what keeps a fee created already sent
   * from notifying twice.
   */
  public function testTheSentTransitionFiresOnceAndOnlyOnAnUpdate() {
    $sent = $this->fee('recibo', MYAPI_FEE_STATUS_SENT);

    $this->assertFalse(myapi_fee_is_sent_transition($sent), 'an insert never fires');

    foreach (['Borrador', 'Anulado', NULL] as $previous) {
      $update = $this->fee('recibo', MYAPI_FEE_STATUS_SENT);
      $update->original = $this->fee('recibo', $previous);
      $this->assertTrue(myapi_fee_is_sent_transition($update), json_encode($previous));
    }

    $already = $this->fee('recibo', MYAPI_FEE_STATUS_SENT);
    $already->original = $this->fee('recibo', MYAPI_FEE_STATUS_SENT);
    $this->assertFalse(myapi_fee_is_sent_transition($already), 'already sent');

    $other = $this->fee('recibo', 'Borrador');
    $other->original = $this->fee('recibo', 'Anulado');
    $this->assertFalse(myapi_fee_is_sent_transition($other), 'not a send at all');
  }

  /**
   * The notification reaches the OCCUPANTS of the unit — not its owner — with
   * the unit title and the amount in its body, and the deep link of its own
   * kind.
   */
  public function testTheFeeNotificationReachesTheOccupants() {
    $unit = (object) ['nid' => 45, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101'];
    $unit->field_condominio[LANGUAGE_NONE][0]['target_id'] = 12;
    myapi_test_node_seed([45 => $unit]);
    myapi_test_db_seed([
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '9', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupante' => [
        ['entity_id' => '45', 'field_ocupante_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupantes' => [],
    ]);
    $GLOBALS['myapi_test_db_writes'] = [];

    myapi_fee_notify_issued($this->fee('recibo', MYAPI_FEE_STATUS_SENT));

    $rows = myapi_test_db_writes('myapi_notifications')[0]['rows'];
    $this->assertCount(1, $rows);
    $this->assertSame(3, $rows[0]['uid'], 'the occupant, not the owner');
    $this->assertSame(MYAPI_NOTIFICATION_TYPE_RECEIPT_SENT, $rows[0]['type']);
    $this->assertSame(45, $rows[0]['unit_id']);
    $this->assertSame(12, $rows[0]['condominium_id']);
    $this->assertStringContainsString('A-101', $rows[0]['body']);
    $this->assertStringContainsString('187.32', $rows[0]['body']);
  }

  /**
   * THE TWO BUNDLES DIFFER IN FOUR VALUES AND NOTHING ELSE: the value field,
   * the inserted word, the source and the type.
   */
  public function testTheTwoBundlesDifferInFourValues() {
    $unit = (object) ['nid' => 45, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101'];
    myapi_test_node_seed([45 => $unit]);
    myapi_test_db_seed([
      'field_data_field_ocupante' => [
        ['entity_id' => '45', 'field_ocupante_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupantes' => [],
    ]);
    $GLOBALS['myapi_test_db_writes'] = [];

    myapi_fee_notify_issued($this->fee('alicuota_extra', MYAPI_FEE_STATUS_SENT, ['amount' => '25.00']));

    $row = myapi_test_db_writes('myapi_notifications')[0]['rows'][0];
    $this->assertSame(MYAPI_NOTIFICATION_TYPE_EXTRA_FEE_SENT, $row['type']);
    $this->assertStringContainsString('alícuota extra', $row['title']);
    $this->assertStringContainsString('25.00', $row['body']);
  }

  /**
   * AN UNKNOWN BUNDLE IS A NO-OP, before the once-per-request guard is even
   * set — so a node type this file does not know about costs nothing.
   */
  public function testAnUnknownBundleIsANoOp() {
    myapi_fee_notify_issued($this->fee('pagos', MYAPI_FEE_STATUS_SENT));

    $this->assertSame([], myapi_test_db_writes());
  }

  /**
   * ONCE PER NODE PER REQUEST. A Rule recalculating balances re-saves the same
   * object with a stale ->original, and the guard is what stops the second
   * notification.
   */
  public function testTheFeeNotifiesOncePerRequest() {
    $unit = (object) ['nid' => 45, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101'];
    myapi_test_node_seed([45 => $unit]);
    myapi_test_db_seed([
      'field_data_field_ocupante' => [
        ['entity_id' => '45', 'field_ocupante_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupantes' => [],
    ]);
    $GLOBALS['myapi_test_db_writes'] = [];

    myapi_fee_notify_issued($this->fee('recibo', MYAPI_FEE_STATUS_SENT));
    myapi_fee_notify_issued($this->fee('recibo', MYAPI_FEE_STATUS_SENT));

    $this->assertCount(1, myapi_test_db_writes('myapi_notifications'));
  }

  /**
   * A fee with no unit, or one whose unit has no occupant, notifies nobody and
   * writes nothing.
   */
  public function testAFeeWithNobodyToNotifyWritesNothing() {
    myapi_test_node_seed([]);
    myapi_test_db_seed([]);
    $GLOBALS['myapi_test_db_writes'] = [];

    myapi_fee_notify_issued($this->fee('recibo', MYAPI_FEE_STATUS_SENT, ['unit' => NULL]));
    $this->assertSame([], myapi_test_db_writes());

    myapi_test_static_reset();
    myapi_fee_notify_issued($this->fee('recibo', MYAPI_FEE_STATUS_SENT));
    $this->assertSame([], myapi_test_db_writes(), 'a unit with no occupant notifies nobody');
  }
}
