<?php

/**
 * @file
 * Area resource for the myapi module.
 *
 * Lists 'area' nodes of a condominium for a user who has access to it.
 * Read-only, paginated, sorted by title. Mirrors payment.resource.inc, except
 * the visibility criterion is by inclusion: only areas whose field_area_status
 * is one of MYAPI_AREA_VISIBLE_STATUSES are exposed, and an area with no status
 * row is hidden (inner join with an IN condition).
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
 * Fetches a page of visible 'area' nodes for a condominium, with every field.
 *
 * Shares the same base query and inclusion criteria as myapi_area_count()
 * (published 'area', matching field_condominium, visible field_area_status),
 * then left-joins every mapped field and file_managed (for the image uri) so
 * one query returns the whole response shape. Ordered by title in the
 * requested direction with n.nid as a deterministic tie-breaker in the same
 * direction, so rows sharing a title stay stable across pages.
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
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'area')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_condominium', 'fcon', "fcon.entity_id = n.nid AND fcon.entity_type = 'node' AND fcon.deleted = 0");
  $query->condition('fcon.field_condominium_target_id', $condominium_id);
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
