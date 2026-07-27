<?php

/**
 * @file
 * Reservation notification helpers for the myapi module (SPEC 48).
 *
 * Two events, and only two:
 * - A reservation created through POST /api/v1/reservations notifies the
 *   resident (push + inbox + email) and emails the detail to every active user
 *   with the 'backend' role. The trigger lives inside myapi_reservation_create()
 *   and there is deliberately NO hook_node_insert() branch for 'reservation', so
 *   a reservation created by an operator from the node form — or by drush, a
 *   migration or an import — notifies nobody.
 * - A back-office update that moves field_reservation_status to 'cancelled'
 *   notifies the resident (push + inbox + email). The cancellation the resident
 *   performs from the app opts out through a transient node flag, so it notifies
 *   nobody either.
 *
 * The email to 'backend' reuses the label helpers of the reservation calendar
 * (SPEC 47) instead of re-deriving the ten values here: this file builds a row
 * with exactly the shape myapi_reservation_calendar_rows() returns and hands it
 * over, so both surfaces show the same text, including the absence labels of a
 * deleted area, unit or account. The coupling is one-way — the calendar knows
 * nothing about this file and none of its code was modified.
 *
 * Everything here is best-effort: no branch throws, so a failure to notify can
 * never break a node save or an API response.
 */

// Reservation field readers (myapi_reservation_node_value() /
// myapi_reservation_node_target_id()) live in the reservation resource. They are
// reused instead of repeating the LANGUAGE_NONE unwrapping, exactly as
// includes/myapi.reservation_calendar.inc reuses myapi_reservation_valid_date().
module_load_include('inc', 'myapi', 'resources/reservation.resource');

/**
 * Value stored in myapi_notifications.source_type for a reservation event.
 */
define('MYAPI_NOTIFICATION_SOURCE_RESERVATION', 'reservation');

/**
 * Notification type of the "reservation created" event.
 */
define('MYAPI_NOTIFICATION_TYPE_RESERVATION_CREATED', 'reservation_created');

/**
 * Notification type of the "reservation cancelled" event.
 */
define('MYAPI_NOTIFICATION_TYPE_RESERVATION_CANCELLED', 'reservation_cancelled');

/**
 * Deep link target the app opens from a reservation notification.
 */
define('MYAPI_NOTIFICATION_DEEP_LINK_RESERVATION', 'reservation');

/**
 * Stored value of field_reservation_status for a cancelled reservation.
 *
 * Single source for the literal: the allowed_values of the field are created by
 * _myapi_reservations_install() in myapi.install, and the cancellation detector
 * compares against this constant and never against a repeated string.
 */
define('MYAPI_RESERVATION_STATUS_CANCELLED', 'cancelled');

/**
 * Role whose active users receive the reservation detail email.
 *
 * Matched by role NAME and never by rid: the rid varies per environment (same
 * reasoning documented for myapi_calendar_admin_roles() in SPEC 47).
 */
define('MYAPI_RESERVATION_NOTIFY_ROLE', 'backend');

/**
 * Builds, from a reservation node, a row with the same shape the calendar query
 * returns.
 *
 * The property names are exactly those of myapi_reservation_calendar_rows() so
 * the label helpers of SPEC 47 (myapi_calendar_user_label(),
 * myapi_calendar_unit_label(), myapi_calendar_area_label(),
 * myapi_calendar_condominium_label(), myapi_calendar_duration_label()) consume
 * it unchanged — no duplicated formatting and no duplicated absence handling.
 *
 * Every absence lands on NULL, which is precisely what those helpers already
 * degrade into 'Sin vivienda', 'Usuario eliminado (#789)' and friends. A deleted
 * area or unit keeps its target_id and loses only its title, exactly as the LEFT
 * JOINs of the calendar query produce.
 *
 * @param object $node  A 'reservation' node.
 *
 * @return object  Row with nid, created, date, start_time, end_time, status,
 *   cancelled_by, area_id, area_title, unit_id, unit_title, condominium_id, uid,
 *   user_name and user_mail.
 */
function myapi_reservation_notification_row($node) {
  $row = new stdClass();

  $row->nid = isset($node->nid) ? (int) $node->nid : NULL;
  $row->created = isset($node->created) ? (int) $node->created : REQUEST_TIME;

  $row->date = myapi_reservation_node_date($node);
  $row->start_time = myapi_reservation_node_value($node, 'field_start_time');
  $row->end_time = myapi_reservation_node_value($node, 'field_end_time');
  $row->status = myapi_reservation_node_value($node, 'field_reservation_status');
  $row->cancelled_by = myapi_reservation_node_value($node, 'field_cancelled_by');

  // Area and unit: the title is resolved with node_load() and stays NULL when
  // the referenced node no longer exists, which is the deleted-entity case the
  // calendar labels already cover.
  $row->area_id = myapi_reservation_node_target_id($node, 'field_area');
  $row->area_title = NULL;
  if ($row->area_id !== NULL) {
    $area = node_load($row->area_id);
    $row->area_title = $area ? $area->title : NULL;
  }

  $row->unit_id = myapi_reservation_node_target_id($node, 'field_unit');
  $row->unit_title = NULL;
  if ($row->unit_id !== NULL) {
    $unit = node_load($row->unit_id);
    $row->unit_title = $unit ? $unit->title : NULL;
  }

  // The calendar query returns the condominium nid only; its title is resolved
  // by myapi_calendar_condominium_label() from the options array.
  $row->condominium_id = myapi_reservation_node_target_id($node, 'field_condominium');

  // Requester: name and mail via user_load(), NULL when the account is gone.
  $row->uid = myapi_reservation_node_target_id($node, 'field_requester');
  $row->user_name = NULL;
  $row->user_mail = NULL;
  if ($row->uid !== NULL) {
    $account = user_load($row->uid);
    if ($account) {
      $row->user_name = $account->name;
      $row->user_mail = $account->mail !== '' ? $account->mail : NULL;
    }
  }

  return $row;
}

/**
 * Lists the uids of the ACTIVE users holding the reservation notification role.
 *
 * Matched by role name (MYAPI_RESERVATION_NOTIFY_ROLE) and never by rid, because
 * the rid differs between environments. Blocked accounts are excluded by
 * users.status = 1, and uid 1 only appears if the role is actually assigned to
 * it — it gets no implicit membership here.
 *
 * @return array  Unique uids as integers. Empty when nobody holds the role.
 */
function myapi_reservation_backend_uids() {
  $query = db_select('users', 'u');
  $query->fields('u', array('uid'));
  $query->innerJoin('users_roles', 'ur', 'ur.uid = u.uid');
  $query->innerJoin('role', 'r', 'r.rid = ur.rid');
  $query->condition('r.name', MYAPI_RESERVATION_NOTIFY_ROLE);
  $query->condition('u.status', 1);
  $query->condition('u.uid', 0, '>');
  $query->distinct();

  $uids = $query->execute()->fetchCol();

  return array_map('intval', $uids);
}
