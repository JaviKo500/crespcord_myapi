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
 *   - page   default 1.
 *   - limit  default 20, clamped to [1, 50]; -1 returns all, unpaginated.
 *   - unread '1' returns only unread; anything else (or absent) returns all.
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

  // Total of the current (possibly filtered) set, for pagination.
  $count_query = db_select('myapi_notifications', 'n')->condition('uid', $uid);
  if ($unread_only) {
    $count_query->condition('is_read', 0);
  }
  $total = (int) $count_query->countQuery()->execute()->fetchField();

  // Unread badge count: always the user's total unread, ignoring the filter
  // and pagination.
  $unread_count = (int) db_select('myapi_notifications', 'n')
    ->condition('uid', $uid)
    ->condition('is_read', 0)
    ->countQuery()
    ->execute()
    ->fetchField();

  $total_pages = $limit === -1
    ? ($total > 0 ? 1 : 0)
    : ($total > 0 ? (int) ceil($total / $limit) : 0);

  $query = db_select('myapi_notifications', 'n')
    ->fields('n', ['id', 'type', 'title', 'body', 'deep_link_target', 'deep_link_id', 'condominium_id', 'unit_id', 'is_read', 'created', 'read_at'])
    ->condition('uid', $uid);
  if ($unread_only) {
    $query->condition('is_read', 0);
  }
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
