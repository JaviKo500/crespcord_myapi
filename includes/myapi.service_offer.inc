<?php

/**
 * @file
 * The domain of a service offer (SPEC 100).
 *
 * The offer is a resource of its own — resources/service_offer.resource.inc —
 * and Rule 5 of CLAUDE.md forbids it calling into
 * resources/service_request.resource.inc, which is where everything an offer
 * knows about itself used to live. This file is where it moved, so both
 * resources reach it as an include instead of one reaching into the other.
 *
 * myapi_service_offer_build() ARRIVED HERE FROM myapi_service_request_build_offer(),
 * renamed and widened from six keys to fifteen. THE SIX ORIGINAL KEYS STAY
 * FIRST AND IN THEIR EXACT ORDER, which is what lets "the first six keys are
 * byte for byte what SPEC 89 answered" be an acceptance criterion this file
 * cannot break by accident, and what keeps every existing client of the two
 * detail endpoints working untouched. amount_type would read better next to
 * amount; order is contract in this module, and the documentation is where
 * legibility is paid for.
 *
 * NINE OF THE FIFTEEN KEYS ARE NULL ON EVERY OFFER STORED BEFORE SPEC 100, and
 * that is the answer, not a gap: myapi_update_7035() creates the ten columns
 * and backfills nothing. Deducing an amount_type from the amount would put in a
 * provider's mouth a statement they never made.
 *
 * Everything here is PURE — no database, no node_load(), no globals — except
 * the two functions whose names say otherwise (*_provider_row() and
 * *_live_offer_exists()), which are single queries and nothing else. That is
 * what lets tests/unit exercise the whole decision surface with no site booted,
 * the same split includes/myapi.services_common.inc follows.
 */

// For MYAPI_SERVICES_OFFER_TYPE, MYAPI_SERVICES_PROVIDER_TYPE, the offer status
// constants and the two quote catalogues: no bundle machine name and no
// catalogue key is ever written as a literal here.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// For myapi_text_to_plain() and myapi_text_to_multiline(): the four strings an
// offer carries — the provider's name, the message, the includes and the
// excludes — leave this serialiser with no markup in them.
module_load_include('inc', 'myapi', 'includes/myapi.text');

/**
 * Shapes one offer row into the item of `offers` / `my_offers` (SPEC 100).
 *
 * ALWAYS THE SAME FIFTEEN KEYS, IN THIS ORDER, whatever the data. PURE: no
 * database, no node_load().
 *
 * THE FIRST SIX ARE SPEC 89's, UNCHANGED — id, provider, amount, message,
 * status, created — same names, same types, same order, same code. This
 * function is myapi_service_request_build_offer() moved and widened, and the
 * prefix is deliberately untouched so no client of GET /api/v1/service-requests/%
 * or GET /api/v1/service-requests/provider/% notices the move.
 *
 * `provider` IS THE WHOLE OBJECT OR NULL, AND THE OFFER STAYS IN THE LIST
 * EITHER WAY. The provider is resolved through a LEFT JOIN restricted to
 * published 'provider' nodes, so an unpublished or deleted one leaves this NULL
 * — and dropping the offer instead would make `offers` disagree with
 * `offers_count`, which counts it.
 *
 * `logo` IS AN ABSOLUTE, DIRECT URL, unlike the request's own images: field_logo
 * is public:// (SPEC 85), so it is served by the web server and needs no token.
 * Same guard as myapi_provider_build_item() — isset() AND !== '' — so a provider
 * with no logo, or one whose field row points at a file_managed that no longer
 * exists, answers NULL and never "" nor a broken URL.
 *
 * `amount` IS A FLOAT OR NULL, NEVER "95.50" AND NEVER 0.0. The field is
 * optional by SPEC 77's decision — the price can be settled in the chat — and 0
 * is a price somebody offered, not a missing one. Same rule as `hourly_rate` in
 * GET /api/v1/providers.
 *
 * `message`, `includes` AND `excludes` GO THROUGH myapi_text_to_multiline(),
 * exactly like `description` in the listing item and for the same reason: the
 * provider writes them with the Enter key, so the line breaks are content and
 * the one-line helper would answer a wall of text — but any markup in them is
 * removed, because the app paints these in a Text widget. They used to travel
 * as stored, on the argument that the instance pins the 'plain_text' format
 * (SPEC 77); that argument holds only for what the app's creation form writes,
 * and not for the back office, whose node form has a rich editor behind these
 * fields. Nothing new gets stored with markup either — the flattening also runs
 * on the way in, in myapi_service_offer_validate_body() — so this is what fixes
 * the rows written before that.
 *
 * THE NINE NEW KEYS, and the two rules they all obey:
 *
 *   A MISSING OPTIONAL TEXT IS null AND NEVER "", and the emptiness is judged
 *   AFTER the flattening, so a stored `<p></p>` answers null like any other
 *   nothing. `message` is the exception and stays "" when empty, because it is
 *   REQUIRED: an empty message is a corrupt row, not an absence, and the two
 *   must not read alike.
 *
 *   `amount_type` IS null ON EVERY OFFER OLDER THAN SPEC 100. Nothing
 *   backfilled it and nothing will: null says "this offer predates the field",
 *   which is exactly what happened.
 *
 * `duration` IS ONE OBJECT OR ONE NULL, never two flat keys and never
 * {value: null, unit: null}. The two columns are coupled — one without the
 * other means nothing — so a row carrying only one of them answers null, the
 * same way `provider` travels whole or not at all.
 *
 * `tax_included` IS bool OR null: null means the provider never declared it,
 * which is a different answer from "no". `requires_visit` IS NEVER null: the
 * absence of the claim "I need to visit first" reads as false, and there is
 * nothing a null would tell a client that false does not.
 *
 * The two dates are datestamps, so format_date() and not the raw column —
 * the same treatment `created`, `desired_start` and `closed_at` already get.
 *
 * @param object $row
 *   One row of myapi_service_request_load_offers(), carrying `nid`,
 *   `provider_id`, `provider_name`, `provider_logo_uri`, `amount`, `message`,
 *   `status`, `created` and the ten aliases of SPEC 100: `amount_type`,
 *   `valid_until`, `available_from`, `duration`, `duration_unit`, `includes`,
 *   `excludes`, `tax_included`, `warranty_days` and `requires_visit`.
 *
 * @return array  The fifteen keys above, in that order.
 */
function myapi_service_offer_build($row) {
  $provider = NULL;
  if (isset($row->provider_id) && $row->provider_id !== '') {
    $provider = [
      'id'   => (int) $row->provider_id,
      'name' => isset($row->provider_name) ? myapi_text_to_plain($row->provider_name) : NULL,
      'logo' => isset($row->provider_logo_uri) && $row->provider_logo_uri !== ''
        ? file_create_url($row->provider_logo_uri)
        : NULL,
    ];
  }

  // Flattened HERE and not inline in the array below, so the "empty is null"
  // rule is decided over the FLATTENED value: a stored `<p></p>` is nothing
  // written, and answering "" for it would invent a third state the app has to
  // test for on top of null and a real text.
  $includes = myapi_text_to_multiline(isset($row->includes) ? $row->includes : '');
  $excludes = myapi_text_to_multiline(isset($row->excludes) ? $row->excludes : '');

  // Both halves or neither — see the docblock.
  $duration = NULL;
  if (isset($row->duration) && $row->duration !== ''
    && isset($row->duration_unit) && $row->duration_unit !== '') {
    $duration = [
      'value' => (int) $row->duration,
      'unit'  => $row->duration_unit,
    ];
  }

  return [
    'id'             => (int) $row->nid,
    'provider'       => $provider,
    'amount'         => isset($row->amount) && $row->amount !== ''
      ? (float) $row->amount
      : NULL,
    // The three long strings of the offer go through the MULTILINE helper: the
    // provider writes them with the Enter key — a message of two paragraphs, an
    // includes that is really a list — so the line breaks are the content and
    // the one-line helper would destroy them. What goes is the markup, and only
    // the markup, whether it was typed in the app or written from the back
    // office with a rich editor behind the field.
    'message'        => myapi_text_to_multiline(isset($row->message) ? $row->message : ''),
    'status'         => isset($row->status) ? $row->status : NULL,
    'created'        => isset($row->created)
      ? format_date((int) $row->created, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
    'amount_type'    => isset($row->amount_type) && $row->amount_type !== ''
      ? $row->amount_type
      : NULL,
    'valid_until'    => isset($row->valid_until) && $row->valid_until !== ''
      ? format_date((int) $row->valid_until, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
    'available_from' => isset($row->available_from) && $row->available_from !== ''
      ? format_date((int) $row->available_from, 'custom', 'Y-m-d\TH:i:s')
      : NULL,
    'duration'       => $duration,
    'includes'       => $includes !== '' ? $includes : NULL,
    'excludes'       => $excludes !== '' ? $excludes : NULL,
    // The only three-valued key of the fifteen: TRUE, FALSE or "never said".
    'tax_included'   => isset($row->tax_included) && $row->tax_included !== ''
      ? (bool) $row->tax_included
      : NULL,
    'warranty_days'  => isset($row->warranty_days) && $row->warranty_days !== ''
      ? (int) $row->warranty_days
      : NULL,
    // Never null: an undeclared visit requirement is no visit requirement.
    'requires_visit' => isset($row->requires_visit) && $row->requires_visit !== ''
      ? (bool) $row->requires_visit
      : FALSE,
  ];
}

/* ---------------------------------------------------------------------------
 * The gate, and the two queries it needs.
 *
 * TWO QUERIES AND NOT ONE PER CONDITION: the provider is read once, whole, and
 * the uniqueness check runs LAST because it is the only one that can be skipped
 * — everything before it is cheaper and fails more often.
 * ------------------------------------------------------------------------ */

/**
 * The provider, as the gate needs to see it (SPEC 100).
 *
 * ONE QUERY FOR THE THREE SCALARS — the node's title and published flag and its
 * field_license_expiry — plus the two facts that are not columns of a provider
 * node at all, each read from the function that OWNS it:
 *
 *   owned        myapi_provider_role_provider_ids() (SPEC 78). "Whose provider
 *                is this" is decided over field_provider_users, in one place,
 *                statically cached per uid. Asking here with a query of our own
 *                would be a second definition of ownership, drifting the day
 *                SPEC 78's changes.
 *   category_ids myapi_provider_role_category_ids_for_providers() (SPEC 78),
 *                for the same reason and with the same cache. It CANNOT ride in
 *                the query above: field_categories is unlimited, so joining it
 *                would multiply this row by the number of categories and the
 *                caller would have to fold it back — which is exactly the code
 *                that function already is.
 *
 * TAKES THE uid AS WELL AS THE PROVIDER NID, because `owned` has no meaning
 * without an account. What comes back is not a raw node row, it is "this
 * provider, as seen by this account", which is precisely what the gate decides
 * over.
 *
 * NO status = 1 CONDITION, deliberately. An unpublished provider must answer
 * 403 service_offer_provider_not_active and not 403 ..._not_owned: the account
 * does own it, it is suspended. Filtering it out here would collapse the two
 * answers into the wrong one. The bundle condition IS there — a nid of another
 * bundle is not a provider anybody owns.
 *
 * @param mixed $provider_id  The provider nid, already validated as a positive
 *                            integer by the caller.
 * @param mixed $uid          The authenticated reader's uid.
 *
 * @return object|FALSE  A row carrying `nid`, `title`, `status`,
 *                       `license_expiry`, `owned` (bool) and `category_ids`
 *                       (list of ints), or FALSE when no such provider node
 *                       exists.
 */
function myapi_service_offer_provider_row($provider_id, $uid) {
  $provider_id = (int) $provider_id;

  if ($provider_id <= 0) {
    return FALSE;
  }

  $query = db_select('node', 'n');
  $query->condition('n.nid', $provider_id);
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  $query->addField('n', 'nid', 'nid');
  $query->addField('n', 'title', 'title');
  $query->addField('n', 'status', 'status');

  // LEFT: a provider with no licence row at all must reach
  // myapi_services_provider_is_active(), which reads an empty expiry as
  // "expired". An INNER would drop the row and answer "not owned" instead.
  $query->leftJoin('field_data_field_license_expiry', 'fle', "fle.entity_id = n.nid AND fle.entity_type = 'node' AND fle.deleted = 0");
  $query->addField('fle', 'field_license_expiry_value', 'license_expiry');

  $query->range(0, 1);

  $row = $query->execute()->fetchObject();

  if (!$row) {
    return FALSE;
  }

  // (object) ['uid' => n] is enough for both: neither reads ->roles. Same
  // lightweight account myapi_service_request_detail() already builds.
  $account = (object) ['uid' => (int) $uid];

  $row->owned = in_array(
    $provider_id,
    array_map('intval', myapi_provider_role_provider_ids($account)),
    TRUE
  );
  $row->category_ids = array_map(
    'intval',
    myapi_provider_role_category_ids_for_providers([$provider_id])
  );

  return $row;
}

/**
 * Whether this provider already has a LIVE offer on this request (SPEC 100).
 *
 * LIVE IS 'sent' OR 'selected', AND NOTHING ELSE. A 'rejected' or a 'withdrawn'
 * offer does not compete, so it does not block a new one: the resident is being
 * protected from comparing two quotes of the same company, not from a company
 * that already walked away or was turned down coming back.
 *
 * `no.type = MYAPI_SERVICES_OFFER_TYPE` IS NOT DECORATION. field_request is
 * SHARED with 'service_transaction' (SPEC 77), so without the bundle condition
 * a request's own timeline entries would be counted as offers — and the second
 * offer of every provider on a request with any history would be refused.
 *
 * `no.status = 1`: an unpublished offer blocks nothing. It is invisible in
 * `offers`, uncounted by offers_count, and refusing a new offer because of one
 * would be refusing it for a reason nobody can see.
 *
 * The INNER JOIN to field_data_field_offer_status is deliberate: an offer with
 * no status row has no status to be live, and treating a NULL as live would
 * lock a provider out over a broken row.
 *
 * ->range(0, 1): the question is "is there one", not "how many".
 *
 * @param mixed $request_nid  The request nid.
 * @param mixed $provider_id  The provider nid.
 *
 * @return bool  TRUE when a 'sent' or 'selected' offer of that provider already
 *               exists on that request.
 */
function myapi_service_offer_live_offer_exists($request_nid, $provider_id) {
  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);
  $query->condition('fq.field_request_target_id', (int) $request_nid);

  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);

  $query->innerJoin('field_data_field_provider', 'fp', "fp.entity_id = no.nid AND fp.entity_type = 'node' AND fp.deleted = 0");
  $query->condition('fp.field_provider_target_id', (int) $provider_id);

  $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->condition('fos.field_offer_status_value', [
    MYAPI_SERVICES_OFFER_STATUS_SENT,
    MYAPI_SERVICES_OFFER_STATUS_SELECTED,
  ], 'IN');

  $query->addField('no', 'nid', 'nid');
  $query->range(0, 1);

  return $query->execute()->fetchField() !== FALSE;
}

/**
 * The six conditions that decide whether this account may bid (SPEC 100).
 *
 * PURE, and that is what makes the whole matrix testable with no site booted:
 * every query it decides over has already been made by the caller, and what
 * arrives is two rows, a uid and a clock.
 *
 * THEY ARE EVALUATED IN THIS ORDER AND THE FIRST FAILURE ANSWERS. The order is
 * not cosmetic — it is what makes the errors legible:
 *
 *   1. provider_not_owned    Not your provider. Asked first because it is the
 *                            only question whose answer does not depend on the
 *                            request at all.
 *   2. provider_not_active   Yours, but suspended: unpublished node or an
 *                            expired (or missing) licence. Decided by
 *                            myapi_services_provider_is_active(), never by
 *                            reading the two columns here — SPEC 77 owns that
 *                            rule and SPEC 83 and SPEC 90 already obey it.
 *   3. request_not_found     Asked AFTER the provider on purpose: an account
 *                            with no standing to bid learns nothing about which
 *                            request nids exist.
 *   4. own_request           You are the requester. A request that is already
 *                            yours is not bid on, it is worked on.
 *   5. request_not_offerable The request is not taking offers.
 *   6. category_mismatch     Last of the six because it is the only one that
 *                            depends on both rows at once, and the ONLY one a
 *                            'direct' of my own skips (SPEC 101).
 *
 * ALL SIX RUN BEFORE A SINGLE FIELD OF THE BODY IS VALIDATED, the criterion
 * step 6 of SPEC 95 set: WHO YOU ARE DOES NOT DEPEND ON WHAT YOU WROTE. An
 * empty body over a closed request answers 409, never 422.
 *
 * CONDITION 5 READS THE RAW COLUMNS, `assigned_offer_raw` and
 * `assigned_provider_raw`, AND NEVER THE RESOLVED ONES.
 * myapi_service_request_detail_row() leaves `assigned_provider_id` NULL when
 * the awarded node was unpublished, and an award to an unpublished provider is
 * still an award. The raw read fails closed; the resolved one would quietly
 * reopen a request that is already somebody's job.
 *
 * CONDITION 5 HAS A SECOND BRANCH SINCE SPEC 101, AND ONLY ONE: a 'direct'
 * whose field_assigned_provider IS EXACTLY THE PROVIDER THAT IS BIDDING is
 * offerable, because that provider is quoting a job the resident already gave
 * them. field_assigned_offer must still be empty — a request that already has
 * an awarded offer went through a round, and that is not the case this opens.
 * A 'direct' awarded to ANYBODY ELSE answers the same 409 as before and gets no
 * code of its own: a provider who is not the awarded one cannot even see that
 * request, so the shared code hides nothing they did not already not know,
 * while a specific one would confirm to them that it is taken.
 *
 * THE STATUS IS NOT MOVED BY THIS, and that is the whole point of SPEC 101 —
 * see the docblock of resources/service_offer.resource.inc. This function only
 * says who may bid.
 *
 * CONDITION 6 IS SKIPPED ON A 'direct' OF MY OWN, and on nothing else. SPEC 90
 * already checked this provider against the category when the request was
 * born; asking again here would lock a company out of a job the resident
 * personally handed them, only because they stopped serving that category
 * afterwards. Condition 2 is NOT skipped with it: the category says what you
 * do, the licence says whether you may operate at all.
 *
 * An empty or corrupt field_request_status also falls out of condition 5, as a
 * 409 and never a 500: `in_array()` over the two keys says no, which is the
 * safe reading of a status nobody can name.
 *
 * A provider with an empty field_categories fails condition 6 with no special
 * code: `[]` contains nothing, so it matches no request. "You do not serve this
 * category" is the true statement in that case too.
 *
 * @param object|FALSE $request_row   A row of myapi_service_request_detail_row(),
 *                                    or FALSE when there is no such request.
 * @param object|FALSE $provider_row  A row of myapi_service_offer_provider_row(),
 *                                    or FALSE when there is no such provider.
 * @param mixed $uid                  The authenticated reader's uid.
 * @param mixed $now                  The clock, as a Unix timestamp
 *                                    (REQUEST_TIME in production).
 *
 * @return string|NULL  The error_code of the first condition that fails, or
 *                      NULL when all six pass — five of them, on a 'direct'
 *                      of my own.
 */
function myapi_service_offer_eligibility($request_row, $provider_row, $uid, $now) {
  // 1. Mine? A provider node that no longer exists is not owned by anybody,
  //    which is why a FALSE row lands here and not on the active check.
  if (!$provider_row || empty($provider_row->owned)) {
    return 'service_offer_provider_not_owned';
  }

  // 2. Active? The rule lives in services_common, and reading `status` and
  //    `license_expiry` here would be a second copy of it.
  $license_expiry = isset($provider_row->license_expiry) ? $provider_row->license_expiry : NULL;
  if (!myapi_services_provider_is_active((int) $provider_row->status, $license_expiry, $now)) {
    return 'service_offer_provider_not_active';
  }

  // 3. Does the request exist? The detail row already answers "no such
  //    request" for the three indistinguishable reasons — missing, unpublished,
  //    another bundle — so there is nothing to add here.
  if (!$request_row) {
    return 'service_request_not_found';
  }

  // 4. Not my own. Costs an integer comparison over a row already loaded, and
  //    the case is real in this data model: an account can be a resident and a
  //    provider at once.
  if (isset($request_row->requester_uid) && (int) $request_row->requester_uid === (int) $uid) {
    return 'service_offer_own_request';
  }

  // 5. Still taking offers — see the docblock on why the RAW columns.
  $status = isset($request_row->status) ? $request_row->status : NULL;
  $assigned_offer = isset($request_row->assigned_offer_raw) ? $request_row->assigned_offer_raw : NULL;
  $assigned_provider = isset($request_row->assigned_provider_raw) ? $request_row->assigned_provider_raw : NULL;

  // Set only on the one case SPEC 101 opens, and read once, by condition 6.
  $own_direct = FALSE;

  if ($status === MYAPI_SERVICES_REQUEST_STATUS_DIRECT) {
    // The awarded provider quoting their own job (SPEC 101). A provider row
    // with no nid compares as 0 and cannot match the non-empty column above
    // it, so the branch fails closed on a broken row like every other read
    // here does.
    $bidder_id = isset($provider_row->nid) ? (int) $provider_row->nid : 0;

    if (empty($assigned_provider) || (int) $assigned_provider !== $bidder_id) {
      return 'service_request_not_offerable';
    }

    // Mine, but it already carries an awarded offer: that request went through
    // a round and is not the case this branch is for.
    if (!empty($assigned_offer)) {
      return 'service_request_not_offerable';
    }

    $own_direct = TRUE;
  }
  else {
    $biddable = [
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
    ];

    if (!in_array($status, $biddable, TRUE)) {
      return 'service_request_not_offerable';
    }

    if (!empty($assigned_offer) || !empty($assigned_provider)) {
      return 'service_request_not_offerable';
    }
  }

  // 6. My category, UNLESS this is a 'direct' of my own — SPEC 90 already
  //    asked it when the request was born, and the resident's choice outranks
  //    the catalogue afterwards. An empty category on either side matches
  //    nothing, which is the honest answer and needs no code of its own.
  if (!$own_direct) {
    $category_id = isset($request_row->category_id) ? (int) $request_row->category_id : 0;
    $categories = isset($provider_row->category_ids) ? $provider_row->category_ids : [];

    if (!$category_id || !in_array($category_id, array_map('intval', $categories), TRUE)) {
      return 'service_offer_category_mismatch';
    }
  }

  return NULL;
}

/* ---------------------------------------------------------------------------
 * The body, and the node it becomes.
 *
 * Everything below is PURE. Nothing here reads the database, and the only
 * Drupal it touches is t(), drupal_strlen() and truncate_utf8() — which is
 * what puts the whole validation matrix, whole, inside tests/unit.
 * ------------------------------------------------------------------------ */

/**
 * Validates the body of the offer, rule by rule and in order (SPEC 100,
 * amended by SPEC 104).
 *
 * SAME CONTRACT AS myapi_service_request_validate_cancel_reason() (SPEC 95):
 * it answers ['ok' => TRUE, 'values' => [...]] or ['ok' => FALSE,
 * 'error_code' => …, 'replacements' => …], and never prints, never exits and
 * never decides an HTTP status. Every failure here is a 422; the caller is the
 * one that says so.
 *
 * IT RUNS ONLY AFTER THE GATE HAS PASSED. Who you are does not depend on what
 * you wrote, so an empty body over a closed request answers 409 and not 422 —
 * the order lives in myapi_service_offer_create(), and this function is never
 * reached in that case.
 *
 * THE ORDER OF THE TEN IS THE CONTRACT, and the first failure answers. The
 * three conditional rules are what make it matter:
 *
 *   3. `amount` is REQUIRED for 'fixed', 'estimate' and 'hourly' and FORBIDDEN
 *      for 'on_site_quote'. Both halves are enforced: a quote to be priced on
 *      site that carries a number is as wrong as a closed price with none.
 *   4. `tax_included` without an `amount` is refused rather than ignored. "Tax
 *      is included" in a quote with no figure is a statement about nothing.
 *   7. `duration` and `duration_unit` travel together or not at all. One
 *      without the other means nothing, and accepting it would store half a
 *      fact the serialiser would then have to hide.
 *
 * A MISSING OR UNPARSEABLE BODY IS A MISSING `message`, not a body error of its
 * own: the first thing the client failed to send IS the message, and inventing
 * a separate code for "no body" would give the app two things to handle where
 * there is one.
 *
 * BOOLEANS ARE READ AS JSON BOOLEANS AND NOTHING ELSE. "true", "1" and 1 are
 * all 422: the body is JSON, the client can send a real boolean, and accepting
 * the string "false" would open the door to reading it as true — which is what
 * PHP does with any non-empty string.
 *
 * LENGTHS ARE MEASURED IN CHARACTERS, with drupal_strlen() and never strlen():
 * 2000 accented characters are not 2000 bytes, and the cut has to be the one
 * the provider can count. Same rule SPEC 95 set for the cancellation reason.
 *
 * THE TWO DATES MUST BE STRICTLY IN THE FUTURE, the same decision SPEC 90 took
 * for `desired_start` and for the same reason: everything is compared against
 * the server's clock and the exact second is not a case a client builds on
 * purpose. NEITHER IS COMPARED AGAINST THE OTHER, and SPEC 104 removed the rule
 * that did: they are not the two ends of a range. `valid_until` is a deadline on
 * the RESIDENT'S DECISION and `available_from` a date on the PROVIDER'S
 * EXECUTION, so the order between them is free — and the natural one, accept
 * first and work afterwards, is precisely the one the old rule refused.
 *
 * An optional text that is empty after trim() is stored as ABSENT, not as "".
 * The serialiser answers null for it either way, and an empty row is a value
 * somebody will eventually read as one.
 *
 * @param mixed $body  The decoded JSON body, or anything at all when the client
 *                     sent no body or an unparseable one.
 *
 * @return array  ['ok' => TRUE, 'values' => [...]] with the twelve validated
 *                values, or ['ok' => FALSE, 'error_code' => string,
 *                'replacements' => array].
 */
function myapi_service_offer_validate_body($body) {
  if (!is_array($body)) {
    $body = [];
  }

  $values = [
    'message'        => NULL,
    'amount_type'    => NULL,
    'amount'         => NULL,
    'tax_included'   => NULL,
    'valid_until'    => NULL,
    'available_from' => NULL,
    'duration'       => NULL,
    'duration_unit'  => NULL,
    'includes'       => NULL,
    'excludes'       => NULL,
    'warranty_days'  => NULL,
    // The one value that is never NULL: an undeclared visit requirement is no
    // visit requirement.
    'requires_visit' => FALSE,
  ];

  /* 1. message — required, 1..2000 characters. */
  if (!array_key_exists('message', $body)) {
    return _myapi_service_offer_invalid('missing_field', 'message');
  }
  if (!is_string($body['message'])) {
    return _myapi_service_offer_invalid('invalid_field', 'message');
  }
  // FLATTENED BEFORE IT IS MEASURED AND BEFORE IT IS STORED. A client that
  // sends `<p>Hola</p>` — which is what a rich-text editor on the app's edit
  // screen produces, while its create screen sends plain text — must not put
  // markup into field_offer_message: the app paints this value in a Text
  // widget, and nothing downstream of here would ever turn it back into a
  // paragraph. Doing it BEFORE the length check is what makes the 2000 count
  // the provider's words and not the tags around them, and doing it BEFORE the
  // empty check is what makes a body of nothing but `<p></p>` a 422 instead of
  // an offer with an empty message. Old rows that already carry markup are
  // covered on the way out, in myapi_service_offer_build().
  $message = myapi_text_to_multiline($body['message']);
  if ($message === '') {
    return _myapi_service_offer_invalid('invalid_field', 'message');
  }
  if (drupal_strlen($message) > 2000) {
    return _myapi_service_offer_invalid('invalid_field', 'message');
  }
  $values['message'] = $message;

  /* 2. amount_type — required, and one of the catalogue's four. */
  if (!array_key_exists('amount_type', $body)) {
    return _myapi_service_offer_invalid('missing_field', 'amount_type');
  }
  $amount_types = myapi_services_offer_amount_types();
  if (!is_string($body['amount_type']) || !isset($amount_types[$body['amount_type']])) {
    return _myapi_service_offer_invalid('invalid_field', 'amount_type');
  }
  $values['amount_type'] = $body['amount_type'];

  /* 3. amount — conditional on amount_type, both ways. */
  $priced = $values['amount_type'] !== 'on_site_quote';
  $has_amount = array_key_exists('amount', $body) && $body['amount'] !== NULL && $body['amount'] !== '';

  if ($priced && !$has_amount) {
    return ['ok' => FALSE, 'error_code' => 'service_offer_amount_required', 'replacements' => []];
  }
  if (!$priced && $has_amount) {
    return ['ok' => FALSE, 'error_code' => 'service_offer_amount_not_allowed', 'replacements' => []];
  }
  if ($has_amount) {
    // is_numeric() and not is_float(): a JSON client may send 150.50 as a
    // string to keep the decimals exact, and that is a legitimate amount. It
    // answers FALSE for booleans and arrays, which is what keeps `true` out.
    if (!is_numeric($body['amount']) || is_bool($body['amount'])) {
      return _myapi_service_offer_invalid('invalid_field', 'amount');
    }
    $amount = (float) $body['amount'];
    // 0 IS A PRICE somebody offered, so the floor is inclusive. The ceiling is
    // the field's own: number_decimal(10, 2).
    if ($amount < 0 || $amount > 99999999.99) {
      return _myapi_service_offer_invalid('invalid_field', 'amount');
    }
    $values['amount'] = $amount;
  }

  /* 4. tax_included — a real boolean, and only alongside an amount. */
  if (array_key_exists('tax_included', $body) && $body['tax_included'] !== NULL) {
    if (!is_bool($body['tax_included'])) {
      return _myapi_service_offer_invalid('invalid_field', 'tax_included');
    }
    if ($values['amount'] === NULL) {
      return ['ok' => FALSE, 'error_code' => 'service_offer_tax_without_amount', 'replacements' => []];
    }
    $values['tax_included'] = $body['tax_included'];
  }

  /* 5 and 6. The two dates — parseable and strictly in the future. */
  foreach (['valid_until', 'available_from'] as $field) {
    if (!array_key_exists($field, $body) || $body[$field] === NULL || $body[$field] === '') {
      continue;
    }
    $timestamp = _myapi_service_offer_future_timestamp($body[$field]);
    if ($timestamp === FALSE) {
      return _myapi_service_offer_invalid('invalid_field', $field);
    }
    $values[$field] = $timestamp;
  }

  /* 7. duration and duration_unit — both or neither. */
  $has_duration = array_key_exists('duration', $body) && $body['duration'] !== NULL && $body['duration'] !== '';
  $has_unit = array_key_exists('duration_unit', $body) && $body['duration_unit'] !== NULL && $body['duration_unit'] !== '';

  if ($has_duration !== $has_unit) {
    return ['ok' => FALSE, 'error_code' => 'service_offer_duration_incomplete', 'replacements' => []];
  }
  if ($has_duration) {
    $duration = _myapi_service_offer_integer($body['duration']);
    if ($duration === FALSE || $duration < 1 || $duration > 9999) {
      return _myapi_service_offer_invalid('invalid_field', 'duration');
    }
    $units = myapi_services_offer_duration_units();
    if (!is_string($body['duration_unit']) || !isset($units[$body['duration_unit']])) {
      return _myapi_service_offer_invalid('invalid_field', 'duration_unit');
    }
    $values['duration'] = $duration;
    $values['duration_unit'] = $body['duration_unit'];
  }

  /* 8. includes and excludes — optional text, up to 2000 characters. */
  foreach (['includes', 'excludes'] as $field) {
    if (!array_key_exists($field, $body) || $body[$field] === NULL) {
      continue;
    }
    if (!is_string($body[$field])) {
      return _myapi_service_offer_invalid('invalid_field', $field);
    }
    // Same rule as `message` above, and for the same reason: no markup gets
    // into the field, and the 2000 counts characters the provider typed.
    $text = myapi_text_to_multiline($body[$field]);
    if (drupal_strlen($text) > 2000) {
      return _myapi_service_offer_invalid('invalid_field', $field);
    }
    // Empty after trim() is stored as ABSENT, not as "".
    if ($text !== '') {
      $values[$field] = $text;
    }
  }

  /* 9. warranty_days — an integer from 0 to 3650. */
  if (array_key_exists('warranty_days', $body) && $body['warranty_days'] !== NULL && $body['warranty_days'] !== '') {
    $days = _myapi_service_offer_integer($body['warranty_days']);
    // 0 IS A DECLARATION — "no warranty" — and not an absence.
    if ($days === FALSE || $days < 0 || $days > 3650) {
      return _myapi_service_offer_invalid('invalid_field', 'warranty_days');
    }
    $values['warranty_days'] = $days;
  }

  /* 10. requires_visit — a real boolean; absent is FALSE. */
  if (array_key_exists('requires_visit', $body) && $body['requires_visit'] !== NULL) {
    if (!is_bool($body['requires_visit'])) {
      return _myapi_service_offer_invalid('invalid_field', 'requires_visit');
    }
    $values['requires_visit'] = $body['requires_visit'];
  }

  return ['ok' => TRUE, 'values' => $values];
}

/**
 * The failure shape of myapi_service_offer_validate_body(), for the codes that
 * name a field.
 *
 * Written once because ten rules return it: a helper is what keeps the
 * '@field' replacement from being retyped fourteen times, where one typo would
 * answer a message naming the wrong field.
 *
 * @param string $error_code  'missing_field' or 'invalid_field'.
 * @param string $field       The body key, as the client wrote it.
 *
 * @return array  The ['ok' => FALSE, ...] shape.
 */
function _myapi_service_offer_invalid($error_code, $field) {
  return [
    'ok'           => FALSE,
    'error_code'   => $error_code,
    'replacements' => ['@field' => $field],
  ];
}

/**
 * A date the client sent, as a timestamp, or FALSE.
 *
 * strtotime() and not a format assumption, exactly like
 * myapi_service_request_parse_desired_start(): the documented format is
 * 'Y-m-d H:i' and anything else strtotime() understands is accepted too, which
 * costs nothing and refuses nothing a client legitimately sends.
 *
 * STRICTLY GREATER THAN REQUEST_TIME. The exact second of now is refused along
 * with the past — an offer that is valid until this instant is not valid, and
 * SPEC 90 drew the same line for 'desired_start'.
 *
 * @param mixed $raw  The value as it came in the body.
 *
 * @return int|FALSE  The timestamp, or FALSE when it does not parse or is not
 *                    in the future.
 */
function _myapi_service_offer_future_timestamp($raw) {
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
 * An integer the client sent, or FALSE.
 *
 * Accepts a JSON integer and a string of digits — the two shapes a client
 * legitimately produces — and refuses everything else, booleans and floats with
 * a fractional part included: 2.5 hours is not a duration this field can store,
 * and silently flooring it would change what the provider said.
 *
 * @param mixed $raw  The value as it came in the body.
 *
 * @return int|FALSE
 */
function _myapi_service_offer_integer($raw) {
  if (is_bool($raw) || !is_numeric($raw)) {
    return FALSE;
  }

  if ((string) (int) $raw !== (string) $raw && (int) $raw != $raw) {
    return FALSE;
  }

  return (int) $raw;
}

/**
 * The node.title of an offer (SPEC 100).
 *
 * "Oferta de <proveedor> — solicitud #<nid>", never longer than the 255
 * characters node.title holds.
 *
 * THE PROVIDER'S NAME IS WHAT GETS TRUNCATED, not the whole string. Cutting the
 * finished title would take the request number off the end for a long enough
 * company name, and the number is the half that makes the title findable in
 * /admin/content. So the fixed part is measured first and the name is given
 * whatever is left, with truncate_utf8()'s word-safe cut and its ellipsis.
 *
 * NO t() PLACEHOLDER for either value. Drupal runs check_plain() over '@name'
 * style replacements and this string goes into a raw column, so a provider
 * called "Fontanería & Hijos" would be stored with an &amp; in its title —
 * the same trap myapi_service_transaction_title() documents.
 *
 * A provider with no resolvable name still gets a title: the module's promise
 * since SPEC 60 is that no node is ever titleless, and /admin/content showing
 * a list of blank links is how that promise gets broken quietly.
 *
 * @param mixed $request_nid    The request nid.
 * @param string|NULL $provider_name  The provider's node title.
 *
 * @return string  Never empty, never longer than 255 characters.
 */
function myapi_service_offer_title($request_nid, $provider_name) {
  $suffix = ' — ' . t('solicitud') . ' #' . (int) $request_nid;
  $provider_name = trim((string) $provider_name);

  if ($provider_name === '') {
    return truncate_utf8(t('Oferta') . $suffix, 255, TRUE, TRUE);
  }

  $prefix = t('Oferta de') . ' ';
  $budget = 255 - drupal_strlen($prefix) - drupal_strlen($suffix);

  if ($budget < 1) {
    // Defensive: only reachable if the two translated fragments grew past 255
    // characters between them, which would be a translation bug and not data.
    return truncate_utf8($prefix . $provider_name . $suffix, 255, TRUE, TRUE);
  }

  if (drupal_strlen($provider_name) > $budget) {
    $provider_name = truncate_utf8($provider_name, $budget, TRUE, TRUE);
  }

  return $prefix . $provider_name . $suffix;
}

/**
 * The field_comment of the 'open' → 'offered' transaction (SPEC 100).
 *
 * SPEC 92 established that no transaction is ever born without a comment, and
 * this function is what keeps that promise for the one this endpoint writes. It
 * never returns an empty string.
 *
 * The provider's NAME and not the account's: the timeline is read by the
 * resident, and "Plomería Torres envió una oferta" is the sentence that means
 * something to them — the operator's user account does not.
 *
 * No t() placeholder and no check_plain(), for the two reasons SPEC 92 gives:
 * field_comment is stored raw and escaped by whoever renders it, and a '@name'
 * replacement would bake an &amp; into the column.
 *
 * @param string|NULL $provider_name  The provider's node title.
 *
 * @return string  The comment to store, never empty.
 */
function myapi_service_offer_transaction_comment($provider_name) {
  $provider_name = trim((string) $provider_name);

  if ($provider_name === '') {
    return t('Se recibió una oferta.');
  }

  return $provider_name . ' ' . t('envió una oferta.');
}

/**
 * The field_comment of a quote sent on a 'direct' request (SPEC 101).
 *
 * SISTER OF myapi_service_offer_transaction_comment() AND NOT A REPLACEMENT OF
 * IT. That one is the comment of the 'open' → 'offered' move and stays exactly
 * as it was; this one belongs to a transaction whose field_request_status
 * REPEATS the 'direct' the request already had, because the status did not
 * move — see decision 3 of SPEC 101.
 *
 * AND THAT IS WHY THE TWO TEXTS MUST NOT BE THE SAME SENTENCE. The comment is
 * the only thing that tells the two timeline entries of a 'direct' apart: the
 * status column repeats, so the app has nothing else to render. "envió su
 * presupuesto" says what happened — the company that already had the job put a
 * price on it — where "envió una oferta" would read, on a job with no
 * competition, as if somebody were still bidding for it.
 *
 * The provider's NAME and not the account's, never empty, no t() placeholder
 * and no check_plain(): the same three rules as its sister, for the same three
 * reasons — the resident reads this, SPEC 92 forbids a transaction with no
 * comment, and field_comment is stored raw and escaped by whoever renders it.
 *
 * @param string|NULL $provider_name  The provider's node title.
 *
 * @return string  The comment to store, never empty.
 */
function myapi_service_offer_direct_quote_comment($provider_name) {
  $provider_name = trim((string) $provider_name);

  if ($provider_name === '') {
    return t('Se recibió el presupuesto del proveedor asignado.');
  }

  return $provider_name . ' ' . t('envió su presupuesto.');
}

/**
 * Builds the offer node, WITHOUT saving it (SPEC 100).
 *
 * Same pattern as myapi_claim_build_node() and
 * myapi_service_request_build_node(): pure, so the whole shape of what gets
 * written is assertable in tests/unit, and node_save() stays in the resource
 * where the ordering of the three writes lives.
 *
 * THE SEVEN VALUES THE SERVER FIXES, none of which is a field of the body:
 *
 *   node.type          'service_offer'.
 *   node.uid           The uid of the Bearer token — the ACCOUNT that bid.
 *   node.status        1.
 *   node.title         myapi_service_offer_title().
 *   field_request      The {id} of the route, already validated. It cannot
 *                      contradict a request_id in the body because there is no
 *                      request_id in the body.
 *   field_offer_status Always 'sent'. A `status` in the body is ignored, not
 *                      refused: it is not a field of this request.
 *   the three chat     field_firebase_path, field_chat_opened_at and
 *   fields             field_last_message_at are never written HERE. Since
 *                      SPEC 117 the chat mirrors them on the offer — the
 *                      credential writes the path, the notice writes the two
 *                      dates — but a brand new offer has no thread yet, so it
 *                      is born with the three of them empty.
 *
 * node.uid AND field_provider ARE TWO DIFFERENT THINGS AND BOTH ARE WRITTEN. A
 * provider may be operated by several accounts, and the offer has to know which
 * of them sent it — the node's author — as well as which company it commits.
 *
 * THE TWELVE VALUES OF THE BODY are not written here. They are written by
 * myapi_service_offer_apply_values(), which SPEC 105 extracted so that the POST
 * that creates an offer and the PUT that corrects it store them identically —
 * including the rule that an optional value left out is not written at all.
 *
 * @param mixed $uid            The authenticated account's uid.
 * @param mixed $request_nid    The request this offer is made on.
 * @param mixed $provider_id    The validated provider nid.
 * @param string|NULL $provider_name  The provider's node title, for the title.
 * @param array $values         The 'values' of myapi_service_offer_validate_body().
 *
 * @return object  The node, ready for node_save().
 */
function myapi_service_offer_build_node($uid, $request_nid, $provider_id, $provider_name, array $values) {
  $node = new stdClass();
  $node->type = MYAPI_SERVICES_OFFER_TYPE;
  node_object_prepare($node);
  $node->uid = $uid;
  $node->status = 1;
  $node->language = LANGUAGE_NONE;
  $node->title = myapi_service_offer_title($request_nid, $provider_name);

  $node->field_request[LANGUAGE_NONE][0]['target_id'] = (int) $request_nid;
  $node->field_provider[LANGUAGE_NONE][0]['target_id'] = (int) $provider_id;
  $node->field_offer_status[LANGUAGE_NONE][0]['value'] = MYAPI_SERVICES_OFFER_STATUS_SENT;

  // The twelve values of the body, shared with the PUT of SPEC 105.
  myapi_service_offer_apply_values($node, $values);

  return $node;
}

/**
 * Writes the twelve validated body values onto an offer node (SPEC 105).
 *
 * Extracted from myapi_service_offer_build_node() of SPEC 100, unchanged, so
 * that the POST that creates an offer and the PUT that corrects it can never
 * drift into storing the same twelve fields differently. Pure: it mutates the
 * node it is handed and never saves it.
 *
 * AN OPTIONAL VALUE THAT IS NULL IS NOT WRITTEN AT ALL on a node that never
 * had it, rather than written as an empty row. The two are different in the
 * database and the serialiser reads them the same way only by accident;
 * leaving the field alone is what makes a new offer that declared nothing
 * indistinguishable from one stored before myapi_update_7035().
 *
 * ON A NODE THAT DOES HAVE IT, A NULL EMPTIES THE FIELD. That is the half the
 * PUT of SPEC 105 needs and the half the POST never reaches: the PUT is a TOTAL
 * REPLACEMENT, so an optional field the provider left out of the body is a
 * field the provider deleted, and an offer that had 90 warranty days ends the
 * request with none. A brand new node has no such property, so the branch never
 * fires from build_node() and the extraction changed nothing.
 *
 * duration AND duration_unit TRAVEL TOGETHER, as rule 7 of the validator
 * guarantees: the unit follows the duration, so both are written or both are
 * emptied and the pair can never end up half stored.
 *
 * requires_visit IS THE EXCEPTION and is ALWAYS written, as 0 or 1. It has no
 * third state — the absence of "I need to visit" is "I do not" — so storing the
 * false is storing what the provider said.
 *
 * Only 'value' is written and never 'format', the rule every write path of this
 * module follows: the column is stored raw and escaped by whoever renders it.
 *
 * @param object $node   The node to write on, new or loaded. Mutated in place.
 * @param array $values  The 'values' of myapi_service_offer_validate_body().
 *
 * @return object  The same node, for the caller that prefers to chain.
 */
function myapi_service_offer_apply_values($node, array $values) {
  // The two the body can never omit.
  $node->field_offer_message[LANGUAGE_NONE][0]['value'] = $values['message'];
  $node->field_offer_amount_type[LANGUAGE_NONE][0]['value'] = $values['amount_type'];

  // The eight optional ones: written when declared, emptied when absent.
  $optional = [
    'field_offer_amount'         => $values['amount'],
    'field_offer_tax_included'   => $values['tax_included'] === NULL ? NULL : ($values['tax_included'] ? 1 : 0),
    'field_offer_valid_until'    => $values['valid_until'],
    'field_offer_available_from' => $values['available_from'],
    'field_offer_duration'       => $values['duration'],
    'field_offer_duration_unit'  => $values['duration'] === NULL ? NULL : $values['duration_unit'],
    'field_offer_includes'       => $values['includes'],
    'field_offer_excludes'       => $values['excludes'],
    'field_offer_warranty_days'  => $values['warranty_days'],
  ];

  foreach ($optional as $field => $value) {
    if ($value !== NULL) {
      $node->{$field}[LANGUAGE_NONE][0]['value'] = $value;
    }
    elseif (property_exists($node, $field)) {
      // The field existed and the body left it out: the provider deleted it.
      $node->{$field} = [];
    }
  }

  // Always written — see the docblock.
  $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value'] = $values['requires_visit'] ? 1 : 0;

  return $node;
}


/* ---------------------------------------------------------------------------
 * Writing on an offer that already exists (SPEC 105).
 *
 * PUT /api/v1/service-offers/% and PUT /api/v1/service-offers/%/withdraw ask
 * the SAME four questions about who may touch an offer, and the edit asks one
 * more. That "one more" is added from OUTSIDE, by the second function calling
 * the first — a single function with an $is_edit inside would be two functions
 * badly glued, and the flag would grow the day a third verb arrives.
 *
 * NEITHER OF THEM IS myapi_service_offer_eligibility(). That gate answers "may
 * this account bid on this request?" and asks two things this pair must not:
 * whether the provider serves the category — already asked the day the offer
 * was born, and asking again would lock a company out of correcting a price
 * only because it stopped serving that category (decision 12) — and whether the
 * request is free of an awarded offer. Reordering that function with a flag was
 * the alternative; writing these two short ones was the decision.
 * ------------------------------------------------------------------------ */

/**
 * The four conditions editing and withdrawing an offer SHARE (SPEC 105).
 *
 * Conditions 1 to 3 of the spec's gate — the id, the token and the 'proveedor'
 * role — are the dispatcher's, and are answered before this is ever called.
 * What is left is everything that depends on the two rows:
 *
 *   4. not_found                        No such offer.
 *   5. service_offer_provider_not_owned The offer is somebody else's.
 *   6. $not_writable_code               The offer is no longer 'sent'.
 *   7. service_request_not_offerable    Its request is not taking offers.
 *
 * CONDITION 5 READS `provider_raw` AND NEVER `provider_id`, the same split
 * myapi_service_offer_detail_row() documents on the two columns: the joined one
 * is what gets painted and the raw one is what decides. Gating on the joined
 * column would answer 403 to an account FOR ITS OWN OFFER the day its provider
 * node is unpublished — and leaving a wrong offer alive because somebody
 * suspended your listing is exactly the damage this spec exists to undo.
 *
 * CONDITION 5 IS field_provider AND NEVER node.uid (decision 4). It is the
 * criterion SPEC 89 already fixed for the resident: the domain field rules, not
 * the author. A company with two employees cannot be left with an offer frozen
 * because the one who sent it is on holiday.
 *
 * CONDITION 6 IS WHY THIS FUNCTION TAKES A CODE. Only 'sent' may be written on:
 * 'selected' means the resident already chose that price and changing it behind
 * their back is the worst thing this endpoint could do, while 'rejected' and
 * 'withdrawn' are dead and have nothing left to correct (decision 10). The two
 * verbs say so with different words — "can no longer be edited" and "can no
 * longer be withdrawn" — so the code travels as DATA from the call site. It is
 * not a mode: nothing in here branches on it.
 *
 * WITHDRAWING TWICE THEREFORE ANSWERS 409 AND NOT 200, on purpose and by the
 * literal precedent of SPEC 95's cancellation: the second call did nothing, and
 * a 200 would pretend otherwise.
 *
 * A 'cancelled' REQUEST NEEDS NO RULE OF ITS OWN. Cancelling ran
 * myapi_service_offer_reject_live() (SPEC 95), which left this offer
 * 'rejected', so condition 6 answers before condition 7 has anything to say.
 * Condition 7 is only ever reached on statuses that today none but the back
 * office writes, and it fails closed: a status nobody can name is not offerable.
 *
 * PURE, like every other gate in this file: it reads the two rows it is handed
 * and asks nothing of the database, so the whole matrix is assertable with no
 * site booted.
 *
 * @param object|FALSE $row       A row of myapi_service_offer_detail_row(), or
 *                                FALSE when there is no such offer.
 * @param array $provider_ids     myapi_provider_role_provider_ids()'s answer
 *                                for the authenticated account.
 * @param object|FALSE $request_row  A row of myapi_service_request_detail_row()
 *                                for $row->request_id, or FALSE.
 * @param string $not_writable_code  The error_code condition 6 answers with:
 *                                'service_offer_not_editable' for the PUT,
 *                                'service_offer_not_withdrawable' for the
 *                                withdrawal.
 *
 * @return string|NULL  The error_code of the first condition that fails, or
 *                      NULL when all four pass.
 */
function myapi_service_offer_write_gate($row, array $provider_ids, $request_row, $not_writable_code) {
  // 4. Does the offer exist? The detail row already answers "no such offer"
  //    for the four indistinguishable reasons — missing, unpublished, another
  //    bundle, or its request unpublished — so there is nothing to add here.
  if (!$row) {
    return 'not_found';
  }

  // 5. Mine? The RAW column, and field_provider and not node.uid — see the
  //    docblock. An offer with no provider at all is nobody's and fails closed.
  $provider_raw = isset($row->provider_raw) ? (int) $row->provider_raw : 0;
  $owned = array_map('intval', $provider_ids);

  if ($provider_raw <= 0 || !in_array($provider_raw, $owned, TRUE)) {
    return 'service_offer_provider_not_owned';
  }

  // 6. Still 'sent'? The code is the caller's, the rule is not.
  $status = isset($row->status) ? $row->status : NULL;

  if ($status !== MYAPI_SERVICES_OFFER_STATUS_SENT) {
    return $not_writable_code;
  }

  // 7. Is its request still taking offers? A missing row fails closed, exactly
  //    as an unnameable status does.
  $request_status = ($request_row && isset($request_row->status)) ? $request_row->status : NULL;

  $writable_on = [
    MYAPI_SERVICES_REQUEST_STATUS_OPEN,
    MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
    MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
  ];

  if (!in_array($request_status, $writable_on, TRUE)) {
    return 'service_request_not_offerable';
  }

  return NULL;
}

/**
 * The shared four PLUS the licence: the gate of the EDIT alone (SPEC 105).
 *
 * Condition 8, and it belongs to the PUT only:
 *
 *   8. service_offer_provider_not_active  The provider may not operate today.
 *
 * WHY EDITING IS BLOCKED BY A LAPSED LICENCE AND WITHDRAWING IS NOT
 * (decision 9). Editing is sending a NEW quote, and whoever may not operate
 * does not quote: it is condition 2 of SPEC 100, unchanged. Withdrawing commits
 * to nothing, and forcing a provider to leave a wrong offer alive because their
 * licence expired is precisely the damage this spec came to repair.
 *
 * IT COMES AFTER THE STATE OF THE REQUEST, AND THAT IS DELIBERATE. Conditions 5
 * to 7 ask "may anything be written on this offer at all?", and 8 asks "may you
 * send a new quote?". The second only means anything once the first has passed,
 * so a 409 over a closed request wins over the 403 of a lapsed licence.
 *
 * THE RULE ITSELF IS NOT REWRITTEN HERE. myapi_services_provider_is_active()
 * owns what "active" means and reads both columns; reading `status` and
 * `license_expiry` in this file would be a second copy of it.
 *
 * @param object|FALSE $row          A row of myapi_service_offer_detail_row().
 * @param array $provider_ids        myapi_provider_role_provider_ids()'s answer.
 * @param object|FALSE $request_row  A row of myapi_service_request_detail_row().
 * @param object|FALSE $provider_row A row of myapi_service_offer_provider_row()
 *                                   for the offer's provider, or FALSE.
 * @param mixed $now                 The clock, as a Unix timestamp
 *                                   (REQUEST_TIME in production).
 *
 * @return string|NULL  The error_code of the first condition that fails, or
 *                      NULL when all five pass.
 */
function myapi_service_offer_update_gate($row, array $provider_ids, $request_row, $provider_row, $now) {
  $shared = myapi_service_offer_write_gate($row, $provider_ids, $request_row, 'service_offer_not_editable');

  if ($shared !== NULL) {
    return $shared;
  }

  // 8. May this provider operate today? A provider row that no longer loads is
  //    not active either, and fails closed like every other read of this gate.
  if (!$provider_row) {
    return 'service_offer_provider_not_active';
  }

  $license_expiry = isset($provider_row->license_expiry) ? $provider_row->license_expiry : NULL;
  $status = isset($provider_row->status) ? (int) $provider_row->status : 0;

  if (!myapi_services_provider_is_active($status, $license_expiry, $now)) {
    return 'service_offer_provider_not_active';
  }

  return NULL;
}

/**
 * Rejects every live offer of a request, optionally sparing one (SPECS 95, 106).
 *
 * A request that stops being in play must not leave providers working on it,
 * and that is the whole reason this sweep exists. 'sent' and 'selected' are the
 * two statuses that mean "still in play", so those are the two this rewrites to
 * 'rejected'. 'withdrawn' and 'rejected' are left alone: the first is the
 * provider's own retreat and overwriting it would erase who walked away by
 * themselves, and the second is already terminal.
 *
 * TWO CALLERS, ONE CRITERION. It was born in SPEC 95 as
 * myapi_service_request_reject_live_offers(), inside
 * resources/service_request.resource.inc, for the cancellation. SPEC 106 needs
 * the very same sweep for the award — the losing offers of an assigned request
 * are as dead as those of a cancelled one — and Rule 5 of CLAUDE.md forbids
 * resources/service_offer.resource.inc reaching into another resource for it.
 * So it moved here, where both reach it as an include. IT CHANGED NAME ON THE
 * WAY: a myapi_service_request_* function living inside the offers' include
 * would lie about where it is and about what it talks about, and offers are
 * what it talks about. Nothing else about it changed — the cancellation calls
 * it with one argument and gets exactly what it always got.
 *
 * $except_nid IS WHAT THE AWARD ADDS, and it is filtered in PHP AFTER the query
 * and not as a condition inside it. Cancelling spares nobody and awarding
 * spares exactly one, so the difference is one nid out of a list that is small
 * and bounded — pushing it into the db_select() would mean a conditional
 * condition on a query that today has none, for a saving of one row.
 *
 * SPARING THE WINNER IS OBLIGATORY, NOT AN OPTIMISATION. The award writes
 * 'selected' onto the winning offer, and 'selected' is one of the two statuses
 * this sweep considers live: without the exception the very next line would
 * rewrite the winner to 'rejected' and the request would end up assigned to a
 * rejected offer.
 *
 * `no.type = MYAPI_SERVICES_OFFER_TYPE` IS NOT DECORATION. field_request is
 * SHARED with 'service_transaction' (SPEC 77), so without the bundle condition
 * this would pick up the request's own timeline entries and try to write an
 * offer status onto them. The INNER JOIN to field_data_field_offer_status is
 * deliberate too: an offer with no status row has nothing to rewrite.
 *
 * WHY node_load()/node_save() PER OFFER AND NOT ONE db_update(). A direct
 * UPDATE on field_data_field_offer_status would be one query instead of N, and
 * it would leave the revision table and the entity cache lying. The number of
 * offers a request can hold is small and bounded, so the correct write wins
 * over the cheap one.
 *
 * NOT ATOMIC, ON PURPOSE. Both callers write the request's status FIRST, so a
 * failure halfway through this loop leaves live offers pointing at a request
 * that is already 'cancelled' or already 'assigned' — inconsistent but
 * harmless, because neither status can award anything from the graph. No
 * explicit database transaction is opened: node_save() with the Field API and
 * its hooks inside one is a known source of lock-ups in Drupal 7.
 *
 * @param mixed $request_nid  The request whose offers are swept.
 * @param mixed $except_nid   The offer to spare — the winner, for the award —
 *                            or NULL to spare none, which is the cancellation.
 *                            A nid that is not an offer of this request spares
 *                            nothing, because it simply never appears in the
 *                            list the query answers.
 *
 * @return int  How many offers actually changed status. 0 when the request nid
 *              is not a positive integer and 0 when there was nothing live.
 */
function myapi_service_offer_reject_live($request_nid, $except_nid = NULL) {
  if (!is_scalar($request_nid) || !ctype_digit((string) $request_nid) || (int) $request_nid <= 0) {
    return 0;
  }
  $request_nid = (int) $request_nid;

  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);
  $query->condition('fq.field_request_target_id', $request_nid);

  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);

  $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  // The two live statuses, off the constants of services_common (SPEC 100) and
  // not the literals this line carried since SPEC 95: it is the same value
  // written where the offer's lifecycle is defined, so the day one of the two
  // keys changes, this sweep and the uniqueness gate of the offer endpoint move
  // together instead of one of them being forgotten.
  $query->condition('fos.field_offer_status_value', [
    MYAPI_SERVICES_OFFER_STATUS_SENT,
    MYAPI_SERVICES_OFFER_STATUS_SELECTED,
  ], 'IN');

  $query->addField('no', 'nid', 'nid');

  $offer_nids = $query->execute()->fetchCol();

  // The exception, after the query — see the docblock. NULL spares nobody, and
  // the comparison is on integers so a nid arriving as a string still matches.
  if ($except_nid !== NULL) {
    $except_nid = (int) $except_nid;
    $offer_nids = array_filter($offer_nids, function ($offer_nid) use ($except_nid) {
      return (int) $offer_nid !== $except_nid;
    });
  }

  $rejected = 0;
  foreach ($offer_nids as $offer_nid) {
    $offer = node_load($offer_nid);
    if (!$offer) {
      continue;
    }

    $offer->field_offer_status[LANGUAGE_NONE][0]['value'] = 'rejected';
    node_save($offer);
    $rejected++;
  }

  return $rejected;
}

/**
 * Lists the 'sent' offers of a request that a win is about to leave behind
 * (SPEC 112).
 *
 * Read-only, and read BEFORE myapi_service_offer_reject_live() sweeps them to
 * 'rejected': this is the only moment the two groups — who is about to lose,
 * and who was already terminal — are still distinguishable by status alone.
 * After the sweep, a 'rejected' offer could mean either, and there is no way
 * back to which one.
 *
 * NOT a variant of myapi_service_offer_reject_live(): that function returns an
 * int, considers 'selected' live too (because it runs AFTER the winner is
 * marked so, and must still be told to spare it by nid), and is reused as-is
 * by cancel (SPEC 95), which never needs the list. Widening its contract for
 * a second caller that does not want the same shape would make the one caller
 * that matters — cancel — carry a result it never reads. A separate,
 * read-only query changes nothing this function's one existing caller relies
 * on.
 *
 * LEFT JOIN on the provider, same reason as myapi_service_offer_provider_row():
 * an unpublished or deleted provider must not make the loser's own row
 * disappear from the result, only its name — which the caller degrades to
 * MYAPI_SERVICE_REQUEST_MAIL_EMPTY like every other unresolved label in this
 * domain.
 *
 * @param mixed $request_nid  The request being awarded.
 * @param mixed $except_nid   The winning offer, excluded from the result even
 *                            though it cannot be 'sent' by the time this runs
 *                            unassisted — kept for symmetry with the caller's
 *                            own nid and as a defensive floor.
 *
 * @return array  List of (object) ['nid' => int, 'provider_raw' => int|NULL,
 *   'provider_name' => string|NULL], one per live-losing offer. Empty when the
 *   request had no other 'sent' offer.
 */
function myapi_service_offer_sent_offers_for_request($request_nid, $except_nid = NULL) {
  if (!is_scalar($request_nid) || !ctype_digit((string) $request_nid) || (int) $request_nid <= 0) {
    return [];
  }
  $request_nid = (int) $request_nid;

  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);
  $query->condition('fq.field_request_target_id', $request_nid);

  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);

  $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->condition('fos.field_offer_status_value', MYAPI_SERVICES_OFFER_STATUS_SENT);

  $query->innerJoin('field_data_field_provider', 'fp', "fp.entity_id = no.nid AND fp.entity_type = 'node' AND fp.deleted = 0");
  $query->leftJoin('node', 'pn', 'pn.nid = fp.field_provider_target_id');

  if ($except_nid !== NULL) {
    $query->condition('no.nid', (int) $except_nid, '<>');
  }

  $query->addField('no', 'nid', 'nid');
  $query->addField('fp', 'field_provider_target_id', 'provider_raw');
  $query->addField('pn', 'title', 'provider_name');

  return $query->execute()->fetchAll();
}

/**
 * Lists the LIVE offers of a request a cancellation is about to reject
 * (SPEC 113).
 *
 * Read-only, and read BEFORE myapi_service_offer_reject_live() sweeps them to
 * 'rejected': the same reasoning as
 * myapi_service_offer_sent_offers_for_request() (SPEC 112) — after the sweep,
 * an offer that was already 'rejected'/'withdrawn' and one that just became
 * 'rejected' are indistinguishable by status alone.
 *
 * NOT a variant of either existing function. Unlike
 * myapi_service_offer_reject_live(), this returns a list, not a count.
 * Unlike myapi_service_offer_sent_offers_for_request(), it includes
 * 'selected' — a cancellation has no winner to exclude, so BOTH statuses that
 * function's sibling considers live are notified, with no exception by nid.
 * Widening either of those two contracts for a caller neither one needs would
 * make cancel's own reader carry a shape or a filter it never uses.
 *
 * LEFT JOIN on the provider, same reason as
 * myapi_service_offer_sent_offers_for_request(): an unpublished or deleted
 * provider must not make the affected offer's own row disappear from the
 * result, only its name — which the caller degrades to
 * MYAPI_SERVICE_REQUEST_MAIL_EMPTY like every other unresolved label in this
 * domain.
 *
 * @param mixed $request_nid  The request being cancelled.
 *
 * @return array  List of (object) ['nid' => int, 'provider_raw' => int|NULL,
 *   'provider_name' => string|NULL], one per live offer ('sent' or
 *   'selected'). Empty when the request had no live offer.
 */
function myapi_service_offer_live_offers_for_request($request_nid) {
  if (!is_scalar($request_nid) || !ctype_digit((string) $request_nid) || (int) $request_nid <= 0) {
    return [];
  }
  $request_nid = (int) $request_nid;

  $query = db_select('field_data_field_request', 'fq');
  $query->condition('fq.entity_type', 'node');
  $query->condition('fq.deleted', 0);
  $query->condition('fq.field_request_target_id', $request_nid);

  $query->innerJoin('node', 'no', 'no.nid = fq.entity_id');
  $query->condition('no.type', MYAPI_SERVICES_OFFER_TYPE);
  $query->condition('no.status', 1);

  $query->innerJoin('field_data_field_offer_status', 'fos', "fos.entity_id = no.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->condition('fos.field_offer_status_value', [
    MYAPI_SERVICES_OFFER_STATUS_SENT,
    MYAPI_SERVICES_OFFER_STATUS_SELECTED,
  ], 'IN');

  $query->innerJoin('field_data_field_provider', 'fp', "fp.entity_id = no.nid AND fp.entity_type = 'node' AND fp.deleted = 0");
  $query->leftJoin('node', 'pn', 'pn.nid = fp.field_provider_target_id');

  $query->addField('no', 'nid', 'nid');
  $query->addField('fp', 'field_provider_target_id', 'provider_raw');
  $query->addField('pn', 'title', 'provider_name');

  return $query->execute()->fetchAll();
}

/**
 * The four conditions of the AWARD that need no query (SPEC 106).
 *
 * Conditions 6 to 9 of PUT /api/v1/service-offers/{id}/accept:
 *
 *   6. service_offer_not_acceptable      This offer is not 'sent' any more.
 *   7. service_request_not_assignable    Its request cannot be awarded from
 *                                        the status it is in.
 *   8. service_offer_expired             The provider's quote has lapsed.
 *   9. service_offer_provider_not_active The provider may not operate today.
 *
 * The first five are the RESOURCE'S: the nid, the token, the two rows existing
 * and the reader being the requester. They cost queries and HTTP output, and
 * this function does neither — the caller has already paid for the three rows
 * and decides what to answer with the code this returns. That split is what
 * puts the whole matrix inside tests/unit with no site booted, the same shape
 * as myapi_service_offer_eligibility() and the two gates of SPEC 105.
 *
 * THE GRAPH IS ASKED, NOT TRANSCRIBED. Condition 7 does not write 'offered'
 * anywhere: it asks myapi_services_transition_allowed(), which has owned the
 * graph since SPEC 77. Copying the list of awardable statuses here would be a
 * second truth, drifting the day somebody adds a status. And because that
 * function answers FALSE for an unknown or empty value BY DESIGN, a corrupt
 * field_request_status is a 409 and never a 500 — which is also why a request
 * row that did not load fails closed through the same line.
 *
 * A CONSEQUENCE OF ASKING THE GRAPH, and it is known and accepted: a request
 * sitting in 'open' with offers hanging off it answers 409. Nothing syncs
 * 'open' -> 'offered' when an offer is created from the back office (the hole
 * SPEC 100 left, documented in myapi_service_request_update_gate()), and
 * 'open' -> 'assigned' is not an edge of the graph. SPEC 106 does not invent
 * that edge and does not fix that hook — decision 3.
 *
 * valid_until ABSENT MEANS "DOES NOT EXPIRE". It is optional since SPEC 100 and
 * most offers do not carry one; reading an empty column as "lapsed" would block
 * almost the whole catalogue. The comparison is >=, exactly like
 * myapi_services_provider_is_active(): the quote is good THROUGHOUT its expiry
 * instant and not one second less. A non-numeric value is read as lapsed rather
 * than as absent, the same fail-closed reading that function gives a corrupt
 * licence — a deadline nobody can parse is not a deadline that has been met.
 *
 * WHY THE AWARD VALIDATES valid_until WHEN SPECS 100 AND 105 DID NOT
 * (decision 7). Here the date means something it did not mean for the other two
 * verbs: it is the provider saying "this price holds until then", and awarding
 * work at a price they already declared lapsed commits them to something they
 * did not offer. What this does NOT do is sweep expired offers — they keep
 * saying 'sent' everywhere, and the 409 is the last line of defence and not the
 * first.
 *
 * THE LICENCE IS LAST, AND THAT IS DELIBERATE (decision 8). Conditions 6, 7 and
 * 8 ask "is this offer awardable?" and 9 asks "may this provider still work?".
 * The second only means anything once the first has passed, so a rejected offer
 * from an unpublished provider answers 409 and not 403 — the same order SPEC
 * 105 fixed for editing. The rule itself is not rewritten here either:
 * myapi_services_provider_is_active() owns what "active" means and reads both
 * columns.
 *
 * @param object|FALSE $row
 *   A row of myapi_service_offer_detail_row() (SPEC 103) for the offer being
 *   awarded. Only `status` is read.
 * @param object|FALSE $request_row
 *   A row of myapi_service_request_detail_row() (SPEC 89) for its request. Only
 *   `status` is read — `requester_uid` is condition 5 and belongs to the
 *   caller.
 * @param object|FALSE $provider_row
 *   A row of myapi_service_offer_provider_row() (SPEC 100) for the offer's
 *   provider, read off `provider_raw` and never off `provider_id` — the joined
 *   column carries status = 1 inside it, so an unpublished provider would
 *   arrive as NULL and slip under condition 9 instead of failing it. FALSE
 *   fails closed.
 * @param mixed $now
 *   The clock, as a Unix timestamp (REQUEST_TIME in production). Explicit so
 *   the matrix does not depend on the wall clock.
 *
 * @return string|NULL
 *   The error_code of the first condition that fails, or NULL when all four
 *   pass.
 */
function myapi_service_offer_accept_gate($row, $request_row, $provider_row, $now) {
  // 6. Still 'sent'? 'selected' says it was already awarded, 'rejected' that it
  //    lost, 'withdrawn' that the provider took it back — none of the three is
  //    awardable, and a row that did not load has no status and fails closed
  //    through the same comparison.
  $status = ($row && isset($row->status)) ? $row->status : NULL;

  if ($status !== MYAPI_SERVICES_OFFER_STATUS_SENT) {
    return 'service_offer_not_acceptable';
  }

  // 7. May its request be awarded from where it stands? Asked of the graph —
  //    see the docblock. A missing row, an empty status and a status that is
  //    not in the catalogue all answer FALSE here, which is a 409 and never an
  //    exception.
  $request_status = ($request_row && isset($request_row->status)) ? $request_row->status : NULL;

  if (!myapi_services_transition_allowed($request_status, MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED)) {
    return 'service_request_not_assignable';
  }

  // 8. Has the quote lapsed? Absent means it does not expire; the column
  //    carries a Unix timestamp, so this costs an integer comparison and not a
  //    strtotime().
  $valid_until = ($row && isset($row->valid_until)) ? $row->valid_until : NULL;

  if ($valid_until !== NULL && $valid_until !== '') {
    if (!is_numeric($valid_until) || (int) $valid_until < (int) $now) {
      return 'service_offer_expired';
    }
  }

  // 9. May this provider operate today? A provider row that no longer loads is
  //    not active either, and fails closed like every other read of this gate.
  if (!$provider_row) {
    return 'service_offer_provider_not_active';
  }

  $license_expiry = isset($provider_row->license_expiry) ? $provider_row->license_expiry : NULL;
  $provider_status = isset($provider_row->status) ? (int) $provider_row->status : 0;

  if (!myapi_services_provider_is_active($provider_status, $license_expiry, $now)) {
    return 'service_offer_provider_not_active';
  }

  return NULL;
}
