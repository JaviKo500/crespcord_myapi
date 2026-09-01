<?php

/**
 * @file
 * The loaders and the serialisers of the resident's service_request detail
 * (SPEC 106).
 *
 * Extracted VERBATIM from resources/service_request.resource.inc, where they
 * lived since SPECS 88, 89 and 93, and NOT renamed: all six keep the names
 * their callers already use, so the move is a change of file and nothing else.
 * The existing suite — ServiceRequestDetailEndpointTest,
 * ServiceRequestListEndpointTest, ServiceRequestCreateEndpointTest,
 * ServiceRequestCancelTest, ServiceRequestUpdateTest,
 * ServiceRequestProviderDetailTest and ServiceOfferDetailTest — passes without
 * a single assertion being touched, and that is the proof the extraction moved
 * no behaviour.
 *
 * THIS FILE FINISHES A SPLIT SPEC 89 LEFT HALF DONE. The *query* half of the
 * detail — myapi_service_request_base_query(), myapi_service_request_detail_row(),
 * myapi_service_request_build_item() and myapi_service_request_viewer() — has
 * lived in includes/myapi.service_request_query.inc since SPEC 100 and SPEC 103
 * moved it there. The *loader and serialiser* half stayed behind in the
 * resource, which meant the two halves of one answer sat on opposite sides of
 * Rule 5. This file is that second half, and after it the resource keeps only
 * the endpoints.
 *
 * WHY THEY LEFT THE RESOURCE. Rule 5 of CLAUDE.md forbids a resource calling
 * another resource's internals, and PUT /api/v1/service-offers/{id}/accept
 * (SPEC 106) lives in resources/service_offer.resource.inc: awarding an offer
 * changes the request, so the 200 it answers is the resident's WHOLE detail —
 * the same object GET /api/v1/service-requests/{id} answers, byte for byte,
 * because it is built by these very functions. Copying any of the six into the
 * offer would be a second definition of what a request detail looks like,
 * drifting from the day one of them changed. So they move here, where both
 * resources reach them as an include, which is the same toll SPEC 90 paid
 * extracting myapi_node_files_save() and SPEC 89 paid for the query half.
 *
 * myapi_service_request_offer_counts_by_nid() travels with them and is not an
 * extra: it is the ONE function that answers "how many offers does this
 * request have", the listing and both details already share it, and
 * myapi_service_request_build_detail() takes its result as an argument
 * precisely so that count can never be derived from count($offers). Its other
 * callers inside the resource — the two listings and the edit gate — reach it
 * through this include.
 *
 * TWO FUNCTIONS WERE BORN HERE AFTERWARDS, and both are about ONE key each of
 * the award (SPEC 89). `assigned_offer` and `assigned_provider` used to be two
 * keys apiece — an id and a name, an id and a status — and are now the WHOLE
 * offer and the WHOLE provider card, which is what the app needs on the one
 * screen where the resident looks at what they hired:
 *
 *   myapi_service_request_assigned_offer_item()  — pure, and costs no query:
 *      the awarded offer of a request IS one of that request's offers, so it
 *      comes out of the list the detail already loaded. It also owns the one
 *      confidentiality rule of the pair — the redaction for a bidder who lost.
 *   myapi_service_request_assigned_provider_card() — one call to
 *      myapi_provider_card_by_nid(), and only when there is an award. It lives
 *      here rather than in either serialiser because both serialisers are pure,
 *      and it is written once because seven call sites answer this same detail.
 *
 * Both are shared by the resident's serialiser and the provider's, which is
 * what keeps the two routes from ever answering two different things about one
 * award.
 *
 * myapi_service_request_provider_build_detail() DID NOT COME. It is the
 * provider's sibling of myapi_service_request_build_detail() and nobody outside
 * the request resource calls it, so moving it would be moving code without a
 * reason. It stays where it is and reaches
 * myapi_service_request_build_file() from here, which is what an include is
 * for.
 *
 * NO QUERY HERE CARRIES ->addTag('node_access'), and that is inherited, not
 * decided here: see the @file docblock of resources/service_request.resource.inc
 * for why tagging them would silently shorten a resident's own listing the day
 * they also held the 'proveedor' role.
 *
 * Like the rest of this module's read paths, they read the field_data_* tables
 * directly instead of going through node_load(), with the trade-off
 * docs/service-request.md documents: they assume the current field storage.
 */

// For MYAPI_SERVICES_OFFER_TYPE, MYAPI_SERVICES_PROVIDER_TYPE and
// MYAPI_SERVICES_TRANSACTION_TYPE: a bundle machine name is never written as a
// literal here.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// SPEC 89: myapi_service_request_build_item(), the listing's own serialiser.
// myapi_service_request_build_detail() calls it and adds eight keys after it,
// which is what makes the detail's first eleven keys byte for byte the
// listing's — see its docblock.
module_load_include('inc', 'myapi', 'includes/myapi.service_request_query');
// SPEC 89: myapi_service_offer_build(), the serialiser of an item of `offers`.
// The detail used to serve the awarded offer's id and status; since it serves
// the WHOLE offer under `assigned_offer`, the object it answers has to be built
// by the same function that builds the items of the list, and not by a second
// one — see myapi_service_request_assigned_offer_item().
module_load_include('inc', 'myapi', 'includes/myapi.service_offer');
// SPEC 89: myapi_provider_card_by_nid(), the eight keys GET /api/v1/providers
// answers per provider. `assigned_provider` is that card and not a name, so it
// is loaded from the file that OWNS the card — includes/myapi.provider_card.inc,
// where SPEC 89 moved it out of resources/provider.resource.inc — and never
// rebuilt here.
module_load_include('inc', 'myapi', 'includes/myapi.provider_card');
// For myapi_text_to_plain() and myapi_text_to_multiline(): the timeline's
// comment and the two names of the detail travel flattened, so nothing the
// back office wrote with a rich editor reaches the app as markup.
module_load_include('inc', 'myapi', 'includes/myapi.text');

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
 *                `provider_logo_uri`, `amount`, `message`, `status`,
 *                `created`, and the ten of SPEC 100: `amount_type`,
 *                `valid_until`, `available_from`, `duration`, `duration_unit`,
 *                `includes`, `excludes`, `tax_included`, `warranty_days` and
 *                `requires_visit` — the aliases myapi_service_offer_build()
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

  // The ten quote columns of SPEC 100. ALL TEN ARE LEFT JOINS, and that is not
  // the usual caution: an offer created before myapi_update_7035() has no row
  // in ANY of these ten tables, so a single INNER here would drop every
  // historic offer out of `offers` and out of `my_offers` — and out of
  // agreement with offers_count, which counts it. They answer null, which is
  // what myapi_service_offer_build() serves for them.
  //
  // None of the ten can multiply a row: every field is cardinality 1 and joined
  // on entity_id, which is the primary key of those tables.
  $query->leftJoin('field_data_field_offer_amount_type', 'fat', "fat.entity_id = no.nid AND fat.entity_type = 'node' AND fat.deleted = 0");
  $query->addField('fat', 'field_offer_amount_type_value', 'amount_type');

  $query->leftJoin('field_data_field_offer_valid_until', 'fvu', "fvu.entity_id = no.nid AND fvu.entity_type = 'node' AND fvu.deleted = 0");
  $query->addField('fvu', 'field_offer_valid_until_value', 'valid_until');

  $query->leftJoin('field_data_field_offer_available_from', 'faf', "faf.entity_id = no.nid AND faf.entity_type = 'node' AND faf.deleted = 0");
  $query->addField('faf', 'field_offer_available_from_value', 'available_from');

  $query->leftJoin('field_data_field_offer_duration', 'fdu', "fdu.entity_id = no.nid AND fdu.entity_type = 'node' AND fdu.deleted = 0");
  $query->addField('fdu', 'field_offer_duration_value', 'duration');

  $query->leftJoin('field_data_field_offer_duration_unit', 'fdn', "fdn.entity_id = no.nid AND fdn.entity_type = 'node' AND fdn.deleted = 0");
  $query->addField('fdn', 'field_offer_duration_unit_value', 'duration_unit');

  $query->leftJoin('field_data_field_offer_includes', 'fin', "fin.entity_id = no.nid AND fin.entity_type = 'node' AND fin.deleted = 0");
  $query->addField('fin', 'field_offer_includes_value', 'includes');

  $query->leftJoin('field_data_field_offer_excludes', 'fex', "fex.entity_id = no.nid AND fex.entity_type = 'node' AND fex.deleted = 0");
  $query->addField('fex', 'field_offer_excludes_value', 'excludes');

  $query->leftJoin('field_data_field_offer_tax_included', 'fti', "fti.entity_id = no.nid AND fti.entity_type = 'node' AND fti.deleted = 0");
  $query->addField('fti', 'field_offer_tax_included_value', 'tax_included');

  $query->leftJoin('field_data_field_offer_warranty_days', 'fwd', "fwd.entity_id = no.nid AND fwd.entity_type = 'node' AND fwd.deleted = 0");
  $query->addField('fwd', 'field_offer_warranty_days_value', 'warranty_days');

  $query->leftJoin('field_data_field_offer_requires_visit', 'frv', "frv.entity_id = no.nid AND frv.entity_type = 'node' AND frv.deleted = 0");
  $query->addField('frv', 'field_offer_requires_visit_value', 'requires_visit');

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
      // MULTILINE: an operator writes this one in the back office, where the
      // field has a rich editor behind it, and a comment of three lines is a
      // comment of three lines — not one line and not a literal `<p>`.
      'comment'     => $row->comment === NULL ? NULL : myapi_text_to_multiline($row->comment),
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
 * The card of the provider awarded a request, or NULL (SPEC 89).
 *
 * THE ONLY FUNCTION OF THE DETAIL THAT QUERIES THE PROVIDER, and the reason it
 * exists is that both serialisers of the detail are PURE: neither
 * myapi_service_request_build_detail() nor its provider-side sibling may open a
 * query, so the card has to be resolved by the orchestrator and handed to them.
 * Seven call sites do exactly that — the resident's detail, the provider's
 * detail, the creation, the edition, the cancellation, the closing and the
 * award — and every one of them writes the same single line, because the rule
 * of WHEN to pay for the card is written here and nowhere else.
 *
 * IT COSTS NOTHING WHEN THERE IS NO AWARD, and that is the whole point of the
 * guard below: a request nobody has been awarded — every 'open' one, which is
 * most of them — never reaches myapi_provider_card_by_nid() and never pays its
 * two queries. The check is over `assigned_provider_id`, the alias of the
 * JOINED provider node, and never over the raw field column: a field row left
 * pointing at a provider that was deleted or unpublished has already left that
 * alias NULL, and asking for its card would be a query with no possible answer.
 *
 * @param object $row
 *   The row of myapi_service_request_detail_row(), which carries
 *   `assigned_provider_id`.
 *
 * @return array|NULL
 *   The eight keys of myapi_provider_build_item(), or NULL when the request has
 *   no provider awarded.
 */
function myapi_service_request_assigned_provider_card($row) {
  if (!is_object($row) || !isset($row->assigned_provider_id) || $row->assigned_provider_id === '') {
    return NULL;
  }

  return myapi_provider_card_by_nid($row->assigned_provider_id);
}

/**
 * The awarded offer, WHOLE, as the reader is allowed to see it (SPEC 89).
 *
 * `assigned_offer` used to be two keys — {id, status} — and is now the SAME
 * OBJECT an item of `offers` is: the fifteen keys of
 * myapi_service_offer_build(). The app that already parses the list parses this
 * with the same model, and the screen that shows the awarded quote no longer
 * has to look the offer up in the list to find its amount.
 *
 * IT COSTS NO QUERY, and that is the design and not a lucky accident. The
 * awarded offer of a request IS one of that request's offers, so the object is
 * taken from the list the detail has already loaded — matched by id — instead
 * of being read again. A second query would also be a second chance to
 * disagree with the list about the same offer.
 *
 * THE FALLBACK IS A REDACTION AND NOT AN ERROR, and it is the one rule of this
 * function that is about confidentiality. The list handed in is ALREADY TRIMMED
 * BY THE QUERY to what the reader may see (myapi_service_request_load_offers()
 * with the reader's provider nids), so there is exactly one case in which the
 * awarded offer is not in it: a provider who bid and LOST, and who keeps
 * reading the request because rule 2 of myapi_service_request_viewer() says
 * whoever offered keeps their access. Serving them the winning quote would hand
 * a competitor's price to the bidder who lost against it — the very leak SPEC
 * 89 trimmed `offers` to prevent — so what travels is the same fifteen keys
 * with the thirteen that say anything about the quote left empty. `id` and
 * `status` are the two that always carry, because they were already public in
 * the two-key shape this replaces.
 *
 * THE EMPTY OBJECT IS BUILT BY myapi_service_offer_build() TOO, out of a row
 * carrying nothing but the nid and the status, and not written out key by key
 * here. That is what makes "always the same fifteen keys, in the same order"
 * true by construction on both branches, and what keeps this function from
 * becoming a second definition of an offer the day a sixteenth key is added.
 * The nulls it answers are exactly the ones an offer stored before SPEC 100
 * already answers, so no client meets a shape it has not met before.
 *
 * PURE: no database. Both inputs arrive resolved.
 *
 * @param mixed $assigned_offer
 *   The `assigned_offer` key of myapi_service_request_build_item(): NULL, or
 *   the two-key {id, status} it builds from the JOINED offer node.
 * @param array $offers
 *   The list of myapi_service_offer_build() items the detail is answering —
 *   `offers` on the resident's route, `my_offers` on the provider's — ALREADY
 *   TRIMMED BY THE QUERY to what this reader may see.
 *
 * @return array|NULL
 *   The fifteen keys of an offer, or NULL when the request has no offer
 *   awarded.
 */
function myapi_service_request_assigned_offer_item($assigned_offer, array $offers) {
  if (!is_array($assigned_offer) || !isset($assigned_offer['id'])) {
    return NULL;
  }

  $id = (int) $assigned_offer['id'];

  foreach ($offers as $offer) {
    if (isset($offer['id']) && (int) $offer['id'] === $id) {
      return $offer;
    }
  }

  // The redaction. Same builder, same fifteen keys, and a row that says only
  // what the two-key shape already said out loud.
  return myapi_service_offer_build((object) [
    'nid'    => $id,
    'status' => isset($assigned_offer['status']) ? $assigned_offer['status'] : NULL,
  ]);
}

/**
 * Shapes the whole detail item, for either reader (SPEC 89).
 *
 * THE ELEVEN FIRST KEYS ARE THE LISTING'S, PRODUCED BY THE LISTING'S OWN
 * SERIALISER. This function calls myapi_service_request_build_item() and adds
 * eight keys after it; it does not restate one of them, and it OVERWRITES
 * three — `unit` for the provider, and `assigned_offer` and `assigned_provider`
 * for both readers. Every id, every key and every position stays exactly where
 * the listing put it, so SPEC 88's response does not change by a single key and
 * the detail is still the listing plus eight; what an overwrite changes is how
 * much of an object travels, never which objects there are.
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
 *            resolution of the same two columns. The resolving rule stays in
 *            one place.
 *
 * THE AWARD TRAVELS WHOLE, AND THAT IS THE OTHER TWO OVERWRITES. A listing
 * answers many requests and can only afford to name the award; a detail answers
 * one and is where the app paints it, so:
 *
 *   assigned_offer    : the SAME fifteen-key object an item of `offers` is,
 *                       taken out of that very list by
 *                       myapi_service_request_assigned_offer_item() — no query,
 *                       and no second opinion about the same offer. The one
 *                       reader it is redacted for is the provider who bid and
 *                       lost, whose reasons are written in that function.
 *   assigned_provider : the SAME eight-key card GET /api/v1/providers answers,
 *                       built by myapi_provider_build_item() and loaded by the
 *                       orchestrator through
 *                       myapi_service_request_assigned_provider_card(). The app
 *                       paints the awarded company with the widget it already
 *                       has for the marketplace, and `title` is the company's
 *                       name in it — the card's own key, not a `name` this
 *                       function would add to keep an older shape alive.
 *
 * Both are still NULL when there is no award, and they still go NULL
 * INDEPENDENTLY: a 'direct' request has a provider and no offer, and that is
 * the whole reason they are two keys and not one.
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
 *   The list of myapi_service_offer_build() items, already ordered and
 *   already trimmed to the reader by the query. Always an array.
 * @param mixed $offers_count
 *   The TOTAL number of published offers of the request, from
 *   myapi_service_request_offer_counts_by_nid().
 * @param array $transactions
 *   The timeline, from myapi_service_request_load_transactions(): already
 *   ordered and already shaped into its five keys. Always an array, empty for
 *   a request born before SPEC 92.
 * @param array|NULL $assigned_provider
 *   The card of the awarded provider, from
 *   myapi_service_request_assigned_provider_card(), or NULL when the request
 *   has none. REQUIRED and not optional on purpose: a default would let a call
 *   site forget it and answer `assigned_provider: null` on a request that
 *   plainly has one, which is the kind of silence a signature is supposed to
 *   prevent.
 *
 * @return array  The nineteen keys, in order.
 */
function myapi_service_request_build_detail($row, $viewer, array $images, array $offers, $offers_count, array $transactions, $assigned_provider) {
  $nid = (int) $row->nid;

  // The eleven keys of the listing, from the listing's own serialiser. The
  // count is handed over as the one-entry map that function expects.
  $item = myapi_service_request_build_item($row, [$nid => (int) $offers_count]);

  // Half of the provider's trim, and the FIRST of the three keys of the listing
  // this function overwrites instead of adding — see the docblock. NULL here is
  // a DECISION and not a missing datum, which is what `viewer` below makes
  // readable.
  if ($viewer === 'provider') {
    $item['unit'] = NULL;
  }

  // THE AWARD, ANSWERED WHOLE. The two other keys of the listing this function
  // overwrites, and the reason the detail is worth a round trip once a request
  // has been awarded: the listing says WHICH offer and WHICH provider won, the
  // detail says what the quote actually is and who the company actually is. The
  // ids and the order of the keys do not move — `assigned_offer` and
  // `assigned_provider` are still the eighth and ninth keys, still null when
  // there is no award — so what changes is the CONTENT of an object, never the
  // shape of the response.
  //
  // Neither line resolves anything: the offer comes out of the list this same
  // response is already carrying, and the card was loaded by the orchestrator
  // through myapi_service_request_assigned_provider_card(). This function stays
  // pure, which is what its docblock promises and what the unit suite relies on.
  $item['assigned_offer'] = myapi_service_request_assigned_offer_item($item['assigned_offer'], $offers);

  // The guard is over the BUILT key and not over the argument: a card that
  // could not be loaded — the provider node deleted between two queries of the
  // same request — answers NULL, the same answer a broken reference already
  // gets from myapi_service_request_build_item(). Passing a card for a request
  // with no award never invents one.
  if ($item['assigned_provider'] !== NULL) {
    $item['assigned_provider'] = is_array($assigned_provider) ? $assigned_provider : NULL;
  }

  // Required on the bundle, but LEFT-joined: a field row deleted by hand
  // answers null rather than a PHP notice in the middle of the response.
  $requester = NULL;
  if (isset($row->requester_uid) && $row->requester_uid !== '') {
    $requester = [
      'id'   => (int) $row->requester_uid,
      'name' => isset($row->requester_name) ? myapi_text_to_plain($row->requester_name) : NULL,
    ];
  }

  $condominium = NULL;
  if (isset($row->condominium_id) && $row->condominium_id !== '') {
    $condominium = [
      'id'   => (int) $row->condominium_id,
      'name' => isset($row->condominium_name) ? myapi_text_to_plain($row->condominium_name) : NULL,
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
