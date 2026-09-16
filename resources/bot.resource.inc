<?php

/**
 * @file
 * Bot resource for the myapi module (SPEC 127).
 *
 * The read-only half of what the WhatsApp bot in n8n needs: given a phone
 * number, who that is and which units the payment can be charged to. It
 * registers nothing — creating the payment is the existing payment endpoint's
 * job; this one answers WHO and WHERE.
 *
 * One file per consumer, one dispatcher per route: the bot will grow more
 * endpoints, and this follows the same shape myapi.module already uses for
 * providers.
 *
 * Authentication is the machine credential in includes/myapi.bot_auth.inc, not
 * the access token — see that file for why.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.bot_auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
module_load_include('inc', 'myapi', 'includes/myapi.unit_query');
module_load_include('inc', 'myapi', 'includes/myapi.user');

/**
 * The digits a phone number is compared by.
 *
 * The last nine, which is the national number in Ecuador once the trunk 0 or
 * the +593 country code is stripped. Comparing by the tail is what makes the
 * stored format irrelevant: 0987535645, +593 98 753 5645 and (098) 753-5645
 * all end in the same nine digits.
 */
define('MYAPI_BOT_PHONE_DIGITS', 9);

/**
 * Reduces a phone number, however it is written, to the digits it is compared
 * by.
 *
 * Drops every non-digit and keeps the last MYAPI_BOT_PHONE_DIGITS. Used on
 * BOTH sides of the comparison — the value from the query string and the value
 * stored in the field — which is the whole point: one definition, so the two
 * sides cannot drift.
 *
 * Shorter input comes back shorter than nine digits rather than padded, and
 * that is what the caller checks to answer invalid_phone.
 *
 * @param mixed $value  The number as written, e.g. '+593 98 753 5645'.
 *
 * @return string  Up to nine digits, e.g. '987535645'. Empty when the input
 *                 holds no digits at all.
 */
function myapi_bot_normalize_phone($value) {
  $digits = preg_replace('/\D/', '', (string) $value);

  return (string) substr($digits, -MYAPI_BOT_PHONE_DIGITS);
}

/**
 * Builds the SQL pre-filter for a normalised phone number.
 *
 * A wildcard between every digit and at both ends, so the pattern matches the
 * digits in order no matter what separators, prefixes or trailing text sit
 * between them: '%9%8%7%5%3%5%6%4%5%'.
 *
 * It produces no false negatives — the digits of a phone number never appear
 * out of order — and the false positives it does let through are removed by
 * myapi_bot_normalize_phone() on each candidate row. A plain '%987535645'
 * would not do: it misses '099 753 5645', which is exactly the case this has
 * to cover.
 *
 * @param string $digits  Normalised digits, as myapi_bot_normalize_phone()
 *                        returns them.
 *
 * @return string  A LIKE pattern. The digits need no db_like() escaping:
 *                 there is no % or _ in a string of digits.
 */
function myapi_bot_phone_like_pattern($digits) {
  return '%' . implode('%', str_split((string) $digits)) . '%';
}

/**
 * Finds the active users whose phone number matches, exactly, after
 * normalisation.
 *
 * Two stages on purpose. The LIKE narrows the scan inside the database, and
 * the comparison in PHP is what decides: the pattern is deliberately loose, so
 * trusting it alone would match '0912987535645'. Only rows whose normalised
 * value is IDENTICAL to $digits survive.
 *
 * field_telefono is multi-value: one person can have several rows with
 * different deltas, and ANY of them matching is a match for that person.
 * Blocked accounts (users.status = 0) never come back — to the caller they are
 * indistinguishable from a number nobody has, which is the point.
 *
 * @param string $digits  Normalised digits to look for.
 *
 * @return array  Unique uids of the active users that match. Integers: they
 *                come back as the array keys the rows were deduped by, and
 *                PHP casts a numeric string key to int.
 */
function myapi_bot_find_uids_by_phone($digits) {
  $query = db_select('field_data_field_telefono', 'p')
    ->fields('p', ['entity_id', 'field_telefono_value'])
    ->condition('p.entity_type', 'user')
    ->condition('p.deleted', 0)
    ->condition('p.field_telefono_value', myapi_bot_phone_like_pattern($digits), 'LIKE');

  $query->innerJoin('users', 'u', 'u.uid = p.entity_id');
  $query->condition('u.status', 1);

  $uids = [];
  foreach ($query->execute() as $row) {
    if (myapi_bot_normalize_phone($row->field_telefono_value) === $digits) {
      $uids[$row->entity_id] = TRUE;
    }
  }

  return array_keys($uids);
}

/**
 * Builds the `units` block from the nids a person owns and occupies.
 *
 * One list, not two: the bot charges a payment to a unit, and whether the
 * person owns it or rents it is an attribute of that relationship, carried in
 * `relation`. When somebody is both owner and occupant of the same unit,
 * 'owner' wins.
 *
 * Visibility follows SPEC 08 unchanged — myapi_unit_fetch_units() returns only
 * published units, and a unit whose condominium is not in the map (unpublished
 * or gone) is dropped here. Neither the balance nor the payment information is
 * copied over: they are within reach of the query and still have no business
 * leaving Drupal for n8n.
 *
 * @param array $owned_nids     'vivienda' nids the person owns.
 * @param array $occupied_nids  'vivienda' nids the person occupies.
 *
 * @return array  One entry per visible unit, with unit_id, unit,
 *                condominium_id, condominium and relation.
 */
function myapi_bot_build_units(array $owned_nids, array $occupied_nids) {
  $nids = array_values(array_unique(array_merge($owned_nids, $occupied_nids)));
  if (empty($nids)) {
    return [];
  }

  $units = myapi_unit_fetch_units($nids);

  $condominium_nids = [];
  foreach ($units as $unit) {
    if ($unit->condominio_nid !== NULL) {
      $condominium_nids[] = $unit->condominio_nid;
    }
  }
  $condominiums = myapi_unit_fetch_condominiums(array_values(array_unique($condominium_nids)));

  // Owned nids as a lookup. Numeric string keys become ints on both sides of
  // the isset(), so '45' from one query and 45 from another still meet.
  $owned = array_flip($owned_nids);

  $built = [];
  foreach ($units as $unit) {
    if ($unit->condominio_nid === NULL || !isset($condominiums[$unit->condominio_nid])) {
      continue;
    }

    $built[] = [
      'unit_id'        => (int) $unit->nid,
      'unit'           => $unit->name,
      'condominium_id' => (int) $unit->condominio_nid,
      'condominium'    => $condominiums[$unit->condominio_nid]->title,
      'relation'       => isset($owned[$unit->nid]) ? 'owner' : 'occupant',
    ];
  }

  return $built;
}

/**
 * GET /api/v1/bot/person — resolves a phone number to a person and her units.
 *
 * The credential is checked first, before any table is read; that ordering is
 * the invariant EndpointContractTest asserts by counting queries.
 *
 * A malformed `phone` is a 422 and not a `found: false`: an empty parameter is
 * a bug in the n8n flow, and answering "I don't know that number" would hide
 * it behind a conversation with somebody who was registered all along.
 *
 * Everything past that is a 200, including every way of not finding anybody —
 * one shape, so n8n has one branch. `person` is null in all three, `no_units`
 * included: if the bot cannot charge the payment anywhere, the uid and the
 * name are personal data travelling for nothing.
 */
function myapi_bot_person_lookup() {
  myapi_bot_require_api_key();

  $phone = isset($_GET['phone']) ? trim((string) $_GET['phone']) : '';
  if ($phone === '') {
    myapi_error('missing_phone', 422);
  }

  $digits = myapi_bot_normalize_phone($phone);
  if (strlen($digits) < MYAPI_BOT_PHONE_DIGITS) {
    myapi_error('invalid_phone', 422);
  }

  $uids = myapi_bot_find_uids_by_phone($digits);

  // Nobody active has the number. A blocked account lands here too, and is not
  // told apart: a reason of its own would confirm to whoever is asking that
  // the person exists.
  if (empty($uids)) {
    myapi_respond(['found' => FALSE, 'person' => NULL, 'units' => [], 'reason' => 'not_found']);
  }

  // Two people sharing the last nine digits, with a receipt in the middle, is
  // a payment charged to the wrong unit. Answering nothing is the safe failure.
  if (count($uids) > 1) {
    myapi_respond(['found' => FALSE, 'person' => NULL, 'units' => [], 'reason' => 'ambiguous']);
  }

  $uid = (int) $uids[0];
  $units = myapi_bot_build_units(
    myapi_user_owned_unit_nids($uid),
    myapi_user_occupied_unit_nids($uid)
  );

  // Identified, but with nowhere to charge the payment. For the bot that ends
  // in the same action as not identifying her at all — ask over WhatsApp — so
  // it is the same branch, and `reason` keeps the difference for the log.
  if (empty($units)) {
    myapi_respond(['found' => FALSE, 'person' => NULL, 'units' => [], 'reason' => 'no_units']);
  }

  $names = myapi_user_display_names([$uid]);

  myapi_respond([
    'found'  => TRUE,
    'person' => [
      'uid'  => $uid,
      'name' => isset($names[$uid]) ? $names[$uid] : NULL,
    ],
    'units'  => $units,
  ]);
}

/**
 * Dispatcher for /api/v1/bot/person.
 *
 * Routes by HTTP method. Only GET is supported: the endpoint reads, and the
 * bot writes through the endpoints that already exist.
 */
function myapi_bot_person_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_bot_person_lookup();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}
