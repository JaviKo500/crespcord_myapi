<?php

/**
 * @file
 * Shared token helpers for the myapi module.
 *
 * Generation, hashing, TTL resolution and persistence of opaque access and
 * refresh tokens. Kept here so future refresh/logout resources can reuse it
 * without duplicating logic.
 */

/**
 * Default access token TTL in seconds (30 minutes).
 *
 * Effective value is resolved by myapi_token_access_ttl() via variable_get(),
 * overridable with `drush vset myapi_token_access_ttl <seconds>`.
 */
define('MYAPI_TOKEN_ACCESS_TTL_DEFAULT', 1800);

/**
 * Default refresh token TTL in seconds (30 days).
 *
 * Effective value is resolved by myapi_token_refresh_ttl() via variable_get(),
 * overridable with `drush vset myapi_token_refresh_ttl <seconds>`.
 */
define('MYAPI_TOKEN_REFRESH_TTL_DEFAULT', 2592000);

/**
 * Generates an opaque access token.
 *
 * @return string  64 hex chars (32 random bytes).
 */
function myapi_token_generate_access() {
  return bin2hex(random_bytes(32));
}

/**
 * Generates an opaque refresh token.
 *
 * @return string  128 hex chars (64 random bytes).
 */
function myapi_token_generate_refresh() {
  return bin2hex(random_bytes(64));
}

/**
 * Hashes a token for storage.
 *
 * @param string $token  The plaintext token.
 *
 * @return string  SHA-256 hex digest (64 chars).
 */
function myapi_token_hash($token) {
  return hash('sha256', $token);
}

/**
 * Resolves the effective access token TTL in seconds.
 *
 * Configurable at runtime with `drush vset myapi_token_access_ttl <seconds>`.
 *
 * @return int
 */
function myapi_token_access_ttl() {
  return variable_get('myapi_token_access_ttl', MYAPI_TOKEN_ACCESS_TTL_DEFAULT);
}

/**
 * Resolves the effective refresh token TTL in seconds.
 *
 * Configurable at runtime with `drush vset myapi_token_refresh_ttl <seconds>`.
 *
 * @return int
 */
function myapi_token_refresh_ttl() {
  return variable_get('myapi_token_refresh_ttl', MYAPI_TOKEN_REFRESH_TTL_DEFAULT);
}

/**
 * Default password reset token TTL in seconds (1 hour).
 *
 * Effective value is resolved by myapi_password_reset_ttl() via variable_get(),
 * overridable with `drush vset myapi_password_reset_ttl <seconds>`.
 */
define('MYAPI_PASSWORD_RESET_TTL_DEFAULT', 3600);

/**
 * Resolves the effective password reset token TTL in seconds.
 *
 * Configurable at runtime with `drush vset myapi_password_reset_ttl <seconds>`.
 *
 * @return int
 */
function myapi_password_reset_ttl() {
  return variable_get('myapi_password_reset_ttl', MYAPI_PASSWORD_RESET_TTL_DEFAULT);
}

/**
 * Generates an opaque password reset token.
 *
 * @return string  64 hex chars (32 random bytes).
 */
function myapi_token_generate_reset() {
  return bin2hex(random_bytes(32));
}

/**
 * Persists a freshly issued password reset token into myapi_password_reset_tokens.
 *
 * Only the SHA-256 hash is stored, never the plaintext token.
 *
 * @param int    $uid    Owner user id.
 * @param string $token  Plaintext reset token.
 * @param string $ip     IP address of the /forgot request that generated it.
 *
 * @return int  The inserted row id.
 */
function myapi_password_reset_token_persist($uid, $token, $ip) {
  return db_insert('myapi_password_reset_tokens')
    ->fields([
      'uid'         => $uid,
      'token_hash'  => myapi_token_hash($token),
      'expires_at'  => REQUEST_TIME + myapi_password_reset_ttl(),
      'used'        => 0,
      'created'     => REQUEST_TIME,
      'ip_address'  => $ip,
    ])
    ->execute();
}

/**
 * Invalidates all unused password reset tokens for a user.
 *
 * Called before issuing a new token so only one reset token stays valid at a
 * time, reducing the attack surface.
 *
 * @param int $uid  Owner user id.
 */
function myapi_password_reset_token_invalidate_previous($uid) {
  db_update('myapi_password_reset_tokens')
    ->fields(['used' => 1])
    ->condition('uid', $uid)
    ->condition('used', 0)
    ->execute();
}

/**
 * Persists a freshly issued token pair into my_api_tokens.
 *
 * Only the SHA-256 hashes are stored, never the plaintext tokens.
 *
 * @param int    $uid      Owner user id.
 * @param string $access   Plaintext access token.
 * @param string $refresh  Plaintext refresh token.
 *
 * @return int  The inserted row id.
 */
function myapi_token_persist($uid, $access, $refresh) {
  return db_insert('my_api_tokens')
    ->fields([
      'uid'                => $uid,
      'access_token_hash'  => myapi_token_hash($access),
      'refresh_token_hash' => myapi_token_hash($refresh),
      'access_expires_at'  => REQUEST_TIME + myapi_token_access_ttl(),
      'refresh_expires_at' => REQUEST_TIME + myapi_token_refresh_ttl(),
      'revoked'            => 0,
      'created'            => REQUEST_TIME,
      'user_agent'         => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : NULL,
      'ip_address'         => ip_address(),
    ])
    ->execute();
}
