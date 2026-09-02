<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/receipt.resource.inc';

/**
 * Unit tests for myapi_receipt_build_item() (SPEC 11, covered by SPEC 121).
 *
 * The pure mapper of GET /api/v1/units/%/receipts: one raw row in, one
 * 40-key response item out. It is the widest mapper of the module — 32 of
 * those 40 keys are decimals cast to float — and every one of them is a line
 * of the resident's bill.
 *
 * WHY IT GETS ITS OWN CLASS. The endpoint tests exercise it too, but through a
 * fixture row whose 'status' column is the node's published flag (the alias
 * collision documented in ReceiptEndpointTest), and through a query stub that
 * projects NULL for every column a fixture omits. Here the row is built by
 * hand, so a value can be a string '0.00', a negative balance, a numeric
 * string with spaces, or genuinely absent — the shapes MySQL actually answers
 * for a DECIMAL column, and the shapes the cast has to survive.
 *
 * The rule the whole class is about: a field with a stored row is a float, a
 * field with NO row is null, and nothing in between. The app renders a null as
 * a dash and a 0.0 as "$0.00", so the two are not interchangeable.
 */
class ReceiptBuildItemTest extends TestCase {

  /**
   * The 32 decimal keys, in the order the mapper writes them.
   *
   * Written out rather than read from the function so a reordering or a
   * dropped field FAILS here instead of being mirrored by the test.
   */
  const FLOAT_FIELDS = [
    'gas_previous_reading', 'gas_current_reading', 'gas_consumption',
    'water_previous_reading', 'water_current_reading', 'water_consumption',
    'hot_water_previous_reading', 'hot_water_current_reading', 'hot_water_consumption',
    'water_heating', 'gym', 'jacuzzi_sauna', 'extra', 'extra_fee', 'internet',
    'electricity', 'preheating', 'fee', 'storage_fee', 'parking_fee',
    'terrace_fee', 'office_fee', 'commercial_unit_fee', 'total_fee',
    'insurance', 'penalty_amount', 'monthly_total', 'previous_balance',
    'total', 'gas_fixed_rate', 'water_fixed_rate', 'hot_water_fixed_rate',
  ];

  /**
   * The 8 non-decimal keys, in the order the mapper writes them.
   */
  const TEXT_FIELDS = [
    'id', 'title', 'unit_id', 'period_start', 'period_end', 'status',
    'observation', 'late_payment_message',
  ];

  /**
   * A row with every column present, as myapi_receipt_fetch() answers it:
   * strings, because that is what the database driver returns.
   */
  private function row(array $overrides = []) {
    $row = [
      'nid'          => '501',
      'title'        => 'Recibo junio 2026',
      'unit_id'      => '45',
      'period_start' => '2026-06-01',
      'period_end'   => '2026-06-30',
      'status'       => 'Enviado',
      'observation'  => NULL,
      'late_payment_message' => NULL,
    ];

    foreach (self::FLOAT_FIELDS as $i => $field) {
      $row[$field] = (string) ($i + 1);
    }

    return (object) ($overrides + $row);
  }

  /**
   * A row where every decimal column is absent (NULL), which is what a LEFT
   * JOIN answers for a receipt with no row in that field's table.
   */
  private function emptyRow(array $overrides = []) {
    $row = [];
    foreach (self::FLOAT_FIELDS as $field) {
      $row[$field] = NULL;
    }

    return $this->row($overrides + $row);
  }

  /* -------------------------------------------------------------------------
   * The documented shape.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly 40 keys, in the documented order: the three identity keys, the
   * period, the status, the 32 decimals, and the two free-text fields last.
   */
  public function testReturnsExactlyTheFortyDocumentedKeysInOrder() {
    $item = myapi_receipt_build_item($this->row());

    $expected = array_merge(
      ['id', 'title', 'unit_id', 'period_start', 'period_end', 'status'],
      self::FLOAT_FIELDS,
      ['observation', 'late_payment_message']
    );

    $this->assertSame($expected, array_keys($item));
    $this->assertCount(40, $item);
  }

  /**
   * Every documented key is present even when the row carries nothing for it:
   * a receipt with no decimal rows still answers 40 keys, all of them null.
   */
  public function testAnEmptyReceiptStillAnswersTheFortyKeys() {
    $item = myapi_receipt_build_item($this->emptyRow());

    $this->assertCount(40, $item);
    foreach (self::FLOAT_FIELDS as $field) {
      $this->assertArrayHasKey($field, $item, $field);
      $this->assertNull($item[$field], $field);
    }
  }

  /**
   * No column of the raw row leaks into the item under its storage name: the
   * mapper renames, it does not merge. 'nid' in particular must not survive
   * next to 'id'.
   */
  public function testNoStorageColumnNameLeaksIntoTheItem() {
    $item = myapi_receipt_build_item($this->row());

    $this->assertArrayNotHasKey('nid', $item);
    $this->assertArrayNotHasKey('field_periodo_value', $item);
  }

  /**
   * The mapper does not mutate the row it was given: it reads and builds a new
   * array. Two calls over the same object answer the same thing.
   */
  public function testDoesNotMutateTheRow() {
    $row = $this->row();
    $before = clone $row;

    $first = myapi_receipt_build_item($row);
    $second = myapi_receipt_build_item($row);

    $this->assertEquals($before, $row);
    $this->assertSame($first, $second);
  }

  /* -------------------------------------------------------------------------
   * The two integer casts.
   * ---------------------------------------------------------------------- */

  /**
   * id and unit_id are ints in the JSON, cast from the strings the database
   * answers. The app indexes by them.
   */
  public function testIdAndUnitIdAreCastToInt() {
    $item = myapi_receipt_build_item($this->row(['nid' => '501', 'unit_id' => '45']));

    $this->assertSame(501, $item['id']);
    $this->assertSame(45, $item['unit_id']);
  }

  /**
   * A large nid survives the cast: these are 64-bit ids on a long-lived site.
   */
  public function testLargeIdsSurviveTheCast() {
    $item = myapi_receipt_build_item($this->row(['nid' => '2147483647', 'unit_id' => '2147483646']));

    $this->assertSame(2147483647, $item['id']);
    $this->assertSame(2147483646, $item['unit_id']);
  }

  /* -------------------------------------------------------------------------
   * The decimal cast: the rule of the class.
   * ---------------------------------------------------------------------- */

  /**
   * A stored decimal comes back as a float, never as the string the driver
   * answered. A string in the JSON would make the app's arithmetic silently
   * concatenate.
   */
  public function testEveryStoredDecimalIsAFloat() {
    $item = myapi_receipt_build_item($this->row());

    foreach (self::FLOAT_FIELDS as $field) {
      $this->assertIsFloat($item[$field], $field);
    }
  }

  /**
   * The value is preserved, not rounded: '187.32' is 187.32 and nothing else.
   */
  public function testTheDecimalValueIsPreservedExactly() {
    $item = myapi_receipt_build_item($this->row([
      'monthly_total' => '187.32',
      'total'         => '187.32',
      'fee'           => '120.00',
    ]));

    $this->assertSame(187.32, $item['monthly_total']);
    $this->assertSame(187.32, $item['total']);
    $this->assertSame(120.0, $item['fee']);
  }

  /**
   * A stored zero is a float 0.0 and NOT a null: the receipt HAS a gym line
   * worth nothing, which the app prints as "$0.00". The cast is guarded by a
   * `!== NULL` and not by an empty() check, and this is the case that tells
   * them apart.
   */
  public function testAStoredZeroIsAFloatAndNeverNull() {
    foreach (['0', '0.00', '0.000', '-0.00'] as $stored) {
      $item = myapi_receipt_build_item($this->row(['gym' => $stored]));

      $this->assertNotNull($item['gym'], $stored);
      $this->assertIsFloat($item['gym'], $stored);
      $this->assertSame(0.0, abs($item['gym']), $stored);
    }
  }

  /**
   * A negative balance keeps its sign: previous_balance is regularly negative
   * on a real bill (a credit in the resident's favour).
   */
  public function testANegativeBalanceKeepsItsSign() {
    $item = myapi_receipt_build_item($this->row(['previous_balance' => '-3393.00']));

    $this->assertSame(-3393.0, $item['previous_balance']);
  }

  /**
   * An absent decimal column is null and is not turned into 0.0. This is the
   * other half of the rule above, and the reason the two cases are asserted
   * side by side.
   */
  public function testAnAbsentDecimalIsNullAndNeverZero() {
    $item = myapi_receipt_build_item($this->row(['gym' => NULL, 'insurance' => NULL]));

    $this->assertNull($item['gym']);
    $this->assertNull($item['insurance']);
    // Only the two nulled columns are null: the rest of the row is untouched,
    // so this is a per-field decision and not a whole-item fallback.
    $this->assertNotNull($item['jacuzzi_sauna']);
    $this->assertIsFloat($item['jacuzzi_sauna']);
  }

  /**
   * Each field is read from its own column: a value set on one decimal does
   * not bleed into its neighbours. The fixture gives every field a distinct
   * value (1..32) precisely so a copy-paste in the mapper is visible.
   */
  public function testEachDecimalIsReadFromItsOwnColumn() {
    $item = myapi_receipt_build_item($this->row());

    foreach (self::FLOAT_FIELDS as $i => $field) {
      $this->assertSame((float) ($i + 1), $item[$field], $field);
    }
  }

  /**
   * A numeric value that is already a float or an int in the row survives the
   * cast unchanged — the mapper is not driver-specific.
   */
  public function testAlreadyNumericValuesSurviveTheCast() {
    $item = myapi_receipt_build_item($this->row(['total' => 42.5, 'fee' => 7]));

    $this->assertSame(42.5, $item['total']);
    $this->assertSame(7.0, $item['fee']);
  }

  /* -------------------------------------------------------------------------
   * The text fields: raw passthrough, no interpretation.
   * ---------------------------------------------------------------------- */

  /**
   * title, period_start, period_end and status travel exactly as stored: no
   * reformatting of the dates, no validation of the state against a catalogue.
   */
  public function testTextFieldsTravelExactlyAsStored() {
    $item = myapi_receipt_build_item($this->row([
      'title'        => 'Recibo junio 2026',
      'period_start' => '2026-06-01',
      'period_end'   => '2026-06-30',
      'status'       => 'Enviado',
    ]));

    $this->assertSame('Recibo junio 2026', $item['title']);
    $this->assertSame('2026-06-01', $item['period_start']);
    $this->assertSame('2026-06-30', $item['period_end']);
    $this->assertSame('Enviado', $item['status']);
  }

  /**
   * A stored datetime is NOT truncated to a date: the mapper hands over what
   * the column holds, and the SUBSTR() that compares only the first ten
   * characters lives in the query, not here.
   */
  public function testAStoredDatetimeIsNotReformatted() {
    $item = myapi_receipt_build_item($this->row(['period_start' => '2026-06-01 00:00:00']));

    $this->assertSame('2026-06-01 00:00:00', $item['period_start']);
  }

  /**
   * A missing period is null on both ends — a receipt with no periodo row is
   * legal storage, and the endpoint answers it rather than hiding it.
   */
  public function testAMissingPeriodIsNullOnBothEnds() {
    $item = myapi_receipt_build_item($this->row(['period_start' => NULL, 'period_end' => NULL]));

    $this->assertNull($item['period_start']);
    $this->assertNull($item['period_end']);
  }

  /**
   * observation and late_payment_message are the two free-text fields and are
   * null when absent — the documented default, and what the app checks before
   * rendering the notice block.
   */
  public function testTheTwoFreeTextFieldsAreNullWhenAbsent() {
    $item = myapi_receipt_build_item($this->row(['observation' => NULL, 'late_payment_message' => NULL]));

    $this->assertNull($item['observation']);
    $this->assertNull($item['late_payment_message']);
  }

  /**
   * When present they travel whole, newlines and accents included: this is the
   * message the administrator wrote for this resident.
   */
  public function testTheFreeTextFieldsTravelWhole() {
    $observation = "Pago parcial recibido.\nSaldo pendiente: $12,00";
    $item = myapi_receipt_build_item($this->row([
      'observation'          => $observation,
      'late_payment_message' => 'Recargo por mora aplicado en julio',
    ]));

    $this->assertSame($observation, $item['observation']);
    $this->assertSame('Recargo por mora aplicado en julio', $item['late_payment_message']);
  }

  /**
   * NO ESCAPING HAPPENS HERE, and that is a deliberate, documented property of
   * this mapper rather than an oversight: the receipt fields are numbers and
   * administrator-written text, the envelope is encoded with the JSON_HEX_*
   * flags of drupal_json_encode() (which is what neutralises markup for an
   * HTML embed), and the app renders the value as text. The case is pinned so
   * that adding a check_plain() here becomes a decision someone takes on
   * purpose — it would change the bytes the app already renders.
   */
  public function testTextIsNotEscapedByTheMapper() {
    $item = myapi_receipt_build_item($this->row(['title' => 'Recibo <b>junio</b>']));

    $this->assertSame('Recibo <b>junio</b>', $item['title']);
  }

  /**
   * An empty string is not a null: an observation stored as '' comes back as
   * '', which is what the field holds.
   */
  public function testAnEmptyStringIsNotTurnedIntoNull() {
    $item = myapi_receipt_build_item($this->row(['observation' => '', 'late_payment_message' => '']));

    $this->assertSame('', $item['observation']);
    $this->assertSame('', $item['late_payment_message']);
  }
}
