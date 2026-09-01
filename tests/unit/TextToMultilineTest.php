<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.text.inc';

/**
 * Unit tests for myapi_text_to_multiline().
 *
 * The second pure function of includes/myapi.text.inc, and the one every long
 * text field of the marketplace now travels through: the description of a
 * request, the message / includes / excludes of an offer, the comment of a
 * rating and the comment of a transaction.
 *
 * WHAT IS BEING PINNED HERE IS THE DIFFERENCE WITH ITS SISTER. Both remove
 * every trace of markup; only this one keeps the line breaks, and the tests
 * below are written so that a future "simplification" that routes a
 * description through myapi_text_to_plain() — or that answers it as stored —
 * breaks something visible instead of quietly changing what the app paints.
 *
 * No site is booted. decode_entities() is the only Drupal call, and
 * bootstrap.php stubs it with html_entity_decode(), which is what Drupal 7
 * does.
 */
class TextToMultilineTest extends TestCase {

  /* -------------------------------------------------------------------------
   * The line breaks: the whole reason this function exists.
   * ---------------------------------------------------------------------- */

  /**
   * A plain-text value the resident typed in the app comes back untouched.
   * This is the everyday case and the one that must not regress.
   */
  public function testKeepsTheLineBreaksOfPlainText() {
    $this->assertSame(
      "Se rompió la tubería.\nEstá inundando el baño.",
      myapi_text_to_multiline("Se rompió la tubería.\nEstá inundando el baño.")
    );
  }

  /**
   * A <br> IS a line break, so it becomes one instead of disappearing.
   * This is where the sister helper answers a single line.
   */
  public function testLineBreakTagBecomesARealLineBreak() {
    $this->assertSame(
      "Primera línea\nSegunda línea",
      myapi_text_to_multiline('<p>Primera línea<br />Segunda línea</p>')
    );
  }

  /**
   * Two paragraphs stay two paragraphs, separated by one blank line.
   */
  public function testTwoParagraphsBecomeTwoBlocks() {
    $this->assertSame(
      "Uno\n\nDos",
      myapi_text_to_multiline('<p>Uno</p><p>Dos</p>')
    );
  }

  /**
   * The four empty paragraphs a rich editor emits between two sentences come
   * out as ONE blank line and not as four.
   */
  public function testCollapsesRunsOfBlankLines() {
    $this->assertSame(
      "Uno\n\nDos",
      myapi_text_to_multiline("<p>Uno</p>\r\n<p>&nbsp;</p>\r\n<p>&nbsp;</p>\r\n<p>Dos</p>")
    );
  }

  /**
   * "\r\n" and "\r" are normalised, so a Windows client and a mac one answer
   * the same string.
   */
  public function testNormalisesCarriageReturns() {
    $this->assertSame("A\nB\nC", myapi_text_to_multiline("A\r\nB\rC"));
  }

  /**
   * A list is a list: each item ends up on its own block.
   */
  public function testListItemsAreSeparated() {
    $this->assertSame("Uno\n\nDos", myapi_text_to_multiline('<ul><li>Uno</li><li>Dos</li></ul>'));
  }

  /* -------------------------------------------------------------------------
   * The markup: nothing survives, which is the ask this function answers.
   * ---------------------------------------------------------------------- */

  /**
   * Inline markup goes away and the text inside it survives, with no word
   * glued to the next one.
   */
  public function testStripsInlineMarkupKeepingTheText() {
    $this->assertSame(
      'Hola mundo',
      myapi_text_to_multiline('<p>Hola <strong>mundo</strong></p>')
    );
  }

  /**
   * Entities come back DECODED and are never re-escaped: the destination is a
   * Flutter Text widget, not an HTML page.
   */
  public function testDecodesEntitiesAndDoesNotEscape() {
    $this->assertSame('Luz & Cía', myapi_text_to_multiline('<p>Luz &amp; C&iacute;a</p>'));
  }

  /**
   * strip_tags() BEFORE decode_entities(), same order as the sister and for
   * the same reason: a stored `&lt;b&gt;` is the characters the operator
   * typed, so it comes back as the literal text `<b>` and is not decoded into
   * a tag that would then be deleted.
   */
  public function testStripsBeforeDecodingSoTypedAngleBracketsSurvive() {
    $this->assertSame('<b>literal</b>', myapi_text_to_multiline('&lt;b&gt;literal&lt;/b&gt;'));
  }

  /**
   * No markup survives — which is not the same as the text between two tags
   * disappearing. The body is text, and text is kept.
   */
  public function testScriptTagLeavesItsBodyAsText() {
    $this->assertSame('alert(1)', myapi_text_to_multiline('<script>alert(1)</script>'));
  }

  /* -------------------------------------------------------------------------
   * Horizontal whitespace: collapsed, unlike the vertical one.
   * ---------------------------------------------------------------------- */

  /**
   * Runs of spaces and tabs become one space; the line breaks around them are
   * not touched.
   */
  public function testCollapsesHorizontalWhitespaceOnly() {
    $this->assertSame("A B\nC D", myapi_text_to_multiline("A   \t B\nC \t  D"));
  }

  /**
   * The no-break space &nbsp; leaves behind is whitespace too, and a line made
   * only of it is a blank line, not a line with an invisible character in it.
   */
  public function testTreatsTheNoBreakSpaceAsWhitespace() {
    $this->assertSame('A B', myapi_text_to_multiline('A&nbsp;&nbsp;B'));
  }

  /**
   * No space hugging a line break, and nothing hanging off either end.
   */
  public function testTrimsEachLineAndTheWholeString() {
    $this->assertSame("A\nB", myapi_text_to_multiline("  A  \n  B  "));
  }

  /* -------------------------------------------------------------------------
   * Every input answers a string, never NULL.
   * ---------------------------------------------------------------------- */

  /**
   * The empty string and a value made only of markup both answer "", which is
   * what lets a caller treat "nothing was written" as a 422 without a second
   * check.
   */
  public function testEmptyAndMarkupOnlyAnswerTheEmptyString() {
    $this->assertSame('', myapi_text_to_multiline(''));
    $this->assertSame('', myapi_text_to_multiline('<p></p>'));
    $this->assertSame('', myapi_text_to_multiline('<p>&nbsp;</p>'));
  }

  /**
   * NULL, numbers, booleans and arrays are accepted and answer "" — the same
   * contract the sister has, so no caller needs a type branch of its own.
   */
  public function testNonStringsAnswerTheEmptyString() {
    $this->assertSame('', myapi_text_to_multiline(NULL));
    $this->assertSame('', myapi_text_to_multiline(123));
    $this->assertSame('', myapi_text_to_multiline(TRUE));
    $this->assertSame('', myapi_text_to_multiline(['x']));
  }

  /* -------------------------------------------------------------------------
   * The contrast with myapi_text_to_plain(), stated once and explicitly.
   * ---------------------------------------------------------------------- */

  /**
   * The same input, the two helpers, two different answers. If this test ever
   * fails because both answer the same thing, one of the two has lost its
   * reason to exist.
   *
   * The one-line answer is 'UnoDos' AND NOT 'Uno Dos', with the two words
   * glued: myapi_text_to_plain() deletes the `</p><p>` with strip_tags()
   * without putting anything in its place, so there is no whitespace left to
   * collapse. That is a real rough edge of the older helper, pinned here
   * rather than fixed, because the values it serves — a name, a code, a node
   * title — never carry block markup. It is also, on its own, a reason not to
   * route a description through it.
   */
  public function testDiffersFromTheOneLineHelperExactlyOnLineBreaks() {
    $stored = '<p>Uno</p><p>Dos</p>';

    $this->assertSame('UnoDos', myapi_text_to_plain($stored));
    $this->assertSame("Uno\n\nDos", myapi_text_to_multiline($stored));
  }
}
