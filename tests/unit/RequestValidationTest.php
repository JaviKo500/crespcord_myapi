<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';

/**
 * Unit tests for the shared request helpers of includes/myapi.request.inc
 * (SPEC 73).
 *
 * These are the validators every resource funnels its input through, so a
 * change here is a change to the contract of the whole API at once — that is
 * why they get their own class instead of being exercised sideways from a
 * resource's tests. The four groups below are the four that never touch the
 * database:
 *
 *   - myapi_request_require_fields()  — presence
 *   - myapi_request_require_strings() — presence + type + length
 *   - myapi_valid_iso_date() / myapi_parse_date_range_param() — the lax
 *     date-range filter shared by expenses, receipts and the condominium
 *     summary
 *   - myapi_request_method() / myapi_request_post_field(_array)() — the thin
 *     readers over $_SERVER and $_POST
 *   - myapi_is_positive_int_param() and the three parsers built on it
 *     (SPEC 122) — the pagination and the optional ids every listing reads
 *
 * myapi_request_body() has no test on purpose: it reads php://input, which
 * cannot be written from PHP, and caches the result in a static that no test
 * could reset afterwards. It is covered over real HTTP in
 * tests/integration/MyapiAuthTestCase.test.
 *
 * The two validators reject by calling the REAL myapi_error(), loaded above:
 * it prints the envelope and ends the request with drupal_exit(), which
 * tests/unit/bootstrap.php turns into a captured body plus a thrown MyapiExit.
 * So these cases assert the error the client actually receives — code, status
 * and translated text — and not a reimplementation of it. assertRejects() is
 * the only place that knows the mechanics.
 */
class RequestValidationTest extends TestCase {

  protected function setUp(): void {
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    // Precondition, not decoration: myapi_error() translates through the
    // language myapi_get_lang() memoised for this process, and assertRejects()
    // builds its expected text in Spanish. See the same guard, with the long
    // explanation, in PasswordResetPageTest.
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');
  }

  protected function tearDown(): void {
    $_GET = [];
    $_POST = [];
  }

  /**
   * Runs $callable and asserts it was stopped by the expected error.
   *
   * Asserting the key AND the replacements matters: '@field' is what tells the
   * app which input to highlight, and a validator that reports the wrong field
   * name is as broken as one that lets the value through.
   *
   * @param callable $callable      The validator call under test.
   * @param string   $expected_key  Catalogue key, e.g. 'invalid_field'.
   * @param array    $replacements  Expected placeholder map.
   * @param string   $message       Label for the failure output.
   */
  private function assertRejects(callable $callable, $expected_key, array $replacements, $message = '') {
    $result = myapi_test_capture($callable);

    $this->assertTrue($result['exited'], ($message !== '' ? $message . ': ' : '') . 'nothing was rejected');
    $this->assertFalse($result['json']['success'], $message);
    $this->assertSame($expected_key, $result['json']['error_code'], $message);
    $this->assertSame(422, $result['status'], $message);
    // The translated text is where the replacements land, so asserting it
    // covers the '@field' map as well as the wording the user reads.
    $this->assertSame(myapi_t($expected_key, $replacements, 'es'), $result['json']['error'], $message);
  }

  /**
   * Asserts $callable ran to completion, i.e. the input was accepted.
   *
   * @param callable $callable  The validator call under test.
   * @param string   $message   Label for the failure output.
   */
  private function assertAccepts(callable $callable, $message = '') {
    $result = myapi_test_capture($callable);

    $this->assertFalse(
      $result['exited'],
      ($message !== '' ? $message . ': ' : '') . 'rejected with ' . $result['output']
    );
    $this->assertSame('', $result['output'], $message);
  }

  // ---------------------------------------------------------------------
  // myapi_request_require_fields()
  // ---------------------------------------------------------------------

  /**
   * The happy path: every requested key is there, so the validator returns and
   * the resource carries on.
   */
  public function testRequireFieldsAcceptsBodyWithEveryKey() {
    $this->assertAccepts(function () {
      myapi_request_require_fields(['username' => 'ana', 'password' => 'secret'], ['username', 'password']);
    });
  }

  /**
   * An absent key is a 422 naming that key.
   */
  public function testRequireFieldsRejectsAbsentKey() {
    $this->assertRejects(
      function () {
        myapi_request_require_fields(['username' => 'ana'], ['username', 'password']);
      },
      'missing_field',
      ['@field' => 'password']
    );
  }

  /**
   * The check is isset(), so an explicit null is as absent as no key at all.
   * A client sending {"password": null} gets 'missing_field', not
   * 'invalid_field' — worth pinning because array_key_exists() would answer the
   * opposite and this is the distinction myapi_claim_validate_close_reason()
   * had to be written by hand to get right (SPEC 70).
   */
  public function testRequireFieldsTreatsExplicitNullAsMissing() {
    $this->assertRejects(
      function () {
        myapi_request_require_fields(['token' => NULL], ['token']);
      },
      'missing_field',
      ['@field' => 'token']
    );
  }

  /**
   * Presence is all this validator promises: an empty string, a zero and an
   * empty array are all "present". This is precisely why every endpoint that
   * cares about the VALUE calls myapi_request_require_strings() instead.
   */
  public function testRequireFieldsAcceptsPresentButEmptyValues() {
    $this->assertAccepts(function () {
      myapi_request_require_fields(
        ['a' => '', 'b' => 0, 'c' => [], 'd' => FALSE],
        ['a', 'b', 'c', 'd']
      );
    });
  }

  /**
   * With more than one key missing, the first one in the REQUESTED order is
   * reported — not the first one in the body — so the message is stable
   * whatever order the client serialized its JSON in.
   */
  public function testRequireFieldsReportsTheFirstMissingInRequestedOrder() {
    $this->assertRejects(
      function () {
        myapi_request_require_fields([], ['username', 'password']);
      },
      'missing_field',
      ['@field' => 'username']
    );

    $this->assertRejects(
      function () {
        myapi_request_require_fields([], ['password', 'username']);
      },
      'missing_field',
      ['@field' => 'password']
    );
  }

  /**
   * Asking for nothing accepts anything, including an empty body. Endpoints
   * with no required field can call the validator unconditionally.
   */
  public function testRequireFieldsWithNoFieldsAcceptsEmptyBody() {
    $this->assertAccepts(function () {
      myapi_request_require_fields([], []);
    });
  }

  // ---------------------------------------------------------------------
  // myapi_request_require_strings()
  // ---------------------------------------------------------------------

  /**
   * Two ordinary non-empty strings pass.
   */
  public function testRequireStringsAcceptsNonEmptyStrings() {
    $this->assertAccepts(function () {
      myapi_request_require_strings(['username' => 'ana', 'password' => 'secret'], ['username', 'password']);
    });
  }

  /**
   * Everything that is not a usable string collapses into the same
   * 'invalid_field': absent, explicitly null, wrong type, or whitespace only.
   * The single key is deliberate — the client cannot tell "you forgot it" from
   * "you sent the wrong thing", and does not need to.
   */
  public function testRequireStringsRejectsEverythingThatIsNotAUsableString() {
    $cases = [
      'absent'          => [],
      'explicit null'   => ['username' => NULL],
      'integer'         => ['username' => 123],
      'float'           => ['username' => 1.5],
      'boolean true'    => ['username' => TRUE],
      'boolean false'   => ['username' => FALSE],
      'list'            => ['username' => ['ana']],
      'object'          => ['username' => ['name' => 'ana']],
      'empty string'    => ['username' => ''],
      'spaces only'     => ['username' => '   '],
      'tab only'        => ['username' => "\t"],
      'newlines only'   => ['username' => "\n \n"],
    ];

    foreach ($cases as $label => $body) {
      $this->assertRejects(
        function () use ($body) {
          myapi_request_require_strings($body, ['username']);
        },
        'invalid_field',
        ['@field' => 'username'],
        $label
      );
    }
  }

  /**
   * The default ceiling is 255, the width of the columns these values end up
   * in. Exactly 255 is the last accepted length; 256 is one too many.
   */
  public function testRequireStringsEnforcesTheDefault255Ceiling() {
    $this->assertAccepts(function () {
      myapi_request_require_strings(['name' => str_repeat('a', 255)], ['name']);
    }, '255 characters');

    $this->assertRejects(
      function () {
        myapi_request_require_strings(['name' => str_repeat('a', 256)], ['name']);
      },
      'field_too_long',
      ['@field' => 'name']
    );
  }

  /**
   * The floor defaults to 1 (any non-empty string passes) and is raised by
   * callers that have a real minimum — the password reset page asks for 8.
   */
  public function testRequireStringsEnforcesTheMinimumLength() {
    $this->assertAccepts(function () {
      myapi_request_require_strings(['new_password' => str_repeat('a', 8)], ['new_password'], 255, 8);
    }, 'exactly the minimum');

    $this->assertRejects(
      function () {
        myapi_request_require_strings(['new_password' => str_repeat('a', 7)], ['new_password'], 255, 8);
      },
      'field_too_short',
      ['@field' => 'new_password']
    );

    $this->assertAccepts(function () {
      myapi_request_require_strings(['name' => 'x'], ['name']);
    }, 'one character passes the default floor of 1');
  }

  /**
   * Both bounds are measured with strlen(), i.e. in BYTES, and this is the
   * behaviour the API ships: a 200-character Spanish name with accents is 200+
   * bytes and can be rejected by a limit its character count clears.
   *
   * The test pins it rather than calling it a bug because 255 is a byte-width
   * column limit, so counting bytes is the check that actually protects the
   * write. Where the limit is editorial rather than structural the resources
   * do NOT use this helper — myapi_claim_validate_close_reason() counts
   * characters with drupal_strlen() for exactly this reason (SPEC 70), and
   * ClaimCloseReasonTest asserts the opposite outcome for the same input.
   */
  public function testRequireStringsMeasuresLengthInBytesNotCharacters() {
    $accented = str_repeat('á', 128);
    $this->assertSame(256, strlen($accented), 'fixture sanity: two bytes per character');
    $this->assertSame(128, mb_strlen($accented, 'UTF-8'), 'fixture sanity: 128 characters');

    $this->assertRejects(
      function () use ($accented) {
        myapi_request_require_strings(['name' => $accented], ['name']);
      },
      'field_too_long',
      ['@field' => 'name']
    );
  }

  /**
   * Emptiness is judged after trim(), length is not. A value padded with
   * spaces is measured with its padding, so ' a ' clears a floor of 3 that its
   * useful content does not.
   *
   * Documented here because it is the one place the helper is inconsistent
   * with itself, and because resources rely on it in the safe direction: none
   * of them stores the raw value — they re-read it from the body and the ones
   * that care trim it themselves.
   */
  public function testRequireStringsMeasuresLengthWithoutTrimming() {
    $this->assertAccepts(function () {
      myapi_request_require_strings(['name' => ' a '], ['name'], 255, 3);
    }, 'padding counts towards the floor');

    $this->assertRejects(
      function () {
        myapi_request_require_strings(['name' => ' ' . str_repeat('a', 255)], ['name']);
      },
      'field_too_long',
      ['@field' => 'name']
    );
  }

  /**
   * Order of the three checks, for one field that fails all it can: the type
   * check runs first, so a non-string never reports a length problem.
   */
  public function testRequireStringsChecksTypeBeforeLength() {
    $this->assertRejects(
      function () {
        myapi_request_require_strings(['name' => 12345], ['name'], 3, 10);
      },
      'invalid_field',
      ['@field' => 'name']
    );
  }

  /**
   * And 'field_too_short' outranks 'field_too_long' — unreachable together in
   * practice, but it fixes the branch order so a future edit cannot silently
   * swap the two messages.
   */
  public function testRequireStringsChecksShortBeforeLong() {
    $this->assertRejects(
      function () {
        // Floor above the ceiling: every value violates both bounds at once.
        myapi_request_require_strings(['name' => 'abc'], ['name'], 2, 10);
      },
      'field_too_short',
      ['@field' => 'name']
    );
  }

  /**
   * With several fields, the first invalid one in the REQUESTED order stops
   * the request — the later ones are never even looked at.
   */
  public function testRequireStringsReportsTheFirstInvalidInRequestedOrder() {
    $body = ['username' => '', 'password' => ''];

    $this->assertRejects(
      function () use ($body) {
        myapi_request_require_strings($body, ['username', 'password']);
      },
      'invalid_field',
      ['@field' => 'username']
    );

    $this->assertRejects(
      function () use ($body) {
        myapi_request_require_strings($body, ['password', 'username']);
      },
      'invalid_field',
      ['@field' => 'password']
    );
  }

  // ---------------------------------------------------------------------
  // myapi_valid_iso_date()
  // ---------------------------------------------------------------------

  /**
   * A real calendar date in 'YYYY-MM-DD' comes back unchanged — the function
   * is a filter, not a parser, so the caller can hand the value straight to
   * the query it was already going to build.
   */
  public function testValidIsoDateReturnsARealDateUnchanged() {
    foreach (['2026-08-06', '2024-02-29', '1999-12-31', '2026-01-01'] as $date) {
      $this->assertSame($date, myapi_valid_iso_date($date), $date);
    }
  }

  /**
   * Anything that is not a string is silently ignored — this reads the query
   * string, where '?date_from[]=x' arrives as an array and must not fatal.
   */
  public function testValidIsoDateRejectsNonStrings() {
    foreach ([NULL, 20260806, 2026.08, TRUE, FALSE, ['2026-08-06']] as $value) {
      $this->assertNull(myapi_valid_iso_date($value), var_export($value, TRUE));
    }
  }

  /**
   * The format is exact: no single-digit parts, no other separators, no time
   * component, no surrounding whitespace. The anchors in the pattern are what
   * make the last three fail.
   *
   * The trailing-newline case is the one that found a defect (SPEC 73): PCRE
   * lets '$' match just before a final newline, so "2026-08-06\n" passed the
   * pattern and came back WITH the newline attached, straight into the query
   * the caller builds. The 'D' modifier added to myapi_valid_iso_date() is
   * what this case now guards.
   */
  public function testValidIsoDateRejectsMalformedStrings() {
    $cases = [
      '', '2026', '2026-08', '2026-8-6', '26-08-06', '06/08/2026', '2026/08/06',
      '2026-08-06T00:00:00', '2026-08-06 00:00:00', ' 2026-08-06', "2026-08-06\n",
      'yyyy-mm-dd',
    ];

    foreach ($cases as $value) {
      $this->assertNull(myapi_valid_iso_date($value), json_encode($value));
    }
  }

  /**
   * Well-formed but impossible dates are rejected too: checkdate() is the
   * second half of the check, and it is what keeps '2026-02-30' out of a
   * BETWEEN clause where it would silently match nothing.
   */
  public function testValidIsoDateRejectsImpossibleCalendarDates() {
    foreach (['2026-02-30', '2026-13-01', '2026-00-10', '2026-04-31', '2026-02-29', '2026-01-00'] as $value) {
      $this->assertNull(myapi_valid_iso_date($value), $value);
    }
  }

  // ---------------------------------------------------------------------
  // myapi_parse_date_range_param()
  // ---------------------------------------------------------------------

  /**
   * No parameters at all: an unfiltered range, which every caller reads as
   * "return everything".
   */
  public function testDateRangeWithoutParamsIsEmpty() {
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_parse_date_range_param());
  }

  /**
   * Both bounds valid: both kept, and a single-day range (from === to) is a
   * legitimate one, not an inversion.
   */
  public function testDateRangeKeepsBothValidBounds() {
    $_GET['date_from'] = '2026-01-01';
    $_GET['date_to'] = '2026-12-31';
    $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], myapi_parse_date_range_param());

    $_GET['date_from'] = '2026-08-06';
    $_GET['date_to'] = '2026-08-06';
    $this->assertSame(['from' => '2026-08-06', 'to' => '2026-08-06'], myapi_parse_date_range_param());
  }

  /**
   * One bound only is a valid half-open range: the other stays NULL and the
   * caller applies a single comparison.
   */
  public function testDateRangeAcceptsASingleBound() {
    $_GET['date_from'] = '2026-08-06';
    $this->assertSame(['from' => '2026-08-06', 'to' => NULL], myapi_parse_date_range_param());

    $_GET = ['date_to' => '2026-08-06'];
    $this->assertSame(['from' => NULL, 'to' => '2026-08-06'], myapi_parse_date_range_param());
  }

  /**
   * A junk bound is dropped on its own, without taking the valid one with it —
   * the filter is lax by design and never answers 422.
   */
  public function testDateRangeDropsOnlyTheInvalidBound() {
    $_GET['date_from'] = 'not-a-date';
    $_GET['date_to'] = '2026-12-31';
    $this->assertSame(['from' => NULL, 'to' => '2026-12-31'], myapi_parse_date_range_param());

    $_GET = ['date_from' => '2026-01-01', 'date_to' => '2026-02-30'];
    $this->assertSame(['from' => '2026-01-01', 'to' => NULL], myapi_parse_date_range_param());
  }

  /**
   * An inverted range drops BOTH bounds, so the response is the unfiltered
   * list instead of the guaranteed-empty one a from > to comparison would
   * produce. That choice is the whole reason this function exists rather than
   * two independent myapi_valid_iso_date() calls at each call site.
   */
  public function testInvertedRangeDropsBothBounds() {
    $_GET['date_from'] = '2026-12-31';
    $_GET['date_to'] = '2026-01-01';

    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_parse_date_range_param());
  }

  /**
   * The inversion check compares the two ISO strings directly. That is only
   * sound because both have already passed myapi_valid_iso_date(), so they are
   * fixed-width and zero-padded and sort chronologically as text — including
   * across a year boundary, which is what this case pins.
   */
  public function testInversionIsDetectedAcrossYears() {
    $_GET['date_from'] = '2027-01-01';
    $_GET['date_to'] = '2026-12-31';
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_parse_date_range_param());

    $_GET = ['date_from' => '2026-12-31', 'date_to' => '2027-01-01'];
    $this->assertSame(['from' => '2026-12-31', 'to' => '2027-01-01'], myapi_parse_date_range_param());
  }

  /**
   * An invalid bound is dropped BEFORE the inversion check, so a junk
   * 'date_from' never drags a good 'date_to' down with it.
   */
  public function testInvalidBoundIsNotComparedForInversion() {
    $_GET['date_from'] = '9999-99-99';
    $_GET['date_to'] = '2026-01-01';

    $this->assertSame(['from' => NULL, 'to' => '2026-01-01'], myapi_parse_date_range_param());
  }

  // ---------------------------------------------------------------------
  // myapi_request_method()
  // ---------------------------------------------------------------------

  /**
   * The method is uppercased, which is what lets every dispatcher compare it
   * against 'POST' with a plain === and still answer a lowercase client.
   */
  public function testRequestMethodIsUppercased() {
    foreach (['get' => 'GET', 'post' => 'POST', 'Put' => 'PUT', 'dElEtE' => 'DELETE', 'POST' => 'POST'] as $raw => $expected) {
      $_SERVER['REQUEST_METHOD'] = $raw;
      $this->assertSame($expected, myapi_request_method(), $raw);
    }
  }

  // ---------------------------------------------------------------------
  // myapi_request_post_field() / myapi_request_post_field_array()
  // ---------------------------------------------------------------------

  /**
   * A scalar multipart field comes back trimmed — the browser's own line
   * breaks around a textarea value never reach the database.
   */
  public function testPostFieldReturnsTheTrimmedValue() {
    $_POST['description'] = "  Fuga en el pasillo \n";

    $this->assertSame('Fuga en el pasillo', myapi_request_post_field('description'));
  }

  /**
   * Absent is NULL, and so is a repeated field — 'name[]' arrives as an array
   * and belongs to the sibling function. Answering NULL rather than the array
   * is what keeps a resource from writing an array into a text column.
   */
  public function testPostFieldReturnsNullForAbsentAndForArrays() {
    $this->assertNull(myapi_request_post_field('missing'));

    $_POST['tags'] = ['a', 'b'];
    $this->assertNull(myapi_request_post_field('tags'));
  }

  /**
   * A field sent empty is an empty string, NOT NULL: "present but blank" and
   * "not sent" stay distinguishable, which is what lets an update endpoint
   * tell "clear this value" from "leave it alone".
   */
  public function testPostFieldDistinguishesBlankFromAbsent() {
    $_POST['note'] = '   ';

    $this->assertSame('', myapi_request_post_field('note'));
    $this->assertNull(myapi_request_post_field('note_absent'));
  }

  /**
   * The repeated form: values are trimmed, blanks and non-scalars are dropped,
   * and the result is reindexed from 0 so it serializes as a JSON list and not
   * as an object with gaps in its keys.
   */
  public function testPostFieldArrayTrimsFiltersAndReindexes() {
    $_POST['remove_file_ids'] = ['  12 ', '', '  ', '13', ['nested'], "\n14\n"];

    $result = myapi_request_post_field_array('remove_file_ids');

    $this->assertSame(['12', '13', '14'], $result);
    $this->assertSame([0, 1, 2], array_keys($result), 'reindexed from 0');
  }

  /**
   * Absent, or sent as a single scalar instead of the 'name[]' form, is the
   * empty list — never NULL, so every caller can foreach() the result without
   * a guard.
   */
  public function testPostFieldArrayReturnsEmptyListForAbsentAndForScalars() {
    $this->assertSame([], myapi_request_post_field_array('missing'));

    $_POST['remove_file_ids'] = '12';
    $this->assertSame([], myapi_request_post_field_array('remove_file_ids'));
  }

  /**
   * Values keep their string type: the function filters, it does not cast. A
   * resource that needs an integer id casts it itself, after checking it
   * against something.
   */
  public function testPostFieldArrayDoesNotCastValues() {
    $_POST['ids'] = [12, '13', 14.5];

    $this->assertSame(['12', '13', '14.5'], myapi_request_post_field_array('ids'));
  }

  /* -------------------------------------------------------------------------
   * The pagination parsers (SPEC 122).
   *
   * Thirteen resources used to write this test by hand and twelve wrote it
   * without the is_scalar() guard, which is what made '?page[]=1' emit a
   * notice on its way to the right answer. These are the pulled-out versions,
   * and the cases below are the ones that guard was missing for.
   * ---------------------------------------------------------------------- */

  /**
   * A positive integer is one, in every shape a query string can carry it.
   */
  public function testIsPositiveIntParamAcceptsPositiveIntegers() {
    foreach (['1', '20', '007', 1, 50] as $value) {
      $this->assertTrue(myapi_is_positive_int_param($value), json_encode($value));
    }
  }

  /**
   * And nothing else is: zero, negatives, floats, words, the empty string, and
   * — the case this function exists for — an ARRAY.
   */
  public function testIsPositiveIntParamRejectsEverythingElse() {
    foreach (['0', '-1', '1.5', '1a', 'abc', '', ' 1', '1 ', '+1', 0, -3, 1.5, NULL, FALSE, ['1'], [], (object) []] as $value) {
      $this->assertFalse(myapi_is_positive_int_param($value), json_encode($value));
    }
  }

  /**
   * THE ARRAY IS REJECTED WITHOUT A NOTICE. That is the whole point: the cast
   * happens after is_scalar(), so it never runs on an array.
   */
  public function testAnArrayIsRejectedSilently() {
    $notices = [];
    set_error_handler(function ($severity, $message) use (&$notices) {
      $notices[] = $message;

      return TRUE;
    });
    try {
      $verdict = myapi_is_positive_int_param(['1']);
    }
    finally {
      restore_error_handler();
    }

    $this->assertFalse($verdict);
    $this->assertSame([], $notices, 'not one notice');
  }

  /**
   * The page defaults to 1 and answers what was asked for when it can.
   */
  public function testThePageParam() {
    $_GET = [];
    $this->assertSame(1, myapi_parse_page_param());

    $_GET = ['page' => '3'];
    $this->assertSame(3, myapi_parse_page_param());

    $_GET = ['page' => '007'];
    $this->assertSame(7, myapi_parse_page_param(), 'leading zeros are normalised');

    foreach (['0', '-1', 'abc', '', '1.5', ['2']] as $value) {
      $_GET = ['page' => $value];
      $this->assertSame(1, myapi_parse_page_param(), json_encode($value));
    }
  }

  /**
   * The limit defaults to 20, caps at 50, and falls back silently.
   */
  public function testTheLimitParamDefaultsAndClamps() {
    $_GET = [];
    $this->assertSame(20, myapi_parse_limit_param());

    $_GET = ['limit' => '5'];
    $this->assertSame(5, myapi_parse_limit_param());

    $_GET = ['limit' => '50'];
    $this->assertSame(50, myapi_parse_limit_param());

    $_GET = ['limit' => '9999'];
    $this->assertSame(50, myapi_parse_limit_param(), 'capped');

    foreach (['0', '-5', 'abc', '', ['5']] as $value) {
      $_GET = ['limit' => $value];
      $this->assertSame(20, myapi_parse_limit_param(), json_encode($value));
    }
  }

  /**
   * THE '-1' SENTINEL IS A PARAMETER AND NOT A DEFAULT: a listing that does
   * not honour it reads '-1' as one more malformed value and answers its
   * default, which is what keeps the bulletin and provider listings paginating
   * the way they always did.
   */
  public function testTheUnlimitedSentinelIsOptIn() {
    $_GET = ['limit' => '-1'];

    $this->assertSame(-1, myapi_parse_limit_param(TRUE));
    $this->assertSame(20, myapi_parse_limit_param(FALSE), 'not every listing has it');
    $this->assertSame(20, myapi_parse_limit_param(), 'and it is off by default');
  }

  /**
   * The sentinel is matched STRICTLY against the string $_GET carries, so a
   * value that merely looks like it — a float, an array — is not it.
   */
  public function testOnlyTheExactSentinelStringIsTheSentinel() {
    foreach (['-1.0', '-01', ' -1', '-2', ['-1']] as $value) {
      $_GET = ['limit' => $value];
      $this->assertSame(20, myapi_parse_limit_param(TRUE), json_encode($value));
    }
  }

  /**
   * The default and the cap are parameters, so a listing with its own numbers
   * does not need its own parser.
   */
  public function testTheDefaultAndTheCapAreParameters() {
    $_GET = [];
    $this->assertSame(10, myapi_parse_limit_param(FALSE, 10));

    $_GET = ['limit' => '80'];
    $this->assertSame(80, myapi_parse_limit_param(FALSE, 10, 100));
    $this->assertSame(50, myapi_parse_limit_param(), 'the module-wide cap is 50');
  }

  /**
   * The optional id is the LAX sibling of myapi_parse_id_param(): a malformed
   * value is NULL — "no filter" — where that one answers 422, because its
   * callers offer a narrowing the client may simply omit.
   */
  public function testTheOptionalIdParamIsLax() {
    $_GET = [];
    $this->assertNull(myapi_parse_optional_id_param('condominium'));

    $_GET = ['condominium' => '12'];
    $this->assertSame(12, myapi_parse_optional_id_param('condominium'));

    foreach (['0', '-1', 'abc', '', '1.5', ['12']] as $value) {
      $_GET = ['condominium' => $value];
      $this->assertNull(myapi_parse_optional_id_param('condominium'), json_encode($value));
    }
  }

  /**
   * And it never answers, which is the difference that matters: the strict one
   * ends the request with a 422 for the very same input.
   */
  public function testTheLaxAndStrictIdParsersDisagreeOnPurpose() {
    $_GET = ['unit_id' => 'abc'];

    $this->assertNull(myapi_parse_optional_id_param('unit_id'));

    $result = myapi_test_capture(function () {
      myapi_parse_id_param('unit_id');
    });
    $this->assertSame(422, $result['status']);
  }

}
