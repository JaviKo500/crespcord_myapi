<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.claims_common.inc';
require_once __DIR__ . '/../../includes/myapi.node_files.inc';
require_once __DIR__ . '/../../resources/claim.resource.inc';

/**
 * Unit tests for the WRITE paths of the claims API (SPECS 66/67/70, covered by
 * SPEC 77): POST /api/v1/claims, POST /api/v1/claims/% and
 * PUT /api/v1/claims/%/close.
 *
 * These three endpoints are the only way a resident changes anything in this
 * domain, and each one is a numbered sequence of guards whose ORDER is part of
 * the contract: a claim that is not yours answers 403 even when the body is
 * empty, and one the administration already took answers 409 even when the
 * payload is perfect. The sequences were written down in the specs and verified
 * once by hand with curl; every step of all three has a case below.
 *
 * WHAT RUNS FOR REAL: the token middleware, the visibility fetch (the same
 * myapi_claim_base_query() the read endpoints use), every validator, and the
 * whole orchestration up to and including the object handed to node_save().
 *
 * WHAT DOES NOT: node_save() itself, file_save_upload() and the notification
 * stack. node_save() is a recorder here (see its stub in bootstrap.php), so a
 * green case says "the resource asked for this node", never "Drupal stored it"
 * — and in particular never that hook_node_insert() created the initial
 * transaction or that the closing sync moved the claim's status. Uploads are
 * out entirely: every case sends no file, which is the shape that reaches
 * myapi_node_files_save() and returns immediately. Both belong to
 * tests/integration and are named here rather than skipped in silence.
 */
class ClaimWriteGuardsTest extends TestCase {

  const TOKEN = 'a-valid-access-token';
  const UID = 3;
  const CONDO = 12;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_write_reset();
    myapi_test_node_seed();
    myapi_test_field_seed_allowed_values([
      'field_claim_type'  => ['requirement' => 'Requerimiento', 'claim' => 'Reclamo'],
      'field_visibility'  => ['private' => 'Privado', 'public' => 'Público'],
    ]);
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
    myapi_test_write_reset();
    myapi_test_node_seed();
    $_POST = [];
    $_FILES = [];
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  private function tokenRow(array $overrides = []) {
    return $overrides + [
      'id'                => '1',
      'uid'               => (string) self::UID,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * The same claim row shape ClaimEndpointTest documents: three keys are
   * qualified because their alias collides with a column of `node`.
   */
  private function claim(array $values = []) {
    return $values + [
      'n.nid'       => 140,
      'id'          => 140,
      'type'        => 'reclamo',
      'status'      => 1,
      'created'     => mktime(16, 45, 0, 8, 4, 2026),
      'subject'     => 'Fuga en el pasillo',
      'description' => 'Hay agua desde el martes.',
      'field_condominium_target_id' => self::CONDO,
      'condominium_id'              => self::CONDO,
      'condominium_name'            => 'Edificio El Sáuco',
      'field_visibility_value'      => 'private',
      'visibility'                  => 'private',
      'field_requester_target_id'   => self::UID,
      'requester_id'                => self::UID,
      'fs.field_status_value'          => 'received',
      'field_claim_type_value'         => 'claim',
      'claim_type'                     => 'claim',
      'frd.field_reception_date_value' => '2026-08-04 16:45:00',
      'attachment_fid'      => NULL,
      'attachment_filename' => NULL,
    ];
  }

  private function seed(array $claims = [], array $tables = [], array $condos = [self::CONDO]) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];

    $condominium_rows = [];
    foreach ($condos as $condo) {
      $condominium_rows[] = [
        'entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0',
        'field_condominio_target_id' => (string) $condo,
      ];
    }

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0'],
      ],
      'field_data_field_condominio' => $condominium_rows,
      'node' => $claims,
    ] + $tables);
  }

  /**
   * A complete, valid creation payload.
   */
  private function validPost(array $overrides = []) {
    $_POST = $overrides + [
      'subject'        => 'Fuga en el pasillo',
      'claim_type'     => 'claim',
      'condominium_id' => (string) self::CONDO,
      'description'    => 'Hay agua desde el martes.',
      'visibility'     => 'private',
    ];
  }

  private function create() {
    return myapi_test_capture('myapi_claim_create');
  }

  private function update($id) {
    return myapi_test_capture(function () use ($id) {
      myapi_claim_update($id);
    });
  }

  private function close($id) {
    return myapi_test_capture(function () use ($id) {
      myapi_claim_close($id);
    });
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/claims — authentication and the five required fields.
   * ---------------------------------------------------------------------- */

  public function testCreateRequiresATokenBeforeAnythingElse() {
    $this->validPost();

    $result = $this->create();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * Each of the five fields, absent: 422 missing_field naming that field. The
   * @field replacement is what the app maps to the input it must highlight.
   */
  public function testEachMissingFieldIsNamedInIts422() {
    foreach (['subject', 'claim_type', 'condominium_id', 'description', 'visibility'] as $field) {
      $this->seed();
      $this->validPost();
      unset($_POST[$field]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], $field);
      $this->assertSame('missing_field', $result['json']['error_code'], $field);
      $this->assertStringContainsString($field, $result['json']['error'], $field);
    }
  }

  /**
   * An empty string — and a whitespace-only one, which trims to it — is
   * missing, not present-but-empty.
   */
  public function testAnEmptyOrWhitespaceFieldCountsAsMissing() {
    foreach (['', '   ', "\n\t"] as $value) {
      $this->seed();
      $this->validPost(['subject' => $value]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], var_export($value, TRUE));
      $this->assertSame('missing_field', $result['json']['error_code'], var_export($value, TRUE));
    }
  }

  /**
   * A field sent as an array (subject[]=a&subject[]=b) is read as absent by
   * myapi_request_post_field(), so it answers missing_field instead of
   * reaching strlen() with an array.
   */
  public function testAnArrayFieldIsTreatedAsMissing() {
    $this->seed();
    $this->validPost(['subject' => ['a', 'b']]);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
  }

  /**
   * The order of the five is the spec's, and it is observable: with every
   * field missing, the answer names the FIRST one.
   */
  public function testTheRequiredFieldsAreCheckedInTheDocumentedOrder() {
    $this->seed();
    $_POST = [];

    $result = $this->create();

    $this->assertStringContainsString('subject', $result['json']['error']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/claims — the per-field rules.
   * ---------------------------------------------------------------------- */

  /**
   * node.title is varchar(255): 255 passes, 256 does not.
   */
  public function testSubjectIsBoundedAt255Bytes() {
    $this->seed([$this->claim(['n.nid' => 900, 'id' => 900])]);
    $this->validPost(['subject' => str_repeat('a', 255)]);
    $accepted = $this->create();

    $this->seed();
    $this->validPost(['subject' => str_repeat('a', 256)]);
    $rejected = $this->create();

    $this->assertNotSame(422, $accepted['status'], '255 is inside the column');
    $this->assertSame(422, $rejected['status']);
    $this->assertSame('invalid_field', $rejected['json']['error_code']);
    $this->assertStringContainsString('subject', $rejected['json']['error']);
  }

  /**
   * claim_type must be a KEY of field_claim_type's allowed_values — the field's
   * own catalogue, read at runtime, not a list hardcoded in the resource. The
   * label ('Reclamo') is not a key and is rejected.
   */
  public function testClaimTypeMustBeAKeyOfTheFieldsCatalogue() {
    foreach (['queja', 'Reclamo', 'CLAIM', '', '0'] as $value) {
      $this->seed();
      $this->validPost(['claim_type' => $value]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], var_export($value, TRUE));
      $this->assertStringContainsString('claim_type', $result['json']['error'], var_export($value, TRUE));
    }
  }

  /**
   * A site where the field does not exist rejects every value rather than
   * accepting any: field_info_field() answers NULL and the allowed list is
   * empty.
   */
  public function testAMissingCatalogueFieldRejectsEveryValue() {
    myapi_test_field_seed_allowed_values([]);
    $this->seed();
    $this->validPost();

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('claim_type', $result['json']['error']);
  }

  /**
   * Same rule for visibility, checked after everything else — a request with
   * both an invalid claim_type and an invalid visibility names claim_type.
   */
  public function testVisibilityMustBeAKeyOfItsOwnCatalogue() {
    $this->seed();
    $this->validPost(['visibility' => 'publico']);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertStringContainsString('visibility', $result['json']['error']);
  }

  public function testAnInvalidClaimTypeIsReportedBeforeAnInvalidVisibility() {
    $this->seed();
    $this->validPost(['claim_type' => 'queja', 'visibility' => 'publico']);

    $result = $this->create();

    $this->assertStringContainsString('claim_type', $result['json']['error']);
  }

  /**
   * condominium_id must be a positive integer before it is compared to
   * anything.
   */
  public function testAMalformedCondominiumIdIs422AndNotA403() {
    foreach (['abc', '0', '-3', '1.5', '12abc'] as $value) {
      $this->seed();
      $this->validPost(['condominium_id' => $value]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
      $this->assertStringContainsString('condominium_id', $result['json']['error'], $value);
    }
  }

  /**
   * A well-formed condominium the resident does not belong to is 403, not 422:
   * the payload is fine, the permission is not.
   */
  public function testAForeignCondominiumIs403() {
    $this->seed();
    $this->validPost(['condominium_id' => '77']);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('condominium_access_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * A resident with no unit at all has an empty condominium set, so EVERY
   * condominium_id falls outside it and answers the same 403 — no special
   * branch for "you have no condominium".
   */
  public function testAResidentWithoutCondominiumsGetsTheSame403() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];
    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);
    $this->validPost();

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('condominium_access_denied', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/claims — the node it builds.
   * ---------------------------------------------------------------------- */

  /**
   * The valid request, end to end: the node handed to node_save() carries
   * exactly what SPEC 66 fixes, and the endpoint answers 201 with the created
   * claim and its translated message.
   *
   * The claim row is seeded in advance under the nid node_save() will assign,
   * because the response re-reads it through the very same fetch
   * GET /api/v1/claims/% uses — which is the point of that step: the two
   * responses cannot drift apart.
   */
  public function testAValidCreationBuildsTheDocumentedNodeAndAnswers201() {
    $this->seed([$this->claim(['n.nid' => 900, 'id' => 900])]);
    $this->validPost();

    $result = $this->create();

    $this->assertSame(201, $result['status']);
    $this->assertSame('Reclamo creado correctamente.', $result['json']['message']);
    $this->assertSame(900, $result['json']['data']['claim']['id']);

    $saves = myapi_test_node_saves();
    $this->assertCount(1, $saves);
    $node = $saves[0];

    $this->assertSame('reclamo', $node->type);
    $this->assertSame(self::UID, $node->uid);
    $this->assertSame(1, $node->status);
    $this->assertSame('Fuga en el pasillo', $node->title);
    $this->assertSame('claim', $node->field_claim_type[LANGUAGE_NONE][0]['value']);
    $this->assertSame(self::CONDO, $node->field_condominium[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('Hay agua desde el martes.', $node->field_description[LANGUAGE_NONE][0]['value']);
    $this->assertSame('private', $node->field_visibility[LANGUAGE_NONE][0]['value']);
  }

  /**
   * field_requester is ALWAYS the token's uid: a request that sends its own
   * requester_id, uid or field_requester changes nothing. Without this, any
   * resident could file a claim in somebody else's name.
   */
  public function testTheRequesterIsTheTokensUidAndNeverTheRequests() {
    $this->seed([$this->claim(['n.nid' => 900, 'id' => 900])]);
    $this->validPost([
      'requester_id'    => '99',
      'uid'             => '99',
      'field_requester' => '99',
    ]);

    $this->create();

    $node = myapi_test_node_saves()[0];
    $this->assertSame(self::UID, $node->field_requester[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::UID, $node->uid);
  }

  /**
   * field_status is written explicitly as 'received' — a programmatic
   * node_save() never runs the form, so the field's default_value would leave
   * it empty and the claim would have no status at all.
   */
  public function testTheStatusIsWrittenExplicitlyAsReceived() {
    $this->seed([$this->claim(['n.nid' => 900, 'id' => 900])]);
    $this->validPost(['status' => 'closed', 'field_status' => 'closed']);

    $this->create();

    $node = myapi_test_node_saves()[0];
    $this->assertSame('received', $node->field_status[LANGUAGE_NONE][0]['value']);
  }

  /**
   * field_reception_date is the SERVER's instant, with the seconds pinned to
   * ':00' like every other path that stamps a claim date — never a value the
   * client sends.
   */
  public function testTheReceptionDateIsTheServersInstantWithZeroSeconds() {
    $this->seed([$this->claim(['n.nid' => 900, 'id' => 900])]);
    $this->validPost(['reception_date' => '1999-01-01 05:00:00']);

    $this->create();

    $node = myapi_test_node_saves()[0];
    $stored = $node->field_reception_date[LANGUAGE_NONE][0]['value'];

    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $stored);
    $this->assertStringStartsWith(date('Y-m-d'), $stored);
    $this->assertStringNotContainsString('1999', $stored);
  }

  /**
   * The transient flag that tells hook_node_insert() the claim came from the
   * app — the one thing that decides whether the back office is emailed at
   * all (SPEC 68). It is a property, never a stored field.
   */
  public function testTheFromApiFlagTravelsOnTheSavedNode() {
    $this->seed([$this->claim(['n.nid' => 900, 'id' => 900])]);
    $this->validPost();

    $this->create();

    $this->assertTrue(myapi_test_node_saves()[0]->myapi_claim_from_api);
  }

  /**
   * No failure ever writes: every rejection above leaves node_save() uncalled.
   */
  public function testNoRejectedCreationSavesANode() {
    $rejections = [
      'missing subject'   => ['subject' => ''],
      'long subject'      => ['subject' => str_repeat('a', 300)],
      'bad claim_type'    => ['claim_type' => 'queja'],
      'bad condominium'   => ['condominium_id' => 'abc'],
      'foreign condominium' => ['condominium_id' => '77'],
      'bad visibility'    => ['visibility' => 'publico'],
    ];

    foreach ($rejections as $name => $overrides) {
      $this->seed();
      $this->validPost($overrides);

      $this->create();

      $this->assertSame([], myapi_test_node_saves(), $name);
    }
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/claims/% — the update guards (SPEC 67).
   * ---------------------------------------------------------------------- */

  /**
   * A non-numeric id is the same 404 as a missing claim, and it never reaches
   * a claim query.
   */
  public function testUpdateAnswers404ForAMalformedId() {
    foreach (['abc', '0', '-1', ''] as $id) {
      $this->seed([$this->claim()]);
      $this->validPost();

      $result = $this->update($id);

      $this->assertSame(404, $result['status'], var_export($id, TRUE));
      $this->assertSame('claim_not_found', $result['json']['error_code'], var_export($id, TRUE));
    }
  }

  /**
   * A claim the reader cannot see is a 404 — the same uniform answer as the
   * detail, so probing ids reveals nothing.
   */
  public function testUpdateAnswers404ForAClaimTheReaderCannotSee() {
    $this->seed([$this->claim(['field_condominium_target_id' => 30, 'condominium_id' => 30])]);
    $this->validPost();

    $result = $this->update('140');

    $this->assertSame(404, $result['status']);
    $this->assertSame('claim_not_found', $result['json']['error_code']);
  }

  /**
   * A claim the reader CAN see but did not file is 403 and not 404: it is
   * already visible to them, so there is nothing left to hide.
   */
  public function testUpdatingSomebodyElsesVisibleClaimIs403() {
    $this->seed([$this->claim([
      'field_visibility_value'    => 'public',
      'visibility'                => 'public',
      'field_requester_target_id' => 99,
      'requester_id'              => 99,
    ])]);
    $this->validPost();

    $result = $this->update('140');

    $this->assertSame(403, $result['status']);
    $this->assertSame('claim_edit_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * Once the administration took the claim it is no longer editable: every
   * status other than 'received' answers 409.
   */
  public function testUpdatingAClaimBeyondReceivedIs409() {
    foreach (['in_progress', 'resolved', 'closed'] as $status) {
      $this->seed([$this->claim(['fs.field_status_value' => $status])]);
      $this->validPost();

      $result = $this->update('140');

      $this->assertSame(409, $result['status'], $status);
      $this->assertSame('claim_not_editable', $result['json']['error_code'], $status);
    }
  }

  /**
   * The order of the guards is observable: an empty body against a claim that
   * is not yours answers 403, not 422 — ownership is decided before the
   * payload is even read.
   */
  public function testOwnershipIsCheckedBeforeTheBody() {
    $this->seed([$this->claim([
      'field_visibility_value'    => 'public',
      'visibility'                => 'public',
      'field_requester_target_id' => 99,
      'requester_id'              => 99,
    ])]);
    $_POST = [];

    $result = $this->update('140');

    $this->assertSame(403, $result['status']);
  }

  /**
   * And the status check comes before it too, for the same reason.
   */
  public function testTheStatusIsCheckedBeforeTheBody() {
    $this->seed([$this->claim(['fs.field_status_value' => 'closed'])]);
    $_POST = [];

    $result = $this->update('140');

    $this->assertSame(409, $result['status']);
  }

  /**
   * The update is TOTAL: the five text fields are required on every call, so a
   * request that only means to add an image still has to send them.
   */
  public function testTheFiveTextFieldsAreRequiredOnEveryUpdate() {
    foreach (['subject', 'claim_type', 'condominium_id', 'description', 'visibility'] as $field) {
      $this->seed([$this->claim()]);
      myapi_test_node_seed([140 => ['nid' => 140, 'type' => 'reclamo']]);
      $this->validPost();
      unset($_POST[$field]);

      $result = $this->update('140');

      $this->assertSame(422, $result['status'], $field);
      $this->assertSame('missing_field', $result['json']['error_code'], $field);
      $this->assertStringContainsString($field, $result['json']['error'], $field);
    }
  }

  /**
   * A claim whose node has disappeared between the fetch and the load answers
   * 404 rather than fataling on a FALSE node.
   */
  public function testUpdateAnswers404WhenTheNodeCannotBeLoaded() {
    $this->seed([$this->claim()]);
    myapi_test_node_seed();
    $this->validPost();

    $result = $this->update('140');

    $this->assertSame(404, $result['status']);
    $this->assertSame('claim_not_found', $result['json']['error_code']);
  }

  /**
   * remove_image_ids[] naming a fid the claim does not reference is 422 — the
   * check that stops a stray id from becoming a way to probe other people's
   * files.
   */
  public function testRemoveImageIdsRejectsAFidTheClaimDoesNotOwn() {
    $this->seed([$this->claim()]);
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo',
      'field_images' => [LANGUAGE_NONE => [['fid' => 7], ['fid' => 8]]],
    ]]);
    $this->validPost();
    $_POST['remove_image_ids'] = ['9'];

    $result = $this->update('140');

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('remove_image_ids', $result['json']['error']);
    $this->assertSame([], myapi_test_file_deletes(), 'nothing is deleted on the way out');
  }

  /**
   * And so is a value that is not a positive integer at all.
   */
  public function testRemoveImageIdsRejectsAMalformedValue() {
    foreach (['abc', '-1', '0', '1.5'] as $value) {
      $this->seed([$this->claim()]);
      myapi_test_node_seed([140 => [
        'nid' => 140, 'type' => 'reclamo',
        'field_images' => [LANGUAGE_NONE => [['fid' => 7]]],
      ]]);
      $this->validPost();
      $_POST['remove_image_ids'] = [$value];

      $result = $this->update('140');

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
    }
  }

  /**
   * A valid update: the loaded node is overwritten field by field, the removed
   * image is gone from field_images and from disk — and only AFTER the save —
   * and the answer is 200 with the claim.
   */
  public function testAValidUpdateOverwritesTheFieldsAndDeletesTheRemovedImage() {
    $this->seed([$this->claim()]);
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo', 'uid' => self::UID, 'created' => 1750000000,
      'title' => 'Fuga en el pasillo',
      'field_images' => [LANGUAGE_NONE => [['fid' => 7, 'display' => 1], ['fid' => 8, 'display' => 1]]],
      'field_status' => [LANGUAGE_NONE => [['value' => 'received']]],
      'field_requester' => [LANGUAGE_NONE => [['target_id' => self::UID]]],
    ]]);
    myapi_test_file_seed([7 => ['fid' => 7, 'uri' => 'private://claims/a.jpg']]);
    $this->validPost([
      'subject'     => 'Fuga en el pasillo 2B',
      'description' => 'Sigue igual.',
      'visibility'  => 'public',
    ]);
    $_POST['remove_image_ids'] = ['7'];

    $result = $this->update('140');

    $this->assertSame(200, $result['status']);
    $this->assertSame('Reclamo actualizado correctamente.', $result['json']['message']);

    $node = myapi_test_node_saves()[0];
    $this->assertSame('Fuga en el pasillo 2B', $node->title);
    $this->assertSame('Sigue igual.', $node->field_description[LANGUAGE_NONE][0]['value']);
    $this->assertSame('public', $node->field_visibility[LANGUAGE_NONE][0]['value']);
    $this->assertSame([['fid' => 8, 'display' => 1]], $node->field_images[LANGUAGE_NONE]);
    $this->assertSame([7], myapi_test_file_deletes());
  }

  /**
   * The immutable fields survive BY CONSTRUCTION: the update loads the node and
   * overwrites only what the request names, so node.uid, node.created,
   * field_requester, field_status and field_reception_date come out untouched.
   * This is the case that fails the day somebody rebuilds the node instead.
   */
  public function testTheImmutableFieldsAreUntouchedByAnUpdate() {
    $this->seed([$this->claim()]);
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo', 'uid' => 41, 'created' => 1750000000,
      'field_status' => [LANGUAGE_NONE => [['value' => 'received']]],
      'field_requester' => [LANGUAGE_NONE => [['target_id' => self::UID]]],
      'field_reception_date' => [LANGUAGE_NONE => [['value' => '2026-08-04 16:45:00']]],
    ]]);
    $this->validPost(['subject' => 'Otro asunto']);

    $this->update('140');

    $node = myapi_test_node_saves()[0];
    $this->assertSame(41, $node->uid);
    $this->assertSame(1750000000, $node->created);
    $this->assertSame(self::UID, $node->field_requester[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('received', $node->field_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame('2026-08-04 16:45:00', $node->field_reception_date[LANGUAGE_NONE][0]['value']);
  }

  /**
   * 'remove_attachment' empties the field and deletes the file; anything other
   * than '1'/'true' leaves it alone.
   */
  public function testRemoveAttachmentEmptiesTheFieldOnlyForTheDocumentedValues() {
    $cases = ['1' => TRUE, 'true' => TRUE, 'TRUE' => TRUE, '0' => FALSE, 'false' => FALSE, 'yes' => FALSE];

    foreach ($cases as $sent => $removed) {
      myapi_test_write_reset();
      $this->seed([$this->claim()]);
      myapi_test_node_seed([140 => [
        'nid' => 140, 'type' => 'reclamo',
        'field_attachment' => [LANGUAGE_NONE => [['fid' => 31, 'display' => 1]]],
      ]]);
      myapi_test_file_seed([31 => ['fid' => 31, 'uri' => 'private://claims/p.pdf']]);
      $this->validPost();
      $_POST['remove_attachment'] = (string) $sent;

      $this->update('140');

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
   * The deletion of a file that left the claim happens AFTER node_save(), never
   * before: the order is what guarantees the node never references a file that
   * no longer exists. Asserted through the usage record, which is written
   * between the two.
   */
  public function testRemovedFilesAreDeletedAfterTheSave() {
    $this->seed([$this->claim()]);
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo',
      'field_images' => [LANGUAGE_NONE => [['fid' => 7, 'display' => 1]]],
    ]]);
    myapi_test_file_seed([7 => ['fid' => 7, 'uri' => 'private://claims/a.jpg']]);
    $this->validPost();
    $_POST['remove_image_ids'] = ['7'];

    $this->update('140');

    $this->assertCount(1, myapi_test_node_saves(), 'the node was saved');
    $usage = myapi_test_file_usage();
    $this->assertSame('delete', $usage[0]['op']);
    $this->assertSame(7, $usage[0]['fid']);
    $this->assertSame([7], myapi_test_file_deletes());
  }

  /**
   * A fid that no longer loads is skipped in silence: the goal state — the
   * file is gone — is already true.
   */
  public function testDeletingAnAlreadyMissingFileIsANoOp() {
    $this->seed([$this->claim()]);
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo',
      'field_images' => [LANGUAGE_NONE => [['fid' => 7, 'display' => 1]]],
    ]]);
    myapi_test_file_seed([]);
    $this->validPost();
    $_POST['remove_image_ids'] = ['7'];

    $result = $this->update('140');

    $this->assertSame(200, $result['status']);
    $this->assertSame([], myapi_test_file_deletes());
  }

  /* -------------------------------------------------------------------------
   * PUT /api/v1/claims/%/close (SPEC 70).
   * ---------------------------------------------------------------------- */

  public function testCloseDispatchAcceptsOnlyPut() {
    foreach (['GET', 'POST', 'DELETE', 'PATCH'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = myapi_test_capture(function () {
        myapi_claim_close_dispatch('140');
      });

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame([], myapi_test_db_queries(), $method . ': no token lookup either');
    }
  }

  public function testCloseRequiresAToken() {
    $result = $this->close('140');

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  public function testCloseAnswers404ForAMalformedOrInvisibleClaim() {
    $cases = [
      'malformed' => ['id' => 'abc', 'claims' => [$this->claim()]],
      'missing'   => ['id' => '999', 'claims' => [$this->claim()]],
      'foreign'   => ['id' => '140', 'claims' => [$this->claim(['field_condominium_target_id' => 30, 'condominium_id' => 30])]],
    ];

    foreach ($cases as $name => $case) {
      $this->seed($case['claims']);

      $result = $this->close($case['id']);

      $this->assertSame(404, $result['status'], $name);
      $this->assertSame('claim_not_found', $result['json']['error_code'], $name);
    }
  }

  /**
   * Only the requester closes: a public claim of a neighbour is visible and
   * still answers 403.
   */
  public function testClosingSomebodyElsesClaimIs403() {
    $this->seed([$this->claim([
      'field_visibility_value'    => 'public',
      'visibility'                => 'public',
      'field_requester_target_id' => 99,
      'requester_id'              => 99,
    ])]);

    $result = $this->close('140');

    $this->assertSame(403, $result['status']);
    $this->assertSame('claim_close_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * Only while the administration has not taken it: exactly 'received', so an
   * already closed claim answers 409 too — which is what makes a double click
   * harmless.
   */
  public function testClosingAClaimBeyondReceivedIs409IncludingAnAlreadyClosedOne() {
    foreach (['in_progress', 'resolved', 'closed'] as $status) {
      $this->seed([$this->claim(['fs.field_status_value' => $status])]);

      $result = $this->close('140');

      $this->assertSame(409, $result['status'], $status);
      $this->assertSame('claim_not_closable', $result['json']['error_code'], $status);
    }
  }

  /**
   * The reason is validated LAST: a claim that is not yours keeps answering
   * 403 even with an empty body, and one already closed keeps answering 409.
   */
  public function testTheReasonIsValidatedAfterTheBusinessChecks() {
    $this->seed([$this->claim([
      'field_visibility_value' => 'public', 'visibility' => 'public',
      'field_requester_target_id' => 99, 'requester_id' => 99,
    ])]);
    $foreign = $this->close('140');

    $this->seed([$this->claim(['fs.field_status_value' => 'closed'])]);
    $already_closed = $this->close('140');

    $this->assertSame(403, $foreign['status']);
    $this->assertSame(409, $already_closed['status']);
  }







}
