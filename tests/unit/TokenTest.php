<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.token.inc';

/**
 * Unit tests for the pure token helpers in includes/myapi.token.inc.
 *
 * Only covers myapi_token_hash() and the myapi_token_generate_*() functions —
 * the rest of that file touches the database and is out of scope for unit
 * tests (see tests/integration for that coverage).
 */
class TokenTest extends TestCase {

  public function testHashIsDeterministic() {
    $this->assertSame(myapi_token_hash('same-input'), myapi_token_hash('same-input'));
  }

  public function testHashIs64HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', myapi_token_hash('anything'));
  }

  public function testGenerateAccessIs64HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', myapi_token_generate_access());
  }

  public function testGenerateAccessDiffersBetweenCalls() {
    $this->assertNotSame(myapi_token_generate_access(), myapi_token_generate_access());
  }

  public function testGenerateRefreshIs128HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', myapi_token_generate_refresh());
  }

  public function testGenerateRefreshDiffersBetweenCalls() {
    $this->assertNotSame(myapi_token_generate_refresh(), myapi_token_generate_refresh());
  }

  public function testGenerateResetIs64HexChars() {
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', myapi_token_generate_reset());
  }

  public function testGenerateResetDiffersBetweenCalls() {
    $this->assertNotSame(myapi_token_generate_reset(), myapi_token_generate_reset());
  }

}
