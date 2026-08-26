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
   * The ten fields SPEC 100 adds to 'service_offer'. All new names in the whole
   * module, which is why they are owned and not borrowed. Listed in the order
   * of the spec's table so a diff against it reads straight down.
   */
  private const NEW_OFFER_FIELDS = [
    'field_offer_amount_type',
    'field_offer_valid_until',
    'field_offer_available_from',
    'field_offer_duration',
    'field_offer_duration_unit',
    'field_offer_includes',
    'field_offer_excludes',
    'field_offer_tax_included',
    'field_offer_warranty_days',
    'field_offer_requires_visit',
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
   * One _myapi_reservations_ensure_instance() call on the 'service_offer'
   * bundle (SPEC 100).
   */
  private function offerInstanceDefinition($field_name) {
    return $this->definitionAt(
      $this->functionSource('_myapi_services_install'),
      "_myapi_reservations_ensure_instance('" . $field_name . "', \$offer_type, [",
      $field_name . ' must have an instance on the service_offer bundle'
    );
  }

  /**
   * The '$owned = [...]' list of the destructive teardown, as text.
   */
  private function ownedFieldList() {
    $teardown = $this->functionSource('_myapi_services_uninstall_destructive');
    $start = strpos($teardown, '$owned = [');

    return substr($teardown, $start, strpos($teardown, '];', $start) - $start);
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
      ['open', 'direct', 'offered', 'assigned', 'closed', 'cancelled'],
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
   * The two catalogues of the offer's quote (SPEC 100).
   * ---------------------------------------------------------------------- */

  public function testOfferAmountTypeCatalogue() {
    $this->assertSame(
      ['fixed', 'estimate', 'hourly', 'on_site_quote'],
      array_keys(myapi_services_offer_amount_types())
    );
  }

  public function testOfferDurationUnitCatalogue() {
    $this->assertSame(
      ['hours', 'days'],
      array_keys(myapi_services_offer_duration_units())
    );
  }

  /**
   * Same rule the status catalogues follow: English snake_case keys, which
   * travel in the API, and Spanish labels, which never leave the back office.
   */
  public function testTheOfferQuoteCatalogueKeysAreStableEnglishIdentifiers() {
    $keys = array_merge(
      array_keys(myapi_services_offer_amount_types()),
      array_keys(myapi_services_offer_duration_units())
    );

    foreach ($keys as $key) {
      $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $key, $key . ' is not a stable snake_case key');
    }
  }

  /**
   * Every label is a non-empty string: the installer feeds these straight into
   * allowed_values, and an empty one would render a blank option in the back
   * office form with no clue as to which value it writes.
   */
  public function testTheOfferQuoteCataloguesCarryLabels() {
    $catalogues = array_merge(
      myapi_services_offer_amount_types(),
      myapi_services_offer_duration_units()
    );

    foreach ($catalogues as $key => $label) {
      $this->assertIsString($label, $key . ' must carry a string label');
      $this->assertNotSame('', trim($label), $key . ' must carry a non-empty label');
    }
  }

  /**
   * 'on_site_quote' is the one amount type that carries NO amount, and the
   * whole conditional half of the body validation hangs on that key existing
   * under exactly this name. Naming it here is what makes renaming it a failing
   * test instead of a silent 422 on every offer.
   */
  public function testTheAmountlessTypeIsCatalogued() {
    $this->assertArrayHasKey('on_site_quote', myapi_services_offer_amount_types());
  }

  /**
   * The two live offer statuses, as constants. They are the same values
   * myapi_services_offer_statuses() catalogues — this asserts the pair cannot
   * drift, which is the point of naming them at all.
   */
  public function testTheLiveOfferStatusConstantsMatchTheCatalogue() {
    $this->assertSame('sent', MYAPI_SERVICES_OFFER_STATUS_SENT);
    $this->assertSame('selected', MYAPI_SERVICES_OFFER_STATUS_SELECTED);

    $catalogue = array_keys(myapi_services_offer_statuses());
    $this->assertContains(MYAPI_SERVICES_OFFER_STATUS_SENT, $catalogue);
    $this->assertContains(MYAPI_SERVICES_OFFER_STATUS_SELECTED, $catalogue);
  }

  /**
   * The live-offer sweep asks the constants for the two live statuses instead of
   * retyping them. A literal creeping back in here is the drift SPEC 100
   * removed, and it would only show up the day one of the keys changed — which
   * is exactly when nobody is looking at this query.
   *
   * IT LIVES IN THE OFFERS' INCLUDE SINCE SPEC 106, which needed the same sweep
   * for the award and renamed it myapi_service_offer_reject_live() on the way
   * out of resources/service_request.resource.inc. The guard follows the
   * function; what it asserts about it has not changed.
   */
  public function testTheCancellationSweepReadsTheLiveStatusConstants() {
    $source = file_get_contents(__DIR__ . '/../../includes/myapi.service_offer.inc');
    $start = strpos($source, "\nfunction myapi_service_offer_reject_live(");
    $this->assertNotFalse($start, 'myapi_service_offer_reject_live() must exist');

    $end = strpos($source, "\n}\n", $start);
    $body = substr($source, $start, $end - $start);

    $this->assertStringContainsString('MYAPI_SERVICES_OFFER_STATUS_SENT', $body);
    $this->assertStringContainsString('MYAPI_SERVICES_OFFER_STATUS_SELECTED', $body);
    $this->assertStringNotContainsString("'sent', 'selected'", $body);
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
      // SPEC 87. The two, and only two, ways out of 'direct'.
      'direct job closed'       => ['direct', 'closed'],
      'direct job cancelled'    => ['direct', 'cancelled'],
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
      // SPEC 87: 'direct' is a ROOT. Nothing leads to it — a request is born
      // with a provider chosen or it goes through the round, never both — and
      // it does not fall back into the round either.
      'open into direct'          => ['open', 'direct'],
      'offered into direct'       => ['offered', 'direct'],
      'assigned into direct'      => ['assigned', 'direct'],
      'closed into direct'        => ['closed', 'direct'],
      'cancelled into direct'     => ['cancelled', 'direct'],
      'direct back to open'       => ['direct', 'open'],
      'direct into the round'     => ['direct', 'offered'],
      'direct to direct'          => ['direct', 'direct'],
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
   * The rule the user set: the rating is demanded exactly when there is a
   * provider who did the job — 'assigned' (SPEC 77) and 'direct' (SPEC 87).
   * Closing from 'offered' is the "no award" path of the contract and there is
   * nobody to score.
   */
  public function testOnlyAnAssignedOrDirectRequestDemandsARatingToClose() {
    $this->assertTrue(myapi_services_close_requires_rating('assigned'));
    $this->assertTrue(myapi_services_close_requires_rating('direct'));

    foreach (['open', 'offered', 'closed', 'cancelled', 'pendiente'] as $status) {
      $this->assertFalse(
        myapi_services_close_requires_rating($status),
        'closing from ' . $status . ' must not demand a rating'
      );
    }
  }

  /**
   * The consequence of the rule above on the data model, pinned where the rule
   * is: a 'direct' close demands a rating and a direct job has no offer, so the
   * offer of a rating CANNOT be required. If somebody ever makes
   * field_rating_offer required again, this pair of assertions is the one that
   * explains why the two decisions cannot both stand.
   */
  public function testADirectCloseNeedsARatingThatHasNoOfferToPointAt() {
    $this->assertTrue(myapi_services_close_requires_rating('direct'));

    $instance = $this->definitionAt(
      $this->functionSource('_myapi_services_install'),
      "_myapi_reservations_ensure_instance('field_rating_offer', \$rating_type, [",
      'the service_rating instance of field_rating_offer must exist'
    );

    $this->assertStringContainsString("'required' => 0", $instance);
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
    $this->assertStringContainsString('function myapi_update_7031()', $source);
    $this->assertStringContainsString('function myapi_update_7032()', $source);
    $this->assertStringContainsString('function myapi_update_7033()', $source);
    // SPEC 91 appended 7034 (the file_managed.uri repair) and SPEC 100 appended
    // 7035 (the ten quote fields); the ceiling moves with each of them, so the
    // guard keeps saying "nothing beyond the last spec".
    $this->assertStringContainsString('function myapi_update_7034()', $source);
    $this->assertStringContainsString('function myapi_update_7035()', $source);
    $this->assertStringNotContainsString('function myapi_update_7036()', $source);
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

  /* -------------------------------------------------------------------------
   * SPEC 85 — the public logo.
   * ---------------------------------------------------------------------- */

  /**
   * The decision of this spec that cannot be corrected later without moving
   * files: uri_scheme = 'public' is a FIELD setting, decided the moment the
   * field is created. Same reasoning as the gallery above and the opposite
   * value, on purpose — catalogue and commercial identity go public, content
   * uploaded for one record goes private.
   *
   * Cardinality 1 belongs to the field too: one file or none, never a list.
   */
  public function testTheLogoIsAPublicImageFieldWithCardinalityOne() {
    $field = $this->fieldDefinition('field_logo');

    $this->assertStringContainsString("'type' => 'image'", $field);
    $this->assertStringContainsString("'cardinality' => 1", $field);
    $this->assertStringContainsString("'settings' => ['uri_scheme' => 'public']", $field);
    $this->assertStringNotContainsString("'uri_scheme' => 'private'", $field);
  }

  /**
   * The instance: optional, on the provider bundle, with the image widget, the
   * three extensions, the 2 MB cap and BOTH resolutions.
   *
   * The two resolutions are not symmetrical and that is the reason both are
   * pinned here: min_resolution is the only one that REJECTS, so dropping it
   * would leave the field with no dimension validation at all — just a silent
   * resize. max_resolution is what keeps a 4000x4000 from taking disk.
   */
  public function testTheLogoInstanceIsOptionalAndCapsSizeAndResolution() {
    $instance = $this->providerInstanceDefinition('field_logo');

    $this->assertStringContainsString("'bundle' => \$provider_type", $instance);
    $this->assertStringContainsString("'required' => 0", $instance);
    $this->assertStringContainsString("'widget' => ['type' => 'image_image']", $instance);
    $this->assertStringContainsString("'file_extensions' => 'png jpg jpeg'", $instance);
    $this->assertStringContainsString("'max_filesize' => '2 MB'", $instance);
    $this->assertStringContainsString("'min_resolution' => '200x200'", $instance);
    $this->assertStringContainsString("'max_resolution' => '1000x1000'", $instance);
    $this->assertStringContainsString("'alt_field' => 1", $instance);
    // webp and svg are out of scope by express decision: GD without imagewebp
    // cannot resize a webp, and getimagesize() does not recognise an svg.
    $this->assertStringNotContainsString('webp', $instance);
    $this->assertStringNotContainsString('svg', $instance);
  }

  /**
   * The logo is deleted by a destructive uninstall: the name is new in the
   * whole module and no other bundle uses it, exactly like field_gallery and
   * the three fields of SPEC 81.
   */
  public function testTheLogoIsOwnedByThisFeaturesTeardown() {
    $teardown = $this->functionSource('_myapi_services_uninstall_destructive');
    $owned_start = strpos($teardown, '$owned = [');
    $owned = substr($teardown, $owned_start, strpos($teardown, '];', $owned_start) - $owned_start);

    $this->assertStringContainsString(
      "'field_logo'",
      $owned,
      'the logo is created by this feature and must be deleted by its teardown'
    );
  }

  /**
   * myapi_update_7031() re-runs the whole installer, exactly like
   * myapi_update_7028(), 7029() and 7030(), and does nothing else: no backfill,
   * no node touched, no field deleted. The field is born empty for every
   * provider already on the site.
   */
  public function testTheUpdate7031ReRunsTheInstallerAndNothingElse() {
    $update = $this->functionSource('myapi_update_7031');

    $this->assertStringContainsString('_myapi_services_install();', $update);
    $this->assertStringContainsString('field_logo', $update);
    $this->assertStringNotContainsString('field_delete_field(', $update);
    $this->assertStringNotContainsString('node_save(', $update);
    $this->assertStringNotContainsString('db_update(', $update);
  }

  /**
   * And the gallery keeps every one of its own settings: this spec adds a
   * second image field to the same bundle and must not have leaked a single
   * value into the first one — the private scheme least of all.
   */
  public function testTheGalleryIsUntouchedByTheLogo() {
    $field = $this->fieldDefinition('field_gallery');
    $instance = $this->providerInstanceDefinition('field_gallery');

    $this->assertStringContainsString("'cardinality' => 10", $field);
    $this->assertStringContainsString("'settings' => ['uri_scheme' => 'private']", $field);
    $this->assertStringContainsString("'max_filesize' => '3 MB'", $instance);
    $this->assertStringNotContainsString("'min_resolution'", $instance);
    $this->assertStringNotContainsString("'max_resolution'", $instance);
  }

  /* -------------------------------------------------------------------------
   * SPEC 86 — the required field_unit instance on 'service_request'.
   * ---------------------------------------------------------------------- */

  /**
   * The instance is REQUIRED, which is what separates this spec from SPEC 84.
   * No 'settings' either: target_bundles is a FIELD setting, already fixed by
   * the field itself and shared with the other two instances.
   */
  public function testFieldUnitGetsARequiredInstanceOnServiceRequest() {
    $instance = $this->definitionAt(
      $this->functionSource('_myapi_services_install'),
      "_myapi_reservations_ensure_instance('field_unit', \$request_type, [",
      'field_unit must have an instance on the service_request bundle'
    );

    $this->assertStringContainsString("'required' => 1", $instance);
    $this->assertStringContainsString("'widget' => ['type' => 'entityreference_autocomplete']", $instance);
    $this->assertStringNotContainsString("'settings'", $instance);
  }

  /**
   * The two instances of the same borrowed field diverge on purpose, and both
   * values are pinned here so no later change quietly levels them: a required
   * rating unit would invalidate ratings already stored, and an optional
   * request unit would push the validation into the endpoint.
   *
   * 'required' is an INSTANCE setting, which is what makes the divergence legal
   * at all — the field-level settings (type, cardinality, target_bundles) stay
   * single and shared.
   */
  public function testFieldUnitStaysOptionalOnServiceRatingAfterThisSpec() {
    $installer = $this->functionSource('_myapi_services_install');

    $rating = $this->definitionAt(
      $installer,
      "_myapi_reservations_ensure_instance('field_unit', \$rating_type, [",
      'the service_rating instance of field_unit must survive this spec'
    );
    $request = $this->definitionAt(
      $installer,
      "_myapi_reservations_ensure_instance('field_unit', \$request_type, [",
      'the service_request instance of field_unit must exist'
    );

    $this->assertStringContainsString("'required' => 0", $rating);
    $this->assertStringContainsString("'required' => 1", $request);
  }

  /**
   * Still not re-created and still not owned: this spec adds an instance and
   * touches neither the field nor the teardown. The SPEC 84 guards above cover
   * the same two rules; these repeat them because a second instance is a second
   * chance to get them wrong.
   */
  public function testTheSecondServicesInstanceDoesNotMakeFieldUnitOwned() {
    $this->assertStringNotContainsString(
      "_myapi_reservations_ensure_field('field_unit'",
      $this->functionSource('_myapi_services_install')
    );
    $this->assertStringNotContainsString(
      'field_unit',
      $this->functionSource('_myapi_services_uninstall_destructive')
    );
  }

  /**
   * myapi_update_7032() re-runs the whole installer and does nothing else: no
   * backfill of the requests already on the site, no node saved, no field
   * deleted. It is the first services update to create a REQUIRED instance, so
   * "nothing else" is the part that matters — anything writing rows here would
   * be guessing a unit the module cannot infer.
   */
  public function testTheUpdate7032ReRunsTheInstallerAndNothingElse() {
    $update = $this->functionSource('myapi_update_7032');

    $this->assertStringContainsString('_myapi_services_install();', $update);
    $this->assertStringContainsString('field_unit', $update);
    $this->assertStringNotContainsString('field_delete_field(', $update);
    $this->assertStringNotContainsString('node_save(', $update);
    $this->assertStringNotContainsString('db_update(', $update);
  }

  /* -------------------------------------------------------------------------
   * SPEC 87 — the 'direct' status.
   * ---------------------------------------------------------------------- */

  /**
   * The status exists as a constant, with the key the API will carry and the
   * label the back office shows. Written as its own test because the key is a
   * contract with the app the moment a request travels in a response.
   */
  public function testTheDirectStatusIsCatalogued() {
    $this->assertTrue(defined('MYAPI_SERVICES_REQUEST_STATUS_DIRECT'));
    $this->assertSame('direct', MYAPI_SERVICES_REQUEST_STATUS_DIRECT);

    $statuses = myapi_services_request_statuses();

    $this->assertArrayHasKey('direct', $statuses);
    $this->assertSame('Proveedor directo', $statuses['direct']);
  }

  /**
   * 'direct' is a root of the graph: it has an entry of its own and NO status
   * lists it as a destination. The closure test above would not catch this — a
   * status nothing reaches is well-formed — and the product decision is exactly
   * that unreachability.
   *
   * THE WAY IN IS WHAT MAKES IT A ROOT, AND SPEC 107 DID NOT TOUCH IT. What
   * that spec added is a third way OUT: 'direct' → 'assigned', the resident
   * accepting the quote of the provider they already chose. A 'direct' is born
   * with a provider but without a price, and until then it had no verb that
   * closed that gap.
   */
  public function testTheDirectStatusIsARootOfTheGraph() {
    $transitions = myapi_services_request_transitions();

    $this->assertSame(['assigned', 'closed', 'cancelled'], $transitions['direct']);

    foreach ($transitions as $from => $targets) {
      $this->assertNotContains(
        'direct',
        $targets,
        $from . ' must not lead into direct: a request is born direct or it is not direct'
      );
    }
  }

  /**
   * 'direct' → 'assigned' IS AN EDGE OF THE GRAPH AND NOT A BRANCH IN AN
   * ENDPOINT (SPEC 107). The award's gate asks the graph and transcribes
   * nothing, so this one line is the whole of what made a 'direct' awardable —
   * which is also why the change could not be made anywhere else.
   */
  public function testADirectRequestCanBeAwarded() {
    $this->assertTrue(myapi_services_transition_allowed('direct', 'assigned'));
  }

  /**
   * The two updates that a new allowed value needs, and that re-running the
   * installer cannot do: _myapi_reservations_ensure_field() skips an existing
   * field and _myapi_reservations_ensure_instance() skips an existing instance,
   * so without these two calls an installed site would keep the five old values
   * and a required offer, with no error to show for it.
   */
  public function testTheUpdate7033WidensTheFieldAndFreesTheOffer() {
    $update = $this->functionSource('myapi_update_7033');

    $this->assertStringContainsString("field_read_field('field_request_status')", $update);
    $this->assertStringContainsString('field_update_field($field);', $update);
    $this->assertStringContainsString("field_read_instance('node', 'field_rating_offer'", $update);
    $this->assertStringContainsString('field_update_instance($instance);', $update);
    $this->assertStringContainsString("\$instance['required'] = 0;", $update);
    // And the caches, or the old allowed_values survive the request.
    $this->assertStringContainsString('field_info_cache_clear();', $update);
  }

  /**
   * The update must read the catalogue, never retype the six values. Same rule
   * the installer is held to by testStatusFieldsTakeTheirValuesFromTheCatalogue:
   * a hand-typed list here would drift the day a seventh status arrives, and the
   * symptom — a status the API accepts that the field refuses to store — would
   * point everywhere except at this function.
   */
  public function testTheUpdate7033TakesTheValuesFromTheCatalogue() {
    $update = $this->functionSource('myapi_update_7033');

    $this->assertStringContainsString(
      "\$field['settings']['allowed_values'] = myapi_services_request_statuses();",
      $update
    );
    // No status key written out by hand next to it.
    foreach (array_keys(myapi_services_request_statuses()) as $key) {
      $this->assertStringNotContainsString(
        "'" . $key . "' =>",
        $update,
        'the update must not retype the catalogue it can read'
      );
    }
  }

  /**
   * Adding a value is not removing one: there is nothing to migrate, and the
   * update must not touch a single row. myapi_update_7021() had to rewrite rows
   * before shrinking a catalogue because core forbids dropping a value still in
   * use; widening one has no such hazard, and a db_update() here would be
   * somebody guessing which requests are "really" direct.
   */
  public function testTheUpdate7033MigratesNoData() {
    $update = $this->functionSource('myapi_update_7033');

    $this->assertStringNotContainsString('db_update(', $update);
    $this->assertStringNotContainsString('node_save(', $update);
    $this->assertStringNotContainsString('field_delete_field(', $update);
    $this->assertStringNotContainsString('field_delete_instance(', $update);
  }
  /* -------------------------------------------------------------------------
   * The ten quote fields of 'service_offer' (SPEC 100).
   * ---------------------------------------------------------------------- */

  /**
   * Every one of the ten is created, with the type and the cardinality the
   * spec's table names. Storage is the half that a hook_update_N cannot undo
   * cheaply — changing a type once there are rows means a migration — so it is
   * pinned here field by field rather than counted.
   *
   * @dataProvider newOfferFields
   */
  public function testTheTenQuoteFieldsAreCreated($field_name, $type, array $contains) {
    $field = $this->fieldDefinition($field_name);

    $this->assertStringContainsString("'type' => '" . $type . "'", $field);
    $this->assertStringContainsString("'cardinality' => 1", $field, $field_name . ' must hold one value');

    foreach ($contains as $expected) {
      $this->assertStringContainsString($expected, $field, $field_name . ' must carry ' . $expected);
    }
  }

  public function newOfferFields() {
    return [
      // The two list_text fields read their values from the catalogue and
      // never retype them — see the dedicated test below.
      'amount type'    => ['field_offer_amount_type', 'list_text', ["'allowed_values' => myapi_services_offer_amount_types()"]],
      // The two dates share the bundle's timestamp settings, so a licence and
      // an offer validity are stored with the same granularity.
      'valid until'    => ['field_offer_valid_until', 'datestamp', ["'settings' => \$timestamp_settings"]],
      'available from' => ['field_offer_available_from', 'datestamp', ["'settings' => \$timestamp_settings"]],
      'duration'       => ['field_offer_duration', 'number_integer', []],
      'duration unit'  => ['field_offer_duration_unit', 'list_text', ["'allowed_values' => myapi_services_offer_duration_units()"]],
      // text_long with no settings: no format column, unlike field_offer_message.
      'includes'       => ['field_offer_includes', 'text_long', []],
      'excludes'       => ['field_offer_excludes', 'text_long', []],
      // list_boolean and not an integer: the value has to be able to be absent.
      'tax included'   => ['field_offer_tax_included', 'list_boolean', ["'allowed_values' => [0 => 'No', 1 => 'Sí']"]],
      'warranty days'  => ['field_offer_warranty_days', 'number_integer', []],
      'requires visit' => ['field_offer_requires_visit', 'list_boolean', ["'allowed_values' => [0 => 'No', 1 => 'Sí']"]],
    ];
  }

  /**
   * THE DECISION THE WHOLE SPEC RESTS ON: all ten instances are OPTIONAL.
   * There are real offers already saved on this site, and a required instance
   * would leave every one of them unsaveable from node/%/edit until a human
   * filled the new field in. The obligation lives in the endpoint, where it can
   * be reasoned about and changed without touching the database.
   *
   * @dataProvider newOfferInstances
   */
  public function testTheTenQuoteInstancesAreOptionalAndHangOffTheOffer($field_name, $widget, $label) {
    $instance = $this->offerInstanceDefinition($field_name);

    $this->assertStringContainsString("'bundle' => \$offer_type", $instance);
    $this->assertStringContainsString("'required' => 0", $instance, $field_name . ' must stay optional');
    $this->assertStringContainsString("'widget' => " . $widget, $instance);
    $this->assertStringContainsString("'label' => '" . $label . "'", $instance);
  }

  public function newOfferInstances() {
    return [
      'amount type'    => ['field_offer_amount_type', "['type' => 'options_select']", 'Tipo de precio'],
      'valid until'    => ['field_offer_valid_until', '$date_widget', 'Válida hasta'],
      'available from' => ['field_offer_available_from', '$date_widget', 'Disponible desde'],
      'duration'       => ['field_offer_duration', "['type' => 'number']", 'Duración estimada'],
      'duration unit'  => ['field_offer_duration_unit', "['type' => 'options_select']", 'Unidad de la duración'],
      'includes'       => ['field_offer_includes', "['type' => 'text_textarea']", 'Qué incluye'],
      'excludes'       => ['field_offer_excludes', "['type' => 'text_textarea']", 'Qué no incluye'],
      'tax included'   => ['field_offer_tax_included', "['type' => 'options_select']", 'Impuesto incluido'],
      'warranty days'  => ['field_offer_warranty_days', "['type' => 'number']", 'Garantía (días)'],
      'requires visit' => ['field_offer_requires_visit', "['type' => 'options_select']", 'Requiere visita previa'],
    ];
  }

  /**
   * The two booleans must NOT use 'options_onoff'. A checkbox has no empty
   * state, so the operator could never leave "impuesto incluido" unanswered —
   * and "unanswered" is exactly the third value the field exists to hold, the
   * one the detail serves as null instead of inventing a false.
   */
  public function testTheTwoBooleansCanBeLeftUnanswered() {
    foreach (['field_offer_tax_included', 'field_offer_requires_visit'] as $field_name) {
      $this->assertStringNotContainsString(
        'options_onoff',
        $this->offerInstanceDefinition($field_name),
        $field_name . ' must keep an empty state, so no checkbox widget'
      );
    }
  }

  /**
   * Same rule testStatusFieldsTakeTheirValuesFromTheCatalogue holds the status
   * fields to: the two new list_text fields READ their allowed_values from
   * includes/myapi.services_common.inc and never retype a single key. A
   * hand-typed list here would drift the day a value is added, and the
   * symptom — a value the API accepts that the field refuses to store — would
   * point everywhere except at the installer.
   */
  public function testTheQuoteFieldsTakeTheirValuesFromTheCatalogue() {
    $installer = $this->functionSource('_myapi_services_install');

    $this->assertStringContainsString(
      "'allowed_values' => myapi_services_offer_amount_types()",
      $installer,
      'field_offer_amount_type must read its allowed_values from the catalogue'
    );
    $this->assertStringContainsString(
      "'allowed_values' => myapi_services_offer_duration_units()",
      $installer,
      'field_offer_duration_unit must read its allowed_values from the catalogue'
    );

    $keys = array_merge(
      array_keys(myapi_services_offer_amount_types()),
      array_keys(myapi_services_offer_duration_units())
    );
    foreach ($keys as $key) {
      $this->assertStringNotContainsString(
        "'" . $key . "' =>",
        $installer,
        'the installer must not retype the catalogue it can read'
      );
    }
  }

  /**
   * All ten names are new in the whole module and live on no other bundle, so
   * the destructive teardown deletes them outright. Missing one would strand a
   * field on a bundle that no longer exists — and a reinstall would then find
   * it already there and never recreate it with the right settings.
   */
  public function testTheTenQuoteFieldsAreOwnedByThisFeature() {
    $owned = $this->ownedFieldList();

    foreach (self::NEW_OFFER_FIELDS as $field_name) {
      $this->assertStringContainsString(
        "'" . $field_name . "'",
        $owned,
        $field_name . ' is created by this feature and must be deleted by its teardown'
      );
    }
  }

  /**
   * The eight fields SPEC 77 gave 'service_offer' are untouched by this spec.
   * Ten new columns next to them is the cheap change; altering one of the eight
   * would be a migration, and this asserts none was smuggled in.
   */
  public function testTheEightOriginalOfferFieldsAreUnchanged() {
    $this->assertStringContainsString("'required' => 1", $this->offerInstanceDefinition('field_request'));
    $this->assertStringContainsString("'required' => 1", $this->offerInstanceDefinition('field_provider'));
    $this->assertStringContainsString("'required' => 1", $this->offerInstanceDefinition('field_offer_message'));
    $this->assertStringContainsString("'required' => 0", $this->offerInstanceDefinition('field_offer_amount'));
    $this->assertStringContainsString("'required' => 1", $this->offerInstanceDefinition('field_offer_status'));

    // The three chat fields stay optional and stay empty: no spec has built the
    // transport yet, and this one does not either.
    foreach (['field_firebase_path', 'field_chat_opened_at', 'field_last_message_at'] as $field_name) {
      $this->assertStringContainsString("'required' => 0", $this->offerInstanceDefinition($field_name));
    }

    $field = $this->fieldDefinition('field_offer_amount');
    $this->assertStringContainsString("'settings' => ['precision' => 10, 'scale' => 2]", $field);
  }
  /* -------------------------------------------------------------------------
   * myapi_update_7035 — the ten quote fields on an installed site (SPEC 100).
   * ---------------------------------------------------------------------- */

  /**
   * Re-running the installer is ALL this update needs, unlike
   * myapi_update_7033(). That one modified two things that already existed, and
   * the idempotent _ensure_* helpers only ever create; here all twenty
   * creations are new, so "only ever create" is exactly the wanted behaviour
   * and there is nothing for field_update_field() to do.
   *
   * field_info_cache_clear() is not optional: without it the ten fields exist
   * in the database and the Field API keeps answering the old bundle shape for
   * the rest of this request.
   */
  public function testTheUpdate7035ReRunsTheInstallerAndClearsTheFieldCache() {
    $update = $this->functionSource('myapi_update_7035');

    $this->assertStringContainsString("module_load_include('inc', 'myapi', 'includes/myapi.services_common');", $update);
    $this->assertStringContainsString('_myapi_services_install();', $update);
    $this->assertStringContainsString('field_info_cache_clear();', $update);

    // Nothing that MODIFIES an existing field or instance: all ten are new, and
    // a field_update_field() here would be rewriting somebody else's settings.
    $this->assertStringNotContainsString('field_update_field(', $update);
    $this->assertStringNotContainsString('field_update_instance(', $update);
  }

  /**
   * NO BACKFILL, and this is the assertion that keeps it that way. There are
   * real offers stored, and deducing an amount_type from the amount would put a
   * statement in a provider's mouth they never made. Every stored offer answers
   * null on all ten new fields, which says exactly what happened: it predates
   * them. Same discipline myapi_update_7032() is held to.
   */
  public function testTheUpdate7035TouchesNoStoredOffer() {
    $update = $this->functionSource('myapi_update_7035');

    $this->assertStringNotContainsString('db_update(', $update);
    $this->assertStringNotContainsString('db_insert(', $update);
    $this->assertStringNotContainsString('node_save(', $update);
    $this->assertStringNotContainsString('node_load(', $update);
    $this->assertStringNotContainsString('field_delete_field(', $update);
    $this->assertStringNotContainsString('field_delete_instance(', $update);
  }

  /**
   * The update must not retype a single allowed value either: it delegates to
   * the installer, which reads the catalogues. Same rule
   * testTheUpdate7033TakesTheValuesFromTheCatalogue holds its own update to.
   */
  public function testTheUpdate7035RetypesNoCatalogue() {
    $update = $this->functionSource('myapi_update_7035');

    $keys = array_merge(
      array_keys(myapi_services_offer_amount_types()),
      array_keys(myapi_services_offer_duration_units())
    );
    foreach ($keys as $key) {
      $this->assertStringNotContainsString(
        "'" . $key . "'",
        $update,
        'the update must not retype a catalogue the installer already reads'
      );
    }
  }
}
