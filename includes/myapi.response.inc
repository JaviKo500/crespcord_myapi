<?php

/**
 * @file
 * Response helpers for the myapi module.
 */

/**
 * Sends a successful JSON response and exits.
 *
 * @param mixed $data
 * @param int   $status  HTTP status code (default 200).
 */
function myapi_respond($data, $status = 200) {
  drupal_add_http_header('Content-Type', 'application/json');
  drupal_add_http_header('Status', $status);
  print drupal_json_encode(['success' => TRUE, 'data' => $data]);
  drupal_exit();
}

/**
 * Sends an error JSON response and exits.
 *
 * @param string $message  Human-readable error description.
 * @param int    $status   HTTP status code (default 400).
 */
function myapi_error($message, $status = 400) {
  drupal_add_http_header('Content-Type', 'application/json');
  drupal_add_http_header('Status', $status);
  print drupal_json_encode(['success' => FALSE, 'error' => $message]);
  drupal_exit();
}
