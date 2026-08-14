<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_files.inc';
require_once __DIR__ . '/../../includes/myapi.provider_query.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../resources/provider.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/providers/% (SPEC 84).
 *
 * myapi_provider_detail_dispatch() is called the way hook_menu() calls it,
 * over a fixture `node` table (which carries both the provider row and the
 * service_rating rows, exactly as the real table holds every content type),
 * a fixture my_api_tokens row and a fixture Authorization header.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, same convention as
 * ProviderListEndpointTest and ProviderGalleryEndpointTest: a provider or a
 * rating is seeded flat, carrying its own node columns plus the value each
 * JOIN would have brought, under the alias the query gives it. The one
 * exception is myapi_provider_rating_summary(): being a GROUPED query, its
 * fixture rows are read by the BARE column name ('field_stars_value'), not
 * the alias ('stars') — both are seeded on every rating row so the same
 * fixture answers both queries.
 *
 * What this suite does NOT prove, for the same reason as the two suites
 * above: that an INNER JOIN really drops an orphan row in real SQL — joins
 * are recorded and never resolved by the fixture builder, so what is
 * asserted is the shape of the join (INNER, on that table), and the dropping
 * itself is the database's to prove, in the manual acceptance pass.
 */
class ProviderDetailEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';
  const UID = 3;
  const PROVIDER = 41;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    unset($GLOBALS['myapi_test_profile_fields']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    unset($GLOBALS['myapi_test_profile_fields']);
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_static_reset();
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
   * The provider row, as myapi_provider_detail_fetch()'s six LEFT JOINs
   * deliver it: the provider's own columns plus the alias each join gives
   * its field ('rating_avg', not 'field_rating_avg_value' — see the class
   * docblock). No field_license_expiry anywhere: the detail never joins it.
   */
  private function providerNode($overrides = []) {
    return $overrides + [
      'nid'                => (string) self::PROVIDER,
      'type'               => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'             => '1',
      'title'              => 'Plomería Torres',
      'rating_avg'         => '4.90',
      'rating_count'       => '2',
      'short_description'  => 'Destapes y reparaciones.',
      'hourly_rate'        => '25.50',
      'address'            => 'Av. Siempre Viva 123',
      'description'        => 'Instalaciones eléctricas residenciales.',
    ];
  }

  /**
   * One row of field_data_field_categories, joined to its term — copied from
   * ProviderListEndpointTest: myapi_provider_categories_by_nid() is reused
   * unchanged by the detail, so its fixture shape is unchanged too.
   */
  private function categoryRow($nid, $tid, $name, $code = 'code', $delta = 0) {
    return [
      'entity_id'            => (string) $nid,
      'entity_type'          => 'node',
      'deleted'              => '0',
      'delta'                => (string) $delta,
      'field_categories_tid' => (string) $tid,
      'tid'                  => (string) $tid,
      'name'                 => $name,
      'code'                 => $code,
    ];
  }

  /**
   * One row of field_data_field_tags, joined to its term.
   */
  private function tagRow($nid, $tid, $name, $delta = 0) {
    return [
      'entity_id'      => (string) $nid,
      'entity_type'    => 'node',
      'deleted'        => '0',
      'delta'          => (string) $delta,
      'field_tags_tid' => (string) $tid,
      'tid'            => (string) $tid,
      'name'           => $name,
    ];
  }

  /**
   * One row of field_data_field_gallery, joined to file_managed — same shape
   * ProviderGalleryEndpointTest seeds, minus the node columns
   * myapi_provider_gallery_images() never joins.
   */
  private function galleryRow($fid, $delta = 0, $filename = 'taller-01.jpg') {
    return [
      'entity_id'         => (string) self::PROVIDER,
      'entity_type'       => 'node',
      'deleted'           => '0',
      'delta'             => (string) $delta,
      'field_gallery_fid' => (string) $fid,
      'fid'               => (string) $fid,
      'filename'          => $filename,
    ];
  }

  /**
   * One 'node' row of type service_rating, carrying BOTH the alias
   * ('stars') myapi_provider_ratings_recent() projects and the bare column
   * ('field_stars_value') myapi_provider_rating_summary()'s GROUP BY reads —
   * the same underlying value, seeded twice because the two queries resolve
   * a fixture row differently (see the class docblock).
   */
  private function ratingNode($nid, $stars = 5, array $overrides = []) {
    return $overrides + [
      'nid'                              => (string) $nid,
      'type'                             => MYAPI_SERVICES_RATING_TYPE,
      'uid'                              => (string) self::UID,
      'created'                          => (string) (REQUEST_TIME - 3600),
      'field_rating_provider_target_id'  => (string) self::PROVIDER,
      'stars'                            => (string) $stars,
      'field_stars_value'                => (string) $stars,
      'comment'                          => 'Excelente atención.',
      'unit_nid'                         => NULL,
    ];
  }

  /**
   * The reader's token and every table one request may need, with the
   * provider always present in 'node' and any extra rows (ratings) merged
   * alongside it — 'node' holds every content type, exactly like the real
   * table.
   */
  private function seedRequest(array $provider_overrides = [], array $extra_nodes = [], array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'node'          => array_merge([$this->providerNode($provider_overrides)], $extra_nodes),
    ] + $tables);
  }

  private function detail($id) {
    return myapi_test_capture(function () use ($id) {
      myapi_provider_detail_dispatch($id);
    });
  }

  private function data(array $result) {
    return $result['json']['data'];
  }

  /* -------------------------------------------------------------------------
   * Method routing and authentication.
   * ---------------------------------------------------------------------- */

  /**
   * The detail is read-only: the method is checked BEFORE the token, so a
   * POST answers 405 with no credentials at all.
   */
  public function testEveryMethodOtherThanGetIs405BeforeAuthentication() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->detail((string) self::PROVIDER);

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame([], myapi_test_db_queries(), $method . ' must not reach the database');
    }
  }

  public function testMissingAuthorizationHeaderIs401() {
    myapi_test_db_seed(['node' => [$this->providerNode()]]);

    $result = $this->detail((string) self::PROVIDER);

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

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

      $result = $this->detail((string) self::PROVIDER);

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
    }
  }

  public function testABlockedUsersTokenAnswers401() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 0];
    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'node'          => [$this->providerNode()],
    ]);

    $result = $this->detail((string) self::PROVIDER);

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * Existence and the "published" rule.
   * ---------------------------------------------------------------------- */

  public function testAMissingForeignOrUnpublishedProviderAnswersProviderNotFound() {
    $cases = [
      'missing'     => [],
      'other type'  => [$this->providerNode(['type' => 'service_request'])],
      'unpublished' => [$this->providerNode(['status' => '0'])],
    ];

    foreach ($cases as $name => $nodes) {
      $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
      $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];
      myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()], 'node' => $nodes]);

      $result = $this->detail((string) self::PROVIDER);

      $this->assertSame(404, $result['status'], $name);
      $this->assertSame('provider_not_found', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries('field_data_field_tags'), $name . ': the rest of the ficha was never queried');
    }
  }

  public function testAMalformedProviderIdAnswersProviderNotFoundWithoutQueryingNode() {
    foreach (['abc', '0', '-1', '7a', NULL] as $id) {
      $this->seedRequest();

      $result = $this->detail($id);

      $this->assertSame(404, $result['status'], var_export($id, TRUE));
      $this->assertSame('provider_not_found', $result['json']['error_code'], var_export($id, TRUE));
      $this->assertSame([], myapi_test_db_queries('node'), var_export($id, TRUE));
    }
  }

  /**
   * The detail never joins field_license_expiry: a lapsed or altogether
   * absent licence still answers 200, unlike the listing (SPEC 83).
   */
  public function testALapsedLicenceStillAnswers200AndTheFieldIsNeverQueried() {
    $this->seedRequest([], [], [
      'field_data_field_license_expiry' => [
        ['entity_id' => (string) self::PROVIDER, 'field_license_expiry_value' => (string) (REQUEST_TIME - 86400)],
      ],
    ]);

    $result = $this->detail((string) self::PROVIDER);

    $this->assertSame(200, $result['status']);
    $this->assertSame([], myapi_test_db_queries('field_data_field_license_expiry'));
  }

  /* -------------------------------------------------------------------------
   * The shape of the item.
   * ---------------------------------------------------------------------- */

  public function testTheItemCarriesExactlyThirteenKeysInOrder() {
    $this->seedRequest();

    $item = $this->data($this->detail((string) self::PROVIDER));

    $this->assertSame([
      'id', 'title', 'categories', 'rating_avg', 'rating_count',
      'short_description', 'hourly_rate', 'address', 'description', 'tags',
      'gallery', 'ratings', 'rating_summary',
    ], array_keys($item));
  }

  public function testDataIsTheProviderDirectlyWithNoWrapper() {
    $this->seedRequest();

    $result = $this->detail((string) self::PROVIDER);

    $this->assertTrue($result['json']['success']);
    $this->assertSame(self::PROVIDER, $result['json']['data']['id']);
    $this->assertArrayNotHasKey('provider', $result['json']['data']);
    $this->assertArrayNotHasKey('pagination', $result['json']['data']);
  }

  /**
   * A provider with none of the optional data answers every documented
   * empty value at once — never a PHP notice, never NULL where the spec
   * promises an empty string, array or zero.
   */
  public function testAProviderWithNothingOptionalAnswersTheDocumentedEmptyValues() {
    $this->seedRequest([
      'rating_avg'        => NULL,
      'rating_count'      => NULL,
      'short_description' => NULL,
      'hourly_rate'       => NULL,
      'address'           => NULL,
      'description'       => NULL,
    ]);

    $item = $this->data($this->detail((string) self::PROVIDER));

    $this->assertNull($item['rating_avg']);
    $this->assertSame(0, $item['rating_count']);
    $this->assertSame('', $item['short_description']);
    $this->assertNull($item['hourly_rate']);
    $this->assertSame('', $item['address']);
    $this->assertSame('', $item['description']);
    $this->assertSame([], $item['categories']);
    $this->assertSame([], $item['tags']);
    $this->assertSame([], $item['gallery']);
    $this->assertSame([], $item['ratings']);
    $this->assertSame(['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0], $item['rating_summary']);
  }

  public function testRatingAvgAndHourlyRateAreFloatsNeverZero() {
    $this->seedRequest(['rating_avg' => '4.90', 'hourly_rate' => '25.50']);

    $item = $this->data($this->detail((string) self::PROVIDER));

    $this->assertSame(4.9, $item['rating_avg']);
    $this->assertIsFloat($item['rating_avg']);
    $this->assertSame(25.5, $item['hourly_rate']);
    $this->assertIsFloat($item['hourly_rate']);
  }

  /* -------------------------------------------------------------------------
   * address and description.
   * ---------------------------------------------------------------------- */

  public function testAddressAndDescriptionStripMarkupAndDecodeEntities() {
    $this->seedRequest([
      'address'     => '<p>Av.&nbsp;Siempre Viva <b>123</b></p>',
      'description' => '<p>Tableros &amp; iluminación.</p>',
    ]);

    $item = $this->data($this->detail((string) self::PROVIDER));

    $this->assertSame('Av. Siempre Viva 123', $item['address']);
    $this->assertSame('Tableros & iluminación.', $item['description']);
  }

  /* -------------------------------------------------------------------------
   * tags.
   * ---------------------------------------------------------------------- */

  public function testTagsAreStringsInDeltaOrder() {
    $this->seedRequest([], [], [
      'field_data_field_tags' => [
        $this->tagRow(self::PROVIDER, 9, '24h', 1),
        $this->tagRow(self::PROVIDER, 7, 'urgencias', 0),
        $this->tagRow(self::PROVIDER, 11, 'certificado', 2),
      ],
    ]);

    $item = $this->data($this->detail((string) self::PROVIDER));

    $this->assertSame(['urgencias', '24h', 'certificado'], $item['tags']);
  }

  public function testTagNamesAreEscaped() {
    $this->seedRequest([], [], [
      'field_data_field_tags' => [$this->tagRow(self::PROVIDER, 7, 'Gas & Plomería')],
    ]);

    $item = $this->data($this->detail((string) self::PROVIDER));

    $this->assertSame(['Gas &amp; Plomería'], $item['tags']);
  }

  /**
   * The shape assertion that stands for "an orphan tid is dropped in
   * silence": the fixture builder cannot make a recorded join fail to
   * match, so what is pinned here is that the join really is INNER, on
   * taxonomy_term_data — a LEFT one would let an orphan through with a null
   * name instead of omitting it. Same criterion as
   * ProviderListEndpointTest::testTheCategoryQueryJoinsTheTermInnerAndTheCodeLeft().
   */
  public function testTheTagsQueryJoinsTheTermInner() {
    $this->seedRequest([], [], [
      'field_data_field_tags' => [$this->tagRow(self::PROVIDER, 7, 'urgencias')],
    ]);

    $this->detail((string) self::PROVIDER);

    $query = myapi_test_db_queries('field_data_field_tags')[0];
    $this->assertSame(['taxonomy_term_data'], array_column($query['joins'], 'table'));
    $this->assertSame(['INNER'], array_column($query['joins'], 'type'));
    $this->assertSame([['field' => 'ft.delta', 'direction' => 'ASC']], $query['order']);
  }

  /* -------------------------------------------------------------------------
   * gallery.
   * ---------------------------------------------------------------------- */

  public function testGalleryCarriesIdUrlAndFilenameInDeltaOrder() {
    $this->seedRequest([], [], [
      'field_data_field_gallery' => [
        $this->galleryRow(44, 2, 'tercera.jpg'),
        $this->galleryRow(42, 0, 'primera.jpg'),
        $this->galleryRow(43, 1, 'segunda.jpg'),
      ],
    ]);

    $gallery = $this->data($this->detail((string) self::PROVIDER))['gallery'];

    $this->assertSame(['primera.jpg', 'segunda.jpg', 'tercera.jpg'], array_column($gallery, 'filename'));
    $this->assertSame(['id', 'url', 'filename'], array_keys($gallery[0]));
    $this->assertSame(42, $gallery[0]['id']);
    $this->assertSame(
      'https://crespcord.example.com/api/v1/providers/' . self::PROVIDER . '/gallery/42',
      $gallery[0]['url']
    );
  }

  /**
   * The same query myapi_provider_gallery_images() runs for
   * GET /api/v1/providers/%/gallery — extracted in step 3 precisely so the
   * two routes can never disagree about which images a provider has.
   */
  public function testGalleryReusesTheSameExtractedQueryAsTheGalleryEndpoint() {
    $this->seedRequest([], [], [
      'field_data_field_gallery' => [$this->galleryRow(42)],
    ]);

    $this->detail((string) self::PROVIDER);

    $query = myapi_test_db_queries('field_data_field_gallery')[0];
    $this->assertSame(['file_managed'], array_column($query['joins'], 'table'));
    $this->assertSame(['INNER'], array_column($query['joins'], 'type'));
  }

  /* -------------------------------------------------------------------------
   * ratings.
   * ---------------------------------------------------------------------- */

  public function testOnlyTheLastThreeRatingsComeBackNewestFirst() {
    $this->seedRequest([], [
      $this->ratingNode(101, 5, ['created' => (string) (REQUEST_TIME - 400), 'comment' => 'la mas nueva']),
      $this->ratingNode(102, 4, ['created' => (string) (REQUEST_TIME - 300), 'comment' => 'segunda']),
      $this->ratingNode(103, 3, ['created' => (string) (REQUEST_TIME - 200), 'comment' => 'tercera']),
      $this->ratingNode(104, 2, ['created' => (string) (REQUEST_TIME - 100), 'comment' => 'la mas reciente']),
    ]);

    $ratings = $this->data($this->detail((string) self::PROVIDER))['ratings'];

    $this->assertCount(3, $ratings);
    $this->assertSame(
      ['la mas reciente', 'tercera', 'segunda'],
      array_column($ratings, 'comment')
    );
  }

  public function testEachRatingItemCarriesExactlyFiveKeys() {
    $this->seedRequest([], [$this->ratingNode(101, 5)]);

    $rating = $this->data($this->detail((string) self::PROVIDER))['ratings'][0];

    $this->assertSame(['stars', 'comment', 'author_name', 'unit', 'created'], array_keys($rating));
    $this->assertIsInt($rating['stars']);
  }

  public function testAnEmptyCommentAnswersEmptyStringNeverNull() {
    $this->seedRequest([], [$this->ratingNode(101, 5, ['comment' => NULL])]);

    $rating = $this->data($this->detail((string) self::PROVIDER))['ratings'][0];

    $this->assertSame('', $rating['comment']);
  }

  public function testCreatedUsesTheDocumentedFormat() {
    $timestamp = mktime(18, 4, 0, 6, 12, 2026);
    $this->seedRequest([], [$this->ratingNode(101, 5, ['created' => (string) $timestamp])]);

    $rating = $this->data($this->detail((string) self::PROVIDER))['ratings'][0];

    $this->assertSame('2026-06-12T18:04:00', $rating['created']);
  }

  /**
   * The abbreviated author name, resolved from the SPEC 54 profile pair —
   * first name plus the initial of the last name, never the full name
   * myapi_claim_notification.inc builds for requester_name.
   */
  public function testAuthorNameIsAbbreviatedFromTheProfile() {
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Andrés', 'last_name' => 'Muñoz'];
    $this->seedRequest([], [$this->ratingNode(101, 5)]);

    $rating = $this->data($this->detail((string) self::PROVIDER))['ratings'][0];

    $this->assertSame('Andrés M.', $rating['author_name']);
  }

  public function testAuthorNameFallsBackToTheUsernameWithNoProfile() {
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => NULL, 'last_name' => NULL];
    $this->seedRequest([], [$this->ratingNode(101, 5)]);
    $GLOBALS['myapi_test_users'][self::UID]['name'] = 'atecnico3';

    $rating = $this->data($this->detail((string) self::PROVIDER))['ratings'][0];

    $this->assertSame('atecnico3', $rating['author_name']);
  }

  public function testAuthorNameFallsBackToDeletedAccountWhenTheUserNoLongerExists() {
    $this->seedRequest([], [$this->ratingNode(101, 5, ['uid' => '999'])]);

    $rating = $this->data($this->detail((string) self::PROVIDER))['ratings'][0];

    $this->assertSame('Usuario eliminado', $rating['author_name']);
  }

  public function testUnitIsNullWithoutFieldUnitAndTheUnitTitleWithIt() {
    $this->seedRequest([], [
      $this->ratingNode(101, 5, ['unit_nid' => NULL, 'comment' => 'sin unidad']),
      $this->ratingNode(102, 4, ['unit_nid' => '55', 'created' => (string) (REQUEST_TIME - 10), 'comment' => 'con unidad']),
    ]);
    myapi_test_node_seed([55 => ['nid' => 55, 'type' => 'vivienda', 'title' => '4B']]);

    $ratings = $this->data($this->detail((string) self::PROVIDER))['ratings'];

    $withUnit = current(array_filter($ratings, function ($r) { return $r['comment'] === 'con unidad'; }));
    $withoutUnit = current(array_filter($ratings, function ($r) { return $r['comment'] === 'sin unidad'; }));

    $this->assertSame('4B', $withUnit['unit']);
    $this->assertNull($withoutUnit['unit']);
  }

  /* -------------------------------------------------------------------------
   * rating_summary.
   * ---------------------------------------------------------------------- */

  public function testRatingSummaryCountsTheWholeHistoryNotJustTheThreeShown() {
    $this->seedRequest(['rating_count' => '4'], [
      $this->ratingNode(101, 5),
      $this->ratingNode(102, 5),
      $this->ratingNode(103, 4),
      $this->ratingNode(104, 3),
    ]);

    $item = $this->data($this->detail((string) self::PROVIDER));

    $this->assertCount(3, $item['ratings'], 'only the three most recent travel in ratings');
    $this->assertSame(['1' => 0, '2' => 0, '3' => 1, '4' => 1, '5' => 2], $item['rating_summary']);
    $this->assertSame(
      $item['rating_count'],
      array_sum($item['rating_summary']),
      'the summary always sums to rating_count, not to count(ratings)'
    );
  }

}
