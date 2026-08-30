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

/* ---------------------------------------------------------------------------
 * SPEC 110 — the offer-received notice to the resident.
 *
 * One event: a provider creates an offer on a request
 * (POST /api/v1/service-requests/{id}/offers). One channel set — inbox row,
 * push and email — goes to the resident (field_requester) of that request,
 * once per offer, first or repeat: each POST is its own event to the resident,
 * who just got a quote.
 *
 * Lives in this same file, next to the SPEC 109 constants and functions above,
 * because it is the same event domain (a service request) and the same
 * best-effort, no-throw discipline — not a second include for a variant of a
 * pattern this file already owns.
 * ------------------------------------------------------------------------ */

/**
 * Value stored in myapi_notifications.source_type for an offer-received event.
 *
 * Distinct from MYAPI_NOTIFICATION_SOURCE_SERVICE_REQUEST above: the node that
 * fired this event is the OFFER, even though the deep link the resident opens
 * is the REQUEST. source_type/source_nid describe the origin; deep_link_target/
 * deep_link_id describe the navigation, and nothing forces the two pairs to
 * agree — SPEC 109 already uses them independently for the provider notice.
 */
define('MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER', 'service_offer');

/**
 * Notification type of the "your request got a new offer" event.
 */
define('MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_RECEIVED', 'service_offer_received');

/**
 * Deep link target the app opens from the resident's offer-received notice.
 *
 * The FIRST notice of this file's domain addressed to the resident rather than
 * to a provider, hence a target of its own: there is no per-offer screen to
 * point at (SPEC 100 never built one), so this always lands on the resident's
 * own request detail — the same screen the resident already reaches from the
 * SPEC 26 unit/condominium context of their own notifications.
 */
define('MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST', 'service_request');

/**
 * drupal_mail() key of the offer-received notice sent to the resident.
 */
define('MYAPI_SERVICE_OFFER_RECEIVED_MAIL_KEY', 'service_request_offer_resident');

/* ---------------------------------------------------------------------------
 * SPEC 111 — the offer-withdrawn notice to the resident.
 *
 * One event: a provider withdraws their offer
 * (PUT /api/v1/service-offers/{id}/withdraw). Same channel set — inbox row,
 * push and email — to the resident (field_requester), reusing
 * MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER and
 * MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST from SPEC 110 unchanged.
 * ------------------------------------------------------------------------ */

/**
 * Notification type of the "your offer was withdrawn" event.
 */
define('MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_WITHDRAWN', 'service_offer_withdrawn');

/**
 * drupal_mail() key of the offer-withdrawn notice sent to the resident.
 */
define('MYAPI_SERVICE_OFFER_WITHDRAWN_MAIL_KEY', 'service_request_offer_withdrawn_resident');

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

/* ---------------------------------------------------------------------------
 * Pure text builders.
 *
 * No database and no node loading: they receive values already resolved by the
 * orchestrator and return strings or arrays. That is what makes the four of
 * them — the two the resident never sees and the two the back office reads —
 * assertable character by character in tests/unit with no site booted.
 *
 * Two rules run through all of them:
 * - An unresolvable value prints as MYAPI_SERVICE_REQUEST_MAIL_EMPTY, never as
 *   an empty line. A deleted category or a condominium with no title must not
 *   turn a notification into a hole.
 * - The push and the inbox row carry PLAIN text (an HTML banner would show
 *   literal tags); the mail params are escaped here with check_plain(), once,
 *   because the queue drains on cron and the message must describe what was
 *   true at the instant of the trigger.
 * ------------------------------------------------------------------------ */

/**
 * Plain-text value of a resolved field, or the placeholder.
 *
 * For the push and the inbox row: no escaping, because neither is HTML.
 *
 * @param string|null $value  Already-resolved value.
 *
 * @return string  The value, or MYAPI_SERVICE_REQUEST_MAIL_EMPTY.
 */
function myapi_service_request_notification_label($value) {
  if ($value === NULL || $value === '') {
    return MYAPI_SERVICE_REQUEST_MAIL_EMPTY;
  }

  return $value;
}

/**
 * Escaped value of a resolved field, or the placeholder.
 *
 * For the two emails, whose params are enqueued already escaped.
 *
 * @param string|null $value  Already-resolved value.
 *
 * @return string  Escaped value, or MYAPI_SERVICE_REQUEST_MAIL_EMPTY.
 */
function myapi_service_request_mail_label($value) {
  if ($value === NULL || $value === '') {
    return MYAPI_SERVICE_REQUEST_MAIL_EMPTY;
  }

  return check_plain($value);
}

/**
 * Human label of a timestamp, or the placeholder.
 *
 * Resolved ONCE per request and shared by the push and both emails, so the
 * three channels can never disagree about when the work is meant to start.
 * The output has no character check_plain() would touch, which is why it is
 * safe in the plain-text push and in the HTML mails alike.
 *
 * @param int|null $timestamp  Unix timestamp, or NULL/0 when the field is empty.
 *
 * @return string  'd/m/Y H:i', or MYAPI_SERVICE_REQUEST_MAIL_EMPTY.
 */
function myapi_service_request_date_label($timestamp) {
  $timestamp = (int) $timestamp;
  if ($timestamp <= 0) {
    return MYAPI_SERVICE_REQUEST_MAIL_EMPTY;
  }

  return format_date($timestamp, 'custom', 'd/m/Y H:i');
}

/**
 * Title of the push and of the inbox row sent to a provider.
 *
 * Two texts and not one with a suffix: a direct request is already theirs and
 * an open one is a race, and the provider decides whether to open the app on
 * the title alone.
 *
 * @param bool $is_direct  TRUE when the request was born with an assigned
 *   provider.
 *
 * @return string  Fixed Spanish text.
 */
function myapi_service_request_push_title($is_direct) {
  return $is_direct
    ? 'Nueva solicitud directa para ti'
    : 'Nueva solicitud de servicio';
}

/**
 * Body of the push and of the inbox row sent to a provider.
 *
 * The same four lines in both cases — subject, category, condominium and start
 * — because what changes between an open and a direct request is who is being
 * told, not what they are told. What is deliberately NOT here: the unit, the
 * requester and the description, which no provider sees until they open the
 * detail endpoint.
 *
 * myapi_onesignal_truncate_body() cuts this to 200 characters for the banner;
 * the inbox keeps the whole text.
 *
 * @param string|null $title           node.title of the request.
 * @param string|null $category        Term name of field_category.
 * @param string|null $condominium     node.title of field_condominium.
 * @param string|null $desired_start   Already formatted by
 *   myapi_service_request_date_label().
 *
 * @return string  Four lines separated by "\n".
 */
function myapi_service_request_push_body($title, $category, $condominium, $desired_start) {
  return implode("\n", [
    myapi_service_request_notification_label($title),
    'Categoría: ' . myapi_service_request_notification_label($category),
    'Condominio: ' . myapi_service_request_notification_label($condominium),
    'Inicio: ' . myapi_service_request_notification_label($desired_start),
  ]);
}

/**
 * Params of the heads-up email sent to a notified provider's accounts.
 *
 * The same four values as the push and nothing else: the email is a copy of the
 * notice for the account that does not have the app open, not a richer version
 * of it. 'is_direct' travels because the subject depends on it.
 *
 * @param int   $nid     nid of the request.
 * @param array $values  Already-resolved 'title', 'category', 'desired_start'
 *   (formatted), 'condominium' and 'is_direct'.
 *
 * @return array  Escaped params for myapi_mail_format_service_request_provider().
 */
function myapi_service_request_provider_mail_params($nid, array $values) {
  $values += [
    'title'         => NULL,
    'category'      => NULL,
    'desired_start' => NULL,
    'condominium'   => NULL,
    'is_direct'     => FALSE,
  ];

  return [
    'nid'           => (int) $nid,
    'title'         => myapi_service_request_mail_label($values['title']),
    'category'      => myapi_service_request_mail_label($values['category']),
    'desired_start' => myapi_service_request_mail_label($values['desired_start']),
    'condominium'   => myapi_service_request_mail_label($values['condominium']),
    'is_direct'     => (bool) $values['is_direct'],
  ];
}

/**
 * Params of the detail email sent to the 'backend' role.
 *
 * The whole request, because the back office is the one audience allowed to see
 * it: the unit, the requester, their email and the description are here and in
 * no other channel of this spec.
 *
 * 'type' is built here rather than in the formatter so the branch that says
 * "Abierta" or "Directa a {trade name}" is covered by the same pure test as the
 * rest of the lines. A direct request whose provider name did not resolve reads
 * "Directa a —": still the fact that it is direct, which is what the operator
 * triages by.
 *
 * @param int   $nid     nid of the request.
 * @param array $values  Already-resolved 'title', 'provider_name' (NULL on an
 *   open request), 'category', 'desired_start' (formatted), 'unit',
 *   'condominium', 'requester', 'requester_email', 'description' and 'created'
 *   (formatted).
 *
 * @return array  Escaped params for myapi_mail_format_service_request_admin().
 */
function myapi_service_request_admin_mail_params($nid, array $values) {
  $values += [
    'title'           => NULL,
    'provider_name'   => NULL,
    'category'        => NULL,
    'desired_start'   => NULL,
    'unit'            => NULL,
    'condominium'     => NULL,
    'requester'       => NULL,
    'requester_email' => NULL,
    'description'     => NULL,
    'created'         => NULL,
    'is_direct'       => FALSE,
  ];

  $type = $values['is_direct']
    ? 'Directa a ' . myapi_service_request_mail_label($values['provider_name'])
    : 'Abierta';

  return [
    'nid'             => (int) $nid,
    'title'           => myapi_service_request_mail_label($values['title']),
    'type'            => $type,
    'category'        => myapi_service_request_mail_label($values['category']),
    'desired_start'   => myapi_service_request_mail_label($values['desired_start']),
    'unit'            => myapi_service_request_mail_label($values['unit']),
    'condominium'     => myapi_service_request_mail_label($values['condominium']),
    'requester'       => myapi_service_request_mail_label($values['requester']),
    'requester_email' => myapi_service_request_mail_label($values['requester_email']),
    'description'     => myapi_service_request_mail_label($values['description']),
    'created'         => myapi_service_request_mail_label($values['created']),
    // Absolute so the link works from any mail client; check_plain() because
    // url() does not encode the '&' between query arguments for HTML output.
    // The node view and not the edit form: the operator reads a request before
    // touching it, unlike a payment (SPEC 80) whose next action is a status
    // change.
    'node_url'        => check_plain(url('node/' . (int) $nid, ['absolute' => TRUE])),
  ];
}

/* ---------------------------------------------------------------------------
 * SPEC 110 — pure text builders for the offer-received notice.
 *
 * Same two rules as the block above: an unresolvable value degrades to
 * MYAPI_SERVICE_REQUEST_MAIL_EMPTY rather than an empty line or a thrown
 * error, and the push/inbox text is plain while the mail params are escaped.
 * ------------------------------------------------------------------------ */

/**
 * Human text of an offer's amount, or the "quote on site" text.
 *
 * Reuses myapi_services_offer_amount_types() (SPEC 100) rather than
 * retyping its labels — rule 3 of CLAUDE.md. An 'amount_type' outside that
 * catalogue is corrupt data, not a reason to fail: it falls back to the same
 * text as 'on_site_quote'.
 *
 * @param float|string|null $amount       The offer's amount, or NULL.
 * @param string|null       $amount_type  One of myapi_services_offer_amount_types()'s
 *   keys, or NULL/anything else.
 *
 * @return string  'A presupuestar en sitio', or '{amount} ({label})'.
 */
function myapi_service_offer_amount_text($amount, $amount_type) {
  $labels = myapi_services_offer_amount_types();

  if ($amount_type === 'on_site_quote' || !isset($labels[$amount_type])) {
    return $labels['on_site_quote'];
  }

  return number_format((float) $amount, 2) . ' (' . $labels[$amount_type] . ')';
}

/**
 * Title of the push and of the inbox row sent to the resident (SPEC 110).
 *
 * @return string  Fixed Spanish text.
 */
function myapi_service_offer_push_title() {
  return 'Nueva oferta recibida';
}

/**
 * Body of the push and of the inbox row sent to the resident (SPEC 110).
 *
 * Three lines: the request's own subject, who quoted it and what they quoted
 * — the minimum for the resident to decide whether the detail is worth
 * opening. Deliberately without the provider's free-text message, which can
 * run long and would crowd the push banner.
 *
 * @param string|null $subject        node.title of the request.
 * @param string|null $provider_name  node.title of the provider that offered.
 * @param string      $amount_text    Already built by
 *   myapi_service_offer_amount_text().
 *
 * @return string  Three lines separated by "\n".
 */
function myapi_service_offer_push_body($subject, $provider_name, $amount_text) {
  return implode("\n", [
    myapi_service_request_notification_label($subject),
    'Proveedor: ' . myapi_service_request_notification_label($provider_name),
    'Monto: ' . myapi_service_request_notification_label($amount_text),
  ]);
}

/**
 * URL of the app deep link a service request's detail opens from (SPEC 110).
 *
 * Same pattern as myapi_password_reset_deep_link_base() (SPEC 07,
 * resources/auth.resource.inc): a custom 'myapp://' scheme already adopted by
 * the app, kept in a Drupal variable so it can be adjusted without a deploy.
 * A DIFFERENT variable, on purpose — see the risk note in the spec — so
 * changing the password-reset link can never change this one by accident.
 *
 * @param int $request_nid  nid of the service request.
 *
 * @return string  check_plain()'d URL, e.g. 'myapp://service-requests/128'.
 */
function myapi_service_request_app_deep_link_url($request_nid) {
  $base = variable_get('myapi_service_request_deep_link_base', 'myapp://service-requests');
  return check_plain($base . '/' . (int) $request_nid);
}

/**
 * Params of the offer-received email sent to the resident (SPEC 110).
 *
 * The one email of this file with a button: myapi_mail_service_request_offer_resident_html()
 * points it at the URL built here.
 *
 * @param int   $nid     nid of the request (not the offer).
 * @param array $values  Already-resolved 'name' (resident's, NULL when
 *   unresolved), 'subject', 'provider_name' and 'amount_text'.
 *
 * @return array  Escaped params for
 *   myapi_mail_format_service_request_offer_resident().
 */
function myapi_service_offer_resident_mail_params($nid, array $values) {
  $values += [
    'name'          => NULL,
    'subject'       => NULL,
    'provider_name' => NULL,
    'amount_text'   => NULL,
  ];

  return [
    'nid'           => (int) $nid,
    'name'          => $values['name'] !== NULL ? check_plain($values['name']) : NULL,
    'subject'       => myapi_service_request_mail_label($values['subject']),
    'provider_name' => myapi_service_request_mail_label($values['provider_name']),
    'amount_text'   => myapi_service_request_mail_label($values['amount_text']),
    'deep_link_url' => myapi_service_request_app_deep_link_url($nid),
  ];
}

/* ---------------------------------------------------------------------------
 * SPEC 111 — pure text builders for the offer-withdrawn notice.
 *
 * Same two rules as the blocks above: an unresolvable value degrades to
 * MYAPI_SERVICE_REQUEST_MAIL_EMPTY rather than an empty line or a thrown
 * error, and the push/inbox text is plain while the mail params are escaped.
 * myapi_service_offer_amount_text() does NOT apply here: a withdrawn offer's
 * amount is no longer actionable information for the resident (see the
 * spec's decisions).
 * ------------------------------------------------------------------------ */

/**
 * Title of the push and of the inbox row sent to the resident (SPEC 111).
 *
 * @return string  Fixed Spanish text.
 */
function myapi_service_offer_withdrawn_push_title() {
  return 'Oferta retirada';
}

/**
 * Body of the push and of the inbox row sent to the resident (SPEC 111).
 *
 * Two lines: the request's own subject and who withdrew — no amount, on
 * purpose (see the spec's decisions).
 *
 * @param string|null $subject        node.title of the request.
 * @param string|null $provider_name  node.title of the provider that
 *   withdrew.
 *
 * @return string  Two lines separated by "\n".
 */
function myapi_service_offer_withdrawn_push_body($subject, $provider_name) {
  return implode("\n", [
    myapi_service_request_notification_label($subject),
    'Proveedor: ' . myapi_service_request_notification_label($provider_name),
  ]);
}

/**
 * Params of the offer-withdrawn email sent to the resident (SPEC 111).
 *
 * Same shape as myapi_service_offer_resident_mail_params() (SPEC 110), minus
 * 'amount_text': myapi_mail_service_request_offer_withdrawn_resident_html()
 * points its button at the URL built here.
 *
 * @param int   $nid     nid of the request (not the offer).
 * @param array $values  Already-resolved 'name' (resident's, NULL when
 *   unresolved), 'subject' and 'provider_name'.
 *
 * @return array  Escaped params for
 *   myapi_mail_format_service_request_offer_withdrawn_resident().
 */
function myapi_service_offer_withdrawn_resident_mail_params($nid, array $values) {
  $values += [
    'name'          => NULL,
    'subject'       => NULL,
    'provider_name' => NULL,
  ];

  return [
    'nid'           => (int) $nid,
    'name'          => $values['name'] !== NULL ? check_plain($values['name']) : NULL,
    'subject'       => myapi_service_request_mail_label($values['subject']),
    'provider_name' => myapi_service_request_mail_label($values['provider_name']),
    'deep_link_url' => myapi_service_request_app_deep_link_url($nid),
  ];
}

/* ---------------------------------------------------------------------------
 * Resolution of the values the three channels share.
 *
 * Each of these runs ONCE per request, no matter how many providers are being
 * notified: the category, the condominium and the unit are properties of the
 * request, not of the recipient. Every one of them degrades to NULL rather than
 * throwing, because a deleted term or an unpublished node must cost a dash in a
 * line and never the 201 of the endpoint.
 * ------------------------------------------------------------------------ */

/**
 * Title of one node, or NULL.
 *
 * db_select() and not node_load(): three titles are needed (condominium, unit
 * and, on a direct request, the provider) and none of their bodies is, so
 * loading three full entities to read three strings would be the expensive way
 * of asking. No status condition — the back office email must still name the
 * unit of a request whose unit was unpublished a minute later.
 *
 * @param int|null $nid  Node id.
 *
 * @return string|null  The title, or NULL when the node is gone.
 */
function myapi_service_request_node_title($nid) {
  $nid = (int) $nid;
  if ($nid <= 0) {
    return NULL;
  }

  $title = db_select('node', 'n')
    ->fields('n', ['title'])
    ->condition('n.nid', $nid)
    ->execute()
    ->fetchField();

  return ($title === FALSE || $title === '') ? NULL : $title;
}

/**
 * Name of one taxonomy term, or NULL.
 *
 * @param int|null $tid  Term id.
 *
 * @return string|null  The name, or NULL when the term is gone.
 */
function myapi_service_request_term_name($tid) {
  $tid = (int) $tid;
  if ($tid <= 0) {
    return NULL;
  }

  $name = db_select('taxonomy_term_data', 'td')
    ->fields('td', ['name'])
    ->condition('td.tid', $tid)
    ->execute()
    ->fetchField();

  return ($name === FALSE || $name === '') ? NULL : $name;
}

/**
 * Email address of one account, or NULL.
 *
 * @param int|null $uid  User id.
 *
 * @return string|null  The address, or NULL when empty or the account is gone.
 */
function myapi_service_request_user_mail($uid) {
  $uid = (int) $uid;
  if ($uid <= 0) {
    return NULL;
  }

  $mail = db_select('users', 'u')
    ->fields('u', ['mail'])
    ->condition('u.uid', $uid)
    ->execute()
    ->fetchField();

  return ($mail === FALSE || $mail === '') ? NULL : $mail;
}

/**
 * Notifies the creation of a service request. THE ENTRY POINT OF THIS SPEC.
 *
 * Called from myapi_service_request_create() after node_save() and after the
 * file_usage_add() calls, so nothing here can leave a file orphaned, and never
 * from a node hook: a request typed into the back office notifies nobody.
 *
 * Order of the work, and why:
 * 1. Resolve every shared label ONCE — the category, the condominium, the unit,
 *    the requester and the dates are the same for all recipients.
 * 2. Decide the audience. A direct request goes to its awarded provider and to
 *    nobody else; an open one, to every ACTIVE provider of its category. The
 *    direct branch does NOT re-check that the provider is active:
 *    myapi_service_request_validate_provider() (SPEC 90) checked it two steps
 *    earlier in this same request, and restating the rule here would be its
 *    third home.
 * 3. One myapi_notification_create() PER PROVIDER, not one for all of them:
 *    provider_id is a column of the row, so an account operating two active
 *    providers of the category deliberately gets two rows and two pushes, one
 *    per provider (the spec's accepted consequence).
 * 4. One mail per ACCOUNT, because a mail goes to an address and not to a
 *    company.
 * 5. The back office mail last, so a failure there cannot cost the providers
 *    their notice.
 *
 * Best-effort from end to end: the whole body is wrapped, every failure lands in
 * watchdog, and none of them undoes the node or changes the 201. Three empty
 * sets are normal and not errors — a category nobody serves, a provider with no
 * account attached, and an empty 'backend' role.
 *
 * @param object $node     The just-saved 'service_request' node.
 * @param array  $context  Ids already resolved and validated by the endpoint:
 *   'unit_id', 'condominium_id', 'category_id', 'assigned_provider_id' (NULL on
 *   an open request) and 'requester_uid'.
 *
 * @return void
 */
function myapi_service_request_notify_created($node, array $context = []) {
  try {
    module_load_include('inc', 'myapi', 'includes/myapi.notification');
    module_load_include('inc', 'myapi', 'includes/myapi.mail_queue');
    module_load_include('inc', 'myapi', 'includes/myapi.user');

    $context += [
      'unit_id'              => NULL,
      'condominium_id'       => NULL,
      'category_id'          => NULL,
      'assigned_provider_id' => NULL,
      'requester_uid'        => NULL,
    ];

    $nid = (int) $node->nid;
    $is_direct = !empty($context['assigned_provider_id']);

    // The audience. Keyed by nid => trade name in both branches, so the loop
    // below does not care which of the two produced it.
    $providers = $is_direct
      ? [(int) $context['assigned_provider_id'] => myapi_service_request_node_title($context['assigned_provider_id'])]
      : myapi_service_request_active_providers_for_category($context['category_id']);

    // The shared labels, resolved once.
    $category = myapi_service_request_term_name($context['category_id']);
    $condominium = myapi_service_request_node_title($context['condominium_id']);
    $desired_start = myapi_service_request_date_label(
      isset($node->field_desired_start[LANGUAGE_NONE][0]['value'])
        ? $node->field_desired_start[LANGUAGE_NONE][0]['value']
        : NULL
    );

    $push_title = myapi_service_request_push_title($is_direct);
    $push_body = myapi_service_request_push_body($node->title, $category, $condominium, $desired_start);

    $provider_params = myapi_service_request_provider_mail_params($nid, [
      'title'         => $node->title,
      'category'      => $category,
      'desired_start' => $desired_start,
      'condominium'   => $condominium,
      'is_direct'     => $is_direct,
    ]);

    foreach ($providers as $provider_id => $provider_name) {
      $uids = myapi_service_request_provider_uids($provider_id);
      if (empty($uids)) {
        continue;
      }

      myapi_notification_create([
        'source_type'      => MYAPI_NOTIFICATION_SOURCE_SERVICE_REQUEST,
        'source_nid'       => $nid,
        'type'             => $is_direct
          ? MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_DIRECT
          : MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_OPEN,
        'title'            => $push_title,
        'body'             => $push_body,
        'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER,
        'deep_link_id'     => $nid,
        'condominium_id'   => $context['condominium_id'],
        // Never the unit: a provider does not learn which home asked until
        // they open the detail endpoint.
        'unit_id'          => NULL,
        'provider_id'      => (int) $provider_id,
        'audience'         => MYAPI_NOTIFICATION_AUDIENCE_PROVIDER,
        'uids'             => $uids,
      ]);

      foreach (user_load_multiple($uids) as $account) {
        myapi_mail_queue_enqueue(
          MYAPI_SERVICE_REQUEST_PROVIDER_MAIL_KEY,
          (string) $account->mail,
          $provider_params
        );
      }
    }

    myapi_service_request_enqueue_admin_mail($node, $context, [
      'category'      => $category,
      'condominium'   => $condominium,
      'desired_start' => $desired_start,
      'is_direct'     => $is_direct,
      'provider_name' => $is_direct ? reset($providers) : NULL,
    ]);
  }
  catch (Exception $e) {
    watchdog_exception('myapi', $e);
  }
}

/**
 * Enqueues the detail email of a new request to the 'backend' role.
 *
 * Split out of the orchestrator because it is the one part that resolves values
 * NO other channel uses — the unit, the requester and their address — and
 * paying those three queries for a role that may be empty would be waste: the
 * recipients are resolved first and the function returns before anything else
 * when nobody holds the role.
 *
 * Building admins are deliberately not here (they are, for claims, in SPEC 68):
 * this spec's audience is the 'backend' role and nothing else.
 *
 * @param object $node     The just-saved 'service_request' node.
 * @param array  $context  The ids handed to myapi_service_request_notify_created().
 * @param array  $labels   Already-resolved 'category', 'condominium',
 *   'desired_start' (formatted), 'is_direct' and 'provider_name'.
 *
 * @return void
 */
function myapi_service_request_enqueue_admin_mail($node, array $context, array $labels) {
  $uids = myapi_notification_role_uids(MYAPI_SERVICE_REQUEST_NOTIFY_ROLE);
  if (empty($uids)) {
    return;
  }

  $requester_uid = (int) $context['requester_uid'];
  $names = myapi_user_display_names([$requester_uid]);

  $params = myapi_service_request_admin_mail_params((int) $node->nid, [
    'title'           => $node->title,
    'provider_name'   => $labels['provider_name'],
    'category'        => $labels['category'],
    'desired_start'   => $labels['desired_start'],
    'unit'            => myapi_service_request_node_title($context['unit_id']),
    'condominium'     => $labels['condominium'],
    'requester'       => isset($names[$requester_uid]) ? $names[$requester_uid] : NULL,
    'requester_email' => myapi_service_request_user_mail($requester_uid),
    'description'     => isset($node->field_description[LANGUAGE_NONE][0]['value'])
      ? $node->field_description[LANGUAGE_NONE][0]['value']
      : NULL,
    'created'         => myapi_service_request_date_label($node->created),
    'is_direct'       => $labels['is_direct'],
  ]);

  foreach (user_load_multiple($uids) as $account) {
    myapi_mail_queue_enqueue(MYAPI_SERVICE_REQUEST_ADMIN_MAIL_KEY, (string) $account->mail, $params);
  }
}

/**
 * Notifies the resident that their request received an offer. SPEC 110'S
 * ENTRY POINT.
 *
 * Called from myapi_service_offer_create() after the offer/transition/
 * transaction writes, so nothing here can undo any of them, and never from a
 * node hook: an offer typed into the back office notifies nobody, same
 * criterion as myapi_service_request_notify_created() above.
 *
 * EVERY VALUE IN $context ARRIVES ALREADY RESOLVED — nothing here re-queries
 * the request or the provider: the endpoint already loaded both rows to run
 * its eligibility gate, and re-reading them would be the same waste
 * myapi_service_request_notify_created() avoids for its own caller.
 *
 * Fires once per POST, no matter how many offers the request already has:
 * each offer is its own event to the resident (the spec's accepted
 * consequence), unlike myapi_service_request_notify_created(), which can
 * notify many providers from one call.
 *
 * Best-effort from end to end, same discipline as the rest of this file: the
 * whole body is wrapped, a failure lands in watchdog, and none of it undoes
 * the offer, the transition or the transaction that were just saved.
 *
 * @param object $offer_node  The just-saved 'service_offer' node.
 * @param array   $context    Values already resolved by
 *   myapi_service_offer_create(): 'request_nid', 'request_title',
 *   'requester_uid', 'condominium_id', 'unit_id', 'provider_id',
 *   'provider_name', 'amount' (NULL on an 'on_site_quote') and 'amount_type'.
 *
 * @return void
 */
function myapi_service_request_notify_offer_received($offer_node, array $context = []) {
  try {
    module_load_include('inc', 'myapi', 'includes/myapi.notification');
    module_load_include('inc', 'myapi', 'includes/myapi.mail_queue');
    module_load_include('inc', 'myapi', 'includes/myapi.user');

    $context += [
      'request_nid'    => NULL,
      'request_title'  => NULL,
      'requester_uid'  => NULL,
      'condominium_id' => NULL,
      'unit_id'        => NULL,
      'provider_id'    => NULL,
      'provider_name'  => NULL,
      'amount'         => NULL,
      'amount_type'    => NULL,
    ];

    // A request always has a requester (a mandatory field since the request
    // was created), but the account behind it may have since been deleted —
    // the same defensive floor myapi_service_request_provider_uids() and
    // myapi_service_request_user_mail() apply elsewhere in this file.
    $requester_uid = (int) $context['requester_uid'];
    $account = $requester_uid > 0 ? user_load($requester_uid) : FALSE;
    if (!$account) {
      return;
    }

    $amount_text = myapi_service_offer_amount_text($context['amount'], $context['amount_type']);

    myapi_notification_create([
      'source_type'      => MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER,
      'source_nid'       => (int) $offer_node->nid,
      'type'             => MYAPI_NOTIFICATION_TYPE_SERVICE_OFFER_RECEIVED,
      'title'            => myapi_service_offer_push_title(),
      'body'             => myapi_service_offer_push_body($context['request_title'], $context['provider_name'], $amount_text),
      'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST,
      'deep_link_id'     => (int) $context['request_nid'],
      // Unlike the provider notice above, this is the resident's OWN request:
      // no privacy reason to withhold either id (see the spec's decisions).
      'condominium_id'   => $context['condominium_id'],
      'unit_id'          => $context['unit_id'],
      'provider_id'      => $context['provider_id'],
      'audience'         => MYAPI_NOTIFICATION_AUDIENCE_RESIDENT,
      'uids'             => [$requester_uid],
    ]);

    // Same resolution as myapi_claim_notification_row()'s requester: SPEC 54
    // profile fields first, the account's own name as the fallback, so
    // "Hola {name}" never needs the account to have filled in a profile.
    $profile = myapi_user_fetch_profile_fields($requester_uid);
    $full_name = trim((string) $profile['first_name'] . ' ' . (string) $profile['last_name']);
    $name = $full_name !== '' ? $full_name : $account->name;

    $params = myapi_service_offer_resident_mail_params($context['request_nid'], [
      'name'          => $name,
      'subject'       => $context['request_title'],
      'provider_name' => $context['provider_name'],
      'amount_text'   => $amount_text,
    ]);

    myapi_mail_queue_enqueue(MYAPI_SERVICE_OFFER_RECEIVED_MAIL_KEY, (string) $account->mail, $params);
  }
  catch (Exception $e) {
    watchdog_exception('myapi', $e);
  }
}
