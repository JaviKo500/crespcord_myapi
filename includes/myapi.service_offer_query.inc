<?php

/**
 * @file
 * The queries GET /api/v1/service-offers/provider is made of (SPEC 102), plus
 * the row and the serialisers its two detail routes are made of (SPEC 103).
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
 * SPEC 103 ADDED THE DETAIL OF ONE OFFER, at the bottom of this file and
 * behind its own banner. It is the same set seen one row at a time: the same
 * INNER JOIN to the published request, the same fifteen aliases, and the same
 * myapi_service_offer_provider_scope() as the gate — never a copy of it, which
 * is what keeps a row that the listing shows from answering 403 when opened
 * (riesgo 5). Its two pure serialisers live here too, beside the listing's,
 * because a file that owns the query of a resource owns the shape of its
 * answer as well.
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

// SPEC 103: myapi_service_request_build_item(), of which the `request` block of
// the detail takes five of its seven keys instead of rewriting them — so the
// same request cannot describe itself one way inside its own endpoint and
// another way inside an offer that hangs off it. See
// myapi_service_offer_build_context().
module_load_include('inc', 'myapi', 'includes/myapi.service_request_query');

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

/* ---------------------------------------------------------------------------
 * The detail of ONE offer (SPEC 103).
 *
 * Its two routes — the provider's and the resident's — read the SAME row and
 * differ only in who they let in and in two keys of the context block. What
 * follows is the query, and nothing above it changes.
 * ------------------------------------------------------------------------ */

/**
 * The row GET /api/v1/service-offers/provider/% and GET /api/v1/service-offers/%
 * are both built on: one offer, whole, with its request's nid (SPEC 103).
 *
 * ONE QUERY AND THE SERVABLE SET IS THREE CONDITIONS, no more:
 *
 *   offer.status = 1  AND  offer.type = 'service_offer'
 *   AND request.status = 1        (INNER JOIN through field_request)
 *
 * NOTHING ELSE. Not the offer's status key, not the request's, not
 * np.status and not field_license_expiry: an offer 'withdrawn' on a request
 * 'cancelled' from a suspended provider is served whole. Whoever misses one of
 * the three gets FALSE, and the caller answers 404 without saying which — the
 * four ways in are indistinguishable on purpose.
 *
 * THE INNER JOIN TO THE REQUEST NODE IS NOT A CONVENIENCE. field_request is
 * SHARED with 'service_transaction' (SPEC 77) — the same warning
 * myapi_service_offer_provider_base_query() carries above — so a join that
 * stopped at the field table would serve a timeline entry as if it were a
 * request. `nr.type` is what keeps the other bundle out, and `nr.status = 1`
 * is what makes a request that was unpublished answer 404 instead of a 200
 * with half a context nobody can paint (decision 7).
 *
 * THE FIFTEEN ALIASES ARE myapi_service_request_load_offers()'s, JOIN FOR JOIN.
 * They are copied and not invented: the same offer read through `offers`,
 * through `my_offers` and through this detail has to answer the same fifteen
 * keys, and it only does while both queries hand myapi_service_offer_build()
 * the same columns. All fourteen field joins are LEFT for the reason that file
 * spells out — an offer older than myapi_update_7035() has no row in ANY of the
 * ten quote tables, and a single INNER would 404 the whole archive.
 *
 * TWO COLUMNS FOR THE PROVIDER, AND THE DIFFERENCE IS THE WHOLE POINT:
 *
 *   provider_id   from the JOINED node, which carries status = 1. This is what
 *                 myapi_service_offer_build() paints, so a suspended provider
 *                 answers `provider: null` here exactly as it already does
 *                 inside `offers` — one datum, one answer, in every route.
 *   provider_raw  the RAW field_provider_target_id. This is what the GATE
 *                 reads, and it has to be the raw one: SPEC 102 decided that a
 *                 suspended provider still reads its own archive — the licence
 *                 governs the market, not the record of what was already quoted
 *                 — and myapi_service_offer_provider_scope() carries no
 *                 status condition for that reason. Gating on the joined column
 *                 would answer 403 to an account for its OWN offer the day its
 *                 provider node is unpublished.
 *
 * It is the same split myapi_service_request_detail_row() already makes with
 * `assigned_provider_id` and `assigned_provider_raw`: the joined column is what
 * the client is shown, the raw column is what the access rule decides over.
 *
 * ONLY `nr.nid` IS PROJECTED OFF THE REQUEST, as `request_id`, and that is a
 * decision and not an oversight (decision 9). The context block is built from
 * myapi_service_request_detail_row(), which OWNS what a condominium, a unit and
 * a category are; projecting them here would be a second definition of all
 * three inside a file that is not their home. Two queries in the detail of one
 * item, against zero duplicated definitions.
 *
 * NO JOIN CAN MULTIPLY THE ROW: every field is cardinality 1 and joined on
 * entity_id, which is the primary key of those tables, and node.nid is a
 * primary key. So range(0, 1) is a guard and not a trim.
 *
 * @param mixed $nid
 *   The OFFER's nid, already validated as a positive integer by
 *   myapi_parse_id_param(). A value that is not one still answers FALSE.
 *
 * @return object|FALSE
 *   The row, carrying the fifteen aliases myapi_service_offer_build() reads —
 *   `nid`, `provider_id`, `provider_name`, `provider_logo_uri`, `amount`,
 *   `message`, `status`, `created`, `amount_type`, `valid_until`,
 *   `available_from`, `duration`, `duration_unit`, `includes`, `excludes`,
 *   `tax_included`, `warranty_days` and `requires_visit` — plus `provider_raw`
 *   for the gate and `request_id` for the context. FALSE when the offer does
 *   not exist, is unpublished, is of another bundle, or its request is not a
 *   published 'service_request'.
 */
function myapi_service_offer_detail_row($nid) {
  $nid = (int) $nid;

  if ($nid <= 0) {
    return FALSE;
  }

  $query = db_select('node', 'n')
    ->condition('n.nid', $nid)
    ->condition('n.type', MYAPI_SERVICES_OFFER_TYPE)
    ->condition('n.status', 1);
  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'created', 'created');

  // The request, in two INNER hops. The bundle condition on the node join is
  // what keeps field_request's OTHER bundle out — see the docblock — and only
  // nr.nid travels.
  $query->innerJoin('field_data_field_request', 'fq', "fq.entity_id = n.nid AND fq.entity_type = 'node' AND fq.deleted = 0");
  $query->innerJoin('node', 'nr', 'nr.nid = fq.field_request_target_id AND nr.type = :request_type AND nr.status = 1', [':request_type' => MYAPI_SERVICES_REQUEST_TYPE]);
  $query->addField('nr', 'nid', 'request_id');

  // The provider, in three LEFT hops: the reference, the node, its logo file.
  // Byte for byte myapi_service_request_load_offers()'s, status = 1 included:
  // an unpublished provider leaves provider_id NULL and the offer is STILL
  // served, which is what `offers` already answers for the same row.
  $query->leftJoin('field_data_field_provider', 'fp', "fp.entity_id = n.nid AND fp.entity_type = 'node' AND fp.deleted = 0");
  $query->leftJoin('node', 'np', 'np.nid = fp.field_provider_target_id AND np.type = :provider_type AND np.status = 1', [':provider_type' => MYAPI_SERVICES_PROVIDER_TYPE]);
  $query->addField('np', 'nid', 'provider_id');
  $query->addField('np', 'title', 'provider_name');

  // The raw target_id, for the GATE only — see the docblock. Read off the field
  // row, so an offer whose provider node was unpublished is still its owner's.
  $query->addField('fp', 'field_provider_target_id', 'provider_raw');

  // The logo is public:// (SPEC 85), so what travels is the uri and the
  // serialiser turns it into a direct URL. Both hops LEFT: no logo, or a
  // file_managed row that no longer exists, answers null.
  $query->leftJoin('field_data_field_logo', 'lg', "lg.entity_id = np.nid AND lg.entity_type = 'node' AND lg.deleted = 0");
  $query->leftJoin('file_managed', 'fml', 'fml.fid = lg.field_logo_fid');
  $query->addField('fml', 'uri', 'provider_logo_uri');

  $query->leftJoin('field_data_field_offer_amount', 'foa', "foa.entity_id = n.nid AND foa.entity_type = 'node' AND foa.deleted = 0");
  $query->addField('foa', 'field_offer_amount_value', 'amount');

  $query->leftJoin('field_data_field_offer_message', 'fom', "fom.entity_id = n.nid AND fom.entity_type = 'node' AND fom.deleted = 0");
  $query->addField('fom', 'field_offer_message_value', 'message');

  $query->leftJoin('field_data_field_offer_status', 'fost', "fost.entity_id = n.nid AND fost.entity_type = 'node' AND fost.deleted = 0");
  $query->addField('fost', 'field_offer_status_value', 'status');

  // The ten quote columns of SPEC 100, ALL LEFT and for that file's reason: an
  // offer created before myapi_update_7035() has no row in any of these ten
  // tables, and one INNER here would 404 every historic offer.
  $query->leftJoin('field_data_field_offer_amount_type', 'fat', "fat.entity_id = n.nid AND fat.entity_type = 'node' AND fat.deleted = 0");
  $query->addField('fat', 'field_offer_amount_type_value', 'amount_type');

  $query->leftJoin('field_data_field_offer_valid_until', 'fvu', "fvu.entity_id = n.nid AND fvu.entity_type = 'node' AND fvu.deleted = 0");
  $query->addField('fvu', 'field_offer_valid_until_value', 'valid_until');

  $query->leftJoin('field_data_field_offer_available_from', 'faf', "faf.entity_id = n.nid AND faf.entity_type = 'node' AND faf.deleted = 0");
  $query->addField('faf', 'field_offer_available_from_value', 'available_from');

  $query->leftJoin('field_data_field_offer_duration', 'fdu', "fdu.entity_id = n.nid AND fdu.entity_type = 'node' AND fdu.deleted = 0");
  $query->addField('fdu', 'field_offer_duration_value', 'duration');

  $query->leftJoin('field_data_field_offer_duration_unit', 'fdn', "fdn.entity_id = n.nid AND fdn.entity_type = 'node' AND fdn.deleted = 0");
  $query->addField('fdn', 'field_offer_duration_unit_value', 'duration_unit');

  $query->leftJoin('field_data_field_offer_includes', 'fin', "fin.entity_id = n.nid AND fin.entity_type = 'node' AND fin.deleted = 0");
  $query->addField('fin', 'field_offer_includes_value', 'includes');

  $query->leftJoin('field_data_field_offer_excludes', 'fex', "fex.entity_id = n.nid AND fex.entity_type = 'node' AND fex.deleted = 0");
  $query->addField('fex', 'field_offer_excludes_value', 'excludes');

  $query->leftJoin('field_data_field_offer_tax_included', 'fti', "fti.entity_id = n.nid AND fti.entity_type = 'node' AND fti.deleted = 0");
  $query->addField('fti', 'field_offer_tax_included_value', 'tax_included');

  $query->leftJoin('field_data_field_offer_warranty_days', 'fwd', "fwd.entity_id = n.nid AND fwd.entity_type = 'node' AND fwd.deleted = 0");
  $query->addField('fwd', 'field_offer_warranty_days_value', 'warranty_days');

  $query->leftJoin('field_data_field_offer_requires_visit', 'frv', "frv.entity_id = n.nid AND frv.entity_type = 'node' AND frv.deleted = 0");
  $query->addField('frv', 'field_offer_requires_visit_value', 'requires_visit');

  $query->range(0, 1);

  $row = $query->execute()->fetchObject();

  return $row ? $row : FALSE;
}

/**
 * "IS THIS JOB ALREADY MINE?" — one boolean, over the request's award
 * (SPEC 103).
 *
 * THE RULE OF SPEC 98, MOVED FROM THE REQUEST TO THE OFFER, and unchanged:
 *
 *   mine  ⟺  request.field_assigned_provider ∈ myapi_provider_role_provider_ids(uid)
 *
 * IT IS DECIDED ON THE AWARD AND NOT ON THE OFFER'S OWN STATUS (decision 5).
 * Normally the two agree — the awarded provider is the one whose offer was
 * selected — and when they diverge the AWARD wins, because it is the one that
 * says which house I am going to. An offer sitting at 'selected' on a request
 * that ended up awarded to somebody else gives no right to anybody's address;
 * an offer at 'rejected' or 'withdrawn' on a request awarded to me does, and
 * for the same reason: the work is mine either way.
 *
 * IT READS `assigned_provider_id` — THE COLUMN OF THE JOINED NODE — AND NEVER
 * `assigned_provider_raw`, and that is the whole of riesgo 7. The joined column
 * carries the bundle and status = 1 of myapi_service_request_detail_row(), so
 * an award pointing at a provider that was deleted or unpublished leaves it
 * NULL and this answers FALSE: a broken reference CLOSES the address instead of
 * opening it. The raw column is what myapi_service_request_viewer() reads,
 * deliberately and for the opposite reason — there a broken award still reads
 * as "awarded", because losing access to your own job over corrupt data is
 * worse than seeing it. Here what is at stake is a resident's street address,
 * so the two readings differ on purpose.
 *
 * PURE: no database, no node_load(), no account. Both arguments arrive already
 * resolved — the row from myapi_service_request_detail_row(), the list from
 * myapi_provider_role_provider_ids() — which is what lets a test fix all four
 * cases with no site booted.
 *
 * IT IS THE SAME COMPARISON myapi_service_request_provider_build_item() MAKES,
 * written once. That one compares the BUILT key `assigned_provider.id`; this
 * one compares the column that key is built from, which is the same value one
 * level down. If the two ever disagree, the symptom is a provider reading an
 * address in one endpoint and a null in the other for the same request.
 *
 * @param object|FALSE $request_row
 *   A row of myapi_service_request_detail_row(), of which only
 *   `assigned_provider_id` is read. FALSE or anything that is not an object
 *   answers FALSE — a caller that has no request has no job either.
 * @param array $owned_provider_ids
 *   The reader's provider nids, from myapi_provider_role_provider_ids(). An
 *   empty list answers FALSE: an account with no provider owns no job.
 *
 * @return bool  TRUE when the request is awarded to one of the reader's
 *               providers. Never NULL — this is the boolean two keys of the
 *               response hang on, and a null would make them a third thing.
 */
function myapi_service_offer_detail_is_mine($request_row, array $owned_provider_ids) {
  if (!is_object($request_row) || !$owned_provider_ids) {
    return FALSE;
  }

  // The JOINED column — see the docblock. A broken or unpublished award reads
  // NULL here and closes the address.
  if (!isset($request_row->assigned_provider_id) || $request_row->assigned_provider_id === '') {
    return FALSE;
  }

  return in_array(
    (int) $request_row->assigned_provider_id,
    array_map('intval', $owned_provider_ids),
    TRUE
  );
}

/**
 * The `request` block: SEVEN KEYS, ALWAYS THE SEVEN, in this order (SPEC 103).
 *
 *   id, title, status, category, condominium, unit, requester
 *
 * THE SEVEN TRAVEL IN BOTH ROUTES AND IN EVERY CASE; TWO CHANGE CONTENT. It is
 * the doctrine of SPEC 89 — "the keys are always the same; some change what
 * they carry" — and it is what leaves a client with nothing to branch on: it
 * reads seven keys whoever it is, and a value that is null means "not for you"
 * or "not there", which are the same thing to a screen that has nothing to
 * paint either way.
 *
 * IT DECIDES NOTHING. Both booleans arrive already resolved: the provider's
 * route works them out of myapi_service_offer_detail_is_mine(), the resident's
 * route hard-codes them. Putting the decision in here would mean this function
 * needed a uid, a role and a scope, and the two routes would share a branch
 * instead of sharing a serialiser — which is exactly the shape SPEC 99 walked
 * back.
 *
 * FIVE OF THE SEVEN ARE NOT WRITTEN HERE. `id`, `title`, `status`, `category`
 * and `unit` are taken from myapi_service_request_build_item(), the serialiser
 * the two request listings and the two request details already answer with, so
 * the same request cannot describe itself one way inside its own endpoint and
 * another way inside an offer that hangs off it. The (int) of the ids, the ""
 * of a missing title, the "" — never null — of a category code with no term
 * value, and the rule that `unit.name` is field_nombre_vivienda AND NOT the
 * node title are all written once, over there. The empty map is what that
 * function takes for "no offer counts": this block publishes no counter, which
 * is precisely why it can never fall out of step with `offers_count`.
 *
 * `condominium` AND `requester` ARE BUILT HERE, for the THIRD time in the
 * module — the other two are myapi_service_request_provider_build_item() and
 * myapi_service_request_build_detail(). That is decision 4, and it is CONSCIOUS
 * DEBT, not an oversight: extracting a shared pure would mean editing code of
 * SPECS 89 and 98 that nobody is otherwise touching. The day a fourth site
 * needs it, the extraction stops being optional. What makes the debt detectable
 * is that ServiceOfferDetailTest fixes the three shapes against each other, so
 * a divergence fails in red the same day it is written.
 *
 * `condominium` TRAVELS ALWAYS AND FOR EVERYONE, with no `mine` condition
 * (decision 11). It names the residential complex, not a person and not a door,
 * and without it a provider does not know which city it is quoting for. Same
 * decision SPEC 89 took over the same datum. Its `name` is the NODE TITLE: the
 * 'condominio' bundle has no name field.
 *
 * `unit` AND `requester` ARE A WHOLE null WHEN THEY ARE NOT SHOWN, never
 * {id: null, name: null}. A provider cannot tell "not awarded to me" from "the
 * unit was deleted", and has no reason to: in both cases there is no address to
 * paint. Two half-answers would only invite a client to branch on which half is
 * missing.
 *
 * PURE: no database, no node_load(), no account, no $_GET.
 *
 * @param object $request_row
 *   A row of myapi_service_request_detail_row(), carrying every alias
 *   myapi_service_request_build_item() reads plus `condominium_id`,
 *   `condominium_name`, `requester_uid` and `requester_name`. The caller has
 *   already answered 404 if there was none.
 * @param bool $show_requester
 *   TRUE to publish `requester`. FALSE answers a whole null. Resolved by the
 *   caller: "the job is already mine" for the provider, always FALSE for the
 *   resident, who is the requester.
 * @param bool $show_unit
 *   TRUE to publish `unit`. FALSE answers a whole null. "The job is already
 *   mine" for the provider; always TRUE for the resident, whose home it is.
 *
 * @return array  The seven keys above, in that order.
 */
function myapi_service_offer_build_context($request_row, $show_requester, $show_unit) {
  // The five shared keys, from the serialiser that owns them. The empty map is
  // "no offer counts": this block publishes no counter — see the docblock.
  $item = myapi_service_request_build_item($request_row, []);

  // Always, for everyone — see the docblock. Same shape the request's own
  // detail builds, so the two cannot answer two different condominiums.
  $condominium = NULL;
  if (isset($request_row->condominium_id) && $request_row->condominium_id !== '') {
    $condominium = [
      'id'   => (int) $request_row->condominium_id,
      'name' => isset($request_row->condominium_name) ? $request_row->condominium_name : NULL,
    ];
  }

  // Only when the caller says so, and a WHOLE null otherwise. The requester's
  // join is INNER, so on a shown block the null below only fires on corrupt
  // data. NO PHONE, NO EMAIL, NO ADDRESS: no response of this module publishes
  // a contact datum and this one does not open that door.
  $requester = NULL;
  if ($show_requester && isset($request_row->requester_uid) && $request_row->requester_uid !== '') {
    $requester = [
      'id'   => (int) $request_row->requester_uid,
      'name' => isset($request_row->requester_name) ? $request_row->requester_name : NULL,
    ];
  }

  return [
    'id'          => $item['id'],
    'title'       => $item['title'],
    'status'      => $item['status'],
    'category'    => $item['category'],
    'condominium' => $condominium,
    // The unit is the operative datum: it appears when the work is already mine
    // (SPEC 98), and travels always for the resident, whose home it is.
    'unit'        => $show_unit ? $item['unit'] : NULL,
    'requester'   => $requester,
  ];
}
