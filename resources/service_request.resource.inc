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
 *   - PUT /api/v1/service-requests/%/close — the requester closes their own
 *     request and, when a provider did the job, rates it: one 'service_rating'
 *     node, the status, field_closed_at and one entry on the timeline
 *     (SPEC 108).
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
// SPEC 100: myapi_service_request_base_query() and
// myapi_service_request_detail_row(), which lived in this file until the offer
// got a resource of its own and Rule 5 stopped it reaching in here for them.
// SPEC 103 sent myapi_service_request_build_item() and
// myapi_service_request_viewer() the same way, when the offer's detail needed
// to ask who the requester is and what a request item looks like. The four
// moved verbatim and kept their names, so every call site below is the one it
// always was — see the @file docblock of the include.
module_load_include('inc', 'myapi', 'includes/myapi.service_request_query');
// SPEC 100: myapi_service_offer_build(), which lived here as
// myapi_service_request_build_offer() until the offer got a resource of its
// own. Six keys became fifteen; the first six are unchanged, so the three call
// sites below — the detail, the provider's detail and the cancellation —
// answer everything they answered before plus nine keys.
module_load_include('inc', 'myapi', 'includes/myapi.service_offer');
// SPEC 106: the loaders and serialisers of the resident's detail —
// myapi_service_request_offer_counts_by_nid(), myapi_service_request_load_images(),
// myapi_service_request_build_file(), myapi_service_request_load_offers(),
// myapi_service_request_load_transactions() and
// myapi_service_request_build_detail() — which lived in this file since SPECS
// 88, 89 and 93 until PUT /api/v1/service-offers/{id}/accept had to answer the
// resident's whole detail from the offer's resource and Rule 5 stopped it
// reaching in here for them. The six moved verbatim and kept their names, so
// every call site below is the one it always was; this finishes the split
// SPEC 89 started when the query half left for
// includes/myapi.service_request_query.inc — see the @file docblock of the
// include.
module_load_include('inc', 'myapi', 'includes/myapi.service_request_detail');
// SPEC 106: myapi_service_transaction_record(), the four fields of a timeline
// entry, which myapi_service_request_cancel() wrote inline since SPEC 95 until
// the award needed the identical block from the offers' resource. Cancelling
// writes the same node it always wrote; what changed is that one function now
// writes it for both.
module_load_include('inc', 'myapi', 'includes/myapi.service_transaction');
// For myapi_text_to_plain() and myapi_text_to_multiline(): the title and the
// description a resident writes are flattened ON THE WAY IN, so nothing this
// endpoint stores carries markup, and the two names this file serialises are
// flattened on the way out like every other name of the marketplace.
module_load_include('inc', 'myapi', 'includes/myapi.text');

// SPEC 118. The `chat` key of the two listings, and NOT a query of this file's
// own: which requests this reader can already chat about is the rule of
// membership of SPEC 115, and a resource that asked it a second way is a
// resource that would eventually paint a chat button the token does not
// authorise. Rule 5 of CLAUDE.md — the shared criterion lives in includes/.
module_load_include('inc', 'myapi', 'includes/myapi.chat');

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
 * offer counts — plus the one the token costs, plus AT MOST TWO MORE since
 * SPEC 118 for the `chat` key: one for the resident side of the rule of
 * membership and one for the provider side, the second only when the account
 * belongs to a provider at all. None of them grows with the number of rows.
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

  // The chat of the whole page in at most two queries (SPEC 118), never one per
  // request. The reader is the requester here, so every awarded request of the
  // page comes back with a block — but the helper is asked for THIS uid all the
  // same, because the question the key answers is "can I chat about this?" and
  // not "does somebody chat about this?".
  $chat_blocks = myapi_chat_blocks_for_requests($nids, $uid);

  $service_requests = array_map(function ($row) use ($offer_counts, $chat_blocks) {
    $item = myapi_service_request_build_item($row, $offer_counts);

    return myapi_chat_attach_block($item, $row->nid, $chat_blocks);
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
 * page, offers, names — PLUS THE TWO SPEC 118 ADDS for the `chat` key, AND NOT
 * ONE OF THEM GROWS WITH THE PAGE. Twenty requests cost what one costs; the
 * names of the whole page are resolved in ONE call to
 * myapi_user_display_names() and the chat of the whole page in ONE call to
 * myapi_chat_blocks_for_requests(), never one per row.
 *
 * THE `chat` KEY IS THE READER'S AND NOT THE REQUEST'S (SPEC 118), and on this
 * route that is a gate rather than a nicety: set A puts a rival's awarded
 * request on this page on purpose, and that request has a live thread nobody
 * here belongs to. The key is asked for the reader's uid, so it comes back
 * null. See myapi_chat_blocks_for_requests().
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

  // The chat of the whole page (SPEC 118), asked for THE READER and not for the
  // request. That is the whole gate of this key here: set A of the scope above
  // means a rival's request can perfectly well be on this page — "whoever has a
  // live offer needs to see what became of it" — and that request DOES have a
  // live thread, between its resident and whoever won it. It is not mine, so it
  // gets no block and the app paints no chat button.
  //
  // $token->uid and not $uid: the loop of step 13 below reuses that name for
  // each row's requester.
  $chat_blocks = myapi_chat_blocks_for_requests($nids, $token->uid);

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
  $service_requests = array_map(function ($row) use ($offer_counts, $owned, $chat_blocks) {
    $item = myapi_service_request_provider_build_item($row, $offer_counts, $owned);

    return myapi_chat_attach_block($item, $row->nid, $chat_blocks);
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
 * The strict parser the three '?*_id' wrappers of this file are made of.
 *
 * THE PARSING IS NO LONGER WRITTEN HERE (SPEC 102). It moved verbatim to
 * myapi_parse_id_param() in includes/myapi.request.inc the day a second
 * resource needed the same three refusals — a non-scalar, a non-digit string,
 * a zero or negative — and Regla 5 de CLAUDE.md forbade it from reaching into
 * this file for them. The reason each of the three parameters is strict stays
 * written in the wrapper that carries its name; the shape they all refuse is
 * written once, where request parsing lives.
 *
 * THIS NAME STAYS AS A ONE-LINE DELEGATION on purpose. Renaming the three call
 * sites would have been the same change with a bigger diff, and the '@field'
 * of the 422 message of three endpoints already in production is exactly what
 * the move was not allowed to touch.
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
  return myapi_parse_id_param($name);
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
      'name' => isset($row->requester_name) ? myapi_text_to_plain($row->requester_name) : NULL,
    ];
  }

  // Same shape myapi_service_request_build_detail() builds, so the listing and
  // the detail cannot answer two different things about the same condominium.
  // `name` is the NODE TITLE: the 'condominio' bundle has no name field.
  $condominium = NULL;
  if (isset($row->condominium_id) && $row->condominium_id !== '') {
    $condominium = [
      'id'   => (int) $row->condominium_id,
      'name' => isset($row->condominium_name) ? myapi_text_to_plain($row->condominium_name) : NULL,
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
    'myapi_service_offer_build',
    myapi_service_request_load_offers($nid, $provider_ids)
  );

  // The timeline (SPEC 93). NOT TRIMMED BY READER: the provider of the
  // category receives it whole, comments included, the same as the requester.
  // The comments that exist today are written by SPEC 92 and are addressed to
  // the resident; cutting them back "just in case" would be inventing a
  // confidentiality rule no field marks and nobody can consult. It runs AFTER
  // the 403 above, so a reader who fits no rule never pays for this query.
  $transactions = myapi_service_request_load_transactions($nid);

  // The card of the awarded provider (SPEC 89): the same eight keys
  // GET /api/v1/providers answers, so the app paints whoever won the job with
  // the widget it already has. TWO QUERIES, AND ONLY WHEN THERE IS AN AWARD —
  // the helper answers NULL with no query at all for every request nobody has
  // been awarded, which is every 'open' one. The awarded OFFER costs nothing:
  // the serialiser takes it out of $offers, which this response is already
  // carrying.
  $assigned_provider = myapi_service_request_assigned_provider_card($row);

  // 7. Respond 200 (read-only endpoint, no message_key). One object under
  // 'service_request', never a list.
  myapi_respond([
    'service_request' => myapi_service_request_build_detail($row, $viewer, $images, $offers, $offers_count, $transactions, $assigned_provider),
  ], 200);
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
 * THE AWARD IS WIDENED BY THE SHARED FUNCTIONS OF SPEC 89, not by a copy:
 * `assigned_offer` becomes the fifteen-key offer (out of `my_offers`, which is
 * the trim the redaction of a lost bid depends on) and `assigned_provider`
 * becomes the eight-key provider card. Both routes call the same two helpers,
 * so the two details cannot answer two different things about one award.
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
 *   The list of myapi_service_offer_build() items, ALREADY TRIMMED BY
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
 * @param array|NULL $assigned_provider
 *   The card of the awarded provider, from
 *   myapi_service_request_assigned_provider_card(), or NULL when the request
 *   has none. REQUIRED and not optional, for the same reason its twin in
 *   myapi_service_request_build_detail() is.
 *
 * @return array  The nineteen keys, in the documented order.
 */
function myapi_service_request_provider_build_detail($row, array $images, array $my_offers, $offers_count, array $transactions, array $owned_provider_ids, $assigned_provider) {
  $nid = (int) $row->nid;

  // The thirteen keys of the provider's listing, from the provider's listing's
  // own serialiser — the `unit` rule of decision 5 included. The count is
  // handed over as the one-entry map that function expects.
  $item = myapi_service_request_provider_build_item(
    $row, [$nid => (int) $offers_count], $owned_provider_ids
  );

  // THE AWARD, ANSWERED WHOLE (SPEC 89), and by the SAME two functions the
  // resident's detail uses — never by a copy of them. That is what keeps the
  // two routes from ever disagreeing about the same award, and it is why the
  // `unit` rule above is the only thing about this response that is written
  // twice: it is the only one that genuinely differs.
  //
  // ORDER MATTERS HERE. The `unit` rule of
  // myapi_service_request_provider_build_item() measures "is this job mine?"
  // against $item['assigned_provider']['id'], so it has already run by the time
  // the two keys below are widened. `id` is the one key both shapes carry, so
  // the rule would still hold if the order changed; not depending on that is
  // cheaper than remembering it.
  //
  // `my_offers` AND NOT `offers`: the list handed to the serialiser is this
  // reader's own, which is exactly the trim the redaction of a lost bid needs —
  // see myapi_service_request_assigned_offer_item().
  $item['assigned_offer'] = myapi_service_request_assigned_offer_item($item['assigned_offer'], $my_offers);

  if ($item['assigned_provider'] !== NULL) {
    $item['assigned_provider'] = is_array($assigned_provider) ? $assigned_provider : NULL;
  }

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
    'myapi_service_offer_build',
    myapi_service_request_load_offers($nid, $provider_ids)
  );

  // NOT TRIMMED BY READER (decision 9). It runs after the 403 above, so a
  // reader who fits no rule never pays for this query.
  $transactions = myapi_service_request_load_transactions($nid);

  // The card of the awarded provider (SPEC 89), the same one the resident's
  // detail answers and loaded by the same helper. IT IS PUBLIC DATA and it
  // travels whatever the reader: the card is what GET /api/v1/providers already
  // shows to everybody, so a provider who bid and lost learns from it only who
  // won — never what they charged, which is the redaction
  // myapi_service_request_assigned_offer_item() makes over `assigned_offer`.
  $assigned_provider = myapi_service_request_assigned_provider_card($row);

  // 9. Respond 200 (read-only endpoint, no message_key). One object under
  // 'service_request', the same envelope the general detail uses.
  myapi_respond([
    'service_request' => myapi_service_request_provider_build_detail(
      $row, $images, $my_offers, $offers_count, $transactions, $provider_ids, $assigned_provider
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
  // The header block is myapi_file_download_headers()'s and not this file's:
  // the disposition is a security decision (a text/html or an image/svg+xml
  // served inline from this origin is stored XSS) and it is taken in ONE place
  // for the six call sites that stream a managed file. A photo still travels
  // 'inline', which is what the app renders on screen; anything whose type is
  // not on that function's closed list travels as an inert attachment.
  //
  // 'private, no-store' stays here because it is this ENDPOINT's decision and
  // not the file's: the whole point of the route is that the bytes are not
  // cacheable by anything between the app and Drupal.
  module_load_include('inc', 'myapi', 'includes/myapi.file_download');

  file_transfer($file->uri, myapi_file_download_headers($file, [
    'Cache-Control' => 'private, no-store',
  ]));
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
 * SINCE SPEC 109 THE CREATION ALSO NOTIFIES, and that trigger lives here and
 * not in hook_node_insert() — the opposite choice to the transaction above, and
 * deliberately so: a request typed into node/add/service_request by an operator
 * must not push anybody, the same criterion as payments (SPEC 80) and
 * reservations (SPEC 48). myapi_service_request_notify_created() reaches every
 * active provider of the category — or only the awarded one when the request is
 * born 'direct' — plus the detail email to the 'backend' role, and it swallows
 * its own failures into watchdog, so the 201 below is not conditional on any of
 * it. See includes/myapi.service_request_notification.inc and
 * docs/service-request-notifications.md.
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

  // FLATTENED THE MOMENT THEY ARE READ, before the required check and before
  // the length check. A client whose edit screen has a rich-text editor behind
  // it sends `<p>Se rompió la tubería</p>` while its create screen sends plain
  // text, and without this the two write two different things into the same
  // field — which is exactly how a request comes back from the API with a
  // literal `<p>` in it. `title` gets the ONE-LINE helper (node.title is one
  // line by definition) and `description` the MULTILINE one (the paragraphs
  // the resident typed are content). Doing it first is what makes the 255 of
  // the title count characters and not tags, and what makes a description of
  // nothing but `<p></p>` answer 422 instead of storing emptiness. Rows
  // written before this are covered on the way out, in
  // myapi_service_request_build_item().
  $title = $title === NULL ? NULL : myapi_text_to_plain($title);
  $description = $description === NULL ? NULL : myapi_text_to_multiline($description);

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

  // 13. Notify (SPEC 109). After the files are tied to the node, so a failure
  // in here can never leave one orphaned, and before the 201 is built, so the
  // response the resident reads is the last thing that happens. The trigger
  // lives on this endpoint and not on hook_node_insert(): a request typed into
  // the back office notifies nobody. Best-effort end to end — the function
  // swallows its own failures into watchdog, so nothing below is conditional
  // on it.
  module_load_include('inc', 'myapi', 'includes/myapi.service_request_notification');
  myapi_service_request_notify_created($node, [
    'unit_id'              => $unit_id,
    'condominium_id'       => $condominium_id,
    'category_id'          => $category_id,
    'assigned_provider_id' => $assigned_provider_id,
    'requester_uid'        => $uid,
  ]);

  // 14. Respond 201 with the same nineteen-key shape as
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

  // The card of the awarded provider (SPEC 89). NOT DEAD CODE ON THIS ROUTE:
  // a request created with a valid assigned_provider_id is born 'direct' with
  // the award already made, and this 201 is the first place the app sees the
  // company it chose. An 'open' one pays no query for it.
  $assigned_provider = myapi_service_request_assigned_provider_card($row);

  myapi_respond(
    ['service_request' => myapi_service_request_build_detail($row, 'requester', $images, [], 0, $transactions, $assigned_provider)],
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
 *     myapi_service_offer_reject_live().
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

  // 7b. The transaction. The four fields of SPEC 92, written by the one function
  // that writes a timeline entry — extracted to
  // includes/myapi.service_transaction.inc by SPEC 106, where the award needs
  // the identical block. The title is NOT set here or there:
  // myapi_service_transaction_set_title() puts it in from hook_node_presave(),
  // inside that node_save().
  myapi_service_transaction_record(
    $node->nid,
    MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    // The resident who cancelled, which is who is behind the token.
    $uid,
    myapi_service_request_cancel_comment($reason['value'])
  );

  // The offers about to lose their 'sent'/'selected' status, read BEFORE 7c
  // sweeps them: this is the only moment they are still distinguishable from
  // one that was already terminal (SPEC 113). Between the two writes above and
  // this read, on purpose — the same window SPEC 95 already documents and
  // accepts for its own three writes.
  $affected_providers = myapi_service_offer_live_offers_for_request($node->nid);

  // 7c. The offers. Last, and the only step that can leave the data half
  // written — see the function's own docblock for why that is accepted.
  //
  // $affected_providers was captured just above, BEFORE this sweep: after it,
  // an offer that was already 'rejected'/'withdrawn' and one that just became
  // 'rejected' are indistinguishable by status alone (SPEC 113).
  $offers_rejected = myapi_service_offer_reject_live($node->nid);

  // Notify (SPEC 113). After the three writes, so a failure in here can never
  // undo any of them, and before the 8th step builds the response — including
  // the degraded branch below, which still fires this trigger even though its
  // own detail could not be built: the context comes from $node, not from the
  // $row that branch is missing. Best-effort end to end — the function
  // swallows its own failures into watchdog, so nothing below is conditional
  // on it.
  module_load_include('inc', 'myapi', 'includes/myapi.service_request_notification');
  myapi_service_request_notify_cancelled($node, $affected_providers, [
    'request_nid'    => $node->nid,
    'request_title'  => $node->title,
    'condominium_id' => myapi_building_admin_field_target_id($node, 'field_condominium'),
    'unit_id'        => myapi_building_admin_field_target_id($node, 'field_unit'),
    'reason'         => $reason['value'],
  ]);

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
    'myapi_service_offer_build',
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
        $transactions,
        myapi_service_request_assigned_provider_card($row)
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

  // Flattened and not merely trimmed: this string is stored as the
  // field_comment of the cancellation transaction and travels back in the
  // request's timeline, so markup in it would be painted literally by the app.
  // Before the length check, so the 255 counts the resident's words; before
  // the empty check, so a reason of nothing but `<p></p>` is the same as no
  // reason at all and the automatic fallback takes its place.
  $value = myapi_text_to_multiline($body['reason']);
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

  // The same two lines the creation runs, in the same place and for the same
  // reason — see myapi_service_request_create(). THE EDIT IS THE PATH THAT
  // NEEDED THEM MOST: it is the one a client with a rich-text editor behind
  // its form uses, and the one that overwrites a description that was clean.
  $title = $title === NULL ? NULL : myapi_text_to_plain($title);
  $description = $description === NULL ? NULL : myapi_text_to_multiline($description);

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
  // The card of the awarded provider (SPEC 89): a 'direct' request IS editable
  // in its title, its description, its date and its files, and it keeps the
  // provider the resident chose — so this 200 answers the same card the detail
  // does. An 'open' one pays no query for it.
  $detail = myapi_service_request_build_detail(
    $row,
    'requester',
    $images,
    [],
    0,
    [],
    myapi_service_request_assigned_provider_card($row)
  );
  unset($detail['offers_count'], $detail['offers'], $detail['transactions']);

  myapi_respond(['service_request' => $detail], 200, 'service_request_updated');
}

/* ---------------------------------------------------------------------------
 * Closing a request, and rating the provider that did the job (SPEC 108).
 *
 * The exit 'closed' never had. 'cancelled' has had a door since SPEC 95,
 * 'assigned' since SPEC 106, 'offered' since SPEC 100, and 'open' and 'direct'
 * are birth statuses — an awarded request simply could not END, and the
 * provider who did the work earned no reputation for it.
 *
 * CLOSING IS RATING, WHICH IS WHY THEY ARE ONE ENDPOINT.
 * myapi_services_close_requires_rating() (includes/myapi.services_common.inc)
 * has existed since SPEC 77 and nobody had ever called it; it says that
 * closing from 'assigned' and from 'direct' demands a rating. Splitting the
 * verb into two calls would open a window in which the request is closed and
 * unrated, which is exactly the state that function was written to forbid.
 * ------------------------------------------------------------------------ */

/**
 * Dispatcher for /api/v1/service-requests/%/close (SPEC 108).
 *
 * PUT and nothing else, the same single-method shape as
 * myapi_service_request_cancel_dispatch() above, and the same shape as the
 * module's other three '/cancel' routes and as api/v1/claims/%/close — the
 * route SPEC 70 gave the very same verb, on another resource, to the very same
 * actor.
 *
 * THE METHOD IS CHECKED BEFORE THE TOKEN, like every other dispatcher of this
 * file: a GET on this path is answered 405 with no Authorization header and
 * without the request having to exist, because the answer is about the VERB and
 * not about what is behind it.
 *
 * @param mixed $nid  The route's wildcard, raw.
 */
function myapi_service_request_close_dispatch($nid) {
  if (myapi_request_method() === 'PUT') {
    myapi_service_request_close($nid);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * PUT /api/v1/service-requests/%/close — the resident closes and rates
 * (SPEC 108).
 *
 * Seven numbered gate steps and then three writes, in the order the sections
 * below defend. Steps 1 to 5 are, line for line, the ones of
 * myapi_service_request_cancel() with 'closed' where that one writes
 * 'cancelled' and with its own 409 key. That is not copying for convenience:
 * the two endpoints are the same act — the resident ends their request — with
 * two different meanings, and the day the access rule changes it has to change
 * in both.
 *
 * WHO MAY CLOSE: THE field_requester, EXACTLY, and nobody else. Not the
 * assigned provider — the rating is the resident's opinion OF the provider, and
 * a close executed by the provider would arrive without the one thing the
 * catalogue demands it carry, or would ask the provider to score itself. Not an
 * operator through this endpoint either; the back office edits the node. It is
 * also the rule SPEC 95 fixed for the sibling verb and the one SPEC 70 fixed for
 * this same verb on claims: whoever opens is whoever closes. The price — a
 * request that stays 'assigned' forever because the resident never opens the app
 * again — is SPEC 108's risk 1, accepted and documented, and its fix needs new
 * schema.
 *
 * WHICH STATUSES ADMIT IT IS NOT WRITTEN HERE, exactly as in the cancellation:
 * the question goes to myapi_services_transition_allowed(), which owns the
 * graph. Out of that comes free that 'open' answers 409 — there is no edge, and
 * a request nobody has bid on is CANCELLED, which is what it means — and that a
 * request already 'closed' or 'cancelled' answers 409 too, both being terminal.
 * And because that function answers FALSE to an unknown value by design, a
 * corrupt field_request_status is a 409 and never a 500.
 *
 * THE BODY HAS TWO SHAPES AND THE STATUS PICKS ONE. Closing a job, the rating's
 * comment IS the text of the close, so demanding a close_reason on top would be
 * asking for the same thing twice; closing with nothing awarded there is nobody
 * to rate and the reason is all that is left. The shape is chosen by
 * myapi_services_close_requires_rating(), read at step 5 and spent at step 6,
 * which is the order that lets ONE pure validator decide between the two
 * without looking at the node again.
 *
 * THE PROVIDER IS READ FROM THE NODE AND NOT FROM myapi_service_request_detail_
 * row(), the opposite of SPEC 106. The node_load() is already paid for — this
 * endpoint has to SAVE the request — and the detail row's assigned_provider_id
 * arrives FILTERED BY status = 1, so an unpublished provider would leave it NULL
 * and this endpoint would answer 409 to a resident who really did have somebody
 * doing the work. On the node every column is raw.
 *
 * THE ORDER OF THE THREE WRITES IS THE MOST IMPORTANT DECISION OF THIS
 * ENDPOINT:
 *
 *   - THE RATING FIRST. It is the only irrecoverable step. If it fails, the
 *     request is still 'assigned', the resident retries and nothing is lost.
 *     The other way round, with the request already 'closed' — terminal, second
 *     close answers 409 — a rating that failed can NEVER be retried, and that
 *     provider loses the reputation of a job it did. Same reasoning that put the
 *     losing offers before the request in SPEC 106, applied to the step that
 *     costs most to lose.
 *   - THE REQUEST BEFORE THE TRANSACTION, and that is not style.
 *     myapi_service_transaction_sync_request_status() (SPEC 94) hangs off
 *     hook_node_insert() and rewrites the parent request's status from the
 *     transaction's; writing the request first makes it compare two equal
 *     statuses and return WITHOUT saving again. SPEC 95 and SPEC 106 each warn
 *     about this in their own place; this is the third time and it is still the
 *     same property.
 *   - THE COUNTERS ARE A FOURTH WRITE AND THEY ARE NOT THIS ENDPOINT'S. The
 *     'service_rating' branch of myapi_node_insert() fires inside the
 *     node_save() of the first write — see includes/myapi.service_rating.inc for
 *     why the hook and not this function.
 *
 * field_closed_at IS WRITTEN HERE FOR THE FIRST TIME. It is the last field of
 * the bundle that had no writer at all, installed by SPEC 77 and empty ever
 * since. It goes in the same node_save() as the status because they are the same
 * fact — the request closed, and this is the instant — and it is REQUEST_TIME
 * and not time(), like every timestamp of this module.
 *
 * NOTHING ELSE OF THE REQUEST MOVES. The category, the unit, the description,
 * the files and BOTH award fields stay exactly as they were, and the node stays
 * published: closing neither unpublishes nor deletes. The award fields in
 * particular are what a closed request is audited by.
 *
 * NOT IDEMPOTENT, on purpose and for the third time in this module: a second
 * call answers 409 service_request_not_closable and writes nothing — no second
 * rating and no second timeline entry. A silent 200 would hide from the app that
 * its first call did arrive.
 *
 * THE 200 ANSWERS THE WHOLE DETAIL plus one key, the third time this module
 * takes that decision and for the same reason as the other two: the app is on
 * the detail screen, and returning the complete object rebuilt AFTER the writes
 * saves it a GET and makes it impossible for this response to drift from what
 * that GET would answer, because it IS what it would answer.
 *
 * @param mixed $nid  The route's wildcard, raw.
 */
function myapi_service_request_close($nid) {
  // 1. The nid, before anything else and before the token. Same guard as the
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

  // 3. The request itself. node_load() and not a joined query, for the reason
  // the cancellation gives: what comes next SAVES this node, and a write needs
  // the whole entity with its fields. FALSE, the wrong bundle and the
  // unpublished one are all "no such request".
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $node = node_load($nid);
  if (!$node || $node->type !== MYAPI_SERVICES_REQUEST_TYPE || (int) $node->status !== 1) {
    myapi_error('service_request_not_found', 404);
  }

  // 4. WHO IS ASKING. field_requester, never $node->uid. A request with no
  // requester at all resolves to NULL and is a 403: nobody owns it, so nobody
  // may close it. THE ASSIGNED PROVIDER LANDS HERE TOO, which is the point of
  // decision 1.
  $requester_id = myapi_building_admin_field_target_id($node, 'field_requester');
  if ($requester_id === NULL || (int) $requester_id !== $uid) {
    myapi_error('service_request_forbidden', 403);
  }

  // 5. The graph, asked and not transcribed. 'open' lands here as a 409 because
  // the graph carries no open -> closed edge, and so does an already-closed or
  // cancelled request, and so does a corrupt status.
  $status = myapi_building_admin_field_value($node, 'field_request_status');
  if (!myapi_services_transition_allowed($status, MYAPI_SERVICES_REQUEST_STATUS_CLOSED)) {
    myapi_error('service_request_not_closable', 409);
  }

  // 6. The body, AFTER the 403 and the 409, and IN THE SHAPE THIS STATUS ASKS
  // FOR. A 422 arriving before the resident even knows whether they may close
  // would tell the client to fix the form when the problem is another one.
  $requires_rating = myapi_services_close_requires_rating($status);
  $body = myapi_service_request_validate_close_body(myapi_request_body(), $requires_rating);
  if (!$body['ok']) {
    myapi_error($body['error_code'], 422, $body['replacements']);
  }

  // 7. The provider, and ONLY when the status demands a rating. Last condition
  // of the gate and there only for the corrupt case: field_assigned_provider is
  // written by SPEC 90 on every 'direct' and by SPEC 106 on every 'assigned', so
  // its absence means a node edited by hand or a broken reference. Without a
  // provider the rating cannot be saved — field_rating_provider is REQUIRED on
  // the bundle — so the whole close stops here, BEFORE writing anything, rather
  // than closing the request and then failing to rate.
  $provider_nid = NULL;
  $provider_name = NULL;
  if ($requires_rating) {
    $provider_nid = myapi_building_admin_field_target_id($node, 'field_assigned_provider');
    if (empty($provider_nid)) {
      myapi_error('service_request_provider_missing', 409);
    }

    // The provider's name, for the timeline entry alone. node_load() and not a
    // query: Drupal 7's entity static cache means hook_node_presave() resolving
    // the same provider for the rating's title a few lines below pays nothing
    // for it. A provider that cannot be loaded degrades the timeline text to its
    // nameless variant and stops nothing — the nid is what the rating stores.
    $provider = node_load($provider_nid);
    if ($provider && $provider->type === MYAPI_SERVICES_PROVIDER_TYPE) {
      $provider_name = $provider->title;
    }
  }

  // 8. The three writes, in the order the docblock defends.
  //
  // 8a. THE RATING, FIRST, and only when the status demands it. Closing from
  // 'offered' skips this step whole and the rest does not notice.
  //
  // field_rating_offer travels as whatever the request has — a 'direct' has
  // none, which is exactly why SPEC 87 made that instance optional — and
  // field_unit likewise. The title is NOT set here:
  // myapi_service_rating_set_title() puts it in from hook_node_presave(), and
  // the two reputation counters are written by the 'service_rating' branch of
  // myapi_node_insert(), both inside the node_save() this call makes.
  $rating_id = NULL;
  if ($requires_rating) {
    module_load_include('inc', 'myapi', 'includes/myapi.service_rating');

    $rating = myapi_service_rating_record(
      $provider_nid,
      myapi_building_admin_field_target_id($node, 'field_assigned_offer'),
      myapi_building_admin_field_target_id($node, 'field_unit'),
      // The resident behind the token, which is also what SPEC 84 reads to
      // abbreviate `author_name` on the provider's detail.
      $uid,
      $body['values']['stars'],
      $body['values']['comment']
    );
    $rating_id = (int) $rating->nid;
  }

  // 8b. THE REQUEST. Its status and its closing instant, and nothing else: the
  // category, the unit, the description, the files and both award fields stay
  // as they were, and the node stays published.
  //
  // field_closed_at is 'datestamp' (SPEC 77), so what goes in is the integer
  // REQUEST_TIME and not a formatted string — the same shape
  // includes/myapi.service_request_query.inc reads back to serve `closed_at`.
  $node->field_request_status[LANGUAGE_NONE][0]['value'] = MYAPI_SERVICES_REQUEST_STATUS_CLOSED;
  $node->field_closed_at[LANGUAGE_NONE][0]['value'] = REQUEST_TIME;
  node_save($node);

  // 8c. THE TRANSACTION, last, and after the request for the SPEC 94 reason the
  // docblock gives. The rating's own comment does NOT come here: it lives in
  // field_rating_comment, and two copies of one text drift apart the day the
  // back office edits one.
  myapi_service_transaction_record(
    $node->nid,
    MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
    // The resident who closed, which is who is behind the token.
    $uid,
    myapi_service_transaction_close_comment(
      $body['values']['close_reason'],
      $body['values']['stars'],
      $provider_name
    )
  );

  // Notify (SPEC 114). After the three writes, so a failure in here can never
  // undo any of them, and before the 9th step builds the response — including
  // the degraded branch below, which still fires this trigger even though its
  // own detail could not be built: the context comes from $node, not from the
  // $row that branch is missing. Best-effort end to end — the function
  // swallows its own failures into watchdog, so nothing below is conditional
  // on it.
  //
  // $rating reuses the name of the just-saved rating node above: that node's
  // one job (yielding $rating_id) is already done, and this is the same event
  // reduced to what the notifier needs, not a second entity.
  $rating = $requires_rating ? [
    'rating_id'     => $rating_id,
    'provider_id'   => $provider_nid,
    'provider_name' => $provider_name,
    'stars'         => $body['values']['stars'],
    'comment'       => $body['values']['comment'],
  ] : NULL;

  module_load_include('inc', 'myapi', 'includes/myapi.service_request_notification');
  myapi_service_request_notify_closed($node, $rating, [
    'request_nid'    => $node->nid,
    'request_title'  => $node->title,
    'condominium_id' => myapi_building_admin_field_target_id($node, 'field_condominium'),
    'unit_id'        => myapi_building_admin_field_target_id($node, 'field_unit'),
    'close_reason'   => $body['values']['close_reason'],
  ]);

  // 9. The answer: THE SAME NINETEEN-KEY OBJECT the detail serves, rebuilt from
  // the database AFTER the writes with SPEC 89's own loaders and serialiser, so
  // `status` already reads 'closed' and `closed_at` already carries the instant.
  //
  // `viewer` is always 'requester': step 4 proved whoever got here is the
  // field_requester, and nobody else reaches this line.
  $row = myapi_service_request_detail_row($node->nid);

  // THE DEGRADED BRANCH, exactly as in SPEC 95 and SPEC 106.
  // myapi_service_request_detail_row() INNER JOINs the category term, so a
  // request whose term was deleted resolves to FALSE. The close is ALREADY
  // written at this point and it is CORRECT, so the truthful answer is still
  // 200 with the two keys that are known without a query, plus rating_id, and
  // the inconsistency is logged instead of swallowed. A 500 would lie about an
  // operation that succeeded and would send the client into a retry that
  // answers 409.
  if (!$row) {
    watchdog(
      'myapi',
      'Service request @nid was closed but its detail could not be built: its category term is missing. The response was degraded.',
      ['@nid' => $node->nid],
      WATCHDOG_ERROR
    );

    myapi_respond(
      [
        'service_request' => [
          'id' => (int) $node->nid,
          'status' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
        ],
        'rating_id' => $rating_id,
      ],
      200,
      'service_request_closed'
    );
  }

  $row->requester_name = NULL;
  $names = myapi_user_display_names([$uid]);
  if (isset($names[$uid])) {
    $row->requester_name = $names[$uid];
  }

  $images = myapi_service_request_load_images($node->nid);

  // The requester's answer is EVERY offer, so the trim is the empty list — the
  // same argument the detail and the cancellation pass for this reader. Closing
  // moves no offer: whatever SPEC 106 left them at is what comes back.
  $offers = array_map(
    'myapi_service_offer_build',
    myapi_service_request_load_offers($node->nid, [])
  );

  $offer_counts = myapi_service_request_offer_counts_by_nid([$node->nid]);
  $offers_count = isset($offer_counts[$node->nid]) ? $offer_counts[$node->nid] : 0;

  // The timeline, with the close entry already the last of it. This is why the
  // response carries no separate 'transaction' key: it would be the same object
  // twice.
  $transactions = myapi_service_request_load_transactions($node->nid);

  // rating_id is a SIBLING of service_request and not a twentieth key of it, so
  // the object under 'service_request' stays byte-identical to the detail's and
  // the app can swap it in with no special case. It is ALWAYS PRESENT, NULL when
  // the close needed no rating: no key of this module appears and disappears
  // with the case.
  myapi_respond(
    [
      'service_request' => myapi_service_request_build_detail(
        $row,
        'requester',
        $images,
        $offers,
        $offers_count,
        $transactions,
        myapi_service_request_assigned_provider_card($row)
      ),
      'rating_id' => $rating_id,
    ],
    200,
    'service_request_closed'
  );
}

/**
 * Validates the body of a close, in the shape the status asks for (SPEC 108).
 *
 * Pure: takes the already-decoded body and a boolean, and returns a verdict,
 * never touching HTTP output nor the node, so the caller decides when to abort.
 * Same ['ok' => TRUE, 'values' => [...]] contract as
 * myapi_service_offer_validate_body() (SPEC 100), and the same one
 * myapi_service_request_validate_cancel_reason() carries in its single-value
 * form.
 *
 * TWO SHAPES, ONE FUNCTION, AND THE CALLER HAS ALREADY ASKED WHICH.
 * $requires_rating is myapi_services_close_requires_rating() of the status the
 * gate read at step 5, so this function never looks at the node and the decision
 * lives in exactly one place:
 *
 *   A. TRUE ('assigned', 'direct') — { "stars": 5, "comment": "..." }
 *      `stars` is required and must be a key of myapi_services_star_values();
 *      `comment` is optional, up to 1000 characters; `close_reason` is IGNORED
 *      IN SILENCE, because here the rating's comment is the text of the close.
 *
 *   B. FALSE ('offered') — { "close_reason": "..." }
 *      `close_reason` is required, 1 to 1000 characters; `stars` and `comment`
 *      are IGNORED IN SILENCE, because there is nobody to rate.
 *
 * Whichever shape runs, the three keys always come back, the two that do not
 * apply as NULL — the same rule the response follows for rating_id.
 *
 * THE CATALOGUE IS ASKED, NOT TRANSCRIBED. The valid star values come from
 * myapi_services_star_values() (SPEC 77), which is the same source myapi.install
 * builds field_stars' allowed_values from; writing 1..5 here would create a
 * second truth that drifts the day the scale changes.
 *
 * BOOLEANS AND FLOATS ARE REFUSED EXPLICITLY. (int) TRUE is 1, which IS a key of
 * the catalogue, so `"stars": true` would sneak a one-star rating through a bare
 * cast; and 2.5 is not a star value even though it sits inside the range. Only an
 * int, or a string of digits, are accepted — which is what makes 5 and "5"
 * identical, as a JSON client that quotes its numbers deserves.
 *
 * close_reason IS REQUIRED WHILE THE CANCELLATION'S reason IS OPTIONAL, and the
 * difference is not an oversight. Cancelling is walking away and the reason
 * explains itself; CLOSING WITH NOTHING AWARDED, having received offers, leaves
 * the providers who sent them hanging, and that timeline entry is the only thing
 * left to explain it to them. It is also the treatment SPEC 70 already gives the
 * same word on the other resource.
 *
 * 1000 CHARACTERS AND NOT 255. The 255 of SPEC 95 is for a one-line reason; both
 * texts here are a resident's written opinion about a service, and the module's
 * precedent for that is the close_reason of SPEC 70. Measured with
 * drupal_strlen() (characters) and not strlen() (bytes), so a resident writing
 * in Spanish does not see 1000 accented characters rejected for nothing.
 *
 * KEYS THAT ARE LEFT OVER ARE NOT A 422, same criterion as the rest of the
 * module: what is named is validated and nothing else. An app that sends the
 * whole form in both cases works, which is what one wants from an app.
 *
 * @param mixed $body
 *   Decoded request body, or anything at all when there was none.
 * @param bool $requires_rating
 *   myapi_services_close_requires_rating() of the request's current status.
 *
 * @return array
 *   ['ok' => TRUE, 'values' => ['stars' => int|NULL, 'comment' => string|NULL,
 *   'close_reason' => string|NULL]] when accepted, or ['ok' => FALSE,
 *   'error_code' => string, 'replacements' => array] when it must answer 422.
 */
function myapi_service_request_validate_close_body($body, $requires_rating) {
  if (!is_array($body)) {
    $body = [];
  }

  $values = ['stars' => NULL, 'comment' => NULL, 'close_reason' => NULL];

  /* ---- B. No award: close_reason and nothing else. ---- */
  if (!$requires_rating) {
    if (!array_key_exists('close_reason', $body)) {
      return _myapi_service_request_close_invalid('missing_field', 'close_reason');
    }
    // Present but not a string (array, number, boolean, NULL): the client sent
    // the key on purpose with the wrong type, so this one IS an error.
    if (!is_string($body['close_reason'])) {
      return _myapi_service_request_close_invalid('invalid_field', 'close_reason');
    }

    // Flattened, like the cancellation reason and for the same reason: it ends
    // up in the closing transaction's comment, which the timeline serves back.
    $close_reason = myapi_text_to_multiline($body['close_reason']);
    // Empty after the flattening is the SAME as absent, and both are
    // missing_field: a reason made of spaces explains nothing to the providers
    // who bid.
    if ($close_reason === '') {
      return _myapi_service_request_close_invalid('missing_field', 'close_reason');
    }
    if (drupal_strlen($close_reason) > 1000) {
      return _myapi_service_request_close_invalid('field_too_long', 'close_reason');
    }

    $values['close_reason'] = $close_reason;

    return ['ok' => TRUE, 'values' => $values];
  }

  /* ---- A. There was a provider: stars, and an optional comment. ---- */
  if (!array_key_exists('stars', $body)) {
    return _myapi_service_request_close_invalid('missing_field', 'stars');
  }

  $stars = myapi_service_request_parse_stars($body['stars']);
  if ($stars === NULL) {
    return _myapi_service_request_close_invalid('invalid_field', 'stars');
  }
  $values['stars'] = $stars;

  if (array_key_exists('comment', $body)) {
    if (!is_string($body['comment'])) {
      return _myapi_service_request_close_invalid('invalid_field', 'comment');
    }

    // Flattened: this is the rating the provider's card publishes, so it is
    // read by everybody who opens that provider — the last place markup should
    // survive. Before the 1000, which therefore counts what was written.
    $comment = myapi_text_to_multiline($body['comment']);
    if (drupal_strlen($comment) > 1000) {
      return _myapi_service_request_close_invalid('field_too_long', 'comment');
    }
    // Empty after the flattening is the same as absent, and NOT an error: the
    // comment is optional, and a resident who rates without writing owes
    // nobody words.
    if ($comment !== '') {
      $values['comment'] = $comment;
    }
  }

  return ['ok' => TRUE, 'values' => $values];
}

/**
 * Resolves a raw `stars` value to a key of the catalogue, or to nothing
 * (SPEC 108).
 *
 * Extracted from the validator above so that "what counts as a star value" is
 * one decision in one place, and so the validator reads as the list of its
 * fields. Pure, and the whole matrix of refusals fits in tests/unit.
 *
 * WHAT IS ACCEPTED: an integer, or a string made only of digits. Nothing else.
 * That is what makes 5 and "5" resolve to the same int — a JSON client that
 * quotes its numbers is not wrong — while it refuses, on purpose:
 *   - booleans, because (int) TRUE is 1 and 1 IS a valid star value, so a bare
 *     cast would turn `"stars": true` into a one-star rating nobody wrote;
 *   - floats, because 2.5 is not a star even though it sits inside the range;
 *   - '-3' and '1.5', because ctype_digit() refuses the sign and the dot;
 *   - arrays, objects and NULL, which are not scalars at all.
 *
 * THE RANGE IS NOT WRITTEN HERE. Whether the resulting integer is a star is
 * asked of myapi_services_star_values() (SPEC 77) — the same catalogue
 * myapi.install builds field_stars' allowed_values from — so 0 and 6 are
 * refused by the catalogue and not by a literal, and the day the scale changes
 * nothing here has to.
 *
 * @param mixed $raw
 *   The `stars` value as it came out of the JSON body.
 *
 * @return int|null
 *   The number of stars, or NULL when the value is not one.
 */
function myapi_service_request_parse_stars($raw) {
  if (is_bool($raw) || !is_scalar($raw)) {
    return NULL;
  }

  if (is_int($raw)) {
    $stars = $raw;
  }
  elseif (is_string($raw) && ctype_digit($raw)) {
    $stars = (int) $raw;
  }
  else {
    return NULL;
  }

  $star_values = myapi_services_star_values();

  return isset($star_values[$stars]) ? $stars : NULL;
}

/**
 * The 422 verdict of myapi_service_request_validate_close_body() (SPEC 108).
 *
 * Six call sites in one function say the same three-key array; this is that
 * array, written once. Underscore-prefixed and not documented in docs/: it is
 * private to the close validator, exactly like _myapi_service_offer_invalid()
 * is to SPEC 100's.
 *
 * @param string $error_code
 *   'missing_field', 'invalid_field' or 'field_too_long'.
 * @param string $field
 *   The body key that failed, as it travels in the message's @field.
 *
 * @return array
 *   ['ok' => FALSE, 'error_code' => string, 'replacements' => array].
 */
function _myapi_service_request_close_invalid($error_code, $field) {
  return [
    'ok' => FALSE,
    'error_code' => $error_code,
    'replacements' => ['@field' => $field],
  ];
}
