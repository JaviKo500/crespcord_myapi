<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.service_offer.inc';

/**
 * Unit tests for the offer's domain (SPEC 100).
 *
 * includes/myapi.service_offer.inc is where everything an offer knows about
 * itself lives, and all of it is pure except two named queries, so this class
 * exercises the whole decision surface with no site booted.
 *
 * THE SERIALISER IS THE HALF THAT IS A CONTRACT. myapi_service_offer_build()
 * is myapi_service_request_build_offer() moved and widened from six keys to
 * fifteen, and the six originals stay first and in their exact order. That is
 * asserted here rather than left to prose, because it is what lets SPEC 100
 * promise that no client of GET /api/v1/service-requests/% or of
 * GET /api/v1/service-requests/provider/% notices the move.
 *
 * The sibling classes ServiceRequestDetailEndpointTest and
 * ServiceRequestProviderDetailTest run this same serialiser through the two
 * real endpoints; this one calls it directly, so a failure here says "the
 * mapping is wrong" and not "the endpoint is wrong".
 */
class ServiceOfferCreateTest extends TestCase {

  /**
   * The six keys of SPEC 89, in the order SPEC 89 answered them. Written out as
   * a literal on purpose: reading them off the function under test would make
   * the assertion agree with itself.
   */
  private const SPEC_89_KEYS = [
    'id', 'provider', 'amount', 'message', 'status', 'created',
  ];

  /**
   * The nine SPEC 100 adds, in the order of the spec's table.
   */
  private const SPEC_100_KEYS = [
    'amount_type', 'valid_until', 'available_from', 'duration',
    'includes', 'excludes', 'tax_included', 'warranty_days', 'requires_visit',
  ];

  /**
   * One row of myapi_service_request_load_offers(), the way the query answers
   * it: every column a string or NULL, because that is what PDO hands back.
   *
   * The defaults are an offer stored BEFORE myapi_update_7035(): the eight
   * columns of SPEC 77 filled, the ten of SPEC 100 absent altogether. That is
   * the row the vast majority of this test's cases start from, and it is the
   * one a real site has most of.
   */
  private function row(array $values = []) {
    return (object) ($values + [
      'nid'               => '901',
      'provider_id'       => '41',
      'provider_name'     => 'Plomería Torres',
      'provider_logo_uri' => NULL,
      'amount'            => '150.50',
      'message'           => 'Puedo pasar el jueves por la mañana.',
      'status'            => 'sent',
      'created'           => '1787000000',
    ]);
  }

  /* -------------------------------------------------------------------------
   * The fifteen keys, and the six that may never move.
   * ---------------------------------------------------------------------- */

  /**
   * THE ACCEPTANCE CRITERION THIS FILE EXISTS TO GUARD: the first six keys are
   * byte for byte the ones SPEC 89 answered — same names, same order. Every
   * client of the two detail endpoints reads them positionally or by name and
   * neither reading may break.
   */
  public function testTheFirstSixKeysAreSpec89sUnchangedAndInOrder() {
    $offer = myapi_service_offer_build($this->row());

    $this->assertSame(
      self::SPEC_89_KEYS,
      array_slice(array_keys($offer), 0, 6),
      'the six keys of SPEC 89 must stay first and in their original order'
    );
  }

  /**
   * Fifteen keys, always, in the order of the spec's table. Nothing appears and
   * nothing disappears with the data: a null is an answer, an absent key is a
   * question, and a client that has to test for a key before reading it is a
   * client that will forget to.
   */
  public function testTheFifteenKeysAreAlwaysThereAndInOrder() {
    $expected = array_merge(self::SPEC_89_KEYS, self::SPEC_100_KEYS);

    // A row with nothing but the identifiers, and a row with everything.
    $bare = myapi_service_offer_build((object) ['nid' => '901']);
    $full = myapi_service_offer_build($this->row([
      'amount_type'    => 'fixed',
      'valid_until'    => '1788000000',
      'available_from' => '1787500000',
      'duration'       => '3',
      'duration_unit'  => 'hours',
      'includes'       => 'Mano de obra.',
      'excludes'       => 'El calentador.',
      'tax_included'   => '1',
      'warranty_days'  => '90',
      'requires_visit' => '0',
    ]));

    $this->assertSame($expected, array_keys($bare));
    $this->assertSame($expected, array_keys($full));
    $this->assertCount(15, $bare);
  }

  /* -------------------------------------------------------------------------
   * An offer stored before this spec: nine nulls and one false.
   * ---------------------------------------------------------------------- */

  /**
   * DECISION 6 OF THE SPEC, PINNED. myapi_update_7035() backfills nothing, so
   * every offer stored before it answers null on the eight nullable new keys.
   * Deducing an amount_type from the amount ("it has a number, so it is
   * fixed") would put in a provider's mouth a statement they never made.
   */
  public function testAnOfferOlderThanThisSpecAnswersNullOnTheNewKeys() {
    $offer = myapi_service_offer_build($this->row());

    foreach (['amount_type', 'valid_until', 'available_from', 'duration',
      'includes', 'excludes', 'tax_included', 'warranty_days'] as $key) {
      $this->assertNull($offer[$key], $key . ' must be null on a pre-SPEC-100 offer');
    }

    // And it keeps answering everything SPEC 89 answered.
    $this->assertSame(901, $offer['id']);
    $this->assertSame(150.5, $offer['amount']);
    $this->assertSame('sent', $offer['status']);
  }

  /**
   * requires_visit IS NEVER null, on any row, ever. The absence of the claim
   * "I need to visit first" reads as false, and there is nothing a null would
   * tell a client that false does not.
   */
  public function testRequiresVisitIsNeverNull() {
    foreach ([$this->row(), (object) ['nid' => '901'], $this->row(['requires_visit' => NULL])] as $row) {
      $offer = myapi_service_offer_build($row);
      $this->assertFalse($offer['requires_visit']);
      $this->assertIsBool($offer['requires_visit']);
    }
  }

  /* -------------------------------------------------------------------------
   * The nine new keys, one rule at a time.
   * ---------------------------------------------------------------------- */

  /**
   * `duration` IS ONE OBJECT OR ONE NULL, never two flat keys and never
   * {value: null, unit: null}. The two columns are coupled — one without the
   * other means nothing — the same way `provider` travels whole or not at all.
   *
   * @dataProvider durationRows
   */
  public function testDurationTravelsWholeOrNotAtAll($value, $unit, $expected) {
    $offer = myapi_service_offer_build($this->row([
      'duration'      => $value,
      'duration_unit' => $unit,
    ]));

    $this->assertSame($expected, $offer['duration']);
  }

  public function durationRows() {
    return [
      'both present'   => ['3', 'hours', ['value' => 3, 'unit' => 'hours']],
      'both, in days'  => ['2', 'days', ['value' => 2, 'unit' => 'days']],
      'value only'     => ['3', NULL, NULL],
      'unit only'      => [NULL, 'hours', NULL],
      'value empty'    => ['', 'hours', NULL],
      'unit empty'     => ['3', '', NULL],
      'neither'        => [NULL, NULL, NULL],
    ];
  }

  /**
   * `tax_included` is the one three-valued key of the fifteen: true, false, or
   * "the provider never said". A null is NOT a no — an offer whose price says
   * nothing about tax is a different answer from one that says tax is excluded.
   *
   * @dataProvider taxRows
   */
  public function testTaxIncludedKeepsItsThirdValue($stored, $expected) {
    $offer = myapi_service_offer_build($this->row(['tax_included' => $stored]));

    $this->assertSame($expected, $offer['tax_included']);
  }

  public function taxRows() {
    return [
      'stored true'  => ['1', TRUE],
      // '0' is a DECLARATION, not an absence: the provider said "tax not
      // included", and reading it as null would erase what they said.
      'stored false' => ['0', FALSE],
      'never stored' => [NULL, NULL],
      'empty column' => ['', NULL],
    ];
  }

  /**
   * An optional text is null when empty and never "". `message` is the
   * exception and stays "" — it is REQUIRED, so an empty one is a corrupt row
   * and not an absence, and the two must not read alike.
   */
  public function testAnEmptyOptionalTextIsNullAndAnEmptyMessageIsNot() {
    $offer = myapi_service_offer_build($this->row([
      'includes' => '',
      'excludes' => NULL,
      'message'  => '',
    ]));

    $this->assertNull($offer['includes']);
    $this->assertNull($offer['excludes']);
    $this->assertSame('', $offer['message']);
  }

  /**
   * Texts travel AS STORED, with the line breaks the provider typed — the same
   * rule `message` and the request's `description` already follow.
   */
  public function testTheTwoTextsTravelAsStored() {
    $offer = myapi_service_offer_build($this->row([
      'includes' => "Mano de obra.\nDesplazamiento.",
      'excludes' => 'El calentador, si hiciera falta.',
    ]));

    $this->assertSame("Mano de obra.\nDesplazamiento.", $offer['includes']);
    $this->assertSame('El calentador, si hiciera falta.', $offer['excludes']);
  }

  /**
   * The two dates are datestamps, so they are formatted like `created`,
   * `desired_start` and `closed_at` and never served as the raw column.
   */
  public function testTheTwoDatesAreFormattedAndNotRaw() {
    $offer = myapi_service_offer_build($this->row([
      'valid_until'    => '1788000000',
      'available_from' => '1787500000',
    ]));

    $this->assertSame(
      format_date(1788000000, 'custom', 'Y-m-d\TH:i:s'),
      $offer['valid_until']
    );
    $this->assertSame(
      format_date(1787500000, 'custom', 'Y-m-d\TH:i:s'),
      $offer['available_from']
    );
  }

  /**
   * Numbers come out of the query as strings and must not travel as strings:
   * warranty_days is an int and duration.value is an int, the same rule
   * `amount` follows with a float. 0 is a real answer for the warranty — "no
   * warranty", declared — and must not read as an absence.
   */
  public function testTheNumbersAreTypedAndZeroIsAnAnswer() {
    $offer = myapi_service_offer_build($this->row([
      'warranty_days' => '90',
      'duration'      => '3',
      'duration_unit' => 'hours',
    ]));

    $this->assertSame(90, $offer['warranty_days']);
    $this->assertSame(3, $offer['duration']['value']);

    $zero = myapi_service_offer_build($this->row(['warranty_days' => '0']));
    $this->assertSame(0, $zero['warranty_days']);
    $this->assertNotNull($zero['warranty_days']);
  }

  /* -------------------------------------------------------------------------
   * The six original keys behave exactly as they did.
   * ---------------------------------------------------------------------- */

  /**
   * `provider` IS THE WHOLE OBJECT OR NULL, never {id: null, name: null}, and
   * the offer is serialised either way — dropping it would make `offers`
   * disagree with `offers_count`, which counts it.
   */
  public function testProviderTravelsWholeOrNull() {
    $with = myapi_service_offer_build($this->row());
    $this->assertSame(['id', 'name', 'logo'], array_keys($with['provider']));
    $this->assertSame(41, $with['provider']['id']);

    $without = myapi_service_offer_build($this->row([
      'provider_id'   => NULL,
      'provider_name' => NULL,
    ]));
    $this->assertNull($without['provider']);
    $this->assertSame(901, $without['id'], 'the offer is still serialised');
  }

  /**
   * `amount` IS A FLOAT OR NULL, NEVER "95.50" AND NEVER 0.0 for a missing one:
   * the field is optional by SPEC 77's decision and 0 is a price somebody
   * offered, not a missing one.
   */
  public function testAmountIsAFloatOrNullAndZeroIsAPrice() {
    $this->assertSame(150.5, myapi_service_offer_build($this->row())['amount']);
    $this->assertNull(myapi_service_offer_build($this->row(['amount' => NULL]))['amount']);
    $this->assertNull(myapi_service_offer_build($this->row(['amount' => '']))['amount']);

    $zero = myapi_service_offer_build($this->row(['amount' => '0.00']))['amount'];
    $this->assertSame(0.0, $zero);
    $this->assertNotNull($zero);
  }
}
