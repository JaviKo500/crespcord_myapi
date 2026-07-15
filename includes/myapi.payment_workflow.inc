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
