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
