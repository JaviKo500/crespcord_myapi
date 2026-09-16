<?php

/**
 * @file
 * The machine credential for the bot endpoints (SPEC 127).
 *
 * The WhatsApp bot is not a user: it lives in n8n, has no session, and the
 * Bearer flow of SPEC 02 would force it to log in before every call and to
 * manage the refresh. A static API key in a Drupal variable, compared with
 * hash_equals(), is the smallest credential that answers that case.
 *
 * This guard lives here and not inside resources/bot.resource.inc for the same
 * reason myapi_auth_require_access_token() lives in includes/myapi.auth.inc:
 * the next bot endpoint reuses it without copying a line.
 *
 * It reads no table. That is the invariant EndpointContractTest turns into a
 * test: a request without a valid credential must be rejected before anything
 * touches the database.
 */

module_load_include('inc', 'myapi', 'includes/myapi.response');

/**
 * Rejects the request unless it carries the configured bot API key.
 *
 * The key travels in the X-Api-Key header, which Drupal 7 exposes as
 * $_SERVER['HTTP_X_API_KEY'] — core does not normalise custom headers.
 *
 * There is no default: variable_get() falls back to the empty string, and the
 * empty string never authenticates. A deployment where nobody ran
 * `drush vset myapi_bot_api_key` answers 401 to everything, which is the safe
 * end of the two — an endpoint left open because it was never configured is
 * worse than one that does not work until it is.
 *
 * Every rejection is logged at WATCHDOG_WARNING. The real risk on this
 * endpoint is not brute force (the key is a random secret) but the key
 * leaking, and the log is what makes an attempt to guess it visible.
 *
 * Returns normally when the key matches; otherwise answers 401 and exits
 * through myapi_error(), which never returns.
 */
function myapi_bot_require_api_key() {
  $configured = (string) variable_get('myapi_bot_api_key', '');
  $received = isset($_SERVER['HTTP_X_API_KEY']) ? (string) $_SERVER['HTTP_X_API_KEY'] : '';

  // The two emptiness checks are not redundant with hash_equals(): an
  // unconfigured site plus a caller sending an empty header would otherwise
  // compare '' with '' and authenticate.
  if ($configured === '' || $received === '' || !hash_equals($configured, $received)) {
    watchdog(
      'myapi',
      'Bot API key rejected for @path from @ip.',
      [
        '@path' => current_path(),
        '@ip'   => ip_address(),
      ],
      WATCHDOG_WARNING
    );
    myapi_error('unauthorized', 401);
  }
}
