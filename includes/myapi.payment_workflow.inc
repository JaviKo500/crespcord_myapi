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
