<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../myapi.module';

/**
 * Contract tests for the module's own structure (SPEC 123).
 *
 * Every other class in this suite asserts what a function DOES. This one
 * asserts what the module IS: that the routing table points at functions that
 * exist, in the files it says they are in; that those files are declared in
 * myapi.info; that every endpoint has a doc; and that the four prohibitions of
 * CLAUDE.md are still true.
 *
 * The reason it exists is that none of those failures is visible from a unit
 * test of a resource. A route whose 'file' says resources/payment.resource.inc
 * while the callback lives in an include is a green suite and a white screen:
 * Drupal loads the declared file, does not find the callback, and the endpoint
 * is a 404 that no test in tests/unit/ can see, because every test calls its
 * dispatcher by name and the dispatcher is fine. Same for an .inc missing from
 * files[]: it works on the developer's machine, where another route already
 * pulled it in, and fatals on the first request that hits it alone.
 *
 * These are cheap — they read the source, they touch nothing — and they are the
 * only cases in the suite that fail when a NEW resource is added wrong, rather
 * than when an existing one is changed wrong.
 *
 * What they cannot say: nothing here proves an endpoint answers correctly, or
 * that the doc describes what the code does. They prove the wiring, and the
 * wiring is what the checklist in CLAUDE.md is made of.
 */
class ModuleContractTest extends TestCase {

  /**
   * The routes that are deliberately not under api/v1/.
   *
   * Two kinds, and both are the reason the versioning rule is asserted with an
   * allowlist instead of a blanket "every path starts with api/v1": the reset
   * page is the HTML landing the emailed deep link opens (it is not consumed by
   * the app, it is opened by a browser), and the other five are back-office
   * screens hanging off Drupal's own admin/ and node/ trees, where the path is
   * Drupal's to choose and not ours.
   *
   * A new entry here is a decision, which is the point: adding one is a line in
   * a diff that a reviewer sees, and forgetting the api/v1 prefix on a real
   * endpoint is a failing test.
   */
  const NON_VERSIONED_PATHS = [
    'password/reset',
    'admin/content/reservation-calendar',
    'admin/content/claims',
    'node/%node/claim-transaction/add',
    'node/%node/service-transaction/add',
    'node/%node/service-transaction/%node/delete',
  ];

  /**
   * Functions that PHP 8 added and PHP 7.4 does not have.
   *
   * The syntax half of the PHP 7.4 rule (match, enums, ?->, attributes,
   * promotion, union types) is caught by `php -l` under 7.4 in CI, and by the
   * heuristics below when the suite runs on a newer PHP locally. These are the
   * other half, the one no linter catches: str_contains() parses perfectly
   * under 7.4 and fatals at runtime, on the request that reaches it.
   */
  const PHP8_FUNCTIONS = [
    'str_contains',
    'str_starts_with',
    'str_ends_with',
    'get_debug_type',
    'preg_last_error_msg',
    'fdiv',
    'array_is_list',
    'enum_exists',
  ];

  /**
   * The routing table as Drupal reads it.
   *
   * Calling the hook instead of parsing myapi.module is what makes the rest of
   * this class honest: the assertions are made against the array the site gets,
   * not against a regex's idea of it.
   */
  private function menuItems() {
    $items = myapi_menu();
    $this->assertNotEmpty($items, 'parse sanity: hook_menu() returned routes');

    return $items;
  }

  /**
   * Absolute path of the module root.
   */
  private function root() {
    return dirname(__DIR__, 2);
  }

  /**
   * Every production PHP source of the module, as path => contents.
   */
  private function sourceFiles() {
    $root = $this->root();
    $paths = array_merge(
      glob($root . '/includes/*.inc'),
      glob($root . '/resources/*.inc'),
      [$root . '/myapi.module', $root . '/myapi.install']
    );

    $files = [];
    foreach ($paths as $path) {
      $files[str_replace($root . '/', '', $path)] = file_get_contents($path);
    }
    $this->assertGreaterThan(50, count($files), 'parse sanity: sources found');

    return $files;
  }

  /**
   * The files[] entries of myapi.info, in declaration order.
   */
  private function infoFiles() {
    $lines = file($this->root() . '/myapi.info');
    $files = [];
    foreach ($lines as $line) {
      if (preg_match('/^\s*files\[\]\s*=\s*(\S+)\s*$/', $line, $m)) {
        $files[] = $m[1];
      }
    }
    $this->assertNotEmpty($files, 'parse sanity: files[] entries found');

    return $files;
  }

  /**
   * A route with no 'page callback' is a 404, and one with no explicit
   * 'access callback' is worse: Drupal falls back to user_access('access
   * content'), which on this site is TRUE for anonymous. An endpoint that
   * forgets the key does not fail closed — it opens.
   */
  public function testEveryRouteDeclaresItsCallbackAndItsAccess() {
    foreach ($this->menuItems() as $path => $item) {
      $this->assertArrayHasKey('page callback', $item, $path);
      $this->assertArrayHasKey('access callback', $item, $path);
      $this->assertArrayHasKey('type', $item, $path);
    }
  }

  /**
   * The declared 'file' exists AND actually contains the callback.
   *
   * Both halves matter and only the second one is interesting: Drupal includes
   * the file named here and then calls the function. If the callback moved to
   * another .inc and the route was not updated, the file still exists, the
   * function still exists somewhere, every unit test still passes, and the
   * endpoint answers "page not found" in production.
   */
  public function testEveryCallbackLivesInTheFileItsRouteDeclares() {
    foreach ($this->menuItems() as $path => $item) {
      $this->assertArrayHasKey('file', $item, $path . ': route declares no file');

      $file = $this->root() . '/' . $item['file'];
      $this->assertFileExists($file, $path);

      $this->assertStringContainsString(
        'function ' . $item['page callback'] . '(',
        file_get_contents($file),
        $path . ': ' . $item['page callback'] . '() is not defined in ' . $item['file']
      );
    }
  }

  /**
   * Drupal only autoloads what myapi.info declares. A file missing from
   * files[] works until it is the first one a request needs.
   */
  public function testEveryRouteFileIsDeclaredInTheInfoFile() {
    $declared = $this->infoFiles();

    foreach ($this->menuItems() as $path => $item) {
      $this->assertContains($item['file'], $declared, $path . ': ' . $item['file'] . ' is not in myapi.info');
    }
  }

  /**
   * The same rule for the whole tree, not just for what a route names: an
   * include reached only from another include is just as unloadable.
   */
  public function testEveryIncludeAndResourceIsDeclaredInTheInfoFile() {
    $declared = $this->infoFiles();
    $root = $this->root();

    foreach (array_merge(glob($root . '/includes/*.inc'), glob($root . '/resources/*.inc')) as $path) {
      $relative = str_replace($root . '/', '', $path);
      $this->assertContains($relative, $declared, $relative . ' exists but is not in myapi.info');
    }
  }

  /**
   * And the other direction: a files[] entry pointing at a file that was
   * renamed or deleted makes Drupal fatal on cache rebuild, not on request —
   * which is to say, on deploy.
   */
  public function testEveryFileDeclaredInTheInfoFileExists() {
    foreach ($this->infoFiles() as $relative) {
      $this->assertFileExists($this->root() . '/' . $relative, 'myapi.info declares ' . $relative);
    }
  }

  /**
   * "Versioned from day one" (CLAUDE.md, rule 6) as an assertion.
   */
  public function testEveryEndpointIsUnderTheVersionOnePrefix() {
    foreach ($this->menuItems() as $path => $item) {
      if (in_array($path, self::NON_VERSIONED_PATHS, TRUE)) {
        continue;
      }
      $this->assertStringStartsWith('api/v1/', $path, $path . ': endpoint outside api/v1/');
    }
  }

  /**
   * The allowlist above has no stale entries — a path removed from hook_menu()
   * and left here would silently exempt a future route of the same name.
   */
  public function testTheNonVersionedAllowlistHasNoStaleEntries() {
    $paths = array_keys($this->menuItems());

    foreach (self::NON_VERSIONED_PATHS as $exempt) {
      $this->assertContains($exempt, $paths, $exempt . ': allowlisted but no longer routed');
    }
  }

  /**
   * "An endpoint without docs is incomplete" (CLAUDE.md) as an assertion.
   *
   * The comparison normalises the docs' {unit_id} placeholders to hook_menu()'s
   * %, which is the only difference between the two notations, and then looks
   * for the whole path as a literal. Matching the path and not just the
   * resource name is deliberate: docs/reservation.md existing says nothing
   * about /reservations/%/details having a section in it.
   */
  public function testEveryEndpointIsDocumented() {
    $docs = '';
    foreach (glob($this->root() . '/docs/*.md') as $doc) {
      $docs .= file_get_contents($doc) . "\n";
    }
    $this->assertNotEmpty($docs, 'parse sanity: docs found');
    $docs = preg_replace('/\{[a-z0-9_]+\}/', '%', $docs);

    foreach ($this->menuItems() as $path => $item) {
      if (strpos($path, 'api/v1/') !== 0) {
        continue;
      }
      $this->assertStringContainsString('/' . $path, $docs, $path . ': no docs/*.md mentions it');
    }
  }

  /**
   * A dispatcher nobody routes is either dead code or a route someone forgot
   * to register — and the second one is a feature that was written, tested,
   * merged, and is not reachable.
   */
  public function testEveryDispatcherIsRouted() {
    $routed = [];
    foreach ($this->menuItems() as $item) {
      $routed[$item['page callback']] = TRUE;
    }

    foreach ($this->sourceFiles() as $relative => $source) {
      if (!preg_match_all('/^function (myapi_[a-z0-9_]*_dispatch)\(/m', $source, $matches)) {
        continue;
      }
      foreach ($matches[1] as $dispatcher) {
        $this->assertArrayHasKey($dispatcher, $routed, $relative . ': ' . $dispatcher . '() is defined but not routed');
      }
    }
  }

  /**
   * "No raw JSON output — always myapi_respond() / myapi_error()" (CLAUDE.md).
   *
   * What is forbidden is PRINTING a body outside the envelope, not encoding
   * JSON: myapi.firebase.inc and myapi.onesignal.inc encode outbound payloads
   * for the push services, and myapi.chat.inc encodes to measure a byte length.
   * The check targets the print, which is the only thing that can reach the app
   * as a response that is not the envelope.
   */
  public function testNoResourcePrintsItsOwnJson() {
    foreach ($this->sourceFiles() as $relative => $source) {
      if ($relative === 'includes/myapi.response.inc') {
        continue;
      }
      $this->assertDoesNotMatchRegularExpression(
        '/\b(print|echo)\s+[a-z_]*json_encode\s*\(/',
        $source,
        $relative . ': prints a JSON body outside myapi_respond()/myapi_error()'
      );
      $this->assertStringNotContainsString(
        'drupal_json_output(',
        $source,
        $relative . ': answers with drupal_json_output() instead of the envelope'
      );
    }
  }

  /**
   * "No PHP 8.0+ syntax" (CLAUDE.md) as an assertion, for the part of it that
   * a linter cannot see.
   *
   * Production runs PHP 7.4.33 and most developer machines no longer do, so
   * `php -l` locally proves nothing about the target: PHP 8 syntax parses fine
   * on the machine that wrote it. CI closes that by linting and running this
   * whole suite under 7.4; this case is the fast local version, and the only
   * one that catches a PHP 8 FUNCTION, which is valid syntax everywhere and a
   * fatal error in production.
   *
   * Tokens, not a grep: a docblock that mentions str_contains() is prose, and
   * a method called ->match() is legal in 7.4.
   */
  public function testNoSourceFileUsesPhp8OnlyCode() {
    foreach ($this->sourceFiles() as $relative => $source) {
      foreach ($this->php8Usages($source) as $usage) {
        $this->fail($relative . ':' . $usage['line'] . ': ' . $usage['what'] . ' does not exist in PHP 7.4');
      }
      $this->addToAssertionCount(1);
    }
  }

  /**
   * The token walk behind the case above.
   *
   * @return array  [['what' => string, 'line' => int], ...]
   */
  private function php8Usages($source) {
    $tokens = token_get_all($source);
    $found = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
      $token = $tokens[$i];

      // #[Attr] is an attribute on PHP 8 and a comment on 7.4 — which is
      // exactly why it has to be caught here: it does not fatal, it silently
      // stops meaning anything.
      if (is_array($token) && $token[0] === T_COMMENT && strpos($token[1], '#[') === 0) {
        $found[] = ['what' => 'an attribute (#[...])', 'line' => $token[2]];
        continue;
      }

      if (!is_array($token) || $token[0] !== T_STRING) {
        // ?-> tokenises as '?' + '->' on 7.4 and as one token on 8.
        if ($token === '?' && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_OBJECT_OPERATOR) {
          $found[] = ['what' => 'the nullsafe operator (?->)', 'line' => $tokens[$i + 1][2]];
        }
        continue;
      }

      $previous = $this->previousMeaningfulToken($tokens, $i);
      $name = strtolower($token[1]);

      // A method or a declaration of the same name is not the PHP 8 construct.
      if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], TRUE)) {
        continue;
      }

      if (in_array($name, self::PHP8_FUNCTIONS, TRUE)) {
        $found[] = ['what' => $name . '()', 'line' => $token[2]];
        continue;
      }

      $next = $this->nextMeaningfulToken($tokens, $i);
      if ($name === 'match' && $next === '(') {
        $found[] = ['what' => 'a match expression', 'line' => $token[2]];
      }
      if ($name === 'enum' && is_array($next) && $next[0] === T_STRING) {
        $found[] = ['what' => 'an enum declaration', 'line' => $token[2]];
      }
    }

    return $found;
  }

  /**
   * The token before $index, skipping whitespace and comments.
   */
  private function previousMeaningfulToken(array $tokens, $index) {
    for ($i = $index - 1; $i >= 0; $i--) {
      if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      return $tokens[$i];
    }

    return NULL;
  }

  /**
   * The token after $index, skipping whitespace and comments.
   */
  private function nextMeaningfulToken(array $tokens, $index) {
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; $i++) {
      if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      return $tokens[$i];
    }

    return NULL;
  }

}
