<?php

use PHPUnit\Framework\TestCase;

// Four includes, and in this order: module_load_include() is a no-op in
// tests/unit/bootstrap.php, so every dependency the file under test pulls at
// run time has to be required here for real — the design constraint
// tests/README.md already documents.
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.time_format.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_transaction_admin.inc';

/**
 * Unit tests for the back-office transaction timeline of a service request
 * (SPEC 94), whose pure logic lives in
 * includes/myapi.service_transaction_admin.inc.
 *
 * Five groups, all of them free of the database:
 *   - myapi_service_transaction_author_label()          — the author cell;
 *   - myapi_service_transaction_timeline_table_rows()   — the six cells of a
 *     row, the status label fallback and the date that must NOT be converted;
 *   - myapi_service_transaction_validate_status_date()  — the 'AAAA-MM-DD
 *     HH:MM' rule, including the bare date that has to fail;
 *   - myapi_service_transaction_request_form_alter() and
 *     ..._transaction_form_alter()                      — the two FAPI walks;
 *   - myapi_service_transaction_edit_form_submit_redirect() — the redirect
 *     back to the request, read off the saved node.
 *
 * Deliberately NOT tested here, and said out loud rather than skipped in
 * silence (same criterion as ClaimTransactionEditTest and
 * ServiceTransactionTest):
 *   - myapi_service_transaction_timeline_rows() — db_select() with four joins;
 *   - myapi_service_transaction_edit_link() / _delete_link() — node_load() +
 *     node_access() + l(), three Drupal calls and no branch of their own;
 *   - myapi_service_transaction_sync_request_status() — node_load() +
 *     node_save(), i.e. the Field API and the database;
 *   - myapi_service_transaction_create_form_submit() and
 *     _delete_form_submit()  — same reason;
 *   - myapi_service_transaction_add_access() / _delete_access() — node_access()
 *     resolution, which this layer's fixture cannot prove.
 * All of them are in SPEC 94's manual acceptance matrix instead.
 *
 * The two link builders are still REACHED here, because the table builder
 * calls them: the node_access() fixture is left answering TRUE by default so
 * the two action cells are deterministic, and one case pins the empty cell a
 * denied delete produces. That case proves the table renders what the link
 * builder returned — never that Drupal's own access resolution works.
 *
 * Several cases are guards against a future edit rather than checks of today's
 * output: testStatusDateIsNeverConverted() fails the moment somebody
 * "improves" the date cell with strtotime(), which would shift a naive local
 * time by the site's timezone; testEveryDeltaOfEveryLanguageIsDisabled() fails
 * if only the first target_id is ever locked, invisible on a monolingual site;
 * and testSubmitHandlerIsAppendedLast() fails if the redirect is ever
 * prepended, which would run it before node_form_submit() has left the saved
 * node in $form_state.
 */
class ServiceTransactionAdminTest extends TestCase {

  protected function setUp(): void {
    myapi_test_node_seed();
    $GLOBALS['myapi_test_node_access'] = [];
    $GLOBALS['myapi_test_node_access_default'] = TRUE;
    $GLOBALS['myapi_test_node_access_calls'] = [];
    $GLOBALS['myapi_test_form_errors'] = [];
  }

  protected function tearDown(): void {
    myapi_test_node_seed();
    unset($GLOBALS['myapi_test_node_access'], $GLOBALS['myapi_test_node_access_default']);
    $GLOBALS['myapi_test_form_errors'] = [];
  }

  /**
   * One row of myapi_service_transaction_timeline_rows(), with every column
   * the table reads. The shape is the query's: an object, status_date as the
   * full stored 'Y-m-d H:i:s' string, uid the transaction's own author.
   */
  private function row(array $overrides = []) {
    return (object) ($overrides + [
      'nid'         => 71,
      'status'      => 'assigned',
      'status_date' => '2026-08-19 14:30:00',
      'comment'     => 'Se asigna al proveedor seleccionado.',
      'uid'         => 41,
      'user_name'   => 'operador',
      'created'     => 1755612600,
    ]);
  }

  /* -------------------------------------------------------------------------
   * myapi_service_transaction_author_label()
   * ---------------------------------------------------------------------- */

  public function testAuthorPresentIsTheEscapedName() {
    $this->assertSame('operador', myapi_service_transaction_author_label($this->row()));
  }

  /**
   * The LEFT JOIN to users leaves user_name NULL when the account was deleted
   * after the transaction was written. The uid is still printed, because it is
   * the only trace of who it was.
   */
  public function testDeletedAuthorFallsBackToTheUidMarker() {
    $label = myapi_service_transaction_author_label($this->row(['user_name' => NULL, 'uid' => 41]));

    $this->assertSame('Usuario eliminado (#41)', $label);
  }

  /**
   * uid 0 is not a special case: it is the anonymous account, which exists in
   * the users table, so the join resolves and there is nothing to mark. The
   * marker is about a MISSING row, never about a particular uid — a
   * transaction saved programmatically with uid 0 shows the anonymous name.
   */
  public function testAnonymousAuthorIsNotTreatedAsDeleted() {
    $row = $this->row(['uid' => 0, 'user_name' => 'Anónimo']);

    $this->assertSame('Anónimo', myapi_service_transaction_author_label($row));
  }

  public function testAuthorNameIsEscaped() {
    $row = $this->row(['user_name' => '<script>x</script>']);

    $this->assertSame('&lt;script&gt;x&lt;/script&gt;', myapi_service_transaction_author_label($row));
  }

  /* -------------------------------------------------------------------------
   * myapi_service_transaction_timeline_table_rows()
   * ---------------------------------------------------------------------- */

  public function testCompleteRowFillsTheSixCells() {
    $rows = myapi_service_transaction_timeline_table_rows([$this->row()], 412);

    $this->assertCount(1, $rows);
    $this->assertCount(6, $rows[0]);
    $this->assertSame('Asignada', $rows[0][0]);
    $this->assertSame('2026-08-19 14:30', $rows[0][1]);
    $this->assertSame('Se asigna al proveedor seleccionado.', $rows[0][2]);
    $this->assertSame('operador', $rows[0][3]);
  }

  /**
   * The label comes from myapi_services_request_statuses() and is never
   * transcribed here — renaming it in the catalogue renames it in the table.
   */
  public function testEveryCatalogueStatusIsLabelled() {
    foreach (myapi_services_request_statuses() as $key => $label) {
      $rows = myapi_service_transaction_timeline_table_rows([$this->row(['status' => $key])], 412);

      $this->assertSame($label, $rows[0][0]);
    }
  }

  /**
   * A key that is not in the catalogue still reads, rather than emptying the
   * cell: same fallback myapi_service_transaction_initial_comment() applies to
   * the same catalogue (SPEC 92).
   */
  public function testStatusOutsideTheCatalogueFallsBackToTheRawKey() {
    $rows = myapi_service_transaction_timeline_table_rows([$this->row(['status' => 'archived'])], 412);

    $this->assertSame('archived', $rows[0][0]);
  }

  public function testMissingStatusRendersTheDash() {
    $rows = myapi_service_transaction_timeline_table_rows([$this->row(['status' => NULL])], 412);

    $this->assertSame('—', $rows[0][0]);
  }

  public function testRowWithoutCommentRendersTheDash() {
    $empty = myapi_service_transaction_timeline_table_rows([$this->row(['comment' => ''])], 412);
    $null = myapi_service_transaction_timeline_table_rows([$this->row(['comment' => NULL])], 412);

    $this->assertSame('—', $empty[0][2]);
    $this->assertSame('—', $null[0][2]);
  }

  public function testMissingStatusDateRendersTheDash() {
    $rows = myapi_service_transaction_timeline_table_rows([$this->row(['status_date' => NULL])], 412);

    $this->assertSame('—', $rows[0][1]);
  }

  /**
   * THE GUARD. field_status_date was created with tz_handling = 'none'
   * (SPEC 55), so the stored value is a naive local time: the cell is a
   * substring of it and never format_date(strtotime(...)). A conversion would
   * silently move the hour somebody typed by hand, and would do it only on
   * sites whose timezone is not UTC — the worst kind of bug to find later.
   */
  public function testStatusDateIsNeverConverted() {
    $rows = myapi_service_transaction_timeline_table_rows([
      $this->row(['status_date' => '2026-01-01 00:05:00']),
    ], 412);

    $this->assertSame('2026-01-01 00:05', $rows[0][1]);
  }

  public function testCommentIsEscaped() {
    $rows = myapi_service_transaction_timeline_table_rows([
      $this->row(['comment' => '<b>ojo</b>']),
    ], 412);

    $this->assertSame('&lt;b&gt;ojo&lt;/b&gt;', $rows[0][2]);
  }

  /**
   * The builder does not sort: it renders what the query already ordered
   * (field_status_date DESC, nid DESC). A sort here would be a second source
   * of truth for the order, and the two would drift.
   */
  public function testTheOrderOfTheRowsIsRespected() {
    $rows = myapi_service_transaction_timeline_table_rows([
      $this->row(['nid' => 90, 'status' => 'closed', 'status_date' => '2026-08-20 10:00:00']),
      $this->row(['nid' => 71, 'status' => 'assigned', 'status_date' => '2026-08-19 14:30:00']),
      $this->row(['nid' => 12, 'status' => 'open', 'status_date' => '2026-08-18 09:00:00']),
    ], 412);

    $this->assertSame(['Cerrada', 'Asignada', 'Abierta'], [$rows[0][0], $rows[1][0], $rows[2][0]]);
  }

  /**
   * Both action cells are render elements and not strings: theme_table() runs
   * check_plain() over a string cell, which would print the <a> tag instead of
   * the link.
   */
  public function testActionCellsAreMarkupRenderElements() {
    $rows = myapi_service_transaction_timeline_table_rows([$this->row()], 412);

    $this->assertArrayHasKey('data', $rows[0][4]);
    $this->assertArrayHasKey('#markup', $rows[0][4]['data']);
    $this->assertArrayHasKey('data', $rows[0][5]);
    $this->assertArrayHasKey('#markup', $rows[0][5]['data']);
  }

  /**
   * The delete link carries the REQUEST in its path, not only the transaction:
   * that first nid is what myapi_service_transaction_delete_access() checks
   * the transaction against, and the reason this builder takes the request nid
   * as a second argument at all.
   */
  public function testDeleteCellPointsAtTheRequestScopedRoute() {
    myapi_test_node_seed([71 => ['nid' => 71, 'type' => 'service_transaction']]);

    $rows = myapi_service_transaction_timeline_table_rows([$this->row()], 412);

    $this->assertStringContainsString('node/412/service-transaction/71/delete', $rows[0][5]['data']['#markup']);
    $this->assertStringContainsString('node/71/edit', $rows[0][4]['data']['#markup']);
  }

  /**
   * No access, EMPTY cell — no link and no substitute text. A link that leads
   * to a 403 is worse than no link.
   */
  public function testDeniedDeleteLeavesTheCellEmpty() {
    myapi_test_node_seed([71 => ['nid' => 71, 'type' => 'service_transaction']]);
    $GLOBALS['myapi_test_node_access']['delete:71'] = FALSE;

    $rows = myapi_service_transaction_timeline_table_rows([$this->row()], 412);

    $this->assertSame('', $rows[0][5]['data']['#markup']);
    $this->assertNotSame('', $rows[0][4]['data']['#markup']);
  }

  public function testNoRowsProduceNoTableRows() {
    $this->assertSame([], myapi_service_transaction_timeline_table_rows([], 412));
  }

  /* -------------------------------------------------------------------------
   * myapi_service_transaction_validate_status_date()
   * ---------------------------------------------------------------------- */

  /**
   * The element shape the validator receives from FAPI. '#name' is what the
   * bootstrap's form_error() keys the recorded error by.
   */
  private function dateElement($value) {
    return ['#name' => 'field_status_date', '#value' => $value];
  }

  private function validate($value) {
    $element = $this->dateElement($value);
    $form_state = [];
    myapi_service_transaction_validate_status_date($element, $form_state);

    return $GLOBALS['myapi_test_form_errors'];
  }

  public function testWellFormedDateAndTimePasses() {
    $this->assertSame([], $this->validate('2026-08-19 14:30'));
  }

  public function testMidnightPasses() {
    $this->assertSame([], $this->validate('2026-08-19 00:00'));
  }

  public function testSurroundingWhitespaceIsTolerated() {
    $this->assertSame([], $this->validate('  2026-08-19 14:30  '));
  }

  /**
   * A bare date fails on purpose: the timeline records the exact instant of a
   * status change, and SPEC 58 already paid for the day-only version of this
   * field in claims.
   */
  public function testDateWithoutTimeFails() {
    $this->assertArrayHasKey('field_status_date', $this->validate('2026-08-19'));
  }

  public function testGarbageFails() {
    $this->assertArrayHasKey('field_status_date', $this->validate('ayer por la tarde'));
  }

  public function testEmptyValueFails() {
    $this->assertArrayHasKey('field_status_date', $this->validate(''));
  }

  /**
   * checkdate() and not the pattern alone: '2026-02-31' matches the shape and
   * is still not a day.
   */
  public function testImpossibleDateFails() {
    $this->assertArrayHasKey('field_status_date', $this->validate('2026-02-31 10:00'));
  }

  public function testImpossibleTimeFails() {
    $this->assertArrayHasKey('field_status_date', $this->validate('2026-08-19 24:00'));
  }

  /**
   * '8:00' has no leading zero. The rule is myapi_time_format_is_valid()'s,
   * reused rather than restated — the stored value is a fixed-width string
   * that other code compares and sorts as text.
   */
  public function testTimeWithoutLeadingZeroFails() {
    $this->assertArrayHasKey('field_status_date', $this->validate('2026-08-19 8:00'));
  }

  public function testSecondsAreNotAccepted() {
    $this->assertArrayHasKey('field_status_date', $this->validate('2026-08-19 14:30:00'));
  }

  public function testTwoDigitYearFails() {
    $this->assertArrayHasKey('field_status_date', $this->validate('26-08-19 14:30'));
  }

  /* -------------------------------------------------------------------------
   * myapi_service_transaction_request_form_alter()
   * ---------------------------------------------------------------------- */

  /**
   * The status field as an options_select widget: the element to disable is
   * the langcode level itself, one level shallower than an
   * entityreference_autocomplete.
   */
  private function requestForm($nid, array $langcodes = ['und']) {
    $form = ['#node' => (object) ($nid === NULL ? ['type' => 'service_request'] : ['nid' => $nid, 'type' => 'service_request'])];

    $form['field_request_status'] = ['#theme' => 'field_multiple_value_form'];
    foreach ($langcodes as $langcode) {
      $form['field_request_status'][$langcode] = ['#type' => 'select', '#language' => $langcode];
    }

    return $form;
  }

  /**
   * node/add/service_request: the status stays EDITABLE — it is where the
   * initial status is chosen, the one SPEC 92's automatic transaction copies —
   * and no timeline fieldset is built, because there is no history yet.
   */
  public function testCreationModeLeavesTheFormUntouched() {
    $form = $this->requestForm(NULL);
    $before = $form;
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertSame($before, $form);
    $this->assertArrayNotHasKey('myapi_service_transactions', $form);
  }

  public function testFormWithoutNodeIsLeftUntouched() {
    $form = ['field_request_status' => ['und' => ['#type' => 'select']]];
    $before = $form;
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertSame($before, $form);
  }

  public function testEditModeDisablesTheStatusField() {
    $form = $this->requestForm(412);
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertTrue($form['field_request_status']['und']['#disabled']);
  }

  /**
   * #disabled and NOT #access = FALSE: the operator has to keep SEEING the
   * status while deciding which transaction to create, and Drupal resubmits a
   * disabled element's default value, so saving the request never blanks it.
   */
  public function testTheStatusFieldIsDisabledAndNotHidden() {
    $form = $this->requestForm(412);
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertArrayNotHasKey('#access', $form['field_request_status']['und']);
    $this->assertSame('select', $form['field_request_status']['und']['#type']);
  }

  public function testEveryLanguageOfTheStatusFieldIsDisabled() {
    $form = $this->requestForm(412, ['und', 'es', 'en']);
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    foreach (['und', 'es', 'en'] as $langcode) {
      $this->assertTrue($form['field_request_status'][$langcode]['#disabled'], $langcode);
    }
  }

  public function testHashPropertiesAreNotWalkedAsLangcodes() {
    $form = $this->requestForm(412);
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertSame('field_multiple_value_form', $form['field_request_status']['#theme']);
  }

  /**
   * A form without the field still gets the fieldset: the two halves of this
   * alter are independent, and a field_access rule that removed the status is
   * no reason to hide the history.
   */
  public function testMissingStatusFieldStillAppendsTheFieldset() {
    $form = ['#node' => (object) ['nid' => 412, 'type' => 'service_request']];
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertArrayHasKey('myapi_service_transactions', $form);
  }

  public function testEditModeAppendsTheTimelineFieldsetAtTheEnd() {
    $form = $this->requestForm(412);
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertSame('fieldset', $form['myapi_service_transactions']['#type']);
    $this->assertGreaterThanOrEqual(900, $form['myapi_service_transactions']['#weight']);
    $this->assertArrayHasKey('create_link', $form['myapi_service_transactions']);
    $this->assertArrayHasKey('table', $form['myapi_service_transactions']);
  }

  /**
   * The action link comes FIRST and points at this request's own route.
   */
  public function testTheFieldsetLinksToTheCreationRouteOfThisRequest() {
    $form = $this->requestForm(412);
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertStringContainsString(
      'node/412/service-transaction/add',
      $form['myapi_service_transactions']['create_link']['#markup']
    );
  }

  /**
   * Six headers, in the order SPEC 94 fixes. A seventh column added without
   * a seventh header would silently shift every cell.
   */
  public function testTheTableCarriesTheSixHeaders() {
    $form = $this->requestForm(412);
    $form_state = [];

    myapi_service_transaction_request_form_alter($form, $form_state);

    $this->assertSame(
      ['Estado', 'Fecha del estado', 'Comentario', 'Autor', 'Editar', 'Borrar'],
      $form['myapi_service_transactions']['table']['#header']
    );
  }

  /* -------------------------------------------------------------------------
   * myapi_service_transaction_transaction_form_alter()
   * ---------------------------------------------------------------------- */

  /**
   * field_request as an entityreference_autocomplete widget: the real
   * textfield is nested under 'target_id' inside each delta.
   *
   * @param array $languages
   *   Langcode => list of deltas, each delta TRUE for a normal target_id
   *   element or FALSE for a delta built by some other widget.
   */
  private function transactionForm($nid, array $languages = ['und' => [0 => TRUE]]) {
    $form = ['#node' => (object) ($nid === NULL ? ['type' => 'service_transaction'] : ['nid' => $nid, 'type' => 'service_transaction'])];

    $form['field_request'] = ['#theme' => 'field_multiple_value_form'];
    foreach ($languages as $langcode => $deltas) {
      $form['field_request'][$langcode] = ['#language' => $langcode];
      foreach ($deltas as $delta => $has_target_id) {
        $form['field_request'][$langcode][$delta] = $has_target_id
          ? ['target_id' => ['#type' => 'textfield']]
          : ['#type' => 'markup'];
      }
    }

    $form['actions']['submit'] = ['#type' => 'submit', '#submit' => ['node_form_submit']];

    return $form;
  }

  /**
   * node/add/service_transaction: field_request stays editable — a transaction
   * being created belongs to no request yet — and no redirect is registered,
   * because there is nowhere to go back to.
   */
  public function testTransactionCreationModeLeavesTheFormUntouched() {
    $form = $this->transactionForm(NULL);
    $before = $form;
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame($before, $form);
  }

  public function testEditModeDisablesTheTargetId() {
    $form = $this->transactionForm(71);
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    $this->assertTrue($form['field_request']['und'][0]['target_id']['#disabled']);
  }

  /**
   * THE GUARD. Disabling only the first target_id passes unnoticed on a
   * monolingual, single-delta site and leaves the lock open everywhere else.
   */
  public function testEveryDeltaOfEveryLanguageIsDisabled() {
    $form = $this->transactionForm(71, [
      'und' => [0 => TRUE, 1 => TRUE],
      'es' => [0 => TRUE, 1 => TRUE, 2 => TRUE],
    ]);
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    foreach (['und' => [0, 1], 'es' => [0, 1, 2]] as $langcode => $deltas) {
      foreach ($deltas as $delta) {
        $this->assertTrue($form['field_request'][$langcode][$delta]['target_id']['#disabled'], $langcode . ':' . $delta);
      }
    }
  }

  public function testDeltaWithoutTargetIdIsSkippedWithoutBreakingTheWalk() {
    $form = $this->transactionForm(71, ['und' => [0 => FALSE, 1 => TRUE]]);
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    $this->assertArrayNotHasKey('#disabled', $form['field_request']['und'][0]);
    $this->assertTrue($form['field_request']['und'][1]['target_id']['#disabled']);
  }

  /**
   * The isset() guards the LOOP only. Without field_request there is nothing to
   * lock, but redirecting back is still right — the handler resolves the
   * request off the saved node, not off the form.
   */
  public function testMissingFieldRequestStillAddsTheSubmitHandler() {
    $form = ['#node' => (object) ['nid' => 71, 'type' => 'service_transaction']];
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    $this->assertContains('myapi_service_transaction_edit_form_submit_redirect', $form['#submit']);
  }

  /**
   * form_execute_handlers() runs the TRIGGERING ELEMENT's list when it has one
   * and ignores the form-level one entirely, and node_form() gives its Save
   * button its own. Without this second list, saving lands on node/<nid> —
   * exactly what acceptance criterion 28 forbids.
   */
  public function testSubmitHandlerIsAlsoAppendedToTheSaveButton() {
    $form = $this->transactionForm(71);
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame(
      ['node_form_submit', 'myapi_service_transaction_edit_form_submit_redirect'],
      $form['actions']['submit']['#submit']
    );
  }

  public function testButtonWithoutSubmitListIsNotGivenOne() {
    $form = $this->transactionForm(71);
    unset($form['actions']['submit']['#submit']);
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    $this->assertArrayNotHasKey('#submit', $form['actions']['submit']);
    $this->assertContains('myapi_service_transaction_edit_form_submit_redirect', $form['#submit']);
  }

  /**
   * THE GUARD. Prepended, the handler would run before node_form_submit() had
   * saved the node and left it in $form_state — and would read a stale
   * field_request, or none at all.
   */
  public function testSubmitHandlerIsAppendedLast() {
    $form = $this->transactionForm(71);
    $form['#submit'] = ['some_other_module_submit'];
    $form_state = [];

    myapi_service_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame(
      ['some_other_module_submit', 'myapi_service_transaction_edit_form_submit_redirect'],
      $form['#submit']
    );
  }

  /* -------------------------------------------------------------------------
   * myapi_service_transaction_edit_form_submit_redirect()
   * ---------------------------------------------------------------------- */

  private function savedTransaction($target_id) {
    $node = (object) ['nid' => 71, 'type' => 'service_transaction'];
    if ($target_id !== NULL) {
      $node->field_request = [LANGUAGE_NONE => [0 => ['target_id' => $target_id]]];
    }

    return $node;
  }

  public function testRedirectsToTheRequestEditForm() {
    $form_state = ['node' => $this->savedTransaction(412)];
    $form = [];

    myapi_service_transaction_edit_form_submit_redirect($form, $form_state);

    $this->assertSame('node/412/edit', $form_state['redirect']);
  }

  /**
   * An unresolvable field_request leaves the redirect ALONE rather than
   * pointing it at 'node//edit': Drupal then falls back to its own
   * destination, node/<nid>.
   */
  public function testMissingFieldRequestLeavesNoRedirect() {
    $form_state = ['node' => $this->savedTransaction(NULL)];
    $form = [];

    myapi_service_transaction_edit_form_submit_redirect($form, $form_state);

    $this->assertArrayNotHasKey('redirect', $form_state);
  }

  public function testEmptyFieldRequestLeavesNoRedirect() {
    $node = (object) ['nid' => 71, 'field_request' => [LANGUAGE_NONE => []]];
    $form_state = ['node' => $node];
    $form = [];

    myapi_service_transaction_edit_form_submit_redirect($form, $form_state);

    $this->assertArrayNotHasKey('redirect', $form_state);
  }

  public function testExistingRedirectIsNotOverwrittenWhenTheRequestIsUnresolved() {
    $form_state = ['node' => $this->savedTransaction(NULL), 'redirect' => 'admin/content'];
    $form = [];

    myapi_service_transaction_edit_form_submit_redirect($form, $form_state);

    $this->assertSame('admin/content', $form_state['redirect']);
  }

  /**
   * A submit chain another module altered can reach this handler with no node
   * in $form_state. Reading a missing index would be a PHP notice.
   */
  public function testMissingNodeInFormStateIsHarmless() {
    $form_state = [];
    $form = [];

    myapi_service_transaction_edit_form_submit_redirect($form, $form_state);

    $this->assertArrayNotHasKey('redirect', $form_state);
  }
  /* -------------------------------------------------------------------------
   * myapi_service_transaction_validate_status_date() (SPEC 94, covered by
   * SPEC 122).
   *
   * The element validator of the status date. It had no test of its own, which
   * is how its anchored pattern kept the trailing-newline hole SPEC 73 closed
   * everywhere else: "2026-06-15\n 10:00" used to be a valid instant.
   * ---------------------------------------------------------------------- */

  /**
   * A well-formed 'YYYY-MM-DD HH:MM' raises nothing — and so does one padded
   * with whitespace, because the value is trimmed before it is split. That
   * trim is also why a newline at the END of the whole value is harmless: only
   * one INSIDE it reaches the date half.
   */
  public function testAWellFormedStatusDateRaisesNothing() {
    $GLOBALS['myapi_test_form_errors'] = [];
    $element = ['#name' => 'field_status_date', '#value' => '2026-06-15 10:30'];
    $form_state = [];

    myapi_service_transaction_validate_status_date($element, $form_state);

    $this->assertSame([], $GLOBALS['myapi_test_form_errors']);

    $GLOBALS['myapi_test_form_errors'] = [];
    $padded = ['#name' => 'field_status_date', '#value' => "  2026-06-15 10:30\n"];
    myapi_service_transaction_validate_status_date($padded, $form_state);
    $this->assertSame([], $GLOBALS['myapi_test_form_errors'], 'the value is trimmed first');
  }

  /**
   * A BARE DATE FAILS, deliberately: the timeline records the exact instant of
   * a status change, so the time half is required.
   */
  public function testABareDateIsRejected() {
    $GLOBALS['myapi_test_form_errors'] = [];
    $element = ['#name' => 'field_status_date', '#value' => '2026-06-15'];
    $form_state = [];

    myapi_service_transaction_validate_status_date($element, $form_state);

    $this->assertArrayHasKey('field_status_date', $GLOBALS['myapi_test_form_errors']);
  }

  /**
   * Every malformed half is rejected — including, since SPEC 122, a date or a
   * time carrying a trailing newline, which the unanchored '$' used to let
   * through.
   */
  public function testEveryMalformedStatusDateIsRejected() {
    $invalid = [
      '15/06/2026 10:30',
      '2026-13-01 10:30',
      '2026-02-30 10:30',
      '2026-06-15 25:00',
      '2026-06-15 9:00',
      '2026-6-15 10:30',
      'ayer 10:30',
      '',
      // The newline INSIDE the value survives the trim() and lands on the date
      // half — the one shape the unanchored '$' used to accept.
      "2026-06-15\n 10:30",
    ];

    foreach ($invalid as $value) {
      $GLOBALS['myapi_test_form_errors'] = [];
      $element = ['#name' => 'field_status_date', '#value' => $value];
      $form_state = [];

      myapi_service_transaction_validate_status_date($element, $form_state);

      $this->assertArrayHasKey(
        'field_status_date',
        $GLOBALS['myapi_test_form_errors'],
        json_encode($value)
      );
    }
  }

}
