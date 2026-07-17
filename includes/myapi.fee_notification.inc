<?php

/**
 * @file
 * Fee-issued notification trigger for the myapi module.
 *
 * When a 'recibo' or 'alicuota_extra' node transitions to the "Enviado" status
 * while being edited, this notifies the occupants of the referenced unit
 * (field_vivienda) via myapi_notification_create(). It replicates the state
 * transition pattern of the payment approved trigger (spec 27): the hook that
 * drives it lives in myapi.module (glue only) and all the business logic is
 * here.
 */

/**
 * Node status that fires the notification once it is reached on edit. Shared by
 * both 'recibo' and 'alicuota_extra' (stored in field_estado).
 */
define('MYAPI_FEE_STATUS_SENT', 'Enviado');

/**
 * Notification source_type for a 'recibo' node.
 */
define('MYAPI_NOTIFICATION_SOURCE_RECEIPT', 'receipt');

/**
 * Notification source_type for an 'alicuota_extra' node.
 */
define('MYAPI_NOTIFICATION_SOURCE_EXTRA_FEE', 'extra_fee');

/**
 * Notification type emitted when a 'recibo' is sent.
 */
define('MYAPI_NOTIFICATION_TYPE_RECEIPT_SENT', 'receipt_sent');

/**
 * Notification type emitted when an 'alicuota_extra' is sent.
 */
define('MYAPI_NOTIFICATION_TYPE_EXTRA_FEE_SENT', 'extra_fee_sent');

/**
 * Reads the first value of a single-value Field API field on a node.
 *
 * Centralizes the LANGUAGE_NONE[0]['value'] access so callers do not repeat
 * the nested array lookup.
 *
 * @param object $node        The node to read from.
 * @param string $field_name  The field machine name (e.g. 'field_estado').
 *
 * @return mixed  The stored value, or NULL if the field has no value.
 */
function myapi_fee_field_value($node, $field_name) {
  return isset($node->{$field_name}[LANGUAGE_NONE][0]['value'])
    ? $node->{$field_name}[LANGUAGE_NONE][0]['value']
    : NULL;
}

/**
 * Detects the transition of a fee node to the "Enviado" status on edit.
 *
 * Only returns TRUE during a node_update: an inserted node has no
 * $node->original, which aligns with "only the transition on edit fires". The
 * previous value may be any value other than "Enviado" (a draft, another
 * status, or NULL if the node had no field_estado); any of those counts as
 * "not yet sent" and reaching "Enviado" fires exactly once.
 *
 * @param object $node  The node being updated.
 *
 * @return bool  TRUE if field_estado moved from a non-"Enviado" value to
 *   "Enviado", FALSE otherwise.
 */
function myapi_fee_is_sent_transition($node) {
  if (!isset($node->original)) {
    return FALSE;
  }
  $previous = myapi_fee_field_value($node->original, 'field_estado');
  $incoming = myapi_fee_field_value($node, 'field_estado');

  return $previous !== MYAPI_FEE_STATUS_SENT
    && $incoming === MYAPI_FEE_STATUS_SENT;
}

/**
 * Builds and dispatches the fee-issued notification for a fee node.
 *
 * Resolves the unit (field_vivienda), its condominium (field_condominio of the
 * unit) and its title, then notifies the occupants of the unit. Behaviour is
 * parameterized by $node->type ('recibo' vs 'alicuota_extra'): only the value
 * field, the inserted word ("extra ") and the notification source_type/type
 * differ. If the node type is unknown, or the unit cannot be resolved (empty
 * uids), myapi_notification_create() is a no-op and nothing is created.
 *
 * Notifies at most once per node per request (drupal_static guard): a fee node
 * can be re-saved within the same request (e.g. a Rule recalculating balances
 * reuses the same $node object, so $node->original keeps its stale
 * pre-transition value and myapi_fee_is_sent_transition() would fire again).
 *
 * @param object $node  The fee node that just transitioned to "Enviado".
 */
function myapi_fee_notify_issued($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.notification');
  module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

  $map = [
    'recibo' => [
      'source' => MYAPI_NOTIFICATION_SOURCE_RECEIPT,
      'type'   => MYAPI_NOTIFICATION_TYPE_RECEIPT_SENT,
      'field'  => 'field_total_mes',
      'word'   => '',
    ],
    'alicuota_extra' => [
      'source' => MYAPI_NOTIFICATION_SOURCE_EXTRA_FEE,
      'type'   => MYAPI_NOTIFICATION_TYPE_EXTRA_FEE_SENT,
      'field'  => 'field_valor_extra',
      'word'   => 'extra ',
    ],
  ];
  if (!isset($map[$node->type])) {
    return;
  }
  $cfg = $map[$node->type];

  // Notify at most once per node per request: guards against a re-save within
  // the same request that carries a stale $node->original (see docblock).
  $notified = &drupal_static(__FUNCTION__, []);
  if (isset($notified[$node->nid])) {
    return;
  }
  $notified[$node->nid] = TRUE;

  $unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id'])
    ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id']
    : NULL;

  $unit_title = '';
  $condominium_id = NULL;
  if ($unit_id !== NULL) {
    $vivienda = node_load($unit_id);
    if ($vivienda) {
      $unit_title = $vivienda->title;
      if (isset($vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'])) {
        $condominium_id = (int) $vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'];
      }
    }
  }

  $amount = number_format((float) myapi_fee_field_value($node, $cfg['field']), 2);
  $uids = $unit_id !== NULL
    ? myapi_unit_member_uids([$unit_id], 'ocupantes')
    : [];

  myapi_notification_create([
    'source_type'      => $cfg['source'],
    'source_nid'       => (int) $node->nid,
    'type'             => $cfg['type'],
    'title'            => 'Nueva alícuota ' . $cfg['word'] . 'generada',
    'body'             => 'Nueva alícuota ' . $cfg['word'] . 'registrada para '
                          . $unit_title . "\nValor total: " . $amount,
    'deep_link_target' => $cfg['source'],
    'deep_link_id'     => (int) $node->nid,
    'condominium_id'   => $condominium_id,
    'unit_id'          => $unit_id,
    'uids'             => $uids,
  ]);
}
