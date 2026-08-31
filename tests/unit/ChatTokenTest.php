<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.firebase.inc';
require_once __DIR__ . '/../../includes/myapi.chat.inc';

/**
 * Unit tests for the chat credential (SPEC 115) — includes/myapi.firebase.inc
 * and includes/myapi.chat.inc.
 *
 * Two halves, and they are tested for two different reasons.
 *
 * THE SIGNATURE. Four of the five functions of the Firebase file are pure, so
 * the seven fields of the JWT can be asserted with no site booted and no
 * credential. The fifth is covered by generating an RSA KEY PAIR INSIDE THE
 * TEST, signing with it and verifying with openssl_verify() over the exact
 * '<header>.<payload>' the token carries. That last one is the only assertion
 * that proves the bytes SIGNED are the bytes SENT — the classic way to hand out
 * a hand-rolled JWT that verifies nowhere, and a failure no assertion over
 * arrays can see.
 *
 * THE MEMBERSHIP, case by case against the table of SPEC 115. The rule is one
 * line — an assigned provider plus a live offer from that provider — and the
 * whole point of writing it that way is that CANCELLED and CLOSED come out
 * right with no condition written for either. The fixtures below therefore seed
 * WHAT CANCELLING AND CLOSING LEAVE BEHIND (every offer swept to 'rejected' /
 * every offer untouched) and never a request status, because nothing in the
 * production query reads one. A test that seeded field_request_status would be
 * testing a column the code does not look at.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: joins are
 * recorded and never resolved, so one offer is one flat row carrying its own
 * columns plus the ones each join would have brought, under the qualified alias
 * the query names them by. 'no.type' and 'rn.type' are seeded QUALIFIED because
 * the offer and its request would collide on the bare column.
 *
 * The two things this suite therefore does NOT prove, both of them the
 * database's half:
 *
 *  - that the join condition matching the offer's field_provider against the
 *    request's field_assigned_provider really excludes a stray 'sent' offer of
 *    another provider hanging off an awarded request. It lives in the ON
 *    clause, which the fixture records and never resolves.
 *  - that MySQL orders by the request's node.changed as the stub does.
 *
 * Both are manual acceptance criteria of SPEC 115 against a booted site.
 */
class ChatTokenTest extends TestCase {

  /**
   * The resident of every fixture request.
   */
  const RESIDENT_UID = 412;

  /**
   * Two accounts of the SAME provider company — field_provider_users is
   * multi-valued, and both must see the same thread.
   */
  const PROVIDER_UID = 7;
  const PROVIDER_UID_2 = 8;

  /**
   * An account that is neither, and belongs to no provider.
   */
  const STRANGER_UID = 99;

  /**
   * The provider node the requests below are awarded to.
   */
  const PROVIDER_NID = 55;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_variables'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
  }

  protected function tearDown(): void {
    $GLOBALS['myapi_test_variables'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    myapi_test_static_reset();
    myapi_test_db_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One offer, as the joins of myapi_chat_offer_nids_for_uid() would deliver
   * it: the offer's own columns, its provider, and its request's columns.
   *
   * Defaults describe the commonest thread there is — an AWARDED request whose
   * winning offer says 'selected'. Every case of the table overrides one key.
   */
  private function offerRow(array $overrides = []) {
    return $overrides + [
      'fq.entity_type'                       => 'node',
      'fq.deleted'                           => 0,
      'no.nid'                               => 901,
      'no.type'                              => MYAPI_SERVICES_OFFER_TYPE,
      'no.status'                            => 1,
      'fos.field_offer_status_value'         => MYAPI_SERVICES_OFFER_STATUS_SELECTED,
      'fp.field_provider_target_id'          => self::PROVIDER_NID,
      'rn.type'                              => MYAPI_SERVICES_REQUEST_TYPE,
      'rn.status'                            => 1,
      'rn.changed'                           => 1000,
      'fap.field_assigned_provider_target_id' => self::PROVIDER_NID,
      'fr.field_requester_target_id'         => self::RESIDENT_UID,
    ];
  }

  /**
   * One field_provider_users row: this account operates this provider.
   */
  private function providerUserRow($provider_nid, $uid) {
    return [
      'entity_id'                      => $provider_nid,
      'entity_type'                    => 'node',
      'deleted'                        => 0,
      'field_provider_users_target_id' => $uid,
    ];
  }

  /**
   * Seeds the two tables the membership queries read.
   */
  private function seed(array $offers, array $provider_users = []) {
    myapi_test_db_seed([
      'field_data_field_request'            => $offers,
      'field_data_field_provider_users'     => $provider_users,
    ]);
  }

  /**
   * The two accounts of PROVIDER_NID, as field_provider_users rows.
   */
  private function bothProviderAccounts() {
    return [
      $this->providerUserRow(self::PROVIDER_NID, self::PROVIDER_UID),
      $this->providerUserRow(self::PROVIDER_NID, self::PROVIDER_UID_2),
    ];
  }

  /**
   * A valid-looking service account, with a key that is NOT a real one: every
   * test that needs a signature generates its own pair.
   */
  private function fakeServiceAccount(array $overrides = []) {
    return $overrides + [
      'client_email' => 'firebase-adminsdk-abcde@my-project.iam.gserviceaccount.com',
      'private_key'  => "-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----\n",
    ];
  }

  /**
   * The inverse of myapi_firebase_base64url_encode(), for reading a token back.
   */
  private function base64urlDecode($segment) {
    $padded = str_pad(strtr($segment, '-_', '+/'), (int) (ceil(strlen($segment) / 4) * 4), '=');

    return base64_decode($padded);
  }

  /* -------------------------------------------------------------------------
   * base64url.
   * ---------------------------------------------------------------------- */

  /**
   * The two substitutions, on bytes chosen because plain base64 answers BOTH
   * offending characters for them. The precondition assertion is what makes
   * the fixture readable: '+//+' is what base64 says, '-__-' is what a JWT
   * needs.
   */
  public function testBase64urlReplacesBothOffendingCharacters() {
    $bytes = "\xfb\xff\xfe";

    $this->assertSame('+//+', base64_encode($bytes), 'fixture precondition');
    $this->assertSame('-__-', myapi_firebase_base64url_encode($bytes));
  }

  /**
   * The third replacement: padding is REMOVED, both when base64 adds one '='
   * and when it adds two.
   */
  public function testBase64urlStripsPadding() {
    $this->assertSame('+/8=', base64_encode("\xfb\xff"), 'fixture precondition');
    $this->assertSame('-_8', myapi_firebase_base64url_encode("\xfb\xff"));

    $this->assertSame('+w==', base64_encode("\xfb"), 'fixture precondition');
    $this->assertSame('-w', myapi_firebase_base64url_encode("\xfb"));
  }

  /**
   * The property the two tests above are really about, over a payload of the
   * shape this module actually encodes: not one of the three characters
   * survives.
   */
  public function testBase64urlOutputCarriesNoneOfTheThreeCharacters() {
    $encoded = myapi_firebase_base64url_encode(drupal_json_encode(
      myapi_firebase_custom_token_payload(412, 'a@b.iam.gserviceaccount.com', ['threads' => 'service_offers/901'], 1756698000)
    ));

    $this->assertStringNotContainsString('+', $encoded);
    $this->assertStringNotContainsString('/', $encoded);
    $this->assertStringNotContainsString('=', $encoded);
  }

  /**
   * An empty input encodes to an empty string rather than to padding.
   */
  public function testBase64urlOfEmptyStringIsEmpty() {
    $this->assertSame('', myapi_firebase_base64url_encode(''));
  }

  /* -------------------------------------------------------------------------
   * The payload.
   * ---------------------------------------------------------------------- */

  /**
   * In a custom token the issuer and the subject are the SAME service account.
   * Two different values there is a token Google refuses, and the refusal names
   * neither field.
   */
  public function testIssuerAndSubjectAreBothTheClientEmail() {
    $email = 'firebase-adminsdk-abcde@my-project.iam.gserviceaccount.com';
    $payload = myapi_firebase_custom_token_payload(412, $email, [], 1756698000);

    $this->assertSame($email, $payload['iss']);
    $this->assertSame($email, $payload['sub']);
  }

  /**
   * The audience is a LITERAL OF THE PROTOCOL — the same string for every
   * Firebase project on earth, and not a URL of this site.
   */
  public function testAudienceIsTheIdentityToolkitLiteral() {
    $payload = myapi_firebase_custom_token_payload(412, 'a@b.iam.gserviceaccount.com', [], 1756698000);

    $this->assertSame(
      'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
      $payload['aud']
    );
  }

  /**
   * Exactly one hour, and the hour is Google's maximum: a larger 'exp' does not
   * lengthen the session, it makes the whole token invalid.
   */
  public function testExpiryIsExactlyOneHourAfterIssuedAt() {
    $payload = myapi_firebase_custom_token_payload(412, 'a@b.iam.gserviceaccount.com', [], 1756698000);

    $this->assertSame(1756698000, $payload['iat']);
    $this->assertSame(3600, $payload['exp'] - $payload['iat']);
    $this->assertSame(3600, MYAPI_FIREBASE_TOKEN_TTL);
  }

  /**
   * THE UID IS A STRING. Firebase requires it, and an integer here is the kind
   * of thing that works in every test that reads the array and fails on the
   * device. assertSame(), not assertEquals(): '412' == 412 in PHP.
   */
  public function testUidIsAStringAndNeverAnInteger() {
    $payload = myapi_firebase_custom_token_payload(412, 'a@b.iam.gserviceaccount.com', [], 1756698000);

    $this->assertSame('412', $payload['uid']);
    $this->assertIsString($payload['uid']);
  }

  /**
   * The claims travel verbatim: this layer knows nothing about what a thread
   * is, and must not reshape what the domain half handed it.
   */
  public function testClaimsTravelVerbatim() {
    $claims = ['threads' => 'service_offers/901,service_offers/88'];
    $payload = myapi_firebase_custom_token_payload(412, 'a@b.iam.gserviceaccount.com', $claims, 1756698000);

    $this->assertSame($claims, $payload['claims']);
  }

  /* -------------------------------------------------------------------------
   * The claim.
   * ---------------------------------------------------------------------- */

  /**
   * The prefix is exact, and it is exact because the RTDB rule matches on
   * 'service_offers/' + $offer: with the bare nid, '901' would match inside
   * 'service_offers/9013'.
   */
  public function testThreadIdCarriesTheExactPrefix() {
    $this->assertSame('service_offers/901', myapi_chat_thread_id(901));
    $this->assertSame('service_offers/', MYAPI_CHAT_THREAD_PREFIX);
  }

  /**
   * A nid arriving as a string still produces the same path: the convention is
   * over the number, not over however the row delivered it.
   */
  public function testThreadIdNormalisesTheNid() {
    $this->assertSame('service_offers/901', myapi_chat_thread_id('901'));
  }

  /**
   * Comma-separated, in the order given. The order is the contract: the caller
   * hands them newest-activity-first, so the tail is what a trim throws away.
   */
  public function testClaimJoinsWithCommasAndPreservesOrder() {
    $claim = myapi_chat_threads_claim(['service_offers/901', 'service_offers/88', 'service_offers/7']);

    $this->assertSame('service_offers/901,service_offers/88,service_offers/7', $claim);
  }

  /**
   * No threads is an empty claim, not a malformed one. It is what an account
   * with no conversations yet is signed with, and it is not an error.
   */
  public function testClaimOfNoThreadsIsAnEmptyString() {
    $this->assertSame('', myapi_chat_threads_claim([]));
  }

  /**
   * The cut to 40, with the ORDER preserved and the TAIL dropped: the quietest
   * threads are the ones lost.
   */
  public function testClaimTrimsToFortyThreadsAndKeepsTheFirstOnes() {
    $thread_ids = [];
    for ($i = 1; $i <= 45; $i++) {
      $thread_ids[] = myapi_chat_thread_id($i);
    }

    $claim = myapi_chat_threads_claim($thread_ids);
    $kept = explode(',', $claim);

    $this->assertSame(40, MYAPI_CHAT_MAX_THREADS);
    $this->assertCount(40, $kept);
    $this->assertSame(array_slice($thread_ids, 0, 40), $kept);
    $this->assertNotContains('service_offers/41', $kept);
  }

  /**
   * THE 1000 BYTES ARE MEASURED, NOT ESTIMATED, and this is the fixture that
   * proves the difference matters: 40 threads of nine-digit nids go OVER the cap
   * that "about 40 fit" would have let through. The function keeps cutting until
   * the encoded claim fits, and 37 is what survives — three fewer than the
   * nominal ceiling, because the '/' of every path is escaped and therefore
   * weighed one byte heavier than Firebase will count it. Erring that way is
   * deliberate: a claim Firebase REJECTS is not a degraded chat, it is no chat.
   */
  public function testClaimStaysWithinFirebasesThousandByteCapWithLongNids() {
    $thread_ids = [];
    for ($i = 0; $i < 40; $i++) {
      $thread_ids[] = myapi_chat_thread_id(999999900 + $i);
    }

    $untrimmed = strlen(drupal_json_encode(['threads' => implode(',', $thread_ids)]));
    $this->assertGreaterThan(MYAPI_CHAT_CLAIM_MAX_BYTES, $untrimmed, 'fixture precondition: 40 long nids do NOT fit');

    $claim = myapi_chat_threads_claim($thread_ids);

    $this->assertLessThanOrEqual(
      MYAPI_CHAT_CLAIM_MAX_BYTES,
      strlen(drupal_json_encode(['threads' => $claim]))
    );
    $this->assertCount(37, explode(',', $claim));
    $this->assertSame(array_slice($thread_ids, 0, 37), explode(',', $claim));
  }

  /**
   * Forty threads of ordinary nids DO fit, so the trimming loop must not fire
   * on the normal case. The sibling of the test above, and the one that would
   * catch a cap accidentally measured against the raw string.
   */
  public function testFortyOrdinaryThreadsFitWithoutTrimming() {
    $thread_ids = [];
    for ($i = 1; $i <= 40; $i++) {
      $thread_ids[] = myapi_chat_thread_id(900 + $i);
    }

    $claim = myapi_chat_threads_claim($thread_ids);

    $this->assertCount(40, explode(',', $claim));
    $this->assertLessThanOrEqual(
      MYAPI_CHAT_CLAIM_MAX_BYTES,
      strlen(drupal_json_encode(['threads' => $claim]))
    );
  }

  /* -------------------------------------------------------------------------
   * myapi_firebase_is_configured().
   * ---------------------------------------------------------------------- */

  /**
   * A site that was never configured. The commonest 503 of this feature.
   */
  public function testNotConfiguredWithNoVariableAtAll() {
    $this->assertFalse(myapi_firebase_is_configured());
    $this->assertNull(myapi_firebase_service_account());
  }

  /**
   * Each half alone is not a credential: an email with no key signs nothing,
   * and a key with no email signs something Google refuses, because 'iss' and
   * 'sub' ARE that email.
   */
  public function testNotConfiguredWithEitherHalfMissing() {
    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = [
      'client_email' => 'a@b.iam.gserviceaccount.com',
    ];
    $this->assertFalse(myapi_firebase_is_configured(), 'private_key missing');

    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = [
      'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----\n",
    ];
    $this->assertFalse(myapi_firebase_is_configured(), 'client_email missing');
  }

  /**
   * A key that is present but blank — the shape a half-finished settings.php
   * leaves behind — is not a key.
   */
  public function testNotConfiguredWithABlankPrivateKey() {
    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount(['private_key' => '']);
    $this->assertFalse(myapi_firebase_is_configured(), 'empty private_key');

    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount(['private_key' => "  \n  "]);
    $this->assertFalse(myapi_firebase_is_configured(), 'whitespace-only private_key');

    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount(['client_email' => '   ']);
    $this->assertFalse(myapi_firebase_is_configured(), 'whitespace-only client_email');
  }

  /**
   * A variable of the wrong TYPE is not a credential either — a string left
   * behind by a `drush vset` is the realistic way this happens.
   */
  public function testNotConfiguredWhenTheVariableIsNotAnArray() {
    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = 'a-json-blob-somebody-pasted';

    $this->assertFalse(myapi_firebase_is_configured());
    $this->assertNull(myapi_firebase_service_account());
  }

  /**
   * Both halves present is configured — PROVIDED the OpenSSL extension is
   * there, which is the third condition and the one this layer cannot switch
   * off: function_exists() cannot be faked in-process. The assertion below
   * states it as a precondition of the suite, so a PHP build with no OpenSSL
   * fails here loudly instead of making the next test lie. The 503 branch that
   * covers a missing extension is a manual acceptance criterion of SPEC 115.
   */
  public function testConfiguredWithBothHalvesPresent() {
    $this->assertTrue(function_exists('openssl_sign'), 'suite precondition: the OpenSSL extension is available');

    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount();

    $this->assertTrue(myapi_firebase_is_configured());
    $this->assertSame(
      'firebase-adminsdk-abcde@my-project.iam.gserviceaccount.com',
      myapi_firebase_service_account()['client_email']
    );
  }

  /**
   * The credential never reaches watchdog. Asserted over the one impure
   * function, because that is the only one that logs at all.
   */
  public function testAFailedSignatureNeverLogsTheCredential() {
    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount();

    $this->assertFalse(myapi_firebase_sign_custom_token(412, ['threads' => '']));
    $this->assertNotEmpty($GLOBALS['myapi_test_watchdog'], 'the failure is logged');
    $this->assertStringContainsString('private key', $GLOBALS['myapi_test_watchdog'][0]['text']);

    foreach ($GLOBALS['myapi_test_watchdog'] as $entry) {
      $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $entry['text']);
      $this->assertStringNotContainsString('not-a-key', $entry['text']);
    }
  }

  /**
   * A missing credential is FALSE plus a log line, never an exception: the
   * caller answers 503 on its own terms.
   */
  public function testSigningWithNoCredentialReturnsFalseAndLogs() {
    $this->assertFalse(myapi_firebase_sign_custom_token(412, []));
    $this->assertCount(1, $GLOBALS['myapi_test_watchdog']);
    $this->assertStringContainsString('myapi_firebase_service_account', $GLOBALS['myapi_test_watchdog'][0]['text']);
  }

  /* -------------------------------------------------------------------------
   * The signature, for real.
   * ---------------------------------------------------------------------- */

  /**
   * THE ONE TEST THAT PROVES THE BYTES SIGNED ARE THE BYTES SENT.
   *
   * A key pair is generated here, the module signs with the private half, and
   * openssl_verify() checks the signature against the public half over the
   * EXACT '<header>.<payload>' the returned token carries. Re-encoding either
   * segment after signing — the classic bug of a hand-rolled JWT — produces a
   * token that passes every assertion over arrays and verifies nowhere; this is
   * the assertion that sees it.
   */
  public function testTheSignatureVerifiesOverTheTokensOwnHeaderAndPayload() {
    $keys = $this->generateKeyPair();

    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount([
      'private_key' => $keys['private'],
    ]);

    $jwt = myapi_firebase_sign_custom_token(412, ['threads' => 'service_offers/901']);
    $this->assertIsString($jwt);

    $segments = explode('.', $jwt);
    $this->assertCount(3, $segments, 'a JWT is three segments');

    $verified = openssl_verify(
      $segments[0] . '.' . $segments[1],
      $this->base64urlDecode($segments[2]),
      $keys['public'],
      OPENSSL_ALGO_SHA256
    );

    $this->assertSame(1, $verified, 'the signature verifies over the token\'s own first two segments');
  }

  /**
   * The header is RS256, and it is read back OFF THE TOKEN rather than off a
   * constant: the algorithm advertised has to be the one openssl_sign() used.
   */
  public function testTheTokenAdvertisesRs256() {
    $keys = $this->generateKeyPair();
    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount([
      'private_key' => $keys['private'],
    ]);

    $jwt = myapi_firebase_sign_custom_token(412, []);
    $header = json_decode($this->base64urlDecode(explode('.', $jwt)[0]), TRUE);

    $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
  }

  /**
   * The seven fields survive the round trip through JSON and base64url — in
   * particular the uid, which must still be a STRING after decoding.
   */
  public function testTheSignedPayloadCarriesTheSevenFields() {
    $keys = $this->generateKeyPair();
    $GLOBALS['myapi_test_variables']['myapi_firebase_service_account'] = $this->fakeServiceAccount([
      'private_key' => $keys['private'],
    ]);

    $jwt = myapi_firebase_sign_custom_token(412, ['threads' => 'service_offers/901']);
    $payload = json_decode($this->base64urlDecode(explode('.', $jwt)[1]), TRUE);

    $this->assertSame(
      ['iss', 'sub', 'aud', 'iat', 'exp', 'uid', 'claims'],
      array_keys($payload)
    );
    $this->assertSame('412', $payload['uid']);
    $this->assertSame(3600, $payload['exp'] - $payload['iat']);
    $this->assertSame(REQUEST_TIME, $payload['iat']);
    $this->assertSame(['threads' => 'service_offers/901'], $payload['claims']);
  }

  /**
   * A 2048-bit RSA pair, generated in-process. Skips rather than fails on a
   * build where openssl_pkey_new() cannot find its configuration — that is an
   * environment problem, not a regression of this module.
   */
  private function generateKeyPair() {
    if (!function_exists('openssl_pkey_new')) {
      $this->markTestSkipped('OpenSSL key generation is not available.');
    }

    $resource = @openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($resource === FALSE) {
      $this->markTestSkipped('openssl_pkey_new() could not generate a key pair in this environment.');
    }

    $private = '';
    openssl_pkey_export($resource, $private);
    $details = openssl_pkey_get_details($resource);

    return ['private' => $private, 'public' => $details['key']];
  }

  /* -------------------------------------------------------------------------
   * Membership — the table of SPEC 115, row by row.
   * ---------------------------------------------------------------------- */

  /**
   * AWARDED (SPEC 106): the request carries field_assigned_provider and the
   * winning offer says 'selected'. The resident sees the thread.
   */
  public function testAwardedRequestGivesTheResidentAThread() {
    $this->seed([$this->offerRow()]);

    $this->assertSame([901], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
  }

  /**
   * And the winning provider sees THE SAME thread — same offer nid, therefore
   * the same path. This is the assertion the whole feature rests on: two
   * different accounts, one conversation.
   */
  public function testTheAwardedProviderSeesTheSameThreadAsTheResident() {
    $this->seed([$this->offerRow()], $this->bothProviderAccounts());

    $resident = myapi_chat_offer_nids_for_uid(self::RESIDENT_UID);
    $provider = myapi_chat_offer_nids_for_uid(self::PROVIDER_UID);

    $this->assertSame([901], $resident);
    $this->assertSame($resident, $provider);
    $this->assertSame(
      myapi_chat_thread_id($resident[0]),
      myapi_chat_thread_id($provider[0])
    );
  }

  /**
   * MEMBERSHIP IS BY COMPANY, NOT BY ACCOUNT. field_provider_users is
   * multi-valued, so a second employee of the same provider sees the same
   * thread — what SPEC 109-112's notifications already do.
   */
  public function testASecondAccountOfTheSameProviderSeesTheSameThread() {
    $this->seed([$this->offerRow()], $this->bothProviderAccounts());

    $this->assertSame([901], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID));
    $this->assertSame([901], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID_2));
  }

  /**
   * DIRECT, QUOTED (SPEC 101): field_assigned_provider was written AT BIRTH and
   * the quote stays 'sent' forever, because there is nothing to award. It is a
   * thread, and gating on 'selected' alone would have left every direct job
   * with no chat, no error and no red test.
   */
  public function testAQuotedDirectRequestIsAThreadEvenThoughTheOfferIsOnlySent() {
    $this->seed(
      [$this->offerRow(['no.nid' => 902, 'fos.field_offer_status_value' => MYAPI_SERVICES_OFFER_STATUS_SENT])],
      $this->bothProviderAccounts()
    );

    $this->assertSame([902], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
    $this->assertSame([902], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID));
  }

  /**
   * DIRECT, NOT QUOTED YET: the request has its assigned provider but nobody
   * has priced it, so there is no offer — and "no offer, no possible thread"
   * (SPEC 101). Nothing is seeded, because nothing exists.
   */
  public function testADirectRequestWithNoOfferIsNoThread() {
    $this->seed([], $this->bothProviderAccounts());

    $this->assertSame([], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
    $this->assertSame([], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID));
  }

  /**
   * A LOSER of the award: field_assigned_provider names the winner, and this
   * offer was swept to 'rejected'. No thread — the provider who lost has
   * nothing to talk about.
   */
  public function testALosingOfferIsNoThread() {
    $this->seed([
      $this->offerRow(),
      $this->offerRow([
        'no.nid'                       => 903,
        'fos.field_offer_status_value' => 'rejected',
        'fp.field_provider_target_id'  => 77,
      ]),
    ]);

    $this->assertSame([901], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
  }

  /**
   * WITHDRAWN (SPEC 105): a quote the provider took back is not a live offer,
   * so it opens nothing.
   */
  public function testAWithdrawnOfferIsNoThread() {
    $this->seed(
      [$this->offerRow(['no.nid' => 904, 'fos.field_offer_status_value' => 'withdrawn'])],
      $this->bothProviderAccounts()
    );

    $this->assertSame([], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
    $this->assertSame([], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID));
  }

  /**
   * CANCELLED (SPEC 95), AND WITH NO CONDITION WRITTEN FOR IT. Cancelling calls
   * myapi_service_offer_reject_live() with no exception, so what it leaves
   * behind is every offer at 'rejected' — which is what is seeded here.
   * field_assigned_provider is KEPT, on purpose, and the request still excludes
   * itself. For both sides.
   */
  public function testACancelledRequestLeavesNoThreadForEitherSide() {
    $this->seed(
      [$this->offerRow(['fos.field_offer_status_value' => 'rejected'])],
      $this->bothProviderAccounts()
    );

    $this->assertSame([], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
    $this->assertSame([], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID));
  }

  /**
   * CLOSED (SPEC 108), AND ALSO WITH NO CONDITION WRITTEN FOR IT. Closing
   * writes the status, the closing date and the rating and touches NO offer, so
   * the winner still says 'selected' — which is what is seeded here. THE
   * CONVERSATION SURVIVES THE CLOSE, which is what a warranty needs.
   */
  public function testAClosedRequestKeepsItsThreadForBothSides() {
    $this->seed([$this->offerRow()], $this->bothProviderAccounts());

    $this->assertSame([901], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
    $this->assertSame([901], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID));
  }

  /**
   * A THIRD PARTY sees nothing: not the resident, not an account of the awarded
   * provider. No thread, and no query on the provider side at all — an account
   * that belongs to no provider is not asked about.
   */
  public function testAStrangerSeesNoThread() {
    $this->seed([$this->offerRow()], $this->bothProviderAccounts());

    $this->assertSame([], myapi_chat_offer_nids_for_uid(self::STRANGER_UID));
    $this->assertCount(
      1,
      myapi_test_db_queries('field_data_field_request'),
      'the provider side is not queried for an account with no provider'
    );
  }

  /**
   * An anonymous or malformed uid is answered without touching the database:
   * the guard is before the queries.
   */
  public function testNoUidMeansNoQueryAtAll() {
    $this->seed([$this->offerRow()]);

    $this->assertSame([], myapi_chat_offer_nids_for_uid(0));
    $this->assertSame([], myapi_test_db_queries());
  }

  /* -------------------------------------------------------------------------
   * The union, and what the cut keeps.
   * ---------------------------------------------------------------------- */

  /**
   * The two sides are merged, not concatenated: the result is ordered by the
   * REQUEST'S last activity, newest first, across both. That order is what
   * decides which 40 survive — if threads have to be lost, the quietest go.
   */
  public function testTheUnionIsOrderedByTheRequestsLastActivity() {
    $this->seed([
      $this->offerRow(['no.nid' => 10, 'rn.changed' => 100]),
      $this->offerRow(['no.nid' => 20, 'rn.changed' => 300]),
      $this->offerRow(['no.nid' => 30, 'rn.changed' => 200]),
    ], $this->bothProviderAccounts());

    $this->assertSame([20, 30, 10], myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
  }

  /**
   * A thread this account reaches from BOTH sides — resident of the request and
   * employee of the awarded provider, which the marketplace does not forbid —
   * appears ONCE. A duplicate would waste one of the 40 slots on a thread
   * already in the list.
   */
  public function testAThreadReachedFromBothSidesAppearsOnce() {
    $this->seed(
      [$this->offerRow(['fr.field_requester_target_id' => self::PROVIDER_UID])],
      $this->bothProviderAccounts()
    );

    $this->assertSame([901], myapi_chat_offer_nids_for_uid(self::PROVIDER_UID));
  }

  /**
   * The end-to-end shape of what the endpoint signs: nids to paths to a claim,
   * and the claim back to the list the response publishes. What is asserted is
   * that the two cannot drift — the response's `threads` is derived from the
   * very string that was signed.
   */
  public function testTheThreadsOfTheResponseAreExactlyTheOnesInTheClaim() {
    $this->seed([
      $this->offerRow(['no.nid' => 901, 'rn.changed' => 300]),
      $this->offerRow(['no.nid' => 88, 'rn.changed' => 200]),
    ]);

    $thread_ids = array_map('myapi_chat_thread_id', myapi_chat_offer_nids_for_uid(self::RESIDENT_UID));
    $claim = myapi_chat_threads_claim($thread_ids);

    $this->assertSame('service_offers/901,service_offers/88', $claim);
    $this->assertSame(['service_offers/901', 'service_offers/88'], explode(',', $claim));
  }

}
