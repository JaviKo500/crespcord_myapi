<?php

/**
 * @file
 * Notification inbox resource for the myapi module.
 *
 * Endpoints (all authenticated; a user only ever sees or touches its own rows):
 *   GET  /api/v1/notifications            paginated inbox + unread_count
 *   PUT  /api/v1/notifications/%/read     mark one as read (idempotent)
 *   PUT  /api/v1/notifications/read-all   mark every unread as read
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');

/**
 * Dispatcher for /api/v1/notifications. Only GET is supported.
 */
function myapi_notification_dispatch() {
  if (myapi_request_method() === 'GET') {
    myapi_notification_list();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/notifications/%/read. Only PUT is supported.
 *
 * @param mixed $id  The notification id from the route (wildcard, not validated
 *                   by Drupal; a non-numeric value casts to 0 and 404s).
 */
function myapi_notification_read_dispatch($id) {
  if (myapi_request_method() === 'PUT') {
    myapi_notification_mark_read($id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/notifications/read-all. Only PUT is supported.
 */
function myapi_notification_read_all_dispatch() {
  if (myapi_request_method() === 'PUT') {
    myapi_notification_mark_all_read();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles GET /api/v1/notifications.
 *
 * Paginated list of the authenticated user's notifications, ordered
 * created DESC. Query params follow the lax criterion of the receipts endpoint:
 * invalid values fall back to the default, never a 422.
 *   - page        default 1.
 *   - limit       default 20, clamped to [1, 50]; -1 returns all, unpaginated.
 *   - unread      '1' returns only unread; anything else (or absent) returns all.
 *   - condominium optional condominium scope (see myapi_notification_apply_scope).
 *                 Absent/invalid => no scope filter, the full inbox (back-compat).
 *   - unit        optional unit scope; only honoured when condominium is present.
 */
function myapi_notification_list() {
  $auth = myapi_auth_require_access_token();
  $uid = $auth->uid;

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

  $unread_only = isset($_GET['unread']) && $_GET['unread'] === '1';

  // Condominium/unit scope: both lax. Only a positive integer counts; anything
  // else is treated as absent. Unit is meaningless without a condominium, so it
  // is ignored when condominium is absent.
  $condominium = isset($_GET['condominium']) && ctype_digit((string) $_GET['condominium']) && (int) $_GET['condominium'] > 0
    ? (int) $_GET['condominium']
    : NULL;
  $unit = $condominium !== NULL && isset($_GET['unit']) && ctype_digit((string) $_GET['unit']) && (int) $_GET['unit'] > 0
    ? (int) $_GET['unit']
    : NULL;

  // Total of the current (possibly filtered) set, for pagination.
  $count_query = db_select('myapi_notifications', 'n')->condition('uid', $uid);
  if ($unread_only) {
    $count_query->condition('is_read', 0);
  }
  myapi_notification_apply_scope($count_query, $condominium, $unit);
  $total = (int) $count_query->countQuery()->execute()->fetchField();

  // Unread badge count: the user's total unread within the current scope
  // (ignoring the unread filter and pagination, but honouring condominium/unit).
  $unread_query = db_select('myapi_notifications', 'n')
    ->condition('uid', $uid)
    ->condition('is_read', 0);
  myapi_notification_apply_scope($unread_query, $condominium, $unit);
  $unread_count = (int) $unread_query->countQuery()->execute()->fetchField();

  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $query = db_select('myapi_notifications', 'n')
    ->fields('n', ['id', 'type', 'title', 'body', 'deep_link_target', 'deep_link_id', 'condominium_id', 'unit_id', 'is_read', 'created', 'read_at'])
    ->condition('uid', $uid);
  if ($unread_only) {
    $query->condition('is_read', 0);
  }
  myapi_notification_apply_scope($query, $condominium, $unit);
  $query->orderBy('created', 'DESC');
  // Stable tie-breaker for rows sharing the same created timestamp (fan-out).
  $query->orderBy('id', 'DESC');
  if ($limit !== -1) {
    $query->range(($page - 1) * $limit, $limit);
  }
  $rows = $query->execute()->fetchAll();

  myapi_respond([
    'notifications' => array_map('myapi_notification_build_item', $rows),
    'unread_count'  => $unread_count,
    'pagination'    => [
      'total'       => $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * Handles PUT /api/v1/notifications/%/read.
 *
 * Marks the user's own notification as read. Idempotent: if it was already
 * read, responds 200 without changing read_at. A missing id or one belonging to
 * another user returns 404 (the two cases are not distinguished).
 *
 * @param mixed $id  Notification id from the route.
 */
function myapi_notification_mark_read($id) {
  $auth = myapi_auth_require_access_token();
  $uid = $auth->uid;
  $id = (int) $id;

  $notification = db_select('myapi_notifications', 'n')
    ->fields('n', ['id', 'type', 'title', 'body', 'deep_link_target', 'deep_link_id', 'condominium_id', 'unit_id', 'is_read', 'created', 'read_at'])
    ->condition('id', $id)
    ->condition('uid', $uid)
    ->execute()
    ->fetchObject();

  if (!$notification) {
    myapi_error('notification_not_found', 404);
  }

  if (!$notification->is_read) {
    $now = REQUEST_TIME;
    db_update('myapi_notifications')
      ->fields(['is_read' => 1, 'read_at' => $now])
      ->condition('id', $id)
      ->condition('uid', $uid)
      ->execute();
    $notification->is_read = 1;
    $notification->read_at = $now;
  }

  myapi_respond(myapi_notification_build_item($notification), 200, 'notification_marked_read');
}

/**
 * Handles PUT /api/v1/notifications/read-all.
 *
 * Marks every unread notification of the user as read and reports how many were
 * marked (0 if there were none).
 */
function myapi_notification_mark_all_read() {
  $auth = myapi_auth_require_access_token();
  $uid = $auth->uid;

  $marked = db_update('myapi_notifications')
    ->fields(['is_read' => 1, 'read_at' => REQUEST_TIME])
    ->condition('uid', $uid)
    ->condition('is_read', 0)
    ->execute();

  myapi_respond(['marked' => (int) $marked], 200, 'notifications_marked_read');
}

/**
 * Applies the condominium/unit scope filter to a notifications query.
 *
 * Shared by the data, total and unread-count queries so the three always agree.
 * The visibility rules (a notification with no context is broader, not hidden):
 *   - A row with condominium_id NULL is always visible (global notification),
 *     regardless of the requested scope.
 *   - Otherwise the row must match the requested condominium, and either have no
 *     unit (unit_id NULL, visible to the whole condominium) or match the
 *     requested unit.
 *   - When no unit is requested, the unit constraint is dropped: every unit of
 *     the requested condominium (and the condominium-wide rows) is visible.
 *
 * A NULL $condominium means no scope was requested: the query is left untouched
 * and the full inbox is returned (back-compatible with callers that never send
 * the parameter).
 *
 * @param SelectQuery $query        The query to constrain (modified in place).
 * @param int|null    $condominium  Requested condominium nid, or NULL for none.
 * @param int|null    $unit         Requested unit nid, or NULL for none.
 */
function myapi_notification_apply_scope($query, $condominium, $unit) {
  if ($condominium === NULL) {
    return;
  }

  $in_condominium = db_and()->condition('condominium_id', $condominium);
  if ($unit !== NULL) {
    $in_condominium->condition(
      db_or()
        ->condition('unit_id', NULL, 'IS NULL')
        ->condition('unit_id', $unit)
    );
  }

  $query->condition(
    db_or()
      ->condition('condominium_id', NULL, 'IS NULL')
      ->condition($in_condominium)
  );
}

/**
 * Maps one raw notification row into the documented response shape.
 *
 * @param object $row  One row from myapi_notifications.
 *
 * @return array  Response item.
 */
function myapi_notification_build_item($row) {
  return [
    'id'         => (int) $row->id,
    'type'       => $row->type,
    'title'      => $row->title,
    'body'       => $row->body,
    'deep_link'  => [
      'target'      => $row->deep_link_target,
      'id'          => $row->deep_link_id !== NULL ? (int) $row->deep_link_id : NULL,
      'unit'        => $row->unit_id !== NULL ? (int) $row->unit_id : NULL,
      'condominium' => $row->condominium_id !== NULL ? (int) $row->condominium_id : NULL,
    ],
    'is_read'    => (bool) $row->is_read,
    'created_at' => (int) $row->created,
    'read_at'    => $row->read_at !== NULL ? (int) $row->read_at : NULL,
  ];
}
