<?php

/**
 * @file
 * Payment verification workflow for the myapi module.
 *
 * Replicates the legacy Rules component 'rules_actualizar_saldo_pago', but
 * triggered by the verification transition of a 'pagos' node instead of by its
 * creation. When a payment moves from "Pendiente de verificar" to "Nuevo" it
 * discounts the amount from the unit balance, adds it to the condominium cash
 * balance, forces the payment to "Completado" and cancels the pending
 * rules_scheduler tasks of that unit.
 *
 * The hook that drives this workflow lives in myapi.module (glue only); all the
 * business logic is here.
 */

/**
 * Payment status that a payment must have in the database (its previous value)
 * for the verification transition to fire.
 */
define('MYAPI_PAYMENT_STATUS_PENDING', 'Pendiente de verificar');

/**
 * Incoming payment status that triggers the verification. It is transient: it
 * is never persisted, the payment ends up as MYAPI_PAYMENT_STATUS_COMPLETED.
 */
define('MYAPI_PAYMENT_STATUS_TRIGGER', 'Nuevo');

/**
 * Final payment status written to the incoming node during presave.
 */
define('MYAPI_PAYMENT_STATUS_COMPLETED', 'Completado');

/**
 * Payment status written when a pending payment is cancelled by its owner or
 * occupant (PUT /api/v1/payments/%/cancel).
 */
define('MYAPI_PAYMENT_STATUS_CANCELLED', 'Anulado');

/**
 * Reads the first value of a single-value Field API field on a node.
 *
 * Centralizes the LANGUAGE_NONE[0]['value'] access so callers do not repeat
 * the nested array lookup.
 *
 * @param object $node   The node to read from.
 * @param string $field  The field machine name (e.g. 'field_valor').
 *
 * @return mixed  The stored value, or NULL if the field has no value.
 */
function myapi_payment_field_value($node, $field) {
  if (isset($node->{$field}[LANGUAGE_NONE][0]['value'])) {
    return $node->{$field}[LANGUAGE_NONE][0]['value'];
  }
  return NULL;
}

/**
 * Detects the exact verification transition of a 'pagos' node.
 *
 * The transition fires only when the payment is being updated (so it has a
 * loaded $node->original) and its status moves from "Pendiente de verificar"
 * (previous value in the database) to "Nuevo" (incoming value). A node insert
 * has no $node->original, so it never fires here.
 *
 * @param object $node  The 'pagos' node being saved.
 *
 * @return bool  TRUE only for the "Pendiente de verificar" -> "Nuevo" update.
 */
function myapi_payment_is_verification_transition($node) {
  if (!isset($node->original)) {
    return FALSE;
  }

  $previous = myapi_payment_field_value($node->original, 'field_estado_pago');
  $incoming = myapi_payment_field_value($node, 'field_estado_pago');

  return $previous === MYAPI_PAYMENT_STATUS_PENDING
    && $incoming === MYAPI_PAYMENT_STATUS_TRIGGER;
}

/**
 * Detects the "Nuevo" -> "Completado" transition produced by the legacy Rule
 * 'rules_actualizar_saldo_pago'.
 *
 * That Rule reacts to the insert of a 'pagos' node already carrying
 * "Nuevo" (created directly in that status, typically from the Drupal admin),
 * applies the same balance changes as myapi_payment_apply_verification() and
 * sets field_estado_pago to "Completado" via a Rules data_set action. Rules'
 * auto-save then persists that change with a second node_save() (an update),
 * which is the point this function detects: it does not repeat the balance
 * work, only observes the transition already committed by the Rule.
 *
 * @param object $node  The 'pagos' node being saved.
 *
 * @return bool  TRUE only for the "Nuevo" -> "Completado" update.
 */
function myapi_payment_is_rule_completion($node) {
  if (!isset($node->original)) {
    return FALSE;
  }

  $previous = myapi_payment_field_value($node->original, 'field_estado_pago');
  $incoming = myapi_payment_field_value($node, 'field_estado_pago');

  return $previous === MYAPI_PAYMENT_STATUS_TRIGGER
    && $incoming === MYAPI_PAYMENT_STATUS_COMPLETED;
}

/**
 * Detects an update that moves a 'pagos' node into "Anulado" from the Drupal
 * back office.
 *
 * Fires on any update whose incoming field_estado_pago is "Anulado" and whose
 * previous value was not already "Anulado" (any prior status qualifies; this
 * only guards against re-firing on an update that leaves the payment
 * cancelled). The endpoint at PUT /api/v1/payments/%/cancel (spec 23) sets
 * $node->myapi_skip_cancel_notification before its own node_save() so that its
 * own cancellation never fires here.
 *
 * @param object $node  The 'pagos' node being saved.
 *
 * @return bool  TRUE only for a back-office "* -> Anulado" update, excluding
 *               opt-out saves.
 */
function myapi_payment_is_cancellation_transition($node) {
  if (!empty($node->myapi_skip_cancel_notification)) {
    return FALSE;
  }
  if (!isset($node->original)) {
    return FALSE;
  }

  $previous = myapi_payment_field_value($node->original, 'field_estado_pago');
  $incoming = myapi_payment_field_value($node, 'field_estado_pago');

  return $incoming === MYAPI_PAYMENT_STATUS_CANCELLED
    && $previous !== MYAPI_PAYMENT_STATUS_CANCELLED;
}

/**
 * Applies the verification workflow to a 'pagos' node in presave.
 *
 * Validates the preconditions and, only if they all pass, discounts the amount
 * from the unit balance, adds it to the condominium cash balance, forces the
 * payment to "Completado" and cancels the pending scheduled tasks. If any
 * precondition fails it returns without any effect: balances are untouched and
 * the payment keeps its incoming status (it is NOT forced to "Completado").
 *
 * @param object $node  The 'pagos' node being saved (modified in place).
 */
function myapi_payment_apply_verification($node) {
  // Precondition 1: the amount must be numeric and strictly positive.
  $amount = myapi_payment_field_value($node, 'field_valor');
  if (!is_numeric($amount) || (float) $amount <= 0) {
    return;
  }

  // Precondition 2: the payment must reference a published 'vivienda' node.
  if (!isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id'])) {
    return;
  }
  $vivienda_nid = $node->field_vivienda[LANGUAGE_NONE][0]['target_id'];
  $vivienda = node_load($vivienda_nid);
  if (!$vivienda || $vivienda->type !== 'vivienda' || empty($vivienda->status)) {
    return;
  }

  // Precondition 3: the unit must reference an existing 'condominio' node.
  if (!isset($vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'])) {
    return;
  }
  $condominio_nid = $vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'];
  $condominio = node_load($condominio_nid);
  if (!$condominio || $condominio->type !== 'condominio') {
    return;
  }

  // Discount the amount from the unit balance (NULL treated as 0) and save it
  // as a new revision.
  $saldo = (float) myapi_payment_field_value($vivienda, 'field_saldo_actual');
  $vivienda->field_saldo_actual[LANGUAGE_NONE][0]['value'] = $saldo - (float) $amount;
  $vivienda->revision = 1;
  node_save($vivienda);

  // Add the amount to the condominium cash balance (NULL treated as 0) and save
  // it as a new revision.
  $caja = (float) myapi_payment_field_value($condominio, 'field_saldo_caja');
  $condominio->field_saldo_caja[LANGUAGE_NONE][0]['value'] = $caja + (float) $amount;
  $condominio->revision = 1;
  node_save($condominio);

  // Force the payment status on the incoming node: the in-progress node_save()
  // persists "Completado" directly, so "Nuevo" is never stored and there is no
  // second save nor recursion on the payment itself.
  $node->field_estado_pago[LANGUAGE_NONE][0]['value'] = MYAPI_PAYMENT_STATUS_COMPLETED;

  // Cancel the pending reminder/penalty tasks scheduled for this unit.
  myapi_payment_cancel_scheduled_tasks($vivienda_nid);

  // Notify the payment's author that it was approved.
  myapi_payment_notify_approved($node);
}

/**
 * Cancels the pending rules_scheduler tasks of a unit.
 *
 * Replicates the four schedule_delete of the legacy rule by removing the
 * pending rows of the reminder and penalty components for this unit. Each row
 * is identified by its component name ('config') and identifier, where {nid} is
 * the unit nid. The delete is idempotent: if no matching row exists, nothing is
 * removed and no error is raised.
 *
 * @param int $vivienda_nid  The nid of the referenced 'vivienda' node.
 */
function myapi_payment_cancel_scheduled_tasks($vivienda_nid) {
  $tasks = array(
    'rules_recordatorio_pago'              => 'recordatorio ' . $vivienda_nid,
    'rules_recalcular_con_penalizacion'    => 'penalizacion 10 ' . $vivienda_nid,
    'rules_recalcular_con_penalizacion_15' => 'penalizacion 15 ' . $vivienda_nid,
    'rules_recalcular_con_penalizacion_31' => 'penalizacion 31 ' . $vivienda_nid,
  );

  foreach ($tasks as $config => $identifier) {
    db_delete('rules_scheduler')
      ->condition('config', $config)
      ->condition('identifier', $identifier)
      ->execute();
  }
}

/**
 * Notifies the author of a 'pagos' node that their payment was approved.
 *
 * Called both from the end of myapi_payment_apply_verification() (the payment
 * already has $node->nid because it is an update) and from a dedicated branch
 * in hook_node_insert() for a node created directly in "Completado" (see
 * myapi.module). Missing field_referencia/field_valor/field_vivienda do not
 * block the notification: it is built with whatever the node has (empty
 * reference, "0.00" amount, NULL unit_id/condominium_id).
 *
 * @param object $node  The 'pagos' node whose status just reached
 *                       MYAPI_PAYMENT_STATUS_COMPLETED.
 */
function myapi_payment_notify_approved($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.notification');

  $reference = myapi_payment_field_value($node, 'field_referencia');
  $reference = $reference !== NULL ? $reference : '';
  $amount = number_format((float) myapi_payment_field_value($node, 'field_valor'), 2);

  $unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id'])
    ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id']
    : NULL;

  $condominium_id = NULL;
  if ($unit_id !== NULL) {
    $vivienda = node_load($unit_id);
    if ($vivienda && isset($vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'])) {
      $condominium_id = (int) $vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'];
    }
  }

  myapi_notification_create([
    'source_type'      => MYAPI_NOTIFICATION_SOURCE_PAYMENT,
    'source_nid'       => (int) $node->nid,
    'type'             => MYAPI_NOTIFICATION_TYPE_PAYMENT_APPROVED,
    'title'            => 'Pago aprobado — Ref. ' . $reference,
    'body'             => 'Tu pago de ' . $amount . " ha sido aprobado.\nReferencia: " . $reference . "\nGracias.",
    'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT,
    'deep_link_id'     => (int) $node->nid,
    'condominium_id'   => $condominium_id,
    'unit_id'          => $unit_id,
    'uids'             => myapi_payment_notify_recipients($node, $unit_id),
  ]);
}

/**
 * Notifies the recipient of a 'pagos' node that their payment was cancelled.
 *
 * Called from the "Anulado" branch of hook_node_update() (see myapi.module)
 * when the transition was made from the Drupal back office, never for the
 * opt-out save performed by PUT /api/v1/payments/%/cancel (spec 23). Reuses
 * myapi_payment_notify_recipients() unmodified, so the recipient resolution
 * (author, or occupant when the author is an administrator) is identical to
 * the "payment approved" notification. Missing field_referencia/field_valor/
 * field_vivienda do not block the notification: it is built with whatever the
 * node has (empty reference, "0.00" amount, NULL unit_id/condominium_id).
 *
 * @param object $node  The 'pagos' node whose status just reached
 *                       MYAPI_PAYMENT_STATUS_CANCELLED.
 */
function myapi_payment_notify_cancelled($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.notification');

  $reference = myapi_payment_field_value($node, 'field_referencia');
  $reference = $reference !== NULL ? $reference : '';
  $amount = number_format((float) myapi_payment_field_value($node, 'field_valor'), 2);

  $reason = myapi_payment_field_value($node, 'field_detalle');
  $reason = ($reason !== NULL && trim($reason) !== '') ? trim($reason) : NULL;

  $body = 'Tu pago de ' . $amount . ' ha sido anulado.';
  if ($reason !== NULL) {
    $body .= "\nMotivo: " . $reason;
  }
  $body .= "\nReferencia: " . $reference;

  $unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id'])
    ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id']
    : NULL;

  $condominium_id = NULL;
  if ($unit_id !== NULL) {
    $vivienda = node_load($unit_id);
    if ($vivienda && isset($vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'])) {
      $condominium_id = (int) $vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'];
    }
  }

  myapi_notification_create([
    'source_type'      => MYAPI_NOTIFICATION_SOURCE_PAYMENT,
    'source_nid'       => (int) $node->nid,
    'type'             => MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED,
    'title'            => 'Pago anulado — Ref. ' . $reference,
    'body'             => $body,
    'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT,
    'deep_link_id'     => (int) $node->nid,
    'condominium_id'   => $condominium_id,
    'unit_id'          => $unit_id,
    'uids'             => myapi_payment_notify_recipients($node, $unit_id),
  ]);
}

/**
 * Resolves the recipient uid(s) of the "payment approved" notification.
 *
 * Normally the payment's own author (node->uid) is the recipient, since that
 * is the resident who registered it via the app. When the author has the
 * "administrator" Drupal role, the payment was created/completed on their
 * behalf from the Drupal back office, so node->uid is the admin, not the
 * actual payer: in that case the unit's occupant(s) are notified instead.
 * Falls back to node->uid if the unit has no resolvable occupant, so the
 * notification is never lost.
 *
 * @param object   $node     The 'pagos' node.
 * @param int|null $unit_id  The resolved 'vivienda' nid, or NULL.
 *
 * @return array  List of recipient uids.
 */
function myapi_payment_notify_recipients($node, $unit_id) {
  $author = user_load($node->uid);
  if ($author && !empty($author->roles) && in_array('administrator', $author->roles)) {
    if ($unit_id !== NULL) {
      module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
      $occupant_uids = myapi_unit_member_uids([$unit_id], 'ocupantes');
      if (!empty($occupant_uids)) {
        return $occupant_uids;
      }
    }
  }

  return [(int) $node->uid];
}
