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

  $live_statuses = [
    MYAPI_SERVICES_OFFER_STATUS_SENT,
    MYAPI_SERVICES_OFFER_STATUS_SELECTED,
  ];

  // nid => the request's 'changed', so the union can be ordered as a whole.
  $threads = [];

  // ---------------------------------------------------------------- resident.
  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);

  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);

  $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->condition('fos.field_offer_status_value', $live_statuses, 'IN');

  $query->innerJoin('field_data_field_provider', 'fp', "fp.entity_id = no.nid AND fp.entity_type = 'node' AND fp.deleted = 0");

  $query->innerJoin('node', 'rn', 'rn.nid = fq.field_request_target_id');
  $query->condition('rn.type', MYAPI_SERVICES_REQUEST_TYPE);
  $query->condition('rn.status', 1);

  // The rule of membership, in one join: the request is awarded, and it is
  // awarded to the provider this very offer belongs to.
  $query->innerJoin('field_data_field_assigned_provider', 'fap', "fap.entity_id = rn.nid AND fap.entity_type = 'node' AND fap.deleted = 0 AND fap.field_assigned_provider_target_id = fp.field_provider_target_id");

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
    $query = db_select('field_data_field_request', 'fq');
    $query->condition('fq.entity_type', 'node');
    $query->condition('fq.deleted', 0);

    $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
    $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
    $query->condition('no.status', 1);

    $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
    $query->condition('fos.field_offer_status_value', $live_statuses, 'IN');

    $query->innerJoin('field_data_field_provider', 'fp', "fp.entity_id = no.nid AND fp.entity_type = 'node' AND fp.deleted = 0");

    $query->innerJoin('node', 'rn', 'rn.nid = fq.field_request_target_id');
    $query->condition('rn.type', MYAPI_SERVICES_REQUEST_TYPE);
    $query->condition('rn.status', 1);

    $query->innerJoin('field_data_field_assigned_provider', 'fap', "fap.entity_id = rn.nid AND fap.entity_type = 'node' AND fap.deleted = 0 AND fap.field_assigned_provider_target_id = fp.field_provider_target_id");
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
