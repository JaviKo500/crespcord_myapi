<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.auth.inc';

/**
 * Unit tests for myapi_auth_parse_bearer() in includes/myapi.auth.inc.
 *
 * Only covers this function — the rest of that file (myapi_auth_require_access_token())
 * touches the database and Drupal's error/response helpers, and is out of
 * scope for unit tests (see tests/integration for that coverage).
 */
class AuthBearerTest extends TestCase {

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    parent::tearDown();
  }

  public function testReturnsNullWhenHeaderIsAbsent() {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $this->assertNull(myapi_auth_parse_bearer());
  }

  public function testReturnsTokenFromValidHeader() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
    $this->assertSame('abc123', myapi_auth_parse_bearer());
  }

  public function testPrefixIsCaseInsensitive() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'bearer abc';
    $this->assertSame('abc', myapi_auth_parse_bearer());
  }

  public function testReturnsNullForWrongScheme() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic xxx';
    $this->assertNull(myapi_auth_parse_bearer());
  }

  public function testReturnsNullForMalformedPrefix() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Beareraaa';
    $this->assertNull(myapi_auth_parse_bearer());
  }

  public function testReturnsNullForEmptyHeader() {
    $_SERVER['HTTP_AUTHORIZATION'] = '';
    $this->assertNull(myapi_auth_parse_bearer());
  }

}
