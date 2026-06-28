<?php

/**
 * @file
 * Ping resource for the myapi module.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');

/**
 * Dispatcher for GET /api/v1/ping.
 */
function myapi_ping_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_ping_get();
  }
  else {
    myapi_error('Method not allowed.', 405);
  }
}

/**
 * Handles GET /api/v1/ping.
 */
function myapi_ping_get() {
  myapi_respond(['pong' => TRUE]);
}
