<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.building_admin.inc';

/**
 * Unit tests for the 'administrador edificio' role (SPEC 49), whose catalogues
 * and pure logic live in includes/myapi.building_admin.inc.
 *
 * Covers the five decidable pieces of the spec, all of them free of Drupal:
 *   - the four catalogues (editable types, visible types, permissions, map);
 *   - myapi_building_admin_resolve_condominium() — the three modes of the map;
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
  }

  /**
   * The claims bundle joins the editable types only once it exists on the
   * site; until then the catalogue must not name it.
   */
  public function testEditableTypesWithTheClaimsBundle() {
    $types = myapi_building_admin_editable_types(TRUE);

    $this->assertContains(MYAPI_BUILDING_ADMIN_CLAIM_TYPE, $types);
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
   * unreachable: no /admin/content and no admin theme on the node forms.
   */
  public function testPermissionsIncludeTheBackOfficeAccess() {
    $permissions = myapi_building_admin_permissions(FALSE);

    foreach (array(
      'access content',
      'access content overview',
      'access administration pages',
      'view the administration theme',
    ) as $permission) {
      $this->assertContains($permission, $permissions);
    }
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
   * The map covers exactly the nine types of the data model, each with the
   * declared mode and field. A tenth entry, or a changed field name, is a
   * change to the access rule and must be a deliberate edit here too.
   */
  public function testCondominiumMapCoversTheNineDeclaredTypes() {
    $expected = array(
      'condominio'     => array('mode' => 'self'),
      'boletin'        => array('mode' => 'direct',   'field' => 'field_condominio'),
      'gastos'         => array('mode' => 'direct',   'field' => 'field_condominio'),
      'vivienda'       => array('mode' => 'direct',   'field' => 'field_condominio'),
      'area'           => array('mode' => 'direct',   'field' => 'field_condominium'),
      'reservation'    => array('mode' => 'direct',   'field' => 'field_condominium'),
      'pagos'          => array('mode' => 'via_unit', 'field' => 'field_vivienda'),
      'recibo'         => array('mode' => 'via_unit', 'field' => 'field_vivienda'),
      'alicuota_extra' => array('mode' => 'via_unit', 'field' => 'field_vivienda'),
    );

    $map = myapi_building_admin_condominium_map();

    $this->assertCount(9, $map);
    $this->assertSame(array_keys($expected), array_keys($map));
    foreach ($expected as $type => $entry) {
      $this->assertSame($entry, $map[$type], 'Map entry for ' . $type);
    }
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
}
