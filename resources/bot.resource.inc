<?php

/**
 * @file
 * Bot resource for the myapi module (SPECS 127, 128 and 130).
 *
 * Everything the WhatsApp bot in n8n talks to, in the order a conversation
 * uses it. Given a phone number, who that is and which units a payment can be
 * charged to (SPEC 127); when the phone identified nobody, the same unit
 * reached by the building and unit names the person dictated (SPEC 128); and
 * finally, holding a uid, a unit_id and a receipt the model already read, the
 * payment itself (SPEC 130).
 *
 * THE FIRST TWO READ, THE THIRD WRITES, and that is the line worth keeping in
 * mind while reading this file: the same machine key that opened two harmless
 * lookups now creates payment nodes. Which is why the write path revalidates
 * every identity it is handed instead of trusting it, and why the payment it
 * creates is born "Pendiente de verificar" like every other one — nobody is
 * paid until a human verifies it in the back office.
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
// The write half of a payment (SPEC 130). The bot's create reuses the app's
// helpers rather than reimplementing them: the same date normalization, the
// same duplicate-reference lookup, the same upload and the same node builder,
// because a payment registered from WhatsApp and one registered from the app
// are the same 'pagos' node. It also brings in includes/myapi.payment_workflow
// with it, which is where MYAPI_PAYMENT_CHANNEL_BOT and the backend email live.
module_load_include('inc', 'myapi', 'includes/myapi.payment_write');

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

/**
 * The sort weight of a match rank.
 *
 * The four ranks are an order, not a set: a unit whose name was typed in full
 * must come out above one that merely contains the term, and both above one
 * that needed a letter corrected. This is that order, and it is a function so
 * the numbers live once.
 *
 * @param string|null $rank  As myapi_bot_match_rank() returns it.
 *
 * @return int  0 for 'exact' … 3 for 'fuzzy'; 4 for anything unknown, which
 *              sorts last rather than first.
 */
function myapi_bot_match_weight($rank) {
  $weights = ['exact' => 0, 'prefix' => 1, 'contains' => 2, 'fuzzy' => 3];

  return isset($weights[$rank]) ? $weights[$rank] : 4;
}

/**
 * Resolves a term to the condominiums it names — the first phase of the
 * cascade.
 *
 * Two passes, and the second only runs if the first found nothing: a search
 * that already works must not start answering with similar-looking neighbours,
 * and the normal path does not pay for the arithmetic.
 *
 * The result is capped at MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT, best rank first
 * and, at equal rank, by normalised title. Without the cap a term like 'ed'
 * matches the hundred buildings whose name starts with "Edificio" and the next
 * phase scans 23.000 units. The cap is why `total` counts inside the twenty
 * examined and not inside the hundred and fifty, and the documentation says so
 * in those words.
 *
 * @param mixed $term           The condominium term as typed.
 * @param array $condominiums   Map of nid => title, as
 *                              myapi_unit_fetch_all_condominiums() returns it.
 *
 * @return array  Map of nid => rank, at most MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT
 *                entries, in the order described above.
 */
function myapi_bot_match_condominiums($term, array $condominiums) {
  $matched = [];

  foreach ([FALSE, TRUE] as $allow_fuzzy) {
    foreach ($condominiums as $nid => $title) {
      $rank = myapi_bot_match_rank($term, $title, $allow_fuzzy);
      if ($rank !== NULL) {
        $matched[$nid] = $rank;
      }
    }

    if ($matched) {
      break;
    }
  }

  if (!$matched) {
    return [];
  }

  $order = [];
  foreach ($matched as $nid => $rank) {
    $order[] = [
      'nid'    => $nid,
      'rank'   => $rank,
      'weight' => myapi_bot_match_weight($rank),
      'title'  => myapi_bot_search_normalize($condominiums[$nid]),
    ];
  }

  usort($order, function ($a, $b) {
    if ($a['weight'] !== $b['weight']) {
      return $a['weight'] < $b['weight'] ? -1 : 1;
    }
    $title = strcmp($a['title'], $b['title']);
    if ($title !== 0) {
      return $title;
    }

    // The final tie-break, so two identical calls cannot come out in two
    // different orders because the map arrived in a different order.
    return (int) $a['nid'] < (int) $b['nid'] ? -1 : ((int) $a['nid'] > (int) $b['nid'] ? 1 : 0);
  });

  $ranks = [];
  foreach (array_slice($order, 0, MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT) as $entry) {
    $ranks[$entry['nid']] = $entry['rank'];
  }

  return $ranks;
}

/**
 * Resolves a term to the units it names — the second phase of the cascade.
 *
 * The same two passes as the phase above, over the rows of the condominiums
 * already resolved, with ONE difference: the approximate pass is skipped
 * entirely unless the term is at least MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH
 * characters long. See that constant for why the asymmetry is deliberate.
 *
 * @param mixed $term   The unit term as typed.
 * @param array $units  Rows as myapi_unit_fetch_units() returns them.
 *
 * @return array  Map of unit nid => rank.
 */
function myapi_bot_match_units($term, array $units) {
  $allow_fuzzy_pass = strlen(myapi_bot_search_normalize($term)) >= MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH;

  $matched = [];
  foreach ([FALSE, TRUE] as $allow_fuzzy) {
    if ($allow_fuzzy && !$allow_fuzzy_pass) {
      break;
    }

    foreach ($units as $unit) {
      $rank = myapi_bot_match_rank($term, $unit->name, $allow_fuzzy);
      if ($rank !== NULL) {
        $matched[$unit->nid] = $rank;
      }
    }

    if ($matched) {
      break;
    }
  }

  return $matched;
}

/**
 * The `owner` block of one unit, or NULL.
 *
 * NULL — and the unit still travels — in the three cases that mean the same
 * thing to the bot: no owner assigned, a uid that no longer resolves to an
 * account, and a name that was not asked for. The payment can be charged
 * either way; what it must never carry is the owner's phone, national id or
 * email, and the only two fields here are the two the bot needs to confirm
 * over WhatsApp ("are you Juan Pérez, from Dpto 3B?").
 *
 * It exists as a function because two callers build this block — the element
 * builder below and the handler, which fills the names in after the cut — and
 * the shape must not be written twice.
 *
 * @param mixed $uid    The unit's owner_uid, possibly NULL.
 * @param array $names  Map of uid => display name.
 *
 * @return array|null  ['uid' => int, 'name' => string] or NULL.
 */
function myapi_bot_search_owner($uid, array $names) {
  if ($uid === NULL || !isset($names[$uid])) {
    return NULL;
  }

  return ['uid' => (int) $uid, 'name' => $names[$uid]];
}

/**
 * Builds the answer's elements, ordered, and ALL of them.
 *
 * The cap belongs to the caller, which is the only one that needs `total`: the
 * real number of matches is the length of what this returns, and slicing it
 * here would throw that away.
 *
 * `match` collapses the four internal ranks into the two behaviours n8n has.
 * 'exact' means NOT APPROXIMATED — prefix and contains are literal matches and
 * belong on that side — and 'fuzzy' means at least one of the two names needed
 * a letter corrected, which is when the bot must confirm before charging
 * anything.
 *
 * The order is the five criteria of the spec, and the last of them is the
 * unit's nid so that two identical calls cannot answer in two different orders.
 *
 * @param array $units                Rows as myapi_unit_fetch_units() returns.
 * @param array $unit_ranks           Map of unit nid => rank.
 * @param array $condominium_ranks    Map of condominium nid => rank.
 * @param array $condominium_titles   Map of condominium nid => title.
 * @param array $owner_names          Map of uid => display name; a uid that is
 *                                    missing answers a null owner.
 *
 * @return array  Every matching unit, ordered, with unit_id, unit,
 *                condominium_id, condominium, owner and match.
 */
function myapi_bot_build_search_results(array $units, array $unit_ranks, array $condominium_ranks, array $condominium_titles, array $owner_names) {
  $built = [];

  foreach ($units as $unit) {
    if (!isset($unit_ranks[$unit->nid])) {
      continue;
    }

    // A unit whose condominium did not match is not in the answer, and neither
    // is one whose condominium is no longer visible: the nid never reached the
    // map that resolved the term.
    if ($unit->condominio_nid === NULL || !isset($condominium_ranks[$unit->condominio_nid])) {
      continue;
    }

    $unit_rank = $unit_ranks[$unit->nid];
    $condominium_rank = $condominium_ranks[$unit->condominio_nid];

    $built[] = [
      'element' => [
        'unit_id'        => (int) $unit->nid,
        'unit'           => $unit->name,
        'condominium_id' => (int) $unit->condominio_nid,
        'condominium'    => $condominium_titles[$unit->condominio_nid],
        'owner'          => myapi_bot_search_owner($unit->owner_uid, $owner_names),
        'match'          => ($unit_rank === 'fuzzy' || $condominium_rank === 'fuzzy') ? 'fuzzy' : 'exact',
      ],
      'unit_weight'        => myapi_bot_match_weight($unit_rank),
      'condominium_weight' => myapi_bot_match_weight($condominium_rank),
      'condominium_title'  => myapi_bot_search_normalize($condominium_titles[$unit->condominio_nid]),
      'unit_name'          => myapi_bot_search_normalize($unit->name),
      'unit_id'            => (int) $unit->nid,
    ];
  }

  usort($built, function ($a, $b) {
    foreach (['unit_weight', 'condominium_weight'] as $weight) {
      if ($a[$weight] !== $b[$weight]) {
        return $a[$weight] < $b[$weight] ? -1 : 1;
      }
    }
    foreach (['condominium_title', 'unit_name'] as $name) {
      $comparison = strcmp($a[$name], $b[$name]);
      if ($comparison !== 0) {
        return $comparison;
      }
    }

    return $a['unit_id'] < $b['unit_id'] ? -1 : ($a['unit_id'] > $b['unit_id'] ? 1 : 0);
  });

  return array_column($built, 'element');
}

/**
 * GET /api/v1/bot/units — resolves a condominium and a unit name to units.
 *
 * The second way to the same unit_id. SPEC 127 answers "who is this phone?";
 * when it cannot, the bot asks over WhatsApp for the building and the unit, and
 * this is what it calls with those two answers in hand.
 *
 * BOTH parameters are required. `?unit=3b` alone walks a table of 35.000 rows
 * and '3B' exists in nearly every one of the 150 buildings — five results out
 * of a hundred-odd real matches is noise, not an answer — and
 * `?condominium=torre azul` alone is the whole building listed and cut to five,
 * which no branch of the conversation needs.
 *
 * The credential is checked first, before any table is read; that ordering is
 * the invariant EndpointContractTest asserts by counting queries. A malformed
 * parameter is a 422 and not an empty answer, for the same reason as in
 * /bot/person: it is a bug in the n8n flow, and answering "I found nothing"
 * would hide it behind a conversation with somebody who typed correctly.
 *
 * Everything past that is a 200, including finding nothing at all. One shape,
 * one branch in n8n, and never a 404 — a 404 reads like a mistyped URL.
 */
function myapi_bot_units_search() {
  myapi_bot_require_api_key();

  $condominium_term = isset($_GET['condominium']) ? trim((string) $_GET['condominium']) : '';
  if ($condominium_term === '') {
    myapi_error('missing_condominium', 422);
  }

  $unit_term = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
  if ($unit_term === '') {
    myapi_error('missing_unit', 422);
  }

  if (strlen(myapi_bot_search_normalize($condominium_term)) < MYAPI_BOT_SEARCH_MIN_LENGTH) {
    myapi_error('invalid_condominium', 422);
  }

  if (strlen(myapi_bot_search_normalize($unit_term)) < MYAPI_BOT_SEARCH_MIN_LENGTH) {
    myapi_error('invalid_unit', 422);
  }

  $empty = ['found' => FALSE, 'total' => 0, 'units' => []];

  $titles = myapi_unit_fetch_all_condominiums();
  $condominium_ranks = myapi_bot_match_condominiums($condominium_term, $titles);

  // No building, no second phase. The table of 35.000 units is never touched
  // when the term names nothing, which is the point of the cascade.
  if (empty($condominium_ranks)) {
    myapi_respond($empty);
  }

  $nids = myapi_unit_fetch_unit_nids_by_condominium(array_keys($condominium_ranks));
  if (empty($nids)) {
    myapi_respond($empty);
  }

  $units = myapi_unit_fetch_units($nids);
  $unit_ranks = myapi_bot_match_units($unit_term, $units);
  if (empty($unit_ranks)) {
    myapi_respond($empty);
  }

  // Built with no names at all: what the order and the count need is the
  // ranks, and a name is only worth a query for the units that survive the
  // cut. Every `owner` is NULL at this point.
  $results = myapi_bot_build_search_results($units, $unit_ranks, $condominium_ranks, $titles, []);
  if (empty($results)) {
    myapi_respond($empty);
  }

  // Counted BEFORE the cut: `total` is what tells the bot "these are all of
  // them" from "there are forty, ask for a better name".
  $total = count($results);
  $results = array_slice($results, 0, MYAPI_BOT_SEARCH_MAX_RESULTS);

  $owner_uids = [];
  foreach ($units as $unit) {
    if ($unit->owner_uid !== NULL) {
      $owner_uids[$unit->nid] = $unit->owner_uid;
    }
  }

  // The owners' names, for the five that travel and not for all the matches.
  // With a total of forty that is the difference between a query of five rows
  // and one of forty, for a value that was never going to be answered.
  $wanted = [];
  foreach ($results as $result) {
    if (isset($owner_uids[$result['unit_id']])) {
      $wanted[$owner_uids[$result['unit_id']]] = TRUE;
    }
  }

  if ($wanted) {
    $names = myapi_user_display_names(array_keys($wanted));
    foreach ($results as $index => $result) {
      $uid = isset($owner_uids[$result['unit_id']]) ? $owner_uids[$result['unit_id']] : NULL;
      $results[$index]['owner'] = myapi_bot_search_owner($uid, $names);
    }
  }

  myapi_respond([
    'found' => TRUE,
    'total' => $total,
    'units' => $results,
  ]);
}

/**
 * Dispatcher for /api/v1/bot/units.
 *
 * Routes by HTTP method. Only GET is supported: the endpoint reads, and the bot
 * writes through the endpoints that already exist.
 */
function myapi_bot_units_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_bot_units_search();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/* ---------------------------------------------------------------------------
 * POST /api/v1/bot/payments — the payment registration (SPEC 130).
 *
 * Where the conversation stops being a question. The two routes above end at a
 * uid and a unit_id; this is what the bot calls holding both, plus the receipt
 * image and whatever the model read off it, and it creates the SAME 'pagos'
 * node the app creates — same helpers, same forced estado, same email to the
 * 'backend' role — told apart only by its channel and by the raw reading kept
 * beside it as evidence.
 *
 * Two things make it unlike anything else in this file. It WRITES, so the
 * machine key stops being a read-only credential: the identity the bot hands
 * over is revalidated rather than trusted, because a leaked key must not be
 * able to charge a payment to a unit that is not that person's. And it is
 * IDEMPOTENT per WhatsApp message attachment, because n8n retries: the ledger
 * in myapi_bot_payments turns a retry into a 200 carrying the payment that
 * already exists instead of a second payment nobody asked for.
 * ------------------------------------------------------------------------ */

/**
 * Dispatcher for /api/v1/bot/payments.
 *
 * Routes by HTTP method. Only POST is supported: the endpoint creates, and
 * reading, listing or voiding a payment is the app's payment endpoints' job —
 * they do not distinguish the channel, so a payment the bot registered shows
 * up there as one more.
 */
function myapi_bot_payment_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_bot_payment_create();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * The biggest 'payload' part this endpoint will parse, in bytes.
 *
 * 64 KB, which is orders of magnitude more than a structured reading of one
 * receipt needs. The cap is not about the bot: it is about what a
 * drupal_json_decode() of an arbitrarily large body costs on an EOL Drupal 7,
 * which without a ceiling is a very cheap denial of service against a route
 * whose credential is a shared machine key.
 */
define('MYAPI_BOT_PAYMENT_MAX_PAYLOAD', 65536);

/**
 * What the second half of the idempotency key is when media.ref is absent.
 *
 * A single character that cannot be confused with a real reference, so that a
 * bot that never sends media.ref behaves exactly as if the key were the wamid
 * alone — which is the right behaviour when a message carries one attachment.
 */
define('MYAPI_BOT_PAYMENT_MEDIA_REF_FALLBACK', '-');

/**
 * Reads a dot-separated path out of the decoded payload.
 *
 * The payload is four levels of nested objects, and the alternative to this is
 * a staircase of isset() per field that gets one level wrong exactly once and
 * emits a notice into the JSON response. Here a missing step, or a step that
 * holds a scalar where an object was expected, is simply NULL.
 *
 * It also hands the caller the error message for free: the path it was asked
 * for IS the '@field' of the 422, so a failure reads as 'receipt.amount' in
 * the n8n log instead of an 'amount' that could be three different fields.
 *
 * @param array  $payload  The decoded payload.
 * @param string $path     Dot-separated path, e.g. 'identity.person.uid'.
 *
 * @return mixed  The value at that path, or NULL when any step is missing or
 *                is not an array.
 */
function myapi_bot_payload_get(array $payload, $path) {
  $current = $payload;
  foreach (explode('.', $path) as $step) {
    if (!is_array($current) || !array_key_exists($step, $current)) {
      return NULL;
    }
    $current = $current[$step];
  }

  return $current;
}

/**
 * Reads and decodes the 'payload' part of the multipart request.
 *
 * The JSON travels in a text field rather than as the request body because the
 * receipt image forces multipart — the same decision SPEC 20 made, and what
 * lets this endpoint reuse myapi_payment_save_file() untouched.
 *
 * Everything that can go wrong here is one error, invalid_payload: absent,
 * empty, over the cap, malformed, or well-formed JSON that is not an object.
 * They are all the same failure from n8n's point of view — "what you put in
 * the payload field is not a reading" — and splitting them into codes would
 * give the bot four branches where it needs none.
 *
 * A list and an empty array both fail here. PHP cannot tell `{}` from `[]`
 * after decoding, so the empty case has to pick a side, and invalid_payload is
 * the honest one: an empty object is not a reading of a receipt either.
 *
 * Aborts with 422 invalid_payload; returns only on success.
 *
 * @return array  The decoded payload as an associative array.
 */
function myapi_bot_payment_parse_payload() {
  $raw = myapi_request_post_field('payload');
  if ($raw === NULL || $raw === '') {
    myapi_error('invalid_payload', 422);
  }
  if (strlen($raw) > MYAPI_BOT_PAYMENT_MAX_PAYLOAD) {
    myapi_error('invalid_payload', 422);
  }

  $decoded = drupal_json_decode($raw);
  if (!is_array($decoded) || $decoded === []) {
    myapi_error('invalid_payload', 422);
  }
  // A JSON list decodes to an array too, and every read below is by name, so
  // a list would answer NULL to all of them and report a missing message_key
  // for what is really a malformed payload.
  if (array_keys($decoded) === range(0, count($decoded) - 1)) {
    myapi_error('invalid_payload', 422);
  }

  return $decoded;
}

/**
 * Reads a required positive integer out of the payload.
 *
 * Absent is missing_field and malformed is invalid_field, because they send
 * n8n to two different places: the first says a mapping node never wrote the
 * key, the second that it wrote something that is not an id.
 *
 * @param array  $payload  The decoded payload.
 * @param string $path     Dot-separated path, used verbatim as '@field'.
 *
 * @return int  The value as a positive integer.
 */
function myapi_bot_payment_required_id(array $payload, $path) {
  $value = myapi_bot_payload_get($payload, $path);
  if ($value === NULL || $value === '') {
    myapi_error('missing_field', 422, ['@field' => $path]);
  }
  // JSON gives a real int, but n8n mapping nodes routinely stringify, so both
  // are accepted and nothing else is: a float, a bool or an array is not an id.
  if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
    myapi_error('invalid_field', 422, ['@field' => $path]);
  }
  if ((int) $value <= 0) {
    myapi_error('invalid_field', 422, ['@field' => $path]);
  }

  return (int) $value;
}

/**
 * Reads an optional free-text field, length-checked and sanitized.
 *
 * Absent, empty and whitespace-only all collapse to NULL so the caller has one
 * "there is nothing here" to test. Over the limit is invalid_field rather than
 * a silent truncation: the two bank names are what a human reads when
 * verifying the payment, and half a bank name is worse than a rejected call.
 *
 * check_plain() runs AFTER the length check, exactly as the app's create does,
 * so the two doors sanitize the same string the same way.
 *
 * @param array  $payload     The decoded payload.
 * @param string $path        Dot-separated path, used verbatim as '@field'.
 * @param int    $max_length  Maximum length before sanitizing.
 *
 * @return string|null  The sanitized value, or NULL when absent.
 */
function myapi_bot_payment_optional_text(array $payload, $path, $max_length = 255) {
  $value = myapi_bot_payload_get($payload, $path);
  if ($value === NULL) {
    return NULL;
  }
  if (!is_scalar($value)) {
    myapi_error('invalid_field', 422, ['@field' => $path]);
  }
  $value = trim((string) $value);
  if ($value === '') {
    return NULL;
  }
  if (strlen($value) > $max_length) {
    myapi_error('invalid_field', 422, ['@field' => $path]);
  }

  return check_plain($value);
}

/**
 * Validates the payload against the contract and returns it normalized.
 *
 * Walks the contract in the order of the table in specs/bot/130, and every
 * check aborts with its own error before anything else is read — so a payload
 * with three problems always reports the first one, and reports it the same
 * way twice.
 *
 * WHAT IT DOES NOT DO: touch the site. Whether the uid exists, whether the
 * unit is published, whether that person may pay for that unit — all of that
 * is myapi_bot_payment_create()'s, after this returns. This function is pure,
 * which is why nearly every test of this endpoint lands on it.
 *
 * The date validation and the amount rule are NOT rewritten here: they are the
 * app's, myapi_payment_normalize_date() and the same is_numeric() && > 0, so
 * the two doors cannot drift into accepting different dates for the same node.
 *
 * @param array $payload  The decoded payload.
 *
 * @return array  Normalized values, keyed: message_key, media_ref, uid,
 *                unit_nid, condominium_id, reference, amount, date,
 *                issuing_bank, destination_bank.
 */
function myapi_bot_payment_validate(array $payload) {
  // 1. message.message_key — the wamid, and the first half of the idempotency
  // key. Capped at the width of the column that stores it in the clear: a
  // truncated wamid would still hash to the right key, but the audit column
  // that answers "which payments came from this message?" would hold a
  // different string than the one WhatsApp sent.
  $message_key = myapi_bot_payload_get($payload, 'message.message_key');
  if ($message_key === NULL || (is_string($message_key) && trim($message_key) === '')) {
    myapi_error('missing_field', 422, ['@field' => 'message.message_key']);
  }
  if (!is_scalar($message_key)) {
    myapi_error('invalid_field', 422, ['@field' => 'message.message_key']);
  }
  $message_key = trim((string) $message_key);
  if (strlen($message_key) > 255) {
    myapi_error('invalid_field', 422, ['@field' => 'message.message_key']);
  }

  // 2-4. The identity the bot resolved with SPEC 127 or 128. Only the shape is
  // checked here; whether they exist and belong together is revalidated
  // against the site by the caller.
  $uid            = myapi_bot_payment_required_id($payload, 'identity.person.uid');
  $unit_nid       = myapi_bot_payment_required_id($payload, 'identity.unit.unit_id');
  $condominium_id = myapi_bot_payment_required_id($payload, 'identity.unit.condominium_id');

  // 5. media.ref — which attachment of the message this receipt is, and the
  // second half of the idempotency key. Absent falls back to '-'.
  $media_ref = myapi_bot_payload_get($payload, 'media.ref');
  if ($media_ref === NULL) {
    $media_ref = MYAPI_BOT_PAYMENT_MEDIA_REF_FALLBACK;
  }
  else {
    if (!is_scalar($media_ref)) {
      myapi_error('invalid_field', 422, ['@field' => 'media.ref']);
    }
    $media_ref = trim((string) $media_ref);
    if ($media_ref === '') {
      $media_ref = MYAPI_BOT_PAYMENT_MEDIA_REF_FALLBACK;
    }
    elseif (strlen($media_ref) > 64) {
      myapi_error('invalid_field', 422, ['@field' => 'media.ref']);
    }
  }

  // 6. receipt.reference — same rule and same order as the app's create.
  $reference = myapi_bot_payload_get($payload, 'receipt.reference');
  if ($reference === NULL || (is_string($reference) && trim($reference) === '')) {
    myapi_error('missing_field', 422, ['@field' => 'receipt.reference']);
  }
  if (!is_scalar($reference)) {
    myapi_error('invalid_field', 422, ['@field' => 'receipt.reference']);
  }
  $reference = trim((string) $reference);
  if (strlen($reference) > 255) {
    myapi_error('invalid_field', 422, ['@field' => 'receipt.reference']);
  }
  $reference = check_plain($reference);

  // 7. receipt.amount — the app's rule, not a second one.
  $amount = myapi_bot_payload_get($payload, 'receipt.amount');
  if ($amount === NULL || $amount === '') {
    myapi_error('missing_field', 422, ['@field' => 'receipt.amount']);
  }
  if (!is_numeric($amount) || (float) $amount <= 0) {
    myapi_error('invalid_amount', 422);
  }
  $amount = (float) $amount;

  // 8. receipt.date — REQUIRED here and optional in the app, on purpose. In
  // the app there is a person who knows what day they paid; here an absent
  // date means the OCR did not read one, and dating the payment at the instant
  // of the conversation instead of the day of the transfer would be a false
  // value nobody would ever catch. So there is no fall back to server time.
  $date = myapi_bot_payload_get($payload, 'receipt.date');
  if ($date === NULL || (is_string($date) && trim($date) === '')) {
    myapi_error('missing_field', 422, ['@field' => 'receipt.date']);
  }
  if (!is_scalar($date)) {
    myapi_error('invalid_date', 422);
  }
  $date = myapi_payment_normalize_date(trim((string) $date));
  if ($date === NULL) {
    myapi_error('invalid_date', 422);
  }

  // 9-10. The two banks the OCR read. Free text: they are NOT resolved against
  // the 'bancos' vocabulary, which is the app's mechanism (SPEC 129).
  $issuing_bank     = myapi_bot_payment_optional_text($payload, 'receipt.issuing_bank');
  $destination_bank = myapi_bot_payment_optional_text($payload, 'receipt.destination_bank');

  return [
    'message_key'      => $message_key,
    'media_ref'        => $media_ref,
    'uid'              => $uid,
    'unit_nid'         => $unit_nid,
    'condominium_id'   => $condominium_id,
    'reference'        => $reference,
    'amount'           => $amount,
    'date'             => $date,
    'issuing_bank'     => $issuing_bank,
    'destination_bank' => $destination_bank,
  ];
}

/**
 * The payment method every bot payment is stored with.
 *
 * Forced, never read from the payload: what the bot registers is always a bank
 * receipt, and a receipt is never cash. One field fewer to send is one field
 * fewer to validate, and one fewer way for a reading to be wrong.
 */
define('MYAPI_BOT_PAYMENT_METHOD', 'Transferencia');

/**
 * The SPEC 129 field holding the raw reading, and the guard that this endpoint
 * is deployed on a site where SPEC 129 was applied.
 */
define('MYAPI_BOT_PAYMENT_EVIDENCE_FIELD', 'field_comprobante_ocr');

/**
 * The two free-text bank fields of SPEC 129.
 *
 * Text and not a term reference on purpose: what the OCR read is a name off a
 * receipt, and forcing it through the 'bancos' vocabulary would raise the
 * question "and if it matches no term?" on every single payment.
 */
define('MYAPI_BOT_PAYMENT_ISSUING_BANK_FIELD', 'field_banco_emisor');
define('MYAPI_BOT_PAYMENT_DESTINATION_BANK_FIELD', 'field_banco_destino');

/**
 * Builds the idempotency key of one WhatsApp message attachment.
 *
 * WHY THE KEY IS COMPOUND. The wamid identifies the MESSAGE, which is what n8n
 * retries — but one message can carry several attachments. With the wamid
 * alone, a person sending two receipts in a single WhatsApp message would have
 * the second answered as a retry of the first: 200, the wrong payment back,
 * and one payment silently lost. media.ref says WHICH attachment this is, and
 * costs one concatenation to close that hole.
 *
 * WHY IT IS HASHED. Both halves are of uncertain length, and a varchar primary
 * key cannot exceed 191 characters under utf8mb4. A sha256 hex is 64 fixed
 * characters, whatever came in.
 *
 * @param string $message_key  The wamid of the WhatsApp message.
 * @param string $media_ref    The attachment reference, or '-' when absent.
 *
 * @return string  A 64-character hex digest.
 */
function myapi_bot_payment_idempotency_key($message_key, $media_ref) {
  return hash('sha256', $message_key . '#' . $media_ref);
}

/**
 * Adds to the node the four things that make it a bot payment.
 *
 * Deliberately NOT parameters of myapi_payment_build_node(): the base node is
 * identical for both channels, and the path every resident's payment travels
 * must not grow arguments only one caller ever passes.
 *
 * The evidence is the payload WHOLE, wrapped in nothing but a timestamp. A
 * selection made by today's criterion does not answer the question asked in
 * six months, and it is cheap: one drupal_json_encode(). What ends up in there
 * is the wamid that caused the payment, the model that read it, the
 * per-field confidences and whatever the bot corrected — none of which the
 * backend ever reads, by design (SPEC 130, out of scope).
 *
 * field_banco is NOT set here and must stay unset: bank_id and bank_name are
 * always null on a bot payment.
 *
 * @param object      $node         The node built by myapi_payment_build_node().
 * @param array       $payload      The decoded payload, exactly as received.
 * @param string|null $issuing      Sanitized issuing bank name, or NULL.
 * @param string|null $destination  Sanitized destination bank name, or NULL.
 */
function myapi_bot_payment_decorate_node($node, array $payload, $issuing, $destination) {
  $node->{MYAPI_PAYMENT_CHANNEL_FIELD}[LANGUAGE_NONE][0]['value'] = MYAPI_PAYMENT_CHANNEL_BOT;

  $node->{MYAPI_BOT_PAYMENT_EVIDENCE_FIELD}[LANGUAGE_NONE][0]['value'] = drupal_json_encode([
    'stored_at' => date('Y-m-d\TH:i:s'),
    'payload'   => $payload,
  ]);

  if ($issuing !== NULL) {
    $node->{MYAPI_BOT_PAYMENT_ISSUING_BANK_FIELD}[LANGUAGE_NONE][0]['value'] = $issuing;
  }
  if ($destination !== NULL) {
    $node->{MYAPI_BOT_PAYMENT_DESTINATION_BANK_FIELD}[LANGUAGE_NONE][0]['value'] = $destination;
  }
}

/**
 * Responds with a payment node in the app's exact 201 shape, and exits.
 *
 * The body of the idempotent 200 and of the 201 are built by the SAME mapper,
 * myapi_payment_build_created_item(), so n8n never has to branch: it always
 * reads data.payment, and the only difference between a new payment and a
 * retry is the HTTP status, which is there for whoever reads the logs.
 *
 * The bank term is always NULL — a bot payment has no 'bancos' term, so
 * bank_id and bank_name are always null.
 *
 * @param object $node    The payment node, saved or loaded.
 * @param int    $status  201 for a new payment, 200 for a retry.
 */
function myapi_bot_payment_respond($node, $status) {
  $file = NULL;
  if (isset($node->field_archivo[LANGUAGE_NONE][0]['fid'])) {
    $loaded = file_load($node->field_archivo[LANGUAGE_NONE][0]['fid']);
    if ($loaded) {
      $file = $loaded;
    }
  }

  myapi_respond(
    ['payment' => myapi_payment_build_created_item($node, $file, NULL)],
    $status,
    'payment_created'
  );
}

/**
 * Looks an idempotency key up and answers 200 when it already has a payment.
 *
 * Three outcomes, and the third is the interesting one: no row at all (this is
 * a new payment); a row whose node is alive (a retry — answer 200 and touch
 * nothing); or a row whose node was DELETED in the back office. That last row
 * is an orphan, and it is removed rather than honoured: answering 200 with a
 * payment that no longer exists would be a lie, and answering 409 forever
 * would let one deletion block that WhatsApp message for good.
 *
 * Exits with 200 when it finds a live payment; returns otherwise.
 *
 * @param string $key  The idempotency key.
 */
function myapi_bot_payment_resolve_existing($key) {
  $nid = db_select('myapi_bot_payments', 'b')
    ->fields('b', ['nid'])
    ->condition('b.idempotency_key', $key)
    ->range(0, 1)
    ->execute()
    ->fetchField();

  if ($nid === FALSE || $nid === NULL) {
    return;
  }

  $node = node_load((int) $nid);
  if ($node && $node->type === 'pagos') {
    myapi_bot_payment_respond($node, 200);
  }

  db_delete('myapi_bot_payments')
    ->condition('idempotency_key', $key)
    ->execute();
}

/**
 * Handles POST /api/v1/bot/payments.
 *
 * Creates the same 'pagos' node the app creates, from the reading the bot made
 * of a WhatsApp receipt plus the image itself, idempotently per message
 * attachment. Every check aborts with its own error before anything is
 * created, and the order below is the contract — see the numbered plan in
 * specs/bot/130-bot-payment-registration.md, step 6.
 *
 * THE IDENTITY IS REVALIDATED, NOT TRUSTED. The bot resolved the uid and the
 * unit_id with SPEC 127 or 128 and hands them over here, but the credential on
 * this route is a MACHINE key: whoever holds it could otherwise charge a
 * payment to any unit in the name of any resident. So the user must exist and
 * be active, the unit must be a published 'vivienda', its condominium must be
 * the one the payload claims, and that person must own or occupy it.
 *
 * WHAT IS DELIBERATELY NOT CHECKED is the sender's phone number. It is kept in
 * the evidence and compared with nothing: SPEC 128 exists precisely to reach a
 * unit when the phone identified nobody, and demanding a match would close
 * that road.
 */
function myapi_bot_payment_create() {
  // 1. The machine credential. First, and before any table is read.
  myapi_bot_require_api_key();

  // 2. Schema guard. Without SPEC 129 applied, node_save() drops the channel
  // and the evidence SILENTLY — the payments would be created with neither,
  // and nothing anywhere would say so. An endpoint that does not work beats
  // one that stores mutilated payments.
  if (!field_info_field(MYAPI_BOT_PAYMENT_EVIDENCE_FIELD)) {
    watchdog(
      'myapi',
      'Bot payment refused: @field does not exist. SPEC 129 has not been applied on this site (run drush updb).',
      ['@field' => MYAPI_BOT_PAYMENT_EVIDENCE_FIELD],
      WATCHDOG_ERROR
    );
    myapi_error('server_error', 500);
  }

  // 3-4. The reading, parsed and validated. Pure: nothing below is touched
  // until the payload is known to be well formed.
  $payload = myapi_bot_payment_parse_payload();
  $values  = myapi_bot_payment_validate($payload);

  // 5. Idempotency, first pass. A retry of n8n ends here with 200 and the
  // payment it already created; an orphan row is swept and we carry on.
  $key = myapi_bot_payment_idempotency_key($values['message_key'], $values['media_ref']);
  myapi_bot_payment_resolve_existing($key);

  // 6. The person. Blocked is refused like non-existent: a deactivated account
  // does not get payments registered in its name.
  $account = user_load($values['uid']);
  if (!$account || (int) $account->status !== 1) {
    myapi_error('invalid_field', 422, ['@field' => 'identity.person.uid']);
  }

  // 7. The unit, and the condominium it really belongs to. IDS are compared,
  // never names: a building is written a dozen ways and its nid only one.
  $unit = node_load($values['unit_nid']);
  if (!$unit || $unit->type !== 'vivienda' || (int) $unit->status !== 1) {
    myapi_error('invalid_field', 422, ['@field' => 'identity.unit.unit_id']);
  }
  $unit_condominium = isset($unit->field_condominio[LANGUAGE_NONE][0]['target_id'])
    ? (int) $unit->field_condominio[LANGUAGE_NONE][0]['target_id']
    : NULL;
  if ($unit_condominium !== $values['condominium_id']) {
    myapi_error('invalid_field', 422, ['@field' => 'identity.unit.condominium_id']);
  }

  // 8. Access. Non-strict in_array() like the app's create, because
  // myapi_unit_related_nids() returns nids as strings.
  if (!in_array($values['unit_nid'], myapi_unit_related_nids($values['uid']))) {
    myapi_error('unit_access_denied', 403);
  }

  // 9. The forced payment method must exist in the field's allowed_values. If
  // it does not, somebody renamed the key in the back office — which is NOT
  // n8n's fault, so this is a 500 and a watchdog entry rather than a 422 that
  // would send the bot hunting for an error in a payload that has none.
  $method_field = field_info_field(MYAPI_PAYMENT_METHOD_FIELD);
  $allowed = ($method_field && isset($method_field['settings']['allowed_values']))
    ? $method_field['settings']['allowed_values']
    : [];
  if (!array_key_exists(MYAPI_BOT_PAYMENT_METHOD, $allowed)) {
    watchdog(
      'myapi',
      'Bot payment refused: "@method" is not an allowed value of @field.',
      [
        '@method' => MYAPI_BOT_PAYMENT_METHOD,
        '@field'  => MYAPI_PAYMENT_METHOD_FIELD,
      ],
      WATCHDOG_ERROR
    );
    myapi_error('server_error', 500);
  }

  // 10. The same receipt sent twice in two different messages is a duplicate,
  // not a retry: the retry was already answered in step 5.
  if (myapi_payment_reference_exists($values['reference'], $values['unit_nid'])) {
    myapi_error('duplicate_reference', 409);
  }

  // 11. The image. Required here and optional in the app: a bot payment with
  // no receipt cannot be verified by anyone, so the flow must fail loudly
  // instead of creating blind payments.
  if (empty($_FILES['file']['name'])) {
    myapi_error('missing_file', 422);
  }
  $file = myapi_payment_save_file();

  // 12. Node and ledger row together, or neither. The primary key is what
  // makes this safe against two simultaneous retries: the loser's INSERT
  // throws, its whole transaction rolls back — the node never comes to
  // exist — and it answers 200 with the winner's payment.
  //
  // The node is BUILT before the transaction opens and only saved inside it.
  // Building touches nothing — it fills an object in memory — so what the
  // transaction wraps is exactly what the spec asks it to wrap: node_save()
  // and the ledger INSERT, and nothing that cannot be undone by a rollback.
  $node = myapi_payment_build_node(
    $values['uid'],
    $values['unit_nid'],
    $values['reference'],
    $values['amount'],
    MYAPI_BOT_PAYMENT_METHOD,
    NULL,
    $values['date'],
    $file
  );
  myapi_bot_payment_decorate_node($node, $payload, $values['issuing_bank'], $values['destination_bank']);

  $tx = db_transaction();
  try {
    node_save($node);

    db_insert('myapi_bot_payments')
      ->fields([
        'idempotency_key' => $key,
        'message_key'     => $values['message_key'],
        'media_ref'       => $values['media_ref'],
        'nid'             => (int) $node->nid,
        'uid'             => $values['uid'],
        'unit_nid'        => $values['unit_nid'],
        'created'         => REQUEST_TIME,
      ])
      ->execute();
  }
  catch (Exception $e) {
    $tx->rollback();

    // Re-read: if another request got there first, this is the race and the
    // right answer is its payment. myapi_bot_payment_resolve_existing() exits
    // when it finds one.
    myapi_bot_payment_resolve_existing($key);

    // No winner, so it was not a race but a real failure.
    watchdog(
      'myapi',
      'Bot payment failed for message @message_key: @error',
      [
        '@message_key' => $values['message_key'],
        '@error'       => $e->getMessage(),
      ],
      WATCHDOG_ERROR
    );
    myapi_error('server_error', 500);
  }
  unset($tx);

  // 13. Tie the file to the node now that the node is committed, so cron does
  // not reap it. After the commit and not inside: a rolled-back loser leaves
  // the receipt as a managed file with no usage, which cron collects.
  file_usage_add($file, 'myapi', 'node', $node->nid);

  // 14. The SPEC 80 email to the 'backend' role, OUTSIDE the transaction and
  // on purpose. Inside, a losing retry would enqueue a mail that then gets
  // rolled back — or worse, one that outlives a node which never existed.
  // NULL bank term: a bot payment has none.
  myapi_payment_notify_created($node, $unit, NULL, $file);

  // 15. The app's exact 201 body. Same keys, same mapper, same order.
  myapi_bot_payment_respond($node, 201);
}
