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
