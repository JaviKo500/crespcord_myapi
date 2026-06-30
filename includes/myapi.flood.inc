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
 * Reads the limit and window from Drupal variables, then delegates to
 * flood_is_allowed(). If the limit has been reached, responds with HTTP 429
 * and stops execution via myapi_error().
 *
 * @param string $event       Flood event name (e.g. 'myapi_login_ip').
 * @param string $identifier  Value that identifies the subject (IP or username).
 * @param string $limit_var   Drupal variable name holding the max attempts.
 * @param string $window_var  Drupal variable name holding the window in seconds.
 */
function myapi_flood_check($event, $identifier, $limit_var, $window_var) {
  $limit  = (int) variable_get($limit_var, 0);
  $window = (int) variable_get($window_var, 3600);

  if (!flood_is_allowed($event, $limit, $window, $identifier)) {
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
  $window = (int) variable_get($window_var, 3600);
  flood_register_event($event, $window, $identifier);
}
