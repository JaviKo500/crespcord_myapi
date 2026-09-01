<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.text.inc';

/**
 * Unit tests for myapi_text_fold() (SPEC 119).
 *
 * The second pure function of includes/myapi.text.inc: it folds a string for
 * COMPARISON — lower-cased and stripped of Spanish accents — so that a
 * resident typing "plomeria" into the search box finds the category the
 * operator named "Plomería".
 *
 * Everything ?search= promises about matching is decided here. The endpoint
 * tests then prove the filter uses it; these prove what it folds.
 *
 * No site is booted: the only Drupal call is drupal_strtolower(), which
 * bootstrap.php stubs with mb_strtolower(), exactly what Drupal 7 does when
 * mbstring is available.
 */
class TextFoldTest extends TestCase {

  /* -------------------------------------------------------------------------
   * Case.
   * ---------------------------------------------------------------------- */

  /**
   * Plain ASCII is lower-cased and otherwise untouched.
   */
  public function testAsciiIsLowerCased() {
    $this->assertSame('electricidad', myapi_text_fold('ELECTRICIDAD'));
    $this->assertSame('electricidad', myapi_text_fold('Electricidad'));
    $this->assertSame('electricidad', myapi_text_fold('electricidad'));
  }

  /**
   * Digits, spaces and punctuation survive: the needle is compared against a
   * name that may carry any of them ("A/C, ventilación").
   */
  public function testDigitsAndPunctuationAreKept() {
    $this->assertSame('a/c, ventilacion 24/7', myapi_text_fold('A/C, Ventilación 24/7'));
    $this->assertSame('cortes & instalaciones', myapi_text_fold('Cortes & instalaciones'));
  }

  /* -------------------------------------------------------------------------
   * Accents.
   * ---------------------------------------------------------------------- */

  /**
   * The five Spanish accented vowels, lower and upper case, fold to the bare
   * ASCII vowel.
   */
  public function testSpanishVowelsFoldToAscii() {
    $this->assertSame('aeiou', myapi_text_fold('áéíóú'));
    $this->assertSame('aeiou', myapi_text_fold('ÁÉÍÓÚ'));
  }

  /**
   * The ñ folds to n and the ü to u — the two letters a Spanish catalogue is
   * guaranteed to carry beyond the vowels.
   */
  public function testEnyeAndDiaeresisFold() {
    $this->assertSame('espanol', myapi_text_fold('Español'));
    $this->assertSame('pinguino', myapi_text_fold('Pingüino'));
    $this->assertSame('desagues', myapi_text_fold('desagües'));
  }

  /**
   * The accents a borrowed word drags in are folded too, so a term the
   * operator copied from elsewhere is still findable.
   */
  public function testBorrowedAccentsFold() {
    $this->assertSame('aaaa', myapi_text_fold('äâãå'));
    $this->assertSame('eeee', myapi_text_fold('ëêÈÊ'));
    $this->assertSame('c', myapi_text_fold('ç'));
    $this->assertSame('facade', myapi_text_fold('Façade'));
  }

  /**
   * The real catalogue names, folded to what the app's keyboard produces.
   */
  public function testTheCatalogueNamesFoldToTheirCodes() {
    $names = [
      'Plomería'      => 'plomeria',
      'Jardinería'    => 'jardineria',
      'Climatización' => 'climatizacion',
      'Cerrajería'    => 'cerrajeria',
      'Carpintería'   => 'carpinteria',
      'Albañilería'   => 'albanileria',
      'Impermeabilización' => 'impermeabilizacion',
    ];

    foreach ($names as $name => $expected) {
      $this->assertSame($expected, myapi_text_fold($name), $name);
    }
  }

  /**
   * FOLDING IS IDEMPOTENT: an already folded string folds to itself, which is
   * what lets the endpoint fold the needle once and every candidate on the
   * fly.
   */
  public function testFoldingIsIdempotent() {
    foreach (['Plomería', 'ÁÉÍÓÚ', 'Español', 'A/C', ''] as $value) {
      $once = myapi_text_fold($value);
      $this->assertSame($once, myapi_text_fold($once), $value);
    }
  }

  /* -------------------------------------------------------------------------
   * Non-strings and edge values.
   * ---------------------------------------------------------------------- */

  /**
   * Anything that is not a string answers "" — never NULL and never a PHP
   * warning. The needle comes from $_GET, where an array is one query string
   * away.
   */
  public function testNonStringsAnswerAnEmptyString() {
    foreach ([NULL, [], ['a'], 42, 4.2, TRUE, FALSE, new stdClass()] as $value) {
      $this->assertSame('', myapi_text_fold($value), gettype($value));
    }
  }

  /**
   * An empty string folds to an empty string.
   */
  public function testEmptyStringAnswersEmptyString() {
    $this->assertSame('', myapi_text_fold(''));
  }

  /**
   * Whitespace is NOT trimmed here: folding is about letters, and the caller
   * that reads a search box does its own trim(). Pinned so the two
   * responsibilities do not quietly merge.
   */
  public function testWhitespaceIsNotTrimmed() {
    $this->assertSame('  plomeria  ', myapi_text_fold('  Plomería  '));
  }

  /**
   * A letter the map does not know about is still lower-cased and never
   * mangled: the fold degrades to a plain lower-case, it does not drop
   * characters.
   */
  public function testUnknownLettersAreLowerCasedAndKept() {
    $this->assertSame('straße', myapi_text_fold('Straße'));
    $this->assertSame('αβγ', myapi_text_fold('ΑΒΓ'));
  }

}
