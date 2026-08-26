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
