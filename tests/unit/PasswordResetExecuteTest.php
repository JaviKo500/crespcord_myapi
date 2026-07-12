<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../resources/auth.resource.inc';

/**
 * Unit tests for myapi_auth_password_reset_execute() in resources/auth.resource.inc.
 *
 * Only covers the two early-return validation branches (new_password length),
 * which run before any database access. The rest of that function touches
 * myapi_password_reset_tokens/my_api_tokens and Drupal's user_load()/
 * user_save(), and is out of scope for unit tests (see tests/integration for
 * that coverage). Relies on tests/unit/bootstrap.php stubbing
 * module_load_include() so this file-level call in auth.resource.inc does not
 * fatal outside Drupal.
 */
class PasswordResetExecuteTest extends TestCase {

  public function testTooShortPasswordReturnsFieldTooShort() {
    $result = myapi_auth_password_reset_execute('any-token', str_repeat('a', 7));

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_short', $result['error_code']);
    $this->assertSame(['@field' => 'new_password'], $result['replacements']);
  }

  public function testTooLongPasswordReturnsFieldTooLong() {
    $result = myapi_auth_password_reset_execute('any-token', str_repeat('a', 256));

    $this->assertFalse($result['ok']);
    $this->assertSame('field_too_long', $result['error_code']);
    $this->assertSame(['@field' => 'new_password'], $result['replacements']);
  }

}
