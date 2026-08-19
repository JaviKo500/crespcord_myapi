<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_files.inc';
require_once __DIR__ . '/../../includes/myapi.node_files.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * Unit tests for the pure pieces of
 * POST /api/v1/service-requests/% (SPEC 96).
 *
 * The gate is the one decision of that endpoint that needs neither Drupal nor a
 * database: given the row the detail query already paid for and the offer count
 * SPEC 88's aggregate already answers, does this request still admit being
 * edited. Everything else — the ownership check against field_requester, the
 * uploads, the node_save() and the deletion of the files that left the
 * request — is verified with an HTTP client against the running site (see step
 * 4 of the spec and docs/service-request.md).
 *
 * The two rules worth stating out loud, because both are easy to "fix" into
 * bugs:
 *
 * - A status that is not exactly 'open' is not editable, and that INCLUDES an
 *   empty or unknown one. The comparison is strict against the literal on
 *   purpose: "I do not know what state this is in" has to read as "I do not let
 *   it be edited", never as a 500.
 * - Zero offers means zero, whatever became of them. The count this function
 *   receives includes 'withdrawn' and 'rejected' offers, because a provider who
 *   already read the statement must not find it changed.
 *
 * THE SECOND HALF OF THIS SUITE DRIVES THE ENDPOINT, the same way
 * ClaimWriteGuardsTest drives POST /api/v1/claims/%: the token middleware, the
 * validators, the removals, the quota arithmetic, the composition of the final
 * field_images and the sixteen-key body all run for real, over the fixture query
 * builder of bootstrap.php.
 *
 * WHAT DOES NOT RUN: node_save() is a recorder, so a green case says "the
 * resource asked for this node", never "Drupal stored it". REAL UPLOADS ARE OUT
 * ENTIRELY, the same boundary the create suite draws: a fake $_FILES entry gets
 * as far as myapi_node_files_save()'s own count guard — which fires BEFORE it
 * ever reaches field_info_field() or file_save_upload() — and no further. Two
 * consequences worth naming, because they are the shape of this file and not an
 * oversight:
 *
 * - The quota is pinned by its CEILING, one file above what fits. That a request
 *   which deletes three and uploads three is ACCEPTED cannot be asserted here —
 *   accepting it means saving files — and lives in the manual pass of the spec.
 * - 'remove_attachment' being ignored when a new attachment arrives needs a
 *   SAVED attachment, so it is asserted over the source of the guard, the same
 *   technique ServiceRequestDetailEndpointTest uses for the file endpoint's
 *   membership check.
 */
class ServiceRequestUpdateTest extends TestCase {

  /**
   * Builds the only thing the gate reads: a row carrying a status.
   *
   * @param mixed $status  The value of field_request_status, however broken.
   *
   * @return object  A stand-in for myapi_service_request_detail_row()'s row.
   */
  private function row($status) {
    return (object) ['nid' => 42, 'status' => $status];
  }

  /* -------------------------------------------------------------------------
   * The gate — the status half.
   * ---------------------------------------------------------------------- */

  /**
   * The only combination that admits an edit: 'open' and nobody has bid.
   */
  public function testOpenWithoutOffersIsEditable() {
    $this->assertTrue(
      myapi_service_request_update_gate($this->row(MYAPI_SERVICES_REQUEST_STATUS_OPEN), 0)
    );
  }

  /**
   * The other five statuses of the catalogue, none of them editable — not even
   * 'direct', which has a provider but no offer, so the count alone would let
   * it through.
   */
  public function testEveryOtherStatusIsNotEditable() {
    $statuses = [
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ];

    foreach ($statuses as $status) {
      $this->assertFalse(
        myapi_service_request_update_gate($this->row($status), 0),
        $status
      );
    }
  }

  /**
   * An empty, NULL or unknown field_request_status is answered FALSE and not a
   * PHP error: a corrupt row is a 409, never a 500.
   */
  public function testEmptyOrUnknownStatusIsNotEditable() {
    foreach (['', ' ', 'OPEN', 'Open', 'opened', 'garbage', NULL, 0, FALSE] as $status) {
      $this->assertFalse(
        myapi_service_request_update_gate($this->row($status), 0),
        var_export($status, TRUE)
      );
    }
  }

  /**
   * A row with no `status` property at all — nothing builds one today, but the
   * gate must not emit a notice if something ever does.
   */
  public function testRowWithoutStatusIsNotEditable() {
    $this->assertFalse(myapi_service_request_update_gate((object) ['nid' => 42], 0));
  }

  /* -------------------------------------------------------------------------
   * The gate — the offers half.
   * ---------------------------------------------------------------------- */

  /**
   * 'open' with one live offer: the provider has read and priced the job.
   */
  public function testOpenWithOneOfferIsNotEditable() {
    $this->assertFalse(
      myapi_service_request_update_gate($this->row(MYAPI_SERVICES_REQUEST_STATUS_OPEN), 1)
    );
  }

  /**
   * 'open' with a single WITHDRAWN offer: myapi_service_request_offer_counts_by_nid()
   * counts it, and so does the gate. The provider read the statement before
   * withdrawing.
   */
  public function testOpenWithOneWithdrawnOfferIsNotEditable() {
    $this->assertFalse(
      myapi_service_request_update_gate($this->row(MYAPI_SERVICES_REQUEST_STATUS_OPEN), 1)
    );
  }

  /**
   * 'open' with a single REJECTED offer: the same, for the same reason.
   */
  public function testOpenWithOneRejectedOfferIsNotEditable() {
    $this->assertFalse(
      myapi_service_request_update_gate($this->row(MYAPI_SERVICES_REQUEST_STATUS_OPEN), 1)
    );
  }

  /**
   * Any number above zero closes the gate, and the count arrives as the string
   * a database can hand back without that changing the verdict.
   */
  public function testAnyPositiveOfferCountIsNotEditable() {
    foreach ([1, 2, 5, 99, '1', '3'] as $count) {
      $this->assertFalse(
        myapi_service_request_update_gate($this->row(MYAPI_SERVICES_REQUEST_STATUS_OPEN), $count),
        var_export($count, TRUE)
      );
    }
  }

  /**
   * Zero arriving as '0' or NULL — a missing key of the aggregate's map is
   * zero-filled by that function, but the endpoint's isset() default must read
   * as editable too.
   */
  public function testZeroOfferCountInEveryShapeIsEditable() {
    foreach ([0, '0', NULL] as $count) {
      $this->assertTrue(
        myapi_service_request_update_gate($this->row(MYAPI_SERVICES_REQUEST_STATUS_OPEN), $count),
        var_export($count, TRUE)
      );
    }
  }

  /**
   * Both conditions failing at once is still one FALSE: the endpoint answers a
   * single 409 and never has to say which half of the gate closed.
   */
  public function testBothConditionsFailingIsNotEditable() {
    $this->assertFalse(
      myapi_service_request_update_gate($this->row(MYAPI_SERVICES_REQUEST_STATUS_OFFERED), 3)
    );
  }

  /* -------------------------------------------------------------------------
   * The endpoint — fixtures.
   * ---------------------------------------------------------------------- */

  const TOKEN = 'a-valid-access-token';
  const UID = 42;
  const NID = 700;
  const UNIT = 55;
  const CONDO = 7;
  const CATEGORY = 12;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_file_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    $_FILES = [];
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_file_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $_POST = [];
    $_FILES = [];
  }

  /**
   * The request row as myapi_service_request_detail_row()'s joins deliver it:
   * 'open', owned by UID, no offer and no award. Qualified keys where a bare
   * alias would collide with a column of `node`, the fixture convention the
   * other three suites of this resource already established.
   */
  private function requestRow(array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) self::NID,
      'type'                           => MYAPI_SERVICES_REQUEST_TYPE,
      // The node's published flag; the request's own status is the qualified
      // key below, since a flat row cannot carry 'status' twice.
      'status'                         => '1',
      'title'                          => 'Fuga en el calentador',
      'created'                        => (string) REQUEST_TIME,
      'fr.field_requester_target_id'   => (string) self::UID,
      'requester_uid'                  => (string) self::UID,
      'fcat.field_category_tid'        => (string) self::CATEGORY,
      'category_code'                  => 'plumbing',
      'category_name'                  => 'Plomería',
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'description'                    => 'El calentador gotea desde el lunes.',
      'desired_start'                  => (string) (REQUEST_TIME + 3600),
      'closed_at'                      => NULL,
      'unit_id'                        => (string) self::UNIT,
      'unit_name'                      => 'A-301',
      'condominium_id'                 => (string) self::CONDO,
      'condominium_name'               => 'Torres del Este',
      'attachment_fid'                 => NULL,
      'attachment_filename'            => NULL,
      'assigned_offer_id'              => NULL,
      'assigned_offer_status'          => NULL,
      'assigned_provider_id'           => NULL,
      'assigned_provider_name'         => NULL,
      'assigned_offer_raw'             => NULL,
      'assigned_provider_raw'          => NULL,
    ];
  }

  /**
   * Seeds the token, the user and the request row. Extra tables let a case add
   * the images the response paints or the offers that close the gate.
   */
  private function seed(array $extra_tables = [], array $row_overrides = []) {
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'aperez', 'status' => 1];

    myapi_test_db_seed($extra_tables + [
      'my_api_tokens' => [[
        'id'                => '1',
        'uid'               => (string) self::UID,
        'access_token_hash' => myapi_token_hash(self::TOKEN),
        'revoked'           => '0',
        'access_expires_at' => REQUEST_TIME + 1800,
      ]],
      'users' => [[
        'uid'        => (string) self::UID,
        'name'       => 'aperez',
        'first_name' => 'Ana',
        'last_name'  => 'Pérez',
      ]],
      'node' => [$this->requestRow($row_overrides)],
    ]);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * The loaded node the endpoint overwrites and saves, with the file fields the
   * case needs. Only the fields an edit touches are shaped; everything else is
   * left out precisely because the endpoint must not invent it.
   *
   * @param array $image_fids  Fids of field_images, in delta order.
   * @param mixed $attachment_fid  Fid of field_attachment, or NULL for none.
   */
  private function seedNode(array $image_fids = [], $attachment_fid = NULL) {
    $node = [
      'nid'    => self::NID,
      'type'   => MYAPI_SERVICES_REQUEST_TYPE,
      'uid'    => 41,
      'status' => 1,
      'title'  => 'Fuga en el calentador',
      'field_request_status' => [LANGUAGE_NONE => [['value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN]]],
      'field_requester'      => [LANGUAGE_NONE => [['target_id' => self::UID]]],
      'field_category'       => [LANGUAGE_NONE => [['target_id' => self::CATEGORY]]],
      'field_unit'           => [LANGUAGE_NONE => [['target_id' => self::UNIT]]],
      'field_condominium'    => [LANGUAGE_NONE => [['target_id' => self::CONDO]]],
    ];

    $items = [];
    foreach ($image_fids as $fid) {
      $items[] = ['fid' => $fid, 'display' => 1];
    }
    $node['field_images'] = [LANGUAGE_NONE => $items];

    if ($attachment_fid !== NULL) {
      $node['field_attachment'] = [LANGUAGE_NONE => [['fid' => $attachment_fid, 'display' => 1]]];
    }

    myapi_test_node_seed([self::NID => $node]);

    // Every fid the node references loads, so the deletion step can run for
    // real. A case that wants "the file is already gone" seeds its own.
    $files = [];
    foreach ($image_fids as $fid) {
      $files[$fid] = ['fid' => $fid, 'uri' => 'private://service-requests/' . $fid . '.jpg'];
    }
    if ($attachment_fid !== NULL) {
      $files[$attachment_fid] = ['fid' => $attachment_fid, 'uri' => 'private://service-requests/' . $attachment_fid . '.pdf'];
    }
    myapi_test_file_seed($files);
  }

  /**
   * The three mandatory text fields, valid. Every case starts from these and
   * overrides what it is about.
   */
  private function validPost(array $overrides = []) {
    $_POST = $overrides + [
      'title'         => 'Fuga en el calentador del baño',
      'description'   => 'Sigue goteando, ahora más.',
      'desired_start' => date('Y-m-d H:i:s', REQUEST_TIME + 86400),
    ];
  }

  /**
   * A fake $_FILES entry for 'images[]' with $count files. It never gets past
   * myapi_node_files_save()'s count guard — see the class docblock — which is
   * exactly what makes the quota assertable without a filesystem.
   */
  private function fakeImages($count) {
    $entry = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    for ($i = 0; $i < $count; $i++) {
      $entry['name'][] = 'foto' . $i . '.jpg';
      $entry['type'][] = 'image/jpeg';
      $entry['tmp_name'][] = '/tmp/php' . $i;
      $entry['error'][] = UPLOAD_ERR_OK;
      $entry['size'][] = 1024;
    }

    return $entry;
  }

  /**
   * POSTs the item route through the dispatcher, so the new POST branch is
   * exercised and not bypassed.
   */
  private function update($nid = self::NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_request_item_dispatch((string) $nid);
    });
  }

  /**
   * The resource's source with the comments stripped, so an assertion about a
   * guard cannot be satisfied by a docblock that merely mentions it.
   */
  private function codeWithoutComments() {
    $code = file_get_contents(__DIR__ . '/../../resources/service_request.resource.inc');

    return preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $code);
  }

  /* -------------------------------------------------------------------------
   * The gate, through the endpoint.
   * ---------------------------------------------------------------------- */

  /**
   * ONE PUBLISHED OFFER CLOSES THE GATE: 409 service_request_not_editable, and
   * nothing is written.
   *
   * This case is also what proves the happy paths below are green for the right
   * reason. Every one of them relies on myapi_service_request_offer_counts_by_nid()
   * answering 0, and a count query that silently matched nothing would answer 0
   * too — so a suite without this case would assert the gate is open without
   * ever having asked it anything.
   */
  public function testAnOfferClosesTheGateThroughTheEndpoint() {
    $this->seed([
      'field_data_field_request' => [[
        'fq.field_request_target_id' => (string) self::NID,
        'field_request_target_id'    => (string) self::NID,
        'entity_type'                => 'node',
        'deleted'                    => '0',
        'nid'                        => '46',
        'no.type'                    => MYAPI_SERVICES_OFFER_TYPE,
        'no.status'                  => '1',
      ]],
    ]);
    $this->seedNode([7]);
    $this->validPost();

    $result = $this->update();

    $this->assertSame(409, $result['status']);
    $this->assertSame('service_request_not_editable', $result['json']['error_code']);
    $this->assertSame('Esta solicitud ya no se puede editar.', $result['json']['error']);
    $this->assertSame([], myapi_test_node_saves(), 'nothing was saved');
    $this->assertSame([], myapi_test_file_deletes(), 'nothing was deleted');
  }

  /**
   * A status other than 'open' closes it too, and the 409 arrives without the
   * offer count having to say anything.
   */
  public function testANonOpenStatusClosesTheGateThroughTheEndpoint() {
    foreach ([
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
      '',
      'garbage',
    ] as $status) {
      myapi_test_write_reset();
      $this->seed([], ['frs.field_request_status_value' => $status]);
      $this->seedNode();
      $this->validPost();

      $result = $this->update();

      $this->assertSame(409, $result['status'], $status);
      $this->assertSame('service_request_not_editable', $result['json']['error_code'], $status);
      $this->assertSame([], myapi_test_node_saves(), $status . ': nothing was saved');
    }
  }

  /* -------------------------------------------------------------------------
   * The image quota.
   * ---------------------------------------------------------------------- */

  /**
   * THE QUOTA IS WHAT SURVIVES THE REMOVALS, not what the request has now:
   * max_new = max(0, 5 - (current - removed)). Each case sends one file more
   * than fits and is answered service_request_too_many_images — and, just as
   * importantly, nothing is written and no file is deleted on the way out.
   */
  public function testTheImageQuotaCountsWhatSurvivesTheRemovals() {
    $cases = [
      // label => [current fids, remove_image_ids, files sent]
      'full, one more'            => [[7, 8, 9, 10, 11], [], 1],
      'full, three out four in'   => [[7, 8, 9, 10, 11], ['7', '8', '9'], 4],
      'full, all out six in'     => [[7, 8, 9, 10, 11], ['7', '8', '9', '10', '11'], 6],
      'empty, six in'             => [[], [], 6],
      'one out, six in'           => [[7], ['7'], 6],
    ];

    foreach ($cases as $label => $case) {
      list($current, $removals, $sent) = $case;

      myapi_test_write_reset();
      $this->seed();
      $this->seedNode($current);
      $this->validPost();
      $_POST['remove_image_ids'] = $removals;
      $_FILES = ['images' => $this->fakeImages($sent)];

      $result = $this->update();

      $this->assertSame(422, $result['status'], $label);
      $this->assertSame('service_request_too_many_images', $result['json']['error_code'], $label);
      $this->assertSame([], myapi_test_node_saves(), $label . ': nothing was saved');
      $this->assertSame([], myapi_test_file_deletes(), $label . ': nothing was deleted');
    }
  }

  /**
   * A request whose quota is exactly 0 — five images and no removals — is NOT
   * an error while it uploads nothing: the ceiling only bites when a file is
   * sent. Together with the first case above, this pins that ceiling at 0
   * rather than merely "small".
   */
  public function testAFullRequestWithNoNewImagesIsStillEditable() {
    $this->seed();
    $this->seedNode([7, 8, 9, 10, 11]);
    $this->validPost();

    $result = $this->update();

    $this->assertSame(200, $result['status']);
    $this->assertCount(1, myapi_test_node_saves());
  }

  /**
   * The removals can never outnumber the existing images — every value is
   * validated against what the node references and duplicates are collapsed —
   * so max_new tops out at 5 and its `< 0` guard is unreachable from outside.
   * A fid repeated three times is treated once, is not an error, and frees one
   * slot and not three.
   */
  public function testARepeatedRemovalIsCountedOnceAndFreesOneSlot() {
    $this->seed();
    $this->seedNode([7, 8, 9, 10, 11]);
    $this->validPost();
    $_POST['remove_image_ids'] = ['7', '7', '7'];
    $_FILES = ['images' => $this->fakeImages(2)];

    $result = $this->update();

    // One slot freed, two files sent: over the ceiling. Had the duplicates
    // counted three times, max_new would have been 3 and this would be a 200.
    $this->assertSame(422, $result['status']);
    $this->assertSame('service_request_too_many_images', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_deletes(), 'the 422 happens before any deletion');
  }

  /* -------------------------------------------------------------------------
   * The final field_images.
   * ---------------------------------------------------------------------- */

  /**
   * THE SURVIVORS KEEP THEIR DELTA ORDER AND THE LIST IS REINDEXED WITHOUT
   * HOLES: removing the middle image leaves [0 => 7, 1 => 9] and not
   * [0 => 7, 2 => 9], which Drupal would store as a field with a gap. The items
   * are COPIED and not rebuilt, so whatever keys they carried ('display' here)
   * survive with them.
   */
  public function testTheSurvivingImagesKeepTheirOrderAndAreReindexed() {
    $this->seed();
    $this->seedNode([7, 8, 9]);
    $this->validPost();
    $_POST['remove_image_ids'] = ['8'];

    $result = $this->update();

    $this->assertSame(200, $result['status']);
    $node = myapi_test_node_saves()[0];
    $this->assertSame(
      [['fid' => 7, 'display' => 1], ['fid' => 9, 'display' => 1]],
      $node->field_images[LANGUAGE_NONE]
    );
    $this->assertSame([8], myapi_test_file_deletes());
  }

  /**
   * Every image can go: a request with no photos at all is valid, and the field
   * is an empty list and never a hole.
   */
  public function testEveryImageCanBeRemoved() {
    $this->seed();
    $this->seedNode([7, 8]);
    $this->validPost();
    $_POST['remove_image_ids'] = ['7', '8'];

    $result = $this->update();

    $this->assertSame(200, $result['status']);
    $node = myapi_test_node_saves()[0];
    $this->assertSame([], $node->field_images[LANGUAGE_NONE]);
    $this->assertSame([7, 8], myapi_test_file_deletes());
  }

  /**
   * No removals and no uploads: the field comes out exactly as it went in, in
   * the same order. This is the case that fails the day somebody rebuilds the
   * field instead of copying what survives.
   */
  public function testWithoutRemovalsTheImagesAreUntouched() {
    $this->seed();
    $this->seedNode([7, 8, 9]);
    $this->validPost();

    $result = $this->update();

    $this->assertSame(200, $result['status']);
    $node = myapi_test_node_saves()[0];
    $this->assertSame(
      [['fid' => 7, 'display' => 1], ['fid' => 8, 'display' => 1], ['fid' => 9, 'display' => 1]],
      $node->field_images[LANGUAGE_NONE]
    );
    $this->assertSame([], myapi_test_file_deletes());
  }

  /**
   * A fid the request does not reference — another request's, a claim's, or
   * none at all — is 422 invalid_field naming the input, and NOT ONE FILE IS
   * TOUCHED on the way out. This is the check that stops a stray fid from
   * becoming a way to probe other people's files.
   */
  public function testARemovalOfAForeignFidIsRejectedAndDeletesNothing() {
    foreach (['99', 'abc', '0', '-1', '1.5'] as $value) {
      myapi_test_write_reset();
      $this->seed();
      $this->seedNode([7, 8]);
      $this->validPost();
      $_POST['remove_image_ids'] = [$value];

      $result = $this->update();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
      $this->assertStringContainsString('remove_image_ids', $result['json']['error'], $value);
      $this->assertSame([], myapi_test_node_saves(), $value . ': nothing was saved');
      $this->assertSame([], myapi_test_file_deletes(), $value . ': nothing was deleted');
    }
  }

  /**
   * The files that left the request are deleted AFTER the node_save(), never
   * before — the order that guarantees the node never references a file that no
   * longer exists. Asserted through the usage record, which is written between
   * the two.
   */
  public function testRemovedFilesAreDeletedAfterTheSave() {
    $this->seed();
    $this->seedNode([7]);
    $this->validPost();
    $_POST['remove_image_ids'] = ['7'];

    $this->update();

    $this->assertCount(1, myapi_test_node_saves(), 'the node was saved');
    $usage = myapi_test_file_usage();
    $this->assertSame('delete', $usage[0]['op']);
    $this->assertSame(7, $usage[0]['fid']);
    $this->assertSame([7], myapi_test_file_deletes());
  }

  /* -------------------------------------------------------------------------
   * The attachment.
   * ---------------------------------------------------------------------- */

  /**
   * 'remove_attachment' empties the field and deletes the file for '1', 'true'
   * and 'TRUE'; anything else leaves it exactly as it was. The documented
   * values and nothing more — 'yes' is not a synonym.
   */
  public function testRemoveAttachmentEmptiesTheFieldOnlyForTheDocumentedValues() {
    $cases = ['1' => TRUE, 'true' => TRUE, 'TRUE' => TRUE, '0' => FALSE, 'false' => FALSE, 'yes' => FALSE];

    foreach ($cases as $sent => $removed) {
      myapi_test_write_reset();
      $this->seed();
      $this->seedNode([], 31);
      $this->validPost();
      $_POST['remove_attachment'] = (string) $sent;

      $result = $this->update();

      $this->assertSame(200, $result['status'], (string) $sent);
      $node = myapi_test_node_saves()[0];
      if ($removed) {
        $this->assertSame([], $node->field_attachment[LANGUAGE_NONE], (string) $sent);
        $this->assertSame([31], myapi_test_file_deletes(), (string) $sent);
      }
      else {
        $this->assertSame([['fid' => 31, 'display' => 1]], $node->field_attachment[LANGUAGE_NONE], (string) $sent);
        $this->assertSame([], myapi_test_file_deletes(), (string) $sent);
      }
    }
  }

  /**
   * Neither 'attachment' nor 'remove_attachment': the attachment stays exactly
   * as it was, and the field is not even rewritten.
   */
  public function testWithoutTheFlagTheAttachmentSurvives() {
    $this->seed();
    $this->seedNode([], 31);
    $this->validPost();

    $result = $this->update();

    $this->assertSame(200, $result['status']);
    $node = myapi_test_node_saves()[0];
    $this->assertSame([['fid' => 31, 'display' => 1]], $node->field_attachment[LANGUAGE_NONE]);
    $this->assertSame([], myapi_test_file_deletes());
  }

  /**
   * 'remove_attachment' IS IGNORED WHEN A NEW ATTACHMENT ARRIVES: the field has
   * cardinality 1, so uploading one already replaces the previous file and the
   * outcome with and without the flag is identical — a 422 would reject a
   * request whose meaning is unambiguous.
   *
   * Asserted over the source and not over a response, because reaching that
   * branch needs a SAVED attachment and this suite cannot upload (see the class
   * docblock). The guard is one conjunct of one expression, and the whole rule
   * is that conjunct: if it is dropped, the flag would empty the field the same
   * request had just filled and delete the file it had just saved.
   */
  public function testTheRemoveAttachmentFlagIsGuardedByTheNewAttachment() {
    $this->assertMatchesRegularExpression(
      '/\$remove_attachment\s*=\s*!\$attachment_file\s*\n?\s*&&/',
      $this->codeWithoutComments(),
      'remove_attachment is only honoured when no new attachment was saved'
    );
  }

  /* -------------------------------------------------------------------------
   * The sixteen-key response.
   * ---------------------------------------------------------------------- */

  /**
   * THE RESPONSE IS THE DETAIL'S NINETEEN KEYS MINUS THREE, IN THE DOCUMENTED
   * ORDER. 'offers', 'offers_count' and 'transactions' are absent because the
   * edit changes none of them — the gate has just proved there is no offer, and
   * the timeline stays where it was — which is also why this object is NOT
   * interchangeable with the GET's and the app has to merge it onto what it
   * already holds.
   */
  public function testTheResponseCarriesTheSixteenKeysInOrder() {
    $this->seed();
    $this->seedNode();
    $this->validPost();

    $result = $this->update();

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame('Solicitud actualizada correctamente.', $result['json']['message']);

    $this->assertSame(
      [
        'id', 'title', 'description', 'status', 'category', 'unit',
        'assigned_offer', 'assigned_provider', 'created', 'desired_start',
        'viewer', 'requester', 'condominium', 'images', 'attachment',
        'closed_at',
      ],
      array_keys($result['json']['data']['service_request'])
    );
  }

  /**
   * The three that are gone, named one by one so a future change that puts one
   * back has to say so here.
   */
  public function testTheResponseCarriesNeitherOffersNorTheTimeline() {
    $this->seed();
    $this->seedNode();
    $this->validPost();

    $item = $this->update()['json']['data']['service_request'];

    $this->assertArrayNotHasKey('offers', $item);
    $this->assertArrayNotHasKey('offers_count', $item);
    $this->assertArrayNotHasKey('transactions', $item);
    $this->assertCount(16, $item);
  }

  /**
   * `viewer` is always 'requester' — the access step proved whoever got here is
   * the field_requester — and `status` is always 'open', because editing does
   * not move the status. `images` is an array and `attachment` is null, never
   * {fid: null}.
   */
  public function testTheResponseAnswersTheRequesterAnOpenRequest() {
    $this->seed();
    $this->seedNode();
    $this->validPost();

    $item = $this->update()['json']['data']['service_request'];

    $this->assertSame('requester', $item['viewer']);
    $this->assertSame(MYAPI_SERVICES_REQUEST_STATUS_OPEN, $item['status']);
    $this->assertSame([], $item['images']);
    $this->assertNull($item['attachment']);
    $this->assertSame(self::NID, $item['id']);
  }

}
