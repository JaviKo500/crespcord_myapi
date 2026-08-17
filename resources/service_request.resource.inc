<?php

/**
 * @file
 * Service request resource for the myapi module (SPEC 88).
 *
 * One route, read-only and authenticated:
 *
 *   - GET /api/v1/service-requests — the paginated listing of the service
 *     requests the reader created (SPEC 88).
 *
 * The first endpoint of the 'service_request' bundle. SPEC 77, 86 and 87 built
 * its schema — the fields, the status catalogue, the 'direct' status — without
 * a single route; this file is where the bundle finally gets one.
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
 * NOTHING HERE WRITES. Requests and offers are loaded by the operator from the
 * back office, and field_assigned_offer / field_assigned_provider are filled in
 * by no flow yet: POST, PUT and DELETE answer 405. The detail
 * (GET /api/v1/service-requests/%) — with the unit, the condominium, the
 * images, the attachment, closed_at and the offers one by one — is another
 * spec, and so is every write.
 *
 * Like the other read resources of this module, the queries read the
 * field_data_* tables directly instead of going through node_load(): the
 * listing answers ten keys per request, and node_load() of twenty of them would
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

/**
 * Dispatcher for /api/v1/service-requests (SPEC 88).
 *
 * Only GET is allowed. Creating, editing, cancelling, closing and awarding a
 * request are all out of this spec — every one of them needs decisions this
 * listing does not have to make — so there is no POST, PUT or DELETE.
 *
 * The method is checked BEFORE any authentication, the same criterion every
 * other dispatcher of this module follows: a POST answers 405 without a token,
 * because the method is wrong whoever is asking.
 */
function myapi_service_request_dispatch() {
  if (myapi_request_method() === 'GET') {
    myapi_service_request_list();
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
 * sort or status falls back to its default in silence, never a 422: a listing
 * that breaks the whole screen because '?page=abc' arrived is punishing the
 * client for a decorative parameter, and the 422 is reserved for write bodies,
 * where a bad value corrupts something. The single exception is '?category_id',
 * which answers 422 for the reason written in
 * myapi_service_request_parse_category_id() — and it is checked FIRST, before
 * the pagination and before the count, so a malformed request costs no query at
 * all.
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

  // 2. The only parameter that can be WRONG rather than merely unknown, and it
  // goes before everything else on purpose: a 422 must not cost a query.
  $category_id = myapi_service_request_parse_category_id();

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

  // 4. The filters, both optional and composing with AND. '?status' is
  // validated against the catalogue and never against a list written here.
  $filters = [
    'status'      => myapi_service_request_parse_status(),
    'category_id' => $category_id,
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
 * is_scalar() comes FIRST, so '?category_id[]=1' answers 422 without ever
 * reaching a string cast of an array. Same guard, in the same order, as
 * myapi_provider_list().
 *
 * Never returns on a bad value: myapi_error() prints and exits.
 *
 * @return int|NULL
 *   The tid to narrow by, or NULL when the parameter is absent.
 */
function myapi_service_request_parse_category_id() {
  if (!isset($_GET['category_id'])) {
    return NULL;
  }

  $raw = $_GET['category_id'];
  if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'category_id']);
  }

  return (int) $raw;
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
 * n.status, the requester, the category joins and the two filters — and
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
 * THE QUERY DELIBERATELY CARRIES NO ->addTag('node_access'). See the @file
 * docblock: the tag would run myapi_provider_role_alter_node_query() over this
 * query, and a resident who also holds the 'proveedor' role would stop seeing
 * their own requests of categories they do not attend.
 *
 * @param int   $uid      Authenticated reader uid. The whole scope.
 * @param array $filters  ['status' => array|NULL (list of status keys),
 *                        'category_id' => int|NULL]. Both optional; NULL means
 *                        no filter on that column, and they compose with AND.
 *
 * @return SelectQuery  The query, with these aliases already in place:
 *                      n (node), fr (requester), fcat (category field),
 *                      td (taxonomy term), frs (status).
 */
function myapi_service_request_base_query($uid, array $filters = []) {
  $query = db_select('node', 'n')
    ->condition('n.type', MYAPI_SERVICES_REQUEST_TYPE)
    ->condition('n.status', 1);

  // The scope, and the only one: whose request this is.
  $query->innerJoin('field_data_field_requester', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->condition('fr.field_requester_target_id', $uid);

  // The category, INNER on both tables — see the docblock. The '?category_id'
  // filter below adds NO table: it is one more condition on a join that was
  // already here because the response has to paint the category anyway.
  $query->innerJoin('field_data_field_category', 'fcat', "fcat.entity_id = n.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0");
  $query->innerJoin('taxonomy_term_data', 'td', 'td.tid = fcat.field_category_tid');

  // LEFT: joined here because the filter reads it, projected by the fetch.
  $query->leftJoin('field_data_field_request_status', 'frs', "frs.entity_id = n.nid AND frs.entity_type = 'node' AND frs.deleted = 0");

  // '?status' arrives as a LIST, so the condition is always an IN — with one
  // element for the single-value form, which MySQL resolves exactly like the
  // '=' it replaces. Same shape as myapi_claim_base_query().
  if (!empty($filters['status'])) {
    $query->condition('frs.field_request_status_value', (array) $filters['status'], 'IN');
  }

  if (!empty($filters['category_id'])) {
    $query->condition('fcat.field_category_tid', $filters['category_id']);
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
 * columns: the term name, the description, the desired start, and the awarded
 * offer and provider.
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
 * @return array  Rows carrying the twelve aliases
 *                myapi_service_request_build_item() reads.
 */
function myapi_service_request_fetch($uid, array $filters, $page, $limit, $sort) {
  $query = myapi_service_request_base_query($uid, $filters);

  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'title', 'title');
  $query->addField('n', 'created', 'created');

  // The category: the tid off the field row, the name off the term. Both joins
  // already live in the base query.
  $query->addField('fcat', 'field_category_tid', 'category_id');
  $query->addField('td', 'name', 'category_name');

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
 * ALWAYS THE SAME TEN KEYS, IN THIS ORDER — id, title, description, status,
 * category, offers_count, assigned_offer, assigned_provider, created,
 * desired_start — because that order is the one drupal_json_encode() prints and
 * the one docs/service-request.md shows. No key is ever added or dropped by the
 * data: a request with no offers and no award answers all ten just the same.
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
 * The isset() guards are not decoration. `category` comes from two INNER JOINs
 * and cannot be missing in practice, and description and desired_start are
 * required at the instance level; but they are LEFT-joined, so a field row
 * deleted by hand would hand a NULL in here, and answering "" or null beats a
 * PHP notice in the middle of a listing.
 *
 * @param object $row
 *   One row of myapi_service_request_fetch(), carrying the aliases `nid`,
 *   `title`, `description`, `status`, `category_id`, `category_name`,
 *   `assigned_offer_id`, `assigned_offer_status`, `assigned_provider_id`,
 *   `assigned_provider_name`, `created` and `desired_start`.
 * @param array $offer_counts
 *   The map myapi_service_request_offer_counts_by_nid() returns for the WHOLE
 *   page, keyed by nid. Passed in and not looked up: resolving it here would
 *   mean one query per request, and the whole page is meant to cost three.
 *
 * @return array
 *   The ten keys above, in that order.
 */
function myapi_service_request_build_item($row, array $offer_counts) {
  $nid = (int) $row->nid;

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
    'category'    => [
      'id'   => isset($row->category_id) ? (int) $row->category_id : NULL,
      'name' => isset($row->category_name) ? $row->category_name : '',
    ],
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
