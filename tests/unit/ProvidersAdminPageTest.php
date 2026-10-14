<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../resources/reservation.resource.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_calendar.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.service_requests_admin.inc';
require_once __DIR__ . '/../../includes/myapi.providers_admin.inc';

/**
 * Unit tests for the back-office provider listing (SPEC 131):
 * includes/myapi.providers_admin.inc.
 *
 * Same division of labour as ServiceRequestsAdminPageTest: the page and the
 * form are drupal_get_form() and theme('pager'), everything that decides
 * something is here.
 *
 * The cases worth more than they look:
 *
 *  - The situation borders. The rule is SPEC 83's "active provider" — published
 *    and field_license_expiry >= REQUEST_TIME — so a licence expiring exactly
 *    now is NOT expired, and one expiring in exactly 30 days is "Vence pronto".
 *    The PHP function and the SQL conditions are tested over the same borders,
 *    because the filter and the cell must never disagree.
 *  - testAnUnpublishedProviderIsListed(). Unlike the other three listings, this
 *    one does not filter n.status: a suspended provider is the one the
 *    operator needs to find.
 *  - testTheMultivalueFieldsAreNeverJoinedToThePagedQuery(). Joining
 *    field_categories would multiply rows and break the pager.
 */
class ProvidersAdminPageTest extends TestCase {

  const NOW = 1791000000;

  const DAY = 86400;

  protected function setUp(): void {
    $this->reset();
  }

  protected function tearDown(): void {
    $this->reset();
  }

  private function reset() {
    myapi_test_db_seed();
    myapi_test_static_reset();
    myapi_test_taxonomy_seed();
    myapi_test_node_seed();
    $GLOBALS['myapi_test_permissions'] = [];
    $GLOBALS['myapi_test_node_access'] = [];
    $GLOBALS['myapi_test_node_access_calls'] = [];
    $GLOBALS['myapi_test_node_load_multiple'] = [];
    unset($GLOBALS['myapi_test_node_access_default']);
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * The situation, in PHP.
   * ---------------------------------------------------------------------- */

  public function testTheFiveSituationsInPriorityOrder() {
    $this->assertSame('suspended', myapi_providers_admin_situation(0, NULL, self::NOW), 'unpublished wins over everything');
    $this->assertSame('suspended', myapi_providers_admin_situation(0, self::NOW + 90 * self::DAY, self::NOW));
    $this->assertSame('no_license', myapi_providers_admin_situation(1, NULL, self::NOW));
    $this->assertSame('no_license', myapi_providers_admin_situation(1, '', self::NOW));
    $this->assertSame('expired', myapi_providers_admin_situation(1, self::NOW - 1, self::NOW));
    $this->assertSame('expiring', myapi_providers_admin_situation(1, self::NOW + 10 * self::DAY, self::NOW));
    $this->assertSame('active', myapi_providers_admin_situation(1, self::NOW + 90 * self::DAY, self::NOW));
  }

  /**
   * Expiring exactly now is still valid (SPEC 83's >=), so it is not expired.
   */
  public function testALicenceExpiringExactlyNowIsNotExpired() {
    $this->assertSame('expiring', myapi_providers_admin_situation(1, self::NOW, self::NOW));
  }

  /**
   * The 30-day window is closed: exactly 30 days out is "Vence pronto", one
   * second more is "Activo".
   */
  public function testTheThirtyDayBorder() {
    $this->assertSame('expiring', myapi_providers_admin_situation(1, self::NOW + 30 * self::DAY, self::NOW));
    $this->assertSame('active', myapi_providers_admin_situation(1, self::NOW + 30 * self::DAY + 1, self::NOW));
  }

  /**
   * Values come out of the database as strings.
   */
  public function testTheSituationReadsStringsAsTheDatabaseAnswersThem() {
    $this->assertSame('suspended', myapi_providers_admin_situation('0', (string) self::NOW, self::NOW));
    $this->assertSame('expired', myapi_providers_admin_situation('1', (string) (self::NOW - 1), self::NOW));
  }

  public function testEverySituationHasALabel() {
    $this->assertSame([
      'active'     => 'Activo',
      'expiring'   => 'Vence pronto',
      'expired'    => 'Habilitación vencida',
      'suspended'  => 'Suspendido',
      'no_license' => 'Sin habilitación',
    ], myapi_providers_admin_situation_options());
  }

  /**
   * The cell carries a class per situation, for css/myapi.claims.css.
   */
  public function testTheSituationCellCarriesAClassPerSituation() {
    $this->assertSame(
      '<span class="myapi-provider-situation myapi-provider-situation-expired">Habilitación vencida</span>',
      myapi_providers_admin_situation_label('expired')
    );
    $this->assertSame(
      '<span class="myapi-provider-situation myapi-provider-situation-no_license">Sin habilitación</span>',
      myapi_providers_admin_situation_label('no_license')
    );
  }

  /* -------------------------------------------------------------------------
   * The GET filters.
   * ---------------------------------------------------------------------- */

  public function testNoQueryStringMeansNoFilters() {
    $this->assertSame(['category' => NULL, 'situation' => NULL], myapi_providers_admin_list_filters());
  }

  public function testTheValidFiltersPassThrough() {
    $_GET = ['category' => '7', 'situation' => 'expiring'];

    $this->assertSame(['category' => 7, 'situation' => 'expiring'], myapi_providers_admin_list_filters());
  }

  public function testEveryMalformedFilterFallsBackToNoFilter() {
    foreach ([
      ['category' => '-1', 'situation' => 'xyz'],
      ['category' => 'abc', 'situation' => 'Activo'],
      ['category' => ['7'], 'situation' => ['active']],
      ['category' => '0', 'situation' => ''],
    ] as $get) {
      $_GET = $get;
      $this->assertSame(['category' => NULL, 'situation' => NULL], myapi_providers_admin_list_filters(), var_export($get, TRUE));
    }
  }

  /* -------------------------------------------------------------------------
   * Row labels.
   * ---------------------------------------------------------------------- */

  public function testTheRatingLabel() {
    $this->assertSame('4,6 ★ (12)', myapi_providers_admin_rating_label('4.60', '12'));
    $this->assertSame('5,0 ★ (1)', myapi_providers_admin_rating_label('5.00', '1'));
    $this->assertSame('4,7 ★ (3)', myapi_providers_admin_rating_label('4.67', '3'));
    $this->assertSame('Sin calificaciones', myapi_providers_admin_rating_label(NULL, NULL));
    $this->assertSame('Sin calificaciones', myapi_providers_admin_rating_label('0.00', '0'));
    $this->assertSame('Sin calificaciones', myapi_providers_admin_rating_label(NULL, '4'), 'a count with no average is not a rating');
  }

  public function testTheDateLabelShowsTheDayOrADash() {
    $this->assertSame(date('d/m/Y', self::NOW), myapi_providers_admin_date_label(self::NOW));
    $this->assertSame(date('d/m/Y', self::NOW), myapi_providers_admin_date_label((string) self::NOW));
    $this->assertSame('—', myapi_providers_admin_date_label(NULL));
    $this->assertSame('—', myapi_providers_admin_date_label(''));
  }

  public function testTheListLabelJoinsWithCommasAndEscapes() {
    $this->assertSame('Plomería, Electricidad', myapi_providers_admin_list_label(['Plomería', 'Electricidad']));
    $this->assertSame('—', myapi_providers_admin_list_label([]));
    $this->assertSame('&lt;b&gt;', myapi_providers_admin_list_label(['<b>']));
  }

  /* -------------------------------------------------------------------------
   * The table body.
   * ---------------------------------------------------------------------- */

  private function listRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'            => '88',
      'title'          => 'Plomería Sur',
      'published'      => '1',
      'created'        => (string) strtotime('2026-03-02 10:00:00'),
      'phone'          => '+56 9 1234 5678',
      'license_expiry' => (string) (self::NOW + 90 * self::DAY),
      'rating_avg'     => '4.60',
      'rating_count'   => '12',
    ]);
  }

  /**
   * The nine cells of SPEC 131, in header order.
   */
  public function testARowBecomesTheNineDocumentedCells() {
    $rows = myapi_providers_admin_table_rows(
      [$this->listRow()],
      [88 => TRUE],
      [88 => ['Plomería', 'Gasfitería']],
      [88 => ['Pablo Cordero', 'jperez']],
      self::NOW
    );

    $this->assertSame([
      '<a href="/node/88/edit">88</a>',
      'Plomería Sur',
      'Plomería, Gasfitería',
      '+56 9 1234 5678',
      'Pablo Cordero, jperez',
      date('d/m/Y', self::NOW + 90 * self::DAY),
      '<span class="myapi-provider-situation myapi-provider-situation-active">Activo</span>',
      '4,6 ★ (12)',
      '02/03/2026',
    ], $rows[0]);
  }

  public function testARowMissingEverythingOptionalStillHasItsNineCells() {
    $rows = myapi_providers_admin_table_rows(
      [$this->listRow(['title' => '', 'phone' => NULL, 'license_expiry' => NULL, 'rating_avg' => NULL, 'rating_count' => NULL])],
      [],
      [],
      [],
      self::NOW
    );

    $this->assertSame([
      '<a href="/node/88">88</a>',
      '88',
      '—',
      '—',
      '—',
      '—',
      '<span class="myapi-provider-situation myapi-provider-situation-no_license">Sin habilitación</span>',
      'Sin calificaciones',
      '02/03/2026',
    ], $rows[0]);
  }

  public function testTheTitleIsEscaped() {
    $rows = myapi_providers_admin_table_rows([$this->listRow(['title' => '<img src=x>'])], [], [], [], self::NOW);

    $this->assertSame('&lt;img src=x&gt;', $rows[0][1]);
  }

  public function testAnUnpublishedRowReadsSuspended() {
    $rows = myapi_providers_admin_table_rows([$this->listRow(['published' => '0'])], [], [], [], self::NOW);

    $this->assertStringContainsString('Suspendido', $rows[0][6]);
  }

  /* -------------------------------------------------------------------------
   * The multivalue fields, outside the paged query.
   * ---------------------------------------------------------------------- */

  public function testTheCategoriesAreGroupedPerProviderInDeltaOrder() {
    myapi_test_db_seed(['field_data_field_categories' => [
      ['entity_id' => 88, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 1, 'term_name' => 'Electricidad'],
      ['entity_id' => 88, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 0, 'term_name' => 'Plomería'],
      ['entity_id' => 89, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 0, 'term_name' => 'Pintura'],
      ['entity_id' => 88, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 2, 'term_name' => NULL],
      ['entity_id' => 90, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 0, 'term_name' => 'Fuera de página'],
    ]]);

    $this->assertSame(
      [88 => ['Plomería', 'Electricidad'], 89 => ['Pintura']],
      myapi_providers_admin_category_names([88, 89])
    );

    $query = myapi_test_db_queries('field_data_field_categories')[0];
    $this->assertSame([], $query['tags'], 'no node_access tag on a field table');
  }

  /**
   * The users of each provider, through the module's display-name rule. An
   * account that no longer exists says so instead of disappearing.
   */
  public function testTheUsersAreGroupedAndNamed() {
    myapi_test_db_seed([
      'field_data_field_provider_users' => [
        ['entity_id' => 88, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 0, 'field_provider_users_target_id' => 3],
        ['entity_id' => 88, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 1, 'field_provider_users_target_id' => 41],
        ['entity_id' => 89, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 0, 'field_provider_users_target_id' => 5],
      ],
      'users' => [
        ['uid' => 3, 'name' => 'pcordero', 'first_name' => 'Pablo', 'last_name' => 'Cordero'],
        ['uid' => 5, 'name' => 'jperez', 'first_name' => NULL, 'last_name' => NULL],
      ],
    ]);

    $this->assertSame(
      [88 => ['Pablo Cordero', 'Usuario eliminado (#41)'], 89 => ['jperez']],
      myapi_providers_admin_user_names([88, 89])
    );
  }

  public function testNoNidsAskNothing() {
    $this->assertSame([], myapi_providers_admin_category_names([]));
    $this->assertSame([], myapi_providers_admin_user_names([]));
    $this->assertSame([], myapi_test_db_queries());
  }

  /* -------------------------------------------------------------------------
   * Who may edit what.
   * ---------------------------------------------------------------------- */

  public function testBypassNodeAccessSkipsTheNodeLoadEntirely() {
    $GLOBALS['myapi_test_permissions']['bypass node access'] = TRUE;

    $this->assertSame([88 => TRUE, 89 => TRUE], myapi_providers_admin_editable_map([$this->listRow(), $this->listRow(['nid' => 89])]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  public function testWithoutBypassEachNodeIsAskedInOneBatch() {
    $GLOBALS['myapi_test_node_access']['update:88'] = TRUE;
    $GLOBALS['myapi_test_node_access']['update:89'] = FALSE;
    myapi_test_node_seed([
      88 => ['nid' => 88, 'type' => MYAPI_SERVICES_PROVIDER_TYPE],
      89 => ['nid' => 89, 'type' => MYAPI_SERVICES_PROVIDER_TYPE],
    ]);

    $this->assertSame([88 => TRUE, 89 => FALSE], myapi_providers_admin_editable_map([$this->listRow(), $this->listRow(['nid' => 89])]));
    $this->assertSame([[88, 89]], myapi_test_node_load_multiple_calls());
  }

  /* -------------------------------------------------------------------------
   * The query.
   * ---------------------------------------------------------------------- */

  /**
   * The published flag is projected under the alias 'published', and a flat
   * fixture row carries it twice: 'status' for the conditions on n.status,
   * 'published' for the projection.
   */
  private function providerRow(array $overrides = []) {
    $row = $overrides + [
      'nid'     => 88,
      'type'    => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'  => 1,
      'title'   => 'Plomería Sur',
      'created' => strtotime('2026-03-02 10:00:00'),
      'phone'   => '+56 9 1234 5678',
      'field_license_expiry_value' => self::NOW + 90 * self::DAY,
      'license_expiry' => self::NOW + 90 * self::DAY,
      'rating_avg'   => '4.60',
      'rating_count' => 12,
    ];
    $row['published'] = $row['status'];

    return $row;
  }

  private function runList($category_id = NULL, $situation = NULL) {
    return myapi_providers_admin_list_rows($category_id, $situation, self::NOW)->execute()->fetchAll();
  }

  private function nids(array $rows) {
    return array_map('intval', array_column($rows, 'nid'));
  }

  public function testTheListingQueryCarriesTheNodeAccessTagAndThePager() {
    myapi_test_db_seed(['node' => [$this->providerRow()]]);

    $this->runList();

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['node_access'], $query['tags']);
    $this->assertSame(['PagerDefault'], $query['extenders']);
    $this->assertSame(['start' => 0, 'length' => 20], $query['range']);
  }

  /**
   * Every join LEFT, and none of them on a multivalue field.
   */
  public function testTheMultivalueFieldsAreNeverJoinedToThePagedQuery() {
    myapi_test_db_seed(['node' => [$this->providerRow()]]);

    $this->runList();

    $joins = myapi_test_db_queries('node')[0]['joins'];
    $this->assertSame(['LEFT'], array_values(array_unique(array_column($joins, 'type'))));
    $tables = array_column($joins, 'table');
    $this->assertNotContains('field_data_field_categories', $tables);
    $this->assertNotContains('field_data_field_provider_users', $tables);
  }

  public function testAnUnpublishedProviderIsListed() {
    myapi_test_db_seed(['node' => [
      $this->providerRow(),
      $this->providerRow(['nid' => 89, 'status' => 0, 'title' => 'Andes Gas']),
      $this->providerRow(['nid' => 90, 'type' => MYAPI_SERVICES_OFFER_TYPE]),
    ]]);

    $rows = $this->runList();

    $this->assertSame([89, 88], $this->nids($rows));
    $this->assertSame('0', (string) $rows[0]->published, 'the published flag is projected for the situation');
  }

  /**
   * A directory: by name, then by nid.
   */
  public function testTheListingIsOrderedByTitleThenNid() {
    myapi_test_db_seed(['node' => [
      $this->providerRow(['nid' => 91, 'title' => 'Zeta']),
      $this->providerRow(['nid' => 92, 'title' => 'Alfa']),
      $this->providerRow(['nid' => 90, 'title' => 'Alfa']),
    ]]);

    $this->assertSame([90, 92, 91], $this->nids($this->runList()));
  }

  /**
   * The category filter is a correlated sub-select, so a provider with three
   * categories is still ONE row.
   */
  public function testTheCategoryFilterDoesNotMultiplyRows() {
    myapi_test_db_seed([
      'node' => [
        $this->providerRow(),
        $this->providerRow(['nid' => 89, 'title' => 'Andes Gas']),
      ],
      'field_data_field_categories' => [
        ['entity_id' => 88, 'entity_type' => 'node', 'deleted' => 0, 'field_categories_tid' => 7],
        ['entity_id' => 88, 'entity_type' => 'node', 'deleted' => 0, 'field_categories_tid' => 9],
        ['entity_id' => 89, 'entity_type' => 'node', 'deleted' => 0, 'field_categories_tid' => 9],
      ],
    ]);

    $this->assertSame([88], $this->nids($this->runList(7)));
    $this->assertSame([89, 88], $this->nids($this->runList(9)));
  }

  /**
   * One provider per situation, and each SQL filter selects exactly the one
   * the PHP function labels — over the same borders.
   */
  public function testEachSituationFilterAgreesWithTheCell() {
    $fixture = [
      'suspended'  => $this->providerRow(['nid' => 1, 'status' => 0]),
      'no_license' => $this->providerRow(['nid' => 2, 'field_license_expiry_value' => NULL, 'license_expiry' => NULL]),
      'expired'    => $this->providerRow(['nid' => 3, 'field_license_expiry_value' => self::NOW - 1, 'license_expiry' => self::NOW - 1]),
      'expiring'   => $this->providerRow(['nid' => 4, 'field_license_expiry_value' => self::NOW, 'license_expiry' => self::NOW]),
      'active'     => $this->providerRow(['nid' => 5, 'field_license_expiry_value' => self::NOW + 30 * self::DAY + 1, 'license_expiry' => self::NOW + 30 * self::DAY + 1]),
    ];
    $fixture[] = $this->providerRow(['nid' => 6, 'field_license_expiry_value' => self::NOW + 30 * self::DAY, 'license_expiry' => self::NOW + 30 * self::DAY]);

    $expected = [
      'suspended'  => [1],
      'no_license' => [2],
      'expired'    => [3],
      'expiring'   => [4, 6],
      'active'     => [5],
    ];

    foreach ($expected as $situation => $nids) {
      myapi_test_db_seed(['node' => array_values($fixture)]);
      $rows = $this->runList(NULL, $situation);
      $this->assertSame($nids, $this->nids($rows), $situation);

      foreach ($rows as $row) {
        $this->assertSame($situation, myapi_providers_admin_situation($row->published, $row->license_expiry, self::NOW), 'nid ' . $row->nid);
      }
    }
  }

  public function testTheRowCarriesEveryProjectedColumn() {
    myapi_test_db_seed(['node' => [$this->providerRow()]]);

    $this->assertSame([
      'nid',
      'title',
      'published',
      'created',
      'phone',
      'license_expiry',
      'rating_avg',
      'rating_count',
    ], array_keys((array) $this->runList()[0]));
  }

}
