<?php

/**
 * @file
 * Service offer resource for the myapi module (SPEC 100).
 *
 * Two routes, both authenticated:
 *
 *   - POST /api/v1/service-requests/%/offers — a provider bids on an open
 *     request of their category, with the eleven fields that turn "a text and a
 *     number" into a quote the resident can compare. SINCE SPEC 101 THE SAME
 *     ROUTE ALSO CARRIES THE QUOTE OF AN AWARDED 'direct', where there is
 *     nothing to bid for and everything to price.
 *   - GET /api/v1/service-offers/provider — the provider's own archive
 *     (SPEC 102): "what have I quoted?", paginated and filtered, with eight
 *     referential keys per item. See the nuance below.
 *
 * TWO DISPATCHERS IN ONE FILE, AND THAT IS RULE 2 AND NOT AN EXCEPTION TO IT:
 * one resource, one file. The offer is one resource whether it is written or
 * read, and a resources/service_offer_provider.resource.inc would be two files
 * sharing myapi_service_offer_build(), which is the duplication Rule 3 forbids.
 *
 * THE FIRST WRITE OF THIS MODULE THAT MOVES THE STATE OF SOMEBODY ELSE'S NODE.
 * The provider is not the field_requester of the request and still pushes it
 * from 'open' to 'offered'. That is bounded to ONE transition, in ONE
 * direction, from ONE status, and only after a gate of seven conditions has
 * let them through — and the transition is ASKED of
 * myapi_services_transition_allowed() rather than transcribed, so the day the
 * graph changes this endpoint obeys without anybody remembering it exists.
 *
 * WHY A FILE OF ITS OWN AND NOT A BRANCH OF service_request.resource.inc, which
 * already holds everything this needs: Rules 2 and 5 of CLAUDE.md — one
 * resource, one file, and a resource never calls another resource's internals.
 * The price was the two extractions of SPEC 100's steps 1 and 5
 * (includes/myapi.service_request_query.inc and includes/myapi.service_offer.inc),
 * which is the same toll SPEC 90 paid extracting myapi_node_files_save().
 *
 * THE BODY IS application/json AND NOT multipart/form-data, unlike POST
 * /api/v1/service-requests (SPEC 90): this endpoint uploads no file, and the
 * multipart form exists only for that. Photos of previous jobs and a PDF quote
 * are a spec of their own, and when it arrives it must add an upload route over
 * an already-created offer — NOT change the format of this one, which would
 * break every client already publishing offers (SPEC 100, risk 4).
 *
 * THE REQUEST TRAVELS IN THE ROUTE AND NEVER IN THE BODY. An offer does not
 * exist outside its request, and with the nid in the path there can be no
 * request_id in the body contradicting the URL. Same criterion that hung
 * 'cancel' off the request in SPEC 95.
 *
 * EVERY METHOD BUT POST ANSWERS 405 ON THE BIDDING ROUTE, BEFORE THE TOKEN AND
 * BEFORE ANY QUERY. There is no GET of THE OFFERS OF A REQUEST on purpose: they
 * already travel inside the two details, in `offers` for the resident and in
 * `my_offers` for the provider, and a third place to read them would be a third
 * thing to keep in agreement with offers_count.
 *
 * SPEC 102 DELIMITS THAT DECISION; IT DOES NOT REVERSE IT. The sentence above
 * is about the offers OF A REQUEST — the competition, the set offers_count
 * counts. GET /api/v1/service-offers/provider answers a different question,
 * "what have I quoted?", whose set CROSSES requests instead of living inside
 * one, counts nothing of anybody else and publishes no counter, so there is
 * nothing it can fall out of step with. The offers of a request still have no
 * collection endpoint, and that is still on purpose.
 *
 * NOT IN THIS SPEC: editing or withdrawing an offer, awarding one, the chat,
 * notifying the resident, expiring an offer by its valid_until, and any file.
 * Each of them is its own spec.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
// For MYAPI_SERVICES_* and myapi_services_transition_allowed(): neither a
// bundle machine name nor a status key is ever a literal in this file.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// The role gate of SPEC 78, shared with the two provider endpoints of SPECS 98
// and 99 — the same 403 provider_role_required, decided in one place.
module_load_include('inc', 'myapi', 'includes/myapi.provider_role');
// SPEC 100: the whole domain of the offer — the gate, the body validation, the
// node builder and the serialiser. This file orchestrates; it decides nothing.
module_load_include('inc', 'myapi', 'includes/myapi.service_offer');
// SPEC 100 step 1: myapi_service_request_detail_row(), extracted from
// service_request.resource.inc precisely so this file can ask "does this
// request exist" without reaching into another resource.
module_load_include('inc', 'myapi', 'includes/myapi.service_request_query');
// SPEC 102: the three queries, the scope and the item of the provider's own
// archive. Same split as above — this file orchestrates and decides nothing.
module_load_include('inc', 'myapi', 'includes/myapi.service_offer_query');

// SPEC 103: myapi_user_display_names(), the "nombre apellidos" / users.name rule
// this module writes ONCE. The provider's detail resolves the requester's name
// with it — the one alias myapi_service_request_detail_row() does not project —
// and only when the name is going to travel.
module_load_include('inc', 'myapi', 'includes/myapi.user');

/**
 * Dispatcher for /api/v1/service-requests/%/offers (SPEC 100).
 *
 * POST creates. Everything else answers 405 — including GET, which is not an
 * oversight: the offers of a request are served inside the two details and
 * nowhere else.
 *
 * THE METHOD IS CHECKED BEFORE THE TOKEN AND BEFORE ANY QUERY, the criterion
 * every dispatcher of this module follows: the method is wrong whoever is
 * asking, so answering it costs nothing and leaks nothing.
 *
 * @param mixed $nid  The request nid, straight off the path. Validated by
 *                    myapi_service_offer_create(), not here — a 405 must not
 *                    depend on the shape of the id.
 */
function myapi_service_offer_dispatch($nid = NULL) {
  if (myapi_request_method() === 'POST') {
    myapi_service_offer_create($nid);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * POST /api/v1/service-requests/%/offers — the provider bids (SPEC 100).
 *
 * TEN NUMBERED STEPS, AND THE ORDER IS THE CONTRACT. Every 4xx below is
 * reachable only after every earlier one has passed, which is what makes the
 * error a client reads actionable instead of arbitrary:
 *
 *   1. The nid's shape.        404, with no query at all — not even the token's.
 *   2. Authentication.         401 missing_authorization / invalid_token.
 *   3. The provider role.      403 provider_role_required, before any query
 *                              about providers or requests. An administrator
 *                              without the role is refused like anybody else:
 *                              this is a marketplace actor, not a permission.
 *   4. provider_id.            422 missing_field / invalid_field. It is the
 *                              only body field read before the gate, because
 *                              the gate is ABOUT it.
 *   5. The two rows.           The provider as this account sees it, and the
 *                              request.
 *   6. The gate.               Six conditions, first failure answers.
 *   7. Uniqueness.             409 service_offer_already_sent, last of the
 *                              seven because it is the only one whose query can
 *                              be skipped when an earlier condition fails.
 *   8. The body.               Ten rules, all 422.
 *   9. The three writes.
 *  10. 201.
 *
 * STEPS 3 TO 7 ALL RUN BEFORE A SINGLE FIELD OF THE BODY IS VALIDATED, the
 * criterion step 6 of SPEC 95 set and this file inherits: WHO YOU ARE DOES NOT
 * DEPEND ON WHAT YOU WROTE. An empty body over a cancelled request answers 409,
 * never 422.
 *
 * provider_id IS ALWAYS REQUIRED, even for an account that operates a single
 * provider. Deriving it would choose in silence, and the day that account
 * operated two, a client that never sent the field would start bidding with the
 * wrong company without anything failing (SPEC 100, decision 10).
 *
 * THE THREE WRITES, IN THIS ORDER, AND ONLY THE FIRST IS UNCONDITIONAL:
 *
 *   9a. The offer. What the provider asked to create, and the one write that
 *       cannot be missing.
 *   9b. The request, ONLY IF IT MOVES. 'open' → 'offered', asked of
 *       myapi_services_transition_allowed(). Already 'offered' writes NOTHING.
 *   9c. The transaction, IF THE STATUS MOVED — OR IF A 'direct' WAS QUOTED.
 *       'service_transaction' has been "one entry per status change" since
 *       SPEC 77, and the third offer on an already-'offered' request changes
 *       none. Recording it would write a timeline row whose
 *       field_request_status repeats the one before it; the resident learns
 *       about the second and third offers from offers_count and from `offers`,
 *       not from the history.
 *
 *       SPEC 101 RELAXES THAT RULE IN EXACTLY ONE CASE, on purpose. A quote on
 *       a 'direct' writes a transaction whose field_request_status REPEATS the
 *       'direct' the request already had, because the status did not move and
 *       saying otherwise would be a lie. It is written anyway because it is the
 *       one thing the 'open' → 'offered' move gave that would otherwise be
 *       lost: the resident seeing "your provider sent you a quote" in their
 *       timeline. The COMMENT is what tells the two entries apart, which is why
 *       the two are different sentences — see
 *       myapi_service_offer_direct_quote_comment().
 *
 * The order is SPEC 95's: first what the user asked for, then what follows from
 * it. IT IS NOT ATOMIC, and that is accepted: if 9b or 9c failed, an offer
 * would be left on an 'open' request — which is the state the module ALREADY
 * admits today, since an offer created from the back office moves nothing, and
 * which myapi_service_request_update_gate() already defends against by counting
 * offers instead of trusting the status. The failure is logged rather than
 * swallowed (SPEC 100, risk 5).
 *
 * NEITHER field_assigned_offer NOR field_assigned_provider IS EVER TOUCHED:
 * bidding is not awarding, and awarding is another spec, on the resident's
 * side. That holds for a 'direct' too, and there it means something stronger —
 * the request is NOT SAVED AT ALL. Its status, its awarded provider and its
 * `changed` come out of this endpoint exactly as they went in.
 *
 * A 'direct' STAYS 'direct', AND THAT IS THE CENTRAL DECISION OF SPEC 101, not
 * an omission. Moving it to 'offered' would let it be closed WITHOUT RATING the
 * provider, silently and with no test going red, because
 * myapi_services_close_requires_rating() answers TRUE for 'assigned' and
 * 'direct' and not for 'offered'. Moving it to 'assigned' would record as
 * agreed a price the resident never accepted. Standing still is the only option
 * that breaks nothing.
 *
 * @param mixed $nid  The request nid, straight off the path.
 */
function myapi_service_offer_create($nid) {
  // 1. The nid, before anything else — including the token. ctype_digit() over
  // the string form rejects 'abc', '-3', '1.5' and '' alike, and the > 0 then
  // rejects '0'. Costs no query, and 'service_request_not_found' is the honest
  // answer: the URL names a request that cannot exist.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('service_request_not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The role gate, shared with SPECS 98 and 99 — same code, same function,
  // decided in one place. The account is loaded because the gate reads
  // ->roles; the token check already touched the users table, so it costs
  // nothing new. An administrator without the role lands here too: operating a
  // provider is a marketplace fact, not a permission.
  $account = user_load($uid);
  if (!myapi_provider_role_is($account)) {
    myapi_error('provider_role_required', 403);
  }

  // 4. provider_id — the ONLY body field read before the gate, because the
  // gate is about it. Same shape check as the nid above: a JSON integer or a
  // string of digits, greater than zero.
  $body = myapi_request_body();
  if (!is_array($body) || !array_key_exists('provider_id', $body)) {
    myapi_error('missing_field', 422, ['@field' => 'provider_id']);
  }
  $provider_id = $body['provider_id'];
  if (is_bool($provider_id) || !is_scalar($provider_id)
    || !ctype_digit((string) $provider_id) || (int) $provider_id <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'provider_id']);
  }
  $provider_id = (int) $provider_id;

  // 5. The two rows the gate decides over. The provider first: it is the
  // cheaper of the two and the one that fails more often.
  $provider_row = myapi_service_offer_provider_row($provider_id, $uid);
  $request_row = myapi_service_request_detail_row($nid);

  // 6. The gate — six conditions, in order, first failure answers. The mapping
  // from code to HTTP status is the ONE thing this file adds to it: everything
  // about WHO may bid is a 403, and everything about WHETHER THIS REQUEST still
  // takes offers is a 409 (or the 404 of a request that is not there).
  $error = myapi_service_offer_eligibility($request_row, $provider_row, $uid, REQUEST_TIME);
  if ($error !== NULL) {
    $statuses = [
      'service_offer_provider_not_owned'  => 403,
      'service_offer_provider_not_active' => 403,
      'service_request_not_found'         => 404,
      'service_offer_own_request'         => 403,
      'service_request_not_offerable'     => 409,
      'service_offer_category_mismatch'   => 403,
    ];
    myapi_error($error, isset($statuses[$error]) ? $statuses[$error] : 403);
  }

  // 7. Uniqueness — the seventh condition of the gate, kept out of the pure
  // function because it is the only one that costs a query of its own, and the
  // only one that can be skipped whenever an earlier one has already answered.
  // 'rejected' and 'withdrawn' do NOT block: a dead offer does not compete.
  if (myapi_service_offer_live_offer_exists($nid, $provider_id)) {
    myapi_error('service_offer_already_sent', 409);
  }

  // 8. The body, and only now. Every failure here is a 422, which is why the
  // status is written once instead of mapped.
  $validated = myapi_service_offer_validate_body($body);
  if (!$validated['ok']) {
    myapi_error($validated['error_code'], 422, $validated['replacements']);
  }
  $values = $validated['values'];

  // 9a. The offer. myapi_service_offer_build_node() decides its whole shape —
  // including the seven values the server fixes and the client cannot send —
  // and this line is only the save.
  $provider_name = isset($provider_row->title) ? $provider_row->title : NULL;
  $offer = myapi_service_offer_build_node($uid, $nid, $provider_id, $provider_name, $values);
  node_save($offer);

  // 9b. The request, ONLY IF IT MOVES. The transition is ASKED and never
  // transcribed, so the graph of services_common stays the single truth. A
  // request already in 'offered' is not saved at all — not a no-op save, no
  // save — which is what keeps its `changed` timestamp honest.
  //
  // node_load() and not the four columns of $request_row: a write needs the
  // whole entity with its fields, the same argument step 3 of SPEC 95 makes.
  $moved = FALSE;
  if ($request_row->status === MYAPI_SERVICES_REQUEST_STATUS_OPEN
    && myapi_services_transition_allowed(MYAPI_SERVICES_REQUEST_STATUS_OPEN, MYAPI_SERVICES_REQUEST_STATUS_OFFERED)) {
    $request_node = node_load($nid);

    // Defensive: the row above proves the request exists, so this can only fail
    // on a race. The offer is already written and correct, so the truthful
    // thing is to keep going and log, never to fatal after a successful write.
    if ($request_node && $request_node->type === MYAPI_SERVICES_REQUEST_TYPE) {
      $request_node->field_request_status[LANGUAGE_NONE][0]['value'] = MYAPI_SERVICES_REQUEST_STATUS_OFFERED;
      node_save($request_node);
      $moved = TRUE;
    }
    else {
      watchdog(
        'myapi',
        'SPEC 100: la oferta @offer se guardó sobre la solicitud @request, pero la solicitud no se pudo cargar para moverla a @status. La oferta es válida; el estado de la solicitud hay que revisarlo a mano.',
        ['@offer' => $offer->nid, '@request' => $nid, '@status' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED],
        WATCHDOG_ERROR
      );
    }
  }

  // 9c. The transaction, IF THE STATUS ACTUALLY MOVED — OR IF THIS WAS THE
  // QUOTE OF A 'direct' (SPEC 101). See the docblock on why the second and
  // third offers on an 'offered' request still write nothing here, and on why
  // the 'direct' is the one case that writes an entry without a status change.
  //
  // $request_row->status and not a re-read: 9b did not save this request — a
  // 'direct' never enters that branch — so the row still says what the database
  // says. The two flags are mutually exclusive: $moved is only ever set from
  // 'open'.
  $direct_quote = ($request_row->status === MYAPI_SERVICES_REQUEST_STATUS_DIRECT);

  // The four fields of SPEC 92; the title is NOT set,
  // myapi_service_transaction_set_title() puts it in from hook_node_presave(),
  // inside the node_save() below.
  if ($moved || $direct_quote) {
    $transaction = (object) [
      'type' => MYAPI_SERVICES_TRANSACTION_TYPE,
      // The ACCOUNT that bid, which is who is behind the token — not the
      // provider node, and not the requester.
      'uid' => $uid,
      'status' => 1,
    ];
    node_object_prepare($transaction);

    $transaction->field_request[LANGUAGE_NONE][0]['target_id'] = $nid;
    // On a 'direct' this REPEATS the status the request already had, and that
    // is the truth: the status did not change. The comment below is what makes
    // the entry legible.
    $transaction->field_request_status[LANGUAGE_NONE][0]['value'] = $moved
      ? MYAPI_SERVICES_REQUEST_STATUS_OFFERED
      : MYAPI_SERVICES_REQUEST_STATUS_DIRECT;
    // 'Y-m-d H:i:00': the real instant with the seconds pinned, not that day at
    // midnight. field_status_date is 'datetime' and not 'datestamp' (SPEC 77),
    // so a string is written and not a timestamp.
    $transaction->field_status_date[LANGUAGE_NONE][0]['value'] = date('Y-m-d H:i:00');
    // Only 'value', no 'format': stored raw and escaped by whoever renders it.
    // Two sentences and not one — on a 'direct' the status column repeats, so
    // the comment is the ONLY thing that tells this entry from the one before.
    $transaction->field_comment[LANGUAGE_NONE][0]['value'] = $moved
      ? myapi_service_offer_transaction_comment($provider_name)
      : myapi_service_offer_direct_quote_comment($provider_name);

    node_save($transaction);
  }

  // 10. The answer. THE OBJECT UNDER 'service_offer' IS BYTE FOR BYTE THE
  // ELEMENT THAT WILL APPEAR IN `my_offers` the next time this provider reads
  // GET /api/v1/service-requests/provider/%, because it comes out of the SAME
  // serialiser. That is an acceptance criterion, and reusing
  // myapi_service_offer_build() is how this file cannot break it by accident.
  //
  // The row is assembled in code and not re-read with a query: everything in it
  // was either sent by the provider and validated, or fixed by the server one
  // screen above. A SELECT here would cost a round trip to learn what this
  // function already knows.
  $offer_row = (object) [
    'nid'               => $offer->nid,
    'provider_id'       => $provider_id,
    'provider_name'     => $provider_name,
    // The logo is not read: it costs a query and a join for a value the
    // provider's own app already has. It travels in `my_offers`, where the
    // resident's screen needs it.
    'provider_logo_uri' => NULL,
    'amount'            => $values['amount'],
    'message'           => $values['message'],
    'status'            => MYAPI_SERVICES_OFFER_STATUS_SENT,
    'created'           => isset($offer->created) ? $offer->created : REQUEST_TIME,
    'amount_type'       => $values['amount_type'],
    'valid_until'       => $values['valid_until'],
    'available_from'    => $values['available_from'],
    'duration'          => $values['duration'],
    'duration_unit'     => $values['duration_unit'],
    'includes'          => $values['includes'],
    'excludes'          => $values['excludes'],
    'tax_included'      => $values['tax_included'] === NULL ? NULL : ($values['tax_included'] ? 1 : 0),
    'warranty_days'     => $values['warranty_days'],
    'requires_visit'    => $values['requires_visit'] ? 1 : 0,
  ];

  // `request` is a SIBLING of 'service_offer' and not a sixteenth key of it. It
  // carries the one thing the provider cannot deduce from what they just sent —
  // whether their offer was the first, and so whether the request moved — and
  // it is known in code, with no extra query. Same criterion with which SPEC 95
  // put 'offers_rejected' next to 'service_request' and not inside it.
  myapi_respond([
    'service_offer' => myapi_service_offer_build($offer_row),
    'request'       => [
      'id'     => $nid,
      'status' => $moved
        ? MYAPI_SERVICES_REQUEST_STATUS_OFFERED
        : $request_row->status,
    ],
  ], 201, 'service_offer_created');
}

/* ---------------------------------------------------------------------------
 * GET /api/v1/service-offers/provider — the provider's own archive (SPEC 102).
 *
 * The second dispatcher of this file, and the first read of the offer resource.
 * Everything it decides lives elsewhere: the SQL in
 * includes/myapi.service_offer_query.inc, the role gate in
 * includes/myapi.provider_role.inc, the format of an id in
 * includes/myapi.request.inc. What these functions own is the ORDER, and the
 * order is the contract.
 * ------------------------------------------------------------------------ */

/**
 * Routes /api/v1/service-offers/provider by HTTP method (SPEC 102).
 *
 * GET AND NOTHING ELSE. POST, PUT, PATCH and DELETE answer 405, and there is no
 * accident waiting here: creating an offer is the other route of this file,
 * and editing, withdrawing or awarding one is each its own spec. A dispatcher
 * that refused everything but GET is what keeps that visible.
 *
 * THE 405 COMES BEFORE THE TOKEN AND BEFORE ANY QUERY, the criterion every
 * dispatcher of this module follows: a POST to a GET-only route is wrong
 * whoever is asking, and answering 401 first would tell a client with a broken
 * verb to go fix its credentials.
 */
function myapi_service_offer_provider_dispatch() {
  if (myapi_request_method() === 'GET') {
    myapi_service_offer_provider_list();
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * GET /api/v1/service-offers/provider — what have I quoted? (SPEC 102).
 *
 * EIGHT NUMBERED STEPS, AND THE ORDER IS THE CONTRACT:
 *
 *   1. The method.        Already answered by the dispatcher, before the token.
 *   2. Authentication.    401 missing_authorization / invalid_token.
 *   3. The provider role. 403 provider_role_required, before any query about
 *                         providers or offers. An administrator without the
 *                         role is refused like anybody else: this is a
 *                         marketplace actor, not a permission.
 *   4. provider_id.       422 missing_field when absent, 422 invalid_field when
 *                         malformed — AND NOT A SINGLE OFFER QUERY RUNS FIRST.
 *   5. The rest.          Five lax filters plus page/limit/sort. No 422 lives
 *                         here: garbage falls back, unknown keys are dropped in
 *                         silence, and an unknown parameter is ignored.
 *   6. The scope.         FALSE — foreign or non-existent — is a 200 with an
 *                         empty list, never a 403 and never a 404.
 *   7. Count, then page.  In that order, because `pagination` describes the
 *                         FILTERED set and not the page.
 *   8. 200.
 *
 * '?provider_id' IS PARSED AFTER THE GATE AND BEFORE EVERYTHING ELSE. After,
 * because a reader who may not be here should not learn whether their parameter
 * was well formed; before, because a 422 must not cost a query. Same order
 * myapi_service_request_provider_list() fixed for the same parameter.
 *
 * IT IS THE ONE MANDATORY PARAMETER OF THIS ENDPOINT, and the ONE difference
 * with SPEC 98's version of it. An account that operates two providers and asks
 * without saying which is asking something this endpoint does not answer, and
 * "both mixed together" would be inventing an aggregate format nobody designed.
 * Deriving it for an account that operates a single provider is the decision
 * SPEC 100 already refused: choosing in silence works until the day there are
 * two, and then a client that never sent the field starts reading the wrong
 * list without anything failing. It is born strict because relaxing it later is
 * compatible and tightening it is not.
 *
 * STRICT IN THE FORMAT, LAX IN THE OWNERSHIP — the rule of SPEC 98, applied to
 * a mandatory parameter. 'abc', '0', '-1', '1,2' or ' 41' is a broken client
 * and earns its 422 before a single query; a nid that belongs to somebody else,
 * or to no node at all, is NOT an error and answers 200 with an empty list. A
 * 403 here would only turn a listing into an oracle for "this nid exists and is
 * not yours", which is the opposite of protecting anything.
 *
 * THE ONLY 403 IS THE ROLE, AND IT REUSES 'provider_role_required' (SPEC 97).
 * Not a new i18n key: the cause is identical to GET /api/v1/providers/mine and
 * to GET /api/v1/service-requests/provider. An account WITH the role and NO
 * linked provider node reaches step 6 and answers an empty list, never a 403:
 * having the role and being linked to nothing is missing data, not a missing
 * permission. There is no exception for administrators.
 *
 * SIX QUERIES, FIXED — token, account, link, provider, count, page — AND NOT
 * ONE OF THEM GROWS WITH THE PAGE. The provider is resolved ONCE, before the
 * loop, and copied into every item; fifty offers cost what one costs.
 *
 * NO LICENCE, NO PUBLISHED FLAG. A suspended provider and one whose
 * field_license_expiry passed both read their whole archive. The licence
 * governs the market — being able to quote — and not the record of what was
 * already quoted; see myapi_service_offer_provider_scope().
 */
function myapi_service_offer_provider_list() {
  // 2. Authentication. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();

  // The account, because the gate reads ->roles and the token row does not
  // carry them. Not an extra query: myapi_auth_require_access_token() already
  // loaded this user and Drupal 7 serves the second call from its static cache.
  $account = user_load($token->uid);

  // 3. The gate, and the only 403 of this endpoint — see the docblock.
  if (!myapi_provider_role_is($account)) {
    myapi_error('provider_role_required', 403);
  }

  // 4. The one parameter that is REQUIRED, and the only one that can be wrong
  // rather than merely unknown. Two refusals, in this order: absent, then
  // malformed — and myapi_parse_id_param() never returns on the second.
  if (!isset($_GET['provider_id'])) {
    myapi_error('missing_field', 422, ['@field' => 'provider_id']);
  }

  $provider_id = myapi_parse_id_param('provider_id');

  // 5. Pagination and sort, read before the short circuit so the empty answer
  // still reports the limit the caller asked for. Same idiom as the two request
  // listings: garbage falls back to the default with no 422, '-1' is
  // "everything on one page" (SPEC 15), and anything above 50 is trimmed.
  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && $_GET['limit'] === '-1'
    ? -1
    : (isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
      ? max(1, min(50, (int) $_GET['limit']))
      : 20);

  if ($limit === -1) {
    $page = 1;
  }

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  // 6. The provider, resolved ONCE for the whole page — and the gate that is
  // not a gate: FALSE means foreign, non-existent or of another bundle, and all
  // three answer the SAME empty list. `page` is forced to 1 and `total_pages`
  // is 0 — not 1 — because there is nothing to page through; `limit` echoes
  // what the caller asked for, so a client that sent '?limit=50' is not told it
  // got 20.
  $provider = myapi_service_offer_provider_scope($provider_id, $token->uid);

  if ($provider === FALSE) {
    myapi_respond([
      'service_offers' => [],
      'pagination'     => [
        'total'       => 0,
        'page'        => 1,
        'limit'       => $limit,
        'total_pages' => 0,
      ],
    ], 200);
  }

  // The five lax filters. '?category_id' is the only one of them that can
  // answer 422, and it does so for the reason its twin in the request listings
  // does: the SAME parameter name in the SAME domain already answers 422 in
  // GET /api/v1/providers and in the two request listings, and having it mean
  // two different things across sibling endpoints would force the client to
  // remember which is which.
  $created = myapi_service_offer_provider_parse_created_range();

  $filters = [
    'status'         => myapi_service_offer_provider_parse_status(),
    'request_status' => myapi_service_offer_provider_parse_request_status(),
    'category_id'    => myapi_parse_id_param('category_id'),
    'created_from'   => $created['from'],
    'created_to'     => $created['to'],
  ];

  // 7. Count first — the pagination block describes the FILTERED set and not
  // the page — then the page itself.
  $total = myapi_service_offer_provider_count($provider_id, $filters);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_service_offer_provider_fetch($provider_id, $filters, $page, $limit, $sort);

  // The provider is resolved, so the loop joins nothing: the same {id, name} is
  // copied into every item.
  $service_offers = array_map(function ($row) use ($provider) {
    return myapi_service_offer_provider_build_item($row, $provider);
  }, $rows);

  // 8. Respond 200 (read-only endpoint, no message_key). The same `pagination`
  // block, byte for byte, as the two request listings.
  myapi_respond([
    'service_offers' => $service_offers,
    'pagination'     => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * Reads '?status' into a list of OFFER status keys, or NULL for no filter
 * (SPEC 102).
 *
 * LAX, like every parameter of this endpoint except '?provider_id' and
 * '?category_id': an unknown key is dropped in silence rather than answered
 * with a 422, and '?status=sent,invented' filters by 'sent' alone. That is the
 * idiom of the whole module, and it is the same function
 * myapi_service_request_parse_status() is, over the OTHER catalogue.
 *
 * THE WHITELIST IS THE CATALOGUE, NEVER A LIST TYPED HERE. It reads
 * myapi_services_offer_statuses(), the same function that configures the
 * allowed_values of field_offer_status in myapi.install, so the day the
 * catalogue gains a fifth status the filter accepts it with no change to this
 * file.
 *
 * Duplicates are collapsed and the order of the query string is preserved;
 * neither matters to the SQL, both keep the value predictable in a test.
 *
 * @return array|NULL
 *   The valid offer status keys, or NULL when the parameter is absent, is not a
 *   scalar, or carries no valid key at all — the three cases mean "no filter".
 */
function myapi_service_offer_provider_parse_status() {
  return myapi_service_offer_provider_parse_keys('status', myapi_services_offer_statuses());
}

/**
 * Reads '?request_status' into a list of REQUEST status keys, or NULL
 * (SPEC 102).
 *
 * TWO PARAMETERS AND NOT ONE, because they are two catalogues with no value in
 * common (SPEC 77): 'sent' is not a state a request can be in and 'cancelled'
 * is not a state an offer can be in. Merging them into one '?status' would make
 * '?status=cancelled' ambiguous the day either catalogue grew a shared word,
 * and would hide from the client which of the two things it is filtering.
 *
 * Same laxity and same catalogue-as-whitelist rule as '?status' above, over
 * myapi_services_request_statuses() — the function that configures
 * field_request_status, so 'direct' (SPEC 87) is accepted here without this
 * file knowing it exists.
 *
 * @return array|NULL
 *   The valid request status keys, or NULL when there is no filter.
 */
function myapi_service_offer_provider_parse_request_status() {
  return myapi_service_offer_provider_parse_keys('request_status', myapi_services_request_statuses());
}

/**
 * The lax catalogue parser both status filters above are made of (SPEC 102).
 *
 * ONE FUNCTION AND NOT TWO COPIES: the two parameters do exactly the same thing
 * over two different catalogues, and what differs between them is only WHICH
 * catalogue and WHY, which is written in the wrapper that carries its name.
 * Regla 3 de CLAUDE.md applied inside a file rather than between two of them,
 * the same shape myapi_service_request_parse_id_param() has.
 *
 * NOT SHARED WITH resources/service_request.resource.inc, deliberately.
 * myapi_service_request_parse_status() is the same six lines over its own
 * catalogue, and Rule 5 forbids this file from calling it. Lifting BOTH into
 * includes/myapi.request.inc is the right move and it is not this spec's:
 * SPEC 102's step 1 extracted the ONE parser a second resource needed, and
 * moving a third function nobody asked for would widen a spec whose risk table
 * is about not moving anything else.
 *
 * @param string $name      The query-string parameter to read.
 * @param array  $allowed   The catalogue, as key => label.
 *
 * @return array|NULL  The valid keys, or NULL for "no filter".
 */
function myapi_service_offer_provider_parse_keys($name, array $allowed) {
  if (!isset($_GET[$name]) || !is_scalar($_GET[$name])) {
    return NULL;
  }

  $keys = [];
  foreach (explode(',', (string) $_GET[$name]) as $item) {
    $key = trim($item);
    if (isset($allowed[$key]) && !in_array($key, $keys, TRUE)) {
      $keys[] = $key;
    }
  }

  return $keys ? $keys : NULL;
}

/**
 * Reads '?date_from' / '?date_to' into a range of OFFER n.created timestamps,
 * or NULLs for no filter (SPEC 102).
 *
 * THE PARSING IS NOT WRITTEN HERE. myapi_parse_date_range_param()
 * (includes/myapi.request.inc) is the one place this module reads that pair: it
 * keeps a bound only when it is a real ISO 'YYYY-MM-DD' calendar date, drops
 * anything else in silence, and drops the WHOLE range when the two bounds
 * arrive inverted. No 422 ever, exactly like '?page', '?limit', '?sort' and the
 * two status filters here.
 *
 * WHAT THIS FUNCTION ADDS IS THE COLUMN'S TYPE, and that is its whole reason to
 * exist — the same one myapi_service_request_parse_created_range() has. The
 * filter is on n.created, an integer Unix timestamp, so each bound becomes the
 * instant that makes the DAY inclusive: the lower one the site-local 00:00:00
 * of date_from, the upper one the site-local 23:59:59 of date_to. An offer sent
 * at 23:50 of '?date_to' is INSIDE the range, which is what a bare midnight
 * would have silently dropped.
 *
 * THE COLUMN IS THE OFFER'S created — when I quoted — AND NEVER THE REQUEST'S.
 * '?sort' orders by that same column, and a range over the column the client
 * sorts by is the only combination that reads consistently. A filter over the
 * request's date, or over valid_until, is another parameter and another spec.
 *
 * @return array  ['from' => int|NULL, 'to' => int|NULL] Unix timestamps.
 */
function myapi_service_offer_provider_parse_created_range() {
  $range = myapi_parse_date_range_param();

  return [
    'from' => $range['from'] !== NULL ? strtotime($range['from'] . ' 00:00:00') : NULL,
    'to'   => $range['to'] !== NULL ? strtotime($range['to'] . ' 23:59:59') : NULL,
  ];
}

/* ---------------------------------------------------------------------------
 * The detail of ONE offer — two routes, two audiences (SPEC 103).
 *
 * GET /api/v1/service-offers/provider/%  the provider who sent it.
 * GET /api/v1/service-offers/%           the resident who received it.
 *
 * TWO SIBLING DISPATCHERS AND TWO SIBLING HANDLERS, NEVER ONE WITH A BRANCH
 * INSIDE (decision 1). SPEC 89 served two readers through one route and paid
 * for it with eighteen keys that change content depending on who looks; SPEC 99
 * walked that back and opened a route of its own for the provider. This spec
 * starts at the end of that story. The role gate is the plainest reason it
 * could not be one route: a shared dispatcher would either demand the
 * 'proveedor' role of a resident or stop demanding it of the provider.
 *
 * NEITHER OF THEM OWNS AN ACCESS RULE. The provider goes through
 * myapi_service_offer_provider_scope() (SPEC 102), the resident through
 * myapi_service_request_viewer() (SPEC 89), and both are used UNMODIFIED. If
 * this endpoint and the request's own detail ever disagreed about who may read
 * what, that would be a bug and not a nuance — the symptom being a reader who
 * can open the offer but not the request it hangs on, with nothing failing
 * loudly.
 *
 * NEITHER OF THEM WRITES ANYTHING, and neither publishes a counter. That is
 * what keeps them outside the sentence of SPEC 100 — "there is no GET of the
 * collection on purpose" — which is about the offers OF A REQUEST: the
 * competition, the set offers_count counts. These serve ONE offer to somebody
 * who already had the right to read it.
 *
 * What these four functions own is the ORDER, and the order is the contract.
 * ------------------------------------------------------------------------ */

/**
 * Routes /api/v1/service-offers/provider/% by HTTP method (SPEC 103).
 *
 * A SIBLING of myapi_service_offer_provider_dispatch(), not a branch inside it:
 * Drupal 7 resolves routes by number of segments first, so the listing and the
 * item are two menu entries that never compete, and one dispatcher per route
 * keeps each one's 405 as simple as it looks.
 *
 * GET AND NOTHING ELSE. Editing, withdrawing and awarding an offer are three
 * writes and three specs of their own; until they arrive, a dispatcher that
 * refuses everything but GET is what keeps that visible.
 *
 * THE 405 COMES BEFORE THE TOKEN AND BEFORE ANY QUERY, the criterion every
 * dispatcher of this module follows: a POST to a GET-only route is wrong
 * whoever is asking.
 */
function myapi_service_offer_provider_item_dispatch($nid = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_service_offer_provider_detail($nid);
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * GET /api/v1/service-offers/provider/% — one of my offers, whole (SPEC 103).
 *
 * WHAT IT CLOSES. GET /api/v1/service-offers/provider is REFERENTIAL by
 * decision — eight keys per item — and refers the whole quote to
 * GET /api/v1/service-requests/provider/%, inside `my_offers`. So reading the
 * fifteen keys of ONE offer meant downloading the entire detail of its request:
 * its images, its timeline, the count of the competition and the rest of one's
 * own offers. A row of that listing could not be opened. Now it can.
 *
 * EIGHT NUMBERED STEPS, AND THE ORDER IS THE CONTRACT:
 *
 *   1. The nid.        404 not_found when it is not a positive integer, before
 *                      the token and before any query.
 *   2. Authentication. 401 missing_authorization / invalid_token.
 *   3. The role.       403 provider_role_required, BEFORE ANY QUERY ABOUT
 *                      OFFERS. An administrator without the role is refused
 *                      like anybody else: this is a marketplace actor, not a
 *                      permission.
 *   4. The offer.      FALSE — no such offer, unpublished, another bundle, or
 *                      its request not published — is 404, and the four are
 *                      told apart by nobody.
 *   5. Ownership.      myapi_service_offer_provider_scope() over the RAW
 *                      provider column, FALSE is 403 forbidden.
 *   6. The request.    Its context, from the function that owns it.
 *   7. Is it mine?     The award decides what `unit` and `requester` carry.
 *   8. 200.
 *
 * A FOREIGN OFFER IS 403 AND NOT 404 (decision 6). The module already answers
 * 403 to a foreign REQUEST, and a provider debugging its integration has to be
 * able to tell "not yours" from "does not exist". What the 403 leaks — that an
 * offer with that nid exists — says nothing about whose it is, for how much, or
 * on what.
 *
 * THE GATE READS `provider_raw` AND NOT `provider_id`, and that is deliberate:
 * the raw column is the offer's owner even when the provider node is
 * unpublished, and SPEC 102 decided that a suspended provider still reads its
 * own archive — the licence governs the market, not the record of what was
 * already quoted. Gating on the joined column would answer 403 to an account
 * for ITS OWN offer the day its provider node was suspended.
 *
 * IT IS THE SAME myapi_service_offer_provider_scope() THE LISTING CALLS, with
 * no copy and no variant (riesgo 5). While that stays true, an item that
 * appears in the listing can always be opened; the day one of them grows a
 * condition the other lacks, the symptom is a row answering 403 when tapped and
 * nothing failing loudly.
 *
 * FIVE QUERIES, FIXED — token, account, link, offer, request — plus the one
 * that resolves the requester's name, and that sixth one only runs when the
 * name is going to travel. Nothing here grows with anything.
 *
 * NO LICENCE, NO PUBLISHED FLAG, NO STATUS OF EITHER KIND. An offer 'withdrawn'
 * on a request 'cancelled' from a suspended provider whose licence expired is
 * served whole. The servable set is three conditions and they are all in
 * myapi_service_offer_detail_row().
 *
 * @param mixed $nid  The OFFER's nid, from the route.
 */
function myapi_service_offer_provider_detail($nid) {
  // 1. The nid, before anything else — including the token. ctype_digit() over
  // the string form rejects 'abc', '-1', '1.5' and '' alike; the > 0 then
  // rejects '0'. Nothing is leaked by answering first: the answer is about the
  // SHAPE of the URL and not about what exists. Byte for byte the guard
  // myapi_service_request_provider_detail() opens with.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 missing_authorization / invalid_token on
  // its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The role gate, before a single query about offers. The account is
  // loaded because the gate reads ->roles; Drupal 7 serves it from the static
  // cache myapi_auth_require_access_token() already filled, so it costs no
  // query.
  $account = user_load($uid);
  if (!myapi_provider_role_is($account)) {
    myapi_error('provider_role_required', 403);
  }

  // 4. The offer. FALSE covers all four "no such offer" cases at once and the
  // reader is told none of them apart.
  $row = myapi_service_offer_detail_row($nid);
  if (!$row) {
    myapi_error('not_found', 404);
  }

  // 5. Whose offer this is — the RAW column, see the docblock. FALSE means
  // foreign, non-existent or of another bundle, and all three are 403 here:
  // this is an item and not a listing, so there is no empty list to answer
  // with, and the module answers 403 to a foreign request already.
  if (myapi_service_offer_provider_scope($row->provider_raw, $uid) === FALSE) {
    myapi_error('forbidden', 403);
  }

  // 6. The context, from the ONE function that knows what a request is. The
  // INNER JOIN of step 4 already guaranteed the request is published, so a
  // FALSE here is corrupt data — an orphaned category tid — and it answers the
  // same 404 as the rest: a response the client could not paint is not half a
  // response.
  $request_row = myapi_service_request_detail_row($row->request_id);
  if (!$request_row) {
    myapi_error('not_found', 404);
  }

  // 7. "Is this job already mine?" — over the AWARD of the request and never
  // over the status of my offer (decision 5). It governs `unit` and
  // `requester`, and nothing else.
  $provider_ids = myapi_provider_role_provider_ids($account);
  $is_mine = myapi_service_offer_detail_is_mine($request_row, $provider_ids);

  // The requester's name, by the shared rule of includes/myapi.user.inc, and
  // ONLY when it is going to travel: the query is skipped outright when the job
  // is not mine, because the key is a whole null then. It is the one alias
  // myapi_service_request_detail_row() does not project. NO CONTACT DETAIL is
  // fetched — not a phone, not an email, not an address.
  $request_row->requester_name = NULL;
  if ($is_mine && isset($request_row->requester_uid) && $request_row->requester_uid !== '') {
    $names = myapi_user_display_names([(int) $request_row->requester_uid]);
    $request_row->requester_name = isset($names[(int) $request_row->requester_uid])
      ? $names[(int) $request_row->requester_uid]
      : NULL;
  }

  // 8. Respond 200 (read-only endpoint, no message_key). One object under
  // 'service_offer', never a list.
  myapi_respond([
    'service_offer' => myapi_service_offer_build_detail($row, $request_row, $is_mine, $is_mine),
  ], 200);
}

/**
 * Routes /api/v1/service-offers/% by HTTP method (SPEC 103).
 *
 * A SIBLING of myapi_service_offer_provider_item_dispatch(), and the reason
 * they are two and not one is the role: this route demands none, and that one
 * demands 'proveedor' before it queries anything. A shared dispatcher would
 * either demand it of a resident or stop demanding it of the provider.
 *
 * GET AND NOTHING ELSE, and the 405 before the token — the criterion of every
 * dispatcher in this module.
 */
function myapi_service_offer_item_dispatch($nid = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_service_offer_requester_detail($nid);
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * GET /api/v1/service-offers/% — one of the offers I received (SPEC 103).
 *
 * WHAT IT CLOSES. The offers of a request already reach the resident inside
 * GET /api/v1/service-requests/%, in `offers`, with all fifteen keys, and that
 * does not change. What had no answer was OPENING ONE OFFER FROM A NOTIFICATION
 * OR A DEEP LINK: the client knows the nid of the offer and not the nid of the
 * request, and to paint a screen about ONE offer it had to download the whole
 * request — the rival offers, their amounts and their messages — in order to
 * keep one of them.
 *
 * SIX NUMBERED STEPS, AND THE ORDER IS THE CONTRACT:
 *
 *   1. The nid.        404 not_found when it is not a positive integer, before
 *                      the token and before any query.
 *   2. Authentication. 401 missing_authorization / invalid_token.
 *   3. The offer.      FALSE — no such offer, unpublished, another bundle, or
 *                      its request not published — is 404.
 *   4. The request.    Its context, and the row the access rule reads.
 *   5. Who is asking.  myapi_service_request_viewer(), UNMODIFIED. Only
 *                      'requester' passes; NULL and 'provider' are both 403.
 *   6. 200.
 *
 * NO ROLE IS DEMANDED, AND THAT IS DECISION 13. There is no 'residente' role in
 * this module, and what grants access is not a label on the account but a FACT
 * ABOUT THE DATA: being the requester of the request this offer hangs on.
 * Inventing a role would put a second, weaker condition beside the real one.
 *
 * THE ACCESS RULE IS myapi_service_request_viewer(), UNMODIFIED (decision 10),
 * AND ONLY 'requester' PASSES. Rule 1 of that function — field_requester and
 * NOT node.uid — is the module's ONE definition of "who is the resident", and
 * rewriting the comparison here would be a second one: the symptom of them
 * drifting would be an account that can read the offer and not the request it
 * hangs on, with nothing failing loudly.
 *
 * A PROVIDER WHO BID GETS 403 HERE, and so does the AWARDED one: that function
 * answers 'provider' for both, and 'provider' is not 'requester'. For them
 * there is the other route, which is where the role gate and the ownership
 * gate live. A building administrator gets 403 too — decision 14: the request's
 * own detail does not let them in either, and making the offer the first
 * exception would be an exception that starts at the leaf.
 *
 * `requester` IS ALWAYS NULL HERE, AND THAT IS DELIBERATE (decision 8). The
 * reader IS the requester, so the key would tell them their own name; it still
 * travels, as one of the seven, because a client that reads the same seven keys
 * in both routes has nothing to branch on. docs/service-offer.md says so in
 * as many words, because a client that does not know it will open a bug.
 *
 * `unit` TRAVELS ALWAYS: it is their home. No `mine` rule applies on this side
 * — that rule exists to keep a resident's address away from a provider who is
 * not going to that house, and the resident lives there.
 *
 * FOUR QUERIES, FIXED — token, offer, request, and the account load the token
 * layer already did. NO query about providers, no role lookup, and no name
 * resolution: `requester` is null by construction, so there is nobody to name.
 *
 * @param mixed $nid  The OFFER's nid, from the route.
 */
function myapi_service_offer_requester_detail($nid) {
  // 1. The nid, before anything else — including the token. Byte for byte the
  // guard the provider's route opens with: the answer is about the SHAPE of the
  // URL and not about what exists.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The offer. The SAME servable set as the provider's route — three
  // conditions, four indistinguishable ways to miss them — because "which
  // offers exist" cannot depend on who is asking.
  $row = myapi_service_offer_detail_row($nid);
  if (!$row) {
    myapi_error('not_found', 404);
  }

  // 4. The request: both the context of the response and the row the access
  // rule below reads. The INNER JOIN of step 3 already guaranteed it is
  // published, so a FALSE here is corrupt data and answers the same 404.
  $request_row = myapi_service_request_detail_row($row->request_id);
  if (!$request_row) {
    myapi_error('not_found', 404);
  }

  // 5. WHO IS ASKING, by the one function that decides it for this whole
  // domain, used unmodified. EXACTLY 'requester' passes: NULL is a 403 and so
  // is 'provider' — for them there is the other route.
  if (myapi_service_request_viewer($request_row, $uid) !== 'requester') {
    myapi_error('forbidden', 403);
  }

  // 6. Respond 200 (read-only endpoint, no message_key). The unit always — it
  // is their home — and the requester never: it is them. One object under
  // 'service_offer', never a list.
  myapi_respond([
    'service_offer' => myapi_service_offer_build_detail($row, $request_row, FALSE, TRUE),
  ], 200);
}

/* ---------------------------------------------------------------------------
 * PUT /api/v1/service-offers/%/withdraw — the provider takes a quote back
 * (SPEC 105).
 *
 * THE STATE 'withdrawn' HAS BEEN IN myapi_services_offer_statuses() SINCE
 * SPEC 77 AND UNTIL TODAY NOBODY WROTE IT. That is the whole of this endpoint:
 * no new field, no new table, no hook_update_N, and no change to the graph of
 * request transitions.
 *
 * A PUT AND NOT A DELETE, because nothing disappears — see the route's comment
 * in myapi.module and decision 5.
 *
 * NOTHING ELSE MOVES. The request keeps its status even when this was its last
 * live offer (decision 6), no service_transaction is written (decision 7), and
 * offers_count still counts this offer, because "how many offers did I receive"
 * includes the ones that were taken back (SPEC 88).
 * ------------------------------------------------------------------------ */

/**
 * Routes /api/v1/service-offers/%/withdraw by HTTP method (SPEC 105).
 *
 * PUT AND NOTHING ELSE, and the 405 before the token and before any query —
 * the criterion of every dispatcher in this module. GET is not a read of a
 * withdrawal: there is nothing there to read, the offer is read by its own two
 * detail routes.
 */
function myapi_service_offer_withdraw_dispatch($nid = NULL) {
  if (myapi_request_method() === 'PUT') {
    myapi_service_offer_withdraw($nid);
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * PUT /api/v1/service-offers/%/withdraw — withdraw my own offer (SPEC 105).
 *
 * WHAT IT UNBLOCKS. Since SPEC 101 a provider who quoted a 'direct' with one
 * zero too many had no way back, and the resident's only exit was to cancel the
 * job. Withdrawing alone already fixes that case, because condition 7 of
 * SPEC 100 — myapi_service_offer_live_offer_exists() — never counted a
 * 'withdrawn' offer: take the wrong quote back and the same provider may POST a
 * new one on the same request.
 *
 * SEVEN NUMBERED STEPS, AND THE ORDER IS THE CONTRACT:
 *
 *   1. The nid.        404 not_found when it is not a positive integer, before
 *                      the token and before any query.
 *   2. Authentication. 401 missing_authorization / invalid_token.
 *   3. The role.       403 provider_role_required, BEFORE the offer is read: an
 *                      account with no standing here learns nothing about which
 *                      offer nids exist.
 *   4. The two rows.   The offer and its request, the same two the edit reads.
 *   5. The gate.       myapi_service_offer_write_gate(), conditions 4 to 7.
 *   6. The write.      field_offer_status = 'withdrawn', and NOTHING ELSE.
 *   7. 200.
 *
 * THE LICENCE IS NOT CHECKED, AND THAT IS DECISION 9. The edit calls
 * myapi_service_offer_update_gate() and this calls the shared one directly.
 * Withdrawing commits to nothing, and forcing a provider to leave a wrong offer
 * alive because their licence expired is exactly the damage this spec came to
 * repair. A suspended provider withdraws with 200.
 *
 * THE BODY IS NOT READ AT ALL. There is no reason field (decision 8) and no key
 * this endpoint would know what to do with, so a body with keys and a body that
 * is malformed JSON are the same thing: ignored. Nothing is parsed, so nothing
 * can fail.
 *
 * NOT IDEMPOTENT, ON PURPOSE — the literal precedent of SPEC 95's cancellation.
 * A second PUT on an offer that is already 'withdrawn' answers 409
 * service_offer_not_withdrawable, which is the truth; a 200 would pretend it
 * had done something.
 *
 * THREE QUERIES AND ONE SAVE, fixed: the offer, its request, the account the
 * token layer loads, and the node_load()/node_save() of the offer itself. The
 * answer costs no query of its own — the row the gate already read is reused,
 * with its status swapped, which is SPEC 100's criterion: a SELECT here would
 * cost a round trip to learn what this function already knows.
 *
 * @param mixed $nid  The OFFER's nid, from the route.
 */
function myapi_service_offer_withdraw($nid) {
  // 1. The nid, before anything else — including the token. Byte for byte the
  // guard both detail routes open with.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The role gate, shared with SPECS 98, 99, 100 and 102 — same code, same
  // function, decided in one place. BEFORE the offer is read: who you are does
  // not depend on which nid you asked for.
  $account = user_load($uid);
  if (!myapi_provider_role_is($account)) {
    myapi_error('provider_role_required', 403);
  }

  // 4. The two rows the gate decides over. The request is only asked for when
  // there is an offer to hang it on; without one the gate answers 404 and the
  // second query would have been a query about nothing.
  $row = myapi_service_offer_detail_row($nid);
  $request_row = $row ? myapi_service_request_detail_row($row->request_id) : FALSE;

  // 5. The gate — four conditions, in order, first failure answers. The mapping
  // from code to HTTP status is the ONE thing this file adds to it: WHO may
  // touch the offer is a 403, WHETHER it may still be touched is a 409, and
  // "there is no such offer" is the 404 the four indistinguishable ways in
  // share.
  $error = myapi_service_offer_write_gate(
    $row,
    myapi_provider_role_provider_ids($account),
    $request_row,
    'service_offer_not_withdrawable'
  );
  if ($error !== NULL) {
    $statuses = [
      'not_found'                        => 404,
      'service_offer_provider_not_owned' => 403,
      'service_offer_not_withdrawable'   => 409,
      'service_request_not_offerable'    => 409,
    ];
    myapi_error($error, isset($statuses[$error]) ? $statuses[$error] : 409);
  }

  // 6. The write, and it is ONE field. node_load() and not an UPDATE on the
  // field table: a write goes through the entity so hook_node_presave() and
  // hook_node_update() see it, the same argument step 3 of SPEC 95 makes.
  $offer = node_load($nid);

  // The row above proves the offer exists and is of this bundle, so this can
  // only fail on a race. Nothing has been written yet, so the honest answer is
  // a 500 and not a 200 over a save that did not happen.
  if (!$offer || $offer->type !== MYAPI_SERVICES_OFFER_TYPE) {
    watchdog(
      'myapi',
      'SPEC 105: la oferta @offer pasó la compuerta del retiro pero no se pudo cargar como nodo @type.',
      ['@offer' => $nid, '@type' => MYAPI_SERVICES_OFFER_TYPE],
      WATCHDOG_ERROR
    );
    myapi_error('server_error', 500);
  }

  // The literal, exactly as SPEC 95's rejection sweep writes 'rejected': the
  // value is an allowed_value of field_offer_status and a key of
  // myapi_services_offer_statuses() since SPEC 77, and it has never had a
  // constant of its own.
  $offer->field_offer_status[LANGUAGE_NONE][0]['value'] = 'withdrawn';
  node_save($offer);

  // 7. The answer. THE OBJECT UNDER 'service_offer' IS BYTE FOR BYTE what the
  // offer's own detail answers for this nid a second later, because it comes
  // out of the SAME serialiser over the SAME row. The only column touched is
  // the one that was just written.
  //
  // The provider's logo DOES travel here, unlike in the 201 of SPEC 100, and
  // that is not an inconsistency: there it would have cost a query, and here it
  // came free in the row the gate needed anyway (decision 17).
  $row->status = 'withdrawn';

  // `request` is a SIBLING of 'service_offer' and not a sixteenth key of it,
  // and it carries the request's status WITHOUT MOVING IT — decision 6. It
  // travels because the provider's screen is the same one the 201 of SPEC 100
  // fills, and a client that reads the same shape everywhere has nothing to
  // branch on.
  myapi_respond([
    'service_offer' => myapi_service_offer_build($row),
    'request'       => [
      'id'     => (int) $row->request_id,
      'status' => isset($request_row->status) ? $request_row->status : NULL,
    ],
  ], 200, 'service_offer_withdrawn');
}
