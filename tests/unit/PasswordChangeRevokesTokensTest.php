<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../myapi.module';

/**
 * Unit tests for myapi_user_update() (hook_user_update) in myapi.module.
 *
 * The hook ends every API session of an account whose password just changed,
 * wherever the change came from. POST /api/v1/auth/password/reset already did
 * that for its own flow; a password changed from /user/N/edit, by an
 * administrator on somebody else's account, or with `drush upwd`, left every
 * access and refresh token alive for up to 30 days.
 *
 * WHAT IS ACTUALLY BEING TESTED IS THE DISCRIMINATOR, and it cuts both ways:
 *
 *   - Missing a real password change leaves the old credential working, which
 *     is the hole.
 *   - Firing on any other profile save logs the whole site out every time an
 *     administrator edits a phone number, which is an outage.
 *
 * So there are as many "does NOT revoke" cases below as "does".
 *
 * HOW A REVOCATION IS OBSERVED HERE. tests/unit has no database and
 * db_update() throws on purpose (see myapi_test_record_write() in
 * bootstrap.php), which is exactly the signal wanted: reaching the UPDATE means
 * the hook decided the password changed. revocationAttempt() answers the table
 * the write went for, or NULL when the hook returned without writing.
 */
class PasswordChangeRevokesTokensTest extends TestCase {

  protected function setUp(): void {
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
  }

  protected function tearDown(): void {
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
  }

  /**
   * Runs the hook and reports the table it tried to write to, if any.
   *
   * @param array  $edit     The $edit array user_save() would pass.
   * @param object $account  The saved account.
   *
   * @return string|NULL  Table a write was attempted on, or NULL for none.
   */
  private function revocationAttempt(array $edit, $account) {
    try {
      myapi_user_update($edit, $account, 'account');
    }
    catch (RuntimeException $e) {
      // db_update() throwing IS the observation; see the class docblock.
    }

    $writes = $GLOBALS['myapi_test_db_writes'];

    return $writes ? $writes[0]['table'] : NULL;
  }

  /* -------------------------------------------------------------------------
   * The password changed.
   * ---------------------------------------------------------------------- */

  /**
   * $edit['pass'] is the signal, and it is the one the profile form sends.
   *
   * user_save() UNSETS the key when the password field was left blank, so its
   * presence really does mean "the password was changed" and not merely "the
   * form was submitted". Same shape as `drush upwd` and as this module's own
   * reset endpoint.
   */
  public function testAPasswordChangeThroughEditRevokesTheSessions() {
    $account = (object) ['uid' => 7];

    $this->assertSame('my_api_tokens', $this->revocationAttempt(['pass' => '$S$hashed'], $account));
  }

  /**
   * The second shape: the hash set straight on the account by a caller that
   * passes no $edit at all, caught by comparing against ->original.
   */
  public function testAPasswordChangedOnTheAccountObjectIsCaught() {
    $account = (object) [
      'uid'      => 7,
      'pass'     => '$S$new',
      'original' => (object) ['pass' => '$S$old'],
    ];

    $this->assertSame('my_api_tokens', $this->revocationAttempt([], $account));
  }

  /* -------------------------------------------------------------------------
   * The password did NOT change — every one of these must write nothing.
   * ---------------------------------------------------------------------- */

  /**
   * An ordinary profile save revokes nothing.
   *
   * The case that keeps the hook from being an outage: an administrator
   * editing an email, a phone or a role must not log that person's phone out.
   */
  public function testAnOrdinaryProfileSaveRevokesNothing() {
    $account = (object) ['uid' => 7];

    $this->assertNull($this->revocationAttempt(['mail' => 'a@b.c', 'status' => 1], $account));
    $this->assertSame([], $GLOBALS['myapi_test_db_writes']);
  }

  /**
   * A save that carries the SAME hash is not a change.
   *
   * This is what an unrelated field edit looks like on the ->original path:
   * the password travels on the object because it was loaded with it, not
   * because anybody touched it.
   */
  public function testAnUnchangedPasswordRevokesNothing() {
    $account = (object) [
      'uid'      => 7,
      'pass'     => '$S$same',
      'original' => (object) ['pass' => '$S$same'],
    ];

    $this->assertNull($this->revocationAttempt([], $account));
  }

  /**
   * An empty 'pass' is not a change either.
   *
   * user_save() is supposed to unset the key rather than pass '' through, and
   * this asserts the hook does not depend on it having done so — an empty
   * password would otherwise log the site out on every blank submit.
   */
  public function testAnEmptyPassKeyRevokesNothing() {
    $account = (object) ['uid' => 7];

    $this->assertNull($this->revocationAttempt(['pass' => ''], $account));
  }

  /**
   * A missing ->original is not a signal.
   *
   * Drupal only loads the unchanged entity in some paths, so its absence has
   * to read as "nothing known" rather than as a difference against NULL.
   */
  public function testAMissingOriginalIsNotASignal() {
    $account = (object) ['uid' => 7, 'pass' => '$S$whatever'];

    $this->assertNull($this->revocationAttempt([], $account));
  }

  /**
   * No uid, no revocation: the condition would otherwise be `uid = 0`, which
   * is the anonymous row and matches every token issued to nobody.
   */
  public function testAnAccountWithNoUidRevokesNothing() {
    $account = (object) ['uid' => 0];

    $this->assertNull($this->revocationAttempt(['pass' => '$S$hashed'], $account));
  }

}
