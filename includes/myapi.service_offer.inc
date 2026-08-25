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
 * `message` travels AS STORED, with the line breaks the provider typed, exactly
 * like `description` in the listing item. The instance pins the 'plain_text'
 * format (SPEC 77); the note in docs/service-request.md says what depends on
 * that.
 *
 * THE NINE NEW KEYS, and the two rules they all obey:
 *
 *   A MISSING OPTIONAL TEXT IS null AND NEVER "". `message` is the exception
 *   and stays "" when empty, because it is REQUIRED: an empty message is a
 *   corrupt row, not an absence, and the two must not read alike.
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
      'name' => isset($row->provider_name) ? $row->provider_name : NULL,
      'logo' => isset($row->provider_logo_uri) && $row->provider_logo_uri !== ''
        ? file_create_url($row->provider_logo_uri)
        : NULL,
    ];
  }

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
    'message'        => isset($row->message) ? $row->message : '',
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
    'includes'       => isset($row->includes) && $row->includes !== ''
      ? $row->includes
      : NULL,
    'excludes'       => isset($row->excludes) && $row->excludes !== ''
      ? $row->excludes
      : NULL,
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
 *                            depends on both rows at once.
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
 * A 'direct' request falls out of condition 5 with no rule of its own: it is
 * not one of the two biddable statuses AND it is born with
 * field_assigned_provider filled (SPEC 87). A 'direct' awarded TO ME falls the
 * same way, and that is right — a request that is already mine is not bid on.
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
 *                      NULL when all six pass.
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
  $biddable = [
    MYAPI_SERVICES_REQUEST_STATUS_OPEN,
    MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
  ];

  $status = isset($request_row->status) ? $request_row->status : NULL;

  if (!in_array($status, $biddable, TRUE)) {
    return 'service_request_not_offerable';
  }

  if (!empty($request_row->assigned_offer_raw) || !empty($request_row->assigned_provider_raw)) {
    return 'service_request_not_offerable';
  }

  // 6. My category. An empty category on either side matches nothing, which is
  //    the honest answer and needs no code of its own.
  $category_id = isset($request_row->category_id) ? (int) $request_row->category_id : 0;
  $categories = isset($provider_row->category_ids) ? $provider_row->category_ids : [];

  if (!$category_id || !in_array($category_id, array_map('intval', $categories), TRUE)) {
    return 'service_offer_category_mismatch';
  }

  return NULL;
}
