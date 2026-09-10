<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../resources/reservation.resource.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_calendar.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.service_requests_admin.inc';

/**
 * Unit tests for the back-office service-request listing (SPEC 125):
 * includes/myapi.service_requests_admin.inc.
 *
 * Same shape as ClaimsAdminPageTest — the page it is modelled on — and the
 * same division of labour: everything that decides something is tested here,
 * and myapi_service_requests_list_page() / _filter_form() are not, because
 * what they do is drupal_get_form(), theme('pager') and drupal_add_css().
 * What they assemble is covered below piece by piece.
 *
 * Three cases here are worth more than they look:
 *
 *  - testTheListingQueryCarriesTheNodeAccessTag(). That tag is the ONLY thing
 *    restricting an 'administrador edificio' to their own buildings. Losing it
 *    does not break the page: it silently lists every service request of every
 *    condominium of the site, requester name included.
 *  - testEveryJoinedColumnIsOptional(). The API's own base query INNERs the
 *    category and the requester so a broken request never reaches the app;
 *    this page LEFTs everything, because its reader is the operator who has to
 *    fix that request. A row that vanishes here is a row nobody repairs.
 *  - testTheStatusOptionsComeFromTheServicesCatalogue(). The six keys and
 *    their Spanish labels live in myapi_services_request_statuses() (SPEC 77)
 *    and are read from there, never redeclared — the select of this page and
 *    the state machine of the endpoints cannot drift.
 */
class ServiceRequestsAdminPageTest extends TestCase {

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
   * The status catalogue.
   * ---------------------------------------------------------------------- */

  /**
   * The six keys of SPEC 77, in lifecycle order, with the catalogue's own
   * labels. This page declares none of them.
   */
  public function testTheStatusOptionsComeFromTheServicesCatalogue() {
    $this->assertSame(myapi_services_request_statuses(), myapi_service_requests_status_options());
    $this->assertSame([
      'open',
      'direct',
      'offered',
      'assigned',
      'closed',
      'cancelled',
    ], array_keys(myapi_service_requests_status_options()));
  }

  /* -------------------------------------------------------------------------
   * The GET filters.
   * ---------------------------------------------------------------------- */

  /**
   * No query string at all: four NULLs, i.e. no filter anywhere. The
   * condominium is deliberately not among them — resolving it needs the
   * reader's assigned scope, which the page reuses from SPEC 47.
   */
  public function testNoQueryStringMeansNoFilters() {
    $this->assertSame([
      'status'    => NULL,
      'category'  => NULL,
      'date_from' => NULL,
      'date_to'   => NULL,
    ], myapi_service_requests_list_filters());
  }

  public function testTheValidFiltersPassThrough() {
    $_GET = [
      'status'    => 'assigned',
      'category'  => '7',
      'date_from' => '2026-09-01',
      'date_to'   => '2026-09-30',
    ];

    $this->assertSame([
      'status'    => 'assigned',
      'category'  => 7,
      'date_from' => '2026-09-01',
      'date_to'   => '2026-09-30',
    ], myapi_service_requests_list_filters(), 'the category comes back as an int, the dates as strings');
  }

  /**
   * Nothing here ever fails: an invented status, a category that is not a
   * positive integer and a malformed date all fall back to "no filter". A
   * stale bookmark answers a listing, never an error page.
   */
  public function testEveryMalformedFilterFallsBackToNoFilter() {
    $_GET = [
      'status'    => 'pendiente',
      'category'  => 'abc',
      'date_from' => '01/09/2026',
      'date_to'   => '2026-02-30',
    ];

    $this->assertSame([
      'status'    => NULL,
      'category'  => NULL,
      'date_from' => NULL,
      'date_to'   => NULL,
    ], myapi_service_requests_list_filters());
  }

  /**
   * Zero and a negative are not tids, and neither is a float dressed as one.
   */
  public function testTheCategoryMustBeAPositiveInteger() {
    foreach (['0', '-1', '7.5', '', ' ', '1e3'] as $value) {
      $_GET = ['category' => $value];
      $filters = myapi_service_requests_list_filters();
      $this->assertNull($filters['category'], var_export($value, TRUE) . ' is not a tid');
    }
  }

  /**
   * An array where a string is expected does not fatal.
   */
  public function testArrayFiltersAreIgnoredWithoutAFatal() {
    $_GET = [
      'status'    => ['open'],
      'category'  => ['7'],
      'date_from' => ['2026-09-01'],
      'date_to'   => ['2026-09-30'],
    ];

    $this->assertSame([
      'status'    => NULL,
      'category'  => NULL,
      'date_from' => NULL,
      'date_to'   => NULL,
    ], myapi_service_requests_list_filters());
  }

  /**
   * The two bounds are validated independently: a broken 'from' does not drop
   * a perfectly good 'to'. Same criterion as the claims listing.
   */
  public function testTheTwoDateBoundsAreIndependent() {
    $_GET = ['date_from' => 'ayer', 'date_to' => '2026-09-30'];

    $filters = myapi_service_requests_list_filters();

    $this->assertNull($filters['date_from']);
    $this->assertSame('2026-09-30', $filters['date_to']);
  }

  /**
   * And an inverted range is kept as sent, which simply matches nothing.
   */
  public function testAnInvertedRangeIsKeptOnThisPage() {
    $_GET = ['date_from' => '2026-09-30', 'date_to' => '2026-09-01'];

    $filters = myapi_service_requests_list_filters();

    $this->assertSame('2026-09-30', $filters['date_from']);
    $this->assertSame('2026-09-01', $filters['date_to']);
  }

  /* -------------------------------------------------------------------------
   * The date range, turned into the timestamps n.created is compared with.
   * ---------------------------------------------------------------------- */

  /**
   * Each bound covers its whole day: from 00:00:00 to 23:59:59. Same criterion
   * as myapi_service_request_parse_created_range() in the resource — written
   * again here rather than called, because Regla 5 de CLAUDE.md keeps an
   * include out of resources/.
   */
  public function testTheRangeCoversWholeDays() {
    $range = myapi_service_requests_created_range('2026-09-01', '2026-09-30');

    $this->assertSame(strtotime('2026-09-01 00:00:00'), $range['from']);
    $this->assertSame(strtotime('2026-09-30 23:59:59'), $range['to']);
  }

  /**
   * A single day is a closed range over that day, and a request created at
   * 16:45 on it is inside.
   */
  public function testASingleDayIsAClosedRangeOverThatDay() {
    $range = myapi_service_requests_created_range('2026-09-04', '2026-09-04');
    $created = strtotime('2026-09-04 16:45:00');

    $this->assertLessThanOrEqual($created, $range['from']);
    $this->assertGreaterThanOrEqual($created, $range['to']);
  }

  /**
   * Each bound is independent, and NULL means "no bound on this side".
   */
  public function testEachBoundIsOptional() {
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_service_requests_created_range(NULL, NULL));

    $only_from = myapi_service_requests_created_range('2026-09-01', NULL);
    $this->assertSame(strtotime('2026-09-01 00:00:00'), $only_from['from']);
    $this->assertNull($only_from['to']);

    $only_to = myapi_service_requests_created_range(NULL, '2026-09-30');
    $this->assertNull($only_to['from']);
    $this->assertSame(strtotime('2026-09-30 23:59:59'), $only_to['to']);
  }

  /* -------------------------------------------------------------------------
   * The category select.
   * ---------------------------------------------------------------------- */

  public function testTheCategoryOptionsComeFromTheVocabulary() {
    myapi_test_taxonomy_seed([
      MYAPI_SERVICES_CATEGORY_VOCABULARY => [
        ['tid' => 7, 'name' => 'Plomería'],
        ['tid' => 9, 'name' => 'Electricidad'],
      ],
    ]);

    $this->assertSame([7 => 'Plomería', 9 => 'Electricidad'], myapi_service_requests_category_options());
  }

  /**
   * A site without the vocabulary answers an empty select instead of a notice
   * — the degraded case of an environment that never ran the services install.
   */
  public function testAMissingVocabularyAnswersNoOptions() {
    myapi_test_taxonomy_seed([]);

    $this->assertSame([], myapi_service_requests_category_options());
  }

  /**
   * The requested category is only honoured when it is one of the offered
   * terms — the same shape myapi_calendar_effective_condominium() gives the
   * condominium, and the reason list_filters() does not have to touch the
   * taxonomy to stay pure.
   */
  public function testAnUnknownCategoryIsDroppedFromTheFilter() {
    $options = [7 => 'Plomería', 9 => 'Electricidad'];

    $this->assertSame(7, myapi_service_requests_effective_category(7, $options));
    $this->assertNull(myapi_service_requests_effective_category(41, $options));
    $this->assertNull(myapi_service_requests_effective_category(NULL, $options));
    $this->assertNull(myapi_service_requests_effective_category(7, []));
  }

  /* -------------------------------------------------------------------------
   * Row labels.
   * ---------------------------------------------------------------------- */

  public function testTheStatusLabelUsesTheCatalogue() {
    $this->assertSame('Asignada', myapi_service_requests_status_label('assigned'));
    $this->assertSame('—', myapi_service_requests_status_label(NULL), 'no status is an em dash, not an empty cell');
    $this->assertSame('inventado', myapi_service_requests_status_label('inventado'), 'an unknown key prints raw');
  }

  /**
   * Every free-text cell is escaped: the condominium title and the category
   * name are printed into an HTML table and both are editable from the UI.
   */
  public function testTheTextLabelEscapesAndFallsBackToADash() {
    $this->assertSame('Edificio El Sáuco', myapi_service_requests_text_label('Edificio El Sáuco'));
    $this->assertSame('—', myapi_service_requests_text_label(NULL));
    $this->assertSame('—', myapi_service_requests_text_label(''));
    $this->assertSame('&lt;b&gt;x&lt;/b&gt;', myapi_service_requests_text_label('<b>x</b>'));
  }

  /**
   * One date label for the two date columns, because they arrive in different
   * shapes: n.created is a Unix timestamp and field_desired_start is a
   * datetime string.
   */
  public function testTheDateLabelReadsBothShapes() {
    $this->assertSame('04/09/2026 16:45', myapi_service_requests_date_label(strtotime('2026-09-04 16:45:00')));
    $this->assertSame('04/09/2026 16:45', myapi_service_requests_date_label('2026-09-04 16:45:00'));
    $this->assertSame('04/09/2026 00:00', myapi_service_requests_date_label('2026-09-04'));
  }

  /**
   * A missing date is an em dash and never 01/01/1970.
   */
  public function testAMissingDateShowsADash() {
    $this->assertSame('—', myapi_service_requests_date_label(NULL));
    $this->assertSame('—', myapi_service_requests_date_label(''));
    $this->assertSame('—', myapi_service_requests_date_label('mañana'));
  }

  /**
   * The requester cell has three shapes and each says something different:
   * nobody set, the account deleted, or here is the name.
   */
  public function testTheRequesterLabelHasThreeShapes() {
    $none = (object) ['requester_uid' => NULL, 'requester_name' => NULL];
    $deleted = (object) ['requester_uid' => 41, 'requester_name' => NULL];
    $present = (object) ['requester_uid' => 3, 'requester_name' => 'pcordero'];

    $this->assertSame('Sin solicitante', myapi_service_requests_requester_label($none));
    $this->assertSame('Usuario eliminado (#41)', myapi_service_requests_requester_label($deleted));
    $this->assertSame('pcordero', myapi_service_requests_requester_label($present));
    $this->assertSame(
      '&lt;script&gt;x&lt;/script&gt;',
      myapi_service_requests_requester_label((object) ['requester_uid' => 3, 'requester_name' => '<script>x</script>'])
    );
  }

  /**
   * The awarded provider has the same three shapes, and the middle one is the
   * reason the query projects the RAW target_id next to the resolved node: a
   * request awarded to a provider that was later unpublished must not read as
   * "not awarded yet", which is a different fact entirely.
   */
  public function testTheProviderLabelDistinguishesUnawardedFromBroken() {
    $none = (object) ['assigned_provider_raw' => NULL, 'assigned_provider_name' => NULL];
    $broken = (object) ['assigned_provider_raw' => 88, 'assigned_provider_name' => NULL];
    $awarded = (object) ['assigned_provider_raw' => 88, 'assigned_provider_name' => 'Plomería Sur'];

    $this->assertSame('—', myapi_service_requests_provider_label($none));
    $this->assertSame('Proveedor eliminado (#88)', myapi_service_requests_provider_label($broken));
    $this->assertSame('Plomería Sur', myapi_service_requests_provider_label($awarded));
    $this->assertSame(
      '&lt;img src=x&gt;',
      myapi_service_requests_provider_label((object) ['assigned_provider_raw' => 88, 'assigned_provider_name' => '<img src=x>'])
    );
  }

  /* -------------------------------------------------------------------------
   * The table body.
   * ---------------------------------------------------------------------- */

  private function listRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'                    => '312',
      'title'                  => 'Cambio de bomba de agua',
      'created'                => (string) strtotime('2026-09-04 16:45:00'),
      'condominium_id'         => '12',
      'condominium_title'      => 'Edificio El Sáuco',
      'status'                 => 'assigned',
      'category_id'            => '7',
      'category_name'          => 'Plomería',
      'requester_uid'          => '3',
      'requester_name'         => 'pcordero',
      'desired_start'          => '2026-09-10 08:00:00',
      'assigned_provider_id'   => '88',
      'assigned_provider_name' => 'Plomería Sur',
      'assigned_provider_raw'  => '88',
    ]);
  }

  /**
   * The nine cells of a row, in the order of the table header.
   */
  public function testARowBecomesTheNineDocumentedCells() {
    $rows = myapi_service_requests_list_table_rows([$this->listRow()], [312 => TRUE]);

    $this->assertCount(1, $rows);
    $this->assertCount(9, $rows[0]);
    $this->assertSame('<a href="/admin/content/service-requests/312">312</a>', $rows[0][0], 'the id opens the supervision detail (SPEC 126)');
    $this->assertSame('<a href="/node/312/edit">Cambio de bomba de agua</a>', $rows[0][1]);
    $this->assertSame('Edificio El Sáuco', $rows[0][2]);
    $this->assertSame('Asignada', $rows[0][3]);
    $this->assertSame('pcordero', $rows[0][4]);
    $this->assertSame('04/09/2026 16:45', $rows[0][5]);
    $this->assertSame('10/09/2026 08:00', $rows[0][6]);
    $this->assertSame('Plomería', $rows[0][7]);
    $this->assertSame('Plomería Sur', $rows[0][8]);
  }

  /**
   * The ID cell is the door to the supervision detail (SPEC 126), and it is
   * the SAME destination whatever the reader may or may not edit — unlike the
   * title right next to it. The two links leaving the same row on purpose is
   * what lets the detail stay read-only without costing 'backend' its
   * one-click path to the editing form.
   */
  public function testTheIdOpensTheSupervisionDetailForEveryReader() {
    $editable = myapi_service_requests_list_table_rows([$this->listRow()], [312 => TRUE]);
    $read_only = myapi_service_requests_list_table_rows([$this->listRow()], [312 => FALSE]);

    $this->assertSame('<a href="/admin/content/service-requests/312">312</a>', $editable[0][0]);
    $this->assertSame($editable[0][0], $read_only[0][0], 'the detail link does not depend on the edit permission');
    $this->assertNotSame($editable[0][1], $read_only[0][1], 'the title link still does');
  }

  /**
   * THE cell that changes per reader: a user who cannot edit the node is sent
   * to the node itself instead of collecting a 403 from node/%nid/edit. The
   * building admin reads this listing and edits nothing (SPEC 125).
   */
  public function testTheTitleLinksToTheNodeWhenTheReaderCannotEdit() {
    $rows = myapi_service_requests_list_table_rows([$this->listRow()], [312 => FALSE]);

    $this->assertSame('<a href="/node/312">Cambio de bomba de agua</a>', $rows[0][1]);
  }

  /**
   * A nid absent from the map is treated as "cannot edit": the fallback of the
   * cell is the link that never 403s.
   */
  public function testAnUnknownNidIsNotEditable() {
    $rows = myapi_service_requests_list_table_rows([$this->listRow()], []);

    $this->assertSame('<a href="/node/312">Cambio de bomba de agua</a>', $rows[0][1]);
  }

  /**
   * A request titled with markup must not render it in the listing.
   */
  public function testTheTitleIsEscapedInsideTheLink() {
    $rows = myapi_service_requests_list_table_rows([$this->listRow(['title' => '<img src=x>'])], [312 => TRUE]);

    $this->assertStringNotContainsString('<img', $rows[0][1]);
    $this->assertStringContainsString('&lt;img src=x&gt;', $rows[0][1]);
  }

  /**
   * A request with an empty title is still reachable: the link falls back to
   * the nid rather than rendering an unclickable empty cell.
   */
  public function testAnEmptyTitleFallsBackToTheNid() {
    $rows = myapi_service_requests_list_table_rows([$this->listRow(['title' => ''])], [312 => TRUE]);

    $this->assertSame('<a href="/node/312/edit">312</a>', $rows[0][1]);
  }

  /**
   * Every optional column degrades to an em dash, and the row still shows.
   */
  public function testARowMissingEverythingOptionalStillHasItsNineCells() {
    $rows = myapi_service_requests_list_table_rows([$this->listRow([
      'condominium_id'         => NULL,
      'condominium_title'      => NULL,
      'status'                 => NULL,
      'category_id'            => NULL,
      'category_name'          => NULL,
      'requester_uid'          => NULL,
      'requester_name'         => NULL,
      'desired_start'          => NULL,
      'assigned_provider_id'   => NULL,
      'assigned_provider_name' => NULL,
      'assigned_provider_raw'  => NULL,
    ])], [312 => TRUE]);

    $this->assertCount(9, $rows[0]);
    $this->assertSame('—', $rows[0][2]);
    $this->assertSame('—', $rows[0][3]);
    $this->assertSame('Sin solicitante', $rows[0][4]);
    $this->assertSame('—', $rows[0][6]);
    $this->assertSame('—', $rows[0][7]);
    $this->assertSame('—', $rows[0][8]);
  }

  /**
   * An empty result set is an empty body — the page's '#empty' text takes over.
   */
  public function testNoRowsProduceNoCells() {
    $this->assertSame([], myapi_service_requests_list_table_rows([], []));
  }

  /* -------------------------------------------------------------------------
   * Who may edit what.
   * ---------------------------------------------------------------------- */

  /**
   * An 'administrator' edits everything and the page loads NO node to find
   * that out: 'bypass node access' answers for every row at once.
   */
  public function testBypassNodeAccessSkipsTheNodeLoadEntirely() {
    $GLOBALS['myapi_test_permissions']['bypass node access'] = TRUE;

    $map = myapi_service_requests_editable_map([$this->listRow(), $this->listRow(['nid' => 313])]);

    $this->assertSame([312 => TRUE, 313 => TRUE], $map);
    $this->assertSame([], myapi_test_node_load_multiple_calls(), 'no node is loaded on the fast path');
    $this->assertSame([], $GLOBALS['myapi_test_node_access_calls']);
  }

  /**
   * And so does the 'backend' operator this page was built for, through the
   * bundle permission.
   */
  public function testTheEditAnyPermissionAlsoSkipsTheNodeLoad() {
    $GLOBALS['myapi_test_permissions']['edit any ' . MYAPI_SERVICES_REQUEST_TYPE . ' content'] = TRUE;

    $this->assertSame([312 => TRUE], myapi_service_requests_editable_map([$this->listRow()]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  /**
   * A reader with neither permission — an 'administrador edificio', which by
   * design holds no write permission over this bundle — falls to Drupal's own
   * per-node decision, resolved in ONE batch and not one load per row.
   */
  public function testWithoutAPermissionEachNodeIsAskedInOneBatch() {
    $GLOBALS['myapi_test_node_access']['update:312'] = FALSE;
    $GLOBALS['myapi_test_node_access']['update:313'] = TRUE;
    myapi_test_node_seed([
      312 => ['nid' => 312, 'type' => MYAPI_SERVICES_REQUEST_TYPE],
      313 => ['nid' => 313, 'type' => MYAPI_SERVICES_REQUEST_TYPE],
    ]);

    $map = myapi_service_requests_editable_map([$this->listRow(), $this->listRow(['nid' => 313])]);

    $this->assertSame([312 => FALSE, 313 => TRUE], $map);
    $this->assertSame([[312, 313]], myapi_test_node_load_multiple_calls(), 'one batch, not one load per row');
  }

  /**
   * A nid whose node cannot be loaded is simply absent from the map, which
   * myapi_service_requests_list_table_rows() reads as "not editable" — the
   * link that never 403s.
   */
  public function testAnUnloadableNodeIsAbsentFromTheMap() {
    myapi_test_node_seed([]);

    $this->assertSame([], myapi_service_requests_editable_map([$this->listRow()]));
  }

  /**
   * An empty listing asks nothing of anybody.
   */
  public function testNoRowsResolveNoPermissions() {
    $this->assertSame([], myapi_service_requests_editable_map([]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  /* -------------------------------------------------------------------------
   * The query.
   * ---------------------------------------------------------------------- */

  /**
   * A published 'service_request' with every column the listing projects.
   *
   * The status is seeded under its QUALIFIED source name: the alias 'status'
   * collides with node.status, the published flag, and a flat fixture row
   * cannot hold both.
   */
  private function requestRow(array $overrides = []) {
    return $overrides + [
      'nid'     => 312,
      'type'    => MYAPI_SERVICES_REQUEST_TYPE,
      'status'  => 1,
      'title'   => 'Cambio de bomba de agua',
      'created' => strtotime('2026-09-04 16:45:00'),
      'field_condominium_target_id' => 12,
      'condominium_id'    => 12,
      'condominium_title' => 'Edificio El Sáuco',
      'frs.field_request_status_value' => 'assigned',
      'field_category_tid' => 7,
      'category_id'        => 7,
      'category_name'      => 'Plomería',
      'field_requester_target_id' => 3,
      'requester_uid'      => 3,
      'requester_name'     => 'pcordero',
      'desired_start'      => '2026-09-10 08:00:00',
      'assigned_provider_id'   => 88,
      'assigned_provider_name' => 'Plomería Sur',
      'assigned_provider_raw'  => 88,
    ];
  }

  private function runList($condominium_id = NULL, $status = NULL, $category_id = NULL, $from = NULL, $to = NULL) {
    return myapi_service_requests_list_rows($condominium_id, $status, $category_id, $from, $to)
      ->execute()
      ->fetchAll();
  }

  /**
   * THE assertion of this file: the query carries ->addTag('node_access').
   *
   * That tag is what makes myapi_building_admin_alter_node_query() narrow the
   * listing to the assigned condominiums of an 'administrador edificio'.
   * Losing it does not break the page — it shows every request of the site.
   */
  public function testTheListingQueryCarriesTheNodeAccessTag() {
    myapi_test_db_seed(['node' => [$this->requestRow()]]);

    $this->runList();

    $this->assertSame(['node_access'], myapi_test_db_queries('node')[0]['tags']);
  }

  /**
   * And it is paginated through Drupal's own pager rather than by hand.
   */
  public function testTheListingQueryIsExtendedWithThePager() {
    myapi_test_db_seed(['node' => [$this->requestRow()]]);

    $this->runList();

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['PagerDefault'], $query['extenders']);
    $this->assertSame(['start' => 0, 'length' => 20], $query['range']);
  }

  /**
   * Only published requests, and only requests.
   */
  public function testOnlyPublishedServiceRequestsAreListed() {
    myapi_test_db_seed(['node' => [
      $this->requestRow(),
      $this->requestRow(['nid' => 313, 'status' => 0]),
      $this->requestRow(['nid' => 314, 'type' => MYAPI_SERVICES_OFFER_TYPE]),
      $this->requestRow(['nid' => 315, 'type' => 'reclamo']),
    ]]);

    $rows = $this->runList();

    $this->assertSame([312], array_map('intval', array_column($rows, 'nid')));
  }

  /**
   * Newest first: the listing is read from the top.
   */
  public function testTheListingIsOrderedByNidDescending() {
    myapi_test_db_seed(['node' => [
      $this->requestRow(['nid' => 312]),
      $this->requestRow(['nid' => 314]),
      $this->requestRow(['nid' => 313]),
    ]]);

    $rows = $this->runList();

    $this->assertSame([314, 313, 312], array_map('intval', array_column($rows, 'nid')));
  }

  /**
   * Each of the five filters narrows on its own, and a NULL means "no filter
   * on this column".
   */
  public function testEachFilterNarrowsOnItsOwn() {
    $seed = function () {
      myapi_test_db_seed(['node' => [
        $this->requestRow(),
        $this->requestRow([
          'nid' => 313,
          'field_condominium_target_id' => 30, 'condominium_id' => 30,
          'frs.field_request_status_value' => 'open',
          'field_category_tid' => 9, 'category_id' => 9,
          'created' => strtotime('2026-10-02 09:00:00'),
        ]),
      ]]);
    };

    $seed();
    $this->assertSame([312], array_map('intval', array_column($this->runList(12), 'nid')));

    $seed();
    $this->assertSame([313], array_map('intval', array_column($this->runList(NULL, 'open'), 'nid')));

    $seed();
    $this->assertSame([313], array_map('intval', array_column($this->runList(NULL, NULL, 9), 'nid')));

    $seed();
    $this->assertSame(
      [313],
      array_map('intval', array_column($this->runList(NULL, NULL, NULL, strtotime('2026-10-01 00:00:00')), 'nid'))
    );

    $seed();
    $this->assertSame(
      [312],
      array_map('intval', array_column($this->runList(NULL, NULL, NULL, NULL, strtotime('2026-09-30 23:59:59')), 'nid'))
    );
  }

  /**
   * The whole day of the upper bound is inside the range: a request created at
   * 16:45 on it is listed.
   */
  public function testTheUpperBoundIncludesTheWholeDay() {
    myapi_test_db_seed(['node' => [$this->requestRow()]]);

    $range = myapi_service_requests_created_range('2026-09-04', '2026-09-04');
    $rows = $this->runList(NULL, NULL, NULL, $range['from'], $range['to']);

    $this->assertCount(1, $rows);
  }

  /**
   * With no filter, a request missing every optional value still appears —
   * LEFT and not INNER, so the operator who has to fix it can see it. This is
   * the deliberate difference with myapi_service_request_base_query(), which
   * INNERs the category and the requester for the app.
   */
  public function testEveryJoinedColumnIsOptional() {
    myapi_test_db_seed(['node' => [
      $this->requestRow([
        'field_condominium_target_id' => NULL,
        'condominium_id' => NULL, 'condominium_title' => NULL,
        'frs.field_request_status_value' => NULL,
        'field_category_tid' => NULL, 'category_id' => NULL, 'category_name' => NULL,
        'field_requester_target_id' => NULL, 'requester_uid' => NULL, 'requester_name' => NULL,
        'desired_start' => NULL,
        'assigned_provider_id' => NULL, 'assigned_provider_name' => NULL, 'assigned_provider_raw' => NULL,
      ]),
    ]]);

    $rows = $this->runList();

    $this->assertCount(1, $rows);
    $this->assertNull($rows[0]->status);
    $this->assertNull($rows[0]->category_name);
    $this->assertNull($rows[0]->requester_name);
    $this->assertNull($rows[0]->assigned_provider_name);
  }

  /**
   * A filter on a left-joined column also excludes the requests that have no
   * value in it — the desired reading of "show me this status".
   */
  public function testFilteringExcludesRequestsWithNoValueInThatColumn() {
    myapi_test_db_seed(['node' => [
      $this->requestRow(),
      $this->requestRow(['nid' => 313, 'frs.field_request_status_value' => NULL]),
    ]]);

    $rows = $this->runList(NULL, 'assigned');

    $this->assertSame([312], array_map('intval', array_column($rows, 'nid')));
  }

  /**
   * The row carries the fourteen columns the table reads, under the names the
   * label helpers expect. A renamed alias breaks the page silently — every
   * cell would answer its em dash — so the shape is pinned here.
   */
  public function testTheRowCarriesEveryProjectedColumn() {
    myapi_test_db_seed(['node' => [$this->requestRow()]]);

    $rows = $this->runList();

    $this->assertSame([
      'nid',
      'title',
      'created',
      'condominium_id',
      'condominium_title',
      'status',
      'category_id',
      'category_name',
      'requester_uid',
      'requester_name',
      'desired_start',
      'assigned_provider_id',
      'assigned_provider_name',
      'assigned_provider_raw',
    ], array_keys((array) $rows[0]));
  }

}
