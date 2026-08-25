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
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../resources/service_offer.resource.inc';

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
  /* -------------------------------------------------------------------------
   * The gate: six conditions, in order, first failure answers (SPEC 100).
   * ---------------------------------------------------------------------- */

  private const NOW = 1787000000;
  private const UID = 12;
  private const CATEGORY = 5;

  /**
   * A row of myapi_service_offer_provider_row(): mine, published, licence in
   * date, serving the request's category. Every gate case below starts from
   * this one and breaks exactly one thing.
   */
  private function providerRow(array $values = []) {
    return (object) ($values + [
      'nid'            => '41',
      'title'          => 'Plomería Torres',
      'status'         => '1',
      'license_expiry' => (string) (self::NOW + 86400),
      'owned'          => TRUE,
      'category_ids'   => [self::CATEGORY],
    ]);
  }

  /**
   * A row of myapi_service_request_detail_row(): open, unawarded, of the
   * provider's category, and NOT this account's own request.
   */
  private function requestRow(array $values = []) {
    return (object) ($values + [
      'nid'                    => '128',
      'status'                 => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'requester_uid'          => '77',
      'category_id'            => (string) self::CATEGORY,
      'assigned_offer_raw'     => NULL,
      'assigned_provider_raw'  => NULL,
    ]);
  }

  private function gate($request_row, $provider_row) {
    return myapi_service_offer_eligibility($request_row, $provider_row, self::UID, self::NOW);
  }

  /**
   * The happy path: everything in place answers NULL, which is the only value
   * that means "let them through".
   */
  public function testTheGateLetsAnEligibleProviderThrough() {
    $this->assertNull($this->gate($this->requestRow(), $this->providerRow()));
  }

  /**
   * The whole matrix, one broken thing at a time.
   *
   * @dataProvider gateCases
   */
  public function testTheGateAnswersTheRightCode($request_values, $provider_values, $expected) {
    $request_row = $request_values === FALSE ? FALSE : $this->requestRow($request_values);
    $provider_row = $provider_values === FALSE ? FALSE : $this->providerRow($provider_values);

    $this->assertSame($expected, $this->gate($request_row, $provider_row));
  }

  public function gateCases() {
    return [
      /* 1 — not mine. */
      'no such provider node'      => [[], FALSE, 'service_offer_provider_not_owned'],
      'somebody else\'s provider'  => [[], ['owned' => FALSE], 'service_offer_provider_not_owned'],

      /* 2 — mine, but suspended. */
      'unpublished provider'       => [[], ['status' => '0'], 'service_offer_provider_not_active'],
      'licence expired yesterday'  => [[], ['license_expiry' => (string) (self::NOW - 1)], 'service_offer_provider_not_active'],
      'licence empty'              => [[], ['license_expiry' => ''], 'service_offer_provider_not_active'],
      'licence missing'            => [[], ['license_expiry' => NULL], 'service_offer_provider_not_active'],
      // The boundary: the licence dies the second AFTER it expires, so the
      // exact second is still active. myapi_services_provider_is_active() owns
      // this and it is asserted here only to prove the gate asks it.
      'licence expiring now'       => [[], ['license_expiry' => (string) self::NOW], NULL],

      /* 3 — no such request. Asked AFTER the provider. */
      'no such request'            => [FALSE, [], 'service_request_not_found'],

      /* 4 — my own request. */
      'my own request'             => [['requester_uid' => (string) self::UID], [], 'service_offer_own_request'],

      /* 5 — the request is not taking offers. */
      'direct'                     => [['status' => MYAPI_SERVICES_REQUEST_STATUS_DIRECT], [], 'service_request_not_offerable'],
      'assigned'                   => [['status' => MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED], [], 'service_request_not_offerable'],
      'closed'                     => [['status' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED], [], 'service_request_not_offerable'],
      'cancelled'                  => [['status' => MYAPI_SERVICES_REQUEST_STATUS_CANCELLED], [], 'service_request_not_offerable'],
      // Never a 500: a status nobody can name reads as "not offerable".
      'status empty'               => [['status' => ''], [], 'service_request_not_offerable'],
      'status corrupt'             => [['status' => 'pendiente'], [], 'service_request_not_offerable'],
      'status missing'             => [['status' => NULL], [], 'service_request_not_offerable'],
      // 'offered' IS biddable: the second and third providers still bid.
      'offered'                    => [['status' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED], [], NULL],

      /* 5 — awarded, read RAW. */
      'awarded an offer'           => [['assigned_offer_raw' => '900'], [], 'service_request_not_offerable'],
      'awarded a provider'         => [['assigned_provider_raw' => '41'], [], 'service_request_not_offerable'],
      'awarded, offered'           => [['status' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED, 'assigned_offer_raw' => '900'], [], 'service_request_not_offerable'],

      /* 6 — not my category. */
      'another category'           => [['category_id' => '99'], [], 'service_offer_category_mismatch'],
      'provider serves none'       => [[], ['category_ids' => []], 'service_offer_category_mismatch'],
      'request has no category'    => [['category_id' => NULL], [], 'service_offer_category_mismatch'],
      // Two categories, one of them the request's: through.
      'provider serves two'        => [[], ['category_ids' => [99, self::CATEGORY]], NULL],
    ];
  }

  /**
   * THE ORDER IS THE CONTRACT, not just the list. Every case below breaks TWO
   * conditions at once and must answer the EARLIER one — which is what makes
   * the error a client reads actionable instead of arbitrary.
   *
   * @dataProvider gatePrecedence
   */
  public function testTheFirstFailingConditionAnswers($request_values, $provider_values, $expected) {
    $request_row = $request_values === FALSE ? FALSE : $this->requestRow($request_values);
    $provider_row = $provider_values === FALSE ? FALSE : $this->providerRow($provider_values);

    $this->assertSame($expected, $this->gate($request_row, $provider_row));
  }

  public function gatePrecedence() {
    return [
      // Not mine beats suspended.
      'not owned + unpublished' => [[], ['owned' => FALSE, 'status' => '0'], 'service_offer_provider_not_owned'],
      // AND it beats "no such request": an account with no standing to bid
      // learns nothing about which request nids exist.
      'not owned + no request'  => [FALSE, ['owned' => FALSE], 'service_offer_provider_not_owned'],
      'suspended + no request'  => [FALSE, ['status' => '0'], 'service_offer_provider_not_active'],
      // No such request beats everything about the request itself.
      'no request + own + shut' => [FALSE, [], 'service_request_not_found'],
      // Mine beats closed: you are told it is yours, not that it is shut.
      'own + cancelled'         => [['requester_uid' => (string) self::UID, 'status' => MYAPI_SERVICES_REQUEST_STATUS_CANCELLED], [], 'service_offer_own_request'],
      // Closed beats wrong category.
      'cancelled + category'    => [['status' => MYAPI_SERVICES_REQUEST_STATUS_CANCELLED, 'category_id' => '99'], [], 'service_request_not_offerable'],
      // Awarded is condition 5 and the category is 6, so the award answers.
      'awarded + category'      => [['assigned_provider_raw' => '41', 'category_id' => '99'], [], 'service_request_not_offerable'],
    ];
  }

  /**
   * The gate is PURE: called twice with the same rows it answers the same
   * thing, and it writes nothing back into them. Cheap to assert, and it is
   * what lets the whole matrix above run with no site booted.
   */
  public function testTheGateIsPureAndMutatesNothing() {
    $request_row = $this->requestRow();
    $provider_row = $this->providerRow();

    $before = [clone $request_row, clone $provider_row];

    $this->assertNull($this->gate($request_row, $provider_row));
    $this->assertNull($this->gate($request_row, $provider_row));

    $this->assertEquals($before[0], $request_row);
    $this->assertEquals($before[1], $provider_row);
  }
  /* -------------------------------------------------------------------------
   * The body: eleven rules, in order, first failure answers (SPEC 100).
   * ---------------------------------------------------------------------- */

  /**
   * The minimum body the endpoint accepts: three fields and nothing else.
   * Risk 2 of the spec rests on this — a provider on site, on a phone, bidding
   * for a 150-dollar job, must be able to finish the form.
   */
  private function minimalBody(array $values = []) {
    return $values + [
      'message'     => 'Puedo pasar el jueves por la mañana.',
      'amount_type' => 'fixed',
      'amount'      => 150.5,
    ];
  }

  private function validate($body) {
    return myapi_service_offer_validate_body($body);
  }

  private function assertRejected($body, $error_code, $field = NULL) {
    $result = $this->validate($body);

    $this->assertFalse($result['ok']);
    $this->assertSame($error_code, $result['error_code']);
    if ($field !== NULL) {
      $this->assertSame(['@field' => $field], $result['replacements']);
    }
  }

  private function assertAccepted($body) {
    $result = $this->validate($body);

    $this->assertTrue(
      $result['ok'],
      'expected acceptance, got ' . (isset($result['error_code']) ? $result['error_code'] : '?')
    );

    return $result['values'];
  }

  /**
   * The minimum body, and the twelve values it produces. Everything the
   * provider did not declare comes back NULL — except requires_visit, which is
   * FALSE and never NULL.
   */
  public function testTheMinimalBodyIsAccepted() {
    $values = $this->assertAccepted($this->minimalBody());

    $this->assertSame('Puedo pasar el jueves por la mañana.', $values['message']);
    $this->assertSame('fixed', $values['amount_type']);
    $this->assertSame(150.5, $values['amount']);

    foreach (['tax_included', 'valid_until', 'available_from', 'duration',
      'duration_unit', 'includes', 'excludes', 'warranty_days'] as $key) {
      $this->assertNull($values[$key], $key . ' must default to null');
    }
    $this->assertFalse($values['requires_visit']);
  }

  /**
   * A missing or unparseable body IS a missing `message`, not a code of its
   * own: the first thing the client failed to send is the message, and a
   * separate code would give the app two things to handle where there is one.
   *
   * @dataProvider bodilessCases
   */
  public function testAMissingBodyIsAMissingMessage($body) {
    $this->assertRejected($body, 'missing_field', 'message');
  }

  public function bodilessCases() {
    return [
      'empty array' => [[]],
      // json_decode() of an unparseable body answers NULL.
      'null'        => [NULL],
      'a string'    => ['not json'],
      'a number'    => [7],
      'false'       => [FALSE],
    ];
  }

  /**
   * The eleven rules, one broken field at a time, in the order of the spec's
   * table.
   *
   * @dataProvider bodyCases
   */
  public function testTheBodyIsValidatedRuleByRule(array $overrides, $error_code, $field) {
    // A NULL override means "remove the key", which is how a missing required
    // field is expressed.
    $body = $this->minimalBody();
    foreach ($overrides as $key => $value) {
      if ($value === '__unset__') {
        unset($body[$key]);
      }
      else {
        $body[$key] = $value;
      }
    }

    $this->assertRejected($body, $error_code, $field);
  }

  public function bodyCases() {
    return [
      /* 1 — message. */
      'message missing'      => [['message' => '__unset__'], 'missing_field', 'message'],
      'message empty'        => [['message' => ''], 'invalid_field', 'message'],
      'message only spaces'  => [['message' => "   \n  "], 'invalid_field', 'message'],
      'message not a string' => [['message' => 42], 'invalid_field', 'message'],
      'message is an array'  => [['message' => ['a']], 'invalid_field', 'message'],
      'message 2001 chars'   => [['message' => str_repeat('a', 2001)], 'invalid_field', 'message'],

      /* 2 — amount_type. */
      'type missing'         => [['amount_type' => '__unset__'], 'missing_field', 'amount_type'],
      'type off catalogue'   => [['amount_type' => 'negotiable'], 'invalid_field', 'amount_type'],
      'type empty'           => [['amount_type' => ''], 'invalid_field', 'amount_type'],
      'type not a string'    => [['amount_type' => 3], 'invalid_field', 'amount_type'],

      /* 3 — amount, conditional both ways. */
      'fixed with no amount' => [['amount' => '__unset__'], 'service_offer_amount_required', NULL],
      'estimate, no amount'  => [['amount_type' => 'estimate', 'amount' => '__unset__'], 'service_offer_amount_required', NULL],
      'hourly, no amount'    => [['amount_type' => 'hourly', 'amount' => '__unset__'], 'service_offer_amount_required', NULL],
      'on site WITH amount'  => [['amount_type' => 'on_site_quote'], 'service_offer_amount_not_allowed', NULL],
      'amount negative'      => [['amount' => -1], 'invalid_field', 'amount'],
      'amount over the cap'  => [['amount' => 100000000], 'invalid_field', 'amount'],
      'amount not a number'  => [['amount' => 'mucho'], 'invalid_field', 'amount'],
      'amount is true'       => [['amount' => TRUE], 'invalid_field', 'amount'],

      /* 4 — tax_included. */
      'tax without amount'   => [['amount_type' => 'on_site_quote', 'amount' => '__unset__', 'tax_included' => TRUE], 'service_offer_tax_without_amount', NULL],
      'tax as "true"'        => [['tax_included' => 'true'], 'invalid_field', 'tax_included'],
      'tax as "1"'           => [['tax_included' => '1'], 'invalid_field', 'tax_included'],
      'tax as 1'             => [['tax_included' => 1], 'invalid_field', 'tax_included'],
      'tax as "false"'       => [['tax_included' => 'false'], 'invalid_field', 'tax_included'],

      /* 5 and 6 — the two dates. */
      'valid_until garbage'  => [['valid_until' => 'el jueves'], 'invalid_field', 'valid_until'],
      'valid_until past'     => [['valid_until' => '2001-01-01 10:00'], 'invalid_field', 'valid_until'],
      'valid_until not text' => [['valid_until' => 12345], 'invalid_field', 'valid_until'],
      'available garbage'    => [['available_from' => 'mañana temprano???'], 'invalid_field', 'available_from'],
      'available past'       => [['available_from' => '2001-01-01 10:00'], 'invalid_field', 'available_from'],

      /* 8 — duration and its unit. */
      'duration, no unit'    => [['duration' => 3], 'service_offer_duration_incomplete', NULL],
      'unit, no duration'    => [['duration_unit' => 'hours'], 'service_offer_duration_incomplete', NULL],
      'duration zero'        => [['duration' => 0, 'duration_unit' => 'hours'], 'invalid_field', 'duration'],
      'duration negative'    => [['duration' => -3, 'duration_unit' => 'hours'], 'invalid_field', 'duration'],
      'duration over 9999'   => [['duration' => 10000, 'duration_unit' => 'hours'], 'invalid_field', 'duration'],
      'duration fractional'  => [['duration' => 2.5, 'duration_unit' => 'hours'], 'invalid_field', 'duration'],
      'unit off catalogue'   => [['duration' => 3, 'duration_unit' => 'weeks'], 'invalid_field', 'duration_unit'],

      /* 9 — includes and excludes. */
      'includes 2001 chars'  => [['includes' => str_repeat('a', 2001)], 'invalid_field', 'includes'],
      'excludes 2001 chars'  => [['excludes' => str_repeat('a', 2001)], 'invalid_field', 'excludes'],
      'includes not a text'  => [['includes' => 5], 'invalid_field', 'includes'],

      /* 10 — warranty_days. */
      'warranty negative'    => [['warranty_days' => -1], 'invalid_field', 'warranty_days'],
      'warranty over 3650'   => [['warranty_days' => 3651], 'invalid_field', 'warranty_days'],
      'warranty fractional'  => [['warranty_days' => 1.5], 'invalid_field', 'warranty_days'],

      /* 11 — requires_visit. */
      'visit as "true"'      => [['requires_visit' => 'true'], 'invalid_field', 'requires_visit'],
      'visit as 1'           => [['requires_visit' => 1], 'invalid_field', 'requires_visit'],
      'visit as 0'           => [['requires_visit' => 0], 'invalid_field', 'requires_visit'],
    ];
  }

  /**
   * The boundaries that must be ACCEPTED. Every one of them is a value a real
   * provider sends and a careless off-by-one would refuse.
   */
  public function testTheAcceptedBoundaries() {
    // 2000 characters, and ACCENTED ones: drupal_strlen() counts characters,
    // strlen() would count bytes and refuse this at roughly 1000.
    $this->assertAccepted($this->minimalBody(['message' => str_repeat('á', 2000)]));

    // 0 is a price somebody offered, with a closed price type.
    $this->assertSame(0.0, $this->assertAccepted($this->minimalBody(['amount' => 0]))['amount']);

    // The ceiling of number_decimal(10, 2), exactly.
    $this->assertSame(99999999.99, $this->assertAccepted($this->minimalBody(['amount' => 99999999.99]))['amount']);

    // An amount sent as a string, to keep the decimals exact over the wire.
    $this->assertSame(150.5, $this->assertAccepted($this->minimalBody(['amount' => '150.50']))['amount']);

    // on_site_quote with NO amount is the whole point of that type.
    $on_site = $this->assertAccepted([
      'message'     => 'Tengo que verlo antes de dar precio.',
      'amount_type' => 'on_site_quote',
    ]);
    $this->assertNull($on_site['amount']);

    // 0 warranty days is a declaration — "no warranty" — and not an absence.
    $this->assertSame(0, $this->assertAccepted($this->minimalBody(['warranty_days' => 0]))['warranty_days']);
    $this->assertSame(3650, $this->assertAccepted($this->minimalBody(['warranty_days' => 3650]))['warranty_days']);

    // The two ends of the duration range.
    $one = $this->assertAccepted($this->minimalBody(['duration' => 1, 'duration_unit' => 'hours']));
    $this->assertSame(1, $one['duration']);
    $max = $this->assertAccepted($this->minimalBody(['duration' => 9999, 'duration_unit' => 'days']));
    $this->assertSame(9999, $max['duration']);

    // Real booleans, both of them, both ways.
    $flags = $this->assertAccepted($this->minimalBody([
      'tax_included'   => FALSE,
      'requires_visit' => TRUE,
    ]));
    $this->assertFalse($flags['tax_included']);
    $this->assertTrue($flags['requires_visit']);
  }

  /**
   * Rule 7 compares available_from <= valid_until AND NOT THE OTHER WAY ROUND.
   * Promising availability for after the offer expires is the incoherence;
   * being able to come before it expires is not.
   */
  public function testTheTwoDatesMustBeCoherentInOneDirectionOnly() {
    $soon = date('Y-m-d H:i', REQUEST_TIME + 3600);
    $later = date('Y-m-d H:i', REQUEST_TIME + 7200);

    // available_from AFTER valid_until: refused.
    $this->assertRejected(
      $this->minimalBody(['valid_until' => $soon, 'available_from' => $later]),
      'service_offer_dates_inconsistent'
    );

    // available_from BEFORE valid_until: accepted, and this is the normal case.
    $this->assertAccepted($this->minimalBody(['valid_until' => $later, 'available_from' => $soon]));

    // The same instant: accepted. Nothing is incoherent about an offer that
    // expires the moment you become available.
    $this->assertAccepted($this->minimalBody(['valid_until' => $soon, 'available_from' => $soon]));

    // Either one alone is fine.
    $this->assertAccepted($this->minimalBody(['valid_until' => $later]));
    $this->assertAccepted($this->minimalBody(['available_from' => $soon]));
  }

  /**
   * The cut is STRICTLY the future, the same line SPEC 90 drew for
   * `desired_start`: the exact second of now is refused along with the past.
   */
  public function testTheExactSecondOfNowIsAlreadyThePast() {
    $now = date('Y-m-d H:i:s', REQUEST_TIME);

    $this->assertRejected($this->minimalBody(['valid_until' => $now]), 'invalid_field', 'valid_until');
    $this->assertRejected($this->minimalBody(['available_from' => $now]), 'invalid_field', 'available_from');

    $this->assertAccepted($this->minimalBody([
      'valid_until' => date('Y-m-d H:i:s', REQUEST_TIME + 1),
    ]));
  }

  /**
   * An optional text that is empty after trim() is stored as ABSENT and not as
   * "": the two are different in the database, and an empty row is a value
   * somebody will eventually read as one.
   */
  public function testAnEmptyOptionalTextIsStoredAsAbsent() {
    $values = $this->assertAccepted($this->minimalBody([
      'includes' => '   ',
      'excludes' => '',
    ]));

    $this->assertNull($values['includes']);
    $this->assertNull($values['excludes']);

    // And a real one is trimmed but otherwise stored as typed.
    $kept = $this->assertAccepted($this->minimalBody([
      'includes' => "  Mano de obra.\nDesplazamiento.  ",
    ]));
    $this->assertSame("Mano de obra.\nDesplazamiento.", $kept['includes']);
  }

  /**
   * The order is the contract here too: a body that breaks two rules answers
   * the earlier one, so the provider fixes the first thing that is wrong
   * instead of chasing errors one deploy at a time.
   */
  public function testTheFirstBrokenRuleAnswers() {
    // message (1) beats amount_type (2).
    $this->assertRejected(['amount_type' => 'nope'], 'missing_field', 'message');

    // amount_type (2) beats amount (3).
    $this->assertRejected(
      ['message' => 'Hola.', 'amount_type' => 'nope', 'amount' => -5],
      'invalid_field',
      'amount_type'
    );

    // amount (3) beats tax_included (4).
    $this->assertRejected(
      $this->minimalBody(['amount' => -5, 'tax_included' => 'sí']),
      'invalid_field',
      'amount'
    );

    // The dates (5, 6) beat their coherence (7).
    $this->assertRejected(
      $this->minimalBody(['valid_until' => 'nunca', 'available_from' => '2001-01-01 10:00']),
      'invalid_field',
      'valid_until'
    );
  }

  /**
   * `status` in the body is IGNORED and never refused: it is not a field of
   * this request, and the offer is born 'sent' whatever the client sends.
   * Same for a `request_id`, which lives in the route and nowhere else.
   */
  public function testUnknownKeysInTheBodyAreIgnored() {
    $values = $this->assertAccepted($this->minimalBody([
      'status'     => 'selected',
      'request_id' => 999,
      'provider'   => ['id' => 1],
    ]));

    $this->assertArrayNotHasKey('status', $values);
    $this->assertArrayNotHasKey('request_id', $values);
    $this->assertCount(12, $values);
  }

  /* -------------------------------------------------------------------------
   * The node, the title and the transaction comment (SPEC 100).
   * ---------------------------------------------------------------------- */

  /**
   * The seven values the server fixes, none of which is a field of the body.
   */
  public function testTheNodeCarriesWhatTheServerDecides() {
    $values = $this->assertAccepted($this->minimalBody());
    $node = myapi_service_offer_build_node(self::UID, 128, 41, 'Plomería Torres', $values);

    $this->assertSame(MYAPI_SERVICES_OFFER_TYPE, $node->type);
    $this->assertSame(self::UID, $node->uid);
    $this->assertSame(1, $node->status);
    $this->assertSame(128, $node->field_request[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(41, $node->field_provider[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('sent', $node->field_offer_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame(MYAPI_SERVICES_OFFER_STATUS_SENT, $node->field_offer_status[LANGUAGE_NONE][0]['value']);

    // The three chat fields are never written.
    foreach (['field_firebase_path', 'field_chat_opened_at', 'field_last_message_at'] as $field) {
      $this->assertFalse(property_exists($node, $field), $field . ' must stay empty');
    }
  }

  /**
   * An optional value the provider did not declare is NOT WRITTEN AT ALL,
   * rather than written as an empty row: a new offer that declared nothing must
   * be indistinguishable from one stored before myapi_update_7035().
   */
  public function testAnUndeclaredOptionalIsNotWritten() {
    $node = myapi_service_offer_build_node(
      self::UID, 128, 41, 'Plomería Torres',
      $this->assertAccepted($this->minimalBody())
    );

    foreach (['field_offer_tax_included', 'field_offer_valid_until',
      'field_offer_available_from', 'field_offer_duration',
      'field_offer_duration_unit', 'field_offer_includes',
      'field_offer_excludes', 'field_offer_warranty_days'] as $field) {
      $this->assertFalse(property_exists($node, $field), $field . ' must not be written when undeclared');
    }

    // requires_visit IS the exception: always written, as 0 or 1.
    $this->assertSame(0, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);
  }

  /**
   * A full body writes all ten quote columns, with the booleans stored as the
   * 0/1 a list_boolean column holds and never as PHP booleans.
   */
  public function testAFullBodyWritesEveryQuoteColumn() {
    $valid_until = date('Y-m-d H:i', REQUEST_TIME + 7200);
    $available_from = date('Y-m-d H:i', REQUEST_TIME + 3600);

    $values = $this->assertAccepted($this->minimalBody([
      'tax_included'   => TRUE,
      'valid_until'    => $valid_until,
      'available_from' => $available_from,
      'duration'       => 3,
      'duration_unit'  => 'hours',
      'includes'       => 'Mano de obra.',
      'excludes'       => 'El calentador.',
      'warranty_days'  => 90,
      'requires_visit' => TRUE,
    ]));

    $node = myapi_service_offer_build_node(self::UID, 128, 41, 'Plomería Torres', $values);

    $this->assertSame('fixed', $node->field_offer_amount_type[LANGUAGE_NONE][0]['value']);
    $this->assertSame(150.5, $node->field_offer_amount[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $node->field_offer_tax_included[LANGUAGE_NONE][0]['value']);
    $this->assertSame(strtotime($valid_until), $node->field_offer_valid_until[LANGUAGE_NONE][0]['value']);
    $this->assertSame(strtotime($available_from), $node->field_offer_available_from[LANGUAGE_NONE][0]['value']);
    $this->assertSame(3, $node->field_offer_duration[LANGUAGE_NONE][0]['value']);
    $this->assertSame('hours', $node->field_offer_duration_unit[LANGUAGE_NONE][0]['value']);
    $this->assertSame('Mano de obra.', $node->field_offer_includes[LANGUAGE_NONE][0]['value']);
    $this->assertSame('El calentador.', $node->field_offer_excludes[LANGUAGE_NONE][0]['value']);
    $this->assertSame(90, $node->field_offer_warranty_days[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);

    // Only 'value' is ever written, never 'format'.
    $this->assertSame(['value'], array_keys($node->field_offer_message[LANGUAGE_NONE][0]));
    $this->assertSame(['value'], array_keys($node->field_offer_includes[LANGUAGE_NONE][0]));
  }

  /**
   * A `status` in the body cannot make the offer anything but 'sent'. The
   * validator drops the key and the builder writes the constant.
   */
  public function testTheBodyCannotChooseTheOfferStatus() {
    $values = $this->assertAccepted($this->minimalBody(['status' => 'selected']));
    $node = myapi_service_offer_build_node(self::UID, 128, 41, 'Torres', $values);

    $this->assertSame('sent', $node->field_offer_status[LANGUAGE_NONE][0]['value']);
  }

  /**
   * node.title never exceeds 255 characters, NOT EVEN with the longest provider
   * name on the site — and the request number, which is what makes the title
   * findable in /admin/content, survives the cut.
   */
  public function testTheTitleFitsAndKeepsTheRequestNumber() {
    $short = myapi_service_offer_title(128, 'Plomería Torres');
    $this->assertStringContainsString('Plomería Torres', $short);
    $this->assertStringContainsString('#128', $short);

    $long = myapi_service_offer_title(128, str_repeat('Fontanería Hermanos Rodríguez ', 40));
    $this->assertLessThanOrEqual(255, drupal_strlen($long));
    $this->assertStringContainsString('#128', $long, 'the request number must survive the truncation');
    $this->assertStringEndsWith('#128', $long);
  }

  /**
   * No node of this module is ever titleless (the promise since SPEC 60), so a
   * provider whose name does not resolve still gets a usable title.
   */
  public function testATitlelessProviderStillGetsATitle() {
    foreach ([NULL, '', '   '] as $name) {
      $title = myapi_service_offer_title(128, $name);

      $this->assertNotSame('', trim($title));
      $this->assertStringContainsString('#128', $title);
      $this->assertLessThanOrEqual(255, drupal_strlen($title));
    }
  }

  /**
   * The title carries the provider's name RAW, with no check_plain(): it goes
   * into a raw column, and a t() '@name' replacement would store an &amp;
   * where the company has an ampersand.
   */
  public function testTheTitleDoesNotEscapeTheProvidersName() {
    $title = myapi_service_offer_title(128, 'Fontanería & Hijos');

    $this->assertStringContainsString('Fontanería & Hijos', $title);
    $this->assertStringNotContainsString('&amp;', $title);
  }

  /**
   * SPEC 92's promise: no transaction is ever born without a comment. It names
   * the PROVIDER and not the account, because the resident reads the timeline
   * and the operator's user account means nothing to them.
   */
  public function testTheTransactionCommentIsNeverEmptyAndNamesTheProvider() {
    $this->assertStringContainsString(
      'Plomería Torres',
      myapi_service_offer_transaction_comment('Plomería Torres')
    );

    foreach ([NULL, '', '   '] as $name) {
      $this->assertNotSame('', trim(myapi_service_offer_transaction_comment($name)));
    }

    // Raw, like the title and for the same reason.
    $this->assertStringNotContainsString(
      '&amp;',
      myapi_service_offer_transaction_comment('Fontanería & Hijos')
    );
  }
  /* -------------------------------------------------------------------------
   * The twelve i18n keys (SPEC 100).
   * ---------------------------------------------------------------------- */

  /**
   * Every code this endpoint can answer resolves to a real message in BOTH
   * languages. myapi_t() falls back to returning the key itself when it does
   * not know it, so a missing translation is not an error anywhere — it is a
   * response with 'service_offer_already_sent' where the message should be, and
   * this is what catches it.
   *
   * I18nTest already holds the whole catalogue to parity, no duplicates and
   * matching placeholders; this test pins these twelve BY NAME, so renaming one
   * in the code without renaming it in the catalogue fails here.
   *
   * @dataProvider spec100Keys
   */
  public function testTheTwelveKeysResolveInBothLanguages($key) {
    foreach (['es', 'en'] as $lang) {
      $message = myapi_t($key, [], $lang);

      $this->assertNotSame($key, $message, $key . ' has no ' . $lang . ' message');
      $this->assertNotSame('', trim($message));
      // No placeholder left unreplaced: none of the twelve takes one.
      $this->assertStringNotContainsString('@', $message, $key . ' (' . $lang . ') must take no placeholder');
    }

    // And the two languages are actually different texts, not one pasted twice.
    $this->assertNotSame(myapi_t($key, [], 'es'), myapi_t($key, [], 'en'), $key);
  }

  public function spec100Keys() {
    return [
      ['service_offer_created'],
      ['service_offer_provider_not_owned'],
      ['service_offer_provider_not_active'],
      ['service_offer_own_request'],
      ['service_request_not_offerable'],
      ['service_offer_category_mismatch'],
      ['service_offer_already_sent'],
      ['service_offer_amount_required'],
      ['service_offer_amount_not_allowed'],
      ['service_offer_tax_without_amount'],
      ['service_offer_dates_inconsistent'],
      ['service_offer_duration_incomplete'],
    ];
  }

  /**
   * Every error_code the gate and the body validation can return has a message.
   * Read off the FUNCTIONS rather than off a list written here: a seventh gate
   * condition added tomorrow with no catalogue entry fails this test, which a
   * hand-kept list never would.
   */
  public function testEveryCodeTheGateCanReturnIsTranslated() {
    $codes = [];

    foreach ($this->gateCases() as $case) {
      if ($case[2] !== NULL) {
        $codes[$case[2]] = TRUE;
      }
    }
    foreach ($this->bodyCases() as $case) {
      $codes[$case[1]] = TRUE;
    }

    $this->assertNotEmpty($codes);

    foreach (array_keys($codes) as $code) {
      foreach (['es', 'en'] as $lang) {
        $this->assertNotSame(
          $code,
          myapi_t($code, ['@field' => 'x'], $lang),
          $code . ' is answered by this endpoint and has no ' . $lang . ' message'
        );
      }
    }
  }

  /**
   * The four generic keys this endpoint REUSES are still there, untouched. The
   * spec adds twelve and reuses seven; these are the ones a careless cleanup of
   * the catalogue could take away without any SPEC 100 test noticing.
   */
  public function testTheReusedGenericKeysStillExist() {
    foreach (['missing_authorization', 'invalid_token', 'method_not_allowed',
      'missing_field', 'invalid_field', 'provider_role_required',
      'service_request_not_found'] as $key) {
      foreach (['es', 'en'] as $lang) {
        $this->assertNotSame($key, myapi_t($key, ['@field' => 'x'], $lang), $key . ' (' . $lang . ')');
      }
    }
  }
  /* -------------------------------------------------------------------------
   * The endpoint itself: method, nid, token and the role gate (SPEC 100).
   *
   * WHAT THIS SECTION CAN AND CANNOT REACH. myapi_request_body() reads
   * php://input, which a unit test cannot write — the same limitation
   * RequestValidationTest and AuthEndpointGuardsTest already document — so
   * everything from step 4 (provider_id) onwards is exercised through its pure
   * functions above and through the manual matrix of the spec's step 11, never
   * from here. What IS reachable is every guard that runs BEFORE the body is
   * read, and those are exactly the ones whose whole point is the order they
   * run in.
   * ---------------------------------------------------------------------- */

  const TOKEN = 'a-valid-access-token';
  const REQUEST_NID = 128;
  const PROVIDER_A = 41;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
    myapi_test_write_reset();
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_nodes'] = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_static_reset();
    myapi_test_db_seed();
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  private function tokenRow($uid, array $overrides = []) {
    return $overrides + [
      'id'                => '1',
      'uid'               => (string) $uid,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * Seeds an account with a token and a set of roles. The provider role by
   * default, because it is the precondition of everything that is not the gate.
   */
  private function seedAccount($uid = self::UID, $roles = NULL) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;

    $GLOBALS['myapi_test_users'][$uid] = [
      'uid'    => $uid,
      'name'   => 'usuario' . $uid,
      'status' => 1,
      'roles'  => $roles,
    ];

    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow($uid)]]);
    myapi_test_static_reset();
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * Runs the endpoint the way hook_menu() does — the wildcard raw, as Drupal
   * hands it over.
   */
  private function dispatch($nid = self::REQUEST_NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_offer_dispatch($nid);
    });
  }

  /**
   * EVERY METHOD BUT POST IS 405, AND BEFORE THE TOKEN AND BEFORE ANY QUERY. A
   * GET with no Authorization header at all is still 405, never 401 — and GET
   * is in this list on purpose: there is no collection read here, the offers
   * travel inside the two details.
   */
  public function testEveryMethodOtherThanPostIs405BeforeAuthentication() {
    foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;
      unset($_SERVER['HTTP_AUTHORIZATION']);
      myapi_test_db_queries();

      $result = $this->dispatch();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertFalse($result['json']['success'], $method);
      $this->assertSame([], myapi_test_db_queries(), $method . ' must cost no query');
      $this->assertSame([], myapi_test_node_saves(), $method . ' must write nothing');
    }
  }

  /**
   * The 405 does not depend on the shape of the id either: the method is wrong
   * whatever the URL names.
   */
  public function testTheMethodIsCheckedBeforeTheNid() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    myapi_test_db_queries();

    $result = $this->dispatch('no-soy-un-nid');

    $this->assertSame(405, $result['status']);
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * A nid that cannot name a request is 404, BEFORE the token: the answer is
   * about the shape of the URL and not about what exists, so it leaks nothing
   * and costs no query — not even the token's.
   *
   * @dataProvider impossibleNids
   */
  public function testAnImpossibleNidIs404WithNoQuery($nid) {
    myapi_test_db_queries();

    $result = $this->dispatch($nid);

    $this->assertSame(404, $result['status'], var_export($nid, TRUE));
    $this->assertSame('service_request_not_found', $result['json']['error_code'], var_export($nid, TRUE));
    $this->assertSame([], myapi_test_db_queries(), var_export($nid, TRUE) . ' must cost no query');
  }

  public function impossibleNids() {
    return [
      'not a number' => ['abc'],
      'zero'         => ['0'],
      'negative'     => ['-3'],
      'fractional'   => ['1.5'],
      'empty'        => [''],
      'null'         => [NULL],
      'an array'     => [['128']],
      'with spaces'  => [' 128 '],
    ];
  }

  /**
   * No Authorization header is 401 missing_authorization, and the role gate is
   * never reached.
   */
  public function testNoTokenIs401() {
    $this->seedAccount();

    $result = $this->dispatch();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * An invented, a revoked and an expired token are all 401 invalid_token —
   * told apart from each other by nothing, on purpose.
   */
  public function testABadTokenIs401() {
    $cases = [
      'invented' => NULL,
      'revoked'  => ['revoked' => '1'],
      'expired'  => ['access_expires_at' => REQUEST_TIME - 1],
    ];

    foreach ($cases as $label => $overrides) {
      $GLOBALS['myapi_test_users'][self::UID] = [
        'uid' => self::UID, 'name' => 'u', 'status' => 1,
        'roles' => ['authenticated user', MYAPI_PROVIDER_ROLE],
      ];
      myapi_test_db_seed([
        'my_api_tokens' => $overrides === NULL ? [] : [$this->tokenRow(self::UID, $overrides)],
      ]);
      myapi_test_static_reset();
      $this->authenticate();

      $result = $this->dispatch();

      $this->assertSame(401, $result['status'], $label);
      $this->assertSame('invalid_token', $result['json']['error_code'], $label);
    }
  }

  /**
   * THE ROLE GATE, BEFORE ANY QUERY ABOUT PROVIDERS OR REQUESTS. An account
   * without the 'proveedor' role is 403 provider_role_required — AND SO IS AN
   * ADMINISTRATOR. Operating a provider is a marketplace fact, not a
   * permission, so no role short-circuits it.
   *
   * @dataProvider rolelessAccounts
   */
  public function testAnAccountWithoutTheProviderRoleIs403($roles) {
    $this->seedAccount(self::UID, $roles);
    $this->authenticate();

    $result = $this->dispatch();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves(), 'a refused account must write nothing');
  }

  public function rolelessAccounts() {
    return [
      'plain user'    => [['authenticated user']],
      'administrator' => [['authenticated user', 'administrator']],
      'no roles'      => [[]],
    ];
  }

  /**
   * With the role and a valid token, the next thing asked for is provider_id —
   * and a body that never arrived is a MISSING provider_id, not a body error of
   * its own.
   *
   * php://input is empty in a unit test, so this is the one step-4 case
   * reachable from here; the other three shapes of a bad provider_id are
   * acceptance criteria of the manual matrix.
   */
  public function testAnAbsentBodyIsAMissingProviderId() {
    $this->seedAccount();
    $this->authenticate();

    $result = $this->dispatch();

    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertStringContainsString('provider_id', $result['json']['error']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * Every refusal above answers the documented envelope — success false, an
   * error_code and a TRANSLATED error — and none of them writes a node.
   */
  public function testEveryRefusalAnswersTheErrorEnvelopeAndWritesNothing() {
    $this->seedAccount(self::UID, ['authenticated user']);
    $this->authenticate();

    $result = $this->dispatch();

    $this->assertSame(['success', 'error_code', 'error'], array_keys($result['json']));
    $this->assertFalse($result['json']['success']);
    $this->assertNotSame(
      $result['json']['error_code'],
      $result['json']['error'],
      'the message must be translated, not the key echoed back'
    );
    $this->assertSame([], myapi_test_node_saves());
    $this->assertTrue($result['exited']);
  }
}
