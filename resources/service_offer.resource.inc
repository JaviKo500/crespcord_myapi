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
 *   8. The body.               Eleven rules, all 422.
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
