<?php

/**
 * @file
 * Chat membership for the myapi module (SPEC 115).
 *
 * THE DOMAIN HALF of the chat credential: WHO takes part in WHICH thread. The
 * signing half is includes/myapi.firebase.inc, which knows nothing about offers
 * — and this file knows nothing about JWTs. The split is the one SPEC 115 asks
 * for, and it is what makes the day the transport changes cost one file.
 *
 * THE RULE OF MEMBERSHIP, WHOLE, IN ONE LINE:
 *
 *   A thread exists when the request has a field_assigned_provider AND there is
 *   a LIVE offer ('sent' or 'selected') FROM THAT PROVIDER on that request. The
 *   thread IS that offer.
 *
 * ONE RULE, NOT TWO BRANCHES, and that is decision 6 of the spec. It covers the
 * two ways a thread is born today without naming either:
 *
 *   - AWARDED (SPEC 106): field_assigned_provider is written when the offer is
 *     accepted, and the winner says 'selected'.
 *   - DIRECT (SPEC 101): field_assigned_provider is written AT BIRTH by
 *     myapi_service_request_build_node(), and the provider's quote stays 'sent'
 *     forever, because there is nothing to award.
 *
 * Gating on 'selected' alone would have left EVERY direct job with no chat, with
 * no error and no red test. That is the silent failure this rule exists to
 * avoid, and the reason the condition names the ASSIGNED PROVIDER and not the
 * status of the request.
 *
 * TWO CONSEQUENCES THAT ARE NOT CONDITIONS, and this is the part worth reading
 * twice, because nothing below mentions field_request_status:
 *
 *   - A CANCELLED REQUEST HAS NO THREAD, and no line here says so.
 *     myapi_service_request_cancel() calls myapi_service_offer_reject_live()
 *     WITHOUT the second argument (SPEC 95), so a cancelled request has no live
 *     offer left — it excludes itself. field_assigned_provider is kept on
 *     purpose ("a cancellation with no trace of who it had been awarded to is a
 *     cancellation nobody can audit") and still nothing matches.
 *   - A CLOSED REQUEST KEEPS ITS THREAD, and no line here says that either.
 *     myapi_service_request_close() (SPEC 108) writes the status, the closing
 *     date and the rating, and touches NO offer: the winner still says
 *     'selected'. That is wanted — field_offer_warranty_days exists, and a
 *     warranty you cannot write to the provider about is a useless warranty.
 *     To reverse it, one condition on field_request_status in both queries
 *     below, and the price would be the conversation VANISHING at closing time,
 *     because the RTDB rules cannot tell reading from writing without doubling
 *     the claim — and doubling it eats the 1000-byte budget.
 *
 * LIVE IS THE TWO CONSTANTS OF includes/myapi.services_common.inc, the very same
 * ones myapi_service_offer_reject_live() sweeps on. The criterion is SHARED, not
 * copied: the day one of those two values changes, the sweep and this query move
 * together instead of one of them being forgotten.
 *
 * MEMBERSHIP IS BY COMPANY, NOT BY ACCOUNT. field_provider_users is
 * multi-valued, so two employees of the same provider see the same thread. It is
 * what the notifications of SPEC 109-112 already do and what
 * myapi_service_offer_query() already does; the chat deciding otherwise would be
 * incoherent.
 *
 * NOT A SINGLE ROW IS WRITTEN, HERE OR ANYWHERE IN SPEC 115. The three chat
 * fields of SPEC 77 — field_firebase_path, field_chat_opened_at,
 * field_last_message_at — are STILL EMPTY when this feature ships: the path of a
 * thread is a CONVENTION over the offer's nid (decision 8), not a stored datum,
 * so there is nothing to keep in sync and nothing an operator can break by hand.
 */

// The prefix of every thread path. THE ONE DEFINITION OF THE CONVENTION, and
// the place to change the day a thread stops being an offer.
//
// IT IS ALSO HALF OF A SECURITY RULE. docs/chat.md matches with
// contains('service_offers/' + $offer) and NOT with the bare nid, because
// '901' matches inside 'service_offers/9013'. Whoever edits this string edits
// the RTDB rules in the same commit.
if (!defined('MYAPI_CHAT_THREAD_PREFIX')) {
  define('MYAPI_CHAT_THREAD_PREFIX', 'service_offers/');
}

// How many threads a token may carry. Firebase caps ALL custom claims at 1000
// bytes together; a thread costs ~22, so about 40 fit. The number is the
// starting point, NOT the guarantee: myapi_chat_threads_claim() measures the
// encoded result and keeps cutting until it fits, because trusting the
// arithmetic is how a provider with long nids gets a token Firebase rejects.
if (!defined('MYAPI_CHAT_MAX_THREADS')) {
  define('MYAPI_CHAT_MAX_THREADS', 40);
}

// The hard limit Firebase enforces on the custom claims of a token, in bytes.
// A product limit, not one of this spec's making.
if (!defined('MYAPI_CHAT_CLAIM_MAX_BYTES')) {
  define('MYAPI_CHAT_CLAIM_MAX_BYTES', 1000);
}

// The app section the chat push deep-links to (SPEC 116). Same role as
// MYAPI_NOTIFICATION_DEEP_LINK_* — a section of the app and not a route of this
// API — and it lives here rather than there because the chat is the one push of
// the module that does not go through myapi_notification_create().
if (!defined('MYAPI_CHAT_DEEP_LINK_TARGET')) {
  define('MYAPI_CHAT_DEEP_LINK_TARGET', 'chat');
}

// The 'notification_type' the chat push carries (SPEC 116). It is the key the
// app switches on, and it is deliberately NOT one of the
// MYAPI_NOTIFICATION_TYPE_* values: nothing of this type is ever written to
// myapi_notifications, and a type shared with the inbox would suggest a row the
// app could go and mark as read (decision 3).
if (!defined('MYAPI_CHAT_NOTIFICATION_TYPE')) {
  define('MYAPI_CHAT_NOTIFICATION_TYPE', 'chat_message');
}

// How long a chat banner is worth delivering, in seconds (SPEC 116). One hour.
// A push with no TTL is queued by the platform until the device comes back, so a
// phone switched off all afternoon lights up with the whole afternoon's banners
// at once — for a conversation the recipient has long since read in the app.
if (!defined('MYAPI_CHAT_PUSH_TTL')) {
  define('MYAPI_CHAT_PUSH_TTL', 3600);
}

// How much of the message the banner is allowed to show (SPEC 116, revision).
// 140 characters, and the number is small on purpose: THE PREVIEW IS A HOOK,
// NOT THE MESSAGE. A banner is not a reader, it is shown on a LOCKED SCREEN,
// and the RTDB caps a message at 2000 — pouring those onto a lock screen is
// not a feature, it is a leak with extra steps.
if (!defined('MYAPI_CHAT_PREVIEW_MAX_LENGTH')) {
  define('MYAPI_CHAT_PREVIEW_MAX_LENGTH', 140);
}

/**
 * The path of the chat thread of an offer.
 *
 * PURE, and the single definition of the convention (SPEC 115, decision 8): the
 * thread of offer 901 is 'service_offers/901', derived and never stored. The app
 * already holds the offer's nid — SPEC 103 and SPEC 106 both hand it over — so
 * storing this in field_firebase_path would be storing a function of the nid in
 * a hand-editable column that, as myapi.install itself puts it, "breaks the chat
 * without raising any error".
 *
 * @param mixed $offer_nid  The offer's nid.
 *
 * @return string  The thread path, prefix included.
 */
function myapi_chat_thread_id($offer_nid) {
  return MYAPI_CHAT_THREAD_PREFIX . (int) $offer_nid;
}

/**
 * The SelectQuery every membership question starts from.
 *
 * THE RULE OF MEMBERSHIP, BUILT ONCE. Six joins that say a live offer, a
 * request with an assigned provider, and THE OFFER'S PROVIDER BEING THAT
 * ASSIGNED PROVIDER. The three callers — the two sides of
 * myapi_chat_offer_nids_for_uid() and myapi_chat_thread_row() — differ only in
 * what they hang off this, and that is the whole reason the builder exists:
 * with three hand-written copies, the day one of the two live-status constants
 * changes is the day the chat and its push stop agreeing on what a thread is,
 * silently and with no red test. Rule 3 of CLAUDE.md, applied to a criterion
 * rather than to a helper.
 *
 * A REFACTOR AND NOT A CHANGE OF CRITERION: every condition below was already
 * written, twice, in the function under it, and ChatTokenTest — the whole
 * membership table of SPEC 115 — is the detector that says so.
 *
 * The reasons behind each condition are in this file's header and are not
 * repeated: 'no.type' because field_request is shared with
 * 'service_transaction', and the fap join condition because SPEC 106 sweeps the
 * losers with no transaction, so a stray 'sent' offer of another provider can
 * hang off an awarded request for a moment and must not open a thread.
 *
 * @return SelectQuery
 *   Aliases: 'fq' the offer's field_request, 'no' the offer node, 'fos' its
 *   status, 'fp' its provider, 'rn' the request node, 'fap' the assignment.
 *   NOT A SINGLE FIELD IS SELECTED and no ordering is applied — what to read
 *   and how to sort it is each caller's business.
 */
function myapi_chat_thread_base_query() {
  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);

  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);

  $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->condition('fos.field_offer_status_value', [
    MYAPI_SERVICES_OFFER_STATUS_SENT,
    MYAPI_SERVICES_OFFER_STATUS_SELECTED,
  ], 'IN');

  $query->innerJoin('field_data_field_provider', 'fp', "fp.entity_id = no.nid AND fp.entity_type = 'node' AND fp.deleted = 0");

  $query->innerJoin('node', 'rn', 'rn.nid = fq.field_request_target_id');
  $query->condition('rn.type', MYAPI_SERVICES_REQUEST_TYPE);
  $query->condition('rn.status', 1);

  // The rule of membership, in one join: the request is awarded, and it is
  // awarded to the provider this very offer belongs to.
  $query->innerJoin('field_data_field_assigned_provider', 'fap', "fap.entity_id = rn.nid AND fap.entity_type = 'node' AND fap.deleted = 0 AND fap.field_assigned_provider_target_id = fp.field_provider_target_id");

  return $query;
}

/**
 * The nids of every offer that is a chat thread of this account.
 *
 * TWO SEPARATE db_select()s, JOINED IN PHP, and that is deliberate. The two
 * sides share no table beyond 'node', and an OR over LEFT JOINs in Drupal 7 is
 * exactly where the indexes are lost. Two indexed queries beat one that scans.
 *
 *   RESIDENT SIDE  field_requester_target_id = the uid.
 *   PROVIDER SIDE  field_assigned_provider_target_id IN the account's providers,
 *                  through myapi_provider_role_provider_ids() — the module's one
 *                  definition of "which providers are this account's".
 *
 * BOTH SIDES DEMAND THE SAME THREE THINGS, and the middle one is the rule of
 * membership: a live offer, a request with an assigned provider, and THE OFFER'S
 * PROVIDER BEING THAT ASSIGNED PROVIDER. The last one is a join condition rather
 * than decoration: SPEC 106 sweeps the losers WITHOUT a transaction, so a 'sent'
 * offer from somebody else can survive for a moment hanging off an awarded
 * request, and it must not open a thread.
 *
 * `no.type = MYAPI_SERVICES_OFFER_TYPE` IS NOT DECORATION EITHER. field_request
 * is SHARED with 'service_transaction' (SPEC 77), so without the bundle
 * condition a request's own timeline entries would be read as offers.
 *
 * ORDERED BY THE REQUEST'S node.changed, DESCENDING, AND THAT IS WHAT DECIDES
 * WHICH 40 SURVIVE THE CUT: if threads have to be lost, the quietest ones are
 * lost. The ordering is applied again in PHP over the union, because two
 * separately ordered lists concatenated are not a sorted list — and the cut is
 * taken from the union, not from either half.
 *
 * The account handed to myapi_provider_role_provider_ids() is
 * (object) ['uid' => n], the same lightweight object
 * myapi_service_offer_provider_row() and myapi_service_request_detail() already
 * build: that function never reads ->roles.
 *
 * @param mixed $uid  The Drupal uid.
 *
 * @return array
 *   Offer nids (int), newest request activity first, deduplicated. Empty when
 *   the account takes part in nothing — which is not an error.
 */
function myapi_chat_offer_nids_for_uid($uid) {
  $uid = (int) $uid;
  if ($uid <= 0) {
    return [];
  }

  // nid => the request's 'changed', so the union can be ordered as a whole.
  $threads = [];

  // ---------------------------------------------------------------- resident.
  $query = myapi_chat_thread_base_query();

  $query->innerJoin('field_data_field_requester', 'fr', "fr.entity_id = rn.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->condition('fr.field_requester_target_id', $uid);

  $query->addField('no', 'nid', 'nid');
  $query->addField('rn', 'changed', 'changed');
  $query->distinct();
  $query->orderBy('rn.changed', 'DESC');

  foreach ($query->execute() as $row) {
    $nid = (int) $row->nid;
    $changed = (int) $row->changed;
    if (!isset($threads[$nid]) || $changed > $threads[$nid]) {
      $threads[$nid] = $changed;
    }
  }

  // ---------------------------------------------------------------- provider.
  // Asked only when the account belongs to at least one provider: an empty IN
  // list is a query that can match nothing, so not building it is one round
  // trip saved for every resident in the building.
  $provider_ids = array_map('intval', myapi_provider_role_provider_ids((object) ['uid' => $uid]));

  if ($provider_ids) {
    $query = myapi_chat_thread_base_query();
    $query->condition('fap.field_assigned_provider_target_id', $provider_ids, 'IN');

    $query->addField('no', 'nid', 'nid');
    $query->addField('rn', 'changed', 'changed');
    $query->distinct();
    $query->orderBy('rn.changed', 'DESC');

    foreach ($query->execute() as $row) {
      $nid = (int) $row->nid;
      $changed = (int) $row->changed;
      if (!isset($threads[$nid]) || $changed > $threads[$nid]) {
        $threads[$nid] = $changed;
      }
    }
  }

  // The union, ordered as one list. The tie-break on the nid is not cosmetic:
  // two requests saved in the same second must not swap places between two
  // calls, or the 40 that survive the cut would not be the same 40.
  uksort($threads, function ($a, $b) use ($threads) {
    if ($threads[$a] !== $threads[$b]) {
      return $threads[$b] < $threads[$a] ? -1 : 1;
    }

    return $b < $a ? -1 : 1;
  });

  return array_keys($threads);
}

/**
 * The 'threads' custom claim: the thread paths, comma-separated and within
 * Firebase's byte budget.
 *
 * PURE.
 *
 * A COMMA-SEPARATED STRING AND NOT AN ARRAY (SPEC 115, decision 5). The RTDB
 * rule engine has no membership operator over lists:
 * auth.token.threads.contains(...) works on a string and not on an array. It is
 * a limitation of the engine, and it is what decides the shape of the claim —
 * which is why it is written down here instead of being discovered while
 * debugging rules.
 *
 * THE 1000 BYTES ARE MEASURED, NOT ESTIMATED. The cap is on the ENCODED claims,
 * so what is weighed is drupal_json_encode(['threads' => …]) and not the raw
 * string: the key, the braces and the quotes are part of the budget too. The cut
 * to MYAPI_CHAT_MAX_THREADS comes first because it is the common case and costs
 * nothing; the loop after it is what makes the guarantee true regardless of how
 * long the nids grow.
 *
 * THE MEASURE IS DELIBERATELY CONSERVATIVE. PHP escapes the '/' of every thread
 * path as '\/', so each one is weighed one byte heavier than Firebase will
 * actually count it. Erring that way is the cheap mistake: over-counting drops
 * one more thread than strictly necessary in the very worst case, while
 * under-counting hands Firebase a claim it REJECTS — and a rejected claim is
 * not a degraded chat, it is no chat at all.
 *
 * THE ORDER IS PRESERVED AND THE TAIL IS WHAT GOES. The caller hands the threads
 * in "most recent activity first" order, so what is dropped is always the
 * quietest — the degradation SPEC 115 accepts and documents, and the signal that
 * it is time for the members-node of decision 4.
 *
 * @param array $thread_ids  Thread paths, most recently active first.
 *
 * @return string
 *   The claim's value. Empty string when the list is empty — which is a valid
 *   claim for an account with no conversations yet, not an error.
 */
function myapi_chat_threads_claim(array $thread_ids) {
  $thread_ids = array_values($thread_ids);

  if (count($thread_ids) > MYAPI_CHAT_MAX_THREADS) {
    $thread_ids = array_slice($thread_ids, 0, MYAPI_CHAT_MAX_THREADS);
  }

  while ($thread_ids) {
    $claim = implode(',', $thread_ids);

    if (strlen(drupal_json_encode(['threads' => $claim])) <= MYAPI_CHAT_CLAIM_MAX_BYTES) {
      return $claim;
    }

    array_pop($thread_ids);
  }

  return '';
}

/* ---------------------------------------------------------------------------
 * SPEC 116 — the other direction of the rule of membership, and the text of
 * the banner.
 *
 * Everything above answers "which threads are this account's?", which is what
 * a token needs. A push needs the opposite question — "who are the two sides of
 * THIS thread?" — and it is the same rule read backwards, which is why it lives
 * in this file and on top of myapi_chat_thread_base_query() rather than in a
 * second place that would drift.
 *
 * THE TEXT PASSES THROUGH, AND IT DOES NOT STAY (decision 2, as revised). The
 * banner carries a PREVIEW of the message, so this file does now handle the
 * text — but it handles it the way a pipe handles water: myapi_chat_message_preview()
 * sanitises it, myapi_chat_message_push_body() puts it on a line, and it leaves
 * for OneSignal. IT IS NEVER WRITTEN ANYWHERE: not a table, not a watchdog
 * entry, not a log line of this module. The three chat fields of SPEC 77 stay
 * empty, the conversation still lives in exactly one place, and the promise of
 * SPEC 115 becomes "the content does not STAY here" rather than "the content
 * does not pass here".
 *
 * AND THE SERVER CANNOT VOUCH FOR IT. Drupal does not read the RTDB, so what it
 * forwards is what the SENDER SAYS they wrote. A modified client can send one
 * thing and write another. It is accepted (decision 2a) for one reason: the
 * worst it achieves is showing a false line to somebody the sender could
 * already chat with — and writing the lie into the actual thread would be both
 * easier and more convincing. The preview is a COURTESY OF THE SENDER ABOUT
 * THEIR OWN MESSAGE, never an assertion of this server, and docs/chat.md says
 * so in those words.
 *
 * TWO DEPENDENCIES WORTH NAMING, both of them deliberate reuse rather than a
 * new query:
 *
 *  - includes/myapi.notification.inc, for MYAPI_NOTIFICATION_AUDIENCE_RESIDENT
 *    and _PROVIDER. The 'audience' key of this push means the same thing it
 *    means in every other push of the module, so it is spelled with the same
 *    two constants. A private vocabulary here would be a second word for one
 *    idea.
 *  - includes/myapi.service_request_notification.inc, for
 *    myapi_service_request_provider_uids(), myapi_service_request_node_title()
 *    and myapi_service_request_notification_label(). "Every active account of
 *    field_provider_users, role or no role" is SPEC 109's criterion and asking
 *    it a second way is how the chat and the notifications would start
 *    disagreeing about who works for a provider.
 *  - includes/myapi.text.inc, for myapi_text_to_plain() (SPEC 79), and
 *    includes/myapi.onesignal.inc, for myapi_onesignal_truncate_body(). Between
 *    them they ARE the preview's sanitising: strip the markup, decode the
 *    entities, collapse every newline into a space, cut on a character
 *    boundary. Not one line of that is rewritten here — both already exist and
 *    both already have their own tests.
 * ------------------------------------------------------------------------ */

/**
 * The two sides of ONE thread, plus the context its push has to carry.
 *
 * THE SAME RULE AS THE TOKEN, ASKED ABOUT ONE OFFER: myapi_chat_thread_base_query()
 * narrowed to a single nid. That is the whole reason the builder was extracted —
 * this function cannot answer "yes" to a thread the token would not have
 * carried, because it is not allowed to state the rule itself.
 *
 * NULL IS ONE ANSWER FOR THREE SITUATIONS, AND THAT IS DELIBERATE (decision 6):
 * the offer does not exist, the offer is not live, or its request is not awarded
 * to its provider. The caller turns all three into the same 404 with the same
 * body, because telling them apart would turn the route into an enumerator of
 * live offers — ask for 1..N and you learn which ones are awarded and active.
 *
 * THE TWO CONTEXT JOINS ARE LEFT AND NOT INNER, and the reason is the one SPEC
 * 91 already wrote down: field_unit is required on the bundle since SPEC 86 but
 * was added WITHOUT a backfill, so a request created before it simply has no
 * row. An INNER there would make those threads vanish — no error, no log, just
 * a 404 on a conversation that works fine in the app.
 *
 * ONE HOP AND NOT TWO. Unlike myapi_service_request_detail(), what is read is
 * the *_target_id of the reference and never the referenced node: the push
 * carries nids so the app can switch context, and it needs no names.
 *
 * @param mixed $offer_nid  The offer's nid.
 *
 * @return array|null
 *   'offer_nid', 'request_nid', 'request_title', 'requester_uid',
 *   'provider_id', 'unit_id' and 'condominium_id'. The last two are NULL when
 *   the request has no such row; 'request_title' is NULL when it is empty.
 *   NULL when this offer is not a thread — see above.
 */
function myapi_chat_thread_row($offer_nid) {
  $offer_nid = (int) $offer_nid;
  if ($offer_nid <= 0) {
    return NULL;
  }

  $query = myapi_chat_thread_base_query();
  $query->condition('no.nid', $offer_nid);

  $query->innerJoin('field_data_field_requester', 'fr', "fr.entity_id = rn.nid AND fr.entity_type = 'node' AND fr.deleted = 0");

  // LEFT, both of them. See the docblock.
  $query->leftJoin('field_data_field_unit', 'fu', "fu.entity_id = rn.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->leftJoin('field_data_field_condominium', 'fco', "fco.entity_id = rn.nid AND fco.entity_type = 'node' AND fco.deleted = 0");

  $query->addField('no', 'nid', 'offer_nid');
  $query->addField('rn', 'nid', 'request_nid');
  $query->addField('rn', 'title', 'request_title');
  $query->addField('fr', 'field_requester_target_id', 'requester_uid');
  $query->addField('fp', 'field_provider_target_id', 'provider_id');
  $query->addField('fu', 'field_unit_target_id', 'unit_id');
  $query->addField('fco', 'field_condominium_target_id', 'condominium_id');
  $query->range(0, 1);

  $row = $query->execute()->fetchAssoc();
  if (!$row) {
    return NULL;
  }

  return [
    'offer_nid'      => (int) $row['offer_nid'],
    'request_nid'    => (int) $row['request_nid'],
    'request_title'  => ($row['request_title'] === NULL || $row['request_title'] === '') ? NULL : $row['request_title'],
    'requester_uid'  => (int) $row['requester_uid'],
    'provider_id'    => (int) $row['provider_id'],
    'unit_id'        => $row['unit_id'] !== NULL ? (int) $row['unit_id'] : NULL,
    'condominium_id' => $row['condominium_id'] !== NULL ? (int) $row['condominium_id'] : NULL,
  ];
}

/**
 * Which side of a thread an account is on.
 *
 * PURE: it decides nothing by asking the database, it reads the row and the
 * list the caller already resolved.
 *
 * THE ORDER OF THE TWO CHECKS IS THE ANSWER TO A REAL CASE. An account can be
 * BOTH the resident of a request and an employee of the provider it was awarded
 * to — a plumber who lives in the building he works for. The resident check
 * comes first, so that account counts as the RESIDENT: the thread hangs off
 * THEIR request, and the alternative would have them writing to themselves.
 *
 * @param array $thread         A myapi_chat_thread_row().
 * @param array $provider_uids  The accounts of the thread's provider, as
 *   myapi_service_request_provider_uids() answers them.
 * @param mixed $uid            The account to place.
 *
 * @return string|null
 *   MYAPI_NOTIFICATION_AUDIENCE_RESIDENT, MYAPI_NOTIFICATION_AUDIENCE_PROVIDER,
 *   or NULL when the account takes no part in this thread — which the caller
 *   turns into the very same 404 as a thread that does not exist.
 */
function myapi_chat_thread_side(array $thread, array $provider_uids, $uid) {
  $uid = (int) $uid;
  if ($uid <= 0) {
    return NULL;
  }

  if (isset($thread['requester_uid']) && (int) $thread['requester_uid'] === $uid) {
    return MYAPI_NOTIFICATION_AUDIENCE_RESIDENT;
  }

  if (in_array($uid, array_map('intval', $provider_uids), TRUE)) {
    return MYAPI_NOTIFICATION_AUDIENCE_PROVIDER;
  }

  return NULL;
}

/**
 * Who gets told about a message: THE OTHER SIDE, minus whoever wrote it.
 *
 * PURE.
 *
 * THE SENDER NEVER APPEARS, and neither does a COLLEAGUE of the sender. Both
 * fall out of "the other side" without a line written for either: a provider
 * account writing means the recipients are the resident, so the second employee
 * is not in the list to begin with. That is wanted — the message was sent by
 * their company, and they can already see the thread. The explicit filter on
 * the sender is what covers the account that is both sides at once.
 *
 * MEMBERSHIP IS BY COMPANY, SO SO IS THE NOTICE: when the resident writes, EVERY
 * active account of field_provider_users is told, which is the same audience
 * SPEC 109-112 already notify. A chat that reached only one of two employees
 * would be a chat whose messages depend on who happened to be listed first.
 *
 * @param array  $thread         A myapi_chat_thread_row().
 * @param array  $provider_uids  The accounts of the thread's provider.
 * @param string $side           The SENDER's side, from myapi_chat_thread_side().
 * @param mixed  $sender_uid     Who wrote.
 *
 * @return array
 *   uids as integers, deduplicated. EMPTY IS A NORMAL ANSWER — a provider with
 *   no active account has nobody to tell, and that is a 200 with notified: 0,
 *   not an error.
 */
function myapi_chat_notify_recipients(array $thread, array $provider_uids, $side, $sender_uid) {
  $sender_uid = (int) $sender_uid;

  if ($side === MYAPI_NOTIFICATION_AUDIENCE_RESIDENT) {
    $recipients = array_map('intval', $provider_uids);
  }
  elseif ($side === MYAPI_NOTIFICATION_AUDIENCE_PROVIDER) {
    $recipients = isset($thread['requester_uid']) ? [(int) $thread['requester_uid']] : [];
  }
  else {
    return [];
  }

  $recipients = array_filter($recipients, function ($uid) use ($sender_uid) {
    return $uid > 0 && $uid !== $sender_uid;
  });

  return array_values(array_unique($recipients));
}

/**
 * The name the recipient will read in the banner.
 *
 * THE COMPANY, NEVER THE EMPLOYEE (decision 9). When the provider writes, the
 * title is the provider node's own title — the trading name the resident chose
 * when they hired. "Nuevo mensaje de Pedro" would name somebody the resident has
 * no reason to recognise, and it would leak which employee is on the job.
 *
 * When the RESIDENT writes it is the other way round: there is no company, so
 * the SPEC 54 profile answers, and users.name is the fallback — the exact
 * resolution SPEC 110 already uses for "Hola {name}", so the same account is
 * called the same thing in a chat banner and in an offer email.
 *
 * NOT PURE, unlike the two builders below it: both branches read one row. It is
 * one query per call and it is the reason the builders take a STRING and not a
 * uid — the text of the banner stays assertable with nothing booted.
 *
 * @param array  $thread      A myapi_chat_thread_row().
 * @param string $side        The SENDER's side.
 * @param mixed  $sender_uid  Who wrote.
 *
 * @return string|null
 *   The name, or NULL when it cannot be resolved (a deleted provider node, an
 *   account with no profile and no name). The title builder degrades it to the
 *   same dash every other notice of the module uses.
 */
function myapi_chat_sender_label(array $thread, $side, $sender_uid) {
  if ($side === MYAPI_NOTIFICATION_AUDIENCE_PROVIDER) {
    return myapi_service_request_node_title(isset($thread['provider_id']) ? $thread['provider_id'] : NULL);
  }

  $sender_uid = (int) $sender_uid;
  if ($sender_uid <= 0) {
    return NULL;
  }

  $profile = myapi_user_fetch_profile_fields($sender_uid);
  $full_name = trim((string) $profile['first_name'] . ' ' . (string) $profile['last_name']);
  if ($full_name !== '') {
    return $full_name;
  }

  $account = user_load($sender_uid);

  return ($account && isset($account->name) && $account->name !== '') ? $account->name : NULL;
}

/**
 * Title of the chat push (SPEC 116).
 *
 * PURE, and fixed Spanish like every push builder of the module: the catalogue
 * translates API responses, and a banner is not one.
 *
 * @param string|null $sender_label  From myapi_chat_sender_label().
 *
 * @return string  'Nuevo mensaje de {who}'.
 */
function myapi_chat_message_push_title($sender_label) {
  return 'Nuevo mensaje de ' . myapi_service_request_notification_label($sender_label);
}

/**
 * The preview of the message, sanitised — or NULL.
 *
 * PURE, and THE ONE DOOR THE MESSAGE COMES IN THROUGH. Everything the text of a
 * chat is allowed to do to this server happens in these six lines, which is why
 * they are one function and not three lines inside the resource.
 *
 * THREE THINGS, IN THIS ORDER, AND NOT ONE OF THEM IS NEW CODE:
 *
 *   1. myapi_text_to_plain() (SPEC 79) — strips the markup, decodes the
 *      entities and COLLAPSES EVERY RUN OF WHITESPACE INTO ONE SPACE. That last
 *      one is not tidiness: a '\n' in the message would turn a three-line
 *      banner into four and make one message look like two. Non-strings answer
 *      '' there, so a number, an array, a boolean and NULL all fall out here
 *      with no branch written for them.
 *   2. An empty result is NULL — the banner shows two lines rather than a third
 *      blank one.
 *   3. myapi_onesignal_truncate_body(), with 140 instead of 200. The same cut,
 *      on a character boundary, with the same ellipsis.
 *
 * NOTHING IS ESCAPED, and that is correct: a push body is plain text and its
 * consumer is a native notification, so check_plain() here would show a literal
 * '&amp;' on somebody's lock screen. Step 1 already guarantees no markup
 * survives; there is no HTML context left to inject into.
 *
 * @param mixed $value  Whatever arrived in the body's 'preview' key. ANY type
 *   is accepted on purpose — this route answers no 422 (decision 2b), so
 *   rubbish is degraded here rather than refused there.
 *
 * @return string|null  The preview, or NULL when there is nothing to show.
 */
function myapi_chat_message_preview($value) {
  $text = myapi_text_to_plain($value);

  if ($text === '') {
    return NULL;
  }

  return myapi_onesignal_truncate_body($text, MYAPI_CHAT_PREVIEW_MAX_LENGTH);
}

/**
 * Body of the chat push (SPEC 116).
 *
 * PURE. Two lines, and a third ONLY when a preview came with the notice:
 *
 *   Solicitud: Fuga en el calentador
 *   ¿Te viene bien el jueves por la mañana?
 *
 * THE REQUEST'S LINE STAYS, AND THE PREVIEW DOES NOT REPLACE IT. It would have
 * been tempting to answer just the message, the way a messaging app does, but a
 * provider with five open jobs receives five different conversations: "¿Te
 * viene bien el jueves?" with no context does not say WHICH ONE. The title is
 * what makes the notice actionable, the preview is what makes it worth opening,
 * and both fit. It also matters in what order: Android and iOS show about two
 * lines collapsed, so WHO AND ABOUT WHAT IS WHAT IS ALWAYS VISIBLE and the text
 * is what you gain by expanding.
 *
 * THE SECOND PARAMETER IS OPTIONAL, AND THAT IS THE COMPATIBILITY GUARANTEE:
 * with no preview this returns byte for byte what it returned before the
 * revision, which is what a client that does not send one still gets.
 *
 * @param string|null $request_title  node.title of the request.
 * @param string|null $preview        ALREADY through myapi_chat_message_preview().
 *   This function sanitises nothing — it is pure text assembly, and the raw
 *   message must never reach it.
 *
 * @return string  Two lines, or three.
 */
function myapi_chat_message_push_body($request_title, $preview = NULL) {
  $body = 'Solicitud: ' . myapi_service_request_notification_label($request_title);

  if ($preview !== NULL && $preview !== '') {
    $body .= "\n" . $preview;
  }

  return $body;
}

/**
 * Whether this recipient may be told about this thread right now.
 *
 * THE DEBOUNCE, AND IT IS WHAT MAKES A CHAT LIVEABLE: at most one banner per
 * thread and per recipient every 60 seconds. Twenty messages in a row are one
 * notice, not twenty.
 *
 * THE FLOOD API AND NOT A TABLE OF ITS OWN (decision 5). The module already
 * wraps it, its defaults already live in code and its window is already
 * adjustable with variable_set() and no deploy. A myapi_chat_last_push table
 * would do the same thing plus a hook_update_N.
 *
 * myapi_flood_is_allowed() AND NOT myapi_flood_check(): a silenced recipient is
 * not a caller doing something wrong, it is a 'muted' in a 200. This is the
 * only flood use in the module that must not be able to answer 429.
 *
 * THE IDENTIFIER IS '{offer_nid}:{uid}' and it MUST be the same string
 * myapi_chat_notify_register() writes — the pair below is one mechanism split in
 * two calls, and the end-to-end assertions of ChatNotifyTest (first through,
 * second muted) are what would catch them disagreeing.
 *
 * @param mixed $offer_nid  The thread's offer nid.
 * @param mixed $uid        The recipient.
 *
 * @return bool  TRUE when a banner may go out.
 */
function myapi_chat_notify_allowed($offer_nid, $uid) {
  return myapi_flood_is_allowed(
    'myapi_chat_notify_thread',
    (int) $offer_nid . ':' . (int) $uid,
    'myapi_flood_chat_notify_thread_limit',
    'myapi_flood_chat_notify_thread_window'
  );
}

/**
 * Opens the debounce window for this recipient and this thread.
 *
 * CALLED ONLY WHEN A BANNER ACTUALLY WENT OUT, never before the attempt. If
 * OneSignal is unconfigured or the call failed, the window is left unburnt so
 * the NEXT message tries again — otherwise a minute of outage would cost a
 * minute of silence on a conversation that is still going.
 *
 * The identifier is the one myapi_chat_notify_allowed() reads. See its docblock.
 *
 * @param mixed $offer_nid  The thread's offer nid.
 * @param mixed $uid        The recipient.
 */
function myapi_chat_notify_register($offer_nid, $uid) {
  myapi_flood_register(
    'myapi_chat_notify_thread',
    (int) $offer_nid . ':' . (int) $uid,
    'myapi_flood_chat_notify_thread_window'
  );
}

/**
 * What the three mirror columns currently hold, for a batch of offers.
 *
 * THE READ HALF OF THE MIRROR (SPEC 117), and the only place in the module that
 * SELECTs a field_data_field_* table of the chat. It answers one question —
 * WHAT IS MISSING — and the three joins are LEFT because that question has no
 * meaning otherwise: a thread whose path has never been written has no row to
 * inner-join to, and is exactly the thread this is looking for.
 *
 * IT STARTS FROM node AND NOT FROM THE FIELD TABLES, and that is not a matter
 * of taste: the write needs the offer's vid, and NOT ONE PATH OF THE CHAT KNOWS
 * IT. myapi_chat_offer_nids_for_uid() and myapi_chat_thread_row() both answer
 * in bare nids, because until this spec nothing here had any business with
 * revisions. Hanging the three joins off node brings the vid out of the query
 * that had to be run anyway, instead of a second one per offer.
 *
 * A nid THAT IS NOT A NODE SIMPLY DOES NOT COME BACK, and the caller writes
 * nothing for it — no branch, no error. The mirror never invents a row.
 *
 * THE JOINS PIN language AND delta THE SAME WAY THE MERGE KEYS DO. The three
 * fields were created without 'translatable' and none is multi-valued, so
 * LANGUAGE_NONE / 0 is what the Field API would have stored and what
 * myapi_chat_field_write() does store; reading on a wider key than the one that
 * is written is how one offer would come back as two rows.
 *
 * NOTHING HERE IS AUTHORISATION. This function will read whatever nid it is
 * given: deciding WHICH threads may be mirrored is the caller's, and is done
 * before it — the token passes the threads its claim really carries, never the
 * whole membership query (decision 3).
 *
 * @param array $offer_nids  The offers to look at. Rubbish is dropped and
 *   repeats collapse, so callers hand over what they have.
 *
 * @return array
 *   [nid => ['nid', 'vid', 'path', 'opened_at', 'last_message_at']], EMPTY when
 *   there is nothing to look at. 'nid' and 'vid' are integers; the three values
 *   travel AS THE DATABASE ANSWERS THEM — a string or NULL — because what the
 *   decision needs to know is whether they are there, and casting NULL to an
 *   integer would answer that question wrong.
 */
function myapi_chat_field_state(array $offer_nids) {
  $nids = [];
  foreach ($offer_nids as $offer_nid) {
    $offer_nid = (int) $offer_nid;
    if ($offer_nid > 0) {
      $nids[$offer_nid] = $offer_nid;
    }
  }

  if (!$nids) {
    return [];
  }

  $query = db_select('node', 'n');
  $query->condition('n.nid', $nids, 'IN');

  // LEFT, the three of them. What is being asked is what is MISSING.
  $query->leftJoin('field_data_field_firebase_path', 'fpath', "fpath.entity_id = n.nid AND fpath.entity_type = 'node' AND fpath.deleted = 0 AND fpath.delta = 0 AND fpath.language = :myapi_chat_lang", [':myapi_chat_lang' => LANGUAGE_NONE]);
  $query->leftJoin('field_data_field_chat_opened_at', 'fopen', "fopen.entity_id = n.nid AND fopen.entity_type = 'node' AND fopen.deleted = 0 AND fopen.delta = 0 AND fopen.language = :myapi_chat_lang_open", [':myapi_chat_lang_open' => LANGUAGE_NONE]);
  $query->leftJoin('field_data_field_last_message_at', 'flast', "flast.entity_id = n.nid AND flast.entity_type = 'node' AND flast.deleted = 0 AND flast.delta = 0 AND flast.language = :myapi_chat_lang_last", [':myapi_chat_lang_last' => LANGUAGE_NONE]);

  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'vid', 'vid');
  $query->addField('fpath', 'field_firebase_path_value', 'path');
  $query->addField('fopen', 'field_chat_opened_at_value', 'opened_at');
  $query->addField('flast', 'field_last_message_at_value', 'last_message_at');

  $state = [];
  foreach ($query->execute() as $row) {
    $state[(int) $row->nid] = [
      'nid' => (int) $row->nid,
      'vid' => (int) $row->vid,
      'path' => $row->path,
      'opened_at' => $row->opened_at,
      'last_message_at' => $row->last_message_at,
    ];
  }

  return $state;
}

/**
 * What the mirror has to write on one offer — or nothing at all.
 *
 * PURE, AND THE ONLY FUNCTION OF THE MIRROR WITH A CRITERION INSIDE (SPEC 117).
 * The three around it read, write and orchestrate; this one decides, and it is
 * the only one the suite covers. That split is deliberate: the impure half must
 * have nothing to get wrong.
 *
 * THREE RULES AND NOT ONE MORE:
 *
 *   - field_firebase_path is written WHENEVER IT IS MISSING and never touched
 *     again. It is a function of the nid — the very same string
 *     myapi_chat_thread_id() derives on every signature — so rewriting it would
 *     cost a query to store a value that has not changed and cannot.
 *   - field_chat_opened_at is written ONCE IN THE LIFE OF THE THREAD, and only
 *     on a notice. The credential does NOT open a thread (decision 6): a
 *     resident who launches the app and never writes would otherwise mark
 *     twelve conversations as opened, and the column would end up meaning "a
 *     token was issued", which is what the path already says.
 *   - field_last_message_at is OVERWRITTEN ON EVERY NOTICE. It is the only one
 *     of the three that is allowed to move.
 *
 * $is_message === FALSE CAN NEVER WRITE A TIMESTAMP. That is one condition, not
 * three, and it is why both dates hang off the same branch.
 *
 * AN EMPTY RETURN IS THE COMMON CASE AND THE POINT OF THE WHOLE FUNCTION: an
 * app launching for the umpteenth time finds its path already written, gets []
 * back, and the mirror runs NOT ONE write query. It is what makes the cost of
 * this spec 40 writes once in the life of a thread instead of 40 per launch.
 *
 * MISSING IS NULL OR THE EMPTY STRING, and never 0: field_chat_opened_at is a
 * datestamp, so empty() would read a legitimate — if absurd — 1970 as unwritten
 * and rewrite it on every notice.
 *
 * @param array $current  One row of myapi_chat_field_state(): 'nid', 'vid' and
 *   the three current values under 'path', 'opened_at' and 'last_message_at'.
 * @param mixed $now      REQUEST_TIME. Cast here, so the caller passes what it
 *   has and the column always receives an integer.
 * @param bool $is_message  TRUE when a message was announced, FALSE when only a
 *   credential was issued.
 *
 * @return array  Column name => value to write, EMPTY when there is nothing to
 *   write. Keys are the field names, not the column names: turning
 *   'field_firebase_path' into its two tables is myapi_chat_field_write()'s job.
 */
function myapi_chat_field_values(array $current, $now, $is_message) {
  $values = [];

  if (!isset($current['path']) || $current['path'] === '') {
    $values['field_firebase_path'] = myapi_chat_thread_id($current['nid']);
  }

  if ($is_message) {
    if (!isset($current['opened_at']) || $current['opened_at'] === '') {
      $values['field_chat_opened_at'] = (int) $now;
    }

    $values['field_last_message_at'] = (int) $now;
  }

  return $values;
}

/**
 * Writes the mirror of one offer. The only impure part, and the only door.
 *
 * SIX MERGES AT MOST AND TWO INVALIDATIONS, WITH NO CRITERION INSIDE (SPEC
 * 117). It is handed a column list somebody else decided on, and it does not
 * know what a thread is, whether a message arrived or which of the three values
 * may be overwritten. Everything that could be wrong about the mirror was left
 * in myapi_chat_field_values(), where a test can reach it.
 *
 * TWO TABLES PER FIELD, BECAUSE A D7 FIELD IS TWO TABLES. field_data_* is what
 * a node_load() reads and field_revision_* is what a revision diff reads, and
 * NOBODY ELSE IS GOING TO WRITE THE SECOND ONE FOR US — this is the module's
 * first write that skips the Field API (decision 2). Both take THE SAME KEY:
 * the revision_id of the revision table is the offer's CURRENT vid, because no
 * new revision is created. Writing only field_data_* would leave the ficha
 * right and the revision empty, which is precisely the kind of half-write that
 * shows up months later as "the history lost it".
 *
 * db_merge() AND NOT db_insert(), because the same call has to be able to open
 * field_chat_opened_at and move field_last_message_at, and the second one has a
 * row already. It also makes the whole function idempotent: running it twice
 * with the same values leaves the same two rows.
 *
 * THE format COLUMN IS THE ONE THING THAT IS NOT SYMMETRIC. field_firebase_path
 * is a text field, so its tables carry a *_format column the two datestamps do
 * not have; it is written to NULL, which is exactly what the Field API would
 * store for a text_textfield with no filter. It is a property of the field's
 * TYPE and not a decision, which is why it reads off a map instead of an if.
 *
 * THE TWO INVALIDATIONS LIVE HERE AND NOT IN THE ENDPOINTS, and that is risk 1
 * of the spec answered in code: a fourth mirrored column added in a year
 * inherits them by coming through this door, instead of being remembered.
 *
 *   - cache_field is what a node_load() consults. Without clearing it, the back
 *     office would be shown the value from before the write, with no error —
 *     "I wrote the thread and the ficha still shows it empty".
 *   - the entity controller's static cache is the same problem inside THIS
 *     request: anything that loaded the offer before us holds the old values.
 *
 * NOTHING ELSE IS TOUCHED. node.changed keeps its value, no new revision is
 * created, and cache_node is left alone because it stores no field values.
 *
 * @param mixed $offer_nid  The offer being mirrored.
 * @param mixed $vid        Its CURRENT vid, from myapi_chat_field_state().
 * @param array $values     Field name => value, as myapi_chat_field_values()
 *   answers it. An empty array writes nothing and invalidates nothing.
 */
function myapi_chat_field_write($offer_nid, $vid, array $values) {
  if (!$values) {
    return;
  }

  $offer_nid = (int) $offer_nid;
  $vid = (int) $vid;

  // What each field type carries besides its _value column. A property of the
  // field, not a decision of this spec — see the docblock.
  $extra_columns = [
    'field_firebase_path' => ['field_firebase_path_format' => NULL],
  ];

  foreach ($values as $field_name => $value) {
    $key = [
      'entity_type' => 'node',
      'entity_id' => $offer_nid,
      'revision_id' => $vid,
      'deleted' => 0,
      'delta' => 0,
      'language' => LANGUAGE_NONE,
    ];

    $fields = [
      'bundle' => MYAPI_SERVICES_OFFER_TYPE,
      $field_name . '_value' => $value,
    ];
    if (isset($extra_columns[$field_name])) {
      $fields += $extra_columns[$field_name];
    }

    db_merge('field_data_' . $field_name)->key($key)->fields($fields)->execute();
    db_merge('field_revision_' . $field_name)->key($key)->fields($fields)->execute();
  }

  // What the Field API would have done for us. See the docblock.
  cache_clear_all('field:node:' . $offer_nid, 'cache_field');
  entity_get_controller('node')->resetCache([$offer_nid]);
}
