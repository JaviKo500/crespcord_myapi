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

/**
 * Unit tests for the back-office claims listing (SPEC 56, covered by SPEC 77):
 * includes/myapi.claims_admin.inc and includes/myapi.claim_query.inc.
 *
 * This is the screen an operator uses all day, and until this spec none of its
 * nine functions had a test — its own @file block says so ("these touch $_GET
 * and, for the labels, the Field API — so they are not pure in the sense the
 * tests/unit files require, and this page has none"). Since SPEC 74/77 the
 * layer has a fixture db_select() and a fixture Field API, so the sentence no
 * longer describes a limit of the harness, only of the coverage — which is what
 * this class closes.
 *
 * Two things here are worth more than they look:
 *  - The labels read the FIELD's allowed_values, so the Spanish text lives in
 *    one place (myapi.install) and a site that lost the field degrades to the
 *    raw key instead of showing a blank cell.
 *  - myapi_claims_list_rows() carries ->addTag('node_access'), and that tag is
 *    the ONLY thing restricting an 'administrador edificio' to their own
 *    buildings. Losing it does not break the page; it silently shows every
 *    claim of the site. There is a case below for exactly that.
 *
 * Out of scope, and named rather than skipped: myapi_claims_list_page() and
 * myapi_claims_list_filter_form(), which are drupal_get_form(), theme('pager')
 * and drupal_add_css() — Drupal's render pipeline, not a decision of ours. What
 * they assemble (the filters, the query, the rows) is covered here piece by
 * piece.
 */
class ClaimsAdminPageTest extends TestCase {

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
    myapi_test_field_seed_allowed_values([
      'field_status' => [
        'received'    => 'Recibido',
        'in_progress' => 'En proceso',
        'resolved'    => 'Resuelto',
        'closed'      => 'Cerrado',
      ],
      'field_claim_type' => [
        'requirement' => 'Requerimiento',
        'claim'       => 'Reclamo',
      ],
    ]);
    $_GET = [];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_fields'] = [];
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * The catalogues, read off the field.
   * ---------------------------------------------------------------------- */

  public function testTheAllowedValuesComeFromTheField() {
    $this->assertSame([
      'received'    => 'Recibido',
      'in_progress' => 'En proceso',
      'resolved'    => 'Resuelto',
      'closed'      => 'Cerrado',
    ], myapi_claims_field_allowed_values('field_status'));
  }

  /**
   * A field this environment does not have answers an empty array rather than
   * a notice — the degraded case a site missing the install step lands in.
   */
  public function testAMissingFieldAnswersAnEmptyAllowedValues() {
    $this->assertSame([], myapi_claims_field_allowed_values('field_inexistente'));

    myapi_test_field_seed_allowed_values([]);
    $this->assertSame([], myapi_claims_field_allowed_values('field_status'));
  }

  /**
   * The four status keys in the fixed order of the spec, labelled from the
   * field — so the select never shows them alphabetically or in storage order.
   */
  public function testTheStatusOptionsAreTheFourKeysInSpecOrder() {
    $this->assertSame([
      'received'    => 'Recibido',
      'in_progress' => 'En proceso',
      'resolved'    => 'Resuelto',
      'closed'      => 'Cerrado',
    ], myapi_claims_status_options());
  }

  /**
   * A key with no label falls back to the RAW KEY instead of disappearing from
   * the select: an operator can still filter by it on a half-configured site.
   */
  public function testAStatusWithNoLabelFallsBackToItsKey() {
    myapi_test_field_seed_allowed_values(['field_status' => ['received' => 'Recibido']]);

    $this->assertSame([
      'received'    => 'Recibido',
      'in_progress' => 'in_progress',
      'resolved'    => 'resolved',
      'closed'      => 'closed',
    ], myapi_claims_status_options());
  }

  /**
   * A value the field allows but SPEC 62 dropped ('duplicated') is not offered:
   * the four keys are the code's, only the labels come from the field.
   */
  public function testALabelForADroppedStatusIsNotOffered() {
    myapi_test_field_seed_allowed_values(['field_status' => [
      'received'   => 'Recibido',
      'duplicated' => 'Duplicado',
    ]]);

    $this->assertArrayNotHasKey('duplicated', myapi_claims_status_options());
  }

  public function testTheClaimTypeOptionsFollowTheSameRule() {
    $this->assertSame([
      'requirement' => 'Requerimiento',
      'claim'       => 'Reclamo',
    ], myapi_claims_claim_type_options());

    myapi_test_field_seed_allowed_values([]);
    $this->assertSame([
      'requirement' => 'requirement',
      'claim'       => 'claim',
    ], myapi_claims_claim_type_options());
  }

  /* -------------------------------------------------------------------------
   * The GET filters.
   * ---------------------------------------------------------------------- */

  /**
   * No query string at all: four NULLs, i.e. no filter anywhere.
   */
  public function testNoQueryStringMeansNoFilters() {
    $this->assertSame([
      'status'     => NULL,
      'claim_type' => NULL,
      'date_from'  => NULL,
      'date_to'    => NULL,
    ], myapi_claims_list_filters());
  }

  public function testTheValidFiltersPassThrough() {
    $_GET = [
      'status'     => 'in_progress',
      'claim_type' => 'requirement',
      'date_from'  => '2026-08-01',
      'date_to'    => '2026-08-31',
    ];

    $this->assertSame([
      'status'     => 'in_progress',
      'claim_type' => 'requirement',
      'date_from'  => '2026-08-01',
      'date_to'    => '2026-08-31',
    ], myapi_claims_list_filters());
  }

  /**
   * Nothing here ever fails: an invented status, a malformed date and a value
   * of the wrong type all fall back to "no filter", which is what keeps a
   * stale bookmark from answering an error page.
   */
  public function testEveryMalformedFilterFallsBackToNoFilter() {
    $_GET = [
      'status'     => 'duplicated',
      'claim_type' => 'queja',
      'date_from'  => '01/08/2026',
      'date_to'    => '2026-02-30',
    ];

    $this->assertSame([
      'status'     => NULL,
      'claim_type' => NULL,
      'date_from'  => NULL,
      'date_to'    => NULL,
    ], myapi_claims_list_filters());
  }

  /**
   * An array where a string is expected does not fatal: is_scalar() guards the
   * two dates and the whitelists reject the rest.
   */
  public function testArrayFiltersAreIgnoredWithoutAFatal() {
    $_GET = [
      'status'     => ['received'],
      'claim_type' => ['claim'],
      'date_from'  => ['2026-08-01'],
      'date_to'    => ['2026-08-31'],
    ];

    $this->assertSame([
      'status'     => NULL,
      'claim_type' => NULL,
      'date_from'  => NULL,
      'date_to'    => NULL,
    ], myapi_claims_list_filters());
  }

  /**
   * The two dates are validated independently: a broken 'from' does not drop a
   * perfectly good 'to'. (The API endpoint drops BOTH on an inverted range;
   * this page deliberately does not — see myapi_claim_parse_date_range().)
   */
  public function testTheTwoDateBoundsAreIndependent() {
    $_GET = ['date_from' => 'ayer', 'date_to' => '2026-08-31'];

    $filters = myapi_claims_list_filters();

    $this->assertNull($filters['date_from']);
    $this->assertSame('2026-08-31', $filters['date_to']);
  }

  /**
   * And an inverted range is kept as sent, which simply matches nothing —
   * unlike the API, and worth pinning so the difference stays deliberate.
   */
  public function testAnInvertedRangeIsKeptOnThisPage() {
    $_GET = ['date_from' => '2026-08-31', 'date_to' => '2026-08-01'];

    $filters = myapi_claims_list_filters();

    $this->assertSame('2026-08-31', $filters['date_from']);
    $this->assertSame('2026-08-01', $filters['date_to']);
  }

  /* -------------------------------------------------------------------------
   * Row labels.
   * ---------------------------------------------------------------------- */

  public function testTheStatusLabelUsesTheCatalogueAndEscapes() {
    $this->assertSame('En proceso', myapi_claims_status_label('in_progress'));
    $this->assertSame('—', myapi_claims_status_label(NULL), 'no status is an em dash, not an empty cell');
    $this->assertSame('duplicated', myapi_claims_status_label('duplicated'), 'an unknown key prints raw');
  }

  /**
   * The label is escaped because it is printed into an HTML table — and the
   * label comes from a field a site administrator can edit from the UI.
   */
  public function testTheStatusLabelIsEscaped() {
    myapi_test_field_seed_allowed_values(['field_status' => ['received' => '<b>Recibido</b>']]);

    $this->assertSame('&lt;b&gt;Recibido&lt;/b&gt;', myapi_claims_status_label('received'));
  }

  public function testTheClaimTypeLabelFollowsTheSameThreeCases() {
    $this->assertSame('Requerimiento', myapi_claims_claim_type_label('requirement'));
    $this->assertSame('—', myapi_claims_claim_type_label(NULL));
    $this->assertSame('queja', myapi_claims_claim_type_label('queja'));
  }

  /**
   * The requester cell has three shapes and each one says something different:
   * nobody was set, the account was deleted, or here is the name.
   */
  public function testTheRequesterLabelHasThreeShapes() {
    $none = (object) ['requester_uid' => NULL, 'requester_name' => NULL];
    $deleted = (object) ['requester_uid' => 41, 'requester_name' => NULL];
    $present = (object) ['requester_uid' => 3, 'requester_name' => 'pcordero'];

    $this->assertSame('Sin solicitante', myapi_claims_requester_label($none));
    $this->assertSame('Usuario eliminado (#41)', myapi_claims_requester_label($deleted));
    $this->assertSame('pcordero', myapi_claims_requester_label($present));
  }

  /**
   * A username is escaped: it is user-controlled text printed into the table.
   */
  public function testTheRequesterNameIsEscaped() {
    $row = (object) ['requester_uid' => 3, 'requester_name' => '<script>x</script>'];

    $this->assertSame('&lt;script&gt;x&lt;/script&gt;', myapi_claims_requester_label($row));
  }

  /* -------------------------------------------------------------------------
   * The table body.
   * ---------------------------------------------------------------------- */

  private function listRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'               => '140',
      'subject'           => 'Fuga en el pasillo',
      'condominium_id'    => '12',
      'condominium_title' => 'Edificio El Sáuco',
      'status'            => 'in_progress',
      'claim_type'        => 'claim',
      'requester_uid'     => '3',
      'requester_name'    => 'pcordero',
      'reception_date'    => '2026-08-04 16:45:00',
    ]);
  }

  /**
   * The seven cells of a row, in the order of the table header.
   */
  public function testARowBecomesTheSevenDocumentedCells() {
    $rows = myapi_claims_list_table_rows([$this->listRow()], [12 => 'Edificio El Sáuco']);

    $this->assertCount(1, $rows);
    $this->assertSame(140, $rows[0][0], 'the id is an int');
    $this->assertSame('<a href="/node/140/edit">Fuga en el pasillo</a>', $rows[0][1]);
    $this->assertSame('En proceso', $rows[0][3]);
    $this->assertSame('Reclamo', $rows[0][4]);
    $this->assertSame('pcordero', $rows[0][5]);
    $this->assertSame('04/08/2026 16:45', $rows[0][6]);
  }

  /**
   * The reception date prints day AND time since SPEC 63; a claim stored
   * before it carries '00:00' and is shown with it — the value actually
   * recorded, not a formatting bug.
   */
  public function testAPreSpec63RowShowsItsMidnightTime() {
    $rows = myapi_claims_list_table_rows(
      [$this->listRow(['reception_date' => '2026-01-05 00:00:00'])],
      []
    );

    $this->assertSame('05/01/2026 00:00', $rows[0][6]);
  }

  /**
   * A claim with no reception date shows an em dash, never 01/01/1970.
   */
  public function testARowWithoutAReceptionDateShowsADash() {
    $rows = myapi_claims_list_table_rows([$this->listRow(['reception_date' => NULL])], []);

    $this->assertSame('—', $rows[0][6]);
  }

  /**
   * An empty result set is an empty body — the page's '#empty' text takes over
   * from there.
   */
  public function testNoRowsProduceNoCells() {
    $this->assertSame([], myapi_claims_list_table_rows([], [12 => 'Edificio El Sáuco']));
  }

  /**
   * The subject is escaped by l() before it reaches the cell: a claim titled
   * with markup must not render it in the listing.
   */
  public function testTheSubjectIsEscapedInsideTheLink() {
    $rows = myapi_claims_list_table_rows([$this->listRow(['subject' => '<img src=x>'])], []);

    $this->assertStringNotContainsString('<img', $rows[0][1]);
    $this->assertStringContainsString('&lt;img src=x&gt;', $rows[0][1]);
  }

  /* -------------------------------------------------------------------------
   * The query (includes/myapi.claim_query.inc).
   * ---------------------------------------------------------------------- */

  /**
   * A published 'reclamo', with every column the listing shows.
   */
  private function claimRow(array $overrides = []) {
    return $overrides + [
      'nid'    => 140,
      'type'   => 'reclamo',
      'status' => 1,
      'subject'           => 'Fuga en el pasillo',
      'field_condominium_target_id' => 12,
      'condominium_id'    => 12,
      'condominium_title' => 'Edificio El Sáuco',
      // Qualified: the alias 'status' the query projects collides with
      // node.status, the published flag, and a flat row cannot hold both.
      'fs.field_status_value' => 'received',
      'field_claim_type_value' => 'claim',
      'claim_type'        => 'claim',
      'field_reception_date_value' => '2026-08-04 16:45:00',
      'reception_date'    => '2026-08-04 16:45:00',
      'field_requester_target_id' => 3,
      'requester_uid'     => 3,
      'requester_name'    => 'pcordero',
    ];
  }

  private function runList($condominium_id = NULL, $status = NULL, $claim_type = NULL, $from = NULL, $to = NULL) {
    return myapi_claims_list_rows($condominium_id, $status, $claim_type, $from, $to)
      ->execute()
      ->fetchAll();
  }

  /**
   * THE assertion of this file: the query carries ->addTag('node_access').
   * That tag is what makes myapi_building_admin_alter_node_query() narrow the
   * listing to the assigned condominiums of an 'administrador edificio'.
   * Losing it does not break the page — it shows every claim of the site.
   */
  public function testTheListingQueryCarriesTheNodeAccessTag() {
    myapi_test_db_seed(['node' => [$this->claimRow()]]);

    $this->runList();

    $this->assertSame(['node_access'], myapi_test_db_queries('node')[0]['tags']);
  }

  /**
   * And it is paginated through Drupal's own pager rather than by hand.
   */
  public function testTheListingQueryIsExtendedWithThePager() {
    myapi_test_db_seed(['node' => [$this->claimRow()]]);

    $this->runList();

    $query = myapi_test_db_queries('node')[0];
    $this->assertSame(['PagerDefault'], $query['extenders']);
    $this->assertSame(['start' => 0, 'length' => 20], $query['range']);
  }

  /**
   * Only published claims, and only claims.
   */
  public function testOnlyPublishedClaimsAreListed() {
    myapi_test_db_seed(['node' => [
      $this->claimRow(),
      $this->claimRow(['nid' => 141, 'status' => 0]),
      $this->claimRow(['nid' => 142, 'type' => 'boletin']),
    ]]);

    $rows = $this->runList();

    $this->assertSame([140], array_map('intval', array_column($rows, 'nid')));
  }

  /**
   * Newest first: the listing is read from the top.
   */
  public function testTheListingIsOrderedByNidDescending() {
    myapi_test_db_seed(['node' => [
      $this->claimRow(['nid' => 140]),
      $this->claimRow(['nid' => 142]),
      $this->claimRow(['nid' => 141]),
    ]]);

    $rows = $this->runList();

    $this->assertSame([142, 141, 140], array_map('intval', array_column($rows, 'nid')));
  }

  /**
   * Each of the five filters narrows on its own, and a NULL means "no filter
   * on this column".
   */
  public function testEachFilterNarrowsOnItsOwn() {
    $seed = function () {
      myapi_test_db_seed(['node' => [
        $this->claimRow(),
        $this->claimRow([
          'nid' => 141,
          'field_condominium_target_id' => 30, 'condominium_id' => 30,
          'fs.field_status_value' => 'closed',
          'field_claim_type_value' => 'requirement',
          'field_reception_date_value' => '2026-09-10 08:00:00',
        ]),
      ]]);
    };

    $seed();
    $this->assertSame([140], array_map('intval', array_column($this->runList(12), 'nid')));

    $seed();
    $this->assertSame([141], array_map('intval', array_column($this->runList(NULL, 'closed'), 'nid')));

    $seed();
    $this->assertSame([141], array_map('intval', array_column($this->runList(NULL, NULL, 'requirement'), 'nid')));

    $seed();
    $this->assertSame([141], array_map('intval', array_column($this->runList(NULL, NULL, NULL, '2026-09-01'), 'nid')));

    $seed();
    $this->assertSame([140], array_map('intval', array_column($this->runList(NULL, NULL, NULL, NULL, '2026-08-31'), 'nid')));
  }

  /**
   * date_to is INCLUSIVE of the whole day: a claim received at 16:45 on the
   * bound is inside the range. That is what the SUBSTR is for, now that
   * field_reception_date carries a time.
   */
  public function testDateToIncludesTheWholeDay() {
    myapi_test_db_seed(['node' => [$this->claimRow()]]);

    $rows = $this->runList(NULL, NULL, NULL, '2026-08-04', '2026-08-04');

    $this->assertCount(1, $rows);
  }

  /**
   * A filter on a left-joined column also excludes the claims that have no
   * value in it — the desired reading of "show me this status".
   */
  public function testFilteringExcludesClaimsWithNoValueInThatColumn() {
    myapi_test_db_seed(['node' => [
      $this->claimRow(),
      $this->claimRow(['nid' => 141, 'fs.field_status_value' => NULL]),
    ]]);

    $rows = $this->runList(NULL, 'received');

    $this->assertSame([140], array_map('intval', array_column($rows, 'nid')));
  }

  /**
   * With no filter, a claim missing an optional value still appears — LEFT and
   * not INNER, so a half-filled claim is visible to the operator who has to
   * fix it.
   */
  public function testAClaimMissingOptionalValuesStillAppears() {
    myapi_test_db_seed(['node' => [
      $this->claimRow([
        'fs.field_status_value' => NULL,
        'requester_uid' => NULL, 'requester_name' => NULL,
        'condominium_id' => NULL, 'condominium_title' => NULL,
      ]),
    ]]);

    $rows = $this->runList();

    $this->assertCount(1, $rows);
    $this->assertNull($rows[0]->status);
    $this->assertNull($rows[0]->requester_name);
  }

}
