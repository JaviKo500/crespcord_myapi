<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../resources/notification.resource.inc';

/**
 * End-to-end unit tests for the three /api/v1/notifications endpoints (SPECS
 * 25 and 26, covered by SPEC 121).
 *
 * THE FIRST ENDPOINTS OF THIS SUITE THAT WRITE AND THEN ANSWER, and the reason
 * SPEC 121 made the fixture write side apply instead of throw (see
 * MyapiTestWriteQuery in bootstrap.php). Their whole contract is about what the
 * SECOND call sees:
 *
 *  - PUT /notifications/%/read is IDEMPOTENT — marking an already-read notice
 *    must not move its read_at, and a resource that dropped the `is_read` guard
 *    would still answer a perfectly plausible 200 while quietly rewriting the
 *    timestamp the app shows.
 *  - PUT /notifications/read-all answers HOW MANY it marked, which is the
 *    affected-row count of an UPDATE and is 0 the second time.
 *
 * Neither is observable without applying the write, which is why none of this
 * had a test before.
 *
 * The other rule under test is ownership: every one of the three endpoints is
 * scoped to the token's uid, and the failure mode of losing that scope is
 * reading — or marking — a neighbour's inbox. The 404 of a foreign id is
 * deliberately indistinguishable from the 404 of a missing one.
 */
class NotificationEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

  const UID = 3;
  const OTHER_UID = 900;

  const CONDOMINIUM = 12;
  const OTHER_CONDOMINIUM = 99;
  const UNIT = 45;
  const OTHER_UNIT = 46;

  const CREATED = 1780000000;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_users'] = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One myapi_notifications row, with every column the resource selects.
   */
  private function notificationRow(array $spec) {
    $spec += [
      'uid'         => self::UID,
      'type'        => 'bulletin',
      'title'       => 'Aviso ' . $spec['id'],
      'body'        => 'Cuerpo ' . $spec['id'],
      'target'      => 'bulletin',
      'target_id'   => 700 + $spec['id'],
      'condominium' => NULL,
      'unit'        => NULL,
      'provider'    => NULL,
      'is_read'     => 0,
      'created'     => self::CREATED + $spec['id'],
      'read_at'     => NULL,
    ];

    return [
      'id'               => (string) $spec['id'],
      'uid'              => (string) $spec['uid'],
      'type'             => $spec['type'],
      'title'            => $spec['title'],
      'body'             => $spec['body'],
      'deep_link_target' => $spec['target'],
      'deep_link_id'     => $spec['target_id'] === NULL ? NULL : (string) $spec['target_id'],
      'condominium_id'   => $spec['condominium'] === NULL ? NULL : (string) $spec['condominium'],
      'unit_id'          => $spec['unit'] === NULL ? NULL : (string) $spec['unit'],
      'provider_id'      => $spec['provider'] === NULL ? NULL : (string) $spec['provider'],
      'is_read'          => (string) $spec['is_read'],
      'created'          => (string) $spec['created'],
      'read_at'          => $spec['read_at'] === NULL ? NULL : (string) $spec['read_at'],
    ];
  }

  private function seed(array $notifications) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $rows = [];
    foreach ($notifications as $spec) {
      $rows[] = $this->notificationRow($spec);
    }

    myapi_test_db_seed([
      'my_api_tokens' => [[
        'id'                => '1',
        'uid'               => (string) self::UID,
        'access_token_hash' => myapi_token_hash(self::TOKEN),
        'revoked'           => '0',
        'access_expires_at' => REQUEST_TIME + 1800,
      ]],
      'myapi_notifications' => $rows,
    ]);
    $GLOBALS['myapi_test_db_writes'] = [];
  }

  private function listRequest() {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture('myapi_notification_dispatch');
  }

  private function readRequest($id) {
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    return myapi_test_capture(function () use ($id) {
      myapi_notification_read_dispatch($id);
    });
  }

  private function readAllRequest() {
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    return myapi_test_capture('myapi_notification_read_all_dispatch');
  }

  private function ids(array $result) {
    return array_column($result['json']['data']['notifications'], 'id');
  }

  /**
   * The stored row of a notification, straight out of the fixture table.
   */
  private function storedRow($id) {
    foreach ($GLOBALS['myapi_test_db']['myapi_notifications'] as $row) {
      if ((string) $row['id'] === (string) $id) {
        return $row;
      }
    }

    return NULL;
  }

  /* -------------------------------------------------------------------------
   * Method routing on the three dispatchers.
   * ---------------------------------------------------------------------- */

  /**
   * The inbox is GET-only; the two write endpoints are PUT-only. Every other
   * verb is 405 on each of them, and the rejection runs no query.
   */
  public function testEachDispatcherAcceptsOnlyItsOwnVerb() {
    $cases = [
      'list'     => ['allowed' => 'GET', 'call' => function () { myapi_notification_dispatch(); }],
      'read'     => ['allowed' => 'PUT', 'call' => function () { myapi_notification_read_dispatch(1); }],
      'read-all' => ['allowed' => 'PUT', 'call' => function () { myapi_notification_read_all_dispatch(); }],
    ];

    foreach ($cases as $name => $case) {
      foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
        if ($method === $case['allowed']) {
          continue;
        }
        $this->seed([['id' => 1]]);
        $_SERVER['REQUEST_METHOD'] = $method;

        $result = myapi_test_capture($case['call']);

        $this->assertSame(405, $result['status'], $name . ' ' . $method);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $name . ' ' . $method);
        $this->assertSame([], myapi_test_db_queries(), $name . ' ' . $method);
      }
    }
  }

  /**
   * A lowercase verb still routes: the comparison goes through
   * myapi_request_method().
   */
  public function testLowercaseVerbsAreAccepted() {
    $this->seed([['id' => 1]]);

    $_SERVER['REQUEST_METHOD'] = 'get';
    $this->assertSame(200, myapi_test_capture('myapi_notification_dispatch')['status']);

    $_SERVER['REQUEST_METHOD'] = 'put';
    $this->assertSame(200, myapi_test_capture('myapi_notification_read_all_dispatch')['status']);
  }

  /* -------------------------------------------------------------------------
   * Authentication, on all three.
   * ---------------------------------------------------------------------- */

  /**
   * Each endpoint requires a valid token, and none of them touches the
   * notifications table when it fails.
   */
  public function testAllThreeEndpointsRequireAValidToken() {
    $calls = [
      'list'     => function () { return $this->listRequest(); },
      'read'     => function () { return $this->readRequest(1); },
      'read-all' => function () { return $this->readAllRequest(); },
    ];

    foreach ($calls as $name => $call) {
      $this->seed([['id' => 1]]);
      unset($_SERVER['HTTP_AUTHORIZATION']);
      $result = $call();
      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries(), $name);

      foreach ([
        'unknown' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'] = []; },
        'revoked' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['revoked'] = '1'; },
        'expired' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['access_expires_at'] = REQUEST_TIME - 1; },
        'blocked' => function () { $GLOBALS['myapi_test_users'][NotificationEndpointTest::UID]['status'] = 0; },
      ] as $break_name => $break) {
        $this->seed([['id' => 1]]);
        $break();

        $result = $call();

        $this->assertSame(401, $result['status'], $name . ' ' . $break_name);
        $this->assertSame([], myapi_test_db_queries('myapi_notifications'), $name . ' ' . $break_name);
        $this->assertSame([], myapi_test_db_writes(), $name . ' ' . $break_name);
      }
    }
  }

  /* -------------------------------------------------------------------------
   * GET /notifications — ownership, order, badge.
   * ---------------------------------------------------------------------- */

  /**
   * The inbox is the caller's OWN rows and nobody else's.
   */
  public function testTheInboxIsScopedToTheTokenUid() {
    $this->seed([
      ['id' => 1, 'uid' => self::UID],
      ['id' => 2, 'uid' => self::OTHER_UID],
      ['id' => 3, 'uid' => self::UID],
    ]);

    $result = $this->listRequest();

    $this->assertSame([3, 1], $this->ids($result));
    $this->assertSame(2, $result['json']['data']['pagination']['total']);
  }

  /**
   * The order is created DESC with id DESC as a deterministic tie-breaker —
   * which matters precisely because a fan-out writes every recipient's row with
   * the same timestamp.
   */
  public function testTheOrderIsCreatedThenIdBothDescending() {
    $this->seed([
      ['id' => 1, 'created' => self::CREATED],
      ['id' => 2, 'created' => self::CREATED],
      ['id' => 3, 'created' => self::CREATED - 100],
    ]);

    $this->assertSame([2, 1, 3], $this->ids($this->listRequest()));

    $order = myapi_test_db_queries('myapi_notifications')[2]['order'];
    $this->assertSame([
      ['field' => 'created', 'direction' => 'DESC'],
      ['field' => 'id', 'direction' => 'DESC'],
    ], $order);
  }

  /**
   * unread_count is the caller's unread total, INDEPENDENT of the page and of
   * the ?unread filter: it is the badge, not the size of the answer.
   */
  public function testUnreadCountIsTheBadgeAndNotTheSizeOfThePage() {
    $this->seed([
      ['id' => 1, 'is_read' => 0],
      ['id' => 2, 'is_read' => 0],
      ['id' => 3, 'is_read' => 1, 'read_at' => self::CREATED],
      ['id' => 4, 'is_read' => 0],
    ]);
    $_GET['limit'] = '1';

    $result = $this->listRequest();

    $this->assertCount(1, $result['json']['data']['notifications']);
    $this->assertSame(3, $result['json']['data']['unread_count']);
    $this->assertSame(4, $result['json']['data']['pagination']['total']);
  }

  /**
   * ?unread=1 narrows the list and the total, and leaves the badge alone.
   */
  public function testUnreadOneNarrowsTheListAndTheTotalButNotTheBadge() {
    $this->seed([
      ['id' => 1, 'is_read' => 0],
      ['id' => 2, 'is_read' => 1, 'read_at' => self::CREATED],
      ['id' => 3, 'is_read' => 0],
    ]);
    $_GET['unread'] = '1';

    $result = $this->listRequest();

    $this->assertSame([3, 1], $this->ids($result));
    $this->assertSame(2, $result['json']['data']['pagination']['total']);
    $this->assertSame(2, $result['json']['data']['unread_count']);
  }

  /**
   * Only the exact string '1' turns the filter on: it is a strict comparison,
   * so 'true', '01' and 1.0 are all "no filter" rather than a 422.
   */
  public function testOnlyTheExactStringOneEnablesTheUnreadFilter() {
    $this->seed([
      ['id' => 1, 'is_read' => 0],
      ['id' => 2, 'is_read' => 1, 'read_at' => self::CREATED],
    ]);

    foreach (['0', 'true', 'yes', '01', '', 'unread'] as $value) {
      $_GET = ['unread' => $value];

      $result = $this->listRequest();

      $this->assertSame(200, $result['status'], $value);
      $this->assertSame([2, 1], $this->ids($result), $value);
    }
  }

  /**
   * The badge counts unread rows of THIS user only.
   */
  public function testTheBadgeIgnoresOtherUsersUnreadRows() {
    $this->seed([
      ['id' => 1, 'uid' => self::UID, 'is_read' => 0],
      ['id' => 2, 'uid' => self::OTHER_UID, 'is_read' => 0],
      ['id' => 3, 'uid' => self::OTHER_UID, 'is_read' => 0],
    ]);

    $this->assertSame(1, $this->listRequest()['json']['data']['unread_count']);
  }

  /**
   * An empty inbox is a 200 with an empty array, a zero badge and
   * total_pages 0.
   */
  public function testAnEmptyInboxIsAnEmptyTwoHundred() {
    $this->seed([]);

    $result = $this->listRequest();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['notifications']);
    $this->assertSame(0, $result['json']['data']['unread_count']);
    $this->assertSame(0, $result['json']['data']['pagination']['total_pages']);
    $this->assertStringContainsString('"notifications":[]', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * GET /notifications — pagination.
   * ---------------------------------------------------------------------- */

  /**
   * The documented defaults, the clamping and the slicing.
   */
  public function testPaginationDefaultsClampsAndSlices() {
    $notifications = [];
    for ($i = 1; $i <= 7; $i++) {
      $notifications[] = ['id' => $i];
    }
    $this->seed($notifications);

    $this->assertSame(
      ['total' => 7, 'page' => 1, 'limit' => 20, 'total_pages' => 1],
      $this->listRequest()['json']['data']['pagination']
    );

    foreach (['0' => 20, 'x' => 20, '51' => 50, '3' => 3] as $sent => $expected) {
      $_GET = ['limit' => (string) $sent];
      $this->assertSame($expected, $this->listRequest()['json']['data']['pagination']['limit'], 'limit=' . $sent);
    }

    $_GET = ['limit' => '3', 'page' => '1'];
    $this->assertSame([7, 6, 5], $this->ids($this->listRequest()));
    $_GET['page'] = '3';
    $this->assertSame([1], $this->ids($this->listRequest()));
  }

  /**
   * '-1' is the documented sentinel here too: every row in one answer, page
   * forced to 1 and total_pages 1 — and 0 when the inbox is empty.
   */
  public function testTheMinusOneSentinelReturnsEverythingUnpaginated() {
    $notifications = [];
    for ($i = 1; $i <= 60; $i++) {
      $notifications[] = ['id' => $i];
    }
    $this->seed($notifications);
    $_GET = ['limit' => '-1', 'page' => '4'];

    $result = $this->listRequest();

    $this->assertCount(60, $result['json']['data']['notifications']);
    $this->assertSame(-1, $result['json']['data']['pagination']['limit']);
    $this->assertSame(1, $result['json']['data']['pagination']['page'], 'the page is forced back to 1');
    $this->assertSame(1, $result['json']['data']['pagination']['total_pages']);
    $this->assertNull(myapi_test_db_queries('myapi_notifications')[2]['range'], 'no range was applied');

    $this->seed([]);
    $_GET = ['limit' => '-1'];
    $this->assertSame(0, $this->listRequest()['json']['data']['pagination']['total_pages']);
  }

  /* -------------------------------------------------------------------------
   * GET /notifications — the condominium/unit scope (SPEC 26).
   * ---------------------------------------------------------------------- */

  /**
   * A GLOBAL notification — no condominium at all — is visible under EVERY
   * scope. The scope narrows the contextual notices; it never hides the ones
   * that belong to no context.
   */
  public function testAGlobalNotificationSurvivesEveryScope() {
    $this->seed([
      ['id' => 1, 'condominium' => NULL],
      ['id' => 2, 'condominium' => self::CONDOMINIUM],
      ['id' => 3, 'condominium' => self::OTHER_CONDOMINIUM],
    ]);

    $_GET = ['condominium' => (string) self::CONDOMINIUM];
    $this->assertSame([2, 1], $this->ids($this->listRequest()));

    $_GET = ['condominium' => (string) self::OTHER_CONDOMINIUM];
    $this->assertSame([3, 1], $this->ids($this->listRequest()));
  }

  /**
   * With a condominium and no unit, every unit of that condominium is in
   * scope — the unit constraint is dropped rather than defaulting to "no unit".
   */
  public function testACondominiumWithoutAUnitCoversAllItsUnits() {
    $this->seed([
      ['id' => 1, 'condominium' => self::CONDOMINIUM, 'unit' => NULL],
      ['id' => 2, 'condominium' => self::CONDOMINIUM, 'unit' => self::UNIT],
      ['id' => 3, 'condominium' => self::CONDOMINIUM, 'unit' => self::OTHER_UNIT],
    ]);
    $_GET = ['condominium' => (string) self::CONDOMINIUM];

    $this->assertSame([3, 2, 1], $this->ids($this->listRequest()));
  }

  /**
   * With a unit, the scope keeps that unit's rows AND the condominium-wide
   * ones (unit_id NULL), and drops the other units'.
   */
  public function testAUnitScopeKeepsTheCondominiumWideRowsToo() {
    $this->seed([
      ['id' => 1, 'condominium' => self::CONDOMINIUM, 'unit' => NULL],
      ['id' => 2, 'condominium' => self::CONDOMINIUM, 'unit' => self::UNIT],
      ['id' => 3, 'condominium' => self::CONDOMINIUM, 'unit' => self::OTHER_UNIT],
      ['id' => 4, 'condominium' => NULL, 'unit' => NULL],
    ]);
    $_GET = ['condominium' => (string) self::CONDOMINIUM, 'unit' => (string) self::UNIT];

    $this->assertSame([4, 2, 1], $this->ids($this->listRequest()));
  }

  /**
   * A unit WITHOUT a condominium is meaningless and is ignored: the answer is
   * the full inbox, not an empty one.
   */
  public function testAUnitWithoutACondominiumIsIgnored() {
    $this->seed([
      ['id' => 1, 'condominium' => self::CONDOMINIUM, 'unit' => self::OTHER_UNIT],
      ['id' => 2, 'condominium' => self::OTHER_CONDOMINIUM, 'unit' => NULL],
    ]);
    $_GET = ['unit' => (string) self::UNIT];

    $this->assertSame([2, 1], $this->ids($this->listRequest()));
  }

  /**
   * Both scope parameters are LAX: a malformed value is treated as absent and
   * never answers a 422 — the back-compatible behaviour for clients that never
   * send them.
   */
  public function testTheScopeParametersAreLax() {
    $this->seed([
      ['id' => 1, 'condominium' => self::CONDOMINIUM],
      ['id' => 2, 'condominium' => self::OTHER_CONDOMINIUM],
    ]);

    foreach (['0', '-3', 'abc', '', '1.5'] as $value) {
      $_GET = ['condominium' => $value];

      $result = $this->listRequest();

      $this->assertSame(200, $result['status'], $value);
      $this->assertSame([2, 1], $this->ids($result), $value);
    }
  }

  /**
   * The scope is applied to the THREE queries — the page, the total and the
   * badge — so the three always agree. A badge computed outside the scope
   * would show a number the list cannot explain.
   */
  public function testTheScopeIsAppliedToThePageTheTotalAndTheBadge() {
    $this->seed([
      ['id' => 1, 'condominium' => self::CONDOMINIUM, 'is_read' => 0],
      ['id' => 2, 'condominium' => self::OTHER_CONDOMINIUM, 'is_read' => 0],
      ['id' => 3, 'condominium' => self::OTHER_CONDOMINIUM, 'is_read' => 0],
    ]);
    $_GET = ['condominium' => (string) self::CONDOMINIUM];

    $result = $this->listRequest();

    $this->assertSame([1], $this->ids($result));
    $this->assertSame(1, $result['json']['data']['pagination']['total']);
    $this->assertSame(1, $result['json']['data']['unread_count']);
  }

  /**
   * With no scope requested the query is left untouched: no condition is added
   * at all, which is what keeps the endpoint byte-compatible with the clients
   * written before SPEC 26.
   */
  public function testNoScopeAddsNoCondition() {
    $this->seed([['id' => 1, 'condominium' => self::CONDOMINIUM]]);

    $this->listRequest();

    foreach (myapi_test_db_queries('myapi_notifications') as $query) {
      foreach ($query['conditions'] as $condition) {
        $this->assertNotSame('GROUP', $condition['operator'], 'no scope group was added');
      }
    }
  }

  /* -------------------------------------------------------------------------
   * PUT /notifications/%/read.
   * ---------------------------------------------------------------------- */

  /**
   * Marking an unread notification writes is_read and read_at, answers the
   * updated item and carries the translated success message.
   */
  public function testMarkingAnUnreadNotificationWritesAndAnswersTheItem() {
    $this->seed([['id' => 5, 'is_read' => 0]]);

    $result = $this->readRequest(5);

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);
    $this->assertTrue($result['json']['data']['is_read']);
    $this->assertSame(REQUEST_TIME, $result['json']['data']['read_at']);
    $this->assertArrayHasKey('message', $result['json']);
    $this->assertNotSame('', $result['json']['message']);

    $stored = $this->storedRow(5);
    $this->assertSame(1, $stored['is_read']);
    $this->assertSame(REQUEST_TIME, $stored['read_at']);
  }

  /**
   * THE IDEMPOTENCE. A second call answers the same 200 and does NOT move
   * read_at — the row is left exactly as the first call left it, and no UPDATE
   * is issued at all.
   */
  public function testMarkingAnAlreadyReadNotificationChangesNothing() {
    $earlier = self::CREATED;
    $this->seed([['id' => 5, 'is_read' => 1, 'read_at' => $earlier]]);

    $result = $this->readRequest(5);

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['data']['is_read']);
    $this->assertSame($earlier, $result['json']['data']['read_at'], 'read_at was not moved');
    $this->assertSame([], myapi_test_db_writes(), 'no UPDATE was issued');
    $this->assertSame((string) $earlier, $this->storedRow(5)['read_at']);
  }

  /**
   * Two calls in a row over the same fixture: the first writes, the second
   * does not, and both answer the same read_at. This is the idempotence stated
   * as a sequence rather than as two fixtures.
   */
  public function testTwoConsecutiveCallsAnswerTheSameReadAt() {
    $this->seed([['id' => 5, 'is_read' => 0]]);

    $first = $this->readRequest(5);
    $writes_after_first = count(myapi_test_db_writes());
    $second = $this->readRequest(5);

    $this->assertSame($first['json']['data']['read_at'], $second['json']['data']['read_at']);
    $this->assertSame(1, $writes_after_first);
    $this->assertCount(1, myapi_test_db_writes(), 'the second call wrote nothing');
  }

  /**
   * The UPDATE is scoped to the id AND the uid: a resource that dropped the
   * uid condition would let anyone mark a neighbour's notification.
   */
  public function testTheUpdateIsScopedToBothTheIdAndTheUid() {
    $this->seed([['id' => 5, 'is_read' => 0]]);

    $this->readRequest(5);

    $writes = myapi_test_db_writes('myapi_notifications');
    $this->assertCount(1, $writes);
    $values = array_column($writes[0]['conditions'], 'value', 'field');
    $this->assertSame(5, $values['id']);
    $this->assertSame(self::UID, (int) $values['uid']);
  }

  /**
   * Another user's notification is 404 and is NOT marked: the two halves of
   * the same guard.
   */
  public function testAnotherUsersNotificationIs404AndIsNeverMarked() {
    $this->seed([['id' => 5, 'uid' => self::OTHER_UID, 'is_read' => 0]]);

    $result = $this->readRequest(5);

    $this->assertSame(404, $result['status']);
    $this->assertSame('notification_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_writes());
    $this->assertSame('0', $this->storedRow(5)['is_read']);
  }

  /**
   * A missing id answers the SAME bytes as a foreign one: the endpoint never
   * reveals that a notification exists.
   */
  public function testAMissingIdIsIndistinguishableFromAForeignOne() {
    $this->seed([['id' => 5, 'uid' => self::OTHER_UID]]);

    $foreign = $this->readRequest(5);
    $missing = $this->readRequest(4242);

    $this->assertSame(404, $missing['status']);
    $this->assertSame($foreign['output'], $missing['output']);
  }

  /**
   * A non-numeric id casts to 0 and 404s instead of fataling — the route
   * wildcard is not validated by Drupal, so this is the first place that can
   * refuse it.
   */
  public function testANonNumericIdIs404() {
    $this->seed([['id' => 5]]);

    foreach (['abc', '', '0', '-1', 'abc5'] as $id) {
      $result = $this->readRequest($id);

      $this->assertSame(404, $result['status'], json_encode($id));
      $this->assertSame('notification_not_found', $result['json']['error_code'], json_encode($id));
    }
  }

  /**
   * '5abc' RESOLVES TO NOTIFICATION 5, and that is pinned as it is.
   *
   * The id is read with a plain `(int)` cast and not with ctype_digit(), and
   * PHP's cast reads the leading digits of a string and stops — so a client
   * that appends a suffix to the id still marks the notification. It is not a
   * hole (the uid scope still decides whose row it is, and the 404 of a
   * foreign row is unchanged) but it IS the route's behaviour, and a stricter
   * parse would change what some client already gets away with.
   *
   * Recorded as a finding in SPEC 121 rather than fixed here.
   */
  public function testANumericPrefixResolvesToThatId() {
    $this->seed([['id' => 5, 'is_read' => 0]]);

    $result = $this->readRequest('5abc');

    $this->assertSame(200, $result['status']);
    $this->assertSame(5, $result['json']['data']['id']);
    $this->assertTrue($result['json']['data']['is_read']);

    // And the uid scope still holds for the same sloppy id.
    $this->seed([['id' => 5, 'uid' => self::OTHER_UID, 'is_read' => 0]]);
    $this->assertSame(404, $this->readRequest('5abc')['status']);
  }

  /* -------------------------------------------------------------------------
   * PUT /notifications/read-all.
   * ---------------------------------------------------------------------- */

  /**
   * Every unread row of the caller is marked, and `marked` is how many.
   */
  public function testReadAllMarksEveryUnreadRowAndReportsHowMany() {
    $this->seed([
      ['id' => 1, 'is_read' => 0],
      ['id' => 2, 'is_read' => 0],
      ['id' => 3, 'is_read' => 1, 'read_at' => self::CREATED],
    ]);

    $result = $this->readAllRequest();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['marked' => 2], $result['json']['data']);
    $this->assertArrayHasKey('message', $result['json']);
    $this->assertSame('1', (string) $this->storedRow(1)['is_read']);
    $this->assertSame('1', (string) $this->storedRow(2)['is_read']);
  }

  /**
   * The already-read row is NOT touched: its read_at keeps the older value,
   * because the UPDATE carries an is_read = 0 condition.
   */
  public function testReadAllDoesNotMoveTheTimestampOfAnAlreadyReadRow() {
    $earlier = self::CREATED;
    $this->seed([
      ['id' => 1, 'is_read' => 0],
      ['id' => 2, 'is_read' => 1, 'read_at' => $earlier],
    ]);

    $this->readAllRequest();

    $this->assertSame((string) $earlier, (string) $this->storedRow(2)['read_at']);
    $this->assertSame(REQUEST_TIME, (int) $this->storedRow(1)['read_at']);
  }

  /**
   * A second call marks nothing and answers 0 — read-all is idempotent too.
   */
  public function testASecondReadAllMarksNothing() {
    $this->seed([['id' => 1, 'is_read' => 0], ['id' => 2, 'is_read' => 0]]);

    $first = $this->readAllRequest();
    $second = $this->readAllRequest();

    $this->assertSame(['marked' => 2], $first['json']['data']);
    $this->assertSame(['marked' => 0], $second['json']['data']);
  }

  /**
   * An inbox with nothing unread answers 0 and not an error.
   */
  public function testReadAllOnAnEmptyInboxAnswersZero() {
    $this->seed([]);

    $result = $this->readAllRequest();

    $this->assertSame(200, $result['status']);
    $this->assertSame(['marked' => 0], $result['json']['data']);
  }

  /**
   * ANOTHER USER'S ROWS ARE NEVER MARKED. The UPDATE carries the uid, and a
   * resource that lost it would silently clear the whole building's badges.
   */
  public function testReadAllNeverTouchesAnotherUsersRows() {
    $this->seed([
      ['id' => 1, 'uid' => self::UID, 'is_read' => 0],
      ['id' => 2, 'uid' => self::OTHER_UID, 'is_read' => 0],
      ['id' => 3, 'uid' => self::OTHER_UID, 'is_read' => 0],
    ]);

    $result = $this->readAllRequest();

    $this->assertSame(['marked' => 1], $result['json']['data']);
    $this->assertSame('0', (string) $this->storedRow(2)['is_read']);
    $this->assertSame('0', (string) $this->storedRow(3)['is_read']);

    $values = array_column(myapi_test_db_writes('myapi_notifications')[0]['conditions'], 'value', 'field');
    $this->assertSame(self::UID, (int) $values['uid']);
    $this->assertSame(0, $values['is_read']);
  }

  /**
   * READ-ALL IGNORES THE SCOPE. It is deliberately unscoped: the parameters of
   * SPEC 26 narrow the LIST, and marking everything read means everything.
   * Pinned so that adding a scope here becomes a decision.
   */
  public function testReadAllIgnoresTheCondominiumScope() {
    $this->seed([
      ['id' => 1, 'condominium' => self::CONDOMINIUM, 'is_read' => 0],
      ['id' => 2, 'condominium' => self::OTHER_CONDOMINIUM, 'is_read' => 0],
    ]);
    $_GET = ['condominium' => (string) self::CONDOMINIUM];

    $this->assertSame(['marked' => 2], $this->readAllRequest()['json']['data']);
  }

  /* -------------------------------------------------------------------------
   * The mapper.
   * ---------------------------------------------------------------------- */

  /**
   * The documented item: seven top-level keys with the deep_link sub-object in
   * the middle, and exactly five keys inside it.
   */
  public function testTheItemHasTheDocumentedShape() {
    $this->seed([['id' => 1]]);

    $item = $this->listRequest()['json']['data']['notifications'][0];

    $this->assertSame(
      ['id', 'type', 'title', 'body', 'deep_link', 'is_read', 'created_at', 'read_at'],
      array_keys($item)
    );
    $this->assertSame(
      ['target', 'id', 'unit', 'condominium', 'provider'],
      array_keys($item['deep_link'])
    );
  }

  /**
   * The casts: id and created_at are ints, is_read is a real BOOLEAN, and the
   * four nullable ids are ints when present and null when not — never 0.
   */
  public function testTheCastsOfTheMapper() {
    $this->seed([[
      'id'          => 5,
      'target_id'   => 701,
      'condominium' => self::CONDOMINIUM,
      'unit'        => self::UNIT,
      'provider'    => 88,
      'is_read'     => 1,
      'read_at'     => self::CREATED,
    ]]);

    $result = $this->listRequest();
    $item = $result['json']['data']['notifications'][0];

    $this->assertSame(5, $item['id']);
    $this->assertTrue($item['is_read']);
    $this->assertSame(self::CREATED, $item['read_at']);
    $this->assertSame(701, $item['deep_link']['id']);
    $this->assertSame(self::UNIT, $item['deep_link']['unit']);
    $this->assertSame(self::CONDOMINIUM, $item['deep_link']['condominium']);
    $this->assertSame(88, $item['deep_link']['provider']);
    $this->assertStringContainsString('"is_read":true', $result['output']);
  }

  /**
   * is_read is a boolean and not the '0'/'1' the driver answers: the app binds
   * it directly.
   */
  public function testIsReadIsABooleanInBothStates() {
    $this->seed([
      ['id' => 1, 'is_read' => 0],
      ['id' => 2, 'is_read' => 1, 'read_at' => self::CREATED],
    ]);

    $result = $this->listRequest();
    $items = $result['json']['data']['notifications'];

    $this->assertTrue($items[0]['is_read']);
    $this->assertFalse($items[1]['is_read']);
    $this->assertStringContainsString('"is_read":false', $result['output']);
  }

  /**
   * An unread notification answers a null read_at, and every absent context id
   * is null too — the app checks these before deep-linking.
   */
  public function testAbsentValuesAreNullAndNeverZero() {
    $this->seed([['id' => 1, 'target_id' => NULL, 'condominium' => NULL, 'unit' => NULL, 'provider' => NULL]]);

    $result = $this->listRequest();
    $item = $result['json']['data']['notifications'][0];

    $this->assertNull($item['read_at']);
    $this->assertNull($item['deep_link']['id']);
    $this->assertNull($item['deep_link']['unit']);
    $this->assertNull($item['deep_link']['condominium']);
    $this->assertNull($item['deep_link']['provider']);
    $this->assertStringContainsString('"provider":null', $result['output']);
  }

  /**
   * type, title, body and target travel verbatim: the mapper renames and
   * casts, it does not interpret.
   */
  public function testTheTextFieldsTravelVerbatim() {
    $this->seed([[
      'id'     => 1,
      'type'   => 'payment_approved',
      'title'  => 'Pago verificado',
      'body'   => "Su pago de $120,00\nfue verificado",
      'target' => 'payment',
    ]]);

    $item = $this->listRequest()['json']['data']['notifications'][0];

    $this->assertSame('payment_approved', $item['type']);
    $this->assertSame('Pago verificado', $item['title']);
    $this->assertSame("Su pago de $120,00\nfue verificado", $item['body']);
    $this->assertSame('payment', $item['deep_link']['target']);
  }

  /**
   * The single item answered by the read endpoint has the SAME shape as an
   * item of the list: one mapper, one contract.
   */
  public function testTheReadEndpointAnswersTheSameItemShapeAsTheList() {
    $this->seed([['id' => 5, 'is_read' => 0]]);

    $listed = $this->listRequest()['json']['data']['notifications'][0];
    $marked = $this->readRequest(5)['json']['data'];

    $this->assertSame(array_keys($listed), array_keys($marked));
    $this->assertSame(array_keys($listed['deep_link']), array_keys($marked['deep_link']));
  }

  /**
   * Every answer of the three endpoints carries the no-store headers.
   */
  public function testEveryAnswerIsUncacheable() {
    $this->seed([['id' => 1]]);

    foreach ([$this->listRequest(), $this->readRequest(1), $this->readAllRequest()] as $result) {
      $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
      $this->assertSame('nosniff', $result['headers']['X-Content-Type-Options']);
    }
  }
}
