<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.node_files.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.service_offer.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_detail.inc';
require_once __DIR__ . '/../../includes/myapi.provider_card.inc';
require_once __DIR__ . '/../../includes/myapi.provider_query.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_notification.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * Unit tests for POST /api/v1/service-requests (SPEC 90).
 *
 * Same split as ClaimWriteGuardsTest, which this suite deliberately mirrors:
 *
 * WHAT RUNS FOR REAL: the token middleware, every validator in the order the
 * spec fixes, myapi_service_request_build_node(), and the whole re-fetch that
 * builds the 201 body — myapi_service_request_detail_row(),
 * myapi_user_display_names(), myapi_service_request_load_images() and
 * myapi_service_request_build_detail(), the very same functions
 * GET /api/v1/service-requests/% uses.
 *
 * WHAT DOES NOT: node_save() is a recorder (see bootstrap.php), so a green case
 * says "the resource asked for this node", never "Drupal stored it". Real file
 * uploads are out entirely, the same boundary ClaimWriteGuardsTest draws: every
 * case here sends either no file or a FAKE one whose shape is enough to trip
 * myapi_node_files_save()'s own count guard (which fires BEFORE it ever touches
 * field_info_field() or file_save_upload()) — actual extension/size/MIME
 * validation needs the real Field API and belongs to tests/integration.
 *
 * THE PRE-SEEDED NODE ROW IS WHAT MAKES THE 201 BODY ASSERTABLE. node_save()
 * assigns nid 900 by default (bootstrap.php's own note), so every happy-path
 * case seeds a 'node' row under that nid, shaped exactly as
 * myapi_service_request_detail_row()'s joins would deliver it — the same
 * fixture convention ServiceRequestDetailEndpointTest and
 * ServiceRequestListEndpointTest already establish: qualified keys
 * ('frs.field_request_status_value', 'fcat.field_category_tid') where a bare
 * alias would collide with a column of `node`, bare aliases everywhere else.
 * A provider with more than one category is likewise simulated as more than
 * one 'node' row sharing the same nid — the fixture query builder never
 * resolves a join, so that is what a real one-row-per-category LEFT JOIN
 * becomes here.
 */
class ServiceRequestCreateEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';
  const UID = 42;
  const UNIT = 55;
  const CONDO = 7;
  const CATEGORY = 12;
  const OTHER_CATEGORY = 13;
  const PROVIDER = 501;

  /**
   * The nid node_save() assigns by default (bootstrap.php's own note) — every
   * happy-path fixture seeds its request row under this nid.
   */
  const NID = 900;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    $_FILES = [];
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    myapi_test_db_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $_POST = [];
    $_FILES = [];
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

  private function userRow($uid, $name, $first = NULL, $last = NULL) {
    return [
      'uid'        => (string) $uid,
      'name'       => $name,
      'first_name' => $first,
      'last_name'  => $last,
    ];
  }

  /**
   * The request row as myapi_service_request_detail_row()'s joins would
   * deliver it, under NID — what the re-fetch after node_save() finds. Shaped
   * for the MINIMAL valid payload: 'open', no award, no files.
   */
  private function requestRow(array $overrides = []) {
    return $overrides + [
      'nid'                             => (string) self::NID,
      'type'                            => MYAPI_SERVICES_REQUEST_TYPE,
      // The node's published flag. The request's own status is the qualified
      // key below — a flat row cannot carry 'status' twice.
      'status'                          => '1',
      'title'                           => 'Fuga en el calentador',
      'created'                         => (string) REQUEST_TIME,
      'fr.field_requester_target_id'    => (string) self::UID,
      'requester_uid'                   => (string) self::UID,
      'fcat.field_category_tid'         => (string) self::CATEGORY,
      'category_code'                   => 'plumbing',
      'category_name'                   => 'Plomería',
      'frs.field_request_status_value'  => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'description'                     => 'El calentador del baño principal gotea desde el lunes.',
      'desired_start'                   => (string) (REQUEST_TIME + 3600),
      'closed_at'                       => NULL,
      'unit_id'                         => (string) self::UNIT,
      'unit_name'                       => 'A-301',
      'condominium_id'                  => (string) self::CONDO,
      'condominium_name'                => 'Torres del Este',
      'attachment_fid'                  => NULL,
      'attachment_filename'             => NULL,
      'assigned_offer_id'               => NULL,
      'assigned_offer_status'           => NULL,
      'assigned_provider_id'            => NULL,
      'assigned_provider_name'          => NULL,
      'assigned_offer_raw'              => NULL,
      'assigned_provider_raw'           => NULL,
    ];
  }

  /**
   * One 'service_transaction' of the created request, as the timeline query
   * delivers it (SPEC 93).
   *
   * Seeded into the `node` table, which is that query's base table, next to the
   * request itself and told apart from it by `type` — the very condition under
   * test. node_save() is a recorder here, so hook_node_insert() writes nothing:
   * the entry SPEC 92 would have created is seeded by hand.
   */
  private function transactionRow($nid, $status_date, array $overrides = []) {
    return $overrides + [
      'n.nid'                          => (string) $nid,
      'nid'                            => (string) $nid,
      'type'                           => MYAPI_SERVICES_TRANSACTION_TYPE,
      // The node's published flag, not the transaction's status.
      'status'                         => '1',
      'created'                        => (string) REQUEST_TIME,
      'fr.field_request_target_id'     => (string) self::NID,
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'status_date'                    => $status_date,
      'comment'                        => 'Hemos recibido su solicitud.',
    ];
  }

  /**
   * A 'provider' node, one 'node' fixture row per category — the fixture
   * engine never resolves a join, so this is what a real one-row-per-category
   * LEFT JOIN to field_categories becomes here (see the class docblock).
   */
  private function providerRows($nid, array $categories, $status = '1', $expiry = NULL) {
    $expiry = $expiry === NULL ? (string) (REQUEST_TIME + 86400) : $expiry;

    // `title` is what the CARD of SPEC 89 reads: a 'direct' request is born
    // awarded, so the 201 answers this provider's card and the fixture has to
    // carry the node column that card is built from.
    if (empty($categories)) {
      return [[
        'nid' => (string) $nid, 'type' => MYAPI_SERVICES_PROVIDER_TYPE,
        'title' => 'Plomería Rivas',
        'status' => $status, 'license_expiry' => $expiry, 'category_tid' => NULL,
      ]];
    }

    return array_map(function ($tid) use ($nid, $status, $expiry) {
      return [
        'nid' => (string) $nid, 'type' => MYAPI_SERVICES_PROVIDER_TYPE,
        'title' => 'Plomería Rivas',
        'status' => $status, 'license_expiry' => $expiry, 'category_tid' => (string) $tid,
      ];
    }, $categories);
  }

  /**
   * Seeds a full scenario: the resident's unit/condominium, the token, and
   * whatever extra 'node' rows the case needs (a pre-seeded request row for a
   * happy path, provider rows for the direct-award cases). The vocabulary
   * carries two of its own terms plus one of an unrelated vocabulary, so
   * "wrong vocabulary" cases need nothing extra.
   */
  private function seed(array $extra_node_rows = []) {
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user' . self::UID, 'status' => 1];

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'users'         => [$this->userRow(self::UID, 'aperez', 'Ana', 'Pérez')],
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0'],
      ],
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDO, 'deleted' => '0'],
      ],
      'node' => $extra_node_rows,
    ]);
    myapi_test_write_reset();
    myapi_test_static_reset();

    myapi_test_taxonomy_seed([
      MYAPI_SERVICES_CATEGORY_VOCABULARY => [
        ['tid' => self::CATEGORY, 'name' => 'Plomería'],
        ['tid' => self::OTHER_CATEGORY, 'name' => 'Electricidad'],
      ],
      'other_vocabulary' => [
        ['tid' => 999, 'name' => 'Ajeno'],
      ],
    ]);
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  private function validPost(array $overrides = []) {
    $future = date('Y-m-d H:i', REQUEST_TIME + 3600);
    $_POST = $overrides + [
      'title'         => 'Fuga en el calentador',
      'unit_id'       => (string) self::UNIT,
      'category_id'   => (string) self::CATEGORY,
      'description'   => 'El calentador del baño principal gotea desde el lunes.',
      'desired_start' => $future,
    ];
  }

  private function create() {
    return myapi_test_capture('myapi_service_request_create');
  }

  private function dispatch() {
    return myapi_test_capture('myapi_service_request_dispatch');
  }

  /**
   * A raw $_FILES['images'] shape with $count fake files, enough to trip
   * myapi_node_files_save()'s COUNT guard — which fires before it ever touches
   * field_info_field() or file_save_upload(), so no real Field API is needed.
   */
  private function fakeFiles($count) {
    return [
      'name'     => array_fill(0, $count, 'foto.jpg'),
      'type'     => array_fill(0, $count, 'image/jpeg'),
      'tmp_name' => array_fill(0, $count, '/tmp/fake'),
      'error'    => array_fill(0, $count, 0),
      'size'     => array_fill(0, $count, 1024),
    ];
  }

  private function queriedTables() {
    return array_column(myapi_test_db_queries(), 'table');
  }

  /* -------------------------------------------------------------------------
   * The three pure helpers (step 4), against fixture rows, no HTTP involved.
   * ---------------------------------------------------------------------- */

  public function testValidCategoryAcceptsARealTermOfTheVocabulary() {
    myapi_test_taxonomy_seed([
      MYAPI_SERVICES_CATEGORY_VOCABULARY => [['tid' => self::CATEGORY, 'name' => 'Plomería']],
    ]);

    $this->assertTrue(myapi_service_request_valid_category(self::CATEGORY));
  }

  public function testValidCategoryRejectsATermOfAnotherVocabulary() {
    myapi_test_taxonomy_seed([
      MYAPI_SERVICES_CATEGORY_VOCABULARY => [['tid' => self::CATEGORY, 'name' => 'Plomería']],
      'other_vocabulary' => [['tid' => 999, 'name' => 'Ajeno']],
    ]);

    $this->assertFalse(myapi_service_request_valid_category(999));
  }

  public function testValidCategoryRejectsANonexistentTid() {
    myapi_test_taxonomy_seed([
      MYAPI_SERVICES_CATEGORY_VOCABULARY => [['tid' => self::CATEGORY, 'name' => 'Plomería']],
    ]);

    $this->assertFalse(myapi_service_request_valid_category(123456));
  }

  public function testValidCategoryRejectsNonPositiveValues() {
    foreach ([0, -5] as $value) {
      $this->assertFalse(myapi_service_request_valid_category($value), var_export($value, TRUE));
    }
  }

  public function testParseDesiredStartAcceptsAFutureInstant() {
    $future = date('Y-m-d H:i', REQUEST_TIME + 3600);

    $this->assertSame(strtotime($future), myapi_service_request_parse_desired_start($future));
  }

  public function testParseDesiredStartRejectsThePast() {
    $past = date('Y-m-d H:i', REQUEST_TIME - 3600);

    $this->assertFalse(myapi_service_request_parse_desired_start($past));
  }

  public function testParseDesiredStartRejectsTheExactCurrentInstant() {
    $now = date('Y-m-d H:i:s', REQUEST_TIME);

    $this->assertFalse(myapi_service_request_parse_desired_start($now));
  }

  public function testParseDesiredStartRejectsAnUnparseableString() {
    foreach (['not-a-date', '', '2026-13-40'] as $value) {
      $this->assertFalse(myapi_service_request_parse_desired_start($value), var_export($value, TRUE));
    }
  }

  public function testValidateProviderRequiresARealNode() {
    myapi_test_db_seed(['node' => []]);

    $this->assertFalse(myapi_service_request_validate_provider(999999, self::CATEGORY));
  }

  public function testValidateProviderRequiresTheProviderBundle() {
    myapi_test_db_seed(['node' => [
      ['nid' => (string) self::PROVIDER, 'type' => 'vivienda', 'status' => '1', 'license_expiry' => (string) (REQUEST_TIME + 3600), 'category_tid' => (string) self::CATEGORY],
    ]]);

    $this->assertFalse(myapi_service_request_validate_provider(self::PROVIDER, self::CATEGORY));
  }

  public function testValidateProviderRequiresPublished() {
    myapi_test_db_seed(['node' => $this->providerRows(self::PROVIDER, [self::CATEGORY], '0')]);

    $this->assertFalse(myapi_service_request_validate_provider(self::PROVIDER, self::CATEGORY));
  }

  public function testValidateProviderRequiresAnUnexpiredLicense() {
    myapi_test_db_seed(['node' => $this->providerRows(self::PROVIDER, [self::CATEGORY], '1', (string) (REQUEST_TIME - 1))]);

    $this->assertFalse(myapi_service_request_validate_provider(self::PROVIDER, self::CATEGORY));
  }

  public function testValidateProviderRequiresTheCategory() {
    myapi_test_db_seed(['node' => $this->providerRows(self::PROVIDER, [self::OTHER_CATEGORY])]);

    $this->assertFalse(myapi_service_request_validate_provider(self::PROVIDER, self::CATEGORY));
  }

  public function testValidateProviderWithNoCategoriesAtAllIsNotEligible() {
    myapi_test_db_seed(['node' => $this->providerRows(self::PROVIDER, [])]);

    $this->assertFalse(myapi_service_request_validate_provider(self::PROVIDER, self::CATEGORY));
  }

  public function testValidateProviderAcceptsAnEligibleProvider() {
    myapi_test_db_seed(['node' => $this->providerRows(self::PROVIDER, [self::OTHER_CATEGORY, self::CATEGORY])]);

    $this->assertTrue(myapi_service_request_validate_provider(self::PROVIDER, self::CATEGORY));
  }

  /* -------------------------------------------------------------------------
   * Non-regression guard: myapi_node_files_save() / myapi_node_files_delete()
   * answer exactly like the functions they replaced (step 1/2), exercised from
   * BOTH consumers.
   * ---------------------------------------------------------------------- */

  public function testNodeFilesSaveReturnsEmptyForBothConsumersWhenNothingIsSent() {
    $this->assertSame([], myapi_node_files_save('field_images', 'reclamo', [], 5, 'claim_invalid_image', 'claim_too_many_images'));
    $this->assertSame([], myapi_node_files_save('field_images', MYAPI_SERVICES_REQUEST_TYPE, [], 5, 'service_request_invalid_image', 'service_request_too_many_images'));
  }

  /**
   * The one behaviour the extraction changed on purpose (a decision the user
   * confirmed): the "too many files" branch used to hardcode
   * 'claim_too_many_images'. It is now the $too_many_key parameter, so each
   * consumer answers its OWN catalogue key for the identical condition.
   */
  public function testNodeFilesSaveAnswersEachCallersOwnTooManyKey() {
    $files = $this->fakeFiles(6);

    $claimResult = myapi_test_capture(function () use ($files) {
      myapi_node_files_save('field_images', 'reclamo', $files, 5, 'claim_invalid_image', 'claim_too_many_images');
    });
    $this->assertSame(422, $claimResult['status']);
    $this->assertSame('claim_too_many_images', $claimResult['json']['error_code']);

    $serviceRequestResult = myapi_test_capture(function () use ($files) {
      myapi_node_files_save('field_images', MYAPI_SERVICES_REQUEST_TYPE, $files, 5, 'service_request_invalid_image', 'service_request_too_many_images');
    });
    $this->assertSame(422, $serviceRequestResult['status']);
    $this->assertSame('service_request_too_many_images', $serviceRequestResult['json']['error_code']);
  }

  public function testNodeFilesDeleteToleratesAnEmptyBatch() {
    myapi_node_files_delete([]);

    $this->assertSame([], myapi_test_file_deletes());
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — authentication and routing.
   * ---------------------------------------------------------------------- */

  public function testCreateRequiresATokenBeforeAnythingElse() {
    $this->seed();
    $this->validPost();

    $result = $this->create();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  public function testAnInvalidTokenIs401() {
    $this->seed();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';
    $this->validPost();

    $result = $this->create();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /**
   * The dispatcher itself: POST routes to creation, wired the way hook_menu()
   * calls it.
   */
  public function testTheDispatcherRoutesPostToCreation() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->dispatch();

    $this->assertSame(201, $result['status']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — required fields.
   * ---------------------------------------------------------------------- */

  public function testEachMissingFieldIsNamedInIts422() {
    foreach (['title', 'unit_id', 'category_id', 'description', 'desired_start'] as $field) {
      $this->seed();
      $this->authenticate();
      $this->validPost();
      unset($_POST[$field]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], $field);
      $this->assertSame('missing_field', $result['json']['error_code'], $field);
      $this->assertStringContainsString($field, $result['json']['error'], $field);
      $this->assertSame([], myapi_test_node_saves(), $field);
    }
  }

  public function testAnEmptyOrWhitespaceTitleCountsAsMissing() {
    foreach (['', '   ', "\n\t"] as $value) {
      $this->seed();
      $this->authenticate();
      $this->validPost(['title' => $value]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], var_export($value, TRUE));
      $this->assertSame('missing_field', $result['json']['error_code'], var_export($value, TRUE));
    }
  }

  public function testTheRequiredFieldsAreCheckedInTheDocumentedOrder() {
    $this->seed();
    $this->authenticate();
    $_POST = [];

    $result = $this->create();

    $this->assertStringContainsString('title', $result['json']['error']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — title.
   * ---------------------------------------------------------------------- */

  public function testTitleIsBoundedAt255Chars() {
    $this->seed([$this->requestRow(['title' => str_repeat('a', 255)])]);
    $this->authenticate();
    $this->validPost(['title' => str_repeat('a', 255)]);
    $accepted = $this->create();

    $this->seed();
    $this->authenticate();
    $this->validPost(['title' => str_repeat('a', 256)]);
    $rejected = $this->create();

    $this->assertSame(201, $accepted['status'], '255 is inside the column');
    $this->assertSame(422, $rejected['status']);
    $this->assertSame('invalid_field', $rejected['json']['error_code']);
    $this->assertStringContainsString('title', $rejected['json']['error']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — unit_id and the derived condominium.
   * ---------------------------------------------------------------------- */

  public function testAMalformedUnitIdIs422() {
    foreach (['abc', '0', '-3', '1.5'] as $value) {
      $this->seed();
      $this->authenticate();
      $this->validPost(['unit_id' => $value]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
      $this->assertStringContainsString('unit_id', $result['json']['error'], $value);
    }
  }

  public function testAForeignUnitIdIs403() {
    $this->seed();
    $this->authenticate();
    $this->validPost(['unit_id' => '99999']);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('unit_access_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * A resident with no unit at all has an empty related-units set, so EVERY
   * unit_id falls outside it and answers the same 403 — never 422, never 200.
   */
  public function testAResidentWithoutAnyUnitGetsTheSame403() {
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user' . self::UID, 'status' => 1];
    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('unit_access_denied', $result['json']['error_code']);
  }

  /**
   * condominium_id is not a field of this request: sending one has no effect
   * whatsoever, because the endpoint never reads that key at all. The
   * condominium the node ends up with is the one DERIVED from unit_id.
   */
  public function testTheCondominiumIsDerivedAndAClientSentOneIsIgnored() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost(['condominium_id' => '999999']);

    $this->create();

    $node = myapi_test_node_saves()[0];
    $this->assertSame(self::CONDO, $node->field_condominium[LANGUAGE_NONE][0]['target_id']);
  }

  /**
   * A unit with no field_condominio row at all (a data inconsistency nothing
   * today prevents) has no condominium to derive: the endpoint answers 500 and
   * logs the case, instead of creating a request with no condominium.
   */
  public function testAUnitWithNoCondominiumRowIsA500AndLogged() {
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user' . self::UID, 'status' => 1];
    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0'],
      ],
      // No field_data_field_condominio row for this unit at all.
    ]);
    myapi_test_taxonomy_seed([
      MYAPI_SERVICES_CATEGORY_VOCABULARY => [['tid' => self::CATEGORY, 'name' => 'Plomería']],
    ]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $this->assertSame(500, $result['status']);
    $this->assertSame('server_error', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
    $this->assertCount(1, $GLOBALS['myapi_test_watchdog']);
    $this->assertSame(WATCHDOG_ERROR, $GLOBALS['myapi_test_watchdog'][0]['severity']);
    $this->assertStringContainsString((string) self::UNIT, $GLOBALS['myapi_test_watchdog'][0]['text']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — category_id.
   * ---------------------------------------------------------------------- */

  public function testAMalformedCategoryIdIs422() {
    foreach (['abc', '0', '-3', '1.5'] as $value) {
      $this->seed();
      $this->authenticate();
      $this->validPost(['category_id' => $value]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
      $this->assertStringContainsString('category_id', $result['json']['error'], $value);
    }
  }

  public function testATidOfAnotherVocabularyIs422() {
    $this->seed();
    $this->authenticate();
    $this->validPost(['category_id' => '999']);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('category_id', $result['json']['error']);
    $this->assertSame([], myapi_test_node_saves());
  }

  public function testANonexistentTidIs422() {
    $this->seed();
    $this->authenticate();
    $this->validPost(['category_id' => '123456']);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — description.
   * ---------------------------------------------------------------------- */

  public function testDescriptionMustBeNonEmptyAfterTrim() {
    foreach (['', '   ', "\n\t"] as $value) {
      $this->seed();
      $this->authenticate();
      $this->validPost(['description' => $value]);

      $result = $this->create();

      // myapi_request_post_field() already trims, so whitespace collapses to
      // '' and is caught by the missing-field check, same as title.
      $this->assertSame(422, $result['status'], var_export($value, TRUE));
      $this->assertSame('missing_field', $result['json']['error_code'], var_export($value, TRUE));
    }
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — desired_start.
   * ---------------------------------------------------------------------- */

  public function testAnUnparseableDesiredStartIs422() {
    $this->seed();
    $this->authenticate();
    $this->validPost(['desired_start' => 'not-a-date']);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('desired_start', $result['json']['error']);
  }

  public function testAPastDesiredStartIs422() {
    $this->seed();
    $this->authenticate();
    $this->validPost(['desired_start' => date('Y-m-d H:i', REQUEST_TIME - 3600)]);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
  }

  public function testTheExactCurrentInstantIs422() {
    $this->seed();
    $this->authenticate();
    $this->validPost(['desired_start' => date('Y-m-d H:i:s', REQUEST_TIME)]);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
  }

  public function testAFutureDesiredStartIsAcceptedAndFormatted() {
    $timestamp = REQUEST_TIME + 3600;
    $this->seed([$this->requestRow(['desired_start' => (string) $timestamp])]);
    $this->authenticate();
    $this->validPost(['desired_start' => date('Y-m-d H:i', $timestamp)]);

    $result = $this->create();

    $this->assertSame(201, $result['status']);
    $this->assertMatchesRegularExpression(
      '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
      $result['json']['data']['service_request']['desired_start']
    );
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — assigned_provider_id and 'direct'.
   * ---------------------------------------------------------------------- */

  public function testAMalformedProviderIdIs422() {
    foreach (['abc', '0', '-3', '1.5'] as $value) {
      $this->seed();
      $this->authenticate();
      $this->validPost(['assigned_provider_id' => $value]);

      $result = $this->create();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
      $this->assertStringContainsString('assigned_provider_id', $result['json']['error'], $value);
    }
  }

  public function testANonexistentProviderIdIs403ProviderNotEligible() {
    $this->seed();
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => '999999']);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_not_eligible', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  public function testAProviderIdOfAnotherBundleIs403ProviderNotEligible() {
    $this->seed([['nid' => (string) self::PROVIDER, 'type' => 'vivienda', 'status' => '1']]);
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => (string) self::PROVIDER]);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_not_eligible', $result['json']['error_code']);
  }

  public function testAnUnpublishedProviderIs403ProviderNotEligible() {
    $this->seed($this->providerRows(self::PROVIDER, [self::CATEGORY], '0'));
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => (string) self::PROVIDER]);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_not_eligible', $result['json']['error_code']);
  }

  public function testAProviderWithAnExpiredLicenseIs403ProviderNotEligible() {
    $this->seed($this->providerRows(self::PROVIDER, [self::CATEGORY], '1', (string) (REQUEST_TIME - 1)));
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => (string) self::PROVIDER]);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_not_eligible', $result['json']['error_code']);
  }

  public function testAProviderOfAnotherCategoryIs403ProviderNotEligible() {
    $this->seed($this->providerRows(self::PROVIDER, [self::OTHER_CATEGORY]));
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => (string) self::PROVIDER]);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_not_eligible', $result['json']['error_code']);
  }

  /**
   * The provider is validated BEFORE any file touches the filesystem: an
   * ineligible provider answers 403 even when the request also carries 6
   * images that would otherwise be rejected at step 9 — proving the ORDER,
   * not just the individual outcomes.
   */
  public function testProviderValidationHappensBeforeAnyFileIsTouched() {
    $this->seed($this->providerRows(self::PROVIDER, [self::OTHER_CATEGORY]));
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => (string) self::PROVIDER]);
    $_FILES['images'] = $this->fakeFiles(6);

    $result = $this->create();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_not_eligible', $result['json']['error_code']);
  }

  public function testAnEligibleProviderCreatesADirectRequest() {
    $requestRow = $this->requestRow([
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      'assigned_provider_id'           => (string) self::PROVIDER,
      'assigned_provider_name'         => 'Plomería Rivas',
      'assigned_provider_raw'          => (string) self::PROVIDER,
    ]);
    $this->seed(array_merge([$requestRow], $this->providerRows(self::PROVIDER, [self::CATEGORY])));
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => (string) self::PROVIDER]);

    $result = $this->create();

    $this->assertSame(201, $result['status']);
    $sr = $result['json']['data']['service_request'];
    $this->assertSame('direct', $sr['status']);
    // THE WHOLE CARD, not a name (SPEC 89): the same eight keys
    // GET /api/v1/providers answers, so the company travels with its logo, its
    // categories and its rating and the app paints it with the widget it
    // already has. `title` IS the name here — the card's own key.
    $this->assertSame(
      ['id', 'logo', 'title', 'categories', 'rating_avg', 'rating_count', 'short_description', 'hourly_rate'],
      array_keys($sr['assigned_provider'])
    );
    $this->assertSame(self::PROVIDER, $sr['assigned_provider']['id']);
    $this->assertSame('Plomería Rivas', $sr['assigned_provider']['title']);
    // A direct award never adjudicates an offer.
    $this->assertNull($sr['assigned_offer']);

    $node = myapi_test_node_saves()[0];
    $this->assertSame(MYAPI_SERVICES_REQUEST_STATUS_DIRECT, $node->field_request_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame(self::PROVIDER, $node->field_assigned_provider[LANGUAGE_NONE][0]['target_id']);
  }

  public function testWithoutAssignedProviderIdTheRequestIsOpen() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $sr = $result['json']['data']['service_request'];
    $this->assertSame('open', $sr['status']);
    $this->assertNull($sr['assigned_provider']);
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — images[] (count guard only, see the class
   * docblock for why extension/size/MIME stay out of tests/unit).
   * ---------------------------------------------------------------------- */

  public function testSixImagesAnswer422TooManyImagesAndCreatesNoNode() {
    $this->seed();
    $this->authenticate();
    $this->validPost();
    $_FILES['images'] = $this->fakeFiles(6);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('service_request_too_many_images', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * MORE THAN ONE ATTACHMENT ANSWERS THE ATTACHMENT'S OWN KEY, not the
   * images one. 'attachment' is a single-file input, but nothing stops a
   * client from sending it as 'attachment[]' with two files, and PHP hands
   * that over in the same parallel-array shape as images[]. Answering
   * 'service_request_too_many_images' there — as this endpoint did at first —
   * pointed the client at a field it had not even filled.
   */
  public function testMoreThanOneAttachmentAnswersTheAttachmentKey() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();
    $_FILES['attachment'] = $this->fakeFiles(2);

    $result = $this->create();

    $this->assertSame(422, $result['status']);
    $this->assertSame('service_request_too_many_attachments', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — the node it builds.
   * ---------------------------------------------------------------------- */

  public function testAValidCreationBuildsTheDocumentedNodeAndAnswers201() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $this->assertSame(201, $result['status']);
    $this->assertSame('Solicitud de servicio creada correctamente.', $result['json']['message']);
    $this->assertSame(self::NID, $result['json']['data']['service_request']['id']);

    // No images[] and no attachment were sent, so the two file keys answer
    // their documented empty shapes — a list and a null, never the other way
    // around.
    $this->assertSame([], $result['json']['data']['service_request']['images']);
    $this->assertNull($result['json']['data']['service_request']['attachment']);
    $this->assertFalse(isset(myapi_test_node_saves()[0]->field_images));
    $this->assertFalse(isset(myapi_test_node_saves()[0]->field_attachment));

    $saves = myapi_test_node_saves();
    $this->assertCount(1, $saves);
    $node = $saves[0];

    $this->assertSame(MYAPI_SERVICES_REQUEST_TYPE, $node->type);
    $this->assertSame(self::UID, $node->uid);
    $this->assertSame(1, $node->status);
    $this->assertSame('Fuga en el calentador', $node->title);
    $this->assertSame(self::UNIT, $node->field_unit[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::CONDO, $node->field_condominium[LANGUAGE_NONE][0]['target_id']);
    // 'tid', not 'target_id': field_category is a taxonomy_term_reference, and
    // writing the entityreference key instead left the term unset — core's
    // taxonomy_build_node_index() then tried to INSERT a NULL tid into
    // {taxonomy_index} and node_save() died with an integrity violation.
    $this->assertArrayNotHasKey('target_id', $node->field_category[LANGUAGE_NONE][0]);
    $this->assertSame(self::CATEGORY, $node->field_category[LANGUAGE_NONE][0]['tid']);
    $this->assertSame('El calentador del baño principal gotea desde el lunes.', $node->field_description[LANGUAGE_NONE][0]['value']);
    $this->assertSame(MYAPI_SERVICES_REQUEST_STATUS_OPEN, $node->field_request_status[LANGUAGE_NONE][0]['value']);
  }

  /**
   * field_requester is ALWAYS the token's uid: nothing the request sends under
   * any name changes it, because there is no such input read at all.
   */
  public function testTheRequesterIsAlwaysTheTokensUid() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost(['requester_id' => '99', 'uid' => '99', 'field_requester' => '99']);

    $this->create();

    $node = myapi_test_node_saves()[0];
    $this->assertSame(self::UID, $node->field_requester[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::UID, $node->uid);
  }

  /**
   * field_assigned_offer and field_closed_at are never written by this
   * endpoint, whatever the outcome — open or direct.
   */
  public function testFieldAssignedOfferAndClosedAtAreAlwaysEmpty() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();
    $this->create();
    $openNode = myapi_test_node_saves()[0];

    $requestRow = $this->requestRow([
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      'assigned_provider_id'           => (string) self::PROVIDER,
      'assigned_provider_raw'          => (string) self::PROVIDER,
    ]);
    $this->seed(array_merge([$requestRow], $this->providerRows(self::PROVIDER, [self::CATEGORY])));
    $this->authenticate();
    $this->validPost(['assigned_provider_id' => (string) self::PROVIDER]);
    $this->create();
    $directNode = myapi_test_node_saves()[0];

    $this->assertFalse(isset($openNode->field_assigned_offer));
    $this->assertFalse(isset($openNode->field_closed_at));
    $this->assertFalse(isset($directNode->field_assigned_offer));
    $this->assertFalse(isset($directNode->field_closed_at));
  }

  public function testNoRejectedCreationSavesANode() {
    $rejections = [
      'missing title'        => ['title' => ''],
      'long title'           => ['title' => str_repeat('a', 300)],
      'bad unit_id'          => ['unit_id' => 'abc'],
      'foreign unit_id'      => ['unit_id' => '99999'],
      'bad category_id'      => ['category_id' => 'abc'],
      'orphan category_id'   => ['category_id' => '999999'],
      'empty description'    => ['description' => '   '],
      'unparseable start'    => ['desired_start' => 'not-a-date'],
      'past start'           => ['desired_start' => date('Y-m-d H:i', REQUEST_TIME - 3600)],
    ];

    foreach ($rejections as $name => $overrides) {
      $this->seed();
      $this->authenticate();
      $this->validPost($overrides);

      $this->create();

      $this->assertSame([], myapi_test_node_saves(), $name);
    }
  }

  /* -------------------------------------------------------------------------
   * POST /api/v1/service-requests — the 201 response shape (SPEC 89's own
   * keys, reused; the nineteenth is SPEC 93's timeline).
   * ---------------------------------------------------------------------- */

  public function testTheResponseHasExactlyNineteenKeys() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $keys = [
      'id', 'title', 'description', 'status', 'category', 'unit',
      'offers_count', 'assigned_offer', 'assigned_provider', 'created',
      'desired_start', 'viewer', 'requester', 'condominium', 'images',
      'attachment', 'closed_at', 'offers', 'transactions',
    ];
    $this->assertCount(19, $keys, 'the fixture list itself must be complete');
    $this->assertSame($keys, array_keys($result['json']['data']['service_request']));
  }

  /**
   * THE 201 CARRIES THE TIMELINE POPULATED (SPEC 93), not an empty list.
   *
   * The initial transaction of SPEC 92 already exists at this instant — the
   * node_save() this endpoint performs fires hook_node_insert(), which writes
   * it — so answering `transactions: []` here would be serving a datum that is
   * false, and omitting the key would break SPEC 89's promise that the 201 and
   * GET /% are the same object.
   *
   * It is the one difference with `offers` and `offers_count`, which ARE put
   * directly in code as [] and 0: those two are known to be empty, this one is
   * known NOT to be, so it is queried and never assumed.
   */
  public function testThe201CarriesTheInitialTransaction() {
    $this->seed([$this->requestRow(), $this->transactionRow(512, '2026-08-19 14:30:00')]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $this->assertSame([
      [
        'id'          => 512,
        'status'      => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        'status_date' => '2026-08-19T14:30:00',
        'comment'     => 'Hemos recibido su solicitud.',
        'created'     => format_date(REQUEST_TIME, 'custom', 'Y-m-d\TH:i:s'),
      ],
    ], $result['json']['data']['service_request']['transactions']);
  }

  /**
   * A request whose timeline is empty still answers the key, as a list. It is
   * the shape a request born before SPEC 92 has forever: no backfill invents a
   * row for it.
   */
  public function testThe201AnswersAnEmptyTimelineAsAList() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $this->assertSame([], $result['json']['data']['service_request']['transactions']);
    $this->assertStringContainsString('"transactions":[]', $result['output']);
  }

  public function testViewerIsAlwaysRequester() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $this->assertSame('requester', $result['json']['data']['service_request']['viewer']);
  }

  /**
   * offers: [] and offers_count: 0 are put directly in code — no query against
   * field_data_field_request ever runs on the write path.
   */
  public function testOffersAndOffersCountAreEmptyWithNoOfferQuery() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $sr = $result['json']['data']['service_request'];
    $this->assertSame([], $sr['offers']);
    $this->assertSame(0, $sr['offers_count']);
    $this->assertNotContains('field_data_field_request', $this->queriedTables());
  }

  public function testClosedAtIsAlwaysNull() {
    $this->seed([$this->requestRow()]);
    $this->authenticate();
    $this->validPost();

    $result = $this->create();

    $this->assertNull($result['json']['data']['service_request']['closed_at']);
  }

  /**
   * Immediately after the POST, GET /api/v1/service-requests/{id} with the
   * same token answers the SAME object — both re-fetch the same pre-seeded
   * row through the same serialiser, so this is the module's own guarantee
   * that the two responses cannot drift apart, made concrete.
   *
   * The fixture seeds a transaction (SPEC 93) so the comparison covers the
   * timeline too and is not vacuously true on an empty list: `transactions` is
   * the one collection the 201 QUERIES instead of assuming, through the same
   * loader the detail calls, and this is what pins that the entry the creator
   * receives is byte for byte the entry they will read back.
   */
  public function testResponseMatchesAnImmediateGetOfTheSameRequest() {
    $this->seed([$this->requestRow(), $this->transactionRow(512, '2026-08-19 14:30:00')]);
    $this->authenticate();
    $this->validPost();
    $createResult = $this->create();

    $this->authenticate();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $getResult = myapi_test_capture(function () {
      myapi_service_request_item_dispatch(self::NID);
    });

    $this->assertSame(200, $getResult['status']);
    $this->assertSame(
      $createResult['json']['data']['service_request'],
      $getResult['json']['data']['service_request']
    );
    // Not an empty list on both sides: the comparison above has to be about
    // something.
    $this->assertCount(1, $createResult['json']['data']['service_request']['transactions']);
  }
}
