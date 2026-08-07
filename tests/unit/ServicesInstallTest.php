<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';

/**
 * Unit tests for the services marketplace catalogues and installer (SPEC 77).
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
   * The install file as text, for the guards at the bottom.
   */
  private function installSource() {
    return file_get_contents(__DIR__ . '/../../myapi.install');
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
}
