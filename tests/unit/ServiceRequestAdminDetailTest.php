<?php

use PHPUnit\Framework\TestCase;

// module_load_include() is a no-op in tests/unit/bootstrap.php, so every
// dependency the file under test pulls at run time has to be required here for
// real — the constraint tests/README.md documents. The order is the dependency
// order: catalogues, then the two back-office files whose labels this screen
// reuses, then the screen itself.
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.time_format.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_transaction_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_requests_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_detail_admin.inc';
require_once __DIR__ . '/../../myapi.module';

/**
 * Unit tests for the read-only supervision detail of a service request
 * (SPEC 126): includes/myapi.service_request_detail_admin.inc.
 *
 * The screen is a composition and nothing else — it opens no query of its own
 * — so what there is to test is exactly the composition: how a row of
 * myapi_service_request_detail_row(), a row of
 * myapi_service_transaction_timeline_rows(), a row of
 * myapi_service_request_load_offers() and the file fields of the loaded node
 * become table cells, and what each of them does when the value is missing.
 *
 * Four cases here are worth more than they look:
 *
 *  - testTheScreenHasNoWriteAffordanceAtAll(). SPEC 126's whole promise is
 *    that this page carries no control that leads to a write. It is a promise
 *    about a FILE, so it is asserted over the file: no form, no submit, no
 *    node/%/edit, no service-transaction route, no node_save(). It is the case
 *    that fails the day somebody adds an "Editar" link because it was
 *    convenient.
 *  - testABrokenRequestIsDiagnosedAndNotHidden(). The reused detail query
 *    INNERs the category and the requester while the listing LEFTs everything,
 *    so a request the listing shows can answer no row here. The screen has to
 *    say so, not 404.
 *  - testTheAttachmentLinksNeverPointAtTheApi(). The api/v1 file endpoint
 *    demands a Bearer token that a back-office session does not carry, so a
 *    link built by myapi_service_request_build_file() would 401 on every
 *    click. This pins the file:// -> file_create_url() route instead.
 *  - testTheTimelineHasNoEditOrDeleteCell(). The SPEC 94 table builder makes
 *    six cells and two of them are write links; this screen makes four.
 *
 * Deliberately NOT tested here, and said out loud rather than skipped in
 * silence (same criterion as ServiceTransactionAdminTest):
 *   - myapi_service_request_admin_detail_page() — module_load_include(),
 *     user_load() and four render arrays; every piece it assembles is below.
 *   - the node_access() half of myapi_service_request_admin_detail_access() —
 *     the fixture answers what it is told, so asserting it would only prove
 *     the stub. What IS asserted is the bundle gate and the role gate, which
 *     are this module's own decisions.
 */
class ServiceRequestAdminDetailTest extends TestCase {

  protected function setUp(): void {
    $this->reset();
  }

  protected function tearDown(): void {
    $this->reset();
  }

  private function reset() {
    myapi_test_node_seed();
    $GLOBALS['myapi_test_node_access'] = [];
    $GLOBALS['myapi_test_node_access_calls'] = [];
    unset($GLOBALS['myapi_test_node_access_default']);
    $GLOBALS['user'] = (object) ['uid' => 0, 'roles' => []];
  }

  /**
   * A row of myapi_service_request_detail_row(), with every alias the summary
   * block reads. The shape is the query's: an object, `created` a Unix
   * timestamp, `desired_start` / `closed_at` stored 'Y-m-d H:i:s' strings, and
   * the awarded pair projected twice, resolved and raw.
   */
  private function row(array $overrides = []) {
    return (object) ($overrides + [
      'nid'                   => 412,
      'title'                 => 'Fuga en el baño',
      'created'               => mktime(9, 30, 0, 9, 4, 2026),
      'status'                => 'assigned',
      'category_id'           => 7,
      'category_name'         => 'Plomería',
      'category_code'         => 'PLO',
      'requester_uid'         => 55,
      'description'           => "Gotea desde ayer.\nY hoy más.",
      'desired_start'         => '2026-09-10 08:00:00',
      'closed_at'             => NULL,
      'unit_id'               => 31,
      'unit_name'             => 'Apto 101',
      'condominium_id'        => 9,
      'condominium_name'      => 'Torre Norte',
      'attachment_fid'        => NULL,
      'attachment_filename'   => NULL,
      'assigned_offer_id'     => 77,
      'assigned_offer_status' => 'selected',
      'assigned_offer_raw'    => 77,
      'assigned_provider_id'  => 88,
      'assigned_provider_name' => 'Plomería Delta',
      'assigned_provider_raw' => 88,
    ]);
  }

  /**
   * The values of the summary block, by label, so a case can assert one cell
   * without counting positions.
   */
  private function summary(array $overrides = [], $requester_name = 'ana') {
    $rows = myapi_service_request_admin_summary_rows($this->row($overrides), $requester_name);
    $map = [];

    foreach ($rows as $pair) {
      $map[$pair[0]] = $pair[1];
    }

    return $map;
  }

  /* -------------------------------------------------------------------------
   * Block 1 — the summary.
   * ---------------------------------------------------------------------- */

  /**
   * The thirteen documented rows, in the documented order, as label/value
   * pairs. Order is part of the contract: it is the order the operator reads.
   */
  public function testTheSummaryIsTheThirteenDocumentedRowsInOrder() {
    $rows = myapi_service_request_admin_summary_rows($this->row(), 'ana');

    $this->assertCount(13, $rows);
    $this->assertSame([
      'ID',
      'Título',
      'Estado',
      'Condominio',
      'Vivienda',
      'Solicitante',
      'Categoría',
      'Fecha de creación',
      'Fecha deseada',
      'Fecha de cierre',
      'Proveedor adjudicado',
      'Oferta adjudicada',
      'Descripción',
    ], array_map(function ($pair) {
      return $pair[0];
    }, $rows));
  }

  public function testTheSummaryPaintsAWholeRequest() {
    $summary = $this->summary();

    $this->assertSame('412', $summary['ID']);
    $this->assertSame('Fuga en el baño', $summary['Título']);
    $this->assertSame('Asignada', $summary['Estado'], 'the label comes from the SPEC 77 catalogue');
    $this->assertSame('Torre Norte', $summary['Condominio']);
    $this->assertSame('Apto 101', $summary['Vivienda']);
    $this->assertSame('ana', $summary['Solicitante']);
    $this->assertSame('Plomería (PLO)', $summary['Categoría']);
    $this->assertSame('04/09/2026 09:30', $summary['Fecha de creación']);
    $this->assertSame('10/09/2026 08:00', $summary['Fecha deseada']);
    $this->assertSame('—', $summary['Fecha de cierre']);
    $this->assertSame('Plomería Delta', $summary['Proveedor adjudicado']);
    $this->assertSame('#77 (Seleccionada)', $summary['Oferta adjudicada']);
  }

  /**
   * Every optional value degrades on its own and no row disappears: this is
   * the screen where a half-filled request gets found, so thirteen rows are
   * thirteen rows whatever is missing.
   */
  public function testARequestMissingEverythingOptionalStillHasItsThirteenRows() {
    $rows = myapi_service_request_admin_summary_rows($this->row([
      'title'                 => NULL,
      'status'                => NULL,
      'condominium_name'      => NULL,
      'unit_name'             => NULL,
      'category_name'         => NULL,
      'category_code'         => NULL,
      'description'           => NULL,
      'desired_start'         => NULL,
      'closed_at'             => NULL,
      'assigned_offer_id'     => NULL,
      'assigned_offer_status' => NULL,
      'assigned_offer_raw'    => NULL,
      'assigned_provider_id'  => NULL,
      'assigned_provider_name' => NULL,
      'assigned_provider_raw' => NULL,
    ]), NULL);

    $this->assertCount(13, $rows);

    $map = [];
    foreach ($rows as $pair) {
      $map[$pair[0]] = $pair[1];
    }

    $this->assertSame('412', $map['ID'], 'the nid is never optional');
    $this->assertSame('—', $map['Título']);
    $this->assertSame('—', $map['Estado']);
    $this->assertSame('—', $map['Condominio']);
    $this->assertSame('—', $map['Vivienda']);
    $this->assertSame('—', $map['Categoría']);
    $this->assertSame('—', $map['Fecha deseada']);
    $this->assertSame('—', $map['Proveedor adjudicado']);
    $this->assertSame('—', $map['Oferta adjudicada']);
    $this->assertSame('—', $map['Descripción']);
  }

  /**
   * The three branches the listing already draws, reused here rather than
   * rewritten: no requester, deleted account, and the name.
   */
  public function testTheRequesterLabelKeepsItsThreeBranches() {
    $present = $this->summary([], 'ana');
    $deleted = $this->summary([], NULL);
    $missing = myapi_service_request_admin_summary_rows($this->row(['requester_uid' => NULL]), NULL);

    $this->assertSame('ana', $present['Solicitante']);
    $this->assertSame('Usuario eliminado (#55)', $deleted['Solicitante']);
    $this->assertSame('Sin solicitante', $missing[5][1]);
  }

  /**
   * A category with no code prints the name alone — the parenthesis is not an
   * empty pair of brackets.
   */
  public function testACategoryWithoutACodePrintsTheNameAlone() {
    $summary = $this->summary(['category_code' => NULL]);
    $this->assertSame('Plomería', $summary['Categoría']);

    $summary = $this->summary(['category_code' => '']);
    $this->assertSame('Plomería', $summary['Categoría']);
  }

  /**
   * "Not awarded" and "awarded to something that is gone" are different facts
   * about a request, and the raw column is projected precisely so the screen
   * can tell them apart. Same three-way rule the listing applies to the
   * provider.
   */
  public function testTheOfferLabelDistinguishesUnawardedFromBroken() {
    $this->assertSame('—', myapi_service_request_admin_offer_label($this->row([
      'assigned_offer_raw'    => NULL,
      'assigned_offer_id'     => NULL,
      'assigned_offer_status' => NULL,
    ])));

    $this->assertSame('Oferta eliminada (#77)', myapi_service_request_admin_offer_label($this->row([
      'assigned_offer_raw'    => 77,
      'assigned_offer_id'     => NULL,
      'assigned_offer_status' => NULL,
    ])));

    $this->assertSame('#77 (Seleccionada)', myapi_service_request_admin_offer_label($this->row()));
  }

  /**
   * An offer whose status row was deleted by hand still names the offer: the
   * id is what the operator needs to go look for it.
   */
  public function testAnAwardedOfferWithNoStatusStillNamesItself() {
    $this->assertSame('#77', myapi_service_request_admin_offer_label($this->row([
      'assigned_offer_status' => NULL,
    ])));
  }

  /**
   * A 'direct' request has a provider and NO offer at all (SPEC 87), and the
   * two cells are read from their own fields, never one through the other.
   */
  public function testADirectRequestShowsItsProviderAndNoOffer() {
    $summary = $this->summary([
      'status'                => 'direct',
      'assigned_offer_id'     => NULL,
      'assigned_offer_status' => NULL,
      'assigned_offer_raw'    => NULL,
    ]);

    $this->assertSame('Proveedor directo', $summary['Estado']);
    $this->assertSame('Plomería Delta', $summary['Proveedor adjudicado']);
    $this->assertSame('—', $summary['Oferta adjudicada']);
  }

  /**
   * The description is the one multi-line value of the block, and its line
   * breaks have to survive as markup while its content stays escaped.
   */
  public function testTheDescriptionKeepsItsLineBreaksAndIsEscaped() {
    $summary = $this->summary(['description' => "uno\ndos"]);
    $this->assertStringContainsString('<br', $summary['Descripción']);
    $this->assertStringContainsString('uno', $summary['Descripción']);
    $this->assertStringContainsString('dos', $summary['Descripción']);

    $summary = $this->summary(['description' => '<script>alert(1)</script>']);
    $this->assertStringNotContainsString('<script>', $summary['Descripción']);
  }

  /**
   * Free text arriving from the app is escaped in every cell of the block, not
   * only in the description.
   */
  public function testFreeTextCellsAreEscaped() {
    $summary = $this->summary([
      'title'         => '<b>x</b>',
      'category_name' => '<i>y</i>',
    ]);

    $this->assertStringNotContainsString('<b>', $summary['Título']);
    $this->assertStringNotContainsString('<i>', $summary['Categoría']);
  }

  /* -------------------------------------------------------------------------
   * The broken request.
   * ---------------------------------------------------------------------- */

  /**
   * The reused detail query INNERs field_requester, field_category and
   * taxonomy_term_data, while the listing LEFTs everything: a request with a
   * deleted category term is listed and answers no detail row. The screen
   * diagnoses it — it never 404s and it never blames the reader.
   */
  public function testABrokenRequestIsDiagnosedAndNotHidden() {
    $notice = myapi_service_request_admin_broken_notice(412);

    $this->assertStringContainsString('412', $notice);
    $this->assertStringContainsString('warning', $notice, 'it renders as a Drupal warning message');
    $this->assertStringContainsString('categoría', $notice);
    $this->assertStringContainsString('solicitante', $notice);
  }

  /**
   * With no detail row there is still a node, and the three things the node
   * knows about itself are worth painting: which request this is, what it is
   * called and when it was created.
   */
  public function testTheDegradedSummaryFallsBackToTheNode() {
    $node = (object) [
      'nid'     => 412,
      'title'   => 'Fuga en el baño',
      'created' => mktime(9, 30, 0, 9, 4, 2026),
    ];

    $this->assertSame([
      ['ID', '412'],
      ['Título', 'Fuga en el baño'],
      ['Fecha de creación', '04/09/2026 09:30'],
    ], myapi_service_request_admin_degraded_summary_rows($node));
  }

  public function testTheDegradedSummarySurvivesAnUntitledNode() {
    $node = (object) ['nid' => 412, 'title' => '', 'created' => NULL];
    $rows = myapi_service_request_admin_degraded_summary_rows($node);

    $this->assertSame('—', $rows[1][1]);
    $this->assertSame('—', $rows[2][1]);
  }

  /* -------------------------------------------------------------------------
   * Block 2 — the transaction timeline.
   * ---------------------------------------------------------------------- */

  private function transaction(array $overrides = []) {
    return (object) ($overrides + [
      'nid'         => 900,
      'status'      => 'assigned',
      'status_date' => '2026-09-05 14:20:00',
      'comment'     => 'Se adjudica a Delta.',
      'uid'         => 3,
      'user_name'   => 'operador',
      'created'     => mktime(14, 21, 0, 9, 5, 2026),
    ]);
  }

  /**
   * Four cells and not six. The SPEC 94 builder appends 'Editar' and 'Borrar',
   * two write links; this screen promises none.
   */
  public function testTheTimelineHasNoEditOrDeleteCell() {
    $rows = myapi_service_request_admin_timeline_table_rows([$this->transaction()]);

    $this->assertCount(1, $rows);
    $this->assertCount(4, $rows[0]);
    $this->assertSame([
      'Asignada',
      '2026-09-05 14:20',
      'Se adjudica a Delta.',
      'operador',
    ], $rows[0]);
  }

  /**
   * field_status_date was created with tz_handling = 'none' (SPEC 55): what is
   * stored is a naive local time, and converting it would shift the hour
   * somebody typed by hand. The cut to sixteen characters drops the seconds
   * and nothing else. This case fails the moment somebody "improves" the cell
   * with strtotime().
   */
  public function testTheStatusDateIsNeverConverted() {
    $rows = myapi_service_request_admin_timeline_table_rows([
      $this->transaction(['status_date' => '2026-01-31 23:59:00']),
    ]);

    $this->assertSame('2026-01-31 23:59', $rows[0][1]);
  }

  public function testEveryTimelineCellDegradesOnItsOwn() {
    $rows = myapi_service_request_admin_timeline_table_rows([
      $this->transaction([
        'status'      => NULL,
        'status_date' => NULL,
        'comment'     => '',
        'user_name'   => NULL,
      ]),
    ]);

    $this->assertSame('—', $rows[0][0]);
    $this->assertSame('—', $rows[0][1]);
    $this->assertSame('—', $rows[0][2]);
    $this->assertSame('Usuario eliminado (#3)', $rows[0][3], 'the SPEC 94 author label, reused');
  }

  /**
   * A status the catalogue does not know prints RAW rather than disappearing:
   * a field edited by hand is exactly what the operator needs to see.
   */
  public function testAnUnknownTransactionStatusPrintsRaw() {
    $rows = myapi_service_request_admin_timeline_table_rows([
      $this->transaction(['status' => 'archived']),
    ]);

    $this->assertSame('archived', $rows[0][0]);
  }

  public function testAnEmptyTimelineIsAnEmptyTable() {
    $this->assertSame([], myapi_service_request_admin_timeline_table_rows([]));
  }

  /* -------------------------------------------------------------------------
   * Block 3 — the offers received.
   * ---------------------------------------------------------------------- */

  private function offer(array $overrides = []) {
    return (object) ($overrides + [
      'nid'               => 77,
      'created'           => mktime(11, 0, 0, 9, 3, 2026),
      'provider_id'       => 88,
      'provider_name'     => 'Plomería Delta',
      'provider_logo_uri' => NULL,
      'amount'            => '150.00',
      'message'           => 'Incluye materiales.',
      'status'            => 'selected',
      'amount_type'       => 'fixed',
      'valid_until'       => '2026-09-20 00:00:00',
      'available_from'    => NULL,
      'duration'          => NULL,
      'duration_unit'     => NULL,
      'includes'          => NULL,
      'excludes'          => NULL,
      'tax_included'      => NULL,
      'warranty_days'     => NULL,
      'requires_visit'    => NULL,
    ]);
  }

  public function testAnOfferBecomesTheEightDocumentedCells() {
    $rows = myapi_service_request_admin_offer_table_rows([$this->offer()], NULL);

    $this->assertCount(1, $rows);
    $this->assertSame([
      '77',
      'Plomería Delta',
      'Seleccionada',
      '150.00',
      'Precio cerrado',
      '20/09/2026 00:00',
      'Incluye materiales.',
      '03/09/2026 11:00',
    ], $rows[0]['data']);
  }

  /**
   * The awarded offer is marked in place, by matching its nid against the
   * summary's assigned_offer_id. It is a mark, not a control.
   */
  public function testTheAwardedOfferIsMarkedAndTheOthersAreNot() {
    $rows = myapi_service_request_admin_offer_table_rows([
      $this->offer(['nid' => 77]),
      $this->offer(['nid' => 78, 'status' => 'rejected']),
    ], 77);

    $this->assertContains('myapi-offer-selected', $rows[0]['class']);
    $this->assertNotContains('myapi-offer-selected', $rows[1]['class']);
  }

  public function testNoOfferIsMarkedWhenNothingIsAwarded() {
    $rows = myapi_service_request_admin_offer_table_rows([$this->offer()], NULL);

    $this->assertNotContains('myapi-offer-selected', $rows[0]['class']);
  }

  /**
   * An offer created before SPEC 100 has a row in none of the ten quote
   * tables, so its three quote cells answer the em dash. It must not vanish:
   * the list has to agree with offers_count.
   */
  public function testAnOfferOlderThanTheQuoteFieldsKeepsItsRow() {
    $rows = myapi_service_request_admin_offer_table_rows([
      $this->offer([
        'amount'      => NULL,
        'amount_type' => NULL,
        'valid_until' => NULL,
      ]),
    ], NULL);

    $this->assertCount(1, $rows);
    $this->assertSame('—', $rows[0]['data'][3]);
    $this->assertSame('—', $rows[0]['data'][4]);
    $this->assertSame('—', $rows[0]['data'][5]);
  }

  /**
   * An offer whose provider was unpublished stays in the list — that is how
   * SPEC 89 built the query, so that the list and offers_count agree — and the
   * cell says what happened instead of going blank.
   */
  public function testAnOfferWhoseProviderIsGoneSaysSo() {
    $rows = myapi_service_request_admin_offer_table_rows([
      $this->offer(['provider_id' => NULL, 'provider_name' => NULL]),
    ], NULL);

    $this->assertCount(1, $rows);
    $this->assertSame('Proveedor eliminado', $rows[0]['data'][1]);
  }

  public function testAnUnknownOfferStatusPrintsRaw() {
    $rows = myapi_service_request_admin_offer_table_rows([
      $this->offer(['status' => 'archived']),
    ], NULL);

    $this->assertSame('archived', $rows[0]['data'][2]);
  }

  public function testAnEmptyOfferListIsAnEmptyTable() {
    $this->assertSame([], myapi_service_request_admin_offer_table_rows([], 77));
  }

  /* -------------------------------------------------------------------------
   * Block 4 — the attachments.
   * ---------------------------------------------------------------------- */

  private function fileNode(array $overrides = []) {
    return (object) ($overrides + [
      'nid'              => 412,
      'field_attachment' => [LANGUAGE_NONE => [
        ['fid' => 1, 'filename' => 'presupuesto.pdf', 'uri' => 'private://myapi/requests/presupuesto.pdf', 'filemime' => 'application/pdf'],
      ]],
      'field_images'     => [LANGUAGE_NONE => [
        ['fid' => 2, 'filename' => 'antes.jpg', 'uri' => 'private://myapi/requests/antes.jpg', 'filemime' => 'image/jpeg'],
        ['fid' => 3, 'filename' => 'despues.jpg', 'uri' => 'private://myapi/requests/despues.jpg', 'filemime' => 'image/jpeg'],
      ]],
    ]);
  }

  /**
   * The attachment first and the images afterwards in their stored delta
   * order, which is the order they were uploaded in and the order the app
   * paints them.
   */
  public function testTheFilesComeOffTheLoadedNodeInOrder() {
    $files = myapi_service_request_admin_node_files($this->fileNode());

    $this->assertSame([1, 2, 3], array_column($files, 'fid'));
    $this->assertSame(['attachment', 'image', 'image'], array_column($files, 'source'));
    $this->assertSame('presupuesto.pdf', $files[0]['filename']);
    $this->assertSame('private://myapi/requests/antes.jpg', $files[1]['uri']);
    $this->assertSame('image/jpeg', $files[2]['filemime']);
  }

  public function testANodeWithNoFilesAnswersAnEmptyList() {
    $this->assertSame([], myapi_service_request_admin_node_files((object) ['nid' => 412]));
    $this->assertSame([], myapi_service_request_admin_node_files((object) [
      'nid'              => 412,
      'field_attachment' => [],
      'field_images'     => [LANGUAGE_NONE => []],
    ]));
  }

  public function testEitherCollectionAloneIsEnough() {
    $only_attachment = myapi_service_request_admin_node_files((object) [
      'field_attachment' => [LANGUAGE_NONE => [
        ['fid' => 1, 'filename' => 'a.pdf', 'uri' => 'private://a.pdf', 'filemime' => 'application/pdf'],
      ]],
    ]);
    $only_images = myapi_service_request_admin_node_files((object) [
      'field_images' => [LANGUAGE_NONE => [
        ['fid' => 2, 'filename' => 'b.jpg', 'uri' => 'private://b.jpg', 'filemime' => 'image/jpeg'],
      ]],
    ]);

    $this->assertSame(['attachment'], array_column($only_attachment, 'source'));
    $this->assertSame(['image'], array_column($only_images, 'source'));
  }

  /**
   * A field item with no fid is not a file: it is the empty widget row Drupal
   * leaves behind, and it must not become a row with a broken link.
   */
  public function testAFieldItemWithoutAFidIsNotAFile() {
    $files = myapi_service_request_admin_node_files((object) [
      'field_images' => [LANGUAGE_NONE => [
        ['fid' => 0, 'filename' => '', 'uri' => '', 'filemime' => ''],
        ['fid' => 5, 'filename' => 'c.jpg', 'uri' => 'private://c.jpg', 'filemime' => 'image/jpeg'],
      ]],
    ]);

    $this->assertSame([5], array_column($files, 'fid'));
  }

  /**
   * THE POINT OF BLOCK 4. myapi_service_request_load_images() builds
   * api/v1/service-requests/{id}/files/{fid} URLs, which demand a Bearer token
   * a back-office session does not carry: every link would answer 401. What
   * this screen serves is file_create_url(), which hook_file_download()
   * (SPEC 89) already authorises for the three roles and already scopes by
   * condominium.
   */
  public function testTheAttachmentLinksNeverPointAtTheApi() {
    $rows = myapi_service_request_admin_file_table_rows(
      myapi_service_request_admin_node_files($this->fileNode())
    );

    $this->assertCount(3, $rows);

    foreach ($rows as $row) {
      $this->assertStringNotContainsString('api/v1', $row[0]);
      $this->assertStringContainsString('<a href="', $row[0]);
    }

    $this->assertStringContainsString('presupuesto.pdf', $rows[0][0]);
    $this->assertSame('application/pdf', $rows[0][1]);
    $this->assertSame('Adjunto', $rows[0][2]);
    $this->assertSame('Imagen', $rows[1][2]);
  }

  /**
   * A file row whose filename was lost still offers a way to open it: the
   * fid, which is what the link needs anyway.
   */
  public function testAFileWithNoNameIsStillReachable() {
    $rows = myapi_service_request_admin_file_table_rows([
      ['fid' => 9, 'filename' => '', 'uri' => 'private://x.bin', 'filemime' => '', 'source' => 'image'],
    ]);

    $this->assertStringContainsString('#9', $rows[0][0]);
    $this->assertSame('—', $rows[0][1]);
  }

  public function testAFilenameIsEscaped() {
    $rows = myapi_service_request_admin_file_table_rows([
      ['fid' => 9, 'filename' => '<img src=x>', 'uri' => 'private://x.bin', 'filemime' => 'image/jpeg', 'source' => 'image'],
    ]);

    $this->assertStringNotContainsString('<img', $rows[0][0]);
  }

  public function testAnEmptyFileListIsAnEmptyTable() {
    $this->assertSame([], myapi_service_request_admin_file_table_rows([]));
  }

  /* -------------------------------------------------------------------------
   * Access.
   * ---------------------------------------------------------------------- */

  private function requestNode() {
    return (object) ['nid' => 412, 'type' => 'service_request', 'title' => 'x'];
  }

  /**
   * The bundle gate. Without it the route renders a supervision screen for any
   * node id on the site — every field empty, but the timeline and the offers
   * of nothing, and the route itself readable as a directory of node ids.
   */
  public function testOnlyAServiceRequestOpensTheScreen() {
    $GLOBALS['user'] = (object) ['uid' => 5, 'roles' => [3 => 'backend']];
    $GLOBALS['myapi_test_node_access_default'] = TRUE;

    $this->assertTrue(myapi_service_request_admin_detail_access($this->requestNode()));
    $this->assertFalse(myapi_service_request_admin_detail_access((object) ['nid' => 1, 'type' => 'reclamo']));
    $this->assertFalse(myapi_service_request_admin_detail_access(NULL));
  }

  /**
   * The role gate is the listing's own callback, not a second list: the day a
   * fourth role is allowed to supervise, it is allowed in one place.
   */
  public function testTheRoleGateIsTheListingsOwn() {
    $GLOBALS['myapi_test_node_access_default'] = TRUE;

    $GLOBALS['user'] = (object) ['uid' => 5, 'roles' => [2 => 'authenticated user']];
    $this->assertFalse(myapi_service_request_admin_detail_access($this->requestNode()));

    foreach (myapi_service_requests_admin_roles() as $rid => $role) {
      $GLOBALS['user'] = (object) ['uid' => 5, 'roles' => [2 => 'authenticated user', 10 + $rid => $role]];
      $this->assertTrue(
        myapi_service_request_admin_detail_access($this->requestNode()),
        $role . ': supervises the listing but not the detail'
      );
    }
  }

  /**
   * The per-node half. An 'administrador edificio' holds the role and still
   * must not open a request of a condominium that is not theirs — and the rule
   * that says so is node_access(), never a condominium list written here.
   */
  public function testTheRoleAloneIsNotEnoughWhenNodeAccessSaysNo() {
    $GLOBALS['user'] = (object) ['uid' => 5, 'roles' => [4 => MYAPI_BUILDING_ADMIN_ROLE]];
    $GLOBALS['myapi_test_node_access'] = ['view:412' => FALSE];

    $this->assertFalse(myapi_service_request_admin_detail_access($this->requestNode()));
    $this->assertContains('view:412', $GLOBALS['myapi_test_node_access_calls']);
  }

  /* -------------------------------------------------------------------------
   * The promise of the whole spec.
   * ---------------------------------------------------------------------- */

  /**
   * The file under test with every comment removed.
   *
   * The two cases below assert over the SOURCE, and the source is a file whose
   * docblocks explain at length which write paths it deliberately does not
   * take — naming them. Scanning the raw bytes would make the explanation
   * itself fail the assertion, so the tokens are walked and the comments
   * dropped: what is left is the code, which is what the promise is about.
   *
   * @return string
   */
  private function codeWithoutComments() {
    $code = '';

    foreach (token_get_all(file_get_contents(__DIR__ . '/../../includes/myapi.service_request_detail_admin.inc')) as $token) {
      if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
          continue;
        }
        $code .= $token[1];
        continue;
      }

      $code .= $token;
    }

    return $code;
  }

  /**
   * SPEC 126 promises a screen with no control that leads to a write. It is a
   * promise about a file, so it is asserted over the file: this is the case
   * that fails the day somebody adds an "Editar" link because it was
   * convenient, or reaches for the SPEC 94 table builder and gets its two
   * write cells for free.
   */
  public function testTheScreenHasNoWriteAffordanceAtAll() {
    $source = $this->codeWithoutComments();

    foreach ([
      'node_save',
      'node_delete',
      'db_insert',
      'db_update',
      'db_delete',
      'drupal_get_form',
      '#type\' => \'submit',
      'service-transaction',
      '/edit',
      'myapi_service_transaction_timeline_table_rows',
      'myapi_service_transaction_timeline_build',
    ] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $source, $forbidden . ': the supervision screen must carry no write path');
    }
  }

  /**
   * And it opens no query of its own: every row it paints comes from a
   * function SPEC 89, SPEC 94 or SPEC 125 already wrote. A db_select() here
   * would be a fourth answer to "what is a service request".
   */
  public function testTheScreenOpensNoQueryOfItsOwn() {
    $source = $this->codeWithoutComments();

    $this->assertStringNotContainsString('db_select', $source);
    $this->assertStringNotContainsString('db_query', $source);
  }
}
