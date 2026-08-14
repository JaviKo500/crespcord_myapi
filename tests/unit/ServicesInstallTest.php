<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';

/**
 * Unit tests for the services marketplace catalogues and installer (SPEC 77,
 * extended by SPEC 81 with the hourly rate, the tags and the short
 * description).
 *
 * Two groups, and the second is the one that earns its keep:
 *
 *   - the pure catalogues and rules of includes/myapi.services_common.inc —
 *     the status lists, the transition graph, when closing needs a rating and
 *     when a provider is active;
 *   - GUARDS that read myapi.install as text. What they check is not a return
 *     value but a property of the installer no return value exposes: that the
 *     allowed_values of the status fields come from the catalogue instead of
 *     being retyped, that the seven fields this feature borrows from
 *     reservations and claims are never re-created, and — the one that would
 *     hurt most — that the destructive uninstall never deletes a borrowed
 *     field. field_delete_field('field_description') would take every reclamo
 *     down with it, and nothing in a code review makes that visible.
 *
 * Deliberately NOT tested here: _myapi_services_install() itself, the
 * vocabulary helper and the update hook. All three need a booted site with a
 * Field API, which is what tests/unit avoids across this repo; their
 * verification is the manual acceptance criteria of the spec.
 */
class ServicesInstallTest extends TestCase {

  /**
   * The fields SPEC 77 reuses instead of creating.
   *
   * Written out rather than read from the installer, for the usual reason: an
   * expectation derived from the code under test proves nothing. Five belong
   * to reservations (SPEC 32) and claims (SPEC 55/65) and live on
   * 'service_request'; the last two live on 'service_transaction'.
   */
  private const BORROWED_FIELDS = [
    'field_requester',
    'field_condominium',
    'field_description',
    'field_images',
    'field_attachment',
    'field_status_date',
    'field_comment',
  ];

  /**
   * The three fields SPEC 81 adds to 'provider'. All new names in the whole
   * module, which is why they are owned and not borrowed.
   */
  private const NEW_PROVIDER_FIELDS = [
    'field_hourly_rate',
    'field_tags',
    'field_short_description',
  ];

  /**
   * The install file as text, for the guards at the bottom.
   */
  private function installSource() {
    return file_get_contents(__DIR__ . '/../../myapi.install');
  }

  /**
   * One _myapi_reservations_ensure_field() call of the installer, as text with
   * its whitespace collapsed.
   *
   * Collapsing is what makes the expectations readable: the installer aligns
   * its '=>' by column, so a literal assertion would have to reproduce the
   * padding and would break the day a longer key widens the block.
   */
  private function fieldDefinition($field_name) {
    return $this->definitionAt(
      $this->functionSource('_myapi_services_install'),
      "_myapi_reservations_ensure_field('" . $field_name . "', [",
      $field_name . ' must be created by the installer'
    );
  }

  /**
   * One _myapi_reservations_ensure_instance() call on the 'provider' bundle.
   */
  private function providerInstanceDefinition($field_name) {
    return $this->definitionAt(
      $this->functionSource('_myapi_services_install'),
      "_myapi_reservations_ensure_instance('" . $field_name . "', \$provider_type, [",
      $field_name . ' must have an instance on the provider bundle'
    );
  }

  /**
   * From an opening call to its closing '];' — the array literal of one
   * definition and nothing of the next one.
   */
  private function definitionAt($source, $opening, $message) {
    $start = strpos($source, $opening);
    $this->assertNotFalse($start, $message);

    // ']);' closes the call itself; the nested settings arrays close with '],'.
    $end = strpos($source, ']);', $start);
    $this->assertNotFalse($end, $message . ' and must close');

    return preg_replace('/\s+/', ' ', substr($source, $start, $end - $start));
  }

  /**
   * The body of one function of myapi.install, as text.
   *
   * Relies on the coding standard the whole file follows: a function opens at
   * column 0 and closes with a '}' at column 0. Good enough to tell one
   * installer apart from another, which is all the guards need.
   */
  private function functionSource($name) {
    $source = $this->installSource();
    $start = strpos($source, "\nfunction " . $name . '(');
    $this->assertNotFalse($start, $name . '() must exist in myapi.install');

    $end = strpos($source, "\n}\n", $start);
    $this->assertNotFalse($end, $name . '() must close at column 0');

    return substr($source, $start, $end - $start);
  }

  /* -------------------------------------------------------------------------
   * The catalogues.
   * ---------------------------------------------------------------------- */

  public function testTheFiveBundlesAreCatalogued() {
    $this->assertSame(
      ['provider', 'service_request', 'service_offer', 'service_rating', 'service_transaction'],
      myapi_services_node_types()
    );
  }

  public function testRequestStatusCatalogue() {
    $this->assertSame(
      ['open', 'offered', 'assigned', 'closed', 'cancelled'],
      array_keys(myapi_services_request_statuses())
    );
  }

  public function testOfferStatusCatalogue() {
    $this->assertSame(
      ['sent', 'selected', 'rejected', 'withdrawn'],
      array_keys(myapi_services_offer_statuses())
    );
  }

  /**
   * The two status catalogues must not share a single value. They are separate
   * fields precisely because they are separate vocabularies of state, and an
   * overlap would be the first sign somebody started merging them.
   */
  public function testTheTwoStatusCataloguesDoNotOverlap() {
    $this->assertSame([], array_intersect(
      array_keys(myapi_services_request_statuses()),
      array_keys(myapi_services_offer_statuses())
    ));
  }

  /**
   * Keys travel in the API and are English snake_case; labels are Spanish and
   * only ever seen in the back office. Mixing the two up — a Spanish key —
   * would be a breaking change the day it reached the app.
   */
  public function testStatusKeysAreStableEnglishIdentifiers() {
    $keys = array_merge(
      array_keys(myapi_services_request_statuses()),
      array_keys(myapi_services_offer_statuses())
    );

    foreach ($keys as $key) {
      $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $key, $key . ' is not a stable snake_case key');
    }
  }

  public function testStarScaleIsOneToFive() {
    $this->assertSame([1, 2, 3, 4, 5], array_keys(myapi_services_star_values()));
  }

  /* -------------------------------------------------------------------------
   * The transition graph.
   * ---------------------------------------------------------------------- */

  /**
   * Every status of the catalogue has an entry, and every status named as a
   * destination is itself in the catalogue. A typo on either side would make a
   * transition silently unreachable rather than fail.
   */
  public function testTheGraphIsClosedOverTheCatalogue() {
    $statuses = array_keys(myapi_services_request_statuses());
    $transitions = myapi_services_request_transitions();

    $this->assertSame($statuses, array_keys($transitions));

    foreach ($transitions as $from => $targets) {
      foreach ($targets as $to) {
        $this->assertContains($to, $statuses, $from . ' → ' . $to . ' names a status that is not in the catalogue');
      }
    }
  }

  /**
   * @dataProvider allowedTransitions
   */
  public function testAllowedTransitions($from, $to) {
    $this->assertTrue(myapi_services_transition_allowed($from, $to), $from . ' → ' . $to . ' must be allowed');
  }

  public function allowedTransitions() {
    return [
      'first offer arrives'     => ['open', 'offered'],
      'resident awards'         => ['offered', 'assigned'],
      'closed with no award'    => ['offered', 'closed'],
      'closed after the job'    => ['assigned', 'closed'],
      'cancelled while open'    => ['open', 'cancelled'],
      'cancelled with offers'   => ['offered', 'cancelled'],
      'cancelled once assigned' => ['assigned', 'cancelled'],
    ];
  }

  /**
   * @dataProvider refusedTransitions
   */
  public function testRefusedTransitions($from, $to) {
    $this->assertFalse(myapi_services_transition_allowed($from, $to), $from . ' → ' . $to . ' must be refused');
  }

  public function refusedTransitions() {
    return [
      // Skipping a step: there is no award without an offer on the table.
      'open straight to assigned' => ['open', 'assigned'],
      'open straight to closed'   => ['open', 'closed'],
      // Going backwards.
      'assigned back to offered'  => ['assigned', 'offered'],
      'offered back to open'      => ['offered', 'open'],
      // Staying put is not a transition either.
      'open to open'              => ['open', 'open'],
      // An unknown status answers FALSE instead of throwing: a hand-written
      // value in the field must be refused, not crash the caller.
      'unknown origin'            => ['pendiente', 'closed'],
      'unknown destination'       => ['open', 'pendiente'],
    ];
  }

  /**
   * 'closed' and 'cancelled' are terminal, and nothing reopens a request.
   * Written as its own test because it is a product decision — reopening would
   * need rules for the offers and the chat of the closed round — and not an
   * accident of how the graph was typed.
   */
  public function testTerminalStatusesLeadNowhere() {
    $transitions = myapi_services_request_transitions();

    $this->assertSame([], $transitions['closed']);
    $this->assertSame([], $transitions['cancelled']);

    foreach (array_keys(myapi_services_request_statuses()) as $to) {
      $this->assertFalse(myapi_services_transition_allowed('closed', $to));
      $this->assertFalse(myapi_services_transition_allowed('cancelled', $to));
    }
  }

  /* -------------------------------------------------------------------------
   * Closing and the rating.
   * ---------------------------------------------------------------------- */

  /**
   * The rule the user set: the rating is demanded when the request reached
   * 'assigned', and only then. Closing from 'offered' is the "no award" path
   * of the contract and there is nobody to score.
   */
  public function testOnlyAnAssignedRequestDemandsARatingToClose() {
    $this->assertTrue(myapi_services_close_requires_rating('assigned'));

    foreach (['open', 'offered', 'closed', 'cancelled', 'pendiente'] as $status) {
      $this->assertFalse(
        myapi_services_close_requires_rating($status),
        'closing from ' . $status . ' must not demand a rating'
      );
    }
  }

  /* -------------------------------------------------------------------------
   * Active provider.
   * ---------------------------------------------------------------------- */

  /**
   * @dataProvider providerStates
   */
  public function testProviderIsActive($expected, $status, $expiry, $now, $message) {
    $this->assertSame($expected, myapi_services_provider_is_active($status, $expiry, $now), $message);
  }

  public function providerStates() {
    $now = 1800000000;

    return [
      'published, licence in the future' => [TRUE, 1, $now + 86400, $now, 'the ordinary active provider'],
      // The boundary. '>=' means the licence is good throughout its last
      // instant; '>' would suspend a provider one second early.
      'licence expires exactly now'      => [TRUE, 1, $now, $now, 'the licence is valid through its expiry instant'],
      'licence expired one second ago'   => [FALSE, 1, $now - 1, $now, 'an expired licence deactivates by itself'],
      'unpublished with a valid licence' => [FALSE, 0, $now + 86400, $now, 'unpublishing suspends a provider by hand'],
      'unpublished and expired'          => [FALSE, 0, $now - 1, $now, 'both halves failing is still inactive'],
      // The field is required, so an empty expiry means a node saved outside
      // the form or a field removed by hand. "No licence on record" reads as
      // inactive, never as active.
      'no licence on record'             => [FALSE, 1, NULL, $now, 'a missing expiry must not pass as active'],
      'empty string expiry'              => [FALSE, 1, '', $now, 'an empty expiry must not pass as active'],
      'non numeric expiry'               => [FALSE, 1, 'nunca', $now, 'a non numeric expiry must not pass as active'],
    ];
  }

  /* -------------------------------------------------------------------------
   * Guards over myapi.install.
   * ---------------------------------------------------------------------- */

  /**
   * The allowed_values of the three list fields must come from the catalogue
   * functions, not from a list retyped in the installer. Two copies of the
   * same catalogue drift the first time one of them gains a status, and the
   * symptom — a status the API accepts that the field refuses to store — would
   * point everywhere except here.
   */
  public function testStatusFieldsTakeTheirValuesFromTheCatalogue() {
    $installer = $this->functionSource('_myapi_services_install');

    $this->assertStringContainsString(
      "'allowed_values' => myapi_services_request_statuses()",
      $installer,
      'field_request_status must read its allowed_values from the catalogue'
    );
    $this->assertStringContainsString(
      "'allowed_values' => myapi_services_offer_statuses()",
      $installer,
      'field_offer_status must read its allowed_values from the catalogue'
    );
    $this->assertStringContainsString(
      "'allowed_values' => myapi_services_star_values()",
      $installer,
      'field_stars must read its allowed_values from the catalogue'
    );
  }

  /**
   * The five bundles must actually be created by the installer. A bundle in
   * the catalogue that nothing creates would only surface as an empty
   * autocomplete on the field that references it.
   */
  public function testEveryCataloguedBundleIsCreated() {
    $installer = $this->functionSource('_myapi_services_install');

    foreach (myapi_services_node_types() as $type) {
      $this->assertMatchesRegularExpression(
        '/_myapi_reservations_ensure_node_type\(\s*\$[a-z_]+_type,/',
        $installer,
        'the installer must create its bundles through the idempotent helper'
      );
    }

    // Five creations, one per bundle.
    $this->assertSame(
      count(myapi_services_node_types()),
      substr_count($installer, '_myapi_reservations_ensure_node_type('),
      'there must be exactly one ensure_node_type() call per catalogued bundle'
    );
  }

  /**
   * The seven borrowed fields must never be re-created. field_create_field()
   * on an existing name is a no-op through the _ensure_ helper, so the damage
   * would not be an error but a lie: a second definition in the installer,
   * with settings that look authoritative and are never applied — the exact
   * shape of the SPEC 65 hole, where the fields said 'public' in one place and
   * 'private' in another.
   */
  public function testBorrowedFieldsAreNeverReCreated() {
    $installer = $this->functionSource('_myapi_services_install');

    foreach (self::BORROWED_FIELDS as $field_name) {
      $this->assertStringNotContainsString(
        "_myapi_reservations_ensure_field('" . $field_name . "'",
        $installer,
        $field_name . ' belongs to reservations/claims; SPEC 77 may only add an instance of it'
      );
    }
  }

  /**
   * The guard that would hurt most if it ever failed: the destructive
   * uninstall must not name a single borrowed field in its list of fields to
   * delete. field_delete_field('field_description') takes the description of
   * every reclamo with it, and field_delete_field('field_condominium') takes
   * every area and reservation — from a teardown whose author only meant to
   * remove the marketplace.
   */
  public function testTheDestructiveUninstallNeverDeletesABorrowedField() {
    $teardown = $this->functionSource('_myapi_services_uninstall_destructive');

    // The $owned list is the one that reaches field_delete_field(); the
    // borrowed ones appear further down, under field_delete_instance().
    $owned_start = strpos($teardown, '$owned = [');
    $this->assertNotFalse($owned_start, 'the teardown must keep its owned fields in an $owned list');
    $owned = substr($teardown, $owned_start, strpos($teardown, '];', $owned_start) - $owned_start);

    foreach (self::BORROWED_FIELDS as $field_name) {
      $this->assertStringNotContainsString(
        "'" . $field_name . "'",
        $owned,
        $field_name . ' is owned by another feature and must never be deleted by this teardown'
      );
    }

    // And the borrowed ones must lose their instance instead — otherwise the
    // teardown leaves instances pointing at deleted bundles.
    $this->assertStringContainsString('field_delete_instance(', $teardown);
  }

  /**
   * The teardown is opt-in and stays opt-in. A constant flipped to TRUE in a
   * commit would turn `drush pm-uninstall myapi` into a data loss.
   */
  public function testTheDestructiveUninstallIsOptIn() {
    $this->assertStringContainsString(
      "define('MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL', FALSE)",
      $this->installSource()
    );
  }

  /**
   * The installer must run on fresh sites AND on already-installed ones. A
   * spec that only wires hook_install() leaves production without the bundles
   * and the omission has no symptom until somebody looks for the content type.
   */
  public function testTheInstallerIsWiredToBothEntryPoints() {
    $source = $this->installSource();

    $this->assertStringContainsString('_myapi_services_install();', $this->functionSource('myapi_install'));
    $this->assertStringContainsString('_myapi_services_install();', $this->functionSource('myapi_update_7025'));
    // And the teardown hangs off hook_uninstall behind its constant.
    $this->assertStringContainsString('_myapi_services_uninstall_destructive();', $this->functionSource('myapi_uninstall'));
    $this->assertStringContainsString('MYAPI_SERVICES_DESTRUCTIVE_UNINSTALL', $source);
  }

  /* -------------------------------------------------------------------------
   * SPEC 81 — the hourly rate, the tags and the short description.
   * ---------------------------------------------------------------------- */

  /**
   * The tag vocabulary has a constant of its own and it is NOT the category
   * one. The two are opposites — free and codeless against closed and carrying
   * field_category_code — and pointing a field at the wrong one would fill the
   * app's category grid with '24h' and 'garantía'.
   */
  public function testTheTagVocabularyIsCatalogued() {
    $this->assertTrue(defined('MYAPI_SERVICES_TAG_VOCABULARY'));
    $this->assertSame('provider_tag', MYAPI_SERVICES_TAG_VOCABULARY);
    $this->assertNotSame(MYAPI_SERVICES_CATEGORY_VOCABULARY, MYAPI_SERVICES_TAG_VOCABULARY);
  }

  /**
   * The installer creates the tag vocabulary through the same idempotent
   * helper as the category one — which is what lets myapi_update_7028() re-run
   * the whole installer without duplicating anything.
   */
  public function testTheInstallerCreatesTheTagVocabulary() {
    $installer = preg_replace('/\s+/', ' ', $this->functionSource('_myapi_services_install'));

    $this->assertStringContainsString(
      '_myapi_services_ensure_vocabulary( MYAPI_SERVICES_TAG_VOCABULARY,',
      $installer,
      'the tag vocabulary must be created through the idempotent helper, by constant'
    );
    $this->assertSame(
      2,
      substr_count($installer, '_myapi_services_ensure_vocabulary('),
      'this feature creates exactly two vocabularies: the categories and the tags'
    );
  }

  /**
   * The rate is money: decimal, and the same 10,2 as field_offer_amount. A
   * float would store 25.10 as something that is not 25.10, and the 3,2 of
   * field_rating_avg — dimensioned for a 1-5 average — would cap the rate at
   * 9.99.
   */
  public function testTheHourlyRateIsADecimalTheSizeOfAnOfferAmount() {
    $field = $this->fieldDefinition('field_hourly_rate');

    $this->assertStringContainsString("'type' => 'number_decimal'", $field);
    $this->assertStringContainsString("'cardinality' => 1", $field);
    $this->assertStringContainsString("'settings' => ['precision' => 10, 'scale' => 2]", $field);
  }

  /**
   * The tags field must point at provider_tag and never at service_category:
   * the two allowed_values differ in exactly one word, and getting it wrong
   * would turn the free autocomplete into a second way of writing categories.
   */
  public function testTheTagsFieldPointsAtTheTagVocabulary() {
    $field = $this->fieldDefinition('field_tags');

    $this->assertStringContainsString("'type' => 'taxonomy_term_reference'", $field);
    $this->assertStringContainsString("'cardinality' => FIELD_CARDINALITY_UNLIMITED", $field);
    $this->assertStringContainsString("'settings' => \$tag_settings", $field);

    $installer = preg_replace('/\s+/', ' ', $this->functionSource('_myapi_services_install'));
    $start = strpos($installer, '$tag_settings = [');
    $this->assertNotFalse($start, 'the installer must define $tag_settings');
    $settings = substr($installer, $start, strpos($installer, '];', $start) - $start);

    $this->assertStringContainsString("'vocabulary' => MYAPI_SERVICES_TAG_VOCABULARY", $settings);
    $this->assertStringNotContainsString('MYAPI_SERVICES_CATEGORY_VOCABULARY', $settings);
  }

  /**
   * One line of 255, not a text_long. The limit is what keeps this field from
   * becoming a second field_services_desc with nobody knowing which one goes
   * on the marketplace card.
   */
  public function testTheShortDescriptionIsOneLineOf255() {
    $field = $this->fieldDefinition('field_short_description');

    $this->assertStringContainsString("'type' => 'text'", $field);
    $this->assertStringNotContainsString("'type' => 'text_long'", $field);
    $this->assertStringContainsString("'cardinality' => 1", $field);
    $this->assertStringContainsString("'settings' => ['max_length' => 255]", $field);
  }

  /**
   * The decision the whole spec rests on: the three instances are OPTIONAL. A
   * required field added to a bundle with data leaves every provider already
   * loaded on the site invalid the moment somebody opens it to edit it.
   *
   * @dataProvider newProviderInstances
   */
  public function testTheThreeNewInstancesAreOptionalAndHangOffProvider($field_name, $widget) {
    $instance = $this->providerInstanceDefinition($field_name);

    $this->assertStringContainsString("'bundle' => \$provider_type", $instance);
    $this->assertStringContainsString("'required' => 0", $instance, $field_name . ' must stay optional');
    $this->assertStringContainsString("'widget' => ['type' => '" . $widget . "']", $instance);
  }

  public function newProviderInstances() {
    return [
      // A number widget, so min/prefix are honoured by the form.
      'hourly rate'       => ['field_hourly_rate', 'number'],
      // An autocomplete, not options_buttons: the operator must be able to
      // create a term by typing it, without loading the vocabulary first.
      'tags'              => ['field_tags', 'taxonomy_autocomplete'],
      // A textfield, not a textarea and with no format selector.
      'short description' => ['field_short_description', 'text_textfield'],
    ];
  }

  /**
   * min = 0 rejects a typo'd negative rate in the back-office form, and the
   * prefix paints the currency without touching the stored value. Both are
   * instance settings by decision: a hook_node_validate() would be module code
   * for what configuration already does.
   */
  public function testTheHourlyRateInstanceRefusesNegativesAndShowsACurrency() {
    $instance = $this->providerInstanceDefinition('field_hourly_rate');

    $this->assertStringContainsString("'min' => 0", $instance);
    $this->assertStringContainsString("'prefix' => '$ '", $instance);
  }

  /**
   * The three names are new in the whole module, so the destructive teardown
   * deletes the fields outright — the caution the seven borrowed ones demand
   * does not apply, and leaving them out would strand three fields on a bundle
   * that no longer exists.
   */
  public function testTheThreeNewFieldsAreOwnedByThisFeature() {
    $teardown = $this->functionSource('_myapi_services_uninstall_destructive');
    $owned_start = strpos($teardown, '$owned = [');
    $owned = substr($teardown, $owned_start, strpos($teardown, '];', $owned_start) - $owned_start);

    foreach (self::NEW_PROVIDER_FIELDS as $field_name) {
      $this->assertStringContainsString(
        "'" . $field_name . "'",
        $owned,
        $field_name . ' is created by this feature and must be deleted by its teardown'
      );
    }
  }

  /**
   * Both vocabularies are created by this feature, so both go in the
   * destructive teardown. Deleting only the categories would leave provider_tag
   * behind with its terms and no field pointing at it.
   */
  public function testTheDestructiveUninstallDeletesBothVocabularies() {
    $teardown = $this->functionSource('_myapi_services_uninstall_destructive');

    $this->assertStringContainsString('MYAPI_SERVICES_CATEGORY_VOCABULARY', $teardown);
    $this->assertStringContainsString('MYAPI_SERVICES_TAG_VOCABULARY', $teardown);
    $this->assertSame(
      2,
      substr_count($teardown, 'taxonomy_vocabulary_delete('),
      'the teardown must delete the two vocabularies this feature creates'
    );
  }

  /**
   * The update hook re-runs the whole installer, like myapi_update_7025(). It
   * is the one place where a surgical alternative would restate the three field
   * definitions in a second spot of the same file.
   */
  public function testTheUpdateHookReRunsTheInstaller() {
    $this->assertStringContainsString(
      '_myapi_services_install();',
      $this->functionSource('myapi_update_7028')
    );
  }

  /**
   * The non-regression net of this spec. The installer is a long file of
   * definitions in a row, so an ensure_field() pasted three lines off its right
   * place would read fine in review and silently change a field of SPEC 77.
   * This walks the eight provider fields of SPEC 77 that are still there —
   * field_photo is the ninth and SPEC 82 deleted it — and pins their type,
   * cardinality, settings, requiredness and widget.
   *
   * @dataProvider spec77ProviderFields
   */
  public function testTheEightRemainingProviderFieldsOfSpec77AreUnchanged($field_name, array $field_expectations, array $instance_expectations) {
    $field = $this->fieldDefinition($field_name);
    foreach ($field_expectations as $expectation) {
      $this->assertStringContainsString($expectation, $field, $field_name . ' changed at field level');
    }

    $instance = $this->providerInstanceDefinition($field_name);
    foreach ($instance_expectations as $expectation) {
      $this->assertStringContainsString($expectation, $instance, $field_name . ' changed at instance level');
    }
  }

  public function spec77ProviderFields() {
    return [
      'associated users'  => [
        'field_provider_users',
        ["'type' => 'entityreference'", "'cardinality' => FIELD_CARDINALITY_UNLIMITED"],
        ["'required' => 1", "'widget' => ['type' => 'entityreference_autocomplete']"],
      ],
      'phone'             => [
        'field_phone',
        ["'type' => 'text'", "'cardinality' => 1", "'settings' => ['max_length' => 20]"],
        ["'required' => 1", "'widget' => ['type' => 'text_textfield']"],
      ],
      'address'           => [
        'field_address',
        ["'type' => 'text_long'", "'cardinality' => 1"],
        ["'required' => 0", "'widget' => ['type' => 'text_textarea']"],
      ],
      // The long description stays REQUIRED and stays a textarea: the short one
      // is a companion, not a replacement.
      'services desc'     => [
        'field_services_desc',
        ["'type' => 'text_long'", "'cardinality' => 1"],
        ["'required' => 1", "'widget' => ['type' => 'text_textarea']"],
      ],
      'licence expiry'    => [
        'field_license_expiry',
        ["'type' => 'datestamp'", "'cardinality' => 1", "'settings' => \$timestamp_settings"],
        ["'required' => 1", "'widget' => \$date_widget"],
      ],
      // Still checkboxes over the CATEGORY vocabulary — the tags field must not
      // have taken its settings by accident.
      'categories'        => [
        'field_categories',
        ["'type' => 'taxonomy_term_reference'", "'cardinality' => FIELD_CARDINALITY_UNLIMITED", "'settings' => \$category_settings"],
        ["'required' => 1", "'widget' => ['type' => 'options_buttons']"],
      ],
      // 3,2 and not the 10,2 of the new rate: this one holds a 1-5 average.
      'rating average'    => [
        'field_rating_avg',
        ["'type' => 'number_decimal'", "'cardinality' => 1", "'settings' => ['precision' => 3, 'scale' => 2]"],
        ["'required' => 0", "'widget' => ['type' => 'number']"],
      ],
      'rating count'      => [
        'field_rating_count',
        ["'type' => 'number_integer'", "'cardinality' => 1"],
        ["'required' => 0", "'widget' => ['type' => 'number']"],
      ],
    ];
  }

  /* -------------------------------------------------------------------------
   * SPEC 82 — the private gallery, and the death of field_photo.
   * ---------------------------------------------------------------------- */

  /**
   * The decision the whole spec rests on, and the one that cannot be corrected
   * later without moving files: uri_scheme = 'private' is a FIELD setting, so
   * it is decided the moment the field is created. A field born public would
   * need field_update_field() AND a file_move() of every image, which is
   * exactly the work myapi_update_7023() had to do in SPEC 65.
   *
   * The cardinality is pinned here for the same reason: it belongs to the
   * field, and lowering it later silently discards the extra deltas.
   */
  public function testTheGalleryIsAPrivateImageFieldCappedAtTen() {
    $field = $this->fieldDefinition('field_gallery');

    $this->assertStringContainsString("'type' => 'image'", $field);
    $this->assertStringContainsString("'cardinality' => 10", $field);
    $this->assertStringContainsString("'settings' => ['uri_scheme' => 'private']", $field);
    $this->assertStringNotContainsString("'uri_scheme' => 'public'", $field);
  }

  /**
   * The instance: optional, on the provider bundle, with the image widget and
   * the same extensions and size cap the deleted field_photo had.
   */
  public function testTheGalleryInstanceIsOptionalAndCapsTheFileSize() {
    $instance = $this->providerInstanceDefinition('field_gallery');

    $this->assertStringContainsString("'bundle' => \$provider_type", $instance);
    $this->assertStringContainsString("'required' => 0", $instance);
    $this->assertStringContainsString("'widget' => ['type' => 'image_image']", $instance);
    $this->assertStringContainsString("'file_extensions' => 'png jpg jpeg'", $instance);
    $this->assertStringContainsString("'max_filesize' => '3 MB'", $instance);
  }

  /**
   * field_photo is gone from the INSTALLER, both as a field and as an
   * instance. A fresh site must never create it: the update below deletes it
   * on the existing ones, and an installer that still created it would put it
   * back on every new environment.
   */
  public function testTheInstallerNoLongerCreatesTheOldPhotoField() {
    $installer = $this->functionSource('_myapi_services_install');

    $this->assertStringNotContainsString("_myapi_reservations_ensure_field('field_photo'", $installer);
    $this->assertStringNotContainsString("_myapi_reservations_ensure_instance('field_photo'", $installer);
  }

  /**
   * And it is gone from the destructive teardown's $owned list too — deleting
   * a field that no longer exists is not an error, but leaving the name there
   * would say this feature still owns something it does not.
   */
  public function testTheTeardownNoLongerNamesTheOldPhotoField() {
    $teardown = $this->functionSource('_myapi_services_uninstall_destructive');
    $owned_start = strpos($teardown, '$owned = [');
    $owned = substr($teardown, $owned_start, strpos($teardown, '];', $owned_start) - $owned_start);

    $this->assertStringNotContainsString("'field_photo'", $owned);
    $this->assertStringContainsString(
      "'field_gallery'",
      $owned,
      'the gallery is created by this feature and must be deleted by its teardown'
    );
  }

  /**
   * The update creates before it destroys, and it destroys behind a guard.
   *
   * The order is not cosmetic: running the installer FIRST means the site is
   * never left with the old field already gone and the new one not yet there.
   * And field_delete_field() alone only marks the field as deleted —
   * field_purge_batch() is what actually drops field_data_field_photo, which
   * is what the acceptance criteria check on the site.
   */
  public function testTheUpdateCreatesTheGalleryAndThenDeletesThePhoto() {
    $update = $this->functionSource('myapi_update_7029');

    $this->assertStringContainsString('_myapi_services_install();', $update);
    $this->assertStringContainsString("field_info_field('field_photo')", $update);
    $this->assertStringContainsString("field_delete_field('field_photo');", $update);
    $this->assertStringContainsString('field_purge_batch(', $update);

    $this->assertLessThan(
      strpos($update, "field_delete_field('field_photo')"),
      strpos($update, '_myapi_services_install();'),
      'the installer must run before the deletion, never after'
    );
    $this->assertLessThan(
      strpos($update, 'field_purge_batch('),
      strpos($update, "field_delete_field('field_photo')"),
      'the purge must follow the deletion'
    );
  }

  /**
   * The deletion is UNCONDITIONAL by express decision of the user: it does not
   * count the rows of field_data_field_photo and it does not abort when it
   * finds any. This pins that the guard is an existence check and nothing more
   * — if somebody ever adds a count, this test is where they will have to come
   * and say so out loud.
   */
  public function testTheDeletionOfThePhotoFieldIsUnconditional() {
    $update = $this->functionSource('myapi_update_7029');

    $this->assertStringNotContainsString('field_data_field_photo', $update);
    $this->assertStringNotContainsString('countQuery', $update);
  }

  /**
   * myapi_update_7028 and everything before it stay untouched: a spec that
   * renumbers an existing update leaves every already-updated site skipping it
   * or running it twice.
   */
  public function testTheUpdateNumberingOfPreviousSpecsIsUntouched() {
    $source = $this->installSource();

    $this->assertStringContainsString('function myapi_update_7028()', $source);
    $this->assertStringContainsString('function myapi_update_7029()', $source);
    $this->assertStringContainsString('function myapi_update_7030()', $source);
    $this->assertStringNotContainsString('function myapi_update_7031()', $source);
    // 7028 is still SPEC 81's, not this spec's.
    $this->assertStringContainsString(
      '_myapi_services_install();',
      $this->functionSource('myapi_update_7028')
    );
    $this->assertStringNotContainsString(
      'field_delete_field(',
      $this->functionSource('myapi_update_7028')
    );
  }

  /* -------------------------------------------------------------------------
   * SPEC 84 — the borrowed field_unit instance on 'service_rating'.
   * ---------------------------------------------------------------------- */

  /**
   * field_unit is NOT created here — it already exists, owned by reservations
   * (SPEC 32) — only a new, OPTIONAL instance is added on 'service_rating'.
   * No 'settings' on the instance: target_bundles is a FIELD setting, already
   * fixed by the field itself and shared with the 'reservation' instance.
   */
  public function testFieldUnitGetsAnOptionalInstanceOnServiceRating() {
    $instance = $this->definitionAt(
      $this->functionSource('_myapi_services_install'),
      "_myapi_reservations_ensure_instance('field_unit', \$rating_type, [",
      'field_unit must have an instance on the service_rating bundle'
    );

    $this->assertStringContainsString("'required' => 0", $instance);
    $this->assertStringContainsString("'widget' => ['type' => 'entityreference_autocomplete']", $instance);
    $this->assertStringNotContainsString("'settings'", $instance);
  }

  /**
   * The field itself is not re-created: _myapi_services_install() must call
   * _myapi_reservations_ensure_field('field_unit', ...) exactly zero times —
   * the ensure_field() calls of this function are for fields IT owns, and
   * field_unit belongs to reservations.
   */
  public function testFieldUnitIsNeverReCreatedByTheServicesInstaller() {
    $installer = $this->functionSource('_myapi_services_install');

    $this->assertStringNotContainsString("_myapi_reservations_ensure_field('field_unit'", $installer);
  }

  /**
   * Same criterion as field_condominium/field_requester on service_request:
   * a borrowed field never enters $owned, and the teardown is not touched at
   * all by this spec — the instance is lost with the bundle when
   * node_type_delete() runs, the field survives because it was never listed.
   */
  public function testFieldUnitStaysOutOfDestructiveUninstall() {
    $teardown = $this->functionSource('_myapi_services_uninstall_destructive');

    $this->assertStringNotContainsString('field_unit', $teardown);
  }

  /**
   * myapi_update_7030() re-runs the whole installer, exactly like
   * myapi_update_7028(): every creation goes through the idempotent
   * _ensure_* sub-helpers, so it is safe to call twice.
   */
  public function testTheUpdate7030ReRunsTheInstaller() {
    $this->assertStringContainsString(
      '_myapi_services_install();',
      $this->functionSource('myapi_update_7030')
    );
  }
}
