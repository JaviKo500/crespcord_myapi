<?php

/**
 * @file
 * The two queries every service_request read starts from (SPEC 100), plus the
 * shared item serialiser and the access rule (SPEC 103).
 *
 * Extracted VERBATIM from resources/service_request.resource.inc, where they
 * lived since SPECS 88 and 89, and NOT renamed: all four keep the names their
 * callers already use, so the move is a change of file and nothing else. The
 * existing suite — ServiceRequestDetailEndpointTest, ServiceRequestCancelTest,
 * ServiceRequestUpdateTest, ServiceRequestProviderDetailTest and
 * ServiceRequestProviderListTest — passes without a single test being touched,
 * and that is the proof the extraction moved no behaviour.
 *
 * SPEC 103 ADDED THE OTHER TWO, for the same reason and by the same move.
 * GET /api/v1/service-offers/{id} has to answer "is this reader the requester
 * of the request this offer hangs on", and the ONE function that answers it is
 * myapi_service_request_viewer(); its context block reuses the five keys
 * myapi_service_request_build_item() already shapes. Reimplementing either in
 * the offer would be a second definition of "who is the requester" and of
 * "what a request item looks like" — and the symptom of them drifting is a
 * reader who can open the offer but not the request, with nothing failing
 * loudly. Neither was renamed and neither changed a line, so every call site
 * inside the request resource is the one it always was.
 *
 * WHY THEY LEFT THE RESOURCE. Rule 5 of CLAUDE.md forbids a resource calling
 * another resource's internals, and SPEC 100 gives the offer a resource of its
 * own: resources/service_offer.resource.inc has to resolve "does this request
 * exist, and is it still offerable" before it writes anything, and the ONE
 * function that answers "which requests are real" for this module is
 * myapi_service_request_detail_row(). Copying it into the offer would be two
 * answers to that question, drifting from the day one of them changed. So it
 * moves here, where both resources reach it as an include, which is the same
 * toll SPEC 90 paid extracting myapi_node_files_save().
 *
 * myapi_service_request_base_query() travels with it and is not an extra:
 * detail_row()'s first line IS a call to it, so leaving it behind would have
 * made this file depend on a resource — exactly the coupling the move exists
 * to remove. It keeps its three other callers inside the resource
 * (myapi_service_request_count(), myapi_service_request_fetch() and the
 * provider listing's test), which reach it through this include.
 *
 * NEITHER QUERY CARRIES ->addTag('node_access'), and that is inherited, not
 * decided here: see the @file docblock of resources/service_request.resource.inc
 * for why tagging them would silently shorten a resident's own listing the day
 * they also held the 'proveedor' role.
 *
 * Like the rest of this module's read paths, both read the field_data_* tables
 * directly instead of going through node_load(), with the trade-off
 * docs/service-request.md documents: they assume the current field storage.
 */

// For MYAPI_SERVICES_REQUEST_TYPE, MYAPI_SERVICES_OFFER_TYPE,
// MYAPI_SERVICES_PROVIDER_TYPE and the two request status keys: neither a
// bundle machine name nor a status key is ever written as a literal here.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// SPEC 103: the four questions the provider's half of
// myapi_service_request_viewer() hangs on — which providers the reader
// operates, their categories, whether any of them is active, and whether one
// of them already offered. It travelled here with the function, so this file
// answers "who is asking" without depending on whoever loaded it.
module_load_include('inc', 'myapi', 'includes/myapi.provider_role');

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
