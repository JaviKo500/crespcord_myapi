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
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_files.inc';
// The third owner myapi_file_download() asks since SPEC 89. This file exercises
// that chain end to end, so every link of it has to be loaded here.
require_once __DIR__ . '/../../includes/myapi.service_request_files.inc';
require_once __DIR__ . '/../../resources/provider.resource.inc';
require_once __DIR__ . '/../../myapi.module';

/**
 * Unit tests for the private gallery of a provider (SPEC 82): the two routes
 * of resources/provider.resource.inc, the ownership resolution of
 * includes/myapi.provider_files.inc, and the chaining of hook_file_download()
 * that now has TWO owners to ask.
 *
 * field_gallery is born in private://, so NOTHING serves it without an
 * explicit decision, and this file is where that decision is checked for both
 * consumers: the app through the two endpoints and the back office through
 * myapi_file_download(). A wrong answer is not a broken page — it is either a
 * provider's images served to whoever guesses a URL, or, in the chaining, a
 * CLAIM file whose denial quietly turns into a permission.
 *
 * What runs for real: the four ordered checks of the download, the shape of
 * the list, the ownership query over fixture rows and the whole chain of
 * myapi_file_download().
 *
 * What does not: the SQL of those queries — joins are recorded, not resolved
 * (bootstrap.php) — so "the INNER JOIN to node restricts the bundle" is
 * asserted here as behaviour over seeded columns. And Drupal's own file
 * delivery: file_transfer() is a recorder that ends the request, so a green
 * case says "these headers were asked for", never "the bytes reached the app".
 * node_access() is a fixture too, so no case here proves the SPEC 78 rules.
 */
class ProviderGalleryEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';
  const UID = 3;
  const PROVIDER = 7;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_file_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_node_access'] = [];
    unset($GLOBALS['myapi_test_node_access_default']);
    $GLOBALS['myapi_test_node_access_calls'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_node_access'] = [];
    unset($GLOBALS['myapi_test_node_access_default']);
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
   * A row of the node table, as both queries of the resource read it.
   */
  private function providerNode($nid = self::PROVIDER, array $overrides = []) {
    return $overrides + [
      'nid'    => $nid,
      'type'   => 'provider',
      'status' => 1,
      'title'  => 'Taller El Sáuco',
    ];
  }

  /**
   * A row of field_data_field_gallery carrying, flat, the columns of BOTH
   * queries that read the table: the list (which joins file_managed for the
   * filename) and the ownership resolution (which joins node for the bundle).
   * The stub records joins instead of resolving them, so the joined columns
   * are seeded under the alias production reads them by.
   */
  private function galleryRow($fid, $nid = self::PROVIDER, $delta = 0, $filename = 'taller-01.jpg', $type = 'provider') {
    return [
      'entity_id'         => $nid,
      'entity_type'       => 'node',
      'deleted'           => 0,
      'delta'             => $delta,
      'field_gallery_fid' => $fid,
      // Joined from file_managed.
      'fid'      => $fid,
      'filename' => $filename,
      // Joined from node.
      'nid'  => $nid,
      'type' => $type,
    ];
  }

  /**
   * The reader's token and the tables of one request.
   */
  private function seedRequest(array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'node'          => [$this->providerNode()],
    ] + $tables);
  }

  private function listGallery($id) {
    return myapi_test_capture(function () use ($id) {
      myapi_provider_gallery_dispatch($id);
    });
  }

  private function download($id, $fid) {
    return myapi_test_capture(function () use ($id, $fid) {
      myapi_provider_gallery_file_dispatch($id, $fid);
    });
  }

  private function account($uid, array $roles = []) {
    return (object) ['uid' => $uid, 'roles' => $roles];
  }

  /* -------------------------------------------------------------------------
   * The two dispatchers.
   * ---------------------------------------------------------------------- */

  /**
   * Both routes are read-only: the operator loads the gallery from the back
   * office, and there is no upload, delete or reorder path in this spec. The
   * method is checked BEFORE the token, so a POST answers 405 with no
   * credentials at all — the method is wrong whoever is asking.
   */
  public function testBothRoutesAcceptOnlyGetAndAnswer405WithoutAToken() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $list = $this->listGallery('7');
      $this->assertSame(405, $list['status'], $method . ' on the list');
      $this->assertSame('method_not_allowed', $list['json']['error_code'], $method);

      $download = $this->download('7', '42');
      $this->assertSame(405, $download['status'], $method . ' on the download');
      $this->assertSame('method_not_allowed', $download['json']['error_code'], $method);

      $this->assertSame([], myapi_test_db_queries(), $method . ' must not reach the database');
    }
  }

  /**
   * A Bearer token is mandatory on BOTH routes, the image included: there is no
   * ?access_token= in this module, which is the decision that makes a bare
   * Image.network fail in the app. Documented in docs/provider-gallery.md.
   */
  public function testBothRoutesRequireAnAuthorizationHeader() {
    foreach ([$this->listGallery('7'), $this->download('7', '42')] as $result) {
      $this->assertSame(401, $result['status']);
      $this->assertSame('missing_authorization', $result['json']['error_code']);
    }
  }

  /**
   * An unknown, revoked or expired token is 401 invalid_token, and never a
   * 404 that would leak whether that provider exists.
   */
  public function testAnInvalidRevokedOrExpiredTokenAnswers401() {
    $cases = [
      'unknown' => [],
      'revoked' => ['revoked' => '1'],
      'expired' => ['access_expires_at' => REQUEST_TIME - 1],
    ];

    foreach ($cases as $name => $overrides) {
      $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
      $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];
      myapi_test_db_seed([
        'my_api_tokens' => $name === 'unknown' ? [] : [$this->tokenRow($overrides)],
        'node'          => [$this->providerNode()],
      ]);

      $result = $this->listGallery('7');

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
    }
  }

  /**
   * A blocked or deleted user's token is refused too, even though the row is
   * still valid: the account is loaded and checked by the shared auth helper.
   */
  public function testABlockedUsersTokenAnswers401() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 0];
    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'node'          => [$this->providerNode()],
    ]);

    $result = $this->listGallery('7');

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * GET /api/v1/providers/%/gallery — the list.
   * ---------------------------------------------------------------------- */

  /**
   * The three ways of not being a visible provider answer the same 404, with
   * the key SPEC 82 adds: a nid that does not exist, one of another content
   * type, and one that is unpublished. An unpublished node is invisible to the
   * app in every other endpoint of this module, and 404 does not confirm that
   * the nid exists.
   */
  public function testAMissingForeignOrUnpublishedProviderAnswersProviderNotFound() {
    $cases = [
      'missing'     => [],
      'other type'  => [$this->providerNode(self::PROVIDER, ['type' => 'service_request'])],
      'unpublished' => [$this->providerNode(self::PROVIDER, ['status' => 0])],
    ];

    foreach ($cases as $name => $nodes) {
      $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
      $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];
      myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()], 'node' => $nodes]);

      $result = $this->listGallery('7');

      $this->assertSame(404, $result['status'], $name);
      $this->assertSame('provider_not_found', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries('field_data_field_gallery'), $name . ': the gallery was never looked up');
    }
  }

  /**
   * A non-numeric or non-positive nid never reaches the database: the guard
   * that keeps a route argument of 'abc' or '-1' out of the query.
   */
  public function testAMalformedProviderIdAnswersProviderNotFoundWithoutQueryingNode() {
    foreach (['abc', '0', '-1', '7a', NULL] as $id) {
      $this->seedRequest();

      $result = $this->listGallery($id);

      $this->assertSame(404, $result['status'], var_export($id, TRUE));
      $this->assertSame('provider_not_found', $result['json']['error_code'], var_export($id, TRUE));
      $this->assertSame([], myapi_test_db_queries('node'), var_export($id, TRUE));
    }
  }

  /**
   * A published provider with no images is 200 and an empty list, never 404:
   * the provider exists and its gallery is empty, which is a different fact
   * from "there is no such provider".
   */
  public function testAProviderWithoutImagesAnswers200AndAnEmptyList() {
    $this->seedRequest(['field_data_field_gallery' => []]);

    $result = $this->listGallery('7');

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame(['images' => []], $result['json']['data']);
  }

  /**
   * The shape of an item: exactly three keys, an INTEGER id — a Flutter client
   * comparing 42 to "42" fails silently — and the absolute URL of the download
   * route.
   */
  public function testAnItemCarriesExactlyIdUrlAndFilename() {
    $this->seedRequest(['field_data_field_gallery' => [$this->galleryRow('42', self::PROVIDER, 0, 'taller-01.jpg')]]);

    $result = $this->listGallery('7');
    $images = $result['json']['data']['images'];

    $this->assertCount(1, $images);
    $this->assertSame(['id', 'url', 'filename'], array_keys($images[0]));
    $this->assertSame(42, $images[0]['id']);
    $this->assertSame('taller-01.jpg', $images[0]['filename']);
    $this->assertSame(
      'https://crespcord.example.com/api/v1/providers/7/gallery/42',
      $images[0]['url']
    );
  }

  /**
   * THE decision of the whole spec, in one assertion: the url is the
   * endpoint's, never the file's own. file_create_url() over a private:// uri
   * would answer /system/files/..., which returns 403 to the app because it
   * carries no Drupal session — a URL that only works in the operator's
   * browser.
   */
  public function testTheUrlNeverPointsAtTheFileSystem() {
    $this->seedRequest(['field_data_field_gallery' => [$this->galleryRow('42')]]);

    $url = $this->listGallery('7')['json']['data']['images'][0]['url'];

    $this->assertStringNotContainsString('/system/files', $url);
    $this->assertStringNotContainsString('sites/default/files', $url);
    $this->assertStringNotContainsString('private://', $url);
    $this->assertStringContainsString('/api/v1/providers/7/gallery/42', $url);
  }

  /**
   * The carousel order is the order of the Field API deltas — what the
   * operator dragged in the form — and it does not change between two
   * identical requests. Asserted over a fixture written out of order, so a
   * missing ORDER BY would show up as the fixture's own order.
   */
  public function testTheListIsOrderedByDelta() {
    $this->seedRequest(['field_data_field_gallery' => [
      $this->galleryRow('44', self::PROVIDER, 2, 'tercera.jpg'),
      $this->galleryRow('42', self::PROVIDER, 0, 'primera.jpg'),
      $this->galleryRow('43', self::PROVIDER, 1, 'segunda.jpg'),
    ]]);

    $first = array_column($this->listGallery('7')['json']['data']['images'], 'filename');
    $this->assertSame(['primera.jpg', 'segunda.jpg', 'tercera.jpg'], $first);

    $query = myapi_test_db_queries('field_data_field_gallery')[0];
    $this->assertSame([['field' => 'fg.delta', 'direction' => 'ASC']], $query['order']);
  }

  /**
   * The images of ANOTHER provider are not in this provider's list: the
   * entity_id condition is what keeps two fichas apart.
   */
  public function testTheListOnlyCarriesTheImagesOfThatProvider() {
    $this->seedRequest(['field_data_field_gallery' => [
      $this->galleryRow('42', self::PROVIDER, 0, 'mia.jpg'),
      $this->galleryRow('99', 8, 0, 'ajena.jpg'),
    ]]);

    $images = $this->listGallery('7')['json']['data']['images'];

    $this->assertCount(1, $images);
    $this->assertSame('mia.jpg', $images[0]['filename']);
  }

  /**
   * A lapsed licence does NOT block the gallery, by decision: the expiry
   * decides whether a provider APPEARS in the marketplace, not whether its
   * bytes are readable. Blocking it would break a carousel already open
   * mid-session. Asserted twice — the 200 itself, and the fact that the field
   * is never even queried.
   */
  public function testALapsedProviderStillServesItsGallery() {
    $this->seedRequest([
      'field_data_field_gallery'       => [$this->galleryRow('42')],
      'field_data_field_license_expiry' => [
        ['entity_id' => self::PROVIDER, 'field_license_expiry_value' => REQUEST_TIME - 86400],
      ],
    ]);

    $result = $this->listGallery('7');

    $this->assertSame(200, $result['status']);
    $this->assertCount(1, $result['json']['data']['images']);
    $this->assertSame([], myapi_test_db_queries('field_data_field_license_expiry'));
  }

  /* -------------------------------------------------------------------------
   * GET /api/v1/providers/%/gallery/% — the bytes.
   * ---------------------------------------------------------------------- */

  /**
   * The provider is decided BEFORE the file: an invisible provider answers
   * provider_not_found whatever fid is asked for, so fids cannot be probed
   * under a nid the caller cannot see.
   */
  public function testAnInvisibleProviderAnswersProviderNotFoundWhateverTheFid() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];
    myapi_test_db_seed([
      'my_api_tokens'            => [$this->tokenRow()],
      'node'                     => [$this->providerNode(self::PROVIDER, ['status' => 0])],
      'field_data_field_gallery' => [$this->galleryRow('42')],
    ]);

    $result = $this->download('7', '42');

    $this->assertSame(404, $result['status']);
    $this->assertSame('provider_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries('field_data_field_gallery'), 'the file was never even looked up');
    $this->assertSame([], myapi_test_file_transfers());
  }

  /**
   * Under a provider the caller DOES see, a malformed fid is a different
   * problem and says so: file_not_found.
   */
  public function testAMalformedFidAnswersFileNotFound() {
    foreach (['abc', '0', '-1', NULL] as $fid) {
      $this->seedRequest(['field_data_field_gallery' => [$this->galleryRow('42')]]);

      $result = $this->download('7', $fid);

      $this->assertSame(404, $result['status'], var_export($fid, TRUE));
      $this->assertSame('file_not_found', $result['json']['error_code'], var_export($fid, TRUE));
    }
  }

  /**
   * A fid that exists but hangs from ANOTHER provider is 404 file_not_found
   * and not 403: the caller already proved they see the provider in the route,
   * so nothing leaks by telling the two errors apart — but they are not told
   * that fid exists somewhere else either. It fails closed.
   */
  public function testAFidOfAnotherProviderIsFileNotFound() {
    $this->seedRequest(['field_data_field_gallery' => [$this->galleryRow('99', 8, 0, 'ajena.jpg')]]);
    // The file is perfectly loadable: the ONLY thing refusing it is the
    // membership check, which is what this case is about.
    myapi_test_file_seed([99 => [
      'fid' => 99, 'uri' => __FILE__,
      'filemime' => 'image/jpeg', 'filesize' => 100, 'filename' => 'ajena.jpg',
    ]]);

    $result = $this->download('7', '99');

    $this->assertSame(404, $result['status']);
    $this->assertSame('file_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers(), 'nothing was streamed');
  }

  /**
   * And a fid that belongs to a CLAIM — the other family of private files of
   * this module — is refused the same way. This is the case that proves the
   * two families do not cross through the endpoint.
   */
  public function testAFidOfAClaimIsFileNotFound() {
    $this->seedRequest([
      'field_data_field_gallery' => [],
      'field_data_field_images'  => [
        ['entity_id' => 140, 'entity_type' => 'node', 'deleted' => 0, 'field_images_fid' => 7, 'nid' => 140, 'type' => 'reclamo'],
      ],
    ]);
    myapi_test_file_seed([7 => [
      'fid' => 7, 'uri' => __FILE__,
      'filemime' => 'image/jpeg', 'filesize' => 100, 'filename' => 'fuga.jpg',
    ]]);

    $result = $this->download('7', '7');

    $this->assertSame(404, $result['status']);
    $this->assertSame('file_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers());
  }

  /**
   * And so is the fid of a LOGO (SPEC 85), even the logo of THIS very provider.
   *
   * The logo is the third family of files hanging off a provider and the only
   * PUBLIC one: it lives in field_data_field_logo, it is served by the web
   * server and it never goes through this route. This case is what proves the
   * two families do not cross — the download endpoint only ever recognises what
   * hangs from field_data_field_gallery, so a logo fid is simply not a member of
   * this provider's gallery and fails closed, exactly like a claim's.
   *
   * Seeding an EMPTY gallery is the point: the logo must not become an image of
   * the carousel by the mere fact of belonging to the same provider.
   */
  public function testAFidOfALogoIsFileNotFound() {
    $this->seedRequest([
      'field_data_field_gallery' => [],
      'field_data_field_logo'    => [
        [
          'entity_id'      => self::PROVIDER,
          'entity_type'    => 'node',
          'deleted'        => 0,
          'delta'          => 0,
          'field_logo_fid' => 55,
          'fid'            => 55,
          'uri'            => 'public://logo-taller-el-sauco.png',
        ],
      ],
    ]);
    // Perfectly loadable, like the claim file above: the only thing refusing it
    // is the membership check.
    myapi_test_file_seed([55 => [
      'fid' => 55, 'uri' => __FILE__,
      'filemime' => 'image/png', 'filesize' => 100, 'filename' => 'logo-taller-el-sauco.png',
    ]]);

    $result = $this->download((string) self::PROVIDER, '55');

    $this->assertSame(404, $result['status']);
    $this->assertSame('file_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers(), 'a logo is never streamed by the gallery route');
  }

  /**
   * A file_managed row pointing at bytes that are not on disk answers 404 too,
   * never a 200 of zero bytes and never a PHP warning out of fopen().
   */
  public function testAFileMissingFromDiskAnswersFileNotFound() {
    $this->seedRequest(['field_data_field_gallery' => [$this->galleryRow('42')]]);
    myapi_test_file_seed([42 => [
      'fid' => 42, 'uri' => '/tmp/a-file-that-does-not-exist-' . __LINE__,
      'filemime' => 'image/jpeg', 'filesize' => 10, 'filename' => 'taller-01.jpg',
    ]]);

    $result = $this->download('7', '42');

    $this->assertSame(404, $result['status']);
    $this->assertSame('file_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers());
  }

  /**
   * The success path: the bytes are streamed with the four documented headers
   * and NO JSON envelope — the second endpoint of the module that answers
   * something else, on purpose (Regla 4 de CLAUDE.md, broken knowingly).
   *
   * The fixture points at this very test file so file_exists() is true without
   * writing anything to disk.
   */
  public function testAValidRequestStreamsTheImageWithoutAnEnvelope() {
    $this->seedRequest(['field_data_field_gallery' => [$this->galleryRow('42')]]);
    myapi_test_file_seed([42 => [
      'fid' => 42, 'uri' => __FILE__,
      'filemime' => 'image/jpeg', 'filesize' => 20481, 'filename' => 'taller-01.jpg',
    ]]);

    $result = $this->download('7', '42');

    $this->assertTrue($result['exited']);
    $this->assertSame('', $result['output'], 'no envelope, no body of our own');
    $this->assertNull($result['status']);

    $transfers = myapi_test_file_transfers();
    $this->assertCount(1, $transfers);
    $this->assertSame(__FILE__, $transfers[0]['uri']);
    $this->assertSame([
      'Content-Type'        => 'image/jpeg',
      'Content-Length'      => 20481,
      'Content-Disposition' => 'inline; filename="taller-01.jpg"',
      'Cache-Control'       => 'private, no-store',
    ], $transfers[0]['headers']);
  }

  /* -------------------------------------------------------------------------
   * myapi_provider_file_provider_nid() / _fid_by_uri() — the shared resolution.
   * ---------------------------------------------------------------------- */

  /**
   * A fid that cannot be one answers NULL before any query: the guard that
   * keeps a route argument of '0' or 'abc' from reaching the database.
   */
  public function testAnImpossibleFidAnswersNullWithoutAQuery() {
    foreach ([0, -3, 'abc', '', NULL] as $fid) {
      myapi_test_db_seed();

      $this->assertNull(myapi_provider_file_provider_nid($fid), var_export($fid, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($fid, TRUE));
    }
  }

  public function testAnImageOfAProviderResolvesToThatProvider() {
    myapi_test_db_seed(['field_data_field_gallery' => [$this->galleryRow('42')]]);

    $this->assertSame(self::PROVIDER, myapi_provider_file_provider_nid('42'));
  }

  /**
   * The same field attached to another bundle by a future spec is not a
   * provider's file — the INNER JOIN to node restricts it to 'provider'.
   */
  public function testAGalleryRowOnAnotherBundleResolvesToNothing() {
    myapi_test_db_seed(['field_data_field_gallery' => [
      $this->galleryRow('42', 300, 0, 'x.jpg', 'service_request'),
    ]]);

    $this->assertNull(myapi_provider_file_provider_nid('42'));
  }

  /**
   * Deleted rows and rows of another entity type never resolve, and neither
   * does a fid nothing points at — which is what keeps both consumers off
   * files that are not this module's.
   */
  public function testDeletedRowsForeignEntitiesAndUnknownFidsResolveToNothing() {
    myapi_test_db_seed(['field_data_field_gallery' => [
      ['entity_id' => self::PROVIDER, 'entity_type' => 'node', 'deleted' => 1, 'field_gallery_fid' => 42, 'nid' => self::PROVIDER, 'type' => 'provider'],
    ]]);
    $this->assertNull(myapi_provider_file_provider_nid(42), 'deleted');

    myapi_test_db_seed(['field_data_field_gallery' => [
      ['entity_id' => self::PROVIDER, 'entity_type' => 'user', 'deleted' => 0, 'field_gallery_fid' => 42, 'nid' => self::PROVIDER, 'type' => 'provider'],
    ]]);
    $this->assertNull(myapi_provider_file_provider_nid(42), 'entity_type');

    myapi_test_db_seed(['field_data_field_gallery' => [$this->galleryRow('42')]]);
    $this->assertNull(myapi_provider_file_provider_nid(999), 'unknown fid');
  }

  /**
   * The resolution is deterministic when a fid is referenced more than once:
   * ORDER BY n.nid ASC, one row. It is the only thing that makes two identical
   * requests answer the same provider.
   */
  public function testTheOwnershipQueryIsOrderedAndLimitedToOneRow() {
    myapi_test_db_seed(['field_data_field_gallery' => [$this->galleryRow('42')]]);

    myapi_provider_file_provider_nid(42);

    $query = myapi_test_db_queries('field_data_field_gallery')[0];
    $this->assertSame([['field' => 'n.nid', 'direction' => 'ASC']], $query['order']);
    $this->assertSame(['start' => 0, 'length' => 1], $query['range']);
  }

  public function testAnEmptyOrNonStringUriAnswersNullWithoutAQuery() {
    foreach (['', NULL, 42, ['private://x']] as $uri) {
      myapi_test_db_seed();

      $this->assertNull(myapi_provider_file_fid_by_uri($uri), var_export($uri, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($uri, TRUE));
    }
  }

  /**
   * A known URI answers its fid as an int; an unknown one — an image-style
   * derivative, a payment receipt — answers NULL, which is the normal outcome
   * since the hook fires for every private file of the site.
   */
  public function testAKnownUriAnswersItsFidAndAnUnknownOneNull() {
    myapi_test_db_seed(['file_managed' => [
      ['fid' => '42', 'uri' => 'private://gallery/taller-01.jpg'],
    ]]);

    $this->assertSame(42, myapi_provider_file_fid_by_uri('private://gallery/taller-01.jpg'));
    $this->assertNull(myapi_provider_file_fid_by_uri('private://styles/thumbnail/gallery/taller-01.jpg'));
    $this->assertNull(myapi_provider_file_fid_by_uri('private://comprobantes_pago/recibo.pdf'));
  }

  /* -------------------------------------------------------------------------
   * myapi_provider_file_download_headers() — the back office's half.
   * ---------------------------------------------------------------------- */

  /**
   * A URI that belongs to no gallery answers NULL — "somebody else decides" —
   * and costs at most the two lookups, because the hook fires for every
   * private file of the site.
   */
  public function testAForeignUriAnswersNull() {
    myapi_test_db_seed(['file_managed' => []]);
    $this->assertNull(myapi_provider_file_download_headers('private://comprobantes_pago/r.pdf', $this->account(1)));

    myapi_test_db_seed([
      'file_managed'             => [['fid' => '42', 'uri' => 'private://otros/x.pdf']],
      'field_data_field_gallery' => [],
    ]);
    $this->assertNull(myapi_provider_file_download_headers('private://otros/x.pdf', $this->account(1)));
    $this->assertCount(2, myapi_test_db_queries(), 'the fid lookup plus the ownership one');
  }

  /**
   * A LOGO uri (SPEC 85) answers NULL here too, and that is not a gap: the hook
   * only ever fires for PRIVATE files, and a public:// logo is served by the web
   * server without PHP ever being asked. Even seeded as a real file_managed row
   * of this provider, it belongs to no gallery, so the ownership resolution says
   * "not mine" and nobody claims it.
   *
   * The case exists because the alternative would be silent: a logo that this
   * module decided to own would be a public file gaining an access check it does
   * not need, on the hot path of every private download of the site.
   */
  public function testALogoUriIsClaimedByNobody() {
    myapi_test_db_seed([
      'file_managed'             => [['fid' => '55', 'uri' => 'public://logo-taller-el-sauco.png']],
      'field_data_field_gallery' => [],
    ]);

    $this->assertNull(myapi_provider_file_download_headers('public://logo-taller-el-sauco.png', $this->account(41)));
  }

  /**
   * The access decision is node_access('view', $provider) and nothing else:
   * SPEC 78 already decides who sees a provider node, and a second role
   * catalogue here would be two truths to keep in sync. A denial is a hard -1,
   * not a NULL that another module could turn into a grant.
   */
  public function testADeniedGalleryFileIsAHardDeny() {
    myapi_test_db_seed([
      'file_managed'             => [['fid' => '42', 'uri' => 'private://gallery/taller-01.jpg']],
      'field_data_field_gallery' => [$this->galleryRow('42')],
    ]);
    myapi_test_node_seed([self::PROVIDER => ['nid' => self::PROVIDER, 'type' => 'provider']]);
    $GLOBALS['myapi_test_node_access']['view:' . self::PROVIDER] = FALSE;

    $this->assertSame(-1, myapi_provider_file_download_headers('private://gallery/taller-01.jpg', $this->account(41)));
  }

  /**
   * With access, the headers — 'inline' and not 'attachment', because the back
   * office renders these images on screen in the gallery widget and forcing a
   * download on every thumbnail would make the form unusable.
   */
  public function testAnAllowedGalleryFileAnswersInlineHeaders() {
    myapi_test_db_seed([
      'file_managed'             => [['fid' => '42', 'uri' => 'private://gallery/taller-01.jpg']],
      'field_data_field_gallery' => [$this->galleryRow('42')],
    ]);
    myapi_test_node_seed([self::PROVIDER => ['nid' => self::PROVIDER, 'type' => 'provider']]);
    myapi_test_file_seed([42 => [
      'fid' => 42, 'uri' => 'private://gallery/taller-01.jpg',
      'filemime' => 'image/jpeg', 'filesize' => 20481, 'filename' => 'taller-01.jpg',
    ]]);
    $GLOBALS['myapi_test_node_access']['view:' . self::PROVIDER] = TRUE;

    $headers = myapi_provider_file_download_headers('private://gallery/taller-01.jpg', $this->account(1));

    $this->assertSame([
      'Content-Type'        => 'image/jpeg',
      'Content-Length'      => 20481,
      'Content-Disposition' => 'inline; filename="taller-01.jpg"',
    ], $headers);
  }

  /**
   * A provider node that no longer loads, and a file_managed row whose file
   * object no longer loads, both answer NULL rather than headers describing
   * nothing — or, worse, a grant with no node to have decided it.
   */
  public function testAnUnloadableProviderOrFileAnswersNull() {
    myapi_test_db_seed([
      'file_managed'             => [['fid' => '42', 'uri' => 'private://gallery/taller-01.jpg']],
      'field_data_field_gallery' => [$this->galleryRow('42')],
    ]);
    myapi_test_node_seed();
    $this->assertNull(myapi_provider_file_download_headers('private://gallery/taller-01.jpg', $this->account(1)), 'no node');

    myapi_test_node_seed([self::PROVIDER => ['nid' => self::PROVIDER, 'type' => 'provider']]);
    myapi_test_file_seed();
    $this->assertNull(myapi_provider_file_download_headers('private://gallery/taller-01.jpg', $this->account(1)), 'no file');
  }

  /* -------------------------------------------------------------------------
   * hook_file_download() with TWO owners — the expensive part of SPEC 82.
   * ---------------------------------------------------------------------- */

  /**
   * THE regression this spec can introduce, and the reason the cut is written
   * `$headers !== NULL`: a claim file the account may not read is already a
   * decision — a -1 — and it must NOT fall through to the provider owner, who
   * would answer NULL and let the denial become somebody else's permission.
   *
   * Asserted twice: the -1 survives, and the gallery table is never even
   * queried.
   */
  public function testADeniedClaimFileNeverFallsThroughToProviders() {
    myapi_test_db_seed([
      'file_managed'            => [['fid' => '7', 'uri' => 'private://claims/fuga.jpg']],
      'field_data_field_images' => [
        ['entity_id' => 140, 'entity_type' => 'node', 'deleted' => 0, 'field_images_fid' => 7, 'nid' => 140, 'type' => 'reclamo', 'claim_nid' => NULL],
      ],
    ]);
    // An account with no back-office role at all: the claims rule denies it.
    $GLOBALS['user'] = $this->account(5, [2 => 'authenticated user']);

    $this->assertSame(-1, myapi_file_download('private://claims/fuga.jpg'));
    $this->assertSame([], myapi_test_db_queries('field_data_field_gallery'));
  }

  /**
   * An ALLOWED claim file still answers its headers: the first owner keeps
   * deciding everything it recognises, exactly as before SPEC 82.
   */
  public function testAnAllowedClaimFileStillAnswersItsHeaders() {
    myapi_test_db_seed([
      'file_managed'            => [['fid' => '7', 'uri' => 'private://claims/fuga.jpg']],
      'field_data_field_images' => [
        ['entity_id' => 140, 'entity_type' => 'node', 'deleted' => 0, 'field_images_fid' => 7, 'nid' => 140, 'type' => 'reclamo', 'claim_nid' => NULL],
      ],
    ]);
    myapi_test_file_seed([7 => [
      'fid' => 7, 'uri' => 'private://claims/fuga.jpg',
      'filemime' => 'image/jpeg', 'filesize' => 100, 'filename' => 'fuga.jpg',
    ]]);
    $GLOBALS['user'] = $this->account(1);

    $headers = myapi_file_download('private://claims/fuga.jpg');

    $this->assertSame('inline; filename="fuga.jpg"', $headers['Content-Disposition']);
    $this->assertSame([], myapi_test_db_queries('field_data_field_gallery'), 'claims answered, so providers were never asked');
  }

  /**
   * A gallery file reaches the SECOND owner and is served: the chain only
   * falls through when claims answered NULL, which is what "not mine" means.
   */
  public function testAGalleryFileIsAnsweredByTheSecondOwner() {
    myapi_test_db_seed([
      'file_managed'             => [['fid' => '42', 'uri' => 'private://gallery/taller-01.jpg']],
      'field_data_field_images'  => [],
      'field_data_field_gallery' => [$this->galleryRow('42')],
    ]);
    myapi_test_node_seed([self::PROVIDER => ['nid' => self::PROVIDER, 'type' => 'provider']]);
    myapi_test_file_seed([42 => [
      'fid' => 42, 'uri' => 'private://gallery/taller-01.jpg',
      'filemime' => 'image/jpeg', 'filesize' => 20481, 'filename' => 'taller-01.jpg',
    ]]);
    $GLOBALS['user'] = $this->account(1);
    $GLOBALS['myapi_test_node_access']['view:' . self::PROVIDER] = TRUE;

    $headers = myapi_file_download('private://gallery/taller-01.jpg');

    $this->assertSame('inline; filename="taller-01.jpg"', $headers['Content-Disposition']);
  }

  /**
   * And a file NONE of the owners recognises — a payment receipt in
   * private://comprobantes_pago, an area photo, another module's file — is
   * still NULL, which is what keeps the rest of the site behaving exactly as
   * it did before this spec.
   *
   * Since SPEC 89 the chain has a third link (service request files), and the
   * seeding below grew field_data_field_attachment for it: the assertion is
   * "nobody claims this receipt", so every field the chain looks into has to be
   * present and empty, or a missing fixture table would be doing the work.
   */
  public function testAFileOfNeitherOwnerIsStillNull() {
    myapi_test_db_seed([
      'file_managed'                => [['fid' => '90', 'uri' => 'private://comprobantes_pago/recibo.pdf']],
      'field_data_field_images'     => [],
      'field_data_field_gallery'    => [],
      'field_data_field_attachment' => [],
    ]);
    $GLOBALS['user'] = $this->account(1);

    $this->assertNull(myapi_file_download('private://comprobantes_pago/recibo.pdf'));
  }

}
