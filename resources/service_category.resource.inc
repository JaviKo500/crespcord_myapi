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
// For myapi_provider_apply_active_conditions(): the SQL half of "an active
// provider", shared with the marketplace listing (SPEC 83).
module_load_include('inc', 'myapi', 'includes/myapi.provider_query');

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
 * With ?with_counts=1 each item carries a seventh key, `providers_count`. The
 * count is opt-in because the app paints the grid far more often than it needs
 * the number, and paying the aggregate query on every request to throw it away
 * is pure waste.
 *
 * A missing vocabulary and an empty one both answer 200 with an empty list,
 * never 404 or 500: whether the vocabulary exists is site configuration and
 * not a resource the client asked for, and reporting it would tell an attacker
 * what is installed. Same rule as /api/v1/banks. Neither case runs the count
 * query — there is no category to count for.
 *
 * Pagination is OPT-IN (SPEC 118): without ?page and without ?limit the whole
 * catalog travels and there is no `pagination` key, exactly as SPEC 79 shipped
 * it, so an app built against that contract keeps seeing every category. The
 * block appears — and the list is sliced — only for a client that asked for a
 * page, and then it appears always, degraded cases included.
 *
 * ?search= filters by `name` and `description`, accent- and case-insensitively
 * (SPEC 119), and TURNS PAGINATION OFF: a search answers every match, with no
 * slice and no `pagination` block, even when ?page and ?limit travel with it.
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

  // The seventh key is opt-in and the flag is strict: exactly '1' turns it on,
  // and '0', 'true', 'yes', an empty value or an array leave the response at
  // its six keys with a 200 — never a 422. Same shape as ?unread=1 in
  // notification.resource.inc, so the module writes URL booleans one way only.
  $with_counts = isset($_GET['with_counts']) && $_GET['with_counts'] === '1';

  // Pagination is opt-in and the PRESENCE of either parameter is what turns it
  // on — not their validity. ?page=abc pages from 1, it does not fall back to
  // the whole catalogue: a client that asked for a page always gets a page and
  // a `pagination` block, and a client that asked for nothing keeps the SPEC 79
  // response byte for byte. Same lax parse as the marketplace listing: an
  // invalid page is 1, an invalid limit is 20, over 50 is cut to 50, and none
  // of it is a 422.
  // The needle, folded once for the whole request. Absent, non-scalar or
  // blank (spaces only) all mean "no search" — a search box the user cleared
  // must answer the catalogue, not an empty list.
  $search = isset($_GET['search']) && is_scalar($_GET['search'])
    ? myapi_text_fold(trim((string) $_GET['search']))
    : '';
  $searching = $search !== '';

  // SEARCHING OVERRULES PAGINATING, by decision of SPEC 119: a result set the
  // user is reading as "what matched" is answered whole, and ?page/?limit
  // travelling alongside a ?search are ignored — block included, so the app
  // cannot mistake a complete result for the first page of one.
  $paginate = (isset($_GET['page']) || isset($_GET['limit'])) && !$searching;

  $page = myapi_parse_page_param();
  $limit = myapi_parse_limit_param();

  $vocabulary = taxonomy_vocabulary_machine_name_load(MYAPI_SERVICES_CATEGORY_VOCABULARY);
  if ($vocabulary === FALSE) {
    myapi_respond(myapi_service_category_response([], $paginate, 0, $page, $limit), 200);
    return;
  }

  $tree = taxonomy_get_tree($vocabulary->vid);
  if (empty($tree)) {
    myapi_respond(myapi_service_category_response([], $paginate, 0, $page, $limit), 200);
    return;
  }

  // taxonomy_get_tree() returns lightweight term objects with no Field API
  // values; hydrate them in a single batch query to reach field_category_code
  // and field_category_icon. Same pattern as myapi_payment_method_list().
  $tids = array_map(function ($t) {
    return $t->tid;
  }, $tree);
  $terms = entity_load('taxonomy_term', $tids);

  // The filter runs over the TERMS and not over the built items, so it reads
  // the name the operator wrote rather than its check_plain() escaping: a
  // category called "Cortes & instalaciones" is findable by "&", which it
  // would not be against the `&amp;` that travels in the response.
  if ($searching) {
    $terms = array_filter($terms, function ($term) use ($search) {
      return myapi_service_category_matches($term, $search);
    });
  }

  // Keyed by (int) tid whatever entity_load() used for its own keys, because
  // the count pass below reaches back for the term of an already built item.
  $by_tid = [];
  foreach ($terms as $term) {
    $by_tid[(int) $term->tid] = $term;
  }

  // array_values() because entity_load() answers a tid-keyed map and the
  // response must be a JSON array, not an object. Counts are NOT resolved here
  // any more: they are paid for after the slice, over the page alone.
  $items = array_values(array_map(function ($term) {
    return myapi_service_category_build_item($term);
  }, $terms));

  // Order alphabetically by `name`, ignoring case, regardless of the weight
  // the operator gave the terms in admin/structure/taxonomy and of the
  // tid-keyed order of entity_load(). strcasecmp() compares bytes, so a name
  // starting with an accented vowel sorts after Z; accepted as a known defect
  // for consistency with banks and payment-methods (SPEC 79, "Decisiones").
  usort($items, function ($a, $b) use ($sort) {
    $cmp = strcasecmp($a['name'], $b['name']);
    return $sort === 'desc' ? -$cmp : $cmp;
  });

  // `total` is the size of the whole catalogue, which is also the set that was
  // just ordered: the slice is taken AFTER the sort, so page 2 is the second
  // alphabetical page and not the second chunk of the tid order.
  $total = count($items);
  if ($paginate) {
    $items = array_slice($items, ($page - 1) * $limit, $limit);
  }

  // The counts are the one part of the response the slice actually makes
  // cheaper: still ONE grouped query, but over the tids being answered instead
  // of the whole vocabulary. The item is rebuilt through the same builder
  // rather than having the key appended here, so `providers_count` keeps
  // travelling last and the six other keys keep their order.
  if ($with_counts) {
    $counts = myapi_service_category_provider_counts(array_column($items, 'id'));
    $items = array_map(function ($item) use ($by_tid, $counts) {
      $tid = (int) $item['id'];

      return myapi_service_category_build_item($by_tid[$tid], isset($counts[$tid]) ? $counts[$tid] : 0);
    }, $items);
  }

  myapi_respond(myapi_service_category_response($items, $paginate, $total, $page, $limit), 200);
}

/**
 * Whether a term matches the search needle (SPEC 119).
 *
 * TWO FIELDS AND NOT THREE: `name` and `description`, the two the operator
 * writes for a human to read. `code` is deliberately NOT searched — it exists
 * so the app can key its icons on something stable, and the one thing that
 * made searching it tempting (typing "plomeria" and finding "Plomería") is
 * already covered by the folding, so matching it would only add hits nobody
 * asked for.
 *
 * The description is compared AS IT IS ANSWERED — flattened with
 * myapi_text_to_plain() — so a word that is only in the stored markup (a
 * class name, an href) never matches something the user cannot see.
 *
 * A plain substring test, not a word or prefix one: "eria" finds "Plomería"
 * and "Cerrajería". With 64 short strings this is a handful of microseconds
 * and no query at all.
 *
 * @param object $term
 *   A hydrated term object.
 * @param string $needle
 *   The needle, ALREADY folded by myapi_text_fold() and non-empty.
 *
 * @return bool
 *   TRUE when the needle is inside the folded name or the folded description.
 */
function myapi_service_category_matches($term, $needle) {
  $name = myapi_text_fold(isset($term->name) ? $term->name : '');
  if (strpos($name, $needle) !== FALSE) {
    return TRUE;
  }

  $description = myapi_text_fold(myapi_text_to_plain(isset($term->description) ? $term->description : ''));

  return strpos($description, $needle) !== FALSE;
}

/**
 * Assembles the response body, with or without the pagination block (SPEC 118).
 *
 * ONE function and four callers — the two degraded exits and the two ends of
 * the normal path — because the promise the app is given is precisely that the
 * shape does not depend on which of them answered: ask for a page and the
 * block is there, over a missing vocabulary too, with `total` and
 * `total_pages` at 0. A client that opted in never has to parse an optional
 * key.
 *
 * `page` is echoed AS ASKED FOR, not clamped to the last page: a page beyond
 * the end is an empty list with a 200, the same rule as /api/v1/providers, and
 * a page silently rewritten to the last one would hide the client's bug.
 *
 * @param array $items
 *   The categories already ordered and, when paginating, already sliced.
 * @param bool $paginate
 *   Whether the request carried ?page or ?limit at all.
 * @param int $total
 *   Size of the whole catalogue, never of the page.
 * @param int $page
 *   1-based page asked for, already defaulted.
 * @param int $limit
 *   Items per page, already clamped to [1, 50].
 *
 * @return array
 *   `service_categories` alone, or `service_categories` plus `pagination` in
 *   that order.
 */
function myapi_service_category_response(array $items, $paginate, $total, $page, $limit) {
  $data = ['service_categories' => $items];

  if ($paginate) {
    $data['pagination'] = [
      'total'       => (int) $total,
      'page'        => (int) $page,
      'limit'       => (int) $limit,
      // 0 and not 1 when there is nothing to page through, same as providers.
      'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
    ];
  }

  return $data;
}

/**
 * Counts the ACTIVE providers of each of the given categories (SPEC 79).
 *
 * One grouped query for the whole catalogue, never one per category: with the
 * tids already in memory, a GROUP BY answers every count at once. This is what
 * makes `?with_counts=1` affordable enough to be an option rather than a spec
 * of its own.
 *
 * WHAT COUNTS AS ACTIVE is not decided here. The rule — published AND licence
 * not expired — is the one myapi_services_provider_is_active() states in PHP
 * (SPEC 77), and it cannot be called from a WHERE clause: it is a pure
 * function over loaded values, and loading every provider node just to filter
 * it in PHP is exactly what the COUNT() avoids. So the rule has a second half
 * written in SQL, and since SPEC 83 that half lives in ONE place —
 * myapi_provider_apply_active_conditions(), in
 * includes/myapi.provider_query.inc — which this query calls and the
 * marketplace listing calls too. Neither restates it.
 *
 * THE TWO HALVES STILL MOVE TOGETHER: a spec that changes what "active" means
 * has to change the PHP function and that include, and no third copy may be
 * written. Both halves (unpublished, expired) are pinned by tests here so a
 * one-sided change breaks the suite instead of silently inflating the counts.
 *
 * A provider that is not active is not counted anywhere; a category with no
 * active provider answers 0 and is never dropped from the catalogue.
 *
 * @param array $tids
 *   The term ids to count for — the ones already loaded and about to be
 *   answered. An empty list answers an empty map with no query at all.
 *
 * @return array
 *   Map of (int) tid => (int) count, with ONE ENTRY PER REQUESTED TID: the
 *   categories with no active provider are filled in with 0 here, because the
 *   GROUP BY only answers the ones that have at least one.
 */
function myapi_service_category_provider_counts(array $tids) {
  if (empty($tids)) {
    return [];
  }

  // Every requested tid starts at 0, in the order it was asked for; the query
  // then overwrites the ones that have providers.
  $counts = [];
  foreach ($tids as $tid) {
    $counts[(int) $tid] = 0;
  }

  $query = db_select('node', 'n');
  $query->innerJoin(
    'field_data_field_categories',
    'c',
    "c.entity_type = 'node' AND c.entity_id = n.nid AND c.deleted = 0"
  );
  // The licence join and the two active conditions, in one call: same SQL as
  // before, written once and shared with GET /api/v1/providers (SPEC 83).
  myapi_provider_apply_active_conditions($query, 'n');
  $query->addField('c', 'field_categories_tid', 'tid');
  // COUNT(DISTINCT n.nid) and not COUNT(*): field_categories has unlimited
  // cardinality, so a provider carrying the same category in two deltas would
  // otherwise be counted twice.
  $query->addExpression('COUNT(DISTINCT n.nid)', 'total');
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  // Only the categories being answered: the query counts, it does not discover.
  $query->condition('c.field_categories_tid', array_keys($counts), 'IN');
  $query->groupBy('c.field_categories_tid');

  foreach ($query->execute() as $row) {
    $counts[(int) $row->tid] = (int) $row->total;
  }

  return $counts;
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
 * The optional `providers_count` is the one key that may or may not be there,
 * and it is appended LAST so the six above always print in the same order.
 *
 * @param object $term
 *   A term object loaded with entity_load() (exposes tid, name, description
 *   and the Field API values field_category_code and field_category_icon).
 *   The lightweight objects of taxonomy_get_tree() carry no field values, so
 *   a term coming straight from the tree would answer code "" and no icon.
 * @param int|null $providers_count
 *   The number of active providers of this category, or NULL when the request
 *   did not ask for it (no ?with_counts=1). NULL means the key is ABSENT from
 *   the item — it never travels as null. A count of 0 is a real answer and
 *   does travel: "this category has no provider yet" is information.
 *
 * @return array
 *   Associative array with `id` (int, never NULL), `code` (string), `name`
 *   (string), `description` (string, "" when empty, never NULL), `icon_id`
 *   (int|NULL), `icon_url` (string|NULL) and, only when asked for,
 *   `providers_count` (int, never NULL).
 */
function myapi_service_category_build_item($term, $providers_count = NULL) {
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

  $item = [
    'id'          => (int) $term->tid,
    'code'        => check_plain($code),
    'name'        => check_plain($term->name),
    'description' => myapi_text_to_plain($term->description),
    'icon_id'     => $has_icon ? (int) $icon['fid'] : NULL,
    'icon_url'    => $has_icon ? file_create_url($icon['uri']) : NULL,
  ];

  if ($providers_count !== NULL) {
    $item['providers_count'] = (int) $providers_count;
  }

  return $item;
}
