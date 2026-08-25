<?php

/**
 * @file
 * The two queries every service_request read starts from (SPEC 100).
 *
 * Extracted VERBATIM from resources/service_request.resource.inc, where they
 * lived since SPECS 88 and 89, and NOT renamed: both keep the names their
 * callers already use, so the move is a change of file and nothing else. The
 * existing suite — ServiceRequestDetailEndpointTest, ServiceRequestCancelTest,
 * ServiceRequestUpdateTest, ServiceRequestProviderDetailTest and
 * ServiceRequestProviderListTest — passes without a single test being touched,
 * and that is the proof the extraction moved no behaviour.
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
