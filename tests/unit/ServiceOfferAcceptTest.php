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
require_once __DIR__ . '/../../includes/myapi.service_transaction.inc';

/**
 * Unit tests for PUT /api/v1/service-offers/{id}/accept (SPEC 106).
 *
 * The award is the first write of this module that touches four nodes and two
 * bundles in one pass — the winning offer, the losing ones, the request and the
 * transaction — and the order of those writes is a contract, not a style: it is
 * what makes myapi_service_transaction_sync_request_status() compare two equal
 * statuses and NOT save the request a second time.
 *
 * WHAT THIS FILE CAN PROVE AND WHAT IT CANNOT. node_save() here is a recorder
 * (see tests/unit/bootstrap.php): a green test says the code asked for the
 * right thing, never that Drupal persisted it. That the node_save() lands, that
 * hook_node_presave() titles the transaction and that hook_node_insert() runs
 * the status sync all stay with the HTTP client against the running site — see
 * step 6 of the spec and docs/service-offer.md.
 */
class ServiceOfferAcceptTest extends TestCase {

  const REQUEST_NID = 500;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_write_reset();
  }

  /* -------------------------------------------------------------------------
   * The live-offer sweep, myapi_service_offer_reject_live() (SPECS 95, 106).
   * ---------------------------------------------------------------------- */

  /**
   * One row of the sweep's query, seeded flat: the stub records joins instead
   * of resolving them, so every column the query reads — whatever table it
   * really comes from — lives on the base fixture under the alias the query
   * gives it.
   *
   * @param mixed  $offer_nid    The offer's nid, which is what the query
   *                             projects and the loop then loads.
   * @param string $status       Its field_offer_status value.
   * @param mixed  $request_nid  The request it hangs on.
   */
  private function offerRow($offer_nid, $status, $request_nid = self::REQUEST_NID) {
    return [
      'entity_type'              => 'node',
      'deleted'                  => 0,
      'field_request_target_id'  => $request_nid,
      'type'                     => MYAPI_SERVICES_OFFER_TYPE,
      'status'                   => 1,
      'field_offer_status_value' => $status,
      'nid'                      => $offer_nid,
    ];
  }

  /**
   * Seeds the query's rows and a loadable node per offer, which is what the
   * loop needs: a nid the query answers but node_load() does not is skipped,
   * and that is a case of its own below.
   */
  private function seedOffers(array $rows) {
    myapi_test_db_seed(['field_data_field_request' => $rows]);

    $nodes = [];
    foreach ($rows as $row) {
      $nodes[$row['nid']] = (object) [
        'nid'                => $row['nid'],
        'type'               => MYAPI_SERVICES_OFFER_TYPE,
        'field_offer_status' => [LANGUAGE_NONE => [0 => ['value' => $row['field_offer_status_value']]]],
      ];
    }
    myapi_test_node_seed($nodes);
  }

  /**
   * The status every saved node ended up carrying, keyed by nid — what the
   * sweep actually wrote, read off the recorder.
   */
  private function savedStatuses() {
    $saved = [];
    foreach (myapi_test_node_saves() as $node) {
      $saved[(int) $node->nid] = $node->field_offer_status[LANGUAGE_NONE][0]['value'];
    }

    return $saved;
  }

  /**
   * NO $except_nid IS THE CANCELLATION, and it behaves exactly as the function
   * it replaces did (SPEC 95): every live offer goes to 'rejected'.
   *
   * This is the case that makes the extraction safe — myapi_service_request_cancel()
   * calls it with one argument, and one argument must mean what it always meant.
   */
  public function testWithoutAnExceptionEveryLiveOfferIsRejected() {
    $this->seedOffers([
      $this->offerRow(901, 'sent'),
      $this->offerRow(902, 'sent'),
      $this->offerRow(903, 'selected'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID);

    $this->assertSame(3, $rejected);
    $this->assertSame([901 => 'rejected', 902 => 'rejected', 903 => 'rejected'], $this->savedStatuses());
  }

  /**
   * THE AWARD'S CASE: the spared offer survives and every other live one does
   * not.
   *
   * Sparing the winner is obligatory and not an optimisation. The award writes
   * 'selected' onto it first, and 'selected' is one of the two statuses this
   * sweep considers live — without the exception the very next line would
   * rewrite the winner to 'rejected' and the request would end up assigned to a
   * rejected offer.
   */
  public function testTheExceptedOfferSurvivesAndTheOthersDoNot() {
    $this->seedOffers([
      $this->offerRow(901, 'selected'),
      $this->offerRow(902, 'sent'),
      $this->offerRow(903, 'sent'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID, 901);

    $this->assertSame(2, $rejected);
    $this->assertSame([902 => 'rejected', 903 => 'rejected'], $this->savedStatuses());
    $this->assertArrayNotHasKey(901, $this->savedStatuses(), 'the winner is not even saved');
  }

  /**
   * AN $except_nid THAT IS NOT AN OFFER OF THIS REQUEST SPARES NOTHING, and it
   * is not an error either: it simply never appears in the list the query
   * answers, so the sweep behaves as if no exception had been passed.
   */
  public function testAnExceptionFromAnotherRequestChangesNothing() {
    $this->seedOffers([
      $this->offerRow(901, 'sent'),
      $this->offerRow(902, 'sent'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID, 777);

    $this->assertSame(2, $rejected);
    $this->assertSame([901 => 'rejected', 902 => 'rejected'], $this->savedStatuses());
  }

  /**
   * The exception is compared as an integer, so a nid arriving as a string —
   * which is how it comes off a query row — still spares the right offer.
   */
  public function testTheExceptionMatchesAcrossTypes() {
    $this->seedOffers([
      $this->offerRow('901', 'selected'),
      $this->offerRow('902', 'sent'),
    ]);

    $rejected = myapi_service_offer_reject_live((string) self::REQUEST_NID, '901');

    $this->assertSame(1, $rejected);
    $this->assertSame([902 => 'rejected'], $this->savedStatuses());
  }

  /**
   * 'withdrawn' and 'rejected' ARE NEVER TOUCHED, with or without an exception.
   * The first is the provider's own retreat and overwriting it would erase who
   * walked away by themselves; the second is already terminal. The query is
   * what enforces it, through the IN over the two live constants.
   */
  public function testTerminalStatusesAreLeftAlone() {
    $this->seedOffers([
      $this->offerRow(901, 'sent'),
      $this->offerRow(902, 'withdrawn'),
      $this->offerRow(903, 'rejected'),
    ]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID, 901);

    $this->assertSame(0, $rejected);
    $this->assertSame([], $this->savedStatuses());
  }

  /**
   * Nothing live is 0 and no save, not an error — the normal answer for a
   * request whose offers were all withdrawn.
   */
  public function testNothingLiveAnswersZero() {
    $this->seedOffers([]);

    $this->assertSame(0, myapi_service_offer_reject_live(self::REQUEST_NID));
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * A nid the query answers but node_load() no longer resolves is SKIPPED and
   * not counted: a row pointing at a deleted node has nothing to rewrite, and
   * failing here would abort a sweep that is already halfway done.
   */
  public function testAnUnloadableOfferIsSkippedAndNotCounted() {
    myapi_test_db_seed(['field_data_field_request' => [
      $this->offerRow(901, 'sent'),
      $this->offerRow(902, 'sent'),
    ]]);
    // Only one of the two loads.
    myapi_test_node_seed([901 => (object) [
      'nid'                => 901,
      'type'               => MYAPI_SERVICES_OFFER_TYPE,
      'field_offer_status' => [LANGUAGE_NONE => [0 => ['value' => 'sent']]],
    ]]);

    $rejected = myapi_service_offer_reject_live(self::REQUEST_NID);

    $this->assertSame(1, $rejected);
    $this->assertSame([901 => 'rejected'], $this->savedStatuses());
  }

  /* -------------------------------------------------------------------------
   * The pure gate, myapi_service_offer_accept_gate() — conditions 6 to 9.
   * ---------------------------------------------------------------------- */

  const NOW = 1800000000;

  const NOT_ACCEPTABLE = 'service_offer_not_acceptable';
  const NOT_ASSIGNABLE = 'service_request_not_assignable';
  const EXPIRED        = 'service_offer_expired';
  const NOT_ACTIVE     = 'service_offer_provider_not_active';

  /**
   * A row of myapi_service_offer_detail_row(), trimmed to the two columns the
   * gate reads. Everything else on that row belongs to the response, not here.
   */
  private function offer(array $overrides = []) {
    return (object) ($overrides + [
      'status'      => MYAPI_SERVICES_OFFER_STATUS_SENT,
      'valid_until' => NULL,
    ]);
  }

  /**
   * A row of myapi_service_request_detail_row(), trimmed the same way:
   * requester_uid is condition 5 and belongs to the resource.
   */
  private function request(array $overrides = []) {
    return (object) ($overrides + ['status' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED]);
  }

  /**
   * A row of myapi_service_offer_provider_row(), trimmed to the licence pair.
   * `owned` and `category_ids` travel on the real row and are never read here.
   */
  private function provider(array $overrides = []) {
    return (object) ($overrides + [
      'status'         => 1,
      'license_expiry' => self::NOW + 86400,
    ]);
  }

  private function gate($row = NULL, $request_row = NULL, $provider_row = NULL) {
    return myapi_service_offer_accept_gate(
      $row === NULL ? $this->offer() : $row,
      $request_row === NULL ? $this->request() : $request_row,
      $provider_row === NULL ? $this->provider() : $provider_row,
      self::NOW
    );
  }

  /**
   * The happy path: a 'sent' offer, an 'offered' request, no expiry and an
   * active provider. NULL means the resource may write.
   */
  public function testAllFourConditionsPassing() {
    $this->assertNull($this->gate());
  }

  /**
   * CONDITION 6 — THE FOUR OFFER STATUSES. Only 'sent' is awardable: 'selected'
   * says it was already awarded, 'rejected' that it lost, 'withdrawn' that the
   * provider took it back.
   */
  public function testOnlyASentOfferIsAcceptable() {
    $this->assertNull($this->gate($this->offer(['status' => 'sent'])));

    foreach (['selected', 'rejected', 'withdrawn'] as $status) {
      $this->assertSame(self::NOT_ACCEPTABLE, $this->gate($this->offer(['status' => $status])), $status);
    }
  }

  /**
   * A corrupt, empty or absent offer status is NOT acceptable and never an
   * exception — the same fail-closed reading a row that did not load gets.
   */
  public function testACorruptOfferStatusFailsClosed() {
    $rows = [
      'empty'    => $this->offer(['status' => '']),
      'null'     => $this->offer(['status' => NULL]),
      'unknown'  => $this->offer(['status' => 'not_a_status']),
      'absent'   => (object) [],
      'no row'   => FALSE,
    ];

    foreach ($rows as $label => $row) {
      $this->assertSame(self::NOT_ACCEPTABLE, $this->gate($row), $label);
    }
  }

  /**
   * CONDITION 7 — THE GRAPH ANSWERS, AND ONLY 'offered' LEADS TO 'assigned'.
   * The list below is not this gate's: it is myapi_services_request_transitions()
   * read from the outside, which is the point of asking instead of transcribing.
   *
   * 'open' IS IN THE FAILING LIST ON PURPOSE. A request with offers still
   * sitting in 'open' — the hole SPEC 100 left, since nothing syncs
   * 'open' -> 'offered' from the back office — cannot be awarded from the app,
   * and SPEC 106 does not invent that edge (decision 3).
   */
  public function testOnlyAnOfferedRequestIsAssignable() {
    $this->assertNull($this->gate(NULL, $this->request(['status' => 'offered'])));

    foreach (['open', 'direct', 'assigned', 'closed', 'cancelled'] as $status) {
      $this->assertSame(
        self::NOT_ASSIGNABLE,
        $this->gate(NULL, $this->request(['status' => $status])),
        $status
      );
    }
  }

  /**
   * A field_request_status that is empty, absent or not in the catalogue is a
   * 409 AND NEVER AN EXCEPTION: myapi_services_transition_allowed() answers
   * FALSE for an unknown key by design, and this gate leans on that rather than
   * branching on it. Same for a request row that did not load.
   */
  public function testACorruptRequestStatusIsA409AndNotAnException() {
    $rows = [
      'empty'   => $this->request(['status' => '']),
      'null'    => $this->request(['status' => NULL]),
      'unknown' => $this->request(['status' => 'not_a_status']),
      'absent'  => (object) [],
      'no row'  => FALSE,
    ];

    foreach ($rows as $label => $row) {
      $this->assertSame(self::NOT_ASSIGNABLE, $this->gate(NULL, $row), $label);
    }
  }

  /**
   * CONDITION 8 — ABSENT valid_until MEANS "DOES NOT EXPIRE". It is optional
   * since SPEC 100 and most offers do not carry one; reading an empty column as
   * lapsed would block almost the whole catalogue.
   */
  public function testAnOfferWithoutAnExpiryNeverLapses() {
    foreach ([NULL, ''] as $valid_until) {
      $this->assertNull($this->gate($this->offer(['valid_until' => $valid_until])), var_export($valid_until, TRUE));
    }

    // The column absent from the row entirely, the way an offer created before
    // myapi_update_7035() arrives.
    $this->assertNull($this->gate((object) ['status' => MYAPI_SERVICES_OFFER_STATUS_SENT]));
  }

  /**
   * A quote whose deadline has passed is 409 service_offer_expired: awarding
   * work at a price the provider already declared lapsed commits them to
   * something they did not offer.
   */
  public function testAPastExpiryLapses() {
    foreach ([self::NOW - 1, self::NOW - 86400, 0, '0'] as $valid_until) {
      $this->assertSame(
        self::EXPIRED,
        $this->gate($this->offer(['valid_until' => $valid_until])),
        var_export($valid_until, TRUE)
      );
    }
  }

  /**
   * THE COMPARISON IS >=, exactly like myapi_services_provider_is_active(): the
   * quote is good THROUGHOUT its expiry instant and not one second less. The
   * boundary is the case worth pinning — off by one here silently rejects every
   * offer that expires this very second.
   */
  public function testTheExpiryBoundaryIsInclusive() {
    $this->assertNull($this->gate($this->offer(['valid_until' => self::NOW])), 'exactly now still holds');
    $this->assertNull($this->gate($this->offer(['valid_until' => self::NOW + 1])));
    $this->assertSame(self::EXPIRED, $this->gate($this->offer(['valid_until' => self::NOW - 1])));
  }

  /**
   * The timestamp arrives off a query column, so it is a string; it must be
   * read as the integer it is and not compared as text.
   */
  public function testTheExpiryReadsTheSameAsStringOrInteger() {
    $this->assertNull($this->gate($this->offer(['valid_until' => (string) (self::NOW + 86400)])));
    $this->assertSame(self::EXPIRED, $this->gate($this->offer(['valid_until' => (string) (self::NOW - 86400)])));
  }

  /**
   * A deadline nobody can parse is not a deadline that has been met: a
   * non-numeric value is read as LAPSED and not as absent, the same fail-closed
   * reading myapi_services_provider_is_active() gives a corrupt licence.
   */
  public function testANonNumericExpiryFailsClosed() {
    foreach (['abc', '2026-08-26', []] as $valid_until) {
      $this->assertSame(
        self::EXPIRED,
        $this->gate($this->offer(['valid_until' => $valid_until])),
        var_export($valid_until, TRUE)
      );
    }
  }

  /**
   * CONDITION 9 — THE PROVIDER MUST STILL BE ABLE TO WORK. Unpublished, licence
   * expired, or no licence row at all: all three are 403, and the rule is
   * myapi_services_provider_is_active()'s, not this gate's.
   */
  public function testAnInactiveProviderIsRejected() {
    $rows = [
      'unpublished'      => $this->provider(['status' => 0]),
      'licence expired'  => $this->provider(['license_expiry' => self::NOW - 1]),
      'licence empty'    => $this->provider(['license_expiry' => '']),
      'licence null'     => $this->provider(['license_expiry' => NULL]),
      'licence corrupt'  => $this->provider(['license_expiry' => 'abc']),
      'no licence row'   => (object) ['status' => 1],
      'no provider row'  => FALSE,
    ];

    foreach ($rows as $label => $row) {
      $this->assertSame(self::NOT_ACTIVE, $this->gate(NULL, NULL, $row), $label);
    }
  }

  /**
   * The licence boundary is inclusive too, and it is the same function's rule.
   */
  public function testTheLicenceBoundaryIsInclusive() {
    $this->assertNull($this->gate(NULL, NULL, $this->provider(['license_expiry' => self::NOW])));
    $this->assertSame(
      self::NOT_ACTIVE,
      $this->gate(NULL, NULL, $this->provider(['license_expiry' => self::NOW - 1]))
    );
  }

  /**
   * THE ORDER IS THE CONTRACT, AND IT IS ASSERTED HERE AND NOT ASSUMED.
   * Conditions 6, 7 and 8 ask "is this offer awardable?" and 9 asks "may this
   * provider still work?"; the second only means anything once the first has
   * passed. So a rejected offer from an unpublished provider answers 409
   * service_offer_not_acceptable and NOT 403 — the same order SPEC 105 fixed
   * for editing.
   */
  public function testTheFirstFailingConditionIsTheOneThatAnswers() {
    // Everything wrong at once: the offer status wins.
    $this->assertSame(self::NOT_ACCEPTABLE, $this->gate(
      $this->offer(['status' => 'rejected', 'valid_until' => self::NOW - 1]),
      $this->request(['status' => 'cancelled']),
      $this->provider(['status' => 0])
    ));

    // Offer fine: the request's status wins over the expiry and the licence.
    $this->assertSame(self::NOT_ASSIGNABLE, $this->gate(
      $this->offer(['valid_until' => self::NOW - 1]),
      $this->request(['status' => 'assigned']),
      $this->provider(['status' => 0])
    ));

    // Offer and request fine: the expiry wins over the licence.
    $this->assertSame(self::EXPIRED, $this->gate(
      $this->offer(['valid_until' => self::NOW - 1]),
      NULL,
      $this->provider(['status' => 0])
    ));
  }

  /**
   * PURE: the gate reads the three rows it is handed and asks nothing of the
   * database, which is what lets the whole matrix above run with no site
   * booted. A query creeping in here would make the resource pay twice for what
   * it already read.
   */
  public function testTheGateCostsNoQuery() {
    myapi_test_db_seed();

    $this->gate();
    $this->gate($this->offer(['status' => 'rejected']));

    $this->assertSame([], myapi_test_db_queries());
    $this->assertSame([], myapi_test_node_saves());
  }

  /* -------------------------------------------------------------------------
   * The timeline entry, myapi_service_transaction_record() (SPECS 95, 106).
   * ---------------------------------------------------------------------- */

  /**
   * The four fields of SPEC 77, plus node.uid and the bundle. This is the whole
   * contract of the extraction: the cancellation writes the same node it wrote
   * inline since SPEC 95, and the award writes one of the same shape.
   */
  public function testTheRecordedTransactionCarriesTheFourFields() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'Oferta adjudicada a Fontanería Ruiz.');

    $this->assertCount(1, myapi_test_node_saves());
    $this->assertSame($saved, myapi_test_node_saves()[0]);

    $this->assertSame(MYAPI_SERVICES_TRANSACTION_TYPE, $saved->type);
    $this->assertSame(77, $saved->uid);
    $this->assertSame(1, $saved->status);
    $this->assertSame(self::REQUEST_NID, $saved->field_request[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('assigned', $saved->field_request_status[LANGUAGE_NONE][0]['value']);
    $this->assertSame('Oferta adjudicada a Fontanería Ruiz.', $saved->field_comment[LANGUAGE_NONE][0]['value']);
  }

  /**
   * field_status_date is the REAL instant with the seconds pinned to 00, and a
   * string and not a timestamp: the field is 'datetime' and not 'datestamp'
   * (SPEC 77). Midnight of that day would be the bug SPEC 58 already paid for
   * once in claims.
   */
  public function testTheStatusDateIsTheRealInstantWithSecondsPinned() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'x');
    $value = $saved->field_status_date[LANGUAGE_NONE][0]['value'];

    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $value);
    $this->assertSame(date('Y-m-d H:i:00'), $value);
  }

  /**
   * field_comment is written with 'value' ONLY and no 'format': the column is
   * stored raw and whoever renders it escapes it, exactly like claims.
   */
  public function testTheCommentCarriesNoTextFormat() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'x');

    $this->assertSame(['value' => 'x'], $saved->field_comment[LANGUAGE_NONE][0]);
  }

  /**
   * THE TITLE IS NOT SET HERE. myapi_service_transaction_set_title() puts it in
   * from hook_node_presave(), inside the node_save() above — the one place that
   * titles a transaction whatever created it. A title written here would be a
   * second one for the same node.
   */
  public function testTheRecorderDoesNotSetTheTitle() {
    $saved = myapi_service_transaction_record(self::REQUEST_NID, 'assigned', 77, 'x');

    $this->assertFalse(property_exists($saved, 'title'), 'the recorder leaves the title to hook_node_presave()');
  }

  /**
   * The status is stored AS-IS. Recording what there was is the point, so
   * nothing here validates it against the catalogue or against the graph — a
   * request saved programmatically without one copies the empty value, exactly
   * as myapi_service_transaction_create_initial() does.
   */
  public function testTheStatusIsStoredWithoutValidation() {
    foreach (['assigned', 'cancelled', '', 'not_a_status'] as $status) {
      myapi_test_write_reset();
      $saved = myapi_service_transaction_record(self::REQUEST_NID, $status, 77, 'x');

      $this->assertSame($status, $saved->field_request_status[LANGUAGE_NONE][0]['value'], $status);
    }
  }

  /* -------------------------------------------------------------------------
   * The award's timeline text, myapi_service_transaction_accept_comment().
   * ---------------------------------------------------------------------- */

  /**
   * WITH AN AMOUNT: the provider and the price, which is the part of the
   * decision worth recording. Comma for the decimal separator and dot for the
   * thousands, two decimals always, and NO CURRENCY SYMBOL — field_offer_amount
   * stores a number and the module has no currency anywhere, so writing one
   * here would invent a datum.
   */
  public function testAPricedOfferNamesTheProviderAndTheAmount() {
    $cases = [
      '95.5'      => 'Oferta adjudicada a Fontanería Ruiz por 95,50.',
      '95.50'     => 'Oferta adjudicada a Fontanería Ruiz por 95,50.',
      '1250'      => 'Oferta adjudicada a Fontanería Ruiz por 1.250,00.',
      '1234567.8' => 'Oferta adjudicada a Fontanería Ruiz por 1.234.567,80.',
      '0.5'       => 'Oferta adjudicada a Fontanería Ruiz por 0,50.',
      '95.567'    => 'Oferta adjudicada a Fontanería Ruiz por 95,57.',
    ];

    foreach ($cases as $amount => $expected) {
      $this->assertSame(
        $expected,
        myapi_service_transaction_accept_comment('Fontanería Ruiz', $amount, 'fixed'),
        $amount
      );
    }
  }

  /**
   * The amount arrives off a query column, so it is a string; a float behaves
   * identically. Neither prints differently from the other.
   */
  public function testTheAmountReadsTheSameAsStringOrFloat() {
    $this->assertSame(
      myapi_service_transaction_accept_comment('Fontanería Ruiz', '95.50', 'fixed'),
      myapi_service_transaction_accept_comment('Fontanería Ruiz', 95.50, 'fixed')
    );
  }

  /**
   * 'on_site_quote' IS THE AMOUNTLESS TYPE (SPEC 100) AND IT WINS over whatever
   * sits in the column: an offer to be quoted on site has no price to record,
   * and printing one left over from an earlier edit would put a figure nobody
   * agreed to on a timeline that is forever.
   */
  public function testAnOnSiteQuoteNeverPrintsAnAmount() {
    $expected = 'Oferta adjudicada a Fontanería Ruiz.';

    $this->assertSame($expected, myapi_service_transaction_accept_comment('Fontanería Ruiz', NULL, 'on_site_quote'));
    $this->assertSame($expected, myapi_service_transaction_accept_comment('Fontanería Ruiz', '95.50', 'on_site_quote'));
  }

  /**
   * NO AMOUNT AT ALL is the same sentence: an offer created before the ten
   * quote columns existed has no row in field_data_field_offer_amount, and a
   * non-numeric value is treated as an absence rather than printed raw.
   */
  public function testAnOfferWithoutAnAmountNamesOnlyTheProvider() {
    $expected = 'Oferta adjudicada a Fontanería Ruiz.';

    foreach ([NULL, '', 'abc', []] as $amount) {
      $this->assertSame(
        $expected,
        myapi_service_transaction_accept_comment('Fontanería Ruiz', $amount, 'fixed'),
        var_export($amount, TRUE)
      );
    }
  }

  /**
   * WITHOUT A PROVIDER NAME the entry is still a whole sentence. This case
   * cannot happen today — condition 9 requires an active provider, which is
   * published and therefore titled — and exists because a pure function handed
   * NULL must answer a sentence and not half of one.
   */
  public function testWithoutAProviderNameTheEntryStillReads() {
    foreach ([NULL, '', '   '] as $name) {
      $this->assertSame(
        'Oferta adjudicada.',
        myapi_service_transaction_accept_comment($name, '95.50', 'fixed'),
        var_export($name, TRUE)
      );
    }
  }

  /**
   * The provider's name is trimmed, so the timeline never prints a leading
   * blank — the same rule the cancellation reason follows.
   */
  public function testTheProviderNameIsTrimmed() {
    $this->assertSame(
      'Oferta adjudicada a Fontanería Ruiz.',
      myapi_service_transaction_accept_comment('  Fontanería Ruiz  ', NULL, 'on_site_quote')
    );
  }

  /**
   * A request nid that is not a positive integer answers 0 BEFORE any query,
   * which is the guard the function was born with (SPEC 95) and the reason a
   * malformed route can never start a sweep.
   *
   * TRUE IS NOT IN THE LIST, AND THAT IS DELIBERATE. ctype_digit((string) TRUE)
   * is ctype_digit('1'), so a boolean true walks through this guard as the nid
   * 1. It is the behaviour the function has carried since SPEC 95 and the
   * extraction did not change it; no caller can produce it — both pass a nid
   * read off a node — so it is left as inherited rather than asserted here as
   * if it were a decision.
   */
  public function testANonPositiveIntegerRequestNidCostsNoQuery() {
    foreach (['abc', '0', '-3', '', 0, -3, 1.5, NULL, []] as $raw) {
      myapi_test_db_seed(['field_data_field_request' => [$this->offerRow(901, 'sent')]]);
      myapi_test_write_reset();

      $this->assertSame(0, myapi_service_offer_reject_live($raw), var_export($raw, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($raw, TRUE));
      $this->assertSame([], myapi_test_node_saves(), var_export($raw, TRUE));
    }
  }

}
