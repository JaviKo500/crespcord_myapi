<?php

/**
 * @file
 * Bank resource for the myapi module.
 *
 * Exposes the taxonomy vocabulary `bancos` as an authenticated, read-only
 * collection. Any user with a valid access token may list the banks.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');

/**
 * Dispatcher for /api/v1/banks.
 *
 * Only GET is allowed; any other method returns 405.
 */
function myapi_bank_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_bank_list();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/banks.
 *
 * Requires a valid Bearer access token (any authenticated user). Returns the
 * terms of the `bancos` taxonomy vocabulary ordered alphabetically by `name`,
 * ascending by default, or descending with ?sort=desc. If the vocabulary does
 * not exist (or has no terms), responds 200 with an empty list.
 */
function myapi_bank_list() {
  // Authentication only: any user with a valid access token may list the
  // banks. The returned token row is not used (the catalog is not per-user).
  myapi_auth_require_access_token();

  // Sort direction over `name`. Any value other than 'asc'/'desc' (absent,
  // empty, uppercase, another field) is silently ignored and defaults to
  // 'asc', the same lax rule used by the payment/expense resources.
  $sort = (isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE))
    ? $_GET['sort']
    : 'asc';

  $vocabulary = taxonomy_vocabulary_machine_name_load('bancos');
  if ($vocabulary === FALSE) {
    myapi_respond(['banks' => []], 200);
    return;
  }

  $tree = taxonomy_get_tree($vocabulary->vid);
  $banks = array_map('myapi_bank_build_item', $tree);

  // Order alphabetically by `name`, regardless of taxonomy_get_tree()'s
  // natural weight order.
  usort($banks, function ($a, $b) use ($sort) {
    $cmp = strcasecmp($a['name'], $b['name']);
    return $sort === 'desc' ? -$cmp : $cmp;
  });

  myapi_respond(['banks' => $banks], 200);
}

/**
 * Maps a taxonomy term object to a bank item.
 *
 * @param object $term
 *   A term object from taxonomy_get_tree() (exposes tid, name, description).
 *
 * @return array
 *   Associative array with `id` (int), `name` and `description` (both
 *   sanitized with check_plain(); an empty description yields "").
 */
function myapi_bank_build_item($term) {
  return [
    'id'          => (int) $term->tid,
    'name'        => check_plain($term->name),
    'description' => check_plain($term->description),
  ];
}
