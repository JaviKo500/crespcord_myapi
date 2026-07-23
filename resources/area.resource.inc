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
 * hidden (inner join with an IN condition). The detail endpoint reuses that
 * same criterion, so a hidden area is a non-revealing 404, just as it is
 * absent from the list.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

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
 * Handles GET /api/v1/condominiums/%/areas.
 *
 * Returns, for the authenticated user, a paginated list of visible 'area'
 * nodes belonging to $condominium_id, provided the user is related to that
 * condominium (same access rule as myapi_payment_list()). "No access" and
 * "condominium does not exist" collapse into the same 403, since access is
 * resolved with a lax in_array() against myapi_condominium_related_nids()
 * without a separate existence check.
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

  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && $_GET['limit'] === '-1'
    ? -1
    : (isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
      ? max(1, min(50, (int) $_GET['limit']))
      : 20);

  if ($limit === -1) {
    $page = 1;
  }

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  $total = myapi_area_count($condominium_id);
  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $rows = myapi_area_fetch($condominium_id, $page, $limit, $sort);
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
 * 13-key item shape as the list (GET /api/v1/condominiums/%/areas), wrapped as
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
 * Counts published, visible 'area' nodes for a condominium, unpaginated.
 *
 * Visibility is by inclusion: field_area_status is an inner join with an IN
 * condition over MYAPI_AREA_VISIBLE_STATUSES, so an area whose status is not
 * visible (e.g. 'closed') or which has no status row at all is excluded. The
 * count therefore matches the same filtered set as the fetched page.
 *
 * @param int $condominium_id  The 'condominium' nid to filter by.
 *
 * @return int  Total number of matching areas.
 */
function myapi_area_count($condominium_id) {
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

  return (int) $query->countQuery()->execute()->fetchField();
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

  return $query;
}

/**
 * Fetches a page of visible 'area' nodes for a condominium, with every field.
 *
 * Shares the whole projection and inclusion criteria with the area detail via
 * myapi_area_base_select(), then restricts to field_condominium, orders by
 * title in the requested direction with n.nid as a deterministic tie-breaker
 * in the same direction (so rows sharing a title stay stable across pages),
 * and paginates.
 *
 * @param int    $condominium_id  The 'condominium' nid to filter by.
 * @param int    $page            1-based page number.
 * @param int    $limit           Rows per page, already clamped to [1, 50], or
 *                                -1 to return every row unpaginated.
 * @param string $sort            'asc' or 'desc', applied to n.title.
 *
 * @return array  Rows keyed numerically, one per area.
 */
function myapi_area_fetch($condominium_id, $page, $limit, $sort) {
  $query = myapi_area_base_select();
  $query->condition('fcon.field_condominium_target_id', $condominium_id);

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
 * close_time, status, who_can_reserve, category) pass through as stored, NULL
 * when the node has no row for that field.
 *
 * @param object $area  One row from myapi_area_fetch().
 *
 * @return array  Response item with all 13 mapped keys.
 */
function myapi_area_build_item($area) {
  return [
    'id'                      => (int) $area->nid,
    'name'                    => $area->title,
    'condominium_id'          => (int) $area->condominium_id,
    'image_id'                => $area->image_id !== NULL ? (int) $area->image_id : NULL,
    'image_url'               => $area->image_uri !== NULL ? file_create_url($area->image_uri) : NULL,
    'open_time'               => $area->open_time,
    'close_time'              => $area->close_time,
    'slot_minutes'            => $area->slot_minutes !== NULL ? (int) $area->slot_minutes : NULL,
    'max_minutes'             => $area->max_minutes !== NULL ? (int) $area->max_minutes : NULL,
    'status'                  => $area->status,
    'who_can_reserve'         => $area->who_can_reserve,
    'cancel_deadline_minutes' => $area->cancel_deadline_minutes !== NULL ? (int) $area->cancel_deadline_minutes : NULL,
    'category'                => $area->category,
  ];
}
