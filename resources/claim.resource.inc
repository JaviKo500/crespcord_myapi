<?php

/**
 * @file
 * Claim resource for the myapi module (SPEC 64).
 *
 * Exposes the claims ('reclamo' nodes) of the authenticated resident in two
 * read-only endpoints:
 *   - GET /api/v1/claims    — paginated, filterable list.
 *   - GET /api/v1/claims/%  — one claim by nid, transactions always expanded.
 * There is no write path here: creating, editing or closing a claim, and
 * adding a transaction to it, are back office only (SPEC 57) until a spec says
 * otherwise.
 *
 * A claim is visible to $uid when its condominium is one of
 * myapi_condominium_related_nids($uid) AND (field_visibility = 'public' OR
 * field_requester = $uid). That single condition is built once, in
 * myapi_claim_base_query(), and reused by the count, the list fetch and the
 * detail, so the three can never drift apart. node.uid — whoever typed the
 * form — deliberately plays no part in it: an administrator filing a private
 * claim on a resident's behalf must not then see it in their own app.
 *
 * Like payment.resource.inc, reservation.resource.inc and
 * bulletin.resource.inc, this file reads the field_data_* tables directly
 * instead of going through node_load_multiple(): loading the full node objects
 * would fire the entity load hooks once per claim and bring every field along
 * for a response that needs a dozen columns. The trade-off is the usual one,
 * documented in docs/claim.md: the queries assume the current field storage
 * and would break if someone rebuilt those fields from the UI.
 *
 * The queries deliberately do NOT carry ->addTag('node_access'). Access here
 * is decided by the visibility condition above — explicit, testable, and
 * written for a resident holding a token, not for an operator holding a role
 * (that is what includes/myapi.claim_query.inc does for the back office). The
 * consequence to keep in mind: if a future spec adds hook_node_access() rules
 * over 'reclamo', this endpoint will not inherit them, and the condition below
 * is where they would have to be mirrored.
 *
 * Image and attachment URLs are authenticated since SPEC 65: field_images and
 * field_attachment live in private://, so nothing serves them without a
 * decision, and the 'url' key of every file object now carries the absolute
 * URL of GET /api/v1/claims/%/files/% — myapi_claim_file_download() below —
 * instead of a sites/default/files one. The key and its type did not change;
 * what changed is that the app must send the Authorization header when loading
 * an image too. The back office reads the same files through
 * hook_file_download() (myapi_file_download() in myapi.module), and both
 * consumers share includes/myapi.claims_files.inc. See docs/claim.md.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
module_load_include('inc', 'myapi', 'includes/myapi.claims_common');

/**
 * Maximum length, in CHARACTERS, of the closing reason (SPEC 70).
 *
 * field_comment is a text_long column, so this is the spec's limit and not the
 * column's: a closing reason is a sentence, and the timeline and the email both
 * print it whole.
 */
define('MYAPI_CLAIM_CLOSE_REASON_MAX', 1000);

/* ---------------------------------------------------------------------------
 * Query-string parsing.
 *
 * Every helper in this block is pure — it reads $_GET and returns a value, and
 * touches neither the database nor the Field API — which is what makes them
 * testable from tests/unit/ClaimListFilterTest.php.
 *
 * The parsing is lax on purpose, the same idiom payments, reservations and
 * bulletins already follow: an invalid value falls back to the default in
 * silence and never raises a 422. A client with a stale bookmark
 * ('?status=duplicated', dropped from the catalogue by SPEC 62) gets the full
 * list rather than an error. The one deliberate exception is
 * myapi_claim_parse_condominium_id(), which is not in this block precisely
 * because it can cut the request short with a 403.
 *
 * There is no status or claim_type validator here either: those are
 * myapi_claims_valid_status_list() and myapi_claims_valid_claim_type() in
 * includes/myapi.claims_common.inc, built on the same two whitelists the back
 * office uses, so both consumers accept exactly the same values (Regla 3 de
 * CLAUDE.md). '?status' is multi-value in this endpoint
 * ('?status=received,in_progress'), which is the only thing the API reads
 * differently from the back-office listing — and even that goes through
 * myapi_claims_valid_status() item by item.
 * ------------------------------------------------------------------------ */

/**
 * Parses the optional date-range filter from the query string.
 *
 * Reads $_GET['date_from'] and $_GET['date_to'], each expected as an ISO
 * 'YYYY-MM-DD' string. A bound is kept only when it matches that format and is
 * a real calendar date (checkdate()); anything else is silently ignored, like
 * page/limit/sort. If both bounds are present but inverted (from > to), the
 * whole filter is dropped so the endpoint responds as if no range were given.
 * No 422 is ever raised here. Same shape as myapi_payment_parse_date_range().
 *
 * Both bounds are inclusive and compared by DAY, against
 * SUBSTR(field_reception_date_value, 1, 10) — see myapi_claim_base_query().
 * SPEC 63 gave field_reception_date a time part, so comparing the stored value
 * whole would drop everything received on the date_to day after midnight.
 *
 * @return array  ['from' => string|NULL, 'to' => string|NULL].
 */
function myapi_claim_parse_date_range() {
  $from = myapi_claim_valid_date(isset($_GET['date_from']) ? $_GET['date_from'] : NULL);
  $to = myapi_claim_valid_date(isset($_GET['date_to']) ? $_GET['date_to'] : NULL);

  if ($from !== NULL && $to !== NULL && $from > $to) {
    return ['from' => NULL, 'to' => NULL];
  }

  return ['from' => $from, 'to' => $to];
}

/**
 * Validates a single 'YYYY-MM-DD' date string.
 *
 * The format check alone is not enough: '2026-02-30' matches the pattern and
 * is not a date, so checkdate() has the last word.
 *
 * @param mixed $value  Raw query-string value, or NULL when absent.
 *
 * @return string|NULL  The value when it is a real ISO calendar date,
 *                      NULL otherwise.
 */
function myapi_claim_valid_date($value) {
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
    return NULL;
  }
  if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
    return NULL;
  }
  return $value;
}

/**
 * Whether the list must expand its claims' transactions into full objects.
 *
 * Only the exact value '?include=transactions' expands them; anything else —
 * absent, empty, a typo, an array, a future 'include=images' this endpoint
 * does not implement yet — leaves them collapsed to an array of ints, with no
 * 422. 'include' is the conventional name and leaves room for a comma-separated
 * list later without inventing a parameter per expandable thing; today the
 * single exact value is all that is supported, and the comparison is strict so
 * 'transactions,images' does NOT expand until a spec says what that means.
 *
 * The detail endpoint ignores this entirely: it always expands.
 *
 * @return bool
 */
function myapi_claim_include_transactions() {
  return isset($_GET['include']) && is_string($_GET['include']) && $_GET['include'] === 'transactions';
}

/**
 * Parses the optional 'condominium_id' filter, gating it against the reader's
 * own condominiums.
 *
 * The one query-string parameter that can cut the request short, which is why
 * it sits outside the pure block above and why it takes the allowed set as an
 * argument instead of resolving it itself:
 *   - absent, empty, or not a positive integer -> NULL, i.e. no filter, in
 *     silence. '?condominium_id=abc' is a client bug, not an access attempt:
 *     there is no condominium being denied to anyone, and turning a typo into a
 *     permission error would only confuse the diagnosis. This is a deliberate
 *     divergence from myapi_bulletin_parse_condominium_id(), which answers 422.
 *   - a positive integer NOT in $allowed -> 403 condominium_access_denied, and
 *     myapi_error() ends the request here.
 *   - a positive integer in $allowed -> that nid, to narrow the list.
 *
 * Asking for one specific condominium is a statement of intent, not a
 * convenience parameter: silently returning the claims of a DIFFERENT set of
 * condominiums would be a misleading answer, so this is the single place where
 * the lax parsing of this endpoint stops. Existence of the node is not checked
 * — a non-existent nid is foreign to the reader's set just like any other, and
 * answers the same 403, so the response never confirms whether it exists.
 *
 * @param array $allowed  Condominium nids the reader is related to, as
 *                        returned by myapi_condominium_related_nids().
 *
 * @return int|NULL  The condominium nid to filter by, or NULL for no filter.
 */
function myapi_claim_parse_condominium_id(array $allowed) {
  if (!isset($_GET['condominium_id'])) {
    return NULL;
  }
  $value = $_GET['condominium_id'];
  if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value <= 0) {
    return NULL;
  }

  $condominium_id = (int) $value;
  if (!in_array($condominium_id, array_map('intval', $allowed), TRUE)) {
    myapi_error('condominium_access_denied', 403);
  }

  return $condominium_id;
}

/* ---------------------------------------------------------------------------
 * Queries.
 *
 * Three per request, never one per row: the main query returns one row per
 * claim with every cardinality-1 field already joined, a second brings the
 * images of the whole page in one IN, and a third the transactions. See the
 * N+1 note in docs/claim.md.
 * ------------------------------------------------------------------------ */

/**
 * Builds the query every claim read starts from: the access rule plus the
 * optional filters, with no projection, ordering or pagination.
 *
 * This is the ONE place the visibility rule is written. The count, the list
 * fetch and the detail all call it, so the three can never disagree about what
 * the reader is allowed to see — which is the point, and Regla 3 de CLAUDE.md.
 * A claim is visible when:
 *
 *   field_condominium_target_id IN (:condominiums of the reader)
 *   AND (field_visibility_value = 'public' OR field_requester_target_id = :uid)
 *
 * Two consequences worth stating out loud, both deliberate:
 *   - The condominium filter comes FIRST and applies to everything, including
 *     the reader's own claims: a resident who no longer has a unit in a
 *     condominium stops seeing the private claims they filed there.
 *   - node.uid — whoever typed the form — is NOT part of the condition. An
 *     administrator filing a private claim on a resident's behalf is its
 *     author but not its requester, and must not see it in their own app.
 *
 * field_condominium and field_visibility are INNER joins because both are part
 * of the access decision: a claim with no visibility row (imported by hand, or
 * created before the field was required) disappears from the endpoint instead
 * of defaulting to public. Everything else is LEFT joined, so a claim missing
 * an optional-in-practice value still shows up with a null in that key rather
 * than vanishing — the same criterion as myapi_claims_list_rows().
 *
 * fs/fct/frd are joined unconditionally, even when no filter uses them: they
 * are the columns myapi_claim_fetch() projects anyway, and joining them here
 * keeps every alias of the field tables in one function. The count pays three
 * LEFT JOINs on entity_id-indexed tables for it, which is not a cost worth
 * splitting the aliases across two files to avoid.
 *
 * The query deliberately carries NO ->addTag('node_access'): see the @file
 * docblock.
 *
 * @param int   $uid      Authenticated reader uid.
 * @param array $condos   Condominium nids the reader is related to. MUST be
 *                        non-empty — the callers answer an empty list without
 *                        querying, since an "IN ()" is invalid SQL in D7.
 * @param array $filters  ['nid' => int|NULL, 'condominium_id' => int|NULL,
 *                        'status' => array|string|NULL (list of statuses; a
 *                        bare string is accepted and treated as a list of one),
 *                        'claim_type' => string|NULL,
 *                        'from' => string|NULL, 'to' => string|NULL]. Every key
 *                        optional; NULL means no filter on that column. 'nid'
 *                        is what turns this into the detail query: one claim,
 *                        under exactly the same access rule as the list.
 *
 * @return SelectQuery  The query, with these aliases already in place:
 *                      n (node), fc (condominium), fv (visibility),
 *                      fr (requester), fs (status), fct (claim type),
 *                      frd (reception date).
 */
function myapi_claim_base_query($uid, array $condos, array $filters = []) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'reclamo')
    ->condition('n.status', 1);

  // Access, part one: the claim belongs to a condominium of the reader.
  $query->innerJoin('field_data_field_condominium', 'fc', "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0");
  $query->condition('fc.field_condominium_target_id', $condos, 'IN');

  // Access, part two: public, or filed by the reader.
  $query->innerJoin('field_data_field_visibility', 'fv', "fv.entity_id = n.nid AND fv.entity_type = 'node' AND fv.deleted = 0");
  $query->leftJoin('field_data_field_requester', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->condition(db_or()
    ->condition('fv.field_visibility_value', 'public')
    ->condition('fr.field_requester_target_id', $uid)
  );

  $query->leftJoin('field_data_field_status', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->leftJoin('field_data_field_claim_type', 'fct', "fct.entity_id = n.nid AND fct.entity_type = 'node' AND fct.deleted = 0");
  $query->leftJoin('field_data_field_reception_date', 'frd', "frd.entity_id = n.nid AND frd.entity_type = 'node' AND frd.deleted = 0");

  // The detail's only extra condition. It narrows the same access rule instead
  // of replacing it, which is why a claim the reader may not see answers "no
  // rows" here rather than needing a separate permission check afterwards.
  if (!empty($filters['nid'])) {
    $query->condition('n.nid', $filters['nid']);
  }

  // Optional filters. '?condominium_id' narrows WITHIN the set above, it never
  // widens it: myapi_claim_parse_condominium_id() has already answered 403 for
  // anything outside, so this condition can only ever be a subset of the IN.
  if (!empty($filters['condominium_id'])) {
    $query->condition('fc.field_condominium_target_id', $filters['condominium_id']);
  }
  // 'status' arrives as a LIST since SPEC 69 ('?status=received,in_progress'),
  // so the condition is always an IN — with one element for the single-value
  // form, which MySQL resolves exactly like the '=' it replaced.
  if (!empty($filters['status'])) {
    $query->condition('fs.field_status_value', (array) $filters['status'], 'IN');
  }
  if (!empty($filters['claim_type'])) {
    $query->condition('fct.field_claim_type_value', $filters['claim_type']);
  }

  // Both bounds are inclusive and compared by DAY. The SUBSTR is what makes
  // date_to inclusive now that SPEC 63 gave field_reception_date a time part:
  // against the full stored value, a claim received on date_to at 14:30 would
  // fall outside the range. Same expression as the back-office listing.
  //
  // Applied on the LEFT-joined column on purpose, so a claim with no reception
  // date drops out as soon as either bound is active — the desired reading of
  // "show me what came in during this range".
  if (!empty($filters['from'])) {
    $query->where('SUBSTR(frd.field_reception_date_value, 1, 10) >= :date_from', [':date_from' => $filters['from']]);
  }
  if (!empty($filters['to'])) {
    $query->where('SUBSTR(frd.field_reception_date_value, 1, 10) <= :date_to', [':date_to' => $filters['to']]);
  }

  return $query;
}

/**
 * Counts the claims visible to the reader under the current filters,
 * unpaginated.
 *
 * Nothing but myapi_claim_base_query()->countQuery(): the total and the page
 * are, by construction, the same set. Every field table it joins is
 * cardinality 1, so there is one row per claim and the count needs no DISTINCT.
 *
 * @param int   $uid      Authenticated reader uid.
 * @param array $condos   Condominium nids the reader is related to, non-empty.
 * @param array $filters  Same shape as myapi_claim_base_query().
 *
 * @return int  Number of matching claims.
 */
function myapi_claim_count($uid, array $condos, array $filters = []) {
  return (int) myapi_claim_base_query($uid, $condos, $filters)
    ->countQuery()
    ->execute()
    ->fetchField();
}

/**
 * Fetches one page of claims, with every cardinality-1 column projected.
 *
 * Starts from the same myapi_claim_base_query() the count uses — so the page is
 * always a slice of the counted set — and adds what the response needs: the
 * remaining single-value fields, the attachment's file row, and the
 * condominium's own node title.
 *
 * Images and transactions are NOT joined here. Both are multi-valued and would
 * multiply the rows; they are loaded per page in one query each by
 * myapi_claim_load_images() and myapi_claim_load_transactions().
 *
 * Ordering is field_reception_date first and n.nid second, in the SAME
 * direction. The tie-break is what makes paging stable: reception dates repeat
 * (several claims filed the same minute, or none at all), and without a second
 * key MySQL is free to return tied rows in a different order per page, which
 * shows up as a claim appearing twice or never across two requests.
 *
 * @param int    $uid      Authenticated reader uid.
 * @param array  $condos   Condominium nids the reader is related to, non-empty.
 * @param array  $filters  Same shape as myapi_claim_base_query().
 * @param int    $page     1-based page number. Ignored when $limit is -1.
 * @param int    $limit    Rows per page, or -1 for "all matching rows,
 *                         unpaginated" (SPEC 15).
 * @param string $sort     'asc' or 'desc' on the reception date.
 *
 * @return array  Row objects, one per claim.
 */
function myapi_claim_fetch($uid, array $condos, array $filters, $page, $limit, $sort) {
  $query = myapi_claim_base_query($uid, $condos, $filters);

  $query->addField('n', 'nid', 'id');
  $query->addField('n', 'title', 'subject');
  $query->addField('n', 'created', 'created');

  $query->addField('fc', 'field_condominium_target_id', 'condominium_id');
  $query->leftJoin('node', 'nc', 'nc.nid = fc.field_condominium_target_id');
  $query->addField('nc', 'title', 'condominium_name');

  $query->addField('fv', 'field_visibility_value', 'visibility');
  $query->addField('fr', 'field_requester_target_id', 'requester_id');
  $query->addField('fs', 'field_status_value', 'status');
  $query->addField('fct', 'field_claim_type_value', 'claim_type');
  // The full stored value ('Y-m-d H:i:s'): the SUBSTR belongs to the filters,
  // not to the projection — the response carries the time (SPEC 63).
  $query->addField('frd', 'field_reception_date_value', 'reception_date');

  $query->leftJoin('field_data_field_description', 'fd', "fd.entity_id = n.nid AND fd.entity_type = 'node' AND fd.deleted = 0");
  $query->addField('fd', 'field_description_value', 'description');

  // The attachment travels joined rather than file_load()ed: cardinality 1, and
  // this way the whole page costs no extra query. The uri is NOT projected:
  // since SPEC 65 the URL is built from the claim nid and the fid, and the
  // file's own location never reaches the response.
  $query->leftJoin('field_data_field_attachment', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
  $query->leftJoin('file_managed', 'fm', 'fm.fid = fa.field_attachment_fid');
  $query->addField('fm', 'fid', 'attachment_fid');
  $query->addField('fm', 'filename', 'attachment_filename');

  $direction = ($sort === 'asc') ? 'ASC' : 'DESC';
  $query->orderBy('frd.field_reception_date_value', $direction);
  $query->orderBy('n.nid', $direction);

  // limit = -1 means "no pagination" (SPEC 15): no ->range() at all, and the
  // caller has already forced page = 1.
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }

  return $query->execute()->fetchAll();
}

/**
 * Loads the images of a whole page of nodes in a single query.
 *
 * This is the function that keeps the endpoint free of an N+1: 20 claims with
 * three images each cost ONE query here, not sixty. field_images is
 * multi-valued, so it cannot be joined into myapi_claim_fetch() without
 * multiplying its rows — it is loaded apart and stitched back in PHP.
 *
 * It takes nids and knows nothing about bundles, which is why the same function
 * serves the claims of a page and, when they are expanded, the transactions of
 * those claims (see myapi_claim_load_transactions()).
 *
 * The URLs it builds are AUTHENTICATED since SPEC 65: field_images lives in
 * private://, and every url points at GET /api/v1/claims/%/files/% instead of
 * at the file. That URL needs the nid of the CLAIM, which for a transaction's
 * image is not the nid of the row — hence $owner_map.
 *
 * @param array $nids       Node ids to load images for. An empty array
 *                          short-circuits with no query (an "IN ()" is invalid
 *                          SQL in D7).
 * @param array $owner_map  [entity nid => owning claim nid], for the callers
 *                          whose nids are not claims. Empty — the default —
 *                          means every nid is its own owner, which is exactly
 *                          the case when the nids ARE claims; only
 *                          myapi_claim_load_transactions() fills it in. A nid
 *                          missing from a non-empty map falls back to itself
 *                          rather than to nothing, so a url is never built
 *                          without a nid.
 *
 * @return array  [nid => [['id' => int, 'url' => string, 'filename' => string],
 *                ...]], in delta order. A nid with no images is simply absent
 *                from the map; callers default it to an empty array, so the
 *                'images' key is always an array in the response.
 */
function myapi_claim_load_images(array $nids, array $owner_map = []) {
  if (empty($nids)) {
    return [];
  }

  $query = db_select('field_data_field_images', 'fi');
  $query->condition('fi.entity_type', 'node');
  $query->condition('fi.deleted', 0);
  $query->condition('fi.entity_id', $nids, 'IN');
  $query->innerJoin('file_managed', 'fm', 'fm.fid = fi.field_images_fid');
  $query->addField('fi', 'entity_id', 'entity_id');
  $query->addField('fm', 'fid', 'fid');
  $query->addField('fm', 'filename', 'filename');
  // entity_id first so the rows of one node arrive together, delta second so
  // they keep the order the operator uploaded them in.
  $query->orderBy('fi.entity_id', 'ASC');
  $query->orderBy('fi.delta', 'ASC');

  // INNER and not LEFT on file_managed: a field row pointing at a file that no
  // longer exists has no URL to offer, and an entry with a null url would be
  // worse for the client than no entry at all.
  $images = [];
  foreach ($query->execute() as $row) {
    $entity_id = (int) $row->entity_id;
    $claim_nid = isset($owner_map[$entity_id]) ? (int) $owner_map[$entity_id] : $entity_id;

    $images[$entity_id][] = myapi_claim_build_file($row->fid, $row->filename, $claim_nid);
  }

  return $images;
}

/**
 * Loads the transactions of a whole page of claims in one query (two when
 * expanded, plus one for their images).
 *
 * The map is built from field_data_field_claim, the entityreference every
 * 'claim_transaction' carries back to its 'reclamo': fcl.entity_id is the
 * transaction, fcl.field_claim_target_id the claim it belongs to.
 *
 * Two shapes, one key name — 'transactions' either way, so the client never
 * has to look at which key arrived; the request it made decides the type:
 *   - $expand = FALSE : [claim_nid => [12, 15, 18]], plain ints. The default
 *                       for the list.
 *   - $expand = TRUE  : [claim_nid => [{id, status, status_date, comment,
 *                       created, images, attachment}, ...]]. What
 *                       '?include=transactions' asks for, and what the detail
 *                       endpoint always uses.
 *
 * The author of each transaction — the administrator who recorded the status
 * change — is deliberately NOT exposed: it is back-office information, and the
 * resident needs to know what happened and when, not who typed it. That is the
 * one column myapi_claim_transaction_timeline_rows() has and this does not.
 *
 * Order is ASCENDING by field_status_date, tie-broken by nid ascending: this
 * is a timeline, read oldest-first, which is the reverse of the back-office
 * table. A transaction with no status date sorts first (MySQL puts NULLs
 * first in ASC) rather than being dropped — same LEFT JOIN criterion as the
 * rest of the module.
 *
 * Only published transactions are listed (n.status = 1), exactly like the
 * back-office timeline.
 *
 * @param array $nids    Claim nids whose transactions to load. Empty
 *                       short-circuits with no query.
 * @param bool  $expand  FALSE for ids, TRUE for full objects.
 *
 * @return array  [claim_nid => array], as described above. A claim with no
 *                transactions is absent from the map; callers default it to an
 *                empty array.
 */
function myapi_claim_load_transactions(array $nids, $expand) {
  if (empty($nids)) {
    return [];
  }

  $query = db_select('node', 'n')
    ->condition('n.type', 'claim_transaction')
    ->condition('n.status', 1);
  $query->addField('n', 'nid', 'id');

  $query->innerJoin('field_data_field_claim', 'fcl', "fcl.entity_id = n.nid AND fcl.entity_type = 'node' AND fcl.deleted = 0");
  $query->condition('fcl.field_claim_target_id', $nids, 'IN');
  $query->addField('fcl', 'field_claim_target_id', 'claim_id');

  // Joined in both modes: collapsed or not, the ids come out in timeline order.
  $query->leftJoin('field_data_field_status_date', 'fsd', "fsd.entity_id = n.nid AND fsd.entity_type = 'node' AND fsd.deleted = 0");

  if ($expand) {
    $query->addField('n', 'created', 'created');
    $query->addField('fsd', 'field_status_date_value', 'status_date');

    $query->leftJoin('field_data_field_status', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
    $query->addField('fs', 'field_status_value', 'status');

    $query->leftJoin('field_data_field_comment', 'fcm', "fcm.entity_id = n.nid AND fcm.entity_type = 'node' AND fcm.deleted = 0");
    $query->addField('fcm', 'field_comment_value', 'comment');

    $query->leftJoin('field_data_field_attachment', 'fa', "fa.entity_id = n.nid AND fa.entity_type = 'node' AND fa.deleted = 0");
    $query->leftJoin('file_managed', 'fm', 'fm.fid = fa.field_attachment_fid');
    $query->addField('fm', 'fid', 'attachment_fid');
    $query->addField('fm', 'filename', 'attachment_filename');
  }

  $query->orderBy('fsd.field_status_date_value', 'ASC');
  $query->orderBy('n.nid', 'ASC');

  $rows = $query->execute()->fetchAll();

  if (!$expand) {
    $ids = [];
    foreach ($rows as $row) {
      $ids[(int) $row->claim_id][] = (int) $row->id;
    }
    return $ids;
  }

  // One more query for the images of every transaction on the page, the same
  // batch load the claims themselves use — never one per transaction.
  //
  // The owner map is what makes the file URLs carry the CLAIM's nid and not
  // the transaction's (SPEC 65): the endpoint that serves them is
  // /api/v1/claims/{claim}/files/{fid}, because the claim is the unit of
  // access and the app never has to know which transaction a picture hangs
  // from.
  $transaction_nids = [];
  $owner_map = [];
  foreach ($rows as $row) {
    $transaction_nids[] = (int) $row->id;
    $owner_map[(int) $row->id] = (int) $row->claim_id;
  }
  $images = myapi_claim_load_images($transaction_nids, $owner_map);

  $transactions = [];
  foreach ($rows as $row) {
    $id = (int) $row->id;
    $transactions[(int) $row->claim_id][] = [
      'id'          => $id,
      'status'      => $row->status,
      // Same rule as the claim's reception_date: the stored naive local time
      // with a 'T' in it, never passed through strtotime() — see
      // myapi_claim_build_item().
      'status_date' => $row->status_date === NULL ? NULL : str_replace(' ', 'T', $row->status_date),
      'comment'     => $row->comment,
      'created'     => format_date((int) $row->created, 'custom', 'Y-m-d\TH:i:s'),
      'images'      => isset($images[$id]) ? $images[$id] : [],
      // The claim's nid, not $id: same reason as the owner map above.
      'attachment'  => myapi_claim_build_file($row->attachment_fid, $row->attachment_filename, (int) $row->claim_id),
    ];
  }

  return $transactions;
}

/**
 * Shapes one file — an image, the claim's attachment or a transaction's — into
 * the response, or NULL when there is none.
 *
 * The single place a file object is built, so the three keys and their types
 * are the same wherever a file appears. The columns arrive already joined from
 * file_managed; nothing here loads anything.
 *
 * Since SPEC 65 the url is NOT the file's own location: field_images and
 * field_attachment live in private://, so what travels is the absolute URL of
 * GET /api/v1/claims/%/files/% — myapi_claim_file_download() — which serves the
 * bytes to a caller holding a Bearer token. The file's uri is therefore not
 * needed here at all, and is no longer projected by the queries either.
 *
 * $claim_nid is the nid of the CLAIM, which is not always the nid of the row
 * the file hangs from: for a transaction's file it is the claim that
 * transaction belongs to. See myapi_claim_load_transactions().
 *
 * @param mixed $fid        file_managed.fid, or NULL when the LEFT JOIN found
 *                          no row.
 * @param mixed $filename   file_managed.filename.
 * @param int   $claim_nid  The nid of the claim the file belongs to.
 *
 * @return array|NULL  ['id' => int, 'url' => string, 'filename' => string].
 */
function myapi_claim_build_file($fid, $filename, $claim_nid) {
  if ($fid === NULL) {
    return NULL;
  }

  return [
    'id'       => (int) $fid,
    'url'      => url('api/v1/claims/' . (int) $claim_nid . '/files/' . (int) $fid, ['absolute' => TRUE]),
    'filename' => $filename,
  ];
}

/**
 * Shapes one claim row into the response item.
 *
 * The single serialiser: the list and the detail both go through it, so the two
 * responses cannot drift into having different keys or different types for the
 * same claim. The only difference between them is what the caller puts in
 * $transactions — ids in the list, objects in the detail.
 *
 * Three formatting rules it is responsible for:
 *
 *   - Ids are JSON integers, never strings: the database hands every column
 *     back as a string, and a Flutter client comparing 140 to "140" fails
 *     silently. NULL survives as NULL, so a claim with no requester answers
 *     null rather than 0.
 *
 *   - reception_date is the STORED value with a 'T' in it and nothing else.
 *     field_reception_date was created with tz_handling = 'none' (SPEC 55/63):
 *     what is stored is a naive local time, not a UTC instant, so running it
 *     through strtotime() + format_date() would shift it by the server's
 *     timezone and answer an hour nobody ever typed. A claim created before
 *     SPEC 63 stores 'Y-m-d 00:00:00' and comes out as T00:00:00, which is
 *     correct and not an error.
 *
 *   - created, in contrast, IS a real timestamp, so it gets format_date() in
 *     the site's timezone — identical to SPEC 34.
 *
 * @param object $row           One row from myapi_claim_fetch().
 * @param array  $images        [nid => images] from myapi_claim_load_images().
 * @param array  $transactions  [nid => ids|objects] from
 *                              myapi_claim_load_transactions().
 *
 * @return array  The claim as it travels in data.claims[] and data.claim.
 */
function myapi_claim_build_item($row, array $images, array $transactions) {
  $id = (int) $row->id;

  return [
    'id'               => $id,
    'subject'          => $row->subject,
    'description'      => $row->description,
    'status'           => $row->status,
    'claim_type'       => $row->claim_type,
    'visibility'       => $row->visibility,
    'reception_date'   => $row->reception_date === NULL ? NULL : str_replace(' ', 'T', $row->reception_date),
    'created'          => format_date((int) $row->created, 'custom', 'Y-m-d\TH:i:s'),
    'condominium_id'   => $row->condominium_id === NULL ? NULL : (int) $row->condominium_id,
    'condominium_name' => $row->condominium_name,
    'requester_id'     => $row->requester_id === NULL ? NULL : (int) $row->requester_id,
    // Always an array, never null: an empty list is the honest answer to "no
    // images", and it saves every client a null check before iterating.
    'images'           => isset($images[$id]) ? $images[$id] : [],
    'attachment'       => myapi_claim_build_file($row->attachment_fid, $row->attachment_filename, $id),
    'transactions'     => isset($transactions[$id]) ? $transactions[$id] : [],
  ];
}

/* ---------------------------------------------------------------------------
 * Write-path helpers (SPEC 66).
 * ------------------------------------------------------------------------ */

/**
 * Whether $value is a valid key of $field_name's allowed_values.
 *
 * One helper for both 'field_claim_type' and 'field_visibility' instead of two
 * copies of the same lookup: neither field has a whitelist of its own in this
 * file, they read whatever the Field API instance currently allows — same
 * pattern as MYAPI_PAYMENT_METHOD_FIELD in payment.resource.inc. This is what
 * lets the two field's option lists change from the Drupal UI without a code
 * change here.
 *
 * @param string $field_name  Machine name of the field, e.g. 'field_claim_type'.
 * @param mixed  $value       The raw value to check.
 *
 * @return bool  TRUE when $value is a key of the field's allowed_values.
 */
function myapi_claim_valid_catalogue_value($field_name, $value) {
  $field_info = field_info_field($field_name);
  $allowed = ($field_info && isset($field_info['settings']['allowed_values']))
    ? $field_info['settings']['allowed_values']
    : [];

  return is_scalar($value) && array_key_exists($value, $allowed);
}

/**
 * Deletes a batch of just-saved managed files.
 *
 * The all-or-nothing cleanup primitive: used both inside
 * myapi_claim_create_save_files() (when a later file of the SAME field fails)
 * and by myapi_claim_create() (when the attachment fails after the images of
 * the same request already saved). One place for the loop instead of two.
 *
 * @param array $files  file_managed objects, as returned by file_save().
 */
function myapi_claim_create_delete_files(array $files) {
  foreach ($files as $file) {
    file_delete($file);
  }
}

/**
 * Validates and saves the files of one upload field, all-or-nothing.
 *
 * Serves both 'field_images' ($max_count = 5, multi-file input) and
 * 'field_attachment' ($max_count = 1, single-file input) — one function for
 * the two fields, since the only difference between them is which Field API
 * instance they read. $files is the raw $_FILES entry for the input
 * ($_FILES['images'] or $_FILES['attachment']): PHP gives a multi-file input
 * ('images[]') as parallel arrays keyed by index, and a single-file input
 * ('attachment') as one flat entry; both shapes are normalized here into a
 * list of individual files before anything is validated or saved.
 *
 * Nothing about extensions or sizes is hardcoded: $field_name's Field API
 * field and instance (field_info_field() / field_info_instance()) are read to
 * build the same #upload_validators and #upload_location the native widget
 * would use (file_field_widget_upload_validators() / file_field_widget_uri()),
 * exactly like myapi_claim_transaction_create_form() (SPEC 57) does for the
 * back office — so this endpoint inherits SPEC 55's limits instead of
 * duplicating them. The real-MIME whitelist is not hardcoded either: it is
 * derived from $validators['file_validate_extensions'][0] — the very string
 * the extension check above it already enforces, not a second read of
 * $instance['settings']['file_extensions'] under a different name — via
 * file_get_mimetype('dummy.<ext>'), Drupal core's own extension-to-mimetype
 * map. Same double-check myapi_payment_save_file() does with a hardcoded
 * list; here the list follows the field instead.
 *
 * On the first failure (too many files, a rejected extension/size, or a MIME
 * mismatch) every file already saved BY THIS CALL is deleted with
 * myapi_claim_create_delete_files() before myapi_error() ends the request:
 * no orphaned file_managed row survives a failed images[] or attachment
 * upload on its own. $also_delete extends that same cleanup to files saved by
 * an EARLIER call within the same request — myapi_claim_create() passes its
 * already-saved $image_files here when it calls this function for the
 * attachment, so a rejected attachment does not leave the request's valid
 * images orphaned. myapi_error() never returns to its caller (it ends the
 * request with drupal_exit()), which is exactly why this cleanup has to
 * happen HERE, before it is called, rather than by the orchestrator
 * afterwards.
 *
 * @param string $field_name   Machine name of the field ('field_images' or
 *                              'field_attachment').
 * @param string $bundle       Node bundle the field is attached to
 *                              ('reclamo').
 * @param array  $files        The raw $_FILES[...] entry for this input, or
 *                              an empty array when nothing was sent.
 * @param int    $max_count    Maximum number of files allowed.
 * @param string $error_key    i18n key to answer when a file is rejected for
 *                              its extension or size ('claim_invalid_image'
 *                              or 'claim_invalid_attachment'). A MIME
 *                              mismatch always answers 'invalid_file_type'
 *                              instead, regardless of this parameter.
 * @param array  $also_delete  Managed file objects saved by an earlier field
 *                              of the SAME request, to delete together with
 *                              this call's own batch on any failure. Empty by
 *                              default — the images call passes none, the
 *                              attachment call passes the images.
 *
 * @return array  The saved, permanent file objects, in the order they were
 *                sent. Empty when $files carried nothing.
 */
function myapi_claim_create_save_files($field_name, $bundle, array $files, $max_count, $error_key, array $also_delete = []) {
  // Normalize into a flat list of individual file specs, whichever shape
  // $files arrived in.
  $items = [];
  if (isset($files['name']) && is_array($files['name'])) {
    foreach ($files['name'] as $delta => $name) {
      if ($name === '' || (int) $files['error'][$delta] === UPLOAD_ERR_NO_FILE) {
        continue;
      }
      $items[] = [
        'name'     => $files['name'][$delta],
        'type'     => $files['type'][$delta],
        'tmp_name' => $files['tmp_name'][$delta],
        'error'    => $files['error'][$delta],
        'size'     => $files['size'][$delta],
      ];
    }
  }
  elseif (isset($files['name']) && $files['name'] !== '' && (int) $files['error'] !== UPLOAD_ERR_NO_FILE) {
    $items[] = $files;
  }

  if (empty($items)) {
    return [];
  }

  // The only count this module currently enforces past a field's own
  // cardinality: 'images[]' is unlimited at the field level (SPEC 55), so the
  // "max 5" from this spec is applied here. 'field_attachment' is a
  // non-array PHP input, so $_FILES can never hand this function more than
  // one item for it — this branch exists for 'field_images' in practice.
  if (count($items) > $max_count) {
    myapi_claim_create_delete_files($also_delete);
    myapi_error('claim_too_many_images', 422);
  }

  $field_info = field_info_field($field_name);
  $instance = field_info_instance('node', $field_name, $bundle);
  $validators = file_field_widget_upload_validators($field_info, $instance);
  $directory = file_field_widget_uri($field_info, $instance);

  if (!file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
    myapi_claim_create_delete_files($also_delete);
    myapi_error($error_key, 422);
  }

  // The extensions actually enforced by $validators itself
  // ('file_validate_extensions', the same key file_save_upload() reads),
  // rather than re-reading $instance['settings']['file_extensions'] under a
  // second name: this way the MIME whitelist can never drift from what the
  // extension check above it already validated, whichever key Drupal stores
  // it under.
  $extensions = isset($validators['file_validate_extensions'][0])
    ? preg_split('/\s+/', trim($validators['file_validate_extensions'][0]))
    : [];
  $allowed_mimes = [];
  foreach ($extensions as $extension) {
    $allowed_mimes[] = file_get_mimetype('dummy.' . $extension);
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $saved = [];

  foreach ($items as $delta => $item) {
    // Remap into the nested $_FILES['files'][...][<key>] shape
    // file_save_upload() expects — the same remap myapi_payment_save_file()
    // does for its single field, generalized to one key per item so N files
    // of the same input can each be uploaded on their own call.
    $form_key = $field_name . '_' . $delta;
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $part) {
      $_FILES['files'][$part][$form_key] = $item[$part];
    }

    $file = file_save_upload($form_key, $validators, $directory . '/');
    if (!is_object($file)) {
      myapi_claim_create_delete_files($saved);
      myapi_claim_create_delete_files($also_delete);
      if ($finfo) {
        finfo_close($finfo);
      }
      myapi_error($error_key, 422);
    }

    $real_path = drupal_realpath($file->uri);
    $mime = $finfo ? finfo_file($finfo, $real_path) : FALSE;
    if (!in_array($mime, $allowed_mimes, TRUE)) {
      file_delete($file);
      myapi_claim_create_delete_files($saved);
      myapi_claim_create_delete_files($also_delete);
      if ($finfo) {
        finfo_close($finfo);
      }
      myapi_error('invalid_file_type', 422);
    }

    $file->status = FILE_STATUS_PERMANENT;
    $saved[] = file_save($file);
  }

  if ($finfo) {
    finfo_close($finfo);
  }

  return $saved;
}

/**
 * Builds an unsaved 'reclamo' node from the validated claim input.
 *
 * Same pattern as myapi_payment_build_node(): every value is already
 * validated by the caller, and the node is returned UNSAVED so the
 * orchestrator controls the order against file_usage_add() — node_save()
 * first, then usage, exactly as SPEC 65 assumes for every file already
 * attached to a claim.
 *
 * Three fields are fixed here regardless of what travels in the request
 * (see the Modelo de datos of SPEC 66):
 *   - field_requester is always $uid, the token's own — never read from the
 *     request.
 *   - field_status is written explicitly as 'received': a programmatic
 *     node_save() never goes through field_default_form(), so the field's
 *     default_value would otherwise leave it empty.
 *   - field_reception_date is the server's own instant, 'Y-m-d H:i:00' —
 *     same format, seconds pinned to ':00', as
 *     myapi_claim_transaction_create_initial() uses for the transaction's own
 *     field_status_date.
 *
 * hook_node_insert()'s 'reclamo' branch (myapi.module) fires on the
 * caller's node_save() and creates the initial "received" transaction on its
 * own — nothing here calls myapi_claim_transaction_create_initial() directly.
 *
 * @param int         $uid             Authenticated resident (token uid):
 *                                      node.uid and field_requester.
 * @param string      $subject         Validated title, <= 255 chars.
 * @param string      $claim_type      Validated field_claim_type key.
 * @param int         $condominium_id  Validated field_condominium target nid.
 * @param string      $description     Validated, non-empty field_description.
 * @param string      $visibility      Validated field_visibility key.
 * @param array       $image_files     Saved permanent file objects for
 *                                      field_images, in upload order. May be
 *                                      empty.
 * @param object|null $attachment_file Saved permanent file object for
 *                                      field_attachment, or NULL when none.
 *
 * @return object  The prepared, unsaved node object.
 */
function myapi_claim_build_node($uid, $subject, $claim_type, $condominium_id, $description, $visibility, array $image_files, $attachment_file) {
  $node = new stdClass();
  $node->type = 'reclamo';
  node_object_prepare($node);
  $node->uid = $uid;
  $node->status = 1;
  $node->language = LANGUAGE_NONE;
  $node->title = $subject;

  $node->field_claim_type[LANGUAGE_NONE][0]['value'] = $claim_type;
  $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = $condominium_id;
  $node->field_requester[LANGUAGE_NONE][0]['target_id'] = $uid;
  $node->field_description[LANGUAGE_NONE][0]['value'] = $description;
  $node->field_visibility[LANGUAGE_NONE][0]['value'] = $visibility;
  $node->field_status[LANGUAGE_NONE][0]['value'] = 'received';
  $node->field_reception_date[LANGUAGE_NONE][0]['value'] = date('Y-m-d H:i:00');

  foreach ($image_files as $delta => $file) {
    $node->field_images[LANGUAGE_NONE][$delta] = ['fid' => $file->fid, 'display' => 1];
  }

  if ($attachment_file) {
    $node->field_attachment[LANGUAGE_NONE][0] = ['fid' => $attachment_file->fid, 'display' => 1];
  }

  return $node;
}

/**
 * Reads the fids currently stored in a file field of a loaded node.
 *
 * One function for both file fields of the bundle: 'field_images' (N items)
 * and 'field_attachment' (0 or 1), since the only difference between them is
 * how many deltas the same structure carries — not two copies of the same
 * nested isset() chain. Used by the update endpoint (SPEC 67) to know what the
 * claim references BEFORE the request changes it: what remove_image_ids[] is
 * allowed to name, and what has to be deleted from disk afterwards.
 *
 * @param object $node        A loaded node object.
 * @param string $field_name  Machine name of the file field.
 *
 * @return array  The fids as integers, in delta order. Empty when the field is
 *                absent or carries no file.
 */
function myapi_claim_node_file_fids($node, $field_name) {
  if (!isset($node->{$field_name}[LANGUAGE_NONE]) || !is_array($node->{$field_name}[LANGUAGE_NONE])) {
    return [];
  }

  $fids = [];
  foreach ($node->{$field_name}[LANGUAGE_NONE] as $item) {
    if (isset($item['fid']) && (int) $item['fid'] > 0) {
      $fids[] = (int) $item['fid'];
    }
  }

  return $fids;
}

/**
 * Validates the remove_image_ids[] field of an update request (SPEC 67).
 *
 * Reads the repeated multipart field through myapi_request_post_field_array()
 * — never $_POST directly — and checks every value twice: it must be a
 * positive integer, and it must be a fid the claim references RIGHT NOW. A fid
 * belonging to another claim, to a payment, or to nothing at all fails exactly
 * the same way: 422 invalid_field with @field remove_image_ids, before a
 * single file is touched. Silence plus a partial delete would leave the client
 * believing it removed something that is still there, and would turn a stray
 * fid into a way to probe other people's files.
 *
 * Validation only: nothing here queries the database, saves, or deletes. The
 * caller decides what to do with the list, and the actual deletion happens in
 * myapi_claim_update_delete_files() AFTER node_save().
 *
 * @param array $current_fids  The fids currently in the claim's field_images,
 *                              from myapi_claim_node_file_fids().
 *
 * @return array  The fids to remove, as integers, without duplicates. Empty
 *                when the field was not sent.
 */
function myapi_claim_update_parse_removals(array $current_fids) {
  $raw = myapi_request_post_field_array('remove_image_ids');
  if (empty($raw)) {
    return [];
  }

  $removals = [];
  foreach ($raw as $value) {
    if (!ctype_digit((string) $value) || (int) $value <= 0) {
      myapi_error('invalid_field', 422, ['@field' => 'remove_image_ids']);
    }
    $fid = (int) $value;
    if (!in_array($fid, $current_fids, TRUE)) {
      myapi_error('invalid_field', 422, ['@field' => 'remove_image_ids']);
    }
    if (!in_array($fid, $removals, TRUE)) {
      $removals[] = $fid;
    }
  }

  return $removals;
}

/**
 * Deletes files that an update took out of a claim (SPEC 67).
 *
 * The counterpart of myapi_claim_create_delete_files(): that one cleans up
 * files this request had just saved and the node never referenced, this one
 * removes files the node HAD referenced and no longer does — the images named
 * in remove_image_ids[] and the attachment that was replaced or dropped. They
 * live in private:// and nothing else points at them, so they are deleted for
 * real (file_usage_delete() + file_delete()) instead of being left for cron:
 * every correction a resident makes would otherwise add dead weight to disk.
 *
 * Passing 0 as the count to file_usage_delete() drops EVERY usage row this
 * module holds on that fid for the node, which is what lets file_delete()
 * actually remove it. That is safe only while a fid belongs to exactly one
 * claim — today it does, since each upload creates its own file_managed row
 * and myapi_claims_file_claim_nid() (SPEC 65) already assumes that 1:1
 * relation. A future spec introducing files shared between nodes has to revisit
 * this line first.
 *
 * MUST be called AFTER node_save(), never before: deleting first would open a
 * window where the node still references a file that no longer exists, and a
 * node_save() that fails would leave the claim pointing at nothing. A fid that
 * no longer loads is skipped in silence — the goal state (the file is gone) is
 * already true.
 *
 * @param array $fids  Fids to delete. May be empty.
 * @param int   $nid   The claim node the usage records belong to.
 */
function myapi_claim_update_delete_files(array $fids, $nid) {
  foreach ($fids as $fid) {
    $file = file_load($fid);
    if (!$file) {
      continue;
    }
    file_usage_delete($file, 'myapi', 'node', $nid, 0);
    file_delete($file);
  }
}

/* ---------------------------------------------------------------------------
 * Endpoints.
 * ------------------------------------------------------------------------ */

/**
 * Dispatcher for /api/v1/claims and /api/v1/claims/%.
 *
 * Routes by HTTP method, the same shape every resource in this module uses.
 * One callback serves both routes: hook_menu() passes the nid for the item path
 * and nothing for the collection, so $id is what tells list from detail.
 *
 * GET is read-only (SPEC 64: list and detail). Since SPEC 66, POST with no id
 * creates a claim (myapi_claim_create()) — creation is always on the
 * collection, never on an item. Since SPEC 67, POST WITH an id updates that
 * claim (myapi_claim_update()): POST and not PUT because PHP fills neither
 * $_POST nor $_FILES on a PUT, so the multipart body of an edit with files
 * would need a hand-written MIME parser. It is the only POST-on-an-item route
 * of the module, and it is documented as a decision, not an oversight. PUT and
 * DELETE still answer 405. Authentication is deliberately NOT resolved here but
 * inside each handler, so an unsupported method answers 405 without a token
 * rather than 401: the method is wrong whoever is asking.
 *
 * @param mixed $id  The nid from the item route, or NULL on the collection.
 */
function myapi_claim_dispatch($id = NULL) {
  $method = myapi_request_method();

  if ($method === 'GET' && $id === NULL) {
    myapi_claim_list();
  }
  elseif ($method === 'GET') {
    myapi_claim_detail($id);
  }
  elseif ($method === 'POST' && $id === NULL) {
    myapi_claim_create();
  }
  elseif ($method === 'POST') {
    myapi_claim_update($id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/claims/%/close (SPEC 70).
 *
 * Its own route and its own dispatcher, not a fifth branch of
 * myapi_claim_dispatch(): closing is a state transition, not an edition of the
 * resource, and the module already spells that shape twice —
 * PUT /api/v1/reservations/%/cancel and PUT /api/v1/payments/%/cancel.
 *
 * PUT and not POST: the body is JSON, so the PHP limitation that forced SPEC 67
 * to use POST on the item (neither $_POST nor $_FILES are filled on a PUT) does
 * not apply — myapi_request_body() reads php://input with no help from PHP.
 *
 * Authentication lives inside myapi_claim_close(), not here, so an unsupported
 * method answers 405 without a token: the method is wrong whoever is asking.
 *
 * @param mixed $id  The claim's nid from the route.
 */
function myapi_claim_close_dispatch($id) {
  $method = myapi_request_method();

  if ($method === 'PUT') {
    myapi_claim_close($id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Validates the mandatory 'close_reason' of a close request (SPEC 70).
 *
 * Pure: takes the already-decoded body and returns a verdict, never touching
 * HTTP output nor a node, so the caller decides when to abort — and so the rule
 * is testable from tests/unit without a site. Same shape as
 * myapi_reservation_validate_cancel_reason() (SPEC 50), with the one difference
 * that matters: there the reason is optional, here it is required. An absent
 * key, a body that is not an array and a whitespace-only string are therefore
 * all 'missing_field', not "no reason".
 *
 * Length is measured with drupal_strlen() (characters) and not strlen()
 * (bytes): field_comment is a text_long column, so the 1000 is this spec's
 * limit and not the column's, and counting bytes would reject a perfectly
 * storable reason written with accents — the language the residents of this
 * product write in.
 *
 * @param mixed $body  Decoded request body, or NULL when there was none.
 *
 * @return array  ['ok' => TRUE, 'value' => string] when accepted, or
 *                ['ok' => FALSE, 'error_code' => '...', 'replacements' => [...]]
 *                when it must answer 422.
 */
function myapi_claim_validate_close_reason($body) {
  $replacements = ['@field' => 'close_reason'];

  if (!is_array($body) || !array_key_exists('close_reason', $body)) {
    return ['ok' => FALSE, 'error_code' => 'missing_field', 'replacements' => $replacements];
  }

  // Present but not a string (array, number, boolean, NULL): the client sent
  // the key on purpose with the wrong type, which is a format error and not a
  // missing one.
  if (!is_string($body['close_reason'])) {
    return ['ok' => FALSE, 'error_code' => 'invalid_field', 'replacements' => $replacements];
  }

  $value = trim($body['close_reason']);
  if ($value === '') {
    return ['ok' => FALSE, 'error_code' => 'missing_field', 'replacements' => $replacements];
  }
  if (drupal_strlen($value) > MYAPI_CLAIM_CLOSE_REASON_MAX) {
    return ['ok' => FALSE, 'error_code' => 'field_too_long', 'replacements' => $replacements];
  }

  return ['ok' => TRUE, 'value' => $value];
}

/**
 * PUT /api/v1/claims/%/close — the requester closes their own claim (SPEC 70).
 *
 * The claim's field_status is NEVER written here. What this function does is
 * create a 'claim_transaction' with field_status = 'closed', and
 * hook_node_insert() -> myapi_claim_transaction_sync_claim_status() (SPEC 57)
 * is what syncs the claim. That is the only path that keeps the invariant "a
 * claim's status is its last transaction's status" and the only one that leaves
 * an entry in the timeline; writing field_status directly would leave the
 * timeline ending in 'received' while the claim reads 'closed'.
 *
 * Every step aborts with its own error before anything is written, in this
 * order:
 *
 *   1. Authentication.
 *   2. {id} is a positive integer, or 404 claim_not_found.
 *   3. Visibility: myapi_claim_fetch() with no row, 404 claim_not_found — the
 *      same rule, the same query and the same uniform 404 as
 *      GET /api/v1/claims/%.
 *   4. Requester: row.requester_id !== uid, 403 claim_close_denied. NOT a 404,
 *      for the same reason as myapi_claim_update(): a public claim of a
 *      neighbour is already visible, so there is nothing left to hide.
 *   5. Status: row.status !== 'received', 409 claim_not_closable. Exactly
 *      'received' and not "anything but closed": once the administration took
 *      the claim, closing it is their call. An already-closed claim lands here
 *      too, which is what makes a double click harmless.
 *   6. close_reason, mandatory — myapi_claim_validate_close_reason().
 *
 * The reason is validated LAST, after the two business checks, so a claim the
 * caller may not close keeps answering 403/409 even when the body is empty or
 * carries garbage. Same criterion as step 6 of myapi_reservation_cancel().
 *
 * The requester is notified of nothing: they just pressed the button, and the
 * 200 is the acknowledgement. That is what myapi_skip_claim_notification is
 * for — without it myapi_claim_notify_transaction() (SPEC 68) would push and
 * email them "your claim has news" about news they wrote themselves. The back
 * office does get an email, best-effort and after the save.
 *
 * @param mixed $id  The nid from the route. Anything that is not a positive
 *                   integer is a 404, never a PHP error.
 */
function myapi_claim_close($id) {
  // 1. Authentication.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 2. '/api/v1/claims/abc/close' answers the same 404 as a missing claim.
  if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
    myapi_error('claim_not_found', 404);
  }
  $nid = (int) $id;

  // 3. Visibility: the very same fetch GET /api/v1/claims/% runs. A claim of
  // another condominium, a private one of another resident and a non-existent
  // one are indistinguishable here, exactly as in the detail.
  $condos = myapi_condominium_related_nids($uid);
  if (empty($condos)) {
    myapi_error('claim_not_found', 404);
  }
  $rows = myapi_claim_fetch($uid, $condos, ['nid' => $nid], 1, -1, 'desc');
  if (empty($rows)) {
    myapi_error('claim_not_found', 404);
  }
  $row = reset($rows);

  // 4. Only the requester closes. Visible but not yours: 403, never 404.
  if ((int) $row->requester_id !== $uid) {
    myapi_error('claim_close_denied', 403);
  }

  // 5. Only while the administration has not taken it yet.
  if ($row->status !== 'received') {
    myapi_error('claim_not_closable', 409);
  }

  // 6. The reason, mandatory. Nothing has been written yet at this point.
  $reason = myapi_claim_validate_close_reason(myapi_request_body());
  if (!$reason['ok']) {
    myapi_error($reason['error_code'], 422, $reason['replacements']);
  }

  // 7. The closing transaction. uid is the requester's, which is what makes
  // the back-office timeline print their name as the author
  // (myapi_claim_transaction_author_label(), SPEC 57). The title is not
  // written: hook_node_presave() composes it (SPEC 60). The seconds of
  // field_status_date are pinned to ':00' like every other path that creates a
  // transaction (SPEC 58).
  $status_date = date('Y-m-d H:i:00');

  $transaction = (object) [
    'type'   => 'claim_transaction',
    'uid'    => $uid,
    'status' => 1,
  ];
  node_object_prepare($transaction);

  $transaction->field_claim[LANGUAGE_NONE][0]['target_id'] = $nid;
  $transaction->field_status[LANGUAGE_NONE][0]['value'] = 'closed';
  $transaction->field_status_date[LANGUAGE_NONE][0]['value'] = $status_date;
  // Only 'value' is written, no 'format', exactly like the other two paths
  // that create a transaction: the timeline reads the raw column and
  // check_plain()s it itself.
  $transaction->field_comment[LANGUAGE_NONE][0]['value'] = $reason['value'];
  // Transient flag (SPEC 68), not a field: it lives only for this save and is
  // what keeps the requester from being notified about their own click. The
  // claim's own node_save(), fired by the status sync, reaches
  // hook_node_update()'s 'reclamo' branch, which only notifies on the
  // private -> public transition — and visibility does not change here.
  $transaction->myapi_skip_claim_notification = TRUE;

  node_save($transaction);

  // 8. Email the detail to the back office (SPEC 70). After the save and
  // best-effort by contract: the closure is already stored, so nothing here
  // may turn the 200 into a 500. The claim is reloaded from the database
  // ($reset = TRUE) so the email prints the status the sync just wrote.
  $claim = node_load($nid, NULL, TRUE);
  if ($claim) {
    module_load_include('inc', 'myapi', 'includes/myapi.claim_notification');
    myapi_claim_notify_closed_by_requester($claim, $reason['value'], strtotime($status_date));
  }

  // 9. Respond 200 with the same object as GET /api/v1/claims/%, transactions
  // always expanded — the same helpers, so the two responses cannot drift. The
  // row is re-fetched, so 'status' is the 'closed' the sync wrote and the new
  // transaction is already in the timeline.
  $rows = myapi_claim_fetch($uid, $condos, ['nid' => $nid], 1, -1, 'desc');
  if (empty($rows)) {
    myapi_error('claim_not_found', 404);
  }
  $row = reset($rows);
  $nids = [(int) $row->id];
  $images = myapi_claim_load_images($nids);
  $transactions = myapi_claim_load_transactions($nids, TRUE);

  myapi_respond(
    ['claim' => myapi_claim_build_item($row, $images, $transactions)],
    200,
    'claim_closed'
  );
}

/**
 * POST /api/v1/claims — creates a claim for the authenticated resident
 * (SPEC 66).
 *
 * multipart/form-data, not JSON: text fields through myapi_request_post_field()
 * ($_POST), files through $_FILES — same contract as POST /api/v1/payments.
 * Every step below aborts with its own error BEFORE anything touches the
 * database, in the exact order of the Modelo de datos section of SPEC 66:
 *
 *   1. Authentication.
 *   2. Required fields present (subject, claim_type, condominium_id,
 *      description, visibility).
 *   3. subject <= 255 chars.
 *   4. claim_type is a key of field_claim_type's allowed_values.
 *   5. condominium_id is a positive integer AND one of the reader's own
 *      condominiums (myapi_condominium_related_nids($uid), the same set
 *      GET /api/v1/claims uses) — a user with no condominium at all simply
 *      has an empty set, so every condominium_id falls outside it and
 *      answers the same 403.
 *   6. description non-empty after trim() (myapi_request_post_field()
 *      already trims).
 *   7. visibility is a key of field_visibility's allowed_values.
 *   8. images[] (if any) — myapi_claim_create_save_files(), all-or-nothing.
 *   9. attachment (if any) — same, and on failure also deletes the images
 *      already saved by step 8 (the $also_delete parameter — see that
 *      function's docblock for why the cleanup has to live there and not
 *      here).
 *
 * field_requester is NEVER read from the request: it is always $uid, the
 * token's own (see myapi_claim_build_node()). field_status and
 * field_reception_date are likewise fixed by the server, not the client.
 *
 * hook_node_insert()'s 'reclamo' branch (myapi.module) fires on node_save()
 * below and creates the initial "received" transaction on its own — nothing
 * here calls myapi_claim_transaction_create_initial() directly, so the app
 * and the back office create that first transaction through the exact same
 * code path.
 *
 * The 201 response reuses myapi_claim_fetch() + myapi_claim_load_images() +
 * myapi_claim_load_transactions() + myapi_claim_build_item() — the very same
 * functions GET /api/v1/claims/% uses — so the two responses can never drift
 * apart, and the client gets the freshly created claim WITH its initial
 * transaction and authenticated file URLs in one round trip.
 */
function myapi_claim_create() {
  // 1. Authentication.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;
  $condos = myapi_condominium_related_nids($uid);

  // 2. Read the multipart/form-data text fields.
  $subject = myapi_request_post_field('subject');
  $claim_type = myapi_request_post_field('claim_type');
  $condominium_raw = myapi_request_post_field('condominium_id');
  $description = myapi_request_post_field('description');
  $visibility = myapi_request_post_field('visibility');

  $required = [
    'subject'         => $subject,
    'claim_type'      => $claim_type,
    'condominium_id'  => $condominium_raw,
    'description'     => $description,
    'visibility'      => $visibility,
  ];
  foreach ($required as $name => $value) {
    if ($value === NULL || $value === '') {
      myapi_error('missing_field', 422, ['@field' => $name]);
    }
  }

  // 3. subject: node.title is varchar(255).
  if (strlen($subject) > 255) {
    myapi_error('invalid_field', 422, ['@field' => 'subject']);
  }

  // 4. claim_type: a key of field_claim_type's allowed_values.
  if (!myapi_claim_valid_catalogue_value('field_claim_type', $claim_type)) {
    myapi_error('invalid_field', 422, ['@field' => 'claim_type']);
  }

  // 5. condominium_id: positive integer, then one of the reader's own
  // condominiums. An empty $condos (no unit at all) makes every id fall
  // outside it, so this is the SAME 403 for both cases — no special code for
  // "no condominium" beyond the natural consequence of the rule.
  if (!ctype_digit((string) $condominium_raw) || (int) $condominium_raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'condominium_id']);
  }
  $condominium_id = (int) $condominium_raw;
  if (!in_array($condominium_id, array_map('intval', $condos), TRUE)) {
    myapi_error('condominium_access_denied', 403);
  }

  // 6. description: non-empty after trim() (already trimmed above).
  if ($description === '') {
    myapi_error('invalid_field', 422, ['@field' => 'description']);
  }

  // 7. visibility: a key of field_visibility's allowed_values.
  if (!myapi_claim_valid_catalogue_value('field_visibility', $visibility)) {
    myapi_error('invalid_field', 422, ['@field' => 'visibility']);
  }

  // 8. images[]: up to 5, all-or-nothing.
  $image_files = myapi_claim_create_save_files(
    'field_images',
    'reclamo',
    isset($_FILES['images']) ? $_FILES['images'] : [],
    5,
    'claim_invalid_image'
  );

  // 9. attachment: at most 1 (field_attachment is not an array input, so
  // $_FILES can never carry more than one). On failure, the images already
  // saved in step 8 are deleted too — see $also_delete.
  $attachment_file = NULL;
  $attachment_saved = myapi_claim_create_save_files(
    'field_attachment',
    'reclamo',
    isset($_FILES['attachment']) ? $_FILES['attachment'] : [],
    1,
    'claim_invalid_attachment',
    $image_files
  );
  if (!empty($attachment_saved)) {
    $attachment_file = reset($attachment_saved);
  }

  // 10. Build and persist the node. hook_node_insert() creates the initial
  // "received" transaction on its own.
  $node = myapi_claim_build_node($uid, $subject, $claim_type, $condominium_id, $description, $visibility, $image_files, $attachment_file);
  // SPEC 68. Transient flag, not a field: it lives only for this save and is
  // what tells hook_node_insert() that this claim was filed FROM THE APP, so
  // the detail email also goes to 'backend' and to the building admins of the
  // condominium. A claim created from node/add/reclamo carries no flag and
  // mails nobody in the back office — the operator who typed it in already
  // knows. Same mechanism SPEC 30 and 48 already run in production.
  $node->myapi_claim_from_api = TRUE;
  node_save($node);

  // 11. Tie every saved file to the node so cron does not reap it as unused.
  foreach ($image_files as $file) {
    file_usage_add($file, 'myapi', 'node', $node->nid);
  }
  if ($attachment_file) {
    file_usage_add($attachment_file, 'myapi', 'node', $node->nid);
  }

  // 12. Respond 201 with the same shape as GET /api/v1/claims/%, transactions
  // always expanded — the initial transaction hook_node_insert() just created
  // is already there.
  $rows = myapi_claim_fetch($uid, $condos, ['nid' => $node->nid], 1, -1, 'desc');
  $row = reset($rows);
  $nids = [(int) $row->id];
  $images = myapi_claim_load_images($nids);
  $transactions = myapi_claim_load_transactions($nids, TRUE);

  myapi_respond(
    ['claim' => myapi_claim_build_item($row, $images, $transactions)],
    201,
    'claim_created'
  );
}

/**
 * POST /api/v1/claims/% — the requester edits their own claim (SPEC 67).
 *
 * POST on an item and not PUT: PHP fills neither $_POST nor $_FILES on a PUT,
 * so a multipart/form-data body would arrive raw through php://input and would
 * need a hand-written MIME parser. POST reuses myapi_request_post_field() and
 * myapi_claim_create_save_files() unchanged. It is the only POST-on-an-item
 * route of the module, and that is a decision, not an oversight.
 *
 * The update is TOTAL, not partial: the five text fields are required on every
 * call, exactly like POST /api/v1/claims. Files are the one incremental part —
 * images[] appends, remove_image_ids[] removes, and what the request does not
 * mention stays as it is, so a forgotten parameter never destroys anything.
 *
 * Every step aborts with its own error before anything is written, in this
 * order:
 *
 *   1. Authentication.
 *   2. {id} is a positive integer, or 404 claim_not_found.
 *   3. Visibility: myapi_claim_fetch() with no row, 404 claim_not_found — the
 *      same rule, the same query and the same uniform 404 as
 *      GET /api/v1/claims/%.
 *   4. Requester: row.requester_id !== uid, 403 claim_edit_denied. NOT a 404:
 *      the claim is already visible to whoever is asking (a public claim of a
 *      neighbour), so there is nothing left to hide and a 404 would only
 *      confuse the diagnosis.
 *   5. Status: row.status !== 'received', 409 claim_not_editable. The payload
 *      is valid; what fails is the current state of the resource — once the
 *      administration took the claim, the resident no longer edits it.
 *   6-11. The five text fields, with the exact same checks as
 *      myapi_claim_create().
 *   12. remove_image_ids[] — myapi_claim_update_parse_removals(), which is
 *      where a fid that is not one of THIS claim's images answers 422.
 *   13. Image quota: the number of new images admissible is
 *      5 - (current - removed), passed as $max_count to
 *      myapi_claim_create_save_files(), which already answers
 *      claim_too_many_images. No counting code of its own.
 *   14-15. images[] and attachment, same all-or-nothing helper as creation.
 *
 * The write order is what keeps nothing half-done: everything above validates
 * and saves the NEW files first — a failure there deletes them and leaves the
 * claim untouched, text and files alike. Then the node is saved, then the new
 * files are tied to it with file_usage_add(), and only THEN the old ones are
 * deleted (myapi_claim_update_delete_files()). Deleting after node_save() is
 * what guarantees a file the node still references is never destroyed.
 *
 * The node is node_load()ed and only the request's fields are overwritten, so
 * the immutable ones (node.uid, node.created, field_requester, field_status,
 * field_reception_date) stay intact BY CONSTRUCTION — no line preserves them
 * by hand and no future field can be forgotten. hook_node_update() has no
 * 'reclamo' branch, so this node_save() fires no transaction and no
 * notification: the timeline is exactly the same after editing.
 *
 * @param mixed $id  The nid from the route. Anything that is not a positive
 *                   integer is a 404, never a PHP error.
 */
function myapi_claim_update($id) {
  // 1. Authentication.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 2. '/api/v1/claims/abc' answers the same 404 as a missing claim.
  if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
    myapi_error('claim_not_found', 404);
  }
  $nid = (int) $id;

  // 3. Visibility: the very same fetch GET /api/v1/claims/% runs. A claim of
  // another condominium, a private one of another resident and a
  // non-existent one are indistinguishable here, exactly as in the detail.
  $condos = myapi_condominium_related_nids($uid);
  if (empty($condos)) {
    myapi_error('claim_not_found', 404);
  }
  $rows = myapi_claim_fetch($uid, $condos, ['nid' => $nid], 1, -1, 'desc');
  if (empty($rows)) {
    myapi_error('claim_not_found', 404);
  }
  $row = reset($rows);

  // 4. Only the requester edits. Visible but not yours: 403, never 404.
  if ((int) $row->requester_id !== $uid) {
    myapi_error('claim_edit_denied', 403);
  }

  // 5. Only while the administration has not taken it yet.
  if ($row->status !== 'received') {
    myapi_error('claim_not_editable', 409);
  }

  // 6. The five text fields, all required on every call.
  $subject = myapi_request_post_field('subject');
  $claim_type = myapi_request_post_field('claim_type');
  $condominium_raw = myapi_request_post_field('condominium_id');
  $description = myapi_request_post_field('description');
  $visibility = myapi_request_post_field('visibility');

  $required = [
    'subject'         => $subject,
    'claim_type'      => $claim_type,
    'condominium_id'  => $condominium_raw,
    'description'     => $description,
    'visibility'      => $visibility,
  ];
  foreach ($required as $name => $value) {
    if ($value === NULL || $value === '') {
      myapi_error('missing_field', 422, ['@field' => $name]);
    }
  }

  // 7. subject: node.title is varchar(255).
  if (strlen($subject) > 255) {
    myapi_error('invalid_field', 422, ['@field' => 'subject']);
  }

  // 8. claim_type: a key of field_claim_type's allowed_values.
  if (!myapi_claim_valid_catalogue_value('field_claim_type', $claim_type)) {
    myapi_error('invalid_field', 422, ['@field' => 'claim_type']);
  }

  // 9. condominium_id: positive integer, then one of the editor's own
  // condominiums — the same rule as creation, which is what lets a resident
  // fix a wrongly filed claim without being able to move it out of reach.
  if (!ctype_digit((string) $condominium_raw) || (int) $condominium_raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => 'condominium_id']);
  }
  $condominium_id = (int) $condominium_raw;
  if (!in_array($condominium_id, array_map('intval', $condos), TRUE)) {
    myapi_error('condominium_access_denied', 403);
  }

  // 10. description: non-empty after trim() (already trimmed above).
  if ($description === '') {
    myapi_error('invalid_field', 422, ['@field' => 'description']);
  }

  // 11. visibility: a key of field_visibility's allowed_values.
  if (!myapi_claim_valid_catalogue_value('field_visibility', $visibility)) {
    myapi_error('invalid_field', 422, ['@field' => 'visibility']);
  }

  // 12. The node itself. Everything the request does not name is preserved by
  // loading it instead of building a new one.
  $node = node_load($nid);
  if (!$node) {
    myapi_error('claim_not_found', 404);
  }

  $current_image_fids = myapi_claim_node_file_fids($node, 'field_images');
  $current_attachment_fids = myapi_claim_node_file_fids($node, 'field_attachment');

  // 13. remove_image_ids[]: validated against what the claim references right
  // now. A fid of another claim, of a payment or of nothing answers 422 here,
  // before a single file is saved or deleted.
  $removed_image_fids = myapi_claim_update_parse_removals($current_image_fids);

  // 14. Image quota: what is left after the removals decides how many new
  // images fit. myapi_claim_create_save_files() already answers
  // claim_too_many_images when the request sends more than that.
  $kept_count = count($current_image_fids) - count($removed_image_fids);
  $max_new_images = 5 - $kept_count;
  if ($max_new_images < 0) {
    $max_new_images = 0;
  }

  // 15. images[]: all-or-nothing. On failure nothing has been written yet, so
  // the claim keeps its text and its original files.
  $image_files = myapi_claim_create_save_files(
    'field_images',
    'reclamo',
    isset($_FILES['images']) ? $_FILES['images'] : [],
    $max_new_images,
    'claim_invalid_image'
  );

  // 16. attachment: at most 1. On failure the images just saved by step 15 are
  // deleted too ($also_delete), so a rejected attachment leaves no orphan.
  $attachment_file = NULL;
  $attachment_saved = myapi_claim_create_save_files(
    'field_attachment',
    'reclamo',
    isset($_FILES['attachment']) ? $_FILES['attachment'] : [],
    1,
    'claim_invalid_attachment',
    $image_files
  );
  if (!empty($attachment_saved)) {
    $attachment_file = reset($attachment_saved);
  }

  // remove_attachment is ignored when a new attachment came with the request:
  // field_attachment has cardinality 1, so uploading one already replaces the
  // previous file — the outcome with and without the flag is identical, and a
  // 422 would reject a request whose meaning is unambiguous.
  $remove_attachment_raw = myapi_request_post_field('remove_attachment');
  $remove_attachment = !$attachment_file
    && $remove_attachment_raw !== NULL
    && in_array(strtolower($remove_attachment_raw), ['1', 'true'], TRUE);

  // 17. Overwrite ONLY the request's fields on the loaded node.
  $node->title = $subject;
  $node->field_claim_type[LANGUAGE_NONE][0]['value'] = $claim_type;
  $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = $condominium_id;
  $node->field_description[LANGUAGE_NONE][0]['value'] = $description;
  $node->field_visibility[LANGUAGE_NONE][0]['value'] = $visibility;

  // 18. The final field_images: what survives the removals, in its current
  // delta order, plus the new files at the end. Reordering the existing ones
  // is out of this spec's scope.
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

  // 19. The attachment: replaced, emptied, or left exactly as it was.
  $deleted_attachment_fids = [];
  if ($attachment_file) {
    $node->field_attachment[LANGUAGE_NONE][0] = ['fid' => $attachment_file->fid, 'display' => 1];
    $deleted_attachment_fids = $current_attachment_fids;
  }
  elseif ($remove_attachment) {
    $node->field_attachment[LANGUAGE_NONE] = [];
    $deleted_attachment_fids = $current_attachment_fids;
  }

  // 20. Persist. hook_node_update() has no 'reclamo' branch, so this creates
  // no transaction and sends no notification.
  node_save($node);

  // 21. Tie every new file to the node so cron does not reap it as unused.
  foreach ($image_files as $file) {
    file_usage_add($file, 'myapi', 'node', $node->nid);
  }
  if ($attachment_file) {
    file_usage_add($attachment_file, 'myapi', 'node', $node->nid);
  }

  // 22. Only now the files that left the claim are deleted for real. After the
  // node_save(), never before: this is what guarantees no file the node still
  // references is ever destroyed.
  myapi_claim_update_delete_files(array_merge($removed_image_fids, $deleted_attachment_fids), $node->nid);

  // 23. Respond 200 with the same object as GET /api/v1/claims/%, transactions
  // always expanded — the same helpers, so the two responses cannot drift.
  $rows = myapi_claim_fetch($uid, $condos, ['nid' => $node->nid], 1, -1, 'desc');
  $row = reset($rows);
  $nids = [(int) $row->id];
  $images = myapi_claim_load_images($nids);
  $transactions = myapi_claim_load_transactions($nids, TRUE);

  myapi_respond(
    ['claim' => myapi_claim_build_item($row, $images, $transactions)],
    200,
    'claim_updated'
  );
}

/**
 * GET /api/v1/claims — the claims of the authenticated resident.
 *
 * Orchestration only; every decision it makes lives in one of the helpers
 * above. In order: authenticate, resolve the reader's condominiums, parse the
 * query string, count, fetch the page, batch-load images and transactions, and
 * serialise.
 *
 * A reader with no unit at all gets 200 with an empty list and total 0, NOT a
 * 403: "you have nothing" is not "you may not", the same criterion as
 * GET /api/v1/bulletins. It also short-circuits before any query, which is
 * what keeps myapi_claim_base_query() from ever building an "IN ()" — invalid
 * SQL in D7.
 *
 * Query-string parsing is lax throughout — an invalid page, limit, sort,
 * status, claim_type, date bound or include falls back to its default in
 * silence, never a 422. The single exception is '?condominium_id' pointing at a
 * condominium the reader does not belong to, which myapi_claim_parse_condominium_id()
 * answers with 403 (see its docblock for why that one is different).
 *
 * Exactly three queries reach the database per request — count, page, images —
 * plus one for the transactions and, when they are expanded, one more for
 * THEIR images. None of them grows with the number of rows.
 */
function myapi_claim_list() {
  // 1. Authentication. Answers 401 missing_authorization / invalid_token on
  // its own and never returns on failure; on success it hands back the token
  // row, whose uid is the reader.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // 2. The reader's condominiums: the outer bound of everything below.
  $condos = myapi_condominium_related_nids($uid);
  if (empty($condos)) {
    myapi_respond([
      'claims' => [],
      'pagination' => [
        'total'       => 0,
        'page'        => 1,
        'limit'       => 20,
        'total_pages' => 0,
      ],
    ], 200);
  }

  // 3. Pagination and sort. Same idiom as payments: garbage falls back to the
  // default, and '-1' means "everything on one page" (SPEC 15).
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

  // 4. Filters. status and claim_type go through the SAME whitelists the back
  // office uses (includes/myapi.claims_common.inc): none is written here, so
  // '?status=duplicated' falls back to "no filter" through one line of code
  // shared by both consumers. The only difference is that this endpoint takes
  // a LIST of statuses (SPEC 69) — myapi_claims_valid_status_list() splits on
  // commas and validates each item with the very same function.
  $range = myapi_claim_parse_date_range();
  $filters = [
    'condominium_id' => myapi_claim_parse_condominium_id($condos),
    'status'         => isset($_GET['status']) ? myapi_claims_valid_status_list($_GET['status']) : NULL,
    'claim_type'     => isset($_GET['claim_type']) ? myapi_claims_valid_claim_type($_GET['claim_type']) : NULL,
    'from'           => $range['from'],
    'to'             => $range['to'],
  ];

  // 5. Count first: the pagination block describes the filtered set, not the
  // page. A page past the last one simply fetches no rows and answers 200 with
  // an empty list.
  $total = myapi_claim_count($uid, $condos, $filters);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  // 6. The page, then its images and transactions in one batch each.
  $rows = myapi_claim_fetch($uid, $condos, $filters, $page, $limit, $sort);
  $nids = [];
  foreach ($rows as $row) {
    $nids[] = (int) $row->id;
  }
  $images = myapi_claim_load_images($nids);
  $transactions = myapi_claim_load_transactions($nids, myapi_claim_include_transactions());

  $claims = array_map(function ($row) use ($images, $transactions) {
    return myapi_claim_build_item($row, $images, $transactions);
  }, $rows);

  // 7. Respond 200 (read-only endpoint, no message).
  myapi_respond([
    'claims' => $claims,
    'pagination' => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * GET /api/v1/claims/% — one claim, with its full timeline.
 *
 * Same access rule as the list, because it is literally the same query: the
 * only differences are a condition on n.nid, no pagination, and transactions
 * that are ALWAYS expanded. That asymmetry with the list is deliberate — the
 * detail exists precisely to read the timeline, and requiring
 * '?include=transactions' here would force every client to always send it.
 *
 * Every way of not being allowed to see a claim answers the same
 * 404 claim_not_found: a nid that does not exist, one from another
 * condominium, one that is private and filed by somebody else, an unpublished
 * one, and a non-numeric id are indistinguishable from each other in the
 * response. A 403 would confirm to anyone probing ids that there IS a private
 * claim behind that number; the uniform 404 leaks neither the existence nor
 * the reason. This is on purpose different from the 403 unit_access_denied of
 * payments and reservations, where the id in the URL is a unit the caller
 * already knows about.
 *
 * @param mixed $id  The nid from the route. Anything that is not a positive
 *                   integer is a 404, never a PHP error.
 */
function myapi_claim_detail($id) {
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  // '/api/v1/claims/abc' must answer the same 404 as a missing claim rather
  // than reaching the query with a non-numeric id.
  if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
    myapi_error('claim_not_found', 404);
  }

  $condos = myapi_condominium_related_nids($uid);
  if (empty($condos)) {
    myapi_error('claim_not_found', 404);
  }

  // The same fetch the list uses, narrowed to one nid and unpaginated: same
  // access rule, same joins, same projected columns — so the item of the detail
  // cannot end up with different keys or types than the item of the list. The
  // query string plays no part here; no filter other than the nid is applied.
  $rows = myapi_claim_fetch($uid, $condos, ['nid' => (int) $id], 1, -1, 'desc');
  if (empty($rows)) {
    myapi_error('claim_not_found', 404);
  }
  $row = reset($rows);

  $nids = [(int) $row->id];
  $images = myapi_claim_load_images($nids);
  $transactions = myapi_claim_load_transactions($nids, TRUE);

  myapi_respond([
    'claim' => myapi_claim_build_item($row, $images, $transactions),
  ], 200);
}

/**
 * Dispatcher for /api/v1/claims/%/files/% (SPEC 65).
 *
 * Its own dispatcher and not a third case inside myapi_claim_dispatch(): that
 * one routes by the presence of $id, and adding a second optional argument
 * would turn "list, detail or file" into a chain of isset() that is hard to
 * read. Two routes, two dispatchers, one responsibility each.
 *
 * Same criterion as myapi_claim_dispatch() for the method check: it happens
 * before any authentication, so POST answers 405 without a token — the method
 * is wrong whoever is asking.
 *
 * @param mixed $id   The claim nid from the route.
 * @param mixed $fid  The file id from the route.
 */
function myapi_claim_file_dispatch($id = NULL, $fid = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_claim_file_download($id, $fid);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * GET /api/v1/claims/%/files/% — the bytes of one claim file (SPEC 65).
 *
 * The app's way into the files that SPEC 65 moved to private://. The back
 * office's way is hook_file_download() (myapi_file_download() in myapi.module),
 * which asks a role question; this asks the resident's one — the same
 * visibility rule as the rest of this file, because it is the same query.
 *
 * THE ONE ENDPOINT OF THIS MODULE THAT DOES NOT ANSWER THE JSON ENVELOPE ON
 * SUCCESS. A binary file has no envelope to travel in, and base64 in a JSON
 * body would inflate it by a third and break every standard image loader.
 * Regla 4 de CLAUDE.md is broken here on purpose, exactly once, and only for
 * the 200: errors below still go through myapi_error() unchanged. Written down
 * in docs/claim.md so it does not read as an oversight.
 *
 * The order of the three checks is the rule, not an implementation detail:
 * token → claim → file. Answering about the file first would let anyone probe
 * fids under the nid of a claim they cannot see; the access question is always
 * answered before the existence one. So a foreign fid under an invisible claim
 * says claim_not_found, and only under a claim the reader DOES see does it say
 * file_not_found.
 *
 * The nid in the route is always the CLAIM's, including for the files of a
 * transaction: the claim is the unit of access, and the client never needs to
 * know which transaction a picture hangs from.
 *
 * @param mixed $id   The claim nid from the route. Anything that is not a
 *                    positive integer answers 404, never a PHP error.
 * @param mixed $fid  The file id from the route, same treatment.
 */
function myapi_claim_file_download($id, $fid) {
  // 1. Token. Answers 401 on its own and never returns on failure.
  $token = myapi_auth_require_access_token();
  $uid = (int) $token->uid;

  if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
    myapi_error('claim_not_found', 404);
  }
  $id = (int) $id;

  // 2. The claim, under the visibility rule of SPEC 64. Not rewritten here:
  // this is the same myapi_claim_base_query() the list and the detail use, so
  // who may see a private claim is decided in one place for all three. Every
  // way of not being allowed answers the same 404 as GET /api/v1/claims/%.
  $condos = myapi_condominium_related_nids($uid);
  if (empty($condos)) {
    myapi_error('claim_not_found', 404);
  }

  if (myapi_claim_count($uid, $condos, ['nid' => $id]) === 0) {
    myapi_error('claim_not_found', 404);
  }

  // 3. The file. From here on the reader has already proved they see this
  // claim, so distinguishing "that file is not of this claim" leaks nothing —
  // and it is a different problem to diagnose in support.
  module_load_include('inc', 'myapi', 'includes/myapi.claims_files');

  if (!is_scalar($fid) || !ctype_digit((string) $fid) || (int) $fid <= 0) {
    myapi_error('file_not_found', 404);
  }
  $fid = (int) $fid;

  // Membership, resolved by the include the back-office hook also calls: the
  // fid must hang from THIS claim or from one of its transactions. A fid of
  // another claim, or one that belongs to no claim at all (a payment receipt),
  // answers 404 here rather than being served.
  if (myapi_claims_file_claim_nid($fid) !== $id) {
    myapi_error('file_not_found', 404);
  }

  $file = file_load($fid);

  // A file_managed row pointing at a file that is not on disk answers 404 too,
  // not a 200 of zero bytes nor a PHP warning from fopen().
  if (!is_object($file) || !file_exists($file->uri)) {
    myapi_error('file_not_found', 404);
  }

  // 4. The bytes. file_transfer() sends the headers, streams the file in
  // chunks and ends the request; nothing after this line runs.
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
