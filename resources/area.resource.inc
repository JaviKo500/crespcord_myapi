<?php

/**
 * @file
 * Area resource for the myapi module.
 *
 * Lists 'area' nodes of a condominium for a user who has access to it
 * (GET /api/v1/condominiums/%/areas), and returns a single area by id
 * (GET /api/v1/areas/%). Read-only. The list is paginated and sorted by title;
 * both endpoints share the same visibility criterion and column projection via
 * myapi_area_base_select(). Mirrors payment.resource.inc, except the visibility
 * criterion is by inclusion: only areas whose field_area_status is one of
 * MYAPI_AREA_VISIBLE_STATUSES are exposed, and an area with no status row is
 * hidden (inner join with an IN condition). The list also takes an optional
 * ?search over the area name and the category label. The detail endpoint reuses that
 * same criterion, so a hidden area is a non-revealing 404, just as it is
 * absent from the list.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
module_load_include('inc', 'myapi', 'includes/myapi.reservation_query');
module_load_include('inc', 'myapi', 'includes/myapi.text');

/**
 * Area statuses that are visible to the app (inclusion criterion).
 *
 * The criterion is by inclusion: only areas whose field_area_status is one of
 * these values are exposed. 'closed' and any area with no status row are
 * hidden, because "no status" is not a safe state to show.
 */
define('MYAPI_AREA_VISIBLE_STATUSES', ['active', 'maintenance']);

/**
 * Dispatcher for /api/v1/condominiums/%/areas.
 *
 * Routes by HTTP method. Only GET is supported; any other method returns 405.
 *
 * @param int $condominium_id  The 'condominium' nid from the route.
 */
function myapi_area_dispatch($condominium_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_area_list($condominium_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/areas/%.
 *
 * Routes by HTTP method. Only GET is supported (read one area); any other
 * method returns 405. Authentication and the rest of the validations are
 * resolved inside myapi_area_details(), not here.
 *
 * @param int $area_id  The 'area' nid from the route.
 */
function myapi_area_details_dispatch($area_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_area_details($area_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/areas/%/availability.
 *
 * Routes by HTTP method. Only GET is supported (read the busy time ranges of an
 * area for a day); any other method returns 405. Authentication and the rest of
 * the validations are resolved inside myapi_area_availability(), not here.
 *
 * @param int $area_id  The 'area' nid from the route.
 */
function myapi_area_availability_dispatch($area_id) {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_area_availability($area_id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/condominiums/%/areas.
 *
 * Returns, for the authenticated user, a paginated list of visible 'area'
 * nodes belonging to $condominium_id, provided the user is related to that
 * condominium (same access rule as myapi_payment_list()). "No access" and
 * "condominium does not exist" collapse into the same 403, since access is
 * resolved with a lax in_array() against myapi_condominium_related_nids()
 * without a separate existence check.
 *
 * ?search filters the list by area NAME or by the label of its CATEGORY,
 * case- and accent-insensitively, and keeps paginating (see
 * myapi_area_apply_search()). ?page, ?limit and ?sort keep their meaning over
 * the narrowed set.
 *
 * @param int $condominium_id  The 'condominium' nid from the route.
 */
function myapi_area_list($condominium_id) {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  $nids = myapi_condominium_related_nids($uid);
  if (!in_array((int) $condominium_id, $nids)) {
    myapi_error('condominium_access_denied', 403);
  }

  $page = myapi_parse_page_param();
  $limit = myapi_parse_limit_param(TRUE);

  if ($limit === -1) {
    $page = 1;
  }

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  // The needle, taken RAW (only trimmed) and never folded here: it travels to
  // SQL as a LIKE over n.title, where the accent- and case-insensitivity is the
  // column collation's job (utf8_general_ci on a standard Drupal 7 install).
  // Folding it first would be strictly worse — under an accent-SENSITIVE
  // collation "Jardín" would stop finding "Jardín". The folding that this
  // module does control happens one level down, over the category labels.
  // Absent, non-scalar or blank (spaces only) all mean "no search": a search
  // box the user cleared must answer the whole list, not an empty one.
  $search = isset($_GET['search']) && is_scalar($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

  // Searching does NOT turn pagination off here, unlike the service-category
  // catalogue (SPEC 119). That catalogue is ~40 fixed rows and is answered
  // whole; this list is per-condominium node data the client already reads page
  // by page, so a search narrows the SAME paginated set: `total` and
  // `total_pages` describe the matches, not the whole condominium.
  $total = myapi_area_count($condominium_id, $search);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_area_fetch($condominium_id, $page, $limit, $sort, $search);
  $areas = array_map('myapi_area_build_item', $rows);

  myapi_respond([
    'areas' => $areas,
    'pagination' => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * Handles GET /api/v1/areas/%.
 *
 * Returns the single visible 'area' node identified by $area_id, in the same
 * 15-key item shape as the list (GET /api/v1/condominiums/%/areas), wrapped as
 * {"area": ...}. The area is only visible when it would also appear in the
 * caller's list: it must be a published 'area' node with a visible
 * field_area_status (MYAPI_AREA_VISIBLE_STATUSES) and belong to a condominium
 * the caller is related to (same access rule as myapi_area_list()).
 *
 * Every "not visible to you" case — invalid id, missing/unpublished node, a
 * non-'area' node, a hidden status ('closed' or no status row), or an area in
 * a condominium the caller is not related to — collapses into the same
 * 404 area_not_found, so the endpoint never reveals whether an area exists or
 * in which condominium it lives, mirroring the list where those areas are
 * simply invisible.
 *
 * @param mixed $area_id  The 'area' nid from the route.
 */
function myapi_area_details($area_id) {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  if (!ctype_digit((string) $area_id) || (int) $area_id <= 0) {
    myapi_error('area_not_found', 404);
  }

  // No row means: nonexistent, not an 'area', unpublished, or a hidden status
  // (the base select's inner join on field_area_status filters it out).
  $area = myapi_area_fetch_one((int) $area_id);
  if (!$area) {
    myapi_error('area_not_found', 404);
  }

  // Same access rule as the list: the caller must be related to the area's
  // condominium. Collapses into the same 404 (non-revealing), mirroring the
  // list where another condominium's areas never appear.
  if (!in_array((int) $area->condominium_id, myapi_condominium_related_nids($uid))) {
    myapi_error('area_not_found', 404);
  }

  myapi_respond(['area' => myapi_area_build_item($area)], 200);
}

/**
 * Handles GET /api/v1/areas/%/availability.
 *
 * Read-only. Reports what a visible area has taken for a given day, derived
 * from every confirmed, published reservation of the area (all units of the
 * condominium), including those that cross midnight. The requester only learns
 * that a slot is taken, never by whom (no id/unit_id/requester_id/names).
 *
 * Responds with 4 keys (SPEC 45 turned the original {date, busy} into this;
 * the change is additive and 'date'/'busy' keep their name and shape):
 *   - date       — the requested day, echoed back as received.
 *   - capacity   — the area's effective capacity, always an int >= 1.
 *   - busy       — ranges the caller CANNOT book (see the split below).
 *   - occupancy  — every constant-occupancy range with its reserved/remaining
 *                  counters; always present, capacity 1 included.
 *
 * Access mirrors the area detail (SPEC 39): a nonexistent, unpublished, hidden
 * or foreign-condominium area collapses into the same non-revealing 404. The
 * 'date' query param is validated strictly (YYYY-MM-DD + checkdate): absent is
 * 422 missing_field, malformed or non-calendar is 422 invalid_field.
 *
 * @param mixed $area_id  The 'area' nid from the route.
 */
function myapi_area_availability($area_id) {
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  if (!ctype_digit((string) $area_id) || (int) $area_id <= 0) {
    myapi_error('area_not_found', 404);
  }

  // Same non-revealing criterion as the area detail: no row means nonexistent,
  // not an 'area', unpublished, or a hidden status.
  $area = myapi_area_fetch_one((int) $area_id);
  if (!$area) {
    myapi_error('area_not_found', 404);
  }

  if (!in_array((int) $area->condominium_id, myapi_condominium_related_nids($uid))) {
    myapi_error('area_not_found', 404);
  }

  $date = isset($_GET['date']) ? $_GET['date'] : NULL;
  if (!is_string($date) || $date === '') {
    myapi_error('missing_field', 422, ['@field' => 'date']);
  }
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $date_parts)
    || !checkdate((int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1])) {
    myapi_error('invalid_field', 422, ['@field' => 'date']);
  }

  // Availability is reported per SESSION (SPEC 41 follow-up): availability for
  // day D covers the area's operating window [D open, D+1 close]. For a normal
  // area that is just the calendar day D; for an area that closes after
  // midnight it also includes the early-morning tail stored under D+1's clock
  // day. The derivation needs the area's open/close to tell the tail of this
  // session apart from the evening of the next one, so both are parsed here and
  // the next day is fetched only when the area wraps.
  $open_min = $area->open_time !== NULL ? myapi_reservation_time_to_minutes($area->open_time) : NULL;
  $close_min = $area->close_time !== NULL ? myapi_reservation_time_to_minutes($area->close_time) : NULL;
  $wraps = $open_min !== NULL && $close_min !== NULL && $close_min <= $open_min;

  $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
  $fetch_dates = $wraps ? [$date, $next_date] : [$date];

  $rows = myapi_reservation_busy_rows((int) $area_id, $fetch_dates);
  $busy = myapi_reservation_busy_ranges($rows, $date, $next_date, $open_min, $close_min);

  // Occupancy per constant-occupancy range (SPEC 45), always reported — also in
  // capacity-1 areas, where it is redundant with 'busy', so the client never
  // has to branch on capacity to read the response. Counters only: the privacy
  // rule above still holds.
  $capacity = myapi_reservation_effective_capacity($area->max_concurrent_reservations);
  $occupancy = myapi_reservation_occupancy_ranges($busy, $capacity);

  // 'busy' keeps its meaning — "you cannot book this" — but is derived
  // differently depending on the capacity, and that split is DELIBERATE.
  //
  // Capacity 1: 'busy' stays byte for byte what it has always been, one entry
  // per reservation. Deriving blocks for every area instead would merge two
  // consecutive bookings (10:00-11:00 and 11:00-12:00) into a single
  // 10:00-12:00 block, silently changing the payload of every area in
  // production. Do not "unify this for cleanliness".
  //
  // Capacity > 1: a range with free slots is still bookable, so it must NOT be
  // in 'busy'. Only saturated ranges (reserved >= capacity) are, with
  // contiguous ones merged into a single block.
  if ($capacity > 1) {
    $busy = [];
    foreach ($occupancy as $range) {
      if ($range['reserved'] < $capacity) {
        continue;
      }
      $last = count($busy) - 1;
      if ($last >= 0
        && $busy[$last]['end_date'] === $range['start_date']
        && $busy[$last]['end_time'] === $range['start_time']) {
        // Contiguous with the previous block: extend it instead of emitting a
        // second entry. Safe because occupancy comes sorted ascending.
        $busy[$last]['end_date'] = $range['end_date'];
        $busy[$last]['end_time'] = $range['end_time'];
      }
      else {
        $busy[] = [
          'start_date' => $range['start_date'],
          'start_time' => $range['start_time'],
          'end_date' => $range['end_date'],
          'end_time' => $range['end_time'],
        ];
      }
    }
  }

  myapi_respond(['date' => $date, 'capacity' => $capacity, 'busy' => $busy, 'occupancy' => $occupancy], 200);
}

/**
 * Counts published, visible 'area' nodes for a condominium, unpaginated.
 *
 * Visibility is by inclusion: field_area_status is an inner join with an IN
 * condition over MYAPI_AREA_VISIBLE_STATUSES, so an area whose status is not
 * visible (e.g. 'closed') or which has no status row at all is excluded. The
 * count therefore matches the same filtered set as the fetched page — which is
 * why $search has to reach here too: a search that narrowed the page but not
 * the count would report pages the client cannot reach.
 *
 * @param int    $condominium_id  The 'condominium' nid to filter by.
 * @param string $search          Raw, trimmed ?search needle, or '' for none.
 *
 * @return int  Total number of matching areas.
 */
function myapi_area_count($condominium_id, $search = '') {
  $query = db_select('node', 'n')
    ->condition('n.type', 'area')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_condominium', 'fcon', "fcon.entity_id = n.nid AND fcon.entity_type = 'node' AND fcon.deleted = 0");
  $query->condition('fcon.field_condominium_target_id', $condominium_id);

  // Status is an inner join with an IN condition: only visible statuses are
  // counted, and an area with no status row is excluded, so the count matches
  // the fetched, filtered set.
  $query->innerJoin('field_data_field_area_status', 'fsta', "fsta.entity_id = n.nid AND fsta.entity_type = 'node' AND fsta.deleted = 0");
  $query->condition('fsta.field_area_status_value', MYAPI_AREA_VISIBLE_STATUSES, 'IN');

  // The counting query carries none of the projection joins, so the category
  // one is added here and ONLY when there is something to search: an unfiltered
  // count keeps the exact three-join query it has always run.
  if ($search !== '') {
    $query->leftJoin('field_data_field_area_category', 'fcat', "fcat.entity_id = n.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0");
    myapi_area_apply_search($query, $search);
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Applies the ?search filter to an area query (name OR category).
 *
 * Shared by the count and the fetch so both answer the same set. The caller
 * MUST have joined field_data_field_area_category as 'fcat' (the base select
 * already does; myapi_area_count() adds it on demand).
 *
 * The two halves are matched by different machinery, on purpose:
 *
 *  - The NAME is the node title, free text an operator typed, so it is matched
 *    in SQL with a LIKE over the raw needle. Case- and accent-insensitivity
 *    come from the column collation (utf8_general_ci on a standard Drupal 7
 *    install), which is why the needle is not folded first.
 *  - The CATEGORY is a list_text field whose stored value is a machine key
 *    ('pool'), while the user types the Spanish label ("piscina"). No LIKE can
 *    bridge that, so the labels are resolved to keys in PHP first
 *    (myapi_area_category_keys_matching()) and the query filters by an IN over
 *    those keys. That resolution is where myapi_text_fold() earns its keep:
 *    "jardin" finds "Zona verde / Jardín".
 *
 * The two are OR-ed: one needle, either field, mirroring how ?search reads to
 * the person typing it — "show me what has this in it".
 *
 * @param SelectQuery $query   The query to narrow, with 'n' and 'fcat' joined.
 * @param string      $search  Raw, trimmed needle; must be non-empty.
 */
function myapi_area_apply_search($query, $search) {
  $group = db_or();
  // db_like() escapes the % and _ a user may type, so a needle of "100%" is a
  // literal search and not a match-everything wildcard.
  $group->condition('n.title', '%' . db_like($search) . '%', 'LIKE');

  $keys = myapi_area_category_keys_matching($search);
  if (!empty($keys)) {
    $group->condition('fcat.field_area_category_value', $keys, 'IN');
  }

  $query->condition($group);
}

/**
 * Resolves a search needle to the field_area_category keys whose label matches.
 *
 * The catalogue is read from the LIVE field definition
 * (field_info_field('field_area_category')['settings']['allowed_values']) and
 * not from _myapi_area_category_allowed_values() in myapi.install, for the same
 * reason myapi_claims_field_allowed_values() does it: the install file is the
 * seed, the field is the truth, and a label an administrator edited from
 * admin/structure must stay findable. A site whose field is missing simply
 * matches no category, and the caller falls back to searching the name alone.
 *
 * Only the LABELS are searched, never the machine keys — the same call SPEC 119
 * made for the service categories. Keys exist so the app can key its icons on
 * something stable; matching them would only add hits nobody typed for (the
 * needle "other" answering every area classified as 'Otra').
 *
 * A plain folded substring test, not a word or prefix one: "cancha" finds all
 * five courts, "eria" finds "Lavandería". With ~40 short labels this is a
 * handful of microseconds and no query at all.
 *
 * @param string $search  Raw, trimmed needle.
 *
 * @return array  Machine keys to match, possibly empty.
 */
function myapi_area_category_keys_matching($search) {
  $needle = myapi_text_fold($search);
  if ($needle === '') {
    return [];
  }

  $field = field_info_field('field_area_category');
  $labels = ($field && isset($field['settings']['allowed_values']))
    ? $field['settings']['allowed_values']
    : [];

  $keys = [];
  foreach ($labels as $key => $label) {
    if (strpos(myapi_text_fold($label), $needle) !== FALSE) {
      $keys[] = $key;
    }
  }

  return $keys;
}

/**
 * Builds the base select shared by the area list and the area detail.
 *
 * Encapsulates the visibility criterion and the full column projection so both
 * endpoints return the exact same shape from the exact same joins: published
 * 'area' nodes, field_area_status inner-joined with an IN over
 * MYAPI_AREA_VISIBLE_STATUSES (an area whose status is not visible, or which
 * has no status row, is excluded), then a left join per mapped field plus
 * file_managed for the image uri. It deliberately does NOT restrict by
 * condominium and does NOT order or paginate: the list adds the
 * field_condominium condition, ordering and range; the detail adds the n.nid
 * condition. 'condominium_id' is projected so the detail can enforce access.
 *
 * @return SelectQuery  A select ready for the caller to add its own
 *                      condition/order/range on, mapped by myapi_area_build_item().
 */
function myapi_area_base_select() {
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'area')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_condominium', 'fcon', "fcon.entity_id = n.nid AND fcon.entity_type = 'node' AND fcon.deleted = 0");
  $query->addField('fcon', 'field_condominium_target_id', 'condominium_id');

  // Status is an inner join with an IN condition: only visible statuses are
  // returned, and an area with no status row is excluded.
  $query->innerJoin('field_data_field_area_status', 'fsta', "fsta.entity_id = n.nid AND fsta.entity_type = 'node' AND fsta.deleted = 0");
  $query->condition('fsta.field_area_status_value', MYAPI_AREA_VISIBLE_STATUSES, 'IN');
  $query->addField('fsta', 'field_area_status_value', 'status');

  $query->leftJoin('field_data_field_image', 'fimg', "fimg.entity_id = n.nid AND fimg.entity_type = 'node' AND fimg.deleted = 0");
  $query->addField('fimg', 'field_image_fid', 'image_id');
  // file_managed for the image uri, so image_url is built without a per-row
  // file_load(). NULL together with image_id when the area has no image.
  $query->leftJoin('file_managed', 'fm', 'fm.fid = fimg.field_image_fid');
  $query->addField('fm', 'uri', 'image_uri');

  $query->leftJoin('field_data_field_open_time', 'fopn', "fopn.entity_id = n.nid AND fopn.entity_type = 'node' AND fopn.deleted = 0");
  $query->addField('fopn', 'field_open_time_value', 'open_time');

  $query->leftJoin('field_data_field_close_time', 'fcls', "fcls.entity_id = n.nid AND fcls.entity_type = 'node' AND fcls.deleted = 0");
  $query->addField('fcls', 'field_close_time_value', 'close_time');

  $query->leftJoin('field_data_field_slot_minutes', 'fslt', "fslt.entity_id = n.nid AND fslt.entity_type = 'node' AND fslt.deleted = 0");
  $query->addField('fslt', 'field_slot_minutes_value', 'slot_minutes');

  $query->leftJoin('field_data_field_max_minutes', 'fmax', "fmax.entity_id = n.nid AND fmax.entity_type = 'node' AND fmax.deleted = 0");
  $query->addField('fmax', 'field_max_minutes_value', 'max_minutes');

  $query->leftJoin('field_data_field_who_can_reserve', 'fwho', "fwho.entity_id = n.nid AND fwho.entity_type = 'node' AND fwho.deleted = 0");
  $query->addField('fwho', 'field_who_can_reserve_value', 'who_can_reserve');

  $query->leftJoin('field_data_field_cancel_deadline_minutes', 'fcan', "fcan.entity_id = n.nid AND fcan.entity_type = 'node' AND fcan.deleted = 0");
  $query->addField('fcan', 'field_cancel_deadline_minutes_value', 'cancel_deadline_minutes');

  $query->leftJoin('field_data_field_area_category', 'fcat', "fcat.entity_id = n.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0");
  $query->addField('fcat', 'field_area_category_value', 'category');

  $query->leftJoin('field_data_field_area_notes', 'fnot', "fnot.entity_id = n.nid AND fnot.entity_type = 'node' AND fnot.deleted = 0");
  $query->addField('fnot', 'field_area_notes_value', 'notes');

  // Concurrent capacity (SPEC 45). Left join like every optional field: an area
  // with no row yields NULL here, which myapi_reservation_effective_capacity()
  // reads as a capacity of 1 — the single-reservation behaviour those areas
  // already had.
  $query->leftJoin('field_data_field_concurrent_reservations', 'fcap', "fcap.entity_id = n.nid AND fcap.entity_type = 'node' AND fcap.deleted = 0");
  $query->addField('fcap', 'field_concurrent_reservations_value', 'max_concurrent_reservations');

  return $query;
}

/**
 * Fetches a page of visible 'area' nodes for a condominium, with every field.
 *
 * Shares the whole projection and inclusion criteria with the area detail via
 * myapi_area_base_select(), then restricts to field_condominium, applies the
 * optional ?search filter, orders by title in the requested direction with n.nid as a deterministic tie-breaker
 * in the same direction (so rows sharing a title stay stable across pages),
 * and paginates.
 *
 * @param int    $condominium_id  The 'condominium' nid to filter by.
 * @param int    $page            1-based page number.
 * @param int    $limit           Rows per page, already clamped to [1, 50], or
 *                                -1 to return every row unpaginated.
 * @param string $sort            'asc' or 'desc', applied to n.title.
 * @param string $search           Raw, trimmed ?search needle, or '' for none.
 *
 * @return array  Rows keyed numerically, one per area.
 */
function myapi_area_fetch($condominium_id, $page, $limit, $sort, $search = '') {
  $query = myapi_area_base_select();
  $query->condition('fcon.field_condominium_target_id', $condominium_id);

  // The base select already joined 'fcat', so the filter needs no extra join
  // here; it must stay in step with myapi_area_count($condominium_id, $search).
  if ($search !== '') {
    myapi_area_apply_search($query, $search);
  }

  $query->orderBy('n.title', $sort === 'asc' ? 'ASC' : 'DESC');
  // Deterministic tie-breaker: areas sharing the same title would otherwise
  // come back in an unstable order across requests (and could shift between
  // pages), because SQL does not guarantee row order for ties. nid is unique,
  // never NULL and immutable, so it makes the ordering reproducible.
  $query->orderBy('n.nid', $sort === 'asc' ? 'ASC' : 'DESC');
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }

  return $query->execute()->fetchAll();
}

/**
 * Fetches a single visible 'area' node by nid, with every mapped field.
 *
 * Uses the same myapi_area_base_select() projection and visibility criterion
 * as the list, restricted to n.nid, so the returned row maps to the identical
 * item shape via myapi_area_build_item(). Returns FALSE when the area does not
 * exist, is not published, is not an 'area' node, or has a non-visible/absent
 * status (the base select's inner join on field_area_status excludes it) — the
 * caller treats all of these as a non-revealing 404.
 *
 * @param int $area_id  The 'area' nid to load.
 *
 * @return object|false  One row, or FALSE when no visible area matches.
 */
function myapi_area_fetch_one($area_id) {
  $query = myapi_area_base_select();
  $query->condition('n.nid', $area_id);

  return $query->execute()->fetchObject();
}

/**
 * Maps one raw area row into the response shape documented for
 * GET /api/v1/condominiums/%/areas.
 *
 * 'id' and 'condominium_id' come from non-nullable columns and are always cast
 * to int. 'image_id', 'slot_minutes', 'max_minutes' and
 * 'cancel_deadline_minutes' are cast to int only when the node has the row,
 * NULL otherwise. 'image_url' is built with file_create_url() from the joined
 * file_managed uri (absolute URL, no per-row file_load), NULL together with
 * 'image_id' when the area has no image. Text/list fields (open_time,
 * close_time, status, who_can_reserve, category, notes) pass through as stored,
 * NULL when the node has no row for that field. 'notes' is the raw
 * field_area_notes_value: it may contain HTML and is never sanitized or
 * rendered here (no check_markup(), no filter_xss()).
 *
 * 'max_concurrent_reservations' (SPEC 45) is the ONE key that deviates from
 * that convention: instead of passing raw with NULL when the node has no row,
 * it is exposed already NORMALISED through
 * myapi_reservation_effective_capacity() — always an int, always >= 1. This is
 * deliberate, not an oversight. The client needs the EFFECTIVE capacity to draw
 * free slots, and re-implementing the fail-closed rule in Flutter is exactly
 * what would drift out of sync with the server's validation 6.
 *
 * @param object $area  One row from myapi_area_fetch().
 *
 * @return array  Response item with all 15 mapped keys.
 */
function myapi_area_build_item($area) {
  return [
    'id'                          => (int) $area->nid,
    'name'                        => $area->title,
    'condominium_id'              => (int) $area->condominium_id,
    'image_id'                    => $area->image_id !== NULL ? (int) $area->image_id : NULL,
    'image_url'                   => $area->image_uri !== NULL ? file_create_url($area->image_uri) : NULL,
    'open_time'                   => $area->open_time,
    'close_time'                  => $area->close_time,
    'slot_minutes'                => $area->slot_minutes !== NULL ? (int) $area->slot_minutes : NULL,
    'max_minutes'                 => $area->max_minutes !== NULL ? (int) $area->max_minutes : NULL,
    'status'                      => $area->status,
    'who_can_reserve'             => $area->who_can_reserve,
    'cancel_deadline_minutes'     => $area->cancel_deadline_minutes !== NULL ? (int) $area->cancel_deadline_minutes : NULL,
    'category'                    => $area->category,
    'notes'                       => $area->notes,
    'max_concurrent_reservations' => myapi_reservation_effective_capacity($area->max_concurrent_reservations),
  ];
}
