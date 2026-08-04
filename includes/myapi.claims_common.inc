<?php

/**
 * @file
 * Validators shared by every consumer of the claims data model (SPEC 64).
 *
 * The two whitelists here — the four claim statuses and the two claim types —
 * are read by two different consumers: the back-office listing
 * (includes/myapi.claims_admin.inc, admin/content/claims) and the read-only
 * API (resources/claim.resource.inc, GET /api/v1/claims). Both must accept
 * exactly the same set of values, so a '?status=duplicated' left in a bookmark
 * falls back to "no filter" through the same line of code in either place, and
 * the next change to the catalogue has one file to touch instead of two —
 * Regla 3 de CLAUDE.md.
 *
 * They were born in includes/myapi.claims_admin.inc (SPEC 56, narrowed by
 * SPEC 62) and moved here unchanged when the API needed them. The move was
 * the reason for this file: the alternative — a resource doing
 * module_load_include() of the back-office include — would load 415 lines of
 * form builders and page callbacks on every API request, and would invert the
 * dependency (resource → back office). This file is deliberately neutral: no
 * form builders, no page callbacks, no Field API, no database. Only pure
 * functions, which is also what makes them testable from tests/unit.
 *
 * The whitelists are hard-coded rather than read from field_info_field(): see
 * myapi_claims_valid_status() below, and the decision recorded in SPEC 56.
 */

/**
 * Normalises a raw GET value into one of the four allowed statuses, or NULL.
 *
 * The whitelist is hard-coded rather than read from field_info_field(): the
 * four stored values are part of the data model this spec was written
 * against, and validation must not silently change if a site administrator
 * edits the field's allowed values from the UI. Labels, which carry no such
 * risk, ARE read from the field (see myapi_claims_status_options()).
 *
 * 'duplicated' was the fifth until SPEC 62 dropped it from the catalogue; a
 * bookmarked '?status=duplicated' now falls back to "no filter", like any
 * other unknown value.
 *
 * @param mixed $value  Raw $_GET value.
 *
 * @return string|null
 */
function myapi_claims_valid_status($value) {
  $allowed = array('received', 'in_progress', 'resolved', 'closed');
  return (is_scalar($value) && in_array((string) $value, $allowed, TRUE)) ? (string) $value : NULL;
}

/**
 * Normalises a raw GET value into a LIST of allowed statuses, or NULL.
 *
 * The multi-value form of the filter (SPEC 69): '?status=received,in_progress'
 * means "any of these", where '?status=received' means "exactly this one". A
 * single value is therefore just a list of one, which is why the API can call
 * this function for every request and the single-value contract keeps working
 * unchanged.
 *
 * The whitelist is NOT repeated here: each item goes through
 * myapi_claims_valid_status() above, so the catalogue still lives in exactly
 * one place and the next change to it has one line to touch.
 *
 * Parsing stays as lax as everywhere else in the query string, item by item:
 * unknown values are dropped and the valid ones still filter
 * ('?status=received,inventado' filters by 'received'). Only when NOTHING
 * survives does the whole filter fall back to NULL, "every status" — the same
 * answer '?status=inventado' has given since SPEC 62. Duplicates collapse
 * ('?status=received,received' is one condition), and empty items from a
 * trailing or doubled comma are ignored.
 *
 * @param mixed $value  Raw $_GET value. A comma-separated string; anything
 *                      non-scalar (e.g. '?status[]=received', which PHP hands
 *                      over as an array) is NULL, like any other unparsable
 *                      value.
 *
 * @return array|null  Non-empty list of distinct valid statuses, or NULL for
 *                     "no filter".
 */
function myapi_claims_valid_status_list($value) {
  if (!is_scalar($value)) {
    return NULL;
  }

  $statuses = array();
  foreach (explode(',', (string) $value) as $item) {
    $status = myapi_claims_valid_status(trim($item));
    if ($status !== NULL && !in_array($status, $statuses, TRUE)) {
      $statuses[] = $status;
    }
  }

  return $statuses ? $statuses : NULL;
}

/**
 * Normalises a raw GET value into one of the two allowed claim types, or
 * NULL. Same reasoning as myapi_claims_valid_status().
 *
 * @param mixed $value  Raw $_GET value.
 *
 * @return string|null
 */
function myapi_claims_valid_claim_type($value) {
  $allowed = array('requirement', 'claim');
  return (is_scalar($value) && in_array((string) $value, $allowed, TRUE)) ? (string) $value : NULL;
}
