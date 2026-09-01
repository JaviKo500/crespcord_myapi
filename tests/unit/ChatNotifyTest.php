<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.flood.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_notification.inc';
require_once __DIR__ . '/../../includes/myapi.firebase.inc';
require_once __DIR__ . '/../../includes/myapi.chat.inc';
require_once __DIR__ . '/../../resources/chat.resource.inc';

/**
 * Unit tests for the new-message notice (SPEC 116) — the second half of
 * includes/myapi.chat.inc and POST /api/v1/chat/threads/%/notify.
 *
 * THE ONE ASSERTION THIS WHOLE FILE IS REALLY ABOUT is the one that cannot be
 * written as a value: THE MESSAGE NEVER REACHES THIS SERVER. It is asserted the
 * only way a negative can be — by reflection over
 * myapi_chat_message_push_body(), which has ONE parameter and it is the
 * request's title, so there is no door for the text to come in through. A value
 * assertion would only prove that today's caller does not pass it.
 *
 * THE MEMBERSHIP TABLE IS ASKED TWICE, ON PURPOSE. Every row of SPEC 115's
 * table is exercised here against myapi_chat_thread_row() and again in
 * ChatTokenTest against myapi_chat_offer_nids_for_uid(). That duplication IS the
 * test: since SPEC 116 both questions are built on
 * myapi_chat_thread_base_query(), and two suites reading the same fixtures
 * through two entry points is what would catch the day one of them stops
 * agreeing about what a thread is. If this file goes red on a row that
 * ChatTokenTest still passes, the refactor is what broke.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: joins are
 * recorded and never resolved, so one offer is one flat row carrying its own
 * columns plus the ones each join would have brought, under the qualified alias
 * the query names them by.
 *
 * WHAT THIS SUITE DOES NOT PROVE, and both are manual acceptance criteria
 * against a booted site:
 *
 *  - that the ON clause matching the offer's field_provider against the
 *    request's field_assigned_provider really excludes a stray 'sent' offer of
 *    another provider. The fixture records join conditions and never resolves
 *    them.
 *  - that the Flood API expires a window after 60 real seconds. What is
 *    asserted here is that the endpoint ASKS with the right event, identifier,
 *    threshold and window, and reacts correctly to both answers.
 *  - THAT A 'preview' IN THE BODY REACHES THE BANNER. myapi_request_body()
 *    reads php://input, which a unit test cannot write — the same limitation
 *    RequestValidationTest, AuthEndpointGuardsTest and ServiceOfferCreateTest
 *    already document. So the preview is exercised END TO END only through its
 *    pure functions (myapi_chat_message_preview() and
 *    myapi_chat_message_push_body(), both covered below case by case) plus the
 *    manual matrix of the spec. WHAT IS REACHABLE FROM HERE, and asserted, is
 *    the other half of the guarantee: with NO body — which is exactly what this
 *    environment gives — the endpoint answers the two-line banner it answered
 *    before the revision.
 */
class ChatNotifyTest extends TestCase {

  const RESIDENT_UID = 412;
  const PROVIDER_UID = 7;
  const PROVIDER_UID_2 = 8;
  const STRANGER_UID = 99;

  const PROVIDER_NID = 55;
  const OFFER_NID = 901;
  const REQUEST_NID = 380;
  const UNIT_NID = 61;
  const CONDOMINIUM_NID = 12;

  /**
   * A SECOND thread, of the SAME provider and the SAME resident. It is what
   * makes "silencing uid 7 on thread 901 does not silence uid 7 on thread 902"
   * an assertion rather than a hope.
   */
  const OTHER_OFFER_NID = 902;

  const REQUEST_TITLE = 'Fuga en el calentador';
  const PROVIDER_NAME = 'Ferretería El Tornillo';

  const TOKEN_RESIDENT = 'token-of-the-resident';
  const TOKEN_PROVIDER = 'token-of-the-provider';
  const TOKEN_PROVIDER_2 = 'token-of-the-other-employee';
  const TOKEN_STRANGER = 'token-of-a-stranger';

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
    myapi_test_http_reset();
    $GLOBALS['myapi_test_variables'] = [
      'myapi_onesignal_app_id'       => 'the-app-id',
      'myapi_onesignal_rest_api_key' => 'the-rest-key',
    ];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_flood_calls'] = [];
    $GLOBALS['myapi_test_flood_denied'] = [];
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Ana', 'last_name' => 'Pérez'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    unset($GLOBALS['myapi_test_profile_fields']);
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_flood_denied'] = [];
    $GLOBALS['myapi_test_flood_calls'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_variables'] = [];
    myapi_test_http_reset();
    myapi_test_static_reset();
    myapi_test_db_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One offer, as the joins of myapi_chat_thread_row() would deliver it.
   *
   * The defaults describe the commonest thread there is — an AWARDED request
   * whose winning offer says 'selected', with a home and a condominium. Every
   * case of the membership table overrides exactly one key.
   */
  private function offerRow(array $overrides = []) {
    return $overrides + [
      'fq.entity_type'                        => 'node',
      'fq.deleted'                            => 0,
      'no.nid'                                => self::OFFER_NID,
      'no.type'                               => MYAPI_SERVICES_OFFER_TYPE,
      'no.status'                             => 1,
      'fos.field_offer_status_value'          => MYAPI_SERVICES_OFFER_STATUS_SELECTED,
      'fp.field_provider_target_id'           => self::PROVIDER_NID,
      'rn.nid'                                => self::REQUEST_NID,
      'rn.type'                               => MYAPI_SERVICES_REQUEST_TYPE,
      'rn.status'                             => 1,
      'rn.title'                              => self::REQUEST_TITLE,
      'fap.field_assigned_provider_target_id' => self::PROVIDER_NID,
      'fr.field_requester_target_id'          => self::RESIDENT_UID,
      'fu.field_unit_target_id'               => self::UNIT_NID,
      'fco.field_condominium_target_id'       => self::CONDOMINIUM_NID,
    ];
  }

  /**
   * One row of field_data_field_provider_users, carrying BOTH shapes the two
   * readers of that table need: the target id myapi_provider_role_provider_ids()
   * reads, and the joined users columns myapi_service_request_provider_uids()
   * projects.
   */
  private function providerUserRow($uid, $status = 1) {
    return [
      'entity_id'   => self::PROVIDER_NID,
      'entity_type' => 'node',
      'deleted'     => 0,
      MYAPI_PROVIDER_USERS_FIELD . '_target_id' => $uid,
      'uid'         => $uid,
      'status'      => $status,
    ];
  }

  private function bothProviderAccounts() {
    return [
      $this->providerUserRow(self::PROVIDER_UID),
      $this->providerUserRow(self::PROVIDER_UID_2),
    ];
  }

  private function tokenRow($uid, $token) {
    return [
      'id'                => $uid,
      'uid'               => $uid,
      'access_token_hash' => myapi_token_hash($token),
      'revoked'           => 0,
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * Seeds a whole scenario in one call: myapi_test_db_seed() REPLACES the
   * fixture, so nothing can be added afterwards.
   */
  private function seed($offers = NULL, $provider_users = NULL) {
    $offers = $offers === NULL ? [$this->offerRow()] : $offers;
    $provider_users = $provider_users === NULL ? $this->bothProviderAccounts() : $provider_users;

    foreach ([self::RESIDENT_UID, self::PROVIDER_UID, self::PROVIDER_UID_2, self::STRANGER_UID] as $uid) {
      $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'name' => 'cuenta' . $uid, 'status' => 1];
    }

    myapi_test_db_seed([
      'field_data_field_request' => $offers,
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => $provider_users,
      'my_api_tokens' => [
        $this->tokenRow(self::RESIDENT_UID, self::TOKEN_RESIDENT),
        $this->tokenRow(self::PROVIDER_UID, self::TOKEN_PROVIDER),
        $this->tokenRow(self::PROVIDER_UID_2, self::TOKEN_PROVIDER_2),
        $this->tokenRow(self::STRANGER_UID, self::TOKEN_STRANGER),
      ],
      'node' => [
        ['nid' => self::PROVIDER_NID, 'title' => self::PROVIDER_NAME],
      ],
    ]);
    myapi_test_static_reset();
  }

  private function authenticate($token) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
  }

  private function dispatch($offer_nid = self::OFFER_NID) {
    return myapi_test_capture(function () use ($offer_nid) {
      myapi_chat_notify_dispatch((string) $offer_nid);
    });
  }

  /** Authenticates, seeds and runs: the happy path every case starts from. */
  private function notify($token = self::TOKEN_RESIDENT, $offer_nid = self::OFFER_NID, $offers = NULL, $provider_users = NULL) {
    $this->authenticate($token);
    $this->seed($offers, $provider_users);

    return $this->dispatch($offer_nid);
  }

  /** The payload of the single outgoing OneSignal call. */
  private function push($index = 0) {
    $requests = myapi_test_http_requests();
    $this->assertArrayHasKey($index, $requests, 'an outgoing push was expected');

    return $requests[$index]['payload'];
  }

  private function assertError(array $result, $status, $error_code) {
    $this->assertSame($status, $result['status']);
    $this->assertFalse($result['json']['success']);
    $this->assertSame($error_code, $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * myapi_chat_thread_row(), against the membership table of SPEC 115.
   *
   * The SAME table ChatTokenTest walks, read through the other entry point.
   * ---------------------------------------------------------------------- */

  /**
   * The commonest thread: an awarded request, its winning offer 'selected'.
   * Every one of the seven keys, because the push reads all seven.
   */
  public function testAnAwardedOfferIsAThreadWithItsWholeContext() {
    $this->seed();

    $this->assertSame([
      'offer_nid'      => self::OFFER_NID,
      'request_nid'    => self::REQUEST_NID,
      'request_title'  => self::REQUEST_TITLE,
      'requester_uid'  => self::RESIDENT_UID,
      'provider_id'    => self::PROVIDER_NID,
      'unit_id'        => self::UNIT_NID,
      'condominium_id' => self::CONDOMINIUM_NID,
    ], myapi_chat_thread_row(self::OFFER_NID));
  }

  /**
   * A DIRECT job (SPEC 101): field_assigned_provider written at birth and the
   * quote staying 'sent' forever. Gating on 'selected' alone would have left
   * every direct job with no chat and no push — silently.
   */
  public function testADirectOfferStuckOnSentIsAThread() {
    $this->seed([$this->offerRow(['fos.field_offer_status_value' => MYAPI_SERVICES_OFFER_STATUS_SENT])]);

    $row = myapi_chat_thread_row(self::OFFER_NID);
    $this->assertNotNull($row);
    $this->assertSame(self::OFFER_NID, $row['offer_nid']);
  }

  /**
   * A CLOSED request keeps its thread (SPEC 108): closing writes the status and
   * the rating and sweeps NO offer, so the winner still says 'selected'. There
   * is a warranty to claim, and a warranty you cannot write about is useless.
   */
  public function testAClosedRequestStillHasAThread() {
    $this->seed();

    $this->assertNotNull(myapi_chat_thread_row(self::OFFER_NID));
  }

  /**
   * The three ways an offer stops being live, and the one way a request stops
   * being awarded to it. Each is ONE overridden key, and each answers NULL.
   *
   * @dataProvider deadThreads
   */
  public function testAnOfferThatIsNotALiveThreadAnswersNull(array $overrides) {
    $this->seed([$this->offerRow($overrides)]);

    $this->assertNull(myapi_chat_thread_row(self::OFFER_NID));
  }

  public function deadThreads() {
    return [
      // The losers of an award (SPEC 106 sweeps them).
      'rejected' => [['fos.field_offer_status_value' => 'rejected']],
      // Taken back by the provider (SPEC 105).
      'withdrawn' => [['fos.field_offer_status_value' => 'withdrawn']],
      // A CANCELLED request (SPEC 95). No condition names a request status:
      // cancelling sweeps every live offer, so the row excludes itself. What is
      // seeded is what cancelling LEAVES BEHIND.
      'cancelled — every offer swept to rejected' => [['fos.field_offer_status_value' => 'rejected']],
      // field_request is shared with 'service_transaction' (SPEC 77): without
      // the bundle condition, a timeline entry would read as an offer.
      'a service_transaction, not an offer' => [['no.type' => 'service_transaction']],
      'the offer is unpublished' => [['no.status' => 0]],
      'the request is unpublished' => [['rn.status' => 0]],
    ];
  }

  /**
   * "AWARDED TO SOMEBODY ELSE" CANNOT BE ASSERTED AS A VALUE HERE, and pretending
   * otherwise would be worse than not testing it: the exclusion lives in the ON
   * clause of the fap join, and the fixture RECORDS join conditions without ever
   * resolving them, so a stray offer of another provider comes back from the
   * stub as a thread. It matters — SPEC 106 sweeps the losers with no
   * transaction, so a 'sent' offer of another provider can hang off an awarded
   * request for a moment.
   *
   * What CAN be asserted, and is, is that the condition is there and says what
   * it has to say. The behaviour itself is a manual acceptance criterion of the
   * spec against a booted site.
   */
  public function testTheAssignmentJoinDemandsTheOffersOwnProvider() {
    $this->seed();
    myapi_chat_thread_row(self::OFFER_NID);

    $queries = myapi_test_db_queries('field_data_field_request');
    $joins = array_values(array_filter($queries[0]['joins'], function ($join) {
      return $join['alias'] === 'fap';
    }));

    $this->assertCount(1, $joins);
    $this->assertSame('INNER', $joins[0]['type']);
    $this->assertStringContainsString(
      'fap.field_assigned_provider_target_id = fp.field_provider_target_id',
      $joins[0]['condition'],
      'the request must be awarded to THIS offer\'s provider'
    );
  }

  /**
   * An offer that simply is not there, and the two shapes of a nid that cannot
   * be one. All three answer NULL, which the endpoint turns into ONE 404.
   *
   * @dataProvider impossibleNids
   */
  public function testANidThatIsNotAThreadAnswersNull($nid) {
    $this->seed();

    $this->assertNull(myapi_chat_thread_row($nid));
  }

  public function impossibleNids() {
    return [
      'never existed' => [4242],
      'zero'          => [0],
      'negative'      => [-1],
      'not a number'  => ['abc'],
    ];
  }

  /**
   * A request created before the field_unit backfill of SPEC 86 STILL HAS A
   * THREAD. The two context joins are LEFT for exactly this: an INNER would
   * make those conversations vanish with no error and no log.
   */
  public function testARequestWithNoUnitRowStillHasAThread() {
    $this->seed([$this->offerRow(['fu.field_unit_target_id' => NULL])]);

    $row = myapi_chat_thread_row(self::OFFER_NID);
    $this->assertNotNull($row, 'a missing unit must not cost the thread');
    $this->assertNull($row['unit_id']);
    $this->assertSame(self::CONDOMINIUM_NID, $row['condominium_id']);
  }

  /* -------------------------------------------------------------------------
   * myapi_chat_thread_side().
   * ---------------------------------------------------------------------- */

  public function testTheRequesterIsTheResidentSide() {
    $thread = ['requester_uid' => self::RESIDENT_UID, 'provider_id' => self::PROVIDER_NID];

    $this->assertSame('resident', myapi_chat_thread_side($thread, [self::PROVIDER_UID], self::RESIDENT_UID));
  }

  /**
   * BOTH employees are the provider side. Membership is by company.
   *
   * @dataProvider providerAccounts
   */
  public function testEitherEmployeeIsTheProviderSide($uid) {
    $thread = ['requester_uid' => self::RESIDENT_UID, 'provider_id' => self::PROVIDER_NID];

    $this->assertSame('provider', myapi_chat_thread_side($thread, [self::PROVIDER_UID, self::PROVIDER_UID_2], $uid));
  }

  public function providerAccounts() {
    return ['first' => [self::PROVIDER_UID], 'second' => [self::PROVIDER_UID_2]];
  }

  public function testAnAccountOnNeitherSideIsNull() {
    $thread = ['requester_uid' => self::RESIDENT_UID, 'provider_id' => self::PROVIDER_NID];

    $this->assertNull(myapi_chat_thread_side($thread, [self::PROVIDER_UID], self::STRANGER_UID));
  }

  /**
   * The plumber who lives in the building he works for: BOTH the resident and
   * an employee of the awarded provider. He counts as the RESIDENT, because the
   * thread hangs off HIS request — and the alternative has him writing to
   * himself.
   */
  public function testAnAccountThatIsBothSidesCountsAsTheResident() {
    $thread = ['requester_uid' => self::PROVIDER_UID, 'provider_id' => self::PROVIDER_NID];

    $this->assertSame('resident', myapi_chat_thread_side($thread, [self::PROVIDER_UID], self::PROVIDER_UID));
  }

  /* -------------------------------------------------------------------------
   * myapi_chat_notify_recipients().
   * ---------------------------------------------------------------------- */

  public function testWhenTheResidentWritesBothEmployeesAreTold() {
    $thread = ['requester_uid' => self::RESIDENT_UID];

    $this->assertSame(
      [self::PROVIDER_UID, self::PROVIDER_UID_2],
      myapi_chat_notify_recipients($thread, [self::PROVIDER_UID, self::PROVIDER_UID_2], 'resident', self::RESIDENT_UID)
    );
  }

  public function testWhenAnEmployeeWritesOnlyTheResidentIsTold() {
    $thread = ['requester_uid' => self::RESIDENT_UID];

    $this->assertSame(
      [self::RESIDENT_UID],
      myapi_chat_notify_recipients($thread, [self::PROVIDER_UID, self::PROVIDER_UID_2], 'provider', self::PROVIDER_UID)
    );
  }

  /**
   * The colleague gets NOTHING when their teammate writes: the message came
   * from their company, and they both already see the thread. It falls out of
   * "the other side" with no line written for it.
   */
  public function testAColleagueIsNotToldWhenTheirTeammateWrites() {
    $thread = ['requester_uid' => self::RESIDENT_UID];
    $recipients = myapi_chat_notify_recipients($thread, [self::PROVIDER_UID, self::PROVIDER_UID_2], 'provider', self::PROVIDER_UID);

    $this->assertNotContains(self::PROVIDER_UID_2, $recipients);
  }

  /**
   * THE SENDER IS NEVER IN THE LIST, not even on the pathological account that
   * is on both sides at once — which is the one case the explicit filter is
   * there for.
   */
  public function testTheSenderIsNeverARecipient() {
    $thread = ['requester_uid' => self::PROVIDER_UID];
    $recipients = myapi_chat_notify_recipients($thread, [self::PROVIDER_UID, self::PROVIDER_UID_2], 'resident', self::PROVIDER_UID);

    $this->assertSame([self::PROVIDER_UID_2], $recipients);
  }

  /**
   * A side of NULL — an account that takes no part — addresses nobody.
   */
  public function testNoSideMeansNoRecipients() {
    $thread = ['requester_uid' => self::RESIDENT_UID];

    $this->assertSame([], myapi_chat_notify_recipients($thread, [self::PROVIDER_UID], NULL, self::STRANGER_UID));
  }

  /* -------------------------------------------------------------------------
   * myapi_chat_sender_label().
   * ---------------------------------------------------------------------- */

  /**
   * The COMPANY, never the employee: whoever hired talks to the provider, not
   * to a person whose name they have no reason to recognise.
   */
  public function testAProviderWritesUnderTheCompanyName() {
    $this->seed();

    $this->assertSame(
      self::PROVIDER_NAME,
      myapi_chat_sender_label(['provider_id' => self::PROVIDER_NID], 'provider', self::PROVIDER_UID)
    );
  }

  public function testAResidentWritesUnderTheirProfileName() {
    $this->seed();
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Ana', 'last_name' => 'Pérez'];

    $this->assertSame(
      'Ana Pérez',
      myapi_chat_sender_label(['requester_uid' => self::RESIDENT_UID], 'resident', self::RESIDENT_UID)
    );
  }

  /**
   * With no profile filled in, the account's own name — the exact fallback
   * SPEC 110 already uses for "Hola {name}", so one account is called one thing
   * everywhere.
   */
  public function testAResidentWithNoProfileFallsBackToTheAccountName() {
    $this->seed();
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => NULL, 'last_name' => NULL];

    $this->assertSame(
      'cuenta' . self::RESIDENT_UID,
      myapi_chat_sender_label(['requester_uid' => self::RESIDENT_UID], 'resident', self::RESIDENT_UID)
    );
  }

  /**
   * A deleted provider node degrades to NULL rather than throwing; the title
   * builder turns it into the same dash every other notice of the module uses.
   */
  public function testAnUnresolvableProviderIsNull() {
    $this->seed();

    $this->assertNull(myapi_chat_sender_label(['provider_id' => 4242], 'provider', self::PROVIDER_UID));
  }

  /* -------------------------------------------------------------------------
   * The two text builders.
   * ---------------------------------------------------------------------- */

  public function testTheTitleNamesTheSender() {
    $this->assertSame('Nuevo mensaje de ' . self::PROVIDER_NAME, myapi_chat_message_push_title(self::PROVIDER_NAME));
  }

  public function testTheTitleDegradesAnUnresolvedSender() {
    $this->assertSame(
      'Nuevo mensaje de ' . MYAPI_SERVICE_REQUEST_MAIL_EMPTY,
      myapi_chat_message_push_title(NULL)
    );
  }

  public function testTheBodyIsTheRequestTitle() {
    $this->assertSame('Solicitud: ' . self::REQUEST_TITLE, myapi_chat_message_push_body(self::REQUEST_TITLE));
  }

  /**
   * THE DOOR, AND ONLY THE DOOR. Until the revision of SPEC 116 this test said
   * the body builder had ONE parameter and therefore no way in for the message;
   * the preview gave it one, so what is pinned now is that there is EXACTLY ONE
   * and that it is the preview — not the raw body, not an array of the request,
   * not anything a future edit could widen without this going red.
   *
   * It is still written as reflection, and still for the same reason: it is a
   * statement about the SHAPE of the function, which no assertion over values
   * can make.
   */
  public function testTheBodyTakesTheRequestTitleAndOnlyThePreview() {
    $builder = new ReflectionFunction('myapi_chat_message_push_body');
    $parameters = $builder->getParameters();

    $this->assertSame(2, $builder->getNumberOfParameters());
    $this->assertSame('request_title', $parameters[0]->getName());
    $this->assertSame('preview', $parameters[1]->getName());
    $this->assertTrue($parameters[1]->isOptional(), 'a client that sends none still gets a banner');
    $this->assertNull($parameters[1]->getDefaultValue());
  }

  /**
   * With no preview the body is byte for byte the one from before the revision.
   * THE COMPATIBILITY GUARANTEE, asserted rather than assumed.
   *
   * @dataProvider absentPreviews
   */
  public function testWithNoPreviewTheBodyIsTheOneFromBeforeTheRevision($preview) {
    $this->assertSame(
      'Solicitud: ' . self::REQUEST_TITLE,
      myapi_chat_message_push_body(self::REQUEST_TITLE, $preview)
    );
  }

  public function absentPreviews() {
    return ['omitted' => [NULL], 'empty string' => ['']];
  }

  /**
   * With one, a third line — and the request's line SURVIVES. A provider with
   * five open jobs must still learn which of the five wrote.
   */
  public function testWithAPreviewTheBodyIsThreeLinesAndKeepsTheRequest() {
    $body = myapi_chat_message_push_body(self::REQUEST_TITLE, '¿Te viene bien el jueves?');

    $this->assertSame(
      "Solicitud: " . self::REQUEST_TITLE . "\n¿Te viene bien el jueves?",
      $body
    );
    $this->assertCount(2, explode("\n", $body), 'the title is the third line of the banner, added by the caller');
  }

  /* -------------------------------------------------------------------------
   * myapi_chat_message_preview() — the one door the text comes in through.
   * ---------------------------------------------------------------------- */

  public function testAPlainPreviewComesThroughUntouched() {
    $this->assertSame('¿Te viene bien el jueves?', myapi_chat_message_preview('¿Te viene bien el jueves?'));
  }

  /**
   * Anything that is not usable text answers NULL, and NOT ONE of these is a
   * branch of its own: myapi_text_to_plain() answers '' for every non-string,
   * and '' is the single condition here. This route answers no 422, so rubbish
   * is degraded and never refused.
   *
   * @dataProvider unusablePreviews
   */
  public function testAnUnusablePreviewIsNull($value) {
    $this->assertNull(myapi_chat_message_preview($value));
  }

  public function unusablePreviews() {
    return [
      'null'          => [NULL],
      'empty string'  => [''],
      'only spaces'   => ['     '],
      'only newlines' => ["\n\n"],
      'an integer'    => [42],
      'a float'       => [1.5],
      'a boolean'     => [TRUE],
      'an array'      => [['texto']],
      'markup only'   => ['<b></b>'],
    ];
  }

  /**
   * A NEWLINE BECOMES A SPACE, and this is the assertion that keeps the banner
   * at three lines. Without it a message with a line break would look like two
   * messages.
   */
  public function testANewlineInsideThePreviewBecomesASpace() {
    $this->assertSame('Hola qué tal', myapi_chat_message_preview("Hola\nqué tal"));
    $this->assertSame('Hola qué tal', myapi_chat_message_preview("Hola\r\n\r\n  qué tal  "));
  }

  public function testMarkupIsStrippedButItsTextIsKept() {
    $this->assertSame('alert(1)', myapi_chat_message_preview('<script>alert(1)</script>'));
    $this->assertSame('negrita', myapi_chat_message_preview('<b>negrita</b>'));
  }

  /**
   * The cut: 140 exactly survives, 141 comes back at 140 WITH the ellipsis
   * counted inside the limit.
   */
  public function testThePreviewIsCutAtOneHundredAndForty() {
    $this->assertSame(140, drupal_strlen(myapi_chat_message_preview(str_repeat('a', 140))));

    $cut = myapi_chat_message_preview(str_repeat('a', 141));
    $this->assertSame(140, drupal_strlen($cut));
    $this->assertStringEndsWith('...', $cut);
  }

  /**
   * An accented message is NOT split mid-character — the cut is multibyte-safe
   * because it is the module's own truncation, not a second one written here.
   */
  public function testAnAccentedPreviewIsNotSplitMidCharacter() {
    $cut = myapi_chat_message_preview(str_repeat('ñ', 200));

    $this->assertSame(140, drupal_strlen($cut));
    $this->assertSame($cut, mb_convert_encoding($cut, 'UTF-8', 'UTF-8'), 'still valid UTF-8');
  }

  /**
   * The chat's own limit did NOT move the one every other push uses.
   */
  public function testTruncatingWithNoLengthStillCutsAtTwoHundred() {
    $this->assertSame(200, drupal_strlen(myapi_onesignal_truncate_body(str_repeat('a', 300))));
  }

  /* -------------------------------------------------------------------------
   * The gate, through the endpoint.
   * ---------------------------------------------------------------------- */

  /**
   * The 405 is answered before the flood, before the token and before any
   * query.
   *
   * @dataProvider refusedMethods
   */
  public function testAnyMethodOtherThanPostIs405($method) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $this->authenticate(self::TOKEN_RESIDENT);
    $this->seed();

    $result = $this->dispatch();

    $this->assertError($result, 405, 'method_not_allowed');
    $this->assertSame([], myapi_test_db_queries(), 'no query was run');
    $this->assertSame([], $GLOBALS['myapi_test_flood_calls'], 'the flood was not even asked');
  }

  public function refusedMethods() {
    return [
      'GET'    => ['GET'],
      'PUT'    => ['PUT'],
      'PATCH'  => ['PATCH'],
      'DELETE' => ['DELETE'],
    ];
  }

  public function testNoAuthorizationHeaderIs401() {
    $this->seed();

    $this->assertError($this->dispatch(), 401, 'missing_authorization');
  }

  public function testAnUnknownTokenIs401() {
    $this->authenticate('not-a-real-token');
    $this->seed();

    $this->assertError($this->dispatch(), 401, 'invalid_token');
  }

  /**
   * The IP flood answers BEFORE the token, because until then there is no uid
   * to count against.
   */
  public function testTheIpFloodIs429BeforeTheToken() {
    $GLOBALS['myapi_test_flood_denied'] = ['myapi_chat_notify_ip:' . ip_address()];
    $this->seed();

    $this->assertError($this->dispatch(), 429, 'too_many_attempts');
  }

  /**
   * The IP counter is asked with the right event, threshold and window — a
   * swapped pair here is a silently wrong rate limit and no error anywhere.
   */
  public function testTheIpFloodIsAskedWithSixHundredPerHour() {
    $this->notify();

    $ip_calls = array_values(array_filter($GLOBALS['myapi_test_flood_calls'], function ($call) {
      return $call['event'] === 'myapi_chat_notify_ip';
    }));

    $this->assertSame('is_allowed', $ip_calls[0]['call']);
    $this->assertSame(600, $ip_calls[0]['threshold']);
    $this->assertSame(3600, $ip_calls[0]['window']);
    $this->assertSame(ip_address(), $ip_calls[0]['identifier']);
    $this->assertSame('register', $ip_calls[1]['call'], 'every call is counted, not only a failed one');
  }

  /**
   * TWO 404s WITH ONE BODY, and that is the point: an offer that does not exist
   * and a real thread that is not yours are indistinguishable from outside, so
   * the route cannot be used to enumerate live threads.
   */
  public function testAnUnknownThreadAndSomebodyElsesThreadAnswerTheSame404() {
    $this->authenticate(self::TOKEN_RESIDENT);
    $this->seed();
    $unknown = $this->dispatch(4242);

    $this->authenticate(self::TOKEN_STRANGER);
    $this->seed();
    $not_mine = $this->dispatch();

    $this->assertError($unknown, 404, 'not_found');
    $this->assertError($not_mine, 404, 'not_found');
    $this->assertSame($unknown['json'], $not_mine['json'], 'byte for byte the same body');
  }

  /**
   * A dead thread is a 404 too, through the very same NULL.
   *
   * @dataProvider deadThreads
   */
  public function testADeadThreadIs404(array $overrides) {
    $result = $this->notify(self::TOKEN_RESIDENT, self::OFFER_NID, [$this->offerRow($overrides)]);

    $this->assertError($result, 404, 'not_found');
    $this->assertSame([], myapi_test_http_requests(), 'nothing went out');
  }

  /**
   * A CLOSED request answers 200: the thread survives the close and so does the
   * notice.
   */
  public function testAClosedRequestStillNotifies() {
    $result = $this->notify();

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
  }

  /* -------------------------------------------------------------------------
   * The recipients, through the endpoint.
   * ---------------------------------------------------------------------- */

  public function testTheResidentWritingReachesBothEmployeesAndNotThemselves() {
    $result = $this->notify(self::TOKEN_RESIDENT);

    $this->assertSame(2, $result['json']['data']['recipients']);
    $this->assertSame(2, $result['json']['data']['notified']);
    $this->assertSame(0, $result['json']['data']['muted']);
    $this->assertSame(
      [(string) self::PROVIDER_UID, (string) self::PROVIDER_UID_2],
      $this->push()['include_external_user_ids']
    );
  }

  public function testAnEmployeeWritingReachesOnlyTheResident() {
    $result = $this->notify(self::TOKEN_PROVIDER);

    $this->assertSame(1, $result['json']['data']['recipients']);
    $this->assertSame([(string) self::RESIDENT_UID], $this->push()['include_external_user_ids']);
  }

  /**
   * A provider with no active account has nobody to tell. 200, recipients: 0,
   * notified: 0, and NOT ONE outgoing call.
   */
  public function testAProviderWithNoActiveAccountIsATwoHundredWithNobodyToTell() {
    $result = $this->notify(self::TOKEN_RESIDENT, self::OFFER_NID, NULL, []);

    $this->assertSame(200, $result['status']);
    $this->assertSame(0, $result['json']['data']['recipients']);
    $this->assertSame(0, $result['json']['data']['notified']);
    $this->assertSame([], myapi_test_http_requests());
  }

  /**
   * "No ACTIVE account" is not the same as "no account": a provider whose
   * employees are all blocked has nobody to tell either. users.status = 1 is
   * SPEC 109's rule and the chat inherits it rather than restating it.
   */
  public function testABlockedProviderAccountIsNeitherToldNorCounted() {
    $result = $this->notify(self::TOKEN_RESIDENT, self::OFFER_NID, NULL, [
      $this->providerUserRow(self::PROVIDER_UID, 0),
      $this->providerUserRow(self::PROVIDER_UID_2, 0),
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(0, $result['json']['data']['recipients']);
    $this->assertSame(0, $result['json']['data']['notified']);
    $this->assertSame([], myapi_test_http_requests());
  }

  /**
   * The thread path is derived and answered back, so the client can check it
   * talked about the thread it thought it was talking about.
   */
  public function testTheThreadPathIsAnsweredBack() {
    $result = $this->notify();

    $this->assertSame('service_offers/' . self::OFFER_NID, $result['json']['data']['thread']);
  }

  /* -------------------------------------------------------------------------
   * The push: the title, the body and the eight keys of the data.
   * ---------------------------------------------------------------------- */

  /**
   * The company when the provider writes.
   */
  public function testTheBannerNamesTheCompanyWhenTheProviderWrites() {
    $this->notify(self::TOKEN_PROVIDER);

    $this->assertSame('Nuevo mensaje de ' . self::PROVIDER_NAME, $this->push()['headings']['es']);
    $this->assertSame('Solicitud: ' . self::REQUEST_TITLE, $this->push()['contents']['es']);
  }

  /**
   * NO BODY, NO THIRD LINE — and this is the compatibility guarantee of the
   * revision, asserted at the level a unit test can reach: a client that sends
   * nothing gets exactly the banner it got before 'preview' existed.
   *
   * It is worth being precise about what this proves. It does NOT prove that a
   * preview arrives (php://input is unwritable here); it proves that the path
   * with no preview did not move, which is the half that could break already
   * published clients.
   */
  public function testWithNoBodyTheBannerIsTheTwoLineOneFromBeforeTheRevision() {
    $this->notify(self::TOKEN_PROVIDER);

    $this->assertSame('Solicitud: ' . self::REQUEST_TITLE, $this->push()['contents']['es']);
    $this->assertStringNotContainsString("\n", $this->push()['contents']['es']);
  }

  /**
   * The resident's own name when the resident writes — never the employee's,
   * and never the company's either.
   */
  public function testTheBannerNamesTheResidentWhenTheResidentWrites() {
    $this->notify(self::TOKEN_RESIDENT);

    $this->assertSame('Nuevo mensaje de Ana Pérez', $this->push()['headings']['es']);
  }

  /**
   * THE EIGHT KEYS, whole, towards the provider. 'unit' is null even though the
   * request HAS one, which is the assertion that keeps SPEC 109's rule from
   * being lost in a refactor: "a provider does not learn which home asked until
   * they open the detail endpoint".
   */
  public function testTheDataTowardsTheProviderCarriesTheCondominiumAndNeverTheUnit() {
    $this->notify(self::TOKEN_RESIDENT);

    $this->assertSame([
      'target'            => 'chat',
      'id'                => self::OFFER_NID,
      'thread'            => 'service_offers/' . self::OFFER_NID,
      'notification_type' => 'chat_message',
      'audience'          => 'provider',
      'provider'          => self::PROVIDER_NID,
      'condominium'       => self::CONDOMINIUM_NID,
      'unit'              => NULL,
    ], $this->push()['data']);
  }

  /**
   * Towards the resident BOTH context keys travel, with the real nids: an
   * account can hold more than one home, and without them the app opens the
   * thread in whichever house was last on screen.
   */
  public function testTheDataTowardsTheResidentCarriesBothTheUnitAndTheCondominium() {
    $this->notify(self::TOKEN_PROVIDER);
    $data = $this->push()['data'];

    $this->assertSame('resident', $data['audience'], "the RECIPIENT's audience, not the sender's");
    $this->assertSame(self::UNIT_NID, $data['unit']);
    $this->assertSame(self::CONDOMINIUM_NID, $data['condominium']);
  }

  /**
   * A request with no unit row still notifies, with unit: null to BOTH sides.
   */
  public function testARequestWithNoUnitStillNotifiesWithANullUnit() {
    $offers = [$this->offerRow(['fu.field_unit_target_id' => NULL])];

    // Towards the RESIDENT, who is the side that normally receives a unit.
    $to_resident = $this->notify(self::TOKEN_PROVIDER, self::OFFER_NID, $offers);
    $this->assertSame(200, $to_resident['status']);
    $this->assertSame(1, $to_resident['json']['data']['notified']);
    $this->assertSame('resident', $this->push()['data']['audience']);
    $this->assertNull($this->push()['data']['unit']);
    $this->assertSame(self::CONDOMINIUM_NID, $this->push()['data']['condominium']);

    // And towards the PROVIDER, where it was already null by rule.
    myapi_test_http_reset();
    $to_provider = $this->notify(self::TOKEN_RESIDENT, self::OFFER_NID, $offers);
    $this->assertSame(200, $to_provider['status']);
    $this->assertSame('provider', $this->push()['data']['audience']);
    $this->assertNull($this->push()['data']['unit']);
    $this->assertSame(self::CONDOMINIUM_NID, $this->push()['data']['condominium']);
  }

  /**
   * The banner carries the request's title and NOT ONE WORD MORE. Asserted over
   * the wire, because that is where a leak would show.
   */
  public function testNothingInTheOutgoingCallCarriesAMessage() {
    $this->notify(self::TOKEN_RESIDENT);
    $payload = $this->push();

    $this->assertSame(['en', 'es'], array_keys($payload['contents']));
    $this->assertSame('Solicitud: ' . self::REQUEST_TITLE, $payload['contents']['en']);
    $this->assertArrayNotHasKey('text', $payload['data']);
    $this->assertArrayNotHasKey('message', $payload['data']);
    $this->assertArrayNotHasKey('preview', $payload['data']);
  }

  /* -------------------------------------------------------------------------
   * Collapsing, grouping and TTL.
   * ---------------------------------------------------------------------- */

  public function testTheFourDeliveryOptionsTravel() {
    $this->notify();
    $payload = $this->push();

    $this->assertSame('chat_' . self::OFFER_NID, $payload['collapse_id']);
    $this->assertSame('service_offers/' . self::OFFER_NID, $payload['thread_id']);
    $this->assertSame('chat_' . self::OFFER_NID, $payload['android_group']);
    $this->assertSame(3600, $payload['ttl']);
  }

  /**
   * Five seconds and not thirty: what is protected by this synchronous call is
   * the PHP-FPM process, not a user — nobody is waiting for the response.
   */
  public function testTheOutgoingCallUsesTheShortTimeout() {
    $this->notify();
    $requests = myapi_test_http_requests();

    $this->assertSame(5, $requests[0]['options']['timeout']);
  }

  /* -------------------------------------------------------------------------
   * The debounce.
   * ---------------------------------------------------------------------- */

  /**
   * Asked with the composite identifier, threshold 1 and window 60 — and asked
   * once per recipient.
   */
  public function testTheDebounceIsAskedPerThreadAndRecipient() {
    $this->notify(self::TOKEN_RESIDENT);

    $checks = array_values(array_filter($GLOBALS['myapi_test_flood_calls'], function ($call) {
      return $call['event'] === 'myapi_chat_notify_thread' && $call['call'] === 'is_allowed';
    }));

    $this->assertCount(2, $checks);
    $this->assertSame(self::OFFER_NID . ':' . self::PROVIDER_UID, $checks[0]['identifier']);
    $this->assertSame(self::OFFER_NID . ':' . self::PROVIDER_UID_2, $checks[1]['identifier']);
    $this->assertSame(1, $checks[0]['threshold']);
    $this->assertSame(60, $checks[0]['window']);
  }

  /**
   * TWO NOTICES IN A ROW ON THE SAME THREAD, as a sequence and not as a
   * simulated answer: the first goes through, burns its window, and the second
   * — with the Flood API now holding exactly what the first one registered — is
   * silenced. ONE outgoing call for the two notices.
   *
   * This is also the idempotence the endpoint has without a line written for
   * it: an app that retries the POST silences its own duplicate.
   */
  public function testTwoNoticesInARowSilenceTheSecondWithOneOutgoingCall() {
    $this->authenticate(self::TOKEN_PROVIDER);
    $this->seed();

    $first = $this->dispatch();

    // What the {flood} table would hold now: every window the first call
    // actually burnt, and nothing else.
    foreach ($GLOBALS['myapi_test_flood_calls'] as $call) {
      if ($call['event'] === 'myapi_chat_notify_thread' && $call['call'] === 'register') {
        $GLOBALS['myapi_test_flood_denied'][] = $call['event'] . ':' . $call['identifier'];
      }
    }

    $second = $this->dispatch();

    $this->assertSame(1, $first['json']['data']['recipients']);
    $this->assertSame(1, $first['json']['data']['notified']);
    $this->assertSame(0, $first['json']['data']['muted']);

    $this->assertSame(200, $second['status']);
    $this->assertSame(1, $second['json']['data']['recipients']);
    $this->assertSame(0, $second['json']['data']['notified']);
    $this->assertSame(1, $second['json']['data']['muted']);

    $this->assertCount(1, myapi_test_http_requests(), 'one outgoing call for the two notices');
  }

  /**
   * A silenced recipient is a 'muted' and a 200 — never a 429, which is why the
   * pair reads myapi_flood_is_allowed() and not myapi_flood_check().
   */
  public function testASilencedRecipientIsMutedAndNotAnError() {
    $GLOBALS['myapi_test_flood_denied'] = [
      'myapi_chat_notify_thread:' . self::OFFER_NID . ':' . self::PROVIDER_UID,
      'myapi_chat_notify_thread:' . self::OFFER_NID . ':' . self::PROVIDER_UID_2,
    ];
    $result = $this->notify(self::TOKEN_RESIDENT);
    $data = $result['json']['data'];

    $this->assertSame(200, $result['status']);
    $this->assertSame(2, $data['recipients']);
    $this->assertSame(0, $data['notified']);
    $this->assertSame(2, $data['muted']);
    $this->assertSame([], myapi_test_http_requests(), 'not one outgoing call');
    $this->assertSame($data['recipients'], $data['notified'] + $data['muted']);
  }

  /**
   * SILENCING ONE DOES NOT SILENCE THE OTHER. One outgoing call, carrying only
   * the employee who is still allowed.
   */
  public function testSilencingOneRecipientLeavesTheOtherAlone() {
    $GLOBALS['myapi_test_flood_denied'] = [
      'myapi_chat_notify_thread:' . self::OFFER_NID . ':' . self::PROVIDER_UID,
    ];
    $result = $this->notify(self::TOKEN_RESIDENT);
    $data = $result['json']['data'];

    $this->assertSame(1, $data['notified']);
    $this->assertSame(1, $data['muted']);
    $this->assertSame($data['recipients'], $data['notified'] + $data['muted']);
    $this->assertCount(1, myapi_test_http_requests());
    $this->assertSame([(string) self::PROVIDER_UID_2], $this->push()['include_external_user_ids']);
  }

  /**
   * SILENCING A RECIPIENT ON ONE THREAD DOES NOT SILENCE THEM ON ANOTHER. The
   * identifier is composite for exactly this reason.
   */
  public function testSilencingAThreadDoesNotSilenceTheSamePersonElsewhere() {
    $GLOBALS['myapi_test_flood_denied'] = [
      'myapi_chat_notify_thread:' . self::OFFER_NID . ':' . self::PROVIDER_UID,
      'myapi_chat_notify_thread:' . self::OFFER_NID . ':' . self::PROVIDER_UID_2,
    ];

    $other = $this->offerRow([
      'no.nid'      => self::OTHER_OFFER_NID,
      'rn.nid'      => self::REQUEST_NID + 1,
      'rn.title'    => 'Persiana atascada',
    ]);
    $result = $this->notify(self::TOKEN_RESIDENT, self::OTHER_OFFER_NID, [$this->offerRow(), $other]);
    $data = $result['json']['data'];

    $this->assertSame('service_offers/' . self::OTHER_OFFER_NID, $data['thread']);
    $this->assertSame(2, $data['notified']);
    $this->assertSame(0, $data['muted']);
  }

  /**
   * THE WINDOW IS BURNT ONLY FOR THE PEOPLE WHO ACTUALLY GOT A BANNER, and only
   * after the call came back accepted.
   */
  public function testTheWindowIsRegisteredForEveryRecipientThatWasSent() {
    $this->notify(self::TOKEN_RESIDENT);

    $registers = array_values(array_filter($GLOBALS['myapi_test_flood_calls'], function ($call) {
      return $call['event'] === 'myapi_chat_notify_thread' && $call['call'] === 'register';
    }));

    $this->assertCount(2, $registers);
    $this->assertSame(self::OFFER_NID . ':' . self::PROVIDER_UID, $registers[0]['identifier']);
    $this->assertSame(60, $registers[0]['window']);
  }

  /* -------------------------------------------------------------------------
   * Best-effort: everything that can go wrong is still a 200.
   * ---------------------------------------------------------------------- */

  /**
   * OneSignal unconfigured: 200, notified: 0, a watchdog line — AND THE WINDOW
   * LEFT UNBURNT, so the next message tries again instead of a minute of outage
   * costing a minute of silence.
   */
  public function testUnconfiguredOneSignalIsATwoHundredThatDoesNotBurnTheWindow() {
    $GLOBALS['myapi_test_variables'] = [];
    $result = $this->notify(self::TOKEN_RESIDENT);

    $this->assertSame(200, $result['status']);
    $this->assertSame(2, $result['json']['data']['recipients']);
    $this->assertSame(0, $result['json']['data']['notified']);
    $this->assertSame(0, $result['json']['data']['muted']);
    $this->assertNotEmpty($GLOBALS['myapi_test_watchdog']);

    $registers = array_filter($GLOBALS['myapi_test_flood_calls'], function ($call) {
      return $call['event'] === 'myapi_chat_notify_thread' && $call['call'] === 'register';
    });
    $this->assertSame([], $registers, 'the window must stay open');
  }

  /**
   * OneSignal refusing the call: the same 200, and the same unburnt window. A
   * 5xx here would only make the app doubt a message that did arrive.
   */
  public function testAFailedOutgoingCallIsATwoHundredThatDoesNotBurnTheWindow() {
    $GLOBALS['myapi_test_http_code'] = 500;
    $result = $this->notify(self::TOKEN_RESIDENT);

    $this->assertSame(200, $result['status']);
    $this->assertSame(0, $result['json']['data']['notified']);

    $registers = array_filter($GLOBALS['myapi_test_flood_calls'], function ($call) {
      return $call['event'] === 'myapi_chat_notify_thread' && $call['call'] === 'register';
    });
    $this->assertSame([], $registers, 'the window must stay open');
  }

  /**
   * NOT ONE ROW anywhere: a chat message never reaches the inbox, because the
   * inbox has no way of learning it was read.
   */
  public function testNotOneRowIsWritten() {
    $this->notify(self::TOKEN_RESIDENT);

    // db_insert() THROWS in tests/unit, so a stray write would not merely fail
    // this assertion — it would blow up every case above. Both are wanted.
    $this->assertSame([], $GLOBALS['myapi_test_db_writes']);
  }

  /* -------------------------------------------------------------------------
   * Non-regression of myapi_onesignal_send().
   * ---------------------------------------------------------------------- */

  /**
   * WITHOUT $options THE REQUEST IS THE ONE OF BEFORE: the same five keys, in
   * the same order, and the same 30-second timeout. This is the guarantee that
   * SPEC 116 did not change one byte of a bulletin's push or of a service
   * notification's.
   */
  public function testSendingWithNoOptionsProducesTodaysPayload() {
    $GLOBALS['myapi_test_variables'] = [
      'myapi_onesignal_app_id'       => 'the-app-id',
      'myapi_onesignal_rest_api_key' => 'the-rest-key',
    ];

    $ok = myapi_onesignal_send([812], 'Aviso de la administración', 'El agua se corta el jueves.', [
      'target' => 'bulletin',
      'id'     => 812,
    ]);

    $requests = myapi_test_http_requests();
    $this->assertTrue($ok);
    $this->assertSame(30, $requests[0]['options']['timeout']);
    $this->assertSame([
      'app_id'                    => 'the-app-id',
      'include_external_user_ids' => ['812'],
      'headings'                  => ['en' => 'Aviso de la administración', 'es' => 'Aviso de la administración'],
      'contents'                  => ['en' => 'El agua se corta el jueves.', 'es' => 'El agua se corta el jueves.'],
      'data'                      => ['target' => 'bulletin', 'id' => 812],
    ], $requests[0]['payload']);
    $this->assertSame(
      ['app_id', 'include_external_user_ids', 'headings', 'contents', 'data'],
      array_keys($requests[0]['payload']),
      'not one key more, and in the same order'
    );
  }

  /**
   * An empty $options array is the same thing as no $options at all.
   */
  public function testAnEmptyOptionsArrayChangesNothing() {
    $GLOBALS['myapi_test_variables'] = [
      'myapi_onesignal_app_id'       => 'the-app-id',
      'myapi_onesignal_rest_api_key' => 'the-rest-key',
    ];

    myapi_onesignal_send([812], 'T', 'B', ['target' => 'bulletin'], []);
    $requests = myapi_test_http_requests();

    $this->assertSame(30, $requests[0]['options']['timeout']);
    $this->assertSame(
      ['app_id', 'include_external_user_ids', 'headings', 'contents', 'data'],
      array_keys($requests[0]['payload'])
    );
  }

  /**
   * A blank string and a non-positive ttl count as "not given": an empty
   * collapse_id would group every notification of the site into one.
   */
  public function testBlankOptionsAreNotSent() {
    $GLOBALS['myapi_test_variables'] = [
      'myapi_onesignal_app_id'       => 'the-app-id',
      'myapi_onesignal_rest_api_key' => 'the-rest-key',
    ];

    myapi_onesignal_send([812], 'T', 'B', [], [
      'collapse_id'   => '',
      'thread_id'     => '',
      'android_group' => '',
      'ttl'           => 0,
    ]);
    $payload = myapi_test_http_requests()[0]['payload'];

    $this->assertArrayNotHasKey('collapse_id', $payload);
    $this->assertArrayNotHasKey('thread_id', $payload);
    $this->assertArrayNotHasKey('android_group', $payload);
    $this->assertArrayNotHasKey('ttl', $payload);
  }

  /**
   * An unknown key is IGNORED and never forwarded, so a typo cannot reach
   * OneSignal as an unrecognised field.
   */
  public function testAnUnknownOptionIsIgnored() {
    $GLOBALS['myapi_test_variables'] = [
      'myapi_onesignal_app_id'       => 'the-app-id',
      'myapi_onesignal_rest_api_key' => 'the-rest-key',
    ];

    myapi_onesignal_send([812], 'T', 'B', [], ['colapse_id' => 'typo', 'priority' => 10]);
    $payload = myapi_test_http_requests()[0]['payload'];

    $this->assertArrayNotHasKey('colapse_id', $payload);
    $this->assertArrayNotHasKey('priority', $payload);
  }
}
