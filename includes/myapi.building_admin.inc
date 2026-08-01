<?php

/**
 * @file
 * Catalogues and helpers for the 'administrador edificio' role (SPEC 49).
 *
 * This role is BACK OFFICE ONLY. Nothing here is reachable from an
 * api/v1/... endpoint, and no file under resources/ calls into it: the role
 * exists so a building operator can create bulletins, areas and reservations
 * from the Drupal admin UI while seeing only the condominiums assigned to
 * them.
 *
 * This file is the single source of truth for four catalogues — the editable
 * types, the visible types, the granted permissions and the map that says how
 * each content type resolves its condominium. myapi.install grants the
 * permissions from here, myapi.module decides node access and alters the
 * node_access-tagged queries from here, and the bulletin validation reads its
 * rule from here. Adding a content type to the role is meant to be an edit to
 * one of the four functions below and nothing else.
 *
 * The role is always matched BY NAME, never by rid: a rid is assigned per
 * environment, so production's 5 may well be a different role locally. Same
 * criterion as myapi_calendar_admin_roles() with 'administrator' / 'backend'.
 *
 * Split between pure and Drupal-facing code, which is what makes
 * tests/unit/BuildingAdminTest.php possible with no site booted:
 * - The "pure helpers" block below takes plain values and returns plain
 *   values. No database, no globals, no Drupal calls.
 * - The "Drupal-facing helpers" block is thin wrappers around them:
 *   user_role_load_by_name(), user_load(), node_load(), node_type_load().
 *
 * The catalogue functions sit in between: they accept an explicit
 * $include_claim_type argument (pure when passed) and only fall back to
 * node_type_load() when it is omitted.
 */

// Literal role name, lower case, as agreed with the client. Compared by name
// everywhere; the rid never enters the logic.
//
// Every define() here is guarded so a PHPUnit require_once of this file next
// to one that already defined it does not emit a redefinition notice (same
// pattern as MYAPI_TIME_FORMAT_PATTERN in includes/myapi.time_format.inc).
if (!defined('MYAPI_BUILDING_ADMIN_ROLE')) {
  define('MYAPI_BUILDING_ADMIN_ROLE', 'administrador edificio');
}

// User field holding the condominiums a building admin may administer.
// entityreference to 'condominio' nodes, unlimited cardinality, instance on
// the 'user' bundle. Created by _myapi_building_admin_install().
if (!defined('MYAPI_BUILDING_ADMIN_CONDO_FIELD')) {
  define('MYAPI_BUILDING_ADMIN_CONDO_FIELD', 'field_condominio_admin');
}

// Provisional machine name of the claims-and-suggestions bundle, which does
// NOT exist yet (see the spec's out-of-scope list). Isolated in a constant and
// always guarded by node_type_load(): if the bundle never arrives, or arrives
// under another name, nothing breaks and the fix is this one line.
if (!defined('MYAPI_BUILDING_ADMIN_CLAIM_TYPE')) {
  define('MYAPI_BUILDING_ADMIN_CLAIM_TYPE', 'reclamo');
}

// The only field_tipo_de_boletin value this role may publish. That field is a
// pre-existing site field, not managed by this module, so the comparison is
// against this literal and the validation skips itself when the field is
// missing from the bundle (see myapi_building_admin_bulletin_errors()).
if (!defined('MYAPI_BUILDING_ADMIN_BULLETIN_SCOPE')) {
  define('MYAPI_BUILDING_ADMIN_BULLETIN_SCOPE', 'Condominio');
}

// Text format the role is allowed to write with. Without one, every formatted
// field it can edit — the bulletin body, the area notes — comes up DISABLED
// with "no tiene permisos suficientes para editarlo": Drupal only lets a user
// through the fallback format (plain_text) unless a 'use text format ...'
// permission says otherwise.
//
// 'filtered_html' and not 'full_html': the latter allows arbitrary HTML and the
// permission is site-wide, not per field. A format machine name is site
// configuration, so this is the one place in the module that names one — and
// the installer drops the permission silently if the format does not exist
// (see myapi_building_admin_filter_available_permissions()).
if (!defined('MYAPI_BUILDING_ADMIN_TEXT_FORMAT')) {
  define('MYAPI_BUILDING_ADMIN_TEXT_FORMAT', 'filtered_html');
}

// The permission that lets the role read people at all (SPEC 51). Named here
// rather than written twice because myapi_requirements() in myapi.install has
// to ask whether this very permission is still granted, and the warning it
// raises would be worthless if the two strings could drift apart.
if (!defined('MYAPI_BUILDING_ADMIN_USER_PERMISSION')) {
  define('MYAPI_BUILDING_ADMIN_USER_PERMISSION', 'access user profiles');
}

/* ---------------------------------------------------------------------------
 * Catalogues.
 *
 * Pure when $include_claim_type is passed explicitly; they only touch Drupal
 * (node_type_load) when it is omitted.
 * ------------------------------------------------------------------------ */

/**
 * The content types the role may create and edit.
 *
 * 'condominio' is deliberately NOT here: the role sees its condominiums (see
 * myapi_building_admin_visible_types()) but never edits them.
 *
 * @param bool|null $include_claim_type
 *   TRUE/FALSE to force whether the claims bundle is included. NULL (the
 *   default) resolves it with node_type_load(), which is what callers inside
 *   Drupal want.
 *
 * @return array
 *   List of node type machine names.
 */
function myapi_building_admin_editable_types($include_claim_type = NULL) {
  if ($include_claim_type === NULL) {
    $include_claim_type = (bool) node_type_load(MYAPI_BUILDING_ADMIN_CLAIM_TYPE);
  }

  $types = ['boletin', 'reservation', 'area'];

  if ($include_claim_type) {
    $types[] = MYAPI_BUILDING_ADMIN_CLAIM_TYPE;
  }

  return $types;
}

/**
 * The content types the role may READ but never create nor edit.
 *
 * Kept apart from myapi_building_admin_editable_types() on purpose: nothing
 * here ever reaches myapi_building_admin_permissions(), so a type listed below
 * gets no 'create' and no 'edit any' — the only way it could become editable is
 * by moving it to the other catalogue, which is a deliberate edit and not an
 * oversight. A unit test pins the two lists apart.
 *
 * - 'condominio' is not a convenience: entityreference autocompletes query with
 *   the 'node_access' tag, so leaving it out of the visible set would empty the
 *   field_condominio selector of the bulletin form and the field_condominium
 *   selector of the area and reservation forms — the role would not be able to
 *   create anything. The wanted side effect is that those selectors only offer
 *   the assigned condominiums.
 * - 'vivienda' was added after SPEC 49 was approved, on the client's call: the
 *   building operator needs to consult the units of their building — and who
 *   lives in them — without being able to touch them. It reverses one of the
 *   spec's acceptance criteria; see the note in specs/roles/49-building-admin-role.md.
 *
 * Both are scoped by the condominium map exactly like the editable types, so a
 * 'vivienda' of another building stays invisible and answers 403.
 *
 * @return array
 *   List of node type machine names.
 */
function myapi_building_admin_readonly_types() {
  return ['condominio', 'vivienda'];
}

/**
 * The content types the role may see in listings and autocompletes.
 *
 * The editable ones plus the read-only ones. This is the list the query alter
 * narrows every node_access-tagged query to.
 *
 * @param bool|null $include_claim_type
 *   See myapi_building_admin_editable_types().
 *
 * @return array
 *   List of node type machine names.
 */
function myapi_building_admin_visible_types($include_claim_type = NULL) {
  return array_merge(
    myapi_building_admin_editable_types($include_claim_type),
    myapi_building_admin_readonly_types()
  );
}

/**
 * The permissions granted to the role, already expanded per content type.
 *
 * NEVER contains a 'delete any ...' nor a 'delete own ...' permission, and a
 * unit test fails if one ever sneaks in. The role does not delete content: a
 * reservation is taken down by cancelling it (SPEC 36 / 47) and a bulletin or
 * an area by unpublishing it from the edit form — both reversible, a delete is
 * not.
 *
 * The list is intentionally wider than what a given site can grant: the
 * installer crosses it against the permissions that actually exist (see
 * myapi_building_admin_filter_available_permissions()), so 'create reclamo
 * content' with no claims bundle, or 'access toolbar' with the toolbar module
 * disabled, are dropped silently instead of writing a dead row in
 * role_permission.
 *
 * @param bool|null $include_claim_type
 *   See myapi_building_admin_editable_types().
 *
 * @return array
 *   List of permission names.
 */
function myapi_building_admin_permissions($include_claim_type = NULL) {
  $permissions = [];

  foreach (myapi_building_admin_editable_types($include_claim_type) as $type) {
    $permissions[] = 'create ' . $type . ' content';
    $permissions[] = 'edit any ' . $type . ' content';
  }

  // Back office access. Without these the role holds content permissions it
  // cannot reach: no /admin/content and no way into the administration pages.
  //
  // Two permissions were in this list when SPEC 49 was approved and were taken
  // out afterwards, both on the client's call:
  //
  // - 'access toolbar' — the role navigates through its own sidebar menu,
  //   restricted to what it manages, and Drupal's black toolbar offered it
  //   'Estructura' and 'Configuración' entries it can only get a 403 from.
  // - 'view the administration theme' — with it, node forms and /admin/content
  //   rendered in Seven while every other operator of this site sees them in
  //   the site theme. The role is meant to work exactly like 'backend' does,
  //   sidebar menu included, and Seven hides that sidebar because blocks are
  //   placed per theme.
  //
  // Removing a permission from this catalogue does NOT revoke it on a site that
  // already has it — the installer never revokes. 'view the administration
  // theme' is revoked once by myapi_update_7015(); 'access toolbar' still needs
  // it by hand:
  //   drush role-remove-perm "administrador edificio" "access toolbar"
  $permissions[] = 'access content';
  $permissions[] = 'access content overview';
  // Reading people (SPEC 51). Site-wide by nature — Drupal has no per-scope
  // variant — and narrowed to the residents of the assigned condominiums by the
  // two halves of the filter in includes/myapi.building_admin_user.inc. Without
  // it the role loses the field_requester autocomplete of the reservation form
  // and every profile page, which myapi_requirements() reports as a warning at
  // /admin/reports/status rather than granting it back behind anyone's back.
  //
  // 'administer users' is NOT here and must never be: besides being account
  // administration the role has no business with, holding it switches the
  // people filter OFF for whoever has it. See
  // myapi_building_admin_user_filter_applies().
  $permissions[] = MYAPI_BUILDING_ADMIN_USER_PERMISSION;
  $permissions[] = 'access administration pages';

  // Writing. A role with no 'use text format ...' permission only reaches the
  // fallback format, so every formatted field it may edit renders disabled —
  // which is how this was found: field_area_notes came up blocked on
  // node/add/area. See MYAPI_BUILDING_ADMIN_TEXT_FORMAT.
  $permissions[] = 'use text format ' . MYAPI_BUILDING_ADMIN_TEXT_FORMAT;

  return $permissions;
}

/**
 * The roles allowed to see and edit field_condominio_admin.
 *
 * That field is the key to the whole feature: whoever can write it decides
 * which condominiums a building admin reaches. So it is NOT self-service —
 * a building admin must not be able to widen their own assignment, and editing
 * one's own account needs no permission in Drupal.
 *
 * Names, never rids, like everywhere else here. Deliberately NOT reusing
 * myapi_calendar_admin_roles(): that list grew to include the building-admin
 * role itself in SPEC 49, which is precisely who must be kept out of this one.
 *
 * @return array
 *   Role names.
 */
function myapi_building_admin_assigner_roles() {
  return ['administrator', 'backend'];
}

/**
 * Decides whether a set of roles may manage the condominium assignment.
 *
 * uid 1 is let in explicitly, the same guard myapi_calendar_access() carries
 * and for the same reason: Drupal's superuser bypass lives inside
 * user_access(), which hook_field_access() never calls, so without this the
 * site owner could be locked out of assigning condominiums on their own site.
 *
 * @param array $role_names
 *   Role names of the account, as the values of $account->roles.
 * @param bool $is_superuser
 *   TRUE when the account is uid 1.
 *
 * @return bool
 *   TRUE when the account may see and edit the field.
 */
function myapi_building_admin_may_manage_assignment(array $role_names, $is_superuser = FALSE) {
  if ($is_superuser) {
    return TRUE;
  }

  return (bool) array_intersect($role_names, myapi_building_admin_assigner_roles());
}

/**
 * How the condominium of each content type is resolved.
 *
 * Three modes:
 * - 'self'     — the node IS the condominium; its own nid is the answer.
 * - 'direct'   — an entityreference field on the node points at it.
 * - 'via_unit' — the node points at a 'vivienda', whose field_condominio
 *                points at it. Two hops.
 *
 * A type that is not in this map is OUT of the access rule entirely:
 * myapi_building_admin_node_access() returns NODE_ACCESS_IGNORE for it and the
 * rest of Drupal decides, exactly as for any other user.
 *
 * @return array
 *   Node type machine name => ['mode' => ..., 'field' => ...]. The 'field' key
 *   is absent for the 'self' mode.
 */
function myapi_building_admin_condominium_map() {
  return [
    'condominio'     => ['mode' => 'self'],
    'boletin'        => ['mode' => 'direct',   'field' => 'field_condominio'],
    'gastos'         => ['mode' => 'direct',   'field' => 'field_condominio'],
    'vivienda'       => ['mode' => 'direct',   'field' => 'field_condominio'],
    'area'           => ['mode' => 'direct',   'field' => 'field_condominium'],
    'reservation'    => ['mode' => 'direct',   'field' => 'field_condominium'],
    'pagos'          => ['mode' => 'via_unit', 'field' => 'field_vivienda'],
    'recibo'         => ['mode' => 'via_unit', 'field' => 'field_vivienda'],
    'alicuota_extra' => ['mode' => 'via_unit', 'field' => 'field_vivienda'],
    // SPEC 55. claim_transaction is deliberately NOT here: its condominium is
    // only resolvable by hopping field_claim -> reclamo -> field_condominium,
    // and no mode above supports that hop yet.
    MYAPI_BUILDING_ADMIN_CLAIM_TYPE => ['mode' => 'direct', 'field' => 'field_condominium'],
  ];
}

/* ---------------------------------------------------------------------------
 * Pure helpers.
 *
 * No database, no Drupal, no globals. Everything below takes plain values and
 * returns plain values, so tests/unit/BuildingAdminTest.php can require this
 * file and exercise them directly.
 * ------------------------------------------------------------------------ */

/**
 * Tells whether a list of role names contains the building-admin role.
 *
 * $account->roles is rid => name in Drupal 7, so the caller hands over the
 * values and no rid ever enters the logic.
 *
 * @param array $role_names
 *   Role names, as the values of $account->roles.
 *
 * @return bool
 *   TRUE when the role is among them.
 */
function myapi_building_admin_roles_match(array $role_names) {
  return in_array(MYAPI_BUILDING_ADMIN_ROLE, $role_names, TRUE);
}

/**
 * Reads the first target_id of an entity-reference field on an entity.
 *
 * Walks langcode and delta instead of reading [LANGUAGE_NONE][0] directly, for
 * the same reason myapi_area_validate_times() does: with a direct read, a
 * change in language handling would make the resolution return NULL silently
 * and open access instead of failing loudly. It also keeps this helper pure —
 * LANGUAGE_NONE is a Drupal constant and this block must not need Drupal.
 *
 * @param object $entity
 *   Node or user object.
 * @param string $field_name
 *   Field machine name (e.g. 'field_condominio').
 *
 * @return int|null
 *   The referenced entity id, or NULL when the field is absent or empty.
 */
function myapi_building_admin_field_target_id($entity, $field_name) {
  $ids = myapi_building_admin_field_target_ids($entity, $field_name);

  return $ids ? $ids[0] : NULL;
}

/**
 * Reads every target_id of a multi-value entity-reference field on an entity.
 *
 * Used for the user's field_condominio_admin, which is unlimited cardinality.
 * Duplicates are collapsed and the keys are renumbered, so the caller always
 * gets a plain list.
 *
 * @param object $entity
 *   Node or user object.
 * @param string $field_name
 *   Field machine name.
 *
 * @return array
 *   List of referenced entity ids as integers; empty when the field is absent
 *   or empty. Never NULL, so callers can foreach without a guard.
 */
function myapi_building_admin_field_target_ids($entity, $field_name) {
  $ids = [];

  if (!is_object($entity) || empty($entity->{$field_name}) || !is_array($entity->{$field_name})) {
    return $ids;
  }

  foreach ($entity->{$field_name} as $items) {
    if (!is_array($items)) {
      continue;
    }
    foreach ($items as $item) {
      if (is_array($item) && isset($item['target_id'])) {
        $ids[] = (int) $item['target_id'];
      }
    }
  }

  return array_values(array_unique($ids));
}

/**
 * Reads the first scalar 'value' of a field on an entity.
 *
 * Same langcode/delta walk as myapi_building_admin_field_target_ids(), for the
 * same reasons. Used for field_tipo_de_boletin, whose items carry 'value'
 * rather than 'target_id'.
 *
 * @param object $entity
 *   Node or user object. On hook_node_validate() this is the pseudo-node
 *   Drupal builds from $form_state['values'], so the structure is the raw form
 *   one.
 * @param string $field_name
 *   Field machine name.
 *
 * @return mixed|null
 *   The stored value, or NULL when the field is absent or empty.
 */
function myapi_building_admin_field_value($entity, $field_name) {
  if (!is_object($entity) || empty($entity->{$field_name}) || !is_array($entity->{$field_name})) {
    return NULL;
  }

  foreach ($entity->{$field_name} as $items) {
    if (!is_array($items)) {
      continue;
    }
    foreach ($items as $item) {
      if (is_array($item) && isset($item['value'])) {
        return $item['value'];
      }
    }
  }

  return NULL;
}

/**
 * Resolves the condominium of a node by applying the map.
 *
 * The 'via_unit' hop is the only one that needs to load another node, and that
 * load is injected as a callable so the function stays pure and testable: the
 * production caller passes 'node_load', a test passes a closure over its own
 * fixtures.
 *
 * Returns NULL whenever the answer cannot be established — type outside the
 * map, empty or absent field, unloadable unit. NULL is not an error: it means
 * "this node is out of the rule", and the caller turns it into
 * NODE_ACCESS_IGNORE. Never emits a PHP notice on a half-filled node.
 *
 * @param object $node
 *   The node to resolve. Needs at least ->type, plus ->nid for the 'self' mode.
 * @param callable|null $unit_loader
 *   Called as $unit_loader($nid) and expected to return a node object or a
 *   falsy value. Only used by the 'via_unit' mode; NULL disables that mode and
 *   makes it resolve to NULL.
 *
 * @return int|null
 *   The condominium nid, or NULL when it cannot be resolved.
 */
function myapi_building_admin_resolve_condominium($node, $unit_loader = NULL) {
  if (!is_object($node) || empty($node->type)) {
    return NULL;
  }

  $map = myapi_building_admin_condominium_map();

  if (!isset($map[$node->type])) {
    return NULL;
  }

  $entry = $map[$node->type];

  switch ($entry['mode']) {
    case 'self':
      return empty($node->nid) ? NULL : (int) $node->nid;

    case 'direct':
      return myapi_building_admin_field_target_id($node, $entry['field']);

    case 'via_unit':
      $unit_nid = myapi_building_admin_field_target_id($node, $entry['field']);
      if (!$unit_nid || !is_callable($unit_loader)) {
        return NULL;
      }
      $unit = call_user_func($unit_loader, $unit_nid);
      if (!is_object($unit)) {
        return NULL;
      }
      // The unit's own condominium field, taken from the map rather than
      // hard-coded, so 'vivienda' is declared in one place only.
      return myapi_building_admin_field_target_id($unit, $map['vivienda']['field']);
  }

  return NULL;
}

/**
 * Decides access for one node of the role, given the resolved condominium.
 *
 * The whole rule of SPEC 49 in four lines, deliberately returning only 'deny'
 * or 'ignore' and NEVER 'allow': granting here would short-circuit every other
 * check Drupal makes (unpublished nodes, other modules' hooks), turning a
 * condominium filter into a permission escalation.
 *
 * The order of the two first guards is the rule itself:
 * - An unresolved condominium (NULL) leaves the node OUT of the rule.
 * - Once inside the rule, no assigned condominium means no access at all.
 *
 * @param int|null $node_condominium
 *   As returned by myapi_building_admin_resolve_condominium().
 * @param array $assigned
 *   Condominium nids assigned to the user; may be empty.
 *
 * @return string
 *   'deny' or 'ignore'.
 */
function myapi_building_admin_access_decision($node_condominium, array $assigned) {
  if ($node_condominium === NULL) {
    return 'ignore';
  }

  if (!$assigned) {
    return 'deny';
  }

  // Both sides are cast before the strict comparison: nids arrive as ints from
  // the resolver but as strings from a database read, and a strict in_array()
  // over mixed types would deny access to the user's own condominium.
  return in_array((int) $node_condominium, array_map('intval', $assigned), TRUE)
    ? 'ignore'
    : 'deny';
}

/**
 * Validates a bulletin about to be saved by a building admin.
 *
 * Two rules, in this order:
 * - The bulletin must be of scope 'Condominio'. 'General' notifies by push and
 *   mail EVERY condominium of the site — the one action of this role that
 *   reaches outside its own building, and irreversible once sent.
 *   'Personalizado' targets hand-picked recipients across condominiums.
 * - Its condominium must be one of the assigned ones.
 *
 * A NULL $scope means field_tipo_de_boletin is not on the bundle in this
 * environment. The validation then skips itself and returns no errors: that
 * field is a pre-existing site field this module does not own, and blocking
 * every bulletin of the role because of a missing field would be worse than
 * letting it through. The caller logs a watchdog WARNING for that branch.
 *
 * NULL is reserved for that case alone. When the field IS on the bundle but
 * nothing was picked, the caller passes the empty string, which fails the
 * scope check like any other non-'Condominio' value — fail closed, at the cost
 * of a second message next to Drupal's own "field is required".
 *
 * @param string|null $scope
 *   The field_tipo_de_boletin value; '' when nothing was picked, NULL only
 *   when the field is not on the bundle.
 * @param int|null $condominium
 *   The field_condominio target id, or NULL when empty.
 * @param array $assigned
 *   Condominium nids assigned to the user; may be empty.
 *
 * @return array
 *   List of errors, each ['field' => machine name, 'message' => text]. Empty
 *   when the bulletin may be saved. Messages are admin form strings and do NOT
 *   go through myapi_t(), which is the API translation catalogue.
 */
function myapi_building_admin_bulletin_errors($scope, $condominium, array $assigned) {
  $errors = [];

  if ($scope === NULL) {
    return $errors;
  }

  if ($scope !== MYAPI_BUILDING_ADMIN_BULLETIN_SCOPE) {
    $errors[] = [
      'field'   => 'field_tipo_de_boletin',
      'message' => 'Como administrador de edificio solo puede publicar boletines de tipo "'
        . MYAPI_BUILDING_ADMIN_BULLETIN_SCOPE . '".',
    ];

    // The scope is already wrong, so the condominium is not worth a second
    // message on the same submit.
    return $errors;
  }

  if ($condominium === NULL || myapi_building_admin_access_decision($condominium, $assigned) === 'deny') {
    $errors[] = [
      'field'   => 'field_condominio',
      'message' => 'Debe seleccionar uno de los condominios que administra.',
    ];
  }

  return $errors;
}

/**
 * Narrows the field_tipo_de_boletin options to the only scope this role may
 * publish.
 *
 * Pure counterpart of myapi_building_admin_restrict_bulletin_form(): it takes
 * the widget's #options and gives back the subset the building admin is
 * allowed to choose from.
 *
 * The empty '_none' option is preserved when the widget offers it. Dropping it
 * would silently pre-select 'Condominio' on a non-required field and hide from
 * the operator that a choice was made on their behalf; leaving it means the
 * select still asks, but the only real answer is the right one.
 *
 * An options array that turns out not to contain 'Condominio' at all — a site
 * where the allowed values of that pre-existing field were renamed — comes back
 * with just '_none', or empty. That is deliberate: the operator cannot publish
 * anything until somebody looks at it, which is the same fail-closed direction
 * the rest of this feature takes.
 *
 * @param array $options
 *   The widget's #options, key => label.
 *
 * @return array
 *   The subset this role may pick, in the original order.
 */
function myapi_building_admin_filter_bulletin_scope_options(array $options) {
  $allowed = [];

  foreach ($options as $key => $label) {
    if ($key === '_none' || $key === MYAPI_BUILDING_ADMIN_BULLETIN_SCOPE) {
      $allowed[$key] = $label;
    }
  }

  return $allowed;
}

/**
 * Keeps only the permissions that actually exist on this site.
 *
 * Crossed against module_invoke_all('permission') by the installer. Two cases
 * this closes: 'create reclamo content' while the claims bundle does not
 * exist, and 'access toolbar' with the toolbar module disabled. Granting those
 * blind would write rows in role_permission that no module can ever show at
 * /admin/people/permissions nor revoke.
 *
 * @param array $wanted
 *   Permission names the role should get.
 * @param array $available
 *   Permission names that exist on the site — the KEYS of the
 *   module_invoke_all('permission') result, already extracted by the caller.
 *
 * @return array
 *   The intersection, as a plain list in the order of $wanted.
 */
function myapi_building_admin_filter_available_permissions(array $wanted, array $available) {
  $lookup = array_flip($available);

  $granted = [];
  foreach ($wanted as $permission) {
    if (isset($lookup[$permission])) {
      $granted[] = $permission;
    }
  }

  return $granted;
}

/* ---------------------------------------------------------------------------
 * Drupal-facing helpers.
 *
 * Thin wrappers over the pure block: they fetch from Drupal and delegate the
 * decision. Nothing here is unit-tested; it is covered by the manual
 * acceptance criteria of the spec.
 * ------------------------------------------------------------------------ */

/**
 * Tells whether an account holds the 'administrador edificio' role.
 *
 * By name, never by rid. uid 1 does NOT inherit the role: the superuser bypass
 * lives in user_access(), which this never calls, so the site owner keeps
 * seeing everything precisely because they do not hold this role.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return bool
 *   TRUE when the account holds the role.
 */
function myapi_building_admin_is($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  if (!is_object($account) || empty($account->roles) || !is_array($account->roles)) {
    return FALSE;
  }

  return myapi_building_admin_roles_match(array_values($account->roles));
}

/**
 * Whether an account may see and edit field_condominio_admin.
 *
 * Called from myapi_field_access() for that one field only. Denying it hides
 * the field from the user form AND from the rendered profile, for every role
 * outside myapi_building_admin_assigner_roles() — the building admin's own
 * account included, which is the point: the assignment is granted to them, not
 * chosen by them.
 *
 * Denying 'edit' does NOT wipe the stored value. Drupal's field_attach_form()
 * omits a field the account cannot edit, and field_attach_submit() skips it on
 * save, so an operator editing their own profile keeps their condominiums
 * untouched.
 *
 * It also does not affect the filter itself: myapi_building_admin_condominium_ids()
 * reads the field off the loaded user object and never calls field_access(),
 * which is what keeps the condominium scoping working for an account that
 * cannot see its own assignment.
 *
 * @param object $account
 *   The account the check is made for.
 *
 * @return bool
 *   TRUE when the account may see and edit the field.
 */
function myapi_building_admin_assignment_field_access($account) {
  if (!is_object($account)) {
    return FALSE;
  }

  $roles = (!empty($account->roles) && is_array($account->roles))
    ? array_values($account->roles)
    : [];

  return myapi_building_admin_may_manage_assignment($roles, (int) $account->uid === 1);
}

/**
 * Returns the condominium nids assigned to an account.
 *
 * Statically cached per uid: hook_node_access() runs once per node loaded, so
 * without the cache a listing of 50 nodes would re-read the same user field 50
 * times. The cache lives for the request only.
 *
 * The account is re-loaded with user_load() when the field is not on the
 * object, which is the normal case: the global $user of a page request is a
 * partial object with no fields attached.
 *
 * An empty result is meaningful — a building admin with no condominium
 * assigned sees nothing — so it is cached too.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return array
 *   List of condominium nids; may be empty.
 */
function myapi_building_admin_condominium_ids($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  if (!is_object($account) || empty($account->uid)) {
    return [];
  }

  $cache = &drupal_static(__FUNCTION__, []);
  $uid = (int) $account->uid;

  if (!isset($cache[$uid])) {
    // isset() and not property_exists(): a loaded user with an empty field
    // carries the property as an empty array, which is answer enough.
    if (!isset($account->{MYAPI_BUILDING_ADMIN_CONDO_FIELD})) {
      $account = user_load($uid);
    }
    $cache[$uid] = $account
      ? myapi_building_admin_field_target_ids($account, MYAPI_BUILDING_ADMIN_CONDO_FIELD)
      : [];
  }

  return $cache[$uid];
}

/**
 * Returns the condominium nid of a node, or NULL when it cannot be resolved.
 *
 * Statically cached per nid, for the same reason as the helper above: a
 * 'via_unit' node costs one extra node_load(), and the same payment node is
 * easily asked about more than once in a request.
 *
 * Unsaved nodes (no nid, as on node/add before the first save) are resolved
 * but not cached — there is no key to cache them under.
 *
 * @param object $node
 *   The node to resolve.
 *
 * @return int|null
 *   The condominium nid, or NULL.
 */
function myapi_building_admin_node_condominium($node) {
  if (!is_object($node)) {
    return NULL;
  }

  if (empty($node->nid)) {
    return myapi_building_admin_resolve_condominium($node, 'node_load');
  }

  $cache = &drupal_static(__FUNCTION__, []);
  $nid = (int) $node->nid;

  if (!array_key_exists($nid, $cache)) {
    $cache[$nid] = myapi_building_admin_resolve_condominium($node, 'node_load');
  }

  return $cache[$nid];
}

/**
 * Removes 'General' and 'Personalizado' from the bulletin form for this role.
 *
 * Called from myapi_form_boletin_node_form_alter(). Cosmetic in the strict
 * sense — myapi_building_admin_validate_bulletin() already refuses those two
 * scopes — but the right kind of cosmetic: an option that cannot be used should
 * not be offered, and rejecting a choice after the fact reads as a bug to the
 * operator who made it.
 *
 * The two layers are deliberately kept: this one shapes the form, the
 * validation guards the submission. A hand-crafted POST bypasses the first and
 * still hits the second, and if the widget ever changes to a shape this
 * function does not recognise, the worst case is the old behaviour — the option
 * shows and the validation rejects it. Nothing opens up.
 *
 * Walks langcode by langcode instead of assuming LANGUAGE_NONE, the same
 * reasoning as everywhere else in this file.
 *
 * @param array $form
 *   The node form, by reference.
 * @param array $form_state
 *   The form state, by reference. Unused; kept to mirror the hook's signature.
 */
function myapi_building_admin_restrict_bulletin_form(&$form, &$form_state) {
  if (!myapi_building_admin_is()) {
    return;
  }

  if (empty($form['field_tipo_de_boletin']) || !is_array($form['field_tipo_de_boletin'])) {
    return;
  }

  foreach (element_children($form['field_tipo_de_boletin']) as $langcode) {
    if (!isset($form['field_tipo_de_boletin'][$langcode]['#options'])
      || !is_array($form['field_tipo_de_boletin'][$langcode]['#options'])) {
      continue;
    }

    $form['field_tipo_de_boletin'][$langcode]['#options'] =
      myapi_building_admin_filter_bulletin_scope_options(
        $form['field_tipo_de_boletin'][$langcode]['#options']
      );
  }
}

/**
 * Validates a 'boletin' node form submitted by a building admin.
 *
 * Called from myapi_node_validate() for 'boletin' nodes only. Everyone else —
 * administrators included — passes straight through on the first line, so no
 * existing editor gains a new obstacle.
 *
 * The rule and its two messages live in the pure
 * myapi_building_admin_bulletin_errors(); this wrapper only fetches the three
 * inputs and turns the result into form errors.
 *
 * The $node it receives is NOT a stored node: node_form_validate() builds a
 * pseudo-entity out of $form_state['values'], which is exactly what must be
 * checked — the values about to be saved. entityreference has already turned
 * the autocomplete text into a 'target_id' by then, because element-level
 * validation runs before this form-level handler.
 *
 * Missing-field branch: if field_tipo_de_boletin has no instance on the
 * 'boletin' bundle in this environment — it is a pre-existing site field this
 * module does not own — the validation skips itself and logs a WARNING rather
 * than blocking every bulletin of the role.
 *
 * form_set_error() gets the plain field name and not the full
 * 'field][langcode][delta][value' path that myapi_area_validate_times() uses:
 * the error is about the field as a whole (a wrong choice, a missing
 * condominium), not about one delta of a multi-value widget.
 *
 * @param object $node
 *   The pseudo-node built from the submitted values.
 * @param array $form
 *   The node form. Unused; kept to mirror the hook's signature.
 * @param array $form_state
 *   The form state, by reference. Unused for the same reason.
 */
function myapi_building_admin_validate_bulletin($node, $form, &$form_state) {
  if (!myapi_building_admin_is()) {
    return;
  }

  if (!field_info_instance('node', 'field_tipo_de_boletin', 'boletin')) {
    watchdog(
      'myapi',
      'SPEC 49: field_tipo_de_boletin has no instance on the "boletin" bundle; the building-admin scope validation was skipped.',
      [],
      WATCHDOG_WARNING
    );
    return;
  }

  // The instance exists, so an empty pick is a real empty pick and not a
  // missing field: '' rather than NULL, which is what keeps it failing the
  // scope check. See myapi_building_admin_bulletin_errors().
  $scope = myapi_building_admin_field_value($node, 'field_tipo_de_boletin');
  if ($scope === NULL) {
    $scope = '';
  }

  $errors = myapi_building_admin_bulletin_errors(
    $scope,
    myapi_building_admin_field_target_id($node, 'field_condominio'),
    myapi_building_admin_condominium_ids()
  );

  foreach ($errors as $error) {
    form_set_error($error['field'], $error['message']);
  }
}

/**
 * LEFT JOINs a field data table once, and returns its alias.
 *
 * The same table can be needed twice in one query with DIFFERENT join
 * conditions — field_data_field_condominio hangs off the node for a 'boletin'
 * and off the referenced 'vivienda' for a payment — so the reuse key is the
 * pair (field, left-hand expression), not the field alone.
 *
 * Both the table name and every expression fed to this helper are built from
 * the catalogues of this file. No request data reaches the SQL string; the
 * assigned condominium ids, which DO come from the database, travel as bound
 * arguments in the caller's condition().
 *
 * @param SelectQueryInterface $query
 *   The query being altered.
 * @param string $field
 *   Field machine name, e.g. 'field_condominio'.
 * @param string $left_expression
 *   What the field's entity_id must equal, e.g. 'node.nid' or another field
 *   table's target_id column.
 * @param array $joined
 *   Reuse registry, passed by reference across the calls of one alter.
 *
 * @return string
 *   The alias of the joined table.
 */
function myapi_building_admin_join_field($query, $field, $left_expression, array &$joined) {
  $key = $field . '|' . $left_expression;

  if (!isset($joined[$key])) {
    $joined[$key] = $query->leftJoin(
      'field_data_' . $field,
      NULL,
      '%alias.entity_id = ' . $left_expression . " AND %alias.entity_type = 'node' AND %alias.deleted = 0"
    );
  }

  return $joined[$key];
}

/**
 * Narrows a node_access-tagged query to what a building admin may see.
 *
 * The listing half of the SPEC 49 filter, called from
 * myapi_query_node_access_alter(). It reaches /admin/content, but also EVERY
 * other query carrying that tag — blocks, search, other modules' views and,
 * crucially, the entityreference autocompletes. Narrowing those last ones is
 * the wanted behaviour: it is what makes the condominium selector of the
 * bulletin, area and reservation forms offer the assigned condominiums and
 * only those.
 *
 * Three guards run before anything is touched, in this order:
 * 1. The current user holds the role. Everyone else leaves immediately, so the
 *    cost for the rest of the site is one function call.
 * 2. The query really selects from 'node'. The tag does NOT imply it: other
 *    modules apply it to taxonomy, search or non-node entity queries. Without
 *    this check the JOIN and the conditions would land on a query with no such
 *    column and break unrelated pages with an SQL error.
 * 3. The user has at least one condominium assigned. With none, '1 = 0' ends
 *    it: the role sees nothing, which is the documented meaning of an empty
 *    assignment.
 *
 * Past the guards the query gets `type IN (visible types)` AND an OR group
 * with one branch per visible type, each pinning that type to the assigned
 * condominiums through the mode declared in
 * myapi_building_admin_condominium_map().
 *
 * Two fail-closed branches worth knowing about:
 * - A visible type whose condominium field does not exist in this environment
 *   contributes no branch, so its nodes disappear from the listings rather
 *   than showing unfiltered.
 * - If that leaves no branch at all, the whole query is closed with '1 = 0'
 *   instead of falling back to an unfiltered `type IN (...)`.
 *
 * A visible type that is NOT in the map (the future claims bundle, until
 * whoever creates it adds its entry) is passed through unfiltered, which is
 * the same thing myapi_node_access() does with it: out of the map means out of
 * the rule, in both halves of the filter.
 *
 * @param SelectQueryInterface $query
 *   The query being altered.
 */
function myapi_building_admin_alter_node_query($query) {
  // method_exists() and not `instanceof SelectQueryInterface`: it says exactly
  // what is needed of the object without depending on the class being loaded.
  if (!is_object($query) || !method_exists($query, 'getTables')) {
    return;
  }

  // Guard 1 — the role.
  if (!myapi_building_admin_is()) {
    return;
  }

  // Guard 2 — 'node' among the tables. The 'table' key holds a Select object
  // for a subquery, hence the is_string() before comparing.
  $node_alias = NULL;
  foreach ($query->getTables() as $table) {
    if (isset($table['table']) && is_string($table['table']) && $table['table'] === 'node') {
      $node_alias = $table['alias'];
      break;
    }
  }
  if ($node_alias === NULL) {
    return;
  }

  // Guard 3 — no condominium assigned, nothing visible.
  $assigned = myapi_building_admin_condominium_ids();
  if (!$assigned) {
    $query->where('1 = 0');
    return;
  }

  $visible = myapi_building_admin_visible_types();
  $map = myapi_building_admin_condominium_map();
  $joined = [];
  $branches = db_or();
  $branch_count = 0;

  foreach ($visible as $type) {
    if (!isset($map[$type])) {
      $branches->condition($node_alias . '.type', $type);
      $branch_count++;
      continue;
    }

    $entry = $map[$type];

    if ($entry['mode'] === 'self') {
      $branches->condition(db_and()
        ->condition($node_alias . '.type', $type)
        ->condition($node_alias . '.nid', $assigned, 'IN'));
      $branch_count++;
      continue;
    }

    // field_info_field() is served from the field info cache, so this costs no
    // query. A missing field means no branch: fail closed.
    if (!field_info_field($entry['field'])) {
      continue;
    }

    if ($entry['mode'] === 'direct') {
      $alias = myapi_building_admin_join_field($query, $entry['field'], $node_alias . '.nid', $joined);
      $branches->condition(db_and()
        ->condition($node_alias . '.type', $type)
        ->condition($alias . '.' . $entry['field'] . '_target_id', $assigned, 'IN'));
      $branch_count++;
      continue;
    }

    if ($entry['mode'] === 'via_unit') {
      // Two hops: node -> vivienda -> condominio. The unit's own field comes
      // from the map so 'vivienda' is declared in one place only.
      $unit_field = $map['vivienda']['field'];
      if (!field_info_field($unit_field)) {
        continue;
      }
      $unit_alias = myapi_building_admin_join_field($query, $entry['field'], $node_alias . '.nid', $joined);
      $condo_alias = myapi_building_admin_join_field(
        $query,
        $unit_field,
        $unit_alias . '.' . $entry['field'] . '_target_id',
        $joined
      );
      $branches->condition(db_and()
        ->condition($node_alias . '.type', $type)
        ->condition($condo_alias . '.' . $unit_field . '_target_id', $assigned, 'IN'));
      $branch_count++;
    }
  }

  if (!$branch_count) {
    $query->where('1 = 0');
    return;
  }

  $query->condition($node_alias . '.type', $visible, 'IN');
  $query->condition($branches);
}
