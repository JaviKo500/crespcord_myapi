<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/auth.resource.inc';

/**
 * Unit tests for myapi_auth_build_user_payload() (SPEC 73).
 *
 * The "user" sub-object of POST /auth/login and POST /auth/refresh. It exists
 * as a shared function precisely so the two endpoints cannot drift apart, and
 * these cases are what make that guarantee checkable: the app reads
 * data.user.roles[].uid to decide which screens it opens, so a role id that
 * arrives as a string instead of an int, or a key that quietly disappears from
 * the object, is a client-side bug with no server-side symptom.
 *
 * The function's only outside call is myapi_user_fetch_profile_fields(), four
 * LEFT JOINs over the profile field tables, which tests/unit/bootstrap.php
 * replaces with a caller-controlled row (see the redeclare note there). What is
 * under test is everything around it: the casts, the role mapping and the shape
 * of the object. Loading the account, the joins themselves and the token pair
 * next to this object in the envelope are covered over real HTTP in
 * tests/integration/MyapiAuthTestCase.test.
 */
class AuthUserPayloadTest extends TestCase {

  protected function tearDown(): void {
    unset($GLOBALS['myapi_test_profile_fields']);
  }

  /**
   * Builds an account object shaped like the one user_load() returns.
   *
   * @param array $overrides  Property overrides.
   *
   * @return object  The account.
   */
  private function account(array $overrides = []) {
    return (object) ($overrides + [
      'uid'   => 7,
      'name'  => 'ana',
      'mail'  => 'ana@example.com',
      'roles' => [2 => 'authenticated user'],
    ]);
  }

  /**
   * The full object, key by key, for an account with every profile field
   * filled in.
   *
   * assertSame() on the whole array is deliberate: it pins the KEY ORDER too,
   * which is the order drupal_json_encode() prints, and a client reading the
   * response by eye during an incident should keep finding the same shape.
   */
  public function testCompletePayload() {
    $GLOBALS['myapi_test_profile_fields'] = [
      'first_name' => 'Ana',
      'last_name'  => 'Pérez',
      'dni'        => '0102030405',
      'phone'      => '0999123456',
    ];

    $payload = myapi_auth_build_user_payload($this->account());

    $this->assertSame([
      'uid'        => 7,
      'name'       => 'ana',
      'mail'       => 'ana@example.com',
      'first_name' => 'Ana',
      'last_name'  => 'Pérez',
      'dni'        => '0102030405',
      'phone'      => '0999123456',
      'picture'    => NULL,
      'roles'      => [
        ['name' => 'authenticated user', 'uid' => 2],
      ],
    ], $payload);
  }

  /**
   * The keys are always the same nine, whatever the account looks like — the
   * app can read them without a guard.
   */
  public function testKeysAreAlwaysPresent() {
    $payload = myapi_auth_build_user_payload($this->account());

    $this->assertSame(
      ['uid', 'name', 'mail', 'first_name', 'last_name', 'dni', 'phone', 'picture', 'roles'],
      array_keys($payload)
    );
  }

  /**
   * uid is cast to int on the way out.
   *
   * Drupal's database layer hands back column values as strings, so
   * $account->uid is '7' and not 7 whenever the account came from a query. The
   * cast is what keeps the JSON printing 7 instead of "7", which is what the
   * app's parser expects.
   */
  public function testUidIsCastToInt() {
    $payload = myapi_auth_build_user_payload($this->account(['uid' => '7']));

    $this->assertSame(7, $payload['uid']);
  }

  /**
   * The role id is cast the same way and for the same reason. $account->roles
   * is keyed by rid, and those keys arrive as strings from the same layer.
   */
  public function testRoleIdIsCastToInt() {
    $payload = myapi_auth_build_user_payload($this->account([
      'roles' => ['2' => 'authenticated user', '4' => 'administrador'],
    ]));

    $this->assertSame([
      ['name' => 'authenticated user', 'uid' => 2],
      ['name' => 'administrador', 'uid' => 4],
    ], $payload['roles']);
  }

  /**
   * Roles come out as a LIST, in the account's own order, reindexed from 0 —
   * so drupal_json_encode() prints a JSON array and never an object keyed by
   * role id. That reindexing is the whole point of rebuilding the array
   * instead of returning $account->roles as it is.
   */
  public function testRolesAreAListInAccountOrder() {
    $payload = myapi_auth_build_user_payload($this->account([
      'roles' => [9 => 'guardia', 2 => 'authenticated user', 5 => 'residente'],
    ]));

    $this->assertSame([0, 1, 2], array_keys($payload['roles']));
    $this->assertSame(['guardia', 'authenticated user', 'residente'], array_column($payload['roles'], 'name'));
    $this->assertSame('[', substr(json_encode($payload['roles']), 0, 1), 'serializes as a JSON array');
  }

  /**
   * No roles at all is an empty list, not NULL and not an empty object: the
   * app iterates the field unconditionally.
   */
  public function testNoRolesIsAnEmptyList() {
    $payload = myapi_auth_build_user_payload($this->account(['roles' => []]));

    $this->assertSame([], $payload['roles']);
    $this->assertSame('[]', json_encode($payload['roles']));
  }

  /**
   * An account with no profile values still answers the four keys, as NULL.
   *
   * This is the shape myapi_user_fetch_profile_fields() returns for a user
   * whose fields were never filled in — including the empty string, which it
   * normalises to NULL so the app does not have to tell "" from "not set".
   */
  public function testMissingProfileFieldsAreNull() {
    $payload = myapi_auth_build_user_payload($this->account());

    $this->assertNull($payload['first_name']);
    $this->assertNull($payload['last_name']);
    $this->assertNull($payload['dni']);
    $this->assertNull($payload['phone']);
  }

  /**
   * The account's own strings pass through untouched — no trimming, no
   * escaping. Escaping belongs to drupal_json_encode() at the edge, and doing
   * it here would double-escape a name with an accent.
   */
  public function testAccountStringsPassThroughUnchanged() {
    $payload = myapi_auth_build_user_payload($this->account([
      'name' => 'José Ángel',
      'mail' => 'jose.angel+test@example.com',
    ]));

    $this->assertSame('José Ángel', $payload['name']);
    $this->assertSame('jose.angel+test@example.com', $payload['mail']);
  }

  /**
   * 'picture' is a hardcoded NULL, not a value read off the account.
   *
   * The key exists because the app's model declares it; the feature behind it
   * was never built. Pinned so a future avatar implementation has to change
   * this test on purpose, rather than an unrelated edit silently starting to
   * leak a file object into the response.
   */
  public function testPictureIsAlwaysNull() {
    $payload = myapi_auth_build_user_payload($this->account(['picture' => (object) ['fid' => 3]]));

    $this->assertNull($payload['picture']);
  }

}
