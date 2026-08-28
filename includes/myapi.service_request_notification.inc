<?php

/**
 * @file
 * Service request notification helpers for the myapi module (SPEC 109).
 *
 * One event: a resident creates a service request from
 * POST /api/v1/service-requests. Three channels leave from it — inbox row,
 * OneSignal push and email to every account of each notified provider — plus a
 * fourth, the detail email to the 'backend' role.
 *
 * Who gets notified depends on how the request is born, and only on that:
 * - Open (no assigned provider): every ACTIVE provider of its category, one
 *   notification per provider, because provider_id is a column of the row and
 *   not a property of the batch.
 * - Direct (field_assigned_provider set): only the awarded provider.
 *
 * The trigger hangs off the endpoint and not off hook_node_insert(), the same
 * criterion as payments (SPEC 80) and reservations (SPEC 48): a request typed
 * into node/add/service_request by an operator notifies nobody. The endpoint is
 * also where the ids are already resolved and validated, so nothing here is
 * loaded twice.
 *
 * WHAT A PROVIDER NEVER SEES: unit, requester, requester's email, description.
 * Not in the push, not in the inbox row (unit_id stays NULL), not in the email.
 * That detail belongs to GET /api/v1/service-requests/{nid}, which already
 * decides what each viewer is allowed to read.
 *
 * Texts are fixed in Spanish, not translated through myapi_t(): same criterion
 * as every other mail and push of this module (SPEC 27/28/30/48/68/80).
 *
 * Everything here is best-effort: no branch throws, so a failure to notify can
 * never break the 201 of the endpoint nor undo the node that was just saved.
 */

// The bundle and the "active provider" rule live in the services catalogue and
// in the query half of SPEC 83; this file states neither a second time.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
module_load_include('inc', 'myapi', 'includes/myapi.provider_query');
module_load_include('inc', 'myapi', 'includes/myapi.provider_role');

/**
 * Value stored in myapi_notifications.source_type for a service request event.
 */
define('MYAPI_NOTIFICATION_SOURCE_SERVICE_REQUEST', 'service_request');

/**
 * Notification type of the "new open request in your category" event.
 */
define('MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_OPEN', 'service_request_open');

/**
 * Notification type of the "new request addressed to you" event.
 *
 * A separate type and not a flag on the open one, because the two read
 * differently to the provider: one is an opportunity shared with competitors,
 * the other is already theirs.
 */
define('MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_DIRECT', 'service_request_direct');

/**
 * Deep link target the app opens from either provider notification.
 *
 * Distinct from the resident's 'service_request' target: the same nid opens a
 * different screen depending on which side of the marketplace is looking. A
 * value the app does not know yet — until it does, an unknown target must
 * degrade to opening the inbox, never break. Documented as part of the contract
 * in docs/service-request-notifications.md.
 */
define('MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER', 'service_request_provider');

/**
 * Role that receives the detail email of every request created from the app.
 *
 * Only 'backend', as asked: building admins are deliberately out (they are in
 * for claims, SPEC 68, and widening this audience is a one-line spec of its
 * own).
 */
define('MYAPI_SERVICE_REQUEST_NOTIFY_ROLE', 'backend');

/**
 * drupal_mail() key of the heads-up sent to a notified provider's accounts.
 */
define('MYAPI_SERVICE_REQUEST_PROVIDER_MAIL_KEY', 'service_request_provider');

/**
 * drupal_mail() key of the detail sent to the back office.
 */
define('MYAPI_SERVICE_REQUEST_ADMIN_MAIL_KEY', 'service_request_admin');

/**
 * What an unresolvable value prints as, in the push and in both emails.
 *
 * A deleted category or a condominium with no title draws a dash and not an
 * empty cell: a mail with a blank line reads as a bug, a dash reads as "there
 * is nothing here".
 */
define('MYAPI_SERVICE_REQUEST_MAIL_EMPTY', '—');

/**
 * Lists the ACTIVE providers that offer one category.
 *
 * The REVERSE lookup of SPEC 78, which resolves provider -> accounts and
 * account -> providers but never category -> providers: an open request knows
 * its category and has to find everybody who could answer it.
 *
 * "Active" is not restated here: myapi_provider_apply_active_conditions()
 * (SPEC 83) adds the licence join, the published condition and the expiry
 * comparison, so a published provider with an expired or missing licence is
 * dropped by the same SQL that drops it from GET /api/v1/providers. Writing the
 * WHERE by hand would be the third home of the rule the maintenance note in
 * includes/myapi.provider_query.inc exists to prevent.
 *
 * Keyed by nid, so a provider carrying the same category in two deltas of
 * field_categories (unlimited cardinality) collapses to one entry and is
 * notified once — the same reason the category counts of SPEC 79 count
 * DISTINCT n.nid.
 *
 * @param int|null $category_id
 *   Category tid of the request.
 *
 * @return array
 *   [provider_nid => trade name], possibly empty. An empty result is not an
 *   error: a category nobody serves notifies nobody.
 */
function myapi_service_request_active_providers_for_category($category_id) {
  $category_id = (int) $category_id;
  if ($category_id <= 0) {
    return [];
  }

  $query = db_select('node', 'n');
  $query->fields('n', ['nid', 'title']);
  $query->innerJoin(
    'field_data_field_categories',
    'fc',
    "fc.entity_type = 'node' AND fc.entity_id = n.nid AND fc.deleted = 0"
  );
  // The licence join ('l') plus published and not-expired, in one call.
  myapi_provider_apply_active_conditions($query, 'n');
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  $query->condition('fc.field_categories_tid', $category_id);

  $providers = [];
  foreach ($query->execute() as $row) {
    $providers[(int) $row->nid] = $row->title;
  }

  return $providers;
}

/**
 * Lists the ACTIVE accounts that operate one provider.
 *
 * The field is the source of truth of who operates a provider (SPEC 78's
 * decision), so the 'proveedor' role is deliberately NOT required: an account
 * listed on field_provider_users is notified whether or not somebody remembered
 * to give it the role.
 *
 * Blocked accounts are excluded (users.status = 1) and so is the anonymous
 * user, the same two rules myapi_notification_role_uids() applies.
 *
 * @param int|null $provider_id
 *   Provider nid.
 *
 * @return array
 *   Unique uids as integers; may be empty (a provider with no account attached
 *   simply receives nothing).
 */
function myapi_service_request_provider_uids($provider_id) {
  $provider_id = (int) $provider_id;
  if ($provider_id <= 0) {
    return [];
  }

  $field = MYAPI_PROVIDER_USERS_FIELD;

  $query = db_select('field_data_' . $field, 'fpu');
  $query->fields('u', ['uid']);
  $query->innerJoin('users', 'u', 'u.uid = fpu.' . $field . '_target_id');
  $query->condition('fpu.entity_type', 'node');
  $query->condition('fpu.deleted', 0);
  $query->condition('fpu.entity_id', $provider_id);
  $query->condition('u.status', 1);
  $query->condition('u.uid', 0, '>');
  $query->distinct();

  return array_map('intval', $query->execute()->fetchCol());
}
