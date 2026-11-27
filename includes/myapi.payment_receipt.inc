<?php

/**
 * @file
 * Shared model helpers for payment receipts (SPEC 138).
 *
 * A payment receipt is an INTERNAL document, one per verified payment. It has
 * nothing to do with the SRI (no access key, no XML, no 001-001-... format) and
 * nothing to do with the monthly 'recibo' that GET /api/v1/units/%/receipts
 * exposes: that one is the statement of account, this one is the proof of a
 * single payment.
 *
 * This file holds what both the emission code and the unit tests need: the
 * status catalogue of the two tables and the security code generator. The
 * number reservation lives here too, next to the table it locks, because the
 * two halves of "one receipt, one number" must not drift apart.
 */

/**
 * Billing data state: usable by the emission flow.
 */
define('MYAPI_RECEIPT_BILLING_DATA_STATUS_ACTIVE', 'active');

/**
 * Billing data state: kept for the receipts that already point at it, never
 * offered again for new emissions.
 */
define('MYAPI_RECEIPT_BILLING_DATA_STATUS_INACTIVE', 'inactive');

/**
 * Receipt state: the row exists but the PDF has not been produced yet.
 */
define('MYAPI_PAYMENT_RECEIPT_STATUS_PENDING', 'pending');

/**
 * Receipt state: the PDF was produced and stored.
 */
define('MYAPI_PAYMENT_RECEIPT_STATUS_GENERATED', 'generated');

/**
 * Receipt state: the PDF could not be produced. The number is kept so a retry
 * reuses it.
 */
define('MYAPI_PAYMENT_RECEIPT_STATUS_FAILED', 'failed');

/**
 * Receipt state: the receipt was voided on purpose. Its security code no
 * longer verifies.
 */
define('MYAPI_PAYMENT_RECEIPT_STATUS_VOIDED', 'voided');

/**
 * Receipt state: a newer version of the same payment replaced this one. All
 * versions share the number; this one keeps its own security code so a scanned
 * old PDF can be recognised as superseded.
 */
define('MYAPI_PAYMENT_RECEIPT_STATUS_SUPERSEDED', 'superseded');

/**
 * Length of the security code, in characters.
 *
 * 20 Base32 symbols are 100 bits: enough that a collision is not a practical
 * concern, and short enough that the barcode stays readable.
 */
define('MYAPI_PAYMENT_RECEIPT_SECURITY_CODE_LENGTH', 20);

/**
 * The 32 symbols of the security code, without 0, O, 1 and I.
 *
 * Removing the four look-alikes is what makes the printed code readable by a
 * person and by an OCR; the cost is none, because 32 is still a power of two.
 */
define('MYAPI_PAYMENT_RECEIPT_SECURITY_CODE_ALPHABET', '23456789ABCDEFGHJKLMNPQRSTUVWXYZ');

/**
 * Generates the security code of a receipt version.
 *
 * One byte of drupal_random_bytes() per character, masked with & 31. The mask
 * is uniform because 256 is an exact multiple of 32: every symbol has the same
 * probability, which is what makes the 100 bits real entropy and not a
 * plausible-looking string.
 *
 * The code is stored WITHOUT hyphens. Grouping it for printing is the
 * template's job, and doing it here would store a second, different
 * representation of the same value.
 *
 * A collision with the UNIQUE key on security_code is not handled here: the
 * emission code catches the duplicate and retries, because only it knows how
 * many attempts are acceptable and what to do when they run out.
 *
 * @return string
 *   A 20-character code from MYAPI_PAYMENT_RECEIPT_SECURITY_CODE_ALPHABET.
 */
function myapi_payment_receipt_security_code() {
  $bytes = drupal_random_bytes(MYAPI_PAYMENT_RECEIPT_SECURITY_CODE_LENGTH);
  $alphabet = MYAPI_PAYMENT_RECEIPT_SECURITY_CODE_ALPHABET;
  $code = '';

  for ($i = 0; $i < MYAPI_PAYMENT_RECEIPT_SECURITY_CODE_LENGTH; $i++) {
    $code .= $alphabet[ord($bytes[$i]) & 31];
  }

  return $code;
}

/**
 * Reserves the next receipt number of a condominium.
 *
 * MUST BE CALLED INSIDE AN OPEN TRANSACTION. The row lock this function takes
 * is released at the end of the transaction, not at the end of the function:
 * outside a transaction, the SELECT ... FOR UPDATE would lock nothing by the
 * time the receipt is inserted, and two emissions could take the same number.
 * The LogicException is the guard that turns that silent race into a bug the
 * caller sees immediately.
 *
 * The three statements, in order:
 *
 *  1. INSERT ... ON DUPLICATE KEY UPDATE creates the condominium's counter row
 *     with last_number = field_receipt_start_number - 1, or 0 when the field is
 *     empty. The no-op UPDATE branch is what makes the statement idempotent
 *     without ever resetting a counter that already exists.
 *  2. SELECT ... FOR UPDATE locks that row until the transaction commits or
 *     rolls back.
 *  3. UPDATE last_number = last_number + 1 and the new value is returned.
 *
 * A rollback moves the counter back with the rest of the transaction, so the
 * number is not lost. A failed PDF keeps the row in 'failed' and its number,
 * and a retry reuses it.
 *
 * The number is read from the condominium's field ONLY while creating the
 * counter row: changing field_receipt_start_number afterwards has no effect,
 * which is what the field description warns about.
 *
 * MySQL/MariaDB dependency, stated on purpose: INSERT ... ON DUPLICATE KEY
 * UPDATE and SELECT ... FOR UPDATE are not portable SQL. Production and local
 * both run MariaDB.
 *
 * @param int $condominium_nid
 *   The condominium node id.
 *
 * @return int
 *   The reserved number.
 *
 * @throws LogicException
 *   When there is no open database transaction.
 */
function myapi_payment_receipt_next_number($condominium_nid) {
  if (!Database::getConnection()->inTransaction()) {
    throw new LogicException('myapi_payment_receipt_next_number() must run inside an open transaction.');
  }

  $condominium_nid = (int) $condominium_nid;

  // The start number is read only here, when the counter row is created. An
  // empty or invalid field means "start at 1", which is last_number = 0.
  $node = node_load($condominium_nid);
  $start = 0;
  if ($node && isset($node->field_receipt_start_number[LANGUAGE_NONE][0]['value'])) {
    $start = (int) $node->field_receipt_start_number[LANGUAGE_NONE][0]['value'];
  }
  if ($start < 1) {
    $start = 0;
  }

  db_query(
    'INSERT INTO {myapi_receipt_sequences} (condominium_nid, last_number, changed) VALUES (:condominium_nid, :last_number, :changed) ON DUPLICATE KEY UPDATE condominium_nid = condominium_nid',
    [
      ':condominium_nid' => $condominium_nid,
      ':last_number'     => $start > 0 ? $start - 1 : 0,
      ':changed'         => REQUEST_TIME,
    ]
  );

  $last = db_query(
    'SELECT last_number FROM {myapi_receipt_sequences} WHERE condominium_nid = :condominium_nid FOR UPDATE',
    [':condominium_nid' => $condominium_nid]
  )->fetchField();

  db_query(
    'UPDATE {myapi_receipt_sequences} SET last_number = last_number + 1, changed = :changed WHERE condominium_nid = :condominium_nid',
    [
      ':changed'         => REQUEST_TIME,
      ':condominium_nid' => $condominium_nid,
    ]
  );

  return (int) $last + 1;
}
