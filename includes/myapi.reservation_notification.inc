<?php

/**
 * @file
 * Reservation notification helpers for the myapi module (SPEC 48).
 *
 * Three events, and only three:
 * - A reservation created through POST /api/v1/reservations notifies the
 *   resident (push + inbox + email) and emails the detail to every active user
 *   with the 'backend' role. The trigger lives inside myapi_reservation_create()
 *   and there is deliberately NO hook_node_insert() branch for 'reservation', so
 *   a reservation created by an operator from the node form — or by drush, a
 *   migration or an import — notifies nobody.
 * - A back-office update that moves field_reservation_status to 'cancelled'
 *   notifies the resident (push + inbox + email) and nobody else. The
 *   cancellation the resident performs from the app opts out of this branch
 *   through a transient node flag.
 * - The cancellation the resident performs through
 *   PUT /api/v1/reservations/%/cancel emails the detail to the 'backend' role
 *   and sends the resident nothing (SPEC 50). Fired by the endpoint itself, not
 *   by a hook, for the same reason as the creation one.
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

// myapi_user_fetch_profile_fields() resolves field_nombre/field_apellidos
// (SPEC 54). Reused instead of a duplicate query with the same joins, exactly
// as includes/myapi.user.inc already does for the profile resources.
module_load_include('inc', 'myapi', 'includes/myapi.user');

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
 *   cancelled_by, cancel_reason, area_id, area_title, unit_id, unit_title,
 *   condominium_id, uid, user_name, user_mail, user_first_name and
 *   user_last_name.
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
  // SPEC 50. Read from the incoming node and never from $node->original, so an
  // operator who types the reason and flips the status in the same save sees
  // that reason travel in the very notification of that save.
  $row->cancel_reason = myapi_reservation_node_value($node, 'field_cancel_reason');

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
  $row->user_first_name = NULL;
  $row->user_last_name = NULL;
  if ($row->uid !== NULL) {
    $account = user_load($row->uid);
    if ($account) {
      $row->user_name = $account->name;
      $row->user_mail = $account->mail !== '' ? $account->mail : NULL;

      // SPEC 54: same profile fields myapi_calendar_user_label() already
      // consumes, resolved here so the row carries the shape the calendar
      // rows have — myapi_reservation_calendar_rows() joins these fields
      // directly, and this row is built from a single node instead.
      $profile = myapi_user_fetch_profile_fields($row->uid);
      $row->user_first_name = $profile['first_name'];
      $row->user_last_name = $profile['last_name'];
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
  // Body generalized into myapi_notification_role_uids() by SPEC 68, which needs
  // the very same query for the 'backend' recipients of a claim. This signature
  // and its callers stay untouched; only the role name became a parameter.
  module_load_include('inc', 'myapi', 'includes/myapi.notification');

  return myapi_notification_role_uids(MYAPI_RESERVATION_NOTIFY_ROLE);
}

/**
 * Lists the uids of the ACTIVE building admins of one condominium (SPEC 49).
 *
 * Same shape as myapi_reservation_backend_uids() — role matched by name, never
 * by rid, blocked accounts out, no implicit membership for uid 1 — plus a join
 * on the user's field_condominio_admin, so only the operators of THIS
 * condominium come back. Sending it to the admins of other buildings would be
 * noise and a leak of other residents' data.
 *
 * Two cases return an empty list without touching the database:
 * - A reservation with no condominium resolved. There is nobody to scope to.
 * - An environment where field_condominio_admin does not exist yet, i.e. the
 *   deploy window between pushing this code and running `drush updb`. Querying
 *   its data table there would be a fatal error in the middle of creating a
 *   reservation. field_info_field() is served from cache, so the check is free.
 *
 * @param int|NULL $condominium_id  The reservation's condominium nid.
 *
 * @return array  Unique uids as integers. Empty when nobody matches.
 */
function myapi_reservation_building_admin_uids($condominium_id) {
  // Body generalized into myapi_notification_building_admin_uids() by SPEC 68,
  // which needs the very same query for the building admins of a claim's
  // condominium. Signature, callers and behaviour — including both empty-list
  // shortcuts — stay exactly as SPEC 48 documented them.
  module_load_include('inc', 'myapi', 'includes/myapi.notification');

  return myapi_notification_building_admin_uids($condominium_id);
}

/**
 * Date of a reservation row as 'd/m/Y'.
 *
 * Same format the calendar detail panel prints, so push, inbox and both emails
 * show one single date format to everybody.
 *
 * @param object $row  Reservation row.
 *
 * @return string  Escaped 'd/m/Y'.
 */
function myapi_reservation_date_label($row) {
  return check_plain(format_date(strtotime($row->date), 'custom', 'd/m/Y'));
}

/**
 * Schedule of a reservation row as 'HH:MM - HH:MM', with the overnight marker.
 *
 * Without the ' (+1 día)' suffix, '22:00 - 02:00' reads as a 20-hour booking.
 * It is the same indicator the calendar already shows.
 *
 * @param object $row   Reservation row.
 * @param string $dash  Separator: the simple hyphen for push, inbox and the
 *   resident email; the en dash for the 'backend' email, which replicates the
 *   calendar detail panel field by field.
 *
 * @return string  Escaped schedule.
 */
function myapi_reservation_schedule_label($row, $dash = '-') {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_query');

  $label = check_plain($row->start_time) . ' ' . $dash . ' ' . check_plain($row->end_time);

  if (myapi_reservation_time_to_minutes($row->end_time) <= myapi_reservation_time_to_minutes($row->start_time)) {
    $label .= ' (+1 día)';
  }

  return $label;
}

/**
 * Body of the push + inbox notification: three plain-text lines.
 *
 * Uses the RAW area title, not the escaped calendar label: this text is stored
 * in myapi_notifications and rendered by the app, which is not HTML. When the
 * area no longer exists the first line drops the quoted name altogether rather
 * than printing empty quotes.
 *
 * The three fixed lines stay well under the 200-character push cut; the
 * optional fourth one (the cancellation reason, SPEC 50) may push past it, and
 * what myapi_onesignal_truncate_body() then trims is the tail of that reason.
 * The inbox row and the email always carry it in full.
 *
 * @param object $row        Reservation row.
 * @param bool   $cancelled  TRUE for the cancellation wording.
 *
 * @return string  Notification body: three lines, four with a reason.
 */
function myapi_reservation_notification_body($row, $cancelled) {
  $action = $cancelled ? 'ha sido cancelada por un operador.' : 'ha sido confirmada.';

  $first = $row->area_title !== NULL
    ? 'Tu reserva del área "' . $row->area_title . '" ' . $action
    : 'Tu reserva ' . $action;

  $body = $first
    . "\nFecha: " . decode_entities(myapi_reservation_date_label($row))
    . "\nHorario: " . decode_entities(myapi_reservation_schedule_label($row));

  // SPEC 50: fourth line, cancellations only and only when there is a reason.
  // Without one the body stays byte for byte the three-line text of SPEC 48.
  // The raw stored value is used, like the area title above: this text is
  // rendered by the app as plain text, not as HTML.
  if ($cancelled && isset($row->cancel_reason) && trim($row->cancel_reason) !== '') {
    $body .= "\nMotivo: " . $row->cancel_reason;
  }

  return $body;
}

/**
 * Inserts the inbox row and enqueues the push for one reservation event.
 *
 * A reservation with no field_requester has nobody to notify;
 * myapi_notification_create() is a no-op on an empty uid list, so the guard is
 * only there to avoid building the payload for nothing.
 *
 * @param object $row        Reservation row.
 * @param bool   $cancelled  TRUE for the cancellation event.
 */
function myapi_reservation_notify_inbox($row, $cancelled) {
  if ($row->uid === NULL) {
    return;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.notification');

  myapi_notification_create([
    'source_type'      => MYAPI_NOTIFICATION_SOURCE_RESERVATION,
    'source_nid'       => $row->nid,
    'type'             => $cancelled
      ? MYAPI_NOTIFICATION_TYPE_RESERVATION_CANCELLED
      : MYAPI_NOTIFICATION_TYPE_RESERVATION_CREATED,
    'title'            => $cancelled ? 'Reserva cancelada' : 'Reserva creada',
    'body'             => myapi_reservation_notification_body($row, $cancelled),
    'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_RESERVATION,
    'deep_link_id'     => $row->nid,
    'condominium_id'   => $row->condominium_id,
    'unit_id'          => $row->unit_id,
    'uids'             => [$row->uid],
  ]);
}

/**
 * Enqueues the reservation email addressed to the resident.
 *
 * The params travel already resolved and escaped, built with the label helpers
 * of the calendar (SPEC 47) so a deleted area, unit, condominium or account
 * degrades to the very same text the back office shows.
 *
 * A resident with no email address is skipped: myapi_mail_queue_enqueue() logs
 * the reason and returns FALSE, and the push and the inbox row — which are the
 * immediate channel — already went out.
 *
 * @param object $row        Reservation row.
 * @param bool   $cancelled  TRUE for the cancellation email.
 */
function myapi_reservation_enqueue_user_mail($row, $cancelled) {
  module_load_include('inc', 'myapi', 'includes/myapi.mail_queue');
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  $params = [
    // SPEC 54: full name, no username suffix — the calendar label reused
    // here on purpose is myapi_calendar_user_name_label(), not
    // myapi_calendar_user_label(), which is reserved for the admin panel.
    'name'        => myapi_calendar_user_name_label($row),
    'area'        => myapi_calendar_area_label($row),
    'condominium' => myapi_calendar_condominium_label($row, myapi_calendar_condominium_options()),
    'unit'        => myapi_calendar_unit_label($row),
    'date'        => myapi_reservation_date_label($row),
    'schedule'    => myapi_reservation_schedule_label($row),
    'duration'    => myapi_calendar_duration_label($row),
    'nid'         => $row->nid,
    // SPEC 50. Empty string on a creation and on a cancellation with no
    // reason, which is what keeps the 'Motivo' line out of the email.
    'cancel_reason' => $cancelled && isset($row->cancel_reason) && trim($row->cancel_reason) !== ''
      ? check_plain($row->cancel_reason)
      : '',
  ];

  $key = $cancelled ? 'reservation_cancelled_user' : 'reservation_created_user';
  myapi_mail_queue_enqueue($key, (string) $row->user_mail, $params);
}

/**
 * Enqueues the detail email to every active user holding the 'backend' role.
 *
 * One queue item per recipient, so an invalid address never drags the rest
 * down. With nobody holding the role nothing is enqueued and the event behaves
 * exactly the same.
 *
 * SPEC 49 widens the CREATION key only: 'reservation_created_admin' also
 * reaches the building admins of the reservation's condominium, because they
 * are the ones who have to make room for it. The union is deduplicated, so
 * somebody holding both 'backend' and 'administrador edificio' gets one single
 * email. 'reservation_cancelled_admin' is deliberately left alone — the spec
 * only asks for the creation one, and widening the cancellation email is a
 * decision for whoever writes that spec.
 *
 * Serves the two 'backend' keys (SPEC 50), which share ten of their twelve
 * lines: 'reservation_created_admin', fired by POST /api/v1/reservations, and
 * 'reservation_cancelled_admin', fired by PUT /api/v1/reservations/%/cancel.
 * The ten common lines are the ones of the calendar detail panel, resolved
 * through the same helpers; the cancellation variant adds 'Cancelada por' and,
 * when there is one, 'Motivo'. Duplicating the function for the second key
 * would guarantee both drift apart at the first layout change (rule 3 of
 * CLAUDE.md).
 *
 * @param object $row  Reservation row.
 * @param string $key  Mail key to enqueue. Defaults to the creation one, so
 *   every pre-SPEC-50 call site keeps its exact behaviour.
 */
function myapi_reservation_enqueue_admin_mails($row, $key = 'reservation_created_admin') {
  $uids = myapi_reservation_backend_uids();

  if ($key === 'reservation_created_admin') {
    $uids = array_values(array_unique(array_merge(
      $uids,
      myapi_reservation_building_admin_uids($row->condominium_id)
    )));
  }

  if (empty($uids)) {
    return;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.mail_queue');
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  $params = [
    'nid'         => $row->nid,
    // SPEC 54: full name, no username suffix (see myapi_reservation_enqueue_user_mail()).
    'user'        => myapi_calendar_user_name_label($row),
    'email'       => $row->user_mail === NULL ? '—' : check_plain($row->user_mail),
    'unit'        => myapi_calendar_unit_label($row),
    'area'        => myapi_calendar_area_label($row),
    'condominium' => myapi_calendar_condominium_label($row, myapi_calendar_condominium_options()),
    'date'        => myapi_reservation_date_label($row),
    // The en dash is the one the calendar detail panel uses.
    'schedule'    => myapi_reservation_schedule_label($row, '–'),
    'duration'    => myapi_calendar_duration_label($row),
    'status'      => $row->status === MYAPI_RESERVATION_STATUS_CANCELLED ? 'Cancelada' : 'Confirmada',
    'created'     => check_plain(format_date($row->created, 'custom', 'd/m/Y H:i')),
    // Absolute so the link works from any mail client; check_plain() because
    // url() does not encode the '&' between query arguments for HTML output.
    'node_url'    => check_plain(url('node/' . $row->nid, array('absolute' => TRUE))),
  ];

  // The two extra lines of the cancellation variant. 'Cancelada por' is fixed
  // to 'Usuario' because this key only fires from the resident's endpoint; the
  // raw stored value there is always 'user', and 'Cancelada por: user' reads as
  // a bug in an email. 'Motivo' is empty when nobody typed one, which is what
  // keeps the line out of the message.
  if ($key === 'reservation_cancelled_admin') {
    $params['cancelled_by'] = 'Usuario';
    $params['cancel_reason'] = isset($row->cancel_reason) && trim($row->cancel_reason) !== ''
      ? check_plain($row->cancel_reason)
      : '';
  }

  foreach (user_load_multiple($uids) as $account) {
    myapi_mail_queue_enqueue($key, (string) $account->mail, $params);
  }
}

/**
 * Notifies a reservation created through POST /api/v1/reservations.
 *
 * Resident: push + inbox row + email. Users with the 'backend' role: the detail
 * email. Called from myapi_reservation_create() only — a reservation created by
 * an operator from the node form, by drush or by an import never reaches this
 * function, which is the whole point of not hooking node insert.
 *
 * Notifies at most once per node per request (drupal_static guard), the same
 * mechanism as myapi_fee_notify_issued(), so a re-save inside the same request
 * cannot duplicate the inbox row.
 *
 * Best-effort by contract: the reservation is already committed when this runs,
 * so nothing here may bubble up and turn a successful 201 into a 500. Any
 * failure is logged and swallowed.
 *
 * @param object $node  The reservation node that was just created.
 */
function myapi_reservation_notify_created($node) {
  $notified = &drupal_static(__FUNCTION__, []);
  if (isset($notified[$node->nid])) {
    return;
  }
  $notified[$node->nid] = TRUE;

  try {
    $row = myapi_reservation_notification_row($node);

    myapi_reservation_notify_inbox($row, FALSE);
    myapi_reservation_enqueue_user_mail($row, FALSE);
    myapi_reservation_enqueue_admin_mails($row);
  }
  catch (Exception $e) {
    watchdog_exception('myapi', $e);
  }
}

/**
 * Detects the back-office update that cancels a reservation.
 *
 * Called from myapi_node_update() (in myapi.module) for every 'reservation'
 * node save. Four ways to say no:
 * - The transient opt-out flag is set: the save comes from
 *   PUT /api/v1/reservations/%/cancel, where the resident cancelled their own
 *   reservation and nobody is notified.
 * - There is no $node->original: not an update of a stored node, so there is no
 *   previous status to compare against.
 * - The incoming status is not 'cancelled'.
 * - It already was 'cancelled': editing any other field of a cancelled
 *   reservation is not a new cancellation.
 *
 * The transition is read from the status field and never from
 * field_cancelled_by, which an operator may well leave empty.
 *
 * @param object $node  The 'reservation' node being updated.
 *
 * @return bool  TRUE when this save cancels the reservation from the back office.
 */
function myapi_reservation_is_cancellation_transition($node) {
  if (!empty($node->myapi_skip_reservation_notification)) {
    return FALSE;
  }
  if (!isset($node->original)) {
    return FALSE;
  }

  $previous = myapi_reservation_node_value($node->original, 'field_reservation_status');
  $incoming = myapi_reservation_node_value($node, 'field_reservation_status');

  return $incoming === MYAPI_RESERVATION_STATUS_CANCELLED
    && $previous !== MYAPI_RESERVATION_STATUS_CANCELLED;
}

/**
 * Notifies a reservation cancelled by an operator from the back office.
 *
 * Resident only: push + inbox row + email. No detail email to the 'backend'
 * role — that one exists on creation and nowhere else, so the operators who
 * already see the cancellation in the calendar are not mailed about it.
 *
 * Same guard and same best-effort contract as myapi_reservation_notify_created():
 * once per node per request, and no exception may escape into the node save
 * that triggered it.
 *
 * @param object $node  The reservation node that just moved to 'cancelled'.
 */
function myapi_reservation_notify_cancelled($node) {
  $notified = &drupal_static(__FUNCTION__, []);
  if (isset($notified[$node->nid])) {
    return;
  }
  $notified[$node->nid] = TRUE;

  try {
    $row = myapi_reservation_notification_row($node);

    myapi_reservation_notify_inbox($row, TRUE);
    myapi_reservation_enqueue_user_mail($row, TRUE);
  }
  catch (Exception $e) {
    watchdog_exception('myapi', $e);
  }
}

/**
 * Notifies a reservation cancelled by its own resident from the app (SPEC 50).
 *
 * The exact mirror image of myapi_reservation_notify_cancelled(): 'backend'
 * users get the detail email and the resident gets NOTHING — no inbox row, no
 * push, no email. They just cancelled it on screen, so confirming it back to
 * them adds nothing; the operators, who did not, are the ones who need to know.
 *
 * Called from myapi_reservation_cancel() only, right after node_save(). It is
 * deliberately NOT hooked to node update: the endpoint is the resident's only
 * cancellation path, and hooking the transition would also mail on any
 * programmatic save that happened to set field_cancelled_by to 'user'. The
 * transient myapi_skip_reservation_notification flag on the node keeps
 * hook_node_update() silent, exactly as before — it only ever silenced the
 * channels aimed at the resident, which are still silent here.
 *
 * Same guard and same best-effort contract as the other two notifiers: once per
 * node per request, and no exception may escape and turn a saved cancellation
 * into a 500. Enqueuing is a write to the mail queue, never an SMTP round trip.
 *
 * @param object $node  The reservation node the resident just cancelled.
 */
function myapi_reservation_notify_user_cancelled($node) {
  $notified = &drupal_static(__FUNCTION__, []);
  if (isset($notified[$node->nid])) {
    return;
  }
  $notified[$node->nid] = TRUE;

  try {
    $row = myapi_reservation_notification_row($node);

    myapi_reservation_enqueue_admin_mails($row, 'reservation_cancelled_admin');
  }
  catch (Exception $e) {
    watchdog_exception('myapi', $e);
  }
}
