<?php

/**
 * @file
 * Notification inbox helpers for the myapi module.
 *
 * Single reusable entry point (myapi_notification_create()) that fans a
 * notification out to the myapi_notifications table (one row per recipient) and
 * enqueues the OneSignal push. In this spec the only trigger is a published
 * 'boletin' node (hook_node_insert in myapi.module delegates here); future
 * triggers (alicuota created, payment approved) will call the same functions.
 *
 * Recipient resolution reads Field API tables directly (field_data_field_*): a
 * rename or cardinality change on those fields breaks resolution silently. Kept
 * intentional and documented (same trade-off as specs 09/10/11).
 */

/**
 * source_type value for notifications originated by a 'boletin' node.
 */
define('MYAPI_NOTIFICATION_SOURCE_BOLETIN', 'boletin');

/**
 * Notification category (4.2.7) for a bulletin. Constant, not the audience
 * field field_tipo_de_boletin.
 */
define('MYAPI_NOTIFICATION_TYPE_BULLETIN', 'bulletin');

/**
 * App section the bulletin deep-link points to.
 */
define('MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN', 'bulletin');

/**
 * source_type value for notifications originated by a 'pagos' node.
 */
define('MYAPI_NOTIFICATION_SOURCE_PAYMENT', 'payment');

/**
 * Notification category (4.2.7) for a payment reaching "Completado".
 */
define('MYAPI_NOTIFICATION_TYPE_PAYMENT_APPROVED', 'payment_approved');

/**
 * Notification category (4.2.7) for a payment reaching "Anulado".
 */
define('MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED', 'payment_cancelled');

/**
 * App section the payment deep-link points to.
 */
define('MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT', 'payment');

/**
 * Name of the cron queue that carries OneSignal push batches.
 *
 * Also defined in myapi.module (always loaded) so hook_cron_queue_info() can
 * register the worker without depending on this include being loaded first;
 * guarded here to avoid a redefinition notice.
 */
if (!defined('MYAPI_ONESIGNAL_QUEUE')) {
  define('MYAPI_ONESIGNAL_QUEUE', 'myapi_onesignal_push');
}

/**
 * Reads the first value of a single-value Field API field on a node.
 *
 * @param object $node   The node to read from.
 * @param string $field  Field machine name (e.g. 'field_mensaje').
 *
 * @return mixed  The stored 'value', or NULL if the field is empty.
 */
function myapi_notification_field_value($node, $field) {
  if (isset($node->{$field}[LANGUAGE_NONE][0]['value'])) {
    return $node->{$field}[LANGUAGE_NONE][0]['value'];
  }
  return NULL;
}

/**
 * Reduces a rich-text (possibly HTML) value to clean plain text.
 *
 * field_mensaje may come from a WYSIWYG, so it can carry markup. The inbox and
 * the push both need plain text (an HTML push banner would show literal tags),
 * so the notification is stored already flattened. Block-level breaks are turned
 * into newlines first so basic structure survives, then tags are stripped and
 * entities decoded.
 *
 * @param string|null $value  Raw field value.
 *
 * @return string|null  Plain-text version, or NULL when the input is NULL.
 */
function myapi_notification_plain_text($value) {
  if ($value === NULL || $value === '') {
    return $value;
  }

  $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $value);
  $text = preg_replace('#</\s*(p|div|li|h[1-6]|tr|ul|ol|table)\s*>#i', "\n", $text);
  $text = strip_tags($text);
  $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

  // Tidy whitespace: drop trailing spaces on lines and collapse blank runs.
  $text = preg_replace("/[ \t]+\n/", "\n", $text);
  $text = preg_replace("/\n{3,}/", "\n\n", $text);

  return trim($text);
}

/**
 * Reads the first target_id of an entity-reference field on a node.
 *
 * @param object $node   The node to read from.
 * @param string $field  Field machine name (e.g. 'field_condominio').
 *
 * @return int|null  The referenced entity id, or NULL if the field is empty.
 */
function myapi_notification_field_target_id($node, $field) {
  if (isset($node->{$field}[LANGUAGE_NONE][0]['target_id'])) {
    return (int) $node->{$field}[LANGUAGE_NONE][0]['target_id'];
  }
  return NULL;
}

/**
 * Reads every target_id of a multi-value entity-reference field on a node.
 *
 * @param object $node   The node to read from.
 * @param string $field  Field machine name (e.g. 'field_personalizar').
 *
 * @return array  List of referenced entity ids (may be empty).
 */
function myapi_notification_field_target_ids($node, $field) {
  $ids = [];
  if (!empty($node->{$field}[LANGUAGE_NONE])) {
    foreach ($node->{$field}[LANGUAGE_NONE] as $item) {
      if (isset($item['target_id'])) {
        $ids[] = (int) $item['target_id'];
      }
    }
  }
  return $ids;
}

/**
 * Maps a field_enviar_a value to the canonical role used by the unit-access
 * helpers.
 *
 * @param string $role  'Propietarios', 'Ocupantes' or 'Todos'.
 *
 * @return string|null  'propietarios' | 'ocupantes' | 'todos', or NULL for an
 *                      unknown value (fail-safe: caller notifies nobody).
 */
function myapi_boletin_role_canonical($role) {
  switch ($role) {
    case 'Propietarios':
      return 'propietarios';

    case 'Ocupantes':
      return 'ocupantes';

    case 'Todos':
      return 'todos';

    default:
      return NULL;
  }
}

/**
 * Resolves the recipient uids of a 'boletin' node by crossing its audience
 * scope (field_tipo_de_boletin) with its role (field_enviar_a).
 *
 * | Scope         | Universe                                   | Role filters |
 * | General       | Owners/occupants of every published unit   | yes          |
 * | Condominio    | Owners/occupants of the units of field_condominio | yes   |
 * | Personalizado | Users listed on the node itself            | yes (mapped) |
 *
 * Unknown scope or role degrades to an empty set (never an accidental fan-out
 * to everyone) plus a watchdog. Result is filtered to active users and deduped.
 *
 * @param object $node  The 'boletin' node.
 *
 * @return array  Unique list of active recipient uids (may be empty).
 */
function myapi_boletin_recipient_uids($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

  $scope = myapi_notification_field_value($node, 'field_tipo_de_boletin');
  $role = myapi_notification_field_value($node, 'field_enviar_a');
  $role_canonical = myapi_boletin_role_canonical($role);

  $uids = [];

  switch ($scope) {
    case 'General':
      if ($role_canonical === NULL) {
        watchdog('myapi', 'Boletin @nid: unknown field_enviar_a value "@role"; nobody notified.', ['@nid' => $node->nid, '@role' => (string) $role], WATCHDOG_WARNING);
        break;
      }
      $unit_nids = db_select('node', 'n')
        ->fields('n', ['nid'])
        ->condition('type', 'vivienda')
        ->condition('status', 1)
        ->execute()
        ->fetchCol();
      $uids = myapi_unit_member_uids($unit_nids, $role_canonical);
      break;

    case 'Condominio':
      if ($role_canonical === NULL) {
        watchdog('myapi', 'Boletin @nid: unknown field_enviar_a value "@role"; nobody notified.', ['@nid' => $node->nid, '@role' => (string) $role], WATCHDOG_WARNING);
        break;
      }
      $condo_nid = myapi_notification_field_target_id($node, 'field_condominio');
      if ($condo_nid) {
        $uids = myapi_condominium_member_uids([$condo_nid], $role_canonical);
      }
      break;

    case 'Personalizado':
      // Propietarios -> field_personalizar; Ocupantes -> field_ocupantes;
      // Todos -> both. field_condominio is ignored for this scope.
      if ($role === 'Propietarios' || $role === 'Todos') {
        $uids = array_merge($uids, myapi_notification_field_target_ids($node, 'field_personalizar'));
      }
      if ($role === 'Ocupantes' || $role === 'Todos') {
        $uids = array_merge($uids, myapi_notification_field_target_ids($node, 'field_ocupantes'));
      }
      if ($role_canonical === NULL) {
        watchdog('myapi', 'Boletin @nid: unknown field_enviar_a value "@role"; nobody notified.', ['@nid' => $node->nid, '@role' => (string) $role], WATCHDOG_WARNING);
      }
      break;

    default:
      watchdog('myapi', 'Boletin @nid: unknown field_tipo_de_boletin value "@scope"; nobody notified.', ['@nid' => $node->nid, '@scope' => (string) $scope], WATCHDOG_WARNING);
      return [];
  }

  if (empty($uids)) {
    return [];
  }

  // Keep only active users, deduped.
  $uids = array_values(array_unique(array_map('intval', $uids)));
  $active = db_select('users', 'u')
    ->fields('u', ['uid'])
    ->condition('uid', $uids, 'IN')
    ->condition('status', 1)
    ->execute()
    ->fetchCol();

  return array_map('intval', $active);
}

/**
 * Creates a notification: fan-out to myapi_notifications (one row per uid) and
 * enqueue of the OneSignal push. Reusable entry point for any trigger.
 *
 * The DB insert is synchronous (the inbox is available immediately); the push
 * is deferred to the cron queue so a slow/down OneSignal never blocks the
 * caller. With no recipients nothing is inserted or enqueued.
 *
 * @param array $params  Keys: 'source_type', 'source_nid', 'type', 'title',
 *                       'body', 'deep_link_target', 'deep_link_id', 'uids',
 *                       'condominium_id' (optional), 'unit_id' (optional).
 *
 * @return int  Number of rows inserted (recipients).
 */
function myapi_notification_create(array $params) {
  $uids = isset($params['uids']) ? array_values(array_unique(array_map('intval', $params['uids']))) : [];
  if (empty($uids)) {
    return 0;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.onesignal');

  $now = REQUEST_TIME;
  $source_nid = isset($params['source_nid']) ? $params['source_nid'] : NULL;
  $body = isset($params['body']) ? $params['body'] : NULL;
  $deep_link_target = isset($params['deep_link_target']) ? $params['deep_link_target'] : NULL;
  $deep_link_id = isset($params['deep_link_id']) ? $params['deep_link_id'] : NULL;
  $condominium_id = isset($params['condominium_id']) ? (int) $params['condominium_id'] : NULL;
  $unit_id = isset($params['unit_id']) ? (int) $params['unit_id'] : NULL;

  $insert = db_insert('myapi_notifications')->fields([
    'uid', 'source_type', 'source_nid', 'type', 'title', 'body',
    'deep_link_target', 'deep_link_id', 'condominium_id', 'unit_id',
    'is_read', 'created', 'read_at',
  ]);
  foreach ($uids as $uid) {
    $insert->values([
      'uid'              => $uid,
      'source_type'      => $params['source_type'],
      'source_nid'       => $source_nid,
      'type'             => $params['type'],
      'title'            => $params['title'],
      'body'             => $body,
      'deep_link_target' => $deep_link_target,
      'deep_link_id'     => $deep_link_id,
      'condominium_id'   => $condominium_id,
      'unit_id'          => $unit_id,
      'is_read'          => 0,
      'created'          => $now,
      'read_at'          => NULL,
    ]);
  }
  $insert->execute();

  // Deferred push: one queue item per batch of external ids.
  $data = [
    'target'             => $deep_link_target,
    'id'                 => $deep_link_id,
    'unit'               => $unit_id,
    'condominium'        => $condominium_id,
    'notification_type'  => $params['type'],
  ];
  $queue = DrupalQueue::get(MYAPI_ONESIGNAL_QUEUE);
  foreach (array_chunk($uids, MYAPI_ONESIGNAL_MAX_EXTERNAL_IDS) as $chunk) {
    $queue->createItem([
      'external_ids' => array_map('strval', $chunk),
      'title'        => $params['title'],
      'body'         => $body !== NULL ? $body : '',
      'data'         => $data,
    ]);
  }

  return count($uids);
}

/**
 * Glue: extracts the fields of a 'boletin' node, resolves its recipients and
 * delegates to myapi_notification_create(). Called from hook_node_insert().
 *
 * @param object $node  The published 'boletin' node.
 *
 * @return int  Number of recipients notified.
 */
function myapi_notification_create_from_boletin($node) {
  $uids = myapi_boletin_recipient_uids($node);
  if (empty($uids)) {
    return 0;
  }

  return myapi_notification_create([
    'source_type'      => MYAPI_NOTIFICATION_SOURCE_BOLETIN,
    'source_nid'       => (int) $node->nid,
    'type'             => MYAPI_NOTIFICATION_TYPE_BULLETIN,
    'title'            => $node->title,
    'body'             => myapi_notification_plain_text(myapi_notification_field_value($node, 'field_mensaje')),
    'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN,
    'deep_link_id'     => (int) $node->nid,
    'uids'             => $uids,
  ]);
}
