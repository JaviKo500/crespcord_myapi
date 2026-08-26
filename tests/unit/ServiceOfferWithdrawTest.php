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
}
