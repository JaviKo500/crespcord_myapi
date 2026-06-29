<?php

/**
 * @file
 * Auth resource for the myapi module.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
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
    myapi_error('Method not allowed', 405);
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
    myapi_error('Invalid credentials', 401);
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
