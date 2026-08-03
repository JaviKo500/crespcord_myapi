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
