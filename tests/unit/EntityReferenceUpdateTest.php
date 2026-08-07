<?php

use PHPUnit\Framework\TestCase;

// MYAPI_BUILDING_ADMIN_CONDO_FIELD and MYAPI_BUILDING_ADMIN_CLAIM_TYPE are
// defined here and are both catalogue values, so this include comes first.
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../myapi.install';

/**
 * Unit tests for myapi_update_7016(), the SPEC 53 repair (covered by SPEC 75).
 *
 * EntityReferenceFieldSettingsTest covers the catalogue and the pure decision
 * behind this update — what it would write, given a field. What it explicitly
 * left out was the update itself, and with it three acceptance criteria of
 * SPEC 53: that a second run writes nothing (4), that no field_data_* or
 * field_revision_* row is touched (5), and that a site missing one of the
 * fields runs it without error (6).
 *
 * Those three are not decoration. This is the only place in the module that
 * writes over an EXISTING field definition — its own docblock says so — it
 * runs once per site during a `drush updb`, and a field it gets wrong is
 * shared by two bundles. "It writes nothing the second time" is also the kind
 * of claim that is true right up until someone moves a line out of the
 * `if ($settings === NULL) { continue; }` guard.
 *
 * The Field API here is the fixture map in bootstrap.php: field_read_field()
 * answers what a test seeded, and field_update_field() records the write AND
 * applies it, so a second pass reads what the first one left. Applying it is
 * what makes the idempotence case real rather than an assertion against a map
 * that never changed.
 *
 * The fifth criterion is the one that needs the odd machinery: "touches no
 * field_data_* row" is a claim about something that does NOT happen, and is
 * unfalsifiable unless the forbidden call is observable. db_insert(),
 * db_update(), db_delete(), db_merge() and db_query() are all stubbed to
 * record themselves and throw, so assertNoDatabaseWrites() below is a real
 * check and not a comment.
 */
class EntityReferenceUpdateTest extends TestCase {

  /**
   * The six fields the catalogue covers, with the bundles each must end up
   * restricted to.
   *
   * Written out rather than read from _myapi_entityreference_field_settings():
   * a test that takes its expectations from the code under test proves only
   * that the code equals itself. field_requester is the deliberate NULL — the
   * user entity has a single bundle, so it must never gain a target_bundles
   * key at all.
   */
  private const EXPECTED_BUNDLES = [
    'field_condominium'      => 'condominio',
    'field_condominio_admin' => 'condominio',
    'field_unit'             => 'vivienda',
    'field_area'             => 'area',
    'field_claim'            => 'reclamo',
    'field_requester'        => NULL,
  ];

  /**
   * The entity type each field points at. field_requester is the only one that
   * targets users, and the only one the update must never try to repair the
   * bundles of.
   */
  private const TARGET_TYPES = [
    'field_condominium'      => 'node',
    'field_condominio_admin' => 'node',
    'field_unit'             => 'node',
    'field_area'             => 'node',
    'field_claim'            => 'node',
    'field_requester'        => 'user',
  ];

  protected function setUp(): void {
    $GLOBALS['myapi_test_fields'] = [];
    $GLOBALS['myapi_test_field_writes'] = [];
    $GLOBALS['myapi_test_field_cache_clears'] = 0;
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_db_writes'] = [];
  }

  protected function tearDown(): void {
    $GLOBALS['myapi_test_fields'] = [];
    $GLOBALS['myapi_test_field_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_db_writes'] = [];
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A stored field row, in the shape field_read_field() answers.
   *
   * The extra keys (id, cardinality, module) are not decoration: the update
   * writes the WHOLE row back, and one of the assertions below is that it
   * hands field_update_field() the row it read with only 'settings' changed.
   */
  private function fieldRow($name, array $settings) {
    return [
      'id'          => (string) (array_search($name, array_keys(self::TARGET_TYPES), TRUE) + 1),
      'field_name'  => $name,
      'type'        => 'entityreference',
      'module'      => 'entityreference',
      'cardinality' => $name === 'field_condominio_admin' ? '-1' : '1',
      'settings'    => $settings,
    ];
  }

  /**
   * The state SPEC 53 found in production: every field exists, every one
   * carries its target_type, and not one carries a handler or handler_settings.
   */
  private function seedBrokenSite(array $only = []) {
    $names = $only ? $only : array_keys(self::TARGET_TYPES);

    foreach ($names as $name) {
      $GLOBALS['myapi_test_fields'][$name] = $this->fieldRow($name, [
        'target_type' => self::TARGET_TYPES[$name],
      ]);
    }
  }

  /**
   * The state the update is supposed to leave behind: run it once on a broken
   * site and forget the bookkeeping.
   */
  private function seedRepairedSite() {
    $this->seedBrokenSite();
    myapi_update_7016();
    $GLOBALS['myapi_test_field_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_field_cache_clears'] = 0;
  }

  private function writtenFieldNames() {
    return array_column($GLOBALS['myapi_test_field_writes'], 'field_name');
  }

  private function storedSettings($name) {
    return $GLOBALS['myapi_test_fields'][$name]['settings'];
  }

  private function storedBundles($name) {
    $settings = $this->storedSettings($name);

    return isset($settings['handler_settings']['target_bundles'])
      ? $settings['handler_settings']['target_bundles']
      : NULL;
  }

  /**
   * SPEC 53, criterion 5. Asserted after every run in this class.
   */
  private function assertNoDatabaseWrites() {
    $this->assertSame([], $GLOBALS['myapi_test_db_writes'], 'the update wrote to the database');
  }

  /* -------------------------------------------------------------------------
   * The repair itself.
   * ---------------------------------------------------------------------- */

  /**
   * On the production site SPEC 53 describes, all six fields are repaired and
   * all six are named in the summary drush prints.
   */
  public function testRepairsEveryFieldThatCarriesNoSelectionSettings() {
    $this->seedBrokenSite();

    $summary = myapi_update_7016();

    $this->assertSame(array_keys(self::TARGET_TYPES), $this->writtenFieldNames());
    foreach (array_keys(self::TARGET_TYPES) as $name) {
      $this->assertStringContainsString($name, $summary);
    }
    $this->assertNoDatabaseWrites();
  }

  /**
   * Each repaired field ends up restricted to the bundle of the catalogue —
   * the whole point of the spec, asserted on what is STORED after the update
   * rather than on what the catalogue says.
   */
  public function testEachRepairedFieldEndsUpRestrictedToItsOwnBundle() {
    $this->seedBrokenSite();

    myapi_update_7016();

    foreach (self::EXPECTED_BUNDLES as $name => $bundle) {
      if ($bundle === NULL) {
        continue;
      }
      $this->assertSame([$bundle => $bundle], $this->storedBundles($name), $name);
    }
  }

  /**
   * field_requester never gains a target_bundles key.
   *
   * The user entity has one bundle, so a restriction there would be
   * meaningless — and an empty target_bundles is treated by entityreference
   * exactly like an absent one, which is why the absence has to be asserted
   * rather than assumed harmless.
   */
  public function testTheUserFieldNeverGainsABundleRestriction() {
    $this->seedBrokenSite();

    myapi_update_7016();

    $this->assertNull($this->storedBundles('field_requester'));
    $this->assertSame('user', $this->storedSettings('field_requester')['target_type']);
  }

  /**
   * Every repaired field gets the generic 'base' handler, which is the handler
   * that reads target_bundles at all.
   */
  public function testEveryRepairedFieldGetsTheGenericHandler() {
    $this->seedBrokenSite();

    myapi_update_7016();

    foreach (array_keys(self::TARGET_TYPES) as $name) {
      $this->assertSame('base', $this->storedSettings($name)['handler'], $name);
    }
  }

  /**
   * Only the 'settings' key of the stored row changes; the rest of the field
   * definition is handed back to field_update_field() as it was read.
   *
   * A repair that dropped 'cardinality' on the way through would silently turn
   * the unlimited field_condominio_admin into a single-value field, and no
   * assertion about bundles would notice.
   */
  public function testOnlyTheSettingsKeyOfTheStoredRowChanges() {
    $this->seedBrokenSite();
    $before = $GLOBALS['myapi_test_fields'];

    myapi_update_7016();

    foreach ($GLOBALS['myapi_test_field_writes'] as $written) {
      $name = $written['field_name'];
      unset($written['settings']);
      $original = $before[$name];
      unset($original['settings']);

      $this->assertSame($original, $written, $name . ': something other than settings changed');
    }
  }

  /**
   * target_type is never rewritten, even while the settings around it are.
   *
   * It is the one setting whose change would orphan every stored target_id,
   * and it was the one that was always in the right place to begin with.
   */
  public function testTargetTypeSurvivesTheRepairUntouched() {
    $this->seedBrokenSite();

    myapi_update_7016();

    foreach (self::TARGET_TYPES as $name => $type) {
      $this->assertSame($type, $this->storedSettings($name)['target_type'], $name);
    }
  }

  /* -------------------------------------------------------------------------
   * Criterion 4: idempotence.
   * ---------------------------------------------------------------------- */

  /**
   * SPEC 53, criterion 4: the second pass writes nothing and says so.
   */
  public function testTheSecondPassWritesNothingAndReportsIt() {
    $this->seedRepairedSite();

    $summary = myapi_update_7016();

    $this->assertSame([], $GLOBALS['myapi_test_field_writes']);
    $this->assertStringContainsString('nothing to repair', $summary);
    $this->assertNoDatabaseWrites();
  }

  /**
   * A third and fourth pass are just as quiet: idempotence is a property of
   * the state, not of the number of runs.
   */
  public function testFurtherPassesStayQuiet() {
    $this->seedRepairedSite();

    myapi_update_7016();
    myapi_update_7016();
    myapi_update_7016();

    $this->assertSame([], $GLOBALS['myapi_test_field_writes']);
  }

  /**
   * The stored settings after two passes are identical to those after one.
   */
  public function testASecondPassLeavesTheStoredSettingsIdentical() {
    $this->seedBrokenSite();
    myapi_update_7016();
    $after_first = $GLOBALS['myapi_test_fields'];

    myapi_update_7016();

    $this->assertSame($after_first, $GLOBALS['myapi_test_fields']);
  }

  /**
   * A site where only SOME fields are missing their settings gets only those
   * written — the partially-migrated case, which is what a site that ran an
   * earlier repair by hand looks like.
   */
  public function testAPartiallyRepairedSiteOnlyWritesWhatIsMissing() {
    $this->seedRepairedSite();
    // Two fields lose their settings again, as if restored from an old backup.
    $GLOBALS['myapi_test_fields']['field_unit']['settings'] = ['target_type' => 'node'];
    $GLOBALS['myapi_test_fields']['field_area']['settings'] = ['target_type' => 'node'];

    $summary = myapi_update_7016();

    $this->assertSame(['field_unit', 'field_area'], $this->writtenFieldNames());
    $this->assertStringContainsString('field_unit', $summary);
    $this->assertStringNotContainsString('field_requester', $summary);
  }

  /* -------------------------------------------------------------------------
   * Criterion 6: a site that does not have every field.
   * ---------------------------------------------------------------------- */

  /**
   * SPEC 53, criterion 6: a missing field is named in the summary and does not
   * stop the run — the fields that ARE there still get repaired.
   */
  public function testAMissingFieldIsNamedAndTheRestAreStillRepaired() {
    $this->seedBrokenSite(['field_condominium', 'field_unit', 'field_area']);

    $summary = myapi_update_7016();

    $this->assertSame(['field_condominium', 'field_unit', 'field_area'], $this->writtenFieldNames());
    $this->assertStringContainsString('Not present on this site', $summary);
    $this->assertStringContainsString('field_requester', $summary);
    $this->assertStringContainsString('field_claim', $summary);
    $this->assertNoDatabaseWrites();
  }

  /**
   * A site with none of the six — a fresh install where nothing was created
   * yet — runs clean, writes nothing and still names all six.
   */
  public function testASiteWithNoneOfTheFieldsRunsClean() {
    $summary = myapi_update_7016();

    $this->assertSame([], $GLOBALS['myapi_test_field_writes']);
    $this->assertStringContainsString('Not present on this site', $summary);
    foreach (array_keys(self::TARGET_TYPES) as $name) {
      $this->assertStringContainsString($name, $summary);
    }
    $this->assertNoDatabaseWrites();
  }

  /* -------------------------------------------------------------------------
   * A target_type that does not match: report, do not repair.
   * ---------------------------------------------------------------------- */

  /**
   * A field pointing at the wrong entity type is skipped, left untouched, and
   * named in the summary as skipped rather than as repaired.
   *
   * Repairing it would be the one destructive thing this update could do:
   * writing node-bundle settings onto a field that stores user ids.
   */
  public function testATargetTypeMismatchIsSkippedAndNotWritten() {
    $this->seedBrokenSite();
    $GLOBALS['myapi_test_fields']['field_unit']['settings'] = ['target_type' => 'user'];

    $summary = myapi_update_7016();

    $this->assertNotContains('field_unit', $this->writtenFieldNames());
    $this->assertSame(['target_type' => 'user'], $this->storedSettings('field_unit'));
    $this->assertStringContainsString('Skipped for a target_type mismatch', $summary);
    $this->assertNoDatabaseWrites();
  }

  /**
   * The mismatch is logged as a warning naming the field and both types, which
   * is the only trace an operator running `drush updb` gets.
   */
  public function testATargetTypeMismatchIsLoggedAsAWarning() {
    $this->seedBrokenSite();
    $GLOBALS['myapi_test_fields']['field_unit']['settings'] = ['target_type' => 'taxonomy_term'];

    myapi_update_7016();

    $warnings = array_values(array_filter($GLOBALS['myapi_test_watchdog'], function ($entry) {
      return $entry['severity'] === WATCHDOG_WARNING;
    }));

    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('field_unit', $warnings[0]['text']);
    $this->assertStringContainsString('taxonomy_term', $warnings[0]['text']);
    $this->assertStringContainsString('node', $warnings[0]['text']);
  }

  /**
   * One mismatched field does not stop the other five from being repaired.
   */
  public function testAMismatchDoesNotStopTheOtherFields() {
    $this->seedBrokenSite();
    $GLOBALS['myapi_test_fields']['field_area']['settings'] = ['target_type' => 'user'];

    myapi_update_7016();

    $written = $this->writtenFieldNames();
    $this->assertNotContains('field_area', $written);
    $this->assertCount(5, $written);
  }

  /* -------------------------------------------------------------------------
   * Rellenar, no imponer: what an administrator set by hand survives.
   * ---------------------------------------------------------------------- */

  /**
   * A target_bundles set by hand — deliberate or not — is not overwritten.
   *
   * The declared price of the conservative rule: a wrong hand-set value
   * survives, and so does a right one.
   */
  public function testBundlesSetByHandSurviveTheUpdate() {
    $this->seedBrokenSite();
    $GLOBALS['myapi_test_fields']['field_area']['settings'] = [
      'target_type'      => 'node',
      'handler'          => 'base',
      'handler_settings' => ['target_bundles' => ['sala' => 'sala']],
    ];

    myapi_update_7016();

    $this->assertSame(['sala' => 'sala'], $this->storedBundles('field_area'));
  }

  /**
   * A handler switched to 'views' by hand survives too: the field is still
   * restricted, by the view instead of by the bundle list.
   */
  public function testAHandlerSetByHandSurvivesTheUpdate() {
    $this->seedBrokenSite();
    $GLOBALS['myapi_test_fields']['field_unit']['settings'] = [
      'target_type' => 'node',
      'handler'     => 'views',
    ];

    myapi_update_7016();

    $this->assertSame('views', $this->storedSettings('field_unit')['handler']);
  }

  /**
   * Settings written by other modules are carried through, not discarded.
   */
  public function testUnknownSettingsOfOtherModulesSurvive() {
    $this->seedBrokenSite();
    $GLOBALS['myapi_test_fields']['field_unit']['settings'] = [
      'target_type'      => 'node',
      'some_other_module' => ['keep' => 'me'],
    ];

    myapi_update_7016();

    $this->assertSame(['keep' => 'me'], $this->storedSettings('field_unit')['some_other_module']);
    $this->assertSame(['vivienda' => 'vivienda'], $this->storedBundles('field_unit'));
  }

  /**
   * An empty target_bundles counts as missing and gets filled.
   *
   * entityreference tests it with !empty(), so an empty list restricts nothing
   * — it is the bug, not a deliberate setting.
   */
  public function testAnEmptyBundleListIsTreatedAsMissingAndFilled() {
    $this->seedBrokenSite();
    $GLOBALS['myapi_test_fields']['field_unit']['settings'] = [
      'target_type'      => 'node',
      'handler'          => 'base',
      'handler_settings' => ['target_bundles' => []],
    ];

    myapi_update_7016();

    $this->assertSame(['vivienda' => 'vivienda'], $this->storedBundles('field_unit'));
  }

  /* -------------------------------------------------------------------------
   * The cache, and the log.
   * ---------------------------------------------------------------------- */

  /**
   * The field info cache is cleared after a repair — without it the
   * autocompletes keep serving the old definition and the update looks like it
   * did nothing.
   */
  public function testTheFieldInfoCacheIsClearedAfterARepair() {
    $this->seedBrokenSite();

    myapi_update_7016();

    $this->assertGreaterThanOrEqual(1, $GLOBALS['myapi_test_field_cache_clears']);
  }

  /**
   * And it is cleared even on a run that repaired nothing, which is the case
   * its own comment calls out: a run that wrote nothing must still leave a
   * warm, correct cache.
   */
  public function testTheFieldInfoCacheIsClearedEvenWhenNothingWasRepaired() {
    $this->seedRepairedSite();

    myapi_update_7016();

    $this->assertSame(1, $GLOBALS['myapi_test_field_cache_clears']);
  }

  /**
   * The repair is logged once, as a notice naming every field it wrote.
   */
  public function testTheRepairIsLoggedAsANoticeNamingTheFields() {
    $this->seedBrokenSite();

    myapi_update_7016();

    $notices = array_values(array_filter($GLOBALS['myapi_test_watchdog'], function ($entry) {
      return $entry['severity'] === WATCHDOG_NOTICE;
    }));

    $this->assertCount(1, $notices);
    foreach (array_keys(self::TARGET_TYPES) as $name) {
      $this->assertStringContainsString($name, $notices[0]['text']);
    }
  }

  /**
   * A run that repaired nothing logs nothing: `drush updb` on an already
   * correct site must not add noise to watchdog.
   */
  public function testAQuietRunLogsNothing() {
    $this->seedRepairedSite();

    myapi_update_7016();

    $this->assertSame([], $GLOBALS['myapi_test_watchdog']);
  }

}
