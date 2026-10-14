<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../resources/reservation.resource.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_calendar.inc';
require_once __DIR__ . '/../../includes/myapi.claims_common.inc';
require_once __DIR__ . '/../../includes/myapi.claim_query.inc';
require_once __DIR__ . '/../../includes/myapi.claims_admin.inc';
require_once __DIR__ . '/../../includes/myapi.payment_workflow.inc';
require_once __DIR__ . '/../../includes/myapi.payments_admin.inc';

/**
 * Unit tests for the back-office payments report (SPEC 132):
 * includes/myapi.payments_admin.inc.
 *
 * Same division of labour as ServiceRequestsAdminPageTest, the page it is
 * modelled on: everything that DECIDES something — which filter applies, which
 * date range is the default, what a cell or a CSV field says — is a pure
 * helper and is tested here. The page, the filter form and the CSV callback
 * only assemble those pieces around drupal_get_form(), theme('pager') and
 * php://output, so they are not.
 *
 * Three cases here are worth more than they look:
 *
 *  - testAbsentDatesApplyTheDefaultRangeButEmptyOnesRemoveIt(). "Absent" and
 *    "empty" are different requests (decision 6): the first load of the page
 *    gets the last 30 days, an operator who cleared both boxes gets no limit.
 *  - testTheCsvLinkCarriesTheEffectiveDates(). The CSV re-reads the filters
 *    from its own query string, so the button must send the dates the table
 *    is ACTUALLY using — the default range included, and the "no limit" as two
 *    empty values — or the file and the screen stop matching (decision 12).
 *  - testFormulaTriggersAreNeutralised(). References and names come from the
 *    app and the bot, i.e. from residents; a cell starting with '=' would run
 *    in the operator's Excel (decision 14).
 */
class PaymentsAdminPageTest extends TestCase {

  /**
   * 2026-10-14 12:00:00, built with mktime() like every other fixture so
   * format_date() (date() in the stub) answers in the same timezone.
   */
  private $now;

  protected function setUp(): void {
    $this->now = mktime(12, 0, 0, 10, 14, 2026);
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
    myapi_test_field_seed_allowed_values([
      'field_estado_pago' => [
        'Nuevo'                  => 'Nuevo',
        'Completado'             => 'Completado',
        'Anulado'                => 'Anulado',
        'Pendiente de verificar' => 'Pendiente de verificar',
      ],
      'field_forma_de_pago' => [
        'Deposito'      => 'Deposito',
        'Efectivo'      => 'Efectivo',
        'Cheque'        => 'Cheque',
        'Transferencia' => 'Transferencia',
        'PayPhone'      => 'PayPhone',
        'Tarjeta'       => 'Tarjeta',
      ],
      'field_canal' => [
        'bot'        => 'Bot de WhatsApp',
        'app'        => 'App',
        'backoffice' => 'Back office',
      ],
    ]);
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * The select catalogues.
   * ---------------------------------------------------------------------- */

  /**
   * Status and payment method come from the fields' own allowed_values: the
   * page declares neither catalogue.
   */
  public function testTheStatusAndMethodOptionsComeFromTheFields() {
    $this->assertSame(['Nuevo', 'Completado', 'Anulado', 'Pendiente de verificar'], array_keys(myapi_payments_admin_status_options()));
    $this->assertSame(['Deposito', 'Efectivo', 'Cheque', 'Transferencia', 'PayPhone', 'Tarjeta'], array_keys(myapi_payments_admin_method_options()));
  }

  /**
   * The three channels of SPEC 129, keyed by the constants and labelled by
   * field_canal. All three are offered (decision 5): without 'backoffice' the
   * migrated history could not be isolated.
   */
  public function testTheChannelOptionsAreTheThreeConstants() {
    $this->assertSame([
      MYAPI_PAYMENT_CHANNEL_APP        => 'App',
      MYAPI_PAYMENT_CHANNEL_BOT        => 'Bot de WhatsApp',
      MYAPI_PAYMENT_CHANNEL_BACKOFFICE => 'Back office',
    ], myapi_payments_admin_channel_options());
  }

  /**
   * A site missing field_canal still offers the three channels, under their
   * raw keys: the filter validates against the constants, not the field.
   */
  public function testTheChannelOptionsSurviveAMissingField() {
    unset($GLOBALS['myapi_test_fields']['field_canal']);

    $this->assertSame(['app' => 'app', 'bot' => 'bot', 'backoffice' => 'backoffice'], myapi_payments_admin_channel_options());
  }

  /* -------------------------------------------------------------------------
   * The GET filters.
   * ---------------------------------------------------------------------- */

  public function testTheValidFiltersPassThrough() {
    $_GET = [
      'condominium'        => '12',
      'status'             => 'Completado',
      'unit_name'          => '  Torre B 302 ',
      'owner_last_name'    => 'Cordero',
      'occupant_last_name' => 'Peña',
      'payment_method'     => 'Transferencia',
      'channel'            => 'bot',
      'date_from'          => '2026-09-01',
      'date_to'            => '2026-09-30',
    ];

    $this->assertSame([
      'condominium'        => 12,
      'status'             => 'Completado',
      'unit_name'          => 'Torre B 302',
      'owner_last_name'    => 'Cordero',
      'occupant_last_name' => 'Peña',
      'payment_method'     => 'Transferencia',
      'channel'            => 'bot',
      'date_from'          => '2026-09-01',
      'date_to'            => '2026-09-30',
    ], myapi_payments_admin_list_filters($this->now), 'the condominium is an int, the texts are trimmed');
  }

  /**
   * Nothing ever fails: every malformed value is "no filter". The dates are
   * present (and invalid), so no default range either.
   */
  public function testEveryMalformedFilterFallsBackToNoFilter() {
    $_GET = [
      'condominium'        => '-1',
      'status'             => 'xyz',
      'unit_name'          => '   ',
      'owner_last_name'    => str_repeat('a', 101),
      'occupant_last_name' => '',
      'payment_method'     => 'Bitcoin',
      'channel'            => 'whatsapp',
      'date_from'          => '2026-02-30',
      'date_to'            => '30/09/2026',
    ];

    $this->assertSame([
      'condominium'        => NULL,
      'status'             => NULL,
      'unit_name'          => NULL,
      'owner_last_name'    => NULL,
      'occupant_last_name' => NULL,
      'payment_method'     => NULL,
      'channel'            => NULL,
      'date_from'          => NULL,
      'date_to'            => NULL,
    ], myapi_payments_admin_list_filters($this->now));
  }

  /**
   * ?channel[]=bot and friends: an array never reaches a string function.
   */
  public function testArrayFiltersAreIgnoredWithoutAFatal() {
    $_GET = [
      'condominium'        => ['12'],
      'status'             => ['Completado'],
      'unit_name'          => ['x'],
      'owner_last_name'    => ['x'],
      'occupant_last_name' => ['x'],
      'payment_method'     => ['Efectivo'],
      'channel'            => ['bot'],
      'date_from'          => ['2026-09-01'],
      'date_to'            => ['2026-09-30'],
    ];

    foreach (myapi_payments_admin_list_filters($this->now) as $key => $value) {
      $this->assertNull($value, $key);
    }
  }

  /**
   * 1–100 characters, counted as characters and not bytes: 100 'ñ' are 200
   * bytes and still a valid filter.
   */
  public function testTheTextFiltersAcceptOneToAHundredCharacters() {
    $this->assertSame('a', myapi_payments_admin_text_filter('a'));
    $this->assertSame(str_repeat('ñ', 100), myapi_payments_admin_text_filter(str_repeat('ñ', 100)));
    $this->assertNull(myapi_payments_admin_text_filter(str_repeat('a', 101)));
    $this->assertNull(myapi_payments_admin_text_filter(''));
    $this->assertNull(myapi_payments_admin_text_filter(" \t "));
    $this->assertNull(myapi_payments_admin_text_filter(NULL));
    $this->assertNull(myapi_payments_admin_text_filter(['a']));
  }

  /**
   * The channel is matched against the three constants, case and all.
   */
  public function testTheChannelMustBeOneOfTheThreeConstants() {
    foreach (['app', 'bot', 'backoffice'] as $channel) {
      $_GET = ['channel' => $channel];
      $this->assertSame($channel, myapi_payments_admin_list_filters($this->now)['channel']);
    }

    $_GET = ['channel' => 'BOT'];
    $this->assertNull(myapi_payments_admin_list_filters($this->now)['channel']);
  }

  /* -------------------------------------------------------------------------
   * The default range (decision 6).
   * ---------------------------------------------------------------------- */

  public function testTheDefaultRangeIsTheLastThirtyDays() {
    $this->assertSame([
      'date_from' => '2026-09-14',
      'date_to'   => '2026-10-14',
    ], myapi_payments_admin_default_range($this->now));
  }

  /**
   * Thirty calendar days, across a month boundary and a leap day.
   */
  public function testTheDefaultRangeCountsCalendarDays() {
    $this->assertSame([
      'date_from' => '2028-01-31',
      'date_to'   => '2028-03-01',
    ], myapi_payments_admin_default_range(mktime(23, 59, 0, 3, 1, 2028)));
  }

  /**
   * THE distinction of the page: absent dates are the first load and get the
   * default; present-but-empty dates are an operator who cleared both boxes,
   * and get no limit at all.
   */
  public function testAbsentDatesApplyTheDefaultRangeButEmptyOnesRemoveIt() {
    $_GET = [];
    $absent = myapi_payments_admin_list_filters($this->now);
    $this->assertSame('2026-09-14', $absent['date_from']);
    $this->assertSame('2026-10-14', $absent['date_to']);

    $_GET = ['date_from' => '', 'date_to' => ''];
    $empty = myapi_payments_admin_list_filters($this->now);
    $this->assertNull($empty['date_from']);
    $this->assertNull($empty['date_to']);
  }

  /**
   * One bound alone is not "absent": it is used as is and the other side is
   * open. The default only applies when the URL names neither.
   */
  public function testTheTwoDateBoundsAreIndependent() {
    $_GET = ['date_from' => '2026-01-01'];
    $filters = myapi_payments_admin_list_filters($this->now);
    $this->assertSame('2026-01-01', $filters['date_from']);
    $this->assertNull($filters['date_to']);

    $_GET = ['date_to' => '2026-01-31'];
    $filters = myapi_payments_admin_list_filters($this->now);
    $this->assertNull($filters['date_from']);
    $this->assertSame('2026-01-31', $filters['date_to']);
  }

  /**
   * The pager adds ?page=N and nothing else, so page 2 of a first load must
   * get the same default range as page 1.
   */
  public function testThePagerAloneStillGetsTheDefaultRange() {
    $_GET = ['page' => '1', 'channel' => 'bot'];
    $filters = myapi_payments_admin_list_filters($this->now);

    $this->assertSame('2026-09-14', $filters['date_from']);
    $this->assertSame('2026-10-14', $filters['date_to']);
  }

  /* -------------------------------------------------------------------------
   * The condominium.
   * ---------------------------------------------------------------------- */

  public function testAnUnknownCondominiumIsDroppedFromTheFilter() {
    $options = [12 => 'Edificio El Sáuco', 14 => 'Torres del Río'];

    $this->assertSame(12, myapi_payments_admin_effective_condominium(12, $options));
    $this->assertNull(myapi_payments_admin_effective_condominium(99, $options));
    $this->assertNull(myapi_payments_admin_effective_condominium(NULL, $options));
  }

  /* -------------------------------------------------------------------------
   * Labels.
   * ---------------------------------------------------------------------- */

  public function testTheTextLabelEscapesAndFallsBackToADash() {
    $this->assertSame('Banco Pichincha', myapi_payments_admin_text_label('Banco Pichincha'));
    $this->assertSame('—', myapi_payments_admin_text_label(NULL));
    $this->assertSame('—', myapi_payments_admin_text_label(''));
    $this->assertSame('&lt;b&gt;x&lt;/b&gt;', myapi_payments_admin_text_label('<b>x</b>'));
  }

  public function testTheStatusLabelUsesTheCatalogueAndPrintsUnknownKeysRaw() {
    $this->assertSame('Pendiente de verificar', myapi_payments_admin_status_label('Pendiente de verificar'));
    $this->assertSame('—', myapi_payments_admin_status_label(NULL));
    $this->assertSame('Rechazado', myapi_payments_admin_status_label('Rechazado'));
    $this->assertSame('&lt;x&gt;', myapi_payments_admin_status_label('<x>'));
  }

  /**
   * The person has three shapes: no reference, a deleted account, or the
   * person — full name when the profile has one, account name otherwise.
   */
  public function testThePersonNameHasThreeShapes() {
    $this->assertNull(myapi_payments_admin_person_name($this->listRow(['owner_raw' => NULL, 'owner_name' => NULL]), 'owner'));
    $this->assertSame('Usuario eliminado (#41)', myapi_payments_admin_person_name($this->listRow(['owner_raw' => '41', 'owner_name' => NULL]), 'owner'));
    $this->assertSame('Pedro Cordero', myapi_payments_admin_person_name($this->listRow(), 'owner'));
    $this->assertSame('pcordero', myapi_payments_admin_person_name($this->listRow(['owner_first_name' => NULL, 'owner_last_name' => NULL]), 'owner'));
    $this->assertSame('Cordero', myapi_payments_admin_person_name($this->listRow(['owner_first_name' => NULL]), 'owner'));
    $this->assertSame('Ana Peña', myapi_payments_admin_person_name($this->listRow(), 'occupant'));
  }

  /**
   * The unit: field_nombre_vivienda first, the title otherwise, and a deleted
   * unit says so instead of vanishing.
   */
  public function testTheUnitNameHasThreeShapes() {
    $this->assertSame('Torre B 302', myapi_payments_admin_unit_name($this->listRow()));
    $this->assertSame('VIV-0302', myapi_payments_admin_unit_name($this->listRow(['unit_name' => NULL])));
    $this->assertSame('VIV-0302', myapi_payments_admin_unit_name($this->listRow(['unit_name' => ''])));
    $this->assertSame('Vivienda eliminada (#77)', myapi_payments_admin_unit_name($this->listRow(['unit_id' => NULL, 'unit_title' => NULL, 'unit_name' => NULL])));
    $this->assertNull(myapi_payments_admin_unit_name($this->listRow(['unit_raw' => NULL, 'unit_id' => NULL, 'unit_title' => NULL, 'unit_name' => NULL])));
  }

  /**
   * On screen the unit links to its node; a deleted one is plain text.
   */
  public function testTheUnitLabelLinksToTheUnit() {
    $this->assertSame('<a href="/node/77">Torre B 302</a>', myapi_payments_admin_unit_label($this->listRow()));
    $this->assertSame('Vivienda eliminada (#77)', myapi_payments_admin_unit_label($this->listRow(['unit_id' => NULL, 'unit_title' => NULL, 'unit_name' => NULL])));
    $this->assertSame('—', myapi_payments_admin_unit_label($this->listRow(['unit_raw' => NULL, 'unit_id' => NULL, 'unit_title' => NULL, 'unit_name' => NULL])));
    $this->assertStringNotContainsString('<img', myapi_payments_admin_unit_label($this->listRow(['unit_name' => '<img src=x>'])));
  }

  public function testTheMoneyLabelUsesDollarsAndSpanishSeparators() {
    $this->assertSame('$1.234,56', myapi_payments_admin_money_label('1234.56'));
    $this->assertSame('$186,40', myapi_payments_admin_money_label('186.4'));
    $this->assertSame('$0,00', myapi_payments_admin_money_label('0'));
    $this->assertSame('—', myapi_payments_admin_money_label(NULL));
    $this->assertSame('—', myapi_payments_admin_money_label(''));
    $this->assertSame('—', myapi_payments_admin_money_label('abc'));
  }

  /**
   * The total row: the sum of every filtered payment and how many there are.
   * An empty result is a total of zero, not a dash — "nothing" is an answer.
   */
  public function testTheTotalLabelShowsTheSumAndTheCount() {
    $this->assertSame('Total: $12.345,60 (37 pagos)', myapi_payments_admin_total_label('12345.6', 37));
    $this->assertSame('Total: $186,40 (1 pago)', myapi_payments_admin_total_label('186.40', 1));
    $this->assertSame('Total: $0,00 (0 pagos)', myapi_payments_admin_total_label(NULL, 0));
  }

  /* -------------------------------------------------------------------------
   * The table body.
   * ---------------------------------------------------------------------- */

  private function listRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'                 => '905',
      'created'             => (string) mktime(9, 30, 0, 10, 2, 2026),
      'unit_raw'            => '77',
      'unit_id'             => '77',
      'unit_title'          => 'VIV-0302',
      'unit_name'           => 'Torre B 302',
      'condominium_title'   => 'Edificio El Sáuco',
      'owner_raw'           => '3',
      'owner_name'          => 'pcordero',
      'owner_first_name'    => 'Pedro',
      'owner_last_name'     => 'Cordero',
      'owner_cedula'        => '0102030405',
      'occupant_raw'        => '8',
      'occupant_name'       => 'apena',
      'occupant_first_name' => 'Ana',
      'occupant_last_name'  => 'Peña',
      'occupant_cedula'     => '0908070605',
      'payment_date'        => '2026-10-01T00:00:00',
      'payment_method'      => 'Transferencia',
      'bank_name'           => 'Banco Pichincha',
      'reference'           => 'REF-123',
      'status'              => 'Completado',
      'amount'              => '1234.5',
      'channel'             => 'bot',
      'issuer_bank'         => 'Produbanco',
      'destination_bank'    => 'Banco Pichincha',
    ]);
  }

  /**
   * The fifteen cells of SPEC 132, in the order of the table header.
   */
  public function testARowBecomesTheFifteenDocumentedCells() {
    $rows = myapi_payments_admin_table_rows([$this->listRow()], [905 => TRUE]);

    $this->assertCount(1, $rows);
    $this->assertSame([
      '<a href="/node/905/edit">905</a>',
      '<a href="/node/77">Torre B 302</a>',
      'Edificio El Sáuco',
      'Pedro Cordero',
      '0102030405',
      'Ana Peña',
      '0908070605',
      '01/10/2026',
      'Transferencia',
      'Banco Pichincha',
      'REF-123',
      'Completado',
      '$1.234,50',
      'Bot de WhatsApp',
      '02/10/2026 09:30',
    ], $rows[0]);
  }

  /**
   * A reader who cannot edit the node is sent to its view, and a nid absent
   * from the map counts as "cannot edit" — the link that never 403s.
   */
  public function testTheIdLinksToTheNodeWhenTheReaderCannotEdit() {
    $this->assertSame('<a href="/node/905">905</a>', myapi_payments_admin_table_rows([$this->listRow()], [905 => FALSE])[0][0]);
    $this->assertSame('<a href="/node/905">905</a>', myapi_payments_admin_table_rows([$this->listRow()], [])[0][0]);
  }

  /**
   * A payment whose unit was deleted keeps its row: the unit says so, and
   * everything read THROUGH the unit — condominium, owner, occupant — is a
   * dash.
   */
  public function testAPaymentOfADeletedUnitStillHasItsFifteenCells() {
    $row = $this->listRow([
      'unit_id'             => NULL,
      'unit_title'          => NULL,
      'unit_name'           => NULL,
      'condominium_title'   => NULL,
      'owner_raw'           => NULL,
      'owner_name'          => NULL,
      'owner_first_name'    => NULL,
      'owner_last_name'     => NULL,
      'owner_cedula'        => NULL,
      'occupant_raw'        => NULL,
      'occupant_name'       => NULL,
      'occupant_first_name' => NULL,
      'occupant_last_name'  => NULL,
      'occupant_cedula'     => NULL,
    ]);
    $cells = myapi_payments_admin_table_rows([$row], [905 => TRUE])[0];

    $this->assertCount(15, $cells);
    $this->assertSame('Vivienda eliminada (#77)', $cells[1]);
    $this->assertSame(['—', '—', '—', '—', '—'], array_slice($cells, 2, 5));
  }

  /**
   * Every optional payment field degrades to a dash on its own.
   */
  public function testEveryOptionalPaymentFieldShowsADash() {
    $cells = myapi_payments_admin_table_rows([$this->listRow([
      'payment_date'   => NULL,
      'payment_method' => NULL,
      'bank_name'      => NULL,
      'reference'      => NULL,
      'status'         => NULL,
      'amount'         => NULL,
      'channel'        => NULL,
    ])], [905 => TRUE])[0];

    $this->assertSame(['—', '—', '—', '—', '—', '—', '—'], array_slice($cells, 7, 7));
  }

  public function testTheReferenceIsEscaped() {
    $cells = myapi_payments_admin_table_rows([$this->listRow(['reference' => '<script>x</script>'])], [905 => TRUE])[0];

    $this->assertSame('&lt;script&gt;x&lt;/script&gt;', $cells[10]);
  }

  public function testNoRowsProduceNoCells() {
    $this->assertSame([], myapi_payments_admin_table_rows([], []));
  }

  /* -------------------------------------------------------------------------
   * Who may edit what.
   * ---------------------------------------------------------------------- */

  public function testBypassNodeAccessSkipsTheNodeLoadEntirely() {
    $GLOBALS['myapi_test_permissions']['bypass node access'] = TRUE;

    $map = myapi_payments_admin_editable_map([$this->listRow(), $this->listRow(['nid' => '906'])]);

    $this->assertSame([905 => TRUE, 906 => TRUE], $map);
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  /**
   * The 'backend' operator edits payments through the bundle permission, and
   * loads no node either.
   */
  public function testTheEditAnyPermissionAlsoSkipsTheNodeLoad() {
    $GLOBALS['myapi_test_permissions']['edit any pagos content'] = TRUE;

    $this->assertSame([905 => TRUE], myapi_payments_admin_editable_map([$this->listRow()]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  public function testWithoutAPermissionEachNodeIsAskedInOneBatch() {
    $GLOBALS['myapi_test_node_access']['update:905'] = FALSE;
    $GLOBALS['myapi_test_node_access']['update:906'] = TRUE;
    myapi_test_node_seed([
      905 => ['nid' => 905, 'type' => 'pagos'],
      906 => ['nid' => 906, 'type' => 'pagos'],
    ]);

    $map = myapi_payments_admin_editable_map([$this->listRow(), $this->listRow(['nid' => '906'])]);

    $this->assertSame([905 => FALSE, 906 => TRUE], $map);
    $this->assertSame([[905, 906]], myapi_test_node_load_multiple_calls());
  }

  public function testNoRowsResolveNoPermissions() {
    $this->assertSame([], myapi_payments_admin_editable_map([]));
    $this->assertSame([], myapi_test_node_load_multiple_calls());
  }

  /* -------------------------------------------------------------------------
   * The effective filters.
   * ---------------------------------------------------------------------- */

  /**
   * A condominium that is not among the options is dropped, the rest of the
   * filters pass untouched.
   */
  public function testTheEffectiveFiltersNarrowTheCondominium() {
    $_GET = ['condominium' => '99', 'channel' => 'app', 'date_from' => '', 'date_to' => ''];

    $filters = myapi_payments_admin_effective_filters([12 => 'Edificio El Sáuco']);

    $this->assertNull($filters['condominium']);
    $this->assertSame('app', $filters['channel']);

    $_GET['condominium'] = '12';
    $this->assertSame(12, myapi_payments_admin_effective_filters([12 => 'Edificio El Sáuco'])['condominium']);
  }

  /* -------------------------------------------------------------------------
   * The query.
   * ---------------------------------------------------------------------- */

  /**
   * A 'pagos' fixture row. The columns a filter, the order or the SUM reads
   * are seeded under their QUALIFIED names, because several of them collide
   * once bare (nv.title, the two field_apellidos_value); the rest under the
   * alias the row helpers read.
   */
  private function paymentRow(array $overrides = []) {
    return $overrides + [
      'nid'     => 905,
      'type'    => 'pagos',
      'created' => mktime(9, 30, 0, 10, 2, 2026),
      'unit_raw'   => 77,
      'unit_id'    => 77,
      'nv.title'   => 'VIV-0302',
      'fnv.field_nombre_vivienda_value' => 'Torre B 302',
      'fco.field_condominio_target_id'  => 12,
      'condominium_title' => 'Edificio El Sáuco',
      'owner_raw'  => 3,
      'owner_name' => 'pcordero',
      'owner_first_name' => 'Pedro',
      'uoa.field_apellidos_value' => 'Cordero',
      'owner_cedula' => '0102030405',
      'occupant_raw'  => 8,
      'occupant_name' => 'apena',
      'occupant_first_name' => 'Ana',
      'uca.field_apellidos_value' => 'Peña',
      'occupant_cedula' => '0908070605',
      'ffp.field_fecha_de_pago_value' => '2026-10-01T00:00:00',
      'ffo.field_forma_de_pago_value' => 'Transferencia',
      'bank_name' => 'Banco Pichincha',
      'reference' => 'REF-123',
      'fes.field_estado_pago_value' => 'Completado',
      'fva.field_valor_value' => '100.00',
      'fca.field_canal_value' => 'bot',
      'issuer_bank' => 'Produbanco',
      'destination_bank' => 'Banco Pichincha',
    ];
  }

  private function noFilters(array $overrides = []) {
    return $overrides + [
      'condominium'        => NULL,
      'status'             => NULL,
      'unit_name'          => NULL,
      'owner_last_name'    => NULL,
      'occupant_last_name' => NULL,
      'payment_method'     => NULL,
      'channel'            => NULL,
      'date_from'          => NULL,
      'date_to'            => NULL,
    ];
  }

  private function pageNids(array $filters) {
    return array_map('intval', array_column(myapi_payments_admin_fetch_page($filters), 'nid'));
  }

  public function testThePageQueryCarriesTheNodeAccessTagAndThePager() {
    myapi_test_db_seed(['node' => [$this->paymentRow()]]);

    myapi_payments_admin_fetch_page($this->noFilters());

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['node_access'], $query['tags']);
    $this->assertSame(['PagerDefault'], $query['extenders']);
    $this->assertSame(['start' => 0, 'length' => 20], $query['range']);
  }

  /**
   * Every join is LEFT: a payment never vanishes because something it points
   * at is gone.
   */
  public function testEveryJoinIsLeft() {
    myapi_test_db_seed(['node' => [$this->paymentRow()]]);

    myapi_payments_admin_fetch_page($this->noFilters());

    $types = array_unique(array_column(myapi_test_db_queries('node')[0]['joins'], 'type'));
    $this->assertSame(['LEFT'], array_values($types));
  }

  /**
   * Only payments — and every one of them, published or not, 'Nuevo'
   * included (decision 4).
   */
  public function testEveryPaymentIsListedWhateverItsStateOrPublication() {
    myapi_test_db_seed(['node' => [
      $this->paymentRow(['nid' => 901, 'fes.field_estado_pago_value' => 'Nuevo']),
      $this->paymentRow(['nid' => 902, 'fes.field_estado_pago_value' => 'Pendiente de verificar']),
      $this->paymentRow(['nid' => 903, 'fes.field_estado_pago_value' => 'Anulado', 'status' => 0]),
      $this->paymentRow(['nid' => 904]),
      $this->paymentRow(['nid' => 999, 'type' => 'reclamo']),
    ]]);

    $this->assertSame([904, 903, 902, 901], $this->pageNids($this->noFilters()));
  }

  /**
   * Latest payment date first; on a tie, the higher nid.
   */
  public function testTheOrderIsPaymentDateThenNidDescending() {
    myapi_test_db_seed(['node' => [
      $this->paymentRow(['nid' => 901, 'ffp.field_fecha_de_pago_value' => '2026-10-05T00:00:00']),
      $this->paymentRow(['nid' => 902, 'ffp.field_fecha_de_pago_value' => '2026-09-20T00:00:00']),
      $this->paymentRow(['nid' => 903, 'ffp.field_fecha_de_pago_value' => '2026-10-05T00:00:00']),
    ]]);

    $this->assertSame([903, 901, 902], $this->pageNids($this->noFilters()));
  }

  /**
   * Each filter narrows on its own column.
   */
  public function testEachFilterNarrowsOnItsOwn() {
    myapi_test_db_seed(['node' => [
      $this->paymentRow(),
      $this->paymentRow([
        'nid' => 906,
        'fco.field_condominio_target_id' => 30,
        'fes.field_estado_pago_value'    => 'Anulado',
        'ffo.field_forma_de_pago_value'  => 'Efectivo',
        'fca.field_canal_value'          => 'app',
        'nv.title'                       => 'VIV-0101',
        'fnv.field_nombre_vivienda_value' => 'Casa 1',
        'uoa.field_apellidos_value'      => 'Zambrano',
        'uca.field_apellidos_value'      => 'Vélez',
        'ffp.field_fecha_de_pago_value'  => '2026-08-15T00:00:00',
      ]),
    ]]);

    $this->assertSame([905], $this->pageNids($this->noFilters(['condominium' => 12])));
    $this->assertSame([906], $this->pageNids($this->noFilters(['status' => 'Anulado'])));
    $this->assertSame([906], $this->pageNids($this->noFilters(['payment_method' => 'Efectivo'])));
    $this->assertSame([905], $this->pageNids($this->noFilters(['channel' => 'bot'])));
    $this->assertSame([906], $this->pageNids($this->noFilters(['owner_last_name' => 'zamb'])));
    $this->assertSame([905], $this->pageNids($this->noFilters(['occupant_last_name' => 'pena'])));
    $this->assertSame([906], $this->pageNids($this->noFilters(['date_to' => '2026-08-31'])));
    $this->assertSame([905], $this->pageNids($this->noFilters(['date_from' => '2026-09-01'])));
  }

  /**
   * The unit is found by its title AND by field_nombre_vivienda, partially
   * and case-insensitively (decision 7).
   */
  public function testTheUnitNameMatchesTitleOrName() {
    myapi_test_db_seed(['node' => [
      $this->paymentRow(),
      $this->paymentRow(['nid' => 906, 'nv.title' => 'VIV-0101', 'fnv.field_nombre_vivienda_value' => 'Casa 1']),
    ]]);

    $this->assertSame([905], $this->pageNids($this->noFilters(['unit_name' => 'viv-03'])), 'by title');
    $this->assertSame([905], $this->pageNids($this->noFilters(['unit_name' => 'torre b'])), 'by name');
    $this->assertSame([906, 905], $this->pageNids($this->noFilters(['unit_name' => 'viv'])));
  }

  /**
   * Each date bound covers its whole day, against the 'T' of a date field.
   */
  public function testASingleDayRangeIncludesThatDay() {
    myapi_test_db_seed(['node' => [$this->paymentRow(['ffp.field_fecha_de_pago_value' => '2026-10-01T18:30:00'])]]);

    $this->assertSame([905], $this->pageNids($this->noFilters(['date_from' => '2026-10-01', 'date_to' => '2026-10-01'])));
  }

  /**
   * A payment whose unit was deleted keeps its row.
   */
  public function testAPaymentOfADeletedUnitIsStillListed() {
    myapi_test_db_seed(['node' => [$this->paymentRow([
      'unit_id' => NULL, 'nv.title' => NULL, 'fnv.field_nombre_vivienda_value' => NULL,
      'fco.field_condominio_target_id' => NULL, 'condominium_title' => NULL,
      'owner_raw' => NULL, 'owner_name' => NULL, 'occupant_raw' => NULL, 'occupant_name' => NULL,
    ])]]);

    $rows = myapi_payments_admin_fetch_page($this->noFilters());

    $this->assertCount(1, $rows);
    $this->assertSame('Vivienda eliminada (#77)', myapi_payments_admin_unit_name($rows[0]));
  }

  /**
   * The row carries every column the table and the CSV read, under the names
   * the helpers expect. A renamed alias would blank a column silently.
   */
  public function testTheRowCarriesEveryProjectedColumn() {
    myapi_test_db_seed(['node' => [$this->paymentRow()]]);

    $rows = myapi_payments_admin_fetch_page($this->noFilters());

    $this->assertSame([
      'nid', 'created',
      'unit_raw', 'unit_id', 'unit_title', 'unit_name', 'condominium_title',
      'owner_raw', 'owner_name', 'owner_first_name', 'owner_last_name', 'owner_cedula',
      'occupant_raw', 'occupant_name', 'occupant_first_name', 'occupant_last_name', 'occupant_cedula',
      'payment_date', 'payment_method', 'bank_name', 'reference', 'status', 'amount', 'channel',
      'issuer_bank', 'destination_bank',
    ], array_keys((array) $rows[0]));
    $this->assertSame('VIV-0302', $rows[0]->unit_title);
    $this->assertSame('Cordero', $rows[0]->owner_last_name);
    $this->assertSame('Peña', $rows[0]->occupant_last_name);
  }

  /**
   * The total sums EVERY filtered payment, not the 20 of the page, and it
   * follows the filter.
   */
  public function testTheTotalSumsEveryFilteredPayment() {
    $rows = [];
    for ($nid = 1; $nid <= 25; $nid++) {
      $rows[] = $this->paymentRow(['nid' => $nid, 'fva.field_valor_value' => '10.50']);
    }
    $rows[] = $this->paymentRow(['nid' => 26, 'fva.field_valor_value' => '100.00', 'fes.field_estado_pago_value' => 'Anulado']);
    myapi_test_db_seed(['node' => $rows]);

    $this->assertSame(['total' => 362.5, 'count' => 26], myapi_payments_admin_total($this->noFilters()));
    $this->assertSame(['total' => 100.0, 'count' => 1], myapi_payments_admin_total($this->noFilters(['status' => 'Anulado'])));
  }

  /**
   * No match is a NULL sum and a zero count, as SQL answers.
   */
  public function testTheTotalOfNothingIsNullAndZero() {
    myapi_test_db_seed(['node' => [$this->paymentRow()]]);

    $this->assertSame(['total' => NULL, 'count' => 0], myapi_payments_admin_total($this->noFilters(['channel' => 'app'])));
  }

  /**
   * The total projects only aggregates and orders by nothing — what
   * ONLY_FULL_GROUP_BY demands — and keeps the node_access tag.
   */
  public function testTheTotalQueryIsAPureAggregate() {
    myapi_test_db_seed(['node' => [$this->paymentRow()]]);

    myapi_payments_admin_total($this->noFilters());

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['total', 'payments'], $query['fields']);
    $this->assertSame([], $query['order']);
    $this->assertSame([], $query['extenders']);
    $this->assertSame(['node_access'], $query['tags']);
  }

  /**
   * The CSV batches walk the same ordered set without gaps or repeats.
   */
  public function testTheCsvBatchesWalkTheOrderedSet() {
    $rows = [];
    for ($nid = 1; $nid <= 5; $nid++) {
      $rows[] = $this->paymentRow(['nid' => $nid]);
    }
    myapi_test_db_seed(['node' => $rows]);

    $first = array_map('intval', array_column(myapi_payments_admin_fetch_batch($this->noFilters(), 0, 2), 'nid'));
    $second = array_map('intval', array_column(myapi_payments_admin_fetch_batch($this->noFilters(), 2, 2), 'nid'));
    $last = array_map('intval', array_column(myapi_payments_admin_fetch_batch($this->noFilters(), 4, 2), 'nid'));

    $this->assertSame([5, 4], $first);
    $this->assertSame([3, 2], $second);
    $this->assertSame([1], $last);
  }

  /**
   * The batch query is the page query without the pager.
   */
  public function testTheCsvBatchHasNoPager() {
    myapi_test_db_seed(['node' => [$this->paymentRow()]]);

    myapi_payments_admin_fetch_batch($this->noFilters(), 500, 500);

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame([], $query['extenders']);
    $this->assertSame(['start' => 500, 'length' => 500], $query['range']);
    $this->assertSame(['node_access'], $query['tags']);
  }

  /* -------------------------------------------------------------------------
   * The CSV.
   * ---------------------------------------------------------------------- */

  public function testTheCsvHeaderIsTheFifteenColumnsPlusTheTwoBanks() {
    $this->assertSame([
      'ID',
      'Vivienda',
      'Condominio',
      'Propietario',
      'Cédula propietario',
      'Ocupante',
      'Cédula ocupante',
      'Fecha de pago',
      'Forma de pago',
      'Banco',
      'Referencia',
      'Estado',
      'Valor',
      'Canal',
      'Creado',
      'Banco emisor',
      'Banco destino',
    ], myapi_payments_admin_csv_header());
  }

  /**
   * Raw values: no '$', a dot decimal, ISO dates, labels for the catalogues,
   * nothing HTML-escaped and no link markup.
   */
  public function testACsvRowCarriesRawValues() {
    $this->assertSame([
      '905',
      'Torre B 302',
      'Edificio El Sáuco',
      'Pedro Cordero',
      '0102030405',
      'Ana Peña',
      '0908070605',
      '2026-10-01',
      'Transferencia',
      'Banco Pichincha',
      'REF-123',
      'Completado',
      '1234.50',
      'Bot de WhatsApp',
      '2026-10-02 09:30',
      'Produbanco',
      'Banco Pichincha',
    ], myapi_payments_admin_csv_row($this->listRow()));
  }

  /**
   * A CSV cell is data: '<b>' stays '<b>' and an ampersand stays one.
   */
  public function testACsvRowIsNotHtmlEscaped() {
    $row = myapi_payments_admin_csv_row($this->listRow(['reference' => 'A&B <1>', 'unit_name' => 'Torre "B"']));

    $this->assertSame('A&B <1>', $row[10]);
    $this->assertSame('Torre "B"', $row[1]);
  }

  /**
   * A missing value is an empty cell, never the screen's dash — a dash in the
   * Valor column would break a SUM in the sheet.
   */
  public function testAMissingCsvValueIsAnEmptyCell() {
    $row = myapi_payments_admin_csv_row($this->listRow([
      'unit_raw'          => NULL,
      'unit_id'           => NULL,
      'unit_title'        => NULL,
      'unit_name'         => NULL,
      'condominium_title' => NULL,
      'owner_raw'         => NULL,
      'owner_cedula'      => NULL,
      'occupant_raw'      => NULL,
      'occupant_cedula'   => NULL,
      'payment_date'      => NULL,
      'payment_method'    => NULL,
      'bank_name'         => NULL,
      'reference'         => NULL,
      'status'            => NULL,
      'amount'            => NULL,
      'channel'           => NULL,
      'issuer_bank'       => NULL,
      'destination_bank'  => NULL,
    ]));

    $this->assertCount(17, $row);
    $this->assertSame('905', $row[0]);
    $this->assertSame('2026-10-02 09:30', $row[14]);
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16] as $index) {
      $this->assertSame('', $row[$index], 'column ' . $index);
    }
  }

  /**
   * The deleted unit and the deleted account say so in the CSV too.
   */
  public function testTheCsvKeepsTheDeletedShapes() {
    $row = myapi_payments_admin_csv_row($this->listRow([
      'unit_id'    => NULL,
      'unit_title' => NULL,
      'unit_name'  => NULL,
      'owner_name' => NULL,
    ]));

    $this->assertSame('Vivienda eliminada (#77)', $row[1]);
    $this->assertSame('Usuario eliminado (#3)', $row[3]);
  }

  /**
   * An unknown catalogue key prints raw in the CSV as well as on screen.
   */
  public function testTheCsvPrintsUnknownCatalogueKeysRaw() {
    $row = myapi_payments_admin_csv_row($this->listRow(['status' => 'Rechazado', 'payment_method' => 'Bitcoin', 'channel' => 'fax']));

    $this->assertSame('Rechazado', $row[11]);
    $this->assertSame('Bitcoin', $row[8]);
    $this->assertSame('fax', $row[13]);
  }

  /**
   * The six triggers of a spreadsheet formula get a leading apostrophe.
   */
  public function testFormulaTriggersAreNeutralised() {
    $this->assertSame("'=1+1", myapi_payments_admin_csv_cell('=1+1'));
    $this->assertSame("'+1", myapi_payments_admin_csv_cell('+1'));
    $this->assertSame("'-1", myapi_payments_admin_csv_cell('-1'));
    $this->assertSame("'@SUM(A1)", myapi_payments_admin_csv_cell('@SUM(A1)'));
    $this->assertSame("'\tx", myapi_payments_admin_csv_cell("\tx"));
    $this->assertSame("'\rx", myapi_payments_admin_csv_cell("\rx"));
  }

  public function testOrdinaryCellsAreLeftAlone() {
    $this->assertSame('REF-123', myapi_payments_admin_csv_cell('REF-123'));
    $this->assertSame('a=b', myapi_payments_admin_csv_cell('a=b'));
    $this->assertSame('1234.50', myapi_payments_admin_csv_cell('1234.50'));
    $this->assertSame('', myapi_payments_admin_csv_cell(''));
    $this->assertSame('', myapi_payments_admin_csv_cell(NULL));
    $this->assertSame('905', myapi_payments_admin_csv_cell(905));
  }

  /**
   * The escape is applied to EVERY cell of a row, not only the reference.
   */
  public function testTheCsvRowEscapesEveryCell() {
    $row = myapi_payments_admin_csv_row($this->listRow([
      'reference'         => '=HYPERLINK("http://x")',
      'unit_name'         => '@x',
      'owner_first_name'  => '+Pedro',
      'issuer_bank'       => '-x',
    ]));

    $this->assertSame('\'=HYPERLINK("http://x")', $row[10]);
    $this->assertSame("'@x", $row[1]);
    $this->assertSame("'+Pedro Cordero", $row[3]);
    $this->assertSame("'-x", $row[15]);
  }

  /**
   * Parses a captured CSV body into rows, after checking and dropping the BOM.
   */
  private function parseCsv($output) {
    $this->assertSame("\xEF\xBB\xBF", substr($output, 0, 3), 'UTF-8 BOM first');

    $lines = array_filter(explode("\n", substr($output, 3)), 'strlen');

    return array_values(array_map(function ($line) {
      return str_getcsv($line, ',', '"', '');
    }, $lines));
  }

  /**
   * The download: its headers, the BOM, the header row and one row per
   * filtered payment, and it ends the request.
   */
  public function testTheCsvDownloadStreamsTheFilteredPayments() {
    myapi_test_db_seed(['node' => [
      $this->paymentRow(),
      $this->paymentRow(['nid' => 906, 'fca.field_canal_value' => 'app', 'fnv.field_nombre_vivienda_value' => 'Peñón 1']),
    ]]);

    $result = myapi_test_capture(function () {
      myapi_payments_admin_csv_send($this->noFilters(['channel' => 'bot']), $this->now);
    });

    $this->assertTrue($result['exited']);
    $this->assertSame('text/csv; charset=utf-8', $result['headers']['Content-Type']);
    $this->assertSame('attachment; filename="pagos-20261014-1200.csv"', $result['headers']['Content-Disposition']);
    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);

    $rows = $this->parseCsv($result['output']);
    $this->assertCount(2, $rows, 'header + the one bot payment');
    $this->assertSame(myapi_payments_admin_csv_header(), $rows[0]);
    $this->assertCount(17, $rows[1]);
    $this->assertSame('905', $rows[1][0]);
    $this->assertSame('100.00', $rows[1][12]);
  }

  /**
   * Commas, quotes and a backslash before a quote survive the round trip:
   * the empty escape character keeps fputcsv() RFC 4180.
   */
  public function testTheCsvQuotesAwkwardValues() {
    myapi_test_db_seed(['node' => [$this->paymentRow(['reference' => 'a,b "c" d\\"e'])]]);

    $result = myapi_test_capture(function () {
      myapi_payments_admin_csv_send($this->noFilters(), $this->now);
    });

    $this->assertSame('a,b "c" d\\"e', $this->parseCsv($result['output'])[1][10]);
  }

  /**
   * More than two batches are exported whole, in order, by ranged reads of
   * 500 — never one unbounded query.
   */
  public function testTheCsvReadsEveryPaymentInBatchesOfFiveHundred() {
    $rows = [];
    for ($nid = 1; $nid <= 1001; $nid++) {
      $rows[] = $this->paymentRow(['nid' => $nid]);
    }
    myapi_test_db_seed(['node' => $rows]);

    $result = myapi_test_capture(function () {
      myapi_payments_admin_csv_send($this->noFilters(), $this->now);
    });

    $csv = $this->parseCsv($result['output']);
    $this->assertCount(1002, $csv);
    $this->assertSame('1001', $csv[1][0]);
    $this->assertSame('1', $csv[1001][0]);
    $this->assertSame(
      [['start' => 0, 'length' => 500], ['start' => 500, 'length' => 500], ['start' => 1000, 'length' => 500]],
      array_column(myapi_test_db_queries('node'), 'range')
    );
  }

  /**
   * Exactly one full batch costs one extra, empty read — and no duplicate.
   */
  public function testAnExactBatchEndsOnAnEmptyRead() {
    $rows = [];
    for ($nid = 1; $nid <= 500; $nid++) {
      $rows[] = $this->paymentRow(['nid' => $nid]);
    }
    myapi_test_db_seed(['node' => $rows]);

    $result = myapi_test_capture(function () {
      myapi_payments_admin_csv_send($this->noFilters(), $this->now);
    });

    $this->assertCount(501, $this->parseCsv($result['output']));
    $this->assertCount(2, myapi_test_db_queries('node'));
  }

  /**
   * No match is still a valid file: the header row alone.
   */
  public function testAnEmptyCsvHasOnlyTheHeaderRow() {
    $result = myapi_test_capture(function () {
      myapi_payments_admin_csv_send($this->noFilters(), $this->now);
    });

    $this->assertSame([myapi_payments_admin_csv_header()], $this->parseCsv($result['output']));
  }

  public function testTheCsvFilenameCarriesTheMinute() {
    $this->assertSame('pagos-20261014-1200.csv', myapi_payments_admin_csv_filename($this->now));
  }

  /**
   * The CSV button sends the filters the table is ACTUALLY using: the default
   * range explicitly, and every unset filter left out.
   */
  public function testTheCsvLinkCarriesTheEffectiveDates() {
    $_GET = ['channel' => 'bot'];
    $query = myapi_payments_admin_csv_query(myapi_payments_admin_list_filters($this->now));

    $this->assertSame([
      'channel'   => 'bot',
      'date_from' => '2026-09-14',
      'date_to'   => '2026-10-14',
    ], $query);
  }

  /**
   * An operator who removed the date limit gets a CSV without it: the two
   * dates travel EMPTY, which the CSV callback reads as "present and empty",
   * not as "absent" — the latter would re-apply the default range.
   */
  public function testTheCsvLinkKeepsANoLimitRangeAsEmptyDates() {
    $_GET = ['date_from' => '', 'date_to' => ''];
    $query = myapi_payments_admin_csv_query(myapi_payments_admin_list_filters($this->now));

    $this->assertSame(['date_from' => '', 'date_to' => ''], $query);

    $_GET = $query;
    $round_trip = myapi_payments_admin_list_filters($this->now);
    $this->assertNull($round_trip['date_from']);
    $this->assertNull($round_trip['date_to']);
  }

  /**
   * Every filter survives the round trip through the CSV link.
   */
  public function testEveryFilterSurvivesTheCsvRoundTrip() {
    $_GET = [
      'condominium'        => '12',
      'status'             => 'Anulado',
      'unit_name'          => 'Torre',
      'owner_last_name'    => 'Cordero',
      'occupant_last_name' => 'Peña',
      'payment_method'     => 'Efectivo',
      'channel'            => 'backoffice',
      'date_from'          => '2026-01-01',
      'date_to'            => '',
    ];
    $filters = myapi_payments_admin_list_filters($this->now);

    $_GET = myapi_payments_admin_csv_query($filters);
    $this->assertSame($filters, myapi_payments_admin_list_filters($this->now));
  }

}
