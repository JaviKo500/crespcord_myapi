<?php

/**
 * @file
 * Request helpers for the myapi module.
 */

/**
 * Reads and decodes the JSON request body.
 *
 * Result is cached in a static variable because php://input can only be
 * read once per request.
 *
 * @return array|null  Decoded body as associative array, or NULL on failure.
 */
function myapi_request_body() {
  static $body;
  if (!isset($body)) {
    $raw = file_get_contents('php://input');
    $body = !empty($raw) ? json_decode($raw, TRUE) : NULL;
  }
  return $body;
}

/**
 * Returns the current HTTP method in uppercase.
 *
 * @return string  e.g. 'GET', 'POST', 'PUT', 'DELETE'.
 */
function myapi_request_method() {
  return strtoupper($_SERVER['REQUEST_METHOD']);
}

/**
 * Validates that all required fields are present in the request body.
 *
 * Calls myapi_error() with HTTP 422 and exits if any field is missing.
 *
 * @param array $body    Decoded request body.
 * @param array $fields  List of required field names.
 */
function myapi_request_require_fields(array $body, array $fields) {
  foreach ($fields as $field) {
    if (!isset($body[$field])) {
      myapi_error('Missing required field: ' . $field, 422);
    }
  }
}
