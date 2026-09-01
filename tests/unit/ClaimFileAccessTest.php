<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.claims_common.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.claims_files.inc';
require_once __DIR__ . '/../../includes/myapi.file_download.inc';
require_once __DIR__ . '/../../resources/claim.resource.inc';
require_once __DIR__ . '/../../myapi.module';

/**
 * Unit tests for the private files of a claim (SPEC 65, covered by SPEC 77):
 * includes/myapi.claims_files.inc and the endpoint that serves them,
 * GET /api/v1/claims/%/files/%.
 *
 * SPEC 65 moved field_images and field_attachment into private://, which means
 * NOTHING serves them without an explicit decision — and this file is where
 * that decision is made, for both consumers: the app through the endpoint and
 * the back office through hook_file_download(). A wrong answer here is not a
 * broken page, it is a photo of somebody's flat served to a stranger, or the
 * bank details in a claim's attachment reaching a building administrator of
 * another building.
 *
 * Until this spec none of the four functions had a test in any layer.
 *
 * What runs for real: the ownership resolution over fixture rows, the whole
 * role/condominium rule, and the endpoint end to end including its three
 * ordered checks (token, claim, file).
 *
 * What does not: the SQL of the ownership query — joins are recorded, not
 * resolved (bootstrap.php) — so "an INNER JOIN to node restricts the bundles"
 * is asserted here as behaviour over seeded columns. And Drupal's own file
 * delivery: file_transfer() is a recorder that ends the request, so a green
 * case says "these headers were asked for", never "the bytes reached the app".
 */
class ClaimFileAccessTest extends TestCase {

  const TOKEN = 'a-valid-access-token';
  const UID = 3;
  const CONDO = 12;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_file_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
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
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A row of field_data_field_images / field_data_field_attachment, with the
   * columns the ownership query reads and the ones it projects off its joins.
   *
   * @param string $field  'field_images' or 'field_attachment'.
   * @param int    $fid    The file.
   * @param int    $nid    The node the file hangs from.
   * @param string $type   That node's bundle.
   * @param mixed  $claim  field_claim of that node, for a transaction.
   */
  private function fileRow($field, $fid, $nid, $type = 'reclamo', $claim = NULL) {
    return [
      'entity_id'   => $nid,
      'entity_type' => 'node',
      'deleted'     => 0,
      $field . '_fid' => $fid,
      // Joined from node.
      'nid'  => $nid,
      'type' => $type,
      // Joined from field_data_field_claim.
      'claim_nid' => $claim,
    ];
  }

  private function tokenRow(array $overrides = []) {
    return $overrides + [
      'id'                => '1',
      'uid'               => (string) self::UID,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  private function claim(array $values = []) {
    return $values + [
      'n.nid'       => 140,
      'id'          => 140,
      'type'        => 'reclamo',
      'status'      => 1,
      'created'     => mktime(16, 45, 0, 8, 4, 2026),
      'subject'     => 'Fuga en el pasillo',
      'description' => 'Hay agua.',
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

  /**
   * The reader, their condominium, the claim and the file rows.
   */
  private function seedRequest(array $claims, array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0'],
      ],
      'field_data_field_condominio' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_condominio_target_id' => (string) self::CONDO],
      ],
      'node' => $claims,
    ] + $tables);
  }

  private function download($id, $fid) {
    return myapi_test_capture(function () use ($id, $fid) {
      myapi_claim_file_dispatch($id, $fid);
    });
  }

  /**
   * A back-office account.
   */
  private function account($uid, array $roles = [], ?array $condominiums = NULL) {
    $account = (object) ['uid' => $uid, 'roles' => $roles];

    if ($condominiums !== NULL) {
      $account->{MYAPI_BUILDING_ADMIN_CONDO_FIELD}[LANGUAGE_NONE] = array_map(function ($nid) {
        return ['target_id' => $nid];
      }, $condominiums);
    }

    return $account;
  }

  /* -------------------------------------------------------------------------
   * myapi_claims_file_claim_nid() — which claim owns a file.
   * ---------------------------------------------------------------------- */

  /**
   * A fid that cannot be one answers NULL before any query: the guard that
   * keeps a route argument of '0' or 'abc' from reaching the database.
   */
  public function testAnImpossibleFidAnswersNullWithoutAQuery() {
    foreach ([0, -3, 'abc', '', NULL] as $fid) {
      myapi_test_db_seed();

      $this->assertNull(myapi_claims_file_claim_nid($fid), var_export($fid, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($fid, TRUE));
    }
  }

  /**
   * An image of a claim resolves to that claim.
   */
  public function testAnImageOfAClaimResolvesToTheClaim() {
    myapi_test_db_seed(['field_data_field_images' => [$this->fileRow('field_images', 7, 140)]]);

    $this->assertSame(140, myapi_claims_file_claim_nid(7));
  }

  /**
   * The attachment of a claim resolves too — it is the second field the
   * function walks, so this also proves it does not stop after the images.
   */
  public function testTheAttachmentOfAClaimResolvesToTheClaim() {
    myapi_test_db_seed(['field_data_field_attachment' => [$this->fileRow('field_attachment', 31, 140)]]);

    $this->assertSame(140, myapi_claims_file_claim_nid(31));
    $this->assertSame(
      ['field_data_field_images', 'field_data_field_attachment'],
      array_column(myapi_test_db_queries(), 'table'),
      'images first, attachment second'
    );
  }

  /**
   * An image of a TRANSACTION resolves to the claim that transaction belongs
   * to — the hop through field_claim, and the reason the endpoint's URL always
   * carries the claim's nid.
   */
  public function testAnImageOfATransactionResolvesToItsClaim() {
    myapi_test_db_seed(['field_data_field_images' => [
      $this->fileRow('field_images', 9, 15, 'claim_transaction', 140),
    ]]);

    $this->assertSame(140, myapi_claims_file_claim_nid(9));
  }

  /**
   * A transaction with a corrupt field_claim owns no claim: NULL, i.e. "not
   * ours", rather than an accessible file with no owner. It fails closed.
   */
  public function testATransactionWithoutAClaimAnswersNull() {
    myapi_test_db_seed(['field_data_field_images' => [
      $this->fileRow('field_images', 9, 15, 'claim_transaction', NULL),
    ]]);

    $this->assertNull(myapi_claims_file_claim_nid(9));
  }

  /**
   * The same field attached to another bundle by a future spec is not a
   * claim's file — the INNER JOIN to node restricts it to the two claim
   * bundles.
   */
  public function testAFileOfAnotherBundleIsNotAClaimsFile() {
    myapi_test_db_seed(['field_data_field_images' => [
      $this->fileRow('field_images', 7, 300, 'boletin'),
    ]]);

    $this->assertNull(myapi_claims_file_claim_nid(7));
  }

  /**
   * The bundle restriction is what does that work, and this is the case that
   * proves it: a node of ANOTHER type that also carries a field_claim — a
   * future bundle referencing a claim — would otherwise resolve through the
   * transaction branch and hand out that claim's files.
   */
  public function testAnotherBundleWithAFieldClaimStillResolvesToNothing() {
    myapi_test_db_seed(['field_data_field_images' => [
      $this->fileRow('field_images', 7, 300, 'seguimiento', 140),
    ]]);

    $this->assertNull(myapi_claims_file_claim_nid(7));
  }

  /**
   * A file nothing points at — a payment receipt, an area photo, another
   * module's — answers NULL, which is what keeps both consumers off files that
   * are not theirs.
   */
  public function testAForeignFidAnswersNull() {
    myapi_test_db_seed(['field_data_field_images' => [$this->fileRow('field_images', 7, 140)]]);

    $this->assertNull(myapi_claims_file_claim_nid(999));
  }

  /**
   * A deleted field row and a row of another entity type never resolve.
   */
  public function testDeletedRowsAndNonNodeEntitiesAreIgnored() {
    myapi_test_db_seed(['field_data_field_images' => [
      ['entity_id' => 140, 'entity_type' => 'node', 'deleted' => 1, 'field_images_fid' => 7, 'nid' => 140, 'type' => 'reclamo', 'claim_nid' => NULL],
    ]]);
    $this->assertNull(myapi_claims_file_claim_nid(7), 'deleted');

    myapi_test_db_seed(['field_data_field_images' => [
      ['entity_id' => 140, 'entity_type' => 'user', 'deleted' => 0, 'field_images_fid' => 7, 'nid' => 140, 'type' => 'reclamo', 'claim_nid' => NULL],
    ]]);
    $this->assertNull(myapi_claims_file_claim_nid(7), 'entity_type');
  }

  /**
   * The resolution is deterministic when a fid is referenced more than once:
   * ORDER BY n.nid ASC, one row. Asserted on the shape, because it is the only
   * thing that makes two identical requests answer the same claim.
   */
  public function testTheOwnershipQueryIsOrderedAndLimitedToOneRow() {
    myapi_test_db_seed(['field_data_field_images' => [$this->fileRow('field_images', 7, 140)]]);

    myapi_claims_file_claim_nid(7);

    $query = myapi_test_db_queries('field_data_field_images')[0];
    $this->assertSame([['field' => 'n.nid', 'direction' => 'ASC']], $query['order']);
    $this->assertSame(['start' => 0, 'length' => 1], $query['range']);
  }

  /**
   * A string fid from a route argument is cast, so '7' and 7 answer the same.
   */
  public function testAStringFidIsCast() {
    myapi_test_db_seed(['field_data_field_images' => [$this->fileRow('field_images', 7, 140)]]);

    $this->assertSame(140, myapi_claims_file_claim_nid('7'));
  }

  /* -------------------------------------------------------------------------
   * myapi_claims_file_fid_by_uri() — the hook's way in.
   * ---------------------------------------------------------------------- */

  public function testAnEmptyOrNonStringUriAnswersNullWithoutAQuery() {
    foreach (['', NULL, 42, ['private://x']] as $uri) {
      myapi_test_db_seed();

      $this->assertNull(myapi_claims_file_fid_by_uri($uri), var_export($uri, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($uri, TRUE));
    }
  }

  public function testAKnownUriAnswersItsFidAsAnInt() {
    myapi_test_db_seed(['file_managed' => [
      ['fid' => '7', 'uri' => 'private://claims/2026-08/fuga.jpg'],
    ]]);

    $this->assertSame(7, myapi_claims_file_fid_by_uri('private://claims/2026-08/fuga.jpg'));
  }

  /**
   * An unknown URI answers NULL — the normal outcome, since the hook fires for
   * every private file of the site, including image-style derivatives that
   * have no row in file_managed at all.
   */
  public function testAnUnknownUriAnswersNull() {
    myapi_test_db_seed(['file_managed' => [
      ['fid' => '7', 'uri' => 'private://claims/2026-08/fuga.jpg'],
    ]]);

    $this->assertNull(myapi_claims_file_fid_by_uri('private://styles/thumbnail/claims/fuga.jpg'));
    $this->assertNull(myapi_claims_file_fid_by_uri('private://comprobantes_pago/recibo.pdf'));
  }

  /* -------------------------------------------------------------------------
   * myapi_claims_file_access() — the back-office rule.
   * ---------------------------------------------------------------------- */

  public function testANonObjectAccountIsDenied() {
    $this->assertFalse(myapi_claims_file_access(140, NULL));
    $this->assertFalse(myapi_claims_file_access(140, 'admin'));
  }

  /**
   * uid 1 is let in explicitly, because the superuser bypass lives inside
   * user_access() and this function never calls it.
   */
  public function testUidOneIsAlwaysAllowed() {
    $this->assertTrue(myapi_claims_file_access(140, $this->account(1, [])));
  }

  /**
   * Anonymous, and any account with no admin role, is denied — a resident
   * reaches their files through the API endpoint, never through this rule.
   */
  public function testAnonymousAndPlainAccountsAreDenied() {
    $this->assertFalse(myapi_claims_file_access(140, $this->account(0, [])));
    $this->assertFalse(myapi_claims_file_access(140, $this->account(3, [2 => 'authenticated user'])));
    $this->assertFalse(myapi_claims_file_access(140, $this->account(3, [7 => 'propietario'])));
  }

  /**
   * 'administrator' and 'backend' see every claim of the site, with no
   * condominium check and without loading the claim at all.
   */
  public function testAdministratorAndBackendSeeEveryClaim() {
    foreach (['administrator', 'backend'] as $role) {
      myapi_test_node_seed();

      $this->assertTrue(myapi_claims_file_access(140, $this->account(41, [4 => $role])), $role);
    }
  }

  /**
   * A building admin sees the files of a claim of one of THEIR condominiums.
   */
  public function testABuildingAdminSeesTheirOwnCondominiumsClaims() {
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo',
      'field_condominium' => [LANGUAGE_NONE => [['target_id' => self::CONDO]]],
    ]]);

    $account = $this->account(41, [6 => MYAPI_BUILDING_ADMIN_ROLE], [self::CONDO]);

    $this->assertTrue(myapi_claims_file_access(140, $account));
  }

  /**
   * And NOT the files of another building's claim — the leak the role scoping
   * exists to prevent, reachable by pasting a URL.
   */
  public function testABuildingAdminDoesNotSeeAnotherBuildingsClaim() {
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo',
      'field_condominium' => [LANGUAGE_NONE => [['target_id' => 30]]],
    ]]);

    $account = $this->account(41, [6 => MYAPI_BUILDING_ADMIN_ROLE], [self::CONDO]);

    $this->assertFalse(myapi_claims_file_access(140, $account));
  }

  /**
   * A building admin with no assignment yet sees nothing.
   */
  public function testABuildingAdminWithoutCondominiumsSeesNothing() {
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo',
      'field_condominium' => [LANGUAGE_NONE => [['target_id' => self::CONDO]]],
    ]]);

    $account = $this->account(41, [6 => MYAPI_BUILDING_ADMIN_ROLE], []);

    $this->assertFalse(myapi_claims_file_access(140, $account));
  }

  /**
   * A claim whose condominium cannot be resolved is 'ignore' — out of the
   * rule — and that becomes TRUE here, because in this context there is
   * nobody else to decide: the account already holds the role and the file
   * already belongs to a claim.
   */
  public function testAClaimWithNoCondominiumIsAllowedForTheRole() {
    myapi_test_node_seed([140 => ['nid' => 140, 'type' => 'reclamo']]);

    $account = $this->account(41, [6 => MYAPI_BUILDING_ADMIN_ROLE], [self::CONDO]);

    $this->assertTrue(myapi_claims_file_access(140, $account));
  }

  /**
   * A claim that no longer loads is denied, not allowed by omission.
   */
  public function testAMissingClaimIsDeniedForABuildingAdmin() {
    myapi_test_node_seed();

    $account = $this->account(41, [6 => MYAPI_BUILDING_ADMIN_ROLE], [self::CONDO]);

    $this->assertFalse(myapi_claims_file_access(140, $account));
  }

  /**
   * Roles are compared by NAME and never by rid: the same role under a
   * different rid still opens, and an unknown name under rid 3 does not.
   */
  public function testRolesAreComparedByNameAndNotByRid() {
    $this->assertTrue(myapi_claims_file_access(140, $this->account(41, [99 => 'administrator'])));
    $this->assertFalse(myapi_claims_file_access(140, $this->account(41, [3 => 'administrador'])));
  }

  /* -------------------------------------------------------------------------
   * myapi_claims_file_download_headers() — hook_file_download().
   * ---------------------------------------------------------------------- */

  /**
   * A URI that belongs to no file of ours answers NULL — "somebody else
   * decides" — which is what keeps every other private file of the site
   * behaving exactly as before.
   */
  public function testAForeignUriAnswersNull() {
    myapi_test_db_seed(['file_managed' => []]);

    $this->assertNull(myapi_claims_file_download_headers('private://comprobantes_pago/r.pdf', $this->account(1)));
  }

  /**
   * And so does a file of ours that belongs to no claim: one query for the
   * fid, one for the ownership, and it is over. That path has to stay cheap —
   * the hook fires for every private file of the site.
   */
  public function testAFileThatBelongsToNoClaimAnswersNull() {
    myapi_test_db_seed([
      'file_managed' => [['fid' => '7', 'uri' => 'private://otros/x.pdf']],
      'field_data_field_images' => [],
    ]);

    $this->assertNull(myapi_claims_file_download_headers('private://otros/x.pdf', $this->account(1)));
    $this->assertCount(3, myapi_test_db_queries(), 'the fid lookup plus the two ownership queries');
  }

  /**
   * A claim file the account may NOT read is a hard deny (-1), not a NULL:
   * NULL would let another module grant it.
   */
  public function testAClaimFileWithoutAccessIsAHardDeny() {
    myapi_test_db_seed([
      'file_managed' => [['fid' => '7', 'uri' => 'private://claims/fuga.jpg']],
      'field_data_field_images' => [$this->fileRow('field_images', 7, 140)],
    ]);
    myapi_test_node_seed([140 => [
      'nid' => 140, 'type' => 'reclamo',
      'field_condominium' => [LANGUAGE_NONE => [['target_id' => 30]]],
    ]]);

    $account = $this->account(41, [6 => MYAPI_BUILDING_ADMIN_ROLE], [self::CONDO]);

    $this->assertSame(-1, myapi_claims_file_download_headers('private://claims/fuga.jpg', $account));
  }

  /**
   * With access, the headers — 'inline' and not 'attachment', because the back
   * office renders these images on screen and forcing a download on every
   * thumbnail would make the forms unusable.
   */
  public function testAnAllowedClaimFileAnswersInlineHeaders() {
    myapi_test_db_seed([
      'file_managed' => [['fid' => '7', 'uri' => 'private://claims/fuga.jpg']],
      'field_data_field_images' => [$this->fileRow('field_images', 7, 140)],
    ]);
    myapi_test_file_seed([7 => [
      'fid' => 7, 'uri' => 'private://claims/fuga.jpg',
      'filemime' => 'image/jpeg', 'filesize' => 20481, 'filename' => 'fuga.jpg',
    ]]);

    $headers = myapi_claims_file_download_headers('private://claims/fuga.jpg', $this->account(1));

    $this->assertSame([
      'Content-Type'        => 'image/jpeg',
      'Content-Length'      => 20481,
      'Content-Disposition' => 'inline; filename="fuga.jpg"',
      'X-Content-Type-Options' => 'nosniff',
    ], $headers);
  }

  /**
   * A file_managed row whose file object no longer loads answers NULL rather
   * than headers describing nothing.
   */
  public function testAnUnloadableFileAnswersNull() {
    myapi_test_db_seed([
      'file_managed' => [['fid' => '7', 'uri' => 'private://claims/fuga.jpg']],
      'field_data_field_images' => [$this->fileRow('field_images', 7, 140)],
    ]);
    myapi_test_file_seed();

    $this->assertNull(myapi_claims_file_download_headers('private://claims/fuga.jpg', $this->account(1)));
  }

  /* -------------------------------------------------------------------------
   * GET /api/v1/claims/%/files/% — the app's way in.
   * ---------------------------------------------------------------------- */

  public function testTheFileRouteAcceptsOnlyGetAndAnswers405WithoutAToken() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->download('140', '7');

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame([], myapi_test_db_queries(), $method);
    }
  }

  public function testTheFileRouteRequiresAToken() {
    $result = $this->download('140', '7');

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /**
   * The claim is decided BEFORE the file: a claim the reader cannot see
   * answers claim_not_found whatever fid is asked for, so fids cannot be
   * probed under a foreign nid.
   */
  public function testAnInvisibleClaimAnswersClaimNotFoundWhateverTheFid() {
    $this->seedRequest([$this->claim([
      'field_visibility_value' => 'private', 'visibility' => 'private',
      'field_requester_target_id' => 99, 'requester_id' => 99,
    ])], ['field_data_field_images' => [$this->fileRow('field_images', 7, 140)]]);

    $result = $this->download('140', '7');

    $this->assertSame(404, $result['status']);
    $this->assertSame('claim_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries('field_data_field_images'), 'the file was never even looked up');
  }

  public function testAMalformedClaimIdAnswersClaimNotFound() {
    foreach (['abc', '0', '-1'] as $id) {
      $this->seedRequest([$this->claim()]);

      $result = $this->download($id, '7');

      $this->assertSame(404, $result['status'], $id);
      $this->assertSame('claim_not_found', $result['json']['error_code'], $id);
    }
  }

  /**
   * Under a claim the reader DOES see, a malformed or foreign fid is a
   * different problem and says so: file_not_found.
   */
  public function testAMalformedFidAnswersFileNotFound() {
    foreach (['abc', '0', '-1', NULL] as $fid) {
      $this->seedRequest([$this->claim()]);

      $result = $this->download('140', $fid);

      $this->assertSame(404, $result['status'], var_export($fid, TRUE));
      $this->assertSame('file_not_found', $result['json']['error_code'], var_export($fid, TRUE));
    }
  }

  /**
   * A fid that belongs to ANOTHER claim is file_not_found, not served: the
   * membership check is what makes the claim the unit of access.
   */
  public function testAFidOfAnotherClaimIsFileNotFound() {
    $this->seedRequest([$this->claim()], [
      'field_data_field_images' => [$this->fileRow('field_images', 7, 999)],
    ]);
    // The file exists and is perfectly loadable: the ONLY thing refusing it is
    // the membership check, which is what this case is about.
    myapi_test_file_seed([7 => [
      'fid' => 7, 'uri' => __FILE__,
      'filemime' => 'image/jpeg', 'filesize' => 100, 'filename' => 'ajena.jpg',
    ]]);

    $result = $this->download('140', '7');

    $this->assertSame(404, $result['status']);
    $this->assertSame('file_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers(), 'nothing was streamed');
  }

  /**
   * And a fid that belongs to NO claim at all — a payment receipt — is refused
   * the same way, even under a claim the reader owns.
   */
  public function testAFidOfNoClaimIsFileNotFound() {
    $this->seedRequest([$this->claim()], ['field_data_field_images' => []]);
    myapi_test_file_seed([7 => [
      'fid' => 7, 'uri' => __FILE__,
      'filemime' => 'application/pdf', 'filesize' => 100, 'filename' => 'recibo.pdf',
    ]]);

    $result = $this->download('140', '7');

    $this->assertSame(404, $result['status']);
    $this->assertSame('file_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers());
  }

  /**
   * A file_managed row pointing at bytes that are not on disk answers 404 too,
   * never a 200 of zero bytes.
   */
  public function testAFileMissingFromDiskAnswersFileNotFound() {
    $this->seedRequest([$this->claim()], [
      'field_data_field_images' => [$this->fileRow('field_images', 7, 140)],
    ]);
    myapi_test_file_seed([7 => [
      'fid' => 7, 'uri' => '/tmp/a-file-that-does-not-exist-' . __LINE__,
      'filemime' => 'image/jpeg', 'filesize' => 10, 'filename' => 'fuga.jpg',
    ]]);

    $result = $this->download('140', '7');

    $this->assertSame(404, $result['status']);
    $this->assertSame('file_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers());
  }

  /**
   * The success path: the bytes are streamed with the four documented headers
   * and NO JSON envelope — the one endpoint of the module that answers
   * something else, on purpose.
   *
   * The fixture points at this very test file so file_exists() is true without
   * writing anything to disk.
   */
  public function testAValidRequestStreamsTheFileWithoutAnEnvelope() {
    $this->seedRequest([$this->claim()], [
      'field_data_field_images' => [$this->fileRow('field_images', 7, 140)],
    ]);
    myapi_test_file_seed([7 => [
      'fid' => 7, 'uri' => __FILE__,
      'filemime' => 'image/jpeg', 'filesize' => 20481, 'filename' => 'fuga.jpg',
    ]]);

    $result = $this->download('140', '7');

    $this->assertTrue($result['exited']);
    $this->assertSame('', $result['output'], 'no envelope, no body of our own');
    $this->assertNull($result['status']);

    $transfers = myapi_test_file_transfers();
    $this->assertCount(1, $transfers);
    $this->assertSame(__FILE__, $transfers[0]['uri']);
    $this->assertSame([
      'Content-Type'        => 'image/jpeg',
      'Content-Length'      => 20481,
      'Content-Disposition' => 'inline; filename="fuga.jpg"',
      'X-Content-Type-Options' => 'nosniff',
      'Cache-Control'       => 'private, no-store',
    ], $transfers[0]['headers']);
  }

  /**
   * A file of one of the claim's TRANSACTIONS is served under the claim's nid:
   * the app never has to know which transaction a picture hangs from.
   */
  public function testATransactionsFileIsServedUnderTheClaimsNid() {
    $this->seedRequest([$this->claim()], [
      'field_data_field_images' => [$this->fileRow('field_images', 9, 15, 'claim_transaction', 140)],
    ]);
    myapi_test_file_seed([9 => [
      'fid' => 9, 'uri' => __FILE__,
      'filemime' => 'image/jpeg', 'filesize' => 100, 'filename' => 'visita.jpg',
    ]]);

    $result = $this->download('140', '9');

    $this->assertTrue($result['exited']);
    $this->assertCount(1, myapi_test_file_transfers());
  }

  /**
   * And NOT under the transaction's own nid, which is not a claim the reader
   * can see: claim_not_found.
   */
  public function testATransactionsFileIsNotServedUnderTheTransactionsNid() {
    $this->seedRequest([$this->claim()], [
      'field_data_field_images' => [$this->fileRow('field_images', 9, 15, 'claim_transaction', 140)],
    ]);

    $result = $this->download('15', '9');

    $this->assertSame(404, $result['status']);
    $this->assertSame('claim_not_found', $result['json']['error_code']);
  }

  /**
   * A reader with no unit at all gets the uniform 404 and never reaches the
   * claim query.
   */
  public function testAReaderWithoutCondominiumsAnswersClaimNotFound() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];
    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);

    $result = $this->download('140', '7');

    $this->assertSame(404, $result['status']);
    $this->assertSame('claim_not_found', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * The role list both consumers share (SPEC 56).
   * ---------------------------------------------------------------------- */

  /**
   * The three roles, in one place: the file rule, the listing page and the
   * 'administrator'/'backend' shortcut of myapi_claims_file_access() all read
   * this list, so adding a fourth role has exactly one line to touch.
   */
  public function testTheClaimsAdminRolesAreTheThreeDocumentedOnes() {
    $this->assertSame(
      ['administrator', 'backend', MYAPI_BUILDING_ADMIN_ROLE],
      myapi_claims_admin_roles()
    );
  }

}
