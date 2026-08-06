<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.i18n.inc';

/**
 * Unit tests for includes/myapi.i18n.inc (SPEC 73).
 *
 * myapi_t() is the last thing every response passes through: the 'error' and
 * 'message' fields of the envelope are its return value, so a key missing from
 * one of the two catalogues does not fail — it silently ships the raw key
 * ('invalid_credentials') to the app's screen. The parity cases below are the
 * point of this class; the substitution cases pin the mechanics around them.
 *
 * myapi_get_lang() is tested in separate processes, and the reason is in its
 * own docblock: it caches the resolved language in a function static, which no
 * test can reset. Sharing a process would mean the FIRST case to run decides
 * the language for every other test in the suite — including
 * PasswordResetPageTest, whose HTML is language-dependent.
 */
class I18nTest extends TestCase {

  /**
   * The catalogue keys per language, read out of the source of myapi_t().
   *
   * The catalogue is a local array inside the function, so there is no runtime
   * handle on it — but the parity check needs the full key list, and a list
   * hardcoded here would go stale on the next resource that adds a message.
   * Reflection narrows the read to the function's own line range, and
   * assertNotEmpty() in the caller makes a parse that stops matching fail
   * loudly instead of turning the parity assertions into no-ops.
   *
   * @return array  ['en' => [key, ...], 'es' => [key, ...]].
   */
  private function catalogueKeys() {
    $reflection = new ReflectionFunction('myapi_t');
    $lines = array_slice(
      file($reflection->getFileName()),
      $reflection->getStartLine() - 1,
      $reflection->getEndLine() - $reflection->getStartLine() + 1
    );

    $keys = ['en' => [], 'es' => []];
    $current = NULL;

    foreach ($lines as $line) {
      if (preg_match("/^\s*'(en|es)'\s*=>\s*\[\s*$/", $line, $m)) {
        $current = $m[1];
        continue;
      }
      // Both quotings on the value side: the English texts that contain an
      // apostrophe ("the area's opening hours") are written with double
      // quotes, and a parse that only accepted single ones would drop exactly
      // those and report them as a parity gap that is not there.
      if ($current !== NULL && preg_match("/^\s*'([a-z0-9_]+)'\s*=>\s*['\"]/", $line, $m)) {
        $keys[$current][] = $m[1];
      }
    }

    return $keys;
  }

  /**
   * Every key exists in both languages.
   *
   * A key present only in 'es' does not raise anything for an English client:
   * the fallback returns the key itself, so the app shows
   * 'reservation_overlap' where it should show a sentence. This is the case
   * that catches it before the app does.
   */
  public function testEveryKeyExistsInBothLanguages() {
    $keys = $this->catalogueKeys();

    $this->assertNotEmpty($keys['en'], 'catalogue parse sanity: English keys found');
    $this->assertNotEmpty($keys['es'], 'catalogue parse sanity: Spanish keys found');

    $this->assertSame([], array_values(array_diff($keys['en'], $keys['es'])), 'keys in English but not in Spanish');
    $this->assertSame([], array_values(array_diff($keys['es'], $keys['en'])), 'keys in Spanish but not in English');
  }

  /**
   * No key is declared twice inside the same language.
   *
   * PHP keeps the LAST duplicate silently, so a second declaration of a key
   * overrides the first with no warning anywhere — a whole endpoint's message
   * can change from an edit made for another one.
   */
  public function testNoKeyIsDeclaredTwiceWithinALanguage() {
    foreach ($this->catalogueKeys() as $lang => $keys) {
      $duplicates = array_values(array_unique(array_diff_assoc($keys, array_unique($keys))));

      $this->assertSame([], $duplicates, 'duplicate keys in ' . $lang);
    }
  }

  /**
   * Every key resolves to a non-empty message in both languages, and never to
   * the key itself — which is what the fallback would return for a key the
   * parse found but the lookup cannot.
   */
  public function testEveryKeyResolvesToARealMessage() {
    foreach ($this->catalogueKeys()['en'] as $key) {
      foreach (['en', 'es'] as $lang) {
        $message = myapi_t($key, [], $lang);

        $this->assertNotSame($key, $message, $key . ' [' . $lang . ']');
        $this->assertNotSame('', trim($message), $key . ' [' . $lang . ']');
      }
    }
  }

  /**
   * The two languages of a key carry the SAME placeholders.
   *
   * An '@field' present in the English text and missing from the Spanish one
   * means the Spanish message drops the only part that tells the user what
   * went wrong; the reverse leaves a literal '@field' on the English screen,
   * since strtr() only replaces what the caller passed and the caller passes
   * the same map for both.
   */
  public function testPlaceholdersMatchBetweenLanguages() {
    foreach ($this->catalogueKeys()['en'] as $key) {
      $en = $this->placeholdersIn(myapi_t($key, [], 'en'));
      $es = $this->placeholdersIn(myapi_t($key, [], 'es'));

      $this->assertSame($en, $es, 'placeholders differ for ' . $key);
    }
  }

  /**
   * The sorted, unique '@placeholder' tokens of a message.
   *
   * @param string $message  Catalogue text.
   *
   * @return array  e.g. ['@field'].
   */
  private function placeholdersIn($message) {
    preg_match_all('/@[a-z_]+/', $message, $matches);
    $placeholders = array_unique($matches[0]);
    sort($placeholders);

    return $placeholders;
  }

  /**
   * The explicit language wins over the resolved one, which is what lets the
   * reset email be written in the language of the request that asked for it
   * and not in the language of the cron run that sends it.
   */
  public function testExplicitLanguageIsHonoured() {
    $this->assertSame('Invalid username or password.', myapi_t('invalid_credentials', [], 'en'));
    $this->assertSame('Usuario o contraseña incorrectos.', myapi_t('invalid_credentials', [], 'es'));
  }

  /**
   * Placeholders are substituted with strtr(), one map for both languages.
   */
  public function testPlaceholdersAreSubstituted() {
    $this->assertSame(
      'Missing required field: username',
      myapi_t('missing_field', ['@field' => 'username'], 'en')
    );
    $this->assertSame(
      'Falta el campo requerido: username',
      myapi_t('missing_field', ['@field' => 'username'], 'es')
    );
  }

  /**
   * A message with more than one placeholder substitutes all of them, and a
   * replacement whose value itself looks like a placeholder is not re-scanned
   * — strtr() walks the subject once, so no substitution can cascade into
   * another.
   */
  public function testSeveralPlaceholdersAndNoCascade() {
    $this->assertSame(
      'This link expires in @minutes minutes. If you did not request this, you can ignore this email.',
      myapi_t('password_reset_email_expiry', [], 'en'),
      'no replacements: the token stays literal'
    );

    $this->assertSame(
      'This link expires in 30 minutes. If you did not request this, you can ignore this email.',
      myapi_t('password_reset_email_expiry', ['@minutes' => 30], 'en')
    );

    $this->assertSame(
      'Hi @minutes,',
      myapi_t('password_reset_email_greeting', ['@name' => '@minutes', '@minutes' => 'never'], 'en'),
      'a replaced value is not itself replaced again'
    );
  }

  /**
   * Calling a placeholder message with no map leaves the raw '@field' in the
   * text instead of raising anything.
   *
   * Pinned because it is the failure mode of a caller that forgets the third
   * argument of myapi_error(): the response is still a valid envelope, still
   * a 422, and the user reads "Falta el campo requerido: @field". Only a test
   * or a screenshot catches it.
   */
  public function testMissingReplacementMapLeavesTheTokenLiteral() {
    $this->assertSame('Falta el campo requerido: @field', myapi_t('missing_field', [], 'es'));
  }

  /**
   * An unknown key falls back to the key itself rather than to an empty
   * string or a notice: a response is never broken by a typo, and the typo is
   * visible in the payload.
   */
  public function testUnknownKeyFallsBackToTheKey() {
    $this->assertSame('no_such_key', myapi_t('no_such_key', [], 'es'));
    $this->assertSame('no_such_key', myapi_t('no_such_key', ['@field' => 'x'], 'en'));
  }

  /**
   * An unsupported language falls back the same way. myapi_get_lang() can only
   * answer 'es' or 'en', so this is reachable only through the explicit third
   * argument — which the mail queue passes from a stored value.
   */
  public function testUnknownLanguageFallsBackToTheKey() {
    $this->assertSame('invalid_credentials', myapi_t('invalid_credentials', [], 'fr'));
  }

  // ---------------------------------------------------------------------
  // myapi_get_lang()
  //
  // One case per process: the function memoises its answer in a static, so a
  // second case in the same process would read the first case's language. The
  // isolation is what makes each of these assert the resolution rule instead
  // of the order the suite happens to run in.
  // ---------------------------------------------------------------------

  /**
   * Nothing to go on: Spanish, the documented default.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testLangDefaultsToSpanish() {
    unset($_GET['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);

    $this->assertSame('es', myapi_get_lang());
  }

  /**
   * Accept-Language decides when there is no 'lang' parameter, reading the
   * first two characters — which is how a full 'en-US,en;q=0.9' header
   * resolves.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testLangComesFromAcceptLanguageHeader() {
    unset($_GET['lang']);
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';

    $this->assertSame('en', myapi_get_lang());
  }

  /**
   * The 'lang' query parameter outranks the header. This is what keeps the
   * password/reset page in the language of the email link that opened it,
   * whatever the browser asks for.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testLangParameterOutranksTheHeader() {
    $_GET['lang'] = 'en';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-ES,es;q=0.9';

    $this->assertSame('en', myapi_get_lang());
  }

  /**
   * An unsupported 'lang' is ignored rather than honoured, and the header
   * still gets its turn.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testUnsupportedLangParameterFallsBackToTheHeader() {
    $_GET['lang'] = 'fr';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-GB';

    $this->assertSame('en', myapi_get_lang());
  }

  /**
   * An unsupported header with no usable parameter lands on the default.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testUnsupportedHeaderFallsBackToSpanish() {
    unset($_GET['lang']);
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';

    $this->assertSame('es', myapi_get_lang());
  }

  /**
   * The parameter is matched case-insensitively and on its first two
   * characters only, so 'EN' and 'en-US' both resolve.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testLangParameterIsNormalised() {
    $_GET['lang'] = 'EN-us';
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

    $this->assertSame('en', myapi_get_lang());
  }

  /**
   * A non-string 'lang' — '?lang[]=en' — is ignored instead of fataling on
   * substr(), the same defensive shape myapi_valid_iso_date() has.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testArrayLangParameterIsIgnored() {
    $_GET['lang'] = ['en'];
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

    $this->assertSame('es', myapi_get_lang());
  }

  /**
   * The answer is memoised: the language is resolved once per request, so a
   * value that changes mid-request cannot split one response between two
   * languages.
   *
   * @runInSeparateProcess
   * @preserveGlobalState disabled
   */
  public function testResolvedLanguageIsMemoised() {
    $_GET['lang'] = 'en';
    $this->assertSame('en', myapi_get_lang());

    $_GET['lang'] = 'es';
    $this->assertSame('en', myapi_get_lang(), 'the static wins over the new value');
  }

}
