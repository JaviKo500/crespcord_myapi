<?php

/**
 * @file
 * Request helpers for the myapi module.
 */

/**
 * Reads and decodes the JSON request body.
 *
 * Result is cached in a static variable because php://input can only be
 * read once per request.
 *
 * @return array|null  Decoded body as associative array, or NULL on failure.
 */
function myapi_request_body() {
  static $body;
  if (!isset($body)) {
    $raw = file_get_contents('php://input');
    $body = !empty($raw) ? json_decode($raw, TRUE) : NULL;
  }
  return $body;
}

/**
 * Returns the current HTTP method in uppercase.
 *
 * @return string  e.g. 'GET', 'POST', 'PUT', 'DELETE'.
 */
function myapi_request_method() {
  return strtoupper($_SERVER['REQUEST_METHOD']);
}

/**
 * Reads a single scalar field from a multipart/form-data request ($_POST).
 *
 * Used by resources that accept multipart/form-data (file uploads) instead of a
 * JSON body, so they never touch $_POST directly. In multipart requests the
 * fields arrive in $_POST, not through php://input, so myapi_request_body()
 * does not see them.
 *
 * @param string $name  The form-data field name.
 *
 * @return string|null  The trimmed value when it is a scalar string,
 *                       NULL when absent or not a scalar (e.g. an array).
 */
function myapi_request_post_field($name) {
  if (!isset($_POST[$name]) || !is_string($_POST[$name])) {
    return NULL;
  }
  return trim($_POST[$name]);
}

/**
 * Reads a repeated field from a multipart/form-data request ($_POST).
 *
 * Sibling of myapi_request_post_field() for fields sent as an array (the
 * "name[]" form), which that function returns NULL for on purpose. Keeps
 * resources away from $_POST when they need a repeated field such as a list of
 * file ids to remove.
 *
 * It does not validate types or contents beyond discarding what cannot be a
 * value at all: casting to int, checking ranges or matching against a
 * catalogue is the resource's job.
 *
 * @param string $name  The form-data field name, without the "[]" suffix.
 *
 * @return array  List of trimmed non-empty scalar values, reindexed from 0.
 *                Empty array when the field is absent or is not an array.
 */
function myapi_request_post_field_array($name) {
  if (!isset($_POST[$name]) || !is_array($_POST[$name])) {
    return [];
  }

  $values = [];
  foreach ($_POST[$name] as $value) {
    if (!is_scalar($value)) {
      continue;
    }
    $value = trim((string) $value);
    if ($value !== '') {
      $values[] = $value;
    }
  }

  return $values;
}

/**
 * Validates that all required fields are present in the request body.
 *
 * Calls myapi_error() with HTTP 422 and exits if any field is missing.
 *
 * @param array $body    Decoded request body.
 * @param array $fields  List of required field names.
 */
function myapi_request_require_fields(array $body, array $fields) {
  foreach ($fields as $field) {
    if (!isset($body[$field])) {
      myapi_error('missing_field', 422, ['@field' => $field]);
    }
  }
}

/**
 * Validates that the given fields are present and non-empty strings.
 *
 * Rejects arrays, objects, numbers, booleans and whitespace-only values so
 * resources never pass unexpected types into downstream APIs. Calls
 * myapi_error() with HTTP 422 and exits on the first invalid field.
 *
 * @param array $body        Decoded request body.
 * @param array $fields      List of field names that must be non-empty strings.
 * @param int   $max_length  Maximum allowed length per field (default 255).
 * @param int   $min_length  Minimum allowed length per field (default 1).
 */
function myapi_request_require_strings(array $body, array $fields, $max_length = 255, $min_length = 1) {
  foreach ($fields as $field) {
    if (!isset($body[$field]) || !is_string($body[$field]) || trim($body[$field]) === '') {
      myapi_error('invalid_field', 422, ['@field' => $field]);
    }
    if (strlen($body[$field]) < $min_length) {
      myapi_error('field_too_short', 422, ['@field' => $field]);
    }
    if (strlen($body[$field]) > $max_length) {
      myapi_error('field_too_long', 422, ['@field' => $field]);
    }
  }
}

/**
 * Parses the optional date-range filter from the query string.
 *
 * Reads $_GET['date_from'] and $_GET['date_to'], each expected as an ISO
 * 'YYYY-MM-DD' string. A bound is kept only when it matches that format and
 * is a real calendar date (validated with checkdate()); anything else is
 * silently ignored. If both bounds are present but inverted (from > to), the
 * whole filter is dropped so the caller responds as if no range were given.
 * No 422 is ever raised here.
 *
 * Shared by every resource that offers a lax date-range filter (expenses,
 * condominium summary, ...), so the parsing lives in one place.
 *
 * @return array  ['from' => string|NULL, 'to' => string|NULL].
 */
function myapi_parse_date_range_param() {
  $from = myapi_valid_iso_date(isset($_GET['date_from']) ? $_GET['date_from'] : NULL);
  $to = myapi_valid_iso_date(isset($_GET['date_to']) ? $_GET['date_to'] : NULL);

  if ($from !== NULL && $to !== NULL && $from > $to) {
    return ['from' => NULL, 'to' => NULL];
  }

  return ['from' => $from, 'to' => $to];
}

/**
 * Validates a single 'YYYY-MM-DD' date string.
 *
 * The 'D' modifier is not decorative (SPEC 73): without it PCRE lets '$' match
 * just before a trailing newline, so "2026-08-06\n" passed the pattern and was
 * returned WITH the newline still in it — an unvalidated shape reaching the
 * query the caller builds out of the return value. 'D' anchors '$' at the true
 * end of the subject, so that value is now rejected like any other malformed
 * one.
 *
 * @param mixed $value  Raw query-string value, or NULL when absent.
 *
 * @return string|NULL  The value when it is a real ISO calendar date,
 *                       NULL otherwise.
 */
function myapi_valid_iso_date($value) {
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
    return NULL;
  }
  if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
    return NULL;
  }
  return $value;
}

/**
 * The strict parser BOTH '?category_id' and '?unit_id' are made of (SPEC 91).
 *
 * ONE FUNCTION AND NOT TWO COPIES. The two parameters refuse the same three
 * malformations — a non-scalar, a non-digit string, a zero or negative — and
 * answer the same 422 invalid_field naming themselves; what differs between
 * them is only the REASON each one is strict, and that is written in the
 * wrapper that carries its name. Regla 3 de CLAUDE.md applied inside a file
 * rather than between two of them.
 *
 * is_scalar() COMES FIRST, so '?category_id[]=1' answers 422 without ever
 * reaching a string cast of an array — a PHP notice in the middle of a JSON
 * body is not an answer. Same guard, in the same order, as myapi_provider_list().
 *
 * The empty string is a malformation and not an absence: '?unit_id=' is a
 * present-and-broken value, and ctype_digit('') is FALSE, which is what makes
 * it a 422 rather than a silent "no filter".
 *
 * IT LIVES HERE AND NOT IN A RESOURCE (SPEC 102). It was born inside
 * resources/service_request.resource.inc, where three wrappers of that file
 * still call it; the moment a SECOND resource needed the same parser, Regla 5
 * de CLAUDE.md — a resource never calls another resource's internals — left
 * only two options, and copying it would have been the third definition of
 * "a positive integer or 422". Reading an id out of the query string is
 * request parsing, which is exactly what this include is for. The move was
 * verbatim: myapi_service_request_parse_id_param() stays as a one-line
 * delegation so its three wrappers, and the tests that cover them, did not
 * change by a single character.
 *
 * @param string $name
 *   The query-string parameter to read, which is also the '@field' the error
 *   message names. Always a literal at the call site — never a value that
 *   reached this file from the request.
 *
 * @return int|NULL
 *   The positive integer, or NULL when the parameter is absent.
 */
function myapi_parse_id_param($name) {
  if (!isset($_GET[$name])) {
    return NULL;
  }

  $raw = $_GET[$name];
  if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw <= 0) {
    myapi_error('invalid_field', 422, ['@field' => $name]);
  }

  return (int) $raw;
}

/**
 * Tells whether a raw query-string value is a positive integer (SPEC 122).
 *
 * THE is_scalar() COMES FIRST, AND THAT IS THE WHOLE REASON THIS EXISTS.
 * '?page[]=1' hands $_GET['page'] an ARRAY, and casting an array to a string
 * raises "Array to string conversion" — a notice on the PHP 7.4 production runs
 * — before answering the literal 'Array'. The answer was always right (that is
 * not all digits, so the caller fell back to its default) but the notice was
 * emitted in the middle of the request, and on a site with display_errors on it
 * lands INSIDE the JSON body, where it costs the app the whole response.
 *
 * Thirteen resources wrote that test by hand and twelve of them wrote it
 * without the guard; the thirteenth (service_category, SPEC 118) wrote it with,
 * which is what made the divergence visible in the first place. This is the
 * shape that one got right, pulled out so nobody writes the fourteenth.
 *
 * @param mixed $value  Raw query-string value.
 *
 * @return bool  TRUE for a scalar whose digits spell a number >= 1.
 */
function myapi_is_positive_int_param($value) {
  return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0;
}

/**
 * Reads the 1-based '?page' of a paginated listing (SPEC 122).
 *
 * LAX BY CONTRACT: every value that is not a positive integer — absent, zero,
 * negative, a float, a word, an array — falls back to page 1 silently, never a
 * 422. That is the criterion every listing of this module has always applied,
 * and the one documented in docs/*.md; what changed with SPEC 122 is only that
 * the array case no longer emits a notice on its way to the same answer.
 *
 * @return int  The requested page, or 1.
 */
function myapi_parse_page_param() {
  return isset($_GET['page']) && myapi_is_positive_int_param($_GET['page'])
    ? (int) $_GET['page']
    : 1;
}

/**
 * Reads the '?limit' of a paginated listing, clamped (SPEC 122).
 *
 * Same lax contract as the page above: anything that is not a positive integer
 * falls back to the default, and anything above the cap is capped, both without
 * a 422.
 *
 * THE '-1' SENTINEL IS A PARAMETER AND NOT A DEFAULT, because it is not
 * universal: SPEC 15 gave it to the twin listings (receipts, extra fees,
 * payments) and later specs to the notification inbox, the reservations, the
 * areas, the claims, the expenses and the marketplace — but NOT to the bulletin
 * or provider listings, which cap at 50 and always paginate. Passing the flag
 * at the call site keeps each endpoint answering exactly what it answered
 * before, instead of quietly gaining an unpaginated mode nobody specified.
 *
 * The sentinel is matched with a STRICT comparison against the string '-1',
 * which is what $_GET carries; an array never equals a string, so that branch
 * needs no guard of its own.
 *
 * The clamp is written as min() alone: the caller already required a value >= 1
 * to get here, so the max(1, ...) the twelve hand-written copies carried could
 * never fire. Same answer, one operation less, and no reader wondering which
 * case needed it.
 *
 * @param bool $allow_unlimited
 *   TRUE when this listing honours '?limit=-1' as "every row, unpaginated".
 * @param int  $default
 *   Rows per page when nothing usable was asked for.
 * @param int  $max
 *   Upper cap.
 *
 * @return int  The clamped limit, or -1 for the unpaginated sentinel.
 */
function myapi_parse_limit_param($allow_unlimited = FALSE, $default = 20, $max = 50) {
  if ($allow_unlimited && isset($_GET['limit']) && $_GET['limit'] === '-1') {
    return -1;
  }

  return isset($_GET['limit']) && myapi_is_positive_int_param($_GET['limit'])
    ? min($max, (int) $_GET['limit'])
    : $default;
}

/**
 * Reads an OPTIONAL id from the query string, laxly (SPEC 122).
 *
 * The lax sibling of myapi_parse_id_param(): where that one answers 422 for a
 * malformed value because it gates an access decision, this one answers NULL —
 * "no filter" — because its callers offer a narrowing the client may simply
 * omit. The notification inbox's '?condominium' and '?unit' are the two, and
 * their laxness is deliberate and documented: a client written before SPEC 26
 * sends neither, and one sending a stale id must still get its inbox.
 *
 * @param string $name  The query-string parameter to read.
 *
 * @return int|NULL  The positive integer, or NULL when absent or malformed.
 */
function myapi_parse_optional_id_param($name) {
  return isset($_GET[$name]) && myapi_is_positive_int_param($_GET[$name])
    ? (int) $_GET[$name]
    : NULL;
}
