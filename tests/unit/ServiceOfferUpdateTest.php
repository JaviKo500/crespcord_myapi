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
 * Unit tests for editing an offer — PUT /api/v1/service-offers/{id} (SPEC 105).
 *
 * THE PUT IS A TOTAL REPLACEMENT, and that is the one thing this class exists
 * to pin down: an optional field the provider leaves out of the body is a field
 * the provider deleted. myapi_service_offer_apply_values() is where that lives,
 * and it is the function SPEC 105 extracted out of
 * myapi_service_offer_build_node() so the POST and the PUT can never drift.
 *
 * ServiceOfferCreateTest is the net under that extraction and is NOT touched:
 * it asserts the other half of the same function — that on a NEW node an
 * undeclared optional is not written at all — and it still passes untouched.
 */
class ServiceOfferUpdateTest extends TestCase {

  /**
   * The twelve values of a body that declares everything, so that every
   * optional field has something to lose.
   */
  private function fullValues(array $overrides = []) {
    $body = $overrides + [
      'message'        => 'Puedo pasar el jueves por la mañana.',
      'amount_type'    => 'fixed',
      'amount'         => 150.5,
      'tax_included'   => TRUE,
      'valid_until'    => date('Y-m-d H:i', REQUEST_TIME + 7200),
      'available_from' => date('Y-m-d H:i', REQUEST_TIME + 3600),
      'duration'       => 3,
      'duration_unit'  => 'hours',
      'includes'       => 'Mano de obra.',
      'excludes'       => 'El calentador.',
      'warranty_days'  => 90,
      'requires_visit' => TRUE,
    ];

    $result = myapi_service_offer_validate_body($body);
    $this->assertTrue($result['ok'], 'the fixture body must be valid');

    return $result['values'];
  }

  /**
   * The minimum a body may be: three keys, nine values NULL and requires_visit
   * FALSE. This is the body that deletes everything else.
   */
  private function minimalValues() {
    $result = myapi_service_offer_validate_body([
      'message'     => 'Corrijo el precio.',
      'amount_type' => 'fixed',
      'amount'      => 120,
    ]);
    $this->assertTrue($result['ok'], 'the fixture body must be valid');

    return $result['values'];
  }

  /**
   * A stored offer, the way node_load() hands one back: EVERY field property
   * exists, filled or as an empty array. That is what makes the deletion branch
   * of apply_values() reachable, and what a brand new node never looks like.
   */
  private function storedOffer() {
    $node = new stdClass();
    $node->nid = 901;
    $node->type = 'service_offer';
    $node->uid = 7;
    $node->status = 1;
    $node->created = 1787000000;
    $node->title = 'Oferta de Plomería Torres — solicitud #128';
    $node->language = LANGUAGE_NONE;
    $node->field_request[LANGUAGE_NONE][0]['target_id'] = 128;
    $node->field_provider[LANGUAGE_NONE][0]['target_id'] = 41;
    $node->field_offer_status[LANGUAGE_NONE][0]['value'] = 'sent';

    myapi_service_offer_apply_values($node, $this->fullValues());

    return $node;
  }

  /* -------------------------------------------------------------------------
   * The extraction (step 1) — apply_values() on a node that already has values.
   * ---------------------------------------------------------------------- */

  /**
   * A body that declares everything writes all twelve, on a stored node exactly
   * as on a new one.
   */
  public function testAFullBodyWritesEveryColumnOnAStoredOffer() {
    $node = $this->storedOffer();

    $this->assertSame('Puedo pasar el jueves por la mañana.', $node->field_offer_message[LANGUAGE_NONE][0]['value']);
    $this->assertSame('fixed', $node->field_offer_amount_type[LANGUAGE_NONE][0]['value']);
    $this->assertSame(150.5, $node->field_offer_amount[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $node->field_offer_tax_included[LANGUAGE_NONE][0]['value']);
    $this->assertSame(3, $node->field_offer_duration[LANGUAGE_NONE][0]['value']);
    $this->assertSame('hours', $node->field_offer_duration_unit[LANGUAGE_NONE][0]['value']);
    $this->assertSame('Mano de obra.', $node->field_offer_includes[LANGUAGE_NONE][0]['value']);
    $this->assertSame('El calentador.', $node->field_offer_excludes[LANGUAGE_NONE][0]['value']);
    $this->assertSame(90, $node->field_offer_warranty_days[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);
  }

  /**
   * THE HEART OF THE TOTAL REPLACEMENT: every optional field the second body
   * leaves out is EMPTIED, not left with what it had. An offer that had 90 days
   * of warranty ends the request with none.
   */
  public function testTheOptionalsTheBodyOmitsAreEmptied() {
    $node = $this->storedOffer();

    myapi_service_offer_apply_values($node, $this->minimalValues());

    foreach (['field_offer_tax_included', 'field_offer_valid_until',
      'field_offer_available_from', 'field_offer_duration',
      'field_offer_duration_unit', 'field_offer_includes',
      'field_offer_excludes', 'field_offer_warranty_days'] as $field) {
      $this->assertSame([], $node->{$field}, $field . ' must be emptied when the body omits it');
    }
  }

  /**
   * The two the body can never omit are overwritten, and amount follows
   * amount_type: dropping to 'on_site_quote' without an amount empties it.
   */
  public function testTheDeclaredValuesAreOverwritten() {
    $node = $this->storedOffer();

    myapi_service_offer_apply_values($node, $this->minimalValues());

    $this->assertSame('Corrijo el precio.', $node->field_offer_message[LANGUAGE_NONE][0]['value']);
    $this->assertSame(120.0, $node->field_offer_amount[LANGUAGE_NONE][0]['value']);

    $quote = myapi_service_offer_validate_body([
      'message'     => 'Necesito verlo antes de dar precio.',
      'amount_type' => 'on_site_quote',
    ]);
    $this->assertTrue($quote['ok']);
    myapi_service_offer_apply_values($node, $quote['values']);

    $this->assertSame('on_site_quote', $node->field_offer_amount_type[LANGUAGE_NONE][0]['value']);
    $this->assertSame([], $node->field_offer_amount, 'amount must be emptied when the body stops declaring it');
  }

  /**
   * requires_visit is the exception in both directions: ALWAYS written, as 0 or
   * 1, and never emptied. An absent requires_visit is FALSE and not NULL.
   */
  public function testRequiresVisitIsAlwaysWrittenAndNeverEmptied() {
    $node = $this->storedOffer();
    $this->assertSame(1, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);

    myapi_service_offer_apply_values($node, $this->minimalValues());

    $this->assertSame(0, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);
  }

  /**
   * apply_values() writes the twelve of the body and NOTHING ELSE. The seven
   * the server fixed the day the offer was born are still exactly what they
   * were — the PUT never rewrites history.
   */
  public function testWhatTheServerFixedIsNotTouched() {
    $node = $this->storedOffer();

    myapi_service_offer_apply_values($node, $this->minimalValues());

    $this->assertSame(901, $node->nid);
    $this->assertSame(7, $node->uid);
    $this->assertSame(1787000000, $node->created);
    $this->assertSame('Oferta de Plomería Torres — solicitud #128', $node->title);
    $this->assertSame(128, $node->field_request[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(41, $node->field_provider[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('sent', $node->field_offer_status[LANGUAGE_NONE][0]['value']);

    // The three chat fields are never written, here either.
    foreach (['field_firebase_path', 'field_chat_opened_at', 'field_last_message_at'] as $field) {
      $this->assertFalse(property_exists($node, $field), $field . ' must stay empty');
    }
  }

  /**
   * Only 'value' is ever written, never 'format' — the rule every write path of
   * this module follows.
   */
  public function testOnlyValueIsWritten() {
    $node = $this->storedOffer();

    $this->assertSame(['value'], array_keys($node->field_offer_message[LANGUAGE_NONE][0]));
    $this->assertSame(['value'], array_keys($node->field_offer_includes[LANGUAGE_NONE][0]));
  }

  /* -------------------------------------------------------------------------
   * The gate of the edit (step 2) — the shared four PLUS the licence.
   * ---------------------------------------------------------------------- */

  /** The account's providers, as myapi_provider_role_provider_ids() answers. */
  private const OWNED = [41, 55];

  /** The clock every licence case below is read against. */
  private const NOW = 1787000000;

  private function offer(array $values = []) {
    return (object) ($values + [
      'nid'          => '901',
      'provider_id'  => '41',
      'provider_raw' => '41',
      'request_id'   => '128',
      'status'       => 'sent',
      'created'      => '1787000000',
    ]);
  }

  private function request(array $values = []) {
    return (object) ($values + ['nid' => '128', 'status' => 'offered']);
  }

  /** A row of myapi_service_offer_provider_row(): active by default. */
  private function provider(array $values = []) {
    return (object) ($values + [
      'nid'            => '41',
      'title'          => 'Plomería Torres',
      'status'         => '1',
      'license_expiry' => (string) (self::NOW + 86400),
      'owned'          => TRUE,
      'category_ids'   => [9],
    ]);
  }

  private function updateGate($row = NULL, $request_row = NULL, $provider_row = NULL, array $owned = self::OWNED) {
    return myapi_service_offer_update_gate(
      $row === NULL ? $this->offer() : $row,
      $owned,
      $request_row === NULL ? $this->request() : $request_row,
      $provider_row === NULL ? $this->provider() : $provider_row,
      self::NOW
    );
  }

  /**
   * All five pass: the gate answers NULL and the body is validated next.
   */
  public function testAnActiveProvidersOwnSentOfferPasses() {
    $this->assertNull($this->updateGate());
  }

  /**
   * THE EDIT REALLY DELEGATES the shared four instead of asking its own
   * questions: every one of them answers here, with the EDIT's code on 6.
   *
   * @dataProvider sharedFailures
   */
  public function testTheSharedFourAnswerThroughTheEditsGate($offer, $request, $expected) {
    $row = $offer === FALSE ? FALSE : $this->offer($offer);
    $request_row = $request === FALSE ? FALSE : $this->request($request);

    $this->assertSame($expected, $this->updateGate($row, $request_row));
  }

  public function sharedFailures() {
    return [
      'no such offer'      => [FALSE, [], 'not_found'],
      'somebody elses'     => [['provider_raw' => '77'], [], 'service_offer_provider_not_owned'],
      'no provider at all' => [['provider_raw' => NULL], [], 'service_offer_provider_not_owned'],
      'already selected'   => [['status' => 'selected'], [], 'service_offer_not_editable'],
      'already withdrawn'  => [['status' => 'withdrawn'], [], 'service_offer_not_editable'],
      'already rejected'   => [['status' => 'rejected'], [], 'service_offer_not_editable'],
      'request assigned'   => [[], ['status' => 'assigned'], 'service_request_not_offerable'],
      'request closed'     => [[], ['status' => 'closed'], 'service_request_not_offerable'],
      'no request row'     => [[], FALSE, 'service_request_not_offerable'],
    ];
  }

  /**
   * The offer's status answers with THE EDIT'S WORDS and never the
   * withdrawal's: same rule, two messages, and the code travels from the call
   * site.
   */
  public function testConditionSixSpeaksTheEditsLanguage() {
    $this->assertSame('service_offer_not_editable', $this->updateGate($this->offer(['status' => 'withdrawn'])));
  }

  /**
   * 8, AND THE ASYMMETRY THAT IS DECISION 9: a lapsed licence blocks EDITING.
   * Editing is sending a new quote, and whoever may not operate does not quote.
   *
   * @dataProvider inactiveProviders
   */
  public function testALapsedOrSuspendedProviderMayNotEdit($provider) {
    $row = $provider === FALSE ? FALSE : $this->provider($provider);

    $this->assertSame('service_offer_provider_not_active', $this->updateGate(NULL, NULL, $row));
  }

  public function inactiveProviders() {
    return [
      'licence expired yesterday' => [['license_expiry' => (string) (self::NOW - 86400)]],
      'no licence row at all'     => [['license_expiry' => NULL]],
      'empty licence'             => [['license_expiry' => '']],
      'unparseable licence'       => [['license_expiry' => 'soon']],
      'node unpublished'          => [['status' => '0']],
      'no provider row'           => [FALSE],
    ];
  }

  /**
   * 8, the other half of decision 9, asserted where it can be: THAT SAME lapsed
   * licence passes the SHARED gate untouched — which is what lets the
   * withdrawal answer 200 where the edit answers 403.
   */
  public function testTheSameLapsedLicencePassesTheSharedGate() {
    $this->assertSame('service_offer_provider_not_active', $this->updateGate(NULL, NULL, $this->provider(['license_expiry' => (string) (self::NOW - 1)])));

    $this->assertNull(myapi_service_offer_write_gate(
      $this->offer(),
      self::OWNED,
      $this->request(),
      'service_offer_not_withdrawable'
    ));
  }

  /**
   * A licence that expires EXACTLY now is still valid — the rule is
   * myapi_services_provider_is_active()'s and this gate does not restate it.
   */
  public function testTheLicenceRuleIsNotRestatedHere() {
    $this->assertNull($this->updateGate(NULL, NULL, $this->provider(['license_expiry' => (string) self::NOW])));
    $this->assertSame(
      'service_offer_provider_not_active',
      $this->updateGate(NULL, NULL, $this->provider(['license_expiry' => (string) (self::NOW - 1)]))
    );
  }

  /**
   * THE 409 OF THE REQUEST WINS OVER THE 403 OF THE LICENCE, because condition
   * 7 runs before condition 8: "may anything be written on this offer at all?"
   * has to pass before "may you send a new quote?" means anything.
   */
  public function testTheStateOfTheRequestAnswersBeforeTheLicence() {
    $this->assertSame(
      'service_request_not_offerable',
      $this->updateGate(NULL, $this->request(['status' => 'closed']), $this->provider(['license_expiry' => (string) (self::NOW - 86400)]))
    );
  }

  /**
   * And so does every condition above it: a stranger's offer with a lapsed
   * licence answers 403 not_owned, never not_active.
   */
  public function testOwnershipAnswersBeforeTheLicence() {
    $this->assertSame(
      'service_offer_provider_not_owned',
      $this->updateGate($this->offer(['provider_raw' => '77']), NULL, $this->provider(['status' => '0']))
    );
  }

  /* =========================================================================
   * The endpoint, end to end.
   *
   * myapi_service_offer_item_dispatch() is called the way hook_menu() calls it,
   * over a fixture `node` table, a fixture my_api_tokens row, a fixture account
   * carrying its roles and a fixture Authorization header.
   *
   * THE BODY CANNOT BE DRIVEN FROM HERE, and saying so is the point.
   * myapi_request_body() reads php://input, which is empty in a CLI process and
   * has no test hook, so EVERY case below runs with NO BODY — which is exactly
   * what makes the ORDER assertable: a case that answers 403 or 409 proves the
   * gate ran and answered BEFORE the body was ever looked at, and a case that
   * answers 422 missing_field proves the whole gate passed. It is the same
   * boundary ServiceOfferCreateTest draws for the POST.
   *
   * SO WHAT IS NOT ASSERTED HERE, and is a manual criterion of the spec: the
   * ten body rules through the endpoint (they are asserted against the
   * validator directly, in ServiceOfferCreateTest, unchanged), the explicit
   * refusal of provider_id, and the total replacement over a real node — that
   * last one lives where the rule lives, in the apply_values() cases at the top
   * of this class. node_save() is a RECORDER: a green case says "the resource
   * asked for this node", never "Drupal wrote it".
   * ====================================================================== */

  const TOKEN = 'a-valid-access-token';
  const UID = 7;
  const OFFER_NID = 901;
  const REQUEST_NID = 128;
  const PROVIDER_NID = 41;
  const FOREIGN_PROVIDER = 77;
  const REQUESTER_UID = 314;
  const CREATED = 1756116840;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_write_reset();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_static_reset();
    myapi_test_write_reset();
    myapi_test_node_seed();
    myapi_test_db_seed();
  }

  /* ------------------------------------------------------------------------
   * Fixtures — the withdrawal's, because the two verbs read the same two rows.
   * --------------------------------------------------------------------- */

  private function offerRow(array $overrides = []) {
    return $overrides + [
      'nid'                                  => (string) self::OFFER_NID,
      'type'                                 => MYAPI_SERVICES_OFFER_TYPE,
      'status'                               => '1',
      'created'                              => (string) self::CREATED,
      'fq.field_request_target_id'           => (string) self::REQUEST_NID,
      'nr.nid'                               => (string) self::REQUEST_NID,
      'fp.field_provider_target_id'          => (string) self::PROVIDER_NID,
      'np.nid'                               => (string) self::PROVIDER_NID,
      'np.title'                             => 'Plomería Torres',
      'fml.uri'                              => 'public://logo.png',
      'foa.field_offer_amount_value'         => '150.50',
      'fom.field_offer_message_value'        => 'Puedo pasar el jueves por la mañana.',
      'fost.field_offer_status_value'        => 'sent',
      'fat.field_offer_amount_type_value'    => 'fixed',
      'frv.field_offer_requires_visit_value' => '0',
    ];
  }

  private function requestNodeRow(array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) self::REQUEST_NID,
      'type'                           => MYAPI_SERVICES_REQUEST_TYPE,
      'status'                         => '1',
      'title'                          => 'Fuga en el calentador',
      'created'                        => (string) self::CREATED,
      'fr.field_requester_target_id'   => (string) self::REQUESTER_UID,
      'requester_uid'                  => (string) self::REQUESTER_UID,
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      'fcat.field_category_tid'        => '9',
      'category_code'                  => 'plumbing',
      'category_name'                  => 'Plomería',
    ];
  }

  /**
   * The provider node, with its licence. myapi_service_offer_provider_row()
   * reads the licence off a joined field row, so it travels qualified.
   */
  private function providerNode(array $overrides = []) {
    return $overrides + [
      'nid'    => (string) self::PROVIDER_NID,
      'type'   => MYAPI_SERVICES_PROVIDER_TYPE,
      'status' => '1',
      'title'  => 'Plomería Torres',
      'fle.field_license_expiry_value' => (string) (REQUEST_TIME + 86400),
    ];
  }

  private function link($provider_nid, $uid = self::UID) {
    return [
      'entity_id'   => (string) $provider_nid,
      'entity_type' => 'node',
      'deleted'     => '0',
      MYAPI_PROVIDER_USERS_FIELD . '_target_id' => (string) $uid,
    ];
  }

  private function tokenRow($uid = self::UID) {
    return [
      'id'                => '1',
      'uid'               => (string) $uid,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /** The loaded offer node the endpoint writes on. */
  private function seedOfferNode() {
    myapi_test_node_seed([self::OFFER_NID => (object) [
      'nid'     => self::OFFER_NID,
      'type'    => MYAPI_SERVICES_OFFER_TYPE,
      'uid'     => 33,
      'status'  => 1,
      'created' => self::CREATED,
      'title'   => 'Oferta de Plomería Torres — solicitud #128',
      'field_request'      => [LANGUAGE_NONE => [['target_id' => self::REQUEST_NID]]],
      'field_provider'     => [LANGUAGE_NONE => [['target_id' => self::PROVIDER_NID]]],
      'field_offer_status' => [LANGUAGE_NONE => [['value' => 'sent']]],
    ]]);
  }

  private function seedEndpoint(array $offer_overrides = [], array $request_overrides = [], array $provider_overrides = [], $roles = NULL, $uid = self::UID) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;

    $GLOBALS['myapi_test_users'][$uid] = [
      'uid'    => $uid,
      'name'   => 'cuenta' . $uid,
      'status' => 1,
      'roles'  => $roles,
    ];

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow($uid)],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [$this->link(self::PROVIDER_NID)],
      'node' => [
        $this->offerRow($offer_overrides),
        $this->requestNodeRow($request_overrides),
        $this->providerNode($provider_overrides),
      ],
    ]);
    myapi_test_static_reset();
    $this->seedOfferNode();
  }

  private function dispatch($nid = self::OFFER_NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_offer_item_dispatch((string) $nid);
    });
  }

  private function put(array $offer_overrides = [], array $request_overrides = [], array $provider_overrides = [], $roles = NULL) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $this->seedEndpoint($offer_overrides, $request_overrides, $provider_overrides, $roles);

    return $this->dispatch();
  }

  private function assertError(array $result, $status, $error_code) {
    $this->assertSame($status, $result['status']);
    $this->assertFalse($result['json']['success']);
    $this->assertSame($error_code, $result['json']['error_code']);
  }

  /* ------------------------------------------------------------------------
   * The dispatcher: two actors on one URL.
   * --------------------------------------------------------------------- */

  /**
   * THE PUT NO LONGER ANSWERS 405. It reaches the endpoint, runs the gate and
   * — with no body to validate — stops at the first rule of SPEC 100's
   * validator, which is the proof that everything before it passed.
   */
  public function testThePutReachesTheEndpointInsteadOf405() {
    $result = $this->put();

    $this->assertError($result, 422, 'missing_field');
    // Rule 1 of SPEC 100's validator, reached and answered: an absent body IS
    // an absent `message`, with the field named in the translated text.
    $this->assertSame('Falta el campo requerido: message', $result['json']['error']);
    $this->assertSame([], myapi_test_node_saves(), 'an invalid body writes nothing');
  }

  /**
   * POST, PATCH and DELETE stay refused, and BEFORE the token: creating an
   * offer is the other route of this file, and a DELETE would promise a
   * disappearance that withdrawing does not perform.
   *
   * @dataProvider stillRefusedMethods
   */
  public function testPostPatchAndDeleteStayRefused($method) {
    $_SERVER['REQUEST_METHOD'] = $method;

    $result = $this->dispatch();

    $this->assertError($result, 405, 'method_not_allowed');
    $this->assertSame([], myapi_test_db_queries(), 'no query was run');
  }

  public function stillRefusedMethods() {
    return ['POST' => ['POST'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
  }

  /**
   * THE GET IS UNTOUCHED AND IS STILL THE RESIDENT'S. A provider — even the one
   * who bid, which is who this fixture authenticates — is answered 403
   * forbidden by myapi_service_request_viewer(), exactly as SPEC 103 left it,
   * and never 405 and never the PUT's provider_role_required.
   */
  public function testTheGetStillServesTheResidentsDetailUntouched() {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = $this->put();

    $this->assertError($result, 403, 'forbidden');
  }

  /* ------------------------------------------------------------------------
   * The gate, through the endpoint, and always before the body.
   * --------------------------------------------------------------------- */

  /**
   * A nid that is not a positive integer answers 404 — before the token and
   * with no query.
   *
   * @dataProvider malformedIds
   */
  public function testAMalformedIdIs404BeforeTheToken($nid) {
    $result = myapi_test_capture(function () use ($nid) {
      myapi_service_offer_item_dispatch($nid);
    });

    $this->assertError($result, 404, 'not_found');
    $this->assertSame([], myapi_test_db_queries(), 'no query was run');
  }

  public function malformedIds() {
    return [
      'letters'  => ['abc'],
      'zero'     => ['0'],
      'negative' => ['-1'],
      'list'     => ['1,2'],
      'decimal'  => ['1.5'],
      'empty'    => [''],
      'null'     => [NULL],
    ];
  }

  public function testWithoutATokenItIs401() {
    $this->seedEndpoint();

    $this->assertError($this->dispatch(), 401, 'missing_authorization');
  }

  public function testAnInventedTokenIs401() {
    $this->seedEndpoint();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';

    $this->assertError($this->dispatch(), 401, 'invalid_token');
  }

  /**
   * A RESIDENT WHO PUTS THE URL OF AN OFFER THEY RECEIVED lands here: no
   * 'proveedor' role, 403, and before the offer is read.
   */
  public function testAnAccountWithoutTheProviderRoleIs403() {
    $result = $this->put([], [], [], ['authenticated user']);

    $this->assertError($result, 403, 'provider_role_required');
    $this->assertSame([], myapi_test_node_saves());
  }

  public function testAnOfferThatDoesNotExistIs404() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $this->seedEndpoint();

    $this->assertError($this->dispatch(self::OFFER_NID + 1), 404, 'not_found');
  }

  public function testAnUnpublishedOfferIs404() {
    $this->assertError($this->put(['status' => '0']), 404, 'not_found');
  }

  /**
   * A STRANGER'S OFFER ANSWERS 403 AND NEVER 422, with no body at all — which
   * is the criterion "who you are does not depend on what you wrote", asserted
   * where it can be.
   */
  public function testAnotherProvidersOfferIs403AndNever422() {
    $result = $this->put(['fp.field_provider_target_id' => (string) self::FOREIGN_PROVIDER]);

    $this->assertError($result, 403, 'service_offer_provider_not_owned');
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * An offer that is no longer 'sent' answers with THE EDIT'S 409 and never the
   * withdrawal's.
   */
  public function testAnOfferThatIsNotSentIs409NotEditable() {
    foreach (['selected', 'rejected', 'withdrawn'] as $status) {
      $result = $this->put(['fost.field_offer_status_value' => $status]);

      $this->assertError($result, 409, 'service_offer_not_editable');
      $this->assertSame([], myapi_test_node_saves(), $status);
    }
  }

  public function testARequestThatIsNotOfferableIs409() {
    foreach (['assigned', 'closed'] as $status) {
      $result = $this->put([], ['frs.field_request_status_value' => $status]);

      $this->assertError($result, 409, 'service_request_not_offerable');
    }
  }

  /* ------------------------------------------------------------------------
   * The licence, and the asymmetry between the two verbs.
   * --------------------------------------------------------------------- */

  /**
   * A LAPSED LICENCE BLOCKS EDITING with 403 — editing is sending a new quote,
   * and whoever may not operate does not quote.
   */
  public function testALapsedLicenceIs403OnThePut() {
    $result = $this->put([], [], [
      'fle.field_license_expiry_value' => (string) (REQUEST_TIME - 86400),
    ]);

    $this->assertError($result, 403, 'service_offer_provider_not_active');
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * THE SAME LAPSED LICENCE WITHDRAWS WITH 200 — decision 9, asserted across
   * the two endpoints over one fixture, which is the only place the asymmetry
   * is visible as behaviour and not as a code path.
   */
  public function testThatSameLapsedLicenceStillWithdraws() {
    $expired = ['fle.field_license_expiry_value' => (string) (REQUEST_TIME - 86400)];

    $this->assertError($this->put([], [], $expired), 403, 'service_offer_provider_not_active');

    $withdrawal = myapi_test_capture(function () {
      myapi_service_offer_withdraw_dispatch((string) self::OFFER_NID);
    });

    $this->assertSame(200, $withdrawal['status']);
    $this->assertSame('withdrawn', $withdrawal['json']['data']['service_offer']['status']);
  }

  /**
   * THE 409 OF THE REQUEST WINS OVER THE 403 OF THE LICENCE: condition 7 runs
   * before condition 8, through the endpoint and not only in the gate.
   */
  public function testTheRequestsStateAnswersBeforeTheLicence() {
    $result = $this->put(
      [],
      ['frs.field_request_status_value' => 'closed'],
      ['fle.field_license_expiry_value' => (string) (REQUEST_TIME - 86400)]
    );

    $this->assertError($result, 409, 'service_request_not_offerable');
  }

  /**
   * A SUSPENDED PROVIDER CANNOT EDIT, and the code says why: not_active, never
   * not_owned. The gate read the RAW column to know the offer is theirs, and
   * the licence check read the provider row that the raw column found.
   */
  public function testASuspendedProviderIsNotActiveAndNotUnowned() {
    $result = $this->put(
      ['np.nid' => NULL, 'np.title' => NULL, 'fml.uri' => NULL],
      [],
      ['status' => '0']
    );

    $this->assertError($result, 403, 'service_offer_provider_not_active');
  }

  /* ------------------------------------------------------------------------
   * What the endpoint is made of, read off its source.
   * --------------------------------------------------------------------- */

  /**
   * provider_id IS REFUSED EXPLICITLY, and BEFORE the validator that would drop
   * it in silence. The fixture cannot send a body, so the guard is asserted
   * over the source with the comments stripped — the technique
   * ServiceRequestUpdateTest uses for the guards a fixture cannot reach.
   */
  public function testProviderIdIsRefusedBeforeTheValidator() {
    $source = $this->updateSource();

    $guard = strpos($source, "array_key_exists('provider_id'");
    $validator = strpos($source, 'myapi_service_offer_validate_body');
    $gate = strpos($source, 'myapi_service_offer_update_gate');

    $this->assertNotFalse($guard, 'provider_id is refused explicitly');
    $this->assertNotFalse($validator);
    $this->assertNotFalse($gate);
    $this->assertLessThan($validator, $guard, 'the refusal comes before the validator');
    $this->assertLessThan($guard, $gate, 'and the whole gate comes before both');
  }

  /**
   * THE TWELVE VALUES ARE WRITTEN BY THE SHARED WRITER and not re-assigned here
   * (decision 16): duplicating them would guarantee that the POST and the PUT
   * store an offer differently within three months.
   */
  public function testTheTwelveValuesGoThroughTheSharedWriter() {
    $source = $this->updateSource();

    $this->assertStringContainsString('myapi_service_offer_apply_values($offer, $values)', $source);
    $this->assertStringNotContainsString('field_offer_warranty_days[LANGUAGE_NONE]', $source);
    $this->assertStringNotContainsString('field_offer_duration[LANGUAGE_NONE]', $source);
  }

  /**
   * AND THE SEVEN THE SERVER FIXED ARE NEVER RE-ASSIGNED: not the author, not
   * the created date, not the title, not the request, not the provider, and not
   * the offer's status — the PUT leaves it 'sent'.
   */
  public function testThePutNeverRewritesWhatTheServerFixed() {
    $source = $this->updateSource();

    foreach (['$offer->uid', '$offer->created', '$offer->title',
      '$offer->field_request', '$offer->field_provider',
      '$offer->field_offer_status'] as $assignment) {
      $this->assertStringNotContainsString($assignment, $source, $assignment . ' must not be written');
    }
  }

  /** The body of myapi_service_offer_update(), comments stripped. */
  private function updateSource() {
    $code = file_get_contents(__DIR__ . '/../../resources/service_offer.resource.inc');
    $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $code);

    $start = strpos($code, 'function myapi_service_offer_update($nid)');
    $this->assertNotFalse($start, 'the endpoint exists');

    // Bounded at the NEXT function: this file grows, and a scan that ran to
    // the end of it would read the neighbours' code as if it were this one's.
    $end = strpos($code, "\nfunction ", $start + 1);

    return $end === FALSE ? substr($code, $start) : substr($code, $start, $end - $start);
  }
}
