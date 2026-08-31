<?php

/**
 * @file
 * Chat credential resource for the myapi module (SPEC 115).
 *
 * ONE ROUTE: POST /api/v1/chat/token. It trades this API's Bearer for a Firebase
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

  $thread_ids = [];
  foreach ($offer_nids as $offer_nid) {
    $thread_ids[] = myapi_chat_thread_id($offer_nid);
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

  myapi_respond([
    'token'        => $jwt,
    // The same clock the payload's 'exp' is built on, off the same constant.
    'expires_at'   => REQUEST_TIME + MYAPI_FIREBASE_TOKEN_TTL,
    // A STRING, because that is what Firebase stores and what auth.uid will be.
    'firebase_uid' => (string) $uid,
    'threads'      => $threads,
  ]);
}
