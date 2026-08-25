<?php

/**
 * @file
 * Service offer resource for the myapi module (SPEC 100).
 *
 * One route, authenticated:
 *
 *   - POST /api/v1/service-requests/%/offers — a provider bids on an open
 *     request of their category, with the eleven fields that turn "a text and a
 *     number" into a quote the resident can compare. SINCE SPEC 101 THE SAME
 *     ROUTE ALSO CARRIES THE QUOTE OF AN AWARDED 'direct', where there is
 *     nothing to bid for and everything to price.
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
 * EVERY METHOD BUT POST ANSWERS 405, BEFORE THE TOKEN AND BEFORE ANY QUERY.
 * There is no GET of the collection on purpose: the offers already travel
 * inside the two details, in `offers` for the resident and in `my_offers` for
 * the provider, and a third place to read them would be a third thing to keep
 * in agreement with offers_count.
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
