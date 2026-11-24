<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../myapi.module';
require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_form.inc';

/**
 * Unit tests for includes/myapi.reservation_form.inc (SPEC 134): the chain of
 * the four selectors of the back-office reservation form, and both halves of
 * the file.
 *
 *   - The pure half: the chain, the path id, the suggestion key, the edit
 *     detection and the rule that the four references must agree.
 *   - The Drupal-facing half, under the stubs of bootstrap.php: the form alter
 *     on a hand-built form array, the autocomplete callback through
 *     myapi_test_capture(), its queries against the fixture database, and the
 *     save validation through node_load() and form_set_error().
 *   - myapi_reservation_form_access(), the route's access callback, which
 *     lives in myapi.module.
 *
 * ONE LIMIT OF THE FIXTURE DATABASE shapes the query tests: the db_select()
 * stub does not evaluate a JOIN condition, so "only the units of THIS
 * condominium" cannot be asserted on the rows that come back. It is asserted
 * on the recorded query instead — the join, the condition and its value.
 *
 * Not tested here, because no unit test can: js/myapi.reservation_form.js and
 * how the form behaves under the back-office theme. That is the manual matrix
 * of the spec.
 *
 * The tests that matter most are the two about editing:
 * testAnEditThatLeavesTheReferencesAloneIsNotAChange() and
 * testAnEditThatTouchesNoReferenceIsNotValidated() are what keep an operator
 * able to cancel a reservation whose requester no longer lives in the unit.
 */
class ReservationFormTest extends TestCase {

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    $GLOBALS['myapi_test_form_errors'] = [];
    $GLOBALS['myapi_test_permissions'] = [];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    $GLOBALS['myapi_test_form_errors'] = [];
    $GLOBALS['myapi_test_permissions'] = [];
  }

  /* -------------------------------------------------------------------------
   * The chain — myapi_reservation_form_selectors().
   * ---------------------------------------------------------------------- */

  public function testTheChainIsCondominiumThenUnitAndAreaThenRequester() {
    $selectors = myapi_reservation_form_selectors();

    $this->assertSame(['condominium', 'unit', 'area', 'requester'], array_keys($selectors));
    $this->assertNull($selectors['condominium']['parent']);
    $this->assertSame('condominium', $selectors['unit']['parent']);
    $this->assertSame('condominium', $selectors['area']['parent']);
    $this->assertSame('unit', $selectors['requester']['parent']);
  }

  /**
   * GUARD: the JavaScript walks the list in order and reads a parent before
   * its children. A selector listed before its parent would read a stale value.
   */
  public function testEveryParentIsListedBeforeItsChildren() {
    $seen = [];
    foreach (myapi_reservation_form_selectors() as $kind => $selector) {
      if ($selector['parent'] !== NULL) {
        $this->assertContains($selector['parent'], $seen, $kind . ' comes after its parent');
      }
      $seen[] = $kind;
    }
  }

  public function testTheSelectorsMapToTheFourReservationFields() {
    $fields = array_column(myapi_reservation_form_selectors(), 'field');

    $this->assertSame(['field_condominium', 'field_unit', 'field_area', 'field_requester'], $fields);
  }

  /**
   * @dataProvider dependentKinds
   */
  public function testOnlyTheThreeDependentSelectorsAreServed($kind, $expected) {
    $this->assertSame($expected, myapi_reservation_form_is_dependent($kind));
  }

  public static function dependentKinds() {
    return [
      'unit'                     => ['unit', TRUE],
      'area'                     => ['area', TRUE],
      'requester'                => ['requester', TRUE],
      'the root has no parent'   => ['condominium', FALSE],
      'unknown'                  => ['payment', FALSE],
      'a field name is not one'  => ['field_unit', FALSE],
      'empty'                    => ['', FALSE],
      'not a string'             => [['unit'], FALSE],
      'null'                     => [NULL, FALSE],
    ];
  }

  /* -------------------------------------------------------------------------
   * Path argument — myapi_reservation_form_positive_int().
   * ---------------------------------------------------------------------- */

  /**
   * @dataProvider pathIds
   */
  public function testPositiveInt($value, $expected) {
    $this->assertSame($expected, myapi_reservation_form_positive_int($value));
  }

  public static function pathIds() {
    return [
      'digits'             => ['16533', 16533],
      'integer'            => [42, 42],
      'zero means none'    => ['0', 0],
      'integer zero'       => [0, 0],
      'negative integer'   => [-3, 0],
      'negative string'    => ['-3', 0],
      'empty'              => ['', 0],
      'trailing letters'   => ['12abc', 0],
      'sql'                => ['1 OR 1=1', 0],
      'decimal'            => ['1.5', 0],
      'newline after'      => ["12\n", 0],
      'boolean'            => [FALSE, 0],
      'array'              => [['1'], 0],
      'null'               => [NULL, 0],
    ];
  }

  /* -------------------------------------------------------------------------
   * Suggestion key — myapi_reservation_form_autocomplete_key().
   * ---------------------------------------------------------------------- */

  public function testTheKeyIsLabelAndIdInParentheses() {
    $this->assertSame('Motora 2 (30057)', myapi_reservation_form_autocomplete_key('Motora 2', 30057));
  }

  /**
   * GUARD: entityreference's element validation reads the id back with
   * /.+\((\d+)\)/. Whatever the label looks like, the key must satisfy it.
   */
  public function testEntityreferenceCanReadTheIdBackFromAnyKey() {
    $labels = ['Motora 2', 'Torre A, piso 3', 'Salón "Los Pinos"', "  Piscina \n grande ", '<b>Asadero</b>', 'Casa (2)'];

    foreach ($labels as $label) {
      $key = myapi_reservation_form_autocomplete_key($label, 77);
      $this->assertSame(1, preg_match('/.+\((\d+)\)/', $key, $m), $key);
      $this->assertSame('77', $m[1], $key);
    }
  }

  public function testALabelWithACommaOrAQuoteIsWrappedInQuotes() {
    $this->assertSame('"Torre A, piso 3 (5)"', myapi_reservation_form_autocomplete_key('Torre A, piso 3', 5));
    $this->assertSame('"Salón ""Los Pinos"" (5)"', myapi_reservation_form_autocomplete_key('Salón "Los Pinos"', 5));
  }

  public function testTagsLineBreaksAndRunsOfSpacesAreStripped() {
    $this->assertSame('Asadero (5)', myapi_reservation_form_autocomplete_key('<b>Asadero</b>', 5));
    $this->assertSame('Piscina grande (5)', myapi_reservation_form_autocomplete_key("  Piscina   grande\n", 5));
  }

  /* -------------------------------------------------------------------------
   * Edit detection — myapi_reservation_form_references_changed().
   * ---------------------------------------------------------------------- */

  private function references(array $override = []) {
    return $override + [
      'field_condominium' => 16533,
      'field_unit'        => 30057,
      'field_area'        => 40001,
      'field_requester'   => 80614,
    ];
  }

  /**
   * GUARD: cancelling is an edit that touches none of the four. If this ever
   * reads as a change, a reservation whose requester moved out of the unit can
   * no longer be cancelled from the back office.
   */
  public function testAnEditThatLeavesTheReferencesAloneIsNotAChange() {
    $this->assertFalse(myapi_reservation_form_references_changed($this->references(), $this->references()));
  }

  public function testStoredStringsAndSubmittedIntegersCompareEqual() {
    $stored = array_map('strval', $this->references());

    $this->assertFalse(myapi_reservation_form_references_changed($this->references(), $stored));
  }

  /**
   * @dataProvider referenceFields
   */
  public function testChangingAnyOneOfTheFourIsAChange($field) {
    $submitted = $this->references([$field => 999]);

    $this->assertTrue(myapi_reservation_form_references_changed($submitted, $this->references()));
  }

  public static function referenceFields() {
    return [['field_condominium'], ['field_unit'], ['field_area'], ['field_requester']];
  }

  public function testEmptyingAReferenceIsAChange() {
    $submitted = $this->references(['field_area' => NULL]);

    $this->assertTrue(myapi_reservation_form_references_changed($submitted, $this->references()));
  }

  public function testAMissingStoredReferenceIsAChange() {
    $stored = $this->references();
    unset($stored['field_unit']);

    $this->assertTrue(myapi_reservation_form_references_changed($this->references(), $stored));
  }

  /* -------------------------------------------------------------------------
   * The rule — myapi_reservation_form_scope_errors().
   * ---------------------------------------------------------------------- */

  private function fields(array $errors) {
    return array_column($errors, 'field');
  }

  public function testACoherentReservationHasNoErrors() {
    $errors = myapi_reservation_form_scope_errors(16533, 30057, [16533], 40001, [16533], 80614, [80614, 80615]);

    $this->assertSame([], $errors);
  }

  public function testIdsReadFromTheDatabaseAsStringsStillMatch() {
    $errors = myapi_reservation_form_scope_errors('16533', '30057', ['16533'], '40001', ['16533'], '80614', ['80614']);

    $this->assertSame([], $errors);
  }

  public function testAUnitOfAnotherCondominiumIsRefused() {
    $errors = myapi_reservation_form_scope_errors(16533, 30057, [999], 40001, [16533], 80614, [80614]);

    $this->assertSame(['field_unit'], $this->fields($errors));
  }

  public function testAnAreaOfAnotherCondominiumIsRefused() {
    $errors = myapi_reservation_form_scope_errors(16533, 30057, [16533], 40001, [999], 80614, [80614]);

    $this->assertSame(['field_area'], $this->fields($errors));
  }

  /**
   * The decision of this spec: the requester is scoped to the UNIT. A resident
   * of the same condominium who lives in another unit is refused.
   */
  public function testARequesterWhoDoesNotLiveInTheUnitIsRefused() {
    $errors = myapi_reservation_form_scope_errors(16533, 30057, [16533], 40001, [16533], 80614, [80615]);

    $this->assertSame(['field_requester'], $this->fields($errors));
  }

  public function testAUnitWithNobodyInItRefusesEveryRequester() {
    $errors = myapi_reservation_form_scope_errors(16533, 30057, [16533], 40001, [16533], 80614, []);

    $this->assertSame(['field_requester'], $this->fields($errors));
  }

  /**
   * A unit or an area whose condominium cannot be resolved fails closed.
   */
  public function testAnUnresolvedCondominiumIsRefused() {
    $errors = myapi_reservation_form_scope_errors(16533, 30057, [], 40001, [], 80614, [80614]);

    $this->assertSame(['field_unit', 'field_area'], $this->fields($errors));
  }

  public function testEveryMismatchIsReportedAtOnce() {
    $errors = myapi_reservation_form_scope_errors(16533, 30057, [999], 40001, [999], 80614, [1]);

    $this->assertSame(['field_unit', 'field_area', 'field_requester'], $this->fields($errors));
    foreach ($errors as $error) {
      $this->assertNotSame('', $error['message']);
    }
  }

  /**
   * Empty fields are Drupal's to report: the four are required. This rule
   * stays silent about a pair with an empty end.
   */
  public function testEmptyFieldsAreLeftToTheRequiredValidation() {
    $this->assertSame([], myapi_reservation_form_scope_errors(NULL, 30057, [999], 40001, [999], NULL, []));
    $this->assertSame([], myapi_reservation_form_scope_errors(16533, NULL, [], NULL, [], 80614, []));
    $this->assertSame([], myapi_reservation_form_scope_errors(NULL, NULL, [], NULL, [], NULL, []));
  }

  /**
   * With no condominium the unit pair is skipped, but the requester still has
   * to live in the unit: that pair does not depend on the condominium.
   */
  public function testTheRequesterRuleDoesNotNeedACondominium() {
    $errors = myapi_reservation_form_scope_errors(NULL, 30057, [16533], NULL, [], 80614, [1]);

    $this->assertSame(['field_requester'], $this->fields($errors));
  }

  /* -------------------------------------------------------------------------
   * The form — myapi_reservation_form_alter().
   * ---------------------------------------------------------------------- */

  const FIELDS = [
    'condominium' => 'field_condominium',
    'unit'        => 'field_unit',
    'area'        => 'field_area',
    'requester'   => 'field_requester',
  ];

  /**
   * The slice of a node form the alter reads: four entityreference
   * autocomplete widgets, as entityreference_field_widget_form() builds them.
   */
  private function form() {
    $form = ['title' => ['#type' => 'textfield']];
    foreach (self::FIELDS as $field) {
      $form[$field] = [
        '#language' => 'und',
        'und'       => [
          0 => [
            'target_id' => [
              '#type'              => 'textfield',
              '#autocomplete_path' => 'entityreference/autocomplete/single/' . $field . '/node/reservation/NULL',
            ],
          ],
        ],
      ];
    }

    return $form;
  }

  private function altered() {
    $form = $this->form();
    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    return $form;
  }

  public function testTheThreeDependentSelectorsAskThisModuleStartingAtParentZero() {
    $form = $this->altered();

    foreach (['unit', 'area', 'requester'] as $kind) {
      $this->assertSame(
        'admin/content/reservations/autocomplete/' . $kind . '/0',
        $form[self::FIELDS[$kind]]['und'][0]['target_id']['#autocomplete_path']
      );
    }
  }

  /**
   * The root keeps entityreference's own route: nothing scopes a condominium
   * but the operator's role, and that is SPEC 49's job.
   */
  public function testTheCondominiumKeepsItsEntityreferenceRoute() {
    $form = $this->altered();

    $this->assertSame(
      'entityreference/autocomplete/single/field_condominium/node/reservation/NULL',
      $form['field_condominium']['und'][0]['target_id']['#autocomplete_path']
    );
  }

  /**
   * The attribute is how the JavaScript finds the inputs; ids are Drupal's to
   * choose.
   */
  public function testTheFourInputsAreTaggedWithTheirSelectorName() {
    $form = $this->altered();

    foreach (self::FIELDS as $kind => $field) {
      $this->assertSame($kind, $form[$field]['und'][0]['target_id']['#attributes']['data-myapi-reservation-selector']);
    }
  }

  /**
   * GUARD: '#disabled' makes Drupal throw away the submitted value. Blocking
   * the inputs is the JavaScript's job and must stay there.
   */
  public function testNoSelectorIsDisabledOnTheServer() {
    $form = $this->altered();

    foreach (self::FIELDS as $field) {
      $this->assertArrayNotHasKey('#disabled', $form[$field]['und'][0]['target_id']);
    }
  }

  public function testTheScriptAndItsSettingsAreAttachedToTheForm() {
    $form = $this->altered();
    $js = $form['#attached']['js'];

    $this->assertContains(drupal_get_path('module', 'myapi') . '/js/myapi.reservation_form.js', $js);

    $settings = array_values(array_filter($js, 'is_array'));
    $this->assertCount(1, $settings);
    $this->assertSame('setting', $settings[0]['type']);

    $config = $settings[0]['data']['myapiReservationForm'];
    $this->assertSame(url('admin/content/reservations/autocomplete', ['absolute' => TRUE]), $config['basePath']);
    $this->assertSame(
      ['condominium' => NULL, 'unit' => 'condominium', 'area' => 'condominium', 'requester' => 'unit'],
      $config['parents']
    );
    $this->assertSame(
      ['unit' => '- Elige un condominio -', 'area' => '- Elige un condominio -', 'requester' => '- Elige una vivienda -'],
      $config['placeholders']
    );
  }

  /**
   * ALL OR NOTHING. A widget changed from the field UI — no '#language', or a
   * select with no '#autocomplete_path' — on ANY of the four leaves the whole
   * form exactly as it arrived, the other three included.
   *
   * @dataProvider brokenWidgets
   */
  public function testAFormThatIsNotTheExpectedShapeIsLeftUntouched($field, $breakage) {
    $form = $this->form();
    if ($breakage === 'language') {
      unset($form[$field]['#language']);
    }
    else {
      unset($form[$field]['und'][0]['target_id']['#autocomplete_path']);
    }
    $before = $form;

    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertSame($before, $form);
  }

  public static function brokenWidgets() {
    $cases = [];
    foreach (self::FIELDS as $field) {
      $cases[$field . ' without #language'] = [$field, 'language'];
      $cases[$field . ' without #autocomplete_path'] = [$field, 'path'];
    }

    return $cases;
  }

  public function testAFormWithoutTheFieldsIsLeftUntouched() {
    $form = ['title' => ['#type' => 'textfield']];
    $form_state = [];
    myapi_reservation_form_alter($form, $form_state);

    $this->assertSame(['title' => ['#type' => 'textfield']], $form);
  }

  /* -------------------------------------------------------------------------
   * The route — myapi_reservation_form_autocomplete().
   * ---------------------------------------------------------------------- */

  private function ask(...$arguments) {
    return myapi_test_capture(function () use ($arguments) {
      myapi_reservation_form_autocomplete(...$arguments);
    });
  }

  /**
   * @dataProvider requestsThatAnswerNothing
   */
  public function testARequestThatCannotBeScopedAnswersAnEmptyObject(array $arguments) {
    $result = $this->ask(...$arguments);

    $this->assertTrue($result['exited']);
    $this->assertSame(200, $result['status']);
    $this->assertSame('{}', $result['output']);
    $this->assertSame([], myapi_test_db_queries(), 'no query is run for it');
  }

  public static function requestsThatAnswerNothing() {
    return [
      'no arguments'           => [[]],
      'unknown selector'       => [['payment', '9', 'a']],
      'the root is not served' => [['condominium', '9', 'a']],
      'parent zero'            => [['unit', '0', 'a']],
      'parent not a number'    => [['unit', 'abc', 'a']],
      'no text'                => [['unit', '9']],
      'blank text'             => [['unit', '9', '  ']],
    ];
  }

  public function testUnitAndAreaAreAnsweredFromNodes() {
    foreach (['unit', 'area'] as $kind) {
      myapi_test_db_seed(['node' => [['nid' => 7, 'title' => 'Casa 7', 'type' => 'x', 'status' => 1]]]);
      $this->ask($kind, '9', 'cas');

      $this->assertCount(1, myapi_test_db_queries('node'), $kind);
      $this->assertSame([], myapi_test_db_queries('users'), $kind);
    }
  }

  public function testTheSuggestionsTravelAsValueToLabel() {
    myapi_test_db_seed(['node' => [['nid' => 7, 'title' => 'Casa 7', 'type' => 'vivienda', 'status' => 1]]]);

    $result = $this->ask('unit', '9', 'cas');

    $this->assertSame(['Casa 7 (7)' => '<div class="reference-autocomplete">Casa 7</div>'], $result['json']);
    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
  }

  /**
   * Drupal splits the path on '/', so a search for "A/B" arrives as two
   * arguments and has to be glued back.
   */
  public function testATextWithASlashIsGluedBack() {
    $this->ask('area', '9', 'A', 'B');

    $this->assertSame('%A/B%', $this->condition(myapi_test_db_queries('node')[0], 'n.title')['value']);
  }

  public function testRequesterIsAnsweredFromTheUnit() {
    $this->ask('requester', '30057', 'ana');

    $queries = myapi_test_db_queries('node');
    $this->assertCount(1, $queries);
    $this->assertSame(30057, $this->condition($queries[0], 'n.nid')['value']);
  }

  /* -------------------------------------------------------------------------
   * Units and areas — myapi_reservation_form_node_matches().
   * ---------------------------------------------------------------------- */

  /**
   * The recorded condition on one field of a query.
   */
  private function condition(array $query, $field) {
    foreach ($query['conditions'] as $condition) {
      if ($condition['field'] === $field) {
        return $condition;
      }
    }
    $this->fail('no condition on ' . $field);
  }

  /**
   * The two bundles name their condominium field differently, and the
   * condominium is asserted on the QUERY: the stub does not evaluate joins.
   *
   * @dataProvider nodeKinds
   */
  public function testTheQueryIsScopedToTheBundleAndTheCondominium($kind, $type, $field) {
    myapi_reservation_form_node_matches($kind, 16533, 'mot');
    $query = myapi_test_db_queries('node')[0];

    $this->assertSame($type, $this->condition($query, 'n.type')['value']);
    $this->assertSame(1, $this->condition($query, 'n.status')['value']);

    $this->assertCount(1, $query['joins']);
    $this->assertSame('INNER', $query['joins'][0]['type']);
    $this->assertSame('field_data_' . $field, $query['joins'][0]['table']);
    $this->assertStringContainsString('fc.entity_id = n.nid', $query['joins'][0]['condition']);
    $this->assertStringContainsString("fc.entity_type = 'node'", $query['joins'][0]['condition']);
    $this->assertStringContainsString('fc.deleted = 0', $query['joins'][0]['condition']);

    $scope = $this->condition($query, 'fc.' . $field . '_target_id');
    $this->assertSame(16533, $scope['value']);
    $this->assertSame('=', $scope['operator']);
  }

  public static function nodeKinds() {
    return [
      'unit' => ['unit', 'vivienda', 'field_condominio'],
      'area' => ['area', 'area', 'field_condominium'],
    ];
  }

  /**
   * GUARD: without the tag the page does not break — it silently offers a
   * building admin the units of every condominium of the site.
   */
  public function testTheQueryCarriesTheNodeAccessTag() {
    myapi_reservation_form_node_matches('unit', 16533, 'mot');

    $this->assertContains('node_access', myapi_test_db_queries('node')[0]['tags']);
  }

  public function testAtMostTenSuggestionsOrderedByTitle() {
    myapi_reservation_form_node_matches('unit', 16533, 'mot');
    $query = myapi_test_db_queries('node')[0];

    $this->assertSame(['start' => 0, 'length' => 10], $query['range']);
    $this->assertSame([['field' => 'n.title', 'direction' => 'ASC']], $query['order']);
  }

  public function testTheTextIsMatchedAsContainsAndItsWildcardsAreLiteral() {
    myapi_reservation_form_node_matches('unit', 16533, '100%_a');
    $title = $this->condition(myapi_test_db_queries('node')[0], 'n.title');

    $this->assertSame('LIKE', $title['operator']);
    $this->assertSame('%100\\%\\_a%', $title['value']);
  }

  public function testOnlyPublishedNodesOfTheBundleWhoseTitleMatchesComeBack() {
    myapi_test_db_seed(['node' => [
      ['nid' => 1, 'title' => 'Motora 1', 'type' => 'vivienda', 'status' => 1],
      ['nid' => 2, 'title' => 'Motora 2', 'type' => 'vivienda', 'status' => 0],
      ['nid' => 3, 'title' => 'Motora 3', 'type' => 'area', 'status' => 1],
      ['nid' => 4, 'title' => 'Otra', 'type' => 'vivienda', 'status' => 1],
    ]]);

    $this->assertSame(['Motora 1 (1)'], array_keys(myapi_reservation_form_node_matches('unit', 16533, 'moto')));
  }

  public function testTheLabelIsEscapedAndTheKeyIsNot() {
    myapi_test_db_seed(['node' => [
      ['nid' => 1, 'title' => 'Sala <script>x</script> & Co', 'type' => 'area', 'status' => 1],
    ]]);

    $matches = myapi_reservation_form_node_matches('area', 16533, 'sala');

    $this->assertSame(
      ['Sala x & Co (1)' => '<div class="reference-autocomplete">Sala &lt;script&gt;x&lt;/script&gt; &amp; Co</div>'],
      $matches
    );
  }

  /* -------------------------------------------------------------------------
   * People of a unit — myapi_reservation_form_requester_matches().
   * ---------------------------------------------------------------------- */

  /**
   * Unit 30057: owner 5 (ana), occupant 6 (luis), occupants 7 (eva, blocked)
   * and 8 (noe, no e-mail). User 9 lives nowhere.
   */
  private function seedUnit(array $override = []) {
    $member = function ($field, $uid) {
      return ['entity_id' => 30057, 'entity_type' => 'node', 'deleted' => 0, $field . '_target_id' => $uid];
    };

    myapi_test_db_seed($override + [
      'node' => [['nid' => 30057, 'title' => 'Motora 2', 'type' => 'vivienda', 'status' => 1]],
      'field_data_field_propietario' => [$member('field_propietario', 5)],
      'field_data_field_ocupante'    => [$member('field_ocupante', 6)],
      'field_data_field_ocupantes'   => [$member('field_ocupantes', 7), $member('field_ocupantes', 8)],
      'users' => [
        ['uid' => 5, 'name' => 'ana', 'mail' => 'ana@example.com', 'status' => 1],
        ['uid' => 6, 'name' => 'luis', 'mail' => 'luis@example.com', 'status' => 1],
        ['uid' => 7, 'name' => 'eva', 'mail' => 'eva@example.com', 'status' => 0],
        ['uid' => 8, 'name' => 'noe', 'mail' => '', 'status' => 1],
        ['uid' => 9, 'name' => 'ana-otra', 'mail' => 'otra@example.com', 'status' => 1],
      ],
    ]);
  }

  public function testOwnerAndOccupantsAreOfferedByName() {
    $this->seedUnit();

    $this->assertSame(['ana (5)'], array_keys(myapi_reservation_form_requester_matches(30057, 'ana')));
    $this->assertSame(['luis (6)'], array_keys(myapi_reservation_form_requester_matches(30057, 'lui')));
  }

  /**
   * GUARD: 'supervisor' and 'pagos' can ask this route and nothing narrows
   * the query for them. A text that only matches an e-mail finds nobody, and
   * the query never reads the column.
   */
  public function testNobodyIsFoundByEmailAndTheEmailIsNeverRead() {
    $this->seedUnit();

    $this->assertSame([], myapi_reservation_form_requester_matches(30057, '@example'));

    $query = myapi_test_db_queries('users')[0];
    $this->assertSame(['uid', 'name'], $query['fields']);
    $this->assertSame('LIKE', $this->condition($query, 'u.name')['operator']);
    foreach ($query['conditions'] as $condition) {
      $this->assertNotSame('u.mail', $condition['field']);
    }
  }

  /**
   * The decision of this spec, on the query side: user 9 matches the text and
   * is not a member of the unit.
   */
  public function testSomebodyWhoDoesNotLiveInTheUnitIsNeverOffered() {
    $this->seedUnit();

    $this->assertArrayNotHasKey('ana-otra (9)', myapi_reservation_form_requester_matches(30057, 'ana'));
    $this->assertSame([], myapi_reservation_form_requester_matches(30057, 'otra'));
  }

  public function testABlockedAccountIsNotOffered() {
    $this->seedUnit();

    $this->assertSame([], myapi_reservation_form_requester_matches(30057, 'eva'));
  }

  /**
   * The key is what entityreference renders when the form is reopened, and
   * the label says no more than the key does.
   */
  public function testTheSuggestionIsTheUserNameAndNothingElse() {
    $this->seedUnit();

    $this->assertSame(
      ['ana (5)' => '<div class="reference-autocomplete">ana</div>'],
      myapi_reservation_form_requester_matches(30057, 'ana')
    );
  }

  public function testTheLabelIsEscaped() {
    $this->seedUnit(['users' => [['uid' => 5, 'name' => 'a<b>', 'mail' => 'a&b@example.com', 'status' => 1]]]);

    $this->assertSame(
      ['a (5)' => '<div class="reference-autocomplete">a&lt;b&gt;</div>'],
      myapi_reservation_form_requester_matches(30057, 'a')
    );
  }

  /**
   * GUARD: the people of a unit are only as visible as the unit. The unit is
   * resolved through a 'node_access' query, and when it does not come back —
   * which is what a building admin gets for a unit of another condominium —
   * nobody is looked up at all.
   */
  public function testAUnitTheOperatorCannotSeeOffersNobody() {
    $this->seedUnit(['node' => []]);

    $this->assertSame([], myapi_reservation_form_requester_matches(30057, 'ana'));

    $unit = myapi_test_db_queries('node')[0];
    $this->assertContains('node_access', $unit['tags']);
    $this->assertSame('vivienda', $this->condition($unit, 'n.type')['value']);
    $this->assertSame([], myapi_test_db_queries('users'));
    $this->assertSame([], myapi_test_db_queries('field_data_field_propietario'));
  }

  public function testAUnitWithNobodyInItOffersNobody() {
    $this->seedUnit([
      'field_data_field_propietario' => [],
      'field_data_field_ocupante'    => [],
      'field_data_field_ocupantes'   => [],
    ]);

    $this->assertSame([], myapi_reservation_form_requester_matches(30057, 'ana'));
    $this->assertSame([], myapi_test_db_queries('users'), 'no IN () is ever sent');
  }

  public function testAtMostTenPeopleOrderedByName() {
    $this->seedUnit();
    myapi_reservation_form_requester_matches(30057, 'a');
    $query = myapi_test_db_queries('users')[0];

    $this->assertSame(['start' => 0, 'length' => 10], $query['range']);
    $this->assertSame([['field' => 'u.name', 'direction' => 'ASC']], $query['order']);
    $this->assertSame(1, $this->condition($query, 'u.status')['value']);
    $this->assertSame('IN', $this->condition($query, 'u.uid')['operator']);
  }

  /* -------------------------------------------------------------------------
   * The area's condominium — myapi_reservation_form_area_condominiums().
   * ---------------------------------------------------------------------- */

  public function testTheCondominiumOfAnAreaIsReadFromItsOwnFieldRows() {
    $row = function ($entity_id, $bundle, $deleted, $target) {
      return [
        'entity_id' => $entity_id, 'entity_type' => 'node', 'bundle' => $bundle,
        'deleted' => $deleted, 'field_condominium_target_id' => $target,
      ];
    };
    myapi_test_db_seed(['field_data_field_condominium' => [
      $row(40001, 'area', 0, 16533),
      $row(40001, 'area', 1, 111),
      $row(40001, 'reservation', 0, 222),
      $row(40002, 'area', 0, 333),
    ]]);

    $this->assertEquals([16533], myapi_reservation_form_area_condominiums(40001));
    $this->assertSame([], myapi_reservation_form_area_condominiums(49999));
  }

  /* -------------------------------------------------------------------------
   * The save — myapi_reservation_form_validate_scope().
   * ---------------------------------------------------------------------- */

  /**
   * Condominium 16533 has unit 30057 (owner 80614) and area 40001.
   * Condominium 999 has unit 30099 (owner 80700) and area 40099.
   */
  private function seedSite() {
    $unit = function ($nid, $condominium) {
      return ['entity_id' => $nid, 'entity_type' => 'node', 'deleted' => 0, 'field_condominio_target_id' => $condominium];
    };
    $area = function ($nid, $condominium) {
      return ['entity_id' => $nid, 'entity_type' => 'node', 'bundle' => 'area', 'deleted' => 0, 'field_condominium_target_id' => $condominium];
    };
    $owner = function ($nid, $uid) {
      return ['entity_id' => $nid, 'entity_type' => 'node', 'deleted' => 0, 'field_propietario_target_id' => $uid];
    };

    myapi_test_db_seed([
      'field_data_field_condominio'  => [$unit(30057, 16533), $unit(30099, 999)],
      'field_data_field_condominium' => [$area(40001, 16533), $area(40099, 999)],
      'field_data_field_propietario' => [$owner(30057, 80614), $owner(30099, 80700)],
    ]);
  }

  /**
   * A reservation as node_form_validate() hands it over, or as node_load()
   * answers it: the four references under one langcode.
   */
  private function reservation(array $override = [], $nid = NULL, $langcode = 'und') {
    $node = new stdClass();
    $node->type = 'reservation';
    if ($nid !== NULL) {
      $node->nid = $nid;
    }
    foreach ($this->references($override) as $field => $target) {
      $node->{$field} = $target === NULL ? [] : [$langcode => [['target_id' => $target]]];
    }

    return $node;
  }

  /**
   * Runs the validation and answers the errors it set, keyed as Drupal keys
   * them.
   */
  private function validate($node, ?array $form = NULL) {
    $form = $form === NULL ? $this->form() : $form;
    $form_state = [];
    $GLOBALS['myapi_test_form_errors'] = [];
    myapi_reservation_form_validate_scope($node, $form, $form_state);

    return $GLOBALS['myapi_test_form_errors'];
  }

  public function testACoherentNewReservationIsSaved() {
    $this->seedSite();

    $this->assertSame([], $this->validate($this->reservation()));
  }

  /**
   * The key is the full element path, which is what paints the input red.
   */
  public function testANewReservationWithAUnitOfAnotherCondominiumIsRefused() {
    $this->seedSite();

    $errors = $this->validate($this->reservation(['field_unit' => 30099, 'field_requester' => 80700]));

    $this->assertSame(
      ['field_unit][und][0][target_id' => 'La vivienda no pertenece al condominio seleccionado.'],
      $errors
    );
  }

  public function testEveryIncoherentReferenceOfANewReservationIsFlagged() {
    $this->seedSite();

    $errors = $this->validate($this->reservation(['field_unit' => 30099, 'field_area' => 40099]));

    $this->assertSame(
      ['field_unit][und][0][target_id', 'field_area][und][0][target_id', 'field_requester][und][0][target_id'],
      array_keys($errors)
    );
  }

  public function testTheKeyUsesTheLangcodeOfTheWidget() {
    $this->seedSite();
    $form = $this->form();
    $form['field_area']['#language'] = 'es';

    $errors = $this->validate($this->reservation(['field_area' => 40099]), $form);

    $this->assertSame(['field_area][es][0][target_id'], array_keys($errors));
  }

  public function testAFormWithoutALangcodeFallsBackToUnd() {
    $this->seedSite();

    $errors = $this->validate($this->reservation(['field_area' => 40099]), []);

    $this->assertSame(['field_area][und][0][target_id'], array_keys($errors));
  }

  /**
   * GUARD: the stored reservation is incoherent — its requester no longer
   * lives in the unit — and the operator edits it without touching the four
   * references, which is what cancelling does. It must save.
   */
  public function testAnEditThatTouchesNoReferenceIsNotValidated() {
    $this->seedSite();
    $stale = ['field_requester' => 80700];
    myapi_test_node_seed([500 => $this->reservation($stale, 500)]);

    $this->assertSame([], $this->validate($this->reservation($stale, 500)));
    $this->assertSame([], myapi_test_db_queries(), 'nothing is even looked up');
  }

  public function testAnEditThatChangesAReferenceIsValidated() {
    $this->seedSite();
    myapi_test_node_seed([500 => $this->reservation([], 500)]);

    $errors = $this->validate($this->reservation(['field_area' => 40099], 500));

    $this->assertSame(['field_area][und][0][target_id'], array_keys($errors));
  }

  public function testAnEditThatChangesAReferenceCoherentlyIsSaved() {
    $this->seedSite();
    myapi_test_node_seed([500 => $this->reservation(['field_unit' => 30099, 'field_requester' => 80700, 'field_area' => 40099, 'field_condominium' => 999], 500)]);

    $this->assertSame([], $this->validate($this->reservation([], 500)));
  }

  /**
   * A nid that no longer loads has nothing stored to compare with: every
   * reference counts as changed and the rule applies. Fail closed.
   */
  public function testAnEditWhoseStoredNodeDoesNotLoadIsValidated() {
    $this->seedSite();

    $errors = $this->validate($this->reservation(['field_unit' => 30099, 'field_requester' => 80700], 500));

    $this->assertSame(['field_unit][und][0][target_id'], array_keys($errors));
  }

  public function testEmptyReferencesAreLeftToTheRequiredValidation() {
    $this->seedSite();

    $node = $this->reservation(['field_unit' => NULL, 'field_area' => NULL, 'field_requester' => NULL]);

    $this->assertSame([], $this->validate($node));
  }

  /* -------------------------------------------------------------------------
   * Who may ask — myapi_reservation_form_access(), in myapi.module.
   * ---------------------------------------------------------------------- */

  /**
   * @dataProvider reservationPermissions
   */
  public function testAnyOneOfTheThreeReservationPermissionsOpensTheRoute($permission) {
    $GLOBALS['myapi_test_permissions'] = [$permission => TRUE];

    $this->assertTrue(myapi_reservation_form_access());
  }

  public static function reservationPermissions() {
    return [
      ['create reservation content'],
      ['edit any reservation content'],
      ['edit own reservation content'],
    ];
  }

  public function testWithoutAnyOfThemTheRouteIsClosed() {
    $GLOBALS['myapi_test_permissions'] = [
      'access content'             => TRUE,
      'create area content'        => TRUE,
      'view reservation calendar'  => TRUE,
    ];

    $this->assertFalse(myapi_reservation_form_access());
  }

}
