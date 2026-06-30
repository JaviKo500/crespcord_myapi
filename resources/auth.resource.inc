<?php

/**
 * @file
 * Auth resource for the myapi module.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');

/**
 * Dispatcher for /api/v1/auth/login.
 *
 * Routes by HTTP method. Only POST is supported.
 */
function myapi_auth_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_auth_login();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles POST /api/v1/auth/login.
 *
 * Authenticates by username + password against dr_users and, on success,
 * issues an opaque access/refresh token pair and returns them together with
 * the basic user data.
 */
function myapi_auth_login() {
  $body = myapi_request_body();
  myapi_request_require_strings((array) $body, ['username', 'password']);

  $username = $body['username'];
  $password = $body['password'];

  // user_check_password() lives in includes/password.inc, which Drupal 7 does
  // not load on every request; pull it in before verifying credentials.
  require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');

  // Load the account. Any failure below returns the same generic 401 so the
  // response never reveals whether a given username exists.
  $account = user_load_by_name($username);
  if (!$account || $account->uid == 0 || $account->status == 0
      || !user_check_password($password, $account)) {
    myapi_error('invalid_credentials', 401);
  }

  // Issue and persist a fresh token pair.
  $access = myapi_token_generate_access();
  $refresh = myapi_token_generate_refresh();
  myapi_token_persist($account->uid, $access, $refresh);

  // Map $account->roles (rid => name) to a list of {name, uid} objects, where
  // uid is the role id (rid) per the client requirement.
  $roles = [];
  foreach ($account->roles as $rid => $name) {
    $roles[] = ['name' => $name, 'uid' => (int) $rid];
  }

  $user = [
    'uid'     => (int) $account->uid,
    'name'    => $account->name,
    'mail'    => $account->mail,
    'picture' => NULL,
    'roles'   => $roles,
  ];

  myapi_respond([
    'access_token'  => $access,
    'refresh_token' => $refresh,
    'expires_in'    => myapi_token_access_ttl(),
    'user'          => $user,
  ], 200);
}

/**
 * Dispatcher for /api/v1/auth/refresh.
 *
 * Routes by HTTP method. Only POST is supported.
 */
function myapi_auth_refresh_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_auth_refresh();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles POST /api/v1/auth/refresh.
 *
 * Validates the incoming refresh token, revokes it, issues a new token pair,
 * and returns the new tokens together with the basic user data.
 */
function myapi_auth_refresh() {
  $body = myapi_request_body();
  myapi_request_require_fields((array) $body, ['refresh_token']);

  $refresh_token = $body['refresh_token'];
  $hash = myapi_token_hash($refresh_token);

  $row = db_select('my_api_tokens', 't')
    ->fields('t')
    ->condition('refresh_token_hash', $hash)
    ->condition('revoked', 0)
    ->execute()
    ->fetchObject();

  if (!$row) {
    myapi_error('invalid_token', 401);
  }

  if ($row->refresh_expires_at < REQUEST_TIME) {
    myapi_error('token_expired', 401);
  }

  $account = user_load($row->uid);
  if (!$account || $account->status != 1) {
    myapi_error('invalid_token', 401);
  }

  db_update('my_api_tokens')
    ->fields(['revoked' => 1])
    ->condition('id', $row->id)
    ->execute();

  $access  = myapi_token_generate_access();
  $refresh = myapi_token_generate_refresh();
  myapi_token_persist($account->uid, $access, $refresh);

  $roles = [];
  foreach ($account->roles as $rid => $name) {
    $roles[] = ['name' => $name, 'uid' => (int) $rid];
  }

  $user = [
    'uid'     => (int) $account->uid,
    'name'    => $account->name,
    'mail'    => $account->mail,
    'picture' => NULL,
    'roles'   => $roles,
  ];

  myapi_respond([
    'access_token'  => $access,
    'refresh_token' => $refresh,
    'expires_in'    => myapi_token_access_ttl(),
    'user'          => $user,
  ], 200);
}
