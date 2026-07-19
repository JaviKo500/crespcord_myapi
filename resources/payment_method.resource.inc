<?php

/**
 * @file
 * Payment method resource for the myapi module.
 *
 * Exposes the taxonomy vocabulary `metodos_pago` as an authenticated,
 * read-only collection. Any user with a valid access token may list the
 * payment methods. Each term is enriched with the `field_tipo_pago` field
 * (exposed as `type_method`); terms without that value are excluded.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');

/**
 * Dispatcher for /api/v1/payment-methods.
 *
 * Only GET is allowed; any other method returns 405.
 */
function myapi_payment_method_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_payment_method_list();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/payment-methods.
 *
 * Requires a valid Bearer access token (any authenticated user). Returns the
 * terms of the `metodos_pago` taxonomy vocabulary, each enriched with
 * `field_tipo_pago` (exposed as `type_method`), ordered alphabetically by
 * `name`, ascending by default or descending with ?sort=desc. Terms without a
 * `type_method` value are excluded. If the vocabulary does not exist (or has
 * no usable terms), responds 200 with an empty list.
 */
function myapi_payment_method_list() {
  // Authentication only: any user with a valid access token may list the
  // payment methods. The returned token row is not used (the catalog is not
  // per-user).
  myapi_auth_require_access_token();

  // Sort direction over `name`. Any value other than 'asc'/'desc' (absent,
  // empty, uppercase, another field) is silently ignored and defaults to
  // 'asc', the same lax rule used by the bank/payment/expense resources.
  $sort = (isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE))
    ? $_GET['sort']
    : 'asc';

  $vocabulary = taxonomy_vocabulary_machine_name_load('metodos_pago');
  if ($vocabulary === FALSE) {
    myapi_respond(['payment_methods' => []], 200);
    return;
  }

  $tree = taxonomy_get_tree($vocabulary->vid);
  if (empty($tree)) {
    myapi_respond(['payment_methods' => []], 200);
    return;
  }

  // taxonomy_get_tree() returns lightweight term objects without Field API
  // values; hydrate them in a single batch query to access field_tipo_pago.
  $tids = array_map(function ($t) {
    return $t->tid;
  }, $tree);
  $terms = entity_load('taxonomy_term', $tids);

  // Build each item and drop the terms without a type_method (build_item
  // returns NULL for those, array_filter removes them).
  $items = array_map('myapi_payment_method_build_item', $terms);
  $items = array_values(array_filter($items));

  // Order alphabetically by `name`, regardless of the natural order of
  // taxonomy_get_tree() or the tid-keyed order of entity_load().
  usort($items, function ($a, $b) use ($sort) {
    $cmp = strcasecmp($a['name'], $b['name']);
    return $sort === 'desc' ? -$cmp : $cmp;
  });

  myapi_respond(['payment_methods' => $items], 200);
}

/**
 * Maps a hydrated taxonomy term object to a payment method item.
 *
 * @param object $term
 *   A term object loaded with entity_load() (exposes tid, name, description
 *   and the Field API value field_tipo_pago).
 *
 * @return array|null
 *   Associative array with `id` (int), `name`, `type_method` and `description`
 *   (the three strings sanitized with check_plain(); an empty description
 *   yields ""). Returns NULL when the term has no field_tipo_pago value, so it
 *   is excluded from the collection (a method without type_method is not
 *   usable to register a payment).
 */
function myapi_payment_method_build_item($term) {
  $type = isset($term->field_tipo_pago[LANGUAGE_NONE][0]['value'])
    ? $term->field_tipo_pago[LANGUAGE_NONE][0]['value']
    : '';

  // Without type_method the method is not usable in a payment: exclude it.
  if (trim($type) === '') {
    return NULL;
  }

  return [
    'id'          => (int) $term->tid,
    'name'        => check_plain($term->name),
    'type_method' => check_plain($type),
    'description' => check_plain($term->description),
  ];
}
