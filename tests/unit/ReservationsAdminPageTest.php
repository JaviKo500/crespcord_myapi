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
require_once __DIR__ . '/../../includes/myapi.claims_common.inc';
require_once __DIR__ . '/../../includes/myapi.claim_query.inc';
require_once __DIR__ . '/../../includes/myapi.claims_admin.inc';
require_once __DIR__ . '/../../includes/myapi.reservations_admin.inc';

/**
 * Unit tests for the back-office reservation listing (SPEC 131):
 * includes/myapi.reservations_admin.inc.
 *
 * Same shape as ServiceRequestsAdminPageTest — the page it is modelled on —
 * and the same division of labour: everything that decides something is
 * tested here, and myapi_reservations_admin_page() / _filter_form() are not,
 * because what they do is drupal_get_form(), theme('pager') and
 * drupal_add_css().
 *
 * Three cases here are worth more than they look:
 *
 *  - testTheListingQueryCarriesTheNodeAccessTag(). That tag is the ONLY thing
 *    restricting an 'administrador edificio' to the reservations of their own
 *    buildings.
 *  - testTheDateRangeFiltersTheReservationDateNotTheCreationDate(). The
 *    question this page answers is "what is booked", so the range reads
 *    field_date, unlike every other back-office listing of the module.
 *  - testTheListingIsOrderedByReservationDateThenStartTimeThenNid(). Decision
 *    6 of SPEC 131: the furthest booking on top, not the newest node.
 */
class ReservationsAdminPageTest extends TestCase {

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
    myapi_test_field_seed_allowed_values([
      'field_reservation_status' => ['confirmed' => 'Confirmada', 'cancelled' => 'Cancelada'],
      'field_cancelled_by'       => ['user' => 'Usuario', 'admin' => 'Admin'],
    ]);
    $GLOBALS['myapi_test_permissions'] = [];
    $GLOBALS['myapi_test_node_access'] = [];
    $GLOBALS['myapi_test_node_access_calls'] = [];
    $GLOBALS['myapi_test_node_load_multiple'] = [];
    unset($GLOBALS['myapi_test_node_access_default']);
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * The catalogues.
   * ---------------------------------------------------------------------- */

  /**
   * The status select is the field's own allowed_values, untouched: this page
   * declares no label of its own.
   */
  public function testTheStatusOptionsComeFromTheField() {
    $this->assertSame(
      ['confirmed' => 'Confirmada', 'cancelled' => 'Cancelada'],
      myapi_reservations_admin_status_options()
    );
  }

  /**
   * A site missing the field answers an empty select rather than a notice.
   */
  public function testAMissingStatusFieldAnswersNoOptions() {
    myapi_test_field_seed_allowed_values([]);

    $this->assertSame([], myapi_reservations_admin_status_options());
  }

  /* -------------------------------------------------------------------------
   * The GET filters.
   * ---------------------------------------------------------------------- */

  /**
   * No query string at all: no filter anywhere. The condominium is resolved
   * by the page, with the reader's scope, exactly as in SPEC 56/125.
   */
  public function testNoQueryStringMeansNoFilters() {
    $this->assertSame([
      'area'      => NULL,
      'status'    => NULL,
      'date_from' => NULL,
      'date_to'   => NULL,
    ], myapi_reservations_admin_list_filters());
  }

  public function testTheValidFiltersPassThrough() {
    $_GET = [
      'area'      => '41',
      'status'    => 'cancelled',
      'date_from' => '2026-10-01',
      'date_to'   => '2026-10-31',
    ];

    $this->assertSame([
      'area'      => 41,
      'status'    => 'cancelled',
      'date_from' => '2026-10-01',
      'date_to'   => '2026-10-31',
    ], myapi_reservations_admin_list_filters());
  }

  /**
   * Garbage in any parameter means no filter on it, never an error.
   */
  public function testEveryMalformedFilterFallsBackToNoFilter() {
    $_GET = [
      'area'      => '-1',
      'status'    => 'xyz',
      'date_from' => '2026-02-30',
      'date_to'   => '31/10/2026',
    ];

    $this->assertSame([
      'area'      => NULL,
      'status'    => NULL,
      'date_from' => NULL,
      'date_to'   => NULL,
    ], myapi_reservations_admin_list_filters());
  }

  public function testArrayFiltersAreIgnoredWithoutAFatal() {
    $_GET = [
      'area'      => ['41'],
      'status'    => ['confirmed'],
      'date_from' => ['2026-10-01'],
      'date_to'   => ['2026-10-31'],
    ];

    $this->assertSame([
      'area'      => NULL,
      'status'    => NULL,
      'date_from' => NULL,
      'date_to'   => NULL,
    ], myapi_reservations_admin_list_filters());
  }

  /**
   * The two bounds are independent: a broken one does not drop the other.
   */
  public function testTheTwoDateBoundsAreIndependent() {
    $_GET = ['date_from' => 'ayer', 'date_to' => '2026-10-31'];

    $filters = myapi_reservations_admin_list_filters();

    $this->assertNull($filters['date_from']);
    $this->assertSame('2026-10-31', $filters['date_to']);
  }

  /**
   * The area is only honoured when it is one of the areas of the effective
   * condominium — a hand-typed area of somebody else's building filters
   * nothing.
   */
  public function testAnAreaOutsideTheEffectiveCondominiumIsDropped() {
    $options = [41 => 'Piscina', 42 => 'Quincho'];

    $this->assertSame(41, myapi_reservations_admin_effective_area(41, $options));
    $this->assertNull(myapi_reservations_admin_effective_area(99, $options));
    $this->assertNull(myapi_reservations_admin_effective_area(NULL, $options));
    $this->assertNull(myapi_reservations_admin_effective_area(41, []), 'no condominium selected means no area to pick from');
  }

  /* -------------------------------------------------------------------------
   * Row labels.
   * ---------------------------------------------------------------------- */

  /**
   * field_date is a datetime stored as a string; the cell shows the day only.
   */
  public function testTheDateLabelShowsTheDayOnly() {
    $this->assertSame('14/10/2026', myapi_reservations_admin_date_label('2026-10-14 00:00:00'));
    $this->assertSame('14/10/2026', myapi_reservations_admin_date_label('2026-10-14'));
    $this->assertSame('—', myapi_reservations_admin_date_label(NULL));
    $this->assertSame('—', myapi_reservations_admin_date_label(''));
    $this->assertSame('—', myapi_reservations_admin_date_label('mañana'));
  }

  public function testTheCreatedLabelCarriesTheTime() {
    $this->assertSame('04/10/2026 16:45', myapi_reservations_admin_created_label(strtotime('2026-10-04 16:45:00')));
  }

  /**
   * 'HH:MM – HH:MM', and an em dash when either end is missing. An overnight
   * booking keeps its stored values (SPEC 41): '22:00 – 02:00'.
   */
  public function testTheScheduleLabel() {
    $this->assertSame('09:00 – 11:30', myapi_reservations_admin_schedule_label('09:00', '11:30'));
    $this->assertSame('22:00 – 02:00', myapi_reservations_admin_schedule_label('22:00', '02:00'));
    $this->assertSame('—', myapi_reservations_admin_schedule_label(NULL, '11:30'));
    $this->assertSame('—', myapi_reservations_admin_schedule_label('09:00', ''));
    $this->assertSame('&lt;b&gt; – 11:30', myapi_reservations_admin_schedule_label('<b>', '11:30'));
  }

  public function testTheTextLabelEscapesAndFallsBackToADash() {
    $this->assertSame('Piscina', myapi_reservations_admin_text_label('Piscina'));
    $this->assertSame('—', myapi_reservations_admin_text_label(NULL));
    $this->assertSame('—', myapi_reservations_admin_text_label(''));
    $this->assertSame('&lt;b&gt;x&lt;/b&gt;', myapi_reservations_admin_text_label('<b>x</b>'));
  }

  public function testTheStatusLabelUsesTheField() {
    $this->assertSame('Confirmada', myapi_reservations_admin_status_label('confirmed'));
    $this->assertSame('Cancelada', myapi_reservations_admin_status_label('cancelled'));
    $this->assertSame('—', myapi_reservations_admin_status_label(NULL));
    $this->assertSame('inventado', myapi_reservations_admin_status_label('inventado'), 'an unknown key prints raw');
  }

  /**
   * The requester: nobody, a deleted account, or the name — full name first,
   * the account name when the profile has none. Same criterion as
   * myapi_calendar_user_name_label().
   */
  public function testTheRequesterLabel() {
    $base = ['uid' => '3', 'user_name' => 'pcordero', 'user_first_name' => NULL, 'user_last_name' => NULL];

    $this->assertSame('Sin solicitante', myapi_reservations_admin_requester_label((object) (['uid' => NULL, 'user_name' => NULL] + $base)));
    $this->assertSame('Usuario eliminado (#41)', myapi_reservations_admin_requester_label((object) (['uid' => '41', 'user_name' => NULL] + $base)));
    $this->assertSame('pcordero', myapi_reservations_admin_requester_label((object) $base));
    $this->assertSame('Pablo Cordero', myapi_reservations_admin_requester_label((object) (['user_first_name' => 'Pablo', 'user_last_name' => 'Cordero'] + $base)));
    $this->assertSame('&lt;script&gt;', myapi_reservations_admin_requester_label((object) (['user_name' => '<script>'] + $base)));
  }

  /**
   * Who cancelled, and why: 'Usuario: <reason>' / 'Admin: <reason>', only who
   * when there is no reason, an em dash when nobody did.
   */
  public function testTheCancellationLabel() {
    $this->assertSame('Usuario: Viaje imprevisto', myapi_reservations_admin_cancellation_label('user', 'Viaje imprevisto'));
    $this->assertSame('Admin: Mantención de la piscina', myapi_reservations_admin_cancellation_label('admin', 'Mantención de la piscina'));
    $this->assertSame('Admin', myapi_reservations_admin_cancellation_label('admin', NULL));
    $this->assertSame('Usuario', myapi_reservations_admin_cancellation_label('user', '   '));
    $this->assertSame('—', myapi_reservations_admin_cancellation_label(NULL, NULL));
    $this->assertSame('robot', myapi_reservations_admin_cancellation_label('robot', NULL), 'an unknown key prints raw');
    $this->assertSame('Usuario: &lt;b&gt;', myapi_reservations_admin_cancellation_label('user', '<b>'));
  }

  /* -------------------------------------------------------------------------
   * The table body.
   * ---------------------------------------------------------------------- */

  private function listRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'               => '512',
      'created'           => (string) strtotime('2026-10-04 16:45:00'),
      'reservation_date'  => '2026-10-20 00:00:00',
      'start_time'        => '09:00',
      'end_time'          => '11:30',
      'area_title'        => 'Piscina',
      'condominium_title' => 'Edificio El Sáuco',
      'unit_title'        => 'Depto 301',
      'uid'               => '3',
      'user_name'         => 'pcordero',
      'user_first_name'   => 'Pablo',
      'user_last_name'    => 'Cordero',
      'status'            => 'cancelled',
      'cancelled_by'      => 'user',
      'cancel_reason'     => 'Viaje imprevisto',
    ]);
  }

  /**
   * The ten cells of SPEC 131, in the order of the table header.
   */
  public function testARowBecomesTheTenDocumentedCells() {
    $rows = myapi_reservations_admin_table_rows([$this->listRow()], [512 => TRUE]);

    $this->assertCount(1, $rows);
    $this->assertSame([
      '<a href="/node/512/edit">512</a>',
      '20/10/2026',
      '09:00 – 11:30',
      'Piscina',
      'Edificio El Sáuco',
      'Depto 301',
      'Pablo Cordero',
      'Cancelada',
      'Usuario: Viaje imprevisto',
      '04/10/2026 16:45',
    ], $rows[0]);
  }

  /**
   * A reader who cannot edit the node is sent to the node itself, never to a
   * 403; and a nid absent from the map counts as "cannot edit".
   */
  public function testTheIdLinksToTheNodeWhenTheReaderCannotEdit() {
    $this->assertSame('<a href="/node/512">512</a>', myapi_reservations_admin_table_rows([$this->listRow()], [512 => FALSE])[0][0]);
    $this->assertSame('<a href="/node/512">512</a>', myapi_reservations_admin_table_rows([$this->listRow()], [])[0][0]);
  }

  /**
   * Every joined column degrades on its own, and the row still shows.
   */
  public function testARowMissingEverythingOptionalStillHasItsTenCells() {
    $rows = myapi_reservations_admin_table_rows([$this->listRow([
      'reservation_date'  => NULL,
      'start_time'        => NULL,
      'end_time'          => NULL,
      'area_title'        => NULL,
      'condominium_title' => NULL,
      'unit_title'        => NULL,
      'uid'               => NULL,
      'user_name'         => NULL,
      'user_first_name'   => NULL,
      'user_last_name'    => NULL,
      'status'            => NULL,
      'cancelled_by'      => NULL,
      'cancel_reason'     => NULL,
    ])], [512 => TRUE]);

    $this->assertSame([
      '<a href="/node/512/edit">512</a>',
      '—',
      '—',
      '—',
      '—',
      '—',
      'Sin solicitante',
      '—',
      '—',
      '04/10/2026 16:45',
    ], $rows[0]);
  }

  public function testNoRowsProduceNoCells() {
    $this->assertSame([], myapi_reservations_admin_table_rows([], []));
  }

  /* -------------------------------------------------------------------------
   * Who may edit what.
   * ---------------------------------------------------------------------- */

  /**
   * 'bypass node access' answers for every row and loads no node.
   */
  public function testBypassNodeAccessSkipsTheNodeLoadEntirely() {
    $GLOBALS['myapi_test_permissions']['bypass node access'] = TRUE;

    $map = myapi_reservations_admin_editable_map([$this->listRow(), $this->listRow(['nid' => 513])]);

    $this->assertSame([512 => TRUE, 513 => TRUE], $map);
    $this->assertSame([], myapi_test_node_load_multiple_calls());
    $this->assertSame([], $GLOBALS['myapi_test_node_access_calls']);
  }

  /**
   * Anybody else is asked node by node, in ONE batch load.
   */
  public function testWithoutBypassEachNodeIsAskedInOneBatch() {
    $GLOBALS['myapi_test_node_access']['update:512'] = FALSE;
    $GLOBALS['myapi_test_node_access']['update:513'] = TRUE;
    myapi_test_node_seed([
      512 => ['nid' => 512, 'type' => 'reservation'],
      513 => ['nid' => 513, 'type' => 'reservation'],
    ]);

    $map = myapi_reservations_admin_editable_map([$this->listRow(), $this->listRow(['nid' => 513])]);

    $this->assertSame([512 => FALSE, 513 => TRUE], $map);
    $this->assertSame([[512, 513]], myapi_test_node_load_multiple_calls());
  }

  public function testNoRowsResolveNoPermissions() {
    $this->assertSame([], myapi_reservations_admin_editable_map([]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  /* -------------------------------------------------------------------------
   * The query.
   * ---------------------------------------------------------------------- */

  /**
   * A published reservation with every column the listing projects. The
   * status is seeded under its QUALIFIED source name: the alias 'status'
   * collides with node.status, the published flag.
   */
  private function reservationRow(array $overrides = []) {
    return $overrides + [
      'nid'     => 512,
      'type'    => 'reservation',
      'status'  => 1,
      'created' => strtotime('2026-10-04 16:45:00'),
      'field_condominium_target_id' => 12,
      'condominium_title' => 'Edificio El Sáuco',
      'field_area_target_id' => 41,
      'area_title'        => 'Piscina',
      'unit_title'        => 'Depto 301',
      'uid'               => 3,
      'user_name'         => 'pcordero',
      'user_first_name'   => 'Pablo',
      'user_last_name'    => 'Cordero',
      'field_date_value'  => '2026-10-20 00:00:00',
      'reservation_date'  => '2026-10-20 00:00:00',
      'start_time'        => '09:00',
      'end_time'          => '11:30',
      'frs.field_reservation_status_value' => 'confirmed',
      'cancelled_by'      => NULL,
      'cancel_reason'     => NULL,
    ];
  }

  private function runList($condominium_id = NULL, $area_id = NULL, $status = NULL, $date_from = NULL, $date_to = NULL) {
    return myapi_reservations_admin_list_rows($condominium_id, $area_id, $status, $date_from, $date_to)
      ->execute()
      ->fetchAll();
  }

  private function nids(array $rows) {
    return array_map('intval', array_column($rows, 'nid'));
  }

  /**
   * THE assertion of this file: the node_access tag is what narrows an
   * 'administrador edificio' to their own condominiums.
   */
  public function testTheListingQueryCarriesTheNodeAccessTag() {
    myapi_test_db_seed(['node' => [$this->reservationRow()]]);

    $this->runList();

    $this->assertSame(['node_access'], myapi_test_db_queries('node')[0]['tags']);
  }

  public function testTheListingQueryIsExtendedWithThePager() {
    myapi_test_db_seed(['node' => [$this->reservationRow()]]);

    $this->runList();

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['PagerDefault'], $query['extenders']);
    $this->assertSame(['start' => 0, 'length' => 20], $query['range']);
  }

  /**
   * Every join is LEFT: a broken reference shows a dash, never loses its row.
   */
  public function testEveryJoinIsLeft() {
    myapi_test_db_seed(['node' => [$this->reservationRow()]]);

    $this->runList();

    $joins = myapi_test_db_queries('node')[0]['joins'];
    $this->assertNotEmpty($joins);
    $this->assertSame(['LEFT'], array_values(array_unique(array_column($joins, 'type'))));
  }

  public function testOnlyPublishedReservationsAreListed() {
    myapi_test_db_seed(['node' => [
      $this->reservationRow(),
      $this->reservationRow(['nid' => 513, 'status' => 0]),
      $this->reservationRow(['nid' => 514, 'type' => 'reclamo']),
    ]]);

    $this->assertSame([512], $this->nids($this->runList()));
  }

  /**
   * Decision 6: the furthest booking on top, then the latest start that day,
   * then the highest nid.
   */
  public function testTheListingIsOrderedByReservationDateThenStartTimeThenNid() {
    myapi_test_db_seed(['node' => [
      $this->reservationRow(['nid' => 600, 'field_date_value' => '2026-10-18 00:00:00', 'start_time' => '09:00']),
      $this->reservationRow(['nid' => 510, 'field_date_value' => '2026-10-25 00:00:00', 'start_time' => '09:00']),
      $this->reservationRow(['nid' => 520, 'field_date_value' => '2026-10-20 00:00:00', 'start_time' => '09:00']),
      $this->reservationRow(['nid' => 530, 'field_date_value' => '2026-10-20 00:00:00', 'start_time' => '18:00']),
      $this->reservationRow(['nid' => 540, 'field_date_value' => '2026-10-20 00:00:00', 'start_time' => '09:00']),
    ]]);

    $this->assertSame([510, 530, 540, 520, 600], $this->nids($this->runList()));
  }

  public function testEachFilterNarrowsOnItsOwn() {
    $seed = function () {
      myapi_test_db_seed(['node' => [
        $this->reservationRow(),
        $this->reservationRow([
          'nid' => 513,
          'field_condominium_target_id' => 30,
          'field_area_target_id' => 77,
          'frs.field_reservation_status_value' => 'cancelled',
          'field_date_value' => '2026-11-05 00:00:00',
        ]),
      ]]);
    };

    $seed();
    $this->assertSame([512], $this->nids($this->runList(12)));
    $seed();
    $this->assertSame([513], $this->nids($this->runList(NULL, 77)));
    $seed();
    $this->assertSame([513], $this->nids($this->runList(NULL, NULL, 'cancelled')));
    $seed();
    $this->assertSame([513], $this->nids($this->runList(NULL, NULL, NULL, '2026-11-01')));
    $seed();
    $this->assertSame([512], $this->nids($this->runList(NULL, NULL, NULL, NULL, '2026-10-31')));
  }

  /**
   * The range reads field_date, the day of the booking. A reservation created
   * on 4 October for 20 October is inside a 20-October range and outside a
   * 4-October one.
   */
  public function testTheDateRangeFiltersTheReservationDateNotTheCreationDate() {
    myapi_test_db_seed(['node' => [$this->reservationRow()]]);
    $this->assertSame([512], $this->nids($this->runList(NULL, NULL, NULL, '2026-10-20', '2026-10-20')), 'the whole day of the booking is inside');

    myapi_test_db_seed(['node' => [$this->reservationRow()]]);
    $this->assertSame([], $this->nids($this->runList(NULL, NULL, NULL, '2026-10-04', '2026-10-04')), 'the creation day is not');
  }

  /**
   * The bound is a filter on a left-joined column: a reservation with no date
   * is excluded when a range is asked for, and listed when it is not.
   */
  public function testAReservationWithNoDateIsOnlyListedWithoutARange() {
    $row = $this->reservationRow(['field_date_value' => NULL, 'reservation_date' => NULL]);

    myapi_test_db_seed(['node' => [$row]]);
    $this->assertSame([512], $this->nids($this->runList()));

    myapi_test_db_seed(['node' => [$row]]);
    $this->assertSame([], $this->nids($this->runList(NULL, NULL, NULL, '2026-10-01')));
  }

  /**
   * The row carries the columns the table reads, under the names the labels
   * expect. A renamed alias breaks the page silently — every cell would answer
   * its em dash — so the shape is pinned here.
   */
  public function testTheRowCarriesEveryProjectedColumn() {
    myapi_test_db_seed(['node' => [$this->reservationRow()]]);

    $rows = $this->runList();

    $this->assertSame([
      'nid',
      'created',
      'condominium_title',
      'area_title',
      'unit_title',
      'uid',
      'user_name',
      'user_first_name',
      'user_last_name',
      'reservation_date',
      'start_time',
      'end_time',
      'status',
      'cancelled_by',
      'cancel_reason',
    ], array_keys((array) $rows[0]));
    $this->assertSame('confirmed', $rows[0]->status, 'the reservation status, not the published flag');
  }

}
