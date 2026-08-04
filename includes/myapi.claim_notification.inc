<?php

/**
 * @file
 * Claim notification helpers for the myapi module (SPEC 68).
 *
 * Three events, and only three:
 * - A 'reclamo' node is created. The requester gets push + inbox + email; when
 *   the claim is born public, every other owner and occupant of its condominium
 *   gets the neighbour text; and ONLY when the claim comes from the app (the
 *   transient $node->myapi_claim_from_api flag set by myapi_claim_create()) the
 *   detail email also goes to the 'backend' role and to the building admins of
 *   that condominium.
 * - A 'reclamo' node moves from private to public. Only the condominium is
 *   notified, requester excluded: they already know, and the inverse transition
 *   deliberately notifies nobody.
 * - A 'claim_transaction' node is created, except the automatic initial one,
 *   which opts out through $transaction->myapi_skip_claim_notification. The
 *   requester gets one text, the neighbours another, and the claim — never the
 *   transaction — is what the deep link and source_nid point at.
 *
 * All three triggers hang off hook_node_insert() / hook_node_update() in
 * myapi.module rather than off the endpoints, because a claim is created by two
 * paths (POST /api/v1/claims and node/add/reclamo) and published by two more.
 * The API contributes one flag and nothing else; no endpoint changes its
 * response.
 *
 * Texts are fixed in Spanish, not translated through myapi_t(): these run inside
 * node_save(), where there is no Accept-Language to resolve, and translating
 * only the API-born half would leave two notifications of the same claim in two
 * different languages. Same criterion as SPEC 27/28/30/48.
 *
 * Everything here is best-effort: no branch throws, so a failure to notify can
 * never break a node save or an API response.
 */

// Field readers (myapi_building_admin_field_value() /
// myapi_building_admin_field_target_id(), SPEC 49) are reused instead of
// repeating the langcode/delta walk, exactly as SPEC 57 does for the timeline.
module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

/**
 * Value stored in myapi_notifications.source_type for a claim event.
 *
 * The three events share it — including the transaction ones, whose source is
 * the CLAIM and not the transaction node.
 */
define('MYAPI_NOTIFICATION_SOURCE_CLAIM', 'claim');

/**
 * Deep link target the app opens from any of the three claim notifications.
 *
 * A value the app does not know yet: until it does, an unknown target must
 * degrade to opening the inbox, never break. Documented as part of the contract
 * in docs/claim-notifications.md.
 */
define('MYAPI_NOTIFICATION_DEEP_LINK_CLAIM', 'claim');

/**
 * Notification type of the "claim created" event (to the requester).
 */
define('MYAPI_NOTIFICATION_TYPE_CLAIM_CREATED', 'claim_created');

/**
 * Notification type of the "claim became visible to you" event (to neighbours).
 *
 * One single type for the two ways a claim reaches a neighbour — born public, or
 * turned public later — because from the neighbour's side both facts are the
 * same one: a claim they can now open.
 */
define('MYAPI_NOTIFICATION_TYPE_CLAIM_PUBLISHED', 'claim_published');

/**
 * Notification type of the "new transaction on a claim" event.
 */
define('MYAPI_NOTIFICATION_TYPE_CLAIM_TRANSACTION', 'claim_transaction_created');

/**
 * Stored value of field_visibility for a public claim.
 *
 * Single source for the literal, same reasoning as
 * MYAPI_RESERVATION_STATUS_CANCELLED (SPEC 48): the allowed_values of the field
 * are created by _myapi_claims_install() in myapi.install, and the publication
 * detector compares against this constant and never against a repeated string.
 */
define('MYAPI_CLAIM_VISIBILITY_PUBLIC', 'public');

/**
 * Role whose active users receive the claim detail email.
 *
 * Matched by role NAME and never by rid — see myapi_notification_role_uids().
 * MYAPI_BUILDING_ADMIN_ROLE (SPEC 49) is the other half of that audience and is
 * reused from includes/myapi.building_admin.inc, not redefined here.
 */
define('MYAPI_CLAIM_NOTIFY_ROLE', 'backend');

/**
 * Characters of the claim subject that survive into push, inbox and mail subject.
 *
 * Without this cut a 255-character subject would eat the whole push: the body is
 * trimmed to 200 by myapi_onesignal_truncate_body(), and the date and the status
 * — the part that says what actually happened — would never arrive.
 */
define('MYAPI_CLAIM_SUBJECT_EXCERPT', 80);

/**
 * Characters of the operator's comment that survive into push and inbox.
 *
 * The email carries it in full: there is no 200-character budget to respect
 * there.
 */
define('MYAPI_CLAIM_COMMENT_EXCERPT', 120);

/* ---------------------------------------------------------------------------
 * The equivalent row.
 * ------------------------------------------------------------------------ */

/**
 * Builds, from a 'reclamo' node, a row with every value already resolved.
 *
 * Same pattern as myapi_reservation_notification_row() (SPEC 48): the text
 * builders and the five email formatters read THIS object, so none of them goes
 * back to the node — or to node_load() / user_load() — on its own.
 *
 * Every absence lands on the value documented in the spec's model, so a claim
 * with no condominium, a deleted requester or a missing reception date produces
 * a correct text instead of a notice.
 *
 * unit_id is deliberately absent: the 'reclamo' bundle has no field_unit (only
 * field_condominium), which is why every myapi_notifications row this spec
 * writes carries unit_id = NULL.
 *
 * @param object $node  A 'reclamo' node.
 *
 * @return object  Row with nid, created, subject, description, claim_type,
 *   type_label, visibility, status, status_label, condominium_id,
 *   condominium_name, requester_uid, requester_name, requester_mail,
 *   reception_date, image_count and has_attachment.
 */
function myapi_claim_notification_row($node) {
  $row = new stdClass();

  $row->nid = isset($node->nid) ? (int) $node->nid : NULL;
  $row->created = isset($node->created) ? (int) $node->created : REQUEST_TIME;

  $row->subject = isset($node->title) ? (string) $node->title : '';
  $row->description = (string) myapi_building_admin_field_value($node, 'field_description');

  $row->claim_type = myapi_building_admin_field_value($node, 'field_claim_type');
  $row->type_label = myapi_claim_type_label($row->claim_type);

  // NULL is treated as private everywhere: the only value that opens the
  // fan-out is the explicit 'public' one.
  $row->visibility = myapi_building_admin_field_value($node, 'field_visibility');

  $row->status = myapi_building_admin_field_value($node, 'field_status');
  // NULL for an unknown or missing status, which is what makes the requester
  // title degrade to 'Novedad en tu reclamo' and drops the neighbour's status.
  $row->status_label = myapi_claim_status_label($row->status);

  $row->condominium_id = myapi_building_admin_field_target_id($node, 'field_condominium');
  $row->condominium_name = 'Sin condominio';
  if ($row->condominium_id !== NULL) {
    $condominium = node_load($row->condominium_id);
    if ($condominium) {
      $row->condominium_name = $condominium->title;
    }
  }

  // Requester: uid, display name and address. A uid pointing at a deleted
  // account keeps the uid and gets the same label the claims list already
  // prints for it (myapi_claims_requester_label(), SPEC 56).
  $row->requester_uid = myapi_building_admin_field_target_id($node, 'field_requester');
  $row->requester_name = NULL;
  $row->requester_mail = NULL;
  if ($row->requester_uid !== NULL) {
    $account = user_load($row->requester_uid);
    if ($account) {
      // SPEC 54 profile fields first — the back-office email says "Javier
      // Correa", not the username — with the username as the fallback, exactly
      // as myapi_calendar_user_name_label() resolves it for reservations.
      module_load_include('inc', 'myapi', 'includes/myapi.user');
      $profile = myapi_user_fetch_profile_fields($row->requester_uid);
      $full_name = trim((string) $profile['first_name'] . ' ' . (string) $profile['last_name']);
      $row->requester_name = $full_name !== '' ? $full_name : $account->name;
      $row->requester_mail = $account->mail !== '' ? $account->mail : NULL;
    }
    else {
      $row->requester_name = 'Usuario eliminado (#' . $row->requester_uid . ')';
    }
  }

  // field_reception_date is a 'Y-m-d H:i:00' string with minute granularity
  // since SPEC 63. It travels as a timestamp so every text formats it through
  // the same format_date() call. An empty or unparseable value falls back to
  // the node's own creation time, which is the closest true statement left.
  $row->reception_date = $row->created;
  $reception = myapi_building_admin_field_value($node, 'field_reception_date');
  if ($reception !== NULL && trim((string) $reception) !== '') {
    $timestamp = strtotime((string) $reception);
    if ($timestamp !== FALSE) {
      $row->reception_date = $timestamp;
    }
  }

  $row->image_count = myapi_claim_file_count($node, 'field_images');
  $row->has_attachment = myapi_claim_file_count($node, 'field_attachment') > 0;

  return $row;
}

/**
 * Counts the files stored in a file/image field of a node.
 *
 * The langcode/delta walk of myapi_building_admin_field_target_ids() does not
 * serve here: file items carry 'fid', not 'target_id'. Serves both
 * 'field_images' (up to 5) and 'field_attachment' (0 or 1) — one function for
 * the two, same criterion as the claim resource's own file helpers.
 *
 * @param object $node        The claim node.
 * @param string $field_name  'field_images' or 'field_attachment'.
 *
 * @return int  Number of files. 0 when the field is absent or empty.
 */
function myapi_claim_file_count($node, $field_name) {
  if (!is_object($node) || empty($node->{$field_name}) || !is_array($node->{$field_name})) {
    return 0;
  }

  $count = 0;
  foreach ($node->{$field_name} as $items) {
    if (!is_array($items)) {
      continue;
    }
    foreach ($items as $item) {
      if (is_array($item) && !empty($item['fid'])) {
        $count++;
      }
    }
  }

  return $count;
}

/* ---------------------------------------------------------------------------
 * Recipient resolvers.
 * ------------------------------------------------------------------------ */

/**
 * The uid of a claim's requester, or NULL.
 *
 * A claim saved from the back office may legitimately have no field_requester:
 * the operator chooses it and can leave it empty. NULL is the answer, never
 * node.uid — that would send the acknowledgement to the operator who typed the
 * claim in, about a claim that is not theirs.
 *
 * @param object $node  A 'reclamo' node.
 *
 * @return int|null
 */
function myapi_claim_requester_uid($node) {
  return myapi_building_admin_field_target_id($node, 'field_requester');
}

/**
 * The ACTIVE owners and occupants of a condominium, minus one uid.
 *
 * Wraps myapi_condominium_member_uids([$condo], 'todos') — the very universe
 * SPEC 09 already resolves for the condominium bulletin — and adds the two
 * things this spec needs on top of it: the active-users filter (that function
 * deliberately does not apply one) and the exclusion of the requester, which is
 * what guarantees ONE notification per person with the text that belongs to
 * them.
 *
 * The audience matches the visibility rule of SPEC 64: a public claim is
 * readable by the neighbours of its condominium and nobody else, so notifying
 * further would announce something the recipient cannot open.
 *
 * @param int|NULL $condominium_id  The claim's condominium nid.
 * @param int|NULL $exclude_uid     uid to leave out, normally the requester.
 *
 * @return array  Unique active uids as integers. Empty when there is nobody.
 */
function myapi_claim_condominium_uids($condominium_id, $exclude_uid = NULL) {
  if ($condominium_id === NULL) {
    return array();
  }

  module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

  $uids = myapi_condominium_member_uids(array((int) $condominium_id), 'todos');
  if (empty($uids)) {
    return array();
  }

  $uids = array_values(array_unique(array_map('intval', $uids)));

  if ($exclude_uid !== NULL) {
    $uids = array_values(array_diff($uids, array((int) $exclude_uid)));
  }

  if (empty($uids)) {
    return array();
  }

  $active = db_select('users', 'u')
    ->fields('u', array('uid'))
    ->condition('uid', $uids, 'IN')
    ->condition('status', 1)
    ->execute()
    ->fetchCol();

  return array_map('intval', $active);
}

/* ---------------------------------------------------------------------------
 * Pure text builders.
 *
 * Everything below is free of Drupal except t(), format_date() and
 * truncate_utf8(), which is what puts it whole inside tests/unit — same split
 * as SPEC 60 draws between myapi_claim_transaction_title() and set_title().
 * The values arrive already resolved from the row; nothing here reads a node.
 *
 * Nothing here escapes anything either: these strings are stored in
 * myapi_notifications and rendered by the app as plain text, not as HTML. The
 * five emails do their own escaping.
 * ------------------------------------------------------------------------ */

/**
 * The claim's noun in lowercase: 'reclamo' or 'requerimiento'.
 *
 * Written here rather than lowercased off myapi_claims_claim_type_options():
 * that catalogue supplies SELECT labels read from field_claim_type's
 * allowed_values, and a site administrator editing one from the UI would
 * silently rewrite these sentences mid-phrase. Same criterion SPEC 61 states
 * for myapi_claim_transaction_initial_comment(), which carries its own copy of
 * this branch for the same reason: labels come from the field, meaning does
 * not.
 *
 * Both nouns are masculine, so the rest of every sentence is invariable.
 *
 * @param string|null $claim_type  field_claim_type, as stored.
 *
 * @return string  Unknown or missing degrades to 'reclamo', the bundle's own
 *   name, exactly like SPEC 61.
 */
function myapi_claim_type_noun($claim_type) {
  return ($claim_type === 'requirement') ? t('requerimiento') : t('reclamo');
}

/**
 * The claim's noun capitalised: 'Reclamo' or 'Requerimiento'.
 *
 * @param string|null $claim_type  field_claim_type, as stored.
 *
 * @return string  Unknown or missing degrades to 'Reclamo'.
 */
function myapi_claim_type_label($claim_type) {
  return ($claim_type === 'requirement') ? t('Requerimiento') : t('Reclamo');
}

/**
 * Human label of a claim status, or NULL when it cannot be resolved.
 *
 * The four keys are SPEC 62's catalogue and the labels are the ones
 * _myapi_claims_install() writes into field_status' allowed_values. They are
 * hard-coded here — instead of calling myapi_claims_status_options(), which
 * reads the field — for the two reasons the file header states: these texts run
 * inside node_save() and must not change under a site administrator's UI edit,
 * and reading the Field API would drag this whole block out of tests/unit.
 *
 * NULL is a first-class answer, not an error: it is what degrades the
 * requester's title to 'Novedad en tu reclamo' and drops the neighbour's
 * 'Estado:' prefix, with no PHP notice on the way.
 *
 * @param string|null $status  field_status, as stored.
 *
 * @return string|null
 */
function myapi_claim_status_label($status) {
  $labels = array(
    'received'    => 'Recibido',
    'in_progress' => 'En proceso',
    'resolved'    => 'Resuelto',
    'closed'      => 'Cerrado',
  );

  return isset($labels[$status]) ? t($labels[$status]) : NULL;
}

/**
 * One line of text, trimmed, collapsed and cut at $limit with an ellipsis.
 *
 * The collapse happens BEFORE the cut, not after: field_description and
 * field_comment are textareas whose raw value carries newlines and runs of
 * spaces, and a newline inside the first line of a push body would break the
 * three-line layout the app renders. Truncating first would also spend part of
 * the budget on whitespace. Same reasoning, same order, as
 * myapi_claim_transaction_title_comment() (SPEC 60).
 *
 * truncate_utf8() is Drupal core's, with $wordsafe and $add_ellipsis, so the cut
 * lands on a word boundary and ends in '…'. A text of exactly $limit characters
 * or fewer comes back untouched and WITHOUT an ellipsis.
 *
 * preg_replace() returns NULL on invalid UTF-8; the ?: falls back to the
 * original value, so a malformed text degrades to an untidy line instead of an
 * empty one.
 *
 * @param string|null $text   Raw value.
 * @param int         $limit  Maximum characters, ellipsis included.
 *
 * @return string  '' when the text is missing or only whitespace.
 */
function myapi_claim_excerpt($text, $limit) {
  $text = trim((string) $text);
  if ($text === '') {
    return '';
  }

  $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

  return truncate_utf8($text, $limit, TRUE, TRUE);
}

/**
 * A claim date as 'd/m/Y H:i'.
 *
 * The single format of the module's user-facing surfaces — calendar, claims
 * panel, reservation emails — so push, inbox and the five emails never show two
 * date formats to the same person. 'Y-m-d' is a machine format and appears in
 * none of them.
 *
 * @param int $timestamp
 *
 * @return string
 */
function myapi_claim_date_label($timestamp) {
  return format_date($timestamp, 'custom', 'd/m/Y H:i');
}

/**
 * Push + inbox title of the creation event, addressed to the requester.
 *
 * The title carries the EVENT, not the subject: the three events of one claim
 * would otherwise arrive with an identical heading and the resident could not
 * tell, on a locked screen, whether their claim was received, a neighbour
 * published one, or theirs has news.
 *
 * @param string|null $claim_type  field_claim_type, as stored.
 *
 * @return string  'Reclamo recibido' / 'Requerimiento recibido'.
 */
function myapi_claim_push_title($claim_type) {
  return t('@type recibido', array('@type' => myapi_claim_type_label($claim_type)));
}

/**
 * Push + inbox body of the creation event, addressed to the requester.
 *
 *   Fuga de agua en el pasillo
 *   Recibido el 04/08/2026 16:45
 *
 * The claim's description is deliberately out: the requester wrote it, and a
 * long one would arrive cut mid-sentence by the 200-character push limit. It
 * does travel in the email, where it fits.
 *
 * A subject that is empty drops its line rather than leaving a blank one
 * hanging at the top of the body.
 *
 * @param string|null $subject         node.title of the claim.
 * @param int         $reception_date  Timestamp of field_reception_date.
 *
 * @return string
 */
function myapi_claim_push_body($subject, $reception_date) {
  $lines = array();

  $subject = myapi_claim_excerpt($subject, MYAPI_CLAIM_SUBJECT_EXCERPT);
  if ($subject !== '') {
    $lines[] = $subject;
  }

  $lines[] = t('Recibido el @date', array('@date' => myapi_claim_date_label($reception_date)));

  return implode("\n", $lines);
}

/**
 * Push + inbox title of the publication event, addressed to the neighbours.
 *
 * One single text for the two ways a claim reaches a neighbour — born public,
 * or turned public later — because both facts are the same one from their side.
 * The title says before anything else that the claim is not theirs.
 *
 * @param string|null $claim_type  field_claim_type, as stored.
 *
 * @return string  'Nuevo reclamo en tu condominio'.
 */
function myapi_claim_published_push_title($claim_type) {
  return t('Nuevo @noun en tu condominio', array('@noun' => myapi_claim_type_noun($claim_type)));
}

/**
 * Push + inbox body of the publication event, addressed to the neighbours.
 *
 *   Fuga de agua en el pasillo
 *   Publicado el 04/08/2026 16:45
 *
 * The date is the reception one when the claim is born public and the save time
 * when it turns public later: in both cases the line states the same thing —
 * the instant this neighbour could see it — which is why the caller chooses the
 * timestamp and this builder does not.
 *
 * @param string|null $subject    node.title of the claim.
 * @param int         $timestamp  Instant the claim became visible.
 *
 * @return string
 */
function myapi_claim_published_push_body($subject, $timestamp) {
  $lines = array();

  $subject = myapi_claim_excerpt($subject, MYAPI_CLAIM_SUBJECT_EXCERPT);
  if ($subject !== '') {
    $lines[] = $subject;
  }

  $lines[] = t('Publicado el @date', array('@date' => myapi_claim_date_label($timestamp)));

  return implode("\n", $lines);
}

/**
 * Push + inbox title of a transaction, addressed to the requester.
 *
 *   Tu reclamo pasó a "En proceso"
 *
 * The status goes in the TITLE here because it is the whole point of the event
 * and the only thing read on a locked screen. The neighbour's title cannot
 * spend itself on it — it has to say first that the claim is not theirs — so
 * there the status travels in the body.
 *
 * @param string|null $claim_type    field_claim_type, as stored.
 * @param string|null $status_label  Resolved status label, or NULL.
 *
 * @return string  With no resolvable status: 'Novedad en tu reclamo'.
 */
function myapi_claim_transaction_push_title($claim_type, $status_label) {
  $noun = myapi_claim_type_noun($claim_type);

  if ($status_label === NULL || $status_label === '') {
    return t('Novedad en tu @noun', array('@noun' => $noun));
  }

  return t('Tu @noun pasó a "@status"', array('@noun' => $noun, '@status' => $status_label));
}

/**
 * Push + inbox body of a transaction, addressed to the requester.
 *
 *   Fuga de agua en el pasillo
 *   Se asignó un técnico para revisar la tubería del tercer piso.
 *   05/08/2026 09:30
 *
 * An empty comment drops its line and the body stays at two, with no blank line
 * dangling in the middle. The email carries the comment in full; the cut to 120
 * only exists so the 200-character push limit can never reach the first line.
 *
 * @param string|null $subject    node.title of the claim.
 * @param string|null $comment    field_comment of the transaction.
 * @param int         $timestamp  Timestamp of field_status_date.
 *
 * @return string
 */
function myapi_claim_transaction_push_body($subject, $comment, $timestamp) {
  $lines = array();

  $subject = myapi_claim_excerpt($subject, MYAPI_CLAIM_SUBJECT_EXCERPT);
  if ($subject !== '') {
    $lines[] = $subject;
  }

  $comment = myapi_claim_excerpt($comment, MYAPI_CLAIM_COMMENT_EXCERPT);
  if ($comment !== '') {
    $lines[] = $comment;
  }

  $lines[] = myapi_claim_date_label($timestamp);

  return implode("\n", $lines);
}

/**
 * Push + inbox title of a transaction, addressed to the neighbours.
 *
 * @param string|null $claim_type  field_claim_type, as stored.
 *
 * @return string  'Novedad en un reclamo de tu condominio'.
 */
function myapi_claim_transaction_neighbour_push_title($claim_type) {
  return t('Novedad en un @noun de tu condominio', array('@noun' => myapi_claim_type_noun($claim_type)));
}

/**
 * Push + inbox body of a transaction, addressed to the neighbours.
 *
 *   Fuga de agua en el pasillo
 *   Estado: En proceso · 05/08/2026 09:30
 *   Se asignó un técnico para revisar la tubería del tercer piso.
 *
 * With no resolvable status label the 'Estado: … · ' prefix disappears and the
 * middle line is left as the bare date: dropping the whole line would take the
 * only timestamp of the notification with it, and the spec's rule — no line
 * starting with 'Estado:' when there is no label — is what actually matters.
 *
 * An empty comment drops its line, same as the requester's body.
 *
 * @param string|null $subject       node.title of the claim.
 * @param string|null $status_label  Resolved status label, or NULL.
 * @param string|null $comment       field_comment of the transaction.
 * @param int         $timestamp     Timestamp of field_status_date.
 *
 * @return string
 */
function myapi_claim_transaction_neighbour_push_body($subject, $status_label, $comment, $timestamp) {
  $lines = array();

  $subject = myapi_claim_excerpt($subject, MYAPI_CLAIM_SUBJECT_EXCERPT);
  if ($subject !== '') {
    $lines[] = $subject;
  }

  $date = myapi_claim_date_label($timestamp);
  $lines[] = ($status_label === NULL || $status_label === '')
    ? $date
    : t('Estado: @status · @date', array('@status' => $status_label, '@date' => $date));

  $comment = myapi_claim_excerpt($comment, MYAPI_CLAIM_COMMENT_EXCERPT);
  if ($comment !== '') {
    $lines[] = $comment;
  }

  return implode("\n", $lines);
}
