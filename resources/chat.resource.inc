<?php

/**
 * @file
 * Chat credential resource for the myapi module (SPEC 115).
 *
 * TWO ROUTES, AND THEY ARE THE SAME PROMISE READ TWICE (SPEC 116 added the
 * second):
 *
 *   POST /api/v1/chat/token           who you are, and which threads are yours.
 *   POST /api/v1/chat/threads/%/notify  somebody wrote in this thread; tell the
 *                                     other side.
 *
 * NEITHER OF THEM STORES A MESSAGE, and the second one is the interesting case.
 * It is told that there was a message and, since the revision of SPEC 116, a
 * PREVIEW of what it said. Its job is to work out who the other side is — the
 * very rule the first route signs into a claim — and hand OneSignal a banner
 * naming the writer, the request and up to 140 characters of the text. The
 * preview PASSES THROUGH: it is sanitised, sent and forgotten, and it is never
 * written to a table, a log or a watchdog entry.
 *
 * THE FIRST ROUTE: POST /api/v1/chat/token. It trades this API's Bearer for a Firebase
 * CUSTOM TOKEN signed by the server, whose custom claims declare WHICH CHAT
 * THREADS this account takes part in, so the Realtime Database rules can
 * authorise the conversation between a resident and their awarded provider
 * WITHOUT Firebase ever asking Drupal and WITHOUT Drupal carrying a single
 * message.
 *
 * THIS ENDPOINT CARRIES NO MESSAGES AND NEVER WILL. Messages live in Firebase.
 * The module does ONE thing: it signs who you are and which threads are yours.
 * Ordering, offline, unread counts, typing and presence are Firebase's, and none
 * of them touches this file.
 *
 * THE ONE THING THAT CANNOT BE DONE FROM THE CLIENT IS AUTHORISATION. Firebase
 * has no way of knowing that uid 412 is the resident of request 380 or an
 * account of the awarded provider: that fact lives in field_requester,
 * field_assigned_provider and field_provider_users, and an RTDB rule cannot
 * query an external API.
 *
 * 'chat/token' AND NOT 'firebase/token' (SPEC 115, decision 1). myapi.install
 * already wrote down that the transport "may not end up being Firebase at all".
 * The app asks for THE CHAT CREDENTIAL; what signs it is a detail of the server.
 * If the transport changes, the body of the response changes — not the URL that
 * already-published versions of the app depend on.
 *
 * A RESOURCE OF ITS OWN AND NOT ONE MORE FUNCTION IN auth.resource.inc
 * (decision 2, and Rule 2 of CLAUDE.md). It does trade a credential, but its
 * gate READS OFFERS AND PROVIDERS: folded into the auth resource, that file
 * would start knowing what an awarded offer is.
 *
 * THE BODY IS NOT READ AT ALL. There is not one key to send — who you are is
 * what the Bearer says and which threads are yours is what the database says —
 * so a body with keys and a body that is malformed JSON are the same thing:
 * ignored. Nothing is parsed, so nothing can fail, and there is no 422 on this
 * route (decision 10). Same criterion and same wording as the 'accept' of
 * SPEC 106 and the 'withdraw' of SPEC 105.
 *
 * NOT A SINGLE ROW IS WRITTEN. No field, no table, no hook_update_N, no
 * drush updb: this endpoint reads, signs and answers.
 *
 * ONE CALL PER SESSION IS ENOUGH, and that is worth saying out loud because it
 * is the number-one confusion about custom tokens: the app trades this one for
 * an ID TOKEN through signInWithCustomToken(), and THAT is what talks to the
 * database and refreshes itself every hour without coming back here.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.flood');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');

// The catalogue of bundle machine names and the two live offer statuses the
// rule of membership is written against.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');

// The module's one definition of "which providers are this account's".
module_load_include('inc', 'myapi', 'includes/myapi.provider_role');

// The two halves of the credential: who belongs to which thread, and the JWT.
module_load_include('inc', 'myapi', 'includes/myapi.chat');
module_load_include('inc', 'myapi', 'includes/myapi.firebase');

// SPEC 116, for the 'notify' route only.
//
// The transport of the banner. myapi_notification_create() is NOT loaded and
// NOT used: a chat message writes no inbox row (decision 3).
module_load_include('inc', 'myapi', 'includes/myapi.onesignal');

// For MYAPI_NOTIFICATION_AUDIENCE_RESIDENT / _PROVIDER — the same two words
// every other push of the module puts in its 'audience' key.
module_load_include('inc', 'myapi', 'includes/myapi.notification');

// For myapi_service_request_provider_uids() (SPEC 109's "every active account
// of field_provider_users, role or no role"), myapi_service_request_node_title()
// and the label that degrades an unresolved value to a dash.
module_load_include('inc', 'myapi', 'includes/myapi.service_request_notification');

// For myapi_user_fetch_profile_fields(), the resident's name in the banner.
module_load_include('inc', 'myapi', 'includes/myapi.user');

// For myapi_text_to_plain() (SPEC 79), which IS the preview's sanitising.
module_load_include('inc', 'myapi', 'includes/myapi.text');

/**
 * Dispatcher for api/v1/chat/token.
 *
 * POST and nothing else, and the 405 is answered BEFORE the token and BEFORE any
 * query — every dispatcher of this module opens the same way.
 */
function myapi_chat_token_dispatch() {
  if (myapi_request_method() === 'POST') {
    myapi_chat_token();
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * POST /api/v1/chat/token — the chat credential (SPEC 115).
 *
 * THE GATE, IN ORDER, AND THE ORDER IS THE CONTRACT:
 *
 *   1. The method.     405 method_not_allowed, in the dispatcher above.
 *   2. Flood by IP.    429 too_many_attempts.
 *   3. Authentication. 401 missing_authorization / invalid_token.
 *   4. Configuration.  503 chat_not_configured.
 *   5. The signature.  500 chat_token_failed.
 *
 * THE 429 COMES BEFORE THE TOKEN, NOT AFTER (decision 12). Every call costs an
 * RSA signature, and the point is to bound that cost, not to punish an
 * authenticated user. The counter is BY IP and not by uid for the plainest of
 * reasons: before step 3 there is no uid to count against. It is also registered
 * on EVERY call and not only on a failure — the divergence from
 * myapi_auth_logout() and myapi_auth_refresh(), which count failed attempts,
 * because here there is no such thing as a failed attempt to rate-limit; the
 * work itself is what is being limited.
 *
 * THE 503 OF STEP 4 IS NOT A 500, ON PURPOSE. A deployment whose settings.php is
 * missing the credential is not broken, it is NOT SET UP, and that difference is
 * what tells whoever reads the log where to go. The client is told neither which
 * half of the credential is missing nor that OpenSSL is absent; watchdog is.
 *
 * ZERO THREADS IS A 200, NOT A 403 (decision 7). A resident with nothing awarded
 * yet gets a valid token and threads: []. The token states an identity; the list
 * of threads is a datum, and an empty datum is a datum.
 *
 * `threads` TRAVELS EVEN THOUGH THE APP COULD DERIVE IT. It is the list this
 * token ACTUALLY authorises — already cut to what fits in the claim, exactly the
 * same values, taken from the very string that was signed so the two cannot
 * drift. THE APP MUST NOT PAINT A CHAT ITS TOKEN DOES NOT COVER: if this
 * disagrees with what the app believes, this is the one that is right.
 */
function myapi_chat_token() {
  // 2. Flood by IP, before the token: this is a cost limiter, so it counts
  // every call, successful ones included.
  $ip = ip_address();
  myapi_flood_check('myapi_chat_token_ip', $ip, 'myapi_flood_chat_token_ip_limit', 'myapi_flood_chat_token_ip_window');
  myapi_flood_register('myapi_chat_token_ip', $ip, 'myapi_flood_chat_token_ip_window');

  // 3. Authentication. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 4. Is this site able to sign at all? The three things that can be missing
  // are named ONE BY ONE in the log — a deployment that answers 503 must not
  // leave whoever reads the log guessing between the credential and the PHP
  // build. Only the PRESENCE of the two keys is inspected here; their values
  // never leave includes/myapi.firebase.inc.
  if (!myapi_firebase_is_configured()) {
    $service_account = variable_get('myapi_firebase_service_account', []);
    $service_account = is_array($service_account) ? $service_account : [];

    $missing = [];
    if (!isset($service_account['client_email']) || trim((string) $service_account['client_email']) === '') {
      $missing[] = "myapi_firebase_service_account['client_email']";
    }
    if (!isset($service_account['private_key']) || trim((string) $service_account['private_key']) === '') {
      $missing[] = "myapi_firebase_service_account['private_key']";
    }
    if (!function_exists('openssl_sign')) {
      $missing[] = 'the OpenSSL extension (openssl_sign)';
    }

    watchdog('myapi', 'Chat token not issued: the chat is not configured — missing @missing.', [
      '@missing' => implode(', ', $missing),
    ], WATCHDOG_WARNING);

    myapi_error('chat_not_configured', 503);
  }

  // The rule of membership, and the only queries of this endpoint.
  $offer_nids = myapi_chat_offer_nids_for_uid($uid);

  // The path of each thread, and the nid it came from. The second one is the
  // mirror's (SPEC 117): what is written down has to be exactly what the claim
  // ends up carrying, and after the cut the only way back from a path to its
  // offer is a map built here — parsing 'service_offers/901' apart again would
  // be a second definition of a convention myapi_chat_thread_id() owns.
  $thread_ids = [];
  $offer_nid_by_thread = [];
  foreach ($offer_nids as $offer_nid) {
    $thread_id = myapi_chat_thread_id($offer_nid);
    $thread_ids[] = $thread_id;
    $offer_nid_by_thread[$thread_id] = $offer_nid;
  }

  // The claim is built ONCE and the response is derived FROM IT, never rebuilt
  // from $thread_ids: what is answered is what was signed, cut included.
  $claim = myapi_chat_threads_claim($thread_ids);
  $threads = $claim === '' ? [] : explode(',', $claim);

  // 5. The signature. FALSE has already said why in watchdog; the client gets a
  // key of its own and not a recycled server_error, because the app can do
  // something different with it — retry the chat without dropping the session.
  $jwt = myapi_firebase_sign_custom_token($uid, ['threads' => $claim]);
  if ($jwt === FALSE) {
    myapi_error('chat_token_failed', 500);
  }

  // The mirror (SPEC 117), AFTER the signature and BEFORE the response, which
  // exits. It writes field_firebase_path on THE THREADS THIS TOKEN AUTHORISES
  // and not on every thread of the membership query (decision 3):
  // myapi_chat_offer_nids_for_uid() is not cut — the cut to
  // MYAPI_CHAT_MAX_THREADS is myapi_chat_threads_claim()'s — so a large
  // provider could have hundreds of live offers, and mirroring a thread the
  // credential does not cover would be writing down something that did not
  // happen. The batch is bounded at 40 by construction, with no new constant.
  //
  // IT CANNOT CHANGE ONE BYTE OF WHAT IS ANSWERED BELOW: it reads nothing back,
  // it swallows its own failures into watchdog (decision 4), and in steady
  // state — every path already written — it runs not one write query.
  $mirror_nids = [];
  foreach ($threads as $thread_id) {
    $mirror_nids[] = $offer_nid_by_thread[$thread_id];
  }
  myapi_chat_field_sync($mirror_nids, FALSE);

  myapi_respond([
    'token'        => $jwt,
    // The same clock the payload's 'exp' is built on, off the same constant.
    'expires_at'   => REQUEST_TIME + MYAPI_FIREBASE_TOKEN_TTL,
    // A STRING, because that is what Firebase stores and what auth.uid will be.
    'firebase_uid' => (string) $uid,
    'threads'      => $threads,
  ]);
}

/**
 * Dispatcher for api/v1/chat/threads/%/notify (SPEC 116).
 *
 * POST and nothing else, and the 405 comes before the flood, before the token
 * and before any query — the same opening as every dispatcher of this module.
 *
 * @param mixed $offer_nid  The OFFER's nid, from the route.
 */
function myapi_chat_notify_dispatch($offer_nid = NULL) {
  if (myapi_request_method() === 'POST') {
    myapi_chat_notify($offer_nid);
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * POST /api/v1/chat/threads/{offer_nid}/notify — tell the other side that a
 * message was written (SPEC 116).
 *
 * WHAT THIS ENDPOINT IS TOLD, AND WHAT IT CAN DO WITH IT. Who wrote is what the
 * Bearer says, where is what the URL says, and what was written is what the
 * body's OPTIONAL 'preview' says. It still does not read the Realtime Database
 * and could not: the message lives there and this server has no client for it.
 * So THE PREVIEW IS THE SENDER'S WORD FOR THEIR OWN MESSAGE, not something this
 * server verified (decision 2a) — a modified client can send one thing and
 * write another, and the worst that achieves is showing a false line to
 * somebody it could already chat with.
 *
 * THERE IS STILL NO 422 ON THIS ROUTE, and now that there IS a body that is a
 * decision rather than an accident (decision 2b). The notice is
 * fire-and-forget: nobody reads this response, so a 422 would be a banner lost
 * to a validation the server can settle by itself. Everything degrades instead:
 * a long preview is CUT, a preview that is not text is IGNORED, a malformed
 * body is IGNORED WHOLE, and an absent one simply gives the two-line banner
 * every client got before the revision.
 *
 * THE CLIENT THAT WROTE IS WHAT FIRES THIS, and it is the only process that
 * knows a message happened without somebody watching Firebase (decision 1). The
 * app writes to the RTDB FIRST and calls this SECOND, fire-and-forget. The price
 * is written down in the spec's risks: if the phone dies between the two steps,
 * the message still arrives and the banner does not.
 *
 * THE GATE, IN ORDER, AND THE ORDER IS THE CONTRACT:
 *
 *   1. The method.      405 method_not_allowed, in the dispatcher above.
 *   2. Flood by IP.     429 too_many_attempts.
 *   3. Authentication.  401 missing_authorization / invalid_token.
 *   4. The thread.      404 not_found — for "there is no such thread" AND for
 *                       "it is not yours", with the same body.
 *
 * THE 429 COMES BEFORE THE TOKEN for the same reason it does on the token route:
 * before step 3 there is no uid to count against. The ceiling is high — 600 an
 * hour per IP — because CHATTING IS MANY CALLS and a whole household shares one
 * address. What actually bounds the outgoing traffic is not this counter, it is
 * the debounce, which is why there is no third counter per uid (decision 11).
 *
 * STEP 4 IS ONE 404 FOR TWO DIFFERENT THINGS, ON PURPOSE (decision 6). Telling
 * "it exists but is not yours" (403) apart from "it does not exist" (404) would
 * turn this route into an enumerator of live threads: ask for 1..N and you learn
 * which offers are awarded and active. A malformed or negative nid lands in the
 * same 404 through myapi_chat_thread_row(), which is why there is no separate
 * guard for it. The key is the generic 'not_found' already in the catalogue —
 * what service-offers/%/withdraw and %/accept already answer.
 *
 * notified: 0 IS A 200 AND NEVER AN ERROR (decision 10). Not when the other side
 * has no active account, not when the debounce silenced everyone, not when
 * OneSignal is unconfigured, not when the call to OneSignal failed. THE MESSAGE
 * IS ALREADY IN FIREBASE and the chat works without a banner; a 5xx here would
 * only make the app doubt a message that did arrive. The real failures go to
 * watchdog, which is where they are looked at.
 *
 * SYNCHRONOUS AND NOT QUEUED (decision 4). The push queue is drained by
 * `drush queue-run` once a minute, and a minute is half a conversation. The cost
 * the queue exists to avoid — blocking the caller — does not apply: nobody is
 * waiting for this response. The timeout drops from 30 to 5 because what is
 * being protected is the PHP-FPM process, not a user.
 *
 * NOT ONE ROW IS WRITTEN, and myapi_notification_create() is not called
 * (decision 3): a chat message does not go to the inbox, because the inbox has
 * no way of learning that you read the chat — that happens in Firebase — so the
 * row would stay unread for ever and the badge would be permanently dirty. The
 * one write anywhere near this endpoint is the Flood API's own table.
 *
 * @param mixed $offer_nid  The OFFER's nid, from the route. NOT the thread path:
 *   'service_offers/901' has a slash in it and does not fit in one URL
 *   component (decision 7). The path is derived by myapi_chat_thread_id(), which
 *   stays the single definition of the convention.
 */
function myapi_chat_notify($offer_nid) {
  // 2. Flood by IP, before the token. Counts every call, successful ones
  // included: what is limited is the work, not a failed attempt.
  $ip = ip_address();
  myapi_flood_check('myapi_chat_notify_ip', $ip, 'myapi_flood_chat_notify_ip_limit', 'myapi_flood_chat_notify_ip_window');
  myapi_flood_register('myapi_chat_notify_ip', $ip, 'myapi_flood_chat_notify_ip_window');

  // 3. Authentication. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 4. The thread, and then who the caller is in it. TWO 404s WITH ONE BODY.
  $thread = myapi_chat_thread_row($offer_nid);
  if ($thread === NULL) {
    myapi_error('not_found', 404);
  }

  // The provider's accounts are needed twice — to place the caller and to
  // address the notice — so they are asked for once. SPEC 109's function, not a
  // query of this file's own.
  $provider_uids = myapi_service_request_provider_uids($thread['provider_id']);

  $side = myapi_chat_thread_side($thread, $provider_uids, $uid);
  if ($side === NULL) {
    myapi_error('not_found', 404);
  }

  $recipients = myapi_chat_notify_recipients($thread, $provider_uids, $side, $uid);
  $thread_id = myapi_chat_thread_id($thread['offer_nid']);

  // The preview, and the ONLY line of this endpoint that touches the message.
  // myapi_request_body() answers NULL for an absent or malformed body, so the
  // two of them and an absent key are one expression and not three branches;
  // myapi_chat_message_preview() answers NULL for everything else that cannot
  // be shown. NOTHING IS VALIDATED AND NOTHING IS REFUSED — see the docblock.
  $body = myapi_request_body();
  $preview = myapi_chat_message_preview(isset($body['preview']) ? $body['preview'] : NULL);

  // The debounce, one recipient at a time: at most one banner per thread and
  // per person every 60 seconds. A silenced recipient is a 'muted', never an
  // error — which is why this reads myapi_chat_notify_allowed() and not
  // myapi_flood_check().
  //
  // It also makes this endpoint IDEMPOTENT INSIDE THE WINDOW without a line
  // written to be: an app that retries the POST, or sends it twice after a
  // reconnection, silences its own duplicate.
  $allowed = [];
  $muted = 0;
  foreach ($recipients as $recipient_uid) {
    if (myapi_chat_notify_allowed($thread['offer_nid'], $recipient_uid)) {
      $allowed[] = $recipient_uid;
    }
    else {
      $muted++;
    }
  }

  $notified = 0;

  if ($allowed) {
    // Every recipient of one call is on the SAME side — the other one — so the
    // payload is built once and one HTTP call serves them all.
    $audience = ($side === MYAPI_NOTIFICATION_AUDIENCE_RESIDENT)
      ? MYAPI_NOTIFICATION_AUDIENCE_PROVIDER
      : MYAPI_NOTIFICATION_AUDIENCE_RESIDENT;

    $data = [
      'target'            => MYAPI_CHAT_DEEP_LINK_TARGET,
      'id'                => $thread['offer_nid'],
      // The eighth key, and the only one no other push of the module carries.
      'thread'            => $thread_id,
      'notification_type' => MYAPI_CHAT_NOTIFICATION_TYPE,
      // THE RECIPIENT'S, NOT THE SENDER'S.
      'audience'          => $audience,
      'provider'          => $thread['provider_id'],
      // To BOTH sides: knowing which complex a job is in is not knowing which
      // door it is behind, and SPEC 109 already tells the provider this much.
      'condominium'       => $thread['condominium_id'],
      // TO THE RESIDENT ONLY, AND THIS IS A RULE AND NOT AN OMISSION. An account
      // can hold more than one home and the app always works "inside" one, so
      // without this the banner opens the thread in whichever home was last on
      // screen — possibly the wrong house. Towards the PROVIDER it is NULL
      // always, even when the request has a unit: SPEC 109's own words, "a
      // provider does not learn which home asked until they open the detail
      // endpoint". Relaxing it here would leak through the chat what the detail
      // endpoint protects at the front door (decision 13).
      'unit'              => $audience === MYAPI_NOTIFICATION_AUDIENCE_RESIDENT ? $thread['unit_id'] : NULL,
    ];

    $sent = myapi_onesignal_send(
      $allowed,
      // The company when the provider writes, the resident's own name when the
      // resident does — never the employee's (decision 9).
      myapi_chat_message_push_title(myapi_chat_sender_label($thread, $side, $uid)),
      // The request's title, and the preview under it when there is one. The
      // title is NOT replaced by the message: a provider with five open jobs
      // has to learn which of the five wrote.
      myapi_chat_message_push_body($thread['request_title'], $preview),
      $data,
      [
        // Twenty messages in a row are ONE banner that gets replaced, not
        // twenty stacked.
        'collapse_id'   => 'chat_' . $thread['offer_nid'],
        'thread_id'     => $thread_id,
        'android_group' => 'chat_' . $thread['offer_nid'],
        // A chat notice from six hours ago is worth nothing.
        'ttl'           => MYAPI_CHAT_PUSH_TTL,
        // 5 and not 30: this call is made inside the request, and what it
        // protects is the PHP-FPM process.
        'timeout'       => 5,
      ]
    );

    // THE WINDOW IS BURNT ONLY WHEN A BANNER ACTUALLY WENT OUT. Unconfigured
    // credentials or a failed call leave it open, so the NEXT message tries
    // again instead of a minute of outage costing a minute of silence.
    if ($sent) {
      foreach ($allowed as $recipient_uid) {
        myapi_chat_notify_register($thread['offer_nid'], $recipient_uid);
      }
      $notified = count($allowed);
    }
  }

  myapi_respond([
    // Derived and answered back so the client can check it talked about the
    // thread it thought it was talking about.
    'thread'     => $thread_id,
    'recipients' => count($recipients),
    'notified'   => $notified,
    // ONLY the debounce. A recipient nobody could reach because OneSignal was
    // down is not 'muted' — it is a failure, it is in watchdog, and the next
    // message will try again.
    'muted'      => $muted,
  ]);
}
