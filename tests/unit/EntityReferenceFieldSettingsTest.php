<?php

use PHPUnit\Framework\TestCase;

// The constant MYAPI_BUILDING_ADMIN_CONDO_FIELD is defined here and is one of
// the catalogue keys, so this include comes first.
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../myapi.install';

/**
 * Unit tests for the entityreference selection settings of the installer
 * (SPEC 53).
 *
 * The bug this file exists to keep buried: 'handler' and
 * 'handler_settings.target_bundles' were written on the field INSTANCES, while
 * the entityreference selection handler reads them off the FIELD:
 *
 *   // EntityReference_SelectionHandler_Generic::buildEntityFieldQuery()
 *   if (!empty($this->field['settings']['handler_settings']['target_bundles'])) {
 *     $query->entityCondition('bundle', ..., 'IN');
 *   }
 *
 * With the settings in the wrong place that condition never ran, so EVERY
 * autocomplete of the module offered EVERY node of the site: typing in
 * field_condominium of the area form returned viviendas, boletines and recibos.
 * It is a placement bug, invisible in review — both arrays look right — which
 * is exactly the kind that needs a test rather than a careful reader.
 *
 * Three groups here, and the last two matter most:
 *
 *   - the catalogue's content: which bundles each field may reference;
 *   - _myapi_entityreference_repair_settings(), the pure decision behind
 *     myapi_update_7016(): it fills in what is missing and never overwrites
 *     what an administrator set by hand;
 *   - two GUARDS that read myapi.install as text — one fails if an
 *     entityreference field is ever created outside the catalogue, the other
 *     fails if 'handler_settings' ever reappears on an instance. Both are the
 *     bug coming back, and neither is detectable from the return values.
 *
 * Not tested here: myapi_update_7016() itself. It lives in
 * EntityReferenceUpdateTest (SPEC 75), which runs it against the fixture Field
 * API in bootstrap.php — field_read_field() answers a seeded map and
 * field_update_field() records and applies the write. That is where the spec's
 * criteria 4, 5 and 6 (idempotence, no field_data_* writes, a site missing a
 * field) are checked; what stays manual is only the last mile, the five
 * autocompletes in the node forms.
 */
class EntityReferenceFieldSettingsTest extends TestCase {

  /**
   * The six entityreference fields this module creates.
   *
   * Written out rather than derived from the catalogue: a test that reads its
   * expectations from the code under test proves nothing.
   */
  private const FIELDS = [
    'field_condominium',
    'field_condominio_admin',
    'field_unit',
    'field_area',
    'field_requester',
    'field_claim',
  ];

  /**
   * The install file as text, for the two guards at the bottom.
   */
  private function installSource() {
    return file_get_contents(__DIR__ . '/../../myapi.install');
  }

  /* -------------------------------------------------------------------------
   * The catalogue.
   * ---------------------------------------------------------------------- */

  public function testCatalogueCoversExactlyTheSixReferenceFields() {
    $catalogue = _myapi_entityreference_field_settings();

    $names = array_keys($catalogue);
    sort($names);
    $expected = self::FIELDS;
    sort($expected);

    $this->assertSame($expected, $names);
  }

  /**
   * @dataProvider bundleRestrictions
   */
  public function testEachFieldRestrictsItsReferenceableBundles($field_name, array $expected_bundles) {
    $settings = _myapi_entityreference_field_settings()[$field_name];

    $this->assertSame(
      $expected_bundles,
      $settings['handler_settings']['target_bundles'],
      $field_name . ' must only offer ' . implode(', ', $expected_bundles)
    );
  }

  public function bundleRestrictions() {
    return [
      'area + reservation condominium' => ['field_condominium', ['condominio' => 'condominio']],
      'people condominium'             => ['field_condominio_admin', ['condominio' => 'condominio']],
      'reservation unit'               => ['field_unit', ['vivienda' => 'vivienda']],
      'reservation area'               => ['field_area', ['area' => 'area']],
      'transaction claim'              => ['field_claim', [MYAPI_BUILDING_ADMIN_CLAIM_TYPE => MYAPI_BUILDING_ADMIN_CLAIM_TYPE]],
    ];
  }

  /**
   * field_requester targets 'user', whose entity type has a single bundle, so
   * the ABSENCE of target_bundles is the correct configuration and not the bug
   * the rest of this file is about. What narrows that autocomplete to the
   * residents of the assigned condominiums is the SPEC 51 'user_access' query
   * alter.
   */
  public function testRequesterTargetsUsersAndRestrictsNoBundle() {
    $settings = _myapi_entityreference_field_settings()['field_requester'];

    $this->assertSame('user', $settings['target_type']);
    $this->assertArrayNotHasKey('target_bundles', $settings['handler_settings']);
  }

  /**
   * @dataProvider nodeFields
   */
  public function testNodeFieldsTargetNodes($field_name) {
    $this->assertSame('node', _myapi_entityreference_field_settings()[$field_name]['target_type']);
  }

  public function nodeFields() {
    return [
      ['field_condominium'],
      ['field_condominio_admin'],
      ['field_unit'],
      ['field_area'],
      ['field_claim'],
    ];
  }

  /**
   * 'base' is the machine name of the generic selection handler. Anything else
   * would be a handler this module does not ship — a view, typically — and the
   * autocompletes would silently fall back to offering everything.
   */
  public function testEveryFieldUsesTheGenericHandler() {
    foreach (_myapi_entityreference_field_settings() as $field_name => $settings) {
      $this->assertSame('base', $settings['handler'], $field_name . ' must use the generic handler');
    }
  }

  /**
   * field_condominium is attached to both 'area' and 'reservation'. Selection
   * settings are field-level in D7, so the two bundles necessarily share one
   * entry — this pins that they agree on 'condominio' and that nobody split
   * the entry in two believing it could differ per bundle.
   */
  public function testTheSharedCondominiumFieldHasOneEntry() {
    $catalogue = _myapi_entityreference_field_settings();

    $this->assertSame(
      $catalogue['field_condominium'],
      $catalogue['field_condominio_admin']
    );
  }

  /* -------------------------------------------------------------------------
   * _myapi_entityreference_repair_settings() — the update's decision.
   * ---------------------------------------------------------------------- */

  /**
   * The production case: a field created before SPEC 53, carrying target_type
   * and nothing else.
   */
  public function testFillsInTheSettingsOfAFieldThatHasNone() {
    $wanted = _myapi_entityreference_field_settings()['field_condominium'];

    $repaired = _myapi_entityreference_repair_settings(['target_type' => 'node'], $wanted);

    $this->assertSame('base', $repaired['handler']);
    $this->assertSame(['condominio' => 'condominio'], $repaired['handler_settings']['target_bundles']);
    $this->assertSame('node', $repaired['target_type'], 'target_type must survive untouched');
  }

  public function testWritesNothingWhenTheSettingsAreAlreadyThere() {
    $wanted = _myapi_entityreference_field_settings()['field_unit'];

    $this->assertNull(_myapi_entityreference_repair_settings($wanted, $wanted));
  }

  /**
   * The conservative rule of this installer: an administrator who widened or
   * narrowed a field by hand in production keeps their choice. Only an ABSENT
   * value is filled in.
   */
  public function testNeverOverwritesBundlesSetByHand() {
    $wanted = _myapi_entityreference_field_settings()['field_condominium'];
    $current = [
      'target_type'      => 'node',
      'handler'          => 'base',
      'handler_settings' => [
        'target_bundles' => ['vivienda' => 'vivienda'],
      ],
    ];

    $repaired = _myapi_entityreference_repair_settings($current, $wanted);

    $this->assertSame(['vivienda' => 'vivienda'], $repaired['handler_settings']['target_bundles']);
    // The missing half is still completed.
    $this->assertSame(['type' => 'none'], $repaired['handler_settings']['sort']);
  }

  /**
   * A handler somebody switched to a view is left alone: replacing it would
   * take away a deliberate configuration, and the field would still be
   * restricted — by the view.
   */
  public function testNeverOverwritesAHandlerSetByHand() {
    $wanted = _myapi_entityreference_field_settings()['field_area'];
    $current = [
      'target_type'      => 'node',
      'handler'          => 'views',
      'handler_settings' => ['view' => ['view_name' => 'areas']],
    ];

    $repaired = _myapi_entityreference_repair_settings($current, $wanted);

    $this->assertSame('views', $repaired['handler']);
    $this->assertSame(['view_name' => 'areas'], $repaired['handler_settings']['view']);
  }

  /**
   * An empty target_bundles is the broken state, not a choice: entityreference
   * treats it exactly like an absent one (its check is !empty()), so it gets
   * filled in.
   */
  public function testAnEmptyBundleListCountsAsMissing() {
    $wanted = _myapi_entityreference_field_settings()['field_condominium'];
    $current = [
      'target_type'      => 'node',
      'handler'          => 'base',
      'handler_settings' => ['target_bundles' => []],
    ];

    $repaired = _myapi_entityreference_repair_settings($current, $wanted);

    $this->assertSame(['condominio' => 'condominio'], $repaired['handler_settings']['target_bundles']);
  }

  /**
   * Settings this module knows nothing about survive the repair: field_info
   * carries keys written by other modules, and the update must not drop them.
   */
  public function testUnknownSettingsSurvive() {
    $wanted = _myapi_entityreference_field_settings()['field_unit'];
    $current = [
      'target_type' => 'node',
      'profile2_private' => TRUE,
    ];

    $repaired = _myapi_entityreference_repair_settings($current, $wanted);

    $this->assertTrue($repaired['profile2_private']);
  }

  /**
   * A field whose catalogue entry restricts nothing must not gain an empty
   * 'handler_settings' key just because the update ran — that would be a write
   * with no effect, and myapi_update_7016() reports every field it writes.
   */
  public function testAddsNoEmptyHandlerSettings() {
    $current = ['target_type' => 'user', 'handler' => 'base'];
    $wanted = ['target_type' => 'user', 'handler' => 'base', 'handler_settings' => []];

    $this->assertNull(_myapi_entityreference_repair_settings($current, $wanted));
  }

  /* -------------------------------------------------------------------------
   * Guards. These read myapi.install as text: what they check is WHERE the
   * settings are written, which no return value exposes.
   * ---------------------------------------------------------------------- */

  /**
   * Every entityreference field created by the installer must take its settings
   * from the catalogue. A new one hard-coding `'settings' => ['target_type' =>
   * 'node']` is the SPEC 53 bug reappearing on a sixth field.
   */
  public function testEveryEntityreferenceFieldComesFromTheCatalogue() {
    $catalogue = _myapi_entityreference_field_settings();
    $found = [];

    foreach ($this->blocks('_myapi_reservations_ensure_field') as $block) {
      if (strpos($block, "'entityreference'") === FALSE) {
        continue;
      }

      $field_name = $this->firstArgument($block);
      $this->assertNotNull($field_name, 'Could not read the field name of: ' . $block);
      $this->assertArrayHasKey(
        $field_name,
        $catalogue,
        $field_name . ' is an entityreference field and must be listed in _myapi_entityreference_field_settings()'
      );
      $this->assertStringContainsString(
        '$reference_settings[',
        $block,
        $field_name . ' must take its settings from the catalogue, not inline'
      );

      $found[] = $field_name;
    }

    sort($found);
    $expected = self::FIELDS;
    sort($expected);
    $this->assertSame($expected, $found, 'The installer must create exactly the catalogued reference fields');
  }

  /**
   * The guard that names the bug: not one field instance may carry selection
   * settings. entityreference never reads them there, so an instance that has
   * them is either dead weight or — as it was until SPEC 53 — a restriction
   * that looks configured and does nothing.
   */
  public function testNoInstanceCarriesSelectionSettings() {
    foreach ($this->blocks('_myapi_reservations_ensure_instance') as $block) {
      $this->assertStringNotContainsString(
        'handler_settings',
        $block,
        "Selection settings are field-level; this instance carries them:\n" . $block
      );
      $this->assertStringNotContainsString(
        "'handler'",
        $block,
        "Selection settings are field-level; this instance carries them:\n" . $block
      );
    }
  }

  /**
   * Splits the install file into the argument list of every call to $function.
   *
   * Each block runs from the opening parenthesis to the first line that closes
   * the array at the call's own indentation — `  ]);` or `  ], 'user');` — which
   * is the shape every call in that file has.
   *
   * Two kinds of match are dropped, and both appear in the file: the function's
   * own `function _myapi_..._ensure_field($field_name, ...)` definition, and the
   * `_myapi_..._ensure_field()` mentions inside docblocks. Neither starts with a
   * field name, which is what tells them apart from a call.
   *
   * @return array
   *   One string per call, without the function name.
   */
  private function blocks($function) {
    $chunks = explode($function . '(', $this->installSource());
    // Whatever precedes the first match is not a call.
    array_shift($chunks);

    $blocks = [];
    foreach ($chunks as $chunk) {
      $end = strpos($chunk, "\n  ]");
      $block = $end === FALSE ? $chunk : substr($chunk, 0, $end);

      if ($this->firstArgument($block) !== NULL) {
        $blocks[] = $block;
      }
    }

    return $blocks;
  }

  /**
   * Reads the first argument of a call block: a quoted field name, or the
   * constant the building-admin field is passed as.
   *
   * @return string|null
   *   The field name, or NULL when the block does not start with one.
   */
  private function firstArgument($block) {
    if (preg_match("/^\s*'([^']+)'/", $block, $matches)) {
      return $matches[1];
    }
    if (preg_match('/^\s*([A-Z][A-Z0-9_]+)/', $block, $matches) && defined($matches[1])) {
      return constant($matches[1]);
    }

    return NULL;
  }

}
