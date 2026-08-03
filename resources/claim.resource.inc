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
 * myapi_claims_valid_status() and myapi_claims_valid_claim_type() in
 * includes/myapi.claims_common.inc, shared verbatim with the back office so
 * both consumers accept exactly the same values (Regla 3 de CLAUDE.md).
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
 *                        'status' => string|NULL, 'claim_type' => string|NULL,
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
  if (!empty($filters['status'])) {
    $query->condition('fs.field_status_value', $filters['status']);
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
 * Endpoints.
 * ------------------------------------------------------------------------ */

/**
 * Dispatcher for /api/v1/claims and /api/v1/claims/%.
 *
 * Routes by HTTP method, the same shape every resource in this module uses.
 * One callback serves both routes: hook_menu() passes the nid for the item path
 * and nothing for the collection, so $id is what tells list from detail.
 *
 * GET is the only supported method — this resource is read-only (SPEC 64) —
 * and POST/PUT/DELETE answer 405 on BOTH paths. Authentication is deliberately
 * NOT resolved here but inside myapi_claim_list() / myapi_claim_detail(), so an
 * unsupported method answers 405 without a token rather than 401: the method is
 * wrong whoever is asking.
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
  else {
    myapi_error('method_not_allowed', 405);
  }
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

  // 4. Filters. status and claim_type go through the SAME validators the back
  // office uses (includes/myapi.claims_common.inc): no whitelist is written
  // here, so '?status=duplicated' falls back to "no filter" through one line of
  // code shared by both consumers.
  $range = myapi_claim_parse_date_range();
  $filters = [
    'condominium_id' => myapi_claim_parse_condominium_id($condos),
    'status'         => isset($_GET['status']) ? myapi_claims_valid_status($_GET['status']) : NULL,
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
