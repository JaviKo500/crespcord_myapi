<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.flood.inc';
require_once __DIR__ . '/../../resources/auth.resource.inc';

/**
 * Unit tests for the HTML renderers of the password/reset page (SPEC 73).
 *
 * `GET/POST password/reset` is the one screen of this API a real user ever
 * looks at — the fallback the reset email opens when the deep link does not
 * take. SPEC 21 left it out of every layer ("no sigue el envelope JSON, se
 * probaría distinto"), which left the only page in the project that prints
 * attacker-supplied input with no coverage at all. This class closes that:
 * the four renderers are pure string builders, so they belong here and not in
 * an HTML-parsing suite.
 *
 *   - myapi_auth_password_reset_page_html_shell()   — document, CSS, logo
 *   - myapi_auth_password_reset_page_html_message() — the message-only screens
 *   - myapi_auth_password_reset_page_html_form()    — the form screen
 *   - myapi_auth_password_reset_page_script()       — the inline enhancement
 *
 * Their three callers are covered too, at the bottom of this class: page(),
 * page_get() and page_post() end in drupal_exit(), which tests/unit/bootstrap.php
 * turns into a captured body plus a thrown MyapiExit, so which screen each
 * branch chooses is assertable. Everything up to the first db_select() runs
 * here; the branches past it — a real token being spent, the password being
 * written, the sessions being revoked — need a Drupal sandbox and stay in
 * tests/integration.
 *
 * The escaping cases are the reason the class exists. The token comes straight
 * off the query string and the error message comes out of the catalogue, and
 * both are printed into the document; check_plain() is the only thing between
 * a crafted reset link and script execution on this page.
 */
class PasswordResetPageTest extends TestCase {

  protected function setUp(): void {
    unset($GLOBALS['myapi_test_variables']);
    unset($_GET['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    $_GET = [];
    $_POST = [];
    $GLOBALS['myapi_test_flood_calls'] = [];
    $GLOBALS['myapi_test_ip'] = '198.51.100.9';
    unset($GLOBALS['myapi_test_flood_allowed']);

    // Precondition, not decoration: the renderers call myapi_get_lang(), which
    // memoises its answer in a function static for the whole PHP process. With
    // nothing to resolve from, that answer is the documented default 'es', and
    // every expected string below is the Spanish one. If a future test file
    // that sorts BEFORE this one resolves the language to 'en' first, this
    // assertion says so instead of letting six cases fail on their texts.
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');
  }

  protected function tearDown(): void {
    unset($GLOBALS['myapi_test_variables'], $GLOBALS['myapi_test_ip'], $GLOBALS['myapi_test_flood_allowed']);
    $GLOBALS['myapi_test_flood_calls'] = [];
    $_GET = [];
    $_POST = [];
  }

  // ---------------------------------------------------------------------
  // Shell
  // ---------------------------------------------------------------------

  /**
   * The shell is a complete, self-contained document: doctype, the resolved
   * language on <html>, the viewport meta the phone browsers need, the title,
   * the inline CSS and the logo. Nothing is loaded from the theme layer, which
   * is what lets this page render on a site whose theme is broken.
   */
  public function testShellIsACompleteDocument() {
    $html = myapi_auth_password_reset_page_html_shell('<p>inner</p>');

    $this->assertStringStartsWith('<!DOCTYPE html><html lang="es">', $html);
    $this->assertStringEndsWith('</body></html>', $html);
    $this->assertStringContainsString('<meta charset="utf-8">', $html);
    $this->assertStringContainsString('name="viewport"', $html);
    $this->assertStringContainsString('<title>Restablece tu contraseña</title>', $html);
    $this->assertStringContainsString('<style>' . myapi_auth_password_reset_page_css() . '</style>', $html);
    $this->assertStringContainsString(myapi_auth_password_reset_page_logo_svg(), $html);
    $this->assertStringContainsString('<p>inner</p>', $html);
  }

  /**
   * The inner HTML is inserted as-is: the shell trusts its caller, which is
   * why every caller sanitizes before building it. Pinned so nobody "fixes"
   * this by adding a check_plain() here, which would print the form's own
   * markup as visible text.
   */
  public function testShellDoesNotEscapeItsInnerHtml() {
    $html = myapi_auth_password_reset_page_html_shell('<form action="x"><input name="y"></form>');

    $this->assertStringContainsString('<form action="x"><input name="y"></form>', $html);
  }

  /**
   * The meta-refresh goes inside <head>, before the title, and only when one
   * was passed. In the browser, a refresh tag placed after </head> is ignored,
   * so its position is the behaviour and not a formatting detail.
   */
  public function testShellPlacesTheMetaRefreshInsideHead() {
    $tag = '<meta http-equiv="refresh" content="0;url=myapp://reset-password">';

    $html = myapi_auth_password_reset_page_html_shell('<p>x</p>', $tag);
    $this->assertStringContainsString($tag, $html);
    $this->assertLessThan(strpos($html, '</head>'), strpos($html, $tag), 'inside <head>');

    $this->assertStringNotContainsString('http-equiv="refresh"', myapi_auth_password_reset_page_html_shell('<p>x</p>'));
  }

  // ---------------------------------------------------------------------
  // Message screen
  // ---------------------------------------------------------------------

  /**
   * The three message screens — invalid link, too many attempts, success —
   * are a heading and a paragraph inside the same shell, with no form: there
   * is nothing left to submit on any of them.
   */
  public function testMessageScreenPrintsTheMessageAndNoForm() {
    $html = myapi_auth_password_reset_page_html_message('Tu contraseña fue actualizada.');

    $this->assertStringContainsString('<h1>Restablece tu contraseña</h1>', $html);
    $this->assertStringContainsString('<p class="message">Tu contraseña fue actualizada.</p>', $html);
    $this->assertStringNotContainsString('<form', $html);
    $this->assertStringNotContainsString('<script', $html);
  }

  /**
   * The message is escaped before it is printed.
   *
   * Today every caller passes a myapi_t() value, so nothing hostile can get
   * this far — but the parameter is a plain string and the next caller may not
   * be so careful. The escaping is what makes that safe by construction.
   */
  public function testMessageScreenEscapesTheMessage() {
    $html = myapi_auth_password_reset_page_html_message('<script>alert(1)</script>');

    $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
  }

  /**
   * Accented text survives intact: check_plain() escapes markup, not UTF-8,
   * and the document declares the charset that renders it.
   */
  public function testMessageScreenKeepsAccentedText() {
    $html = myapi_auth_password_reset_page_html_message(myapi_t('too_many_attempts'));

    $this->assertStringContainsString('Demasiados intentos. Inténtalo de nuevo más tarde.', $html);
  }

  // ---------------------------------------------------------------------
  // Form screen
  // ---------------------------------------------------------------------

  /**
   * The form the user actually submits: the password field, the hidden token
   * that identifies the request, and the submit button. Those two inputs are
   * exactly what myapi_auth_password_reset_page_post() reads out of $_POST, so
   * this case is the contract between the two halves of the page.
   */
  public function testFormCarriesTheFieldsThePostHandlerReads() {
    $html = myapi_auth_password_reset_page_html_form('tok123');

    $this->assertStringContainsString('<form method="post" action="/password/reset?lang=es">', $html);
    $this->assertStringContainsString('<input type="password" id="new_password" name="new_password" required>', $html);
    $this->assertStringContainsString('<input type="hidden" name="token" value="tok123">', $html);
    $this->assertStringContainsString('<button type="submit" id="submit-btn">Restablecer contraseña</button>', $html);
  }

  /**
   * The confirm field has an id but NO name, so the browser never submits it.
   *
   * That is what keeps it a client-side convenience: the server's contract is
   * token + new_password, and a confirmation value arriving in $_POST would be
   * one more thing the handler has to ignore on purpose.
   */
  public function testConfirmFieldIsNotSubmitted() {
    $html = myapi_auth_password_reset_page_html_form('tok123');

    $this->assertStringContainsString('<input type="password" id="confirm_password">', $html);
    $this->assertStringNotContainsString('name="confirm_password"', $html);
  }

  /**
   * The action carries the language resolved for THIS request, so the page
   * re-rendered after the POST — error or success — stays in the language the
   * email link opened it in, instead of falling back to the browser's
   * Accept-Language.
   */
  public function testFormActionCarriesTheResolvedLanguage() {
    $html = myapi_auth_password_reset_page_html_form('tok123');

    $this->assertStringContainsString('action="/password/reset?lang=es"', $html);
  }

  /**
   * The token is escaped into the hidden field.
   *
   * This is the injection that matters on this page: the token is whatever the
   * query string of the opened link contained, and it is printed inside an
   * attribute. A value carrying a quote must not be able to close it and start
   * a new one.
   */
  public function testTokenIsEscapedInTheHiddenField() {
    $html = myapi_auth_password_reset_page_html_form('"><script>alert(1)</script>');

    $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    $this->assertStringContainsString(
      '<input type="hidden" name="token" value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">',
      $html
    );
  }

  /**
   * An error message is rendered in its own alert block, escaped like every
   * other value, and the form is still there underneath so the user can try
   * again without reopening the link.
   */
  public function testErrorMessageIsRenderedAboveTheForm() {
    $message = myapi_t('field_too_short', ['@field' => 'new_password']);

    $html = myapi_auth_password_reset_page_html_form('tok123', $message);

    $this->assertStringContainsString('<div class="alert">Campo demasiado corto: new_password</div>', $html);
    $this->assertLessThan(strpos($html, '<form'), strpos($html, 'class="alert"'), 'above the form');
    $this->assertStringContainsString('name="new_password"', $html);
  }

  /**
   * With no error there is no alert block at all — an empty one would print a
   * styled, blank box on the first render.
   */
  public function testNoAlertBlockWithoutAnError() {
    $this->assertStringNotContainsString('class="alert"', myapi_auth_password_reset_page_html_form('tok123'));
  }

  /**
   * The error message is escaped too.
   */
  public function testErrorMessageIsEscaped() {
    $html = myapi_auth_password_reset_page_html_form('tok123', '<img src=x onerror=alert(1)>');

    $this->assertStringNotContainsString('<img src=x', $html);
    $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
  }

  // ---------------------------------------------------------------------
  // Deep link
  // ---------------------------------------------------------------------

  /**
   * The meta-refresh into the app is added only on the initial GET.
   *
   * On a POST re-render it must not be: the user is on the web fallback
   * BECAUSE the app did not open, and bouncing them at the app again would
   * throw away the error they were meant to read.
   */
  public function testDeepLinkOnlyOnTheInitialRender() {
    $with = myapi_auth_password_reset_page_html_form('tok123', NULL, TRUE);
    $this->assertStringContainsString(
      '<meta http-equiv="refresh" content="0;url=myapp://reset-password?token=tok123">',
      $with
    );

    $this->assertStringNotContainsString('http-equiv="refresh"', myapi_auth_password_reset_page_html_form('tok123'));
    $this->assertStringNotContainsString('http-equiv="refresh"', myapi_auth_password_reset_page_html_form('tok123', 'error'));
  }

  /**
   * The base of the deep link is a Drupal variable, so the app's scheme can be
   * changed on the site without a deploy. Its default is the shipped
   * 'myapp://reset-password', asserted in the case above.
   */
  public function testDeepLinkBaseIsConfigurable() {
    $GLOBALS['myapi_test_variables']['myapi_password_reset_deep_link_base'] = 'crespcord://reset';

    $html = myapi_auth_password_reset_page_html_form('tok123', NULL, TRUE);

    $this->assertStringContainsString('content="0;url=crespcord://reset?token=tok123">', $html);
  }

  /**
   * The token is url-encoded into the deep link and then escaped into the
   * attribute — two different jobs, in that order.
   *
   * rawurlencode() first is what keeps a token containing '&' or '#' from
   * splitting into a second query parameter or a fragment, which would hand
   * the app a truncated token; check_plain() after is what keeps the same
   * value from closing the attribute.
   */
  public function testDeepLinkTokenIsUrlEncodedThenEscaped() {
    $html = myapi_auth_password_reset_page_html_form('a&b#c "d"', NULL, TRUE);

    $this->assertStringContainsString('content="0;url=myapp://reset-password?token=a%26b%23c%20%22d%22">', $html);
    $this->assertStringNotContainsString('?token=a&b#c', $html);
  }

  // ---------------------------------------------------------------------
  // Inline script
  // ---------------------------------------------------------------------

  /**
   * The enhancement script ships with the form and only with the form.
   */
  public function testScriptShipsWithTheFormOnly() {
    $script = myapi_auth_password_reset_page_script();

    $this->assertStringContainsString($script, myapi_auth_password_reset_page_html_form('tok123'));
    $this->assertStringNotContainsString($script, myapi_auth_password_reset_page_html_message('done'));
  }

  /**
   * The script is a static string with no interpolation — the heredoc is
   * nowdoc-quoted, so no value from the request can ever reach it. This is
   * what makes it safe to print unescaped inside <script>, where check_plain()
   * would not have protected anything anyway.
   */
  public function testScriptContainsNoRequestData() {
    $script = myapi_auth_password_reset_page_script();

    $this->assertStringStartsWith('<script>', trim($script));
    $this->assertStringEndsWith('</script>', trim($script));
    $this->assertSame($script, myapi_auth_password_reset_page_script(), 'no per-request state');
    $this->assertStringNotContainsString('$', $script, 'no PHP interpolation left in the output');
  }

  /**
   * The four requirement lines the script drives are the ones the markup
   * declares, with the data-rule attributes it queries them by. They are the
   * hints only — the server's rule is the 8-255 range of
   * myapi_auth_password_reset_execute(), which PasswordResetExecuteTest covers.
   */
  public function testRequirementChecklistMatchesTheScriptRules() {
    $html = myapi_auth_password_reset_page_html_form('tok123');

    foreach (['length' => 'Al menos 8 caracteres', 'upper' => 'Una letra mayúscula', 'number' => 'Un número', 'symbol' => 'Un símbolo'] as $rule => $label) {
      $this->assertStringContainsString('data-rule="' . $rule . '"', $html, $rule);
      $this->assertStringContainsString($label, $html, $rule);
    }
  }

  // ---------------------------------------------------------------------
  // The request handlers
  //
  // Everything below runs the real page functions end to end, up to the first
  // db_select(). What they choose to print is the behaviour; the markup they
  // print was already pinned above.
  // ---------------------------------------------------------------------

  /**
   * Runs a page handler and returns the captured screen.
   *
   * @param string $function  Page function name.
   *
   * @return array  The captured response, with 'output' carrying the HTML.
   */
  private function render($function) {
    return myapi_test_capture($function);
  }

  /**
   * GET with a token renders the form, with the deep-link meta-refresh.
   *
   * This is the screen the email link opens: the refresh tries the app first
   * and the form is what the user sees when the app is not installed.
   */
  public function testGetWithATokenRendersTheFormAndTriesTheApp() {
    $_GET['token'] = 'tok123';

    $result = $this->render('myapi_auth_password_reset_page_get');

    $this->assertTrue($result['exited']);
    $this->assertStringContainsString('<form method="post"', $result['output']);
    $this->assertStringContainsString('value="tok123"', $result['output']);
    $this->assertStringContainsString('content="0;url=myapp://reset-password?token=tok123"', $result['output']);
  }

  /**
   * GET without a token renders the "invalid link" message and no form.
   *
   * There is nothing to submit without a token, so offering the form would
   * only produce a second error after the user typed a password.
   */
  public function testGetWithoutATokenRendersTheInvalidLinkMessage() {
    $result = $this->render('myapi_auth_password_reset_page_get');

    $this->assertStringContainsString('<p class="message">Token inválido.</p>', $result['output']);
    $this->assertStringNotContainsString('<form', $result['output']);
  }

  /**
   * A whitespace-only token counts as no token: the value is trimmed before it
   * is judged, so '?token=%20%20' cannot render a form carrying a blank token.
   */
  public function testGetTrimsTheTokenBeforeJudgingIt() {
    $_GET['token'] = '   ';

    $result = $this->render('myapi_auth_password_reset_page_get');

    $this->assertStringNotContainsString('<form', $result['output']);
  }

  /**
   * A non-string token — '?token[]=x' — is ignored instead of fataling on
   * trim(), the same defensive shape the JSON endpoints have.
   */
  public function testGetIgnoresANonStringToken() {
    $_GET['token'] = ['x'];

    $result = $this->render('myapi_auth_password_reset_page_get');

    $this->assertStringContainsString('class="message"', $result['output']);
  }

  /**
   * The token from the query string reaches the page escaped.
   *
   * The full path, from $_GET to the rendered attribute: this is the case that
   * proves the escaping asserted above is actually applied on the real route,
   * and not only when the renderer is called by hand.
   */
  public function testGetEscapesATokenFromTheQueryString() {
    $_GET['token'] = '"><script>alert(1)</script>';

    $result = $this->render('myapi_auth_password_reset_page_get');

    $this->assertStringNotContainsString('<script>alert(1)</script>', $result['output']);
    $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $result['output']);
  }

  /**
   * POST over the reset limit renders the "too many attempts" message and
   * stops — no form to retry with, which is the point of a rate limit.
   *
   * The page shares the 'myapi_reset_ip' counter with the JSON endpoint (SPEC
   * 07), so hammering the web form cannot buy extra attempts at the API.
   */
  public function testPostOverTheLimitRendersTheTooManyAttemptsScreen() {
    $GLOBALS['myapi_test_flood_allowed'] = FALSE;

    $result = $this->render('myapi_auth_password_reset_page_post');

    $this->assertStringContainsString('Demasiados intentos.', $result['output']);
    $this->assertStringNotContainsString('<form', $result['output']);

    $call = $GLOBALS['myapi_test_flood_calls'][0];
    $this->assertSame('is_allowed', $call['call']);
    $this->assertSame('myapi_reset_ip', $call['event'], 'the same counter as the JSON endpoint');
    $this->assertSame('198.51.100.9', $call['identifier']);
    $this->assertSame(10, $call['threshold']);
    $this->assertSame(900, $call['window']);
  }

  /**
   * POST with an empty field re-renders the form with an error, keeping the
   * token so the user can just type again.
   */
  public function testPostWithAnEmptyFieldRerendersTheFormWithTheError() {
    $_POST['token'] = 'tok123';
    $_POST['new_password'] = '';

    $result = $this->render('myapi_auth_password_reset_page_post');

    $this->assertStringContainsString('<div class="alert">Campo inválido o ausente: new_password</div>', $result['output']);
    $this->assertStringContainsString('value="tok123"', $result['output'], 'the token survives the re-render');
    $this->assertStringContainsString('<form method="post"', $result['output']);
  }

  /**
   * The re-render never carries the meta-refresh.
   *
   * The user is on the web fallback BECAUSE the app did not open; bouncing
   * them at it again would throw away the error they were meant to read. This
   * is the same rule asserted on the renderer, now on the real POST route.
   */
  public function testPostNeverBouncesTheUserAtTheApp() {
    $_POST['token'] = 'tok123';
    $_POST['new_password'] = '';

    $result = $this->render('myapi_auth_password_reset_page_post');

    $this->assertStringNotContainsString('http-equiv="refresh"', $result['output']);
  }

  /**
   * An empty POST is the same error, with an empty token field: the page does
   * not pretend to have a token it never received.
   */
  public function testEmptyPostRendersTheFormWithAnEmptyToken() {
    $result = $this->render('myapi_auth_password_reset_page_post');

    $this->assertStringContainsString('class="alert"', $result['output']);
    $this->assertStringContainsString('<input type="hidden" name="token" value="">', $result['output']);
  }

  /**
   * A password shorter than 8 characters is rejected by the same rule the JSON
   * endpoint applies, and the failed attempt IS charged to the counter.
   *
   * Both halves matter. The rule is shared because both routes go through
   * myapi_auth_password_reset_execute(), whose length checks return before any
   * database access — which is exactly why this case runs in this layer. And
   * registering the attempt is what stops the web form from being an unlimited
   * oracle for guessing tokens.
   */
  public function testPostWithAShortPasswordIsRejectedAndCharged() {
    $_POST['token'] = 'tok123';
    $_POST['new_password'] = str_repeat('a', 7);

    $result = $this->render('myapi_auth_password_reset_page_post');

    $this->assertStringContainsString('<div class="alert">Campo demasiado corto: new_password</div>', $result['output']);
    $this->assertSame(
      ['is_allowed', 'register'],
      array_column($GLOBALS['myapi_test_flood_calls'], 'call'),
      'the failed attempt advances the counter'
    );
  }

  /**
   * And one longer than 255 gets the other end of the same rule.
   */
  public function testPostWithALongPasswordIsRejected() {
    $_POST['token'] = 'tok123';
    $_POST['new_password'] = str_repeat('a', 256);

    $result = $this->render('myapi_auth_password_reset_page_post');

    $this->assertStringContainsString('<div class="alert">Campo demasiado largo: new_password</div>', $result['output']);
  }

  /**
   * A request that never reached the reset logic is NOT charged to the
   * counter: an empty form submission is a user mistake, not an attempt.
   */
  public function testAnEmptySubmissionIsNotChargedToTheCounter() {
    $_POST['token'] = 'tok123';
    $_POST['new_password'] = '';

    $this->render('myapi_auth_password_reset_page_post');

    $this->assertSame(['is_allowed'], array_column($GLOBALS['myapi_test_flood_calls'], 'call'));
  }

  /**
   * The page entry point routes by method, and declares the response HTML
   * rather than the JSON every other route in this module answers.
   */
  public function testTheEntryPointRoutesByMethodAndDeclaresHtml() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['token'] = 'tok123';

    $get = $this->render('myapi_auth_password_reset_page');
    $this->assertSame('text/html; charset=utf-8', $get['headers']['Content-Type']);
    $this->assertStringContainsString('content="0;url=myapp://reset-password', $get['output'], 'routed to the GET handler');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['token'] = 'tok123';
    $_POST['new_password'] = '';

    $post = $this->render('myapi_auth_password_reset_page');
    $this->assertStringContainsString('class="alert"', $post['output'], 'routed to the POST handler');
    $this->assertStringNotContainsString('http-equiv="refresh"', $post['output']);
  }

  /**
   * Every screen this page can print is a complete HTML document.
   *
   * The page has no theme layer behind it to close a tag it forgot, so a
   * branch that printed a fragment would render as broken markup in the user's
   * browser with nothing failing anywhere else.
   */
  public function testEveryScreenIsACompleteDocument() {
    // Each closure calls the page function DIRECTLY: myapi_test_capture()
    // owns the output buffer, so going through render() here would nest one
    // capture inside another and the inner one would swallow the HTML.
    $screens = [
      'invalid link'      => function () {
        myapi_auth_password_reset_page_get();
      },
      'form'              => function () {
        $_GET['token'] = 'tok123';
        myapi_auth_password_reset_page_get();
      },
      'too many attempts' => function () {
        $GLOBALS['myapi_test_flood_allowed'] = FALSE;
        myapi_auth_password_reset_page_post();
      },
      'form with error'   => function () {
        $_POST['token'] = 'tok123';
        myapi_auth_password_reset_page_post();
      },
    ];

    foreach ($screens as $label => $screen) {
      $_GET = [];
      $_POST = [];
      unset($GLOBALS['myapi_test_flood_allowed']);

      $output = myapi_test_capture($screen)['output'];

      $this->assertStringStartsWith('<!DOCTYPE html><html lang="es">', $output, $label);
      $this->assertStringEndsWith('</body></html>', $output, $label);
    }
  }

}
