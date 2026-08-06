<?php

/**
 * @file
 * The USER half of the condominium filter of the 'administrador edificio'
 * role (SPEC 51).
 *
 * SPEC 49 scoped the NODES a building operator reaches; this file scopes the
 * PEOPLE. Same shape, two halves:
 * - the direct URL, /user/N, closed by the access callback
 *   myapi_building_admin_user_view_access() that myapi_menu_alter() installs;
 * - the listings and autocompletes, narrowed by
 *   myapi_building_admin_alter_user_query() on the 'user_access' tag —
 *   which is what makes field_requester of the reservation form offer the
 *   residents of the assigned condominiums and nobody else.
 *
 * It lives apart from includes/myapi.building_admin.inc, which is already past
 * a thousand lines and whose axis is the node, because the axis here is the
 * user entity. Nothing is duplicated: the assignment is still read with
 * myapi_building_admin_condominium_ids() and the condominium -> units -> people
 * path is still myapi_condominium_member_uids(), untouched, from
 * includes/myapi.unit_access.inc.
 *
 * The rule in one sentence: a building operator sees the owners and occupants
 * of the units of their assigned condominiums, plus themselves. There is NO
 * exception for 'administrator', for 'backend' nor for another building admin —
 * whoever cannot be traced to an assigned condominium is not visible. The one
 * exception is on the other side of the glass: an operator who ALSO holds
 * 'administer users' is not filtered at all, which is the symmetric counterpart
 * of what 'bypass node access' already does for nodes. That permission is never
 * in myapi_building_admin_permissions(); the exception exists for whoever holds
 * it through another role.
 *
 * Same pure / Drupal-facing split as includes/myapi.building_admin.inc, which
 * is what makes tests/unit/BuildingAdminUserTest.php possible with no site
 * booted.
 *
 * BACK OFFICE ONLY, like the rest of the role: no query under resources/ nor
 * includes/ carries the 'user_access' tag, so no api/v1/... response changes.
 */

/* ---------------------------------------------------------------------------
 * Pure helpers.
 *
 * No database, no Drupal, no globals. Plain values in, plain values out.
 * ------------------------------------------------------------------------ */

/**
 * Tells whether the people filter applies to an operator.
 *
 * The guard of BOTH halves of the filter, in one place: the menu access
 * callback and the query alter ask this same question, so they can never drift
 * apart.
 *
 * 'administer users' switches the filter OFF. Without that exception, an
 * account holding both 'administrator' and this role would see every node of
 * the site — 'bypass node access' cuts in before myapi_node_access() ever runs
 * — but only the people of its condominiums. That asymmetry reads as a bug in
 * the module and costs an afternoon to diagnose. The accepted price is written
 * down in docs/building-admin-role.md: granting 'administer users' to a
 * building admin opens the whole address book to them.
 *
 * @param bool $has_role
 *   TRUE when the account holds the 'administrador edificio' role.
 * @param bool $may_administer_users
 *   TRUE when the account holds the 'administer users' permission.
 *
 * @return bool
 *   TRUE when the account's view of the user entity must be narrowed.
 */
function myapi_building_admin_user_filter_applies($has_role, $may_administer_users) {
  return ((bool) $has_role) && !((bool) $may_administer_users);
}

/**
 * Decides whether an operator may see one user, given the visible set.
 *
 * The whole rule of SPEC 51, and the only place that answers the question.
 * Returns 'allow' or 'deny' and NEVER a third value — a unit test pins that
 * down, because the callers turn anything that is not 'allow' into a 403.
 *
 * One's own account is 'allow' unconditionally, even when it is not in the
 * list. Without that, an operator with no unit of their own would lose "Mi
 * cuenta" and their own edit form, and it opens nothing: seeing one's own
 * profile needs no permission for any other role in Drupal.
 *
 * Everything else that is not in the list is 'deny', with no exception for
 * 'administrator', 'backend' nor another building admin. A rule without
 * exceptions fits in one sentence and does not erode over time.
 *
 * Both sides are cast before comparing: uids arrive as ints from the session
 * and as strings from a database read, and a strict in_array() over mixed types
 * would deny an operator the residents that are genuinely theirs.
 *
 * @param int|string|null $target_uid
 *   The uid being looked at.
 * @param int|string|null $current_uid
 *   The uid of the operator doing the looking.
 * @param array $visible_uids
 *   The uids the operator may see, as returned by
 *   myapi_building_admin_visible_uids(). May be empty.
 *
 * @return string
 *   'allow' or 'deny'.
 */
function myapi_building_admin_user_decision($target_uid, $current_uid, array $visible_uids) {
  $target = (int) $target_uid;

  if ($target === (int) $current_uid) {
    return 'allow';
  }

  return in_array($target, array_map('intval', $visible_uids), TRUE) ? 'allow' : 'deny';
}

/**
 * Validates the two scoped fields of a reservation about to be saved.
 *
 * Closes the gap SPEC 49 left written down: hook_node_access() is never called
 * for 'create', so the node form is the only place where the values ABOUT TO BE
 * SAVED can be checked. The form already offers nothing foreign — the two
 * halves of each filter see to that — so a foreign value can only arrive
 * through a hand-crafted POST. It is still worth closing: the opposite failure
 * mode is the worst one available, a reservation booked for a resident of
 * another building that turns INVISIBLE to the very operator who saved it.
 *
 * Same error shape as myapi_building_admin_bulletin_errors(), and the messages
 * are admin form strings: they do NOT go through myapi_t(), which is the API
 * translation catalogue.
 *
 * @param int|string|null $requester_uid
 *   The field_requester target id, or NULL when the field came in empty.
 * @param array $visible_uids
 *   The uids the operator may see. Always carries at least their own.
 * @param string|null $unit_decision
 *   The verdict the wrapper already got from
 *   myapi_building_admin_access_decision() on the field_unit 'vivienda', or
 *   NULL when field_unit came in empty. Resolved by the caller so this function
 *   stays pure and never has to load a node.
 *
 *   That function speaks 'deny' / 'ignore' — 'ignore' meaning "this node is
 *   outside the rule", which is the verdict every unit of an assigned
 *   condominium gets. 'allow' is accepted as its synonym so the vocabulary of
 *   the spec and the vocabulary of SPEC 49 both work here. Anything else,
 *   NULL included, is an error: fail closed.
 *
 * @return array
 *   List of errors, each ['field' => machine name, 'message' => text]. Empty
 *   when the reservation may be saved.
 */
function myapi_building_admin_reservation_errors($requester_uid, array $visible_uids, $unit_decision) {
  $errors = [];

  // Plain membership, not myapi_building_admin_user_decision(): that function's
  // "yourself is always allowed" branch needs an operator to compare against,
  // and here it would be dead weight — the visible list already carries the
  // operator's own uid, so an operator booking for themselves passes anyway.
  // empty() and not === NULL: uid 0 is the anonymous user and is never a valid
  // requester, so it is rejected like an empty field.
  if (empty($requester_uid)
    || !in_array((int) $requester_uid, array_map('intval', $visible_uids), TRUE)) {
    $errors[] = [
      'field'   => 'field_requester',
      'message' => 'Debe seleccionar a un propietario u ocupante de una vivienda de los condominios que administra.',
    ];
  }

  if ($unit_decision !== 'allow' && $unit_decision !== 'ignore') {
    $errors[] = [
      'field'   => 'field_unit',
      'message' => 'Debe seleccionar una vivienda de los condominios que administra.',
    ];
  }

  return $errors;
}

/* ---------------------------------------------------------------------------
 * Drupal-facing helpers.
 *
 * Thin wrappers over the pure block: they fetch from Drupal and delegate the
 * decision. Nothing here is unit-tested; it is covered by the manual acceptance
 * criteria of the spec.
 *
 * They load the two includes they lean on themselves — includes/ files are not
 * autoloaded in Drupal 7, files[] only feeds the class registry — because the
 * menu access callback below is invoked straight by the menu system and not
 * through the glue in myapi.module. module_load_include() is a require_once, so
 * asking twice costs nothing.
 * ------------------------------------------------------------------------ */

/**
 * Whether the people filter is active for an account.
 *
 * The first guard of both halves. Everybody else — every other role, every API
 * request, the site owner — leaves on this line, so the cost of this feature
 * for the rest of the site is one role lookup.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return bool
 *   TRUE when this account's view of the user entity must be narrowed.
 */
function myapi_building_admin_user_filter_is_active($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  if (!is_object($account) || empty($account->uid)) {
    return FALSE;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  return myapi_building_admin_user_filter_applies(
    myapi_building_admin_is($account),
    user_access('administer users', $account)
  );
}

/**
 * Returns the uids an operator may see.
 *
 * Assigned condominiums -> their units -> owners and occupants, resolved by the
 * functions SPEC 25 already wrote, plus the operator's own uid, which is added
 * ALWAYS. That is why there is no "empty list" special case anywhere: an
 * operator with no condominium assigned gets a list of exactly one uid, their
 * own, and the filter answers "only yourself" instead of "everybody".
 *
 * Statically cached per uid: a single page can carry several 'user_access'
 * queries — one per autocomplete, one per view — and the four chained queries
 * behind this list are worth paying once per request.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return array
 *   Flat list of uids as integers, without duplicates.
 */
function myapi_building_admin_visible_uids($account = NULL) {
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
    module_load_include('inc', 'myapi', 'includes/myapi.building_admin');
    module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

    $assigned = myapi_building_admin_condominium_ids($account);
    $uids = $assigned ? myapi_condominium_member_uids($assigned, 'todos') : [];

    $uids = array_map('intval', $uids);
    $uids[] = $uid;

    $cache[$uid] = array_values(array_unique($uids));
  }

  return $cache[$uid];
}

/**
 * Access callback for user/%user and user/%user/view.
 *
 * Installed by myapi_menu_alter() in place of core's user_view_access(), which
 * it calls FIRST and delegates to: this callback can only take access away,
 * never hand it out. Blocked accounts, anonymous visitors and the
 * 'access user profiles' permission itself are all still decided by core.
 *
 * @param object|null $account
 *   The account whose profile is being opened, as loaded by the %user
 *   wildcard.
 *
 *   It carries a default so that a router item reaching this callback with no
 *   access arguments — which is exactly what a MENU_DEFAULT_LOCAL_TASK does
 *   when an alter cancels its inheritance, see myapi_menu_alter() — denies
 *   access instead of raising an ArgumentCountError on every page that renders
 *   the "Mi cuenta" link. core's user_view_access() reads NULL as uid 0 and
 *   refuses it, so the default fails closed on its own.
 *
 * @return bool
 *   TRUE when the profile may be viewed.
 */
function myapi_building_admin_user_view_access($account = NULL) {
  if (!user_view_access($account)) {
    return FALSE;
  }

  if (!myapi_building_admin_user_filter_is_active()) {
    return TRUE;
  }

  global $user;

  return myapi_building_admin_user_decision(
    is_object($account) ? $account->uid : $account,
    $user->uid,
    myapi_building_admin_visible_uids()
  ) === 'allow';
}

/**
 * Narrows a user_access-tagged query to the people an operator may see.
 *
 * The listing half of the filter, called from myapi_query_user_access_alter().
 * The tag travels on the generic entityreference handler for target_type
 * 'user' — hence field_requester — and on the 'users' base table of Views,
 * which declares it as an access query tag.
 *
 * Two guards, in this order:
 * 1. The filter is active. Everybody else leaves before anything is touched.
 * 2. The query is really ABOUT users, i.e. 'users' is its BASE table. The tag
 *    does NOT imply it, exactly like 'node_access' does not imply 'node':
 *    without this check the condition would land on a query with no such column
 *    and break unrelated pages with an SQL error.
 *
 *    Requiring the BASE table and not merely the presence of 'users' is
 *    SPEC 72, and it repairs a listing this filter emptied. Views adds the
 *    access tag of every relationship's base table
 *    (views_handler_relationship::query()), so /admin/content — whose base
 *    table is 'node' — carries the 'user_access' tag as soon as it has a
 *    relationship to the author. This alter then found the joined 'users' alias
 *    and constrained node.uid, i.e. the AUTHOR of each row, turning the content
 *    listing into "content written by a resident of my condominiums". Since the
 *    condominiums, units and areas of the site are authored by an operator who
 *    is nobody's neighbour, a building admin saw an empty /admin/content for
 *    EVERY content type. The condominium scoping of that listing is, and always
 *    was, myapi_building_admin_alter_node_query()'s job.
 *
 *    Both legitimate consumers of the tag keep working, because in both 'users'
 *    IS the base table: the field_requester autocomplete (EntityFieldQuery over
 *    the user entity) and any Views listing of people, /admin/people included.
 *
 * There is deliberately no third "empty list" guard: the list always carries at
 * least the operator's own uid. See myapi_building_admin_visible_uids().
 *
 * @param SelectQueryInterface $query
 *   The query being altered.
 */
function myapi_building_admin_alter_user_query($query) {
  // method_exists() and not `instanceof SelectQueryInterface`: it says exactly
  // what is needed of the object without depending on the class being loaded.
  if (!is_object($query) || !method_exists($query, 'getTables')) {
    return;
  }

  // Guard 1 — the filter applies to this operator.
  if (!myapi_building_admin_user_filter_is_active()) {
    return;
  }

  // Guard 2 — 'users' is the BASE table, not just one of the joined ones.
  //
  // The include is explicit rather than inherited from guard 1 — which does
  // reach it through myapi_building_admin_user_filter_is_active() — because the
  // cost of being wrong about that is an undefined function on every listing of
  // the site, and it is only paid by an operator holding the role. Same
  // criterion as myapi_building_admin_visible_uids() below.
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $users_alias = myapi_building_admin_query_base_table_alias($query->getTables(), 'users');
  if ($users_alias === NULL) {
    return;
  }

  $query->condition($users_alias . '.uid', myapi_building_admin_visible_uids(), 'IN');
}

/**
 * Validates a 'reservation' node form submitted by a building admin.
 *
 * Called from myapi_node_validate() for 'reservation' nodes only, and every
 * other user — administrators included — leaves on the first line, so no
 * existing editor gains a new obstacle.
 *
 * It guards the two fields SPEC 51 scopes, one per filter:
 * - field_requester, against the visible people of this file;
 * - field_unit, against the assigned condominiums of SPEC 49, reusing
 *   myapi_building_admin_node_condominium() and
 *   myapi_building_admin_access_decision() so no new query is written.
 *
 * The $node it receives is NOT a stored node: node_form_validate() builds a
 * pseudo-entity out of $form_state['values'], which is exactly what has to be
 * checked — the values about to be saved. entityreference has already turned
 * both autocomplete strings into 'target_id' by then, because element-level
 * validation runs before this form-level handler; that same element validation
 * is what keeps field_unit pointing at a 'vivienda' and nothing else.
 *
 * A field_unit whose node cannot be loaded is reported as 'deny' and not as an
 * empty field: something was submitted, it just does not resolve. Fail closed.
 *
 * This hook is only reached from node_form_validate(), so
 * POST /api/v1/reservations is untouched — the endpoint saves its node
 * programmatically and never goes through form validation.
 *
 * @param object $node
 *   The pseudo-node built from the submitted values.
 * @param array $form
 *   The node form. Unused; kept to mirror the hook's signature.
 * @param array $form_state
 *   The form state, by reference. Unused for the same reason.
 */
function myapi_building_admin_validate_reservation($node, $form, &$form_state) {
  if (!myapi_building_admin_user_filter_is_active()) {
    return;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $unit_nid = myapi_building_admin_field_target_id($node, 'field_unit');
  $unit_decision = NULL;
  if ($unit_nid) {
    $unit = node_load($unit_nid);
    $unit_decision = $unit
      ? myapi_building_admin_access_decision(
        myapi_building_admin_node_condominium($unit),
        myapi_building_admin_condominium_ids()
      )
      : 'deny';
  }

  $errors = myapi_building_admin_reservation_errors(
    myapi_building_admin_field_target_id($node, 'field_requester'),
    myapi_building_admin_visible_uids(),
    $unit_decision
  );

  foreach ($errors as $error) {
    form_set_error($error['field'], $error['message']);
  }
}
