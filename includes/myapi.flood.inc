<?php

/**
 * @file
 * Flood protection helpers for the myapi module.
 *
 * Wraps Drupal 7's Flood API so resource files never call it directly.
 * Thresholds and windows are read from Drupal variables, allowing runtime
 * adjustment via variable_set() without a cache clear.
 */

/**
 * Checks whether a flood event is still allowed for a given identifier.
 *
 * Reads the limit and window from Drupal variables and evaluates them against
 * Drupal's flood_is_allowed(), without touching HTTP output. Callers that need
 * to stop execution with a 429 response should use myapi_flood_check() instead;
 * this variant exists for callers (e.g. the password reset web page) that need
 * to react to the flood limit themselves.
 *
 * @param string $event       Flood event name (e.g. 'myapi_login_ip').
 * @param string $identifier  Value that identifies the subject (IP or username).
 * @param string $limit_var   Drupal variable name holding the max attempts.
 * @param string $window_var  Drupal variable name holding the window in seconds.
 *
 * @return bool  TRUE if the event is still allowed, FALSE if the limit was reached.
 */
function myapi_flood_is_allowed($event, $identifier, $limit_var, $window_var) {
  // Default values mirror the spec. variable_get() uses them when the variable
  // has never been set in the database via variable_set().
  static $limit_defaults = [
    'myapi_flood_login_user_limit'       => 5,
    'myapi_flood_login_ip_limit'         => 20,
    'myapi_flood_refresh_ip_limit'       => 10,
    'myapi_flood_logout_ip_limit'        => 20,
    'myapi_flood_forgot_ip_limit'        => 10,
    'myapi_flood_forgot_identifier_limit' => 3,
    'myapi_flood_reset_ip_limit'         => 10,
  ];
  static $window_defaults = [
    'myapi_flood_login_user_window'       => 3600,
    'myapi_flood_login_ip_window'         => 3600,
    'myapi_flood_refresh_ip_window'       => 900,
    'myapi_flood_logout_ip_window'        => 900,
    'myapi_flood_forgot_ip_window'        => 3600,
    'myapi_flood_forgot_identifier_window' => 3600,
    'myapi_flood_reset_ip_window'         => 900,
  ];

  $default_limit  = isset($limit_defaults[$limit_var])   ? $limit_defaults[$limit_var]   : 10;
  $default_window = isset($window_defaults[$window_var]) ? $window_defaults[$window_var] : 3600;

  $limit  = (int) variable_get($limit_var,  $default_limit);
  $window = (int) variable_get($window_var, $default_window);

  return flood_is_allowed($event, $limit, $window, $identifier);
}

/**
 * Checks whether a flood event is still allowed for a given identifier.
 *
 * Delegates to myapi_flood_is_allowed(). If the limit has been reached,
 * responds with HTTP 429 and stops execution via myapi_error().
 *
 * @param string $event       Flood event name (e.g. 'myapi_login_ip').
 * @param string $identifier  Value that identifies the subject (IP or username).
 * @param string $limit_var   Drupal variable name holding the max attempts.
 * @param string $window_var  Drupal variable name holding the window in seconds.
 */
function myapi_flood_check($event, $identifier, $limit_var, $window_var) {
  if (!myapi_flood_is_allowed($event, $identifier, $limit_var, $window_var)) {
    myapi_error('too_many_attempts', 429);
  }
}

/**
 * Registers a failed attempt for a flood event.
 *
 * Reads the window from a Drupal variable and calls flood_register_event().
 * Call this after a failed authentication attempt so the counter advances.
 *
 * @param string $event       Flood event name (e.g. 'myapi_login_ip').
 * @param string $identifier  Value that identifies the subject (IP or username).
 * @param string $window_var  Drupal variable name holding the window in seconds.
 */
function myapi_flood_register($event, $identifier, $window_var) {
  static $window_defaults = [
    'myapi_flood_login_user_window'       => 3600,
    'myapi_flood_login_ip_window'         => 3600,
    'myapi_flood_refresh_ip_window'       => 900,
    'myapi_flood_logout_ip_window'        => 900,
    'myapi_flood_forgot_ip_window'        => 3600,
    'myapi_flood_forgot_identifier_window' => 3600,
    'myapi_flood_reset_ip_window'         => 900,
  ];

  $default_window = isset($window_defaults[$window_var]) ? $window_defaults[$window_var] : 3600;
  $window = (int) variable_get($window_var, $default_window);
  flood_register_event($event, $window, $identifier);
}
