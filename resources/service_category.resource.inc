<?php

/**
 * @file
 * Service category resource for the myapi module (SPEC 79).
 *
 * Exposes the taxonomy vocabulary `service_category` — created by SPEC 77 —
 * as an authenticated, read-only collection. Any user with a valid access
 * token may list the categories: no role, condominium or unit is looked at,
 * because the marketplace grid is the same for everybody.
 *
 * Same shape as resources/bank.resource.inc and
 * resources/payment_method.resource.inc (GET only, ?sort=asc|desc over `name`,
 * 200 with an empty list when the vocabulary is missing), with two additions
 * of its own: the terms carry two Field API values (field_category_code and
 * field_category_icon), and `description` is flattened to real plain text with
 * myapi_text_to_plain() instead of being escaped with check_plain(). The why
 * of that divergence is in includes/myapi.text.inc and in
 * docs/service-category.md.
 *
 * Writing categories is not exposed: the operator loads them from
 * admin/structure/taxonomy/service_category (SPEC 77).
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
// For MYAPI_SERVICES_CATEGORY_VOCABULARY: the machine name is never written as
// a literal in this file.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// For myapi_text_to_plain(), which builds `description`.
module_load_include('inc', 'myapi', 'includes/myapi.text');

/**
 * Dispatcher for /api/v1/service-categories.
 *
 * Only GET is allowed; any other method returns 405. The collection is
 * read-only by decision of SPEC 79 — there is no POST, PUT or DELETE, and
 * there is no item route either.
 */
function myapi_service_category_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_service_category_list();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/service-categories.
 *
 * Requires a valid Bearer access token, and nothing else: the catalog is the
 * same for every authenticated user, so no role, condominium or unit is read
 * (SPEC 79). Answers the terms of the `service_category` vocabulary, each with
 * its code, name, plain-text description and icon, ordered alphabetically by
 * `name` — ascending by default, descending with ?sort=desc.
 *
 * A missing vocabulary and an empty one both answer 200 with an empty list,
 * never 404 or 500: whether the vocabulary exists is site configuration and
 * not a resource the client asked for, and reporting it would tell an attacker
 * what is installed. Same rule as /api/v1/banks.
 *
 * The whole catalog is returned on every call — no pagination and no filters.
 * It is a handful of terms that the app needs all at once to paint the grid.
 */
function myapi_service_category_list() {
  // Authentication only: any user with a valid access token may list the
  // categories. The returned token row is not used (the catalog is not
  // per-user).
  myapi_auth_require_access_token();

  // Sort direction over `name`. Any value other than 'asc'/'desc' (absent,
  // empty, uppercase, another field) is silently ignored and defaults to
  // 'asc', the same lax rule used by the bank/payment-method resources — a
  // bad ?sort is never a 422.
  $sort = (isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE))
    ? $_GET['sort']
    : 'asc';

  $vocabulary = taxonomy_vocabulary_machine_name_load(MYAPI_SERVICES_CATEGORY_VOCABULARY);
  if ($vocabulary === FALSE) {
    myapi_respond(['service_categories' => []], 200);
    return;
  }

  $tree = taxonomy_get_tree($vocabulary->vid);
  if (empty($tree)) {
    myapi_respond(['service_categories' => []], 200);
    return;
  }

  // taxonomy_get_tree() returns lightweight term objects with no Field API
  // values; hydrate them in a single batch query to reach field_category_code
  // and field_category_icon. Same pattern as myapi_payment_method_list().
  $tids = array_map(function ($t) {
    return $t->tid;
  }, $tree);
  $terms = entity_load('taxonomy_term', $tids);

  // array_values() because entity_load() answers a tid-keyed map and the
  // response must be a JSON array, not an object.
  $items = array_values(array_map('myapi_service_category_build_item', $terms));

  // Order alphabetically by `name`, ignoring case, regardless of the weight
  // the operator gave the terms in admin/structure/taxonomy and of the
  // tid-keyed order of entity_load(). strcasecmp() compares bytes, so a name
  // starting with an accented vowel sorts after Z; accepted as a known defect
  // for consistency with banks and payment-methods (SPEC 79, "Decisiones").
  usort($items, function ($a, $b) use ($sort) {
    $cmp = strcasecmp($a['name'], $b['name']);
    return $sort === 'desc' ? -$cmp : $cmp;
  });

  myapi_respond(['service_categories' => $items], 200);
}

/**
 * Maps a hydrated taxonomy term object to a service category item.
 *
 * Always the same six keys in the same order — `id`, `code`, `name`,
 * `description`, `icon_id`, `icon_url` — because that order is the one
 * json_encode() prints and the one docs/service-category.md shows. Nothing
 * else of the term travels: a term object carries vid, weight, depth, parents
 * and the whole Field API alongside the values the API exposes.
 *
 * Three decisions of SPEC 79 live here:
 *
 * - A term with an empty field_category_code is NOT excluded, it answers
 *   `code: ""`. The field is required in the form, so a term without a code is
 *   corrupt data, not a business case; hiding it would make a category vanish
 *   from the marketplace with no trace. (This is where the resource parts ways
 *   with payment-methods, which drops terms with no type_method: without that
 *   value a payment method is unusable, while a category is still selectable
 *   by `id`.)
 *
 * - `name` and `code` go through check_plain(), like every other catalog of
 *   the module: they are single-line fields with no rich editor, so there is
 *   no markup to flatten. `description` goes through myapi_text_to_plain()
 *   instead, and therefore travels UNESCAPED — see includes/myapi.text.inc.
 *
 * - `icon_id` and `icon_url` are both NULL or both filled, never one without
 *   the other: the app checks one key and knows about the other.
 *
 * @param object $term
 *   A term object loaded with entity_load() (exposes tid, name, description
 *   and the Field API values field_category_code and field_category_icon).
 *   The lightweight objects of taxonomy_get_tree() carry no field values, so
 *   a term coming straight from the tree would answer code "" and no icon.
 *
 * @return array
 *   Associative array with `id` (int, never NULL), `code` (string), `name`
 *   (string), `description` (string, "" when empty, never NULL), `icon_id`
 *   (int|NULL) and `icon_url` (string|NULL).
 */
function myapi_service_category_build_item($term) {
  $code = isset($term->field_category_code[LANGUAGE_NONE][0]['value'])
    ? $term->field_category_code[LANGUAGE_NONE][0]['value']
    : '';

  // The image value of Field API. Only two of its keys are used; filename,
  // filemime, filesize, width, height, alt and title are not exposed.
  $icon = isset($term->field_category_icon[LANGUAGE_NONE][0])
    ? $term->field_category_icon[LANGUAGE_NONE][0]
    : NULL;

  // Both keys are required for the icon to be answered at all, which is what
  // makes the "both NULL or neither" promise true: a value with a fid but no
  // uri (or the other way round) is treated as no icon, never as half an icon.
  $has_icon = is_array($icon)
    && !empty($icon['fid'])
    && isset($icon['uri'])
    && $icon['uri'] !== '';

  return [
    'id'          => (int) $term->tid,
    'code'        => check_plain($code),
    'name'        => check_plain($term->name),
    'description' => myapi_text_to_plain($term->description),
    'icon_id'     => $has_icon ? (int) $icon['fid'] : NULL,
    'icon_url'    => $has_icon ? file_create_url($icon['uri']) : NULL,
  ];
}
