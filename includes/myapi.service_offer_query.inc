<?php

/**
 * @file
 * The queries GET /api/v1/service-offers/provider is made of (SPEC 102).
 *
 * "WHAT HAVE I QUOTED?" — a set that CROSSES requests instead of living inside
 * one, which is exactly what makes it a file of its own and not one more
 * function in includes/myapi.service_request_query.inc. Everything there starts
 * from a service_request and narrows; everything here starts from ONE PROVIDER
 * and gathers the offers hanging off it, whatever request each one belongs to.
 *
 * IT DOES NOT REVERSE THE DECISION OF SPEC 100. resources/service_offer.resource.inc
 * says there is no GET of the collection on purpose, and that stays true for
 * THE OFFERS OF A REQUEST: those are the competition, they have to agree with
 * `offers_count`, and a third place to read them would be a third place to keep
 * in step. This set publishes no counter of anybody, so there is nothing it can
 * fall out of step with.
 *
 * THE THREE FUNCTIONS ARE THE SHAPE OF SPEC 98 — base_query, count, fetch — and
 * for its reason: `pagination.total` describes the FILTERED set and not the
 * page, so the count has to run over the same scope the page does. The base
 * query is the ONE place that scope is written; neither of the other two adds a
 * condition of its own.
 *
 * THE SCOPE IS AN EQUALITY AND NOT AN ACCESS RULE. There is no A ∪ B ∪ C here,
 * no market, nothing to keep in step with myapi_service_request_viewer(): an
 * offer is in this set when its field_provider IS the provider asked for. What
 * decides whether that provider may be asked for at all is
 * myapi_service_offer_provider_scope(), below, in PHP, in one place.
 *
 * Like the rest of this module's read paths, these read the field_data_* tables
 * directly instead of going through node_load(), with the trade-off
 * docs/service-offer.md documents: they assume the current field storage. And
 * like every query of includes/myapi.service_request_query.inc, none of them
 * carries ->addTag('node_access') — see that file's @file docblock for why.
 */

// For MYAPI_SERVICES_OFFER_TYPE, MYAPI_SERVICES_REQUEST_TYPE and
// MYAPI_SERVICES_PROVIDER_TYPE: no bundle machine name is ever written as a
// literal here.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');

// For myapi_service_offer_build(): the item below TAKES six of its fifteen keys
// instead of rewriting them, so the two listings cannot disagree about what an
// amount or a date looks like. See myapi_service_offer_provider_build_item().
module_load_include('inc', 'myapi', 'includes/myapi.service_offer');

/**
 * The query the count and the page are both built on: one provider's offers,
 * with the request each one hangs off, and the five optional filters
 * (SPEC 102).
 *
 * THE SET IS FOUR CONDITIONS AND NOT ONE MORE:
 *
 *   n.type = 'service_offer' AND n.status = 1
 *   AND fp.field_provider_target_id = :provider_id
 *   AND the request exists, is of the request bundle and is published
 *
 * NOTHING ELSE FILTERS. Not the offer's status — 'sent', 'selected',
 * 'rejected' and 'withdrawn' all travel — not the request's, 'cancelled'
 * included, and not the provider's own state: neither provider.status nor
 * field_license_expiry appear anywhere in this file. The licence governs the
 * MARKET, which is being able to quote, and not the ARCHIVE of what was already
 * quoted; a suspended provider losing its own quotes would lose the proof of
 * work it did. That the caller may ask about this provider at all is decided
 * before this query runs, by myapi_service_offer_provider_scope().
 *
 * THE REFERENCE TO THE REQUEST IS field_request AND NOT 'field_offer_request'.
 * SPEC 102 names the table 'field_data_field_offer_request' in its step 2; no
 * such field exists — SPEC 77 installed ONE reference field, field_request,
 * shared by 'service_offer' and 'service_transaction', which is what
 * myapi_service_request_load_offers() and myapi_service_offer_live_offer_exists()
 * already read. The spec's intent is unambiguous (join the offer to its
 * request); only the table name in it is wrong, and the schema is what this
 * file follows.
 *
 * BOTH HOPS TO THE REQUEST ARE INNER, and that is the one decision of this
 * query that DROPS rows. Every item of this listing has to be openable: an
 * offer whose request was unpublished or deleted is a row that answers 404 the
 * moment it is touched, so it is not listed and — because the count runs over
 * this same query — it is not counted either. A LEFT JOIN answering
 * `request: null` was the alternative and it is worse: it makes the client
 * paint a row that leads nowhere and tell two classes of row apart.
 *
 * `nr.type` ON THE NODE JOIN IS NOT DECORATION, for the same reason
 * myapi_service_offer_live_offer_exists() carries it: field_request is SHARED
 * with 'service_transaction' (SPEC 77), so without the bundle condition a
 * reference pointing anywhere else could paint its title as the request's.
 *
 * THE CATEGORY IS INNER-JOINED, AND TWICE — the field row and the term — which
 * is byte for byte what myapi_service_request_base_query() does with the same
 * two tables and for the same reason: field_category is required, so an orphan
 * tid is an operator having deleted a term from the vocabulary, and a services
 * row with no category is not actionable. The inconsistency must SHOW as a
 * missing row rather than spread as a null every client paints its own way.
 * '?category_id' adds NO table on top: it is one more condition on a join the
 * response needs anyway.
 *
 * EVERY OTHER JOIN IS LEFT, AND THE FOUR OFFER FIELDS ARE LEFT FOR A REASON OF
 * THEIR OWN: an offer created before myapi_update_7035() has no row in
 * field_data_field_offer_amount_type nor in field_data_field_offer_valid_until,
 * so a single INNER there would erase every historic offer from a provider's
 * own archive. They answer null, which is what myapi_service_offer_build()
 * serves for them and what the acceptance criteria demand.
 *
 * NO JOIN CAN MULTIPLY A ROW: every field joined has cardinality 1 and is
 * joined on entity_id, which is the primary key of those tables;
 * taxonomy_term_data.tid and node.nid are primary keys. So the count needs no
 * DISTINCT and the page cannot repeat an offer.
 *
 * THE FIVE FILTERS COMPOSE WITH AND and every one of them is optional. Four of
 * them read a table that is already joined, so none adds a table to the count.
 *
 * @param mixed $provider_id
 *   The provider nid, already validated as a positive integer AND already
 *   confirmed to belong to the reader by the caller. This function does not
 *   check either: it is the scope, not the gate.
 * @param array $filters
 *   ['status' => array|NULL (offer status keys),
 *    'request_status' => array|NULL (request status keys),
 *    'category_id' => int|NULL (tid of the REQUEST's category),
 *    'created_from' => int|NULL, 'created_to' => int|NULL (Unix timestamps
 *    compared against the OFFER's n.created)]. All optional; NULL means no
 *    filter on that column.
 *
 * @return SelectQuery
 *   The query, with these aliases already in place: n (the offer node),
 *   fp (provider reference), fq (request reference), nr (the request node),
 *   fcat (category field), td (term), cc (category code), frs (request status),
 *   fos / foa / fat / fvu (the four offer columns).
 */
function myapi_service_offer_provider_base_query($provider_id, array $filters = []) {
  $query = db_select('node', 'n')
    ->condition('n.type', MYAPI_SERVICES_OFFER_TYPE)
    ->condition('n.status', 1);

  // The scope, and the only one: whose offer this is. INNER — an offer with no
  // provider row belongs to nobody and can never equal a nid.
  $query->innerJoin('field_data_field_provider', 'fp', "fp.entity_id = n.nid AND fp.entity_type = 'node' AND fp.deleted = 0");
  $query->condition('fp.field_provider_target_id', (int) $provider_id);

  // The request, in two INNER hops — see the docblock. The bundle condition on
  // the node join is what keeps field_request's OTHER bundle out.
  $query->innerJoin('field_data_field_request', 'fq', "fq.entity_id = n.nid AND fq.entity_type = 'node' AND fq.deleted = 0");
  $query->innerJoin('node', 'nr', 'nr.nid = fq.field_request_target_id AND nr.type = :request_type AND nr.status = 1', [':request_type' => MYAPI_SERVICES_REQUEST_TYPE]);

  // The REQUEST's category, INNER on both tables, LEFT on the code — the exact
  // three joins myapi_service_request_base_query() and its fetch make.
  $query->innerJoin('field_data_field_category', 'fcat', "fcat.entity_id = nr.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0");
  $query->innerJoin('taxonomy_term_data', 'td', 'td.tid = fcat.field_category_tid');
  $query->leftJoin('field_data_field_category_code', 'cc', "cc.entity_id = td.tid AND cc.entity_type = 'taxonomy_term' AND cc.deleted = 0");

  // The REQUEST's status: LEFT, so a request whose status row was deleted by
  // hand still carries its offers into the list with a null status instead of
  // making them vanish. Once '?request_status' is active the IN excludes it on
  // its own, which is the right reading of "show me the closed ones".
  $query->leftJoin('field_data_field_request_status', 'frs', "frs.entity_id = nr.nid AND frs.entity_type = 'node' AND frs.deleted = 0");

  // The four columns of the offer itself. ALL LEFT — see the docblock: two of
  // them do not exist on any offer older than SPEC 100.
  $query->leftJoin('field_data_field_offer_status', 'fos', "fos.entity_id = n.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->leftJoin('field_data_field_offer_amount', 'foa', "foa.entity_id = n.nid AND foa.entity_type = 'node' AND foa.deleted = 0");
  $query->leftJoin('field_data_field_offer_amount_type', 'fat', "fat.entity_id = n.nid AND fat.entity_type = 'node' AND fat.deleted = 0");
  $query->leftJoin('field_data_field_offer_valid_until', 'fvu', "fvu.entity_id = n.nid AND fvu.entity_type = 'node' AND fvu.deleted = 0");

  // '?status' arrives as a LIST, so the condition is always an IN — with one
  // element for the single-value form, which MySQL resolves exactly like the
  // '=' it replaces. Same shape as myapi_service_request_base_query().
  if (!empty($filters['status'])) {
    $query->condition('fos.field_offer_status_value', (array) $filters['status'], 'IN');
  }

  // '?request_status' filters by the state of the REQUEST and never of the
  // offer. The two catalogues have no value in common (SPEC 77), so a mix-up
  // would answer an empty list rather than the wrong one — which is exactly why
  // they are two parameters and not one.
  if (!empty($filters['request_status'])) {
    $query->condition('frs.field_request_status_value', (array) $filters['request_status'], 'IN');
  }

  if (!empty($filters['category_id'])) {
    $query->condition('fcat.field_category_tid', $filters['category_id']);
  }

  // The creation date, on the OFFER's own node column — when I quoted, never
  // when the request was asked for. It adds no table, and being a column of `n`
  // it cannot multiply a row. Each bound is already the instant that makes its
  // day inclusive; the conversion lives in the parser, so this file compares
  // numbers and never parses a date.
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

  return $query;
}

/**
 * Counts the provider's offers under the current filters, unpaginated
 * (SPEC 102).
 *
 * Nothing but myapi_service_offer_provider_base_query()->countQuery(): the
 * total and the page are, by construction, the same set — which is what makes
 * `pagination.total` describe the FILTERED set and not the page.
 *
 * IT COUNTS WHAT THE PAGE WOULD LIST, INCLUDING THE INNER JOIN TO THE REQUEST.
 * An offer whose request was unpublished or deleted is absent from both, and
 * that agreement is not a detail: a total that counted it would send the client
 * looking for a row no page ever returns.
 *
 * Every table the base joins is cardinality 1 (or a primary key), so there is
 * one row per offer and the count needs no DISTINCT. The four LEFT-joined offer
 * columns and the category code add columns, never rows, so counting with them
 * answers the same number the fetch's set has.
 *
 * @param mixed $provider_id
 *   The provider nid, already validated and already confirmed to be the
 *   reader's by the caller.
 * @param array $filters
 *   Same shape as myapi_service_offer_provider_base_query().
 *
 * @return int  Number of matching offers.
 */
function myapi_service_offer_provider_count($provider_id, array $filters = []) {
  return (int) myapi_service_offer_provider_base_query($provider_id, $filters)
    ->countQuery()
    ->execute()
    ->fetchField();
}

/**
 * Fetches one page of the provider's offers, with every column the item needs
 * (SPEC 102).
 *
 * Starts from the same myapi_service_offer_provider_base_query() the count uses
 * — so the page is always a slice of the counted set — and adds ONLY
 * projection. Not a join and not a condition: every table it reads from is
 * already in the base query, because the base joined them for the filters and
 * for the request the INNER JOIN guarantees. That is the frontier SPEC 88 drew
 * between a base and a fetch, kept here.
 *
 * THE SIX OFFER ALIASES ARE THE NAMES myapi_service_offer_build() READS —
 * `nid`, `created`, `status`, `amount`, `amount_type`, `valid_until` — and they
 * are not a coincidence: myapi_service_offer_provider_build_item() runs that
 * serialiser over this row and picks six of its fifteen keys, so the day the
 * format of `amount` changes it changes in both listings or in neither. The
 * nine keys of that serialiser this query does not project answer their
 * documented absence (null, "" for `message`, FALSE for `requires_visit`), and
 * the item drops all nine.
 *
 * THE REQUEST'S STATUS IS `request_status` AND THE OFFER'S IS `status`. Two
 * catalogues with no value in common (SPEC 77) travelling in the same row: one
 * alias each, and the pair that decides which is which lives here rather than
 * in the builder.
 *
 * `request_id` IS nr.nid — THE JOINED NODE — AND NEVER fq.field_request_target_id.
 * With an INNER JOIN the two agree by construction today; projecting the joined
 * node is what keeps them agreeing the day somebody relaxes that join, and it
 * is the rule every id of this module's read paths follows.
 *
 * The category code is LEFT-joined by the base, so a term with no
 * field_category_code answers "" through the builder and keeps its offer —
 * exactly what /api/v1/service-categories, /api/v1/providers and both request
 * listings already do with this same field.
 *
 * THE ORDERING IS TWO CRITERIA ON THE OFFER'S OWN created, AND THE SECOND IS
 * NOT DECORATION. `n.created` is "the last thing I quoted, on top" — never the
 * request's date, which would sort the provider's work by somebody else's
 * clock — and `n.nid` breaks the tie. Without it, two offers sent in the same
 * second can swap places between page 1 and page 2, so the app shows one twice
 * and never shows the other: the trap SPEC 83 documented with rating_avg and
 * SPEC 88 with this same pair. Both take the SAME direction, so '?sort=asc'
 * reverses the listing coherently.
 *
 * @param mixed  $provider_id  The provider nid, already validated and owned.
 * @param array  $filters      Same shape as the base query.
 * @param int    $page         1-based page number. Ignored when $limit is -1.
 * @param int    $limit        Rows per page, or -1 for "all matching rows,
 *                             unpaginated" (SPEC 15).
 * @param string $sort         'asc' or 'desc' on the OFFER's creation date.
 *
 * @return array  Rows carrying `nid`, `created`, `status`, `amount`,
 *                `amount_type`, `valid_until`, `request_id`, `request_title`,
 *                `request_status`, `category_id`, `category_name` and
 *                `category_code`.
 */
function myapi_service_offer_provider_fetch($provider_id, array $filters, $page, $limit, $sort) {
  $query = myapi_service_offer_provider_base_query($provider_id, $filters);

  // The offer, under the six aliases the shared serialiser reads.
  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'created', 'created');
  $query->addField('fos', 'field_offer_status_value', 'status');
  $query->addField('foa', 'field_offer_amount_value', 'amount');
  $query->addField('fat', 'field_offer_amount_type_value', 'amount_type');
  $query->addField('fvu', 'field_offer_valid_until_value', 'valid_until');

  // The request: the joined node, never the raw target_id — see the docblock.
  $query->addField('nr', 'nid', 'request_id');
  $query->addField('nr', 'title', 'request_title');
  $query->addField('frs', 'field_request_status_value', 'request_status');

  // Its category, off the three joins the base already made.
  $query->addField('fcat', 'field_category_tid', 'category_id');
  $query->addField('td', 'name', 'category_name');
  $query->addField('cc', 'field_category_code_value', 'category_code');

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
 * The provider this listing is about, resolved ONCE (SPEC 102).
 *
 * TWO QUESTIONS AND ONE ANSWER: "may this account ask about this nid" and
 * "what is this provider called". They are resolved together because the
 * response needs both and neither is worth a call of its own — and they answer
 * FALSE together, because the endpoint does exactly the same thing in both
 * cases: a 200 with an empty list.
 *
 * THE OWNERSHIP IS NOT DECIDED HERE. It is read from
 * myapi_provider_role_provider_ids() (SPEC 78), which is the ONE place this
 * module decides whose provider a node is, over field_provider_users, and
 * which is statically cached per uid so asking it costs nothing the second
 * time. A query of our own would be a second definition of ownership, drifting
 * the day SPEC 78's changes. Same reason myapi_service_offer_provider_row()
 * defers to it.
 *
 * OWNERSHIP IS CHECKED FIRST, AND THAT IS WHAT MAKES A FOREIGN NID COST NO
 * QUERY. A nid that is not the account's is refused off the cached list, so
 * nobody can probe which provider nodes exist by timing this endpoint — and a
 * foreign nid and a nid of no node at all reach the same FALSE by two different
 * routes on purpose. This is the rule of SPEC 98 applied to a mandatory
 * parameter: STRICT IN THE FORMAT, LAX IN THE OWNERSHIP. The format was already
 * refused with a 422 by myapi_parse_id_param() before this function ran; from
 * here on, "not yours" and "does not exist" are the same empty list, never a
 * 403 that would turn a listing into an oracle for "this nid exists and is not
 * yours".
 *
 * NO status = 1 CONDITION ON THE PROVIDER, deliberately, and it is the one
 * place in this module where that absence is the FEATURE. A suspended provider
 * reads its whole archive: the licence governs the MARKET — being able to quote
 * — and not the record of what was already quoted, and an account that lost its
 * own quotes would lose the proof of work it did. field_license_expiry is not
 * read here either, for the same reason. The bundle condition IS there: a nid
 * of another bundle is not a provider, whoever it belongs to.
 *
 * `name` IS THE NODE TITLE, which is what the 'provider' bundle carries and
 * what `offers` and `my_offers` already paint as provider.name — never a field.
 * It is resolved once and copied into every item of the page, so the listing
 * never joins file_managed or node per row to repeat the same string.
 *
 * @param mixed $provider_id
 *   The provider nid, already validated as a positive integer by
 *   myapi_parse_id_param(). A value that is not one answers FALSE rather than
 *   querying.
 * @param mixed $uid
 *   The authenticated reader's uid.
 *
 * @return array|FALSE
 *   ['id' => int, 'name' => string] when the nid is one of the account's
 *   providers AND the node exists; FALSE in every other case — foreign,
 *   non-existent, of another bundle, or a malformed uid.
 */
function myapi_service_offer_provider_scope($provider_id, $uid) {
  $provider_id = (int) $provider_id;

  if ($provider_id <= 0) {
    return FALSE;
  }

  // (object) ['uid' => n] is enough: myapi_provider_role_provider_ids() does
  // not read ->roles. Same lightweight account the rest of the services domain
  // builds when all it needs is the link.
  $account = (object) ['uid' => (int) $uid];

  $owned = array_map('intval', myapi_provider_role_provider_ids($account));

  // Not yours: no query, and the same answer a nid of no node gets.
  if (!in_array($provider_id, $owned, TRUE)) {
    return FALSE;
  }

  $row = db_select('node', 'np')
    ->condition('np.nid', $provider_id)
    ->condition('np.type', MYAPI_SERVICES_PROVIDER_TYPE)
    ->fields('np', ['nid', 'title'])
    ->range(0, 1)
    ->execute()
    ->fetchObject();

  if (!$row) {
    return FALSE;
  }

  return [
    'id'   => (int) $row->nid,
    'name' => isset($row->title) ? $row->title : NULL,
  ];
}

/**
 * Serialises one row of GET /api/v1/service-offers/provider: EIGHT keys, always
 * the eight, in this order (SPEC 102).
 *
 * PURE: no database, no node_load(). Same split includes/myapi.service_offer.inc
 * follows, and what lets tests/unit fix the whole shape of this response with
 * no site booted.
 *
 * THE SIX SHARED KEYS ARE TAKEN FROM myapi_service_offer_build() AND NOT
 * REWRITTEN, and that is the single most important line of this function. The
 * (float) that keeps `amount` from travelling as "95.50", the format_date()
 * that makes the two dates 'Y-m-d\TH:i:s', and the rule that an empty string is
 * a null are written ONCE, in the serialiser of fifteen keys that `offers` and
 * `my_offers` already answer with. This item is that serialiser TRIMMED, never
 * a second one: the day somebody rounds `amount` there, this listing rounds it
 * too, or the change does not happen at all. It cannot drift without somebody
 * deleting the call — which is exactly what the test that compares the six keys
 * over the same row guards.
 *
 * THE SEVEN LONG KEYS ARE DROPPED ON PURPOSE — message, includes, excludes,
 * duration, tax_included, warranty_days, requires_visit, available_from. They
 * are read where they are already read: inside GET /api/v1/service-requests/provider/%,
 * in `my_offers`, with all fifteen. Multiplied by fifty rows they would turn a
 * listing into a download. This endpoint is REFERENTIAL by decision: enough to
 * paint the row and open the detail, and not half a detail.
 *
 * `provider` IS COPIED FROM THE ARGUMENT AND NEVER READ OFF THE ROW. It is the
 * same object in every item of the page — myapi_service_offer_provider_scope()
 * resolved it ONCE, outside the loop — so the listing never joins node or
 * file_managed per row to repeat the same string twenty times. It is {id, name}
 * and NOT {id, name, logo} like `offers` and `my_offers`: the logo is the
 * reader's OWN, which the app already has, and fetching it would cost a
 * leftJoin to file_managed on every row to paint the same image. It is never
 * null — a page with no provider was answered as an empty list before this
 * function ran.
 *
 * `request` IS NEVER NULL EITHER, and that is the INNER JOIN of the base query
 * showing up in the contract: every item of this listing can be opened. It
 * carries the label and nothing more — no description, no unit, no
 * offers_count, no assigned_provider — because it is what lets the client paint
 * the row and navigate, not a second copy of the detail.
 *
 * `request.category` IS {id, code, name}, in the order and with the types of
 * the SPEC 98 item: `code` is "" and never null when the term carries none,
 * because the field is required on the vocabulary and an empty one is corrupt
 * data the client can still compare as a string.
 *
 * NOT ONE DATUM OF THE COMPETITION OR OF THE CUSTOMER TRAVELS. No requester, no
 * condominium, no unit, no count of rival offers and no amount of theirs. What
 * a provider reads here is its own archive.
 *
 * @param object $row
 *   One row of myapi_service_offer_provider_fetch(), carrying `nid`, `created`,
 *   `status`, `amount`, `amount_type`, `valid_until`, `request_id`,
 *   `request_title`, `request_status`, `category_id`, `category_name` and
 *   `category_code`.
 * @param array $provider
 *   ['id' => int, 'name' => string], as myapi_service_offer_provider_scope()
 *   returns it — resolved once for the whole page.
 *
 * @return array  The eight keys above, in that order.
 */
function myapi_service_offer_provider_build_item($row, array $provider) {
  // The fifteen-key serialiser, run once over this row. Six of its keys are
  // this item's first six; the other nine are dropped here and never rewritten.
  $offer = myapi_service_offer_build($row);

  return [
    'id'          => $offer['id'],
    'status'      => $offer['status'],
    'amount'      => $offer['amount'],
    'amount_type' => $offer['amount_type'],
    'created'     => $offer['created'],
    'valid_until' => $offer['valid_until'],
    // Copied, not read off the row — see the docblock.
    'provider'    => [
      'id'   => isset($provider['id']) ? (int) $provider['id'] : NULL,
      'name' => isset($provider['name']) ? $provider['name'] : NULL,
    ],
    'request'     => [
      'id'       => isset($row->request_id) ? (int) $row->request_id : NULL,
      'title'    => isset($row->request_title) ? $row->request_title : '',
      'status'   => isset($row->request_status) ? $row->request_status : NULL,
      // Three keys, in the same order /api/v1/service-categories and the two
      // request listings print them — id, code, name.
      'category' => [
        'id'   => isset($row->category_id) ? (int) $row->category_id : NULL,
        'code' => isset($row->category_code) ? $row->category_code : '',
        'name' => isset($row->category_name) ? $row->category_name : '',
      ],
    ],
  ];
}
