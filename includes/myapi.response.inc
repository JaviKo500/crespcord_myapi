<?php

/**
 * @file
 * Response helpers for the myapi module.
 */

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
  drupal_add_http_header('Content-Type', 'application/json');
  drupal_add_http_header('Status', $status);

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
  drupal_add_http_header('Content-Type', 'application/json');
  drupal_add_http_header('Status', $status);
  print drupal_json_encode([
    'success'    => FALSE,
    'error_code' => $key,
    'error'      => myapi_t($key, $replacements),
  ]);
  drupal_exit();
}
