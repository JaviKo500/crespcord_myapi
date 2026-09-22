<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.unit_query.inc';
require_once __DIR__ . '/../../includes/myapi.bot_auth.inc';
require_once __DIR__ . '/../../resources/bot.resource.inc';

/**
 * Unit tests for GET /api/v1/bot/person (SPEC 127).
 *
 * myapi_bot_person_dispatch() is called the way hook_menu() calls it, over
 * fixture rows and a fixture X-Api-Key header, and what gets asserted is the
 * JSON body the module prints and the status it sets — the same bytes n8n
 * receives.
 *
 * Most of the weight is on the two pure functions. Whether this endpoint finds
 * a person comes down to one definition —
 *
 *   normalize(v) = substr(preg_replace('/\D/', '', v), -9)
 *
 * — applied to BOTH the number in the query string and the number in the
 * field, and to a LIKE pattern loose enough never to hide a row that
 * normalisation would have accepted. Those are testable without a database and
 * they are where the bugs live: a format nobody thought of is a person the bot
 * cannot identify, and the failure is silent — `found: false` looks exactly
 * like a number that is not registered.
 *
 * The end-to-end cases cover what the resolution rules decide: who is dropped
 * (blocked accounts, unpublished units, unpublished condominiums), when
 * answering is refused (ambiguity), and what never travels (the balance, the
 * payment information, the phone itself).
 *
 * What this file does NOT prove is the database's half. The fixture builder
 * records joins without resolving them (see the SPEC 74 disclaimer in
 * bootstrap.php), so "users.status = 0 hides the row" is asserted here as the
 * condition the query carries plus the fixture that models it — not as
 * something MySQL did. Nor does it say anything about how phone numbers are
 * REALLY stored in production: that is the open risk of the spec, and it is
 * answered with a census over the real table, not from here.
 */
class BotPersonTest extends TestCase {

  /**
   * The key every fixture request sends, and the one the variable holds.
   */
  const API_KEY = 'a-shared-secret-for-the-bot';

  /**
   * The nine digits every fixture number normalises to.
   */
  const DIGITS = '987535645';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_variables'] = ['myapi_bot_api_key' => self::API_KEY];
    $GLOBALS['myapi_test_watchdog'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_X_API_KEY'] = self::API_KEY;
    $_GET = ['q' => 'api/v1/bot/person'];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_variables'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_X_API_KEY']);
    $_GET = [];
  }

  /**
   * A field_data_field_telefono row.
   *
   * It carries users.status and users.uid alongside its own columns because
   * the fixture builder records joins without resolving them: the row a test
   * seeds is the row the INNER JOIN would have produced.
   */
  private function phoneRow($uid, $value, $status = 1, $delta = 0) {
    return [
      'entity_id'            => (string) $uid,
      'entity_type'          => 'user',
      'deleted'              => '0',
      'delta'                => (string) $delta,
      'field_telefono_value' => $value,
      'uid'                  => (string) $uid,
      'status'               => (string) $status,
    ];
  }

  /**
   * A published 'vivienda' node row, with the columns its LEFT JOINs project.
   */
  private function unitRow($nid, $name, $condominium_nid, array $overrides = []) {
    return $overrides + [
      'nid'            => (string) $nid,
      'type'           => 'vivienda',
      'status'         => '1',
      'title'          => NULL,
      'name'           => $name,
      'category'       => NULL,
      'area_m2'        => NULL,
      'condominio_nid' => $condominium_nid === NULL ? NULL : (string) $condominium_nid,
      'owner_uid'      => NULL,
      'saldo_actual'   => '1234.56',
    ];
  }

  /**
   * A published 'condominio' node row.
   */
  private function condominiumRow($nid, $title, array $overrides = []) {
    return $overrides + [
      'nid'                 => (string) $nid,
      'type'                => 'condominio',
      'status'              => '1',
      'title'               => $title,
      'payment_information' => 'Cuenta corriente 00-1234567-8',
    ];
  }

  /**
   * A users row with the name columns myapi_user_display_names() joins.
   */
  private function userRow($uid, $first, $last, $name = 'jperez') {
    return [
      'uid'        => (string) $uid,
      'name'       => $name,
      'status'     => '1',
      'first_name' => $first,
      'last_name'  => $last,
    ];
  }

  /**
   * The fixture the happy path runs on: one person, one owned unit, one
   * occupied unit, both in the same published condominium.
   */
  private function seedTwoUnits(array $overrides = []) {
    myapi_test_db_seed($overrides + [
      'field_data_field_telefono'    => [$this->phoneRow(7, '+593 98 753 5645')],
      'users'                        => [$this->userRow(7, 'Juan', 'Pérez')],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_propietario_target_id' => '7'],
      ],
      'field_data_field_ocupantes'   => [
        ['entity_id' => '46', 'entity_type' => 'node', 'deleted' => '0', 'field_ocupantes_target_id' => '7'],
      ],
      'node' => [
        $this->unitRow(45, 'Dpto 3B', 12),
        $this->unitRow(46, 'Local 2', 12),
        $this->condominiumRow(12, 'Torre Azul'),
      ],
    ]);
  }

  /**
   * Calls the dispatcher the way the router does and captures the response.
   */
  private function request($phone = '0987535645', $method = 'GET') {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET['q'] = 'api/v1/bot/person';
    if ($phone === NULL) {
      unset($_GET['phone']);
    }
    else {
      $_GET['phone'] = $phone;
    }

    return myapi_test_capture(function () {
      myapi_bot_person_dispatch();
    });
  }

  // ---------------------------------------------------------------------
  // The normalisation, which is the whole identity rule.
  // ---------------------------------------------------------------------

  /**
   * Every way a person writes the same number reduces to the same digits.
   *
   * The four formats of the spec plus the two the census is likeliest to turn
   * up: a trailing space and a number with a note after it. Both normalise to
   * the same nine digits, which is why the LIKE pattern below has to have a
   * wildcard at its END as well — a pre-filter that anchored on the last digit
   * would drop exactly these two while normalisation accepted them.
   *
   * @dataProvider equivalentPhoneFormats
   */
  public function testEveryWrittenFormOfTheNumberNormalisesToTheSameDigits($written) {
    $this->assertSame(self::DIGITS, myapi_bot_normalize_phone($written), $written);
  }

  public function equivalentPhoneFormats() {
    return [
      'plain, national with trunk 0' => ['0987535645'],
      'E.164'                        => ['+593987535645'],
      'country code with spaces'     => ['+593 98 753 5645'],
      'parentheses and dash'         => ['(098) 753-5645'],
      'dashes'                       => ['098-753-5645'],
      'dots'                         => ['098.753.5645'],
      'non breaking spaces'          => ["098\xC2\xA0753\xC2\xA05645"],
      'trailing space'               => ['0987535645 '],
      'leading space'                => [' 0987535645'],
      'with a note after it'         => ['0987535645 (casa)'],
      'already normalised'           => ['987535645'],
    ];
  }

  /**
   * A number with fewer than nine digits comes back short, not padded.
   *
   * That is what the handler checks to answer invalid_phone, so the shortness
   * has to survive normalisation rather than be hidden by it.
   */
  public function testAShortNumberStaysShort() {
    $this->assertSame('0987535', myapi_bot_normalize_phone('0987535'));
    $this->assertSame('555', myapi_bot_normalize_phone('555'));
    $this->assertSame('', myapi_bot_normalize_phone(''));
    $this->assertSame('', myapi_bot_normalize_phone('no digits here'));
  }

  /**
   * Only the LAST nine digits count.
   *
   * The country code is dropped from the front, not matched: '+57' and '+593'
   * in front of the same national number are the same person to this endpoint
   * (an accepted risk of the spec — the fail-safe is `ambiguous`, never a
   * payment charged to the wrong unit).
   */
  public function testOnlyTheLastNineDigitsCount() {
    $this->assertSame(self::DIGITS, myapi_bot_normalize_phone('+57 98 753 5645'));
    $this->assertSame(self::DIGITS, myapi_bot_normalize_phone('00593987535645'));
  }

  // ---------------------------------------------------------------------
  // The SQL pre-filter.
  // ---------------------------------------------------------------------

  /**
   * The pattern is the digits in order, with a wildcard everywhere between and
   * around them.
   */
  public function testTheLikePatternWildcardsBetweenEveryDigitAndAtBothEnds() {
    $this->assertSame('%9%8%7%5%3%5%6%4%5%', myapi_bot_phone_like_pattern(self::DIGITS));
  }

  /**
   * And it really matches every stored format, which is the property the
   * pattern exists for: the pre-filter must never hide a row that
   * normalisation would have accepted.
   *
   * Asserted against the same fold-aware LIKE the fixture builder applies, so
   * this is the pattern semantics, not a second implementation of it.
   *
   * @dataProvider equivalentPhoneFormats
   */
  public function testTheLikePatternMatchesEveryStoredFormat($stored) {
    myapi_test_db_seed(['field_data_field_telefono' => [$this->phoneRow(7, $stored)]]);

    $this->assertSame([7], myapi_bot_find_uids_by_phone(self::DIGITS), $stored);
  }

  /**
   * What the pattern lets through, normalisation throws out.
   *
   * '0987535645 9' contains the nine digits in order, so the LIKE matches it —
   * and its last nine digits are a different number. The exact comparison in
   * PHP is the one that decides.
   */
  public function testALikeMatchThatIsNotTheSameNumberIsDropped() {
    myapi_test_db_seed(['field_data_field_telefono' => [$this->phoneRow(7, '0987535645 9')]]);

    $this->assertSame([], myapi_bot_find_uids_by_phone(self::DIGITS));
  }

  /**
   * The query carries the conditions the row set depends on.
   *
   * The fixture builder records joins without resolving them, so this is how
   * "only user fields, only live rows, only active accounts" is asserted from
   * a unit test: by reading the query that was built.
   */
  public function testTheLookupQueryFiltersByEntityTypeDeletedAndUserStatus() {
    myapi_test_db_seed(['field_data_field_telefono' => []]);
    myapi_bot_find_uids_by_phone(self::DIGITS);

    $queries = myapi_test_db_queries('field_data_field_telefono');
    $this->assertCount(1, $queries, 'one query, not one per candidate row');

    $conditions = [];
    foreach ($queries[0]['conditions'] as $condition) {
      $conditions[$condition['field']] = $condition;
    }

    $this->assertSame('user', $conditions['p.entity_type']['value']);
    $this->assertSame(0, $conditions['p.deleted']['value']);
    $this->assertSame(1, $conditions['u.status']['value']);
    $this->assertSame('LIKE', $conditions['p.field_telefono_value']['operator']);
    $this->assertSame('%9%8%7%5%3%5%6%4%5%', $conditions['p.field_telefono_value']['value']);

    $joined = [];
    foreach ($queries[0]['joins'] as $join) {
      $joined[$join['table']] = $join['type'];
    }
    $this->assertSame('INNER', $joined['users'], 'a user without a users row must not match');
  }

  /**
   * field_telefono is multi-value: any of a person's rows matching is a match,
   * and the person is answered once.
   */
  public function testASecondPhoneOnTheSamePersonMatchesAndDoesNotDuplicateHer() {
    myapi_test_db_seed([
      'field_data_field_telefono' => [
        $this->phoneRow(7, '022345678', 1, 0),
        $this->phoneRow(7, '0987535645', 1, 1),
      ],
    ]);

    $this->assertSame([7], myapi_bot_find_uids_by_phone(self::DIGITS));
  }

  // ---------------------------------------------------------------------
  // Authentication.
  // ---------------------------------------------------------------------

  /**
   * No header, no answer — and nothing was read on the way out.
   */
  public function testWithoutTheApiKeyItIs401AndReadsNothing() {
    $this->seedTwoUnits();
    unset($_SERVER['HTTP_X_API_KEY']);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertFalse($result['json']['success']);
    $this->assertSame('unauthorized', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries(), 'queried a table before authenticating');
  }

  /**
   * A wrong key is the same 401, and it leaves a WARNING naming the path and
   * the caller.
   *
   * The log is the whole mitigation the spec keeps against the real risk on
   * this endpoint, which is not brute force but the key leaking: an attempt to
   * use it from somewhere unexpected has to be visible in dblog.
   */
  public function testAWrongApiKeyIs401AndIsLogged() {
    $this->seedTwoUnits();
    $_SERVER['HTTP_X_API_KEY'] = 'not-the-configured-key';

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('unauthorized', $result['json']['error_code']);

    $this->assertCount(1, $GLOBALS['myapi_test_watchdog']);
    $entry = $GLOBALS['myapi_test_watchdog'][0];
    $this->assertSame('myapi', $entry['type']);
    $this->assertSame(WATCHDOG_WARNING, $entry['severity']);
    $this->assertStringContainsString('api/v1/bot/person', $entry['text']);
    $this->assertStringContainsString(ip_address(), $entry['text']);
    $this->assertStringNotContainsString(self::API_KEY, $entry['text'], 'the key itself must never reach the log');
  }

  /**
   * An empty header is a missing header.
   */
  public function testAnEmptyApiKeyHeaderIs401() {
    $this->seedTwoUnits();
    $_SERVER['HTTP_X_API_KEY'] = '';

    $this->assertSame(401, $this->request()['status']);
  }

  /**
   * And with the variable unset — the shipped state of a site nobody
   * configured — everything is 401, including a request that sends an empty
   * header. Without the emptiness checks, '' would be compared against '' and
   * the endpoint would be open.
   */
  public function testWithNoKeyConfiguredEverythingIs401() {
    $this->seedTwoUnits();
    $GLOBALS['myapi_test_variables'] = [];

    foreach ([self::API_KEY, '', 'anything'] as $sent) {
      $_SERVER['HTTP_X_API_KEY'] = $sent;
      $result = $this->request();

      $this->assertSame(401, $result['status'], 'sent: ' . var_export($sent, TRUE));
      $this->assertSame('unauthorized', $result['json']['error_code']);
    }
  }

  // ---------------------------------------------------------------------
  // Method and parameter.
  // ---------------------------------------------------------------------

  /**
   * Everything that is not GET is 405 — checked before the credential, so a
   * caller using the wrong verb is told so rather than told nothing.
   *
   * @dataProvider refusedMethods
   */
  public function testEveryMethodOtherThanGetIs405($method) {
    $this->seedTwoUnits();
    unset($_SERVER['HTTP_X_API_KEY']);

    $result = $this->request('0987535645', $method);

    $this->assertSame(405, $result['status'], $method);
    $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
  }

  public function refusedMethods() {
    return [['POST'], ['PUT'], ['PATCH'], ['DELETE'], ['HEAD'], ['OPTIONS']];
  }

  /**
   * No phone parameter, or an empty one, is a 422 — not a `found: false`.
   *
   * An empty parameter is a bug in the n8n flow. Answering "unknown number"
   * would hide it behind a WhatsApp conversation with somebody who was
   * registered all along.
   *
   * @dataProvider missingPhoneValues
   */
  public function testAMissingPhoneParameterIs422($phone) {
    $this->seedTwoUnits();

    $result = $this->request($phone);

    $this->assertSame(422, $result['status']);
    $this->assertFalse($result['json']['success']);
    $this->assertSame('missing_phone', $result['json']['error_code']);
  }

  public function missingPhoneValues() {
    return [
      'absent'      => [NULL],
      'empty'       => [''],
      'spaces only' => ['   '],
    ];
  }

  /**
   * Fewer than nine digits is a different 422: the parameter arrived, it is
   * just not a phone number.
   *
   * @dataProvider invalidPhoneValues
   */
  public function testAPhoneWithTooFewDigitsIs422($phone) {
    $this->seedTwoUnits();

    $result = $this->request($phone);

    $this->assertSame(422, $result['status'], $phone);
    $this->assertSame('invalid_phone', $result['json']['error_code'], $phone);
  }

  public function invalidPhoneValues() {
    return [
      'three digits'   => ['555'],
      'eight digits'   => ['09875356'],
      'no digits'      => ['not a phone'],
      'separators only' => ['+ - ( )'],
    ];
  }

  // ---------------------------------------------------------------------
  // Resolution.
  // ---------------------------------------------------------------------

  /**
   * The happy path: the person, and one entry per unit with its own relation.
   */
  public function testAPersonIsAnsweredWithHerUnitsAndTheirRelations() {
    $this->seedTwoUnits();

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);

    $data = $result['json']['data'];
    $this->assertTrue($data['found']);
    $this->assertSame(7, $data['person']['uid']);
    $this->assertSame('Juan Pérez', $data['person']['name']);

    $this->assertCount(2, $data['units']);
    $this->assertSame(
      [
        ['unit_id' => 45, 'unit' => 'Dpto 3B', 'condominium_id' => 12, 'condominium' => 'Torre Azul', 'condominium_payment_info' => 'Cuenta corriente 00-1234567-8', 'relation' => 'owner'],
        ['unit_id' => 46, 'unit' => 'Local 2', 'condominium_id' => 12, 'condominium' => 'Torre Azul', 'condominium_payment_info' => 'Cuenta corriente 00-1234567-8', 'relation' => 'occupant'],
      ],
      $data['units']
    );
  }

  /**
   * The same person found through any of the stored formats, with the same
   * query — the other direction of the normalisation cases above, end to end.
   *
   * @dataProvider equivalentPhoneFormats
   */
  public function testThePersonIsFoundWhicheverFormatIsStored($stored) {
    $this->seedTwoUnits(['field_data_field_telefono' => [$this->phoneRow(7, $stored)]]);

    $data = $this->request()['json']['data'];

    $this->assertTrue($data['found'], 'stored as ' . $stored);
    $this->assertSame(7, $data['person']['uid']);
  }

  /**
   * And found whichever format the bot asks with.
   *
   * @dataProvider equivalentPhoneFormats
   */
  public function testThePersonIsFoundWhicheverFormatIsAsked($asked) {
    $this->seedTwoUnits();

    $data = $this->request($asked)['json']['data'];

    $this->assertTrue($data['found'], 'asked with ' . $asked);
    $this->assertSame(7, $data['person']['uid']);
  }

  /**
   * Units in two condominiums come back with their own condominium each.
   */
  public function testUnitsInTwoCondominiumsKeepTheirOwn() {
    $this->seedTwoUnits([
      'node' => [
        $this->unitRow(45, 'Dpto 3B', 12),
        $this->unitRow(46, 'Local 2', 30),
        $this->condominiumRow(12, 'Torre Azul'),
        $this->condominiumRow(30, 'Villa Sol'),
      ],
    ]);

    $units = $this->request()['json']['data']['units'];

    $this->assertSame([12, 30], array_column($units, 'condominium_id'));
    $this->assertSame(['Torre Azul', 'Villa Sol'], array_column($units, 'condominium'));
  }

  /**
   * Owner wins when the same person is both owner and occupant of one unit.
   */
  public function testOwnerWinsOverOccupantOnTheSameUnit() {
    $this->seedTwoUnits([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_ocupantes_target_id' => '7'],
      ],
      'node' => [
        $this->unitRow(45, 'Dpto 3B', 12),
        $this->condominiumRow(12, 'Torre Azul'),
      ],
    ]);

    $units = $this->request()['json']['data']['units'];

    $this->assertCount(1, $units, 'the unit is listed once, not once per relation');
    $this->assertSame('owner', $units[0]['relation']);
  }

  /**
   * Nobody has the number.
   */
  public function testAnUnknownNumberIsNotFound() {
    $this->seedTwoUnits();

    $result = $this->request('0999999999');

    $this->assertSame(200, $result['status'], 'never a 404: that reads like a mistyped URL');
    $this->assertSame(
      ['found' => FALSE, 'person' => NULL, 'units' => [], 'reason' => 'not_found'],
      $result['json']['data']
    );
  }

  /**
   * A blocked account is not_found, not no_units and not found — a reason of
   * its own would confirm to whoever is asking that the person exists.
   */
  public function testABlockedAccountIsNotFound() {
    $this->seedTwoUnits(['field_data_field_telefono' => [$this->phoneRow(7, '0987535645', 0)]]);

    $data = $this->request()['json']['data'];

    $this->assertFalse($data['found']);
    $this->assertSame('not_found', $data['reason']);
  }

  /**
   * Two active people on the same last nine digits: answer nothing.
   *
   * Picking the first uid here is a payment charged to the wrong unit, and the
   * conversational flow in n8n already knows how to ask for the building and
   * the unit over WhatsApp.
   */
  public function testTwoPeopleOnTheSameNumberAreAmbiguous() {
    $this->seedTwoUnits([
      'field_data_field_telefono' => [
        $this->phoneRow(7, '0987535645'),
        $this->phoneRow(8, '+593 98 753 5645'),
      ],
    ]);

    $data = $this->request()['json']['data'];

    $this->assertFalse($data['found']);
    $this->assertSame('ambiguous', $data['reason']);
    $this->assertNull($data['person'], 'neither candidate may be named');
    $this->assertSame([], $data['units']);
  }

  /**
   * Identified, but with nowhere to charge the payment.
   */
  public function testAPersonWithNoUnitsIsNoUnits() {
    $this->seedTwoUnits([
      'field_data_field_propietario' => [],
      'field_data_field_ocupantes'   => [],
    ]);

    $data = $this->request()['json']['data'];

    $this->assertFalse($data['found']);
    $this->assertSame('no_units', $data['reason']);
    $this->assertNull($data['person'], 'the uid is of no use to the bot and is personal data');
    $this->assertSame([], $data['units']);
  }

  /**
   * An unpublished unit is not a unit.
   */
  public function testAnUnpublishedUnitIsDropped() {
    $this->seedTwoUnits([
      'node' => [
        $this->unitRow(45, 'Dpto 3B', 12, ['status' => '0']),
        $this->condominiumRow(12, 'Torre Azul'),
      ],
      'field_data_field_ocupantes' => [],
    ]);

    $this->assertSame('no_units', $this->request()['json']['data']['reason']);
  }

  /**
   * And neither is a published unit hanging off an unpublished condominium —
   * the SPEC 08 rule, unchanged.
   */
  public function testAUnitWhoseCondominiumIsUnpublishedIsDropped() {
    $this->seedTwoUnits([
      'node' => [
        $this->unitRow(45, 'Dpto 3B', 12),
        $this->condominiumRow(12, 'Torre Azul', ['status' => '0']),
      ],
      'field_data_field_ocupantes' => [],
    ]);

    $this->assertSame('no_units', $this->request()['json']['data']['reason']);
  }

  // ---------------------------------------------------------------------
  // The response contract.
  // ---------------------------------------------------------------------

  /**
   * Nothing financial of the PERSON and nothing personal beyond the name
   * leaves Drupal.
   *
   * The balance is within reach of the query this endpoint already runs —
   * saldo_actual is selected by myapi_unit_fetch_units() — which is exactly
   * why the absence is asserted over the raw body instead of trusted to the
   * shape of the array.
   *
   * The condominium's payment information is the one thing on this list that
   * changed sides: it now travels, as condominium_payment_info, because the
   * bot reads it back over WhatsApp so the resident knows where to transfer.
   * It describes the building's bank account, not the resident, and it is
   * asserted present here so that removing it again breaks a test rather than
   * a conversation.
   */
  public function testTheResponseCarriesNoBalanceNoPhoneAndOnlyTheDisplayName() {
    $this->seedTwoUnits();

    $body = $this->request()['output'];

    $this->assertStringNotContainsString('1234.56', $body, 'the balance travelled');
    $this->assertStringNotContainsString('current_balance', $body);
    $this->assertStringNotContainsString('753', $body, 'the phone number travelled back');
    $this->assertStringNotContainsString('jperez', $body, 'the account name is not the display name');

    $this->assertStringContainsString('condominium_payment_info', $body);
    $this->assertStringContainsString('00-1234567-8', $body);
  }

  /**
   * The envelope has the same shape whether somebody was found or not, so n8n
   * needs one branch and not two.
   */
  public function testTheEnvelopeHasTheSameShapeFoundOrNot() {
    $this->seedTwoUnits();
    $found = $this->request()['json'];
    $missing = $this->request('0999999999')['json'];

    foreach ([$found, $missing] as $envelope) {
      $this->assertTrue($envelope['success']);
      $this->assertArrayHasKey('found', $envelope['data']);
      $this->assertArrayHasKey('person', $envelope['data']);
      $this->assertArrayHasKey('units', $envelope['data']);
      $this->assertIsArray($envelope['data']['units']);
    }

    $this->assertArrayNotHasKey('reason', $found['data'], 'reason only describes a non-answer');
    $this->assertSame('not_found', $missing['data']['reason']);
  }

  /**
   * Every key of the JSON is in English (CLAUDE.md), including the ones this
   * resource invents rather than reuses.
   */
  public function testEveryResponseKeyIsInEnglish() {
    $this->seedTwoUnits();
    $data = $this->request()['json']['data'];

    $this->assertSame(['found', 'person', 'units'], array_keys($data));
    $this->assertSame(['uid', 'name'], array_keys($data['person']));
    $this->assertSame(
      ['unit_id', 'unit', 'condominium_id', 'condominium', 'condominium_payment_info', 'relation'],
      array_keys($data['units'][0])
    );
  }

  /**
   * The display name falls back to users.name when either half is missing —
   * behaviour of myapi_user_display_names(), asserted here because it is what
   * the bot shows the resident over WhatsApp.
   */
  public function testTheDisplayNameFallsBackToTheAccountName() {
    $this->seedTwoUnits(['users' => [$this->userRow(7, 'Juan', NULL, 'jperez')]]);

    $this->assertSame('jperez', $this->request()['json']['data']['person']['name']);
  }

}
