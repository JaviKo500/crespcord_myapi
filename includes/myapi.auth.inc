<?php

/**
 * @file
 * HTTP authentication middleware for the myapi module.
 *
 * Extracts and validates access tokens from the Authorization header.
 * Kept separate from myapi.token.inc (token lifecycle) to maintain a clear
 * boundary: this file authenticates HTTP requests, not manages token storage.
 */

/**
 * Extracts the bearer token from the Authorization header.
 *
 * Reads $_SERVER['HTTP_AUTHORIZATION'], matches the pattern
 * "Bearer <token>" (case-insensitive prefix), and returns the token string.
 *
 * @return string|null  The raw token string, or NULL if the header is absent
 *                      or does not match the expected format.
 */
function myapi_auth_parse_bearer() {
  if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    return NULL;
  }

  if (!preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
    return NULL;
  }

  return $matches[1];
}

/**
 * Validates the access token from the Authorization header and returns its row.
 *
 * Halts the request with myapi_error() on any failure; on success returns the
 * matching row from my_api_tokens so the caller can inspect uid, id, etc.
 *
 * Validation order:
 *   1. Bearer token present in Authorization header.
 *   2. Hash found in my_api_tokens with revoked = 0.
 *   3. access_expires_at >= REQUEST_TIME.
 *   4. Owner user exists and is active (status = 1).
 *
 * @return object  The my_api_tokens row (guaranteed valid).
 */
function myapi_auth_require_access_token() {
  $access_token = myapi_auth_parse_bearer();
  if ($access_token === NULL) {
    myapi_error('missing_authorization', 401);
  }

  $hash = myapi_token_hash($access_token);

  $row = db_select('my_api_tokens', 't')
    ->fields('t')
    ->condition('access_token_hash', $hash)
    ->condition('revoked', 0)
    ->execute()
    ->fetchObject();

  if (!$row) {
    myapi_error('invalid_token', 401);
  }

  if ($row->access_expires_at < REQUEST_TIME) {
    myapi_error('invalid_token', 401);
  }

  $account = user_load($row->uid);
  if (!$account || $account->status != 1) {
    myapi_error('invalid_token', 401);
  }

  return $row;
}
