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
 * Unit tests for withdrawing an offer — PUT .../service-offers/{id}/withdraw
 * (SPEC 105).
 *
 * THE SHARED GATE IS EXERCISED HERE, in full, with the withdrawal's code.
 * myapi_service_offer_write_gate() is the four conditions the two verbs of
 * SPEC 105 have in common, and ServiceOfferUpdateTest asserts the same four
 * once more THROUGH myapi_service_offer_update_gate(), which is the only proof
 * that the edit really delegates instead of asking its own questions.
 *
 * Pure: rows are fixtures, no site is booted, and every case of the matrix is
 * one call.
 */
class ServiceOfferWithdrawTest extends TestCase {

  /** The account's providers, as myapi_provider_role_provider_ids() answers. */
  private const OWNED = [41, 55];

  /** The code condition 6 answers with when the caller is the withdrawal. */
  private const NOT_WITHDRAWABLE = 'service_offer_not_withdrawable';

  /**
   * One row of myapi_service_offer_detail_row(), the way the query answers it:
   * every column a string, because that is what PDO hands back.
   */
  private function offer(array $values = []) {
    return (object) ($values + [
      'nid'         => '901',
      'provider_id' => '41',
      'provider_raw' => '41',
      'request_id'  => '128',
      'status'      => 'sent',
      'amount'      => '150.50',
      'created'     => '1787000000',
    ]);
  }

  /** One row of myapi_service_request_detail_row(), reduced to what gates. */
  private function request(array $values = []) {
    return (object) ($values + ['nid' => '128', 'status' => 'offered']);
  }

  private function gate($row, $request_row = NULL, array $owned = self::OWNED) {
    if ($request_row === NULL) {
      $request_row = $this->request();
    }

    return myapi_service_offer_write_gate($row, $owned, $request_row, self::NOT_WITHDRAWABLE);
  }

  /* -------------------------------------------------------------------------
   * The four shared conditions, in order.
   * ---------------------------------------------------------------------- */

  /**
   * All four pass: the gate answers NULL and the caller writes.
   */
  public function testAnOwnSentOfferOnALiveRequestPasses() {
    $this->assertNull($this->gate($this->offer()));
  }

  /**
   * 4. No such offer. The detail row already folded the four indistinguishable
   * reasons into one FALSE — missing, unpublished, another bundle, or its
   * request unpublished — and the gate adds nothing to them.
   */
  public function testAMissingOfferIsNotFound() {
    $this->assertSame('not_found', $this->gate(FALSE));
  }

  /**
   * 5. Somebody else's offer. Checked BEFORE the offer's own status, so a
   * stranger cannot tell a 'sent' offer from a 'withdrawn' one.
   */
  public function testAnotherProvidersOfferIsNotOwned() {
    $row = $this->offer(['provider_raw' => '77']);

    $this->assertSame('service_offer_provider_not_owned', $this->gate($row));
    $this->assertSame(
      'service_offer_provider_not_owned',
      $this->gate($this->offer(['provider_raw' => '77', 'status' => 'withdrawn'])),
      'ownership must answer before the offer status'
    );
  }

  /**
   * 5, and the whole of decision 4: the gate reads field_provider and NOT
   * node.uid. ANOTHER ACCOUNT OF THE SAME PROVIDER withdraws an offer a third
   * one sent — a company with two employees is not left with a frozen offer
   * because the one who sent it is on holiday.
   */
  public function testAnySiblingAccountOfTheSameProviderPasses() {
    // The offer belongs to provider 55, the account's second provider, and the
    // row carries no uid at all: the gate never had one to read.
    $this->assertNull($this->gate($this->offer(['provider_raw' => '55'])));
  }

  /**
   * 5, and the reason it reads `provider_raw`: A SUSPENDED PROVIDER STILL OWNS
   * ITS OFFERS. The joined column is NULL when the provider node is
   * unpublished, and gating on it would answer 403 to an account for its own
   * offer — the very damage this spec exists to undo.
   */
  public function testASuspendedProviderStillOwnsItsOffer() {
    $row = $this->offer(['provider_id' => NULL, 'provider_raw' => '41']);

    $this->assertNull($this->gate($row));
  }

  /**
   * 5. An offer with no provider at all is nobody's, and fails closed.
   */
  public function testAnOfferWithNoProviderIsNobodys() {
    $this->assertSame('service_offer_provider_not_owned', $this->gate($this->offer(['provider_raw' => NULL])));
    $this->assertSame('service_offer_provider_not_owned', $this->gate($this->offer(['provider_raw' => '0'])));
  }

  /**
   * 6. Only 'sent' may be written on. 'selected' is the resident's choice,
   * 'rejected' and 'withdrawn' are dead.
   *
   * @dataProvider unwritableStatuses
   */
  public function testOnlyASentOfferMayBeWithdrawn($status) {
    $this->assertSame(self::NOT_WITHDRAWABLE, $this->gate($this->offer(['status' => $status])));
  }

  public function unwritableStatuses() {
    return [
      'selected'  => ['selected'],
      'rejected'  => ['rejected'],
      'withdrawn' => ['withdrawn'],
      'empty'     => [''],
      'unknown'   => ['whatever'],
      'null'      => [NULL],
    ];
  }

  /**
   * 6, WITHDRAWING TWICE IS 409 AND NOT 200 — the literal precedent of SPEC 95,
   * whose cancellation documents itself as "NOT IDEMPOTENT, on purpose". The
   * second call did nothing, and a 200 would pretend otherwise.
   */
  public function testWithdrawingTwiceIsRefusedAndNotIdempotent() {
    $this->assertNull($this->gate($this->offer(['status' => 'sent'])));
    $this->assertSame(self::NOT_WITHDRAWABLE, $this->gate($this->offer(['status' => 'withdrawn'])));
  }

  /**
   * 6. The code is the CALLER'S: the same row, the same rule, two words. That
   * is the whole reason this function takes a code instead of a flag.
   */
  public function testTheCodeOfConditionSixIsTheCallers() {
    $row = $this->offer(['status' => 'rejected']);
    $request = $this->request();

    $this->assertSame(
      'service_offer_not_editable',
      myapi_service_offer_write_gate($row, self::OWNED, $request, 'service_offer_not_editable')
    );
    $this->assertSame(
      'service_offer_not_withdrawable',
      myapi_service_offer_write_gate($row, self::OWNED, $request, 'service_offer_not_withdrawable')
    );
  }

  /**
   * 7. The request must still be taking offers. 'open', 'offered' and 'direct'
   * are the three, and 'direct' is in because that is the case that made this
   * spec urgent: a quote on a job the resident handed the provider directly.
   *
   * @dataProvider offerableRequestStatuses
   */
  public function testTheThreeRequestStatusesThatAllowWriting($status) {
    $this->assertNull($this->gate($this->offer(), $this->request(['status' => $status])));
  }

  public function offerableRequestStatuses() {
    return ['open' => ['open'], 'offered' => ['offered'], 'direct' => ['direct']];
  }

  /**
   * 7. Anything else answers 409, and an unnameable status fails closed.
   *
   * @dataProvider unofferableRequestStatuses
   */
  public function testAnyOtherRequestStatusRefuses($status) {
    $this->assertSame(
      'service_request_not_offerable',
      $this->gate($this->offer(), $this->request(['status' => $status]))
    );
  }

  public function unofferableRequestStatuses() {
    return [
      'assigned'  => ['assigned'],
      'closed'    => ['closed'],
      'cancelled' => ['cancelled'],
      'empty'     => [''],
      'unknown'   => ['whatever'],
      'null'      => [NULL],
    ];
  }

  /**
   * 7. A request row that does not load at all fails closed too.
   */
  public function testAMissingRequestRowFailsClosed() {
    $this->assertSame('service_request_not_offerable', $this->gate($this->offer(), FALSE));
  }

  /* -------------------------------------------------------------------------
   * The order of the four, which is a contract of its own.
   * ---------------------------------------------------------------------- */

  /**
   * A CANCELLED REQUEST NEEDS NO RULE OF ITS OWN. Cancelling ran
   * myapi_service_request_reject_live_offers() (SPEC 95), which left the offer
   * 'rejected', so condition 6 answers before condition 7 is reached.
   */
  public function testACancelledRequestAnswersThroughTheOfferStatus() {
    $row = $this->offer(['status' => 'rejected']);

    $this->assertSame(self::NOT_WITHDRAWABLE, $this->gate($row, $this->request(['status' => 'cancelled'])));
  }

  /**
   * The four in order: each condition answers over every one below it.
   */
  public function testTheFirstConditionThatFailsIsTheOneThatAnswers() {
    // 4 over 5: no row, and the ids would not have matched either.
    $this->assertSame('not_found', $this->gate(FALSE, $this->request(['status' => 'closed'])));

    // 5 over 6 and 7.
    $this->assertSame(
      'service_offer_provider_not_owned',
      $this->gate($this->offer(['provider_raw' => '77', 'status' => 'withdrawn']), $this->request(['status' => 'closed']))
    );

    // 6 over 7.
    $this->assertSame(
      self::NOT_WITHDRAWABLE,
      $this->gate($this->offer(['status' => 'withdrawn']), $this->request(['status' => 'closed']))
    );
  }

  /**
   * The account with no provider at all — a resident who somehow reached the
   * gate — owns nothing.
   */
  public function testAnAccountWithNoProviderOwnsNothing() {
    $this->assertSame('service_offer_provider_not_owned', $this->gate($this->offer(), NULL, []));
  }

  /* =========================================================================
   * The endpoint, end to end.
   *
   * myapi_service_offer_withdraw_dispatch() is called the way hook_menu() calls
   * it, over a fixture `node` table, a fixture my_api_tokens row, a fixture
   * account carrying its roles and a fixture Authorization header. What is
   * asserted is the JSON body the module prints, the status it sets and the
   * node the resource asked to save.
   *
   * WHAT THIS SUITE DOES NOT PROVE, all of it the database's half: that
   * Drupal's router resolves 'api/v1/service-offers/%/withdraw' before
   * 'api/v1/service-offers/provider/%' (hook_menu() is not run here — that is
   * the manual criterion of step 4), and that node_save() really stored
   * anything: the stub is a RECORDER, so a green case says "the resource asked
   * for this node", never "Drupal wrote it".
   * ====================================================================== */

  const TOKEN = 'a-valid-access-token';
  const UID = 7;
  const OFFER_NID = 901;
  const REQUEST_NID = 128;
  const PROVIDER_NID = 41;
  const FOREIGN_PROVIDER = 77;
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
   * Fixtures.
   * --------------------------------------------------------------------- */

  /**
   * One offer row, flat, as every join of myapi_service_offer_detail_row()
   * delivers it. The published flag of the node travels as `status` and the
   * offer's own status under its QUALIFIED source, because a flat row cannot
   * hold both and the fixture resolves the qualified name first.
   */
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
      'fvu.field_offer_valid_until_value'    => '1756771199',
      'faf.field_offer_available_from_value' => '1756285200',
      'fdu.field_offer_duration_value'       => '3',
      'fdn.field_offer_duration_unit_value'  => 'hours',
      'fin.field_offer_includes_value'       => 'Mano de obra.',
      'fex.field_offer_excludes_value'       => 'El calentador.',
      'fti.field_offer_tax_included_value'   => '1',
      'fwd.field_offer_warranty_days_value'  => '90',
      'frv.field_offer_requires_visit_value' => '0',
    ];
  }

  /** The request row, flat, as myapi_service_request_detail_row() reads it. */
  private function requestRow(array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) self::REQUEST_NID,
      'type'                           => MYAPI_SERVICES_REQUEST_TYPE,
      'status'                         => '1',
      'title'                          => 'Fuga en el calentador',
      'created'                        => (string) self::CREATED,
      'fr.field_requester_target_id'   => '314',
      'requester_uid'                  => '314',
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      'fcat.field_category_tid'        => '9',
      'category_code'                  => 'plumbing',
      'category_name'                  => 'Plomería',
    ];
  }

  private function providerNode($nid = self::PROVIDER_NID) {
    return [
      'nid'    => (string) $nid,
      'type'   => MYAPI_SERVICES_PROVIDER_TYPE,
      'status' => '1',
      'title'  => 'Plomería Torres',
    ];
  }

  /** One row of field_data_field_provider_users: the account -> provider link. */
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
   * The loaded offer node the endpoint writes on. Only what a withdrawal reads
   * or touches is shaped: everything else is left out precisely because the
   * endpoint must not invent it.
   */
  private function offerNode(array $overrides = []) {
    myapi_test_node_seed([self::OFFER_NID => $overrides + [
      'nid'    => self::OFFER_NID,
      'type'   => MYAPI_SERVICES_OFFER_TYPE,
      'uid'    => 33,
      'status' => 1,
      'title'  => 'Oferta de Plomería Torres — solicitud #128',
      'created' => self::CREATED,
      'field_request'      => [LANGUAGE_NONE => [['target_id' => self::REQUEST_NID]]],
      'field_provider'     => [LANGUAGE_NONE => [['target_id' => self::PROVIDER_NID]]],
      'field_offer_status' => [LANGUAGE_NONE => [['value' => 'sent']]],
      'field_offer_amount' => [LANGUAGE_NONE => [['value' => '150.50']]],
      'field_offer_message' => [LANGUAGE_NONE => [['value' => 'Puedo pasar el jueves por la mañana.']]],
    ]]);
  }

  /**
   * Seeds a whole scenario in one call: every myapi_test_db_seed() replaces the
   * entire fixture, so nothing can be added afterwards.
   */
  private function seed(array $offer_overrides = [], array $request_overrides = [], $links = NULL, $roles = NULL) {
    $roles = $roles === NULL ? ['authenticated user', MYAPI_PROVIDER_ROLE] : $roles;
    $links = $links === NULL ? [$this->link(self::PROVIDER_NID)] : $links;

    $GLOBALS['myapi_test_users'][self::UID] = [
      'uid'    => self::UID,
      'name'   => 'proveedor' . self::UID,
      'status' => 1,
      'roles'  => $roles,
    ];

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow()],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => $links,
      'node' => [
        $this->offerRow($offer_overrides),
        $this->requestRow($request_overrides),
        $this->providerNode(),
      ],
    ]);
    myapi_test_static_reset();
    $this->offerNode();
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  private function dispatch($nid = self::OFFER_NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_offer_withdraw_dispatch((string) $nid);
    });
  }

  /** Authenticates, seeds and runs: the happy path every case starts from. */
  private function withdraw(array $offer_overrides = [], array $request_overrides = [], $links = NULL, $roles = NULL) {
    $this->authenticate();
    $this->seed($offer_overrides, $request_overrides, $links, $roles);

    return $this->dispatch();
  }

  private function assertError(array $result, $status, $error_code) {
    $this->assertSame($status, $result['status']);
    $this->assertFalse($result['json']['success']);
    $this->assertSame($error_code, $result['json']['error_code']);
  }

  /* ------------------------------------------------------------------------
   * The dispatcher: PUT and nothing else, before the token.
   * --------------------------------------------------------------------- */

  /**
   * Every method but PUT answers 405 — and BEFORE the token and before a single
   * query, the criterion every dispatcher of this module follows.
   *
   * @dataProvider refusedMethods
   */
  public function testEveryMethodButPutIsRefusedBeforeTheToken($method) {
    $_SERVER['REQUEST_METHOD'] = $method;

    $result = $this->dispatch();

    $this->assertError($result, 405, 'method_not_allowed');
    $this->assertSame([], myapi_test_db_queries(), 'no query was run');
    $this->assertSame([], myapi_test_node_saves(), 'nothing was saved');
  }

  public function refusedMethods() {
    return [
      'GET'    => ['GET'],
      'POST'   => ['POST'],
      'PATCH'  => ['PATCH'],
      'DELETE' => ['DELETE'],
    ];
  }

  /* ------------------------------------------------------------------------
   * The gate, through the endpoint.
   * --------------------------------------------------------------------- */

  /**
   * A nid that is not a positive integer answers 404 — before the token and
   * with no query, so the shape of a URL never costs a round trip.
   *
   * @dataProvider malformedIds
   */
  public function testAMalformedIdIs404BeforeTheToken($nid) {
    $result = myapi_test_capture(function () use ($nid) {
      myapi_service_offer_withdraw_dispatch($nid);
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

  /** No Authorization header at all. */
  public function testWithoutATokenItIs401() {
    $this->seed();

    $result = $this->dispatch();

    $this->assertError($result, 401, 'missing_authorization');
    $this->assertSame([], myapi_test_node_saves());
  }

  /** A token nobody issued. */
  public function testAnInventedTokenIs401() {
    $this->seed();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';

    $result = $this->dispatch();

    $this->assertError($result, 401, 'invalid_token');
  }

  /**
   * An account without the 'proveedor' role is refused BEFORE the offer is
   * read: who you are does not depend on which nid you asked for. A resident
   * who PUTs the URL of an offer they received lands here.
   */
  public function testAnAccountWithoutTheProviderRoleIs403() {
    $result = $this->withdraw([], [], NULL, ['authenticated user']);

    $this->assertError($result, 403, 'provider_role_required');
    $this->assertSame([], myapi_test_node_saves());
  }

  /** An offer that is not there. */
  public function testAnOfferThatDoesNotExistIs404() {
    $this->authenticate();
    $this->seed();

    $result = $this->dispatch(self::OFFER_NID + 1);

    $this->assertError($result, 404, 'not_found');
  }

  /** An unpublished offer is 404 and not 403: the servable set decides first. */
  public function testAnUnpublishedOfferIs404() {
    $result = $this->withdraw(['status' => '0']);

    $this->assertError($result, 404, 'not_found');
  }

  /** Somebody else's offer. */
  public function testAnotherProvidersOfferIs403() {
    $result = $this->withdraw([
      'fp.field_provider_target_id' => (string) self::FOREIGN_PROVIDER,
    ]);

    $this->assertError($result, 403, 'service_offer_provider_not_owned');
    $this->assertSame([], myapi_test_node_saves());
  }

  /** An offer that is no longer 'sent'. */
  public function testAnOfferThatIsNotSentIs409() {
    foreach (['selected', 'rejected', 'withdrawn'] as $status) {
      $result = $this->withdraw(['fost.field_offer_status_value' => $status]);

      $this->assertError($result, 409, 'service_offer_not_withdrawable');
      $this->assertSame([], myapi_test_node_saves(), $status);
    }
  }

  /** A request that is no longer taking offers. */
  public function testARequestThatIsNotOfferableIs409() {
    foreach (['assigned', 'closed'] as $status) {
      $result = $this->withdraw([], ['frs.field_request_status_value' => $status]);

      $this->assertError($result, 409, 'service_request_not_offerable');
      $this->assertSame([], myapi_test_node_saves(), $status);
    }
  }

  /* ------------------------------------------------------------------------
   * The happy path.
   * --------------------------------------------------------------------- */

  /**
   * 200, the offer under 'service_offer' with 'withdrawn' as its status, the
   * request as a SIBLING with its status UNMOVED, and the translated message.
   */
  public function testAWithdrawalAnswers200WithTheWholeOffer() {
    $result = $this->withdraw();

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertSame('Oferta retirada correctamente.', $result['json']['message']);

    $offer = $result['json']['data']['service_offer'];
    $this->assertSame(self::OFFER_NID, $offer['id']);
    $this->assertSame('withdrawn', $offer['status']);
    $this->assertSame(150.5, $offer['amount']);
    $this->assertSame('Puedo pasar el jueves por la mañana.', $offer['message']);

    // The provider's logo DOES travel here (decision 17): it came free in the
    // row the gate needed anyway.
    $this->assertSame(self::PROVIDER_NID, $offer['provider']['id']);
    $this->assertSame('Plomería Torres', $offer['provider']['name']);
    $this->assertNotNull($offer['provider']['logo']);

    // `request` is a sibling and carries the status WITHOUT moving it.
    $this->assertSame(self::REQUEST_NID, $result['json']['data']['request']['id']);
    $this->assertSame('offered', $result['json']['data']['request']['status']);
  }

  /**
   * THE FIFTEEN KEYS ARE THE SERIALISER'S, whole and in order — the same object
   * the offer's own detail answers for this nid a second later, because it
   * comes out of the same function over the same row.
   */
  public function testTheOfferIsTheSerialisersFifteenKeys() {
    $result = $this->withdraw();

    $row = myapi_service_offer_detail_row(self::OFFER_NID);
    $row->status = 'withdrawn';

    $this->assertSame(
      myapi_service_offer_build($row),
      $result['json']['data']['service_offer']
    );
  }

  /**
   * ONE FIELD IS WRITTEN AND ONE NODE IS SAVED. field_offer_status becomes
   * 'withdrawn' and nothing else on the offer changes — not the author, not the
   * created date, not the title, not the request, not the provider, not the
   * amount.
   */
  public function testOnlyTheStatusIsWrittenAndOnlyTheOfferIsSaved() {
    $this->withdraw();

    $saves = myapi_test_node_saves();
    $this->assertCount(1, $saves, 'the offer, and nothing else');

    $saved = $saves[0];
    $this->assertSame(self::OFFER_NID, $saved->nid);
    $this->assertSame(MYAPI_SERVICES_OFFER_TYPE, $saved->type);
    $this->assertSame('withdrawn', $saved->field_offer_status[LANGUAGE_NONE][0]['value']);

    // Everything the withdrawal must not touch.
    $this->assertSame(33, $saved->uid, 'the author is history and is not rewritten');
    $this->assertSame(self::CREATED, $saved->created);
    $this->assertSame('Oferta de Plomería Torres — solicitud #128', $saved->title);
    $this->assertSame(self::REQUEST_NID, $saved->field_request[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(self::PROVIDER_NID, $saved->field_provider[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('150.50', $saved->field_offer_amount[LANGUAGE_NONE][0]['value']);
  }

  /**
   * NOTHING ELSE MOVES: the request is not saved (decision 6) and no
   * service_transaction is written (decision 7). One save, and it is the offer.
   */
  public function testNeitherTheRequestNorATransactionIsWritten() {
    $this->withdraw();

    foreach (myapi_test_node_saves() as $saved) {
      $this->assertSame(MYAPI_SERVICES_OFFER_TYPE, $saved->type);
    }
  }

  /**
   * A BODY IS IGNORED ENTIRELY — with keys, empty, or malformed JSON — and the
   * proof is that THE ENDPOINT NEVER READS ONE. There is no reason field
   * (decision 8) and no key this endpoint would know what to do with, so
   * nothing is parsed and nothing can fail.
   *
   * Asserted over the source with the comments stripped, the technique
   * ServiceRequestUpdateTest uses for the guards a fixture cannot reach: a
   * docblock that merely mentions the rule cannot satisfy it.
   */
  public function testTheEndpointNeverReadsTheBody() {
    $source = $this->withdrawSource();

    $this->assertStringNotContainsString('myapi_request_body', $source);
    $this->assertStringNotContainsString('$_POST', $source);
    $this->assertStringNotContainsString('json_decode', $source);
    $this->assertStringNotContainsString('php://input', $source);
  }

  /**
   * And it never reads the licence either: it calls the SHARED gate and not the
   * edit's, which is the mechanism behind decision 9 and not a coincidence of
   * the fixture below.
   */
  public function testTheEndpointCallsTheSharedGateAndNotTheEdits() {
    $source = $this->withdrawSource();

    $this->assertStringContainsString('myapi_service_offer_write_gate', $source);
    $this->assertStringNotContainsString('myapi_service_offer_update_gate', $source);
    $this->assertStringNotContainsString('myapi_services_provider_is_active', $source);
    $this->assertStringNotContainsString('myapi_service_offer_provider_row', $source);
  }

  /**
   * The body of myapi_service_offer_withdraw(), comments stripped.
   */
  private function withdrawSource() {
    $code = file_get_contents(__DIR__ . '/../../resources/service_offer.resource.inc');
    $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $code);

    $start = strpos($code, 'function myapi_service_offer_withdraw($nid)');
    $this->assertNotFalse($start, 'the endpoint exists');

    return substr($code, $start);
  }

  /**
   * A PROVIDER WITH A LAPSED LICENCE WITHDRAWS WITH 200 — decision 9, and the
   * asymmetry that is the point of the two gates. The endpoint never reads the
   * licence at all: it calls the SHARED gate and not the edit's.
   */
  public function testASuspendedProviderStillWithdrawsWith200() {
    // The provider node is unpublished, so the joined column is NULL and the
    // offer answers `provider: null` — and the gate, which reads the RAW
    // column, still says it is theirs.
    $result = $this->withdraw([
      'np.nid'   => NULL,
      'np.title' => NULL,
      'fml.uri'  => NULL,
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame('withdrawn', $result['json']['data']['service_offer']['status']);
    $this->assertNull($result['json']['data']['service_offer']['provider']);
  }

  /**
   * ANOTHER ACCOUNT OF THE SAME PROVIDER withdraws an offer a third one sent:
   * the gate reads field_provider and never node.uid (decision 4). The fixture
   * node's uid is 33 and the token's is 7.
   */
  public function testASiblingAccountOfTheSameProviderWithdraws() {
    $result = $this->withdraw();

    $this->assertSame(200, $result['status']);
    $this->assertSame(33, myapi_test_node_saves()[0]->uid);
  }
}
