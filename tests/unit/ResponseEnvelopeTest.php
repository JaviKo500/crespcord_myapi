<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';

/**
 * Unit tests for includes/myapi.response.inc (SPEC 73).
 *
 * The envelope. CLAUDE.md calls it "no exceptions": every endpoint of this API
 * answers through one of these two functions, so their shape is the only thing
 * the Flutter client is entitled to assume. Nothing had ever asserted it — SPEC
 * 21 could not, because both functions end in drupal_exit(), which would have
 * killed the runner in-process.
 *
 * The three stubs SPEC 73 added to tests/unit/bootstrap.php are what changed
 * that: drupal_exit() throws, drupal_add_http_header() records, and
 * drupal_json_encode() is core's own implementation. So these cases run the
 * REAL helpers and assert the REAL bytes they print — not a reimplementation
 * of the envelope, which would agree with itself and prove nothing.
 */
class ResponseEnvelopeTest extends TestCase {

  protected function setUp(): void {
    // Precondition, not decoration: myapi_respond()/myapi_error() translate
    // through the language myapi_get_lang() memoised for this process, and
    // every expected text below is the Spanish one. See the same guard, with
    // the long explanation, in PasswordResetPageTest.
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');
  }

  // ---------------------------------------------------------------------
  // myapi_respond()
  // ---------------------------------------------------------------------

  /**
   * The success envelope is exactly two keys, in this order, and the request
   * ends there.
   */
  public function testSuccessEnvelopeIsSuccessAndData() {
    $result = myapi_test_capture(function () {
      myapi_respond(['pong' => TRUE]);
    });

    $this->assertTrue($result['exited'], 'the request ends inside myapi_respond()');
    $this->assertSame(['success', 'data'], array_keys($result['json']));
    $this->assertTrue($result['json']['success']);
    $this->assertSame(['pong' => TRUE], $result['json']['data']);
  }

  /**
   * 200 is the default, and every other code is passed through untouched.
   *
   * The status travels in a 'Status' header rather than a body field, so a
   * client that only reads the body cannot tell 200 from 201 — which is
   * exactly why the header has to be right.
   */
  public function testStatusDefaultsTo200AndIsOverridable() {
    $default = myapi_test_capture(function () {
      myapi_respond([]);
    });
    $this->assertSame(200, $default['status']);

    $created = myapi_test_capture(function () {
      myapi_respond(['id' => 12], 201);
    });
    $this->assertSame(201, $created['status']);
  }

  /**
   * Content-Type is always application/json, including on errors — the client
   * parses the body the same way whatever happened.
   */
  public function testContentTypeIsAlwaysJson() {
    $ok = myapi_test_capture(function () {
      myapi_respond([]);
    });
    $this->assertSame('application/json', $ok['headers']['Content-Type']);

    $ko = myapi_test_capture(function () {
      myapi_error('server_error', 500);
    });
    $this->assertSame('application/json', $ko['headers']['Content-Type']);
  }

  /**
   * Every response forbids being stored, on both helpers.
   *
   * Not a style preference. Both functions end in drupal_exit(), which never
   * reaches Drupal's page delivery layer — the one that would otherwise have
   * sent the default Cache-Control of drupal_page_header(). Without these
   * headers the responses leave with NO cache directive at all, and every one
   * of them is somebody's receipts, claims or reservations: a CDN or a proxy
   * is then entitled to store a 200 and hand it to the next caller of the same
   * URL, which here means handing one neighbour's data to another.
   *
   * 'no-store' is asserted by name because it is the one that matters: it
   * forbids writing the response down at all, where 'no-cache' only forbids
   * reusing it without revalidating.
   */
  public function testEveryResponseIsUncacheable() {
    $cases = [
      'success' => myapi_test_capture(function () {
        myapi_respond(['pong' => TRUE]);
      }),
      'error'   => myapi_test_capture(function () {
        myapi_error('invalid_token', 401);
      }),
    ];

    foreach ($cases as $name => $result) {
      $this->assertStringContainsString('no-store', $result['headers']['Cache-Control'], $name);
      $this->assertStringContainsString('private', $result['headers']['Cache-Control'], $name);
      $this->assertSame('no-cache', $result['headers']['Pragma'], $name);
      $this->assertSame('0', $result['headers']['Expires'], $name);
      // The other end: an error body is JSON, and a browser that decides for
      // itself that it looks like HTML would render it.
      $this->assertSame('nosniff', $result['headers']['X-Content-Type-Options'], $name);
    }
  }

  /**
   * No 'message' key unless a catalogue key was passed.
   *
   * The field is optional by contract, so a client checking for its presence
   * must not find an empty string on every response that has nothing to say.
   */
  public function testNoMessageKeyWhenNoneWasRequested() {
    $result = myapi_test_capture(function () {
      myapi_respond(['a' => 1]);
    });

    $this->assertArrayNotHasKey('message', $result['json']);
  }

  /**
   * With a key, 'message' is the translated text — the key never reaches the
   * client.
   */
  public function testMessageIsTranslated() {
    $result = myapi_test_capture(function () {
      myapi_respond([], 200, 'logout_success');
    });

    $this->assertSame(['success', 'data', 'message'], array_keys($result['json']));
    $this->assertSame('Sesión cerrada correctamente.', $result['json']['message']);
  }

  /**
   * And its placeholders are substituted from the replacement map.
   */
  public function testMessagePlaceholdersAreSubstituted() {
    $result = myapi_test_capture(function () {
      myapi_respond([], 200, 'missing_field', ['@field' => 'token']);
    });

    $this->assertSame('Falta el campo requerido: token', $result['json']['message']);
  }

  /**
   * 'data' carries whatever the resource passed, with its JSON type intact: a
   * list stays a list, an empty array stays an array, a scalar stays a scalar.
   *
   * The empty-array case is the one that matters in practice — PHP cannot tell
   * [] from {} and several endpoints answer myapi_respond([]) on success, so
   * the client always receives an array there.
   */
  public function testDataKeepsItsJsonType() {
    $list = myapi_test_capture(function () {
      myapi_respond([['id' => 1], ['id' => 2]]);
    });
    $this->assertStringContainsString('"data":[{"id":1},{"id":2}]', $list['output']);

    $empty = myapi_test_capture(function () {
      myapi_respond([]);
    });
    $this->assertStringContainsString('"data":[]', $empty['output']);

    $null = myapi_test_capture(function () {
      myapi_respond(NULL);
    });
    $this->assertStringContainsString('"data":null', $null['output']);
  }

  // ---------------------------------------------------------------------
  // myapi_error()
  // ---------------------------------------------------------------------

  /**
   * The error envelope is exactly three keys, in this order.
   *
   * 'error_code' is the stable English key the app branches on and
   * 'error' is the translated sentence it may show — CLAUDE.md's contract, and
   * the reason the two are separate fields instead of one.
   */
  public function testErrorEnvelopeIsSuccessCodeAndMessage() {
    $result = myapi_test_capture(function () {
      myapi_error('invalid_credentials', 401);
    });

    $this->assertTrue($result['exited']);
    $this->assertSame(['success', 'error_code', 'error'], array_keys($result['json']));
    $this->assertFalse($result['json']['success']);
    $this->assertSame('invalid_credentials', $result['json']['error_code']);
    $this->assertSame('Usuario o contraseña incorrectos.', $result['json']['error']);
    $this->assertSame(401, $result['status']);
  }

  /**
   * The default status is 400. Every caller in the module passes an explicit
   * one, so this is the value a future caller gets by forgetting to.
   */
  public function testErrorStatusDefaultsTo400() {
    $result = myapi_test_capture(function () {
      myapi_error('invalid_field');
    });

    $this->assertSame(400, $result['status']);
  }

  /**
   * The replacement map lands in 'error' and never in 'error_code': the code
   * stays a fixed catalogue key the client can compare against, whatever the
   * offending field was called.
   */
  public function testReplacementsAffectTheTextAndNotTheCode() {
    $result = myapi_test_capture(function () {
      myapi_error('missing_field', 422, ['@field' => 'refresh_token']);
    });

    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertSame('Falta el campo requerido: refresh_token', $result['json']['error']);
  }

  /**
   * An unknown key still produces a well-formed envelope, with the key echoed
   * in both fields.
   *
   * A typo therefore degrades to an ugly message, never to a 500 or to a body
   * the client cannot parse — which is the whole point of myapi_t()'s fallback.
   */
  public function testUnknownKeyStillProducesAValidEnvelope() {
    $result = myapi_test_capture(function () {
      myapi_error('no_such_key', 422);
    });

    $this->assertFalse($result['json']['success']);
    $this->assertSame('no_such_key', $result['json']['error_code']);
    $this->assertSame('no_such_key', $result['json']['error']);
    $this->assertSame(422, $result['status']);
  }

  /**
   * Every status code the module uses survives the round trip as an int.
   *
   * The list is CLAUDE.md's: "HTTP status codes must be correct: 200, 201,
   * 400, 401, 403, 404, 405, 422, 429, 500."
   */
  public function testEveryDocumentedStatusCodeIsPassedThrough() {
    foreach ([400, 401, 403, 404, 405, 422, 429, 500] as $status) {
      $result = myapi_test_capture(function () use ($status) {
        myapi_error('server_error', $status);
      });

      $this->assertSame($status, $result['status'], (string) $status);
    }
  }

  // ---------------------------------------------------------------------
  // Encoding
  // ---------------------------------------------------------------------

  /**
   * The body is encoded with core's drupal_json_encode(), which escapes <, >,
   * ' and & as \uXXXX.
   *
   * That escaping is what makes a JSON response safe to drop into an HTML
   * document, and it changes the BYTES of every response this module sends —
   * so a test asserting a raw body has to expect it. A resource that reached
   * for json_encode() directly would produce a different payload here.
   */
  public function testBodyIsEncodedWithCoresHexEscaping() {
    $result = myapi_test_capture(function () {
      myapi_respond(['note' => '<b>a & b</b> \'quoted\'']);
    });

    $this->assertStringNotContainsString('<b>', $result['output'], 'raw markup never reaches the body');
    $this->assertStringContainsString("\\u003Cb\\u003E", $result['output'], '< and > escaped');
    $this->assertStringContainsString("\\u0026", $result['output'], '& escaped');
    $this->assertStringContainsString("\\u0027quoted\\u0027", $result['output'], "' escaped");
    // And it is still valid JSON that decodes back to the original value.
    $this->assertSame('<b>a & b</b> \'quoted\'', $result['json']['data']['note']);
  }

  /**
   * Accented text is not escaped into \u sequences by these flags, and comes
   * back byte-identical.
   */
  public function testAccentedTextSurvivesEncoding() {
    $result = myapi_test_capture(function () {
      myapi_error('too_many_attempts', 429);
    });

    $this->assertSame('Demasiados intentos. Inténtalo de nuevo más tarde.', $result['json']['error']);
  }

}
