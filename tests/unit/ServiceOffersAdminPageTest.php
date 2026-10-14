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

/**
 * Unit tests for the back-office service-offer listing (SPEC 131):
 * includes/myapi.service_offers_admin.inc.
 *
 * The cases worth more than they look:
 *
 *  - testTheRequestCellOpensTheSupervisionDetail(). The Solicitud column goes
 *    to the admin detail of SPEC 126, not to the node.
 *  - testAnOnSiteQuoteShowsNoAmount(). 'on_site_quote' carries no price (SPEC
 *    100), even if a stray value sits in the column.
 *  - testTheCondominiumFilterReadsTheRequestsCondominium(). An offer has no
 *    condominium of its own; the filter reads its request's.
 */
class ServiceOffersAdminPageTest extends TestCase {

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
   * Catalogues and options.
   * ---------------------------------------------------------------------- */

  public function testTheStatusOptionsComeFromTheServicesCatalogue() {
    $this->assertSame(myapi_services_offer_statuses(), myapi_service_offers_admin_status_options());
  }

  /**
   * Every provider, published or not — an offer of a suspended provider is
   * still an offer — ordered by name.
   */
  public function testTheProviderOptionsListEveryProviderByTitle() {
    myapi_test_db_seed(['node' => [
      ['nid' => 88, 'type' => MYAPI_SERVICES_PROVIDER_TYPE, 'status' => 1, 'title' => 'Plomería Sur'],
      ['nid' => 89, 'type' => MYAPI_SERVICES_PROVIDER_TYPE, 'status' => 0, 'title' => 'Andes Gas'],
      ['nid' => 90, 'type' => 'condominio', 'status' => 1, 'title' => 'Edificio'],
    ]]);

    $this->assertSame([89 => 'Andes Gas', 88 => 'Plomería Sur'], myapi_service_offers_admin_provider_options());
  }

  /* -------------------------------------------------------------------------
   * The GET filters.
   * ---------------------------------------------------------------------- */

  public function testNoQueryStringMeansNoFilters() {
    $this->assertSame([
      'condominium' => NULL,
      'provider'    => NULL,
      'status'      => NULL,
      'date_from'   => NULL,
      'date_to'     => NULL,
    ], myapi_service_offers_admin_list_filters());
  }

  public function testTheValidFiltersPassThrough() {
    $_GET = [
      'condominium' => '12',
      'provider'    => '88',
      'status'      => 'selected',
      'date_from'   => '2026-10-01',
      'date_to'     => '2026-10-31',
    ];

    $this->assertSame([
      'condominium' => 12,
      'provider'    => 88,
      'status'      => 'selected',
      'date_from'   => '2026-10-01',
      'date_to'     => '2026-10-31',
    ], myapi_service_offers_admin_list_filters());
  }

  public function testEveryMalformedFilterFallsBackToNoFilter() {
    foreach ([
      ['condominium' => '-1', 'provider' => '-1', 'status' => 'xyz', 'date_from' => '2026-02-30', 'date_to' => 'mañana'],
      ['condominium' => ['12'], 'provider' => ['88'], 'status' => ['sent'], 'date_from' => ['2026-10-01'], 'date_to' => ['2026-10-31']],
    ] as $get) {
      $_GET = $get;
      $this->assertSame([
        'condominium' => NULL,
        'provider'    => NULL,
        'status'      => NULL,
        'date_from'   => NULL,
        'date_to'     => NULL,
      ], myapi_service_offers_admin_list_filters(), var_export($get, TRUE));
    }
  }

  /**
   * A well-formed id that is not among the options filters nothing.
   */
  public function testAnIdOutsideTheOptionsIsDropped() {
    $options = [12 => 'Edificio El Sáuco'];

    $this->assertSame(12, myapi_service_offers_admin_effective_option(12, $options));
    $this->assertNull(myapi_service_offers_admin_effective_option(13, $options));
    $this->assertNull(myapi_service_offers_admin_effective_option(NULL, $options));
  }

  /* -------------------------------------------------------------------------
   * Row labels.
   * ---------------------------------------------------------------------- */

  public function testTheAmountLabel() {
    $this->assertSame('1.500,00 (Precio cerrado)', myapi_service_offers_admin_amount_label('1500.00', 'fixed'));
    $this->assertSame('25,50 (Por hora)', myapi_service_offers_admin_amount_label('25.5', 'hourly'));
    $this->assertSame('80,00', myapi_service_offers_admin_amount_label('80', NULL), 'an amount with no type is still an amount');
    $this->assertSame('80,00 (inventado)', myapi_service_offers_admin_amount_label('80', 'inventado'), 'an unknown type prints raw');
    $this->assertSame('—', myapi_service_offers_admin_amount_label(NULL, NULL));
    $this->assertSame('—', myapi_service_offers_admin_amount_label(NULL, 'fixed'), 'a priced type with no price');
  }

  /**
   * 'on_site_quote' shows the label and never an amount (SPEC 100).
   */
  public function testAnOnSiteQuoteShowsNoAmount() {
    $this->assertSame('A presupuestar en sitio', myapi_service_offers_admin_amount_label(NULL, 'on_site_quote'));
    $this->assertSame('A presupuestar en sitio', myapi_service_offers_admin_amount_label('999', 'on_site_quote'));
  }

  public function testTheStatusLabel() {
    $this->assertSame('Seleccionada', myapi_service_offers_admin_status_label('selected'));
    $this->assertSame('—', myapi_service_offers_admin_status_label(NULL));
    $this->assertSame('inventado', myapi_service_offers_admin_status_label('inventado'));
  }

  public function testTheDateLabels() {
    $ts = strtotime('2026-10-14 09:30:00');
    $this->assertSame('14/10/2026', myapi_service_offers_admin_date_label($ts, 'd/m/Y', '—'));
    $this->assertSame('14/10/2026 09:30', myapi_service_offers_admin_date_label((string) $ts, 'd/m/Y H:i', '—'));
    $this->assertSame('—', myapi_service_offers_admin_date_label(NULL, 'd/m/Y', '—'));
    $this->assertSame('Sin chat', myapi_service_offers_admin_date_label('', 'd/m/Y H:i', 'Sin chat'));
  }

  /* -------------------------------------------------------------------------
   * The table body.
   * ---------------------------------------------------------------------- */

  private function listRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'               => '700',
      'created'           => (string) strtotime('2026-10-05 11:00:00'),
      'request_raw'       => '312',
      'request_id'        => '312',
      'request_title'     => 'Cambio de bomba de agua',
      'condominium_title' => 'Edificio El Sáuco',
      'provider_raw'      => '88',
      'provider_title'    => 'Plomería Sur',
      'amount'            => '1500.00',
      'amount_type'       => 'fixed',
      'offer_status'      => 'sent',
      'valid_until'       => (string) strtotime('2026-10-20 00:00:00'),
      'last_message_at'   => (string) strtotime('2026-10-06 18:15:00'),
    ]);
  }

  public function testARowBecomesTheNineDocumentedCells() {
    $rows = myapi_service_offers_admin_table_rows([$this->listRow()], [700 => TRUE]);

    $this->assertSame([
      '<a href="/node/700/edit">700</a>',
      '<a href="/admin/content/service-requests/312">Cambio de bomba de agua</a>',
      'Edificio El Sáuco',
      'Plomería Sur',
      '1.500,00 (Precio cerrado)',
      'Enviada',
      '05/10/2026 11:00',
      '20/10/2026',
      '06/10/2026 18:15',
    ], $rows[0]);
  }

  /**
   * The Solicitud column opens the supervision detail of SPEC 126, for every
   * reader — the ID is the one that depends on the edit permission.
   */
  public function testTheRequestCellOpensTheSupervisionDetail() {
    $rows = myapi_service_offers_admin_table_rows([$this->listRow()], [700 => FALSE]);

    $this->assertSame('<a href="/node/700">700</a>', $rows[0][0]);
    $this->assertSame('<a href="/admin/content/service-requests/312">Cambio de bomba de agua</a>', $rows[0][1]);
  }

  /**
   * No request at all, a request that is gone, a request with no title.
   */
  public function testTheRequestCellHasThreeShapes() {
    $none = myapi_service_offers_admin_table_rows([$this->listRow(['request_raw' => NULL, 'request_id' => NULL, 'request_title' => NULL])], []);
    $gone = myapi_service_offers_admin_table_rows([$this->listRow(['request_id' => NULL, 'request_title' => NULL])], []);
    $untitled = myapi_service_offers_admin_table_rows([$this->listRow(['request_title' => ''])], []);

    $this->assertSame('—', $none[0][1]);
    $this->assertSame('Solicitud eliminada (#312)', $gone[0][1]);
    $this->assertSame('<a href="/admin/content/service-requests/312">312</a>', $untitled[0][1]);
  }

  public function testTheProviderCellHasThreeShapes() {
    $none = myapi_service_offers_admin_table_rows([$this->listRow(['provider_raw' => NULL, 'provider_title' => NULL])], []);
    $gone = myapi_service_offers_admin_table_rows([$this->listRow(['provider_title' => NULL])], []);
    $escaped = myapi_service_offers_admin_table_rows([$this->listRow(['provider_title' => '<b>x</b>'])], []);

    $this->assertSame('—', $none[0][3]);
    $this->assertSame('Proveedor eliminado (#88)', $gone[0][3]);
    $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $escaped[0][3]);
  }

  public function testARowMissingEverythingOptionalStillHasItsNineCells() {
    $rows = myapi_service_offers_admin_table_rows([$this->listRow([
      'request_raw' => NULL, 'request_id' => NULL, 'request_title' => NULL,
      'condominium_title' => NULL,
      'provider_raw' => NULL, 'provider_title' => NULL,
      'amount' => NULL, 'amount_type' => NULL,
      'offer_status' => NULL,
      'valid_until' => NULL,
      'last_message_at' => NULL,
    ])], []);

    $this->assertSame([
      '<a href="/node/700">700</a>',
      '—',
      '—',
      '—',
      '—',
      '—',
      '05/10/2026 11:00',
      '—',
      'Sin chat',
    ], $rows[0]);
  }

  /* -------------------------------------------------------------------------
   * Who may edit what.
   * ---------------------------------------------------------------------- */

  public function testBypassNodeAccessSkipsTheNodeLoadEntirely() {
    $GLOBALS['myapi_test_permissions']['bypass node access'] = TRUE;

    $this->assertSame([700 => TRUE], myapi_service_offers_admin_editable_map([$this->listRow()]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  public function testWithoutBypassEachNodeIsAskedInOneBatch() {
    $GLOBALS['myapi_test_node_access']['update:700'] = FALSE;
    $GLOBALS['myapi_test_node_access']['update:701'] = TRUE;
    myapi_test_node_seed([
      700 => ['nid' => 700, 'type' => MYAPI_SERVICES_OFFER_TYPE],
      701 => ['nid' => 701, 'type' => MYAPI_SERVICES_OFFER_TYPE],
    ]);

    $this->assertSame([700 => FALSE, 701 => TRUE], myapi_service_offers_admin_editable_map([$this->listRow(), $this->listRow(['nid' => 701])]));
    $this->assertSame([[700, 701]], myapi_test_node_load_multiple_calls());
  }

  /* -------------------------------------------------------------------------
   * The query.
   * ---------------------------------------------------------------------- */

  /**
   * The offer status is seeded under its QUALIFIED source name, so it cannot
   * be confused with n.status.
   */
  private function offerRow(array $overrides = []) {
    return $overrides + [
      'nid'     => 700,
      'type'    => MYAPI_SERVICES_OFFER_TYPE,
      'status'  => 1,
      'created' => strtotime('2026-10-05 11:00:00'),
      'request_raw'   => 312,
      'request_id'    => 312,
      'request_title' => 'Cambio de bomba de agua',
      'field_condominium_target_id' => 12,
      'condominium_title' => 'Edificio El Sáuco',
      'field_provider_target_id' => 88,
      'provider_raw'   => 88,
      'provider_title' => 'Plomería Sur',
      'amount'      => '1500.00',
      'amount_type' => 'fixed',
      'fos.field_offer_status_value' => 'sent',
      'valid_until'     => strtotime('2026-10-20 00:00:00'),
      'last_message_at' => strtotime('2026-10-06 18:15:00'),
    ];
  }

  private function runList($condominium = NULL, $provider = NULL, $status = NULL, $from = NULL, $to = NULL) {
    return myapi_service_offers_admin_list_rows($condominium, $provider, $status, $from, $to)->execute()->fetchAll();
  }

  private function nids(array $rows) {
    return array_map('intval', array_column($rows, 'nid'));
  }

  public function testTheListingQueryCarriesTheNodeAccessTagAndThePager() {
    myapi_test_db_seed(['node' => [$this->offerRow()]]);

    $this->runList();

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['node_access'], $query['tags']);
    $this->assertSame(['PagerDefault'], $query['extenders']);
    $this->assertSame(['start' => 0, 'length' => 20], $query['range']);
  }

  public function testEveryJoinIsLeft() {
    myapi_test_db_seed(['node' => [$this->offerRow()]]);

    $this->runList();

    $this->assertSame(['LEFT'], array_values(array_unique(array_column(myapi_test_db_queries('node')[0]['joins'], 'type'))));
  }

  /**
   * The condominium is joined through the REQUEST, never from the offer.
   */
  public function testTheCondominiumFilterReadsTheRequestsCondominium() {
    myapi_test_db_seed(['node' => [
      $this->offerRow(),
      $this->offerRow(['nid' => 701, 'field_condominium_target_id' => 30]),
    ]]);

    $this->assertSame([700], $this->nids($this->runList(12)));

    $joins = myapi_test_db_queries('node')[0]['joins'];
    $condominium_join = array_values(array_filter($joins, function ($join) {
      return $join['alias'] === 'fco';
    }))[0];
    $this->assertStringContainsString('fco.entity_id = freq.field_request_target_id', $condominium_join['condition']);
  }

  public function testOnlyPublishedOffersNewestFirst() {
    myapi_test_db_seed(['node' => [
      $this->offerRow(['nid' => 700]),
      $this->offerRow(['nid' => 702]),
      $this->offerRow(['nid' => 701]),
      $this->offerRow(['nid' => 703, 'status' => 0]),
      $this->offerRow(['nid' => 704, 'type' => MYAPI_SERVICES_REQUEST_TYPE]),
    ]]);

    $this->assertSame([702, 701, 700], $this->nids($this->runList()));
  }

  public function testEachFilterNarrowsOnItsOwn() {
    $seed = function () {
      myapi_test_db_seed(['node' => [
        $this->offerRow(),
        $this->offerRow([
          'nid' => 701,
          'field_condominium_target_id' => 30,
          'field_provider_target_id' => 89,
          'fos.field_offer_status_value' => 'rejected',
          'created' => strtotime('2026-11-02 09:00:00'),
        ]),
      ]]);
    };

    $seed();
    $this->assertSame([701], $this->nids($this->runList(30)));
    $seed();
    $this->assertSame([700], $this->nids($this->runList(NULL, 88)));
    $seed();
    $this->assertSame([701], $this->nids($this->runList(NULL, NULL, 'rejected')));
    $seed();
    $this->assertSame([701], $this->nids($this->runList(NULL, NULL, NULL, strtotime('2026-11-01 00:00:00'))));
    $seed();
    $this->assertSame([700], $this->nids($this->runList(NULL, NULL, NULL, NULL, strtotime('2026-10-31 23:59:59'))));
  }

  public function testTheRowCarriesEveryProjectedColumn() {
    myapi_test_db_seed(['node' => [$this->offerRow()]]);

    $rows = $this->runList();

    $this->assertSame([
      'nid',
      'created',
      'request_raw',
      'request_id',
      'request_title',
      'condominium_title',
      'provider_raw',
      'provider_title',
      'amount',
      'amount_type',
      'offer_status',
      'valid_until',
      'last_message_at',
    ], array_keys((array) $rows[0]));
    $this->assertSame('sent', $rows[0]->offer_status);
  }

}
