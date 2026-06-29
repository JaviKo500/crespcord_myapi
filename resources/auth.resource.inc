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
