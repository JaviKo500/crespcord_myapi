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
module_load_include('inc', 'myapi', 'includes/myapi.text');

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

/* ---------------------------------------------------------------------------
 * GET /api/v1/bot/units — the search by condominium and unit name (SPEC 128).
 *
 * The second way to the same unit_id. SPEC 127 answers "who is this phone?";
 * when it answers `found: false` the bot asks over WhatsApp for the building
 * and the unit, and these are the functions that turn those two answers into
 * units.
 *
 * Everything from here to myapi_bot_match_condominiums() is PURE: no database,
 * no site state. That is deliberate — the search either finds what a person
 * typed with a missing accent, a different separator or a wrong letter, or it
 * does not, and that is decided entirely by these four functions.
 * ------------------------------------------------------------------------ */

/**
 * The shortest search term, in characters after normalising, that either
 * parameter may carry.
 *
 * Below two, "contains" answers with half the building list and the cap of
 * MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT becomes the real selection criterion —
 * which is a way of saying "the first twenty alphabetically".
 */
define('MYAPI_BOT_SEARCH_MIN_LENGTH', 2);

/**
 * How many units travel in one answer.
 *
 * `total` carries the real count, so the bot can tell "these are all of them"
 * from "there are forty, type a better name" instead of showing five as if
 * they were the whole answer.
 */
define('MYAPI_BOT_SEARCH_MAX_RESULTS', 5);

/**
 * How many condominiums the second phase is allowed to look under.
 *
 * A term like 'ed' matches the hundred buildings whose name starts with
 * "Edificio", and without this cap the next step scans 23.000 units. The best
 * twenty by rank — and, at equal rank, alphabetically — is where the search
 * stops being a report and stays a way to narrow down.
 */
define('MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT', 20);

/**
 * The shortest UNIT term the approximate pass will touch.
 *
 * The single asymmetry of the algorithm, and it is on purpose. '3B' and '3C'
 * are one edit apart: correcting one into the other hands back the neighbour's
 * unit with a payment receipt behind it. On the condominium a wrong guess costs
 * one more question; on the unit it costs money. 'dpto 3b' (six characters)
 * does go through, because there the distance measures the prefix that was
 * typed and not the number.
 */
define('MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH', 4);

/**
 * Reduces a name, however it is written, to the form it is compared by.
 *
 * myapi_text_fold() (SPEC 119) drops the accents and lowercases; what this adds
 * is removing the separators — space, '-', '.', ',', '#' and '/' — which is the
 * half that makes '3b' find 'Dpto 3-B'. Those six are what people put inside
 * the name of a home.
 *
 * Applied to BOTH sides of every comparison, exactly like
 * myapi_bot_normalize_phone() above: one definition, so the two sides cannot
 * drift apart.
 *
 * The cast is what lets a unit stored as the number 3 through — myapi_text_fold()
 * answers '' to anything that is not a string.
 *
 * @param mixed $value  The name as written or as stored, e.g. 'Dpto 3-B'.
 *
 * @return string  The comparable form, e.g. 'dpto3b'.
 */
function myapi_bot_search_normalize($value) {
  return preg_replace('/[\s\-._,#\/]+/', '', myapi_text_fold((string) $value));
}

/**
 * Splits a term into its normalised words.
 *
 * The separators of myapi_bot_search_normalize() are word boundaries here
 * rather than noise to delete, and each word is normalised on its own. It is
 * what lets 'azul torre' find 'Edificio Torre Azul': whoever types to a bot
 * over WhatsApp does not keep the written order, and the caller matches the
 * words in any order.
 *
 * @param mixed $value  The term or the stored value, e.g. 'Edificio Torre Azul'.
 *
 * @return array  The normalised words, empty ones dropped, e.g.
 *                ['edificio', 'torre', 'azul'].
 */
function myapi_bot_search_tokens($value) {
  $parts = preg_split('/[\s\-._,#\/]+/', myapi_text_fold((string) $value));

  $tokens = [];
  foreach ($parts as $part) {
    if ($part !== '') {
      $tokens[] = $part;
    }
  }

  return $tokens;
}

/**
 * Whether one name is within the edit thresholds of another.
 *
 * The table itself, and nothing else:
 *
 *   - under MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH characters: never. With three
 *     letters a difference of one is a different thing, not a typo.
 *   - 4 to 6:  levenshtein <= 1.
 *   - 7 or more: levenshtein <= 2, or similar_text >= 80%.
 *
 * It exists as a function of its own because myapi_bot_fuzzy_match() below
 * applies it in two places — the whole term, and each word of it — and the
 * thresholds are the one thing in this file that must not be written twice.
 *
 * @param string $term       A normalised term or word.
 * @param string $candidate  A normalised value or word to compare it against.
 *
 * @return bool  TRUE when the two are within the thresholds.
 */
function myapi_bot_fuzzy_within_thresholds($term, $candidate) {
  $length = strlen($term);

  if ($length < MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH || $candidate === '') {
    return FALSE;
  }

  // levenshtein() answers -1 instead of a distance when either side is longer
  // than 255 bytes, and -1 passes every <= below. A node title that long is
  // absurd and would match everything, so it is skipped rather than trusted;
  // similar_text() has no such limit and still gets its say.
  if (strlen($candidate) <= 255 && levenshtein($term, $candidate) <= ($length <= 6 ? 1 : 2)) {
    return TRUE;
  }

  if ($length >= 7) {
    similar_text($term, $candidate, $percent);
    if ($percent >= 80) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 * Whether two names are close enough to be the same one typed wrong.
 *
 * Three comparisons, in this order, each one wider than the last, and the
 * thresholds of myapi_bot_fuzzy_within_thresholds() in all three:
 *
 *   1. The whole term against the whole value.
 *   2. The whole term against each WORD of the value. The distance from
 *      'torreasul' to 'edificiotorreazul' is nine, and the one from 'asul' to
 *      'azul' is one: without this pass the typo would only be forgiven to
 *      somebody who typed the building's full name.
 *   3. Each WORD of the term against the words of the value — every one of
 *      them, the way the literal pass matches tokens. This is what finds
 *      'Edificio Torre Azul' from 'torre asul', which neither of the first two
 *      reaches: nine characters against 'torre' is four edits and 71% of
 *      similarity, while 'torre' and 'asul' matched one by one are zero edits
 *      and one.
 *
 * THE GUARD HOLDS INSIDE THE THIRD PASS TOO. A word under
 * MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH characters has to appear in the value
 * literally; it is never corrected on its own. Otherwise 'torrre 3b' would
 * reach 'Torre Azul 3-C' by correcting the unit as well as the typo, and the
 * cost of that is the neighbour's unit with a payment receipt behind it.
 *
 * What that does NOT say is that '3b' can never be approximated at all. A
 * short term compared AS A WHOLE still can — 'dpto 3b' is six characters and
 * one edit from 'dpto3c' — and the spec asks for exactly that. The defence
 * there is not the distance, it is `match: "fuzzy"` reaching n8n and n8n
 * confirming before it charges anything.
 *
 * @param mixed $needle    The term as typed.
 * @param mixed $haystack  The stored value to compare it against.
 *
 * @return bool  TRUE when the two are within the thresholds.
 */
function myapi_bot_fuzzy_match($needle, $haystack) {
  $term = myapi_bot_search_normalize($needle);
  $value = myapi_bot_search_normalize($haystack);

  if (strlen($term) < MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH || $value === '') {
    return FALSE;
  }

  $value_words = myapi_bot_search_tokens($haystack);

  foreach (array_merge([$value], $value_words) as $candidate) {
    if (myapi_bot_fuzzy_within_thresholds($term, $candidate)) {
      return TRUE;
    }
  }

  $term_words = myapi_bot_search_tokens($needle);
  if (count($term_words) < 2) {
    return FALSE;
  }

  foreach ($term_words as $word) {
    // Written as it is: nothing to forgive, whatever its length.
    if (strpos($value, $word) !== FALSE) {
      continue;
    }

    if (strlen($word) < MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH) {
      return FALSE;
    }

    $matched = FALSE;
    foreach ($value_words as $value_word) {
      if (myapi_bot_fuzzy_within_thresholds($word, $value_word)) {
        $matched = TRUE;
        break;
      }
    }

    if (!$matched) {
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * How well a term matches a value, or NULL when it does not match at all.
 *
 * The four ranks decide both what gets in and the order it comes out in:
 *
 *   exact     normalise(term) === normalise(value)
 *   prefix    normalise(value) starts with normalise(term)
 *   contains  normalise(term) appears inside it, OR every word of the term does
 *   fuzzy     within the thresholds of myapi_bot_fuzzy_match()
 *
 * $allow_fuzzy is what implements the two passes: the first one is called with
 * FALSE over the whole set, and only if it found nothing is the second one run
 * with TRUE. Mixing the two into one ranking was rejected — a search that
 * already worked must not start answering with similar-looking neighbours, and
 * the normal path does not pay for the arithmetic.
 *
 * An empty term matches nothing. Without that guard strpos() would answer 0 for
 * it and every value would come back as a prefix match.
 *
 * @param mixed $term         The term as typed.
 * @param mixed $value        The stored value.
 * @param bool  $allow_fuzzy  Whether this is the approximate pass.
 *
 * @return string|null  'exact', 'prefix', 'contains', 'fuzzy' or NULL.
 */
function myapi_bot_match_rank($term, $value, $allow_fuzzy = FALSE) {
  $needle = myapi_bot_search_normalize($term);
  $haystack = myapi_bot_search_normalize($value);

  if ($needle === '' || $haystack === '') {
    return NULL;
  }

  if ($needle === $haystack) {
    return 'exact';
  }

  $position = strpos($haystack, $needle);
  if ($position === 0) {
    return 'prefix';
  }
  if ($position !== FALSE) {
    return 'contains';
  }

  // Every word of the term, in any order. AND and not OR: with OR, 'torre'
  // alone would drag in half the buildings that carry any second word.
  $tokens = myapi_bot_search_tokens($term);
  if (count($tokens) > 1) {
    $all = TRUE;
    foreach ($tokens as $token) {
      if (strpos($haystack, $token) === FALSE) {
        $all = FALSE;
        break;
      }
    }
    if ($all) {
      return 'contains';
    }
  }

  if ($allow_fuzzy && myapi_bot_fuzzy_match($term, $value)) {
    return 'fuzzy';
  }

  return NULL;
}
