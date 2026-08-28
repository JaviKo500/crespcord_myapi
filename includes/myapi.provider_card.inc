<?php

/**
 * @file
 * The provider CARD: the eight keys GET /api/v1/providers answers per provider
 * (SPECS 83 and 89).
 *
 * The card used to live in resources/provider.resource.inc, which is where it
 * was born and where it belonged while the provider's listing and the
 * provider's detail were its only two readers. It moved here the day a THIRD
 * reader appeared, and that reader is in another resource: the detail of a
 * service request answers `assigned_provider` as a whole card — the very same
 * eight keys, built by the very same function — so that the app paints the
 * awarded provider with the widget it already has for the marketplace.
 *
 * WHY A MOVE AND NOT A CALL. Rule 5 of CLAUDE.md forbids a resource reaching
 * into another resource's internals, and Rule 3 says what to do instead: the
 * shared code goes to includes/. Copying myapi_provider_build_item() into
 * includes/myapi.service_request_detail.inc would have been a SECOND definition
 * of what a provider looks like, drifting from the day one of the two changed —
 * and the first symptom of that drift is a card in the marketplace showing a
 * rating the awarded provider's card does not.
 *
 * The two functions moved VERBATIM and kept their names, so every call site in
 * resources/provider.resource.inc is the one it always was and the suites of
 * SPECS 83, 84 and 97 pass with no assertion touched. That is the proof the
 * move carried no behaviour with it.
 *
 * THE THIRD FUNCTION IS NEW, and it is the one this file exists to make
 * possible: myapi_provider_card_by_nid() answers the card of ONE provider, for
 * a caller that holds a nid and nothing else. The listing has its own query
 * (myapi_provider_fetch(), which pages and orders) and the provider's detail
 * has its own (myapi_provider_detail_fetch(), which also reads the address, the
 * long description and the phone); neither is what a service request needs, and
 * both stay where they are.
 *
 * NO "ACTIVE" CONDITION IN THIS FILE, deliberately — see the docblock of
 * myapi_provider_card_by_nid(). "Active" is the rule of the MARKETPLACE, and it
 * is applied by the caller that lists hireable providers, never by the builder
 * of a card.
 *
 * Like the rest of this module's read paths, the query reads the field_data_*
 * tables directly instead of going through node_load(), with the trade-off
 * docs/provider.md documents: it assumes the current field storage.
 */

// For MYAPI_SERVICES_PROVIDER_TYPE: the bundle machine name is never written as
// a literal here.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// For myapi_text_to_plain(): every string of a card travels as plain text, the
// single criterion the provider resource already followed — see
// myapi_provider_build_item().
module_load_include('inc', 'myapi', 'includes/myapi.text');

/**
 * The categories of a whole page of providers, in ONE query (SPEC 83).
 *
 * TWO QUERIES PER REQUEST, NOT ONE PER PROVIDER. With the nids of the page
 * already in memory, a single grouped read answers the categories of all twenty
 * providers; resolving them inside the main query is impossible for the reason
 * the filter is an EXISTS (it would multiply rows), and one query per provider
 * would be twenty-one reads per page. Same shape myapi_bulletin_list() uses
 * with file_load_multiple().
 *
 * A tid that no longer exists as a term — the field row left behind by a term
 * deleted in the back office — is DROPPED by the inner join on
 * taxonomy_term_data: the category vanishes from that provider's list and the
 * provider is answered just the same. Failing closed and in silence, exactly
 * what myapi_service_category_build_item() does with half an icon. Excluding
 * the provider instead would make a term deletion silently remove companies
 * from the marketplace.
 *
 * The code is left-joined and not inner-joined: a term with no
 * field_category_code travels as `code: ""`, which is what
 * /api/v1/service-categories already answers for the same term. Hiding the
 * category would make it disappear from the provider's card with no trace.
 *
 * @param array $nids
 *   The provider nids of the page. An empty list answers an empty map with no
 *   query at all.
 *
 * @return array
 *   Map of (int) nid => list of {id, code, name} items, in Field API delta
 *   order. EVERY REQUESTED NID IS A KEY, with an empty list when the provider
 *   has no category, so the caller never has to decide what a missing entry
 *   means.
 */
function myapi_provider_categories_by_nid(array $nids) {
  if (empty($nids)) {
    return [];
  }

  // Every requested nid starts empty; the query then fills the ones that have
  // categories. Same criterion as myapi_service_category_provider_counts().
  $map = [];
  foreach ($nids as $nid) {
    $map[(int) $nid] = [];
  }

  $query = db_select('field_data_field_categories', 'fc');
  $query->condition('fc.entity_type', 'node');
  $query->condition('fc.deleted', 0);
  $query->condition('fc.entity_id', array_keys($map), 'IN');
  // INNER: an orphan tid is dropped here, quietly and on purpose.
  $query->innerJoin('taxonomy_term_data', 't', 't.tid = fc.field_categories_tid');
  $query->leftJoin('field_data_field_category_code', 'cc', "cc.entity_id = t.tid AND cc.entity_type = 'taxonomy_term' AND cc.deleted = 0");
  $query->addField('fc', 'entity_id', 'nid');
  $query->addField('t', 'tid', 'tid');
  $query->addField('t', 'name', 'name');
  $query->addField('cc', 'field_category_code_value', 'code');
  // Delta order, the order the operator left in the form — never alphabetical.
  // Same criterion as the gallery of SPEC 82.
  $query->orderBy('fc.delta', 'ASC');

  foreach ($query->execute() as $row) {
    // myapi_text_to_plain() on both, the single criterion every string of this
    // resource follows: the consumer is a Flutter Text widget and there is no
    // HTML context to escape for. See myapi_provider_build_item().
    $map[(int) $row->nid][] = [
      'id'   => (int) $row->tid,
      'code' => myapi_text_to_plain(isset($row->code) ? $row->code : ''),
      'name' => myapi_text_to_plain($row->name),
    ];
  }

  return $map;
}

/**
 * Shapes one provider row into the item of the listing (SPEC 83).
 *
 * ALWAYS THE SAME EIGHT KEYS, IN THIS ORDER — id, logo, title, categories,
 * rating_avg, rating_count, short_description, hourly_rate — because that order
 * is the one drupal_json_encode() prints and the one docs/provider.md shows. No
 * key is ever added or dropped by the data: a provider with no logo, no rating,
 * no rate, no description and no category answers all eight just the same.
 *
 * THE DETAIL INHERITS EVERY ONE OF THEM. GET /api/v1/providers/% builds its
 * first keys by calling this very builder (SPEC 84), so `logo` appeared on both
 * endpoints with a single change and the two routes CANNOT diverge — no card of
 * the listing can show a logo the record then fails to show. That is why the key
 * is added here and not twice, once per endpoint.
 *
 * Nothing else of the detail travels: no phone, no address, no
 * field_services_desc and no tags. That is not an omission, it is the scope line
 * between the listing and GET /api/v1/providers/% (SPEC 83, "Fuera de alcance").
 * The GALLERY does not travel either, and for a different reason: those files
 * are private and have a route of their own. The logo does travel because it is
 * public (SPEC 85) and it is what the card of the listing is for.
 *
 * Five typing decisions live here, and each one has a symptom behind it:
 *
 * - `logo` is an ABSOLUTE, DIRECT URL to the file or NULL, never "" and never a
 *   broken URL. It is NOT an api/v1/... path, unlike the gallery: field_logo is
 *   public storage, so the web server serves it and a bare Flutter
 *   Image.network paints it with no Authorization header at all. Same shape
 *   `icon_url` already has in GET /api/v1/service-categories.
 * - `rating_avg` is float or NULL, never 0.0. Zero is OUTSIDE the 1–5 scale of
 *   myapi_services_star_values(), so it would be an impossible value travelling
 *   as if it were real, and the app would paint five empty stars where it
 *   should paint "not rated yet".
 * - `rating_count` is an int and 0 when there is nothing to count, never NULL.
 *   The two keys are not the same question: "how many ratings" always has a
 *   numeric answer and "what does it score" does not. It is also the key the
 *   app must read to tell "not rated" from "rated badly".
 * - `hourly_rate` is float or NULL, never the string "25.50" and never 0.0: an
 *   unpublished rate is not a free service. Same treatment `amount` gets in
 *   expenses, receipts and payments. The prefix '$ ' of SPEC 81 belongs to the
 *   back-office form and never travels.
 * - `title` and `short_description` go through myapi_text_to_plain(), like
 *   EVERY string this resource answers — the categories, the tags, the address,
 *   the long description and the author of a rating included. They used to be
 *   check_plain()'d, and that was the wrong helper in the wrong place: escaping
 *   belongs to a rendering context, and a JSON body read by a Flutter Text
 *   widget is not one. The symptom was a provider named `Luz & Cía` travelling
 *   as `Luz &amp; Cía` and being painted with the entity visible, since Flutter
 *   decodes nothing. One criterion for the whole resource also removes the
 *   older split where `address` and `description` arrived as plain text and
 *   `title` did not, in the same payload.
 *
 *   Nothing is lost on the safety side: myapi_text_to_plain() strips tags
 *   before decoding entities, so no markup survives it either — see the
 *   reasoning written in includes/myapi.text.inc. It costs a strip_tags() on
 *   fields that carry no markup (field_short_description is a `text` field with
 *   no text_processing, SPEC 81) and normalises their whitespace as a side
 *   effect; both are worth one rule instead of two.
 *
 * @param object $row
 *   One row of myapi_provider_fetch(), carrying the aliases `nid`, `title`,
 *   `rating_avg`, `rating_count`, `short_description`, `hourly_rate` and
 *   `logo_uri`. Every field column arrives from a LEFT JOIN, so any of them may
 *   be NULL.
 * @param array $categories
 *   The categories of THIS provider, already built as {id, code, name} items
 *   and in delta order. Passed in and not looked up: resolving them here would
 *   mean one query per provider, and the whole page is meant to cost two.
 *   Defaults to an empty list, which is also the honest answer for a provider
 *   that has none.
 *
 * @return array
 *   The eight keys above, in that order.
 */
function myapi_provider_build_item($row, array $categories = []) {
  $rating_avg = isset($row->rating_avg) && $row->rating_avg !== ''
    ? (float) $row->rating_avg
    : NULL;

  $hourly_rate = isset($row->hourly_rate) && $row->hourly_rate !== ''
    ? (float) $row->hourly_rate
    : NULL;

  $short_description = isset($row->short_description) ? $row->short_description : '';

  // Same emptiness guard as rating_avg and hourly_rate above — isset() AND
  // !== '' — so a provider with no logo, or one whose field row points at a
  // file_managed that no longer exists, answers NULL and never "" nor a broken
  // URL. The app has one empty case to handle, not two.
  $logo = isset($row->logo_uri) && $row->logo_uri !== ''
    ? file_create_url($row->logo_uri)
    : NULL;

  return [
    'id'                => (int) $row->nid,
    'logo'              => $logo,
    'title'             => myapi_text_to_plain($row->title),
    'categories'        => $categories,
    'rating_avg'        => $rating_avg,
    // (int) of NULL is 0, which is exactly the answer wanted: the cast is the
    // rule, not a coincidence to be tidied away later.
    'rating_count'      => (int) (isset($row->rating_count) ? $row->rating_count : 0),
    'short_description' => myapi_text_to_plain($short_description),
    'hourly_rate'       => $hourly_rate,
  ];
}

/**
 * The card of ONE provider, from its nid alone (SPEC 89).
 *
 * TWO QUERIES AND NOT ONE: the row of the provider, and then its categories
 * through myapi_provider_categories_by_nid(). The second cannot ride in the
 * first for the reason SPEC 83 already wrote — field_categories is unlimited,
 * so joining it would multiply the row and the caller would have to fold it
 * back, which is exactly the code that function already is.
 *
 * TWO QUERIES ARE THE WHOLE PRICE, AND THEY ARE PAID ONLY WHEN THERE IS AN
 * AWARD. The detail of a service request calls this once and only when
 * `assigned_provider` is not NULL; a request with no provider awarded costs
 * nothing at all. That is what keeps a card that used to be two keys from
 * becoming a per-row cost in a listing: no listing calls this function, and
 * none should — a page of twenty requests would be forty queries. For a page,
 * the shape to write is the one myapi_provider_fetch() already has.
 *
 * THE PUBLISHED FLAG IS THE ONLY CONDITION BESIDES THE BUNDLE, and the absence
 * of the licence check is a decision:
 *
 *   - `status = 1` MATCHES THE CALLER'S OWN JOIN. myapi_service_request_detail_row()
 *     resolves `assigned_provider_id` through a join that already carries
 *     `np.status = 1`, so a provider unpublished in the back office leaves that
 *     key NULL and this function is never called for it. Repeating the flag
 *     here keeps the two in agreement if one of them is ever called alone.
 *   - NO myapi_provider_apply_active_conditions(). An AWARDED provider whose
 *     licence expired last week is still the provider doing the job, and the
 *     resident must keep seeing whom they hired. Hiding it would answer
 *     `assigned_provider: null` on a request that plainly has one, which reads
 *     as "nobody was awarded" and is a lie about the state of the request. The
 *     marketplace rule — only active providers are LISTED — is about who may be
 *     hired, not about who already was, and the two must be able to diverge.
 *
 * The row is fetched with the SAME aliases myapi_provider_fetch() projects, and
 * that is not a coincidence to be tidied away: myapi_provider_build_item()
 * reads those names, and a card built from a differently-named row would be a
 * card with nulls where the data is.
 *
 * @param mixed $provider_id
 *   The provider nid. Anything that is not a positive integer answers NULL with
 *   no query at all — the same guard every loader of this module opens with.
 *
 * @return array|NULL
 *   The eight keys of myapi_provider_build_item(), or NULL when no PUBLISHED
 *   node of bundle 'provider' carries that nid.
 */
function myapi_provider_card_by_nid($provider_id) {
  if (!is_scalar($provider_id) || (int) $provider_id <= 0) {
    return NULL;
  }
  $provider_id = (int) $provider_id;

  $query = db_select('node', 'n');
  $query->fields('n', ['nid', 'title']);
  $query->condition('n.nid', $provider_id);
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  $query->condition('n.status', 1);

  // Every field join is LEFT, the same criterion myapi_provider_fetch() states
  // at length: a provider with no rating, no rate, no description or no logo
  // still has a card, with NULLs in what it does not have.
  $query->leftJoin('field_data_field_rating_avg', 'ra', "ra.entity_id = n.nid AND ra.entity_type = 'node' AND ra.deleted = 0");
  $query->addField('ra', 'field_rating_avg_value', 'rating_avg');

  $query->leftJoin('field_data_field_rating_count', 'rc', "rc.entity_id = n.nid AND rc.entity_type = 'node' AND rc.deleted = 0");
  $query->addField('rc', 'field_rating_count_value', 'rating_count');

  $query->leftJoin('field_data_field_short_description', 'sd', "sd.entity_id = n.nid AND sd.entity_type = 'node' AND sd.deleted = 0");
  $query->addField('sd', 'field_short_description_value', 'short_description');

  $query->leftJoin('field_data_field_hourly_rate', 'hr', "hr.entity_id = n.nid AND hr.entity_type = 'node' AND hr.deleted = 0");
  $query->addField('hr', 'field_hourly_rate_value', 'hourly_rate');

  // The logo of SPEC 85, in the same two chained LEFT joins the listing uses
  // and under the same alias, so both read it from the same column name.
  $query->leftJoin('field_data_field_logo', 'lg', "lg.entity_id = n.nid AND lg.entity_type = 'node' AND lg.deleted = 0");
  $query->leftJoin('file_managed', 'fm', 'fm.fid = lg.field_logo_fid');
  $query->addField('fm', 'uri', 'logo_uri');

  $query->range(0, 1);

  $row = $query->execute()->fetchObject();
  if (!$row) {
    return NULL;
  }

  $categories = myapi_provider_categories_by_nid([$provider_id]);

  return myapi_provider_build_item(
    $row,
    isset($categories[$provider_id]) ? $categories[$provider_id] : []
  );
}
