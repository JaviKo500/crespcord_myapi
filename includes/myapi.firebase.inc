<?php

/**
 * @file
 * Firebase custom-token signing for the myapi module (SPEC 115).
 *
 * ISOLATED TRANSPORT LAYER, exactly like includes/myapi.onesignal.inc: it knows
 * nothing about offers, requests or providers. It receives a uid and an array
 * of custom claims and gives back a signed JWT. Who belongs to which chat
 * thread is includes/myapi.chat.inc's job, and the two never learn about each
 * other — the day the transport stops being Firebase, this file is the only one
 * that is thrown away.
 *
 * WHAT IT DOES AND WHAT IT WILL NEVER DO. It SIGNS. It does not call the
 * Firebase Admin API, it does not talk to the Realtime Database, and it carries
 * no message: the chat lives in Firebase, and a chat over PHP-FPM would need
 * polling or websockets, neither of which exists in Drupal 7 without a separate
 * process. Signing is the one thing that cannot be done from the client, because
 * the fact "this uid is a participant of that thread" lives in three Drupal
 * fields and the RTDB rules cannot query an external API.
 *
 * A JWT BY HAND, NOT kreait/firebase-php (SPEC 115, decision 3). The library
 * drags Guzzle and its whole tree into a D7 module whose composer.json holds
 * PHPUnit and nothing else — and it is not needed, because we only sign. What
 * is left is ~50 lines: two JSON documents, three base64url segments and one
 * openssl_sign().
 *
 * FOUR OF THE FIVE FUNCTIONS ARE PURE, and that is the point:
 * myapi_firebase_custom_token_payload() returns the payload as an array, so the
 * seven fields of the JWT can be asserted with no site booted and no private
 * key. Only myapi_firebase_sign_custom_token() touches the world.
 *
 * FAILURE NEVER THROWS AND NEVER LEAKS. A missing credential, a missing OpenSSL
 * extension or a refused signature is a FALSE plus a watchdog line with the real
 * reason; the caller decides what to answer. The credential itself is never
 * logged and never travels in a response.
 *
 * Credentials are read from Drupal variables, never hardcoded, and the pattern
 * is the one the two OneSignal variables already set:
 *   - myapi_firebase_service_account  Array with 'client_email' and
 *                                     'private_key', straight out of the JSON
 *                                     key file the Firebase console downloads.
 *   - myapi_firebase_database_url     The RTDB URL. NOT USED BY THIS MODULE —
 *                                     nothing here calls the database — and
 *                                     documented next to its sibling because
 *                                     that is where whoever configures the app
 *                                     will look for it.
 * Set them in settings.php, never in the repository and never in the variable
 * table of a shared environment:
 *   $conf['myapi_firebase_service_account'] = [
 *     'client_email' => 'firebase-adminsdk-xxxxx@PROJECT.iam.gserviceaccount.com',
 *     'private_key'  => "-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----\n",
 *   ];
 *
 * Every define() is guarded so a PHPUnit require_once of this file next to one
 * that already defined the same constant does not emit a redefinition notice
 * (the pattern of includes/myapi.services_common.inc).
 */

// The 'aud' of a custom token. A LITERAL OF THE PROTOCOL, not a URL of ours and
// not a per-project value: Google checks it byte for byte, and the same string
// is used by every Firebase project on earth.
if (!defined('MYAPI_FIREBASE_TOKEN_AUDIENCE')) {
  define('MYAPI_FIREBASE_TOKEN_AUDIENCE', 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit');
}

// Lifetime of the custom token, in seconds. 3600 IS THE MAXIMUM GOOGLE ACCEPTS:
// a larger 'exp' does not shorten the session, it makes Google reject the whole
// token. Not configurable on purpose (SPEC 115, decision 11) — a variable could
// only be used to get it wrong downwards.
//
// It has NOTHING TO DO with the 30 minutes of this module's own access token
// (includes/myapi.token.inc): the app trades this custom token for a Firebase
// ID token through signInWithCustomToken(), and THAT one refreshes itself
// without ever hitting this API again. The two clocks do not have to agree.
if (!defined('MYAPI_FIREBASE_TOKEN_TTL')) {
  define('MYAPI_FIREBASE_TOKEN_TTL', 3600);
}

/**
 * The service account credential, or NULL when it is not usable.
 *
 * Reads the Drupal variable and demands BOTH halves: a client_email with no
 * private key signs nothing, and a private key with no client_email signs
 * something Google refuses, because 'iss' and 'sub' are that email.
 *
 * NEVER LOGS THE CREDENTIAL AND NEVER RETURNS IT TO A RESPONSE. The one caller
 * that answers HTTP — myapi_chat_token() — reads only whether this is NULL.
 *
 * @return array|null
 *   ['client_email' => string, 'private_key' => string], or NULL when either
 *   half is missing or blank.
 */
function myapi_firebase_service_account() {
  $service_account = variable_get('myapi_firebase_service_account', []);

  if (!is_array($service_account)) {
    return NULL;
  }

  $client_email = isset($service_account['client_email']) ? trim((string) $service_account['client_email']) : '';
  $private_key  = isset($service_account['private_key'])  ? (string) $service_account['private_key'] : '';

  // trim() decides the private key is missing, but the UNTRIMMED value is what
  // is returned: openssl_sign() wants the PEM with its trailing newline, and a
  // key whose only content is whitespace is not a key.
  if ($client_email === '' || trim($private_key) === '') {
    return NULL;
  }

  return [
    'client_email' => $client_email,
    'private_key'  => $private_key,
  ];
}

/**
 * Whether this site can sign a Firebase custom token at all.
 *
 * The sister of myapi_onesignal_is_configured(), with ONE condition that has no
 * equivalent there: function_exists('openssl_sign'). A PHP build with no
 * OpenSSL extension cannot produce an RS256 signature, and that is "not set up"
 * and not "broken" — which is exactly the difference between the 503 and the
 * 500 of this feature's endpoint.
 *
 * @return bool
 */
function myapi_firebase_is_configured() {
  return myapi_firebase_service_account() !== NULL && function_exists('openssl_sign');
}

/**
 * base64url of a byte string: base64 with '+/' swapped for '-_' and no padding.
 *
 * PURE. The encoding JWT uses on all three segments — a '+' or a '/' would be
 * re-interpreted by anything that carries the token in a URL, and the '=' is
 * padding a JWT parser reconstructs on its own.
 *
 * @param string $bytes  Raw bytes (a JSON document, or a binary signature).
 *
 * @return string  base64url, unpadded.
 */
function myapi_firebase_base64url_encode($bytes) {
  return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * The payload of a custom token, as an array.
 *
 * PURE, AND THAT IS WHY IT EXISTS as its own function: the seven fields below
 * are the whole contract with Google, and this shape lets every one of them be
 * asserted with no site booted and no private key — including the two that are
 * silently fatal when wrong, 'uid' being a STRING and 'exp' being exactly one
 * hour after 'iat'.
 *
 * 'iss' AND 'sub' ARE THE SAME VALUE. That is not a copy-paste: in a custom
 * token the issuer and the subject are both the service account.
 *
 * 'uid' IS A STRING. Firebase requires it and caps it at 128 characters; a
 * Drupal uid is an integer, so the cast is the whole of the adaptation. It is
 * the value the RTDB rules will see as auth.uid.
 *
 * @param mixed  $uid           The Drupal uid.
 * @param string $client_email  The service account's email (iss and sub).
 * @param array  $claims        Custom claims, verbatim.
 * @param mixed  $now           The 'iat', normally REQUEST_TIME.
 *
 * @return array  The payload, ready to be JSON-encoded.
 */
function myapi_firebase_custom_token_payload($uid, $client_email, array $claims, $now) {
  $now = (int) $now;

  return [
    'iss'    => (string) $client_email,
    'sub'    => (string) $client_email,
    'aud'    => MYAPI_FIREBASE_TOKEN_AUDIENCE,
    'iat'    => $now,
    'exp'    => $now + MYAPI_FIREBASE_TOKEN_TTL,
    'uid'    => (string) $uid,
    'claims' => $claims,
  ];
}

/**
 * Signs a Firebase custom token for a uid, carrying the given custom claims.
 *
 * THE ONLY IMPURE FUNCTION OF THIS FILE: it reads the credential, reads
 * REQUEST_TIME and calls openssl_sign(). Everything it assembles comes from the
 * pure helpers above, so what is left here is the three segments and the one
 * call that can fail.
 *
 * THE BYTES THAT ARE SIGNED ARE THE BYTES THAT ARE SENT. openssl_sign() is
 * handed '<header>.<payload>' already base64url-encoded, and the very same
 * string is what precedes the signature in the returned token. Re-encoding
 * either half after signing is the classic way to hand out a JWT that verifies
 * nowhere, and no assertion over arrays catches it — which is why the test
 * generates a key pair and runs openssl_verify() over this exact
 * concatenation.
 *
 * NEVER THROWS, and never leaks. FALSE plus a watchdog line with the real
 * reason; the caller decides what the client is told, and the client is told
 * nothing about which half of the credential is missing.
 *
 * @param mixed $uid     The Drupal uid the token identifies.
 * @param array $claims  Custom claims. Firebase caps them at 1000 bytes in
 *                       total — the caller is what enforces that; see
 *                       myapi_chat_threads_claim().
 *
 * @return string|false  The signed JWT, or FALSE on any failure.
 */
function myapi_firebase_sign_custom_token($uid, array $claims) {
  $service_account = myapi_firebase_service_account();

  if ($service_account === NULL) {
    watchdog('myapi', 'Firebase custom token not signed: myapi_firebase_service_account is missing client_email and/or private_key.', [], WATCHDOG_WARNING);
    return FALSE;
  }

  if (!function_exists('openssl_sign')) {
    watchdog('myapi', 'Firebase custom token not signed: the OpenSSL extension is not available (openssl_sign).', [], WATCHDOG_WARNING);
    return FALSE;
  }

  $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
  $payload = myapi_firebase_custom_token_payload($uid, $service_account['client_email'], $claims, REQUEST_TIME);

  $signing_input = myapi_firebase_base64url_encode(drupal_json_encode($header))
    . '.'
    . myapi_firebase_base64url_encode(drupal_json_encode($payload));

  // THE KEY IS READ BEFORE IT IS USED, and not handed to openssl_sign() raw.
  // A malformed PEM makes openssl_sign() emit a PHP warning on its way to
  // returning FALSE, and a warning is not this function's contract: the
  // contract is FALSE plus one watchdog line. Reading the key first fails
  // silently and lets the reason be logged in the module's own words.
  $private_key = openssl_pkey_get_private($service_account['private_key']);
  if ($private_key === FALSE) {
    watchdog('myapi', 'Firebase custom token not signed: the private key in myapi_firebase_service_account could not be read (@error).', [
      '@error' => ($error = openssl_error_string()) === FALSE ? 'no OpenSSL error reported' : $error,
    ], WATCHDOG_ERROR);

    return FALSE;
  }

  $signature = '';
  if (!openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
    // openssl_error_string() is drained here and not in the response: it names
    // the private key's problem, which is the one thing a client must never be
    // told and the one thing whoever reads the log needs.
    $error = openssl_error_string();
    watchdog('myapi', 'Firebase custom token not signed: openssl_sign() failed (@error).', [
      '@error' => $error === FALSE ? 'no OpenSSL error reported' : $error,
    ], WATCHDOG_ERROR);

    return FALSE;
  }

  return $signing_input . '.' . myapi_firebase_base64url_encode($signature);
}
