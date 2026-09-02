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
 * Audience of a push: the notice is addressed to a resident (SPEC 109).
 *
 * Default of myapi_notification_create(), so every trigger written before that
 * spec keeps emitting it without changing a line.
 */
define('MYAPI_NOTIFICATION_AUDIENCE_RESIDENT', 'resident');

/**
 * Audience of a push: the notice is addressed to a provider (SPEC 109).
 *
 * The app reads it together with the 'provider' key of the same payload to
 * decide which side of itself to open.
 */
define('MYAPI_NOTIFICATION_AUDIENCE_PROVIDER', 'provider');

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
 * field_mensaje may come from a WYSIWYG, so it can carry markup and the source
 * indentation of that markup. The inbox and the push both need plain text (an
 * HTML push banner would show literal tags), so the notification is stored
 * already flattened. Block-level breaks become newlines first so basic
 * structure survives, then tags are stripped and entities decoded.
 *
 * Whitespace is then tidied so the banner reads cleanly: every line is trimmed
 * and its internal space/tab runs squeezed to a single space (killing the
 * source indentation like "    2"), and blank lines are dropped entirely — a
 * push reads better as tight lines than as text spread out by empty paragraphs.
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

  // A &nbsp; decodes to a non-breaking space (U+00A0); treat it as a normal one.
  $text = str_replace("\xC2\xA0", ' ', $text);

  // Normalize line endings so the per-line pass below is predictable.
  $text = preg_replace('/\r\n?/', "\n", $text);

  // Trim each line and squeeze internal space/tab runs, then drop blank lines so
  // WYSIWYG indentation and empty paragraphs do not leak through as extra space.
  $lines = array_filter(array_map(function ($line) {
    return trim(preg_replace('/[ \t]+/', ' ', $line));
  }, explode("\n", $text)), 'strlen');

  return implode("\n", $lines);
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
  // Strict lookup, and not a switch, because switch compares LOOSELY: under
  // PHP 7.4 — the version production runs — 0 == 'Propietarios' is TRUE, so an
  // integer scope reaching this function answered 'propietarios' instead of
  // NULL and the caller notified every owner instead of nobody. The comparison
  // changed in PHP 8, which is why it survived every local run: the bug only
  // exists on the target. Found by the CI of SPEC 123, the first time the suite
  // ran on 7.4.
  //
  // The is_string() guard is what makes the lookup safe for any input: an int
  // key can never match these string keys, and an array as an offset is a
  // TypeError rather than a miss.
  $map = [
    'Propietarios' => 'propietarios',
    'Ocupantes'    => 'ocupantes',
    'Todos'        => 'todos',
  ];

  return (is_string($role) && isset($map[$role])) ? $map[$role] : NULL;
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

  $scope_raw = myapi_notification_field_value($node, 'field_tipo_de_boletin');
  $role = myapi_notification_field_value($node, 'field_enviar_a');
  $role_canonical = myapi_boletin_role_canonical($role);

  // The switch below compares loosely, and on PHP 7.4 that means 0 == 'General'
  // is TRUE: a non-string scope would take the General branch and fan out to
  // every published unit — the exact opposite of the guarantee in this
  // function's docblock. Field API returns whatever the field stores (see
  // myapi_notification_field_value()), so the type is not ours to assume.
  // Anything that is not a string becomes a value no case can match, on both
  // PHP versions; the raw one is kept for the log.
  $scope = is_string($scope_raw) ? $scope_raw : '';

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
      watchdog('myapi', 'Boletin @nid: unknown field_tipo_de_boletin value "@scope"; nobody notified.', ['@nid' => $node->nid, '@scope' => is_scalar($scope_raw) ? (string) $scope_raw : ''], WATCHDOG_WARNING);
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
 * Lists the uids of the ACTIVE users holding one role, matched by NAME.
 *
 * Generalized from myapi_reservation_backend_uids() (SPEC 48), which now
 * delegates here: the same three rules apply to every caller.
 * - Role matched by name and never by rid, because the rid differs between
 *   environments (same reasoning documented for myapi_calendar_admin_roles()).
 * - Blocked accounts excluded by users.status = 1.
 * - No implicit membership for uid 1: it only appears if the role is actually
 *   assigned to it.
 *
 * @param string $role_name  Role name, e.g. 'backend'.
 *
 * @return array  Unique uids as integers. Empty when nobody holds the role.
 */
function myapi_notification_role_uids($role_name) {
  $query = db_select('users', 'u');
  $query->fields('u', array('uid'));
  $query->innerJoin('users_roles', 'ur', 'ur.uid = u.uid');
  $query->innerJoin('role', 'r', 'r.rid = ur.rid');
  $query->condition('r.name', $role_name);
  $query->condition('u.status', 1);
  $query->condition('u.uid', 0, '>');
  $query->distinct();

  $uids = $query->execute()->fetchCol();

  return array_map('intval', $uids);
}

/**
 * Lists the uids of the ACTIVE building admins of one condominium (SPEC 49).
 *
 * Generalized from myapi_reservation_building_admin_uids() (SPEC 48), which now
 * delegates here. Same shape as myapi_notification_role_uids() — role matched by
 * name, blocked accounts out, no implicit membership for uid 1 — plus a join on
 * the user's field_condominio_admin, so only the operators of THIS condominium
 * come back. Sending the detail to the admins of other buildings would be noise
 * and a leak of other residents' data.
 *
 * Two cases return an empty list without touching the database:
 * - No condominium resolved. There is nobody to scope to.
 * - An environment where field_condominio_admin does not exist yet, i.e. the
 *   deploy window between pushing this code and running `drush updb`. Querying
 *   its data table there would be a fatal error in the middle of a node save.
 *   field_info_field() is served from cache, so the check is free.
 *
 * @param int|NULL $condominium_id  Condominium nid to scope to.
 *
 * @return array  Unique uids as integers. Empty when nobody matches.
 */
function myapi_notification_building_admin_uids($condominium_id) {
  if ($condominium_id === NULL) {
    return array();
  }

  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $field = MYAPI_BUILDING_ADMIN_CONDO_FIELD;
  if (!field_info_field($field)) {
    return array();
  }

  $query = db_select('users', 'u');
  $query->fields('u', array('uid'));
  $query->innerJoin('users_roles', 'ur', 'ur.uid = u.uid');
  $query->innerJoin('role', 'r', 'r.rid = ur.rid');
  $query->innerJoin('field_data_' . $field, 'fca', "fca.entity_id = u.uid AND fca.entity_type = 'user' AND fca.deleted = 0");
  $query->condition('r.name', MYAPI_BUILDING_ADMIN_ROLE);
  $query->condition('fca.' . $field . '_target_id', $condominium_id);
  $query->condition('u.status', 1);
  $query->condition('u.uid', 0, '>');
  $query->distinct();

  $uids = $query->execute()->fetchCol();

  return array_map('intval', $uids);
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
 *                       'condominium_id' (optional), 'unit_id' (optional),
 *                       'provider_id' (optional, SPEC 109) and 'audience'
 *                       (optional, SPEC 109: 'resident' by default).
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
  // SPEC 109. provider_id is persisted AND travels in the push; audience only
  // travels in the push (it is derivable from the type and the provider, so
  // there is nothing to gain from storing it). Every caller before this spec
  // passes neither, and so keeps provider_id = NULL and audience = 'resident'.
  $provider_id = isset($params['provider_id']) ? (int) $params['provider_id'] : NULL;
  $audience = isset($params['audience']) ? $params['audience'] : MYAPI_NOTIFICATION_AUDIENCE_RESIDENT;

  $insert = db_insert('myapi_notifications')->fields([
    'uid', 'source_type', 'source_nid', 'type', 'title', 'body',
    'deep_link_target', 'deep_link_id', 'condominium_id', 'unit_id',
    'provider_id', 'is_read', 'created', 'read_at',
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
      'provider_id'      => $provider_id,
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
    'audience'           => $audience,
    'provider'           => $provider_id,
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

  // For a 'Condominio' bulletin every recipient belongs to the same condominium,
  // so the single per-batch condominium_id is exact. 'General' and 'Personalizado'
  // span several (or unrelated) condominiums, so it stays NULL for those.
  $condominium_id = NULL;
  if (myapi_notification_field_value($node, 'field_tipo_de_boletin') === 'Condominio') {
    $condo_nid = myapi_notification_field_target_id($node, 'field_condominio');
    if ($condo_nid) {
      $condominium_id = (int) $condo_nid;
    }
  }

  return myapi_notification_create([
    'source_type'      => MYAPI_NOTIFICATION_SOURCE_BOLETIN,
    'source_nid'       => (int) $node->nid,
    'type'             => MYAPI_NOTIFICATION_TYPE_BULLETIN,
    'title'            => $node->title,
    'body'             => myapi_notification_plain_text(myapi_notification_field_value($node, 'field_mensaje')),
    'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN,
    'deep_link_id'     => (int) $node->nid,
    'condominium_id'   => $condominium_id,
    'uids'             => $uids,
  ]);
}
