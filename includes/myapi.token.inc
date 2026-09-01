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

/* ---------------------------------------------------------------------------
 * Purge.
 *
 * Both token tables are append-only everywhere above this comment: a login
 * writes a row, a refresh writes another and revokes the first, a reset writes
 * one and marks it used. Nothing ever deleted. Over a couple of years that is
 * one row per login of every account on the site, all of them carrying a
 * hash, an IP and a User-Agent long after they can authenticate anything —
 * data with no purpose left, which is the definition of what a breach should
 * not find.
 *
 * The grace period is what separates "expired" from "deletable": a row still
 * answers the support question "when did this device last log in, and from
 * where" for a while after it stops being a credential. It is one week by
 * default and one variable to change.
 * ------------------------------------------------------------------------ */

/**
 * Default grace period before an unusable token row is deleted (7 days).
 *
 * Effective value is resolved by myapi_token_purge_grace() via variable_get(),
 * overridable with `drush vset myapi_token_purge_grace <seconds>`.
 */
define('MYAPI_TOKEN_PURGE_GRACE_DEFAULT', 604800);

/**
 * Maximum rows one cron run deletes from each table.
 *
 * The purge is bounded rather than unbounded because the FIRST run after this
 * code is deployed meets every row ever written, and a DELETE of unknown size
 * inside cron is how a cron run starts timing out. This module sets no cron
 * interval of its own — the purge runs once per pass of whatever cron the site
 * has — so the drain rate is that interval times this ceiling: with hourly
 * cron, say, a backlog of a million rows clears in a few days on its own, with
 * no manual step and no long-running query on a production database.
 */
define('MYAPI_TOKEN_PURGE_LIMIT', 5000);

/**
 * Resolves the effective purge grace period in seconds.
 *
 * @return int
 */
function myapi_token_purge_grace() {
  return (int) variable_get('myapi_token_purge_grace', MYAPI_TOKEN_PURGE_GRACE_DEFAULT);
}

/**
 * Deletes a bounded batch of rows matching a condition group.
 *
 * The ids are SELECTed first and DELETEd by primary key, in two statements,
 * because db_delete() has no ->range() in Drupal 7 and an unbounded DELETE is
 * exactly what the ceiling above exists to avoid. Written once here so the two
 * tables below cannot drift apart in how they are drained.
 *
 * @param string             $table       Table name.
 * @param DatabaseCondition  $condition   The rows to consider deletable.
 *
 * @return int  Rows actually deleted.
 */
function myapi_token_purge_batch($table, $condition) {
  $ids = db_select($table, 't')
    ->fields('t', ['id'])
    ->condition($condition)
    ->range(0, MYAPI_TOKEN_PURGE_LIMIT)
    ->execute()
    ->fetchCol();

  if (!$ids) {
    return 0;
  }

  return (int) db_delete($table)
    ->condition('id', $ids, 'IN')
    ->execute();
}

/**
 * Purges access/refresh token rows that can no longer authenticate anything.
 *
 * TWO CONDITIONS AND NOT ONE, because a row dies in two different ways:
 *
 *   1. It EXPIRED. refresh_expires_at is the outer bound of the pair — the
 *      access token always expires first (30 minutes against 30 days), so a
 *      row past its refresh expiry is past everything.
 *   2. It was REVOKED. A logout, a refresh rotation or a password reset kills
 *      the row instantly, months before its refresh_expires_at. Waiting for
 *      that date would keep every rotated token of an active user on disk, and
 *      an active user rotates one every 30 minutes — the bulk of the table.
 *
 * Both are dated from the moment they became unusable: the expiry for the
 * first, `created` for the second (a revoked row is never touched again, so
 * `created` is the only timestamp it has).
 *
 * @return int  Rows deleted in this run.
 */
function myapi_token_purge() {
  $cutoff = REQUEST_TIME - myapi_token_purge_grace();

  $dead = db_or()
    ->condition('refresh_expires_at', $cutoff, '<')
    ->condition(db_and()
      ->condition('revoked', 1)
      ->condition('created', $cutoff, '<')
    );

  return myapi_token_purge_batch('my_api_tokens', $dead);
}

/**
 * Purges password reset token rows that can no longer reset anything.
 *
 * Same two ways of dying as myapi_token_purge() above, and the same dating:
 * expired by its own expires_at, or used (a successful reset, or invalidated
 * by a newer request) and older than the grace period.
 *
 * These rows matter more than the ones above, not less: a reset token is the
 * one credential in this module that can take over an account without a
 * password, and there is no reason for a spent one to outlive the week.
 *
 * @return int  Rows deleted in this run.
 */
function myapi_password_reset_token_purge() {
  $cutoff = REQUEST_TIME - myapi_token_purge_grace();

  $dead = db_or()
    ->condition('expires_at', $cutoff, '<')
    ->condition(db_and()
      ->condition('used', 1)
      ->condition('created', $cutoff, '<')
    );

  return myapi_token_purge_batch('myapi_password_reset_tokens', $dead);
}

/**
 * Revokes every live token of one user.
 *
 * THE ONE PLACE THAT ENDS ALL OF SOMEBODY'S SESSIONS. Two callers today, and
 * they are the two halves of the same event: the reset endpoint
 * (myapi_auth_password_reset_execute(), which reaches it directly) and
 * myapi_user_update() in myapi.module, which catches a password changed from
 * anywhere else on the site — /user/N/edit, `drush upwd`, an administrator
 * resetting an account. Before the hook existed only the first half was
 * covered, so a resident who changed their password from the web because they
 * believed it was compromised left every phone holding the old token logged in.
 *
 * Idempotent by construction: the `revoked = 0` condition makes a second call
 * touch nothing, which is why the two callers can both run on the same reset
 * without the count meaning anything different.
 *
 * @param int $uid  Owner user id.
 *
 * @return int  Rows revoked — 0 when the user had no live session.
 */
function myapi_token_revoke_all_for_user($uid) {
  return (int) db_update('my_api_tokens')
    ->fields(['revoked' => 1])
    ->condition('uid', (int) $uid)
    ->condition('revoked', 0)
    ->execute();
}
