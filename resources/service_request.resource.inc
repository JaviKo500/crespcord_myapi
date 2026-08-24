<?php

/**
 * @file
 * Service request resource for the myapi module (SPECS 88, 89, 90, 91, 95
 * and 96).
 *
 * Four routes, authenticated throughout:
 *
 *   - GET /api/v1/service-requests — the paginated listing of the service
 *     requests the reader created (SPEC 88).
 *   - POST /api/v1/service-requests — creates a service request for the
 *     authenticated resident's own unit, with the condominium derived
 *     server-side, an optional direct award to an eligible provider, and
 *     optional images/attachment saved all-or-nothing (SPEC 90).
 *   - GET /api/v1/service-requests/% — the detail of one request: the listing's
 *     eleven keys plus the condominium, the requester, the images, the
 *     attachment, closed_at and the offers (SPEC 89).
 *   - POST /api/v1/service-requests/% — the requester corrects the title, the
 *     description, the desired date and the files of their own request, while
 *     it is still 'open' or 'direct' and nobody has bid (SPEC 96).
 *   - PUT /api/v1/service-requests/%/cancel — the requester cancels, which
 *     writes the status and one entry on the timeline (SPEC 95).
 *   - GET /api/v1/service-requests/%/files/% — the bytes of one image or of the
 *     attachment, both private:// since SPEC 77 (SPEC 89).
 *
 * The first endpoints of the 'service_request' bundle. SPEC 77, 86 and 87 built
 * its schema — the fields, the status catalogue, the 'direct' status — without
 * a single route; this file is where the bundle finally gets them.
 *
 * TWO READERS, TWO ANSWERS, ONE ACCESS RULE. The detail is the first endpoint
 * of this module whose response depends on WHO ASKS: the resident who created
 * the request reads it whole with every offer; a provider of its category who
 * may still bid reads the same eighteen keys with `unit` null and `offers`
 * trimmed to their own. Both routes above decide with the SAME function,
 * myapi_service_request_viewer(), and neither reimplements a condition of it —
 * a copy would drift into a 403 on the detail that still serves the photos.
 *
 * THE SCOPE IS field_requester = uid, AND NOTHING ELSE. Not the condominium
 * (unlike GET /api/v1/claims, where a claim can be public inside one), not
 * node.uid (the technical author, which today is the administrator who loaded
 * the request from the back office), and NOT the reader's roles: a user who is
 * also a 'proveedor' sees here what they created as a resident, complete, and
 * nothing more. The provider's side of the marketplace — "the requests I may
 * attend" — is another endpoint with another scope, owned by
 * myapi_provider_role_visible_request_ids().
 *
 * FOR THAT REASON NO QUERY IN THIS FILE CARRIES ->addTag('node_access'), and
 * that is a decision, not an omission: myapi_query_node_access_alter() runs
 * myapi_provider_role_alter_node_query() over every tagged query, and that
 * alter is a whitelist by the provider's categories. A resident holding the
 * 'proveedor' role would stop seeing their own requests as soon as they were
 * not of a category they attend — silently, with a shorter list and no error.
 * The same docblock also states that no query of this module carries the tag;
 * this file keeps that sentence true instead of turning it into an exception.
 *
 * CREATION LIVES ON THE COLLECTION ONLY (SPEC 90): POST /api/v1/service-requests
 * creates, never the item path — creating is always about the collection, the
 * same criterion SPEC 66 gave claims. THE ITEM PATH IS WHERE THE REQUEST ITSELF
 * IS WRITTEN: POST /api/v1/service-requests/% edits it (SPEC 96), POST and not
 * PUT because PHP fills neither $_POST nor $_FILES on a PUT and an edit carries
 * files — the same reason POST /api/v1/claims/% took in SPEC 67. Cancelling has
 * its own named route (SPEC 95), because it is an action on the resource and not
 * the writing of the resource. PUT and DELETE answer 405 on every route of this
 * file. Closing, awarding and offering are all still another spec —
 * field_assigned_offer is never written by this file, and
 * field_assigned_provider is written only at birth, by
 * myapi_service_request_build_node(), never afterwards.
 *
 * Like the other read resources of this module, the queries read the
 * field_data_* tables directly instead of going through node_load(): the
 * listing answers eleven keys per request, and node_load() of twenty would
 * fire the entity load hooks and drag a dozen fields along for them. The usual
 * trade-off, documented in docs/service-request.md: the queries assume the
 * current field storage.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
// For MYAPI_SERVICES_REQUEST_TYPE, MYAPI_SERVICES_OFFER_TYPE and
// myapi_services_request_statuses(): neither a bundle machine name nor a status
// key is ever written as a literal in this file.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// SPEC 89, the detail: the four questions the provider's half of
// myapi_service_request_viewer() hangs on — which providers the reader
// operates, their categories, whether any of them is active, and whether one of
// them already offered.
module_load_include('inc', 'myapi', 'includes/myapi.provider_role');
// SPEC 89: myapi_user_display_names(), the "nombre apellidos" / users.name rule
// this resource shares with GET /api/v1/units instead of copying.
module_load_include('inc', 'myapi', 'includes/myapi.user');
// SPEC 90: myapi_unit_related_nids() to validate 'unit_id' belongs to the
// reader, and myapi_units_condominium_nids() to derive the condominium from
// it — the same table myapi_condominium_related_nids() reads, but for one
// unit instead of every unit of the reader.
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
// SPEC 90: myapi_node_files_save() / myapi_node_files_delete(), the
// all-or-nothing upload helpers this resource shares with claims (see
// includes/myapi.node_files.inc — extracted from claim.resource.inc by this
// same spec).
module_load_include('inc', 'myapi', 'includes/myapi.node_files');

/**
 * Dispatcher for /api/v1/service-requests (SPECS 88 and 90).
 *
 * GET lists (SPEC 88); POST with no id creates (SPEC 90) — creation is always
 * on the collection, never on an item, the same criterion SPEC 66 gave claims.
 * Editing and cancelling are writes on ONE request, so they live on the item
 * routes and not here (SPECS 96 and 95); closing and awarding are still out of
 * scope. PUT and DELETE answer 405 on this route whatever they carry.
 *
 * The method is checked BEFORE any authentication, the same criterion every
 * other dispatcher of this module follows: an unsupported method answers 405
 * without a token, because the method is wrong whoever is asking.
 */
function myapi_service_request_dispatch() {
  $method = myapi_request_method();

  if ($method === 'GET') {
    myapi_service_request_list();
  }
  elseif ($method === 'POST') {
    myapi_service_request_create();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * GET /api/v1/service-requests — the reader's own service requests (SPEC 88).
 *
 * Orchestration only; every decision it makes lives in one of the helpers
 * below. In order: authenticate, refuse a malformed category, parse the query
 * string, count, fetch the page, count the offers of that page, and serialise.
 *
 * REQUIRES A VALID BEARER TOKEN AND NOTHING ELSE. No role is read and no
 * condominium is resolved: the scope is field_requester = uid, so a reader with
 * no requests gets 200 with an empty list and total 0 — never a 403. "You have
 * nothing" is not "you may not", the same criterion as GET /api/v1/bulletins
 * and GET /api/v1/claims. A user holding the 'proveedor' role reads exactly
 * what they created as a resident, whole; see the @file docblock for why the
 * query is not tagged.
 *
 * THE PARSING IS LAX EVERYWHERE EXCEPT ONE PARAMETER. An invalid page, limit,
 * sort, status or date bound falls back to its default in silence, never a 422: a listing
 * that breaks the whole screen because '?page=abc' arrived is punishing the
 * client for a decorative parameter, and the 422 is reserved for write bodies,
 * where a bad value corrupts something. The two exceptions are '?category_id'
 * and '?unit_id', which answer 422 for the reasons written in their own
 * parsers — and both are checked FIRST, before the pagination and before the
 * count, so a malformed request costs no query at all.
 *
 * Exactly THREE listing queries reach the database per request — count, page,
 * offer counts — plus the one the token costs. None of them grows with the
 * number of rows.
 */
function myapi_service_request_list() {
  // 1. Authentication. Answers 401 missing_authorization / invalid_token on its
  // own and never returns on failure; on success it hands back the token row,
  // whose uid is the reader — and the whole scope of this endpoint.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 2. The only two parameters that can be WRONG rather than merely unknown,
  // and they go before everything else on purpose: a 422 must not cost a query.
  $category_id = myapi_service_request_parse_category_id();
  $unit_id = myapi_service_request_parse_unit_id();

  // 3. Pagination and sort. Same idiom as claims and payments: garbage falls
  // back to the default, and '-1' means "everything on one page" (SPEC 15).
  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && $_GET['limit'] === '-1'
    ? -1
    : (isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
      ? max(1, min(50, (int) $_GET['limit']))
      : 20);

  if ($limit === -1) {
    $page = 1;
  }

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  // 4. The filters, all optional and composing with AND. '?status' is
  // validated against the catalogue and never against a list written here, and
  // the date range goes through the shared parser rather than a fourth copy of
  // it — see myapi_service_request_parse_created_range(). '?unit_id' is already
  // parsed above, with '?category_id', because both can answer 422.
  $created = myapi_service_request_parse_created_range();

  $filters = [
    'status'       => myapi_service_request_parse_status(),
    'category_id'  => $category_id,
    'unit_id'      => $unit_id,
    'created_from' => $created['from'],
    'created_to'   => $created['to'],
  ];

  // 5. Count first: the pagination block describes the FILTERED set, not the
  // page. total_pages is 0 and not 1 when there is nothing to page through, and
  // a page past the last one simply fetches no rows and answers 200 with an
  // empty list.
  $total = myapi_service_request_count($uid, $filters);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  // 6. The page, and then the offers of that whole page in ONE query — never
  // one per request.
  $rows = myapi_service_request_fetch($uid, $filters, $page, $limit, $sort);
  $nids = [];
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
  }
  $offer_counts = myapi_service_request_offer_counts_by_nid($nids);

  $service_requests = array_map(function ($row) use ($offer_counts) {
    return myapi_service_request_build_item($row, $offer_counts);
  }, $rows);

  // 7. Respond 200 (read-only endpoint, no message_key).
  myapi_respond([
    'service_requests' => $service_requests,
    'pagination'       => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * Routes /api/v1/service-requests/provider by HTTP method (SPEC 98).
 *
 * READ-ONLY, AND ONLY GET. There is no POST here and there will not be one by
 * accident: creating, withdrawing or awarding an offer is another spec, and a
 * dispatcher that answered 405 for everything except GET is what keeps that
 * decision visible.
 *
 * THE 405 COMES BEFORE THE TOKEN, deliberately: a POST to a GET-only route is
 * wrong whether or not the caller is authenticated, and answering 401 first
 * would tell a client with a broken verb to go fix its credentials. Same order
 * myapi_service_request_dispatch() and the whole module already use.
 */
function myapi_service_request_provider_dispatch() {
  if (myapi_request_method() === 'GET') {
    myapi_service_request_provider_list();
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * GET /api/v1/service-requests/provider — the provider's own board (SPEC 98).
 *
 * Orchestration only, like myapi_service_request_list(): the access rule lives
 * in myapi_service_request_provider_scope(), the SQL in
 * myapi_service_request_base_query(), the item in
 * myapi_service_request_provider_build_item(). What this function owns is the
 * ORDER, and the order is the contract.
 *
 * THE ONLY 403 IS THE ROLE, AND IT REUSES 'provider_role_required' (SPEC 97).
 * Not a new i18n key: the cause is identical to GET /api/v1/providers/mine —
 * "your account does not hold the provider role" — and one key per route would
 * force the app to treat as different two errors that are not. An account WITH
 * the role and NO linked provider node is a 200 with an empty list, never a
 * 403: having the role and being linked to nothing is missing data, not a
 * missing permission. There is no exception for administrators.
 *
 * '?provider_id' IS PARSED AFTER THE GATE AND BEFORE EVERYTHING ELSE. After,
 * because a reader who may not be here should not learn whether their
 * parameter was well formed; before, because a 422 must not cost a scope
 * query. Every other parameter is lax, and '?unit_id' does not exist on this
 * route at all — it is IGNORED IN SILENCE and never a 422, because filtering by
 * flat contradicts decision 5, which only paints the unit when the job is
 * already mine.
 *
 * THE EMPTY-SCOPE SHORT CIRCUIT COVERS BOTH CASES AT ONCE and is the FIRST of
 * the two defences against the empty OR (risk 3): a role with no link, and a
 * '?provider_id' that belongs to somebody else, both answer 200 with an empty
 * list without a single scope query. The second defence is the impossible
 * condition in myapi_service_request_base_query(). Two defences on purpose.
 *
 * $uid IS NULL IN THE COUNT AND IN THE FETCH, and that is exactly what SPEC 88
 * left that parameter nullable for: the reader is the requester of nothing
 * here, and the whole scope travels inside $filters['provider_scope'].
 *
 * TEN QUERIES, FIXED — token, account, link, active, categories, A, C, count,
 * page, offers, names — AND NOT ONE OF THEM GROWS WITH THE PAGE. Twenty
 * requests cost what one costs; the names of the whole page are resolved in ONE
 * call to myapi_user_display_names() and never one per row.
 */
function myapi_service_request_provider_list() {
  // 1. Authentication. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();

  // 2. The account, because the gate reads ->roles and the token row does not
  // carry them. Not an extra query: myapi_auth_require_access_token() already
  // loaded this user and Drupal 7 serves the second call from its static cache.
  $account = user_load($token->uid);

  // 3. The gate, and the only 403 of this endpoint — see the docblock.
  if (!myapi_provider_role_is($account)) {
    myapi_error('provider_role_required', 403);
  }

  // 4. The providers of the ACCOUNT. They are what decision 5 measures `unit`
  // against later, so they are kept apart from the '?provider_id' narrowing
  // below and never overwritten by it.
  $owned = myapi_provider_role_provider_ids($account);

  // 5. The one parameter that can be WRONG rather than merely unknown. Strict
  // in the format, lax in the ownership — see its parser.
  $provider_id = myapi_service_request_parse_provider_id();

  // 6. P: every provider of the account, or the single one selected. A nid that
  // is not the account's narrows P to nothing, which is decision 9 — a 200 with
  // an empty list, not a 403 that would confirm the node exists.
  $scope_ids = $provider_id === NULL
    ? $owned
    : (in_array($provider_id, array_map('intval', $owned), TRUE) ? [$provider_id] : []);

  // 7. Pagination and sort, read before the short circuit so the empty answer
  // still reports the limit the caller asked for. Same idiom as the resident's
  // listing: garbage falls back to the default, '-1' is "everything on one
  // page" (SPEC 15).
  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && $_GET['limit'] === '-1'
    ? -1
    : (isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
      ? max(1, min(50, (int) $_GET['limit']))
      : 20);

  if ($limit === -1) {
    $page = 1;
  }

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  // 8. The access rule, resolved into its three ingredients. An empty P costs
  // NO query here — myapi_service_request_provider_scope() short-circuits on an
  // empty list — so the role-with-no-link case and the foreign-'?provider_id'
  // case both reach the empty answer below without a single scope query, which
  // is what decision 9 and SPEC 97 ask for.
  $scope = myapi_service_request_provider_scope($scope_ids);

  // 9. Nothing in A, B or C: one empty page, one exit, and NOT a 403. Three
  // situations share this answer — the role with no linked provider, a
  // '?provider_id' that is not the account's, and a provider with no offers, no
  // awards and no biddable category — and they share the ONE response so they
  // cannot drift into three slightly different empty bodies.
  //
  // This is also the FIRST of the two defences against the empty OR (risk 3):
  // no empty scope ever reaches myapi_service_request_base_query(). The second
  // is the impossible condition that function adds anyway. Two on purpose.
  //
  // `page` is forced to 1 and `total_pages` is 0 — not 1 — because there is
  // nothing to page through; `limit` echoes what the caller asked for, so a
  // client that sent '?limit=50' is not told it got 20.
  if (!$scope['nids'] && !($scope['biddable'] && $scope['category_ids'])) {
    myapi_respond([
      'service_requests' => [],
      'pagination'       => [
        'total'       => 0,
        'page'        => 1,
        'limit'       => $limit,
        'total_pages' => 0,
      ],
    ], 200);
  }

  // 10. The filters. Identical to the resident's listing EXCEPT '?unit_id',
  // which is not read here at all, plus the scope that makes this endpoint what
  // it is.
  $category_id = myapi_service_request_parse_category_id();
  $created = myapi_service_request_parse_created_range();

  $filters = [
    'status'         => myapi_service_request_parse_status(),
    'category_id'    => $category_id,
    'created_from'   => $created['from'],
    'created_to'     => $created['to'],
    'provider_scope' => $scope,
  ];

  // 11. Count first — the pagination block describes the FILTERED set and not
  // the page. Because B lives in SQL, this total is the real one and not a
  // number counted in PHP over a page.
  $total = myapi_service_request_count(NULL, $filters);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  // 12. The page, then the offers of that whole page in ONE query. The count is
  // the REAL total, competition included (decision 10).
  $rows = myapi_service_request_fetch(NULL, $filters, $page, $limit, $sort);

  $nids = [];
  $requester_uids = [];
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
    if (isset($row->requester_uid) && $row->requester_uid !== '') {
      $requester_uids[(int) $row->requester_uid] = (int) $row->requester_uid;
    }
  }

  $offer_counts = myapi_service_request_offer_counts_by_nid($nids);

  // 13. The names of the WHOLE page in one call, by the shared rule of
  // includes/myapi.user.inc — never one query per row. No contact detail is
  // fetched: myapi_user_fetch_profile_fields() is not called from this
  // resource, so no email, phone or id number can leak into a market listing.
  $names = myapi_user_display_names(array_values($requester_uids));

  foreach ($rows as $row) {
    $row->requester_name = NULL;
    if (isset($row->requester_uid) && $row->requester_uid !== '') {
      $uid = (int) $row->requester_uid;
      $row->requester_name = isset($names[$uid]) ? $names[$uid] : NULL;
    }
  }

  // 14. Serialise. The account's providers and NOT $scope_ids — decision 5 is
  // measured against what I operate, not against the view I am filtering by.
  $service_requests = array_map(function ($row) use ($offer_counts, $owned) {
    return myapi_service_request_provider_build_item($row, $offer_counts, $owned);
  }, $rows);

  // 15. Respond 200 (read-only endpoint, no message_key). Same envelope, same
  // key and same pagination block as the resident's listing.
  myapi_respond([
    'service_requests' => $service_requests,
    'pagination'       => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}


/**
 * Reads '?status' into a list of status keys, or NULL for no filter (SPEC 88).
 *
 * LAX, like every other parameter of this endpoint except category_id: an
 * unknown key is dropped in silence rather than answered with a 422, and
 * '?status=open,invented' filters by 'open' alone. That is the idiom of the
 * whole module (claims, payments, bulletins, providers), and the reasoning is
 * in myapi_service_request_list().
 *
 * THE WHITELIST IS THE CATALOGUE, NEVER A LIST TYPED HERE. It reads
 * myapi_services_request_statuses(), the same function that configures the
 * allowed_values of field_request_status in myapi.install, so the day the
 * catalogue gains a seventh status the filter accepts it with no change to this
 * file — which is exactly what happened when SPEC 87 added 'direct'. A test
 * walks the catalogue keys and fails if any of them appears typed in here.
 *
 * Duplicates are collapsed ('?status=open,open' is one condition) and the order
 * of the query string is preserved; neither matters to the SQL, both keep the
 * value predictable in a test.
 *
 * @return array|NULL
 *   The valid status keys, or NULL when the parameter is absent, is not a
 *   scalar, or carries no valid key at all — the three cases mean "no filter".
 */
function myapi_service_request_parse_status() {
  if (!isset($_GET['status']) || !is_scalar($_GET['status'])) {
    return NULL;
  }

  $allowed = myapi_services_request_statuses();

  $statuses = [];
  foreach (explode(',', (string) $_GET['status']) as $item) {
    $status = trim($item);
    if (isset($allowed[$status]) && !in_array($status, $statuses, TRUE)) {
      $statuses[] = $status;
    }
  }

  return $statuses ? $statuses : NULL;
}

/**
 * Reads '?category_id' into a tid, or NULL for no filter (SPEC 88).
 *
 * THE ONE STRICT PARAMETER OF THIS ENDPOINT, and the asymmetry is deliberate:
 * '?page=abc' falls back to page 1 in silence, but '?category_id=abc' answers
 * 422 invalid_field. The reason is not the parameter, it is its twin —
 * GET /api/v1/providers (SPEC 83) already treats the SAME parameter name in the
 * SAME domain exactly like this, and having '?category_id=abc' answer 422 in
 * one and the full list in the other would force the client to remember which
 * endpoint is which. The coherence that matters is that of one parameter across
 * sibling endpoints, not that of different parameters inside one.
 *
 * A MALFORMED ID IS AN ERROR; A TID THAT DOES NOT EXIST IS NOT. Once the value
 * is a positive integer there are no more judgements: a tid of another
 * vocabulary, or one no request carries, all answer 200 with an empty list.
 * This endpoint filters, it does not validate the catalogue — checking the term
 * would cost an extra query to answer 422 where an empty list says the same
 * thing, and a 404 would say the endpoint does not exist rather than the
 * category.
 *
 * The three malformations it refuses, and the order it checks them in, live in
 * myapi_service_request_parse_id_param(): '?unit_id' (SPEC 91) is strict for a
 * reason of its own but refuses exactly the same values, and one parser serves
 * both.
 *
 * Never returns on a bad value: myapi_error() prints and exits.
 *
 * @return int|NULL
 *   The tid to narrow by, or NULL when the parameter is absent.
 */
function myapi_service_request_parse_category_id() {
  return myapi_service_request_parse_id_param('category_id');
}

/**
 * Reads '?unit_id' into a 'vivienda' nid, or NULL for no filter (SPEC 91).
 *
 * THE SECOND STRICT PARAMETER OF THIS ENDPOINT, and strict for the same KIND of
 * reason '?category_id' is — its twin, not itself. POST /api/v1/service-requests
 * already answers 422 invalid_field for a malformed `unit_id` (SPEC 90), and
 * the app sends here the very value it just created a request with: a listing
 * that swallowed a broken id and answered the WHOLE list would tell the client
 * its unit selector works while it does not, and the resident would read
 * somebody's else request under the heading of their flat.
 *
 * A UNIT THAT IS NOT YOURS IS NOT AN ERROR, AND THAT IS THE WHOLE ASYMMETRY
 * WITH THE CREATION. The POST answers 403 unit_access_denied for a foreign unit
 * because it would WRITE on it; this filter only narrows a set that is already
 * scoped to field_requester = uid, so a foreign unit can intersect it in the
 * empty set and nowhere else. A 403 here would do the opposite of protecting
 * something: it would confirm to whoever probed that the unit exists, and it
 * would cost a query to say what an empty list says for free. Same criterion
 * as '?category_id' with a tid no request carries.
 *
 * Never returns on a bad value: myapi_error() prints and exits.
 *
 * @return int|NULL
 *   The 'vivienda' nid to narrow by, or NULL when the parameter is absent.
 */
function myapi_service_request_parse_unit_id() {
  return myapi_service_request_parse_id_param('unit_id');
}

/**
 * Reads '?provider_id' into a 'provider' nid, or NULL for no filter (SPEC 98).
 *
 * THE THIRD STRICT PARAMETER, and the third to copy '?unit_id' line by line:
 * STRICT IN THE FORMAT, LAX IN THE OWNERSHIP. '?provider_id=abc', '1,2', '-1'
 * or '0' is a broken client and earns its 422 invalid_field before a single
 * query runs; a nid that belongs to somebody else, or to no node at all, is
 * NOT an error — it narrows P to the empty set and the endpoint answers 200
 * with an empty list (SPEC 98, decision 9). The reason is the one written in
 * myapi_service_request_parse_unit_id(): a 403 would confirm to whoever probed
 * that the provider exists, and would cost a query to say what an empty list
 * says for free. It also spares a new i18n key.
 *
 * ONE ID, NEVER A LIST. '?provider_id=1,2' is a malformed value and not a
 * two-provider filter: the union of every provider of the account is what the
 * parameter's ABSENCE already means, so a list would only offer a slower way
 * to ask for a subset nobody asked for.
 *
 * Never returns on a bad value: myapi_error() prints and exits.
 *
 * @return int|NULL
 *   The 'provider' nid to narrow P by, or NULL when the parameter is absent.
 */
function myapi_service_request_parse_provider_id() {
  return myapi_service_request_parse_id_param('provider_id');
}

/**
 * The strict parser BOTH '?category_id' and '?unit_id' are made of (SPEC 91).
 *
 * ONE FUNCTION AND NOT TWO COPIES. The two parameters refuse the same three
 * malformations — a non-scalar, a non-digit string, a zero or negative — and
 * answer the same 422 invalid_field naming themselves; what differs between
 * them is only the REASON each one is strict, and that is written in the
 * wrapper that carries its name. Regla 3 de CLAUDE.md applied inside a file
 * rather than between two of them.
 *
 * is_scalar() COMES FIRST, so '?category_id[]=1' answers 422 without ever
 * reaching a string cast of an array — a PHP notice in the middle of a JSON
 * body is not an answer. Same guard, in the same order, as myapi_provider_list().
 *
 * The empty string is a malformation and not an absence: '?unit_id=' is a
 * present-and-broken value, and ctype_digit('') is FALSE, which is what makes
 * it a 422 rather than a silent "no filter".
 *
 * @param string $name
 *   The query-string parameter to read, which is also the '@field' the error
 *   message names. Always a literal at the call site — never a value that
 *   reached this file from the request.
 *
 * @return int|NULL
 *   The positive integer, or NULL when the parameter is absent.
 */
function myapi_service_request_parse_id_param($name) {
  if (!isset($_GET[$name])) {
    return NULL;
  }

  $raw = $_GET[$name];
  if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => $name]);
  }

  return (int) $raw;
}

/**
 * Reads '?date_from' / '?date_to' into a range of n.created timestamps, or
 * NULLs for no filter.
 *
 * THE PARSING IS NOT WRITTEN HERE. myapi_parse_date_range_param()
 * (includes/myapi.request.inc) is the one place this module reads that pair: it
 * keeps a bound only when it is a real ISO 'YYYY-MM-DD' calendar date, drops
 * anything else in silence, and drops the WHOLE range when the two bounds
 * arrive inverted (from > to). No 422 ever, exactly like '?page', '?limit',
 * '?sort' and '?status' here. Expenses and the condominium summary already
 * share it; a copy of the validation in this file would be the duplication
 * Regla 3 de CLAUDE.md forbids, and the SPEC 73 bug it fixed — "2026-08-06\n"
 * passing the pattern with the newline still in it — would come back with it.
 *
 * WHAT THIS FUNCTION ADDS IS THE COLUMN'S TYPE, and that is its whole reason to
 * exist. Claims, payments and receipts compare against a datetime TEXT column,
 * so they pass the ISO string into the SQL as it arrives; the filter here is on
 * n.created, an integer Unix timestamp, so each bound becomes the instant that
 * makes the DAY inclusive — the lower one the site-local 00:00:00 of date_from,
 * the upper one the site-local 23:59:59 of date_to. '?date_to=2026-08-18' with
 * a bare midnight would drop everything asked for later that same day, which is
 * the mistake myapi_bulletin_parse_date_range() documents; this is the same
 * conversion, over the same column, for the same reason.
 *
 * THE COLUMN IS n.created — when the request was ASKED FOR — and never
 * field_desired_start, when the resident wants the work done. Two different
 * questions: '?sort' already orders by the first one, and a range over the
 * column the client sorts by is the only combination that reads consistently.
 * A filter on desired_start is another parameter and another spec.
 *
 * @return array  ['from' => int|NULL, 'to' => int|NULL] Unix timestamps.
 */
function myapi_service_request_parse_created_range() {
  $range = myapi_parse_date_range_param();

  return [
    'from' => $range['from'] !== NULL ? strtotime($range['from'] . ' 00:00:00') : NULL,
    'to'   => $range['to'] !== NULL ? strtotime($range['to'] . ' 23:59:59') : NULL,
  ];
}

/**
 * Resolves the provider access rule into the three ingredients of A ∪ B ∪ C
 * (SPEC 98).
 *
 * THE ACCESS RULE OF THE PROVIDER LISTING, WRITTEN ONCE AND AS A SET. A
 * request concerns the reader when it is in ANY of:
 *
 *   A — "I already offered": a published service_offer of one of these
 *       providers points at it. ANY status, ANY category — even one the
 *       provider no longer attends. From
 *       myapi_provider_role_offered_request_ids(), untouched.
 *   B — "the market": status IN ('open','offered') AND no assigned offer AND
 *       no assigned provider AND the category is one of theirs AND at least
 *       one of them is active. This is rule 3 of
 *       myapi_service_request_viewer(), condition by condition.
 *   C — "my work": field_assigned_provider points at one of them, IN ANY
 *       STATUS, 'closed' and 'cancelled' included. From
 *       myapi_provider_role_assigned_request_ids().
 *
 * A AND C COME BACK AS NIDS, B AS THE INGREDIENTS OF A SQL CONDITION, and that
 * asymmetry is deliberate (SPEC 98, decision 12). A and C are bounded by the
 * account's own activity — the offers it sent, the jobs it was given — so the
 * IN list has tens or hundreds of members and does not grow with the system.
 * B does: every open request of a popular category can be thousands, and
 * resolving it to nids in PHP would make the query slower the BETTER the
 * marketplace works, and would move the pagination total out of SQL.
 * myapi_service_request_base_query() is where these three become a WHERE.
 *
 * B USES THE SPEC 89 STATUS LIST AND NOT
 * myapi_provider_role_broadcast_statuses(). That catalogue includes 'direct',
 * and B has to exclude it: a 'direct' is born assigned, which is exactly what
 * "unassigned" rules out. A 'direct' of MINE still shows up — through C. A
 * foreign one shows up through nothing, which is the right answer.
 *
 * 'biddable' IS RESOLVED BEFORE 'category_ids' AND SWITCHES IT OFF: with no
 * active provider in P there is no B at all, so the categories are not worth a
 * query. One query less for the suspended provider.
 *
 * THIS IS THE SAME RULE myapi_service_request_viewer() WRITES AS A PER-ROW
 * DECISION (its rules 2b and 3). The two forms cannot be derived from one
 * another without losing either the pagination or the loaded row, so they
 * live side by side — and if they ever drift, the symptom is an item in this
 * listing that answers 403 when opened. ServiceRequestProviderListTest holds
 * the equivalence over the whole status × assignment × category × offered
 * matrix; that test is the only thing that catches the drift.
 *
 * @param array $provider_ids
 *   The providers P is narrowed to: every provider of the account, or the
 *   single one '?provider_id' selected. Empty gives three empty ingredients
 *   without a single query.
 *
 * @return array
 *   ['nids' => array (A ∪ C, deduplicated), 'category_ids' => array (B's
 *   categories, empty when B is off), 'biddable' => bool (B's licence gate)].
 */
function myapi_service_request_provider_scope(array $provider_ids) {
  if (!$provider_ids) {
    return ['nids' => [], 'category_ids' => [], 'biddable' => FALSE];
  }

  $biddable = myapi_provider_role_any_provider_active_for_providers($provider_ids);

  return [
    'nids' => array_values(array_unique(array_merge(
      myapi_provider_role_offered_request_ids($provider_ids),
      myapi_provider_role_assigned_request_ids($provider_ids)
    ))),
    'category_ids' => $biddable
      ? myapi_provider_role_category_ids_for_providers($provider_ids)
      : [],
    'biddable' => $biddable,
  ];
}

/* ---------------------------------------------------------------------------
 * Queries.
 *
 * THREE PER REQUEST, FIXED, AND NONE OF THEM GROWS WITH THE PAGE: the count,
 * the page, and the offers of that whole page. Never one query per request —
 * see myapi_service_request_offer_counts_by_nid().
 * ------------------------------------------------------------------------ */

/**
 * Builds the query every request read starts from: the scope plus the optional
 * filters, with no projection, ordering or pagination (SPEC 88).
 *
 * THE ONE PLACE THE SCOPE IS WRITTEN. The count and the page both call it, so
 * the two can never disagree about which requests exist — which is the point,
 * and Regla 3 de CLAUDE.md. A request is visible when:
 *
 *   n.type = 'service_request' AND n.status = 1
 *   AND field_requester_target_id = :uid
 *
 * field_requester and NOT node.uid, deliberately: node.uid is the technical
 * author, which today — with no creation endpoint — is the administrator who
 * loaded the request from the back office, so filtering by it would answer an
 * empty list to everyone. field_requester is the semantic field, the same one
 * myapi_claim_create() fills in.
 *
 * THE FRONTIER BETWEEN THIS FUNCTION AND myapi_service_request_fetch() IS
 * EXACT, and it is what makes `pagination.total` describe the rows the pages
 * return: everything that decides WHICH ROWS EXIST lives here — the bundle,
 * n.status, the requester, the category joins and every filter — and
 * everything that only adds COLUMNS lives in the fetch. It is the rule
 * myapi_provider_count() already writes down.
 *
 * THE CATEGORY IS INNER-JOINED, AND TWICE — the field table and
 * taxonomy_term_data — because both decide rows. field_category is required, so
 * an orphan tid only happens when an operator deletes a term from the
 * vocabulary with requests still hanging off it, and a service listing with no
 * category is not actionable: the inconsistency must SHOW (a missing row)
 * rather than spread as a null every client paints its own way. It is the one
 * exception to the LEFT JOIN rule the awarded offer and provider follow, it is
 * deliberate, and docs/service-request.md carries the query that diagnoses it.
 * Neither join can multiply rows: field_category has cardinality 1 and
 * taxonomy_term_data.tid is a primary key.
 *
 * field_request_status is LEFT-joined even though the filter reads it: a
 * request whose status row was deleted by hand still appears in the unfiltered
 * listing, with a null status, instead of vanishing. Once '?status' is active
 * the IN excludes it on its own, which is the right reading of "show me the
 * open ones".
 *
 * field_unit is LEFT-joined for that same reason and one more of its own
 * (SPEC 91). The field is REQUIRED on the bundle since SPEC 86, so in a healthy
 * site an INNER JOIN would list the same rows — but the requirement was added
 * to a bundle that already existed, with no backfill, so a request loaded from
 * the back office before that day can have no field row at all, and an INNER
 * JOIN would make it disappear from its owner's listing with no message. It is
 * the failure the category join accepts on purpose and this one does not: a
 * request with no unit is still actionable, one with no category is not.
 *
 * THE FILTER COMPARES THE RAW target_id AND NEVER A JOINED 'vivienda' NODE.
 * That is what keeps the count query at three join tables — resolving the node
 * would drag it in too — and it is also the right answer: a request whose unit
 * was unpublished is still a request OF that unit, so '?unit_id' keeps it and
 * the page answers unit: null, exactly what a broken award already does. The
 * two halves are independent on purpose, and the fetch is where the node is
 * resolved.
 *
 * THE QUERY DELIBERATELY CARRIES NO ->addTag('node_access'). See the @file
 * docblock: the tag would run myapi_provider_role_alter_node_query() over this
 * query, and a resident who also holds the 'proveedor' role would stop seeing
 * their own requests of categories they do not attend.
 *
 * $uid ACCEPTS NULL SINCE SPEC 89, and that is the ONE thing the detail asked
 * of the listing. NULL means "do not add the requester condition" — never
 * "compare the column with NULL", which would answer an empty set — and the
 * INNER JOIN to field_data_field_requester stays either way, so a request still
 * has to HAVE a requester to exist for this module. The detail needs it because
 * its reader may be a provider, who is not the requester of anything and would
 * be locked out by a condition written into the shared base.
 *
 * A second base query for the detail was the alternative, and it is worse: two
 * functions answering "which requests are real" drift the day one of them
 * changes, and the listing already shares this one between its count and its
 * page for exactly that reason. Writing an OR into the SQL to cover both
 * readers was the other, and it mixes two access rules into a WHERE clause
 * where nobody can see them — the access rule of the detail lives in
 * myapi_service_request_viewer(), in PHP, in one place.
 *
 * @param int|NULL $uid  Authenticated reader uid, or NULL for "every request,
 *                       whoever the requester is". Only the detail passes NULL;
 *                       the listing always passes a uid, and its behaviour is
 *                       byte for byte what it was.
 * @param array $filters  ['status' => array|NULL (list of status keys),
 *                        'category_id' => int|NULL, 'unit_id' => int|NULL
 *                        ('vivienda' nid, compared against the RAW target_id),
 *                        'created_from' => int|NULL, 'created_to' => int|NULL
 *                        (Unix timestamps compared against n.created),
 *                        'provider_scope' => array|NULL (SPEC 98: the shape
 *                        myapi_service_request_provider_scope() returns —
 *                        ABSENT OR NULL LEAVES THE QUERY EXACTLY AS IT WAS,
 *                        which is what keeps the resident's listing free of
 *                        this)]. All optional; NULL means no filter on that
 *                        column, and they compose with AND.
 *
 * @return SelectQuery  The query, with these aliases already in place:
 *                      n (node), fr (requester), fcat (category field),
 *                      td (taxonomy term), frs (status), fu (unit field) —
 *                      plus sfao/sfap when 'provider_scope' turns B on.
 */
function myapi_service_request_base_query($uid, array $filters = []) {
  $query = db_select('node', 'n')
    ->condition('n.type', MYAPI_SERVICES_REQUEST_TYPE)
    ->condition('n.status', 1);

  // The scope, and the only one: whose request this is. The join is
  // unconditional — every request has a requester — and only the CONDITION is
  // optional, which is what keeps a NULL from widening what a request is.
  $query->innerJoin('field_data_field_requester', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");

  if ($uid !== NULL) {
    $query->condition('fr.field_requester_target_id', $uid);
  }

  // The category, INNER on both tables — see the docblock. The '?category_id'
  // filter below adds NO table: it is one more condition on a join that was
  // already here because the response has to paint the category anyway.
  $query->innerJoin('field_data_field_category', 'fcat', "fcat.entity_id = n.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0");
  $query->innerJoin('taxonomy_term_data', 'td', 'td.tid = fcat.field_category_tid');

  // LEFT: joined here because the filter reads it, projected by the fetch.
  $query->leftJoin('field_data_field_request_status', 'frs', "frs.entity_id = n.nid AND frs.entity_type = 'node' AND frs.deleted = 0");

  // The unit, same case as the status above: the '?unit_id' filter reads this
  // table, so it belongs to the shared base and not to the fetch — and it is
  // LEFT for the reason the docblock spells out. The detail also reads it from
  // here, and resolves the 'vivienda' node on top of this same alias.
  $query->leftJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");

  // '?status' arrives as a LIST, so the condition is always an IN — with one
  // element for the single-value form, which MySQL resolves exactly like the
  // '=' it replaces. Same shape as myapi_claim_base_query().
  if (!empty($filters['status'])) {
    $query->condition('frs.field_request_status_value', (array) $filters['status'], 'IN');
  }

  if (!empty($filters['category_id'])) {
    $query->condition('fcat.field_category_tid', $filters['category_id']);
  }

  // The RAW target_id — see the docblock. It adds no table either: the join is
  // already above, because the response paints the unit anyway.
  if (!empty($filters['unit_id'])) {
    $query->condition('fu.field_unit_target_id', $filters['unit_id']);
  }

  // The creation date, on the node's own column: it adds NO table, and being a
  // column of `n` it cannot multiply a row either. Each bound is already the
  // instant that makes its day inclusive — the conversion lives in
  // myapi_service_request_parse_created_range(), so this file compares numbers
  // and never parses a date. The two are independent: '?date_from' alone is a
  // half-open range, and so is '?date_to' alone.
  //
  // The test is `!== NULL` and not !empty(), unlike the filters above: 0 is a
  // legal timestamp (the epoch), and !empty() would silently drop a bound of
  // 1970-01-01 instead of filtering by it.
  if (isset($filters['created_from']) && $filters['created_from'] !== NULL) {
    $query->condition('n.created', $filters['created_from'], '>=');
  }

  if (isset($filters['created_to']) && $filters['created_to'] !== NULL) {
    $query->condition('n.created', $filters['created_to'], '<=');
  }

  // The provider scope, and the ONLY door A ∪ B ∪ C has into this shared query
  // (SPEC 98). ABSENT OR NULL LEAVES THE QUERY BYTE FOR BYTE AS IT WAS: not a
  // condition, not a join, not a placeholder. The resident's listing pays
  // nothing for this, which is the promise the spec makes and the acceptance
  // criterion that guards it.
  if (!empty($filters['provider_scope'])) {
    $scope = $filters['provider_scope'];
    $scope_nids = !empty($scope['nids']) ? $scope['nids'] : [];
    $scope_market = !empty($scope['biddable']) && !empty($scope['category_ids']);

    // *** AN EMPTY db_or() RETURNS THE WHOLE SYSTEM, NOT NOTHING. ***
    // Drupal 7 compiles a condition group with no conditions to NOTHING AT
    // ALL — not to FALSE. An empty scope reaching the OR below without this
    // guard would produce a query with NO provider filter, and the endpoint
    // would answer EVERY service_request of EVERY condominium, requester name
    // and condominium included. It is the worst failure this code can have.
    // myapi_service_request_provider_list() cuts earlier and answers an empty
    // list before it ever gets here; this is the SECOND defence, an impossible
    // condition, so that a bug in this file answers NOTHING and never
    // EVERYTHING.
    if (!$scope_nids && !$scope_market) {
      $query->condition('n.nid', [0], 'IN');
    }
    else {
      $or = db_or();

      // A ∪ C — already resolved to nids, because both are bounded by the
      // account's own activity and do not grow with the system.
      if ($scope_nids) {
        $or->condition('n.nid', $scope_nids, 'IN');
      }

      // B, the market — kept in SQL because it DOES grow with the system, and
      // because only there can pagination's total describe the real set.
      if ($scope_market) {
        // *** THE ALIASES 'sfao' AND 'sfap' ARE THEIR OWN ON PURPOSE. ***
        // These two tables end up joined TWICE in the page query: once here,
        // for B's conditions, and once in myapi_service_request_fetch() as
        // 'fao'/'fap', to resolve the nodes. It looks like an oversight and it
        // is not (SPEC 98, risk 5): merging them towards fao/fap would move
        // joins into the resident's page query, and merging them towards this
        // base would change the resident's countQuery too. Either way the
        // promise that GET /api/v1/service-requests gains not a single join
        // breaks, and it breaks SILENTLY, because the answer stays correct.
        // Reusing the aliases would simply be a collision. DO NOT MERGE.
        $query->leftJoin('field_data_field_assigned_offer', 'sfao', "sfao.entity_id = n.nid AND sfao.entity_type = 'node' AND sfao.deleted = 0");
        $query->leftJoin('field_data_field_assigned_provider', 'sfap', "sfap.entity_id = n.nid AND sfap.entity_type = 'node' AND sfap.deleted = 0");

        $market = db_and();
        // The SPEC 89 list, and NOT myapi_provider_role_broadcast_statuses():
        // that catalogue carries 'direct', which is born assigned — the very
        // thing the two isNull() below rule out. A 'direct' of mine still
        // arrives, through C; a foreign one arrives through nothing.
        $market->condition('frs.field_request_status_value', [
          MYAPI_SERVICES_REQUEST_STATUS_OPEN,
          MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
        ], 'IN');
        $market->isNull('sfao.field_assigned_offer_target_id');
        $market->isNull('sfap.field_assigned_provider_target_id');
        $market->condition('fcat.field_category_tid', $scope['category_ids'], 'IN');

        $or->condition($market);
      }

      $query->condition($or);
    }
  }

  return $query;
}

/**
 * Counts the reader's requests under the current filters, unpaginated
 * (SPEC 88).
 *
 * Nothing but myapi_service_request_base_query()->countQuery(): the total and
 * the page are, by construction, the same set. What it does NOT carry is the
 * six LEFT JOINs of the fetch — they add columns, never rows, so counting
 * without them answers the same number over the same set while reading three
 * join tables instead of nine.
 *
 * Every table the base joins is cardinality 1 (or a primary key), so there is
 * one row per request and the count needs no DISTINCT.
 *
 * @param int   $uid      Authenticated reader uid.
 * @param array $filters  Same shape as myapi_service_request_base_query().
 *
 * @return int  Number of matching requests.
 */
function myapi_service_request_count($uid, array $filters = []) {
  return (int) myapi_service_request_base_query($uid, $filters)
    ->countQuery()
    ->execute()
    ->fetchField();
}

/**
 * Fetches one page of requests, with every column the item needs (SPEC 88).
 *
 * Starts from the same myapi_service_request_base_query() the count uses — so
 * the page is always a slice of the counted set — and adds ONLY what projects
 * columns: the term name and code, the unit's node and name, the description,
 * the desired start, and the awarded offer and provider.
 *
 * EVERY JOIN ADDED HERE IS LEFT AND NEVER INNER, and the awarded pair is the
 * reason the rule exists: nothing validates today that field_assigned_offer
 * points at a live node, and losing a request of your own from the listing
 * because of a broken reference is the worst failure a read endpoint can have —
 * the user sees nothing and no error explains it. A reference that does not
 * resolve leaves its key null and the row survives, which is exactly the
 * distinction the client needs and the one myapi_service_request_build_item()
 * already makes.
 *
 * The offer's and the provider's node joins carry `status = 1` and the bundle:
 * an unpublished or deleted node answers null (the acceptance criterion), and
 * the type condition means a reference pointing at the wrong bundle can never
 * paint someone else's title as the provider's name. Neither join can multiply
 * rows — both fields have cardinality 1 and node.nid is a primary key.
 *
 * THE ORDERING IS TWO CRITERIA, AND THE SECOND IS NOT DECORATION.
 * `n.created` is the order the resident expects ("the last thing I asked for,
 * on top") and no edit ever moves it; `n.nid` breaks the tie. Without it, two
 * requests created in the same second can swap places between page 1 and page
 * 2, so the app shows one of them twice and never shows the other — the same
 * trap SPEC 83 documented with rating_avg. Both take the SAME direction, so
 * '?sort=asc' reverses the whole listing coherently.
 *
 * @param int    $uid      Authenticated reader uid.
 * @param array  $filters  Same shape as myapi_service_request_base_query().
 * @param int    $page     1-based page number. Ignored when $limit is -1.
 * @param int    $limit    Rows per page, or -1 for "all matching rows,
 *                         unpaginated" (SPEC 15).
 * @param string $sort     'asc' or 'desc' on the creation date.
 *
 * @return array  Rows carrying the fifteen aliases
 *                myapi_service_request_build_item() reads, plus the three
 *                SPEC 98 added for the provider listing — `requester_uid`,
 *                `condominium_id` and `condominium_name` — which that builder
 *                ignores, so the resident's response is unchanged.
 */
function myapi_service_request_fetch($uid, array $filters, $page, $limit, $sort) {
  $query = myapi_service_request_base_query($uid, $filters);

  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'title', 'title');
  $query->addField('n', 'created', 'created');

  // The category: the tid off the field row, the name off the term. Both joins
  // already live in the base query. The code is one more column and therefore
  // LEFT: field_category_code is required on the vocabulary, so a term without
  // one is corrupt data, not a business case — it answers code "" and keeps its
  // request, exactly what /api/v1/service-categories and /api/v1/providers
  // already do with this same field.
  $query->addField('fcat', 'field_category_tid', 'category_id');
  $query->addField('td', 'name', 'category_name');
  $query->leftJoin('field_data_field_category_code', 'cc', "cc.entity_id = td.tid AND cc.entity_type = 'taxonomy_term' AND cc.deleted = 0");
  $query->addField('cc', 'field_category_code_value', 'category_code');

  // The unit (SPEC 91). The reference itself is in the base query — the filter
  // reads it — so what the page adds is the second hop: the 'vivienda' node and
  // the name field hanging off it. Byte for byte the two joins the detail
  // makes, and for the same reasons: the node join carries the bundle, so a
  // reference to something else can never paint its name here, and it carries
  // status = 1, so an unpublished unit answers null instead of a name nobody
  // can open. The id projected is nu.nid and NOT fu.field_unit_target_id, which
  // is what makes a broken reference answer unit: null rather than an id the
  // client cannot resolve — the same rule the awarded offer follows, and the
  // reason '?unit_id' filters on the raw column while the response paints this
  // one.
  $query->leftJoin('node', 'nu', "nu.nid = fu.field_unit_target_id AND nu.type = 'vivienda' AND nu.status = 1");
  $query->addField('nu', 'nid', 'unit_id');
  $query->leftJoin('field_data_field_nombre_vivienda', 'fnv', "fnv.entity_id = nu.nid AND fnv.entity_type = 'node' AND fnv.deleted = 0");
  $query->addField('fnv', 'field_nombre_vivienda_value', 'unit_name');

  $query->addField('frs', 'field_request_status_value', 'status');

  $query->leftJoin('field_data_field_description', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->addField('fd', 'field_description_value', 'description');

  $query->leftJoin('field_data_field_desired_start', 'fds', "fds.entity_id = n.nid AND fds.entity_type = 'node' AND fds.deleted = 0");
  $query->addField('fds', 'field_desired_start_value', 'desired_start');

  // The awarded offer, in three chained LEFT JOINs. The id projected is the
  // JOINED node's nid and not fao.field_assigned_offer_target_id, which is what
  // makes an unpublished or deleted offer answer null instead of an id the
  // client cannot resolve.
  $query->leftJoin('field_data_field_assigned_offer', 'fao', "fao.entity_id = n.nid AND fao.entity_type = 'node' AND fao.deleted = 0");
  $query->leftJoin('node', 'no', 'no.nid = fao.field_assigned_offer_target_id AND no.type = :offer_type AND no.status = 1', [':offer_type' => MYAPI_SERVICES_OFFER_TYPE]);
  $query->addField('no', 'nid', 'assigned_offer_id');
  $query->leftJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->addField('fos', 'field_offer_status_value', 'assigned_offer_status');

  // The awarded provider, resolved on its own and never through the offer: a
  // 'direct' request has a provider and no offer at all (SPEC 87), which is the
  // whole reason these are two sibling keys.
  $query->leftJoin('field_data_field_assigned_provider', 'fap', "fap.entity_id = n.nid AND fap.entity_type = 'node' AND fap.deleted = 0");
  $query->leftJoin('node', 'np', 'np.nid = fap.field_assigned_provider_target_id AND np.type = :provider_type AND np.status = 1', [':provider_type' => MYAPI_SERVICES_PROVIDER_TYPE]);
  $query->addField('np', 'nid', 'assigned_provider_id');
  $query->addField('np', 'title', 'assigned_provider_name');

  // The requester's uid and the condominium (SPEC 98). THREE COLUMNS THAT
  // myapi_service_request_build_item() DOES NOT READ, which is precisely why
  // adding them here changes nothing for the resident: their listing carries
  // two more columns nobody looks at and answers byte for byte what it did.
  // Only myapi_service_request_provider_build_item() reads them.
  //
  // The uid is free: 'fr' is joined by the base query already, unconditionally,
  // because a request has to HAVE a requester to exist. Projecting it costs no
  // table and no row. myapi_service_request_provider_list() turns the page's
  // unique uids into names in ONE call to myapi_user_display_names(), never one
  // query per row.
  $query->addField('fr', 'field_requester_target_id', 'requester_uid');

  // The condominium, byte for byte the two joins myapi_service_request_detail_row()
  // makes — same aliases, same bundle, same status = 1 — so the two responses
  // cannot drift on what a condominium is. Its name is the NODE TITLE and not a
  // field: the 'condominio' bundle has none. An empty reference, a deleted node
  // or an unpublished one leaves both columns null, which the item turns into a
  // whole `condominium: null` while the request keeps its place in the list.
  $query->leftJoin('field_data_field_condominium', 'fco', "fco.entity_id = n.nid AND fco.entity_type = 'node' AND fco.deleted = 0");
  $query->leftJoin('node', 'nc', "nc.nid = fco.field_condominium_target_id AND nc.type = 'condominio' AND nc.status = 1");
  $query->addField('nc', 'nid', 'condominium_id');
  $query->addField('nc', 'title', 'condominium_name');

  $direction = ($sort === 'asc') ? 'ASC' : 'DESC';
  $query->orderBy('n.created', $direction);
  $query->orderBy('n.nid', $direction);

  // limit = -1 means "no pagination" (SPEC 15): no ->range() at all, and the
  // caller has already forced page = 1.
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }

  return $query->execute()->fetchAll();
}

/**
 * How many offers each request of a page received, in ONE query (SPEC 88).
 *
 * ONE QUERY FOR THE WHOLE PAGE, NEVER ONE PER REQUEST. With the nids of the
 * page already in memory, a single grouped read answers the count of all twenty
 * requests; joining the offers into the page query is impossible — an offer per
 * row would multiply the requests — and one query per request would be
 * twenty-one reads per page. Same shape as myapi_provider_categories_by_nid()
 * (SPEC 83), with an aggregate instead of a list.
 *
 * THE INNER JOIN TO node IS OBLIGATORY AND NOT DECORATIVE, and it is the whole
 * correctness of this function: field_request is a field SHARED by
 * 'service_offer' and 'service_transaction' (SPEC 77), so counting rows of
 * field_data_field_request without filtering by bundle would also count the
 * timeline entries, and offers_count would grow with every status change. The
 * failure is silent — the number stays plausible, just higher. `status = 1` is
 * in the same condition for a smaller reason: an unpublished offer is one the
 * operator withdrew from sight, and it must not count either.
 *
 * WHAT IT COUNTS IS EVERY PUBLISHED OFFER, WHATEVER ITS STATUS — 'sent',
 * 'selected', 'rejected' and 'withdrawn' alike. "How many offers did I get" is
 * the question a listing answers, and an offer that was later withdrawn was
 * still received. The breakdown by status is detail information and belongs to
 * GET /api/v1/service-requests/%, which lists the offers one by one.
 *
 * @param array $nids
 *   The request nids of the page. An empty list answers an empty map with no
 *   query at all — which is also what keeps an "IN ()" (invalid SQL in D7) from
 *   ever being built.
 *
 * @return array
 *   Map of (int) nid => (int) count. EVERY REQUESTED NID IS A KEY, zero-filled
 *   when the request has no offers, so the caller never has to decide what a
 *   missing entry means.
 */
function myapi_service_request_offer_counts_by_nid(array $nids) {
  if (empty($nids)) {
    return [];
  }

  // Every requested nid starts at zero; the query then raises the ones that
  // received offers. Same criterion as myapi_provider_categories_by_nid().
  $map = [];
  foreach ($nids as $nid) {
    $map[(int) $nid] = 0;
  }

  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);
  $query->condition('fq.field_request_target_id', array_keys($map), 'IN');
  // INNER — see the docblock. The bundle and the published flag are written as
  // conditions and not inside the ON clause, which for an INNER JOIN is exactly
  // the same set of rows and is the form a test can assert by outcome instead of
  // by the text of a join. Removing either would silently count
  // 'service_transaction' rows, or withdrawn ones, as offers.
  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);
  $query->addField('fq', 'field_request_target_id', 'nid');
  $query->addExpression('COUNT(*)', 'total');
  $query->groupBy('fq.field_request_target_id');

  foreach ($query->execute() as $row) {
    $map[(int) $row->nid] = (int) $row->total;
  }

  return $map;
}

/**
 * Shapes one request row into the item of the listing (SPEC 88).
 *
 * ALWAYS THE SAME ELEVEN KEYS, IN THIS ORDER — id, title, description, status,
 * category, unit, offers_count, assigned_offer, assigned_provider, created,
 * desired_start — because that order is the one drupal_json_encode() prints and
 * the one docs/service-request.md shows. No key is ever added or dropped by the
 * data: a request with no offers and no award answers all eleven just the same.
 *
 * `unit` SITS BESIDE `category` AND NOT AT THE END (SPEC 91). The two answer
 * the same question — WHAT the request is and WHERE it is — while
 * offers_count, assigned_offer and assigned_provider are the market's state and
 * stay contiguous. The position matters twice: it is the order the JSON is
 * printed in, and the detail merges this item first, so `unit` reached the
 * detail's response through here too and moved with it.
 *
 * PURE: no database, no node_load(), no $_GET. Everything it needs arrives in
 * $row and $offer_counts, which is what lets a test exercise every shape of
 * the response — the direct request, the broken reference, the empty count —
 * with no site booted at all.
 *
 * THE AWARD IS TWO SIBLING KEYS AND NOT ONE OBJECT, and the reason is the
 * 'direct' status of SPEC 87: a request whose resident picked a provider with
 * no bidding round has a provider and NO offer. An awarded: {offer, provider}
 * would have to answer either a whole null — losing the provider — or an object
 * with half of it empty. The two keys go null independently:
 *
 *   open / offered / cancelled : null      + null
 *   assigned                   : {id,…}    + {id,…}
 *   direct                     : NULL      + {id,…}
 *   closed                     : whatever it carried when it closed
 *
 * Four typing decisions live here, each with a symptom behind it:
 *
 * - Ids are JSON integers, never strings: the database hands every column back
 *   as a string, and a Flutter client comparing 128 to "128" fails silently.
 *   Same rule myapi_claim_build_item() follows.
 * - `offers_count` is an int and 0 when there is nothing to count, never NULL
 *   and never an absent key: "how many offers did I get" always has a numeric
 *   answer, and 0 is the honest one for a request nobody bid on — every
 *   'direct' request included.
 * - `description` travels AS STORED, without myapi_text_to_plain(): that helper
 *   collapses the line breaks the resident typed. It is also exactly what
 *   myapi_claim_build_item() does with this very same shared field, so the same
 *   datum travels the same way in both endpoints. The instance pins the
 *   'plain_text' format (SPEC 77), so no markup travels — see the note in
 *   docs/service-request.md.
 * - `created` and `desired_start` are real timestamps — field_desired_start is
 *   a datestamp with tz_handling, NOT the tz_handling = 'none' of
 *   field_reception_date — so both get format_date() in the site's timezone,
 *   identical to `created` in GET /api/v1/claims.
 *
 * The isset() guards are not decoration. `category.id` and `category.name` come
 * from two INNER JOINs and cannot be missing in practice, and description and
 * desired_start are required at the instance level; but they are LEFT-joined, so
 * a field row deleted by hand would hand a NULL in here, and answering "" or
 * null beats a PHP notice in the middle of a listing. `category.code` is the one
 * whose guard fires in real data: its join IS left, and a term with no
 * field_category_code answers "" — the same "" /api/v1/service-categories and
 * /api/v1/providers already answer for that same term.
 *
 * @param object $row
 *   One row of myapi_service_request_fetch(), carrying the aliases `nid`,
 *   `title`, `description`, `status`, `category_id`, `category_code`,
 *   `category_name`, `unit_id`, `unit_name`, `assigned_offer_id`,
 *   `assigned_offer_status`, `assigned_provider_id`, `assigned_provider_name`,
 *   `created` and `desired_start`.
 * @param array $offer_counts
 *   The map myapi_service_request_offer_counts_by_nid() returns for the WHOLE
 *   page, keyed by nid. Passed in and not looked up: resolving it here would
 *   mean one query per request, and the whole page is meant to cost three.
 *
 * @return array
 *   The eleven keys above, in that order.
 */
function myapi_service_request_build_item($row, array $offer_counts) {
  $nid = (int) $row->nid;

  // Resolved from the JOINED 'vivienda' node, never from the field's raw
  // target_id, and for the same reason the award below is: a reference to a
  // unit that was deleted or unpublished leaves this NULL instead of handing
  // the client an id it cannot open. See myapi_service_request_fetch().
  $unit = NULL;
  if (isset($row->unit_id) && $row->unit_id !== '') {
    $unit = [
      'id'   => (int) $row->unit_id,
      'name' => isset($row->unit_name) ? $row->unit_name : NULL,
    ];
  }

  // The offer is resolved from the JOINED node, never from the field's raw
  // target_id: a field row pointing at an offer that was deleted or unpublished
  // leaves this NULL, and the request keeps its place in the listing.
  $assigned_offer = NULL;
  if (isset($row->assigned_offer_id) && $row->assigned_offer_id !== '') {
    $assigned_offer = [
      'id'     => (int) $row->assigned_offer_id,
      'status' => isset($row->assigned_offer_status) ? $row->assigned_offer_status : NULL,
    ];
  }

  // Same rule, and independently: this is the key a 'direct' request answers
  // with the one above left NULL.
  $assigned_provider = NULL;
  if (isset($row->assigned_provider_id) && $row->assigned_provider_id !== '') {
    $assigned_provider = [
      'id'   => (int) $row->assigned_provider_id,
      'name' => isset($row->assigned_provider_name) ? $row->assigned_provider_name : NULL,
    ];
  }

  return [
    'id'          => $nid,
    'title'       => isset($row->title) ? $row->title : '',
    // As stored — see the docblock: myapi_text_to_plain() would eat the line
    // breaks, and claims already answers this same field this same way.
    'description' => isset($row->description) ? $row->description : '',
    'status'      => isset($row->status) ? $row->status : NULL,
    // Three keys, in the same order /api/v1/service-categories prints them —
    // id, code, name. `code` is the stable identifier the app hangs its
    // per-category logic on (the tid changes if the vocabulary is reimported;
    // the code does not), and "" when the term has none, never NULL: the field
    // is required on the vocabulary, so an empty one is corrupt data and the
    // client still has a string to compare.
    'category'    => [
      'id'   => isset($row->category_id) ? (int) $row->category_id : NULL,
      'code' => isset($row->category_code) ? $row->category_code : '',
      'name' => isset($row->category_name) ? $row->category_name : '',
    ],
    // The unit the service goes to (SPEC 91). A WHOLE null, never {id: null,
    // name: null}: "this request has no unit I can paint" is one answer and not
    // two half-answers, the same shape assigned_offer and assigned_provider
    // already use above. It is null in exactly two cases — a request older than
    // the SPEC 86 requirement, and a reference whose 'vivienda' was deleted or
    // unpublished — and the request keeps its place in the listing in both.
    //
    // `name` is field_nombre_vivienda and NOT the node title: the title is an
    // internal label and the field is the name the resident knows their flat
    // by, which is what GET /api/v1/units answers as `name` too.
    'unit'        => $unit,
    // (int) of a missing entry is 0, which is exactly the answer wanted: the
    // fallback is the rule, not a coincidence to be tidied away later.
    'offers_count'      => isset($offer_counts[$nid]) ? (int) $offer_counts[$nid] : 0,
    'assigned_offer'    => $assigned_offer,
    'assigned_provider' => $assigned_provider,
    'created'           => isset($row->created)
      ? format_date((int) $row->created, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
    // NULL and not format_date(0), which would answer a 1970 nobody typed.
    'desired_start'     => isset($row->desired_start) && $row->desired_start !== ''
      ? format_date((int) $row->desired_start, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
  ];
}

/**
 * Serialises one row of the PROVIDER listing: the eleven keys plus two
 * (SPEC 98).
 *
 * THE ELEVEN COME FROM myapi_service_request_build_item(), UNTOUCHED, and the
 * two new ones go AFTER them — the same construction-by-delegation SPEC 97
 * used, and for the same reason: the shared serialiser is the one thing that
 * cannot be allowed to drift, so the provider's response is the resident's
 * response plus a suffix and never a second copy of the same eleven keys.
 *
 * THE UNIT IS OVERWRITTEN TO NULL UNLESS THE JOB IS ALREADY MINE (decision 5).
 * SPEC 89 nulls `unit` for EVERY provider reader with a reason that still
 * holds: the flat number adds nothing to the decision to bid and does say
 * where a specific person lives, to anyone in the category. But once the
 * request is awarded to one of MY providers the argument inverts — I am going
 * to that house, the address is the operational datum of the job, and hiding
 * it would force a second channel for something the API already has.
 *
 * THE COMPARISON IS AGAINST assigned_provider AS ALREADY BUILT, AND NEVER
 * AGAINST THE RAW COLUMN. That key was resolved against `node` with the bundle
 * and status = 1, so an award pointing at a deleted or unpublished node
 * answers `assigned_provider: null` and therefore `unit: null` too: the rule
 * fails towards the closed side, which is the only direction a privacy rule
 * may fail in.
 *
 * $owned_provider_ids ARE THE ACCOUNT'S PROVIDERS AND NOT '?provider_id'. With
 * ?provider_id=A, a request awarded to my other provider B that shows up
 * through A's offer still paints the unit: I am going to that house either
 * way, and narrowing a VIEW does not change what I already know.
 *
 * THE KEY ALWAYS TRAVELS, with value null when it does not apply. A client
 * that gets it sometimes and not others would have to tell "absent" from
 * "null", and here the two mean the same thing.
 *
 * The condominium, by contrast, travels ALWAYS (decision 6): it names the area
 * without naming the door.
 *
 * @param object $row
 *   One row of myapi_service_request_fetch(), with the aliases that function's
 *   docblock lists plus `requester_uid`, `condominium_id`, `condominium_name`
 *   and `requester_name` — the last one written onto the row by
 *   myapi_service_request_provider_list() out of ONE call to
 *   myapi_user_display_names() for the whole page.
 * @param array $offer_counts
 *   The whole page's map, exactly as myapi_service_request_build_item() takes
 *   it. The count is the REAL total, competition included (decision 10).
 * @param array $owned_provider_ids
 *   Every provider nid of the READER'S ACCOUNT — see above, not the
 *   '?provider_id' selection.
 *
 * @return array
 *   The thirteen keys, in the documented order.
 */
function myapi_service_request_provider_build_item($row, array $offer_counts, array $owned_provider_ids) {
  $item = myapi_service_request_build_item($row, $offer_counts);

  // The unit, only when the job is already mine — see the docblock. Compared
  // against the BUILT key and not the raw column, so a broken award closes it.
  $assigned = $item['assigned_provider'];
  $mine = $assigned !== NULL
    && in_array((int) $assigned['id'], array_map('intval', $owned_provider_ids), TRUE);

  if (!$mine) {
    $item['unit'] = NULL;
  }

  // A WHOLE null and never {id: null, name: null}, the shape `unit`,
  // `assigned_offer` and `assigned_provider` already use above. The requester's
  // join is INNER, so in practice this only fires on corrupt data.
  $requester = NULL;
  if (isset($row->requester_uid) && $row->requester_uid !== '') {
    $requester = [
      'id'   => (int) $row->requester_uid,
      'name' => isset($row->requester_name) ? $row->requester_name : NULL,
    ];
  }

  // Same shape myapi_service_request_build_detail() builds, so the listing and
  // the detail cannot answer two different things about the same condominium.
  // `name` is the NODE TITLE: the 'condominio' bundle has no name field.
  $condominium = NULL;
  if (isset($row->condominium_id) && $row->condominium_id !== '') {
    $condominium = [
      'id'   => (int) $row->condominium_id,
      'name' => isset($row->condominium_name) ? $row->condominium_name : NULL,
    ];
  }

  // Appended, never interleaved: the eleven keep their order and their place,
  // which is what makes "the first eleven are byte for byte the resident's"
  // checkable.
  $item['requester'] = $requester;
  $item['condominium'] = $condominium;

  return $item;
}

/* ---------------------------------------------------------------------------
 * The detail — GET /api/v1/service-requests/% (SPEC 89).
 *
 * The listing above answers eleven keys about many requests; this half answers
 * eighteen about one, and — for the first time in this module — answers them
 * DIFFERENTLY DEPENDING ON WHO ASKS. Two readers reach it: the resident who
 * created the request, and a provider of its category who may still bid on it.
 * The keys are always the same eighteen for both; three of them change content.
 *
 * Nothing here writes, and nothing here is tagged 'node_access' either — the
 * @file docblock's reasoning applies unchanged, and this half is where it
 * matters most: the access decision is myapi_service_request_viewer(), in one
 * place and in plain sight, and a node_access tag would add a second, invisible
 * one that contradicts it for a resident holding the 'proveedor' role.
 * ------------------------------------------------------------------------ */

/**
 * Dispatcher for /api/v1/service-requests/% (SPECS 89 and 96).
 *
 * A SIBLING of myapi_service_request_dispatch(), not a branch inside it: Drupal
 * 7 resolves routes by number of segments first, so the collection and the item
 * are two menu entries that never compete, and one dispatcher per route keeps
 * each one's 405 as simple as it looks.
 *
 * GET reads the detail (SPEC 89). Since SPEC 96, POST EDITS the request
 * (myapi_service_request_update()) — POST and not PUT because PHP fills neither
 * $_POST nor $_FILES on a PUT and an edit carries files, the same reason
 * POST /api/v1/claims/% made that choice in SPEC 67. Cancelling has its own
 * route (%/cancel, SPEC 95); closing, awarding and offering are still out.
 *
 * DELETE stays 405 and is not a synonym of the cancellation: a request is
 * cancelled, never deleted, and hiding that behind the verb would lose the
 * timeline entry the cancellation writes.
 *
 * The method is checked BEFORE the token and before the nid, same criterion as
 * every other dispatcher of this module: a PUT answers 405 without a token and
 * without a query, because the method is wrong whoever is asking and whatever
 * they are asking about.
 *
 * @param mixed $nid  The route's wildcard, RAW as Drupal hands it over — a
 *                    string, and not necessarily a number. Validating it is the
 *                    first job of whichever endpoint takes it.
 */
function myapi_service_request_item_dispatch($nid) {
  $method = myapi_request_method();

  if ($method === 'GET') {
    myapi_service_request_detail($nid);
  }
  elseif ($method === 'POST') {
    myapi_service_request_update($nid);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * GET /api/v1/service-requests/% — the detail of one request (SPEC 89).
 *
 * Orchestration only; every decision it makes lives in one of the helpers
 * below. In order: validate the nid, authenticate, load the row, resolve WHO IS
 * ASKING, and then the four loads and the serialisation.
 *
 * THE FIRST TWO STEPS ARE IN THIS ORDER ON PURPOSE. A '/service-requests/abc',
 * '/0' or '/-3' answers 404 without costing a single query — not even the
 * token's — because a wildcard that is not a positive integer cannot name a
 * request whoever is asking. Nothing is leaked by answering before the token:
 * the answer is about the SHAPE of the URL, not about what exists.
 *
 * 404 AND 403 MEAN DIFFERENT THINGS HERE, unlike in the listing, which simply
 * does not show a row:
 *
 *   404 not_found  — no such request: it does not exist, it is unpublished, it
 *                    is of another bundle, or its category tid is orphaned.
 *   403 forbidden  — it exists, and the reader is neither its requester nor a
 *                    provider who may still bid on it.
 *
 * That is the module's own idiom (GET /api/v1/claims/{id} answers the same way)
 * and the one that gives an actionable message: "this request no longer takes
 * offers" is useful to a provider, and the nid is not a secret — they reached
 * it from a listing that handed it to them.
 *
 * SIX CONTENT QUERIES, FIXED, plus the one the token costs. None of them grows
 * with the number of images, of offers or of transactions:
 *
 *   1. the request row, with unit, condominium, category, status, award and
 *      attachment                       — myapi_service_request_detail_row()
 *   2. the requester's name             — myapi_user_display_names()
 *   3. the images, in delta order       — myapi_service_request_load_images()
 *   4. offers_count                     — myapi_service_request_offer_counts_by_nid()
 *   5. the offers, with their provider  — myapi_service_request_load_offers()
 *   6. the timeline (SPEC 93)           — myapi_service_request_load_transactions()
 *
 * A provider reader adds the provider_role questions on top — which providers
 * they operate, their categories, whether any is active, whether one already
 * offered — all statically cached per uid by that include, and none of them
 * reached at all by a requester.
 *
 * offers_count COMES FROM QUERY 4 AND NEVER FROM count($offers), even though
 * for the requester the two numbers are identical: the provider receives a
 * trimmed list and the full total, so deriving it from the list would answer
 * them 1 or 0 where the key promises the total. Reusing SPEC 88's aggregate has
 * a second benefit — the bundle filter that stops 'service_transaction' rows
 * from being counted is already written and tested in there.
 *
 * @param mixed $nid  The route's wildcard, raw.
 */
function myapi_service_request_detail($nid) {
  // 1. The nid, before anything else. ctype_digit() over the string form
  // rejects 'abc', '-3', '1.5' and '' alike; the > 0 then rejects '0'.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 missing_authorization / invalid_token on its
  // own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The request itself. FALSE covers all four "no such request" cases at
  // once, and the reader is told none of them apart.
  $row = myapi_service_request_detail_row($nid);
  if (!$row) {
    myapi_error('not_found', 404);
  }

  // 4. WHO IS ASKING. The one function that decides, shared with the file
  // endpoint. NULL is a 403 and never a 404: the request does exist — step 3
  // just proved it.
  $viewer = myapi_service_request_viewer($row, $uid);
  if ($viewer === NULL) {
    myapi_error('forbidden', 403);
  }

  // 5. The requester's name, by the shared rule of includes/myapi.user.inc.
  // Written onto the row because that is what the serialiser reads; no contact
  // detail is fetched, for either reader.
  $row->requester_name = NULL;
  if (isset($row->requester_uid) && $row->requester_uid !== '') {
    $names = myapi_user_display_names([(int) $row->requester_uid]);
    $row->requester_name = isset($names[(int) $row->requester_uid])
      ? $names[(int) $row->requester_uid]
      : NULL;
  }

  // 6. The images, the total, and the offers. The trim is a query argument and
  // never a filter in PHP: an empty list means "every offer" and is exactly
  // what the requester passes.
  $images = myapi_service_request_load_images($nid);

  $offer_counts = myapi_service_request_offer_counts_by_nid([$nid]);
  $offers_count = isset($offer_counts[$nid]) ? $offer_counts[$nid] : 0;

  // Statically cached by the include: for a provider this was already resolved
  // inside myapi_service_request_viewer(), and for a requester it is skipped.
  $provider_ids = ($viewer === 'provider')
    ? myapi_provider_role_provider_ids((object) ['uid' => $uid])
    : [];

  $offers = array_map(
    'myapi_service_request_build_offer',
    myapi_service_request_load_offers($nid, $provider_ids)
  );

  // The timeline (SPEC 93). NOT TRIMMED BY READER: the provider of the
  // category receives it whole, comments included, the same as the requester.
  // The comments that exist today are written by SPEC 92 and are addressed to
  // the resident; cutting them back "just in case" would be inventing a
  // confidentiality rule no field marks and nobody can consult. It runs AFTER
  // the 403 above, so a reader who fits no rule never pays for this query.
  $transactions = myapi_service_request_load_transactions($nid);

  // 7. Respond 200 (read-only endpoint, no message_key). One object under
  // 'service_request', never a list.
  myapi_respond([
    'service_request' => myapi_service_request_build_detail($row, $viewer, $images, $offers, $offers_count, $transactions),
  ], 200);
}

/**
 * WHO IS ASKING: 'requester', 'provider', or NULL for a 403 (SPEC 89).
 *
 * The access rule of the detail and of its file endpoint, written ONCE. Both
 * routes call this same function; neither reimplements a condition of it. That
 * is not tidiness: a rule copied in two places drifts, and the shape the drift
 * takes here is a 403 on the detail that still lets the photos be downloaded,
 * or the reverse.
 *
 * Three rules, evaluated in this order, first hit wins:
 *
 *   1. THE REQUESTER — field_requester = uid. Whatever the status, whatever the
 *      reader's roles: a resident who also holds 'proveedor' reads their own
 *      request of a category they do not attend, complete.
 *   2. ALREADY OFFERED — one of the reader's providers has an offer on this
 *      request. Whatever the status, and whatever the category is TODAY: whoever
 *      has a live offer needs to see what became of it, and losing the detail
 *      the moment it is awarded to somebody else would leave an offer in their
 *      app with nothing behind it and a 403 as the only explanation.
 *   2b. AWARDED TO ME (SPEC 98) — field_assigned_provider points at one of the
 *      reader's providers. Whatever the status: 'direct', 'assigned', 'closed'
 *      and 'cancelled' all included. It is set C of
 *      myapi_service_request_provider_scope() — see the comment on the rule
 *      itself, and keep the two in step.
 *   3. AN ELIGIBLE PROVIDER — the request is still biddable AND of one of the
 *      reader's categories AND at least one of their providers is active:
 *        status ∈ ('open', 'offered')
 *        AND field_assigned_offer is empty AND field_assigned_provider is empty
 *        AND field_category_tid ∈ myapi_provider_role_category_ids()
 *        AND myapi_provider_role_any_provider_active()
 *
 * Anything else is NULL, and the caller answers 403. NULL never means "not
 * found": a request that does not exist or is unpublished never reaches this
 * function — myapi_service_request_detail_row() answered 404 first.
 *
 * WHY THIS DOES NOT CALL myapi_provider_role_broadcast_statuses(), even though
 * that function exists and looks like exactly this list: it includes 'direct',
 * and rule 3 must not. They are TWO POLICIES OVER THE SAME DATUM — "what do I
 * hide from this provider in the back office" versus "what may they still bid
 * on in the app" — and they have to be able to diverge. A 'direct' request is
 * born with a provider already chosen, which is precisely what "unawarded"
 * excludes. SPEC 89 left the consequence written in its Riesgos — the provider
 * of a 'direct' request could not read its own detail — and SPEC 98 is the
 * spec that knew what relation that provider has with the request: rule 2b,
 * above rule 3 rather than a fourth clause inside it, because "awarded to me"
 * is the opposite of "unawarded" and cannot be a condition of the same rule.
 *
 * BOTH AWARD KEYS ARE CHECKED, NOT JUST THE STATUS, and they are read from the
 * RAW target_id and not from the joined node: a request left in 'offered' with
 * field_assigned_offer already filled in — an incoherent datum nothing prevents
 * today — stops being biddable, which is the safe reading. Reading the joined
 * nid instead would make an award pointing at an unpublished offer look like no
 * award at all, and reopen the request to every provider of the category.
 *
 * The account handed to myapi_provider_role_* is built from the uid alone. Those
 * four functions read ->uid and nothing else — provider_ids is a REVERSE lookup
 * over field_provider_users, not a role read — so this costs no user_load(), and
 * the reader's ROLE is never consulted: what makes somebody a provider here is a
 * 'provider' node pointing at them, not the label on their account. A user
 * holding the 'proveedor' role with no provider node behind it gets an empty
 * provider_ids and falls straight through to NULL. If a future change made those
 * functions read roles, a synthetic account with none would deny, never grant.
 *
 * Costs NO query for the requester — rule 1 is decided on a column already in
 * $row. The provider's path costs the provider_role queries, all statically
 * cached per uid.
 *
 * @param object $row
 *   The row of myapi_service_request_detail_row(), carrying `nid`,
 *   `requester_uid`, `status`, `category_id` and the two RAW award columns
 *   `assigned_offer_raw` and `assigned_provider_raw`.
 * @param mixed $uid
 *   The authenticated reader's uid, from the token row.
 *
 * @return string|NULL
 *   'requester', 'provider', or NULL when neither rule applies.
 */
function myapi_service_request_viewer($row, $uid) {
  $uid = (int) $uid;

  if ($uid <= 0 || !is_object($row)) {
    return NULL;
  }

  // Rule 1. field_requester and NOT node.uid, the same criterion the listing
  // scope uses: node.uid is the operator who loaded the request.
  if (isset($row->requester_uid) && (int) $row->requester_uid === $uid) {
    return 'requester';
  }

  // From here on the reader can only get in as a provider, and everything below
  // needs the providers they operate. None: nothing else to ask.
  $account = (object) ['uid' => $uid];
  $provider_ids = myapi_provider_role_provider_ids($account);

  if (!$provider_ids) {
    return NULL;
  }

  // Rule 2. Status-independent and category-independent, on purpose.
  if (myapi_provider_role_has_offered(isset($row->nid) ? $row->nid : 0, $provider_ids)) {
    return 'provider';
  }

  // Rule 2b (SPEC 98). The request is awarded to one of my providers: it is my
  // job, and it is my job in ANY status — 'direct', 'assigned', 'closed' and
  // 'cancelled' included. This closes the 403 SPEC 89 left written in its
  // Riesgos: until today the provider of a 'direct' request could not read its
  // own detail, because rule 3 requires the request to be UNAWARDED, which is
  // the exact opposite of this.
  //
  // It reads assigned_provider_raw, the raw column
  // myapi_service_request_detail_row() already projects and rule 3 below
  // already reads: NO new query and NO new column. It goes BEFORE rule 3 for
  // the reason just given, and it is STRICTLY ADDITIVE — it can only turn a
  // NULL (403) into 'provider', never the reverse, so no reader loses access
  // and the requester never reaches it.
  //
  // THIS IS SET C OF myapi_service_request_provider_scope(), WRITTEN AS A
  // PER-ROW DECISION. The two forms live side by side because neither can be
  // derived from the other without losing the pagination or the loaded row; if
  // one of them ever grows a condition the other does not, the symptom is an
  // item in the provider listing that answers 403 when opened, and nothing
  // fails loudly. ServiceRequestProviderListTest holds the equivalence.
  if (!empty($row->assigned_provider_raw)
    && in_array((int) $row->assigned_provider_raw, array_map('intval', $provider_ids), TRUE)) {
    return 'provider';
  }

  // Rule 3, condition by condition. The list of statuses is built from the
  // catalogue constants, never typed as strings, and is deliberately NOT
  // myapi_provider_role_broadcast_statuses() — see the docblock.
  $biddable = [
    MYAPI_SERVICES_REQUEST_STATUS_OPEN,
    MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
  ];

  if (!isset($row->status) || !in_array($row->status, $biddable, TRUE)) {
    return NULL;
  }

  // Awarded by either key — even inconsistently — is awarded.
  if (!empty($row->assigned_offer_raw) || !empty($row->assigned_provider_raw)) {
    return NULL;
  }

  if (!isset($row->category_id) || $row->category_id === '') {
    return NULL;
  }

  $categories = array_map('intval', myapi_provider_role_category_ids($account));

  if (!in_array((int) $row->category_id, $categories, TRUE)) {
    return NULL;
  }

  // Last because it is the most expensive of the three and the least likely to
  // be the one that fails: a suspended provider — unpublished node or expired
  // licence — loses the marketplace, which is what suspending them means.
  if (!myapi_provider_role_any_provider_active($account)) {
    return NULL;
  }

  return 'provider';
}

/**
 * The whole request in ONE query: the row the detail is built from (SPEC 89).
 *
 * Starts from myapi_service_request_base_query() WITH NO uid — see that
 * function's docblock — so what counts as an existing request is decided in one
 * place for the listing and for the detail. A nid that answers no row is a 404,
 * and the three reasons are indistinguishable on purpose: it does not exist, it
 * is unpublished, or it is of another bundle. The reader learns "no such
 * request", which is all three.
 *
 * It projects the thirteen aliases of the listing item PLUS the nine the detail
 * adds. The overlap with myapi_service_request_fetch() is projection, not
 * decision: the fetch pages and orders a listing, this one reads a single row,
 * and the ONE thing the two must agree on — which requests are real — is the
 * base query they share. SPEC 89 deliberately changed nothing else in the
 * fetch.
 *
 * THE AWARDED PAIR IS PROJECTED TWICE, resolved and raw, and the second is not
 * redundant:
 *
 *   assigned_offer_id / assigned_provider_id — the JOINED node's nid, NULL when
 *     the reference points at an unpublished or deleted node. This is what the
 *     response paints, exactly as the listing does.
 *   assigned_offer_raw / assigned_provider_raw — the field's target_id, however
 *     broken. This is what myapi_service_request_viewer() reads to decide
 *     whether the request is still biddable: an award pointing at an
 *     unpublished offer must close the request to new providers, and reading
 *     the resolved nid would reopen it.
 *
 * EVERY JOIN ADDED HERE IS LEFT, the same rule the fetch follows and for the
 * same reason: losing your own request because a reference is broken is the
 * worst failure a read endpoint can have. The category is the one exception,
 * and it is inherited from the base query — an orphan tid answers 404 here and
 * a missing row in the listing, which is the coherent pair. docs/service-
 * request.md carries the query that diagnoses it.
 *
 * The unit's name is field_nombre_vivienda and NOT the 'vivienda' node title,
 * the same value GET /api/v1/units answers as `name`. The condominium's name IS
 * the node title, which is what that bundle carries.
 *
 * No join can multiply rows: every field joined has cardinality 1, and
 * node.nid / file_managed.fid are primary keys. field_images is the one
 * unlimited field of this bundle and it is deliberately NOT here — it has its
 * own query, myapi_service_request_load_images().
 *
 * THE QUERY CARRIES NO ->addTag('node_access'), like every other query of this
 * file. See the @file docblock.
 *
 * @param mixed $nid  The request nid, already validated as a positive integer
 *                    by the caller.
 *
 * @return object|FALSE  The row, or FALSE when there is no such request.
 */
function myapi_service_request_detail_row($nid) {
  // NULL: the detail's reader may be a provider, not the requester.
  $query = myapi_service_request_base_query(NULL);
  $query->condition('n.nid', (int) $nid);

  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'title', 'title');
  $query->addField('n', 'created', 'created');

  // The category, off the two INNER JOINs the base query already made, plus the
  // code — LEFT, like in the listing's fetch and for the same reason: a term
  // with no field_category_code answers code "" instead of dropping the row.
  $query->addField('fcat', 'field_category_tid', 'category_id');
  $query->addField('td', 'name', 'category_name');
  $query->leftJoin('field_data_field_category_code', 'cc', "cc.entity_id = td.tid AND cc.entity_type = 'taxonomy_term' AND cc.deleted = 0");
  $query->addField('cc', 'field_category_code_value', 'category_code');

  $query->addField('frs', 'field_request_status_value', 'status');

  // The requester: the uid off the join the base query already made, so the
  // access rule costs no query of its own for a resident.
  $query->addField('fr', 'field_requester_target_id', 'requester_uid');

  $query->leftJoin('field_data_field_description', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->addField('fd', 'field_description_value', 'description');

  $query->leftJoin('field_data_field_desired_start', 'fds', "fds.entity_id = n.nid AND fds.entity_type = 'node' AND fds.deleted = 0");
  $query->addField('fds', 'field_desired_start_value', 'desired_start');

  $query->leftJoin('field_data_field_closed_at', 'fca', "fca.entity_id = n.nid AND fca.entity_type = 'node' AND fca.deleted = 0");
  $query->addField('fca', 'field_closed_at_value', 'closed_at');

  // The unit (SPEC 86). The reference itself comes from the base query since
  // SPEC 91 — the listing's '?unit_id' filter reads it there — so what is left
  // here is the second hop: the 'vivienda' node and its name field. The node
  // join carries the bundle so a reference to something else can never paint
  // its name here. Joining field_data_field_unit again would not be wrong, it
  // would be a second alias for a table already in the query.
  $query->leftJoin('node', 'nu', "nu.nid = fu.field_unit_target_id AND nu.type = 'vivienda' AND nu.status = 1");
  $query->addField('nu', 'nid', 'unit_id');
  $query->leftJoin('field_data_field_nombre_vivienda', 'fnv', "fnv.entity_id = nu.nid AND fnv.entity_type = 'node' AND fnv.deleted = 0");
  $query->addField('fnv', 'field_nombre_vivienda_value', 'unit_name');

  // The condominium. Its name is the node title, unlike the unit's.
  $query->leftJoin('field_data_field_condominium', 'fco', "fco.entity_id = n.nid AND fco.entity_type = 'node' AND fco.deleted = 0");
  $query->leftJoin('node', 'nc', "nc.nid = fco.field_condominium_target_id AND nc.type = 'condominio' AND nc.status = 1");
  $query->addField('nc', 'nid', 'condominium_id');
  $query->addField('nc', 'title', 'condominium_name');

  // The attachment: cardinality 1, so it is a column of this row and not a
  // query of its own. INNER on file_managed would drop the whole REQUEST if the
  // file had been deleted from disk, so it is LEFT like everything else and the
  // serialiser answers attachment: null.
  $query->leftJoin('field_data_field_attachment', 'fat', "fat.entity_id = n.nid AND fat.entity_type = 'node' AND fat.deleted = 0");
  $query->leftJoin('file_managed', 'fma', 'fma.fid = fat.field_attachment_fid');
  $query->addField('fma', 'fid', 'attachment_fid');
  $query->addField('fma', 'filename', 'attachment_filename');

  // The awarded offer, resolved exactly as the listing resolves it...
  $query->leftJoin('field_data_field_assigned_offer', 'fao', "fao.entity_id = n.nid AND fao.entity_type = 'node' AND fao.deleted = 0");
  $query->leftJoin('node', 'no', 'no.nid = fao.field_assigned_offer_target_id AND no.type = :offer_type AND no.status = 1', [':offer_type' => MYAPI_SERVICES_OFFER_TYPE]);
  $query->addField('no', 'nid', 'assigned_offer_id');
  $query->leftJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->addField('fos', 'field_offer_status_value', 'assigned_offer_status');

  // ...and the awarded provider on its own, never through the offer: a 'direct'
  // request has a provider and no offer at all (SPEC 87).
  $query->leftJoin('field_data_field_assigned_provider', 'fap', "fap.entity_id = n.nid AND fap.entity_type = 'node' AND fap.deleted = 0");
  $query->leftJoin('node', 'np', 'np.nid = fap.field_assigned_provider_target_id AND np.type = :provider_type AND np.status = 1', [':provider_type' => MYAPI_SERVICES_PROVIDER_TYPE]);
  $query->addField('np', 'nid', 'assigned_provider_id');
  $query->addField('np', 'title', 'assigned_provider_name');

  // The raw target_ids, for the access rule only — see the docblock. They are
  // read off the field rows, so a broken reference still reads as "awarded".
  $query->addField('fao', 'field_assigned_offer_target_id', 'assigned_offer_raw');
  $query->addField('fap', 'field_assigned_provider_target_id', 'assigned_provider_raw');

  $query->range(0, 1);

  return $query->execute()->fetchObject();
}

/**
 * The images of one request, in ONE query and in delta order (SPEC 89).
 *
 * The shape of myapi_claim_load_images() with one request instead of a page:
 * field_images is the only unlimited field of this bundle, so it cannot ride in
 * myapi_service_request_detail_row() without multiplying that row by the number
 * of photos.
 *
 * INNER and not LEFT on file_managed, same as claims: a field row pointing at a
 * file that no longer exists has no URL to offer, and an entry with a null url
 * would be worse for the client than no entry at all.
 *
 * ORDER BY delta — the order the operator uploaded them in, which is the order
 * the app paints them. Nothing else would be stable.
 *
 * @param mixed $nid  The request nid.
 *
 * @return array  A list of myapi_service_request_build_file() items. Empty when
 *                the request has no images — never NULL.
 */
function myapi_service_request_load_images($nid) {
  $nid = (int) $nid;

  $query = db_select('field_data_field_images', 'fi');
  $query->condition('fi.entity_type', 'node');
  $query->condition('fi.deleted', 0);
  $query->condition('fi.entity_id', $nid);
  $query->innerJoin('file_managed', 'fm', 'fm.fid = fi.field_images_fid');
  $query->addField('fm', 'fid', 'fid');
  $query->addField('fm', 'filename', 'filename');
  $query->orderBy('fi.delta', 'ASC');

  $images = [];
  foreach ($query->execute() as $row) {
    $images[] = myapi_service_request_build_file($row->fid, $row->filename, $nid);
  }

  return $images;
}

/**
 * The offers of one request, in ONE query, with their provider (SPEC 89).
 *
 * ONE QUERY WHATEVER THE NUMBER OF OFFERS, and never a node_load() per offer:
 * the provider's title and logo ride along as joined columns, exactly as the
 * listing resolves its awarded provider.
 *
 * $provider_ids IS THE PROVIDER'S TRIM, AND IT IS MADE HERE AND NOT IN PHP.
 * Empty means "every offer of this request" — the requester's answer; a
 * non-empty list narrows field_provider to the reader's own provider nodes, so
 * a provider gets zero offers or their own (two, if they operate two provider
 * nodes that both bid). Filtering afterwards would mean loading every
 * competitor's amount and message into memory in order to drop them, and the
 * whole point of the trim is that those never reach the process.
 *
 * The condition sits on the LEFT-joined field_data_field_provider, so an offer
 * with no provider at all is excluded from a provider's trimmed list and kept
 * in the requester's — which is the right answer for both.
 *
 * THE INNER JOIN TO node IS WHAT MAKES THE LIST AGREE WITH offers_count.
 * field_request is SHARED by 'service_offer' and 'service_transaction' (SPEC
 * 77), so without the bundle condition the timeline entries of the request
 * would be listed as offers. `status = 1` is there for the same reason
 * myapi_service_request_offer_counts_by_nid() has it: an unpublished offer must
 * neither be listed nor counted, and the two functions have to make that call
 * identically or the number and the list contradict each other.
 *
 * EVERY PUBLISHED OFFER TRAVELS, WHATEVER ITS STATUS — 'sent', 'selected',
 * 'rejected' and 'withdrawn' alike — which is exactly what offers_count counts.
 *
 * ORDER: created DESC, nid DESC. The most recent on top, and the tie-break is
 * not decoration — two offers of the same second swap places between two reads
 * of the same screen without it, the trap SPEC 83 and SPEC 88 documented.
 *
 * @param mixed $nid           The request nid.
 * @param array $provider_ids  The reader's provider nids, from
 *                             myapi_provider_role_provider_ids(). EMPTY MEANS
 *                             NO TRIM (the requester), never "no offers".
 *
 * @return array  Rows carrying `nid`, `provider_id`, `provider_name`,
 *                `provider_logo_uri`, `amount`, `message`, `status` and
 *                `created` — the aliases myapi_service_request_build_offer()
 *                reads.
 */
function myapi_service_request_load_offers($nid, array $provider_ids) {
  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);
  $query->condition('fq.field_request_target_id', (int) $nid);

  // INNER, and the bundle and the published flag as conditions — same shape,
  // same reasons, as myapi_service_request_offer_counts_by_nid().
  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);
  $query->addField('no', 'nid', 'nid');
  $query->addField('no', 'created', 'created');

  // The provider, in three LEFT hops: the reference, the node, its logo file.
  // The node join carries the bundle and status = 1, so an unpublished provider
  // leaves provider_id NULL and the offer STAYS in the list — dropping it would
  // make the list disagree with offers_count.
  $query->leftJoin('field_data_field_provider', 'fp', "fp.entity_id = no.nid AND fp.entity_type = 'node' AND fp.deleted = 0");
  $query->leftJoin('node', 'np', 'np.nid = fp.field_provider_target_id AND np.type = :provider_type AND np.status = 1', [':provider_type' => MYAPI_SERVICES_PROVIDER_TYPE]);
  $query->addField('np', 'nid', 'provider_id');
  $query->addField('np', 'title', 'provider_name');

  // The logo is public:// (SPEC 85), so what travels is the uri and the
  // serialiser turns it into a direct URL. Both hops LEFT: no logo, or a
  // file_managed row that no longer exists, answers null.
  $query->leftJoin('field_data_field_logo', 'lg', "lg.entity_id = np.nid AND lg.entity_type = 'node' AND lg.deleted = 0");
  $query->leftJoin('file_managed', 'fml', 'fml.fid = lg.field_logo_fid');
  $query->addField('fml', 'uri', 'provider_logo_uri');

  $query->leftJoin('field_data_field_offer_amount', 'foa', "foa.entity_id = no.nid AND foa.entity_type = 'node' AND foa.deleted = 0");
  $query->addField('foa', 'field_offer_amount_value', 'amount');

  $query->leftJoin('field_data_field_offer_message', 'fom', "fom.entity_id = no.nid AND fom.entity_type = 'node' AND fom.deleted = 0");
  $query->addField('fom', 'field_offer_message_value', 'message');

  $query->leftJoin('field_data_field_offer_status', 'fost', "fost.entity_id = no.nid AND fost.entity_type = 'node' AND fost.deleted = 0");
  $query->addField('fost', 'field_offer_status_value', 'status');

  // The trim. Empty list = no condition at all, and never an "IN ()", which is
  // invalid SQL in Drupal 7.
  if ($provider_ids) {
    $query->condition('fp.field_provider_target_id', $provider_ids, 'IN');
  }

  $query->orderBy('no.created', 'DESC');
  $query->orderBy('no.nid', 'DESC');

  return $query->execute()->fetchAll();
}

/**
 * Loads the timeline of one request: its published transactions (SPEC 93).
 *
 * ONE QUERY, and the only one this key ever costs. The five values of every
 * entry come out of the four joins below, and there is nothing to load apart
 * afterwards — no images, no attachment — which is exactly what forces a
 * second query in myapi_claim_load_transactions(). A request with twenty
 * transactions costs the same as one with a single one.
 *
 * TAKES A nid AND NOT AN ARRAY, unlike its counterpart in claims. That one
 * loads a whole page of claims and therefore needs the IN (...) and the map
 * keyed by claim_id; here there is one request, and copying the signature
 * would bring an array of one element and an ungrouping nobody uses.
 *
 * n.type = 'service_transaction' IS MANDATORY, NOT DECORATION. field_request
 * is SHARED with 'service_offer' (SPEC 77), so without the bundle condition
 * the timeline would list the request's offers as if they were transactions —
 * each one with status, status_date and comment at NULL, which reads like a
 * broken transaction and not like a wrong query. It is the same rule SPEC 92
 * wrote for the opposite direction (offers_count counting transactions),
 * applied from this side.
 *
 * n.status = 1: published only. A transaction unpublished from the back office
 * disappears from the app, which is what unpublishing means.
 *
 * THE THREE FIELD JOINS ARE LEFT EVEN THOUGH TWO OF THE FIELDS ARE REQUIRED.
 * A field required at instance level can still end up without a row if someone
 * deletes it by hand; with INNER the whole transaction would vanish from the
 * timeline instead of coming out with a NULL, which is visible and
 * diagnosable. Same criterion myapi_service_request_detail_row() already
 * applies to requester_uid.
 *
 * ORDER: field_status_date_value ASC, n.nid ASC. It is a timeline, read from
 * the oldest to the newest. The tie-break by ascending nid makes two
 * transactions of the same minute — and there are, when an operator registers
 * two changes in a row — come out in the order they were created and not in
 * the one MySQL decides. A literal copy of the claims order.
 *
 * NOT TAGGED WITH node_access, inherited from SPEC 88/89: access was already
 * decided by myapi_service_request_viewer() over the request, and the
 * transaction has no access of its own.
 *
 * status_date DOES NOT GO THROUGH strtotime(), AND THAT IS NOT A DETAIL.
 * field_status_date is the very same field shared with 'claim_transaction'
 * (SPEC 77), created with tz_handling = 'none' in SPEC 55: what is stored is a
 * naive local time, not a UTC instant. Converting it would shift it by the
 * server's zone and answer an hour nobody wrote — the bug SPEC 58 already paid
 * for once. created IS a real timestamp, and that is why it does go through
 * format_date(): two columns, two rules, on purpose.
 *
 * @param mixed $nid  The request nid.
 *
 * @return array  A list of transactions, already ordered and already shaped
 *                into their five keys — id, status, status_date, comment,
 *                created — or [] when there is none.
 */
function myapi_service_request_load_transactions($nid) {
  if (!is_scalar($nid) || (int) $nid <= 0) {
    return [];
  }

  $query = db_select('node', 'n');
  $query->condition('n.type', MYAPI_SERVICES_TRANSACTION_TYPE);
  $query->condition('n.status', 1);
  $query->addField('n', 'nid', 'id');
  $query->addField('n', 'created', 'created');

  $query->innerJoin('field_data_field_request', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->condition('fr.field_request_target_id', (int) $nid);

  $query->leftJoin('field_data_field_request_status', 'frs', "frs.entity_id = n.nid AND frs.entity_type = 'node' AND frs.deleted = 0");
  $query->addField('frs', 'field_request_status_value', 'status');

  $query->leftJoin('field_data_field_status_date', 'fsd', "fsd.entity_id = n.nid AND fsd.entity_type = 'node' AND fsd.deleted = 0");
  $query->addField('fsd', 'field_status_date_value', 'status_date');

  $query->leftJoin('field_data_field_comment', 'fcm', "fcm.entity_id = n.nid AND fcm.entity_type = 'node' AND fcm.deleted = 0");
  $query->addField('fcm', 'field_comment_value', 'comment');

  $query->orderBy('fsd.field_status_date_value', 'ASC');
  $query->orderBy('n.nid', 'ASC');

  $transactions = [];
  foreach ($query->execute()->fetchAll() as $row) {
    $transactions[] = [
      'id'          => (int) $row->id,
      // The raw catalogue key — 'open', 'direct', 'assigned'… — exactly like
      // the status the listing and the detail already serve. No label: serving
      // the translation in one place and not in the other two would force the
      // client to know which of the two sources to use.
      'status'      => $row->status,
      // The stored value with a 'T' in it. See the note above: no strtotime().
      'status_date' => $row->status_date === NULL ? NULL : str_replace(' ', 'T', $row->status_date),
      'comment'     => $row->comment,
      'created'     => format_date((int) $row->created, 'custom', 'Y-m-d\TH:i:s'),
    ];
  }

  return $transactions;
}

/**
 * Shapes one private file into {id, url, filename}, or NULL (SPEC 89).
 *
 * A copy of myapi_claim_build_file() in shape and in reasoning, pointed at this
 * bundle's route: field_images and field_attachment are private:// at the field
 * level since SPEC 77, so the URL that travels is the authenticated endpoint of
 * this module — GET /api/v1/service-requests/{id}/files/{fid} — and never a
 * system/files/... path, which the app could not open with a Bearer token.
 *
 * ABSOLUTE, because the app has no base URL of its own to resolve a relative
 * path against.
 *
 * PURE: no database. The fid and the filename arrive already read.
 *
 * @param mixed  $fid          file_managed.fid, or NULL for "no file".
 * @param mixed  $filename     file_managed.filename.
 * @param mixed  $request_nid  The request the route is built around. It is the
 *                             {id} of the URL, and the file endpoint checks the
 *                             fid really belongs to it.
 *
 * @return array|NULL  ['id' => int, 'url' => string, 'filename' => string], or
 *                     NULL when there is no file.
 */
function myapi_service_request_build_file($fid, $filename, $request_nid) {
  if ($fid === NULL || $fid === '') {
    return NULL;
  }

  return [
    'id'       => (int) $fid,
    'url'      => url('api/v1/service-requests/' . (int) $request_nid . '/files/' . (int) $fid, ['absolute' => TRUE]),
    'filename' => $filename,
  ];
}

/**
 * Shapes one offer row into the item of the `offers` list (SPEC 89).
 *
 * ALWAYS THE SAME SIX KEYS, IN THIS ORDER — id, provider, amount, message,
 * status, created — whatever the data. PURE: no database, no node_load().
 *
 * `provider` IS THE WHOLE OBJECT OR NULL, AND THE OFFER STAYS IN THE LIST
 * EITHER WAY. The provider is resolved through a LEFT JOIN restricted to
 * published 'provider' nodes, so an unpublished or deleted one leaves this NULL
 * — and dropping the offer instead would make `offers` disagree with
 * `offers_count`, which counts it. The same distinction
 * myapi_service_request_build_item() makes with the awarded pair.
 *
 * `logo` IS AN ABSOLUTE, DIRECT URL, unlike the request's own images: field_logo
 * is public:// (SPEC 85), so it is served by the web server and needs no token.
 * Same guard as myapi_provider_build_item() — isset() AND !== '' — so a provider
 * with no logo, or one whose field row points at a file_managed that no longer
 * exists, answers NULL and never "" nor a broken URL.
 *
 * `amount` IS A FLOAT OR NULL, NEVER "95.50" AND NEVER 0.0. The field is
 * optional by SPEC 77's decision — the price can be settled in the chat — and 0
 * is a price somebody offered, not a missing one. Same rule as `hourly_rate` in
 * GET /api/v1/providers.
 *
 * `message` travels AS STORED, with the line breaks the provider typed, exactly
 * like `description` in the listing item. The instance pins the 'plain_text'
 * format (SPEC 77); the note in docs/service-request.md says what depends on
 * that.
 *
 * @param object $row
 *   One row of myapi_service_request_load_offers(), carrying `nid`,
 *   `provider_id`, `provider_name`, `provider_logo_uri`, `amount`, `message`,
 *   `status` and `created`.
 *
 * @return array  The six keys above, in that order.
 */
function myapi_service_request_build_offer($row) {
  $provider = NULL;
  if (isset($row->provider_id) && $row->provider_id !== '') {
    $provider = [
      'id'   => (int) $row->provider_id,
      'name' => isset($row->provider_name) ? $row->provider_name : NULL,
      'logo' => isset($row->provider_logo_uri) && $row->provider_logo_uri !== ''
        ? file_create_url($row->provider_logo_uri)
        : NULL,
    ];
  }

  return [
    'id'       => (int) $row->nid,
    'provider' => $provider,
    'amount'   => isset($row->amount) && $row->amount !== ''
      ? (float) $row->amount
      : NULL,
    'message'  => isset($row->message) ? $row->message : '',
    'status'   => isset($row->status) ? $row->status : NULL,
    'created'  => isset($row->created)
      ? format_date((int) $row->created, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
  ];
}

/**
 * Shapes the whole detail item, for either reader (SPEC 89).
 *
 * THE TEN FIRST KEYS ARE THE LISTING'S, PRODUCED BY THE LISTING'S OWN
 * SERIALISER. This function calls myapi_service_request_build_item() and adds
 * eight keys after it; it does not restate one of them. That is what makes
 * "the ten first keys are byte for byte what GET /api/v1/service-requests
 * answers for that request" an acceptance criterion this file cannot break by
 * accident, and it is why SPEC 88's response does not change by a single key.
 *
 * NINETEEN KEYS, ALWAYS, FOR BOTH READERS. Nothing appears and nothing
 * disappears with the data or with who is asking: a null is an answer, an absent
 * key is a question, and a client that has to test for the existence of a key
 * before reading it is a client that will forget to. (The spec's prose says
 * "seventeen" in a couple of places and then lists eight new keys next to the
 * listing's ten; the enumeration is the part that is unambiguous, and this is
 * it. Since SPEC 91 the arithmetic reads differently and the total does not
 * move: `unit` became the listing's eleventh key, so the detail adds seven.
 * SPEC 93 counts "seventeen to eighteen" from that same prose and adds exactly
 * one key, `transactions`; the enumeration below is again the unambiguous
 * part, and it is eleven plus eight.)
 *
 * THE PROVIDER'S TRIM LIVES HERE AND NOWHERE ELSE, and it is exactly two keys:
 *
 *   viewer : 'requester' | 'provider' — the reason the trim is legible at all.
 *            Without it, `unit: null` cannot be told apart from "this request
 *            has no unit", and the client cannot decide whether to paint "make
 *            an offer" or "award". Deducing the role by comparing requester.id
 *            with the token's uid would work today and would put the server's
 *            access rule inside the app.
 *   unit   : NULL for the provider. To decide whether to bid they need the
 *            category, the description, the photos, the desired date and the
 *            condominium; the flat number adds nothing to that decision and does
 *            say where a specific person lives, to anyone of the category.
 *            Since SPEC 91 this key arrives ALREADY BUILT from
 *            myapi_service_request_build_item() — the listing answers it too —
 *            so what happens here is an OVERWRITE and no longer a second
 *            resolution of the same two columns. It is the one key of the
 *            listing the detail touches, and the resolving rule stays in one
 *            place.
 *
 * Everything else the provider receives whole, `requester {id, name}` and
 * `condominium` included, and `offers_count` is the FULL count and not the size
 * of the trimmed list — the caller passes it in from
 * myapi_service_request_offer_counts_by_nid() precisely so this cannot be
 * derived from `count($offers)`, which for a provider would always be 0 or 1.
 * The third difference between the two readers — `offers` trimmed to the
 * reader's own — is made by the QUERY, not here: filtering in PHP would mean
 * loading every competitor's amount into memory to then drop it.
 *
 * NO CONTACT DETAILS TRAVEL, for either reader: `requester` is {id, name} and
 * nothing else. myapi_user_fetch_profile_fields() is not called from this
 * resource — the name says who you would be dealing with, the phone and the
 * email are contact data and belong to no read endpoint of this bundle.
 *
 * PURE: no database, no node_load(), no $_GET. Every input arrives resolved.
 *
 * @param object $row
 *   The row of myapi_service_request_detail_row(), carrying the fifteen aliases
 *   myapi_service_request_build_item() reads — `unit_id` and `unit_name` among
 *   them since SPEC 91 — plus `requester_uid`, `requester_name` (written onto
 *   the row by the caller after myapi_user_display_names()), `condominium_id`,
 *   `condominium_name`, `attachment_fid`, `attachment_filename` and
 *   `closed_at`.
 * @param string $viewer
 *   'requester' or 'provider', from myapi_service_request_viewer().
 * @param array $images
 *   The list of myapi_service_request_build_file() items, in delta order.
 *   Always an array, empty when there are none.
 * @param array $offers
 *   The list of myapi_service_request_build_offer() items, already ordered and
 *   already trimmed to the reader by the query. Always an array.
 * @param mixed $offers_count
 *   The TOTAL number of published offers of the request, from
 *   myapi_service_request_offer_counts_by_nid().
 * @param array $transactions
 *   The timeline, from myapi_service_request_load_transactions(): already
 *   ordered and already shaped into its five keys. Always an array, empty for
 *   a request born before SPEC 92.
 *
 * @return array  The nineteen keys, in order.
 */
function myapi_service_request_build_detail($row, $viewer, array $images, array $offers, $offers_count, array $transactions) {
  $nid = (int) $row->nid;

  // The eleven keys of the listing, from the listing's own serialiser. The
  // count is handed over as the one-entry map that function expects.
  $item = myapi_service_request_build_item($row, [$nid => (int) $offers_count]);

  // Half of the provider's trim, and the only key of the listing this function
  // overwrites instead of adding — see the docblock. NULL here is a DECISION
  // and not a missing datum, which is what `viewer` below makes readable.
  if ($viewer === 'provider') {
    $item['unit'] = NULL;
  }

  // Required on the bundle, but LEFT-joined: a field row deleted by hand
  // answers null rather than a PHP notice in the middle of the response.
  $requester = NULL;
  if (isset($row->requester_uid) && $row->requester_uid !== '') {
    $requester = [
      'id'   => (int) $row->requester_uid,
      'name' => isset($row->requester_name) ? $row->requester_name : NULL,
    ];
  }

  $condominium = NULL;
  if (isset($row->condominium_id) && $row->condominium_id !== '') {
    $condominium = [
      'id'   => (int) $row->condominium_id,
      'name' => isset($row->condominium_name) ? $row->condominium_name : NULL,
    ];
  }

  return array_merge($item, [
    'viewer'      => $viewer,
    'requester'   => $requester,
    'condominium' => $condominium,
    // Always an array, never null: "no photos" is an empty list, and a client
    // that can iterate has nothing to branch on.
    'images'      => array_values($images),
    'attachment'  => myapi_service_request_build_file(
      isset($row->attachment_fid) ? $row->attachment_fid : NULL,
      isset($row->attachment_filename) ? $row->attachment_filename : NULL,
      $nid
    ),
    // A datestamp like desired_start, so format_date() and not the raw column.
    // NULL — and not format_date(0), a 1970 nobody typed — while the request is
    // not closed, which is every request that is not 'closed'.
    'closed_at'   => isset($row->closed_at) && $row->closed_at !== ''
      ? format_date((int) $row->closed_at, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
    'offers'      => array_values($offers),
    // Always an array too, and for the same reason as `images`: a request born
    // before SPEC 92 has no timeline, and [] says that without the client
    // having to test whether the key is there. No backfill invents a row for
    // it — the app treats the empty list as a normal state, exactly as it
    // treats `offers: []`.
    'transactions' => array_values($transactions),
  ]);
}

/**
 * Shapes the whole detail item FOR THE PROVIDER: the listing's thirteen keys
 * plus six (SPEC 99).
 *
 * THE SIBLING OF myapi_service_request_build_detail(), NOT A BRANCH INSIDE IT.
 * The two answer the same node to two different readers, and SPEC 99's
 * decision 1 is that they do it from two functions: folding this into the one
 * above would have meant an `if` picking between `offers` and `my_offers` and
 * between two `unit` rules, which is exactly the shape SPEC 89 split the
 * dispatchers to avoid.
 *
 * THE THIRTEEN COME FROM myapi_service_request_provider_build_item(),
 * UNTOUCHED — the same construction-by-delegation SPEC 97 and 98 used, one
 * level further down. That function already owns the provider's `unit` rule,
 * the `requester` and the `condominium`, so none of the three is restated
 * here, and "the thirteen first keys are byte for byte what
 * GET /api/v1/service-requests/provider answers for that nid" is an acceptance
 * criterion this file cannot break by accident.
 *
 * THE UNIT RULE IS NOT WRITTEN HERE, AND THAT IS THE POINT. The detail no
 * longer contradicts the listing about the same nid — SPEC 98's risk 4 — for
 * the structural reason that both read it out of the same function. Note the
 * inversion against myapi_service_request_build_detail(), which OVERWRITES
 * `unit` to NULL for every provider reader: that is still correct on the
 * resident's route, which this spec does not touch.
 *
 * `my_offers` AND NOT `offers` (decision 7). The general detail hands a
 * provider a key called `offers` carrying only their own, which forces the
 * client to know which route it came through before it can read it. Here the
 * name says what it holds. The trim itself is still made by the QUERY —
 * myapi_service_request_load_offers($nid, $provider_ids) — and never in PHP.
 *
 * `viewer` IS THE CONSTANT 'provider' AND STILL TRAVELS (decision 8). Every
 * 200 of this route is a provider reading, so the key carries no information;
 * it stays so the Flutter model of the two detail routes is the SAME object
 * with the same parser, and dropping it would buy nothing but a second almost
 * identical class.
 *
 * `transactions` TRAVELS WHOLE, not trimmed to "my" events (decision 9): there
 * is no stable ownership criterion in a status transition, and a timeline with
 * holes in it is a story the client would paint as complete.
 *
 * PURE: no database, no node_load(), no $_GET. Every input arrives resolved.
 *
 * @param object $row
 *   The row of myapi_service_request_detail_row(), which projects every alias
 *   myapi_service_request_provider_build_item() reads — the fifteen of
 *   myapi_service_request_build_item() plus `requester_uid`, `condominium_id`
 *   and `condominium_name` — plus `attachment_fid`, `attachment_filename` and
 *   `closed_at` for the six added below. `requester_name` is the one it does
 *   NOT project: the caller writes it onto the row out of
 *   myapi_user_display_names(), exactly as myapi_service_request_detail()
 *   does.
 * @param array $images
 *   The list of myapi_service_request_build_file() items, in delta order.
 *   Always an array, empty when there are none.
 * @param array $my_offers
 *   The list of myapi_service_request_build_offer() items, ALREADY TRIMMED BY
 *   THE QUERY to the reader's own providers. Always an array.
 * @param mixed $offers_count
 *   The TOTAL number of published offers of the request, competition included,
 *   from myapi_service_request_offer_counts_by_nid(). Never count($my_offers),
 *   which would answer the reader 1 or 0 where the key promises the total.
 * @param array $transactions
 *   The timeline, from myapi_service_request_load_transactions(): already
 *   ordered and already shaped. Always an array.
 * @param array $owned_provider_ids
 *   Every provider nid of the READER'S ACCOUNT, from
 *   myapi_provider_role_provider_ids(). Never empty on this route — the
 *   orchestrator answered 403 before getting here — and it is what the `unit`
 *   rule measures the award against.
 *
 * @return array  The nineteen keys, in the documented order.
 */
function myapi_service_request_provider_build_detail($row, array $images, array $my_offers, $offers_count, array $transactions, array $owned_provider_ids) {
  $nid = (int) $row->nid;

  // The thirteen keys of the provider's listing, from the provider's listing's
  // own serialiser — the `unit` rule of decision 5 included. The count is
  // handed over as the one-entry map that function expects.
  $item = myapi_service_request_provider_build_item(
    $row, [$nid => (int) $offers_count], $owned_provider_ids
  );

  // Appended, never interleaved: the thirteen keep their order and their
  // place, which is what makes the byte-for-byte comparison against the
  // listing checkable.
  return array_merge($item, [
    'viewer'      => 'provider',
    // Always an array, never null: "no photos" is an empty list, and a client
    // that can iterate has nothing to branch on.
    'images'      => array_values($images),
    'attachment'  => myapi_service_request_build_file(
      isset($row->attachment_fid) ? $row->attachment_fid : NULL,
      isset($row->attachment_filename) ? $row->attachment_filename : NULL,
      $nid
    ),
    // A datestamp like desired_start, so format_date() and not the raw column.
    // NULL — and not format_date(0), a 1970 nobody typed — while the request
    // is not closed, which is every request that is not 'closed'.
    'closed_at'   => isset($row->closed_at) && $row->closed_at !== ''
      ? format_date((int) $row->closed_at, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
    'my_offers'   => array_values($my_offers),
    'transactions' => array_values($transactions),
  ]);
}

/**
 * Dispatcher for /api/v1/service-requests/provider/% (SPEC 99).
 *
 * A SIBLING of myapi_service_request_provider_dispatch() and of
 * myapi_service_request_item_dispatch(), and a branch inside neither of them.
 * Drupal 7 resolves routes by number of components first and by the literal in
 * the position second, so /provider (four), /% (four, wildcard) and
 * /provider/% (five) are three menu entries that never compete — and one
 * dispatcher per route is what keeps each one's 405 as simple as it looks.
 *
 * READ-ONLY, AND ONLY GET. Offering, editing an offer, accepting, rejecting,
 * awarding and closing are all out of SPEC 99 and none of them will land here
 * by accident: a dispatcher that answers 405 to everything but GET is what
 * keeps that decision visible.
 *
 * THE 405 COMES BEFORE THE TOKEN AND BEFORE ANY QUERY, deliberately and like
 * every other dispatcher of this module: the method is wrong whoever is asking
 * and whatever they are asking about, and answering 401 first would tell a
 * client with a broken verb to go fix its credentials.
 *
 * @param mixed $nid  The route's wildcard, RAW as Drupal hands it over — a
 *                    string, and not necessarily a number. Validating it is
 *                    the first job of the endpoint that takes it.
 */
function myapi_service_request_provider_item_dispatch($nid = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_service_request_provider_detail($nid);
    return;
  }

  myapi_error('method_not_allowed', 405);
}

/**
 * GET /api/v1/service-requests/provider/% — the provider's detail (SPEC 99).
 *
 * Orchestration only, and the SIBLING of myapi_service_request_detail(): every
 * decision it makes already lives in a helper, and what this function owns is
 * the ORDER. The order is the contract, and it is this one:
 *
 *   1. the nid          → 404 not_found, before a single query
 *   2. the token        → 401 missing_authorization / invalid_token
 *   3. THE ROLE GATE    → 403 provider_role_required
 *   4. the providers    → 403 forbidden when there are none
 *   5. the row          → 404 not_found
 *   6. the access rule  → 403 forbidden unless it answers 'provider'
 *   7. the four loads
 *   8. the serialisation
 *
 * THE ROLE GATE IS THE ONLY THING THIS ENDPOINT ASKS FOR OVER THE GENERAL
 * DETAIL (decision 3). GET /api/v1/service-requests/% lets in any account that
 * operates a provider, role or no role, and that is correct THERE: it serves
 * residents and providers alike and cannot reject by role. This route can —
 * it is the provider's — and the precedent is the listing of SPECS 97 and 98.
 * It is also the point where an administrator cuts an account off the
 * provider channel by removing a role, without unlinking a single node.
 *
 * IT RUNS BEFORE THE ROW IS LOADED, so a nid that does not exist asked for by
 * an account with no role answers 403 and never 404: the reader is not on this
 * route at all, and telling them apart which requests exist would be answering
 * a question they may not ask.
 *
 * THE GATE READS THE LOADED ACCOUNT AND NOT A SYNTHETIC OBJECT.
 * myapi_provider_role_is() reads ->roles, so (object) ['uid' => n] would deny
 * every account alive. user_load() is not an extra query — the token check
 * already loaded this user and Drupal 7 serves the second call from its static
 * cache — and it is exactly what myapi_service_request_provider_list() does
 * for the same gate.
 *
 * A ROLE WITH NO PROVIDER BEHIND IT IS 403 HERE AND 200-WITH-AN-EMPTY-LIST ON
 * THE LISTING (decision 5), and the two are not in contradiction. A listing
 * has a legitimate way of saying "nothing": the empty list. A detail does not
 * — over one concrete resource there is only "here it is" or "you may not see
 * it" — and with no provider to operate there is no access rule that could
 * ever be satisfied. 403 and not 404: the request exists, the reader does not
 * reach it.
 *
 * THE ACCESS RULE IS myapi_service_request_viewer(), UNMODIFIED (decision 2).
 * Not one new condition, not one parameter, not one `if`. SPEC 98 already
 * widened it with rule 2b — the 'direct' awarded to me — which is the case
 * this route needed. Writing a second definition of "who may read this" here
 * would make a THIRD copy of the access rule (risk 1) and the first one that
 * was forgotten in an update would be the one that opened the hole.
 *
 * A 'requester' IS A 403 HERE (decision 4). It reads as the unkind answer and
 * it is the correct one: a resident who also holds the 'proveedor' role would
 * otherwise receive their own request through this route with `my_offers`
 * empty and `unit` decided by the award — the same request told wrong. Their
 * route exists and it is GET /api/v1/service-requests/%.
 *
 * SIX CONTENT QUERIES, FIXED, plus the token's and the provider_role ones,
 * which that include caches statically per uid:
 *
 *   1. the request row                  — myapi_service_request_detail_row()
 *   2. the requester's name             — myapi_user_display_names()
 *   3. the images, in delta order       — myapi_service_request_load_images()
 *   4. offers_count, the REAL total     — myapi_service_request_offer_counts_by_nid()
 *   5. MY offers                        — myapi_service_request_load_offers()
 *   6. the timeline                     — myapi_service_request_load_transactions()
 *
 * Exactly what the general detail already costs, over one single node.
 *
 * NOTHING HERE IS TAGGED 'node_access', same as SPECS 88, 89 and 98: the
 * access decision is myapi_service_request_viewer(), in one place and in plain
 * sight, and a tag would add a second, invisible one that could contradict it.
 *
 * @param mixed $nid  The route's wildcard, RAW as Drupal hands it over.
 */
function myapi_service_request_provider_detail($nid) {
  // 1. The nid, before anything else — including the token. ctype_digit() over
  // the string form rejects 'abc', '-1', '1.5' and '' alike; the > 0 then
  // rejects '0'. Nothing is leaked by answering first: the answer is about the
  // SHAPE of the URL and not about what exists.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 missing_authorization / invalid_token on
  // its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The role gate, and the one step this endpoint adds over the general
  // detail — see the docblock. The account is loaded because the gate reads
  // ->roles; it costs no query.
  $account = user_load($uid);
  if (!myapi_provider_role_is($account)) {
    myapi_error('provider_role_required', 403);
  }

  // 4. The providers of the ACCOUNT. Empty means the role is there and there
  // is nothing to operate with it, so no access rule below could ever be met.
  // They are also what the `unit` rule measures the award against.
  $provider_ids = myapi_provider_role_provider_ids($account);
  if (!$provider_ids) {
    myapi_error('forbidden', 403);
  }

  // 5. The request itself. FALSE covers all four "no such request" cases at
  // once — it does not exist, it is unpublished, it is of another bundle, or
  // its category tid is orphaned — and the reader is told none of them apart.
  $row = myapi_service_request_detail_row($nid);
  if (!$row) {
    myapi_error('not_found', 404);
  }

  // 6. WHO IS ASKING, by the one function that decides it, shared with the
  // general detail and with the file endpoint. Only 'provider' passes here:
  // NULL is a 403 and so is 'requester'.
  if (myapi_service_request_viewer($row, $uid) !== 'provider') {
    myapi_error('forbidden', 403);
  }

  // 7. The requester's name, by the shared rule of includes/myapi.user.inc.
  // Written onto the row because that is what the serialiser reads — the only
  // key of the thirteen that myapi_service_request_detail_row() does not
  // project. No contact detail is fetched.
  $row->requester_name = NULL;
  if (isset($row->requester_uid) && $row->requester_uid !== '') {
    $names = myapi_user_display_names([(int) $row->requester_uid]);
    $row->requester_name = isset($names[(int) $row->requester_uid])
      ? $names[(int) $row->requester_uid]
      : NULL;
  }

  // 8. The images, the REAL total, my offers and the timeline. The trim is a
  // query argument and never a filter in PHP, and $provider_ids is never empty
  // here — step 4 answered 403 — which matters because an empty list means
  // "every offer" to that function.
  $images = myapi_service_request_load_images($nid);

  $offer_counts = myapi_service_request_offer_counts_by_nid([$nid]);
  $offers_count = isset($offer_counts[$nid]) ? $offer_counts[$nid] : 0;

  $my_offers = array_map(
    'myapi_service_request_build_offer',
    myapi_service_request_load_offers($nid, $provider_ids)
  );

  // NOT TRIMMED BY READER (decision 9). It runs after the 403 above, so a
  // reader who fits no rule never pays for this query.
  $transactions = myapi_service_request_load_transactions($nid);

  // 9. Respond 200 (read-only endpoint, no message_key). One object under
  // 'service_request', the same envelope the general detail uses.
  myapi_respond([
    'service_request' => myapi_service_request_provider_build_detail(
      $row, $images, $my_offers, $offers_count, $transactions, $provider_ids
    ),
  ], 200);
}

/**
 * Dispatcher for /api/v1/service-requests/%/files/% (SPEC 89).
 *
 * Its own dispatcher and not a second case inside the item one: routing
 * "detail or file" by the presence of $fid would turn two responsibilities into
 * a chain of isset(). Three routes, three dispatchers, one job each — the same
 * split myapi_claim_file_dispatch() made in SPEC 65 and
 * myapi_provider_gallery_file_dispatch() in SPEC 82.
 *
 * It is also what makes "POST, PUT and DELETE answer 405 on BOTH routes,
 * without a token and before any query" true of this one too: the method is
 * wrong whoever is asking.
 *
 * @param mixed $nid  The request nid from the route.
 * @param mixed $fid  The file id from the route.
 */
function myapi_service_request_file_dispatch($nid = NULL, $fid = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_service_request_file_download($nid, $fid);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * GET /api/v1/service-requests/%/files/% — the bytes of one file (SPEC 89).
 *
 * NOT A JSON ENDPOINT ON THE HAPPY PATH: it answers the file itself, streamed
 * by file_transfer(). Errors do travel in the module's envelope. It exists
 * because field_images and field_attachment are private:// at the field level
 * since SPEC 77 — without it the detail would serve filenames nobody can open —
 * and it is the same shape SPEC 65 gave claims and SPEC 82 gave provider
 * galleries, with THIS spec's access rule.
 *
 * FOUR CHECKS, IN THIS ORDER, and the order is the security of the route:
 *
 *   1. The token. 401 before anything else is read.
 *   2. The request exists — myapi_service_request_detail_row(). 404 covers "no
 *      such request", "unpublished" and "another bundle" alike.
 *   3. WHO IS ASKING — myapi_service_request_viewer(), THE SAME FUNCTION the
 *      detail calls, not a copy of its conditions. This is what makes "whoever
 *      cannot read the detail cannot download its photos either" true by
 *      construction rather than by two lists somebody has to keep in sync. The
 *      day a condition changes it changes for both routes at once.
 *   4. THE FID BELONGS TO THIS REQUEST — myapi_service_request_file_request_nid()
 *      from includes/myapi.service_request_files.inc, the include
 *      hook_file_download() also calls. THIS IS THE CHECK THAT MAKES THE ROUTE
 *      SAFE: without it, /service-requests/128/files/999 would serve any
 *      private file of the site — a payment receipt, another resident's claim
 *      photo, a provider's private gallery — to anyone with access to ONE
 *      request, and access to an 'open' one of your category is access every
 *      active provider has. A fid of another request, or of no request at all,
 *      answers 404 BEFORE file_load() is ever reached.
 *
 * The membership check is a comparison against the {id} OF THE ROUTE, which is
 * the same nid step 2 proved exists and step 3 granted access to. Comparing
 * against anything else — the fid's own owner, say — would grant access to a
 * request the reader never passed the rule for.
 *
 * WHAT IT DOES NOT DO: image styles. The URL serves the original file;
 * private://styles/... is neither generated nor offered (out of scope), and the
 * back office's thumbnails are core's business through hook_file_download().
 *
 * @param mixed $nid  The request nid, raw from the route.
 * @param mixed $fid  The file id, raw from the route.
 */
function myapi_service_request_file_download($nid, $fid) {
  // 1. Token. Answers 401 missing_authorization / invalid_token on its own and
  // never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('not_found', 404);
  }
  $nid = (int) $nid;

  // 2. The request. Same function, same 404, as the detail.
  $row = myapi_service_request_detail_row($nid);
  if (!$row) {
    myapi_error('not_found', 404);
  }

  // 3. The access rule. THE SAME FUNCTION, not a copy — see the docblock.
  if (myapi_service_request_viewer($row, $uid) === NULL) {
    myapi_error('forbidden', 403);
  }

  // 4. The file. From here on the reader has proved they may read this request,
  // so telling "that file is not of this request" apart from "no such file"
  // leaks nothing — and both answer the same 404 anyway.
  module_load_include('inc', 'myapi', 'includes/myapi.service_request_files');

  if (!is_scalar($fid) || !ctype_digit((string) $fid) || (int) $fid <= 0) {
    myapi_error('not_found', 404);
  }
  $fid = (int) $fid;

  // Membership, resolved by the include the back-office hook also calls. The
  // strict comparison is against the route's nid, and it happens BEFORE
  // file_load().
  if (myapi_service_request_file_request_nid($fid) !== $nid) {
    myapi_error('not_found', 404);
  }

  $file = file_load($fid);

  // A file_managed row pointing at a file that is not on disk answers 404 too,
  // not a 200 of zero bytes nor a PHP warning from fopen().
  if (!is_object($file) || !file_exists($file->uri)) {
    myapi_error('not_found', 404);
  }

  // 5. The bytes. file_transfer() sends the headers, streams the file in chunks
  // and ends the request; nothing after this line runs.
  //
  // 'inline' because the app renders these images on screen and 'attachment'
  // would force a download in any web viewer; the original filename travels in
  // the header either way. 'private, no-store' because the whole point of this
  // endpoint is that the file is not cacheable by anything between the app and
  // Drupal.
  file_transfer($file->uri, [
    'Content-Type'        => $file->filemime,
    'Content-Length'      => $file->filesize,
    'Content-Disposition' => 'inline; filename="' . $file->filename . '"',
    'Cache-Control'       => 'private, no-store',
  ]);
}

/* ---------------------------------------------------------------------------
 * Write-path helpers (SPEC 90).
 *
 * Three pure(-ish) validators for POST /api/v1/service-requests, written before
 * the endpoint that uses them so each is testable against fixture rows with no
 * site booted. None of them routes anything yet — myapi_service_request_create()
 * and the dispatcher's POST branch are the next step of the plan.
 * ------------------------------------------------------------------------ */

/**
 * Whether $tid is a real term of the service_category vocabulary (SPEC 90).
 *
 * Two Drupal calls and a comparison of vids: taxonomy_term_load() resolves the
 * term (FALSE for a tid that names nothing), and
 * taxonomy_vocabulary_machine_name_load(MYAPI_SERVICES_CATEGORY_VOCABULARY)
 * resolves the vocabulary this bundle's category field is meant to point at.
 * A tid of ANOTHER vocabulary loads a real term whose vid simply does not
 * match, which is what stops it here instead of leaking through as a category
 * that does not belong to this marketplace.
 *
 * Deliberately NOT myapi_service_category_provider_counts()'s or
 * myapi_provider_role_category_ids()'s query-based approach: this is a single
 * id, not a set, and taxonomy_term_load() is Drupal's own single-entity
 * primitive for exactly that — the same reason
 * myapi_service_request_validate_provider() below reads its provider with a
 * query instead: THAT one needs three columns of the SAME node in one round
 * trip, this one needs one column of a term Drupal already knows how to load.
 *
 * @param mixed $tid  Raw category_id from the request, already known to be a
 *                     positive integer by the caller's earlier check.
 *
 * @return bool  TRUE when $tid is a real term of the category vocabulary.
 */
function myapi_service_request_valid_category($tid) {
  $tid = (int) $tid;

  if ($tid <= 0) {
    return FALSE;
  }

  $term = taxonomy_term_load($tid);
  if (!$term || !isset($term->vid)) {
    return FALSE;
  }

  $vocabulary = taxonomy_vocabulary_machine_name_load(MYAPI_SERVICES_CATEGORY_VOCABULARY);
  if (!$vocabulary) {
    return FALSE;
  }

  return (int) $term->vid === (int) $vocabulary->vid;
}

/**
 * Parses 'desired_start' into a timestamp, or FALSE when invalid (SPEC 90).
 *
 * ONE SIGNAL FOR TWO FAILURES, ON PURPOSE: a string strtotime() cannot parse
 * and a string that parses into the past or into REQUEST_TIME itself both
 * answer FALSE, because the caller's response to either is the identical
 * 422 invalid_field — there is no second message that would help the client
 * tell the two apart, and inventing one would be a distinction the spec's
 * error table does not draw.
 *
 * STRICTLY FUTURE, NOT "AT OR AFTER": the comparison is `<= REQUEST_TIME`,
 * so the exact instant of REQUEST_TIME is rejected along with the past. A
 * client racing the clock to send exactly now is not a case worth a second
 * code path — see the spec's Decisiones table.
 *
 * PURE: no database, no Field API. Every caller that has already read the
 * field out of $_POST can test this against a fixed REQUEST_TIME with no site
 * booted.
 *
 * @param mixed $raw  Raw 'desired_start' from the request, expected as
 *                     'Y-m-d H:i' but parsed with strtotime() rather than a
 *                     strict format check, the same laxness core's own form
 *                     widgets allow.
 *
 * @return int|FALSE  The Unix timestamp when $raw parses into the strict
 *                     future, FALSE otherwise.
 */
function myapi_service_request_parse_desired_start($raw) {
  if (!is_string($raw) || $raw === '') {
    return FALSE;
  }

  $timestamp = strtotime($raw);

  if ($timestamp === FALSE || $timestamp <= REQUEST_TIME) {
    return FALSE;
  }

  return $timestamp;
}

/**
 * Whether a provider may receive a direct award of $category_id (SPEC 90).
 *
 * THE FOUR CONDITIONS OF THE SPEC, ALL OR NOTHING, ANSWERED FROM ONE QUERY:
 *
 *   1. $provider_id is the nid of a REAL node of bundle 'provider'.
 *   2 & 3. That node is active — myapi_services_provider_is_active(), the same
 *      function myapi_provider_role_any_provider_active() calls, which already
 *      folds "published" into "active" (a status of 0 answers FALSE on its
 *      own, so condition 2 costs no extra check here).
 *   4. $category_id is among the tids of the provider's OWN field_categories.
 *
 * ONE QUERY, NOT FOUR, because the caller must answer a single 403 for all
 * four causes (see the spec's Decisiones table) and there is no reason to pay
 * for a node_load() plus a separate field_categories read when a single LEFT-
 * JOINed SELECT already carries every column the four conditions need. The
 * shape mirrors myapi_provider_role_any_provider_active(): node + LEFT JOIN
 * field_license_expiry, with field_categories added as a second LEFT JOIN —
 * unlimited cardinality, so the query answers one row per category (or one
 * row with a NULL category_tid when the provider has none), never fewer than
 * one row for a provider that exists.
 *
 * NOT myapi_provider_role_category_ids(): that function resolves the
 * categories of the PROVIDERS THE AUTHENTICATED OPERATOR OWNS, a completely
 * different question from "what categories does THIS ARBITRARY NID attend" —
 * confusing the two would filter eligibility by the wrong provider's
 * categories. See the spec's Riesgos table.
 *
 * @param mixed $provider_id  Raw 'assigned_provider_id' from the request,
 *                             already known to be a positive integer by the
 *                             caller's earlier check.
 * @param mixed $category_id  The already-validated category tid of the
 *                             request (step 5 of the validation order).
 *
 * @return bool  TRUE when all four conditions hold.
 */
function myapi_service_request_validate_provider($provider_id, $category_id) {
  $provider_id = (int) $provider_id;
  $category_id = (int) $category_id;

  if ($provider_id <= 0) {
    return FALSE;
  }

  $query = db_select('node', 'n');
  $query->fields('n', ['type', 'status']);
  $query->leftJoin(
    'field_data_field_license_expiry',
    'fle',
    "fle.entity_id = n.nid AND fle.entity_type = 'node' AND fle.deleted = 0"
  );
  $query->addField('fle', 'field_license_expiry_value', 'license_expiry');
  $query->leftJoin(
    'field_data_field_categories',
    'fc',
    "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0"
  );
  $query->addField('fc', 'field_categories_tid', 'category_tid');
  $query->condition('n.nid', $provider_id);

  $type = NULL;
  $status = NULL;
  $license_expiry = NULL;
  $category_tids = [];

  foreach ($query->execute() as $row) {
    // Repeated identically on every row (the node columns do not vary per
    // category), so overwriting on each iteration is harmless and simpler
    // than reading only the first row.
    $type = $row->type;
    $status = (int) $row->status;
    $license_expiry = $row->license_expiry;

    if ($row->category_tid !== NULL && $row->category_tid !== '') {
      $category_tids[] = (int) $row->category_tid;
    }
  }

  // Condition 1: a real node, and of the right bundle. An unknown nid answers
  // no rows at all, which leaves $type NULL and fails the same comparison.
  if ($type !== MYAPI_SERVICES_PROVIDER_TYPE) {
    return FALSE;
  }

  // Conditions 2 and 3, together: published AND licence not expired.
  if (!myapi_services_provider_is_active($status, $license_expiry, REQUEST_TIME)) {
    return FALSE;
  }

  // Condition 4. An empty $category_tids (a provider with no categories at
  // all) fails the same IN as one with the wrong categories — no special case
  // needed, see the spec's Riesgos table.
  return in_array($category_id, $category_tids, TRUE);
}

/**
 * Builds an unsaved 'service_request' node from the validated input (SPEC 90).
 *
 * Same pattern as myapi_claim_build_node(): every value has already been
 * validated by the caller, and the node is returned UNSAVED so the
 * orchestrator controls the order against file_usage_add() — node_save()
 * first, then usage, exactly as SPEC 89 assumes for every file already
 * attached to a request.
 *
 * FOUR FIELDS ARE FIXED HERE, NEVER READ FROM THE REQUEST (see the Modelo de
 * datos of SPEC 90 — "Campos que el servidor fija, nunca el cliente"):
 *   - field_requester is always $uid, the token's own.
 *   - field_condominium is the DERIVED condominium of $unit_id, resolved by
 *     the caller before this is called — never the client's.
 *   - field_request_status is 'direct' when $assigned_provider_id is not
 *     NULL (meaning the caller already ran it through
 *     myapi_service_request_validate_provider() and it passed all four
 *     conditions), 'open' otherwise — including a $assigned_provider_id the
 *     request never sent at all, which is exactly the "any other case"
 *     the spec's field table describes.
 *   - field_assigned_offer and field_closed_at are never written: leaving a
 *     cardinality-1 field entirely unset on a new node is what "always
 *     empty" means to node_save(), the same as field_assigned_offer already
 *     is on every claim this module creates.
 *
 * field_assigned_provider is written ONLY when $assigned_provider_id is not
 * NULL — an entityreference field left unset, not one pointed at a target_id
 * of 0, is what keeps a request with no direct award from resolving to a
 * phantom provider.
 *
 * @param int         $uid                  Authenticated resident (token
 *                                            uid): node.uid and
 *                                            field_requester.
 * @param string      $title                Validated title, <= 255 chars.
 * @param int         $unit_id              Validated field_unit target nid,
 *                                            already checked to belong to the
 *                                            reader.
 * @param int         $condominium_id       The condominium DERIVED from
 *                                            $unit_id — never the client's.
 * @param int         $category_id          Validated field_category target
 *                                            tid, a real term of the category
 *                                            vocabulary.
 * @param string      $description          Validated, non-empty
 *                                            field_description.
 * @param int         $desired_start        Validated field_desired_start
 *                                            timestamp, strictly in the
 *                                            future.
 * @param int|NULL    $assigned_provider_id The validated provider nid, or
 *                                            NULL when the request carried
 *                                            none or it failed eligibility —
 *                                            NULL is what decides 'open'
 *                                            below.
 * @param array       $image_files          Saved permanent file objects for
 *                                            field_images, in upload order.
 *                                            May be empty.
 * @param object|null $attachment_file      Saved permanent file object for
 *                                            field_attachment, or NULL when
 *                                            none.
 *
 * @return object  The prepared, unsaved node object.
 */
function myapi_service_request_build_node($uid, $title, $unit_id, $condominium_id, $category_id, $description, $desired_start, $assigned_provider_id, array $image_files, $attachment_file) {
  $node = new stdClass();
  $node->type = MYAPI_SERVICES_REQUEST_TYPE;
  node_object_prepare($node);
  $node->uid = $uid;
  $node->status = 1;
  $node->language = LANGUAGE_NONE;
  $node->title = $title;

  $node->field_unit[LANGUAGE_NONE][0]['target_id'] = $unit_id;
  $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = $condominium_id;
  // field_category is a taxonomy_term_reference, NOT an entityreference: its
  // storage column is 'tid' (field_category_tid, the very column every read
  // path of this resource joins on), so the item key must be 'tid' too.
  // Writing 'target_id' here leaves the term unset and makes core's own
  // taxonomy_build_node_index() insert a NULL tid into {taxonomy_index}.
  $node->field_category[LANGUAGE_NONE][0]['tid'] = $category_id;
  $node->field_requester[LANGUAGE_NONE][0]['target_id'] = $uid;
  $node->field_description[LANGUAGE_NONE][0]['value'] = $description;
  $node->field_desired_start[LANGUAGE_NONE][0]['value'] = $desired_start;

  $node->field_request_status[LANGUAGE_NONE][0]['value'] = $assigned_provider_id !== NULL
    ? MYAPI_SERVICES_REQUEST_STATUS_DIRECT
    : MYAPI_SERVICES_REQUEST_STATUS_OPEN;

  if ($assigned_provider_id !== NULL) {
    $node->field_assigned_provider[LANGUAGE_NONE][0]['target_id'] = $assigned_provider_id;
  }

  foreach ($image_files as $delta => $file) {
    $node->field_images[LANGUAGE_NONE][$delta] = ['fid' => $file->fid, 'display' => 1];
  }

  if ($attachment_file) {
    $node->field_attachment[LANGUAGE_NONE][0] = ['fid' => $attachment_file->fid, 'display' => 1];
  }

  return $node;
}

/**
 * POST /api/v1/service-requests — creates a request for the authenticated
 * resident (SPEC 90).
 *
 * multipart/form-data, not JSON: text fields through
 * myapi_request_post_field() ($_POST), files through $_FILES — same contract
 * as POST /api/v1/claims. Every step below aborts with its own error BEFORE
 * anything touches the database or the filesystem, in the exact order of the
 * Modelo de datos section of SPEC 90:
 *
 *   1. Authentication.
 *   2. Required fields present (title, unit_id, category_id, description,
 *      desired_start). assigned_provider_id, images[] and attachment are all
 *      optional and are not in this list.
 *   3. title <= 255 chars.
 *   4. unit_id is a positive integer AND one of the reader's own units
 *      (myapi_unit_related_nids($uid)) — a user with no unit at all simply
 *      has an empty set, so every unit_id falls outside it and answers the
 *      same 403, never a 422 or a 200.
 *   4b. The condominium DERIVED from unit_id — never read from the request.
 *      A unit with no field_condominio row is a data inconsistency no client
 *      input can cause or fix, so it answers 500 and is logged rather than
 *      creating a request with no condominium — see the spec's Riesgos.
 *   5. category_id is a positive integer AND a real tid of the
 *      service_category vocabulary.
 *   6. description non-empty after trim() (myapi_request_post_field()
 *      already trims).
 *   7. desired_start parses with strtotime() into a strictly future instant.
 *   8. assigned_provider_id (if any) — the four conditions of
 *      myapi_service_request_validate_provider(), a single 403
 *      provider_not_eligible for all four. Resolved BEFORE any file touches
 *      the filesystem, so a request rejected for its provider never leaves an
 *      orphaned upload to clean up.
 *   9. images[] (if any) — myapi_node_files_save(), all-or-nothing.
 *   10. attachment (if any) — same, and on failure also deletes the images
 *      already saved by step 9 (the $also_delete parameter).
 *
 * field_requester, field_condominium, field_assigned_offer and field_closed_at
 * are NEVER read from the request: see myapi_service_request_build_node().
 *
 * A service_transaction IS created, and not from here (SPEC 92). The node_save()
 * this function already performs fires hook_node_insert(), whose
 * 'service_request' branch (myapi.module) delegates to
 * myapi_service_transaction_create_initial()
 * (includes/myapi.service_transaction.inc), which writes the first entry of the
 * request's timeline copying the status it was just born with — 'open' or
 * 'direct'. Exactly like 'reclamo', and for the same reason: the hook covers
 * this endpoint, node/add/service_request and any future path with one
 * implementation, which is why not a single executable line of this function
 * mentions transactions. It is also why nothing here needs to change if that
 * behaviour ever does.
 *
 * SINCE SPEC 93 THAT TIMELINE IS ALSO SERVED, and the 201 below carries it
 * populated: the initial transaction already exists at this instant, written
 * inside the very node_save() above, so answering `transactions: []` here would
 * be serving a datum that is false — and omitting the key would break SPEC 89's
 * promise that the 201 and GET /% are the same object. It is the one difference
 * with `offers` and `offers_count` below: those two are known to be empty, this
 * one is known NOT to be, so it is queried and not assumed.
 *
 * The 201 response reuses myapi_service_request_detail_row() +
 * myapi_user_display_names() + myapi_service_request_load_images() +
 * myapi_service_request_load_transactions() +
 * myapi_service_request_build_detail() — the very same functions
 * GET /api/v1/service-requests/% uses, with `viewer` fixed to 'requester' (the
 * creator is always the requester), so the two responses can never drift
 * apart. `offers` and `offers_count` are put directly in code as `[]` and `0`,
 * never queried: at the instant of creation nothing can have offered on a
 * node that did not exist a moment ago, and querying anyway would be one more
 * read on the write path to confirm what the flow already knows.
 */
function myapi_service_request_create() {
  // 1. Authentication.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 2. Read the multipart/form-data text fields.
  $title = myapi_request_post_field('title');
  $unit_raw = myapi_request_post_field('unit_id');
  $category_raw = myapi_request_post_field('category_id');
  $description = myapi_request_post_field('description');
  $desired_start_raw = myapi_request_post_field('desired_start');

  $required = [
    'title'         => $title,
    'unit_id'       => $unit_raw,
    'category_id'   => $category_raw,
    'description'   => $description,
    'desired_start' => $desired_start_raw,
  ];
  foreach ($required as $name => $value) {
    if ($value === NULL || $value === '') {
      myapi_error('missing_field', 422, ['@field' => $name]);
    }
  }

  // 3. title: node.title is varchar(255).
  if (strlen($title) > 255) {
    myapi_error('invalid_field', 422, ['@field' => 'title']);
  }

  // 4. unit_id: positive integer, then one of the reader's own units. An
  // empty set (no unit at all) makes every id fall outside it, so this is the
  // SAME 403 for both cases — no special code for "no unit" beyond the
  // natural consequence of the rule.
  if (!ctype_digit($unit_raw) || (int) $unit_raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'unit_id']);
  }
  $unit_id = (int) $unit_raw;
  if (!in_array($unit_id, array_map('intval', myapi_unit_related_nids($uid)), TRUE)) {
    myapi_error('unit_access_denied', 403);
  }

  // 4b. The condominium, derived and never read from the client. A unit with
  // no field_condominio row is corrupt data no client input can cause or fix
  // — see the spec's Riesgos — so it is logged and answered as a server
  // error instead of silently creating a request with no condominium.
  $condo_nids = myapi_units_condominium_nids([$unit_id]);
  if (empty($condo_nids)) {
    watchdog(
      'myapi',
      'SPEC 90: unit @unit has no field_condominio row; service request creation aborted for uid @uid.',
      ['@unit' => $unit_id, '@uid' => $uid],
      WATCHDOG_ERROR
    );
    myapi_error('server_error', 500);
  }
  $condominium_id = (int) reset($condo_nids);

  // 5. category_id: positive integer, then a real tid of the vocabulary.
  if (!ctype_digit($category_raw) || (int) $category_raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'category_id']);
  }
  $category_id = (int) $category_raw;
  if (!myapi_service_request_valid_category($category_id)) {
    myapi_error('invalid_field', 422, ['@field' => 'category_id']);
  }

  // 6. description: non-empty after trim() (already trimmed above).
  if ($description === '') {
    myapi_error('invalid_field', 422, ['@field' => 'description']);
  }

  // 7. desired_start: strtotime() must parse it into a strictly future
  // instant. FALSE covers both failures with the same 422 — see the
  // helper's docblock.
  $desired_start = myapi_service_request_parse_desired_start($desired_start_raw);
  if ($desired_start === FALSE) {
    myapi_error('invalid_field', 422, ['@field' => 'desired_start']);
  }

  // 8. assigned_provider_id: optional. Resolved BEFORE any file touches the
  // filesystem — see the docblock.
  $assigned_provider_raw = myapi_request_post_field('assigned_provider_id');
  $assigned_provider_id = NULL;
  if ($assigned_provider_raw !== NULL) {
    if (!ctype_digit($assigned_provider_raw) || (int) $assigned_provider_raw <= 0) {
      myapi_error('invalid_field', 422, ['@field' => 'assigned_provider_id']);
    }
    $candidate_provider_id = (int) $assigned_provider_raw;
    if (!myapi_service_request_validate_provider($candidate_provider_id, $category_id)) {
      myapi_error('provider_not_eligible', 403);
    }
    $assigned_provider_id = $candidate_provider_id;
  }

  // 9. images[]: up to 5, all-or-nothing.
  $image_files = myapi_node_files_save(
    'field_images',
    MYAPI_SERVICES_REQUEST_TYPE,
    isset($_FILES['images']) ? $_FILES['images'] : [],
    5,
    'service_request_invalid_image',
    'service_request_too_many_images'
  );

  // 10. attachment: at most 1. A client CAN still send the input as
  // 'attachment[]' with several files, which PHP hands over in the same
  // parallel-array shape as images[], so the count is enforced here with its
  // OWN key: answering the images key for an attachment problem told the
  // client to look at the wrong field. On failure, the images already saved
  // in step 9 are deleted too — see $also_delete.
  $attachment_file = NULL;
  $attachment_saved = myapi_node_files_save(
    'field_attachment',
    MYAPI_SERVICES_REQUEST_TYPE,
    isset($_FILES['attachment']) ? $_FILES['attachment'] : [],
    1,
    'service_request_invalid_attachment',
    'service_request_too_many_attachments',
    $image_files
  );
  if (!empty($attachment_saved)) {
    $attachment_file = reset($attachment_saved);
  }

  // 11. Build and persist the node. No hook_node_insert() branch reacts to
  // this bundle — see the docblock.
  $node = myapi_service_request_build_node(
    $uid,
    $title,
    $unit_id,
    $condominium_id,
    $category_id,
    $description,
    $desired_start,
    $assigned_provider_id,
    $image_files,
    $attachment_file
  );
  node_save($node);

  // 12. Tie every saved file to the node so cron does not reap it as unused.
  foreach ($image_files as $file) {
    file_usage_add($file, 'myapi', 'node', $node->nid);
  }
  if ($attachment_file) {
    file_usage_add($attachment_file, 'myapi', 'node', $node->nid);
  }

  // 13. Respond 201 with the same nineteen-key shape as
  // GET /api/v1/service-requests/%, reusing SPEC 89's own serialiser. `viewer`
  // is always 'requester' here: the creator IS the requester. `offers` and
  // `offers_count` are put directly as `[]` and `0`, while `transactions` IS
  // queried — see the docblock.
  $row = myapi_service_request_detail_row($node->nid);

  $row->requester_name = NULL;
  $names = myapi_user_display_names([$uid]);
  if (isset($names[$uid])) {
    $row->requester_name = $names[$uid];
  }

  $images = myapi_service_request_load_images($node->nid);

  // The timeline of a node that is one node_save() old: exactly the one entry
  // hook_node_insert() just wrote (SPEC 92). The same loader the detail calls,
  // so the element this 201 returns and the one an immediate GET returns are
  // the same object and cannot drift.
  $transactions = myapi_service_request_load_transactions($node->nid);

  myapi_respond(
    ['service_request' => myapi_service_request_build_detail($row, 'requester', $images, [], 0, $transactions)],
    201,
    'service_request_created'
  );
}

/**
 * Dispatcher for /api/v1/service-requests/%/cancel (SPEC 95).
 *
 * PUT and nothing else — the third '/cancel' route of the module, after
 * api/v1/reservations/%/cancel and api/v1/payments/%/cancel, and the same
 * single-method shape as both.
 *
 * THE METHOD IS CHECKED BEFORE THE TOKEN, like every other dispatcher here: a
 * GET on this path is answered 405 with no Authorization header and without
 * the request having to exist, because the answer is about the VERB and not
 * about what is behind it.
 *
 * @param mixed $nid  The route's wildcard, raw.
 */
function myapi_service_request_cancel_dispatch($nid) {
  if (myapi_request_method() === 'PUT') {
    myapi_service_request_cancel($nid);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * PUT /api/v1/service-requests/% /cancel — the resident cancels (SPEC 95).
 *
 * The first WRITE this resource does on an existing request, and the first
 * status transition of the marketplace that has an endpoint. Seven numbered
 * steps below, in the order SPEC 90 fixed for this file: nid, token, load,
 * ownership, status, body, and only then the writes.
 *
 * WHO MAY CANCEL: THE field_requester, EXACTLY. Not node->uid — the same
 * column SPEC 89 made authoritative for reading, for the same reason: a request
 * an operator registered on a resident's behalf belongs to the resident. Not
 * the rest of the unit either, unlike a payment (SPEC 23): a service request is
 * signed by one person and the household does not inherit the right to pull it.
 * The assigned provider never cancels — "I quit this job" is another action,
 * with another actor and probably another target status — and the operator
 * already cancels from the back office (SPEC 94), with a form and a mandatory
 * comment.
 *
 * WHICH STATUSES ADMIT IT IS NOT WRITTEN HERE. The question is asked to
 * myapi_services_transition_allowed() (includes/myapi.services_common.inc,
 * SPEC 77), which owns the graph. Copying the four cancellable keys into this
 * file would create a second truth that drifts the day somebody adds a status.
 * That function answers FALSE for an unknown or empty value by design, so a
 * corrupt field_request_status is a 409 and never a 500: "I do not know what
 * state this is in" reads as "I do not cancel it".
 *
 * 404 AND 403 MEAN DIFFERENT THINGS, same criterion as the detail (SPEC 89):
 * the request exists and its nid is not a secret, so the 403 tells the client
 * something actionable instead of pretending it is not there. The body is
 * validated AFTER both, so a request with garbage in it never masks an access
 * or a status error.
 *
 * THE ORDER OF THE THREE WRITES IS LORE, NOT STYLE. The request goes first, the
 * transaction second, the offers third:
 *
 *   - Request first, so myapi_service_transaction_sync_request_status()
 *     (includes/myapi.service_transaction_admin.inc, SPEC 94), which fires on
 *     hook_node_insert() of the transaction, compares the two statuses, finds
 *     them equal and returns WITHOUT saving the request a second time. Whoever
 *     touches either of the two has to keep that property — the same warning
 *     myapi_service_transaction_create_initial() already carries.
 *   - The main effect is written in plain sight in this endpoint, not hidden in
 *     a hook. Creating only the transaction and letting the sync write the
 *     status would save one line and make the cancellation stop cancelling, in
 *     silence, the day somebody edits that sync.
 *   - Offers last, and NOT atomically: see
 *     myapi_service_request_reject_live_offers().
 *
 * field_assigned_offer AND field_assigned_provider ARE KEPT, even when the
 * offer they point at is now 'rejected'. A cancellation with no trace of who
 * the job had been awarded to is a cancellation nobody can audit.
 *
 * NOT IDEMPOTENT, on purpose. A second call answers 409 rather than 200: it
 * tells the resident their action did nothing, which is the truth, and it is
 * what stops a duplicate transaction from landing on the timeline.
 *
 * THE 200 ANSWERS THE WHOLE DETAIL, not a minimal acknowledgement: the same
 * nineteen-key object GET /api/v1/service-requests/% serves, built by the same
 * loaders and the same serialiser, plus offers_rejected as a sibling key. Six
 * queries, spent so the app can repaint the screen it is already on — status,
 * offers and timeline at once — without a second round trip, and so this
 * response can never disagree with what a GET would say.
 *
 * @param mixed $nid  The route's wildcard, raw.
 */
function myapi_service_request_cancel($nid) {
  // 1. The nid, before anything else. Same guard as the detail: ctype_digit()
  // over the string form rejects 'abc', '-3', '1.5' and '' alike, and the > 0
  // then rejects '0'. Costs no query, not even the token's.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('service_request_not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 missing_authorization / invalid_token on its
  // own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The request itself. node_load() and not a joined query, unlike every
  // read of this file: what comes next SAVES this node, and a write needs the
  // whole entity with its fields, not four columns. FALSE, the wrong bundle and
  // the unpublished one are told apart from each other by nothing — all three
  // are "no such request".
  //
  // myapi.building_admin is loaded here and not at the top of the file: it is
  // needed by this path only, and the reads of this resource must not pay for
  // it. Same reason the file route loads myapi.service_request_files.
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $node = node_load($nid);
  if (!$node || $node->type !== MYAPI_SERVICES_REQUEST_TYPE || (int) $node->status !== 1) {
    myapi_error('service_request_not_found', 404);
  }

  // 4. WHO IS ASKING. field_requester, never $node->uid — see the docblock.
  // A request with no requester at all resolves to NULL and is a 403: nobody
  // owns it, so nobody may cancel it.
  $requester_id = myapi_building_admin_field_target_id($node, 'field_requester');
  if ($requester_id === NULL || (int) $requester_id !== $uid) {
    myapi_error('service_request_forbidden', 403);
  }

  // 5. The graph, asked and not transcribed. An empty or unknown status lands
  // here too, as a 409.
  $status = myapi_building_admin_field_value($node, 'field_request_status');
  if (!myapi_services_transition_allowed($status, MYAPI_SERVICES_REQUEST_STATUS_CANCELLED)) {
    myapi_error('service_request_not_cancellable', 409);
  }

  // 6. The optional reason, AFTER the 403 and the 409. Absent body, absent key
  // and whitespace-only all mean "no reason" and are not errors.
  $reason = myapi_service_request_validate_cancel_reason(myapi_request_body());
  if (!$reason['ok']) {
    myapi_error($reason['error_code'], 422, $reason['replacements']);
  }

  // 7. The three writes, in the order the docblock defends.
  //
  // 7a. The request. Its status and nothing else: the assignment fields, the
  // unit, the category, the description and the files stay exactly as they
  // were, and the node stays published — cancelling neither unpublishes nor
  // deletes.
  $node->field_request_status[LANGUAGE_NONE][0]['value'] = MYAPI_SERVICES_REQUEST_STATUS_CANCELLED;
  node_save($node);

  // 7b. The transaction. The four fields of SPEC 92, written the same way; the
  // title is NOT set here — myapi_service_transaction_set_title() puts it in
  // from hook_node_presave(), inside the node_save() below.
  $transaction = (object) [
    'type' => MYAPI_SERVICES_TRANSACTION_TYPE,
    // The resident who cancelled, which is who is behind the token.
    'uid' => $uid,
    'status' => 1,
  ];
  node_object_prepare($transaction);

  $transaction->field_request[LANGUAGE_NONE][0]['target_id'] = $node->nid;
  $transaction->field_request_status[LANGUAGE_NONE][0]['value'] = MYAPI_SERVICES_REQUEST_STATUS_CANCELLED;
  // 'Y-m-d H:i:00': the real instant with the seconds pinned, not that day at
  // midnight. The field is 'datetime' and not 'datestamp' (SPEC 77), so a
  // string is written and not a timestamp.
  $transaction->field_status_date[LANGUAGE_NONE][0]['value'] = date('Y-m-d H:i:00');
  // Only 'value', no 'format': stored raw and escaped by whoever renders it.
  $transaction->field_comment[LANGUAGE_NONE][0]['value'] = myapi_service_request_cancel_comment($reason['value']);

  node_save($transaction);

  // 7c. The offers. Last, and the only step that can leave the data half
  // written — see the function's own docblock for why that is accepted.
  $offers_rejected = myapi_service_request_reject_live_offers($node->nid);

  // 8. The answer: THE SAME NINETEEN-KEY OBJECT the detail serves, rebuilt from
  // the database AFTER the three writes, reusing SPEC 89's own loaders and
  // serialiser — the same decision, and the same shape, as the 201 of SPEC 90.
  // Six queries, and they are the price of the app never having to re-request
  // the screen it is already on: status, offers and timeline all come back in
  // one piece, consistent with each other, and the response cannot drift from
  // what a GET would answer because it IS what a GET answers.
  //
  // `viewer` is always 'requester': step 4 proved whoever got here is the
  // field_requester, and nobody else reaches this line.
  $row = myapi_service_request_detail_row($node->nid);

  // THE ONE THING THAT CAN FAIL AFTER THE WRITES, AND IT MUST NOT FATAL.
  // myapi_service_request_detail_row() INNER JOINs field_requester (guaranteed
  // by step 4), field_category AND taxonomy_term_data — so a request whose
  // category term was deleted resolves to FALSE. Such a request is ALREADY
  // invisible through GET /api/v1/service-requests/% and through the listing,
  // for the same joins, so the resident cannot have opened it; a stale id in
  // the app can still reach here.
  //
  // The cancellation is already written at this point and it is CORRECT, so
  // the truthful answer is 200. What cannot be built is the detail, so the
  // body degrades to the two keys that are known without a query, and the
  // inconsistency is logged instead of swallowed. The app tells the two shapes
  // apart by `viewer`, which only the full object carries. A 500 here would lie
  // about an operation that succeeded and would send the client into a retry
  // that answers 409.
  if (!$row) {
    watchdog(
      'myapi',
      'Service request @nid was cancelled but its detail could not be built: its category term is missing. The response was degraded.',
      ['@nid' => $node->nid],
      WATCHDOG_ERROR
    );

    myapi_respond(
      [
        'service_request' => [
          'id' => (int) $node->nid,
          'status' => MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
        ],
        'offers_rejected' => $offers_rejected,
      ],
      200,
      'service_request_cancelled'
    );
  }

  $row->requester_name = NULL;
  $names = myapi_user_display_names([$uid]);
  if (isset($names[$uid])) {
    $row->requester_name = $names[$uid];
  }

  $images = myapi_service_request_load_images($node->nid);

  // The requester's answer is EVERY offer, so the trim is the empty list — the
  // same argument the detail passes for this reader. They all come back with
  // the statuses step 7c just wrote.
  $offers = array_map(
    'myapi_service_request_build_offer',
    myapi_service_request_load_offers($node->nid, [])
  );

  $offer_counts = myapi_service_request_offer_counts_by_nid([$node->nid]);
  $offers_count = isset($offer_counts[$node->nid]) ? $offer_counts[$node->nid] : 0;

  // The timeline, with the cancellation entry already the last of it. This is
  // why the response carries no separate 'transaction' key: it would be the
  // same object twice.
  $transactions = myapi_service_request_load_transactions($node->nid);

  // offers_rejected is a SIBLING of service_request and not a twentieth key of
  // it, so the object under 'service_request' stays byte-identical to the
  // detail's and the app can swap it in with no special case. It is also the
  // one thing the app cannot deduce from what it just received: `offers` shows
  // which offers are rejected NOW, not which ones THIS call rejected — an
  // offer already rejected before the cancellation looks exactly the same.
  myapi_respond(
    [
      'service_request' => myapi_service_request_build_detail(
        $row,
        'requester',
        $images,
        $offers,
        $offers_count,
        $transactions
      ),
      'offers_rejected' => $offers_rejected,
    ],
    200,
    'service_request_cancelled'
  );
}

/**
 * Validates the optional 'reason' of a cancellation request (SPEC 95).
 *
 * Pure: takes the already-decoded body and returns a verdict, never touching
 * HTTP output nor the node, so the caller decides when to abort. Absent body,
 * absent key and whitespace-only text all resolve to "no reason" without an
 * error — the reason is optional by design, and answering 422 to a resident
 * who cancelled without explaining would put friction on the only exit the app
 * gives them.
 *
 * Length is measured with drupal_strlen() (characters) and not strlen()
 * (bytes): 255 is the same limit reservations and payments use, and a resident
 * writing in Spanish would see 255 accented characters rejected for nothing.
 *
 * This is deliberately NOT myapi_reservation_validate_cancel_reason() nor a
 * shared helper parameterised by the key name: the body key ('reason' here,
 * 'cancel_reason' there) and the @field name that travels in the 422 are
 * different, and the indirection would save twelve lines of which eleven are
 * the return contract.
 *
 * @param mixed $body  Decoded request body, or NULL when there was none.
 *
 * @return array  ['ok' => TRUE, 'value' => string|NULL] when accepted, or
 *                ['ok' => FALSE, 'error_code' => '...', 'replacements' => [...]]
 *                when it must answer 422.
 */
function myapi_service_request_validate_cancel_reason($body) {
  if (!is_array($body) || !array_key_exists('reason', $body)) {
    return ['ok' => TRUE, 'value' => NULL];
  }

  // Present but not a string (array, number, boolean, NULL): the client sent
  // the key on purpose with the wrong type, so this one IS an error.
  if (!is_string($body['reason'])) {
    return ['ok' => FALSE, 'error_code' => 'invalid_field', 'replacements' => ['@field' => 'reason']];
  }

  $value = trim($body['reason']);
  if ($value === '') {
    return ['ok' => TRUE, 'value' => NULL];
  }
  if (drupal_strlen($value) > 255) {
    return ['ok' => FALSE, 'error_code' => 'field_too_long', 'replacements' => ['@field' => 'reason']];
  }

  return ['ok' => TRUE, 'value' => $value];
}

/**
 * Builds the field_comment of the cancellation transaction (SPEC 95).
 *
 * The resident's own words ARE the comment: when a reason came in, it is
 * returned untouched, with no label and no prefix, because the timeline of
 * SPEC 93 already says who wrote the entry and what status it carries. When
 * none came in, an automatic fallback takes its place — SPEC 92 established
 * that no transaction is ever born without a comment, and this function is
 * what keeps that promise without demanding anything from the user. It never
 * returns an empty string.
 *
 * check_plain() is NOT applied: field_comment is stored raw and escaped by
 * whoever renders it, exactly as in SPEC 92.
 *
 * @param string|NULL $reason  The validated reason, or NULL when there is none.
 *
 * @return string  The comment to store, never empty.
 */
function myapi_service_request_cancel_comment($reason) {
  if (is_string($reason) && $reason !== '') {
    return $reason;
  }

  return t('El residente canceló la solicitud.');
}

/**
 * Rejects every live offer of a cancelled request (SPEC 95).
 *
 * A request that is dead must not leave providers working on it, and that is
 * the whole reason this spec exists. 'sent' and 'selected' are the two statuses
 * that mean "still in play", so those are the two this rewrites to 'rejected'.
 * 'withdrawn' and 'rejected' are left alone: the first is the provider's own
 * retreat and overwriting it would erase who walked away by themselves, and the
 * second is already terminal.
 *
 * `no.type = MYAPI_SERVICES_OFFER_TYPE` IS NOT DECORATION. field_request is
 * SHARED with 'service_transaction' (SPEC 77), so without the bundle condition
 * this would pick up the request's own timeline entries and try to write an
 * offer status onto them. The INNER JOIN to field_data_field_offer_status is
 * deliberate too: an offer with no status row has nothing to rewrite.
 *
 * WHY node_load()/node_save() PER OFFER AND NOT ONE db_update(). A direct
 * UPDATE on field_data_field_offer_status would be one query instead of N, and
 * it would leave the revision table and the entity cache lying. The number of
 * offers a request can hold is small and bounded, so the correct write wins
 * over the cheap one.
 *
 * NOT ATOMIC, ON PURPOSE. The caller writes the request's status FIRST, so a
 * failure halfway through this loop leaves live offers pointing at a request
 * that is already 'cancelled' — inconsistent but harmless, because 'cancelled'
 * is terminal in the graph and no flow can award them. No explicit database
 * transaction is opened: node_save() with the Field API and its hooks inside
 * one is a known source of lock-ups in Drupal 7.
 *
 * @param mixed $nid  The cancelled request's nid.
 *
 * @return int  How many offers actually changed status. 0 when the nid is not
 *              a positive integer and 0 when there was nothing live.
 */
function myapi_service_request_reject_live_offers($nid) {
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    return 0;
  }
  $nid = (int) $nid;

  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);
  $query->condition('fq.field_request_target_id', $nid);

  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);

  $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->condition('fos.field_offer_status_value', ['sent', 'selected'], 'IN');

  $query->addField('no', 'nid', 'nid');

  $offer_nids = $query->execute()->fetchCol();

  $rejected = 0;
  foreach ($offer_nids as $offer_nid) {
    $offer = node_load($offer_nid);
    if (!$offer) {
      continue;
    }

    $offer->field_offer_status[LANGUAGE_NONE][0]['value'] = 'rejected';
    node_save($offer);
    $rejected++;
  }

  return $rejected;
}

/**
 * Whether a request still admits being edited by its requester (SPEC 96).
 *
 * TWO CONDITIONS, AND BOTH HAVE TO HOLD: the request is in one of the two
 * PRE-COMMITMENT statuses, and it has received no offer at all. Before anybody
 * has priced the statement of the job, changing it harms nobody; afterwards it
 * does.
 *
 * THE TWO STATUSES ARE 'open' AND 'direct'. They are the two ways a request
 * waits without anybody having committed to a price: 'open' waits for whoever
 * bids, 'direct' waits for the one provider the resident named. Neither carries
 * an accepted offer — a direct award adjudicates no offer at all (SPEC 87), so
 * field_assigned_offer is empty in both — and that, and not the number of
 * actors involved, is what makes an edit harmless. WHAT THE EDIT MAY NOT DO in
 * 'direct' is change WHO the job was given to: assigned_provider_id is not a
 * field of the request body, so the award survives the edit untouched.
 *
 * THE STATUS IS COMPARED STRICTLY AGAINST THE TWO LITERALS, not asked to
 * myapi_services_transition_allowed(). There is no transition to consult here —
 * editing does not move the status — and a corrupt or empty
 * field_request_status is neither of the two, so it falls on the right side of
 * this comparison without a branch of its own. That is what makes a request
 * with garbage in that field a 409 and never a 500.
 *
 * THE SECOND CONDITION IS NOT REDUNDANT WITH THE FIRST, however much the graph
 * suggests it is. The graph says the first offer moves open → offered, but
 * nothing executes that today: myapi.module has no 'service_offer' branch in
 * hook_node_insert() nor in hook_node_update(), so an offer created from the
 * back office leaves the request 'open' with offers hanging off it. This
 * condition covers that hole, and the day the offers endpoint exists and syncs
 * the status, it will simply stop being the one that fires.
 *
 * EVERY PUBLISHED OFFER COUNTS, whatever its status — 'withdrawn' and
 * 'rejected' included. A provider who read the statement and bid on it must not
 * find it changed, and whether they later withdrew or were rejected does not
 * un-read it. The criterion that is expensive to explain is the one that
 * distinguishes, not the one that counts them all.
 *
 * THE OFFER CONDITION APPLIES TO BOTH STATUSES and not only to 'open', even
 * though a 'direct' request is not supposed to have offers: nothing in the
 * module enforces that today either, and the rule reads the same in both cases —
 * whoever priced the statement must not find it changed.
 *
 * PURE: no query, no node_load(), no HTTP output. The caller has already paid
 * for the row and the count, and decides what to answer — which is what lets
 * the unit test run this function directly.
 *
 * @param object $row
 *   The row of myapi_service_request_detail_row(), of which only `status` is
 *   read.
 * @param mixed $offer_count
 *   The number of published offers of the request, from
 *   myapi_service_request_offer_counts_by_nid().
 *
 * @return bool  TRUE when the request may be edited, FALSE otherwise.
 */
function myapi_service_request_update_gate($row, $offer_count) {
  $editable = [
    MYAPI_SERVICES_REQUEST_STATUS_OPEN,
    MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
  ];

  // Strict, so an empty status, a NULL one, 0 or FALSE are none of the two and
  // need no branch of their own.
  $status = isset($row->status) ? $row->status : NULL;
  if (!in_array($status, $editable, TRUE)) {
    return FALSE;
  }

  return (int) $offer_count === 0;
}

/**
 * POST /api/v1/service-requests/% — the resident corrects it (SPEC 96).
 *
 * The second write of this resource on an existing request, after the
 * cancellation, and the one that changes what the job IS instead of what state
 * it is in. Title, description, desired date, images and attachment; nothing
 * else. The steps below are the order SPEC 90 fixed for this file and SPEC 95
 * repeated — nid, token, load, access, status, body — with the writes last.
 *
 * POST AND NOT PUT, the second time this module makes that choice after
 * POST /api/v1/claims/% (SPEC 67), and for the same reason: PHP fills neither
 * $_POST nor $_FILES on a PUT, so a multipart body would arrive raw on
 * php://input and need a MIME parser written by hand. There are files here, so
 * the language made the decision. docs/service-request.md says so out loud,
 * because the app has to know.
 *
 * WHO MAY EDIT: THE field_requester, EXACTLY, the same rule as the
 * cancellation. Not node->uid — a request an operator registered on a
 * resident's behalf belongs to the resident. Not the assigned provider, who may
 * READ the detail and gets 403 here, and not the building admin, who edits from
 * the back office with the native form.
 *
 * THE 404 IS RESOLVED BY myapi_service_request_detail_row(), NOT node_load().
 * One query brings at once the existence criterion (the same one the GET uses,
 * INNER JOIN with the category included), the requester_uid the 403 needs and
 * the status the 409 needs. The node_load() comes afterwards and only to write.
 * That ordering is what avoids the ugly branch of the cancellation: there the
 * detail_row() runs at the END, so a deleted category term forces the response
 * to degrade AFTER the write; here anything that cannot be serialised is
 * answered 404 before a single byte is written.
 *
 * WHEN: 'open' OR 'direct', and with no offer. Both are pre-commitment states —
 * nobody has priced the statement of the job — and 'direct' adjudicates no offer
 * at all (SPEC 87), so an edit there corrects the job without touching who it
 * was given to: assigned_provider_id is not a field of this request body, so the
 * award survives untouched.
 *
 * A REPLACEMENT AND NOT A PATCH. The three text fields travel on every call and
 * a missing one is 422 missing_field, never "leave it as it was": telling
 * "absent" from "empty" in multipart/form-data is slippery, and with three
 * fields the cost to the client is one line. What the request does NOT name is
 * preserved because the node is LOADED and only the named fields are
 * overwritten — field_category, field_unit, field_condominium,
 * field_requester, field_request_status, field_assigned_provider,
 * field_assigned_offer and field_closed_at are never read from the request.
 *
 * NO DATABASE TRANSACTION, like the cancellation and like the claim: a
 * node_save() with the Field API and its hooks inside an explicit transaction
 * is a known source of deadlocks in Drupal 7. The only step that can be left
 * half done is the last one, and its consequence is a dead file on disk that
 * nothing references any more.
 *
 * THE node_save() FIRES NOTHING. hook_node_update() of myapi.module has no
 * 'service_request' branch: no transaction, no notification, no status sync.
 * Editing leaves no trace on the timeline, and that is a decision and not an
 * omission.
 *
 * @param mixed $nid  The route's wildcard, raw.
 */
function myapi_service_request_update($nid) {
  // 1. The nid, before anything else. Same guard as the detail and the
  // cancellation: ctype_digit() over the string form rejects 'abc', '-3', '1.5'
  // and '' alike, and the > 0 then rejects '0'. Costs no query.
  if (!is_scalar($nid) || !ctype_digit((string) $nid) || (int) $nid <= 0) {
    myapi_error('service_request_not_found', 404);
  }
  $nid = (int) $nid;

  // 2. Authentication. Answers 401 missing_authorization / invalid_token on its
  // own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 3. The request, in ONE query that answers three questions — see the
  // docblock. FALSE covers the four causes of a 404 with the same answer: no
  // such nid, another bundle, unpublished, or an orphaned category term.
  $row = myapi_service_request_detail_row($nid);
  if (!$row) {
    myapi_error('service_request_not_found', 404);
  }

  // 4. WHO IS ASKING: field_requester and nothing else. A row whose requester
  // is empty resolves to nobody, so nobody may edit it — 403 and never 200.
  if (!isset($row->requester_uid) || $row->requester_uid === '' || (int) $row->requester_uid !== $uid) {
    myapi_error('service_request_forbidden', 403);
  }

  // 5. The gate, AFTER the 403: an edit on somebody else's request that is also
  // not editable answers 403, because "not yours" is the first true thing about
  // it. It admits 'open' and 'direct' — the two statuses in which nobody has
  // priced the job yet. The offer count is SPEC 88's aggregate, which already
  // filters by bundle and by published — see
  // myapi_service_request_update_gate() for why the count is asked at all when
  // the status supposedly implies it.
  $offer_counts = myapi_service_request_offer_counts_by_nid([$nid]);
  $offer_count = isset($offer_counts[$nid]) ? $offer_counts[$nid] : 0;
  if (!myapi_service_request_update_gate($row, $offer_count)) {
    myapi_error('service_request_not_editable', 409);
  }

  // 6. The body, AFTER the 404, the 403 and the 409, so garbage in the request
  // never masks an access or a status error. The three text fields are read
  // through the request helpers and never off $_POST, and the same checks
  // SPEC 90 wrote for the creation are reused verbatim: one truth per rule.
  $title = myapi_request_post_field('title');
  $description = myapi_request_post_field('description');
  $desired_start_raw = myapi_request_post_field('desired_start');

  $required = [
    'title'         => $title,
    'description'   => $description,
    'desired_start' => $desired_start_raw,
  ];
  foreach ($required as $name => $value) {
    if ($value === NULL || $value === '') {
      myapi_error('missing_field', 422, ['@field' => $name]);
    }
  }

  // 6a. title: node.title is varchar(255).
  if (strlen($title) > 255) {
    myapi_error('invalid_field', 422, ['@field' => 'title']);
  }

  // 6b. desired_start: strtotime() must parse it into a STRICTLY FUTURE
  // instant — so a request whose stored date has already passed cannot be
  // saved again with that same date, which is correct: the resident is being
  // asked when they now want the job done.
  $desired_start = myapi_service_request_parse_desired_start($desired_start_raw);
  if ($desired_start === FALSE) {
    myapi_error('invalid_field', 422, ['@field' => 'desired_start']);
  }

  // 7. The node itself, and now node_load(): what comes next SAVES it, and a
  // write needs the whole entity with its fields. Everything the request does
  // not name survives because this is a LOAD and not a rebuild. It cannot be
  // FALSE here — step 3 proved this nid is a published request of this bundle —
  // so there is no second 404 to answer.
  $node = node_load($nid);

  $current_image_fids = myapi_node_files_current_fids($node, 'field_images');
  $current_attachment_fids = myapi_node_files_current_fids($node, 'field_attachment');

  // 8. remove_image_ids[]: every value a positive integer AND a fid this
  // request references RIGHT NOW. A fid of another request, of a claim or of
  // nothing answers 422 HERE, before a single file touches the disk, and before
  // a stray fid can serve to probe other people's files.
  $removed_image_fids = myapi_node_files_parse_removals('remove_image_ids', $current_image_fids);

  // 9. The image quota: what is LEFT AFTER the removals decides how many new
  // ones fit, so a request that deletes three and uploads three is valid while
  // one that uploads six without deleting anything is not.
  // myapi_node_files_save() already answers service_request_too_many_images
  // when the request sends more than this.
  $kept_count = count($current_image_fids) - count($removed_image_fids);
  $max_new_images = 5 - $kept_count;
  if ($max_new_images < 0) {
    $max_new_images = 0;
  }

  // 10. images[]: all-or-nothing. On failure nothing has been written yet, so
  // the request keeps its text and its original files.
  $image_files = myapi_node_files_save(
    'field_images',
    MYAPI_SERVICES_REQUEST_TYPE,
    isset($_FILES['images']) ? $_FILES['images'] : [],
    $max_new_images,
    'service_request_invalid_image',
    'service_request_too_many_images'
  );

  // 11. attachment: at most 1, and with its OWN error keys — answering the
  // images key for an attachment problem would send the client to the wrong
  // field. On failure the images step 10 had just saved are deleted too
  // ($also_delete), so a rejected attachment leaves no orphan behind.
  $attachment_file = NULL;
  $attachment_saved = myapi_node_files_save(
    'field_attachment',
    MYAPI_SERVICES_REQUEST_TYPE,
    isset($_FILES['attachment']) ? $_FILES['attachment'] : [],
    1,
    'service_request_invalid_attachment',
    'service_request_too_many_attachments',
    $image_files
  );
  if (!empty($attachment_saved)) {
    $attachment_file = reset($attachment_saved);
  }

  // remove_attachment is IGNORED when a new attachment came with the request:
  // field_attachment has cardinality 1, so uploading one already replaces the
  // previous file — the outcome with and without the flag is identical, and a
  // 422 would reject a request whose meaning is unambiguous.
  $remove_attachment_raw = myapi_request_post_field('remove_attachment');
  $remove_attachment = !$attachment_file
    && $remove_attachment_raw !== NULL
    && in_array(strtolower($remove_attachment_raw), ['1', 'true'], TRUE);

  // 12. Overwrite ONLY what the request named, on the loaded node. Not
  // $node->uid either: editing does not take ownership of the request.
  $node->title = $title;
  $node->field_description[LANGUAGE_NONE][0]['value'] = $description;
  $node->field_desired_start[LANGUAGE_NONE][0]['value'] = $desired_start;

  // 13. The final field_images: what survives the removals, in its current
  // delta order, plus the new files at the end. Reordering the existing ones is
  // out of this spec's scope — it needs the client to send the whole order and
  // the server to validate it against what is there, which is another contract.
  $items = [];
  if (isset($node->field_images[LANGUAGE_NONE]) && is_array($node->field_images[LANGUAGE_NONE])) {
    foreach ($node->field_images[LANGUAGE_NONE] as $item) {
      if (isset($item['fid']) && in_array((int) $item['fid'], $removed_image_fids, TRUE)) {
        continue;
      }
      $items[] = $item;
    }
  }
  foreach ($image_files as $file) {
    $items[] = ['fid' => $file->fid, 'display' => 1];
  }
  $node->field_images[LANGUAGE_NONE] = array_values($items);

  // 14. The attachment: replaced, emptied, or left exactly as it was.
  $deleted_attachment_fids = [];
  if ($attachment_file) {
    $node->field_attachment[LANGUAGE_NONE][0] = ['fid' => $attachment_file->fid, 'display' => 1];
    $deleted_attachment_fids = $current_attachment_fids;
  }
  elseif ($remove_attachment) {
    $node->field_attachment[LANGUAGE_NONE] = [];
    $deleted_attachment_fids = $current_attachment_fids;
  }

  // 15. Persist. No hook_node_update() branch reacts to this bundle, so this
  // creates no transaction and sends no notification — see the docblock.
  node_save($node);

  // 16. Tie every new file to the node so cron does not reap it as unused.
  foreach ($image_files as $file) {
    file_usage_add($file, 'myapi', 'node', $node->nid);
  }
  if ($attachment_file) {
    file_usage_add($attachment_file, 'myapi', 'node', $node->nid);
  }

  // 17. Only NOW the files that left the request are deleted for real — the
  // removed images and the attachment that was replaced or dropped. After the
  // node_save() and never before: this is what guarantees no file the node
  // still references is ever destroyed.
  myapi_node_files_delete_removed(array_merge($removed_image_fids, $deleted_attachment_fids), $node->nid);

  // 18. The answer: SIXTEEN KEYS, the detail's nineteen minus 'offers',
  // 'offers_count' and 'transactions'. The gate has just proved there is no
  // offer and the timeline is exactly where it was, so the response neither
  // queries nor repeats them.
  //
  // The consequence has to be accepted with open eyes and is written in
  // docs/service-request.md in these words: THIS OBJECT IS NOT INTERCHANGEABLE
  // WITH THE GET'S, so the app cannot simply swap the detail it has on screen
  // for what this call returns — it has to merge the sixteen keys onto the
  // object it already holds.
  //
  // The row is read AGAIN, after the writes, because the sixteen keys have to
  // equal the GET's byte for byte and the row of step 3 still carries the old
  // title, description and attachment. It cannot fail to serialise: step 3
  // already proved this request has a requester and a live category, and
  // nothing above touches either. `viewer` is always 'requester' — step 4
  // proved whoever got here is the field_requester — and `status` is whatever
  // the request already had, 'open' or 'direct', because editing does not move
  // the status. A 'direct' request comes back with its assigned_provider
  // untouched: the award is not a field of the request body.
  $row = myapi_service_request_detail_row($node->nid);

  $row->requester_name = NULL;
  $names = myapi_user_display_names([$uid]);
  if (isset($names[$uid])) {
    $row->requester_name = $names[$uid];
  }

  $images = myapi_service_request_load_images($node->nid);

  // ONE SERIALISER AND NOT TWO: the sixteen keys come out of the same function
  // that serves the GET, so they cannot drift from it by accident. The three
  // that go are unset AFTER it builds, which is also what keeps the remaining
  // sixteen in the documented order.
  $detail = myapi_service_request_build_detail($row, 'requester', $images, [], 0, []);
  unset($detail['offers_count'], $detail['offers'], $detail['transactions']);

  myapi_respond(['service_request' => $detail], 200, 'service_request_updated');
}
