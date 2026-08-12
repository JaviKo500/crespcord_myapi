<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.text.inc';

/**
 * Unit tests for myapi_text_to_plain() (SPEC 79).
 *
 * The one pure function of includes/myapi.text.inc: it turns the HTML a term
 * description carries in the database into the real plain text the Flutter app
 * paints in a Text widget. Everything SPEC 79 promises about `description` is
 * decided here — that tags go away, that entities come back decoded, that the
 * result is UNESCAPED, that whitespace is collapsed, and that the answer is a
 * string in every single case, never NULL.
 *
 * The order of the two first steps (strip_tags() before decode_entities()) is
 * the delicate part of the contract and has its own section below: reversing
 * them would silently delete text the operator actually typed.
 *
 * No site is booted. decode_entities() is the only Drupal call, and
 * bootstrap.php stubs it with html_entity_decode(), which is what Drupal 7
 * does.
 */
class TextToPlainTest extends TestCase {

  /* -------------------------------------------------------------------------
   * Step 1 — strip_tags().
   * ---------------------------------------------------------------------- */

  /**
   * The everyday case: what a rich-text editor stores for one paragraph.
   */
  public function testStripsSimpleMarkup() {
    $this->assertSame(
      'Instalación y reparación de tuberías.',
      myapi_text_to_plain('<p>Instalación y reparación de tuberías.</p>')
    );
  }

  /**
   * Nested inline markup goes away and the text inside it survives, with no
   * word glued to the next one.
   */
  public function testStripsNestedInlineMarkupKeepingTheText() {
    $this->assertSame(
      'Hola mundo',
      myapi_text_to_plain('<p>Hola <strong>mundo</strong></p>')
    );
  }

  /**
   * A <br> is markup, so it disappears; the two lines it separated end up as
   * one line with a single space, because step 3 collapses what is left.
   */
  public function testLineBreakTagBecomesASingleSpace() {
    $this->assertSame(
      'Primera línea Segunda línea',
      myapi_text_to_plain("<p>Primera línea<br />\nSegunda línea</p>")
    );
  }

  /**
   * Attributes travel inside the tag, so they go away with it: no `href` and
   * no `style` leaks into the plain text.
   */
  public function testTagAttributesDoNotLeak() {
    $this->assertSame(
      'Ver tarifas',
      myapi_text_to_plain('<a href="https://example.com" title="Tarifas">Ver tarifas</a>')
    );
  }

  /**
   * A stored <script> loses its tags and keeps its body as text: the value
   * answers `alert(1)`, not executable markup.
   *
   * This is the mitigation SPEC 79 writes down for the "description travels
   * unescaped" risk, pinned as it really behaves. The risk table of the spec
   * words it as "sale como cadena vacía", which is not what strip_tags() does
   * — it removes tags, not the text between them. What the mitigation
   * actually guarantees, and all it needs to guarantee, is that NO MARKUP is
   * left in the returned value: there is no `<script>` for any consumer to
   * execute, only the characters `alert(1)`, which a Flutter Text widget
   * paints as the harmless string it is.
   */
  public function testStoredScriptTagLosesItsTagsAndKeepsItsBodyAsText() {
    $this->assertSame('alert(1)', myapi_text_to_plain('<script>alert(1)</script>'));
  }

  /**
   * Markup-only input answers "" rather than a string of leftover whitespace.
   */
  public function testMarkupOnlyInputYieldsAnEmptyString() {
    $this->assertSame('', myapi_text_to_plain('<p></p><br /><div>  </div>'));
  }

  /* -------------------------------------------------------------------------
   * Step 2 — decode_entities().
   * ---------------------------------------------------------------------- */

  /**
   * The entity a rich editor writes most often: &amp; comes back as a single
   * `&`, so the app shows "Plomería & gasfitería" and not the source text.
   */
  public function testDecodesAmpersand() {
    $this->assertSame(
      'Plomería & gasfitería',
      myapi_text_to_plain('<p>Plomer&iacute;a &amp; gasfiter&iacute;a</p>')
    );
  }

  /**
   * Both quote styles decode too: check_plain() would have left them as
   * `&quot;` and `&#039;` in the response.
   */
  public function testDecodesBothQuoteStyles() {
    $this->assertSame(
      'Servicio "urgente" del día',
      myapi_text_to_plain('Servicio &quot;urgente&quot; del d&#237;a')
    );
  }

  /**
   * The result is plain text, NOT escaped: a `<` that the operator typed
   * (stored as `&lt;`) comes back as a real `<` character. This is the whole
   * difference with /api/v1/banks, whose description is check_plain()'d.
   */
  public function testTheResultIsUnescaped() {
    $this->assertSame('Menores de <18 años', myapi_text_to_plain('Menores de &lt;18 a&ntilde;os'));
  }

  /**
   * UTF-8 letters travel as themselves; nothing is entity-encoded on the way
   * out.
   */
  public function testAccentedTextTravelsUnchanged() {
    $this->assertSame('Áreas verdes — jardinería', myapi_text_to_plain('Áreas verdes — jardinería'));
  }

  /* -------------------------------------------------------------------------
   * The order of steps 1 and 2 — the case SPEC 79 calls delicate.
   * ---------------------------------------------------------------------- */

  /**
   * A stored `&lt;b&gt;texto&lt;/b&gt;` is text the operator typed, so it must
   * come back as the literal `<b>texto</b>`.
   *
   * If the function decoded first, step 2 would produce a real `<b>` tag and
   * step 1 would then delete it, answering just "texto": the operator's text
   * would be silently altered. This test is what pins the order.
   */
  public function testEntityEncodedTagSurvivesAsLiteralText() {
    $this->assertSame(
      '<b>texto</b>',
      myapi_text_to_plain('&lt;b&gt;texto&lt;/b&gt;')
    );
  }

  /**
   * Same rule with the dangerous example: a stored `&lt;script&gt;` comes back
   * as the literal characters `<script>`, it does not disappear. The value is
   * plain text that never enters an HTML parser, so the characters are just
   * characters.
   */
  public function testEntityEncodedScriptSurvivesAsLiteralText() {
    $this->assertSame(
      '<script>alert(1)</script>',
      myapi_text_to_plain('&lt;script&gt;alert(1)&lt;/script&gt;')
    );
  }

  /**
   * Real markup and encoded markup in the same value are each treated the way
   * they were written: the `<em>` goes, the `&lt;em&gt;` stays as text.
   */
  public function testRealMarkupIsRemovedAndEncodedMarkupIsKeptInTheSameValue() {
    $this->assertSame(
      'La etiqueta <em> pone énfasis',
      myapi_text_to_plain('<p>La etiqueta &lt;em&gt; pone <em>énfasis</em></p>')
    );
  }

  /**
   * Decoding happens ONCE, in step 2: the entities it produces are not decoded
   * again, so a stored `&amp;lt;` answers the literal `&lt;`. Pinned because
   * a second pass would be an easy "improvement" that changes the text.
   */
  public function testDecodingIsNotAppliedTwice() {
    $this->assertSame('&lt;', myapi_text_to_plain('&amp;lt;'));
  }

  /* -------------------------------------------------------------------------
   * Step 3 — collapsing whitespace.
   * ---------------------------------------------------------------------- */

  /**
   * Newlines and tabs are whitespace: the app receives one paragraph on one
   * line, which is what a category description is.
   */
  public function testNewlinesAndTabsCollapseToSingleSpaces() {
    $this->assertSame(
      'Primera línea segunda línea tercera',
      myapi_text_to_plain("Primera línea\n\tsegunda línea\r\n\t\ttercera")
    );
  }

  /**
   * Runs of plain spaces collapse too.
   */
  public function testRunsOfSpacesCollapse() {
    $this->assertSame('Uno dos tres', myapi_text_to_plain('Uno     dos   tres'));
  }

  /**
   * `&nbsp;` decodes to the no-break space (\xC2\xA0), which is NOT matched by
   * \s: without the explicit byte pair in the pattern it would survive into
   * the JSON as an invisible character that breaks a Dart `trim()` and any
   * `== ''` check. This is the case SPEC 79 names by hand.
   */
  public function testNbspEntityIsCollapsedLikeASpace() {
    $this->assertSame('Hola mundo', myapi_text_to_plain('<p>Hola&nbsp;mundo</p>'));
  }

  /**
   * The same no-break space stored raw (pasted from Word, not written as an
   * entity) is collapsed as well.
   */
  public function testRawNoBreakSpaceIsCollapsed() {
    $this->assertSame('Hola mundo', myapi_text_to_plain("Hola\xC2\xA0mundo"));
  }

  /**
   * A trailing `&nbsp;` is trimmed away, not left as a stray character at the
   * end of the string — the acceptance-criteria example of SPEC 79.
   */
  public function testTrailingNbspIsTrimmed() {
    $this->assertSame(
      'Hola mundo',
      myapi_text_to_plain('<p>Hola <strong>mundo</strong></p>&nbsp;')
    );
  }

  /**
   * A no-break space at both ends disappears entirely: step 3 turns it into a
   * plain space and step 4 trims it.
   */
  public function testLeadingAndTrailingNoBreakSpacesAreTrimmed() {
    $this->assertSame('Plomería', myapi_text_to_plain("\xC2\xA0Plomería\xC2\xA0"));
  }

  /* -------------------------------------------------------------------------
   * Step 4 — trim().
   * ---------------------------------------------------------------------- */

  /**
   * Surrounding whitespace never reaches the app.
   */
  public function testSurroundingWhitespaceIsTrimmed() {
    $this->assertSame('Plomería', myapi_text_to_plain("  \n\t Plomería \n  "));
  }

  /**
   * Whitespace-only input answers "", the same as no description at all.
   */
  public function testWhitespaceOnlyInputYieldsAnEmptyString() {
    $this->assertSame('', myapi_text_to_plain("   \n\t  "));
  }

  /* -------------------------------------------------------------------------
   * The non-string inputs: the function never answers NULL.
   * ---------------------------------------------------------------------- */

  /**
   * The empty string answers the empty string.
   */
  public function testEmptyStringYieldsAnEmptyString() {
    $this->assertSame('', myapi_text_to_plain(''));
  }

  /**
   * NULL — what the database answers for a term saved with the description
   * blank — answers "" with no notice and no deprecation, because the guard
   * comes before any string function. This is what lets the resource call the
   * helper unconditionally.
   */
  public function testNullYieldsAnEmptyString() {
    $this->assertSame('', myapi_text_to_plain(NULL));
    $this->assertNotNull(myapi_text_to_plain(NULL));
  }

  /**
   * An array — what a caller passing the raw Field API value instead of the
   * ['value'] key would hand over — answers "" instead of blowing up with a
   * "conversion of array to string" notice.
   */
  public function testArrayYieldsAnEmptyString() {
    $this->assertSame('', myapi_text_to_plain([LANGUAGE_NONE => [['value' => 'Hola']]]));
  }

  /**
   * Numbers, booleans and objects answer "" too. Pinned as behaviour rather
   * than endorsed as data: the guard is is_string(), so a numeric description
   * is NOT silently cast to text.
   */
  public function testOtherNonStringsYieldAnEmptyString() {
    $this->assertSame('', myapi_text_to_plain(0));
    $this->assertSame('', myapi_text_to_plain(12));
    $this->assertSame('', myapi_text_to_plain(FALSE));
    $this->assertSame('', myapi_text_to_plain(TRUE));
    $this->assertSame('', myapi_text_to_plain((object) ['value' => 'Hola']));
  }

  /* -------------------------------------------------------------------------
   * Purity and robustness.
   * ---------------------------------------------------------------------- */

  /**
   * A string of "0" survives: it is falsy in PHP, and any empty()-based
   * shortcut in a future rewrite would turn it into "".
   */
  public function testTextOfZeroIsKept() {
    $this->assertSame('0', myapi_text_to_plain('0'));
  }

  /**
   * Invalid UTF-8 does not make the function answer NULL. The whitespace
   * pattern runs without the /u modifier precisely so preg_replace() cannot
   * fail here: a byte-broken description degrades to a mangled string, never
   * to a missing key in the JSON.
   */
  public function testInvalidUtf8StillAnswersAString() {
    $result = myapi_text_to_plain("Plomer\xC3\x28ía");

    $this->assertIsString($result);
    $this->assertNotSame('', $result);
  }

  /**
   * Running the function over its own output changes nothing: the plain text
   * of plain text is the same plain text. Cheap guarantee that a resource
   * calling the helper twice by accident cannot corrupt a value.
   */
  public function testIsIdempotent() {
    $once  = myapi_text_to_plain('<p>Hola&nbsp;<strong>mundo</strong>&amp;co</p>');
    $twice = myapi_text_to_plain($once);

    $this->assertSame('Hola mundo&co', $once);
    $this->assertSame($once, $twice);
  }

}
