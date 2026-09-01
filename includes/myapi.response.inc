<?php

/**
 * @file
 * Response helpers for the myapi module.
 */

/**
 * Sends the headers every JSON response of this module carries.
 *
 * ONE FUNCTION AND NOT TWO COPIES: myapi_respond() and myapi_error() answer
 * the same envelope over the same transport, so they send the same headers,
 * and a header added here is added to both.
 *
 * THE CACHE HEADERS ARE NOT DECORATIVE. Both callers below print their body
 * and end the request with drupal_exit(), which never reaches Drupal's page
 * delivery layer — the one that would otherwise have sent the default
 * 'Cache-Control: no-cache, must-revalidate' and 'Expires' of
 * drupal_page_header(). Without these lines the responses leave with NO cache
 * directive at all, and every one of them is personal data: the receipts, the
 * claims and the reservations of one resident. A CDN, a corporate proxy or a
 * carrier proxy is entitled to store an uncontrolled 200 and hand it to the
 * next caller of the same URL, which for this API means handing one
 * neighbour's file to another. Drupal's own page cache is NOT the risk (the
 * drupal_exit() that skips the delivery layer also skips
 * drupal_page_set_cache()); every cache between the app and Drupal is.
 *
 * 'no-store' is the one that matters — it forbids writing the response down at
 * all, where 'no-cache' only forbids reusing it without revalidating. The
 * other three are the belt and braces of HTTP/1.0 intermediaries that ignore
 * Cache-Control.
 *
 * 'nosniff' closes the other end: an error body is JSON, and a browser that
 * decides for itself that it looks like HTML would render it.
 *
 * @param int $status  HTTP status code.
 */
function myapi_response_headers($status) {
  drupal_add_http_header('Content-Type', 'application/json');
  drupal_add_http_header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
  drupal_add_http_header('Pragma', 'no-cache');
  drupal_add_http_header('Expires', '0');
  drupal_add_http_header('X-Content-Type-Options', 'nosniff');
  drupal_add_http_header('Status', $status);
}

/**
 * Sends a successful JSON response and exits.
 *
 * When $message_key is provided, a translated 'message' field is added to the
 * envelope. When it is NULL, the envelope is unchanged ({ success, data }).
 *
 * @param mixed  $data
 * @param int    $status        HTTP status code (default 200).
 * @param string $message_key   Optional catalogue key for a success message.
 * @param array  $replacements  Placeholder map for the message.
 */
function myapi_respond($data, $status = 200, $message_key = NULL, $replacements = []) {
  myapi_response_headers($status);

  $envelope = ['success' => TRUE, 'data' => $data];
  if ($message_key !== NULL) {
    $envelope['message'] = myapi_t($message_key, $replacements);
  }

  print drupal_json_encode($envelope);
  drupal_exit();
}

/**
 * Sends an error JSON response and exits.
 *
 * The first argument is a stable catalogue key (English, snake_case). It is
 * echoed back as 'error_code' (language-independent, for client logic) while
 * 'error' carries the message translated into the resolved language.
 *
 * @param string $key           Catalogue key, e.g. 'invalid_credentials'.
 * @param int    $status        HTTP status code (default 400).
 * @param array  $replacements  Placeholder map, e.g. ['@field' => 'username'].
 */
function myapi_error($key, $status = 400, $replacements = []) {
  myapi_response_headers($status);
  print drupal_json_encode([
    'success'    => FALSE,
    'error_code' => $key,
    'error'      => myapi_t($key, $replacements),
  ]);
  drupal_exit();
}
