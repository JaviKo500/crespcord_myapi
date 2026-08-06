<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.building_admin.inc';

/**
 * Unit tests for the 'administrador edificio' role (SPEC 49), whose catalogues
 * and pure logic live in includes/myapi.building_admin.inc.
 *
 * Covers the five decidable pieces of the spec, all of them free of Drupal:
 *   - the four catalogues (editable types, visible types, permissions, map);
 *   - myapi_building_admin_resolve_condominium() — the four modes of the map;
 *   - myapi_building_admin_access_decision()     — deny / ignore, never allow;
 *   - myapi_building_admin_bulletin_errors()     — the node form rule;
 *   - myapi_building_admin_filter_available_permissions().
 *
 * Deliberately NOT tested here: myapi_node_access(), the node_access query
 * alter and the idempotent installer. All three need the Drupal container — a
 * booted site, a database, a field API — which is exactly what tests/unit
 * avoids across this repo. Their verification is the manual acceptance
 * criteria of the spec, and it is written down there rather than skipped
 * silently.
 *
 * Two tests here are guards against a future edit rather than checks of
 * today's behaviour, and they are the reason this file matters most:
 * testNoDeletePermissionIsEverGranted() fails the moment somebody adds a
 * 'delete ...' permission to the catalogue, and testDecisionIsNeverAllow()
 * fails if the access decision ever grows an 'allow' branch. Both would be
 * silent privilege escalations otherwise.
 */
class BuildingAdminTest extends TestCase {

  /**
   * Builds a fake node with entity-reference field values.
   *
   * The shape is the stored one, langcode => delta => item, which is also what
   * a node form hands over after element validation.
   *
   * @param string $type   Node type.
   * @param array $fields  Field name => target id, or field name => NULL for a
   *   field present but empty.
   * @param int|null $nid  Node id, needed by the 'self' mode.
   */
  private function node($type, array $fields = array(), $nid = NULL) {
    $node = new stdClass();
    $node->type = $type;
    if ($nid !== NULL) {
      $node->nid = $nid;
    }
    foreach ($fields as $name => $target) {
      $node->{$name} = $target === NULL
        ? array()
        : array('und' => array(array('target_id' => $target)));
    }

    return $node;
  }

  /* -------------------------------------------------------------------------
   * Catalogues.
   * ---------------------------------------------------------------------- */

  /**
   * The three types the role manages, and NOT 'condominio': seeing a
   * condominium is not administering it.
   */
  public function testEditableTypesWithoutTheClaimsBundle() {
    $types = myapi_building_admin_editable_types(FALSE);

    $this->assertContains('boletin', $types);
    $this->assertContains('reservation', $types);
    $this->assertContains('area', $types);
    $this->assertNotContains('condominio', $types);
    $this->assertNotContains(MYAPI_BUILDING_ADMIN_CLAIM_TYPE, $types);
    $this->assertNotContains(MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE, $types);
  }

  /**
   * The claims bundle joins the editable types only once it exists on the
   * site; until then the catalogue must not name it. Same criterion for the
   * claim_transaction bundle (SPEC 56): forcing TRUE includes both.
   */
  public function testEditableTypesWithTheClaimsBundle() {
    $types = myapi_building_admin_editable_types(TRUE);

    $this->assertContains(MYAPI_BUILDING_ADMIN_CLAIM_TYPE, $types);
    $this->assertContains(MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE, $types);
    $this->assertNotContains('condominio', $types);
  }

  /**
   * Visible = editable + read-only. 'condominio' is what keeps the
   * entityreference autocompletes of the node forms from coming up empty;
   * 'vivienda' is the units the operator consults but never touches.
   */
  public function testVisibleTypesAreTheEditableOnesPlusTheReadOnlyOnes() {
    foreach (array(FALSE, TRUE) as $has_claims) {
      $editable = myapi_building_admin_editable_types($has_claims);
      $visible = myapi_building_admin_visible_types($has_claims);

      $this->assertSame(
        array_merge($editable, myapi_building_admin_readonly_types()),
        $visible
      );
      $this->assertContains('condominio', $visible);
      $this->assertContains('vivienda', $visible);
    }
  }

  /**
   * GUARD: the two catalogues never overlap, and no read-only type ever gets a
   * write permission.
   *
   * Moving a type from one list to the other is what turns "consult" into
   * "administer". This test makes that a deliberate act instead of a side
   * effect: adding 'vivienda' to the editable list fails here.
   */
  public function testReadOnlyTypesAreNeverEditable() {
    foreach (array(FALSE, TRUE) as $has_claims) {
      $editable = myapi_building_admin_editable_types($has_claims);
      $permissions = myapi_building_admin_permissions($has_claims);

      foreach (myapi_building_admin_readonly_types() as $type) {
        $this->assertNotContains($type, $editable);
        $this->assertNotContains('create ' . $type . ' content', $permissions);
        $this->assertNotContains('edit any ' . $type . ' content', $permissions);
        $this->assertNotContains('edit own ' . $type . ' content', $permissions);
      }
    }
  }

  /**
   * The role creates and edits the types it manages, and nothing else.
   */
  public function testPermissionsCoverCreateAndEditOfEveryEditableType() {
    $permissions = myapi_building_admin_permissions(FALSE);

    foreach (myapi_building_admin_editable_types(FALSE) as $type) {
      $this->assertContains('create ' . $type . ' content', $permissions);
      $this->assertContains('edit any ' . $type . ' content', $permissions);
    }

    // Seeing a type is not editing it: no permission over the read-only ones.
    $this->assertNotContains('create condominio content', $permissions);
    $this->assertNotContains('edit any condominio content', $permissions);
    $this->assertNotContains('create vivienda content', $permissions);
    $this->assertNotContains('edit any vivienda content', $permissions);
  }

  /**
   * The back-office permissions, without which the content ones are
   * unreachable: no /admin/content and no way into the administration pages.
   */
  public function testPermissionsIncludeTheBackOfficeAccess() {
    $permissions = myapi_building_admin_permissions(FALSE);

    foreach (array(
      'access content',
      'access content overview',
      'access administration pages',
    ) as $permission) {
      $this->assertContains($permission, $permissions);
    }
  }

  /**
   * One text format, and not 'full_html'.
   *
   * Without a 'use text format ...' permission the role only reaches Drupal's
   * fallback format, and every formatted field it may edit — the bulletin body,
   * the area notes — renders disabled. 'full_html' would fix that too, but the
   * permission is site-wide and allows arbitrary HTML, so it is the wrong tool.
   */
  public function testASafeTextFormatIsGranted() {
    $permissions = myapi_building_admin_permissions(FALSE);

    $this->assertContains('use text format ' . MYAPI_BUILDING_ADMIN_TEXT_FORMAT, $permissions);
    $this->assertSame('filtered_html', MYAPI_BUILDING_ADMIN_TEXT_FORMAT);
    $this->assertNotContains('use text format full_html', $permissions);
  }

  /**
   * The role navigates through its own sidebar menu, not Drupal's toolbar.
   *
   * 'access toolbar' was granted when SPEC 49 was approved and was dropped
   * afterwards: the black toolbar offered 'Estructura' and 'Configuración'
   * entries this role can only get a 403 from. This test is here so it is not
   * silently added back.
   */
  public function testToolbarIsNotGranted() {
    foreach (array(FALSE, TRUE) as $has_claims) {
      $this->assertNotContains('access toolbar', myapi_building_admin_permissions($has_claims));
    }
  }

  /**
   * The role works on the site theme, like every other operator here.
   *
   * 'view the administration theme' was granted when SPEC 49 was approved and
   * was dropped afterwards: it rendered the node forms and /admin/content in
   * Seven, which looks nothing like what 'backend' sees and — because blocks
   * are placed per theme — hides the role's own sidebar menu. Adding it back
   * silently would undo that, so this test is the guard. myapi_update_7015()
   * revokes it on sites that already had it.
   */
  public function testAdminThemeIsNotGranted() {
    foreach (array(FALSE, TRUE) as $has_claims) {
      $this->assertNotContains('view the administration theme', myapi_building_admin_permissions($has_claims));
    }
  }

  /**
   * GUARD: no delete permission, ever, with or without the claims bundle.
   *
   * The role takes content down by cancelling (SPEC 36 / 47) or unpublishing,
   * both reversible. A delete is not. This test is here to fail the day
   * somebody adds one to the catalogue.
   */
  public function testNoDeletePermissionIsEverGranted() {
    foreach (array(FALSE, TRUE) as $has_claims) {
      foreach (myapi_building_admin_permissions($has_claims) as $permission) {
        $this->assertStringStartsNotWith('delete ', $permission);
      }
    }
  }

  /**
   * The map covers exactly the eleven types of the data model, each with the
   * declared mode and field. A twelfth entry, or a changed field name, is a
   * change to the access rule and must be a deliberate edit here too.
   *
   * claim_transaction resolves via the new 'via_claim' mode (SPEC 56): its
   * condominium is only resolvable by hopping field_claim -> reclamo ->
   * field_condominium, reusing the claim's own map entry rather than
   * hard-coding the field name twice.
   */
  public function testCondominiumMapCoversTheElevenDeclaredTypes() {
    $expected = array(
      'condominio'                            => array('mode' => 'self'),
      'boletin'                               => array('mode' => 'direct',    'field' => 'field_condominio'),
      'gastos'                                => array('mode' => 'direct',    'field' => 'field_condominio'),
      'vivienda'                              => array('mode' => 'direct',    'field' => 'field_condominio'),
      'area'                                  => array('mode' => 'direct',    'field' => 'field_condominium'),
      'reservation'                           => array('mode' => 'direct',    'field' => 'field_condominium'),
      'pagos'                                 => array('mode' => 'via_unit',  'field' => 'field_vivienda'),
      'recibo'                                => array('mode' => 'via_unit',  'field' => 'field_vivienda'),
      'alicuota_extra'                        => array('mode' => 'via_unit',  'field' => 'field_vivienda'),
      MYAPI_BUILDING_ADMIN_CLAIM_TYPE         => array('mode' => 'direct',    'field' => 'field_condominium'),
      MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE   => array('mode' => 'via_claim', 'field' => 'field_claim'),
    );

    $map = myapi_building_admin_condominium_map();

    $this->assertCount(11, $map);
    $this->assertSame(array_keys($expected), array_keys($map));
    foreach ($expected as $type => $entry) {
      $this->assertSame($entry, $map[$type], 'Map entry for ' . $type);
    }
  }

  /* -------------------------------------------------------------------------
   * Who may hand out the condominium assignment.
   * ---------------------------------------------------------------------- */

  /**
   * Only the two staff roles, plus uid 1.
   */
  public function testOnlyStaffRolesMayManageTheAssignment() {
    $this->assertTrue(myapi_building_admin_may_manage_assignment(array('administrator')));
    $this->assertTrue(myapi_building_admin_may_manage_assignment(array('backend')));
    $this->assertTrue(myapi_building_admin_may_manage_assignment(array('authenticated user'), TRUE));
  }

  /**
   * GUARD: a building admin may NOT manage the assignment — not even their own.
   *
   * Editing one's own account needs no permission in Drupal, so if this ever
   * returns TRUE the role can widen its own reach to every condominium of the
   * site and the whole feature stops meaning anything.
   */
  public function testABuildingAdminMayNotManageTheAssignment() {
    $this->assertFalse(myapi_building_admin_may_manage_assignment(array(MYAPI_BUILDING_ADMIN_ROLE)));
    $this->assertFalse(myapi_building_admin_may_manage_assignment(
      array('authenticated user', MYAPI_BUILDING_ADMIN_ROLE)
    ));
    $this->assertNotContains(MYAPI_BUILDING_ADMIN_ROLE, myapi_building_admin_assigner_roles());
  }

  /**
   * A plain resident, and an account with no roles at all, are out.
   */
  public function testEverybodyElseIsDenied() {
    $this->assertFalse(myapi_building_admin_may_manage_assignment(array('authenticated user')));
    $this->assertFalse(myapi_building_admin_may_manage_assignment(array()));
  }

  /* -------------------------------------------------------------------------
   * Condominium resolution.
   * ---------------------------------------------------------------------- */

  /**
   * Mode 'self': a condominium node is its own condominium.
   */
  public function testResolveSelfModeReturnsTheOwnNid() {
    $node = $this->node('condominio', array(), 42);

    $this->assertSame(42, myapi_building_admin_resolve_condominium($node));
  }

  /**
   * Mode 'direct', on both field names of the map.
   */
  public function testResolveDirectModeReturnsTheFieldTarget() {
    $bulletin = $this->node('boletin', array('field_condominio' => 7));
    $area = $this->node('area', array('field_condominium' => 9));

    $this->assertSame(7, myapi_building_admin_resolve_condominium($bulletin));
    $this->assertSame(9, myapi_building_admin_resolve_condominium($area));
  }

  /**
   * Mode 'via_unit': node -> vivienda -> condominio, two hops, with the unit
   * load injected so no database is needed.
   */
  public function testResolveViaUnitModeFollowsTheUnit() {
    $payment = $this->node('pagos', array('field_vivienda' => 100));
    $loader = function ($nid) {
      return $nid === 100
        ? (object) array('type' => 'vivienda', 'field_condominio' => array('und' => array(array('target_id' => 5))))
        : NULL;
    };

    $this->assertSame(5, myapi_building_admin_resolve_condominium($payment, $loader));
  }

  /**
   * A 'via_unit' node whose unit cannot be loaded — deleted, or an id pointing
   * nowhere — resolves to NULL instead of erroring.
   */
  public function testResolveViaUnitModeWithAnUnloadableUnitIsNull() {
    $payment = $this->node('recibo', array('field_vivienda' => 100));
    $loader = function ($nid) {
      return NULL;
    };

    $this->assertNull(myapi_building_admin_resolve_condominium($payment, $loader));
  }

  /**
   * Mode 'via_claim': claim_transaction -> reclamo -> field_condominium, two
   * hops through the claim rather than through a unit, with the claim load
   * injected as the third parameter so no database is needed. The reclamo's
   * own field name is read off the map entry (via testCondominiumMap...)
   * rather than hard-coded a second time.
   */
  public function testResolveViaClaimModeFollowsTheClaim() {
    $transaction = $this->node(MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE, array('field_claim' => 200));
    $loader = function ($nid) {
      return $nid === 200
        ? (object) array('type' => 'reclamo', 'field_condominium' => array('und' => array(array('target_id' => 6))))
        : NULL;
    };

    $this->assertSame(6, myapi_building_admin_resolve_condominium($transaction, NULL, $loader));
  }

  /**
   * A 'via_claim' node with no claim loader injected — the default — resolves
   * to NULL instead of erroring, same guard as 'via_unit' with no unit loader.
   */
  public function testResolveViaClaimModeWithUninvocableLoaderIsNull() {
    $transaction = $this->node(MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE, array('field_claim' => 200));

    $this->assertNull(
      myapi_building_admin_resolve_condominium($transaction, NULL, 'nonexistent_loader_never_called')
    );
  }

  /**
   * A 'via_claim' node whose referenced 'reclamo' loads but has no
   * field_condominium resolves to NULL, and quietly — no PHP notice on the
   * missing field.
   */
  public function testResolveViaClaimModeWhenTheClaimHasNoCondominiumIsNull() {
    $transaction = $this->node(MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE, array('field_claim' => 200));
    $loader = function ($nid) {
      return $nid === 200 ? (object) array('type' => 'reclamo') : NULL;
    };

    $this->assertNull(myapi_building_admin_resolve_condominium($transaction, NULL, $loader));
  }

  /**
   * A type outside the map is outside the rule entirely.
   */
  public function testResolveUnknownTypeIsNull() {
    $node = $this->node('article', array('field_condominio' => 7));

    $this->assertNull(myapi_building_admin_resolve_condominium($node));
  }

  /**
   * A mapped type whose field is empty, or not on the object at all, resolves
   * to NULL — and, just as importantly, raises no PHP notice on the way. A
   * notice here would mean the hook printing warnings on every node listing.
   */
  public function testResolveWithMissingOrEmptyFieldIsNullAndQuiet() {
    $cases = array(
      // The field is not on the object at all.
      $this->node('reservation'),
      // The field is there but empty, as on a node saved with nothing picked.
      $this->node('reservation', array('field_condominium' => NULL)),
      // Mode 'self' on an unsaved node: no nid yet.
      $this->node('condominio'),
      // Mode 'via_unit' with nothing to hop to.
      $this->node('pagos', array('field_vivienda' => NULL)),
      // Mode 'via_claim' with nothing to hop to.
      $this->node(MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE, array('field_claim' => NULL)),
    );

    foreach ($cases as $index => $node) {
      $this->assertNull(
        myapi_building_admin_resolve_condominium($node, 'nonexistent_loader_never_called'),
        'Case ' . $index
      );
    }
  }

  /* -------------------------------------------------------------------------
   * Access decision.
   * ---------------------------------------------------------------------- */

  /**
   * A node of an assigned condominium: nothing to say, the rest of Drupal
   * decides.
   */
  public function testDecisionIgnoresAnAssignedCondominium() {
    $this->assertSame('ignore', myapi_building_admin_access_decision(5, array(5, 8)));
  }

  /**
   * A node of somebody else's condominium: denied. This is the 403 on a direct
   * URL.
   */
  public function testDecisionDeniesAForeignCondominium() {
    $this->assertSame('deny', myapi_building_admin_access_decision(9, array(5, 8)));
  }

  /**
   * No condominium assigned means no content at all — the documented meaning
   * of an empty assignment, not an oversight.
   */
  public function testDecisionDeniesWhenNothingIsAssigned() {
    $this->assertSame('deny', myapi_building_admin_access_decision(5, array()));
  }

  /**
   * An unresolved condominium leaves the node out of the rule.
   */
  public function testDecisionIgnoresAnUnresolvedCondominium() {
    $this->assertSame('ignore', myapi_building_admin_access_decision(NULL, array(5)));
    $this->assertSame('ignore', myapi_building_admin_access_decision(NULL, array()));
  }

  /**
   * Ids arriving as strings from a database read still match. Without the
   * cast on both sides, a strict comparison would deny a user their own
   * condominium.
   */
  public function testDecisionMatchesIdsAcrossTypes() {
    $this->assertSame('ignore', myapi_building_admin_access_decision('5', array(5)));
    $this->assertSame('ignore', myapi_building_admin_access_decision(5, array('5')));
  }

  /**
   * GUARD: the decision is never 'allow', in any combination.
   *
   * Allowing here would short-circuit every other check Drupal makes —
   * unpublished nodes, other modules' hooks — turning a condominium filter
   * into a permission escalation.
   */
  public function testDecisionIsNeverAllow() {
    foreach (array(NULL, 0, 5, 9, '5') as $condominium) {
      foreach (array(array(), array(5), array(5, 8), array('5')) as $assigned) {
        $this->assertContains(
          myapi_building_admin_access_decision($condominium, $assigned),
          array('deny', 'ignore')
        );
      }
    }
  }

  /* -------------------------------------------------------------------------
   * Bulletin validation.
   * ---------------------------------------------------------------------- */

  /**
   * 'General' notifies every condominium of the site and is irreversible once
   * sent; 'Personalizado' picks recipients across condominiums. Both are out
   * of reach for this role.
   */
  public function testBulletinScopeOtherThanCondominioIsRejected() {
    foreach (array('General', 'Personalizado', '', 'condominio') as $scope) {
      $errors = myapi_building_admin_bulletin_errors($scope, 5, array(5));

      $this->assertCount(1, $errors, 'Scope ' . var_export($scope, TRUE));
      $this->assertSame('field_tipo_de_boletin', $errors[0]['field']);
    }
  }

  /**
   * The happy path: 'Condominio' scope, own condominium, no errors.
   */
  public function testBulletinOfAnAssignedCondominiumIsAccepted() {
    $errors = myapi_building_admin_bulletin_errors('Condominio', 5, array(5, 8));

    $this->assertSame(array(), $errors);
  }

  /**
   * Right scope, wrong condominium — or none picked at all.
   */
  public function testBulletinOfAForeignOrMissingCondominiumIsRejected() {
    foreach (array(9, NULL) as $condominium) {
      $errors = myapi_building_admin_bulletin_errors('Condominio', $condominium, array(5));

      $this->assertCount(1, $errors, 'Condominium ' . var_export($condominium, TRUE));
      $this->assertSame('field_condominio', $errors[0]['field']);
    }
  }

  /**
   * A building admin with nothing assigned cannot publish anywhere.
   */
  public function testBulletinIsRejectedWhenNothingIsAssigned() {
    $errors = myapi_building_admin_bulletin_errors('Condominio', 5, array());

    $this->assertCount(1, $errors);
    $this->assertSame('field_condominio', $errors[0]['field']);
  }

  /**
   * NULL scope means field_tipo_de_boletin is not on the bundle in this
   * environment. The validation skips itself rather than blocking every
   * bulletin of the role; the caller logs a WARNING for that branch.
   */
  public function testBulletinWithNoScopeFieldIsNotValidated() {
    $this->assertSame(array(), myapi_building_admin_bulletin_errors(NULL, 9, array(5)));
    $this->assertSame(array(), myapi_building_admin_bulletin_errors(NULL, NULL, array()));
  }

  /**
   * The form offers only 'Condominio', and keeps the empty option when the
   * widget had one so the select still asks rather than choosing for the
   * operator.
   */
  public function testBulletinScopeOptionsAreNarrowedToCondominio() {
    $options = array(
      '_none'         => '- Seleccione un valor -',
      'General'       => 'General',
      'Condominio'    => 'Condominio',
      'Personalizado' => 'Personalizado',
    );

    $this->assertSame(
      array('_none' => '- Seleccione un valor -', 'Condominio' => 'Condominio'),
      myapi_building_admin_filter_bulletin_scope_options($options)
    );
  }

  /**
   * A widget with no empty option keeps none, and a site whose allowed values
   * were renamed comes back with nothing to pick — fail closed, never with an
   * unusable scope left in.
   */
  public function testBulletinScopeOptionsFailClosed() {
    $this->assertSame(
      array('Condominio' => 'Condominio'),
      myapi_building_admin_filter_bulletin_scope_options(
        array('General' => 'General', 'Condominio' => 'Condominio')
      )
    );

    $this->assertSame(
      array(),
      myapi_building_admin_filter_bulletin_scope_options(
        array('general' => 'General', 'condominio' => 'Condominio')
      )
    );
  }

  /* -------------------------------------------------------------------------
   * Permission filtering.
   * ---------------------------------------------------------------------- */

  /**
   * Only the permissions that exist on the site are granted, so nothing dead
   * is ever written into role_permission.
   */
  public function testOnlyExistingPermissionsSurvive() {
    $wanted = array(
      'create boletin content',
      'create reclamo content',
      'access content',
      'access toolbar',
    );
    // A site with no claims bundle and the toolbar module disabled.
    $available = array(
      'create boletin content',
      'edit any boletin content',
      'access content',
      'access content overview',
    );

    $this->assertSame(
      array('create boletin content', 'access content'),
      myapi_building_admin_filter_available_permissions($wanted, $available)
    );
  }

  /**
   * The same catalogue, on a site where the claims bundle and the toolbar do
   * exist, grants them both.
   */
  public function testPermissionsAppearOnceTheirModuleOrBundleExists() {
    $wanted = array('create reclamo content', 'access toolbar');
    $available = array('create reclamo content', 'access toolbar', 'access content');

    $this->assertSame($wanted, myapi_building_admin_filter_available_permissions($wanted, $available));
  }

  /**
   * Nothing available means nothing granted — and no notice for the empty
   * array_flip() on the way.
   */
  public function testNothingIsGrantedOnAnEmptySite() {
    $this->assertSame(
      array(),
      myapi_building_admin_filter_available_permissions(myapi_building_admin_permissions(FALSE), array())
    );
  }

  /* -------------------------------------------------------------------------
   * Guard 2 of both query alters —
   * myapi_building_admin_query_base_table_alias().
   *
   * The shape is the one $query->getTables() returns: alias => array with
   * 'table', 'alias' and 'join type', the base table first and with a NULL
   * join type, exactly as db_select() registers it in its constructor.
   * ---------------------------------------------------------------------- */

  /**
   * Builds a getTables() result: the first entry is the base table.
   *
   * @param string $base  Base table name.
   * @param array $joins  Joined tables as alias => table name.
   */
  private function tables($base, array $joins = array(), $base_alias = NULL) {
    $base_alias = $base_alias === NULL ? $base : $base_alias;

    $tables = array(
      $base_alias => array('join type' => NULL, 'table' => $base, 'alias' => $base_alias),
    );

    foreach ($joins as $alias => $table) {
      $tables[$alias] = array('join type' => 'LEFT OUTER', 'table' => $table, 'alias' => $alias);
    }

    return $tables;
  }

  /**
   * The base table is found, under its own alias.
   */
  public function testBaseTableAliasIsReturned() {
    $this->assertSame('node', myapi_building_admin_query_base_table_alias($this->tables('node'), 'node'));
    $this->assertSame('users', myapi_building_admin_query_base_table_alias($this->tables('users'), 'users'));
  }

  /**
   * An alias other than the table name — myapi_claims_list_rows() builds its
   * query as db_select('node', 'n') — is returned as it is.
   */
  public function testBaseTableAliasIsNotAssumedToBeTheTableName() {
    $tables = $this->tables('node', array('nc' => 'node'), 'n');

    $this->assertSame('n', myapi_building_admin_query_base_table_alias($tables, 'node'));
  }

  /**
   * GUARD (SPEC 72): a JOINED table is never mistaken for the base one.
   *
   * This is the bug that emptied /admin/content. Views adds the access tag of
   * every relationship's base table, so the content listing carries the
   * 'user_access' tag because of its relationship to the author; matching the
   * joined 'users' alias landed the people filter on the node's uid and hid
   * every row from a building admin. If this test ever passes an alias back,
   * that listing is empty again.
   */
  public function testJoinedTableIsNeverTakenForTheBaseTable() {
    $content_listing = $this->tables('node', array(
      'users_node' => 'users',
      'taxonomy_index' => 'taxonomy_index',
    ));

    $this->assertNull(myapi_building_admin_query_base_table_alias($content_listing, 'users'));
    $this->assertSame('node', myapi_building_admin_query_base_table_alias($content_listing, 'node'));
  }

  /**
   * The mirror case: a listing of people that joins content is not narrowed by
   * the content filter either.
   */
  public function testTheMirrorCaseIsGuardedToo() {
    $people_listing = $this->tables('users', array('node_users' => 'node'));

    $this->assertNull(myapi_building_admin_query_base_table_alias($people_listing, 'node'));
    $this->assertSame('users', myapi_building_admin_query_base_table_alias($people_listing, 'users'));
  }

  /**
   * A base table that is a subquery object fails closed: NULL, so the alter
   * leaves the query untouched instead of building SQL against an alias whose
   * columns it knows nothing about.
   */
  public function testASubqueryBaseTableFailsClosed() {
    $tables = array(
      'subquery' => array('join type' => NULL, 'table' => new stdClass(), 'alias' => 'subquery'),
    );

    $this->assertNull(myapi_building_admin_query_base_table_alias($tables, 'node'));
  }

  /**
   * No tables at all, and a table list carrying only joins, are both NULL and
   * neither raises a notice.
   */
  public function testAnEmptyOrJoinOnlyTableListIsNull() {
    $this->assertNull(myapi_building_admin_query_base_table_alias(array(), 'node'));

    $joins_only = array(
      'users_node' => array('join type' => 'INNER', 'table' => 'users', 'alias' => 'users_node'),
    );

    $this->assertNull(myapi_building_admin_query_base_table_alias($joins_only, 'users'));
  }
}
