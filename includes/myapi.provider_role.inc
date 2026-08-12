<?php

/**
 * @file
 * Catalogues and helpers for the 'proveedor' role (SPEC 78).
 *
 * Continuation of the marketplace bundles created by SPEC 77
 * (includes/myapi.services_common.inc). This role does NOT authorize the
 * api/v1/... endpoints of this module — none of their queries carry the
 * 'node_access' tag, and node_save() does not check permissions either. What
 * this role closes is the Drupal BACK OFFICE: without an explicit denial, a
 * provider with a Drupal session could read any building's service_request by
 * direct node/N URL, because 'access content' is inherited from the
 * *authenticated user* role, not granted by this one.
 *
 * Same split as includes/myapi.building_admin.inc (SPEC 49), which this file
 * replicates the pattern of:
 * - "Pure helpers" take plain values and return plain values. No database, no
 *   globals, no Drupal calls. This is what lets tests/unit/ProviderRoleTest.php
 *   require this file and exercise the whole decision surface with no site
 *   booted.
 * - "Drupal-facing helpers" are thin wrappers over them.
 *
 * The role is scoped by TWO axes, neither of which is "which condominium" —
 * that is why it does not enter myapi_building_admin_condominium_map():
 * - Belonging — which 'provider' node is the account's own, resolved
 *   backwards from field_provider_users.
 * - Category — which service_request nodes concern the account's provider,
 *   crossing service_request.field_category against provider.field_categories.
 *
 * The role is always matched BY NAME, never by rid, same criterion as
 * MYAPI_BUILDING_ADMIN_ROLE.
 *
 * Every define() is guarded so a PHPUnit require_once of this file next to one
 * that already defined the same constant does not emit a redefinition notice.
 */

// Literal role name, Spanish and lower case, as agreed with the client —
// coherent with 'administrador edificio', the other role this module creates.
if (!defined('MYAPI_PROVIDER_ROLE')) {
  define('MYAPI_PROVIDER_ROLE', 'proveedor');
}

// User field holding the accounts that operate a provider (SPEC 77). Unlimited
// cardinality on the 'provider' node, entityreference to 'user'. Resolving it
// backwards (user -> provider) is what myapi_provider_role_provider_ids()
// does; nothing in this module makes it 1:1, so that resolution always answers
// a list.
if (!defined('MYAPI_PROVIDER_USERS_FIELD')) {
  define('MYAPI_PROVIDER_USERS_FIELD', 'field_provider_users');
}

/* ---------------------------------------------------------------------------
 * Catalogues.
 * ------------------------------------------------------------------------ */

/**
 * The permissions granted to the role.
 *
 * The list is empty ON PURPOSE, not an oversight: the role needs no Drupal
 * permission for the app to work, because the API writes with node_save() and
 * reads with db_select() carrying no 'node_access' tag. Granting anything here
 * would only open a path into the back office that the app does not use — in
 * particular 'create service_offer content', which would let a provider reach
 * node/add/service_offer and create an offer that skips the API's uniqueness,
 * enablement and request-status checks.
 *
 * The function exists anyway, returning an empty list, for three reasons: it
 * is the single place where something would be added the day decision 1 or 2
 * of the spec's draft note changes; the installer is identical in shape to the
 * one for the neighbouring role; and a unit test fails the day a 'delete'
 * sneaks in here, the same guard myapi_building_admin_permissions() carries.
 *
 * @return array
 *   List of permission names. Empty for this role.
 */
function myapi_provider_role_permissions() {
  return [];
}

/**
 * How each of the five marketplace bundles is scoped for this role.
 *
 * Four modes:
 * - 'self'        — the node IS the provider; its own nid has to be among the
 *                    account's.
 * - 'own'         — an entityreference field on the node points at the
 *                    account's provider.
 * - 'category'    — the node is a service_request; visibility is decided by
 *                    myapi_provider_role_request_visible(), not by a simple
 *                    membership test.
 * - 'via_request' — the node points at a service_request through 'field',
 *                    and inherits whatever that request's own decision is.
 *                    Same two-hop shape as the 'via_claim' mode of
 *                    myapi_building_admin_condominium_map() (SPEC 49/56).
 *
 * A bundle that is NOT in this map is OUT of the rule entirely: the caller
 * gets NODE_ACCESS_IGNORE for it and the rest of Drupal decides, exactly as
 * for any other node type. That is what keeps a provider who also lives in
 * the building seeing bulletins, their unit and their receipts unchanged.
 *
 * @return array
 *   Node type machine name => ['mode' => ..., 'field' => ...]. The 'field'
 *   key is absent for the 'self' and 'category' modes.
 */
function myapi_provider_role_scope_map() {
  return [
    MYAPI_SERVICES_PROVIDER_TYPE    => ['mode' => 'self'],
    MYAPI_SERVICES_OFFER_TYPE       => ['mode' => 'own', 'field' => 'field_provider'],
    MYAPI_SERVICES_RATING_TYPE      => ['mode' => 'own', 'field' => 'field_rating_provider'],
    MYAPI_SERVICES_REQUEST_TYPE     => ['mode' => 'category'],
    MYAPI_SERVICES_TRANSACTION_TYPE => ['mode' => 'via_request', 'field' => 'field_request'],
  ];
}

/* ---------------------------------------------------------------------------
 * Pure helpers.
 *
 * No database, no Drupal, no globals. Everything below takes plain values and
 * returns plain values, so tests/unit/ProviderRoleTest.php can require this
 * file and exercise them directly.
 * ------------------------------------------------------------------------ */

/**
 * Tells whether a list of role names contains the provider role.
 *
 * $account->roles is rid => name in Drupal 7, so the caller hands over the
 * values and no rid ever enters the logic. Same shape as
 * myapi_building_admin_roles_match().
 *
 * @param array $role_names
 *   Role names, as the values of $account->roles.
 *
 * @return bool
 *   TRUE when the role is among them.
 */
function myapi_provider_role_roles_match(array $role_names) {
  return in_array(MYAPI_PROVIDER_ROLE, $role_names, TRUE);
}

/**
 * Decides access for one node of the role, given whether it belongs.
 *
 * The whole rule of SPEC 78 in a few lines, deliberately returning only
 * 'deny' or 'ignore' and NEVER 'allow' — granting here would short-circuit
 * every other check Drupal makes (unpublished nodes, other modules' hooks)
 * and turn a scope filter into a permission escalation. Same contract as
 * myapi_building_admin_access_decision().
 *
 * One function serves both scope axes of myapi_provider_role_scope_map():
 * - For 'self' and 'own' modes, the caller resolves $belongs as the
 *   membership test itself (is the node's own nid, or its field's target id,
 *   among the account's provider nids), and passes NULL only when that field
 *   could not be resolved at all (an empty required reference — a data
 *   anomaly, not a policy call).
 * - For 'category' mode, the caller passes the bool
 *   myapi_provider_role_request_visible() answers.
 * - For 'via_request' mode, the caller passes whatever the linked request's
 *   OWN decision resolves to (TRUE for 'ignore', FALSE for 'deny'), so a
 *   service_transaction is exactly as visible as the request it belongs to.
 *
 * The empty-$account_provider_ids guard is what makes "an account with the
 * role but no provider sees nothing of the five bundles" hold for every mode
 * from a single place, instead of being re-derived per mode: the same
 * explicit criterion SPEC 49 took with "no condominium assigned" (an account
 * with no provider always fails every membership and every category test
 * too, but denying it here up front keeps that guarantee from depending on
 * that fact staying true as new modes are added).
 *
 * @param bool|null $belongs
 *   TRUE when the node passes the rule of its scope mode, FALSE when it does
 *   not, NULL when the mode could not be resolved at all — which takes the
 *   node OUT of the rule, mirroring the "type outside the map" branch of
 *   myapi_building_admin_access_decision().
 * @param array $account_provider_ids
 *   Provider nids the account belongs to; may be empty.
 *
 * @return string
 *   'deny' or 'ignore'.
 */
function myapi_provider_role_access_decision($belongs, array $account_provider_ids) {
  if ($belongs === NULL) {
    return 'ignore';
  }

  if (!$account_provider_ids) {
    return 'deny';
  }

  return $belongs ? 'ignore' : 'deny';
}

/**
 * Decides whether a service_request is visible to a provider.
 *
 * Visible when EITHER holds:
 * 1. The provider already offered on it — does NOT depend on status nor on
 *    enablement. What was already touched stays visible, including the
 *    history of a closed or cancelled request the provider took part in.
 * 2. It concerns the provider NOW — all three at once: the request's
 *    category is one of the provider's; the status is 'open' or 'offered';
 *    and the provider is active (myapi_services_provider_is_active()).
 *
 * A request in 'assigned', 'closed' or 'cancelled' of a matching category, in
 * which the provider did NOT offer, is therefore not visible: it is not
 * theirs and there is nothing left for them to do with it.
 *
 * @param int|null $request_category
 *   The tid of the request's field_category, or NULL when unset.
 * @param array $provider_categories
 *   Tids of the provider's own field_categories; may be empty.
 * @param string $request_status
 *   The request's field_request_status value.
 * @param bool $already_offered
 *   TRUE when the provider has a service_offer pointing at this request.
 * @param bool $provider_active
 *   TRUE when the provider is active, as decided by
 *   myapi_services_provider_is_active().
 *
 * @return bool
 *   TRUE when the request is visible to the provider.
 */
function myapi_provider_role_request_visible(
  $request_category,
  array $provider_categories,
  $request_status,
  $already_offered,
  $provider_active
) {
  if ($already_offered) {
    return TRUE;
  }

  if (!$provider_active) {
    return FALSE;
  }

  if ($request_category === NULL) {
    return FALSE;
  }

  if (!in_array((int) $request_category, array_map('intval', $provider_categories), TRUE)) {
    return FALSE;
  }

  return in_array($request_status, [
    MYAPI_SERVICES_REQUEST_STATUS_OPEN,
    MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
  ], TRUE);
}

/**
 * Reads the first scalar under $key of a field's first delta on an entity.
 *
 * Shared engine behind myapi_provider_role_field_target_id(),
 * myapi_provider_role_field_tid() and myapi_provider_role_field_value():
 * the three differ only in which key the Field API stores the scalar under
 * ('target_id' for entityreference, 'tid' for taxonomy_term_reference,
 * 'value' for a plain widget). Same langcode/delta walk as
 * myapi_building_admin_field_target_ids(), and for the same reason: reading
 * [LANGUAGE_NONE][0] directly would make a language-handling change silently
 * return NULL and open access instead of failing loudly.
 *
 * @param object $entity
 *   Node or user object.
 * @param string $field_name
 *   Field machine name.
 * @param string $key
 *   The item key to read: 'target_id', 'tid' or 'value'.
 *
 * @return mixed|null
 *   The stored value, or NULL when the field is absent or empty.
 */
function myapi_provider_role_field_scalar($entity, $field_name, $key) {
  if (!is_object($entity) || empty($entity->{$field_name}) || !is_array($entity->{$field_name})) {
    return NULL;
  }

  foreach ($entity->{$field_name} as $items) {
    if (!is_array($items)) {
      continue;
    }
    foreach ($items as $item) {
      if (is_array($item) && isset($item[$key])) {
        return $item[$key];
      }
    }
  }

  return NULL;
}

/**
 * Reads the first target_id of an entityreference field on an entity.
 *
 * Used for field_provider, field_rating_provider and field_request — every
 * 'own' and 'via_request' mode of myapi_provider_role_scope_map().
 *
 * @param object $entity
 *   Node object.
 * @param string $field_name
 *   Field machine name.
 *
 * @return int|null
 *   The referenced entity id, or NULL when the field is absent or empty.
 */
function myapi_provider_role_field_target_id($entity, $field_name) {
  $value = myapi_provider_role_field_scalar($entity, $field_name, 'target_id');

  return $value === NULL ? NULL : (int) $value;
}

/**
 * Reads the first tid of a taxonomy_term_reference field on an entity.
 *
 * Used for field_category, the single-value term reference on
 * 'service_request' that drives the 'category' mode.
 *
 * @param object $entity
 *   Node object.
 * @param string $field_name
 *   Field machine name.
 *
 * @return int|null
 *   The referenced term id, or NULL when the field is absent or empty.
 */
function myapi_provider_role_field_tid($entity, $field_name) {
  $value = myapi_provider_role_field_scalar($entity, $field_name, 'tid');

  return $value === NULL ? NULL : (int) $value;
}

/**
 * Reads the first scalar 'value' of a field on an entity.
 *
 * Used for field_request_status, a list_text field whose items carry 'value'
 * rather than 'target_id' or 'tid'.
 *
 * @param object $entity
 *   Node object.
 * @param string $field_name
 *   Field machine name.
 *
 * @return mixed|null
 *   The stored value, or NULL when the field is absent or empty.
 */
function myapi_provider_role_field_value($entity, $field_name) {
  return myapi_provider_role_field_scalar($entity, $field_name, 'value');
}

/* ---------------------------------------------------------------------------
 * Drupal-facing helpers.
 *
 * Thin wrappers over the pure block: they fetch from Drupal and delegate the
 * decision. Nothing here is unit-tested; it is covered by the manual
 * acceptance criteria of the spec, same split as myapi.building_admin.inc.
 * ------------------------------------------------------------------------ */

/**
 * Tells whether an account holds the 'proveedor' role.
 *
 * By name, never by rid. uid 1 does NOT inherit the role: the superuser
 * bypass lives in user_access(), which this never calls, so the site owner
 * keeps seeing everything precisely because they do not hold this role.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return bool
 *   TRUE when the account holds the role.
 */
function myapi_provider_role_is($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  if (!is_object($account) || empty($account->roles) || !is_array($account->roles)) {
    return FALSE;
  }

  return myapi_provider_role_roles_match(array_values($account->roles));
}

/**
 * Returns the provider nids an account belongs to.
 *
 * A REVERSE lookup: MYAPI_PROVIDER_USERS_FIELD is an entityreference field on
 * the 'provider' bundle that points AT a user, not a field on the user. So
 * resolving user -> provider means querying the field's storage table for
 * rows whose target is this uid, rather than reading a field off the account
 * object the way myapi_building_admin_condominium_ids() reads
 * field_condominio_admin off the user.
 *
 * Always a LIST, never a single id: field_provider_users has unlimited
 * cardinality on the provider side, and nothing in this module enforces one
 * account per provider (SPEC 78's decision 4) — a user may operate more than
 * one provider, and the caller gets the union of both.
 *
 * Statically cached per uid, for the same reason as
 * myapi_building_admin_condominium_ids(): hook_node_access() runs once per
 * node loaded, so without the cache a listing of 50 nodes would repeat the
 * same query 50 times.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return array
 *   List of provider nids; may be empty.
 */
function myapi_provider_role_provider_ids($account = NULL) {
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
    $result = db_select('field_data_' . MYAPI_PROVIDER_USERS_FIELD, 'fpu')
      ->fields('fpu', ['entity_id'])
      ->condition('fpu.entity_type', 'node')
      ->condition('fpu.deleted', 0)
      ->condition('fpu.' . MYAPI_PROVIDER_USERS_FIELD . '_target_id', $uid)
      ->execute()
      ->fetchCol();

    $cache[$uid] = array_values(array_unique(array_map('intval', $result)));
  }

  return $cache[$uid];
}

/**
 * Returns the category tids of every provider an account belongs to.
 *
 * The union across all of the account's providers (SPEC 78's decision 4):
 * with two providers, a category is "theirs" if either provider offers it.
 * Read with a query rather than a node_load() per provider, the same
 * efficiency criterion as the rest of this file's Drupal-facing helpers.
 *
 * Statically cached per uid, same reasoning as
 * myapi_provider_role_provider_ids(): the 'category' mode of
 * myapi_provider_role_node_decision() asks this once per service_request
 * node evaluated in a request.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return array
 *   List of category tids; may be empty.
 */
function myapi_provider_role_category_ids($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  $provider_ids = myapi_provider_role_provider_ids($account);

  if (!$provider_ids) {
    return [];
  }

  $cache = &drupal_static(__FUNCTION__, []);
  $key = implode(',', $provider_ids);

  if (!isset($cache[$key])) {
    $result = db_select('field_data_field_categories', 'fc')
      ->fields('fc', ['field_categories_tid'])
      ->condition('fc.entity_type', 'node')
      ->condition('fc.deleted', 0)
      ->condition('fc.entity_id', $provider_ids, 'IN')
      ->execute()
      ->fetchCol();

    $cache[$key] = array_values(array_unique(array_map('intval', $result)));
  }

  return $cache[$key];
}

/**
 * Tells whether an account has at least one ACTIVE provider.
 *
 * A single flag for the whole account, crossed against the union of
 * categories from myapi_provider_role_category_ids() in
 * myapi_provider_role_node_decision(): with two providers, one active and one
 * not, the account keeps browsing new requests in either provider's
 * categories as long as ONE of them is active. This is the same "union, kept
 * simple" reading SPEC 78 gives the rest of decision 4, and is deliberately
 * not a per-category active flag — myapi_provider_role_request_visible()
 * takes a single bool by design.
 *
 * Statically cached per uid.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return bool
 *   TRUE when at least one of the account's providers is active.
 */
function myapi_provider_role_any_provider_active($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  $provider_ids = myapi_provider_role_provider_ids($account);

  if (!$provider_ids) {
    return FALSE;
  }

  $cache = &drupal_static(__FUNCTION__, []);
  $key = implode(',', $provider_ids);

  if (!isset($cache[$key])) {
    $query = db_select('node', 'n');
    $query->fields('n', ['status']);
    $query->leftJoin(
      'field_data_field_license_expiry',
      'fle',
      "fle.entity_id = n.nid AND fle.entity_type = 'node' AND fle.deleted = 0"
    );
    $query->addField('fle', 'field_license_expiry_value', 'license_expiry');
    $query->condition('n.nid', $provider_ids, 'IN');

    $active = FALSE;
    foreach ($query->execute() as $row) {
      if (myapi_services_provider_is_active((int) $row->status, $row->license_expiry, REQUEST_TIME)) {
        $active = TRUE;
        break;
      }
    }

    $cache[$key] = $active;
  }

  return $cache[$key];
}

/**
 * Returns the service_request nids an account's providers already offered on.
 *
 * The "ya ofertó" branch of myapi_provider_role_request_visible(), computed
 * for the WHOLE account at once rather than one request at a time: joins
 * field_data_field_request (shared with service_transaction, but the INNER
 * JOIN to field_data_field_provider naturally excludes those rows, since
 * field_provider is only instanced on 'service_offer') to
 * field_data_field_provider, and collects every request nid that has an offer
 * from one of the account's providers.
 *
 * Statically cached per provider-id set. This is what both
 * myapi_provider_role_has_offered() (one nid at a time, from
 * myapi_provider_role_node_decision()) and
 * myapi_provider_role_visible_request_ids() (the whole set, from the listing
 * alter) read from — one query per request instead of one per node asked
 * about, which is cheaper than the "per nid" caching the spec sketches, not a
 * shortfall of it.
 *
 * @param array $provider_ids
 *   Provider nids the account belongs to; may be empty.
 *
 * @return array
 *   List of service_request nids; may be empty.
 */
function myapi_provider_role_offered_request_ids(array $provider_ids) {
  if (!$provider_ids) {
    return [];
  }

  $cache = &drupal_static(__FUNCTION__, []);
  $key = implode(',', $provider_ids);

  if (!isset($cache[$key])) {
    $query = db_select('field_data_field_request', 'fr');
    $query->fields('fr', ['field_request_target_id']);
    $query->innerJoin(
      'field_data_field_provider',
      'fp',
      "fp.entity_id = fr.entity_id AND fp.entity_type = 'node' AND fp.deleted = 0"
    );
    $query->condition('fr.entity_type', 'node');
    $query->condition('fr.deleted', 0);
    $query->condition('fp.field_provider_target_id', $provider_ids, 'IN');

    $result = $query->execute()->fetchCol();
    $cache[$key] = array_values(array_unique(array_map('intval', $result)));
  }

  return $cache[$key];
}

/**
 * Tells whether an account's providers already offered on a service_request.
 *
 * Thin wrapper over myapi_provider_role_offered_request_ids(): the one-nid
 * question myapi_provider_role_node_decision() asks per node it evaluates.
 *
 * @param int $request_nid
 *   The service_request node id.
 * @param array $provider_ids
 *   Provider nids the account belongs to; may be empty.
 *
 * @return bool
 *   TRUE when at least one of the account's providers offered on it.
 */
function myapi_provider_role_has_offered($request_nid, array $provider_ids) {
  return in_array((int) $request_nid, myapi_provider_role_offered_request_ids($provider_ids), TRUE);
}

/**
 * Returns the service_request nids visible to an account under this role.
 *
 * The category-mode counterpart of myapi_provider_role_offered_request_ids():
 * the union of "already offered" (myapi_provider_role_request_visible()'s
 * first branch, status- and enablement-independent) and "concerns them now"
 * (its second branch: category matches, status is 'open' or 'offered', AND
 * the account has at least one active provider — the category sub-query is
 * skipped entirely when it does not, since nothing in it would survive
 * myapi_provider_role_request_visible() either).
 *
 * This is the set both branches of the 'category' and 'via_request' modes of
 * myapi_provider_role_alter_node_query() are narrowed to — a
 * service_transaction is exactly as visible as the request field_request
 * points at, same as in myapi_provider_role_node_decision().
 *
 * A missing field_category or field_request_status (a site not yet migrated
 * to SPEC 77's schema) skips the category sub-query and fails closed, the
 * same criterion myapi_building_admin_alter_node_query() uses for a missing
 * condominium field.
 *
 * Statically cached per provider-id set.
 *
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return array
 *   List of service_request nids; may be empty.
 */
function myapi_provider_role_visible_request_ids($account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  $provider_ids = myapi_provider_role_provider_ids($account);

  if (!$provider_ids) {
    return [];
  }

  $cache = &drupal_static(__FUNCTION__, []);
  $key = implode(',', $provider_ids);

  if (!isset($cache[$key])) {
    $visible = myapi_provider_role_offered_request_ids($provider_ids);

    $category_ids = myapi_provider_role_category_ids($account);

    if ($category_ids
      && myapi_provider_role_any_provider_active($account)
      && field_info_field('field_category')
      && field_info_field('field_request_status')) {
      $query = db_select('node', 'n');
      $query->fields('n', ['nid']);
      $query->innerJoin(
        'field_data_field_category',
        'fcat',
        "fcat.entity_id = n.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0"
      );
      $query->innerJoin(
        'field_data_field_request_status',
        'frs',
        "frs.entity_id = n.nid AND frs.entity_type = 'node' AND frs.deleted = 0"
      );
      $query->condition('n.type', MYAPI_SERVICES_REQUEST_TYPE);
      $query->condition('fcat.field_category_tid', $category_ids, 'IN');
      $query->condition('frs.field_request_status_value', [
        MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      ], 'IN');

      $visible = array_merge($visible, array_map('intval', $query->execute()->fetchCol()));
    }

    $cache[$key] = array_values(array_unique($visible));
  }

  return $cache[$key];
}

/**
 * Decides node access for one node against the provider role.
 *
 * Ties every piece of this file together: the role check, the scope map, the
 * per-mode resolution (self/own membership, the category rule, or the
 * via_request inheritance) and the final 'deny'/'ignore' verdict of
 * myapi_provider_role_access_decision(). Called from myapi_node_access() in
 * myapi.module for the direct-URL half of the filter.
 *
 * The 'via_request' mode recurses ONE level into the linked service_request
 * — never deeper, since 'category' is a leaf mode — and reuses this same
 * function so a service_transaction is exactly as visible as the request it
 * belongs to, the same shape as the 'via_claim' mode of
 * myapi_building_admin_resolve_condominium() (SPEC 49/56).
 *
 * A node whose relevant reference field is empty (a data anomaly: every field
 * used here is required on its bundle) resolves $belongs to NULL, which
 * myapi_provider_role_access_decision() turns into 'ignore' — the same
 * fail-open reading the building-admin role gives an unresolvable
 * condominium, rather than a policy call this function is not positioned to
 * make.
 *
 * @param object $node
 *   The node being checked. Needs at least ->type; ->nid may be absent for a
 *   'create' op stub, which resolves 'self' and 'own' modes to NULL.
 * @param object|null $account
 *   A user object, or NULL for the current user.
 *
 * @return string
 *   'deny' or 'ignore'. Never 'allow' — see
 *   myapi_provider_role_access_decision().
 */
function myapi_provider_role_node_decision($node, $account = NULL) {
  if ($account === NULL) {
    global $user;
    $account = $user;
  }

  if (!myapi_provider_role_is($account)) {
    return 'ignore';
  }

  if (!is_object($node) || empty($node->type)) {
    return 'ignore';
  }

  $map = myapi_provider_role_scope_map();

  if (!isset($map[$node->type])) {
    return 'ignore';
  }

  $entry = $map[$node->type];
  $account_provider_ids = myapi_provider_role_provider_ids($account);

  switch ($entry['mode']) {
    case 'self':
      $belongs = empty($node->nid)
        ? NULL
        : in_array((int) $node->nid, $account_provider_ids, TRUE);
      break;

    case 'own':
      $target = myapi_provider_role_field_target_id($node, $entry['field']);
      $belongs = ($target === NULL) ? NULL : in_array($target, $account_provider_ids, TRUE);
      break;

    case 'category':
      $already_offered = empty($node->nid)
        ? FALSE
        : myapi_provider_role_has_offered((int) $node->nid, $account_provider_ids);

      $belongs = myapi_provider_role_request_visible(
        myapi_provider_role_field_tid($node, 'field_category'),
        myapi_provider_role_category_ids($account),
        myapi_provider_role_field_value($node, 'field_request_status'),
        $already_offered,
        myapi_provider_role_any_provider_active($account)
      );
      break;

    case 'via_request':
      $request_nid = myapi_provider_role_field_target_id($node, $entry['field']);
      $request = $request_nid ? node_load($request_nid) : FALSE;
      $belongs = is_object($request)
        ? (myapi_provider_role_node_decision($request, $account) === 'ignore')
        : NULL;
      break;

    default:
      $belongs = NULL;
  }

  return myapi_provider_role_access_decision($belongs, $account_provider_ids);
}

/**
 * LEFT JOINs a field data table once, and returns its alias.
 *
 * Local copy of myapi_building_admin_join_field(), not a call into it: it is
 * a generic query-building utility with no building-admin logic in it, and
 * this file's only spec-mandated cross-file reuse is
 * myapi_building_admin_query_base_table_alias() (SPEC 78's own "Decisiones"
 * table). Every other file in this module that needs a small reusable piece
 * like this keeps its own copy — myapi.notification.inc and
 * myapi.payment_workflow.inc both do the same for their field readers.
 *
 * @param SelectQueryInterface $query
 *   The query being altered.
 * @param string $field
 *   Field machine name, e.g. 'field_provider'.
 * @param string $left_expression
 *   What the field's entity_id must equal, e.g. 'node.nid'.
 * @param array $joined
 *   Reuse registry, passed by reference across the calls of one alter.
 *
 * @return string
 *   The alias of the joined table.
 */
function myapi_provider_role_join_field($query, $field, $left_expression, array &$joined) {
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
 * Narrows a node_access-tagged query to what a provider may see.
 *
 * The listing half of the SPEC 78 filter, called from
 * myapi_query_node_access_alter() in myapi.module.
 * myapi_provider_role_node_decision() is the direct-URL half; this one
 * reaches the same five bundles wherever else the 'node_access' tag shows up
 * — /admin/content, blocks, search, entityreference autocompletes. With
 * decisions 1 and 2 of the spec's draft note applied (no back office, no
 * self-edit), the role opens none of those screens today, so this half has
 * almost no surface to exercise — it is still implemented, for the same
 * reason SPEC 49 gives: a single-half filter is exactly the bug that spec
 * documented, and the tag is not only /admin/content's.
 *
 * Three guards, same order and meaning as
 * myapi_building_admin_alter_node_query():
 * 1. The current user holds the role.
 * 2. 'node' is the query's BASE table, not merely joined — reusing
 *    myapi_building_admin_query_base_table_alias() (SPEC 72), the one
 *    cross-file call this spec's "Decisiones" table authorizes. Without it a
 *    query merely joining 'node' would get this filter landed on the wrong
 *    column, which is the exact bug SPEC 72 fixed on the people side.
 * 3. At least one provider assigned; with none, '1 = 0' closes the query —
 *    "no provider associated" means nothing visible, same as the per-node
 *    half.
 *
 * Five branches past the guards, one per bundle of
 * myapi_provider_role_scope_map(): 'self' and 'own' are simple membership,
 * exactly like myapi_building_admin_alter_node_query()'s own 'self'/'direct'
 * modes; 'category' and 'via_request' both narrow to the SAME precomputed
 * myapi_provider_role_visible_request_ids() set, which is what keeps a
 * service_transaction exactly as visible as the request it belongs to on the
 * listing side too.
 *
 * A branch whose field does not exist in this environment contributes
 * nothing — fail closed, same as building-admin's alter.
 *
 * $exempt_types (SPEC 78) mirrors the same parameter on
 * myapi_building_admin_alter_node_query() and exists for the same reason:
 * this alter's restriction is a complete allowlist of its own 5 bundles, and
 * stacking two independent complete-allowlists on one query collapses to
 * their intersection — empty, since the two roles' domains never overlap —
 * emptying every node_access-tagged listing for an account holding both
 * roles. Passing the building-admin role's own visible types here makes this
 * alter a no-op for them, deferring entirely to that alter, while a type
 * neither role recognizes stays excluded exactly as a solo run would.
 *
 * @param SelectQueryInterface $query
 *   The query being altered.
 * @param array $exempt_types
 *   Node type machine names this alter must NOT restrict, because another
 *   role's own alter on the same query already governs them.
 */
function myapi_provider_role_alter_node_query($query, array $exempt_types = []) {
  if (!is_object($query) || !method_exists($query, 'getTables')) {
    return;
  }

  // Guard 1 — the role.
  if (!myapi_provider_role_is()) {
    return;
  }

  // Guard 2 — 'node' is the BASE table, not just one of the joined ones
  // (SPEC 72).
  $node_alias = myapi_building_admin_query_base_table_alias($query->getTables(), 'node');
  if ($node_alias === NULL) {
    return;
  }

  // The domain gate (SPEC 78): OR'd with this role's own restriction below,
  // so an exempt type always passes THIS alter, deferring entirely to
  // whichever other alter governs it.
  $gate = db_or();
  if ($exempt_types) {
    $gate->condition($node_alias . '.type', $exempt_types, 'IN');
  }

  // Guard 3 — no provider assigned, nothing visible WITHIN this role's own
  // domain.
  //
  // Same empty-group hazard as myapi_building_admin_alter_node_query(): a
  // DatabaseCondition with zero sub-conditions compiles to nothing in Drupal
  // 7, so it restricts NOTHING. $gate is empty exactly when $exempt_types is
  // empty (this role running alone), so the hard `1 = 0` is kept for that
  // case; $gate is used only when there is another role's domain to defer to.
  $provider_ids = myapi_provider_role_provider_ids();
  if (!$provider_ids) {
    if (!$exempt_types) {
      $query->where('1 = 0');
      return;
    }
    $query->condition($gate);
    return;
  }

  $visible_requests = myapi_provider_role_visible_request_ids();
  $joined = [];
  $branches = db_or();
  $branch_count = 0;

  // 'self' — provider.
  $branches->condition(db_and()
    ->condition($node_alias . '.type', MYAPI_SERVICES_PROVIDER_TYPE)
    ->condition($node_alias . '.nid', $provider_ids, 'IN'));
  $branch_count++;

  // 'own' — service_offer and service_rating.
  $own_fields = [
    MYAPI_SERVICES_OFFER_TYPE  => 'field_provider',
    MYAPI_SERVICES_RATING_TYPE => 'field_rating_provider',
  ];
  foreach ($own_fields as $type => $field) {
    if (!field_info_field($field)) {
      continue;
    }
    $alias = myapi_provider_role_join_field($query, $field, $node_alias . '.nid', $joined);
    $branches->condition(db_and()
      ->condition($node_alias . '.type', $type)
      ->condition($alias . '.' . $field . '_target_id', $provider_ids, 'IN'));
    $branch_count++;
  }

  // 'category' — service_request — and 'via_request' — service_transaction —
  // both narrowed to the same precomputed visible-request set.
  if ($visible_requests) {
    $branches->condition(db_and()
      ->condition($node_alias . '.type', MYAPI_SERVICES_REQUEST_TYPE)
      ->condition($node_alias . '.nid', $visible_requests, 'IN'));
    $branch_count++;

    if (field_info_field('field_request')) {
      $alias = myapi_provider_role_join_field($query, 'field_request', $node_alias . '.nid', $joined);
      $branches->condition(db_and()
        ->condition($node_alias . '.type', MYAPI_SERVICES_TRANSACTION_TYPE)
        ->condition($alias . '.field_request_target_id', $visible_requests, 'IN'));
      $branch_count++;
    }
  }

  if (!$branch_count) {
    // Same empty-group hazard as guard 3 above.
    if (!$exempt_types) {
      $query->where('1 = 0');
      return;
    }
    $query->condition($gate);
    return;
  }

  // $branches already implies `type IN myapi_services_node_types()` — every
  // disjunct pins one of the five bundles — so it is nested into $gate
  // directly rather than ANDing a redundant outer `type IN (...)` alongside
  // it, which is what would make an exempt-type row fail this alter too.
  $gate->condition($branches);
  $query->condition($gate);
}
