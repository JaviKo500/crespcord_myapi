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
require_once __DIR__ . '/../../includes/myapi.service_offer_query.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../resources/service_offer.resource.inc';

/**
 * Unit tests for GET /api/v1/service-offers/provider (SPEC 102).
 *
 * THE ITEM IS THE CONTRACT, and it is the half of this endpoint that is pure:
 * myapi_service_offer_provider_build_item() takes a row and a provider and
 * answers eight keys, with no database and no site booted. What is asserted
 * here is that shape and, above all, that SIX OF THE EIGHT ARE NOT WRITTEN
 * TWICE — they are taken from myapi_service_offer_build(), the fifteen-key
 * serialiser `offers` and `my_offers` already answer with, so the two listings
 * cannot disagree about what an amount or a date looks like. That is asserted
 * by COMPARING the two over the same row, key by key, which is a test that
 * fails the day somebody deletes the call and reimplements it.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: joins are
 * recorded and never resolved, so an offer is seeded flat — its own node
 * columns plus the value each JOIN would have brought, under the alias the
 * query gives it.
 */
class ServiceOfferProviderListTest extends TestCase {

  /**
   * The eight keys of the item, in the order the spec fixes them. Written out
   * as a literal on purpose: reading them off the function under test would
   * make the assertion agree with itself.
   */
  const ITEM_KEYS = [
    'id',
    'status',
    'amount',
    'amount_type',
    'created',
    'valid_until',
    'provider',
    'request',
  ];

  /**
   * The six keys this item SHARES with myapi_service_offer_build(), and which
   * it is forbidden from rewriting.
   */
  const SHARED_KEYS = ['id', 'status', 'amount', 'amount_type', 'created', 'valid_until'];

  /**
   * The eight keys of the fifteen-key serialiser this item must NOT carry.
   */
  const DROPPED_KEYS = [
    'message',
    'includes',
    'excludes',
    'duration',
    'tax_included',
    'warranty_days',
    'requires_visit',
    'available_from',
  ];

  const OFFER_NID = 901;
  const REQUEST_NID = 128;
  const PROVIDER_NID = 41;
  const CATEGORY_TID = 12;
  const CREATED = 1755000000;
  const VALID_UNTIL = 1756771199;

  /**
   * The provider, as myapi_service_offer_provider_scope() answers it.
   */
  private function provider() {
    return ['id' => self::PROVIDER_NID, 'name' => 'Plomería Torres'];
  }

  /**
   * A complete row of myapi_service_offer_provider_fetch().
   */
  private function row(array $overrides = []) {
    return (object) ($overrides + [
      'nid'            => self::OFFER_NID,
      'created'        => self::CREATED,
      'status'         => 'sent',
      'amount'         => '95.50',
      'amount_type'    => 'fixed',
      'valid_until'    => self::VALID_UNTIL,
      'request_id'     => self::REQUEST_NID,
      'request_title'  => 'Fuga en el calentador',
      'request_status' => 'assigned',
      'category_id'    => self::CATEGORY_TID,
      'category_name'  => 'Plomería',
      'category_code'  => 'plumbing',
    ]);
  }

  /* -------------------------------------------------------------------------
   * The eight keys, and their order.
   * ---------------------------------------------------------------------- */

  public function testItemCarriesExactlyTheEightKeysInOrder() {
    $item = myapi_service_offer_provider_build_item($this->row(), $this->provider());

    $this->assertSame(self::ITEM_KEYS, array_keys($item), 'the eight keys, in the order the spec fixes');
  }

  public function testItemKeepsTheEightKeysOnAnEmptyRow() {
    // A row carrying nothing but its nid — the corrupt case. The shape is the
    // contract whatever the data, so no key may disappear.
    $item = myapi_service_offer_provider_build_item((object) ['nid' => self::OFFER_NID], $this->provider());

    $this->assertSame(self::ITEM_KEYS, array_keys($item));
    $this->assertSame(self::OFFER_NID, $item['id']);
    $this->assertNull($item['status'], 'a corrupt row answers a null status, and keeps its place');
    $this->assertNull($item['amount']);
    $this->assertNull($item['amount_type']);
    $this->assertNull($item['valid_until']);
  }

  public function testItemCarriesNoneOfTheEightLongKeys() {
    $item = myapi_service_offer_provider_build_item($this->row(), $this->provider());

    foreach (self::DROPPED_KEYS as $key) {
      $this->assertArrayNotHasKey($key, $item, $key . ' belongs to the detail, not to this listing');
    }
    // Nor the request's, which is a label and never half a detail.
    foreach (['description', 'unit', 'offers_count', 'assigned_provider', 'requester', 'condominium'] as $key) {
      $this->assertArrayNotHasKey($key, $item['request'], 'request.' . $key . ' must not travel');
    }
  }

  /* -------------------------------------------------------------------------
   * The six shared keys: taken, never rewritten.
   * ---------------------------------------------------------------------- */

  public function testTheSixSharedKeysAreIdenticalToTheFifteenKeySerialiser() {
    $row = $this->row();

    $item = myapi_service_offer_provider_build_item($row, $this->provider());
    $full = myapi_service_offer_build($row);

    foreach (self::SHARED_KEYS as $key) {
      $this->assertSame($full[$key], $item[$key], $key . ' must be the same value the detail answers');
    }
  }

  public function testTheSixSharedKeysStayIdenticalOnAPreSpec100Offer() {
    // No amount_type, no valid_until — nothing backfilled them and nothing
    // will. The two serialisers have to agree on that absence too.
    $row = $this->row(['amount_type' => NULL, 'valid_until' => NULL, 'amount' => NULL]);

    $item = myapi_service_offer_provider_build_item($row, $this->provider());
    $full = myapi_service_offer_build($row);

    foreach (self::SHARED_KEYS as $key) {
      $this->assertSame($full[$key], $item[$key]);
    }
    $this->assertNull($item['amount_type'], 'an offer older than SPEC 100 answers null, and appears in the list');
    $this->assertNull($item['valid_until']);
  }

  public function testAmountIsAFloatAndNeverAString() {
    $item = myapi_service_offer_provider_build_item($this->row(), $this->provider());

    $this->assertIsFloat($item['amount']);
    $this->assertSame(95.5, $item['amount']);
  }

  public function testAmountIsZeroWhenSomebodyQuotedZeroAndNullWhenNobodyQuoted() {
    $zero = myapi_service_offer_provider_build_item($this->row(['amount' => '0']), $this->provider());
    $this->assertSame(0.0, $zero['amount'], '0 is a price somebody offered, not a missing one');

    $none = myapi_service_offer_provider_build_item(
      $this->row(['amount' => '', 'amount_type' => 'on_site_quote']),
      $this->provider()
    );
    $this->assertNull($none['amount'], 'on_site_quote carries no amount');
    $this->assertSame('on_site_quote', $none['amount_type']);
  }

  public function testTheTwoDatesTravelInTheIsoFormat() {
    $item = myapi_service_offer_provider_build_item($this->row(), $this->provider());

    $this->assertSame(format_date(self::CREATED, 'custom', 'Y-m-d\TH:i:s'), $item['created']);
    $this->assertSame(format_date(self::VALID_UNTIL, 'custom', 'Y-m-d\TH:i:s'), $item['valid_until']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $item['created']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $item['valid_until']);
  }

  public function testAnExpiredValidUntilTravelsUntouched() {
    // The caducity depends on the instant it is read: the server sends the
    // date and the client decides. No `expired` key, and no row dropped.
    $item = myapi_service_offer_provider_build_item($this->row(['valid_until' => 1000000000]), $this->provider());

    $this->assertSame(format_date(1000000000, 'custom', 'Y-m-d\TH:i:s'), $item['valid_until']);
    $this->assertArrayNotHasKey('expired', $item);
  }

  /* -------------------------------------------------------------------------
   * provider: copied, {id, name}, never null.
   * ---------------------------------------------------------------------- */

  public function testProviderIsIdAndNameAndCarriesNoLogo() {
    $item = myapi_service_offer_provider_build_item($this->row(), $this->provider());

    $this->assertSame(['id', 'name'], array_keys($item['provider']));
    $this->assertSame(self::PROVIDER_NID, $item['provider']['id']);
    $this->assertSame('Plomería Torres', $item['provider']['name']);
    $this->assertArrayNotHasKey('logo', $item['provider'], 'the logo is the reader\'s own; it costs a join per row');
  }

  public function testProviderIsCopiedFromTheArgumentAndNeverReadOffTheRow() {
    // The row carries the columns the fifteen-key serialiser would build its
    // own `provider` from. They must be ignored: the page resolved the
    // provider ONCE, outside the loop.
    $row = $this->row([
      'provider_id'       => 999,
      'provider_name'     => 'Otra empresa',
      'provider_logo_uri' => 'public://logo.png',
    ]);

    $item = myapi_service_offer_provider_build_item($row, $this->provider());

    $this->assertSame(['id' => self::PROVIDER_NID, 'name' => 'Plomería Torres'], $item['provider']);
  }

  public function testProviderIsTheSameObjectInEveryItemOfThePage() {
    $provider = $this->provider();

    $first = myapi_service_offer_provider_build_item($this->row(), $provider);
    $second = myapi_service_offer_provider_build_item($this->row(['nid' => 902]), $provider);

    $this->assertSame($first['provider'], $second['provider']);
  }

  /* -------------------------------------------------------------------------
   * request: never null, four keys, and the category of SPEC 98.
   * ---------------------------------------------------------------------- */

  public function testRequestCarriesItsFourKeysInOrder() {
    $item = myapi_service_offer_provider_build_item($this->row(), $this->provider());

    $this->assertSame(['id', 'title', 'status', 'category'], array_keys($item['request']));
    $this->assertSame(self::REQUEST_NID, $item['request']['id']);
    $this->assertSame('Fuga en el calentador', $item['request']['title']);
    $this->assertSame('assigned', $item['request']['status']);
  }

  public function testRequestCategoryIsIdCodeName() {
    $item = myapi_service_offer_provider_build_item($this->row(), $this->provider());

    $this->assertSame(['id', 'code', 'name'], array_keys($item['request']['category']));
    $this->assertSame(self::CATEGORY_TID, $item['request']['category']['id']);
    $this->assertSame('plumbing', $item['request']['category']['code']);
    $this->assertSame('Plomería', $item['request']['category']['name']);
  }

  public function testCategoryCodeIsAnEmptyStringAndNeverNull() {
    $item = myapi_service_offer_provider_build_item($this->row(['category_code' => NULL]), $this->provider());

    $this->assertSame('', $item['request']['category']['code'], 'the client still has a string to compare');
  }

  public function testARequestOfADirectRequestLooksLikeAnyOther() {
    // SPEC 101: an offer can hang off a 'direct' request. Nothing in the item
    // says so, which is the point.
    $item = myapi_service_offer_provider_build_item($this->row(['request_status' => 'direct']), $this->provider());

    $this->assertSame(self::ITEM_KEYS, array_keys($item));
    $this->assertSame('direct', $item['request']['status']);
  }

  public function testACancelledRequestKeepsItsOffer() {
    $item = myapi_service_offer_provider_build_item($this->row(['request_status' => 'cancelled']), $this->provider());

    $this->assertSame('cancelled', $item['request']['status']);
    $this->assertSame(self::OFFER_NID, $item['id']);
  }

  /**
   * The four offer statuses all serialise, and none of them is filtered here.
   *
   * @dataProvider offerStatusProvider
   */
  public function testEveryOfferStatusTravels($status) {
    $item = myapi_service_offer_provider_build_item($this->row(['status' => $status]), $this->provider());

    $this->assertSame($status, $item['status']);
  }

  public function offerStatusProvider() {
    $cases = [];
    foreach (array_keys(myapi_services_offer_statuses()) as $status) {
      $cases[$status] = [$status];
    }

    return $cases;
  }

  /* =========================================================================
   * The endpoint, end to end.
   *
   * myapi_service_offer_provider_dispatch() is called the way hook_menu() calls
   * it, over a fixture `node` table, a fixture my_api_tokens row, a fixture
   * account carrying its roles and a fixture Authorization header. What is
   * asserted is the JSON body the module prints and the status it sets.
   *
   * What this suite does NOT prove, all of it the database's half: that
   * Drupal's router really prefers the literal 'api/v1/service-offers/provider'
   * over a future 'api/v1/service-offers/%' (hook_menu() is not run here — that
   * is the manual criterion of step 7), and that MySQL evaluates the INNER JOIN
   * to the request the way the fixture evaluator does. What it DOES prove is
   * everything the module decides: the order of the gate, the two refusals of
   * '?provider_id', the six parameters and the pagination block.
   * ====================================================================== */

  const TOKEN = 'a-valid-access-token';
  const UID = 7;
  const PROVIDER_B = 42;
  const FOREIGN_PROVIDER = 99;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $this->clearQueryString();
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $this->clearQueryString();
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_static_reset();
    myapi_test_db_seed();
  }

  private function clearQueryString() {
    unset(
      $_GET['page'], $_GET['limit'], $_GET['sort'], $_GET['status'],
      $_GET['request_status'], $_GET['category_id'], $_GET['provider_id'],
      $_GET['date_from'], $_GET['date_to'], $_GET['unit_id'], $_GET['foo']
    );
  }

  /**
   * One offer row, flat, as every join of the listing delivers it.
   *
   * The published flag of the node travels as `status` and the offer's own
   * status under its QUALIFIED source, because a flat row cannot hold both and
   * the fixture resolves the qualified name first.
   */
  private function offerRow($nid = self::OFFER_NID, array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) $nid,
      'type'                           => MYAPI_SERVICES_OFFER_TYPE,
      'status'                         => '1',
      'created'                        => (string) self::CREATED,
      'fp.field_provider_target_id'    => (string) self::PROVIDER_NID,
      'fos.field_offer_status_value'   => 'sent',
      'foa.field_offer_amount_value'   => '95.50',
      'fat.field_offer_amount_type_value' => 'fixed',
      'fvu.field_offer_valid_until_value' => (string) self::VALID_UNTIL,
      'fq.field_request_target_id'     => (string) self::REQUEST_NID,
      'nr.nid'                         => (string) self::REQUEST_NID,
      'nr.title'                       => 'Fuga en el calentador',
      'frs.field_request_status_value' => 'assigned',
      'fcat.field_category_tid'        => (string) self::CATEGORY_TID,
      'td.name'                        => 'Plomería',
      'cc.field_category_code_value'   => 'plumbing',
    ];
  }

  /**
   * A provider node: published, of the provider bundle.
   */
  private function providerNode($nid = self::PROVIDER_NID, $status = '1', $title = 'Plomería Torres') {
    return [
      'nid'    => (string) $nid,
      'type'   => MYAPI_SERVICES_PROVIDER_TYPE,
      'status' => $status,
      'title'  => $title,
    ];
  }

  /**
   * One row of field_data_field_provider_users: the account -> provider link.
   */
  private function link($provider_nid, $uid = self::UID) {
    return [
      'entity_id'   => (string) $provider_nid,
      'entity_type' => 'node',
      'deleted'     => '0',
      MYAPI_PROVIDER_USERS_FIELD . '_target_id' => (string) $uid,
    ];
  }

  private function tokenRow() {
    return [
      'id'                => '1',
      'uid'               => (string) self::UID,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * Seeds a whole scenario in one call: every myapi_test_db_seed() replaces the
   * entire fixture, so nothing can be added afterwards.
   */
  private function seed(array $offers, array $tables = [], $roles = NULL) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;

    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid'    => self::UID,
      'name'   => 'proveedor' . self::UID,
      'status' => 1,
      'roles'  => $roles,
    ];

    $tables += [
      'my_api_tokens' => [$this->tokenRow()],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [$this->link(self::PROVIDER_NID)],
    ];

    $tables['node'] = array_merge(
      $offers,
      isset($tables['node']) ? $tables['node'] : [$this->providerNode()]
    );

    myapi_test_db_seed($tables);
    myapi_test_static_reset();
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  private function dispatch() {
    return myapi_test_capture('myapi_service_offer_provider_dispatch');
  }

  /**
   * Authenticates, seeds, asks for the account's provider and runs.
   */
  private function archive(array $offers, array $tables = [], $roles = NULL) {
    $this->authenticate();
    $this->seed($offers, $tables, $roles);
    if (!isset($_GET['provider_id'])) {
      $_GET['provider_id'] = (string) self::PROVIDER_NID;
    }

    return $this->dispatch();
  }

  private function items(array $result) {
    return $result['json']['data']['service_offers'];
  }

  private function ids(array $result) {
    return array_column($this->items($result), 'id');
  }

  private function pagination(array $result) {
    return $result['json']['data']['pagination'];
  }

  /* -------------------------------------------------------------------------
   * The method, the token and the role.
   * ---------------------------------------------------------------------- */

  public function testEveryMethodOtherThanGetIs405BeforeAuthentication() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
      $this->seed([$this->offerRow()]);
      $_SERVER['REQUEST_METHOD'] = $method;
      unset($_SERVER['HTTP_AUTHORIZATION']);

      $result = $this->dispatch();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
    }
  }

  public function testNoAuthorizationHeaderIs401() {
    $this->seed([$this->offerRow()]);

    $result = $this->dispatch();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  public function testAnUnknownTokenIs401() {
    $this->seed([$this->offerRow()]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-token';

    $result = $this->dispatch();

    $this->assertSame(401, $result['status']);
    $this->assertSame('invalid_token', $result['json']['error_code']);
  }

  public function testAnAccountWithoutTheProviderRoleIs403() {
    $this->authenticate();
    $this->seed([$this->offerRow()], [], ['authenticated user']);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $result = $this->dispatch();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
  }

  public function testAnAdministratorWithoutTheProviderRoleIs403() {
    $this->authenticate();
    $this->seed([$this->offerRow()], [], ['authenticated user', 'administrator']);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $result = $this->dispatch();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
  }

  /**
   * The role is checked BEFORE '?provider_id': a reader who may not be here
   * does not learn whether their parameter was well formed.
   */
  public function testTheRoleIsCheckedBeforeTheMandatoryParameter() {
    $this->authenticate();
    $this->seed([$this->offerRow()], [], ['authenticated user']);

    $result = $this->dispatch();

    $this->assertSame(403, $result['status']);
    $this->assertSame('provider_role_required', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * '?provider_id': mandatory, strict in the format, lax in the ownership.
   * ---------------------------------------------------------------------- */

  public function testAMissingProviderIdIs422MissingField() {
    $this->authenticate();
    $this->seed([$this->offerRow()]);

    $result = $this->dispatch();

    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertStringContainsString('provider_id', $result['json']['error']);
  }

  /**
   * @dataProvider malformedProviderIdProvider
   */
  public function testAMalformedProviderIdIs422WithNoOfferQuery($raw) {
    $this->authenticate();
    $this->seed([$this->offerRow()]);
    $_GET['provider_id'] = $raw;

    $result = $this->dispatch();

    $this->assertSame(422, $result['status'], var_export($raw, TRUE));
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('provider_id', $result['json']['error']);
    $this->assertSame([], myapi_test_db_queries('node'), 'no offer query may run for a malformed id');
  }

  public function malformedProviderIdProvider() {
    return [
      'letters'  => ['abc'],
      'zero'     => ['0'],
      'negative' => ['-1'],
      'a list'   => ['1,2'],
      'padded'   => [' 41'],
      'empty'    => [''],
      'an array' => [['41']],
    ];
  }

  public function testAForeignProviderIdIsAnEmptyList() {
    $this->authenticate();
    $this->seed([$this->offerRow()]);
    $_GET['provider_id'] = (string) self::FOREIGN_PROVIDER;

    $result = $this->dispatch();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));
    $this->assertSame(0, $this->pagination($result)['total']);
    $this->assertSame(0, $this->pagination($result)['total_pages']);
  }

  public function testANonExistentProviderIdAnswersTheSameEmptyList() {
    $this->authenticate();
    // Linked to a nid no node carries: owned, and still nothing to read.
    $this->seed([$this->offerRow()], [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [$this->link(self::PROVIDER_NID), $this->link(777)],
    ]);
    $_GET['provider_id'] = '777';

    $result = $this->dispatch();

    $this->assertSame(200, $result['status'], 'never a 403 and never a 404');
    $this->assertSame([], $this->items($result));
    $this->assertSame(0, $this->pagination($result)['total']);
  }

  public function testAnEmptyAnswerEchoesTheLimitTheCallerAskedFor() {
    $this->authenticate();
    $this->seed([$this->offerRow()]);
    $_GET['provider_id'] = (string) self::FOREIGN_PROVIDER;
    $_GET['limit'] = '50';

    $result = $this->dispatch();

    $this->assertSame(50, $this->pagination($result)['limit']);
    $this->assertSame(1, $this->pagination($result)['page']);
  }

  public function testTwoProvidersAnswerTheirOwnOffersAndNothingElse() {
    $this->authenticate();
    $this->seed(
      [
        $this->offerRow(901),
        $this->offerRow(902, ['fp.field_provider_target_id' => (string) self::PROVIDER_B]),
      ],
      [
        'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
          $this->link(self::PROVIDER_NID),
          $this->link(self::PROVIDER_B),
        ],
        'node' => [$this->providerNode(), $this->providerNode(self::PROVIDER_B, '1', 'Eléctrica Beta')],
      ]
    );

    $_GET['provider_id'] = (string) self::PROVIDER_NID;
    $first = $this->dispatch();
    $this->assertSame([901], $this->ids($first));
    $this->assertSame('Plomería Torres', $this->items($first)[0]['provider']['name']);

    $_GET['provider_id'] = (string) self::PROVIDER_B;
    $second = $this->dispatch();
    $this->assertSame([902], $this->ids($second));
    $this->assertSame('Eléctrica Beta', $this->items($second)[0]['provider']['name']);
  }

  /* -------------------------------------------------------------------------
   * The set.
   * ---------------------------------------------------------------------- */

  public function testEveryOfferStatusOfMineAppearsUnfiltered() {
    $offers = [];
    $nid = 901;
    foreach (array_keys(myapi_services_offer_statuses()) as $status) {
      $offers[] = $this->offerRow($nid++, ['fos.field_offer_status_value' => $status]);
    }

    $result = $this->archive($offers);

    $this->assertSame([904, 903, 902, 901], $this->ids($result));
    $this->assertSame(4, $this->pagination($result)['total']);
  }

  public function testAnOfferOnACancelledRequestAppears() {
    $result = $this->archive([$this->offerRow(901, ['frs.field_request_status_value' => 'cancelled'])]);

    $this->assertSame([901], $this->ids($result));
    $this->assertSame('cancelled', $this->items($result)[0]['request']['status']);
  }

  public function testAnOfferOnADirectRequestAppearsWithNothingSpecial() {
    $result = $this->archive([$this->offerRow(901, ['frs.field_request_status_value' => 'direct'])]);

    $this->assertSame([901], $this->ids($result));
    $this->assertSame(self::ITEM_KEYS, array_keys($this->items($result)[0]));
  }

  public function testAnUnpublishedOfferDoesNotAppear() {
    $result = $this->archive([$this->offerRow(901), $this->offerRow(902, ['status' => '0'])]);

    $this->assertSame([901], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  public function testAnOfferOfAnotherProviderNeverAppears() {
    $result = $this->archive([
      $this->offerRow(901),
      $this->offerRow(902, ['fp.field_provider_target_id' => (string) self::FOREIGN_PROVIDER]),
    ]);

    $this->assertSame([901], $this->ids($result));
  }

  public function testAServiceTransactionSharingFieldRequestIsNeverAnOffer() {
    $result = $this->archive([
      $this->offerRow(901),
      $this->offerRow(902, ['type' => 'service_transaction']),
    ]);

    $this->assertSame([901], $this->ids($result));
  }

  public function testASuspendedProviderReadsItsWholeArchive() {
    $result = $this->archive([$this->offerRow(901)], [
      'node' => [$this->providerNode(self::PROVIDER_NID, '0')],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([901], $this->ids($result));
  }

  /* -------------------------------------------------------------------------
   * The six parameters.
   * ---------------------------------------------------------------------- */

  public function testPageAndLimitDefaultToOneAndTwenty() {
    $result = $this->archive([$this->offerRow()]);

    $this->assertSame(1, $this->pagination($result)['page']);
    $this->assertSame(20, $this->pagination($result)['limit']);
  }

  public function testLimitIsTrimmedToFiftyAndGarbageFallsBackWithNo422() {
    $this->authenticate();
    $this->seed([$this->offerRow()]);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $_GET['limit'] = '999';
    $this->assertSame(50, $this->pagination($this->dispatch())['limit']);

    $_GET['limit'] = 'abc';
    $result = $this->dispatch();
    $this->assertSame(200, $result['status']);
    $this->assertSame(20, $this->pagination($result)['limit']);

    unset($_GET['limit']);
    $_GET['page'] = 'abc';
    $result = $this->dispatch();
    $this->assertSame(200, $result['status']);
    $this->assertSame(1, $this->pagination($result)['page']);
  }

  public function testLimitMinusOneReturnsEverythingOnPageOne() {
    $this->authenticate();
    $this->seed([$this->offerRow(901), $this->offerRow(902), $this->offerRow(903)]);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;
    $_GET['limit'] = '-1';
    $_GET['page'] = '3';

    $result = $this->dispatch();

    $this->assertCount(3, $this->items($result));
    $this->assertSame(1, $this->pagination($result)['page']);
    $this->assertSame(-1, $this->pagination($result)['limit']);
    $this->assertSame(1, $this->pagination($result)['total_pages']);
  }

  public function testSortOrdersByTheOffersOwnCreatedAndFallsBackToDesc() {
    $offers = [
      $this->offerRow(901, ['created' => '1000']),
      $this->offerRow(902, ['created' => '2000']),
    ];

    $this->authenticate();
    $this->seed($offers);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $_GET['sort'] = 'asc';
    $this->assertSame([901, 902], $this->ids($this->dispatch()));

    $_GET['sort'] = 'desc';
    $this->assertSame([902, 901], $this->ids($this->dispatch()));

    $_GET['sort'] = 'sideways';
    $this->assertSame([902, 901], $this->ids($this->dispatch()), 'an unknown sort falls back to desc');
  }

  public function testStatusFiltersByTheOffersStatusAndDropsUnknownKeys() {
    $offers = [
      $this->offerRow(901, ['fos.field_offer_status_value' => 'sent']),
      $this->offerRow(902, ['fos.field_offer_status_value' => 'selected']),
      $this->offerRow(903, ['fos.field_offer_status_value' => 'rejected']),
    ];

    $this->authenticate();
    $this->seed($offers);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $_GET['status'] = 'sent,selected';
    $this->assertSame([902, 901], $this->ids($this->dispatch()));

    $_GET['status'] = 'inventado';
    $result = $this->dispatch();
    $this->assertSame(200, $result['status'], 'an unknown key is dropped in silence, never a 422');
    $this->assertSame([903, 902, 901], $this->ids($result), 'no valid key at all means no filter');

    $_GET['status'] = 'sent,inventado';
    $this->assertSame([901], $this->ids($this->dispatch()));
  }

  public function testRequestStatusFiltersByTheRequestsStatus() {
    $offers = [
      $this->offerRow(901, ['frs.field_request_status_value' => 'closed']),
      $this->offerRow(902, ['frs.field_request_status_value' => 'cancelled']),
      $this->offerRow(903, ['frs.field_request_status_value' => 'open']),
    ];

    $this->authenticate();
    $this->seed($offers);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $_GET['request_status'] = 'closed,cancelled';
    $this->assertSame([902, 901], $this->ids($this->dispatch()));

    $_GET['request_status'] = 'inventado';
    $result = $this->dispatch();
    $this->assertSame(200, $result['status']);
    $this->assertSame([903, 902, 901], $this->ids($result));
  }

  /**
   * The two catalogues are read from the module and never typed here — a
   * status of one must not be accepted by the other's filter.
   */
  public function testTheTwoStatusFiltersDoNotShareACatalogue() {
    $this->authenticate();
    $this->seed([$this->offerRow(901)]);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $_GET['status'] = 'cancelled';
    $this->assertSame([901], $this->ids($this->dispatch()), 'a request status is not an offer status');

    unset($_GET['status']);
    $_GET['request_status'] = 'sent';
    $this->assertSame([901], $this->ids($this->dispatch()), 'an offer status is not a request status');
  }

  public function testCategoryIdFiltersByTheRequestsCategoryAndIsStrict() {
    $offers = [
      $this->offerRow(901),
      $this->offerRow(902, ['fcat.field_category_tid' => '77']),
    ];

    $this->authenticate();
    $this->seed($offers);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $_GET['category_id'] = (string) self::CATEGORY_TID;
    $this->assertSame([901], $this->ids($this->dispatch()));

    $_GET['category_id'] = 'abc';
    $result = $this->dispatch();
    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('category_id', $result['json']['error']);
  }

  public function testTheDateRangeIsInclusiveAtBothEnds() {
    // 2026-08-25 at 23:50, the last minute of the upper bound's day.
    $late = strtotime('2026-08-25 23:50:00');
    $early = strtotime('2026-08-25 00:05:00');
    $before = strtotime('2026-08-24 12:00:00');

    $this->authenticate();
    $this->seed([
      $this->offerRow(901, ['created' => (string) $late]),
      $this->offerRow(902, ['created' => (string) $early]),
      $this->offerRow(903, ['created' => (string) $before]),
    ]);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;

    $_GET['date_from'] = '2026-08-25';
    $_GET['date_to'] = '2026-08-25';
    $this->assertSame([901, 902], $this->ids($this->dispatch()));

    $_GET['date_from'] = '2026-08-24';
    $this->assertSame([901, 902, 903], $this->ids($this->dispatch()));

    // Garbage drops the bound in silence, never a 422.
    $_GET['date_from'] = 'ayer';
    unset($_GET['date_to']);
    $result = $this->dispatch();
    $this->assertSame(200, $result['status']);
    $this->assertSame([901, 902, 903], $this->ids($result));
  }

  public function testAnUnknownParameterIsIgnoredInSilence() {
    $this->authenticate();
    $this->seed([$this->offerRow(901)]);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;
    $_GET['unit_id'] = 'abc';
    $_GET['foo'] = 'bar';

    $result = $this->dispatch();

    $this->assertSame(200, $result['status'], 'a parameter this endpoint does not read is never a 422');
    $this->assertSame([901], $this->ids($result));
  }

  /* -------------------------------------------------------------------------
   * Pagination.
   * ---------------------------------------------------------------------- */

  public function testPaginationDescribesTheFilteredSetAndNotThePage() {
    $offers = [];
    for ($i = 0; $i < 5; $i++) {
      $offers[] = $this->offerRow(901 + $i, ['created' => (string) (1000 + $i)]);
    }

    $this->authenticate();
    $this->seed($offers);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;
    $_GET['limit'] = '2';

    $result = $this->dispatch();

    $this->assertSame(['total', 'page', 'limit', 'total_pages'], array_keys($this->pagination($result)));
    $this->assertSame(5, $this->pagination($result)['total']);
    $this->assertSame(3, $this->pagination($result)['total_pages']);
    $this->assertCount(2, $this->items($result));

    $_GET['page'] = '9';
    $beyond = $this->dispatch();
    $this->assertSame(200, $beyond['status']);
    $this->assertSame([], $this->items($beyond));
    $this->assertSame(5, $this->pagination($beyond)['total'], 'the real total, past the last page');
  }

  public function testTotalPagesIsZeroWhenTotalIsZero() {
    $this->authenticate();
    $this->seed([$this->offerRow(901, ['fos.field_offer_status_value' => 'sent'])]);
    $_GET['provider_id'] = (string) self::PROVIDER_NID;
    $_GET['status'] = 'withdrawn';

    $result = $this->dispatch();

    $this->assertSame(0, $this->pagination($result)['total']);
    $this->assertSame(0, $this->pagination($result)['total_pages'], 'zero, and not one');
  }

  /* -------------------------------------------------------------------------
   * The envelope.
   * ---------------------------------------------------------------------- */

  public function testTheEnvelopeIsSuccessDataServiceOffersAndPagination() {
    $result = $this->archive([$this->offerRow()]);

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame(['service_offers', 'pagination'], array_keys($result['json']['data']));
    $this->assertArrayNotHasKey('message', $result['json'], 'a read endpoint carries no message_key');
  }

  public function testTheItemOfTheRealEndpointCarriesTheEightKeys() {
    $result = $this->archive([$this->offerRow()]);

    $item = $this->items($result)[0];
    $this->assertSame(self::ITEM_KEYS, array_keys($item));
    $this->assertSame(['id', 'name'], array_keys($item['provider']));
    $this->assertSame(['id', 'title', 'status', 'category'], array_keys($item['request']));
    $this->assertSame(95.5, $item['amount']);
    $this->assertSame(self::REQUEST_NID, $item['request']['id']);
  }

}

