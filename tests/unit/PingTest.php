<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../resources/ping.resource.inc';

/**
 * Unit tests for resources/ping.resource.inc (SPEC 01, covered by SPEC 73).
 *
 * The reference resource. SPEC 01 built it to demonstrate the pattern every
 * other resource copies — a dispatcher that routes by HTTP method and answers
 * 405 for everything else, and a handler that goes through myapi_respond() —
 * and then nobody ever tested it: until SPEC 73 this file had no coverage in
 * any of the three layers, which made the project's own example the one piece
 * of it that could break unnoticed.
 *
 * It is also the smallest possible end-to-end check of the envelope: two
 * functions, no database, no request body. If these cases fail, the problem is
 * in myapi_respond()/myapi_error() or in the method routing, not in a resource.
 */
class PingTest extends TestCase {

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');
  }

  /**
   * GET answers 200 with the documented body.
   */
  public function testGetAnswersPong() {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $result = myapi_test_capture('myapi_ping_dispatch');

    $this->assertTrue($result['exited']);
    $this->assertSame(200, $result['status']);
    $this->assertSame(['success' => TRUE, 'data' => ['pong' => TRUE]], $result['json']);
  }

  /**
   * The handler on its own produces the same thing — the dispatcher adds
   * nothing to the response, it only chooses.
   */
  public function testHandlerProducesTheSameBody() {
    $result = myapi_test_capture('myapi_ping_get');

    $this->assertSame(['success' => TRUE, 'data' => ['pong' => TRUE]], $result['json']);
    $this->assertSame(200, $result['status']);
  }

  /**
   * Every other method is 405, which is the half of the dispatcher pattern
   * that is easy to get wrong by copying: a resource that forgets the else
   * branch answers its GET handler to a DELETE.
   */
  public function testEveryOtherMethodIs405() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = myapi_test_capture('myapi_ping_dispatch');

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame('Método no permitido.', $result['json']['error'], $method);
    }
  }

  /**
   * The method comparison goes through myapi_request_method(), so a client
   * sending a lowercase verb is still served.
   */
  public function testLowercaseMethodIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';

    $result = myapi_test_capture('myapi_ping_dispatch');

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['data']['pong']);
  }

}
