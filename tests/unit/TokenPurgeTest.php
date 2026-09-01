<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.token.inc';

/**
 * Unit tests for the two purges of includes/myapi.token.inc.
 *
 * Both token tables were append-only until this code existed: a login wrote a
 * row, a refresh wrote another and revoked the first, a reset wrote one and
 * marked it used, and nothing ever deleted. What is being tested here is the
 * predicate that decides a row is dead — and the reason it deserves its own
 * suite is the direction of the two failures, which are not symmetric:
 *
 *   - Purging TOO LITTLE leaves hashes, IPs and User-Agents on disk for years
 *     after they can authenticate anything. Bad, and invisible.
 *   - Purging TOO MUCH deletes a live session. Every phone holding that token
 *     is logged out, at once, with no error anywhere that says why.
 *
 * So the cases below are weighted towards the second: three of them assert
 * that a row is KEPT, and they are the ones that would catch an off-by-one in
 * the grace window or an OR that should have been an AND.
 *
 * HOW A DELETION IS OBSERVED HERE. tests/unit has no database and db_delete()
 * throws on purpose (see myapi_test_record_write() in bootstrap.php), which is
 * exactly the signal wanted: reaching the DELETE means the SELECT decided the
 * row was deletable. deletionAttempt() below runs the purge and answers which
 * table the write went for, or NULL when no write was attempted at all. The
 * rows actually leaving the table are a question for tests/integration.
 */
class TokenPurgeTest extends TestCase {

  /**
   * Comfortably outside the default 7-day grace period.
   */
  const LONG_AGO = 2592000;

  /**
   * Comfortably inside it.
   */
  const RECENTLY = 3600;

  protected function setUp(): void {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_variables'] = [];
    $GLOBALS['myapi_test_db_writes'] = [];
  }

  protected function tearDown(): void {
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_variables'] = [];
    myapi_test_db_seed();
  }

  /**
   * Runs a purge and reports the table its DELETE went for, if any.
   *
   * @param callable $purge  myapi_token_purge() or its reset-token sibling.
   *
   * @return string|NULL  The table a deletion was attempted on, or NULL when
   *                      the purge found nothing and issued no write at all.
   */
  private function deletionAttempt(callable $purge) {
    try {
      $purge();
    }
    catch (RuntimeException $e) {
      // db_delete() throwing IS the observation; see the class docblock.
    }

    $writes = $GLOBALS['myapi_test_db_writes'];

    return $writes ? $writes[0]['table'] : NULL;
  }

  /* -------------------------------------------------------------------------
   * my_api_tokens — the rows that must be KEPT.
   * ---------------------------------------------------------------------- */

  /**
   * A live session is never touched.
   *
   * The case that matters most: getting this wrong logs every phone out.
   */
  public function testALiveTokenIsKept() {
    myapi_test_db_seed(['my_api_tokens' => [
      [
        'id'                 => 1,
        'refresh_expires_at' => REQUEST_TIME + self::LONG_AGO,
        'revoked'            => 0,
        'created'            => REQUEST_TIME - self::RECENTLY,
      ],
    ]]);

    $this->assertSame(0, myapi_token_purge());
    $this->assertSame([], $GLOBALS['myapi_test_db_writes'], 'no DELETE is issued when nothing is deletable');
  }

  /**
   * A row that expired inside the grace window is kept.
   *
   * The grace period is the whole point of the two dates: a dead credential
   * still answers "when did this device last log in, and from where" for a
   * week, which is the question support actually asks.
   */
  public function testATokenExpiredInsideTheGraceWindowIsKept() {
    myapi_test_db_seed(['my_api_tokens' => [
      [
        'id'                 => 1,
        'refresh_expires_at' => REQUEST_TIME - self::RECENTLY,
        'revoked'            => 0,
        'created'            => REQUEST_TIME - self::LONG_AGO,
      ],
    ]]);

    $this->assertSame(0, myapi_token_purge());
  }

  /**
   * A row revoked a moment ago is kept, even though it is already useless.
   *
   * Revocation is what a logout, a refresh rotation and a password reset all
   * do, so this is the most common shape in the table by far. It is dated from
   * `created` because a revoked row is never written again and that is the
   * only timestamp it has.
   */
  public function testARecentlyRevokedTokenIsKept() {
    myapi_test_db_seed(['my_api_tokens' => [
      [
        'id'                 => 1,
        'refresh_expires_at' => REQUEST_TIME + self::LONG_AGO,
        'revoked'            => 1,
        'created'            => REQUEST_TIME - self::RECENTLY,
      ],
    ]]);

    $this->assertSame(0, myapi_token_purge());
  }

  /* -------------------------------------------------------------------------
   * my_api_tokens — the rows that must GO.
   * ---------------------------------------------------------------------- */

  /**
   * A row past its refresh expiry plus the grace period is deleted.
   *
   * refresh_expires_at is the outer bound of the pair — the access token
   * always dies first, 30 minutes against 30 days — so past this date the row
   * is past everything.
   */
  public function testAnExpiredTokenIsPurged() {
    myapi_test_db_seed(['my_api_tokens' => [
      [
        'id'                 => 1,
        'refresh_expires_at' => REQUEST_TIME - self::LONG_AGO,
        'revoked'            => 0,
        'created'            => REQUEST_TIME - self::LONG_AGO,
      ],
    ]]);

    $this->assertSame('my_api_tokens', $this->deletionAttempt('myapi_token_purge'));
  }

  /**
   * A row revoked long ago is deleted even though its refresh_expires_at is
   * still in the future.
   *
   * This is the SECOND condition and the reason the predicate is an OR. An
   * active user rotates a token every 30 minutes and each rotation revokes the
   * previous one, so waiting for the 30-day expiry of every revoked row would
   * leave the bulk of the table behind for a month.
   */
  public function testALongRevokedTokenIsPurgedBeforeItsExpiry() {
    myapi_test_db_seed(['my_api_tokens' => [
      [
        'id'                 => 1,
        'refresh_expires_at' => REQUEST_TIME + self::LONG_AGO,
        'revoked'            => 1,
        'created'            => REQUEST_TIME - self::LONG_AGO,
      ],
    ]]);

    $this->assertSame('my_api_tokens', $this->deletionAttempt('myapi_token_purge'));
  }

  /* -------------------------------------------------------------------------
   * The bound, and the variable.
   * ---------------------------------------------------------------------- */

  /**
   * The SELECT that picks the victims is bounded.
   *
   * db_delete() has no ->range() in Drupal 7, which is why the ids are chosen
   * first and deleted by primary key. The ceiling is not a detail: the FIRST
   * run after this code is deployed meets every row ever written, and an
   * unbounded DELETE inside cron is how a cron run starts timing out.
   */
  public function testThePurgeSelectIsBounded() {
    myapi_test_db_seed(['my_api_tokens' => []]);
    myapi_token_purge();

    $queries = myapi_test_db_queries('my_api_tokens');
    $this->assertCount(1, $queries);
    $this->assertSame(['start' => 0, 'length' => MYAPI_TOKEN_PURGE_LIMIT], $queries[0]['range']);
  }

  /**
   * The grace period is one variable away, and it is what moves the window.
   *
   * With a grace of zero, the row of testATokenExpiredInsideTheGraceWindowIsKept()
   * — kept there — becomes deletable here. Same row, same clock, one variable.
   */
  public function testTheGraceWindowIsConfigurable() {
    $this->assertSame(MYAPI_TOKEN_PURGE_GRACE_DEFAULT, myapi_token_purge_grace());

    $GLOBALS['myapi_test_variables']['myapi_token_purge_grace'] = 0;
    $this->assertSame(0, myapi_token_purge_grace());

    myapi_test_db_seed(['my_api_tokens' => [
      [
        'id'                 => 1,
        'refresh_expires_at' => REQUEST_TIME - self::RECENTLY,
        'revoked'            => 0,
        'created'            => REQUEST_TIME - self::LONG_AGO,
      ],
    ]]);

    $this->assertSame('my_api_tokens', $this->deletionAttempt('myapi_token_purge'));
  }

  /* -------------------------------------------------------------------------
   * myapi_password_reset_tokens.
   * ---------------------------------------------------------------------- */

  /**
   * An unused reset token still inside its hour is kept.
   *
   * The one credential in this module that takes over an account without a
   * password, so purging a live one would break a reset in flight.
   */
  public function testALiveResetTokenIsKept() {
    myapi_test_db_seed(['myapi_password_reset_tokens' => [
      [
        'id'         => 1,
        'expires_at' => REQUEST_TIME + 3600,
        'used'       => 0,
        'created'    => REQUEST_TIME,
      ],
    ]]);

    $this->assertSame(0, myapi_password_reset_token_purge());
    $this->assertSame([], $GLOBALS['myapi_test_db_writes']);
  }

  /**
   * A token used a moment ago is kept for the grace period, like a revoked
   * access token — same two ways of dying, same dating.
   */
  public function testARecentlyUsedResetTokenIsKept() {
    myapi_test_db_seed(['myapi_password_reset_tokens' => [
      [
        'id'         => 1,
        'expires_at' => REQUEST_TIME + 3600,
        'used'       => 1,
        'created'    => REQUEST_TIME - self::RECENTLY,
      ],
    ]]);

    $this->assertSame(0, myapi_password_reset_token_purge());
  }

  /**
   * A spent reset token older than the grace period is deleted.
   *
   * There is no reason for one of these to outlive the week: it is either
   * already used or already expired, and it is the highest-value row in the
   * schema for anyone who gets a copy of the database.
   */
  public function testASpentResetTokenIsPurged() {
    myapi_test_db_seed(['myapi_password_reset_tokens' => [
      [
        'id'         => 1,
        'expires_at' => REQUEST_TIME + 3600,
        'used'       => 1,
        'created'    => REQUEST_TIME - self::LONG_AGO,
      ],
    ]]);

    $this->assertSame('myapi_password_reset_tokens', $this->deletionAttempt('myapi_password_reset_token_purge'));
  }

  /**
   * An expired reset token past the grace period is deleted whether or not it
   * was ever used — the first half of the same OR.
   */
  public function testAnExpiredResetTokenIsPurged() {
    myapi_test_db_seed(['myapi_password_reset_tokens' => [
      [
        'id'         => 1,
        'expires_at' => REQUEST_TIME - self::LONG_AGO,
        'used'       => 0,
        'created'    => REQUEST_TIME - self::LONG_AGO,
      ],
    ]]);

    $this->assertSame('myapi_password_reset_tokens', $this->deletionAttempt('myapi_password_reset_token_purge'));
  }

  /**
   * The two purges are independent: draining one table issues no query
   * against the other.
   *
   * They are called one after the other from myapi_cron(), and a shared helper
   * sits underneath both, so this pins that the helper is given the right
   * table each time.
   */
  public function testEachPurgeTouchesOnlyItsOwnTable() {
    myapi_test_db_seed(['my_api_tokens' => [], 'myapi_password_reset_tokens' => []]);

    myapi_token_purge();
    $this->assertCount(1, myapi_test_db_queries('my_api_tokens'));
    $this->assertCount(0, myapi_test_db_queries('myapi_password_reset_tokens'));
  }

}
