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
require_once __DIR__ . '/../../includes/myapi.service_offers_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_ratings_admin.inc';

/**
 * Unit tests for the back-office service-rating listing (SPEC 131):
 * includes/myapi.service_ratings_admin.inc.
 *
 * The cases worth more than they look:
 *
 *  - testARatingWithNoOfferReadsDirectProvider(). A rating of a 'direct'
 *    request has no offer (SPEC 87), so there is no path to the request; the
 *    row must still be listed, with 'Proveedor directo'.
 *  - testTheCommentIsEscapedAndCutWithoutBreakingAMultibyteCharacter(). The
 *    comment is the one free text a resident writes into this page.
 */
class ServiceRatingsAdminPageTest extends TestCase {

  protected function setUp(): void {
    $this->reset();
  }

  protected function tearDown(): void {
    $this->reset();
  }

  private function reset() {
    myapi_test_db_seed();
    myapi_test_static_reset();
    myapi_test_node_seed();
    $GLOBALS['myapi_test_permissions'] = [];
    $GLOBALS['myapi_test_node_access'] = [];
    $GLOBALS['myapi_test_node_access_calls'] = [];
    $GLOBALS['myapi_test_node_load_multiple'] = [];
    unset($GLOBALS['myapi_test_node_access_default']);
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * The GET filters.
   * ---------------------------------------------------------------------- */

  public function testNoQueryStringMeansNoFilters() {
    $this->assertSame([
      'provider'  => NULL,
      'stars'     => NULL,
      'date_from' => NULL,
      'date_to'   => NULL,
    ], myapi_service_ratings_admin_list_filters());
  }

  public function testTheValidFiltersPassThrough() {
    $_GET = ['provider' => '88', 'stars' => '4', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

    $this->assertSame([
      'provider'  => 88,
      'stars'     => 4,
      'date_from' => '2026-10-01',
      'date_to'   => '2026-10-31',
    ], myapi_service_ratings_admin_list_filters());
  }

  /**
   * The stars are a key of myapi_services_star_values(), nothing else.
   */
  public function testEveryMalformedFilterFallsBackToNoFilter() {
    foreach ([
      ['provider' => '-1', 'stars' => '6', 'date_from' => '2026-02-30', 'date_to' => 'ayer'],
      ['provider' => 'x', 'stars' => '0', 'date_from' => '', 'date_to' => ''],
      ['provider' => '88.5', 'stars' => '4.5'],
      ['provider' => ['88'], 'stars' => ['4'], 'date_from' => ['2026-10-01'], 'date_to' => ['2026-10-31']],
    ] as $get) {
      $_GET = $get;
      $this->assertSame([
        'provider'  => NULL,
        'stars'     => NULL,
        'date_from' => NULL,
        'date_to'   => NULL,
      ], myapi_service_ratings_admin_list_filters(), var_export($get, TRUE));
    }
  }

  /* -------------------------------------------------------------------------
   * Row labels.
   * ---------------------------------------------------------------------- */

  public function testTheStarsLabel() {
    $this->assertSame('★★★★☆ (4)', myapi_service_ratings_admin_stars_label('4'));
    $this->assertSame('★★★★★ (5)', myapi_service_ratings_admin_stars_label(5));
    $this->assertSame('★☆☆☆☆ (1)', myapi_service_ratings_admin_stars_label('1'));
    $this->assertSame('—', myapi_service_ratings_admin_stars_label(NULL));
    $this->assertSame('—', myapi_service_ratings_admin_stars_label(''));
    $this->assertSame('9', myapi_service_ratings_admin_stars_label('9'), 'a value outside the catalogue prints raw');
  }

  public function testAShortCommentKeepsItsFullTextInTheTitle() {
    $this->assertSame(
      '<span title="Muy puntual">Muy puntual</span>',
      myapi_service_ratings_admin_comment_label('Muy puntual')
    );
    $this->assertSame('—', myapi_service_ratings_admin_comment_label(NULL));
    $this->assertSame('—', myapi_service_ratings_admin_comment_label('   '));
  }

  /**
   * A '<script>' reads as text, both in the cell and in the attribute; and a
   * 200-character comment of 'ñ' is cut at 140 characters — not 140 bytes, so
   * no half character ever reaches the page.
   */
  public function testTheCommentIsEscapedAndCutWithoutBreakingAMultibyteCharacter() {
    $script = myapi_service_ratings_admin_comment_label('<script>alert(1)</script>');
    $this->assertStringNotContainsString('<script>', $script);
    $this->assertSame('<span title="&lt;script&gt;alert(1)&lt;/script&gt;">&lt;script&gt;alert(1)&lt;/script&gt;</span>', $script);

    $long = str_repeat('ñ', 200);
    $label = myapi_service_ratings_admin_comment_label($long);
    $this->assertSame(1, preg_match('/^<span title="(ñ{200})">(.*)<\/span>$/u', $label, $m), 'valid UTF-8, full text in the title');
    $this->assertSame(140, mb_strlen($m[2], 'UTF-8'));
    $this->assertStringEndsWith('…', $m[2]);
  }

  /**
   * Exactly 140 characters is not cut.
   */
  public function testACommentOfExactly140CharactersIsNotCut() {
    $text = str_repeat('a', 140);

    $this->assertSame('<span title="' . $text . '">' . $text . '</span>', myapi_service_ratings_admin_comment_label($text));
  }

  /* -------------------------------------------------------------------------
   * The table body.
   * ---------------------------------------------------------------------- */

  private function listRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'            => '900',
      'created'        => (string) strtotime('2026-10-08 20:10:00'),
      'provider_raw'   => '88',
      'provider_title' => 'Plomería Sur',
      'stars'          => '4',
      'comment'        => 'Muy puntual',
      'offer_raw'      => '700',
      'request_raw'    => '312',
      'request_id'     => '312',
      'request_title'  => 'Cambio de bomba de agua',
      'unit_title'     => NULL,
    ]);
  }

  public function testARowBecomesTheSevenDocumentedCells() {
    $rows = myapi_service_ratings_admin_table_rows([$this->listRow(['unit_title' => 'Depto 301'])], [900 => TRUE]);

    $this->assertSame([
      '<a href="/node/900/edit">900</a>',
      '08/10/2026 20:10',
      'Plomería Sur',
      '★★★★☆ (4)',
      '<span title="Muy puntual">Muy puntual</span>',
      '<a href="/admin/content/service-requests/312">Cambio de bomba de agua</a>',
      'Depto 301',
    ], $rows[0]);
  }

  /**
   * SPEC 87: a rating of a 'direct' request has no offer and therefore no path
   * to the request. It still shows, and says why.
   */
  public function testARatingWithNoOfferReadsDirectProvider() {
    $rows = myapi_service_ratings_admin_table_rows([$this->listRow([
      'offer_raw' => NULL, 'request_raw' => NULL, 'request_id' => NULL, 'request_title' => NULL,
    ])], []);

    $this->assertSame('Proveedor directo', $rows[0][5]);
  }

  /**
   * An offer whose request is gone, and an offer that is itself gone.
   */
  public function testTheRequestCellDegradesThroughTheOffer() {
    $gone_request = myapi_service_ratings_admin_table_rows([$this->listRow(['request_id' => NULL, 'request_title' => NULL])], []);
    $gone_offer = myapi_service_ratings_admin_table_rows([$this->listRow(['request_raw' => NULL, 'request_id' => NULL, 'request_title' => NULL])], []);

    $this->assertSame('Solicitud eliminada (#312)', $gone_request[0][5]);
    $this->assertSame('—', $gone_offer[0][5]);
  }

  public function testTheProviderCellHasThreeShapes() {
    $none = myapi_service_ratings_admin_table_rows([$this->listRow(['provider_raw' => NULL, 'provider_title' => NULL])], []);
    $gone = myapi_service_ratings_admin_table_rows([$this->listRow(['provider_title' => NULL])], []);

    $this->assertSame('—', $none[0][2]);
    $this->assertSame('Proveedor eliminado (#88)', $gone[0][2]);
  }

  /**
   * field_unit is empty on almost every rating (SPEC 131, risk 2): an em dash.
   */
  public function testAMissingUnitIsADash() {
    $this->assertSame('—', myapi_service_ratings_admin_table_rows([$this->listRow()], [])[0][6]);
  }

  public function testTheIdLinksToTheNodeWhenTheReaderCannotEdit() {
    $this->assertSame('<a href="/node/900">900</a>', myapi_service_ratings_admin_table_rows([$this->listRow()], [900 => FALSE])[0][0]);
  }

  /* -------------------------------------------------------------------------
   * Who may edit what.
   * ---------------------------------------------------------------------- */

  public function testBypassNodeAccessSkipsTheNodeLoadEntirely() {
    $GLOBALS['myapi_test_permissions']['bypass node access'] = TRUE;

    $this->assertSame([900 => TRUE], myapi_service_ratings_admin_editable_map([$this->listRow()]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  public function testWithoutBypassEachNodeIsAskedInOneBatch() {
    $GLOBALS['myapi_test_node_access']['update:900'] = TRUE;
    $GLOBALS['myapi_test_node_access']['update:901'] = FALSE;
    myapi_test_node_seed([
      900 => ['nid' => 900, 'type' => MYAPI_SERVICES_RATING_TYPE],
      901 => ['nid' => 901, 'type' => MYAPI_SERVICES_RATING_TYPE],
    ]);

    $this->assertSame([900 => TRUE, 901 => FALSE], myapi_service_ratings_admin_editable_map([$this->listRow(), $this->listRow(['nid' => 901])]));
    $this->assertSame([[900, 901]], myapi_test_node_load_multiple_calls());
  }

  /* -------------------------------------------------------------------------
   * The query.
   * ---------------------------------------------------------------------- */

  private function ratingRow(array $overrides = []) {
    return $overrides + [
      'nid'     => 900,
      'type'    => MYAPI_SERVICES_RATING_TYPE,
      'status'  => 1,
      'created' => strtotime('2026-10-08 20:10:00'),
      'field_rating_provider_target_id' => 88,
      'provider_raw'   => 88,
      'provider_title' => 'Plomería Sur',
      'field_stars_value' => 4,
      'stars'          => 4,
      'comment'        => 'Muy puntual',
      'offer_raw'      => 700,
      'request_raw'    => 312,
      'request_id'     => 312,
      'request_title'  => 'Cambio de bomba de agua',
      'unit_title'     => NULL,
    ];
  }

  private function runList($provider = NULL, $stars = NULL, $from = NULL, $to = NULL) {
    return myapi_service_ratings_admin_list_rows($provider, $stars, $from, $to)->execute()->fetchAll();
  }

  private function nids(array $rows) {
    return array_map('intval', array_column($rows, 'nid'));
  }

  public function testTheListingQueryCarriesTheNodeAccessTagAndThePager() {
    myapi_test_db_seed(['node' => [$this->ratingRow()]]);

    $this->runList();

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['node_access'], $query['tags']);
    $this->assertSame(['PagerDefault'], $query['extenders']);
    $this->assertSame(['start' => 0, 'length' => 20], $query['range']);
  }

  /**
   * Every join LEFT — the rating with no offer must not be dropped by the
   * offer → request hop.
   */
  public function testEveryJoinIsLeftAndTheRequestIsReachedThroughTheOffer() {
    myapi_test_db_seed(['node' => [$this->ratingRow(['offer_raw' => NULL, 'request_raw' => NULL, 'request_id' => NULL, 'request_title' => NULL])]]);

    $rows = $this->runList();

    $this->assertSame([900], $this->nids($rows));
    $joins = myapi_test_db_queries('node')[0]['joins'];
    $this->assertSame(['LEFT'], array_values(array_unique(array_column($joins, 'type'))));
    $request_join = array_values(array_filter($joins, function ($join) {
      return $join['alias'] === 'freq';
    }))[0];
    $this->assertStringContainsString('freq.entity_id = fro.field_rating_offer_target_id', $request_join['condition']);
  }

  public function testOnlyPublishedRatingsNewestFirst() {
    myapi_test_db_seed(['node' => [
      $this->ratingRow(['nid' => 900]),
      $this->ratingRow(['nid' => 902]),
      $this->ratingRow(['nid' => 901]),
      $this->ratingRow(['nid' => 903, 'status' => 0]),
      $this->ratingRow(['nid' => 904, 'type' => MYAPI_SERVICES_OFFER_TYPE]),
    ]]);

    $this->assertSame([902, 901, 900], $this->nids($this->runList()));
  }

  public function testEachFilterNarrowsOnItsOwn() {
    $seed = function () {
      myapi_test_db_seed(['node' => [
        $this->ratingRow(),
        $this->ratingRow([
          'nid' => 901,
          'field_rating_provider_target_id' => 89,
          'field_stars_value' => 2,
          'created' => strtotime('2026-11-02 09:00:00'),
        ]),
      ]]);
    };

    $seed();
    $this->assertSame([900], $this->nids($this->runList(88)));
    $seed();
    $this->assertSame([901], $this->nids($this->runList(NULL, 2)));
    $seed();
    $this->assertSame([901], $this->nids($this->runList(NULL, NULL, strtotime('2026-11-01 00:00:00'))));
    $seed();
    $this->assertSame([900], $this->nids($this->runList(NULL, NULL, NULL, strtotime('2026-10-31 23:59:59'))));
  }

  public function testTheRowCarriesEveryProjectedColumn() {
    myapi_test_db_seed(['node' => [$this->ratingRow()]]);

    $this->assertSame([
      'nid',
      'created',
      'provider_raw',
      'provider_title',
      'stars',
      'comment',
      'offer_raw',
      'request_raw',
      'request_id',
      'request_title',
      'unit_title',
    ], array_keys((array) $this->runList()[0]));
  }

}
