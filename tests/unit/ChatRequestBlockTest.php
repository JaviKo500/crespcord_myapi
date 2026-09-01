<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.chat.inc';

/**
 * Unit tests for the `chat` key of the two service-request listings (SPEC 118)
 * — myapi_chat_blocks_for_requests(), myapi_chat_block() and
 * myapi_chat_attach_block().
 *
 * THE ONE THING WORTH BREAKING THIS SUITE OVER IS THE GATE, and it is a single
 * sentence: THE BLOCK IS THE READER'S, NOT THE REQUEST'S. Every other assertion
 * here is shape. A rival provider — one who has a live offer on a request that
 * was awarded to somebody else — sees that request on their board on purpose
 * (set A of SPEC 98), and that request HAS a live thread. Answering them a
 * block would paint a chat button over a conversation they cannot open, and the
 * app would not find out until Firebase refused the read. That case is
 * testARivalWithALiveOfferOnSomebodyElsesJobGetsNoBlock(), and it is the reason
 * the helper takes a uid at all instead of the cheaper "does this request have
 * a thread?".
 *
 * THE RULE OF MEMBERSHIP IS NOT RE-ASSERTED HERE. Whether a cancelled request
 * has a thread, whether a closed one keeps it, and whether a stray offer of
 * another provider opens one are ChatTokenTest's, over the very same
 * myapi_chat_thread_base_query() this file hangs off — and that shared builder
 * is precisely what makes re-testing it here pointless. What IS asserted is
 * that this half asks the same builder and adds only a narrowing.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, as everywhere in tests/unit: joins are
 * recorded and never resolved, so one thread is one flat row carrying its own
 * columns plus the ones each join would have brought, under the qualified alias
 * the query names them by. That is also this suite's limit — the two LEFT JOINs
 * onto the mirror columns are recorded, not executed, so "a thread with no
 * message yet answers two nulls" is asserted by seeding the columns absent and
 * not by the stub failing to find a row.
 */
class ChatRequestBlockTest extends TestCase {

  /**
   * The resident of every fixture request.
   */
  const RESIDENT_UID = 412;

  /**
   * An account of the provider the fixture requests are awarded to.
   */
  const PROVIDER_UID = 7;

  /**
   * An account of a DIFFERENT provider: it has a live offer on the request —
   * so the request is on its board — and the award went elsewhere.
   */
  const RIVAL_UID = 21;

  /**
   * An account that takes part in nothing.
   */
  const STRANGER_UID = 99;

  const PROVIDER_NID = 55;
  const RIVAL_PROVIDER_NID = 56;

  const REQUEST_NID = 380;
  const OFFER_NID = 901;

  const WHEN = 1756742400;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_static_reset();
  }

  protected function tearDown(): void {
    myapi_test_static_reset();
    myapi_test_db_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One thread, as the joins of myapi_chat_blocks_for_requests() deliver it.
   *
   * The defaults describe the commonest thread there is: an awarded request,
   * its winning offer, and a last message from the resident.
   */
  private function threadRow(array $overrides = []) {
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
      'rn.changed'                            => 1000,
      'fap.field_assigned_provider_target_id' => self::PROVIDER_NID,
      'fr.field_requester_target_id'          => self::RESIDENT_UID,
      // The mirror columns, as the database answers them: strings, or absent.
      'flast.field_last_message_at_value'     => (string) self::WHEN,
      'ffrom.field_last_message_from_value'   => MYAPI_NOTIFICATION_AUDIENCE_RESIDENT,
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

  private function seed(array $threads, array $provider_users = []) {
    myapi_test_db_seed([
      'field_data_field_request'        => $threads,
      'field_data_field_provider_users' => $provider_users,
    ]);
  }

  /**
   * The two sides of the fixture thread, plus the rival's own company.
   */
  private function everyProviderAccount() {
    return [
      $this->providerUserRow(self::PROVIDER_NID, self::PROVIDER_UID),
      $this->providerUserRow(self::RIVAL_PROVIDER_NID, self::RIVAL_UID),
    ];
  }

  /* -------------------------------------------------------------------------
   * The gate: whose block is it.
   * ---------------------------------------------------------------------- */

  /**
   * THE RESIDENT OF THE REQUEST GETS THE BLOCK. The plainest case, and the one
   * the resident's listing lives on.
   */
  public function testTheResidentOfTheRequestGetsTheBlock() {
    $this->seed([$this->threadRow()]);

    $blocks = myapi_chat_blocks_for_requests([self::REQUEST_NID], self::RESIDENT_UID);

    $this->assertSame([
      self::REQUEST_NID => [
        'offer_nid'         => self::OFFER_NID,
        'last_message_at'   => date('Y-m-d\TH:i:s', self::WHEN),
        'last_message_from' => 'resident',
      ],
    ], $blocks);
  }

  /**
   * AND SO DOES AN ACCOUNT OF THE AWARDED PROVIDER, through the second query
   * and the same rule. Membership is by company (SPEC 115), so what puts this
   * account in the thread is field_provider_users and not the request.
   */
  public function testAnAccountOfTheAwardedProviderGetsTheSameBlock() {
    $this->seed([$this->threadRow()], $this->everyProviderAccount());

    $blocks = myapi_chat_blocks_for_requests([self::REQUEST_NID], self::PROVIDER_UID);

    $this->assertSame(self::OFFER_NID, $blocks[self::REQUEST_NID]['offer_nid']);
  }

  /**
   * *** THE ONE THAT MATTERS. *** A rival with a live offer on a request that
   * was awarded to somebody else SEES THE REQUEST on their board — set A of
   * SPEC 98, deliberately — and gets NO BLOCK, because the thread is between
   * the resident and the winner. A block here is a chat button over a
   * conversation Firebase will refuse to open.
   *
   * The fixture seeds the thread of the WINNER, exactly as the database holds
   * it; nothing about it is the rival's, and that is the whole point.
   */
  public function testARivalWithALiveOfferOnSomebodyElsesJobGetsNoBlock() {
    $this->seed([$this->threadRow()], $this->everyProviderAccount());

    $blocks = myapi_chat_blocks_for_requests([self::REQUEST_NID], self::RIVAL_UID);

    $this->assertSame([], $blocks);
  }

  /**
   * And the same answer for an account that takes part in nothing at all: no
   * key, which the listing paints as `chat: null`.
   */
  public function testAStrangerGetsNoBlock() {
    $this->seed([$this->threadRow()]);

    $this->assertSame([], myapi_chat_blocks_for_requests([self::REQUEST_NID], self::STRANGER_UID));
  }

  /**
   * A REQUEST OF THE PAGE WITH NO THREAD SIMPLY HAS NO KEY. It is not an error
   * and not a null entry: the caller's isset() is what turns it into the null
   * the client sees, and a map with holes is what lets one query answer a page
   * of mixed requests.
   */
  public function testARequestWithNoThreadHasNoKey() {
    $this->seed([$this->threadRow()]);

    $blocks = myapi_chat_blocks_for_requests([self::REQUEST_NID, 999], self::RESIDENT_UID);

    $this->assertArrayHasKey(self::REQUEST_NID, $blocks);
    $this->assertArrayNotHasKey(999, $blocks);
  }

  /* -------------------------------------------------------------------------
   * The cost, and the guards on the input.
   * ---------------------------------------------------------------------- */

  /**
   * AN EMPTY PAGE COSTS NO QUERY, and neither does an anonymous reader. An
   * unbounded read over field_data_field_request is what the early return
   * avoids — and an "IN ()" is invalid SQL in D7 besides.
   */
  public function testAnEmptyPageOrNoReaderCostsNoQuery() {
    $this->seed([$this->threadRow()]);

    $this->assertSame([], myapi_chat_blocks_for_requests([], self::RESIDENT_UID));
    $this->assertSame([], myapi_chat_blocks_for_requests([self::REQUEST_NID], 0));
    $this->assertSame([], myapi_chat_blocks_for_requests([self::REQUEST_NID], -1));

    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * RUBBISH IN THE nid LIST IS DROPPED AND REPEATS COLLAPSE, so callers hand
   * over what they have — the very array the listing built out of its rows.
   */
  public function testRubbishNidsAreDroppedAndRepeatsCollapse() {
    $this->seed([$this->threadRow()]);

    $blocks = myapi_chat_blocks_for_requests(
      [self::REQUEST_NID, self::REQUEST_NID, 0, -3, 'abc', NULL],
      self::RESIDENT_UID
    );

    $this->assertSame([self::REQUEST_NID], array_keys($blocks));

    $conditions = [];
    foreach (myapi_test_db_queries()[0]['conditions'] as $condition) {
      $conditions[$condition['field']] = $condition['value'];
    }
    $this->assertSame([self::REQUEST_NID], $conditions['rn.nid']);
  }

  /**
   * A PLAIN RESIDENT PAYS ONE QUERY AND NOT TWO. The provider side is asked
   * only when the account belongs to a provider at all, which is one round trip
   * saved for every resident in the building — the same shortcut
   * myapi_chat_offer_nids_for_uid() takes.
   *
   * The second query below is myapi_provider_role_provider_ids() asking whether
   * this account operates anything; it reads field_data_field_provider_users
   * and is statically cached per uid.
   */
  public function testAResidentPaysOneMembershipQuery() {
    $this->seed([$this->threadRow()]);

    myapi_chat_blocks_for_requests([self::REQUEST_NID], self::RESIDENT_UID);

    $this->assertSame(
      ['field_data_field_request', 'field_data_field_provider_users'],
      array_column(myapi_test_db_queries(), 'table')
    );
  }

  /**
   * AND THE COST DOES NOT GROW WITH THE PAGE. Fifty requests are the same two
   * queries one request costs — the promise both listings make and the reason
   * this helper takes an array.
   */
  public function testFiftyRequestsCostWhatOneCosts() {
    $this->seed([$this->threadRow()]);

    myapi_chat_blocks_for_requests(range(300, 349), self::RESIDENT_UID);

    $this->assertCount(2, myapi_test_db_queries());
  }

  /**
   * THE QUERY IS THE SHARED RULE OF MEMBERSHIP PLUS A NARROWING, and this is
   * the structural half of "the listing cannot paint a chat the token would not
   * authorise": the six joins of myapi_chat_thread_base_query() are all INNER
   * and are still all there, and the only LEFT joins this half adds are the two
   * mirror columns.
   */
  public function testTheQueryIsTheSharedRulePlusTheTwoMirrorJoins() {
    $this->seed([$this->threadRow()]);

    myapi_chat_blocks_for_requests([self::REQUEST_NID], self::RESIDENT_UID);
    $query = myapi_test_db_queries()[0];

    $joins = [];
    foreach ($query['joins'] as $join) {
      $joins[$join['alias']] = $join['type'];
    }

    $this->assertSame([
      'no'    => 'INNER',
      'fos'   => 'INNER',
      'fp'    => 'INNER',
      'rn'    => 'INNER',
      'fap'   => 'INNER',
      'fr'    => 'INNER',
      'flast' => 'LEFT',
      'ffrom' => 'LEFT',
    ], $joins);
  }

  /* -------------------------------------------------------------------------
   * The shape of the block.
   * ---------------------------------------------------------------------- */

  /**
   * THREE KEYS, IN THE DOCUMENTED ORDER, AND THE nid IS AN INTEGER: a Dart
   * client comparing 901 to "901" fails silently.
   */
  public function testTheBlockHasThreeKeysInOrderAndAnIntegerNid() {
    $block = myapi_chat_block((object) [
      'offer_nid'         => '901',
      'last_message_at'   => (string) self::WHEN,
      'last_message_from' => 'provider',
    ]);

    $this->assertSame(['offer_nid', 'last_message_at', 'last_message_from'], array_keys($block));
    $this->assertSame(901, $block['offer_nid']);
  }

  /**
   * A THREAD NOBODY HAS WRITTEN TO YET IS STILL A BLOCK. That is the whole
   * meaning of the key — "the chat can be opened" — and it is why the two
   * mirror joins are LEFT. The dates answer null, never format_date(0), which
   * would put a 1970 nobody typed on the screen.
   */
  public function testAThreadWithNoMessageYetIsStillABlock() {
    $row = $this->threadRow();
    unset($row['flast.field_last_message_at_value'], $row['ffrom.field_last_message_from_value']);
    $this->seed([$row]);

    $blocks = myapi_chat_blocks_for_requests([self::REQUEST_NID], self::RESIDENT_UID);

    $this->assertSame([
      'offer_nid'         => self::OFFER_NID,
      'last_message_at'   => NULL,
      'last_message_from' => NULL,
    ], $blocks[self::REQUEST_NID]);
  }

  /**
   * A THREAD WRITTEN TO BEFORE SPEC 118 SHIPPED: a real date and no side, and
   * the side is answered NULL rather than guessed. There is no backfill —
   * nothing on this site knows who sent a message that lives in Firebase — so
   * defaulting it to 'resident' would paint "tú escribiste el último" over a
   * message the provider sent.
   */
  public function testADateWithNoSideAnswersTheDateAndANullSide() {
    $row = $this->threadRow();
    unset($row['ffrom.field_last_message_from_value']);
    $this->seed([$row]);

    $block = myapi_chat_blocks_for_requests([self::REQUEST_NID], self::RESIDENT_UID)[self::REQUEST_NID];

    $this->assertSame(date('Y-m-d\TH:i:s', self::WHEN), $block['last_message_at']);
    $this->assertNull($block['last_message_from']);
  }

  /**
   * AN EMPTY STRING IS NOT A SIDE AND NOT A DATE. A column blanked from the
   * node form is a hole, and a hole answers null — the same reading
   * myapi_chat_field_values() gives it on the write side.
   */
  public function testEmptyStringsAnswerNull() {
    $block = myapi_chat_block((object) [
      'offer_nid'         => self::OFFER_NID,
      'last_message_at'   => '',
      'last_message_from' => '',
    ]);

    $this->assertNull($block['last_message_at']);
    $this->assertNull($block['last_message_from']);
  }

  /**
   * THE DATE IS THE MODULE'S ONE FORMAT — the same 'Y-m-d\TH:i:s' `created` and
   * `desired_start` already travel in, so the app parses one shape of date and
   * not two.
   */
  public function testTheDateIsTheListingsOwnFormat() {
    $block = myapi_chat_block((object) [
      'offer_nid'         => self::OFFER_NID,
      'last_message_at'   => (string) self::WHEN,
      'last_message_from' => NULL,
    ]);

    $this->assertSame(date('Y-m-d\TH:i:s', self::WHEN), $block['last_message_at']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $block['last_message_at']);
  }

  /**
   * TWO LIVE OFFERS OF THE SAME PROVIDER ON ONE REQUEST — data nothing creates
   * today — ANSWER ONE THREAD, DETERMINISTICALLY: the highest nid wins. An app
   * that opened a different conversation depending on which row the database
   * handed back first would be worse than one that always opened the wrong one,
   * because only the second is reportable.
   */
  public function testTwoLiveOffersOnOneRequestAnswerTheHighestNid() {
    $this->seed([
      $this->threadRow(['no.nid' => 900]),
      $this->threadRow(['no.nid' => 902]),
      $this->threadRow(['no.nid' => 901]),
    ]);

    $blocks = myapi_chat_blocks_for_requests([self::REQUEST_NID], self::RESIDENT_UID);

    $this->assertCount(1, $blocks);
    $this->assertSame(902, $blocks[self::REQUEST_NID]['offer_nid']);
  }

  /* -------------------------------------------------------------------------
   * Hanging the key on an item.
   * ---------------------------------------------------------------------- */

  /**
   * THE KEY ALWAYS TRAVELS AND IT GOES LAST. A client that had to tell an
   * absent key from a null one is a client with a bug waiting in it, and
   * appending is what keeps a new key from moving the documented ones.
   */
  public function testTheKeyAlwaysTravelsAndGoesLast() {
    $item = ['id' => self::REQUEST_NID, 'title' => 'Fuga'];

    $with = myapi_chat_attach_block($item, self::REQUEST_NID, [
      self::REQUEST_NID => ['offer_nid' => self::OFFER_NID, 'last_message_at' => NULL, 'last_message_from' => NULL],
    ]);
    $without = myapi_chat_attach_block($item, self::REQUEST_NID, []);

    $this->assertSame(['id', 'title', 'chat'], array_keys($with));
    $this->assertSame(['id', 'title', 'chat'], array_keys($without));
    $this->assertSame(self::OFFER_NID, $with['chat']['offer_nid']);
  }

  /**
   * NO CHAT IS A WHOLE null, never three null members — the same shape `unit`,
   * `assigned_offer` and `assigned_provider` already answer with.
   */
  public function testNoChatIsAWholeNull() {
    $item = myapi_chat_attach_block(['id' => self::REQUEST_NID], self::REQUEST_NID, []);

    $this->assertNull($item['chat']);
  }

  /**
   * THE nid IS CAST BEFORE THE LOOKUP: the listing hands over whatever the
   * database answered, and a string '380' must find the block of 380.
   */
  public function testAStringNidStillFindsItsBlock() {
    $blocks = [self::REQUEST_NID => ['offer_nid' => self::OFFER_NID, 'last_message_at' => NULL, 'last_message_from' => NULL]];

    $item = myapi_chat_attach_block(['id' => self::REQUEST_NID], (string) self::REQUEST_NID, $blocks);

    $this->assertSame(self::OFFER_NID, $item['chat']['offer_nid']);
  }
}
