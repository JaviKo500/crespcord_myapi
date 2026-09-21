<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.unit_query.inc';
require_once __DIR__ . '/../../includes/myapi.bot_auth.inc';
require_once __DIR__ . '/../../includes/myapi.payment_workflow.inc';
require_once __DIR__ . '/../../includes/myapi.payment_write.inc';
require_once __DIR__ . '/../../resources/bot.resource.inc';

/**
 * Unit tests for POST /api/v1/bot/payments (SPEC 130).
 *
 * The third endpoint of the bot, and the first one that WRITES. What can be
 * asserted without a site is, almost exactly, where the endpoint's decisions
 * live: four pure functions that turn a JSON reading into either an error with
 * a name or ten normalized values, plus the two that build the key and dress
 * the node.
 *
 * WHY THE WEIGHT IS ON myapi_bot_payment_validate(). Every other endpoint of
 * this module is called by the Flutter app, where a person fills a form and
 * reads what went wrong. This one is called by n8n, which reads nothing: a
 * payload the bot got subtly wrong either becomes a 422 naming the exact
 * dotted path, or becomes a payment with a false value that nobody will ever
 * notice — a date that is the day of the conversation instead of the day of
 * the transfer, an amount that came in as a string of the wrong shape. So the
 * tests below are mostly one shape: give it a payload with one thing wrong,
 * assert the error code AND the '@field' the flow will read in its log.
 *
 * WHAT THIS LAYER CANNOT REACH, stated once. Everything the spec marks 🔴:
 * node_save(), the ledger INSERT and its primary key, the race between two
 * simultaneous retries, the managed file, the queued email. They need a live
 * Drupal, they are named in the spec's acceptance criteria, and a test that
 * stubbed db_insert() to throw would be asserting the stub throws. What IS
 * asserted here about idempotency is its only pure part, and the part a bug
 * would actually hide: that the key is a function of exactly those two values.
 */
class BotPaymentCreateTest extends TestCase {

  /**
   * The key every fixture request sends, and the one the variable holds.
   */
  const API_KEY = 'a-shared-secret-for-the-bot';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_variables'] = ['myapi_bot_api_key' => self::API_KEY];
    $GLOBALS['myapi_test_watchdog'] = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_API_KEY'] = self::API_KEY;
    $_GET = ['q' => 'api/v1/bot/payments'];
    $_POST = [];
    $_FILES = [];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_variables'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_X_API_KEY']);
    $_GET = [];
    $_POST = [];
    $_FILES = [];
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A complete, valid payload — the shape n8n sends after renaming its JSON.
   *
   * Carries the informative keys too (the sender, the button, the confidences,
   * the model), because half of what this endpoint promises is that they are
   * kept as evidence and that none of them is validated.
   */
  private function payload(array $overrides = []) {
    $payload = [
      'message' => [
        'message_key' => 'wamid.HBgMNTkzOTg3NTM1NjQ1FQIAEhgU',
        'channel'     => 'whatsapp',
        'provider'    => 'meta',
        'type'        => 'image',
        'sender'      => [
          'id'          => '593987535645',
          'local_phone' => '0987535645',
          'name'        => 'Javier C.',
        ],
        'button'      => ['id' => 'pay', 'title' => 'Registrar pago'],
        'received_at' => '2026-09-12T14:02:55',
      ],
      'identity' => [
        'person' => ['uid' => 3, 'name' => 'Javier C.'],
        'unit'   => [
          'unit_id'        => 45,
          'unit'           => '3B',
          'condominium_id' => 12,
          'condominium'    => 'Torre Azul',
          'relation'       => 'owner',
        ],
        'source' => 'phone_lookup',
      ],
      'media' => [
        'ref'       => 'm-2',
        'mime_type' => 'image/jpeg',
        'file_name' => 'comprobante.jpg',
      ],
      'receipt' => [
        'reference'        => '018273645',
        'amount'           => 45.3,
        'date'             => '2026-09-12',
        'issuing_bank'     => 'Banco Pichincha',
        'destination_bank' => 'Banco Guayaquil',
        'confidence'       => ['amount' => 0.97, 'date' => 0.31],
        'corrected'        => ['date'],
        'model'            => 'some-vision-model',
      ],
    ];

    return $this->merge($payload, $overrides);
  }

  /**
   * Recursive array merge where a NULL override REMOVES the key.
   *
   * Removing is what most of the tests below need — "the same payload but
   * without receipt.date" — and array_merge_recursive() cannot express it.
   */
  private function merge(array $base, array $overrides) {
    foreach ($overrides as $key => $value) {
      if ($value === NULL) {
        unset($base[$key]);
      }
      elseif (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
        $base[$key] = $this->merge($base[$key], $value);
      }
      else {
        $base[$key] = $value;
      }
    }

    return $base;
  }

  /**
   * Runs myapi_bot_payment_validate() and captures what it printed.
   */
  private function validate(array $payload) {
    return myapi_test_capture(function () use ($payload) {
      myapi_bot_payment_validate($payload);
    });
  }

  /**
   * Runs myapi_bot_payment_parse_payload() over a raw 'payload' part.
   */
  private function parse($raw) {
    $_POST = $raw === NULL ? [] : ['payload' => $raw];

    return myapi_test_capture(function () {
      myapi_bot_payment_parse_payload();
    });
  }

  /**
   * Asserts a captured response is a 422 with that code, and that nothing was
   * created on the way out.
   */
  private function assertError($result, $status, $code, $field = NULL, $message = '') {
    $this->assertSame($status, $result['status'], $message);
    $this->assertSame($code, $result['json']['error_code'], $message);
    $this->assertFalse($result['json']['success'], $message);
    if ($field !== NULL) {
      $this->assertStringContainsString($field, $result['json']['error'], $message . ': @field');
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_bot_payload_get() — the dotted-path reader.
   *
   * Its whole job is to never emit a notice. The payload is four levels deep
   * and arrives from a model reading a photo: a missing step is normal, and a
   * PHP warning would land inside the JSON body the bot parses.
   * ---------------------------------------------------------------------- */

  public function testADottedPathReadsThroughEveryLevel() {
    $payload = $this->payload();

    $this->assertSame('wamid.HBgMNTkzOTg3NTM1NjQ1FQIAEhgU', myapi_bot_payload_get($payload, 'message.message_key'));
    $this->assertSame(3, myapi_bot_payload_get($payload, 'identity.person.uid'));
    $this->assertSame(45, myapi_bot_payload_get($payload, 'identity.unit.unit_id'));
    $this->assertSame('0987535645', myapi_bot_payload_get($payload, 'message.sender.local_phone'));
    $this->assertSame(0.31, myapi_bot_payload_get($payload, 'receipt.confidence.date'));
  }

  /**
   * A single step returns the whole subtree, which is what makes the evidence
   * assertions below readable.
   */
  public function testASingleStepReturnsTheSubtree() {
    $payload = $this->payload();

    $this->assertSame($payload['receipt'], myapi_bot_payload_get($payload, 'receipt'));
  }

  /**
   * Absent at the first step, in the middle and at the leaf — all NULL, and no
   * notice. The @ would hide one; there is none, so a notice fails the test.
   */
  public function testAnAbsentStepIsNullAtEveryDepth() {
    $payload = $this->payload();

    $this->assertNull(myapi_bot_payload_get($payload, 'nothing'));
    $this->assertNull(myapi_bot_payload_get($payload, 'nothing.at.all'));
    $this->assertNull(myapi_bot_payload_get($payload, 'identity.nothing.uid'));
    $this->assertNull(myapi_bot_payload_get($payload, 'identity.person.nothing'));
  }

  /**
   * An intermediate step holding a scalar is the case that would fatal on
   * "cannot use string offset as an array" if the guard checked isset() alone.
   */
  public function testAScalarIntermediateStepIsNull() {
    $payload = $this->payload();

    $this->assertNull(myapi_bot_payload_get($payload, 'receipt.reference.deeper'));
    $this->assertNull(myapi_bot_payload_get($payload, 'receipt.amount.deeper.still'));
  }

  /**
   * A key explicitly set to NULL reads as NULL and not as a fatal — same
   * answer as absent, which is what every caller below expects.
   */
  public function testAnExplicitNullReadsAsNull() {
    $this->assertNull(myapi_bot_payload_get(['receipt' => ['date' => NULL]], 'receipt.date'));
  }

  /* -------------------------------------------------------------------------
   * myapi_bot_payment_parse_payload() — the 'payload' part.
   * ---------------------------------------------------------------------- */

  public function testAWellFormedPayloadParsesToAnArray() {
    $payload = $this->payload();
    $_POST = ['payload' => json_encode($payload)];

    $this->assertSame($payload, myapi_bot_payment_parse_payload());
  }

  /**
   * Absent, empty and whitespace-only are the same failure: the flow did not
   * put a reading in the field.
   */
  public function testAnAbsentOrEmptyPayloadIs422() {
    foreach ([NULL, '', '   '] as $raw) {
      $result = $this->parse($raw);

      $this->assertError($result, 422, 'invalid_payload', NULL, var_export($raw, TRUE));
    }
  }

  /**
   * Malformed JSON, and JSON that is well formed but is not an object.
   *
   * The list case is the one worth having: every read in validate() is by
   * name, so a list would answer NULL to all of them and the endpoint would
   * report a missing message_key for what is really a malformed payload.
   */
  public function testJsonThatIsNotAnObjectIs422() {
    $cases = [
      'broken'        => '{"receipt": ',
      'trailing'      => '{"a": 1},',
      'scalar number' => '42',
      'scalar string' => '"just a string"',
      'scalar bool'   => 'true',
      'null'          => 'null',
      'list'          => '[1,2,3]',
      'list of maps'  => '[{"receipt":{}}]',
      'empty object'  => '{}',
      'empty list'    => '[]',
    ];

    foreach ($cases as $label => $raw) {
      $result = $this->parse($raw);

      $this->assertError($result, 422, 'invalid_payload', NULL, $label);
    }
  }

  /**
   * The 64 KB cap. Not about the bot: a drupal_json_decode() of an unbounded
   * body is a cheap denial of service against a route whose credential is a
   * shared machine key.
   */
  public function testAPayloadOverTheCapIs422() {
    $oversized = json_encode(['pad' => str_repeat('x', MYAPI_BOT_PAYMENT_MAX_PAYLOAD)]);
    $this->assertGreaterThan(MYAPI_BOT_PAYMENT_MAX_PAYLOAD, strlen($oversized), 'fixture sanity');

    $this->assertError($this->parse($oversized), 422, 'invalid_payload');
  }

  /**
   * And the other side of the cap: a big-but-allowed payload still parses, so
   * the limit is a limit and not an off-by-one that rejects real readings.
   */
  public function testAPayloadUnderTheCapParses() {
    $padding = MYAPI_BOT_PAYMENT_MAX_PAYLOAD - 100;
    $raw = json_encode(['pad' => str_repeat('x', $padding)]);
    $this->assertLessThanOrEqual(MYAPI_BOT_PAYMENT_MAX_PAYLOAD, strlen($raw), 'fixture sanity');

    $_POST = ['payload' => $raw];
    $parsed = myapi_bot_payment_parse_payload();

    $this->assertSame(str_repeat('x', $padding), $parsed['pad']);
  }

  /* -------------------------------------------------------------------------
   * myapi_bot_payment_validate() — the contract, in the order of the table.
   * ---------------------------------------------------------------------- */

  /**
   * The happy path: ten normalized values and nothing else.
   *
   * The keys are asserted exactly, because everything downstream reads this
   * array by name — a renamed key would be an undefined-index notice inside
   * the transaction, not a failing assertion somewhere visible.
   */
  public function testAValidPayloadReturnsTheTenNormalizedValues() {
    $values = myapi_bot_payment_validate($this->payload());

    $this->assertSame([
      'message_key',
      'media_ref',
      'uid',
      'unit_nid',
      'condominium_id',
      'reference',
      'amount',
      'date',
      'issuing_bank',
      'destination_bank',
    ], array_keys($values));

    $this->assertSame('wamid.HBgMNTkzOTg3NTM1NjQ1FQIAEhgU', $values['message_key']);
    $this->assertSame('m-2', $values['media_ref']);
    $this->assertSame(3, $values['uid']);
    $this->assertSame(45, $values['unit_nid']);
    $this->assertSame(12, $values['condominium_id']);
    $this->assertSame('018273645', $values['reference']);
    $this->assertSame(45.3, $values['amount']);
    $this->assertSame('2026-09-12T00:00:00', $values['date']);
    $this->assertSame('Banco Pichincha', $values['issuing_bank']);
    $this->assertSame('Banco Guayaquil', $values['destination_bank']);
  }

  /**
   * Every required field, absent, reports missing_field with its FULL dotted
   * path.
   *
   * The path is the point. The payload has more than one 'reference' and more
   * than one 'name' in reach, and an '@field' of 'uid' would send whoever
   * debugs the flow looking in the wrong object.
   */
  public function testEveryRequiredFieldReportsItsDottedPath() {
    $cases = [
      'message.message_key'           => ['message' => ['message_key' => NULL]],
      'identity.person.uid'           => ['identity' => ['person' => ['uid' => NULL]]],
      'identity.unit.unit_id'         => ['identity' => ['unit' => ['unit_id' => NULL]]],
      'identity.unit.condominium_id'  => ['identity' => ['unit' => ['condominium_id' => NULL]]],
      'receipt.reference'             => ['receipt' => ['reference' => NULL]],
      'receipt.amount'                => ['receipt' => ['amount' => NULL]],
      'receipt.date'                  => ['receipt' => ['date' => NULL]],
    ];

    foreach ($cases as $path => $override) {
      $result = $this->validate($this->payload($override));

      $this->assertError($result, 422, 'missing_field', $path, $path);
    }
  }

  /**
   * A whole branch missing reports the same way as the leaf missing: n8n gets
   * the deepest path it failed to provide, not "identity is absent".
   */
  public function testAMissingBranchStillReportsALeafPath() {
    $result = $this->validate($this->payload(['identity' => NULL]));

    $this->assertError($result, 422, 'missing_field', 'identity.person.uid');
  }

  /**
   * An empty string is not a value either, for the three required strings.
   */
  public function testAnEmptyRequiredStringIsMissing() {
    $cases = [
      'message.message_key' => ['message' => ['message_key' => '   ']],
      'receipt.reference'   => ['receipt' => ['reference' => '  ']],
      'receipt.date'        => ['receipt' => ['date' => '']],
    ];

    foreach ($cases as $path => $override) {
      $result = $this->validate($this->payload($override));

      $this->assertError($result, 422, 'missing_field', $path, $path);
    }
  }

  /**
   * Present but not an id: invalid_field, not missing_field.
   *
   * The two codes send n8n to two different places — one says a mapping node
   * never wrote the key, the other that it wrote something that is not an id —
   * and collapsing them would hide which.
   */
  public function testAMalformedIdIsInvalidAndNotMissing() {
    $cases = [
      'zero'     => 0,
      'negative' => -3,
      'float'    => 3.5,
      'text'     => 'three',
      'mixed'    => '3a',
      'bool'     => TRUE,
      'array'    => [3],
      'spaced'   => ' 3 ',
    ];

    foreach ($cases as $label => $value) {
      $result = $this->validate($this->payload(['identity' => ['person' => ['uid' => $value]]]));

      $this->assertError($result, 422, 'invalid_field', 'identity.person.uid', $label);
    }
  }

  /**
   * A numeric STRING is an id, because n8n mapping nodes routinely stringify.
   */
  public function testANumericStringIsAnAcceptableId() {
    $values = myapi_bot_payment_validate($this->payload([
      'identity' => ['person' => ['uid' => '3'], 'unit' => ['unit_id' => '45', 'condominium_id' => '12']],
    ]));

    $this->assertSame(3, $values['uid']);
    $this->assertSame(45, $values['unit_nid']);
    $this->assertSame(12, $values['condominium_id']);
  }

  /**
   * The amount rule is the app's, not a second one: is_numeric() && > 0.
   */
  public function testANonPositiveOrNonNumericAmountIsInvalid() {
    foreach ([0, '0', -1, '-45.30', 'mucho', '', ' ', TRUE, []] as $value) {
      $result = $this->validate($this->payload(['receipt' => ['amount' => $value]]));

      $code = ($value === '' || $value === ' ' || $value === []) ? NULL : 'invalid_amount';
      if ($code === NULL) {
        // Empty and whitespace read as absent, which is missing_field.
        $this->assertSame(422, $result['status'], var_export($value, TRUE));
        continue;
      }
      $this->assertError($result, 422, 'invalid_amount', NULL, var_export($value, TRUE));
    }
  }

  /**
   * An amount as a string is accepted and comes back as a float, because that
   * is how n8n sends numbers half the time.
   */
  public function testAnAmountAsAStringBecomesAFloat() {
    $values = myapi_bot_payment_validate($this->payload(['receipt' => ['amount' => '45.30']]));

    $this->assertSame(45.3, $values['amount']);
  }

  /**
   * The date is REQUIRED here and optional in the app, and this is the test
   * that pins the difference. An absent date means the OCR did not read one,
   * and falling back to server time would date the payment on the day of the
   * conversation instead of the day of the transfer — a false value nobody
   * would ever catch.
   */
  public function testAnAbsentDateIsMissingAndNeverTheServerClock() {
    $result = $this->validate($this->payload(['receipt' => ['date' => NULL]]));

    $this->assertError($result, 422, 'missing_field', 'receipt.date');
    $this->assertStringNotContainsString(date('Y-m-d'), $result['output'], 'no fall back to today');
  }

  /**
   * An impossible or misshapen date is invalid_date, through the app's own
   * myapi_payment_normalize_date() — so the two doors cannot drift into
   * accepting different dates for the same node.
   */
  public function testAnImpossibleDateIsInvalid() {
    $cases = ['2026-02-30', '2026-13-01', '12/09/2026', '2026-9-2', '2026-09-12T25:00:00', 'ayer', ['2026-09-12']];

    foreach ($cases as $value) {
      $result = $this->validate($this->payload(['receipt' => ['date' => $value]]));

      $this->assertError($result, 422, 'invalid_date', NULL, var_export($value, TRUE));
    }
  }

  /**
   * A bare calendar date becomes midnight, and a full datetime survives whole.
   */
  public function testADateIsNormalizedLikeTheApp() {
    $midnight = myapi_bot_payment_validate($this->payload(['receipt' => ['date' => '2026-09-12']]));
    $this->assertSame('2026-09-12T00:00:00', $midnight['date']);

    $exact = myapi_bot_payment_validate($this->payload(['receipt' => ['date' => '2026-09-12T13:45:30']]));
    $this->assertSame('2026-09-12T13:45:30', $exact['date']);
  }

  /**
   * 255 is the width of field_referencia; 256 is invalid_field, as in the app.
   */
  public function testAReferenceOverTwoHundredAndFiftyFiveIsInvalid() {
    $ok = myapi_bot_payment_validate($this->payload(['receipt' => ['reference' => str_repeat('7', 255)]]));
    $this->assertSame(str_repeat('7', 255), $ok['reference']);

    $result = $this->validate($this->payload(['receipt' => ['reference' => str_repeat('7', 256)]]));
    $this->assertError($result, 422, 'invalid_field', 'receipt.reference');
  }

  /**
   * The wamid is capped at the width of the column that stores it in the
   * clear. Without this guard a longer one would break the ledger INSERT
   * INSIDE the transaction, where the catch would read it as "another retry
   * won the race" and answer 200 with a payment that does not exist.
   */
  public function testAnOversizedMessageKeyIsInvalid() {
    $result = $this->validate($this->payload(['message' => ['message_key' => str_repeat('w', 256)]]));

    $this->assertError($result, 422, 'invalid_field', 'message.message_key');
  }

  /**
   * Everything that reaches the node is sanitized, exactly as the app does it:
   * the length is checked on the raw value and check_plain() runs after.
   */
  public function testTheTextThatReachesTheNodeIsSanitized() {
    $values = myapi_bot_payment_validate($this->payload([
      'receipt' => [
        'reference'        => '018<script>',
        'issuing_bank'     => 'Banco "Del Pacífico" & Cía',
        'destination_bank' => "Guayaquil's",
      ],
    ]));

    $this->assertSame(check_plain('018<script>'), $values['reference']);
    $this->assertSame(check_plain('Banco "Del Pacífico" & Cía'), $values['issuing_bank']);
    $this->assertSame(check_plain("Guayaquil's"), $values['destination_bank']);
  }

  /**
   * The banks are optional and free text. They are NOT resolved against the
   * 'bancos' vocabulary — that is the app's mechanism, and dropping it is what
   * makes "and if the name matches no term?" stop being a question.
   */
  public function testTheBanksAreOptional() {
    foreach ([NULL, '', '   '] as $value) {
      $values = myapi_bot_payment_validate($this->payload([
        'receipt' => ['issuing_bank' => $value, 'destination_bank' => $value],
      ]));

      $this->assertNull($values['issuing_bank'], var_export($value, TRUE));
      $this->assertNull($values['destination_bank'], var_export($value, TRUE));
    }
  }

  public function testAnOversizedBankNameIsInvalid() {
    $result = $this->validate($this->payload(['receipt' => ['issuing_bank' => str_repeat('B', 256)]]));

    $this->assertError($result, 422, 'invalid_field', 'receipt.issuing_bank');
  }

  /**
   * An unknown key is not an error. The bot will grow fields, and a payload
   * that tips the endpoint over the day somebody adds one is a payload nobody
   * can extend.
   */
  public function testAnUnknownKeyIsIgnoredAndNotAnError() {
    $values = myapi_bot_payment_validate($this->payload([
      'receipt'   => ['tomorrows_field' => 'whatever'],
      'brand_new' => ['nested' => TRUE],
    ]));

    $this->assertSame('018273645', $values['reference']);
    $this->assertCount(10, $values, 'the unknown keys reach the node as nothing');
  }

  /**
   * The sender's phone is NOT compared with the uid, and the unit and
   * condominium NAMES are not compared with anything.
   *
   * Both are load-bearing. SPEC 128 exists precisely to reach a unit when the
   * phone identified nobody, so demanding a match would close that road; and
   * the names are written a dozen ways while the ids are written one.
   */
  public function testThePhoneAndTheNamesAreNotValidated() {
    $values = myapi_bot_payment_validate($this->payload([
      'message'  => ['sender' => ['local_phone' => '0000000000', 'name' => 'Otra Persona']],
      'identity' => ['unit' => ['unit' => 'no existe', 'condominium' => 'tampoco']],
    ]));

    $this->assertSame(3, $values['uid']);
    $this->assertSame(45, $values['unit_nid']);
  }

  /* -------------------------------------------------------------------------
   * media.ref and the idempotency key.
   * ---------------------------------------------------------------------- */

  /**
   * Absent, empty and whitespace-only all fall back to '-', which is what
   * makes a bot that never sends media.ref behave exactly as if the key were
   * the wamid alone.
   */
  public function testAnAbsentMediaRefFallsBackToTheSentinel() {
    foreach ([NULL, '', '   '] as $value) {
      $values = myapi_bot_payment_validate($this->payload(['media' => ['ref' => $value]]));

      $this->assertSame('-', $values['media_ref'], var_export($value, TRUE));
      $this->assertSame(MYAPI_BOT_PAYMENT_MEDIA_REF_FALLBACK, $values['media_ref']);
    }

    $values = myapi_bot_payment_validate($this->payload(['media' => NULL]));
    $this->assertSame('-', $values['media_ref'], 'the whole media block absent');
  }

  public function testAnOversizedMediaRefIsInvalid() {
    $result = $this->validate($this->payload(['media' => ['ref' => str_repeat('m', 65)]]));

    $this->assertError($result, 422, 'invalid_field', 'media.ref');
  }

  /**
   * The key is a 64-character hex digest and a pure function of its two
   * inputs: the same message and attachment always hash the same, which is
   * the whole mechanism.
   */
  public function testTheKeyIsAStableSixtyFourCharacterDigest() {
    $key = myapi_bot_payment_idempotency_key('wamid.ABC', 'm-1');

    $this->assertSame(64, strlen($key));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    $this->assertSame($key, myapi_bot_payment_idempotency_key('wamid.ABC', 'm-1'));
  }

  /**
   * THE REASON THE KEY IS COMPOUND. Two receipts in one WhatsApp message
   * arrive with the SAME wamid. With the wamid alone the second would be
   * answered as a retry of the first — 200, the wrong payment back, and one
   * payment silently lost.
   */
  public function testTwoAttachmentsOfOneMessageGetDifferentKeys() {
    $first  = myapi_bot_payment_idempotency_key('wamid.ABC', 'm-1');
    $second = myapi_bot_payment_idempotency_key('wamid.ABC', 'm-2');

    $this->assertNotSame($first, $second);
  }

  /**
   * And the other direction: the same attachment reference in two different
   * messages is two different payments.
   */
  public function testTheSameAttachmentRefInTwoMessagesGetsDifferentKeys() {
    $this->assertNotSame(
      myapi_bot_payment_idempotency_key('wamid.ABC', 'm-1'),
      myapi_bot_payment_idempotency_key('wamid.XYZ', 'm-1')
    );
  }

  /**
   * The separator is not decorative: without it, ('a', 'bc') and ('ab', 'c')
   * would be the same payment.
   */
  public function testTheSeparatorKeepsTheHalvesApart() {
    $this->assertNotSame(
      myapi_bot_payment_idempotency_key('a', 'bc'),
      myapi_bot_payment_idempotency_key('ab', 'c')
    );
  }

  /* -------------------------------------------------------------------------
   * myapi_bot_payment_decorate_node() — the only thing the bot adds.
   * ---------------------------------------------------------------------- */

  /**
   * Builds the base node the way the endpoint does, then decorates it.
   */
  private function decorated(?array $payload = NULL, $issuing = 'Banco Pichincha', $destination = 'Banco Guayaquil') {
    $payload = $payload === NULL ? $this->payload() : $payload;

    $node = myapi_payment_build_node(3, 45, '018273645', 45.3, 'Transferencia', NULL, '2026-09-12T00:00:00', NULL);
    myapi_bot_payment_decorate_node($node, $payload, $issuing, $destination);

    return $node;
  }

  /**
   * The channel is 'bot', and it is the constant, not a copy of the string.
   */
  public function testTheChannelIsBot() {
    $node = $this->decorated();

    $this->assertSame(MYAPI_PAYMENT_CHANNEL_BOT, $node->field_canal[LANGUAGE_NONE][0]['value']);
    $this->assertSame('bot', $node->field_canal[LANGUAGE_NONE][0]['value']);
    $this->assertNotSame(MYAPI_PAYMENT_CHANNEL_APP, $node->field_canal[LANGUAGE_NONE][0]['value']);
  }

  /**
   * field_banco stays UNSET, which is what makes bank_id and bank_name null in
   * the response. The two banks the OCR read live in their own text fields.
   */
  public function testTheBotNeverTouchesTheBankTermReference() {
    $node = $this->decorated();

    $this->assertFalse(property_exists($node, 'field_banco'), 'field_banco stays unset');
    $this->assertSame('Banco Pichincha', $node->field_banco_emisor[LANGUAGE_NONE][0]['value']);
    $this->assertSame('Banco Guayaquil', $node->field_banco_destino[LANGUAGE_NONE][0]['value']);
  }

  /**
   * A NULL bank is not stored as an empty string: the field stays absent.
   */
  public function testAnAbsentBankLeavesItsFieldUnset() {
    $node = $this->decorated(NULL, NULL, NULL);

    $this->assertFalse(property_exists($node, 'field_banco_emisor'));
    $this->assertFalse(property_exists($node, 'field_banco_destino'));
  }

  /**
   * THE EVIDENCE IS THE PAYLOAD WHOLE.
   *
   * Not a selection: a selection made by today's criterion does not answer the
   * question asked in six months. What has to survive in there is everything
   * the backend deliberately never reads — the confidences, what the bot
   * corrected, the model that read it — plus the wamid that caused the
   * payment.
   */
  public function testTheEvidenceKeepsThePayloadWhole() {
    $payload = $this->payload();
    $node = $this->decorated($payload);

    $stored = json_decode(
      html_entity_decode($node->field_comprobante_ocr[LANGUAGE_NONE][0]['value'], ENT_QUOTES, 'UTF-8'),
      TRUE
    );

    $this->assertSame(['stored_at', 'payload'], array_keys($stored));
    $this->assertSame($payload, $stored['payload'], 'byte for byte, the reading as it arrived');
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $stored['stored_at']);

    // Named one by one, because these are exactly the keys a future "let us
    // only store what we use" refactor would drop.
    $this->assertSame(0.97, $stored['payload']['receipt']['confidence']['amount']);
    $this->assertSame(['date'], $stored['payload']['receipt']['corrected']);
    $this->assertSame('some-vision-model', $stored['payload']['receipt']['model']);
    $this->assertSame('wamid.HBgMNTkzOTg3NTM1NjQ1FQIAEhgU', $stored['payload']['message']['message_key']);
    $this->assertSame('0987535645', $stored['payload']['message']['sender']['local_phone']);
  }

  /**
   * An unknown key reaches the evidence untouched, which is the other half of
   * "a new field must not tip the endpoint over".
   */
  public function testAnUnknownKeyReachesTheEvidence() {
    $payload = $this->payload(['receipt' => ['tomorrows_field' => 'whatever']]);
    $node = $this->decorated($payload);

    $stored = json_decode(
      html_entity_decode($node->field_comprobante_ocr[LANGUAGE_NONE][0]['value'], ENT_QUOTES, 'UTF-8'),
      TRUE
    );

    $this->assertSame('whatever', $stored['payload']['receipt']['tomorrows_field']);
  }

  /**
   * Decorating changes nothing the app's builder decided: same title, same
   * forced estado, same uid, same unit.
   */
  public function testDecoratingLeavesTheBaseNodeAlone() {
    $node = $this->decorated();

    $this->assertSame('pagos', $node->type);
    $this->assertSame(3, $node->uid);
    $this->assertSame(1, $node->status);
    $this->assertSame('Pago 018273645 - 2026-09-12', $node->title);
    $this->assertSame('Pendiente de verificar', $node->field_estado_pago[LANGUAGE_NONE][0]['value']);
    $this->assertSame('Transferencia', $node->field_forma_de_pago[LANGUAGE_NONE][0]['value']);
    $this->assertSame(45, $node->field_vivienda[LANGUAGE_NONE][0]['target_id']);
  }

  /* -------------------------------------------------------------------------
   * The dispatcher and the credential.
   * ---------------------------------------------------------------------- */

  /**
   * Only POST. The endpoint creates; reading, listing and voiding a payment
   * are the app's endpoints, which do not distinguish the channel.
   */
  public function testEveryOtherMethodIs405() {
    foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = myapi_test_capture(function () {
        myapi_bot_payment_dispatch();
      });

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
    }
  }

  /**
   * The method is checked BEFORE the credential, so a GET with no key answers
   * 405 and not 401 — the same order the other two bot endpoints use.
   */
  public function testTheMethodIsCheckedBeforeTheCredential() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_X_API_KEY']);

    $result = myapi_test_capture(function () {
      myapi_bot_payment_dispatch();
    });

    $this->assertSame(405, $result['status']);
  }

  /**
   * No key, an empty key and a wrong key are all 401 — and the payload is
   * never even looked at, which is what keeps a leaked-key sweep bounded.
   */
  public function testAMissingOrWrongKeyIs401() {
    $_POST = ['payload' => json_encode($this->payload())];

    foreach ([NULL, '', 'not-the-key'] as $key) {
      if ($key === NULL) {
        unset($_SERVER['HTTP_X_API_KEY']);
      }
      else {
        $_SERVER['HTTP_X_API_KEY'] = $key;
      }

      $result = myapi_test_capture(function () {
        myapi_bot_payment_create();
      });

      $this->assertSame(401, $result['status'], var_export($key, TRUE));
      $this->assertSame('unauthorized', $result['json']['error_code'], var_export($key, TRUE));
    }
  }

  /**
   * With myapi_bot_api_key unset, EVERY request is 401 — including one that
   * sends no key at all, which would otherwise compare '' with '' and pass.
   */
  public function testAnUnconfiguredKeyRefusesEveryRequest() {
    $GLOBALS['myapi_test_variables'] = [];

    foreach ([NULL, '', self::API_KEY] as $key) {
      if ($key === NULL) {
        unset($_SERVER['HTTP_X_API_KEY']);
      }
      else {
        $_SERVER['HTTP_X_API_KEY'] = $key;
      }

      $result = myapi_test_capture(function () {
        myapi_bot_payment_create();
      });

      $this->assertSame(401, $result['status'], var_export($key, TRUE));
    }
  }

  /**
   * The credential is the SAME variable the other two bot endpoints read.
   * There is no second key to rotate, and this is the assertion that keeps it
   * that way.
   */
  public function testItIsTheSameKeyAsTheOtherTwoBotEndpoints() {
    $source = file_get_contents(dirname(__DIR__, 2) . '/includes/myapi.bot_auth.inc');

    $this->assertStringContainsString("variable_get('myapi_bot_api_key'", $source);
    $this->assertSame(
      1,
      preg_match_all("/variable_get\('myapi_bot_api_key'/", $source),
      'one variable, read in one place'
    );
  }

  /* -------------------------------------------------------------------------
   * The schema guard and the ledger, asserted statically.
   *
   * The rows themselves need a live site (the spec marks them 🔴). What does
   * NOT need one is that the table is declared the way the idempotency
   * mechanism requires — and those two properties are exactly the ones a
   * plausible "simplification" would remove.
   * ---------------------------------------------------------------------- */

  private function installSource() {
    return file_get_contents(dirname(__DIR__, 2) . '/myapi.install');
  }

  /**
   * idempotency_key is the PRIMARY KEY, not a column with an index.
   *
   * This is the whole race protection: uniqueness is imposed by the database,
   * so two simultaneous retries cannot both pass a SELECT and both insert.
   */
  public function testTheLedgerKeyIsThePrimaryKey() {
    $source = $this->installSource();

    $this->assertStringContainsString("\$schema['myapi_bot_payments']", $source);
    $this->assertStringContainsString("'primary key' => ['idempotency_key']", $source);
  }

  /**
   * Both halves are stored in the clear beside the hash.
   *
   * The hash gives uniqueness, not auditability: an operator asking "which
   * payments came in through this WhatsApp message?" has to be able to read
   * the wamid, and the index on message_key is what makes that query cheap.
   */
  public function testTheLedgerKeepsBothHalvesInTheClear() {
    $source = $this->installSource();

    $this->assertStringContainsString("'message_key' => [", $source);
    $this->assertStringContainsString("'media_ref' => [", $source);
    $this->assertStringContainsString("'message_key' => ['message_key']", $source);
  }

  /**
   * The width of the hash column matches what hash('sha256') produces, and the
   * width of message_key matches what myapi_bot_payment_validate() lets
   * through. A mismatch either truncates a wamid or throws inside the
   * transaction.
   */
  public function testTheLedgerColumnsMatchWhatIsWrittenToThem() {
    $source = $this->installSource();

    $this->assertSame(64, strlen(myapi_bot_payment_idempotency_key('a', 'b')));
    $this->assertStringContainsString("'type'        => 'char',", $source);
    $this->assertStringContainsString("'length'      => 64,", $source);
    $this->assertStringContainsString("'length'      => 255,", $source);
  }

  /**
   * The table is created for sites that already run the module, not only for
   * fresh installs — and under its own update number.
   */
  public function testTheLedgerHasItsOwnUpdateHook() {
    $source = $this->installSource();

    $this->assertStringContainsString('function myapi_update_7048()', $source);
    $this->assertStringContainsString("db_table_exists('myapi_bot_payments')", $source);
    $this->assertStringContainsString("db_create_table('myapi_bot_payments'", $source);
  }
}
