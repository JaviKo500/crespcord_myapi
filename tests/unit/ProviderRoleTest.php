<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';

/**
 * Unit tests for the 'proveedor' role (SPEC 78), whose catalogues and pure
 * logic live in includes/myapi.provider_role.inc.
 *
 * Covers the decidable pieces of the spec, all of them free of Drupal:
 *   - myapi_provider_role_permissions()      — empty by design, and the
 *     no-delete guard;
 *   - myapi_provider_role_scope_map()        — exactly the five bundles;
 *   - myapi_provider_role_roles_match()      — role matching by name;
 *   - myapi_provider_role_access_decision()  — deny / ignore, never allow,
 *     including "no provider associated";
 *   - myapi_provider_role_request_visible()  — the service_request rule, in
 *     its category x status x enablement combinations, plus the
 *     already-offered override;
 *   - the field readers (target_id / tid / value).
 *
 * Deliberately NOT tested here: myapi_provider_role_is(),
 * myapi_provider_role_provider_ids(), myapi_provider_role_category_ids(),
 * myapi_provider_role_any_provider_active(), myapi_provider_role_has_offered(),
 * myapi_provider_role_offered_request_ids(),
 * myapi_provider_role_visible_request_ids(), myapi_provider_role_node_decision()
 * and myapi_provider_role_alter_node_query(). All of them need the Drupal
 * container — a booted site, a database, the Field API — which is exactly
 * what tests/unit avoids across this repo (same split as
 * tests/unit/BuildingAdminTest.php for includes/myapi.building_admin.inc).
 * Their verification is the manual acceptance criteria of SPEC 78.
 *
 * Two tests here are guards against a future edit rather than checks of
 * today's behaviour: testNoDeletePermissionIsEverGranted() fails the moment
 * somebody adds a 'delete ...' permission to the catalogue, and
 * testAccessDecisionIsNeverAllow() fails if the decision ever grows an
 * 'allow' branch. Both would be silent privilege escalations otherwise.
 */
class ProviderRoleTest extends TestCase {

  /* -------------------------------------------------------------------------
   * Catalogues.
   * ---------------------------------------------------------------------- */

  /**
   * Empty by design: the role needs no Drupal permission for the app to
   * work, since the API writes with node_save() and reads with db_select()
   * carrying no 'node_access' tag. See the function's own docblock.
   */
  public function testPermissionsCatalogueIsEmpty() {
    $this->assertSame(array(), myapi_provider_role_permissions());
  }

  /**
   * GUARD: no delete permission, ever. Written even though the catalogue is
   * empty today, so the day something is added to it this keeps failing on a
   * 'delete ...' entry specifically. Same guard as
   * BuildingAdminTest::testNoDeletePermissionIsEverGranted().
   */
  public function testNoDeletePermissionIsEverGranted() {
    $permissions = myapi_provider_role_permissions();

    // Asserted even though it is covered by testPermissionsCatalogueIsEmpty()
    // too: today's empty catalogue means the loop below never runs, and
    // without an assertion of its own PHPUnit marks this test "risky" for
    // performing none. The day the catalogue is no longer empty, this line
    // stops mattering and the loop takes over as the real guard.
    $this->assertIsArray($permissions);

    foreach ($permissions as $permission) {
      $this->assertStringStartsNotWith('delete ', $permission);
    }
  }

  /**
   * The map covers exactly the five marketplace bundles, each with the
   * declared mode, and no other type. A sixth entry, or a changed mode, is a
   * change to the access rule and must be a deliberate edit here too.
   */
  public function testScopeMapCoversExactlyTheFiveBundles() {
    $expected = array(
      MYAPI_SERVICES_PROVIDER_TYPE    => array('mode' => 'self'),
      MYAPI_SERVICES_OFFER_TYPE       => array('mode' => 'own', 'field' => 'field_provider'),
      MYAPI_SERVICES_RATING_TYPE      => array('mode' => 'own', 'field' => 'field_rating_provider'),
      MYAPI_SERVICES_REQUEST_TYPE     => array('mode' => 'category'),
      MYAPI_SERVICES_TRANSACTION_TYPE => array('mode' => 'via_request', 'field' => 'field_request'),
    );

    $map = myapi_provider_role_scope_map();

    $this->assertCount(5, $map);
    $this->assertSame(array_keys($expected), array_keys($map));
    foreach ($expected as $type => $entry) {
      $this->assertSame($entry, $map[$type], 'Map entry for ' . $type);
    }
  }

  /**
   * 'condominio', 'vivienda' and every other type of this module are
   * deliberately absent: the role scopes by belonging and category, never by
   * condominium, and a type outside this map is out of the rule entirely.
   */
  public function testScopeMapNamesNoOtherType() {
    $map = myapi_provider_role_scope_map();

    $this->assertArrayNotHasKey('condominio', $map);
    $this->assertArrayNotHasKey('vivienda', $map);
    $this->assertArrayNotHasKey('boletin', $map);
  }

  /* -------------------------------------------------------------------------
   * Role matching.
   * ---------------------------------------------------------------------- */

  public function testRolesMatchByName() {
    $this->assertTrue(myapi_provider_role_roles_match(array('authenticated user', MYAPI_PROVIDER_ROLE)));
    $this->assertFalse(myapi_provider_role_roles_match(array('authenticated user')));
    $this->assertFalse(myapi_provider_role_roles_match(array()));
  }

  /* -------------------------------------------------------------------------
   * Access decision (belonging).
   * ---------------------------------------------------------------------- */

  /**
   * The node belongs to one of the account's own providers: nothing to say,
   * the rest of Drupal decides.
   */
  public function testAccessDecisionIgnoresBelonging() {
    $this->assertSame('ignore', myapi_provider_role_access_decision(TRUE, array(10)));
    $this->assertSame('ignore', myapi_provider_role_access_decision(TRUE, array(10, 20)));
  }

  /**
   * The node does not belong: denied. This is the 403 on a direct URL.
   */
  public function testAccessDecisionDeniesWhenItDoesNotBelong() {
    $this->assertSame('deny', myapi_provider_role_access_decision(FALSE, array(10)));
  }

  /**
   * No provider associated at all means no content at all — the same
   * explicit criterion SPEC 49 took with "no condominium assigned": the
   * absence of an assignment denies, it does not open up. This holds even
   * when $belongs happens to be reported TRUE, which should not normally
   * occur (nothing can "belong" to an empty set) but the guard is checked
   * first regardless, matching myapi_building_admin_access_decision()'s own
   * order.
   */
  public function testAccessDecisionDeniesWithNoProviderAssociated() {
    $this->assertSame('deny', myapi_provider_role_access_decision(FALSE, array()));
    $this->assertSame('deny', myapi_provider_role_access_decision(TRUE, array()));
  }

  /**
   * An unresolvable mode (a required reference field empty or missing) takes
   * the node out of the rule entirely, mirroring the "type outside the map"
   * branch of myapi_building_admin_access_decision().
   */
  public function testAccessDecisionIgnoresAnUnresolvedBelonging() {
    $this->assertSame('ignore', myapi_provider_role_access_decision(NULL, array(10)));
    $this->assertSame('ignore', myapi_provider_role_access_decision(NULL, array()));
  }

  /**
   * GUARD: the decision is never 'allow', in any combination.
   *
   * Allowing here would short-circuit every other check Drupal makes —
   * unpublished nodes, other modules' hooks — turning a scope filter into a
   * permission escalation.
   */
  public function testAccessDecisionIsNeverAllow() {
    foreach (array(NULL, TRUE, FALSE) as $belongs) {
      foreach (array(array(), array(10), array(10, 20)) as $provider_ids) {
        $this->assertContains(
          myapi_provider_role_access_decision($belongs, $provider_ids),
          array('deny', 'ignore')
        );
      }
    }
  }

  /* -------------------------------------------------------------------------
   * Request visibility.
   * ---------------------------------------------------------------------- */

  /**
   * The "concerns them now" branch: category x status x enablement, in its
   * eight combinations, with $already_offered FALSE throughout so the first
   * (unconditional) branch of the rule never masks this one. Visible only
   * when all three hold: category matches, status is 'open' or 'offered',
   * and the provider is active.
   *
   * @dataProvider provideCategoryStatusEnablementCombinations
   */
  public function testRequestVisibleConcernsThemNowCombinations(
    $category_matches,
    $status,
    $provider_active,
    $expected,
    $label
  ) {
    $request_category = $category_matches ? 5 : 9;
    $provider_categories = array(5, 6);

    $this->assertSame(
      $expected,
      myapi_provider_role_request_visible($request_category, $provider_categories, $status, FALSE, $provider_active),
      $label
    );
  }

  public function provideCategoryStatusEnablementCombinations() {
    return array(
      'category+, open,    active   -> visible'     => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_OPEN,      TRUE,  TRUE,  'cat+/open/active'),
      'category+, offered, active   -> visible'     => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_OFFERED,   TRUE,  TRUE,  'cat+/offered/active'),
      // SPEC 87: a request born with a provider already chosen is still
      // broadcast by category, exactly like an open one.
      'category+, direct, active    -> visible'     => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_DIRECT,    TRUE,  TRUE,  'cat+/direct/active'),
      'category-, direct, active    -> not visible' => array(FALSE, MYAPI_SERVICES_REQUEST_STATUS_DIRECT,    TRUE,  FALSE, 'cat-/direct/active'),
      'category+, direct, inactive  -> not visible' => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_DIRECT,    FALSE, FALSE, 'cat+/direct/inactive'),
      'category+, assigned, active  -> not visible' => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,  TRUE,  FALSE, 'cat+/assigned/active'),
      'category+, closed, active    -> not visible' => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_CLOSED,    TRUE,  FALSE, 'cat+/closed/active'),
      'category+, cancelled, active -> not visible' => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_CANCELLED, TRUE,  FALSE, 'cat+/cancelled/active'),
      'category-, open, active      -> not visible' => array(FALSE, MYAPI_SERVICES_REQUEST_STATUS_OPEN,      TRUE,  FALSE, 'cat-/open/active'),
      'category+, open, inactive    -> not visible' => array(TRUE,  MYAPI_SERVICES_REQUEST_STATUS_OPEN,      FALSE, FALSE, 'cat+/open/inactive'),
      'category-, open, inactive    -> not visible' => array(FALSE, MYAPI_SERVICES_REQUEST_STATUS_OPEN,      FALSE, FALSE, 'cat-/open/inactive'),
    );
  }

  /**
   * The "already offered" branch overrides everything else: status,
   * enablement and category are all irrelevant once the provider has an
   * offer on the request. This is what lets a provider consult the history
   * of a closed, cancelled or expired-provider request they took part in.
   */
  public function testRequestVisibleAlreadyOfferedOverridesEverythingElse() {
    // Wrong category, terminal status, inactive provider: still visible.
    $this->assertTrue(myapi_provider_role_request_visible(
      9, array(5, 6), MYAPI_SERVICES_REQUEST_STATUS_CLOSED, TRUE, FALSE
    ));
    $this->assertTrue(myapi_provider_role_request_visible(
      9, array(5, 6), MYAPI_SERVICES_REQUEST_STATUS_CANCELLED, TRUE, FALSE
    ));
    // Right category, open status, active provider, also offered: visible,
    // same as the "concerns them now" branch would answer anyway.
    $this->assertTrue(myapi_provider_role_request_visible(
      5, array(5, 6), MYAPI_SERVICES_REQUEST_STATUS_OPEN, TRUE, TRUE
    ));
  }

  /**
   * A request with no category on record (a data anomaly: the field is
   * required) is never visible through the "concerns them now" branch,
   * without raising a PHP notice.
   */
  public function testRequestVisibleWithNoCategoryIsNotVisible() {
    $this->assertFalse(myapi_provider_role_request_visible(NULL, array(5, 6), MYAPI_SERVICES_REQUEST_STATUS_OPEN, FALSE, TRUE));
  }

  /**
   * An account with no categories at all (no provider, or providers with an
   * empty field_categories) never matches through the category branch.
   */
  public function testRequestVisibleWithNoProviderCategoriesIsNotVisible() {
    $this->assertFalse(myapi_provider_role_request_visible(5, array(), MYAPI_SERVICES_REQUEST_STATUS_OPEN, FALSE, TRUE));
  }

  /**
   * The broadcast list is exactly the three non-terminal statuses in which a
   * provider still has something to do: 'open', 'direct' (SPEC 87) and
   * 'offered'. 'assigned' is out — the job is somebody else's — and so are the
   * two terminals.
   */
  public function testBroadcastStatusesAreTheThreeActionableOnes() {
    $this->assertSame(
      array(
        MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
        MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      ),
      myapi_provider_role_broadcast_statuses()
    );

    foreach (array(
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ) as $status) {
      $this->assertNotContains($status, myapi_provider_role_broadcast_statuses(), $status . ' must not be broadcast');
    }
  }

  /**
   * GUARD, and the reason the list was extracted at all: the SQL half of the
   * filter — the category sub-query of myapi_provider_role_visible_request_ids()
   * — must read the SAME list as the pure decision, not a second copy of it. A
   * status added to one and not to the other makes a request reachable by
   * direct URL and absent from every listing, or the other way round, with no
   * error anywhere.
   */
  public function testTheQueryFilterReadsTheSameBroadcastList() {
    $source = preg_replace(
      '/\s+/',
      ' ',
      file_get_contents(__DIR__ . '/../../includes/myapi.provider_role.inc')
    );

    // The pure decision.
    $this->assertStringContainsString(
      'in_array($request_status, myapi_provider_role_broadcast_statuses(), TRUE)',
      $source
    );
    // The SQL condition, reading the very same function.
    $this->assertStringContainsString(
      "\$query->condition( 'frs.field_request_status_value', myapi_provider_role_broadcast_statuses(), 'IN' )",
      $source
    );
  }

  /* -------------------------------------------------------------------------
   * Field readers.
   * ---------------------------------------------------------------------- */

  private function entity(array $fields) {
    $entity = new stdClass();
    foreach ($fields as $name => $item) {
      $entity->{$name} = $item === NULL ? array() : array(LANGUAGE_NONE => array($item));
    }

    return $entity;
  }

  public function testFieldTargetIdReadsTheFirstDelta() {
    $node = $this->entity(array('field_provider' => array('target_id' => 10)));

    $this->assertSame(10, myapi_provider_role_field_target_id($node, 'field_provider'));
  }

  public function testFieldTidReadsTheFirstDelta() {
    $node = $this->entity(array('field_category' => array('tid' => 5)));

    $this->assertSame(5, myapi_provider_role_field_tid($node, 'field_category'));
  }

  public function testFieldValueReadsTheFirstDelta() {
    $node = $this->entity(array('field_request_status' => array('value' => 'open')));

    $this->assertSame('open', myapi_provider_role_field_value($node, 'field_request_status'));
  }

  /**
   * An absent field, an empty field and a field on a non-object all resolve
   * to NULL, quietly — no PHP notice on the way. A notice here would mean
   * this hook printing warnings on every node_access check.
   */
  public function testFieldReadersAreNullAndQuietWhenUnresolvable() {
    $empty = $this->entity(array('field_provider' => NULL));
    $absent = new stdClass();

    $this->assertNull(myapi_provider_role_field_target_id($empty, 'field_provider'));
    $this->assertNull(myapi_provider_role_field_target_id($absent, 'field_provider'));
    $this->assertNull(myapi_provider_role_field_tid('not an object', 'field_category'));
  }

}
